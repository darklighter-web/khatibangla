<?php
/**
 * Inventory Dashboard - Visual overview of stock levels, movements, and analytics
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Inventory Dashboard';
$db = Database::getInstance();

// Summary stats
$totalProducts = $db->fetch("SELECT COUNT(*) as cnt FROM products WHERE is_active=1")['cnt'] ?? 0;
$inStock = $db->fetch("SELECT COUNT(*) as cnt FROM products WHERE is_active=1 AND stock_quantity > low_stock_threshold")['cnt'] ?? 0;
$lowStock = $db->fetch("SELECT COUNT(*) as cnt FROM products WHERE is_active=1 AND stock_quantity > 0 AND stock_quantity <= low_stock_threshold")['cnt'] ?? 0;
$outOfStock = $db->fetch("SELECT COUNT(*) as cnt FROM products WHERE is_active=1 AND stock_quantity <= 0")['cnt'] ?? 0;
$totalStockValue = $db->fetch("SELECT COALESCE(SUM(stock_quantity * cost_price),0) as val FROM products WHERE is_active=1 AND cost_price > 0")['val'] ?? 0;
$totalRetailValue = $db->fetch("SELECT COALESCE(SUM(stock_quantity * COALESCE(sale_price, regular_price)),0) as val FROM products WHERE is_active=1")['val'] ?? 0;
$totalUnits = $db->fetch("SELECT COALESCE(SUM(stock_quantity),0) as total FROM products WHERE is_active=1")['total'] ?? 0;

// Movement trends (last 30 days)
$movements30d = $db->fetchAll("SELECT DATE(created_at) as date, movement_type, SUM(quantity) as qty
    FROM stock_movements WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at), movement_type ORDER BY date");
$movementData = [];
foreach ($movements30d as $m) {
    $movementData[$m['date']][$m['movement_type']] = (int)$m['qty'];
}

// Top movers (most stock movements)
$topMovers = $db->fetchAll("SELECT p.id, p.name, p.stock_quantity, p.featured_image,
    SUM(CASE WHEN sm.movement_type='in' THEN sm.quantity ELSE 0 END) as total_in,
    SUM(CASE WHEN sm.movement_type='out' THEN sm.quantity ELSE 0 END) as total_out
    FROM stock_movements sm
    JOIN products p ON sm.product_id=p.id
    WHERE sm.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY p.id, p.name, p.stock_quantity, p.featured_image
    ORDER BY (SUM(CASE WHEN sm.movement_type='in' THEN sm.quantity ELSE 0 END) +
              SUM(CASE WHEN sm.movement_type='out' THEN sm.quantity ELSE 0 END)) DESC LIMIT 10");

// Stock by category
$stockByCategory = $db->fetchAll("SELECT c.name, COUNT(p.id) as products, COALESCE(SUM(p.stock_quantity),0) as total_stock,
    COALESCE(SUM(p.stock_quantity * p.cost_price),0) as stock_value
    FROM products p LEFT JOIN categories c ON p.category_id=c.id
    WHERE p.is_active=1 GROUP BY c.id ORDER BY stock_value DESC LIMIT 8");

// Warehouse breakdown
$warehouseStats = $db->fetchAll("SELECT w.name, w.code,
    COUNT(DISTINCT ws.product_id) as products,
    COALESCE(SUM(ws.quantity),0) as total_units,
    COALESCE(SUM(ws.quantity * p.cost_price),0) as stock_value
    FROM warehouses w
    LEFT JOIN warehouse_stock ws ON w.id=ws.warehouse_id
    LEFT JOIN products p ON ws.product_id=p.id
    WHERE w.is_active=1 GROUP BY w.id ORDER BY stock_value DESC");

// Recent movements
$recentMovements = $db->fetchAll("SELECT sm.*, p.name as product_name, w.name as warehouse_name, au.full_name as user_name
    FROM stock_movements sm
    LEFT JOIN products p ON sm.product_id=p.id
    LEFT JOIN warehouses w ON sm.warehouse_id=w.id
    LEFT JOIN admin_users au ON sm.created_by=au.id
    ORDER BY sm.created_at DESC LIMIT 15");

// Pending transfers
$pendingTransfers = $db->fetch("SELECT COUNT(*) as cnt FROM stock_transfers WHERE status IN ('pending','approved')")['cnt'] ?? 0;

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-gray-500 uppercase tracking-wide">Total Products</span>
            <span class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center"><svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg></span>
        </div>
        <p class="text-2xl font-bold text-gray-800"><?= number_format($totalProducts) ?></p>
        <div class="flex gap-3 mt-2 text-xs">
            <span class="text-green-600"><?= $inStock ?> in stock</span>
            <span class="text-yellow-600"><?= $lowStock ?> low</span>
            <span class="text-red-600"><?= $outOfStock ?> out</span>
        </div>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-gray-500 uppercase tracking-wide">Total Units</span>
            <span class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center"><svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg></span>
        </div>
        <p class="text-2xl font-bold text-gray-800"><?= number_format($totalUnits) ?></p>
        <p class="text-xs text-gray-400 mt-1">Across all warehouses</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-gray-500 uppercase tracking-wide">Stock Value (Cost)</span>
            <span class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center"><svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1"/></svg></span>
        </div>
        <p class="text-2xl font-bold text-gray-800">৳<?= number_format($totalStockValue) ?></p>
        <p class="text-xs text-gray-400 mt-1">Retail: ৳<?= number_format($totalRetailValue) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-gray-500 uppercase tracking-wide">Pending Actions</span>
            <span class="w-8 h-8 bg-orange-100 rounded-lg flex items-center justify-center"><svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
        </div>
        <p class="text-2xl font-bold text-gray-800"><?= $pendingTransfers + $lowStock ?></p>
        <div class="flex gap-3 mt-2 text-xs">
            <span class="text-orange-600"><?= $pendingTransfers ?> transfers</span>
            <span class="text-yellow-600"><?= $lowStock ?> low stock</span>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <!-- Stock Movement Trend -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Stock Movement (30 Days)</h4>
        <canvas id="movementChart" height="150"></canvas>
    </div>
    <!-- Stock by Category -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Stock Value by Category</h4>
        <canvas id="categoryChart" height="150"></canvas>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6 mb-6">
    <!-- Warehouse Breakdown -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Warehouse Overview</h4>
        <div class="space-y-3">
            <?php foreach ($warehouseStats as $ws): ?>
            <div class="p-3 bg-gray-50 rounded-lg">
                <div class="flex justify-between items-center mb-1">
                    <span class="font-medium text-sm"><?= e($ws['name']) ?></span>
                    <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full"><?= e($ws['code']) ?></span>
                </div>
                <div class="flex justify-between text-xs text-gray-500">
                    <span><?= $ws['products'] ?> products</span>
                    <span><?= number_format($ws['total_units']) ?> units</span>
                    <span class="font-medium text-gray-700">৳<?= number_format($ws['stock_value']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($warehouseStats)): ?><p class="text-sm text-gray-400">No warehouses</p><?php endif; ?>
        </div>
    </div>

    <!-- Top Movers -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Top Movers (30 Days)</h4>
        <div class="space-y-2">
            <?php foreach ($topMovers as $tm): ?>
            <div class="flex items-center gap-2 py-1.5">
                <?php if ($tm['featured_image']): ?><img src="<?= uploadUrl($tm['featured_image']) ?>" class="w-7 h-7 rounded object-cover"><?php endif; ?>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-800 truncate"><?= e($tm['name']) ?></p>
                    <div class="flex gap-3 text-xs">
                        <span class="text-green-600">+<?= $tm['total_in'] ?> in</span>
                        <span class="text-red-600">-<?= $tm['total_out'] ?> out</span>
                    </div>
                </div>
                <span class="text-sm font-semibold"><?= $tm['stock_quantity'] ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($topMovers)): ?><p class="text-sm text-gray-400">No movements yet</p><?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Recent Activity</h4>
        <div class="space-y-2 max-h-[400px] overflow-y-auto">
            <?php foreach ($recentMovements as $rm): ?>
            <?php $isIn = in_array($rm['movement_type'], ['in', 'return']); ?>
            <div class="flex items-center gap-2 py-1.5 border-b border-gray-50">
                <span class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold <?= $isIn ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' ?>"><?= $isIn ? '+' : '-' ?></span>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-medium text-gray-800 truncate"><?= e($rm['product_name'] ?? 'Unknown') ?></p>
                    <p class="text-xs text-gray-400"><?= e($rm['warehouse_name'] ?? '') ?> · <?= e($rm['user_name'] ?? '') ?></p>
                </div>
                <div class="text-right">
                    <span class="text-xs font-bold <?= $isIn ? 'text-green-600' : 'text-red-600' ?>"><?= $isIn ? '+' : '-' ?><?= $rm['quantity'] ?></span>
                    <p class="text-xs text-gray-400"><?= date('M d', strtotime($rm['created_at'])) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <a href="<?= adminUrl('pages/stock-increase-new.php') ?>" class="bg-white rounded-xl border p-4 hover:shadow-md transition-shadow text-center">
        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mx-auto mb-2"><svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6"/></svg></div>
        <p class="text-sm font-medium text-gray-800">Increase Stock</p>
    </a>
    <a href="<?= adminUrl('pages/stock-decrease-new.php') ?>" class="bg-white rounded-xl border p-4 hover:shadow-md transition-shadow text-center">
        <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mx-auto mb-2"><svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg></div>
        <p class="text-sm font-medium text-gray-800">Decrease Stock</p>
    </a>
    <a href="<?= adminUrl('pages/stock-transfer.php?tab=new') ?>" class="bg-white rounded-xl border p-4 hover:shadow-md transition-shadow text-center">
        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mx-auto mb-2"><svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg></div>
        <p class="text-sm font-medium text-gray-800">Transfer Stock</p>
    </a>
    <a href="<?= adminUrl('pages/smart-restock.php') ?>" class="bg-white rounded-xl border p-4 hover:shadow-md transition-shadow text-center">
        <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mx-auto mb-2"><svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg></div>
        <p class="text-sm font-medium text-gray-800">Smart Restock</p>
    </a>
</div>

<script>
// Movement Chart
const movementData = <?= json_encode($movementData) ?>;
const dates = Object.keys(movementData).sort();
new Chart(document.getElementById('movementChart'), {
    type: 'bar',
    data: {
        labels: dates.map(d => { const dt = new Date(d); return dt.toLocaleDateString('en',{month:'short',day:'numeric'}); }),
        datasets: [
            { label: 'Stock In', data: dates.map(d => movementData[d]?.['in'] || 0), backgroundColor: 'rgba(34,197,94,0.6)', borderRadius: 3 },
            { label: 'Stock Out', data: dates.map(d => movementData[d]?.['out'] || 0), backgroundColor: 'rgba(239,68,68,0.6)', borderRadius: 3 }
        ]
    },
    options: { responsive: true, plugins: {legend:{position:'bottom'}}, scales: {x:{stacked:false},y:{beginAtZero:true}} }
});

// Category Chart
const catData = <?= json_encode($stockByCategory) ?>;
new Chart(document.getElementById('categoryChart'), {
    type: 'doughnut',
    data: {
        labels: catData.map(c => c.name || 'Uncategorized'),
        datasets: [{ data: catData.map(c => c.stock_value), backgroundColor: ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#14b8a6','#f97316'] }]
    },
    options: { responsive: true, plugins: {legend:{position:'bottom',labels:{boxWidth:12,padding:8}}} }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
