<?php
/**
 * Inventory Dashboard
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Inventory Dashboard';
$db = Database::getInstance();

// ── Date range filter ───────────────────────────────────────────────────────
$range = intval($_GET['range'] ?? 7);
if (!in_array($range, [7, 30, 90])) $range = 7;
$rangeLabel = ['7'=>'Last 7 days','30'=>'Last 30 days','90'=>'Last 90 days'][$range];

// ── Stock Health Overview ───────────────────────────────────────────────────
$totalProducts  = intval($db->fetch("SELECT COUNT(*) as c FROM products WHERE is_active=1")['c'] ?? 0);
$healthyStock   = intval($db->fetch("SELECT COUNT(*) as c FROM products WHERE is_active=1 AND stock_quantity > low_stock_threshold")['c'] ?? 0);
$lowStock       = intval($db->fetch("SELECT COUNT(*) as c FROM products WHERE is_active=1 AND stock_quantity > 0 AND stock_quantity <= low_stock_threshold")['c'] ?? 0);
$outOfStock     = intval($db->fetch("SELECT COUNT(*) as c FROM products WHERE is_active=1 AND stock_quantity = 0")['c'] ?? 0);
$negativeStock  = intval($db->fetch("SELECT COUNT(*) as c FROM products WHERE is_active=1 AND stock_quantity < 0")['c'] ?? 0);

// ── Stock Movement Summary ──────────────────────────────────────────────────
// Units Sold: from order_items linked to delivered/shipped orders in range
$unitsSoldRow = $db->fetch("
    SELECT COALESCE(SUM(oi.quantity), 0) as qty
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE o.order_status IN ('delivered','shipped','partial_delivered')
      AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL {$range} DAY)
") ?? [];
$unitsSold = intval($unitsSoldRow['qty'] ?? 0);

// Units Purchased: stock_movements type='in' in range
$unitsPurchasedRow = $db->fetch("
    SELECT COALESCE(SUM(quantity), 0) as qty
    FROM stock_movements
    WHERE movement_type = 'in'
      AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$range} DAY)
") ?? [];
$unitsPurchased = intval($unitsPurchasedRow['qty'] ?? 0);

// Returns: stock_movements type='return' in range
$returnsRow = $db->fetch("
    SELECT COALESCE(SUM(quantity), 0) as qty
    FROM stock_movements
    WHERE movement_type = 'return'
      AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$range} DAY)
") ?? [];
$returns = intval($returnsRow['qty'] ?? 0);

// Total movements in range
$totalMovementsRow = $db->fetch("
    SELECT COUNT(*) as c,
           COALESCE(SUM(CASE WHEN movement_type IN ('in','return') THEN quantity ELSE 0 END), 0) as total_in,
           COALESCE(SUM(CASE WHEN movement_type IN ('out','transfer') THEN quantity ELSE 0 END), 0) as total_out
    FROM stock_movements
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL {$range} DAY)
") ?? [];
$totalMovements = intval($totalMovementsRow['c'] ?? 0);
$netChange = intval(($totalMovementsRow['total_in'] ?? 0) - ($totalMovementsRow['total_out'] ?? 0));

// ── Negative Stock Products ─────────────────────────────────────────────────
$negativeProducts = $db->fetchAll("
    SELECT id, name, sku, stock_quantity
    FROM products
    WHERE is_active=1 AND stock_quantity < 0
    ORDER BY stock_quantity ASC
    LIMIT 20
") ?: [];

// ── Low Stock Products ──────────────────────────────────────────────────────
$lowStockProducts = $db->fetchAll("
    SELECT id, name, sku, stock_quantity, low_stock_threshold
    FROM products
    WHERE is_active=1 AND stock_quantity > 0 AND stock_quantity <= low_stock_threshold
    ORDER BY stock_quantity ASC
    LIMIT 20
") ?: [];

// ── Out of Stock Products ───────────────────────────────────────────────────
$outOfStockProducts = $db->fetchAll("
    SELECT id, name, sku, stock_quantity, sales_count
    FROM products
    WHERE is_active=1 AND stock_quantity = 0
    ORDER BY sales_count DESC
    LIMIT 20
") ?: [];

// ── Recent Stock Movements ──────────────────────────────────────────────────
$recentMovements = $db->fetchAll("
    SELECT sm.movement_type, sm.quantity, sm.note, sm.created_at,
           p.name as product_name, p.sku,
           au.full_name as user_name
    FROM stock_movements sm
    LEFT JOIN products p ON sm.product_id = p.id
    LEFT JOIN admin_users au ON sm.created_by = au.id
    ORDER BY sm.created_at DESC
    LIMIT 10
") ?: [];

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-500">Total Products</p>
            <svg class="w-4 h-4 text-blue-400 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
        </div>
        <p class="text-2xl font-bold text-blue-600"><?= number_format($totalProducts) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-500">Healthy Stock</p>
            <svg class="w-4 h-4 text-green-400 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-2xl font-bold text-green-600"><?= number_format($healthyStock) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-500">Low Stock</p>
            <svg class="w-4 h-4 text-yellow-400 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </div>
        <p class="text-2xl font-bold text-yellow-600"><?= number_format($lowStock) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-500">Out of Stock</p>
            <svg class="w-4 h-4 text-red-400 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-2xl font-bold text-red-600"><?= number_format($outOfStock) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-500">Negative Stock</p>
            <svg class="w-4 h-4 text-purple-400 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/></svg>
        </div>
        <p class="text-2xl font-bold text-purple-600"><?= number_format($negativeStock) ?></p>
    </div>
</div>

<!-- Movement Summary -->
<div class="flex items-center justify-between mb-4">
    <h2 class="text-base font-bold text-gray-800">Stock Movement Summary</h2>
    <form method="GET" class="flex items-center gap-2">
        <select name="range" onchange="this.form.submit()" class="border rounded-lg px-3 py-1.5 text-sm">
            <?php foreach ([7=>'Last 7 days', 30=>'Last 30 days', 90=>'Last 90 days'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= $range==$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500 mb-1">Units Sold</p>
        <p class="text-2xl font-bold text-red-600">-<?= number_format($unitsSold) ?></p>
        <p class="text-xs text-gray-400 mt-1">from order deliveries</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500 mb-1">Units Purchased</p>
        <p class="text-2xl font-bold text-green-600">+<?= number_format($unitsPurchased) ?></p>
        <p class="text-xs text-gray-400 mt-1">from purchase receipts</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500 mb-1">Returns</p>
        <p class="text-2xl font-bold text-yellow-600">+<?= number_format($returns) ?></p>
        <p class="text-xs text-gray-400 mt-1">items returned</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500 mb-1">Net Change</p>
        <p class="text-2xl font-bold <?= $netChange >= 0 ? 'text-green-600' : 'text-red-600' ?>"><?= ($netChange >= 0 ? '+' : '') . number_format($netChange) ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= number_format($totalMovements) ?> total movements</p>
    </div>
</div>

<!-- Attention Required -->
<h2 class="text-base font-bold text-gray-800 mb-4">Attention Required</h2>

<?php if (!empty($negativeProducts)): ?>
<div class="bg-white rounded-xl border border-red-200 mb-5 overflow-hidden">
    <div class="px-5 py-4 border-b bg-red-50">
        <div class="flex items-center gap-2 text-red-600 font-semibold text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            Negative Stock (<?= count($negativeProducts) ?>)
        </div>
        <p class="text-gray-500 text-xs mt-1">Products with stock below zero require immediate attention.</p>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50"><tr>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">Product</th>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">SKU</th>
            <th class="text-center px-4 py-2.5 font-medium text-gray-600">Stock</th>
            <th class="px-4 py-2.5"></th>
        </tr></thead>
        <tbody class="divide-y">
            <?php foreach ($negativeProducts as $p): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium"><?= e($p['name']) ?></td>
                <td class="px-4 py-3 text-gray-500"><?= e($p['sku'] ?: '—') ?></td>
                <td class="px-4 py-3 text-center"><span class="text-xs font-bold px-2.5 py-1 rounded-full bg-red-100 text-red-700"><?= $p['stock_quantity'] ?></span></td>
                <td class="px-4 py-3 text-right"><a href="<?= adminUrl('pages/inventory.php?tab=stock&search=' . urlencode($p['sku'] ?: $p['name'])) ?>" class="text-xs text-blue-600 hover:underline">View</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($lowStockProducts)): ?>
<div class="bg-white rounded-xl border border-yellow-200 mb-5 overflow-hidden">
    <div class="px-5 py-4 border-b bg-yellow-50">
        <div class="flex items-center gap-2 text-yellow-700 font-semibold text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            Low Stock (<?= count($lowStockProducts) ?>)
        </div>
        <p class="text-gray-500 text-xs mt-1">Products below their reorder threshold.</p>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50"><tr>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">Product</th>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">SKU</th>
            <th class="text-center px-4 py-2.5 font-medium text-gray-600">Stock</th>
            <th class="text-center px-4 py-2.5 font-medium text-gray-600">Threshold</th>
            <th class="px-4 py-2.5"></th>
        </tr></thead>
        <tbody class="divide-y">
            <?php foreach ($lowStockProducts as $p): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium"><?= e($p['name']) ?></td>
                <td class="px-4 py-3 text-gray-500"><?= e($p['sku'] ?: '—') ?></td>
                <td class="px-4 py-3 text-center"><span class="text-xs font-bold px-2.5 py-1 rounded-full bg-yellow-100 text-yellow-700"><?= $p['stock_quantity'] ?></span></td>
                <td class="px-4 py-3 text-center text-gray-500"><?= $p['low_stock_threshold'] ?></td>
                <td class="px-4 py-3 text-right"><a href="<?= adminUrl('pages/inventory.php?tab=stock&search=' . urlencode($p['sku'] ?: $p['name'])) ?>" class="text-xs text-green-600 hover:underline font-medium">Restock</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($outOfStockProducts)): ?>
<div class="bg-white rounded-xl border border-blue-200 mb-5 overflow-hidden">
    <div class="px-5 py-4 border-b bg-blue-50">
        <div class="flex items-center gap-2 text-blue-700 font-semibold text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Out of Stock (<?= count($outOfStockProducts) ?>)
        </div>
        <p class="text-gray-500 text-xs mt-1">Products with zero inventory.</p>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50"><tr>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">Product</th>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">SKU</th>
            <th class="text-center px-4 py-2.5 font-medium text-gray-600">Total Sold</th>
            <th class="px-4 py-2.5"></th>
        </tr></thead>
        <tbody class="divide-y">
            <?php foreach ($outOfStockProducts as $p): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium"><?= e($p['name']) ?></td>
                <td class="px-4 py-3 text-gray-500"><?= e($p['sku'] ?: '—') ?></td>
                <td class="px-4 py-3 text-center text-gray-500"><?= $p['sales_count'] ?></td>
                <td class="px-4 py-3 text-right"><a href="<?= adminUrl('pages/inventory.php?tab=stock&search=' . urlencode($p['sku'] ?: $p['name'])) ?>" class="text-xs text-green-600 hover:underline font-medium">Restock</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (empty($negativeProducts) && empty($lowStockProducts) && empty($outOfStockProducts)): ?>
<div class="bg-white rounded-xl border p-5 flex items-center gap-3 text-green-600 mb-5">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span class="text-sm font-medium">All products are healthy — no attention required.</span>
</div>
<?php endif; ?>

<!-- Recent Movements -->
<?php if (!empty($recentMovements)): ?>
<h2 class="text-base font-bold text-gray-800 mb-4">Recent Movements</h2>
<div class="bg-white rounded-xl border shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50"><tr>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">Type</th>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">Product</th>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">SKU</th>
            <th class="text-center px-4 py-2.5 font-medium text-gray-600">Qty</th>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">By</th>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">Note</th>
            <th class="text-left px-4 py-2.5 font-medium text-gray-600">Date</th>
        </tr></thead>
        <tbody class="divide-y">
            <?php foreach ($recentMovements as $m):
                $isIn = in_array($m['movement_type'], ['in','return']);
                $typeBadges = ['in'=>'bg-green-100 text-green-700','out'=>'bg-red-100 text-red-700','return'=>'bg-yellow-100 text-yellow-700','adjustment'=>'bg-indigo-100 text-indigo-700','transfer'=>'bg-blue-100 text-blue-700'];
                $typeLabels = ['in'=>'IN','out'=>'OUT','return'=>'RETURN','adjustment'=>'ADJUST','transfer'=>'TRANSFER'];
                $badge = $typeBadges[$m['movement_type']] ?? 'bg-gray-100 text-gray-700';
                $label = $typeLabels[$m['movement_type']] ?? strtoupper($m['movement_type']);
            ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3"><span class="text-[10px] font-bold px-2 py-0.5 rounded <?= $badge ?>"><?= $label ?></span></td>
                <td class="px-4 py-3 font-medium"><?= e($m['product_name'] ?? '—') ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= e($m['sku'] ?? '—') ?></td>
                <td class="px-4 py-3 text-center font-bold <?= $isIn ? 'text-green-600' : 'text-red-600' ?>"><?= ($isIn?'+':'-') . abs($m['quantity']) ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= e($m['user_name'] ?? '—') ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs max-w-[160px] truncate"><?= e($m['note'] ?? '') ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= date('d M, H:i', strtotime($m['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
