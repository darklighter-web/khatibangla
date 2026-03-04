<?php
/**
 * Admin Header & Sidebar Layout - Light/Dark/UI Theme + View-as-Role
 */
requireAdmin();
refreshAdminPermissions(); // Refresh permissions (may already be done by auth.php auto-enforcement)
// NOTE: enforcePagePermission() already runs in auth.php when included by admin pages
$stats = getDashboardStats();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$siteName = getSetting('site_name', 'E-Commerce');
$db = Database::getInstance();

// ── Theme System (light / dark / ui) ──
$adminTheme = 'ui';
try {
    $t = $db->fetch("SELECT admin_theme FROM admin_users WHERE id = ?", [getAdminId()]);
    if ($t && !empty($t['admin_theme'])) $adminTheme = $t['admin_theme'];
} catch (\Throwable $e) {}
if (!in_array($adminTheme, ['light', 'dark', 'ui'])) $adminTheme = 'ui';
$isDark = ($adminTheme === 'dark');
$isUI   = ($adminTheme === 'ui');
$isLight = ($adminTheme === 'light');

// ── Theme Color Map ──
if ($isUI) {
    $tc = [
        'body'=>'bg-gray-50','sidebar'=>'bg-gradient-to-b from-blue-800 to-blue-900',
        'sidebarBrand'=>'text-white','sidebarSub'=>'text-blue-300','sidebarBorder'=>'border-blue-700/50',
        'sidebarIcon'=>'bg-white/20','sidebarIconTxt'=>'text-white',
        'sidebarBottom'=>'border-blue-700/50','sidebarBottomLink'=>'text-blue-200 hover:text-white hover:bg-blue-700/50',
        'navActive'=>'bg-blue-700 text-white','navNormal'=>'text-blue-100 hover:bg-blue-700/50',
        'navSection'=>'text-blue-300/60',
        'navGroupOpen'=>'bg-blue-700/50 text-white','navGroupNorm'=>'text-blue-100 hover:bg-blue-700/50',
        'navChildActive'=>'text-white font-semibold','navChildNorm'=>'text-blue-200/70 hover:text-white',
        'navChildBorder'=>'border-blue-600/40',
        'quickbar'=>'bg-white border-gray-200','qbBrandHover'=>'hover:bg-gray-100',
        'qbBrandIcon'=>'bg-blue-600','qbBrandText'=>'text-gray-800',
        'qbLinkActive'=>'bg-blue-600 text-white','qbLinkNorm'=>'text-gray-600 hover:bg-gray-100',
        'searchInput'=>'bg-gray-50 border-gray-200 text-gray-700 placeholder-gray-400',
        'searchKbd'=>'text-gray-400 bg-gray-100 border-gray-300',
        'searchDropdown'=>'bg-white border-gray-200','searchHover'=>'gray-50',
        'searchBorder'=>'border-gray-100','searchTitle'=>'text-gray-800','searchSub'=>'text-gray-500',
        'themeBtn'=>'hover:bg-gray-100 text-gray-400',
        'notifBtn'=>'hover:bg-gray-100','notifIcon'=>'text-gray-500',
        'profileBtn'=>'hover:bg-gray-100',
        'dropdown'=>'bg-white border','ddLabel'=>'text-gray-400','ddLink'=>'text-gray-700 hover:bg-gray-50','ddDivider'=>'',
        'topHeader'=>'bg-white border-gray-200',
        'hamburgerBtn'=>'hover:bg-gray-100','hamburgerIcon'=>'text-gray-600','pageTitle'=>'text-gray-800',
        'searchIcon'=>'text-gray-400','profileChevron'=>'text-gray-400',
        'css'=>'',
    ];
} elseif ($isDark) {
    $tc = [
        'body'=>'bg-[#0d1117]','sidebar'=>'bg-[#161b22] border-r border-[#21262d]',
        'sidebarBrand'=>'text-gray-100','sidebarSub'=>'text-gray-500','sidebarBorder'=>'border-[#21262d]',
        'sidebarIcon'=>'bg-gray-700','sidebarIconTxt'=>'text-gray-300',
        'sidebarBottom'=>'border-[#21262d]','sidebarBottomLink'=>'text-gray-500 hover:text-gray-200 hover:bg-[#21262d]',
        'navActive'=>'bg-[#21262d] text-white','navNormal'=>'text-gray-400 hover:bg-[#1c2128] hover:text-gray-200',
        'navSection'=>'text-gray-600',
        'navGroupOpen'=>'bg-[#1c2128] text-gray-200','navGroupNorm'=>'text-gray-400 hover:bg-[#1c2128] hover:text-gray-200',
        'navChildActive'=>'text-white font-semibold','navChildNorm'=>'text-gray-500 hover:text-gray-200',
        'navChildBorder'=>'border-[#21262d]',
        'quickbar'=>'bg-[#010409] border-[#21262d]','qbBrandHover'=>'hover:bg-[#161b22]',
        'qbBrandIcon'=>'bg-gray-700','qbBrandText'=>'text-gray-200',
        'qbLinkActive'=>'bg-[#21262d] text-white','qbLinkNorm'=>'text-gray-400 hover:bg-[#161b22] hover:text-gray-200',
        'searchInput'=>'bg-[#0d1117] border-[#21262d] text-gray-200 placeholder-gray-600',
        'searchKbd'=>'text-gray-500 bg-[#161b22] border-[#21262d]',
        'searchDropdown'=>'bg-[#161b22] border-[#21262d]','searchHover'=>'[#21262d]',
        'searchBorder'=>'border-[#21262d]','searchTitle'=>'text-gray-200','searchSub'=>'text-gray-500',
        'themeBtn'=>'hover:bg-[#161b22] text-gray-400',
        'notifBtn'=>'hover:bg-[#161b22]','notifIcon'=>'text-gray-500',
        'profileBtn'=>'hover:bg-[#161b22]',
        'dropdown'=>'bg-[#161b22] border-[#21262d] border','ddLabel'=>'text-gray-500',
        'ddLink'=>'text-gray-300 hover:bg-[#21262d]','ddDivider'=>'border-[#21262d]',
        'topHeader'=>'bg-[#010409] border-[#21262d]',
        'hamburgerBtn'=>'hover:bg-[#161b22]','hamburgerIcon'=>'text-gray-400','pageTitle'=>'text-gray-100',
        'searchIcon'=>'text-gray-600','profileChevron'=>'text-gray-500',
        'css'=>'
body{background:#0d1117!important;color:#c9d1d9}
.bg-gray-50{background:#0d1117!important}.bg-white{background:#161b22!important}
.border-gray-100,.border-gray-200{border-color:#21262d!important}
.border{border-color:#21262d!important}
.text-gray-800{color:#e6edf3!important}.text-gray-700{color:#c9d1d9!important}
.text-gray-600{color:#8b949e!important}.text-gray-500{color:#8b949e!important}
.text-gray-400{color:#6e7681!important}
.shadow-sm{box-shadow:0 1px 2px rgba(0,0,0,0.4)!important}
.border-b{border-color:#21262d!important}
.hover\\:bg-gray-50:hover{background:#21262d!important}
.hover\\:bg-gray-100:hover{background:#21262d!important}
.bg-gray-100{background:#21262d!important}
.divide-y>:not([hidden])~:not([hidden]){border-color:#21262d}
.divide-gray-100>:not([hidden])~:not([hidden]){border-color:#21262d}
input,select,textarea{background:#0d1117!important;border-color:#21262d!important;color:#c9d1d9!important}
table thead{background:#161b22!important}
.rounded-xl{border-color:#21262d}
.bg-blue-50{background:#0d1117!important}
.ring-1{--tw-ring-color:#21262d!important}
',
    ];
} else {
    $tc = [
        'body'=>'bg-[#f6f8fa]','sidebar'=>'bg-white border-r border-gray-200',
        'sidebarBrand'=>'text-gray-900','sidebarSub'=>'text-gray-400','sidebarBorder'=>'border-gray-200',
        'sidebarIcon'=>'bg-gray-100','sidebarIconTxt'=>'text-gray-600',
        'sidebarBottom'=>'border-gray-200','sidebarBottomLink'=>'text-gray-400 hover:text-gray-700 hover:bg-gray-50',
        'navActive'=>'bg-gray-100 text-gray-900 font-semibold','navNormal'=>'text-gray-600 hover:bg-gray-50 hover:text-gray-900',
        'navSection'=>'text-gray-400',
        'navGroupOpen'=>'bg-gray-50 text-gray-900','navGroupNorm'=>'text-gray-600 hover:bg-gray-50 hover:text-gray-900',
        'navChildActive'=>'text-gray-900 font-semibold','navChildNorm'=>'text-gray-500 hover:text-gray-900',
        'navChildBorder'=>'border-gray-200',
        'quickbar'=>'bg-white border-gray-200','qbBrandHover'=>'hover:bg-gray-100',
        'qbBrandIcon'=>'bg-gray-900','qbBrandText'=>'text-gray-800',
        'qbLinkActive'=>'bg-gray-900 text-white','qbLinkNorm'=>'text-gray-600 hover:bg-gray-100',
        'searchInput'=>'bg-gray-50 border-gray-200 text-gray-700 placeholder-gray-400',
        'searchKbd'=>'text-gray-400 bg-gray-100 border-gray-300',
        'searchDropdown'=>'bg-white border-gray-200','searchHover'=>'gray-50',
        'searchBorder'=>'border-gray-100','searchTitle'=>'text-gray-800','searchSub'=>'text-gray-500',
        'themeBtn'=>'hover:bg-gray-100 text-gray-400',
        'notifBtn'=>'hover:bg-gray-100','notifIcon'=>'text-gray-500',
        'profileBtn'=>'hover:bg-gray-100',
        'dropdown'=>'bg-white border','ddLabel'=>'text-gray-400','ddLink'=>'text-gray-700 hover:bg-gray-50','ddDivider'=>'',
        'topHeader'=>'bg-white border-gray-200',
        'hamburgerBtn'=>'hover:bg-gray-100','hamburgerIcon'=>'text-gray-600','pageTitle'=>'text-gray-800',
        'searchIcon'=>'text-gray-400','profileChevron'=>'text-gray-400',
        'css'=>'',
    ];
}

// ── View-as-Role System (super admin only) ──
$viewAsRole = null; $viewAsRoleName = ''; $viewAsPermsCount = 0;
$realPermissions = $_SESSION['admin_permissions'] ?? [];
if (isSuperAdmin() && !empty($_SESSION['view_as_role_id'])) {
    $vrole = $db->fetch("SELECT * FROM admin_roles WHERE id = ?", [$_SESSION['view_as_role_id']]);
    if ($vrole) {
        $viewAsRole = $vrole; $viewAsRoleName = $vrole['role_name'];
        $_SESSION['_real_permissions'] = $realPermissions;
        $rolePerms = json_decode($vrole['permissions'], true);
        if (!is_array($rolePerms)) $rolePerms = [];
        $_SESSION['admin_permissions'] = $rolePerms;
        $viewAsPermsCount = count($rolePerms);
    }
}
$allRoles = [];
if (isSuperAdmin() || $viewAsRole) {
    try { $allRoles = $db->fetchAll("SELECT * FROM admin_roles ORDER BY id"); } catch (\Throwable $e) {}
}

function navLink($page, $label, $icon, $badge = 0) {
    global $currentPage, $tc;
    if (!canViewPage($page)) return '';
    $active = ($currentPage === $page) ? $tc['navActive'] : $tc['navNormal'];
    $href = adminUrl("pages/{$page}.php");
    $html = '<a href="'.$href.'" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition '.$active.'">';
    $html .= $icon.'<span>'.$label.'</span>';
    if ($badge > 0) $html .= '<span class="ml-auto bg-red-500 text-white text-xs px-2 py-0.5 rounded-full">'.$badge.'</span>';
    $html .= '</a>';
    return $html;
}

function navSection($title) {
    global $tc;
    return '<div class="nav-section-header px-4 pt-5 pb-2"><p class="text-xs font-semibold '.$tc['navSection'].' uppercase tracking-wider">'.$title.'</p></div>';
}

function navGroup($id, $label, $icon, $children, $badge = 0) {
    global $currentPage, $tc;
    $visibleChildren = array_filter($children, function($child) { return canViewPage($child['page']); });
    if (empty($visibleChildren)) return '';
    $isOpen = false;
    foreach ($visibleChildren as $child) { if ($currentPage === $child['page']) { $isOpen = true; break; } }
    $openClass = $isOpen ? '' : 'hidden';
    $arrowClass = $isOpen ? 'rotate-90' : '';
    $btnClass = $isOpen ? $tc['navGroupOpen'] : $tc['navGroupNorm'];
    $html = '<div class="nav-group">';
    $html .= '<button onclick="toggleNav(\''.$id.'\')" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition '.$btnClass.'">';
    $html .= $icon.'<span>'.$label.'</span>';
    if ($badge > 0) $html .= '<span class="ml-auto bg-red-500 text-white text-xs px-2 py-0.5 rounded-full mr-1">'.$badge.'</span>';
    $html .= '<svg class="w-4 h-4 ml-auto transition-transform duration-200 nav-arrow-'.$id.' '.$arrowClass.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>';
    $html .= '</button>';
    $html .= '<div id="nav-'.$id.'" class="ml-6 mt-1 space-y-0.5 border-l '.$tc['navChildBorder'].' pl-3 '.$openClass.'">';
    foreach ($visibleChildren as $child) {
        $active = ($currentPage === $child['page']) ? $tc['navChildActive'] : $tc['navChildNorm'];
        $href = adminUrl("pages/{$child['page']}.php");
        $html .= '<a href="'.$href.'" class="block px-3 py-1.5 text-sm rounded-lg transition '.$active.'">'.$child['label'];
        if (!empty($child['badge'])) $html .= '<span class="ml-2 bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full">'.$child['badge'].'</span>';
        $html .= '</a>';
    }
    $html .= '</div></div>';
    return $html;
}
function subItem($page, $label, $badge = 0) { return ['page'=>$page,'label'=>$label,'badge'=>$badge]; }

$icons = [
    'dashboard'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>',
    'orders'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>',
    'products'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>',
    'categories'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>',
    'customers'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    'inventory'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
    'courier'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>',
    'reports'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>',
    'settings'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    'accounting'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'tasks'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>',
    'expenses'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
    'returns'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>',
    'pages'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>',
    'banners'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
    'coupons'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>',
    'employees'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
    'media'=>'<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $adminTheme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Dashboard' ?> - Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body{font-family:'Inter',sans-serif}
        .sidebar-scroll::-webkit-scrollbar{width:4px}
        .sidebar-scroll::-webkit-scrollbar-thumb{background:rgba(128,128,128,0.3);border-radius:4px}
        #themeDropdown{display:none}
        #themeDropdown.show{display:block}
        <?= $tc['css'] ?>
    </style>
</head>
<body class="<?= $tc['body'] ?>">

<!-- ═══ TOP QUICK ACCESS BAR ═══ -->
<div id="quickBar" class="fixed top-0 left-0 right-0 z-[55] <?= $tc['quickbar'] ?> border-b shadow-sm" style="height:44px">
    <div class="flex items-center h-[44px] px-2 lg:px-4 gap-1">
        <a href="<?= adminUrl('pages/dashboard.php') ?>" class="flex items-center gap-1.5 px-2 py-1 rounded-lg <?= $tc['qbBrandHover'] ?> mr-1 shrink-0">
            <div class="w-6 h-6 <?= $tc['qbBrandIcon'] ?> rounded-md flex items-center justify-center"><span class="text-white text-xs font-bold"><?= strtoupper(substr($siteName,0,1)) ?></span></div>
            <span class="hidden md:inline text-sm font-bold <?= $tc['qbBrandText'] ?>"><?= e($siteName) ?></span>
        </a>
        <div class="flex items-center gap-0.5 shrink-0">
            <a href="<?= adminUrl('pages/search.php') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-semibold <?= $currentPage==='search' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition">Search</a>
            <?php if (canViewPage('order-add')): ?><a href="<?= adminUrl('pages/order-add.php') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-semibold <?= $currentPage==='order-add' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition hidden sm:inline-block">NewOrder</a><?php endif; ?>
            <?php if (canViewPage('order-management')): ?><a href="<?= adminUrl('pages/order-management.php') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-semibold <?= $currentPage==='order-management' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition hidden sm:inline-block">Orders</a><?php endif; ?>
            <?php if (canViewPage('order-management')): ?><a href="<?= adminUrl('pages/order-management.php?status=processing') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-semibold <?= $tc['qbLinkNorm'] ?> transition hidden md:inline-block">Processing</a><?php endif; ?>
            <?php if (canViewPage('incomplete-orders')): ?><a href="<?= adminUrl('pages/incomplete-orders.php') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-semibold <?= $currentPage==='incomplete-orders' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition hidden md:inline-block">Incomplete</a><?php endif; ?>
            <?php if (canViewPage('scan-to-update')): ?><a href="<?= adminUrl('pages/scan-to-update.php') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-semibold <?= $currentPage==='scan-to-update' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition hidden lg:inline-flex items-center gap-1" title="Scan To Update"><i class="fas fa-barcode text-[10px]"></i>Scan</a><?php endif; ?>
        </div>
        <!-- Global Search -->
        <div class="flex-1 max-w-md mx-2 relative" id="globalSearchWrap">
            <div class="relative">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 <?= $tc['searchIcon'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" id="globalSearchInput" placeholder="Search orders, customers, phone..." class="w-full pl-8 pr-8 py-1.5 text-xs <?= $tc['searchInput'] ?> border rounded-lg focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none transition" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();goSearch()}" oninput="liveSearch(this.value)">
                <kbd class="absolute right-2 top-1/2 -translate-y-1/2 text-[9px] <?= $tc['searchKbd'] ?> border px-1 py-0.5 rounded font-mono hidden sm:inline">/</kbd>
            </div>
            <div id="globalSearchResults" class="hidden absolute top-full left-0 right-0 mt-1 <?= $tc['searchDropdown'] ?> border rounded-xl shadow-2xl max-h-[400px] overflow-y-auto z-[100]"></div>
        </div>
        <!-- Right side -->
        <div class="flex items-center gap-1 shrink-0">
            <!-- Theme Switcher -->
            <div class="relative" id="themeWrap">
                <button onclick="document.getElementById('themeDropdown').classList.toggle('show')" class="p-1.5 rounded-lg <?= $tc['themeBtn'] ?> transition" title="Switch theme">
                    <?php if ($isDark): ?>
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/></svg>
                    <?php elseif ($isLight): ?>
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"/></svg>
                    <?php else: ?>
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm1 3a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1H6a1 1 0 01-1-1V5zm6 0a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1V5zm-6 6a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1H6a1 1 0 01-1-1v-2zm6 0a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1v-2z"/></svg>
                    <?php endif; ?>
                </button>
                <div id="themeDropdown" class="absolute right-0 mt-2 w-40 <?= $tc['dropdown'] ?> rounded-lg shadow-xl z-[100] py-1">
                    <p class="px-3 py-1.5 text-[10px] font-semibold <?= $tc['ddLabel'] ?> uppercase tracking-wider">Theme</p>
                    <button onclick="switchTheme('light')" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm <?= $tc['ddLink'] ?> transition">
                        <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"/></svg>
                        <span>Light</span>
                        <?php if ($isLight): ?><svg class="w-3.5 h-3.5 ml-auto text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><?php endif; ?>
                    </button>
                    <button onclick="switchTheme('dark')" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm <?= $tc['ddLink'] ?> transition">
                        <svg class="w-4 h-4 text-indigo-400" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/></svg>
                        <span>Dark</span>
                        <?php if ($isDark): ?><svg class="w-3.5 h-3.5 ml-auto text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><?php endif; ?>
                    </button>
                    <button onclick="switchTheme('ui')" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm <?= $tc['ddLink'] ?> transition">
                        <svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm1 3a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1H6a1 1 0 01-1-1V5zm6 0a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1V5zm-6 6a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1H6a1 1 0 01-1-1v-2zm6 0a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1v-2z"/></svg>
                        <span>UI Color</span>
                        <?php if ($isUI): ?><svg class="w-3.5 h-3.5 ml-auto text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><?php endif; ?>
                    </button>
                </div>
            </div>
            <a href="<?= adminUrl('pages/notifications.php') ?>" class="relative p-1.5 rounded-lg <?= $tc['notifBtn'] ?> transition">
                <svg class="w-4 h-4 <?= $tc['notifIcon'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <?php if ($stats['unread_notifications'] > 0): ?><span class="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[9px] w-4 h-4 rounded-full flex items-center justify-center"><?= $stats['unread_notifications'] > 9 ? '9+' : $stats['unread_notifications'] ?></span><?php endif; ?>
            </a>
            <div class="relative">
                <button onclick="this.nextElementSibling.classList.toggle('hidden')" class="flex items-center gap-1.5 px-2 py-1 rounded-lg <?= $tc['profileBtn'] ?> transition">
                    <div class="w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center text-white text-[10px] font-bold"><?= strtoupper(substr(getAdminName(),0,1)) ?></div>
                    <svg class="w-3 h-3 <?= $tc['profileChevron'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div class="hidden absolute right-0 mt-2 w-48 <?= $tc['dropdown'] ?> rounded-lg shadow-lg py-1 z-[100]">
                    <p class="px-4 py-1.5 text-[10px] font-semibold <?= $tc['ddLabel'] ?> uppercase"><?= e(getAdminName()) ?></p>
                    <a href="<?= adminUrl('pages/profile.php') ?>" class="block px-4 py-2 text-sm <?= $tc['ddLink'] ?>"><i class="fas fa-user-circle mr-2 text-gray-400"></i>Profile</a>
                    <a href="<?= adminUrl('pages/settings.php') ?>" class="block px-4 py-2 text-sm <?= $tc['ddLink'] ?>"><i class="fas fa-cog mr-2 text-gray-400"></i>Settings</a>
                    <?php if (isSuperAdmin() || $viewAsRole): ?>
                    <hr class="my-1 <?= $tc['ddDivider'] ?>">
                    <p class="px-4 py-1 text-[10px] font-semibold <?= $tc['ddLabel'] ?>">VIEW AS ROLE</p>
                    <?php foreach ($allRoles as $r): ?>
                    <a href="<?= adminUrl('pages/profile.php?action=view_as&role_id='.$r['id']) ?>" class="block px-4 py-1.5 text-xs <?= $tc['ddLink'] ?> <?= ($viewAsRole && $viewAsRole['id']==$r['id']) ? 'font-bold text-blue-500' : '' ?>"><?= e($r['role_name']) ?><?php if ($viewAsRole && $viewAsRole['id']==$r['id']): ?> ✓<?php endif; ?></a>
                    <?php endforeach; ?>
                    <?php if ($viewAsRole): ?>
                    <a href="<?= adminUrl('pages/profile.php?action=exit_view_as') ?>" class="block px-4 py-1.5 text-xs text-amber-600 font-semibold hover:bg-amber-50">✕ Exit Preview</a>
                    <?php endif; ?>
                    <?php endif; ?>
                    <hr class="my-1 <?= $tc['ddDivider'] ?>">
                    <a href="<?= adminUrl('index.php?action=logout') ?>" class="block px-4 py-2 text-sm text-red-500 <?= $isDark ? 'hover:bg-red-900/30' : 'hover:bg-red-50' ?>"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Global Search JS -->
<script>
let _gsTimer=null,_gsAbort=null;
function goSearch(){const v=document.getElementById('globalSearchInput').value.trim();if(v)window.location.href='<?= adminUrl("pages/search.php") ?>?q='+encodeURIComponent(v);}
function liveSearch(q){
    clearTimeout(_gsTimer);
    const box=document.getElementById('globalSearchResults');
    if(q.trim().length<2){box.classList.add('hidden');return;}
    _gsTimer=setTimeout(()=>{
        if(_gsAbort)_gsAbort.abort();_gsAbort=new AbortController();
        fetch('<?= adminUrl("api/search.php") ?>?q='+encodeURIComponent(q.trim())+'&limit=8',{signal:_gsAbort.signal})
        .then(r=>r.json()).then(d=>{
            if(!d.results||!d.results.length){box.innerHTML='<p class="p-4 text-sm text-gray-400 text-center">No results</p>';box.classList.remove('hidden');return;}
            let h='';
            d.results.forEach(r=>{
                const badge=r.status_badge||'bg-gray-100 text-gray-600';
                h+=`<a href="${r.url}" class="flex items-center gap-3 px-4 py-2.5 hover:bg-<?= $tc['searchHover'] ?> border-b <?= $tc['searchBorder'] ?> transition">
                    <div class="shrink-0 w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center text-blue-600 text-xs font-bold">#</div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold <?= $tc['searchTitle'] ?> truncate">${r.order_number} — ${r.customer_name||'Unknown'}</p>
                        <p class="text-[11px] <?= $tc['searchSub'] ?>">${r.customer_phone||''} · ৳${r.total} · ${r.date||''}</p>
                    </div>
                    <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold ${badge}">${(r.status||'').toUpperCase()}</span>
                </a>`;
            });
            if(d.total>8) h+=`<a href="<?= adminUrl("pages/search.php") ?>?q=${encodeURIComponent(q.trim())}" class="block text-center py-2.5 text-xs text-blue-600 font-semibold hover:bg-blue-50">View all ${d.total} results →</a>`;
            box.innerHTML=h;box.classList.remove('hidden');
        }).catch(()=>{});
    },300);
}
document.addEventListener('keydown',e=>{if(e.key==='/'&&!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)){e.preventDefault();document.getElementById('globalSearchInput').focus();}});
document.addEventListener('click',e=>{
    if(!document.getElementById('globalSearchWrap')?.contains(e.target))document.getElementById('globalSearchResults')?.classList.add('hidden');
    if(!document.getElementById('themeWrap')?.contains(e.target))document.getElementById('themeDropdown')?.classList.remove('show');
});

// ═══════════════════════════════════════════════════════════════════
// GLOBAL CONFIRM OVERRIDE — replaces all native window.confirm()
// calls site-wide with a minimal styled modal. Zero changes needed
// to any individual page. Supports async via Promise internally.
// ═══════════════════════════════════════════════════════════════════
(function () {
    // Build modal DOM once
    const _overlay = document.createElement('div');
    _overlay.id = '__confirmModal';
    _overlay.style.cssText = 'display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;';
    _overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.2);width:100%;max-width:360px;margin:0 16px;overflow:hidden;transform:scale(0.95);transition:transform 0.15s ease;">
            <div style="padding:24px 24px 16px;">
                <div style="display:flex;align-items:flex-start;gap:12px;">
                    <div id="__cIcon" style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px;"></div>
                    <div>
                        <div id="__cTitle" style="font-weight:700;font-size:14px;color:#111827;margin-bottom:4px;"></div>
                        <div id="__cMsg" style="font-size:13px;color:#6b7280;line-height:1.5;"></div>
                    </div>
                </div>
            </div>
            <div style="display:flex;border-top:1px solid #f3f4f6;">
                <button id="__cCancel" style="flex:1;padding:13px;font-size:13px;font-weight:500;color:#6b7280;background:transparent;border:none;cursor:pointer;border-right:1px solid #f3f4f6;transition:background 0.15s;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='transparent'">Cancel</button>
                <button id="__cOk"     style="flex:1;padding:13px;font-size:13px;font-weight:600;background:transparent;border:none;cursor:pointer;transition:background 0.15s;" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='transparent'"></button>
            </div>
        </div>`;
    document.addEventListener('DOMContentLoaded', () => document.body.appendChild(_overlay));

    let _resolve = null;

    function _show(msg, type) {
        return new Promise(resolve => {
            _resolve = resolve;
            const inner  = _overlay.querySelector('div');
            const icon   = _overlay.querySelector('#__cIcon');
            const title  = _overlay.querySelector('#__cTitle');
            const msgEl  = _overlay.querySelector('#__cMsg');
            const okBtn  = _overlay.querySelector('#__cOk');
            const canBtn = _overlay.querySelector('#__cCancel');

            // Parse optional "Title\nMessage" format
            const parts = msg.split('\n');
            const hasTitle = parts.length > 1 && parts[0].length < 60;
            const titleText = hasTitle ? parts[0] : (type === 'danger' ? 'Are you sure?' : 'Confirm');
            const bodyText  = hasTitle ? parts.slice(1).join(' ') : msg;

            // Style by type
            const styles = {
                danger:  { bg:'#fef2f2', color:'#dc2626', icon:'⚠', okColor:'#dc2626', okHover:'#fef2f2', okLabel:'Delete' },
                warning: { bg:'#fffbeb', color:'#d97706', icon:'!',  okColor:'#d97706', okHover:'#fffbeb', okLabel:'Confirm' },
                info:    { bg:'#eff6ff', color:'#2563eb', icon:'i',  okColor:'#2563eb', okHover:'#eff6ff', okLabel:'OK' },
            };
            const s = styles[type] || styles.info;

            icon.style.cssText  += `;background:${s.bg};color:${s.color};font-weight:900;font-style:italic;`;
            icon.textContent     = s.icon;
            title.textContent    = titleText;
            msgEl.textContent    = bodyText;
            okBtn.textContent    = type === 'danger' ? 'Delete' : 'Confirm';
            okBtn.style.color    = s.okColor;
            okBtn.onmouseover    = function(){ this.style.background = s.okHover; };
            okBtn.onmouseout     = function(){ this.style.background = 'transparent'; };

            // Show
            _overlay.style.display = 'flex';
            setTimeout(() => inner.style.transform = 'scale(1)', 10);
        });
    }

    function _close(result) {
        const inner = _overlay.querySelector('div');
        inner.style.transform = 'scale(0.95)';
        setTimeout(() => { _overlay.style.display = 'none'; }, 140);
        if (_resolve) { _resolve(result); _resolve = null; }
    }

    // Wire buttons (after DOM ready)
    document.addEventListener('DOMContentLoaded', () => {
        _overlay.querySelector('#__cOk').addEventListener('click',     () => _close(true));
        _overlay.querySelector('#__cCancel').addEventListener('click',  () => _close(false));
        _overlay.addEventListener('click', e => { if (e.target === _overlay) _close(false); });
        document.addEventListener('keydown', e => {
            if (_overlay.style.display !== 'none') {
                if (e.key === 'Enter')  { e.preventDefault(); _close(true); }
                if (e.key === 'Escape') { e.preventDefault(); _close(false); }
            }
        });
    });

    // Detect danger keywords to auto-style
    function _detectType(msg) {
        const m = msg.toLowerCase();
        if (/delete|remove|cancel|মুছ|পরিষ্কার|clear all/.test(m)) return 'danger';
        if (/block|warn|স্থায়ী/.test(m)) return 'warning';
        return 'info';
    }

    // OVERRIDE window.confirm — synchronous calls (form onsubmit etc.)
    // We intercept the synchronous call by immediately showing the modal
    // and returning false to block the default action, then re-triggering
    // the action after confirmation.
    window._confirmAsync = function(msg) {
        return _show(msg, _detectType(msg));
    };

    // For synchronous usage (onsubmit="return confirm(...)"),
    // we override confirm() to show the modal and submit the form after OK.
    window.confirm = function (msg) {
        // Find the currently active form submit or button click event
        // by hooking into the event loop
        const type = _detectType(msg);
        _show(msg, type).then(result => {
            if (result && window.__pendingConfirmAction) {
                window.__pendingConfirmAction();
            }
            window.__pendingConfirmAction = null;
        });
        return false; // always block immediate action
    };

    // Intercept all form onsubmit="return confirm(...)" patterns
    document.addEventListener('submit', function(e) {
        const form = e.target;
        const handler = form.getAttribute('onsubmit');
        if (handler && handler.includes('confirm(')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            // Extract message from onsubmit attr
            const match = handler.match(/confirm\(['"]([^'"]+)['"]/);
            const msg   = match ? match[1] : 'Are you sure?';
            _show(msg, _detectType(msg)).then(ok => {
                if (ok) { form.removeAttribute('onsubmit'); form.submit(); }
            });
        }
    }, true); // capture phase — runs before the form's own handler

    // Intercept inline onclick="return confirm(...)" on non-form elements
    document.addEventListener('click', function(e) {
        const el = e.target.closest('[onclick]');
        if (!el) return;
        const handler = el.getAttribute('onclick');
        if (!handler || !handler.includes('confirm(')) return;
        // Only intercept if it starts with "return confirm" pattern
        if (!handler.match(/return confirm\(/)) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        const match = handler.match(/confirm\(['"]([^'"]+)['"]/);
        const msg   = match ? match[1] : 'Are you sure?';
        _show(msg, _detectType(msg)).then(ok => {
            if (ok) {
                // Re-run the onclick without the confirm gate
                const newHandler = handler.replace(/return confirm\([^)]+\);?\s*/g, '');
                if (newHandler.trim()) {
                    try { new Function(newHandler).call(el); } catch(err) {}
                }
                // If it's a link, follow it
                if (el.tagName === 'A' && el.href && el.href !== '#') {
                    window.location.href = el.href;
                }
                // If it wraps a form submit button, submit the form
                const parentForm = el.closest('form');
                if (parentForm && (el.tagName === 'BUTTON' || el.type === 'submit')) {
                    parentForm.submit();
                }
            }
        });
    }, true);
}());


// ═══════════════════════════════════════════════════════════════════
// GLOBAL PROMPT REPLACEMENT — window._promptAsync(msg, placeholder, title)
// Use instead of window.prompt() for a styled input modal.
// ═══════════════════════════════════════════════════════════════════
(function () {
    const _pOverlay = document.createElement('div');
    _pOverlay.id = '__promptModal';
    _pOverlay.style.cssText = 'display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;';
    _pOverlay.innerHTML = `
        <div id="__pInner" style="background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.2);width:100%;max-width:380px;margin:0 16px;overflow:hidden;transform:scale(0.95);transition:transform 0.15s ease;">
            <div style="padding:20px 20px 16px;">
                <div id="__pTitle" style="font-weight:700;font-size:14px;color:#111827;margin-bottom:4px;"></div>
                <div id="__pMsg"   style="font-size:12px;color:#6b7280;margin-bottom:12px;"></div>
                <input id="__pInput" type="text" autocomplete="off"
                    style="width:100%;padding:9px 12px;font-size:13px;border:1.5px solid #e5e7eb;border-radius:8px;outline:none;box-sizing:border-box;transition:border-color 0.15s;"
                    onfocus="this.style.borderColor='#6366f1'"
                    onblur="this.style.borderColor='#e5e7eb'">
            </div>
            <div style="display:flex;border-top:1px solid #f3f4f6;">
                <button id="__pCancel" style="flex:1;padding:12px;font-size:13px;font-weight:500;color:#6b7280;background:transparent;border:none;cursor:pointer;border-right:1px solid #f3f4f6;transition:background 0.15s;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='transparent'">Cancel</button>
                <button id="__pOk"     style="flex:1;padding:12px;font-size:13px;font-weight:600;color:#6366f1;background:transparent;border:none;cursor:pointer;transition:background 0.15s;" onmouseover="this.style.background='#eef2ff'" onmouseout="this.style.background='transparent'">OK</button>
            </div>
        </div>`;

    let _pResolve = null;

    function _pClose(value) {
        const inner = document.getElementById('__pInner');
        if (inner) inner.style.transform = 'scale(0.95)';
        setTimeout(() => { _pOverlay.style.display = 'none'; }, 140);
        if (_pResolve) { _pResolve(value); _pResolve = null; }
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.body.appendChild(_pOverlay);

        document.getElementById('__pOk').addEventListener('click', () => {
            _pClose(document.getElementById('__pInput').value);
        });
        document.getElementById('__pCancel').addEventListener('click', () => _pClose(null));
        _pOverlay.addEventListener('click', e => { if (e.target === _pOverlay) _pClose(null); });
        document.getElementById('__pInput').addEventListener('keydown', e => {
            if (e.key === 'Enter')  { e.preventDefault(); _pClose(document.getElementById('__pInput').value); }
            if (e.key === 'Escape') { e.preventDefault(); _pClose(null); }
        });
    });

    window._promptAsync = function(msg, defaultVal, title) {
        return new Promise(resolve => {
            _pResolve = resolve;
            document.getElementById('__pTitle').textContent = title || 'Enter Value';
            document.getElementById('__pMsg').textContent   = msg   || '';
            const input = document.getElementById('__pInput');
            input.value       = defaultVal || '';
            input.placeholder = defaultVal || '';
            _pOverlay.style.display = 'flex';
            const inner = document.getElementById('__pInner');
            if (inner) setTimeout(() => { inner.style.transform = 'scale(1)'; }, 10);
            setTimeout(() => { input.focus(); input.select(); }, 50);
        });
    };

    // Also override native window.prompt for any code still using it
    window.prompt = function(msg, defaultVal) {
        // Return a special thenable so async callers work,
        // but also schedule the modal for non-async callers
        console.warn('[Prompt] Use window._promptAsync() for styled prompt. Falling back to modal.');
        return null; // Block native, async callers should use _promptAsync
    };
}());

</script>

<?php if ($viewAsRole): ?>
<div class="fixed top-0 left-0 right-0 z-[60] bg-amber-500 text-amber-950 text-center py-1.5 text-sm font-semibold shadow-lg">
    <i class="fas fa-eye mr-1"></i>Viewing as: <strong><?= e($viewAsRoleName) ?></strong> role (<?= $viewAsPermsCount ?> permissions)
    <a href="<?= adminUrl('pages/profile.php?action=exit_view_as') ?>" class="ml-3 px-2 py-0.5 bg-amber-700 text-white rounded text-xs hover:bg-amber-800">Exit Preview</a>
</div>
<style>#quickBar{top:36px !important}[style*="padding-top:44px"]{padding-top:80px !important}[style*="top:44px"]{top:80px !important}</style>
<?php endif; ?>

<div class="flex min-h-screen" style="padding-top:44px">
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 <?= $tc['sidebar'] ?> transform -translate-x-full lg:translate-x-0 transition-transform duration-200 ease-in-out" style="top:44px">
        <div class="flex items-center gap-3 px-5 py-4 border-b <?= $tc['sidebarBorder'] ?>">
            <div class="w-9 h-9 <?= $tc['sidebarIcon'] ?> rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 <?= $tc['sidebarIconTxt'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
                <h1 class="<?= $tc['sidebarBrand'] ?> font-bold text-sm"><?= e($siteName) ?></h1>
                <p class="<?= $tc['sidebarSub'] ?> text-xs">Admin Panel</p>
            </div>
        </div>
        <nav class="sidebar-scroll overflow-y-auto h-[calc(100vh-174px)] py-3 px-3 space-y-0.5">
            <?= navLink('dashboard', 'Dashboard', $icons['dashboard']) ?>
            <?php 
            $salesItems = navGroup('order-management', 'Order Management', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>', [
                subItem('order-management', 'All Orders'),
                subItem('order-add', 'New Order'),
                subItem('incomplete-orders', 'Incomplete Orders', $stats['incomplete_orders']),
                subItem('returns', 'Returns'),
                subItem('scan-to-update', 'Scan To Update'),
            ], $stats['pending_orders'] + $stats['approved_orders']);
            $salesItems .= navLink('customers', 'Customers', $icons['customers']);
            $salesItems .= navLink('coupons', 'Coupons', $icons['coupons']);
            $salesItems .= navLink('visitors', 'Visitors', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>');
            if (trim($salesItems)): ?>
            <?= navSection('Sales') ?>
            <?= $salesItems ?>
            <?php endif;
            $catalogItems = navGroup('inventory', 'Inventory', $icons['inventory'], [
                subItem('product-form', 'Add New Product'),
                subItem('products', 'Product List'),
                subItem('categories', 'Categories & Brands'),
                subItem('stock-decrease-new', 'New Decrease Stock'),
                subItem('stock-decrease-list', 'Decrease Stock List'),
                subItem('stock-increase-new', 'New Increase Stock'),
                subItem('stock-increase-list', 'Increase Stock List'),
                subItem('stock-transfer', 'Transfer Stock'),
                subItem('smart-restock', 'Smart Restock'),
                subItem('inventory-dashboard', 'Inventory Dashboard'),
            ], $stats['low_stock']);
            $catalogItems .= navLink('media', 'Media Gallery', $icons['media']);
            if (trim($catalogItems)): ?>
            <?= navSection('Catalog') ?>
            <?= $catalogItems ?>
            <?php endif;
            $shippingItems = navLink('courier', 'Delivery Methods', $icons['courier']);
            if (trim($shippingItems)): ?>
            <?= navSection('Shipping') ?>
            <?= $shippingItems ?>
            <?php endif;
            $metaAdsItems = navGroup('meta-ads', 'Meta Ads', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>', [
                subItem('meta-ads-report', 'Meta Ads Report'),
                subItem('meta-ads-campaigns', 'Campaign Products'),
            ]);
            if (trim($metaAdsItems)): ?>
            <?= navSection('Marketing') ?>
            <?= $metaAdsItems ?>
            <?php endif;
            $financeItems = navGroup('finance', 'Accounting', $icons['accounting'], [
                subItem('accounting', 'Accounts'),
                subItem('expense-new', 'New Expense'),
                subItem('ad-expense-new', 'New Ad Expense'),
                subItem('expenses', 'Expenses List'),
                subItem('income', 'Income'),
                subItem('liabilities', 'Liabilities'),
            ]);
            $financeItems .= navGroup('reports-nav', 'Reports', $icons['reports'], [
                subItem('report-profit-sales', 'Profit & Sales'),
                subItem('report-employees', 'Employee Report'),
                subItem('report-orders', 'Order Report'),
                subItem('report-products', 'Product Report'),
                subItem('report-web-orders', 'Web Order Report'),
                subItem('report-meta-ads', 'Meta Ads Report'),
                subItem('report-business', 'Business Reports'),
            ]);
            if (trim($financeItems)): ?>
            <?= navSection('Finance') ?>
            <?= $financeItems ?>
            <?php endif;
            $contentItems = navLink('page-builder', 'Page Builder', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>');
            $contentItems .= navLink('shop-design', 'Shop Design', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>');
            $contentItems .= navLink('checkout-fields', 'Checkout Fields', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>');
            $contentItems .= navLink('progress-bars', 'Progress Bar Offers', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/></svg>');
            $contentItems .= navLink('landing-pages', 'Landing Pages', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/></svg>');
            $contentItems .= navLink('banners', 'Banners', $icons['banners']);
            $contentItems .= navLink('cms-pages', 'Pages', $icons['pages']);
            $contentItems .= navLink('blog', 'Blog Posts', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>');
            if (trim($contentItems)): ?>
            <?= navSection('Content') ?>
            <?= $contentItems ?>
            <?php endif;
            $supportItems = navGroup('live-chat-nav', 'Live Chat', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>', [
                subItem('live-chat', 'Chat Console'),
                subItem('chat-settings', 'Settings & Training'),
            ], $stats['chat_waiting'] ?? 0);
            $supportItems .= navGroup('call-center-nav', 'Call Center', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>', [
                subItem('call-center', 'Call Center'),
            ]);
            if (trim($supportItems)): ?>
            <?= navSection('Support') ?>
            <?= $supportItems ?>
            <?php endif;
            $teamItems = navGroup('team', 'HRM', $icons['employees'], [
                subItem('employees', 'Employees'),
                subItem('tasks', 'Tasks & Follow-up'),
            ], $stats['pending_tasks']);
            if (trim($teamItems)): ?>
            <?= navSection('Team') ?>
            <?= $teamItems ?>
            <?php endif;
            $systemItems = navGroup('settings', 'Settings', $icons['settings'], [
                subItem('settings', 'General Settings'),
                subItem('select-invoice', 'Select Invoice'),
                subItem('select-sticker', 'Select Sticker'),
            ]);
            $systemItems .= navLink('speed', 'Speed & Cache', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>');
            $systemItems .= navLink('api-health', 'API Health', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>');
            $systemItems .= navLink('security', 'Security Center', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>');
            if (trim($systemItems)): ?>
            <?= navSection('System') ?>
            <?= $systemItems ?>
            <?php endif; ?>
        </nav>
        <div class="absolute bottom-0 left-0 right-0 p-3 border-t <?= $tc['sidebarBottom'] ?>">
            <a href="<?= url() ?>" target="_blank" class="flex items-center gap-2 <?= $tc['sidebarBottomLink'] ?> text-xs px-3 py-2 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                View Store
            </a>
            <?php if (isSuperAdmin()): ?><p class="text-[9px] text-center opacity-30 mt-1" title="Permission system version">P<?= defined('PERM_SYSTEM_VERSION') ? PERM_SYSTEM_VERSION : '?' ?></p><?php endif; ?>
        </div>
    </aside>
    
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 lg:hidden hidden" onclick="toggleSidebar()"></div>

    <div class="flex-1 lg:ml-64">
        <header class="sticky z-30 <?= $tc['topHeader'] ?> border-b px-4 lg:px-6 py-2.5" style="top:44px">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg <?= $tc['hamburgerBtn'] ?>">
                        <svg class="w-5 h-5 <?= $tc['hamburgerIcon'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <h2 class="text-lg font-semibold <?= $tc['pageTitle'] ?>"><?= $pageTitle ?? 'Dashboard' ?></h2>
                </div>
            </div>
        </header>
        <main class="p-4 lg:p-6">
<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
<?php unset($_SESSION['flash_error']); endif; ?>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('-translate-x-full');document.getElementById('sidebarOverlay').classList.toggle('hidden');}
function toggleNav(id){const el=document.getElementById('nav-'+id);const arrow=document.querySelector('.nav-arrow-'+id);el.classList.toggle('hidden');arrow?.classList.toggle('rotate-90');}
function switchTheme(theme){
    fetch('<?= SITE_URL ?>/api/admin-theme.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'theme='+theme})
    .then(()=>location.reload()).catch(()=>location.reload());
}
</script>

<?php
if ($viewAsRole && !empty($_SESSION['_real_permissions'])) {
    $_SESSION['admin_permissions'] = $_SESSION['_real_permissions'];
    unset($_SESSION['_real_permissions']);
}
?>
