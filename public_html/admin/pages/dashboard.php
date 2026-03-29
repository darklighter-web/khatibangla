<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';

$db = Database::getInstance();
$adminId = getAdminId();

// ── Date Filtering (Overview) ──
$dateFrom = $_GET['from'] ?? date('Y-m-d');
$dateTo   = $_GET['to'] ?? date('Y-m-d');
$isToday  = ($dateFrom === date('Y-m-d') && $dateTo === date('Y-m-d'));
$statusFilter = $_GET['sf'] ?? 'approved'; // approved | all

// Doughnut chart periods (independent)
$webPeriod = $_GET['wp'] ?? '30'; // Web Order Report period days
$srcPeriod = $_GET['sp'] ?? '30'; // Orders by Source period days
$srcFilter = $_GET['ssf'] ?? 'approved'; // Source approved/all

// Date label
if ($isToday) { $dateLabel = 'Today'; }
elseif ($dateFrom === $dateTo) { $dateLabel = date('d M Y', strtotime($dateFrom)); }
else { $dateLabel = date('d M', strtotime($dateFrom)) . ' – ' . date('d M Y', strtotime($dateTo)); }

// ── Overview Stats ──
$ovExclude = $statusFilter === 'approved' ? " AND o.order_status NOT IN ('cancelled','returned')" : "";

$dStats = [];
try {
    $dStats['orders']  = intval($db->fetch("SELECT COUNT(*) as c FROM orders o WHERE DATE(o.created_at) BETWEEN ? AND ? $ovExclude", [$dateFrom, $dateTo])['c'] ?? 0);
    $dStats['revenue'] = floatval($db->fetch("SELECT COALESCE(SUM(o.total),0) as r FROM orders o WHERE DATE(o.created_at) BETWEEN ? AND ? $ovExclude", [$dateFrom, $dateTo])['r'] ?? 0);
    // Profit = revenue - cost (cost_price is on products table)
    $dStats['cost'] = floatval($db->fetch("SELECT COALESCE(SUM(oi.quantity * COALESCE(p.cost_price,0)),0) as c FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN products p ON p.id=oi.product_id WHERE DATE(o.created_at) BETWEEN ? AND ? $ovExclude", [$dateFrom, $dateTo])['c'] ?? 0);
    $dStats['profit'] = $dStats['revenue'] - $dStats['cost'];
    // Pending web orders = all pending/processing orders (not just website channel)
    $dStats['pending_web'] = intval($db->fetch("SELECT COUNT(*) as c FROM orders WHERE order_status IN ('pending','processing')")['c'] ?? 0);
} catch (\Throwable $e) {
    $dStats = ['orders'=>0,'revenue'=>0,'cost'=>0,'profit'=>0,'pending_web'=>0];
}

// Previous period for % change comparison
$daysDiff = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400 + 1);
$prevTo = date('Y-m-d', strtotime($dateFrom . " -1 day"));
$prevFrom = date('Y-m-d', strtotime($prevTo . " -" . ($daysDiff - 1) . " days"));

$prevStats = [];
try {
    $prevStats['orders']  = intval($db->fetch("SELECT COUNT(*) as c FROM orders o WHERE DATE(o.created_at) BETWEEN ? AND ? $ovExclude", [$prevFrom, $prevTo])['c'] ?? 0);
    $prevStats['revenue'] = floatval($db->fetch("SELECT COALESCE(SUM(o.total),0) as r FROM orders o WHERE DATE(o.created_at) BETWEEN ? AND ? $ovExclude", [$prevFrom, $prevTo])['r'] ?? 0);
    $prevStats['cost']    = floatval($db->fetch("SELECT COALESCE(SUM(oi.quantity * COALESCE(p.cost_price,0)),0) as c FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN products p ON p.id=oi.product_id WHERE DATE(o.created_at) BETWEEN ? AND ? $ovExclude", [$prevFrom, $prevTo])['c'] ?? 0);
    $prevStats['profit']  = $prevStats['revenue'] - $prevStats['cost'];
} catch (\Throwable $e) {
    $prevStats = ['orders'=>0,'revenue'=>0,'cost'=>0,'profit'=>0];
}

function pctChange($cur, $prev) {
    if ($prev == 0) return $cur > 0 ? 100 : 0;
    return round(($cur - $prev) / abs($prev) * 100, 1);
}

$chgOrders  = pctChange($dStats['orders'], $prevStats['orders']);
$chgRevenue = pctChange($dStats['revenue'], $prevStats['revenue']);
$chgProfit  = pctChange($dStats['profit'], $prevStats['profit']);

// ── Web Order Report (by status) — OWN period, ALL statuses always ──
$webFrom = date('Y-m-d', strtotime("-{$webPeriod} days"));
$webTo   = date('Y-m-d');
$webOrdersByStatus = [];
try {
    $webOrdersByStatus = $db->fetchAll("
        SELECT order_status, COUNT(*) as cnt 
        FROM orders WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY order_status ORDER BY cnt DESC
    ", [$webFrom, $webTo]);
} catch (\Throwable $e) {}
$webStatusTotal = array_sum(array_column($webOrdersByStatus, 'cnt'));

// ── Orders by Source (channel) — OWN period, OWN approved filter ──
$srcFrom = date('Y-m-d', strtotime("-{$srcPeriod} days"));
$srcTo   = date('Y-m-d');
$srcExclude = $srcFilter === 'approved' ? " AND order_status NOT IN ('cancelled','returned')" : "";
$ordersBySource = [];
try {
    $ordersBySource = $db->fetchAll("
        SELECT COALESCE(NULLIF(channel,''),'unknown') as channel, COUNT(*) as cnt
        FROM orders WHERE DATE(created_at) BETWEEN ? AND ? $srcExclude
        GROUP BY channel ORDER BY cnt DESC
    ", [$srcFrom, $srcTo]);
} catch (\Throwable $e) {}
$sourceTotal = array_sum(array_column($ordersBySource, 'cnt'));

// ── Incomplete Orders (for source chart) ──
$incompleteCount = 0;
try {
    // Detect column name
    $recCol = 'is_recovered';
    try { $db->fetch("SELECT is_recovered FROM incomplete_orders LIMIT 1"); } catch (\Throwable $e) { $recCol = 'recovered'; }
    $incompleteCount = intval($db->fetch("SELECT COUNT(*) as c FROM incomplete_orders WHERE {$recCol}=0 AND DATE(created_at) BETWEEN ? AND ?", [$srcFrom, $srcTo])['c'] ?? 0);
} catch (\Throwable $e) {}

// Determine if user can see admin metrics
$canSeeMetrics = isSuperAdmin() || hasPermission('dashboard.view');

// Auto-close stale sessions
try { $db->query("UPDATE employee_sessions SET clock_out = DATE_ADD(clock_in, INTERVAL 8 HOUR), hours_worked = 8, status = 'auto_closed' WHERE status = 'active' AND clock_in < DATE_SUB(NOW(), INTERVAL 16 HOUR)"); } catch (\Throwable $e) {}

// ── Employee Session Data ──
$activeSession = null; $todaySessions = []; $todayHours = 0;
try {
    $activeSession = $db->fetch("SELECT * FROM employee_sessions WHERE admin_user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$adminId]);
    $todaySessions = $db->fetchAll("SELECT * FROM employee_sessions WHERE admin_user_id = ? AND DATE(clock_in) = CURDATE() ORDER BY clock_in", [$adminId]);
    foreach ($todaySessions as $s) {
        $todayHours += ($s['status'] === 'active') ? (time() - strtotime($s['clock_in'])) / 3600 : floatval($s['hours_worked']);
    }
} catch (\Throwable $e) {}

$todayActivities = [];
try { $todayActivities = $db->fetchAll("SELECT action, entity_type, entity_id, created_at FROM activity_logs WHERE admin_user_id = ? AND DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 30", [$adminId]); } catch (\Throwable $e) {}

// ── Team Attendance ──
$teamAttendance = []; $teamLeaves = [];
if ($canSeeMetrics) {
    try {
        $teamAttendance = $db->fetchAll(
            "SELECT au.id, au.full_name, au.avatar, ar.role_name,
                es.clock_in, es.status as session_status,
                (SELECT SUM(CASE WHEN s2.status='active' THEN TIMESTAMPDIFF(SECOND, s2.clock_in, NOW())/3600 ELSE s2.hours_worked END) FROM employee_sessions s2 WHERE s2.admin_user_id = au.id AND DATE(s2.clock_in) = CURDATE()) as today_hours,
                (SELECT COUNT(*) FROM activity_logs al WHERE al.admin_user_id = au.id AND DATE(al.created_at) = CURDATE()) as today_actions
             FROM admin_users au
             LEFT JOIN admin_roles ar ON ar.id = au.role_id
             LEFT JOIN employee_sessions es ON es.admin_user_id = au.id AND es.status = 'active'
             WHERE au.is_active = 1 ORDER BY es.status DESC, au.full_name"
        );
    } catch (\Throwable $e) {}
    try { $teamLeaves = $db->fetchAll("SELECT el.*, au.full_name FROM employee_leaves el JOIN admin_users au ON au.id = el.admin_user_id WHERE el.leave_date >= CURDATE() ORDER BY el.leave_date LIMIT 10"); } catch (\Throwable $e) {}
}

function actionLabel($action) {
    $map = ['login'=>'🔑 লগইন','logout'=>'🚪 লগআউট','clock_in'=>'⏰ ক্লক ইন','clock_out'=>'⏰ ক্লক আউট','order_created'=>'📦 অর্ডার তৈরি','order_status_changed'=>'🔄 স্ট্যাটাস পরিবর্তন','product_created'=>'➕ পণ্য তৈরি','product_updated'=>'✏️ পণ্য আপডেট','employee_saved'=>'👤 কর্মী সেভ','role_saved'=>'🛡️ রোল সেভ'];
    return $map[$action] ?? '📝 ' . str_replace('_', ' ', $action);
}

// Status display map
$statusLabels = [
    'pending'=>'Pending','processing'=>'Processing','confirmed'=>'Confirmed','ready_to_ship'=>'RTS',
    'shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled','returned'=>'Returned',
    'on_hold'=>'On Hold','no_response'=>'No Response','good_but_no_response'=>'Good No Resp',
    'advance_payment'=>'Advance Payment','incomplete'=>'Incomplete'
];
$statusColors = [
    'pending'=>'#f59e0b','processing'=>'#ef4444','confirmed'=>'#3b82f6','ready_to_ship'=>'#8b5cf6',
    'shipped'=>'#a855f7','delivered'=>'#10b981','cancelled'=>'#6b7280','returned'=>'#f97316',
    'on_hold'=>'#64748b','no_response'=>'#eab308','good_but_no_response'=>'#1e293b',
    'advance_payment'=>'#f97316','incomplete'=>'#ef4444'
];
$channelColors = [
    'website'=>'#10b981','facebook'=>'#3b82f6','phone'=>'#8b5cf6','whatsapp'=>'#22c55e',
    'instagram'=>'#ec4899','landing_page'=>'#f59e0b','unknown'=>'#eab308','direct'=>'#1e293b','exchange'=>'#1e293b'
];
?>

<?php if (!isSuperAdmin()): ?>
<!-- ═══════ EMPLOYEE SESSION PANEL ═══════ -->
<div class="panel-card mb-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h3 class="text-sm font-semibold text-gray-700 mb-1">🕐 আজকের সেশন</h3>
            <p class="text-xs text-gray-400"><?= date('l, d F Y') ?></p>
        </div>
        <div class="flex items-center gap-3">
            <?php if ($activeSession): ?>
                <span class="relative flex h-3 w-3"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span></span>
                <span class="text-sm text-green-700 font-medium">ক্লক ইন: <?= date('h:i A', strtotime($activeSession['clock_in'])) ?></span>
                <span id="liveTimer" class="text-lg font-bold text-green-800 font-mono" data-start="<?= strtotime($activeSession['clock_in']) ?>">00:00:00</span>
                <button onclick="clockOut()" class="px-4 py-2 bg-red-500 text-white rounded-lg text-sm font-bold hover:bg-red-600 transition">ক্লক আউট</button>
            <?php else: ?>
                <span class="text-sm text-gray-500">সেশন চলছে না</span>
                <button onclick="clockIn()" class="px-4 py-2 bg-green-500 text-white rounded-lg text-sm font-bold hover:bg-green-600 transition">ক্লক ইন</button>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($todaySessions)): ?>
    <div class="mt-4 pt-4 border-t">
        <div class="grid grid-cols-3 gap-4 text-center mb-3">
            <div class="bg-blue-50 rounded-lg p-3"><p class="text-xl font-bold text-blue-700"><?= count($todaySessions) ?></p><p class="text-[10px] text-blue-500 uppercase">Sessions</p></div>
            <div class="bg-green-50 rounded-lg p-3"><p class="text-xl font-bold text-green-700"><?= number_format($todayHours, 1) ?>h</p><p class="text-[10px] text-green-500 uppercase">Hours</p></div>
            <div class="bg-purple-50 rounded-lg p-3"><p class="text-xl font-bold text-purple-700"><?= count($todayActivities) ?></p><p class="text-[10px] text-purple-500 uppercase">Actions</p></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>


<?php if ($canSeeMetrics): ?>
<!-- ═══════════════════════════════════════════ -->
<!-- ADMIN/SUPER ADMIN DASHBOARD -->
<!-- ═══════════════════════════════════════════ -->
<?php
$recentOrders = getRecentOrders(10);

// Sales chart data
$daysDiffChart = (strtotime($dateTo) - strtotime($dateFrom)) / 86400 + 1;
$chartData = ($daysDiffChart <= 31)
    ? $db->fetchAll("SELECT DATE(created_at) as date, COUNT(*) as orders, COALESCE(SUM(total),0) as revenue FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND order_status NOT IN ('cancelled','returned') GROUP BY DATE(created_at) ORDER BY date", [$dateFrom, $dateTo])
    : $db->fetchAll("SELECT MIN(DATE(created_at)) as date, COUNT(*) as orders, COALESCE(SUM(total),0) as revenue FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND order_status NOT IN ('cancelled','returned') GROUP BY YEARWEEK(created_at,1) ORDER BY date", [$dateFrom, $dateTo]);

$topProducts = [];
try { $topProducts = $db->fetchAll("SELECT p.name, SUM(oi.quantity) as sold FROM order_items oi JOIN products p ON p.id = oi.product_id JOIN orders o ON o.id = oi.order_id WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.order_status NOT IN ('cancelled','returned') GROUP BY p.id ORDER BY sold DESC LIMIT 5", [$dateFrom, $dateTo]); } catch (\Throwable $e) {}

$deliveredPeriod = intval($db->fetch("SELECT COUNT(*) as c FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND order_status='delivered'", [$dateFrom, $dateTo])['c'] ?? 0);
$shippedPeriod   = intval($db->fetch("SELECT COUNT(*) as c FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND order_status IN ('shipped','delivered','returned')", [$dateFrom, $dateTo])['c'] ?? 0);
$returnedPeriod  = intval($db->fetch("SELECT COUNT(*) as c FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND order_status='returned'", [$dateFrom, $dateTo])['c'] ?? 0);
$deliveryRate    = $shippedPeriod > 0 ? round(($deliveredPeriod / $shippedPeriod) * 100, 1) : 0;
?>

<!-- Team Attendance -->
<?php if (!empty($teamAttendance)): ?>
<div class="panel-card mb-6">
    <div class="section-header">
        <h3>👥 টিম উপস্থিতি — আজ</h3>
        <a href="<?= adminUrl('pages/employees.php?tab=performance') ?>" class="view-all">Full Report →</a>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
        <?php foreach ($teamAttendance as $ta):
            $isOnline = !empty($ta['session_status']) && $ta['session_status'] === 'active';
            $hrs = round(floatval($ta['today_hours'] ?? 0), 1);
            $onLeave = false;
            foreach ($teamLeaves as $lv) { if ($lv['admin_user_id'] == $ta['id'] && $lv['leave_date'] == date('Y-m-d')) { $onLeave = true; break; } }
        ?>
        <div class="flex items-center gap-3 p-3 rounded-lg <?= $onLeave ? 'bg-orange-50 border border-orange-200' : ($isOnline ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border border-gray-100') ?>">
            <div class="relative flex-shrink-0">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-400 to-purple-500 flex items-center justify-center text-white text-sm font-bold"><?= strtoupper(substr($ta['full_name'] ?? '?', 0, 1)) ?></div>
                <?php if ($isOnline): ?><span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-green-500 border-2 border-white rounded-full"></span><?php elseif ($onLeave): ?><span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-orange-500 border-2 border-white rounded-full"></span><?php endif; ?>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-semibold text-gray-800 truncate"><?= e($ta['full_name']) ?></p>
                <p class="text-[10px] <?= $onLeave ? 'text-orange-600' : ($isOnline ? 'text-green-600' : 'text-gray-400') ?>">
                    <?= $onLeave ? '🏖️ ছুটিতে' : ($isOnline ? '🟢 অনলাইন · '.$hrs.'h' : ($hrs > 0 ? '⏸️ '.$hrs.'h done' : '⚫ অফলাইন')) ?>
                </p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($teamLeaves)): ?>
    <div class="mt-3 pt-3 border-t"><p class="text-xs text-gray-500 mb-2">📅 আসন্ন ছুটি:</p><div class="flex flex-wrap gap-2"><?php foreach ($teamLeaves as $lv): ?><span class="inline-flex items-center gap-1 text-[11px] bg-orange-50 text-orange-700 px-2 py-1 rounded-full border border-orange-200"><?= e($lv['full_name']) ?> — <?= date('d M', strtotime($lv['leave_date'])) ?> <?php if (isSuperAdmin()): ?><button onclick="removeLeave(<?= $lv['id'] ?>)" class="ml-0.5 text-orange-400 hover:text-red-500 font-bold">×</button><?php endif; ?></span><?php endforeach; ?></div></div>
    <?php endif; ?>
    <?php if (isSuperAdmin()): ?>
    <div class="mt-3 pt-3 border-t flex flex-wrap items-center gap-2">
        <select id="leaveUser" class="text-xs border rounded-lg px-2 py-1.5"><?php foreach ($teamAttendance as $ta): ?><option value="<?= $ta['id'] ?>"><?= e($ta['full_name']) ?></option><?php endforeach; ?></select>
        <input type="date" id="leaveDate" value="<?= date('Y-m-d') ?>" class="text-xs border rounded-lg px-2 py-1.5">
        <select id="leaveType" class="text-xs border rounded-lg px-2 py-1.5"><option value="casual">Casual</option><option value="sick">Sick</option><option value="annual">Annual</option></select>
        <button onclick="markLeave()" class="text-xs bg-orange-500 text-white px-3 py-1.5 rounded-lg hover:bg-orange-600 font-medium">📅 ছুটি দিন</button>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════ -->
<!-- OVERVIEW (SmartHR-inspired) -->
<!-- ═══════════════════════════════════════════ -->
<div class="section-header">
    <h3>Overview</h3>
    <div class="flex items-center gap-2">
        <select onchange="window.location=updateParam('sf',this.value)" class="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 text-gray-600 bg-white">
            <option value="approved" <?= $statusFilter==='approved'?'selected':'' ?>>Approved</option>
            <option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All Orders</option>
        </select>
        <div data-kb-datepicker data-from-param="from" data-to-param="to" data-preserve-params="sf,wp,sp,ssf"></div>
    </div>
</div>
<div class="panel-grid panel-grid-4 mb-6">
    <?php
    $cards = [
        ['Total Orders', $dStats['orders'], $chgOrders, '#FF6B35', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>'],
        ['Total Sales', '৳'.number_format($dStats['revenue']), $chgRevenue, '#3B82F6', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
        ['Profit', '৳'.number_format($dStats['profit']), $chgProfit, '#10B981', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>'],
        ['Pending Orders', $dStats['pending_web'], null, '#F59E0B', '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
    ];
    foreach ($cards as $c):
        $isUp = ($c[2] ?? 0) >= 0; $pctVal = $c[2];
    ?>
    <div class="panel-card">
        <div class="flex items-start justify-between mb-3">
            <div class="stat-icon" style="background:<?= $c[3] ?>15">
                <svg fill="none" stroke="<?= $c[3] ?>" viewBox="0 0 24 24"><?= $c[4] ?></svg>
            </div>
            <?php if ($pctVal !== null): ?>
            <div class="flex items-center gap-1 px-2 py-0.5 rounded-full <?= $isUp ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-500' ?>" style="font-size:11px;font-weight:600">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="<?= $isUp ? 'M5 10l7-7m0 0l7 7m-7-7v18' : 'M19 14l-7 7m0 0l-7-7m7 7V3' ?>"/></svg>
                <?= abs($pctVal) ?>%
            </div>
            <?php endif; ?>
        </div>
        <p class="card-sub"><?= $c[0] ?></p>
        <p class="card-value"><?= $c[1] ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════ -->
<!-- WEB ORDER REPORT + ORDERS BY SOURCE (doughnuts) -->
<!-- ═══════════════════════════════════════════ -->
<div class="grid md:grid-cols-2 gap-6 mb-6">
    <!-- Web Order Report -->
    <div class="panel-card">
        <div class="section-header">
            <h3>Web Order Report</h3>
            <select onchange="window.location=updateParam('wp',this.value)" class="text-xs border border-gray-200 rounded-lg px-2.5 py-1.5 text-gray-600 bg-white">
                <option value="7" <?= $webPeriod==='7'?'selected':'' ?>>7D</option>
                <option value="30" <?= $webPeriod==='30'?'selected':'' ?>>30D</option>
                <option value="90" <?= $webPeriod==='90'?'selected':'' ?>>90D</option>
                <option value="365" <?= $webPeriod==='365'?'selected':'' ?>>1Y</option>
            </select>
        </div>
        <?php if ($webStatusTotal > 0): ?>
        <div class="flex items-center gap-5">
            <div class="relative flex-shrink-0" data-doughnut style="width:160px;height:160px;">
                <canvas id="statusDoughnut" width="160" height="160"></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-base font-bold text-gray-800"><?= number_format($webStatusTotal) ?></span>
                    <span class="text-[9px] text-gray-400">Total Orders</span>
                </div>
            </div>
            <div class="flex flex-col gap-1.5 min-w-0 flex-1">
                <?php foreach ($webOrdersByStatus as $ws):
                    $lbl = $statusLabels[$ws['order_status']] ?? ucfirst(str_replace('_',' ',$ws['order_status']));
                    $clr = $statusColors[$ws['order_status']] ?? '#94a3b8';
                ?>
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= $clr ?>"></span>
                    <span class="text-gray-600 truncate"><?= $lbl ?></span>
                    <span class="text-gray-800 font-semibold ml-auto">(<?= $ws['cnt'] ?>)</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <p class="text-sm text-gray-400 text-center py-8">No orders in this period</p>
        <?php endif; ?>
    </div>

    <!-- Orders by Source — own period + own approved filter -->
    <div class="panel-card">
        <div class="flex items-center justify-between mb-4">
            <h3 class="card-title" style="font-size:14px;font-weight:700;color:#1a1a2e">Orders by Source</h3>
            <div class="flex items-center gap-2">
                <select onchange="window.location=updateParam('ssf',this.value)" class="text-xs border rounded-lg px-2 py-1.5 text-gray-600">
                    <option value="approved" <?= $srcFilter==='approved'?'selected':'' ?>>Approved</option>
                    <option value="all" <?= $srcFilter==='all'?'selected':'' ?>>All</option>
                </select>
                <select onchange="window.location=updateParam('sp',this.value)" class="text-xs border rounded-lg px-2 py-1.5 text-gray-600">
                    <option value="7" <?= $srcPeriod==='7'?'selected':'' ?>>7D</option>
                    <option value="30" <?= $srcPeriod==='30'?'selected':'' ?>>30D</option>
                    <option value="90" <?= $srcPeriod==='90'?'selected':'' ?>>90D</option>
                    <option value="365" <?= $srcPeriod==='365'?'selected':'' ?>>1Y</option>
                </select>
            </div>
        </div>
        <?php if ($sourceTotal > 0): ?>
        <div class="flex items-center gap-5">
            <div class="relative flex-shrink-0" data-doughnut style="width:160px;height:160px;">
                <canvas id="sourceDoughnut" width="160" height="160"></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-base font-bold text-gray-800"><?= number_format($sourceTotal) ?></span>
                    <span class="text-[9px] text-gray-400">Total Orders</span>
                </div>
            </div>
            <div class="flex flex-col gap-1.5 min-w-0 flex-1">
                <?php foreach ($ordersBySource as $os):
                    $ch = strtoupper(str_replace('_',' ',$os['channel']));
                    $clr = $channelColors[$os['channel']] ?? '#94a3b8';
                ?>
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= $clr ?>"></span>
                    <span class="text-gray-600"><?= $ch ?></span>
                    <span class="text-gray-800 font-semibold ml-auto">(<?= $os['cnt'] ?>)</span>
                </div>
                <?php endforeach; ?>
                <?php if ($incompleteCount > 0): ?>
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 bg-red-500"></span>
                    <span class="text-gray-600">INCOMPLETE</span>
                    <span class="text-gray-800 font-semibold ml-auto">(<?= $incompleteCount ?>)</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <p class="text-sm text-gray-400 text-center py-8">No orders in this period</p>
        <?php endif; ?>
    </div>
</div>

<!-- Order Pipeline -->
<div class="panel-card mb-6">
    <div class="section-header" style="margin-bottom:1.25rem">
        <h3>Order Pipeline</h3>
        <span class="card-sub"><?= $dateLabel ?></span>
    </div>
    <div class="grid grid-cols-4 md:grid-cols-8 gap-3">
        <?php
        $pipeline = [
            ['Pending',$stats['pending_orders'],'#eab308','#fefce8'],
            ['Confirmed',$stats['confirmed_orders'],'#3b82f6','#eff6ff'],
            ['Ready',$stats['processing_orders'],'#6366f1','#eef2ff'],
            ['Shipped',$stats['shipped_orders'],'#8b5cf6','#f5f3ff'],
            ['Delivered',$stats['delivered_orders'],'#10b981','#ecfdf5'],
            ['Cancelled',$stats['cancelled_orders'],'#ef4444','#fef2f2'],
            ['Returned',$stats['returned_orders'],'#f97316','#fff7ed'],
            ['Fake',$stats['fake_orders'],'#6b7280','#f9fafb'],
        ];
        foreach ($pipeline as $p): ?>
        <div style="background:<?=$p[3]?>;border-radius:12px;padding:14px 12px;text-align:center;border:1px solid <?=$p[2]?>15">
            <p style="font-size:1.5rem;font-weight:700;color:<?=$p[2]?>;line-height:1"><?= $p[1] ?></p>
            <p style="font-size:10px;color:<?=$p[2]?>;margin-top:6px;font-weight:500;opacity:.8"><?= $p[0] ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Sales Chart + Delivery Meter -->
<div class="grid md:grid-cols-2 gap-6 mb-6">
    <div class="panel-card">
        <h3 class="card-title" style="font-size:14px;font-weight:700;color:#1a1a2e;margin-bottom:1rem">Sales — <?= $dateLabel ?></h3>
        <div style="position:relative;height:180px;"><canvas id="salesChart"></canvas></div>
    </div>
    <div class="panel-card">
        <h3 class="card-title" style="font-size:14px;font-weight:700;color:#1a1a2e;margin-bottom:1rem">Delivery Success Meter</h3>
        <div class="flex items-center justify-center py-2">
            <div class="relative w-32 h-32">
                <svg class="w-full h-full transform -rotate-90" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="42" fill="none" stroke="#e5e7eb" stroke-width="10"/>
                    <circle cx="50" cy="50" r="42" fill="none" stroke="<?= $deliveryRate >= 80 ? '#10b981' : ($deliveryRate >= 50 ? '#f59e0b' : '#ef4444') ?>" stroke-width="10" stroke-dasharray="<?= 2.64 * $deliveryRate ?> 264" stroke-linecap="round"/>
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-xl font-bold text-gray-800"><?= $deliveryRate ?>%</span>
                    <span class="text-[9px] text-gray-500">Success</span>
                </div>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-3 mt-4 text-center">
            <div style="background:#ecfdf5;border-radius:10px;padding:10px"><p style="font-size:18px;font-weight:700;color:#10b981"><?= $deliveredPeriod ?></p><p style="font-size:10px;color:#059669;margin-top:2px">Delivered</p></div>
            <div style="background:#f5f3ff;border-radius:10px;padding:10px"><p style="font-size:18px;font-weight:700;color:#8b5cf6"><?= $shippedPeriod ?></p><p style="font-size:10px;color:#7c3aed;margin-top:2px">Shipped</p></div>
            <div style="background:#fff7ed;border-radius:10px;padding:10px"><p style="font-size:18px;font-weight:700;color:#f97316"><?= $returnedPeriod ?></p><p style="font-size:10px;color:#ea580c;margin-top:2px">Returned</p></div>
        </div>
    </div>
</div>

<!-- Info Grid -->
<div class="grid md:grid-cols-3 gap-6 mb-6">
    <div class="panel-card">
        <div class="section-header"><h3>Inventory Alerts</h3></div>
        <div style="display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#f8fafc;border-radius:10px"><div style="display:flex;align-items:center;gap:8px"><div class="stat-icon" style="width:32px;height:32px;border-radius:8px;background:#3b82f615"><svg width="16" height="16" fill="none" stroke="#3b82f6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg></div><span style="font-size:13px;color:#374151">Total Products</span></div><span style="font-size:15px;font-weight:700;color:#1a1a2e"><?= $stats['total_products'] ?></span></div>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#fef2f2;border-radius:10px"><div style="display:flex;align-items:center;gap:8px"><div style="width:32px;height:32px;border-radius:8px;background:#ef444415;display:flex;align-items:center;justify-content:center"><svg width="16" height="16" fill="none" stroke="#ef4444" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg></div><span style="font-size:13px;color:#dc2626;font-weight:500">Low Stock</span></div><span style="font-size:15px;font-weight:700;color:#dc2626"><?= $stats['low_stock'] ?></span></div>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#f8fafc;border-radius:10px"><div style="display:flex;align-items:center;gap:8px"><div style="width:32px;height:32px;border-radius:8px;background:#8b5cf615;display:flex;align-items:center;justify-content:center"><svg width="16" height="16" fill="none" stroke="#8b5cf6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><span style="font-size:13px;color:#374151">Customers</span></div><span style="font-size:15px;font-weight:700;color:#1a1a2e"><?= $stats['total_customers'] ?></span></div>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#f8fafc;border-radius:10px"><div style="display:flex;align-items:center;gap:8px"><div style="width:32px;height:32px;border-radius:8px;background:#ef444415;display:flex;align-items:center;justify-content:center"><svg width="16" height="16" fill="none" stroke="#ef4444" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg></div><span style="font-size:13px;color:#374151">Blocked</span></div><span style="font-size:15px;font-weight:700;color:#dc2626"><?= $stats['blocked_customers'] ?></span></div>
        </div>
    </div>
    <div class="panel-card">
        <div class="section-header"><h3>Fraud Prevention</h3></div>
        <div style="display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#fef2f2;border-radius:10px"><div style="display:flex;align-items:center;gap:8px"><div style="width:32px;height:32px;border-radius:8px;background:#ef444415;display:flex;align-items:center;justify-content:center"><svg width="16" height="16" fill="none" stroke="#ef4444" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg></div><span style="font-size:13px;color:#dc2626;font-weight:500">Fake Orders</span></div><span style="font-size:15px;font-weight:700;color:#dc2626"><?= $stats['fake_orders'] ?></span></div>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#f8fafc;border-radius:10px"><div style="display:flex;align-items:center;gap:8px"><div style="width:32px;height:32px;border-radius:8px;background:#6b728015;display:flex;align-items:center;justify-content:center"><svg width="16" height="16" fill="none" stroke="#6b7280" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg></div><span style="font-size:13px;color:#374151">Blocked Customers</span></div><span style="font-size:15px;font-weight:700;color:#1a1a2e"><?= $stats['blocked_customers'] ?></span></div>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#fefce8;border-radius:10px"><div style="display:flex;align-items:center;gap:8px"><div style="width:32px;height:32px;border-radius:8px;background:#eab30815;display:flex;align-items:center;justify-content:center"><svg width="16" height="16" fill="none" stroke="#eab308" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><span style="font-size:13px;color:#854d0e;font-weight:500">Incomplete Orders</span></div><span style="font-size:15px;font-weight:700;color:#854d0e"><?= $stats['incomplete_orders'] ?></span></div>
        </div>
    </div>
    <div class="panel-card">
        <div class="section-header"><h3>Top Products — <?= $dateLabel ?></h3></div>
        <div style="display:flex;flex-direction:column;gap:8px">
            <?php foreach (array_slice($topProducts, 0, 5) as $idx => $tp): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#f8fafc;border-radius:8px">
                <span style="width:22px;height:22px;border-radius:6px;background:var(--th-primary-light);color:var(--th-primary);font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center"><?= $idx+1 ?></span>
                <span style="flex:1;font-size:13px;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($tp['name']) ?></span>
                <span style="font-size:12px;font-weight:600;color:#10b981;white-space:nowrap"><?= $tp['sold'] ?> sold</span>
            </div>
            <?php endforeach; if (empty($topProducts)): ?><p style="font-size:13px;color:#9ca3af;text-align:center;padding:20px 0">No sales data</p><?php endif; ?>
        </div>
    </div>
</div>

<!-- Area Analytics -->
<div class="panel-card mb-6">
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center"><span class="text-white text-sm">📊</span></div>
            <div><h3 class="card-title" style="font-size:14px;font-weight:700;color:#1a1a2e">Delivery Area Analytics</h3><p class="text-[11px] text-gray-400" id="areaSubtitle">Loading...</p></div>
        </div>
        <div class="flex items-center gap-2">
            <select id="dashAreaDays" onchange="loadDashAreaAnalytics()" class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs text-gray-600 bg-gray-50">
                <option value="30">30 days</option><option value="90" selected>90 days</option><option value="180">180 days</option><option value="365">1 year</option>
            </select>
            <a href="<?= adminUrl('pages/courier.php?tab=area_map') ?>" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Full Report →</a>
        </div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5" id="areaSummaryCards">
        <div class="bg-gray-50 rounded-lg p-3 animate-pulse"><div class="h-4 bg-gray-200 rounded w-16 mb-2"></div><div class="h-6 bg-gray-200 rounded w-10"></div></div>
        <div class="bg-gray-50 rounded-lg p-3 animate-pulse"><div class="h-4 bg-gray-200 rounded w-16 mb-2"></div><div class="h-6 bg-gray-200 rounded w-10"></div></div>
        <div class="bg-gray-50 rounded-lg p-3 animate-pulse"><div class="h-4 bg-gray-200 rounded w-16 mb-2"></div><div class="h-6 bg-gray-200 rounded w-10"></div></div>
        <div class="bg-gray-50 rounded-lg p-3 animate-pulse"><div class="h-4 bg-gray-200 rounded w-16 mb-2"></div><div class="h-6 bg-gray-200 rounded w-10"></div></div>
    </div>
    <div class="grid md:grid-cols-5 gap-5 mb-5">
        <div class="md:col-span-2 flex flex-col items-center">
            <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-3">Order Distribution</p>
            <div class="relative" data-doughnut style="width:160px;height:160px;">
                <canvas id="areaDoughnutChart" width="160" height="160"></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-base font-bold text-gray-800" id="doughnutCenter">-</span>
                    <span class="text-[9px] text-gray-400">total orders</span>
                </div>
            </div>
        </div>
        <div class="md:col-span-3">
            <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-3">Success Rate by Area</p>
            <div data-chart-h="180" style="position:relative;height:180px;"><canvas id="areaBarChart"></canvas></div>
        </div>
    </div>
    <div id="dashAreaList" class="text-sm text-gray-400">
        <div class="grid md:grid-cols-2 gap-x-6 gap-y-1">
            <div class="animate-pulse py-2"><div class="h-3 bg-gray-100 rounded w-full"></div></div>
            <div class="animate-pulse py-2"><div class="h-3 bg-gray-100 rounded w-full"></div></div>
        </div>
    </div>
</div>

<!-- Recent Orders -->
<div class="panel-card">
    <div class="section-header"><h3>Recent Orders</h3><a href="<?= adminUrl('pages/order-management.php') ?>" class="view-all">View All →</a></div>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Order</th><th>Customer</th><th>Phone</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
            <tbody class="divide-y">
                <?php foreach ($recentOrders as $order): ?>
                <tr class="hover:bg-gray-50">
                    <td class="py-3"><a href="<?= adminUrl('pages/order-view.php?id='.$order['id']) ?>" class="text-blue-600 font-medium hover:underline">#<?= e($order['order_number']) ?></a></td>
                    <td class="py-3"><?= e($order['customer_name']) ?></td>
                    <td class="py-3"><?= e($order['customer_phone']) ?></td>
                    <td class="py-3 font-medium">৳<?= number_format($order['total']) ?></td>
                    <td class="py-3"><span class="px-2 py-1 text-xs rounded-full font-medium <?= getOrderStatusBadge($order['order_status']) ?>"><?= getOrderStatusLabel($order['order_status']) ?></span></td>
                    <td class="py-3 text-gray-500"><?= date('M d, h:i A', strtotime($order['created_at'])) ?></td>
                </tr>
                <?php endforeach; if (empty($recentOrders)): ?><tr><td colspan="6" class="py-8 text-center text-gray-400">No orders yet</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════ CHARTS JS ═══════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function updateParam(k,v){const u=new URL(location);u.searchParams.set(k,v);return u.toString();}

// Sales Bar+Line Chart (container-based height)
const chartData = <?= json_encode($chartData) ?>;
const labels = chartData.map(d => new Date(d.date).toLocaleDateString('en-US',{month:'short',day:'numeric'}));
new Chart(document.getElementById('salesChart'), {
    type:'bar',
    data:{labels, datasets:[
        {label:'Revenue (৳)', data:chartData.map(d=>d.revenue), backgroundColor:'rgba(59,130,246,0.5)', borderColor:'rgb(59,130,246)', borderWidth:1, borderRadius:4},
        {label:'Orders', data:chartData.map(d=>d.orders), type:'line', borderColor:'rgb(16,185,129)', borderWidth:2, fill:false, yAxisID:'y1', tension:0.4, pointRadius:2}
    ]},
    options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{intersect:false, mode:'index'},
        plugins:{legend:{display:true, position:'bottom', labels:{boxWidth:8, font:{size:10}}}},
        scales:{
            y:{beginAtZero:true, ticks:{font:{size:9}}},
            y1:{beginAtZero:true, position:'right', grid:{drawOnChartArea:false}, ticks:{font:{size:9}}}
        }
    }
});

// Status Doughnut (fixed-size square container)
const statusData = <?= json_encode($webOrdersByStatus) ?>;
const statusMap = <?= json_encode($statusLabels) ?>;
const statusClrs = <?= json_encode($statusColors) ?>;
if (statusData.length > 0) {
    new Chart(document.getElementById('statusDoughnut'), {
        type:'doughnut',
        data:{
            labels:statusData.map(s=>statusMap[s.order_status]||s.order_status),
            datasets:[{data:statusData.map(s=>s.cnt), backgroundColor:statusData.map(s=>statusClrs[s.order_status]||'#94a3b8'), borderWidth:2, borderColor:'#fff'}]
        },
        options:{responsive:true, maintainAspectRatio:true, cutout:'60%', plugins:{legend:{display:false}}}
    });
}

// Source Doughnut (fixed-size square container)
const sourceData = <?= json_encode($ordersBySource) ?>;
const srcClrs = <?= json_encode($channelColors) ?>;
if (sourceData.length > 0) {
    new Chart(document.getElementById('sourceDoughnut'), {
        type:'doughnut',
        data:{
            labels:sourceData.map(s=>(s.channel||'unknown').toUpperCase()),
            datasets:[{data:sourceData.map(s=>s.cnt), backgroundColor:sourceData.map(s=>srcClrs[s.channel]||'#94a3b8'), borderWidth:2, borderColor:'#fff'}]
        },
        options:{responsive:true, maintainAspectRatio:true, cutout:'60%', plugins:{legend:{display:false}}}
    });
}

// Area Analytics (async loaded)
let _doughnutChart = null, _barChart = null;
const _areaColors = ['#6366f1','#8b5cf6','#a78bfa','#c4b5fd','#818cf8','#7c3aed','#4f46e5','#6d28d9','#5b21b6','#4338ca'];

async function loadDashAreaAnalytics(){
    const days = document.getElementById('dashAreaDays')?.value || 90;
    try {
        const res = await fetch('<?= SITE_URL ?>/api/pathao-api.php?action=area_stats&days='+days);
        const json = await res.json();
        const data = json.data || [];
        document.getElementById('areaSubtitle').textContent = data.length > 0 ? data.length+' areas · Last '+days+' days' : 'No area data';
        if (!data.length) { document.getElementById('areaSummaryCards').innerHTML='<div class="col-span-4 text-center py-6 text-gray-400">No area data yet</div>'; return; }

        const totalOrders=data.reduce((s,a)=>s+parseInt(a.total_orders),0);
        const totalDelivered=data.reduce((s,a)=>s+parseInt(a.delivered),0);
        const totalFailed=data.reduce((s,a)=>s+parseInt(a.failed),0);
        const totalRevenue=data.reduce((s,a)=>s+parseFloat(a.revenue||0),0);
        const overallSuccess=totalOrders>0?Math.round((totalDelivered/totalOrders)*100):0;

        document.getElementById('areaSummaryCards').innerHTML = `
            <div class="bg-indigo-50 rounded-lg p-3"><p class="text-[10px] text-indigo-500 uppercase font-semibold">Areas</p><p class="text-xl font-bold text-indigo-700 mt-1">${data.length}</p></div>
            <div class="bg-blue-50 rounded-lg p-3"><p class="text-[10px] text-blue-500 uppercase font-semibold">Orders</p><p class="text-xl font-bold text-blue-700 mt-1">${totalOrders.toLocaleString()}</p></div>
            <div class="bg-green-50 rounded-lg p-3"><p class="text-[10px] text-green-500 uppercase font-semibold">Success</p><p class="text-xl font-bold text-green-700 mt-1">${overallSuccess}%</p><p class="text-[10px] text-green-400 mt-0.5">${totalDelivered.toLocaleString()} delivered</p></div>
            <div class="bg-purple-50 rounded-lg p-3"><p class="text-[10px] text-purple-500 uppercase font-semibold">Revenue</p><p class="text-xl font-bold text-purple-700 mt-1">৳${totalRevenue.toLocaleString('en',{maximumFractionDigits:0})}</p></div>
        `;

        // Area Doughnut
        const top8 = data.slice(0,8);
        if (_doughnutChart) _doughnutChart.destroy();
        _doughnutChart = new Chart(document.getElementById('areaDoughnutChart'), {
            type:'doughnut',
            data:{labels:top8.map(a=>a.area_name), datasets:[{data:top8.map(a=>parseInt(a.total_orders)), backgroundColor:_areaColors.slice(0,8), borderWidth:2, borderColor:'#fff'}]},
            options:{responsive:true, maintainAspectRatio:true, cutout:'55%', plugins:{legend:{display:false}}}
        });
        document.getElementById('doughnutCenter').textContent = totalOrders.toLocaleString();

        // Area Bar Chart (container has fixed height)
        if (_barChart) _barChart.destroy();
        _barChart = new Chart(document.getElementById('areaBarChart'), {
            type:'bar',
            data:{
                labels:top8.map(a=>a.area_name.length>14?a.area_name.slice(0,12)+'…':a.area_name),
                datasets:[
                    {label:'Delivered', data:top8.map(a=>parseInt(a.delivered)), backgroundColor:'rgba(34,197,94,0.75)', borderRadius:3},
                    {label:'In Transit', data:top8.map(a=>parseInt(a.total_orders)-parseInt(a.delivered)-parseInt(a.failed)), backgroundColor:'rgba(234,179,8,0.5)', borderRadius:3},
                    {label:'Failed', data:top8.map(a=>parseInt(a.failed)), backgroundColor:'rgba(239,68,68,0.65)', borderRadius:3}
                ]
            },
            options:{
                indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins:{legend:{display:true, position:'top', labels:{boxWidth:8, padding:6, font:{size:9}}}},
                scales:{x:{stacked:true, grid:{display:false}, ticks:{font:{size:9}}}, y:{stacked:true, grid:{display:false}, ticks:{font:{size:9}}}}
            }
        });

        // Area list
        const mx = Math.max(...data.map(d=>parseInt(d.total_orders)));
        document.getElementById('dashAreaList').innerHTML = `
            <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-3">All Areas</p>
            <div class="grid md:grid-cols-2 gap-x-6 gap-y-1">${data.slice(0,12).map((a,i)=>{
                const pct=Math.round((parseInt(a.total_orders)/mx)*100);
                const sp=parseInt(a.total_orders)>0?Math.round((parseInt(a.delivered)/parseInt(a.total_orders))*100):0;
                const clr=sp>=70?'green':sp>=40?'yellow':'red';
                return `<div class="flex items-center gap-2 py-1.5 hover:bg-gray-50 rounded-md px-1">
                    <span class="w-5 text-[10px] text-gray-300 font-mono">${i+1}</span>
                    <span class="w-28 text-xs text-gray-700 font-medium truncate">${a.area_name}</span>
                    <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden flex"><div class="h-full bg-green-500 rounded-l-full" style="width:${sp*pct/100}%"></div></div>
                    <span class="text-[10px] text-gray-500 w-8 text-right font-medium">${a.total_orders}</span>
                    <span class="text-[10px] font-bold w-9 text-right text-${clr}-600">${sp}%</span>
                </div>`;
            }).join('')}</div>`;
    } catch(e) {
        console.error('Area analytics error:', e);
        document.getElementById('areaSummaryCards').innerHTML='<div class="col-span-4 text-center py-4 text-gray-400">Area analytics unavailable</div>';
    }
}
loadDashAreaAnalytics();
</script>

<?php else: ?>
<!-- EMPLOYEE LIMITED VIEW -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 text-center">
    <h3 class="text-lg font-semibold text-gray-700 mb-1">স্বাগতম, <?= e(getAdminName()) ?>!</h3>
    <p class="text-sm text-gray-500 mb-4">আপনার অনুমতি অনুযায়ী সাইডবার থেকে কাজের প্যানেলে যান।</p>
    <div class="flex flex-wrap justify-center gap-3">
        <?php if (canViewPage('order-management')): ?><a href="<?= adminUrl('pages/order-management.php') ?>" class="px-4 py-2 bg-blue-50 text-blue-600 rounded-lg text-sm font-medium hover:bg-blue-100">📦 অর্ডার</a><?php endif; ?>
        <?php if (canViewPage('products')): ?><a href="<?= adminUrl('pages/products.php') ?>" class="px-4 py-2 bg-purple-50 text-purple-600 rounded-lg text-sm font-medium hover:bg-purple-100">📋 পণ্য</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Session JS -->
<script>
const SESSION_API = '<?= SITE_URL ?>/api/employee-session.php';
const timerEl = document.getElementById('liveTimer');
if (timerEl) {
    const startTs = parseInt(timerEl.dataset.start) * 1000;
    setInterval(() => {
        const d = Date.now() - startTs;
        timerEl.textContent = String(Math.floor(d/3600000)).padStart(2,'0')+':'+String(Math.floor((d%3600000)/60000)).padStart(2,'0')+':'+String(Math.floor((d%60000)/1000)).padStart(2,'0');
    }, 1000);
}
function clockIn(){fetch(SESSION_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=clock_in'}).then(r=>r.json()).then(d=>{if(d.success)location.reload();else alert(d.message)})}
function clockOut(){const n=prompt('আজকের কাজের সারসংক্ষেপ:')||'';fetch(SESSION_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=clock_out&notes='+encodeURIComponent(n)}).then(r=>r.json()).then(d=>{if(d.success)location.reload();else alert(d.message)})}
function markLeave(){const u=document.getElementById('leaveUser').value,d=document.getElementById('leaveDate').value,t=document.getElementById('leaveType').value;fetch(SESSION_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=mark_leave&user_id=${u}&leave_date=${d}&leave_type=${t}`}).then(r=>r.json()).then(d=>{if(d.success)location.reload();else alert(d.message)})}
async function removeLeave(id){const _ok = await window._confirmAsync('Delete?'); if(!_ok) return;fetch(SESSION_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=remove_leave&id='+id}).then(r=>r.json()).then(d=>{if(d.success)location.reload()})}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
