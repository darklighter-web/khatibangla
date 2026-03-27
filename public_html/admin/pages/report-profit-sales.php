<?php
/**
 * Profit & Sales Report - Revenue, cost, margin analysis
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Profit & Sales Report';
$db = Database::getInstance();

$period = $_GET['period'] ?? '30';
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime("-{$period} days"));
$dateTo = $_GET['to'] ?? date('Y-m-d');
$channel = $_GET['channel'] ?? '';

$where = "WHERE o.order_status = 'delivered' AND DATE(o.created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($channel) { $where .= " AND o.channel = ?"; $params[] = $channel; }

// Revenue & orders
$revenue = $db->fetch("SELECT COUNT(*) as orders, COALESCE(SUM(o.total),0) as gross_revenue, COALESCE(SUM(o.shipping_cost),0) as total_shipping, COALESCE(SUM(o.discount_amount),0) as total_discounts, COALESCE(SUM(o.advance_amount),0) as total_advance FROM orders o $where", $params);

// Cost of goods
$cogs = $db->fetch("SELECT COALESCE(SUM(oi.quantity * COALESCE(p.cost_price,0)),0) as total_cost
    FROM order_items oi 
    JOIN orders o ON o.id=oi.order_id 
    LEFT JOIN products p ON oi.product_id=p.id 
    $where", $params);

// Courier charges (from orders)
$courierCosts = $db->fetch("SELECT COALESCE(SUM(courier_delivery_charge),0) as total FROM orders o $where AND courier_delivery_charge > 0", $params)['total'] ?? 0;

// Ad expenses in period
$adExpenses = $db->fetch("SELECT COALESCE(SUM(amount_bdt),0) as total FROM ad_expenses WHERE expense_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'] ?? 0;

// Other expenses in period
$otherExpenses = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE expense_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'] ?? 0;

$grossRevenue = $revenue['gross_revenue'] ?? 0;
$totalCost = $cogs['total_cost'] ?? 0;
$grossProfit = $grossRevenue - $totalCost;
$grossMargin = $grossRevenue > 0 ? round(($grossProfit / $grossRevenue) * 100, 1) : 0;
$netProfit = $grossProfit - $courierCosts - $adExpenses - $otherExpenses;
$netMargin = $grossRevenue > 0 ? round(($netProfit / $grossRevenue) * 100, 1) : 0;
$avgOrderValue = ($revenue['orders'] ?? 0) > 0 ? round($grossRevenue / $revenue['orders']) : 0;

// Daily profit trend
$dailyProfit = $db->fetchAll("SELECT DATE(o.created_at) as date, 
    COALESCE(SUM(o.total),0) as revenue,
    COALESCE(SUM(sub.item_cost),0) as cost,
    COUNT(*) as orders
    FROM orders o
    LEFT JOIN (SELECT oi.order_id, SUM(oi.quantity * COALESCE(p.cost_price,0)) as item_cost FROM order_items oi LEFT JOIN products p ON oi.product_id=p.id GROUP BY oi.order_id) sub ON sub.order_id=o.id
    $where GROUP BY DATE(o.created_at) ORDER BY date", $params);

// Product profitability
$productProfit = $db->fetchAll("SELECT oi.product_name, 
    SUM(oi.quantity) as units_sold,
    SUM(oi.subtotal) as revenue,
    SUM(oi.quantity * COALESCE(p.cost_price,0)) as cost,
    SUM(oi.subtotal) - SUM(oi.quantity * COALESCE(p.cost_price,0)) as profit,
    CASE WHEN SUM(oi.subtotal) > 0 THEN ROUND(((SUM(oi.subtotal) - SUM(oi.quantity * COALESCE(p.cost_price,0))) / SUM(oi.subtotal)) * 100, 1) ELSE 0 END as margin
    FROM order_items oi
    JOIN orders o ON o.id=oi.order_id
    LEFT JOIN products p ON oi.product_id=p.id
    $where GROUP BY oi.product_id, oi.product_name ORDER BY profit DESC LIMIT 20", $params);

// Channel breakdown
$channelBreakdown = $db->fetchAll("SELECT o.channel, COUNT(*) as orders, COALESCE(SUM(o.total),0) as revenue
    FROM orders o $where GROUP BY o.channel ORDER BY revenue DESC", $params);

// CSV Export
if (isset($_GET['export'])) {
    $exportType = $_GET['export'] ?? '';
    header('Content-Type: text/csv');
    $output = fopen('php://output', 'w');
    
    if ($exportType === 'summary') {
        header('Content-Disposition: attachment; filename="profit-summary-' . date('Y-m-d') . '.csv"');
        fputcsv($output, ['Metric', 'Value']);
        fputcsv($output, ['Gross Revenue', $grossRevenue]);
        fputcsv($output, ['COGS', $totalCost]);
        fputcsv($output, ['Gross Profit', $grossProfit]);
        fputcsv($output, ['Gross Margin %', $grossMargin]);
        fputcsv($output, ['Courier Costs', $courierCosts]);
        fputcsv($output, ['Ad Expenses', $adExpenses]);
        fputcsv($output, ['Other Expenses', $otherExpenses]);
        fputcsv($output, ['Net Profit', $netProfit]);
        fputcsv($output, ['Net Margin %', $netMargin]);
    } elseif ($exportType === 'products') {
        header('Content-Disposition: attachment; filename="product-profitability-' . date('Y-m-d') . '.csv"');
        fputcsv($output, ['Product', 'Units Sold', 'Revenue', 'Cost', 'Profit', 'Margin %']);
        foreach ($productProfit as $pp) {
            fputcsv($output, [$pp['product_name'], $pp['units_sold'], $pp['revenue'], $pp['cost'], $pp['profit'], $pp['margin']]);
        }
    } elseif ($exportType === 'daily') {
        header('Content-Disposition: attachment; filename="daily-profit-' . date('Y-m-d') . '.csv"');
        fputcsv($output, ['Date', 'Revenue', 'Cost', 'Profit', 'Orders']);
        foreach ($dailyProfit as $dp) {
            fputcsv($output, [$dp['date'], $dp['revenue'], $dp['cost'], $dp['revenue'] - $dp['cost'], $dp['orders']]);
        }
    }
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Period Filter -->
<div class="bg-white rounded-xl border shadow-sm p-4 mb-6">
    <form class="flex flex-wrap items-center gap-3">
        <input type="hidden" name="from" value="<?= $dateFrom ?>">
        <input type="hidden" name="to" value="<?= $dateTo ?>">
        <div data-kb-datepicker data-from-param="from" data-to-param="to"></div>
        <select name="channel" class="border rounded-lg px-3 py-1.5 text-sm" onchange="this.form.submit()">
            <option value="">All Channels</option>
            <?php foreach (['website','facebook','phone','whatsapp','instagram'] as $ch): ?>
            <option value="<?= $ch ?>" <?= $channel===$ch?'selected':'' ?>><?= ucfirst($ch) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<!-- P&L Cards -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500">Gross Revenue</p>
        <p class="text-xl font-bold text-gray-800 mt-1">৳<?= number_format($grossRevenue) ?></p>
        <p class="text-xs text-gray-400"><?= $revenue['orders'] ?? 0 ?> orders • AOV ৳<?= number_format($avgOrderValue) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500">COGS</p>
        <p class="text-xl font-bold text-red-600 mt-1">৳<?= number_format($totalCost) ?></p>
        <p class="text-xs text-gray-400">Cost of goods sold</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500">Gross Profit</p>
        <p class="text-xl font-bold <?= $grossProfit >= 0 ? 'text-green-600' : 'text-red-600' ?> mt-1">৳<?= number_format($grossProfit) ?></p>
        <p class="text-xs <?= $grossMargin >= 0 ? 'text-green-500' : 'text-red-500' ?>"><?= $grossMargin ?>% margin</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500">Total Expenses</p>
        <p class="text-xl font-bold text-orange-600 mt-1">৳<?= number_format($courierCosts + $adExpenses + $otherExpenses) ?></p>
        <p class="text-xs text-gray-400">Courier + Ads + Other</p>
    </div>
    <div class="bg-white rounded-xl border p-4 <?= $netProfit >= 0 ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' ?>">
        <p class="text-xs text-gray-500">Net Profit</p>
        <p class="text-xl font-bold <?= $netProfit >= 0 ? 'text-green-700' : 'text-red-700' ?> mt-1">৳<?= number_format($netProfit) ?></p>
        <p class="text-xs <?= $netMargin >= 0 ? 'text-green-500' : 'text-red-500' ?>"><?= $netMargin ?>% net margin</p>
    </div>
</div>

<!-- Expense Breakdown -->
<div class="bg-white rounded-xl border shadow-sm p-5 mb-6">
    <div class="flex justify-between items-center mb-4">
        <h4 class="font-semibold text-gray-800">Expense Breakdown</h4>
        <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&export=summary" class="text-xs text-blue-600 hover:text-blue-800">Export Summary CSV</a>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="p-3 bg-gray-50 rounded-lg text-center">
            <p class="text-sm font-semibold text-gray-700">৳<?= number_format($courierCosts) ?></p>
            <p class="text-xs text-gray-500 mt-1">Courier Charges</p>
        </div>
        <div class="p-3 bg-purple-50 rounded-lg text-center">
            <p class="text-sm font-semibold text-purple-700">৳<?= number_format($adExpenses) ?></p>
            <p class="text-xs text-gray-500 mt-1">Ad Expenses</p>
        </div>
        <div class="p-3 bg-red-50 rounded-lg text-center">
            <p class="text-sm font-semibold text-red-700">৳<?= number_format($otherExpenses) ?></p>
            <p class="text-xs text-gray-500 mt-1">Other Expenses</p>
        </div>
        <div class="p-3 bg-blue-50 rounded-lg text-center">
            <p class="text-sm font-semibold text-blue-700"><?= count($channelBreakdown) ?></p>
            <p class="text-xs text-gray-500 mt-1">Active Channels</p>
        </div>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <!-- Daily Profit Chart -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <div class="flex justify-between items-center mb-4">
            <h4 class="font-semibold text-gray-800">Daily Profit Trend</h4>
            <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&export=daily" class="text-xs text-blue-600 hover:text-blue-800">CSV</a>
        </div>
        <canvas id="profitChart" height="150"></canvas>
    </div>

    <!-- Channel Performance -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Revenue by Channel</h4>
        <?php foreach ($channelBreakdown as $cb): 
            $pct = $grossRevenue > 0 ? round(($cb['revenue'] / $grossRevenue) * 100) : 0;
        ?>
        <div class="mb-3">
            <div class="flex justify-between text-sm mb-1">
                <span class="capitalize font-medium"><?= e($cb['channel'] ?: 'Unknown') ?></span>
                <span class="text-gray-600">৳<?= number_format($cb['revenue']) ?> (<?= $cb['orders'] ?> orders)</span>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-2">
                <div class="bg-blue-500 h-2 rounded-full" style="width: <?= $pct ?>%"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Product Profitability -->
<div class="bg-white rounded-xl border shadow-sm mb-6">
    <div class="p-4 border-b flex justify-between items-center">
        <h4 class="font-semibold text-gray-800">Product Profitability</h4>
        <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&export=products" class="text-xs text-blue-600 hover:text-blue-800">Export CSV</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">#</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Product</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Units</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Revenue</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Cost</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Profit</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Margin</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($productProfit as $i => $pp): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-400"><?= $i+1 ?></td>
                    <td class="px-4 py-3 font-medium"><?= e($pp['product_name']) ?></td>
                    <td class="px-4 py-3 text-center text-gray-600"><?= number_format($pp['units_sold']) ?></td>
                    <td class="px-4 py-3 text-right">৳<?= number_format($pp['revenue']) ?></td>
                    <td class="px-4 py-3 text-right text-gray-500">৳<?= number_format($pp['cost']) ?></td>
                    <td class="px-4 py-3 text-right font-semibold <?= $pp['profit'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">৳<?= number_format($pp['profit']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $pp['margin'] >= 30 ? 'bg-green-100 text-green-700' : ($pp['margin'] >= 10 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') ?>"><?= $pp['margin'] ?>%</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const dailyData = <?= json_encode($dailyProfit) ?>;
new Chart(document.getElementById('profitChart'), {
    type: 'line',
    data: {
        labels: dailyData.map(d => { const dt = new Date(d.date); return dt.toLocaleDateString('en-US',{month:'short',day:'numeric'}); }),
        datasets: [
            { label: 'Revenue', data: dailyData.map(d => parseFloat(d.revenue)), borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', fill: true, tension: 0.4 },
            { label: 'Profit', data: dailyData.map(d => parseFloat(d.revenue) - parseFloat(d.cost)), borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,0.1)', fill: true, tension: 0.4 }
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
