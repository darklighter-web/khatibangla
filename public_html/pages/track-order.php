<?php
$pageTitle = 'ট্র্যাক অর্ডার';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();
$order = null;
$items = [];
$statusHistory = [];
$shipment = null;
$error = '';

if ($_GET['q'] ?? $_POST['order_number'] ?? '') {
    $orderNum = sanitize($_GET['q'] ?? $_POST['order_number']);
    $phone = sanitize($_GET['p'] ?? $_POST['phone'] ?? '');

    if ($phone) {
        $order = $db->fetch("SELECT * FROM orders WHERE order_number = ? AND customer_phone = ?", [$orderNum, $phone]);
    } else {
        $order = $db->fetch("SELECT * FROM orders WHERE order_number = ?", [$orderNum]);
    }

    if ($order) {
        $items = $db->fetchAll("SELECT oi.*, p.slug, p.store_credit_enabled, p.store_credit_amount,
            (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = oi.product_id AND pi.is_primary = 1 LIMIT 1) as image 
            FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?", [$order['id']]);
        $statusHistory = $db->fetchAll("SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC", [$order['id']]);
        $shipment = $db->fetch("SELECT cs.*, cp.name as courier_name FROM courier_shipments cs LEFT JOIN courier_providers cp ON cp.id = cs.courier_id WHERE cs.order_id = ?", [$order['id']]);
    } else {
        $error = 'অর্ডারটি খুঁজে পাওয়া যায়নি। অর্ডার নম্বর ও ফোন নম্বর চেক করুন।';
    }
}

require_once __DIR__ . '/../includes/header.php';

$statusSteps = ['processing', 'confirmed', 'shipped', 'delivered'];
$statusConfig = [
    'pending'    => ['প্রসেসিং', 'bg-yellow-100 text-yellow-700 border-yellow-300', 'fas fa-cog', '#eab308'],
    'processing' => ['প্রসেসিং', 'bg-yellow-100 text-yellow-700 border-yellow-300', 'fas fa-cog', '#eab308'],
    'confirmed'  => ['কনফার্মড', 'bg-blue-100 text-blue-700 border-blue-300', 'fas fa-check-circle', '#3b82f6'],
    'shipped'    => ['শিপড', 'bg-purple-100 text-purple-700 border-purple-300', 'fas fa-truck', '#8b5cf6'],
    'delivered'  => ['ডেলিভারড', 'bg-green-100 text-green-700 border-green-300', 'fas fa-box-open', '#22c55e'],
    'cancelled'  => ['ক্যান্সেলড', 'bg-red-100 text-red-700 border-red-300', 'fas fa-times-circle', '#ef4444'],
    'returned'   => ['রিটার্নড', 'bg-orange-100 text-orange-700 border-orange-300', 'fas fa-undo', '#f97316'],
    'pending_return' => ['রিটার্ন পেন্ডিং', 'bg-amber-100 text-amber-700 border-amber-300', 'fas fa-sync', '#f59e0b'],
    'pending_cancel' => ['ক্যান্সেল পেন্ডিং', 'bg-pink-100 text-pink-700 border-pink-300', 'fas fa-hourglass-half', '#ec4899'],
    'partial_delivered' => ['আংশিক ডেলিভারি', 'bg-cyan-100 text-cyan-700 border-cyan-300', 'fas fa-box', '#06b6d4'],
    'lost'       => ['লস্ট', 'bg-stone-100 text-stone-700 border-stone-300', 'fas fa-exclamation-triangle', '#78716c'],
    'on_hold'    => ['হোল্ড', 'bg-gray-100 text-gray-700 border-gray-300', 'fas fa-pause-circle', '#6b7280'],
];
$currentStatus = $order['order_status'] ?? '';
// Map legacy 'pending' status to 'processing'
if ($currentStatus === 'pending') $currentStatus = 'processing';
$conf = $statusConfig[$currentStatus] ?? ['অজানা', 'bg-gray-100 text-gray-700', 'fas fa-question', '#6b7280'];
$creditUsed = floatval($order['store_credit_used'] ?? 0);
$discountAmt = floatval($order['discount_amount'] ?? 0);
$totalItems = 0;
foreach ($items as $it) $totalItems += intval($it['quantity']);
$totalCreditsEarnable = 0;
foreach ($items as $it) {
    if (!empty($it['store_credit_enabled']) && floatval($it['store_credit_amount'] ?? 0) > 0) {
        $totalCreditsEarnable += floatval($it['store_credit_amount']) * intval($it['quantity']);
    }
}
?>

<style>
.track-progress{display:flex;align-items:flex-start;position:relative;padding:0 8px}
.track-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;z-index:2}
.track-step .step-dot{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;border:3px solid #e5e7eb;background:#fff;transition:all .3s}
.track-step.done .step-dot{border-color:#22c55e;background:#22c55e;color:#fff}
.track-step.active .step-dot{border-color:var(--primary,#3b82f6);background:var(--primary,#3b82f6);color:#fff;box-shadow:0 0 0 4px rgba(59,130,246,.2)}
.track-line{position:absolute;top:18px;left:0;right:0;height:3px;background:#e5e7eb;z-index:1}
.track-line-fill{height:100%;background:linear-gradient(90deg,#22c55e,var(--primary,#3b82f6));border-radius:4px;transition:width .6s ease}
.tl-item{position:relative;padding-left:28px;padding-bottom:20px}
.tl-item:last-child{padding-bottom:0}
.tl-item::before{content:'';position:absolute;left:7px;top:24px;bottom:0;width:2px;background:#e5e7eb}
.tl-item:last-child::before{display:none}
.tl-dot{position:absolute;left:0;top:4px;width:16px;height:16px;border-radius:50%;border:3px solid;display:flex;align-items:center;justify-content:center}
</style>

<div class="max-w-2xl mx-auto py-6 px-4">

    <!-- Search Form -->
    <div class="bg-white rounded-2xl shadow-sm border p-5 mb-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center">
                <i class="fas fa-search text-blue-500"></i>
            </div>
            <div>
                <h1 class="text-lg font-bold text-gray-800">অর্ডার ট্র্যাক করুন</h1>
                <p class="text-xs text-gray-400">অর্ডার নম্বর দিয়ে আপনার অর্ডারের অবস্থা দেখুন</p>
            </div>
        </div>
        <form method="POST">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                <input type="text" name="order_number" value="<?= e($_POST['order_number'] ?? $_GET['q'] ?? '') ?>" required placeholder="অর্ডার নম্বর — ORD-XXXXX" class="border border-gray-200 rounded-xl px-4 py-2.5 w-full text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400 outline-none">
                <input type="text" name="phone" value="<?= e($_POST['phone'] ?? $_GET['p'] ?? '') ?>" placeholder="ফোন নম্বর (ঐচ্ছিক)" class="border border-gray-200 rounded-xl px-4 py-2.5 w-full text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400 outline-none">
            </div>
            <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-blue-500 text-white py-2.5 rounded-xl font-semibold text-sm hover:shadow-lg hover:shadow-blue-200 transition-all active:scale-[0.98]">
                <i class="fas fa-search mr-2"></i>ট্র্যাক করুন
            </button>
        </form>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded-xl text-center text-sm">
        <i class="fas fa-exclamation-circle mr-1"></i><?= $error ?>
    </div>
    <?php endif; ?>

    <?php if ($order): ?>

    <!-- Order Header Card -->
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden mb-4">
        <div class="p-5 pb-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <h2 class="font-bold text-base text-gray-800">#<?= e($order['order_number']) ?></h2>
                        <button onclick="navigator.clipboard.writeText('<?= e($order['order_number']) ?>').then(()=>{this.innerHTML='<i class=\'fas fa-check text-green-500\'></i>';setTimeout(()=>this.innerHTML='<i class=\'fas fa-copy\'></i>',1200)})" class="text-gray-300 hover:text-gray-500 text-xs p-1" title="কপি"><i class="fas fa-copy"></i></button>
                    </div>
                    <p class="text-xs text-gray-400">
                        <i class="far fa-calendar-alt mr-1"></i><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
                        <span class="mx-1.5">•</span>
                        <i class="fas fa-box mr-1"></i><?= $totalItems ?> পণ্য
                    </p>
                </div>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border <?= $conf[1] ?>">
                    <i class="<?= $conf[2] ?> text-[10px]"></i>
                    <?= $conf[0] ?>
                </span>
            </div>
        </div>

        <!-- Progress Bar -->
        <?php if (!in_array($currentStatus, ['cancelled', 'returned'])): ?>
        <?php $currentIdx = array_search($currentStatus, $statusSteps); if ($currentIdx === false) $currentIdx = 0; ?>
        <div class="px-5 pb-5">
            <div class="track-progress">
                <div class="track-line">
                    <div class="track-line-fill" style="width:<?= $currentIdx >= count($statusSteps)-1 ? 100 : round(($currentIdx / (count($statusSteps)-1)) * 100) ?>%"></div>
                </div>
                <?php foreach ($statusSteps as $i => $step):
                    $stepClass = $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'active' : '');
                ?>
                <div class="track-step <?= $stepClass ?>">
                    <div class="step-dot">
                        <?php if ($i < $currentIdx): ?>
                            <i class="fas fa-check text-xs"></i>
                        <?php elseif ($i === $currentIdx): ?>
                            <i class="<?= $statusConfig[$step][2] ?? 'fas fa-circle' ?> text-xs"></i>
                        <?php else: ?>
                            <?= $i + 1 ?>
                        <?php endif; ?>
                    </div>
                    <span class="text-[10px] mt-1.5 text-center leading-tight <?= $i <= $currentIdx ? 'text-gray-700 font-medium' : 'text-gray-400' ?>"><?= $statusConfig[$step][0] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="mx-5 mb-5 rounded-xl p-3 <?= $currentStatus === 'cancelled' ? 'bg-red-50 border border-red-200' : 'bg-orange-50 border border-orange-200' ?>">
            <div class="flex items-center gap-2">
                <i class="<?= $conf[2] ?> <?= $currentStatus === 'cancelled' ? 'text-red-500' : 'text-orange-500' ?>"></i>
                <span class="text-sm font-medium <?= $currentStatus === 'cancelled' ? 'text-red-700' : 'text-orange-700' ?>">
                    এই অর্ডারটি <?= $conf[0] ?> করা হয়েছে
                </span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Courier Info -->
    <?php if ($shipment): ?>
    <div class="bg-white rounded-2xl shadow-sm border p-5 mb-4">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-9 h-9 rounded-lg bg-purple-50 flex items-center justify-center"><i class="fas fa-shipping-fast text-purple-500 text-sm"></i></div>
            <h3 class="font-semibold text-sm text-gray-800">কুরিয়ার তথ্য</h3>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div class="bg-gray-50 rounded-xl p-3">
                <span class="text-[10px] text-gray-400 uppercase tracking-wider block mb-0.5">কুরিয়ার</span>
                <span class="text-sm font-semibold text-gray-700"><?= e($shipment['courier_name'] ?? 'N/A') ?></span>
            </div>
            <div class="bg-gray-50 rounded-xl p-3">
                <span class="text-[10px] text-gray-400 uppercase tracking-wider block mb-0.5">স্ট্যাটাস</span>
                <span class="text-sm font-semibold text-gray-700"><?= ucfirst(str_replace('_', ' ', $shipment['status'] ?? 'pending')) ?></span>
            </div>
            <?php if (!empty($shipment['tracking_number'])): ?>
            <div class="bg-gray-50 rounded-xl p-3 col-span-2">
                <span class="text-[10px] text-gray-400 uppercase tracking-wider block mb-0.5">ট্র্যাকিং নম্বর</span>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-mono font-semibold text-gray-700"><?= e($shipment['tracking_number']) ?></span>
                    <button onclick="navigator.clipboard.writeText('<?= e($shipment['tracking_number']) ?>')" class="text-gray-400 hover:text-gray-600 text-xs"><i class="fas fa-copy"></i></button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Delivery Info -->
    <div class="bg-white rounded-2xl shadow-sm border p-5 mb-4">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center"><i class="fas fa-user text-blue-500 text-sm"></i></div>
            <h3 class="font-semibold text-sm text-gray-800">ডেলিভারি তথ্য</h3>
        </div>
        <div class="space-y-2.5 text-sm">
            <div class="flex items-start gap-3">
                <i class="fas fa-user-circle text-gray-300 mt-0.5 w-4 text-center"></i>
                <div><span class="text-gray-400 text-xs block">নাম</span><span class="text-gray-700 font-medium"><?= e($order['customer_name']) ?></span></div>
            </div>
            <div class="flex items-start gap-3">
                <i class="fas fa-phone-alt text-gray-300 mt-0.5 w-4 text-center"></i>
                <div><span class="text-gray-400 text-xs block">ফোন</span><a href="tel:<?= e($order['customer_phone']) ?>" class="text-blue-600 font-medium"><?= e($order['customer_phone']) ?></a></div>
            </div>
            <div class="flex items-start gap-3">
                <i class="fas fa-map-marker-alt text-gray-300 mt-0.5 w-4 text-center"></i>
                <div>
                    <span class="text-gray-400 text-xs block">ঠিকানা</span>
                    <span class="text-gray-700"><?= e($order['customer_address']) ?><?php if (!empty($order['customer_city'])): ?>, <?= e($order['customer_city']) ?><?php endif; ?></span>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <i class="fas fa-money-bill-wave text-gray-300 mt-0.5 w-4 text-center"></i>
                <div>
                    <span class="text-gray-400 text-xs block">পেমেন্ট</span>
                    <span class="text-gray-700 font-medium"><?php
                        $pmLabels = ['cod'=>'ক্যাশ অন ডেলিভারি','bkash'=>'বিকাশ','nagad'=>'নগদ','bank'=>'ব্যাংক ট্রান্সফার','online'=>'অনলাইন'];
                        echo $pmLabels[$order['payment_method'] ?? 'cod'] ?? ucfirst($order['payment_method'] ?? 'cod');
                    ?></span>
                </div>
            </div>
            <?php if (!empty($order['notes'])): ?>
            <div class="flex items-start gap-3">
                <i class="fas fa-sticky-note text-gray-300 mt-0.5 w-4 text-center"></i>
                <div><span class="text-gray-400 text-xs block">নোট</span><span class="text-gray-600 italic"><?= e($order['notes']) ?></span></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Order Items + Pricing -->
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden mb-4">
        <div class="p-5 pb-3">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-9 h-9 rounded-lg bg-green-50 flex items-center justify-center"><i class="fas fa-shopping-bag text-green-500 text-sm"></i></div>
                <h3 class="font-semibold text-sm text-gray-800">পণ্যসমূহ <span class="text-gray-400 font-normal">(<?= count($items) ?>)</span></h3>
            </div>
            <div class="space-y-3">
                <?php foreach ($items as $item): ?>
                <div class="flex items-center gap-3 p-2.5 rounded-xl bg-gray-50">
                    <?php if ($item['image']): ?>
                    <img src="<?= imgSrc('products', $item['image']) ?>" class="w-14 h-14 object-cover rounded-lg border flex-shrink-0" alt="">
                    <?php else: ?>
                    <div class="w-14 h-14 bg-gray-200 rounded-lg flex items-center justify-center text-gray-400 flex-shrink-0"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-sm text-gray-800 leading-tight" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?= e($item['product_name']) ?></p>
                        <?php if (!empty($item['variant_name'])): ?>
                        <p class="text-[11px] text-gray-400 mt-0.5"><?= e($item['variant_name']) ?></p>
                        <?php endif; ?>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs text-gray-500"><?= formatPrice($item['price']) ?> × <?= intval($item['quantity']) ?></span>
                            <?php if (!empty($item['store_credit_enabled']) && floatval($item['store_credit_amount'] ?? 0) > 0 && !in_array($currentStatus, ['delivered','cancelled','returned'])): ?>
                            <span class="text-[9px] bg-yellow-100 text-yellow-700 px-1.5 py-0.5 rounded-full font-medium"><i class="fas fa-coins mr-0.5"></i><?= intval($item['store_credit_amount'] * $item['quantity']) ?> ক্রেডিট</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="font-bold text-sm text-gray-800 flex-shrink-0"><?= formatPrice($item['subtotal']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Price Breakdown -->
        <div class="border-t mx-5"></div>
        <div class="p-5 space-y-2 text-sm">
            <div class="flex justify-between text-gray-500">
                <span>সাবটোটাল</span>
                <span class="font-medium text-gray-700"><?= formatPrice($order['subtotal']) ?></span>
            </div>
            <?php if ($discountAmt > 0): ?>
            <div class="flex justify-between text-green-600">
                <span><i class="fas fa-tag mr-1 text-xs"></i>ডিসকাউন্ট <?= !empty($order['coupon_code']) ? '(' . e($order['coupon_code']) . ')' : '' ?></span>
                <span class="font-medium">-<?= formatPrice($discountAmt) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($creditUsed > 0): ?>
            <div class="flex justify-between items-center">
                <span class="flex items-center gap-1.5 text-yellow-700">
                    <span class="w-5 h-5 rounded-full bg-yellow-100 flex items-center justify-center flex-shrink-0"><i class="fas fa-coins text-[9px] text-yellow-600"></i></span>
                    স্টোর ক্রেডিট ব্যবহৃত
                </span>
                <span class="font-semibold text-yellow-700">-<?= formatPrice($creditUsed) ?></span>
            </div>
            <?php endif; ?>
            <div class="flex justify-between text-gray-500">
                <span><i class="fas fa-truck mr-1 text-xs"></i>ডেলিভারি চার্জ</span>
                <span class="font-medium text-gray-700"><?= floatval($order['shipping_cost']) > 0 ? formatPrice($order['shipping_cost']) : '<span class="text-green-600 font-semibold">ফ্রি</span>' ?></span>
            </div>
            <div class="flex justify-between items-center font-bold text-base pt-2 border-t border-dashed">
                <span class="text-gray-800">মোট পরিশোধযোগ্য</span>
                <span style="color:var(--primary,#2563eb)"><?= formatPrice($order['total']) ?></span>
            </div>
            <?php $totalSaved = $discountAmt + $creditUsed; if ($totalSaved > 0): ?>
            <div class="flex justify-between items-center bg-green-50 rounded-xl px-3 py-2 -mx-1 mt-1">
                <span class="text-green-700 text-xs font-semibold"><i class="fas fa-piggy-bank mr-1"></i>আপনি সেভ করেছেন</span>
                <span class="text-green-700 font-bold"><?= formatPrice($totalSaved) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Credit Earning Notice -->
    <?php if ($totalCreditsEarnable > 0 && !in_array($currentStatus, ['delivered','cancelled','returned'])): ?>
    <div class="bg-gradient-to-r from-yellow-50 to-amber-50 border border-yellow-200 rounded-2xl p-4 mb-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-yellow-100 flex items-center justify-center flex-shrink-0"><i class="fas fa-gift text-yellow-600"></i></div>
            <div>
                <p class="text-sm font-semibold text-yellow-800">ডেলিভারির পর পাবেন <?= number_format($totalCreditsEarnable, 0) ?> ক্রেডিট পয়েন্ট!</p>
                <p class="text-xs text-yellow-600 mt-0.5">পরবর্তী অর্ডারে ব্যবহার করতে পারবেন</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Timeline -->
    <?php if (!empty($statusHistory)): ?>
    <div class="bg-white rounded-2xl shadow-sm border p-5 mb-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center"><i class="fas fa-history text-gray-500 text-sm"></i></div>
            <h3 class="font-semibold text-sm text-gray-800">অর্ডার টাইমলাইন</h3>
        </div>
        <div>
            <?php foreach (array_reverse($statusHistory) as $idx => $sh):
                $shConf = $statusConfig[$sh['status']] ?? ['অজানা','bg-gray-100 text-gray-500','fas fa-circle','#6b7280'];
                $isFirst = $idx === 0;
            ?>
            <div class="tl-item">
                <div class="tl-dot" style="border-color:<?= $shConf[3] ?>;background:<?= $isFirst ? $shConf[3] : '#fff' ?>">
                    <?php if ($isFirst): ?><div style="width:6px;height:6px;border-radius:50%;background:#fff"></div><?php endif; ?>
                </div>
                <div class="<?= $isFirst ? '' : 'opacity-70' ?>">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold" style="color:<?= $shConf[3] ?>"><?= $shConf[0] ?></span>
                        <?php if ($isFirst): ?><span class="text-[9px] bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-full font-medium">সর্বশেষ</span><?php endif; ?>
                    </div>
                    <?php if (!empty($sh['note'])): ?><p class="text-xs text-gray-500 mt-0.5"><?= e($sh['note']) ?></p><?php endif; ?>
                    <p class="text-[11px] text-gray-400 mt-1"><i class="far fa-clock mr-0.5"></i><?= date('d M Y, h:i A', strtotime($sh['created_at'])) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Help -->
    <div class="bg-gray-50 rounded-2xl border border-gray-200 p-5">
        <div class="text-center">
            <p class="text-sm text-gray-500 mb-2">অর্ডার সংক্রান্ত কোনো সমস্যা?</p>
            <div class="flex items-center justify-center gap-3">
                <?php $supportPhone = getSetting('support_phone', ''); if ($supportPhone): ?>
                <a href="tel:<?= e($supportPhone) ?>" class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700"><i class="fas fa-phone-alt text-xs"></i>কল করুন</a>
                <span class="text-gray-300">|</span>
                <?php endif; ?>
                <?php $whatsapp = getSetting('whatsapp_number', ''); if ($whatsapp): ?>
                <a href="https://wa.me/<?= e($whatsapp) ?>?text=<?= urlencode('আমার অর্ডার #' . ($order['order_number'] ?? '') . ' নিয়ে জানতে চাই') ?>" target="_blank" class="inline-flex items-center gap-1.5 text-sm font-medium text-green-600 hover:text-green-700"><i class="fab fa-whatsapp"></i>WhatsApp</a>
                <?php endif; ?>
                <?php if (!$supportPhone && !$whatsapp): ?>
                <span class="text-sm text-gray-400">আমাদের সাথে যোগাযোগ করুন</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
