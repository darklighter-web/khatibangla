<?php
/**
 * CSV Export API for Admin Panel
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

// Check admin auth
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die('Unauthorized');
}

$type = $_GET['type'] ?? '';
$db = Database::getInstance();

switch ($type) {
    case 'orders':
        $filters = [];
        $params = [];
        $where = '1=1';
        
        if (!empty($_GET['status'])) {
            $where .= ' AND o.order_status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['from'])) {
            $where .= ' AND o.created_at >= ?';
            $params[] = $_GET['from'] . ' 00:00:00';
        }
        if (!empty($_GET['to'])) {
            $where .= ' AND o.created_at <= ?';
            $params[] = $_GET['to'] . ' 23:59:59';
        }
        
        $orders = $db->fetchAll("SELECT o.*, 
            (SELECT GROUP_CONCAT(oi.product_name SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) as items_list
            FROM orders o WHERE $where ORDER BY o.created_at DESC", $params);
        
        $filename = 'orders-' . date('Y-m-d') . '.csv';
        $headers = ['Order #', 'Date', 'Customer', 'Phone', 'Email', 'Address', 'City', 'Items', 'Subtotal', 'Shipping', 'Discount', 'Total', 'Status', 'Payment', 'Source', 'Admin Note'];
        
        $rows = array_map(function($o) {
            return [
                $o['order_number'],
                $o['created_at'],
                $o['customer_name'],
                $o['customer_phone'],
                $o['customer_email'],
                $o['customer_address'],
                $o['customer_city'],
                $o['items_list'],
                $o['subtotal'],
                $o['shipping_cost'],
                $o['discount_amount'],
                $o['total'],
                $o['order_status'],
                $o['payment_method'],
                $o['channel'],
                $o['admin_notes'],
            ];
        }, $orders);
        break;
        
    case 'products':
        $products = $db->fetchAll("SELECT p.*, c.name as category_name,
            (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as image
            FROM products p LEFT JOIN categories c ON c.id = p.category_id ORDER BY p.created_at DESC");
        
        $filename = 'products-' . date('Y-m-d') . '.csv';
        $headers = ['ID', 'Name', 'SKU', 'Category', 'Regular Price', 'Sale Price', 'On Sale', 'Stock', 'Status', 'Created'];
        
        $rows = array_map(function($p) {
            return [
                $p['id'],
                $p['name'],
                $p['sku'],
                $p['category_name'],
                $p['regular_price'],
                $p['sale_price'],
                $p['is_on_sale'] ? 'Yes' : 'No',
                $p['stock_quantity'],
                $p['is_active'] ? 'Active' : 'Inactive',
                $p['created_at'],
            ];
        }, $products);
        break;
        
    case 'customers':
        $customers = $db->fetchAll("SELECT c.*,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) as total_orders,
            (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.customer_id = c.id AND o.order_status = 'delivered') as total_spent
            FROM customers c ORDER BY c.created_at DESC");
        
        $filename = 'customers-' . date('Y-m-d') . '.csv';
        $headers = ['ID', 'Name', 'Phone', 'Email', 'Total Orders', 'Total Spent', 'Risk Score', 'Status', 'Joined'];
        
        $rows = array_map(function($c) {
            return [
                $c['id'],
                $c['name'],
                $c['phone'],
                $c['email'],
                $c['total_orders'],
                $c['total_spent'],
                $c['risk_score'] ?? 0,
                ($c['is_blocked'] ?? 0) ? 'Blocked' : 'Active',
                $c['created_at'],
            ];
        }, $customers);
        break;
        
    case 'expenses':
        $expenses = $db->fetchAll("SELECT * FROM expenses ORDER BY expense_date DESC");
        
        $filename = 'expenses-' . date('Y-m-d') . '.csv';
        $headers = ['ID', 'Category', 'Amount', 'Description', 'Reference', 'Date', 'Created By'];
        
        $rows = array_map(function($e) {
            return [
                $e['id'],
                $e['category'],
                $e['amount'],
                $e['description'],
                $e['reference_number'] ?? '',
                $e['expense_date'],
                $e['created_by'],
            ];
        }, $expenses);
        break;
        
    case 'accounting':
        $entries = $db->fetchAll("SELECT * FROM accounting_entries ORDER BY entry_date DESC, created_at DESC");
        
        $filename = 'accounting-' . date('Y-m-d') . '.csv';
        $headers = ['ID', 'Type', 'Amount', 'Description', 'Reference Type', 'Reference ID', 'Date'];
        
        $rows = array_map(function($e) {
            return [
                $e['id'],
                $e['entry_type'],
                $e['amount'],
                $e['description'],
                $e['reference_type'],
                $e['reference_id'],
                $e['entry_date'],
            ];
        }, $entries);
        break;
        
    case 'inventory':
        $items = $db->fetchAll("SELECT p.id, p.name, p.sku, p.stock_quantity, p.low_stock_threshold,
            c.name as category_name,
            CASE WHEN p.stock_quantity <= p.low_stock_threshold THEN 'Low Stock' 
                 WHEN p.stock_quantity = 0 THEN 'Out of Stock' 
                 ELSE 'In Stock' END as stock_status
            FROM products p LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.is_active = 1 ORDER BY p.stock_quantity ASC");
        
        $filename = 'inventory-' . date('Y-m-d') . '.csv';
        $headers = ['ID', 'Name', 'SKU', 'Category', 'Stock Qty', 'Low Stock Threshold', 'Status'];
        
        $rows = array_map(function($i) {
            return [
                $i['id'],
                $i['name'],
                $i['sku'],
                $i['category_name'],
                $i['stock_quantity'],
                $i['low_stock_threshold'],
                $i['stock_status'],
            ];
        }, $items);
        break;
        
    default:
        http_response_code(400);
        die('Invalid export type');
}

// Output CSV with BOM for Excel UTF-8 compatibility
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, $headers);
foreach ($rows as $row) {
    fputcsv($output, $row);
}

fclose($output);
logActivity($_SESSION['admin_id'], 'export', $type);
exit;
