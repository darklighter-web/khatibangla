<?php
/**
 * Dynamic XML Sitemap Generator
 * URL: /sitemap.xml (routed via index.php)
 * 
 * Generates: products, categories, blog posts, static pages, landing pages
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

$db = Database::getInstance();
$siteUrl = rtrim(SITE_URL, '/');
$now = date('c');

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>

<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">

    <!-- Homepage -->
    <url>
        <loc><?= $siteUrl ?>/</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

    <!-- Shop -->
    <url>
        <loc><?= $siteUrl ?>/shop</loc>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>

    <!-- Categories page -->
    <url>
        <loc><?= $siteUrl ?>/categories</loc>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>

    <!-- Blog listing -->
    <url>
        <loc><?= $siteUrl ?>/blog</loc>
        <changefreq>daily</changefreq>
        <priority>0.7</priority>
    </url>

    <!-- Track Order -->
    <url>
        <loc><?= $siteUrl ?>/track-order</loc>
        <changefreq>monthly</changefreq>
        <priority>0.4</priority>
    </url>

<?php
// ── Products ──
try {
    $products = $db->fetchAll("SELECT slug, featured_image, updated_at FROM products WHERE is_active = 1 ORDER BY updated_at DESC");
    foreach ($products as $p):
        $img = !empty($p['featured_image']) ? $siteUrl . '/uploads/products/' . basename($p['featured_image']) : '';
        $lastmod = !empty($p['updated_at']) ? date('c', strtotime($p['updated_at'])) : $now;
?>
    <url>
        <loc><?= $siteUrl ?>/product/<?= htmlspecialchars($p['slug']) ?></loc>
        <lastmod><?= $lastmod ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
<?php if ($img): ?>
        <image:image>
            <image:loc><?= htmlspecialchars($img) ?></image:loc>
        </image:image>
<?php endif; ?>
    </url>
<?php
    endforeach;
} catch (\Throwable $e) {}

// ── Categories ──
try {
    $cats = $db->fetchAll("SELECT slug, updated_at FROM categories WHERE is_active = 1 ORDER BY sort_order");
    foreach ($cats as $c):
        $lastmod = !empty($c['updated_at']) ? date('c', strtotime($c['updated_at'])) : $now;
?>
    <url>
        <loc><?= $siteUrl ?>/category/<?= htmlspecialchars($c['slug']) ?></loc>
        <lastmod><?= $lastmod ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
<?php
    endforeach;
} catch (\Throwable $e) {}

// ── Blog Posts ──
try {
    $posts = $db->fetchAll("SELECT slug, featured_image, updated_at, published_at FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC");
    foreach ($posts as $bp):
        $lastmod = !empty($bp['updated_at']) ? date('c', strtotime($bp['updated_at'])) : (!empty($bp['published_at']) ? date('c', strtotime($bp['published_at'])) : $now);
        $bpImg = !empty($bp['featured_image']) ? $siteUrl . '/uploads/blog/' . basename($bp['featured_image']) : '';
?>
    <url>
        <loc><?= $siteUrl ?>/blog/<?= htmlspecialchars($bp['slug']) ?></loc>
        <lastmod><?= $lastmod ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
<?php if ($bpImg): ?>
        <image:image>
            <image:loc><?= htmlspecialchars($bpImg) ?></image:loc>
        </image:image>
<?php endif; ?>
    </url>
<?php
    endforeach;
} catch (\Throwable $e) {}

// ── Static Pages (CMS) ──
try {
    $pages = $db->fetchAll("SELECT slug, updated_at FROM pages WHERE is_active = 1 ORDER BY id");
    foreach ($pages as $pg):
        $lastmod = !empty($pg['updated_at']) ? date('c', strtotime($pg['updated_at'])) : $now;
?>
    <url>
        <loc><?= $siteUrl ?>/page/<?= htmlspecialchars($pg['slug']) ?></loc>
        <lastmod><?= $lastmod ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.5</priority>
    </url>
<?php
    endforeach;
} catch (\Throwable $e) {}

// ── Landing Pages (published only) ──
try {
    $lps = $db->fetchAll("SELECT slug, updated_at FROM landing_pages WHERE status = 'published' ORDER BY updated_at DESC");
    foreach ($lps as $lp):
        $lastmod = !empty($lp['updated_at']) ? date('c', strtotime($lp['updated_at'])) : $now;
?>
    <url>
        <loc><?= $siteUrl ?>/lp/<?= htmlspecialchars($lp['slug']) ?></loc>
        <lastmod><?= $lastmod ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
<?php
    endforeach;
} catch (\Throwable $e) {}
?>

</urlset>
