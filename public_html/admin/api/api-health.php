<?php
/**
 * API Health Data Endpoint
 * Returns: error logs, rate counters, token status, live connectivity test
 * Used by: admin/pages/api-health.php dashboard
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
if (empty($_SESSION['admin_id'])) { header('Content-Type: application/json'); echo json_encode(['error'=>'Unauthorized']); exit; }

header('Content-Type: application/json');
$db = Database::getInstance();
$action = $_GET['action'] ?? 'overview';

// ═══════════════════════════════════════
// ACTION: overview — dashboard summary
// ═══════════════════════════════════════
if ($action === 'overview') {
    $data = [
        'errors'     => getRecentErrors(50),
        'tokens'     => getTokenStatus($db),
        'rates'      => getRateCounters($db),
        'caches'     => getCacheStatus($db),
        'last_sync'  => getLastSync(),
        'generated'  => date('Y-m-d H:i:s T'),
    ];
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// ═══════════════════════════════════════
// ACTION: test — live connectivity test
// ═══════════════════════════════════════
if ($action === 'test') {
    $courier = $_GET['courier'] ?? 'all';
    $results = [];

    // Pathao
    if ($courier === 'all' || $courier === 'pathao') {
        $results['pathao'] = testPathao($db);
    }
    // Steadfast
    if ($courier === 'all' || $courier === 'steadfast') {
        $results['steadfast'] = testSteadfast($db);
    }
    // RedX
    if ($courier === 'all' || $courier === 'redx') {
        $results['redx'] = testRedx($db);
    }

    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

// ═══════════════════════════════════════
// ACTION: debug_report — full paste-ready report
// ═══════════════════════════════════════
if ($action === 'debug_report') {
    $report = generateDebugReport($db);
    echo json_encode(['report' => $report]);
    exit;
}

// ═══════════════════════════════════════
// ACTION: clear_log — clear error log
// ═══════════════════════════════════════
if ($action === 'clear_log') {
    $logFile = dirname(dirname(__DIR__)) . '/tmp/api-errors.log';
    if (is_file($logFile)) {
        @file_put_contents($logFile, "--- Log cleared by admin at " . date('Y-m-d H:i:s') . " ---\n");
    }
    echo json_encode(['success' => true]);
    exit;
}

// ═══════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════

function getRecentErrors($limit = 50) {
    $logFile = dirname(dirname(__DIR__)) . '/tmp/api-errors.log';
    if (!is_file($logFile)) return ['exists' => false, 'entries' => [], 'size' => 0];

    $size = filesize($logFile);
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_slice($lines, -$limit); // Last N lines
    $lines = array_reverse($lines); // Newest first

    $entries = [];
    foreach ($lines as $line) {
        $entry = parseLogLine($line);
        if ($entry) $entries[] = $entry;
    }

    return ['exists' => true, 'entries' => $entries, 'size' => $size, 'total_lines' => count(file($logFile))];
}

function parseLogLine($line) {
    // Format: 2026-02-18 21:15:00 [redx] HTTP 429 POST /v4/auth/login (retry #2): response text...
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+\[(\w+)\]\s+HTTP (\S+)\s*(.*?):\s*(.*)$/s', $line, $m)) {
        $httpCode = $m[3];
        $endpoint = trim($m[4]);
        $response = trim($m[5]);

        return [
            'time'     => $m[1],
            'courier'  => $m[2],
            'code'     => $httpCode,
            'endpoint' => $endpoint,
            'response' => substr($response, 0, 300),
            'severity' => getSeverity($httpCode),
            'reason'   => getHumanReason($httpCode, $m[2], $response, $endpoint),
            'fix'      => getSuggestedFix($httpCode, $m[2], $response),
        ];
    }
    // Fallback: unparsed line
    if (strpos($line, '--- Log cleared') !== false) return null;
    return [
        'time' => '', 'courier' => 'unknown', 'code' => '???',
        'endpoint' => '', 'response' => $line,
        'severity' => 'warning', 'reason' => 'Unparsed log entry', 'fix' => '',
    ];
}

function getSeverity($code) {
    if ($code == 429) return 'critical';
    if ($code == 401) return 'warning';
    if ($code >= 500) return 'error';
    if ($code === 'RATE_LIMIT') return 'info';
    return 'warning';
}

function getHumanReason($code, $courier, $response, $endpoint) {
    $reasons = [
        '429' => [
            'redx'      => 'RedX blocked your IP — too many login attempts. Their login endpoint is very strict (~3-5 requests per 10 minutes).',
            'pathao'    => 'Pathao rate limit hit — too many API requests per minute.',
            'steadfast' => 'Steadfast rate limit hit — too many requests.',
            'default'   => 'Too many requests sent too quickly. Server is temporarily blocking you.',
        ],
        '401' => [
            'redx'      => 'RedX authentication failed — token expired or invalid credentials.',
            'pathao'    => 'Pathao token expired or invalid — will auto-refresh on next request.',
            'steadfast' => 'Steadfast API key rejected — check your API key and secret.',
            'default'   => 'Authentication failed — credentials are wrong or expired.',
        ],
        '403' => [
            'steadfast' => 'Steadfast actively blocking automated requests (anti-bot protection). This is a known issue — the system falls back to your own database.',
            'default'   => 'Access forbidden — server is blocking this request.',
        ],
        '500' => ['default' => 'Server error on the courier\'s end — not your fault. Temporary issue.'],
        '502' => ['default' => 'Bad gateway — courier\'s server is having issues.'],
        '503' => ['default' => 'Service unavailable — courier\'s server is overloaded or under maintenance.'],
        'RATE_LIMIT' => ['default' => 'Self-throttled: your own rate limiter blocked this request to protect you from hitting the courier\'s limits.'],
    ];

    $codeKey = (string)$code;
    if (isset($reasons[$codeKey][$courier])) return $reasons[$codeKey][$courier];
    if (isset($reasons[$codeKey]['default'])) return $reasons[$codeKey]['default'];

    // Special patterns in response body
    if (stripos($response, 'temporarily blocked') !== false) return 'Courier has temporarily blocked your account due to excessive requests.';
    if (stripos($response, 'credentials') !== false) return 'Login credentials were rejected.';
    if (stripos($response, 'CSRF') !== false) return 'Session token (CSRF) expired — web scrape session needs refresh.';

    return "HTTP {$code} error from {$courier}";
}

function getSuggestedFix($code, $courier, $response) {
    $fixes = [
        '429' => [
            'redx'    => 'Wait 30-60 minutes. RedX token is now cached for 12 hours, so this should not recur after recovery.',
            'pathao'  => 'Wait 5-10 minutes. Pathao token is cached for 4 hours. If this keeps happening, reduce bulk operations.',
            'default' => 'Wait 10-15 minutes before retrying. The system will auto-retry with exponential backoff.',
        ],
        '401' => [
            'redx'    => 'Go to Courier Settings → RedX → verify phone number and password are correct.',
            'pathao'  => 'Go to Courier Settings → Pathao → verify email and password. Token will auto-refresh.',
            'steadfast' => 'Go to Courier Settings → Steadfast → verify API Key and Secret Key.',
            'default' => 'Check your login credentials in Courier Settings.',
        ],
        '403' => [
            'steadfast' => 'No fix needed — this is expected. Steadfast blocks automated fraud checks. Your own database is used instead.',
            'default'   => 'Check if your IP is whitelisted or if credentials need updating.',
        ],
        '500' => ['default' => 'Wait and retry. This is a courier server issue, not something you can fix.'],
        '502' => ['default' => 'Courier server issue. Wait 5-10 minutes.'],
        '503' => ['default' => 'Courier server is overloaded. Wait 5-10 minutes.'],
        'RATE_LIMIT' => ['default' => 'Reduce the frequency of operations. The limiter protects you from being blocked.'],
    ];

    $codeKey = (string)$code;
    if (isset($fixes[$codeKey][$courier])) return $fixes[$codeKey][$courier];
    if (isset($fixes[$codeKey]['default'])) return $fixes[$codeKey]['default'];
    return 'Wait a few minutes and retry.';
}

function getTokenStatus($db) {
    $tokens = [];

    // Pathao shipping token
    try {
        $exp = intval(getSetting('pathao_token_expiry', '0'));
        $tokens['pathao_shipping'] = [
            'type'    => 'OAuth Bearer (shipping)',
            'status'  => $exp > time() ? 'active' : 'expired',
            'expires' => $exp > 0 ? date('Y-m-d H:i:s', $exp) : 'never set',
            'ttl_min' => $exp > time() ? round(($exp - time()) / 60) : 0,
        ];
    } catch (\Throwable $e) { $tokens['pathao_shipping'] = ['status' => 'error', 'error' => $e->getMessage()]; }

    // Pathao fraud token
    try {
        $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'pathao_fraud_token'");
        $data = $row ? json_decode($row['setting_value'] ?? '', true) : null;
        $exp = $data['expires'] ?? 0;
        $tokens['pathao_fraud'] = [
            'type'    => 'OAuth Bearer (fraud check)',
            'status'  => ($data && $exp > time()) ? 'active' : 'expired',
            'expires' => $exp > 0 ? date('Y-m-d H:i:s', $exp) : 'not cached',
            'ttl_min' => $exp > time() ? round(($exp - time()) / 60) : 0,
        ];
    } catch (\Throwable $e) { $tokens['pathao_fraud'] = ['status' => 'error']; }

    // RedX fraud token
    try {
        $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'redx_fraud_token'");
        $data = $row ? json_decode($row['setting_value'] ?? '', true) : null;
        $exp = $data['expires'] ?? 0;
        $tokens['redx_fraud'] = [
            'type'    => 'Login Token (fraud check)',
            'status'  => ($data && $exp > time()) ? 'active' : 'expired',
            'expires' => $exp > 0 ? date('Y-m-d H:i:s', $exp) : 'not cached',
            'ttl_min' => $exp > time() ? round(($exp - time()) / 60) : 0,
        ];
    } catch (\Throwable $e) { $tokens['redx_fraud'] = ['status' => 'error']; }

    // Steadfast (API key, no token)
    $tokens['steadfast'] = [
        'type'   => 'API Key + Secret (permanent)',
        'status' => getSetting('steadfast_api_key', '') ? 'configured' : 'not set',
    ];

    // RedX shipping token
    $tokens['redx_shipping'] = [
        'type'   => 'API Token (shipping)',
        'status' => getSetting('redx_api_token', '') ? 'configured' : 'not set',
    ];

    return $tokens;
}

function getRateCounters($db) {
    $counters = [];
    foreach (['pathao', 'steadfast', 'redx'] as $courier) {
        try {
            $key = $courier . '_rate_counter';
            $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
            $data = $row ? json_decode($row['setting_value'] ?? '', true) : null;
            $limits = ['pathao' => 100, 'steadfast' => 80, 'redx' => 80];
            $count = $data['count'] ?? 0;
            $windowStart = $data['window_start'] ?? 0;
            $elapsed = time() - $windowStart;
            $counters[$courier] = [
                'count'     => $count,
                'limit'     => $limits[$courier],
                'window'    => $elapsed < 60 ? $elapsed . 's ago' : 'expired (reset)',
                'pct'       => $limits[$courier] > 0 ? round(($count / $limits[$courier]) * 100) : 0,
                'remaining' => max(0, $limits[$courier] - $count),
            ];
        } catch (\Throwable $e) {
            $counters[$courier] = ['count' => 0, 'limit' => 80, 'error' => $e->getMessage()];
        }
    }
    return $counters;
}

function getCacheStatus($db) {
    $caches = [];
    try {
        $rows = $db->fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'cache_%' OR setting_key LIKE 'fraud_cache_%' ORDER BY setting_key");
        foreach ($rows as $row) {
            $data = json_decode($row['setting_value'] ?? '', true);
            $exp = $data['expires'] ?? 0;
            $caches[] = [
                'key'      => $row['setting_key'],
                'status'   => $exp > time() ? 'active' : 'expired',
                'expires'  => $exp > 0 ? date('Y-m-d H:i:s', $exp) : '?',
                'ttl_min'  => $exp > time() ? round(($exp - time()) / 60) : 0,
                'cached_at' => $data['cached_at'] ?? '',
            ];
        }
    } catch (\Throwable $e) {}
    return $caches;
}

function getLastSync() {
    $logFile = dirname(dirname(__DIR__)) . '/tmp/courier-sync.log';
    if (!is_file($logFile)) return ['exists' => false];
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $last = end($lines);
    return ['exists' => true, 'last_entry' => $last, 'total_runs' => count($lines)];
}

function testPathao($db) {
    $start = microtime(true);
    try {
        require_once dirname(dirname(__DIR__)) . '/api/pathao.php';
        $p = new PathaoAPI();
        if (!$p->isConfigured()) return ['status' => 'not_configured', 'ms' => 0];
        $cities = $p->getCities();
        $ms = round((microtime(true) - $start) * 1000);
        $count = count($cities['data']['data'] ?? $cities['data'] ?? []);
        return ['status' => 'ok', 'ms' => $ms, 'detail' => "Fetched {$count} cities"];
    } catch (\Throwable $e) {
        $ms = round((microtime(true) - $start) * 1000);
        return ['status' => 'error', 'ms' => $ms, 'detail' => $e->getMessage()];
    }
}

function testSteadfast($db) {
    $start = microtime(true);
    try {
        require_once dirname(dirname(__DIR__)) . '/api/steadfast.php';
        $sf = new SteadfastAPI();
        if (!$sf->isConfigured()) return ['status' => 'not_configured', 'ms' => 0];
        $bal = $sf->getBalance();
        $ms = round((microtime(true) - $start) * 1000);
        return ['status' => 'ok', 'ms' => $ms, 'detail' => 'Balance: ৳' . ($bal['current_balance'] ?? $bal['data']['current_balance'] ?? '?')];
    } catch (\Throwable $e) {
        $ms = round((microtime(true) - $start) * 1000);
        return ['status' => 'error', 'ms' => $ms, 'detail' => $e->getMessage()];
    }
}

function testRedx($db) {
    $start = microtime(true);
    try {
        require_once dirname(dirname(__DIR__)) . '/api/redx.php';
        $rx = new RedXAPI();
        if (!$rx->isConfigured()) return ['status' => 'not_configured', 'ms' => 0];
        $stores = $rx->getPickupStores();
        $ms = round((microtime(true) - $start) * 1000);
        $count = count($stores['pickup_stores'] ?? []);
        return ['status' => 'ok', 'ms' => $ms, 'detail' => "Found {$count} pickup store(s)"];
    } catch (\Throwable $e) {
        $ms = round((microtime(true) - $start) * 1000);
        return ['status' => 'error', 'ms' => $ms, 'detail' => $e->getMessage()];
    }
}

function generateDebugReport($db) {
    $sep = str_repeat('=', 60);
    $lines = [];
    $lines[] = $sep;
    $lines[] = "COURIER API DEBUG REPORT";
    $lines[] = "Generated: " . date('Y-m-d H:i:s T');
    $lines[] = "Server: " . ($_SERVER['SERVER_NAME'] ?? 'unknown');
    $lines[] = "PHP: " . phpversion();
    $lines[] = "cURL: " . (function_exists('curl_version') ? curl_version()['version'] : 'N/A');
    $lines[] = $sep;

    // 1. Credentials status
    $lines[] = "";
    $lines[] = "1. CREDENTIALS STATUS";
    $lines[] = str_repeat('-', 40);
    $creds = [
        'pathao_username' => 'Pathao Email',
        'pathao_password' => 'Pathao Password',
        'pathao_client_id' => 'Pathao Client ID',
        'pathao_client_secret' => 'Pathao Client Secret',
        'pathao_access_token' => 'Pathao Access Token',
        'pathao_token_expiry' => 'Pathao Token Expiry',
        'steadfast_api_key' => 'Steadfast API Key',
        'steadfast_secret_key' => 'Steadfast Secret Key',
        'steadfast_merchant_email' => 'Steadfast Email',
        'steadfast_merchant_password' => 'Steadfast Password',
        'redx_api_token' => 'RedX API Token',
        'redx_phone' => 'RedX Phone',
        'redx_password' => 'RedX Password',
    ];
    foreach ($creds as $key => $label) {
        $val = getSetting($key, '');
        if ($key === 'pathao_token_expiry' && $val) {
            $exp = intval($val);
            $status = $exp > time() ? "SET (expires " . date('M d H:i', $exp) . ")" : "EXPIRED (" . date('M d H:i', $exp) . ")";
        } else {
            $status = $val ? 'SET (' . strlen($val) . ' chars)' : 'NOT SET';
        }
        $lines[] = "  {$label}: {$status}";
    }

    // 2. Token cache status
    $lines[] = "";
    $lines[] = "2. TOKEN CACHE STATUS";
    $lines[] = str_repeat('-', 40);
    foreach (['pathao_fraud_token', 'redx_fraud_token'] as $key) {
        try {
            $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
            $data = $row ? json_decode($row['setting_value'] ?? '', true) : null;
            if ($data && ($data['expires'] ?? 0) > time()) {
                $ttl = round(($data['expires'] - time()) / 60);
                $lines[] = "  {$key}: ACTIVE ({$ttl} min remaining)";
            } else {
                $lines[] = "  {$key}: " . ($data ? 'EXPIRED' : 'NOT CACHED');
            }
        } catch (\Throwable $e) {
            $lines[] = "  {$key}: ERROR - " . $e->getMessage();
        }
    }

    // 3. Rate counters
    $lines[] = "";
    $lines[] = "3. RATE COUNTERS (current window)";
    $lines[] = str_repeat('-', 40);
    foreach (['pathao', 'steadfast', 'redx'] as $courier) {
        try {
            $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$courier . '_rate_counter']);
            $data = $row ? json_decode($row['setting_value'] ?? '', true) : null;
            $count = $data['count'] ?? 0;
            $windowAge = $data ? (time() - ($data['window_start'] ?? 0)) : 0;
            $limits = ['pathao' => 100, 'steadfast' => 80, 'redx' => 80];
            $lines[] = "  {$courier}: {$count}/{$limits[$courier]} req/min (window age: {$windowAge}s)";
        } catch (\Throwable $e) {
            $lines[] = "  {$courier}: ERROR";
        }
    }

    // 4. Recent errors (last 20)
    $lines[] = "";
    $lines[] = "4. RECENT API ERRORS (last 20)";
    $lines[] = str_repeat('-', 40);
    $logFile = dirname(dirname(__DIR__)) . '/tmp/api-errors.log';
    if (is_file($logFile)) {
        $logLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logLines = array_slice($logLines, -20);
        foreach ($logLines as $l) {
            $lines[] = "  " . $l;
        }
        if (empty($logLines)) $lines[] = "  (no errors logged)";
    } else {
        $lines[] = "  (log file does not exist — no errors have occurred)";
    }

    // 5. Last courier sync
    $lines[] = "";
    $lines[] = "5. LAST COURIER SYNC";
    $lines[] = str_repeat('-', 40);
    $syncLog = dirname(dirname(__DIR__)) . '/tmp/courier-sync.log';
    if (is_file($syncLog)) {
        $syncLines = file($syncLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lastLines = array_slice($syncLines, -5);
        foreach ($lastLines as $l) $lines[] = "  " . $l;
    } else {
        $lines[] = "  (no sync log found)";
    }

    // 6. Active caches
    $lines[] = "";
    $lines[] = "6. ACTIVE CACHES";
    $lines[] = str_repeat('-', 40);
    try {
        $rows = $db->fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'cache_%' ORDER BY setting_key LIMIT 20");
        foreach ($rows as $row) {
            $data = json_decode($row['setting_value'] ?? '', true);
            $exp = $data['expires'] ?? 0;
            $status = $exp > time() ? 'ACTIVE (' . round(($exp - time()) / 60) . ' min left)' : 'EXPIRED';
            $lines[] = "  {$row['setting_key']}: {$status}";
        }
        if (empty($rows)) $lines[] = "  (no caches)";
    } catch (\Throwable $e) {
        $lines[] = "  ERROR: " . $e->getMessage();
    }

    // 7. Live connectivity
    $lines[] = "";
    $lines[] = "7. LIVE CONNECTIVITY TEST";
    $lines[] = str_repeat('-', 40);
    foreach (['pathao' => 'https://api-hermes.pathao.com', 'steadfast' => 'https://portal.packzy.com', 'redx' => 'https://openapi.redx.com.bd'] as $name => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_NOBODY => true]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $time = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $err = curl_error($ch);
        curl_close($ch);
        $status = $code > 0 ? "HTTP {$code}" : "FAILED ({$err})";
        $lines[] = "  {$name}: {$status} ({$time}ms)";
    }

    $lines[] = "";
    $lines[] = $sep;
    $lines[] = "END OF REPORT";
    $lines[] = $sep;

    return implode("\n", $lines);
}
