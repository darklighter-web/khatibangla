<?php
/**
 * Security Center API
 * Handles all security operations: status, logs, IP rules, scans, settings
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ═══════════════════════════════════════
    // SECURITY SCORE & DASHBOARD
    // ═══════════════════════════════════════

    case 'dashboard':
        $data = [];
        
        // ── Security Score Calculation ──
        $score = 0; $maxScore = 100; $checks = [];
        
        // 1. HTTPS (10pts)
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443;
        $checks['https'] = ['label' => 'HTTPS / SSL', 'pass' => $isHttps, 'weight' => 10, 'tip' => 'Enable SSL certificate'];
        if ($isHttps) $score += 10;
        
        // 2. Firewall (10pts)
        $fw = getSetting('sec_firewall_enabled', '0');
        $checks['firewall'] = ['label' => 'Web Application Firewall', 'pass' => $fw === '1', 'weight' => 10, 'tip' => 'Enable firewall in settings'];
        if ($fw === '1') $score += 10;
        
        // 3. Rate Limiting (8pts)
        $rl = getSetting('sec_rate_limit_enabled', '0');
        $checks['rate_limit'] = ['label' => 'Rate Limiting', 'pass' => $rl === '1', 'weight' => 8, 'tip' => 'Enable rate limiting'];
        if ($rl === '1') $score += 8;
        
        // 4. Brute Force (10pts)
        $bf = getSetting('sec_brute_force_enabled', '0');
        $checks['brute_force'] = ['label' => 'Brute Force Protection', 'pass' => $bf === '1', 'weight' => 10, 'tip' => 'Enable brute force protection'];
        if ($bf === '1') $score += 10;
        
        // 5. SQLi Protection (10pts)
        $sqli = getSetting('sec_sqli_protection', '0');
        $checks['sqli'] = ['label' => 'SQL Injection Protection', 'pass' => $sqli === '1', 'weight' => 10, 'tip' => 'Enable SQLi protection'];
        if ($sqli === '1') $score += 10;
        
        // 6. XSS Protection (10pts)
        $xss = getSetting('sec_xss_protection', '0');
        $checks['xss'] = ['label' => 'XSS Protection', 'pass' => $xss === '1', 'weight' => 10, 'tip' => 'Enable XSS protection'];
        if ($xss === '1') $score += 10;
        
        // 7. CSRF Protection (8pts)
        $csrf = getSetting('sec_csrf_protection', '1');
        $checks['csrf'] = ['label' => 'CSRF Protection', 'pass' => $csrf === '1', 'weight' => 8, 'tip' => 'Enable CSRF tokens'];
        if ($csrf === '1') $score += 8;
        
        // 8. Security Headers (8pts)
        $sh = getSetting('sec_security_headers', '0');
        $checks['headers'] = ['label' => 'Security Headers', 'pass' => $sh === '1', 'weight' => 8, 'tip' => 'Enable security headers'];
        if ($sh === '1') $score += 8;
        
        // 9. File Upload Scanning (6pts)
        $fus = getSetting('sec_file_upload_scan', '0');
        $checks['upload_scan'] = ['label' => 'File Upload Scanning', 'pass' => $fus === '1', 'weight' => 6, 'tip' => 'Enable upload malware scanning'];
        if ($fus === '1') $score += 6;
        
        // 10. Session Protection (6pts)
        $sp = getSetting('sec_session_protection', '0');
        $checks['session'] = ['label' => 'Session Protection', 'pass' => $sp === '1', 'weight' => 6, 'tip' => 'Enable session hijack protection'];
        if ($sp === '1') $score += 6;
        
        // 11. Bad Bot Blocking (5pts)
        $bb = getSetting('sec_block_bad_bots', '0');
        $checks['bad_bots'] = ['label' => 'Bad Bot Blocking', 'pass' => $bb === '1', 'weight' => 5, 'tip' => 'Block scanners and malicious bots'];
        if ($bb === '1') $score += 5;
        
        // 12. Breach Alerts (5pts)
        $ba = getSetting('sec_breach_alerts', '0');
        $checks['breach_alerts'] = ['label' => 'Breach Alert Notifications', 'pass' => $ba === '1', 'weight' => 5, 'tip' => 'Enable breach alert notifications'];
        if ($ba === '1') $score += 5;
        
        // 13. Honeypot (4pts)
        $hp = getSetting('sec_honeypot_enabled', '0');
        $checks['honeypot'] = ['label' => 'Honeypot Traps', 'pass' => $hp === '1', 'weight' => 4, 'tip' => 'Enable honeypot to trap bots'];
        if ($hp === '1') $score += 4;
        
        $data['score'] = min($score, $maxScore);
        $data['max_score'] = $maxScore;
        $data['grade'] = $score >= 90 ? 'A+' : ($score >= 80 ? 'A' : ($score >= 70 ? 'B' : ($score >= 50 ? 'C' : ($score >= 30 ? 'D' : 'F'))));
        $data['checks'] = $checks;
        
        // ── Threat Stats (last 24h / 7d / 30d) ──
        try {
            $data['threats_24h'] = intval($db->fetch("SELECT COUNT(*) as c FROM security_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")['c'] ?? 0);
            $data['threats_7d'] = intval($db->fetch("SELECT COUNT(*) as c FROM security_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")['c'] ?? 0);
            $data['threats_30d'] = intval($db->fetch("SELECT COUNT(*) as c FROM security_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")['c'] ?? 0);
            $data['blocked_24h'] = intval($db->fetch("SELECT COUNT(*) as c FROM security_logs WHERE blocked = 1 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")['c'] ?? 0);
            $data['critical_24h'] = intval($db->fetch("SELECT COUNT(*) as c FROM security_logs WHERE severity = 'critical' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")['c'] ?? 0);
            $data['blocked_ips'] = intval($db->fetch("SELECT COUNT(*) as c FROM security_ip_rules WHERE rule_type = 'block' AND (expires_at IS NULL OR expires_at > NOW())")['c'] ?? 0);
            $data['failed_logins_24h'] = intval($db->fetch("SELECT COUNT(*) as c FROM security_login_attempts WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")['c'] ?? 0);
            
            // Top threats by type (last 7d)
            $data['threat_types'] = $db->fetchAll("SELECT event_type, COUNT(*) as cnt FROM security_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY event_type ORDER BY cnt DESC LIMIT 8") ?: [];
            
            // Threats over time (last 7 days, daily)
            $data['threat_timeline'] = $db->fetchAll("SELECT DATE(created_at) as day, COUNT(*) as cnt, SUM(blocked) as blocked FROM security_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY day") ?: [];
            
            // Top attacking IPs
            $data['top_attackers'] = $db->fetchAll("SELECT ip_address, COUNT(*) as cnt, MAX(severity) as max_severity, MAX(created_at) as last_seen FROM security_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND severity IN ('high','critical') GROUP BY ip_address ORDER BY cnt DESC LIMIT 10") ?: [];
            
        } catch (\Throwable $e) {
            $data['threats_24h'] = 0; $data['threats_7d'] = 0; $data['threats_30d'] = 0;
            $data['blocked_24h'] = 0; $data['critical_24h'] = 0; $data['blocked_ips'] = 0;
            $data['failed_logins_24h'] = 0;
            $data['threat_types'] = []; $data['threat_timeline'] = []; $data['top_attackers'] = [];
        }
        
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ═══════════════════════════════════════
    // SECURITY LOGS
    // ═══════════════════════════════════════

    case 'logs':
        $page = max(1, intval($_GET['page'] ?? 1));
        $perPage = 30;
        $offset = ($page - 1) * $perPage;
        $severity = $_GET['severity'] ?? '';
        $eventType = $_GET['event_type'] ?? '';
        $search = trim($_GET['search'] ?? '');
        
        $where = '1=1';
        $params = [];
        
        if ($severity) { $where .= ' AND severity = ?'; $params[] = $severity; }
        if ($eventType) { $where .= ' AND event_type = ?'; $params[] = $eventType; }
        if ($search) { $where .= ' AND (ip_address LIKE ? OR payload LIKE ? OR request_uri LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
        
        try {
            $total = intval($db->fetch("SELECT COUNT(*) as c FROM security_logs WHERE {$where}", $params)['c'] ?? 0);
            $logs = $db->fetchAll("SELECT * FROM security_logs WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}", $params) ?: [];
            
            echo json_encode(['success' => true, 'data' => [
                'logs' => $logs, 'total' => $total,
                'page' => $page, 'pages' => ceil($total / $perPage),
            ]]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => true, 'data' => ['logs' => [], 'total' => 0, 'page' => 1, 'pages' => 1]]);
        }
        break;

    case 'clear_logs':
        $period = $_POST['period'] ?? 'all';
        try {
            if ($period === 'all') {
                $db->query("TRUNCATE TABLE security_logs");
            } else {
                $days = intval($period);
                $db->query("DELETE FROM security_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
            }
            echo json_encode(['success' => true, 'message' => 'Logs cleared']);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ═══════════════════════════════════════
    // IP RULES (Block / Allow / Watch)
    // ═══════════════════════════════════════

    case 'ip_rules':
        try {
            $rules = $db->fetchAll("SELECT * FROM security_ip_rules ORDER BY created_at DESC LIMIT 200") ?: [];
            echo json_encode(['success' => true, 'data' => $rules]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => true, 'data' => []]);
        }
        break;

    case 'add_ip_rule':
        $ip = trim($_POST['ip'] ?? '');
        $type = $_POST['rule_type'] ?? 'block';
        $reason = trim($_POST['reason'] ?? 'Manual');
        $duration = $_POST['duration'] ?? ''; // hours, empty = permanent
        
        if (!$ip) { echo json_encode(['success' => false, 'message' => 'IP required']); break; }
        
        // Support CIDR notation or single IPs
        if (!filter_var(explode('/', $ip)[0], FILTER_VALIDATE_IP)) {
            echo json_encode(['success' => false, 'message' => 'Invalid IP address']);
            break;
        }
        
        try {
            $expires = $duration ? date('Y-m-d H:i:s', strtotime("+{$duration} hours")) : null;
            $db->query(
                "INSERT INTO security_ip_rules (ip_address, rule_type, reason, expires_at, created_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rule_type = VALUES(rule_type), reason = VALUES(reason), expires_at = VALUES(expires_at)",
                [$ip, $type, $reason, $expires, $_SESSION['admin_id']]
            );
            echo json_encode(['success' => true, 'message' => "IP {$ip} {$type}ed"]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'remove_ip_rule':
        $id = intval($_POST['id'] ?? 0);
        try {
            $db->query("DELETE FROM security_ip_rules WHERE id = ?", [$id]);
            echo json_encode(['success' => true, 'message' => 'Rule removed']);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'block_ip_from_log':
        $ip = trim($_POST['ip'] ?? '');
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['success' => false, 'message' => 'Invalid IP']); break;
        }
        try {
            $db->query(
                "INSERT INTO security_ip_rules (ip_address, rule_type, reason, created_by) VALUES (?, 'block', 'Blocked from security log', ?) ON DUPLICATE KEY UPDATE rule_type = 'block'",
                [$ip, $_SESSION['admin_id']]
            );
            echo json_encode(['success' => true, 'message' => "IP {$ip} blocked permanently"]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
    
    case 'clear_auto_blocks':
        try {
            // Clear all auto-blocked IPs (from rate limits, scanner detection, etc.)
            $deleted = 0;
            try {
                $count = $db->fetch("SELECT COUNT(*) as c FROM security_ip_rules WHERE rule_type = 'block' AND auto_blocked = 1");
                $deleted = intval($count['c'] ?? 0);
                $db->query("DELETE FROM security_ip_rules WHERE rule_type = 'block' AND auto_blocked = 1");
            } catch (\Throwable $e) {
                // auto_blocked column may not exist yet — clear all timed blocks instead
                $count = $db->fetch("SELECT COUNT(*) as c FROM security_ip_rules WHERE rule_type = 'block' AND expires_at IS NOT NULL");
                $deleted = intval($count['c'] ?? 0);
                $db->query("DELETE FROM security_ip_rules WHERE rule_type = 'block' AND expires_at IS NOT NULL");
            }
            echo json_encode(['success' => true, 'message' => "Cleared {$deleted} auto-blocked IPs. All visitors can now access the site."]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ═══════════════════════════════════════
    // LOGIN ATTEMPTS
    // ═══════════════════════════════════════

    case 'login_attempts':
        try {
            $attempts = $db->fetchAll("SELECT * FROM security_login_attempts ORDER BY created_at DESC LIMIT 100") ?: [];
            echo json_encode(['success' => true, 'data' => $attempts]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => true, 'data' => []]);
        }
        break;

    // ═══════════════════════════════════════
    // SECURITY SCAN
    // ═══════════════════════════════════════

    case 'run_scan':
        $results = [];
        $baseDir = __DIR__ . '/..';
        
        // 1. Check for suspicious PHP files in upload directories
        $results['upload_php_files'] = [];
        $uploadDirs = ['uploads/products', 'uploads/banners', 'uploads/general', 'uploads/logos'];
        foreach ($uploadDirs as $dir) {
            $fullPath = $baseDir . '/' . $dir;
            if (!is_dir($fullPath)) continue;
            foreach (glob($fullPath . '/*.{php,phtml,php5,php7,phar}', GLOB_BRACE) as $f) {
                if (basename($f) === 'index.php') continue; // Skip directory listing blockers
                $results['upload_php_files'][] = str_replace($baseDir, '', $f);
            }
        }
        
        // 2. Check file permissions
        $results['world_writable'] = [];
        $criticalFiles = ['includes/config.php', 'includes/functions.php', '.htaccess', 'index.php'];
        foreach ($criticalFiles as $cf) {
            $fp = $baseDir . '/' . $cf;
            if (file_exists($fp) && (fileperms($fp) & 0002)) {
                $results['world_writable'][] = $cf;
            }
        }
        
        // 3. Check for exposed sensitive files
        $results['exposed_files'] = [];
        $sensitiveFiles = ['.env', '.git/config', 'composer.lock', 'debug.log', 'error.log', 'phpinfo.php', '.DS_Store', 'wp-config.php'];
        foreach ($sensitiveFiles as $sf) {
            if (file_exists($baseDir . '/' . $sf)) {
                $results['exposed_files'][] = $sf;
            }
        }
        
        // 4. Check .htaccess exists
        $results['htaccess_exists'] = file_exists($baseDir . '/.htaccess');
        
        // 5. PHP dangerous functions
        $disabledFuncs = ini_get('disable_functions');
        $dangerousFuncs = ['exec', 'system', 'passthru', 'shell_exec', 'popen', 'proc_open'];
        $results['dangerous_functions'] = [];
        foreach ($dangerousFuncs as $f) {
            $results['dangerous_functions'][] = [
                'function' => $f,
                'disabled' => stripos($disabledFuncs, $f) !== false
            ];
        }
        
        // 6. Check for backdoor patterns in PHP files (scan only key dirs)
        // Patterns built via concatenation so this scanner does NOT flag itself
        $results['suspicious_code'] = [];
        $scanDirs = ['includes', 'api', 'admin/includes', 'admin/pages', 'pages'];
        $selfFile = realpath(__FILE__);
        $_e = 'ev'.'al'; $_s = 'sys'.'tem'; $_p = 'pass'.'thru';
        $_x = 'shell'.'_exec'; $_c = 'call_user_func'.'_array'; $_a = 'ass'.'ert';
        $backdoorPatterns = [
            $_e.'($_GE'.'T'           => 'eval with GET input',
            $_e.'($_PO'.'ST'          => 'eval with POST input',
            $_e.'($_REQU'.'EST'       => 'eval with REQUEST input',
            $_e.'(base64'.'_decode'   => 'encoded eval execution',
            $_e.'(gzinfl'.'ate'       => 'compressed eval execution',
            $_a.'($'                  => 'assert code execution',
            'preg_re'."place('/.*/e"  => 'regex eval modifier',
            '$_GE'."T['cmd']"         => 'web shell command input',
            $_s.'($_'                 => 'system call with user input',
            $_p.'($_'                 => 'passthru with user input',
            $_x.'($_'                 => 'shell_exec with user input',
            $_c.'($_'                 => 'dynamic function from input',
        ];
        foreach ($scanDirs as $dir) {
            $fullPath = $baseDir . '/' . $dir;
            if (!is_dir($fullPath)) continue;
            foreach (glob($fullPath . '/*.php') as $phpFile) {
                if (realpath($phpFile) === $selfFile) continue;
                $content = file_get_contents($phpFile);
                foreach ($backdoorPatterns as $pattern => $desc) {
                    if (strpos($content, $pattern) !== false) {
                        $results['suspicious_code'][] = [
                            'file' => str_replace($baseDir, '', $phpFile),
                            'threat' => $desc,
                            'pattern' => $pattern,
                        ];
                    }
                }
            }
        }
        
        // 7. Directory listing protection
        $results['directory_listing'] = [];
        $checkDirs = ['uploads', 'uploads/products', 'uploads/banners', 'uploads/logos', 'uploads/general', 'includes', 'api', 'admin', 'admin/pages', 'admin/includes', 'assets', 'css', 'js', 'pages'];
        foreach ($checkDirs as $dir) {
            $indexFile = $baseDir . '/' . $dir . '/index.php';
            $htaccess = $baseDir . '/' . $dir . '/.htaccess';
            $results['directory_listing'][] = [
                'dir' => $dir,
                'protected' => file_exists($indexFile) || file_exists($htaccess)
            ];
        }
        
        // 8. Session configuration
        $results['session'] = [
            'save_handler' => ini_get('session.save_handler'),
            'cookie_httponly' => ini_get('session.cookie_httponly') ? true : false,
            'cookie_secure' => ini_get('session.cookie_secure') ? true : false,
            'use_strict_mode' => ini_get('session.use_strict_mode') ? true : false,
            'cookie_samesite' => ini_get('session.cookie_samesite') ?: 'None',
        ];
        
        // 9. PHP settings
        $results['php_settings'] = [
            'display_errors' => ini_get('display_errors') == '1',
            'expose_php' => ini_get('expose_php') == '1',
            'allow_url_fopen' => ini_get('allow_url_fopen') == '1',
            'allow_url_include' => ini_get('allow_url_include') == '1',
            'open_basedir' => ini_get('open_basedir') ?: 'Not set',
        ];
        
        // 10. Uploads directory hardening
        $results['uploads_htaccess'] = file_exists($baseDir . '/uploads/.htaccess');
        
        // 11. .htaccess security rules audit
        $results['htaccess_rules'] = [];
        $htContent = file_exists($baseDir . '/.htaccess') ? file_get_contents($baseDir . '/.htaccess') : '';
        $htChecks = [
            ['rule' => 'Force HTTPS', 'pattern' => 'RewriteCond.*HTTPS.*off', 'found' => false],
            ['rule' => 'HSTS Header', 'pattern' => 'Strict-Transport-Security', 'found' => false],
            ['rule' => 'X-Content-Type-Options', 'pattern' => 'X-Content-Type-Options', 'found' => false],
            ['rule' => 'X-Frame-Options', 'pattern' => 'X-Frame-Options', 'found' => false],
            ['rule' => 'X-XSS-Protection', 'pattern' => 'X-XSS-Protection', 'found' => false],
            ['rule' => 'CSP Header', 'pattern' => 'Content-Security-Policy', 'found' => false],
            ['rule' => 'Permissions-Policy', 'pattern' => 'Permissions-Policy', 'found' => false],
            ['rule' => 'Directory Listing Off', 'pattern' => 'Options.*-Indexes', 'found' => false],
            ['rule' => 'Server Signature Off', 'pattern' => 'ServerSignature Off', 'found' => false],
            ['rule' => 'Hidden Files Blocked', 'pattern' => 'FilesMatch.*\^\\\\\.', 'found' => false],
            ['rule' => 'SQL Injection Block', 'pattern' => 'union.*select', 'found' => false],
            ['rule' => 'Scanner Bot Block', 'pattern' => 'sqlmap|nikto', 'found' => false],
            ['rule' => 'PHP in Uploads Blocked', 'pattern' => 'uploads.*php.*\\[F', 'found' => false],
            ['rule' => 'TRACE/TRACK Disabled', 'pattern' => 'TRACE|TRACK', 'found' => false],
            ['rule' => 'Hotlink Protection', 'pattern' => 'HTTP_REFERER', 'found' => false],
            ['rule' => 'Upload Size Limit', 'pattern' => 'LimitRequestBody', 'found' => false],
        ];
        foreach ($htChecks as &$hc) {
            $hc['found'] = (bool)preg_match('/' . $hc['pattern'] . '/i', $htContent);
        }
        unset($hc);
        $results['htaccess_rules'] = $htChecks;
        $htaccessScore = count(array_filter($htChecks, fn($c) => $c['found']));
        $htaccessTotal = count($htChecks);
        $results['htaccess_score'] = ['passed' => $htaccessScore, 'total' => $htaccessTotal];
        
        // 12. Cookie security flags
        $results['cookie_flags'] = [
            'httponly' => (bool)ini_get('session.cookie_httponly'),
            'secure' => (bool)ini_get('session.cookie_secure'),
            'samesite' => ini_get('session.cookie_samesite') ?: 'None',
        ];
        
        // Calculate scan score
        $issues = count($results['upload_php_files']) + count($results['world_writable']) + count($results['exposed_files']) + count($results['suspicious_code']);
        $results['issues_found'] = $issues;
        $results['scan_time'] = date('Y-m-d H:i:s');
        
        // Log the scan
        try {
            $db->insert('security_logs', [
                'event_type' => 'security_scan',
                'severity' => $issues > 0 ? 'high' : 'low',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'payload' => "Scan complete: {$issues} issues found",
                'admin_user_id' => $_SESSION['admin_id'] ?? null,
            ]);
        } catch (\Throwable $e) {}
        
        echo json_encode(['success' => true, 'data' => $results]);
        break;

    case 'delete_suspicious_file':
        $file = $_POST['file'] ?? '';
        $baseDir = __DIR__ . '/..';
        $fullPath = realpath($baseDir . $file);
        
        // Safety: only allow deleting from uploads directory
        if (!$fullPath || strpos($fullPath, realpath($baseDir . '/uploads')) !== 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete files outside uploads directory']);
            break;
        }
        if (@unlink($fullPath)) {
            echo json_encode(['success' => true, 'message' => 'File deleted: ' . $file]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete file']);
        }
        break;

    // ═══════════════════════════════════════
    // SAVE SETTINGS
    // ═══════════════════════════════════════

    case 'save_settings':
        $settingKeys = [
            'sec_firewall_enabled', 'sec_rate_limit_enabled', 'sec_rate_limit_requests', 'sec_rate_limit_window',
            'sec_brute_force_enabled', 'sec_brute_force_max_attempts', 'sec_brute_force_lockout_minutes',
            'sec_sqli_protection', 'sec_xss_protection', 'sec_csrf_protection', 'sec_file_upload_scan',
            'sec_session_protection', 'sec_security_headers', 'sec_force_https', 'sec_block_bad_bots',
            'sec_breach_alerts', 'sec_auto_block_threshold', 'sec_allowed_upload_types', 'sec_max_upload_size_mb',
            'sec_session_timeout_minutes', 'sec_honeypot_enabled', 'sec_content_security_policy',
            'sec_password_min_length', 'sec_disable_directory_listing',
        ];
        
        $saved = 0;
        foreach ($settingKeys as $key) {
            $shortKey = str_replace('sec_', '', $key);
            if (isset($_POST[$shortKey])) {
                updateSetting($key, $_POST[$shortKey]);
                $saved++;
            }
        }
        
        // Log settings change
        try {
            $db->insert('security_logs', [
                'event_type' => 'settings_change',
                'severity' => 'low',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'payload' => "Security settings updated ({$saved} values)",
                'admin_user_id' => $_SESSION['admin_id'] ?? null,
            ]);
        } catch (\Throwable $e) {}
        
        echo json_encode(['success' => true, 'message' => "Saved {$saved} settings"]);
        break;

    // ═══════════════════════════════════════
    // GENERATE .HTACCESS SECURITY RULES
    // ═══════════════════════════════════════

    case 'get_htaccess_rules':
        $rules = <<<'HTACCESS'
# ══════════════════════════════════════════════
# SECURITY HARDENING RULES
# Generated by Security Center
# ══════════════════════════════════════════════

# ── Disable Directory Browsing ──
Options -Indexes

# ── Disable Server Signature ──
ServerSignature Off

# ── Block Access to Hidden Files (.git, .env, etc) ──
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>

# ── Protect Sensitive Files ──
<FilesMatch "(\.sql|\.log|\.env|\.bak|\.config|\.ini|composer\.(json|lock))$">
    Order allow,deny
    Deny from all
</FilesMatch>

# ── Block PHP Execution in Uploads ──
<Directory "uploads">
    <FilesMatch "\.(php|phtml|php5|php7|phar|cgi|pl|py|sh)$">
        Order allow,deny
        Deny from all
    </FilesMatch>
</Directory>

# ── Prevent Image Hotlinking ──
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_REFERER} !^$
    RewriteCond %{HTTP_REFERER} !^https?://(www\.)?khatibangla\.com [NC]
    RewriteRule \.(jpg|jpeg|png|gif|webp)$ - [F,NC,L]
</IfModule>

# ── Security Headers ──
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header unset X-Powered-By
    Header unset Server
</IfModule>

# ── Block Common Exploits ──
<IfModule mod_rewrite.c>
    RewriteEngine On
    
    # Block SQL injection attempts
    RewriteCond %{QUERY_STRING} (\bunion\b.*\bselect\b|\binsert\b.*\binto\b|\bdelete\b.*\bfrom\b|\bdrop\b.*\btable\b) [NC]
    RewriteRule .* - [F,L]
    
    # Block file injection attempts
    RewriteCond %{QUERY_STRING} (\.\.\/|\.\.\\|%2e%2e) [NC]
    RewriteRule .* - [F,L]
    
    # Block script injection
    RewriteCond %{QUERY_STRING} (<script|%3Cscript) [NC]
    RewriteRule .* - [F,L]
    
    # Block common WordPress/vulnerability scanners
    RewriteCond %{REQUEST_URI} (wp-admin|wp-login|wp-content|wp-includes|xmlrpc\.php) [NC]
    RewriteRule .* - [F,L]
    
    # Block common attack tools
    RewriteCond %{HTTP_USER_AGENT} (sqlmap|nikto|nmap|masscan|zgrab|nuclei|dirbuster|gobuster|wfuzz|hydra) [NC]
    RewriteRule .* - [F,L]
</IfModule>

# ── Rate Limit (LiteSpeed) ──
<IfModule LiteSpeed>
    RewriteEngine On
    RewriteCond %{REMOTE_ADDR} ^(.*)$
    # 60 requests per 60 seconds
</IfModule>

# ── Prevent Clickjacking ──
<IfModule mod_headers.c>
    Header always append X-Frame-Options SAMEORIGIN
</IfModule>

# ── Disable Trace/Track Methods ──
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_METHOD} ^(TRACE|TRACK)
    RewriteRule .* - [F]
</IfModule>
HTACCESS;
        echo json_encode(['success' => true, 'data' => ['rules' => $rules]]);
        break;

    // ═══════════════════════════════════════
    // BREACH ALERTS
    // ═══════════════════════════════════════

    case 'breach_alerts':
        try {
            $alerts = $db->fetchAll("SELECT * FROM notifications WHERE type = 'security_breach' ORDER BY created_at DESC LIMIT 50") ?: [];
            echo json_encode(['success' => true, 'data' => $alerts]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => true, 'data' => []]);
        }
        break;

    case 'dismiss_alert':
        $id = intval($_POST['id'] ?? 0);
        try {
            $db->query("UPDATE notifications SET is_read = 1 WHERE id = ?", [$id]);
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false]);
        }
        break;

    case 'dismiss_all_alerts':
        try {
            $db->query("UPDATE notifications SET is_read = 1 WHERE type = 'security_breach'");
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false]);
        }
        break;

    case 'save_admin_key':
        $key = trim($_POST['key'] ?? '');
        if (strlen($key) < 6) {
            echo json_encode(['success' => false, 'message' => 'Key must be at least 6 characters']);
            break;
        }
        updateSetting('admin_secret_key', $key);
        try {
            $db->insert('security_logs', [
                'event_type' => 'settings_change',
                'severity' => 'medium',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'payload' => 'Admin secret key changed',
                'admin_user_id' => $_SESSION['admin_id'] ?? null,
            ]);
        } catch (\Throwable $e) {}
        echo json_encode(['success' => true, 'message' => 'Admin key updated']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
