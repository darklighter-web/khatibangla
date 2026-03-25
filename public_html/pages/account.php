<?php
/**
 * Customer Account Dashboard
 */
$pageTitle = 'আমার একাউন্ট';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/clerk.php';
requireCustomer();

$db = Database::getInstance();
$customer = getCustomer();
$tab = $_GET['tab'] ?? 'overview';
$msg = $_GET['msg'] ?? '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $db->update('customers', [
            'name' => sanitize($_POST['name']),
            'email' => sanitize($_POST['email']),
            'alt_phone' => sanitize($_POST['alt_phone']),
            'address' => sanitize($_POST['address']),
            'city' => sanitize($_POST['city']),
            'district' => sanitize($_POST['district']),
        ], 'id = ?', [$customer['id']]);
        $_SESSION['customer_name'] = sanitize($_POST['name']);
        redirect(url('account?tab=profile&msg=updated'));
    }
    if ($action === 'change_password') {
        $current = $_POST['current_password'];
        $newPass = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];
        if (!verifyPassword($current, $customer['password'])) { redirect(url('account?tab=profile&msg=wrong_password')); }
        elseif (strlen($newPass) < 6) { redirect(url('account?tab=profile&msg=short_password')); }
        elseif ($newPass !== $confirm) { redirect(url('account?tab=profile&msg=mismatch')); }
        else { $db->update('customers', ['password' => hashPassword($newPass)], 'id = ?', [$customer['id']]); redirect(url('account?tab=profile&msg=password_changed')); }
    }
    if ($action === 'save_address') {
        $addrData = ['customer_id'=>$customer['id'],'label'=>sanitize($_POST['label']),'name'=>sanitize($_POST['addr_name']),'phone'=>sanitize($_POST['addr_phone']),'address'=>sanitize($_POST['address_line']),'city'=>sanitize($_POST['addr_city']),'area'=>sanitize($_POST['addr_area'])];
        if ($_POST['addr_id'] ?? '') { $db->update('customer_addresses', $addrData, 'id = ? AND customer_id = ?', [(int)$_POST['addr_id'], $customer['id']]); }
        else { $db->insert('customer_addresses', $addrData); }
        redirect(url('account?tab=addresses&msg=saved'));
    }
    if ($action === 'delete_address') { $db->delete('customer_addresses', 'id = ? AND customer_id = ?', [(int)$_POST['addr_id'], $customer['id']]); redirect(url('account?tab=addresses&msg=deleted')); }
    if ($action === 'set_default_address') {
        $addrId = (int)$_POST['addr_id'];
        $db->query("UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?", [$customer['id']]);
        $db->query("UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?", [$addrId, $customer['id']]);
        // Also update the customer's main address from this default
        $defAddr = $db->fetch("SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ?", [$addrId, $customer['id']]);
        if ($defAddr) {
            $db->update('customers', [
                'address' => $defAddr['address'],
                'city' => $defAddr['city'] ?? '',
            ], 'id = ?', [$customer['id']]);
        }
        redirect(url('account?tab=addresses&msg=default_set'));
    }
    if ($action === 'delete_account') {
        $password = $_POST['confirm_delete_password'] ?? '';
        if (empty($password) || !verifyPassword($password, $customer['password'])) { redirect(url('account?tab=profile&msg=wrong_delete_password')); }
        else {
            $cid = $customer['id'];
            $db->delete('customer_addresses', 'customer_id = ?', [$cid]);
            $db->delete('wishlists', 'customer_id = ?', [$cid]);
            $db->query("UPDATE orders SET customer_name='Deleted User', customer_email='', customer_id=0 WHERE customer_id=?", [$cid]);
            $db->delete('customers', 'id = ?', [$cid]);
            customerLogout(); redirect(url('login'));
        }
    }
}

// ── Identify customer ──
$custId = $customer['id'];
$custPhone = $customer['phone'];

// ── Fetch Data (ID + phone) ──
$orders = $db->fetchAll("SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count FROM orders o WHERE o.customer_id = ? OR o.customer_phone = ? ORDER BY o.created_at DESC LIMIT 50", [$custId, $custPhone]);
$addresses = $db->fetchAll("SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id DESC", [$custId]);
// Load retention reminders for current customer
$retentionItems = [];
if ($tab === 'reminders' || $tab === 'overview') {
    try {
        $retentionItems = $db->fetchAll("
            SELECT crl.*, p.name as prod_name, p.featured_image as prod_img,
                   p.retention_value, p.retention_unit,
                   o.order_number,
                   DATEDIFF(CURDATE(), crl.due_date) as days_overdue,
                   DATEDIFF(crl.due_date, CURDATE()) as days_remaining
            FROM customer_retention_log crl
            JOIN products p ON p.id = crl.product_id
            JOIN orders o ON o.id = crl.order_id
            WHERE crl.customer_id = ? AND crl.segment IN ('overdue','due_soon','at_risk')
              AND crl.status NOT IN ('converted','not_interested')
            ORDER BY crl.due_date ASC
            LIMIT 20
        ", [$custId]);
    } catch (\Throwable $e) { $retentionItems = []; }
}

$wishlist = $db->fetchAll("SELECT p.*, w.id as wishlist_id, (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as image FROM wishlists w JOIN products p ON p.id = w.product_id WHERE w.customer_id = ? ORDER BY w.created_at DESC", [$custId]);

// ── Returns ──
$returns = [];
try {
    $orderIds = array_column($orders, 'id');
    if (!empty($orderIds)) {
        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $returns = $db->fetchAll("SELECT r.*, o.order_number, o.total as order_total FROM return_orders r JOIN orders o ON o.id = r.order_id WHERE r.order_id IN ($ph) ORDER BY r.created_at DESC", $orderIds);
    }
} catch (\Throwable $e) {}
$refundCount = count($returns);

// ── Metrics ──
$metrics = $db->fetch("SELECT COUNT(*) as total_orders, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN order_status='returned' THEN 1 ELSE 0 END) as returned, SUM(CASE WHEN order_status='delivered' THEN total ELSE 0 END) as total_spent, SUM(CASE WHEN order_status IN ('pending','confirmed','processing','shipped') THEN 1 ELSE 0 END) as active_orders FROM orders WHERE customer_id = ? OR customer_phone = ?", [$custId, $custPhone]);
if (!$metrics) $metrics = ['total_orders'=>0,'delivered'=>0,'cancelled'=>0,'returned'=>0,'total_spent'=>0,'active_orders'=>0];

// Credit: compute from transactions for accuracy, sync column if mismatch
$creditBalance = getStoreCredit($custId);
try {
    $txnBalance = $db->fetch("SELECT COALESCE(SUM(amount), 0) as bal FROM store_credit_transactions WHERE customer_id = ?", [$custId]);
    $txnBal = floatval($txnBalance['bal'] ?? 0);
    if (abs($creditBalance - $txnBal) > 0.01) {
        $creditBalance = max(0, $txnBal);
        $db->query("UPDATE customers SET store_credit = ? WHERE id = ?", [$creditBalance, $custId]);
    }
} catch (\Throwable $e) {}
$creditRate = floatval(getSetting('store_credit_conversion_rate', '0.75'));
if ($creditRate <= 0) $creditRate = 0.75;
$creditBalanceTk = round($creditBalance * $creditRate, 2);

$customer = getCustomer();
$memberDays = max(1, floor((time() - strtotime($customer['created_at'])) / 86400));

// Pending reviews count
$pendingReviewCount = 0;
try {
    $__prCount = $db->fetch(
        "SELECT COUNT(DISTINCT oi.product_id) as cnt FROM orders o 
         JOIN order_items oi ON oi.order_id = o.id
         WHERE (o.customer_id = ? OR o.customer_phone = ?)
         AND o.order_status = 'delivered'
         AND oi.product_id NOT IN (SELECT product_id FROM product_reviews WHERE customer_id = ? AND is_dummy = 0)",
        [$custId, $custPhone, $custId]
    );
    $pendingReviewCount = intval($__prCount['cnt'] ?? 0);
} catch (\Throwable $e) {}

$seo = ['type' => 'website', 'noindex' => true];
require_once __DIR__ . '/../includes/header.php';

$statusBadges = [
    'pending'=>['প্রসেসিং','bg-yellow-100 text-yellow-800','fa-cog'],'confirmed'=>['কনফার্মড','bg-blue-100 text-blue-800','fa-check'],
    'processing'=>['প্রসেসিং','bg-indigo-100 text-indigo-800','fa-cog'],'shipped'=>['শিপড','bg-purple-100 text-purple-800','fa-truck'],
    'delivered'=>['ডেলিভারড','bg-green-100 text-green-800','fa-check-double'],'cancelled'=>['ক্যান্সেলড','bg-red-100 text-red-800','fa-times'],
    'returned'=>['রিটার্নড','bg-orange-100 text-orange-800','fa-undo'],'on_hold'=>['অন হোল্ড','bg-gray-100 text-gray-800','fa-pause'],
    'pending_return'=>['রিটার্ন পেন্ডিং','bg-amber-100 text-amber-800','fa-sync'],'pending_cancel'=>['ক্যান্সেল পেন্ডিং','bg-pink-100 text-pink-800','fa-hourglass-half'],
    'partial_delivered'=>['আংশিক ডেলিভারি','bg-cyan-100 text-cyan-800','fa-box'],'lost'=>['লস্ট','bg-stone-100 text-stone-800','fa-exclamation-triangle'],
];

$messages = ['updated'=>['প্রোফাইল আপডেট হয়েছে।','green'],'password_changed'=>['পাসওয়ার্ড পরিবর্তন হয়েছে।','green'],'wrong_password'=>['বর্তমান পাসওয়ার্ড ভুল।','red'],'mismatch'=>['নতুন পাসওয়ার্ড মিলছে না।','red'],'short_password'=>['পাসওয়ার্ড কমপক্ষে ৬ অক্ষর।','red'],'wrong_delete_password'=>['পাসওয়ার্ড ভুল।','red'],'saved'=>['সেভ হয়েছে।','green'],'deleted'=>['মুছে ফেলা হয়েছে।','green']];
?>

<div class="max-w-5xl mx-auto px-4 py-6">

<!-- Profile Header -->
<div class="bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900 rounded-2xl p-5 mb-6 text-white relative overflow-hidden">
    <div class="absolute top-0 right-0 w-48 h-48 bg-white/5 rounded-full -translate-y-1/2 translate-x-1/4"></div>
    <div class="relative flex items-center justify-between">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-white/10 backdrop-blur flex items-center justify-center text-2xl font-bold border border-white/20"><?= mb_strtoupper(mb_substr($customer['name'], 0, 1)) ?></div>
            <div>
                <h1 class="text-lg font-bold"><?= e($customer['name']) ?></h1>
                <p class="text-sm text-white/60"><?= e($customer['phone']) ?><?php if ($customer['email']) echo ' · ' . e($customer['email']); ?></p>
                <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                    <span class="text-[10px] bg-white/10 px-2 py-0.5 rounded-full text-white/70 font-mono"><i class="fas fa-id-badge mr-1"></i>ID: #<?= $custId ?></span>
                    <span class="text-[10px] bg-white/10 px-2 py-0.5 rounded-full text-white/70"><i class="fas fa-calendar-alt mr-1"></i><?= $memberDays ?> দিন</span>
                    <?php if ($creditBalance >= 1) echo '<span class="text-[10px] bg-yellow-500/20 text-yellow-300 px-2 py-0.5 rounded-full"><i class="fas fa-coins mr-1"></i>' . number_format($creditBalance, 0) . ' ক্রেডিট</span>'; ?>
                </div>
            </div>
        </div>
        <?php if (isClerkSession()): ?>
        <a href="#" onclick="event.preventDefault();clerkSignOut()" class="text-sm text-white/50 hover:text-white transition hidden sm:block"><i class="fas fa-sign-out-alt mr-1"></i>লগআউট</a>
        <?php else: ?>
        <a href="<?= url('login?action=logout') ?>" class="text-sm text-white/50 hover:text-white transition hidden sm:block"><i class="fas fa-sign-out-alt mr-1"></i>লগআউট</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg && isset($messages[$msg])): $m = $messages[$msg]; ?>
<div class="mb-4 p-3 bg-<?= $m[1] ?>-50 border border-<?= $m[1] ?>-200 text-<?= $m[1] ?>-700 rounded-xl text-sm flex items-center gap-2"><i class="fas fa-<?= $m[1]==='green' ? 'check-circle' : 'exclamation-circle' ?>"></i><?= $m[0] ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="flex gap-1 mb-6 bg-gray-100/80 rounded-2xl p-1.5 overflow-x-auto" style="-webkit-overflow-scrolling:touch;scrollbar-width:none;">
<?php
$tabs = ['overview'=>['ড্যাশবোর্ড','fa-th-large'],'orders'=>['অর্ডার','fa-shopping-bag'],'reviews'=>['রিভিউ','fa-star'],'returns'=>['রিটার্ন','fa-undo-alt'],'credits'=>['ক্রেডিট','fa-coins'],'wishlist'=>['উইশলিস্ট','fa-heart'],'reminders'=>['রিমাইন্ডার 🔔','fa-bell'],'addresses'=>['ঠিকানা','fa-map-marker-alt'],'profile'=>['প্রোফাইল','fa-user-cog']];
foreach ($tabs as $key => $t): ?>
<a href="<?= url('account?tab=' . $key) ?>" class="px-4 py-2.5 text-sm font-medium rounded-xl whitespace-nowrap transition-all <?= $tab === $key ? 'bg-white shadow-sm text-gray-800 ring-1 ring-gray-200' : 'text-gray-500 hover:text-gray-700 hover:bg-white/50' ?>">
    <i class="fas <?= $t[1] ?> mr-1.5 text-xs"></i><?= $t[0] ?>
    <?php if ($key === 'orders' && $metrics['active_orders'] > 0) echo '<span class="ml-1 text-[10px] bg-red-500 text-white w-4 h-4 inline-flex items-center justify-center rounded-full">' . $metrics['active_orders'] . '</span>'; ?>
    <?php if ($key === 'reviews' && $pendingReviewCount > 0) echo '<span class="ml-1 text-[10px] bg-yellow-500 text-white px-1.5 py-0.5 rounded-full">' . $pendingReviewCount . '</span>'; ?>
    <?php if ($key === 'returns' && $refundCount > 0) echo '<span class="ml-1 text-[10px] bg-orange-500 text-white px-1.5 py-0.5 rounded-full">' . $refundCount . '</span>'; ?>
</a>
<?php endforeach; ?>
</div>

<?php
// ════════════════════════════════════════
// Using if/elseif/endif — ONE block, no nesting issues
// ════════════════════════════════════════

if ($tab === 'overview'):
    $successRate = $metrics['total_orders'] > 0 ? round(($metrics['delivered'] / $metrics['total_orders']) * 100) : 0;
    $metricCards = [
        ['মোট অর্ডার', intval($metrics['total_orders']), 'fa-shopping-bag', 'bg-blue-50 text-blue-500'],
        ['ডেলিভারড', intval($metrics['delivered']), 'fa-check-double', 'bg-green-50 text-green-500'],
        ['মোট খরচ', formatPrice(intval($metrics['total_spent'])), 'fa-wallet', 'bg-emerald-50 text-emerald-600'],
        ['ক্যান্সেলড', intval($metrics['cancelled']), 'fa-times-circle', 'bg-red-50 text-red-500'],
        ['রিটার্ন', intval($metrics['returned']) + $refundCount, 'fa-undo-alt', 'bg-orange-50 text-orange-500'],
        ['ক্রেডিট', number_format($creditBalance, 0), 'fa-coins', 'bg-yellow-50 text-yellow-500'],
    ];
?>
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
<?php foreach ($metricCards as $mc): ?>
<div class="bg-white rounded-2xl border p-4 flex flex-col items-center text-center transition hover:shadow-md hover:-translate-y-0.5">
    <div class="w-10 h-10 rounded-xl <?= $mc[3] ?> flex items-center justify-center mb-2 text-lg"><i class="fas <?= $mc[2] ?>"></i></div>
    <span class="text-xl font-extrabold text-gray-800 leading-none"><?= $mc[1] ?></span>
    <span class="text-[11px] text-gray-400 mt-1 font-medium uppercase tracking-wide"><?= $mc[0] ?></span>
</div>
<?php endforeach; ?>
</div>
<div class="grid sm:grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded-2xl border p-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-chart-line text-green-500 mr-1.5"></i>সফলতার হার</h3>
        <div class="flex items-end gap-3 mb-3"><span class="text-4xl font-extrabold text-gray-800"><?= $successRate ?>%</span><span class="text-sm text-gray-400 mb-1"><?= $metrics['delivered'] ?>/<?= $metrics['total_orders'] ?></span></div>
        <div class="w-full h-2.5 bg-gray-100 rounded-full overflow-hidden"><div class="h-full rounded-full <?= $successRate >= 70 ? 'bg-green-500' : ($successRate >= 40 ? 'bg-yellow-500' : 'bg-red-500') ?>" style="width:<?= $successRate ?>%"></div></div>
    </div>
    <div class="bg-gradient-to-br from-yellow-400 to-orange-400 rounded-2xl p-5 text-white relative overflow-hidden">
        <div class="absolute top-0 right-0 w-24 h-24 bg-white/10 rounded-full -translate-y-1/3 translate-x-1/3"></div>
        <h3 class="text-sm font-semibold opacity-90 mb-1"><i class="fas fa-coins mr-1.5"></i>স্টোর ক্রেডিট</h3>
        <div class="flex items-end gap-2 mb-1"><span class="text-3xl font-extrabold"><?= number_format($creditBalance, 0) ?></span><span class="text-sm opacity-80 mb-0.5">ক্রেডিট</span></div>
        <p class="text-sm opacity-75">= ৳<?= number_format($creditBalanceTk, 0) ?> সমপরিমাণ</p>
        <?php if ($creditBalance >= 1) echo '<a href="' . url('account?tab=credits') . '" class="mt-3 inline-block text-xs bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-lg transition">বিস্তারিত →</a>'; ?>
    </div>
</div>
<div class="bg-white rounded-2xl border shadow-sm">
    <div class="px-5 py-4 border-b flex items-center justify-between"><h3 class="font-semibold text-sm"><i class="fas fa-clock text-blue-500 mr-1.5"></i>সাম্প্রতিক অর্ডার</h3><a href="<?= url('account?tab=orders') ?>" class="text-xs text-blue-600 font-medium">সব দেখুন →</a></div>
<?php if (empty($orders)): ?>
    <div class="p-10 text-center text-gray-400"><i class="fas fa-shopping-bag text-3xl mb-2 opacity-50"></i><p>এখনো কোনো অর্ডার নেই</p></div>
<?php else: ?>
    <div class="divide-y">
    <?php foreach (array_slice($orders, 0, 5) as $o): $sb = $statusBadges[$o['order_status']] ?? ['অজানা','bg-gray-100 text-gray-600','fa-question']; ?>
    <a href="<?= url('track-order?q=' . e($o['order_number'])) ?>" class="px-5 py-3.5 flex items-center justify-between hover:bg-gray-50 transition block">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-9 h-9 rounded-xl <?= $sb[1] ?> flex items-center justify-center flex-shrink-0"><i class="fas <?= $sb[2] ?> text-xs"></i></div>
            <div><p class="text-sm font-semibold">#<?= e($o['order_number']) ?></p><p class="text-[11px] text-gray-400"><?= date('d M Y', strtotime($o['created_at'])) ?> · <?= intval($o['item_count'] ?? 0) ?> আইটেম</p></div>
        </div>
        <div class="text-right flex-shrink-0 flex items-center gap-3">
            <div><p class="text-sm font-bold"><?= formatPrice($o['total']) ?></p><span class="text-[10px] px-2 py-0.5 rounded-full font-medium <?= $sb[1] ?>"><?= $sb[0] ?></span></div>
            <i class="fas fa-chevron-right text-xs text-gray-300"></i>
        </div>
    </a>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<?php if (!empty($retentionItems)): ?>
<div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-3">
    <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2">
            <i class="fas fa-bell text-amber-500"></i>
            <span class="font-semibold text-amber-800 text-sm"><?= count($retentionItems) ?> টি রিঅর্ডার রিমাইন্ডার</span>
        </div>
        <a href="<?= url('account?tab=reminders') ?>" class="text-xs text-amber-700 font-semibold hover:underline">সব দেখুন →</a>
    </div>
    <div class="flex flex-wrap gap-2">
    <?php foreach (array_slice($retentionItems, 0, 3) as $ri): ?>
        <a href="<?= url('product/' . $ri['product_id']) ?>" class="flex items-center gap-2 bg-white border border-amber-200 rounded-lg px-2.5 py-1.5 text-xs hover:border-amber-400 transition">
            <?php if ($ri['prod_img']): ?><img src="<?= imgSrc('products',$ri['prod_img']) ?>" class="w-5 h-5 rounded object-cover"><?php endif; ?>
            <span class="font-medium text-gray-700"><?= e(mb_strimwidth($ri['prod_name'],0,25,'...')) ?></span>
        </a>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'orders'): ?>
<?php if (empty($orders)): ?>
<div class="text-center py-16 bg-white rounded-2xl shadow-sm border"><i class="fas fa-shopping-bag text-4xl text-gray-200 mb-3"></i><p class="text-gray-400 text-lg mb-2">কোনো অর্ডার নেই</p><a href="<?= url() ?>" class="text-blue-600 text-sm font-medium">শপিং শুরু করুন →</a></div>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($orders as $o): $sb = $statusBadges[$o['order_status']] ?? ['অজানা','bg-gray-100 text-gray-600','fa-question']; ?>
<a href="<?= url('track-order?q=' . e($o['order_number'])) ?>" class="block bg-white rounded-2xl border shadow-sm p-4 hover:shadow-md transition group">
    <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-lg <?= $sb[1] ?> flex items-center justify-center flex-shrink-0"><i class="fas <?= $sb[2] ?> text-[11px]"></i></div>
            <div><span class="font-bold text-sm">#<?= e($o['order_number']) ?></span><span class="text-xs text-gray-400 ml-2"><?= date('d M Y, h:i A', strtotime($o['created_at'])) ?></span></div>
        </div>
        <span class="text-xs px-3 py-1 rounded-full font-medium <?= $sb[1] ?>"><?= $sb[0] ?></span>
    </div>
    <div class="flex items-center justify-between">
        <span class="text-sm text-gray-500"><?= intval($o['item_count'] ?? 0) ?> আইটেম</span>
        <div class="flex items-center gap-3"><span class="font-bold text-sm"><?= formatPrice($o['total']) ?></span><i class="fas fa-chevron-right text-xs text-gray-300 group-hover:text-blue-500 transition"></i></div>
    </div>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($tab === 'returns'): ?>
<?php if (empty($returns)): ?>
<div class="text-center py-16 bg-white rounded-2xl shadow-sm border"><i class="fas fa-undo-alt text-4xl text-gray-200 mb-3"></i><p class="text-gray-400 text-lg mb-2">কোনো রিটার্ন/রিফান্ড নেই</p></div>
<?php else:
    $rsLabels = ['requested'=>['অনুরোধ','bg-yellow-100 text-yellow-800','fa-clock'],'approved'=>['অনুমোদিত','bg-blue-100 text-blue-800','fa-check'],'picked_up'=>['পিক আপ','bg-indigo-100 text-indigo-800','fa-truck'],'received'=>['গ্রহণ','bg-purple-100 text-purple-800','fa-box-open'],'refunded'=>['রিফান্ড সম্পন্ন','bg-green-100 text-green-800','fa-check-double'],'rejected'=>['প্রত্যাখ্যাত','bg-red-100 text-red-800','fa-times']];
    $rfLabels = ['pending'=>['পেন্ডিং','bg-yellow-100 text-yellow-700'],'processed'=>['প্রসেসিং','bg-blue-100 text-blue-700'],'completed'=>['সম্পন্ন','bg-green-100 text-green-700']];
?>
<div class="space-y-3">
<?php foreach ($returns as $ret):
    $rsb = $rsLabels[$ret['return_status']] ?? ['অজানা','bg-gray-100 text-gray-600','fa-question'];
    $rfb = $rfLabels[$ret['refund_status']] ?? ['অজানা','bg-gray-100 text-gray-600'];
?>
<div class="bg-white rounded-2xl border shadow-sm p-4 hover:shadow-md transition">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2.5">
            <div class="w-9 h-9 rounded-xl <?= $rsb[1] ?> flex items-center justify-center flex-shrink-0"><i class="fas <?= $rsb[2] ?> text-xs"></i></div>
            <div><span class="font-bold text-sm">অর্ডার #<?= e($ret['order_number']) ?></span><span class="text-xs text-gray-400 ml-2"><?= date('d M Y', strtotime($ret['created_at'])) ?></span></div>
        </div>
        <span class="text-xs px-3 py-1 rounded-full font-medium <?= $rsb[1] ?>"><?= $rsb[0] ?></span>
    </div>
    <div class="bg-gray-50 rounded-xl p-3 space-y-2 text-sm">
        <?php if (!empty($ret['return_reason'])): ?><div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">কারণ:</span><span class="text-gray-700"><?= e($ret['return_reason']) ?></span></div><?php endif; ?>
        <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">রিফান্ড:</span><span class="font-semibold"><?= formatPrice($ret['refund_amount'] ?: 0) ?></span>
        <?php if (!empty($ret['refund_method'])) echo '<span class="text-xs bg-gray-200 text-gray-600 px-2 py-0.5 rounded-full">' . e($ret['refund_method']) . '</span>'; ?></div>
        <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">স্ট্যাটাস:</span><span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $rfb[1] ?>"><?= $rfb[0] ?></span></div>
    </div>
    <div class="mt-3 flex items-center justify-between text-xs text-gray-400">
        <span>অর্ডার মূল্য: <?= formatPrice($ret['order_total']) ?></span>
        <a href="<?= url('track-order?q=' . e($ret['order_number'])) ?>" class="text-blue-600 font-medium">ট্র্যাক →</a>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($tab === 'credits'):
    $creditTxns = getCreditTransactions($custId, 50);
    $totalEarned = 0; $totalSpent = 0; $totalRefunded = 0;
    foreach ($creditTxns as $tx) { if ($tx['type']==='earn') $totalEarned+=$tx['amount']; elseif ($tx['type']==='spend') $totalSpent+=abs($tx['amount']); elseif ($tx['type']==='refund') $totalRefunded+=$tx['amount']; }
?>
<div class="space-y-4">
    <div class="bg-gradient-to-r from-yellow-400 via-amber-400 to-orange-400 rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute top-0 right-0 w-40 h-40 bg-white/10 rounded-full -translate-y-1/2 translate-x-1/4"></div>
        <div class="relative">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center"><i class="fas fa-coins text-2xl"></i></div>
                <div><p class="text-sm opacity-90">স্টোর ক্রেডিট ব্যালেন্স</p><h2 class="text-3xl font-bold"><?= number_format($creditBalance, 0) ?> <span class="text-lg opacity-80">ক্রেডিট</span></h2><p class="text-sm opacity-80">= ৳<?= number_format($creditBalanceTk, 0) ?> <span class="text-xs opacity-70">(১ ক্রেডিট = ৳<?= $creditRate ?>)</span></p></div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div class="bg-white/15 rounded-xl p-3 text-center"><p class="text-xs opacity-80">অর্জিত</p><p class="font-bold text-lg">+<?= number_format($totalEarned, 0) ?></p></div>
                <div class="bg-white/15 rounded-xl p-3 text-center"><p class="text-xs opacity-80">ব্যবহৃত</p><p class="font-bold text-lg">-<?= number_format($totalSpent, 0) ?></p></div>
                <div class="bg-white/15 rounded-xl p-3 text-center"><p class="text-xs opacity-80">রিফান্ড</p><p class="font-bold text-lg">+<?= number_format($totalRefunded, 0) ?></p></div>
            </div>
        </div>
    </div>
    <div class="bg-yellow-50 border border-yellow-200 rounded-2xl p-4">
        <h4 class="font-semibold text-sm text-yellow-800 mb-2"><i class="fas fa-info-circle mr-1"></i> কীভাবে কাজ করে?</h4>
        <ul class="text-xs text-yellow-700 space-y-1.5">
            <li><i class="fas fa-check-circle text-green-500 mr-1"></i> ডেলিভারি হলে ক্রেডিট যোগ হয়</li>
            <li><i class="fas fa-check-circle text-green-500 mr-1"></i> ক্যান্সেল হলে ক্রেডিট ফেরত আসে</li>
            <li><i class="fas fa-check-circle text-green-500 mr-1"></i> চেকআউটে ছাড় (১ ক্রেডিট = ৳<?= $creditRate ?>)</li>
        </ul>
    </div>
    <div class="bg-white rounded-2xl border shadow-sm">
        <div class="px-5 py-4 border-b flex items-center justify-between"><h3 class="font-semibold text-sm"><i class="fas fa-history text-gray-400 mr-1.5"></i>লেনদেনের ইতিহাস</h3><span class="text-xs text-gray-400"><?= count($creditTxns) ?> লেনদেন</span></div>
<?php if (empty($creditTxns)): ?>
        <div class="p-10 text-center text-gray-400"><i class="fas fa-receipt text-3xl mb-2 opacity-50"></i><p>কোনো লেনদেন হয়নি</p></div>
<?php else: ?>
        <div class="divide-y max-h-[500px] overflow-y-auto">
<?php foreach ($creditTxns as $tx):
    $isP = $tx['amount'] > 0;
    $tl = ['earn'=>['অর্জিত','bg-green-100 text-green-700','fa-arrow-down'],'spend'=>['ব্যবহৃত','bg-blue-100 text-blue-700','fa-arrow-up'],'refund'=>['রিফান্ড','bg-yellow-100 text-yellow-700','fa-undo'],'admin_adjust'=>['অ্যাডজাস্ট','bg-purple-100 text-purple-700','fa-sliders-h'],'expire'=>['মেয়াদ শেষ','bg-red-100 text-red-700','fa-clock']][$tx['type']] ?? ['অন্যান্য','bg-gray-100 text-gray-700','fa-circle'];
?>
        <div class="flex items-center justify-between px-5 py-3.5 hover:bg-gray-50">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-full <?= $tl[1] ?> flex items-center justify-center flex-shrink-0"><i class="fas <?= $tl[2] ?> text-xs"></i></div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2"><p class="text-sm font-medium"><?= $tl[0] ?></p><span class="text-[10px] <?= $tl[1] ?> px-1.5 py-0.5 rounded-full"><?= ucfirst($tx['type']) ?></span></div>
                    <p class="text-xs text-gray-400 truncate max-w-[250px]"><?= e($tx['description'] ?: '-') ?></p>
                    <p class="text-[10px] text-gray-300"><?= date('d M Y, h:i A', strtotime($tx['created_at'])) ?></p>
                </div>
            </div>
            <div class="text-right flex-shrink-0">
                <p class="font-bold text-sm <?= $isP ? 'text-green-600' : 'text-red-500' ?>"><?= $isP ? '+' : '-' ?><?= number_format(abs($tx['amount']), 0) ?> ক্রেডিট</p>
                <p class="text-[10px] text-gray-400">৳<?= number_format(abs($tx['amount']) * $creditRate, 0) ?></p>
            </div>
        </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'wishlist'): ?>
<?php if (empty($wishlist)): ?>
<div class="text-center py-16 bg-white rounded-2xl shadow-sm border"><i class="fas fa-heart text-4xl text-gray-200 mb-3"></i><p class="text-gray-400 text-lg mb-2">উইশলিস্ট খালি</p><a href="<?= url() ?>" class="text-blue-600 text-sm font-medium">পণ্য দেখুন →</a></div>
<?php else: ?>
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
<?php foreach ($wishlist as $p): ?>
<div class="bg-white rounded-2xl border shadow-sm overflow-hidden group hover:shadow-md transition">
    <a href="<?= url('product/' . $p['slug']) ?>" class="block">
    <?php if ($p['image']): ?><img src="<?= imgSrc('products', $p['image']) ?>" class="w-full aspect-square object-cover group-hover:scale-105 transition duration-300" alt="" loading="lazy">
    <?php else: ?><div class="w-full aspect-square bg-gray-100 flex items-center justify-center text-gray-300"><i class="fas fa-image text-3xl"></i></div><?php endif; ?>
    </a>
    <div class="p-3">
        <a href="<?= url('product/' . $p['slug']) ?>" class="text-sm font-medium text-gray-800 hover:text-blue-600 line-clamp-2"><?= e($p['name_bn'] ?: $p['name']) ?></a>
        <p class="font-bold text-sm mt-1" style="color:var(--primary)"><?= formatPrice(getProductPrice($p)) ?></p>
        <form method="POST" action="<?= url('api/wishlist.php') ?>" class="mt-2"><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?= $p['id'] ?>"><input type="hidden" name="redirect" value="<?= url('account?tab=wishlist') ?>"><button class="text-xs text-red-400 hover:text-red-600"><i class="fas fa-trash-alt mr-1"></i>সরিয়ে ফেলুন</button></form>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($tab === 'reminders'): ?>
<div class="space-y-3">
    <h3 class="font-bold text-gray-800 text-base flex items-center gap-2">
        <i class="fas fa-bell text-amber-500"></i> রিঅর্ডার রিমাইন্ডার
    </h3>
    <?php if (empty($retentionItems)): ?>
    <div class="bg-green-50 border border-green-200 rounded-xl p-6 text-center">
        <div class="text-3xl mb-2">🎉</div>
        <div class="font-semibold text-green-700">সব পণ্য ঠিকঠাক আছে!</div>
        <div class="text-xs text-green-600 mt-1">আপনার কোনো পণ্যের রিঅর্ডার এখন প্রয়োজন নেই।</div>
    </div>
    <?php else: ?>
    <p class="text-xs text-gray-500">আপনার <?= count($retentionItems) ?> টি পণ্যের রিঅর্ডারের সময় হয়েছে বা হওয়ার পথে।</p>
    <?php foreach ($retentionItems as $ri):
        $daysNum = (int)$ri['days_overdue'];
        $daysRem = (int)$ri['days_remaining'];
    ?>
    <div class="bg-white border rounded-xl p-4 flex items-start gap-3 <?= $ri['segment']==='overdue'?'border-red-200 bg-red-50/30':($ri['segment']==='due_soon'?'border-amber-200 bg-amber-50/30':'border-orange-200 bg-orange-50/20') ?>">
        <?php if ($ri['prod_img']): ?>
        <img src="<?= imgSrc('products', $ri['prod_img']) ?>" class="w-14 h-14 rounded-lg object-cover shrink-0">
        <?php else: ?>
        <div class="w-14 h-14 bg-gray-100 rounded-lg flex items-center justify-center shrink-0 text-2xl">📦</div>
        <?php endif; ?>
        <div class="flex-1 min-w-0">
            <div class="font-semibold text-gray-800 text-sm"><?= e($ri['prod_name']) ?></div>
            <div class="text-[10px] text-gray-400 mt-0.5">অর্ডার: <?= e($ri['order_number']) ?></div>
            <?php if ($ri['segment'] === 'overdue'): ?>
            <div class="mt-1.5 text-xs font-semibold text-red-600">⚠️ <?= $daysNum ?> দিন আগে শেষ হওয়ার কথা ছিল</div>
            <?php elseif ($ri['segment'] === 'due_soon'): ?>
            <div class="mt-1.5 text-xs font-semibold text-amber-600">🕐 <?= $daysRem ?> দিনের মধ্যে শেষ হবে</div>
            <?php else: ?>
            <div class="mt-1.5 text-xs font-semibold text-orange-500">🟠 <?= date('d M Y', strtotime($ri['due_date'])) ?> তারিখের মধ্যে শেষ হতে পারে</div>
            <?php endif; ?>
            <a href="<?= url('product/' . $ri['product_id']) ?>" class="mt-2 inline-block text-xs bg-blue-600 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700 font-medium">পুনরায় অর্ডার করুন →</a>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'addresses'): ?>
<div class="grid sm:grid-cols-2 gap-4 mb-4">
<?php foreach ($addresses as $addr): ?>
<div class="bg-white rounded-2xl border shadow-sm p-4 relative hover:shadow-md transition">
    <?php if ($addr['is_default']) echo '<span class="absolute top-3 right-3 text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium"><i class="fas fa-check mr-0.5"></i>ডিফল্ট</span>'; ?>
    <div class="flex items-center gap-2 mb-2"><span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-xs"><i class="fas fa-map-pin"></i></span><p class="font-semibold text-sm"><?= e($addr['label']) ?></p></div>
    <p class="text-sm text-gray-600"><?= e($addr['name']) ?> · <?= e($addr['phone']) ?></p>
    <p class="text-sm text-gray-500 mt-1"><?= e($addr['address']) ?></p>
    <p class="text-sm text-gray-500"><?= e($addr['area']) ?>, <?= e($addr['city']) ?></p>
    <div class="mt-3 flex gap-3 pt-3 border-t">
        <?php if (!$addr['is_default']): ?>
        <form method="POST" class="inline"><input type="hidden" name="action" value="set_default_address"><input type="hidden" name="addr_id" value="<?= $addr['id'] ?>"><button class="text-xs text-green-600 font-medium"><i class="fas fa-check-circle mr-1"></i>ডিফল্ট</button></form>
        <?php endif; ?>
        <button onclick='editAddress(<?= json_encode($addr) ?>)' class="text-xs text-blue-600 font-medium"><i class="fas fa-pen mr-1"></i>এডিট</button>
        <form method="POST" class="inline"><input type="hidden" name="action" value="delete_address"><input type="hidden" name="addr_id" value="<?= $addr['id'] ?>"><button onclick="return confirm('মুছে ফেলতে চান?')" class="text-xs text-red-400"><i class="fas fa-trash-alt mr-1"></i>মুছুন</button></form>
    </div>
</div>
<?php endforeach; ?>
</div>
<div class="bg-white rounded-2xl border shadow-sm p-5" id="address-form">
    <h3 class="font-semibold text-sm mb-4" id="addr-form-title"><i class="fas fa-plus-circle text-blue-500 mr-1.5"></i>নতুন ঠিকানা</h3>
    <form method="POST"><input type="hidden" name="action" value="save_address"><input type="hidden" name="addr_id" id="addr_id" value="">
    <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="block text-xs font-medium mb-1 text-gray-600">লেবেল</label><select name="label" id="addr_label" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"><option>Home</option><option>Office</option><option>Other</option></select></div>
        <div><label class="block text-xs font-medium mb-1 text-gray-600">নাম</label><input type="text" name="addr_name" id="addr_name" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none" value="<?= e($customer['name']) ?>"></div>
        <div><label class="block text-xs font-medium mb-1 text-gray-600">ফোন</label><input type="text" name="addr_phone" id="addr_phone" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none" value="<?= e($customer['phone']) ?>"></div>
        <div><label class="block text-xs font-medium mb-1 text-gray-600">শহর</label><select name="addr_city" id="addr_city" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"><option>Dhaka</option><option>Outside Dhaka</option></select></div>
        <div class="sm:col-span-2"><label class="block text-xs font-medium mb-1 text-gray-600">ঠিকানা *</label><textarea name="address_line" id="addr_address" rows="2" required class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"></textarea></div>
        <div><label class="block text-xs font-medium mb-1 text-gray-600">এলাকা</label><input type="text" name="addr_area" id="addr_area" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"></div>
    </div>
    <button type="submit" class="mt-4 btn-primary px-6 py-2.5 rounded-xl text-sm font-semibold">সেভ করুন</button>
    </form>
</div>
<script>function editAddress(a){document.getElementById('addr_id').value=a.id;document.getElementById('addr_label').value=a.label;document.getElementById('addr_name').value=a.name;document.getElementById('addr_phone').value=a.phone;document.getElementById('addr_address').value=a.address;document.getElementById('addr_city').value=a.city;document.getElementById('addr_area').value=a.area||'';document.getElementById('addr-form-title').innerHTML='<i class="fas fa-pen text-blue-500 mr-1.5"></i>ঠিকানা এডিট';document.getElementById('address-form').scrollIntoView({behavior:'smooth'});}</script>

<?php elseif ($tab === 'reviews'):
    // Fetch products eligible for review
    $reviewableProducts = [];
    try {
        $reviewableProducts = $db->fetchAll(
            "SELECT DISTINCT p.id, p.name, p.name_bn, p.slug, p.featured_image, p.sale_price, p.regular_price
             FROM orders o 
             JOIN order_items oi ON oi.order_id = o.id
             JOIN products p ON p.id = oi.product_id
             WHERE (o.customer_id = ? OR o.customer_phone = ?)
             AND o.order_status = 'delivered'
             AND p.id NOT IN (SELECT product_id FROM product_reviews WHERE customer_id = ? AND is_dummy = 0)
             ORDER BY o.updated_at DESC", [$custId, $custPhone, $custId]
        );
    } catch (\Throwable $e) {}
    
    // Fetch user's submitted reviews
    $myReviews = [];
    try {
        $myReviews = $db->fetchAll(
            "SELECT r.*, p.name as product_name, p.name_bn as product_name_bn, p.slug as product_slug, p.featured_image
             FROM product_reviews r 
             LEFT JOIN products p ON p.id = r.product_id
             WHERE r.customer_id = ? AND r.is_dummy = 0
             ORDER BY r.created_at DESC", [$custId]
        );
    } catch (\Throwable $e) {}
    
    $reviewCredit = intval(getSetting('review_credit_reward', '50'));
?>

<!-- Pending Reviews -->
<?php if (!empty($reviewableProducts)): ?>
<div class="bg-gradient-to-r from-yellow-50 to-orange-50 border border-yellow-200 rounded-2xl p-5 mb-6">
    <div class="flex items-center gap-3 mb-3">
        <div class="w-10 h-10 bg-yellow-100 rounded-xl flex items-center justify-center"><i class="fas fa-star text-yellow-500 text-lg"></i></div>
        <div>
            <h3 class="font-bold text-gray-800">রিভিউ দিন, ক্রেডিট পান!</h3>
            <p class="text-xs text-gray-500"><?= count($reviewableProducts) ?>টি পণ্যে রিভিউ দেওয়া বাকি আছে <?php if ($reviewCredit > 0): ?>· প্রতিটি রিভিউতে <strong class="text-green-600"><?= $reviewCredit ?> ক্রেডিট</strong> পাবেন<?php endif; ?></p>
        </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <?php foreach ($reviewableProducts as $rp): ?>
        <a href="<?= url('product/' . $rp['slug']) ?>#tab-reviews" class="flex items-center gap-3 bg-white rounded-xl p-3 border border-yellow-100 hover:shadow-md hover:-translate-y-0.5 transition group">
            <?php if ($rp['featured_image']): ?>
            <img src="<?= imgSrc('products', $rp['featured_image']) ?>" class="w-14 h-14 object-cover rounded-lg border" alt="" loading="lazy">
            <?php else: ?>
            <div class="w-14 h-14 bg-gray-100 rounded-lg flex items-center justify-center text-gray-300"><i class="fas fa-image"></i></div>
            <?php endif; ?>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 line-clamp-1 group-hover:text-blue-600"><?= e($rp['name_bn'] ?: $rp['name']) ?></p>
                <p class="text-xs text-gray-400 mt-0.5">ক্লিক করে রিভিউ দিন</p>
            </div>
            <div class="shrink-0 text-yellow-500"><i class="fas fa-pen text-sm"></i></div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- My Reviews -->
<h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-comment-dots text-blue-500 mr-1.5"></i>আমার রিভিউ</h3>
<?php if (empty($myReviews)): ?>
<div class="text-center py-12 bg-white rounded-2xl shadow-sm border">
    <i class="far fa-comment-dots text-4xl text-gray-200 mb-3"></i>
    <p class="text-gray-400 text-lg mb-1">আপনি এখনো কোনো রিভিউ দেননি</p>
    <p class="text-sm text-gray-400">পণ্য ডেলিভারি পাওয়ার পর রিভিউ দিতে পারবেন</p>
</div>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($myReviews as $mr): ?>
<div class="bg-white rounded-2xl border shadow-sm p-4 hover:shadow-md transition">
    <div class="flex items-start gap-3">
        <?php if ($mr['featured_image']): ?>
        <a href="<?= url('product/' . ($mr['product_slug'] ?? '')) ?>"><img src="<?= imgSrc('products', $mr['featured_image']) ?>" class="w-14 h-14 object-cover rounded-lg border" alt="" loading="lazy"></a>
        <?php endif; ?>
        <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between gap-2 mb-1">
                <a href="<?= url('product/' . ($mr['product_slug'] ?? '')) ?>" class="text-sm font-semibold text-gray-800 hover:text-blue-600 line-clamp-1"><?= e($mr['product_name_bn'] ?: $mr['product_name'] ?? 'পণ্য') ?></a>
                <?php if ($mr['is_approved']): ?>
                <span class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded-full whitespace-nowrap"><i class="fas fa-check mr-0.5"></i>প্রকাশিত</span>
                <?php else: ?>
                <span class="text-[10px] bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full whitespace-nowrap"><i class="fas fa-clock mr-0.5"></i>অনুমোদন বাকি</span>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-1 mb-1">
                <?php for ($i=1; $i<=5; $i++): ?><span class="text-sm <?= $i <= $mr['rating'] ? 'text-yellow-400' : 'text-gray-300' ?>">★</span><?php endfor; ?>
                <span class="text-[11px] text-gray-400 ml-1"><?= date('d M Y', strtotime($mr['created_at'])) ?></span>
            </div>
            <p class="text-sm text-gray-600 line-clamp-2"><?= e($mr['review_text']) ?></p>
            <?php $imgs = json_decode($mr['images'] ?? '[]', true); if (!empty($imgs)): ?>
            <div class="flex gap-1.5 mt-2"><?php foreach ($imgs as $img): ?><img src="<?= SITE_URL . $img ?>" class="w-10 h-10 object-cover rounded-md border"><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($mr['credit_awarded']): ?>
            <div class="mt-1.5 text-[11px] text-green-600"><i class="fas fa-coins mr-0.5"></i>ক্রেডিট পেয়েছেন</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($tab === 'profile'): ?>
<div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-2xl border shadow-sm p-5">
        <h3 class="font-semibold text-sm mb-4"><i class="fas fa-user text-blue-500 mr-1.5"></i>প্রোফাইল তথ্য</h3>
        <form method="POST"><input type="hidden" name="action" value="update_profile">
        <div class="space-y-3">
            <div><label class="block text-xs font-medium mb-1 text-gray-600">নাম *</label><input type="text" name="name" value="<?= e($customer['name']) ?>" required class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"></div>
            <div><label class="block text-xs font-medium mb-1 text-gray-600">ফোন</label><input type="text" value="<?= e($customer['phone']) ?>" disabled class="border rounded-xl px-3 py-2.5 text-sm w-full bg-gray-50 text-gray-500"></div>
            <div><label class="block text-xs font-medium mb-1 text-gray-600">ইমেইল</label><input type="email" name="email" value="<?= e($customer['email']) ?>" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"></div>
            <div><label class="block text-xs font-medium mb-1 text-gray-600">বিকল্প ফোন</label><input type="text" name="alt_phone" value="<?= e($customer['alt_phone']) ?>" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"></div>
            <div><label class="block text-xs font-medium mb-1 text-gray-600">ঠিকানা</label><textarea name="address" rows="2" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"><?= e($customer['address']) ?></textarea></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-xs font-medium mb-1 text-gray-600">শহর</label><input type="text" name="city" value="<?= e($customer['city']) ?>" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"></div>
                <div><label class="block text-xs font-medium mb-1 text-gray-600">জেলা</label><input type="text" name="district" value="<?= e($customer['district']) ?>" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"></div>
            </div>
        </div>
        <button type="submit" class="mt-4 btn-primary px-6 py-2.5 rounded-xl text-sm font-semibold">আপডেট করুন</button>
        </form>
    </div>
    <div class="space-y-6">
        <div class="bg-white rounded-2xl border shadow-sm p-5">
            <h3 class="font-semibold text-sm mb-4"><i class="fas fa-lock text-gray-500 mr-1.5"></i>পাসওয়ার্ড পরিবর্তন</h3>
            <form method="POST"><input type="hidden" name="action" value="change_password">
            <div class="space-y-3">
                <div><label class="block text-xs font-medium mb-1 text-gray-600">বর্তমান পাসওয়ার্ড</label><input type="password" name="current_password" required class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"></div>
                <div><label class="block text-xs font-medium mb-1 text-gray-600">নতুন পাসওয়ার্ড</label><input type="password" name="new_password" required minlength="6" class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"></div>
                <div><label class="block text-xs font-medium mb-1 text-gray-600">নিশ্চিত করুন</label><input type="password" name="confirm_password" required class="border rounded-xl px-3 py-2.5 text-sm w-full outline-none"></div>
            </div>
            <button type="submit" class="mt-4 bg-gray-800 text-white px-6 py-2.5 rounded-xl text-sm font-semibold hover:bg-gray-900">পরিবর্তন করুন</button>
            </form>
        </div>
        <div class="bg-white rounded-2xl border shadow-sm p-5">
            <h4 class="font-semibold text-sm mb-3 text-gray-700"><i class="fas fa-info-circle text-blue-400 mr-1.5"></i>একাউন্ট তথ্য</h4>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="bg-gray-50 rounded-xl p-3 text-center"><p class="text-xs text-gray-400">কাস্টমার ID</p><p class="font-bold text-lg font-mono">#<?= $custId ?></p></div>
                <div class="bg-gray-50 rounded-xl p-3 text-center"><p class="text-xs text-gray-400">মোট অর্ডার</p><p class="font-bold text-lg"><?= intval($metrics['total_orders']) ?></p></div>
                <div class="bg-gray-50 rounded-xl p-3 text-center"><p class="text-xs text-gray-400">মোট খরচ</p><p class="font-bold text-lg"><?= formatPrice(intval($metrics['total_spent'])) ?></p></div>
                <div class="bg-gray-50 rounded-xl p-3 text-center"><p class="text-xs text-gray-400">যোগদান</p><p class="font-bold text-sm"><?= date('d M Y', strtotime($customer['created_at'])) ?></p></div>
                <div class="bg-gray-50 rounded-xl p-3 text-center"><p class="text-xs text-gray-400">রিটার্ন</p><p class="font-bold text-lg text-orange-600"><?= $refundCount ?></p></div>
                <div class="bg-gray-50 rounded-xl p-3 text-center"><p class="text-xs text-gray-400">ক্রেডিট</p><p class="font-bold text-lg text-yellow-600"><?= number_format($creditBalance, 0) ?></p></div>
            </div>
        </div>
        <div class="bg-red-50 border border-red-200 rounded-2xl p-5">
            <h4 class="font-semibold text-sm mb-2 text-red-600"><i class="fas fa-exclamation-triangle mr-1"></i>একাউন্ট ডিলিট</h4>
            <p class="text-xs text-red-400 mb-3">এই কাজটি ফেরানো যাবে না।</p>
            <button onclick="document.getElementById('delete-account-modal').classList.remove('hidden')" class="bg-white border border-red-300 text-red-600 px-4 py-2 rounded-xl text-sm font-medium hover:bg-red-50">একাউন্ট ডিলিট করুন</button>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if (isClerkSession()): ?>
<div class="sm:hidden mt-6 text-center"><a href="#" onclick="event.preventDefault();clerkSignOut()" class="text-sm text-red-500 font-medium"><i class="fas fa-sign-out-alt mr-1"></i>লগআউট</a></div>
<?php else: ?>
<div class="sm:hidden mt-6 text-center"><a href="<?= url('login?action=logout') ?>" class="text-sm text-red-500 font-medium"><i class="fas fa-sign-out-alt mr-1"></i>লগআউট</a></div>
<?php endif; ?>
</div>

<!-- Delete Modal -->
<div id="delete-account-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="this.parentElement.classList.add('hidden')"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
        <button onclick="this.closest('#delete-account-modal').classList.add('hidden')" class="absolute top-3 right-3 text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        <div class="text-center mb-5"><div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-3"><i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i></div><h3 class="text-lg font-bold">একাউন্ট ডিলিট করতে চান?</h3><p class="text-sm text-gray-500 mt-1">সকল ডেটা মুছে যাবে।</p></div>
        <form method="POST"><input type="hidden" name="action" value="delete_account">
            <div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-1">পাসওয়ার্ড দিন</label><input type="password" name="confirm_delete_password" required class="w-full border border-red-300 rounded-xl px-4 py-3 text-sm outline-none" placeholder="আপনার পাসওয়ার্ড"></div>
            <div class="flex gap-3"><button type="button" onclick="this.closest('#delete-account-modal').classList.add('hidden')" class="flex-1 border border-gray-300 text-gray-600 py-2.5 rounded-xl text-sm font-medium hover:bg-gray-50">বাতিল</button><button type="submit" class="flex-1 bg-red-600 text-white py-2.5 rounded-xl text-sm font-medium hover:bg-red-700">ডিলিট</button></div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
