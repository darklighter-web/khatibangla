<?php
/**
 * Clerk Integration Diagnostic Tool
 * URL: /api/clerk-diag.php
 * Tests: PHP curl, Clerk API connectivity, DB schema, settings, session
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/clerk.php';

// Admin-only access — no bypass keys
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../admin/includes/auth.php';
if (!isAdminLoggedIn()) {
    http_response_code(403);
    echo "<h2>Access denied</h2><p>Login to admin first.</p>";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Clerk Integration Diagnostic</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
        .card { background: white; border-radius: 12px; padding: 20px; margin: 16px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .pass { color: #16a34a; } .fail { color: #dc2626; } .warn { color: #d97706; }
        .result { padding: 8px 12px; margin: 4px 0; border-radius: 8px; font-size: 14px; }
        .result.pass { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .result.fail { background: #fef2f2; border: 1px solid #fecaca; }
        .result.warn { background: #fffbeb; border: 1px solid #fde68a; }
        h2 { font-size: 18px; margin: 0 0 12px; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>
<h1>🔍 Clerk Integration Diagnostic</h1>
<p style="color:#666;">Run on: <?= date('Y-m-d H:i:s') ?> | Server: <?= php_uname('n') ?></p>

<?php
$allPassed = true;

function result(string $status, string $msg): void {
    global $allPassed;
    if ($status === 'fail') $allPassed = false;
    $icon = match($status) { 'pass' => '✅', 'fail' => '❌', 'warn' => '⚠️', default => 'ℹ️' };
    echo "<div class='result $status'>$icon $msg</div>";
}

// ── TEST 1: PHP Extensions ──
echo "<div class='card'><h2>1. PHP Extensions</h2>";
result(function_exists('curl_init') ? 'pass' : 'fail', 'curl extension: ' . (function_exists('curl_init') ? 'Available' : '<strong>MISSING — install php-curl!</strong>'));
result(function_exists('json_decode') ? 'pass' : 'info', 'json extension: Available');
result(function_exists('hash') ? 'pass' : 'info', 'hash extension: Available');
echo "<div class='result info'>PHP version: " . phpversion() . "</div>";
echo "<div class='result info'>allow_url_fopen: " . ini_get('allow_url_fopen') . "</div>";
echo "</div>";

// ── TEST 2: Clerk Settings ──
echo "<div class='card'><h2>2. Clerk Settings</h2>";
$config = getClerkConfig();
result($config['enabled'] ? 'pass' : 'warn', 'clerk_enabled: ' . ($config['enabled'] ? 'ON' : 'OFF'));
result(!empty($config['publishable_key']) ? 'pass' : 'fail', 'publishable_key: ' . (!empty($config['publishable_key']) ? '<code>' . substr($config['publishable_key'], 0, 15) . '...</code>' : '<strong>EMPTY</strong>'));
result(!empty($config['secret_key']) ? 'pass' : 'fail', 'secret_key: ' . (!empty($config['secret_key']) ? '<code>' . substr($config['secret_key'], 0, 10) . '...</code>' : '<strong>EMPTY</strong>'));
result(true ? 'info' : 'info', 'social_google: ' . ($config['social_google'] ? 'ON' : 'OFF'));
result(true ? 'info' : 'info', 'social_facebook: ' . ($config['social_facebook'] ? 'ON' : 'OFF'));
result(true ? 'info' : 'info', 'keep_legacy: ' . ($config['keep_legacy'] ? 'ON' : 'OFF'));
echo "</div>";

// ── TEST 3: Clerk API Connectivity ──
echo "<div class='card'><h2>3. Clerk API Connectivity</h2>";
if (!empty($config['secret_key']) && function_exists('curl_init')) {
    // Test basic API call
    $ch = curl_init('https://api.clerk.com/v1/users?limit=1');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $config['secret_key'], 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    
    if ($curlErr) {
        result('fail', "curl error: $curlErr");
    } elseif ($httpCode === 200) {
        $data = json_decode($resp, true);
        $userCount = count($data ?? []);
        result('pass', "Clerk API reachable! HTTP $httpCode. Found $userCount user(s) in your Clerk instance.");
        
        // Show first user for debugging
        if ($userCount > 0 && isset($data[0])) {
            $u = $data[0];
            $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            $email = $u['email_addresses'][0]['email_address'] ?? 'none';
            $id = $u['id'];
            echo "<div class='result pass'>First user: <code>$id</code> — $name ($email)</div>";
        }
    } elseif ($httpCode === 401) {
        result('fail', "Clerk API returned 401 Unauthorized. <strong>Secret key is invalid!</strong> Check Settings → Clerk Auth.");
        echo "<pre>" . htmlspecialchars(substr($resp, 0, 300)) . "</pre>";
    } else {
        result('fail', "Clerk API returned HTTP $httpCode");
        echo "<pre>" . htmlspecialchars(substr($resp, 0, 300)) . "</pre>";
    }
} elseif (!function_exists('curl_init')) {
    result('fail', '<strong>Cannot test — curl extension is missing!</strong> Run: <code>sudo apt install php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-curl && sudo systemctl restart lsws</code>');
} else {
    result('warn', 'Cannot test — no secret key configured.');
}
echo "</div>";

// ── TEST 4: Database Schema ──
echo "<div class='card'><h2>4. Database Schema</h2>";
$db = Database::getInstance();
try {
    $cols = $db->fetchAll("SHOW COLUMNS FROM customers WHERE Field IN ('clerk_id','clerk_image')");
    $hasClerkId = false;
    $hasClerkImage = false;
    foreach ($cols as $c) {
        if ($c['Field'] === 'clerk_id') $hasClerkId = true;
        if ($c['Field'] === 'clerk_image') $hasClerkImage = true;
    }
    result($hasClerkId ? 'pass' : 'fail', 'customers.clerk_id column: ' . ($hasClerkId ? 'EXISTS' : '<strong>MISSING — run clerk-migration.sql!</strong>'));
    result($hasClerkImage ? 'pass' : 'fail', 'customers.clerk_image column: ' . ($hasClerkImage ? 'EXISTS' : '<strong>MISSING — run clerk-migration.sql!</strong>'));
    
    // Check if any Clerk users are synced
    $clerkCount = $db->fetch("SELECT COUNT(*) as cnt FROM customers WHERE clerk_id IS NOT NULL AND clerk_id != ''");
    result('info', 'Synced Clerk users in customers table: ' . ($clerkCount['cnt'] ?? 0));
} catch (Throwable $e) {
    result('fail', 'Database error: ' . $e->getMessage());
}
echo "</div>";

// ── TEST 5: Session ──
echo "<div class='card'><h2>5. PHP Session</h2>";
result(session_status() === PHP_SESSION_ACTIVE ? 'pass' : 'warn', 'Session status: ' . (session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive'));
result(true ? 'info' : 'info', 'Session save path: <code>' . session_save_path() . '</code>');
result(is_writable(session_save_path()) ? 'pass' : 'fail', 'Session path writable: ' . (is_writable(session_save_path()) ? 'Yes' : '<strong>NO</strong>'));
result(true ? 'info' : 'info', 'Session cookie params: ' . json_encode(session_get_cookie_params()));
echo "</div>";

// ── TEST 6: URLs & Endpoints ──
echo "<div class='card'><h2>6. URLs & Endpoints</h2>";
result(true ? 'info' : 'info', 'SITE_URL: <code>' . SITE_URL . '</code>');
result(true ? 'info' : 'info', 'Sync endpoint: <code>' . SITE_URL . '/api/clerk-sync.php</code>');
result(true ? 'info' : 'info', 'Login page: <code>' . SITE_URL . '/login</code>');
result(true ? 'info' : 'info', 'Webhook URL for Clerk: <code>' . SITE_URL . '/api/clerk-sync.php</code>');

// Test self-call
$selfUrl = SITE_URL . '/api/clerk-sync.php';
if (function_exists('curl_init')) {
    $ch = curl_init($selfUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['action' => 'test-internal']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    result($code >= 200 && $code < 500 ? 'pass' : 'fail', "Self-call to sync endpoint: HTTP $code" . ($r ? " — " . htmlspecialchars(substr($r, 0, 100)) : ''));
}
echo "</div>";

// ── TEST 7: CyberPanel/LiteSpeed specifics ──
echo "<div class='card'><h2>7. CyberPanel / LiteSpeed</h2>";
$isLsws = (strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'LiteSpeed') !== false);
result($isLsws ? 'info' : 'info', 'Server: ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'));
result(true ? 'info' : 'info', 'Document root: <code>' . ($_SERVER['DOCUMENT_ROOT'] ?? '') . '</code>');

// Check OPcache
$opcacheEnabled = function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false);
result(true ? 'info' : 'info', 'OPcache: ' . ($opcacheEnabled ? 'Enabled (clear cache after deploy!)' : 'Disabled'));

// Check if ModSecurity might block requests
$modSec = ini_get('modsecurity.enabled') ?? '';
result(true ? 'info' : 'info', 'ModSecurity: ' . ($modSec ? 'Enabled (may block API calls)' : 'Not detected'));
echo "</div>";

// ── Summary ──
echo "<div class='card'>";
if ($allPassed) {
    echo "<h2 class='pass'>✅ All checks passed!</h2>";
    echo "<p>If signup still doesn't work, check:</p><ul>";
    echo "<li>Open browser Console → look for <code>[Clerk Login]</code> messages</li>";
    echo "<li>Clerk Dashboard → User & Authentication → verify email verification is optional or email DNS is verified</li>";
    echo "<li>Clerk Dashboard → User & Authentication → Social connections → verify Google/Facebook are enabled</li>";
    echo "</ul>";
} else {
    echo "<h2 class='fail'>❌ Issues found — fix the items marked in red above</h2>";
}
echo "</div>";

// ── Setup Checklist ──
echo "<div class='card'><h2>📋 Clerk Setup Checklist for CyberPanel</h2>";
echo "<ol style='line-height:2'>";
echo "<li>Run <code>clerk-migration.sql</code> in phpMyAdmin or MySQL CLI</li>";
echo "<li>Deploy code files to <code>/home/khatibangla.com/public_html/</code></li>";
echo "<li>In CyberPanel → PHP → Extensions: ensure <code>curl</code> is enabled</li>";
echo "<li>In Clerk Dashboard → API Keys: copy Publishable + Secret keys</li>";
echo "<li>In your site → Admin → Settings → Clerk Auth: paste keys + enable</li>";
echo "<li>In Clerk Dashboard → User & Authentication → <strong>Email, Phone, Username</strong>: configure sign-up options</li>";
echo "<li>In Clerk Dashboard → User & Authentication → <strong>Social connections</strong>: enable Google/Facebook</li>";
echo "<li>In Clerk Dashboard → Configure → Settings → <strong>Email</strong>: if email DNS is unverified, go to Settings → Restrictions → set 'Email verification' to OFF for testing</li>";
echo "<li>In Clerk Dashboard → Developers → Webhooks: add <code>" . SITE_URL . "/api/clerk-sync.php</code></li>";
echo "<li>If using LiteSpeed cache: add <code>/api/*</code> to Do Not Cache URIs</li>";
echo "</ol></div>";
?>
</body>
</html>
