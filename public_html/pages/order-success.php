<?php
/**
 * Order Success Page
 */
$pageTitle = 'অর্ডার সফল';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();
$orderNumber = sanitize($_GET['order'] ?? '');
$order = null;

if ($orderNumber) {
    $order = $db->fetch("SELECT * FROM orders WHERE order_number = ?", [$orderNumber]);
}

// Clear cart after successful order
clearCart();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-xl mx-auto px-4 py-12 text-center">
    <div class="bg-white rounded-2xl shadow-sm border p-8">
        <div class="w-20 h-20 mx-auto mb-5 rounded-full bg-green-100 flex items-center justify-center">
            <i class="fas fa-check text-3xl text-green-500"></i>
        </div>
        
        <h1 class="text-2xl font-bold text-gray-800 mb-2">অর্ডার সফল হয়েছে! 🎉</h1>
        <p class="text-gray-500 mb-6">আপনার অর্ডার সফলভাবে গ্রহণ করা হয়েছে।</p>
        
        <?php if ($order): ?>
        <div class="bg-gray-50 rounded-xl p-5 mb-6 text-left space-y-3">
            <div class="flex justify-between text-sm">
                <span class="text-gray-500">অর্ডার নম্বর</span>
                <span class="font-bold text-lg" style="color:var(--primary)"><?= e($order['order_number']) ?></span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-gray-500">মোট মূল্য</span>
                <span class="font-semibold"><?= formatPrice($order['total']) ?></span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-gray-500">পেমেন্ট</span>
                <span class="font-medium">ক্যাশ অন ডেলিভারি</span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-gray-500">ডেলিভারি ঠিকানা</span>
                <span class="font-medium text-right max-w-[200px]"><?= e($order['customer_address']) ?></span>
            </div>
        </div>
        
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 text-sm text-blue-800">
            <i class="fas fa-info-circle mr-1"></i>
            আপনার অর্ডার নম্বর সেভ করে রাখুন। এটি দিয়ে অর্ডার ট্র্যাক করতে পারবেন।
        </div>
        <?php endif; ?>
        
        <div class="flex flex-col sm:flex-row gap-3 justify-center">
            <?php if ($order): ?>
            <a href="<?= url('track-order?q=' . e($order['order_number'])) ?>" class="px-6 py-3 rounded-xl btn-primary font-medium">
                <i class="fas fa-truck mr-2"></i> অর্ডার ট্র্যাক করুন
            </a>
            <?php endif; ?>
            <a href="<?= url() ?>" class="px-6 py-3 rounded-xl border border-gray-300 text-gray-600 font-medium hover:bg-gray-50 transition">
                <i class="fas fa-home mr-2"></i> হোমপেজে যান
            </a>
        </div>
        
        <div class="mt-8 pt-6 border-t">
            <p class="text-sm text-gray-500 mb-2">যেকোনো প্রশ্নে যোগাযোগ করুন:</p>
            <a href="tel:<?= getSetting('site_phone') ?>" class="text-blue-600 font-medium">
                <i class="fas fa-phone mr-1"></i> <?= getSetting('site_phone') ?>
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
