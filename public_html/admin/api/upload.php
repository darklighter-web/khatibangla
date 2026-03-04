<?php
/**
 * Admin File Upload API
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!isAdminLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$type = $_POST['type'] ?? 'product';
$allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

$directories = [
    'product' => 'products',
    'banner' => 'banners',
    'logo' => 'logos',
    'category' => 'categories',
];

$dir = $directories[$type] ?? 'misc';

if (!isset($_FILES['file'])) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$result = uploadFile($_FILES['file'], $dir, $allowedTypes);

if ($result) {
    echo json_encode([
        'success' => true,
        'path' => $result,
        'url' => uploadUrl($dir . '/' . $result),
    ]);
} else {
    echo json_encode(['error' => 'Upload failed. Check file type and size.']);
}
