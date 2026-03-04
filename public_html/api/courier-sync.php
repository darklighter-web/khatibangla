<?php
/**
 * Courier Status Sync (Polling)
 * Called from admin Order Management "Sync Courier Status" button
 * Polls Pathao & Steadfast APIs for all shipped orders and updates statuses
 * 
 * Also usable as a cron job: php /path/to/courier-sync.php
 * Or via URL: https://khatibangla.com/api/courier-sync.php?key=YOUR_CRON_KEY
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/courier-rate-limiter.php';

header('Content-Type: application/json');

// Allow cron access with a secret key, or admin session
$isCron = php_sapi_name() === 'cli' || ($_GET['key'] ?? '') === getSetting('courier_sync_key', '');
if (!$isCron) {
    // Check admin session
    require_once __DIR__ . '/../includes/session.php';
    if (empty($_SESSION['admin_id'])) {
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$db = Database::getInstance();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$limit = min(100, max(10, intval($input['limit'] ?? $_GET['limit'] ?? 50)));

// Status mapping (same as webhook)
$pathaoMap = [
    'Pending'=>null, 'Picked'=>'shipped', 'In_Transit'=>'shipped', 'At_Transit'=>'shipped',
    'Delivery_Ongoing'=>'shipped', 'Delivered'=>'delivered', 'Partial_Delivered'=>'partial_delivered',
    'Return'=>'pending_return', 'Return_Ongoing'=>'pending_return', 'Returned'=>'pending_return',
    'Exchange'=>'pending_return', 'Hold'=>'on_hold', 'Cancelled'=>'pending_cancel',
    'Payment_Invoice'=>'delivered',
];
$steadfastMap = [
    'pending'=>null, 'in_review'=>'shipped', 'delivered'=>'delivered',
    'delivered_approval_pending'=>'delivered', 'partial_delivered'=>'partial_delivered',
    'partial_delivered_approval_pending'=>'partial_delivered',
    'cancelled'=>'pending_cancel', 'cancelled_approval_pending'=>'pending_cancel',
    'hold'=>'on_hold', 'unknown'=>null, 'unknown_approval_pending'=>null,
];
$redxMap = [
    'pickup-pending'=>null, 'pickup-processing'=>null,
    'ready-for-delivery'=>'shipped', 'delivery-in-progress'=>'shipped',
    'delivered'=>'delivered', 'agent-hold'=>'on_hold',
    'agent-returning'=>'pending_return', 'returned'=>'pending_return',
    'agent-area-change'=>null, 'cancelled'=>'pending_cancel',
];

$results = ['total' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0, 'details' => []];

// Get all active orders with courier tracking (not yet delivered/cancelled/returned)
$activeStatuses = "'ready_to_ship','shipped','on_hold','pending_return','pending_cancel','partial_delivered'";
$orders = $db->fetchAll(
    "SELECT id, order_number, order_status, courier_name, courier_tracking_id, courier_consignment_id, pathao_consignment_id 
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
$pathaoFile = __DIR__ . '/pathao.php';
$steadfastFile = __DIR__ . '/steadfast.php';
$redxFile = __DIR__ . '/redx.php';

if (file_exists($pathaoFile)) {
    require_once $pathaoFile;
    $pathao = new PathaoAPI();
}
if (file_exists($steadfastFile)) {
    require_once $steadfastFile;
    $steadfast = new SteadfastAPI();
}
if (file_exists($redxFile)) {
    require_once $redxFile;
    $redx = new RedXAPI();
}

foreach ($orders as $order) {
    try {
        $courierName = strtolower($order['courier_name'] ?? '');
        $consignmentId = $order['pathao_consignment_id'] ?: $order['courier_consignment_id'] ?: $order['courier_tracking_id'];
        
        if (empty($consignmentId)) {
            $results['skipped']++;
            continue;
        }
        
        // Polling throttle: skip if this order was polled within 15 minutes
        if (!courierPollThrottle($order['id'], 900)) {
            $results['skipped']++;
            continue;
        }
        
        $courierStatus = null;
        $newStatus = null;
        
        if (strpos($courierName, 'pathao') !== false && $pathao && $pathao->isConfigured()) {
            // Poll Pathao API
            $resp = $pathao->getOrderDetails($consignmentId);
            $courierStatus = $resp['data']['order_status'] ?? $resp['order_status'] ?? null;
            if ($courierStatus) {
                $newStatus = $pathaoMap[$courierStatus] ?? null;
            }
        } elseif (strpos($courierName, 'steadfast') !== false && $steadfast && $steadfast->isConfigured()) {
            // Poll Steadfast API
            $resp = $steadfast->getStatusByCid($consignmentId);
            $courierStatus = $resp['delivery_status'] ?? $resp['data']['delivery_status'] ?? null;
            if ($courierStatus) {
                $newStatus = $steadfastMap[$courierStatus] ?? null;
            }
        } elseif (strpos($courierName, 'redx') !== false && $redx && $redx->isConfigured()) {
            // Poll RedX API
            $resp = $redx->getParcelInfo($consignmentId);
            $courierStatus = $resp['parcel']['status'] ?? null;
            if ($courierStatus) {
                $newStatus = $redxMap[$courierStatus] ?? null;
            }
        } else {
            $results['skipped']++;
            continue;
        }
        
        if (!$courierStatus) {
            $results['skipped']++;
            continue;
        }
        
        // Always update courier_status column with raw value
        $updateData = ['courier_status' => $courierStatus, 'updated_at' => date('Y-m-d H:i:s')];
        
        // RedX-specific: also update charge & COD from API response
        if (strpos($courierName, 'redx') !== false && isset($resp['parcel'])) {
            if (isset($resp['parcel']['charge'])) $updateData['courier_delivery_charge'] = floatval($resp['parcel']['charge']);
            if (isset($resp['parcel']['cash_collection_amount'])) $updateData['courier_cod_amount'] = floatval($resp['parcel']['cash_collection_amount']);
        }
        
        if ($newStatus && $newStatus !== $order['order_status']) {
            // Don't allow backward transitions from terminal states
            $terminalStatuses = ['delivered', 'returned', 'cancelled'];
            if (in_array($order['order_status'], $terminalStatuses)) {
                $results['skipped']++;
                $results['details'][] = "#{$order['order_number']}: Already {$order['order_status']}, courier says {$courierStatus}";
                continue;
            }
            
            $updateData['order_status'] = $newStatus;
            
            if ($newStatus === 'delivered') {
                $updateData['delivered_at'] = date('Y-m-d H:i:s');
            }
            
            $db->update('orders', $updateData, 'id = ?', [$order['id']]);
            
            // Log status history
            try {
                $db->insert('order_status_history', [
                    'order_id' => $order['id'],
                    'status' => $newStatus,
                    'note' => "Auto-sync: {$courierName} → {$courierStatus}",
                ]);
            } catch (\Throwable $e) {}
            
            // Trigger credit actions
            if ($newStatus === 'delivered') {
                try { awardOrderCredits($order['id']); } catch (\Throwable $e) {}
            }
            
            $results['updated']++;
            $results['details'][] = "#{$order['order_number']}: {$order['order_status']} → {$newStatus} ({$courierStatus})";
        } else {
            // Just update the raw courier status
            $db->update('orders', $updateData, 'id = ?', [$order['id']]);
            $results['skipped']++;
        }
        
        // Throttle to avoid rate limits (200ms between requests — max ~5 req/sec)
        usleep(200000);
        
    } catch (\Throwable $e) {
        $results['errors']++;
        $results['details'][] = "#{$order['order_number']}: Error - " . $e->getMessage();
    }
}

// Log sync run
try {
    $dir = dirname(__DIR__) . '/tmp';
    @mkdir($dir, 0755, true);
    $line = date('Y-m-d H:i:s') . " SYNC: total={$results['total']} updated={$results['updated']} errors={$results['errors']} skipped={$results['skipped']}\n";
    @file_put_contents($dir . '/courier-sync.log', $line, FILE_APPEND | LOCK_EX);
} catch (\Throwable $e) {}

/* ═══════════════════════════════════════════════════
   AUTO-BACKFILL: Resolve Pathao area names every 24h
   ═══════════════════════════════════════════════════ */
$results['backfill'] = null;
try {
    $backfillEnabled = getSetting('area_backfill_enabled', '0');
    if ($backfillEnabled === '1') {
        $lastRun = getSetting('area_backfill_last_run', '');
        $shouldRun = true;
        if ($lastRun) {
            $elapsed = time() - strtotime($lastRun);
            if ($elapsed < 86400) $shouldRun = false; // 24 hours
        }

        if ($shouldRun) {
            // Ensure name columns exist
            foreach (['delivery_city_name VARCHAR(100) DEFAULT NULL', 'delivery_zone_name VARCHAR(100) DEFAULT NULL', 'delivery_area_name VARCHAR(100) DEFAULT NULL'] as $colDef) {
                try { $db->query("ALTER TABLE orders ADD COLUMN {$colDef}"); } catch (\Throwable $e) {}
            }

            // Count how many need backfill
            $pending = intval($db->fetch("SELECT COUNT(*) as cnt FROM orders WHERE pathao_city_id IS NOT NULL AND pathao_city_id > 0 AND (delivery_city_name IS NULL OR delivery_city_name = '')")['cnt'] ?? 0);

            if ($pending > 0 && $pathao) {
                // Fetch Pathao city list once
                $citiesResp = $pathao->getCities();
                $cityList = $citiesResp['data']['data'] ?? $citiesResp['data'] ?? [];
                $cityMap = [];
                foreach ($cityList as $c) $cityMap[intval($c['city_id'] ?? 0)] = $c['city_name'] ?? '';

                $zoneCache = [];
                $areaCache = [];
                $resolved = 0;
                $maxBatch = 100; // Process up to 100 per cron run

                $rows = $db->fetchAll("SELECT id, pathao_city_id, pathao_zone_id, pathao_area_id FROM orders WHERE pathao_city_id IS NOT NULL AND pathao_city_id > 0 AND (delivery_city_name IS NULL OR delivery_city_name = '') ORDER BY id DESC LIMIT {$maxBatch}");

                foreach ($rows as $row) {
                    $cId = intval($row['pathao_city_id'] ?? 0);
                    $zId = intval($row['pathao_zone_id'] ?? 0);
                    $aId = intval($row['pathao_area_id'] ?? 0);
                    $cName = $cityMap[$cId] ?? '';
                    $zName = '';
                    $aName = '';

                    if ($zId && $cId) {
                        if (!isset($zoneCache[$cId])) {
                            try {
                                $zr = $pathao->getZones($cId);
                                $zoneCache[$cId] = [];
                                foreach (($zr['data']['data'] ?? $zr['data'] ?? []) as $z) $zoneCache[$cId][intval($z['zone_id'] ?? 0)] = $z['zone_name'] ?? '';
                            } catch (\Throwable $e) { $zoneCache[$cId] = []; }
                        }
                        $zName = $zoneCache[$cId][$zId] ?? '';
                    }

                    if ($aId && $zId) {
                        if (!isset($areaCache[$zId])) {
                            try {
                                $ar = $pathao->getAreas($zId);
                                $areaCache[$zId] = [];
                                foreach (($ar['data']['data'] ?? $ar['data'] ?? []) as $a) $areaCache[$zId][intval($a['area_id'] ?? 0)] = $a['area_name'] ?? '';
                            } catch (\Throwable $e) { $areaCache[$zId] = []; }
                        }
                        $aName = $areaCache[$zId][$aId] ?? '';
                    }

                    $upd = [];
                    if ($cName !== '') $upd['delivery_city_name'] = $cName;
                    if ($zName !== '') $upd['delivery_zone_name'] = $zName;
                    if ($aName !== '') $upd['delivery_area_name'] = $aName;
                    if (!empty($upd)) {
                        $db->update('orders', $upd, 'id = ?', [$row['id']]);
                        $resolved++;
                    }
                    usleep(50000); // 50ms throttle
                }

                $remaining = $pending - $resolved;
                $results['backfill'] = ['resolved' => $resolved, 'remaining' => max(0, $remaining)];

                // Log it
                try {
                    $line = date('Y-m-d H:i:s') . " BACKFILL: resolved={$resolved} remaining={$remaining}\n";
                    @file_put_contents($dir . '/courier-sync.log', $line, FILE_APPEND | LOCK_EX);
                } catch (\Throwable $e) {}
            } else {
                $results['backfill'] = ['resolved' => 0, 'remaining' => 0, 'note' => $pending == 0 ? 'all_done' : 'pathao_not_loaded'];
            }

            // Update last run timestamp
            $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = 'area_backfill_last_run'");
            if ($exists) { $db->update('site_settings', ['setting_value' => date('Y-m-d H:i:s')], 'setting_key = ?', ['area_backfill_last_run']); }
            else { $db->insert('site_settings', ['setting_key' => 'area_backfill_last_run', 'setting_value' => date('Y-m-d H:i:s'), 'setting_type' => 'text', 'setting_group' => 'courier', 'label' => 'Area Backfill Last Run']); }
        } else {
            $hoursLeft = round((86400 - (time() - strtotime($lastRun))) / 3600, 1);
            $results['backfill'] = ['skipped' => true, 'next_run_in_hours' => $hoursLeft];
        }
    }
} catch (\Throwable $e) {
    $results['backfill'] = ['error' => $e->getMessage()];
}

echo json_encode($results);
