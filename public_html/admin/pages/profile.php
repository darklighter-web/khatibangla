<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();
$adminId = getAdminId();
$admin = $db->fetch("SELECT au.*, ar.role_name FROM admin_users au JOIN admin_roles ar ON ar.id = au.role_id WHERE au.id = ?", [$adminId]);

// ── View-as-Role Actions (super admin only) ──
$getAction = $_GET['action'] ?? '';
if ($getAction === 'view_as' && isSuperAdmin()) {
    $roleId = intval($_GET['role_id'] ?? 0);
    if ($roleId > 0) $_SESSION['view_as_role_id'] = $roleId;
    $ref = $_SERVER['HTTP_REFERER'] ?? adminUrl('pages/dashboard.php');
    header("Location: $ref"); exit;
}
if ($getAction === 'exit_view_as') {
    unset($_SESSION['view_as_role_id'], $_SESSION['_real_permissions']);
    $ref = $_SERVER['HTTP_REFERER'] ?? adminUrl('pages/dashboard.php');
    header("Location: $ref"); exit;
}

// ── POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
        $db->update('admin_users', [
            'full_name' => sanitize($_POST['full_name']),
            'email' => sanitize($_POST['email']),
            'phone' => sanitize($_POST['phone']),
        ], 'id = ?', [$adminId]);
        $_SESSION['admin_name'] = sanitize($_POST['full_name']);
        redirect(adminUrl('pages/profile.php?msg=updated'));
    }
    if ($action === 'change_password') {
        if (!verifyPassword($_POST['current_password'], $admin['password'])) redirect(adminUrl('pages/profile.php?msg=wrong_password'));
        if ($_POST['new_password'] !== $_POST['confirm_password']) redirect(adminUrl('pages/profile.php?msg=mismatch'));
        if (strlen($_POST['new_password']) < 6) redirect(adminUrl('pages/profile.php?msg=short'));
        $db->update('admin_users', ['password' => hashPassword($_POST['new_password'])], 'id = ?', [$adminId]);
        try { logActivity($adminId, 'password_change', 'admin_users', $adminId); } catch (\Throwable $e) {}
        redirect(adminUrl('pages/profile.php?msg=password_changed'));
    }
    if ($action === 'save_theme') {
        $theme = in_array($_POST['admin_theme'] ?? '', ['light','dark']) ? $_POST['admin_theme'] : 'light';
        try {
            $db->query("UPDATE admin_users SET admin_theme = ? WHERE id = ?", [$theme, $adminId]);
        } catch (\Throwable $e) {
            try { $db->query("ALTER TABLE admin_users ADD COLUMN admin_theme VARCHAR(20) DEFAULT 'light'"); } catch (\Throwable $e2) {}
            $db->query("UPDATE admin_users SET admin_theme = ? WHERE id = ?", [$theme, $adminId]);
        }
        redirect(adminUrl('pages/profile.php?msg=theme_saved'));
    }
}

$admin = $db->fetch("SELECT au.*, ar.role_name FROM admin_users au JOIN admin_roles ar ON ar.id = au.role_id WHERE au.id = ?", [$adminId]);
$currentTheme = $admin['admin_theme'] ?? 'light';
$allRoles = [];
if (isSuperAdmin()) { try { $allRoles = $db->fetchAll("SELECT * FROM admin_roles ORDER BY id"); } catch (\Throwable $e) {} }
$activities = [];
try { $activities = $db->fetchAll("SELECT * FROM activity_logs WHERE admin_user_id = ? ORDER BY created_at DESC LIMIT 20", [$adminId]); } catch (\Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
$msg = $_GET['msg'] ?? '';
$msgMap = [
    'updated' => ['bg-green-50 border-green-200 text-green-700', '<i class="fas fa-check-circle mr-1"></i>Profile updated.'],
    'password_changed' => ['bg-green-50 border-green-200 text-green-700', '<i class="fas fa-check-circle mr-1"></i>Password changed.'],
    'theme_saved' => ['bg-green-50 border-green-200 text-green-700', '<i class="fas fa-check-circle mr-1"></i>Theme preference saved.'],
    'wrong_password' => ['bg-red-50 border-red-200 text-red-700', '<i class="fas fa-times-circle mr-1"></i>Current password is incorrect.'],
    'mismatch' => ['bg-red-50 border-red-200 text-red-700', '<i class="fas fa-times-circle mr-1"></i>New passwords do not match.'],
    'short' => ['bg-red-50 border-red-200 text-red-700', '<i class="fas fa-times-circle mr-1"></i>Password must be at least 6 characters.'],
];
?>

<?php if ($msg && isset($msgMap[$msg])): ?>
<div class="mb-4 p-3 <?= $msgMap[$msg][0] ?> border rounded-xl text-sm"><?= $msgMap[$msg][1] ?></div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Profile Card -->
    <div class="lg:col-span-1 space-y-4">
        <div class="bg-white rounded-xl border shadow-sm p-6 text-center">
            <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold text-3xl mx-auto mb-4">
                <?= strtoupper(substr($admin['full_name'], 0, 1)) ?>
            </div>
            <h3 class="font-semibold text-xl"><?= e($admin['full_name']) ?></h3>
            <p class="text-sm text-gray-500">@<?= e($admin['username']) ?></p>
            <span class="inline-block mt-2 bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-medium"><?= e($admin['role_name']) ?></span>
            <div class="mt-4 text-left space-y-2 text-sm">
                <p class="flex items-center gap-2 text-gray-600"><i class="fas fa-envelope text-gray-400 w-4"></i><?= e($admin['email']) ?></p>
                <?php if ($admin['phone']): ?><p class="flex items-center gap-2 text-gray-600"><i class="fas fa-phone text-gray-400 w-4"></i><?= e($admin['phone']) ?></p><?php endif; ?>
                <p class="text-xs text-gray-400 mt-3">Last login: <?= $admin['last_login'] ? date('d M Y H:i', strtotime($admin['last_login'])) : 'N/A' ?></p>
            </div>
        </div>

        <!-- View-as-Role (Super Admin Only) -->
        <?php if (isSuperAdmin() && !empty($allRoles)): ?>
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-1 flex items-center gap-2"><i class="fas fa-eye text-purple-500"></i>View as Role</h3>
            <p class="text-xs text-gray-400 mb-4">Preview the admin panel as a specific role sees it.</p>
            <div class="space-y-2">
                <?php
                $currentViewRole = $_SESSION['view_as_role_id'] ?? null;
                foreach ($allRoles as $r):
                    $isActive = ($currentViewRole && $currentViewRole == $r['id']);
                    $perms = json_decode($r['permissions'], true) ?: [];
                    $permCount = in_array('all', $perms) ? 'Full access' : count($perms) . ' permissions';
                ?>
                <div class="flex items-center justify-between p-3 rounded-lg border <?= $isActive ? 'border-purple-300 bg-purple-50' : 'border-gray-200 hover:bg-gray-50' ?> transition">
                    <div>
                        <p class="text-sm font-semibold <?= $isActive ? 'text-purple-700' : 'text-gray-800' ?>"><?= e($r['role_name']) ?></p>
                        <p class="text-xs text-gray-400"><?= $permCount ?></p>
                    </div>
                    <?php if ($isActive): ?>
                    <a href="<?= adminUrl('pages/profile.php?action=exit_view_as') ?>" class="px-3 py-1.5 bg-amber-500 text-white rounded-lg text-xs font-semibold hover:bg-amber-600"><i class="fas fa-sign-out-alt mr-1"></i>Exit</a>
                    <?php else: ?>
                    <a href="<?= adminUrl('pages/profile.php?action=view_as&role_id=' . $r['id']) ?>" class="px-3 py-1.5 bg-purple-600 text-white rounded-lg text-xs font-semibold hover:bg-purple-700"><i class="fas fa-eye mr-1"></i>View</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Edit Forms -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Theme Selector (Super Admin) -->
        <?php if (isSuperAdmin()): ?>
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-1 flex items-center gap-2"><i class="fas fa-palette text-indigo-500"></i>Admin Panel Theme</h3>
            <p class="text-xs text-gray-400 mb-4">Choose your preferred appearance for the admin dashboard.</p>
            <form method="POST">
                <input type="hidden" name="action" value="save_theme">
                <div class="grid grid-cols-2 gap-4 max-w-lg">
                    <!-- Light -->
                    <label class="relative cursor-pointer group">
                        <input type="radio" name="admin_theme" value="light" <?= $currentTheme === 'light' ? 'checked' : '' ?> class="sr-only peer" onchange="this.form.querySelector('.theme-check-light').classList.toggle('hidden', !this.checked); this.form.querySelector('.theme-check-dark').classList.toggle('hidden', true);">
                        <div class="border-2 rounded-xl p-4 transition border-gray-200 peer-checked:border-blue-500 peer-checked:shadow-md hover:border-gray-300">
                            <div class="flex items-center gap-2 mb-3">
                                <div class="w-8 h-8 rounded-lg bg-gradient-to-b from-blue-700 to-blue-900 flex items-center justify-center"><i class="fas fa-sun text-white text-xs"></i></div>
                                <span class="font-semibold text-sm">Light</span>
                            </div>
                            <div class="rounded-lg overflow-hidden border bg-gray-50 h-20">
                                <div class="flex h-full">
                                    <div class="w-8 bg-gradient-to-b from-blue-800 to-blue-900"></div>
                                    <div class="flex-1 p-1.5"><div class="h-2 bg-white rounded mb-1 border"></div><div class="flex gap-1"><div class="flex-1 h-6 bg-white rounded border"></div><div class="flex-1 h-6 bg-white rounded border"></div></div></div>
                                </div>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-2 text-center">Default · Clean & bright</p>
                        </div>
                        <div class="theme-check-light absolute top-3 right-3 w-5 h-5 rounded-full bg-blue-500 flex items-center justify-center <?= $currentTheme === 'light' ? '' : 'hidden' ?>"><i class="fas fa-check text-white text-[8px]"></i></div>
                    </label>
                    <!-- Dark -->
                    <label class="relative cursor-pointer group">
                        <input type="radio" name="admin_theme" value="dark" <?= $currentTheme === 'dark' ? 'checked' : '' ?> class="sr-only peer" onchange="this.form.querySelector('.theme-check-dark').classList.toggle('hidden', !this.checked); this.form.querySelector('.theme-check-light').classList.toggle('hidden', true);">
                        <div class="border-2 rounded-xl p-4 transition border-gray-200 peer-checked:border-blue-500 peer-checked:shadow-md hover:border-gray-300">
                            <div class="flex items-center gap-2 mb-3">
                                <div class="w-8 h-8 rounded-lg bg-gradient-to-b from-gray-700 to-gray-900 flex items-center justify-center"><i class="fas fa-moon text-yellow-300 text-xs"></i></div>
                                <span class="font-semibold text-sm">Dark</span>
                            </div>
                            <div class="rounded-lg overflow-hidden border border-gray-600 bg-gray-900 h-20">
                                <div class="flex h-full">
                                    <div class="w-8 bg-gradient-to-b from-gray-800 to-gray-900"></div>
                                    <div class="flex-1 p-1.5"><div class="h-2 bg-gray-800 rounded mb-1"></div><div class="flex gap-1"><div class="flex-1 h-6 bg-gray-800 rounded"></div><div class="flex-1 h-6 bg-gray-800 rounded"></div></div></div>
                                </div>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-2 text-center">Easy on the eyes</p>
                        </div>
                        <div class="theme-check-dark absolute top-3 right-3 w-5 h-5 rounded-full bg-blue-500 flex items-center justify-center <?= $currentTheme === 'dark' ? '' : 'hidden' ?>"><i class="fas fa-check text-white text-[8px]"></i></div>
                    </label>
                </div>
                <button class="mt-4 bg-indigo-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-indigo-700"><i class="fas fa-save mr-1"></i>Save Theme</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Update Profile -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><i class="fas fa-user-edit mr-2 text-blue-500"></i>Edit Profile</h3>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update_profile">
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium mb-1">Full Name</label><input type="text" name="full_name" value="<?= e($admin['full_name']) ?>" required class="border rounded-lg px-3 py-2 text-sm w-full"></div>
                    <div><label class="block text-sm font-medium mb-1">Username</label><input type="text" value="<?= e($admin['username']) ?>" disabled class="border rounded-lg px-3 py-2 text-sm w-full bg-gray-50 text-gray-400"></div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium mb-1">Email</label><input type="email" name="email" value="<?= e($admin['email']) ?>" required class="border rounded-lg px-3 py-2 text-sm w-full"></div>
                    <div><label class="block text-sm font-medium mb-1">Phone</label><input type="text" name="phone" value="<?= e($admin['phone'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm w-full"></div>
                </div>
                <button class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700"><i class="fas fa-save mr-1"></i>Save Changes</button>
            </form>
        </div>

        <!-- Change Password -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><i class="fas fa-lock mr-2 text-red-500"></i>Change Password</h3>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="change_password">
                <div><label class="block text-sm font-medium mb-1">Current Password</label><input type="password" name="current_password" required class="border rounded-lg px-3 py-2 text-sm w-full max-w-sm"></div>
                <div class="grid grid-cols-2 gap-4 max-w-lg">
                    <div><label class="block text-sm font-medium mb-1">New Password</label><input type="password" name="new_password" required minlength="6" class="border rounded-lg px-3 py-2 text-sm w-full"></div>
                    <div><label class="block text-sm font-medium mb-1">Confirm Password</label><input type="password" name="confirm_password" required minlength="6" class="border rounded-lg px-3 py-2 text-sm w-full"></div>
                </div>
                <button class="bg-red-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-red-700"><i class="fas fa-key mr-1"></i>Change Password</button>
            </form>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><i class="fas fa-history mr-2 text-gray-400"></i>Recent Activity</h3>
            <div class="space-y-3 max-h-64 overflow-y-auto" style="scrollbar-width:thin">
                <?php foreach ($activities as $act): ?>
                <div class="flex items-center gap-3 text-sm">
                    <div class="w-2 h-2 rounded-full bg-blue-400 flex-shrink-0"></div>
                    <span class="text-gray-600"><?= e($act['action']) ?> <?= $act['entity_type'] ? e($act['entity_type']) : '' ?> <?= $act['entity_id'] ? '#'.$act['entity_id'] : '' ?></span>
                    <span class="text-gray-400 text-xs ml-auto"><?= date('d M H:i', strtotime($act['created_at'])) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($activities)): ?><p class="text-gray-400 text-sm">No recent activity</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
