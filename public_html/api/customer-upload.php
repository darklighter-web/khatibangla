<?php
/**
 * Customer Upload API
 * Accepts image/document from customer, converts images to WebP, saves to /uploads/customer-uploads/
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$uploadsDir = rtrim(UPLOAD_PATH, '/') . '/customer-uploads/';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
    file_put_contents($uploadsDir . 'index.php', '<?php http_response_code(403);');
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'upload';

if ($action === 'upload') {
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
        exit;
    }

    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        echo json_encode(['success' => false, 'message' => 'File too large (max 10MB)']);
        exit;
    }

    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowedImage = ['jpg','jpeg','png','gif','webp','bmp','tiff','tif'];
    $allowedDoc = ['pdf'];
    $allowed = array_merge($allowedImage, $allowedDoc);

    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Unsupported file type. Use JPG, PNG, GIF, WebP, or PDF.']);
        exit;
    }

    $isImage = in_array($ext, $allowedImage);
    $uid = uniqid('cu_', true);
    $savedFile = '';
    $savedSize = $file['size'];

    if ($isImage) {
        // Convert to WebP
        $newName = $uid . '.webp';
        $destPath = $uploadsDir . $newName;
        $converted = false;
        $src = null;

        if (function_exists('imagecreatefromjpeg')) {
            switch ($ext) {
                case 'jpg': case 'jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
                case 'png':
                    $src = @imagecreatefrompng($file['tmp_name']);
                    if ($src) {
                        // Handle PNG transparency: create true-color canvas with alpha
                        $w = imagesx($src);
                        $h = imagesy($src);
                        $canvas = imagecreatetruecolor($w, $h);
                        // Fill with white background (WebP doesn't always support transparency well)
                        $white = imagecolorallocate($canvas, 255, 255, 255);
                        imagefill($canvas, 0, 0, $white);
                        // Preserve alpha for compositing
                        imagealphablending($canvas, true);
                        imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);
                        imagedestroy($src);
                        $src = $canvas;
                    }
                    break;
                case 'gif': $src = @imagecreatefromgif($file['tmp_name']); break;
                case 'webp': $src = @imagecreatefromwebp($file['tmp_name']); break;
                case 'bmp':
                    $src = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($file['tmp_name']) : @imagecreatefromstring(file_get_contents($file['tmp_name']));
                    break;
                default:
                    $src = @imagecreatefromstring(file_get_contents($file['tmp_name']));
                    break;
            }

            if ($src) {
                // Ensure true color
                if (function_exists('imagepalettetotruecolor')) imagepalettetotruecolor($src);
                $converted = @imagewebp($src, $destPath, 35);
                imagedestroy($src);
                if ($converted && file_exists($destPath)) $savedSize = filesize($destPath);
            }
        }

        // Fallback: Imagick
        if (!$converted && class_exists('Imagick')) {
            try {
                $img = new \Imagick($file['tmp_name']);
                $img->setImageFormat('webp');
                $img->setImageCompressionQuality(35);
                $img->stripImage();
                // Flatten for PNGs with transparency
                if (in_array($ext, ['png', 'gif'])) {
                    $img->setImageBackgroundColor('white');
                    $img = $img->flattenImages();
                }
                $img->writeImage($destPath);
                $img->destroy();
                $converted = true;
                $savedSize = filesize($destPath);
            } catch (\Throwable $e) {}
        }

        // Last fallback: copy original
        if (!$converted) {
            $newName = $uid . '.' . $ext;
            $destPath = $uploadsDir . $newName;
            move_uploaded_file($file['tmp_name'], $destPath);
            $savedSize = filesize($destPath);
        }

        $savedFile = $newName;
    } else {
        // PDF - just move
        $newName = $uid . '.' . $ext;
        $destPath = $uploadsDir . $newName;
        move_uploaded_file($file['tmp_name'], $destPath);
        $savedFile = $newName;
        $savedSize = filesize($destPath);
    }

    echo json_encode([
        'success' => true,
        'file' => $savedFile,
        'url' => SITE_URL . '/uploads/customer-uploads/' . $savedFile,
        'size' => $savedSize,
        'original_name' => $origName,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
