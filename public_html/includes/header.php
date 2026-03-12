<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/clerk.php';

// ── Security Headers (fixes PentestTools CSP finding CWE-693) ──
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
$__clerkCsp = isClerkEnabled() ? ' https://cdn.jsdelivr.net https://*.clerk.accounts.dev https://*.clerk.com https://clerk.khatibangla.com' : '';
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https://cdnjs.cloudflare.com https://cdn.tailwindcss.com https://www.googletagmanager.com https://www.google-analytics.com https://connect.facebook.net{$__clerkCsp}; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.tailwindcss.com{$__clerkCsp}; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; img-src 'self' data: https: blob:; connect-src 'self' https:; frame-src 'self' https://www.youtube.com https://www.facebook.com https://*.clerk.accounts.dev https://*.clerk.com https://clerk.khatibangla.com;");
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443) {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}

// ── Persist _fbc cookie from fbclid URL parameter (must be before output) ──
if (!empty($_GET['fbclid']) && empty($_COOKIE['_fbc'])) {
    $__fbc = 'fb.1.' . (time() * 1000) . '.' . $_GET['fbclid'];
    setcookie('_fbc', $__fbc, time() + 7776000, '/', '', true, false); // 90 days
    $_COOKIE['_fbc'] = $__fbc;
}

$siteName = getSetting('site_name', 'MyShop');
$siteLogo = getSetting('site_logo');
$siteFavicon = getSetting('site_favicon');
$sitePhone = getSetting('site_phone') ?: getSetting('header_phone');
$siteWhatsapp = getSetting('site_whatsapp');
$siteHotline = getSetting('hotline_number') ?: getSetting('site_hotline');
$announcement = getSetting('announcement_content') ?: getSetting('announcement_text') ?: getSetting('header_announcement');
$cartCount = getCartCount();
$categories = getCategories();

// Language toggle
$currentLang = 'bn';

// Font sizes (with defaults)
$fontSizes = [
    'fs_body' => '15', 'fs_announcement' => '13', 'fs_nav_menu' => '14', 'fs_mobile_menu' => '15',
    'fs_search_input' => '14', 'fs_section_heading' => '22', 'fs_section_link' => '14',
    'fs_banner_title' => '28', 'fs_banner_subtitle' => '16', 'fs_category_name' => '12',
    'fs_trust_title' => '14', 'fs_trust_subtitle' => '12', 'fs_card_name' => '14',
    'fs_card_price' => '16', 'fs_card_old_price' => '12', 'fs_card_badge' => '12',
    'fs_card_button' => '13', 'fs_product_title' => '26', 'fs_product_price' => '30',
    'fs_product_desc' => '15', 'fs_order_button' => '16', 'fs_footer_heading' => '20',
    'fs_footer_text' => '14', 'fs_footer_copyright' => '14', 'fs_button_global' => '14',
    'fs_price_global' => '16',
];
$fs = [];
foreach ($fontSizes as $k => $def) { $fs[$k] = getSetting($k, $def); }

// Dynamic colors — Brand
$primaryColor = getSetting('primary_color', '#E53E3E');
$secondaryColor = getSetting('secondary_color', '#2D3748');
$accentColor = getSetting('accent_color', '#38A169');

// Header/Nav colors
$headerBg = getSetting('navbar_bg_color') ?: getSetting('header_bg_color', '#FFFFFF');
$headerText = getSetting('navbar_text_color') ?: getSetting('header_text_color', '#1A202C');
$navbarBg = getSetting('navbar_bg_color', '#1A202C');
$navbarText = getSetting('navbar_text_color', '#FFFFFF');
$topbarBg = getSetting('topbar_bg_color') ?: getSetting('announcement_bg', '#E53E3E');
$topbarText = getSetting('topbar_text_color') ?: getSetting('announcement_text', '#FFFFFF');

// Button colors — Order/Primary (settings saves as button_bg_color)
$btnPrimary = getSetting('button_bg_color') ?: getSetting('btn_primary_color', '#E53E3E');
$btnPrimaryText = getSetting('button_text_color') ?: getSetting('btn_primary_text', '#FFFFFF');
$btnHover = getSetting('button_hover_color', '#C53030');

// Button colors — Cart
$btnCart = getSetting('btn_cart_color', '#DD6B20');
$btnCartText = getSetting('btn_cart_text', '#FFFFFF');

// Checkout button (falls back to order button color)
$checkoutBtn = getSetting('checkout_btn_color') ?: $btnPrimary;
$checkoutBtnText = getSetting('checkout_btn_text') ?: $btnPrimaryText;

// Sale badge
$saleBadge = getSetting('sale_badge_bg') ?: getSetting('sale_badge_color', '#E53E3E');
$saleBadgeText = getSetting('sale_badge_text', '#FFFFFF');

// Price colors
$priceColor = getSetting('price_color', '#E53E3E');
$oldPriceColor = getSetting('old_price_color', '#9CA3AF');

// Footer colors
$footerBg = getSetting('footer_bg_color', '#1A202C');
$footerText = getSetting('footer_text_color', '#E2E8F0');
$footerHeading = getSetting('footer_heading_color', '#FFFFFF');

// Mobile bottom nav
$mobileNavBg = getSetting('mobile_nav_bg', '#ffffff');
$mobileNavActive = getSetting('mobile_nav_active') ?: $primaryColor;

// Header/Nav style settings
$headerBgStyle = getSetting('header_bg_style', 'solid');
$headerGlassOpacity = intval(getSetting('header_glass_opacity', '85'));
$headerGlassBlur = intval(getSetting('header_glass_blur', '12'));
$headerHeightDesktop = intval(getSetting('header_height_desktop', '80'));
$headerHeightMobile = intval(getSetting('header_height_mobile', '64'));
$navbarBgStyle = getSetting('navbar_bg_style', 'solid');
$navbarGlassOpacity = intval(getSetting('navbar_glass_opacity', '75'));
$navbarGlassBlur = intval(getSetting('navbar_glass_blur', '10'));

// Header element visibility
$hShowSearch = getSetting('header_show_search', '1') === '1';
$hShowLogin = getSetting('header_show_login', '1') === '1';
$hShowWishlist = getSetting('header_show_wishlist', '1') === '1';
$hShowWhatsapp = getSetting('header_show_whatsapp', '1') === '1';
$hShowCart = getSetting('header_show_cart', '1') === '1';

// Mobile product page settings
$mobileProductStickyBar = getSetting('mobile_product_sticky_bar', '0') === '1';
$mobileHideNavProduct = getSetting('mobile_hide_nav_product', '1') === '1';
$mobileStickyBgStyle = getSetting('mobile_sticky_bg_style', 'solid');
$mobileStickyBgColor = getSetting('mobile_sticky_bg_color', '#ffffff');
$mobileStickyTextColor = getSetting('mobile_sticky_text_color', '#1f2937');

// Detect if we're on a product page
$isProductPage = (isset($product) && is_array($product) && !empty($product['id']));
$hideMobileNav = $mobileProductStickyBar && $mobileHideNavProduct && $isProductPage;

$metaTitle = $pageTitle ?? getSetting('meta_title', $siteName);
$metaDesc = $pageDescription ?? getSetting('meta_description', '');

// Load SEO helper
require_once __DIR__ . '/seo.php';
$seo = $seo ?? ['type' => 'website'];
$seo['title'] = $seo['title'] ?? $metaTitle;
$seo['description'] = $seo['description'] ?? $metaDesc;

// Auto-generate better title/desc if manual ones are empty or generic
$_autoTitle = seoAutoTitle($seo);
$_autoDesc = seoAutoDescription($seo);
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($_autoTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($_autoDesc) ?>">
<?= seoRenderHead($seo) ?>
<?= seoRenderJsonLd($seo) ?>
    
    <?php if ($siteFavicon): ?>
    <link rel="icon" href="<?= uploadUrl($siteFavicon) ?>" type="image/x-icon">
    <?php endif; ?>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php
    // Load selected font(s) from settings
    $siteFont = getSetting('site_font_family', 'Hind Siliguri');
    $siteFontUrl = getSetting('site_font_url', 'Hind+Siliguri:wght@300;400;500;600;700');
    $siteFontWeight = getSetting('site_font_weight', '400');
    $headingFont = getSetting('site_heading_font', '');
    $headingFontUrl = getSetting('site_heading_font_url', '');
    $headingFontWeight = getSetting('site_heading_weight', '700');
    
    // Custom fonts (@font-face)
    $customFontsJson = getSetting('custom_fonts', '[]');
    $customFontsList = json_decode($customFontsJson, true) ?: [];
    $customFontFaces = '';
    foreach ($customFontsList as $cf) {
        $cfName = htmlspecialchars($cf['name'], ENT_QUOTES);
        $cfFile = SITE_URL . '/uploads/' . $cf['file'];
        $cfExt = strtolower($cf['format'] ?? pathinfo($cf['file'], PATHINFO_EXTENSION));
        $formatMap = ['woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype', 'otf' => 'opentype'];
        $cfFormat = $formatMap[$cfExt] ?? 'truetype';
        $customFontFaces .= "@font-face { font-family: '{$cfName}'; src: url('{$cfFile}') format('{$cfFormat}'); font-display: swap; }\n";
    }
    
    // Build Google Fonts URL (combine both fonts in one request)
    $gFonts = [];
    if ($siteFontUrl) $gFonts[] = 'family=' . $siteFontUrl;
    if ($headingFontUrl && $headingFontUrl !== $siteFontUrl) $gFonts[] = 'family=' . $headingFontUrl;
    if (empty($gFonts) && $siteFont === 'Hind Siliguri') {
        $gFonts[] = 'family=Hind+Siliguri:wght@300;400;500;600;700';
    }
    // Always load Inter for clean numeric rendering (loaded via unicode-range so minimal overhead)
    $needsNumericFont = in_array($siteFont, ['Hind Siliguri', 'Noto Sans Bengali', 'Noto Serif Bengali', 'Baloo Da 2', 'Anek Bangla', 'Tiro Bangla', 'Galada', 'Atma', 'Mina']);
    if ($needsNumericFont) {
        $hasInter = false;
        foreach ($gFonts as $gf) { if (strpos($gf, 'Inter') !== false) { $hasInter = true; break; } }
        if (!$hasInter) $gFonts[] = 'family=Inter:wght@400;500;600;700';
    }
    $gFontLink = $gFonts ? 'https://fonts.googleapis.com/css2?' . implode('&', $gFonts) . '&display=swap' : '';
    
    $fontFallback = 'sans-serif';
    // For Bangla fonts: insert NumericFont (Inter via unicode-range) first for clean numeral rendering
    if ($needsNumericFont) {
        $headingFontFamily = $headingFont ? "'NumericFont', '{$headingFont}', 'Inter', {$fontFallback}" : "'NumericFont', '{$siteFont}', 'Inter', {$fontFallback}";
        $bodyFontFamily = "'NumericFont', '{$siteFont}', 'Inter', {$fontFallback}";
    } else {
        $headingFontFamily = $headingFont ? "'{$headingFont}', {$fontFallback}" : "'{$siteFont}', {$fontFallback}";
        $bodyFontFamily = "'{$siteFont}', {$fontFallback}";
    }
    ?>
    <?php if ($gFontLink): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="<?= $gFontLink ?>" rel="stylesheet">
    <?php endif; ?>
    <?php if ($customFontFaces): ?>
    <style><?= $customFontFaces ?></style>
    <?php endif; ?>
    <?php if ($needsNumericFont): ?>
    <style>
    /* Override: Use Inter for numerals/math when primary font is Bangla */
    @font-face {
        font-family: 'NumericFont';
        src: local('Inter'), local('Inter-Regular');
        font-weight: 100 900;
        font-display: swap;
        unicode-range: U+0030-0039, U+0025, U+002B-002F, U+002C, U+002E, U+003A, U+20B3, U+20B9;
        /* Covers: 0-9, %, +, -, ., /, comma, colon, currency symbols */
    }
    </style>
    <?php endif; ?>
    
    <style>
        :root {
            /* Brand */
            --primary: <?= $primaryColor ?>;
            --secondary: <?= $secondaryColor ?>;
            --accent: <?= $accentColor ?>;
            /* Buttons */
            --btn-primary: <?= $btnPrimary ?>;
            --btn-primary-text: <?= $btnPrimaryText ?>;
            --btn-hover: <?= $btnHover ?>;
            --btn-cart: <?= $btnCart ?>;
            --btn-cart-text: <?= $btnCartText ?>;
            /* Checkout */
            --checkout-btn: <?= $checkoutBtn ?>;
            --checkout-btn-text: <?= $checkoutBtnText ?>;
            /* Product */
            --sale-badge: <?= $saleBadge ?>;
            --sale-badge-text: <?= $saleBadgeText ?>;
            --price-color: <?= $priceColor ?>;
            --old-price-color: <?= $oldPriceColor ?>;
            /* Footer */
            --footer-bg: <?= $footerBg ?>;
            --footer-text: <?= $footerText ?>;
            --footer-heading-color: <?= $footerHeading ?>;
            /* Mobile Nav */
            --mobile-nav-bg: <?= $mobileNavBg ?>;
            --mobile-nav-active: <?= $mobileNavActive ?>;
            /* Font Sizes */
            --fs-body: <?= $fs['fs_body'] ?>px;
            --fs-announcement: <?= $fs['fs_announcement'] ?>px;
            --fs-nav-menu: <?= $fs['fs_nav_menu'] ?>px;
            --fs-mobile-menu: <?= $fs['fs_mobile_menu'] ?>px;
            --fs-search-input: <?= $fs['fs_search_input'] ?>px;
            --fs-section-heading: <?= $fs['fs_section_heading'] ?>px;
            --fs-section-link: <?= $fs['fs_section_link'] ?>px;
            --fs-banner-title: <?= $fs['fs_banner_title'] ?>px;
            --fs-banner-subtitle: <?= $fs['fs_banner_subtitle'] ?>px;
            --fs-category-name: <?= $fs['fs_category_name'] ?>px;
            --fs-trust-title: <?= $fs['fs_trust_title'] ?>px;
            --fs-trust-subtitle: <?= $fs['fs_trust_subtitle'] ?>px;
            --fs-card-name: <?= $fs['fs_card_name'] ?>px;
            --fs-card-price: <?= $fs['fs_card_price'] ?>px;
            --fs-card-old-price: <?= $fs['fs_card_old_price'] ?>px;
            --fs-card-badge: <?= $fs['fs_card_badge'] ?>px;
            --fs-card-button: <?= $fs['fs_card_button'] ?>px;
            --fs-product-title: <?= $fs['fs_product_title'] ?>px;
            --fs-product-price: <?= $fs['fs_product_price'] ?>px;
            --fs-product-desc: <?= $fs['fs_product_desc'] ?>px;
            --fs-order-button: <?= $fs['fs_order_button'] ?>px;
            --fs-footer-heading: <?= $fs['fs_footer_heading'] ?>px;
            --fs-footer-text: <?= $fs['fs_footer_text'] ?>px;
            --fs-footer-copyright: <?= $fs['fs_footer_copyright'] ?>px;
            --fs-button-global: <?= $fs['fs_button_global'] ?>px;
            --fs-price-global: <?= $fs['fs_price_global'] ?>px;
            /* Font Weights */
            --fw-body: <?= (int)$siteFontWeight ?>;
            --fw-heading: <?= (int)$headingFontWeight ?>;
        }
        body { font-family: <?= $bodyFontFamily ?>; font-size: var(--fs-body); }
        h1, h2, h3, h4, h5, h6, .section-heading, .banner-title, .product-detail-title, .footer-heading { 
            font-family: <?= $headingFontFamily ?>; font-weight: var(--fw-heading);
        }
        /* Clean numerals for prices, quantities, and monetary values */
        .pc-price, .pc-price-now, .pc-price-was, .product-price, 
        [class*="price"], [class*="amount"], [class*="total"],
        .cart-count, input[type="number"], .order-total, .checkout-total {
            font-variant-numeric: lining-nums tabular-nums;
            font-feature-settings: 'lnum' 1, 'tnum' 1;
        }
        
        /* Font Size Overrides */
        .topbar-text { font-size: var(--fs-announcement) !important; }
        .nav-menu-item { font-size: var(--fs-nav-menu) !important; }
        .mobile-menu-item { font-size: var(--fs-mobile-menu) !important; }
        .search-input { font-size: var(--fs-search-input) !important; }
        .section-heading { font-size: var(--fs-section-heading) !important; }
        .section-link { font-size: var(--fs-section-link) !important; }
        .banner-title { font-size: var(--fs-banner-title) !important; }
        .banner-subtitle { font-size: var(--fs-banner-subtitle) !important; }
        .cat-name { font-size: var(--fs-category-name) !important; }
        .trust-title { font-size: var(--fs-trust-title) !important; }
        .trust-subtitle { font-size: var(--fs-trust-subtitle) !important; }
        .product-detail-title { font-size: var(--fs-product-title) !important; }
        .product-detail-price { font-size: var(--fs-product-price) !important; }
        .product-detail-desc { font-size: var(--fs-product-desc) !important; }
        .order-btn-text { font-size: var(--fs-order-button) !important; }
        .footer-heading { font-size: var(--fs-footer-heading) !important; }
        .footer-text { font-size: var(--fs-footer-text) !important; }
        .footer-copyright { font-size: var(--fs-footer-copyright) !important; }
        
        .btn-primary { background-color: var(--btn-primary); color: var(--btn-primary-text); }
        .btn-primary:hover { background-color: var(--btn-hover); }
        .btn-cart { background-color: var(--btn-cart); color: var(--btn-cart-text); }
        .btn-cart:hover { filter: brightness(1.1); }
        .btn-checkout { background-color: var(--checkout-btn); color: var(--checkout-btn-text); }
        .btn-checkout:hover { background-color: var(--btn-hover); }
        #checkout-submit-btn {
            animation: checkout-pulse 2s ease-in-out infinite;
        }
        @keyframes checkout-pulse {
            0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--checkout-btn) 60%, transparent); }
            50% { box-shadow: 0 0 0 10px color-mix(in srgb, var(--checkout-btn) 0%, transparent); }
        }
        .sale-badge { background-color: var(--sale-badge); color: var(--sale-badge-text); }
        
        /* Price colors */
        .pc-price-now, .product-detail-price, .price-current { color: var(--price-color) !important; }
        .pc-price-was, .price-old { color: var(--old-price-color) !important; }
        
        /* Footer colors via CSS variables */
        footer .footer-heading { color: var(--footer-heading-color) !important; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .product-card:hover .product-overlay { opacity: 1; }
        .product-card:hover img { transform: scale(1.05); }
        
        /* Announcement marquee */
        @keyframes marquee { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }
        .animate-marquee { animation: marquee 20s linear infinite; }
        
        /* Popup animation */
        .popup-overlay { transition: opacity 0.3s; }
        .popup-content { transition: transform 0.3s, opacity 0.3s; }
        .popup-show .popup-content { transform: translateY(0) scale(1); opacity: 1; }
        .popup-hide .popup-content { transform: translateY(20px) scale(0.95); opacity: 0; }
        
        /* COD Order Button - attention pulse */
        @keyframes cod-pulse {
            0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--btn-primary) 60%, transparent); }
            50% { box-shadow: 0 0 0 12px color-mix(in srgb, var(--btn-primary) 0%, transparent); }
        }
        @keyframes cod-shimmer {
            0% { background-position: -200% center; }
            100% { background-position: 200% center; }
        }
        .cod-order-btn {
            animation: cod-pulse 2s ease-in-out infinite;
            background-size: 200% auto;
            background-image: linear-gradient(90deg, var(--btn-primary) 0%, var(--btn-primary) 40%, rgba(255,255,255,0.25) 50%, var(--btn-primary) 60%, var(--btn-primary) 100%);
            animation: cod-pulse 2s ease-in-out infinite, cod-shimmer 3s linear infinite;
            position: relative;
            overflow: hidden;
        }
        .cod-order-btn:active { animation: none; transform: scale(0.97); }
        
        /* Smooth price transitions */
        .price-animate {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes price-pop {
            0% { transform: scale(1); }
            50% { transform: scale(1.08); }
            100% { transform: scale(1); }
        }
        .price-pop { animation: price-pop 0.35s ease-out; }
    </style>
    
    <?php 
    $ga = getSetting('google_analytics');
    if ($ga): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $ga ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?= $ga ?>');
    </script>
    <?php endif; ?>
    
    <?php 
    // ── Facebook Pixel + Server-Side CAPI + Data Layer ──
    $__fbPixelId = getSetting('fb_pixel_id', '') ?: getSetting('facebook_pixel_id', '') ?: getSetting('facebook_pixel', '');
    if ($__fbPixelId):
        // Load CAPI engine
        if (file_exists(__DIR__ . '/fb-capi.php')) require_once __DIR__ . '/fb-capi.php';
        
        // Generate PageView event_id for deduplication
        $__pvEventId = function_exists('fbEventId') ? fbEventId() : ('evt_' . bin2hex(random_bytes(12)));
        
        // Advanced matching — RAW data (FB pixel hashes client-side automatically)
        $__amEnabled = getSetting('fb_advanced_matching', '1') === '1';
        $__amData = [];
        if ($__amEnabled) {
            if (!empty($_SESSION['customer_phone'])) {
                $ph = preg_replace('/[^0-9]/', '', $_SESSION['customer_phone']);
                if (strlen($ph) === 11 && str_starts_with($ph, '0')) $ph = '880' . substr($ph, 1);
                $__amData['ph'] = $ph; // RAW — FB hashes client-side
            }
            if (!empty($_SESSION['customer_email'])) {
                $__amData['em'] = strtolower(trim($_SESSION['customer_email']));
            }
            if (!empty($_SESSION['customer_name'])) {
                $parts = preg_split('/\s+/', trim($_SESSION['customer_name']), 2);
                $__amData['fn'] = strtolower($parts[0]);
                if (isset($parts[1])) $__amData['ln'] = strtolower($parts[1]);
            }
            $__amData['country'] = 'bd';
            if (!empty($_SESSION['customer_city'])) $__amData['ct'] = strtolower(trim($_SESSION['customer_city']));
            if (!empty($_SESSION['customer_zip'])) $__amData['zp'] = trim($_SESSION['customer_zip']);
        }
        
        $__csPageView = !function_exists('fbPixelEventEnabled') || fbPixelEventEnabled('PageView');
        
        // Build user_data for dataLayer (Google Enhanced Conversions)
        $__dlUserData = [];
        if (!empty($_SESSION['customer_email'])) $__dlUserData['email'] = strtolower(trim($_SESSION['customer_email']));
        if (!empty($_SESSION['customer_phone'])) {
            $dlPh = preg_replace('/[^0-9]/', '', $_SESSION['customer_phone']);
            if (strlen($dlPh) === 11 && str_starts_with($dlPh, '0')) $dlPh = '+880' . substr($dlPh, 1);
            else $dlPh = '+' . $dlPh;
            $__dlUserData['phone_number'] = $dlPh;
        }
        if (!empty($_SESSION['customer_name'])) {
            $__nameParts = preg_split('/\s+/', trim($_SESSION['customer_name']), 2);
            $__dlUserData['address'] = ['first_name' => $__nameParts[0], 'last_name' => $__nameParts[1] ?? '', 'country' => 'BD'];
            if (!empty($_SESSION['customer_city'])) $__dlUserData['address']['city'] = $_SESSION['customer_city'];
        }
    ?>
    <script>
        // ═══ Data Layer (Golden Layer — fires BEFORE tags) ═══
        window.dataLayer = window.dataLayer || [];
        <?php if (!empty($__dlUserData)): ?>
        dataLayer.push({'user_data': <?= json_encode($__dlUserData) ?>});
        <?php endif; ?>
        dataLayer.push({
            'event': 'page_view',
            'event_id': '<?= $__pvEventId ?>',
            'page_location': window.location.href,
            'page_title': document.title
        });

        // ═══ Facebook Pixel ═══
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
        document,'script','https://connect.facebook.net/en_US/fbevents.js');
        <?php if ($__amEnabled && !empty($__amData)): ?>
        fbq('init', '<?= $__fbPixelId ?>', <?= json_encode($__amData) ?>);
        <?php else: ?>
        fbq('init', '<?= $__fbPixelId ?>');
        <?php endif; ?>
        <?php if ($__csPageView): ?>
        fbq('track', 'PageView', {}, {eventID: '<?= $__pvEventId ?>'});
        <?php endif; ?>
        
        // ═══ _fbTrack helper: Client pixel + dataLayer push + returns eventID for dedup ═══
        window._fbTrack = function(event, data, eventId) {
            eventId = eventId || 'evt_' + Date.now() + '_' + Math.random().toString(36).substr(2,12);
            // Facebook Pixel
            if(typeof fbq === 'function') fbq('track', event, data || {}, {eventID: eventId});
            // dataLayer push (for GTM / GA4)
            var dlEvent = {event: 'fb_' + event.toLowerCase(), event_id: eventId};
            if(data) {
                for(var k in data) { if(data.hasOwnProperty(k)) dlEvent[k] = data[k]; }
            }
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push(dlEvent);
            return eventId;
        };

        // ═══ _fbTrackServer: fire server-side CAPI relay (call AFTER _fbTrack) ═══
        window._fbTrackServer = function(event, data, eventId) {
            try {
                var body = 'event='+encodeURIComponent(event)+'&event_id='+encodeURIComponent(eventId||'');
                if(data) body += '&data='+encodeURIComponent(JSON.stringify(data));
                fetch('<?= SITE_URL ?>/api/fb-event.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body}).catch(function(){});
            } catch(e){}
        };
    </script>
    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?= $__fbPixelId ?>&ev=PageView&noscript=1"></noscript>
    <?php
        // Fire server-side PageView
        if (function_exists('fbCapiEnabled') && fbCapiEnabled()) {
            try { fbTrackPageView($__pvEventId); } catch (\Throwable $e) {}
        }
    endif; ?>


    <?= renderClerkHead() ?>

    <?php
    // ── Performance: Skeleton + Loader ──────────────────────────────────────
    $perf_skeleton = getSetting('perf_skeleton_enabled','1') === '1';
    $perf_loader   = getSetting('perf_loader_enabled','1') === '1';
    $sk_base       = getSetting('perf_skeleton_base','#e5e7eb');
    $sk_shimmer    = getSetting('perf_skeleton_shimmer','#f3f4f6');
    $ld_delay      = max(200, intval(getSetting('perf_loader_delay','800')));
    $ld_bg         = getSetting('perf_loader_bg','#ffffff');
    $ld_color      = getSetting('perf_loader_color','#f97316');
    $ld_style      = getSetting('perf_loader_style','spinner');
    $ld_text       = getSetting('perf_loader_text','');
    if ($perf_skeleton || $perf_loader):
    ?>
    <style>
    <?php if($perf_skeleton): ?>
    :root{--sk-base:<?= $sk_base ?>;--sk-shimmer:<?= $sk_shimmer ?>;}
    .skeleton{background:linear-gradient(90deg,var(--sk-base) 25%,var(--sk-shimmer) 50%,var(--sk-base) 75%);background-size:200% 100%;animation:skAnim 1.5s ease-in-out infinite;}
    @keyframes skAnim{0%{background-position:200% 0}100%{background-position:-200% 0}}
    .skeleton-card{border-radius:12px;overflow:hidden;background:var(--sk-base);}
    <?php endif; ?>
    <?php if($perf_loader): ?>
    #kh-page-loader{position:fixed;inset:0;z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;background:<?= $ld_bg ?>;opacity:0;pointer-events:none;transition:opacity .25s;}
    #kh-page-loader.show{opacity:1;pointer-events:all;}
    #kh-loader-spinner{width:44px;height:44px;border-radius:50%;border:4px solid <?= $ld_color ?>22;border-top-color:<?= $ld_color ?>;animation:khSpin .8s linear infinite;}
    @keyframes khSpin{to{transform:rotate(360deg)}}
    #kh-loader-dots{display:flex;gap:7px;}
    #kh-loader-dots span{width:11px;height:11px;border-radius:50%;background:<?= $ld_color ?>;animation:khBounce 1.1s ease-in-out infinite;}
    #kh-loader-dots span:nth-child(2){animation-delay:.18s;}
    #kh-loader-dots span:nth-child(3){animation-delay:.36s;}
    @keyframes khBounce{0%,80%,100%{transform:scale(0.6);opacity:.5}40%{transform:scale(1);opacity:1}}
    #kh-loader-bar-wrap{width:160px;height:4px;background:<?= $ld_color ?>22;border-radius:9px;overflow:hidden;}
    #kh-loader-bar{height:100%;background:<?= $ld_color ?>;border-radius:9px;animation:khBar 1.4s ease-in-out infinite;}
    @keyframes khBar{0%{width:0;margin-left:0}50%{width:70%;margin-left:0}100%{width:0;margin-left:100%}}
    #kh-loader-logo{width:56px;height:56px;border-radius:50%;animation:khPulse 1.2s ease-in-out infinite;}
    @keyframes khPulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.12);opacity:.7}}
    #kh-loader-text{font-size:13px;color:<?= $ld_color ?>;font-weight:500;letter-spacing:.3px;}
    <?php endif; ?>
    </style>
    <?php endif; ?>
</head>
<body class="bg-gray-50 text-gray-800 antialiased">

<?php if($perf_loader ?? false): ?>
<div id="kh-page-loader" aria-hidden="true">
    <?php if($ld_style==='spinner'): ?>
    <div id="kh-loader-spinner"></div>
    <?php elseif($ld_style==='dots'): ?>
    <div id="kh-loader-dots"><span></span><span></span><span></span></div>
    <?php elseif($ld_style==='bar'): ?>
    <div id="kh-loader-bar-wrap"><div id="kh-loader-bar"></div></div>
    <?php elseif($ld_style==='logo'): ?>
    <?php $logoSrc = settingImgUrl($s??[], 'site_logo'); if($logoSrc): ?><img id="kh-loader-logo" src="<?= $logoSrc ?>" alt="Loading"><?php else: ?><div id="kh-loader-spinner"></div><?php endif; ?>
    <?php endif; ?>
    <?php if($ld_text): ?><span id="kh-loader-text"><?= e($ld_text) ?></span><?php endif; ?>
</div>
<script>
(function(){
    var _khl  = document.getElementById('kh-page-loader');
    var _khT  = null;
    var _khD  = <?= $ld_delay ?>;
    // Show loader after delay if page hasn't finished loading
    if(_khl && document.readyState !== 'complete'){
        _khT = setTimeout(function(){ _khl.classList.add('show'); }, _khD);
    }
    // Hide once page is fully loaded
    function _khHide(){
        if(_khT){ clearTimeout(_khT); _khT = null; }
        if(_khl){ _khl.classList.remove('show'); }
    }
    if(document.readyState === 'complete'){ _khHide(); }
    else { window.addEventListener('load', _khHide, {once:true}); }
    // Also hide on navigation away (bfcache)
    window.addEventListener('pageshow', _khHide, {once:true});
})();
</script>
<?php endif; ?>

<!-- Top Bar / Announcement -->
<div class="text-sm py-1.5 text-center overflow-hidden topbar-text" style="background-color:<?= $topbarBg ?>;color:<?= $topbarText ?>">
    <div class="flex items-center justify-center gap-2 px-4">
        <i class="fas fa-phone-alt text-xs"></i>
        <span><?= htmlspecialchars($announcement) ?>: </span>
        <a href="tel:<?= $sitePhone ?>" class="font-semibold hover:underline"><?= $sitePhone ?></a>
        <span class="mx-1">|</span>
        <a href="tel:<?= $siteHotline ?>" class="font-semibold hover:underline">হট লাইন: <?= $siteHotline ?></a>
    </div>
</div>

<!-- Header -->
<?php
// Build header inline styles
$headerStyle = "color:{$headerText};";
if ($headerBgStyle === 'glass') {
    $opacity = $headerGlassOpacity / 100;
    list($r,$g,$b) = sscanf($headerBg, "#%02x%02x%02x");
    $headerStyle .= "background:rgba({$r},{$g},{$b},{$opacity});backdrop-filter:blur({$headerGlassBlur}px);-webkit-backdrop-filter:blur({$headerGlassBlur}px);";
} else {
    $headerStyle .= "background-color:{$headerBg};";
}
?>
<header class="sticky top-0 z-50 <?= $headerBgStyle === 'glass' ? 'border-b border-white/20' : 'shadow-sm' ?> <?= $hideMobileNav ? 'hidden-on-mobile-product' : '' ?>" style="<?= $headerStyle ?>">
    <style>
    @media(max-width:1023px){ header .main-header-row { height: <?= $headerHeightMobile ?>px; } }
    @media(min-width:1024px){ header .main-header-row { height: <?= $headerHeightDesktop ?>px; } }
    <?php if ($hideMobileNav): ?>
    @media(max-width:767px){ .hidden-on-mobile-product { display:none !important; } }
    <?php endif; ?>
    </style>
    <div class="max-w-7xl mx-auto px-4">
        <div class="flex items-center justify-between main-header-row">
            <!-- Mobile Menu Toggle -->
            <button onclick="toggleMobileMenu()" class="lg:hidden p-2 rounded-lg hover:bg-gray-100">
                <i class="fas fa-bars text-xl"></i>
            </button>
            
            <!-- Logo -->
            <a href="<?= url() ?>" class="flex-shrink-0">
                <?php if ($siteLogo): ?>
                <img src="<?= uploadUrl($siteLogo) ?>" alt="<?= $siteName ?>" class="h-10 lg:h-14 object-contain">
                <?php else: ?>
                <span class="text-2xl font-bold" style="color:var(--primary)"><?= $siteName ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Search Bar (Desktop) -->
            <?php if ($hShowSearch): ?>
            <div class="hidden lg:flex flex-1 max-w-xl mx-8 relative" id="desktop-search-wrap">
                <form action="<?= url('search') ?>" method="GET" class="w-full relative" autocomplete="off">
                    <input type="text" name="q" id="desktop-search-input" placeholder="পণ্য খুঁজুন..." 
                           class="w-full border-2 border-gray-200 rounded-full py-2.5 px-5 pr-12 focus:outline-none focus:border-red-400 transition search-input"
                           oninput="liveSearch(this.value, 'desktop-search-results')" onfocus="if(this.value.length>=2) document.getElementById('desktop-search-results').classList.remove('hidden')">
                    <button type="submit" class="absolute right-1 top-1 bottom-1 px-4 rounded-full btn-primary">
                        <i class="fas fa-search"></i>
                    </button>
                </form>
                <div id="desktop-search-results" class="hidden absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-2xl shadow-2xl z-50 max-h-[420px] overflow-y-auto"></div>
            </div>
            <?php endif; ?>
            
            <!-- Right Actions -->
            <div class="flex items-center gap-2 sm:gap-3">
                <!-- Search Mobile -->
                <?php if ($hShowSearch): ?>
                <button onclick="toggleSearch()" class="lg:hidden p-2 rounded-lg hover:bg-gray-100">
                    <i class="fas fa-search text-lg"></i>
                </button>
                <?php endif; ?>
                
                <!-- Account -->
                <?php if ($hShowLogin): ?>
                <?php if (isCustomerLoggedIn()): ?>
                <a href="<?= url('account') ?>" class="hidden sm:flex items-center gap-1.5 p-2 rounded-lg hover:bg-gray-100" title="আমার একাউন্ট">
                    <?php $__clkImg = getClerkImage(); if ($__clkImg): ?>
                    <img src="<?= e($__clkImg) ?>" alt="" class="w-7 h-7 rounded-full object-cover border">
                    <?php else: ?>
                    <i class="fas fa-user text-lg"></i>
                    <?php endif; ?>
                    <span class="text-sm font-medium hidden md:inline"><?= e(getCustomerName()) ?></span>
                </a>
                <?php else: ?>
                <a href="<?= url('login') ?>" class="hidden sm:flex items-center gap-1.5 p-2 rounded-lg hover:bg-gray-100" title="লগইন">
                    <i class="far fa-user text-lg"></i>
                    <span class="text-sm font-medium hidden md:inline">লগইন</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <!-- Review Notification -->
                <?php if (isCustomerLoggedIn() && getSetting('reviews_enabled', '1') === '1'):
                    $__revCustId = getCustomerId();
                    $__pendingRevCount = 0;
                    try {
                        $__prvDb = Database::getInstance();
                        $__prv = $__prvDb->fetch(
                            "SELECT COUNT(DISTINCT oi.product_id) as cnt FROM orders o 
                             JOIN order_items oi ON oi.order_id = o.id
                             WHERE (o.customer_id = ? OR o.customer_phone = (SELECT phone FROM customers WHERE id = ?))
                             AND o.order_status = 'delivered'
                             AND oi.product_id NOT IN (SELECT product_id FROM product_reviews WHERE customer_id = ? AND is_dummy = 0)",
                            [$__revCustId, $__revCustId, $__revCustId]
                        );
                        $__pendingRevCount = intval($__prv['cnt'] ?? 0);
                    } catch (\Throwable $e) {}
                    if ($__pendingRevCount > 0): ?>
                <a href="<?= url('account?tab=reviews') ?>" class="relative p-2 rounded-lg hover:bg-gray-100 hidden sm:block" title="রিভিউ দিন — <?= $__pendingRevCount ?>টি পণ্যে রিভিউ দেওয়া বাকি">
                    <i class="fas fa-star text-lg text-yellow-500"></i>
                    <span class="absolute -top-1 -right-1 w-5 h-5 flex items-center justify-center text-[10px] font-bold rounded-full bg-red-500 text-white animate-pulse"><?= $__pendingRevCount ?></span>
                </a>
                <?php endif; endif; ?>
                
                <!-- Wishlist -->
                <?php if ($hShowWishlist && isCustomerLoggedIn()): ?>
                <a href="<?= url('account?tab=wishlist') ?>" class="relative p-2 rounded-lg hover:bg-gray-100 hidden sm:block" title="উইশলিস্ট">
                    <i class="far fa-heart text-lg"></i>
                    <?php $wc = getWishlistCount(); if ($wc > 0): ?>
                    <span class="absolute -top-1 -right-1 w-5 h-5 flex items-center justify-center text-xs font-bold rounded-full sale-badge"><?= $wc ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                
                <!-- WhatsApp -->
                <?php if ($hShowWhatsapp): ?>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $siteWhatsapp) ?>" 
                   target="_blank" class="hidden sm:flex items-center gap-1.5 text-green-600 hover:text-green-700">
                    <i class="fab fa-whatsapp text-xl"></i>
                </a>
                <?php endif; ?>
                
                <!-- Cart -->
                <?php if ($hShowCart): ?>
                <a href="<?= url('cart') ?>" class="relative p-2 rounded-lg hover:bg-gray-100 group" id="header-cart">
                    <i class="fas fa-shopping-bag text-xl"></i>
                    <span class="absolute -top-1 -right-1 w-5 h-5 flex items-center justify-center text-xs font-bold rounded-full sale-badge cart-count"><?= $cartCount ?></span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Category Navigation -->
    <?php
    $navAlign = getSetting('nav_menu_align', 'left');
    $navShowShop = getSetting('nav_show_shop_link', '1') === '1';
    $navShowCats = getSetting('nav_show_categories', '1') === '1';
    $customMenuJson = getSetting('nav_menu_items', '[]');
    $customMenu = json_decode($customMenuJson, true) ?: [];
    $alignClass = $navAlign === 'center' ? 'justify-center' : ($navAlign === 'right' ? 'justify-end' : 'justify-start');
    ?>
    <?php
    // Build nav bar inline style
    $navStyle = '';
    if ($navbarBgStyle === 'glass') {
        list($nr,$ng,$nb) = sscanf($navbarBg, "#%02x%02x%02x");
        $nOpacity = $navbarGlassOpacity / 100;
        $navStyle = "background:rgba({$nr},{$ng},{$nb},{$nOpacity});backdrop-filter:blur({$navbarGlassBlur}px);-webkit-backdrop-filter:blur({$navbarGlassBlur}px);";
    } else {
        $navStyle = "background-color:{$navbarBg};";
    }
    ?>
    <nav class="hidden lg:block <?= $navbarBgStyle === 'glass' ? 'border-b border-white/10' : '' ?>" style="<?= $navStyle ?>">
        <div class="max-w-7xl mx-auto px-4">
            <ul class="flex items-center gap-1 overflow-x-auto scrollbar-hide py-0.5 <?= $alignClass ?>">
                <?php if ($navShowShop): ?>
                <li>
                    <a href="<?= url('shop') ?>" 
                       class="block px-4 py-2.5 text-sm font-medium whitespace-nowrap rounded-lg hover:bg-white/10 transition nav-menu-item"
                       style="color:<?= $navbarText ?>"
                       data-lang-bn="সকল পণ্য" data-lang-en="<?= e(getSetting('lang_all_products_en', 'All Products')) ?>">
                        <i class="fas fa-th-large mr-1 text-xs opacity-70"></i><span>সকল পণ্য</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php foreach ($customMenu as $mi): if (empty($mi['active'])) continue;
                    $miUrl = $mi['url'] ?? '/';
                    if ($miUrl && $miUrl[0] === '/') $miUrl = SITE_URL . $miUrl;
                    $isButton = ($mi['style'] ?? 'link') === 'button';
                ?>
                <li>
                    <a href="<?= htmlspecialchars($miUrl) ?>" 
                       class="block px-4 py-2.5 text-sm font-medium whitespace-nowrap rounded-lg transition nav-menu-item <?= $isButton ? 'bg-white/20 hover:bg-white/30' : 'hover:bg-white/10' ?>"
                       style="color:<?= $navbarText ?>">
                        <?php if (!empty($mi['icon'])): ?><i class="<?= htmlspecialchars($mi['icon']) ?> mr-1 text-xs opacity-80"></i><?php endif; ?>
                        <?= htmlspecialchars($mi['label']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php if ($navShowCats): foreach ($categories as $cat): ?>
                <li>
                    <a href="<?= url('category/' . $cat['slug']) ?>" 
                       class="block px-4 py-2.5 text-sm font-medium whitespace-nowrap rounded-lg hover:bg-white/10 transition nav-menu-item"
                       style="color:<?= $navbarText ?>">
                        <?= htmlspecialchars($cat['name_bn'] ?: $cat['name']) ?>
                    </a>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </nav>
</header>

<!-- Mobile Search Overlay -->
<div id="mobile-search" class="hidden fixed inset-0 z-50 bg-white p-4">
    <div class="flex items-center gap-3">
        <button onclick="toggleSearch()" class="p-2"><i class="fas fa-arrow-left text-xl"></i></button>
        <form action="<?= url('search') ?>" method="GET" class="flex-1 relative" autocomplete="off">
            <input type="text" name="q" id="mobile-search-input" placeholder="পণ্য খুঁজুন..." autofocus
                   class="w-full border-2 border-gray-200 rounded-full py-2.5 px-4 focus:outline-none focus:border-red-400"
                   oninput="liveSearch(this.value, 'mobile-search-results')">
        </form>
    </div>
    <div id="mobile-search-results" class="mt-3 max-h-[70vh] overflow-y-auto"></div>
</div>

<!-- Mobile Menu Overlay -->
<div id="mobile-menu" class="hidden fixed inset-0 z-50">
    <div class="absolute inset-0 bg-black/50" onclick="toggleMobileMenu()"></div>
    <div class="absolute left-0 top-0 bottom-0 w-80 bg-white shadow-xl overflow-y-auto">
        <div class="p-4 flex items-center justify-between border-b" style="background-color:var(--primary)">
            <span class="text-white font-bold text-lg"><?= $siteName ?></span>
            <button onclick="toggleMobileMenu()" class="text-white p-1"><i class="fas fa-times text-xl"></i></button>
        </div>
        <ul class="py-2">
            <?php if ($navShowShop): ?>
            <li>
                <a href="<?= url('shop') ?>" 
                   class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 border-b border-gray-100 font-semibold mobile-menu-item" style="color:var(--primary)">
                    <i class="fas fa-th-large w-5 text-center"></i>
                    <span>সকল পণ্য</span>
                </a>
            </li>
            <?php endif; ?>
            <?php foreach ($customMenu as $mi): if (empty($mi['active'])) continue;
                $miUrl = $mi['url'] ?? '/';
                if ($miUrl && $miUrl[0] === '/') $miUrl = SITE_URL . $miUrl;
                $isButton = ($mi['style'] ?? 'link') === 'button';
            ?>
            <li>
                <a href="<?= htmlspecialchars($miUrl) ?>" 
                   class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 border-b border-gray-100 mobile-menu-item <?= $isButton ? 'font-semibold' : '' ?>"
                   <?= $isButton ? 'style="color:var(--primary)"' : '' ?>>
                    <?php if (!empty($mi['icon'])): ?>
                    <i class="<?= htmlspecialchars($mi['icon']) ?> w-5 text-center text-gray-400"></i>
                    <?php else: ?>
                    <i class="fas fa-link w-5 text-center text-gray-400"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($mi['label']) ?>
                </a>
            </li>
            <?php endforeach; ?>
            <?php if ($navShowCats): foreach ($categories as $cat): ?>
            <li>
                <a href="<?= url('category/' . $cat['slug']) ?>" 
                   class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 border-b border-gray-100 text-gray-700">
                    <?php if ($cat['icon']): ?>
                    <i class="<?= $cat['icon'] ?> text-gray-400 w-5 text-center"></i>
                    <?php endif; ?>
                    <span class="font-medium"><?= htmlspecialchars($cat['name_bn'] ?: $cat['name']) ?></span>
                </a>
            </li>
            <?php endforeach; endif; ?>
        </ul>
        <div class="p-4 border-t space-y-1">
            <?php if (isCustomerLoggedIn()): ?>
            <a href="<?= url('account') ?>" class="flex items-center gap-3 py-2 text-gray-600 font-medium">
                <i class="fas fa-user w-5 text-center"></i> আমার একাউন্ট
            </a>
            <a href="<?= url('account?tab=wishlist') ?>" class="flex items-center gap-3 py-2 text-gray-600">
                <i class="far fa-heart w-5 text-center"></i> উইশলিস্ট
            </a>
            <?php
            // Review notification in mobile menu
            if (getSetting('reviews_enabled', '1') === '1') {
                $__mRevCount = $__pendingRevCount ?? 0;
                if ($__mRevCount > 0): ?>
            <a href="<?= url('account?tab=reviews') ?>" class="flex items-center gap-3 py-2 text-yellow-600 font-medium">
                <i class="fas fa-star w-5 text-center"></i> রিভিউ দিন
                <span class="ml-auto text-[10px] bg-red-500 text-white px-1.5 py-0.5 rounded-full"><?= $__mRevCount ?></span>
            </a>
            <?php endif; } ?>
            <a href="<?= url('track-order') ?>" class="flex items-center gap-3 py-2 text-gray-600">
                <i class="fas fa-truck w-5 text-center"></i> অর্ডার ট্র্যাক
            </a>
            <?php else: ?>
            <a href="<?= url('login') ?>" class="flex items-center gap-3 py-2 text-blue-600 font-medium">
                <i class="fas fa-sign-in-alt w-5 text-center"></i> লগইন / রেজিস্ট্রেশন
            </a>
            <a href="<?= url('track-order') ?>" class="flex items-center gap-3 py-2 text-gray-600">
                <i class="fas fa-truck w-5 text-center"></i> অর্ডার ট্র্যাক
            </a>
            <?php endif; ?>
            <a href="tel:<?= $sitePhone ?>" class="flex items-center gap-3 py-2 text-gray-600">
                <i class="fas fa-phone-alt w-5 text-center"></i> <?= $sitePhone ?>
            </a>
            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $siteWhatsapp) ?>" class="flex items-center gap-3 py-2 text-green-600">
                <i class="fab fa-whatsapp w-5 text-center"></i> WhatsApp
            </a>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="hidden fixed top-20 right-4 z-[9999] bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full">
    <div class="flex items-center gap-2">
        <i id="toast-icon" class="fas fa-times-circle"></i>
        <span id="toast-message"></span>
    </div>
</div>

<script>
// ── Live Search Autocomplete ──
let _searchTimer = null;
let _searchCache = {};
function liveSearch(query, resultId) {
    clearTimeout(_searchTimer);
    const box = document.getElementById(resultId);
    if (!box) return;
    if (query.length < 2) { box.classList.add('hidden'); return; }
    
    if (_searchCache[query]) { renderSearchResults(_searchCache[query], box, query); return; }
    
    _searchTimer = setTimeout(() => {
        fetch(SITE_URL + '/api/search.php?q=' + encodeURIComponent(query))
        .then(r => r.json())
        .then(data => {
            _searchCache[query] = data.results || [];
            renderSearchResults(data.results || [], box, query);
        }).catch(() => {});
    }, 250);
}

function renderSearchResults(results, box, query) {
    if (!results.length) {
        box.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm">কোন পণ্য পাওয়া যায়নি</div>';
        box.classList.remove('hidden');
        return;
    }
    let html = '';
    results.forEach(p => {
        const priceHtml = p.regular_price > p.price 
            ? `<span class="text-red-600 font-bold">${p.price_formatted}</span> <del class="text-gray-400 text-xs">${CURRENCY} ${Number(p.regular_price).toLocaleString()}</del>`
            : `<span class="text-red-600 font-bold">${p.price_formatted}</span>`;
        const stockBadge = !p.in_stock ? '<span class="text-xs text-red-400 ml-1">(স্টক শেষ)</span>' : '';
        html += `<a href="${SITE_URL}/${p.slug}" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 border-b border-gray-100 last:border-0 transition">
            <img src="${p.image}" class="w-12 h-12 rounded-lg object-cover border flex-shrink-0" alt="" onerror="this.src='${SITE_URL}/assets/img/default-product.svg'">
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">${p.name}</p>
                <p class="text-xs text-gray-400">${p.category || ''}</p>
            </div>
            <div class="text-right flex-shrink-0 text-sm">${priceHtml}${stockBadge}</div>
        </a>`;
    });
    html += `<a href="${SITE_URL}/search?q=${encodeURIComponent(query)}" class="block text-center text-sm text-blue-600 font-medium py-3 hover:bg-blue-50 transition">সব ফলাফল দেখুন →</a>`;
    box.innerHTML = html;
    box.classList.remove('hidden');
}

// Close search results on click outside
document.addEventListener('click', function(e) {
    document.querySelectorAll('#desktop-search-results, #mobile-search-results').forEach(box => {
        if (!box.contains(e.target) && !e.target.closest('#desktop-search-wrap') && !e.target.closest('#mobile-search')) {
            box.classList.add('hidden');
        }
    });
});

function toggleSearch() {
    const ms = document.getElementById('mobile-search');
    if (ms) {
        ms.classList.toggle('hidden');
        if (!ms.classList.contains('hidden')) {
            ms.querySelector('input[name="q"]')?.focus();
        }
    }
}

// ── Language (Bengali only) ──
const SITE_LANG = 'bn';
const LANG_DATA = {bn: {
    all_products: 'সকল পণ্য', search_placeholder: 'পণ্য খুঁজুন...', login: 'লগইন',
    my_account: 'আমার একাউন্ট', wishlist: 'উইশলিস্ট', track_order: 'অর্ডার ট্র্যাক',
    add_to_cart: 'কার্টে যোগ করুন', order_now: 'অর্ডার করুন', out_of_stock: 'স্টক শেষ',
    no_results: 'কোন পণ্য পাওয়া যায়নি', view_all: 'সব দেখুন →', total: 'মোট',
    subtotal: 'সাবটোটাল', shipping: 'ডেলিভারি চার্জ', place_order: 'অর্ডার কনফার্ম করুন',
    your_name: 'আপনার নাম', phone: 'মোবাইল নম্বর', address: 'সম্পূর্ণ ঠিকানা',
    description: 'বিবরণ', contact_us: 'যোগাযোগ'
}};
function t(key) { return (LANG_DATA.bn[key]) || key; }
</script>