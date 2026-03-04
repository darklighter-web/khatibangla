<?php
/**
 * Security Middleware v2 — Safe for ALL devices (iOS Safari, Android, etc.)
 * 
 * KEY PRINCIPLE: Never block legitimate customers.
 * 
 * Public storefront (GET):  Security headers + bad bot block only
 * Public storefront (POST): + light request scanning (SQLi/XSS on form input)
 * Admin panel / API:        Full protection (rate limit, scanning, session guard)
 * 
 * Rate limiting: admin/API only, generous limits, NO auto-IP-block from rate limits
 * Auto-IP-block: only from confirmed attack patterns (SQLi, RFI, command injection)
 * Session protection: admin sessions only, tolerates UA changes (iOS desktop mode toggle)
 */

class SecurityGuard {
    private $db;
    private $ip;
    private $uri;
    private $method;
    private $settings = [];
    private $isAdmin = false;
    private $isApi = false;
    private $isPublic = true;
    
    public function __construct() {
        try {
            $this->db = Database::getInstance();
            $this->ip = $this->getClientIp();
            $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
            $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $this->isAdmin = strpos($this->uri, '/admin/') !== false || strpos($this->uri, '/admin') === strlen(parse_url(SITE_URL ?? '', PHP_URL_PATH) ?: '');
            $this->isApi = strpos($this->uri, '/api/') !== false;
            $this->isPublic = !$this->isAdmin && !$this->isApi;
            $this->loadSettings();
        } catch (\Throwable $e) {
            // Fail open — never break the site
            return;
        }
    }
    
    private function loadSettings() {
        static $cache = null;
        if ($cache !== null) { $this->settings = $cache; return; }
        try {
            $rows = $this->db->fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'sec_%'");
            foreach ($rows as $r) $this->settings[$r['setting_key']] = $r['setting_value'];
            $cache = $this->settings;
        } catch (\Throwable $e) {}
    }
    
    private function s($key, $default = '') {
        return $this->settings["sec_{$key}"] ?? $default;
    }
    
    public function run() {
        try {
            if ($this->s('firewall_enabled', '1') !== '1') return;
            
            // === LAYER 1: Always (all pages) ===
            $this->setSecurityHeaders();
            $this->blockBadBots();       // security scanners only — never real browsers
            
            // === LAYER 2: Admin & API only ===
            if ($this->isAdmin || $this->isApi) {
                $this->checkIpRules();   // IP blocks only enforced on admin/API
                $this->checkRateLimit();
                $this->scanRequest();
                $this->sessionProtection();
            }
            
            // === LAYER 3: Public POST only (checkout, login, contact forms) ===
            elseif ($this->method === 'POST') {
                $this->scanRequest();    // catch SQLi/XSS in form submissions
            }
            
            // Public pages: NO IP blocks, NO rate limiting, NO request scanning
            // Customers on ANY device always see the storefront
            
        } catch (\Throwable $e) {
            // Fail open — always let the customer through
        }
    }
    
    // ── Security Headers ──
    private function setSecurityHeaders() {
        if ($this->s('security_headers', '1') !== '1') return;
        
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        
        if ($this->s('force_https', '1') === '1' && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        
        $csp = $this->s('content_security_policy', '');
        if ($csp) header('Content-Security-Policy: ' . $csp);
    }
    
    // ── IP Firewall (admin/API only — never called on public pages) ──
    private function checkIpRules() {
        try {
            $rule = $this->db->fetch(
                "SELECT * FROM security_ip_rules WHERE ip_address = ? AND rule_type = 'block' AND (expires_at IS NULL OR expires_at > NOW())",
                [$this->ip]
            );
            if (!$rule) return;
            
            $this->db->query("UPDATE security_ip_rules SET hit_count = hit_count + 1 WHERE id = ?", [$rule['id']]);
            
            $this->logEvent('ip_blocked', 'high', "Blocked IP: {$this->ip}", true);
            http_response_code(403);
            echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
            echo '<body style="font-family:-apple-system,sans-serif;text-align:center;padding:50px">';
            echo '<h1>Access Restricted</h1>';
            echo '<p>Your IP address has been temporarily restricted.</p>';
            echo '</body></html>';
            exit;
        } catch (\Throwable $e) {}
    }
    
    // ── Rate Limiting (admin/API only — never public storefront) ──
    private function checkRateLimit() {
        if ($this->s('rate_limit_enabled', '1') !== '1') return;
        
        $maxReq = intval($this->s('rate_limit_requests', '120'));
        $window = intval($this->s('rate_limit_window', '60'));
        
        // API endpoints get a more generous limit
        if ($this->isApi) {
            $maxReq = max($maxReq, 200);
        }
        
        $identifier = $this->ip . ($this->isApi ? ':api' : ':admin');
        
        try {
            $this->db->query("DELETE FROM security_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)", [$window * 2]);
            
            $current = $this->db->fetch(
                "SELECT id, request_count, window_start FROM security_rate_limits WHERE identifier = ? AND window_start > DATE_SUB(NOW(), INTERVAL ? SECOND)",
                [$identifier, $window]
            );
            
            if ($current) {
                $newCount = $current['request_count'] + 1;
                $this->db->query("UPDATE security_rate_limits SET request_count = ? WHERE id = ?", [$newCount, $current['id']]);
                
                if ($newCount > $maxReq) {
                    $this->logEvent('rate_limit', 'medium', "Rate limit exceeded: {$newCount}/{$maxReq} in {$window}s");
                    
                    // Return 429 but NEVER auto-block IP from rate limits
                    http_response_code(429);
                    header('Retry-After: ' . $window);
                    if ($this->isApi) {
                        echo json_encode(['error' => 'Too many requests. Please slow down.', 'retry_after' => $window]);
                    } else {
                        echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">';
                        echo '<meta http-equiv="refresh" content="' . min($window, 10) . '">';
                        echo '</head><body style="font-family:-apple-system,sans-serif;text-align:center;padding:50px">';
                        echo '<h2>Please slow down</h2><p>Too many requests. Page will auto-refresh shortly.</p></body></html>';
                    }
                    exit;
                }
            } else {
                $this->db->insert('security_rate_limits', [
                    'identifier' => $identifier,
                    'endpoint' => parse_url($this->uri, PHP_URL_PATH),
                    'request_count' => 1,
                ]);
            }
        } catch (\Throwable $e) {}
    }
    
    // ── Request Scanning (SQLi, XSS, Path Traversal) ──
    private function scanRequest() {
        $allInput = array_merge($_GET, $_POST);
        if (empty($allInput)) return;
        
        $inputStr = implode(' ', array_values(array_map('strval', $this->flattenArray($allInput))));
        if (strlen($inputStr) < 3) return;
        
        // ── SQL Injection ──
        if ($this->s('sqli_protection', '1') === '1') {
            $sqliPatterns = [
                '/(\bunion\b.*\bselect\b)/i',
                '/(\bselect\b.*\bfrom\b.*\bwhere\b)/i',
                '/(\'|\");\s*(drop|alter|truncate|delete|insert|update)\s/i',
                '/(sleep|benchmark|load_file|into\s+outfile|into\s+dumpfile)\s*\(/i',
                '/(\bexec\b|\bexecute\b)\s*(sp_|xp_)/i',
                '/(information_schema|mysql\.user|sys\.)/i',
            ];
            foreach ($sqliPatterns as $pattern) {
                if (preg_match($pattern, $inputStr)) {
                    $this->logEvent('sql_injection', 'critical', 'SQLi detected: ' . mb_substr($inputStr, 0, 300), true);
                    $this->createBreachAlert('SQL Injection Attempt', "Suspicious SQL pattern from IP: {$this->ip}");
                    if (!$this->isPublic) {
                        $this->autoBlockIp('SQL injection attempt');
                    }
                    http_response_code(403);
                    exit('Forbidden');
                }
            }
        }
        
        // ── XSS ──
        if ($this->s('xss_protection', '1') === '1') {
            $xssPatterns = [
                '/<script[\s>]/i',
                '/javascript\s*:/i',
                '/on(error|load|click|mouseover|focus|blur|submit|change)\s*=/i',
                '/<(iframe|object|embed|applet|base|link|meta|svg|math)/i',
                '/document\.(cookie|write|location)/i',
                '/window\.(location|open)/i',
            ];
            // Skip XSS check for admin saves (they legitimately contain HTML)
            $isAdminSave = $this->isAdmin && $this->method === 'POST';
            if (!$isAdminSave) {
                foreach ($xssPatterns as $pattern) {
                    if (preg_match($pattern, $inputStr)) {
                        $this->logEvent('xss_attempt', 'high', 'XSS attempt: ' . mb_substr($inputStr, 0, 300), true);
                        if (!$this->isPublic) {
                            $this->createBreachAlert('XSS Attack Attempt', "XSS attempt from IP: {$this->ip}");
                        }
                        http_response_code(403);
                        exit('Forbidden');
                    }
                }
            }
        }
        
        // ── Path traversal (admin/API only — public URLs have product slugs) ──
        if (!$this->isPublic) {
            $pathTraversal = ['../', '..\\', '%2e%2e', '%252e%252e', '/etc/passwd', '/proc/self'];
            foreach ($pathTraversal as $pt) {
                if (stripos($this->uri, $pt) !== false || stripos($inputStr, $pt) !== false) {
                    $this->logEvent('path_traversal', 'high', "Path traversal: {$this->uri}", true);
                    http_response_code(403);
                    exit('Forbidden');
                }
            }
        }
        
        // ── WordPress probe blocking (all pages — these are always attacks) ──
        $wpProbes = ['wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'xmlrpc.php'];
        foreach ($wpProbes as $probe) {
            if (stripos($this->uri, $probe) !== false) {
                $this->logEvent('wp_probe', 'medium', "WordPress probe: {$this->uri}", true);
                http_response_code(404);
                exit;
            }
        }
        
        // ── Null byte injection ──
        if (strpos($this->uri, '%00') !== false || strpos($inputStr, "\0") !== false || strpos($inputStr, '%00') !== false) {
            $this->logEvent('null_byte', 'critical', "Null byte injection: {$this->uri}", true);
            $this->autoBlockIp('Null byte injection');
            http_response_code(403);
            exit('Forbidden');
        }
        
        // ── Remote File Inclusion / SSRF (admin/API only) ──
        if (!$this->isPublic && $this->s('sqli_protection', '1') === '1') {
            $rfiPatterns = ['/php:\/\/input/i', '/php:\/\/filter/i', '/data:\/\/text/i', '/expect:\/\//i'];
            foreach ($rfiPatterns as $rfi) {
                if (preg_match($rfi, $inputStr)) {
                    $this->logEvent('rfi_attempt', 'critical', 'RFI/SSRF: ' . mb_substr($inputStr, 0, 300), true);
                    $this->createBreachAlert('Remote File Inclusion', "RFI/SSRF attempt from IP: {$this->ip}");
                    $this->autoBlockIp('RFI/SSRF attempt');
                    http_response_code(403);
                    exit('Forbidden');
                }
            }
        }
        
        // ── Command injection (admin/API only) ──
        if (!$this->isPublic) {
            $cmdPatterns = ['/;\s*(cat|ls|wget|curl|bash|sh|nc|netcat|python|perl|ruby|php)\s/i', '/\|\s*(cat|ls|id|whoami|uname|pwd)/i', '/`[^`]+`/'];
            foreach ($cmdPatterns as $cp) {
                if (preg_match($cp, $inputStr)) {
                    $this->logEvent('cmd_injection', 'critical', 'Command injection: ' . mb_substr($inputStr, 0, 200), true);
                    $this->createBreachAlert('Command Injection', "CMD injection attempt from IP: {$this->ip}");
                    $this->autoBlockIp('Command injection attempt');
                    http_response_code(403);
                    exit('Forbidden');
                }
            }
        }
    }
    
    // ── Bad Bot Blocking (all pages — ONLY known attack tools, never real browsers) ──
    private function blockBadBots() {
        if ($this->s('block_bad_bots', '1') !== '1') return;
        
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (empty($ua)) return; // Empty UA is fine — some privacy browsers / older devices
        
        $badBots = [
            'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'nuclei',
            'dirbuster', 'gobuster', 'wfuzz', 'hydra', 'burpsuite',
            'nessus', 'acunetix', 'havij', 'w3af', 'skipfish',
            'openvas', 'qualys', 'whatweb', 'grabber', 'httprint',
        ];
        foreach ($badBots as $bot) {
            if (strpos($ua, $bot) !== false) {
                $this->logEvent('bad_bot', 'high', "Blocked scanner: {$bot}", true);
                $this->autoBlockIp("Scanner detected: {$bot}");
                http_response_code(403);
                exit;
            }
        }
    }
    
    // ── Session Protection (admin only — never public visitors) ──
    private function sessionProtection() {
        if ($this->s('session_protection', '1') !== '1') return;
        if (!$this->isAdmin) return;
        
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443;
            session_set_cookie_params([
                'lifetime' => 0, 'path' => '/', 'domain' => '',
                'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        if (empty($_SESSION['admin_id'])) return;
        
        // Session fixation prevention
        if (!isset($_SESSION['_sec_created'])) {
            $_SESSION['_sec_created'] = time();
            $_SESSION['_sec_ip'] = $this->ip;
            $_SESSION['_sec_ua_hash'] = $this->uaHash();
        }
        
        // IP change: log but allow (mobile networks switch IPs frequently)
        if (isset($_SESSION['_sec_ip']) && $_SESSION['_sec_ip'] !== $this->ip) {
            $this->logEvent('session_ip_change', 'low', "Admin session IP changed: {$_SESSION['_sec_ip']} → {$this->ip}");
            $_SESSION['_sec_ip'] = $this->ip;
            session_regenerate_id(true);
            $_SESSION['_sec_created'] = time();
        }
        
        // UA change: fuzzy match (iOS Safari changes UA toggling desktop/mobile mode)
        $currentHash = $this->uaHash();
        if (isset($_SESSION['_sec_ua_hash']) && $_SESSION['_sec_ua_hash'] !== '' && $_SESSION['_sec_ua_hash'] !== $currentHash) {
            $this->logEvent('session_ua_change', 'low', "Admin session UA changed (likely device mode toggle)");
            $_SESSION['_sec_ua_hash'] = $currentHash;
            session_regenerate_id(true);
            $_SESSION['_sec_created'] = time();
        }
        
        // Regenerate session ID every 30 minutes
        if (time() - ($_SESSION['_sec_created'] ?? 0) > 1800) {
            session_regenerate_id(true);
            $_SESSION['_sec_created'] = time();
        }
        
        // Session timeout
        $timeout = intval($this->s('session_timeout_minutes', '120')) * 60;
        if (isset($_SESSION['_sec_last_active']) && (time() - $_SESSION['_sec_last_active'] > $timeout)) {
            session_destroy();
            return;
        }
        $_SESSION['_sec_last_active'] = time();
    }
    
    /**
     * Fuzzy UA hash — extracts browser family (Safari/Chrome/Firefox + OS)
     * instead of exact UA string. Prevents iOS Safari desktop-mode toggle
     * from being treated as session hijacking.
     */
    private function uaHash() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $parts = [];
        if (stripos($ua, 'Safari') !== false) $parts[] = 'Safari';
        if (stripos($ua, 'Chrome') !== false) $parts[] = 'Chrome';
        if (stripos($ua, 'Firefox') !== false) $parts[] = 'Firefox';
        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $parts[] = 'iOS';
        if (stripos($ua, 'Android') !== false) $parts[] = 'Android';
        if (stripos($ua, 'Windows') !== false) $parts[] = 'Windows';
        if (stripos($ua, 'Macintosh') !== false) $parts[] = 'Mac';
        if (stripos($ua, 'Linux') !== false) $parts[] = 'Linux';
        return md5(implode('|', $parts));
    }
    
    // ── Brute Force Check (called from login handler) ──
    public function checkBruteForce($username = '') {
        if ($this->s('brute_force_enabled', '1') !== '1') return true;
        
        $maxAttempts = intval($this->s('brute_force_max_attempts', '5'));
        $lockoutMin = intval($this->s('brute_force_lockout_minutes', '30'));
        
        try {
            $recentFails = $this->db->fetch(
                "SELECT COUNT(*) as cnt FROM security_login_attempts WHERE ip_address = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$this->ip, $lockoutMin]
            );
            
            if (intval($recentFails['cnt'] ?? 0) >= $maxAttempts) {
                $this->logEvent('brute_force', 'critical', "Brute force lockout: {$recentFails['cnt']} failed attempts for '{$username}'", true);
                $this->createBreachAlert('Brute Force Attack', "IP {$this->ip} locked out after {$recentFails['cnt']} failed login attempts");
                return false;
            }
        } catch (\Throwable $e) {}
        return true;
    }
    
    public function logLoginAttempt($username, $success) {
        try {
            $this->db->insert('security_login_attempts', [
                'ip_address' => $this->ip,
                'username' => mb_substr($username, 0, 100),
                'success' => $success ? 1 : 0,
                'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
            if (!$success) {
                $this->logEvent('login_failed', 'medium', "Failed login for '{$username}'");
            } else {
                $this->logEvent('admin_login', 'low', "Successful login for '{$username}'");
                $this->db->query("DELETE FROM security_login_attempts WHERE ip_address = ? AND success = 0", [$this->ip]);
            }
        } catch (\Throwable $e) {}
    }
    
    // ── File Upload Validation ──
    public function validateUpload($file, $customAllowed = null) {
        if ($this->s('file_upload_scan', '1') !== '1') return true;
        
        $allowed = $customAllowed ?: explode(',', $this->s('allowed_upload_types', 'jpg,jpeg,png,gif,webp,pdf'));
        $maxSize = intval($this->s('max_upload_size_mb', '10')) * 1024 * 1024;
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            $this->logEvent('file_upload_blocked', 'high', "Blocked upload: .{$ext} not allowed - {$file['name']}", true);
            return 'File type not allowed: .' . $ext;
        }
        
        if ($file['size'] > $maxSize) {
            return 'File too large. Max: ' . $this->s('max_upload_size_mb', '10') . 'MB';
        }
        
        $content = file_get_contents($file['tmp_name'], false, null, 0, 4096);
        $dangerousPatterns = ['<?php', '<?=', '<script', 'eval(', 'exec(', 'system(', 'passthru(', 'shell_exec(', 'base64_decode('];
        foreach ($dangerousPatterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                $this->logEvent('malware_detected', 'critical', "Malicious file blocked: {$file['name']} contains '{$pattern}'", true);
                $this->createBreachAlert('Malware Upload Blocked', "Malicious file '{$file['name']}' from IP: {$this->ip}");
                return 'File contains potentially malicious content';
            }
        }
        
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $validMimes = ['image/jpeg','image/png','image/gif','image/webp'];
            if (!in_array($mime, $validMimes)) {
                $this->logEvent('file_upload_blocked', 'high', "MIME mismatch: {$ext} but detected {$mime}", true);
                return 'File type does not match content';
            }
        }
        
        return true;
    }
    
    // ── Helpers ──
    private function logEvent($type, $severity, $payload, $blocked = false) {
        try {
            $this->db->insert('security_logs', [
                'event_type' => $type,
                'severity' => $severity,
                'ip_address' => $this->ip,
                'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                'request_uri' => mb_substr($this->uri, 0, 500),
                'request_method' => $this->method,
                'payload' => mb_substr($payload, 0, 1000),
                'blocked' => $blocked ? 1 : 0,
                'admin_user_id' => $_SESSION['admin_id'] ?? null,
                'session_id' => session_id() ?: null,
            ]);
        } catch (\Throwable $e) {}
    }
    
    private function createBreachAlert($title, $message) {
        if ($this->s('breach_alerts', '1') !== '1') return;
        try {
            $existing = $this->db->fetch(
                "SELECT id FROM notifications WHERE type = 'security_breach' AND title = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
                [$title]
            );
            if ($existing) return;
            
            $this->db->insert('notifications', [
                'type' => 'security_breach',
                'title' => '🛡️ ' . $title,
                'message' => $message . ' at ' . date('H:i:s'),
                'link' => 'pages/security.php',
                'is_read' => 0,
                'user_id' => null,
            ]);
        } catch (\Throwable $e) {}
    }
    
    private function autoBlockIp($reason) {
        try {
            // auto_blocked=1 so public pages can skip enforcement for shared IPs
            $this->db->query(
                "INSERT INTO security_ip_rules (ip_address, rule_type, reason, auto_blocked, expires_at) 
                 VALUES (?, 'block', ?, 1, DATE_ADD(NOW(), INTERVAL 24 HOUR)) 
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason), hit_count = hit_count + 1, 
                 auto_blocked = 1, expires_at = GREATEST(expires_at, DATE_ADD(NOW(), INTERVAL 24 HOUR))",
                [$this->ip, mb_substr($reason, 0, 255)]
            );
        } catch (\Throwable $e) {
            // If auto_blocked column doesn't exist, fall back
            try {
                $this->db->query(
                    "INSERT INTO security_ip_rules (ip_address, rule_type, reason, expires_at) 
                     VALUES (?, 'block', ?, DATE_ADD(NOW(), INTERVAL 24 HOUR)) 
                     ON DUPLICATE KEY UPDATE reason = VALUES(reason), hit_count = hit_count + 1, 
                     expires_at = GREATEST(expires_at, DATE_ADD(NOW(), INTERVAL 24 HOUR))",
                    [$this->ip, mb_substr($reason, 0, 255)]
                );
            } catch (\Throwable $e2) {}
        }
    }
    
    public function getClientIp() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    private function flattenArray($arr, $prefix = '') {
        $result = [];
        foreach ($arr as $k => $v) {
            if (is_array($v)) $result = array_merge($result, $this->flattenArray($v, $prefix . $k . '.'));
            else $result[$prefix . $k] = (string)$v;
        }
        return $result;
    }
    
    /** Unblock all auto-blocked IPs */
    public function clearAutoBlocks() {
        try {
            return $this->db->query("DELETE FROM security_ip_rules WHERE rule_type = 'block' AND auto_blocked = 1");
        } catch (\Throwable $e) {
            try { return $this->db->query("DELETE FROM security_ip_rules WHERE rule_type = 'block' AND expires_at IS NOT NULL AND expires_at < NOW()"); } catch (\Throwable $e2) { return 0; }
        }
    }
    
    /** Unblock a specific IP */
    public function unblockIp($ip) {
        try { $this->db->query("DELETE FROM security_ip_rules WHERE ip_address = ? AND rule_type = 'block'", [$ip]); return true; } catch (\Throwable $e) { return false; }
    }
}

// Auto-run
try {
    $GLOBALS['_securityGuard'] = new SecurityGuard();
    $GLOBALS['_securityGuard']->run();
} catch (\Throwable $e) {
    // Fail open — NEVER break the website
}

function getSecurityGuard() {
    return $GLOBALS['_securityGuard'] ?? null;
}
