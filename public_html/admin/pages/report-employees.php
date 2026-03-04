<?php
/**
 * Advanced Employee Report — Order Status, Sources, Product, Work Log, Cancellations
 * Tracks orders via activity_logs (first significant action = order owner)
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Employee Report';
$db = Database::getInstance();

$dateFrom = $_GET['from'] ?? date('Y-m-d');
$dateTo   = $_GET['to'] ?? date('Y-m-d');
$tab      = $_GET['tab'] ?? 'status';
$showMoney = ($_GET['money'] ?? '1') === '1';
$employeeId = intval($_GET['employee'] ?? 0);

// Date label
$isToday = ($dateFrom === date('Y-m-d') && $dateTo === date('Y-m-d'));
if ($isToday) { $dateLabel = 'Today'; }
elseif ($dateFrom === $dateTo) { $dateLabel = date('d M Y', strtotime($dateFrom)); }
else { $dateLabel = date('d M', strtotime($dateFrom)) . ' – ' . date('d M Y', strtotime($dateTo)); }

// Get all active employees
$employees = $db->fetchAll("SELECT au.id, au.full_name, au.id as emp_code, ar.role_name 
    FROM admin_users au LEFT JOIN admin_roles ar ON ar.id = au.role_id 
    WHERE au.is_active=1 ORDER BY au.full_name");
$empMap = [];
foreach ($employees as $emp) { $empMap[$emp['id']] = $emp; }

// ══════════════════════════════════════════════
// Build order->employee mapping
// First: find who first touched each order via activity_logs
// ══════════════════════════════════════════════
$ownerMap = []; // order_id => admin_user_id

// Get first significant action per order for orders in date range
try {
    $firstActions = $db->fetchAll("
        SELECT al.entity_id as order_id, al.admin_user_id
        FROM activity_logs al
        INNER JOIN (
            SELECT entity_id, MIN(id) as min_id
            FROM activity_logs
            WHERE entity_type = 'orders'
              AND action IN ('confirm_order','update','update_status','order_created','order_status_changed')
            GROUP BY entity_id
        ) first ON al.id = first.min_id
        INNER JOIN orders o ON o.id = al.entity_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
    ", [$dateFrom, $dateTo]);
    foreach ($firstActions as $fa) {
        $ownerMap[intval($fa['order_id'])] = intval($fa['admin_user_id']);
    }
} catch (\Throwable $e) {}

// Get all orders in date range
$allOrders = [];
try {
    $allOrders = $db->fetchAll("
        SELECT id, order_status, total, channel, is_preorder, created_at
        FROM orders WHERE DATE(created_at) BETWEEN ? AND ?
    ", [$dateFrom, $dateTo]);
} catch (\Throwable $e) {
    // Fallback without is_preorder
    try {
        $allOrders = $db->fetchAll("
            SELECT id, order_status, total, channel, 0 as is_preorder, created_at
            FROM orders WHERE DATE(created_at) BETWEEN ? AND ?
        ", [$dateFrom, $dateTo]);
    } catch (\Throwable $e2) {}
}

// ══════════════════════════════════════════════
// Aggregate stats per employee
// ══════════════════════════════════════════════
$statusList = ['pending','processing','confirmed','ready_to_ship','shipped','delivered','cancelled','returned','on_hold','no_response','advance_payment','incomplete'];
$channelList = ['website','facebook','phone','whatsapp','instagram','landing_page'];

$empStats = [];

// Initialize all employees + unassigned
$initRow = function($name, $code) use ($statusList, $channelList) {
    $row = ['name'=>$name, 'emp_code'=>$code, 'total'=>0, 'total_money'=>0, 'preorder'=>0];
    foreach ($statusList as $s) { $row[$s] = 0; $row[$s.'_money'] = 0; }
    foreach ($channelList as $c) { $row['ch_'.$c] = 0; $row['ch_'.$c.'_money'] = 0; }
    return $row;
};

foreach ($employees as $emp) {
    $empStats[$emp['id']] = $initRow($emp['full_name'], $emp['emp_code'] ?: $emp['id']);
}
$empStats[0] = $initRow('Unassigned', '—');

// Aggregate orders
foreach ($allOrders as $o) {
    $oid = intval($o['id']);
    $eid = $ownerMap[$oid] ?? 0;
    if (!isset($empStats[$eid])) $eid = 0;
    
    if ($employeeId && $eid !== $employeeId) continue;
    
    $status = $o['order_status'] ?? 'pending';
    $total = floatval($o['total'] ?? 0);
    $channel = $o['channel'] ?? 'website';
    
    $empStats[$eid]['total']++;
    $empStats[$eid]['total_money'] += $total;
    if (!empty($o['is_preorder'])) $empStats[$eid]['preorder']++;
    
    if (isset($empStats[$eid][$status])) { $empStats[$eid][$status]++; $empStats[$eid][$status.'_money'] += $total; }
    if (isset($empStats[$eid]['ch_'.$channel])) { $empStats[$eid]['ch_'.$channel]++; $empStats[$eid]['ch_'.$channel.'_money'] += $total; }
}

// Sort by total desc
uasort($empStats, fn($a,$b) => $b['total'] - $a['total']);

// Grand totals
$gt = $initRow('Total', '');
foreach ($empStats as $es) {
    $gt['total'] += $es['total']; $gt['total_money'] += $es['total_money']; $gt['preorder'] += $es['preorder'];
    foreach ($statusList as $s) { $gt[$s] += $es[$s]; $gt[$s.'_money'] += $es[$s.'_money']; }
    foreach ($channelList as $c) { $gt['ch_'.$c] += $es['ch_'.$c]; $gt['ch_'.$c.'_money'] += $es['ch_'.$c.'_money']; }
}

// ══════════════════════════════════════════════
// Tab-specific data
// ══════════════════════════════════════════════
$productStats = [];
if ($tab === 'products') {
    try {
        $productStats = $db->fetchAll("
            SELECT oi.product_id, COALESCE(p.name, oi.product_name) as product_name, oi.variant_name,
                   SUM(oi.quantity) as qty, SUM(oi.subtotal) as revenue, o.id as order_id
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
              AND o.order_status NOT IN ('cancelled','returned')
            GROUP BY oi.product_id, oi.variant_name
            ORDER BY qty DESC LIMIT 100
        ", [$dateFrom, $dateTo]);
    } catch (\Throwable $e) {}
}

$workLog = [];
if ($tab === 'worklog') {
    try {
        $workLog = $db->fetchAll("
            SELECT au.full_name, al.action, al.entity_type, al.entity_id, al.created_at, al.admin_user_id
            FROM activity_logs al
            LEFT JOIN admin_users au ON au.id = al.admin_user_id
            WHERE DATE(al.created_at) BETWEEN ? AND ?
            " . ($employeeId ? " AND al.admin_user_id = ".intval($employeeId) : "") . "
            ORDER BY al.created_at DESC LIMIT 300
        ", [$dateFrom, $dateTo]);
    } catch (\Throwable $e) {}
}

$cancellations = [];
if ($tab === 'cancellations') {
    try {
        $cancellations = $db->fetchAll("
            SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.total, o.order_status, o.created_at,
                osh.changed_by, au.full_name as cancelled_by, osh.created_at as action_at, osh.note
            FROM order_status_history osh
            JOIN orders o ON o.id = osh.order_id
            LEFT JOIN admin_users au ON au.id = osh.changed_by
            WHERE osh.status IN ('cancelled','returned')
              AND DATE(osh.created_at) BETWEEN ? AND ?
            " . ($employeeId ? " AND osh.changed_by = ".intval($employeeId) : "") . "
            ORDER BY osh.created_at DESC LIMIT 200
        ", [$dateFrom, $dateTo]);
    } catch (\Throwable $e) {}
}

$completions = [];
if ($tab === 'completions') {
    try {
        $completions = $db->fetchAll("
            SELECT o.id, o.order_number, o.customer_name, o.total, o.order_status,
                osh.changed_by, au.full_name as completed_by, osh.created_at as action_at
            FROM order_status_history osh
            JOIN orders o ON o.id = osh.order_id
            LEFT JOIN admin_users au ON au.id = osh.changed_by
            WHERE osh.status IN ('delivered','shipped')
              AND DATE(osh.created_at) BETWEEN ? AND ?
            " . ($employeeId ? " AND osh.changed_by = ".intval($employeeId) : "") . "
            ORDER BY osh.created_at DESC LIMIT 200
        ", [$dateFrom, $dateTo]);
    } catch (\Throwable $e) {}
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="employee-report-'.$tab.'-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    if ($tab === 'status') {
        fputcsv($out, ['Employee','ID','Total','Money','Pending','Processing','Confirmed','RTS','Shipped','Delivered','Cancelled','Returned','Preorder']);
        foreach ($empStats as $es) {
            if ($es['total'] === 0) continue;
            fputcsv($out, [$es['name'],$es['emp_code'],$es['total'],$es['total_money'],$es['pending'],$es['processing'],$es['confirmed'],$es['ready_to_ship'],$es['shipped'],$es['delivered'],$es['cancelled'],$es['returned'],$es['preorder']]);
        }
    }
    fclose($out); exit;
}

require_once __DIR__ . '/../includes/header.php';

function qs($ov=[]) {
    global $dateFrom,$dateTo,$showMoney,$employeeId,$tab;
    $b=['from'=>$dateFrom,'to'=>$dateTo,'money'=>$showMoney?'1':'0','tab'=>$tab];
    if($employeeId) $b['employee']=$employeeId;
    return http_build_query(array_merge($b,$ov));
}
function pct($n,$t){ return $t>0?round($n/$t*100,1):0; }
?>

<style>
.etab{display:inline-flex;align-items:center;gap:5px;padding:10px 16px;font-size:13px;font-weight:500;color:#64748b;border-bottom:2px solid transparent;white-space:nowrap;transition:all .15s}
.etab:hover{color:#334155;background:#f8fafc}
.etab.act{color:#0d9488;border-color:#0d9488;font-weight:600}
.etab svg{width:15px;height:15px}
.et td,.et th{padding:10px 12px;font-size:13px;border-bottom:1px solid #f1f5f9}
.et thead th{position:sticky;top:0;background:#f8fafc;z-index:2;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.3px;color:#64748b;cursor:pointer;user-select:none}
.et thead th:hover{background:#f0fdfa}
.et tbody tr:hover{background:#f0fdfa}
.et .totals-row td{font-weight:700;background:#f8fafc;border-top:2px solid #e2e8f0;font-size:13px}
.tgl{position:relative;width:40px;height:22px;background:#e2e8f0;border-radius:11px;cursor:pointer;transition:background .2s;flex-shrink:0}
.tgl.on{background:#0d9488}
.tgl::after{content:'';position:absolute;top:2px;left:2px;width:18px;height:18px;background:#fff;border-radius:50%;transition:left .2s;box-shadow:0 1px 2px rgba(0,0,0,.15)}
.tgl.on::after{left:20px}
.cpct{font-size:10px;color:#94a3b8}
.cmny{font-size:10px;color:#0d9488}
</style>

<!-- ═══════ TABS ═══════ -->
<div class="bg-white rounded-t-xl border border-b-0 flex overflow-x-auto">
    <a href="?<?= qs(['tab'=>'status']) ?>" class="etab <?= $tab==='status'?'act':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 12l2 2 4-4"/></svg> Employee Report
    </a>
    <a href="?<?= qs(['tab'=>'sources']) ?>" class="etab <?= $tab==='sources'?'act':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg> Order Sources
    </a>
    <a href="?<?= qs(['tab'=>'products']) ?>" class="etab <?= $tab==='products'?'act':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v3"/></svg> Product Report
    </a>
    <a href="?<?= qs(['tab'=>'cancellations']) ?>" class="etab <?= $tab==='cancellations'?'act':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> Order Cancellations
    </a>
    <a href="?<?= qs(['tab'=>'worklog']) ?>" class="etab <?= $tab==='worklog'?'act':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Work Log
    </a>
    <a href="?<?= qs(['tab'=>'completions']) ?>" class="etab <?= $tab==='completions'?'act':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Order Completions
    </a>
</div>

<!-- ═══════ TOOLBAR ═══════ -->
<div class="bg-white border border-t-0 rounded-b-xl p-4 mb-5">
    <div class="flex flex-wrap items-center gap-3">
        <h2 class="text-base font-bold text-gray-800">Advanced Employee Report</h2>
        <div class="flex items-center gap-2.5 ml-auto">
            <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-600" onclick="toggleMoney()">
                <div class="tgl <?= $showMoney?'on':'' ?>" id="mt"></div>
                <span class="font-medium">Show Money</span>
            </label>
            <div data-kb-datepicker data-from-param="from" data-to-param="to"></div>
            <select onchange="fEmp(this.value)" class="border rounded-lg px-2.5 py-1.5 text-xs">
                <option value="">All Employees</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>" <?= $employeeId==$emp['id']?'selected':'' ?>><?= e($emp['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <a href="?<?= qs(['export'=>'csv']) ?>" class="flex items-center gap-1 px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-xs font-medium hover:bg-gray-200" title="Download CSV">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> Download
            </a>
        </div>
    </div>
</div>

<?php // ══════ TAB: ORDER STATUS ══════ ?>
<?php if ($tab === 'status'): ?>
<div class="bg-white rounded-xl border shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full et" id="et">
            <thead><tr>
                <th class="text-left" onclick="srt(0)">Employee</th>
                <th class="text-center" onclick="srt(1)">Total</th>
                <?php if($showMoney):?><th class="text-center" onclick="srt(2)">Total Money</th><?php endif;?>
                <th class="text-center">Pending</th>
                <th class="text-center">Processing</th>
                <th class="text-center">Confirmed</th>
                <th class="text-center">Shipped</th>
                <th class="text-center" style="color:#16a34a">Delivered</th>
                <th class="text-center" style="color:#dc2626">Cancelled</th>
                <th class="text-center" style="color:#ea580c">Returned</th>
                <th class="text-center">Preorder</th>
            </tr></thead>
            <tbody>
            <?php foreach ($empStats as $eid => $es):
                if ($es['total'] === 0 && !$employeeId) continue;
                $t = max($es['total'],1);
            ?>
            <tr>
                <td><div class="font-medium text-gray-800"><?= e($es['name']) ?></div><div class="text-[10px] text-gray-400">ID: <?= e($es['emp_code']) ?></div></td>
                <td class="text-center font-semibold"><?= $es['total'] ?></td>
                <?php if($showMoney):?><td class="text-center font-semibold text-teal-600">৳<?= number_format($es['total_money']) ?></td><?php endif;?>
                <?php foreach(['pending','processing','confirmed','shipped'] as $s): ?>
                <td class="text-center"><?= $es[$s] ?><br><span class="cpct">(<?= pct($es[$s],$t) ?>%)</span><?php if($showMoney && $es[$s.'_money']):?><br><span class="cmny">৳<?= number_format($es[$s.'_money']) ?></span><?php endif;?></td>
                <?php endforeach;?>
                <td class="text-center text-green-600 font-semibold"><?= $es['delivered'] ?><br><span class="cpct">(<?= pct($es['delivered'],$t) ?>%)</span><?php if($showMoney && $es['delivered_money']):?><br><span class="cmny">৳<?= number_format($es['delivered_money']) ?></span><?php endif;?></td>
                <td class="text-center text-red-600 font-semibold"><?= $es['cancelled'] ?><br><span class="cpct">(<?= pct($es['cancelled'],$t) ?>%)</span></td>
                <td class="text-center text-orange-600"><?= $es['returned'] ?><br><span class="cpct">(<?= pct($es['returned'],$t) ?>%)</span></td>
                <td class="text-center"><?= $es['preorder'] ?><br><span class="cpct">(<?= pct($es['preorder'],$t) ?>%)</span></td>
            </tr>
            <?php endforeach;?>
            <tr class="totals-row">
                <td>Total</td>
                <td class="text-center"><?= $gt['total'] ?></td>
                <?php if($showMoney):?><td class="text-center text-teal-600">৳<?= number_format($gt['total_money']) ?></td><?php endif;?>
                <td class="text-center"><?= $gt['pending'] ?></td>
                <td class="text-center"><?= $gt['processing'] ?></td>
                <td class="text-center"><?= $gt['confirmed'] ?></td>
                <td class="text-center"><?= $gt['shipped'] ?></td>
                <td class="text-center text-green-600"><?= $gt['delivered'] ?></td>
                <td class="text-center text-red-600"><?= $gt['cancelled'] ?></td>
                <td class="text-center text-orange-600"><?= $gt['returned'] ?></td>
                <td class="text-center"><?= $gt['preorder'] ?></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>

<?php // ══════ TAB: ORDER SOURCES ══════ ?>
<?php elseif ($tab === 'sources'): ?>
<div class="bg-white rounded-xl border shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full et" id="et">
            <thead><tr>
                <th class="text-left">Employee</th>
                <th class="text-center">Total</th>
                <?php if($showMoney):?><th class="text-center">Money</th><?php endif;?>
                <th class="text-center">🌐 Web</th>
                <th class="text-center">📘 Facebook</th>
                <th class="text-center">📞 Phone</th>
                <th class="text-center">💬 WhatsApp</th>
                <th class="text-center">📸 Insta</th>
                <th class="text-center">📄 Landing</th>
            </tr></thead>
            <tbody>
            <?php foreach ($empStats as $eid => $es):
                if ($es['total'] === 0 && !$employeeId) continue;
                $t = max($es['total'],1);
            ?>
            <tr>
                <td><div class="font-medium text-gray-800"><?= e($es['name']) ?></div><div class="text-[10px] text-gray-400">ID: <?= e($es['emp_code']) ?></div></td>
                <td class="text-center font-semibold"><?= $es['total'] ?></td>
                <?php if($showMoney):?><td class="text-center font-semibold text-teal-600">৳<?= number_format($es['total_money']) ?></td><?php endif;?>
                <?php foreach($channelList as $c): ?>
                <td class="text-center"><?= $es['ch_'.$c] ?><br><span class="cpct">(<?= pct($es['ch_'.$c],$t) ?>%)</span><?php if($showMoney && $es['ch_'.$c.'_money']):?><br><span class="cmny">৳<?= number_format($es['ch_'.$c.'_money']) ?></span><?php endif;?></td>
                <?php endforeach;?>
            </tr>
            <?php endforeach;?>
            <tr class="totals-row">
                <td>Total</td>
                <td class="text-center"><?= $gt['total'] ?></td>
                <?php if($showMoney):?><td class="text-center text-teal-600">৳<?= number_format($gt['total_money']) ?></td><?php endif;?>
                <?php foreach($channelList as $c):?><td class="text-center"><?= $gt['ch_'.$c] ?></td><?php endforeach;?>
            </tr>
            </tbody>
        </table>
    </div>
</div>

<?php // ══════ TAB: PRODUCT REPORT ══════ ?>
<?php elseif ($tab === 'products'): ?>
<div class="bg-white rounded-xl border shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full et">
            <thead><tr>
                <th class="text-left">#</th>
                <th class="text-left">Product</th>
                <th class="text-left">Variant</th>
                <th class="text-center">Qty Sold</th>
                <?php if($showMoney):?><th class="text-right">Revenue</th><?php endif;?>
            </tr></thead>
            <tbody>
            <?php if($productStats): $i=1; foreach($productStats as $ps): ?>
            <tr>
                <td class="text-gray-400"><?= $i++ ?></td>
                <td class="font-medium"><?= e($ps['product_name'] ?? 'Unknown') ?></td>
                <td class="text-gray-500"><?= e($ps['variant_name'] ?: '—') ?></td>
                <td class="text-center font-semibold"><?= $ps['qty'] ?></td>
                <?php if($showMoney):?><td class="text-right text-teal-600 font-medium">৳<?= number_format($ps['revenue']??0) ?></td><?php endif;?>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5" class="py-8 text-center text-gray-400">No product data for this period</td></tr>
            <?php endif;?>
            </tbody>
        </table>
    </div>
</div>

<?php // ══════ TAB: CANCELLATIONS ══════ ?>
<?php elseif ($tab === 'cancellations'): ?>
<div class="bg-white rounded-xl border shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full et">
            <thead><tr>
                <th class="text-left">Order</th>
                <th class="text-left">Customer</th>
                <th class="text-left">Phone</th>
                <th class="text-center">Status</th>
                <?php if($showMoney):?><th class="text-right">Amount</th><?php endif;?>
                <th class="text-left">By</th>
                <th class="text-left">Date</th>
                <th class="text-left">Reason</th>
            </tr></thead>
            <tbody>
            <?php if($cancellations): foreach($cancellations as $c): ?>
            <tr>
                <td><a href="<?= adminUrl('pages/order-view.php?id='.$c['id']) ?>" class="text-blue-600 font-medium hover:underline">#<?= e($c['order_number']) ?></a></td>
                <td class="text-gray-700"><?= e($c['customer_name']) ?></td>
                <td class="text-gray-500 text-xs"><?= e($c['customer_phone']) ?></td>
                <td class="text-center"><span class="px-2 py-0.5 text-xs rounded-full <?= $c['order_status']==='cancelled'?'bg-red-100 text-red-700':'bg-orange-100 text-orange-700' ?>"><?= ucfirst($c['order_status']) ?></span></td>
                <?php if($showMoney):?><td class="text-right font-medium">৳<?= number_format($c['total']) ?></td><?php endif;?>
                <td class="font-medium text-gray-700"><?= e($c['cancelled_by'] ?? 'System') ?></td>
                <td class="text-gray-500 text-xs whitespace-nowrap"><?= date('d M, h:i A', strtotime($c['action_at'])) ?></td>
                <td class="text-gray-500 text-xs max-w-[180px] truncate" title="<?= e($c['note']??'') ?>"><?= e($c['note'] ?: '—') ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="8" class="py-8 text-center text-gray-400">No cancellations/returns in this period</td></tr>
            <?php endif;?>
            </tbody>
        </table>
    </div>
</div>

<?php // ══════ TAB: ORDER COMPLETIONS ══════ ?>
<?php elseif ($tab === 'completions'): ?>
<div class="bg-white rounded-xl border shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full et">
            <thead><tr>
                <th class="text-left">Order</th>
                <th class="text-left">Customer</th>
                <th class="text-center">Status</th>
                <?php if($showMoney):?><th class="text-right">Amount</th><?php endif;?>
                <th class="text-left">Completed By</th>
                <th class="text-left">Date</th>
            </tr></thead>
            <tbody>
            <?php if($completions): foreach($completions as $c): ?>
            <tr>
                <td><a href="<?= adminUrl('pages/order-view.php?id='.$c['id']) ?>" class="text-blue-600 font-medium hover:underline">#<?= e($c['order_number']) ?></a></td>
                <td class="text-gray-700"><?= e($c['customer_name']) ?></td>
                <td class="text-center"><span class="px-2 py-0.5 text-xs rounded-full <?= $c['order_status']==='delivered'?'bg-green-100 text-green-700':'bg-purple-100 text-purple-700' ?>"><?= ucfirst($c['order_status']) ?></span></td>
                <?php if($showMoney):?><td class="text-right font-medium">৳<?= number_format($c['total']) ?></td><?php endif;?>
                <td class="font-medium text-gray-700"><?= e($c['completed_by'] ?? 'System') ?></td>
                <td class="text-gray-500 text-xs whitespace-nowrap"><?= date('d M, h:i A', strtotime($c['action_at'])) ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="6" class="py-8 text-center text-gray-400">No completions in this period</td></tr>
            <?php endif;?>
            </tbody>
        </table>
    </div>
</div>

<?php // ══════ TAB: WORK LOG ══════ ?>
<?php elseif ($tab === 'worklog'): ?>
<?php
$wlByEmp = [];
foreach ($workLog as $w) {
    $aid = $w['admin_user_id'];
    if (!isset($wlByEmp[$aid])) $wlByEmp[$aid] = ['name'=>$w['full_name']??'Unknown','cnt'=>0];
    $wlByEmp[$aid]['cnt']++;
}
uasort($wlByEmp, fn($a,$b)=>$b['cnt']-$a['cnt']);
?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <?php foreach(array_slice($wlByEmp,0,4,true) as $wl): ?>
    <div class="bg-white rounded-xl border p-4">
        <div class="font-semibold text-gray-800 text-sm"><?= e($wl['name']) ?></div>
        <div class="text-2xl font-bold text-teal-600 mt-1"><?= $wl['cnt'] ?></div>
        <div class="text-xs text-gray-400">actions</div>
    </div>
    <?php endforeach;?>
</div>
<div class="bg-white rounded-xl border shadow-sm">
    <div class="overflow-x-auto max-h-[500px] overflow-y-auto">
        <table class="w-full et">
            <thead class="sticky top-0"><tr>
                <th class="text-left">Time</th>
                <th class="text-left">Employee</th>
                <th class="text-left">Action</th>
                <th class="text-left">Entity</th>
                <th class="text-left">ID</th>
            </tr></thead>
            <tbody>
            <?php foreach($workLog as $w): ?>
            <tr>
                <td class="text-gray-500 text-xs whitespace-nowrap"><?= date('d M, h:i:s A', strtotime($w['created_at'])) ?></td>
                <td class="font-medium text-gray-700 text-sm"><?= e($w['full_name'] ?? 'Unknown') ?></td>
                <td><span class="px-2 py-0.5 text-[11px] rounded-full bg-blue-50 text-blue-700 font-medium"><?= str_replace('_',' ',$w['action']) ?></span></td>
                <td class="text-gray-500 text-xs"><?= $w['entity_type'] ?></td>
                <td><?php if($w['entity_type']==='orders'&&$w['entity_id']):?><a href="<?= adminUrl('pages/order-view.php?id='.$w['entity_id']) ?>" class="text-blue-600 hover:underline text-xs">#<?= $w['entity_id'] ?></a><?php else:?><span class="text-xs text-gray-400"><?= $w['entity_id']?:'—' ?></span><?php endif;?></td>
            </tr>
            <?php endforeach; if(!$workLog):?>
            <tr><td colspan="5" class="py-8 text-center text-gray-400">No activity in this period</td></tr>
            <?php endif;?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function toggleMoney(){const u=new URL(location);u.searchParams.set('money',(u.searchParams.get('money')||'1')==='1'?'0':'1');location=u}
function fEmp(v){const u=new URL(location);if(v)u.searchParams.set('employee',v);else u.searchParams.delete('employee');location=u}
function srt(c){
    const t=document.getElementById('et');if(!t)return;
    const tb=t.querySelector('tbody');
    const rows=Array.from(tb.querySelectorAll('tr:not(.totals-row)'));
    const tr=tb.querySelector('.totals-row');
    const d=t.dataset.sd==='a'?'d':'a';t.dataset.sd=d;
    rows.sort((a,b)=>{
        let av=(a.cells[c]?.innerText||'').replace(/[৳,%\s\n]/g,'');
        let bv=(b.cells[c]?.innerText||'').replace(/[৳,%\s\n]/g,'');
        const an=parseFloat(av),bn=parseFloat(bv);
        if(!isNaN(an)&&!isNaN(bn))return d==='a'?an-bn:bn-an;
        return d==='a'?av.localeCompare(bv):bv.localeCompare(av);
    });
    rows.forEach(r=>tb.appendChild(r));
    if(tr)tb.appendChild(tr);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
