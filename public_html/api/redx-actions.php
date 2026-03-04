<?php
/**
 * RedX Actions API
 * Handles admin AJAX calls for RedX operations
 * Pattern matches steadfast-actions.php
 */

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
ob_start();

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/redx.php';
} catch (\Throwable $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Server include error: ' . $e->getMessage()]);
    exit;
}

ob_end_clean();

// Auth check
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized — please login to admin panel first']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    $rx = new RedXAPI();
    $db = Database::getInstance();

    switch ($action) {

        // ── Save RedX settings ──
        case 'save_settings':
            $fields = [
                'redx_api_token'               => $input['api_token'] ?? '',
                'redx_environment'             => ($input['environment'] ?? 'production') === 'sandbox' ? 'sandbox' : 'production',
                'redx_default_pickup_store_id' => $input['default_pickup_store_id'] ?? '',
                'redx_default_note'            => $input['default_note'] ?? '',
                'redx_default_weight'          => intval($input['default_weight'] ?? 500),
                'redx_send_product_names'      => ($input['send_product_names'] ?? '1') === '0' ? '0' : '1',
                'redx_active'                  => ($input['active'] ?? '1') === '0' ? '0' : '1',
                'redx_webhook_token'           => $input['webhook_token'] ?? '',
            ];
            foreach ($fields as $k => $v) {
                $rx->saveSetting($k, $v);
            }
            echo json_encode(['success' => true, 'message' => 'RedX settings saved successfully']);
            break;

        // ── Test connection ──
        case 'test_connection':
            // Allow testing with provided token before saving
            $testToken = $input['api_token'] ?? '';
            $testEnv   = $input['environment'] ?? 'production';
            $testRx    = $testToken ? new RedXAPI($testToken) : $rx;

            // Temporarily set env for test
            if ($testToken && $testEnv === 'sandbox') {
                // Create temp instance manually
                $testRx = new class($testToken) extends RedXAPI {
                    public function __construct($token) {
                        // Can't call parent in anonymous, so we'll just test the API
                    }
                };
                // Just do a raw test
                $baseUrl = $testEnv === 'sandbox'
                    ? 'https://sandbox.redx.com.bd/v1.0.0-beta'
                    : 'https://openapi.redx.com.bd/v1.0.0-beta';
                $ch = curl_init($baseUrl . '/pickup/stores');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_HTTPHEADER     => [
                        'API-ACCESS-TOKEN: Bearer ' . $testToken,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $resp     = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err      = curl_error($ch);
                curl_close($ch);

                if ($err) throw new \Exception("Connection error: {$err}");
                if ($httpCode === 401 || $httpCode === 403) throw new \Exception('Invalid API token — check your token in RedX developer portal');
                if ($httpCode >= 500) throw new \Exception("RedX server error (HTTP {$httpCode})");

                $data = json_decode($resp, true);
                $stores = $data['pickup_stores'] ?? [];
                echo json_encode([
                    'success'       => true,
                    'message'       => 'Connected to RedX! Found ' . count($stores) . ' pickup store(s)',
                    'pickup_stores' => $stores,
                ]);
                break;
            }

            // Normal test with saved config
            if (!$testRx->isConfigured()) throw new \Exception('RedX API token is not configured');
            $stores = $testRx->getPickupStores();
            $storeList = $stores['pickup_stores'] ?? [];
            echo json_encode([
                'success'       => true,
                'message'       => 'Connected! Found ' . count($storeList) . ' pickup store(s)',
                'pickup_stores' => $storeList,
            ]);
            break;

        // ── Upload single order ──
        case 'upload_order':
            $orderId = intval($input['order_id'] ?? 0);
            if (!$orderId) throw new \Exception('No order ID');
            $result = $rx->uploadOrder($orderId, $input['overrides'] ?? []);
            echo json_encode($result);
            break;

        // ── Bulk upload orders ──
        case 'bulk_upload':
            $orderIds = $input['order_ids'] ?? [];
            if (empty($orderIds)) throw new \Exception('No orders selected');
            $result = $rx->bulkUploadOrders($orderIds);
            echo json_encode($result);
            break;

        // ── Track a parcel ──
        case 'track_parcel':
            $trackingId = trim($input['tracking_id'] ?? '');
            if (!$trackingId) throw new \Exception('No tracking ID');
            $result = $rx->trackParcel($trackingId);
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        // ── Get parcel info ──
        case 'parcel_info':
            $trackingId = trim($input['tracking_id'] ?? '');
            if (!$trackingId) throw new \Exception('No tracking ID');
            $result = $rx->getParcelInfo($trackingId);
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        // ── Cancel parcel ──
        case 'cancel_parcel':
            $trackingId = trim($input['tracking_id'] ?? '');
            $reason     = trim($input['reason'] ?? '');
            if (!$trackingId) throw new \Exception('No tracking ID');
            $result = $rx->cancelParcel($trackingId, $reason);

            // Update local order too
            $order = $db->fetch(
                "SELECT id, order_status FROM orders WHERE courier_tracking_id = ? OR courier_consignment_id = ?",
                [$trackingId, $trackingId]
            );
            if ($order && !in_array($order['order_status'], ['delivered', 'returned', 'cancelled'])) {
                $db->update('orders', [
                    'order_status'  => 'pending_cancel',
                    'courier_status' => 'cancelled',
                    'updated_at'    => date('Y-m-d H:i:s'),
                ], 'id = ?', [$order['id']]);
                try {
                    $db->insert('order_status_history', [
                        'order_id' => $order['id'],
                        'status'   => 'pending_cancel',
                        'note'     => 'Cancelled on RedX' . ($reason ? ": {$reason}" : ''),
                    ]);
                } catch (\Throwable $e) {}
            }

            echo json_encode(['success' => true, 'message' => 'Parcel cancelled', 'data' => $result]);
            break;

        // ── Get areas ──
        case 'get_areas':
            $postCode = $input['post_code'] ?? '';
            $district = $input['district'] ?? '';
            if ($postCode) {
                $result = $rx->getAreasByPostCode($postCode);
            } elseif ($district) {
                $result = $rx->getAreasByDistrict($district);
            } else {
                $result = $rx->getAreas();
            }
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        // ── Get pickup stores ──
        case 'get_pickup_stores':
            $result = $rx->getPickupStores();
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        // ── Calculate charge ──
        case 'calculate_charge':
            $deliveryAreaId = intval($input['delivery_area_id'] ?? 0);
            $pickupAreaId   = intval($input['pickup_area_id'] ?? 0);
            $codAmount      = floatval($input['cod_amount'] ?? 0);
            $weight         = intval($input['weight'] ?? 500);
            if (!$deliveryAreaId) throw new \Exception('Delivery area ID required');
            if (!$pickupAreaId) throw new \Exception('Pickup area ID required');
            $result = $rx->calculateCharge($deliveryAreaId, $pickupAreaId, $codAmount, $weight);
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        // ── Sync single order status from RedX ──
        case 'sync_order':
            $trackingId = trim($input['tracking_id'] ?? '');
            if (!$trackingId) throw new \Exception('No tracking ID');
            $info = $rx->getParcelInfo($trackingId);
            $parcel = $info['parcel'] ?? [];
            $status = $parcel['status'] ?? '';

            echo json_encode([
                'success' => true,
                'status'  => $status,
                'data'    => $parcel,
            ]);
            break;

        // ── Bulk sync all RedX orders ──
        case 'bulk_sync':
            $limit = min(100, max(10, intval($input['limit'] ?? 50)));
            $redxStatusMap = [
                'pickup-pending'       => null,
                'pickup-processing'    => null,
                'ready-for-delivery'   => null,
                'delivery-in-progress' => null,
                'delivered'            => 'delivered',
                'agent-hold'           => 'on_hold',
                'agent-returning'      => 'pending_return',
                'returned'             => 'pending_return',
                'agent-area-change'    => null,
                'cancelled'            => 'pending_cancel',
            ];

            $orders = $db->fetchAll(
                "SELECT id, order_number, order_status, courier_tracking_id, courier_consignment_id
                 FROM orders
                 WHERE LOWER(courier_name) LIKE '%redx%'
                   AND order_status IN ('shipped','on_hold','pending_return','pending_cancel','partial_delivered')
                   AND (courier_tracking_id IS NOT NULL AND courier_tracking_id != '')
                 ORDER BY updated_at ASC
                 LIMIT ?",
                [$limit]
            );

            $results = ['total' => count($orders), 'updated' => 0, 'errors' => 0, 'skipped' => 0, 'details' => []];

            foreach ($orders as $order) {
                try {
                    $tid = $order['courier_tracking_id'] ?: $order['courier_consignment_id'];
                    if (empty($tid)) { $results['skipped']++; continue; }

                    $info = $rx->getParcelInfo($tid);
                    $parcel = $info['parcel'] ?? [];
                    $courierStatus = $parcel['status'] ?? '';

                    if (empty($courierStatus)) { $results['skipped']++; continue; }

                    $newStatus = $redxStatusMap[$courierStatus] ?? null;
                    $updateData = ['courier_status' => $courierStatus, 'updated_at' => date('Y-m-d H:i:s')];

                    if (isset($parcel['charge'])) {
                        $updateData['courier_delivery_charge'] = floatval($parcel['charge']);
                    }
                    if (isset($parcel['cash_collection_amount'])) {
                        $updateData['courier_cod_amount'] = floatval($parcel['cash_collection_amount']);
                    }

                    if ($newStatus && $newStatus !== $order['order_status']) {
                        if (in_array($order['order_status'], ['delivered', 'returned', 'cancelled'])) {
                            $results['skipped']++;
                            continue;
                        }
                        $updateData['order_status'] = $newStatus;
                        if ($newStatus === 'delivered') $updateData['delivered_at'] = date('Y-m-d H:i:s');

                        $db->update('orders', $updateData, 'id = ?', [$order['id']]);
                        try {
                            $db->insert('order_status_history', [
                                'order_id' => $order['id'],
                                'status'   => $newStatus,
                                'note'     => "RedX sync: {$courierStatus}",
                            ]);
                        } catch (\Throwable $e) {}

                        if ($newStatus === 'delivered') { try { awardOrderCredits($order['id']); } catch (\Throwable $e) {} }
                        if (in_array($newStatus, ['pending_cancel', 'pending_return'])) { try { refundOrderCreditsOnCancel($order['id']); } catch (\Throwable $e) {} }

                        $results['updated']++;
                        $results['details'][] = "#{$order['order_number']}: {$order['order_status']} → {$newStatus}";
                    } else {
                        $db->update('orders', $updateData, 'id = ?', [$order['id']]);
                        $results['skipped']++;
                    }

                    usleep(200000); // 200ms throttle
                } catch (\Throwable $e) {
                    $results['errors']++;
                    $results['details'][] = "#{$order['order_number']}: " . $e->getMessage();
                }
            }

            echo json_encode($results);
            break;

        // ── Webhook logs ──
        case 'webhook_logs':
            $limit = min(50, max(5, intval($input['limit'] ?? 10)));
            $logs  = $db->fetchAll(
                "SELECT * FROM courier_webhook_log WHERE courier = 'redx' ORDER BY id DESC LIMIT ?",
                [$limit]
            );
            echo json_encode(['success' => true, 'logs' => $logs]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
