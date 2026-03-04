<?php
/**
 * Product Documentation Upload/Delete API
 * - Accepts images up to 10MB
 * - Auto-converts to WebP with maximum compression
 * - Stores in /uploads/product-docs/
 * - Also accepts PDF/DOC/DOCX documents (no conversion)
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$db = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Ensure upload directory exists
$docsDir = rtrim(UPLOAD_PATH, '/') . '/product-docs/';
if (!is_dir($docsDir)) {
    mkdir($docsDir, 0755, true);
    file_put_contents($docsDir . 'index.php', '<?php http_response_code(403);');
}

switch ($action) {

    case 'upload':
        $productId = intval($_POST['product_id'] ?? 0);
        if (!$productId) {
            echo json_encode(['success' => false, 'message' => 'Product ID required']);
            exit;
        }

        // Check current count
        $count = 0;
        try { $count = intval($db->fetch("SELECT COUNT(*) as c FROM product_documents WHERE product_id = ?", [$productId])['c']); } catch (\Throwable $e) {}

        $uploaded = [];
        $errors = [];

        $files = $_FILES['docs'] ?? null;
        if (!$files || empty($files['name'][0])) {
            echo json_encode(['success' => false, 'message' => 'No files uploaded']);
            exit;
        }

        $allowedImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif'];
        $allowedDocExts = ['pdf', 'doc', 'docx'];
        $allowedAll = array_merge($allowedImageExts, $allowedDocExts);
        $maxSize = 10 * 1024 * 1024; // 10MB

        foreach ($files['name'] as $i => $name) {
            if (empty($name) || $files['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ($count >= 20) { $errors[] = "Maximum 20 documents per product"; break; }

            $origName = basename($name);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $size = $files['size'][$i];
            $tmpPath = $files['tmp_name'][$i];

            // Validate extension
            if (!in_array($ext, $allowedAll)) {
                $errors[] = "$origName: Unsupported format ($ext)";
                continue;
            }
            // Validate size
            if ($size > $maxSize) {
                $errors[] = "$origName: File too large (max 10MB)";
                continue;
            }

            $isImage = in_array($ext, $allowedImageExts);
            $newFilename = 'doc_' . $productId . '_' . uniqid() . '_' . time();
            $savedPath = '';
            $savedSize = $size;
            $savedType = $isImage ? 'image' : 'document';

            if ($isImage) {
                // ── Convert to WebP with maximum compression ──
                $newFilename .= '.webp';
                $destPath = $docsDir . $newFilename;

                $converted = false;
                $sourceImg = null;

                // Try GD first
                if (function_exists('imagecreatefromjpeg')) {
                    switch ($ext) {
                        case 'jpg': case 'jpeg': $sourceImg = @imagecreatefromjpeg($tmpPath); break;
                        case 'png': $sourceImg = @imagecreatefrompng($tmpPath); break;
                        case 'gif': $sourceImg = @imagecreatefromgif($tmpPath); break;
                        case 'webp': $sourceImg = @imagecreatefromwebp($tmpPath); break;
                        case 'bmp': $sourceImg = @imagecreatefrombmp($tmpPath); break;
                        default: $sourceImg = @imagecreatefromstring(file_get_contents($tmpPath)); break;
                    }

                    if ($sourceImg) {
                        // Preserve transparency
                        imagealphablending($sourceImg, true);
                        imagesavealpha($sourceImg, true);

                        // Convert to WebP with quality 30 (heavy compression)
                        $converted = imagewebp($sourceImg, $destPath, 30);
                        imagedestroy($sourceImg);

                        if ($converted && file_exists($destPath)) {
                            $savedSize = filesize($destPath);
                        }
                    }
                }

                // Fallback: try Imagick
                if (!$converted && class_exists('Imagick')) {
                    try {
                        $img = new \Imagick($tmpPath);
                        $img->setImageFormat('webp');
                        $img->setImageCompressionQuality(30);
                        $img->stripImage(); // Remove metadata
                        $img->writeImage($destPath);
                        $img->destroy();
                        $converted = true;
                        $savedSize = filesize($destPath);
                    } catch (\Throwable $e) {}
                }

                // Last fallback: just copy the original
                if (!$converted) {
                    $newFilename = 'doc_' . $productId . '_' . uniqid() . '_' . time() . '.' . $ext;
                    $destPath = $docsDir . $newFilename;
                    move_uploaded_file($tmpPath, $destPath);
                    $savedSize = filesize($destPath);
                }

                $savedPath = $newFilename;

            } else {
                // Document files: just move
                $newFilename .= '.' . $ext;
                $destPath = $docsDir . $newFilename;
                move_uploaded_file($tmpPath, $destPath);
                $savedPath = $newFilename;
                $savedSize = filesize($destPath);
            }

            // Insert record
            try {
                $docId = $db->insert('product_documents', [
                    'product_id' => $productId,
                    'file_name' => $savedPath,
                    'original_name' => $origName,
                    'file_size' => $savedSize,
                    'file_type' => $savedType,
                    'sort_order' => $count,
                ]);
                $count++;

                $uploaded[] = [
                    'id' => $docId,
                    'file_name' => $savedPath,
                    'original_name' => $origName,
                    'file_size' => $savedSize,
                    'file_type' => $savedType,
                    'url' => SITE_URL . '/uploads/product-docs/' . $savedPath,
                ];
            } catch (\Throwable $e) {
                $errors[] = "$origName: Database error";
            }
        }

        echo json_encode([
            'success' => !empty($uploaded),
            'uploaded' => $uploaded,
            'errors' => $errors,
            'count' => $count,
        ]);
        break;

    case 'delete':
        $docId = intval($_POST['doc_id'] ?? 0);
        if (!$docId) {
            echo json_encode(['success' => false, 'message' => 'Document ID required']);
            exit;
        }

        $doc = $db->fetch("SELECT * FROM product_documents WHERE id = ?", [$docId]);
        if (!$doc) {
            echo json_encode(['success' => false, 'message' => 'Document not found']);
            exit;
        }

        // Delete file
        $filePath = $docsDir . $doc['file_name'];
        if (file_exists($filePath)) @unlink($filePath);

        // Delete record
        $db->delete('product_documents', 'id = ?', [$docId]);

        echo json_encode(['success' => true]);
        break;

    case 'list':
        $productId = intval($_GET['product_id'] ?? 0);
        $docs = $db->fetchAll("SELECT * FROM product_documents WHERE product_id = ? ORDER BY sort_order, id", [$productId]);
        foreach ($docs as &$d) {
            $d['url'] = SITE_URL . '/uploads/product-docs/' . $d['file_name'];
        }
        echo json_encode(['success' => true, 'docs' => $docs]);
        break;

    case 'bulk_delete':
        // Delete all docs for a product (admin cleanup)
        $productId = intval($_POST['product_id'] ?? 0);
        if (!$productId) { echo json_encode(['success' => false]); exit; }

        $docs = $db->fetchAll("SELECT file_name FROM product_documents WHERE product_id = ?", [$productId]);
        foreach ($docs as $d) {
            $fp = $docsDir . $d['file_name'];
            if (file_exists($fp)) @unlink($fp);
        }
        $db->delete('product_documents', 'product_id = ?', [$productId]);
        echo json_encode(['success' => true, 'deleted' => count($docs)]);
        break;

    case 'cleanup_all':
        // Super admin: erase ALL product documentation files
        if (!isSuperAdmin()) { echo json_encode(['success' => false, 'message' => 'Super admin only']); exit; }
        $allDocs = $db->fetchAll("SELECT file_name FROM product_documents");
        $deleted = 0;
        foreach ($allDocs as $d) {
            $fp = $docsDir . $d['file_name'];
            if (file_exists($fp)) { @unlink($fp); $deleted++; }
        }
        $db->query("DELETE FROM product_documents");
        // Also clean orphan files in directory
        $dirFiles = glob($docsDir . 'doc_*');
        foreach ($dirFiles as $f) { @unlink($f); $deleted++; }
        echo json_encode(['success' => true, 'deleted' => $deleted]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
