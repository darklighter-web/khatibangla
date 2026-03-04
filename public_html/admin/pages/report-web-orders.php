<?php
/**
 * Web Order Report - Website order analytics
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Web Order Report';
$db = Database::getInstance();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

$where = "WHERE o.channel = 'website' AND DATE(o.created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

$summary = $db->fetch("SELECT COUNT(*) as total, COALESCE(SUM(total),0) as revenue, COALESCE(AVG(total),0) as aov,
    COUNT(CASE WHEN order_status='delivered' THEN 1 END) as delivered,
    COUNT(CASE WHEN order_status='cancelled' THEN 1 END) as cancelled,
    COUNT(CASE WHEN is_fake=1 THEN 1 END) as fake_orders,
    COUNT(DISTINCT customer_phone) as unique_customers
    FROM orders o $where", $params);

// Device breakdown
$deviceData = $db->fetchAll("SELECT COALESCE(device_type,'unknown') as device, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev FROM orders o $where GROUP BY device_type ORDER BY cnt DESC", $params);

// Shipping area
$areaData = $db->fetchAll("SELECT shipping_area, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev FROM orders o $where GROUP BY shipping_area ORDER BY cnt DESC", $params);

// Payment methods
$paymentData = $db->fetchAll("SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev FROM orders o $where GROUP BY payment_method ORDER BY cnt DESC", $params);

// Conversion funnel (from visitors if available)
$visitorCount = $db->fetch("SELECT COUNT(DISTINCT visitor_id) as cnt FROM orders o $where AND visitor_id IS NOT NULL", $params)['cnt'] ?? 0;

// Daily trend
$dailyOrders = $db->fetchAll("SELECT DATE(created_at) as date, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev FROM orders o $where GROUP BY DATE(created_at) ORDER BY date", $params);

// Top products via website
$topProducts = $db->fetchAll("SELECT oi.product_name, SUM(oi.quantity) as qty, SUM(oi.subtotal) as rev
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    $where GROUP BY oi.product_name ORDER BY rev DESC LIMIT 10", $params);

// Hourly pattern
$hourlyData = $db->fetchAll("SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM orders o $where GROUP BY HOUR(created_at) ORDER BY hr", $params);

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="web-orders-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Order#', 'Date', 'Customer', 'Phone', 'Area', 'Device', 'Status', 'Total', 'Payment', 'Fake']);
    $all = $db->fetchAll("SELECT * FROM orders o $where ORDER BY created_at DESC", $params);
    foreach ($all as $o) {
        fputcsv($output, [$o['order_number'], $o['created_at'], $o['customer_name'], $o['customer_phone'], $o['shipping_area'], $o['device_type'] ?? '', $o['order_status'], $o['total'], $o['payment_method'], $o['is_fake'] ? 'Yes' : 'No']);
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
        <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&export=csv" class="ml-auto bg-gray-100 text-gray-700 px-4 py-1.5 rounded-lg text-sm hover:bg-gray-200">Export CSV</a>
    </form>
</div>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4 text-center"><p class="text-2xl font-bold text-gray-800"><?= number_format($summary['total'] ?? 0) ?></p><p class="text-xs text-gray-500">Web Orders</p></div>
    <div class="bg-white rounded-xl border p-4 text-center"><p class="text-2xl font-bold text-green-600">৳<?= number_format($summary['revenue'] ?? 0) ?></p><p class="text-xs text-gray-500">Revenue</p></div>
    <div class="bg-white rounded-xl border p-4 text-center"><p class="text-2xl font-bold text-blue-600">৳<?= number_format($summary['aov'] ?? 0) ?></p><p class="text-xs text-gray-500">Avg Order Value</p></div>
    <div class="bg-white rounded-xl border p-4 text-center"><p class="text-2xl font-bold text-purple-600"><?= $summary['unique_customers'] ?? 0 ?></p><p class="text-xs text-gray-500">Unique Customers</p></div>
    <div class="bg-white rounded-xl border p-4 text-center"><p class="text-2xl font-bold text-red-600"><?= $summary['fake_orders'] ?? 0 ?></p><p class="text-xs text-gray-500">Flagged Fake</p></div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Daily Web Orders</h4>
        <canvas id="dailyChart" height="150"></canvas>
    </div>
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Peak Hours</h4>
        <canvas id="hourlyChart" height="150"></canvas>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6 mb-6">
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">By Device</h4>
        <?php foreach ($deviceData as $d): ?>
        <div class="flex justify-between py-2 border-b last:border-0 text-sm"><span class="capitalize"><?= e($d['device']) ?></span><span class="font-medium"><?= $d['cnt'] ?> (৳<?= number_format($d['rev']) ?>)</span></div>
        <?php endforeach; ?>
    </div>
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">By Area</h4>
        <?php foreach ($areaData as $a): ?>
        <div class="flex justify-between py-2 border-b last:border-0 text-sm"><span><?= e($a['shipping_area'] ?: 'Unknown') ?></span><span class="font-medium"><?= $a['cnt'] ?></span></div>
        <?php endforeach; ?>
    </div>
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Top Products (Web)</h4>
        <?php foreach ($topProducts as $tp): ?>
        <div class="flex justify-between py-2 border-b last:border-0 text-sm"><span class="truncate max-w-[150px]"><?= e($tp['product_name']) ?></span><span class="font-medium"><?= $tp['qty'] ?> sold</span></div>
        <?php endforeach; ?>
    </div>
</div>

<script>
const d = <?= json_encode($dailyOrders) ?>;
new Chart(document.getElementById('dailyChart'), { type:'line', data:{labels:d.map(x=>{const dt=new Date(x.date);return dt.toLocaleDateString('en-US',{month:'short',day:'numeric'});}),datasets:[{label:'Orders',data:d.map(x=>x.cnt),borderColor:'#3b82f6',tension:0.4,fill:true,backgroundColor:'rgba(59,130,246,0.1)'}]}, options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}} });
const h = <?= json_encode($hourlyData) ?>;
const hrs = Array.from({length:24},(_,i)=>i);
new Chart(document.getElementById('hourlyChart'), { type:'bar', data:{labels:hrs.map(x=>x+':00'),datasets:[{data:hrs.map(x=>{const f=h.find(v=>parseInt(v.hr)===x);return f?f.cnt:0;}),backgroundColor:'rgba(139,92,246,0.5)',borderRadius:4}]}, options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}} });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
