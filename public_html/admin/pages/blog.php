<?php
/**
 * Blog Management — Admin Panel
 * Media Gallery image picker, Delete Block, Fixed links, Template selection
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Blog Posts';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// ── Auto-create tables if missing ──
try { $db->query("SELECT 1 FROM blog_posts LIMIT 1");
    // Fix ENUM→VARCHAR if table was created by older version
    try {
        $ci = $db->fetch("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='blog_posts' AND COLUMN_NAME='template'");
        if ($ci && stripos($ci['COLUMN_TYPE'] ?? '', 'enum') !== false) {
            $db->query("ALTER TABLE blog_posts MODIFY COLUMN `template` VARCHAR(50) DEFAULT 'classic'");
        }
        $ci2 = $db->fetch("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='blog_posts' AND COLUMN_NAME='status'");
        if ($ci2 && stripos($ci2['COLUMN_TYPE'] ?? '', 'enum') !== false) {
            $db->query("ALTER TABLE blog_posts MODIFY COLUMN `status` VARCHAR(20) DEFAULT 'draft'");
        }
    } catch (\Throwable $ax) {}
    // Add recipe_data column if missing
    try {
        $db->query("SELECT recipe_data FROM blog_posts LIMIT 1");
    } catch (\Throwable $ax) {
        try { $db->query("ALTER TABLE blog_posts ADD COLUMN `recipe_data` LONGTEXT DEFAULT NULL AFTER `content_bn`"); } catch (\Throwable $bx) {}
    }
} catch (\Throwable $e) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS blog_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(500) NOT NULL,
            title_bn VARCHAR(500) DEFAULT NULL,
            slug VARCHAR(500) NOT NULL UNIQUE,
            excerpt TEXT DEFAULT NULL,
            excerpt_bn TEXT DEFAULT NULL,
            content LONGTEXT NOT NULL,
            content_bn LONGTEXT DEFAULT NULL,
            recipe_data LONGTEXT DEFAULT NULL,
            featured_image VARCHAR(500) DEFAULT NULL,
            template VARCHAR(50) DEFAULT 'classic',
            status VARCHAR(20) DEFAULT 'draft',
            author_name VARCHAR(200) DEFAULT NULL,
            author_avatar VARCHAR(500) DEFAULT NULL,
            category VARCHAR(200) DEFAULT NULL,
            tags VARCHAR(500) DEFAULT NULL,
            meta_title VARCHAR(500) DEFAULT NULL,
            meta_description TEXT DEFAULT NULL,
            meta_image VARCHAR(500) DEFAULT NULL,
            views INT DEFAULT 0,
            is_featured TINYINT(1) DEFAULT 0,
            allow_comments TINYINT(1) DEFAULT 1,
            published_at DATETIME DEFAULT NULL,
            scheduled_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by INT DEFAULT NULL,
            INDEX idx_status(status),
            INDEX idx_slug(slug),
            INDEX idx_published(published_at),
            INDEX idx_featured(is_featured),
            INDEX idx_category(category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $ex) {}
}
try { $db->query("SELECT 1 FROM blog_categories LIMIT 1"); } catch (\Throwable $e) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS blog_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            name_bn VARCHAR(200) DEFAULT NULL,
            slug VARCHAR(200) NOT NULL UNIQUE,
            description TEXT DEFAULT NULL,
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("INSERT IGNORE INTO blog_categories (name,name_bn,slug,sort_order) VALUES
            ('Tips & Tricks','টিপস ও ট্রিকস','tips-tricks',1),
            ('Product Review','পণ্য রিভিউ','product-review',2),
            ('News & Updates','খবর ও আপডেট','news-updates',3),
            ('Lifestyle','লাইফস্টাইল','lifestyle',4)");
    } catch (\Throwable $ex) {}
}

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save' || $action === 'update') {
        $title = trim($_POST['title'] ?? '');
        $slug  = trim($_POST['slug'] ?? '');
        if (!$slug) $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $slug = trim($slug, '-');

        // Validate template value
        $validTemplates = ['classic','magazine','minimal','modern','recipe'];
        $tplVal = $_POST['template'] ?? 'classic';
        if (!in_array($tplVal, $validTemplates)) $tplVal = 'classic';

        // Build recipe_data JSON if recipe template
        $recipeJson = null;
        if ($tplVal === 'recipe') {
            // Build structured ingredients [{name, amount}]
            $ingNames   = $_POST['recipe_ing_name'] ?? [];
            $ingAmounts = $_POST['recipe_ing_amount'] ?? [];
            $ingredients = [];
            foreach ($ingNames as $k => $n) {
                $n = trim($n);
                if ($n !== '') $ingredients[] = ['name' => $n, 'amount' => trim($ingAmounts[$k] ?? '')];
            }
            // Build structured steps [{title, description, image}]
            $stepTitles = $_POST['recipe_step_title'] ?? [];
            $stepDescs  = $_POST['recipe_step_desc'] ?? [];
            $stepImgs   = $_POST['recipe_step_img'] ?? [];
            $steps = [];
            foreach ($stepTitles as $k => $t) {
                $t = trim($t); $d = trim($stepDescs[$k] ?? '');
                if ($t !== '' || $d !== '') $steps[] = ['title' => $t, 'description' => $d, 'image' => trim($stepImgs[$k] ?? '')];
            }
            $recipeJson = json_encode([
                'prep_time'   => trim($_POST['recipe_prep_time'] ?? ''),
                'cook_time'   => trim($_POST['recipe_cook_time'] ?? ''),
                'servings'    => trim($_POST['recipe_servings'] ?? ''),
                'difficulty'  => trim($_POST['recipe_difficulty'] ?? 'Easy'),
                'calories'    => trim($_POST['recipe_calories'] ?? ''),
                'ingredients' => $ingredients,
                'steps'       => $steps,
            ], JSON_UNESCAPED_UNICODE);
        }

        $data = [
            'title'            => $title,
            'title_bn'         => trim($_POST['title_bn'] ?? '') ?: null,
            'slug'             => $slug,
            'excerpt'          => trim($_POST['excerpt'] ?? '') ?: null,
            'excerpt_bn'       => trim($_POST['excerpt_bn'] ?? '') ?: null,
            'content'          => $_POST['content'] ?? '',
            'content_bn'       => ($_POST['content_bn'] ?? '') ?: null,
            'template'         => $tplVal,
            'status'           => ($_POST['status'] ?? 'draft'),
            'author_name'      => trim($_POST['author_name'] ?? '') ?: null,
            'category'         => trim($_POST['category'] ?? '') ?: null,
            'tags'             => trim($_POST['tags'] ?? '') ?: null,
            'meta_title'       => trim($_POST['meta_title'] ?? '') ?: null,
            'meta_description' => trim($_POST['meta_description'] ?? '') ?: null,
            'is_featured'      => isset($_POST['is_featured']) ? 1 : 0,
            'allow_comments'   => isset($_POST['allow_comments']) ? 1 : 0,
            'recipe_data'      => $recipeJson,
        ];

        if ($data['status'] === 'published' && empty($_POST['published_at'])) {
            $data['published_at'] = date('Y-m-d H:i:s');
        } elseif (!empty($_POST['published_at'])) {
            $data['published_at'] = date('Y-m-d H:i:s', strtotime($_POST['published_at']));
        }

        // Featured image: file upload or existing
        if (!empty($_FILES['featured_image']['tmp_name'])) {
            $img = uploadFile($_FILES['featured_image'], 'blog');
            if ($img) $data['featured_image'] = $img;
        } elseif (!empty($_POST['existing_image'])) {
            $data['featured_image'] = $_POST['existing_image'];
        }

        if ($action === 'save') {
            $data['created_by'] = getAdminId();
            $db->insert('blog_posts', $data);
            logActivity(getAdminId(), 'create', 'blog_posts', $db->lastInsertId());
            redirect(adminUrl('pages/blog.php?msg=created'));
        } else {
            $id = intval($_POST['post_id']);
            $db->update('blog_posts', $data, 'id = ?', [$id]);
            logActivity(getAdminId(), 'update', 'blog_posts', $id);
            redirect(adminUrl('pages/blog.php?msg=updated'));
        }
    }

    if ($action === 'delete') {
        $db->delete('blog_posts', 'id = ?', [intval($_POST['post_id'])]);
        redirect(adminUrl('pages/blog.php?msg=deleted'));
    }
    if ($action === 'toggle_status') {
        $id = intval($_POST['post_id']);
        $row = $db->fetch("SELECT status FROM blog_posts WHERE id = ?", [$id]);
        if ($row) {
            $ns = (($row['status'] ?? '') === 'published') ? 'draft' : 'published';
            $u = ['status' => $ns];
            if ($ns === 'published') $u['published_at'] = date('Y-m-d H:i:s');
            $db->update('blog_posts', $u, 'id = ?', [$id]);
        }
        redirect(adminUrl('pages/blog.php?msg=status_changed'));
    }
    if ($action === 'duplicate') {
        $id = intval($_POST['post_id']);
        $orig = $db->fetch("SELECT * FROM blog_posts WHERE id = ?", [$id]);
        if ($orig) {
            unset($orig['id'], $orig['created_at'], $orig['updated_at'], $orig['views']);
            $orig['title']  = ($orig['title'] ?? '') . ' (Copy)';
            $orig['slug']   = ($orig['slug'] ?? '') . '-copy-' . time();
            $orig['status'] = 'draft';
            $orig['published_at'] = null;
            $orig['created_by']   = getAdminId();
            $db->insert('blog_posts', $orig);
        }
        redirect(adminUrl('pages/blog.php?msg=duplicated'));
    }
    if ($action === 'save_category') {
        $cn = sanitize($_POST['cat_name'] ?? '');
        $cd = [
            'name'      => $cn,
            'name_bn'   => sanitize($_POST['cat_name_bn'] ?? '') ?: null,
            'slug'      => trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $cn)), '-'),
            'is_active' => 1,
        ];
        if (!empty($_POST['cat_id'])) {
            $db->update('blog_categories', $cd, 'id = ?', [intval($_POST['cat_id'])]);
        } else {
            $db->insert('blog_categories', $cd);
        }
        redirect(adminUrl('pages/blog.php?tab=categories&msg=saved'));
    }
    if ($action === 'delete_category') {
        $db->delete('blog_categories', 'id = ?', [intval($_POST['cat_id'])]);
        redirect(adminUrl('pages/blog.php?tab=categories&msg=deleted'));
    }
}

// ── Fetch data ──
$tab = $_GET['tab'] ?? 'posts';
$statusFilter = $_GET['status'] ?? '';
$where = '1=1'; $params = [];
if ($statusFilter) { $where .= " AND status = ?"; $params[] = $statusFilter; }
$posts = $db->fetchAll("SELECT * FROM blog_posts WHERE {$where} ORDER BY COALESCE(published_at, created_at) DESC", $params);
$categories = [];
try { $categories = $db->fetchAll("SELECT * FROM blog_categories ORDER BY sort_order, name"); } catch (\Throwable $e) {}

$editPost = null;
if (isset($_GET['edit'])) {
    $editPost = $db->fetch("SELECT * FROM blog_posts WHERE id = ?", [intval($_GET['edit'])]);
}
$isNew = isset($_GET['new']) || $editPost;

// Safe template value
$currentTpl = 'classic';
if ($editPost && !empty($editPost['template'])) {
    $currentTpl = $editPost['template'];
}

// Safe stats
$blogStats = ['total' => 0, 'published' => 0, 'draft' => 0];
try {
    $s = $db->fetch("SELECT
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status='published' THEN 1 ELSE 0 END), 0) as published,
        COALESCE(SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END), 0) as draft
        FROM blog_posts");
    if ($s) {
        $blogStats['total']     = intval($s['total'] ?? 0);
        $blogStats['published'] = intval($s['published'] ?? 0);
        $blogStats['draft']     = intval($s['draft'] ?? 0);
    }
} catch (\Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
$msg = $_GET['msg'] ?? '';
?>

<?php if ($msg): ?>
<div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
    ✅ <?php
    $msgs = ['created'=>'Blog post created!','updated'=>'Blog post updated!','deleted'=>'Post deleted.','status_changed'=>'Status updated.','duplicated'=>'Post duplicated as draft.','saved'=>'Saved!'];
    echo $msgs[$msg] ?? 'Done.';
    ?>
</div>
<?php endif; ?>

<!-- Tab Navigation -->
<div class="flex items-center gap-2 mb-5 flex-wrap">
    <a href="?tab=posts" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= ($tab==='posts' && !$isNew) ? 'bg-blue-600 text-white shadow' : 'bg-white border hover:bg-gray-50 text-gray-600' ?>">
        📝 All Posts <span class="ml-1 text-xs opacity-70">(<?= $blogStats['total'] ?>)</span>
    </a>
    <a href="?new=1" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $isNew ? 'bg-blue-600 text-white shadow' : 'bg-white border hover:bg-gray-50 text-gray-600' ?>">
        ➕ <?= $editPost ? 'Edit Post' : 'New Post' ?>
    </a>
    <a href="?tab=categories" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= ($tab==='categories') ? 'bg-blue-600 text-white shadow' : 'bg-white border hover:bg-gray-50 text-gray-600' ?>">
        🏷️ Categories
    </a>
    <a href="<?= url('blog') ?>" target="_blank" class="ml-auto px-3 py-2 rounded-lg text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 transition">
        🌐 View Blog Page →
    </a>
</div>

<?php if ($isNew): ?>
<!-- ═══════════════════════════════════════════════════ -->
<!-- POST EDITOR -->
<!-- ═══════════════════════════════════════════════════ -->
<form method="POST" enctype="multipart/form-data" id="blogForm">
<input type="hidden" name="action" value="<?= $editPost ? 'update' : 'save' ?>">
<?php if ($editPost): ?><input type="hidden" name="post_id" value="<?= intval($editPost['id']) ?>"><?php endif; ?>
<?php if ($editPost && !empty($editPost['featured_image'])): ?><input type="hidden" name="existing_image" value="<?= e($editPost['featured_image']) ?>"><?php endif; ?>

<div class="grid lg:grid-cols-4 gap-5">
    <!-- Main Content (3 cols) -->
    <div class="lg:col-span-3 space-y-5">

        <!-- Title -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <div class="grid md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Post Title (English) <span class="text-red-500">*</span></label>
                    <input type="text" name="title" value="<?= e($editPost['title'] ?? '') ?>" required
                           class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-base font-semibold focus:border-blue-500 focus:ring-0 transition"
                           placeholder="Enter blog post title..." oninput="generateSlug(this.value)">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">শিরোনাম (বাংলা)</label>
                    <input type="text" name="title_bn" value="<?= e($editPost['title_bn'] ?? '') ?>"
                           class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-base focus:border-blue-500 focus:ring-0 transition"
                           placeholder="ব্লগ পোস্টের শিরোনাম...">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">URL Slug</label>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-400 shrink-0"><?= SITE_URL ?>/blog/</span>
                    <input type="text" name="slug" id="slugField" value="<?= e($editPost['slug'] ?? '') ?>"
                           class="flex-1 px-3 py-2 border rounded-lg text-sm font-mono focus:border-blue-500 focus:ring-0">
                </div>
            </div>
        </div>

        <!-- Excerpt -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <label class="block text-xs font-semibold text-gray-600 mb-2">Excerpt / Summary</label>
            <div class="grid md:grid-cols-2 gap-4">
                <textarea name="excerpt" rows="3" class="w-full px-3 py-2.5 border rounded-xl text-sm focus:border-blue-500 focus:ring-0" placeholder="Short summary (English)..."><?= e($editPost['excerpt'] ?? '') ?></textarea>
                <textarea name="excerpt_bn" rows="3" class="w-full px-3 py-2.5 border rounded-xl text-sm focus:border-blue-500 focus:ring-0" placeholder="সারাংশ (বাংলা)..."><?= e($editPost['excerpt_bn'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Rich Text Editor -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <label class="text-xs font-semibold text-gray-600">Post Content <span class="text-red-500">*</span></label>
                <div class="flex gap-1">
                    <button type="button" onclick="switchLang('en')" id="langEn" class="px-3 py-1 rounded text-xs font-semibold bg-blue-600 text-white transition">English</button>
                    <button type="button" onclick="switchLang('bn')" id="langBn" class="px-3 py-1 rounded text-xs font-semibold bg-gray-200 text-gray-600 transition">বাংলা</button>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="flex flex-wrap gap-1 mb-2 p-2 bg-gray-50 rounded-lg border" id="editorToolbar">
                <button type="button" onclick="execCmd('bold')" class="px-2.5 py-1.5 rounded hover:bg-gray-200 text-sm font-bold" title="Bold">B</button>
                <button type="button" onclick="execCmd('italic')" class="px-2.5 py-1.5 rounded hover:bg-gray-200 text-sm italic" title="Italic">I</button>
                <button type="button" onclick="execCmd('underline')" class="px-2.5 py-1.5 rounded hover:bg-gray-200 text-sm underline" title="Underline">U</button>
                <span class="w-px h-7 bg-gray-300 mx-1"></span>
                <button type="button" onclick="execCmd('formatBlock','<h2>')" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs font-bold">H2</button>
                <button type="button" onclick="execCmd('formatBlock','<h3>')" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs font-bold">H3</button>
                <button type="button" onclick="execCmd('formatBlock','<h4>')" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs font-bold">H4</button>
                <button type="button" onclick="execCmd('formatBlock','<p>')" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs">P</button>
                <button type="button" onclick="execCmd('formatBlock','<blockquote>')" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs">❝ Quote</button>
                <span class="w-px h-7 bg-gray-300 mx-1"></span>
                <button type="button" onclick="execCmd('insertUnorderedList')" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs">• List</button>
                <button type="button" onclick="execCmd('insertOrderedList')" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs">1. List</button>
                <span class="w-px h-7 bg-gray-300 mx-1"></span>
                <button type="button" onclick="insertLink()" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs font-medium" title="Insert Link">🔗 Link</button>
                <button type="button" onclick="openMediaPicker()" class="px-2.5 py-1.5 rounded hover:bg-blue-100 text-xs bg-blue-50 text-blue-700 font-semibold" title="Insert Image from Media Gallery">🖼️ Gallery Image</button>
                <button type="button" onclick="insertYT()" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs" title="Embed YouTube Video">▶ YouTube</button>
                <span class="w-px h-7 bg-gray-300 mx-1"></span>
                <button type="button" onclick="execCmd('justifyLeft')" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs">⬅</button>
                <button type="button" onclick="execCmd('justifyCenter')" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs">⬌</button>
                <button type="button" onclick="execCmd('justifyRight')" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs">➡</button>
                <span class="w-px h-7 bg-gray-300 mx-1"></span>
                <button type="button" onclick="deleteSelectedBlock()" class="px-2.5 py-1.5 rounded hover:bg-red-100 text-xs text-red-600 font-semibold border border-red-200" title="Delete selected image, video, or embed">🗑 Delete Block</button>
                <button type="button" onclick="toggleSource()" class="px-2 py-1.5 rounded hover:bg-gray-200 text-xs ml-auto text-gray-500">&lt;/&gt; HTML</button>
            </div>

            <!-- Editors -->
            <div id="editorEn" contenteditable="true"
                 class="blog-editor min-h-[420px] w-full px-5 py-4 border-2 border-gray-200 rounded-xl text-base leading-relaxed focus:border-blue-500 focus:outline-none overflow-auto"
                 style="font-family:'Hind Siliguri',sans-serif"><?= $editPost ? ($editPost['content'] ?? '') : '<p>Start writing your post...</p>' ?></div>
            <div id="editorBn" contenteditable="true"
                 class="blog-editor min-h-[420px] w-full px-5 py-4 border-2 border-gray-200 rounded-xl text-base leading-relaxed focus:border-blue-500 focus:outline-none overflow-auto hidden"
                 style="font-family:'Hind Siliguri',sans-serif"><?= $editPost ? ($editPost['content_bn'] ?? '') : '<p>এখানে বাংলায় লিখুন...</p>' ?></div>
            <textarea name="content" id="contentHidden" class="hidden"><?= e($editPost['content'] ?? '') ?></textarea>
            <textarea name="content_bn" id="contentBnHidden" class="hidden"><?= e($editPost['content_bn'] ?? '') ?></textarea>
            <textarea id="sourceEditor" class="hidden w-full min-h-[420px] px-4 py-3 border-2 border-gray-200 rounded-xl font-mono text-sm focus:border-blue-500 focus:ring-0 mt-2"></textarea>
            <p class="text-[10px] text-gray-400 mt-2">💡 Click on any image/video/embed, then press <b class="text-red-500">🗑 Delete Block</b> to remove it.</p>
        </div>
    </div>

    <!-- Sidebar (1 col) -->
    <div class="space-y-5">

        <!-- Publish -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="text-sm font-bold text-gray-800 mb-3">📢 Publish</h4>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border rounded-lg text-sm">
                        <?php $curStatus = $editPost['status'] ?? 'draft'; ?>
                        <option value="draft" <?= $curStatus === 'draft' ? 'selected' : '' ?>>📝 Draft</option>
                        <option value="published" <?= $curStatus === 'published' ? 'selected' : '' ?>>✅ Published</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Publish Date</label>
                    <?php
                    $pubDateVal = '';
                    if ($editPost) {
                        $ts = !empty($editPost['published_at']) ? $editPost['published_at'] : ($editPost['created_at'] ?? '');
                        if ($ts) $pubDateVal = date('Y-m-d\TH:i', strtotime($ts));
                    }
                    ?>
                    <input type="datetime-local" name="published_at" value="<?= $pubDateVal ?>"
                           class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow">
                        💾 <?= $editPost ? 'Update Post' : 'Save Post' ?>
                    </button>
                    <?php if ($editPost && !empty($editPost['slug'])): ?>
                    <a href="<?= url('blog/' . $editPost['slug']) ?>" target="_blank" class="px-3 py-2.5 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200 transition" title="Preview">👁</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Template Selection -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🎨 Template</h4>
            <div class="grid grid-cols-2 gap-2">
                <?php
                $tplOptions = [
                    'classic'  => ['Classic',  '📰', 'Centered hero, clean body'],
                    'magazine' => ['Magazine', '📖', 'Full-width hero overlay'],
                    'minimal'  => ['Minimal',  '✏️', 'Text-focused, serif style'],
                    'modern'   => ['Modern',   '🎯', 'Card layout with TOC'],
                    'recipe'   => ['Recipe',   '🍳', 'Structured recipe layout'],
                ];
                foreach ($tplOptions as $tplKey => $tplInfo): ?>
                <label class="cursor-pointer">
                    <input type="radio" name="template" value="<?= $tplKey ?>" class="hidden peer" <?= ($currentTpl === $tplKey) ? 'checked' : '' ?>>
                    <div class="p-3 border-2 rounded-xl text-center transition peer-checked:border-blue-500 peer-checked:bg-blue-50 hover:border-gray-300">
                        <div class="text-2xl mb-1"><?= $tplInfo[1] ?></div>
                        <div class="text-xs font-bold text-gray-700"><?= $tplInfo[0] ?></div>
                        <div class="text-[10px] text-gray-400 mt-0.5"><?= $tplInfo[2] ?></div>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Featured Image (from Media Gallery) -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🖼️ Featured Image</h4>
            <div id="featuredImgBox" class="mb-3 relative group rounded-lg overflow-hidden border-2 border-dashed border-gray-200 <?= ($editPost && !empty($editPost['featured_image'])) ? '' : 'hidden' ?>" style="min-height:120px">
                <img id="featuredImgPreview" src="<?= ($editPost && !empty($editPost['featured_image'])) ? uploadUrl($editPost['featured_image']) : '' ?>" alt="" class="w-full h-36 object-cover">
                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2">
                    <button type="button" onclick="pickFeaturedImage()" class="text-white text-xs font-bold bg-blue-600 px-3 py-1.5 rounded-lg shadow">🔄 Change</button>
                    <button type="button" onclick="removeFeaturedImage()" class="text-white text-xs font-bold bg-red-500 px-3 py-1.5 rounded-lg shadow">✕ Remove</button>
                </div>
            </div>
            <input type="hidden" name="existing_image" id="featuredImgInput" value="<?= e($editPost['featured_image'] ?? '') ?>">
            <button type="button" onclick="pickFeaturedImage()" id="featuredImgBtn" class="w-full py-3 border-2 border-dashed border-gray-300 rounded-lg text-sm text-gray-500 hover:border-blue-400 hover:text-blue-600 transition <?= ($editPost && !empty($editPost['featured_image'])) ? 'hidden' : '' ?>">
                📁 Choose from Media Gallery
            </button>
            <p class="text-[10px] text-gray-400 mt-1">Recommended: 1200×630px</p>
        </div>

        <!-- Recipe Fields (shown when Recipe template selected) -->
        <?php
        $recipeData = ['prep_time'=>'','cook_time'=>'','servings'=>'','difficulty'=>'Easy','calories'=>'','ingredients'=>[],'steps'=>[]];
        if ($editPost && !empty($editPost['recipe_data'])) {
            $rd = json_decode($editPost['recipe_data'], true);
            if (is_array($rd)) $recipeData = array_merge($recipeData, $rd);
        }
        ?>
        <div id="recipeFieldsPanel" class="bg-white rounded-xl border shadow-sm p-5 <?= $currentTpl === 'recipe' ? '' : 'hidden' ?>" style="border-color:#f59e0b">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🍳 Recipe Details</h4>
            <div class="space-y-3">
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="block text-[10px] font-semibold text-gray-500 mb-1">Prep Time</label>
                        <input type="text" name="recipe_prep_time" value="<?= e($recipeData['prep_time']) ?>" class="w-full px-2.5 py-1.5 border rounded-lg text-xs" placeholder="15 min"></div>
                    <div><label class="block text-[10px] font-semibold text-gray-500 mb-1">Cook Time</label>
                        <input type="text" name="recipe_cook_time" value="<?= e($recipeData['cook_time']) ?>" class="w-full px-2.5 py-1.5 border rounded-lg text-xs" placeholder="30 min"></div>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div><label class="block text-[10px] font-semibold text-gray-500 mb-1">Servings</label>
                        <input type="text" name="recipe_servings" value="<?= e($recipeData['servings']) ?>" class="w-full px-2.5 py-1.5 border rounded-lg text-xs" placeholder="4"></div>
                    <div><label class="block text-[10px] font-semibold text-gray-500 mb-1">Difficulty</label>
                        <select name="recipe_difficulty" class="w-full px-2.5 py-1.5 border rounded-lg text-xs">
                            <?php foreach (['Easy','Medium','Hard'] as $d): ?>
                            <option value="<?= $d ?>" <?= ($recipeData['difficulty'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div><label class="block text-[10px] font-semibold text-gray-500 mb-1">Calories</label>
                        <input type="text" name="recipe_calories" value="<?= e($recipeData['calories']) ?>" class="w-full px-2.5 py-1.5 border rounded-lg text-xs" placeholder="350 kcal"></div>
                </div>

                <!-- Ingredients: name + amount -->
                <div>
                    <label class="block text-[10px] font-semibold text-gray-500 mb-1">Ingredients</label>
                    <div class="text-[9px] text-gray-400 mb-1 flex gap-2"><span class="flex-1 pl-1">Ingredient Name</span><span style="width:80px">Amount</span></div>
                    <div id="ingredientsList" class="space-y-1">
                        <?php if (!empty($recipeData['ingredients'])):
                            foreach ($recipeData['ingredients'] as $ing):
                                $ingName = is_array($ing) ? ($ing['name'] ?? '') : $ing;
                                $ingAmt  = is_array($ing) ? ($ing['amount'] ?? '') : '';
                        ?>
                        <div class="flex gap-1 items-center">
                            <input type="text" name="recipe_ing_name[]" value="<?= e($ingName) ?>" class="flex-1 px-2.5 py-1.5 border rounded-lg text-xs" placeholder="Ingredient">
                            <input type="text" name="recipe_ing_amount[]" value="<?= e($ingAmt) ?>" class="px-2.5 py-1.5 border rounded-lg text-xs" style="width:80px" placeholder="Amount">
                            <button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-600 text-xs px-1">✕</button>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="flex gap-1 items-center">
                            <input type="text" name="recipe_ing_name[]" value="" class="flex-1 px-2.5 py-1.5 border rounded-lg text-xs" placeholder="e.g. Vegetable Oil">
                            <input type="text" name="recipe_ing_amount[]" value="" class="px-2.5 py-1.5 border rounded-lg text-xs" style="width:80px" placeholder="2 tbsp">
                            <button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-600 text-xs px-1">✕</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" onclick="addIngredientRow()" class="mt-1 text-[10px] text-blue-600 font-semibold hover:underline">+ Add ingredient</button>
                </div>

                <!-- Steps: title + description + image -->
                <div>
                    <label class="block text-[10px] font-semibold text-gray-500 mb-2">Steps <span class="text-gray-400">(each step can have an image)</span></label>
                    <div id="stepsList" class="space-y-3">
                        <?php if (!empty($recipeData['steps'])):
                            foreach ($recipeData['steps'] as $si => $step):
                                $sTitle = is_array($step) ? ($step['title'] ?? '') : $step;
                                $sDesc  = is_array($step) ? ($step['description'] ?? '') : '';
                                $sImg   = is_array($step) ? ($step['image'] ?? '') : '';
                        ?>
                        <div class="bg-gray-50 border rounded-lg p-3 space-y-2 recipe-step-block">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold text-gray-400">Step <?= $si+1 ?></span>
                                <input type="text" name="recipe_step_title[]" value="<?= e($sTitle) ?>" class="flex-1 px-2.5 py-1.5 border rounded-lg text-xs font-semibold" placeholder="Step title">
                                <button type="button" onclick="this.closest('.recipe-step-block').remove();renumberSteps()" class="text-red-400 hover:text-red-600 text-xs">✕</button>
                            </div>
                            <textarea name="recipe_step_desc[]" rows="2" class="w-full px-2.5 py-1.5 border rounded-lg text-xs" placeholder="Describe this step..."><?= e($sDesc) ?></textarea>
                            <div class="flex items-center gap-2">
                                <input type="hidden" name="recipe_step_img[]" value="<?= e($sImg) ?>" class="step-img-input">
                                <?php if ($sImg): ?>
                                <img src="<?= uploadUrl($sImg) ?>" class="w-16 h-12 object-cover rounded border step-img-preview">
                                <?php else: ?>
                                <img src="" class="w-16 h-12 object-cover rounded border step-img-preview hidden">
                                <?php endif; ?>
                                <button type="button" onclick="pickStepImage(this)" class="text-[10px] text-blue-600 font-semibold hover:underline">📁 Choose Image</button>
                                <?php if ($sImg): ?>
                                <button type="button" onclick="removeStepImage(this)" class="text-[10px] text-red-500 font-semibold hover:underline">✕ Remove</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="bg-gray-50 border rounded-lg p-3 space-y-2 recipe-step-block">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold text-gray-400">Step 1</span>
                                <input type="text" name="recipe_step_title[]" value="" class="flex-1 px-2.5 py-1.5 border rounded-lg text-xs font-semibold" placeholder="Step title">
                                <button type="button" onclick="this.closest('.recipe-step-block').remove();renumberSteps()" class="text-red-400 hover:text-red-600 text-xs">✕</button>
                            </div>
                            <textarea name="recipe_step_desc[]" rows="2" class="w-full px-2.5 py-1.5 border rounded-lg text-xs" placeholder="Describe this step..."></textarea>
                            <div class="flex items-center gap-2">
                                <input type="hidden" name="recipe_step_img[]" value="" class="step-img-input">
                                <img src="" class="w-16 h-12 object-cover rounded border step-img-preview hidden">
                                <button type="button" onclick="pickStepImage(this)" class="text-[10px] text-blue-600 font-semibold hover:underline">📁 Choose Image</button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" onclick="addStepBlock()" class="mt-2 text-[10px] text-blue-600 font-semibold hover:underline">+ Add step</button>
                </div>
            </div>
        </div>

        <!-- Category & Tags -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🏷️ Organize</h4>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Category</label>
                    <select name="category" class="w-full px-3 py-2 border rounded-lg text-sm">
                        <option value="">— Select —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['name']) ?>" <?= (($editPost['category'] ?? '') === $cat['name']) ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?><?= !empty($cat['name_bn']) ? ' ('.e($cat['name_bn']).')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Tags <span class="text-gray-400">(comma-separated)</span></label>
                    <input type="text" name="tags" value="<?= e($editPost['tags'] ?? '') ?>"
                           class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="tag1, tag2, tag3">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Author Name</label>
                    <input type="text" name="author_name" value="<?= e($editPost['author_name'] ?? (function_exists('getAdminName') ? (getAdminName() ?: 'Admin') : 'Admin')) ?>"
                           class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
            </div>
        </div>

        <!-- SEO -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🔍 SEO</h4>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Meta Title</label>
                    <input type="text" name="meta_title" value="<?= e($editPost['meta_title'] ?? '') ?>"
                           class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="SEO title">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Meta Description</label>
                    <textarea name="meta_description" rows="3" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="SEO description..."><?= e($editPost['meta_description'] ?? '') ?></textarea>
                </div>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 text-xs cursor-pointer">
                        <input type="checkbox" name="is_featured" <?= !empty($editPost['is_featured']) ? 'checked' : '' ?> class="rounded text-blue-600"> ⭐ Featured
                    </label>
                    <label class="flex items-center gap-2 text-xs cursor-pointer">
                        <input type="checkbox" name="allow_comments" <?= (!$editPost || !empty($editPost['allow_comments'])) ? 'checked' : '' ?> class="rounded text-blue-600"> 💬 Comments
                    </label>
                </div>
            </div>
        </div>

    </div>
</div>
</form>

<!-- ═══════════════════════════════════════════════════ -->
<!-- MEDIA GALLERY PICKER MODAL -->
<!-- ═══════════════════════════════════════════════════ -->
<div id="mediaModal" class="fixed inset-0 z-[9999] hidden" style="display:none">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeMediaPicker()"></div>
    <div class="absolute inset-4 md:inset-8 bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden" style="z-index:10000">
        <div class="flex items-center justify-between px-5 py-3 border-b bg-gray-50">
            <h3 class="font-bold text-gray-800 text-sm">🖼️ Insert Image from Media Gallery</h3>
            <div class="flex items-center gap-3">
                <select id="mediaFolder" onchange="loadMediaImages()" class="px-3 py-1.5 border rounded-lg text-xs">
                    <option value="all">All Folders</option>
                    <option value="products">Products</option>
                    <option value="banners">Banners</option>
                    <option value="general">General</option>
                    <option value="logos">Logos</option>
                    <option value="categories">Categories</option>
                </select>
                <button type="button" onclick="closeMediaPicker()" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-200 text-gray-500 text-lg">&times;</button>
            </div>
        </div>
        <div id="mediaGrid" class="flex-1 overflow-y-auto p-4 grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 gap-3 content-start">
            <div class="col-span-full text-center py-16 text-gray-400">Click 🖼️ Gallery Image to load...</div>
        </div>
        <div class="px-5 py-3 border-t bg-gray-50 flex items-center justify-between">
            <span class="text-xs text-gray-400" id="mediaCount"></span>
            <button type="button" onclick="closeMediaPicker()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-300">Cancel</button>
        </div>
    </div>
</div>

<style>
.blog-editor img { cursor: pointer; border: 2px solid transparent; border-radius: 8px; transition: border-color 0.2s, outline 0.2s; }
.blog-editor img.selected-block { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.3); }
.blog-editor .yt-embed { cursor: pointer; transition: outline 0.2s; }
.blog-editor .yt-embed.selected-block { outline: 3px solid #ef4444 !important; outline-offset: 2px !important; }
.blog-editor .yt-overlay { background: transparent; }
.blog-editor .yt-overlay:hover { background: rgba(239,68,68,0.08); }
</style>

<script>
// ── Slug generator ──
function generateSlug(t){
    document.getElementById('slugField').value = t.toLowerCase()
        .replace(/[^a-z0-9\s-]/g,'').replace(/[\s]+/g,'-').replace(/-+/g,'-').replace(/^-|-$/g,'');
}

// ── Recipe template toggle ──
function addIngredientRow(){
    var list = document.getElementById('ingredientsList');
    var div = document.createElement('div');
    div.className = 'flex gap-1 items-center';
    div.innerHTML = '<input type="text" name="recipe_ing_name[]" value="" class="flex-1 px-2.5 py-1.5 border rounded-lg text-xs" placeholder="Ingredient"><input type="text" name="recipe_ing_amount[]" value="" class="px-2.5 py-1.5 border rounded-lg text-xs" style="width:80px" placeholder="Amount"><button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-600 text-xs px-1">✕</button>';
    list.appendChild(div);
    div.querySelector('input').focus();
}
function addStepBlock(){
    var list = document.getElementById('stepsList');
    var num = list.querySelectorAll('.recipe-step-block').length + 1;
    var div = document.createElement('div');
    div.className = 'bg-gray-50 border rounded-lg p-3 space-y-2 recipe-step-block';
    div.innerHTML = '<div class="flex items-center gap-2"><span class="text-xs font-bold text-gray-400">Step '+num+'</span><input type="text" name="recipe_step_title[]" value="" class="flex-1 px-2.5 py-1.5 border rounded-lg text-xs font-semibold" placeholder="Step title"><button type="button" onclick="this.closest(\'.recipe-step-block\').remove();renumberSteps()" class="text-red-400 hover:text-red-600 text-xs">✕</button></div><textarea name="recipe_step_desc[]" rows="2" class="w-full px-2.5 py-1.5 border rounded-lg text-xs" placeholder="Describe this step..."></textarea><div class="flex items-center gap-2"><input type="hidden" name="recipe_step_img[]" value="" class="step-img-input"><img src="" class="w-16 h-12 object-cover rounded border step-img-preview hidden"><button type="button" onclick="pickStepImage(this)" class="text-[10px] text-blue-600 font-semibold hover:underline">📁 Choose Image</button></div>';
    list.appendChild(div);
    div.querySelector('input[name="recipe_step_title[]"]').focus();
}
function renumberSteps(){
    document.querySelectorAll('#stepsList .recipe-step-block').forEach(function(b,i){
        var lbl = b.querySelector('span');
        if(lbl) lbl.textContent = 'Step '+(i+1);
    });
}
// ── Featured Image from Gallery ──
var _mediaPickerCallback = null;
function pickFeaturedImage(){
    _mediaPickerCallback = function(url, path){
        document.getElementById('featuredImgPreview').src = url;
        document.getElementById('featuredImgBox').classList.remove('hidden');
        document.getElementById('featuredImgBtn').classList.add('hidden');
        document.getElementById('featuredImgInput').value = path || url;
    };
    openMediaPicker();
}
function removeFeaturedImage(){
    document.getElementById('featuredImgPreview').src = '';
    document.getElementById('featuredImgBox').classList.add('hidden');
    document.getElementById('featuredImgBtn').classList.remove('hidden');
    document.getElementById('featuredImgInput').value = '';
}
function pickStepImage(btn){
    var block = btn.closest('.recipe-step-block') || btn.closest('.flex');
    var input = block.querySelector('.step-img-input') || block.parentElement.querySelector('.step-img-input');
    var preview = block.querySelector('.step-img-preview') || block.parentElement.querySelector('.step-img-preview');
    _mediaPickerCallback = function(url, path){
        if(preview){ preview.src = url; preview.classList.remove('hidden'); }
        if(input) input.value = path || url;
        // Add remove button if not present
        if(!block.querySelector('.step-img-remove')){
            var rb = document.createElement('button');
            rb.type='button'; rb.className='text-[10px] text-red-500 font-semibold hover:underline step-img-remove';
            rb.textContent='✕ Remove'; rb.onclick=function(){ removeStepImage(this); };
            btn.after(rb);
        }
    };
    openMediaPicker();
}
function removeStepImage(btn){
    var block = btn.closest('.recipe-step-block') || btn.closest('.flex');
    var input = block.querySelector('.step-img-input');
    var preview = block.querySelector('.step-img-preview');
    if(input) input.value = '';
    if(preview){ preview.src=''; preview.classList.add('hidden'); }
    btn.remove();
}
// Toggle recipe panel on template change
document.addEventListener('DOMContentLoaded', function(){
    var radios = document.querySelectorAll('input[name="template"]');
    var panel = document.getElementById('recipeFieldsPanel');
    if(radios.length && panel){
        radios.forEach(function(r){ r.addEventListener('change', function(){
            panel.classList.toggle('hidden', this.value !== 'recipe');
        }); });
    }
});

// ── Basic execCommand ──
function execCmd(c,v){ document.execCommand(c, false, v || null); }

// ── FIXED: Link Insertion using insertHTML ──
function insertLink(){
    var sel = window.getSelection();
    var selectedText = sel.toString().trim();
    var url = prompt('Enter URL:', 'https://');
    if(!url) return;
    var linkText = selectedText || prompt('Link text (leave empty to use URL):', '') || url;
    // Sanitize
    url = url.replace(/"/g, '&quot;');
    linkText = linkText.replace(/</g, '&lt;').replace(/>/g, '&gt;');
    var html = '<a href="' + url + '" target="_blank" style="color:#2563eb;text-decoration:underline">' + linkText + '</a>&nbsp;';
    getActiveEditor().focus();
    document.execCommand('insertHTML', false, html);
}

// ── MEDIA GALLERY: Open/Close ──
function openMediaPicker(){
    var modal = document.getElementById('mediaModal');
    modal.classList.remove('hidden');
    modal.style.display = '';
    loadMediaImages();
}
function closeMediaPicker(){
    var modal = document.getElementById('mediaModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    _mediaPickerCallback = null;
}

// ── MEDIA GALLERY: Load images from server ──
function loadMediaImages(){
    var folder = document.getElementById('mediaFolder').value;
    var grid = document.getElementById('mediaGrid');
    grid.innerHTML = '<div class="col-span-full text-center py-16 text-gray-400"><svg class="animate-spin h-8 w-8 mx-auto mb-2 text-blue-500" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Loading images...</div>';

    fetch('<?= adminUrl("pages/media.php") ?>?api=list&folder=' + folder)
    .then(function(r){ return r.json(); })
    .then(function(data){
        if(!data.success || !data.files || data.files.length === 0){
            grid.innerHTML = '<div class="col-span-full text-center py-16 text-gray-400">No images found in this folder.</div>';
            document.getElementById('mediaCount').textContent = '0 images';
            return;
        }
        document.getElementById('mediaCount').textContent = data.files.length + ' images';
        grid.innerHTML = '';
        data.files.forEach(function(file){
            var div = document.createElement('div');
            div.className = 'relative group cursor-pointer rounded-lg overflow-hidden border-2 border-transparent hover:border-blue-500 transition bg-gray-100';
            div.style.aspectRatio = '1';
            div.setAttribute('data-url', file.url);
            div.onclick = function(){
                if(typeof _mediaPickerCallback === 'function'){
                    _mediaPickerCallback(file.url, file.path || file.url);
                    _mediaPickerCallback = null;
                } else {
                    insertMediaImage(file.url);
                }
                closeMediaPicker();
            };
            div.innerHTML = '<img src="' + file.url + '" alt="' + (file.name || '') + '" class="w-full h-full object-cover" loading="lazy">'
                + '<div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center"><span class="text-white text-xs font-bold bg-blue-600 px-3 py-1.5 rounded-lg shadow">✓ Insert</span></div>'
                + '<div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent px-2 py-1 opacity-0 group-hover:opacity-100 transition"><p class="text-white text-[9px] truncate">' + (file.name || '') + '</p></div>';
            grid.appendChild(div);
        });
    })
    .catch(function(err){
        grid.innerHTML = '<div class="col-span-full text-center py-16 text-red-400">Error: ' + err.message + '</div>';
    });
}

// ── Insert image from gallery into editor ──
function insertMediaImage(url){
    var editor = getActiveEditor();
    editor.focus();
    var html = '<div class="yt-embed" contenteditable="false" style="margin:16px 0;text-align:center">'
        + '<img src="' + url + '" style="max-width:100%;border-radius:8px;display:inline-block" />'
        + '</div><p><br></p>';
    document.execCommand('insertHTML', false, html);
}

// ── YouTube embed ──
function insertYT(){
    var u = prompt('YouTube video URL:', 'https://www.youtube.com/watch?v=');
    if(!u) return;
    var vid = u.match(/[?&]v=([^&]+)/) || u.match(/youtu\.be\/([^?]+)/);
    if(!vid){ alert('Invalid YouTube URL. Use format: youtube.com/watch?v=ID'); return; }
    var editor = getActiveEditor();
    editor.focus();
    var html = '<div class="yt-embed" contenteditable="false" style="position:relative;padding-bottom:56.25%;height:0;margin:16px 0;border-radius:8px;overflow:hidden;border:2px solid transparent">'
        + '<iframe src="https://www.youtube.com/embed/' + vid[1] + '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none;pointer-events:none" allowfullscreen></iframe>'
        + '<div class="yt-overlay" style="position:absolute;top:0;left:0;width:100%;height:100%;cursor:pointer;z-index:5"></div>'
        + '</div><p><br></p>';
    document.execCommand('insertHTML', false, html);
}

// ── DELETE SELECTED BLOCK ──
var selectedBlockEl = null;

function deleteSelectedBlock(){
    if(selectedBlockEl){
        selectedBlockEl.remove();
        selectedBlockEl = null;
        return;
    }
    alert('First click on an image or video inside the editor to select it (it will highlight), then press 🗑 Delete Block.');
}

function selectBlock(el){
    // Clear all previous
    document.querySelectorAll('.blog-editor .selected-block').forEach(function(s){ s.classList.remove('selected-block'); s.style.outline=''; s.style.outlineOffset=''; });
    selectedBlockEl = el;
    el.classList.add('selected-block');
    el.style.outline = '3px solid #ef4444';
    el.style.outlineOffset = '2px';
}

// Click handler: select images/videos/embeds inside editor
document.addEventListener('DOMContentLoaded', function(){
    // Add overlays to any existing YouTube embeds (edit mode)
    document.querySelectorAll('.blog-editor .yt-embed').forEach(function(embed){
        if(!embed.querySelector('.yt-overlay')){
            var overlay = document.createElement('div');
            overlay.className = 'yt-overlay';
            overlay.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;cursor:pointer;z-index:5';
            embed.style.position = 'relative';
            embed.appendChild(overlay);
        }
        // Disable pointer events on iframes
        var iframe = embed.querySelector('iframe');
        if(iframe) iframe.style.pointerEvents = 'none';
    });

    document.querySelectorAll('.blog-editor').forEach(function(editor){
        editor.addEventListener('click', function(e){
            var target = e.target;

            // Click on YouTube overlay
            if(target.classList.contains('yt-overlay')){
                var embed = target.closest('.yt-embed');
                if(embed){ selectBlock(embed); e.preventDefault(); return; }
            }

            // Direct click on img
            if(target.tagName === 'IMG'){
                var wrapper = target.closest('.yt-embed') || target.closest('div[contenteditable="false"]');
                selectBlock(wrapper || target);
                e.preventDefault();
                return;
            }

            // Click on any embed wrapper
            var embed = target.closest('.yt-embed') || target.closest('div[contenteditable="false"]');
            if(embed){
                selectBlock(embed);
                e.preventDefault();
                return;
            }

            // Clicked elsewhere — deselect
            document.querySelectorAll('.blog-editor .selected-block').forEach(function(s){ s.classList.remove('selected-block'); s.style.outline=''; s.style.outlineOffset=''; });
            selectedBlockEl = null;
        });
    });
});

// ── Get active editor ──
function getActiveEditor(){
    return curLang === 'bn' ? document.getElementById('editorBn') : document.getElementById('editorEn');
}

// ── Language switch ──
var curLang = 'en';
function switchLang(l){
    curLang = l;
    document.getElementById('editorEn').classList.toggle('hidden', l !== 'en');
    document.getElementById('editorBn').classList.toggle('hidden', l !== 'bn');
    document.getElementById('langEn').className = 'px-3 py-1 rounded text-xs font-semibold transition ' + (l==='en' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-600');
    document.getElementById('langBn').className = 'px-3 py-1 rounded text-xs font-semibold transition ' + (l==='bn' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-600');
}

// ── Source toggle ──
var srcMode = false;
function toggleSource(){
    var ed = getActiveEditor();
    var src = document.getElementById('sourceEditor');
    srcMode = !srcMode;
    if(srcMode){ src.value = ed.innerHTML; ed.classList.add('hidden'); src.classList.remove('hidden'); }
    else { ed.innerHTML = src.value; src.classList.add('hidden'); ed.classList.remove('hidden'); }
}

// ── Sync before submit ──
document.getElementById('blogForm').addEventListener('submit', function(){
    if(srcMode){
        var ed = getActiveEditor();
        ed.innerHTML = document.getElementById('sourceEditor').value;
        srcMode = false;
    }
    // Strip editor-only overlays and styles before saving
    ['editorEn','editorBn'].forEach(function(id){
        var ed = document.getElementById(id);
        ed.querySelectorAll('.yt-overlay').forEach(function(o){ o.remove(); });
        ed.querySelectorAll('.selected-block').forEach(function(s){ s.classList.remove('selected-block'); s.style.outline=''; s.style.outlineOffset=''; });
        ed.querySelectorAll('iframe').forEach(function(f){ f.style.pointerEvents=''; });
    });
    document.getElementById('contentHidden').value = document.getElementById('editorEn').innerHTML;
    document.getElementById('contentBnHidden').value = document.getElementById('editorBn').innerHTML;
});
</script>

<?php elseif ($tab === 'categories'): ?>
<!-- ═══════════════════════════════════════════════════ -->
<!-- CATEGORIES TAB -->
<!-- ═══════════════════════════════════════════════════ -->
<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b"><tr>
                    <th class="text-left px-5 py-3 font-semibold text-gray-600">Category</th>
                    <th class="text-left px-5 py-3 font-semibold text-gray-600">বাংলা</th>
                    <th class="text-left px-5 py-3 font-semibold text-gray-600">Slug</th>
                    <th class="text-right px-5 py-3 font-semibold text-gray-600">Actions</th>
                </tr></thead>
                <tbody class="divide-y">
                    <?php foreach ($categories as $cat): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3 font-medium"><?= e($cat['name'] ?? '') ?></td>
                        <td class="px-5 py-3 text-gray-500"><?= e($cat['name_bn'] ?? '—') ?></td>
                        <td class="px-5 py-3 font-mono text-xs text-gray-400"><?= e($cat['slug'] ?? '') ?></td>
                        <td class="px-5 py-3 text-right">
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this category?')">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="cat_id" value="<?= intval($cat['id']) ?>">
                                <button class="text-red-500 hover:text-red-700 text-xs font-medium">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($categories)): ?>
                    <tr><td colspan="4" class="px-5 py-10 text-center text-gray-400">No categories yet</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div>
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-4">Add Category</h4>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="save_category">
                <input type="text" name="cat_name" required class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="Category name (English)">
                <input type="text" name="cat_name_bn" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="ক্যাটাগরি নাম (বাংলা)">
                <button class="w-full bg-blue-600 text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition">Add Category</button>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════ -->
<!-- ALL POSTS LIST -->
<!-- ═══════════════════════════════════════════════════ -->
<div class="grid grid-cols-3 gap-4 mb-5">
    <a href="?tab=posts" class="bg-white rounded-xl border p-4 text-center hover:shadow transition <?= !$statusFilter ? 'ring-2 ring-blue-400' : '' ?>">
        <div class="text-2xl font-bold text-gray-800"><?= $blogStats['total'] ?></div>
        <div class="text-xs text-gray-500">Total Posts</div>
    </a>
    <a href="?status=published" class="bg-white rounded-xl border p-4 text-center hover:shadow transition <?= $statusFilter === 'published' ? 'ring-2 ring-green-400' : '' ?>">
        <div class="text-2xl font-bold text-green-600"><?= $blogStats['published'] ?></div>
        <div class="text-xs text-gray-500">Published</div>
    </a>
    <a href="?status=draft" class="bg-white rounded-xl border p-4 text-center hover:shadow transition <?= $statusFilter === 'draft' ? 'ring-2 ring-yellow-400' : '' ?>">
        <div class="text-2xl font-bold text-yellow-600"><?= $blogStats['draft'] ?></div>
        <div class="text-xs text-gray-500">Drafts</div>
    </a>
</div>

<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($posts as $p):
        $pImg = !empty($p['featured_image']) ? uploadUrl($p['featured_image']) : '';
        $pPub = (($p['status'] ?? '') === 'published');
        $pTplIcons = ['classic'=>'📰','magazine'=>'📖','minimal'=>'✏️','modern'=>'🎯','recipe'=>'🍳'];
        $pTpl = $p['template'] ?? 'classic';
    ?>
    <div class="bg-white rounded-xl border shadow-sm overflow-hidden hover:shadow-md transition group">
        <div class="h-40 bg-gray-100 relative overflow-hidden">
            <?php if ($pImg): ?>
            <img src="<?= $pImg ?>" alt="" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
            <?php else: ?>
            <div class="w-full h-full flex items-center justify-center text-gray-300 text-5xl">📝</div>
            <?php endif; ?>
            <div class="absolute top-2 left-2 flex gap-1">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $pPub ? 'bg-green-500 text-white' : 'bg-yellow-400 text-yellow-900' ?>">
                    <?= strtoupper($p['status'] ?? 'draft') ?>
                </span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-white/90 text-gray-600">
                    <?= $pTplIcons[$pTpl] ?? '📰' ?> <?= ucfirst($pTpl) ?>
                </span>
            </div>
            <?php if (!empty($p['is_featured'])): ?>
            <div class="absolute top-2 right-2 px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-500 text-white">⭐</div>
            <?php endif; ?>
        </div>
        <div class="p-4">
            <h3 class="font-bold text-gray-800 text-sm mb-1 line-clamp-2"><?= e($p['title'] ?? '') ?></h3>
            <?php if (!empty($p['category'])): ?><span class="text-[10px] text-blue-600 font-medium"><?= e($p['category']) ?></span><?php endif; ?>
            <div class="flex items-center gap-3 mt-2 text-[10px] text-gray-400">
                <span>👤 <?= e($p['author_name'] ?? 'Admin') ?></span>
                <span>👁 <?= number_format(intval($p['views'] ?? 0)) ?></span>
                <span><?= !empty($p['published_at']) ? date('d M Y', strtotime($p['published_at'])) : date('d M Y', strtotime($p['created_at'] ?? 'now')) ?></span>
            </div>
            <div class="flex items-center gap-1 mt-3 pt-3 border-t">
                <a href="?edit=<?= intval($p['id']) ?>" class="flex-1 text-center py-1.5 bg-blue-50 text-blue-700 rounded-lg text-xs font-medium hover:bg-blue-100 transition">✏️ Edit</a>
                <?php if ($pPub): ?>
                <a href="<?= url('blog/' . ($p['slug'] ?? '')) ?>" target="_blank" class="px-3 py-1.5 bg-gray-50 text-gray-600 rounded-lg text-xs hover:bg-gray-100 transition">👁</a>
                <?php endif; ?>
                <form method="POST" class="inline"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="post_id" value="<?= intval($p['id']) ?>">
                    <button class="px-3 py-1.5 rounded-lg text-xs font-medium transition <?= $pPub ? 'bg-yellow-50 text-yellow-700 hover:bg-yellow-100' : 'bg-green-50 text-green-700 hover:bg-green-100' ?>"><?= $pPub ? '📝' : '✅' ?></button>
                </form>
                <form method="POST" class="inline"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="post_id" value="<?= intval($p['id']) ?>">
                    <button class="px-2 py-1.5 bg-gray-50 text-gray-500 rounded-lg text-xs hover:bg-gray-100 transition" title="Duplicate">📋</button>
                </form>
                <form method="POST" class="inline" onsubmit="return confirm('Delete permanently?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="post_id" value="<?= intval($p['id']) ?>">
                    <button class="px-2 py-1.5 bg-red-50 text-red-500 rounded-lg text-xs hover:bg-red-100 transition" title="Delete">🗑</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($posts)): ?>
    <div class="sm:col-span-2 lg:col-span-3 text-center py-20">
        <div class="text-7xl mb-4 opacity-40">📝</div>
        <h3 class="text-xl font-bold text-gray-700 mb-2">No Blog Posts Yet</h3>
        <p class="text-gray-400 mb-5">Start creating content to engage your audience</p>
        <a href="?new=1" class="inline-block bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow">➕ Create First Post</a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
