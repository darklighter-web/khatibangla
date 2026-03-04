<?php
/**
 * Central Media API - Upload, Delete, Convert to WebP, Move
 * Supports ALL upload directories
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

$allowedFolders = ['products', 'banners', 'logos', 'categories', 'general', 'avatars', 'expenses'];
$allowedExts = ['jpg','jpeg','png','gif','webp','svg'];

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

function parsePath($path, $allowedFolders) {
    $path = str_replace(['..', '\\'], '', $path);
    if (strpos($path, '/') !== false) {
        $parts = explode('/', $path, 2);
        $folder = $parts[0];
        $file = basename($parts[1]);
    } else {
        $folder = 'general';
        $file = basename($path);
    }
    if (!in_array($folder, $allowedFolders)) $folder = 'general';
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
            $uploadedFiles = [];
            $files = $_FILES['files'];
            $count = is_array($files['name']) ? count($files['name']) : 1;

            for ($i = 0; $i < $count; $i++) {
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];

                if (empty($name)) continue;
                if ($error !== UPLOAD_ERR_OK) { $errors[] = "{$name}: upload error code {$error}"; continue; }
                if ($size > 10 * 1024 * 1024) { $errors[] = "{$name}: too large (max 10MB)"; continue; }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExts)) { $errors[] = "{$name}: invalid type ({$ext})"; continue; }

                $newName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($name, PATHINFO_FILENAME));
                $newName = substr($newName, 0, 50) . '_' . time() . '_' . $i . '.' . $ext;

                if (move_uploaded_file($tmp, $targetDir . $newName)) {
                    $uploaded++;
                    $uploadedFiles[] = [
                        'name' => $newName,
                        'path' => $targetFolder . '/' . $newName,
                        'url' => uploadUrl($targetFolder . '/' . $newName),
                    ];
                } else {
                    $errors[] = "{$name}: move failed (check folder permissions)";
                }
            }
            echo json_encode([
                'success' => $uploaded > 0, 
                'uploaded' => $uploaded, 
                'files' => $uploadedFiles,
                'errors' => $errors, 
                'folder' => $targetFolder
            ]);
            break;

        case 'delete':
            $filesToDelete = $input['files'] ?? [];
            if (!is_array($filesToDelete)) $filesToDelete = [$filesToDelete];
            $deleted = 0;
            $deleteErrors = [];
            foreach ($filesToDelete as $filePath) {
                if (empty($filePath)) continue;
                [$folder, $file] = parsePath($filePath, $allowedFolders);
                $fullPath = UPLOAD_PATH . $folder . '/' . $file;
                
                if (!file_exists($fullPath)) {
                    // Try direct path in case it's stored differently
                    $altPath = UPLOAD_PATH . basename($filePath);
                    if (file_exists($altPath)) {
                        $fullPath = $altPath;
                    } else {
                        $deleteErrors[] = "Not found: {$filePath}";
                        continue;
                    }
                }
                
                if (is_file($fullPath)) {
                    if (@unlink($fullPath)) {
                        $deleted++;
                    } else {
                        // Try chmod then delete
                        @chmod($fullPath, 0666);
                        if (@unlink($fullPath)) {
                            $deleted++;
                        } else {
                            $deleteErrors[] = "Permission denied: {$file}";
                        }
                    }
                }
            }
            echo json_encode(['success' => $deleted > 0 || empty($filesToDelete), 'deleted' => $deleted, 'errors' => $deleteErrors]);
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
            $folder = $input['folder'] ?? $_GET['folder'] ?? 'products';
            if ($folder !== 'all' && !in_array($folder, $allowedFolders)) $folder = 'products';
            
            $result = [];
            $scanFolders = ($folder === 'all') ? $allowedFolders : [$folder];
            
            foreach ($scanFolders as $f) {
                $dir = UPLOAD_PATH . $f . '/';
                if (!is_dir($dir)) continue;
                foreach (scandir($dir, SCANDIR_SORT_DESCENDING) as $fname) {
                    if ($fname === '.' || $fname === '..') continue;
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExts)) continue;
                    $result[] = [
                        'name' => $fname,
                        'folder' => $f,
                        'path' => $f . '/' . $fname,
                        'url' => uploadUrl($f . '/' . $fname),
                        'size' => @filesize($dir . $fname),
                        'modified' => @filemtime($dir . $fname),
                    ];
                }
            }
            usort($result, fn($a, $b) => ($b['modified'] ?? 0) - ($a['modified'] ?? 0));
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
    if (!function_exists('imagewebp')) return false;
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
    } catch (\Throwable $e) {
        return false;
    }
}
