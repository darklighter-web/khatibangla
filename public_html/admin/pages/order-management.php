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

// ── POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'update_status') {
        $orderId = intval($_POST['order_id']);
        $newStatus = sanitize($_POST['status']);
        $db->update('orders', ['order_status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$orderId]);
        try { $db->insert('order_status_history', ['order_id' => $orderId, 'status' => $newStatus, 'changed_by' => getAdminId(), 'note' => sanitize($_POST['notes'] ?? '')]); } catch (Exception $e) {}
        if ($newStatus === 'delivered') { try { awardOrderCredits($orderId); } catch (\Throwable $e) {} }
        if (in_array($newStatus, ['cancelled', 'returned'])) { try { refundOrderCreditsOnCancel($orderId); } catch (\Throwable $e) {} }
        if ($newStatus === 'delivered') { try { $db->update('orders', ['delivered_at' => date('Y-m-d H:i:s')], 'id = ? AND delivered_at IS NULL', [$orderId]); } catch (\Throwable $e) {} }
        try { $db->query("DELETE FROM order_locks WHERE order_id = ?", [$orderId]); } catch (\Throwable $e) {}
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['_ajax'])) {
            header('Content-Type: application/json'); echo json_encode(['success'=>true]); exit;
        }
        redirect(adminUrl('pages/order-management.php?' . http_build_query(array_diff_key($_GET, ['msg'=>''])) . '&msg=updated'));
    }
    
    if ($action === 'bulk_status') {
        $ids = $_POST['order_ids'] ?? []; $status = sanitize($_POST['bulk_status']);
        foreach ($ids as $id) {
            $db->update('orders', ['order_status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [intval($id)]);
            try { $db->insert('order_status_history', ['order_id' => intval($id), 'status' => $status, 'changed_by' => getAdminId()]); } catch (Exception $e) {}
            if ($status === 'delivered') { try { awardOrderCredits(intval($id)); } catch (\Throwable $e) {} try { $db->update('orders', ['delivered_at' => date('Y-m-d H:i:s')], 'id = ? AND delivered_at IS NULL', [intval($id)]); } catch (\Throwable $e) {} }
            if (in_array($status, ['cancelled', 'returned'])) { try { refundOrderCreditsOnCancel(intval($id)); } catch (\Throwable $e) {} }
            try { $db->query("DELETE FROM order_locks WHERE order_id = ?", [intval($id)]); } catch (\Throwable $e) {}
        }
        // Return JSON for AJAX requests, redirect for form submits
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['_ajax'])) {
            header('Content-Type: application/json'); echo json_encode(['success'=>true,'count'=>count($ids)]); exit;
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
try { $db->query("ALTER TABLE orders MODIFY COLUMN order_status ENUM('pending','processing','confirmed','shipped','delivered','cancelled','returned','on_hold','no_response','good_but_no_response','advance_payment','incomplete','pending_return','pending_cancel','partial_delivered','lost') DEFAULT 'processing'"); } catch(\Throwable $e) {}
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
// Main flow: Processing → Confirmed → Shipped → Delivered
// Courier-driven (auto-updated by webhooks): pending_return, pending_cancel, partial_delivered, lost
// Manual side statuses: cancelled, returned, on_hold, no_response, good_but_no_response, advance_payment
$mainFlow = ['processing', 'confirmed', 'shipped', 'delivered'];
$courierStatuses = ['pending_return', 'pending_cancel', 'partial_delivered', 'lost'];
$sideStatuses = ['cancelled', 'returned', 'on_hold', 'no_response', 'good_but_no_response', 'advance_payment'];
$allStatuses = array_merge($mainFlow, $courierStatuses, $sideStatuses);

// Next logical action for each status
$nextAction = [
    'processing' => ['status' => 'confirmed', 'label' => 'Confirm', 'icon' => '✅', 'color' => 'blue'],
    'confirmed'  => ['status' => 'shipped',   'label' => 'Ship',    'icon' => '🚚', 'color' => 'purple'],
    'shipped'    => ['status' => 'delivered',  'label' => 'Deliver', 'icon' => '📦', 'color' => 'green'],
    'pending_return' => ['status' => 'returned', 'label' => 'Confirm Return', 'icon' => '↩', 'color' => 'orange'],
    'pending_cancel' => ['status' => 'cancelled', 'label' => 'Confirm Cancel', 'icon' => '✗', 'color' => 'red'],
    'partial_delivered' => ['status' => 'delivered', 'label' => 'Mark Delivered', 'icon' => '📦', 'color' => 'green'],
];

// Status counts
$statusCounts = [];
foreach ($allStatuses as $s) { try { $statusCounts[$s] = $db->count('orders', 'order_status = ?', [$s]); } catch(Exception $e) { $statusCounts[$s] = 0; } }
// Include legacy pending in processing count
try { $pendingCount = $db->count('orders', "order_status = 'pending'"); $statusCounts['processing'] += $pendingCount; } catch(\Throwable $e) {}
$totalOrders = array_sum($statusCounts);

// ── Filters ──
$status=$_GET['status']??''; $search=$_GET['search']??''; $dateFrom=$_GET['date_from']??''; $dateTo=$_GET['date_to']??'';
$channel=$_GET['channel']??''; $courier=$_GET['courier']??''; $assignedTo=$_GET['assigned']??''; $preorderFilter=$_GET['preorder']??'';
$tagFilter=$_GET['tag']??''; $customerFilter=$_GET['customer']??'';
$page = max(1, intval($_GET['page'] ?? 1));
$allowedLimits = [20,50,100,200,500,1000,5000];
$limit = in_array((int)($_GET['per_page']??0), $allowedLimits) ? (int)$_GET['per_page'] : (defined('ADMIN_ITEMS_PER_PAGE') ? ADMIN_ITEMS_PER_PAGE : 20);

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
    if ($courier === 'Unassigned') {
        $where .= " AND (o.courier_name IS NULL OR o.courier_name = '' OR o.courier_name = 'Personal Delivery' OR o.courier_name = 'Unassigned')";
    } else {
        $where .= " AND o.courier_name LIKE ?"; $params[] = '%' . $courier . '%';
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

$orders = $db->fetchAll("SELECT o.*, au.full_name as assigned_name FROM orders o LEFT JOIN admin_users au ON au.id = o.assigned_to WHERE {$where} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}", $params);

// Pre-fetch customer success rates
$successRates=[]; $previousOrders=[];
$phones = array_unique(array_filter(array_column($orders, 'customer_phone')));
foreach ($phones as $phone) {
    $pl = '%'.substr(preg_replace('/[^0-9]/','',$phone),-10).'%';
    try {
        $sr = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(total) as total_spent FROM orders WHERE customer_phone LIKE ?", [$pl]);
        $t=intval($sr['total']); $d=intval($sr['delivered']); $c=intval($sr['cancelled']);
        $rate=$t>0?round(($d/$t)*100):0;
        $successRates[$phone]=['total'=>$t,'delivered'=>$d,'cancelled'=>$c,'rate'=>$rate,'total_spent'=>$sr['total_spent']??0];
        $previousOrders[$phone] = $db->fetchAll("SELECT id, order_number, order_status, total, created_at FROM orders WHERE customer_phone LIKE ? ORDER BY created_at DESC LIMIT 5", [$pl]);
    } catch(Exception $e) { $successRates[$phone]=['total'=>0,'delivered'=>0,'cancelled'=>0,'rate'=>0,'total_spent'=>0]; $previousOrders[$phone]=[]; }
}

// Pre-fetch items + tags
$orderIds = array_column($orders, 'id'); $orderItems=[]; $orderTags=[];
if (!empty($orderIds)) {
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $items = $db->fetchAll("SELECT oi.order_id, oi.product_name, oi.quantity, oi.price, oi.variant_name, p.featured_image FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id IN ({$ph})", $orderIds);
    foreach ($items as $item) { $orderItems[$item['order_id']][] = $item; }
    try { $tags = $db->fetchAll("SELECT order_id, tag_name FROM order_tags WHERE order_id IN ({$ph})", $orderIds);
        foreach ($tags as $t) { $orderTags[$t['order_id']][] = $t; }
    } catch(Exception $e) {}
}

// ── Courier counts (for sub-tabs under each status) ──
$courierList = ['Pathao', 'Steadfast', 'CarryBee', 'Unassigned'];
$courierCounts = [];
$_cwStatusWhere = '1=1';
if ($status === 'processing') { $_cwStatusWhere = "o.order_status IN ('processing','pending')"; }
elseif ($status) { $_cwStatusWhere = "o.order_status = '" . preg_replace('/[^a-z_]/','',$status) . "'"; }

foreach ($courierList as $cn) {
    if ($cn === 'Unassigned') {
        try { $courierCounts[$cn] = (int)($db->fetch("SELECT COUNT(*) as cnt FROM orders o WHERE {$_cwStatusWhere} AND (o.courier_name IS NULL OR o.courier_name = '' OR o.courier_name = 'Personal Delivery' OR o.courier_name = 'Unassigned')")['cnt'] ?? 0); } catch(\Throwable $e) { $courierCounts[$cn] = 0; }
    } else {
        try { $courierCounts[$cn] = (int)($db->fetch("SELECT COUNT(*) as cnt FROM orders o WHERE {$_cwStatusWhere} AND o.courier_name LIKE ?", ['%'.$cn.'%'])['cnt'] ?? 0); } catch(\Throwable $e) { $courierCounts[$cn] = 0; }
    }
}

$defaultCourier = getSetting('default_courier', 'pathao');
$adminUsers = $db->fetchAll("SELECT id, full_name FROM admin_users WHERE is_active = 1 ORDER BY full_name");
$incompleteCount = 0;
try { $incompleteCount = $db->fetch("SELECT COUNT(*) as cnt FROM incomplete_orders WHERE recovered = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")['cnt']; } catch(Exception $e){}

// Today's summary
$todaySummary = $db->fetch("SELECT COUNT(*) as total, SUM(total) as revenue, SUM(CASE WHEN order_status IN ('processing','pending') THEN 1 ELSE 0 END) as processing, SUM(CASE WHEN order_status='confirmed' THEN 1 ELSE 0 END) as confirmed, SUM(CASE WHEN order_status='shipped' THEN 1 ELSE 0 END) as shipped, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN order_status='pending_return' THEN 1 ELSE 0 END) as pending_return, SUM(CASE WHEN order_status='pending_cancel' THEN 1 ELSE 0 END) as pending_cancel FROM orders WHERE DATE(created_at) = CURDATE()");

// Tab config
$tabConfig = [
    'processing' => ['icon'=>'⚙','color'=>'yellow','label'=>'PROCESSING'],
    'confirmed'  => ['icon'=>'✅','color'=>'blue','label'=>'CONFIRMED'],
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
        $sr        = $successRates[$order['customer_phone']] ?? ['total'=>0,'delivered'=>0,'rate'=>0,'cancelled'=>0,'total_spent'=>0];
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
        echo '<tr><td colspan="13" style="text-align:center;padding:40px 20px;color:#94a3b8"><div style="font-size:28px;margin-bottom:8px">📦</div>No orders found</td></tr>';
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
.om-table th,.om-table td{padding:6px 10px;white-space:nowrap;vertical-align:top;border-bottom:1px solid #f0f0f0;font-size:12px}
.om-table th{background:#f8f9fb;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.3px;font-size:10px;position:sticky;top:0;z-index:2;border-bottom:2px solid #e2e8f0;user-select:none}
.om-table th a{color:inherit;text-decoration:none}
.om-table tr:hover{background:#f8fafc}
.om-table .cust-name{font-weight:600;color:#1e293b;font-size:12px}
.om-table .cust-phone{font-size:11px;color:#64748b;font-family:monospace}
.om-table .cust-addr{font-size:10px;color:#94a3b8}
.om-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;background:#fff}
.rate-badge{display:inline-block;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px}
.tag-badge{display:inline-block;font-size:10px;font-weight:600;padding:2px 8px;border-radius:12px;white-space:nowrap}
.dot-menu{width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;cursor:pointer;color:#94a3b8;font-size:14px;line-height:1}
.dot-menu:hover{background:#f1f5f9;color:#475569}
.prod-thumb{width:28px;height:28px;border-radius:4px;object-fit:cover;border:1px solid #e5e7eb;flex-shrink:0}
.status-dot{width:7px;height:7px;border-radius:50%;display:inline-block;margin-right:3px}
</style>

<?php if (isset($_GET['msg'])): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm">✓ <?= $_GET['msg'] === 'updated' ? 'Status updated.' : ($_GET['msg'] === 'bulk_updated' ? 'Bulk update completed.' : 'Action completed.') ?></div><?php endif; ?>

<?php if (empty($_isProcessingView)): ?>
<!-- Summary Cards -->
<div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-7 gap-2 mb-3">
    <div class="bg-white rounded-lg border p-2.5 text-center">
        <p class="text-xl font-bold text-gray-800"><?= intval($todaySummary['total'] ?? 0) ?></p>
        <p class="text-[9px] text-gray-400 uppercase tracking-wider">Today</p>
    </div>
    <div class="bg-yellow-50 rounded-lg border border-yellow-200 p-2.5 text-center cursor-pointer hover:shadow-sm" onclick="location='?status=processing'">
        <p class="text-xl font-bold text-yellow-600"><?= $statusCounts['processing'] ?></p>
        <p class="text-[9px] text-yellow-600 uppercase tracking-wider">Processing</p>
    </div>
    <div class="bg-blue-50 rounded-lg border border-blue-200 p-2.5 text-center cursor-pointer hover:shadow-sm" onclick="location='?status=confirmed'">
        <p class="text-xl font-bold text-blue-600"><?= $statusCounts['confirmed'] ?></p>
        <p class="text-[9px] text-blue-600 uppercase tracking-wider">Confirmed</p>
    </div>
    <?php if (($statusCounts['ready_to_ship'] ?? 0) > 0): ?>
    <div class="bg-violet-50 rounded-lg border border-violet-200 p-2.5 text-center cursor-pointer hover:shadow-sm" onclick="location='?status=ready_to_ship'">
        <p class="text-xl font-bold text-violet-600"><?= $statusCounts['ready_to_ship'] ?? 0 ?></p>
        <p class="text-[9px] text-violet-600 uppercase tracking-wider">RTS</p>
    </div>
    <?php endif; ?>
    <div class="bg-purple-50 rounded-lg border border-purple-200 p-2.5 text-center cursor-pointer hover:shadow-sm" onclick="location='?status=shipped'">
        <p class="text-xl font-bold text-purple-600"><?= $statusCounts['shipped'] ?></p>
        <p class="text-[9px] text-purple-600 uppercase tracking-wider">Shipped</p>
    </div>
    <div class="bg-green-50 rounded-lg border border-green-200 p-2.5 text-center cursor-pointer hover:shadow-sm" onclick="location='?status=delivered'">
        <p class="text-xl font-bold text-green-600"><?= $statusCounts['delivered'] ?></p>
        <p class="text-[9px] text-green-600 uppercase tracking-wider">Delivered</p>
    </div>
    <div class="bg-white rounded-lg border p-2.5 text-center">
        <p class="text-xl font-bold text-gray-800">৳<?= number_format($todaySummary['revenue'] ?? 0) ?></p>
        <p class="text-[9px] text-gray-400 uppercase tracking-wider">Revenue</p>
    </div>
</div>
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

<!-- Courier Sub-Tabs (hidden on processing/all tabs) -->
<?php if ($status && $status !== 'processing'): ?>
<div class="bg-white rounded-lg border mb-3 overflow-hidden om-courier-bar">
    <div class="overflow-x-auto">
        <div class="flex items-center min-w-max gap-1 px-3 py-2">
            <?php
            $courierIcons = [
                'Pathao'           => ['icon' => 'fas fa-motorcycle', 'color' => 'teal',   'bg' => 'bg-teal-50',   'border' => 'border-teal-500',   'text' => 'text-teal-700'],
                'Steadfast'        => ['icon' => 'fas fa-truck',       'color' => 'blue',   'bg' => 'bg-blue-50',   'border' => 'border-blue-500',   'text' => 'text-blue-700'],
                'CarryBee'         => ['icon' => 'fas fa-box',         'color' => 'amber',  'bg' => 'bg-amber-50',  'border' => 'border-amber-500',  'text' => 'text-amber-700'],
                'Unassigned'       => ['icon' => 'fas fa-question-circle','color'=>'gray',  'bg' => 'bg-gray-100',  'border' => 'border-gray-400',   'text' => 'text-gray-600'],
            ];
            foreach ($courierList as $cn):
                $ci = $courierIcons[$cn];
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
<?php endif; ?>
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
      <?php foreach([20,50,100,200,500,1000,5000] as $pp): ?>
      <option value="<?=$pp?>" <?=$limit==$pp?'selected':''?>><?=$pp?>/page</option>
      <?php endforeach; ?>
    </select>
    <button type="button" onclick="fcCheck('')" class="border border-blue-200 text-blue-600 px-2.5 py-1.5 rounded text-xs hover:bg-blue-50 font-medium">🔍 Check</button>
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
    <table class="om-table w-full" id="ordersTable">
        <thead>
            <tr>
                <th style="width:30px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
                <th><a href="#" onclick="event.preventDefault();OM.goSort('created_at')" style="cursor:pointer">Date <?= sortIcon('created_at') ?></a></th>
                <th><a href="#" onclick="event.preventDefault();OM.goSort('order_number')" style="cursor:pointer">Invoice <?= sortIcon('order_number') ?></a></th>
                <th>Customer</th>
                <th>Note</th>
                <th>Products</th>
                <th>Tags</th>
                <th><a href="#" onclick="event.preventDefault();OM.goSort('total')" style="cursor:pointer">Total <?= sortIcon('total') ?></a></th>
                <th>Upload</th>
                <th>User</th>
                <th><a href="#" onclick="event.preventDefault();OM.goSort('channel')" style="cursor:pointer">Source <?= sortIcon('channel') ?></a></th>
                <th>Shipping Note</th>
                <th style="width:40px">Actions</th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($orders as $order):
    $sr=$successRates[$order['customer_phone']]??['total'=>0,'delivered'=>0,'rate'=>0,'cancelled'=>0,'total_spent'=>0];
    $prevO=$previousOrders[$order['customer_phone']]??[];
    $oItems=$orderItems[$order['id']]??[]; $tags=$orderTags[$order['id']]??[];
    $rC=$sr['rate']>=80?'text-green-600':($sr['rate']>=50?'text-yellow-600':'text-red-500');
    $ph=preg_replace('/[^0-9]/','',$order['customer_phone']);
    $oStatus = $order['order_status'] === 'pending' ? 'processing' : $order['order_status'];
    $nxt = $nextAction[$oStatus] ?? null;
    $creditUsed = floatval($order['store_credit_used'] ?? 0);
    
    // Status color mapping
    $statusColors = [
        'processing'=>'bg-yellow-100 text-yellow-700','confirmed'=>'bg-blue-100 text-blue-700',
        'ready_to_ship'=>'bg-violet-100 text-violet-700','shipped'=>'bg-purple-100 text-purple-700',
        'delivered'=>'bg-green-100 text-green-700','cancelled'=>'bg-red-100 text-red-700',
        'returned'=>'bg-orange-100 text-orange-700','pending_return'=>'bg-amber-100 text-amber-700',
        'pending_cancel'=>'bg-pink-100 text-pink-700','partial_delivered'=>'bg-cyan-100 text-cyan-700',
        'on_hold'=>'bg-gray-100 text-gray-700','no_response'=>'bg-rose-100 text-rose-700',
        'good_but_no_response'=>'bg-teal-100 text-teal-700','advance_payment'=>'bg-emerald-100 text-emerald-700',
    ];
    $statusDots = [
        'processing'=>'#eab308','confirmed'=>'#3b82f6','ready_to_ship'=>'#8b5cf6',
        'shipped'=>'#9333ea','delivered'=>'#22c55e','cancelled'=>'#ef4444',
        'returned'=>'#f97316','pending_return'=>'#f59e0b','on_hold'=>'#6b7280',
    ];
    $sDot = $statusDots[$oStatus] ?? '#94a3b8';
    $sBadge = $statusColors[$oStatus] ?? 'bg-gray-100 text-gray-600';
    
    // Courier tracking
    $__cn = strtolower($order['courier_name'] ?? ($order['shipping_method'] ?? ''));
    $__cid = $order['courier_consignment_id'] ?? ($order['pathao_consignment_id'] ?? '');
    $__tid = $order['courier_tracking_id'] ?? $__cid;
    $__link = '';
    if ($__cid) {
        if (strpos($__cn, 'steadfast') !== false) $__link = 'https://steadfast.com.bd/user/consignment/' . urlencode($__cid);
        elseif (strpos($__cn, 'pathao') !== false) $__link = 'https://merchant.pathao.com/courier/orders/' . urlencode($__cid);
        elseif (strpos($__cn, 'redx') !== false) $__link = 'https://redx.com.bd/track-parcel/?trackingId=' . urlencode($__tid);
    }
    
    // Source/channel display
    $channelMap = ['website'=>'WEB','facebook'=>'FACEBOOK','phone'=>'PHONE','whatsapp'=>'WHATSAPP','instagram'=>'INSTAGRAM','landing_page'=>'LP'];
    $srcLabel = $channelMap[$order['channel'] ?? ''] ?? strtoupper($order['channel'] ?? '—');
?>
<tr data-order-id="<?= $order['id'] ?>">
    <!-- Checkbox -->
    <td><input type="checkbox" name="order_ids[]" value="<?= $order['id'] ?>" class="order-check" onchange="updateBulk()"></td>
    
    <!-- Date -->
    <td>
        <div style="font-size:11px;font-weight:500;color:#334155"><?= date('d/m/Y,', strtotime($order['created_at'])) ?></div>
        <div style="font-size:10px;color:#64748b"><?= date('h:i a', strtotime($order['created_at'])) ?></div>
        <div style="font-size:9px;color:#94a3b8">Updated <?= timeAgo($order['updated_at']?:$order['created_at']) ?></div>
    </td>
    
    <!-- Invoice -->
    <td>
        <div style="display:flex;align-items:center;gap:4px">
            <a href="<?= adminUrl('pages/order-view.php?id='.$order['id']) ?>" style="font-weight:700;color:#0f172a;font-size:12px;text-decoration:none" onmouseover="this.style.color='#2563eb'" onmouseout="this.style.color='#0f172a'"><?= e($order['order_number']) ?></a>
            <span class="dot-menu" onclick="toggleRowMenu(this,<?= $order['id'] ?>)">⋮</span>
        </div>
        <?php if (!empty($order['is_preorder'])): ?>
        <span style="font-size:8px;background:#f3e8ff;color:#7c3aed;padding:1px 5px;border-radius:3px;font-weight:600;display:inline-block;margin-top:1px">⏰ PREORDER<?php if(!empty($order['preorder_date'])): ?> · <?= date('d M', strtotime($order['preorder_date'])) ?><?php endif; ?></span>
        <?php endif; ?>
    </td>
    
    <!-- Customer -->
    <td style="min-width:160px">
        <div style="display:flex;align-items:center;gap:5px">
            <span style="color:#94a3b8;font-size:13px">👤</span>
            <span class="cust-name"><?= e($order['customer_name']) ?></span>
        </div>
        <div style="margin-top:1px;display:flex;align-items:center;gap:3px">
            <span class="cust-phone"><?= e($order['customer_phone']) ?></span>
            <span class="rate-badge" style="background:<?= $sr['rate']>=70?'#dcfce7':($sr['rate']>=40?'#fef9c3':'#fee2e2') ?>;color:<?= $sr['rate']>=70?'#166534':($sr['rate']>=40?'#854d0e':'#991b1b') ?>"><?= $sr['rate'] ?>%</span>
        </div>
        <div class="cust-addr">📍 <?= e(mb_strimwidth($order['customer_address'],0,40,'...')) ?></div>
    </td>
    
    <!-- Notes -->
    <td style="max-width:180px;white-space:normal">
        <?php 
        $hasShipNote = !empty($order['notes']);
        $hasOrderNote = !empty($order['order_note']);
        $hasPanelNote = !empty($order['panel_notes']) || !empty($order['admin_notes']);
        if ($hasShipNote || $hasOrderNote || $hasPanelNote): ?>
            <?php if($hasShipNote):?><div style="font-size:10px;color:#ea580c;line-height:1.3;margin-bottom:2px" title="Shipping Note: Sent to courier"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#f97316;margin-right:3px;vertical-align:middle"></span><?= e(mb_strimwidth($order['notes'],0,60,'...')) ?></div><?php endif;?>
            <?php if($hasOrderNote):?><div style="font-size:10px;color:#2563eb;line-height:1.3;margin-bottom:2px" title="Order Note: Printed on invoice"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#3b82f6;margin-right:3px;vertical-align:middle"></span><?= e(mb_strimwidth($order['order_note'],0,60,'...')) ?></div><?php endif;?>
            <?php if($hasPanelNote):?><div style="font-size:10px;color:#16a34a;line-height:1.3" title="Panel Note: Internal only"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#22c55e;margin-right:3px;vertical-align:middle"></span><?= e(mb_strimwidth($order['panel_notes'] ?? $order['admin_notes'] ?? '',0,60,'...')) ?></div><?php endif;?>
        <?php else: ?>
            <span style="color:#d1d5db">—</span>
        <?php endif; ?>
    </td>
    
    <!-- Products -->
    <td style="min-width:160px">
        <div style="display:inline-flex;align-items:center;gap:3px;margin-bottom:3px">
            <span class="status-dot" style="background:<?= $sDot ?>"></span>
            <span class="tag-badge" style="background:<?= $sDot ?>22;color:<?= $sDot ?>;font-size:9px;padding:1px 6px"><?= strtoupper(str_replace('_',' ',$oStatus)) ?></span>
        </div>
        <?php foreach(array_slice($oItems,0,2) as $item): ?>
        <div style="display:flex;align-items:center;gap:5px;margin-top:2px">
            <img src="<?= !empty($item['featured_image'])?imgSrc('products',$item['featured_image']):'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22><rect fill=%22%23f1f5f9%22 width=%2240%22 height=%2240%22/><text y=%22.75em%22 x=%22.15em%22 font-size=%2224%22>📦</text></svg>' ?>" class="prod-thumb" loading="lazy">
            <div>
                <div style="font-size:11px;color:#334155;font-weight:500;max-width:120px;overflow:hidden;text-overflow:ellipsis"><?= e($item['product_name']) ?></div>
                <div style="font-size:9px;color:#94a3b8"><?php if(!empty($item['variant_name'])): ?><?= e($item['variant_name']) ?> · <?php endif; ?>Qty: <?= intval($item['quantity']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(count($oItems)>2): ?><div style="font-size:9px;color:#94a3b8;margin-top:2px">+<?= count($oItems)-2 ?> more</div><?php endif; ?>
    </td>
    
    <!-- Tags -->
    <td>
        <?php
        // Show order tags as colored badges
        $tagColors = ['REPEAT'=>'bg-orange-100 text-orange-700','URGENT'=>'bg-red-100 text-red-700','VIP'=>'bg-purple-100 text-purple-700','GIFT'=>'bg-pink-100 text-pink-700','COD VERIFIED'=>'bg-green-100 text-green-700','ADVANCE PAID'=>'bg-emerald-100 text-emerald-700','FOLLOW UP'=>'bg-blue-100 text-blue-700'];
        if ($sr['total'] > 1): ?>
            <span class="tag-badge" style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;margin-bottom:2px">Repeat Customer</span><br>
        <?php endif;
        foreach(array_slice($tags,0,3) as $tag):
            $tc2 = $tagColors[$tag['tag_name']] ?? 'bg-gray-100 text-gray-600';
        ?>
            <span class="tag-badge <?= $tc2 ?>" style="margin-bottom:2px"><?= e($tag['tag_name']) ?></span><br>
        <?php endforeach; ?>
        <button onclick="addTag(<?= $order['id'] ?>)" style="font-size:9px;color:#93c5fd;border:none;background:none;cursor:pointer;padding:0">+tag</button>
    </td>
    
    <!-- Total -->
    <td style="text-align:right">
        <div style="font-weight:700;color:#0f172a;font-size:12px"><?= number_format($order['total'],2) ?></div>
        <?php if ($creditUsed > 0): ?><div style="font-size:9px;color:#ca8a04">-৳<?= number_format($creditUsed) ?></div><?php endif; ?>
    </td>
    
    <!-- Upload (Courier Tracking) -->
    <td>
        <?php if($__link && $__cid): ?>
            <a href="<?= $__link ?>" target="_blank" style="font-size:11px;color:#2563eb;text-decoration:none;font-family:monospace;font-weight:500" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'"><?= e($__tid) ?></a>
            <?php if(!empty($order['courier_status'])):
                $__cs = $order['courier_status'];
                $__csc = '#94a3b8';
                if (in_array($__cs, ['delivered','delivered_approval_pending'])) $__csc = '#22c55e';
                elseif (in_array($__cs, ['cancelled','cancelled_approval_pending'])) $__csc = '#ef4444';
                elseif ($__cs === 'hold') $__csc = '#eab308';
            ?>
                <div style="font-size:9px;color:<?= $__csc ?>;margin-top:1px"><?= e($__cs) ?></div>
            <?php endif; ?>
        <?php elseif($__cid): ?>
            <span style="font-size:11px;color:#64748b;font-family:monospace"><?= e($__cid) ?></span>
        <?php else: ?>
            <span style="color:#d1d5db">—</span>
        <?php endif; ?>
    </td>
    
    <!-- User -->
    <td>
        <span style="font-size:11px;color:#475569"><?= e($order['assigned_name'] ?? '—') ?></span>
    </td>
    
    <!-- Source -->
    <td>
        <span style="font-size:10px;font-weight:600;color:#64748b"><?= $srcLabel ?></span>
    </td>
    
    <!-- Courier / Shipping -->
    <td style="max-width:160px;white-space:normal">
        <?php if (!empty($order['courier_name'])): ?>
            <div style="font-size:10px;font-weight:600;color:#475569"><?= e($order['courier_name']) ?></div>
        <?php endif; ?>
        <?php if (!empty($order['notes'])): ?>
            <div style="font-size:9px;color:#ea580c;line-height:1.3;margin-top:1px"><?= e(mb_strimwidth($order['notes'],0,70,'...')) ?></div>
        <?php elseif (empty($order['courier_name'])): ?>
            <span style="color:#d1d5db">—</span>
        <?php endif; ?>
    </td>
    
    <!-- Actions -->
    <td style="text-align:center;white-space:nowrap">
        <a href="<?= adminUrl('pages/order-view.php?id='.$order['id']) ?>" 
           class="order-open-link text-xs font-semibold text-green-600 hover:text-green-700"
           data-oid="<?= $order['id'] ?>">Open <span style="font-size:10px">↗</span></a>
        <span class="lock-indicator hidden text-[10px] text-pink-600 font-medium ml-0.5" data-lock-oid="<?= $order['id'] ?>"></span>
    </td>
</tr>
<?php endforeach; ?>
<?php if(empty($orders)): ?>
<tr><td colspan="13" style="text-align:center;padding:40px 20px;color:#94a3b8"><div style="font-size:28px;margin-bottom:8px">📦</div>No orders found</td></tr>
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
    per_page: '<?= $limit ?>',
  },
  _loading: false,

  // Navigate: merge params with current state, then fetch
  go(params) {
    const next = {...OM.state, ...params};
    // If changing tab or courier (not page), reset to page 1
    if ('status' in params || 'courier' in params) next.page = params.page || 1;
    OM.state = next;
    const qs = new URLSearchParams(Object.fromEntries(Object.entries(next).filter(([,v]) => v !== '' && v !== null)));
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

      // Show/hide courier sub-tab bar
      const courierBar = document.querySelector('.om-courier-bar');
      if (courierBar) {
        courierBar.classList.toggle('hidden', !params.status || params.status === 'processing');
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
function bStatus(s){
  const ids=getIds();
  if(!ids.length){alert('Select orders');return}
  if(!confirm('Change '+ids.length+' orders to "'+s+'"?'))return;
  const fd=new FormData();
  fd.append('action','bulk_status');fd.append('bulk_status',s);
  ids.forEach(i=>fd.append('order_ids[]',i));
  const p=document.getElementById('cProg');p.classList.remove('hidden');
  document.getElementById('cProgL').textContent='Updating '+ids.length+' orders...';
  document.getElementById('cProgB').style.width='60%';
  fd.append('_ajax','1');
  fetch(location.pathname,{method:'POST',credentials:'same-origin',body:fd})
    .then(r=>r.json()).then(d=>{
      document.getElementById('cProgB').style.width='100%';
      document.getElementById('cProgL').textContent='✓ Updated '+(d.count||'')+ ' orders';
      setTimeout(()=>{p.classList.add('hidden');OM.refresh();},1200);
    })
    .catch(e=>{document.getElementById('cProgL').textContent='Error';p.classList.add('hidden');OM.refresh();});
}
function bCourier(c){const ids=getIds();if(!ids.length){alert('Select orders');return}if(!confirm('Upload '+ids.length+' to '+c+'?'))return;document.getElementById('actionsMenu').classList.add('hidden');doCourier(ids,c)}

function doCourier(ids,c){
    const p=document.getElementById('cProg');p.classList.remove('hidden');
    document.getElementById('cProgL').textContent='Uploading '+ids.length+' to '+c+'...';
    document.getElementById('cProgB').style.width='30%';
    const fd=new FormData();fd.append('action','bulk_courier');fd.append('courier_name',c);ids.forEach(i=>fd.append('order_ids[]',i));
    fetch(location.pathname,{method:'POST',body:fd}).then(r=>r.text()).then(txt=>{
        document.getElementById('cProgB').style.width='100%';
        try{const d=JSON.parse(txt);document.getElementById('cProgL').textContent='✓ '+d.success+' uploaded, '+d.failed+' failed';
            if(d.errors?.length){const e=document.getElementById('cErr');e.classList.remove('hidden');e.innerHTML=d.errors.join('<br>')}
            setTimeout(()=>OM.refresh(),2000);
        }catch(e){document.getElementById('cProgL').textContent='Error';document.getElementById('cErr').classList.remove('hidden');document.getElementById('cErr').textContent=txt.substring(0,200)}
    }).catch(e=>{document.getElementById('cProgL').textContent='Error: '+e.message});
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
function toggleRowMenu(el, orderId, orderNum) {
    const rm = document.getElementById('rowMenu');
    if (rm._open === orderId) { rm.classList.add('hidden'); rm._open = null; return; }
    const r = el.getBoundingClientRect();
    rm.style.top = (r.bottom + window.scrollY + 2) + 'px';
    rm.style.left = Math.min(r.left, window.innerWidth - 190) + 'px';
    rm.classList.remove('hidden');
    rm._open = orderId;
    document.getElementById('rmOpen').href = '<?= adminUrl('pages/order-view.php?id=') ?>' + orderId;
    document.getElementById('rmPrintInv').onclick = () => { rm.classList.add('hidden'); openInvPrint([orderId]); };
    document.getElementById('rmPrintStk').onclick = () => { rm.classList.add('hidden'); openStkPrint([orderId]); };
    if (document.getElementById('rmEdit')) {
        document.getElementById('rmEdit').onclick = () => { rm.classList.add('hidden'); openEditOrder(orderId, orderNum); };
    }
    ['Confirm','Ship','Deliver','Cancel'].forEach(a => {
        const btn = document.getElementById('rm'+a);
        btn.onclick = () => { if(confirm(a+' this order?')){
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

function syncCourier(){
    document.getElementById('actionsMenu').classList.add('hidden');
    const p=document.getElementById('cProg');p.classList.remove('hidden');
    document.getElementById('cProgL').textContent='🔄 Syncing courier statuses...';
    document.getElementById('cProgB').style.width='30%';
    fetch('<?= SITE_URL ?>/api/courier-sync.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({limit:50})})
    .then(r=>r.json()).then(d=>{
        document.getElementById('cProgB').style.width='100%';
        document.getElementById('cProgL').textContent='✓ Synced '+d.total+' orders: '+d.updated+' updated, '+d.errors+' errors';
        if(d.details?.length){const e=document.getElementById('cErr');e.classList.remove('hidden');e.innerHTML=d.details.slice(0,5).join('<br>')}
        setTimeout(()=>OM.refresh(),2500);
    }).catch(e=>{document.getElementById('cProgL').textContent='Sync error: '+e.message});
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
    _editOrderUrl = '<?= adminUrl('pages/order-view.php') ?>?id=' + orderId + '&modal=1';
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
        if (iUrl.includes('order-management.php') || iUrl.includes('msg=updated') || iUrl.includes('msg=created')) {
            closeEditModal();
            OM.refresh();
            return;
        }
        // Update the order number in header if available
        var title = iframe.contentDocument?.title;
        if (title) document.getElementById('editOrderNum').textContent = title.replace('Order Management','').replace('|','').trim() || document.getElementById('editOrderNum').textContent;
    } catch(e) {}
}

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
function doInvPrint() { document.getElementById('invPrintIframe').contentWindow.print(); }
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
function closePrintModal() { closeInvModal(); closeStkModal(); }
function bPrint(t) { var ids = getIds(); if (!ids.length) { alert('Select orders'); return; } if (t && t.startsWith('stk_')) openStkPrint(ids); else openInvPrint(ids); }

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeInvModal(); closeStkModal(); }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
