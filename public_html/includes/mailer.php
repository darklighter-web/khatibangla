<?php
/**
 * Email Helper
 * Uses PHP mail() by default, or SMTP if configured in admin settings.
 * Optimized for CyberPanel (Postfix/OpenDKIM) hosting.
 */

function sendEmail($to, $subject, $htmlBody, $textBody = '') {
    $smtpEnabled = getSetting('smtp_enabled', '0');
    
    if ($smtpEnabled === '1') {
        return sendEmailSMTP($to, $subject, $htmlBody, $textBody);
    }
    
    return sendEmailPhpMail($to, $subject, $htmlBody, $textBody);
}

/**
 * Send via PHP mail() — works if Postfix is running
 */
function sendEmailPhpMail($to, $subject, $htmlBody, $textBody = '') {
    $fromEmail = getSetting('smtp_from_email', '') ?: getSetting('site_email', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $fromName = getSetting('smtp_from_name', '') ?: getSetting('site_name', 'MyShop');
    
    $boundary = md5(uniqid(time()));
    
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = "From: {$fromName} <{$fromEmail}>";
    $headers[] = "Reply-To: {$fromEmail}";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    
    if (empty($textBody)) {
        $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));
    }
    
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textBody . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= "--{$boundary}--";
    
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

/**
 * Send via SMTP using fsockopen (no external library needed)
 * CyberPanel-aware: tries localhost fallback automatically
 */
function sendEmailSMTP($to, $subject, $htmlBody, $textBody = '') {
    $host = getSetting('smtp_host', '');
    $port = (int)getSetting('smtp_port', 587);
    $username = getSetting('smtp_username', '');
    $password = getSetting('smtp_password', '');
    $encryption = getSetting('smtp_encryption', 'tls');
    $fromEmail = getSetting('smtp_from_email', $username);
    $fromName = getSetting('smtp_from_name', '') ?: getSetting('site_name', 'MyShop');
    
    if (empty($host) || empty($username) || empty($password)) {
        return sendEmailPhpMail($to, $subject, $htmlBody, $textBody);
    }
    
    // Build connection attempts — try configured host first, then localhost fallbacks
    $attempts = [];
    $attempts[] = ['host' => $host, 'port' => $port, 'enc' => $encryption];
    
    // CyberPanel: if configured host fails, try localhost with same port
    if ($host !== 'localhost' && $host !== '127.0.0.1') {
        $attempts[] = ['host' => 'localhost', 'port' => $port, 'enc' => $encryption];
        $attempts[] = ['host' => 'localhost', 'port' => 25, 'enc' => 'none'];
        $attempts[] = ['host' => '127.0.0.1', 'port' => 25, 'enc' => 'none'];
    }
    
    foreach ($attempts as $attempt) {
        $result = _smtpSend($attempt['host'], $attempt['port'], $attempt['enc'], $username, $password, $fromEmail, $fromName, $to, $subject, $htmlBody, $textBody);
        if ($result === true) return true;
    }
    
    // All SMTP attempts failed — try PHP mail as last resort
    return sendEmailPhpMail($to, $subject, $htmlBody, $textBody);
}

/**
 * Low-level SMTP send via raw socket
 */
function _smtpSend($host, $port, $encryption, $username, $password, $fromEmail, $fromName, $to, $subject, $htmlBody, $textBody) {
    try {
        $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
        $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
        
        if (!$socket) {
            error_log("SMTP connect failed [{$host}:{$port}]: {$errstr} ({$errno})");
            return false;
        }
        
        stream_set_timeout($socket, 15);
        
        $response = @fgets($socket, 512);
        if (!$response || substr($response, 0, 3) !== '220') {
            @fclose($socket);
            return false;
        }
        
        // EHLO
        fwrite($socket, "EHLO " . (gethostname() ?: 'localhost') . "\r\n");
        $caps = '';
        while ($line = @fgets($socket, 512)) {
            $caps .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        
        // STARTTLS if needed
        if ($encryption === 'tls' && stripos($caps, 'STARTTLS') !== false) {
            fwrite($socket, "STARTTLS\r\n");
            $tlsResp = @fgets($socket, 512);
            if (substr($tlsResp, 0, 3) !== '220') {
                @fclose($socket);
                return false;
            }
            
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            
            $ctx = stream_context_get_options($socket);
            stream_context_set_option($socket, 'ssl', 'verify_peer', false);
            stream_context_set_option($socket, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($socket, 'ssl', 'allow_self_signed', true);
            
            if (!@stream_socket_enable_crypto($socket, true, $crypto)) {
                @fclose($socket);
                error_log("SMTP TLS handshake failed [{$host}:{$port}]");
                return false;
            }
            
            // Re-EHLO
            fwrite($socket, "EHLO " . (gethostname() ?: 'localhost') . "\r\n");
            while ($line = @fgets($socket, 512)) {
                if (substr($line, 3, 1) === ' ') break;
            }
        }
        
        // AUTH LOGIN (skip if port 25 localhost — Postfix may not require it)
        if ($port !== 25 || ($host !== 'localhost' && $host !== '127.0.0.1')) {
            fwrite($socket, "AUTH LOGIN\r\n");
            $authPrompt = @fgets($socket, 512);
            if (substr($authPrompt, 0, 3) === '334') {
                fwrite($socket, base64_encode($username) . "\r\n");
                @fgets($socket, 512);
                fwrite($socket, base64_encode($password) . "\r\n");
                $authResp = @fgets($socket, 512);
                if (substr($authResp, 0, 3) !== '235') {
                    @fclose($socket);
                    error_log("SMTP auth failed [{$host}:{$port}]: " . trim($authResp));
                    return false;
                }
            }
        }
        
        // MAIL FROM
        fwrite($socket, "MAIL FROM:<{$fromEmail}>\r\n");
        $fromResp = @fgets($socket, 512);
        if (substr($fromResp, 0, 3) !== '250') {
            @fclose($socket);
            return false;
        }
        
        // RCPT TO
        fwrite($socket, "RCPT TO:<{$to}>\r\n");
        $rcptResp = @fgets($socket, 512);
        if (substr($rcptResp, 0, 3) !== '250') {
            @fclose($socket);
            return false;
        }
        
        // DATA
        fwrite($socket, "DATA\r\n");
        $dataResp = @fgets($socket, 512);
        if (substr($dataResp, 0, 3) !== '354') {
            @fclose($socket);
            return false;
        }
        
        // Build message
        $boundary = md5(uniqid(time()));
        if (empty($textBody)) {
            $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));
        }
        
        $message = "Date: " . date('r') . "\r\n";
        $message .= "From: {$fromName} <{$fromEmail}>\r\n";
        $message .= "To: {$to}\r\n";
        $message .= "Subject: {$subject}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $message .= "\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $message .= $textBody . "\r\n\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";
        $message .= "--{$boundary}--\r\n";
        $message .= ".\r\n";
        
        fwrite($socket, $message);
        $sendResp = @fgets($socket, 512);
        
        fwrite($socket, "QUIT\r\n");
        @fclose($socket);
        
        $success = substr($sendResp, 0, 3) === '250';
        if (!$success) {
            error_log("SMTP send failed [{$host}:{$port}]: " . trim($sendResp));
        }
        return $success;
        
    } catch (\Throwable $e) {
        error_log("SMTP error [{$host}:{$port}]: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate password reset email HTML
 */
function getPasswordResetEmailHtml($customerName, $resetLink, $siteName) {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:40px 20px;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:500px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

<tr><td style="background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:32px 40px;text-align:center;">
    <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">{$siteName}</h1>
</td></tr>

<tr><td style="padding:40px;">
    <h2 style="margin:0 0 8px;color:#1e293b;font-size:20px;font-weight:700;">পাসওয়ার্ড রিসেট</h2>
    <p style="margin:0 0 24px;color:#64748b;font-size:14px;line-height:1.6;">
        হ্যালো <strong style="color:#1e293b;">{$customerName}</strong>,<br>
        আপনার একাউন্টের পাসওয়ার্ড রিসেট করার জন্য নিচের বাটনে ক্লিক করুন।
    </p>
    
    <table width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="padding:8px 0 24px;">
        <a href="{$resetLink}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:10px;font-size:15px;font-weight:600;letter-spacing:0.3px;">পাসওয়ার্ড রিসেট করুন</a>
    </td></tr>
    </table>
    
    <p style="margin:0 0 16px;color:#64748b;font-size:13px;line-height:1.6;">
        যদি বাটন কাজ না করে, এই লিংকটি ব্রাউজারে পেস্ট করুন:
    </p>
    <p style="margin:0 0 24px;padding:12px 16px;background:#f1f5f9;border-radius:8px;word-break:break-all;">
        <a href="{$resetLink}" style="color:#2563eb;font-size:12px;text-decoration:none;">{$resetLink}</a>
    </p>
    
    <div style="border-top:1px solid #e2e8f0;padding-top:20px;margin-top:8px;">
        <p style="margin:0;color:#94a3b8;font-size:12px;line-height:1.6;">
            ⏰ এই লিংকটি <strong>৩০ মিনিট</strong> পর মেয়াদ শেষ হবে।<br>
            🔒 আপনি যদি এই রিকোয়েস্ট না করে থাকেন, এই ইমেইল উপেক্ষা করুন।<br>
            আপনার পাসওয়ার্ড পরিবর্তন হবে না।
        </p>
    </div>
</td></tr>

<tr><td style="background:#f8fafc;padding:20px 40px;text-align:center;border-top:1px solid #e2e8f0;">
    <p style="margin:0;color:#94a3b8;font-size:11px;">&copy; {$year} {$siteName}. সর্বস্বত্ব সংরক্ষিত।</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}
