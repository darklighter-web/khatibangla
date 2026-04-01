<?php
/**
 * New Increase Stock - Form to increase stock levels
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'New Increase Stock';
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'increase_stock') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $source = sanitize($_POST['source'] ?? '');
        $costPerUnit = (float)($_POST['cost_per_unit'] ?? 0);
        $note = sanitize($_POST['note'] ?? '');

        if ($productId && $quantity > 0) {
            $existing = $db->fetch("SELECT id, quantity FROM warehouse_stock WHERE warehouse_id=? AND product_id=?", [$warehouseId, $productId]);
            if ($existing) {
                $db->query("UPDATE warehouse_stock SET quantity=quantity+? WHERE id=?", [$quantity, $existing['id']]);
            } else {
                $db->query("INSERT INTO warehouse_stock (warehouse_id, product_id, quantity) VALUES (?,?,?)", [$warehouseId, $productId, $quantity]);
            }

            $noteText = $note;
            if ($costPerUnit > 0) $noteText .= ($noteText ? ' | ' : '') . "Cost: ৳" . number_format($costPerUnit, 2) . "/unit";

            $db->query("INSERT INTO stock_movements (warehouse_id, product_id, movement_type, quantity, reference_type, note, created_by, created_at) VALUES (?,?,'in',?,?,?,?,NOW())",
                [$warehouseId, $productId, $quantity, $source, $noteText, getAdminId()]);

            $totalStock = $db->fetch("SELECT COALESCE(SUM(quantity),0) as total FROM warehouse_stock WHERE product_id=?", [$productId]);
            $db->query("UPDATE products SET stock_quantity=? WHERE id=?", [$totalStock['total'] ?? 0, $productId]);

            logActivity(getAdminId(), 'stock_increased', 'product', $productId);
            redirect(adminUrl('pages/stock-increase-new.php?msg=success'));
        } else {
            redirect(adminUrl('pages/stock-increase-new.php?msg=error'));
        }
    }
}

$msg = $_GET['msg'] ?? '';
$warehouses = $db->fetchAll("SELECT * FROM warehouses WHERE is_active=1 ORDER BY is_default DESC, name");
$products = $db->fetchAll("SELECT id, name, sku, stock_quantity, cost_price, featured_image FROM products WHERE is_active=1 ORDER BY name LIMIT 500");

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($msg === 'success'): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm flex items-center gap-2"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg> Stock increased successfully!</div><?php endif; ?>
<?php if ($msg === 'error'): ?><div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">Please select a product and enter a valid quantity.</div><?php endif; ?>

<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-xl border shadow-sm p-6">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            </div>
            <div>
                <h2 class="text-lg font-semibold text-gray-800">Increase Stock</h2>
                <p class="text-sm text-gray-500">Add new stock to inventory from purchases or returns</p>
            </div>
        </div>

        <form method="POST" class="space-y-5">
            <input type="hidden" name="action" value="increase_stock">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Warehouse *</label>
                <select name="warehouse_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <?php foreach ($warehouses as $wh): ?>
                    <option value="<?= $wh['id'] ?>"><?= e($wh['name']) ?> (<?= e($wh['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Product *</label>
                <select name="product_id" required id="productSelect" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <option value="">Select a product...</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-stock="<?= $p['stock_quantity'] ?>" data-cost="<?= $p['cost_price'] ?>"><?= e($p['name']) ?> (SKU: <?= e($p['sku'] ?? 'N/A') ?>) — Stock: <?= $p['stock_quantity'] ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1">Current stock: <span id="currentStock" class="font-semibold">—</span></p>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Quantity *</label>
                    <input type="number" name="quantity" min="1" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500" placeholder="0">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cost/Unit (৳)</label>
                    <input type="number" name="cost_per_unit" step="0.01" min="0" id="costInput" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500" placeholder="0.00">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Source *</label>
                    <select name="source" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        <option value="">Select...</option>
                        <option value="purchase">New Purchase</option>
                        <option value="return">Customer Return</option>
                        <option value="production">Production</option>
                        <option value="transfer_in">Transfer In</option>
                        <option value="audit_adjustment">Audit Adjustment</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="note" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500" placeholder="Supplier name, invoice reference, etc..."></textarea>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 bg-green-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-green-700 transition-colors">
                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    Increase Stock
                </button>
                <a href="<?= adminUrl('pages/stock-increase-list.php') ?>" class="px-4 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-200 transition-colors">View History</a>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('productSelect').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('currentStock').textContent = opt.dataset.stock || '—';
    const cost = opt.dataset.cost || '';
    if (cost && parseFloat(cost) > 0) document.getElementById('costInput').value = cost;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
