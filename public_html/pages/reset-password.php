<?php
/**
 * Reset Password Page
 * Customer clicks link from email → enters new password
 */
$pageTitle = 'নতুন পাসওয়ার্ড সেট করুন';
require_once __DIR__ . '/../includes/functions.php';

// Already logged in
if (isCustomerLoggedIn()) {
    redirect(url('account'));
}

$db = Database::getInstance();
$token = sanitize($_GET['token'] ?? '');
$error = '';
$success = '';
$valid = false;
$tokenData = null;

// Validate token
if (empty($token)) {
    $error = 'রিসেট লিংক সঠিক নয়।';
} else {
    $tokenData = $db->fetch(
        "SELECT prt.*, c.name, c.phone, c.email 
         FROM password_reset_tokens prt 
         JOIN customers c ON c.id = prt.customer_id 
         WHERE prt.token = ? AND prt.used = 0 AND prt.expires_at > NOW()",
        [$token]
    );
    
    if (!$tokenData) {
        // Check if token existed but expired or used
        $expired = $db->fetch(
            "SELECT id, used, expires_at FROM password_reset_tokens WHERE token = ?",
            [$token]
        );
        if ($expired) {
            if ($expired['used']) {
                $error = 'এই রিসেট লিংকটি ইতিমধ্যে ব্যবহার করা হয়েছে।';
            } else {
                $error = 'রিসেট লিংকের মেয়াদ শেষ হয়ে গেছে। অনুগ্রহ করে আবার চেষ্টা করুন।';
            }
        } else {
            $error = 'রিসেট লিংক সঠিক নয়।';
        }
    } else {
        $valid = true;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($password)) {
        $error = 'নতুন পাসওয়ার্ড দিন।';
    } elseif (strlen($password) < 6) {
        $error = 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।';
    } elseif ($password !== $confirmPassword) {
        $error = 'পাসওয়ার্ড মিলছে না।';
    } else {
        // Update password
        $db->update('customers', [
            'password' => hashPassword($password)
        ], 'id = ?', [$tokenData['customer_id']]);
        
        // Mark token as used
        $db->update('password_reset_tokens', [
            'used' => 1
        ], 'id = ?', [$tokenData['id']]);
        
        // Invalidate all other tokens for this customer
        $db->query(
            "UPDATE password_reset_tokens SET used = 1 WHERE customer_id = ? AND used = 0",
            [$tokenData['customer_id']]
        );
        
        $success = true;
        $valid = false; // Hide form
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-md mx-auto px-4 py-10">
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden">
        <div class="p-6">

            <?php if ($success === true): ?>
            <!-- Success State -->
            <div class="text-center py-4">
                <div class="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-gray-800 mb-2">পাসওয়ার্ড পরিবর্তন হয়েছে!</h1>
                <p class="text-sm text-gray-500 mb-6">আপনার নতুন পাসওয়ার্ড সেট হয়েছে। এখন লগইন করতে পারেন।</p>
                <a href="<?= url('login') ?>" class="inline-block btn-primary px-8 py-3 rounded-xl text-sm font-semibold">
                    লগইন করুন
                </a>
            </div>

            <?php elseif ($valid): ?>
            <!-- Reset Form -->
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-blue-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-gray-800">নতুন পাসওয়ার্ড সেট করুন</h1>
                <p class="text-sm text-gray-500 mt-1">
                    <strong><?= e($tokenData['name']) ?></strong> — <?= e($tokenData['phone']) ?>
                </p>
            </div>

            <?php if ($error): ?>
            <div class="mb-5 p-3.5 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">নতুন পাসওয়ার্ড</label>
                    <input type="password" name="password" required minlength="6" autofocus
                           class="w-full border border-gray-300 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                           placeholder="কমপক্ষে ৬ অক্ষর">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">পাসওয়ার্ড নিশ্চিত করুন</label>
                    <input type="password" name="confirm_password" required
                           class="w-full border border-gray-300 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                           placeholder="আবার পাসওয়ার্ড দিন">
                </div>
                <button type="submit" class="w-full btn-primary py-3 rounded-xl text-sm font-semibold">
                    পাসওয়ার্ড সেট করুন
                </button>
            </form>

            <?php else: ?>
            <!-- Error State -->
            <div class="text-center py-4">
                <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-gray-800 mb-2">লিংক কাজ করছে না</h1>
                <p class="text-sm text-gray-500 mb-6"><?= $error ?></p>
                <a href="<?= url('forgot-password') ?>" class="inline-block btn-primary px-8 py-3 rounded-xl text-sm font-semibold">
                    আবার চেষ্টা করুন
                </a>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
