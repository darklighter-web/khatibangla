<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
refreshAdminPermissions();

header('Content-Type: application/json');

$db = Database::getInstance();

// Return ordered queue of processing order IDs (oldest first)
// Optional: apply search/date filters via GET params
$where = "order_status IN ('processing','pending')";
$params = [];

$search = trim($_GET['search'] ?? '');
if ($search) {
    $where .= " AND (order_number LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';
if ($dateFrom) { $where .= " AND DATE(created_at) >= ?"; $params[] = $dateFrom; }
if ($dateTo)   { $where .= " AND DATE(created_at) <= ?"; $params[] = $dateTo; }

$orders = $db->fetchAll(
    "SELECT id, order_number, customer_name FROM orders WHERE {$where} ORDER BY created_at ASC",
    $params
);

echo json_encode([
    'queue' => array_column($orders, 'id'),
    'total' => count($orders),
    'orders' => $orders,
]);
