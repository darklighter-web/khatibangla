<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Customer Profile';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();
$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(adminUrl('pages/customers.php'));

$customer = $db->fetch("SELECT * FROM customers WHERE id = ?", [$id]);
if (!$customer) redirect(adminUrl('pages/customers.php'));

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_notes') {
        $db->update('customers', ['notes' => sanitize($_POST['notes'])], 'id = ?', [$id]);
        redirect(adminUrl("pages/customer-view.php?id=$id&msg=updated"));
    }
    if ($action === 'update_info') {
        $upd = [];
        foreach (['name','phone','email','address','city','district','alt_phone','postal_code'] as $f) {
            if (isset($_POST[$f])) $upd[$f] = trim($_POST[$f]);
        }
        if (!empty($upd)) $db->update('customers', $upd, 'id = ?', [$id]);
        redirect(adminUrl("pages/customer-view.php?id=$id&msg=updated"));
    }
    if ($action === 'block') {
        $db->update('customers', ['is_blocked' => 1], 'id = ?', [$id]);
        $existing = $db->fetch("SELECT id FROM blocked_phones WHERE phone = ?", [$customer['phone']]);
        if (!$existing) { try { $db->insert('blocked_phones', ['phone' => $customer['phone'], 'reason' => 'Blocked by admin', 'blocked_by' => getAdminId() ?: 1]); } catch(Exception $e) {} }
        redirect(adminUrl("pages/customer-view.php?id=$id&msg=blocked"));
    }
    if ($action === 'unblock') {
        $db->update('customers', ['is_blocked' => 0], 'id = ?', [$id]);
        $db->delete('blocked_phones', 'phone = ?', [$customer['phone']]);
        redirect(adminUrl("pages/customer-view.php?id=$id&msg=unblocked"));
    }
    if ($action === 'reset_password') {
        $newPass = sanitize($_POST['new_password'] ?? '');
        if (strlen($newPass) >= 4) {
            $db->update('customers', ['password' => hashPassword($newPass)], 'id = ?', [$id]);
            redirect(adminUrl("pages/customer-view.php?id=$id&msg=password_reset"));
        } else {
            redirect(adminUrl("pages/customer-view.php?id=$id&msg=pass_error"));
        }
    }
    if ($action === 'adjust_credit') {
        $creditType = $_POST['credit_type'] ?? 'add';
        $creditAmount = floatval($_POST['credit_amount'] ?? 0);
        $creditReason = sanitize($_POST['credit_reason'] ?? '');
        if ($creditAmount > 0) {
            $amount = $creditType === 'deduct' ? -$creditAmount : $creditAmount;
            $desc = $creditReason ?: ($creditType === 'add' ? 'Admin credit added' : 'Admin credit deducted');
            addStoreCredit($id, $amount, 'admin_adjust', null, null, $desc, getAdminId());
            logActivity(getAdminId(), 'adjust_credit', 'customers', $id, "{$creditType} {$creditAmount} credits");
        }
        redirect(adminUrl("pages/customer-view.php?id=$id&section=credits&msg=credit_updated"));
    }
}

// Reload customer
$customer = $db->fetch("SELECT * FROM customers WHERE id = ?", [$id]);
$isRegistered = !empty($customer['password']);

// ── Order Stats ──
$orderStats = $db->fetch("SELECT 
    COUNT(*) as total_orders,
    SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered,
    SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
    SUM(CASE WHEN order_status = 'returned' THEN 1 ELSE 0 END) as returned,
    SUM(CASE WHEN order_status IN ('pending','confirmed','processing','shipped') THEN 1 ELSE 0 END) as active,
    COALESCE(SUM(CASE WHEN order_status = 'delivered' THEN total ELSE 0 END), 0) as total_spent,
    COALESCE(AVG(CASE WHEN order_status = 'delivered' THEN total END), 0) as avg_order,
    MAX(created_at) as last_order_date
    FROM orders WHERE customer_id = ?", [$id]);

$successRate = $orderStats['total_orders'] > 0 ? round(($orderStats['delivered'] / $orderStats['total_orders']) * 100) : 0;

// ── Orders with item count ──
$orders = $db->fetchAll("SELECT o.*, 
    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count,
    (SELECT GROUP_CONCAT(oi.product_name SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) as items_list
    FROM orders o WHERE o.customer_id = ? ORDER BY o.created_at DESC LIMIT 50", [$id]);

// ── Incomplete Orders (by phone match) ──
$incompleteOrders = [];
try {
    $recCol = 'is_recovered';
    try { $db->fetch("SELECT is_recovered FROM incomplete_orders LIMIT 0"); } catch(\Throwable $e) { $recCol = 'recovered'; }
    $incompleteOrders = $db->fetchAll("SELECT * FROM incomplete_orders WHERE customer_phone = ? ORDER BY created_at DESC LIMIT 20", [$customer['phone']]);
} catch (\Throwable $e) {}

// ── Addresses from orders ──
$addresses = $db->fetchAll("SELECT DISTINCT customer_address, customer_city, customer_district FROM orders WHERE customer_id = ? AND customer_address IS NOT NULL AND customer_address != '' ORDER BY created_at DESC", [$id]);

// ── IP Addresses & Devices ──
// From orders
$orderIPs = $db->fetchAll("SELECT DISTINCT ip_address, user_agent, created_at FROM orders WHERE customer_id = ? AND ip_address IS NOT NULL AND ip_address != '' ORDER BY created_at DESC LIMIT 20", [$id]);

// From visitor_logs (by customer_id or phone)
$visitorSessions = [];
try {
    $visitorSessions = $db->fetchAll("SELECT v.* FROM visitor_logs v WHERE (v.customer_id = ? OR v.customer_phone = ?) ORDER BY v.created_at DESC LIMIT 30", [$id, $customer['phone']]);
} catch (\Throwable $e) {}

// Unique IPs across all sources
$allIPs = [];
foreach ($orderIPs as $oip) { if ($oip['ip_address']) $allIPs[$oip['ip_address']] = true; }
foreach ($visitorSessions as $vs) {
    if (!empty($vs['device_ip'])) $allIPs[$vs['device_ip']] = true;
    if (!empty($vs['network_ip'])) $allIPs[$vs['network_ip']] = true;
}
if (!empty($customer['ip_address'])) $allIPs[$customer['ip_address']] = true;

// ── Viewed Products ──
$viewedProducts = [];
try {
    $viewedProducts = $db->fetchAll("
        SELECT cpv.product_id, cpv.view_count, cpv.first_viewed_at, cpv.last_viewed_at, cpv.device_type,
               p.name as product_name, p.regular_price, p.sale_price, p.is_on_sale,
               (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as image
        FROM customer_page_views cpv
        JOIN products p ON p.id = cpv.product_id
        WHERE cpv.customer_id = ?
        ORDER BY cpv.last_viewed_at DESC LIMIT 30
    ", [$id]);
} catch (\Throwable $e) {}

// If no customer_id match, try by IPs
if (empty($viewedProducts) && !empty($allIPs)) {
    $ipList = array_keys($allIPs);
    $ph = implode(',', array_fill(0, count($ipList), '?'));
    try {
        $viewedProducts = $db->fetchAll("
            SELECT cpv.product_id, SUM(cpv.view_count) as view_count, MIN(cpv.first_viewed_at) as first_viewed_at, MAX(cpv.last_viewed_at) as last_viewed_at, cpv.device_type,
                   p.name as product_name, p.regular_price, p.sale_price, p.is_on_sale,
                   (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as image
            FROM customer_page_views cpv
            JOIN products p ON p.id = cpv.product_id
            WHERE cpv.ip_address IN ({$ph})
            GROUP BY cpv.product_id
            ORDER BY last_viewed_at DESC LIMIT 30
        ", $ipList);
    } catch (\Throwable $e) {}
}

// ── Other customers sharing same IP (fraud detection) ──
$sharedIPCustomers = [];
if (!empty($allIPs)) {
    $ipList = array_keys($allIPs);
    $ph = implode(',', array_fill(0, count($ipList), '?'));
    $sharedIPCustomers = $db->fetchAll("SELECT DISTINCT c.id, c.name, c.phone, c.total_orders, c.is_blocked
        FROM orders o JOIN customers c ON c.id = o.customer_id
        WHERE o.ip_address IN ({$ph}) AND c.id != ?
        ORDER BY c.total_orders DESC LIMIT 10", array_merge($ipList, [$id]));
}

require_once __DIR__ . '/../includes/header.php';
$msg = $_GET['msg'] ?? '';
$activeTab = $_GET['section'] ?? 'orders';
?>

<?php if ($msg): ?>
<div class="mb-4 p-3 rounded-xl text-sm flex items-center gap-2 <?= in_array($msg, ['pass_error']) ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-green-50 border border-green-200 text-green-700' ?>">
    <i class="fas fa-<?= in_array($msg, ['pass_error']) ? 'times-circle' : 'check-circle' ?>"></i>
    <?= match($msg) {
        'password_reset' => 'Password reset successfully.',
        'pass_error' => 'Password must be at least 4 characters.',
        'blocked' => 'Customer blocked.',
        'unblocked' => 'Customer unblocked.',
        'updated' => 'Customer info updated.',
        default => 'Done.'
    } ?>
</div>
<?php endif; ?>

<div class="mb-4 flex items-center justify-between">
    <a href="<?= adminUrl('pages/customers.php') ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium"><i class="fas fa-arrow-left mr-1"></i>Back to Customers</a>
    <div class="flex gap-2">
        <button onclick="document.getElementById('editInfoModal').classList.remove('hidden')" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-semibold hover:bg-blue-700"><i class="fas fa-pen mr-1"></i>Edit Info</button>
        <button onclick="document.getElementById('resetPassModal').classList.remove('hidden')" class="px-3 py-1.5 bg-yellow-500 text-white rounded-lg text-xs font-semibold hover:bg-yellow-600"><i class="fas fa-key mr-1"></i>Reset Password</button>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-5">
<!-- ════════════════ LEFT SIDEBAR ════════════════ -->
<div class="space-y-4">
    <!-- Profile Card -->
    <div class="bg-white rounded-xl border shadow-sm p-5">
        <div class="text-center mb-4">
            <div class="w-16 h-16 rounded-full flex items-center justify-center text-2xl font-bold mx-auto mb-3 <?= $isRegistered ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-500' ?>">
                <?= strtoupper(mb_substr($customer['name'], 0, 1)) ?>
            </div>
            <h3 class="font-bold text-lg text-gray-800"><?= e($customer['name']) ?></h3>
            <p class="text-sm text-gray-500"><?= e($customer['phone']) ?></p>
            <?php if ($customer['email']): ?><p class="text-sm text-gray-400"><?= e($customer['email']) ?></p><?php endif; ?>
            <div class="flex items-center justify-center gap-2 mt-2 flex-wrap">
                <?php if ($isRegistered): ?>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700"><i class="fas fa-user-check mr-1 text-[10px]"></i>Registered</span>
                <?php else: ?>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600"><i class="fas fa-user-tag mr-1 text-[10px]"></i>Guest</span>
                <?php endif; ?>
                <?php if ($customer['is_blocked']): ?>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700"><i class="fas fa-ban mr-1 text-[10px]"></i>Blocked</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Risk Score -->
        <div class="mb-4">
            <div class="flex justify-between text-xs text-gray-500 mb-1"><span>Risk Score</span><span class="font-semibold"><?= $customer['risk_score'] ?>%</span></div>
            <div class="w-full bg-gray-200 rounded-full h-2">
                <div class="h-2 rounded-full <?= $customer['risk_score'] >= 70 ? 'bg-red-500' : ($customer['risk_score'] >= 40 ? 'bg-yellow-500' : 'bg-green-500') ?>" style="width:<?= min(100, $customer['risk_score']) ?>%"></div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-3 gap-2 text-center mb-4">
            <div class="bg-gray-50 rounded-lg p-2.5">
                <p class="text-lg font-bold text-gray-800"><?= $orderStats['total_orders'] ?></p>
                <p class="text-[10px] text-gray-500">Orders</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-2.5">
                <p class="text-lg font-bold text-green-600"><?= $orderStats['delivered'] ?></p>
                <p class="text-[10px] text-gray-500">Delivered</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-2.5">
                <p class="text-lg font-bold text-red-500"><?= $orderStats['cancelled'] ?></p>
                <p class="text-[10px] text-gray-500">Cancelled</p>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-2 text-center mb-4">
            <div class="bg-gray-50 rounded-lg p-2.5">
                <p class="text-sm font-bold text-gray-800">৳<?= number_format($orderStats['total_spent']) ?></p>
                <p class="text-[10px] text-gray-500">Total Spent</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-2.5">
                <p class="text-sm font-bold text-gray-800">৳<?= number_format($orderStats['avg_order']) ?></p>
                <p class="text-[10px] text-gray-500">Avg Order</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-2.5">
                <p class="text-sm font-bold <?= $successRate >= 50 ? 'text-green-600' : 'text-red-500' ?>"><?= $successRate ?>%</p>
                <p class="text-[10px] text-gray-500">Success</p>
            </div>
        </div>

        <!-- Block/Unblock -->
        <form method="POST" <?= $customer['is_blocked'] ? '' : 'onsubmit="return confirm(\'Block this customer?\')"' ?>>
            <input type="hidden" name="action" value="<?= $customer['is_blocked'] ? 'unblock' : 'block' ?>">
            <button class="w-full px-4 py-2 rounded-lg text-sm font-semibold <?= $customer['is_blocked'] ? 'bg-green-600 hover:bg-green-700 text-white' : 'bg-red-600 hover:bg-red-700 text-white' ?>">
                <?= $customer['is_blocked'] ? '<i class="fas fa-unlock mr-1"></i>Unblock Customer' : '<i class="fas fa-ban mr-1"></i>Block Customer' ?>
            </button>
        </form>
    </div>

    <!-- Contact Info -->
    <div class="bg-white rounded-xl border shadow-sm p-4">
        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-id-card mr-1"></i>Contact Details</h4>
        <div class="space-y-2 text-sm">
            <div class="flex gap-2"><span class="text-gray-400 w-16 flex-shrink-0">Phone</span><span class="font-medium text-gray-700"><?= e($customer['phone']) ?></span></div>
            <?php if ($customer['alt_phone']): ?><div class="flex gap-2"><span class="text-gray-400 w-16 flex-shrink-0">Alt</span><span class="text-gray-700"><?= e($customer['alt_phone']) ?></span></div><?php endif; ?>
            <?php if ($customer['email']): ?><div class="flex gap-2"><span class="text-gray-400 w-16 flex-shrink-0">Email</span><span class="text-gray-700"><?= e($customer['email']) ?></span></div><?php endif; ?>
            <?php if ($customer['address']): ?><div class="flex gap-2"><span class="text-gray-400 w-16 flex-shrink-0">Address</span><span class="text-gray-700"><?= e($customer['address']) ?></span></div><?php endif; ?>
            <?php if ($customer['city']): ?><div class="flex gap-2"><span class="text-gray-400 w-16 flex-shrink-0">City</span><span class="text-gray-700"><?= e($customer['city']) ?></span></div><?php endif; ?>
            <?php if ($customer['district']): ?><div class="flex gap-2"><span class="text-gray-400 w-16 flex-shrink-0">District</span><span class="text-gray-700"><?= e($customer['district']) ?></span></div><?php endif; ?>
            <div class="flex gap-2"><span class="text-gray-400 w-16 flex-shrink-0">Since</span><span class="text-gray-700"><?= date('d M Y', strtotime($customer['created_at'])) ?></span></div>
            <?php if ($orderStats['last_order_date']): ?><div class="flex gap-2"><span class="text-gray-400 w-16 flex-shrink-0">Last</span><span class="text-gray-700"><?= date('d M Y', strtotime($orderStats['last_order_date'])) ?></span></div><?php endif; ?>
        </div>
    </div>

    <!-- Known IPs & Devices -->
    <div class="bg-white rounded-xl border shadow-sm p-4">
        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-fingerprint mr-1"></i>Known IPs & Devices <span class="text-gray-300">(<?= count($allIPs) ?>)</span></h4>
        <div class="space-y-2 max-h-[200px] overflow-y-auto" style="scrollbar-width:thin">
            <?php if (empty($allIPs)): ?>
            <p class="text-xs text-gray-400">No IP data recorded</p>
            <?php endif; ?>
            <?php foreach ($allIPs as $ip => $_): ?>
            <div class="flex items-center gap-2 text-xs bg-gray-50 rounded-lg px-3 py-2">
                <i class="fas fa-globe text-gray-400"></i>
                <code class="font-mono text-gray-700"><?= e($ip) ?></code>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($visitorSessions)): ?>
        <h5 class="text-[10px] font-semibold text-gray-400 uppercase mt-3 mb-2">Recent Devices</h5>
        <div class="space-y-1.5 max-h-[180px] overflow-y-auto" style="scrollbar-width:thin">
            <?php
            $seenDevices = [];
            foreach ($visitorSessions as $vs):
                $dKey = ($vs['browser'] ?? '') . '|' . ($vs['os'] ?? '') . '|' . ($vs['device_type'] ?? '');
                if (isset($seenDevices[$dKey])) continue;
                $seenDevices[$dKey] = true;
                $icon = match($vs['device_type'] ?? '') { 'mobile' => 'fa-mobile-alt', 'tablet' => 'fa-tablet-alt', 'bot' => 'fa-robot', default => 'fa-desktop' };
            ?>
            <div class="flex items-center gap-2 text-xs bg-gray-50 rounded-lg px-3 py-2">
                <i class="fas <?= $icon ?> text-gray-400"></i>
                <span class="text-gray-700"><?= e($vs['browser'] ?? '?') ?> / <?= e($vs['os'] ?? '?') ?></span>
                <span class="ml-auto text-gray-400"><?= ucfirst($vs['device_type'] ?? '') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Same IP Users (fraud) -->
    <?php if (!empty($sharedIPCustomers)): ?>
    <div class="bg-orange-50 rounded-xl border border-orange-200 p-4">
        <h4 class="text-xs font-semibold text-orange-700 uppercase tracking-wide mb-3"><i class="fas fa-exclamation-triangle mr-1"></i>Shared IP Customers</h4>
        <div class="space-y-2">
            <?php foreach ($sharedIPCustomers as $sc): ?>
            <a href="<?= adminUrl('pages/customer-view.php?id='.$sc['id']) ?>" class="flex items-center justify-between bg-white rounded-lg px-3 py-2 text-xs hover:bg-orange-100 transition">
                <div>
                    <span class="font-semibold text-gray-800"><?= e($sc['name']) ?></span>
                    <span class="text-gray-500 ml-1"><?= e($sc['phone']) ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-gray-500"><?= $sc['total_orders'] ?> orders</span>
                    <?php if ($sc['is_blocked']): ?><span class="text-red-600 font-semibold">Blocked</span><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Admin Notes -->
    <div class="bg-white rounded-xl border shadow-sm p-4">
        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-sticky-note mr-1"></i>Admin Notes</h4>
        <form method="POST">
            <input type="hidden" name="action" value="update_notes">
            <textarea name="notes" rows="3" class="border rounded-lg px-3 py-2 text-sm w-full mb-2 focus:outline-none focus:ring-2 focus:ring-blue-300"><?= e($customer['notes'] ?? '') ?></textarea>
            <button class="bg-gray-100 px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-gray-200">Save Notes</button>
        </form>
    </div>
</div>

<!-- ════════════════ MAIN CONTENT ════════════════ -->
<div class="lg:col-span-2 space-y-4">

    <!-- Sub-tabs -->
    <div class="flex gap-1 bg-white rounded-xl p-1 shadow-sm border">
        <?php foreach ([
            'orders' => ['Orders', 'fas fa-shopping-bag', count($orders)],
            'incomplete' => ['Incomplete', 'fas fa-cart-arrow-down', count($incompleteOrders)],
            'products' => ['Viewed Products', 'fas fa-eye', count($viewedProducts)],
            'sessions' => ['Visitor Sessions', 'fas fa-history', count($visitorSessions)],
            'credits' => ['Store Credits', 'fas fa-coins', number_format(getStoreCredit($customer['id'] ?? 0)) . ' credits'],
        ] as $tk => $tv): ?>
        <button onclick="switchTab('<?= $tk ?>')" id="tab_<?= $tk ?>"
                class="ctab flex-1 px-3 py-2 rounded-lg text-xs font-semibold transition <?= $activeTab === $tk ? 'bg-blue-600 text-white' : 'text-gray-500 hover:bg-gray-100' ?>">
            <i class="<?= $tv[1] ?> mr-1"></i><?= $tv[0] ?> <span class="opacity-70">(<?= $tv[2] ?>)</span>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ═══ Orders Tab ═══ -->
    <div id="panel_orders" class="ctab-panel <?= $activeTab !== 'orders' ? 'hidden' : '' ?>">
        <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Order</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                            <th class="text-right px-4 py-3 font-medium text-gray-600">Total</th>
                            <th class="text-center px-4 py-3 font-medium text-gray-600">Items</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Products</th>
                            <th class="text-center px-4 py-3 font-medium text-gray-600">Status</th>
                            <th class="text-center px-4 py-3 font-medium text-gray-600"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($orders as $order): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-blue-600">#<?= e($order['order_number']) ?></td>
                            <td class="px-4 py-3 text-gray-500 text-xs"><?= date('d M Y H:i', strtotime($order['created_at'])) ?></td>
                            <td class="px-4 py-3 text-right font-semibold">৳<?= number_format($order['total']) ?></td>
                            <td class="px-4 py-3 text-center"><?= $order['item_count'] ?? 0 ?></td>
                            <td class="px-4 py-3 text-xs text-gray-500 max-w-[200px] truncate"><?= e($order['items_list'] ?? '') ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="<?= getOrderStatusBadge($order['order_status']) ?> px-2 py-0.5 rounded-full text-xs"><?= getOrderStatusLabel($order['order_status']) ?></span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="<?= adminUrl('pages/order-view.php?id='.$order['id']) ?>" class="text-blue-600 hover:text-blue-800 text-xs font-medium">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($orders)): ?>
                        <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400"><i class="fas fa-shopping-bag text-3xl mb-2 block text-gray-200"></i>No orders yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══ Incomplete Orders Tab ═══ -->
    <div id="panel_incomplete" class="ctab-panel <?= $activeTab !== 'incomplete' ? 'hidden' : '' ?>">
        <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Step Reached</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Cart Items</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">IP</th>
                            <th class="text-center px-4 py-3 font-medium text-gray-600">Recovered</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($incompleteOrders as $inc):
                            $cartItems = json_decode($inc['cart_data'] ?? '[]', true) ?: [];
                            $recovered = ($inc['is_recovered'] ?? $inc['recovered'] ?? 0);
                            $stepColors = ['cart' => 'bg-gray-100 text-gray-600', 'info' => 'bg-blue-100 text-blue-700', 'shipping' => 'bg-yellow-100 text-yellow-700', 'payment' => 'bg-red-100 text-red-700'];
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-xs text-gray-500"><?= date('d M Y H:i', strtotime($inc['created_at'])) ?></td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold <?= $stepColors[$inc['step_reached']] ?? 'bg-gray-100 text-gray-600' ?>">
                                    <?= ucfirst($inc['step_reached'] ?? 'cart') ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <?php foreach (array_slice($cartItems, 0, 3) as $ci): ?>
                                <div class="truncate max-w-[200px]"><?= e($ci['name'] ?? 'Unknown') ?> × <?= $ci['quantity'] ?? 1 ?></div>
                                <?php endforeach; ?>
                                <?php if (count($cartItems) > 3): ?><div class="text-gray-400">+<?= count($cartItems) - 3 ?> more</div><?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-xs font-mono text-gray-500"><?= e($inc['ip_address'] ?? $inc['device_ip'] ?? '—') ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($recovered): ?>
                                <span class="text-green-600 text-xs font-semibold"><i class="fas fa-check-circle mr-0.5"></i>Yes</span>
                                <?php else: ?>
                                <span class="text-red-400 text-xs">No</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($incompleteOrders)): ?>
                        <tr><td colspan="5" class="px-4 py-12 text-center text-gray-400"><i class="fas fa-cart-arrow-down text-3xl mb-2 block text-gray-200"></i>No incomplete orders</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══ Viewed Products Tab ═══ -->
    <div id="panel_products" class="ctab-panel <?= $activeTab !== 'products' ? 'hidden' : '' ?>">
        <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
            <?php if (empty($viewedProducts)): ?>
            <div class="px-4 py-12 text-center text-gray-400"><i class="fas fa-eye text-3xl mb-2 block text-gray-200"></i>No product views tracked yet</div>
            <?php else: ?>
            <div class="divide-y divide-gray-100">
                <?php foreach ($viewedProducts as $vp):
                    $img = $vp['image'] ? SITE_URL . '/uploads/' . $vp['image'] : '';
                    $price = $vp['is_on_sale'] && $vp['sale_price'] ? $vp['sale_price'] : $vp['regular_price'];
                ?>
                <div class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
                    <div class="w-12 h-12 rounded-lg bg-gray-100 flex-shrink-0 overflow-hidden">
                        <?php if ($img): ?><img src="<?= $img ?>" class="w-full h-full object-cover" alt=""><?php else: ?><div class="w-full h-full flex items-center justify-center text-gray-300"><i class="fas fa-image"></i></div><?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-sm text-gray-800 truncate"><?= e($vp['product_name']) ?></p>
                        <p class="text-xs text-gray-400">First: <?= date('d M Y', strtotime($vp['first_viewed_at'])) ?> · Last: <?= date('d M Y', strtotime($vp['last_viewed_at'])) ?></p>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <p class="font-bold text-sm text-gray-800">৳<?= number_format($price) ?></p>
                        <p class="text-xs"><span class="font-semibold text-blue-600"><?= $vp['view_count'] ?>×</span> <span class="text-gray-400">viewed</span></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ Visitor Sessions Tab ═══ -->
    <div id="panel_sessions" class="ctab-panel <?= $activeTab !== 'sessions' ? 'hidden' : '' ?>">
        <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">IP</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Device</th>
                            <th class="text-center px-4 py-3 font-medium text-gray-600">Pages</th>
                            <th class="text-center px-4 py-3 font-medium text-gray-600">Cart</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Landing</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Referrer</th>
                            <th class="text-center px-4 py-3 font-medium text-gray-600">Order?</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($visitorSessions as $vs):
                            $dIcon = match($vs['device_type'] ?? '') { 'mobile' => 'fa-mobile-alt', 'tablet' => 'fa-tablet-alt', 'bot' => 'fa-robot', default => 'fa-desktop' };
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-xs text-gray-500"><?= date('d M H:i', strtotime($vs['created_at'])) ?></td>
                            <td class="px-4 py-3 text-xs font-mono"><?= e($vs['device_ip'] ?? '') ?></td>
                            <td class="px-4 py-3 text-xs">
                                <span class="inline-flex items-center gap-1"><i class="fas <?= $dIcon ?> text-gray-400"></i><?= e($vs['browser'] ?? '') ?>/<?= e($vs['os'] ?? '') ?></span>
                            </td>
                            <td class="px-4 py-3 text-center font-medium"><?= $vs['pages_viewed'] ?? 0 ?></td>
                            <td class="px-4 py-3 text-center"><?= $vs['cart_items'] ?? 0 ?></td>
                            <td class="px-4 py-3 text-xs text-gray-500 max-w-[120px] truncate"><?= e($vs['landing_page'] ?? '') ?></td>
                            <td class="px-4 py-3 text-xs text-gray-400 max-w-[120px] truncate"><?= e($vs['referrer'] ?? '—') ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($vs['order_placed'] ?? 0): ?>
                                <span class="text-green-600"><i class="fas fa-check-circle"></i></span>
                                <?php else: ?>
                                <span class="text-gray-300">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($visitorSessions)): ?>
                        <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400"><i class="fas fa-history text-3xl mb-2 block text-gray-200"></i>No visitor sessions found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Shipping Addresses -->
    <?php if (!empty($addresses)): ?>
    <div class="bg-white rounded-xl border shadow-sm p-4">
        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-map-marker-alt mr-1"></i>Shipping Addresses Used (<?= count($addresses) ?>)</h4>
        <div class="grid gap-2">
            <?php foreach ($addresses as $addr): ?>
            <div class="text-sm text-gray-600 bg-gray-50 rounded-lg p-3">
                <?= e($addr['customer_address']) ?><?= $addr['customer_city'] ? ', ' . e($addr['customer_city']) : '' ?><?= $addr['customer_district'] ? ', ' . e($addr['customer_district']) : '' ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ═══ Edit Info Modal ═══ -->
<div id="editInfoModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
        <div class="flex items-center justify-between p-5 border-b">
            <h3 class="font-bold text-gray-800"><i class="fas fa-user-edit mr-2 text-blue-500"></i>Edit Customer Info</h3>
            <button onclick="document.getElementById('editInfoModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <input type="hidden" name="action" value="update_info">
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-xs font-medium text-gray-600 mb-1">Name</label><input type="text" name="name" value="<?= e($customer['name']) ?>" class="w-full px-3 py-2 border rounded-lg text-sm" required></div>
                <div><label class="block text-xs font-medium text-gray-600 mb-1">Phone</label><input type="text" name="phone" value="<?= e($customer['phone']) ?>" class="w-full px-3 py-2 border rounded-lg text-sm" required></div>
                <div><label class="block text-xs font-medium text-gray-600 mb-1">Alt Phone</label><input type="text" name="alt_phone" value="<?= e($customer['alt_phone'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                <div><label class="block text-xs font-medium text-gray-600 mb-1">Email</label><input type="email" name="email" value="<?= e($customer['email'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                <div class="col-span-2"><label class="block text-xs font-medium text-gray-600 mb-1">Address</label><textarea name="address" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"><?= e($customer['address'] ?? '') ?></textarea></div>
                <div><label class="block text-xs font-medium text-gray-600 mb-1">City</label><input type="text" name="city" value="<?= e($customer['city'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                <div><label class="block text-xs font-medium text-gray-600 mb-1">District</label><input type="text" name="district" value="<?= e($customer['district'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('editInfoModal').classList.add('hidden')" class="px-4 py-2 bg-gray-100 rounded-lg text-sm font-semibold">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700"><i class="fas fa-save mr-1"></i>Save</button>
            </div>
        </form>
    </div>
</div>

    <!-- ═══ Store Credits Tab ═══ -->
    <div id="panel_credits" class="ctab-panel <?= $activeTab !== 'credits' ? 'hidden' : '' ?>">
        <?php
        $custId = $customer['id'] ?? 0;
        $creditBalance = getStoreCredit($custId);
        $creditTxns = getCreditTransactions($custId, 50);
        $isRegistered = !empty($customer['password']);
        $creditRate = floatval(getSetting('store_credit_conversion_rate', '0.75'));
        if ($creditRate <= 0) $creditRate = 0.75;
        $creditBalanceTk = round($creditBalance * $creditRate, 2);
        ?>
        <div class="space-y-4">
            <!-- Balance + Adjust -->
            <div class="grid md:grid-cols-2 gap-4">
                <div class="bg-gradient-to-r from-yellow-400 to-orange-400 rounded-xl p-5 text-white">
                    <p class="text-sm opacity-90">Current Balance</p>
                    <h2 class="text-3xl font-bold mt-1"><?= number_format($creditBalance, 0) ?> <span class="text-lg opacity-80">credits</span></h2>
                    <p class="text-sm opacity-80 mt-0.5">= ৳<?= number_format($creditBalanceTk, 0) ?> <span class="text-xs">(1 credit = ৳<?= $creditRate ?>)</span></p>
                    <?php if (!$isRegistered): ?>
                    <p class="text-xs mt-2 opacity-80 bg-white/20 inline-block px-2 py-0.5 rounded">Guest — cannot earn credits</p>
                    <?php endif; ?>
                </div>
                <?php if ($isRegistered): ?>
                <div class="bg-white rounded-xl border p-5">
                    <h4 class="font-semibold text-sm mb-3"><i class="fas fa-sliders-h text-purple-500 mr-1"></i>Adjust Credit</h4>
                    <form method="POST" action="<?= adminUrl('pages/customer-view.php?id=' . $custId . '&section=credits') ?>">
                        <input type="hidden" name="action" value="adjust_credit">
                        <div class="flex gap-2 mb-2">
                            <select name="credit_type" class="border rounded-lg px-3 py-2 text-sm flex-1">
                                <option value="add">+ Add Credit</option>
                                <option value="deduct">− Deduct Credit</option>
                            </select>
                            <input type="number" name="credit_amount" step="0.01" min="0.01" required placeholder="Credits" class="border rounded-lg px-3 py-2 text-sm w-32">
                        </div>
                        <input type="text" name="credit_reason" placeholder="Reason (optional)" class="w-full border rounded-lg px-3 py-2 text-sm mb-2">
                        <button type="submit" class="w-full bg-purple-600 text-white py-2 rounded-lg text-sm font-semibold hover:bg-purple-700">Apply</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Transaction History -->
            <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b bg-gray-50 flex items-center justify-between">
                    <h4 class="font-semibold text-sm">Transaction History</h4>
                    <span class="text-xs text-gray-500"><?= count($creditTxns) ?> transactions</span>
                </div>
                <?php if (empty($creditTxns)): ?>
                <div class="p-8 text-center text-gray-400"><i class="fas fa-receipt text-2xl mb-2"></i><p class="text-sm">No transactions yet</p></div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50"><tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Date</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Type</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Description</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500">Credits</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500">TK Value</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500">Balance</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 w-10"></th>
                        </tr></thead>
                        <tbody class="divide-y">
                        <?php foreach ($creditTxns as $tx):
                            $typeColors = ['earn'=>'bg-green-100 text-green-700','spend'=>'bg-blue-100 text-blue-700','refund'=>'bg-yellow-100 text-yellow-700','admin_adjust'=>'bg-purple-100 text-purple-700','expire'=>'bg-red-100 text-red-700'];
                            $typeLabels = ['earn'=>'Earned','spend'=>'Spent','refund'=>'Refund','admin_adjust'=>'Admin','expire'=>'Expired'];
                        ?>
                        <tr class="hover:bg-gray-50" id="credit-tx-<?= $tx['id'] ?>">
                            <td class="px-4 py-2 text-xs text-gray-500"><?= date('d M Y, h:i A', strtotime($tx['created_at'])) ?></td>
                            <td class="px-4 py-2"><span class="text-[10px] px-2 py-0.5 rounded-full font-medium <?= $typeColors[$tx['type']] ?? 'bg-gray-100 text-gray-700' ?>"><?= $typeLabels[$tx['type']] ?? $tx['type'] ?></span></td>
                            <td class="px-4 py-2 text-xs"><?= e($tx['description'] ?: '-') ?> <?php if ($tx['reference_type']): ?><span class="text-gray-400">(<?= $tx['reference_type'] ?> #<?= $tx['reference_id'] ?>)</span><?php endif; ?></td>
                            <td class="px-4 py-2 text-right font-semibold <?= $tx['amount'] > 0 ? 'text-green-600' : 'text-red-500' ?>"><?= $tx['amount'] > 0 ? '+' : '' ?><?= number_format($tx['amount'], 2) ?></td>
                            <td class="px-4 py-2 text-right text-xs text-gray-400">৳<?= number_format(abs($tx['amount']) * $creditRate, 0) ?></td>
                            <td class="px-4 py-2 text-right text-xs text-gray-500"><?= number_format($tx['balance_after'], 2) ?></td>
                            <td class="px-4 py-2 text-center">
                                <button onclick="deleteCreditTx(<?= $tx['id'] ?>, <?= $custId ?>)" class="text-gray-300 hover:text-red-500 transition" title="Delete transaction">
                                    <i class="fas fa-trash-alt text-[10px]"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<!-- ═══ Reset Password Modal ═══ -->
<div id="resetPassModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
        <h3 class="font-bold text-lg mb-1">Reset Password</h3>
        <p class="text-sm text-gray-500 mb-4">For <strong><?= e($customer['name']) ?></strong> (<?= e($customer['phone']) ?>)</p>
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <div class="relative">
                    <input type="text" name="new_password" id="newPassInput" required minlength="4" class="w-full border rounded-lg px-3 py-2.5 text-sm pr-20" placeholder="Min 4 characters">
                    <button type="button" onclick="genPass()" class="absolute right-1 top-1 bg-gray-100 hover:bg-gray-200 text-xs px-2.5 py-1.5 rounded-md text-gray-600">Generate</button>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-yellow-500 text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-yellow-600">Reset</button>
                <button type="button" onclick="document.getElementById('resetPassModal').classList.add('hidden')" class="px-4 py-2.5 bg-gray-100 rounded-lg text-sm hover:bg-gray-200">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(t) {
    document.querySelectorAll('.ctab').forEach(b => { b.classList.remove('bg-blue-600','text-white'); b.classList.add('text-gray-500'); });
    document.querySelectorAll('.ctab-panel').forEach(p => p.classList.add('hidden'));
    document.getElementById('tab_' + t).classList.add('bg-blue-600','text-white');
    document.getElementById('tab_' + t).classList.remove('text-gray-500');
    document.getElementById('panel_' + t).classList.remove('hidden');
}
function genPass() {
    const c = '0123456789abcdefghijklmnopqrstuvwxyz';
    let p = '';
    for (let i = 0; i < 6; i++) p += c.charAt(Math.floor(Math.random() * c.length));
    document.getElementById('newPassInput').value = p;
}
function deleteCreditTx(txId, custId) {
    if (!confirm('Delete this credit transaction? The balance will be adjusted automatically.')) return;
    fetch('<?= SITE_URL ?>/api/store-credit.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete_transaction&transaction_id=' + txId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('credit-tx-' + txId)?.remove();
            alert('Transaction deleted. New balance: ' + data.new_balance + ' credits');
            location.reload();
        } else {
            alert(data.message || 'Failed to delete');
        }
    })
    .catch(() => alert('Network error'));
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
