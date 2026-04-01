<?php
/**
 * Stock Increase - Overview & Summary
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Stock Increase';
$db = Database::getInstance();

$period = $_GET['period'] ?? '30';
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime("-{$period} days"));
$dateTo = $_GET['to'] ?? date('Y-m-d');

// Increase summary
$summary = $db->fetch("SELECT COUNT(*) as total_entries, COALESCE(SUM(quantity),0) as total_qty, COUNT(DISTINCT product_id) as products_affected
    FROM stock_movements WHERE movement_type='in' AND DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);

// Daily trend
$dailyTrend = $db->fetchAll("SELECT DATE(created_at) as date, SUM(quantity) as qty, COUNT(*) as entries
    FROM stock_movements WHERE movement_type='in' AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at) ORDER BY date", [$dateFrom, $dateTo]);

// By source/reference_type
$bySource = $db->fetchAll("SELECT COALESCE(reference_type,'manual') as source, SUM(quantity) as qty, COUNT(*) as entries
    FROM stock_movements WHERE movement_type='in' AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY reference_type ORDER BY qty DESC", [$dateFrom, $dateTo]);

// By warehouse
$byWarehouse = $db->fetchAll("SELECT w.name, SUM(sm.quantity) as qty, COUNT(*) as entries
    FROM stock_movements sm LEFT JOIN warehouses w ON sm.warehouse_id=w.id
    WHERE sm.movement_type='in' AND DATE(sm.created_at) BETWEEN ? AND ?
    GROUP BY sm.warehouse_id ORDER BY qty DESC", [$dateFrom, $dateTo]);

// Top products restocked
$topRestocked = $db->fetchAll("SELECT p.name, p.sku, p.featured_image, SUM(sm.quantity) as qty, COUNT(*) as times
    FROM stock_movements sm LEFT JOIN products p ON sm.product_id=p.id
    WHERE sm.movement_type='in' AND DATE(sm.created_at) BETWEEN ? AND ?
    GROUP BY sm.product_id ORDER BY qty DESC LIMIT 10", [$dateFrom, $dateTo]);

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Quick Actions -->
<div class="flex gap-3 mb-6">
    <a href="<?= adminUrl('pages/stock-increase-new.php') ?>" class="bg-green-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-green-700 flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
        New Increase
    </a>
    <a href="<?= adminUrl('pages/stock-increase-list.php') ?>" class="bg-gray-100 text-gray-700 px-4 py-2.5 rounded-lg text-sm hover:bg-gray-200">View Full History</a>
</div>

<!-- Period Filter -->
<div class="bg-white rounded-xl border shadow-sm p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <div class="flex gap-2">
            <?php foreach (['7'=>'7 Days','30'=>'30 Days','90'=>'90 Days','365'=>'1 Year'] as $d=>$l): ?>
            <a href="?period=<?= $d ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium <?= $period == $d ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>
        <div class="flex items-center gap-2 ml-auto">
            <input type="date" name="from" value="<?= $dateFrom ?>" class="px-3 py-1.5 border rounded-lg text-sm">
            <span class="text-gray-400">to</span>
            <input type="date" name="to" value="<?= $dateTo ?>" class="px-3 py-1.5 border rounded-lg text-sm">
            <button class="bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-green-700">Apply</button>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-green-600">+<?= number_format($summary['total_qty'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Units Added</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-gray-800"><?= number_format($summary['total_entries'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Entries</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-blue-600"><?= number_format($summary['products_affected'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Products Restocked</p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <!-- Daily Trend -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Daily Stock Increase Trend</h4>
        <canvas id="trendChart" height="150"></canvas>
    </div>

    <!-- By Source -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">By Source</h4>
        <?php foreach ($bySource as $src): ?>
        <div class="flex justify-between items-center py-2 border-b last:border-0">
            <span class="text-sm capitalize"><?= e($src['source'] ?: 'Manual') ?></span>
            <div class="text-right">
                <span class="font-semibold text-sm text-green-600">+<?= number_format($src['qty']) ?></span>
                <span class="text-xs text-gray-400 ml-2">(<?= $src['entries'] ?> entries)</span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($bySource)): ?><p class="text-sm text-gray-400">No data</p><?php endif; ?>

        <h4 class="font-semibold text-gray-800 mb-4 mt-6">By Warehouse</h4>
        <?php foreach ($byWarehouse as $wh): ?>
        <div class="flex justify-between items-center py-2 border-b last:border-0">
            <span class="text-sm"><?= e($wh['name'] ?? 'Unknown') ?></span>
            <span class="font-semibold text-sm text-green-600">+<?= number_format($wh['qty']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Top Restocked Products -->
<div class="bg-white rounded-xl border shadow-sm">
    <div class="p-4 border-b"><h4 class="font-semibold text-gray-800">Top Restocked Products</h4></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">#</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Product</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Total Added</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Times Restocked</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($topRestocked as $i => $tp): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-400"><?= $i + 1 ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <?php if ($tp['featured_image']): ?>
                            <img src="/uploads/products/<?= e($tp['featured_image']) ?>" class="w-8 h-8 rounded object-cover" alt="">
                            <?php endif; ?>
                            <div>
                                <p class="font-medium text-gray-800"><?= e($tp['name'] ?? 'Unknown') ?></p>
                                <?php if ($tp['sku']): ?><p class="text-xs text-gray-400"><?= e($tp['sku']) ?></p><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center"><span class="font-semibold text-green-600">+<?= number_format($tp['qty']) ?></span></td>
                    <td class="px-4 py-3 text-center text-gray-500"><?= $tp['times'] ?>x</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($topRestocked)): ?>
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No data</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const dailyData = <?= json_encode($dailyTrend) ?>;
new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: dailyData.map(d => { const dt = new Date(d.date); return dt.toLocaleDateString('en-US',{month:'short',day:'numeric'}); }),
        datasets: [{ label: 'Units Added', data: dailyData.map(d => parseInt(d.qty)), backgroundColor: 'rgba(34,197,94,0.5)', borderColor: '#22c55e', borderWidth: 1, borderRadius: 4 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
