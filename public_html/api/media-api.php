<?php
/**
 * Central Media API - Upload, Delete, Convert to WebP, Move
 * Supports ALL upload directories: products, banners, logos, general
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$allowedFolders = ['products', 'banners', 'logos', 'general'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

$action = $input['action'] ?? '';

/**
 * Parse a path like "products/filename.jpg" into [folder, filename]
 * Security: only allows known folders, strips traversal
 */
function parsePath($path, $allowedFolders) {
    $path = str_replace(['..', '\\'], '', $path);
    if (strpos($path, '/') !== false) {
        $parts = explode('/', $path, 2);
        $folder = $parts[0];
        $file = basename($parts[1]);
    } else {
        $folder = 'products'; // default
        $file = basename($path);
    }
    if (!in_array($folder, $allowedFolders)) $folder = 'products';
    return [$folder, $file];
}

try {
    switch ($action) {

        case 'upload':
            if (empty($_FILES['files'])) {
                echo json_encode(['success' => false, 'message' => 'No files uploaded']);
                exit;
            }
            $targetFolder = sanitize($_POST['folder'] ?? 'products');
            if (!in_array($targetFolder, $allowedFolders)) $targetFolder = 'products';
            $targetDir = UPLOAD_PATH . $targetFolder . '/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

            $uploaded = 0;
            $errors = [];
            $files = $_FILES['files'];
            $count = is_array($files['name']) ? count($files['name']) : 1;

            for ($i = 0; $i < $count; $i++) {
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];

                if ($error !== UPLOAD_ERR_OK) { $errors[] = "{$name}: upload error"; continue; }
                if ($size > 10 * 1024 * 1024) { $errors[] = "{$name}: too large (max 10MB)"; continue; }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) { $errors[] = "{$name}: invalid type"; continue; }

                $newName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($name, PATHINFO_FILENAME));
                $newName = substr($newName, 0, 50) . '_' . time() . '_' . $i . '.' . $ext;

                if (move_uploaded_file($tmp, $targetDir . $newName)) {
                    $uploaded++;
                    if (in_array($ext, ['jpg','jpeg','png']) && function_exists('imagewebp')) {
                        convertToWebp($targetDir . $newName, $targetDir);
                    }
                } else {
                    $errors[] = "{$name}: move failed";
                }
            }
            echo json_encode(['success' => $uploaded > 0, 'uploaded' => $uploaded, 'errors' => $errors, 'folder' => $targetFolder]);
            break;

        case 'delete':
            $filesToDelete = $input['files'] ?? [];
            $deleted = 0;
            foreach ($filesToDelete as $filePath) {
                [$folder, $file] = parsePath($filePath, $allowedFolders);
                $fullPath = UPLOAD_PATH . $folder . '/' . $file;
                if (file_exists($fullPath) && is_file($fullPath)) {
                    @unlink($fullPath);
                    $deleted++;
                }
            }
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            break;

        case 'convert_webp':
            $filesToConvert = $input['files'] ?? [];
            $converted = 0;
            foreach ($filesToConvert as $filePath) {
                [$folder, $file] = parsePath($filePath, $allowedFolders);
                $fullPath = UPLOAD_PATH . $folder . '/' . $file;
                $outDir = UPLOAD_PATH . $folder . '/';
                if (file_exists($fullPath) && convertToWebp($fullPath, $outDir)) {
                    $converted++;
                }
            }
            echo json_encode(['success' => true, 'converted' => $converted]);
            break;

        case 'move':
            // Accepts: file = "products/filename.jpg", target_folder = "banners"
            $filePath = $input['file'] ?? '';
            $targetFolder = $input['target_folder'] ?? '';

            if (!$filePath || !$targetFolder) {
                echo json_encode(['success' => false, 'message' => 'File and target folder required']);
                break;
            }

            [$srcFolder, $file] = parsePath($filePath, $allowedFolders);
            if (!in_array($targetFolder, $allowedFolders)) {
                echo json_encode(['success' => false, 'message' => 'Invalid target folder']);
                break;
            }

            if ($srcFolder === $targetFolder) {
                echo json_encode(['success' => false, 'message' => 'Already in that folder']);
                break;
            }

            $srcPath = UPLOAD_PATH . $srcFolder . '/' . $file;
            $destDir = UPLOAD_PATH . $targetFolder . '/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);

            if (file_exists($srcPath) && rename($srcPath, $destDir . $file)) {
                echo json_encode(['success' => true, 'message' => "Moved to {$targetFolder}"]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Move failed - file not found']);
            }
            break;

        case 'list':
            // List files in a folder (for AJAX)
            $folder = $input['folder'] ?? $_GET['folder'] ?? 'products';
            if (!in_array($folder, $allowedFolders)) $folder = 'products';
            $dir = UPLOAD_PATH . $folder . '/';
            $allowed = ['jpg','jpeg','png','gif','webp','svg'];
            $result = [];
            if (is_dir($dir)) {
                foreach (scandir($dir, SCANDIR_SORT_DESCENDING) as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed)) continue;
                    $result[] = [
                        'name' => $f,
                        'folder' => $folder,
                        'path' => $folder . '/' . $f,
                        'url' => uploadUrl($folder . '/' . $f),
                        'size' => @filesize($dir . $f),
                    ];
                }
            }
            echo json_encode(['success' => true, 'files' => $result]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'Fatal: ' . $e->getMessage()]);
}

function convertToWebp($sourcePath, $outputDir) {
    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $baseName = pathinfo($sourcePath, PATHINFO_FILENAME);
    $webpPath = $outputDir . $baseName . '.webp';

    if (file_exists($webpPath)) return true;

    try {
        switch ($ext) {
            case 'jpg': case 'jpeg': $img = @imagecreatefromjpeg($sourcePath); break;
            case 'png':
                $img = @imagecreatefrompng($sourcePath);
                if ($img) { imagepalettetotruecolor($img); imagealphablending($img, true); imagesavealpha($img, true); }
                break;
            default: return false;
        }
        if (!$img) return false;
        $result = imagewebp($img, $webpPath, 82);
        imagedestroy($img);
        return $result;
    } catch (Exception $e) {
        return false;
    }
}
