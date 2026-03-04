<?php
/**
 * Admin Header & Sidebar Layout - Collapsible sub-menus
 */
requireAdmin();
$stats = getDashboardStats();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$siteName = getSetting('site_name', 'E-Commerce');

function navLink($page, $label, $icon, $badge = 0) {
    global $currentPage;
    $active = ($currentPage === $page) ? 'bg-blue-700 text-white' : 'text-blue-100 hover:bg-blue-700/50';
    $href = adminUrl("pages/{$page}.php");
    $html = '<a href="' . $href . '" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition ' . $active . '">';
    $html .= $icon . '<span>' . $label . '</span>';
    if ($badge > 0) {
        $html .= '<span class="ml-auto bg-red-500 text-white text-xs px-2 py-0.5 rounded-full">' . $badge . '</span>';
    }
    $html .= '</a>';
    return $html;
}

function navSection($title) {
    return '<div class="px-4 pt-5 pb-2"><p class="text-xs font-semibold text-blue-300/60 uppercase tracking-wider">' . $title . '</p></div>';
}

function navGroup($id, $label, $icon, $children, $badge = 0) {
    global $currentPage;
    $isOpen = false;
    foreach ($children as $child) {
        if ($currentPage === $child['page']) { $isOpen = true; break; }
    }
    $openClass = $isOpen ? '' : 'hidden';
    $arrowClass = $isOpen ? 'rotate-90' : '';
    $btnClass = $isOpen ? 'bg-blue-700/50 text-white' : 'text-blue-100 hover:bg-blue-700/50';
    
    $html = '<div class="nav-group">';
    $html .= '<button onclick="toggleNav(\'' . $id . '\')" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition ' . $btnClass . '">';
    $html .= $icon . '<span>' . $label . '</span>';
    if ($badge > 0) {
        $html .= '<span class="ml-auto bg-red-500 text-white text-xs px-2 py-0.5 rounded-full mr-1">' . $badge . '</span>';
    }
    $html .= '<svg class="w-4 h-4 ml-auto transition-transform duration-200 nav-arrow-' . $id . ' ' . $arrowClass . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>';
    $html .= '</button>';
    $html .= '<div id="nav-' . $id . '" class="ml-6 mt-1 space-y-0.5 border-l border-blue-600/40 pl-3 ' . $openClass . '">';
    foreach ($children as $child) {
        $active = ($currentPage === $child['page']) ? 'text-white font-semibold' : 'text-blue-200/70 hover:text-white';
        $href = adminUrl("pages/{$child['page']}.php");
        $html .= '<a href="' . $href . '" class="block px-3 py-1.5 text-sm rounded-lg transition ' . $active . '">' . $child['label'];
        if (!empty($child['badge'])) $html .= '<span class="ml-2 bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full">' . $child['badge'] . '</span>';
        $html .= '</a>';
    }
    $html .= '</div></div>';
    return $html;
}

function subItem($page, $label, $badge = 0) { return ['page' => $page, 'label' => $label, 'badge' => $badge]; }

$icons = [
    'dashboard' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>',
    'orders' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>',
    'products' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>',
    'categories' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>',
    'customers' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    'inventory' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
    'courier' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>',
    'reports' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>',
    'settings' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    'accounting' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'tasks' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>',
    'expenses' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
    'returns' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>',
    'pages' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>',
    'banners' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
    'coupons' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>',
    'employees' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
    'media' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
    'landing-pages' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/></svg>',
    'visitors' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>',
    'page-builder' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm0 8a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zm10 0a1 1 0 011-1h4a1 1 0 011 1v6a1 1 0 01-1 1h-4a1 1 0 01-1-1v-6z"/></svg>',
    'shop-design' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>',
    'checkout-fields' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    'progress-bars' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/></svg>',
    'blog' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="en">
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
        body { font-family: 'Inter', sans-serif; }
        .sidebar-scroll::-webkit-scrollbar { width: 4px; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-gray-50">
<div class="flex min-h-screen">
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-gradient-to-b from-blue-800 to-blue-900 transform -translate-x-full lg:translate-x-0 transition-transform duration-200 ease-in-out">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-blue-700/50">
            <div class="w-9 h-9 bg-white/20 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
                <h1 class="text-white font-bold text-sm"><?= e($siteName) ?></h1>
                <p class="text-blue-300 text-xs">Admin Panel</p>
            </div>
        </div>
        <nav class="sidebar-scroll overflow-y-auto h-[calc(100vh-130px)] py-3 px-3 space-y-0.5">
            <?= navLink('dashboard', 'Dashboard', $icons['dashboard']) ?>
            
            <?= navSection('Sales') ?>
            <?= navGroup('orders', 'Orders', $icons['orders'], [
                subItem('orders', 'All Orders'),
                subItem('order-add', 'New Order'),
                subItem('incomplete-orders', 'Abandoned Carts'),
                subItem('returns', 'Returns'),
            ], $stats['pending_orders']) ?>
            <?= navLink('customers', 'Customers', $icons['customers']) ?>
            <?= navLink('coupons', 'Coupons', $icons['coupons']) ?>
            <?= navLink('visitors', 'Visitors', $icons['visitors']) ?>
            
            <?= navSection('Catalog') ?>
            <?= navGroup('inventory', 'Inventory', $icons['inventory'], [
                subItem('product-form', 'Add New Product'),
                subItem('products', 'Product List'),
                subItem('categories', 'Categories & Brands'),
                subItem('inventory', 'Stock Management'),
            ], $stats['low_stock']) ?>
            <?= navLink('media', 'Media Gallery', $icons['media']) ?>
            
            <?= navSection('Shipping') ?>
            <?= navLink('courier', 'Delivery Methods', $icons['courier']) ?>
            
            <?= navSection('Finance') ?>
            <?= navGroup('finance', 'Accounting', $icons['accounting'], [
                subItem('accounting', 'Accounts'),
                subItem('expenses', 'Expenses'),
                subItem('reports', 'Reports & AI'),
            ]) ?>
            
            <?= navSection('Content') ?>
            <?= navLink('page-builder', 'Page Builder', $icons['page-builder']) ?>
            <?= navLink('shop-design', 'Shop Design', $icons['shop-design']) ?>
            <?= navLink('checkout-fields', 'Checkout Fields', $icons['checkout-fields']) ?>
            <?= navLink('progress-bars', 'Progress Bar Offers', $icons['progress-bars']) ?>
            <?= navLink('landing-pages', 'Landing Pages', $icons['landing-pages']) ?>
            <?= navLink('banners', 'Banners', $icons['banners']) ?>
            <?= navLink('cms-pages', 'Pages', $icons['pages']) ?>
            <?= navLink('blog', 'Blog Posts', $icons['blog']) ?>
            
            <?= navSection('Team') ?>
            <?= navGroup('team', 'HRM', $icons['employees'], [
                subItem('employees', 'Employees'),
                subItem('tasks', 'Tasks & Follow-up'),
            ], $stats['pending_tasks']) ?>
            
            <?= navSection('System') ?>
            <?= navLink('settings', 'Settings', $icons['settings']) ?>
        </nav>
        <div class="absolute bottom-0 left-0 right-0 p-3 border-t border-blue-700/50">
            <a href="<?= url() ?>" target="_blank" class="flex items-center gap-2 text-blue-200 hover:text-white text-xs px-3 py-2 rounded-lg hover:bg-blue-700/50 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                View Store
            </a>
        </div>
    </aside>
    
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 lg:hidden hidden" onclick="toggleSidebar()"></div>

    <div class="flex-1 lg:ml-64">
        <header class="sticky top-0 z-30 bg-white border-b border-gray-200">
            <div class="flex items-center justify-between px-3 lg:px-5 py-2">
                <div class="flex items-center gap-0.5 overflow-x-auto no-scrollbar">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 mr-1 flex-shrink-0">
                        <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <a href="<?= adminUrl('pages/dashboard.php') ?>" class="flex items-center gap-2 mr-3 flex-shrink-0">
                        <div class="w-7 h-7 bg-blue-600 rounded-lg flex items-center justify-center"><span class="text-white font-bold text-xs">K</span></div>
                        <span class="font-bold text-gray-800 text-sm hidden sm:inline"><?= e($siteName) ?></span>
                    </a>
                    <a href="<?= adminUrl('pages/orders.php') ?>" class="px-2.5 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100 rounded-lg whitespace-nowrap transition flex-shrink-0">Search</a>
                    <a href="<?= adminUrl('pages/order-add.php') ?>" class="px-2.5 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100 rounded-lg whitespace-nowrap transition flex-shrink-0">NewOrder</a>
                    <a href="<?= adminUrl('pages/order-management.php') ?>" class="px-2.5 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100 rounded-lg whitespace-nowrap transition flex-shrink-0">Orders</a>
                    <a href="<?= adminUrl('pages/order-management.php?status=processing') ?>" class="px-2.5 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100 rounded-lg whitespace-nowrap transition flex-shrink-0">Processing</a>
                    <a href="<?= adminUrl('pages/incomplete-orders.php') ?>" class="px-2.5 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100 rounded-lg whitespace-nowrap transition flex-shrink-0">Incomplete</a>
                    <form action="<?= adminUrl('pages/order-management.php') ?>" method="GET" class="hidden md:flex items-center ml-2 flex-shrink-0">
                        <div class="relative">
                            <svg class="w-4 h-4 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" name="search" placeholder="Search orders, customers, phone..." class="pl-8 pr-3 py-1.5 text-xs border border-gray-200 rounded-lg w-56 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 focus:outline-none bg-gray-50">
                        </div>
                    </form>
                </div>
                <div class="flex items-center gap-1.5 flex-shrink-0 ml-2">
                    <button onclick="document.documentElement.classList.toggle('dark')" class="p-2 rounded-lg hover:bg-gray-100 transition" title="Toggle theme">
                        <svg class="w-4.5 h-4.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    </button>
                    <a href="<?= adminUrl('pages/notifications.php') ?>" class="relative p-2 rounded-lg hover:bg-gray-100 transition">
                        <svg class="w-4.5 h-4.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <?php if (($stats['unread_notifications'] ?? 0) > 0): ?>
                        <span class="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center"><?= ($stats['unread_notifications'] ?? 0) > 9 ? '9+' : $stats['unread_notifications'] ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="relative">
                        <button onclick="this.nextElementSibling.classList.toggle('hidden')" class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-100 transition">
                            <div class="w-7 h-7 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-semibold"><?= strtoupper(substr(getAdminName(), 0, 1)) ?></div>
                            <span class="hidden sm:inline text-xs font-medium text-gray-700"><?= e(getAdminName()) ?></span>
                            <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border py-1 z-50">
                            <a href="<?= adminUrl('pages/profile.php') ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Profile</a>
                            <a href="<?= adminUrl('pages/settings.php') ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Settings</a>
                            <hr class="my-1">
                            <a href="<?= adminUrl('login.php?action=logout') ?>" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50">Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <main class="p-4 lg:p-6">
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('-translate-x-full');document.getElementById('sidebarOverlay').classList.toggle('hidden');}
function toggleNav(id){const el=document.getElementById('nav-'+id);const arrow=document.querySelector('.nav-arrow-'+id);el.classList.toggle('hidden');arrow?.classList.toggle('rotate-90');}
</script>