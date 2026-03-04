<?php
/**
 * Steadfast Actions API
 * Handles admin AJAX calls for Steadfast operations
 * 
 * Pattern matches pathao-api.php (the working reference)
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
    require_once __DIR__ . '/steadfast.php';
} catch (\Throwable $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Server include error: ' . $e->getMessage()]);
    exit;
}

// Discard any output from includes (warnings, notices etc)
ob_end_clean();

// Auth check - must be logged in as admin
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized — please login to admin panel first']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    $sf = new SteadfastAPI();
    $db = Database::getInstance();

    switch ($action) {
        // ── Upload single order to Steadfast ──
        case 'upload_order':
            $orderId = intval($input['order_id'] ?? 0);
            if (!$orderId) throw new \Exception('No order ID');
            $result = $sf->uploadOrder($orderId, $input['overrides'] ?? []);
            echo json_encode($result);
            break;

        // ── Bulk upload orders ──
        case 'bulk_upload':
            $orderIds = $input['order_ids'] ?? [];
            if (empty($orderIds)) throw new \Exception('No orders selected');
            $result = $sf->bulkUploadOrders($orderIds);
            echo json_encode($result);
            break;

        // ── Sync single order status (universal — Pathao + Steadfast) ──
        case 'sync_status':
        case 'sync_courier':
            $orderId = intval($input['order_id'] ?? 0);
            if (!$orderId) throw new \Exception('No order ID');
            
            $order = $db->fetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
            if (!$order) throw new \Exception('Order not found');
            
            $courierName = strtolower($order['courier_name'] ?? $order['shipping_method'] ?? '');
            $cid = $order['courier_consignment_id'] ?: ($order['pathao_consignment_id'] ?? '') ?: ($order['courier_tracking_id'] ?? '');
            
            if (empty($cid)) throw new \Exception('No consignment ID on this order');
            
            // ── Steadfast sync ──
            if (strpos($courierName, 'steadfast') !== false) {
                $result = $sf->syncOrderStatus($orderId);
                // Enrich with live data
                $result['courier_name'] = 'Steadfast';
                $result['consignment_id'] = $cid;
                echo json_encode($result);
            }
            // ── Pathao sync ──
            elseif (strpos($courierName, 'pathao') !== false) {
                $pathaoFile = __DIR__ . '/pathao.php';
                if (!file_exists($pathaoFile)) throw new \Exception('Pathao API not configured');
                require_once $pathaoFile;
                $pathao = new \PathaoAPI();
                
                if (!$pathao->isConfigured()) throw new \Exception('Pathao API not configured');
                
                $resp = $pathao->getOrderDetails($cid);
                $pathaoStatus = $resp['data']['order_status'] ?? $resp['order_status'] ?? null;
                
                // Pathao status mapping
                $pathaoMap = [
                    'Pending'=>null, 'Picked'=>'shipped', 'In_Transit'=>'shipped', 'At_Transit'=>'shipped',
                    'Delivery_Ongoing'=>'shipped', 'Delivered'=>'delivered', 'Partial_Delivered'=>'partial_delivered',
                    'Return'=>'pending_return', 'Return_Ongoing'=>'pending_return', 'Returned'=>'pending_return',
                    'Exchange'=>'pending_return', 'Hold'=>'on_hold', 'Cancelled'=>'pending_cancel',
                    'Payment_Invoice'=>'delivered',
                ];
                
                $newStatus = $pathaoMap[$pathaoStatus] ?? null;
                $updateData = ['courier_status' => $pathaoStatus, 'updated_at' => date('Y-m-d H:i:s')];
                
                // Extract tracking/charge data from Pathao response
                $trackingMsg = $resp['data']['updated_at'] ?? '';
                $deliveryCharge = $resp['data']['delivery_fee'] ?? $resp['data']['total_fee'] ?? 0;
                $codAmount = $resp['data']['cod_amount'] ?? $resp['data']['item_price'] ?? 0;
                
                if ($trackingMsg) $updateData['courier_tracking_message'] = 'Pathao: ' . $pathaoStatus . ' — ' . $trackingMsg;
                if ($deliveryCharge) $updateData['courier_delivery_charge'] = floatval($deliveryCharge);
                if ($codAmount) $updateData['courier_cod_amount'] = floatval($codAmount);
                
                // Update order status if changed
                $terminalStatuses = ['delivered', 'returned', 'cancelled'];
                if ($newStatus && $newStatus !== $order['order_status'] && !in_array($order['order_status'], $terminalStatuses)) {
                    $updateData['order_status'] = $newStatus;
                    if ($newStatus === 'delivered') $updateData['delivered_at'] = date('Y-m-d H:i:s');
                    
                    try {
                        $db->insert('order_status_history', [
                            'order_id' => $orderId,
                            'status' => $newStatus,
                            'note' => "Auto-sync: Pathao → {$pathaoStatus}",
                        ]);
                    } catch (\Throwable $e) {}
                    
                    if ($newStatus === 'delivered') { try { awardOrderCredits($orderId); } catch (\Throwable $e) {} }
                }
                
                $db->update('orders', $updateData, 'id = ?', [$orderId]);
                
                echo json_encode([
                    'success' => true,
                    'courier_name' => 'Pathao',
                    'courier_status' => $pathaoStatus,
                    'live_status' => $pathaoStatus,
                    'order_status' => $newStatus ?: $order['order_status'],
                    'tracking_message' => $updateData['courier_tracking_message'] ?? '',
                    'delivery_charge' => $deliveryCharge,
                    'cod_amount' => $codAmount,
                    'consignment_id' => $cid,
                    'message' => "Pathao status: {$pathaoStatus}",
                    'raw' => $resp['data'] ?? $resp,
                ]);
            }
            else {
                // Unknown courier — just return what we have
                echo json_encode([
                    'success' => true,
                    'courier_name' => $order['courier_name'] ?? 'Unknown',
                    'courier_status' => $order['courier_status'] ?? 'unknown',
                    'consignment_id' => $cid,
                    'message' => 'No API sync available for this courier',
                ]);
            }
            break;

        // ── Bulk sync all shipped orders ──
        case 'bulk_sync':
            $limit = min(100, intval($input['limit'] ?? 50));
            $orders = $db->fetchAll(
                "SELECT id, order_number, courier_consignment_id FROM orders 
                 WHERE courier_name = 'Steadfast' 
                   AND order_status IN ('shipped','on_hold','pending_return','pending_cancel','partial_delivered')
                   AND courier_consignment_id IS NOT NULL AND courier_consignment_id != ''
                 ORDER BY updated_at ASC LIMIT {$limit}"
            );
            $results = ['total' => count($orders), 'updated' => 0, 'errors' => 0, 'details' => []];
            foreach ($orders as $o) {
                try {
                    $r = $sf->syncOrderStatus($o['id']);
                    if ($r['success']) {
                        $results['updated']++;
                        $results['details'][] = "#{$o['order_number']}: {$r['courier_status']}";
                    }
                } catch (\Throwable $e) {
                    $results['errors']++;
                    $results['details'][] = "#{$o['order_number']}: " . $e->getMessage();
                }
                usleep(200000); // 200ms delay between API calls
            }
            echo json_encode($results);
            break;

        // ── Check balance ──
        case 'check_balance':
            $resp = $sf->getBalance();
            echo json_encode([
                'success' => true,
                'balance' => $resp['current_balance'] ?? $resp['balance'] ?? 0,
                'raw'     => $resp,
            ]);
            break;

        // ── Test connection ──
        case 'test_connection':
            $testKey = $input['api_key'] ?? '';
            $testSecret = $input['secret_key'] ?? '';
            if ($testKey && $testSecret) {
                $testSf = new SteadfastAPI($testKey, $testSecret);
                $resp = $testSf->getBalance();
            } else {
                $resp = $sf->getBalance();
            }
            if (isset($resp['current_balance']) || isset($resp['balance'])) {
                echo json_encode(['success' => true, 'balance' => $resp['current_balance'] ?? $resp['balance'] ?? 0]);
            } else {
                echo json_encode(['success' => false, 'error' => $resp['message'] ?? 'Connection failed', 'raw' => $resp]);
            }
            break;

        // ── Save settings ──
        case 'save_settings':
            $fields = [
                'api_key'            => 'steadfast_api_key',
                'secret_key'         => 'steadfast_secret_key',
                'webhook_token'      => 'steadfast_webhook_token',
                'email'              => 'steadfast_email',
                'password'           => 'steadfast_password',
                'default_note'       => 'steadfast_default_note',
                'send_product_names' => 'steadfast_send_product_names',
                'active'             => 'steadfast_active',
            ];
            foreach ($fields as $short => $key) {
                if (isset($input[$short])) {
                    $val = $input[$short];
                    // Trim whitespace from keys/tokens to prevent auth failures
                    if (in_array($short, ['api_key', 'secret_key', 'webhook_token'])) $val = trim($val);
                    $sf->saveSetting($key, $val);
                }
            }
            echo json_encode(['success' => true, 'message' => 'Settings saved!']);
            break;

        // ── Check consignment status ──
        case 'check_consignment':
            $cid = $input['consignment_id'] ?? '';
            if (empty($cid)) throw new \Exception('No consignment ID');
            // Try by CID first, then by invoice
            try {
                $resp = $sf->getStatusByCid($cid);
            } catch (\Throwable $e) {
                $resp = $sf->getStatusByInvoice($cid);
            }
            echo json_encode(['success' => true, 'data' => $resp]);
            break;

        // ── Get delivery rate for a phone number ──
        case 'delivery_rate':
            $phone = preg_replace('/[^0-9]/', '', $input['phone'] ?? '');
            if (strlen($phone) < 10) throw new \Exception('Invalid phone');
            
            $overall = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status IN ('cancelled','returned') THEN 1 ELSE 0 END) as cancelled FROM orders WHERE customer_phone LIKE ?", ['%'.substr($phone,-10).'%']);
            $sfRate = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered FROM orders WHERE customer_phone LIKE ? AND LOWER(courier_name) LIKE 'steadfast%'", ['%'.substr($phone,-10).'%']);
            
            $total = intval($overall['total'] ?? 0);
            $rate = $total > 0 ? round(intval($overall['delivered'] ?? 0) / $total * 100) : 0;
            
            echo json_encode([
                'success'  => true,
                'phone'    => $phone,
                'total'    => $total,
                'delivered'=> intval($overall['delivered'] ?? 0),
                'cancelled'=> intval($overall['cancelled'] ?? 0),
                'rate'     => $rate,
                'steadfast'=> [
                    'total'    => intval($sfRate['total'] ?? 0),
                    'delivered'=> intval($sfRate['delivered'] ?? 0),
                ],
            ]);
            break;

        // ── Get webhook logs ──
        case 'webhook_logs':
            $limit = min(50, intval($input['limit'] ?? 20));
            try {
                $logs = $db->fetchAll("SELECT * FROM courier_webhook_log WHERE courier = 'steadfast' ORDER BY created_at DESC LIMIT {$limit}");
                echo json_encode(['success' => true, 'logs' => $logs]);
            } catch (\Throwable $e) {
                // Table may not exist yet
                echo json_encode(['success' => true, 'logs' => [], 'note' => 'Webhook log table not yet created']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
