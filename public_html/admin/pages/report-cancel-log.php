<?php
/**
 * Cancel & Delete Log Report
 * Tracks all cancelled orders, deleted (soft-deactivated) orders,
 * and incomplete/abandoned orders in one unified view.
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Cancel & Delete Log';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// Date filters
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$filterType = $_GET['type'] ?? '';

// ── Summary Stats ──
$cancelledCount = intval($db->fetch("SELECT COUNT(*) as c FROM orders WHERE order_status = 'cancelled' AND DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo])['c'] ?? 0);
$cancelledValue = floatval($db->fetch("SELECT COALESCE(SUM(total),0) as v FROM orders WHERE order_status = 'cancelled' AND DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo])['v'] ?? 0);

$returnedCount = intval($db->fetch("SELECT COUNT(*) as c FROM orders WHERE order_status = 'returned' AND DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo])['c'] ?? 0);
$returnedValue = floatval($db->fetch("SELECT COALESCE(SUM(total),0) as v FROM orders WHERE order_status = 'returned' AND DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo])['v'] ?? 0);

// Incomplete orders count
$_incRecCol = 'is_recovered';
try { $db->fetch("SELECT is_recovered FROM incomplete_orders LIMIT 1"); } catch (\Throwable $e) { $_incRecCol = 'recovered'; }
$incompleteCount = intval($db->fetch("SELECT COUNT(*) as c FROM incomplete_orders WHERE DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo])['c'] ?? 0);
$incompleteRecovered = intval($db->fetch("SELECT COUNT(*) as c FROM incomplete_orders WHERE {$_incRecCol} = 1 AND DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo])['c'] ?? 0);
$incompleteAbandoned = $incompleteCount - $incompleteRecovered;

// Estimated incomplete value
$incompleteValue = 0;
try {
    $incRows = $db->fetchAll("SELECT cart_data FROM incomplete_orders WHERE DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
    foreach ($incRows as $ir) {
        $cart = json_decode($ir['cart_data'] ?? '[]', true) ?: [];
        foreach ($cart as $ci) {
            $incompleteValue += floatval($ci['price'] ?? $ci['sale_price'] ?? 0) * intval($ci['qty'] ?? $ci['quantity'] ?? 1);
        }
    }
} catch (\Throwable $e) {}

$totalLostValue = $cancelledValue + $returnedValue;

// ── Cancelled Orders with status history (who cancelled + when) ──
$cancelWhere = "1=1";
$cancelParams = [$dateFrom, $dateTo];
if ($filterType === 'cancelled') {
    $cancelWhere .= " AND o.order_status = 'cancelled'";
} elseif ($filterType === 'returned') {
    $cancelWhere .= " AND o.order_status = 'returned'";
} else {
    $cancelWhere .= " AND o.order_status IN ('cancelled','returned')";
}

$cancelledOrders = $db->fetchAll("
    SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.total, o.order_status,
           o.channel, o.created_at, o.updated_at, o.courier_name, o.notes,
           osh.changed_by, osh.note as cancel_note, osh.created_at as cancelled_at,
           au.full_name as cancelled_by_name
    FROM orders o
    LEFT JOIN (
        SELECT order_id, changed_by, note, created_at,
               ROW_NUMBER() OVER (PARTITION BY order_id ORDER BY created_at DESC) as rn
        FROM order_status_history
        WHERE status IN ('cancelled','returned')
    ) osh ON osh.order_id = o.id AND osh.rn = 1
    LEFT JOIN admin_users au ON au.id = osh.changed_by
    WHERE {$cancelWhere} AND DATE(o.created_at) BETWEEN ? AND ?
    ORDER BY o.updated_at DESC
    LIMIT 200
", $cancelParams);

// ── Incomplete Orders ──
$incompleteOrders = [];
try {
    $incompleteOrders = $db->fetchAll("
        SELECT io.*, 
               CASE WHEN io.{$_incRecCol} = 1 THEN 'recovered' ELSE 'abandoned' END as inc_status
        FROM incomplete_orders io
        WHERE DATE(io.created_at) BETWEEN ? AND ?
        ORDER BY io.created_at DESC
        LIMIT 200
    ", [$dateFrom, $dateTo]);
} catch (\Throwable $e) {}

// ── Daily cancel trend ──
$dailyTrend = $db->fetchAll("
    SELECT DATE(updated_at) as dt, 
           SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled,
           SUM(CASE WHEN order_status='returned' THEN 1 ELSE 0 END) as returned,
           COALESCE(SUM(CASE WHEN order_status IN ('cancelled','returned') THEN total ELSE 0 END), 0) as lost_value
    FROM orders 
    WHERE order_status IN ('cancelled','returned') AND DATE(updated_at) BETWEEN ? AND ?
    GROUP BY DATE(updated_at)
    ORDER BY dt
", [$dateFrom, $dateTo]);

// ── Top cancel reasons (from status history notes) ──
$cancelReasons = $db->fetchAll("
    SELECT osh.note, COUNT(*) as cnt
    FROM order_status_history osh
    JOIN orders o ON o.id = osh.order_id
    WHERE osh.status IN ('cancelled','returned') 
      AND osh.note IS NOT NULL AND osh.note != ''
      AND DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY osh.note
    ORDER BY cnt DESC
    LIMIT 10
", [$dateFrom, $dateTo]);

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-500">Cancelled</p>
            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </div>
        <p class="text-2xl font-bold text-red-600"><?= number_format($cancelledCount) ?></p>
        <p class="text-xs text-gray-400 mt-1">৳<?= number_format($cancelledValue) ?> lost</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-500">Returned</p>
            <svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
        </div>
        <p class="text-2xl font-bold text-orange-600"><?= number_format($returnedCount) ?></p>
        <p class="text-xs text-gray-400 mt-1">৳<?= number_format($returnedValue) ?> lost</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-500">Total Lost Value</p>
            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/></svg>
        </div>
        <p class="text-2xl font-bold text-gray-800">৳<?= number_format($totalLostValue) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-500">Incomplete</p>
            <svg class="w-4 h-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-2xl font-bold text-yellow-600"><?= number_format($incompleteCount) ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= $incompleteRecovered ?> recovered, <?= $incompleteAbandoned ?> abandoned</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-500">Incomplete Value</p>
            <svg class="w-4 h-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
        </div>
        <p class="text-2xl font-bold text-yellow-600">৳<?= number_format($incompleteValue) ?></p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl border p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <input type="date" name="from" value="<?= e($dateFrom) ?>" class="border rounded-lg px-3 py-2 text-sm">
        <input type="date" name="to" value="<?= e($dateTo) ?>" class="border rounded-lg px-3 py-2 text-sm">
        <select name="type" class="border rounded-lg px-3 py-2 text-sm">
            <option value="">All (Cancel + Return)</option>
            <option value="cancelled" <?= $filterType==='cancelled'?'selected':'' ?>>Cancelled Only</option>
            <option value="returned" <?= $filterType==='returned'?'selected':'' ?>>Returned Only</option>
        </select>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700">Filter</button>
    </form>
</div>

<!-- Tabs -->
<div class="flex gap-1 mb-6 border-b">
    <button onclick="showTab('orders')" id="tab-orders" class="px-4 py-2.5 text-sm font-medium border-b-2 border-blue-600 text-blue-600">Cancelled & Returned Orders</button>
    <button onclick="showTab('incomplete')" id="tab-incomplete" class="px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">Incomplete Orders</button>
    <button onclick="showTab('trend')" id="tab-trend" class="px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">Daily Trend</button>
</div>

<!-- Tab: Cancelled & Returned Orders -->
<div id="panel-orders" class="bg-white rounded-xl border shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Order</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Customer</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Total</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Status</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Cancelled By</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Reason / Note</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Source</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Created</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Cancelled</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($cancelledOrders as $o): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3"><a href="<?= adminUrl('pages/order-view.php?id='.$o['id']) ?>" class="text-blue-600 hover:underline font-medium">#<?= e($o['order_number']) ?></a></td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800"><?= e($o['customer_name']) ?></div>
                        <div class="text-xs text-gray-500"><?= e($o['customer_phone']) ?></div>
                    </td>
                    <td class="px-4 py-3 text-right font-bold">৳<?= number_format($o['total']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($o['order_status'] === 'cancelled'): ?>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-red-100 text-red-700">Cancelled</span>
                        <?php else: ?>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-orange-100 text-orange-700">Returned</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600"><?= e($o['cancelled_by_name'] ?? 'System') ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 max-w-[200px] truncate"><?= e($o['cancel_note'] ?: ($o['notes'] ?: '—')) ?></td>
                    <td class="px-4 py-3"><span class="text-xs font-semibold text-gray-500"><?= strtoupper($o['channel'] ?? '—') ?></span></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= date('d M Y, h:i a', strtotime($o['created_at'])) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= $o['cancelled_at'] ? date('d M Y, h:i a', strtotime($o['cancelled_at'])) : date('d M Y', strtotime($o['updated_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($cancelledOrders)): ?>
                <tr><td colspan="9" class="px-4 py-12 text-center text-gray-400">No cancelled or returned orders in this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Tab: Incomplete Orders -->
<div id="panel-incomplete" class="bg-white rounded-xl border shadow-sm overflow-hidden hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">ID</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Customer</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Products</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Value</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Step Reached</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Status</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Created</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($incompleteOrders as $io): 
                    $ioCart = json_decode($io['cart_data'] ?? '[]', true) ?: [];
                    $ioTotal = 0;
                    foreach ($ioCart as $ci) { $ioTotal += floatval($ci['price'] ?? $ci['sale_price'] ?? 0) * intval($ci['qty'] ?? $ci['quantity'] ?? 1); }
                    $ioProducts = array_map(fn($c) => $c['name'] ?? $c['product_name'] ?? 'Product', array_slice($ioCart, 0, 3));
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-700">#INC-<?= $io['id'] ?></td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800"><?= e($io['customer_name'] ?? 'Unknown') ?></div>
                        <div class="text-xs text-gray-500"><?= e($io['customer_phone'] ?? '') ?></div>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600 max-w-[200px]">
                        <?= e(implode(', ', $ioProducts)) ?>
                        <?php if (count($ioCart) > 3): ?><span class="text-gray-400">+<?= count($ioCart)-3 ?> more</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-bold">৳<?= number_format($ioTotal) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-indigo-100 text-indigo-700"><?= strtoupper(str_replace('_', ' ', $io['step_reached'] ?? 'cart')) ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($io['inc_status'] === 'recovered'): ?>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-green-100 text-green-700">Recovered</span>
                        <?php else: ?>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-gray-100 text-gray-600">Abandoned</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= date('d M Y, h:i a', strtotime($io['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($incompleteOrders)): ?>
                <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">No incomplete orders in this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Tab: Daily Trend -->
<div id="panel-trend" class="hidden">
    <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Cancelled</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Returned</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-600">Lost Value</th>
                </tr></thead>
                <tbody class="divide-y">
                    <?php foreach ($dailyTrend as $d): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium"><?= date('d M Y (D)', strtotime($d['dt'])) ?></td>
                        <td class="px-4 py-3 text-center"><span class="text-red-600 font-bold"><?= $d['cancelled'] ?></span></td>
                        <td class="px-4 py-3 text-center"><span class="text-orange-600 font-bold"><?= $d['returned'] ?></span></td>
                        <td class="px-4 py-3 text-right font-bold">৳<?= number_format($d['lost_value']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($dailyTrend)): ?>
                    <tr><td colspan="4" class="px-4 py-12 text-center text-gray-400">No data in this period</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php if (!empty($cancelReasons)): ?>
    <div class="bg-white rounded-xl border shadow-sm overflow-hidden mt-4">
        <div class="px-4 py-3 border-b">
            <h3 class="text-sm font-bold text-gray-800">Top Cancel/Return Reasons</h3>
        </div>
        <div class="p-4 space-y-2">
            <?php foreach ($cancelReasons as $cr): ?>
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-700"><?= e($cr['note']) ?></span>
                <span class="text-xs font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded"><?= $cr['cnt'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function showTab(tab) {
    ['orders','incomplete','trend'].forEach(t => {
        document.getElementById('panel-'+t).classList.toggle('hidden', t !== tab);
        const btn = document.getElementById('tab-'+t);
        btn.classList.toggle('border-blue-600', t === tab);
        btn.classList.toggle('text-blue-600', t === tab);
        btn.classList.toggle('border-transparent', t !== tab);
        btn.classList.toggle('text-gray-500', t !== tab);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
