<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Pages';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $data = [
            'title' => sanitize($_POST['title']),
            'slug' => sanitize($_POST['slug']) ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($_POST['title']))),
            'content' => $_POST['content'], // Allow HTML
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'meta_title' => sanitize($_POST['meta_title']),
            'meta_description' => sanitize($_POST['meta_description']),
        ];

        if ($action === 'add') {
            $db->insert('pages', $data);
            logActivity(getAdminId(), 'create', 'pages', $db->lastInsertId());
            redirect(adminUrl('pages/cms-pages.php?msg=added'));
        } else {
            $id = intval($_POST['page_id']);
            $db->update('pages', $data, 'id = ?', [$id]);
            redirect(adminUrl('pages/cms-pages.php?msg=updated'));
        }
    }

    if ($action === 'delete') {
        $db->delete('pages', 'id = ?', [intval($_POST['page_id'])]);
        redirect(adminUrl('pages/cms-pages.php?msg=deleted'));
    }
}

$pages = $db->fetchAll("SELECT * FROM pages ORDER BY title");
$editPage = null;
if (isset($_GET['edit'])) {
    $editPage = $db->fetch("SELECT * FROM pages WHERE id = ?", [intval($_GET['edit'])]);
}

require_once __DIR__ . '/../includes/header.php';
$msg = $_GET['msg'] ?? '';
?>

<?php if ($msg): ?>
<div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">Page <?= $msg ?>.</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Form -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><?= $editPage ? 'Edit Page' : 'Create New Page' ?></h3>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="<?= $editPage ? 'update' : 'add' ?>">
                <?php if ($editPage): ?><input type="hidden" name="page_id" value="<?= $editPage['id'] ?>"><?php endif; ?>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Title *</label>
                        <input type="text" name="title" value="<?= e($editPage['title'] ?? '') ?>" required class="border rounded-lg px-3 py-2 text-sm w-full" oninput="if(!document.querySelector('[name=slug]').dataset.manual) document.querySelector('[name=slug]').value = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/-$/,'')">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Slug</label>
                        <input type="text" name="slug" value="<?= e($editPage['slug'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm w-full" oninput="this.dataset.manual=true">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Content</label>
                    <textarea name="content" rows="15" class="border rounded-lg px-3 py-2 text-sm w-full font-mono"><?= e($editPage['content'] ?? '') ?></textarea>
                    <p class="text-xs text-gray-400 mt-1">HTML is supported. Use headings, paragraphs, lists etc.</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Meta Title</label>
                        <input type="text" name="meta_title" value="<?= e($editPage['meta_title'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Meta Description</label>
                        <input type="text" name="meta_description" value="<?= e($editPage['meta_description'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm w-full">
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" <?= ($editPage['is_active'] ?? 1) ? 'checked' : '' ?>> Published
                </label>

                <div class="flex gap-3">
                    <button class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700"><?= $editPage ? 'Update Page' : 'Create Page' ?></button>
                    <?php if ($editPage): ?>
                    <a href="<?= adminUrl('pages/cms-pages.php') ?>" class="border px-6 py-2.5 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Page List -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="p-4 border-b">
                <h3 class="font-semibold text-gray-800">All Pages (<?= count($pages) ?>)</h3>
            </div>
            <div class="divide-y">
                <?php foreach ($pages as $page): ?>
                <div class="p-4 hover:bg-gray-50 flex items-center justify-between">
                    <div>
                        <h4 class="font-medium text-sm"><?= e($page['title']) ?></h4>
                        <p class="text-xs text-gray-400">/page/<?= e($page['slug']) ?></p>
                    </div>
                    <div class="flex items-center gap-3">
                        <?php if (!$page['is_active']): ?>
                        <span class="bg-gray-100 text-gray-500 px-2 py-0.5 rounded text-xs">Draft</span>
                        <?php endif; ?>
                        <a href="?edit=<?= $page['id'] ?>" class="text-blue-600 hover:text-blue-800 text-xs">Edit</a>
                        <a href="<?= url('page/' . $page['slug']) ?>" target="_blank" class="text-gray-400 hover:text-gray-600 text-xs">View</a>
                        <form method="POST" class="inline" onsubmit="return confirm('Delete this page?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="page_id" value="<?= $page['id'] ?>">
                            <button class="text-red-500 hover:text-red-700 text-xs">Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
