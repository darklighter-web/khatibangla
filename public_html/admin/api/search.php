<?php
/**
 * Admin Search API - Live search for orders, customers
 * GET /admin/api/search.php?q=QUERY&limit=N&field=all|invoice|phone|name
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$q = trim($_GET['q'] ?? '');
$limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
$field = $_GET['field'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

if (strlen($q) < 1) {
    echo json_encode(['results' => [], 'total' => 0]);
    exit;
}

$where = [];
$params = [];
$cleanPhone = preg_replace('/[^0-9]/', '', $q);

if ($field === 'invoice') {
    $where[] = "(order_number LIKE ? OR order_number LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%M{$q}%"; // prefix M for order numbers
} elseif ($field === 'phone') {
    $where[] = "customer_phone LIKE ?";
    $params[] = "%{$cleanPhone}%";
} elseif ($field === 'name') {
    $where[] = "customer_name LIKE ?";
    $params[] = "%{$q}%";
} else {
    // All fields search
    $conditions = ["order_number LIKE ?", "customer_name LIKE ?"];
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    
    if (strlen($cleanPhone) >= 4) {
        $conditions[] = "customer_phone LIKE ?";
        $params[] = "%{$cleanPhone}%";
    }
    
    // Search courier tracking ID
    $conditions[] = "courier_tracking_id LIKE ?";
    $params[] = "%{$q}%";
    
    // Search courier consignment ID
    $conditions[] = "courier_consignment_id LIKE ?";
    $params[] = "%{$q}%";
    
    $where[] = "(" . implode(" OR ", $conditions) . ")";
}

$whereStr = implode(' AND ', $where);

// Count total
$total = 0;
try {
    $countRow = $db->fetch("SELECT COUNT(*) as cnt FROM orders WHERE {$whereStr}", $params);
    $total = intval($countRow['cnt']);
} catch (\Throwable $e) {}

// Fetch results
$results = [];
try {
    $rows = $db->fetchAll(
        "SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.customer_address,
                o.total, o.order_status, o.courier_name, o.courier_tracking_id, o.courier_status,
                o.shipping_method, o.payment_method, o.notes, o.created_at, o.updated_at,
                (SELECT GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity) SEPARATOR ', ') 
                 FROM order_items oi WHERE oi.order_id = o.id) as products_summary
         FROM orders o
         WHERE {$whereStr}
         ORDER BY o.created_at DESC
         LIMIT {$limit} OFFSET {$offset}",
        $params
    );
    
    foreach ($rows as $r) {
        $results[] = [
            'id' => $r['id'],
            'order_number' => $r['order_number'],
            'customer_name' => $r['customer_name'],
            'customer_phone' => $r['customer_phone'],
            'customer_address' => $r['customer_address'],
            'total' => number_format(floatval($r['total']), 2),
            'status' => getOrderStatusLabel($r['order_status']),
            'status_raw' => $r['order_status'],
            'status_badge' => getOrderStatusBadge($r['order_status']),
            'courier_name' => $r['courier_name'] ?: $r['shipping_method'] ?: '',
            'courier_tracking' => $r['courier_tracking_id'] ?? '',
            'courier_status' => $r['courier_status'] ?? '',
            'payment' => $r['payment_method'] ?? '',
            'notes' => $r['notes'] ?? '',
            'products' => $r['products_summary'] ?? '',
            'date' => date('d/m/Y, g:i a', strtotime($r['created_at'])),
            'updated' => $r['updated_at'] ? timeAgo($r['updated_at']) : '',
            'url' => adminUrl('pages/order-view.php?id=' . $r['id']),
        ];
    }
} catch (\Throwable $e) {
    $results = [];
}

echo json_encode([
    'results' => $results,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'query' => $q,
    'field' => $field,
]);
