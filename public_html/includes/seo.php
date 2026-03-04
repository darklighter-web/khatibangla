<?php
/**
 * Auto-SEO System — khatibangla.com
 * 
 * ZERO-CONFIG: Generates smart SEO defaults from existing page content.
 * Manual overrides (meta_title, meta_description in admin) take priority.
 */

function seoCanonical(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'khatibangla.com';
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    return rtrim($scheme . '://' . $host . $path, '/');
}

function seoTruncate(string $text, int $maxLen): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    if (mb_strlen($text) <= $maxLen) return $text;
    $cut = mb_substr($text, 0, $maxLen);
    $lastSpace = mb_strrpos($cut, ' ');
    $lastPeriod = mb_strrpos($cut, '।');
    $breakAt = max($lastSpace ?: 0, $lastPeriod ?: 0);
    if ($breakAt > $maxLen * 0.6) $cut = mb_substr($cut, 0, $breakAt);
    return rtrim($cut, ' ,।.') . '...';
}

// ── Auto Meta Title ──
function seoAutoTitle(array $seo): string {
    $siteName = getSetting('site_name', 'KhatiBangla');
    $tagline = getSetting('site_tagline', '');
    $type = $seo['type'] ?? 'website';
    
    if (!empty($seo['title']) && $seo['title'] !== $siteName) return $seo['title'];
    
    switch ($type) {
        case 'home':
            if ($tagline) return $siteName . ' — ' . $tagline;
            $siteDesc = getSetting('site_description', '');
            if ($siteDesc) return $siteName . ' — ' . seoTruncate($siteDesc, 40);
            return $siteName . ' — অনলাইন শপিং বাংলাদেশ';
        case 'product':
            $p = $seo['product'] ?? [];
            $name = ($p['name_bn'] ?? '') ?: ($p['name'] ?? '');
            if (!$name) return $siteName;
            $price = '';
            if (!empty($p['sale_price']) && $p['sale_price'] > 0 && $p['sale_price'] < ($p['regular_price'] ?? 0))
                $price = ' — ৳' . number_format((float)$p['sale_price']);
            elseif (!empty($p['regular_price']))
                $price = ' — ৳' . number_format((float)$p['regular_price']);
            $title = $name . $price . ' | ' . $siteName;
            return mb_strlen($title) > 65 ? $name . $price : $title;
        case 'category':
            $cat = $seo['category_name'] ?? '';
            if ($cat) return $cat . ' — কিনুন সেরা দামে | ' . $siteName;
            return 'সকল পণ্য | ' . $siteName;
        case 'article':
            $post = $seo['article'] ?? [];
            $title = ($post['title_bn'] ?? '') ?: ($post['title'] ?? '');
            if ($title) return seoTruncate($title, 55) . ' | ' . $siteName;
            return 'ব্লগ | ' . $siteName;
        case 'shop':
            return 'সকল পণ্য — অনলাইনে অর্ডার করুন | ' . $siteName;
        default:
            return $seo['title'] ?? $siteName;
    }
}

// ── Auto Meta Description ──
function seoAutoDescription(array $seo): string {
    $siteName = getSetting('site_name', 'KhatiBangla');
    $type = $seo['type'] ?? 'website';
    
    if (!empty($seo['description'])) return seoTruncate($seo['description'], 160);
    
    switch ($type) {
        case 'home':
            $siteDesc = getSetting('site_description', '');
            if ($siteDesc) return seoTruncate($siteDesc, 160);
            $tagline = getSetting('site_tagline', '');
            if ($tagline) return $tagline . '। ' . $siteName . ' থেকে ঘরে বসে অর্ডার করুন। ক্যাশ অন ডেলিভারি। সারা বাংলাদেশে হোম ডেলিভারি।';
            return $siteName . ' — বাংলাদেশের বিশ্বস্ত অনলাইন শপ। সেরা মানের পণ্য, সেরা দামে। ক্যাশ অন ডেলিভারি।';
        case 'product':
            $p = $seo['product'] ?? [];
            $name = ($p['name_bn'] ?? '') ?: ($p['name'] ?? '');
            $desc = $p['short_description'] ?? '';
            if (!$desc) $desc = $p['description'] ?? '';
            $price = '';
            if (!empty($p['sale_price']) && $p['sale_price'] > 0 && $p['sale_price'] < ($p['regular_price'] ?? 0))
                $price = 'মাত্র ৳' . number_format((float)$p['sale_price']) . ' (আগের দাম ৳' . number_format((float)$p['regular_price']) . ')';
            elseif (!empty($p['regular_price']))
                $price = 'মাত্র ৳' . number_format((float)$p['regular_price']);
            if ($desc) {
                $d = seoTruncate(strip_tags($desc), 130);
                if ($price && mb_strlen($d) < 130) $d .= ' ' . $price . '।';
                return $d;
            }
            $cat = $p['category_name'] ?? '';
            return $name . ($price ? ' — ' . $price : '') . '। ' . ($cat ? $cat . '। ' : '') . $siteName . ' থেকে অর্ডার করুন। ক্যাশ অন ডেলিভারি।';
        case 'category':
            $catName = $seo['category_name'] ?? '';
            $catDesc = $seo['category_description'] ?? '';
            if ($catDesc) return seoTruncate($catDesc, 160);
            if ($catName) return $catName . ' — সেরা মানের পণ্য সেরা দামে কিনুন ' . $siteName . ' থেকে। ক্যাশ অন ডেলিভারি।';
            return 'সকল পণ্য ব্রাউজ করুন ' . $siteName . ' এ। ক্যাশ অন ডেলিভারি।';
        case 'article':
            $post = $seo['article'] ?? [];
            $excerpt = ($post['excerpt_bn'] ?? '') ?: ($post['excerpt'] ?? '');
            if ($excerpt) return seoTruncate($excerpt, 160);
            $content = ($post['content_bn'] ?? '') ?: ($post['content'] ?? '');
            if ($content) return seoTruncate(strip_tags($content), 160);
            return (($post['title_bn'] ?? '') ?: ($post['title'] ?? 'ব্লগ')) . ' — ' . $siteName;
        case 'shop':
            return $siteName . ' এর সকল পণ্য দেখুন। সেরা মানের পণ্য, সেরা দামে। ক্যাশ অন ডেলিভারি। সারা বাংলাদেশে হোম ডেলিভারি।';
        default:
            return getSetting('meta_description', '') ?: ($siteName . ' — বাংলাদেশের বিশ্বস্ত অনলাইন শপ।');
    }
}

// ── Auto OG Image ──
function seoAutoImage(array $seo): string {
    $siteUrl = rtrim(SITE_URL, '/');
    if (!empty($seo['image'])) {
        $img = $seo['image'];
        if ($img && !str_starts_with($img, 'http')) $img = $siteUrl . '/' . ltrim($img, '/');
        return $img;
    }
    $type = $seo['type'] ?? 'website';
    if ($type === 'product' && !empty($seo['product']['featured_image'])) {
        $img = $seo['product']['featured_image'];
        if (!str_starts_with($img, 'http')) $img = $siteUrl . '/uploads/products/' . basename($img);
        return $img;
    }
    if ($type === 'article' && !empty($seo['article']['featured_image'])) {
        $img = $seo['article']['featured_image'];
        if (!str_starts_with($img, 'http')) $img = $siteUrl . '/uploads/blog/' . basename($img);
        return $img;
    }
    if ($type === 'category' && !empty($seo['category_image'])) {
        $img = $seo['category_image'];
        if (!str_starts_with($img, 'http')) $img = $siteUrl . '/uploads/categories/' . basename($img);
        return $img;
    }
    $ogImage = getSetting('og_image', '');
    if ($ogImage) { if (!str_starts_with($ogImage, 'http')) $ogImage = $siteUrl . '/' . ltrim($ogImage, '/'); return $ogImage; }
    $logo = getSetting('site_logo', '');
    if ($logo) { if (!str_starts_with($logo, 'http')) $logo = $siteUrl . '/' . ltrim($logo, '/'); return $logo; }
    return '';
}

// ── Auto Keywords ──
function seoAutoKeywords(array $seo): string {
    if (!empty($seo['keywords'])) return $seo['keywords'];
    $siteName = getSetting('site_name', 'KhatiBangla');
    $defaultKw = getSetting('meta_keywords', '');
    $type = $seo['type'] ?? 'website';
    if ($type === 'product') {
        $p = $seo['product'] ?? [];
        $kw = [];
        if (!empty($p['name_bn'])) $kw[] = $p['name_bn'];
        if (!empty($p['name']) && ($p['name'] !== ($p['name_bn'] ?? ''))) $kw[] = $p['name'];
        if (!empty($p['tags'])) $kw = array_merge($kw, array_map('trim', explode(',', $p['tags'])));
        if (!empty($p['category_name'])) $kw[] = $p['category_name'];
        $kw[] = $siteName;
        return implode(', ', array_unique(array_filter($kw)));
    }
    if ($type === 'category') {
        $catName = $seo['category_name'] ?? '';
        if ($catName) return $catName . ', ' . $siteName . ', অনলাইন শপিং, বাংলাদেশ';
    }
    return $defaultKw ?: ($siteName . ', অনলাইন শপিং, বাংলাদেশ, ক্যাশ অন ডেলিভারি');
}

// ══════════════════════════════════════════
// RENDER: All <head> SEO tags
// ══════════════════════════════════════════
function seoRenderHead(array $seo = []): string {
    $siteName = getSetting('site_name', 'KhatiBangla');
    $gaId = getSetting('google_analytics_id', '');
    $fbPixel = getSetting('facebook_pixel_id', '');
    $ttPixel = getSetting('tiktok_pixel_id', '');
    $gscVerify = getSetting('google_site_verification', '');
    $bingVerify = getSetting('bing_site_verification', '');
    $type = $seo['type'] ?? 'website';
    
    $title = seoAutoTitle($seo);
    $desc = seoAutoDescription($seo);
    $image = seoAutoImage($seo);
    $keywords = seoAutoKeywords($seo);
    $url = $seo['url'] ?? seoCanonical();
    $noindex = $seo['noindex'] ?? false;
    $locale = $seo['locale'] ?? 'bn_BD';
    $price = $seo['price'] ?? '';
    $currency = $seo['currency'] ?? 'BDT';
    
    $html = '';
    $html .= '    <link rel="canonical" href="' . htmlspecialchars($url) . '">' . "\n";
    $html .= $noindex
        ? '    <meta name="robots" content="noindex, nofollow">' . "\n"
        : '    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">' . "\n";
    if ($keywords) $html .= '    <meta name="keywords" content="' . htmlspecialchars($keywords) . '">' . "\n";
    if (!empty($seo['author'])) $html .= '    <meta name="author" content="' . htmlspecialchars($seo['author']) . '">' . "\n";
    
    // OG
    $ogType = match($type) { 'product' => 'product', 'article','blog' => 'article', default => 'website' };
    $html .= '    <meta property="og:type" content="' . $ogType . '">' . "\n";
    $html .= '    <meta property="og:title" content="' . htmlspecialchars($title) . '">' . "\n";
    $html .= '    <meta property="og:description" content="' . htmlspecialchars($desc) . '">' . "\n";
    $html .= '    <meta property="og:url" content="' . htmlspecialchars($url) . '">' . "\n";
    $html .= '    <meta property="og:site_name" content="' . htmlspecialchars($siteName) . '">' . "\n";
    $html .= '    <meta property="og:locale" content="' . $locale . '">' . "\n";
    if ($image) {
        $html .= '    <meta property="og:image" content="' . htmlspecialchars($image) . '">' . "\n";
        $html .= '    <meta property="og:image:width" content="1200">' . "\n";
        $html .= '    <meta property="og:image:height" content="630">' . "\n";
    }
    if ($ogType === 'product' && $price) {
        $html .= '    <meta property="product:price:amount" content="' . $price . '">' . "\n";
        $html .= '    <meta property="product:price:currency" content="' . $currency . '">' . "\n";
    }
    if ($ogType === 'article') {
        if (!empty($seo['published_time'])) $html .= '    <meta property="article:published_time" content="' . $seo['published_time'] . '">' . "\n";
        if (!empty($seo['modified_time'])) $html .= '    <meta property="article:modified_time" content="' . $seo['modified_time'] . '">' . "\n";
    }
    // Twitter
    $html .= '    <meta name="twitter:card" content="summary_large_image">' . "\n";
    $html .= '    <meta name="twitter:title" content="' . htmlspecialchars($title) . '">' . "\n";
    $html .= '    <meta name="twitter:description" content="' . htmlspecialchars($desc) . '">' . "\n";
    if ($image) $html .= '    <meta name="twitter:image" content="' . htmlspecialchars($image) . '">' . "\n";
    // Verification
    if ($gscVerify) $html .= '    <meta name="google-site-verification" content="' . htmlspecialchars($gscVerify) . '">' . "\n";
    if ($bingVerify) $html .= '    <meta name="msvalidate.01" content="' . htmlspecialchars($bingVerify) . '">' . "\n";
    // Preconnect
    $html .= '    <link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    $html .= '    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    // GA
    if ($gaId) {
        $html .= '    <script async src="https://www.googletagmanager.com/gtag/js?id=' . htmlspecialchars($gaId) . '"></script>' . "\n";
        $html .= '    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());gtag("config","' . htmlspecialchars($gaId) . '");</script>' . "\n";
    }
    // FB Pixel
    if ($fbPixel) {
        $html .= "    <script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . htmlspecialchars($fbPixel) . "');fbq('track','PageView');</script>\n";
        $html .= '    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=' . htmlspecialchars($fbPixel) . '&ev=PageView&noscript=1"></noscript>' . "\n";
    }
    // TT Pixel
    if ($ttPixel) {
        $html .= "    <script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=i;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};var o=document.createElement('script');o.type='text/javascript';o.async=!0;o.src=i+'?sdkid='+e+'&lib='+t;var a=document.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load('" . htmlspecialchars($ttPixel) . "');ttq.page()}(window,document,'ttq');</script>\n";
    }
    return $html;
}

// ══════════════════════════════════════════
// JSON-LD Structured Data
// ══════════════════════════════════════════
function seoSchemaOrganization(): array {
    $siteName = getSetting('site_name', 'KhatiBangla');
    $siteUrl = rtrim(SITE_URL, '/');
    $logo = getSetting('site_logo', '');
    if ($logo && !str_starts_with($logo, 'http')) $logo = $siteUrl . '/' . ltrim($logo, '/');
    $phone = getSetting('site_phone', '') ?: getSetting('hotline_number', '');
    $email = getSetting('site_email', '') ?: getSetting('order_notification_email', '');
    $address = getSetting('site_address', '');
    $org = ['@type'=>'Organization','@id'=>$siteUrl.'/#organization','name'=>$siteName,'url'=>$siteUrl];
    if ($logo) $org['logo'] = ['@type'=>'ImageObject','url'=>$logo];
    if ($phone) $org['telephone'] = $phone;
    if ($email) $org['email'] = $email;
    if ($address) $org['address'] = ['@type'=>'PostalAddress','addressLocality'=>'Dhaka','addressCountry'=>'BD','streetAddress'=>$address];
    $social = [];
    foreach (['social_facebook','social_instagram','social_youtube','social_tiktok'] as $k) { $v = getSetting($k, ''); if ($v) $social[] = $v; }
    if ($social) $org['sameAs'] = $social;
    return $org;
}

function seoSchemaWebSite(): array {
    $siteUrl = rtrim(SITE_URL, '/');
    return ['@type'=>'WebSite','@id'=>$siteUrl.'/#website','name'=>getSetting('site_name','KhatiBangla'),'description'=>getSetting('site_description','') ?: getSetting('site_tagline',''),'url'=>$siteUrl,'potentialAction'=>['@type'=>'SearchAction','target'=>['@type'=>'EntryPoint','urlTemplate'=>$siteUrl.'/search?q={search_term_string}'],'query-input'=>'required name=search_term_string']];
}

function seoSchemaProduct(array $p): array {
    $siteUrl = rtrim(SITE_URL, '/');
    $siteName = getSetting('site_name', 'KhatiBangla');
    $name = ($p['name_bn'] ?? '') ?: ($p['name'] ?? '');
    $desc = strip_tags($p['short_description'] ?? $p['description'] ?? '');
    if (mb_strlen($desc) > 200) $desc = mb_substr($desc, 0, 200) . '...';
    if (!$desc) $desc = $name . ' — ' . $siteName . ' থেকে কিনুন।';
    $img = '';
    if (!empty($p['featured_image'])) { $img = $p['featured_image']; if (!str_starts_with($img, 'http')) $img = $siteUrl . '/uploads/products/' . basename($img); }
    $price = (($p['sale_price'] ?? 0) > 0 && ($p['sale_price'] ?? 0) < ($p['regular_price'] ?? 0)) ? $p['sale_price'] : ($p['regular_price'] ?? 0);
    $avail = match($p['stock_status'] ?? 'in_stock') { 'out_of_stock'=>'https://schema.org/OutOfStock','pre_order'=>'https://schema.org/PreOrder',default=>'https://schema.org/InStock' };
    $s = ['@type'=>'Product','name'=>$name,'url'=>$siteUrl.'/product/'.($p['slug']??''),'description'=>$desc,'sku'=>$p['sku']??($p['slug']??''),'brand'=>['@type'=>'Brand','name'=>$siteName],'offers'=>['@type'=>'Offer','url'=>$siteUrl.'/product/'.($p['slug']??''),'priceCurrency'=>'BDT','price'=>number_format((float)$price,2,'.',''),'availability'=>$avail,'seller'=>['@type'=>'Organization','name'=>$siteName],'shippingDetails'=>['@type'=>'OfferShippingDetails','shippingDestination'=>['@type'=>'DefinedRegion','addressCountry'=>'BD']]]];
    if ($img) $s['image'] = [$img];
    if (!empty($p['category_name'])) $s['category'] = $p['category_name'];
    if (!empty($p['avg_rating']) && $p['avg_rating'] > 0 && !empty($p['review_count']) && $p['review_count'] > 0)
        $s['aggregateRating'] = ['@type'=>'AggregateRating','ratingValue'=>round((float)$p['avg_rating'],1),'reviewCount'=>(int)$p['review_count'],'bestRating'=>5,'worstRating'=>1];
    return $s;
}

function seoSchemaBreadcrumb(array $items): array {
    $list = [];
    foreach ($items as $i => $item) {
        $entry = ['@type'=>'ListItem','position'=>$i+1,'name'=>$item['name']];
        if ($i < count($items) - 1 && !empty($item['url'])) $entry['item'] = $item['url'];
        $list[] = $entry;
    }
    return ['@type'=>'BreadcrumbList','itemListElement'=>$list];
}

function seoSchemaArticle(array $post): array {
    $siteUrl = rtrim(SITE_URL, '/');
    $siteName = getSetting('site_name', 'KhatiBangla');
    $title = ($post['title_bn'] ?? '') ?: ($post['title'] ?? '');
    $desc = strip_tags($post['excerpt'] ?? $post['content'] ?? '');
    if (mb_strlen($desc) > 200) $desc = mb_substr($desc, 0, 200) . '...';
    $img = '';
    if (!empty($post['featured_image'])) { $img = $post['featured_image']; if (!str_starts_with($img,'http')) $img = $siteUrl.'/uploads/blog/'.basename($img); }
    $logo = getSetting('site_logo','');
    if ($logo && !str_starts_with($logo,'http')) $logo = $siteUrl.'/'.ltrim($logo,'/');
    $s = ['@type'=>'Article','headline'=>$title,'description'=>$desc,'url'=>$siteUrl.'/blog/'.($post['slug']??''),'author'=>['@type'=>'Person','name'=>$post['author_name']??'Admin'],'publisher'=>['@type'=>'Organization','name'=>$siteName]];
    if ($logo) $s['publisher']['logo'] = ['@type'=>'ImageObject','url'=>$logo];
    if ($img) $s['image'] = [$img];
    if (!empty($post['published_at'])) $s['datePublished'] = date('c', strtotime($post['published_at']));
    if (!empty($post['updated_at'])) $s['dateModified'] = date('c', strtotime($post['updated_at']));
    return $s;
}

function seoRenderJsonLd(array $seo = []): string {
    $graphs = [];
    $type = $seo['type'] ?? 'website';
    if ($type === 'home' || $type === 'website') { $graphs[] = seoSchemaOrganization(); $graphs[] = seoSchemaWebSite(); }
    if ($type === 'product' && !empty($seo['product'])) $graphs[] = seoSchemaProduct($seo['product']);
    if ($type === 'article' && !empty($seo['article'])) $graphs[] = seoSchemaArticle($seo['article']);
    if (!empty($seo['breadcrumbs'])) $graphs[] = seoSchemaBreadcrumb($seo['breadcrumbs']);
    if (empty($graphs)) return '';
    $jsonLd = ['@context' => 'https://schema.org'];
    if (count($graphs) === 1) $jsonLd = array_merge($jsonLd, $graphs[0]);
    else $jsonLd['@graph'] = $graphs;
    return '<script type="application/ld+json">' . json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>' . "\n";
}
