<?php
/**
 * Admin Header & Sidebar Layout — shadcn/ui New York Variant
 * Themes: Light / Dark / UI (toggleable from header)
 * Design System: shadcn CSS variables + Tailwind utility classes
 */
requireAdmin();
refreshAdminPermissions();
$stats = getDashboardStats();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$siteName    = getSetting('site_name', 'E-Commerce');
$siteLogo    = getSetting('site_logo', '');
$siteFavicon = getSetting('site_favicon', '');
$db = Database::getInstance();

// ── Theme System (light / dark / ui) ──
$adminTheme = 'ui';
$adminAvatar = '';
try { $db->query("ALTER TABLE admin_users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
try {
    $t = $db->fetch("SELECT admin_theme, avatar FROM admin_users WHERE id = ?", [getAdminId()]);
    if ($t && !empty($t['admin_theme'])) $adminTheme = $t['admin_theme'];
    if ($t && !empty($t['avatar'])) $adminAvatar = $t['avatar'];
} catch (\Throwable $e) {}
if (!in_array($adminTheme, ['light', 'dark', 'ui'])) $adminTheme = 'ui';
$isDark = ($adminTheme === 'dark');
$isUI   = ($adminTheme === 'ui');
$isLight = ($adminTheme === 'light');

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

// ── shadcn Theme Color Map ──
// Each theme provides Tailwind classes AND will inject CSS variables
$__pc = getSetting('primary_color', '#E53E3E');
$__sc = getSetting('secondary_color', '#1E293B');
$__ac = getSetting('accent_color', '#38A169');

if ($isUI) {
    $tc = [
        'body'=>'bg-background','sidebar'=>'bg-sidebar border-r border-sidebar-border',
        'sidebarBrand'=>'text-sidebar-foreground','sidebarSub'=>'text-sidebar-foreground/60','sidebarBorder'=>'border-sidebar-border',
        'sidebarIcon'=>'bg-primary/10','sidebarIconTxt'=>'text-primary',
        'sidebarBottom'=>'border-sidebar-border','sidebarBottomLink'=>'text-sidebar-foreground/60 hover:text-sidebar-foreground hover:bg-sidebar-accent',
        'navActive'=>'bg-sidebar-accent text-sidebar-accent-foreground font-medium','navNormal'=>'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-accent-foreground',
        'navSection'=>'text-sidebar-foreground/40',
        'navGroupOpen'=>'bg-sidebar-accent/70 text-sidebar-accent-foreground','navGroupNorm'=>'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-accent-foreground',
        'navChildActive'=>'text-sidebar-accent-foreground font-semibold','navChildNorm'=>'text-sidebar-foreground/50 hover:text-sidebar-accent-foreground',
        'navChildBorder'=>'border-sidebar-border',
        'quickbar'=>'bg-card border-border','qbBrandHover'=>'hover:bg-accent',
        'qbBrandIcon'=>'bg-primary','qbBrandText'=>'text-card-foreground',
        'qbLinkActive'=>'bg-primary text-primary-foreground','qbLinkNorm'=>'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
        'searchInput'=>'bg-muted/50 border-input text-foreground placeholder:text-muted-foreground',
        'searchKbd'=>'text-muted-foreground bg-muted border-border',
        'searchDropdown'=>'bg-popover border-border','searchHover'=>'accent',
        'searchBorder'=>'border-border','searchTitle'=>'text-popover-foreground','searchSub'=>'text-muted-foreground',
        'themeBtn'=>'hover:bg-accent text-muted-foreground hover:text-accent-foreground',
        'notifBtn'=>'hover:bg-accent','notifIcon'=>'text-muted-foreground',
        'profileBtn'=>'hover:bg-accent',
        'dropdown'=>'bg-popover border border-border shadow-md','ddLabel'=>'text-muted-foreground','ddLink'=>'text-popover-foreground hover:bg-accent','ddDivider'=>'border-border',
        'topHeader'=>'bg-card border-border',
        'hamburgerBtn'=>'hover:bg-accent','hamburgerIcon'=>'text-muted-foreground','pageTitle'=>'text-foreground',
        'searchIcon'=>'text-muted-foreground','profileChevron'=>'text-muted-foreground',
        'css'=>'',
    ];
} elseif ($isDark) {
    $tc = [
        'body'=>'bg-background','sidebar'=>'bg-sidebar border-r border-sidebar-border',
        'sidebarBrand'=>'text-sidebar-foreground','sidebarSub'=>'text-sidebar-foreground/50','sidebarBorder'=>'border-sidebar-border',
        'sidebarIcon'=>'bg-muted','sidebarIconTxt'=>'text-muted-foreground',
        'sidebarBottom'=>'border-sidebar-border','sidebarBottomLink'=>'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent',
        'navActive'=>'bg-sidebar-accent text-sidebar-accent-foreground','navNormal'=>'text-sidebar-foreground/60 hover:bg-sidebar-accent/50 hover:text-sidebar-accent-foreground',
        'navSection'=>'text-sidebar-foreground/30',
        'navGroupOpen'=>'bg-sidebar-accent/50 text-sidebar-accent-foreground','navGroupNorm'=>'text-sidebar-foreground/60 hover:bg-sidebar-accent/50 hover:text-sidebar-accent-foreground',
        'navChildActive'=>'text-sidebar-accent-foreground font-semibold','navChildNorm'=>'text-sidebar-foreground/40 hover:text-sidebar-accent-foreground',
        'navChildBorder'=>'border-sidebar-border',
        'quickbar'=>'bg-card border-border','qbBrandHover'=>'hover:bg-accent',
        'qbBrandIcon'=>'bg-muted','qbBrandText'=>'text-card-foreground',
        'qbLinkActive'=>'bg-accent text-accent-foreground','qbLinkNorm'=>'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
        'searchInput'=>'bg-muted/50 border-input text-foreground placeholder:text-muted-foreground',
        'searchKbd'=>'text-muted-foreground bg-muted border-border',
        'searchDropdown'=>'bg-popover border-border','searchHover'=>'accent',
        'searchBorder'=>'border-border','searchTitle'=>'text-popover-foreground','searchSub'=>'text-muted-foreground',
        'themeBtn'=>'hover:bg-accent text-muted-foreground hover:text-accent-foreground',
        'notifBtn'=>'hover:bg-accent','notifIcon'=>'text-muted-foreground',
        'profileBtn'=>'hover:bg-accent',
        'dropdown'=>'bg-popover border border-border shadow-md','ddLabel'=>'text-muted-foreground','ddLink'=>'text-popover-foreground hover:bg-accent','ddDivider'=>'border-border',
        'topHeader'=>'bg-card border-border',
        'hamburgerBtn'=>'hover:bg-accent','hamburgerIcon'=>'text-muted-foreground','pageTitle'=>'text-foreground',
        'searchIcon'=>'text-muted-foreground','profileChevron'=>'text-muted-foreground',
        'css'=>'',
    ];
} else {
    // Light theme
    $tc = [
        'body'=>'bg-background','sidebar'=>'bg-sidebar border-r border-sidebar-border',
        'sidebarBrand'=>'text-sidebar-foreground','sidebarSub'=>'text-sidebar-foreground/50','sidebarBorder'=>'border-sidebar-border',
        'sidebarIcon'=>'bg-muted','sidebarIconTxt'=>'text-muted-foreground',
        'sidebarBottom'=>'border-sidebar-border','sidebarBottomLink'=>'text-muted-foreground hover:text-sidebar-foreground hover:bg-sidebar-accent',
        'navActive'=>'bg-sidebar-accent text-sidebar-accent-foreground font-medium','navNormal'=>'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-accent-foreground',
        'navSection'=>'text-sidebar-foreground/40',
        'navGroupOpen'=>'bg-sidebar-accent/50 text-sidebar-accent-foreground','navGroupNorm'=>'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-accent-foreground',
        'navChildActive'=>'text-sidebar-accent-foreground font-semibold','navChildNorm'=>'text-sidebar-foreground/50 hover:text-sidebar-accent-foreground',
        'navChildBorder'=>'border-sidebar-border',
        'quickbar'=>'bg-card border-border','qbBrandHover'=>'hover:bg-accent',
        'qbBrandIcon'=>'bg-foreground','qbBrandText'=>'text-card-foreground',
        'qbLinkActive'=>'bg-primary text-primary-foreground','qbLinkNorm'=>'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
        'searchInput'=>'bg-muted/50 border-input text-foreground placeholder:text-muted-foreground',
        'searchKbd'=>'text-muted-foreground bg-muted border-border',
        'searchDropdown'=>'bg-popover border-border','searchHover'=>'accent',
        'searchBorder'=>'border-border','searchTitle'=>'text-popover-foreground','searchSub'=>'text-muted-foreground',
        'themeBtn'=>'hover:bg-accent text-muted-foreground hover:text-accent-foreground',
        'notifBtn'=>'hover:bg-accent','notifIcon'=>'text-muted-foreground',
        'profileBtn'=>'hover:bg-accent',
        'dropdown'=>'bg-popover border border-border shadow-md','ddLabel'=>'text-muted-foreground','ddLink'=>'text-popover-foreground hover:bg-accent','ddDivider'=>'border-border',
        'topHeader'=>'bg-card border-border',
        'hamburgerBtn'=>'hover:bg-accent','hamburgerIcon'=>'text-muted-foreground','pageTitle'=>'text-foreground',
        'searchIcon'=>'text-muted-foreground','profileChevron'=>'text-muted-foreground',
        'css'=>'',
    ];
}

// ── Navigation Helper Functions ──
function navLink($page, $label, $icon, $badge = 0) {
    global $currentPage, $tc;
    if (!canViewPage($page)) return '';
    $active = ($currentPage === $page) ? $tc['navActive'] : $tc['navNormal'];
    $href = adminUrl("pages/{$page}.php");
    $html = '<a href="'.$href.'" class="flex items-center gap-3 px-3 py-2 rounded-md text-sm transition-colors '.$active.'">';
    $html .= $icon.'<span>'.$label.'</span>';
    if ($badge > 0) $html .= '<span class="ml-auto inline-flex items-center justify-center h-5 min-w-[20px] px-1.5 text-[10px] font-semibold rounded-full bg-destructive text-destructive-foreground">'.$badge.'</span>';
    $html .= '</a>';
    return $html;
}
function navSection($title) {
    global $tc;
    return '<div class="nav-section-header px-3 pt-5 pb-2"><p class="text-[11px] font-semibold '.$tc['navSection'].' uppercase tracking-widest">'.$title.'</p></div>';
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
    $html .= '<button onclick="toggleNav(\''.$id.'\')" class="w-full flex items-center gap-3 px-3 py-2 rounded-md text-sm transition-colors '.$btnClass.'">';
    $html .= $icon.'<span>'.$label.'</span>';
    if ($badge > 0) $html .= '<span class="ml-auto inline-flex items-center justify-center h-5 min-w-[20px] px-1.5 text-[10px] font-semibold rounded-full bg-destructive text-destructive-foreground mr-1">'.$badge.'</span>';
    $html .= '<svg class="w-4 h-4 ml-auto transition-transform duration-200 nav-arrow-'.$id.' '.$arrowClass.'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>';
    $html .= '</button>';
    $html .= '<div id="nav-'.$id.'" class="ml-6 mt-1 space-y-0.5 border-l '.$tc['navChildBorder'].' pl-3 '.$openClass.'">';
    foreach ($visibleChildren as $child) {
        $active = ($currentPage === $child['page']) ? $tc['navChildActive'] : $tc['navChildNorm'];
        $href = adminUrl("pages/{$child['page']}.php");
        $html .= '<a href="'.$href.'" class="block px-3 py-1.5 text-sm rounded-md transition-colors '.$active.'">'.$child['label'];
        if (!empty($child['badge'])) $html .= '<span class="ml-2 inline-flex items-center justify-center h-4 min-w-[16px] px-1 text-[9px] font-semibold rounded-full bg-destructive text-destructive-foreground">'.$child['badge'].'</span>';
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
    <meta name="csrf-token" content="<?= generateCSRFToken() ?>">
    <title><?= $pageTitle ?? 'Dashboard' ?> - Admin Panel</title>
    <?php if ($siteFavicon): ?>
    <link rel="icon" href="<?= uploadUrl($siteFavicon) ?>" type="image/png">
    <link rel="shortcut icon" href="<?= uploadUrl($siteFavicon) ?>" type="image/png">
    <?php else: ?>
    <link rel="icon" href="<?= SITE_URL ?>/favicon.ico" type="image/x-icon">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: { sans: ['Inter', '-apple-system', 'system-ui', 'sans-serif'] },
                colors: {
                    border: 'hsl(var(--border))',
                    input: 'hsl(var(--input))',
                    ring: 'hsl(var(--ring))',
                    background: 'hsl(var(--background))',
                    foreground: 'hsl(var(--foreground))',
                    primary: { DEFAULT: 'hsl(var(--primary))', foreground: 'hsl(var(--primary-foreground))' },
                    secondary: { DEFAULT: 'hsl(var(--secondary))', foreground: 'hsl(var(--secondary-foreground))' },
                    destructive: { DEFAULT: 'hsl(var(--destructive))', foreground: 'hsl(var(--destructive-foreground))' },
                    muted: { DEFAULT: 'hsl(var(--muted))', foreground: 'hsl(var(--muted-foreground))' },
                    accent: { DEFAULT: 'hsl(var(--accent))', foreground: 'hsl(var(--accent-foreground))' },
                    popover: { DEFAULT: 'hsl(var(--popover))', foreground: 'hsl(var(--popover-foreground))' },
                    card: { DEFAULT: 'hsl(var(--card))', foreground: 'hsl(var(--card-foreground))' },
                    sidebar: {
                        DEFAULT: 'hsl(var(--sidebar-background))',
                        foreground: 'hsl(var(--sidebar-foreground))',
                        border: 'hsl(var(--sidebar-border))',
                        accent: 'hsl(var(--sidebar-accent))',
                        'accent-foreground': 'hsl(var(--sidebar-accent-foreground))',
                    },
                },
                borderRadius: {
                    lg: 'var(--radius)',
                    md: 'calc(var(--radius) - 2px)',
                    sm: 'calc(var(--radius) - 4px)',
                },
            },
        },
    };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr" defer></script>
    <style>
        /* ═══════════════════════════════════════════════════════
           shadcn/ui New York — CSS Variable Design Tokens
           Themes: Light / Dark / UI
           ═══════════════════════════════════════════════════════ */
        :root {
            --th-primary: <?=$__pc?>;
            --th-secondary: <?=$__sc?>;
            --th-accent: <?=$__ac?>;
            --th-primary-light: <?=$__pc?>15;
            --th-primary-soft: <?=$__pc?>25;
            --radius: 0.5rem;
        }

        /* ── LIGHT THEME (default) ── */
        [data-theme="light"] {
            --background: 0 0% 100%;
            --foreground: 240 10% 3.9%;
            --card: 0 0% 100%;
            --card-foreground: 240 10% 3.9%;
            --popover: 0 0% 100%;
            --popover-foreground: 240 10% 3.9%;
            --primary: 240 5.9% 10%;
            --primary-foreground: 0 0% 98%;
            --secondary: 240 4.8% 95.9%;
            --secondary-foreground: 240 5.9% 10%;
            --muted: 240 4.8% 95.9%;
            --muted-foreground: 240 3.8% 46.1%;
            --accent: 240 4.8% 95.9%;
            --accent-foreground: 240 5.9% 10%;
            --destructive: 0 84.2% 60.2%;
            --destructive-foreground: 0 0% 98%;
            --border: 240 5.9% 90%;
            --input: 240 5.9% 90%;
            --ring: 240 5.9% 10%;
            /* Sidebar */
            --sidebar-background: 0 0% 98%;
            --sidebar-foreground: 240 5.3% 26.1%;
            --sidebar-border: 240 5.9% 90%;
            --sidebar-accent: 240 4.8% 95.9%;
            --sidebar-accent-foreground: 240 5.9% 10%;
        }

        /* ── DARK THEME ── */
        [data-theme="dark"] {
            --background: 240 10% 3.9%;
            --foreground: 0 0% 98%;
            --card: 240 10% 3.9%;
            --card-foreground: 0 0% 98%;
            --popover: 240 10% 3.9%;
            --popover-foreground: 0 0% 98%;
            --primary: 0 0% 98%;
            --primary-foreground: 240 5.9% 10%;
            --secondary: 240 3.7% 15.9%;
            --secondary-foreground: 0 0% 98%;
            --muted: 240 3.7% 15.9%;
            --muted-foreground: 240 5% 64.9%;
            --accent: 240 3.7% 15.9%;
            --accent-foreground: 0 0% 98%;
            --destructive: 0 62.8% 30.6%;
            --destructive-foreground: 0 0% 98%;
            --border: 240 3.7% 15.9%;
            --input: 240 3.7% 15.9%;
            --ring: 240 4.9% 83.9%;
            /* Sidebar */
            --sidebar-background: 240 5.9% 6%;
            --sidebar-foreground: 240 4.8% 95.9%;
            --sidebar-border: 240 3.7% 15.9%;
            --sidebar-accent: 240 3.7% 15.9%;
            --sidebar-accent-foreground: 240 4.8% 95.9%;
        }

        /* ── UI THEME (branded blue sidebar) ── */
        [data-theme="ui"] {
            --background: 0 0% 98%;
            --foreground: 240 10% 3.9%;
            --card: 0 0% 100%;
            --card-foreground: 240 10% 3.9%;
            --popover: 0 0% 100%;
            --popover-foreground: 240 10% 3.9%;
            --primary: 221.2 83.2% 53.3%;
            --primary-foreground: 210 40% 98%;
            --secondary: 210 40% 96.1%;
            --secondary-foreground: 222.2 47.4% 11.2%;
            --muted: 210 40% 96.1%;
            --muted-foreground: 215.4 16.3% 46.9%;
            --accent: 210 40% 96.1%;
            --accent-foreground: 222.2 47.4% 11.2%;
            --destructive: 0 84.2% 60.2%;
            --destructive-foreground: 0 0% 98%;
            --border: 214.3 31.8% 91.4%;
            --input: 214.3 31.8% 91.4%;
            --ring: 221.2 83.2% 53.3%;
            /* Sidebar — branded dark blue */
            --sidebar-background: 222 47% 11%;
            --sidebar-foreground: 210 40% 98%;
            --sidebar-border: 217 33% 17%;
            --sidebar-accent: 217 33% 17%;
            --sidebar-accent-foreground: 210 40% 98%;
        }

        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }

        /* ── Scrollbar ── */
        .sidebar-scroll::-webkit-scrollbar { width: 4px; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(128,128,128,0.3); border-radius: 4px; }

        /* ── Theme dropdown ── */
        #themeDropdown { display: none; }
        #themeDropdown.show { display: block; }

        /* ═══════════════════════════════════════
           shadcn Component Overrides (Global)
           These apply to ALL 81 admin pages
           ═══════════════════════════════════════ */

        /* Card — replaces .panel-card */
        .panel-card {
            background: hsl(var(--card));
            color: hsl(var(--card-foreground));
            border-radius: var(--radius);
            border: 1px solid hsl(var(--border));
            padding: 1.5rem;
            transition: box-shadow .2s;
            position: relative;
            overflow: hidden;
            box-shadow: 0 1px 2px 0 rgba(0,0,0,.03);
        }
        .panel-card:hover { box-shadow: 0 1px 3px 0 rgba(0,0,0,.06), 0 1px 2px -1px rgba(0,0,0,.06); }
        .panel-card .card-title { font-size: 13px; font-weight: 600; color: hsl(var(--muted-foreground)); letter-spacing: -.01em; }
        .panel-card .card-value { font-size: 1.75rem; font-weight: 700; color: hsl(var(--card-foreground)); letter-spacing: -.02em; line-height: 1.2; }
        .panel-card .card-sub { font-size: 11px; color: hsl(var(--muted-foreground)); margin-top: 2px; }

        /* Stat icons */
        .stat-icon { width: 40px; height: 40px; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .stat-icon svg { width: 20px; height: 20px; }

        /* Grid layout */
        .panel-grid { display: grid; gap: 1rem; margin-bottom: 1.5rem; }
        .panel-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .panel-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .panel-grid-4 { grid-template-columns: repeat(4, 1fr); }
        @media (max-width: 768px) { .panel-grid-3, .panel-grid-4 { grid-template-columns: repeat(2, 1fr); } }

        /* Data table — shadcn style */
        .data-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
        .data-table thead th {
            background: hsl(var(--muted));
            color: hsl(var(--muted-foreground));
            font-weight: 500; font-size: 12px; letter-spacing: .01em;
            padding: 10px 12px; border-bottom: 1px solid hsl(var(--border));
            text-align: left;
        }
        .data-table tbody td { padding: 12px; border-bottom: 1px solid hsl(var(--border)); color: hsl(var(--card-foreground)); }
        .data-table tbody tr:hover { background: hsl(var(--accent)); }
        .data-table tbody tr:last-child td { border-bottom: none; }

        /* Progress bars */
        .progress-bar { height: 6px; border-radius: 9999px; background: hsl(var(--muted)); overflow: hidden; }
        .progress-bar .fill { height: 100%; border-radius: 9999px; transition: width .6s ease; }

        /* Badge */
        .badge-sm {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10px; font-weight: 600;
            padding: 2px 8px; border-radius: 9999px; letter-spacing: .02em;
        }

        /* Section headers */
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        .section-header h3 { font-size: 15px; font-weight: 700; color: hsl(var(--foreground)); letter-spacing: -.01em; }
        .section-header .view-all { font-size: 12px; color: hsl(var(--primary)); font-weight: 500; text-decoration: none; }
        .section-header .view-all:hover { text-decoration: underline; }

        /* ── shadcn Form Controls (global override) ── */
        input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]):not([type="file"]):not(.flatpickr-input),
        select, textarea {
            background: hsl(var(--background)) !important;
            border: 1px solid hsl(var(--input)) !important;
            border-radius: calc(var(--radius) - 2px) !important;
            color: hsl(var(--foreground)) !important;
            font-size: 14px;
            padding: 8px 12px;
            transition: border-color .15s, box-shadow .15s;
            outline: none;
        }
        input:focus, select:focus, textarea:focus {
            border-color: hsl(var(--ring)) !important;
            box-shadow: 0 0 0 2px hsl(var(--ring) / .2) !important;
        }
        input::placeholder, textarea::placeholder { color: hsl(var(--muted-foreground)); }

        /* ── shadcn Button Variants (global) ── */
        .btn, button[type="submit"], input[type="submit"] {
            font-weight: 500;
            border-radius: calc(var(--radius) - 2px);
            transition: all .15s;
            font-size: 14px;
            cursor: pointer;
        }

        /* ── shadcn Table overrides (for pages using <table> directly) ── */
        table:not(.data-table) {
            border-collapse: separate;
            border-spacing: 0;
        }
        table:not(.data-table) thead th {
            background: hsl(var(--muted));
            color: hsl(var(--muted-foreground));
            font-weight: 500;
            font-size: 12px;
            border-bottom: 1px solid hsl(var(--border));
        }
        table:not(.data-table) tbody td {
            border-bottom: 1px solid hsl(var(--border));
            color: hsl(var(--card-foreground));
        }
        table:not(.data-table) tbody tr:hover {
            background: hsl(var(--accent));
        }

        /* ── Override old Tailwind classes to use CSS variables ── */
        .bg-white { background: hsl(var(--card)) !important; }
        .bg-gray-50 { background: hsl(var(--background)) !important; }
        .bg-gray-100 { background: hsl(var(--muted)) !important; }
        .border-gray-100, .border-gray-200, .border-gray-300 { border-color: hsl(var(--border)) !important; }
        .border { border-color: hsl(var(--border)) !important; }
        .text-gray-900, .text-gray-800 { color: hsl(var(--foreground)) !important; }
        .text-gray-700 { color: hsl(var(--card-foreground)) !important; }
        .text-gray-600 { color: hsl(var(--muted-foreground)) !important; }
        .text-gray-500 { color: hsl(var(--muted-foreground)) !important; }
        .text-gray-400 { color: hsl(var(--muted-foreground) / .7) !important; }
        .shadow-sm { box-shadow: 0 1px 2px 0 rgba(0,0,0,.03) !important; }
        .border-b { border-color: hsl(var(--border)) !important; }
        .hover\:bg-gray-50:hover { background: hsl(var(--accent)) !important; }
        .hover\:bg-gray-100:hover { background: hsl(var(--accent)) !important; }
        .divide-y > :not([hidden]) ~ :not([hidden]) { border-color: hsl(var(--border)); }
        .divide-gray-100 > :not([hidden]) ~ :not([hidden]) { border-color: hsl(var(--border)); }
        .divide-gray-200 > :not([hidden]) ~ :not([hidden]) { border-color: hsl(var(--border)); }
        .ring-1 { --tw-ring-color: hsl(var(--border)) !important; }
        .rounded-xl { border-radius: var(--radius) !important; }
        .rounded-lg { border-radius: var(--radius) !important; }

        /* ── Flatpickr theme override ── */
        .flatpickr-calendar { background: hsl(var(--popover)); border-color: hsl(var(--border)); }
        .flatpickr-day.selected { background: hsl(var(--primary)) !important; color: hsl(var(--primary-foreground)) !important; }
    </style>
</head>
<body class="<?= $tc['body'] ?>">

<!-- ═══ TOP QUICK ACCESS BAR ═══ -->
<div id="quickBar" class="fixed top-0 left-0 right-0 z-[55] <?= $tc['quickbar'] ?> border-b" style="height:44px">
    <div class="flex items-center h-[44px] px-2 lg:px-4 gap-1">
        <a href="<?= adminUrl('pages/dashboard.php') ?>" class="flex items-center gap-1.5 px-2 py-1 rounded-md <?= $tc['qbBrandHover'] ?> mr-1 shrink-0">
            <?php if ($siteLogo): ?>
            <img src="<?= uploadUrl($siteLogo) ?>" alt="<?= e($siteName) ?>" class="h-6 w-auto object-contain max-w-[120px]">
            <?php else: ?>
            <div class="w-6 h-6 <?= $tc['qbBrandIcon'] ?> rounded-md flex items-center justify-center"><span class="text-primary-foreground text-xs font-bold"><?= strtoupper(substr($siteName,0,1)) ?></span></div>
            <span class="hidden md:inline text-sm font-bold <?= $tc['qbBrandText'] ?>"><?= e($siteName) ?></span>
            <?php endif; ?>
        </a>
        <div class="flex items-center gap-0.5 shrink-0">
            <a href="<?= adminUrl('pages/search.php') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-medium <?= $currentPage==='search' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition-colors">Search</a>
            <?php if (canViewPage('order-add')): ?><a href="<?= adminUrl('pages/order-add.php') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-medium <?= $currentPage==='order-add' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition-colors hidden sm:inline-block">NewOrder</a><?php endif; ?>
            <?php if (canViewPage('order-management')): ?><a href="<?= adminUrl('pages/order-management.php?status=confirmed') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-medium <?= $currentPage==='order-management' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition-colors hidden sm:inline-block">Orders</a><?php endif; ?>
            <?php if (canViewPage('order-processing')): ?><a href="<?= adminUrl('pages/order-processing.php') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-medium <?= $currentPage==='order-processing' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition-colors hidden md:inline-block">Processing</a><?php endif; ?>
            <?php if (canViewPage('incomplete-orders')): ?><a href="<?= adminUrl('pages/order-management.php?status=incomplete') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-medium <?= $currentPage==='incomplete-orders' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition-colors hidden md:inline-block">Incomplete</a><?php endif; ?>
            <?php if (canViewPage('scan-to-update')): ?><a href="<?= adminUrl('pages/scan-to-update.php') ?>" class="px-2.5 py-1.5 rounded-md text-xs font-medium <?= $currentPage==='scan-to-update' ? $tc['qbLinkActive'] : $tc['qbLinkNorm'] ?> transition-colors hidden lg:inline-flex items-center gap-1" title="Scan To Update"><i class="fas fa-barcode text-[10px]"></i>Scan</a><?php endif; ?>
        </div>
        <!-- Global Search -->
        <div class="flex-1 max-w-md mx-2 relative" id="globalSearchWrap">
            <div class="relative">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 <?= $tc['searchIcon'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" id="globalSearchInput" placeholder="Search orders, customers, phone..." class="w-full pl-8 pr-8 py-1.5 text-xs <?= $tc['searchInput'] ?> border rounded-md focus:ring-2 focus:ring-ring/20 focus:border-ring outline-none transition-colors" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();goSearch()}" oninput="liveSearch(this.value)">
                <kbd class="absolute right-2 top-1/2 -translate-y-1/2 text-[9px] <?= $tc['searchKbd'] ?> border px-1 py-0.5 rounded font-mono hidden sm:inline">/</kbd>
            </div>
            <div id="globalSearchResults" class="hidden absolute top-full left-0 right-0 mt-1 <?= $tc['searchDropdown'] ?> border rounded-md shadow-lg max-h-[400px] overflow-y-auto z-[100]"></div>
        </div>
        <!-- Right side -->
        <div class="flex items-center gap-1 shrink-0">
            <!-- Theme Switcher -->
            <div class="relative" id="themeWrap">
                <button onclick="document.getElementById('themeDropdown').classList.toggle('show')" class="p-1.5 rounded-md <?= $tc['themeBtn'] ?> transition-colors" title="Switch theme">
                    <?php if ($isDark): ?>
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/></svg>
                    <?php elseif ($isLight): ?>
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"/></svg>
                    <?php else: ?>
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm1 3a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1H6a1 1 0 01-1-1V5zm6 0a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1V5zm-6 6a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1H6a1 1 0 01-1-1v-2zm6 0a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1v-2z"/></svg>
                    <?php endif; ?>
                </button>
                <div id="themeDropdown" class="absolute right-0 mt-2 w-44 <?= $tc['dropdown'] ?> rounded-md z-[100] py-1">
                    <p class="px-3 py-1.5 text-[10px] font-semibold <?= $tc['ddLabel'] ?> uppercase tracking-wider">Theme</p>
                    <button onclick="switchTheme('light')" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm <?= $tc['ddLink'] ?> transition-colors rounded-sm mx-0">
                        <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"/></svg>
                        <span>Light</span>
                        <?php if ($isLight): ?><svg class="w-3.5 h-3.5 ml-auto text-primary" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><?php endif; ?>
                    </button>
                    <button onclick="switchTheme('dark')" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm <?= $tc['ddLink'] ?> transition-colors rounded-sm">
                        <svg class="w-4 h-4 text-indigo-400" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/></svg>
                        <span>Dark</span>
                        <?php if ($isDark): ?><svg class="w-3.5 h-3.5 ml-auto text-primary" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><?php endif; ?>
                    </button>
                    <button onclick="switchTheme('ui')" class="w-full flex items-center gap-2.5 px-3 py-2 text-sm <?= $tc['ddLink'] ?> transition-colors rounded-sm">
                        <svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2H4zm1 3a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1H6a1 1 0 01-1-1V5zm6 0a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1V5zm-6 6a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1H6a1 1 0 01-1-1v-2zm6 0a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 01-1 1h-2a1 1 0 01-1-1v-2z"/></svg>
                        <span>UI Color</span>
                        <?php if ($isUI): ?><svg class="w-3.5 h-3.5 ml-auto text-primary" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><?php endif; ?>
                    </button>
                </div>
            </div>
            <a href="<?= adminUrl('pages/notifications.php') ?>" class="relative p-1.5 rounded-md <?= $tc['notifBtn'] ?> transition-colors">
                <svg class="w-4 h-4 <?= $tc['notifIcon'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <?php if ($stats['unread_notifications'] > 0): ?><span class="absolute -top-0.5 -right-0.5 bg-destructive text-destructive-foreground text-[9px] w-4 h-4 rounded-full flex items-center justify-center"><?= $stats['unread_notifications'] > 9 ? '9+' : $stats['unread_notifications'] ?></span><?php endif; ?>
            </a>
            <div class="relative">
                <button onclick="this.nextElementSibling.classList.toggle('hidden')" class="flex items-center gap-1.5 px-2 py-1 rounded-md <?= $tc['profileBtn'] ?> transition-colors">
                    <?php if ($adminAvatar): ?><img src="<?= uploadUrl($adminAvatar) ?>" class="w-6 h-6 rounded-full object-cover"><?php else: ?><div class="w-6 h-6 bg-primary rounded-full flex items-center justify-center text-primary-foreground text-[10px] font-bold"><?= strtoupper(substr(getAdminName(),0,1)) ?></div><?php endif; ?>
                    <svg class="w-3 h-3 <?= $tc['profileChevron'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div class="hidden absolute right-0 mt-2 w-48 <?= $tc['dropdown'] ?> rounded-md py-1 z-[100]">
                    <p class="px-4 py-1.5 text-[10px] font-semibold <?= $tc['ddLabel'] ?> uppercase"><?= e(getAdminName()) ?></p>
                    <a href="<?= adminUrl('pages/profile.php') ?>" class="block px-4 py-2 text-sm <?= $tc['ddLink'] ?> rounded-sm"><i class="fas fa-user-circle mr-2 opacity-50"></i>Profile</a>
                    <a href="<?= adminUrl('pages/settings.php') ?>" class="block px-4 py-2 text-sm <?= $tc['ddLink'] ?> rounded-sm"><i class="fas fa-cog mr-2 opacity-50"></i>Settings</a>
                    <?php if (isSuperAdmin() || $viewAsRole): ?>
                    <hr class="my-1 <?= $tc['ddDivider'] ?>">
                    <p class="px-4 py-1 text-[10px] font-semibold <?= $tc['ddLabel'] ?>">VIEW AS ROLE</p>
                    <?php foreach ($allRoles as $r): ?>
                    <a href="<?= adminUrl('pages/profile.php?action=view_as&role_id='.$r['id']) ?>" class="block px-4 py-1.5 text-xs <?= $tc['ddLink'] ?> rounded-sm <?= ($viewAsRole && $viewAsRole['id']==$r['id']) ? 'font-bold text-primary' : '' ?>"><?= e($r['role_name']) ?><?php if ($viewAsRole && $viewAsRole['id']==$r['id']): ?> ✓<?php endif; ?></a>
                    <?php endforeach; ?>
                    <?php if ($viewAsRole): ?>
                    <a href="<?= adminUrl('pages/profile.php?action=exit_view_as') ?>" class="block px-4 py-1.5 text-xs text-amber-600 font-semibold hover:bg-amber-50 rounded-sm">✕ Exit Preview</a>
                    <?php endif; ?>
                    <?php endif; ?>
                    <hr class="my-1 <?= $tc['ddDivider'] ?>">
                    <a href="<?= adminUrl('index.php?action=logout') ?>" class="block px-4 py-2 text-sm text-destructive hover:bg-destructive/10 rounded-sm"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
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
            if(!d.results||!d.results.length){box.innerHTML='<p class="p-4 text-sm text-muted-foreground text-center">No results</p>';box.classList.remove('hidden');return;}
            let h='';
            d.results.forEach(r=>{
                const badge=r.status_badge||'bg-muted text-muted-foreground';
                h+=`<a href="${r.url}" class="flex items-center gap-3 px-4 py-2.5 hover:bg-accent border-b border-border transition-colors">
                    <div class="shrink-0 w-8 h-8 bg-primary/10 rounded-md flex items-center justify-center text-primary text-xs font-bold">#</div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-popover-foreground truncate">${r.order_number} — ${r.customer_name||'Unknown'}</p>
                        <p class="text-[11px] text-muted-foreground">${r.customer_phone||''} · ৳${r.total} · ${r.date||''}</p>
                    </div>
                    <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold ${badge}">${(r.status||'').toUpperCase()}</span>
                </a>`;
            });
            if(d.total>8) h+=`<a href="<?= adminUrl("pages/search.php") ?>?q=${encodeURIComponent(q.trim())}" class="block text-center py-2.5 text-xs text-primary font-semibold hover:bg-accent transition-colors">View all ${d.total} results →</a>`;
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
// GLOBAL CONFIRM OVERRIDE — shadcn styled modal
// ═══════════════════════════════════════════════════════════════════
(function () {
    const _overlay = document.createElement('div');
    _overlay.id = '__confirmModal';
    _overlay.style.cssText = 'display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;backdrop-filter:blur(2px);';
    _overlay.innerHTML = `
        <div style="background:hsl(var(--popover));color:hsl(var(--popover-foreground));border-radius:var(--radius);border:1px solid hsl(var(--border));box-shadow:0 16px 70px rgba(0,0,0,0.15);width:100%;max-width:380px;margin:0 16px;overflow:hidden;transform:scale(0.95);transition:transform 0.15s ease;">
            <div style="padding:24px 24px 16px;">
                <div style="display:flex;align-items:flex-start;gap:12px;">
                    <div id="__cIcon" style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px;"></div>
                    <div>
                        <div id="__cTitle" style="font-weight:600;font-size:14px;margin-bottom:4px;"></div>
                        <div id="__cMsg" style="font-size:13px;color:hsl(var(--muted-foreground));line-height:1.5;"></div>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:8px;padding:0 24px 20px;justify-content:flex-end;">
                <button id="__cCancel" style="padding:8px 16px;font-size:13px;font-weight:500;color:hsl(var(--foreground));background:hsl(var(--secondary));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) - 2px);cursor:pointer;transition:all 0.15s;">Cancel</button>
                <button id="__cOk" style="padding:8px 16px;font-size:13px;font-weight:600;border:none;border-radius:calc(var(--radius) - 2px);cursor:pointer;transition:all 0.15s;"></button>
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
            const parts = msg.split('\n');
            const hasTitle = parts.length > 1 && parts[0].length < 60;
            const titleText = hasTitle ? parts[0] : (type === 'danger' ? 'Are you sure?' : 'Confirm');
            const bodyText  = hasTitle ? parts.slice(1).join(' ') : msg;

            const styles = {
                danger:  { bg:'hsl(0 84% 60% / .1)', color:'hsl(0 84% 60%)', icon:'⚠', okBg:'hsl(var(--destructive))', okColor:'hsl(var(--destructive-foreground))', okLabel:'Delete' },
                warning: { bg:'hsl(38 92% 50% / .1)', color:'hsl(38 92% 50%)', icon:'!',  okBg:'hsl(38 92% 50%)', okColor:'#fff', okLabel:'Confirm' },
                info:    { bg:'hsl(var(--primary) / .1)', color:'hsl(var(--primary))', icon:'i',  okBg:'hsl(var(--primary))', okColor:'hsl(var(--primary-foreground))', okLabel:'OK' },
            };
            const s = styles[type] || styles.info;

            icon.style.cssText  = `width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px;background:${s.bg};color:${s.color};font-weight:900;font-style:italic;`;
            icon.textContent     = s.icon;
            title.textContent    = titleText;
            msgEl.textContent    = bodyText;
            okBtn.textContent    = type === 'danger' ? 'Delete' : 'Confirm';
            okBtn.style.background = s.okBg;
            okBtn.style.color = s.okColor;

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

    document.addEventListener('DOMContentLoaded', () => {
        _overlay.querySelector('#__cOk').addEventListener('click', () => _close(true));
        _overlay.querySelector('#__cCancel').addEventListener('click', () => _close(false));
        _overlay.addEventListener('click', e => { if (e.target === _overlay) _close(false); });
        document.addEventListener('keydown', e => {
            if (_overlay.style.display !== 'none') {
                if (e.key === 'Enter')  { e.preventDefault(); _close(true); }
                if (e.key === 'Escape') { e.preventDefault(); _close(false); }
            }
        });
    });

    function _detectType(msg) {
        const m = msg.toLowerCase();
        if (/delete|remove|cancel|মুছ|পরিষ্কার|clear all/.test(m)) return 'danger';
        if (/block|warn|স্থায়ী/.test(m)) return 'warning';
        return 'info';
    }

    window._confirmAsync = function(msg) { return _show(msg, _detectType(msg)); };
    window.__nativeConfirm = window.confirm.bind(window);
    window.confirm = function (msg) {
        const type = _detectType(msg);
        _show(msg, type).then(result => {
            if (result && window.__pendingConfirmAction) { window.__pendingConfirmAction(); }
            window.__pendingConfirmAction = null;
        });
        return false;
    };
    document.addEventListener('submit', function(e) {
        const form = e.target;
        const handler = form.getAttribute('onsubmit');
        if (handler && handler.includes('confirm(')) {
            e.preventDefault(); e.stopImmediatePropagation();
            const match = handler.match(/confirm\(['"]([^'"]+)['"]/);
            const msg   = match ? match[1] : 'Are you sure?';
            _show(msg, _detectType(msg)).then(ok => { if (ok) { form.removeAttribute('onsubmit'); form.submit(); } });
        }
    }, true);
    document.addEventListener('click', function(e) {
        const el = e.target.closest('[onclick]');
        if (!el) return;
        const handler = el.getAttribute('onclick');
        if (!handler || !handler.includes('confirm(')) return;
        if (!handler.match(/return confirm\(/)) return;
        e.preventDefault(); e.stopImmediatePropagation();
        const match = handler.match(/confirm\(['"]([^'"]+)['"]/);
        const msg   = match ? match[1] : 'Are you sure?';
        _show(msg, _detectType(msg)).then(ok => {
            if (ok) {
                const newHandler = handler.replace(/return confirm\([^)]+\);?\s*/g, '');
                if (newHandler.trim()) { try { new Function(newHandler).call(el); } catch(err) {} }
                if (el.tagName === 'A' && el.href && el.href !== '#') { window.location.href = el.href; }
                const parentForm = el.closest('form');
                if (parentForm && (el.tagName === 'BUTTON' || el.type === 'submit')) { parentForm.submit(); }
            }
        });
    }, true);
}());

// ═══════════════════════════════════════════════════════════════════
// GLOBAL PROMPT REPLACEMENT — shadcn styled
// ═══════════════════════════════════════════════════════════════════
(function () {
    const _pOverlay = document.createElement('div');
    _pOverlay.id = '__promptModal';
    _pOverlay.style.cssText = 'display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;backdrop-filter:blur(2px);';
    _pOverlay.innerHTML = `
        <div id="__pInner" style="background:hsl(var(--popover));color:hsl(var(--popover-foreground));border-radius:var(--radius);border:1px solid hsl(var(--border));box-shadow:0 16px 70px rgba(0,0,0,0.15);width:100%;max-width:400px;margin:0 16px;overflow:hidden;transform:scale(0.95);transition:transform 0.15s ease;">
            <div style="padding:20px 20px 16px;">
                <div id="__pTitle" style="font-weight:600;font-size:14px;margin-bottom:4px;"></div>
                <div id="__pMsg"   style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:12px;"></div>
                <input id="__pInput" type="text" autocomplete="off"
                    style="width:100%;padding:8px 12px;font-size:13px;border:1px solid hsl(var(--input));border-radius:calc(var(--radius) - 2px);outline:none;box-sizing:border-box;transition:border-color 0.15s;background:hsl(var(--background));color:hsl(var(--foreground));">
            </div>
            <div style="display:flex;gap:8px;padding:0 20px 16px;justify-content:flex-end;">
                <button id="__pCancel" style="padding:8px 16px;font-size:13px;font-weight:500;color:hsl(var(--foreground));background:hsl(var(--secondary));border:1px solid hsl(var(--border));border-radius:calc(var(--radius) - 2px);cursor:pointer;">Cancel</button>
                <button id="__pOk" style="padding:8px 16px;font-size:13px;font-weight:600;color:hsl(var(--primary-foreground));background:hsl(var(--primary));border:none;border-radius:calc(var(--radius) - 2px);cursor:pointer;">OK</button>
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
        document.getElementById('__pOk').addEventListener('click', () => { _pClose(document.getElementById('__pInput').value); });
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
            input.value = defaultVal || ''; input.placeholder = defaultVal || '';
            _pOverlay.style.display = 'flex';
            const inner = document.getElementById('__pInner');
            if (inner) setTimeout(() => { inner.style.transform = 'scale(1)'; }, 10);
            setTimeout(() => { input.focus(); input.select(); }, 50);
        });
    };
    window.prompt = function(msg, defaultVal) { return null; };
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
            <?php if ($siteLogo): ?>
            <img src="<?= uploadUrl($siteLogo) ?>" alt="<?= e($siteName) ?>" class="h-8 w-auto object-contain max-w-[130px]">
            <?php else: ?>
            <div class="w-9 h-9 <?= $tc['sidebarIcon'] ?> rounded-md flex items-center justify-center">
                <svg class="w-5 h-5 <?= $tc['sidebarIconTxt'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
                <h1 class="<?= $tc['sidebarBrand'] ?> font-bold text-sm"><?= e($siteName) ?></h1>
                <p class="<?= $tc['sidebarSub'] ?> text-xs">Admin Panel</p>
            </div>
            <?php endif; ?>
        </div>
        <nav class="sidebar-scroll overflow-y-auto h-[calc(100vh-174px)] py-3 px-3 space-y-0.5">
            <?= navLink('dashboard', 'Dashboard', $icons['dashboard']) ?>
            <?php
            $salesItems = navGroup('order-management', 'Order Management', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>', [
                subItem('order-management', 'All Orders'),
                subItem('order-processing', 'Processing', $stats['pending_orders']),
                subItem('order-add', 'New Order'),

                subItem('returns', 'Returns'),
                subItem('scan-to-update', 'Scan To Update'),
            ], $stats['pending_orders'] + $stats['approved_orders']);
            $salesItems .= navGroup('crm-system', 'CRM System', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>', [
                subItem('customer-retention', 'Retention Dashboard'),
                subItem('call-center', 'Call Center'),
            ]);
            $salesItems .= navLink('customers', 'Customers', $icons['customers']);
            if (trim($salesItems)): ?>
            <?= navSection('Sales') ?>
            <?= $salesItems ?>
            <?php endif;
            $catalogItems = navLink('products', 'Products', $icons['products']);
            $catalogItems .= navLink('categories', 'Categories', $icons['categories']);
            $catalogItems .= navGroup('inventory-nav', 'Inventory', $icons['inventory'], [
                subItem('inventory', 'Stock Management'),
                subItem('inventory-dashboard', 'Dashboard'),
                subItem('smart-restock', 'Smart Restock'),
                subItem('stock-increase-list', 'Stock Increase'),
                subItem('stock-decrease-list', 'Stock Decrease'),
                subItem('stock-transfer', 'Stock Transfer'),
            ]);
            $catalogItems .= navLink('media', 'Media Library', $icons['media']);
            $catalogItems .= navLink('reviews', 'Reviews', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>');
            $catalogItems .= navLink('suppliers', 'Suppliers', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>');
            $catalogItems .= navLink('coupons', 'Coupons', $icons['coupons']);
            if (trim($catalogItems)): ?>
            <?= navSection('Catalog') ?>
            <?= $catalogItems ?>
            <?php endif;
            $shippingItems = navLink('courier', 'Courier Settings', $icons['courier']);
            $shippingItems .= navLink('select-sticker', 'Shipping Labels', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>');
            if (trim($shippingItems)): ?>
            <?= navSection('Shipping') ?>
            <?= $shippingItems ?>
            <?php endif;
            $financeItems = navGroup('accounting-nav', 'Accounting', $icons['accounting'], [
                subItem('accounting', 'Dashboard'),
                subItem('expenses', 'Expenses'),
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
                subItem('report-cancel-log', 'Cancel & Delete Log'),
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
            $systemItems .= navLink('load-tester', 'Load Tester', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>');
            $systemItems .= navLink('speed', 'Speed & Cache', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>');
            $systemItems .= navLink('api-health', 'API Health', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>');
            $systemItems .= navLink('security', 'Security Center', '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>');
            if (trim($systemItems)): ?>
            <?= navSection('System') ?>
            <?= $systemItems ?>
            <?php endif; ?>
        </nav>
        <div class="absolute bottom-0 left-0 right-0 p-3 border-t <?= $tc['sidebarBottom'] ?>">
            <a href="<?= url() ?>" target="_blank" class="flex items-center gap-2 <?= $tc['sidebarBottomLink'] ?> text-xs px-3 py-2 rounded-md transition-colors">
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
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-md <?= $tc['hamburgerBtn'] ?>">
                        <svg class="w-5 h-5 <?= $tc['hamburgerIcon'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <h2 class="text-lg font-semibold <?= $tc['pageTitle'] ?>"><?= $pageTitle ?? 'Dashboard' ?></h2>
                </div>
            </div>
        </header>
        <main class="p-4 lg:p-6">
<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="bg-destructive/10 border border-destructive/20 text-destructive px-4 py-3 rounded-md mb-4 text-sm"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
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
