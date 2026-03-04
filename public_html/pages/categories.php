<?php
/**
 * All Categories Listing Page
 */
$pageTitle = 'সকল ক্যাটাগরি';

$db = Database::getInstance();
$categories = $db->fetchAll("
    SELECT c.*, 
        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) as product_count
    FROM categories c 
    WHERE c.is_active = 1 
    ORDER BY c.sort_order ASC, c.name ASC
");

$pageDescription = 'Browse all product categories at ' . getSetting('site_name', 'KhatiBangla');
$seo = [
    'type' => 'website',
    'title' => $pageTitle . ' | ' . getSetting('site_name'),
    'description' => $pageDescription,
    'breadcrumbs' => [
        ['name' => 'হোম', 'url' => SITE_URL],
        ['name' => 'সকল ক্যাটাগরি'],
    ],
];

include ROOT_PATH . 'includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 py-6">
    <h1 class="text-2xl font-bold mb-6">সকল ক্যাটাগরি</h1>
    
    <?php if (empty($categories)): ?>
    <div class="text-center py-16 bg-white rounded-2xl shadow-sm border">
        <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
            <i class="fas fa-folder-open text-3xl text-gray-300"></i>
        </div>
        <p class="text-gray-500">কোনো ক্যাটাগরি পাওয়া যায়নি</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
        <?php foreach ($categories as $cat): ?>
        <a href="<?= url('category/' . $cat['slug']) ?>" 
           class="bg-white rounded-xl shadow-sm border overflow-hidden group hover:shadow-md transition">
            <?php if ($cat['image']): ?>
            <div class="aspect-square overflow-hidden">
                <img src="<?= uploadUrl($cat['image']) ?>" alt="<?= e($cat['name_bn'] ?: $cat['name']) ?>" 
                     class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
            </div>
            <?php else: ?>
            <div class="aspect-square bg-gradient-to-br from-gray-100 to-gray-50 flex items-center justify-center">
                <i class="fas fa-tags text-3xl text-gray-300"></i>
            </div>
            <?php endif; ?>
            <div class="p-3 text-center">
                <h3 class="font-semibold text-sm group-hover:text-blue-600 transition"><?= e($cat['name_bn'] ?: $cat['name']) ?></h3>
                <p class="text-xs text-gray-400 mt-0.5"><?= $cat['product_count'] ?> পণ্য</p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
