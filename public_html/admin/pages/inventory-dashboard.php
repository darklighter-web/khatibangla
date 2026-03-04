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

<style>
.inv-dark { background:#111; color:#e5e5e5; }
.inv-card { background:#1a1a1a; border:1px solid #2a2a2a; border-radius:12px; padding:20px; }
.inv-card-red    { border-color:#3b1212; background:#1a0d0d; }
.inv-card-purple { border-color:#2a1a3b; background:#150d1f; }
.inv-stat-blue   { color:#3b82f6; }
.inv-stat-green  { color:#22c55e; }
.inv-stat-orange { color:#f97316; }
.inv-stat-red    { color:#ef4444; }
.inv-stat-purple { color:#a855f7; }
.inv-stat-yellow { color:#eab308; }
.inv-label { font-size:13px; color:#9ca3af; margin-bottom:4px; }
.inv-num { font-size:2.4rem; font-weight:700; line-height:1; margin:10px 0 4px; }
.inv-sub { font-size:12px; color:#6b7280; }
.inv-section-title { font-size:18px; font-weight:700; color:#f3f4f6; margin-bottom:16px; }
.inv-table { width:100%; border-collapse:collapse; }
.inv-table th { font-size:12px; color:#6b7280; font-weight:500; text-align:left; padding:10px 12px; border-bottom:1px solid #2a2a2a; }
.inv-table td { padding:14px 12px; border-bottom:1px solid #1e1e1e; font-size:14px; color:#e5e5e5; }
.inv-table tr:last-child td { border-bottom:none; }
.inv-table tr:hover td { background:#1e1e1e; }
.inv-badge-neg { background:#7f1d1d; color:#fca5a5; font-size:12px; font-weight:700; padding:3px 10px; border-radius:999px; display:inline-block; }
.inv-badge-low { background:#78350f; color:#fcd34d; font-size:12px; font-weight:700; padding:3px 10px; border-radius:999px; display:inline-block; }
.inv-badge-out { background:#1e3a5f; color:#93c5fd; font-size:12px; font-weight:700; padding:3px 10px; border-radius:999px; display:inline-block; }
.inv-btn { font-size:12px; color:#9ca3af; border:1px solid #2a2a2a; padding:4px 12px; border-radius:6px; background:transparent; cursor:pointer; transition:all .15s; }
.inv-btn:hover { color:#f3f4f6; border-color:#444; }
.inv-btn-restock { font-size:12px; color:#22c55e; border:1px solid #14532d; padding:4px 12px; border-radius:6px; background:#0a1f12; cursor:pointer; text-decoration:none; transition:all .15s; }
.inv-btn-restock:hover { background:#14532d; }
.inv-range-select { background:#1a1a1a; border:1px solid #2a2a2a; color:#e5e5e5; padding:6px 12px; border-radius:8px; font-size:13px; cursor:pointer; }
.inv-alert-box { border:1px solid #3b1212; background:#120808; border-radius:12px; margin-bottom:20px; overflow:hidden; }
.inv-alert-box-low { border-color:#3b2a08; background:#120f04; }
.inv-alert-box-out { border-color:#0d2340; background:#060e1a; }
.inv-alert-header { padding:16px 20px 12px; border-bottom:1px solid #2a2a2a; }
.inv-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; }
.inv-movement-type { font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:.5px; }
.mt-in         { background:#0a2010; color:#22c55e; }
.mt-out        { background:#1a0808; color:#ef4444; }
.mt-return     { background:#1a120a; color:#f59e0b; }
.mt-adjustment { background:#0d0d2a; color:#818cf8; }
.mt-transfer   { background:#0d1a2a; color:#60a5fa; }
</style>

<div class="inv-dark -m-6 p-6 min-h-screen">

<!-- ── Header ─────────────────────────────────────────────────────────────── -->
<div class="flex items-start justify-between mb-8">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-gray-800 rounded-xl flex items-center justify-center">
            <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
        </div>
        <div>
            <h1 class="text-xl font-bold text-white">Inventory Dashboard</h1>
            <p class="text-sm text-gray-500">Monitor stock health and movements across your inventory</p>
        </div>
    </div>
    <a href="<?= adminUrl('pages/stock-increase-new.php') ?>" class="flex items-center gap-2 border border-gray-700 text-gray-300 px-4 py-2 rounded-lg text-sm hover:bg-gray-800 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        Stock Reconciliation
    </a>
</div>

<!-- ── Stock Health Overview ──────────────────────────────────────────────── -->
<p class="inv-section-title">Stock Health Overview</p>
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
    <div class="inv-card">
        <div class="flex justify-between items-start">
            <span class="inv-label">Total Products</span>
            <svg class="w-5 h-5 text-blue-500 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
        </div>
        <p class="inv-num inv-stat-blue"><?= number_format($totalProducts) ?></p>
    </div>
    <div class="inv-card">
        <div class="flex justify-between items-start">
            <span class="inv-label">Healthy Stock</span>
            <svg class="w-5 h-5 text-green-500 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="inv-num inv-stat-green"><?= number_format($healthyStock) ?></p>
    </div>
    <div class="inv-card inv-card-red" style="border-color:#3b2008;background:#150d04;">
        <div class="flex justify-between items-start">
            <span class="inv-label">Low Stock</span>
            <svg class="w-5 h-5 text-orange-500 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </div>
        <p class="inv-num inv-stat-orange"><?= number_format($lowStock) ?></p>
    </div>
    <div class="inv-card" style="border-color:#3b1212;background:#1a0d0d;">
        <div class="flex justify-between items-start">
            <span class="inv-label">Out of Stock</span>
            <svg class="w-5 h-5 text-red-500 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="inv-num inv-stat-red"><?= number_format($outOfStock) ?></p>
    </div>
    <div class="inv-card" style="border-color:#2a1a3b;background:#110a1a;">
        <div class="flex justify-between items-start">
            <span class="inv-label">Negative Stock</span>
            <svg class="w-5 h-5 text-purple-500 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/></svg>
        </div>
        <p class="inv-num inv-stat-purple"><?= number_format($negativeStock) ?></p>
    </div>
</div>

<!-- ── Stock Movement Summary ─────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-4">
    <p class="inv-section-title mb-0">Stock Movement Summary</p>
    <form method="GET" class="flex items-center gap-2">
        <select name="range" onchange="this.form.submit()" class="inv-range-select">
            <?php foreach ([7=>'Last 7 days', 30=>'Last 30 days', 90=>'Last 90 days'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= $range==$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="inv-card">
        <div class="flex justify-between items-start">
            <span class="inv-label">Units Sold</span>
            <svg class="w-5 h-5 text-red-400 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6"/></svg>
        </div>
        <p class="inv-num inv-stat-red">-<?= number_format($unitsSold) ?></p>
        <p class="inv-sub">from order deliveries</p>
    </div>
    <div class="inv-card">
        <div class="flex justify-between items-start">
            <span class="inv-label">Units Purchased</span>
            <svg class="w-5 h-5 text-green-400 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"/></svg>
        </div>
        <p class="inv-num inv-stat-green">+<?= number_format($unitsPurchased) ?></p>
        <p class="inv-sub">from purchase receipts</p>
    </div>
    <div class="inv-card">
        <div class="flex justify-between items-start">
            <span class="inv-label">Returns</span>
            <svg class="w-5 h-5 text-yellow-400 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        </div>
        <p class="inv-num inv-stat-yellow">+<?= number_format($returns) ?></p>
        <p class="inv-sub">items returned</p>
    </div>
    <div class="inv-card">
        <div class="flex justify-between items-start">
            <span class="inv-label">Net Change</span>
            <svg class="w-5 h-5 text-green-400 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
        </div>
        <p class="inv-num <?= $netChange >= 0 ? 'inv-stat-green' : 'inv-stat-red' ?>"><?= ($netChange >= 0 ? '+' : '') . number_format($netChange) ?></p>
        <p class="inv-sub"><?= number_format($totalMovements) ?> total movements</p>
    </div>
</div>

<!-- ── Attention Required ─────────────────────────────────────────────────── -->
<p class="inv-section-title">Attention Required</p>

<?php if (!empty($negativeProducts)): ?>
<div class="inv-alert-box mb-5">
    <div class="inv-alert-header">
        <div class="flex items-center gap-2 text-red-400 font-semibold text-sm mb-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            Negative Stock
        </div>
        <p class="text-gray-500 text-xs">Products with stock below zero require immediate attention.</p>
    </div>
    <table class="inv-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Stock</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($negativeProducts as $p): ?>
            <tr>
                <td><?= e($p['name']) ?></td>
                <td class="text-gray-500"><?= e($p['sku'] ?: '—') ?></td>
                <td><span class="inv-badge-neg"><?= $p['stock_quantity'] ?></span></td>
                <td><a href="<?= adminUrl('pages/stock-increase-new.php?product_id=' . $p['id']) ?>" class="inv-btn">View History</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($lowStockProducts)): ?>
<div class="inv-alert-box inv-alert-box-low mb-5">
    <div class="inv-alert-header">
        <div class="flex items-center gap-2 text-yellow-400 font-semibold text-sm mb-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            Low Stock
        </div>
        <p class="text-gray-500 text-xs">Products below their reorder threshold.</p>
    </div>
    <table class="inv-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Stock</th>
                <th>Threshold</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lowStockProducts as $p): ?>
            <tr>
                <td><?= e($p['name']) ?></td>
                <td class="text-gray-500"><?= e($p['sku'] ?: '—') ?></td>
                <td><span class="inv-badge-low"><?= $p['stock_quantity'] ?></span></td>
                <td class="text-gray-500"><?= $p['low_stock_threshold'] ?></td>
                <td><a href="<?= adminUrl('pages/stock-increase-new.php?product_id=' . $p['id']) ?>" class="inv-btn-restock">Restock</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($outOfStockProducts)): ?>
<div class="inv-alert-box inv-alert-box-out mb-5">
    <div class="inv-alert-header">
        <div class="flex items-center gap-2 text-blue-400 font-semibold text-sm mb-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Out of Stock
        </div>
        <p class="text-gray-500 text-xs">Products with zero inventory. Restock to resume selling.</p>
    </div>
    <table class="inv-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Stock</th>
                <th>Total Sold</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($outOfStockProducts as $p): ?>
            <tr>
                <td><?= e($p['name']) ?></td>
                <td class="text-gray-500"><?= e($p['sku'] ?: '—') ?></td>
                <td><span class="inv-badge-out">0</span></td>
                <td class="text-gray-500"><?= $p['sales_count'] ?></td>
                <td><a href="<?= adminUrl('pages/stock-increase-new.php?product_id=' . $p['id']) ?>" class="inv-btn-restock">Restock</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (empty($negativeProducts) && empty($lowStockProducts) && empty($outOfStockProducts)): ?>
<div class="inv-card flex items-center gap-3 text-green-400">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span class="text-sm font-medium">All products are healthy — no attention required.</span>
</div>
<?php endif; ?>

<!-- ── Recent Movements ───────────────────────────────────────────────────── -->
<?php if (!empty($recentMovements)): ?>
<p class="inv-section-title mt-8">Recent Movements</p>
<div class="inv-card">
    <table class="inv-table">
        <thead>
            <tr>
                <th>Type</th>
                <th>Product</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>By</th>
                <th>Note</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentMovements as $m):
                $typeClass = 'mt-' . $m['movement_type'];
                $typeLabel = ['in'=>'IN','out'=>'OUT','return'=>'RETURN','adjustment'=>'ADJUST','transfer'=>'TRANSFER'][$m['movement_type']] ?? strtoupper($m['movement_type']);
                $isIn = in_array($m['movement_type'], ['in','return']);
            ?>
            <tr>
                <td><span class="inv-movement-type <?= $typeClass ?>"><?= $typeLabel ?></span></td>
                <td class="font-medium"><?= e($m['product_name'] ?? '—') ?></td>
                <td class="text-gray-500 text-xs"><?= e($m['sku'] ?? '—') ?></td>
                <td class="<?= $isIn ? 'text-green-400' : 'text-red-400' ?> font-bold"><?= ($isIn?'+':'-') . abs($m['quantity']) ?></td>
                <td class="text-gray-500 text-xs"><?= e($m['user_name'] ?? '—') ?></td>
                <td class="text-gray-500 text-xs max-w-[160px] truncate"><?= e($m['note'] ?? '') ?></td>
                <td class="text-gray-500 text-xs"><?= date('d M, H:i', strtotime($m['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</div><!-- /inv-dark -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
