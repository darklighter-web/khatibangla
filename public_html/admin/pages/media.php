<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

// ── JSON API for Media Picker ──
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $folders = ['products', 'banners', 'logos', 'categories', 'general'];
    $allowed = ['jpg','jpeg','png','gif','webp','svg'];
    $activeFolder = $_GET['folder'] ?? 'all';
    
    if ($_GET['api'] === 'list') {
        $allFiles = [];
        $scanFolders = ($activeFolder === 'all') ? $folders : [$activeFolder];
        foreach ($scanFolders as $folder) {
            $dir = UPLOAD_PATH . $folder . '/';
            if (!is_dir($dir)) continue;
            foreach (scandir($dir, SCANDIR_SORT_DESCENDING) as $f) {
                if ($f === '.' || $f === '..') continue;
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) continue;
                $allFiles[] = [
                    'name' => $f,
                    'folder' => $folder,
                    'path' => $folder . '/' . $f,
                    'url' => uploadUrl($folder . '/' . $f),
                    'size' => @filesize($dir . $f),
                    'modified' => @filemtime($dir . $f),
                ];
            }
        }
        usort($allFiles, fn($a, $b) => ($b['modified'] ?? 0) - ($a['modified'] ?? 0));
        echo json_encode(['success' => true, 'files' => $allFiles]);
        exit;
    }
    
    if ($_GET['api'] === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $folder = sanitize($_POST['folder'] ?? 'general');
        if (!in_array($folder, $folders)) $folder = 'general';
        $count = 0;
        if (!empty($_FILES['files']['name'][0])) {
            foreach ($_FILES['files']['name'] as $key => $name) {
                if (!$name) continue;
                $file = [
                    'name' => $name,
                    'type' => $_FILES['files']['type'][$key],
                    'tmp_name' => $_FILES['files']['tmp_name'][$key],
                    'error' => $_FILES['files']['error'][$key],
                    'size' => $_FILES['files']['size'][$key],
                ];
                $path = uploadFile($file, $folder);
                if ($path) $count++;
            }
        }
        echo json_encode(['success' => $count > 0, 'count' => $count]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}
$pageTitle = 'Media Gallery';
$isPicker = isset($_GET['picker']);
$pickerFolder = $_GET['folder'] ?? '';

// All upload folders to scan
$folders = ['products', 'banners', 'logos', 'general'];
$activeFolder = $_GET['f'] ?? 'all';
$allowed = ['jpg','jpeg','png','gif','webp','svg'];

// Scan all folders
$allFiles = [];
foreach ($folders as $folder) {
    $dir = UPLOAD_PATH . $folder . '/';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); continue; }
    foreach (scandir($dir, SCANDIR_SORT_DESCENDING) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) continue;
        $fullPath = $dir . $f;
        $allFiles[] = [
            'name' => $f,
            'folder' => $folder,
            'ext' => $ext,
            'size' => @filesize($fullPath),
            'modified' => @filemtime($fullPath),
            'url' => uploadUrl($folder . '/' . $f),
            'path' => $folder . '/' . $f,
            'is_webp' => $ext === 'webp',
        ];
    }
}

// Sort by modified time descending
usort($allFiles, fn($a, $b) => ($b['modified'] ?? 0) - ($a['modified'] ?? 0));

// Apply folder filter
$files = ($activeFolder === 'all') ? $allFiles : array_filter($allFiles, fn($f) => $f['folder'] === $activeFolder);
$files = array_values($files);

// Stats
$totalFiles = count($files);
$totalSize = array_sum(array_column($files, 'size'));
$webpCount = count(array_filter($files, fn($f) => $f['is_webp']));
$nonWebpCount = $totalFiles - $webpCount;

// Folder counts
$folderCounts = ['all' => count($allFiles)];
foreach ($folders as $folder) {
    $folderCounts[$folder] = count(array_filter($allFiles, fn($f) => $f['folder'] === $folder));
}

function formatBytes($bytes, $precision = 1) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) $bytes /= 1024;
    return round($bytes, $precision) . ' ' . $units[$i];
}

// ================================
// PICKER MODE (popup for product/banner forms)
// ================================
if ($isPicker) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Images</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
<div class="p-4">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-gray-800">🖼️ Select Images</h2>
        <div class="flex gap-2">
            <span id="pickerCount" class="text-sm text-gray-500 self-center">0 selected</span>
            <button onclick="confirmSelection()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">✓ Use Selected</button>
        </div>
    </div>
    <!-- Folder tabs -->
    <div class="flex gap-2 mb-4">
        <?php 
        $pf = $pickerFolder ?: 'all';
        foreach (array_merge(['all'], $folders) as $tab): 
            $cnt = $folderCounts[$tab] ?? 0;
            $isActive = $pf === $tab;
        ?>
        <a href="?picker=1&folder=<?= $tab ?>" class="text-xs px-3 py-1.5 rounded-full <?= $isActive ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-600 hover:bg-gray-300' ?>">
            <?= ucfirst($tab) ?> <span class="ml-1 opacity-70"><?= $cnt ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <!-- Upload in picker -->
    <div class="mb-4">
        <input type="file" id="pickerUpload" multiple accept="image/*" class="hidden" onchange="pickerUploadFiles(this)">
        <button onclick="document.getElementById('pickerUpload').click()" class="text-sm text-blue-600 hover:text-blue-800">📤 Upload new images</button>
        <span id="pickerUploadStatus" class="text-xs text-gray-400 ml-2"></span>
    </div>
    <div class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 gap-3">
        <?php 
        $pickerFiles = ($pf === 'all') ? $allFiles : array_filter($allFiles, fn($f) => $f['folder'] === $pf);
        foreach ($pickerFiles as $f): ?>
        <div class="picker-item relative cursor-pointer group" data-path="<?= e($f['path']) ?>" data-folder="<?= e($f['folder']) ?>" onclick="togglePick(this)">
            <div class="aspect-square rounded-lg overflow-hidden border-2 border-gray-200 hover:border-blue-400 transition bg-gray-50">
                <img src="<?= $f['url'] ?>" class="w-full h-full object-cover" loading="lazy">
            </div>
            <div class="picker-check hidden absolute top-1 right-1 w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs">✓</div>
            <p class="text-xs text-gray-500 truncate mt-1"><?= $f['name'] ?></p>
            <span class="text-xs text-gray-300"><?= $f['folder'] ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
const selected = new Set();
function togglePick(el) {
    const path = el.dataset.path;
    const check = el.querySelector('.picker-check');
    const border = el.querySelector('div');
    if (selected.has(path)) {
        selected.delete(path); check.classList.add('hidden'); border.classList.remove('border-blue-500'); border.classList.add('border-gray-200');
    } else {
        selected.add(path); check.classList.remove('hidden'); border.classList.remove('border-gray-200'); border.classList.add('border-blue-500');
    }
    document.getElementById('pickerCount').textContent = selected.size + ' selected';
}
function confirmSelection() {
    if (selected.size === 0) { alert('Select at least one image'); return; }
    if (window.opener && window.opener.onMediaSelected) {
        window.opener.onMediaSelected(Array.from(selected));
    }
    window.close();
}
function pickerUploadFiles(input) {
    const form = new FormData();
    form.append('action', 'upload');
    form.append('folder', '<?= e($pf === 'all' ? 'products' : $pf) ?>');
    for (let i = 0; i < input.files.length; i++) form.append('files[]', input.files[i]);
    document.getElementById('pickerUploadStatus').textContent = 'Uploading...';
    fetch('<?= SITE_URL ?>/api/media.php', { method: 'POST', body: form })
    .then(r => r.json()).then(d => {
        document.getElementById('pickerUploadStatus').textContent = d.success ? '✓ Uploaded!' : 'Failed';
        setTimeout(() => location.reload(), 1000);
    });
}
</script>
</body></html>
<?php exit; }

// ================================
// FULL GALLERY MODE
// ================================
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h3 class="text-xl font-bold text-gray-800">🖼️ Central Media Gallery</h3>
        <p class="text-sm text-gray-500 mt-1">
            <?= $totalFiles ?> files · <?= formatBytes($totalSize) ?> total
            · <span class="text-green-600"><?= $webpCount ?> WebP</span>
            · <span class="text-orange-600"><?= $nonWebpCount ?> non-WebP</span>
        </p>
    </div>
    <div class="flex gap-2">
        <select id="uploadFolder" class="px-3 py-2 border rounded-lg text-sm">
            <?php foreach ($folders as $folder): ?>
            <option value="<?= $folder ?>" <?= $activeFolder === $folder ? 'selected' : ($activeFolder === 'all' ? '' : '') ?>><?= ucfirst($folder) ?></option>
            <?php endforeach; ?>
        </select>
        <button onclick="document.getElementById('uploadInput').click()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">📤 Upload</button>
        <button onclick="bulkConvert()" id="convertBtn" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50" disabled>🔄 Convert WebP</button>
        <button onclick="bulkDelete()" id="deleteBtn" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 disabled:opacity-50" disabled>🗑 Delete</button>
    </div>
</div>

<input type="file" id="uploadInput" multiple accept="image/*" class="hidden" onchange="uploadFiles(this)">

<!-- Drop Zone -->
<div id="dropZone" class="hidden border-4 border-dashed border-blue-400 bg-blue-50 rounded-xl p-8 mb-6 text-center transition">
    <p class="text-blue-600 font-medium text-lg">📁 Drop images here to upload</p>
    <p class="text-blue-400 text-sm mt-1">Files will be added to the selected folder</p>
</div>

<!-- Upload progress -->
<div id="uploadProgress" class="hidden mb-6">
    <div class="bg-white rounded-xl border p-4">
        <div class="flex items-center gap-3">
            <span class="animate-spin text-blue-500">⟳</span>
            <span id="uploadStatus" class="text-sm text-gray-600">Uploading...</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
            <div id="uploadBar" class="bg-blue-600 h-2 rounded-full transition-all" style="width:0%"></div>
        </div>
    </div>
</div>

<!-- Folder Tabs -->
<div class="flex flex-wrap gap-2 mb-4">
    <?php 
    $folderIcons = ['all'=>'📁','products'=>'📦','banners'=>'🖼️','logos'=>'🏷️','general'=>'📂'];
    foreach (array_merge(['all'], $folders) as $tab): 
        $cnt = $folderCounts[$tab] ?? 0;
        $isActive = $activeFolder === $tab;
    ?>
    <a href="?f=<?= $tab ?>" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium transition <?= $isActive ? 'bg-blue-600 text-white shadow-sm' : 'bg-white text-gray-600 hover:bg-gray-50 border' ?>">
        <?= $folderIcons[$tab] ?? '📂' ?> <?= ucfirst($tab) ?>
        <span class="<?= $isActive ? 'bg-blue-500' : 'bg-gray-100' ?> px-2 py-0.5 rounded-full text-xs"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Toolbar -->
<div class="bg-white rounded-xl shadow-sm border p-3 mb-4 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" class="rounded">
            <span class="text-sm text-gray-600">Select All</span>
        </label>
        <span id="selCount" class="text-sm text-gray-400">0 selected</span>
    </div>
    <div class="flex items-center gap-2">
        <input type="text" id="searchInput" placeholder="Search files..." class="px-3 py-1.5 border rounded-lg text-xs w-40" oninput="filterImages()">
        <select id="filterType" onchange="filterImages()" class="px-3 py-1.5 border rounded-lg text-xs">
            <option value="all">All Types</option>
            <option value="webp">WebP Only</option>
            <option value="non-webp">Non-WebP Only</option>
            <option value="jpg">JPG</option>
            <option value="png">PNG</option>
        </select>
        <button onclick="toggleView('grid')" class="p-1.5 rounded hover:bg-gray-100" title="Grid">⊞</button>
        <button onclick="toggleView('list')" class="p-1.5 rounded hover:bg-gray-100" title="List">☰</button>
    </div>
</div>

<!-- Image Grid -->
<div id="mediaGrid" class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8 gap-3">
    <?php foreach ($files as $f): ?>
    <div class="media-item relative group" data-name="<?= e($f['name']) ?>" data-folder="<?= e($f['folder']) ?>" data-path="<?= e($f['path']) ?>" data-ext="<?= $f['ext'] ?>" data-webp="<?= $f['is_webp']?1:0 ?>">
        <div class="aspect-square rounded-lg overflow-hidden border-2 border-gray-200 hover:border-blue-400 transition bg-gray-50 cursor-pointer" onclick="toggleSelect(this.parentElement)">
            <img src="<?= $f['url'] ?>" class="w-full h-full object-cover" loading="lazy" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📷</text></svg>'">
        </div>
        <div class="sel-check hidden absolute top-1 left-1 w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs shadow">✓</div>
        <div class="absolute top-1 right-1 flex gap-0.5">
            <span class="text-xs px-1.5 py-0.5 rounded-full font-medium <?= $f['is_webp'] ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700' ?>"><?= strtoupper($f['ext']) ?></span>
        </div>
        <div class="mt-1 flex items-center justify-between">
            <p class="text-xs text-gray-500 truncate flex-1" title="<?= e($f['name']) ?>"><?= e($f['name']) ?></p>
            <span class="text-xs text-gray-400 ml-1"><?= formatBytes($f['size']) ?></span>
        </div>
        <p class="text-xs text-gray-300"><?= $f['folder'] ?></p>
        <!-- Hover actions -->
        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition rounded-lg flex items-center justify-center gap-2 pointer-events-none group-hover:pointer-events-auto" style="height:calc(100% - 36px)">
            <a href="<?= $f['url'] ?>" target="_blank" class="p-2 bg-white rounded-lg text-gray-600 hover:text-blue-600 shadow" title="View full">👁</a>
            <button onclick="event.stopPropagation();copyUrl('<?= $f['url'] ?>')" class="p-2 bg-white rounded-lg text-gray-600 hover:text-green-600 shadow" title="Copy URL">🔗</button>
            <button onclick="event.stopPropagation();moveFile('<?= e($f['path']) ?>')" class="p-2 bg-white rounded-lg text-gray-600 hover:text-purple-600 shadow" title="Move to folder">📁</button>
            <button onclick="event.stopPropagation();deleteSingle('<?= e($f['path']) ?>')" class="p-2 bg-white rounded-lg text-gray-600 hover:text-red-600 shadow" title="Delete">🗑</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (empty($files)): ?>
<div class="text-center py-16 bg-white rounded-xl border">
    <div class="text-5xl mb-4">🖼️</div>
    <p class="text-gray-500">No images in <?= $activeFolder === 'all' ? 'any folder' : ucfirst($activeFolder) ?>. Upload some to get started.</p>
</div>
<?php endif; ?>

<!-- Convert Progress Modal -->
<div id="convertModal" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center">
    <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4">
        <h3 class="font-bold text-lg mb-3">🔄 Converting to WebP</h3>
        <p id="convertStatus" class="text-sm text-gray-600 mb-3">Preparing...</p>
        <div class="w-full bg-gray-200 rounded-full h-3">
            <div id="convertBar" class="bg-green-500 h-3 rounded-full transition-all" style="width:0%"></div>
        </div>
        <p id="convertDetail" class="text-xs text-gray-400 mt-2"></p>
    </div>
</div>

<!-- Move File Modal -->
<div id="moveModal" class="fixed inset-0 z-50 hidden bg-black/40 flex items-center justify-center">
    <div class="bg-white rounded-xl p-5 w-80 shadow-2xl">
        <h3 class="font-bold text-gray-800 mb-3">📁 Move to Folder</h3>
        <input type="hidden" id="moveFilePath">
        <div class="grid grid-cols-2 gap-2 mb-3">
            <?php foreach ($folders as $folder): ?>
            <button onclick="doMove('<?= $folder ?>')" class="text-sm bg-gray-100 hover:bg-blue-100 text-gray-700 px-3 py-2 rounded-lg"><?= $folderIcons[$folder] ?? '📂' ?> <?= ucfirst($folder) ?></button>
            <?php endforeach; ?>
        </div>
        <button onclick="document.getElementById('moveModal').classList.add('hidden')" class="text-xs text-gray-400 hover:text-gray-600 w-full text-center">Cancel</button>
    </div>
</div>

<script>
const API_URL = '<?= SITE_URL ?>/api/media.php';
const selected = new Set();

// Drag & Drop
document.addEventListener('dragover', e => { e.preventDefault(); document.getElementById('dropZone').classList.remove('hidden'); });
document.addEventListener('dragleave', e => { if (!e.relatedTarget || e.relatedTarget === document.documentElement) document.getElementById('dropZone').classList.add('hidden'); });
document.addEventListener('drop', e => {
    e.preventDefault(); document.getElementById('dropZone').classList.add('hidden');
    if (e.dataTransfer.files.length) uploadFilesFromList(e.dataTransfer.files);
});

function uploadFiles(input) { uploadFilesFromList(input.files); }

function uploadFilesFromList(fileList) {
    const folder = document.getElementById('uploadFolder').value;
    const form = new FormData();
    form.append('action', 'upload');
    form.append('folder', folder);
    for (let i = 0; i < fileList.length; i++) form.append('files[]', fileList[i]);
    
    const prog = document.getElementById('uploadProgress');
    prog.classList.remove('hidden');
    document.getElementById('uploadStatus').textContent = `Uploading ${fileList.length} file(s) to ${folder}...`;
    
    const xhr = new XMLHttpRequest();
    xhr.upload.onprogress = e => {
        if (e.lengthComputable) document.getElementById('uploadBar').style.width = Math.round(e.loaded/e.total*100)+'%';
    };
    xhr.onload = () => {
        try {
            const d = JSON.parse(xhr.responseText);
            document.getElementById('uploadStatus').textContent = d.success ? `✓ ${d.uploaded || fileList.length} files uploaded to ${folder}` : 'Upload failed: ' + (d.message || '');
        } catch(e) {
            document.getElementById('uploadStatus').textContent = 'Upload failed';
        }
        setTimeout(() => location.reload(), 1200);
    };
    xhr.open('POST', API_URL);
    xhr.send(form);
}

function toggleSelect(el) {
    const path = el.dataset.path;
    const check = el.querySelector('.sel-check');
    const border = el.querySelector('div');
    if (selected.has(path)) {
        selected.delete(path); check.classList.add('hidden'); border.classList.remove('border-blue-500');
    } else {
        selected.add(path); check.classList.remove('hidden'); border.classList.add('border-blue-500');
    }
    updateToolbar();
}

function toggleSelectAll(cb) {
    document.querySelectorAll('.media-item:not(.hidden)').forEach(el => {
        const path = el.dataset.path;
        const check = el.querySelector('.sel-check');
        const border = el.querySelector('div');
        if (cb.checked) { selected.add(path); check.classList.remove('hidden'); border.classList.add('border-blue-500'); }
        else { selected.delete(path); check.classList.add('hidden'); border.classList.remove('border-blue-500'); }
    });
    updateToolbar();
}

function updateToolbar() {
    document.getElementById('selCount').textContent = selected.size + ' selected';
    document.getElementById('deleteBtn').disabled = selected.size === 0;
    const hasNonWebp = Array.from(selected).some(path => {
        const el = document.querySelector(`.media-item[data-path="${CSS.escape(path)}"]`);
        return el && el.dataset.webp === '0';
    });
    document.getElementById('convertBtn').disabled = selected.size === 0 || !hasNonWebp;
}

function filterImages() {
    const filter = document.getElementById('filterType').value;
    const search = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.media-item').forEach(el => {
        const ext = el.dataset.ext;
        const isWebp = el.dataset.webp === '1';
        const name = el.dataset.name.toLowerCase();
        let show = true;
        if (filter === 'webp') show = isWebp;
        else if (filter === 'non-webp') show = !isWebp;
        else if (filter === 'jpg') show = ext === 'jpg' || ext === 'jpeg';
        else if (filter === 'png') show = ext === 'png';
        if (search && !name.includes(search)) show = false;
        el.classList.toggle('hidden', !show);
    });
}

function deleteSingle(path) {
    if (!confirm('Delete ' + path + '?')) return;
    fetch(API_URL, {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'delete', files: [path]})
    }).then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.message || 'Delete failed'); });
}

function bulkDelete() {
    if (!confirm(`Delete ${selected.size} selected files?`)) return;
    fetch(API_URL, {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'delete', files: Array.from(selected)})
    }).then(r => r.json()).then(d => { if (d.success) location.reload(); });
}

async function bulkConvert() {
    const toConvert = Array.from(selected).filter(path => {
        const el = document.querySelector(`.media-item[data-path="${CSS.escape(path)}"]`);
        return el && el.dataset.webp === '0';
    });
    if (toConvert.length === 0) { alert('No non-WebP images selected'); return; }
    if (!confirm(`Convert ${toConvert.length} images to WebP? Originals will be kept.`)) return;

    const modal = document.getElementById('convertModal');
    modal.classList.remove('hidden');
    let done = 0;
    for (const path of toConvert) {
        document.getElementById('convertStatus').textContent = `Converting ${done+1} of ${toConvert.length}...`;
        document.getElementById('convertDetail').textContent = path;
        try {
            await fetch(API_URL, { method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'convert_webp', files: [path]}) });
        } catch(e) {}
        done++;
        document.getElementById('convertBar').style.width = Math.round(done/toConvert.length*100)+'%';
    }
    document.getElementById('convertStatus').textContent = `✓ ${done} images converted!`;
    setTimeout(() => location.reload(), 1500);
}

function moveFile(path) {
    document.getElementById('moveFilePath').value = path;
    document.getElementById('moveModal').classList.remove('hidden');
}

function doMove(targetFolder) {
    const path = document.getElementById('moveFilePath').value;
    fetch(API_URL, {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'move', file: path, target_folder: targetFolder})
    }).then(r => r.json()).then(d => {
        document.getElementById('moveModal').classList.add('hidden');
        if (d.success) location.reload(); else alert(d.message || 'Move failed');
    });
}

function copyUrl(url) {
    navigator.clipboard.writeText(url).then(() => {
        const t = document.createElement('div');
        t.className = 'fixed top-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50 text-sm';
        t.textContent = '✓ URL copied!';
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 2000);
    });
}

function toggleView(mode) {
    const grid = document.getElementById('mediaGrid');
    if (mode === 'list') {
        grid.className = 'space-y-2';
        grid.querySelectorAll('.media-item').forEach(el => {
            el.classList.add('flex', 'items-center', 'gap-3', 'bg-white', 'rounded-lg', 'p-2', 'border');
        });
    } else {
        location.reload();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
