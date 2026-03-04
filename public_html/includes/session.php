<?php
/**
 * Session Initialization
 * Handles session save path for shared hosting (LiteSpeed/cPanel)
 * Security: Secure, HttpOnly, SameSite flags on session cookie
 * 
 * FIXES: PentestTools findings:
 *   - "Insecure cookie setting: missing Secure flag"   (CWE-614)
 *   - "Insecure cookie setting: missing HttpOnly flag"  (CWE-1004)
 */
if (session_status() === PHP_SESSION_NONE) {
    $sessDir = dirname(__DIR__) . '/tmp/sessions';
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0700, true);
    }
    if (is_dir($sessDir) && is_writable($sessDir)) {
        session_save_path($sessDir);
    }
    
    // Detect HTTPS (including behind Cloudflare / reverse proxy)
    $isSecure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ($_SERVER['SERVER_PORT'] ?? 0) == 443 ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
    );
    
    // Belt-and-suspenders: ini_set + session_set_cookie_params
    ini_set('session.cookie_secure', $isSecure ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_lifetime', '0');
    
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);
    
    // Use stronger session ID
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    
    session_start();
    
    // Force re-set cookie with correct flags on every request (nuclear option)
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(), [
            'expires'  => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }
    
    // Regenerate session ID periodically to prevent fixation
    if (!isset($_SESSION['_sess_created'])) {
        $_SESSION['_sess_created'] = time();
    } elseif (time() - $_SESSION['_sess_created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_sess_created'] = time();
    }
}
