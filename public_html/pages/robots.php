<?php
/**
 * Dynamic robots.txt
 * URL: /robots.txt (routed via index.php)
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

$siteUrl = rtrim(SITE_URL, '/');

// Check if custom robots.txt content exists in settings
$customRobots = getSetting('robots_txt', '');

if (trim($customRobots)) {
    // Use custom content but ensure sitemap is included
    echo $customRobots;
    if (stripos($customRobots, 'sitemap') === false) {
        echo "\n\nSitemap: {$siteUrl}/sitemap.xml\n";
    }
} else {
    // Default robots.txt
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "\n";
    echo "# Disallow admin and API\n";
    echo "Disallow: /admin/\n";
    echo "Disallow: /api/\n";
    echo "Disallow: /config/\n";
    echo "Disallow: /includes/\n";
    echo "Disallow: /tmp/\n";
    echo "Disallow: /cache/\n";
    echo "Disallow: /cart\n";
    echo "Disallow: /login\n";
    echo "Disallow: /register\n";
    echo "Disallow: /account\n";
    echo "Disallow: /forgot-password\n";
    echo "Disallow: /reset-password\n";
    echo "Disallow: /order-success\n";
    echo "Disallow: /checkout\n";
    echo "\n";
    echo "# Allow search engines to crawl CSS/JS/images\n";
    echo "Allow: /css/\n";
    echo "Allow: /js/\n";
    echo "Allow: /assets/\n";
    echo "Allow: /uploads/\n";
    echo "\n";
    echo "Sitemap: {$siteUrl}/sitemap.xml\n";
}
