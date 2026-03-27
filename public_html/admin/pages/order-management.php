<?php
require_once __DIR__ . '/../../includes/session.php';
if (!isset($pageTitle)) $pageTitle = 'Order Management';
require_once __DIR__ . '/../includes/auth.php';

if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        if (empty($datetime)) return '';
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff/60).'m ago';
        if ($diff < 86400) return floor($diff/3600).'h ago';
        if ($diff < 604800) return floor($diff/86400).'d ago';
        return date('d M', strtotime($datetime));
    }
}

$db = Database::getInstance();

// Helper: adjust stock for an order (reduce on confirm, restore on cancel/return)
function _bulkStockAdjust($db, $orderId, $direction = 'reduce') {
    $items = $db->fetchAll("SELECT oi.product_id, oi.quantity, oi.variant_name FROM order_items oi WHERE oi.order_id = ?", [$orderId]);
    $sign = $direction === 'reduce' ? -1 : 1;
    foreach ($items as $oi) {
        if (!$oi['product_id']) continue;
        $prod = $db->fetch("SELECT manage_stock, combined_stock FROM products WHERE id = ?", [$oi['product_id']]);
        if (!$prod || !intval($prod['manage_stock'] ?? 1)) continue;
        $qty = intval($oi['quantity']);

        if (intval($prod['combined_stock'] ?? 0) && !empty($oi['variant_name'])) {
            // Combined kg stock: adjust by weight_per_unit
            $variant = $db->fetch("SELECT id, weight_per_unit FROM product_variants WHERE product_id = ? AND CONCAT(variant_name, ': ', variant_value) = ? LIMIT 1", [$oi['product_id'], $oi['variant_name']]);
            if ($variant && floatval($variant['weight_per_unit'] ?? 0) > 0) {
                $db->query("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?", [$sign * floatval($variant['weight_per_unit']) * $qty, $oi['product_id']]);
            }
        } else {
            // Normal stock: adjust by quantity
            $db->query("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?", [$sign * $qty, $oi['product_id']]);
            if (!empty($oi['variant_name'])) {
                try {
                    $variant = $db->fetch("SELECT id FROM product_variants WHERE product_id = ? AND CONCAT(variant_name, ': ', variant_value) = ? LIMIT 1", [$oi['product_id'], $oi['variant_name']]);
                    if ($variant) {
                        $db->query("UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ?", [$sign * $qty, $variant['id']]);
                    }
                } catch (\Throwable $e) {}
            }
        }
        $db->query("UPDATE products SET stock_status = CASE WHEN stock_quantity > 0 THEN 'in_stock' ELSE 'out_of_stock' END WHERE id = ? AND manage_stock = 1", [$oi['product_id']]);
    }
}

// ── POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'log_print') {
        // Log print to print_queue table
        $printType = sanitize($_POST['print_type'] ?? 'invoice');
        $orderIds = json_decode($_POST['order_ids'] ?? '[]', true) ?: [];
        $adminId = getAdminId();
        $now = date('Y-m-d H:i:s');
        $logged = 0;
        foreach ($orderIds as $oid) {
            $oid = intval($oid);
            if (!$oid) continue;
            try {
                $db->insert('print_queue', [
                    'order_id' => $oid,
                    'print_type' => in_array($printType, ['invoice','label','sticker']) ? $printType : 'invoice',
                    'is_printed' => 1,
                    'printed_at' => $now,
                    'printed_by' => $adminId,
                ]);
                $logged++;
            } catch (\Throwable $e) {}
        }
        if (!empty($_POST['_ajax'])) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'logged' => $logged]);
            exit;
        }
    }
    
    if ($action === 'update_status') {
        $orderId = intval($_POST['order_id']);
        $newStatus = sanitize($_POST['status']);
        $db->update('orders', ['order_status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$orderId]);
        try { $db->insert('order_status_history', ['order_id' => $orderId, 'status' => $newStatus, 'changed_by' => getAdminId(), 'note' => sanitize($_POST['notes'] ?? '')]); } catch (Exception $e) {}
        if ($newStatus === 'delivered') { try { awardOrderCredits($orderId); } catch (\Throwable $e) {} }
        if (in_array($newStatus, ['cancelled', 'returned'])) { try { refundOrderCreditsOnCancel($orderId); } catch (\Throwable $e) {} }
        if ($newStatus === 'delivered') {
            try { $db->update('orders', ['delivered_at' => date('Y-m-d H:i:s')], 'id = ? AND delivered_at IS NULL', [$orderId]); } catch (\Throwable $e) {}
            // Accounting: record income
            try {
                $__o = $db->fetch("SELECT order_number, total FROM orders WHERE id=?", [$orderId]);
                $__ex = $db->fetch("SELECT id FROM accounting_entries WHERE reference_type='order' AND reference_id=? AND entry_type='income'", [$orderId]);
                if (!$__ex && $__o) {
                    $db->insert('accounting_entries', ['entry_type'=>'income','amount'=>floatval($__o['total']),'reference_type'=>'order','reference_id'=>$orderId,'description'=>'Order #'.$__o['order_number'].' delivered','entry_date'=>date('Y-m-d')]);
                }
            } catch (\Throwable $e) {}
        }
        if (in_array($newStatus, ['cancelled', 'returned'])) {
            // Accounting: record refund if income existed
            try {
                $__o = $db->fetch("SELECT order_number, total FROM orders WHERE id=?", [$orderId]);
                $__inc = $db->fetch("SELECT id FROM accounting_entries WHERE reference_type='order' AND reference_id=? AND entry_type='income'", [$orderId]);
                if ($__inc && $__o) {
                    $__ref = $db->fetch("SELECT id FROM accounting_entries WHERE reference_type='order' AND reference_id=? AND entry_type='refund'", [$orderId]);
                    if (!$__ref) { $db->insert('accounting_entries', ['entry_type'=>'refund','amount'=>floatval($__o['total']),'reference_type'=>'order','reference_id'=>$orderId,'description'=>'Order #'.$__o['order_number'].' '.$newStatus.' (refund)','entry_date'=>date('Y-m-d')]); }
                }
            } catch (\Throwable $e) {}
        }
        try { $db->query("DELETE FROM order_locks WHERE order_id = ?", [$orderId]); } catch (\Throwable $e) {}
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['_ajax'])) {
            header('Content-Type: application/json'); echo json_encode(['success'=>true]); exit;
        }
        redirect(adminUrl('pages/order-management.php?' . http_build_query(array_diff_key($_GET, ['msg'=>''])) . '&msg=updated'));
    }
    
    if ($action === 'bulk_status') {
        // Clear ALL output buffering first for clean JSON
        while (ob_get_level()) ob_end_clean();
        
        $ids = $_POST['order_ids'] ?? [];
        $status = sanitize($_POST['bulk_status'] ?? '');
        
        if (empty($ids) || empty($status)) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'No orders or status selected']);
                exit;
            }
            redirect(adminUrl('pages/order-management.php?msg=error'));
        }
        
        $updated = 0;
        $errors = [];
        
        foreach ($ids as $oid) {
            $oid = intval($oid);
            try {
                $oldOrder = $db->fetch("SELECT order_status FROM orders WHERE id=?", [$oid]);
                if (!$oldOrder) {
                    $errors[] = "Order #{$oid} not found";
                    continue;
                }
                $oldStatus = $oldOrder['order_status'] ?? '';
                
                // Skip if already in target status
                if ($oldStatus === $status) {
                    $updated++;
                    continue;
                }
                
                $db->update('orders', ['order_status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$oid]);
                try { $db->insert('order_status_history', ['order_id' => $oid, 'status' => $status, 'changed_by' => getAdminId()]); } catch (Exception $e) {}
                
                if ($status === 'delivered') {
                    try { awardOrderCredits($oid); } catch (\Throwable $e) {}
                    try { $db->update('orders', ['delivered_at' => date('Y-m-d H:i:s')], 'id = ? AND delivered_at IS NULL', [$oid]); } catch (\Throwable $e) {}
                }
                if (in_array($status, ['cancelled', 'returned'])) {
                    try { refundOrderCreditsOnCancel($oid); } catch (\Throwable $e) {}
                }
                
                // Stock: reduce on confirm, restore on cancel/return
                if ($status === 'confirmed' && in_array($oldStatus, ['processing', 'pending'])) {
                    try { _bulkStockAdjust($db, $oid, 'reduce'); } catch (\Throwable $e) {}
                }
                if (in_array($status, ['cancelled', 'returned']) && in_array($oldStatus, ['confirmed', 'ready_to_ship', 'shipped', 'delivered'])) {
                    try { _bulkStockAdjust($db, $oid, 'restore'); } catch (\Throwable $e) {}
                }
                
                try { $db->query("DELETE FROM order_locks WHERE order_id = ?", [$oid]); } catch (\Throwable $e) {}
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = "Order #{$oid}: " . $e->getMessage();
            }
        }
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'count' => $updated,
                'total' => count($ids),
                'errors' => $errors,
            ]);
            exit;
        }
        redirect(adminUrl('pages/order-management.php?msg=bulk_updated'));
    }
    
    if ($action === 'bulk_courier') {
        @ob_clean(); header('Content-Type: application/json');
        $ids = $_POST['order_ids'] ?? []; $courierName = sanitize($_POST['courier_name'] ?? '');
        $results = ['success'=>0,'failed'=>0,'errors'=>[]];
        foreach ($ids as $oid) {
            $oid = intval($oid); $o = $db->fetch("SELECT * FROM orders WHERE id = ?", [$oid]);
            if (!$o) { $results['failed']++; continue; }
            if (strtolower($courierName) === 'pathao') {
                $pathaoFile = __DIR__ . '/../../api/pathao.php';
                if (!file_exists($pathaoFile)) { $results['failed']++; $results['errors'][] = "#{$o['order_number']}: Pathao API not configured"; continue; }
                try {
                    require_once $pathaoFile; $pathao = new PathaoAPI();
                    $resp = $pathao->createOrder(['store_id'=>$pathao->setting('store_id'),'merchant_order_id'=>$o['order_number'],'recipient_name'=>$o['customer_name'],'recipient_phone'=>$o['customer_phone'],'recipient_address'=>$o['customer_address'],'recipient_city'=>intval($o['pathao_city_id']??0),'recipient_zone'=>intval($o['pathao_zone_id']??0),'recipient_area'=>intval($o['pathao_area_id']??0),'delivery_type'=>48,'item_type'=>2,'item_quantity'=>1,'item_weight'=>0.5,'amount_to_collect'=>($o['payment_method']==='cod')?$o['total']:0,'item_description'=>'Order #'.$o['order_number'],'special_instruction'=>$o['notes']??'']);
                    if (!empty($resp['data']['consignment_id'])) {
                        $db->update('orders', ['courier_consignment_id'=>$resp['data']['consignment_id'],'courier_name'=>'Pathao','courier_tracking_id'=>$resp['data']['consignment_id'],'order_status'=>'shipped','updated_at'=>date('Y-m-d H:i:s')], 'id = ?', [$oid]);
                        try { $db->insert('courier_uploads', ['order_id'=>$oid,'courier_provider'=>'pathao','consignment_id'=>$resp['data']['consignment_id'],'status'=>'uploaded','response_data'=>json_encode($resp),'created_by'=>getAdminId()]); } catch(Exception $e){}
                        $results['success']++;
                    } else { $results['failed']++; $results['errors'][]="#{$o['order_number']}: ".($resp['message']??'Failed'); }
                } catch(\Throwable $e) { $results['failed']++; $results['errors'][]="#{$o['order_number']}: ".$e->getMessage(); }
            } elseif (strtolower($courierName) === 'steadfast') {
                $sfFile = __DIR__ . '/../../api/steadfast.php';
                if (file_exists($sfFile)) {
                    try {
                        require_once $sfFile; $sf = new SteadfastAPI();
                        $result = $sf->uploadOrder($oid);
                        if ($result['success']) {
                            $results['success']++;
                        } else { $results['failed']++; $results['errors'][]="#{$o['order_number']}: ".($result['message']??'Failed'); }
                    } catch(\Throwable $e) { $results['failed']++; $results['errors'][]="#{$o['order_number']}: ".$e->getMessage(); }
                } else { $results['failed']++; $results['errors'][]="#{$o['order_number']}: Steadfast API not configured"; }
            } elseif (strtolower($courierName) === 'carrybee') {
                // CarryBee - manual upload (no API yet) - just mark shipped with courier name
                $db->update('orders', ['courier_name'=>'CarryBee','order_status'=>'shipped','updated_at'=>date('Y-m-d H:i:s')], 'id = ?', [$oid]);
                $results['success']++;
            } else {
                $db->update('orders', ['courier_name'=>$courierName,'order_status'=>'shipped','updated_at'=>date('Y-m-d H:i:s')], 'id = ?', [$oid]);
                $results['success']++;
            }
        }
        echo json_encode($results); exit;
    }
    
    if ($action === 'add_tag') {
        header('Content-Type: application/json');
        $orderId = intval($_POST['order_id']); $tag = trim(sanitize($_POST['tag']));
        if ($tag && $orderId) { try { $db->query("INSERT IGNORE INTO order_tags (order_id, tag_name, created_by) VALUES (?, ?, ?)", [$orderId, $tag, getAdminId()]); } catch(Exception $e) {} }
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'remove_tag') {
        header('Content-Type: application/json');
        try { $db->delete('order_tags', 'order_id = ? AND tag_name = ?', [intval($_POST['order_id']), trim(sanitize($_POST['tag']))]); } catch(Exception $e) {}
        echo json_encode(['success'=>true]); exit;
    }
}

// ── Auto-migrate: convert any remaining 'pending' orders to 'processing' ──
try { $db->query("UPDATE orders SET order_status = 'processing' WHERE order_status = 'pending'"); } catch(\Throwable $e) {}
// Expand ENUM to include all courier-driven statuses
try { $db->query("ALTER TABLE orders MODIFY COLUMN order_status ENUM('pending','processing','confirmed','ready_to_ship','shipped','delivered','cancelled','returned','on_hold','no_response','good_but_no_response','advance_payment','incomplete','pending_return','pending_cancel','partial_delivered','lost') DEFAULT 'processing'"); } catch(\Throwable $e) {}
// Add courier_status column for raw courier API status
try { $db->query("ALTER TABLE orders ADD COLUMN courier_status VARCHAR(100) DEFAULT NULL AFTER courier_tracking_id"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN courier_tracking_message TEXT DEFAULT NULL AFTER courier_status"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN courier_delivery_charge DECIMAL(10,2) DEFAULT NULL AFTER courier_tracking_message"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN courier_cod_amount DECIMAL(10,2) DEFAULT NULL AFTER courier_delivery_charge"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN courier_uploaded_at DATETIME DEFAULT NULL AFTER courier_cod_amount"); } catch(\Throwable $e) {}
try { $db->query("CREATE TABLE IF NOT EXISTS courier_webhook_log (id INT AUTO_INCREMENT PRIMARY KEY, courier VARCHAR(50), payload TEXT, result VARCHAR(255), ip_address VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(courier), INDEX(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN pathao_consignment_id VARCHAR(100) DEFAULT NULL AFTER courier_consignment_id"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD INDEX idx_pathao_cid (pathao_consignment_id)"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN is_preorder TINYINT(1) DEFAULT 0 AFTER is_fake"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN order_note TEXT DEFAULT NULL AFTER notes"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN preorder_date DATE DEFAULT NULL AFTER is_preorder"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN panel_notes TEXT DEFAULT NULL AFTER admin_notes"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN advance_amount DECIMAL(12,2) DEFAULT 0 AFTER discount_amount"); } catch(\Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN advance_amount DECIMAL(12,2) DEFAULT 0 AFTER discount_amount"); } catch(\Throwable $e) {}

// ── Order Flow Definition ──
// Main flow: Processing → Confirmed → Ready to Ship → Shipped → Delivered
// Courier-driven (auto-updated by webhooks): pending_return, pending_cancel, partial_delivered, lost
// Manual side statuses: cancelled, returned, on_hold, no_response, good_but_no_response, advance_payment
$mainFlow = ['processing', 'confirmed', 'ready_to_ship', 'shipped', 'delivered'];
$courierStatuses = ['pending_return', 'pending_cancel', 'partial_delivered', 'lost'];
$sideStatuses = ['cancelled', 'returned', 'on_hold', 'no_response', 'good_but_no_response', 'advance_payment'];
$allStatuses = array_merge($mainFlow, $courierStatuses, $sideStatuses);

// Next logical action for each status
$nextAction = [
    'processing' => ['status' => 'confirmed', 'label' => 'Confirm', 'icon' => '✅', 'color' => 'blue'],
    'confirmed'  => ['status' => 'ready_to_ship', 'label' => 'Ready to Ship', 'icon' => '📦', 'color' => 'violet'],
    'ready_to_ship' => ['status' => 'shipped', 'label' => 'Ship', 'icon' => '🚚', 'color' => 'purple'],
    'shipped'    => ['status' => 'delivered',  'label' => 'Deliver', 'icon' => '📦', 'color' => 'green'],
    'pending_return' => ['status' => 'returned', 'label' => 'Confirm Return', 'icon' => '↩', 'color' => 'orange'],
    'pending_cancel' => ['status' => 'cancelled', 'label' => 'Confirm Cancel', 'icon' => '✗', 'color' => 'red'],
    'partial_delivered' => ['status' => 'delivered', 'label' => 'Mark Delivered', 'icon' => '📦', 'color' => 'green'],
];

// Status counts
$statusCounts = [];
foreach ($allStatuses as $s) { $statusCounts[$s] = 0; }
try {
    $scRows = $db->fetchAll("SELECT order_status, COUNT(*) as cnt FROM orders GROUP BY order_status");
    foreach ($scRows as $scr) {
        $st = $scr['order_status'];
        if (isset($statusCounts[$st])) $statusCounts[$st] = intval($scr['cnt']);
        // Map legacy pending into processing
        if ($st === 'pending') $statusCounts['processing'] = ($statusCounts['processing'] ?? 0) + intval($scr['cnt']);
    }
} catch (\Throwable $e) {}
$totalOrders = array_sum($statusCounts);

// ── Filters ──
$status=$_GET['status']??''; $search=$_GET['search']??''; $dateFrom=$_GET['date_from']??''; $dateTo=$_GET['date_to']??'';
$channel=$_GET['channel']??''; $courier=$_GET['courier']??''; $assignedTo=$_GET['assigned']??''; $preorderFilter=$_GET['preorder']??'';
$tagFilter=$_GET['tag']??''; $customerFilter=$_GET['customer']??'';
$page = max(1, intval($_GET['page'] ?? 1));
$allowedLimits = [200,1000,0];
$limit = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowedLimits) ? (int)$_GET['per_page'] : 200;
if ($limit === 0) $limit = 999999; // "All"

$where = '1=1'; $params = [];
if ($status) {
    if ($status === 'processing') {
        $where .= " AND o.order_status IN ('processing','pending')";
    } else {
        $where .= " AND o.order_status = ?"; $params[] = $status;
    }
}
if ($search) { $where .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR CAST(o.id AS CHAR) = ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%",$search]); }
if ($customerFilter) { $where .= " AND (o.customer_name LIKE ? OR o.customer_phone LIKE ?)"; $params[] = "%$customerFilter%"; $params[] = "%$customerFilter%"; }
if ($dateFrom) { $where .= " AND DATE(o.created_at) >= ?"; $params[] = $dateFrom; }
if ($dateTo) { $where .= " AND DATE(o.created_at) <= ?"; $params[] = $dateTo; }
if ($channel) { $where .= " AND o.channel = ?"; $params[] = $channel; }
if ($courier) {
    $_oce2 = "COALESCE(NULLIF(o.courier_name,''), o.shipping_method)";
    if ($courier === 'Unassigned') {
        $where .= " AND ({$_oce2} IS NULL OR {$_oce2} = '' OR {$_oce2} = 'Unassigned')";
    } else {
        $where .= " AND {$_oce2} LIKE ?"; $params[] = '%' . $courier . '%';
    }
}
if ($assignedTo) { $where .= " AND o.assigned_to = ?"; $params[] = intval($assignedTo); }
if ($preorderFilter === '1') { $where .= " AND o.is_preorder = 1"; }
elseif ($preorderFilter === '0') { $where .= " AND (o.is_preorder = 0 OR o.is_preorder IS NULL)"; }
if ($tagFilter) { $where .= " AND EXISTS (SELECT 1 FROM order_tags ot WHERE ot.order_id=o.id AND ot.tag_name=?)"; $params[] = $tagFilter; }

$total = $db->fetch("SELECT COUNT(*) as cnt FROM orders o WHERE {$where}", $params)['cnt'];
$offset = ($page-1)*$limit; $totalPages = ceil($total/$limit);

// Column sorting
$sortCol = $_GET['sort'] ?? 'created_at';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$allowedSorts = ['created_at'=>'o.created_at','order_number'=>'o.order_number','total'=>'o.total','customer_name'=>'o.customer_name','channel'=>'o.channel','updated_at'=>'o.updated_at'];
$orderBy = ($allowedSorts[$sortCol] ?? 'o.created_at') . ' ' . $sortDir;

$orders = $db->fetchAll("SELECT o.*, au.full_name as assigned_name, (SELECT COUNT(*) FROM print_queue pq WHERE pq.order_id = o.id) as print_count, (SELECT au2.full_name FROM order_status_history osh2 LEFT JOIN admin_users au2 ON au2.id = osh2.changed_by WHERE osh2.order_id = o.id AND osh2.changed_by IS NOT NULL ORDER BY osh2.created_at DESC LIMIT 1) as last_action_by FROM orders o LEFT JOIN admin_users au ON au.id = o.assigned_to WHERE {$where} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}", $params);

// Pre-fetch customer success rates
$successRates=[]; $previousOrders=[];
$phones = array_unique(array_filter(array_column($orders, 'customer_phone')));

// Ensure cache table exists
try { $db->query("CREATE TABLE IF NOT EXISTS `customer_courier_stats` (`id` int(11) NOT NULL AUTO_INCREMENT,`phone_hash` varchar(32) NOT NULL,`phone_display` varchar(20) NOT NULL,`total_orders` int(11) NOT NULL DEFAULT 0,`delivered` int(11) NOT NULL DEFAULT 0,`cancelled` int(11) NOT NULL DEFAULT 0,`returned` int(11) NOT NULL DEFAULT 0,`success_rate` decimal(5,2) NOT NULL DEFAULT 0.00,`total_spent` decimal(12,2) NOT NULL DEFAULT 0.00,`courier_breakdown` text DEFAULT NULL,`fetched_at` datetime NOT NULL,`created_at` timestamp DEFAULT CURRENT_TIMESTAMP,`updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `idx_phone_hash` (`phone_hash`),KEY `idx_fetched` (`fetched_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"); } catch (\Throwable $e) {}

// Batch fetch from cache
$phoneHashes = [];
foreach ($phones as $phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    $phoneHashes[$phone] = md5(substr($clean, -11));
}
$cachedStats = [];
if (!empty($phoneHashes)) {
    $hashList = array_values($phoneHashes);
    $ph2 = implode(',', array_fill(0, count($hashList), '?'));
    try {
        $rows = $db->fetchAll("SELECT * FROM customer_courier_stats WHERE phone_hash IN ({$ph2}) AND fetched_at > DATE_SUB(NOW(), INTERVAL 12 HOUR)", $hashList);
        foreach ($rows as $r) { $cachedStats[$r['phone_hash']] = $r; }
    } catch (\Throwable $e) {}
}

foreach ($phones as $phone) {
    $hash = $phoneHashes[$phone];
    if (isset($cachedStats[$hash])) {
        $c = $cachedStats[$hash];
        $successRates[$phone] = [
            'total' => intval($c['total_orders']), 'delivered' => intval($c['delivered']),
            'cancelled' => intval($c['cancelled']), 'returned' => intval($c['returned']),
            'rate' => floatval($c['success_rate']), 'total_spent' => floatval($c['total_spent']),
            'courier_breakdown' => json_decode($c['courier_breakdown'] ?? '[]', true) ?: [],
        ];
    } else {
        // Cache miss — compute from orders table and cache
        $pl = '%' . substr(preg_replace('/[^0-9]/', '', $phone), -11);
        try {
            $sr = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN order_status='returned' THEN 1 ELSE 0 END) as returned, SUM(total) as total_spent FROM orders WHERE REPLACE(REPLACE(customer_phone,' ',''),'-','') LIKE ?", [$pl]);
            $t=intval($sr['total']); $d=intval($sr['delivered']); $c2=intval($sr['cancelled']); $ret=intval($sr['returned']??0);
            $rate=$t>0?round(($d/$t)*100,2):0;
            $courierBreak = [];
            try {
                $cbRows = $db->fetchAll("SELECT COALESCE(NULLIF(courier_name,''),NULLIF(shipping_method,''),'Unknown') as cn, COUNT(*) as total, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered FROM orders WHERE REPLACE(REPLACE(customer_phone,' ',''),'-','') LIKE ? AND (courier_name IS NOT NULL AND courier_name != '' OR shipping_method IS NOT NULL AND shipping_method != '') GROUP BY cn", [$pl]);
                foreach ($cbRows as $cb) { $courierBreak[] = ['name'=>$cb['cn'],'delivered'=>intval($cb['delivered']),'total'=>intval($cb['total'])]; }
            } catch (\Throwable $e) {}
            $successRates[$phone] = ['total'=>$t,'delivered'=>$d,'cancelled'=>$c2,'returned'=>$ret,'rate'=>$rate,'total_spent'=>floatval($sr['total_spent']??0),'courier_breakdown'=>$courierBreak];
            // Write to cache
            try {
                $now = date('Y-m-d H:i:s');
                $db->query("INSERT INTO customer_courier_stats (phone_hash,phone_display,total_orders,delivered,cancelled,returned,success_rate,total_spent,courier_breakdown,fetched_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE phone_display=VALUES(phone_display),total_orders=VALUES(total_orders),delivered=VALUES(delivered),cancelled=VALUES(cancelled),returned=VALUES(returned),success_rate=VALUES(success_rate),total_spent=VALUES(total_spent),courier_breakdown=VALUES(courier_breakdown),fetched_at=VALUES(fetched_at)", [$hash,$phone,$t,$d,$c2,$ret,$rate,floatval($sr['total_spent']??0),json_encode($courierBreak),$now]);
            } catch (\Throwable $e) {}
        } catch (Exception $e) { $successRates[$phone]=['total'=>0,'delivered'=>0,'cancelled'=>0,'returned'=>0,'rate'=>0,'total_spent'=>0,'courier_breakdown'=>[]]; }
    }
    // Previous orders (always live — lightweight query)
    try {
        $pl2 = '%' . substr(preg_replace('/[^0-9]/', '', $phone), -11);
        $previousOrders[$phone] = $db->fetchAll("SELECT id, order_number, order_status, total, created_at FROM orders WHERE REPLACE(REPLACE(customer_phone,' ',''),'-','') LIKE ? ORDER BY created_at DESC LIMIT 5", [$pl2]);
    } catch (\Throwable $e) { $previousOrders[$phone] = []; }
}

// Pre-fetch items + tags
$orderIds = array_column($orders, 'id'); $orderItems=[]; $orderTags=[];
if (!empty($orderIds)) {
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $items = $db->fetchAll("SELECT oi.order_id, oi.product_name, oi.quantity, oi.price, oi.variant_name, p.featured_image, p.sku FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id IN ({$ph})", $orderIds);
    foreach ($items as $item) { $orderItems[$item['order_id']][] = $item; }
    try { $tags = $db->fetchAll("SELECT order_id, tag_name FROM order_tags WHERE order_id IN ({$ph})", $orderIds);
        foreach ($tags as $t) { $orderTags[$t['order_id']][] = $t; }
    } catch(Exception $e) {}
}

// ── Courier counts (for sub-tabs under each status) ──
// ── Courier counts — normalize names, use courier_name OR shipping_method ──
$_courierExpr = "COALESCE(NULLIF(courier_name,''), shipping_method)";
$_dbCouriers = [];
try {
    $_dbCouriers = $db->fetchAll("SELECT DISTINCT {$_courierExpr} as cname FROM orders WHERE {$_courierExpr} IS NOT NULL AND {$_courierExpr} != '' AND {$_courierExpr} != 'Unassigned' ORDER BY cname");
} catch (\Throwable $e) {}

// Build normalized list (merge "Pathao" + "Pathao Courier" → "Pathao")
$courierList = [];
$_courierRawMap = []; // normalized → [raw1, raw2, ...]
foreach ($_dbCouriers as $_dc) {
    $raw = trim($_dc['cname'] ?? '');
    if (!$raw) continue;
    $norm = normalizeCourierName($raw);
    if (!isset($_courierRawMap[$norm])) $_courierRawMap[$norm] = [];
    if (!in_array($raw, $_courierRawMap[$norm])) $_courierRawMap[$norm][] = $raw;
    if (!in_array($norm, $courierList)) $courierList[] = $norm;
}
$courierList[] = 'Unassigned';

$courierCounts = [];
$_cwStatusWhere = '1=1';
if ($status === 'processing') { $_cwStatusWhere = "o.order_status IN ('processing','pending')"; }
elseif ($status) { $_cwStatusWhere = "o.order_status = '" . preg_replace('/[^a-z_]/','',$status) . "'"; }

$_oce = "COALESCE(NULLIF(o.courier_name,''), o.shipping_method)";
foreach ($courierList as $cn) {
    if ($cn === 'Unassigned') {
        try { $courierCounts[$cn] = (int)($db->fetch("SELECT COUNT(*) as cnt FROM orders o WHERE {$_cwStatusWhere} AND ({$_oce} IS NULL OR {$_oce} = '' OR {$_oce} = 'Unassigned')")['cnt'] ?? 0); } catch(\Throwable $e) { $courierCounts[$cn] = 0; }
    } else {
        // Match all raw variants: "Pathao", "Pathao Courier", etc.
        $variants = $_courierRawMap[$cn] ?? [$cn];
        $likeConditions = array_map(function($v){ return "{$GLOBALS['_oce']} LIKE ?"; }, $variants);
        // Simpler: just use the base name with LIKE
        try { $courierCounts[$cn] = (int)($db->fetch("SELECT COUNT(*) as cnt FROM orders o WHERE {$_cwStatusWhere} AND {$_oce} LIKE ?", ['%'.$cn.'%'])['cnt'] ?? 0); } catch(\Throwable $e) { $courierCounts[$cn] = 0; }
    }
}

$defaultCourier = getSetting('default_courier', 'pathao');
$adminUsers = $db->fetchAll("SELECT id, full_name FROM admin_users WHERE is_active = 1 ORDER BY full_name");
$incompleteCount = 0;
try { $incompleteCount = $db->fetch("SELECT COUNT(*) as cnt FROM incomplete_orders WHERE recovered = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")['cnt']; } catch(Exception $e){}

// Today's summary
$todaySummary = $db->fetch("SELECT COUNT(*) as total, COALESCE(SUM(total),0) as revenue FROM orders WHERE DATE(created_at) = CURDATE()");
$totalRevenue = $db->fetch("SELECT COALESCE(SUM(total),0) as revenue FROM orders WHERE order_status = 'delivered'");

// Tab config
$tabConfig = [
    'processing' => ['icon'=>'⚙','color'=>'yellow','label'=>'PROCESSING'],
    'confirmed'  => ['icon'=>'✅','color'=>'blue','label'=>'CONFIRMED'],
    'ready_to_ship'=>['icon'=>'📦','color'=>'violet','label'=>'READY TO SHIP'],
    'shipped'    => ['icon'=>'🚚','color'=>'purple','label'=>'SHIPPED'],
    'delivered'  => ['icon'=>'📦','color'=>'green','label'=>'DELIVERED'],
    'pending_return'=>['icon'=>'🔄','color'=>'amber','label'=>'PENDING RETURN'],
    'pending_cancel'=>['icon'=>'⏳','color'=>'pink','label'=>'PENDING CANCEL'],
    'partial_delivered'=>['icon'=>'📦½','color'=>'cyan','label'=>'PARTIAL'],
    'lost'       => ['icon'=>'❌','color'=>'stone','label'=>'LOST'],
    'cancelled'  => ['icon'=>'✗','color'=>'red','label'=>'CANCELLED'],
    'returned'   => ['icon'=>'↩','color'=>'orange','label'=>'RETURNED'],
    'on_hold'    => ['icon'=>'⏸','color'=>'gray','label'=>'ON HOLD'],
    'no_response'=> ['icon'=>'📵','color'=>'rose','label'=>'NO RESPONSE'],
    'good_but_no_response'=>['icon'=>'📱','color'=>'teal','label'=>'GOOD NO RESP'],
    'advance_payment'=>['icon'=>'💰','color'=>'emerald','label'=>'ADVANCE'],
];


// ── AJAX Fragment Mode ─────────────────────────────────────────────────────
$_isFrag = !empty($_GET['_frag']) && $_SERVER['REQUEST_METHOD'] === 'GET';
if ($_isFrag) {
    // Render table rows
    ob_start();
    $__tplFile = __DIR__ . '/order-row-tpl.php';
    foreach ($orders as $order) {
        $sr        = $successRates[$order['customer_phone']] ?? ['total'=>0,'delivered'=>0,'rate'=>0,'cancelled'=>0,'returned'=>0,'total_spent'=>0,'courier_breakdown'=>[]];
        $prevO     = $previousOrders[$order['customer_phone']] ?? [];
        $oItems    = $orderItems[$order['id']] ?? [];
        $tags      = $orderTags[$order['id']] ?? [];
        $rC        = $sr['rate']>=80?'text-green-600':($sr['rate']>=50?'text-yellow-600':'text-red-500');
        $ph        = preg_replace('/[^0-9]/','',$order['customer_phone']);
        $oStatus   = $order['order_status'] === 'pending' ? 'processing' : $order['order_status'];
        $nxt       = $nextAction[$oStatus] ?? null;
        $creditUsed= floatval($order['store_credit_used'] ?? 0);
        $statusColors=['processing'=>'bg-yellow-100 text-yellow-700','confirmed'=>'bg-blue-100 text-blue-700','ready_to_ship'=>'bg-violet-100 text-violet-700','shipped'=>'bg-purple-100 text-purple-700','delivered'=>'bg-green-100 text-green-700','cancelled'=>'bg-red-100 text-red-700','returned'=>'bg-orange-100 text-orange-700','pending_return'=>'bg-amber-100 text-amber-700','pending_cancel'=>'bg-pink-100 text-pink-700','partial_delivered'=>'bg-cyan-100 text-cyan-700','on_hold'=>'bg-gray-100 text-gray-700','no_response'=>'bg-rose-100 text-rose-700','good_but_no_response'=>'bg-teal-100 text-teal-700','advance_payment'=>'bg-emerald-100 text-emerald-700'];
        $statusDots=['processing'=>'#eab308','confirmed'=>'#3b82f6','ready_to_ship'=>'#8b5cf6','shipped'=>'#9333ea','delivered'=>'#22c55e','cancelled'=>'#ef4444','returned'=>'#f97316','pending_return'=>'#f59e0b','on_hold'=>'#6b7280'];
        $sDot   = $statusDots[$oStatus] ?? '#94a3b8';
        $sBadge = $statusColors[$oStatus] ?? 'bg-gray-100 text-gray-600';
        $__cn   = strtolower($order['courier_name'] ?? ($order['shipping_method'] ?? ''));
        $__cid  = $order['courier_consignment_id'] ?? ($order['pathao_consignment_id'] ?? '');
        $__tid  = $order['courier_tracking_id'] ?? $__cid;
        $__link = '';
        if ($__cid) {
            if (strpos($__cn,'steadfast')!==false) $__link='https://steadfast.com.bd/user/consignment/'.urlencode($__cid);
            elseif (strpos($__cn,'pathao')!==false) $__link='https://merchant.pathao.com/courier/orders/'.urlencode($__cid);
            elseif (strpos($__cn,'redx')!==false)   $__link='https://redx.com.bd/track-parcel/?trackingId='.urlencode($__tid);
        }
        $channelMap=['website'=>'WEB','facebook'=>'FACEBOOK','phone'=>'PHONE','whatsapp'=>'WHATSAPP','instagram'=>'INSTAGRAM','landing_page'=>'LP'];
        $srcLabel=$channelMap[$order['channel']??'']??strtoupper($order['channel']??'—');
        include $__tplFile;
    }
    if (empty($orders)) {
        echo '<tr><td colspan="14" style="text-align:center;padding:40px 20px;color:#94a3b8"><div style="font-size:28px;margin-bottom:8px">📦</div>No orders found</td></tr>';
    }
    $__rowsHtml = ob_get_clean();

    // Render pagination
    ob_start();
    if ($totalPages > 1) {
        echo '<div class="flex items-center justify-between mt-3 px-1">';
        echo '<p class="text-xs text-gray-500">Page <strong>'.$page.'</strong> of '.$totalPages.' · '.number_format($total).' orders</p>';
        echo '<div class="flex gap-1">';
        if ($page > 1) echo '<button class="om-page-btn px-2.5 py-1 text-xs rounded bg-white border hover:bg-gray-50" onclick="OM.go({page:'.($page-1).'})">←</button>';
        for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++) {
            $cls=$i===$page?'bg-blue-600 text-white border-blue-600':'bg-white border hover:bg-gray-50';
            echo '<button class="om-page-btn px-2.5 py-1 text-xs rounded '.$cls.'" onclick="OM.go({page:'.$i.'})">'.$i.'</button>';
        }
        if ($page < $totalPages) echo '<button class="om-page-btn px-2.5 py-1 text-xs rounded bg-white border hover:bg-gray-50" onclick="OM.go({page:'.($page+1).'})">→</button>';
        echo '</div></div>';
    }
    $__paginHtml = ob_get_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'rows'         => $__rowsHtml,
        'pagination'   => $__paginHtml,
        'total'        => (int)$total,
        'page'         => (int)$page,
        'totalPages'   => (int)$totalPages,
        'statusCounts' => $statusCounts,
        'courierCounts'=> $courierCounts,
    ]);
    exit;
}

require_once __DIR__ . '/../includes/header.php';

// Sort link helper
function sortUrl($col) {
    global $sortCol, $sortDir;
    $p = $_GET;
    $p['sort'] = $col;
    $p['dir'] = ($sortCol === $col && $sortDir === 'ASC') ? 'desc' : 'asc';
    return '?' . http_build_query($p);
}
function sortIcon($col) {
    global $sortCol, $sortDir;
    if ($sortCol !== $col) return '<span class="text-gray-300 ml-0.5">↕</span>';
    return '<span class="text-blue-500 ml-0.5">' . ($sortDir === 'ASC' ? '↑' : '↓') . '</span>';
}
?>
<style>
.om-table th,.om-table td{padding:7px 5px;vertical-align:top;border-bottom:1px solid #f1f5f9;font-size:12px}
.om-table th{background:linear-gradient(to bottom,#f8fafc,#f1f5f9);color:#475569;font-weight:600;text-transform:uppercase;letter-spacing:.4px;font-size:10px;position:sticky;top:0;z-index:2;border-bottom:2px solid #e2e8f0;user-select:none;white-space:nowrap;padding:10px 5px}
.om-table th a{color:inherit;text-decoration:none}
.om-table tbody tr{transition:background .15s}
.om-table tbody tr:hover{background:#f0f7ff}
.om-table .cust-name{font-weight:600;color:#1e293b;font-size:12px}
.om-table .cust-phone{font-size:11px;color:#64748b;font-family:'SF Mono',SFMono-Regular,Menlo,monospace}
.om-table .cust-addr{font-size:10px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px}
.om-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.om-table{width:100%;border-collapse:collapse}
.om-table td{word-break:break-word}
.om-col-hide{display:none!important}
.om-view-drop{position:absolute;right:0;top:calc(100% + 4px);width:200px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.12);z-index:50;padding:8px 0;display:none}
.om-view-drop.show{display:block}
.om-view-drop label{display:flex;align-items:center;gap:8px;padding:5px 14px;font-size:12px;color:#374151;cursor:pointer;transition:background .1s}
.om-view-drop label:hover{background:#f3f4f6}
.om-view-drop input[type=checkbox]{width:15px;height:15px;accent-color:#3b82f6;cursor:pointer}
.rate-popup{display:none;position:absolute;z-index:50;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.15);padding:16px;width:280px;left:0;top:calc(100% + 6px)}
.rate-popup::before{content:'';position:absolute;top:-6px;left:20px;width:12px;height:12px;background:#fff;border-left:1px solid #e2e8f0;border-top:1px solid #e2e8f0;transform:rotate(45deg)}
.rate-wrap{position:relative;display:inline-block;cursor:pointer}
.rate-wrap:hover .rate-popup,.rate-wrap.pinned .rate-popup{display:block}
.rate-badge{display:inline-block;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px}
.tag-badge{display:inline-block;font-size:10px;font-weight:600;padding:2px 8px;border-radius:12px;white-space:nowrap}
.dot-menu{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border-radius:5px;cursor:pointer;color:#94a3b8;font-size:14px;line-height:1;transition:all .15s}
.dot-menu:hover{background:#e2e8f0;color:#334155}
.prod-thumb{width:30px;height:30px;border-radius:6px;object-fit:cover;border:1px solid #e5e7eb;flex-shrink:0}
.status-dot{width:7px;height:7px;border-radius:50%;display:inline-block;margin-right:3px}
/* Action Button */
.om-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:6px 16px;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;border:1px solid transparent;text-decoration:none;line-height:1.4;white-space:nowrap}
.om-btn:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,.1)}
.om-btn-open{background:#f0fdf4;color:#15803d;border-color:#bbf7d0}.om-btn-open:hover{background:#dcfce7;border-color:#86efac;color:#166534}
.om-action-cell{text-align:center;vertical-align:middle!important;padding-top:0!important;padding-bottom:0!important}
</style>

<?php if (isset($_GET['msg'])): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm">✓ <?= $_GET['msg'] === 'updated' ? 'Status updated.' : ($_GET['msg'] === 'bulk_updated' ? 'Bulk update completed.' : 'Action completed.') ?></div><?php endif; ?>

<?php if (empty($_isProcessingView)): ?>
<?php endif; /* _isProcessingView stats */ ?>

<?php if (empty($_isProcessingView)): ?>
<!-- Status Tabs -->
<div class="bg-white rounded-lg border mb-3 overflow-hidden">
    <div class="overflow-x-auto">
        <div class="flex items-center min-w-max border-b">
            <a href="<?= adminUrl('pages/order-management.php') ?>"
               data-status-tab=""
               onclick="event.preventDefault();OM.go({status:'',courier:'',page:1})"
               class="px-4 py-2.5 text-xs font-medium border-b-2 transition om-status-tab <?= !$status ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
                ALL <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] tab-count-all <?= !$status ? 'bg-blue-100 text-blue-700 font-bold' : 'bg-gray-100 text-gray-500' ?>"><?= number_format($totalOrders) ?></span>
            </a>
            <?php 
            $allTabStatuses = ['processing', 'confirmed', 'ready_to_ship', 'shipped', 'delivered', 'pending_return', 'returned', 'partial_delivered', 'cancelled', 'pending_cancel', 'on_hold', 'no_response', 'good_but_no_response', 'advance_payment', 'lost'];
            foreach ($allTabStatuses as $s):
                $tc = $tabConfig[$s] ?? ['icon'=>'','color'=>'gray','label'=>ucwords(str_replace('_',' ',$s))];
                $cnt = $statusCounts[$s] ?? 0;
                if ($cnt === 0 && !in_array($s, ['processing','confirmed','ready_to_ship','shipped','delivered','cancelled','returned'])) continue;
                $isActive = $status === $s;
            ?>
            <a href="?status=<?= $s ?>"
               data-status-tab="<?= $s ?>"
               onclick="event.preventDefault();OM.go({status:'<?= $s ?>',page:1})"
               class="px-3 py-2.5 text-xs font-medium whitespace-nowrap border-b-2 transition om-status-tab <?= $isActive ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
                <?= $tc['label'] ?> <span class="px-1.5 py-0.5 rounded text-[10px] tab-count <?= $isActive ? 'bg-blue-100 text-blue-700 font-bold' : 'bg-gray-100 text-gray-500' ?>"><?= number_format($cnt) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Courier Sub-Tabs (from confirmed onwards, always rendered for AJAX) -->
<?php
$_courierVisibleStatuses = ['confirmed','ready_to_ship','shipped','delivered','pending_return','returned','partial_delivered','cancelled','pending_cancel','on_hold','lost'];
$_courierBarHidden = !$status || !in_array($status, $_courierVisibleStatuses);
?>
<div class="bg-white rounded-lg border mb-3 overflow-hidden om-courier-bar <?= $_courierBarHidden ? 'hidden' : '' ?>">
    <div class="overflow-x-auto">
        <div class="flex items-center min-w-max gap-1 px-3 py-2">
            <?php
            $courierIcons = [
                'Pathao'           => ['icon' => 'fas fa-motorcycle', 'color' => 'teal',   'bg' => 'bg-teal-50',   'border' => 'border-teal-500',   'text' => 'text-teal-700'],
                'Pathao Courier'   => ['icon' => 'fas fa-motorcycle', 'color' => 'teal',   'bg' => 'bg-teal-50',   'border' => 'border-teal-500',   'text' => 'text-teal-700'],
                'Steadfast'        => ['icon' => 'fas fa-truck',       'color' => 'blue',   'bg' => 'bg-blue-50',   'border' => 'border-blue-500',   'text' => 'text-blue-700'],
                'Steadfast Courier'=> ['icon' => 'fas fa-truck',       'color' => 'blue',   'bg' => 'bg-blue-50',   'border' => 'border-blue-500',   'text' => 'text-blue-700'],
                'RedX'             => ['icon' => 'fas fa-bolt',        'color' => 'red',    'bg' => 'bg-red-50',    'border' => 'border-red-500',    'text' => 'text-red-700'],
                'CarryBee'         => ['icon' => 'fas fa-box',         'color' => 'amber',  'bg' => 'bg-amber-50',  'border' => 'border-amber-500',  'text' => 'text-amber-700'],
                'Personal Delivery'=> ['icon' => 'fas fa-walking',     'color' => 'violet', 'bg' => 'bg-violet-50', 'border' => 'border-violet-500', 'text' => 'text-violet-700'],
                'Unassigned'       => ['icon' => 'fas fa-question-circle','color'=>'gray',  'bg' => 'bg-gray-100',  'border' => 'border-gray-400',   'text' => 'text-gray-600'],
            ];
            $defaultIcon = ['icon' => 'fas fa-shipping-fast', 'color' => 'slate', 'bg' => 'bg-slate-50', 'border' => 'border-slate-400', 'text' => 'text-slate-700'];
            foreach ($courierList as $cn):
                $ci = $courierIcons[$cn] ?? $defaultIcon;
                $cnt = $courierCounts[$cn] ?? 0;
                $courierParam = $cn === 'Personal Delivery' ? 'Personal Delivery' : $cn;
                $isActiveCourier = ($courierParam === 'Unassigned')
                    ? in_array($courier, ['Personal Delivery', ''])  && $courier === 'Personal Delivery'
                    : $courier === $courierParam;
                $baseParams = array_filter(['status' => $status, 'search' => $search]);
                $activeParams = array_merge($baseParams, ['courier' => $courierParam]);
                $clearParams  = $baseParams;
            ?>
            <button type="button"
               data-courier-tab="<?= htmlspecialchars($courierParam) ?>"
               onclick="OM.go({courier:'<?= addslashes($isActiveCourier ? '' : $courierParam) ?>',page:1})"
               class="flex items-center gap-2 px-4 py-2 rounded-full border-2 text-sm font-semibold transition-all <?= $isActiveCourier ? $ci['bg'].' '.$ci['border'].' '.$ci['text'] : 'border-gray-200 text-gray-500 hover:border-gray-300 hover:bg-gray-50' ?>">
                <i class="<?= $ci['icon'] ?> text-xs"></i>
                <?= $cn ?>
                <span class="courier-count <?= $isActiveCourier ? 'bg-white/70' : 'bg-gray-100' ?> text-xs font-bold px-2 py-0.5 rounded-full"><?= number_format($cnt) ?></span>
            </button>
            <?php endforeach; ?>
            <?php if ($courier): ?>
            <button type="button" onclick="OM.go({courier:'',page:1})" class="ml-2 text-xs text-gray-400 hover:text-red-500 flex items-center gap-1 px-2 py-1 rounded border border-dashed border-gray-300 hover:border-red-300">
                <i class="fas fa-times text-[10px]"></i> Clear
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; /* _isProcessingView status tabs */ ?>

<!-- Search & Toolbar -->
<div class="bg-white rounded-lg border p-2.5 mb-3 flex flex-wrap items-center gap-2">
    <form method="GET" class="flex flex-wrap items-center gap-2 flex-1" onsubmit="event.preventDefault();OM.go({search:this.querySelector('[name=search]').value,page:1})">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <div class="relative flex-1 min-w-[180px]">
            <svg class="absolute left-2.5 top-2 w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search order, name, phone..." class="w-full pl-8 pr-3 py-1.5 border rounded text-xs focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
        </div>
        <button type="button" onclick="document.getElementById('advFilters').classList.toggle('hidden')" class="border text-gray-500 px-2.5 py-1.5 rounded text-xs hover:bg-gray-50">Filters</button>
    </form>
    <a href="<?= adminUrl('pages/order-processing.php') ?>" class="bg-indigo-600 text-white px-3 py-1.5 rounded text-xs font-medium hover:bg-indigo-700 flex items-center gap-1.5" title="Card-based processing view">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
      Processing
    </a>
    <?php if (!empty($_isProcessingView)): ?>
    <div id="activeUsersChip" class="hidden flex items-center gap-1.5 px-2.5 py-1.5 bg-green-50 border border-green-200 rounded-lg text-[11px] text-green-700 font-medium flex-shrink-0">
      <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block animate-pulse"></span>
      <span id="activeUsersText">1 active</span>
    </div>
    <button type="button" id="startProcBtn" onclick="startProcessingSession()"
        class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-1.5 rounded text-xs font-bold flex items-center gap-1.5 shadow-sm transition">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      Start Processing
    </button>
    <?php endif; ?>
    <a href="<?= adminUrl('pages/order-add.php') ?>" class="bg-blue-600 text-white px-3 py-1.5 rounded text-xs font-medium hover:bg-blue-700">+ New Order</a>
    <select class="border rounded text-xs px-2 py-1.5 text-gray-600" onchange="OM.go({per_page:this.value,page:1})" title="Orders per page">
      <option value="200" <?=$limit==200?'selected':''?>>200/page</option>
      <option value="1000" <?=$limit==1000?'selected':''?>>1000/page</option>
      <option value="0" <?=$limit>=999999?'selected':''?>>All</option>
    </select>
    <button type="button" onclick="recheckCourier()" class="border border-emerald-200 text-emerald-600 px-2.5 py-1.5 rounded text-xs hover:bg-emerald-50 font-medium" title="Re-sync courier statuses for all active orders">🔄 Check</button>
    <button type="button" onclick="fcCheck('')" class="border border-blue-200 text-blue-600 px-2.5 py-1.5 rounded text-xs hover:bg-blue-50 font-medium" title="Fraud check a customer phone">🔍 Fraud</button>
    <div class="relative" id="viewWrap" style="display:inline-block">
        <button type="button" onclick="document.getElementById('viewDrop').classList.toggle('show')" class="border text-gray-500 px-2.5 py-1.5 rounded text-xs hover:bg-gray-50 font-medium flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg> View</button>
        <div id="viewDrop" class="om-view-drop">
            <div style="padding:6px 14px 8px;font-weight:700;font-size:12px;color:#111827;border-bottom:1px solid #f3f4f6;margin-bottom:4px">Toggle columns</div>
            <?php
            $__cols = [
                ['key'=>'date','label'=>'Date'],['key'=>'invoice','label'=>'Invoice'],
                ['key'=>'customer','label'=>'Customer'],['key'=>'note','label'=>'Note'],
                ['key'=>'products','label'=>'Products'],['key'=>'tags','label'=>'Tags'],
                ['key'=>'total','label'=>'Total'],['key'=>'upload','label'=>'Upload'],
                ['key'=>'print','label'=>'Print'],['key'=>'user','label'=>'User'],
                ['key'=>'source','label'=>'Source'],['key'=>'shipping','label'=>'Shipping'],
            ];
            foreach ($__cols as $c): ?>
            <label><input type="checkbox" checked data-col="<?= $c['key'] ?>" onchange="toggleCol(this)"> <?= $c['label'] ?></label>
            <?php endforeach; ?>
            <div style="padding:8px 14px 6px;border-top:1px solid #f3f4f6;margin-top:4px;display:flex;gap:6px">
                <button onclick="saveView()" style="flex:1;padding:5px;border-radius:6px;background:#5eead4;color:#0f766e;font-size:11px;font-weight:600;border:none;cursor:pointer">Save</button>
                <button onclick="resetView()" style="flex:1;padding:5px;border-radius:6px;background:#fca5a5;color:#991b1b;font-size:11px;font-weight:600;border:none;cursor:pointer">Reset</button>
            </div>
        </div>
    </div>
    <div class="relative" id="actionsWrap">
        <button type="button" onclick="document.getElementById('actionsMenu').classList.toggle('hidden')" class="border text-gray-500 px-2.5 py-1.5 rounded text-xs hover:bg-gray-50">⋮ Actions</button>
        <div id="actionsMenu" class="hidden absolute right-0 top-full mt-1 w-56 bg-white rounded-lg shadow-xl border z-50 py-1 max-h-[70vh] overflow-y-auto">
            <div class="px-3 py-1.5 flex items-center justify-between"><span id="selC" class="text-[10px] text-gray-400">0 selected</span><button type="button" onclick="document.getElementById('selectAll').checked=true;toggleAll(document.getElementById('selectAll'))" class="text-[10px] text-blue-600">Select All</button></div><hr class="my-0.5">
            <p class="px-3 py-1 text-[10px] font-bold text-gray-400 uppercase">Print</p>
            <button type="button" onclick="openInvPrint()" class="w-full text-left px-3 py-1.5 text-xs hover:bg-blue-50 text-blue-700 font-medium">📄 Print Invoice</button>
            <button type="button" onclick="openStkPrint()" class="w-full text-left px-3 py-1.5 text-xs hover:bg-orange-50 text-orange-700 font-medium">🏷 Print Sticker</button>
            <button type="button" onclick="openPickingList()" class="w-full text-left px-3 py-1.5 text-xs hover:bg-green-50 text-green-700 font-medium">📦 Picking List</button>
            <?php if (empty($_isProcessingView)): ?>
            <hr class="my-0.5"><p class="px-3 py-1 text-[10px] font-bold text-gray-400 uppercase">Status</p>
            <button type="button" onclick="bStatus('confirmed')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50">✅ Confirm</button>
            <button type="button" onclick="bStatus('ready_to_ship')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50">📦 Ready to Ship</button>
            <button type="button" onclick="bStatus('shipped')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50">🚚 Ship</button>
            <button type="button" onclick="bStatus('delivered')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50">📦 Deliver</button>
            <button type="button" onclick="bStatus('returned')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50 text-orange-600">↩ Return</button>
            <button type="button" onclick="bStatus('cancelled')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50 text-red-600">✗ Cancel</button>
            <button type="button" onclick="openBulkStatusModal()" class="w-full text-left px-3 py-1.5 text-xs hover:bg-indigo-50 text-indigo-600 font-medium">🔄 Update Status (Any)</button>
            <?php else: ?>
            <hr class="my-0.5"><p class="px-3 py-1 text-[10px] font-bold text-gray-400 uppercase">Quick Action</p>
            <button type="button" onclick="bStatus('confirmed')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-blue-50 text-blue-700 font-medium">✅ Confirm Selected</button>
            <?php endif; ?>
            <hr class="my-0.5"><p class="px-3 py-1 text-[10px] font-bold text-gray-400 uppercase">Courier</p>
            <button type="button" onclick="bCourier('Pathao')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50"><span class="inline-block w-3.5 h-3.5 bg-red-500 text-white rounded text-[9px] text-center mr-1 font-bold leading-[14px]">P</span>Pathao</button>
            <button type="button" onclick="bCourier('Steadfast')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50"><span class="inline-block w-3.5 h-3.5 bg-blue-500 text-white rounded text-[9px] text-center mr-1 font-bold leading-[14px]">S</span>Steadfast</button>
            <button type="button" onclick="bCourier('RedX')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50"><span class="inline-block w-3.5 h-3.5 bg-orange-500 text-white rounded text-[9px] text-center mr-1 font-bold leading-[14px]">R</span>RedX</button>
            <hr class="my-0.5">
            <button type="button" onclick="syncCourier()" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50 text-indigo-600">🔄 Sync Courier</button>
            <a href="<?= SITE_URL ?>/api/export.php?type=orders<?= $status?'&status='.$status:'' ?>" class="block px-3 py-1.5 text-xs hover:bg-gray-50">📊 Export Excel</a>
        </div>
    </div>
</div>

<!-- Advanced Filters (hidden) -->
<div id="advFilters" class="<?= ($dateFrom||$dateTo||$channel||$assignedTo||$preorderFilter)?'':'hidden' ?> bg-white rounded-lg border p-3 mb-3">
    <form method="GET" class="flex flex-wrap items-center gap-2" id="advFiltersForm" onsubmit="event.preventDefault();OM.goAdv(this)">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <input type="hidden" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="hidden" name="date_to" value="<?= e($dateTo) ?>">
        <div data-kb-datepicker data-from-param="date_from" data-to-param="date_to"></div>
        <select name="channel" class="px-2.5 py-1.5 border rounded text-xs" onchange="OM.goAdv(document.getElementById('advFiltersForm'))"><option value="">All Channels</option><?php foreach(['website','facebook','phone','whatsapp','instagram','landing_page'] as $ch): ?><option value="<?= $ch ?>" <?= $channel===$ch?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$ch)) ?></option><?php endforeach; ?></select>
        <input type="text" name="customer" value="<?= e($customerFilter) ?>" placeholder="Customer name/phone..." class="px-2.5 py-1.5 border rounded text-xs w-44" onchange="OM.goAdv(document.getElementById('advFiltersForm'))">
        <select name="tag" class="px-2.5 py-1.5 border rounded text-xs" onchange="OM.goAdv(document.getElementById('advFiltersForm'))">
          <option value="">All Tags</option>
          <?php
          try {
            $allTags = $db->fetchAll("SELECT DISTINCT tag_name FROM order_tags ORDER BY tag_name");
            foreach ($allTags as $t): ?>
            <option value="<?= e($t['tag_name']) ?>" <?= $tagFilter===$t['tag_name']?'selected':'' ?>><?= e($t['tag_name']) ?></option>
          <?php endforeach; } catch(\Throwable $e) {} ?>
        </select>
        <select name="assigned" class="px-2.5 py-1.5 border rounded text-xs" onchange="OM.goAdv(document.getElementById('advFiltersForm'))"><option value="">All Staff</option><?php foreach($adminUsers as $au): ?><option value="<?= $au['id'] ?>" <?= $assignedTo==$au['id']?'selected':'' ?>><?= e($au['full_name']) ?></option><?php endforeach; ?></select>
        <select name="preorder" class="px-2.5 py-1.5 border rounded text-xs" onchange="OM.goAdv(document.getElementById('advFiltersForm'))"><option value="">All Orders</option><option value="1" <?= $preorderFilter==='1'?'selected':'' ?>>⏰ Preorders Only</option><option value="0" <?= $preorderFilter==='0'?'selected':'' ?>>Regular Only</option></select>
        <button type="button" onclick="OM.go({status:'',search:'',courier:'',date_from:'',date_to:'',channel:'',assigned:'',preorder:'',page:1})" class="bg-gray-100 text-gray-500 px-3 py-1.5 rounded text-xs">✕ Reset</button>
    </form>
</div>

<!-- Courier Upload Progress -->
<div id="cProg" class="hidden mb-3 bg-white rounded-lg border p-3">
    <div class="flex items-center justify-between mb-1.5"><span class="text-xs font-medium" id="cProgL">Uploading...</span><span id="cProgC" class="text-[10px] text-gray-400"></span></div>
    <div class="w-full bg-gray-200 rounded-full h-1.5"><div id="cProgB" class="bg-blue-600 h-1.5 rounded-full transition-all" style="width:0%"></div></div>
    <div id="cErr" class="mt-1.5 text-[10px] text-red-600 hidden"></div>
</div>

<!-- Orders Table -->
<form method="POST" id="bulkForm"><input type="hidden" name="action" value="bulk_status">
<div class="om-wrap relative" id="ordersTableWrap">
    <div id="ordersLoading" class="hidden absolute inset-0 bg-white/70 z-10 flex items-center justify-center">
        <div class="flex items-center gap-2 bg-white shadow-lg rounded-xl px-5 py-3 border">
            <svg class="animate-spin w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            <span class="text-sm font-medium text-gray-600">Loading orders...</span>
        </div>
    </div>
    <table class="om-table" id="ordersTable">
        <thead>
            <tr>
                <th style="width:28px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
                <th data-col="date" style="width:80px"><a href="#" onclick="event.preventDefault();OM.goSort('created_at')" style="cursor:pointer">Date <?= sortIcon('created_at') ?></a></th>
                <th data-col="invoice" style="width:68px"><a href="#" onclick="event.preventDefault();OM.goSort('order_number')" style="cursor:pointer">Invoice <?= sortIcon('order_number') ?></a></th>
                <th data-col="customer">Customer</th>
                <th data-col="note">Note</th>
                <th data-col="products">Products</th>
                <th data-col="tags" style="width:72px">Tags</th>
                <th data-col="total" style="width:68px;text-align:right"><a href="#" onclick="event.preventDefault();OM.goSort('total')" style="cursor:pointer">Total <?= sortIcon('total') ?></a></th>
                <th data-col="upload" style="width:110px">Upload</th>
                <th data-col="print" style="width:34px;text-align:center">Print</th>
                <th data-col="user" style="width:62px">User</th>
                <th data-col="source" style="width:44px"><a href="#" onclick="event.preventDefault();OM.goSort('channel')" style="cursor:pointer">Src <?= sortIcon('channel') ?></a></th>
                <th data-col="shipping">Shipping</th>
                <th style="width:56px;text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody>
<?php 
$__tplFile2 = __DIR__ . '/order-row-tpl.php';
foreach ($orders as $order):
    $sr = $successRates[$order['customer_phone']] ?? ['total'=>0,'delivered'=>0,'rate'=>0,'cancelled'=>0,'returned'=>0,'total_spent'=>0,'courier_breakdown'=>[]];
    $prevO = $previousOrders[$order['customer_phone']] ?? [];
    $oItems = $orderItems[$order['id']] ?? [];
    $tags = $orderTags[$order['id']] ?? [];
    $rC = $sr['rate']>=80?'text-green-600':($sr['rate']>=50?'text-yellow-600':'text-red-500');
    $ph = preg_replace('/[^0-9]/','',$order['customer_phone']);
    $oStatus = $order['order_status'] === 'pending' ? 'processing' : $order['order_status'];
    $nxt = $nextAction[$oStatus] ?? null;
    $creditUsed = floatval($order['store_credit_used'] ?? 0);
    $statusColors=['processing'=>'bg-yellow-100 text-yellow-700','confirmed'=>'bg-blue-100 text-blue-700','ready_to_ship'=>'bg-violet-100 text-violet-700','shipped'=>'bg-purple-100 text-purple-700','delivered'=>'bg-green-100 text-green-700','cancelled'=>'bg-red-100 text-red-700','returned'=>'bg-orange-100 text-orange-700','pending_return'=>'bg-amber-100 text-amber-700','pending_cancel'=>'bg-pink-100 text-pink-700','partial_delivered'=>'bg-cyan-100 text-cyan-700','on_hold'=>'bg-gray-100 text-gray-700','no_response'=>'bg-rose-100 text-rose-700','good_but_no_response'=>'bg-teal-100 text-teal-700','advance_payment'=>'bg-emerald-100 text-emerald-700'];
    $statusDots=['processing'=>'#eab308','confirmed'=>'#3b82f6','ready_to_ship'=>'#8b5cf6','shipped'=>'#9333ea','delivered'=>'#22c55e','cancelled'=>'#ef4444','returned'=>'#f97316','pending_return'=>'#f59e0b','on_hold'=>'#6b7280'];
    $sDot = $statusDots[$oStatus] ?? '#94a3b8';
    $sBadge = $statusColors[$oStatus] ?? 'bg-gray-100 text-gray-600';
    $__cn = strtolower($order['courier_name'] ?? ($order['shipping_method'] ?? ''));
    $__cid = $order['courier_consignment_id'] ?? ($order['pathao_consignment_id'] ?? '');
    $__tid = $order['courier_tracking_id'] ?? $__cid;
    $__link = '';
    if ($__cid) {
        if (strpos($__cn,'steadfast')!==false) $__link='https://steadfast.com.bd/user/consignment/'.urlencode($__cid);
        elseif (strpos($__cn,'pathao')!==false) $__link='https://merchant.pathao.com/courier/orders/'.urlencode($__cid);
        elseif (strpos($__cn,'redx')!==false) $__link='https://redx.com.bd/track-parcel/?trackingId='.urlencode($__tid);
    }
    $channelMap=['website'=>'WEB','facebook'=>'FACEBOOK','phone'=>'PHONE','whatsapp'=>'WHATSAPP','instagram'=>'INSTAGRAM','landing_page'=>'LP'];
    $srcLabel=$channelMap[$order['channel']??'']??strtoupper($order['channel']??'\xe2\x80\x94');
    include $__tplFile2;
endforeach;
if (empty($orders)):
?>
<tr><td colspan="14" style="text-align:center;padding:40px 20px;color:#94a3b8"><div style="font-size:28px;margin-bottom:8px">📦</div>No orders found</td></tr>
<?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<div id="ordersPagination">
<?php if ($totalPages > 1): ?>
<div class="flex items-center justify-between mt-3 px-1">
    <p class="text-xs text-gray-500">Page <strong><?= $page ?></strong> of <?= $totalPages ?> · <?= number_format($total) ?> orders</p>
    <div class="flex gap-1">
        <?php if($page>1): ?><button class="px-2.5 py-1 text-xs rounded bg-white border hover:bg-gray-50" onclick="OM.go({page:<?= $page-1 ?>})">←</button><?php endif; ?>
        <?php for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?><button class="px-2.5 py-1 text-xs rounded <?= $i===$page?'bg-blue-600 text-white border-blue-600':'bg-white border hover:bg-gray-50' ?>" onclick="OM.go({page:<?= $i ?>})"><?= $i ?></button><?php endfor; ?>
        <?php if($page<$totalPages): ?><button class="px-2.5 py-1 text-xs rounded bg-white border hover:bg-gray-50" onclick="OM.go({page:<?= $page+1 ?>})">→</button><?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>
</form>

<!-- Row Action Menu (reusable popup) -->
<div id="rowMenu" class="hidden fixed z-50 w-44 bg-white rounded-lg shadow-xl border py-1" style="font-size:12px">
    <a id="rmOpen" href="#" class="block px-3 py-1.5 hover:bg-gray-50">📋 Open Order</a>
    <button id="rmEdit" type="button" class="w-full text-left block px-3 py-1.5 hover:bg-gray-50 text-gray-700 font-medium">✏️ Edit Order</button>
    <hr class="my-0.5">
    <button id="rmPrintInv" type="button" class="w-full text-left block px-3 py-1.5 hover:bg-blue-50 text-blue-700">📄 Print Invoice</button>
    <button id="rmPrintStk" type="button" class="w-full text-left block px-3 py-1.5 hover:bg-orange-50 text-orange-700">🏷 Print Sticker</button>
    <hr class="my-0.5">
    <button id="rmConfirm" class="w-full text-left px-3 py-1.5 hover:bg-gray-50 text-blue-600" type="button">✅ Confirm</button>
    <button id="rmShip" class="w-full text-left px-3 py-1.5 hover:bg-gray-50 text-purple-600" type="button">🚚 Ship</button>
    <button id="rmDeliver" class="w-full text-left px-3 py-1.5 hover:bg-gray-50 text-green-600" type="button">📦 Deliver</button>
    <hr class="my-0.5">
    <button id="rmCancel" class="w-full text-left px-3 py-1.5 hover:bg-gray-50 text-red-600" type="button">✗ Cancel</button>
</div>

<!-- Tag Modal -->
<div id="tagModal" class="fixed inset-0 z-50 hidden bg-black/40 flex items-center justify-center">
    <div class="bg-white rounded-lg p-4 w-72 shadow-2xl">
        <h3 class="font-bold text-gray-800 text-sm mb-2">Add Tag</h3><input type="hidden" id="tagOId">
        <div class="flex flex-wrap gap-1.5 mb-2"><?php foreach(['REPEAT','URGENT','VIP','GIFT','FOLLOW UP','COD VERIFIED','ADVANCE PAID'] as $p): ?><button type="button" onclick="subTag('<?= $p ?>')" class="text-[10px] bg-gray-100 hover:bg-blue-100 px-2 py-1 rounded"><?= $p ?></button><?php endforeach; ?></div>
        <div class="flex gap-1.5"><input type="text" id="tagIn" placeholder="Custom..." class="flex-1 px-2.5 py-1.5 border rounded text-xs" onkeydown="if(event.key==='Enter'){event.preventDefault();subTag(this.value)}"><button type="button" onclick="subTag(document.getElementById('tagIn').value)" class="bg-blue-600 text-white px-3 py-1.5 rounded text-xs">Add</button></div>
        <button type="button" onclick="document.getElementById('tagModal').classList.add('hidden')" class="mt-1.5 text-[10px] text-gray-400 w-full text-center">Cancel</button>
    </div>
</div>

<script>

// ─── OM: AJAX Order Management Controller ─────────────────────────────────
const OM = {
  // Current filter state (populated from PHP on page load)
  state: {
    status:   '<?= e($status) ?>',
    search:   '<?= e($search) ?>',
    courier:  '<?= e($courier) ?>',
    sort:     '<?= e($sortCol) ?>',
    dir:      '<?= e($sortDir) ?>',
    page:     <?= $page ?>,
    date_from:'<?= e($dateFrom) ?>',
    date_to:  '<?= e($dateTo) ?>',
    channel:  '<?= e($channel) ?>',
    assigned: '<?= e($assignedTo) ?>',
    preorder: '<?= e($preorderFilter) ?>',
    tag:      '<?= e($tagFilter) ?>',
    customer: '<?= e($customerFilter) ?>',
    per_page: '<?= $limit >= 999999 ? 0 : $limit ?>',
  },
  _loading: false,

  // Navigate: merge params with current state, then fetch
  go(params) {
    const next = {...OM.state, ...params};
    // If changing tab or courier (not page), reset to page 1
    if ('status' in params || 'courier' in params) next.page = params.page || 1;
    OM.state = next;
    // Clean URL: only show essential filters, hide noise (per_page, sort, dir, page defaults)
    const essentialKeys = ['status','search','courier','date_from','date_to','channel','assigned','preorder','tag','customer'];
    const urlParams = {};
    essentialKeys.forEach(k => { if (next[k] && next[k] !== '') urlParams[k] = next[k]; });
    if (next.page > 1) urlParams.page = next.page;
    const qs = new URLSearchParams(urlParams);
    history.pushState(next, '', '?' + qs.toString());
    OM._fetch(next);
  },

  // Sort toggle: same col flips dir, new col = desc
  goSort(col) {
    const dir = OM.state.sort === col ? (OM.state.dir === 'ASC' ? 'desc' : 'asc') : 'desc';
    OM.go({sort: col, dir, page: 1});
  },

  // Advanced filters form submit
  goAdv(form) {
    const data = new FormData(form);
    const params = {};
    for (const [k, v] of data.entries()) {
      if (!['action','_frag'].includes(k)) params[k] = v;
    }
    params.page = 1;
    // Preserve status & courier from current state
    params.status  = params.status  || OM.state.status  || '';
    params.courier = params.courier || OM.state.courier  || '';
    OM.go(params);
  },

  // Just re-fetch current state (after bulk actions etc)
  refresh() { OM._fetch(OM.state); },

  async _fetch(params) {
    if (OM._loading) return;
    OM._loading = true;
    const loadEl = document.getElementById('ordersLoading');
    if (loadEl) loadEl.classList.remove('hidden');

    const qs = new URLSearchParams(Object.fromEntries(Object.entries(params).filter(([,v]) => v !== '' && v !== null)));
    qs.set('_frag', '1');

    try {
      const r = await fetch('?' + qs.toString(), {headers: {'X-Requested-With': 'XMLHttpRequest'}});
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const data = await r.json();

      // Swap rows
      const tbody = document.querySelector('#ordersTable tbody');
      if (tbody) tbody.innerHTML = data.rows;

      // Swap pagination
      const pag = document.getElementById('ordersPagination');
      if (pag) pag.innerHTML = data.pagination;

      // Update status tab counts + active states
      OM._updateStatusTabs(data.statusCounts, params.status || '');

      // Update courier counts + active states
      OM._updateCourierTabs(data.courierCounts, params.courier || '');

      // Show/hide courier sub-tab bar (from confirmed onwards)
      const courierBar = document.querySelector('.om-courier-bar');
      if (courierBar) {
        var _noCourrierStatuses = ['', 'processing', 'no_response', 'good_but_no_response', 'advance_payment'];
        courierBar.classList.toggle('hidden', !params.status || _noCourrierStatuses.indexOf(params.status) >= 0);
      }

      // Re-init polling for new rows
      pollOrderLocks();

    } catch(e) {
      console.error('[OM] fetch error:', e);
    } finally {
      OM._loading = false;
      if (loadEl) loadEl.classList.add('hidden');
    }
  },

  _updateStatusTabs(counts, activeStatus) {
    document.querySelectorAll('[data-status-tab]').forEach(el => {
      const s = el.dataset.statusTab;
      const isActive = s === activeStatus;
      // Toggle active classes
      el.classList.toggle('border-blue-600', isActive);
      el.classList.toggle('text-blue-600', isActive);
      el.classList.toggle('border-transparent', !isActive);
      el.classList.toggle('text-gray-500', !isActive);
      // Update count
      const countEl = el.querySelector('.tab-count');
      if (countEl && s && counts[s] !== undefined) {
        countEl.textContent = Number(counts[s]).toLocaleString();
        const activeClass = 'bg-blue-100 text-blue-700 font-bold';
        const inactiveClass = 'bg-gray-100 text-gray-500';
        countEl.className = 'px-1.5 py-0.5 rounded text-[10px] tab-count ' + (isActive ? activeClass : inactiveClass);
      }
    });
  },

  _updateCourierTabs(counts, activeCourier) {
    document.querySelectorAll('[data-courier-tab]').forEach(el => {
      const c = el.dataset.courierTab;
      const isActive = c === activeCourier;
      const countEl = el.querySelector('.courier-count');
      if (countEl && counts[c] !== undefined) {
        countEl.textContent = Number(counts[c]).toLocaleString();
      }
      // Re-apply active/inactive styling via data attr for PHP-rendered classes
      // We toggle a data-active attr and let CSS handle it via attribute selector
      el.dataset.active = isActive ? '1' : '0';
    });
  }
};

// Handle browser back/forward
window.addEventListener('popstate', e => {
  if (e.state) { OM.state = e.state; OM._fetch(e.state); }
});

// Set initial pushState so back button works from first load
history.replaceState(OM.state, '', location.href);

const defC='<?= e($defaultCourier) ?>';
function toggleAll(el){document.querySelectorAll('.order-check').forEach(c=>c.checked=el.checked);updateBulk()}
function updateBulk(){const n=document.querySelectorAll('.order-check:checked').length;document.getElementById('selC').textContent=n+' selected'}
function getIds(){return Array.from(document.querySelectorAll('.order-check:checked')).map(c=>c.value)}

// Print functions — see invPrint/stkPrint modals at bottom of page
async function bStatus(s){
    const ids = getIds();
    if (!ids.length) { alert('Select orders first'); return; }
    const ok = await window._confirmAsync('Change ' + ids.length + ' order(s) to "' + s + '"?');
    if (!ok) return;
    
    document.getElementById('actionsMenu').classList.add('hidden');
    
    const p = document.getElementById('cProg');
    p.classList.remove('hidden');
    document.getElementById('cProgL').textContent = '⏳ Updating ' + ids.length + ' orders to ' + s + '...';
    document.getElementById('cProgB').style.width = '30%';
    document.getElementById('cErr').classList.add('hidden');
    document.getElementById('cErr').innerHTML = '';
    
    const fd = new FormData();
    fd.append('action', 'bulk_status');
    fd.append('bulk_status', s);
    fd.append('_ajax', '1');
    ids.forEach(i => fd.append('order_ids[]', i));
    
    fetch(location.href.split('?')[0], {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
    })
    .then(r => {
        const contentType = r.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            return r.json();
        }
        return r.text().then(txt => {
            // Try to extract JSON from response
            const jsonMatch = txt.match(/\{[\s\S]*\}/);
            if (jsonMatch) {
                return JSON.parse(jsonMatch[0]);
            }
            throw new Error('Invalid response: ' + txt.substring(0, 100));
        });
    })
    .then(d => {
        document.getElementById('cProgB').style.width = '100%';
        if (d.success) {
            document.getElementById('cProgL').textContent = '✓ Updated ' + (d.count || ids.length) + ' orders to ' + s;
            if (d.errors && d.errors.length) {
                const e = document.getElementById('cErr');
                e.classList.remove('hidden');
                e.innerHTML = d.errors.slice(0, 5).join('<br>');
            }
        } else {
            document.getElementById('cProgL').textContent = '⚠ Error: ' + (d.error || 'Unknown error');
        }
        setTimeout(() => {
            p.classList.add('hidden');
            OM.refresh();
        }, 1500);
    })
    .catch(e => {
        console.error('Bulk status error:', e);
        document.getElementById('cProgL').textContent = '❌ Error: ' + e.message;
        document.getElementById('cErr').classList.remove('hidden');
        document.getElementById('cErr').textContent = e.message;
        setTimeout(() => {
            p.classList.add('hidden');
            OM.refresh();
        }, 3000);
    });
}
function bCourier(c) {
    const ids = getIds();
    if (!ids.length) { alert('Select orders first'); return; }
    document.getElementById('actionsMenu').classList.add('hidden');
    openCourierUploadModal(ids, c);
}

function openCourierUploadModal(ids, courier) {
    // Create or show the modal
    let m = document.getElementById('courierUploadModal');
    if (!m) {
        m = document.createElement('div');
        m.id = 'courierUploadModal';
        m.innerHTML = `
            <div style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(2px)" onclick="if(event.target===this&&!window.__courierUploading)closeCourierModal()">
                <div style="background:#fff;border-radius:16px;max-width:640px;width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,.3);animation:cumFadeIn .2s ease" onclick="event.stopPropagation()">
                    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
                        <div>
                            <h3 id="cumTitle" style="font-size:15px;font-weight:700;color:#111827;margin:0">🚀 Uploading to Courier</h3>
                            <p id="cumSubtitle" style="font-size:11px;color:#6b7280;margin:4px 0 0"></p>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span id="cumBadge" style="display:none;font-size:10px;font-weight:600;padding:3px 10px;border-radius:12px;background:#dbeafe;color:#2563eb"></span>
                            <button id="cumCloseX" onclick="closeCourierModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;line-height:1;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:6px;transition:all .15s" onmouseover="this.style.background='#f3f4f6';this.style.color='#374151'" onmouseout="this.style.background='none';this.style.color='#9ca3af'">&times;</button>
                        </div>
                    </div>
                    <div id="cumProgress" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;flex-shrink:0">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                            <span id="cumStatus" style="font-size:12px;font-weight:600;color:#374151">Preparing...</span>
                            <div style="display:flex;align-items:center;gap:12px">
                                <span id="cumTimer" style="font-size:10px;color:#9ca3af;display:none"></span>
                                <span id="cumCounter" style="font-size:11px;font-weight:600;color:#6b7280;background:#f3f4f6;padding:2px 8px;border-radius:6px">0 / 0</span>
                            </div>
                        </div>
                        <div style="background:#e5e7eb;border-radius:10px;height:10px;overflow:hidden;position:relative">
                            <div id="cumBar" style="background:linear-gradient(90deg,#3b82f6,#6366f1);height:100%;width:0%;transition:width .4s ease;border-radius:10px;position:relative;overflow:hidden">
                                <div style="position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.3),transparent);animation:cumShimmer 1.5s infinite"></div>
                            </div>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-top:6px">
                            <span id="cumPercent" style="font-size:11px;font-weight:600;color:#4b5563">0%</span>
                            <div style="display:flex;gap:12px">
                                <span id="cumSuccess" style="font-size:10px;font-weight:600;color:#16a34a">✓ 0</span>
                                <span id="cumFailed" style="font-size:10px;font-weight:600;color:#dc2626">✗ 0</span>
                            </div>
                        </div>
                        <div id="cumCooldown" style="display:none;margin-top:8px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;text-align:center">
                            <div style="font-size:11px;font-weight:600;color:#92400e">⏸ Rate Limit Cooldown</div>
                            <div id="cumCooldownTimer" style="font-size:20px;font-weight:700;color:#d97706;margin:4px 0">30s</div>
                            <div style="font-size:10px;color:#a16207">Respecting courier API limits — resuming automatically</div>
                        </div>
                    </div>
                    <div id="cumResults" style="flex:1;overflow-y:auto;padding:12px 16px;min-height:180px;max-height:380px">
                        <div style="text-align:center;padding:40px 20px;color:#9ca3af">
                            <div style="font-size:36px;margin-bottom:8px;animation:cumBounce 1s infinite">📦</div>
                            <div style="font-size:12px">Preparing orders for upload...</div>
                        </div>
                    </div>
                    <div id="cumFooter" style="padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;background:#fafafa;border-radius:0 0 16px 16px">
                        <div id="cumSummary" style="font-size:11px;color:#6b7280"></div>
                        <div style="display:flex;gap:8px">
                            <button id="cumPauseBtn" onclick="toggleCourierPause()" style="display:none;padding:8px 14px;border-radius:8px;border:1px solid #d1d5db;background:#fff;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='#fff'">⏸ Pause</button>
                            <button id="cumCloseBtn" onclick="closeCourierModal()" style="padding:8px 14px;border-radius:8px;border:1px solid #d1d5db;background:#fff;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='#fff'">Close</button>
                            <button id="cumRefreshBtn" onclick="closeCourierModal();OM.refresh()" style="padding:8px 14px;border-radius:8px;border:none;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;font-size:11px;font-weight:600;cursor:pointer;display:none;transition:all .15s;box-shadow:0 2px 6px rgba(59,130,246,.3)" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='none'">✓ Refresh List</button>
                        </div>
                    </div>
                </div>
            </div>
            <style>
                @keyframes cumFadeIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
                @keyframes cumShimmer{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}
                @keyframes cumBounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
                @keyframes cumSlideIn{from{opacity:0;transform:translateX(-8px)}to{opacity:1;transform:translateX(0)}}
                @keyframes cumPulse{0%,100%{opacity:1}50%{opacity:.6}}
                .cum-row{animation:cumSlideIn .25s ease}
                .cum-row-uploading{animation:cumPulse 1s infinite}
            </style>
        `;
        document.body.appendChild(m);
    }
    
    m.style.display = 'block';
    window.__courierUploading = true;
    window.__courierPaused = false;
    
    const el = (id) => document.getElementById(id);
    el('cumTitle').textContent = '🚀 Uploading to ' + courier;
    el('cumSubtitle').textContent = ids.length + ' order(s) · Sequential upload with rate limiting';
    el('cumStatus').textContent = 'Starting upload...';
    el('cumBadge').style.display = 'inline-block';
    el('cumBadge').textContent = 'Processing (0/' + ids.length + ')';
    el('cumBadge').style.background = '#dbeafe'; el('cumBadge').style.color = '#2563eb';
    el('cumCounter').textContent = '0 / ' + ids.length;
    el('cumBar').style.width = '2%';
    el('cumPercent').textContent = '0%';
    el('cumSuccess').textContent = '✓ 0';
    el('cumFailed').textContent = '✗ 0';
    el('cumCooldown').style.display = 'none';
    el('cumTimer').style.display = 'inline';
    el('cumTimer').textContent = '';
    el('cumRefreshBtn').style.display = 'none';
    el('cumPauseBtn').style.display = 'inline-block';
    el('cumPauseBtn').textContent = '⏸ Pause';
    el('cumCloseX').onclick = function(){ if(!window.__courierUploading) closeCourierModal(); else toggleCourierPause(); };
    el('cumSummary').textContent = '';
    
    // Build initial results list with all orders as "pending"
    let html = '<div id="cumOrderList" style="display:flex;flex-direction:column;gap:6px">';
    ids.forEach((id, i) => {
        html += '<div id="cumRow_' + id + '" class="cum-row" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;transition:all .25s">';
        html += '<div id="cumIcon_' + id + '" style="width:28px;height:28px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;font-weight:700;color:#9ca3af">' + (i+1) + '</div>';
        html += '<div style="flex:1;min-width:0">';
        html += '<div style="display:flex;align-items:center;gap:6px">';
        html += '<span id="cumOrdNum_' + id + '" style="font-size:12px;font-weight:600;color:#374151">Order #' + id + '</span>';
        html += '<span id="cumOrdBadge_' + id + '" style="font-size:9px;font-weight:600;padding:2px 7px;border-radius:6px;background:#f3f4f6;color:#9ca3af">PENDING</span>';
        html += '</div>';
        html += '<div id="cumOrdMsg_' + id + '" style="font-size:10px;color:#9ca3af;margin-top:2px">Waiting in queue...</div>';
        html += '</div>';
        html += '<div id="cumOrdCid_' + id + '" style="text-align:right;flex-shrink:0;display:none">';
        html += '<div style="font-size:9px;color:#6b7280">Consignment ID</div>';
        html += '<div id="cumOrdCidVal_' + id + '" style="font-size:11px;font-weight:700;color:#2563eb;font-family:ui-monospace,monospace;letter-spacing:.5px"></div>';
        html += '</div>';
        html += '<div id="cumOrdTime_' + id + '" style="font-size:10px;color:#9ca3af;flex-shrink:0;min-width:35px;text-align:right">—</div>';
        html += '</div>';
    });
    html += '</div>';
    el('cumResults').innerHTML = html;
    
    // Start sequential upload
    _sequentialCourierUpload(ids, courier);
}

// ── Sequential Upload Engine with Rate Limiting ──
async function _sequentialCourierUpload(ids, courier) {
    const el = (id) => document.getElementById(id);
    const UPLOAD_INTERVAL_MS = 1000;     // 1 upload per second
    const COOLDOWN_AFTER = 18;            // cooldown after 18 continuous uploads
    const COOLDOWN_SECONDS = 30;          // 30 second cooldown
    
    let uploaded = 0, failed = 0, consecutiveUploads = 0;
    const startTime = Date.now();
    
    for (let i = 0; i < ids.length; i++) {
        // Check pause
        while (window.__courierPaused) {
            await new Promise(r => setTimeout(r, 200));
            if (!document.getElementById('courierUploadModal') || document.getElementById('courierUploadModal').style.display === 'none') return;
        }
        
        const orderId = ids[i];
        const rowEl = el('cumRow_' + orderId);
        const iconEl = el('cumIcon_' + orderId);
        const badgeEl = el('cumOrdBadge_' + orderId);
        const msgEl = el('cumOrdMsg_' + orderId);
        const cidEl = el('cumOrdCid_' + orderId);
        const cidValEl = el('cumOrdCidVal_' + orderId);
        const timeEl = el('cumOrdTime_' + orderId);
        
        // Cooldown check — after 18 continuous uploads, pause 30s
        if (consecutiveUploads > 0 && consecutiveUploads % COOLDOWN_AFTER === 0) {
            el('cumCooldown').style.display = 'block';
            el('cumStatus').textContent = '⏸ Cooldown — Respecting API rate limits';
            el('cumBadge').textContent = 'Cooldown';
            el('cumBadge').style.background = '#fef3c7'; el('cumBadge').style.color = '#92400e';
            
            for (let s = COOLDOWN_SECONDS; s > 0; s--) {
                if (!window.__courierUploading) return;
                el('cumCooldownTimer').textContent = s + 's';
                await new Promise(r => setTimeout(r, 1000));
            }
            
            el('cumCooldown').style.display = 'none';
            el('cumBadge').style.background = '#dbeafe'; el('cumBadge').style.color = '#2563eb';
        }
        
        // Mark as uploading
        if (rowEl) {
            rowEl.style.background = '#eff6ff';
            rowEl.style.borderColor = '#93c5fd';
            rowEl.classList.add('cum-row-uploading');
            rowEl.scrollIntoView({behavior:'smooth', block:'nearest'});
        }
        if (iconEl) { iconEl.style.background = '#dbeafe'; iconEl.style.color = '#2563eb'; iconEl.innerHTML = '<span style="animation:cumPulse .8s infinite">⏳</span>'; }
        if (badgeEl) { badgeEl.textContent = 'UPLOADING'; badgeEl.style.background = '#dbeafe'; badgeEl.style.color = '#2563eb'; }
        if (msgEl) msgEl.textContent = 'Sending to ' + courier + '...';
        
        el('cumStatus').textContent = 'Uploading ' + (i+1) + ' of ' + ids.length + '...';
        el('cumBadge').textContent = 'Processing (' + (i+1) + '/' + ids.length + ')';
        
        // Calculate ETA
        const elapsed = (Date.now() - startTime) / 1000;
        const avgTime = i > 0 ? elapsed / i : 1;
        const remaining = Math.ceil(avgTime * (ids.length - i));
        el('cumTimer').textContent = 'Est. ' + (remaining > 60 ? Math.ceil(remaining/60) + 'm' : remaining + 's') + ' remaining';
        
        // Perform upload
        const uploadStart = Date.now();
        try {
            const resp = await fetch('<?= SITE_URL ?>/api/courier-bulk-upload.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify({ courier: courier.toLowerCase(), order_ids: [orderId] })
            });
            const data = await resp.json();
            const uploadTime = ((Date.now() - uploadStart) / 1000).toFixed(1);
            
            if (data.orders && data.orders[0]) {
                const o = data.orders[0];
                if (o.success) {
                    uploaded++;
                    consecutiveUploads++;
                    if (rowEl) { rowEl.style.background = '#f0fdf4'; rowEl.style.borderColor = '#86efac'; rowEl.classList.remove('cum-row-uploading'); }
                    if (iconEl) { iconEl.style.background = '#dcfce7'; iconEl.style.color = '#16a34a'; iconEl.innerHTML = '✓'; }
                    if (badgeEl) { badgeEl.textContent = 'SUCCESS'; badgeEl.style.background = '#dcfce7'; badgeEl.style.color = '#16a34a'; }
                    if (msgEl) msgEl.textContent = o.message || 'Upload successful';
                    if (timeEl) timeEl.textContent = uploadTime + 's';
                    
                    // Show consignment ID
                    if (o.consignment_id && cidEl && cidValEl) {
                        cidEl.style.display = 'block';
                        cidValEl.textContent = o.consignment_id;
                    }
                    // Update order number if available
                    const numEl = el('cumOrdNum_' + orderId);
                    if (numEl && o.order_number) numEl.textContent = '#' + o.order_number;
                } else {
                    failed++;
                    if (rowEl) { rowEl.style.background = '#fef2f2'; rowEl.style.borderColor = '#fecaca'; rowEl.classList.remove('cum-row-uploading'); }
                    if (iconEl) { iconEl.style.background = '#fee2e2'; iconEl.style.color = '#dc2626'; iconEl.innerHTML = '✗'; }
                    if (badgeEl) { badgeEl.textContent = 'ERROR'; badgeEl.style.background = '#fee2e2'; badgeEl.style.color = '#dc2626'; }
                    if (msgEl) { msgEl.textContent = o.message || 'Upload failed'; msgEl.style.color = '#dc2626'; }
                    if (timeEl) timeEl.textContent = uploadTime + 's';
                }
            } else if (data.error) {
                throw new Error(data.error);
            }
        } catch (e) {
            failed++;
            const uploadTime = ((Date.now() - uploadStart) / 1000).toFixed(1);
            if (rowEl) { rowEl.style.background = '#fef2f2'; rowEl.style.borderColor = '#fecaca'; rowEl.classList.remove('cum-row-uploading'); }
            if (iconEl) { iconEl.style.background = '#fee2e2'; iconEl.style.color = '#dc2626'; iconEl.innerHTML = '✗'; }
            if (badgeEl) { badgeEl.textContent = 'ERROR'; badgeEl.style.background = '#fee2e2'; badgeEl.style.color = '#dc2626'; }
            if (msgEl) { msgEl.textContent = e.message || 'Network error'; msgEl.style.color = '#dc2626'; }
            if (timeEl) timeEl.textContent = uploadTime + 's';
        }
        
        // Update progress
        const pct = Math.round(((i + 1) / ids.length) * 100);
        el('cumBar').style.width = pct + '%';
        el('cumPercent').textContent = pct + '%';
        el('cumCounter').textContent = (i + 1) + ' / ' + ids.length;
        el('cumSuccess').textContent = '✓ ' + uploaded;
        el('cumFailed').textContent = '✗ ' + failed;
        
        // Wait between uploads (1 second interval)
        if (i < ids.length - 1) {
            const waitTime = Math.max(0, UPLOAD_INTERVAL_MS - (Date.now() - uploadStart));
            if (waitTime > 0) await new Promise(r => setTimeout(r, waitTime));
        }
    }
    
    // Done
    window.__courierUploading = false;
    const totalTime = ((Date.now() - startTime) / 1000).toFixed(1);
    
    el('cumBar').style.width = '100%';
    if (failed === 0) {
        el('cumBar').style.background = 'linear-gradient(90deg,#22c55e,#16a34a)';
        el('cumStatus').textContent = '✓ All uploads complete!';
        el('cumBadge').textContent = 'Complete';
        el('cumBadge').style.background = '#dcfce7'; el('cumBadge').style.color = '#16a34a';
    } else if (uploaded === 0) {
        el('cumBar').style.background = '#ef4444';
        el('cumStatus').textContent = '✗ All uploads failed';
        el('cumBadge').textContent = 'Failed';
        el('cumBadge').style.background = '#fee2e2'; el('cumBadge').style.color = '#dc2626';
    } else {
        el('cumBar').style.background = 'linear-gradient(90deg,#f59e0b,#d97706)';
        el('cumStatus').textContent = '⚠ Completed with errors';
        el('cumBadge').textContent = 'Partial';
        el('cumBadge').style.background = '#fef3c7'; el('cumBadge').style.color = '#92400e';
    }
    
    el('cumTimer').textContent = 'Total: ' + totalTime + 's';
    el('cumPauseBtn').style.display = 'none';
    el('cumRefreshBtn').style.display = 'inline-block';
    el('cumSummary').innerHTML = '<span style="color:#16a34a;font-weight:600">✓ ' + uploaded + ' uploaded</span> · <span style="color:#dc2626;font-weight:600">✗ ' + failed + ' failed</span> · ' + totalTime + 's total';
    el('cumCloseX').onclick = function(){ closeCourierModal(); };
}

function toggleCourierPause() {
    window.__courierPaused = !window.__courierPaused;
    const btn = document.getElementById('cumPauseBtn');
    if (window.__courierPaused) {
        btn.textContent = '▶ Resume';
        btn.style.background = '#dcfce7'; btn.style.borderColor = '#86efac';
        document.getElementById('cumStatus').textContent = '⏸ Paused — Click Resume to continue';
        document.getElementById('cumBadge').textContent = 'Paused';
        document.getElementById('cumBadge').style.background = '#fef3c7';
        document.getElementById('cumBadge').style.color = '#92400e';
    } else {
        btn.textContent = '⏸ Pause';
        btn.style.background = '#fff'; btn.style.borderColor = '#d1d5db';
        document.getElementById('cumStatus').textContent = 'Resuming...';
        document.getElementById('cumBadge').style.background = '#dbeafe';
        document.getElementById('cumBadge').style.color = '#2563eb';
    }
}

async function closeCourierModal() {
    if (window.__courierUploading) {
        const ok = await window._confirmAsync('Upload is still in progress. Stop uploading and close?');
        if (!ok) return;
        window.__courierUploading = false;
    }
    const m = document.getElementById('courierUploadModal');
    if (m) m.style.display = 'none';
}

// Legacy function - redirect to new popup
function doCourier(ids, c) {
    openCourierUploadModal(ids, c);
}

function addTag(id){document.getElementById('tagOId').value=id;document.getElementById('tagIn').value='';document.getElementById('tagModal').classList.remove('hidden');document.getElementById('tagIn').focus()}
function subTag(t){
  t=t.trim();if(!t)return;
  const id=document.getElementById('tagOId').value;
  if(!id){return;}
  fetch(location.pathname,{
    method:'POST',
    credentials:'same-origin',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=add_tag&order_id='+encodeURIComponent(id)+'&tag='+encodeURIComponent(t)
  })
  .then(r=>r.json())
  .then(d=>{
    if(d && d.success){
      document.getElementById('tagModal').classList.add('hidden');
      OM.refresh();
    }
  })
  .catch(()=>{
    document.getElementById('tagModal').classList.add('hidden');
    OM.refresh();
  });
}

// Row context menu
async function toggleRowMenu(el, orderId, orderNum) {
    const rm = document.getElementById('rowMenu');
    if (rm._open === orderId) { rm.classList.add('hidden'); rm._open = null; return; }
    const r = el.getBoundingClientRect();
    rm.style.top = (r.bottom + window.scrollY + 2) + 'px';
    rm.style.left = Math.min(r.left, window.innerWidth - 190) + 'px';
    rm.classList.remove('hidden');
    rm._open = orderId;
    document.getElementById('rmOpen').href = '<?= adminUrl('pages/order-view.php?order=') ?>' + encodeURIComponent(orderNum || orderId);
    document.getElementById('rmPrintInv').onclick = () => { rm.classList.add('hidden'); openInvPrint([orderId]); };
    document.getElementById('rmPrintStk').onclick = () => { rm.classList.add('hidden'); openStkPrint([orderId]); };
    if (document.getElementById('rmEdit')) {
        document.getElementById('rmEdit').onclick = () => { rm.classList.add('hidden'); openEditOrder(orderId, orderNum); };
    }
    ['Confirm','Ship','Deliver','Cancel'].forEach(a => {
        const btn = document.getElementById('rm'+a);
        btn.onclick = async () => {
            const ok = await window._confirmAsync(a+' this order?');
            if(ok){
            const fd=new FormData();fd.append('action','update_status');fd.append('order_id',orderId);fd.append('status',{Confirm:'confirmed',Ship:'shipped',Deliver:'delivered',Cancel:'cancelled'}[a]);
            fd.append('_ajax','1');
            fetch(location.pathname,{method:'POST',credentials:'same-origin',body:fd}).then(r=>{try{r.json().then(()=>OM.refresh());}catch(e){OM.refresh();}}).catch(()=>OM.refresh());
        }};
    });
}
document.addEventListener('click', e => {
    const rm = document.getElementById('rowMenu');
    if (rm && !rm.contains(e.target) && !e.target.classList.contains('dot-menu')) { rm.classList.add('hidden'); rm._open = null; }
    const w = document.getElementById('actionsWrap');
    if (w && !w.contains(e.target)) document.getElementById('actionsMenu').classList.add('hidden');
});

// ── Processing Session ───────────────────────────────────────────────────────
function startProcessingSession() {
    const btn = document.getElementById('startProcBtn');
    const origBtnHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.textContent = '⏳ Loading...'; btn.disabled = true; }
    // Build query params from current state (search, date filters etc)
    const qs = new URLSearchParams();
    if (OM.state.search)    qs.set('search', OM.state.search);
    if (OM.state.date_from) qs.set('date_from', OM.state.date_from);
    if (OM.state.date_to)   qs.set('date_to', OM.state.date_to);
    fetch('<?= adminUrl('api/processing-queue.php') ?>?' + qs.toString(), {credentials:'same-origin'})
        .then(r => r.json())
        .then(data => {
            if (!data.queue || data.queue.length === 0) {
                const skippedTotal = data.skipped ? Object.values(data.skipped).reduce((a,b)=>a+b,0) : 0;
                if (skippedTotal > 0) {
                    alert('No orders available — all ' + skippedTotal + ' processing orders are currently locked by other team members.');
                } else {
                    alert('No processing orders in the queue.');
                }
                if (btn) { btn.innerHTML = origBtnHtml; btn.disabled = false; }
                return;
            }
            // Store queue silently (locked orders already excluded by API)
            const session = {
                queue:     data.queue,
                total:     data.queue.length,
                current:   0,
                processed: 0,
                skipped:   data.skipped || {},
                skippedInSession: {},
                startedAt: Date.now()
            };
            sessionStorage.setItem('procSession', JSON.stringify(session));
            // Navigate to first available order
            window.location.href = '<?= adminUrl('pages/order-view.php') ?>?id=' + data.queue[0] + '&proc_session=1';
        })
        .catch(() => {
            alert('Failed to load processing queue. Please try again.');
            if (btn) { btn.innerHTML = origBtnHtml; btn.disabled = false; }
        });
}

// ── Active users count (processing page only) ──────────────────────────────
<?php if (!empty($_isProcessingView)): ?>
(function pollActiveUsers() {
    const LOCK_API = '<?= SITE_URL ?>/api/order-lock.php';
    function fetchCount() {
        fetch(LOCK_API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=active_count'
        })
        .then(r => r.json())
        .then(d => {
            const chip = document.getElementById('activeUsersChip');
            const text = document.getElementById('activeUsersText');
            if (!chip || !text) return;
            if (d.success && d.count > 0) {
                const names = d.others && d.others.length ? d.others.join(', ') : d.count + ' user' + (d.count>1?'s':'');
                text.textContent = '👥 ' + names + ' active';
                chip.classList.remove('hidden');
            } else {
                chip.classList.add('hidden');
            }
        })
        .catch(() => {});
    }
    fetchCount();
    setInterval(fetchCount, 15000);
})();
<?php endif; ?>

// ── Column Toggle View System (saved per admin user via localStorage) ──
var _viewKey = 'om_col_view_<?= getAdminId() ?>';
function _getView() { try { return JSON.parse(localStorage.getItem(_viewKey)) || {}; } catch(e) { return {}; } }
function _applyView() {
    var v = _getView();
    var allCols = document.querySelectorAll('#viewDrop input[data-col]');
    allCols.forEach(function(cb) {
        var col = cb.dataset.col;
        var hidden = v[col] === false;
        cb.checked = !hidden;
        _setColVisible(col, !hidden);
    });
}
function _setColVisible(col, visible) {
    // Toggle th
    document.querySelectorAll('#ordersTable th[data-col="'+col+'"]').forEach(function(el) {
        el.classList.toggle('om-col-hide', !visible);
    });
    // Toggle td by index - find the th index first
    var ths = document.querySelectorAll('#ordersTable thead th');
    var idx = -1;
    ths.forEach(function(th, i) { if (th.dataset.col === col) idx = i; });
    if (idx >= 0) {
        document.querySelectorAll('#ordersTable tbody tr').forEach(function(tr) {
            var td = tr.children[idx];
            if (td) td.classList.toggle('om-col-hide', !visible);
        });
    }
}
function toggleCol(cb) {
    _setColVisible(cb.dataset.col, cb.checked);
}
function saveView() {
    var v = {};
    document.querySelectorAll('#viewDrop input[data-col]').forEach(function(cb) {
        if (!cb.checked) v[cb.dataset.col] = false;
    });
    localStorage.setItem(_viewKey, JSON.stringify(v));
    document.getElementById('viewDrop').classList.remove('show');
}
function resetView() {
    localStorage.removeItem(_viewKey);
    document.querySelectorAll('#viewDrop input[data-col]').forEach(function(cb) {
        cb.checked = true;
        _setColVisible(cb.dataset.col, true);
    });
    document.getElementById('viewDrop').classList.remove('show');
}
// Close view dropdown on outside click
document.addEventListener('click', function(e) {
    var w = document.getElementById('viewWrap');
    if (w && !w.contains(e.target)) document.getElementById('viewDrop').classList.remove('show');
});
// Apply saved view on page load
document.addEventListener('DOMContentLoaded', _applyView);
// Re-apply after AJAX refresh (new rows don't have the hidden class)
var _origOMFetch = OM._fetch;
OM._fetch = async function(params) {
    await _origOMFetch.call(OM, params);
    _applyView();
};

// ── Rate Popup: Refresh (rebuild from DB) & Fetch All (call courier APIs) ──
function rateRefresh(phone, btn) {
    if (!phone) return;
    var orig = btn.innerHTML; btn.innerHTML = '⏳...'; btn.disabled = true;
    fetch('<?= SITE_URL ?>/api/courier-stats.php', {
        method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'same-origin',
        body: JSON.stringify({action:'rebuild', phones:[phone]})
    }).then(r=>r.json()).then(d=> {
        btn.innerHTML = '✓ Done'; btn.style.color = '#16a34a';
        setTimeout(()=> OM.refresh(), 800);
    }).catch(()=> { btn.innerHTML = orig; btn.disabled = false; });
}
function rateFetchAll(phone, btn) {
    if (!phone) return;
    var orig = btn.innerHTML; btn.innerHTML = '⏳ Fetching...'; btn.disabled = true;
    // This calls courier-lookup.php which fetches from all courier APIs and writes to cache
    fetch('<?= adminUrl("api/courier-lookup.php") ?>?phone=' + encodeURIComponent(phone))
    .then(r=>r.json()).then(d=> {
        if (d.error) { btn.innerHTML = '❌ ' + d.error; setTimeout(()=>{btn.innerHTML=orig;btn.disabled=false;},2000); return; }
        btn.innerHTML = '✓ Fetched'; btn.style.color = '#16a34a';
        setTimeout(()=> OM.refresh(), 800);
    }).catch(()=> { btn.innerHTML = orig; btn.disabled = false; });
}
// Pin popup on click so buttons are usable
document.addEventListener('click', function(e) {
    var wrap = e.target.closest('.rate-wrap');
    // Close all other pinned popups
    document.querySelectorAll('.rate-wrap.pinned').forEach(function(el) { if (el !== wrap) el.classList.remove('pinned'); });
    if (wrap && !e.target.closest('button')) wrap.classList.toggle('pinned');
    else if (!e.target.closest('.rate-popup')) document.querySelectorAll('.rate-wrap.pinned').forEach(function(el) { el.classList.remove('pinned'); });
});

function syncCourier(){ recheckCourier(); }

async function recheckCourier(){
    const p=document.getElementById('cProg');
    p.classList.remove('hidden');
    document.getElementById('cProgB').style.width='10%';
    document.getElementById('cProgL').textContent='🔄 Fetching latest courier statuses from APIs...';
    document.getElementById('cErr').classList.add('hidden');
    
    try {
        const r = await fetch('<?= SITE_URL ?>/api/courier-stats.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            credentials:'same-origin',
            body:JSON.stringify({action:'recheck', limit:50})
        });
        const d = await r.json();
        
        document.getElementById('cProgB').style.width='100%';
        
        if (d.success !== false) {
            let msg = '✓ Checked ' + (d.total||0) + ' orders';
            if (d.synced) msg += ' · ' + d.synced + ' synced';
            if (d.updated) msg += ' · ' + d.updated + ' status updated';
            if (d.cache_rebuilt) msg += ' · ' + d.cache_rebuilt + ' customer stats refreshed';
            if (d.skipped) msg += ' · ' + d.skipped + ' skipped';
            if (d.errors) msg += ' · ' + d.errors + ' errors';
            document.getElementById('cProgL').textContent = msg;
            
            if (d.details && d.details.length) {
                const e = document.getElementById('cErr');
                e.classList.remove('hidden');
                e.style.color = d.errors ? '#dc2626' : '#16a34a';
                e.innerHTML = d.details.slice(0, 8).map(function(x){
                    var isErr = x.includes('Error') || x.includes('Rate limited');
                    return '<div style="font-size:10px;padding:2px 0;color:'+(isErr?'#dc2626':'#16a34a')+'">'+x+'</div>';
                }).join('');
            }
            
            document.getElementById('cProgB').style.background = d.errors ? '#f59e0b' : '#22c55e';
        } else {
            document.getElementById('cProgL').textContent = '❌ ' + (d.error || 'Recheck failed');
            document.getElementById('cProgB').style.background = '#ef4444';
        }
        
        setTimeout(function(){
            p.classList.add('hidden');
            document.getElementById('cProgB').style.background = '';
            document.getElementById('cProgB').style.width = '0%';
            OM.refresh();
        }, d.updated > 0 ? 3000 : 2000);
        
    } catch(e) {
        document.getElementById('cProgL').textContent = '❌ Network error: ' + e.message;
        document.getElementById('cProgB').style.width = '100%';
        document.getElementById('cProgB').style.background = '#ef4444';
        setTimeout(function(){ p.classList.add('hidden'); }, 3000);
    }
}

/* ─── Fraud Check Popup ─── */
function fcCheck(phone) {
    if (!phone) { phone = prompt('Enter phone number (01XXXXXXXXX):'); if (!phone) return; }
    phone = phone.replace(/\D/g, '');
    if (phone.length < 10) { alert('Invalid phone number'); return; }
    let m = document.getElementById('fcModal');
    if (!m) {
        m = document.createElement('div'); m.id = 'fcModal';
        m.innerHTML = `<div style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9998;display:flex;align-items:center;justify-content:center" onclick="if(event.target===this)this.parentElement.style.display='none'">
            <div style="background:#fff;border-radius:12px;max-width:700px;width:95%;max-height:85vh;overflow-y:auto;box-shadow:0 25px 50px rgba(0,0,0,.25)">
                <div style="padding:12px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
                    <div style="display:flex;align-items:center;gap:6px"><span style="font-size:16px">🔍</span><b style="font-size:13px">Customer Fraud Check</b></div>
                    <div style="display:flex;gap:6px;align-items:center">
                        <input id="fcPhone" type="tel" placeholder="01XXXXXXXXX" style="border:2px solid #e5e7eb;border-radius:6px;padding:4px 10px;width:140px;font-family:monospace;font-size:12px">
                        <button onclick="fcRun()" style="background:#3b82f6;color:#fff;border:none;border-radius:6px;padding:4px 12px;font-size:11px;cursor:pointer;font-weight:600">Check</button>
                        <button onclick="this.closest('#fcModal').style.display='none'" style="background:none;border:none;font-size:18px;cursor:pointer;color:#999">✕</button>
                    </div>
                </div>
                <div id="fcBody" style="padding:12px 16px"></div>
            </div></div>`;
        document.body.appendChild(m);
    }
    m.style.display = 'block';
    document.getElementById('fcPhone').value = phone;
    fcRun();
}
function fcRun() {
    const phone = document.getElementById('fcPhone').value.trim().replace(/\D/g, '');
    if (!phone || phone.length < 10) return;
    const body = document.getElementById('fcBody');
    body.innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af"><div style="font-size:20px;margin-bottom:6px">⏳</div>Checking...</div>';
    fetch('<?= SITE_URL ?>/api/fraud-checker.php?phone=' + encodeURIComponent(phone))
    .then(r => r.json()).then(j => {
        if (!j.success) { body.innerHTML = '<div style="padding:16px;color:#dc2626">❌ ' + (j.error||'Error') + '</div>'; return; }
        const p=j.pathao||{}, s=j.steadfast||{}, r=j.redx||{}, l=j.local||{}, co=j.combined||{};
        const risk=co.risk||'new';
        const riskBg={low:'#dcfce7',medium:'#fef9c3',high:'#fee2e2',new:'#dbeafe',blocked:'#fee2e2'}[risk]||'#f3f4f6';
        const riskTxt={low:'#166534',medium:'#854d0e',high:'#991b1b',new:'#1e40af',blocked:'#991b1b'}[risk]||'#374151';
        function apiCard(name, data, color) {
            if (data.error) return `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px;flex:1;min-width:120px"><div style="font-size:10px;font-weight:700;color:#374151;margin-bottom:2px">${name}</div><div style="font-size:10px;color:#ef4444">❌ ${data.error.substring(0,50)}</div></div>`;
            if (data.show_count===false && data.customer_rating) {
                const labels={excellent_customer:'⭐ Excellent',good_customer:'✅ Good',moderate_customer:'⚠️ Moderate',risky_customer:'🚫 Risky'};
                return `<div style="background:${color}11;border:1px solid ${color}33;border-radius:8px;padding:8px;flex:1;min-width:120px"><div style="font-size:10px;font-weight:700;color:#374151;margin-bottom:2px">${name}</div><div style="font-size:13px;font-weight:700;color:${color}">${labels[data.customer_rating]||data.customer_rating}</div></div>`;
            }
            const total=data.total||0, success=data.success||0, rate=total>0?Math.round(success/total*100):0;
            return `<div style="background:${color}11;border:1px solid ${color}33;border-radius:8px;padding:8px;flex:1;min-width:120px"><div style="font-size:10px;font-weight:700;color:#374151;margin-bottom:2px">${name}</div><div style="font-size:16px;font-weight:800;color:${color}">${rate}%</div><div style="font-size:10px;color:#6b7280">✅${success} ❌${(data.cancel||0)} (${total})</div></div>`;
        }
        body.innerHTML = `
        <div style="background:${riskBg};border-radius:8px;padding:10px 14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px">
            <div><span style="font-size:14px;font-weight:800;color:#1f2937">📱 ${j.phone}</span>
            ${l.total>0?`<span style="font-size:10px;color:#6b7280;margin-left:6px">${l.total} orders · ৳${Number(l.total_spent||0).toLocaleString()}</span>`:''}</div>
            <span style="background:${riskBg};color:${riskTxt};padding:3px 12px;border-radius:20px;font-size:11px;font-weight:800;border:2px solid ${riskTxt}33">${co.risk_label||'Unknown'}</span>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
            ${apiCard('Pathao',p,'#3b82f6')} ${apiCard('Steadfast',s,'#8b5cf6')} ${apiCard('RedX',r,'#ef4444')}
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px;flex:1;min-width:120px">
                <div style="font-size:10px;font-weight:700;color:#374151;margin-bottom:2px">Local DB</div>
                <div style="font-size:10px;color:#6b7280">✅${l.delivered||0} ❌${l.cancelled||0} 🔄${l.returned||0}</div>
            </div>
        </div>`;
    }).catch(e => { body.innerHTML = '<div style="padding:16px;color:#dc2626">❌ ' + e.message + '</div>'; });
}

// ── ORDER LOCK SYSTEM — poll for active locks and highlight rows ──
const LOCK_API = '<?= SITE_URL ?>/api/order-lock.php';
const CURRENT_ADMIN_ID = <?= intval(getAdminId()) ?>;

function pollOrderLocks() {
    const rows = document.querySelectorAll('tr[data-order-id]');
    if (!rows.length) return;
    const ids = Array.from(rows).map(r => r.dataset.orderId);
    
    fetch(LOCK_API, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=check_bulk&order_ids=' + ids.join(',')
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;
        const locks = data.locks || {};
        
        rows.forEach(row => {
            const oid = row.dataset.orderId;
            const lockInfo = locks[oid];
            const indicator = document.querySelector(`[data-lock-oid="${oid}"]`);
            const openLink = row.querySelector('.order-open-link');
            
            if (lockInfo && !lockInfo.is_mine) {
                // Locked by another user — subtle pink tint + avatar chip
                row.style.background = '#fff1f5';
                if (indicator) {
                    const initial = (lockInfo.locked_by || '?').charAt(0).toUpperCase();
                    indicator.innerHTML = '<span title="' + lockInfo.locked_by + ' is viewing" style="display:inline-flex;align-items:center;gap:3px;background:#fce7f3;border:1px solid #fbcfe8;color:#be185d;border-radius:20px;padding:1px 6px;font-size:10px;font-weight:600;white-space:nowrap">'
                        + '<span style="width:14px;height:14px;border-radius:50%;background:#be185d;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0">' + initial + '</span>'
                        + lockInfo.locked_by
                        + '</span>';
                    indicator.classList.remove('hidden');
                }
            } else {
                // Not locked or locked by me — normal
                row.style.background = '';
                if (indicator) { indicator.innerHTML = ''; indicator.classList.add('hidden'); }
            }
        });
    })
    .catch(() => {});
}

// Poll immediately and every 8 seconds
pollOrderLocks();
setInterval(pollOrderLocks, 8000);
</script>

<!-- ===== PRINT POPUP MODAL ===== -->
<?php
$selInv = getSetting('selected_invoice_template', 'inv_standard');
$selStk = getSetting('selected_sticker_template', 'stk_standard');
$custInvTpls = []; $custStkTpls = [];
try {
    $custInvTpls = $db->fetchAll("SELECT template_key, name FROM print_templates WHERE template_type='invoice' AND is_builtin=0 AND template_key NOT LIKE '\\_preview\\_%' ORDER BY name") ?: [];
    $custStkTpls = $db->fetchAll("SELECT template_key, name FROM print_templates WHERE template_type='sticker' AND is_builtin=0 AND template_key NOT LIKE '\\_preview\\_%' ORDER BY name") ?: [];
} catch(\Throwable $e){}
$defLayout = getSetting('print_default_layout', 'a4_1');
?>

<!-- ══════════════════════════════════════════
     ORDER EDIT MODAL
     ══════════════════════════════════════════ -->
<div id="editOrderModal" class="hidden fixed inset-0 z-[9998] flex items-center justify-center bg-black/60" onclick="if(event.target===this)closeEditModal()">
  <div class="bg-white rounded-2xl shadow-2xl w-[95vw] max-w-6xl h-[92vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-5 py-3 border-b bg-gray-50 shrink-0">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center text-lg">✏️</div>
        <div>
          <h3 class="font-bold text-gray-800 text-sm">Edit Order</h3>
          <p class="text-[11px] text-gray-400" id="editOrderNum">Loading…</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button onclick="openEditNewTab()" class="border px-3 py-1.5 rounded-lg text-xs text-gray-500 hover:bg-gray-50 flex items-center gap-1.5" title="Open in new tab">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>New Tab
        </button>
        <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 p-1">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>
    <div class="flex-1 relative overflow-hidden" style="min-height:0">
      <div id="editLoadingOverlay" class="absolute inset-0 flex items-center justify-center bg-white z-10">
        <div class="text-center"><div class="text-3xl mb-2">⏳</div><p class="text-sm text-gray-500">Loading order…</p></div>
      </div>
      <iframe id="editOrderIframe"
        style="display:block;width:100%;height:100%;border:0"
        onload="if(this.src&&this.src!=='about:blank'){document.getElementById('editLoadingOverlay').style.display='none';handleEditLoad(this);}"
        src="about:blank"></iframe>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════
     PICKING LIST MODAL
     ══════════════════════════════════════════ -->
<div id="pickingModal" class="hidden fixed inset-0 z-[9999] flex items-center justify-center bg-black/60" onclick="if(event.target===this)closePickingModal()">
  <div class="bg-white rounded-2xl shadow-2xl w-[95vw] max-w-4xl h-[90vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-5 py-3 border-b bg-green-50 shrink-0">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center text-lg">📦</div>
        <div>
          <h3 class="font-bold text-gray-800 text-sm">Picking List</h3>
          <p class="text-[11px] text-gray-400"><span id="pickingCount">0</span> order(s) · <span id="pickingItemCount">0</span> unique products</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button onclick="printPickingList()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print
        </button>
        <button onclick="closePickingModal()" class="text-gray-400 hover:text-gray-600 p-1">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>
    <div class="flex-1 overflow-auto p-4" style="min-height:0" id="pickingBody">
      <div id="pickingLoading" class="flex items-center justify-center h-full text-gray-400">
        <div class="text-center"><div class="text-3xl mb-2">⏳</div><p class="text-sm">Building picking list…</p></div>
      </div>
      <div id="pickingContent" class="hidden"></div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════
     INVOICE PRINT MODAL
     ══════════════════════════════════════════ -->
<div id="invPrintModal" class="hidden fixed inset-0 z-[9999] flex items-center justify-center bg-black/60" onclick="if(event.target===this)closeInvModal()">
  <div class="bg-white rounded-2xl shadow-2xl w-[95vw] max-w-5xl h-[90vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-5 py-3 border-b bg-blue-50 shrink-0">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center text-lg">📄</div>
        <div>
          <h3 class="font-bold text-gray-800 text-sm">Print Invoice</h3>
          <p class="text-[11px] text-gray-400"><span id="invPrintCount">0</span> order(s)</p>
        </div>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <select id="invTplSelect" onchange="reloadInvPreview()" class="border rounded-lg px-3 py-1.5 text-xs bg-white focus:ring-2 focus:ring-blue-300 min-w-[200px]">
          <optgroup label="📄 Invoice Templates">
            <option value="inv_standard" <?=($selInv==='inv_standard')?'selected':''?>>Standard Invoice</option>
            <option value="inv_compact"  <?=($selInv==='inv_compact')?'selected':''?>>Compact / Thermal</option>
            <option value="inv_modern"   <?=($selInv==='inv_modern')?'selected':''?>>Modern (Dark Header)</option>
            <option value="inv_branded"  <?=($selInv==='inv_branded')?'selected':''?>>Branded (Gradient)</option>
            <option value="inv_detailed" <?=($selInv==='inv_detailed')?'selected':''?>>Detailed (With Images)</option>
            <option value="inv_minimal"  <?=($selInv==='inv_minimal')?'selected':''?>>Minimal / Clean</option>
            <option value="inv_picking">Picking Sheet</option>
          </optgroup>
          <?php if (!empty($custInvTpls)): ?>
          <optgroup label="📄 Custom Invoices">
            <?php foreach ($custInvTpls as $ci): ?>
            <option value="<?=e($ci['template_key'])?>" <?=($selInv===$ci['template_key'])?'selected':''?>><?=e($ci['name'])?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
        <select id="invLayoutSelect" onchange="reloadInvPreview()" class="border rounded-lg px-2 py-1.5 text-xs bg-white focus:ring-2 focus:ring-blue-300">
          <option value="a4_1" <?=($defLayout==='a4_1')?'selected':''?>>A4 × 1</option>
          <option value="a3_2" <?=($defLayout==='a3_2')?'selected':''?>>A3 × 2</option>
          <option value="a4_3" <?=($defLayout==='a4_3')?'selected':''?>>A4 × 3</option>
        </select>
        <button onclick="doInvPrint()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print
        </button>
        <button onclick="openInvNewTab()" class="border px-2.5 py-1.5 rounded-lg text-xs text-gray-500 hover:bg-gray-50" title="Open in new tab">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
        </button>
        <button onclick="closeInvModal()" class="text-gray-400 hover:text-gray-600 p-1">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>
    <div class="flex-1 relative overflow-hidden bg-gray-100" style="min-height:0">
      <div id="invPrintLoading" class="absolute inset-0 flex items-center justify-center bg-white/80 z-10">
        <div class="text-center"><div class="text-2xl mb-2">⏳</div><p class="text-sm text-gray-500">Loading preview…</p></div>
      </div>
      <iframe id="invPrintIframe"
        style="display:block;width:100%;height:100%;border:0;min-height:0"
        onload="if(this.src&&this.src!=='about:blank'){document.getElementById('invPrintLoading').style.display='none';}"
        src="about:blank"></iframe>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════
     STICKER PRINT MODAL
     ══════════════════════════════════════════ -->
<div id="stkPrintModal" class="hidden fixed inset-0 z-[9999] flex items-center justify-center bg-black/60" onclick="if(event.target===this)closeStkModal()">
  <div class="bg-white rounded-2xl shadow-2xl w-[95vw] max-w-5xl h-[90vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
    <div class="flex items-center justify-between px-5 py-3 border-b bg-orange-50 shrink-0">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 bg-orange-100 rounded-lg flex items-center justify-center text-lg">🏷</div>
        <div>
          <h3 class="font-bold text-gray-800 text-sm">Print Sticker</h3>
          <p class="text-[11px] text-gray-400"><span id="stkPrintCount">0</span> order(s)</p>
        </div>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Sticker Size Selector — sizes must match your printer driver label settings -->
        <select id="stkSizeSelect" onchange="reloadStkPreview()" class="border border-orange-300 rounded-lg px-2 py-1.5 text-xs bg-white focus:ring-2 focus:ring-orange-300 font-semibold text-orange-700" title="MUST match Paper size in Chrome print dialog">
          <optgroup label="── 3 inch wide (most common) ──">
            <option value="272" data-w="72mm"  data-h="100mm" selected>72×100mm  ← 3×4 inch ★</option>
            <option value="272" data-w="72mm"  data-h="150mm">72×150mm  — 3×6 inch</option>
            <option value="272" data-w="72mm"  data-h="180mm">72×180mm  — 3×7 inch</option>
            <option value="288" data-w="76mm"  data-h="100mm">76×100mm  — 3×4 inch</option>
            <option value="288" data-w="76mm"  data-h="130mm">76×130mm  — 3×5 inch</option>
            <option value="288" data-w="76mm"  data-h="152mm">76×152mm  — 3×6 inch</option>
          </optgroup>
          <optgroup label="── 4 inch wide (shipping labels) ──">
            <option value="378" data-w="100mm" data-h="150mm">100×150mm — 4×6 inch</option>
            <option value="378" data-w="100mm" data-h="100mm">100×100mm — 4×4 inch</option>
          </optgroup>
          <optgroup label="── 75mm thermal ──">
            <option value="280" data-w="75mm"  data-h="50mm">75×50mm   — standard thermal</option>
            <option value="280" data-w="75mm"  data-h="40mm">75×40mm   — small thermal</option>
          </optgroup>
          <optgroup label="── 2 inch wide ──">
            <option value="192" data-w="50mm"  data-h="80mm">50×80mm   — 2×3 inch</option>
            <option value="192" data-w="50mm"  data-h="30mm">50×30mm   — 2×1 inch</option>
          </optgroup>
          <optgroup label="── Small labels ──">
            <option value="144" data-w="40mm"  data-h="30mm">40×30mm   — mini label</option>
            <option value="152" data-w="40mm"  data-h="60mm">40×60mm</option>
            <option value="208" data-w="55mm"  data-h="45mm">55×45mm</option>
          </optgroup>
          <optgroup label="── Square ──">
            <option value="272" data-w="72mm"  data-h="72mm">72×72mm   — 3×3 square</option>
            <option value="288" data-w="76mm"  data-h="76mm">76×76mm   — 3×3 square</option>
          </optgroup>
        </select>
        <span class="text-[10px] text-orange-600 font-semibold hidden sm:inline">↑ match Chrome Paper size</span>
        <select id="stkTplSelect" onchange="reloadStkPreview()" class="border rounded-lg px-3 py-1.5 text-xs bg-white focus:ring-2 focus:ring-orange-300 min-w-[200px]">
          <optgroup label="🏷 Sticker Templates">
            <option value="stk_standard"    <?=($selStk==='stk_standard')?'selected':''?>>Standard Sticker</option>
            <option value="stk_compact"     <?=($selStk==='stk_compact')?'selected':''?>>Sticker 1 (2 inch)</option>
            <option value="stk_detailed"    <?=($selStk==='stk_detailed')?'selected':''?>>Sticker 2 (Detailed)</option>
            <option value="stk_pos"         <?=($selStk==='stk_pos')?'selected':''?>>POS 80mm Receipt</option>
            <option value="stk_note"        <?=($selStk==='stk_note')?'selected':''?>>Sticker 4 (With Note)</option>
            <option value="stk_thermal"     <?=($selStk==='stk_thermal')?'selected':''?>>Sticker 7 (75mm Thermal)</option>
            <option value="stk_thermal_m"   <?=($selStk==='stk_thermal_m')?'selected':''?>>Sticker 8 (Multi-Page)</option>
            <option value="stk_thermal_sku" <?=($selStk==='stk_thermal_sku')?'selected':''?>>Sticker 9 (SKU Thermal)</option>
            <option value="stk_2in"         <?=($selStk==='stk_2in')?'selected':''?>>Sticker 10 (2 inch)</option>
            <option value="stk_3in"         <?=($selStk==='stk_3in')?'selected':''?>>Sticker 11 (3 inch)</option>
            <option value="stk_cod_t"       <?=($selStk==='stk_cod_t')?'selected':''?>>Sticker 12 (COD Thermal)</option>
            <option value="stk_courier"     <?=($selStk==='stk_courier')?'selected':''?>>Sticker 13 (Courier)</option>
            <option value="stk_4x3"         <?=($selStk==='stk_4x3')?'selected':''?>>Sticker 14 (4×3 SKU)</option>
            <option value="stk_3in_note"    <?=($selStk==='stk_3in_note')?'selected':''?>>Sticker 15 (3 inch + Note)</option>
            <option value="stk_3sq"         <?=($selStk==='stk_3sq')?'selected':''?>>Sticker 16 (3×3 Square)</option>
            <option value="stk_wide"        <?=($selStk==='stk_wide')?'selected':''?>>Sticker Wide (3.5 inch)</option>
            <option value="stk_sku"         <?=($selStk==='stk_sku')?'selected':''?>>Sticker SKU</option>
            <option value="stk_cod"         <?=($selStk==='stk_cod')?'selected':''?>>Sticker COD Badge</option>
            <option value="stk_mini"        <?=($selStk==='stk_mini')?'selected':''?>>Sticker 17 (38×25mm)</option>
          </optgroup>
          <?php if (!empty($custStkTpls)): ?>
          <optgroup label="🏷 Custom Stickers">
            <?php foreach ($custStkTpls as $cs): ?>
            <option value="<?=e($cs['template_key'])?>" <?=($selStk===$cs['template_key'])?'selected':''?>><?=e($cs['name'])?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
        <button onclick="doStkPrint()" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Print
        </button>
        <button onclick="openStkNewTab()" class="border px-2.5 py-1.5 rounded-lg text-xs text-gray-500 hover:bg-gray-50" title="Open in new tab">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
        </button>
        <button onclick="closeStkModal()" class="text-gray-400 hover:text-gray-600 p-1">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>
    <divid="stkPrintTip" class="shrink-0 px-4 py-2 bg-amber-50 border-b border-amber-200 text-xs text-amber-800 flex items-center gap-2 flex-wrap">
        <svg class="w-3.5 h-3.5 flex-shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
        <span><strong>Chrome print:</strong> Select label size above → Chrome auto-sets paper size · Margins = <strong>None</strong> · Scale = <strong>100%</strong> · Pages per sheet = <strong>1</strong></span>
      </div>
    <div class="flex-1 relative overflow-auto bg-gray-100 p-4" style="min-height:0" id="stkPreviewArea">
      <div id="stkPrintLoading" class="absolute inset-0 flex items-center justify-center bg-white/80 z-10">
        <div class="text-center"><div class="text-2xl mb-2">⏳</div><p class="text-sm text-gray-500">Loading preview…</p></div>
      </div>
      <div id="stkPreviewContent" class="flex flex-col gap-4 items-center"></div>
    </div>
  </div>
</div>

<script>
// ── Invoice modal ──────────────────────────────────────────────
var _invIds = [];
function openInvPrint(forceIds) {
    _invIds = forceIds || getIds();
    if (!_invIds.length) { alert('Select orders first'); return; }
    document.getElementById('actionsMenu')?.classList.add('hidden');
    document.getElementById('rowMenu')?.classList.add('hidden');
    document.getElementById('invPrintCount').textContent = _invIds.length;
    document.getElementById('invPrintLoading').style.display = 'flex';
    document.getElementById('invPrintModal').classList.remove('hidden');
    reloadInvPreview();
}
function closeInvModal() { document.getElementById('invPrintModal').classList.add('hidden'); document.getElementById('invPrintIframe').src = 'about:blank'; }

// ── Picking List ──────────────────────────────────────────────────────────────
var _pickIds = [];
function openPickingList(forceIds) {
    _pickIds = forceIds || getIds();
    if (!_pickIds.length) { alert('Select orders first'); return; }
    document.getElementById('actionsMenu')?.classList.add('hidden');
    document.getElementById('pickingCount').textContent = _pickIds.length;
    document.getElementById('pickingItemCount').textContent = '…';
    document.getElementById('pickingLoading').classList.remove('hidden');
    document.getElementById('pickingContent').classList.add('hidden');
    document.getElementById('pickingModal').classList.remove('hidden');
    // Fetch order items via API
    fetch('<?= adminUrl('api/picking-list.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({ids: _pickIds})
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('pickingLoading').classList.add('hidden');
        document.getElementById('pickingContent').classList.remove('hidden');
        renderPickingList(data);
    })
    .catch(() => {
        document.getElementById('pickingLoading').innerHTML = '<p class="text-red-500">Failed to load picking list</p>';
    });
}
function closePickingModal() {
    document.getElementById('pickingModal').classList.add('hidden');
    document.getElementById('pickingContent').innerHTML = '';
}
function renderPickingList(data) {
    const orders = data.orders || [];
    const items = data.items || []; // aggregated by product+variant
    document.getElementById('pickingItemCount').textContent = items.length;
    var html = '';
    // Summary table — aggregated products
    html += '<div class="mb-6">';
    html += '<h3 class="text-sm font-bold text-gray-700 mb-3 flex items-center gap-2"><span class="w-6 h-6 bg-green-600 text-white rounded text-xs flex items-center justify-center">1</span>Products to Pick — ' + items.length + ' items</h3>';
    html += '<table class="w-full border-collapse text-xs"><thead><tr class="bg-gray-100">';
    html += '<th class="border border-gray-200 px-3 py-2 text-left">Product</th>';
    html += '<th class="border border-gray-200 px-3 py-2 text-left">SKU</th>';
    html += '<th class="border border-gray-200 px-2 py-2 text-center w-16">Qty</th>';
    html += '<th class="border border-gray-200 px-2 py-2 text-center w-16">Orders</th>';
    html += '<th class="border border-gray-200 px-2 py-2 text-center w-16">✓ Picked</th>';
    html += '</tr></thead><tbody>';
    items.forEach(function(item, i) {
        var bg = i % 2 === 0 ? '' : 'background:#fafafa';
        html += '<tr style="' + bg + '">';
        html += '<td class="border border-gray-200 px-3 py-2 font-medium">' + escHtml(item.product_name) + (item.variant_name ? '<br><span class="text-gray-400 font-normal">' + escHtml(item.variant_name) + '</span>' : '') + '</td>';
        html += '<td class="border border-gray-200 px-3 py-2 text-gray-500 font-mono">' + escHtml(item.sku || '—') + '</td>';
        html += '<td class="border border-gray-200 px-2 py-2 text-center font-bold text-lg text-green-700">' + item.total_qty + '</td>';
        html += '<td class="border border-gray-200 px-2 py-2 text-center text-gray-500">' + item.order_count + '</td>';
        html += '<td class="border border-gray-200 px-2 py-2 text-center"><div style="width:22px;height:22px;border:2px solid #999;display:inline-block;border-radius:3px"></div></td>';
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    // Per-order breakdown
    html += '<div>';
    html += '<h3 class="text-sm font-bold text-gray-700 mb-3 flex items-center gap-2"><span class="w-6 h-6 bg-blue-600 text-white rounded text-xs flex items-center justify-center">2</span>Per Order Breakdown — ' + orders.length + ' orders</h3>';
    orders.forEach(function(order) {
        html += '<div class="mb-3 border border-gray-200 rounded-lg overflow-hidden">';
        html += '<div class="px-3 py-2 bg-gray-50 flex items-center justify-between">';
        html += '<span class="font-bold text-xs">#' + escHtml(order.order_number) + '</span>';
        html += '<span class="text-xs text-gray-500">' + escHtml(order.customer_name) + ' · ' + escHtml(order.customer_phone) + '</span>';
        html += '</div>';
        html += '<table class="w-full text-xs border-collapse"><thead><tr class="bg-white">';
        html += '<th class="border-t border-gray-100 px-3 py-1.5 text-left text-gray-500">Product</th><th class="border-t border-gray-100 px-2 py-1.5 text-center w-12 text-gray-500">Qty</th><th class="border-t border-gray-100 px-2 py-1.5 text-center w-16 text-gray-500">✓</th>';
        html += '</tr></thead><tbody>';
        (order.items || []).forEach(function(it) {
            html += '<tr><td class="border-t border-gray-100 px-3 py-1.5">' + escHtml(it.product_name) + (it.variant_name ? ' <span class="text-gray-400">(' + escHtml(it.variant_name) + ')</span>' : '') + '</td>';
            html += '<td class="border-t border-gray-100 px-2 py-1.5 text-center font-bold">' + it.quantity + '</td>';
            html += '<td class="border-t border-gray-100 px-2 py-1.5 text-center"><div style="width:18px;height:18px;border:2px solid #ccc;display:inline-block;border-radius:2px"></div></td></tr>';
        });
        html += '</tbody></table></div>';
    });
    html += '</div>';
    document.getElementById('pickingContent').innerHTML = html;
}
function escHtml(s) { var d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }

// ── Order Edit Modal ───────────────────────────────────────────────────────────
var _editOrderId = null;
var _editOrderUrl = null;

function openEditOrder(orderId, orderNum) {
    _editOrderId = orderId;
    _editOrderUrl = '<?= adminUrl('pages/order-view.php') ?>?order=' + encodeURIComponent(orderNum || orderId) + '&modal=1';
    document.getElementById('editOrderNum').textContent = orderNum ? '#' + orderNum : 'Order #' + orderId;
    document.getElementById('editLoadingOverlay').style.display = 'flex';
    document.getElementById('editOrderModal').classList.remove('hidden');
    document.getElementById('rowMenu')?.classList.add('hidden');
    document.getElementById('editOrderIframe').src = _editOrderUrl;
}
function closeEditModal() {
    document.getElementById('editOrderModal').classList.add('hidden');
    document.getElementById('editOrderIframe').src = 'about:blank';
    _editOrderId = null;
}
function openEditNewTab() {
    if (_editOrderUrl) window.open(_editOrderUrl.replace('&modal=1',''), '_blank');
}
function handleEditLoad(iframe) {
    // After save, order-view.php redirects to order-management.php?msg=updated
    // Detect this redirect and refresh the table instead
    try {
        var iUrl = iframe.contentWindow.location.href;
        if (iUrl.includes('order-management.php') || iUrl.includes('msg=updated') || iUrl.includes('msg=created') || iUrl.includes('msg=status_updated') || iUrl.includes('msg=confirmed')) {
            closeEditModal();
            OM.refresh();
            return;
        }
        // Update the order number in header if available
        var title = iframe.contentDocument?.title;
        if (title) document.getElementById('editOrderNum').textContent = title.replace('Order Management','').replace('|','').trim() || document.getElementById('editOrderNum').textContent;
    } catch(e) {}
}

// Listen for postMessage from modal iframe (primary close mechanism)
window.addEventListener('message', function(e) {
    if (e.data && e.data.type === 'orderSaved') {
        _orderModalSaved = true;
        closeEditModal();
        closeOrderModal();
        // If neither modal was open (edge case), still refresh
        OM.refresh();
    }
});

function printPickingList() {
    var content = document.getElementById('pickingContent').innerHTML;
    var w = window.open('', '_blank', 'width=900,height=700');
    w.document.write('<html><head><title>Picking List</title><style>body{font-family:Arial,sans-serif;font-size:12px;padding:20px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px 10px}th{background:#f0f0f0}h3{margin:16px 0 8px}@media print{h3:first-child{margin-top:0}}@page{margin:10mm}</style></head><body>');
    w.document.write('<h2 style="margin-bottom:16px">📦 Picking List — ' + _pickIds.length + ' Orders</h2>');
    w.document.write(content);
    w.document.write('</body></html>');
    w.document.close();
    w.focus();
    setTimeout(function(){ w.print(); }, 400);
}
function reloadInvPreview() {
    document.getElementById('invPrintLoading').style.display = 'flex';
    var tpl = document.getElementById('invTplSelect').value;
    var layout = document.getElementById('invLayoutSelect').value;
    document.getElementById('invPrintIframe').src = '<?= adminUrl('pages/order-print.php') ?>?ids=' + _invIds.join(',') + '&template=' + tpl + '&layout=' + layout + '&modal=1';
}
function doInvPrint() {
    document.getElementById('invPrintIframe').contentWindow.print();
    // Log print to print_queue
    _logPrintQueue(_invIds, 'invoice');
}
function openInvNewTab() {
    var tpl = document.getElementById('invTplSelect').value;
    var layout = document.getElementById('invLayoutSelect').value;
    window.open('<?= adminUrl('pages/order-print.php') ?>?ids=' + _invIds.join(',') + '&template=' + tpl + '&layout=' + layout, '_blank');
}

// ── Sticker modal ──────────────────────────────────────────────
var _stkIds = [];

function stkSizeParams() {
    var sel = document.getElementById('stkSizeSelect');
    var opt = sel ? sel.options[sel.selectedIndex] : null;
    var w   = opt ? opt.getAttribute('data-w') : '72mm';
    var h   = opt ? opt.getAttribute('data-h') : '100mm';
    var sw  = sel ? sel.value : '272';
    return '&sticker_width=' + sw + '&label_w=' + encodeURIComponent(w) + '&label_h=' + encodeURIComponent(h);
}

function openStkPrint(forceIds) {
    _stkIds = forceIds || getIds();
    if (!_stkIds.length) { alert('Select orders first'); return; }
    document.getElementById('actionsMenu')?.classList.add('hidden');
    document.getElementById('rowMenu')?.classList.add('hidden');
    document.getElementById('stkPrintCount').textContent = _stkIds.length;
    document.getElementById('stkPrintModal').classList.remove('hidden');
    reloadStkPreview();
}
function closeStkModal() {
    document.getElementById('stkPrintModal').classList.add('hidden');
    document.getElementById('stkPreviewContent').innerHTML = '';
}

function reloadStkPreview() {
    var loading = document.getElementById('stkPrintLoading');
    var content = document.getElementById('stkPreviewContent');
    var tpl = document.getElementById('stkTplSelect').value;
    var url = '<?= adminUrl('pages/order-print.php') ?>?ids=' + _stkIds.join(',') + '&template=' + tpl + stkSizeParams() + '&extract=1';
    loading.style.display = 'flex';
    content.innerHTML = '';
    fetch(url, {credentials: 'same-origin'})
        .then(function(r){ return r.text(); })
        .then(function(html){
            content.innerHTML = html;
            loading.style.display = 'none';
            // Re-run barcode rendering (script tags don't execute via innerHTML)
            if (typeof JsBarcode !== 'undefined') {
                content.querySelectorAll('svg[data-value]').forEach(function(svg){
                    var v = svg.getAttribute('data-value');
                    if (v) try { JsBarcode(svg, v, {format:'CODE128',width:1.2,height:32,displayValue:true,fontSize:9}); } catch(e){}
                });
            } else {
                var s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js';
                s.onload = function() {
                    content.querySelectorAll('svg[data-value]').forEach(function(svg){
                        var v = svg.getAttribute('data-value');
                        if (v) try { JsBarcode(svg, v, {format:'CODE128',width:1.2,height:32,displayValue:true,fontSize:9}); } catch(e){}
                    });
                };
                document.head.appendChild(s);
            }
        })
        .catch(function(){ loading.style.display = 'none'; });
}
function doStkPrint() {
    var tpl = document.getElementById('stkTplSelect').value;
    var url = '<?= adminUrl('pages/order-print.php') ?>?ids=' + _stkIds.join(',') + '&template=' + tpl + stkSizeParams();
    var w = window.open(url, '_blank');
    if (w) { w.addEventListener('load', function(){ w.print(); }); }
    // Log print to print_queue
    _logPrintQueue(_stkIds, 'sticker');
}
function openStkNewTab() {
    var tpl = document.getElementById('stkTplSelect').value;
    window.open('<?= adminUrl('pages/order-print.php') ?>?ids=' + _stkIds.join(',') + '&template=' + tpl + stkSizeParams(), '_blank');
}

// ── Legacy aliases (keep any existing calls working) ──────────────────────────
function openPrintPopup(forceIds, defaultTpl) {
    var ids = forceIds || getIds();
    if (!ids.length) { alert('Select orders first'); return; }
    if (defaultTpl && defaultTpl.startsWith('stk_')) openStkPrint(ids);
    else openInvPrint(ids);
}
// ── Print Queue Logging ──
function _logPrintQueue(ids, printType) {
    if (!ids || !ids.length) return;
    fetch(location.href.split('?')[0], {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=log_print&_ajax=1&print_type=' + encodeURIComponent(printType) + '&order_ids=' + encodeURIComponent(JSON.stringify(ids))
    }).catch(function(){});
}
function closePrintModal() { closeInvModal(); closeStkModal(); }
function bPrint(t) { var ids = getIds(); if (!ids.length) { alert('Select orders'); return; } if (t && t.startsWith('stk_')) openStkPrint(ids); else openInvPrint(ids); }

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeInvModal(); closeStkModal(); document.getElementById('prodPopup')&&(document.getElementById('prodPopup').style.display='none'); }
});

// ══════ Product Detail Popup (Fix #7) ══════
function showProductPopup(items, orderNum) {
    var m = document.getElementById('prodPopup');
    if (!m) {
        m = document.createElement('div'); m.id = 'prodPopup';
        m.style.cssText = 'position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center';
        m.onclick = function(e){ if(e.target===m) m.style.display='none'; };
        m.innerHTML = '<div style="background:#fff;border-radius:16px;max-width:480px;width:92%;max-height:80vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.2)" onclick="event.stopPropagation()"><div style="padding:14px 20px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center"><h3 id="prodPopupTitle" style="font-weight:700;font-size:14px;color:#1e293b">Products</h3><button onclick="this.closest(\'#prodPopup\').style.display=\'none\'" style="background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;line-height:1">&times;</button></div><div id="prodPopupBody" style="padding:12px 20px"></div></div>';
        document.body.appendChild(m);
    }
    document.getElementById('prodPopupTitle').textContent = 'Products — Invoice #' + orderNum;
    var body = document.getElementById('prodPopupBody');
    var html = '';
    items.forEach(function(p){
        var img = p.image ? '<img src="'+p.image+'" style="width:56px;height:56px;border-radius:8px;object-fit:cover;border:1px solid #e2e8f0;flex-shrink:0">' : '<div style="width:56px;height:56px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0">📦</div>';
        html += '<div style="display:flex;gap:12px;padding:12px 0;border-bottom:1px solid #f8fafc">';
        html += img;
        html += '<div style="flex:1;min-width:0">';
        html += '<p style="font-weight:600;font-size:13px;color:#1e293b;margin:0 0 2px">' + (p.name||'—') + '</p>';
        if (p.variant) html += '<p style="font-size:11px;color:#64748b;margin:0 0 2px">' + p.variant + '</p>';
        html += '<p style="font-size:11px;color:#94a3b8;margin:0">';
        if (p.sku) html += 'SKU: ' + p.sku + ' · ';
        html += 'Quantity: <strong style="color:#334155">' + p.qty + '</strong></p>';
        if (p.price > 0) html += '<p style="font-size:12px;font-weight:700;color:#0f766e;margin:4px 0 0">৳' + Number(p.price).toLocaleString() + '</p>';
        html += '</div></div>';
    });
    if (!items.length) html = '<p style="text-align:center;color:#94a3b8;padding:20px 0">No products</p>';
    body.innerHTML = html;
    m.style.display = 'flex';
}
</script>

<!-- ══════ Toast Notification System (Fix #1) ══════ -->
<style>
#kbToast{position:fixed;top:-60px;left:50%;transform:translateX(-50%);z-index:99999;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:0 8px 30px rgba(0,0,0,.15);transition:top .35s cubic-bezier(.22,1,.36,1);max-width:90%;white-space:nowrap}
#kbToast.show{top:16px}
#kbToast.success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
#kbToast.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
#kbToast.warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
#kbToast.info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
</style>
<div id="kbToast"></div>
<script>
function kbToast(msg, type, duration) {
    type = type || 'info'; duration = duration || 4000;
    var t = document.getElementById('kbToast');
    t.className = type; t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.classList.remove('show'); }, duration);
}
// Show flash messages from PHP as toast
<?php if (!empty($_GET['msg'])): ?>
kbToast('<?= addslashes(e($_GET['msg'] ?? '')) ?>', 'success');
<?php endif; ?>
</script>

<!-- ══════ Bulk Status Update Modal ══════ -->
<div id="bulkStatusModal" class="fixed inset-0 z-[9998] hidden bg-black/50 flex items-center justify-center" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="bg-white rounded-2xl shadow-2xl w-[95vw] max-w-md overflow-hidden" onclick="event.stopPropagation()">
        <div class="px-5 py-4 border-b bg-indigo-50">
            <h3 class="font-bold text-gray-800 text-sm">🔄 Bulk Update Status</h3>
            <p class="text-[10px] text-gray-500 mt-0.5">Change <span id="bsCount" class="font-bold text-indigo-600">0</span> selected orders to any status</p>
        </div>
        <div class="p-5">
            <div class="grid grid-cols-2 gap-2 mb-4">
                <?php
                $allBulkStatuses = [
                    'processing' => ['⚙️', 'Processing', 'bg-yellow-50 border-yellow-200 hover:bg-yellow-100'],
                    'confirmed' => ['✅', 'Confirmed', 'bg-blue-50 border-blue-200 hover:bg-blue-100'],
                    'ready_to_ship' => ['📦', 'Ready to Ship', 'bg-violet-50 border-violet-200 hover:bg-violet-100'],
                    'shipped' => ['🚚', 'Shipped', 'bg-purple-50 border-purple-200 hover:bg-purple-100'],
                    'delivered' => ['📬', 'Delivered', 'bg-green-50 border-green-200 hover:bg-green-100'],
                    'on_hold' => ['⏸️', 'On Hold', 'bg-gray-50 border-gray-200 hover:bg-gray-100'],
                    'no_response' => ['📵', 'No Response', 'bg-rose-50 border-rose-200 hover:bg-rose-100'],
                    'good_but_no_response' => ['📱', 'Good No Resp', 'bg-teal-50 border-teal-200 hover:bg-teal-100'],
                    'advance_payment' => ['💰', 'Advance Payment', 'bg-emerald-50 border-emerald-200 hover:bg-emerald-100'],
                    'pending_return' => ['🔄', 'Pending Return', 'bg-amber-50 border-amber-200 hover:bg-amber-100'],
                    'returned' => ['↩️', 'Returned', 'bg-orange-50 border-orange-200 hover:bg-orange-100'],
                    'cancelled' => ['✗', 'Cancelled', 'bg-red-50 border-red-200 hover:bg-red-100'],
                    'pending_cancel' => ['⏳', 'Pending Cancel', 'bg-pink-50 border-pink-200 hover:bg-pink-100'],
                    'partial_delivered' => ['📦½', 'Partial Delivered', 'bg-cyan-50 border-cyan-200 hover:bg-cyan-100'],
                    'lost' => ['❌', 'Lost', 'bg-stone-50 border-stone-200 hover:bg-stone-100'],
                ];
                foreach ($allBulkStatuses as $sk => $sv):
                ?>
                <button type="button" onclick="confirmBulkStatus('<?= $sk ?>','<?= $sv[1] ?>')"
                    class="flex items-center gap-2 px-3 py-2.5 rounded-lg border text-xs font-medium text-gray-700 transition <?= $sv[2] ?>">
                    <span class="text-sm"><?= $sv[0] ?></span> <?= $sv[1] ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="px-5 py-3 border-t bg-gray-50 flex justify-end">
            <button type="button" onclick="document.getElementById('bulkStatusModal').classList.add('hidden')" class="px-4 py-1.5 text-xs text-gray-500 hover:text-gray-700">Cancel</button>
        </div>
    </div>
</div>

<script>
function openBulkStatusModal() {
    var ids = getIds();
    if (!ids.length) { alert('Select at least one order first.'); return; }
    document.getElementById('bsCount').textContent = ids.length;
    document.getElementById('actionsMenu').classList.add('hidden');
    document.getElementById('bulkStatusModal').classList.remove('hidden');
}
function confirmBulkStatus(status, label) {
    var ids = getIds();
    if (!ids.length) return;
    document.getElementById('bulkStatusModal').classList.add('hidden');
    bStatus(status);
}

var _orderModalSaved = false;
function closeOrderModal() {
    var m = document.getElementById('orderEditModal');
    if (m.classList.contains('hidden')) return;
    m.classList.add('hidden');
    document.getElementById('orderEditFrame').src = 'about:blank';
    if (_orderModalSaved) { OM.refresh(); _orderModalSaved = false; }
}
function openOrderModal(url, e) {
    if (e) e.preventDefault();
    _orderModalSaved = false;
    var modal = document.getElementById('orderEditModal');
    var iframe = document.getElementById('orderEditFrame');
    iframe.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'modal=1';
    modal.classList.remove('hidden');
    return false;
}
function handleOrderFrameLoad(iframe) {
    if (!iframe.src || iframe.src === 'about:blank') return;
    try {
        var iUrl = iframe.contentWindow.location.href;
        if (iUrl.includes('order-management.php') || iUrl.includes('msg=updated') || iUrl.includes('msg=confirmed') || iUrl.includes('msg=status_updated')) {
            _orderModalSaved = true;
            closeOrderModal();
        }
    } catch(e) {}
}
</script>

<!-- Order Edit Modal (iframe) -->
<div id="orderEditModal" class="fixed inset-0 z-[9997] hidden bg-black/40 flex items-center justify-center p-4" onclick="if(event.target===this)closeOrderModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl h-[90vh] flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3 border-b bg-gray-50">
            <h3 class="text-sm font-bold text-gray-800">📝 Order Details</h3>
            <button onclick="closeOrderModal()" class="w-8 h-8 rounded-full bg-gray-200 hover:bg-gray-300 flex items-center justify-center text-gray-600 text-sm font-bold">✕</button>
        </div>
        <iframe id="orderEditFrame" src="about:blank" class="flex-1 w-full border-0" onload="handleOrderFrameLoad(this)"></iframe>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
