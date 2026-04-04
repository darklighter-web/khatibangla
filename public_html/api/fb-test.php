<?php
/**
 * Facebook Conversions API — Test & Diagnostics Endpoint
 * POST /api/fb-test.php
 * Admin-only
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fb-capi.php';
header('Content-Type: application/json');

session_start();
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized — admin login required']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'test';

if ($action === 'test') {
    // ── Send a test PageView event ──
    $pixelId = fbGetPixelId();
    $token = getSetting('fb_access_token', '');
    
    if (empty($pixelId)) {
        echo json_encode(['success' => false, 'error' => 'Pixel ID is not set. Please enter your Facebook Pixel ID first.']);
        exit;
    }
    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Access Token is not set. Generate one from Facebook Events Manager.']);
        exit;
    }

    // ── Pre-flight: validate token format ──
    $tokenLen = strlen($token);
    $tokenPrefix = substr($token, 0, 3);
    $tokenWarnings = [];
    if ($tokenLen < 50) {
        $tokenWarnings[] = "Token seems too short ({$tokenLen} chars). Re-generate from Events Manager.";
    }
    if (!in_array($tokenPrefix, ['EAA', 'EAB', 'EAC', 'EAD'])) {
        $tokenWarnings[] = "Token doesn't start with 'EAA...' — may be invalid. Expected a System User or Events Manager token.";
    }
    // Check for whitespace/newlines in token
    $cleanToken = trim($token);
    if ($cleanToken !== $token) {
        $tokenWarnings[] = "Token has leading/trailing whitespace. Cleaning automatically.";
        // Auto-fix in DB
        $db = Database::getInstance();
        $db->query("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'fb_access_token'", [$cleanToken]);
        $token = $cleanToken;
    }

    // ── Pre-flight: quick token validation via Graph API debug ──
    $debugUrl = "https://graph.facebook.com/v22.0/debug_token?input_token=" . urlencode($token) . "&access_token=" . urlencode($token);
    $dch = curl_init();
    curl_setopt_array($dch, [
        CURLOPT_URL => $debugUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $debugResp = curl_exec($dch);
    $debugHttp = (int)curl_getinfo($dch, CURLINFO_HTTP_CODE);
    curl_close($dch);
    $debugData = json_decode($debugResp ?? '', true);
    
    $tokenValid = true;
    $tokenDebugInfo = [];
    if ($debugHttp === 200 && isset($debugData['data'])) {
        $dd = $debugData['data'];
        $tokenDebugInfo['type'] = $dd['type'] ?? 'unknown';
        $tokenDebugInfo['app_id'] = $dd['app_id'] ?? 'unknown';
        $tokenDebugInfo['is_valid'] = $dd['is_valid'] ?? false;
        if (!empty($dd['expires_at']) && $dd['expires_at'] > 0) {
            $tokenDebugInfo['expires'] = date('Y-m-d H:i', $dd['expires_at']);
            if ($dd['expires_at'] < time()) {
                $tokenWarnings[] = "Token EXPIRED on " . date('Y-m-d', $dd['expires_at']) . ". Generate a new one.";
                $tokenValid = false;
            }
        } else {
            $tokenDebugInfo['expires'] = 'never';
        }
        if (isset($dd['is_valid']) && !$dd['is_valid']) {
            $tokenWarnings[] = "Facebook says this token is INVALID. Error: " . ($dd['error']['message'] ?? 'unknown');
            $tokenValid = false;
        }
    } elseif ($debugHttp !== 200) {
        $errMsg = $debugData['error']['message'] ?? 'unknown';
        $tokenWarnings[] = "Token validation failed (HTTP {$debugHttp}): {$errMsg}";
    }

    // ── Send test event ──
    $testEventId = 'test_' . bin2hex(random_bytes(8));
    $result = fbCapiSend('PageView', [
        'content_name' => 'CAPI Test Event',
        'content_category' => 'diagnostics',
    ], [], $testEventId);

    $response = [
        'success'         => $result['success'],
        'event_id'        => $result['event_id'] ?? $testEventId,
        'events_received' => $result['events_received'] ?? 0,
        'http_code'       => $result['http_code'] ?? 0,
        'error'           => $result['error'] ?? null,
        'fbtrace_id'      => $result['fbtrace_id'] ?? null,
        'messages'        => $result['messages'] ?? [],
        'pixel_id'        => $pixelId,
        'test_code'       => getSetting('fb_test_event_code', '') ?: '(none — events go to production)',
        'token_length'    => $tokenLen,
        'token_prefix'    => $tokenPrefix . '***',
        'api_version'     => 'v22.0',
    ];
    if (!empty($tokenWarnings)) $response['warnings'] = $tokenWarnings;
    if (!empty($tokenDebugInfo)) $response['token_info'] = $tokenDebugInfo;

    echo json_encode($response);
    exit;
}

if ($action === 'logs') {
    // ── Read recent CAPI logs ──
    $logFile = __DIR__ . '/../logs/fb-capi.log';
    if (!file_exists($logFile)) {
        echo json_encode(['success' => true, 'logs' => '(No logs yet. Enable logging and fire some events.)']);
        exit;
    }
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $recent = array_slice($lines, -50);
    echo json_encode(['success' => true, 'logs' => implode("\n", $recent), 'total_lines' => count($lines)]);
    exit;
}

if ($action === 'clear_logs') {
    $logFile = __DIR__ . '/../logs/fb-capi.log';
    @file_put_contents($logFile, '');
    echo json_encode(['success' => true, 'message' => 'Logs cleared']);
    exit;
}

if ($action === 'status') {
    $pixelId = fbGetPixelId();
    $token = getSetting('fb_access_token', '');
    $testCode = getSetting('fb_test_event_code', '');
    
    $events = ['PageView','ViewContent','AddToCart','InitiateCheckout','Purchase','Search','Lead','CompleteRegistration','Contact'];
    $ssEvents = [];
    $csEvents = [];
    foreach ($events as $ev) {
        $ssEvents[$ev] = fbCapiEventEnabled($ev);
        $csEvents[$ev] = fbPixelEventEnabled($ev);
    }

    echo json_encode([
        'success' => true,
        'pixel_id' => $pixelId ? substr($pixelId, 0, 4) . '****' . substr($pixelId, -4) : '(not set)',
        'token_set' => !empty($token),
        'token_length' => strlen($token),
        'test_mode' => !empty($testCode),
        'logging' => getSetting('fb_event_logging', '0') === '1',
        'server_events' => $ssEvents,
        'client_events' => $csEvents,
        'api_version' => 'v22.0',
        'fbp_cookie' => !empty($_COOKIE['_fbp']) ? 'present' : 'missing',
        'fbc_cookie' => !empty($_COOKIE['_fbc']) ? 'present' : 'missing',
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
