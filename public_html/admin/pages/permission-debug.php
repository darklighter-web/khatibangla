<?php
/**
 * Permission System Diagnostic Tool
 * Access: /admin/pages/permission-debug.php
 * Only accessible to super_admin
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

// Only super admin can access this diagnostic
requireAdmin();
if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    die('Access denied — super_admin only');
}

$db = Database::getInstance();

// Get all users with their roles
$users = $db->fetchAll("SELECT au.id, au.name, au.username, au.is_active, ar.role_name, ar.permissions as role_permissions, au.custom_permissions 
    FROM admin_users au LEFT JOIN admin_roles ar ON ar.id = au.role_id ORDER BY au.id");

// Get permission version
$authVersion = defined('PERM_SYSTEM_VERSION') ? PERM_SYSTEM_VERSION : 'NOT SET (old code!)';

// All sidebar pages to test
$sidebarPages = [
    'ALWAYS' => ['dashboard','search','profile','notifications'],
    'SALES' => ['order-management','order-add','incomplete-orders','returns','scan-to-update','customers','coupons','visitors'],
    'CATALOG' => ['products','product-form','categories','inventory-dashboard','stock-decrease-new','stock-decrease-list','stock-increase-new','stock-increase-list','stock-transfer','smart-restock','media'],
    'SHIPPING' => ['courier'],
    'MARKETING' => ['meta-ads-report','meta-ads-campaigns'],
    'FINANCE' => ['accounting','income','liabilities','expenses','expense-new','ad-expense-new','report-profit-sales','report-employees','report-orders','report-products','report-web-orders','report-meta-ads','report-business'],
    'CONTENT' => ['page-builder','shop-design','checkout-fields','progress-bars','landing-pages','banners','cms-pages','blog'],
    'SUPPORT' => ['live-chat','chat-settings','call-center'],
    'TEAM' => ['employees','tasks'],
    'SYSTEM' => ['settings','select-invoice','select-sticker','speed','api-health','security','reset-test-data'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission System Diagnostic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body{font-family:Inter,sans-serif}.cell{padding:6px 10px;border:1px solid #e5e7eb;font-size:12px}</style>
</head>
<body class="bg-gray-50 p-6">
<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800">🔐 Permission System Diagnostic</h1>
        <a href="<?= adminUrl('pages/dashboard.php') ?>" class="text-sm bg-blue-600 text-white px-4 py-2 rounded-lg">← Dashboard</a>
    </div>

    <!-- System Info -->
    <div class="bg-white rounded-xl border p-5 mb-6">
        <h2 class="font-bold text-lg mb-3">System Info</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="text-gray-500">Permission Version:</span>
                <span class="font-bold <?= $authVersion === 'NOT SET (old code!)' ? 'text-red-600' : 'text-green-600' ?>"><?= $authVersion ?></span>
            </div>
            <div>
                <span class="text-gray-500">Your Role:</span>
                <span class="font-bold"><?= $_SESSION['admin_role'] ?? 'unknown' ?></span>
            </div>
            <div>
                <span class="text-gray-500">Session Perms:</span>
                <span class="font-bold"><?= count($_SESSION['admin_permissions'] ?? []) ?> items</span>
            </div>
            <div>
                <span class="text-gray-500">PHP Version:</span>
                <span class="font-bold"><?= phpversion() ?></span>
            </div>
        </div>
        <div class="mt-3 p-3 bg-gray-50 rounded-lg text-xs font-mono break-all">
            <strong>Session permissions:</strong> <?= htmlspecialchars(json_encode($_SESSION['admin_permissions'] ?? [])) ?>
        </div>
    </div>

    <!-- Per-User Permission Matrix -->
    <?php foreach ($users as $user): 
        $rolePerms = json_decode($user['role_permissions'] ?? '[]', true) ?: [];
        $customPerms = json_decode($user['custom_permissions'] ?? '[]', true) ?: [];
        $mergedPerms = array_values(array_unique(array_merge($rolePerms, $customPerms)));
        $isAll = in_array('all', $mergedPerms);
        $isSuperAdmin = $user['role_name'] === 'super_admin';
    ?>
    <div class="bg-white rounded-xl border p-5 mb-6 <?= !$user['is_active'] ? 'opacity-50' : '' ?>">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center text-blue-700 font-bold"><?= strtoupper(substr($user['name'],0,1)) ?></div>
            <div>
                <h3 class="font-bold text-gray-800"><?= htmlspecialchars($user['name']) ?> <span class="text-xs text-gray-400">(<?= htmlspecialchars($user['username']) ?>)</span></h3>
                <p class="text-xs text-gray-500">Role: <strong><?= htmlspecialchars($user['role_name'] ?? 'none') ?></strong> · Merged perms: <strong><?= count($mergedPerms) ?></strong> · Active: <?= $user['is_active'] ? '✅' : '❌' ?></p>
            </div>
        </div>
        
        <div class="text-xs mb-2 p-2 bg-gray-50 rounded font-mono break-all">
            <strong>Role:</strong> <?= htmlspecialchars(json_encode($rolePerms)) ?>
            <?php if (!empty($customPerms)): ?><br><strong>Custom:</strong> <?= htmlspecialchars(json_encode($customPerms)) ?><?php endif; ?>
        </div>
        
        <?php if (!$isSuperAdmin): ?>
        <table class="w-full border-collapse">
            <tr class="bg-gray-100">
                <td class="cell font-bold">Section</td>
                <td class="cell font-bold">Page</td>
                <td class="cell font-bold">Module</td>
                <td class="cell font-bold">Has Permission?</td>
            </tr>
            <?php foreach ($sidebarPages as $section => $pages): 
                $first = true;
                foreach ($pages as $page):
                    $module = getPagePermission($page);
                    $hasPerm = ($module === null) || $isAll;
                    if (!$hasPerm) {
                        foreach ($mergedPerms as $p) {
                            if ($p === $module || strpos($p, $module . '.') === 0) { $hasPerm = true; break; }
                        }
                    }
            ?>
            <tr>
                <td class="cell text-gray-500"><?= $first ? '<strong>'.$section.'</strong>' : '' ?></td>
                <td class="cell"><?= $page ?></td>
                <td class="cell font-mono text-gray-600"><?= $module ?? '<span class="text-green-600">null (always)</span>' ?></td>
                <td class="cell <?= $hasPerm ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' ?>"><?= $hasPerm ? '✅ YES' : '❌ NO' ?></td>
            </tr>
            <?php $first = false; endforeach; endforeach; ?>
        </table>
        <?php else: ?>
        <p class="text-sm text-green-600 font-semibold">🔓 Super Admin — full access to all pages</p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
</body>
</html>
