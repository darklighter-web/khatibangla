<?php
$pageTitle = '404 - Page Not Found';
$seo = ['type' => 'website', 'noindex' => true];
http_response_code(404);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-[60vh] flex items-center justify-center">
    <div class="text-center px-4">
        <div class="text-8xl font-bold text-gray-200 mb-4">404</div>
        <h1 class="text-2xl font-bold text-gray-800 mb-2">পেজটি খুঁজে পাওয়া যায়নি</h1>
        <p class="text-gray-500 mb-6">দুঃখিত, আপনি যে পেজটি খুঁজছেন সেটি পাওয়া যায়নি।</p>
        <div class="flex gap-3 justify-center">
            <a href="<?= url() ?>" class="bg-blue-600 text-white px-6 py-3 rounded-lg font-medium hover:bg-blue-700 transition">হোমপেজে যান</a>
            <a href="javascript:history.back()" class="border border-gray-300 text-gray-600 px-6 py-3 rounded-lg font-medium hover:bg-gray-50 transition">পূর্বের পেজে যান</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
