<?php
/**
 * Admin Theme Toggle API
 * Saves theme preference (light/dark/ui) to admin_users table
 */
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$theme = $_POST['theme'] ?? 'ui';
if (!in_array($theme, ['light', 'dark', 'ui'])) $theme = 'ui';

try {
    $db = Database::getInstance();
    try {
        $db->query("UPDATE admin_users SET admin_theme = ? WHERE id = ?", [$theme, $_SESSION['admin_id']]);
    } catch (\Throwable $e) {
        $db->query("ALTER TABLE admin_users ADD COLUMN admin_theme VARCHAR(20) DEFAULT 'ui'");
        $db->query("UPDATE admin_users SET admin_theme = ? WHERE id = ?", [$theme, $_SESSION['admin_id']]);
    }
    echo json_encode(['success' => true, 'theme' => $theme]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
