<?php
require_once __DIR__ . '/../../includes/session.php';
/**
 * Admin - Employee & Role Management with Granular Permissions
 */
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Team Management';
$db = Database::getInstance();

// ─── Permission Modules Definition ───
// These must match the module names in auth.php getPagePermission()
$permissionModules = [
    'orders' => ['label' => 'Orders', 'icon' => 'fas fa-shopping-cart', 'color' => 'green', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'status_update']],
    'products' => ['label' => 'Products', 'icon' => 'fas fa-box', 'color' => 'purple', 'actions' => ['view', 'create', 'edit', 'delete']],
    'categories' => ['label' => 'Categories', 'icon' => 'fas fa-folder', 'color' => 'yellow', 'actions' => ['view', 'create', 'edit', 'delete']],
    'inventory' => ['label' => 'Inventory', 'icon' => 'fas fa-warehouse', 'color' => 'teal', 'actions' => ['view', 'edit']],
    'customers' => ['label' => 'Customers', 'icon' => 'fas fa-users', 'color' => 'indigo', 'actions' => ['view', 'edit', 'block']],
    'coupons' => ['label' => 'Coupons', 'icon' => 'fas fa-ticket-alt', 'color' => 'pink', 'actions' => ['view', 'create', 'edit', 'delete']],
    'courier' => ['label' => 'Courier / Delivery', 'icon' => 'fas fa-truck', 'color' => 'cyan', 'actions' => ['view', 'manage']],
    'returns' => ['label' => 'Returns', 'icon' => 'fas fa-undo', 'color' => 'red', 'actions' => ['view', 'process']],
    'accounting' => ['label' => 'Accounting & Income', 'icon' => 'fas fa-calculator', 'color' => 'violet', 'actions' => ['view', 'create', 'edit']],
    'expenses' => ['label' => 'Expenses', 'icon' => 'fas fa-receipt', 'color' => 'amber', 'actions' => ['view', 'create', 'edit', 'delete']],
    'reports' => ['label' => 'Reports & Analytics', 'icon' => 'fas fa-chart-bar', 'color' => 'emerald', 'actions' => ['view', 'export']],
    'marketing' => ['label' => 'Marketing / Meta Ads', 'icon' => 'fas fa-bullhorn', 'color' => 'fuchsia', 'actions' => ['view', 'edit']],
    'banners' => ['label' => 'Banners', 'icon' => 'fas fa-image', 'color' => 'orange', 'actions' => ['view', 'create', 'edit', 'delete']],
    'cms_pages' => ['label' => 'CMS / Landing Pages / Blog', 'icon' => 'fas fa-file-alt', 'color' => 'slate', 'actions' => ['view', 'create', 'edit', 'delete']],
    'employees' => ['label' => 'Team Management', 'icon' => 'fas fa-user-shield', 'color' => 'rose', 'actions' => ['view', 'create', 'edit', 'delete']],
    'tasks' => ['label' => 'Tasks & Follow-up', 'icon' => 'fas fa-tasks', 'color' => 'lime', 'actions' => ['view', 'create', 'edit', 'delete']],
    'settings' => ['label' => 'Settings & System', 'icon' => 'fas fa-cog', 'color' => 'gray', 'actions' => ['view', 'edit']],
];

$actionLabels = [
    'view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete',
    'print' => 'Print', 'status_update' => 'Update Status', 'block' => 'Block/Unblock',
    'process' => 'Process', 'manage' => 'Manage', 'export' => 'Export',
];

// ─── Handle POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ── Save Employee ──
    if ($action === 'save_employee') {
        if (!isSuperAdmin()) {
            redirect(adminUrl('pages/employees.php?msg=no_permission'));
        }
        $id = (int)($_POST['id'] ?? 0);
        $username = sanitize($_POST['username'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $fullName = sanitize($_POST['full_name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 2);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        // Collect permissions
        $customPerms = [];
        if (!empty($_POST['permissions']) && is_array($_POST['permissions'])) {
            $customPerms = $_POST['permissions'];
        }
        $permsJson = json_encode($customPerms);
        
        // Handle avatar
        $avatar = null;
        if (!empty($_FILES['avatar']['name'])) {
            $avatar = uploadFile($_FILES['avatar'], 'avatars');
        }
        
        // Duplicate check for new users
        if ($id === 0) {
            $exists = $db->fetch("SELECT id FROM admin_users WHERE username=? OR email=?", [$username, $email]);
            if ($exists) {
                redirect(adminUrl('pages/employees.php?tab=add&msg=duplicate'));
            }
        }
        
        if ($id > 0) {
            $sql = "UPDATE admin_users SET username=?, email=?, full_name=?, phone=?, role_id=?, is_active=?, custom_permissions=?";
            $params = [$username, $email, $fullName, $phone, $roleId, $isActive, $permsJson];
            if (!empty($_POST['password'])) {
                $sql .= ", password=?";
                $params[] = hashPassword($_POST['password']);
            }
            if ($avatar) {
                $sql .= ", avatar=?";
                $params[] = $avatar;
            }
            $sql .= " WHERE id=?";
            $params[] = $id;
            $db->query($sql, $params);
        } else {
            if (empty($_POST['password'])) {
                redirect(adminUrl('pages/employees.php?tab=add&msg=password_required'));
            }
            $db->query("INSERT INTO admin_users (username, email, full_name, phone, role_id, is_active, password, avatar, custom_permissions) VALUES (?,?,?,?,?,?,?,?,?)", [
                $username, $email, $fullName, $phone, $roleId, $isActive,
                hashPassword($_POST['password']), $avatar, $permsJson
            ]);
        }
        logActivity(getAdminId(), 'employee_saved', 'admin_users', $id);
        redirect(adminUrl('pages/employees.php?tab=list&msg=saved'));
    }
    
    // ── Save Role ──
    if ($action === 'save_role') {
        if (!isSuperAdmin()) {
            redirect(adminUrl('pages/employees.php?tab=roles&msg=no_permission'));
        }
        $roleId = (int)($_POST['role_id'] ?? 0);
        $roleName = sanitize($_POST['role_name'] ?? '');
        $rolePerms = [];
        if (!empty($_POST['role_permissions']) && is_array($_POST['role_permissions'])) {
            $rolePerms = $_POST['role_permissions'];
        }
        $rolePermsJson = json_encode($rolePerms);
        
        if ($roleId > 0) {
            $db->query("UPDATE admin_roles SET role_name=?, permissions=? WHERE id=?", [$roleName, $rolePermsJson, $roleId]);
        } else {
            $db->query("INSERT INTO admin_roles (role_name, permissions) VALUES (?,?)", [$roleName, $rolePermsJson]);
        }
        logActivity(getAdminId(), 'role_saved', 'admin_roles', $roleId);
        redirect(adminUrl('pages/employees.php?tab=roles&msg=role_saved'));
    }
    
    // ── Delete Role ──
    if ($action === 'delete_role') {
        if (!isSuperAdmin()) {
            redirect(adminUrl('pages/employees.php?tab=roles&msg=no_permission'));
        }
        $roleId = (int)($_POST['role_id'] ?? 0);
        if ($roleId > 1) {
            $usersInRole = $db->count('admin_users', 'role_id = ?', [$roleId]);
            if ($usersInRole === 0) {
                $db->delete('admin_roles', 'id = ?', [$roleId]);
                redirect(adminUrl('pages/employees.php?tab=roles&msg=role_deleted'));
            } else {
                redirect(adminUrl('pages/employees.php?tab=roles&msg=role_in_use'));
            }
        }
        redirect(adminUrl('pages/employees.php?tab=roles'));
    }
    
    // ── Toggle Active ──
    if ($action === 'toggle_active') {
        if (!isSuperAdmin()) {
            redirect(adminUrl('pages/employees.php?msg=no_permission'));
        }
        $id = (int)$_POST['id'];
        if ($id != getAdminId()) {
            $db->query("UPDATE admin_users SET is_active = NOT is_active WHERE id=?", [$id]);
        }
        redirect(adminUrl('pages/employees.php?msg=updated'));
    }
    
    // ── Delete Employee ──
    if ($action === 'delete_employee') {
        if (!isSuperAdmin()) {
            redirect(adminUrl('pages/employees.php?msg=no_permission'));
        }
        $id = (int)$_POST['id'];
        if ($id != getAdminId() && $id != 1) {
            // Nullify/delete all foreign key references before deleting
            try { $db->query("UPDATE activity_logs SET admin_user_id = NULL WHERE admin_user_id = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("DELETE FROM employee_performance WHERE admin_user_id = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("UPDATE orders SET changed_by = NULL WHERE changed_by = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("UPDATE tasks SET assigned_to = NULL WHERE assigned_to = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("UPDATE tasks SET assigned_by = NULL WHERE assigned_by = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("UPDATE blocked_ips SET blocked_by = NULL WHERE blocked_by = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("UPDATE blocked_phones SET blocked_by = NULL WHERE blocked_by = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("UPDATE courier_shipments SET processed_by = NULL WHERE processed_by = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("UPDATE stock_movements SET created_by = NULL WHERE created_by = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("UPDATE expenses SET created_by = NULL WHERE created_by = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("UPDATE notifications SET user_id = NULL WHERE user_id = ?", [$id]); } catch (\Throwable $e) {}
            try { $db->query("UPDATE print_queue SET printed_by = NULL WHERE printed_by = ?", [$id]); } catch (\Throwable $e) {}
            $db->query("DELETE FROM admin_users WHERE id=?", [$id]);
        }
        redirect(adminUrl('pages/employees.php?msg=deleted'));
    }
    
    // ── Log Performance ──
    if ($action === 'log_performance') {
        $db->query("INSERT INTO employee_performance (admin_user_id, period_date, orders_processed, orders_confirmed, orders_cancelled, calls_made, tasks_completed, performance_score, notes) VALUES (?,?,?,?,?,?,?,?,?)", [
            (int)$_POST['admin_user_id'], $_POST['period_date'],
            (int)$_POST['orders_processed'], (int)$_POST['orders_confirmed'],
            (int)$_POST['orders_cancelled'], (int)$_POST['calls_made'],
            (int)$_POST['tasks_completed'], (float)$_POST['performance_score'],
            sanitize($_POST['notes'] ?? '')
        ]);
        redirect(adminUrl('pages/employees.php?tab=performance&msg=logged'));
    }
}

// ─── Data Loading ───
$msg = $_GET['msg'] ?? '';
$tab = $_GET['tab'] ?? 'list';
$roles = $db->fetchAll("SELECT * FROM admin_roles ORDER BY id");
$employees = $db->fetchAll("SELECT au.*, ar.role_name, ar.permissions as role_permissions,
    (SELECT COUNT(*) FROM orders WHERE assigned_to=au.id) as orders_processed,
    (SELECT COUNT(*) FROM tasks WHERE assigned_to=au.id AND status='completed') as tasks_done
    FROM admin_users au LEFT JOIN admin_roles ar ON au.role_id=ar.id 
    ORDER BY au.is_active DESC, au.created_at ASC");

$editEmp = null; $editEmpPerms = [];
if (isset($_GET['edit'])) {
    $editEmp = $db->fetch("SELECT * FROM admin_users WHERE id=?", [(int)$_GET['edit']]);
    if ($editEmp) {
        $editEmpPerms = json_decode($editEmp['custom_permissions'] ?? '[]', true) ?: [];
        $tab = 'add';
    }
}

$editRole = null; $editRolePerms = [];
if (isset($_GET['edit_role'])) {
    $editRole = $db->fetch("SELECT * FROM admin_roles WHERE id=?", [(int)$_GET['edit_role']]);
    if ($editRole) {
        $editRolePerms = json_decode($editRole['permissions'] ?? '[]', true) ?: [];
        $tab = 'roles';
    }
}

$performances = [];
if ($tab === 'performance') {
    $performances = $db->fetchAll("SELECT ep.*, au.full_name FROM employee_performance ep 
        JOIN admin_users au ON ep.admin_user_id=au.id ORDER BY ep.period_date DESC LIMIT 50");
}

$rolesPermsMap = [];
foreach ($roles as $r) { $rolesPermsMap[$r['id']] = json_decode($r['permissions'] ?? '[]', true) ?: []; }

include __DIR__ . '/../includes/header.php';
?>

<style>
.perm-card{transition:all .2s}.perm-card:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.08)}
.perm-chip{transition:all .15s;cursor:pointer;user-select:none}
.perm-chip:has(input:checked){background:#3b82f6;color:#fff;border-color:#3b82f6}
.perm-chip:has(input:checked) .chip-check{display:inline}.perm-chip .chip-check{display:none}
.role-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:9999px;font-size:.75rem;font-weight:600}
.tab-btn{transition:all .2s}.tab-btn.active{border-color:#3b82f6;color:#3b82f6;background:#eff6ff}
</style>

<?php 
$messages = [
    'saved' => ['✅ Employee saved successfully!', 'green'],
    'updated' => ['✅ Employee updated!', 'green'],
    'deleted' => ['✅ Employee deleted!', 'green'],
    'role_saved' => ['✅ Role saved successfully!', 'green'],
    'role_deleted' => ['✅ Role deleted!', 'green'],
    'role_in_use' => ['⚠️ Cannot delete — employees are assigned to this role.', 'red'],
    'duplicate' => ['⚠️ Username or email already exists.', 'red'],
    'password_required' => ['⚠️ Password is required for new employees.', 'red'],
    'logged' => ['✅ Performance logged!', 'green'],
    'no_permission' => ['🚫 Only Super Admin can create, edit or delete accounts.', 'red'],
];
if ($msg && isset($messages[$msg])): $mc = $messages[$msg][1]; ?>
<div class="mb-4 p-3 bg-<?=$mc?>-50 text-<?=$mc?>-700 border border-<?=$mc?>-200 rounded-xl text-sm flex items-center justify-between">
    <span><?=$messages[$msg][0]?></span>
    <button onclick="this.parentElement.remove()" class="text-<?=$mc?>-400 hover:text-<?=$mc?>-600 text-lg">&times;</button>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="flex flex-wrap gap-1 mb-6 border-b">
    <a href="?tab=list" class="tab-btn px-4 py-2.5 text-sm font-medium border-b-2 rounded-t-lg <?=$tab==='list'?'active':'border-transparent text-gray-500 hover:text-gray-700'?>">
        <i class="fas fa-users mr-1.5"></i>All Members <span class="ml-1 bg-gray-200 text-gray-600 text-xs px-1.5 py-0.5 rounded-full"><?=count($employees)?></span>
    </a>
    <?php if (isSuperAdmin()): ?>
    <a href="?tab=add" class="tab-btn px-4 py-2.5 text-sm font-medium border-b-2 rounded-t-lg <?=$tab==='add'?'active':'border-transparent text-gray-500 hover:text-gray-700'?>">
        <i class="fas fa-user-plus mr-1.5"></i><?=$editEmp?'Edit Member':'Add Member'?>
    </a>
    <a href="?tab=roles" class="tab-btn px-4 py-2.5 text-sm font-medium border-b-2 rounded-t-lg <?=$tab==='roles'?'active':'border-transparent text-gray-500 hover:text-gray-700'?>">
        <i class="fas fa-shield-alt mr-1.5"></i>Roles & Permissions
    </a>
    <?php endif; ?>
    <a href="?tab=performance" class="tab-btn px-4 py-2.5 text-sm font-medium border-b-2 rounded-t-lg <?=$tab==='performance'?'active':'border-transparent text-gray-500 hover:text-gray-700'?>">
        <i class="fas fa-chart-line mr-1.5"></i>Performance
    </a>
    <a href="?tab=salary" class="tab-btn px-4 py-2.5 text-sm font-medium border-b-2 rounded-t-lg <?=$tab==='salary'?'active':'border-transparent text-gray-500 hover:text-gray-700'?>">
        <i class="fas fa-money-bill-wave mr-1.5"></i>Salary
    </a>
    <a href="?tab=attendance" class="tab-btn px-4 py-2.5 text-sm font-medium border-b-2 rounded-t-lg <?=$tab==='attendance'?'active':'border-transparent text-gray-500 hover:text-gray-700'?>">
        <i class="fas fa-calendar-check mr-1.5"></i>Attendance
    </a>
</div>


<?php // ═══════ TAB: EMPLOYEE LIST ═══════
if ($tab === 'list'): ?>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
<?php foreach ($employees as $emp): 
    $roleColor = match(strtolower($emp['role_name'] ?? '')) {
        'super_admin' => 'red', 'admin' => 'blue', 'manager' => 'purple', default => 'gray',
    };
    $empPerms = json_decode($emp['custom_permissions'] ?? '[]', true) ?: [];
    $rolePerms = json_decode($emp['role_permissions'] ?? '[]', true) ?: [];
    $allPerms = !empty($empPerms) ? $empPerms : $rolePerms;
    $isSuperAdmin = in_array('all', $allPerms);
?>
<div class="perm-card bg-white rounded-xl border shadow-sm <?=!$emp['is_active']?'opacity-50 grayscale':''?>">
    <div class="p-5">
        <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-lg shadow-md">
                    <?php if (!empty($emp['avatar'])): ?>
                    <img src="<?=uploadUrl($emp['avatar'])?>" class="w-12 h-12 rounded-full object-cover">
                    <?php else: echo strtoupper(mb_substr($emp['full_name'],0,1)); endif; ?>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-800"><?=e($emp['full_name'])?></h4>
                    <p class="text-xs text-gray-400">@<?=e($emp['username'])?></p>
                </div>
            </div>
            <div class="flex flex-col items-end gap-1">
                <span class="role-badge bg-<?=$roleColor?>-100 text-<?=$roleColor?>-700">
                    <i class="fas fa-shield-alt text-[10px]"></i>
                    <?=ucwords(str_replace('_',' ',$emp['role_name']??'Staff'))?>
                </span>
                <span class="text-[10px] px-2 py-0.5 rounded-full <?=$emp['is_active']?'bg-green-100 text-green-600':'bg-red-100 text-red-600'?>">
                    <?=$emp['is_active']?'● Active':'● Inactive'?>
                </span>
            </div>
        </div>
        
        <div class="grid grid-cols-2 gap-2 text-xs mb-4">
            <?php if($emp['email']):?><div class="text-gray-500 truncate"><i class="fas fa-envelope mr-1"></i><?=e($emp['email'])?></div><?php endif;?>
            <?php if($emp['phone']):?><div class="text-gray-500"><i class="fas fa-phone mr-1"></i><?=e($emp['phone'])?></div><?php endif;?>
            <div class="text-gray-400"><i class="fas fa-clock mr-1"></i>Joined <?=date('M d, Y',strtotime($emp['created_at']))?></div>
            <?php if($emp['last_login']):?><div class="text-gray-400"><i class="fas fa-sign-in-alt mr-1"></i><?=date('M d, g:ia',strtotime($emp['last_login']))?></div><?php endif;?>
        </div>
        
        <!-- Permissions Preview -->
        <div class="mb-4">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Permissions</p>
            <?php if ($isSuperAdmin): ?>
                <span class="inline-flex items-center gap-1 text-xs bg-red-50 text-red-600 px-2 py-0.5 rounded-full"><i class="fas fa-crown text-[9px]"></i> Full Access</span>
            <?php else:
                $displayPerms = [];
                foreach ($allPerms as $p) { $mod = explode('.',$p)[0]; if(!in_array($mod,$displayPerms)&&isset($permissionModules[$mod])) $displayPerms[]=$mod; }
            ?>
            <div class="flex flex-wrap gap-1">
                <?php foreach(array_slice($displayPerms,0,6) as $dp):?><span class="text-[10px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded"><?=$permissionModules[$dp]['label']?></span><?php endforeach;?>
                <?php if(count($displayPerms)>6):?><span class="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">+<?=count($displayPerms)-6?> more</span><?php endif;?>
                <?php if(empty($displayPerms)):?><span class="text-[10px] text-gray-400 italic">Using role defaults</span><?php endif;?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="flex gap-4 text-center border-t pt-3 mb-1">
            <div class="flex-1"><p class="text-lg font-bold text-gray-800"><?=$emp['orders_processed']?></p><p class="text-[10px] text-gray-400">Orders</p></div>
            <div class="flex-1"><p class="text-lg font-bold text-gray-800"><?=$emp['tasks_done']?></p><p class="text-[10px] text-gray-400">Tasks</p></div>
        </div>
    </div>
    
    <?php if ($emp['id'] != getAdminId() && isSuperAdmin()): ?>
    <div class="flex border-t divide-x text-xs">
        <a href="?tab=add&edit=<?=$emp['id']?>" class="flex-1 py-2.5 text-center text-blue-600 hover:bg-blue-50 transition"><i class="fas fa-edit mr-1"></i>Edit</a>
        <form method="POST" class="flex-1"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?=$emp['id']?>">
            <button class="w-full py-2.5 text-center <?=$emp['is_active']?'text-yellow-600 hover:bg-yellow-50':'text-green-600 hover:bg-green-50'?> transition">
                <i class="fas fa-<?=$emp['is_active']?'ban':'check-circle'?> mr-1"></i><?=$emp['is_active']?'Disable':'Enable'?>
            </button>
        </form>
        <form method="POST" class="flex-1" onsubmit="return confirm('Permanently delete this employee?')"><input type="hidden" name="action" value="delete_employee"><input type="hidden" name="id" value="<?=$emp['id']?>">
            <button class="w-full py-2.5 text-center text-red-600 hover:bg-red-50 transition"><i class="fas fa-trash mr-1"></i>Delete</button>
        </form>
    </div>
    <?php else: ?>
    <div class="border-t px-5 py-2 text-center"><span class="text-xs text-gray-400 italic"><i class="fas fa-user-circle mr-1"></i><?=$emp['id']==getAdminId()?'Your account':'View only'?></span></div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- Add New Card -->
<?php if (isSuperAdmin()): ?>
<a href="?tab=add" class="perm-card flex items-center justify-center border-2 border-dashed border-gray-300 rounded-xl hover:border-blue-400 hover:bg-blue-50/50 transition min-h-[250px]">
    <div class="text-center">
        <div class="w-14 h-14 rounded-full bg-blue-100 flex items-center justify-center mx-auto mb-3"><i class="fas fa-plus text-blue-500 text-xl"></i></div>
        <p class="text-sm font-medium text-gray-600">Add New Member</p>
    </div>
</a>
<?php endif; ?>
</div>


<?php // ═══════ TAB: ADD / EDIT ═══════
elseif ($tab === 'add'): ?>
<?php if (!isSuperAdmin()) { redirect(adminUrl('pages/employees.php?msg=no_permission')); } ?>

<form method="POST" enctype="multipart/form-data" id="employeeForm">
<input type="hidden" name="action" value="save_employee">
<?php if($editEmp):?><input type="hidden" name="id" value="<?=$editEmp['id']?>"><?php endif;?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Left: Basic Info -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl border shadow-sm p-6 sticky top-4">
            <h3 class="text-lg font-semibold text-gray-800 mb-1">
                <?=$editEmp?'<i class="fas fa-user-edit mr-1.5 text-blue-500"></i>Edit':'<i class="fas fa-user-plus mr-1.5 text-green-500"></i>New'?> Team Member
            </h3>
            <p class="text-xs text-gray-400 mb-5">Fill in info and assign permissions on the right</p>
            
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Full Name *</label>
                        <input type="text" name="full_name" required value="<?=e($editEmp['full_name']??'')?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="John Doe">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Username *</label>
                        <input type="text" name="username" required value="<?=e($editEmp['username']??'')?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="johndoe">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Email *</label>
                    <input type="email" name="email" required value="<?=e($editEmp['email']??'')?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="john@example.com">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Phone</label>
                    <input type="text" name="phone" value="<?=e($editEmp['phone']??'')?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="01XXXXXXXXX">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Password <?=$editEmp?'<span class="text-gray-400 font-normal">(leave empty to keep)</span>':'*'?></label>
                    <input type="password" name="password" <?=$editEmp?'':'required'?> minlength="6" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Min 6 characters">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Role *</label>
                    <select name="role_id" id="roleSelect" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <?php foreach($roles as $role):?>
                        <option value="<?=$role['id']?>" <?=($editEmp['role_id']??2)==$role['id']?'selected':''?>><?=ucwords(str_replace('_',' ',$role['role_name']))?></option>
                        <?php endforeach;?>
                    </select>
                    <p class="text-[10px] text-gray-400 mt-1">Role provides base permissions. Override with checkboxes.</p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Avatar</label>
                    <input type="file" name="avatar" accept="image/*" class="w-full text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100">
                </div>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" name="is_active" <?=($editEmp['is_active']??1)?'checked':''?> class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-gray-700">Active Account</span>
                </label>
                <button type="submit" class="w-full bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition shadow-md shadow-blue-500/20">
                    <i class="fas fa-save mr-1.5"></i><?=$editEmp?'Update':'Create'?> Member
                </button>
                <?php if($editEmp):?>
                <a href="?tab=add" class="block w-full text-center border border-gray-300 text-gray-600 px-6 py-2 rounded-lg text-sm hover:bg-gray-50">Cancel</a>
                <?php endif;?>
            </div>
        </div>
    </div>
    
    <!-- Right: Permissions -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm p-6">
            <div class="flex items-center justify-between mb-5">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800"><i class="fas fa-key mr-1.5 text-amber-500"></i>Permissions</h3>
                    <p class="text-xs text-gray-400">Select what this member can access. Leave empty to use role defaults.</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" onclick="selectAllPerms()" class="text-xs bg-blue-50 text-blue-600 px-3 py-1.5 rounded-lg hover:bg-blue-100 transition font-medium"><i class="fas fa-check-double mr-1"></i>All</button>
                    <button type="button" onclick="clearAllPerms()" class="text-xs bg-gray-50 text-gray-600 px-3 py-1.5 rounded-lg hover:bg-gray-100 transition font-medium"><i class="fas fa-times mr-1"></i>Clear</button>
                </div>
            </div>
            
            <!-- Full Access Toggle -->
            <div class="mb-5 p-3 rounded-xl border-2 border-dashed <?=in_array('all',$editEmpPerms)?'border-red-300 bg-red-50':'border-gray-200 bg-gray-50'?>">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="permissions[]" value="all" id="permAll" onchange="toggleFullAccess()"
                        <?=in_array('all',$editEmpPerms)?'checked':''?>
                        class="rounded border-gray-300 text-red-600 focus:ring-red-500 w-5 h-5">
                    <div>
                        <span class="font-semibold text-gray-800"><i class="fas fa-crown text-amber-500 mr-1"></i>Full Access (Super Admin)</span>
                        <p class="text-xs text-gray-500">Grants all permissions. Individual selections below will be ignored.</p>
                    </div>
                </label>
            </div>
            
            <!-- Module Permissions Grid -->
            <div class="grid sm:grid-cols-2 gap-4" id="modulePermsGrid">
                <?php foreach($permissionModules as $moduleKey=>$module):?>
                <div class="perm-card border rounded-xl p-4 hover:border-blue-200">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-<?=$module['color']?>-100 flex items-center justify-center">
                                <i class="<?=$module['icon']?> text-<?=$module['color']?>-500 text-sm"></i>
                            </div>
                            <h4 class="font-semibold text-sm text-gray-800"><?=$module['label']?></h4>
                        </div>
                        <button type="button" onclick="toggleModule('<?=$moduleKey?>')" class="text-[10px] px-2 py-0.5 rounded bg-gray-100 text-gray-500 hover:bg-gray-200">All</button>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach($module['actions'] as $act):
                            $pv=$moduleKey.'.'.$act; $isChecked=in_array($pv,$editEmpPerms);?>
                        <label class="perm-chip inline-flex items-center gap-1 border rounded-full px-2.5 py-1 text-xs text-gray-600 hover:border-blue-300">
                            <input type="checkbox" name="permissions[]" value="<?=$pv?>" class="hidden perm-cb perm-<?=$moduleKey?>" <?=$isChecked?'checked':''?>>
                            <span class="chip-check text-white"><i class="fas fa-check text-[9px]"></i></span>
                            <?=$actionLabels[$act]??ucfirst($act)?>
                        </label>
                        <?php endforeach;?>
                    </div>
                </div>
                <?php endforeach;?>
            </div>
        </div>
    </div>
</div>
</form>

<script>
function toggleFullAccess(){const c=document.getElementById('permAll').checked,g=document.getElementById('modulePermsGrid');g.style.opacity=c?'0.3':'1';g.style.pointerEvents=c?'none':'auto'}
function selectAllPerms(){document.querySelectorAll('.perm-cb').forEach(c=>c.checked=true)}
function clearAllPerms(){document.querySelectorAll('.perm-cb').forEach(c=>c.checked=false);document.getElementById('permAll').checked=false;toggleFullAccess()}
function toggleModule(m){const cbs=document.querySelectorAll('.perm-'+m);const all=[...cbs].every(c=>c.checked);cbs.forEach(c=>c.checked=!all)}
document.addEventListener('DOMContentLoaded',toggleFullAccess);
</script>


<?php // ═══════ TAB: ROLES & PERMISSIONS ═══════
elseif ($tab === 'roles'): ?>
<?php if (!isSuperAdmin()) { redirect(adminUrl('pages/employees.php?msg=no_permission')); } ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Role Form -->
    <div>
        <div class="bg-white rounded-xl border shadow-sm p-6 sticky top-4">
            <h3 class="text-lg font-semibold text-gray-800 mb-1">
                <?=$editRole?'<i class="fas fa-edit mr-1.5 text-blue-500"></i>Edit Role':'<i class="fas fa-plus mr-1.5 text-green-500"></i>New Role'?>
            </h3>
            <p class="text-xs text-gray-400 mb-5">Roles give default permissions to all assigned members.</p>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save_role">
                <?php if($editRole):?><input type="hidden" name="role_id" value="<?=$editRole['id']?>"><?php endif;?>
                
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Role Name *</label>
                    <input type="text" name="role_name" required value="<?=e($editRole['role_name']??'')?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. manager, editor">
                </div>
                
                <!-- Full Access -->
                <div class="p-3 rounded-xl border-2 border-dashed <?=in_array('all',$editRolePerms)?'border-red-300 bg-red-50':'border-gray-200 bg-gray-50'?>">
                    <label class="flex items-center gap-2 cursor-pointer text-sm">
                        <input type="checkbox" name="role_permissions[]" value="all" id="rolePermAll" onchange="toggleRoleFull()"
                            <?=in_array('all',$editRolePerms)?'checked':''?>
                            class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                        <span class="font-medium"><i class="fas fa-crown text-amber-500 mr-1"></i>Full Access</span>
                    </label>
                </div>
                
                <!-- Module Permissions -->
                <div id="roleModulePerms" class="space-y-3 max-h-[55vh] overflow-y-auto pr-1">
                    <?php foreach($permissionModules as $mk=>$m):?>
                    <div class="border rounded-lg p-3">
                        <div class="flex items-center gap-2 mb-2">
                            <i class="<?=$m['icon']?> text-<?=$m['color']?>-500 text-xs"></i>
                            <span class="text-xs font-semibold text-gray-700"><?=$m['label']?></span>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <?php foreach($m['actions'] as $act): $pv=$mk.'.'.$act;?>
                            <label class="perm-chip inline-flex items-center gap-1 border rounded-full px-2 py-0.5 text-[11px] text-gray-600">
                                <input type="checkbox" name="role_permissions[]" value="<?=$pv?>" class="hidden role-perm-cb" <?=in_array($pv,$editRolePerms)?'checked':''?>>
                                <span class="chip-check text-white"><i class="fas fa-check text-[8px]"></i></span>
                                <?=$actionLabels[$act]??ucfirst($act)?>
                            </label>
                            <?php endforeach;?>
                        </div>
                    </div>
                    <?php endforeach;?>
                </div>
                
                <button type="submit" class="w-full bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                    <i class="fas fa-save mr-1.5"></i><?=$editRole?'Update':'Create'?> Role
                </button>
                <?php if($editRole):?>
                <a href="?tab=roles" class="block w-full text-center border border-gray-300 text-gray-600 px-6 py-2 rounded-lg text-sm hover:bg-gray-50">Cancel</a>
                <?php endif;?>
            </form>
        </div>
    </div>
    
    <!-- Existing Roles -->
    <div class="lg:col-span-2 space-y-4">
        <?php foreach($roles as $role):
            $rPerms=json_decode($role['permissions']??'[]',true)?:[];
            $usersCount=$db->count('admin_users','role_id=?',[$role['id']]);
            $isAll=in_array('all',$rPerms);
        ?>
        <div class="perm-card bg-white rounded-xl border shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-purple-500 to-blue-500 flex items-center justify-center text-white"><i class="fas fa-shield-alt"></i></div>
                    <div>
                        <h4 class="font-semibold text-gray-800"><?=ucwords(str_replace('_',' ',$role['role_name']))?></h4>
                        <p class="text-xs text-gray-400"><?=$usersCount?> member<?=$usersCount!==1?'s':''?> assigned</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <?php if(isSuperAdmin()):?>
                    <a href="<?=adminUrl('pages/profile.php?action=view_as&role_id='.$role['id'])?>" class="text-xs bg-purple-50 text-purple-600 px-3 py-1.5 rounded-lg hover:bg-purple-100 transition" title="Preview what this role sees"><i class="fas fa-eye mr-1"></i>Preview</a>
                    <?php endif;?>
                    <a href="?tab=roles&edit_role=<?=$role['id']?>" class="text-xs bg-blue-50 text-blue-600 px-3 py-1.5 rounded-lg hover:bg-blue-100 transition"><i class="fas fa-edit mr-1"></i>Edit</a>
                    <?php if($role['id']>1&&$usersCount===0):?>
                    <form method="POST" class="inline" onsubmit="return confirm('Delete this role?')">
                        <input type="hidden" name="action" value="delete_role"><input type="hidden" name="role_id" value="<?=$role['id']?>">
                        <button class="text-xs bg-red-50 text-red-600 px-3 py-1.5 rounded-lg hover:bg-red-100 transition"><i class="fas fa-trash mr-1"></i>Delete</button>
                    </form>
                    <?php endif;?>
                </div>
            </div>
            
            <div class="flex flex-wrap gap-1.5">
                <?php if($isAll):?>
                <span class="inline-flex items-center gap-1 text-xs bg-red-50 text-red-600 px-2.5 py-1 rounded-full font-medium"><i class="fas fa-crown text-[10px]"></i> Full Access</span>
                <?php else:
                    $modulesSeen=[];
                    foreach($rPerms as $p){$parts=explode('.',$p);$mod=$parts[0];$act=$parts[1]??'';if(!isset($modulesSeen[$mod]))$modulesSeen[$mod]=[];if($act)$modulesSeen[$mod][]=$act;}
                    foreach($modulesSeen as $mod=>$acts):
                        if(!isset($permissionModules[$mod]))continue;$mm=$permissionModules[$mod];
                ?>
                <div class="inline-flex items-center text-xs bg-<?=$mm['color']?>-50 text-<?=$mm['color']?>-700 rounded-lg overflow-hidden border border-<?=$mm['color']?>-100">
                    <span class="px-2 py-1 font-medium bg-<?=$mm['color']?>-100/50"><i class="<?=$mm['icon']?> text-[10px] mr-0.5"></i><?=$mm['label']?></span>
                    <?php if(!empty($acts)):?><span class="px-2 py-1 text-<?=$mm['color']?>-500 text-[10px]"><?=implode(', ',array_map('ucfirst',$acts))?></span><?php endif;?>
                </div>
                <?php endforeach;
                    if(empty($modulesSeen)):?><span class="text-xs text-gray-400 italic">No permissions</span><?php endif;?>
                <?php endif;?>
            </div>
        </div>
        <?php endforeach;?>
    </div>
</div>

<script>
function toggleRoleFull(){const c=document.getElementById('rolePermAll').checked,g=document.getElementById('roleModulePerms');g.style.opacity=c?'0.3':'1';g.style.pointerEvents=c?'none':'auto'}
document.addEventListener('DOMContentLoaded',toggleRoleFull);
</script>


<?php // ═══════ TAB: PERFORMANCE ═══════
elseif ($tab === 'performance'): ?>

<div class="grid lg:grid-cols-3 gap-6">
    <div>
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><i class="fas fa-chart-line mr-1.5 text-green-500"></i>Log Performance</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="log_performance">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Employee *</label>
                    <select name="admin_user_id" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        <?php foreach($employees as $emp):if($emp['is_active']):?>
                        <option value="<?=$emp['id']?>"><?=e($emp['full_name'])?></option>
                        <?php endif;endforeach;?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Period Date *</label>
                    <input type="date" name="period_date" required value="<?=date('Y-m-d')?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="block text-xs font-medium text-gray-600 mb-1">Orders Processed</label><input type="number" name="orders_processed" value="0" min="0" class="w-full border rounded-lg px-3 py-2 text-sm"></div>
                    <div><label class="block text-xs font-medium text-gray-600 mb-1">Orders Confirmed</label><input type="number" name="orders_confirmed" value="0" min="0" class="w-full border rounded-lg px-3 py-2 text-sm"></div>
                    <div><label class="block text-xs font-medium text-gray-600 mb-1">Orders Cancelled</label><input type="number" name="orders_cancelled" value="0" min="0" class="w-full border rounded-lg px-3 py-2 text-sm"></div>
                    <div><label class="block text-xs font-medium text-gray-600 mb-1">Calls Made</label><input type="number" name="calls_made" value="0" min="0" class="w-full border rounded-lg px-3 py-2 text-sm"></div>
                    <div><label class="block text-xs font-medium text-gray-600 mb-1">Tasks Done</label><input type="number" name="tasks_completed" value="0" min="0" class="w-full border rounded-lg px-3 py-2 text-sm"></div>
                    <div><label class="block text-xs font-medium text-gray-600 mb-1">Score (0-100)</label><input type="number" name="performance_score" value="0" min="0" max="100" step="0.1" class="w-full border rounded-lg px-3 py-2 text-sm"></div>
                </div>
                <div><label class="block text-xs font-medium text-gray-600 mb-1">Notes</label><textarea name="notes" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm"></textarea></div>
                <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700"><i class="fas fa-save mr-1"></i>Log Performance</button>
            </form>
        </div>
    </div>
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Employee</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Processed</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Confirmed</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Cancelled</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Calls</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Score</th>
                </tr></thead>
                <tbody class="divide-y">
                    <?php foreach($performances as $p):?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium"><?=e($p['full_name'])?></td>
                        <td class="px-4 py-3 text-gray-500"><?=date('M d, Y',strtotime($p['period_date']))?></td>
                        <td class="px-4 py-3 text-center"><?=$p['orders_processed']?></td>
                        <td class="px-4 py-3 text-center text-green-600"><?=$p['orders_confirmed']?></td>
                        <td class="px-4 py-3 text-center text-red-600"><?=$p['orders_cancelled']?></td>
                        <td class="px-4 py-3 text-center"><?=$p['calls_made']?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="font-semibold <?=$p['performance_score']>=70?'text-green-600':($p['performance_score']>=40?'text-yellow-600':'text-red-600')?>"><?=number_format($p['performance_score'],1)?></span>
                        </td>
                    </tr>
                    <?php endforeach;?>
                    <?php if(empty($performances)):?>
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No performance records yet</td></tr>
                    <?php endif;?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($tab === 'salary'): ?>
<?php
// ── Salary Management ──
// Ensure tables
try { $db->query("SELECT 1 FROM fixed_expenses LIMIT 1"); } catch (\Throwable $e) {
    $db->query("CREATE TABLE IF NOT EXISTS fixed_expenses (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, amount DECIMAL(12,2) NOT NULL, category_id INT DEFAULT NULL, expense_type ENUM('fixed','salary') DEFAULT 'fixed', employee_name VARCHAR(200) DEFAULT NULL, employee_role VARCHAR(100) DEFAULT NULL, frequency ENUM('monthly','quarterly','yearly') DEFAULT 'monthly', start_date DATE NOT NULL, end_date DATE DEFAULT NULL, day_of_month TINYINT DEFAULT 28, is_active TINYINT(1) DEFAULT 1, notes TEXT, created_by INT, admin_user_id INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
try { $db->query("ALTER TABLE fixed_expenses ADD COLUMN admin_user_id INT DEFAULT NULL AFTER created_by"); } catch (\Throwable $e) {}
try { $db->query("ALTER TABLE fixed_expenses ADD COLUMN expense_type ENUM('fixed','salary') DEFAULT 'fixed' AFTER category_id"); } catch (\Throwable $e) {}
try { $db->query("ALTER TABLE fixed_expenses ADD COLUMN employee_name VARCHAR(200) DEFAULT NULL AFTER expense_type"); } catch (\Throwable $e) {}
try { $db->query("ALTER TABLE fixed_expenses ADD COLUMN employee_role VARCHAR(100) DEFAULT NULL AFTER employee_name"); } catch (\Throwable $e) {}

// Ensure salary_deductions table
try { $db->query("SELECT 1 FROM salary_deductions LIMIT 1"); } catch (\Throwable $e) {
    $db->query("CREATE TABLE IF NOT EXISTS salary_deductions (id INT AUTO_INCREMENT PRIMARY KEY, admin_user_id INT NOT NULL, fixed_expense_id INT DEFAULT NULL, month VARCHAR(7) NOT NULL, deduction_amount DECIMAL(12,2) NOT NULL DEFAULT 0, reason VARCHAR(500), is_compromised TINYINT(1) DEFAULT 0, compromised_by INT DEFAULT NULL, compromised_at DATETIME DEFAULT NULL, compromise_note VARCHAR(500) DEFAULT NULL, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_user_month(admin_user_id, month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sa = $_POST['action'] ?? '';
    if ($sa === 'set_salary') {
        $empId = intval($_POST['emp_id']);
        $emp = $db->fetch("SELECT full_name, phone FROM admin_users WHERE id=?", [$empId]);
        if ($emp) {
            // Check if salary already exists for this employee
            $existing = $db->fetch("SELECT id FROM fixed_expenses WHERE expense_type='salary' AND admin_user_id=? AND is_active=1", [$empId]);
            if ($existing) {
                $db->query("UPDATE fixed_expenses SET amount=?, employee_role=?, day_of_month=?, notes=? WHERE id=?", [
                    floatval($_POST['salary_amount']), sanitize($_POST['salary_role'] ?? ''),
                    min(28, max(1, intval($_POST['salary_day'] ?? 28))),
                    sanitize($_POST['salary_notes'] ?? ''), $existing['id']
                ]);
            } else {
                $db->query("INSERT INTO fixed_expenses (title, amount, expense_type, employee_name, employee_role, frequency, start_date, day_of_month, notes, created_by, admin_user_id, is_active) VALUES (?,?,'salary',?,?,'monthly',?,?,?,?,?,1)", [
                    'Salary: ' . $emp['full_name'], floatval($_POST['salary_amount']),
                    $emp['full_name'], sanitize($_POST['salary_role'] ?? ''), date('Y-m-01'),
                    min(28, max(1, intval($_POST['salary_day'] ?? 28))),
                    sanitize($_POST['salary_notes'] ?? ''), getAdminId(), $empId
                ]);
            }
        }
        redirect(adminUrl('pages/employees.php?tab=salary&msg=Salary saved'));
    }
    if ($sa === 'remove_salary') {
        $db->query("UPDATE fixed_expenses SET is_active=0 WHERE id=? AND expense_type='salary'", [intval($_POST['fe_id'])]);
        redirect(adminUrl('pages/employees.php?tab=salary&msg=Salary deactivated'));
    }
}

// Load salary data
$salaries = $db->fetchAll("SELECT fe.*, au.full_name, au.is_active as emp_active FROM fixed_expenses fe LEFT JOIN admin_users au ON au.id = fe.admin_user_id WHERE fe.expense_type = 'salary' ORDER BY fe.is_active DESC, au.full_name") ?: [];
$totalMonthly = 0;
foreach ($salaries as $s) { if ($s['is_active']) $totalMonthly += floatval($s['amount']); }

// Employees without salary
$empWithSalary = array_column(array_filter($salaries, function($s){ return $s['is_active']; }), 'admin_user_id');
$empWithoutSalary = array_filter($employees, function($e) use ($empWithSalary) { return !in_array($e['id'], $empWithSalary); });

// Deductions for current month
$currentMonth = date('Y-m');
$deductions = $db->fetchAll("SELECT sd.*, au.full_name FROM salary_deductions sd LEFT JOIN admin_users au ON au.id = sd.admin_user_id WHERE sd.month = ? ORDER BY sd.created_at DESC", [$currentMonth]) ?: [];
?>

<?php if (!empty($_GET['msg'])): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">✓ <?= e($_GET['msg']) ?></div><?php endif; ?>

<!-- Salary Summary -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase">Total Monthly Salary</p>
        <p class="text-2xl font-bold text-purple-600">৳<?= number_format($totalMonthly) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase">Active Employees</p>
        <p class="text-2xl font-bold text-blue-600"><?= count(array_filter($salaries, function($s){ return $s['is_active']; })) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase">This Month Deductions</p>
        <p class="text-2xl font-bold text-red-500">৳<?= number_format(array_sum(array_map(function($d){ return $d['is_compromised'] ? 0 : floatval($d['deduction_amount']); }, $deductions))) ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase">No Salary Set</p>
        <p class="text-2xl font-bold text-gray-500"><?= count($empWithoutSalary) ?></p>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-5">
    <!-- Salary List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="px-5 py-3 border-b"><h3 class="text-sm font-semibold text-gray-800">Employee Salaries</h3></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Employee</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Role</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Salary</th>
                        <th class="text-center px-4 py-3 font-medium text-gray-600">Pay Day</th>
                        <th class="text-center px-4 py-3 font-medium text-gray-600">Status</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        <?php foreach ($salaries as $s): ?>
                        <tr class="hover:bg-gray-50 <?= $s['is_active'] ? '' : 'opacity-50' ?>">
                            <td class="px-4 py-3 font-medium text-gray-800"><?= e($s['full_name'] ?? $s['employee_name']) ?></td>
                            <td class="px-4 py-3 text-gray-500"><?= e($s['employee_role'] ?? '—') ?></td>
                            <td class="px-4 py-3 text-right font-bold text-purple-600">৳<?= number_format($s['amount']) ?></td>
                            <td class="px-4 py-3 text-center text-gray-500"><?= $s['day_of_month'] ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium <?= $s['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <?php if ($s['is_active']): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Deactivate salary?')"><input type="hidden" name="action" value="remove_salary"><input type="hidden" name="fe_id" value="<?= $s['id'] ?>">
                                    <button class="text-xs text-red-500 hover:text-red-700">Deactivate</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($salaries)): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No salaries configured. Use the form to add employee salaries.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add/Edit Salary -->
    <div class="space-y-4">
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-3">Set Employee Salary</h4>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="set_salary">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Employee *</label>
                    <select name="emp_id" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="">Select employee...</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= e($e['full_name']) ?> (<?= e($e['username']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Monthly Salary (৳) *</label>
                    <input type="number" name="salary_amount" step="0.01" min="0" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Role/Position</label>
                        <input type="text" name="salary_role" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="e.g., Manager">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Pay Day (1-28)</label>
                        <input type="number" name="salary_day" min="1" max="28" value="28" class="w-full border rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                    <input type="text" name="salary_notes" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="Bank account, payment method...">
                </div>
                <button type="submit" class="w-full bg-purple-600 text-white py-2.5 rounded-lg text-sm font-medium hover:bg-purple-700">Save Salary</button>
            </form>
            <p class="text-[9px] text-gray-400 mt-2">Salary auto-generates as an expense on pay day each month. Shows in Accounting → Fixed Expenses.</p>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($tab === 'attendance'): ?>
<?php
// ── Attendance & Deduction System ──
try { $db->query("SELECT 1 FROM salary_deductions LIMIT 1"); } catch (\Throwable $e) {
    $db->query("CREATE TABLE IF NOT EXISTS salary_deductions (id INT AUTO_INCREMENT PRIMARY KEY, admin_user_id INT NOT NULL, fixed_expense_id INT DEFAULT NULL, month VARCHAR(7) NOT NULL, deduction_amount DECIMAL(12,2) NOT NULL DEFAULT 0, reason VARCHAR(500), is_compromised TINYINT(1) DEFAULT 0, compromised_by INT DEFAULT NULL, compromised_at DATETIME DEFAULT NULL, compromise_note VARCHAR(500) DEFAULT NULL, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_user_month(admin_user_id, month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
try { $db->query("SELECT 1 FROM employee_attendance LIMIT 1"); } catch (\Throwable $e) {
    $db->query("CREATE TABLE IF NOT EXISTS employee_attendance (id INT AUTO_INCREMENT PRIMARY KEY, admin_user_id INT NOT NULL, attendance_date DATE NOT NULL, status ENUM('present','absent','late','half_day','leave') DEFAULT 'present', check_in TIME DEFAULT NULL, check_out TIME DEFAULT NULL, notes VARCHAR(500), created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY idx_user_date(admin_user_id, attendance_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$attMonth = $_GET['att_month'] ?? date('Y-m');
$attEmpId = intval($_GET['att_emp'] ?? 0);

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sa = $_POST['action'] ?? '';
    if ($sa === 'mark_attendance') {
        $aDate = $_POST['att_date'] ?? date('Y-m-d');
        foreach ($_POST['status'] ?? [] as $uid => $st) {
            $uid = intval($uid);
            $st = in_array($st, ['present','absent','late','half_day','leave']) ? $st : 'present';
            try {
                $db->query("INSERT INTO employee_attendance (admin_user_id, attendance_date, status, notes, created_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), notes=VALUES(notes)", [
                    $uid, $aDate, $st, sanitize($_POST['att_note'][$uid] ?? ''), getAdminId()
                ]);
            } catch (\Throwable $e) {}
        }
        redirect(adminUrl("pages/employees.php?tab=attendance&att_month={$attMonth}&msg=Attendance saved"));
    }
    if ($sa === 'add_deduction') {
        $db->query("INSERT INTO salary_deductions (admin_user_id, fixed_expense_id, month, deduction_amount, reason, created_by) VALUES (?,?,?,?,?,?)", [
            intval($_POST['ded_emp']),
            intval($_POST['ded_fe'] ?? 0) ?: null,
            $_POST['ded_month'] ?? date('Y-m'),
            floatval($_POST['ded_amount']),
            sanitize($_POST['ded_reason'] ?? ''),
            getAdminId()
        ]);
        redirect(adminUrl("pages/employees.php?tab=attendance&att_month={$attMonth}&msg=Deduction added"));
    }
    if ($sa === 'compromise_deduction') {
        $db->query("UPDATE salary_deductions SET is_compromised=1, compromised_by=?, compromised_at=NOW(), compromise_note=? WHERE id=?", [
            getAdminId(), sanitize($_POST['comp_note'] ?? ''), intval($_POST['ded_id'])
        ]);
        redirect(adminUrl("pages/employees.php?tab=attendance&att_month={$attMonth}&msg=Deduction compromised"));
    }
}

// Load attendance data
$monthStart = $attMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$daysInMonth = intval(date('t', strtotime($monthStart)));
$monthLabel = date('F Y', strtotime($monthStart));

// Get all active employees
$activeEmps = $db->fetchAll("SELECT id, full_name, username FROM admin_users WHERE is_active=1 ORDER BY full_name");

// Get attendance records for the month
$attRecords = [];
try {
    $rows = $db->fetchAll("SELECT * FROM employee_attendance WHERE attendance_date BETWEEN ? AND ?", [$monthStart, $monthEnd]);
    foreach ($rows as $r) { $attRecords[$r['admin_user_id']][$r['attendance_date']] = $r; }
} catch (\Throwable $e) {}

// Get deductions for the month
$monthDeductions = $db->fetchAll("SELECT sd.*, au.full_name FROM salary_deductions sd LEFT JOIN admin_users au ON au.id=sd.admin_user_id WHERE sd.month=? ORDER BY sd.created_at DESC", [$attMonth]) ?: [];

// Get salaries for deduction form
$empSalaries = $db->fetchAll("SELECT fe.id, fe.admin_user_id, fe.amount, au.full_name FROM fixed_expenses fe JOIN admin_users au ON au.id=fe.admin_user_id WHERE fe.expense_type='salary' AND fe.is_active=1") ?: [];
?>

<?php if (!empty($_GET['msg'])): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">✓ <?= e($_GET['msg']) ?></div><?php endif; ?>

<!-- Month Navigator -->
<div class="flex items-center gap-3 mb-5">
    <a href="?tab=attendance&att_month=<?= date('Y-m', strtotime($attMonth . ' -1 month')) ?>" class="px-3 py-1.5 bg-gray-100 rounded-lg text-sm hover:bg-gray-200">← Prev</a>
    <h3 class="text-lg font-bold text-gray-800"><?= $monthLabel ?></h3>
    <a href="?tab=attendance&att_month=<?= date('Y-m', strtotime($attMonth . ' +1 month')) ?>" class="px-3 py-1.5 bg-gray-100 rounded-lg text-sm hover:bg-gray-200">Next →</a>
    <?php if ($attMonth !== date('Y-m')): ?>
    <a href="?tab=attendance" class="text-xs text-blue-600">Today</a>
    <?php endif; ?>
</div>

<div class="grid lg:grid-cols-3 gap-5">
    <!-- Mark Attendance -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="px-5 py-3 border-b flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-800">Daily Attendance</h3>
                <form class="flex items-center gap-2" onsubmit="return false">
                    <input type="date" id="attDatePicker" value="<?= date('Y-m-d') ?>" min="<?= $monthStart ?>" max="<?= $monthEnd ?>" class="border rounded-lg px-2.5 py-1.5 text-xs">
                </form>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="mark_attendance">
                <input type="hidden" name="att_date" id="attDateHidden" value="<?= date('Y-m-d') ?>">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50"><tr>
                            <th class="text-left px-4 py-2 font-medium text-gray-600">Employee</th>
                            <th class="text-center px-2 py-2 font-medium text-gray-600">Status</th>
                            <th class="text-left px-2 py-2 font-medium text-gray-600">Note</th>
                            <th class="text-center px-2 py-2 font-medium text-gray-600">This Month</th>
                        </tr></thead>
                        <tbody class="divide-y">
                            <?php foreach ($activeEmps as $ae):
                                $todayAtt = $attRecords[$ae['id']][date('Y-m-d')] ?? null;
                                $monthPresent = 0; $monthAbsent = 0; $monthLate = 0;
                                foreach ($attRecords[$ae['id']] ?? [] as $ar) {
                                    if ($ar['status'] === 'present') $monthPresent++;
                                    elseif ($ar['status'] === 'absent') $monthAbsent++;
                                    elseif ($ar['status'] === 'late') $monthLate++;
                                    elseif ($ar['status'] === 'half_day') $monthPresent += 0.5;
                                }
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2.5 font-medium text-gray-800"><?= e($ae['full_name']) ?></td>
                                <td class="px-2 py-2.5 text-center">
                                    <select name="status[<?= $ae['id'] ?>]" class="border rounded px-2 py-1 text-xs">
                                        <?php foreach(['present'=>'✅ Present','absent'=>'❌ Absent','late'=>'⏰ Late','half_day'=>'½ Half Day','leave'=>'🏖 Leave'] as $sk=>$sl): ?>
                                        <option value="<?= $sk ?>" <?= ($todayAtt['status'] ?? '') === $sk ? 'selected' : '' ?>><?= $sl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-2 py-2.5"><input type="text" name="att_note[<?= $ae['id'] ?>]" value="<?= e($todayAtt['notes'] ?? '') ?>" class="border rounded px-2 py-1 text-xs w-full" placeholder="Note"></td>
                                <td class="px-2 py-2.5 text-center text-xs">
                                    <span class="text-green-600 font-medium"><?= $monthPresent ?>P</span>
                                    <?php if ($monthAbsent): ?><span class="text-red-500 ml-1"><?= $monthAbsent ?>A</span><?php endif; ?>
                                    <?php if ($monthLate): ?><span class="text-amber-500 ml-1"><?= $monthLate ?>L</span><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t">
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">Save Attendance</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Deductions -->
    <div class="space-y-4">
        <!-- Add Deduction -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-3">Add Salary Deduction</h4>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="add_deduction">
                <input type="hidden" name="ded_month" value="<?= e($attMonth) ?>">
                <select name="ded_emp" required class="w-full border rounded-lg px-3 py-2 text-sm">
                    <option value="">Select employee...</option>
                    <?php foreach ($empSalaries as $es): ?>
                    <option value="<?= $es['admin_user_id'] ?>" data-fe="<?= $es['id'] ?>"><?= e($es['full_name']) ?> (৳<?= number_format($es['amount']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="ded_fe" id="dedFeId" value="">
                <input type="number" name="ded_amount" step="0.01" min="0" required placeholder="Deduction amount (৳)" class="w-full border rounded-lg px-3 py-2 text-sm">
                <input type="text" name="ded_reason" placeholder="Reason (e.g., 3 days absent)" class="w-full border rounded-lg px-3 py-2 text-sm">
                <button type="submit" class="w-full bg-red-600 text-white py-2 rounded-lg text-sm font-medium hover:bg-red-700">Add Deduction</button>
            </form>
            <script>document.querySelector('[name="ded_emp"]').onchange=function(){var o=this.options[this.selectedIndex];document.getElementById('dedFeId').value=o.dataset.fe||'';}</script>
        </div>

        <!-- Deduction List -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-3"><?= $monthLabel ?> Deductions</h4>
            <?php if (empty($monthDeductions)): ?>
            <p class="text-xs text-gray-400 text-center py-4">No deductions this month</p>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($monthDeductions as $ded): ?>
                <div class="p-2.5 rounded-lg border <?= $ded['is_compromised'] ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' ?>">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-medium text-gray-800"><?= e($ded['full_name']) ?></span>
                        <span class="text-xs font-bold <?= $ded['is_compromised'] ? 'text-green-600 line-through' : 'text-red-600' ?>">-৳<?= number_format($ded['deduction_amount']) ?></span>
                    </div>
                    <p class="text-[10px] text-gray-500"><?= e($ded['reason'] ?? '—') ?></p>
                    <?php if ($ded['is_compromised']): ?>
                    <p class="text-[10px] text-green-600 mt-1">✅ Compromised: <?= e($ded['compromise_note'] ?? '') ?></p>
                    <?php else: ?>
                    <form method="POST" class="mt-1.5 flex gap-1">
                        <input type="hidden" name="action" value="compromise_deduction">
                        <input type="hidden" name="ded_id" value="<?= $ded['id'] ?>">
                        <input type="text" name="comp_note" placeholder="Reason to waive..." class="flex-1 border rounded px-2 py-1 text-[10px]">
                        <button type="submit" class="text-[10px] bg-green-600 text-white px-2 py-1 rounded hover:bg-green-700" onclick="return confirm('Compromise this deduction?')">Waive</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <p class="text-[9px] text-gray-400 mt-3">Deductions are subtracted from next month's salary. Compromised deductions are waived by the HRM manager.</p>
        </div>
    </div>
</div>

<script>
document.getElementById('attDatePicker').onchange = function() {
    document.getElementById('attDateHidden').value = this.value;
};
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
