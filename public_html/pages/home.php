<?php
/**
 * Homepage — reads Page Builder settings for sections
 */
$pageTitle = getSetting('meta_title', '') ?: null;
$pageDescription = getSetting('meta_description', '') ?: null;

// SEO: Home page gets Organization + WebSite schema, auto-generates title/desc
$seo = [
    'type' => 'home',
    'title' => $pageTitle ?? '',
    'description' => $pageDescription ?? '',
];

include ROOT_PATH . 'includes/header.php';

$db = Database::getInstance();

// Page Builder settings
$showHero       = getSetting('home_show_hero', '1') === '1';
$showCategories = getSetting('home_show_categories', '1') === '1';
$showSale       = getSetting('home_show_sale', '1') === '1';
$showFeatured   = getSetting('home_show_featured', '1') === '1';
$showAll        = getSetting('home_show_all', '1') === '1';
$showTrust      = getSetting('home_show_trust', '1') === '1';

// Fetch data only for enabled sections
$banners = [];
if ($showHero) {
    try {
        $banners = $db->fetchAll("SELECT * FROM banners WHERE is_active = 1 AND position = 'hero' ORDER BY sort_order ASC LIMIT 10");
    } catch (\Throwable $e) {
        $banners = $db->fetchAll("SELECT id, title, image, mobile_image, link_url, position, sort_order, is_active FROM banners WHERE is_active = 1 AND position = 'hero' ORDER BY sort_order ASC LIMIT 10");
    }
}
$featuredCategories = $showCategories ? $db->fetchAll("SELECT * FROM categories WHERE is_featured = 1 AND is_active = 1 ORDER BY sort_order ASC LIMIT " . intval(getSetting('home_categories_limit', 10))) : [];
$saleProducts = $showSale ? getProducts(['is_on_sale' => true, 'limit' => intval(getSetting('home_sale_limit', 8))]) : [];
$featuredProducts = $showFeatured ? getProducts(['is_featured' => true, 'limit' => intval(getSetting('home_featured_limit', 12))]) : [];
$allProducts = $showAll ? getProducts(['limit' => intval(getSetting('home_all_limit', 20))]) : [];

// Section texts
$saleTitle    = getSetting('home_sale_title', 'বিশেষ অফার');
$saleIcon     = getSetting('home_sale_icon', 'fas fa-fire');
$saleLinkText = getSetting('home_sale_link_text', 'সব দেখুন');
$saleLinkUrl  = getSetting('home_sale_link_url', '/category/offer-zone');
$featTitle    = getSetting('home_featured_title', 'জনপ্রিয় পণ্য');
$featIcon     = getSetting('home_featured_icon', 'fas fa-star');
$allTitle     = getSetting('home_all_title', 'সকল পণ্য');
$allIcon      = getSetting('home_all_icon', 'fas fa-th-large');
$bannerCount  = count($banners);
?>

<main class="pb-8">
    <!-- Hero Slider -->
    <?php if ($showHero && $bannerCount > 0): ?>
    <section class="relative overflow-hidden bg-gray-200" id="hero-slider">
        <!-- Fixed height container: responsive -->
        <div class="relative w-full" style="height:clamp(160px, 30vw, 400px)">
            <?php foreach ($banners as $bi => $banner): 
                // Use imgSrc() which handles both "filename.jpg" and "banners/filename.jpg" via basename()
                $imgUrl = imgSrc('banners', $banner['image']);
                $mobileImg = !empty($banner['mobile_image']) ? imgSrc('banners', $banner['mobile_image']) : '';
                $overlayText = $banner['overlay_text'] ?? '';
                $overlaySubtitle = $banner['overlay_subtitle'] ?? '';
                $buttonText = $banner['button_text'] ?? '';
                $buttonUrl = $banner['button_url'] ?? '#';
                $linkUrl = $banner['link_url'] ?: '#';
            ?>
            <div class="hero-slide absolute inset-0 transition-opacity duration-700 <?= $bi === 0 ? 'opacity-100' : 'opacity-0' ?>" style="z-index:<?= $bi === 0 ? 10 : 0 ?>">
                <a href="<?= htmlspecialchars($linkUrl) ?>" class="block w-full h-full">
                    <?php if ($mobileImg): ?>
                    <picture>
                        <source media="(max-width: 640px)" srcset="<?= $mobileImg ?>">
                        <img src="<?= $imgUrl ?>" alt="<?= htmlspecialchars($banner['title'] ?? '') ?>" 
                             class="w-full h-full object-cover object-center">
                    </picture>
                    <?php else: ?>
                    <img src="<?= $imgUrl ?>" alt="<?= htmlspecialchars($banner['title'] ?? '') ?>" 
                         class="w-full h-full object-cover object-center">
                    <?php endif; ?>
                </a>
                <?php if ($overlayText || $overlaySubtitle): ?>
                <div class="absolute inset-0 bg-gradient-to-r from-black/50 to-transparent flex items-center pointer-events-none">
                    <div class="pl-5 sm:pl-10 text-white max-w-lg">
                        <?php if ($overlayText): ?><h2 class="text-lg sm:text-2xl md:text-3xl font-bold mb-1 drop-shadow-lg banner-title"><?= htmlspecialchars($overlayText) ?></h2><?php endif; ?>
                        <?php if ($overlaySubtitle): ?><p class="text-xs sm:text-base opacity-90 drop-shadow banner-subtitle"><?= htmlspecialchars($overlaySubtitle) ?></p><?php endif; ?>
                        <?php if ($buttonText): ?><a href="<?= htmlspecialchars($buttonUrl) ?>" class="pointer-events-auto mt-2 sm:mt-3 inline-block bg-white text-gray-800 px-3 sm:px-5 py-1.5 sm:py-2 rounded-lg font-medium text-xs sm:text-sm hover:bg-gray-100 transition"><?= htmlspecialchars($buttonText) ?></a><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($bannerCount > 1): ?>
        <button onclick="slideHero(-1)" class="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 z-20 w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white/80 shadow flex items-center justify-center hover:bg-white transition">
            <i class="fas fa-chevron-left text-xs sm:text-sm"></i>
        </button>
        <button onclick="slideHero(1)" class="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 z-20 w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white/80 shadow flex items-center justify-center hover:bg-white transition">
            <i class="fas fa-chevron-right text-xs sm:text-sm"></i>
        </button>
        <div class="absolute bottom-2 left-1/2 -translate-x-1/2 flex gap-1.5 z-20">
            <?php for ($i = 0; $i < $bannerCount; $i++): ?>
            <button class="sdot w-2 h-2 rounded-full transition-all <?= $i === 0 ? 'bg-white w-5' : 'bg-white/50' ?>" onclick="goToSlide(<?= $i ?>)"></button>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </section>
    <script>
    (function(){
        var slides=document.querySelectorAll('.hero-slide'), dots=document.querySelectorAll('.sdot'),
            total=slides.length, cur=0, timer;
        function show(n){
            cur=((n%total)+total)%total;
            slides.forEach(function(s,i){ s.style.opacity=i===cur?'1':'0'; s.style.zIndex=i===cur?'10':'0'; });
            dots.forEach(function(d,i){ d.className='sdot w-2 h-2 rounded-full transition-all '+(i===cur?'bg-white w-5':'bg-white/50'); });
        }
        window.slideHero=function(d){show(cur+d);restart();};
        window.goToSlide=function(n){show(n);restart();};
        function restart(){clearInterval(timer);if(total>1)timer=setInterval(function(){show(cur+1);},5000);}
        // Swipe
        var tx=0,sl=document.getElementById('hero-slider');
        if(sl){
            sl.addEventListener('touchstart',function(e){tx=e.touches[0].clientX;},{passive:true});
            sl.addEventListener('touchend',function(e){var d=tx-e.changedTouches[0].clientX;if(Math.abs(d)>50){show(cur+(d>0?1:-1));restart();}},{passive:true});
        }
        restart();
    })();
    </script>
    <?php endif; ?>

    <!-- Featured Categories -->
    <?php if ($showCategories && !empty($featuredCategories)): ?>
    <section class="max-w-7xl mx-auto px-4 mt-8">
        <div class="flex overflow-x-auto scrollbar-hide gap-3 pb-2 -mx-4 px-4 sm:mx-0 sm:px-0 sm:grid sm:grid-cols-5 lg:grid-cols-10">
            <?php foreach ($featuredCategories as $cat): ?>
            <a href="<?= url('category/' . $cat['slug']) ?>" 
               class="flex-shrink-0 w-20 sm:w-auto flex flex-col items-center gap-2 p-3 rounded-xl hover:bg-red-50 hover:shadow-sm transition group">
                <?php if ($cat['image']): ?>
                <img src="<?= imgSrc('categories', $cat['image']) ?>" alt="" class="w-14 h-14 rounded-full object-cover border-2 border-gray-100 group-hover:border-red-300 transition">
                <?php else: ?>
                <div class="w-14 h-14 rounded-full flex items-center justify-center text-xl" style="background-color:<?= $primaryColor ?? '#e53e3e' ?>20;color:var(--primary)">
                    <i class="fas fa-tag"></i>
                </div>
                <?php endif; ?>
                <span class="text-xs font-medium text-center text-gray-700 group-hover:text-red-600 transition line-clamp-2 cat-name">
                    <?= htmlspecialchars($cat['name_bn'] ?: $cat['name']) ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Sale Products -->
    <?php if ($showSale && !empty($saleProducts)): ?>
    <section class="max-w-7xl mx-auto px-4 mt-10">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 flex items-center gap-2 section-heading">
                <span class="w-8 h-8 rounded-lg flex items-center justify-center sale-badge"><i class="<?= htmlspecialchars($saleIcon) ?> text-sm"></i></span>
                <?= htmlspecialchars($saleTitle) ?>
            </h2>
            <?php if ($saleLinkText): ?>
            <a href="<?= url(ltrim($saleLinkUrl, '/')) ?>" class="text-sm font-medium hover:underline section-link" style="color:var(--primary)">
                <?= htmlspecialchars($saleLinkText) ?> <i class="fas fa-arrow-right ml-1 text-xs"></i>
            </a>
            <?php endif; ?>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 sm:gap-4">
            <?php foreach ($saleProducts as $product): ?>
                <?php include ROOT_PATH . 'includes/product-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Featured Products -->
    <?php if ($showFeatured && !empty($featuredProducts)): ?>
    <section class="max-w-7xl mx-auto px-4 mt-10">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 flex items-center gap-2 section-heading">
                <span class="w-8 h-8 rounded-lg flex items-center justify-center bg-yellow-100"><i class="<?= htmlspecialchars($featIcon) ?> text-sm text-yellow-500"></i></span>
                <?= htmlspecialchars($featTitle) ?>
            </h2>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 sm:gap-4">
            <?php foreach ($featuredProducts as $product): ?>
                <?php include ROOT_PATH . 'includes/product-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- All Products -->
    <?php if ($showAll && !empty($allProducts)): ?>
    <section class="max-w-7xl mx-auto px-4 mt-10">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 flex items-center gap-2 section-heading">
                <span class="w-8 h-8 rounded-lg flex items-center justify-center bg-blue-100"><i class="<?= htmlspecialchars($allIcon) ?> text-sm text-blue-500"></i></span>
                <?= htmlspecialchars($allTitle) ?>
            </h2>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 sm:gap-4">
            <?php foreach ($allProducts as $product): ?>
                <?php include ROOT_PATH . 'includes/product-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Trust Badges -->
    <?php if ($showTrust): ?>
    <section class="max-w-7xl mx-auto px-4 mt-12">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <?php 
            $trustColors = ['green', 'blue', 'orange', 'red'];
            for ($i = 1; $i <= 4; $i++): 
                $tIcon = getSetting("home_trust_{$i}_icon", '');
                $tTitle = getSetting("home_trust_{$i}_title", '');
                $tSub = getSetting("home_trust_{$i}_subtitle", '');
                if (!$tTitle) continue;
                $color = $trustColors[$i - 1];
            ?>
            <div class="bg-white rounded-xl p-5 text-center shadow-sm border border-gray-100">
                <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-<?= $color ?>-100 flex items-center justify-center">
                    <i class="<?= htmlspecialchars($tIcon) ?> text-xl text-<?= $color ?>-600"></i>
                </div>
                <h3 class="font-semibold text-sm trust-title"><?= htmlspecialchars($tTitle) ?></h3>
                <?php if ($tSub): ?><p class="text-xs text-gray-500 mt-1 trust-subtitle"><?= htmlspecialchars($tSub) ?></p><?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>
    </section>
    <?php endif; ?>
</main>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
