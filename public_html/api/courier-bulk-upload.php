<?php
/**
 * Bulk Courier Upload API
 * Handles bulk uploads with detailed progress tracking
 * 
 * POST /api/courier-bulk-upload.php
 * {
 *   "courier": "pathao|steadfast|redx",
 *   "order_ids": [1,2,3]
 * }
 */

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
ob_start();

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../admin/includes/auth.php';
    requireAdmin();
    refreshAdminPermissions();
    if (!hasPermission('courier')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    require_once __DIR__ . '/../includes/functions.php';
} catch (\Throwable $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Auth error: ' . $e->getMessage()]);
    exit;
}

ob_end_clean();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$courier = strtolower(trim($input['courier'] ?? ''));
$orderIds = $input['order_ids'] ?? [];

if (empty($orderIds)) {
    echo json_encode(['success' => false, 'error' => 'No orders selected']);
    exit;
}

if (!in_array($courier, ['pathao', 'steadfast', 'redx'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid courier: ' . $courier]);
    exit;
}

$db = Database::getInstance();
$results = [
    'success' => true,
    'courier' => ucfirst($courier),
    'total' => count($orderIds),
    'uploaded' => 0,
    'failed' => 0,
    'orders' => [],
];

try {
    // Load courier API
    switch ($courier) {
        case 'pathao':
            require_once __DIR__ . '/pathao.php';
            $api = new PathaoAPI();
            if (!$api->isConfigured()) throw new \Exception('Pathao API not configured');
            break;
            
        case 'steadfast':
            require_once __DIR__ . '/steadfast.php';
            $api = new SteadfastAPI();
            if (!$api->isConfigured()) throw new \Exception('Steadfast API not configured');
            break;
            
        case 'redx':
            require_once __DIR__ . '/redx.php';
            $api = new RedXAPI();
            if (!$api->isConfigured()) throw new \Exception('RedX API not configured');
            break;
    }
    
    foreach ($orderIds as $orderId) {
        $orderId = intval($orderId);
        $orderResult = [
            'order_id' => $orderId,
            'success' => false,
            'consignment_id' => null,
            'tracking_id' => null,
            'message' => '',
            'order_number' => '',
        ];
        
        try {
            $order = $db->fetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
            if (!$order) {
                $orderResult['message'] = 'Order not found';
                $results['orders'][] = $orderResult;
                $results['failed']++;
                continue;
            }
            
            $orderResult['order_number'] = $order['order_number'];
            
            // Check if already uploaded
            if (!empty($order['courier_consignment_id'])) {
                $orderResult['message'] = 'Already uploaded (CID: ' . $order['courier_consignment_id'] . ')';
                $orderResult['consignment_id'] = $order['courier_consignment_id'];
                $orderResult['tracking_id'] = $order['courier_tracking_id'] ?? '';
                $orderResult['success'] = true;
                $results['orders'][] = $orderResult;
                $results['uploaded']++;
                continue;
            }
            
            // Upload based on courier
            switch ($courier) {
                case 'pathao':
                    $cityId = intval($order['pathao_city_id'] ?? 0);
                    $zoneId = intval($order['pathao_zone_id'] ?? 0);
                    $areaId = intval($order['pathao_area_id'] ?? 0);
                    
                    if (!$cityId || !$zoneId) {
                        $orderResult['message'] = 'City/Zone not set for Pathao';
                        $results['orders'][] = $orderResult;
                        $results['failed']++;
                        continue 2;
                    }
                    
                    $storeId = $api->setting('pathao_store_id');
                    $codAmount = ($order['payment_method'] === 'cod') ? floatval($order['total']) : 0;
                    
                    $items = $db->fetchAll("SELECT product_name, quantity FROM order_items WHERE order_id = ?", [$orderId]);
                    $itemDesc = array_map(fn($i) => $i['product_name'] . ($i['quantity'] > 1 ? ' x' . $i['quantity'] : ''), $items);
                    
                    $resp = $api->createOrder([
                        'store_id' => $storeId,
                        'merchant_order_id' => $order['order_number'],
                        'recipient_name' => $order['customer_name'],
                        'recipient_phone' => $order['customer_phone'],
                        'recipient_address' => $order['customer_address'],
                        'recipient_city' => $cityId,
                        'recipient_zone' => $zoneId,
                        'recipient_area' => $areaId,
                        'delivery_type' => 48,
                        'item_type' => 2,
                        'item_quantity' => count($items) ?: 1,
                        'item_weight' => 0.5,
                        'amount_to_collect' => $codAmount,
                        'item_description' => implode(', ', $itemDesc),
                        'special_instruction' => $order['notes'] ?? '',
                    ]);
                    
                    if (!empty($resp['data']['consignment_id'])) {
                        $cid = $resp['data']['consignment_id'];
                        $db->update('orders', [
                            'courier_name' => 'Pathao',
                            'courier_consignment_id' => $cid,
                            'courier_tracking_id' => $cid,
                            'pathao_consignment_id' => $cid,
                            'courier_status' => 'pending',
                            'courier_uploaded_at' => date('Y-m-d H:i:s'),
                            'order_status' => 'ready_to_ship',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ], 'id = ?', [$orderId]);
                        
                        try {
                            $db->insert('courier_uploads', [
                                'order_id' => $orderId,
                                'courier_provider' => 'pathao',
                                'consignment_id' => $cid,
                                'tracking_id' => $cid,
                                'status' => 'uploaded',
                                'response_data' => json_encode($resp),
                                'created_by' => getAdminId(),
                            ]);
                            $db->insert('order_status_history', [
                                'order_id' => $orderId,
                                'status' => 'ready_to_ship',
                                'note' => "Uploaded to Pathao. CID: {$cid}",
                                'changed_by' => getAdminId(),
                            ]);
                        } catch (\Throwable $e) {}
                        
                        $orderResult['success'] = true;
                        $orderResult['consignment_id'] = $cid;
                        $orderResult['tracking_id'] = $cid;
                        $orderResult['message'] = 'Uploaded successfully';
                        $results['uploaded']++;
                    } else {
                        $orderResult['message'] = $resp['message'] ?? $resp['errors'] ?? 'Pathao upload failed';
                        $results['failed']++;
                    }
                    break;
                    
                case 'steadfast':
                    $uploadResult = $api->uploadOrder($orderId);
                    if ($uploadResult['success']) {
                        $orderResult['success'] = true;
                        $orderResult['consignment_id'] = $uploadResult['consignment_id'] ?? '';
                        $orderResult['tracking_id'] = $uploadResult['tracking_code'] ?? $uploadResult['consignment_id'] ?? '';
                        $orderResult['message'] = $uploadResult['message'] ?? 'Uploaded successfully';
                        $results['uploaded']++;
                    } else {
                        $orderResult['message'] = $uploadResult['message'] ?? 'Steadfast upload failed';
                        $results['failed']++;
                    }
                    break;
                    
                case 'redx':
                    $uploadResult = $api->uploadOrder($orderId);
                    if ($uploadResult['success']) {
                        $orderResult['success'] = true;
                        $orderResult['consignment_id'] = $uploadResult['tracking_id'] ?? '';
                        $orderResult['tracking_id'] = $uploadResult['tracking_id'] ?? '';
                        $orderResult['message'] = $uploadResult['message'] ?? 'Uploaded successfully';
                        $results['uploaded']++;
                    } else {
                        $orderResult['message'] = $uploadResult['message'] ?? 'RedX upload failed';
                        $results['failed']++;
                    }
                    break;
            }
            
        } catch (\Throwable $e) {
            $orderResult['message'] = $e->getMessage();
            $results['failed']++;
        }
        
        $results['orders'][] = $orderResult;
        usleep(100000); // 100ms throttle between orders
    }
    
} catch (\Throwable $e) {
    $results['success'] = false;
    $results['error'] = $e->getMessage();
}

echo json_encode($results);
