<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Reports & AI Insights';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

$period = $_GET['period'] ?? '30';
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime("-{$period} days"));
$dateTo = $_GET['to'] ?? date('Y-m-d');

// Sales data
$salesData = $db->fetchAll("SELECT DATE(created_at) as date, COUNT(*) as orders, COALESCE(SUM(total),0) as revenue, COALESCE(SUM(shipping_cost),0) as shipping FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND order_status NOT IN ('cancelled','returned') GROUP BY DATE(created_at) ORDER BY date", [$dateFrom, $dateTo]);

// Summary stats
$summary = $db->fetch("SELECT COUNT(*) as total_orders, COALESCE(SUM(total),0) as total_revenue, COALESCE(AVG(total),0) as avg_order, COUNT(DISTINCT customer_id) as unique_customers FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND order_status NOT IN ('cancelled','returned')", [$dateFrom, $dateTo]);

// Top products
$topProducts = $db->fetchAll("SELECT oi.product_name, SUM(oi.quantity) as qty, SUM(oi.subtotal) as rev FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.order_status NOT IN ('cancelled','returned') GROUP BY oi.product_name ORDER BY rev DESC LIMIT 10", [$dateFrom, $dateTo]);

// Status breakdown
$statusBreakdown = $db->fetchAll("SELECT order_status, COUNT(*) as cnt FROM orders WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY order_status", [$dateFrom, $dateTo]);

// Channel breakdown
$channelData = $db->fetchAll("SELECT channel, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND order_status NOT IN ('cancelled','returned') GROUP BY channel", [$dateFrom, $dateTo]);

// Hourly distribution
$hourlyData = $db->fetchAll("SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM orders WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY HOUR(created_at) ORDER BY hr", [$dateFrom, $dateTo]);

// Delivery rate
$delRate = $db->fetch("SELECT COUNT(CASE WHEN order_status='delivered' THEN 1 END) as delivered, COUNT(CASE WHEN order_status IN ('shipped','delivered','returned') THEN 1 END) as total FROM orders WHERE DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
$deliveryRate = $delRate['total'] > 0 ? round(($delRate['delivered'] / $delRate['total']) * 100, 1) : 0;

// Cancellation rate
$cancelRate = $db->fetch("SELECT COUNT(CASE WHEN order_status='cancelled' THEN 1 END) as cancelled, COUNT(*) as total FROM orders WHERE DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
$cancellationRate = $cancelRate['total'] > 0 ? round(($cancelRate['cancelled'] / $cancelRate['total']) * 100, 1) : 0;

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Date Filter -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <div class="flex gap-2">
            <?php foreach (['7'=>'7 Days','30'=>'30 Days','90'=>'90 Days','365'=>'1 Year'] as $d=>$l): ?>
            <a href="?period=<?= $d ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium <?= $period == $d ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>
        <div class="flex items-center gap-2 ml-auto">
            <input type="date" name="from" value="<?= $dateFrom ?>" class="px-3 py-1.5 border rounded-lg text-sm">
            <span class="text-gray-400">to</span>
            <input type="date" name="to" value="<?= $dateTo ?>" class="px-3 py-1.5 border rounded-lg text-sm">
            <button class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-blue-700">Apply</button>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border"><p class="text-2xl font-bold text-gray-800">৳<?= number_format($summary['total_revenue']) ?></p><p class="text-xs text-gray-500 mt-1">Total Revenue</p></div>
    <div class="bg-white rounded-xl p-4 shadow-sm border"><p class="text-2xl font-bold text-gray-800"><?= number_format($summary['total_orders']) ?></p><p class="text-xs text-gray-500 mt-1">Total Orders</p></div>
    <div class="bg-white rounded-xl p-4 shadow-sm border"><p class="text-2xl font-bold text-gray-800">৳<?= number_format($summary['avg_order']) ?></p><p class="text-xs text-gray-500 mt-1">Avg Order Value</p></div>
    <div class="bg-white rounded-xl p-4 shadow-sm border"><p class="text-2xl font-bold text-gray-800"><?= number_format($summary['unique_customers']) ?></p><p class="text-xs text-gray-500 mt-1">Unique Customers</p></div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border"><p class="text-2xl font-bold text-green-600"><?= $deliveryRate ?>%</p><p class="text-xs text-gray-500 mt-1">Delivery Rate</p></div>
    <div class="bg-white rounded-xl p-4 shadow-sm border"><p class="text-2xl font-bold text-red-600"><?= $cancellationRate ?>%</p><p class="text-xs text-gray-500 mt-1">Cancellation Rate</p></div>
    <div class="bg-white rounded-xl p-4 shadow-sm border"><p class="text-2xl font-bold text-gray-800"><?= count($salesData) > 0 ? '৳' . number_format($summary['total_revenue'] / count($salesData)) : '0' ?></p><p class="text-xs text-gray-500 mt-1">Daily Avg Revenue</p></div>
    <div class="bg-white rounded-xl p-4 shadow-sm border"><p class="text-2xl font-bold text-gray-800"><?= count($salesData) > 0 ? round($summary['total_orders'] / count($salesData), 1) : '0' ?></p><p class="text-xs text-gray-500 mt-1">Daily Avg Orders</p></div>
</div>

<!-- Charts Row -->
<div class="grid md:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Revenue Trend</h4>
        <canvas id="revenueChart" height="150"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Order Status Breakdown</h4>
        <canvas id="statusChart" height="150"></canvas>
    </div>
</div>

<div class="grid md:grid-cols-2 gap-6 mb-6">
    <!-- Top Products -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Top Products by Revenue</h4>
        <div class="space-y-3">
            <?php foreach ($topProducts as $i => $tp): ?>
            <div class="flex items-center gap-3">
                <span class="w-6 h-6 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center text-xs font-bold"><?= $i+1 ?></span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-800 truncate"><?= e($tp['product_name']) ?></p>
                    <p class="text-xs text-gray-500"><?= $tp['qty'] ?> units sold</p>
                </div>
                <span class="font-semibold text-sm">৳<?= number_format($tp['rev']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($topProducts)): ?><p class="text-sm text-gray-400">No data</p><?php endif; ?>
        </div>
    </div>

    <!-- Channel & Hourly -->
    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h4 class="font-semibold text-gray-800 mb-4">Sales by Channel</h4>
            <div class="space-y-3">
                <?php foreach ($channelData as $ch): ?>
                <div class="flex items-center justify-between">
                    <span class="text-sm capitalize"><?= e($ch['channel']) ?></span>
                    <div class="text-right"><span class="font-semibold text-sm">৳<?= number_format($ch['rev']) ?></span><span class="text-xs text-gray-400 ml-2">(<?= $ch['cnt'] ?> orders)</span></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h4 class="font-semibold text-gray-800 mb-4">Orders by Hour</h4>
            <canvas id="hourlyChart" height="120"></canvas>
        </div>
    </div>
</div>

<!-- AI Insights -->
<div class="bg-gradient-to-r from-purple-50 to-blue-50 rounded-xl border border-purple-200 p-5 mb-6">
    <h4 class="font-semibold text-purple-800 mb-3 flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
        AI Insights & Recommendations
    </h4>
    <div class="grid md:grid-cols-2 gap-4 text-sm text-gray-700">
        <?php
        $insights = [];
        if ($cancellationRate > 30) $insights[] = ['⚠️', 'High cancellation rate detected (' . $cancellationRate . '%). Consider improving order confirmation calls and fraud detection.'];
        if ($deliveryRate < 70 && $delRate['total'] > 0) $insights[] = ['📦', 'Delivery success rate is below 70%. Review courier performance and customer address validation.'];
        if ($summary['avg_order'] > 0 && $summary['avg_order'] < 500) $insights[] = ['💡', 'Average order value is ৳' . number_format($summary['avg_order']) . '. Consider bundle offers or free shipping thresholds to increase AOV.'];
        if (count($topProducts) > 0) $insights[] = ['🏆', 'Top seller "' . $topProducts[0]['product_name'] . '" generated ৳' . number_format($topProducts[0]['rev']) . '. Consider featuring it more prominently.'];
        if (empty($insights)) $insights[] = ['✅', 'Your store metrics look healthy! Keep monitoring performance and optimizing.'];
        foreach ($insights as $ins): ?>
        <div class="bg-white/60 rounded-lg p-3"><span class="mr-2"><?= $ins[0] ?></span><?= $ins[1] ?></div>
        <?php endforeach; ?>
    </div>
</div>

<script>
const salesData = <?= json_encode($salesData) ?>;
const statusData = <?= json_encode($statusBreakdown) ?>;
const hourlyData = <?= json_encode($hourlyData) ?>;

// Revenue Chart
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: salesData.map(d => { const dt = new Date(d.date); return dt.toLocaleDateString('en-US',{month:'short',day:'numeric'}); }),
        datasets: [{
            label: 'Revenue (৳)', data: salesData.map(d => d.revenue),
            borderColor: 'rgb(59,130,246)', backgroundColor: 'rgba(59,130,246,0.1)', fill: true, tension: 0.4
        },{
            label: 'Orders', data: salesData.map(d => d.orders),
            borderColor: 'rgb(16,185,129)', borderDash: [5,5], fill: false, tension: 0.4, yAxisID: 'y1'
        }]
    },
    options: { responsive: true, plugins: {legend:{position:'bottom'}}, scales: { y:{beginAtZero:true}, y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false}} } }
});

// Status Chart
const statusColors = {pending:'#f59e0b',confirmed:'#3b82f6',processing:'#6366f1',shipped:'#8b5cf6',delivered:'#10b981',cancelled:'#ef4444',returned:'#f97316',on_hold:'#6b7280'};
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusData.map(d => d.order_status.charAt(0).toUpperCase() + d.order_status.slice(1)),
        datasets: [{ data: statusData.map(d => d.cnt), backgroundColor: statusData.map(d => statusColors[d.order_status] || '#6b7280') }]
    },
    options: { responsive: true, plugins: {legend:{position:'bottom'}} }
});

// Hourly Chart
new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: {
        labels: hourlyData.map(d => d.hr + ':00'),
        datasets: [{ data: hourlyData.map(d => d.cnt), backgroundColor: 'rgba(139,92,246,0.5)', borderRadius: 4 }]
    },
    options: { responsive: true, plugins: {legend:{display:false}}, scales: {y:{beginAtZero:true}} }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
