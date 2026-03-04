<?php
/**
 * Admin AJAX API - Quick Actions
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!isAdminLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    // Mark notification as read
    case 'mark_notification_read':
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->update('notifications', ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        }
        echo json_encode(['success' => true]);
        break;

    // Mark all notifications read
    case 'mark_all_notifications_read':
        $db->query("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0");
        echo json_encode(['success' => true]);
        break;

    // Quick stock update
    case 'update_stock':
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $type = $_POST['type'] ?? 'set'; // set, add, subtract
        
        if ($productId) {
            $product = $db->fetch("SELECT stock_quantity FROM products WHERE id = ?", [$productId]);
            if ($product) {
                $newQty = match($type) {
                    'add' => $product['stock_quantity'] + $quantity,
                    'subtract' => max(0, $product['stock_quantity'] - $quantity),
                    default => $quantity,
                };
                $db->update('products', ['stock_quantity' => $newQty], 'id = ?', [$productId]);
                logActivity(getAdminId(), 'stock_update', 'products', $productId);
                echo json_encode(['success' => true, 'new_quantity' => $newQty]);
            } else {
                echo json_encode(['error' => 'Product not found']);
            }
        } else {
            echo json_encode(['error' => 'Invalid product']);
        }
        break;

    // Quick order status update
    case 'update_order_status':
        $orderId = (int)($_POST['order_id'] ?? 0);
        $status = sanitize($_POST['status'] ?? '');
        $note = sanitize($_POST['note'] ?? '');
        
        $validStatuses = ['pending','processing','confirmed','shipped','delivered','cancelled','returned','on_hold','no_response','good_but_no_response','advance_payment','pending_return','pending_cancel','partial_delivered','lost'];
        if ($orderId && in_array($status, $validStatuses)) {
            $db->update('orders', ['order_status' => $status], 'id = ?', [$orderId]);
            $db->insert('order_status_history', [
                'order_id' => $orderId,
                'status' => $status,
                'note' => $note ?: "Status changed by admin",
                'changed_by' => getAdminId(),
            ]);
            logActivity(getAdminId(), 'status_change', 'orders', $orderId);
            
            // Award store credits ONLY on delivered
            if ($status === 'delivered') {
                try { awardOrderCredits($orderId); } catch (\Throwable $e) {}
            }
            // Refund store credits on cancellation
            if ($status === 'cancelled') {
                try { refundOrderCreditsOnCancel($orderId); } catch (\Throwable $e) {}
            }
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Invalid request']);
        }
        break;

    // Toggle product active status
    case 'toggle_product':
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId) {
            $product = $db->fetch("SELECT is_active FROM products WHERE id = ?", [$productId]);
            if ($product) {
                $db->update('products', ['is_active' => $product['is_active'] ? 0 : 1], 'id = ?', [$productId]);
                logActivity(getAdminId(), 'toggle', 'products', $productId);
                echo json_encode(['success' => true, 'is_active' => !$product['is_active']]);
            }
        }
        break;

    // Dashboard stats (for real-time updates)
    case 'dashboard_stats':
        echo json_encode([
            'success' => true,
            'stats' => getDashboardStats(),
        ]);
        break;

    // Search products (for order-add autocomplete)
    case 'search_products':
        $q = sanitize($_GET['q'] ?? '');
        if (strlen($q) >= 2) {
            $results = $db->fetchAll("SELECT id, name, sku, regular_price, sale_price, is_on_sale, stock_quantity FROM products WHERE is_active = 1 AND (name LIKE ? OR sku LIKE ?) LIMIT 20", ["%$q%", "%$q%"]);
            echo json_encode(['success' => true, 'products' => array_map(fn($p) => [
                'id' => $p['id'], 'name' => $p['name'], 'sku' => $p['sku'],
                'price' => getProductPrice($p), 'stock' => $p['stock_quantity'],
            ], $results)]);
        } else {
            echo json_encode(['products' => []]);
        }
        break;

    // Customer search
    case 'search_customers':
        $q = sanitize($_GET['q'] ?? '');
        if (strlen($q) >= 2) {
            $results = $db->fetchAll("SELECT id, name, phone, email, total_orders, total_spent FROM customers WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? LIMIT 10", ["%$q%", "%$q%", "%$q%"]);
            echo json_encode(['success' => true, 'customers' => $results]);
        } else {
            echo json_encode(['customers' => []]);
        }
        break;

    // Toggle order fake flag
    case 'toggle_fake':
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId) {
            $order = $db->fetch("SELECT is_fake FROM orders WHERE id = ?", [$orderId]);
            if ($order) {
                $db->update('orders', ['is_fake' => $order['is_fake'] ? 0 : 1], 'id = ?', [$orderId]);
                echo json_encode(['success' => true, 'is_fake' => !$order['is_fake']]);
            }
        }
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
