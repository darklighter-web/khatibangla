<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
refreshAdminPermissions();
if (!hasPermission('orders')) {
    echo json_encode(['error'=>'Permission denied']); exit;
}

header('Content-Type: application/json');

$db  = Database::getInstance();
$me  = getAdminId();

// Clean expired locks (45s timeout)
try { $db->query("DELETE FROM order_locks WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 45 SECOND)"); } catch (\Throwable $e) {}

// Build filter
$where  = "o.order_status IN ('processing','pending')";
$params = [];

$search   = trim($_GET['search']   ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
if ($search)   { $where .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)"; $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]); }
if ($dateFrom) { $where .= " AND DATE(o.created_at) >= ?"; $params[] = $dateFrom; }
if ($dateTo)   { $where .= " AND DATE(o.created_at) <= ?"; $params[] = $dateTo; }

// Fetch all matching orders
$orders = $db->fetchAll(
    "SELECT o.id, o.order_number, o.customer_name,
            ol.admin_user_id AS lock_uid, ol.admin_name AS lock_name
     FROM orders o
     LEFT JOIN order_locks ol
       ON ol.order_id = o.id
       AND ol.last_heartbeat >= DATE_SUB(NOW(), INTERVAL 45 SECOND)
     WHERE {$where}
     ORDER BY o.created_at ASC",
    $params
);

$queue   = [];   // IDs available to me
$skipped = [];   // [admin_name => count]
$skippedIds = [];

foreach ($orders as $row) {
    $lockUid = $row['lock_uid'] ? intval($row['lock_uid']) : null;
    if ($lockUid === null || $lockUid === $me) {
        // Not locked or locked by me — include
        $queue[] = (int)$row['id'];
    } else {
        // Locked by someone else — skip
        $name = $row['lock_name'] ?: 'Someone';
        $skipped[$name] = ($skipped[$name] ?? 0) + 1;
        $skippedIds[] = (int)$row['id'];
    }
}

echo json_encode([
    'queue'      => $queue,
    'total'      => count($queue),
    'skipped'    => $skipped,      // {name: count}
    'skippedIds' => $skippedIds,
    'totalAll'   => count($orders),
]);
