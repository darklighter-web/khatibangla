<?php
/**
 * Admin - Inventory Management (Multi-Warehouse)
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Inventory Management';
$db = Database::getInstance();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_warehouse') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            sanitize($_POST['name']),
            sanitize($_POST['code']),
            sanitize($_POST['address'] ?? ''),
            sanitize($_POST['city'] ?? ''),
            sanitize($_POST['phone'] ?? ''),
            sanitize($_POST['manager_name'] ?? ''),
            isset($_POST['is_active']) ? 1 : 0
        ];
        if ($id > 0) {
            $data[] = $id;
            $db->query("UPDATE warehouses SET name=?, code=?, address=?, city=?, phone=?, manager_name=?, is_active=? WHERE id=?", $data);
        } else {
            $db->query("INSERT INTO warehouses (name, code, address, city, phone, manager_name, is_active) VALUES (?,?,?,?,?,?,?)", $data);
        }
        logActivity(getAdminId(), 'warehouse_saved', 'warehouse', $id);
        redirect(adminUrl('pages/inventory.php?tab=warehouses&msg=saved'));
    }
    
    if ($action === 'stock_adjustment') {
        $warehouseId = (int)$_POST['warehouse_id'];
        $productId = (int)$_POST['product_id'];
        $quantity = (int)$_POST['quantity'];
        $type = $_POST['movement_type'];
        $note = sanitize($_POST['note'] ?? '');
        
        // Update or insert warehouse stock
        $existing = $db->fetch("SELECT id, quantity FROM warehouse_stock WHERE warehouse_id=? AND product_id=?", [$warehouseId, $productId]);
        if ($existing) {
            $newQty = ($type === 'in' || $type === 'return') ? $existing['quantity'] + $quantity : $existing['quantity'] - $quantity;
            $db->query("UPDATE warehouse_stock SET quantity=? WHERE id=?", [max(0, $newQty), $existing['id']]);
        } else {
            $db->query("INSERT INTO warehouse_stock (warehouse_id, product_id, quantity) VALUES (?,?,?)", [$warehouseId, $productId, $quantity]);
        }
        
        // Record movement
        $db->query("INSERT INTO stock_movements (warehouse_id, product_id, movement_type, quantity, note, created_by) VALUES (?,?,?,?,?,?)", 
            [$warehouseId, $productId, $type, $quantity, $note, getAdminId()]);
        
        // Update main product stock
        $totalStock = $db->fetch("SELECT SUM(quantity) as total FROM warehouse_stock WHERE product_id=?", [$productId]);
        $db->query("UPDATE products SET stock_quantity=? WHERE id=?", [$totalStock['total'] ?? 0, $productId]);
        
        logActivity(getAdminId(), 'stock_adjusted', 'product', $productId);
        redirect(adminUrl('pages/inventory.php?msg=adjusted'));
    }
    
    if ($action === 'delete_warehouse') {
        $id = (int)$_POST['id'];
        $isDefault = $db->fetch("SELECT is_default FROM warehouses WHERE id=?", [$id]);
        if ($isDefault && !$isDefault['is_default']) {
            $db->query("DELETE FROM warehouses WHERE id=? AND is_default=0", [$id]);
        }
        redirect(adminUrl('pages/inventory.php?tab=warehouses&msg=deleted'));
    }
}

$tab = $_GET['tab'] ?? 'stock';
$msg = $_GET['msg'] ?? '';

// Get warehouses
$warehouses = $db->fetchAll("SELECT w.*, (SELECT COUNT(*) FROM warehouse_stock WHERE warehouse_id=w.id AND quantity>0) as product_count FROM warehouses w ORDER BY is_default DESC, name");

// Get stock data
$search = $_GET['search'] ?? '';
$warehouseFilter = (int)($_GET['warehouse'] ?? 0);
$stockFilter = $_GET['stock'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1));

$where = "WHERE 1=1";
$params = [];
if ($search) { $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($warehouseFilter) { $where .= " AND ws.warehouse_id = ?"; $params[] = $warehouseFilter; }
if ($stockFilter === 'low') { $where .= " AND p.stock_quantity <= p.low_stock_threshold AND p.stock_quantity > 0"; }
elseif ($stockFilter === 'out') { $where .= " AND p.stock_quantity <= 0"; }
elseif ($stockFilter === 'in') { $where .= " AND p.stock_quantity > p.low_stock_threshold"; }

if ($warehouseFilter) {
    $total = $db->fetch("SELECT COUNT(DISTINCT p.id) as cnt FROM products p LEFT JOIN warehouse_stock ws ON p.id=ws.product_id $where", $params)['cnt'];
    $stockItems = $db->fetchAll("SELECT p.id, p.name, p.sku, p.stock_quantity, p.low_stock_threshold, p.featured_image, 
        COALESCE(ws.quantity,0) as wh_qty, COALESCE(ws.reserved_quantity,0) as wh_reserved, COALESCE(ws.reorder_level,5) as reorder_level
        FROM products p LEFT JOIN warehouse_stock ws ON p.id=ws.product_id $where ORDER BY p.name LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET " . (($page-1)*ADMIN_ITEMS_PER_PAGE), $params);
} else {
    $total = $db->fetch("SELECT COUNT(*) as cnt FROM products p $where", $params)['cnt'];
    $stockItems = $db->fetchAll("SELECT p.id, p.name, p.sku, p.stock_quantity, p.low_stock_threshold, p.featured_image
        FROM products p $where ORDER BY p.stock_quantity ASC LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET " . (($page-1)*ADMIN_ITEMS_PER_PAGE), $params);
}

$pagination = paginate($total, $page, ADMIN_ITEMS_PER_PAGE, adminUrl('pages/inventory.php?') . http_build_query(array_filter(['search'=>$search,'warehouse'=>$warehouseFilter,'stock'=>$stockFilter,'tab'=>$tab])));

// Recent movements
$movements = $db->fetchAll("SELECT sm.*, p.name as product_name, w.name as warehouse_name, au.full_name as created_by_name 
    FROM stock_movements sm 
    JOIN products p ON sm.product_id=p.id 
    JOIN warehouses w ON sm.warehouse_id=w.id 
    LEFT JOIN admin_users au ON sm.created_by=au.id 
    ORDER BY sm.created_at DESC LIMIT 20");

// Stock summary
$stockSummary = $db->fetch("SELECT 
    COUNT(*) as total_products,
    SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock,
    SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= low_stock_threshold THEN 1 ELSE 0 END) as low_stock,
    SUM(stock_quantity) as total_units
    FROM products WHERE is_active=1");

// All products for adjustment dropdown
$allProducts = $db->fetchAll("SELECT id, name, sku FROM products ORDER BY name");

include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg === 'saved'): ?><div class="mb-4 p-3 bg-green-50 text-green-700 rounded-xl text-sm">Warehouse saved!</div><?php endif; ?>
<?php if ($msg === 'adjusted'): ?><div class="mb-4 p-3 bg-green-50 text-green-700 rounded-xl text-sm">Stock adjusted!</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="mb-4 p-3 bg-green-50 text-green-700 rounded-xl text-sm">Warehouse deleted!</div><?php endif; ?>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500 mb-1">Total Products</p>
        <p class="text-2xl font-bold text-gray-800"><?= number_format($stockSummary['total_products']) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500 mb-1">Total Units</p>
        <p class="text-2xl font-bold text-blue-600"><?= number_format($stockSummary['total_units']) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500 mb-1">Low Stock</p>
        <p class="text-2xl font-bold text-yellow-600"><?= number_format($stockSummary['low_stock']) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs text-gray-500 mb-1">Out of Stock</p>
        <p class="text-2xl font-bold text-red-600"><?= number_format($stockSummary['out_of_stock']) ?></p>
    </div>
</div>

<!-- Tabs -->
<div class="flex gap-1 mb-6 border-b">
    <a href="?tab=stock" class="px-4 py-2.5 text-sm font-medium border-b-2 <?= $tab==='stock' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">Stock Levels</a>
    <a href="?tab=adjust" class="px-4 py-2.5 text-sm font-medium border-b-2 <?= $tab==='adjust' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">Adjust Stock</a>
    <a href="?tab=variants" class="px-4 py-2.5 text-sm font-medium border-b-2 <?= $tab==='variants' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">Variant Stock</a>
    <a href="?tab=movements" class="px-4 py-2.5 text-sm font-medium border-b-2 <?= $tab==='movements' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">Movements</a>
    <a href="?tab=warehouses" class="px-4 py-2.5 text-sm font-medium border-b-2 <?= $tab==='warehouses' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">Warehouses</a>
</div>

<?php if ($tab === 'stock'): ?>
<!-- Stock Levels -->
<div class="bg-white rounded-xl border shadow-sm">
    <div class="p-4 border-b">
        <form class="flex flex-wrap gap-3">
            <input type="hidden" name="tab" value="stock">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search products..." class="flex-1 min-w-[200px] border rounded-lg px-3 py-2 text-sm">
            <select name="warehouse" class="border rounded-lg px-3 py-2 text-sm">
                <option value="">All Warehouses</option>
                <?php foreach ($warehouses as $wh): ?>
                <option value="<?= $wh['id'] ?>" <?= $warehouseFilter==$wh['id']?'selected':'' ?>><?= e($wh['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="stock" class="border rounded-lg px-3 py-2 text-sm">
                <option value="">All Stock</option>
                <option value="in" <?= $stockFilter==='in'?'selected':'' ?>>In Stock</option>
                <option value="low" <?= $stockFilter==='low'?'selected':'' ?>>Low Stock</option>
                <option value="out" <?= $stockFilter==='out'?'selected':'' ?>>Out of Stock</option>
            </select>
            <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700">Filter</button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Product</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">SKU</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Total Stock</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Threshold</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Status</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($stockItems as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <img src="<?= $item['featured_image'] ? uploadUrl('products/' . $item['featured_image']) : asset('img/default-product.svg') ?>" class="w-10 h-10 rounded-lg object-cover border" onerror="this.src='<?= asset('img/default-product.svg') ?>'">
                            <span class="font-medium text-gray-800"><?= e($item['name']) ?></span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-500"><?= e($item['sku'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-center font-semibold"><?= number_format($item['stock_quantity']) ?></td>
                    <td class="px-4 py-3 text-center text-gray-500"><?= $item['low_stock_threshold'] ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($item['stock_quantity'] <= 0): ?>
                            <span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs font-medium">Out of Stock</span>
                        <?php elseif ($item['stock_quantity'] <= $item['low_stock_threshold']): ?>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium">Low Stock</span>
                        <?php else: ?>
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">In Stock</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($stockItems)): ?>
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No products found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="p-4 border-t"><?= renderPagination($pagination) ?></div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'adjust'): ?>
<!-- Stock Adjustment -->
<div class="max-w-2xl">
    <div class="bg-white rounded-xl border shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Stock Adjustment</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="stock_adjustment">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Warehouse *</label>
                    <select name="warehouse_id" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>"><?= e($wh['name']) ?> (<?= $wh['code'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Movement Type *</label>
                    <select name="movement_type" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="in">Stock In (Add)</option>
                        <option value="out">Stock Out (Remove)</option>
                        <option value="adjustment">Adjustment</option>
                        <option value="return">Return</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Product *</label>
                <select name="product_id" required class="w-full border rounded-lg px-3 py-2 text-sm">
                    <option value="">Select Product</option>
                    <?php foreach ($allProducts as $prod): ?>
                    <option value="<?= $prod['id'] ?>"><?= e($prod['name']) ?> <?= $prod['sku'] ? "({$prod['sku']})" : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity *</label>
                <input type="number" name="quantity" required min="1" class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Note</label>
                <textarea name="note" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="Reason for adjustment..."></textarea>
            </div>
            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">Save Adjustment</button>
        </form>
    </div>
</div>

<?php elseif ($tab === 'variants'): ?>
<!-- Variant Stock Management (Feature #7) -->
<?php
$variantSearch = $_GET['vsearch'] ?? '';
$vWhere = "WHERE pv.is_active = 1";
$vParams = [];
if ($variantSearch) { $vWhere .= " AND (p.name LIKE ? OR pv.variant_name LIKE ? OR pv.sku LIKE ?)"; $vParams = ["%$variantSearch%", "%$variantSearch%", "%$variantSearch%"]; }

$variantStock = $db->fetchAll("SELECT pv.*, p.name as product_name, p.featured_image, p.stock_quantity as product_stock 
    FROM product_variants pv 
    JOIN products p ON p.id = pv.product_id 
    {$vWhere} 
    ORDER BY p.name, pv.variant_name 
    LIMIT 200", $vParams);

// Group by product
$grouped = [];
foreach ($variantStock as $v) { $grouped[$v['product_id']]['product'] = $v; $grouped[$v['product_id']]['variants'][] = $v; }
?>
<div class="bg-white rounded-xl border shadow-sm">
    <div class="p-4 border-b">
        <form class="flex gap-3">
            <input type="hidden" name="tab" value="variants">
            <input type="text" name="vsearch" value="<?= e($variantSearch) ?>" placeholder="Search products or variants..." class="flex-1 border rounded-lg px-3 py-2 text-sm">
            <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Search</button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Product</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Variant</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Value</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">SKU</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Stock</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Price Adj.</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Status</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Quick Adjust</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($grouped as $pid => $group):
                    $prod = $group['product'];
                    $variants = $group['variants'];
                    $firstRow = true;
                    foreach ($variants as $v):
                ?>
                <tr class="hover:bg-gray-50">
                    <?php if ($firstRow): ?>
                    <td class="px-4 py-3" rowspan="<?= count($variants) ?>">
                        <div class="flex items-center gap-2">
                            <img src="<?= $prod['featured_image'] ? uploadUrl('products/' . $prod['featured_image']) : '' ?>" class="w-8 h-8 rounded border object-cover" onerror="this.style.display='none'">
                            <div>
                                <p class="font-medium text-gray-800 text-xs"><?= e($prod['product_name']) ?></p>
                                <p class="text-xs text-gray-400">Total: <?= $prod['product_stock'] ?></p>
                            </div>
                        </div>
                    </td>
                    <?php $firstRow = false; endif; ?>
                    <td class="px-4 py-3 text-xs font-medium text-indigo-600"><?= e($v['variant_name']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($v['variant_value']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 font-mono"><?= e($v['sku'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-center font-bold <?= $v['stock_quantity'] <= 0 ? 'text-red-600' : ($v['stock_quantity'] <= 5 ? 'text-yellow-600' : 'text-green-600') ?>"><?= $v['stock_quantity'] ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= $v['price_adjustment'] != 0 ? ($v['price_adjustment'] > 0 ? '+' : '') . '৳' . number_format($v['price_adjustment']) : '-' ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($v['stock_quantity'] <= 0): ?><span class="px-2 py-0.5 bg-red-100 text-red-700 rounded-full text-xs">Out</span>
                        <?php elseif ($v['stock_quantity'] <= 5): ?><span class="px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded-full text-xs">Low</span>
                        <?php else: ?><span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs">OK</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <button onclick="adjustVariant(<?= $v['id'] ?>, -1)" class="w-6 h-6 bg-red-100 text-red-600 rounded text-xs font-bold hover:bg-red-200">-</button>
                            <span class="w-8 text-center text-xs font-mono" id="vstock-<?= $v['id'] ?>"><?= $v['stock_quantity'] ?></span>
                            <button onclick="adjustVariant(<?= $v['id'] ?>, 1)" class="w-6 h-6 bg-green-100 text-green-600 rounded text-xs font-bold hover:bg-green-200">+</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
                <?php if (empty($grouped)): ?><tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">No product variants found. Add variants in product edit.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function adjustVariant(variantId, delta) {
    fetch('<?= SITE_URL ?>/api/variant-stock.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({variant_id: variantId, delta: delta})
    }).then(r => r.json()).then(d => {
        if (d.success) {
            document.getElementById('vstock-' + variantId).textContent = d.new_stock;
        } else {
            alert(d.message || 'Failed');
        }
    });
}
</script>

<?php elseif ($tab === 'movements'): ?>
<!-- Stock Movements History -->
<div class="bg-white rounded-xl border shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Product</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Warehouse</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Type</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Qty</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Note</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">By</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($movements as $mv): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?= date('M d, H:i', strtotime($mv['created_at'])) ?></td>
                    <td class="px-4 py-3 font-medium"><?= e($mv['product_name']) ?></td>
                    <td class="px-4 py-3"><?= e($mv['warehouse_name']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $typeColors = ['in'=>'green','out'=>'red','transfer'=>'blue','adjustment'=>'yellow','return'=>'purple'];
                        $color = $typeColors[$mv['movement_type']] ?? 'gray';
                        ?>
                        <span class="px-2 py-1 bg-<?= $color ?>-100 text-<?= $color ?>-700 rounded-full text-xs font-medium"><?= ucfirst($mv['movement_type']) ?></span>
                    </td>
                    <td class="px-4 py-3 text-center font-semibold <?= in_array($mv['movement_type'],['in','return'])?'text-green-600':'text-red-600' ?>">
                        <?= in_array($mv['movement_type'],['in','return'])?'+':'-' ?><?= $mv['quantity'] ?>
                    </td>
                    <td class="px-4 py-3 text-gray-500"><?= e($mv['note'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-gray-500"><?= e($mv['created_by_name'] ?? 'System') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($movements)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No stock movements recorded</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'warehouses'): ?>
<!-- Warehouses -->
<div class="grid lg:grid-cols-3 gap-6">
    <!-- Add/Edit Form -->
    <div class="lg:col-span-1">
        <?php
        $editWh = null;
        if (isset($_GET['edit'])) {
            $editWh = $db->fetch("SELECT * FROM warehouses WHERE id=?", [(int)$_GET['edit']]);
        }
        ?>
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><?= $editWh ? 'Edit' : 'Add' ?> Warehouse</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="save_warehouse">
                <?php if ($editWh): ?><input type="hidden" name="id" value="<?= $editWh['id'] ?>"><?php endif; ?>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Name *</label>
                    <input type="text" name="name" required value="<?= e($editWh['name'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Code *</label>
                    <input type="text" name="code" required value="<?= e($editWh['code'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="WH-001">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">City</label>
                    <input type="text" name="city" value="<?= e($editWh['city'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Address</label>
                    <textarea name="address" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm"><?= e($editWh['address'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Phone</label>
                    <input type="text" name="phone" value="<?= e($editWh['phone'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Manager</label>
                    <input type="text" name="manager_name" value="<?= e($editWh['manager_name'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" <?= ($editWh['is_active'] ?? 1) ? 'checked' : '' ?> class="rounded">
                    <span>Active</span>
                </label>
                <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <?= $editWh ? 'Update' : 'Add' ?> Warehouse
                </button>
            </form>
        </div>
    </div>
    <!-- Warehouses List -->
    <div class="lg:col-span-2">
        <div class="space-y-3">
            <?php foreach ($warehouses as $wh): ?>
            <div class="bg-white rounded-xl border shadow-sm p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <h4 class="font-semibold text-gray-800"><?= e($wh['name']) ?></h4>
                            <span class="text-xs px-2 py-0.5 bg-gray-100 rounded-full text-gray-500"><?= e($wh['code']) ?></span>
                            <?php if ($wh['is_default']): ?>
                            <span class="text-xs px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full">Default</span>
                            <?php endif; ?>
                            <?php if (!$wh['is_active']): ?>
                            <span class="text-xs px-2 py-0.5 bg-red-100 text-red-700 rounded-full">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-sm text-gray-500"><?= e($wh['city'] ?? '') ?> <?= $wh['address'] ? '· '.e($wh['address']) : '' ?></p>
                        <p class="text-sm text-gray-400 mt-1"><?= $wh['product_count'] ?> products · Manager: <?= e($wh['manager_name'] ?: 'N/A') ?></p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="?tab=warehouses&edit=<?= $wh['id'] ?>" class="text-blue-600 hover:text-blue-800 text-sm">Edit</a>
                        <?php if (!$wh['is_default']): ?>
                        <form method="POST" onsubmit="return confirm('Delete this warehouse?')">
                            <input type="hidden" name="action" value="delete_warehouse">
                            <input type="hidden" name="id" value="<?= $wh['id'] ?>">
                            <button class="text-red-600 hover:text-red-800 text-sm">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
