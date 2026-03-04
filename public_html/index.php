<?php
/**
 * Main Router / Entry Point
 */

// Prevent stale cached error pages from being served
header('X-LiteSpeed-Cache-Control: no-cache');
http_response_code(200); // Explicitly set 200 OK

require_once __DIR__ . '/includes/session.php';

require_once __DIR__ . '/includes/functions.php';

// Security Middleware — Firewall, Rate Limiting, Intrusion Detection
try { require_once __DIR__ . '/includes/security.php'; } catch (\Throwable $e) {}

// Queue/Preloader - shows wait page if server is overloaded
try { require_once __DIR__ . '/includes/queue.php'; } catch (\Throwable $e) {}

// Visitor Tracking (Feature #8) - track every page visit
try {
    require_once __DIR__ . '/includes/tracker.php';
    trackVisitor($page ?? 'home');
} catch (Exception $e) {}

// Simple Router
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = parse_url(SITE_URL, PHP_URL_PATH) ?: '';
$route = trim(str_replace($basePath, '', $requestUri), '/');
$segments = $route ? explode('/', $route) : [];

// ── Maintenance Mode ──
$_maintMode = getSetting('maintenance_mode', '0') === '1';
if ($_maintMode) {
    $_seg0 = $segments[0] ?? '';
    // Allow: admin panel, API, login, assets, and bypass key
    $_allowThrough = in_array($_seg0, ['admin','admin-panel','api','login','assets','uploads','css','js','favicon.ico']);
    $_hasBypass = !empty($_GET['bypass']) && getSetting('maintenance_bypass_key','') !== '' && $_GET['bypass'] === getSetting('maintenance_bypass_key','');
    $_isLoggedAdmin = !empty($_SESSION['admin_id']);
    
    if (!$_allowThrough && !$_isLoggedAdmin && !$_hasBypass) {
        include __DIR__ . '/maintenance.php';
        exit;
    }
}

// Route matching
$page = $segments[0] ?? 'home';
$param1 = $segments[1] ?? null;
$param2 = $segments[2] ?? null;

switch ($page) {
    case '':
    case 'home':
        include __DIR__ . '/pages/home.php';
        break;
        
    case 'product':
        $_GET['slug'] = $param1;
        include __DIR__ . '/pages/product.php';
        break;
        
    case 'category':
        $_GET['slug'] = $param1;
        include __DIR__ . '/pages/category.php';
        break;
    
    case 'shop':
    case 'all-products':
    case 'products':
        include __DIR__ . '/pages/shop.php';
        break;
        
    case 'search':
        include __DIR__ . '/pages/search.php';
        break;
        
    case 'cart':
        include __DIR__ . '/pages/cart.php';
        break;
        
    case 'page':
        $_GET['slug'] = $param1;
        include __DIR__ . '/pages/static-page.php';
        break;
        
    case 'track-order':
        include __DIR__ . '/pages/track-order.php';
        break;
        
    case 'order-success':
        include __DIR__ . '/pages/order-success.php';
        break;
        
    case 'categories':
        include __DIR__ . '/pages/categories.php';
        break;
        
    case 'checkout':
        include __DIR__ . '/pages/cart.php';
        break;
    
    case 'lp':
        $pageSlug = $param1;
        $_GET['slug'] = $param1;
        $lpPreview = isset($_GET['preview']) ? $_GET['preview'] : '';
        include __DIR__ . '/pages/landing-page.php';
        break;
        
    case 'login':
    case 'register':
        include __DIR__ . '/pages/login.php';
        break;

    case 'sso-callback':
        include __DIR__ . '/pages/sso-callback.php';
        break;
    
    case 'forgot-password':
        include __DIR__ . '/pages/forgot-password.php';
        break;
    
    case 'reset-password':
        include __DIR__ . '/pages/reset-password.php';
        break;
    
    case 'account':
        include __DIR__ . '/pages/account.php';
        break;

    case 'blog':
        if ($param1) {
            $_GET['slug'] = $param1;
            include __DIR__ . '/pages/blog-single.php';
        } else {
            include __DIR__ . '/pages/blog.php';
        }
        break;

    case 'sitemap.xml':
        include __DIR__ . '/pages/sitemap.php';
        break;

    case 'robots.txt':
        include __DIR__ . '/pages/robots.php';
        break;

    case 'maintenance-preview':
        // Admin-only preview of maintenance page
        if (!empty($_SESSION['admin_id'])) {
            include __DIR__ . '/maintenance.php';
        } else {
            http_response_code(404);
            include __DIR__ . '/pages/404.php';
        }
        break;
        
    default:
        // Try product slug first
        $product = getProductBySlug($page);
        if ($product) {
            $_GET['slug'] = $page;
            include __DIR__ . '/pages/product.php';
        } else {
            http_response_code(404);
            include __DIR__ . '/pages/404.php';
        }
        break;
}
