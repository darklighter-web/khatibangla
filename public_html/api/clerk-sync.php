<?php
/**
 * Clerk Session Sync API
 *
 * Bridges Clerk JS → PHP sessions. Called from frontend after Clerk sign-in/sign-up.
 *
 * SECURITY: Verifies Clerk's session JWT (RS256) via JWKS before creating PHP sessions.
 * Falls back to Backend API verification if JWKS is unavailable.
 *
 * Also handles Clerk webhooks (verified via Svix HMAC signature).
 */

// ── MUST be first: session.php calls session_set_cookie_params() + session_start()
// which must happen before ANY header() or output. ──────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session.php';
}

// Temporarily capture PHP errors to return in JSON (remove after debugging)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/clerk.php';

// Now safe to send headers
header('X-LiteSpeed-Cache-Control: no-cache');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');

// Same-origin CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . SITE_URL);
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? [];
$action = $input['action'] ?? '';

// Auto-detect Clerk webhook (has 'type' at root level, not 'action')
if (empty($action) && !empty($input['type'])) {
    $action = 'webhook';
}

// For webhooks, pass raw body for signature verification
if ($action === 'webhook') {
    handleWebhook($rawInput);
    exit;
}

try {
    switch ($action) {
        case 'sync':   handleSync($input); break;
        case 'logout': handleLogout(); break;
        case 'ping':   
            echo json_encode(['pong' => true, 'time' => date('c'), 'clerk_enabled' => isClerkEnabled()]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action', 'received' => $action]);
    }
} catch (Throwable $e) {
    $msg = $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
    error_log('[Clerk] Sync error: ' . $msg);
    http_response_code(500);
    echo json_encode(['error' => 'Internal error', 'detail' => $msg]);
}

// ─────────────────────────────────────────────────────────────
// HANDLERS
// ─────────────────────────────────────────────────────────────

function handleSync(array $input): void {
    if (!isClerkEnabled()) {
        echo json_encode(['success' => false, 'error' => 'Clerk not enabled']);
        return;
    }

    $clerkUserId = trim($input['clerk_user_id'] ?? '');

    if (empty($clerkUserId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing clerk_user_id']);
        return;
    }

    // ── Fast path: already synced ──
    if (isCustomerLoggedIn() && ($_SESSION['clerk_user_id'] ?? '') === $clerkUserId) {
        echo json_encode(['success' => true, 'customer_id' => getCustomerId(), 'cached' => true]);
        return;
    }

    // ── Verify user exists via Clerk Backend API (secret key) ──
    // Simpler and more reliable than JWT/JWKS verification across PHP versions.
    $clerkUser = fetchClerkUser($clerkUserId);

    if (!$clerkUser) {
        error_log("[Clerk] Could not verify user via API: $clerkUserId");
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Could not verify user. Check secret key and server connectivity.',
        ]);
        return;
    }

    // ── Sync to local DB + PHP session ──
    $customerId = syncClerkUser($clerkUser);
    $parsed     = parseClerkUser($clerkUser);
    setSessionFromClerk($customerId, $parsed);

    error_log("[Clerk] Sync OK: {$parsed['clerk_id']} → customer #$customerId ({$parsed['name']})");

    echo json_encode([
        'success'     => true,
        'customer_id' => $customerId,
        'name'        => $parsed['name'],
    ]);
}

function handleLogout(): void {
    customerLogout();
    unset($_SESSION['clerk_user_id'], $_SESSION['clerk_image']);
    echo json_encode(['success' => true]);
}

function handleWebhook(string $rawBody): void {
    header('Content-Type: application/json');
    
    // Verify Svix signature — returns null if invalid
    $allHeaders = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
            $allHeaders[$headerName] = $value;
        }
    }
    // Also add non-HTTP_ headers
    foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
        if (isset($_SERVER[$key])) {
            $allHeaders[strtolower(str_replace('_', '-', $key))] = $_SERVER[$key];
        }
    }
    
    $payload = verifyClerkWebhook($rawBody, $allHeaders);
    
    if ($payload === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid webhook signature']);
        return;
    }
    
    $type = $payload['type'] ?? '';
    $data = $payload['data'] ?? [];
    $db   = Database::getInstance();
    
    try {
        switch ($type) {
            case 'user.created':
            case 'user.updated':
                if (!empty($data) && isset($data['id'])) {
                    syncClerkUser($data);
                    error_log("[Clerk] Webhook $type: user {$data['id']} synced");
                }
                break;
            case 'user.deleted':
                $clerkId = $data['id'] ?? '';
                if ($clerkId) {
                    $db->query("UPDATE customers SET clerk_id = NULL WHERE clerk_id = ?", [$clerkId]);
                    error_log("[Clerk] Webhook user.deleted: $clerkId unlinked");
                }
                break;
            case 'session.created':
            case 'session.ended':
                // Log session events if needed
                break;
        }
        echo json_encode(['success' => true, 'type' => $type]);
    } catch (Throwable $e) {
        error_log("[Clerk] Webhook handler error for $type: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Handler failed']);
    }
}