<?php
/**
 * Admin Theme Switcher API
 * Saves the selected theme (light / dark / ui) to admin_users.admin_theme
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$theme = trim($_POST['theme'] ?? '');
if (!in_array($theme, ['light', 'dark', 'ui'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid theme']);
    exit;
}

try {
    $db = Database::getInstance();
    $db->query(
        "UPDATE admin_users SET admin_theme = ? WHERE id = ?",
        [$theme, intval($_SESSION['admin_id'])]
    );
    echo json_encode(['success' => true, 'theme' => $theme]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error']);
}
