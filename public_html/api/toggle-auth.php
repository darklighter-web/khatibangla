<?php
/**
 * Toggle Auth System API
 * Saves clerk_enabled / clerk_keep_legacy_login to site_settings.
 * Called from login page toggle buttons via AJAX.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, private');

require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$system  = $input['system']  ?? '';
$enabled = isset($input['enabled']) ? (bool)$input['enabled'] : null;

if (!in_array($system, ['clerk', 'legacy']) || $enabled === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Map system → setting key
$keyMap = [
    'clerk'  => 'clerk_enabled',
    'legacy' => 'clerk_keep_legacy_login',
];
$key   = $keyMap[$system];
$value = $enabled ? '1' : '0';

try {
    updateSetting($key, $value);
    echo json_encode(['success' => true, 'system' => $system, 'enabled' => $enabled]);
} catch (Throwable $e) {
    error_log('[toggle-auth] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
