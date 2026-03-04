<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Customers & Users';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// ─── Handle POST Actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $custId = intval($_POST['customer_id'] ?? 0);
    $ids = $_POST['selected_ids'] ?? [];
    if (is_string($ids)) $ids = array_filter(explode(',', $ids));
    $ids = array_map('intval', array_filter($ids));
    $redirectTab = $_POST['current_tab'] ?? ($_GET['tab'] ?? 'all');

    // Single: Block
    if ($action === 'block' && $custId) {
        $db->update('customers', ['is_blocked' => 1], 'id = ?', [$custId]);
        $cust = $db->fetch("SELECT phone FROM customers WHERE id = ?", [$custId]);
        if ($cust) {
            $ex = $db->fetch("SELECT id FROM blocked_phones WHERE phone = ?", [$cust['phone']]);
            if (!$ex) { try { $db->insert('blocked_phones', ['phone' => $cust['phone'], 'reason' => 'Blocked by admin', 'blocked_by' => getAdminId() ?: 1]); } catch(Exception $e) {} }
        }
        redirect(adminUrl("pages/customers.php?tab={$redirectTab}&msg=blocked"));
    }

    // Single: Unblock
    if ($action === 'unblock' && $custId) {
        $db->update('customers', ['is_blocked' => 0], 'id = ?', [$custId]);
        $cust = $db->fetch("SELECT phone FROM customers WHERE id = ?", [$custId]);
        if ($cust) $db->delete('blocked_phones', 'phone = ?', [$cust['phone']]);
        redirect(adminUrl("pages/customers.php?tab={$redirectTab}&msg=unblocked"));
    }

    // Single: Delete
    if ($action === 'delete' && $custId) {
        $db->update('orders', ['customer_id' => null], 'customer_id = ?', [$custId]);
        $db->delete('customers', 'id = ?', [$custId]);
        redirect(adminUrl("pages/customers.php?tab={$redirectTab}&msg=deleted"));
    }

    // Single: Update
    if ($action === 'update_single' && $custId) {
        $upd = [];
        foreach (['name','phone','email','address','city','district','notes'] as $fld) {
            if (isset($_POST["edit_{$fld}"])) $upd[$fld] = trim($_POST["edit_{$fld}"]);
        }
        if (!empty($upd)) $db->update('customers', $upd, 'id = ?', [$custId]);
        redirect(adminUrl("pages/customers.php?tab={$redirectTab}&msg=updated"));
    }

    // Add new customer
    if ($action === 'add_customer') {
        $name  = trim($_POST['new_name']  ?? '');
        $phone = trim($_POST['new_phone'] ?? '');
        $email = trim($_POST['new_email'] ?? '');
        $pass  = $_POST['new_password']   ?? '';
        if (empty($name) || empty($phone)) {
            redirect(adminUrl("pages/customers.php?tab={$redirectTab}&msg=add_err&err=" . urlencode('Name and phone are required')));
        }
        $exists = $db->fetch("SELECT id FROM customers WHERE phone = ?", [$phone]);
        if ($exists) {
            redirect(adminUrl("pages/customers.php?tab={$redirectTab}&msg=add_err&err=" . urlencode('Phone number already exists')));
        }
        $insertData = [
            'name'       => $name,
            'phone'      => $phone,
            'email'      => $email ?: null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];
        if (!empty($pass)) {
            $insertData['password'] = password_hash($pass, PASSWORD_DEFAULT);
        }
        $newId = $db->insert('customers', $insertData);
        redirect(adminUrl("pages/customers.php?tab={$redirectTab}&msg=added&new_id={$newId}"));
    }

    // Bulk Actions
    if (strpos($action, 'bulk_') === 0 && !empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        switch ($action) {
            case 'bulk_block':
                $db->query("UPDATE customers SET is_blocked = 1 WHERE id IN ({$ph})", $ids);
                $phones = $db->fetchAll("SELECT phone FROM customers WHERE id IN ({$ph})", $ids);
                foreach ($phones as $p) {
                    $ex = $db->fetch("SELECT id FROM blocked_phones WHERE phone = ?", [$p['phone']]);
                    if (!$ex) { try { $db->insert('blocked_phones', ['phone' => $p['phone'], 'reason' => 'Bulk blocked', 'blocked_by' => getAdminId() ?: 1]); } catch(Exception $e) {} }
                }
                break;
            case 'bulk_unblock':
                $db->query("UPDATE customers SET is_blocked = 0 WHERE id IN ({$ph})", $ids);
                $phones = $db->fetchAll("SELECT phone FROM customers WHERE id IN ({$ph})", $ids);
                foreach ($phones as $p) $db->delete('blocked_phones', 'phone = ?', [$p['phone']]);
                break;
            case 'bulk_delete':
                $db->query("UPDATE orders SET customer_id = NULL WHERE customer_id IN ({$ph})", $ids);
                $db->query("DELETE FROM customers WHERE id IN ({$ph})", $ids);
                break;
        }
        redirect(adminUrl("pages/customers.php?tab={$redirectTab}&msg=bulk_done&count=" . count($ids)));
    }
}

// ─── Tab / Filters ───
$tab = $_GET['tab'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? '';
$sort = $_GET['sort'] ?? 'newest';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

// Tab-specific WHERE
$tabWhere = match($tab) {
    'guests'     => "(c.password IS NULL OR c.password = '') AND (c.clerk_id IS NULL OR c.clerk_id = '')",
    'registered' => "((c.password IS NOT NULL AND c.password != '') OR (c.clerk_id IS NOT NULL AND c.clerk_id != ''))",
    default      => '1=1',
};

$where = $tabWhere;
$params = [];

if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.address LIKE ?)";
    $s = "%{$search}%";
    $params = [$s, $s, $s, $s];
}
if ($filter === 'blocked')    $where .= " AND c.is_blocked = 1";
if ($filter === 'active')     $where .= " AND c.is_blocked = 0";
if ($filter === 'has_orders') $where .= " AND c.total_orders > 0";
if ($filter === 'no_orders')  $where .= " AND (c.total_orders = 0 OR c.total_orders IS NULL)";
if ($filter === 'high_risk')  $where .= " AND c.risk_score >= 70";

$orderBy = match($sort) {
    'oldest' => 'c.created_at ASC',
    'name'   => 'c.name ASC',
    'orders' => 'c.total_orders DESC',
    'spent'  => 'c.total_spent DESC',
    'risk'   => 'c.risk_score DESC',
    default  => 'c.created_at DESC',
};

// Tab counts (uses search if active)
$searchCond = $search ? " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.address LIKE ?)" : "";
$searchP = $search ? ["%{$search}%","%{$search}%","%{$search}%","%{$search}%"] : [];

$allCount = $db->fetch("SELECT COUNT(*) as n FROM customers c WHERE 1=1{$searchCond}", $searchP)['n'];
$guestCount = $db->fetch("SELECT COUNT(*) as n FROM customers c WHERE (c.password IS NULL OR c.password = '') AND (c.clerk_id IS NULL OR c.clerk_id = ''){$searchCond}", $searchP)['n'];
$regCount = $db->fetch("SELECT COUNT(*) as n FROM customers c WHERE ((c.password IS NOT NULL AND c.password != '') OR (c.clerk_id IS NOT NULL AND c.clerk_id != '')){$searchCond}", $searchP)['n'];

// Main query
$total = $db->fetch("SELECT COUNT(*) as n FROM customers c WHERE {$where}", $params)['n'];
$totalPages = max(1, ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$customers = $db->fetchAll("
    SELECT c.*,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) as order_count,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.order_status = 'delivered') as delivered_count,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.order_status = 'cancelled') as cancelled_count,
        (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.customer_id = c.id AND o.order_status = 'delivered') as total_revenue,
        (SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c.id) as last_order_at
    FROM customers c
    WHERE {$where}
    ORDER BY {$orderBy}
    LIMIT {$limit} OFFSET {$offset}
", $params);

// Stats for current tab
$st = $db->fetch("SELECT
    COUNT(*) as total,
    COALESCE(SUM(c.is_blocked), 0) as blocked,
    COALESCE(SUM(c.risk_score >= 70), 0) as high_risk,
    COALESCE(SUM(c.total_orders > 0), 0) as with_orders,
    COALESCE(SUM(c.total_orders = 0 OR c.total_orders IS NULL), 0) as no_orders,
    COALESCE(SUM(c.total_spent), 0) as revenue
FROM customers c WHERE {$tabWhere}");

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm flex items-center gap-2">
    <i class="fas fa-check-circle"></i>
    <?= match($_GET['msg']) {
        'blocked'   => 'Customer blocked.',
        'unblocked' => 'Customer unblocked.',
        'deleted'   => 'Customer deleted.',
        'updated'   => 'Customer updated.',
        'bulk_done' => (intval($_GET['count'] ?? 0)) . ' customer(s) updated.',
        default     => 'Done.'
    } ?>
</div>
<?php endif; ?>

<!-- ─── Stats ─── -->
<div class="grid grid-cols-3 md:grid-cols-6 gap-3 mb-5">
    <?php foreach ([
        ['Total',       $st['total'],       'fas fa-users',              'blue'],
        ['With Orders', $st['with_orders'], 'fas fa-shopping-bag',       'green'],
        ['No Orders',   $st['no_orders'],   'fas fa-user-clock',         'gray'],
        ['Blocked',     $st['blocked'],     'fas fa-ban',                'red'],
        ['High Risk',   $st['high_risk'],   'fas fa-exclamation-triangle','orange'],
        ['Revenue',     '৳'.number_format($st['revenue']), 'fas fa-coins', 'emerald'],
    ] as $sc): ?>
    <div class="bg-white rounded-xl p-3 shadow-sm border">
        <div class="flex items-center gap-1.5 mb-1"><i class="<?= $sc[2] ?> text-<?= $sc[3] ?>-500 text-xs"></i><span class="text-[11px] text-gray-500 font-medium"><?= $sc[0] ?></span></div>
        <p class="text-lg font-bold text-gray-800"><?= $sc[1] ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- ─── Tabs ─── -->
<div class="flex items-center gap-1 mb-4 bg-white rounded-xl p-1.5 shadow-sm border">
    <?php foreach ([
        'all'        => ['All Users',         $allCount,   'fas fa-users'],
        'guests'     => ['Guest Customers',   $guestCount, 'fas fa-user-tag'],
        'registered' => ['Registered Users',  $regCount,   'fas fa-user-check'],
    ] as $tk => $tv): ?>
    <a href="?tab=<?= $tk ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $filter ? '&filter='.$filter : '' ?>"
       class="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold transition
              <?= $tab === $tk ? 'bg-blue-600 text-white shadow' : 'text-gray-600 hover:bg-gray-100' ?>">
        <i class="<?= $tv[2] ?> text-xs"></i><?= $tv[0] ?>
        <span class="px-2 py-0.5 rounded-full text-xs <?= $tab === $tk ? 'bg-white/20' : 'bg-gray-200 text-gray-600' ?>"><?= number_format($tv[1]) ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- ─── Toolbar ─── -->
<div class="bg-white rounded-xl shadow-sm border p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3 items-center">
        <input type="hidden" name="tab" value="<?= $tab ?>">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search name, phone, email, address..."
               class="flex-1 min-w-[180px] px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
        <select name="filter" class="px-3 py-2 border rounded-lg text-sm">
            <option value="">All Status</option>
            <option value="active"     <?= $filter==='active'?'selected':'' ?>>Active</option>
            <option value="blocked"    <?= $filter==='blocked'?'selected':'' ?>>Blocked</option>
            <option value="has_orders" <?= $filter==='has_orders'?'selected':'' ?>>Has Orders</option>
            <option value="no_orders"  <?= $filter==='no_orders'?'selected':'' ?>>No Orders</option>
            <option value="high_risk"  <?= $filter==='high_risk'?'selected':'' ?>>High Risk</option>
        </select>
        <select name="sort" class="px-3 py-2 border rounded-lg text-sm">
            <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest First</option>
            <option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>Oldest First</option>
            <option value="name"   <?= $sort==='name'?'selected':'' ?>>Name A-Z</option>
            <option value="orders" <?= $sort==='orders'?'selected':'' ?>>Most Orders</option>
            <option value="spent"  <?= $sort==='spent'?'selected':'' ?>>Highest Spend</option>
            <option value="risk"   <?= $sort==='risk'?'selected':'' ?>>Highest Risk</option>
        </select>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700"><i class="fas fa-search mr-1"></i>Filter</button>
        <div class="flex gap-1 ml-auto">
            <button type="button" onclick="doExport('csv')" class="px-3 py-2 bg-green-600 text-white rounded-lg text-xs font-semibold hover:bg-green-700" title="Export all to CSV"><i class="fas fa-file-csv mr-1"></i>CSV</button>
            <button type="button" onclick="doExport('xlsx')" class="px-3 py-2 bg-teal-600 text-white rounded-lg text-xs font-semibold hover:bg-teal-700" title="Export all to Excel"><i class="fas fa-file-excel mr-1"></i>Excel</button>
            <button type="button" onclick="openAdd()" class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-xs font-semibold hover:bg-indigo-700"><i class="fas fa-user-plus mr-1"></i>New Customer</button>
        </div>
    </form>
</div>

<?php if (($_GET['msg'] ?? '') === 'added'): ?>
<div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm flex items-center gap-2">
    <i class="fas fa-check-circle"></i>
    Customer added successfully. <a href="<?= adminUrl('pages/customer-view.php?id='.intval($_GET['new_id'] ?? 0)) ?>" class="underline font-medium ml-1">View profile →</a>
</div>
<?php elseif (($_GET['msg'] ?? '') === 'add_err'): ?>
<div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm flex items-center gap-2">
    <i class="fas fa-exclamation-circle"></i> <?= e(urldecode($_GET['err'] ?? 'Error')) ?>
</div>
<?php endif; ?>

<!-- ─── Bulk Bar ─── -->
<div id="bulkBar" class="hidden bg-blue-50 border border-blue-200 rounded-xl p-3 mb-4 flex items-center gap-3 flex-wrap sticky top-0 z-30">
    <span class="text-sm font-bold text-blue-800"><i class="fas fa-check-square mr-1"></i><span id="selCnt">0</span> selected</span>
    <div class="flex gap-2 ml-auto flex-wrap">
        <button onclick="doBulk('bulk_block')" class="px-3 py-1.5 bg-red-500 text-white rounded-lg text-xs font-semibold hover:bg-red-600"><i class="fas fa-ban mr-1"></i>Block</button>
        <button onclick="doBulk('bulk_unblock')" class="px-3 py-1.5 bg-green-500 text-white rounded-lg text-xs font-semibold hover:bg-green-600"><i class="fas fa-unlock mr-1"></i>Unblock</button>
        <button onclick="doBulk('bulk_delete')" class="px-3 py-1.5 bg-gray-700 text-white rounded-lg text-xs font-semibold hover:bg-gray-800"><i class="fas fa-trash mr-1"></i>Delete</button>
        <span class="w-px h-6 bg-blue-300"></span>
        <button onclick="doExport('csv')" class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-semibold hover:bg-green-700"><i class="fas fa-file-csv mr-1"></i>Export CSV</button>
        <button onclick="doExport('xlsx')" class="px-3 py-1.5 bg-teal-600 text-white rounded-lg text-xs font-semibold hover:bg-teal-700"><i class="fas fa-file-excel mr-1"></i>Export Excel</button>
        <button onclick="clearSel()" class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold hover:bg-gray-300"><i class="fas fa-times mr-1"></i>Clear</button>
    </div>
</div>

<!-- ─── Table ─── -->
<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <div class="p-4 border-b flex items-center justify-between">
        <p class="text-sm text-gray-600"><strong><?= number_format($total) ?></strong> <?= $tab==='guests'?'guest customers':($tab==='registered'?'registered users':'users') ?></p>
        <button type="button" onclick="toggleSelAll()" class="text-xs text-blue-600 hover:text-blue-800 font-medium"><i class="fas fa-check-double mr-1"></i>Select All</button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0">
                <tr>
                    <th class="px-3 py-3 text-left w-8"><input type="checkbox" id="allCb" onchange="togAll(this)" class="rounded"></th>
                    <th class="px-3 py-3 text-left font-medium text-gray-600">Customer</th>
                    <th class="px-3 py-3 text-left font-medium text-gray-600">Phone</th>
                    <th class="px-3 py-3 text-center font-medium text-gray-600">Type</th>
                    <th class="px-3 py-3 text-center font-medium text-gray-600">Orders</th>
                    <th class="px-3 py-3 text-center font-medium text-gray-600">Delivered</th>
                    <th class="px-3 py-3 text-center font-medium text-gray-600">Cancelled</th>
                    <th class="px-3 py-3 text-right font-medium text-gray-600">Spent</th>
                    <th class="px-3 py-3 text-right font-medium text-gray-600">Credit</th>
                    <th class="px-3 py-3 text-left font-medium text-gray-600">Last Order</th>
                    <th class="px-3 py-3 text-center font-medium text-gray-600">Risk</th>
                    <th class="px-3 py-3 text-center font-medium text-gray-600">Status</th>
                    <th class="px-3 py-3 text-center font-medium text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($customers)): ?>
                <tr><td colspan="13" class="px-4 py-16 text-center text-gray-400">
                    <i class="fas fa-users text-4xl mb-3 block text-gray-200"></i>
                    No <?= $tab==='guests'?'guest customers':($tab==='registered'?'registered users':'customers') ?> found
                </td></tr>
                <?php endif; ?>
                <?php foreach ($customers as $c):
                    $isReg = !empty($c['password']) || !empty($c['clerk_id']);
                    $isClerkUser = !empty($c['clerk_id']);
                    $rs = (int)($c['risk_score'] ?? 0);
                    $safeJson = e(json_encode([
                        'name'=>$c['name'],'phone'=>$c['phone'],'email'=>$c['email']??'',
                        'address'=>$c['address']??'','city'=>$c['city']??'','district'=>$c['district']??'','notes'=>$c['notes']??''
                    ], JSON_UNESCAPED_UNICODE));
                ?>
                <tr class="hover:bg-gray-50 transition <?= $c['is_blocked'] ? 'bg-red-50/40' : '' ?>">
                    <td class="px-3 py-3"><input type="checkbox" class="rcb rounded" value="<?= $c['id'] ?>" onchange="updBar()"></td>
                    <td class="px-3 py-3">
                        <p class="font-semibold text-gray-800 truncate max-w-[160px]"><?= e($c['name']) ?></p>
                        <?php if ($c['email']): ?><p class="text-xs text-gray-400 truncate max-w-[160px]"><?= e($c['email']) ?></p><?php endif; ?>
                        <p class="text-[10px] text-gray-300 mt-0.5"><?= date('d M Y', strtotime($c['created_at'])) ?></p>
                    </td>
                    <td class="px-3 py-3">
                        <span class="font-medium text-gray-700"><?= e($c['phone']) ?></span>
                        <?php if (!empty($c['alt_phone'])): ?><br><span class="text-xs text-gray-400"><?= e($c['alt_phone']) ?></span><?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-center">
                        <?php if ($isClerkUser): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] rounded-full bg-purple-100 text-purple-700 font-semibold" title="Social Login"><i class="fas fa-shield-alt text-[8px]"></i>Social</span>
                        <?php elseif ($isReg): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] rounded-full bg-blue-100 text-blue-700 font-semibold"><i class="fas fa-user-check text-[8px]"></i>Registered</span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] rounded-full bg-gray-100 text-gray-500 font-semibold"><i class="fas fa-user-tag text-[8px]"></i>Guest</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-center font-semibold"><?= $c['order_count'] ?></td>
                    <td class="px-3 py-3 text-center font-medium text-green-600"><?= $c['delivered_count'] ?></td>
                    <td class="px-3 py-3 text-center font-medium text-red-500"><?= $c['cancelled_count'] ?></td>
                    <td class="px-3 py-3 text-right font-semibold">৳<?= number_format($c['total_revenue']) ?></td>
                    <td class="px-3 py-3 text-right"><?php $cc = floatval($c['store_credit'] ?? 0); if ($cc > 0): ?><span class="text-yellow-600 font-semibold text-xs"><i class="fas fa-coins mr-0.5"></i><?= number_format($cc) ?></span><?php else: ?><span class="text-gray-300">—</span><?php endif; ?></td>
                    <td class="px-3 py-3 text-xs text-gray-500"><?= $c['last_order_at'] ? date('d M Y', strtotime($c['last_order_at'])) : '<span class="text-gray-300">—</span>' ?></td>
                    <td class="px-3 py-3 text-center">
                        <div class="inline-flex items-center gap-1.5">
                            <div class="w-10 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                <div class="h-full rounded-full <?= $rs>=70?'bg-red-500':($rs>=40?'bg-yellow-500':'bg-green-500') ?>" style="width:<?= $rs ?>%"></div>
                            </div>
                            <span class="text-[10px] font-medium <?= $rs>=70?'text-red-600':'text-gray-500' ?>"><?= $rs ?></span>
                        </div>
                    </td>
                    <td class="px-3 py-3 text-center">
                        <?php if ($c['is_blocked']): ?>
                        <span class="px-2 py-0.5 text-[10px] rounded-full bg-red-100 text-red-700 font-semibold">Blocked</span>
                        <?php else: ?>
                        <span class="px-2 py-0.5 text-[10px] rounded-full bg-green-100 text-green-700 font-semibold">Active</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-3">
                        <div class="flex items-center justify-center gap-0.5">
                            <button onclick='openEdit(<?= $c["id"] ?>, <?= $safeJson ?>)' class="p-1.5 rounded hover:bg-blue-50" title="Edit"><i class="fas fa-pen text-xs text-blue-500"></i></button>
                            <a href="<?= adminUrl('pages/customer-view.php?id='.$c['id']) ?>" class="p-1.5 rounded hover:bg-gray-100" title="View"><i class="fas fa-eye text-xs text-gray-400"></i></a>
                            <form method="POST" class="inline"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><input type="hidden" name="current_tab" value="<?= $tab ?>">
                                <?php if ($c['is_blocked']): ?>
                                <input type="hidden" name="action" value="unblock"><button class="p-1.5 rounded hover:bg-green-50" title="Unblock"><i class="fas fa-unlock text-xs text-green-500"></i></button>
                                <?php else: ?>
                                <input type="hidden" name="action" value="block"><button onclick="return confirm('Block this customer?')" class="p-1.5 rounded hover:bg-red-50" title="Block"><i class="fas fa-ban text-xs text-red-400"></i></button>
                                <?php endif; ?>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete permanently? Orders will be unlinked.')"><input type="hidden" name="customer_id" value="<?= $c['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="current_tab" value="<?= $tab ?>"><button class="p-1.5 rounded hover:bg-red-50" title="Delete"><i class="fas fa-trash text-xs text-red-300"></i></button></form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between px-4 py-3 border-t">
        <p class="text-sm text-gray-500">Page <?= $page ?>/<?= $totalPages ?> — <?= count($customers) ?> of <?= number_format($total) ?></p>
        <div class="flex gap-1">
            <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="px-3 py-1.5 text-sm rounded bg-gray-100 hover:bg-gray-200">‹</a><?php endif; ?>
            <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>" class="px-3 py-1.5 text-sm rounded <?= $i===$page?'bg-blue-600 text-white':'bg-gray-100 hover:bg-gray-200' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="px-3 py-1.5 text-sm rounded bg-gray-100 hover:bg-gray-200">›</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ─── Edit Modal ─── -->
<div id="editMdl" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" onclick="if(event.target===this)closeEdit()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg">
        <div class="flex items-center justify-between p-5 border-b">
            <h3 class="font-bold text-gray-800"><i class="fas fa-user-edit mr-2 text-blue-500"></i>Edit Customer</h3>
            <button onclick="closeEdit()" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <input type="hidden" name="action" value="update_single">
            <input type="hidden" name="current_tab" value="<?= $tab ?>">
            <input type="hidden" name="customer_id" id="eId">
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-xs font-medium text-gray-600 mb-1">Name</label><input type="text" name="edit_name" id="eName" class="w-full px-3 py-2 border rounded-lg text-sm" required></div>
                <div><label class="block text-xs font-medium text-gray-600 mb-1">Phone</label><input type="text" name="edit_phone" id="ePhone" class="w-full px-3 py-2 border rounded-lg text-sm" required></div>
                <div class="col-span-2"><label class="block text-xs font-medium text-gray-600 mb-1">Email</label><input type="email" name="edit_email" id="eEmail" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                <div class="col-span-2"><label class="block text-xs font-medium text-gray-600 mb-1">Address</label><textarea name="edit_address" id="eAddr" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"></textarea></div>
                <div><label class="block text-xs font-medium text-gray-600 mb-1">City</label><input type="text" name="edit_city" id="eCity" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                <div><label class="block text-xs font-medium text-gray-600 mb-1">District</label><input type="text" name="edit_district" id="eDist" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                <div class="col-span-2"><label class="block text-xs font-medium text-gray-600 mb-1">Admin Notes</label><textarea name="edit_notes" id="eNotes" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"></textarea></div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeEdit()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm font-semibold">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700"><i class="fas fa-save mr-1"></i>Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Add Customer Modal ─── -->
<div id="addMdl" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" onclick="if(event.target===this)closeAdd()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg">
        <div class="flex items-center justify-between p-5 border-b">
            <h3 class="font-bold text-gray-800"><i class="fas fa-user-plus mr-2 text-indigo-500"></i>New Customer</h3>
            <button onclick="closeAdd()" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <input type="hidden" name="action" value="add_customer">
            <input type="hidden" name="current_tab" value="<?= $tab ?>">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Name <span class="text-red-500">*</span></label>
                    <input type="text" name="new_name" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Customer name" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Phone <span class="text-red-500">*</span></label>
                    <input type="tel" name="new_phone" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="01XXXXXXXXX" required>
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                    <input type="email" name="new_email" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="optional">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Password <span class="text-gray-400 font-normal">(optional — leave blank for guest account)</span></label>
                    <input type="password" name="new_password" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Min 6 characters" minlength="6">
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeAdd()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm font-semibold">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700"><i class="fas fa-user-plus mr-1"></i>Create Customer</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden bulk form -->
<form id="bForm" method="POST" class="hidden">
    <input type="hidden" name="action" id="bAct">
    <input type="hidden" name="selected_ids" id="bIds">
    <input type="hidden" name="current_tab" value="<?= $tab ?>">
</form>

<script>
function getIds(){return [...document.querySelectorAll('.rcb:checked')].map(c=>c.value)}
function updBar(){const n=getIds().length;document.getElementById('selCnt').textContent=n;document.getElementById('bulkBar').classList.toggle('hidden',n===0);document.getElementById('allCb').checked=n>0&&n===document.querySelectorAll('.rcb').length}
function togAll(cb){document.querySelectorAll('.rcb').forEach(c=>c.checked=cb.checked);updBar()}
function toggleSelAll(){const cbs=document.querySelectorAll('.rcb');const all=[...cbs].every(c=>c.checked);cbs.forEach(c=>c.checked=!all);document.getElementById('allCb').checked=!all;updBar()}
function clearSel(){document.querySelectorAll('.rcb').forEach(c=>c.checked=false);document.getElementById('allCb').checked=false;updBar()}

function doBulk(act){
    const ids=getIds();if(!ids.length)return alert('Select customers first');
    const lbl={bulk_block:'block',bulk_unblock:'unblock',bulk_delete:'permanently delete'};
    if(!confirm('Are you sure you want to '+lbl[act]+' '+ids.length+' customer(s)?'))return;
    document.getElementById('bAct').value=act;
    document.getElementById('bIds').value=ids.join(',');
    document.getElementById('bForm').submit();
}

function doExport(fmt){
    const ids=getIds();
    let url='<?= SITE_URL ?>/api/export-customers.php?format='+fmt+'&tab=<?= $tab ?>';
    if(ids.length)url+='&ids='+ids.join(',');
    const s='<?= addslashes($search) ?>';const f='<?= addslashes($filter) ?>';
    if(s)url+='&search='+encodeURIComponent(s);
    if(f)url+='&filter='+f;
    window.location.href=url;
}

function openEdit(id,d){
    try{if(typeof d==='string')d=JSON.parse(d)}catch(e){d={}}
    document.getElementById('eId').value=id;
    document.getElementById('eName').value=d.name||'';
    document.getElementById('ePhone').value=d.phone||'';
    document.getElementById('eEmail').value=d.email||'';
    document.getElementById('eAddr').value=d.address||'';
    document.getElementById('eCity').value=d.city||'';
    document.getElementById('eDist').value=d.district||'';
    document.getElementById('eNotes').value=d.notes||'';
    document.getElementById('editMdl').classList.remove('hidden');
}
function closeEdit(){document.getElementById('editMdl').classList.add('hidden')}
function openAdd(){document.getElementById('addMdl').classList.remove('hidden')}
function closeAdd(){document.getElementById('addMdl').classList.add('hidden')}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
