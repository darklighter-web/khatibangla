<?php
/**
 * Pathao API AJAX Endpoint
 * All admin JS calls go through here
 */

// Suppress PHP errors from appearing as HTML - catch them as JSON instead
error_reporting(0);
ini_set('display_errors', 0);

// Set JSON header FIRST before any output
header('Content-Type: application/json');

// Start output buffering to catch any stray output
ob_start();

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/pathao.php';
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Server include error: ' . $e->getMessage()]);
    exit;
}

// Discard any output from includes (warnings, notices etc)
ob_end_clean();

// Auth check - must be logged in as admin
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login to admin.']);
    exit;
}

$__rawInput = file_get_contents('php://input');
$__jsonInput = json_decode($__rawInput, true) ?: [];
$action = $_GET['action'] ?? $_POST['action'] ?? $__jsonInput['action'] ?? '';

try {
    $pathao = new PathaoAPI();

    switch ($action) {

        case 'test_connection':
            echo json_encode($pathao->testConnection());
            break;

        case 'save_config':
            $data = $__jsonInput ?: $_POST;
            if (empty($data)) {
                echo json_encode(['success' => false, 'message' => 'No data received']);
                break;
            }
            $pathao->saveConfig($data);
            // Recreate with new config and test
            $pathao = new PathaoAPI();
            $result = $pathao->testConnection();
            $result['saved'] = true;
            echo json_encode($result);
            break;

        case 'get_cities':
            $r = $pathao->getCities();
            echo json_encode(['success' => true, 'data' => $r['data'] ?? $r]);
            break;

        case 'get_zones':
            $cityId = intval($_GET['city_id'] ?? 0);
            if (!$cityId) { echo json_encode(['success' => false, 'message' => 'city_id required']); break; }
            $r = $pathao->getZones($cityId);
            echo json_encode(['success' => true, 'data' => $r['data'] ?? $r]);
            break;

        case 'get_areas':
            $zoneId = intval($_GET['zone_id'] ?? 0);
            if (!$zoneId) { echo json_encode(['success' => false, 'message' => 'zone_id required']); break; }
            $r = $pathao->getAreas($zoneId);
            echo json_encode(['success' => true, 'data' => $r['data'] ?? $r]);
            break;

        case 'get_stores':
            $r = $pathao->getStores();
            echo json_encode(['success' => true, 'data' => $r['data'] ?? $r]);
            break;

        case 'price_calculation':
            $data = $__jsonInput ?: $_POST;
            $r = $pathao->getPriceCalculation($data);
            echo json_encode(['success' => true, 'data' => $r]);
            break;

        case 'create_pathao_order':
            $data = $__jsonInput ?: $_POST;
            $r = $pathao->createOrder($data);
            echo json_encode(['success' => !empty($r['data']), 'data' => $r]);
            break;

        case 'check_customer':
            $phone = $_GET['phone'] ?? $_POST['phone'] ?? '';
            $phone = preg_replace('/[^0-9+]/', '', $phone);
            if (empty($phone)) { echo json_encode(['success' => false, 'message' => 'Phone required']); break; }
            
            // Collect diagnostic info alongside the profile
            $diag = [
                'pathao_configured' => $pathao->isConfigured(),
                'pathao_connected'  => $pathao->isConnected(),
                'pathao_api_called' => false,
                'pathao_api_result' => null,
                'steadfast_checked' => false,
                'local_db_queried'  => false,
                'errors' => [],
            ];
            
            // Quick test: can we reach Pathao API?
            if ($pathao->isConfigured()) {
                try {
                    $token = $pathao->getAccessToken();
                    $diag['pathao_auth'] = $token ? 'OK' : 'FAILED';
                } catch (\Throwable $e) {
                    $diag['pathao_auth'] = 'ERROR: ' . $e->getMessage();
                    $diag['errors'][] = 'Pathao auth: ' . $e->getMessage();
                }
                
                // Try direct customer-check API call
                try {
                    $rawCheck = $pathao->checkCustomerPhone($phone);
                    $diag['pathao_api_called'] = true;
                    $diag['pathao_api_result'] = $rawCheck;
                } catch (\Throwable $e) {
                    $diag['errors'][] = 'Pathao customer-check: ' . $e->getMessage();
                }
            } else {
                $diag['errors'][] = 'Pathao API credentials not configured — go to Courier → Pathao tab to set up';
            }
            
            // Check Steadfast
            try {
                $sfFile = __DIR__ . '/steadfast.php';
                if (file_exists($sfFile)) {
                    require_once $sfFile;
                    $sfTest = new \SteadfastAPI();
                    $diag['steadfast_configured'] = $sfTest->isConfigured();
                } else {
                    $diag['steadfast_configured'] = false;
                    $diag['errors'][] = 'Steadfast API file not found';
                }
            } catch (\Throwable $e) {
                $diag['steadfast_configured'] = false;
            }
            
            // Now get the full profile
            try {
                $profile = $pathao->getCustomerProfile($phone);
                $diag['local_db_queried'] = true;
                $diag['local_orders_found'] = $profile['local_orders'] ?? 0;
            } catch (\Throwable $e) {
                $profile = ['total_orders' => 0, 'delivered' => 0, 'cancelled' => 0, 'returned' => 0, 'success_rate' => 0, 'risk_level' => 'new', 'risk_label' => 'New Customer', 'couriers' => [], 'api_notes' => ['PROFILE ERROR: ' . $e->getMessage()]];
                $diag['errors'][] = 'Profile build: ' . $e->getMessage();
            }
            
            echo json_encode([
                'success' => true,
                'data'    => $profile,
                'diag'    => $diag,
            ]);
            break;

        // Debug endpoint — shows raw API responses for troubleshooting
        case 'debug_customer_check':
            $phone = $_GET['phone'] ?? $_POST['phone'] ?? '';
            $phone = preg_replace('/[^0-9+]/', '', $phone);
            if (empty($phone)) { echo json_encode(['success' => false, 'message' => 'Phone required']); break; }
            $debug = ['phone' => $phone, 'is_configured' => $pathao->isConfigured(), 'is_connected' => $pathao->isConnected()];
            
            // Test auth
            try { $token = $pathao->getAccessToken(); $debug['auth'] = $token ? 'OK' : 'FAILED'; }
            catch (\Throwable $e) { $debug['auth'] = 'ERROR: ' . $e->getMessage(); }
            
            // Raw customer-check call
            try {
                $rawResp = $pathao->checkCustomerPhone($phone);
                $debug['customer_check'] = ['response' => $rawResp, 'response_keys' => $rawResp ? array_keys($rawResp) : null];
                if (isset($rawResp['data'])) {
                    $debug['customer_check']['data_keys'] = is_array($rawResp['data']) ? array_keys($rawResp['data']) : gettype($rawResp['data']);
                    $debug['customer_check']['data_content'] = $rawResp['data'];
                }
            } catch (\Throwable $e) { $debug['customer_check'] = ['error' => $e->getMessage()]; }
            
            // Full profile
            try { $debug['profile'] = $pathao->getCustomerProfile($phone); }
            catch (\Throwable $e) { $debug['profile_error'] = $e->getMessage(); }
            
            echo json_encode(['success' => true, 'debug' => $debug], JSON_PRETTY_PRINT);
            break;

        case 'verify_address':
            $data = $__jsonInput ?: $_POST;
            $result = $pathao->verifyAddress(
                intval($data['city_id'] ?? 0),
                intval($data['zone_id'] ?? 0),
                intval($data['area_id'] ?? 0)
            );
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        case 'area_stats':
            $days = intval($_GET['days'] ?? 90);
            $stats = $pathao->getAreaStats($days);
            echo json_encode(['success' => true, 'data' => $stats]);
            break;

        case 'city_stats':
            $days = intval($_GET['days'] ?? 90);
            $stats = $pathao->getCityStats($days);
            echo json_encode(['success' => true, 'data' => $stats]);
            break;

        case 'save_order_location':
            $data = $__jsonInput ?: $_POST;
            $orderId = intval($data['order_id'] ?? 0);
            $cityId   = intval($data['city_id'] ?? 0);
            $zoneId   = intval($data['zone_id'] ?? 0);
            $areaId   = intval($data['area_id'] ?? 0);
            $cityName = trim($data['city_name'] ?? '');
            $zoneName = trim($data['zone_name'] ?? '');
            $areaName = trim($data['area_name'] ?? '');
            if (!$orderId) { echo json_encode(['success' => false, 'message' => 'order_id required']); break; }
            $db = Database::getInstance();

            // Ensure columns exist
            foreach (['pathao_city_id INT DEFAULT NULL', 'pathao_zone_id INT DEFAULT NULL', 'pathao_area_id INT DEFAULT NULL', 'delivery_city_name VARCHAR(100) DEFAULT NULL', 'delivery_zone_name VARCHAR(100) DEFAULT NULL', 'delivery_area_name VARCHAR(100) DEFAULT NULL'] as $colDef) {
                try { $db->query("ALTER TABLE orders ADD COLUMN {$colDef}"); } catch (\Throwable $e) {}
            }

            $updates = ['updated_at' => date('Y-m-d H:i:s')];
            // Always write IDs (even 0/null to clear)
            $updates['pathao_city_id'] = $cityId ?: null;
            $updates['pathao_zone_id'] = $zoneId ?: null;
            $updates['pathao_area_id'] = $areaId ?: null;
            // Always write names (empty string to clear)
            $updates['delivery_city_name'] = $cityName !== '' ? $cityName : null;
            $updates['delivery_zone_name'] = $zoneName !== '' ? $zoneName : null;
            $updates['delivery_area_name'] = $areaName !== '' ? $areaName : null;

            $db->update('orders', $updates, 'id = ?', [$orderId]);
            echo json_encode(['success' => true]);
            break;

        case 'save_steadfast':
            $data = $__jsonInput;
            $db = Database::getInstance();
            foreach (['api_key','secret_key','webhook_token'] as $k) {
                if (isset($data[$k])) {
                    $key = 'steadfast_' . $k;
                    $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key]);
                    if ($exists) { $db->update('site_settings', ['setting_value' => $data[$k]], 'setting_key = ?', [$key]); }
                    else { $db->insert('site_settings', ['setting_key'=>$key,'setting_value'=>$data[$k],'setting_type'=>'text','setting_group'=>'steadfast','label'=>ucwords(str_replace('_',' ',$k))]); }
                }
            }
            echo json_encode(['success' => true]);
            break;

        case 'test_steadfast':
            require_once __DIR__ . '/steadfast.php';
            $sf = new SteadfastAPI();
            if (!$sf->isConfigured()) { echo json_encode(['success'=>false,'error'=>'Not configured']); break; }
            try {
                $resp = $sf->getBalance();
                echo json_encode(['success'=>true,'balance'=>$resp['current_balance']??0]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
            }
            break;

        case 'save_carrybee':
            $data = $__jsonInput;
            $db = Database::getInstance();
            foreach (['api_key','secret_key'] as $k) {
                if (isset($data[$k])) {
                    $key = 'carrybee_' . $k;
                    $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key]);
                    if ($exists) { $db->update('site_settings', ['setting_value' => $data[$k]], 'setting_key = ?', [$key]); }
                    else { $db->insert('site_settings', ['setting_key'=>$key,'setting_value'=>$data[$k],'setting_type'=>'text','setting_group'=>'carrybee','label'=>ucwords(str_replace('_',' ',$k))]); }
                }
            }
            echo json_encode(['success' => true]);
            break;

        case 'save_fraud_config':
            $data = $__jsonInput;
            $db = Database::getInstance();
            $fields = [
                'steadfast_merchant_email'    => 'Steadfast Merchant Email',
                'steadfast_merchant_password' => 'Steadfast Merchant Password',
                'redx_phone'                  => 'RedX Phone',
                'redx_password'               => 'RedX Password',
            ];
            foreach ($fields as $k => $label) {
                if (isset($data[$k])) {
                    $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$k]);
                    if ($exists) { $db->update('site_settings', ['setting_value' => $data[$k]], 'setting_key = ?', [$k]); }
                    else { $db->insert('site_settings', ['setting_key'=>$k,'setting_value'=>$data[$k],'setting_type'=>'text','setting_group'=>'courier','label'=>$label]); }
                }
            }
            echo json_encode(['success' => true, 'message' => 'Fraud check credentials saved']);
            break;

        case 'upload_pathao_order':
            $data = $__jsonInput ?: $_POST;
            $orderId = intval($data['order_id'] ?? 0);
            if (!$orderId) { echo json_encode(['success' => false, 'message' => 'order_id required']); break; }
            $db = Database::getInstance();
            $o = $db->fetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
            if (!$o) { echo json_encode(['success' => false, 'message' => 'Order not found']); break; }

            $cityId = intval($data['recipient_city'] ?? ($o['pathao_city_id'] ?? 0));
            $zoneId = intval($data['recipient_zone'] ?? ($o['pathao_zone_id'] ?? 0));
            $areaId = intval($data['recipient_area'] ?? ($o['pathao_area_id'] ?? 0));
            if (!$cityId || !$zoneId) { echo json_encode(['success' => false, 'message' => 'City and Zone required for Pathao']); break; }

            // Capture area names from JS (sent alongside IDs)
            $cityName = trim($data['city_name'] ?? '');
            $zoneName = trim($data['zone_name'] ?? '');
            $areaName = trim($data['area_name'] ?? '');

            $storeId = intval($data['store_id'] ?? 0);
            if (!$storeId) {
                try { $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'pathao_store_id'"); $storeId = intval($row['setting_value'] ?? 0); } catch (\Throwable $e) {}
            }
            if (!$storeId) {
                try { $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'store_id'"); $storeId = intval($row['setting_value'] ?? 0); } catch (\Throwable $e) {}
            }
            // Auto-fetch store_id from Pathao API if not set
            if (!$storeId) {
                try {
                    $storesResp = $pathao->getStores();
                    $stores = $storesResp['data']['data'] ?? $storesResp['data'] ?? [];
                    if (!empty($stores[0]['store_id'])) {
                        $storeId = intval($stores[0]['store_id']);
                        // Cache it for future use
                        try { $db->query("INSERT INTO site_settings (setting_key, setting_value) VALUES ('pathao_store_id', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$storeId, $storeId]); } catch (\Throwable $e) {}
                    }
                } catch (\Throwable $e) {}
            }
            if (!$storeId) { echo json_encode(['success' => false, 'message' => 'Pathao store_id not found. Go to Settings → Pathao and set your Store ID, or ensure Pathao API credentials are correct.']); break; }
            $codAmount = ($o['payment_method'] === 'cod') ? floatval($o['total']) : 0;

            // Build product description
            $itemDesc = 'Order #' . $o['order_number'];
            try {
                $items = $db->fetchAll("SELECT product_name, quantity FROM order_items WHERE order_id = ?", [$orderId]);
                $parts = [];
                foreach ($items as $it) $parts[] = $it['product_name'] . ($it['quantity'] > 1 ? ' x' . $it['quantity'] : '');
                if ($parts) $itemDesc = implode(', ', $parts);
            } catch (\Throwable $e) {}

            $payload = [
                'store_id'            => $storeId,
                'merchant_order_id'   => $o['order_number'],
                'recipient_name'      => $o['customer_name'],
                'recipient_phone'     => $o['customer_phone'],
                'recipient_address'   => $o['customer_address'],
                'recipient_city'      => $cityId,
                'recipient_zone'      => $zoneId,
                'recipient_area'      => $areaId,
                'delivery_type'       => 48,
                'item_type'           => 2,
                'item_quantity'       => 1,
                'item_weight'         => 0.5,
                'amount_to_collect'   => $codAmount,
                'item_description'    => $itemDesc,
                'special_instruction' => $o['notes'] ?? '',
            ];
            if (!$areaId) unset($payload['recipient_area']);

            $resp = $pathao->createOrder($payload);

            if (!empty($resp['data']['consignment_id'])) {
                $cid = $resp['data']['consignment_id'];
                // Ensure name columns exist
                foreach (['delivery_city_name VARCHAR(100) DEFAULT NULL', 'delivery_zone_name VARCHAR(100) DEFAULT NULL', 'delivery_area_name VARCHAR(100) DEFAULT NULL'] as $colDef) {
                    try { $db->query("ALTER TABLE orders ADD COLUMN {$colDef}"); } catch (\Throwable $e) {}
                }
                $updateData = [
                    'courier_consignment_id' => $cid,
                    'courier_name'           => 'Pathao',
                    'courier_tracking_id'    => $cid,
                    'courier_status'         => 'pending',
                    'courier_uploaded_at'    => date('Y-m-d H:i:s'),
                    'order_status'           => 'ready_to_ship',
                    'pathao_city_id'         => $cityId,
                    'pathao_zone_id'         => $zoneId,
                    'pathao_area_id'         => $areaId,
                    'updated_at'             => date('Y-m-d H:i:s'),
                ];
                if ($cityName !== '') $updateData['delivery_city_name'] = $cityName;
                if ($zoneName !== '') $updateData['delivery_zone_name'] = $zoneName;
                if ($areaName !== '') $updateData['delivery_area_name'] = $areaName;
                $db->update('orders', $updateData, 'id = ?', [$orderId]);
                try { $db->insert('courier_uploads', ['order_id'=>$orderId,'courier_provider'=>'pathao','consignment_id'=>$cid,'status'=>'uploaded','response_data'=>json_encode($resp)]); } catch (\Throwable $e) {}
                try { $db->insert('order_status_history', ['order_id'=>$orderId,'status'=>'ready_to_ship','note'=>'Uploaded to Pathao. CID: '.$cid,'changed_by'=>$_SESSION['admin_id']??null]); } catch (\Throwable $e) {}
                echo json_encode(['success' => true, 'consignment_id' => $cid, 'message' => 'Uploaded to Pathao']);
            } else {
                $errMsg = $resp['message'] ?? 'Pathao upload failed';
                if (!empty($resp['errors'])) $errMsg .= ' — ' . json_encode($resp['errors']);
                echo json_encode(['success' => false, 'message' => $errMsg, 'raw' => $resp]);
            }
            break;

        case 'toggle_area_backfill':
            $data = $__jsonInput ?: $_POST;
            $db = Database::getInstance();
            $enabled = intval($data['enabled'] ?? 0) ? '1' : '0';
            $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = 'area_backfill_enabled'");
            if ($exists) { $db->update('site_settings', ['setting_value' => $enabled], 'setting_key = ?', ['area_backfill_enabled']); }
            else { $db->insert('site_settings', ['setting_key' => 'area_backfill_enabled', 'setting_value' => $enabled, 'setting_type' => 'text', 'setting_group' => 'courier', 'label' => 'Auto Backfill Area Names']); }
            echo json_encode(['success' => true, 'enabled' => $enabled === '1']);
            break;

        case 'backfill_area_names':
            // Resolve Pathao city/zone/area names for orders that have IDs but no names
            $db = Database::getInstance();
            // Ensure columns exist
            foreach (['delivery_city_name VARCHAR(100) DEFAULT NULL', 'delivery_zone_name VARCHAR(100) DEFAULT NULL', 'delivery_area_name VARCHAR(100) DEFAULT NULL'] as $colDef) {
                try { $db->query("ALTER TABLE orders ADD COLUMN {$colDef}"); } catch (\Throwable $e) {}
            }

            // Get orders with pathao IDs but missing area names (max 50 per call)
            $rows = $db->fetchAll("SELECT id, pathao_city_id, pathao_zone_id, pathao_area_id FROM orders WHERE pathao_city_id IS NOT NULL AND pathao_city_id > 0 AND (delivery_city_name IS NULL OR delivery_city_name = '') ORDER BY id DESC LIMIT 50");
            if (empty($rows)) { echo json_encode(['success' => true, 'resolved' => 0, 'message' => 'All orders already have area names']); break; }

            // Fetch all cities once
            $citiesResp = $pathao->getCities();
            $cityList = $citiesResp['data']['data'] ?? $citiesResp['data'] ?? [];
            $cityMap = [];
            foreach ($cityList as $c) $cityMap[intval($c['city_id'] ?? 0)] = $c['city_name'] ?? '';

            // Cache zones/areas per ID to minimize API calls
            $zoneCache = [];
            $areaCache = [];
            $resolved = 0;

            foreach ($rows as $row) {
                $cId = intval($row['pathao_city_id'] ?? 0);
                $zId = intval($row['pathao_zone_id'] ?? 0);
                $aId = intval($row['pathao_area_id'] ?? 0);

                $cName = $cityMap[$cId] ?? '';
                $zName = '';
                $aName = '';

                // Resolve zone name
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

                // Resolve area name
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

                // Update order with resolved names
                $upd = [];
                if ($cName !== '') $upd['delivery_city_name'] = $cName;
                if ($zName !== '') $upd['delivery_zone_name'] = $zName;
                if ($aName !== '') $upd['delivery_area_name'] = $aName;
                if (!empty($upd)) {
                    $db->update('orders', $upd, 'id = ?', [$row['id']]);
                    $resolved++;
                }
            }

            $remaining = $db->fetch("SELECT COUNT(*) as cnt FROM orders WHERE pathao_city_id IS NOT NULL AND pathao_city_id > 0 AND (delivery_city_name IS NULL OR delivery_city_name = '')")['cnt'] ?? 0;
            // Update last run timestamp when all done
            if (intval($remaining) === 0) {
                try {
                    $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = 'area_backfill_last_run'");
                    if ($exists) { $db->update('site_settings', ['setting_value' => date('Y-m-d H:i:s')], 'setting_key = ?', ['area_backfill_last_run']); }
                    else { $db->insert('site_settings', ['setting_key' => 'area_backfill_last_run', 'setting_value' => date('Y-m-d H:i:s'), 'setting_type' => 'text', 'setting_group' => 'courier', 'label' => 'Area Backfill Last Run']); }
                } catch (\Throwable $e) {}
            }
            echo json_encode(['success' => true, 'resolved' => $resolved, 'remaining' => intval($remaining), 'message' => "Resolved {$resolved} orders" . ($remaining > 0 ? ", {$remaining} remaining" : '')]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . htmlspecialchars($action)]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'Fatal: ' . $e->getMessage()]);
}
