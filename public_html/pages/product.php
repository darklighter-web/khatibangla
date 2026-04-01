<?php
/**
 * Single Product Page
 */
$slug = $_GET['slug'] ?? '';
$product = getProductBySlug($slug);

if (!$product) {
    http_response_code(404);
    include ROOT_PATH . 'pages/404.php';
    return;
}

// Increment views
$db = Database::getInstance();
$db->query("UPDATE products SET views = views + 1 WHERE id = ?", [$product['id']]);

$images = getProductImages($product['id']);
$variants = $db->fetchAll("SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1 ORDER BY option_type DESC, variant_name, id", [$product['id']]);
$relatedProducts = getProducts(['category_id' => $product['category_id'], 'limit' => 8]);
$relatedProducts = array_filter($relatedProducts, fn($p) => $p['id'] !== $product['id']);

// Load bundles
$bundles = [];
try {
    $bundles = $db->fetchAll(
        "SELECT pb.*, p.id as bp_id, p.name, p.name_bn, p.slug, p.featured_image, p.regular_price, p.sale_price, p.stock_status
         FROM product_bundles pb JOIN products p ON pb.bundle_product_id = p.id 
         WHERE pb.product_id = ? AND pb.is_active = 1 AND p.is_active = 1 
         ORDER BY pb.sort_order", [$product['id']]
    );
} catch (\Throwable $e) {}

$price = getProductPrice($product);
$discount = getDiscountPercent($product);
$isOnSale = $product['sale_price'] && $product['sale_price'] > 0 && $product['sale_price'] < $product['regular_price'];

// Calculate variation price range for merged products
$hasVariations = false;
$varPriceMin = $varPriceMax = null;
$varRegPriceMin = $varRegPriceMax = null;
$defaultVariantPrice = null;
$defaultVariantRegPrice = null;
foreach ($variants as $v) {
    if (($v['option_type'] ?? '') === 'variation' && $v['is_active'] && $v['absolute_price'] > 0) {
        $hasVariations = true;
        $vp = floatval($v['absolute_price']);
        if ($varPriceMin === null || $vp < $varPriceMin) $varPriceMin = $vp;
        if ($varPriceMax === null || $vp > $varPriceMax) $varPriceMax = $vp;
        // Track regular prices across variants
        $vrp = floatval($v['var_regular_price'] ?? 0);
        if ($vrp <= 0) $vrp = $vp; // fallback to absolute_price
        if ($varRegPriceMin === null || $vrp < $varRegPriceMin) $varRegPriceMin = $vrp;
        if ($varRegPriceMax === null || $vrp > $varRegPriceMax) $varRegPriceMax = $vrp;
        // Track default variant price
        if (!empty($v['is_default'])) {
            $defaultVariantPrice = $vp;
            $defaultVariantRegPrice = $vrp;
        }
    }
}
$showPriceRange = $hasVariations && $varPriceMin !== null && $varPriceMax !== null;
// Check if any variation has explicit default
$anyExplicitDefault = false;
foreach ($variants as $v) { if (($v['option_type'] ?? '') === 'variation' && !empty($v['is_default'])) $anyExplicitDefault = true; }

// For variation products: initial price = default variant price or min variation price (NEVER base product price)
if ($hasVariations && $varPriceMin !== null) {
    $initialPrice = ($anyExplicitDefault && $defaultVariantPrice !== null) ? $defaultVariantPrice : $varPriceMin;
} else {
    $initialPrice = $price;
}

// Regular/compare price for discount badge
$regPrice = 0;
if ($hasVariations) {
    // Use the max regular price across variations
    $regPrice = floatval($varRegPriceMax ?? 0);
    // Fallback: if variations don't have var_regular_price, use max variation price
    if ($regPrice <= 0) $regPrice = floatval($varPriceMax ?? 0);
} else {
    $regPrice = floatval($product['regular_price'] ?? 0);
}

// Calculate variation discount range
$varDiscountRange = null;
if ($hasVariations && $varPriceMin !== null && $regPrice > 0 && $regPrice > $varPriceMin) {
    $dMax = round(($regPrice - $varPriceMin) / $regPrice * 100);
    $dMin = ($regPrice > $varPriceMax) ? round(($regPrice - $varPriceMax) / $regPrice * 100) : 0;
    if ($dMax > 0) $varDiscountRange = ['min' => max(0, $dMin), 'max' => $dMax];
}

// Only use admin-entered meta values as overrides; auto-SEO generates from product data otherwise
$pageTitle = $product['meta_title'] ?: null;
$pageDescription = $product['meta_description'] ?: null;

// SEO: Product structured data + OG tags + auto-generated title/desc
$_prodImg = '';
if (!empty($product['featured_image'])) {
    $_prodImg = $product['featured_image'];
    if (!str_starts_with($_prodImg, 'http')) $_prodImg = SITE_URL . '/uploads/products/' . basename($_prodImg);
}
$seo = [
    'type' => 'product',
    'title' => $pageTitle ?? '',
    'description' => $pageDescription ?? '',
    'image' => $_prodImg,
    'price' => $price,
    'currency' => 'BDT',
    'product' => $product,
    'breadcrumbs' => [
        ['name' => 'হোম', 'url' => SITE_URL],
        ['name' => $product['category_name'] ?? 'Products', 'url' => SITE_URL . '/category/' . ($product['category_slug'] ?? '')],
        ['name' => $product['name_bn'] ?: $product['name']],
    ],
];

include ROOT_PATH . 'includes/header.php';

// ── FB ViewContent tracking ──
$__vcEventId = null;
try {
    if (file_exists(ROOT_PATH . 'includes/fb-capi.php')) {
        require_once ROOT_PATH . 'includes/fb-capi.php';
        if (fbCapiEnabled()) {
            $__vcEventId = fbEventId();
            fbTrackViewContent($product, $__vcEventId);
        }
    }
} catch (\Throwable $e) {}
$__vcPrice = $hasVariations ? floatval($initialPrice) : floatval($product['sale_price'] ?? $product['regular_price'] ?? 0);
$__vcEnabled = !function_exists('fbPixelEventEnabled') || fbPixelEventEnabled('ViewContent');
if ($__vcEnabled && !empty(getSetting('fb_pixel_id', '') ?: getSetting('facebook_pixel_id', '') ?: getSetting('facebook_pixel', ''))): ?>
<script>
if(typeof _fbTrack==='function') _fbTrack('ViewContent',{content_ids:['<?= $product['id'] ?>'],content_type:'product',content_name:<?= json_encode($product['name_bn'] ?? $product['name'] ?? '') ?>,value:<?= $__vcPrice ?>,currency:'BDT'}<?= $__vcEventId ? ",'{$__vcEventId}'" : '' ?>);
window.dataLayer=window.dataLayer||[];dataLayer.push({event:'view_item',ecommerce:{currency:'BDT',value:<?= $__vcPrice ?>,items:[{item_id:'<?= $product['id'] ?>',item_name:<?= json_encode($product['name_bn'] ?? $product['name'] ?? '') ?>,price:<?= $__vcPrice ?>}]}});
</script>
<?php endif;

$btnOrderLabel = getSetting('btn_order_cod_label', 'ক্যাশ অন ডেলিভারিতে অর্ডার করুন');
$btnAddLabel = getSetting('btn_add_to_cart_label', 'কার্টে যোগ করুন');
$btnBuyLabel = getSetting('btn_buy_now_label', 'এখনই কিনুন');
$btnCallLabel = getSetting('btn_call_label', 'কল করুন');
$btnWhatsappLabel = getSetting('btn_whatsapp_label', 'WhatsApp');
$sitePhone = getSetting('site_phone');
$siteWhatsapp = getSetting('site_whatsapp');

// Shop Design settings
$spShowOrder = getSetting('sp_show_order_btn', '1') === '1';
$spShowCart = getSetting('sp_show_cart_btn', '1') === '1';
$spShowBuyNow = getSetting('sp_show_buynow_btn', '1') === '1';
$spShowCall = getSetting('sp_show_call_btn', '1') === '1';
$spShowWhatsapp = getSetting('sp_show_whatsapp_btn', '1') === '1';
$spShowQty = getSetting('sp_show_qty_selector', '1') === '1';
$spShowStock = getSetting('sp_show_stock_status', '1') === '1';
$spShowDiscount = getSetting('sp_show_discount_badge', '1') === '1';
$spShowRelated = getSetting('sp_show_related', '1') === '1';
$spShowBundles = getSetting('sp_show_bundles', '1') === '1';
$spShowTabs = getSetting('sp_show_tabs', '1') === '1';
$spShowShare = getSetting('sp_show_share', '1') === '1';
$spShowWishlist = getSetting('sp_show_wishlist_btn', '1') === '1';
$isWishlisted = isInWishlist($product['id']);
$wishlistUrl = rtrim(SITE_URL, '/') . '/api/wishlist.php';
$spButtonLayout = getSetting('sp_button_layout', 'standard');

// Group variants by name (case-insensitive to merge "packaging" + "Packaging")
$variantGroups = [];
foreach ($variants as $v) {
    $key = mb_strtolower(trim($v['variant_name']));
    if (!isset($variantGroups[$key])) {
        $variantGroups[$key] = ['display_name' => $v['variant_name'], 'items' => []];
    }
    $variantGroups[$key]['items'][] = $v;
}
?>

<main class="max-w-7xl mx-auto px-4 py-4 sm:py-8">
    <!-- Breadcrumbs -->
    <nav class="text-sm text-gray-500 mb-4 flex items-center gap-2 flex-wrap">
        <a href="<?= url() ?>" class="hover:text-red-600 transition"><i class="fas fa-home"></i> Home</a>
        <i class="fas fa-chevron-right text-xs text-gray-300"></i>
        <?php if ($product['category_name']): ?>
        <a href="<?= url('category/' . $product['category_slug']) ?>" class="hover:text-red-600 transition"><?= htmlspecialchars($product['category_name']) ?></a>
        <i class="fas fa-chevron-right text-xs text-gray-300"></i>
        <?php endif; ?>
        <span class="text-gray-700 truncate max-w-xs"><?= htmlspecialchars($product['name']) ?></span>
    </nav>
    
    <!-- Product Detail Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-10">
        <!-- Product Images -->
        <div>
            <div class="relative bg-white rounded-2xl overflow-hidden border border-gray-100 shadow-sm group/slider">
                <?php if ($isOnSale && $discount > 0): ?>
                <span class="absolute top-3 left-3 sale-badge text-sm font-bold px-3 py-1 rounded-full z-10">-<?= $discount ?>%</span>
                <?php endif; ?>
                
                <?php 
                $mainImage = !empty($images) ? imgSrc('products', $images[0]['image_path']) : getProductImage($product);
                $autoSlide = intval($product['image_slideshow'] ?? 0);
                ?>
                <img id="main-product-image" src="<?= $mainImage ?>" alt="<?= htmlspecialchars($product['name']) ?>" 
                     class="w-full aspect-square object-contain p-4 cursor-zoom-in transition-opacity duration-300"
                     onclick="openImageViewer(this.src)"
                     onerror="this.src='<?= asset('img/default-product.svg') ?>'">
                
                <?php if (count($images) > 1): ?>
                <!-- Slider Arrows -->
                <button onclick="slideImage(-1)" class="absolute left-2 top-1/2 -translate-y-1/2 w-9 h-9 bg-white/80 hover:bg-white rounded-full shadow flex items-center justify-center text-gray-600 hover:text-gray-900 transition opacity-0 group-hover/slider:opacity-100 z-10">
                    <i class="fas fa-chevron-left text-sm"></i>
                </button>
                <button onclick="slideImage(1)" class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 bg-white/80 hover:bg-white rounded-full shadow flex items-center justify-center text-gray-600 hover:text-gray-900 transition opacity-0 group-hover/slider:opacity-100 z-10">
                    <i class="fas fa-chevron-right text-sm"></i>
                </button>
                <!-- Dot indicators -->
                <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-1.5 z-10">
                    <?php foreach ($images as $di => $dimg): ?>
                    <span class="slide-dot w-2 h-2 rounded-full transition-all cursor-pointer <?= $di === 0 ? 'bg-red-500 w-4' : 'bg-gray-300' ?>" onclick="goToSlide(<?= $di ?>)"></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (count($images) > 1): ?>
            <div class="flex gap-2 mt-3 overflow-x-auto scrollbar-hide">
                <?php foreach ($images as $i => $img): ?>
                <button onclick="goToSlide(<?= $i ?>)"
                        class="slide-thumb flex-shrink-0 w-16 h-16 sm:w-20 sm:h-20 rounded-lg border-2 overflow-hidden transition <?= $i === 0 ? 'border-red-400' : 'border-gray-200 hover:border-red-400' ?>">
                    <img src="<?= imgSrc('products', $img['image_path']) ?>" alt="" class="w-full h-full object-cover">
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Product Info -->
        <div>
            <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-800 leading-tight product-detail-title">
                <?= htmlspecialchars($product['name_bn'] ?: $product['name']) ?>
            </h1>
            
            <!-- Price Block -->
            <div class="mt-4 p-4 bg-gradient-to-r from-gray-50 to-white rounded-xl border border-gray-100">
                <div class="flex items-baseline gap-3 flex-wrap">
                    <?php if ($showPriceRange): ?>
                    <!-- Variation product: show price range -->
                    <span id="display-price" class="text-3xl font-extrabold price-animate product-detail-price" style="color:var(--price-color, var(--primary))" data-unit-price="<?= $initialPrice ?>"><?php
                        echo ($varPriceMin == $varPriceMax) ? formatPrice($varPriceMin) : formatPrice($varPriceMin) . ' – ' . formatPrice($varPriceMax);
                    ?></span>
                    <?php if ($varDiscountRange): ?>
                    <span id="display-regular-price" class="text-lg text-gray-400 line-through price-animate"><?= formatPrice($regPrice) ?></span>
                    <?php if ($spShowDiscount): ?>
                    <span id="display-discount" class="sale-badge text-sm font-bold px-2.5 py-0.5 rounded-full price-animate"><?php
                        if ($varDiscountRange['min'] === $varDiscountRange['max'] || $varDiscountRange['min'] <= 0) {
                            echo $varDiscountRange['max'] . '% ছাড়';
                        } else {
                            echo $varDiscountRange['min'] . '–' . $varDiscountRange['max'] . '% ছাড়';
                        }
                    ?></span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span id="display-regular-price" class="text-lg text-gray-400 line-through price-animate hidden"></span>
                    <span id="display-discount" class="sale-badge text-sm font-bold px-2.5 py-0.5 rounded-full price-animate hidden"></span>
                    <?php endif; ?>
                    <?php elseif ($isOnSale): ?>
                    <!-- Non-variation product on sale -->
                    <span id="display-price" class="text-3xl font-extrabold price-animate product-detail-price" style="color:var(--price-color, var(--primary))" data-unit-price="<?= $price ?>"><?= formatPrice($price) ?></span>
                    <span id="display-regular-price" class="text-lg text-gray-400 line-through price-animate"><?= formatPrice($product['regular_price']) ?></span>
                    <?php if ($spShowDiscount): ?>
                    <span id="display-discount" class="sale-badge text-sm font-bold px-2.5 py-0.5 rounded-full price-animate"><?= $discount ?>% ছাড়</span>
                    <?php endif; ?>
                    <?php else: ?>
                    <!-- Regular price only -->
                    <span id="display-price" class="text-3xl font-extrabold price-animate product-detail-price" style="color:var(--price-color, var(--primary))" data-unit-price="<?= $initialPrice ?>"><?= formatPrice($initialPrice) ?></span>
                    <span id="display-regular-price" class="text-lg text-gray-400 line-through price-animate hidden"></span>
                    <span id="display-discount" class="sale-badge text-sm font-bold px-2.5 py-0.5 rounded-full price-animate hidden"></span>
                    <?php endif; ?>
                </div>
                
                <?php if ($showPriceRange && !$anyExplicitDefault): ?>
                <p id="price-range-hint" class="text-xs text-orange-500 mt-1"><i class="fas fa-info-circle mr-1"></i>ভ্যারিয়েশন সিলেক্ট করুন</p>
                <?php endif; ?>
                
                <!-- Per-unit info for variant -->
                <p id="price-info-line" class="text-xs text-gray-400 mt-1 hidden price-animate"></p>
                
                <!-- Store Credit Earning Info (logged-in customers only) -->
                <?php if (isCustomerLoggedIn() && !empty($product['store_credit_enabled']) && floatval($product['store_credit_amount'] ?? 0) >= 1): 
                    $earnCredits = floatval($product['store_credit_amount']);
                    $earnRate = floatval(getSetting('store_credit_conversion_rate', '0.75'));
                    if ($earnRate <= 0) $earnRate = 0.75;
                    $earnTk = round($earnCredits * $earnRate, 2);
                ?>
                <div class="mt-2 inline-flex items-center gap-1.5 bg-yellow-50 border border-yellow-200 rounded-lg px-3 py-1.5">
                    <i class="fas fa-coins text-yellow-500 text-sm"></i>
                    <span class="text-xs text-yellow-700 font-medium">ডেলিভারির পর পাবেন <strong><?= number_format($earnCredits, 0) ?> ক্রেডিট</strong> <span class="text-yellow-500">(৳<?= number_format($earnTk, 0) ?> সমপরিমাণ)</span></span>
                </div>
                <?php endif; ?>
                
                <!-- Stock Status -->
                <?php if ($spShowStock): ?>
                <div class="mt-2.5 flex items-center gap-2 text-sm">
                    <?php if ($product['stock_status'] === 'in_stock'): ?>
                    <span class="w-2.5 h-2.5 rounded-full bg-green-500 animate-pulse"></span>
                    <span class="text-green-600 font-medium" id="stock-status">স্টকে আছে</span>
                    <?php else: ?>
                    <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
                    <span class="text-red-600 font-medium" id="stock-status">স্টক শেষ</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Addons & Variations -->
            <?php if (!empty($variantGroups)): ?>
            <div class="mt-4" id="variant-section">
                <?php foreach ($variantGroups as $groupKey => $groupData): 
                    $groupName = $groupData['display_name'];
                    $groupVariants = $groupData['items'];
                    $optType = $groupVariants[0]['option_type'] ?? 'addon';
                    $groupId = md5($groupKey);
                    // Check if any variant in this group has is_default=1
                    $hasExplicitDefault = false;
                    foreach ($groupVariants as $gv) { if (!empty($gv['is_default'])) $hasExplicitDefault = true; }
                ?>
                <div class="mb-3">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700">
                            <?= htmlspecialchars($groupName) ?>
                            <?php if ($optType === 'variation'): ?>
                                <span class="text-xs text-purple-500 font-normal">(ভ্যারিয়েশন)</span>
                            <?php else: ?>
                                <span class="text-xs text-blue-500 font-normal">(অ্যাডঅন)</span>
                            <?php endif; ?>
                        </label>
                        <?php if ($optType !== 'variation'): ?>
                        <button type="button" onclick="clearAddonGroup('variant_group_<?= $groupId ?>')" 
                                class="addon-clear-btn text-xs text-gray-400 hover:text-red-500 transition hidden" 
                                id="clear-btn-<?= $groupId ?>">
                            <i class="fas fa-times mr-0.5"></i>বাদ দিন
                        </button>
                        <?php else: ?>
                        <button type="button" onclick="clearAddonGroup('variant_group_<?= $groupId ?>')" 
                                class="addon-clear-btn text-xs text-gray-400 hover:text-red-500 transition hidden" 
                                id="clear-btn-<?= $groupId ?>">
                            <i class="fas fa-times mr-0.5"></i>বাদ দিন
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($groupVariants as $i => $v): 
                            $isVariation = ($v['option_type'] ?? 'addon') === 'variation';
                            $varImg = $v['variant_image'] ?? '';
                            $varImgUrl = $varImg ? (SITE_URL . '/uploads/products/' . $varImg) : '';
                            // Only pre-select if is_default is explicitly set on THIS variant
                            $shouldCheck = !empty($v['is_default']);
                        ?>
                        <label class="variant-option inline-flex items-center border-2 rounded-xl px-4 py-2.5 cursor-pointer has-[:checked]:border-red-500 has-[:checked]:bg-red-50 hover:border-gray-400 transition <?= $v['stock_quantity'] <= 0 ? 'opacity-50' : '' ?>">
                            <input type="radio" name="variant_group_<?= $groupId ?>" 
                                   value="<?= $v['id'] ?>" 
                                   data-option-type="<?= $isVariation ? 'variation' : 'addon' ?>"
                                   data-price-adj="<?= $v['price_adjustment'] ?>"
                                   data-abs-price="<?= $v['absolute_price'] ?? 0 ?>"
                                   data-var-regular="<?= $v['var_regular_price'] ?? $v['absolute_price'] ?? 0 ?>"
                                   data-var-sale="<?= $v['var_sale_price'] ?? 0 ?>"
                                   data-stock="<?= $v['stock_quantity'] ?>"
                                   data-value="<?= htmlspecialchars($v['variant_value']) ?>"
                                   data-group-id="<?= $groupId ?>"
                                   data-variant-image="<?= e($varImgUrl) ?>"
                                   class="hidden product-variant-radio" 
                                   <?= $shouldCheck ? 'checked' : '' ?>
                                   <?= $v['stock_quantity'] <= 0 ? 'disabled' : '' ?>
                                   onchange="onVariantChange(true)">
                            <?php if ($varImg): ?>
                            <img src="<?= $varImgUrl ?>" class="w-6 h-6 rounded object-cover mr-2 border">
                            <?php endif; ?>
                            <span class="text-sm font-medium">
                                <?= htmlspecialchars($v['variant_value']) ?>
                                <?php if ($isVariation && $v['absolute_price']): 
                                    $vVarReg = floatval($v['var_regular_price'] ?? 0);
                                    $vVarSale = floatval($v['var_sale_price'] ?? 0);
                                    if ($vVarReg > 0 && $vVarSale > 0 && $vVarSale < $vVarReg): ?>
                                    <span class="text-xs text-gray-400 line-through"><?= formatPrice($vVarReg) ?></span>
                                    <span class="text-xs text-green-600 font-semibold"><?= formatPrice($vVarSale) ?></span>
                                    <?php else: ?>
                                    <span class="text-xs text-gray-500">(<?= formatPrice($v['absolute_price']) ?>)</span>
                                    <?php endif; ?>
                                <?php elseif (!$isVariation && $v['price_adjustment'] > 0): ?>
                                    <span class="text-xs text-gray-500">(+<?= formatPrice($v['price_adjustment']) ?>)</span>
                                <?php elseif (!$isVariation && $v['price_adjustment'] < 0): ?>
                                    <span class="text-xs text-green-600">(<?= formatPrice($v['price_adjustment']) ?>)</span>
                                <?php endif; ?>
                                <?php if ($v['stock_quantity'] <= 0): ?>
                                    <span class="text-xs text-red-400">(স্টক শেষ)</span>
                                <?php endif; ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Quantity -->
            <div class="mt-4">
            <!-- Quantity Selector -->
            <?php if ($spShowQty): ?>
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">পরিমাণ</label>
                <div class="inline-flex items-center border-2 rounded-xl overflow-hidden">
                    <button onclick="changeQty(-1)" class="w-10 h-10 flex items-center justify-center hover:bg-gray-100 transition text-gray-600">
                        <i class="fas fa-minus text-sm"></i>
                    </button>
                    <input type="number" id="product-qty" value="1" min="1" max="99" oninput="updateDisplayedPrice()"
                           class="w-14 h-10 text-center border-x font-semibold focus:outline-none">
                    <button onclick="changeQty(1)" class="w-10 h-10 flex items-center justify-center hover:bg-gray-100 transition text-gray-600">
                        <i class="fas fa-plus text-sm"></i>
                    </button>
                </div>
            </div>
            <?php else: ?>
            <input type="hidden" id="product-qty" value="1">
            <?php endif; ?>
            
            <?php
            // Customer Upload Widget
            $custUploadEnabled = !empty($product['require_customer_upload']);
            $custUploadLabel = $product['customer_upload_label'] ?? 'আপনার ছবি/ডকুমেন্ট আপলোড করুন';
            $custUploadRequired = !empty($product['customer_upload_required']);
            ?>
            <?php if ($custUploadEnabled): ?>
            <div class="mt-4" id="customerUploadSection">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <?= e($custUploadLabel) ?>
                    <?php if ($custUploadRequired): ?><span class="text-red-500">*</span><?php endif; ?>
                </label>
                <div id="custUploadArea" class="border-2 border-dashed border-gray-300 rounded-xl p-4 text-center cursor-pointer hover:border-purple-400 transition" onclick="document.getElementById('custFileInput').click()">
                    <div id="custUploadPlaceholder">
                        <i class="fas fa-cloud-upload-alt text-gray-300 text-3xl mb-2"></i>
                        <p class="text-gray-400 text-sm">ছবি বা ডকুমেন্ট আপলোড করতে ক্লিক করুন</p>
                        <p class="text-gray-300 text-xs mt-1">JPG, PNG, PDF · সর্বোচ্চ 10MB</p>
                    </div>
                    <div id="custUploadPreview" class="hidden">
                        <img id="custPreviewImg" class="w-24 h-24 object-cover rounded-lg mx-auto border-2 border-purple-300" src="">
                        <p id="custPreviewName" class="text-xs text-gray-500 mt-2 truncate"></p>
                        <button type="button" onclick="event.stopPropagation();removeCustUpload()" class="mt-1 text-xs text-red-500 hover:text-red-700"><i class="fas fa-trash mr-1"></i>সরিয়ে ফেলুন</button>
                    </div>
                    <div id="custUploadLoading" class="hidden py-3">
                        <i class="fas fa-spinner fa-spin text-purple-500 text-xl"></i>
                        <p class="text-purple-500 text-sm mt-1">আপলোড হচ্ছে...</p>
                    </div>
                </div>
                <input type="file" id="custFileInput" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" class="hidden" onchange="handleCustUpload(this)">
                <input type="hidden" id="custUploadFile" value="">
                <?php if ($custUploadRequired): ?>
                <p id="custUploadError" class="text-red-500 text-xs mt-1 hidden"><i class="fas fa-exclamation-circle mr-1"></i>অনুগ্রহ করে ছবি/ডকুমেন্ট আপলোড করুন</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="mt-6 space-y-3">
                <?php if ($spButtonLayout === 'standard'): ?>
                    <?php if ($spShowOrder): ?>
                    <button onclick="productOrder()" id="btn-order"
                            class="cod-order-btn w-full py-3.5 rounded-xl text-white font-bold text-base transition transform active:scale-[0.98] flex items-center justify-center gap-2 order-btn-text"
                            style="background:var(--btn-primary)"
                            <?= $product['stock_status'] === 'out_of_stock' ? 'disabled' : '' ?>>
                        <i class="fas fa-shopping-bag"></i>
                        <?= $btnOrderLabel ?>
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($spShowCart || $spShowBuyNow): ?>
                    <div class="grid <?= ($spShowCart && $spShowBuyNow) ? 'grid-cols-2' : '' ?> gap-3">
                        <?php if ($spShowCart): ?>
                        <button onclick="productAddToCart()" id="btn-add-cart"
                                class="py-3 rounded-xl font-bold text-sm transition flex items-center justify-center gap-2 btn-cart"
                                <?= $product['stock_status'] === 'out_of_stock' ? 'disabled' : '' ?>>
                            <i class="fas fa-cart-plus"></i>
                            <?= $btnAddLabel ?>
                        </button>
                        <?php endif; ?>
                        <?php if ($spShowBuyNow): ?>
                        <button onclick="productBuyNow()" id="btn-buy-now"
                                class="py-3 rounded-xl font-bold text-sm transition flex items-center justify-center gap-2 border-2 hover:bg-gray-50"
                                style="border-color:var(--btn-primary);color:var(--btn-primary)"
                                <?= $product['stock_status'] === 'out_of_stock' ? 'disabled' : '' ?>>
                            <i class="fas fa-bolt"></i>
                            <?= $btnBuyLabel ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                <?php elseif ($spButtonLayout === 'two_buttons'): ?>
                    <?php if ($spShowOrder): ?>
                    <button onclick="productOrder()" id="btn-order"
                            class="cod-order-btn w-full py-3.5 rounded-xl text-white font-bold text-base transition transform active:scale-[0.98] flex items-center justify-center gap-2 order-btn-text"
                            style="background:var(--btn-primary)"
                            <?= $product['stock_status'] === 'out_of_stock' ? 'disabled' : '' ?>>
                        <i class="fas fa-shopping-bag"></i>
                        <?= $btnOrderLabel ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($spShowCart): ?>
                    <button onclick="productAddToCart()" id="btn-add-cart"
                            class="w-full py-3 rounded-xl font-bold text-sm transition flex items-center justify-center gap-2 btn-cart"
                            <?= $product['stock_status'] === 'out_of_stock' ? 'disabled' : '' ?>>
                        <i class="fas fa-cart-plus"></i>
                        <?= $btnAddLabel ?>
                    </button>
                    <?php endif; ?>
                    
                <?php elseif ($spButtonLayout === 'order_only'): ?>
                    <?php if ($spShowOrder): ?>
                    <button onclick="productOrder()" id="btn-order"
                            class="cod-order-btn w-full py-4 rounded-xl text-white font-bold text-lg transition transform active:scale-[0.98] flex items-center justify-center gap-2 order-btn-text"
                            style="background:var(--btn-primary)"
                            <?= $product['stock_status'] === 'out_of_stock' ? 'disabled' : '' ?>>
                        <i class="fas fa-shopping-bag"></i>
                        <?= $btnOrderLabel ?>
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
                
                <!-- Call & WhatsApp -->
                <?php if (($spShowCall && $sitePhone) || ($spShowWhatsapp && $siteWhatsapp)): ?>
                <div class="grid <?= ($spShowCall && $sitePhone && $spShowWhatsapp && $siteWhatsapp) ? 'grid-cols-2' : '' ?> gap-3">
                    <?php if ($spShowCall && $sitePhone): ?>
                    <a href="tel:<?= $sitePhone ?>" class="py-2.5 rounded-xl border-2 border-blue-500 text-blue-600 font-medium text-sm text-center hover:bg-blue-50 transition">
                        <i class="fas fa-phone-alt mr-1"></i> <?= $btnCallLabel ?>: <?= $sitePhone ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($spShowWhatsapp && $siteWhatsapp): ?>
                    <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $siteWhatsapp) ?>?text=<?= urlencode("আমি " . $product['name'] . " পণ্যটি অর্ডার করতে চাই") ?>" 
                       target="_blank" class="py-2.5 rounded-xl border-2 border-green-500 text-green-600 font-medium text-sm text-center hover:bg-green-50 transition">
                        <i class="fab fa-whatsapp mr-1"></i> <?= $btnWhatsappLabel ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Wishlist Button -->
                <?php if ($spShowWishlist): ?>
                <button
                    id="wishlist-btn"
                    onclick="toggleWishlist(this)"
                    data-product-id="<?= $product['id'] ?>"
                    data-wishlisted="<?= $isWishlisted ? '1' : '0' ?>"
                    data-wishlist-url="<?= $wishlistUrl ?>"
                    data-login-url="<?= url('login?redirect=' . urlencode(url('product/' . $product['slug']))) ?>"
                    class="w-full py-2.5 rounded-xl border-2 font-medium text-sm flex items-center justify-center gap-2 transition <?= $isWishlisted ? 'border-red-400 bg-red-50 text-red-500' : 'border-gray-300 text-gray-500 hover:border-red-300 hover:text-red-400 hover:bg-red-50' ?>">
                    <i class="<?= $isWishlisted ? 'fas' : 'far' ?> fa-heart text-base"></i>
                    <span><?= $isWishlisted ? 'উইশলিস্টে আছে' : 'উইশলিস্টে যোগ করুন' ?></span>
                </button>
                <?php endif; ?>
            </div>
            
            <!-- Product Meta -->
            <div class="mt-6 text-sm text-gray-600 space-y-2">
                <?php if ($product['sku']): ?>
                <p><span class="font-medium text-gray-700">SKU:</span> <?= htmlspecialchars($product['sku']) ?></p>
                <?php endif; ?>
                <?php if ($product['category_name']): ?>
                <p><span class="font-medium text-gray-700">Category:</span> 
                    <a href="<?= url('category/' . $product['category_slug']) ?>" class="text-red-600 hover:underline"><?= htmlspecialchars($product['category_name']) ?></a>
                </p>
                <?php endif; ?>
                <?php if ($product['tags']): ?>
                <p><span class="font-medium text-gray-700">Tags:</span> 
                    <?php foreach (explode(',', $product['tags']) as $tag): ?>
                    <a href="<?= url('search?q=' . urlencode(trim($tag))) ?>" class="inline-block bg-gray-100 rounded-full px-3 py-0.5 mr-1 mb-1 text-xs hover:bg-red-50 hover:text-red-600 transition">
                        <?= htmlspecialchars(trim($tag)) ?>
                    </a>
                    <?php endforeach; ?>
                </p>
                <?php endif; ?>
            </div>
            
            <!-- Shipping Info Cards -->
            <div class="mt-6 grid grid-cols-3 gap-2 text-center text-xs">
                <div class="bg-green-50 rounded-lg p-3">
                    <i class="fas fa-truck text-green-600 text-lg mb-1"></i>
                    <p class="font-medium text-green-800">দ্রুত ডেলিভারি</p>
                </div>
                <div class="bg-blue-50 rounded-lg p-3">
                    <i class="fas fa-shield-alt text-blue-600 text-lg mb-1"></i>
                    <p class="font-medium text-blue-800">নিরাপদ পেমেন্ট</p>
                </div>
                <div class="bg-orange-50 rounded-lg p-3">
                    <i class="fas fa-undo text-orange-600 text-lg mb-1"></i>
                    <p class="font-medium text-orange-800">ইজি রিটার্ন</p>
                </div>
            </div>
            
            <!-- Bundle Deal -->
            <?php if ($spShowBundles && !empty($bundles)): ?>
            <?php
            // ── Pre-calculate ALL bundle prices server-side ──
            $mainRegular = $hasVariations ? floatval($regPrice) : floatval($product['regular_price']);
            $mainSale = $hasVariations ? floatval($initialPrice) : $price; // use variation price if applicable
            
            // "Separate" = what customer pays buying each item individually at current prices
            // "Bundle" = what customer pays with bundle discounts applied
            $bundleSeparate = $mainSale; // Current selling price (NOT regular)
            $bundleTotal = $mainSale;    // Same starting point — main has no bundle discount
            
            $bundleItemsData = [];
            foreach ($bundles as $b) {
                $bRegular = floatval($b['regular_price']);
                $bSelling = ($b['sale_price'] && $b['sale_price'] > 0 && $b['sale_price'] < $b['regular_price']) 
                    ? floatval($b['sale_price']) : $bRegular;
                $bQty = intval($b['bundle_qty']);
                
                // Bundle discount applies on the SELLING price
                $bBundleDiscount = 0;
                if ($b['discount_type'] === 'percentage') {
                    $bBundleDiscount = round(($bSelling * floatval($b['discount_value'])) / 100, 2);
                } else {
                    $bBundleDiscount = min(floatval($b['discount_value']), $bSelling); // Can't exceed price
                }
                $bFinalUnit = round(max(0, $bSelling - $bBundleDiscount), 2);
                $bFinalTotal = $bFinalUnit * $bQty;
                
                $bundleSeparate += $bSelling * $bQty;  // Without bundle discount
                $bundleTotal += $bFinalTotal;           // With bundle discount
                
                $b['_regular'] = $bRegular;
                $b['_selling'] = $bSelling;
                $b['_bundle_discount'] = $bBundleDiscount;
                $b['_final_unit'] = $bFinalUnit;
                $b['_final_total'] = $bFinalTotal;
                $bundleItemsData[] = $b;
            }
            
            // Savings = ONLY what the bundle discount gives (not sale discounts)
            $bundleSaved = $bundleSeparate - $bundleTotal;
            $discountPct = $bundleSeparate > 0 ? ($bundleSaved / $bundleSeparate) * 100 : 0;
            $bundleName = $product['bundle_name'] ?? '';
            if (!$bundleName) $bundleName = ($product['name_bn'] ?: $product['name']) . ' বান্ডেল';
            ?>
            <div class="mt-6 border-2 border-dashed border-green-300 rounded-2xl p-4 bg-green-50/50">
                <h3 class="font-bold text-green-800 mb-3 flex items-center gap-2">
                    <span class="w-7 h-7 rounded-lg bg-green-100 flex items-center justify-center"><i class="fas fa-gift text-green-600 text-sm"></i></span>
                    একসাথে কিনুন, সেভ করুন!
                </h3>
                <div class="space-y-3">
                    <!-- Main product -->
                    <div class="flex items-center gap-3 bg-white rounded-xl p-3 border border-green-200">
                        <img src="<?= getProductImage($product) ?>" class="w-14 h-14 rounded-lg object-cover border">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold truncate"><?= htmlspecialchars($product['name_bn'] ?: $product['name']) ?></p>
                            <p class="text-xs text-gray-500">মূল পণ্য</p>
                        </div>
                        <span class="text-sm font-bold" style="color:var(--price-color, var(--primary))"><?= formatPrice($mainSale) ?></span>
                    </div>
                    <div class="text-center text-gray-400"><i class="fas fa-plus"></i></div>
                    <?php foreach ($bundleItemsData as $b): ?>
                    <div class="flex items-center gap-3 bg-white rounded-xl p-3 border border-green-200">
                        <img src="<?= $b['featured_image'] ? imgSrc('products', $b['featured_image']) : asset('img/default-product.svg') ?>" 
                             class="w-14 h-14 rounded-lg object-cover border">
                        <div class="flex-1 min-w-0">
                            <a href="<?= url('product/' . $b['slug']) ?>" class="text-sm font-semibold truncate block hover:text-red-600">
                                <?= htmlspecialchars($b['name_bn'] ?: $b['name']) ?>
                            </a>
                            <p class="text-xs text-gray-500"><?= $b['bundle_qty'] ?>x 
                                <?php if ($b['_bundle_discount'] > 0): ?>
                                    <span class="line-through text-gray-400"><?= formatPrice($b['_selling']) ?></span>
                                    <span class="text-green-600 font-medium"><?= formatPrice($b['_final_unit']) ?></span>
                                <?php else: ?>
                                    <?= formatPrice($b['_selling']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="text-sm font-bold" style="color:var(--price-color, var(--primary))"><?= formatPrice($b['_final_total']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Bundle total with before/after -->
                <div class="mt-4 bg-green-100 rounded-xl p-3.5 flex items-center justify-between">
                    <div>
                        <?php if ($bundleSaved > 0): ?>
                        <div class="flex items-center gap-2 mb-0.5">
                            <span class="text-sm text-gray-500 line-through"><?= formatPrice($bundleSeparate) ?></span>
                            <span class="px-1.5 py-0.5 bg-red-500 text-white text-[10px] font-bold rounded"><?= ($discountPct >= 1) ? round($discountPct) : number_format($discountPct, 1) ?>% OFF</span>
                        </div>
                        <p class="text-lg font-extrabold text-green-800"><?= formatPrice($bundleTotal) ?></p>
                        <p class="text-xs text-green-600 font-medium">বান্ডেলে সেভ <?= formatPrice($bundleSaved) ?>!</p>
                        <?php else: ?>
                        <p class="text-lg font-extrabold text-green-800">বান্ডেল মূল্য: <?= formatPrice($bundleTotal) ?></p>
                        <?php endif; ?>
                    </div>
                    <button onclick="addBundleToCart()" 
                            class="px-5 py-2.5 bg-green-600 text-white rounded-xl text-sm font-bold hover:bg-green-700 transition shadow-sm">
                        <i class="fas fa-cart-plus mr-1"></i> বান্ডেল কিনুন
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Product Description / Reviews / Q&A -->
    <?php include ROOT_PATH . 'includes/product-reviews.php'; ?>
    
    <!-- Related Products -->
    <?php if ($spShowRelated && !empty($relatedProducts)): ?>
    <section class="mt-10">
        <h2 class="text-xl font-bold mb-5 flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg flex items-center justify-center bg-purple-100"><i class="fas fa-th text-sm text-purple-500"></i></span>
            সম্পর্কিত পণ্য
        </h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 sm:gap-4">
            <?php $__mainProduct = $product; foreach (array_slice($relatedProducts, 0, 4) as $product): ?>
                <?php include ROOT_PATH . 'includes/product-card.php'; ?>
            <?php endforeach; $product = $__mainProduct; ?>
        </div>
    </section>
    <?php endif; ?>
</main>

<!-- Image Viewer Modal -->
<div id="image-viewer" class="hidden fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4 cursor-pointer" onclick="this.classList.add('hidden')">
    <button class="absolute top-4 right-4 text-white text-2xl"><i class="fas fa-times"></i></button>
    <img id="viewer-image" src="" class="max-w-full max-h-full object-contain">
</div>

<?php if ($hasVariations): ?>
<!-- Variation Picker Popup -->
<div id="varPickerOverlay" class="hidden fixed inset-0 z-[60] bg-black/50" onclick="if(event.target===this)closeVarPicker()">
    <div class="absolute inset-0 flex items-end sm:items-center justify-center sm:px-4" onclick="if(event.target===this)closeVarPicker()">
    <div class="vp-sheet bg-white w-full sm:max-w-md sm:rounded-2xl rounded-t-2xl max-h-[85vh] sm:max-h-[80vh] flex flex-col">
        <!-- Drag handle mobile -->
        <div class="sm:hidden flex justify-center pt-2 flex-shrink-0"><div class="w-10 h-1 rounded-full bg-gray-300"></div></div>
        <!-- Header -->
        <div class="flex items-center justify-between px-5 pt-3 pb-3 border-b border-gray-100 flex-shrink-0">
            <h3 class="text-base font-bold text-gray-800">ভ্যারিয়েশন সিলেক্ট করুন</h3>
            <button onclick="closeVarPicker()" class="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-400"><i class="fas fa-times"></i></button>
        </div>
        
        <!-- Scrollable body -->
        <div class="flex-1 overflow-y-auto px-5 py-4 overscroll-contain" style="-webkit-overflow-scrolling:touch">
            <!-- Product preview -->
            <div class="flex items-center gap-3 mb-4 pb-4 border-b">
                <img src="<?= $mainImage ?>" class="w-16 h-16 rounded-lg object-cover border flex-shrink-0">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-gray-800 vp-clamp-2"><?= htmlspecialchars($product['name_bn'] ?: $product['name']) ?></p>
                    <div class="flex items-baseline gap-2 flex-wrap mt-1">
                        <p id="varPickerPrice" class="text-sm font-bold" style="color:var(--price-color, var(--primary))"><?php
                            if ($showPriceRange) {
                                echo ($varPriceMin == $varPriceMax) ? formatPrice($varPriceMin) : formatPrice($varPriceMin) . ' – ' . formatPrice($varPriceMax);
                            } else {
                                echo formatPrice($price);
                            }
                        ?></p>
                        <?php if ($varDiscountRange): ?>
                        <span id="varPickerRegPrice" class="text-xs text-gray-400 line-through"><?= formatPrice($regPrice) ?></span>
                        <span id="varPickerDiscount" class="text-xs font-bold text-red-500"><?php
                            if ($varDiscountRange['min'] === $varDiscountRange['max'] || $varDiscountRange['min'] <= 0) {
                                echo '-' . $varDiscountRange['max'] . '%';
                            } else {
                                echo '-' . $varDiscountRange['min'] . '~' . $varDiscountRange['max'] . '%';
                            }
                        ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Variation groups (only variation type, not addons) -->
            <?php foreach ($variantGroups as $groupKey => $groupData):
                $gVariants = $groupData['items'];
                $gOptType = $gVariants[0]['option_type'] ?? 'addon';
                if ($gOptType !== 'variation') continue;
                $gId = md5($groupKey);
            ?>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars($groupData['display_name']) ?></label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($gVariants as $gv):
                        $gvImg = $gv['variant_image'] ?? '';
                        $gvImgUrl = $gvImg ? (SITE_URL . '/uploads/products/' . $gvImg) : '';
                        $gvOos = $gv['stock_quantity'] <= 0;
                    ?>
                    <label class="inline-flex items-center border-2 rounded-xl px-4 py-2.5 cursor-pointer has-[:checked]:border-red-500 has-[:checked]:bg-red-50 hover:border-gray-400 transition <?= $gvOos ? 'opacity-50' : '' ?>">
                        <input type="radio" name="vp_group_<?= $gId ?>" 
                               value="<?= $gv['id'] ?>"
                               data-abs-price="<?= $gv['absolute_price'] ?? 0 ?>"
                               data-main-radio="variant_group_<?= $gId ?>"
                               data-main-val="<?= $gv['id'] ?>"
                               class="hidden vp-radio"
                               <?= $gvOos ? 'disabled' : '' ?>
                               onchange="onVarPickerChange()">
                        <?php if ($gvImg): ?>
                        <img src="<?= $gvImgUrl ?>" class="w-6 h-6 rounded object-cover mr-2 border">
                        <?php endif; ?>
                        <span class="text-sm font-medium">
                            <?= htmlspecialchars($gv['variant_value']) ?>
                            <?php if ($gv['absolute_price']): ?>
                            <span class="text-xs text-gray-500">(<?= formatPrice($gv['absolute_price']) ?>)</span>
                            <?php endif; ?>
                            <?php if ($gvOos): ?>
                            <span class="text-xs text-red-400">(স্টক শেষ)</span>
                            <?php endif; ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Fixed bottom button -->
        <div class="vp-bottom-btn px-5 pt-3 border-t border-gray-100 flex-shrink-0 bg-white sm:rounded-b-2xl">
            <button onclick="confirmVarPicker()" id="varPickerConfirmBtn"
                    class="w-full py-3.5 rounded-xl text-white font-bold text-base transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                    style="background:var(--btn-primary)" disabled>
                <i class="fas fa-check-circle"></i>
                <span id="varPickerBtnText">ভ্যারিয়েশন সিলেক্ট করুন</span>
            </button>
        </div>
    </div>
    </div>
</div>
<style>
#varPickerOverlay .vp-sheet {
    transform: translateY(100%);
    transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1);
}
#varPickerOverlay.vp-open .vp-sheet { transform: translateY(0); }
.vp-bottom-btn { padding-bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px)); }
.vp-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
@media (min-width: 640px) {
    #varPickerOverlay .vp-sheet {
        transform: translateY(20px); opacity: 0;
        transition: transform 0.25s ease-out, opacity 0.25s ease-out;
    }
    #varPickerOverlay.vp-open .vp-sheet { transform: translateY(0); opacity: 1; }
}
</style>
<?php endif; ?>

<script>
// ── Product Page Config ──
const PRODUCT_ID = <?= $product['id'] ?? 0 ?>;
const BASE_PRICE = <?= $hasVariations ? ($initialPrice ?: $varPriceMin ?: $price) : $price ?>;
const REGULAR_PRICE = <?= $hasVariations ? ($regPrice ?: ($product['regular_price'] ?? 0)) : ($product['regular_price'] ?? 0) ?>;
const HAS_VARIANTS = <?= !empty($variants) ? 'true' : 'false' ?>;
const VAR_PRICE_MIN = <?= $varPriceMin ?? 0 ?>;
const VAR_PRICE_MAX = <?= $varPriceMax ?? 0 ?>;
// Store original image for reverting when no variant image selected
window._originalProductImage = document.getElementById('main-product-image')?.src || '';
const BUNDLE_PRODUCTS = <?= json_encode(!empty($bundles)) ?>; // just true/false for existence check

// ── Image Slider ──
const SLIDE_IMAGES = <?= json_encode(array_map(function($img) { return imgSrc('products', $img['image_path']); }, $images)) ?>;
const AUTO_SLIDE = <?= $autoSlide ? 'true' : 'false' ?>;
let _slideIdx = 0;
let _slideTimer = null;

function goToSlide(idx) {
    if (!SLIDE_IMAGES.length) return;
    _slideIdx = ((idx % SLIDE_IMAGES.length) + SLIDE_IMAGES.length) % SLIDE_IMAGES.length;
    const mainImg = document.getElementById('main-product-image');
    mainImg.style.opacity = '0.3';
    setTimeout(() => {
        mainImg.src = SLIDE_IMAGES[_slideIdx];
        mainImg.style.opacity = '1';
    }, 150);
    // Update dots
    document.querySelectorAll('.slide-dot').forEach((d, i) => {
        d.classList.toggle('bg-red-500', i === _slideIdx);
        d.classList.toggle('w-4', i === _slideIdx);
        d.classList.toggle('bg-gray-300', i !== _slideIdx);
        d.classList.toggle('w-2', i !== _slideIdx);
    });
    // Update thumbnails
    document.querySelectorAll('.slide-thumb').forEach((t, i) => {
        t.classList.toggle('border-red-400', i === _slideIdx);
        t.classList.toggle('border-gray-200', i !== _slideIdx);
    });
    resetAutoSlide();
}
function slideImage(dir) { goToSlide(_slideIdx + dir); }
function resetAutoSlide() {
    if (_slideTimer) clearInterval(_slideTimer);
    if (AUTO_SLIDE && SLIDE_IMAGES.length > 1) {
        _slideTimer = setInterval(() => slideImage(1), 4000);
    }
}
// Start auto-slide on load
if (AUTO_SLIDE && SLIDE_IMAGES.length > 1) {
    _slideTimer = setInterval(() => slideImage(1), 4000);
}
// Touch swipe support
(function(){
    const el = document.getElementById('main-product-image');
    if (!el || SLIDE_IMAGES.length < 2) return;
    let sx = 0;
    el.addEventListener('touchstart', e => { sx = e.touches[0].clientX; }, {passive: true});
    el.addEventListener('touchend', e => {
        const dx = e.changedTouches[0].clientX - sx;
        if (Math.abs(dx) > 40) slideImage(dx < 0 ? 1 : -1);
    }, {passive: true});
})();

function getQty() {
    return parseInt(document.getElementById('product-qty')?.value) || 1;
}
function changeQty(d) {
    const inp = document.getElementById('product-qty');
    inp.value = Math.max(1, Math.min(99, parseInt(inp.value) + d));
    updateDisplayedPrice();
}

// Recalculate displayed price based on qty
function updateDisplayedPrice() {
    // If product has variations, re-run full variant logic (handles range vs single price)
    if (HAS_VARIANTS) { onVariantChange(); return; }
    const qty = getQty();
    const priceEl = document.getElementById('display-price');
    if (!priceEl || !priceEl.dataset.unitPrice) return;
    const unitPrice = parseFloat(priceEl.dataset.unitPrice) || 0;
    priceEl.textContent = CURRENCY + ' ' + Number(unitPrice * qty).toLocaleString();
}

// Get all selected variant IDs from product page radio buttons
function getSelectedVariantId() {
    const checked = document.querySelectorAll('.product-variant-radio:checked');
    if (checked.length === 0) return null;
    const ids = [];
    checked.forEach(r => ids.push(parseInt(r.value)));
    return ids.join(',');
}

// ── Variant Price Update (Addon vs Variation) ──
// If a default variant is pre-checked on load, treat it as explicitly selected
let _variantUserClicked = (function(){
    const preChecked = document.querySelectorAll('.product-variant-radio:checked');
    for(const r of preChecked) { if(r.dataset.optionType === 'variation') return true; }
    return false;
})();
function onVariantChange(explicit) {
    if (explicit === true) _variantUserClicked = true;
    const checked = document.querySelectorAll('.product-variant-radio:checked');
    let finalPrice = BASE_PRICE;
    let hasVariation = false;
    let outOfStock = false;
    let selectedLabels = [];
    
    // Swap main product image if variant has an image
    const mainImg = document.getElementById('main-product-image');
    if (mainImg) {
        let swapped = false;
        checked.forEach(r => {
            if (!swapped && r.dataset.variantImage) {
                mainImg.src = r.dataset.variantImage;
                swapped = true;
                if (_slideTimer) clearInterval(_slideTimer); // pause auto-slide
            }
        });
        if (!swapped) {
            // Revert to current slide position
            if (SLIDE_IMAGES.length > 0) {
                mainImg.src = SLIDE_IMAGES[_slideIdx];
            } else if (window._originalProductImage) {
                mainImg.src = window._originalProductImage;
            }
            resetAutoSlide(); // resume auto-slide
        }
    }
    
    // First pass: check for variation type (replaces price)
    checked.forEach(r => {
        if (r.dataset.optionType === 'variation') {
            finalPrice = parseFloat(r.dataset.absPrice) || 0;
            hasVariation = true;
        }
        if (parseInt(r.dataset.stock) <= 0) outOfStock = true;
        selectedLabels.push(r.dataset.value);
    });
    
    // If no variation, start from base price
    if (!hasVariation) finalPrice = BASE_PRICE;
    
    // Second pass: add addon adjustments
    let addonTotal = 0;
    checked.forEach(r => {
        if (r.dataset.optionType === 'addon') {
            const adj = parseFloat(r.dataset.priceAdj) || 0;
            finalPrice += adj;
            addonTotal += adj;
        }
    });
    
    // Animate price display
    const priceEl = document.getElementById('display-price');
    const regEl = document.getElementById('display-regular-price');
    const discEl = document.getElementById('display-discount');
    const infoEl = document.getElementById('price-info-line');
    const rangeHint = document.getElementById('price-range-hint');
    
    // Pop animation on price change
    priceEl.classList.remove('price-pop');
    void priceEl.offsetWidth; // Force reflow
    priceEl.classList.add('price-pop');
    
    // Hide range hint once variation is explicitly selected by user
    if (hasVariation && _variantUserClicked && rangeHint) rangeHint.classList.add('hidden');
    
    const qty = getQty();
    priceEl.dataset.unitPrice = finalPrice;
    
    // Determine if we should show range: when no variation is actively selected
    // and we have variation options available (VAR_PRICE_MIN/MAX set from PHP)
    const showRange = !_variantUserClicked && VAR_PRICE_MIN > 0 && VAR_PRICE_MAX > 0 && VAR_PRICE_MIN !== VAR_PRICE_MAX;
    
    // Show price range when no variation chosen, single price when chosen
    if (showRange) {
        const rangeMin = Number(VAR_PRICE_MIN * qty).toLocaleString();
        const rangeMax = Number(VAR_PRICE_MAX * qty).toLocaleString();
        priceEl.textContent = CURRENCY + ' ' + rangeMin + ' – ' + CURRENCY + ' ' + rangeMax;
        // Re-show range hint if it was hidden
        if (rangeHint) rangeHint.classList.remove('hidden');
    } else {
        priceEl.textContent = CURRENCY + ' ' + Number(finalPrice * qty).toLocaleString();
        // Hide range hint when a variation is selected
        if (hasVariation && _variantUserClicked && rangeHint) rangeHint.classList.add('hidden');
    }
    
    // Show regular vs sale comparison (per-variant for variable products)
    let compareReg = REGULAR_PRICE;
    if (hasVariation) {
        // Check if selected variation has its own regular price
        checked.forEach(r => {
            if (r.dataset.optionType === 'variation') {
                const varReg = parseFloat(r.dataset.varRegular) || 0;
                if (varReg > 0) compareReg = varReg;
            }
        });
    }
    
    if (showRange) {
        // Range mode: show max discount based on price range vs max regular price
        if (REGULAR_PRICE > VAR_PRICE_MIN) {
            const dMax = Math.round(((REGULAR_PRICE - VAR_PRICE_MIN) / REGULAR_PRICE) * 100);
            if (regEl) { regEl.textContent = CURRENCY + ' ' + Number(REGULAR_PRICE).toLocaleString(); regEl.classList.remove('hidden'); }
            if (discEl && dMax > 0) { discEl.textContent = dMax + '% ছাড়'; discEl.classList.remove('hidden'); }
            else if (discEl) discEl.classList.add('hidden');
        } else {
            if (regEl) regEl.classList.add('hidden');
            if (discEl) discEl.classList.add('hidden');
        }
    } else if (hasVariation && finalPrice < compareReg) {
        const discPct = Math.round(((compareReg - finalPrice) / compareReg) * 100);
        if (regEl) { regEl.textContent = CURRENCY + ' ' + Number(compareReg).toLocaleString(); regEl.classList.remove('hidden'); }
        if (discEl && discPct > 0) { discEl.textContent = discPct + '% ছাড়'; discEl.classList.remove('hidden'); }
        else if (discEl) discEl.classList.add('hidden');
    } else if (finalPrice < REGULAR_PRICE) {
        const discPct = Math.round(((REGULAR_PRICE - finalPrice) / REGULAR_PRICE) * 100);
        if (regEl) { regEl.textContent = CURRENCY + ' ' + Number(REGULAR_PRICE).toLocaleString(); regEl.classList.remove('hidden'); }
        if (discEl && discPct > 0) { discEl.textContent = discPct + '% ছাড়'; discEl.classList.remove('hidden'); }
        else if (discEl) discEl.classList.add('hidden');
    } else {
        if (regEl) regEl.classList.add('hidden');
        if (discEl) discEl.classList.add('hidden');
    }
    
    // Show info line for addons
    if (infoEl) {
        if (addonTotal > 0) {
            infoEl.textContent = 'মূল দাম + অ্যাডঅন ' + CURRENCY + Number(addonTotal).toLocaleString() + ' = ' + CURRENCY + Number(finalPrice).toLocaleString();
            infoEl.classList.remove('hidden');
        } else if (hasVariation) {
            infoEl.textContent = selectedLabels.join(', ');
            infoEl.classList.remove('hidden');
        } else {
            infoEl.classList.add('hidden');
        }
    }
    
    // Update stock status
    const stockEl = document.getElementById('stock-status');
    const btnOrder = document.getElementById('btn-order');
    const btnAdd = document.getElementById('btn-add-cart');
    const btnBuy = document.getElementById('btn-buy-now');
    
    if (outOfStock) {
        if (stockEl) { stockEl.textContent = 'স্টক শেষ'; stockEl.className = 'text-red-600 font-medium'; }
        [btnOrder, btnAdd, btnBuy].forEach(b => { if (b) b.disabled = true; });
    } else {
        if (stockEl) { stockEl.textContent = 'স্টকে আছে'; stockEl.className = 'text-green-600 font-medium'; }
        [btnOrder, btnAdd, btnBuy].forEach(b => { if (b) b.disabled = false; });
    }
}

// ── Customer Upload ──
const CUST_UPLOAD_REQUIRED = <?= $custUploadRequired ? 'true' : 'false' ?>;
const CUST_UPLOAD_ENABLED = <?= $custUploadEnabled ? 'true' : 'false' ?>;

function handleCustUpload(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) { alert('ফাইল সাইজ 10MB এর বেশি হতে পারবে না'); input.value = ''; return; }

    document.getElementById('custUploadPlaceholder').classList.add('hidden');
    document.getElementById('custUploadPreview').classList.add('hidden');
    document.getElementById('custUploadLoading').classList.remove('hidden');

    const fd = new FormData();
    fd.append('file', file);
    fd.append('action', 'upload');

    fetch(SITE_URL + '/api/customer-upload.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            document.getElementById('custUploadLoading').classList.add('hidden');
            if (data.success) {
                document.getElementById('custUploadFile').value = data.file;
                document.getElementById('custPreviewName').textContent = data.original_name;
                const isImg = !data.file.endsWith('.pdf');
                if (isImg) {
                    document.getElementById('custPreviewImg').src = data.url;
                    document.getElementById('custPreviewImg').classList.remove('hidden');
                } else {
                    document.getElementById('custPreviewImg').classList.add('hidden');
                }
                document.getElementById('custUploadPreview').classList.remove('hidden');
                document.getElementById('custUploadArea').classList.remove('border-gray-300');
                document.getElementById('custUploadArea').classList.add('border-green-400', 'bg-green-50');
                const err = document.getElementById('custUploadError');
                if (err) err.classList.add('hidden');
            } else {
                document.getElementById('custUploadPlaceholder').classList.remove('hidden');
                alert(data.message || 'আপলোড ব্যর্থ হয়েছে');
            }
        })
        .catch(() => {
            document.getElementById('custUploadLoading').classList.add('hidden');
            document.getElementById('custUploadPlaceholder').classList.remove('hidden');
            alert('আপলোড ব্যর্থ হয়েছে');
        });
}

function removeCustUpload() {
    document.getElementById('custUploadFile').value = '';
    document.getElementById('custFileInput').value = '';
    document.getElementById('custUploadPreview').classList.add('hidden');
    document.getElementById('custUploadPlaceholder').classList.remove('hidden');
    document.getElementById('custUploadArea').classList.remove('border-green-400', 'bg-green-50');
    document.getElementById('custUploadArea').classList.add('border-gray-300');
}

function getCustUpload() {
    if (!CUST_UPLOAD_ENABLED) return null;
    const val = document.getElementById('custUploadFile')?.value || '';
    if (CUST_UPLOAD_REQUIRED && !val) {
        const err = document.getElementById('custUploadError');
        if (err) err.classList.remove('hidden');
        document.getElementById('custUploadArea')?.classList.add('border-red-400');
        return false; // signals validation failure
    }
    return val || null;
}

// ── Product Page Actions ──
const HAS_VARIATIONS = <?= $hasVariations ? 'true' : 'false' ?>;
const VAR_GROUP_COUNT = <?= $hasVariations ? count(array_filter($variantGroups, fn($g) => ($g['items'][0]['option_type'] ?? '') === 'variation')) : 0 ?>;
let _pendingAction = null; // 'order' | 'cart' | 'buynow'

function variationSelected() {
    if (!HAS_VARIATIONS) return true;
    // Check if all variation groups have a selection on the main page
    const checked = document.querySelectorAll('.product-variant-radio:checked');
    let varCount = 0;
    checked.forEach(r => { if (r.dataset.optionType === 'variation') varCount++; });
    return varCount >= VAR_GROUP_COUNT;
}

function toggleWishlist(btn) {
    const productId = btn.dataset.productId;
    const loginUrl  = btn.dataset.loginUrl;
    const apiUrl    = btn.dataset.wishlistUrl;
    const isIn      = btn.dataset.wishlisted === '1';

    // Not logged in → redirect to login
    if (!document.cookie.includes('PHPSESSID') && loginUrl) {
        // Simple check — let the API handle it authoritatively
    }

    btn.disabled = true;
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: 'action=toggle&product_id=' + productId
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        if (data.error === 'login_required') {
            window.location.href = loginUrl;
            return;
        }
        if (data.success) {
            const added = data.added;
            btn.dataset.wishlisted = added ? '1' : '0';
            const icon = btn.querySelector('i');
            const label = btn.querySelector('span');
            if (added) {
                icon.className = 'fas fa-heart text-base';
                label.textContent = 'উইশলিস্টে আছে';
                btn.className = btn.className.replace('border-gray-300 text-gray-500 hover:border-red-300 hover:text-red-400 hover:bg-red-50', 'border-red-400 bg-red-50 text-red-500');
            } else {
                icon.className = 'far fa-heart text-base';
                label.textContent = 'উইশলিস্টে যোগ করুন';
                btn.className = btn.className.replace('border-red-400 bg-red-50 text-red-500', 'border-gray-300 text-gray-500 hover:border-red-300 hover:text-red-400 hover:bg-red-50');
            }
        }
    })
    .catch(() => { btn.disabled = false; });
}

function productAddToCart() {
    const upload = getCustUpload();
    if (upload === false) return;
    if (!variationSelected()) { _pendingAction = 'cart'; openVarPicker(); return; }
    const variantId = getSelectedVariantId();
    addToCartAjax(PRODUCT_ID, getQty(), variantId, upload);
}

function productOrder() {
    const upload = getCustUpload();
    if (upload === false) return;
    if (!variationSelected()) { _pendingAction = 'order'; openVarPicker(); return; }
    const variantId = getSelectedVariantId();
    openCheckoutPopup(PRODUCT_ID, getQty(), variantId, upload);
}

function productBuyNow() {
    const upload = getCustUpload();
    if (upload === false) return;
    if (!variationSelected()) { _pendingAction = 'buynow'; openVarPicker(); return; }
    const variantId = getSelectedVariantId();
    addToCartAjax(PRODUCT_ID, getQty(), variantId, upload);
    setTimeout(() => showCheckoutModal(), 600);
}

// ── Variation Picker Popup ──
function openVarPicker() {
    const overlay = document.getElementById('varPickerOverlay');
    if (!overlay) return;
    
    // Check if we can auto-select (single option per group → skip popup entirely)
    const vpRadios = overlay.querySelectorAll('.vp-radio:not(:disabled)');
    const vpGroups = {};
    vpRadios.forEach(r => {
        if (!vpGroups[r.name]) vpGroups[r.name] = [];
        vpGroups[r.name].push(r);
    });
    const groupNames = Object.keys(vpGroups);
    const allSingleOption = groupNames.every(g => vpGroups[g].length === 1);
    
    if (allSingleOption && groupNames.length > 0) {
        // Auto-select all single options and confirm immediately (skip popup)
        groupNames.forEach(g => { vpGroups[g][0].checked = true; });
        confirmVarPicker();
        return;
    }
    
    // Reset popup selections
    overlay.querySelectorAll('.vp-radio').forEach(r => r.checked = false);
    document.getElementById('varPickerConfirmBtn').disabled = true;
    document.getElementById('varPickerBtnText').textContent = 'ভ্যারিয়েশন সিলেক্ট করুন';
    overlay.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => { requestAnimationFrame(() => { overlay.classList.add('vp-open'); }); });
}

function closeVarPicker() {
    const overlay = document.getElementById('varPickerOverlay');
    if (!overlay) return;
    overlay.classList.remove('vp-open');
    setTimeout(() => { overlay.classList.add('hidden'); }, 300);
    document.body.style.overflow = '';
    _pendingAction = null;
}

function onVarPickerChange() {
    const allRadios = document.querySelectorAll('.vp-radio');
    const groups = new Set();
    const selectedGroups = new Set();
    let price = 0;
    allRadios.forEach(r => {
        groups.add(r.name);
        if (r.checked) {
            selectedGroups.add(r.name);
            price = parseFloat(r.dataset.absPrice) || price;
        }
    });
    const allSelected = selectedGroups.size >= groups.size;
    const btn = document.getElementById('varPickerConfirmBtn');
    const btnText = document.getElementById('varPickerBtnText');
    const priceEl = document.getElementById('varPickerPrice');
    const regPriceEl = document.getElementById('varPickerRegPrice');
    const discountEl = document.getElementById('varPickerDiscount');
    btn.disabled = !allSelected;
    if (allSelected) {
        btnText.textContent = 'নিশ্চিত করুন — ' + CURRENCY + ' ' + Number(price).toLocaleString();
        // Auto-confirm: skip the confirm button, go directly to action
        setTimeout(() => confirmVarPicker(), 150);
        return;
    } else {
        btnText.textContent = 'ভ্যারিয়েশন সিলেক্ট করুন';
    }
    if (price > 0 && priceEl) {
        priceEl.textContent = CURRENCY + ' ' + Number(price).toLocaleString();
        // Update discount in popup
        if (price < REGULAR_PRICE && regPriceEl && discountEl) {
            const disc = Math.round(((REGULAR_PRICE - price) / REGULAR_PRICE) * 100);
            regPriceEl.textContent = CURRENCY + ' ' + Number(REGULAR_PRICE).toLocaleString();
            discountEl.textContent = '-' + disc + '%';
            regPriceEl.classList.remove('hidden');
            discountEl.classList.remove('hidden');
        }
    }
}

function confirmVarPicker() {
    // Sync popup selections to the main page radio buttons
    document.querySelectorAll('.vp-radio:checked').forEach(r => {
        const mainName = r.dataset.mainRadio;
        const mainVal = r.dataset.mainVal;
        const mainRadio = document.querySelector(`input[name="${mainName}"][value="${mainVal}"]`);
        if (mainRadio) {
            mainRadio.checked = true;
        }
    });
    // Trigger variant change on main page to update price display
    onVariantChange(true);
    // Close popup
    document.getElementById('varPickerOverlay')?.classList.add('hidden');
    document.body.style.overflow = '';
    // Execute pending action
    const action = _pendingAction;
    _pendingAction = null;
    if (action === 'order') {
        const variantId = getSelectedVariantId();
        openCheckoutPopup(PRODUCT_ID, getQty(), variantId, getCustUpload());
    } else if (action === 'cart') {
        const variantId = getSelectedVariantId();
        addToCartAjax(PRODUCT_ID, getQty(), variantId, getCustUpload());
    } else if (action === 'buynow') {
        const variantId = getSelectedVariantId();
        addToCartAjax(PRODUCT_ID, getQty(), variantId, getCustUpload());
        setTimeout(() => showCheckoutModal(), 600);
    }
}

function openImageViewer(src) {
    document.getElementById('viewer-image').src = src;
    document.getElementById('image-viewer').classList.remove('hidden');
}

// ── Tabs ──
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => { b.classList.remove('border-b-2','border-red-500','text-red-600'); b.classList.add('text-gray-500'); });
        document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
        btn.classList.add('border-b-2','border-red-500','text-red-600');
        btn.classList.remove('text-gray-500');
        document.getElementById('tab-' + btn.dataset.tab)?.classList.remove('hidden');
    });
});
// Auto-open tab from URL hash (e.g. #tab-reviews)
if (window.location.hash) {
    const hashTab = window.location.hash.replace('#tab-', '');
    const tabBtn = document.querySelector(`.tab-btn[data-tab="${hashTab}"]`);
    if (tabBtn) { tabBtn.click(); setTimeout(() => { tabBtn.scrollIntoView({behavior:'smooth',block:'center'}); if (hashTab === 'reviews' && typeof openReviewForm === 'function') setTimeout(openReviewForm, 500); }, 300); }
}

// Initialize variant price on page load
document.addEventListener('DOMContentLoaded', () => {
    // Track product view
    try { fetch('<?= SITE_URL ?>/api/track.php', {method:'POST',body:new URLSearchParams({action:'track_product_view',product_id:'<?= $product['id'] ?>'})}).catch(()=>{}); } catch(e){}

    if (HAS_VARIANTS) onVariantChange();
    
    // Variant toggle: tap selected label to deselect it (works for BOTH addon and variation)
    document.querySelectorAll('.variant-option').forEach(label => {
        const radio = label.querySelector('.product-variant-radio');
        if (!radio) return;
        
        // Remove native onchange to prevent double-fire
        radio.removeAttribute('onchange');
        
        // Capture state BEFORE browser changes it
        label.addEventListener('mousedown', function() { radio._wasChecked = radio.checked; });
        label.addEventListener('touchstart', function() { radio._wasChecked = radio.checked; }, {passive: true});
        
        label.addEventListener('click', function(e) {
            if (radio._wasChecked) {
                // Was already selected — deselect (toggle off)
                e.preventDefault();
                radio.checked = false;
                updateClearBtnVisibility(radio.dataset.groupId);
                // If this was a variation, check if any variation is still selected
                if (radio.dataset.optionType === 'variation') {
                    const anyVariationStillSelected = [...document.querySelectorAll('.product-variant-radio:checked')]
                        .some(r => r.dataset.optionType === 'variation');
                    if (!anyVariationStillSelected) {
                        _variantUserClicked = false;
                    }
                }
                onVariantChange();
            } else {
                // New selection — let browser check it, then update after
                setTimeout(() => {
                    updateClearBtnVisibility(radio.dataset.groupId);
                    onVariantChange(true);
                }, 0);
            }
        });
    });
});

// Clear all addons in a group
function clearAddonGroup(groupName) {
    const radios = document.querySelectorAll(`input[name="${groupName}"]`);
    const hadVariation = [...radios].some(r => r.checked && r.dataset.optionType === 'variation');
    radios.forEach(r => r.checked = false);
    // Find groupId from first radio
    const first = radios[0];
    if (first && first.dataset.groupId) {
        updateClearBtnVisibility(first.dataset.groupId);
    }
    // If user cleared a variation group, reset the user-clicked flag so price range re-appears
    if (hadVariation) {
        const anyVariationStillSelected = [...document.querySelectorAll('.product-variant-radio:checked')]
            .some(r => r.dataset.optionType === 'variation');
        if (!anyVariationStillSelected) {
            _variantUserClicked = false;
        }
    }
    onVariantChange();
}

// Show/hide clear button based on whether any addon in group is selected
function updateClearBtnVisibility(groupId) {
    const btn = document.getElementById('clear-btn-' + groupId);
    if (!btn) return;
    const radios = document.querySelectorAll(`input[name="variant_group_${groupId}"]`);
    const anyChecked = [...radios].some(r => r.checked);
    btn.classList.toggle('hidden', !anyChecked);
}

// Update all clear buttons on variant change
const _origOnVariantChange = onVariantChange;
onVariantChange = function() {
    _origOnVariantChange();
    // Update clear button visibility for all addon groups
    document.querySelectorAll('.addon-clear-btn').forEach(btn => {
        const id = btn.id.replace('clear-btn-', '');
        updateClearBtnVisibility(id);
    });
};

// ── Bundle Add to Cart ──
function addBundleToCart() {
    const upload = getCustUpload();
    if (upload === false) return;
    const variantId = getSelectedVariantId();
    
    // Server calculates everything - just send product_id
    fetch(SITE_URL + '/api/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({
            action: 'add_bundle',
            product_id: PRODUCT_ID,
            quantity: getQty(),
            variant_id: variantId || null,
            customer_upload: upload || null
        }, ORDER_NOW_CLEAR_CART ? { clear_first: true } : {}))
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('.cart-count').forEach(el => el.textContent = data.cart_count);
            showCheckoutModal(); // Go straight to checkout
        } else {
            showToast(data.message || 'বান্ডেল যোগ করতে সমস্যা হয়েছে', 4000, 'error');
        }
    })
    .catch(err => {
        console.error('Bundle add error:', err);
        showToast('বান্ডেল যোগ করতে সমস্যা হয়েছে', 3000, 'error');
    });
}
</script>

<?php 
// ── MOBILE STICKY BUY BAR ──
$stickyBarEnabled = getSetting('mobile_product_sticky_bar', '0') === '1';
if ($stickyBarEnabled):
    $stickyBgStyle = getSetting('mobile_sticky_bg_style', 'solid');
    $stickyBgColor = getSetting('mobile_sticky_bg_color', '#ffffff');
    $stickyTextColor = getSetting('mobile_sticky_text_color', '#1f2937');
    
    // Build sticky bar styles
    if ($stickyBgStyle === 'glass') {
        list($sr,$sg,$sb) = sscanf($stickyBgColor, "#%02x%02x%02x");
        $stickyBgCSS = "background:rgba({$sr},{$sg},{$sb},0.75);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border-top:1px solid rgba(255,255,255,0.2);";
    } else {
        $stickyBgCSS = "background-color:{$stickyBgColor};border-top:1px solid #e5e7eb;";
    }
?>
<!-- Mobile Sticky Buy Bar -->
<div id="mobile-sticky-buy" class="md:hidden fixed bottom-0 left-0 right-0 z-50 px-3 py-2.5 shadow-[0_-4px_20px_rgba(0,0,0,0.1)]"
     style="<?= $stickyBgCSS ?>;color:<?= $stickyTextColor ?>;">
    <div class="flex items-center gap-2 w-full max-w-full overflow-hidden">
        <!-- Price -->
        <div class="flex-shrink-0">
            <span id="sticky-price" class="text-lg font-bold" style="color:var(--price-color, var(--primary))">
                <?php if ($showPriceRange): ?>
                    <?= ($varPriceMin == $varPriceMax) ? formatPrice($varPriceMin) : formatPrice($varPriceMin) . '–' . formatPrice($varPriceMax) ?>
                <?php else: ?>
                    <?= formatPrice($price) ?>
                <?php endif; ?>
            </span>
        </div>
        
        <!-- Qty Selector Mini -->
        <div class="flex items-center border rounded-lg overflow-hidden flex-shrink-0" style="border-color:<?= $stickyTextColor ?>30;">
            <button onclick="changeStickyQty(-1)" class="w-8 h-9 flex items-center justify-center hover:bg-black/5 transition text-sm font-bold">−</button>
            <input type="number" id="sticky-qty" value="1" min="1" max="99" 
                   class="w-9 h-9 text-center text-sm font-semibold bg-transparent focus:outline-none"
                   style="color:<?= $stickyTextColor ?>;"
                   oninput="syncQty(this.value)">
            <button onclick="changeStickyQty(1)" class="w-8 h-9 flex items-center justify-center hover:bg-black/5 transition text-sm font-bold">+</button>
        </div>
        
        <!-- Order Button -->
        <button onclick="productOrder()" id="sticky-order-btn"
                class="flex-1 min-w-0 py-2.5 rounded-xl text-white font-bold transition active:scale-[0.97] flex items-center justify-center gap-1.5"
                style="background:var(--btn-primary);font-size:clamp(12px, 3.5vw, 14px);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                <?= $product['stock_status'] === 'out_of_stock' ? 'disabled' : '' ?>>
            <i class="fas fa-shopping-bag text-xs flex-shrink-0"></i>
            <span class="truncate"><?= getSetting('btn_order_cod_label', 'অর্ডার করুন') ?></span>
        </button>
    </div>
</div>
<style>
/* Push page content above sticky bar */
@media(max-width:767px) {
    body { padding-bottom: 70px; }
    #mobile-sticky-buy { animation: slideUpSticky 0.3s ease-out; }
    @keyframes slideUpSticky { from { transform: translateY(100%); } to { transform: translateY(0); } }
}
</style>
<script>
function changeStickyQty(d) {
    const inp = document.getElementById('sticky-qty');
    const mainInp = document.getElementById('product-qty');
    const newVal = Math.max(1, Math.min(99, parseInt(inp.value || 1) + d));
    inp.value = newVal;
    if (mainInp) mainInp.value = newVal;
    if (typeof updateDisplayedPrice === 'function') updateDisplayedPrice();
    updateStickyPrice();
}
function syncQty(v) {
    const mainInp = document.getElementById('product-qty');
    const val = Math.max(1, Math.min(99, parseInt(v) || 1));
    if (mainInp) mainInp.value = val;
    if (typeof updateDisplayedPrice === 'function') updateDisplayedPrice();
    updateStickyPrice();
}
function updateStickyPrice() {
    const priceEl = document.getElementById('sticky-price');
    const displayPrice = document.getElementById('display-price');
    if (priceEl && displayPrice) {
        priceEl.textContent = displayPrice.textContent;
    }
}
// Sync main qty with sticky on any change
const mainQtyInput = document.getElementById('product-qty');
if (mainQtyInput) {
    const origChangeQty = window.changeQty;
    window.changeQty = function(d) {
        origChangeQty(d);
        const stickyInp = document.getElementById('sticky-qty');
        if (stickyInp) stickyInp.value = mainQtyInput.value;
        updateStickyPrice();
    };
}
// Update sticky price when variants change
const origVariantChange = window.onVariantChange;
if (typeof origVariantChange === 'function') {
    window.onVariantChange = function() {
        origVariantChange();
        setTimeout(updateStickyPrice, 50);
    };
}
</script>
<?php endif; ?>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
