<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Banners';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// ── Ensure extended columns exist ──
try {
    $cols = $db->fetchAll("SHOW COLUMNS FROM banners");
    $existingCols = array_column($cols, 'Field');
    $needed = [
        'animation_speed' => 'INT DEFAULT 5000',
        'transition_type' => "VARCHAR(20) DEFAULT 'fade'",
        'overlay_text' => "VARCHAR(255) DEFAULT ''",
        'overlay_subtitle' => "VARCHAR(255) DEFAULT ''",
        'button_text' => "VARCHAR(100) DEFAULT ''",
        'button_url' => "VARCHAR(255) DEFAULT ''",
    ];
    foreach ($needed as $col => $def) {
        if (!in_array($col, $existingCols)) {
            $db->query("ALTER TABLE banners ADD COLUMN {$col} {$def}");
        }
    }
} catch (\Throwable $e) {}

// ── Helper: resize image keeping aspect ratio ──
function resizeBannerImage($filePath, $maxW = 1920, $maxH = 720) {
    if (!file_exists($filePath) || !function_exists('imagecreatefromjpeg')) return;
    $info = @getimagesize($filePath);
    if (!$info) return;
    $origW = $info[0]; $origH = $info[1]; $mime = $info['mime'];
    if ($origW <= $maxW && $origH <= $maxH) return;

    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($filePath); break;
        case 'image/png':  $src = @imagecreatefrompng($filePath); break;
        case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null; break;
        default: return;
    }
    if (!$src) return;

    $ratio = min($maxW / $origW, $maxH / $origH);
    if ($ratio >= 1) { imagedestroy($src); return; }
    $newW = (int)round($origW * $ratio);
    $newH = (int)round($origH * $ratio);
    $dst = imagecreatetruecolor($newW, $newH);

    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

    switch ($mime) {
        case 'image/jpeg': imagejpeg($dst, $filePath, 85); break;
        case 'image/png':  imagepng($dst, $filePath, 8); break;
        case 'image/webp': imagewebp($dst, $filePath, 85); break;
    }
    imagedestroy($src); imagedestroy($dst);
}

// ── Handle POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $bid = intval($_POST['banner_id'] ?? 0);
        $data = [
            'title'            => sanitize($_POST['title'] ?? ''),
            'link_url'         => sanitize($_POST['link_url'] ?? ''),
            'position'         => sanitize($_POST['position'] ?? 'hero'),
            'sort_order'       => intval($_POST['sort_order'] ?? 0),
            'is_active'        => isset($_POST['is_active']) ? 1 : 0,
            'animation_speed'  => intval($_POST['animation_speed'] ?? 5000),
            'transition_type'  => sanitize($_POST['transition_type'] ?? 'fade'),
            'overlay_text'     => sanitize($_POST['overlay_text'] ?? ''),
            'overlay_subtitle' => sanitize($_POST['overlay_subtitle'] ?? ''),
            'button_text'      => sanitize($_POST['button_text'] ?? ''),
            'button_url'       => sanitize($_POST['button_url'] ?? ''),
        ];

        if (!empty($_FILES['image']['name'])) {
            $upload = uploadFile($_FILES['image'], 'banners');
            if ($upload) {
                $fname = basename($upload);
                resizeBannerImage(UPLOAD_PATH . 'banners/' . $fname, 1920, 720);
                $data['image'] = $fname; // Store ONLY filename
            }
        } elseif (!empty($_POST['media_image'])) {
            $data['image'] = basename(sanitize($_POST['media_image'])); // Store ONLY filename
        }

        if (!empty($_FILES['mobile_image']['name'])) {
            $upload = uploadFile($_FILES['mobile_image'], 'banners');
            if ($upload) {
                $fname = basename($upload);
                resizeBannerImage(UPLOAD_PATH . 'banners/' . $fname, 800, 800);
                $data['mobile_image'] = $fname;
            }
        }

        if ($bid) {
            $db->update('banners', $data, 'id = ?', [$bid]);
        } else {
            if (empty($data['image'])) redirect(adminUrl('pages/banners.php?msg=no_image'));
            $db->insert('banners', $data);
        }
        redirect(adminUrl('pages/banners.php?msg=saved'));
    }

    // Bulk upload — multiple images become individual hero banners
    if ($action === 'bulk_upload') {
        $count = 0;
        if (!empty($_FILES['bulk_images']['name'][0])) {
            $maxSort = $db->fetch("SELECT MAX(sort_order) as mx FROM banners WHERE position='hero'");
            $sortStart = ($maxSort['mx'] ?? 0) + 1;
            
            $fileCount = count($_FILES['bulk_images']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                $name = $_FILES['bulk_images']['name'][$i];
                if (empty($name)) continue;
                $file = [
                    'name'     => $name,
                    'type'     => $_FILES['bulk_images']['type'][$i],
                    'tmp_name' => $_FILES['bulk_images']['tmp_name'][$i],
                    'error'    => $_FILES['bulk_images']['error'][$i],
                    'size'     => $_FILES['bulk_images']['size'][$i],
                ];
                $upload = uploadFile($file, 'banners');
                if ($upload) {
                    $fname = basename($upload);
                    resizeBannerImage(UPLOAD_PATH . 'banners/' . $fname, 1920, 720);
                    $db->insert('banners', [
                        'title'      => pathinfo($name, PATHINFO_FILENAME),
                        'image'      => $fname,
                        'position'   => 'hero',
                        'sort_order' => $sortStart + $i,
                        'is_active'  => 1,
                    ]);
                    $count++;
                }
            }
        }
        redirect(adminUrl('pages/banners.php?msg=bulk&count=' . $count));
    }

    // Add banners from media library selection
    if ($action === 'bulk_media_add') {
        $paths = $_POST['media_paths'] ?? [];
        $count = 0;
        $maxSort = $db->fetch("SELECT MAX(sort_order) as mx FROM banners WHERE position='hero'");
        $sortStart = ($maxSort['mx'] ?? 0) + 1;
        foreach ($paths as $i => $path) {
            $fname = basename(sanitize($path));
            if (empty($fname)) continue;
            // Verify file exists
            if (!file_exists(UPLOAD_PATH . 'banners/' . $fname)) {
                // Try with folder prefix
                $cleanPath = str_replace(['..','\\'], '', $path);
                if (file_exists(UPLOAD_PATH . $cleanPath)) {
                    $fname = basename($cleanPath);
                } else continue;
            }
            $db->insert('banners', [
                'title'      => pathinfo($fname, PATHINFO_FILENAME),
                'image'      => $fname,
                'position'   => 'hero',
                'sort_order' => $sortStart + $i,
                'is_active'  => 1,
            ]);
            $count++;
        }
        redirect(adminUrl('pages/banners.php?msg=bulk&count=' . $count));
    }

    if ($action === 'delete') {
        $bid = intval($_POST['banner_id']);
        $banner = $db->fetch("SELECT * FROM banners WHERE id = ?", [$bid]);
        if ($banner && !empty($banner['image'])) {
            $fname = basename($banner['image']);
            $path = UPLOAD_PATH . 'banners/' . $fname;
            if (file_exists($path)) @unlink($path);
        }
        $db->delete('banners', 'id = ?', [$bid]);
        redirect(adminUrl('pages/banners.php?msg=deleted'));
    }

    if ($action === 'toggle') {
        $bid = intval($_POST['banner_id']);
        $banner = $db->fetch("SELECT is_active FROM banners WHERE id = ?", [$bid]);
        if ($banner) $db->update('banners', ['is_active' => $banner['is_active'] ? 0 : 1], 'id = ?', [$bid]);
        redirect(adminUrl('pages/banners.php?msg=toggled'));
    }

    if ($action === 'reorder') {
        $orders = $_POST['banner_order'] ?? [];
        foreach ($orders as $i => $bid) $db->update('banners', ['sort_order' => $i], 'id = ?', [intval($bid)]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

$banners = $db->fetchAll("SELECT * FROM banners ORDER BY position, sort_order, id");
$edit = isset($_GET['edit']) ? $db->fetch("SELECT * FROM banners WHERE id = ?", [intval($_GET['edit'])]) : null;

// Safe getter
function bv($arr, $key, $default = '') { return $arr[$key] ?? $default; }

$heroBanners = array_values(array_filter($banners, fn($b) => $b['position'] === 'hero'));

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.banner-card:hover .banner-overlay { opacity:1; }
.admin-slide { position:absolute; inset:0; opacity:0; transition:opacity 0.6s ease; }
.admin-slide.active { opacity:1; z-index:1; }
</style>

<?php if (isset($_GET['msg'])): ?>
<div class="<?= $_GET['msg']==='no_image' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?> border px-4 py-3 rounded-lg mb-4 text-sm">
    <?php 
    switch($_GET['msg']) {
        case 'no_image': echo 'Please upload an image.'; break;
        case 'bulk': echo '✓ ' . intval($_GET['count'] ?? 0) . ' banner(s) uploaded to slider!'; break;
        default: echo '✓ Action completed.';
    }
    ?>
</div>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h3 class="text-xl font-bold text-gray-800">🖼️ Banner Management</h3>
        <p class="text-sm text-gray-500 mt-1"><?= count($banners) ?> banners · <?= count($heroBanners) ?> in slider</p>
    </div>
    <div class="flex gap-2">
        <button onclick="document.getElementById('bulkUploadBox').classList.toggle('hidden')" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700">📤 Quick Slider Upload</button>
        <a href="?new=1" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">+ Add Banner</a>
    </div>
</div>

<!-- Bulk Upload for Slider -->
<div id="bulkUploadBox" class="hidden bg-white rounded-xl shadow-sm border p-5 mb-6">
    <h4 class="font-semibold text-gray-800 mb-3">📤 Quick Upload — Multiple Slider Images</h4>
    <p class="text-sm text-gray-500 mb-3">Select multiple images at once. Each will become a separate hero slider banner. Images are auto-resized to fit.</p>
    <form method="POST" enctype="multipart/form-data" class="flex items-end gap-4">
        <input type="hidden" name="action" value="bulk_upload">
        <div class="flex-1">
            <input type="file" name="bulk_images[]" multiple accept="image/*" class="w-full text-sm border rounded-lg px-3 py-2" required onchange="showBulkPreview(this)">
        </div>
        <button class="bg-green-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-green-700 flex-shrink-0">Upload All to Slider</button>
    </form>
    <div id="bulkPreview" class="flex gap-2 mt-3 overflow-x-auto pb-2"></div>
    <button type="button" onclick="openMediaLibrary(onBulkMediaSelected, {multiple:true, folder:'banners', uploadFolder:'banners'})" class="mt-2 text-sm text-blue-600 hover:text-blue-700 font-medium">🖼️ Or select from Media Library</button>
</div>

<?php if (isset($_GET['new']) || $edit): ?>
<!-- ═══ Add / Edit Form ═══ -->
<div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
    <div class="flex items-center justify-between mb-5">
        <h4 class="font-semibold text-gray-800 text-lg"><?= $edit ? 'Edit Banner' : 'Add New Banner' ?></h4>
        <a href="<?= adminUrl('pages/banners.php') ?>" class="text-gray-400 hover:text-gray-600 text-xl">✕</a>
    </div>
    <form method="POST" enctype="multipart/form-data" class="space-y-5">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="banner_id" value="<?= bv($edit, 'id', 0) ?>">

        <div class="grid lg:grid-cols-2 gap-6">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Banner Image *</label>
                    <?php if ($edit && !empty($edit['image'])): ?>
                    <div class="mb-3 rounded-lg border overflow-hidden bg-gray-100" style="aspect-ratio:3.2/1;max-height:200px">
                        <img src="<?= imgSrc('banners', $edit['image']) ?>" class="w-full h-full object-cover">
                    </div>
                    <?php endif; ?>
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-5 text-center hover:border-blue-400 transition cursor-pointer" onclick="document.getElementById('bannerImage').click()">
                        <p class="text-gray-400 text-sm">📷 Click to upload (any size — auto-resized)</p>
                    </div>
                    <input type="file" name="image" id="bannerImage" accept="image/*" class="hidden" onchange="previewImg(this,'previewDesktop')">
                    <div id="previewDesktop" class="mt-2"></div>
                    <input type="hidden" name="media_image" id="mediaImage" value="">
                    <button type="button" onclick="openMediaLibrary(onBannerMediaSelected, {multiple:false, folder:'banners', uploadFolder:'banners'})" class="mt-2 text-sm text-blue-600 hover:text-blue-700 font-medium">🖼️ Select from Media Library</button>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Mobile Image (optional)</label>
                    <?php if ($edit && !empty($edit['mobile_image'])): ?>
                    <div class="mb-2"><img src="<?= imgSrc('banners', $edit['mobile_image']) ?>" class="h-20 rounded-lg border object-cover"></div>
                    <?php endif; ?>
                    <input type="file" name="mobile_image" accept="image/*" class="text-sm">
                    <p class="text-xs text-gray-400 mt-1">Separate image for phones</p>
                </div>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                    <input type="text" name="title" value="<?= e(bv($edit,'title')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                        <select name="position" class="w-full px-3 py-2 border rounded-lg text-sm">
                            <option value="hero" <?= bv($edit,'position')==='hero'?'selected':'' ?>>🎠 Hero Slider</option>
                            <option value="sidebar" <?= bv($edit,'position')==='sidebar'?'selected':'' ?>>📌 Sidebar</option>
                            <option value="popup" <?= bv($edit,'position')==='popup'?'selected':'' ?>>💬 Popup</option>
                            <option value="footer" <?= bv($edit,'position')==='footer'?'selected':'' ?>>📐 Footer</option>
                            <option value="category" <?= bv($edit,'position')==='category'?'selected':'' ?>>📂 Category</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
                        <input type="number" name="sort_order" value="<?= bv($edit,'sort_order',0) ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Link URL</label>
                    <input type="url" name="link_url" value="<?= e(bv($edit,'link_url')) ?>" placeholder="https://..." class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>

                <hr>
                <p class="text-sm font-semibold text-gray-600">🎬 Slider Settings</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Speed (ms)</label>
                        <input type="number" name="animation_speed" value="<?= bv($edit,'animation_speed',5000) ?>" step="500" min="1000" max="20000" class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Transition</label>
                        <select name="transition_type" class="w-full px-3 py-2 border rounded-lg text-sm">
                            <option value="fade" <?= bv($edit,'transition_type','fade')==='fade'?'selected':'' ?>>Fade</option>
                            <option value="slide" <?= bv($edit,'transition_type')==='slide'?'selected':'' ?>>Slide</option>
                        </select>
                    </div>
                </div>

                <hr>
                <p class="text-sm font-semibold text-gray-600">📝 Overlay Text (optional)</p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Heading</label>
                    <input type="text" name="overlay_text" value="<?= e(bv($edit,'overlay_text')) ?>" placeholder="Big Sale!" class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subtitle</label>
                    <input type="text" name="overlay_subtitle" value="<?= e(bv($edit,'overlay_subtitle')) ?>" placeholder="Up to 50% off" class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Button Text</label>
                        <input type="text" name="button_text" value="<?= e(bv($edit,'button_text')) ?>" placeholder="Shop Now" class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Button URL</label>
                        <input type="url" name="button_url" value="<?= e(bv($edit,'button_url')) ?>" placeholder="https://..." class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
                </div>

                <label class="flex items-center gap-2 mt-2">
                    <input type="checkbox" name="is_active" value="1" <?= bv($edit,'is_active',1)?'checked':'' ?> class="rounded text-blue-600">
                    <span class="text-sm">Active</span>
                </label>
                <button class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 mt-3">
                    <?= $edit ? '✓ Update Banner' : '+ Upload Banner' ?>
                </button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ═══ Hero Slider Preview ═══ -->
<?php if (!empty($heroBanners)): ?>
<div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
    <div class="px-5 py-3 border-b flex items-center justify-between">
        <h4 class="font-semibold text-gray-800 text-sm">🎠 Slider Preview (<?= count($heroBanners) ?> slides)</h4>
        <div class="flex gap-2 items-center">
            <span class="text-xs text-gray-400" id="slideCounter">1/<?= count($heroBanners) ?></span>
            <?php if (count($heroBanners) > 1): ?>
            <button type="button" onclick="AS.prev()" class="px-2.5 py-1 bg-gray-100 rounded text-xs hover:bg-gray-200">◀</button>
            <button type="button" onclick="AS.next()" class="px-2.5 py-1 bg-gray-100 rounded text-xs hover:bg-gray-200">▶</button>
            <button type="button" onclick="AS.togglePlay()" id="autoplayBtn" class="px-2.5 py-1 bg-green-100 text-green-700 rounded text-xs hover:bg-green-200">⏸</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="relative overflow-hidden bg-gray-200" style="aspect-ratio:3.2/1;max-height:320px">
        <?php foreach ($heroBanners as $i => $hb): ?>
        <div class="admin-slide <?= $i===0?'active':'' ?>">
            <img src="<?= imgSrc('banners', $hb['image']) ?>" class="w-full h-full object-cover object-center" onerror="this.parentElement.innerHTML='<div class=\'flex items-center justify-center h-full text-gray-400\'>Image not found</div>'">
            <?php if (!empty($hb['overlay_text']) || !empty($hb['overlay_subtitle'])): ?>
            <div class="absolute inset-0 bg-gradient-to-r from-black/50 to-transparent flex items-center">
                <div class="pl-8 text-white">
                    <?php if (!empty($hb['overlay_text'])): ?><h2 class="text-2xl font-bold mb-1"><?= e($hb['overlay_text']) ?></h2><?php endif; ?>
                    <?php if (!empty($hb['overlay_subtitle'])): ?><p class="text-base opacity-90"><?= e($hb['overlay_subtitle']) ?></p><?php endif; ?>
                    <?php if (!empty($hb['button_text'])): ?><span class="mt-2 inline-block bg-white text-gray-800 px-4 py-1.5 rounded-lg font-medium text-sm"><?= e($hb['button_text']) ?></span><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (count($heroBanners) > 1): ?>
        <div class="absolute bottom-2 left-1/2 -translate-x-1/2 flex gap-1.5 z-10">
            <?php for($i=0;$i<count($heroBanners);$i++): ?>
            <button type="button" onclick="AS.go(<?=$i?>)" class="adot w-2 h-2 rounded-full transition-all <?=$i===0?'bg-white w-5':'bg-white/50'?>"></button>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══ All Banners Grid ═══ -->
<div class="grid md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
    <?php foreach ($banners as $b): ?>
    <div class="banner-card bg-white rounded-xl shadow-sm border overflow-hidden relative">
        <div class="relative bg-gray-100" style="aspect-ratio:16/9">
            <img src="<?= imgSrc('banners', $b['image']) ?>" class="w-full h-full object-cover object-center" onerror="this.style.display='none'">
            <?php if (!$b['is_active']): ?><div class="absolute inset-0 bg-black/30 flex items-center justify-center"><span class="bg-black/60 text-white px-3 py-1 rounded-full text-xs">Inactive</span></div><?php endif; ?>
            <div class="banner-overlay absolute inset-0 bg-black/40 opacity-0 transition flex items-center justify-center gap-2">
                <a href="?edit=<?=$b['id']?>" class="p-2 bg-white rounded-lg text-blue-600 hover:bg-blue-50 shadow text-sm"><i class="fas fa-edit"></i></a>
                <form method="POST" class="inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="banner_id" value="<?=$b['id']?>">
                    <button class="p-2 bg-white rounded-lg text-red-600 hover:bg-red-50 shadow text-sm"><i class="fas fa-trash"></i></button>
                </form>
                <form method="POST" class="inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="banner_id" value="<?=$b['id']?>">
                    <button class="p-2 bg-white rounded-lg shadow text-sm"><?=$b['is_active']?'🔴 Off':'🟢 On'?></button>
                </form>
            </div>
        </div>
        <div class="p-3">
            <div class="flex items-center justify-between">
                <h5 class="font-medium text-gray-800 text-sm truncate"><?= e($b['title'] ?: 'Untitled') ?></h5>
                <span class="text-xs px-2 py-0.5 rounded-full <?=$b['is_active']?'bg-green-100 text-green-700':'bg-gray-100 text-gray-500'?>"><?=$b['is_active']?'Active':'Off'?></span>
            </div>
            <span class="text-xs text-gray-400"><?= ucfirst($b['position']) ?> · #<?=$b['sort_order']?></span>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($banners)): ?>
    <div class="col-span-full text-center py-12 bg-white rounded-xl border">
        <p class="text-gray-400 text-lg mb-2">🖼️ No banners yet</p>
        <a href="?new=1" class="text-blue-600 text-sm hover:underline">Add your first banner</a>
    </div>
    <?php endif; ?>
</div>

<script>
// ── Admin Slider ──
const AS = (function(){
    const slides=document.querySelectorAll('.admin-slide'), dots=document.querySelectorAll('.adot'),
          ctr=document.getElementById('slideCounter'), total=slides.length;
    let cur=0, playing=true, timer=null;
    function show(n){
        cur=((n%total)+total)%total;
        slides.forEach(function(s,i){ s.classList.toggle('active',i===cur); });
        dots.forEach(function(d,i){ d.className='adot w-2 h-2 rounded-full transition-all '+(i===cur?'bg-white w-5':'bg-white/50'); });
        if(ctr) ctr.textContent=(cur+1)+'/'+total;
    }
    function restart(){ clearInterval(timer); if(playing&&total>1) timer=setInterval(function(){show(cur+1);},5000); }
    if(total>1) restart();
    return {
        next:function(){show(cur+1);restart();}, prev:function(){show(cur-1);restart();}, go:function(n){show(n);restart();},
        togglePlay:function(){
            playing=!playing; var b=document.getElementById('autoplayBtn');
            if(b){b.textContent=playing?'⏸':'▶';b.className='px-2.5 py-1 rounded text-xs '+(playing?'bg-green-100 text-green-700 hover:bg-green-200':'bg-blue-100 text-blue-700 hover:bg-blue-200');}
            playing?restart():clearInterval(timer);
        }
    };
})();

function previewImg(input, targetId) {
    if(input.files&&input.files[0]){
        var r=new FileReader();
        r.onload=function(e){document.getElementById(targetId).innerHTML='<div class="rounded-lg border overflow-hidden bg-gray-100" style="aspect-ratio:3.2/1;max-height:150px"><img src="'+e.target.result+'" class="w-full h-full object-cover"></div><p class="text-xs text-green-600 mt-1">✓ Auto-resized on upload</p>';};
        r.readAsDataURL(input.files[0]);
    }
}

function showBulkPreview(input) {
    var box=document.getElementById('bulkPreview'); box.innerHTML='';
    Array.from(input.files).forEach(function(f){
        var r=new FileReader(); r.onload=function(e){
            box.innerHTML+='<img src="'+e.target.result+'" class="h-16 w-24 rounded border object-cover flex-shrink-0">';
        }; r.readAsDataURL(f);
    });
}

function onBannerMediaSelected(files) {
    if(files.length>0){
        document.getElementById('mediaImage').value=files[0].path;
        document.getElementById('previewDesktop').innerHTML='<div class="rounded-lg border overflow-hidden bg-gray-100" style="aspect-ratio:3.2/1;max-height:150px"><img src="'+files[0].url+'" class="w-full h-full object-cover"></div><p class="text-xs text-green-600 mt-1">✓ From media library</p>';
    }
}

function onBulkMediaSelected(files) {
    // Create individual banners from media library selection
    if(files.length===0) return;
    if(!confirm('Add '+files.length+' image(s) as hero slider banners?')) return;
    var form=new FormData();
    form.append('action','bulk_media');
    files.forEach(function(f,i){form.append('paths[]',f.path);});
    // We need to do this via a hidden form post
    var f=document.createElement('form'); f.method='POST'; f.style.display='none';
    var a=document.createElement('input'); a.name='action'; a.value='bulk_media_add'; f.appendChild(a);
    files.forEach(function(file){
        var inp=document.createElement('input'); inp.name='media_paths[]'; inp.value=file.path; f.appendChild(inp);
    });
    document.body.appendChild(f); f.submit();
}
</script>

<?php include __DIR__ . '/../includes/media-picker.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
