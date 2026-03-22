<?php
/**
 * Order Lock System — prevents multiple admins from processing the same order simultaneously.
 * 
 * Actions: acquire, release, heartbeat, check, takeover, check_bulk
 * Lock expires after 45 seconds of no heartbeat.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../admin/includes/auth.php';

header('Content-Type: application/json');

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$db = Database::getInstance();

// ── Auto-create table ──
try {
    $db->query("CREATE TABLE IF NOT EXISTS order_locks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL UNIQUE,
        admin_user_id INT NOT NULL,
        admin_name VARCHAR(100) NOT NULL DEFAULT '',
        locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_heartbeat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_order (order_id),
        INDEX idx_heartbeat (last_heartbeat)
    ) ENGINE=InnoDB");
} catch (\Throwable $e) {}

// ── Clean expired locks (no heartbeat for 90s) ──
try {
    // Clean expired locks AND corrupt locks (admin_user_id=0/NULL/empty name = invalid)
    $db->query("DELETE FROM order_locks WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 90 SECOND) OR admin_user_id = 0 OR admin_user_id IS NULL OR admin_name = ''");
} catch (\Throwable $e) {}

$action   = $_POST['action'] ?? $_GET['action'] ?? '';
$orderId  = intval($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
$adminId  = getAdminId();
$adminName = '';
try { $au = $db->fetch("SELECT full_name, username FROM admin_users WHERE id = ?", [$adminId]); $adminName = trim($au['full_name'] ?? '') ?: ($au['username'] ?? ''); } catch (\Throwable $e) {}
if (!$adminName) $adminName = trim($_SESSION['admin_name'] ?? '') ?: 'Admin #'.$adminId;

// ── ACQUIRE LOCK ──
if ($action === 'acquire') {
    if (!$orderId) { echo json_encode(['success' => false, 'error' => 'No order ID']); exit; }
    
    // Check if already locked by someone else
    $existing = $db->fetch("SELECT * FROM order_locks WHERE order_id = ? AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 90 SECOND)", [$orderId]);
    
    if ($existing && intval($existing['admin_user_id']) !== $adminId) {
        // If the lock has admin_user_id=0 it's corrupt — auto-clear and proceed
        if (intval($existing['admin_user_id']) === 0) {
            try { $db->query("DELETE FROM order_locks WHERE order_id = ?", [$orderId]); } catch (\Throwable $e) {}
            $existing = null; // fall through to acquire
        }
    }
    if ($existing && intval($existing['admin_user_id']) !== $adminId) {
        // Active lock by another user — never return empty name
        $lockerName = trim($existing['admin_name'] ?? '');
        if (!$lockerName) $lockerName = 'Another user';
        echo json_encode([
            'success' => false,
            'locked' => true,
            'locked_by' => $lockerName,
            'locked_by_id' => intval($existing['admin_user_id']),
            'locked_at' => $existing['locked_at'],
        ]);
        exit;
    }
    
    // Acquire or refresh own lock
    try {
        $db->query("INSERT INTO order_locks (order_id, admin_user_id, admin_name, locked_at, last_heartbeat) 
                     VALUES (?, ?, ?, NOW(), NOW()) 
                     ON DUPLICATE KEY UPDATE admin_user_id = VALUES(admin_user_id), admin_name = VALUES(admin_name), locked_at = NOW(), last_heartbeat = NOW()",
                     [$orderId, $adminId, $adminName]);
    } catch (\Throwable $e) {
        // Fallback: delete then insert
        $db->query("DELETE FROM order_locks WHERE order_id = ?", [$orderId]);
        $db->insert('order_locks', [
            'order_id' => $orderId,
            'admin_user_id' => $adminId,
            'admin_name' => $adminName,
            'locked_at' => date('Y-m-d H:i:s'),
            'last_heartbeat' => date('Y-m-d H:i:s'),
        ]);
    }
    
    echo json_encode(['success' => true, 'locked_by' => $adminName, 'locked_by_id' => $adminId]);
    exit;
}

// ── HEARTBEAT (keep lock alive) ──
if ($action === 'heartbeat') {
    if (!$orderId) { echo json_encode(['success' => false]); exit; }
    
    // Check if someone else took over
    $existing = $db->fetch("SELECT * FROM order_locks WHERE order_id = ?", [$orderId]);
    
    if (!$existing || intval($existing['admin_user_id']) !== $adminId) {
        // Lock was taken over or expired
        $takenBy = $existing['admin_name'] ?? 'Unknown';
        echo json_encode([
            'success' => false,
            'taken_over' => true,
            'taken_by' => $takenBy,
            'taken_by_id' => intval($existing['admin_user_id'] ?? 0),
        ]);
        exit;
    }
    
    // Refresh heartbeat
    $db->query("UPDATE order_locks SET last_heartbeat = NOW() WHERE order_id = ? AND admin_user_id = ?", [$orderId, $adminId]);
    echo json_encode(['success' => true]);
    exit;
}

// ── TAKEOVER ──
if ($action === 'takeover') {
    if (!$orderId) { echo json_encode(['success' => false, 'error' => 'No order ID']); exit; }
    
    // Force-acquire the lock
    try {
        $db->query("INSERT INTO order_locks (order_id, admin_user_id, admin_name, locked_at, last_heartbeat)
                     VALUES (?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE admin_user_id = VALUES(admin_user_id), admin_name = VALUES(admin_name), locked_at = NOW(), last_heartbeat = NOW()",
                     [$orderId, $adminId, $adminName]);
    } catch (\Throwable $e) {
        $db->query("DELETE FROM order_locks WHERE order_id = ?", [$orderId]);
        $db->insert('order_locks', [
            'order_id' => $orderId,
            'admin_user_id' => $adminId,
            'admin_name' => $adminName,
            'locked_at' => date('Y-m-d H:i:s'),
            'last_heartbeat' => date('Y-m-d H:i:s'),
        ]);
    }
    
    echo json_encode(['success' => true, 'locked_by' => $adminName, 'locked_by_id' => $adminId]);
    exit;
}

// ── RELEASE LOCK ──
if ($action === 'release') {
    if (!$orderId) { echo json_encode(['success' => false]); exit; }
    $db->query("DELETE FROM order_locks WHERE order_id = ? AND admin_user_id = ?", [$orderId, $adminId]);
    echo json_encode(['success' => true]);
    exit;
}

// ── CHECK single order ──
if ($action === 'check') {
    if (!$orderId) { echo json_encode(['success' => false]); exit; }
    $lock = $db->fetch("SELECT * FROM order_locks WHERE order_id = ? AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 90 SECOND)", [$orderId]);
    if ($lock) {
        $lockName = trim($lock['admin_name'] ?? '');
        if (!$lockName) $lockName = 'Another user';
        echo json_encode([
            'success' => true,
            'locked' => true,
            'locked_by' => $lockName,
            'locked_by_id' => intval($lock['admin_user_id']),
            'is_mine' => intval($lock['admin_user_id']) === $adminId,
        ]);
    } else {
        echo json_encode(['success' => true, 'locked' => false]);
    }
    exit;
}

// ── CHECK BULK (for order list page) ──
if ($action === 'check_bulk') {
    $orderIds = $_POST['order_ids'] ?? $_GET['order_ids'] ?? '';
    if (is_string($orderIds)) $orderIds = array_filter(array_map('intval', explode(',', $orderIds)));
    if (empty($orderIds)) { echo json_encode(['success' => true, 'locks' => []]); exit; }
    
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $locks = $db->fetchAll("SELECT * FROM order_locks WHERE order_id IN ({$placeholders}) AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 90 SECOND)", $orderIds);
    
    $result = [];
    foreach ($locks as $l) {
        $bulkName = trim($l['admin_name'] ?? '');
        if (!$bulkName) $bulkName = 'Another user';
        $result[$l['order_id']] = [
            'locked_by' => $bulkName,
            'locked_by_id' => intval($l['admin_user_id']),
            'is_mine' => intval($l['admin_user_id']) === $adminId,
        ];
    }
    echo json_encode(['success' => true, 'locks' => $result]);
    exit;
}

// ── ACTIVE VIEWER COUNT (for processing page header) ──
if ($action === 'active_count') {
    $count = 0;
    try {
        $row = $db->fetch("SELECT COUNT(DISTINCT admin_user_id) as cnt FROM order_locks WHERE last_heartbeat >= DATE_SUB(NOW(), INTERVAL 90 SECOND) AND admin_user_id != ?", [$adminId]);
        $count = intval($row['cnt'] ?? 0);
        // Also get names
        $others = $db->fetchAll("SELECT DISTINCT admin_name FROM order_locks WHERE last_heartbeat >= DATE_SUB(NOW(), INTERVAL 90 SECOND) AND admin_user_id != ? LIMIT 5", [$adminId]);
    } catch (\Throwable $e) {}
    echo json_encode(['success'=>true,'count'=>$count,'others'=>array_column($others??[], 'admin_name')]);
    exit;
}

// ── CO-VIEWERS for a single order ──
if ($action === 'co_viewers') {
    if (!$orderId) { echo json_encode(['success'=>false,'viewers',[]]); exit; }
    try {
        $viewers = $db->fetchAll("SELECT admin_name, locked_at FROM order_locks WHERE order_id = ? AND admin_user_id != ? AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 90 SECOND)", [$orderId, $adminId]);
    } catch (\Throwable $e) { $viewers = []; }
    echo json_encode(['success'=>true,'viewers'=>$viewers]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
