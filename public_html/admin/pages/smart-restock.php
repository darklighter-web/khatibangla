<?php
/**
 * Smart Restock - Automated low-stock detection, restock rules, and alerts
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Smart Restock';
$db = Database::getInstance();

// Ensure restock_rules table exists
try { $db->query("SELECT 1 FROM restock_rules LIMIT 1"); } catch (Exception $e) {
    $db->query("CREATE TABLE IF NOT EXISTS restock_rules (id int AUTO_INCREMENT PRIMARY KEY, product_id int NOT NULL, warehouse_id int DEFAULT NULL, min_stock int DEFAULT 5, reorder_quantity int DEFAULT 20, auto_alert tinyint(1) DEFAULT 1, supplier_name varchar(200), supplier_contact varchar(100), last_alerted_at datetime, created_at timestamp DEFAULT CURRENT_TIMESTAMP, KEY product_id(product_id))");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_rule') {
        $productId = (int)$_POST['product_id'];
        $warehouseId = (int)($_POST['warehouse_id'] ?? 0) ?: null;
        $minStock = (int)($_POST['min_stock'] ?? 5);
        $reorderQty = (int)($_POST['reorder_quantity'] ?? 20);
        $autoAlert = isset($_POST['auto_alert']) ? 1 : 0;
        $supplierName = sanitize($_POST['supplier_name'] ?? '');
        $supplierContact = sanitize($_POST['supplier_contact'] ?? '');

        $existing = $db->fetch("SELECT id FROM restock_rules WHERE product_id=? AND COALESCE(warehouse_id,0)=?", [$productId, $warehouseId ?? 0]);
        if ($existing) {
            $db->query("UPDATE restock_rules SET min_stock=?, reorder_quantity=?, auto_alert=?, supplier_name=?, supplier_contact=? WHERE id=?",
                [$minStock, $reorderQty, $autoAlert, $supplierName, $supplierContact, $existing['id']]);
        } else {
            $db->query("INSERT INTO restock_rules (product_id, warehouse_id, min_stock, reorder_quantity, auto_alert, supplier_name, supplier_contact) VALUES (?,?,?,?,?,?,?)",
                [$productId, $warehouseId, $minStock, $reorderQty, $autoAlert, $supplierName, $supplierContact]);
        }
        // Also update product threshold
        $db->query("UPDATE products SET low_stock_threshold=? WHERE id=?", [$minStock, $productId]);
        redirect(adminUrl('pages/smart-restock.php?msg=saved'));
    }

    if ($action === 'delete_rule') {
        $db->query("DELETE FROM restock_rules WHERE id=?", [(int)$_POST['rule_id']]);
        redirect(adminUrl('pages/smart-restock.php?msg=deleted'));
    }

    if ($action === 'quick_restock') {
        // Bulk create increase entries for selected products
        $productIds = $_POST['restock_product_ids'] ?? [];
        $quantities = $_POST['restock_quantities'] ?? [];
        $warehouseId = (int)($_POST['restock_warehouse_id'] ?? 0);
        $count = 0;
        foreach ($productIds as $i => $pid) {
            $qty = (int)($quantities[$i] ?? 0);
            if ($pid && $qty > 0 && $warehouseId) {
                $existing = $db->fetch("SELECT id, quantity FROM warehouse_stock WHERE warehouse_id=? AND product_id=?", [$warehouseId, (int)$pid]);
                if ($existing) {
                    $db->query("UPDATE warehouse_stock SET quantity=quantity+? WHERE id=?", [$qty, $existing['id']]);
                } else {
                    $db->query("INSERT INTO warehouse_stock (warehouse_id, product_id, quantity) VALUES (?,?,?)", [$warehouseId, (int)$pid, $qty]);
                }
                $db->query("INSERT INTO stock_movements (warehouse_id, product_id, movement_type, quantity, reference_type, note, created_by) VALUES (?,?,'in',?,'purchase','Smart Restock',?)",
                    [$warehouseId, (int)$pid, $qty, getAdminId()]);
                $totalStock = $db->fetch("SELECT COALESCE(SUM(quantity),0) as total FROM warehouse_stock WHERE product_id=?", [(int)$pid]);
                $db->query("UPDATE products SET stock_quantity=? WHERE id=?", [$totalStock['total'] ?? 0, (int)$pid]);
                $count++;
            }
        }
        redirect(adminUrl('pages/smart-restock.php?msg=restocked&count=' . $count));
    }
}

$msg = $_GET['msg'] ?? '';
$tab = $_GET['tab'] ?? 'alerts';

// Get products needing restock
$lowStockProducts = $db->fetchAll("SELECT p.id, p.name, p.sku, p.stock_quantity, p.low_stock_threshold, p.cost_price, p.featured_image,
    rr.min_stock as rule_min, rr.reorder_quantity, rr.supplier_name, rr.auto_alert
    FROM products p
    LEFT JOIN restock_rules rr ON p.id=rr.product_id
    WHERE p.is_active=1 AND p.stock_quantity <= COALESCE(rr.min_stock, p.low_stock_threshold, 5)
    ORDER BY p.stock_quantity ASC, p.name
    LIMIT 100");

// Get all rules
$rules = $db->fetchAll("SELECT rr.*, p.name as product_name, p.sku, p.stock_quantity, w.name as warehouse_name
    FROM restock_rules rr
    LEFT JOIN products p ON rr.product_id=p.id
    LEFT JOIN warehouses w ON rr.warehouse_id=w.id
    ORDER BY p.name");

$warehouses = $db->fetchAll("SELECT * FROM warehouses WHERE is_active=1 ORDER BY is_default DESC, name");
$products = $db->fetchAll("SELECT id, name, sku FROM products WHERE is_active=1 ORDER BY name LIMIT 500");

// Stats
$outOfStock = $db->fetch("SELECT COUNT(*) as cnt FROM products WHERE is_active=1 AND stock_quantity<=0")['cnt'] ?? 0;
$lowStock = $db->fetch("SELECT COUNT(*) as cnt FROM products WHERE is_active=1 AND stock_quantity > 0 AND stock_quantity <= low_stock_threshold")['cnt'] ?? 0;
$totalRules = count($rules);

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($msg === 'saved'): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">Restock rule saved!</div><?php endif; ?>
<?php if ($msg === 'restocked'): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm"><?= (int)($_GET['count'] ?? 0) ?> product(s) restocked successfully!</div><?php endif; ?>

<!-- Stats Cards -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4 flex items-center gap-3">
        <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center"><svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg></div>
        <div><p class="text-2xl font-bold text-red-600"><?= $outOfStock ?></p><p class="text-xs text-gray-500">Out of Stock</p></div>
    </div>
    <div class="bg-white rounded-xl border p-4 flex items-center gap-3">
        <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center"><svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
        <div><p class="text-2xl font-bold text-yellow-600"><?= $lowStock ?></p><p class="text-xs text-gray-500">Low Stock</p></div>
    </div>
    <div class="bg-white rounded-xl border p-4 flex items-center gap-3">
        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center"><svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
        <div><p class="text-2xl font-bold text-blue-600"><?= $totalRules ?></p><p class="text-xs text-gray-500">Restock Rules</p></div>
    </div>
</div>

<!-- Tabs -->
<div class="flex gap-2 mb-6">
    <a href="?tab=alerts" class="px-4 py-2 rounded-lg text-sm font-medium <?= $tab==='alerts'?'bg-blue-600 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">Restock Alerts (<?= count($lowStockProducts) ?>)</a>
    <a href="?tab=rules" class="px-4 py-2 rounded-lg text-sm font-medium <?= $tab==='rules'?'bg-blue-600 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">Restock Rules</a>
    <a href="?tab=add-rule" class="px-4 py-2 rounded-lg text-sm font-medium <?= $tab==='add-rule'?'bg-blue-600 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">Add Rule</a>
</div>

<?php if ($tab === 'alerts'): ?>
<!-- Restock Alerts with Quick Restock -->
<div class="bg-white rounded-xl border shadow-sm">
    <form method="POST">
        <input type="hidden" name="action" value="quick_restock">
        <div class="p-4 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Products Needing Restock</h3>
            <div class="flex items-center gap-3">
                <select name="restock_warehouse_id" class="border rounded-lg px-3 py-2 text-sm">
                    <?php foreach ($warehouses as $wh): ?>
                    <option value="<?= $wh['id'] ?>"><?= e($wh['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700">Quick Restock Selected</button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-3 w-8"><input type="checkbox" id="selectAll" class="rounded"></th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Product</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Current Stock</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Threshold</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Restock Qty</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Supplier</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-600">Est. Cost</th>
                </tr></thead>
                <tbody class="divide-y">
                    <?php foreach ($lowStockProducts as $lp): ?>
                    <?php $reorderQty = $lp['reorder_quantity'] ?? 20; ?>
                    <tr class="hover:bg-gray-50 <?= $lp['stock_quantity'] <= 0 ? 'bg-red-50' : '' ?>">
                        <td class="px-4 py-3"><input type="checkbox" name="restock_product_ids[]" value="<?= $lp['id'] ?>" class="rounded row-check"></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <?php if ($lp['featured_image']): ?><img src="<?= uploadUrl($lp['featured_image']) ?>" class="w-8 h-8 rounded object-cover"><?php endif; ?>
                                <div><p class="font-medium text-gray-800"><?= e($lp['name']) ?></p><p class="text-xs text-gray-400"><?= e($lp['sku'] ?? '') ?></p></div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="<?= $lp['stock_quantity'] <= 0 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700' ?> px-2 py-0.5 rounded-full text-xs font-bold"><?= $lp['stock_quantity'] ?></span>
                        </td>
                        <td class="px-4 py-3 text-center text-gray-500"><?= $lp['rule_min'] ?? $lp['low_stock_threshold'] ?></td>
                        <td class="px-4 py-3 text-center"><input type="number" name="restock_quantities[]" value="<?= $reorderQty ?>" min="1" class="w-20 border rounded px-2 py-1 text-sm text-center"></td>
                        <td class="px-4 py-3 text-gray-500 text-xs"><?= e($lp['supplier_name'] ?? '—') ?></td>
                        <td class="px-4 py-3 text-right text-gray-600"><?= $lp['cost_price'] > 0 ? '৳' . number_format($lp['cost_price'] * $reorderQty) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($lowStockProducts)): ?>
                    <tr><td colspan="7" class="px-4 py-8 text-center text-green-600 font-medium">All products are well-stocked!</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>
<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
});
</script>

<?php elseif ($tab === 'rules'): ?>
<!-- Existing Rules -->
<div class="bg-white rounded-xl border shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50"><tr>
            <th class="text-left px-4 py-3 font-medium text-gray-600">Product</th>
            <th class="text-left px-4 py-3 font-medium text-gray-600">Warehouse</th>
            <th class="text-center px-4 py-3 font-medium text-gray-600">Min Stock</th>
            <th class="text-center px-4 py-3 font-medium text-gray-600">Reorder Qty</th>
            <th class="text-center px-4 py-3 font-medium text-gray-600">Auto Alert</th>
            <th class="text-left px-4 py-3 font-medium text-gray-600">Supplier</th>
            <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
        </tr></thead>
        <tbody class="divide-y">
            <?php foreach ($rules as $rule): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium"><?= e($rule['product_name'] ?? 'Unknown') ?><br><span class="text-xs text-gray-400">Stock: <?= $rule['stock_quantity'] ?? 0 ?></span></td>
                <td class="px-4 py-3 text-gray-600"><?= e($rule['warehouse_name'] ?? 'All') ?></td>
                <td class="px-4 py-3 text-center"><?= $rule['min_stock'] ?></td>
                <td class="px-4 py-3 text-center"><?= $rule['reorder_quantity'] ?></td>
                <td class="px-4 py-3 text-center"><?= $rule['auto_alert'] ? '<span class="text-green-600">Yes</span>' : '<span class="text-gray-400">No</span>' ?></td>
                <td class="px-4 py-3 text-gray-500"><?= e($rule['supplier_name'] ?? '—') ?></td>
                <td class="px-4 py-3 text-right">
                    <form method="POST" class="inline" onsubmit="return confirm('Delete this rule?')"><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="rule_id" value="<?= $rule['id'] ?>"><button class="text-red-600 hover:text-red-800 text-xs">Delete</button></form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rules)): ?>
            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No restock rules configured</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php else: ?>
<!-- Add Rule Form -->
<div class="max-w-xl mx-auto bg-white rounded-xl border shadow-sm p-6">
    <h3 class="font-semibold text-gray-800 mb-4">Add Restock Rule</h3>
    <form method="POST" class="space-y-4">
        <input type="hidden" name="action" value="save_rule">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Product *</label>
            <select name="product_id" required class="w-full border rounded-lg px-3 py-2.5 text-sm">
                <option value="">Select...</option>
                <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Warehouse</label>
            <select name="warehouse_id" class="w-full border rounded-lg px-3 py-2.5 text-sm">
                <option value="">All Warehouses</option>
                <?php foreach ($warehouses as $wh): ?><option value="<?= $wh['id'] ?>"><?= e($wh['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Min Stock Level</label><input type="number" name="min_stock" value="5" min="0" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Reorder Quantity</label><input type="number" name="reorder_quantity" value="20" min="1" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Supplier Name</label><input type="text" name="supplier_name" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Supplier Contact</label><input type="text" name="supplier_contact" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
        </div>
        <label class="flex items-center gap-2"><input type="checkbox" name="auto_alert" checked class="rounded"> <span class="text-sm">Enable automatic low-stock alerts</span></label>
        <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">Save Restock Rule</button>
    </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
