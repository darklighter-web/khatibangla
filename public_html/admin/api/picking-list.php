<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
refreshAdminPermissions();
requirePermission('orders', 'view');

header('Content-Type: application/json');

$db = Database::getInstance();
$input = json_decode(file_get_contents('php://input'), true);
$ids = array_map('intval', $input['ids'] ?? []);

if (empty($ids)) { echo json_encode(['orders'=>[],'items'=>[]]); exit; }

$ph = implode(',', array_fill(0, count($ids), '?'));

// Fetch orders
$orders = $db->fetchAll(
    "SELECT id, order_number, customer_name, customer_phone FROM orders WHERE id IN ($ph) ORDER BY created_at DESC",
    $ids
);

// Fetch all items for these orders
$allItems = $db->fetchAll(
    "SELECT oi.order_id, oi.product_name, oi.variant_name, oi.quantity, p.sku
     FROM order_items oi
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE oi.order_id IN ($ph)
     ORDER BY oi.product_name, oi.variant_name",
    $ids
);

// Attach items to orders
$itemsByOrder = [];
foreach ($allItems as $it) {
    $itemsByOrder[$it['order_id']][] = $it;
}
foreach ($orders as &$o) {
    $o['items'] = $itemsByOrder[$o['id']] ?? [];
}
unset($o);

// Aggregate: product+variant totals across all orders
$aggregated = [];
foreach ($allItems as $it) {
    $key = $it['product_name'] . '|||' . ($it['variant_name'] ?? '');
    if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
            'product_name' => $it['product_name'],
            'variant_name' => $it['variant_name'] ?? '',
            'sku'          => $it['sku'] ?? '',
            'total_qty'    => 0,
            'order_count'  => 0,
        ];
    }
    $aggregated[$key]['total_qty']   += intval($it['quantity']);
    $aggregated[$key]['order_count'] += 1;
}

// Sort by product name
usort($aggregated, fn($a,$b) => strcmp($a['product_name'], $b['product_name']));

echo json_encode([
    'orders' => $orders,
    'items'  => array_values($aggregated),
]);
