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
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
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

    $testEventId = 'test_' . bin2hex(random_bytes(8));
    $result = fbCapiSend('PageView', [
        'content_name' => 'CAPI Test Event',
        'content_category' => 'diagnostics',
    ], [], $testEventId);

    echo json_encode([
        'success'         => $result['success'],
        'event_id'        => $result['event_id'] ?? $testEventId,
        'events_received' => $result['events_received'] ?? 0,
        'http_code'       => $result['http_code'] ?? 0,
        'error'           => $result['error'] ?? null,
        'fbtrace_id'      => $result['fbtrace_id'] ?? null,
        'messages'        => $result['messages'] ?? [],
        'pixel_id'        => $pixelId,
        'test_code'       => getSetting('fb_test_event_code', '') ?: '(none — events go to production)',
    ]);
    exit;
}

if ($action === 'logs') {
    // ── Read recent CAPI logs ──
    $logFile = __DIR__ . '/../logs/fb-capi.log';
    if (!file_exists($logFile)) {
        echo json_encode(['success' => true, 'logs' => '(No logs yet. Enable logging and fire some events.)']);
        exit;
    }
    // Read last 50 lines
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
    // ── Check configuration status ──
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
        'test_mode' => !empty($testCode),
        'logging' => getSetting('fb_event_logging', '0') === '1',
        'server_events' => $ssEvents,
        'client_events' => $csEvents,
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
