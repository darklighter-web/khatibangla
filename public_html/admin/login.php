<?php
/**
 * Admin Login Page
 * Protected by secret gate — access only via /menzio-panel.php?key=SECRET
 * Direct visits to /admin/ show fake 404
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in as admin? Go to dashboard
if (isAdminLoggedIn()) {
    redirect(adminUrl('index.php'));
}

// ── Gate Check ──
// Gate cookie is set by /menzio-panel.php (or any custom entry point)
// Also allow ?access=KEY as backward-compatible fallback
$adminSecretKey = getSetting('admin_secret_key', 'menzio2026');
$gateValid = false;

// Check ?access=KEY in URL (backward compat)
if (isset($_GET['access']) && $_GET['access'] === $adminSecretKey) {
    $gateValid = true;
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443 || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $cookieValue = hash('sha256', $adminSecretKey . date('Ymd') . 'gate');
    setcookie('_adm_gate', $cookieValue, [
        'expires' => time() + 86400,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    redirect(adminUrl('login.php'));
}

// Check gate cookie (set by menzio-panel.php or ?access=)
$expectedCookie = hash('sha256', $adminSecretKey . date('Ymd') . 'gate');
if (isset($_COOKIE['_adm_gate']) && $_COOKIE['_adm_gate'] === $expectedCookie) {
    $gateValid = true;
}

// No valid gate? Fake 404
if (!$gateValid) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404 Not Found</title><style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f5f5;color:#333}div{text-align:center}h1{font-size:120px;font-weight:200;margin:0;color:#ddd}p{font-size:18px;color:#999}</style></head><body><div><h1>404</h1><p>The page you are looking for does not exist.</p></div></body></html>';
    exit;
}

// ── Login Logic ──
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Brute force check via SecurityGuard
    $sg = function_exists('getSecurityGuard') ? getSecurityGuard() : null;
    $bruteOk = $sg ? $sg->checkBruteForce($username) : true;
    
    if (!$bruteOk) {
        $error = 'Too many failed attempts. Please try again later.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Please enter username and password';
    } elseif (adminLogin($username, $password)) {
        // Log successful login
        if ($sg) $sg->logLoginAttempt($username, true);
        redirect(adminUrl('index.php'));
    } else {
        // Log failed login
        if ($sg) $sg->logLoginAttempt($username, false);
        $error = 'Invalid username or password';
    }
}

$siteName = getSetting('site_name', 'E-Commerce');
$logo = getSetting('site_logo', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login - <?= e($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gradient-to-br from-slate-900 via-blue-900 to-slate-900 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="bg-white rounded-2xl shadow-2xl p-8">
            <div class="text-center mb-8">
                <?php if ($logo): ?>
                    <img src="<?= uploadUrl($logo) ?>" alt="Logo" class="h-12 mx-auto mb-4">
                <?php else: ?>
                    <div class="w-16 h-16 bg-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                <?php endif; ?>
                <h1 class="text-2xl font-bold text-gray-800">Admin Panel</h1>
                <p class="text-gray-500 mt-1">Sign in to manage your store</p>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 text-sm">
                <?= e($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Username or Email</label>
                    <input type="text" name="username" value="<?= e($username ?? '') ?>" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                        placeholder="Enter your username">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                    <input type="password" name="password" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                        placeholder="Enter your password">
                </div>
                <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 shadow-lg shadow-blue-500/30">
                    Sign In
                </button>
            </form>
        </div>
        <p class="text-center text-blue-200/60 text-sm mt-6">&copy; <?= date('Y') ?> <?= e($siteName) ?></p>
    </div>
</body>
</html>
