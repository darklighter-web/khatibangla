<?php
/**
 * Tracking API - receives beacons from frontend
 * Handles: incomplete order tracking, cart tracking, page events, enhanced visitor data
 */
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $db = Database::getInstance();
    $sessionId = session_id();
    $ip = getClientIP();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    switch ($action) {

        case 'track_incomplete':
            $step = sanitize($_POST['step'] ?? 'cart');
            $cartData = $_POST['cart'] ?? '[]';
            $cartTotal = floatval($_POST['total'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $phone = sanitize($_POST['phone'] ?? '');
            $address = sanitize($_POST['address'] ?? '');

            // Check for existing incomplete for this session
            // Try is_recovered first, fall back to recovered
            $colName = 'is_recovered';
            try {
                $existing = $db->fetch(
                    "SELECT id FROM incomplete_orders WHERE session_id = ? AND is_recovered = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)",
                    [$sessionId]
                );
            } catch (\Throwable $e) {
                $colName = 'recovered';
                $existing = $db->fetch(
                    "SELECT id FROM incomplete_orders WHERE session_id = ? AND recovered = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)",
                    [$sessionId]
                );
            }

            if ($existing) {
                $update = [
                    'step_reached' => $step,
                    'cart_data' => $cartData,
                ];
                // Safely set cart_total only if column exists
                try { $db->query("SELECT cart_total FROM incomplete_orders LIMIT 0"); $update['cart_total'] = $cartTotal; } catch(\Throwable $e) {}
                if ($name) $update['customer_name'] = $name;
                if ($phone) $update['customer_phone'] = $phone;
                // Safely set address if column exists
                if ($address) {
                    try { $db->query("SELECT customer_address FROM incomplete_orders LIMIT 0"); $update['customer_address'] = $address; } catch(\Throwable $e) {}
                }
                $db->update('incomplete_orders', $update, 'id = ?', [$existing['id']]);
            } else {
                $insertData = [
                    'session_id' => $sessionId,
                    'customer_phone' => $phone,
                    'customer_name' => $name,
                    'cart_data' => $cartData,
                    'step_reached' => $step,
                ];
                // Optional columns - add if they exist
                try { $db->query("SELECT cart_total FROM incomplete_orders LIMIT 0"); $insertData['cart_total'] = $cartTotal; } catch(\Throwable $e) {}
                try { $db->query("SELECT customer_address FROM incomplete_orders LIMIT 0"); $insertData['customer_address'] = $address; } catch(\Throwable $e) {}
                try { $db->query("SELECT device_ip FROM incomplete_orders LIMIT 0"); $insertData['device_ip'] = $ip; } catch(\Throwable $e) {}
                try { $db->query("SELECT visitor_id FROM incomplete_orders LIMIT 0"); $insertData['visitor_id'] = $_SESSION['visitor_id'] ?? null; } catch(\Throwable $e) {}
                try { $db->query("SELECT user_agent FROM incomplete_orders LIMIT 0"); $insertData['user_agent'] = mb_substr($ua, 0, 500); } catch(\Throwable $e) {}
                // Set ip_address if that's the column instead of device_ip
                if (!isset($insertData['device_ip'])) {
                    try { $db->query("SELECT ip_address FROM incomplete_orders LIMIT 0"); $insertData['ip_address'] = $ip; } catch(\Throwable $e) {}
                }
                
                $newId = $db->insert('incomplete_orders', $insertData);
                
                // Create admin notification for new incomplete order
                try {
                    $custLabel = $phone ?: 'Unknown visitor';
                    $db->insert('notifications', [
                        'type' => 'incomplete_order',
                        'title' => 'Incomplete Order Detected',
                        'message' => "{$custLabel} started checkout but didn't complete. Step: {$step}",
                        'link' => 'pages/incomplete-orders.php',
                        'is_read' => 0,
                        'user_id' => null,
                    ]);
                } catch (\Throwable $e) {}
            }
            echo json_encode(['success' => true]);
            break;

        case 'track_visitor_data':
            // Enhanced client-side data collection
            if (!empty($_SESSION['visitor_id'])) {
                $update = [];
                if (isset($_POST['screen_width'])) $update['screen_width'] = intval($_POST['screen_width']);
                if (isset($_POST['screen_height'])) $update['screen_height'] = intval($_POST['screen_height']);
                if (isset($_POST['language'])) $update['language'] = sanitize(substr($_POST['language'], 0, 10));
                if (isset($_POST['timezone'])) $update['timezone'] = sanitize(substr($_POST['timezone'], 0, 50));
                if (isset($_POST['connection_type'])) $update['connection_type'] = sanitize(substr($_POST['connection_type'], 0, 20));
                if (isset($_POST['is_touch'])) $update['is_touch'] = intval($_POST['is_touch']);
                if (isset($_POST['platform'])) $update['platform'] = sanitize(substr($_POST['platform'], 0, 50));
                if (isset($_POST['color_depth'])) $update['color_depth'] = intval($_POST['color_depth']);
                if (isset($_POST['cookies_enabled'])) $update['cookies_enabled'] = intval($_POST['cookies_enabled']);
                if (isset($_POST['utm_source'])) $update['utm_source'] = sanitize(substr($_POST['utm_source'], 0, 100));
                if (isset($_POST['utm_medium'])) $update['utm_medium'] = sanitize(substr($_POST['utm_medium'], 0, 100));
                if (isset($_POST['utm_campaign'])) $update['utm_campaign'] = sanitize(substr($_POST['utm_campaign'], 0, 100));
                
                if (!empty($update)) {
                    // Only update columns that exist
                    $safeUpdate = [];
                    foreach ($update as $col => $val) {
                        try { $db->query("SELECT $col FROM visitor_logs LIMIT 0"); $safeUpdate[$col] = $val; } catch(\Throwable $e) {}
                    }
                    if (!empty($safeUpdate)) {
                        $db->update('visitor_logs', $safeUpdate, 'id = ?', [$_SESSION['visitor_id']]);
                    }
                }
            }
            echo json_encode(['success' => true]);
            break;

        case 'track_cart':
            $productId = intval($_POST['product_id'] ?? 0);
            $action_type = sanitize($_POST['type'] ?? 'add');
            if (!empty($_SESSION['visitor_id'])) {
                $db->query(
                    "UPDATE visitor_logs SET cart_items = cart_items + ? WHERE id = ?",
                    [$action_type === 'add' ? 1 : -1, $_SESSION['visitor_id']]
                );
            }
            echo json_encode(['success' => true]);
            break;

        case 'track_page':
            if (!empty($_SESSION['visitor_id'])) {
                $db->query("UPDATE visitor_logs SET pages_viewed = pages_viewed + 1, last_activity = NOW() WHERE id = ?", [$_SESSION['visitor_id']]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'track_product_view':
            $productId = intval($_POST['product_id'] ?? 0);
            if ($productId > 0) {
                $customerId = $_SESSION['customer_id'] ?? null;
                $visitorId = $_SESSION['visitor_id'] ?? null;
                $sid = session_id();
                $deviceType = 'desktop';
                if (function_exists('detectDeviceType')) $deviceType = detectDeviceType($ua);

                // Upsert: increment view_count if same customer/session+product
                $lookupWhere = $customerId
                    ? "customer_id = ? AND product_id = ?"
                    : "session_id = ? AND product_id = ? AND customer_id IS NULL";
                $lookupParams = $customerId ? [$customerId, $productId] : [$sid, $productId];

                $existing = $db->fetch("SELECT id, view_count FROM customer_page_views WHERE {$lookupWhere} LIMIT 1", $lookupParams);

                if ($existing) {
                    $db->query("UPDATE customer_page_views SET view_count = view_count + 1, last_viewed_at = NOW(), visitor_id = COALESCE(visitor_id, ?) WHERE id = ?",
                        [$visitorId, $existing['id']]);
                } else {
                    try {
                        $db->insert('customer_page_views', [
                            'customer_id' => $customerId,
                            'visitor_id' => $visitorId,
                            'session_id' => $sid,
                            'product_id' => $productId,
                            'ip_address' => $ip,
                            'user_agent' => mb_substr($ua, 0, 500),
                            'device_type' => $deviceType,
                            'view_count' => 1,
                        ]);
                    } catch (\Throwable $e) {}
                }
            }
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
