<?php
/**
 * Email Test API — with diagnostics for CyberPanel
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireAdmin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

if ($action === 'test_email') {
    $email = sanitize($_POST['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        exit;
    }
    
    $siteName = getSetting('site_name', 'MyShop');
    $html = "
    <div style='font-family:sans-serif;padding:20px;'>
        <h2 style='color:#2563eb;'>✅ Email Test Successful!</h2>
        <p>This is a test email from <strong>{$siteName}</strong>.</p>
        <p>If you received this, your email configuration is working correctly.</p>
        <p style='color:#94a3b8;font-size:12px;margin-top:20px;'>Sent at: " . date('Y-m-d H:i:s') . "</p>
    </div>";
    
    // Capture errors
    $errorMsg = '';
    set_error_handler(function($errno, $errstr) use (&$errorMsg) {
        $errorMsg .= $errstr . '; ';
        return true;
    });
    
    $sent = sendEmail($email, "Test Email — {$siteName}", $html);
    
    restore_error_handler();
    
    if ($sent) {
        echo json_encode(['success' => true, 'message' => "✅ Test email sent to {$email}! Check inbox & spam folder."]);
    } else {
        $debug = "❌ Failed to send.";
        if ($errorMsg) $debug .= " Error: " . trim($errorMsg, '; ');
        $smtpEnabled = getSetting('smtp_enabled', '0');
        if ($smtpEnabled === '1') {
            $host = getSetting('smtp_host', '');
            $port = getSetting('smtp_port', '587');
            $debug .= " | SMTP: {$host}:{$port}";
        } else {
            $debug .= " | Using PHP mail(). Try enabling SMTP.";
        }
        echo json_encode(['success' => false, 'message' => $debug]);
    }
    exit;
}

if ($action === 'diagnose') {
    $results = [];
    
    $results['php_mail_function'] = function_exists('mail') ? '✅ Available' : '❌ Disabled';
    $results['sendmail_path'] = ini_get('sendmail_path') ?: '(not set)';
    
    $results['smtp_enabled'] = getSetting('smtp_enabled', '0');
    $results['smtp_host'] = getSetting('smtp_host', '(not set)');
    $results['smtp_port'] = getSetting('smtp_port', '587');
    $results['smtp_encryption'] = getSetting('smtp_encryption', 'tls');
    $results['smtp_username'] = getSetting('smtp_username', '(not set)');
    
    // Test connectivity to common SMTP endpoints
    $targets = [
        'localhost:25' => ['host' => 'localhost', 'port' => 25, 'ssl' => false],
        'localhost:587' => ['host' => 'localhost', 'port' => 587, 'ssl' => false],
        'localhost:465' => ['host' => 'localhost', 'port' => 465, 'ssl' => true],
        'mail.menzio.store:587' => ['host' => 'mail.menzio.store', 'port' => 587, 'ssl' => false],
        'mail.menzio.store:465' => ['host' => 'mail.menzio.store', 'port' => 465, 'ssl' => true],
    ];
    
    foreach ($targets as $label => $t) {
        $pfx = $t['ssl'] ? 'ssl://' : '';
        $s = @fsockopen($pfx . $t['host'], $t['port'], $en, $es, 3);
        if ($s) {
            $b = @fgets($s, 512);
            $results["port_{$label}"] = "✅ " . trim($b ?: 'Connected');
            fclose($s);
        } else {
            $results["port_{$label}"] = "❌ {$es} ({$en})";
        }
    }
    
    // Test PHP mail() directly
    $fromEmail = getSetting('smtp_from_email', 'noreply@menzio.store');
    $mailResult = @mail('test@test.com', 'Diag Test', 'Test body', "From: {$fromEmail}\r\n");
    $results['php_mail_test'] = $mailResult ? '✅ mail() returned true' : '❌ mail() returned false';
    
    echo json_encode(['success' => true, 'diagnostics' => $results]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
