<?php
/**
 * Variant Stock Quick-Adjust API
 * Used by inventory variant tab for +/- buttons
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$variantId = intval($input['variant_id'] ?? 0);
$delta = intval($input['delta'] ?? 0);

if (!$variantId || !$delta) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $db = Database::getInstance();
    
    $variant = $db->fetch("SELECT * FROM product_variants WHERE id = ?", [$variantId]);
    if (!$variant) {
        echo json_encode(['success' => false, 'message' => 'Variant not found']);
        exit;
    }
    
    $newStock = max(0, $variant['stock_quantity'] + $delta);
    $db->query("UPDATE product_variants SET stock_quantity = ? WHERE id = ?", [$newStock, $variantId]);
    
    // Update parent product total stock
    $totalVariantStock = $db->fetch("SELECT SUM(stock_quantity) as total FROM product_variants WHERE product_id = ? AND is_active = 1", [$variant['product_id']]);
    $db->query("UPDATE products SET stock_quantity = ? WHERE id = ?", [$totalVariantStock['total'] ?? 0, $variant['product_id']]);
    
    // Log the movement
    try {
        $db->query("INSERT INTO stock_movements (warehouse_id, product_id, variant_id, movement_type, quantity, note, created_by) VALUES (1, ?, ?, ?, ?, ?, ?)", [
            $variant['product_id'],
            $variantId,
            $delta > 0 ? 'in' : 'out',
            abs($delta),
            "Quick adjust variant: {$variant['variant_name']}={$variant['variant_value']}",
            $_SESSION['admin_id']
        ]);
    } catch (Exception $e) {} // silently fail if stock_movements schema differs
    
    echo json_encode(['success' => true, 'new_stock' => $newStock, 'product_total' => $totalVariantStock['total'] ?? 0]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
