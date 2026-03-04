<?php
/**
 * Search Results Page
 */
require_once __DIR__ . '/../includes/functions.php';

$query = sanitize($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'relevance';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$pageTitle = $query ? "অনুসন্ধান: $query" : 'পণ্য অনুসন্ধান';

$db = Database::getInstance();
$products = [];
$total = 0;

if ($query) {
    $searchTerm = "%{$query}%";
    $where = "p.is_active = 1 AND (p.name LIKE ? OR p.name_bn LIKE ? OR p.sku LIKE ? OR p.short_description LIKE ? OR p.tags LIKE ?)";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];

    $total = $db->fetch("SELECT COUNT(*) as cnt FROM products p WHERE $where", $params)['cnt'];

    $orderBy = match($sort) {
        'price_low' => 'CASE WHEN p.is_on_sale=1 AND p.sale_price>0 THEN p.sale_price ELSE p.regular_price END ASC',
        'price_high' => 'CASE WHEN p.is_on_sale=1 AND p.sale_price>0 THEN p.sale_price ELSE p.regular_price END DESC',
        'newest' => 'p.created_at DESC',
        'popular' => 'p.sales_count DESC',
        default => 'p.is_featured DESC, p.sales_count DESC',
    };

    $offset = ($page - 1) * $perPage;
    $products = $db->fetchAll("
        SELECT p.*, c.name as category_name, c.slug as category_slug,
            (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as image
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE $where
        ORDER BY $orderBy
        LIMIT $perPage OFFSET $offset
    ", $params);
}

$pagination = paginate($total, $page, $perPage, url("search?q=" . urlencode($query) . "&sort=$sort"));

require_once __DIR__ . '/../includes/header.php';

// ── FB Search tracking ──
if ($query) {
    $__srEid = null;
    try {
        if (file_exists(__DIR__ . '/../includes/fb-capi.php')) {
            require_once __DIR__ . '/../includes/fb-capi.php';
            if (fbCapiEnabled()) { $__srEid = fbEventId(); fbTrackSearch($query, $__srEid); }
        }
    } catch (\Throwable $e) {}
    $__fbPxId = getSetting('fb_pixel_id', '') ?: getSetting('facebook_pixel_id', '') ?: getSetting('facebook_pixel', '');
    $__csSearch = !function_exists('fbPixelEventEnabled') || fbPixelEventEnabled('Search');
    if ($__fbPxId && $__csSearch): ?>
    <script>if(typeof _fbTrack==='function') _fbTrack('Search',{search_string:<?= json_encode($query) ?>,content_type:'product'}<?= $__srEid ? ",'{$__srEid}'" : '' ?>);</script>
    <?php endif;
}
?>

<div class="max-w-6xl mx-auto px-4 py-6">
    <!-- Search Bar -->
    <form method="GET" action="<?= url('search') ?>" class="mb-6">
        <div class="flex gap-2">
            <div class="relative flex-1">
                <input type="text" name="q" value="<?= e($query) ?>" 
                       class="w-full border rounded-xl px-4 py-3 pl-10 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                       placeholder="পণ্য খুঁজুন..." autofocus>
                <svg class="w-5 h-5 text-gray-400 absolute left-3 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <button type="submit" class="btn-primary px-6 rounded-xl text-sm font-medium">খুঁজুন</button>
        </div>
    </form>

    <?php if ($query): ?>
    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-gray-500">"<strong><?= e($query) ?></strong>" এর জন্য <?= $total ?> ফলাফল</p>
        <select onchange="location.href=this.value" class="border rounded-lg px-3 py-2 text-sm">
            <option value="<?= url("search?q=" . urlencode($query) . "&sort=relevance") ?>" <?= $sort === 'relevance' ? 'selected' : '' ?>>প্রাসঙ্গিক</option>
            <option value="<?= url("search?q=" . urlencode($query) . "&sort=newest") ?>" <?= $sort === 'newest' ? 'selected' : '' ?>>নতুন</option>
            <option value="<?= url("search?q=" . urlencode($query) . "&sort=price_low") ?>" <?= $sort === 'price_low' ? 'selected' : '' ?>>দাম: কম → বেশি</option>
            <option value="<?= url("search?q=" . urlencode($query) . "&sort=price_high") ?>" <?= $sort === 'price_high' ? 'selected' : '' ?>>দাম: বেশি → কম</option>
            <option value="<?= url("search?q=" . urlencode($query) . "&sort=popular") ?>" <?= $sort === 'popular' ? 'selected' : '' ?>>জনপ্রিয়</option>
        </select>
    </div>

    <?php if (empty($products)): ?>
    <div class="text-center py-16 bg-white rounded-2xl shadow-sm border">
        <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
            <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
        <p class="text-gray-500 text-lg mb-1">কোনো পণ্য পাওয়া যায়নি</p>
        <p class="text-gray-400 text-sm">অন্য কীওয়ার্ড দিয়ে চেষ্টা করুন</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
        <?php foreach ($products as $product): ?>
            <?php include __DIR__ . '/../includes/product-card.php'; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($pagination['totalPages'] > 1): ?>
    <div class="mt-8"><?= renderPagination($pagination) ?></div>
    <?php endif; ?>
    <?php endif; ?>

    <?php else: ?>
    <!-- No query - show popular categories -->
    <div class="text-center py-12">
        <p class="text-gray-400 text-lg mb-4">কী খুঁজছেন?</p>
        <?php
        $popularCats = $db->fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 8");
        ?>
        <div class="flex flex-wrap gap-2 justify-center">
            <?php foreach ($popularCats as $cat): ?>
            <a href="<?= url('category/' . $cat['slug']) ?>" 
               class="px-4 py-2 bg-white border rounded-full text-sm text-gray-600 hover:border-blue-500 hover:text-blue-600 transition">
                <?= e($cat['name_bn'] ?: $cat['name']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
