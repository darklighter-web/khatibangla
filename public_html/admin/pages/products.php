<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Products';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && hasPermission('products')) {
        $pid = intval($_POST['product_id']);
        $db->update('products', ['is_active' => 0], 'id = ?', [$pid]);
        logActivity(getAdminId(), 'delete', 'products', $pid);
        redirect(adminUrl('pages/products.php?msg=deleted'));
    }
    if ($_POST['action'] === 'bulk_delete' && hasPermission('products')) {
        $ids = $_POST['product_ids'] ?? [];
        foreach ($ids as $pid) {
            $db->update('products', ['is_active' => 0], 'id = ?', [intval($pid)]);
        }
        redirect(adminUrl('pages/products.php?msg=bulk_deleted'));
    }
}

// Filters
$search = $_GET['search'] ?? '';
$category = intval($_GET['category'] ?? 0);
$stock = $_GET['stock'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));

$where = 'p.is_active = 1';
$params = [];

// Ensure split columns exist
try { ensureVariationSplitColumns(); } catch (\Throwable $e) {}

// Also show hidden parents (is_active=0) that have split children, so admin can edit them
$showParents = $_GET['show_parents'] ?? '';
if ($showParents === '1') {
    $where = '(p.is_active = 1 OR (p.is_active = 0 AND p.id IN (SELECT DISTINCT parent_product_id FROM products WHERE parent_product_id IS NOT NULL)))';
}

if ($search) { $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)"; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
if ($category) { $where .= " AND p.category_id = ?"; $params[] = $category; }
if ($stock === 'low') { $where .= " AND p.stock_quantity <= p.low_stock_threshold AND p.manage_stock = 1"; }
if ($stock === 'out') { $where .= " AND p.stock_quantity = 0 AND p.manage_stock = 1"; }

$total = $db->fetch("SELECT COUNT(*) as cnt FROM products p WHERE {$where}", $params)['cnt'];
$limit = ADMIN_ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;
$totalPages = ceil($total / $limit);

$products = $db->fetchAll("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE {$where} ORDER BY p.created_at DESC LIMIT {$limit} OFFSET {$offset}", $params);
$categories = $db->fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY name");

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm">
    <?= $_GET['msg'] === 'saved' ? 'Product saved successfully.' : ($_GET['msg'] === 'deleted' ? 'Product deleted.' : 'Products deleted.') ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
    <form method="GET" class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search name or SKU..." class="px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        <select name="category" class="px-3 py-2 border rounded-lg text-sm">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="stock" class="px-3 py-2 border rounded-lg text-sm">
            <option value="">All Stock</option>
            <option value="low" <?= $stock === 'low' ? 'selected' : '' ?>>Low Stock</option>
            <option value="out" <?= $stock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
        </select>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">Filter</button>
        <div class="flex gap-2">
            <a href="<?= SITE_URL ?>/api/export.php?type=products" class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50"><i class="fas fa-download mr-1"></i> Export</a>
            <a href="<?= adminUrl('pages/product-form.php') ?>" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700 text-center">+ Add Product</a>
        </div>
    </form>
    <?php
    // Show parents toggle (only relevant in split mode)
    try {
        $__splitCount = intval($db->fetch("SELECT COUNT(*) as cnt FROM products WHERE parent_product_id IS NOT NULL AND parent_product_id > 0")['cnt'] ?? 0);
    } catch (\Throwable $e) { $__splitCount = 0; }
    if ($__splitCount > 0): ?>
    <div class="flex items-center gap-3 mt-3 pt-3 border-t">
        <span class="text-xs text-indigo-600"><i class="fas fa-layer-group mr-1"></i> Split mode active: <?= $__splitCount ?> variation products</span>
        <?php if ($showParents !== '1'): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['show_parents' => '1'])) ?>" class="text-xs text-indigo-600 underline hover:text-indigo-800">Show hidden parents</a>
        <?php else: ?>
        <a href="?<?= http_build_query(array_diff_key($_GET, ['show_parents' => ''])) ?>" class="text-xs text-indigo-600 underline hover:text-indigo-800">Hide parents</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Products Table -->
<form method="POST" id="bulkForm">
<input type="hidden" name="action" value="bulk_delete">
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="flex items-center justify-between p-4 border-b">
        <p class="text-sm text-gray-600"><strong><?= number_format($total) ?></strong> products</p>
        <div id="bulkBar" class="hidden flex items-center gap-2">
            <span class="text-sm text-blue-700 font-medium"><span id="selectedCount">0</span> selected</span>
            <button type="submit" onclick="return confirm('Delete selected products?')" class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-red-700">Delete Selected</button>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Product</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Category</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Price</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Stock</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Sales</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($products as $p): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3"><input type="checkbox" name="product_ids[]" value="<?= $p['id'] ?>" class="item-check" onchange="updateBulk()"></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                                <?php if ($p['featured_image']): ?>
                                <img src="<?= uploadUrl('products/' . $p['featured_image']) ?>" class="w-full h-full object-cover" alt="" onerror="this.src='<?= asset('img/default-product.svg') ?>'">
                                <?php else: ?>
                                <img src="<?= asset('img/default-product.svg') ?>" class="w-full h-full object-cover" alt="">
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800 truncate max-w-[200px]">
                                    <?= e($p['name']) ?>
                                    <?php if (($p['product_type'] ?? 'simple') === 'variable'): ?>
                                    <span class="inline-block text-[9px] bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded font-medium ml-1 align-middle">Variable</span>
                                    <?php endif; ?>
                                </p>
                                <p class="text-xs text-gray-400"><?= e($p['sku'] ?? 'No SKU') ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-600"><?= e($p['category_name'] ?? 'Uncategorized') ?></td>
                    <td class="px-4 py-3">
                        <?php if (($p['product_type'] ?? 'simple') === 'variable'):
                            try {
                                static $_listVarCache = [];
                                if (!isset($_listVarCache[$p['id']])) {
                                    $_listVarCache[$p['id']] = $db->fetch(
                                        "SELECT MIN(absolute_price) as pmin, MAX(absolute_price) as pmax, MAX(COALESCE(var_regular_price, absolute_price)) as reg_max FROM product_variants WHERE product_id = ? AND option_type = 'variation' AND is_active = 1 AND absolute_price > 0",
                                        [$p['id']]
                                    );
                                }
                                $vr = $_listVarCache[$p['id']];
                                if ($vr && floatval($vr['pmin']) > 0):
                                    $hasVarDiscount = floatval($vr['reg_max'] ?? 0) > floatval($vr['pmax']); ?>
                                <span class="font-semibold text-purple-600">৳<?= number_format($vr['pmin']) ?><?php if ($vr['pmin'] != $vr['pmax']): ?> – ৳<?= number_format($vr['pmax']) ?><?php endif; ?></span>
                                <?php if ($hasVarDiscount): ?>
                                <span class="text-xs text-gray-400 line-through ml-1">৳<?= number_format($vr['reg_max']) ?></span>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-xs text-gray-400">No prices</span>
                                <?php endif;
                            } catch (\Throwable $e) { echo '<span class="font-semibold">৳' . number_format($p['regular_price']) . '</span>'; }
                        elseif ($p['sale_price'] && $p['sale_price'] < $p['regular_price']): ?>
                        <span class="font-semibold text-green-600">৳<?= number_format($p['sale_price']) ?></span>
                        <span class="text-xs text-gray-400 line-through ml-1">৳<?= number_format($p['regular_price']) ?></span>
                        <?php else: ?>
                        <span class="font-semibold">৳<?= number_format($p['regular_price']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if (($p['product_type'] ?? 'simple') === 'variable'):
                            // Show per-variant stock summary
                            try {
                                $varStocks = $db->fetchAll(
                                    "SELECT variant_value, stock_quantity, manage_stock FROM product_variants WHERE product_id = ? AND option_type = 'variation' AND is_active = 1 ORDER BY id",
                                    [$p['id']]
                                );
                                $totalVarStock = 0; $trackingAny = false;
                                foreach ($varStocks as $vs) {
                                    if (intval($vs['manage_stock'] ?? 1)) { $totalVarStock += intval($vs['stock_quantity']); $trackingAny = true; }
                                }
                                if ($trackingAny): ?>
                                    <?php if ($totalVarStock <= 0): ?>
                                    <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full">Out</span>
                                    <?php else: ?>
                                    <span class="text-sm text-purple-600" title="<?php foreach($varStocks as $vs) echo e($vs['variant_value']).': '.$vs['stock_quantity'].'  '; ?>"><?= $totalVarStock ?> <span class="text-[10px] text-gray-400">total</span></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                <span class="text-xs text-gray-400">—</span>
                                <?php endif;
                            } catch (\Throwable $e) { echo '<span class="text-xs text-gray-400">—</span>'; }
                        elseif ($p['manage_stock']): ?>
                            <?php if ($p['stock_quantity'] <= 0): ?>
                            <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full">Out</span>
                            <?php elseif ($p['stock_quantity'] <= $p['low_stock_threshold']): ?>
                            <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full"><?= $p['stock_quantity'] ?> left</span>
                            <?php else: ?>
                            <span class="text-sm"><?= $p['stock_quantity'] ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">Not tracked</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600"><?= $p['sales_count'] ?></td>
                    <td class="px-4 py-3">
                        <?php if ($p['is_featured']): ?><span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full mr-1">Featured</span><?php endif; ?>
                        <?php if ($p['is_on_sale']): ?><span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full mr-1">Sale</span><?php endif; ?>
                        <?php if (!empty($p['parent_product_id'])): ?>
                        <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full" title="Split from parent #<?= intval($p['parent_product_id']) ?>">📦 <?= e($p['variant_label'] ?? 'Split') ?></span>
                        <?php endif; ?>
                        <?php if (empty($p['is_active'])): ?>
                        <span class="text-xs bg-gray-200 text-gray-600 px-2 py-0.5 rounded-full" title="Hidden parent (split mode)">👁 Hidden</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex gap-1">
                            <a href="<?= adminUrl('pages/product-form.php?id=' . $p['id']) ?>" class="p-1.5 rounded hover:bg-gray-100" title="Edit">
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </a>
                            <a href="<?= url('product/' . $p['slug']) ?>" target="_blank" class="p-1.5 rounded hover:bg-gray-100" title="View">
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            </a>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this product?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button class="p-1.5 rounded hover:bg-red-50" title="Delete">
                                    <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">No products found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between px-4 py-3 border-t">
        <p class="text-sm text-gray-500">Page <?= $page ?> of <?= $totalPages ?></p>
        <div class="flex gap-1">
            <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="px-3 py-1.5 text-sm rounded <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
</form>

<script>
function toggleAll(el) { document.querySelectorAll('.item-check').forEach(c => c.checked = el.checked); updateBulk(); }
function updateBulk() {
    const n = document.querySelectorAll('.item-check:checked').length;
    document.getElementById('bulkBar').classList.toggle('hidden', n === 0);
    document.getElementById('selectedCount').textContent = n;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
