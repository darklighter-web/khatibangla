<?php
/**
 * Campaign Products - Map Meta ad campaigns to products and landing pages
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Campaign Products';
$db = Database::getInstance();

// Ensure table exists
try {
    $db->query("SELECT 1 FROM meta_ads_campaign_products LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {
    $tableExists = false;
}

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_mapping') {
        $id = intval($_POST['id'] ?? 0);
        $data = [
            sanitize($_POST['campaign_id'] ?? ''),
            sanitize($_POST['campaign_name'] ?? ''),
            intval($_POST['product_id'] ?? 0),
            intval($_POST['landing_page_id'] ?? 0) ?: null,
            sanitize($_POST['status'] ?? 'active'),
            sanitize($_POST['notes'] ?? ''),
        ];
        
        if ($id > 0) {
            $db->query("UPDATE meta_ads_campaign_products SET campaign_id=?, campaign_name=?, product_id=?, landing_page_id=?, status=?, notes=? WHERE id=?",
                [...$data, $id]);
        } else {
            $db->query("INSERT INTO meta_ads_campaign_products (campaign_id, campaign_name, product_id, landing_page_id, status, notes) VALUES (?,?,?,?,?,?)", $data);
        }
        redirect(adminUrl('pages/meta-ads-campaigns.php?msg=saved'));
    }
    
    if ($action === 'delete_mapping') {
        $db->query("DELETE FROM meta_ads_campaign_products WHERE id=?", [intval($_POST['id'] ?? 0)]);
        redirect(adminUrl('pages/meta-ads-campaigns.php?msg=deleted'));
    }
    
    if ($action === 'toggle_status') {
        $id = intval($_POST['id'] ?? 0);
        $current = $db->fetch("SELECT status FROM meta_ads_campaign_products WHERE id=?", [$id]);
        $newStatus = ($current['status'] ?? '') === 'active' ? 'paused' : 'active';
        $db->query("UPDATE meta_ads_campaign_products SET status=? WHERE id=?", [$newStatus, $id]);
        redirect(adminUrl('pages/meta-ads-campaigns.php?msg=updated'));
    }
}

// Get products for dropdown
$products = $db->fetchAll("SELECT id, name, sku FROM products WHERE is_active=1 ORDER BY name");

// Get landing pages for dropdown
try {
    $landingPages = $db->fetchAll("SELECT id, title FROM landing_pages ORDER BY title");
} catch (Exception $e) {
    try {
        $landingPages = $db->fetchAll("SELECT id, title FROM pages WHERE slug LIKE 'lp-%' OR title LIKE '%landing%' ORDER BY title");
    } catch (Exception $e2) {
        $landingPages = [];
    }
}

// Get campaign-product mappings
$mappings = [];
if ($tableExists) {
    $search = $_GET['search'] ?? '';
    $statusFilter = $_GET['status'] ?? '';
    
    $where = "WHERE 1=1";
    $params = [];
    if ($search) { $where .= " AND (cp.campaign_name LIKE ? OR p.name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($statusFilter) { $where .= " AND cp.status = ?"; $params[] = $statusFilter; }
    
    $mappings = $db->fetchAll("SELECT cp.*, p.name as product_name, p.sku as product_sku,
        pg.title as landing_page_name
        FROM meta_ads_campaign_products cp
        LEFT JOIN products p ON cp.product_id = p.id
        LEFT JOIN pages pg ON cp.landing_page_id = pg.id
        $where ORDER BY cp.status ASC, cp.created_at DESC", $params);
}

// Ad spend per campaign (if data exists)
$campaignSpend = [];
try {
    $spendData = $db->fetchAll("SELECT campaign_id, SUM(amount_bdt) as total_spend, SUM(conversions) as conversions
        FROM ad_expenses WHERE campaign_id IS NOT NULL GROUP BY campaign_id");
    foreach ($spendData as $s) { $campaignSpend[$s['campaign_id']] = $s; }
} catch (Exception $e) {}

// Edit mapping
$editMapping = null;
if (isset($_GET['edit']) && $tableExists) {
    $editMapping = $db->fetch("SELECT * FROM meta_ads_campaign_products WHERE id=?", [intval($_GET['edit'])]);
}

$msg = $_GET['msg'] ?? '';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$tableExists): ?>
<div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl">
    <p class="text-amber-800 font-medium"><i class="fas fa-exclamation-triangle mr-2"></i>Database tables not found. Run migration:</p>
    <code class="block mt-2 p-2 bg-white rounded text-sm text-gray-700">admin/migrations/002_new_features.sql</code>
</div>
<?php endif; ?>

<?php if ($msg): ?>
<div class="mb-4 p-3 bg-green-50 text-green-700 rounded-xl text-sm"><?= ucfirst($msg) ?>!</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Left: Form -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><?= $editMapping ? 'Edit' : 'Add' ?> Campaign-Product Mapping</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="save_mapping">
                <?php if ($editMapping): ?><input type="hidden" name="id" value="<?= $editMapping['id'] ?>"><?php endif; ?>
                
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Campaign ID *</label>
                    <input type="text" name="campaign_id" required value="<?= e($editMapping['campaign_id'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="e.g. 23856789012345">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Campaign Name *</label>
                    <input type="text" name="campaign_name" required value="<?= e($editMapping['campaign_name'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="e.g. Winter Sale - Product X">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Product *</label>
                    <select name="product_id" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="">Select Product</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($editMapping['product_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['name']) ?> <?= $p['sku'] ? '(' . e($p['sku']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Landing Page</label>
                    <select name="landing_page_id" class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="">None</option>
                        <?php foreach ($landingPages as $lp): ?>
                        <option value="<?= $lp['id'] ?>" <?= ($editMapping['landing_page_id'] ?? '') == $lp['id'] ? 'selected' : '' ?>><?= e($lp['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                    <select name="status" class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="active" <?= ($editMapping['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="paused" <?= ($editMapping['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                        <option value="stopped" <?= ($editMapping['status'] ?? '') === 'stopped' ? 'selected' : '' ?>>Stopped</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm"><?= e($editMapping['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <?= $editMapping ? 'Update' : 'Add' ?> Mapping
                </button>
                <?php if ($editMapping): ?>
                <a href="<?= adminUrl('pages/meta-ads-campaigns.php') ?>" class="block text-center text-gray-500 text-sm hover:text-gray-700">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Stats -->
        <div class="bg-white rounded-xl border shadow-sm p-5 mt-4">
            <h4 class="font-semibold text-gray-800 mb-3">Quick Stats</h4>
            <?php 
            $active = count(array_filter($mappings, fn($m) => $m['status'] === 'active'));
            $paused = count(array_filter($mappings, fn($m) => $m['status'] === 'paused'));
            $stopped = count(array_filter($mappings, fn($m) => $m['status'] === 'stopped'));
            ?>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-500">Total Mappings</span><span class="font-medium"><?= count($mappings) ?></span></div>
                <div class="flex justify-between"><span class="text-green-600">Active</span><span class="font-medium"><?= $active ?></span></div>
                <div class="flex justify-between"><span class="text-yellow-600">Paused</span><span class="font-medium"><?= $paused ?></span></div>
                <div class="flex justify-between"><span class="text-red-600">Stopped</span><span class="font-medium"><?= $stopped ?></span></div>
            </div>
        </div>
    </div>
    
    <!-- Right: List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="p-4 border-b">
                <form class="flex flex-wrap gap-3">
                    <input type="text" name="search" value="<?= e($search ?? '') ?>" placeholder="Search campaigns or products..." class="flex-1 min-w-[150px] border rounded-lg px-3 py-2 text-sm">
                    <select name="status" class="border rounded-lg px-3 py-2 text-sm">
                        <option value="">All Statuses</option>
                        <option value="active" <?= ($statusFilter ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="paused" <?= ($statusFilter ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                        <option value="stopped" <?= ($statusFilter ?? '') === 'stopped' ? 'selected' : '' ?>>Stopped</option>
                    </select>
                    <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700">Filter</button>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Campaign</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Product</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Landing Page</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Spend</th>
                        <th class="text-center px-4 py-3 font-medium text-gray-600">Status</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        <?php foreach ($mappings as $m): 
                            $spend = $campaignSpend[$m['campaign_id']] ?? ['total_spend' => 0, 'conversions' => 0];
                            $statusBadge = ['active'=>'bg-green-100 text-green-700','paused'=>'bg-yellow-100 text-yellow-700','stopped'=>'bg-red-100 text-red-700'];
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-800 truncate max-w-[200px]"><?= e($m['campaign_name']) ?></p>
                                <p class="text-xs text-gray-400"><?= e($m['campaign_id']) ?></p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-sm"><?= e($m['product_name'] ?? 'Unknown') ?></p>
                                <?php if ($m['product_sku'] ?? ''): ?><p class="text-xs text-gray-400"><?= e($m['product_sku']) ?></p><?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500"><?= e($m['landing_page_name'] ?? '—') ?></td>
                            <td class="px-4 py-3 text-right">
                                <?php if ($spend['total_spend'] > 0): ?>
                                <p class="font-semibold text-sm">৳<?= number_format($spend['total_spend']) ?></p>
                                <p class="text-xs text-gray-400"><?= $spend['conversions'] ?> conv.</p>
                                <?php else: ?>
                                <span class="text-gray-400 text-xs">No data</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                    <button class="<?= $statusBadge[$m['status']] ?? 'bg-gray-100 text-gray-600' ?> px-2 py-0.5 rounded-full text-xs font-bold cursor-pointer hover:opacity-80">
                                        <?= ucfirst($m['status']) ?>
                                    </button>
                                </form>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="?edit=<?= $m['id'] ?>" class="text-blue-600 hover:text-blue-800 text-xs mr-2">Edit</a>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this mapping?')">
                                    <input type="hidden" name="action" value="delete_mapping">
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                    <button class="text-red-600 hover:text-red-800 text-xs">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($mappings)): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No campaign-product mappings yet. Add one using the form.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
