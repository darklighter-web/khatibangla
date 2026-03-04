<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Categories';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $catId = intval($_POST['category_id'] ?? 0);
        $name = sanitize($_POST['name']);
        $slug = sanitize($_POST['slug']) ?: strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        $data = [
            'name' => $name,
            'slug' => $slug,
            'name_bn' => sanitize($_POST['name_bn'] ?? ''),
            'parent_id' => intval($_POST['parent_id']) ?: null,
            'description' => sanitize($_POST['description'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'sort_order' => intval($_POST['sort_order'] ?? 0),
        ];
        
        if (!empty($_FILES['image']['name'])) {
            $upload = uploadFile($_FILES['image'], 'categories');
            if ($upload) $data['image'] = $upload;
        }
        
        if ($catId) {
            $db->update('categories', $data, 'id = ?', [$catId]);
            logActivity(getAdminId(), 'update', 'categories', $catId);
        } else {
            $catId = $db->insert('categories', $data);
            logActivity(getAdminId(), 'create', 'categories', $catId);
        }
        redirect(adminUrl('pages/categories.php?msg=saved'));
    }
    
    if ($action === 'delete') {
        $catId = intval($_POST['category_id']);
        $db->update('categories', ['is_active' => 0], 'id = ?', [$catId]);
        logActivity(getAdminId(), 'delete', 'categories', $catId);
        redirect(adminUrl('pages/categories.php?msg=deleted'));
    }
}

$categories = $db->fetchAll("SELECT c.*, pc.name as parent_name, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) as product_count FROM categories c LEFT JOIN categories pc ON pc.id = c.parent_id WHERE c.is_active = 1 ORDER BY c.sort_order, c.name");
$editCat = isset($_GET['edit']) ? $db->fetch("SELECT * FROM categories WHERE id = ?", [intval($_GET['edit'])]) : null;

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm">
    <?= $_GET['msg'] === 'saved' ? 'Category saved.' : 'Category deleted.' ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Form -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h4 class="font-semibold text-gray-800 mb-4"><?= $editCat ? 'Edit Category' : 'Add Category' ?></h4>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="category_id" value="<?= $editCat['id'] ?? 0 ?>">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                <input type="text" name="name" value="<?= e($editCat['name'] ?? '') ?>" required class="w-full px-3 py-2 border rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name (Bengali)</label>
                <input type="text" name="name_bn" value="<?= e($editCat['name_bn'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                <input type="text" name="slug" value="<?= e($editCat['slug'] ?? '') ?>" placeholder="auto-generated" class="w-full px-3 py-2 border rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Parent Category</label>
                <select name="parent_id" class="w-full px-3 py-2 border rounded-lg text-sm">
                    <option value="">None (Top Level)</option>
                    <?php foreach ($categories as $cat): if ($editCat && $cat['id'] == $editCat['id']) continue; ?>
                    <option value="<?= $cat['id'] ?>" <?= ($editCat['parent_id'] ?? 0) == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"><?= e($editCat['description'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Image</label>
                <?php if ($editCat && $editCat['image']): ?>
                <img src="<?= uploadUrl($editCat['image']) ?>" class="w-16 h-16 object-cover rounded-lg mb-2">
                <?php endif; ?>
                <input type="file" name="image" accept="image/*" class="text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
                <input type="number" name="sort_order" value="<?= $editCat['sort_order'] ?? 0 ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
            </div>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="is_active" value="1" <?= ($editCat['is_active'] ?? 1) ? 'checked' : '' ?> class="rounded">
                <span class="text-sm text-gray-700">Active</span>
            </label>
            <div class="flex gap-2">
                <button class="flex-1 bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700"><?= $editCat ? 'Update' : 'Create' ?></button>
                <?php if ($editCat): ?>
                <a href="<?= adminUrl('pages/categories.php') ?>" class="px-4 py-2 bg-gray-100 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Categories List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 border-b"><p class="text-sm text-gray-600"><strong><?= count($categories) ?></strong> categories</p></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Category</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Parent</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Products</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Order</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($categories as $cat): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <?php if ($cat['image']): ?>
                                    <img src="<?= uploadUrl($cat['image']) ?>" class="w-10 h-10 object-cover rounded-lg">
                                    <?php endif; ?>
                                    <div>
                                        <p class="font-medium text-gray-800"><?= e($cat['name']) ?></p>
                                        <?php if ($cat['name_bn']): ?><p class="text-xs text-gray-500"><?= e($cat['name_bn']) ?></p><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-500"><?= e($cat['parent_name'] ?? '—') ?></td>
                            <td class="px-4 py-3"><?= $cat['product_count'] ?></td>
                            <td class="px-4 py-3"><?= $cat['sort_order'] ?></td>
                            <td class="px-4 py-3">
                                <div class="flex gap-1">
                                    <a href="?edit=<?= $cat['id'] ?>" class="p-1.5 rounded hover:bg-gray-100" title="Edit">
                                        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </a>
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this category?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                        <button class="p-1.5 rounded hover:bg-red-50"><svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
