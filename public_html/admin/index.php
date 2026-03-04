<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/includes/auth.php';

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    adminLogout();
    // Clear gate cookie on logout
    setcookie('_adm_gate', '', ['expires' => time() - 3600, 'path' => '/']);
    header('Location: login.php');
    exit;
}

if (!isAdminLoggedIn()) {
    // Check gate cookie before redirecting to login
    $adminSecretKey = getSetting('admin_secret_key', 'menzio2026');
    $expectedCookie = hash('sha256', $adminSecretKey . date('Ymd') . 'gate');
    if (!isset($_COOKIE['_adm_gate']) || $_COOKIE['_adm_gate'] !== $expectedCookie) {
        // No gate — fake 404
        http_response_code(404);
        echo '<!DOCTYPE html><html><head><title>404 Not Found</title><style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f5f5;color:#333}div{text-align:center}h1{font-size:120px;font-weight:200;margin:0;color:#ddd}p{font-size:18px;color:#999}</style></head><body><div><h1>404</h1><p>The page you are looking for does not exist.</p></div></body></html>';
        exit;
    }
    header('Location: login.php');
    exit;
}

header('Location: pages/dashboard.php');
exit;
