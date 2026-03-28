<?php
/**
 * One-Time Cleanup Script
 * Deletes junk folders created by bad zip extractions
 * 
 * Usage: Visit https://khatibangla.com/admin/cleanup.php
 * DELETE THIS FILE AFTER RUNNING
 */

// Security: only allow logged-in admins
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$basePath = __DIR__;
$deleted = [];
$errors = [];

// Junk folders to delete
$junkFolders = [
    '{includes.pages.api}',
    '{pages.api}',
    '{pages.includes}',
];

function deleteDir($dir) {
    if (!is_dir($dir)) return false;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) { deleteDir($path); }
        else { unlink($path); }
    }
    return rmdir($dir);
}

foreach ($junkFolders as $folder) {
    $fullPath = $basePath . '/' . $folder;
    if (is_dir($fullPath)) {
        if (deleteDir($fullPath)) {
            $deleted[] = $folder;
        } else {
            $errors[] = $folder . ' (permission denied)';
        }
    }
}

// Also scan for any other folders with curly braces (bad extractions)
$scan = scandir($basePath);
foreach ($scan as $item) {
    if ($item === '.' || $item === '..') continue;
    if (strpos($item, '{') !== false && is_dir($basePath . '/' . $item)) {
        $fullPath = $basePath . '/' . $item;
        if (!in_array($item, $junkFolders)) {
            if (deleteDir($fullPath)) {
                $deleted[] = $item . ' (auto-detected)';
            } else {
                $errors[] = $item . ' (permission denied)';
            }
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html><head><title>Cleanup Done</title></head>
<body style="font-family:Inter,sans-serif;max-width:600px;margin:40px auto;padding:20px">
<h2>🧹 Admin Folder Cleanup</h2>

<?php if (!empty($deleted)): ?>
<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px;margin-bottom:16px">
    <h3 style="color:#16a34a;margin:0 0 8px">✅ Deleted <?= count($deleted) ?> junk folder(s):</h3>
    <ul style="margin:0;padding-left:20px">
    <?php foreach ($deleted as $d): ?>
        <li style="color:#166534"><?= htmlspecialchars($d) ?></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px;margin-bottom:16px">
    <h3 style="color:#dc2626;margin:0 0 8px">❌ Failed to delete:</h3>
    <ul style="margin:0;padding-left:20px">
    <?php foreach ($errors as $e): ?>
        <li style="color:#991b1b"><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (empty($deleted) && empty($errors)): ?>
<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px;margin-bottom:16px">
    <p style="color:#0369a1;margin:0">✓ No junk folders found — already clean!</p>
</div>
<?php endif; ?>

<h3>Current admin/ contents:</h3>
<table style="width:100%;border-collapse:collapse;font-size:14px">
<tr style="background:#f1f5f9"><th style="text-align:left;padding:6px">Name</th><th style="text-align:left;padding:6px">Type</th><th style="text-align:left;padding:6px">Modified</th></tr>
<?php foreach (scandir($basePath) as $item):
    if ($item === '.' || $item === '..') continue;
    $fp = $basePath . '/' . $item;
    $isDir = is_dir($fp);
    $isJunk = strpos($item, '{') !== false;
?>
<tr style="border-bottom:1px solid #e5e7eb;<?= $isJunk ? 'background:#fef2f2' : '' ?>">
    <td style="padding:6px"><?= $isDir ? '📁' : '📄' ?> <?= htmlspecialchars($item) ?><?= $isJunk ? ' ⚠️' : '' ?></td>
    <td style="padding:6px;color:#6b7280"><?= $isDir ? 'folder' : 'file' ?></td>
    <td style="padding:6px;color:#6b7280"><?= date('d M Y H:i', filemtime($fp)) ?></td>
</tr>
<?php endforeach; ?>
</table>

<div style="margin-top:24px;padding:16px;background:#fef9c3;border:1px solid #fde68a;border-radius:8px">
    <p style="color:#92400e;margin:0;font-weight:600">⚠️ DELETE THIS FILE NOW</p>
    <p style="color:#92400e;margin:4px 0 0;font-size:13px">Delete <code>admin/cleanup.php</code> from your server after running.</p>
</div>

<p style="margin-top:16px"><a href="<?= 'pages/order-management.php' ?>" style="color:#2563eb">← Back to Orders</a></p>
</body></html>
