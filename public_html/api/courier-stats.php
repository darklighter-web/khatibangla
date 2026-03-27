<?php
/**
 * Customer Courier Stats API
 * 
 * Actions:
 *   recheck  — Sync courier statuses from APIs, then rebuild cache for visible orders
 *   get      — Return cached stats for given phone numbers (from cache table, 12h TTL)
 *   rebuild  — Force rebuild cache from orders table (no courier API calls)
 * 
 * Cache: customer_courier_stats table, 12-hour TTL
 * Rate limiting: Uses courier-rate-limiter.php for API calls
 */
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
ob_start();

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../admin/includes/auth.php';
    requireAdmin();
    refreshAdminPermissions();
} catch (\Throwable $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Auth: ' . $e->getMessage()]);
    exit;
}
ob_end_clean();

$db = Database::getInstance();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

// Ensure cache table exists
try {
    $db->query("CREATE TABLE IF NOT EXISTS `customer_courier_stats` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `phone_hash` varchar(32) NOT NULL,
        `phone_display` varchar(20) NOT NULL,
        `total_orders` int(11) NOT NULL DEFAULT 0,
        `delivered` int(11) NOT NULL DEFAULT 0,
        `cancelled` int(11) NOT NULL DEFAULT 0,
        `returned` int(11) NOT NULL DEFAULT 0,
        `success_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
        `total_spent` decimal(12,2) NOT NULL DEFAULT 0.00,
        `courier_breakdown` text DEFAULT NULL,
        `fetched_at` datetime NOT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_phone_hash` (`phone_hash`),
        KEY `idx_fetched` (`fetched_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3");
} catch (\Throwable $e) {}

// ──────────────────────────────────────────────
// Helper: normalize phone to hash key
// ──────────────────────────────────────────────
function phoneHash($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    $last11 = substr($clean, -11);
    return md5($last11);
}

function phoneLike($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    return '%' . substr($clean, -11);
}

// ──────────────────────────────────────────────
// Helper: build stats for one phone from orders table
// ──────────────────────────────────────────────
function buildStatsForPhone($db, $phone) {
    $pl = phoneLike($phone);
    $sr = $db->fetch("SELECT COUNT(*) as total, 
        SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, 
        SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled, 
        SUM(CASE WHEN order_status='returned' THEN 1 ELSE 0 END) as returned, 
        SUM(total) as total_spent 
        FROM orders WHERE REPLACE(REPLACE(customer_phone,' ',''),'-','') LIKE ?", [$pl]);
    
    $t = intval($sr['total'] ?? 0);
    $d = intval($sr['delivered'] ?? 0);
    $c = intval($sr['cancelled'] ?? 0);
    $r = intval($sr['returned'] ?? 0);
    $rate = $t > 0 ? round(($d / $t) * 100, 2) : 0;
    
    // Per-courier breakdown
    $breakdown = [];
    try {
        $cbRows = $db->fetchAll("SELECT COALESCE(NULLIF(courier_name,''),NULLIF(shipping_method,''),'Unknown') as cn, 
            COUNT(*) as total, 
            SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered
            FROM orders WHERE REPLACE(REPLACE(customer_phone,' ',''),'-','') LIKE ? 
            AND (courier_name IS NOT NULL AND courier_name != '' OR shipping_method IS NOT NULL AND shipping_method != '') 
            GROUP BY cn", [$pl]);
        foreach ($cbRows as $cb) {
            $breakdown[] = ['name' => $cb['cn'], 'delivered' => intval($cb['delivered']), 'total' => intval($cb['total'])];
        }
    } catch (\Throwable $e) {}
    
    return [
        'total' => $t, 'delivered' => $d, 'cancelled' => $c, 'returned' => $r,
        'rate' => $rate, 'total_spent' => floatval($sr['total_spent'] ?? 0),
        'courier_breakdown' => $breakdown,
    ];
}

// ──────────────────────────────────────────────
// Helper: upsert cache row
// ──────────────────────────────────────────────
function upsertCache($db, $phone, $stats) {
    $hash = phoneHash($phone);
    $now = date('Y-m-d H:i:s');
    $breakdownJson = json_encode($stats['courier_breakdown'] ?? []);
    
    try {
        $existing = $db->fetch("SELECT id FROM customer_courier_stats WHERE phone_hash = ?", [$hash]);
        if ($existing) {
            $db->update('customer_courier_stats', [
                'phone_display' => $phone,
                'total_orders' => $stats['total'],
                'delivered' => $stats['delivered'],
                'cancelled' => $stats['cancelled'],
                'returned' => $stats['returned'],
                'success_rate' => $stats['rate'],
                'total_spent' => $stats['total_spent'],
                'courier_breakdown' => $breakdownJson,
                'fetched_at' => $now,
            ], 'id = ?', [$existing['id']]);
        } else {
            $db->insert('customer_courier_stats', [
                'phone_hash' => $hash,
                'phone_display' => $phone,
                'total_orders' => $stats['total'],
                'delivered' => $stats['delivered'],
                'cancelled' => $stats['cancelled'],
                'returned' => $stats['returned'],
                'success_rate' => $stats['rate'],
                'total_spent' => $stats['total_spent'],
                'courier_breakdown' => $breakdownJson,
                'fetched_at' => $now,
            ]);
        }
    } catch (\Throwable $e) {}
}

// ──────────────────────────────────────────────
// ACTION: get — return cached stats for phone list
// ──────────────────────────────────────────────
if ($action === 'get') {
    $phones = $input['phones'] ?? [];
    $ttlHours = 12;
    $results = [];
    
    foreach ($phones as $phone) {
        $hash = phoneHash($phone);
        $cached = $db->fetch("SELECT * FROM customer_courier_stats WHERE phone_hash = ? AND fetched_at > DATE_SUB(NOW(), INTERVAL ? HOUR)", [$hash, $ttlHours]);
        
        if ($cached) {
            $results[$phone] = [
                'total' => intval($cached['total_orders']),
                'delivered' => intval($cached['delivered']),
                'cancelled' => intval($cached['cancelled']),
                'returned' => intval($cached['returned']),
                'rate' => floatval($cached['success_rate']),
                'total_spent' => floatval($cached['total_spent']),
                'courier_breakdown' => json_decode($cached['courier_breakdown'] ?? '[]', true) ?: [],
                'cached' => true,
                'fetched_at' => $cached['fetched_at'],
            ];
        } else {
            // Cache miss or expired — build from orders table
            $stats = buildStatsForPhone($db, $phone);
            upsertCache($db, $phone, $stats);
            $stats['cached'] = false;
            $stats['fetched_at'] = date('Y-m-d H:i:s');
            $results[$phone] = $stats;
        }
    }
    
    echo json_encode(['success' => true, 'stats' => $results]);
    exit;
}

// ──────────────────────────────────────────────
// ACTION: recheck — sync from courier APIs then rebuild cache
// ──────────────────────────────────────────────
if ($action === 'recheck') {
    require_once __DIR__ . '/courier-rate-limiter.php';
    
    $limit = min(100, max(10, intval($input['limit'] ?? 50)));
    $results = ['success' => true, 'total' => 0, 'synced' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];
    
    // Status mappings
    $pathaoMap = ['Pending'=>null,'Picked'=>'shipped','In_Transit'=>'shipped','At_Transit'=>'shipped','Delivery_Ongoing'=>'shipped','Delivered'=>'delivered','Partial_Delivered'=>'partial_delivered','Return'=>'pending_return','Return_Ongoing'=>'pending_return','Returned'=>'pending_return','Exchange'=>'pending_return','Hold'=>'on_hold','Cancelled'=>'pending_cancel','Payment_Invoice'=>'delivered'];
    $steadfastMap = ['pending'=>null,'in_review'=>'shipped','delivered'=>'delivered','delivered_approval_pending'=>'delivered','partial_delivered'=>'partial_delivered','partial_delivered_approval_pending'=>'partial_delivered','cancelled'=>'pending_cancel','cancelled_approval_pending'=>'pending_cancel','hold'=>'on_hold','unknown'=>null,'unknown_approval_pending'=>null];
    $redxMap = ['pickup-pending'=>null,'pickup-processing'=>null,'ready-for-delivery'=>'shipped','delivery-in-progress'=>'shipped','delivered'=>'delivered','agent-hold'=>'on_hold','agent-returning'=>'pending_return','returned'=>'pending_return','cancelled'=>'pending_cancel'];
    
    // Get active orders with courier IDs
    $activeStatuses = "'ready_to_ship','shipped','on_hold','pending_return','pending_cancel','partial_delivered','processing','confirmed'";
    $orders = $db->fetchAll(
        "SELECT id, order_number, order_status, courier_name, courier_tracking_id, courier_consignment_id, pathao_consignment_id, customer_phone, total
         FROM orders 
         WHERE order_status IN ({$activeStatuses}) 
           AND (courier_tracking_id IS NOT NULL AND courier_tracking_id != '' 
                OR courier_consignment_id IS NOT NULL AND courier_consignment_id != ''
                OR pathao_consignment_id IS NOT NULL AND pathao_consignment_id != '')
         ORDER BY updated_at ASC 
         LIMIT {$limit}"
    );
    
    $results['total'] = count($orders);
    
    // Load API classes
    $pathao = null; $steadfast = null; $redx = null;
    try { require_once __DIR__ . '/pathao.php'; $pathao = new PathaoAPI(); } catch (\Throwable $e) {}
    try { require_once __DIR__ . '/steadfast.php'; $steadfast = new SteadfastAPI(); } catch (\Throwable $e) {}
    try { require_once __DIR__ . '/redx.php'; $redx = new RedXAPI(); } catch (\Throwable $e) {}
    
    $phonesToRebuild = [];
    
    foreach ($orders as $order) {
        $courierName = strtolower($order['courier_name'] ?? '');
        $cid = $order['pathao_consignment_id'] ?: ($order['courier_consignment_id'] ?: $order['courier_tracking_id']);
        
        if (empty($cid)) { $results['skipped']++; continue; }
        
        // Rate limit check (1 req/sec effective)
        $courierKey = strpos($courierName, 'pathao') !== false ? 'pathao' : (strpos($courierName, 'steadfast') !== false ? 'steadfast' : (strpos($courierName, 'redx') !== false ? 'redx' : ''));
        if (!$courierKey) { $results['skipped']++; continue; }
        
        if (!courierRateCheck($courierKey, courierRateLimit($courierKey))) {
            $results['skipped']++;
            $results['details'][] = "#{$order['order_number']}: Rate limited ({$courierKey})";
            continue;
        }
        
        try {
            $courierStatus = null;
            $trackingMsg = '';
            
            if ($courierKey === 'pathao' && $pathao && $pathao->isConfigured()) {
                $resp = $pathao->getOrderDetails($cid);
                $courierStatus = $resp['data']['order_status'] ?? $resp['order_status'] ?? null;
                $trackingMsg = $resp['data']['tracking_message'] ?? '';
            } elseif ($courierKey === 'steadfast' && $steadfast && $steadfast->isConfigured()) {
                $resp = $steadfast->getStatusByCid($cid);
                $courierStatus = $resp['delivery_status'] ?? $resp['data']['delivery_status'] ?? null;
                $trackingMsg = $resp['tracking_message'] ?? $resp['data']['tracking_message'] ?? '';
            } elseif ($courierKey === 'redx' && $redx && $redx->isConfigured()) {
                $resp = $redx->getParcelInfo($cid);
                $courierStatus = $resp['parcel']['status'] ?? null;
                $trackingMsg = $resp['parcel']['tracking_message'] ?? '';
            }
            
            if (!$courierStatus) { $results['skipped']++; continue; }
            
            $statusMap = $courierKey === 'pathao' ? $pathaoMap : ($courierKey === 'steadfast' ? $steadfastMap : $redxMap);
            $newStatus = $statusMap[$courierStatus] ?? null;
            
            // Update courier_status on the order
            $updateData = ['courier_status' => $courierStatus, 'updated_at' => date('Y-m-d H:i:s')];
            if ($trackingMsg) $updateData['courier_tracking_message'] = ucfirst($courierKey) . ': ' . $trackingMsg;
            
            // Update order_status if mapped and not terminal
            $terminal = ['delivered', 'returned', 'cancelled'];
            if ($newStatus && $newStatus !== $order['order_status'] && !in_array($order['order_status'], $terminal)) {
                $updateData['order_status'] = $newStatus;
                if ($newStatus === 'delivered') $updateData['delivered_at'] = date('Y-m-d H:i:s');
                
                try { $db->insert('order_status_history', ['order_id' => $order['id'], 'status' => $newStatus, 'note' => "Recheck: {$courierKey} → {$courierStatus}"]); } catch (\Throwable $e) {}
                
                // Accounting entries
                if ($newStatus === 'delivered') {
                    try { $ex = $db->fetch("SELECT id FROM accounting_entries WHERE reference_type='order' AND reference_id=? AND entry_type='income'", [$order['id']]);
                        if (!$ex) $db->insert('accounting_entries', ['entry_type'=>'income','amount'=>floatval($order['total']),'reference_type'=>'order','reference_id'=>$order['id'],'description'=>'Order #'.$order['order_number'].' delivered (recheck)','entry_date'=>date('Y-m-d')]);
                    } catch (\Throwable $e) {}
                }
                
                $results['updated']++;
                $results['details'][] = "#{$order['order_number']}: {$order['order_status']} → {$newStatus} ({$courierStatus})";
            }
            
            $db->update('orders', $updateData, 'id = ?', [$order['id']]);
            $results['synced']++;
            
            // Mark phone for cache rebuild
            if (!empty($order['customer_phone'])) {
                $phonesToRebuild[$order['customer_phone']] = true;
            }
            
            // 1 second delay between API calls
            usleep(100000); // 100ms (rate limiter handles the rest)
            
        } catch (\Throwable $e) {
            $results['errors']++;
            $results['details'][] = "#{$order['order_number']}: Error — " . $e->getMessage();
        }
    }
    
    // Rebuild cache for all affected phones
    $results['cache_rebuilt'] = 0;
    foreach ($phonesToRebuild as $phone => $_) {
        try {
            $stats = buildStatsForPhone($db, $phone);
            upsertCache($db, $phone, $stats);
            $results['cache_rebuilt']++;
        } catch (\Throwable $e) {}
    }
    
    echo json_encode($results);
    exit;
}

// ──────────────────────────────────────────────
// ACTION: rebuild — rebuild cache from orders table (no API calls)
// ──────────────────────────────────────────────
if ($action === 'rebuild') {
    $phones = $input['phones'] ?? [];
    $rebuilt = 0;
    
    if (empty($phones)) {
        // Rebuild for all unique phones
        $allPhones = $db->fetchAll("SELECT DISTINCT customer_phone FROM orders WHERE customer_phone IS NOT NULL AND customer_phone != '' LIMIT 500");
        $phones = array_column($allPhones, 'customer_phone');
    }
    
    foreach ($phones as $phone) {
        try {
            $stats = buildStatsForPhone($db, $phone);
            upsertCache($db, $phone, $stats);
            $rebuilt++;
        } catch (\Throwable $e) {}
    }
    
    echo json_encode(['success' => true, 'rebuilt' => $rebuilt]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
