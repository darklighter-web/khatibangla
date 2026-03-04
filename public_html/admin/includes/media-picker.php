<?php
/**
 * Centralized Media Picker Component
 * Include once in any admin page that needs image selection.
 * 
 * Usage:
 *   openMediaLibrary(callback, options)
 *   callback receives: array of { path, url, folder, name }
 *   options: { multiple: bool, folder: 'products'|'banners'|'all', uploadFolder: 'products' }
 */
if (!empty($_mediaPickerLoaded)) return;
$_mediaPickerLoaded = true;
$_allFolders = ['products', 'banners', 'logos', 'categories', 'general', 'avatars', 'expenses'];
?>

<!-- Media Library Modal -->
<div id="mediaLibraryModal" class="hidden fixed inset-0 z-[100] bg-black/60 flex items-center justify-center p-4" onclick="closeMediaLibrary()">
    <div class="bg-white rounded-2xl w-full max-w-4xl max-h-[85vh] flex flex-col shadow-2xl" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="flex items-center justify-between px-5 py-3 border-b bg-gray-50 rounded-t-2xl">
            <div class="flex items-center gap-3">
                <h3 class="font-bold text-gray-800">🖼️ Media Library</h3>
                <span id="mlSelectedCount" class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full hidden">0 selected</span>
            </div>
            <div class="flex items-center gap-2">
                <label class="flex items-center gap-1.5 bg-blue-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium cursor-pointer hover:bg-blue-700">
                    <i class="fas fa-cloud-upload-alt"></i> Upload
                    <input type="file" id="mlUploadInput" multiple accept="image/*" class="hidden" onchange="mlUploadFiles(this)">
                </label>
                <button onclick="closeMediaLibrary()" class="p-1.5 hover:bg-gray-200 rounded-lg"><i class="fas fa-times text-gray-500"></i></button>
            </div>
        </div>
        
        <!-- Folder Tabs -->
        <div class="flex gap-1 px-5 py-2 border-b bg-gray-50 overflow-x-auto">
            <button onclick="mlFilterFolder('all')" class="ml-tab text-xs px-3 py-1.5 rounded-full bg-blue-600 text-white" data-folder="all">All</button>
            <?php foreach ($_allFolders as $f): ?>
            <button onclick="mlFilterFolder('<?= $f ?>')" class="ml-tab text-xs px-3 py-1.5 rounded-full bg-gray-200 text-gray-600 hover:bg-gray-300" data-folder="<?= $f ?>"><?= ucfirst($f) ?></button>
            <?php endforeach; ?>
            <div class="flex-1"></div>
            <input type="text" id="mlSearchInput" placeholder="Search..." class="text-xs border rounded-lg px-3 py-1 w-36" oninput="mlSearch(this.value)">
        </div>
        
        <!-- Upload Progress -->
        <div id="mlUploadProgress" class="hidden px-5 py-2 bg-blue-50 border-b">
            <div class="flex items-center gap-2 text-xs text-blue-700">
                <i class="fas fa-spinner fa-spin"></i>
                <span id="mlUploadStatus">Uploading...</span>
            </div>
        </div>
        
        <!-- Grid -->
        <div id="mlGrid" class="flex-1 overflow-y-auto p-4 grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 lg:grid-cols-8 gap-2">
            <div class="col-span-full text-center py-12 text-gray-400 text-sm">Click a folder to browse...</div>
        </div>
        
        <!-- Footer -->
        <div class="flex items-center justify-between px-5 py-3 border-t bg-gray-50 rounded-b-2xl">
            <span id="mlFileCount" class="text-xs text-gray-500">0 files</span>
            <div class="flex gap-2">
                <button onclick="closeMediaLibrary()" class="px-4 py-2 text-sm border rounded-lg hover:bg-gray-100">Cancel</button>
                <button onclick="mlConfirmSelection()" id="mlConfirmBtn" class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium disabled:opacity-50" disabled>
                    Select <span id="mlConfirmCount"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
var ML_API = '<?= SITE_URL ?>/api/media.php';

var ML = {
    files: [],
    filtered: [],
    selected: new Set(),
    callback: null,
    multiple: false,
    folder: 'all',
    activeFolder: 'all',
    uploadFolder: 'products'
};

function openMediaLibrary(callback, opts) {
    opts = opts || {};
    ML.callback = callback;
    ML.multiple = opts.multiple !== false;
    ML.folder = opts.folder || 'all';
    ML.uploadFolder = opts.uploadFolder || opts.folder || 'products';
    ML.selected.clear();
    ML.activeFolder = ML.folder;
    
    document.getElementById('mediaLibraryModal').classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
    
    document.querySelectorAll('.ml-tab').forEach(function(t) {
        t.className = t.dataset.folder === ML.activeFolder 
            ? 'ml-tab text-xs px-3 py-1.5 rounded-full bg-blue-600 text-white'
            : 'ml-tab text-xs px-3 py-1.5 rounded-full bg-gray-200 text-gray-600 hover:bg-gray-300';
    });
    
    mlLoadFiles();
    mlUpdateUI();
}

function closeMediaLibrary() {
    document.getElementById('mediaLibraryModal').classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
}

function mlLoadFiles() {
    document.getElementById('mlGrid').innerHTML = '<div class="col-span-full text-center py-12 text-gray-400 text-sm"><i class="fas fa-spinner fa-spin mr-2"></i>Loading...</div>';
    
    var form = new FormData();
    form.append('action', 'list');
    form.append('folder', ML.activeFolder);
    
    fetch(ML_API, { method: 'POST', body: form })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) { throw new Error(data.message || 'Failed'); }
        ML.files = data.files || [];
        ML.filtered = ML.files.slice();
        mlRenderGrid();
    })
    .catch(function(err) {
        console.error('Media load error:', err);
        document.getElementById('mlGrid').innerHTML = '<div class="col-span-full text-center py-12 text-red-400 text-sm">Failed to load. <button onclick="mlLoadFiles()" class="underline">Retry</button></div>';
    });
}

function mlFilterFolder(folder) {
    ML.activeFolder = folder;
    document.querySelectorAll('.ml-tab').forEach(function(t) {
        t.className = t.dataset.folder === folder 
            ? 'ml-tab text-xs px-3 py-1.5 rounded-full bg-blue-600 text-white'
            : 'ml-tab text-xs px-3 py-1.5 rounded-full bg-gray-200 text-gray-600 hover:bg-gray-300';
    });
    mlLoadFiles();
}

function mlSearch(q) {
    q = q.toLowerCase();
    ML.filtered = q ? ML.files.filter(function(f) { return f.name.toLowerCase().indexOf(q) !== -1; }) : ML.files.slice();
    mlRenderGrid();
}

function mlRenderGrid() {
    var grid = document.getElementById('mlGrid');
    if (ML.filtered.length === 0) {
        grid.innerHTML = '<div class="col-span-full text-center py-12 text-gray-400 text-sm">No images found</div>';
        document.getElementById('mlFileCount').textContent = '0 files';
        return;
    }
    
    var html = '';
    ML.filtered.forEach(function(f) {
        var sel = ML.selected.has(f.path);
        html += '<div class="ml-item relative cursor-pointer group rounded-lg overflow-hidden border-2 ' + 
            (sel ? 'border-blue-500 ring-2 ring-blue-200' : 'border-gray-200 hover:border-gray-400') + 
            ' aspect-square bg-gray-50" data-path="' + f.path + '" data-url="' + f.url + 
            '" data-folder="' + f.folder + '" data-name="' + f.name + '" onclick="mlToggle(this)">' +
            '<img src="' + f.url + '" class="w-full h-full object-cover" loading="lazy">' +
            '<div class="absolute top-1 right-1 w-5 h-5 rounded-full flex items-center justify-center text-white text-xs ' + 
            (sel ? 'bg-blue-600' : 'bg-black/30 opacity-0 group-hover:opacity-100') + ' transition">' +
            (sel ? '✓' : '') + '</div>' +
            '<div class="absolute bottom-0 left-0 right-0 bg-black/50 px-1 py-0.5 text-white text-[10px] truncate opacity-0 group-hover:opacity-100 transition">' + f.name + '</div>' +
            '</div>';
    });
    grid.innerHTML = html;
    document.getElementById('mlFileCount').textContent = ML.filtered.length + ' files';
}

function mlToggle(el) {
    var path = el.dataset.path;
    if (!ML.multiple) ML.selected.clear();
    
    if (ML.selected.has(path)) {
        ML.selected.delete(path);
    } else {
        ML.selected.add(path);
    }
    mlRenderGrid();
    mlUpdateUI();
}

function mlUpdateUI() {
    var count = ML.selected.size;
    var countEl = document.getElementById('mlSelectedCount');
    var btn = document.getElementById('mlConfirmBtn');
    var btnCount = document.getElementById('mlConfirmCount');
    
    if (count > 0) {
        countEl.textContent = count + ' selected';
        countEl.classList.remove('hidden');
        btn.disabled = false;
        btnCount.textContent = '(' + count + ')';
    } else {
        countEl.classList.add('hidden');
        btn.disabled = true;
        btnCount.textContent = '';
    }
}

function mlConfirmSelection() {
    if (ML.selected.size === 0) return;
    
    var files = [];
    ML.selected.forEach(function(path) {
        var f = ML.files.find(function(x) { return x.path === path; });
        if (f) files.push({ path: f.path, url: f.url, folder: f.folder, name: f.name });
    });
    
    if (ML.callback) ML.callback(files);
    closeMediaLibrary();
}

function mlUploadFiles(input) {
    if (!input.files.length) return;
    var form = new FormData();
    form.append('action', 'upload');
    form.append('folder', ML.uploadFolder);
    Array.from(input.files).forEach(function(f) { form.append('files[]', f); });
    
    document.getElementById('mlUploadProgress').classList.remove('hidden');
    document.getElementById('mlUploadStatus').textContent = 'Uploading ' + input.files.length + ' files...';
    
    fetch(ML_API, { method: 'POST', body: form })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var msg = data.success ? '✓ ' + (data.uploaded || 0) + ' uploaded!' : 'Failed';
        if (data.errors && data.errors.length) msg += ' (' + data.errors.join(', ') + ')';
        document.getElementById('mlUploadStatus').textContent = msg;
        setTimeout(function() { document.getElementById('mlUploadProgress').classList.add('hidden'); }, 3000);
        input.value = '';
        mlLoadFiles();
    })
    .catch(function(err) {
        console.error('Upload error:', err);
        document.getElementById('mlUploadStatus').textContent = 'Upload error - check console';
        setTimeout(function() { document.getElementById('mlUploadProgress').classList.add('hidden'); }, 3000);
    });
}
</script>
