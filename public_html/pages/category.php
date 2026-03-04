<?php
/**
 * Category / Product Archive Page
 */
$slug = $_GET['slug'] ?? '';
$search = $_GET['q'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$sortBy = $_GET['sort'] ?? 'newest';
$db = Database::getInstance();
$arPerPage = intval(getSetting('ar_products_per_page', ITEMS_PER_PAGE));
if ($arPerPage < 1) $arPerPage = ITEMS_PER_PAGE;
$arShowSort = getSetting('ar_show_sort', '1') === '1';

$category = null;
$filters = ['limit' => $arPerPage, 'offset' => ($page - 1) * $arPerPage];

if ($slug) {
    $category = getCategoryBySlug($slug);
    if (!$category) { http_response_code(404); include ROOT_PATH . 'pages/404.php'; return; }
    $filters['category_slug'] = $slug;
    $pageTitle = ($category['meta_title'] ?: $category['name']) . ' | ' . getSetting('site_name');
} elseif ($search) {
    $filters['search'] = $search;
    $pageTitle = 'Search: ' . htmlspecialchars($search) . ' | ' . getSetting('site_name');
} else {
    $pageTitle = 'All Products | ' . getSetting('site_name');
}

// Sorting
$orderMap = [
    'newest' => 'p.created_at DESC',
    'price_low' => 'COALESCE(p.sale_price, p.regular_price) ASC',
    'price_high' => 'COALESCE(p.sale_price, p.regular_price) DESC',
    'popular' => 'p.sales_count DESC',
    'name' => 'p.name ASC',
];
$filters['order_by'] = $orderMap[$sortBy] ?? $orderMap['newest'];

$products = getProducts($filters);

// Count for pagination
$countWhere = "p.is_active = 1";
$countParams = [];
if ($slug) { $countWhere .= " AND c.slug = ?"; $countParams[] = $slug; }
if ($search) { $s = "%{$search}%"; $countWhere .= " AND (p.name LIKE ? OR p.name_bn LIKE ?)"; $countParams = array_merge($countParams, [$s, $s]); }
$totalProducts = $db->fetch("SELECT COUNT(*) as cnt FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE {$countWhere}", $countParams)['cnt'];

$pagination = paginate($totalProducts, $page, $arPerPage, currentUrl());

// SEO: Category/Search page
$_catDesc = '';
$_catImg = '';
$_crumbs = [['name' => 'হোম', 'url' => SITE_URL]];
if ($category) {
    $_catDesc = $category['meta_description'] ?? '';
    $_catOrigDesc = $category['description'] ?? '';
    if (!empty($category['image'])) {
        $_catImg = $category['image'];
        if (!str_starts_with($_catImg, 'http')) $_catImg = SITE_URL . '/uploads/categories/' . basename($_catImg);
    }
    $_crumbs[] = ['name' => $category['name']];
    // Only set pageDescription if admin explicitly wrote one; otherwise auto-SEO handles it
    $pageDescription = $_catDesc ?: null;
} elseif ($search) {
    $_crumbs[] = ['name' => 'Search: ' . $search];
} else {
    $_crumbs[] = ['name' => 'All Products'];
}
$seo = [
    'type' => $category ? 'category' : ($search ? 'website' : 'shop'),
    'title' => $pageTitle,
    'description' => $pageDescription ?? '',
    'image' => $_catImg,
    'category_name' => $category['name'] ?? '',
    'category_description' => $_catOrigDesc ?? '',
    'category_image' => $_catImg,
    'noindex' => !empty($search),
    'breadcrumbs' => $_crumbs,
];

include ROOT_PATH . 'includes/header.php';
?>

<main class="max-w-7xl mx-auto px-4 py-6">
    <!-- Breadcrumbs -->
    <nav class="text-sm text-gray-500 mb-4 flex items-center gap-2">
        <a href="<?= url() ?>" class="hover:text-red-600"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right text-xs text-gray-300"></i>
        <span class="text-gray-700"><?= $category ? htmlspecialchars($category['name_bn'] ?: $category['name']) : ($search ? 'Search: ' . htmlspecialchars($search) : 'All Products') ?></span>
    </nav>
    
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">
                <?= $category ? htmlspecialchars($category['name_bn'] ?: $category['name']) : ($search ? 'অনুসন্ধান: "' . htmlspecialchars($search) . '"' : 'সকল পণ্য') ?>
            </h1>
            <p class="text-sm text-gray-500 mt-1"><?= $totalProducts ?> টি পণ্য পাওয়া গেছে</p>
        </div>
        
        <!-- Sort -->
        <?php if ($arShowSort): ?>
        <div class="flex items-center gap-2">
            <label class="text-sm text-gray-600">সাজান:</label>
            <select onchange="window.location.href=updateParam('sort',this.value)" class="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300">
                <option value="newest" <?= $sortBy === 'newest' ? 'selected' : '' ?>>নতুন</option>
                <option value="popular" <?= $sortBy === 'popular' ? 'selected' : '' ?>>জনপ্রিয়</option>
                <option value="price_low" <?= $sortBy === 'price_low' ? 'selected' : '' ?>>দাম: কম থেকে বেশি</option>
                <option value="price_high" <?= $sortBy === 'price_high' ? 'selected' : '' ?>>দাম: বেশি থেকে কম</option>
            </select>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if (empty($products)): ?>
    <div class="text-center py-16">
        <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
            <i class="fas fa-search text-3xl text-gray-300"></i>
        </div>
        <h3 class="text-lg font-medium text-gray-600">কোনো পণ্য পাওয়া যায়নি</h3>
        <p class="text-gray-400 mt-1">অন্য কিছু খুঁজে দেখুন</p>
        <a href="<?= url() ?>" class="inline-block mt-4 px-6 py-2.5 rounded-xl btn-primary font-medium">হোমপেজে যান</a>
    </div>
    <?php else: ?>
    <!-- Product Grid -->
    <?php 
    $arColsDesktop = getSetting('ar_grid_cols_desktop', '5');
    $arColsTablet = getSetting('ar_grid_cols_tablet', '4');
    $arColsMobile = getSetting('ar_grid_cols_mobile', '2');
    $gridClass = "grid grid-cols-{$arColsMobile} sm:grid-cols-3 md:grid-cols-{$arColsTablet} lg:grid-cols-{$arColsDesktop} gap-3 sm:gap-4";
    ?>
    <div class="<?= $gridClass ?>">
        <?php foreach ($products as $product): ?>
            <?php include ROOT_PATH . 'includes/product-card.php'; ?>
        <?php endforeach; ?>
    </div>
    
    <?= renderPagination($pagination) ?>
    <?php endif; ?>
</main>

<script>
function updateParam(key, val) {
    const url = new URL(window.location.href);
    url.searchParams.set(key, val);
    url.searchParams.delete('page');
    return url.toString();
}
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
