<?php
/**
 * Enhanced Product Search API for Order Editing
 * Returns products with their variants/addons for order edit panel
 * 
 * GET /api/product-search.php?q=QUERY
 * GET /api/product-search.php?product_id=ID (get variants for specific product)
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Admin auth check
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();

// Get variants for a specific product
if (!empty($_GET['product_id'])) {
    $productId = intval($_GET['product_id']);
    
    try {
        // Get product details
        $product = $db->fetch(
            "SELECT p.*, c.name as category_name 
             FROM products p 
             LEFT JOIN categories c ON c.id = p.category_id 
             WHERE p.id = ?",
            [$productId]
        );
        
        if (!$product) {
            echo json_encode(['success' => false, 'error' => 'Product not found']);
            exit;
        }
        
        // Get variants/addons
        $variants = $db->fetchAll(
            "SELECT * FROM product_variants 
             WHERE product_id = ? AND is_active = 1 
             ORDER BY option_type, variant_name, sort_order, id",
            [$productId]
        ) ?: [];
        
        // Group variants by type and name
        $groupedVariants = [];
        $addons = [];
        
        foreach ($variants as $v) {
            if ($v['option_type'] === 'addon') {
                $addons[] = [
                    'id' => $v['id'],
                    'name' => $v['variant_name'],
                    'value' => $v['variant_value'],
                    'price_adjustment' => floatval($v['price_adjustment'] ?? 0),
                    'absolute_price' => $v['absolute_price'] ? floatval($v['absolute_price']) : null,
                    'stock' => intval($v['stock_quantity'] ?? 0),
                    'sku' => $v['sku'] ?? '',
                    'image' => $v['variant_image'] ?? '',
                ];
            } else {
                // Variation - group by variant_name
                $groupName = $v['variant_name'];
                if (!isset($groupedVariants[$groupName])) {
                    $groupedVariants[$groupName] = [];
                }
                $groupedVariants[$groupName][] = [
                    'id' => $v['id'],
                    'value' => $v['variant_value'],
                    'price_adjustment' => floatval($v['price_adjustment'] ?? 0),
                    'absolute_price' => $v['absolute_price'] ? floatval($v['absolute_price']) : null,
                    'var_regular_price' => $v['var_regular_price'] ? floatval($v['var_regular_price']) : null,
                    'var_sale_price' => $v['var_sale_price'] ? floatval($v['var_sale_price']) : null,
                    'stock' => intval($v['stock_quantity'] ?? 0),
                    'sku' => $v['sku'] ?? '',
                    'image' => $v['variant_image'] ?? '',
                    'is_default' => intval($v['is_default'] ?? 0),
                    'weight_per_unit' => floatval($v['weight_per_unit'] ?? 0),
                ];
            }
        }
        
        $basePrice = floatval($product['sale_price'] > 0 ? $product['sale_price'] : $product['regular_price']);
        
        echo json_encode([
            'success' => true,
            'product' => [
                'id' => $product['id'],
                'name' => $product['name'],
                'name_bn' => $product['name_bn'] ?? '',
                'sku' => $product['sku'] ?? '',
                'type' => $product['product_type'] ?? 'simple',
                'regular_price' => floatval($product['regular_price']),
                'sale_price' => floatval($product['sale_price'] ?? 0),
                'base_price' => $basePrice,
                'stock' => intval($product['stock_quantity'] ?? 0),
                'image' => getProductImage($product),
                'combined_stock' => intval($product['combined_stock'] ?? 0),
            ],
            'variations' => $groupedVariants,
            'addons' => $addons,
            'has_variants' => !empty($groupedVariants) || !empty($addons),
        ]);
        
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Search products
$q = sanitize($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

$term = "%{$q}%";
$limit = 15;

try {
    $products = $db->fetchAll(
        "SELECT p.id, p.name, p.name_bn, p.slug, p.sku, p.product_type, p.featured_image, 
                p.regular_price, p.sale_price, p.stock_status, p.stock_quantity, p.is_on_sale,
                p.combined_stock,
                c.name as category_name,
                (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1) as variant_count
         FROM products p 
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE (p.name LIKE ? OR p.name_bn LIKE ? OR p.sku LIKE ? OR p.tags LIKE ?)
         ORDER BY p.sales_count DESC, p.name ASC 
         LIMIT ?",
        [$term, $term, $term, $term, $limit]
    );
    
    $results = [];
    foreach ($products as $p) {
        $price = floatval($p['sale_price'] > 0 ? $p['sale_price'] : $p['regular_price']);
        $hasVariants = intval($p['variant_count'] ?? 0) > 0;
        
        $results[] = [
            'id' => $p['id'],
            'name' => $p['name_bn'] ?: $p['name'],
            'name_en' => $p['name'],
            'slug' => $p['slug'],
            'sku' => $p['sku'] ?? '',
            'type' => $p['product_type'] ?? 'simple',
            'image' => getProductImage($p),
            'price' => $price,
            'regular_price' => floatval($p['regular_price']),
            'has_variants' => $hasVariants,
            'variant_count' => intval($p['variant_count'] ?? 0),
            'category' => $p['category_name'] ?? '',
            'in_stock' => $p['stock_status'] !== 'out_of_stock',
            'stock_quantity' => intval($p['stock_quantity'] ?? 0),
            'combined_stock' => intval($p['combined_stock'] ?? 0),
        ];
    }
    
    echo json_encode(['success' => true, 'results' => $results]);
    
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
