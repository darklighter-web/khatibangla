<?php
/**
 * Admin Authentication & Permission System v2
 * 
 * Permission format: "module.action" e.g. "orders.view", "products.edit"
 * Super admin role has implicit access to everything.
 * Permissions are refreshed from DB on every page load (role + custom merged).
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/functions.php';

// Version marker — used by permission-debug.php to verify deployment
define('PERM_SYSTEM_VERSION', '2.4');

function adminLogin($username, $password) {
    $db = Database::getInstance();
    $user = $db->fetch("SELECT au.*, ar.role_name, ar.permissions FROM admin_users au JOIN admin_roles ar ON ar.id = au.role_id WHERE (au.username = ? OR au.email = ?) AND au.is_active = 1", [$username, $username]);
    
    if ($user && verifyPassword($password, $user['password'])) {
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_name'] = $user['full_name'];
        $_SESSION['admin_role'] = $user['role_name'];
        // Permissions will be refreshed on next page load via refreshAdminPermissions()
        $_SESSION['admin_permissions'] = [];
        
        $db->update('admin_users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        logActivity($user['id'], 'login', 'admin_users', $user['id']);
        return true;
    }
    return false;
}

function adminLogout() {
    if (isset($_SESSION['admin_id'])) {
        logActivity($_SESSION['admin_id'], 'logout');
    }
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['admin_permissions']);
    session_destroy();
}

function isAdminLoggedIn() {
    return isset($_SESSION['admin_id']);
}

/**
 * Refresh permissions from DB on every page load.
 * Merges: role permissions + user custom_permissions (union).
 * Also refreshes role_name in case it changed.
 */
function refreshAdminPermissions() {
    if (!isAdminLoggedIn()) return;
    
    // Skip refresh if view-as-role is active (handled separately in header.php)
    if (!empty($_SESSION['view_as_role_id'])) return;
    
    static $refreshed = false;
    if ($refreshed) return;
    $refreshed = true;
    
    try {
        $db = Database::getInstance();
        
        // Primary query — does NOT depend on custom_permissions column
        $user = $db->fetch(
            "SELECT au.role_id, ar.role_name, ar.permissions AS role_permissions 
             FROM admin_users au 
             JOIN admin_roles ar ON ar.id = au.role_id 
             WHERE au.id = ? AND au.is_active = 1",
            [$_SESSION['admin_id']]
        );
        
        if (!$user) {
            // User deactivated or deleted — force logout
            adminLogout();
            if (!headers_sent()) {
                header('Location: ' . adminUrl('login.php'));
            }
            exit;
        }
        
        // Update role name in session
        $_SESSION['admin_role'] = $user['role_name'];
        
        // Super admin gets implicit all access — no need to merge
        if ($user['role_name'] === 'super_admin') {
            $_SESSION['admin_permissions'] = ['all'];
            return;
        }
        
        // Decode role permissions (this is the PRIMARY source of truth)
        $rolePerms = json_decode($user['role_permissions'] ?? '[]', true);
        if (!is_array($rolePerms)) $rolePerms = [];
        
        // Try to get custom_permissions (optional — may not exist yet)
        $customPerms = [];
        try {
            $cp = $db->fetch("SELECT custom_permissions FROM admin_users WHERE id = ?", [$_SESSION['admin_id']]);
            if ($cp && !empty($cp['custom_permissions'])) {
                $customPerms = json_decode($cp['custom_permissions'], true);
                if (!is_array($customPerms)) $customPerms = [];
            }
        } catch (\Throwable $e) {
            // custom_permissions column doesn't exist yet — that's OK
        }
        
        // Merge (union) — role + custom, deduplicated
        $merged = array_values(array_unique(array_merge($rolePerms, $customPerms)));
        
        $_SESSION['admin_permissions'] = $merged;
    } catch (\Throwable $e) {
        // Critical DB error — set empty permissions (deny all) rather than keep stale
        $_SESSION['admin_permissions'] = [];
    }
}

function requireAdmin() {
    if (!isAdminLoggedIn()) {
        // Check gate cookie before redirecting (hide admin panel existence)
        $secretKey = getSetting('admin_secret_key', 'menzio2026');
        $gateValid = isset($_COOKIE['_adm_gate']) && $_COOKIE['_adm_gate'] === hash('sha256', $secretKey . date('Ymd') . 'gate');
        
        if ($gateValid) {
            redirect(adminUrl('login.php'));
        } else {
            http_response_code(404);
            echo '<!DOCTYPE html><html><head><title>404 Not Found</title><style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f5f5;color:#333}div{text-align:center}h1{font-size:120px;font-weight:200;margin:0;color:#ddd}p{font-size:18px;color:#999}</style></head><body><div><h1>404</h1><p>The page you are looking for does not exist.</p></div></body></html>';
            exit;
        }
    }
}

/**
 * Check if current admin has a specific permission.
 * Accepts: "orders.view", "orders" (matches any orders.* action), or "all"
 */
function hasPermission($permission) {
    if (!isAdminLoggedIn()) return false;
    if (isSuperAdmin() && empty($_SESSION['view_as_role_id'])) return true;
    
    $perms = $_SESSION['admin_permissions'] ?? [];
    if (!is_array($perms)) return false;
    if (in_array('all', $perms)) return true;
    
    // Exact match
    if (in_array($permission, $perms)) return true;
    
    // Module-level check: "orders" matches "orders.view", "orders.edit", etc.
    if (strpos($permission, '.') === false) {
        foreach ($perms as $p) {
            if ($p === $permission || strpos($p, $permission . '.') === 0) return true;
        }
    }
    
    return false;
}

// ── Complete Page-to-Permission Module Mapping ──
// Maps admin page filenames to required permission module.
// null = always visible (dashboard, profile, search)
function getPagePermission($page) {
    $map = [
        // ── Always visible (no permission needed) ──
        'dashboard' => null,
        'search' => null,
        'profile' => null,
        'notifications' => null,
        
        // ── Orders ──
        'order-management' => 'orders',
        'order-view' => 'orders',
        'order-add' => 'orders',
        'order-print' => 'orders',
        'web-orders' => 'orders',
        'approved-orders' => 'orders',
        'orders' => 'orders',
        'incomplete-orders' => 'orders',
        'scan-to-update' => 'orders',
        
        // ── Products / Catalog ──
        'products' => 'products',
        'product-form' => 'products',
        'categories' => 'categories',
        'media' => 'products',
        'reviews' => 'products',
        
        // ── Inventory ──
        'inventory' => 'inventory',
        'inventory-dashboard' => 'inventory',
        'stock-decrease-new' => 'inventory',
        'stock-decrease-list' => 'inventory',
        'stock-increase' => 'inventory',
        'stock-increase-new' => 'inventory',
        'stock-increase-list' => 'inventory',
        'stock-transfer' => 'inventory',
        'smart-restock' => 'inventory',
        
        // ── Customers ──
        'customers' => 'customers',
        'customer-view' => 'customers',
        'visitors' => 'customers',
        
        // ── Coupons ──
        'coupons' => 'coupons',
        
        // ── Shipping / Courier ──
        'courier' => 'courier',
        'returns' => 'returns',
        
        // ── Finance ──
        'accounting' => 'accounting',
        'expenses' => 'expenses',
        'expense-new' => 'expenses',
        'ad-expense-new' => 'expenses',
        'income' => 'accounting',
        'liabilities' => 'accounting',
        
        // ── Reports ──
        'reports' => 'reports',
        'report-profit-sales' => 'reports',
        'report-employees' => 'reports',
        'report-orders' => 'reports',
        'report-products' => 'reports',
        'report-web-orders' => 'reports',
        'report-meta-ads' => 'reports',
        'report-business' => 'reports',
        
        // ── Marketing (module: marketing) ──
        'meta-ads-report' => 'marketing',
        'meta-ads-campaigns' => 'marketing',
        
        // ── Content / CMS (module: cms_pages) ──
        'page-builder' => 'cms_pages',
        'landing-pages' => 'cms_pages',
        'landing-page-builder' => 'cms_pages',
        'banners' => 'banners',
        'cms-pages' => 'cms_pages',
        'blog' => 'cms_pages',
        'progress-bars' => 'cms_pages',
        'shop-design' => 'settings',
        'checkout-fields' => 'settings',
        
        // ── Support ──
        'live-chat' => 'orders',
        'chat-settings' => 'settings',
        'call-center' => 'orders',
        
        // ── Team / HRM ──
        'employees' => 'employees',
        'tasks' => 'tasks',
        
        // ── System (admin/settings) ──
        'settings' => 'settings',
        'select-invoice' => 'settings',
        'select-sticker' => 'settings',
        'speed' => 'settings',
        'security' => 'settings',
        'api-health' => 'settings',
        'api-diagnostic' => 'settings',
        'reset-test-data' => 'settings',
    ];
    // SAFETY: Unmapped pages default to 'settings' (restricted) instead of null (open)
    // This prevents new pages from being accidentally accessible to all users
    // NOTE: Cannot use ?? here because null is a valid value (= always visible)
    return array_key_exists($page, $map) ? $map[$page] : 'settings';
}

/**
 * Check if current admin can view a page (used by sidebar).
 * Returns true if user has ANY action permission in the page's module.
 */
function canViewPage($page) {
    if (isSuperAdmin() && empty($_SESSION['view_as_role_id'])) return true;
    $module = getPagePermission($page);
    if ($module === null) return true; // Pages with null = always visible
    return hasPermission($module);
}

/**
 * Enforce permission for current page. Call at top of pages or in header.php.
 * Silently redirects to dashboard if unauthorized.
 */
function requirePermission($module, $action = 'view') {
    if (isSuperAdmin() && empty($_SESSION['view_as_role_id'])) return;
    $perm = $action ? ($module . '.' . $action) : $module;
    if (!hasPermission($perm) && !hasPermission($module)) {
        $_SESSION['flash_error'] = '🚫 আপনার এই পেজে প্রবেশের অনুমতি নেই।';
        redirect(adminUrl('pages/dashboard.php'));
    }
}

/**
 * Auto-enforce permission based on current page filename.
 * Shows a professional "Access Denied" page if unauthorized.
 */
function enforcePagePermission() {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
    $module = getPagePermission($currentPage);
    if ($module === null) return; // Always-visible pages
    if (isSuperAdmin() && empty($_SESSION['view_as_role_id'])) return;
    if (hasPermission($module)) return; // Has permission — allow
    
    // ── Render Friendly Restricted Page ──
    http_response_code(403);
    $adminName = getAdminName();
    $dashUrl = adminUrl('pages/dashboard.php');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Restricted</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:"Inter",sans-serif}@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}.float{animation:float 3s ease-in-out infinite}</style>
</head>
<body class="bg-gradient-to-br from-slate-50 to-blue-50 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-sm w-full text-center">
        <div class="float mb-6">
            <svg class="w-24 h-24 mx-auto" viewBox="0 0 120 120" fill="none">
                <circle cx="60" cy="60" r="50" fill="#EEF2FF" stroke="#C7D2FE" stroke-width="2"/>
                <rect x="42" y="38" width="36" height="28" rx="4" fill="#A5B4FC" stroke="#818CF8" stroke-width="2"/>
                <rect x="42" y="38" width="36" height="12" rx="4" fill="#818CF8"/>
                <circle cx="60" cy="74" r="3" fill="#6366F1"/>
                <path d="M52 30 L52 38" stroke="#818CF8" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M68 30 L68 38" stroke="#818CF8" stroke-width="2.5" stroke-linecap="round"/>
                <circle cx="52" cy="27" r="3" fill="#C7D2FE"/>
                <circle cx="68" cy="27" r="3" fill="#C7D2FE"/>
            </svg>
        </div>
        <h1 class="text-xl font-bold text-gray-800 mb-2">This page isn\'t available to you</h1>
        <p class="text-gray-400 text-sm mb-8 leading-relaxed">এই পেজটি আপনার অ্যাকাউন্টে এখন দেখা যাচ্ছে না।<br>প্রয়োজনে আপনার অ্যাডমিনের সাথে যোগাযোগ করুন।</p>
        <a href="' . $dashUrl . '" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-8 rounded-xl text-sm transition shadow-lg shadow-blue-600/20 hover:shadow-blue-600/30">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Go to Dashboard
        </a>
        <p class="text-xs text-gray-300 mt-8">' . htmlspecialchars($adminName) . '</p>
    </div>
</body>
</html>';
    exit;
}

// ═══════════════════════════════════════════════════════════════
// AUTO-ENFORCEMENT: Runs immediately when auth.php is included
// by any admin page. This ensures permission check happens BEFORE
// any page-level PHP code (POST handlers, DB queries, etc.)
// ═══════════════════════════════════════════════════════════════
(function() {
    $script = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    // Only auto-enforce for admin PAGES — skip APIs, login, index
    if (strpos($script, '/admin/pages/') !== false) {
        requireAdmin();
        refreshAdminPermissions();
        enforcePagePermission();
    }
})();

function getAdminId() {
    return $_SESSION['admin_id'] ?? 0;
}

function isSuperAdmin() {
    return ($_SESSION['admin_role'] ?? '') === 'super_admin';
}

function getAdminName() {
    return $_SESSION['admin_name'] ?? 'Admin';
}

// Quick stats functions
function getDashboardStats() {
    $db = Database::getInstance();
    return [
        'total_orders' => $db->count('orders'),
        'pending_orders' => $db->count('orders', "order_status IN ('pending','processing')"),
        'approved_orders' => $db->count('orders', "order_status NOT IN ('pending','processing','cancelled')"),
        'confirmed_orders' => $db->count('orders', "order_status = 'confirmed'"),
        'processing_orders' => $db->count('orders', "order_status = 'processing'"),
        'shipped_orders' => $db->count('orders', "order_status = 'shipped'"),
        'delivered_orders' => $db->count('orders', "order_status = 'delivered'"),
        'cancelled_orders' => $db->count('orders', "order_status = 'cancelled'"),
        'returned_orders' => $db->count('orders', "order_status = 'returned'"),
        'today_orders' => $db->count('orders', "DATE(created_at) = CURDATE()"),
        'today_revenue' => $db->fetch("SELECT COALESCE(SUM(total), 0) as rev FROM orders WHERE DATE(created_at) = CURDATE() AND order_status NOT IN ('cancelled','returned')")['rev'],
        'month_revenue' => $db->fetch("SELECT COALESCE(SUM(total), 0) as rev FROM orders WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND order_status NOT IN ('cancelled','returned')")['rev'],
        'total_products' => $db->count('products'),
        'low_stock' => $db->count('products', "stock_quantity <= low_stock_threshold AND manage_stock = 1"),
        'total_customers' => $db->count('customers'),
        'blocked_customers' => $db->count('customers', "is_blocked = 1"),
        'incomplete_orders' => (function() use ($db) { try { return $db->count('incomplete_orders', "is_recovered = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"); } catch (\Throwable $e) { try { return $db->count('incomplete_orders', "recovered = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"); } catch (\Throwable $e2) { return 0; } } })(),
        'fake_orders' => $db->count('orders', "is_fake = 1"),
        'unread_notifications' => $db->count('notifications', "is_read = 0"),
        'pending_tasks' => $db->count('tasks', "status IN ('pending','in_progress')"),
        'chat_waiting' => (function() use ($db) { try { return $db->count('chat_conversations', "status IN ('waiting','active')"); } catch (\Throwable $e) { return 0; } })(),
    ];
}

function getRecentOrders($limit = 10) {
    $db = Database::getInstance();
    return $db->fetchAll("SELECT * FROM orders ORDER BY created_at DESC LIMIT ?", [$limit]);
}

function getOrderStatusBadge($status) {
    $badges = [
        'pending' => 'bg-yellow-100 text-yellow-800',
        'confirmed' => 'bg-blue-100 text-blue-800',
        'processing' => 'bg-indigo-100 text-indigo-800',
        'ready_to_ship' => 'bg-violet-100 text-violet-800',
        'shipped' => 'bg-purple-100 text-purple-800',
        'delivered' => 'bg-green-100 text-green-800',
        'cancelled' => 'bg-red-100 text-red-800',
        'returned' => 'bg-orange-100 text-orange-800',
        'on_hold' => 'bg-gray-100 text-gray-800',
        'no_response' => 'bg-rose-100 text-rose-800',
        'good_but_no_response' => 'bg-teal-100 text-teal-800',
        'advance_payment' => 'bg-emerald-100 text-emerald-800',
        'incomplete' => 'bg-amber-100 text-amber-800',
        'pending_return' => 'bg-amber-100 text-amber-800',
        'pending_cancel' => 'bg-pink-100 text-pink-800',
        'partial_delivered' => 'bg-cyan-100 text-cyan-800',
        'lost' => 'bg-stone-100 text-stone-800',
    ];
    return $badges[$status] ?? 'bg-gray-100 text-gray-800';
}

function getOrderStatusLabel($status) {
    $labels = [
        'pending' => 'Processing',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'ready_to_ship' => 'RTS',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
        'on_hold' => 'On Hold',
        'no_response' => 'No Response',
        'good_but_no_response' => 'Good But No Response',
        'advance_payment' => 'Advance Payment',
        'incomplete' => 'Incomplete',
        'pending_return' => 'Pending Return',
        'pending_cancel' => 'Pending Cancel',
        'partial_delivered' => 'Partial Delivered',
        'lost' => 'Lost',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function timeAgo($datetime) {
    if (empty($datetime)) return '';
    $now = time();
    $ago = strtotime($datetime);
    if (!$ago) return '';
    $diff = $now - $ago;
    if ($diff < 60)           return 'just now';
    if ($diff < 3600)         return floor($diff / 60) . ' min ago';
    if ($diff < 86400)        return 'about ' . floor($diff / 3600) . ' hours ago';
    if ($diff < 172800)       return 'yesterday';
    if ($diff < 604800)       return floor($diff / 86400) . ' days ago';
    if ($diff < 2592000)      return floor($diff / 604800) . ' weeks ago';
    return date('d M Y', $ago);
}

function getCustomerRating($phone) {
    if (empty($phone)) return 0;
    $db = Database::getInstance();
    $phoneLike = '%' . substr(preg_replace('/[^0-9]/', '', $phone), -10) . '%';
    try {
        $sr = $db->fetch("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered,
                   SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled,
                   SUM(CASE WHEN order_status='returned' THEN 1 ELSE 0 END) as returned
            FROM orders WHERE customer_phone LIKE ?
        ", [$phoneLike]);
        $total = intval($sr['total']);
        $delivered = intval($sr['delivered']);
        $cancelled = intval($sr['cancelled']);
        $returned = intval($sr['returned']);
        $rating = 50 + ($delivered * 10) - ($cancelled * 15) - ($returned * 20);
        return max(0, min(150, $rating));
    } catch (Exception $e) {
        return 0;
    }
}
