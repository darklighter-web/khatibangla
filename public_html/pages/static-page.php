<?php
/**
 * Static CMS Page Display
 */
require_once __DIR__ . '/../includes/functions.php';

$slug = $_GET['slug'] ?? '';
if (!$slug) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

$db = Database::getInstance();
$page = $db->fetch("SELECT * FROM pages WHERE slug = ? AND is_active = 1", [$slug]);

if (!$page) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

$pageTitle = $page['meta_title'] ?: $page['title'];
$pageDescription = $page['meta_description'] ?: mb_substr(strip_tags($page['content']), 0, 160);
$seo = [
    'type' => 'website',
    'title' => $pageTitle . ' | ' . getSetting('site_name'),
    'description' => $pageDescription,
    'breadcrumbs' => [
        ['name' => 'হোম', 'url' => SITE_URL],
        ['name' => $page['title'] ?? ''],
    ],
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6"><?= e($page['title']) ?></h1>
    
    <div class="prose max-w-none bg-white rounded-2xl shadow-sm border p-6 lg:p-8">
        <?= $page['content'] ?>
    </div>
    
    <p class="text-xs text-gray-400 mt-4">সর্বশেষ আপডেট: <?= date('d M Y', strtotime($page['updated_at'])) ?></p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
