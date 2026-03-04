<?php
/**
 * Product Report - Product performance & profitability
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Product Report';
$db = Database::getInstance();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$catFilter = intval($_GET['category'] ?? 0);
$sortBy = $_GET['sort'] ?? 'revenue';

$catWhere = $catFilter ? " AND p.category_id = $catFilter" : "";

// Product performance
$products = $db->fetchAll("SELECT p.id, p.name, p.sku, p.featured_image, p.stock_quantity, p.cost_price,
    COALESCE(p.sale_price, p.regular_price) as selling_price, p.views, c.name as category_name,
    COALESCE(SUM(oi.quantity),0) as units_sold,
    COALESCE(SUM(oi.subtotal),0) as revenue,
    COALESCE(SUM(oi.quantity * COALESCE(p.cost_price,0)),0) as total_cost,
    COUNT(DISTINCT o.id) as order_count,
    COUNT(DISTINCT CASE WHEN o.order_status='returned' THEN o.id END) as return_count,
    COUNT(DISTINCT CASE WHEN o.order_status='cancelled' THEN o.id END) as cancel_count
    FROM products p
    LEFT JOIN categories c ON p.category_id=c.id
    LEFT JOIN order_items oi ON p.id=oi.product_id
    LEFT JOIN orders o ON oi.order_id=o.id AND DATE(o.created_at) BETWEEN ? AND ? AND o.order_status NOT IN ('cancelled','returned')
    WHERE p.is_active=1 $catWhere
    GROUP BY p.id
    ORDER BY " . match($sortBy) { 'units' => 'units_sold DESC', 'margin' => '(revenue - total_cost) DESC', 'views' => 'p.views DESC', 'stock' => 'p.stock_quantity ASC', default => 'revenue DESC' } . "
    LIMIT 50", [$dateFrom, $dateTo]);

// Category summary
$categories = $db->fetchAll("SELECT c.id, c.name, COUNT(DISTINCT p.id) as products,
    COALESCE(SUM(oi.quantity),0) as units, COALESCE(SUM(oi.subtotal),0) as rev
    FROM categories c
    LEFT JOIN products p ON c.id=p.category_id AND p.is_active=1
    LEFT JOIN order_items oi ON p.id=oi.product_id
    LEFT JOIN orders o ON oi.order_id=o.id AND DATE(o.created_at) BETWEEN ? AND ? AND o.order_status NOT IN ('cancelled','returned')
    GROUP BY c.id ORDER BY rev DESC", [$dateFrom, $dateTo]);

// Overall stats
$totalRevenue = array_sum(array_column($products, 'revenue'));
$totalCost = array_sum(array_column($products, 'total_cost'));
$totalProfit = $totalRevenue - $totalCost;
$overallMargin = $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : 0;

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="product-report-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Product', 'SKU', 'Category', 'Units Sold', 'Revenue', 'Cost', 'Profit', 'Margin %', 'Stock', 'Views', 'Returns']);
    foreach ($products as $p) {
        $profit = $p['revenue'] - $p['total_cost'];
        $margin = $p['revenue'] > 0 ? round(($profit / $p['revenue']) * 100, 1) : 0;
        fputcsv($output, [$p['name'], $p['sku'] ?? '', $p['category_name'] ?? '', $p['units_sold'], $p['revenue'], $p['total_cost'], $profit, $margin, $p['stock_quantity'], $p['views'], $p['return_count']]);
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
        <select name="category" class="border rounded-lg px-3 py-1.5 text-sm" onchange="this.form.submit()">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $catFilter==$cat['id']?'selected':'' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="sort" class="border rounded-lg px-3 py-1.5 text-sm" onchange="this.form.submit()">
            <?php foreach (['revenue'=>'Revenue','units'=>'Units Sold','margin'=>'Profit','views'=>'Views','stock'=>'Low Stock'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $sortBy===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&category=<?= $catFilter ?>&export=csv" class="ml-auto bg-gray-100 text-gray-700 px-4 py-1.5 rounded-lg text-sm hover:bg-gray-200">Export CSV</a>
    </form>
</div>

<!-- Summary -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500">Total Revenue</p><p class="text-xl font-bold text-gray-800">৳<?= number_format($totalRevenue) ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500">Total Cost</p><p class="text-xl font-bold text-red-600">৳<?= number_format($totalCost) ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500">Total Profit</p><p class="text-xl font-bold text-green-600">৳<?= number_format($totalProfit) ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500">Overall Margin</p><p class="text-xl font-bold <?= $overallMargin>=0?'text-green-600':'text-red-600' ?>"><?= $overallMargin ?>%</p></div>
</div>

<!-- Product Table -->
<div class="bg-white rounded-xl border shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">#</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Product</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Category</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Units</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Revenue</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Cost</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Profit</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Margin</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Stock</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Views</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Returns</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($products as $i => $p): 
                    $profit = $p['revenue'] - $p['total_cost'];
                    $margin = $p['revenue'] > 0 ? round(($profit / $p['revenue']) * 100, 1) : 0;
                    $convRate = $p['views'] > 0 ? round(($p['order_count'] / $p['views']) * 100, 1) : 0;
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-400"><?= $i+1 ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <?php if ($p['featured_image']): ?>
                            <img src="/uploads/products/<?= e($p['featured_image']) ?>" class="w-8 h-8 rounded object-cover" alt="">
                            <?php endif; ?>
                            <div class="min-w-0">
                                <p class="font-medium text-gray-800 truncate max-w-[200px]"><?= e($p['name']) ?></p>
                                <?php if ($p['sku']): ?><p class="text-xs text-gray-400"><?= e($p['sku']) ?></p><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= e($p['category_name'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-center font-medium"><?= number_format($p['units_sold']) ?></td>
                    <td class="px-4 py-3 text-right">৳<?= number_format($p['revenue']) ?></td>
                    <td class="px-4 py-3 text-right text-gray-500">৳<?= number_format($p['total_cost']) ?></td>
                    <td class="px-4 py-3 text-right font-semibold <?= $profit>=0?'text-green-600':'text-red-600' ?>">৳<?= number_format($profit) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $margin >= 30 ? 'bg-green-100 text-green-700' : ($margin >= 10 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') ?>"><?= $margin ?>%</span>
                    </td>
                    <td class="px-4 py-3 text-center <?= $p['stock_quantity'] <= 5 ? 'text-red-600 font-semibold' : 'text-gray-600' ?>"><?= $p['stock_quantity'] ?></td>
                    <td class="px-4 py-3 text-center text-gray-500"><?= number_format($p['views']) ?></td>
                    <td class="px-4 py-3 text-center text-red-500"><?= $p['return_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Category Summary -->
<div class="bg-white rounded-xl border shadow-sm mt-6">
    <div class="p-4 border-b"><h4 class="font-semibold text-gray-800">Category Summary</h4></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Category</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Products</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Units Sold</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Revenue</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($categories as $cat): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium"><?= e($cat['name']) ?></td>
                    <td class="px-4 py-3 text-center text-gray-600"><?= $cat['products'] ?></td>
                    <td class="px-4 py-3 text-center"><?= number_format($cat['units']) ?></td>
                    <td class="px-4 py-3 text-right font-semibold">৳<?= number_format($cat['rev']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
