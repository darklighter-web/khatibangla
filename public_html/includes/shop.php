<?php
/**
 * Shop — All Products Page
 * Full-featured browsing with category filter, sort, price range, pagination
 */
$db = Database::getInstance();

// ── URL Parameters ──
$page       = max(1, intval($_GET['page'] ?? 1));
$sortBy     = $_GET['sort'] ?? 'newest';
$catSlug    = $_GET['cat'] ?? '';
$priceMin   = $_GET['min'] ?? '';
$priceMax   = $_GET['max'] ?? '';
$stock      = $_GET['stock'] ?? '';    // in_stock | all
$perPage    = intval(getSetting('shop_products_per_page', getSetting('ar_products_per_page', 20)));
if ($perPage < 4) $perPage = 20;

$shopTitle  = getSetting('shop_page_title', 'সকল পণ্য');
$pageTitle  = $shopTitle . ' | ' . getSetting('site_name');

// ── All Categories (with product counts) ──
$allCategories = $db->fetchAll("
    SELECT c.id, c.name, c.name_bn, c.slug, c.image, c.parent_id,
           (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) as product_count
    FROM categories c WHERE c.is_active = 1 
    ORDER BY c.sort_order ASC, c.name ASC
");
$parentCats = array_filter($allCategories, fn($c) => empty($c['parent_id']));

// ── Active category ──
$activeCategory = null;
if ($catSlug) {
    $activeCategory = getCategoryBySlug($catSlug);
}

// ── Build query ──
$where = ["p.is_active = 1"];
$params = [];

if ($activeCategory) {
    // Include child categories
    $childIds = array_column(array_filter($allCategories, fn($c) => $c['parent_id'] == $activeCategory['id']), 'id');
    $allIds = array_merge([$activeCategory['id']], $childIds);
    $placeholders = implode(',', array_fill(0, count($allIds), '?'));
    $where[] = "p.category_id IN ({$placeholders})";
    $params = array_merge($params, $allIds);
}
if ($priceMin !== '' && is_numeric($priceMin)) {
    $where[] = "COALESCE(p.sale_price, p.regular_price) >= ?";
    $params[] = floatval($priceMin);
}
if ($priceMax !== '' && is_numeric($priceMax)) {
    $where[] = "COALESCE(p.sale_price, p.regular_price) <= ?";
    $params[] = floatval($priceMax);
}
if ($stock === 'in_stock') {
    $where[] = "p.stock_status = 'in_stock'";
}

$orderMap = [
    'newest'     => 'p.created_at DESC',
    'popular'    => 'p.sales_count DESC',
    'price_low'  => 'COALESCE(p.sale_price, p.regular_price) ASC',
    'price_high' => 'COALESCE(p.sale_price, p.regular_price) DESC',
    'name'       => 'p.name ASC',
    'discount'   => '(p.regular_price - COALESCE(p.sale_price, p.regular_price)) DESC',
];
$orderSql = $orderMap[$sortBy] ?? $orderMap['newest'];

$whereStr = implode(' AND ', $where);
$offset = ($page - 1) * $perPage;

// ── Total count ──
$totalProducts = $db->fetch(
    "SELECT COUNT(*) as cnt FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE {$whereStr}", 
    $params
)['cnt'];

// ── Fetch products ──
$products = $db->fetchAll(
    "SELECT p.*, c.name as category_name, c.slug as category_slug 
     FROM products p LEFT JOIN categories c ON c.id = p.category_id 
     WHERE {$whereStr} ORDER BY {$orderSql} LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// ── Price stats (for range hints) ──
$priceStats = $db->fetch("SELECT MIN(COALESCE(sale_price, regular_price)) as min_price, MAX(COALESCE(sale_price, regular_price)) as max_price FROM products WHERE is_active = 1");

$totalPages = ceil($totalProducts / $perPage);

// ── Build filter URL helper ──
function shopUrl($overrides = []) {
    $params = [
        'cat'   => $_GET['cat'] ?? '',
        'sort'  => $_GET['sort'] ?? '',
        'min'   => $_GET['min'] ?? '',
        'max'   => $_GET['max'] ?? '',
        'stock' => $_GET['stock'] ?? '',
    ];
    $params = array_merge($params, $overrides);
    // Remove page when changing filters
    if (isset($overrides['cat']) || isset($overrides['min']) || isset($overrides['max']) || isset($overrides['stock'])) {
        unset($params['page']);
    }
    if (isset($overrides['page'])) $params['page'] = $overrides['page'];
    // Clean empties
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    $qs = http_build_query($params);
    return url('shop') . ($qs ? '?' . $qs : '');
}

$gridCols = getSetting('shop_grid_cols', getSetting('ar_grid_cols_desktop', '4'));

include ROOT_PATH . 'includes/header.php';
?>

<style>
/* Shop page specific */
.shop-sidebar { scrollbar-width: thin; }
.shop-sidebar::-webkit-scrollbar { width: 4px; }
.shop-sidebar::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
.cat-link { transition: all 0.15s; }
.cat-link:hover { padding-left: 4px; }
.cat-link.active { color: var(--primary); font-weight: 600; }
.filter-drawer { transform: translateX(-100%); transition: transform 0.3s cubic-bezier(0.4,0,0.2,1); }
.filter-drawer.open { transform: translateX(0); }
.shop-grid { display: grid; gap: 12px; grid-template-columns: repeat(2, 1fr); }
@media(min-width:640px) { .shop-grid { gap: 16px; grid-template-columns: repeat(3, 1fr); } }
@media(min-width:768px) { .shop-grid { grid-template-columns: repeat(3, 1fr); } }
@media(min-width:1024px) { .shop-grid { grid-template-columns: repeat(<?= intval($gridCols) ?>, 1fr); } }
.sort-chip { padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 500; border: 1px solid #e5e7eb; background: #fff; color: #4b5563; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
.sort-chip:hover { border-color: #d1d5db; background: #f9fafb; }
.sort-chip.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.price-input { width: 100%; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; outline: none; }
.price-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(229,62,62,0.1); }
</style>

<main class="max-w-7xl mx-auto px-4 py-5">

    <!-- Breadcrumb -->
    <nav class="text-sm text-gray-400 mb-4 flex items-center gap-1.5 flex-wrap">
        <a href="<?= url() ?>" class="hover:text-gray-700"><i class="fas fa-home text-xs"></i></a>
        <i class="fas fa-chevron-right text-[10px]"></i>
        <?php if ($activeCategory): ?>
        <a href="<?= shopUrl(['cat' => '']) ?>" class="hover:text-gray-700"><?= e($shopTitle) ?></a>
        <i class="fas fa-chevron-right text-[10px]"></i>
        <span class="text-gray-700 font-medium"><?= e($activeCategory['name_bn'] ?: $activeCategory['name']) ?></span>
        <?php else: ?>
        <span class="text-gray-700 font-medium"><?= e($shopTitle) ?></span>
        <?php endif; ?>
    </nav>

    <!-- Mobile: Filter Toggle + Sort -->
    <div class="lg:hidden flex items-center justify-between gap-3 mb-4">
        <button onclick="toggleFilterDrawer()" class="flex items-center gap-2 px-4 py-2.5 bg-white border rounded-xl text-sm font-medium text-gray-700 shadow-sm active:scale-95 transition">
            <i class="fas fa-sliders-h"></i> ফিল্টার
            <?php if ($catSlug || $priceMin || $priceMax || $stock): ?>
            <span class="w-2 h-2 rounded-full bg-red-500"></span>
            <?php endif; ?>
        </button>
        <select onchange="location.href=this.value" class="flex-1 bg-white border rounded-xl px-3 py-2.5 text-sm shadow-sm">
            <option value="<?= shopUrl(['sort' => 'newest']) ?>" <?= $sortBy==='newest'?'selected':'' ?>>নতুন</option>
            <option value="<?= shopUrl(['sort' => 'popular']) ?>" <?= $sortBy==='popular'?'selected':'' ?>>জনপ্রিয়</option>
            <option value="<?= shopUrl(['sort' => 'price_low']) ?>" <?= $sortBy==='price_low'?'selected':'' ?>>দাম: কম → বেশি</option>
            <option value="<?= shopUrl(['sort' => 'price_high']) ?>" <?= $sortBy==='price_high'?'selected':'' ?>>দাম: বেশি → কম</option>
            <option value="<?= shopUrl(['sort' => 'discount']) ?>" <?= $sortBy==='discount'?'selected':'' ?>>ডিসকাউন্ট</option>
        </select>
    </div>

    <!-- Mobile Filter Drawer -->
    <div id="filterOverlay" class="fixed inset-0 bg-black/50 z-50 hidden" onclick="toggleFilterDrawer()"></div>
    <aside id="filterDrawer" class="filter-drawer fixed top-0 left-0 bottom-0 w-[300px] max-w-[85vw] bg-white z-50 overflow-y-auto shadow-2xl">
        <div class="sticky top-0 bg-white border-b px-4 py-3 flex items-center justify-between z-10">
            <h3 class="font-bold text-gray-800"><i class="fas fa-filter mr-2 text-sm"></i>ফিল্টার</h3>
            <button onclick="toggleFilterDrawer()" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100">
                <i class="fas fa-times text-gray-400"></i>
            </button>
        </div>
        <div class="p-4 space-y-5">
            <!-- Categories -->
            <div>
                <h4 class="text-sm font-semibold text-gray-800 mb-2">ক্যাটাগরি</h4>
                <a href="<?= shopUrl(['cat' => '']) ?>" class="cat-link block py-1.5 text-sm <?= !$catSlug ? 'active' : 'text-gray-600 hover:text-gray-900' ?>">
                    সকল পণ্য <span class="text-gray-400">(<?= $totalProducts ?>)</span>
                </a>
                <?php foreach ($parentCats as $cat): ?>
                <a href="<?= shopUrl(['cat' => $cat['slug']]) ?>" 
                   class="cat-link block py-1.5 text-sm <?= $catSlug === $cat['slug'] ? 'active' : 'text-gray-600 hover:text-gray-900' ?>">
                    <?= e($cat['name_bn'] ?: $cat['name']) ?>
                    <span class="text-gray-400">(<?= $cat['product_count'] ?>)</span>
                </a>
                <?php 
                    $children = array_filter($allCategories, fn($c) => $c['parent_id'] == $cat['id']);
                    foreach ($children as $child): ?>
                    <a href="<?= shopUrl(['cat' => $child['slug']]) ?>" 
                       class="cat-link block py-1 text-sm pl-4 <?= $catSlug === $child['slug'] ? 'active' : 'text-gray-500 hover:text-gray-800' ?>">
                        └ <?= e($child['name_bn'] ?: $child['name']) ?>
                        <span class="text-gray-400">(<?= $child['product_count'] ?>)</span>
                    </a>
                <?php endforeach; endforeach; ?>
            </div>
            <!-- Price -->
            <div>
                <h4 class="text-sm font-semibold text-gray-800 mb-2">মূল্য</h4>
                <form method="GET" action="<?= url('shop') ?>" class="space-y-2">
                    <?php if ($catSlug): ?><input type="hidden" name="cat" value="<?= e($catSlug) ?>"><?php endif; ?>
                    <?php if ($sortBy !== 'newest'): ?><input type="hidden" name="sort" value="<?= e($sortBy) ?>"><?php endif; ?>
                    <?php if ($stock): ?><input type="hidden" name="stock" value="<?= e($stock) ?>"><?php endif; ?>
                    <div class="flex gap-2">
                        <input type="number" name="min" placeholder="৳ ন্যূনতম" value="<?= e($priceMin) ?>" class="price-input" min="0">
                        <input type="number" name="max" placeholder="৳ সর্বোচ্চ" value="<?= e($priceMax) ?>" class="price-input" min="0">
                    </div>
                    <button class="w-full py-2 rounded-lg text-sm font-medium btn-primary">ফিল্টার করুন</button>
                </form>
                <?php if ($priceStats['min_price'] !== null): ?>
                <p class="text-xs text-gray-400 mt-1">
                    মূল্য পরিসীমা: <?= formatPrice($priceStats['min_price']) ?> — <?= formatPrice($priceStats['max_price']) ?>
                </p>
                <?php endif; ?>
            </div>
            <!-- Stock -->
            <div>
                <h4 class="text-sm font-semibold text-gray-800 mb-2">স্টক</h4>
                <a href="<?= shopUrl(['stock' => '']) ?>" class="cat-link block py-1.5 text-sm <?= !$stock ? 'active' : 'text-gray-600' ?>">সব দেখুন</a>
                <a href="<?= shopUrl(['stock' => 'in_stock']) ?>" class="cat-link block py-1.5 text-sm <?= $stock==='in_stock' ? 'active' : 'text-gray-600' ?>">
                    <i class="fas fa-check-circle text-green-500 mr-1"></i> শুধু স্টকে আছে
                </a>
            </div>
            <?php if ($catSlug || $priceMin || $priceMax || $stock): ?>
            <a href="<?= url('shop') ?>" class="block text-center text-sm text-red-500 font-medium py-2 border border-red-200 rounded-lg hover:bg-red-50 transition">
                <i class="fas fa-times mr-1"></i> সব ফিল্টার মুছুন
            </a>
            <?php endif; ?>
        </div>
    </aside>

    <div class="flex gap-6">
        <!-- Desktop Sidebar -->
        <aside class="hidden lg:block w-[240px] flex-shrink-0">
            <div class="bg-white rounded-2xl border shadow-sm overflow-hidden sticky top-4">
                <!-- Categories -->
                <div class="p-4 border-b">
                    <h4 class="text-sm font-bold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-layer-group text-xs" style="color:var(--primary)"></i> ক্যাটাগরি
                    </h4>
                    <div class="space-y-0.5 shop-sidebar max-h-[350px] overflow-y-auto pr-1">
                        <a href="<?= shopUrl(['cat' => '']) ?>" class="cat-link flex items-center justify-between py-1.5 px-2 rounded-lg text-sm <?= !$catSlug ? 'active bg-red-50' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <span>সকল পণ্য</span>
                        </a>
                        <?php foreach ($parentCats as $cat): 
                            $isActive = $catSlug === $cat['slug'];
                            $children = array_filter($allCategories, fn($c) => $c['parent_id'] == $cat['id']);
                            $isParentActive = $isActive || in_array($catSlug, array_column($children, 'slug'));
                        ?>
                        <a href="<?= shopUrl(['cat' => $cat['slug']]) ?>" 
                           class="cat-link flex items-center justify-between py-1.5 px-2 rounded-lg text-sm <?= $isActive ? 'active bg-red-50' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <span class="truncate"><?= e($cat['name_bn'] ?: $cat['name']) ?></span>
                            <span class="text-xs text-gray-400 flex-shrink-0 ml-1"><?= $cat['product_count'] ?></span>
                        </a>
                        <?php if (!empty($children) && $isParentActive): foreach ($children as $child): ?>
                        <a href="<?= shopUrl(['cat' => $child['slug']]) ?>" 
                           class="cat-link flex items-center justify-between py-1 px-2 pl-5 rounded-lg text-xs <?= $catSlug === $child['slug'] ? 'active bg-red-50' : 'text-gray-500 hover:bg-gray-50' ?>">
                            <span class="truncate"><?= e($child['name_bn'] ?: $child['name']) ?></span>
                            <span class="text-gray-400 flex-shrink-0 ml-1"><?= $child['product_count'] ?></span>
                        </a>
                        <?php endforeach; endif; endforeach; ?>
                    </div>
                </div>
                <!-- Price Filter -->
                <div class="p-4 border-b">
                    <h4 class="text-sm font-bold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-tag text-xs" style="color:var(--primary)"></i> মূল্য
                    </h4>
                    <form method="GET" action="<?= url('shop') ?>" class="space-y-2">
                        <?php if ($catSlug): ?><input type="hidden" name="cat" value="<?= e($catSlug) ?>"><?php endif; ?>
                        <?php if ($sortBy !== 'newest'): ?><input type="hidden" name="sort" value="<?= e($sortBy) ?>"><?php endif; ?>
                        <?php if ($stock): ?><input type="hidden" name="stock" value="<?= e($stock) ?>"><?php endif; ?>
                        <div class="flex gap-2">
                            <input type="number" name="min" placeholder="ন্যূনতম" value="<?= e($priceMin) ?>" class="price-input text-sm" min="0">
                            <span class="self-center text-gray-300">—</span>
                            <input type="number" name="max" placeholder="সর্বোচ্চ" value="<?= e($priceMax) ?>" class="price-input text-sm" min="0">
                        </div>
                        <button class="w-full py-2 rounded-lg text-xs font-semibold btn-primary transition">ফিল্টার করুন</button>
                    </form>
                    <?php if ($priceStats['min_price'] !== null): ?>
                    <p class="text-[11px] text-gray-400 mt-2"><?= formatPrice($priceStats['min_price']) ?> — <?= formatPrice($priceStats['max_price']) ?></p>
                    <?php endif; ?>
                </div>
                <!-- Stock -->
                <div class="p-4">
                    <h4 class="text-sm font-bold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-box text-xs" style="color:var(--primary)"></i> স্টক
                    </h4>
                    <div class="space-y-1">
                        <a href="<?= shopUrl(['stock' => '']) ?>" class="cat-link block py-1.5 px-2 rounded-lg text-sm <?= !$stock ? 'active bg-red-50' : 'text-gray-600 hover:bg-gray-50' ?>">সব দেখুন</a>
                        <a href="<?= shopUrl(['stock' => 'in_stock']) ?>" class="cat-link block py-1.5 px-2 rounded-lg text-sm <?= $stock==='in_stock' ? 'active bg-red-50' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-check-circle text-green-500 mr-1 text-xs"></i> স্টকে আছে
                        </a>
                    </div>
                </div>
                <?php if ($catSlug || $priceMin || $priceMax || $stock): ?>
                <div class="p-3 border-t">
                    <a href="<?= url('shop') ?>" class="block text-center text-xs text-red-500 font-semibold py-2 border border-red-200 rounded-lg hover:bg-red-50 transition">
                        <i class="fas fa-times mr-1"></i> সব ফিল্টার মুছুন
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 min-w-0">
            <!-- Header Bar -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-800">
                        <?= $activeCategory ? e($activeCategory['name_bn'] ?: $activeCategory['name']) : e($shopTitle) ?>
                    </h1>
                    <p class="text-sm text-gray-500 mt-0.5"><?= number_format($totalProducts) ?> টি পণ্য</p>
                </div>
                <!-- Desktop Sort Chips -->
                <div class="hidden lg:flex items-center gap-2 flex-wrap">
                    <?php 
                    $sorts = [
                        'newest'     => 'নতুন',
                        'popular'    => 'জনপ্রিয়',
                        'price_low'  => 'দাম ↑',
                        'price_high' => 'দাম ↓',
                        'discount'   => 'ছাড়',
                    ];
                    foreach ($sorts as $key => $label): ?>
                    <a href="<?= shopUrl(['sort' => $key]) ?>" class="sort-chip <?= $sortBy === $key ? 'active' : '' ?>"><?= $label ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Active Filters -->
            <?php if ($catSlug || $priceMin || $priceMax || $stock): ?>
            <div class="flex flex-wrap gap-2 mb-4">
                <?php if ($activeCategory): ?>
                <a href="<?= shopUrl(['cat' => '']) ?>" class="inline-flex items-center gap-1.5 bg-red-50 text-red-600 px-3 py-1.5 rounded-full text-xs font-medium hover:bg-red-100 transition">
                    <?= e($activeCategory['name_bn'] ?: $activeCategory['name']) ?>
                    <i class="fas fa-times text-[10px]"></i>
                </a>
                <?php endif; ?>
                <?php if ($priceMin || $priceMax): ?>
                <a href="<?= shopUrl(['min' => '', 'max' => '']) ?>" class="inline-flex items-center gap-1.5 bg-blue-50 text-blue-600 px-3 py-1.5 rounded-full text-xs font-medium hover:bg-blue-100 transition">
                    মূল্য: <?= $priceMin ?: '0' ?> — <?= $priceMax ?: '∞' ?>
                    <i class="fas fa-times text-[10px]"></i>
                </a>
                <?php endif; ?>
                <?php if ($stock === 'in_stock'): ?>
                <a href="<?= shopUrl(['stock' => '']) ?>" class="inline-flex items-center gap-1.5 bg-green-50 text-green-600 px-3 py-1.5 rounded-full text-xs font-medium hover:bg-green-100 transition">
                    স্টকে আছে <i class="fas fa-times text-[10px]"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (empty($products)): ?>
            <!-- Empty State -->
            <div class="text-center py-16 bg-white rounded-2xl border shadow-sm">
                <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
                    <i class="fas fa-box-open text-3xl text-gray-300"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-600">কোনো পণ্য পাওয়া যায়নি</h3>
                <p class="text-gray-400 text-sm mt-1 mb-4">ফিল্টার পরিবর্তন করে আবার চেষ্টা করুন</p>
                <a href="<?= url('shop') ?>" class="inline-block px-6 py-2.5 rounded-xl btn-primary text-sm font-medium">সব পণ্য দেখুন</a>
            </div>

            <?php else: ?>
            <!-- Product Grid -->
            <div class="shop-grid">
                <?php foreach ($products as $product): ?>
                    <?php include ROOT_PATH . 'includes/product-card.php'; ?>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-8 flex justify-center">
                <div class="flex items-center gap-1">
                    <?php if ($page > 1): ?>
                    <a href="<?= shopUrl(['page' => $page - 1]) ?>" class="w-10 h-10 flex items-center justify-center rounded-xl border bg-white text-gray-600 hover:bg-gray-50 transition text-sm">
                        <i class="fas fa-chevron-left text-xs"></i>
                    </a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    if ($start > 1): ?>
                    <a href="<?= shopUrl(['page' => 1]) ?>" class="w-10 h-10 flex items-center justify-center rounded-xl border bg-white text-gray-600 hover:bg-gray-50 text-sm">1</a>
                    <?php if ($start > 2): ?><span class="w-8 text-center text-gray-400 text-sm">…</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                    <a href="<?= shopUrl(['page' => $i]) ?>" 
                       class="w-10 h-10 flex items-center justify-center rounded-xl text-sm font-medium transition <?= $i === $page ? 'text-white shadow-sm' : 'border bg-white text-gray-600 hover:bg-gray-50' ?>"
                       <?= $i === $page ? 'style="background:var(--primary)"' : '' ?>>
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span class="w-8 text-center text-gray-400 text-sm">…</span><?php endif; ?>
                    <a href="<?= shopUrl(['page' => $totalPages]) ?>" class="w-10 h-10 flex items-center justify-center rounded-xl border bg-white text-gray-600 hover:bg-gray-50 text-sm"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="<?= shopUrl(['page' => $page + 1]) ?>" class="w-10 h-10 flex items-center justify-center rounded-xl border bg-white text-gray-600 hover:bg-gray-50 transition text-sm">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </nav>
            <p class="text-center text-xs text-gray-400 mt-2">
                পৃষ্ঠা <?= $page ?> / <?= $totalPages ?> · মোট <?= number_format($totalProducts) ?> টি পণ্য
            </p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
function toggleFilterDrawer() {
    var d = document.getElementById('filterDrawer');
    var o = document.getElementById('filterOverlay');
    var isOpen = d.classList.contains('open');
    d.classList.toggle('open');
    o.classList.toggle('hidden');
    document.body.style.overflow = isOpen ? '' : 'hidden';
}
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
