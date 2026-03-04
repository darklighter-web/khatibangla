<?php
/**
 * Order Report - Comprehensive order analytics
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Order Report';
$db = Database::getInstance();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';

$where = "WHERE DATE(created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($status) { $where .= " AND order_status=?"; $params[] = $status; }

// Summary
$summary = $db->fetch("SELECT COUNT(*) as total, COALESCE(SUM(total),0) as revenue, COALESCE(AVG(total),0) as aov,
    COUNT(CASE WHEN order_status='delivered' THEN 1 END) as delivered,
    COUNT(CASE WHEN order_status='cancelled' THEN 1 END) as cancelled,
    COUNT(CASE WHEN order_status='returned' THEN 1 END) as returned,
    COUNT(CASE WHEN order_status='pending' OR order_status='processing' THEN 1 END) as pending,
    COUNT(CASE WHEN order_status='shipped' THEN 1 END) as shipped
    FROM orders $where", $params);

$deliveryRate = ($summary['total'] ?? 0) > 0 ? round(($summary['delivered'] / $summary['total']) * 100, 1) : 0;

// Status breakdown
$statusBreakdown = $db->fetchAll("SELECT order_status, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev FROM orders $where GROUP BY order_status ORDER BY cnt DESC", $params);

// By channel
$channelData = $db->fetchAll("SELECT channel, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev,
    COUNT(CASE WHEN order_status='delivered' THEN 1 END) as delivered
    FROM orders $where GROUP BY channel ORDER BY cnt DESC", $params);

// By city/district
$cityData = $db->fetchAll("SELECT COALESCE(customer_district, customer_city, 'Unknown') as city, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev
    FROM orders $where GROUP BY city ORDER BY cnt DESC LIMIT 15", $params);

// By courier
$courierData = $db->fetchAll("SELECT COALESCE(courier_name,'No Courier') as courier, COUNT(*) as cnt,
    COUNT(CASE WHEN order_status='delivered' THEN 1 END) as delivered,
    COUNT(CASE WHEN order_status='returned' THEN 1 END) as returned
    FROM orders $where GROUP BY courier_name ORDER BY cnt DESC", $params);

// Daily trend
$dailyOrders = $db->fetchAll("SELECT DATE(created_at) as date, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev FROM orders $where GROUP BY DATE(created_at) ORDER BY date", $params);

// Hourly distribution
$hourlyData = $db->fetchAll("SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM orders $where GROUP BY HOUR(created_at) ORDER BY hr", $params);

// Payment method breakdown
$paymentData = $db->fetchAll("SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev FROM orders $where GROUP BY payment_method ORDER BY cnt DESC", $params);

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="order-report-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Order#', 'Date', 'Customer', 'Phone', 'City', 'Channel', 'Status', 'Courier', 'Total', 'Payment']);
    $allOrders = $db->fetchAll("SELECT * FROM orders $where ORDER BY created_at DESC", $params);
    foreach ($allOrders as $o) {
        fputcsv($output, [$o['order_number'], $o['created_at'], $o['customer_name'], $o['customer_phone'], $o['customer_district'] ?? $o['customer_city'] ?? '', $o['channel'], $o['order_status'], $o['courier_name'] ?? '', $o['total'], $o['payment_method']]);
    }
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="bg-white rounded-xl border shadow-sm p-4 mb-6">
    <form class="flex flex-wrap items-center gap-3">
        <input type="hidden" name="from" value="<?= $dateFrom ?>">
        <input type="hidden" name="to" value="<?= $dateTo ?>">
        <div data-kb-datepicker data-from-param="from" data-to-param="to"></div>
        <select name="status" class="border rounded-lg px-3 py-1.5 text-sm" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <?php foreach (['pending','processing','confirmed','shipped','delivered','cancelled','returned'] as $s): ?>
            <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&status=<?= $status ?>&export=csv" class="ml-auto bg-gray-100 text-gray-700 px-4 py-1.5 rounded-lg text-sm hover:bg-gray-200">Export CSV</a>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-gray-800"><?= number_format($summary['total'] ?? 0) ?></p>
        <p class="text-xs text-gray-500">Total Orders</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-green-600"><?= $summary['delivered'] ?? 0 ?></p>
        <p class="text-xs text-gray-500">Delivered</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-blue-600"><?= $summary['shipped'] ?? 0 ?></p>
        <p class="text-xs text-gray-500">Shipped</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-yellow-600"><?= $summary['pending'] ?? 0 ?></p>
        <p class="text-xs text-gray-500">Pending</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-red-600"><?= $summary['cancelled'] ?? 0 ?></p>
        <p class="text-xs text-gray-500">Cancelled</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center bg-green-50">
        <p class="text-2xl font-bold text-green-700"><?= $deliveryRate ?>%</p>
        <p class="text-xs text-gray-500">Delivery Rate</p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <!-- Daily Trend -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Daily Orders</h4>
        <canvas id="dailyChart" height="150"></canvas>
    </div>

    <!-- Status Breakdown -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Status Breakdown</h4>
        <canvas id="statusChart" height="150"></canvas>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6 mb-6">
    <!-- Channel -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">By Channel</h4>
        <?php foreach ($channelData as $ch): $rate = $ch['cnt'] > 0 ? round(($ch['delivered'] / $ch['cnt'])*100) : 0; ?>
        <div class="flex justify-between items-center py-2 border-b last:border-0 text-sm">
            <span class="capitalize"><?= e($ch['channel'] ?: 'Unknown') ?></span>
            <div class="text-right">
                <span class="font-medium"><?= $ch['cnt'] ?> orders</span>
                <span class="text-xs ml-2 px-1.5 py-0.5 rounded <?= $rate>=60?'bg-green-100 text-green-700':'bg-yellow-100 text-yellow-700' ?>"><?= $rate ?>%</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Courier -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">By Courier</h4>
        <?php foreach ($courierData as $cr): $rate = $cr['cnt'] > 0 ? round(($cr['delivered'] / $cr['cnt'])*100) : 0; ?>
        <div class="flex justify-between items-center py-2 border-b last:border-0 text-sm">
            <span><?= e($cr['courier']) ?></span>
            <div class="text-right">
                <span class="font-medium"><?= $cr['cnt'] ?></span>
                <span class="text-xs ml-1 text-green-600"><?= $cr['delivered'] ?> ✓</span>
                <span class="text-xs ml-1 text-red-600"><?= $cr['returned'] ?> ↩</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Top Cities -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Top Cities</h4>
        <?php foreach ($cityData as $ct): ?>
        <div class="flex justify-between items-center py-2 border-b last:border-0 text-sm">
            <span><?= e($ct['city']) ?></span>
            <div class="text-right">
                <span class="font-medium"><?= $ct['cnt'] ?></span>
                <span class="text-xs text-gray-400 ml-1">৳<?= number_format($ct['rev']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Hourly & Payment -->
<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Hourly Distribution</h4>
        <canvas id="hourlyChart" height="140"></canvas>
    </div>
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Payment Methods</h4>
        <canvas id="paymentChart" height="140"></canvas>
    </div>
</div>

<script>
const dailyData = <?= json_encode($dailyOrders) ?>;
new Chart(document.getElementById('dailyChart'), {
    type: 'line', data: { labels: dailyData.map(d=>{const dt=new Date(d.date);return dt.toLocaleDateString('en-US',{month:'short',day:'numeric'});}), datasets: [{ label:'Orders', data:dailyData.map(d=>d.cnt), borderColor:'#3b82f6', tension:0.4, fill:true, backgroundColor:'rgba(59,130,246,0.1)' }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});

const statusData = <?= json_encode($statusBreakdown) ?>;
const statusColors = {pending:'#eab308',processing:'#3b82f6',confirmed:'#06b6d4',shipped:'#8b5cf6',delivered:'#22c55e',cancelled:'#ef4444',returned:'#f97316',on_hold:'#6b7280'};
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut', data: { labels: statusData.map(s=>s.order_status), datasets: [{ data: statusData.map(s=>s.cnt), backgroundColor: statusData.map(s=>statusColors[s.order_status]||'#9ca3af') }] },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});

const hourlyData = <?= json_encode($hourlyData) ?>;
const hours = Array.from({length:24},(_,i)=>i);
const hourlyCounts = hours.map(h=>{const d=hourlyData.find(x=>parseInt(x.hr)===h);return d?d.cnt:0;});
new Chart(document.getElementById('hourlyChart'), {
    type: 'bar', data: { labels: hours.map(h=>h+':00'), datasets: [{ label:'Orders', data:hourlyCounts, backgroundColor:'rgba(59,130,246,0.5)', borderRadius:4 }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});

const payData = <?= json_encode($paymentData) ?>;
new Chart(document.getElementById('paymentChart'), {
    type: 'pie', data: { labels: payData.map(p=>p.payment_method||'N/A'), datasets: [{ data: payData.map(p=>p.cnt), backgroundColor:['#3b82f6','#22c55e','#f59e0b','#ef4444','#8b5cf6','#06b6d4'] }] },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
