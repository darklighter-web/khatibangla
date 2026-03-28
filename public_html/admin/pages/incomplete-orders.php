<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Incomplete Orders';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();

// Detect column names
$recCol = 'is_recovered';
try { $db->fetch("SELECT is_recovered FROM incomplete_orders LIMIT 1"); } catch (\Throwable $e) { $recCol = 'recovered'; }
$hasCartTotal = true; try { $db->query("SELECT cart_total FROM incomplete_orders LIMIT 0"); } catch (\Throwable $e) { $hasCartTotal = false; }
$hasFollowup = true; try { $db->query("SELECT followup_count FROM incomplete_orders LIMIT 0"); } catch (\Throwable $e) { $hasFollowup = false; }
$hasDeviceIp = true; try { $db->query("SELECT device_ip FROM incomplete_orders LIMIT 0"); } catch (\Throwable $e) { $hasDeviceIp = false; }
$hasCustomerAddress = true; try { $db->query("SELECT customer_address FROM incomplete_orders LIMIT 0"); } catch (\Throwable $e) { $hasCustomerAddress = false; }

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    
    if ($act === 'mark_recovered' && $id) {
        $db->update('incomplete_orders', [$recCol => 1], 'id = ?', [$id]);
        redirect(adminUrl('pages/incomplete-orders.php?msg=recovered'));
    }
    if ($act === 'followup' && $id && $hasFollowup) {
        $db->query("UPDATE incomplete_orders SET followup_count = followup_count + 1, last_followup_at = NOW() WHERE id = ?", [$id]);
        redirect(adminUrl('pages/incomplete-orders.php?msg=followup'));
    }
    if ($act === 'delete' && $id) {
        $db->delete('incomplete_orders', 'id = ?', [$id]);
        redirect(adminUrl('pages/incomplete-orders.php?msg=deleted'));
    }
    if ($act === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        $deleted = 0;
        foreach ($ids as $did) {
            $did = intval($did);
            if ($did > 0) { try { $db->delete('incomplete_orders', 'id = ?', [$did]); $deleted++; } catch (\Throwable $e) {} }
        }
        redirect(adminUrl('pages/incomplete-orders.php?filter=' . urlencode($filter ?? 'active') . '&msg=bulk_deleted&count=' . $deleted));
    }
    if ($act === 'delete_all_active') {
        try { $deleted = $db->fetch("SELECT COUNT(*) as c FROM incomplete_orders WHERE {$recCol} = 0")['c'] ?? 0;
            $db->query("DELETE FROM incomplete_orders WHERE {$recCol} = 0");
        } catch (\Throwable $e) { $deleted = 0; }
        redirect(adminUrl('pages/incomplete-orders.php?msg=bulk_deleted&count=' . $deleted));
    }
    
    // ── Convert incomplete → real confirmed order ──
    if ($act === 'convert_to_order' && $id) {
        $inc = $db->fetch("SELECT * FROM incomplete_orders WHERE id = ?", [$id]);
        if ($inc) {
            $name = sanitize($_POST['conv_name'] ?? $inc['customer_name'] ?? 'Unknown');
            $phone = sanitize($_POST['conv_phone'] ?? $inc['customer_phone'] ?? '');
            $address = sanitize($_POST['conv_address'] ?? ($inc['customer_address'] ?? ''));
            $shippingArea = sanitize($_POST['conv_shipping'] ?? 'outside_dhaka');
            $notes = sanitize($_POST['conv_notes'] ?? '');
            
            if (empty($phone)) { redirect(adminUrl('pages/incomplete-orders.php?msg=no_phone')); exit; }
            
            $cart = json_decode($inc['cart_data'] ?? '[]', true) ?: [];
            if (empty($cart)) { redirect(adminUrl('pages/incomplete-orders.php?msg=empty_cart')); exit; }
            
            $subtotal = 0;
            foreach ($cart as $ci) {
                $price = floatval($ci['price'] ?? $ci['sale_price'] ?? $ci['regular_price'] ?? 0);
                $qty = intval($ci['qty'] ?? $ci['quantity'] ?? 1);
                $subtotal += $price * $qty;
            }
            
            $shippingCost = $shippingArea === 'inside_dhaka'
                ? floatval(getSetting('shipping_inside_dhaka', 70))
                : ($shippingArea === 'dhaka_sub' 
                    ? floatval(getSetting('shipping_dhaka_sub', 100))
                    : floatval(getSetting('shipping_outside_dhaka', 130)));
            if ($subtotal >= floatval(getSetting('free_shipping_minimum', 5000))) $shippingCost = 0;
            $total = $subtotal + $shippingCost;
            
            $customer = $db->fetch("SELECT * FROM customers WHERE phone = ?", [$phone]);
            if ($customer) {
                $customerId = $customer['id'];
                $db->update('customers', ['name' => $name, 'address' => $address, 'total_orders' => $customer['total_orders'] + 1], 'id = ?', [$customerId]);
            } else {
                $customerId = $db->insert('customers', ['name' => $name, 'phone' => $phone, 'address' => $address, 'total_orders' => 1]);
            }
            
            $orderNumber = generateOrderNumber();
            $orderId = $db->insert('orders', [
                'order_number' => $orderNumber, 'customer_id' => $customerId,
                'customer_name' => $name, 'customer_phone' => $phone, 'customer_address' => $address,
                'channel' => 'website', 'subtotal' => $subtotal, 'shipping_cost' => $shippingCost,
                'discount_amount' => 0, 'total' => $total, 'payment_method' => 'cod',
                'order_status' => 'confirmed',
                'notes' => $notes ?: 'Recovered from incomplete order #' . $id,
                'ip_address' => $inc['ip_address'] ?? '',
            ]);
            
            foreach ($cart as $ci) {
                $productId = intval($ci['product_id'] ?? $ci['id'] ?? 0);
                $price = floatval($ci['price'] ?? $ci['sale_price'] ?? $ci['regular_price'] ?? 0);
                $qty = intval($ci['qty'] ?? $ci['quantity'] ?? 1);
                $db->insert('order_items', [
                    'order_id' => $orderId, 'product_id' => $productId,
                    'product_name' => $ci['name'] ?? $ci['product_name'] ?? 'Product',
                    'variant_name' => $ci['variant_name'] ?? $ci['variant'] ?? null,
                    'quantity' => $qty, 'price' => $price, 'subtotal' => $price * $qty,
                ]);
                if ($productId) { try { $db->query("UPDATE products SET stock_quantity = stock_quantity - ?, sales_count = sales_count + ? WHERE id = ?", [$qty, $qty, $productId]); } catch (\Throwable $e) {} }
            }
            
            $db->insert('order_status_history', ['order_id' => $orderId, 'status' => 'confirmed', 'changed_by' => getAdminId(), 'note' => 'Recovered from incomplete order #' . $id]);
            try { $db->insert('accounting_entries', ['entry_type' => 'income', 'amount' => $total, 'reference_type' => 'order', 'reference_id' => $orderId, 'description' => "Order #{$orderNumber}", 'entry_date' => date('Y-m-d')]); } catch (\Throwable $e) {}
            $db->update('incomplete_orders', [$recCol => 1, 'recovered_order_id' => $orderId], 'id = ?', [$id]);
            logActivity(getAdminId(), 'convert_incomplete', 'orders', $orderId, "Incomplete #{$id} → Order #{$orderNumber}");
            redirect(adminUrl("pages/order-view.php?id={$orderId}&msg=converted"));
            exit;
        }
    }
}

$filter = $_GET['filter'] ?? 'active';
$search = $_GET['search'] ?? '';
$where = "1=1"; $params = [];
if ($filter === 'active') { $where .= " AND {$recCol} = 0"; }
elseif ($filter === 'recovered') { $where .= " AND {$recCol} = 1"; }
if ($search) { $where .= " AND (customer_phone LIKE ? OR customer_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

try { $incompletes = $db->fetchAll("SELECT * FROM incomplete_orders WHERE {$where} ORDER BY created_at DESC LIMIT 100", $params); } catch (\Throwable $e) { $incompletes = []; }

$sActive = 0; $sRecovered = 0; $sTotal = 0; $sCartValue = 0;
try {
    $cartCol = $hasCartTotal ? 'cart_total' : '0';
    $row = $db->fetch("SELECT COUNT(*) as cnt, COALESCE(SUM(CASE WHEN {$recCol}=0 THEN 1 ELSE 0 END),0) as act, COALESCE(SUM(CASE WHEN {$recCol}=1 THEN 1 ELSE 0 END),0) as recov, COALESCE(SUM(CASE WHEN {$recCol}=0 THEN {$cartCol} ELSE 0 END),0) as cv FROM incomplete_orders WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
    if ($row) { $sTotal=intval($row['cnt']??0); $sActive=intval($row['act']??0); $sRecovered=intval($row['recov']??0); $sCartValue=floatval($row['cv']??0); }
} catch (\Throwable $e) {}
$sRate = $sTotal > 0 ? round(($sRecovered / $sTotal) * 100) : 0;

// Pre-fetch product images
$allPids = [];
foreach ($incompletes as $inc) { foreach (json_decode($inc['cart_data'] ?? '[]', true) ?: [] as $ci) { $pid = intval($ci['product_id'] ?? $ci['id'] ?? 0); if ($pid) $allPids[] = $pid; } }
$productImages = [];
if (!empty($allPids)) { $uids = array_unique($allPids); $ph = implode(',', array_fill(0, count($uids), '?'));
    try { foreach ($db->fetchAll("SELECT id, featured_image, name, name_bn FROM products WHERE id IN ({$ph})", array_values($uids)) as $img) $productImages[$img['id']] = $img; } catch (\Throwable $e) {} }

// ── Courier success rate for incomplete orders (same cache as order-management) ──
try { $db->query("CREATE TABLE IF NOT EXISTS `customer_courier_stats` (`id` int(11) NOT NULL AUTO_INCREMENT,`phone_hash` varchar(32) NOT NULL,`phone_display` varchar(20) NOT NULL,`total_orders` int(11) NOT NULL DEFAULT 0,`delivered` int(11) NOT NULL DEFAULT 0,`cancelled` int(11) NOT NULL DEFAULT 0,`returned` int(11) NOT NULL DEFAULT 0,`success_rate` decimal(5,2) NOT NULL DEFAULT 0.00,`total_spent` decimal(12,2) NOT NULL DEFAULT 0.00,`courier_breakdown` text DEFAULT NULL,`fetched_at` datetime NOT NULL,`created_at` timestamp DEFAULT CURRENT_TIMESTAMP,`updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`),UNIQUE KEY `idx_phone_hash` (`phone_hash`),KEY `idx_fetched` (`fetched_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"); } catch (\Throwable $e) {}

$incSuccessRates = [];
$incPhones = array_unique(array_filter(array_column($incompletes, 'customer_phone')));
if (!empty($incPhones)) {
    $phoneHashes = [];
    foreach ($incPhones as $phone) {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean) >= 10) $phoneHashes[$phone] = md5(substr($clean, -11));
    }
    // Batch fetch from cache
    if (!empty($phoneHashes)) {
        $hashList = array_values($phoneHashes);
        $ph2 = implode(',', array_fill(0, count($hashList), '?'));
        $cachedRows = [];
        try { $rows = $db->fetchAll("SELECT * FROM customer_courier_stats WHERE phone_hash IN ({$ph2}) AND fetched_at > DATE_SUB(NOW(), INTERVAL 12 HOUR)", $hashList); foreach ($rows as $r) $cachedRows[$r['phone_hash']] = $r; } catch (\Throwable $e) {}
        
        foreach ($phoneHashes as $phone => $hash) {
            if (isset($cachedRows[$hash])) {
                $c = $cachedRows[$hash];
                $incSuccessRates[$phone] = ['total'=>intval($c['total_orders']),'delivered'=>intval($c['delivered']),'cancelled'=>intval($c['cancelled']),'returned'=>intval($c['returned']),'rate'=>floatval($c['success_rate']),'total_spent'=>floatval($c['total_spent']),'courier_breakdown'=>json_decode($c['courier_breakdown']??'[]',true)?:[]];
            } else {
                // Cache miss — compute from orders table
                $pl = '%' . substr(preg_replace('/[^0-9]/', '', $phone), -11);
                try {
                    $sr = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN order_status='returned' THEN 1 ELSE 0 END) as returned, SUM(total) as total_spent FROM orders WHERE REPLACE(REPLACE(customer_phone,' ',''),'-','') LIKE ?", [$pl]);
                    $t=intval($sr['total']??0);$d=intval($sr['delivered']??0);$cn=intval($sr['cancelled']??0);$ret=intval($sr['returned']??0);
                    $rate=$t>0?round(($d/$t)*100,2):0;
                    $incSuccessRates[$phone] = ['total'=>$t,'delivered'=>$d,'cancelled'=>$cn,'returned'=>$ret,'rate'=>$rate,'total_spent'=>floatval($sr['total_spent']??0),'courier_breakdown'=>[]];
                    // Write to cache
                    try { $db->query("INSERT INTO customer_courier_stats (phone_hash,phone_display,total_orders,delivered,cancelled,returned,success_rate,total_spent,courier_breakdown,fetched_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE total_orders=VALUES(total_orders),delivered=VALUES(delivered),cancelled=VALUES(cancelled),returned=VALUES(returned),success_rate=VALUES(success_rate),total_spent=VALUES(total_spent),fetched_at=VALUES(fetched_at)", [$hash,$phone,$t,$d,$cn,$ret,$rate,floatval($sr['total_spent']??0),'[]',date('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
                } catch (\Throwable $e) { $incSuccessRates[$phone] = ['total'=>0,'delivered'=>0,'cancelled'=>0,'returned'=>0,'rate'=>0,'total_spent'=>0,'courier_breakdown'=>[]]; }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['msg'])): $isErr = in_array($_GET['msg'],['no_phone','empty_cart','error']); ?>
<div class="bg-<?= $isErr?'red':'green' ?>-50 border border-<?= $isErr?'red':'green' ?>-200 text-<?= $isErr?'red':'green' ?>-700 px-4 py-3 rounded-lg mb-4 text-sm">
    <?= ['recovered'=>'✓ Marked as recovered.','followup'=>'✓ Follow-up logged.','deleted'=>'✓ Deleted.','bulk_deleted'=>'✓ Deleted '.intval($_GET['count']??0).' incomplete orders.','no_phone'=>'✗ Cannot convert: no phone number.','empty_cart'=>'✗ Cannot convert: cart is empty.','error'=>'✗ Error: '.e($_GET['detail']??'Unknown error')][$_GET['msg']] ?? '✓ Done.' ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500 mb-1">Incomplete (30d)</p><p class="text-2xl font-bold text-red-600"><?= number_format($sActive) ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500 mb-1">Recovered</p><p class="text-2xl font-bold text-green-600"><?= number_format($sRecovered) ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500 mb-1">Recovery Rate</p><p class="text-2xl font-bold text-blue-600"><?= $sRate ?>%</p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500 mb-1">Lost Revenue</p><p class="text-2xl font-bold text-orange-600">৳<?= number_format($sCartValue) ?></p></div>
</div>

<div class="flex flex-wrap gap-2 mb-4">
    <a href="?filter=active" class="px-4 py-2 rounded-lg text-sm font-medium <?= $filter==='active'?'bg-red-600 text-white':'bg-white border text-gray-600 hover:bg-gray-50' ?>">🛒 Active (<?= $sActive ?>)</a>
    <a href="?filter=recovered" class="px-4 py-2 rounded-lg text-sm font-medium <?= $filter==='recovered'?'bg-green-600 text-white':'bg-white border text-gray-600 hover:bg-gray-50' ?>">✅ Recovered (<?= $sRecovered ?>)</a>
    <a href="?filter=all" class="px-4 py-2 rounded-lg text-sm font-medium <?= $filter==='all'?'bg-blue-600 text-white':'bg-white border text-gray-600 hover:bg-gray-50' ?>">All</a>
    <?php if ($sActive > 0): ?>
    <form method="POST" class="inline" onsubmit="return confirm('⚠️ Delete ALL <?= $sActive ?> active incomplete orders?\n\nThis cannot be undone.')">
        <input type="hidden" name="action" value="delete_all_active">
        <button class="px-4 py-2 rounded-lg text-sm font-medium bg-red-50 text-red-600 border border-red-200 hover:bg-red-100">🗑 Delete All Active (<?= $sActive ?>)</button>
    </form>
    <?php endif; ?>
    <a href="<?= adminUrl('pages/order-management.php') ?>" class="px-4 py-2 rounded-lg text-sm font-medium bg-white border text-gray-600 hover:bg-gray-50 ml-auto">← Back to Orders</a>
    <form class="flex gap-2"><input type="hidden" name="filter" value="<?= e($filter) ?>">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search phone/name..." class="px-3 py-2 border rounded-lg text-sm w-48">
        <button class="px-3 py-2 bg-gray-100 rounded-lg text-sm hover:bg-gray-200"><i class="fas fa-search"></i></button>
    </form>
</div>

<!-- Bulk action bar (hidden until selections made) -->
<div id="bulkBar" class="hidden sticky top-0 z-40 bg-red-600 text-white px-4 py-3 rounded-xl mb-3 flex items-center justify-between shadow-lg">
    <div class="flex items-center gap-3">
        <span class="font-semibold text-sm"><span id="selectedCount">0</span> selected</span>
        <button onclick="selectAllVisible()" class="text-xs bg-white/20 px-2.5 py-1 rounded-lg hover:bg-white/30">Select All</button>
        <button onclick="deselectAll()" class="text-xs bg-white/20 px-2.5 py-1 rounded-lg hover:bg-white/30">Deselect</button>
    </div>
    <button onclick="bulkDelete()" class="bg-white text-red-600 px-4 py-1.5 rounded-lg text-sm font-bold hover:bg-red-50">🗑 Delete Selected</button>
</div>

<div class="om-wrap">
<style>
.om-table th,.om-table td{padding:12px 8px;vertical-align:top;border-bottom:1px solid #f1f5f9;font-size:13px}
.om-table th{background:#fafbfc;color:#6b7280;font-weight:600;font-size:12px;position:sticky;top:0;z-index:2;border-bottom:1px solid #e5e7eb;white-space:nowrap}
.om-table tbody tr{transition:background .15s}
.om-table tbody tr:hover{background:#f8fafb}
.om-table{width:100%;border-collapse:collapse}
</style>
<table class="om-table">
<thead><tr>
    <th style="width:30px"><input type="checkbox" id="selectAllCb" onchange="toggleSelectAll(this.checked)" class="w-4 h-4 accent-red-600 cursor-pointer"></th>
    <th>Created At</th>
    <th>Customer</th>
    <th>Note</th>
    <th>Products</th>
    <th>Step</th>
    <th style="text-align:right">Value</th>
    <?php if ($hasFollowup): ?><th>Follow-ups</th><?php endif; ?>
    <th style="text-align:center">Actions</th>
</tr></thead>
<tbody>
<?php foreach ($incompletes as $inc):
    $cart = json_decode($inc['cart_data'] ?? '[]', true) ?: [];
    $isRec = intval($inc[$recCol] ?? 0);
    $stepC = ['cart'=>'#eab308','info'=>'#3b82f6','shipping'=>'#6366f1','payment'=>'#9333ea','checkout_form'=>'#3b82f6'];
    $ph = preg_replace('/[^0-9]/', '', $inc['customer_phone'] ?? '');
    $cartTotal = 0;
    foreach ($cart as $ci) { $cartTotal += floatval($ci['price'] ?? $ci['sale_price'] ?? 0) * intval($ci['qty'] ?? $ci['quantity'] ?? 1); }
    if ($hasCartTotal && !empty($inc['cart_total'])) $cartTotal = floatval($inc['cart_total']);
?>
<tr class="<?= $isRec?'bg-green-50/30':'' ?>">
    <td style="vertical-align:middle"><input type="checkbox" class="row-cb w-4 h-4 accent-red-600 cursor-pointer" value="<?= $inc['id'] ?>" onchange="updateBulkBar()"></td>

    <td style="white-space:nowrap">
        <div style="font-size:13px;color:#374151"><?= date('d M, h:i a', strtotime($inc['created_at'])) ?></div>
        <div style="font-size:11px;color:#9ca3af">ID: <?= $inc['id'] ?></div>
    </td>

    <td>
        <?php
            $__incSr = $incSuccessRates[$inc['customer_phone'] ?? ''] ?? ['rate'=>0,'total'=>0,'delivered'=>0];
            $__incRClr = $__incSr['rate']>=70?'#dcfce7':($__incSr['rate']>=40?'#fef9c3':'#fee2e2');
            $__incRTxt = $__incSr['rate']>=70?'#166534':($__incSr['rate']>=40?'#854d0e':'#991b1b');
        ?>
        <?php if (!empty($inc['customer_phone'])): ?>
        <div style="display:flex;align-items:center;gap:5px;margin-bottom:2px">
            <span style="color:#9ca3af;font-size:11px">📞</span>
            <span style="font-size:12px;color:#374151;font-weight:500"><?= e($inc['customer_phone']) ?></span>
            <?php if($__incSr['total']>0):?><span style="display:inline-block;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;background:<?=$__incRClr?>;color:<?=$__incRTxt?>"><?=$__incSr['rate']?>%</span><?php endif;?>
            <a href="tel:<?= e($ph) ?>" style="color:#3b82f6;font-size:11px">📱</a>
            <a href="https://wa.me/88<?= $ph ?>" target="_blank" style="color:#22c55e;font-size:11px">💬</a>
        </div>
        <?php endif; ?>
        <?php if (!empty($inc['customer_name'])): ?>
        <div style="display:flex;align-items:center;gap:5px;margin-bottom:2px">
            <span style="color:#9ca3af;font-size:11px">👤</span>
            <span style="font-size:13px;font-weight:500;color:#374151"><?= e($inc['customer_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($hasCustomerAddress && !empty($inc['customer_address'])): ?>
        <div style="display:flex;align-items:center;gap:5px">
            <span style="color:#9ca3af;font-size:11px">📍</span>
            <span style="font-size:12px;color:#9ca3af"><?= e(mb_strimwidth($inc['customer_address'],0,30,'...')) ?></span>
        </div>
        <?php elseif(empty($inc['customer_name']) && empty($inc['customer_phone'])): ?>
        <span style="font-size:12px;color:#d1d5db">Unknown visitor</span>
        <?php endif; ?>
    </td>

    <td>
        <div style="font-size:11px;color:#9ca3af;margin-bottom:2px"><?= timeAgo($inc['created_at']) ?></div>
        <?php if (!empty($inc['ip_address']) || !empty($inc['device_ip'])): ?>
        <div style="font-size:10px;color:#d1d5db">IP: <?= e($inc['device_ip'] ?? $inc['ip_address'] ?? '') ?></div>
        <?php else: ?>
        <span style="color:#d1d5db">-</span>
        <?php endif; ?>
    </td>

    <td>
        <?php if (!empty($cart)): ?>
        <?php foreach (array_slice($cart, 0, 2) as $ci):
            $pid=intval($ci['product_id']??$ci['id']??0); $pImg=$productImages[$pid]['featured_image']??''; ?>
        <div style="display:flex;align-items:center;gap:5px;margin-bottom:3px">
            <div style="width:32px;height:32px;border-radius:6px;border:1px solid #e5e7eb;overflow:hidden;flex-shrink:0;background:#f9fafb">
                <?php if ($pImg): ?><img src="<?= imgSrc('products', $pImg) ?>" style="width:100%;height:100%;object-fit:cover" loading="lazy">
                <?php else: ?><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#d1d5db;font-size:14px">📦</div><?php endif; ?>
            </div>
            <div style="overflow:hidden">
                <div style="font-size:12px;color:#374151;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px"><?= e($ci['name'] ?? $ci['product_name'] ?? 'Product') ?></div>
                <div style="font-size:10px;color:#9ca3af"><?php if(!empty($ci['variant_name']??$ci['variant']??'')):?><?=e(mb_strimwidth($ci['variant_name']??$ci['variant']??'',0,18,'..'))?>·<?php endif;?>Qty: <?= intval($ci['qty']??$ci['quantity']??1) ?></div>
            </div>
        </div>
        <?php endforeach; if(count($cart)>2): ?><div style="font-size:10px;color:#9ca3af">+<?= count($cart)-2 ?> more</div><?php endif; ?>
        <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
    </td>

    <td>
        <span style="display:inline-block;font-size:10px;font-weight:600;padding:2px 8px;border-radius:12px;background:<?= ($stepC[$inc['step_reached']??'cart'] ?? '#9ca3af') ?>22;color:<?= $stepC[$inc['step_reached']??'cart'] ?? '#9ca3af' ?>"><?= ucfirst(str_replace('_', ' ', $inc['step_reached'] ?? 'Cart')) ?></span>
    </td>

    <td style="text-align:right;white-space:nowrap">
        <div style="font-weight:700;color:#111827;font-size:13px">৳<?= number_format($cartTotal) ?></div>
    </td>

    <?php if ($hasFollowup): ?>
    <td>
        <span style="font-size:12px;color:#374151"><?= intval($inc['followup_count']??0) ?>×</span>
        <?php if (!empty($inc['last_followup_at'])): ?><div style="font-size:10px;color:#9ca3af"><?= timeAgo($inc['last_followup_at']) ?></div><?php endif; ?>
    </td>
    <?php endif; ?>

    <td style="text-align:center;vertical-align:middle">
        <?php
            $__incAge = time() - strtotime($inc['created_at']);
            $__isTooNew = $__incAge < 420; // 7 minutes
            $__ageMin = round($__incAge / 60);
        ?>
        <?php if (!$isRec): ?>
        <div style="display:flex;align-items:center;justify-content:center;gap:4px;flex-wrap:wrap">
            <button onclick='viewDetails(<?= json_encode($inc, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($cart, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)' style="font-size:11px;background:#f3f4f6;color:#374151;padding:5px 10px;border-radius:6px;border:none;cursor:pointer;font-weight:500">Details</button>
            <?php if (!empty($inc['customer_phone'])): ?>
            <button onclick='confirmIncomplete(<?= json_encode($inc, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($cart, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>, <?= $__isTooNew ? 'true' : 'false' ?>, <?= $__ageMin ?>)' style="font-size:11px;background:#16a34a;color:#fff;padding:5px 10px;border-radius:6px;border:none;cursor:pointer;font-weight:600">✓ Confirm</button>
            <?php endif; ?>
            <?php if ($hasFollowup): ?>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="followup"><input type="hidden" name="id" value="<?= $inc['id'] ?>">
                <button style="font-size:11px;background:#fff7ed;color:#ea580c;padding:5px 8px;border-radius:6px;border:none;cursor:pointer" title="Log follow-up">📞</button></form>
            <?php endif; ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $inc['id'] ?>">
                <button style="font-size:11px;background:#fef2f2;color:#dc2626;padding:5px 8px;border-radius:6px;border:none;cursor:pointer" title="Delete">🗑</button></form>
        </div>
        <?php else: ?>
        <span style="font-size:12px;color:#16a34a">✅ Recovered<?php if (!empty($inc['recovered_order_id'])): ?> → <a href="<?= adminUrl('pages/order-view.php?id='.$inc['recovered_order_id']) ?>" style="color:#2563eb;text-decoration:underline;font-weight:500">View</a><?php endif; ?></span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($incompletes)): ?><tr><td colspan="9" style="padding:48px;text-align:center;color:#9ca3af"><div style="font-size:32px;margin-bottom:8px">🛒</div><p>No incomplete orders found.</p></td></tr><?php endif; ?>
</tbody></table></div>

<!-- ═══ DETAILS MODAL ═══ -->
<div id="detailsModal" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center p-4" onclick="this.classList.add('hidden')">
<div class="bg-white rounded-2xl w-full max-w-lg max-h-[85vh] overflow-y-auto shadow-2xl" onclick="event.stopPropagation()">
    <div class="sticky top-0 bg-white border-b px-5 py-3 flex items-center justify-between z-10 rounded-t-2xl">
        <h3 class="font-bold text-gray-800">📋 Incomplete Order Details</h3>
        <button onclick="document.getElementById('detailsModal').classList.add('hidden')" class="p-1 hover:bg-gray-100 rounded-lg"><i class="fas fa-times text-gray-400"></i></button>
    </div>
    <div class="p-5 space-y-4" id="detailsContent"></div>
</div></div>

<!-- ═══ CONVERT MODAL ═══ -->
<div id="convertModal" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center p-4" onclick="this.classList.add('hidden')">
<div class="bg-white rounded-2xl w-full max-w-lg max-h-[85vh] overflow-y-auto shadow-2xl" onclick="event.stopPropagation()">
    <div class="sticky top-0 bg-white border-b px-5 py-3 flex items-center justify-between z-10 rounded-t-2xl">
        <h3 class="font-bold text-gray-800">✓ Convert to Confirmed Order</h3>
        <button onclick="document.getElementById('convertModal').classList.add('hidden')" class="p-1 hover:bg-gray-100 rounded-lg"><i class="fas fa-times text-gray-400"></i></button>
    </div>
    <form method="POST" class="p-5 space-y-4">
        <input type="hidden" name="action" value="convert_to_order">
        <input type="hidden" name="id" id="conv_id">
        <div class="bg-blue-50 border border-blue-200 text-blue-700 text-xs p-3 rounded-lg">This creates a <strong>Confirmed Order</strong> and moves it to the <strong>Confirmed Orders</strong> panel. Stock will be deducted.</div>
        <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-xs font-medium text-gray-700 mb-1">Name *</label><input type="text" name="conv_name" id="conv_name" required class="w-full px-3 py-2 border rounded-lg text-sm"></div>
            <div><label class="block text-xs font-medium text-gray-700 mb-1">Phone *</label><input type="text" name="conv_phone" id="conv_phone" required class="w-full px-3 py-2 border rounded-lg text-sm"></div>
        </div>
        <div><label class="block text-xs font-medium text-gray-700 mb-1">Address *</label><textarea name="conv_address" id="conv_address" required rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"></textarea></div>
        <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-xs font-medium text-gray-700 mb-1">Shipping</label>
                <select name="conv_shipping" class="w-full px-3 py-2 border rounded-lg text-sm">
                    <option value="inside_dhaka">Dhaka (৳<?= getSetting('shipping_inside_dhaka', 70) ?>)</option>
                    <option value="dhaka_sub">Dhaka Sub (৳<?= getSetting('shipping_dhaka_sub', 100) ?>)</option>
                    <option value="outside_dhaka" selected>Outside (৳<?= getSetting('shipping_outside_dhaka', 130) ?>)</option>
                </select></div>
            <div><label class="block text-xs font-medium text-gray-700 mb-1">Notes</label><input type="text" name="conv_notes" placeholder="Optional..." class="w-full px-3 py-2 border rounded-lg text-sm"></div>
        </div>
        <div><label class="block text-xs font-medium text-gray-700 mb-2">Cart Items</label>
            <div id="conv_items" class="border rounded-lg divide-y text-sm"></div>
            <div class="flex justify-between mt-2 text-sm font-bold"><span>Total:</span><span id="conv_total">৳0</span></div>
        </div>
        <button class="w-full bg-blue-600 text-white py-3 rounded-xl font-semibold hover:bg-blue-700 text-sm"><i class="fas fa-check mr-2"></i>Create Confirmed Order</button>
    </form>
</div></div>

<script>
function esc(s){if(!s)return '';var d=document.createElement('div');d.textContent=s;return d.innerHTML}

function viewDetails(inc, cart){
    var h='';
    h+='<div class="bg-gray-50 rounded-xl p-4"><h4 class="text-xs font-semibold text-gray-500 mb-2 uppercase">Customer</h4><div class="space-y-1.5 text-sm">';
    if(inc.customer_name) h+='<p><span class="text-gray-500">Name:</span> <strong>'+esc(inc.customer_name)+'</strong></p>';
    if(inc.customer_phone){var p=inc.customer_phone.replace(/[^0-9]/g,''); h+='<p><span class="text-gray-500">Phone:</span> <strong>'+esc(inc.customer_phone)+'</strong> <a href="tel:'+p+'" class="text-blue-500 text-xs ml-2">📞 Call</a> <a href="https://wa.me/88'+p+'" target="_blank" class="text-green-500 text-xs ml-1">💬 WhatsApp</a></p>';}
    if(inc.customer_email) h+='<p><span class="text-gray-500">Email:</span> '+esc(inc.customer_email)+'</p>';
    if(inc.customer_address) h+='<p><span class="text-gray-500">Address:</span> '+esc(inc.customer_address)+'</p>';
    h+='</div></div>';
    
    h+='<div class="bg-gray-50 rounded-xl p-4"><h4 class="text-xs font-semibold text-gray-500 mb-2 uppercase">Cart ('+cart.length+' items)</h4>';
    if(cart.length){
        h+='<div class="divide-y border rounded-lg bg-white">';
        var sub=0;
        cart.forEach(function(ci){
            var pr=parseFloat(ci.price||ci.sale_price||ci.regular_price||0);
            var q=parseInt(ci.qty||ci.quantity||1); sub+=pr*q;
            h+='<div class="flex items-center gap-3 p-3"><div class="flex-1 min-w-0"><p class="text-sm font-medium truncate">'+esc(ci.name||ci.product_name||'Item')+'</p>';
            if(ci.variant_name||ci.variant) h+='<p class="text-xs text-indigo-500">'+esc(ci.variant_name||ci.variant)+'</p>';
            h+='<p class="text-xs text-gray-400">x'+q+' × ৳'+pr.toLocaleString()+'</p></div><p class="font-bold text-sm flex-shrink-0">৳'+(pr*q).toLocaleString()+'</p></div>';
        });
        h+='</div><div class="flex justify-between mt-2 font-bold text-sm"><span>Subtotal</span><span>৳'+sub.toLocaleString()+'</span></div>';
    } else h+='<p class="text-gray-400 text-sm">No cart data</p>';
    h+='</div>';
    
    h+='<div class="bg-gray-50 rounded-xl p-4"><h4 class="text-xs font-semibold text-gray-500 mb-2 uppercase">Technical</h4><div class="grid grid-cols-2 gap-2 text-xs">';
    h+='<div class="bg-white p-2 rounded-lg"><span class="text-gray-400 block">Step</span><span class="font-medium capitalize">'+(inc.step_reached||'cart').replace(/_/g,' ')+'</span></div>';
    h+='<div class="bg-white p-2 rounded-lg"><span class="text-gray-400 block">Created</span><span class="font-medium">'+new Date(inc.created_at).toLocaleString()+'</span></div>';
    if(inc.ip_address||inc.device_ip) h+='<div class="bg-white p-2 rounded-lg"><span class="text-gray-400 block">IP</span><span class="font-mono">'+esc(inc.device_ip||inc.ip_address)+'</span></div>';
    if(inc.session_id) h+='<div class="bg-white p-2 rounded-lg"><span class="text-gray-400 block">Session</span><span class="font-mono truncate block">'+esc((inc.session_id||'').substring(0,16))+'...</span></div>';
    if(inc.user_agent) h+='<div class="bg-white p-2 rounded-lg col-span-2"><span class="text-gray-400 block">Browser</span><span class="font-mono text-[10px] break-all">'+esc((inc.user_agent||'').substring(0,150))+'</span></div>';
    h+='</div></div>';
    
    document.getElementById('detailsContent').innerHTML=h;
    document.getElementById('detailsModal').classList.remove('hidden');
}

async function confirmIncomplete(inc, cart, isTooNew, ageMin) {
    if (isTooNew) {
        var ok = await window._confirmAsync(
            '⚠️ This incomplete order is only ' + ageMin + ' minute(s) old!\n\n' +
            'The customer may still be filling out the form right now.\n' +
            'Are you sure you want to create this order?'
        );
        if (!ok) return;
    }
    convertOrder(inc, cart);
}

function convertOrder(inc, cart){
    document.getElementById('conv_id').value=inc.id;
    document.getElementById('conv_name').value=inc.customer_name||'';
    document.getElementById('conv_phone').value=inc.customer_phone||'';
    document.getElementById('conv_address').value=inc.customer_address||'';
    var h='',sub=0;
    cart.forEach(function(ci){
        var pr=parseFloat(ci.price||ci.sale_price||ci.regular_price||0);
        var q=parseInt(ci.qty||ci.quantity||1); sub+=pr*q;
        h+='<div class="flex items-center justify-between p-2.5"><span class="truncate flex-1">'+esc(ci.name||ci.product_name||'Item')+' × '+q+'</span><span class="font-medium flex-shrink-0 ml-2">৳'+(pr*q).toLocaleString()+'</span></div>';
    });
    if(!h) h='<div class="p-3 text-center text-gray-400 text-sm">No items</div>';
    document.getElementById('conv_items').innerHTML=h;
    document.getElementById('conv_total').textContent='৳'+sub.toLocaleString();
    document.getElementById('convertModal').classList.remove('hidden');
}

// ========== BULK SELECT & DELETE ==========
function getChecked() { return document.querySelectorAll('.row-cb:checked'); }

function updateBulkBar() {
    var checked = getChecked();
    var bar = document.getElementById('bulkBar');
    var cnt = document.getElementById('selectedCount');
    if (checked.length > 0) {
        bar.classList.remove('hidden');
        cnt.textContent = checked.length;
    } else {
        bar.classList.add('hidden');
    }
    // Sync header checkbox
    var all = document.querySelectorAll('.row-cb');
    document.getElementById('selectAllCb').checked = all.length > 0 && checked.length === all.length;
}

function toggleSelectAll(state) {
    document.querySelectorAll('.row-cb').forEach(function(cb) { cb.checked = state; });
    updateBulkBar();
}

function selectAllVisible() {
    document.querySelectorAll('.row-cb').forEach(function(cb) { cb.checked = true; });
    document.getElementById('selectAllCb').checked = true;
    updateBulkBar();
}

function deselectAll() {
    document.querySelectorAll('.row-cb').forEach(function(cb) { cb.checked = false; });
    document.getElementById('selectAllCb').checked = false;
    updateBulkBar();
}

async function bulkDelete() {
    var checked = getChecked();
    if (checked.length === 0) return;
    const _ok = await window._confirmAsync('⚠️ Delete ' + checked.length + ' incomplete order(s)?\n\nThis cannot be undone.'); if(!_ok) return;
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    var act = document.createElement('input');
    act.name = 'action'; act.value = 'bulk_delete'; form.appendChild(act);
    
    checked.forEach(function(cb) {
        var inp = document.createElement('input');
        inp.name = 'ids[]'; inp.value = cb.value;
        form.appendChild(inp);
    });
    
    document.body.appendChild(form);
    form.submit();
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
