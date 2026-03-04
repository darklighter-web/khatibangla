<?php
/**
 * Business Reports - Combined overview of inventory, sales, expenses, and all KPIs
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Business Reports';
$db = Database::getInstance();

$period = $_GET['period'] ?? '30';
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime("-{$period} days"));
$dateTo = $_GET['to'] ?? date('Y-m-d');

// ── SALES & ORDERS ──
$sales = $db->fetch("SELECT 
    COUNT(*) as total_orders,
    COUNT(CASE WHEN order_status='delivered' THEN 1 END) as delivered,
    COUNT(CASE WHEN order_status='cancelled' THEN 1 END) as cancelled,
    COUNT(CASE WHEN order_status='returned' THEN 1 END) as returned,
    COALESCE(SUM(total),0) as gross_revenue,
    COALESCE(SUM(CASE WHEN order_status='delivered' THEN total ELSE 0 END),0) as net_revenue,
    COALESCE(SUM(shipping_cost),0) as shipping_collected,
    COALESCE(SUM(discount_amount),0) as total_discounts,
    COALESCE(AVG(total),0) as avg_order,
    COUNT(DISTINCT customer_id) as unique_customers
    FROM orders WHERE DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);

$deliveryRate = (($sales['delivered'] ?? 0) + ($sales['cancelled'] ?? 0) + ($sales['returned'] ?? 0)) > 0
    ? round(($sales['delivered'] / (($sales['delivered'] ?? 0) + ($sales['cancelled'] ?? 0) + ($sales['returned'] ?? 0))) * 100, 1) : 0;

// ── COST OF GOODS ──
$cogs = $db->fetch("SELECT COALESCE(SUM(oi.quantity * COALESCE(p.cost_price,0)),0) as total
    FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN products p ON oi.product_id=p.id
    WHERE o.order_status='delivered' AND DATE(o.created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'] ?? 0;

// ── EXPENSES ──
$expenses = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE expense_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'] ?? 0;

try {
    $adExpenses = $db->fetch("SELECT COALESCE(SUM(amount_bdt),0) as total FROM ad_expenses WHERE expense_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'] ?? 0;
} catch (Exception $e) { $adExpenses = 0; }

$courierCosts = $db->fetch("SELECT COALESCE(SUM(courier_delivery_charge),0) as total FROM orders 
    WHERE order_status='delivered' AND DATE(created_at) BETWEEN ? AND ? AND courier_delivery_charge > 0", [$dateFrom, $dateTo])['total'] ?? 0;

// ── PROFIT CALCULATION ──
$grossProfit = ($sales['net_revenue'] ?? 0) - $cogs;
$totalExpenses = $expenses + $adExpenses + $courierCosts;
$netProfit = $grossProfit - $totalExpenses;
$netMargin = ($sales['net_revenue'] ?? 0) > 0 ? round(($netProfit / $sales['net_revenue']) * 100, 1) : 0;

// ── INVENTORY ──
$inventory = $db->fetch("SELECT 
    COUNT(*) as total_products,
    COUNT(CASE WHEN is_active=1 THEN 1 END) as active_products,
    COALESCE(SUM(stock_quantity),0) as total_stock,
    COUNT(CASE WHEN stock_quantity <= COALESCE(low_stock_threshold,5) AND stock_quantity > 0 THEN 1 END) as low_stock,
    COUNT(CASE WHEN stock_quantity = 0 THEN 1 END) as out_of_stock
    FROM products");

$inventoryValue = $db->fetch("SELECT COALESCE(SUM(stock_quantity * COALESCE(cost_price, 0)),0) as cost_val, 
    COALESCE(SUM(stock_quantity * COALESCE(sale_price, regular_price)),0) as retail_val FROM products WHERE stock_quantity > 0");

// ── DAILY REVENUE TREND ──
$dailyRevenue = $db->fetchAll("SELECT DATE(created_at) as date, COUNT(*) as orders, 
    COALESCE(SUM(total),0) as revenue,
    COUNT(CASE WHEN order_status='delivered' THEN 1 END) as delivered
    FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND order_status NOT IN ('cancelled')
    GROUP BY DATE(created_at) ORDER BY date", [$dateFrom, $dateTo]);

// ── MONTHLY COMPARISON ──
$thisMonth = date('Y-m');
$lastMonth = date('Y-m', strtotime('-1 month'));
$monthlyComp = $db->fetchAll("SELECT DATE_FORMAT(created_at,'%Y-%m') as month, 
    COUNT(*) as orders, COALESCE(SUM(total),0) as revenue,
    COUNT(CASE WHEN order_status='delivered' THEN 1 END) as delivered
    FROM orders WHERE DATE_FORMAT(created_at,'%Y-%m') IN (?,?)
    GROUP BY DATE_FORMAT(created_at,'%Y-%m')", [$thisMonth, $lastMonth]);
$monthData = [];
foreach ($monthlyComp as $mc) { $monthData[$mc['month']] = $mc; }

// ── TOP PRODUCTS ──
$topProducts = $db->fetchAll("SELECT oi.product_name, SUM(oi.quantity) as units, SUM(oi.subtotal) as revenue,
    SUM(oi.quantity * COALESCE(p.cost_price,0)) as cost,
    SUM(oi.subtotal) - SUM(oi.quantity * COALESCE(p.cost_price,0)) as profit,
    CASE WHEN SUM(oi.subtotal) > 0 THEN ROUND(((SUM(oi.subtotal) - SUM(oi.quantity * COALESCE(p.cost_price,0))) / SUM(oi.subtotal)) * 100,1) ELSE 0 END as margin
    FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN products p ON oi.product_id=p.id
    WHERE o.order_status='delivered' AND DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY oi.product_id, oi.product_name ORDER BY revenue DESC LIMIT 10", [$dateFrom, $dateTo]);

// ── BY CHANNEL ──
$channels = $db->fetchAll("SELECT COALESCE(channel,'direct') as channel, COUNT(*) as orders, COALESCE(SUM(total),0) as revenue
    FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND order_status NOT IN ('cancelled')
    GROUP BY channel ORDER BY revenue DESC", [$dateFrom, $dateTo]);

// ── EMPLOYEE SUMMARY ──
$empSummary = $db->fetchAll("SELECT au.full_name, COUNT(o.id) as orders,
    COUNT(CASE WHEN o.order_status='delivered' THEN 1 END) as delivered,
    COALESCE(SUM(CASE WHEN o.order_status='delivered' THEN o.total ELSE 0 END),0) as revenue
    FROM admin_users au LEFT JOIN orders o ON o.assigned_to=au.id AND DATE(o.created_at) BETWEEN ? AND ?
    WHERE au.is_active=1 GROUP BY au.id HAVING orders > 0 ORDER BY orders DESC LIMIT 5", [$dateFrom, $dateTo]);

// ── CSV EXPORT ──
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="business-report-' . $dateFrom . '-to-' . $dateTo . '.csv"');
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['=== BUSINESS REPORT ===', $dateFrom . ' to ' . $dateTo]);
    fputcsv($output, []);
    fputcsv($output, ['--- SALES OVERVIEW ---']);
    fputcsv($output, ['Total Orders', $sales['total_orders']]);
    fputcsv($output, ['Delivered', $sales['delivered']]);
    fputcsv($output, ['Cancelled', $sales['cancelled']]);
    fputcsv($output, ['Returned', $sales['returned']]);
    fputcsv($output, ['Gross Revenue (BDT)', $sales['gross_revenue']]);
    fputcsv($output, ['Net Revenue (Delivered)', $sales['net_revenue']]);
    fputcsv($output, ['Avg Order Value', $sales['avg_order']]);
    fputcsv($output, ['Delivery Rate %', $deliveryRate]);
    fputcsv($output, []);
    fputcsv($output, ['--- PROFIT & LOSS ---']);
    fputcsv($output, ['Net Revenue', $sales['net_revenue']]);
    fputcsv($output, ['Cost of Goods', $cogs]);
    fputcsv($output, ['Gross Profit', $grossProfit]);
    fputcsv($output, ['Operating Expenses', $expenses]);
    fputcsv($output, ['Ad Expenses', $adExpenses]);
    fputcsv($output, ['Courier Costs', $courierCosts]);
    fputcsv($output, ['Net Profit', $netProfit]);
    fputcsv($output, ['Net Margin %', $netMargin]);
    fputcsv($output, []);
    fputcsv($output, ['--- INVENTORY ---']);
    fputcsv($output, ['Total Products', $inventory['total_products']]);
    fputcsv($output, ['Active Products', $inventory['active_products']]);
    fputcsv($output, ['Total Stock Units', $inventory['total_stock']]);
    fputcsv($output, ['Low Stock Items', $inventory['low_stock']]);
    fputcsv($output, ['Out of Stock', $inventory['out_of_stock']]);
    fputcsv($output, ['Inventory Value (Cost)', $inventoryValue['cost_val'] ?? 0]);
    fputcsv($output, ['Inventory Value (Retail)', $inventoryValue['retail_val'] ?? 0]);
    fputcsv($output, []);
    fputcsv($output, ['--- TOP PRODUCTS ---']);
    fputcsv($output, ['Product', 'Units Sold', 'Revenue', 'Cost', 'Profit', 'Margin %']);
    foreach ($topProducts as $tp) {
        fputcsv($output, [$tp['product_name'], $tp['units'], $tp['revenue'], $tp['cost'], $tp['profit'], $tp['margin']]);
    }
    fputcsv($output, []);
    fputcsv($output, ['--- DAILY REVENUE ---']);
    fputcsv($output, ['Date', 'Orders', 'Revenue', 'Delivered']);
    foreach ($dailyRevenue as $dr) {
        fputcsv($output, [$dr['date'], $dr['orders'], $dr['revenue'], $dr['delivered']]);
    }
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Filter Bar -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <input type="hidden" name="from" value="<?= $dateFrom ?>">
        <input type="hidden" name="to" value="<?= $dateTo ?>">
        <div data-kb-datepicker data-from-param="from" data-to-param="to"></div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="ml-auto bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-green-700">
            <i class="fas fa-download mr-1"></i>Export Full Report
        </a>
    </form>
</div>

<!-- ═══ PROFIT & LOSS SUMMARY ═══ -->
<div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-xl p-6 mb-6 text-white">
    <h3 class="font-bold text-lg mb-4">Profit & Loss Summary</h3>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div>
            <p class="text-blue-200 text-xs">Net Revenue</p>
            <p class="text-2xl font-bold">৳<?= number_format($sales['net_revenue'] ?? 0) ?></p>
        </div>
        <div>
            <p class="text-blue-200 text-xs">Gross Profit</p>
            <p class="text-2xl font-bold">৳<?= number_format($grossProfit) ?></p>
        </div>
        <div>
            <p class="text-blue-200 text-xs">Total Expenses</p>
            <p class="text-2xl font-bold text-red-300">৳<?= number_format($totalExpenses) ?></p>
        </div>
        <div>
            <p class="text-blue-200 text-xs">Net Profit</p>
            <p class="text-2xl font-bold <?= $netProfit >= 0 ? 'text-green-300' : 'text-red-300' ?>">৳<?= number_format($netProfit) ?></p>
        </div>
        <div>
            <p class="text-blue-200 text-xs">Net Margin</p>
            <p class="text-2xl font-bold <?= $netMargin >= 0 ? 'text-green-300' : 'text-red-300' ?>"><?= $netMargin ?>%</p>
        </div>
    </div>
</div>

<!-- ═══ SALES & ORDERS ═══ -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-gray-800"><?= number_format($sales['total_orders'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Total Orders</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-green-600"><?= $deliveryRate ?>%</p>
        <p class="text-xs text-gray-500 mt-1">Delivery Rate</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-gray-800">৳<?= number_format($sales['avg_order'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Avg Order Value</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-blue-600"><?= number_format($sales['unique_customers'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Unique Customers</p>
    </div>
</div>

<!-- Revenue Chart + Month Comparison -->
<div class="grid md:grid-cols-3 gap-6 mb-6">
    <div class="md:col-span-2 bg-white rounded-xl shadow-sm border p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Revenue Trend</h4>
        <canvas id="revenueChart" height="150"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Month Comparison</h4>
        <?php 
        $thisM = $monthData[$thisMonth] ?? ['orders'=>0,'revenue'=>0,'delivered'=>0];
        $lastM = $monthData[$lastMonth] ?? ['orders'=>0,'revenue'=>0,'delivered'=>0];
        $orderChange = ($lastM['orders'] ?? 0) > 0 ? round(((($thisM['orders'] ?? 0) - $lastM['orders']) / $lastM['orders']) * 100, 1) : 0;
        $revChange = ($lastM['revenue'] ?? 0) > 0 ? round(((($thisM['revenue'] ?? 0) - $lastM['revenue']) / $lastM['revenue']) * 100, 1) : 0;
        ?>
        <div class="space-y-4">
            <div class="p-3 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 mb-1">This Month Orders</p>
                <p class="text-xl font-bold"><?= number_format($thisM['orders'] ?? 0) ?></p>
                <span class="text-xs <?= $orderChange >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                    <?= $orderChange >= 0 ? '↑' : '↓' ?> <?= abs($orderChange) ?>% vs last month
                </span>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 mb-1">This Month Revenue</p>
                <p class="text-xl font-bold">৳<?= number_format($thisM['revenue'] ?? 0) ?></p>
                <span class="text-xs <?= $revChange >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                    <?= $revChange >= 0 ? '↑' : '↓' ?> <?= abs($revChange) ?>% vs last month
                </span>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 mb-1">Last Month</p>
                <p class="text-lg font-bold text-gray-500"><?= number_format($lastM['orders'] ?? 0) ?> orders · ৳<?= number_format($lastM['revenue'] ?? 0) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- ═══ INVENTORY HEALTH ═══ -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-xl font-bold text-gray-800"><?= number_format($inventory['total_products'] ?? 0) ?></p>
        <p class="text-xs text-gray-500">Total Products</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-xl font-bold text-green-600"><?= number_format($inventory['active_products'] ?? 0) ?></p>
        <p class="text-xs text-gray-500">Active</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-xl font-bold text-gray-800"><?= number_format($inventory['total_stock'] ?? 0) ?></p>
        <p class="text-xs text-gray-500">Total Units</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-xl font-bold text-yellow-600"><?= number_format($inventory['low_stock'] ?? 0) ?></p>
        <p class="text-xs text-gray-500">Low Stock</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-xl font-bold text-red-600"><?= number_format($inventory['out_of_stock'] ?? 0) ?></p>
        <p class="text-xs text-gray-500">Out of Stock</p>
    </div>
</div>

<!-- Inventory Value Bar -->
<div class="bg-white rounded-xl shadow-sm border p-5 mb-6">
    <div class="flex items-center justify-between mb-3">
        <h4 class="font-semibold text-gray-800">Inventory Value</h4>
        <div class="flex gap-4 text-sm">
            <span class="text-gray-500">Cost: <strong class="text-gray-800">৳<?= number_format($inventoryValue['cost_val'] ?? 0) ?></strong></span>
            <span class="text-gray-500">Retail: <strong class="text-green-600">৳<?= number_format($inventoryValue['retail_val'] ?? 0) ?></strong></span>
            <?php $potentialProfit = ($inventoryValue['retail_val'] ?? 0) - ($inventoryValue['cost_val'] ?? 0); ?>
            <span class="text-gray-500">Potential Profit: <strong class="text-blue-600">৳<?= number_format($potentialProfit) ?></strong></span>
        </div>
    </div>
    <div class="w-full h-4 bg-gray-200 rounded-full overflow-hidden flex">
        <?php $costPct = ($inventoryValue['retail_val'] ?? 0) > 0 ? round((($inventoryValue['cost_val'] ?? 0) / $inventoryValue['retail_val']) * 100) : 0; ?>
        <div class="h-full bg-red-400" style="width:<?= $costPct ?>%"></div>
        <div class="h-full bg-green-400" style="width:<?= 100 - $costPct ?>%"></div>
    </div>
    <div class="flex justify-between mt-1 text-xs text-gray-400">
        <span>Cost (<?= $costPct ?>%)</span>
        <span>Margin (<?= 100 - $costPct ?>%)</span>
    </div>
</div>

<!-- Top Products + Channels + Employees -->
<div class="grid md:grid-cols-3 gap-6 mb-6">
    <!-- Top Products -->
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Top Products (Profit)</h4>
        <div class="space-y-3">
            <?php foreach (array_slice($topProducts, 0, 7) as $i => $tp): ?>
            <div class="flex items-center gap-2">
                <span class="w-5 h-5 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center text-[10px] font-bold"><?= $i+1 ?></span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium truncate"><?= e($tp['product_name']) ?></p>
                    <p class="text-xs text-gray-500"><?= $tp['units'] ?> sold · Margin: <?= $tp['margin'] ?>%</p>
                </div>
                <span class="text-sm font-semibold text-green-600">৳<?= number_format($tp['profit']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($topProducts)): ?><p class="text-gray-400 text-center py-4">No data</p><?php endif; ?>
        </div>
    </div>

    <!-- Channel Breakdown -->
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Sales by Channel</h4>
        <canvas id="channelChart" height="150"></canvas>
    </div>

    <!-- Employee Summary -->
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Top Employees</h4>
        <div class="space-y-3">
            <?php foreach ($empSummary as $emp): 
                $empRate = ($emp['orders'] ?? 0) > 0 ? round((($emp['delivered'] ?? 0) / $emp['orders']) * 100, 1) : 0;
            ?>
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center text-xs font-bold"><?= strtoupper(substr($emp['full_name'],0,1)) ?></div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium truncate"><?= e($emp['full_name']) ?></p>
                    <p class="text-xs text-gray-500"><?= $emp['orders'] ?> orders · <?= $empRate ?>% success</p>
                </div>
                <span class="text-sm font-semibold">৳<?= number_format($emp['revenue']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($empSummary)): ?><p class="text-gray-400 text-center py-4">No data</p><?php endif; ?>
        </div>
    </div>
</div>

<!-- Expense Breakdown -->
<div class="bg-white rounded-xl shadow-sm border p-5 mb-6">
    <h4 class="font-semibold text-gray-800 mb-4">Expense Breakdown</h4>
    <div class="grid md:grid-cols-4 gap-4">
        <div class="p-3 bg-red-50 rounded-lg">
            <p class="text-xs text-red-600 font-medium">Operating Expenses</p>
            <p class="text-xl font-bold text-red-700">৳<?= number_format($expenses) ?></p>
        </div>
        <div class="p-3 bg-blue-50 rounded-lg">
            <p class="text-xs text-blue-600 font-medium">Ad Expenses</p>
            <p class="text-xl font-bold text-blue-700">৳<?= number_format($adExpenses) ?></p>
        </div>
        <div class="p-3 bg-purple-50 rounded-lg">
            <p class="text-xs text-purple-600 font-medium">Courier Costs</p>
            <p class="text-xl font-bold text-purple-700">৳<?= number_format($courierCosts) ?></p>
        </div>
        <div class="p-3 bg-orange-50 rounded-lg">
            <p class="text-xs text-orange-600 font-medium">COGS</p>
            <p class="text-xl font-bold text-orange-700">৳<?= number_format($cogs) ?></p>
        </div>
    </div>
</div>

<!-- Quick Links to Detailed Reports -->
<div class="bg-white rounded-xl shadow-sm border p-5 mb-6">
    <h4 class="font-semibold text-gray-800 mb-4">Detailed Reports</h4>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <a href="<?= adminUrl('pages/report-profit-sales.php') ?>" class="p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition text-center">
            <i class="fas fa-chart-line text-blue-600 text-lg mb-1"></i>
            <p class="text-sm font-medium">Profit & Sales</p>
        </a>
        <a href="<?= adminUrl('pages/report-orders.php') ?>" class="p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition text-center">
            <i class="fas fa-clipboard-list text-indigo-600 text-lg mb-1"></i>
            <p class="text-sm font-medium">Order Report</p>
        </a>
        <a href="<?= adminUrl('pages/report-products.php') ?>" class="p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition text-center">
            <i class="fas fa-boxes text-green-600 text-lg mb-1"></i>
            <p class="text-sm font-medium">Product Report</p>
        </a>
        <a href="<?= adminUrl('pages/report-employees.php') ?>" class="p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition text-center">
            <i class="fas fa-users text-purple-600 text-lg mb-1"></i>
            <p class="text-sm font-medium">Employee Report</p>
        </a>
        <a href="<?= adminUrl('pages/report-web-orders.php') ?>" class="p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition text-center">
            <i class="fas fa-globe text-teal-600 text-lg mb-1"></i>
            <p class="text-sm font-medium">Web Orders</p>
        </a>
        <a href="<?= adminUrl('pages/report-meta-ads.php') ?>" class="p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition text-center">
            <i class="fab fa-facebook text-blue-600 text-lg mb-1"></i>
            <p class="text-sm font-medium">Meta Ads</p>
        </a>
        <a href="<?= adminUrl('pages/inventory-dashboard.php') ?>" class="p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition text-center">
            <i class="fas fa-warehouse text-orange-600 text-lg mb-1"></i>
            <p class="text-sm font-medium">Inventory</p>
        </a>
        <a href="<?= adminUrl('pages/accounting.php') ?>" class="p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition text-center">
            <i class="fas fa-calculator text-red-600 text-lg mb-1"></i>
            <p class="text-sm font-medium">Accounting</p>
        </a>
    </div>
</div>

<script>
const daily = <?= json_encode($dailyRevenue) ?>;
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: daily.map(d => { const dt = new Date(d.date); return dt.toLocaleDateString('en',{month:'short',day:'numeric'}); }),
        datasets: [
            { label: 'Revenue (৳)', data: daily.map(d => d.revenue), borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', fill: true, tension: 0.4 },
            { label: 'Orders', data: daily.map(d => d.orders), borderColor: '#22c55e', borderDash: [5,5], fill: false, tension: 0.4, yAxisID: 'y1' }
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } } } }
});

const channels = <?= json_encode($channels) ?>;
new Chart(document.getElementById('channelChart'), {
    type: 'doughnut',
    data: {
        labels: channels.map(c => (c.channel||'direct').charAt(0).toUpperCase() + (c.channel||'direct').slice(1)),
        datasets: [{ data: channels.map(c => c.revenue), backgroundColor: ['#3b82f6','#22c55e','#f59e0b','#ef4444','#8b5cf6','#6b7280'] }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
