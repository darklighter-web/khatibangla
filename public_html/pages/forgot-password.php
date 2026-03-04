<?php
/**
 * Forgot Password Page
 * Tells customer to contact admin for password reset
 */
$pageTitle = 'পাসওয়ার্ড ভুলে গেছেন';
require_once __DIR__ . '/../includes/functions.php';

if (isCustomerLoggedIn()) {
    redirect(url('account'));
}

$seo = ['type' => 'website', 'noindex' => true];
require_once __DIR__ . '/../includes/header.php';
$hotline = getSetting('hotline_number', '') ?: getSetting('site_hotline', '');
$email = getSetting('site_email', '');
$whatsapp = getSetting('whatsapp_number', '') ?: $hotline;
?>

<div class="max-w-md mx-auto px-4 py-10">
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden">
        <div class="p-6">
            <a href="<?= url('login') ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-5">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                লগইনে ফিরে যান
            </a>

            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-orange-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-gray-800">পাসওয়ার্ড ভুলে গেছেন?</h1>
                <p class="text-sm text-gray-500 mt-2 leading-relaxed">পাসওয়ার্ড রিসেট করতে আমাদের সাথে যোগাযোগ করুন।<br>আমরা আপনার পাসওয়ার্ড রিসেট করে দেব।</p>
            </div>

            <div class="space-y-3">
                <?php if ($hotline): ?>
                <a href="tel:<?= e($hotline) ?>" class="flex items-center gap-3 p-3.5 bg-blue-50 border border-blue-200 rounded-xl hover:bg-blue-100 transition">
                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-blue-700">ফোনে কল করুন</p>
                        <p class="text-sm text-blue-600"><?= e($hotline) ?></p>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($whatsapp): ?>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $whatsapp) ?>?text=<?= urlencode('আমি আমার পাসওয়ার্ড ভুলে গেছি। অনুগ্রহ করে রিসেট করে দিন।') ?>" target="_blank" class="flex items-center gap-3 p-3.5 bg-green-50 border border-green-200 rounded-xl hover:bg-green-100 transition">
                    <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-green-700">WhatsApp এ মেসেজ করুন</p>
                        <p class="text-sm text-green-600"><?= e($whatsapp) ?></p>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($email): ?>
                <a href="mailto:<?= e($email) ?>?subject=<?= urlencode('পাসওয়ার্ড রিসেট রিকোয়েস্ট') ?>" class="flex items-center gap-3 p-3.5 bg-purple-50 border border-purple-200 rounded-xl hover:bg-purple-100 transition">
                    <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-purple-700">ইমেইল করুন</p>
                        <p class="text-sm text-purple-600"><?= e($email) ?></p>
                    </div>
                </a>
                <?php endif; ?>
            </div>

            <div class="mt-6 p-3 bg-gray-50 rounded-xl">
                <p class="text-xs text-gray-500 text-center leading-relaxed">
                    আপনার ফোন নম্বর ও নাম জানালে আমরা দ্রুত পাসওয়ার্ড রিসেট করে দেব।
                </p>
            </div>
        </div>
    </div>

    <p class="text-center text-sm text-gray-400 mt-6">
        পাসওয়ার্ড মনে আছে? <a href="<?= url('login') ?>" class="text-blue-600 font-medium hover:text-blue-800">লগইন করুন</a>
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
