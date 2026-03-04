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
        'transition_type' => "VARCHAR(20) DEFAULT 'slide'",
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

// ── Helper: resize banner image keeping aspect ratio ──
function resizeBannerImage($filePath, $maxW = 1920, $maxH = 720) {
    if (!file_exists($filePath)) return;
    $info = @getimagesize($filePath);
    if (!$info) return;

    $origW = $info[0];
    $origH = $info[1];
    $mime  = $info['mime'];

    if ($origW <= $maxW && $origH <= $maxH) return;

    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($filePath); break;
        case 'image/png':  $src = @imagecreatefrompng($filePath); break;
        case 'image/webp': $src = @imagecreatefromwebp($filePath); break;
        case 'image/gif':  $src = @imagecreatefromgif($filePath); break;
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
        case 'image/gif':  imagegif($dst, $filePath); break;
    }

    imagedestroy($src);
    imagedestroy($dst);
}

// ── Handle POST actions ──
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
            'transition_type'  => sanitize($_POST['transition_type'] ?? 'slide'),
            'overlay_text'     => sanitize($_POST['overlay_text'] ?? ''),
            'overlay_subtitle' => sanitize($_POST['overlay_subtitle'] ?? ''),
            'button_text'      => sanitize($_POST['button_text'] ?? ''),
            'button_url'       => sanitize($_POST['button_url'] ?? ''),
        ];

        if (!empty($_FILES['image']['name'])) {
            $upload = uploadFile($_FILES['image'], 'banners');
            if ($upload) {
                resizeBannerImage(UPLOAD_PATH . $upload, 1920, 720);
                $data['image'] = $upload;
            }
        } elseif (!empty($_POST['media_image'])) {
            $data['image'] = sanitize($_POST['media_image']);
        }

        if (!empty($_FILES['mobile_image']['name'])) {
            $upload = uploadFile($_FILES['mobile_image'], 'banners');
            if ($upload) {
                resizeBannerImage(UPLOAD_PATH . $upload, 800, 800);
                $data['mobile_image'] = $upload;
            }
        }

        if ($bid) {
            $db->update('banners', $data, 'id = ?', [$bid]);
        } else {
            if (empty($data['image'])) {
                redirect(adminUrl('pages/banners.php?msg=no_image'));
            }
            $db->insert('banners', $data);
        }
        redirect(adminUrl('pages/banners.php?msg=saved'));
    }

    if ($action === 'delete') {
        $bid = intval($_POST['banner_id']);
        $banner = $db->fetch("SELECT * FROM banners WHERE id = ?", [$bid]);
        if ($banner) {
            if (!empty($banner['image'])) {
                $path = UPLOAD_PATH . 'banners/' . $banner['image'];
                if (file_exists($path)) @unlink($path);
            }
            $db->delete('banners', 'id = ?', [$bid]);
        }
        redirect(adminUrl('pages/banners.php?msg=deleted'));
    }

    if ($action === 'toggle') {
        $bid = intval($_POST['banner_id']);
        $banner = $db->fetch("SELECT is_active FROM banners WHERE id = ?", [$bid]);
        if ($banner) {
            $db->update('banners', ['is_active' => $banner['is_active'] ? 0 : 1], 'id = ?', [$bid]);
        }
        redirect(adminUrl('pages/banners.php?msg=toggled'));
    }

    if ($action === 'reorder') {
        $orders = $_POST['banner_order'] ?? [];
        foreach ($orders as $i => $bid) {
            $db->update('banners', ['sort_order' => $i], 'id = ?', [intval($bid)]);
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

$banners = $db->fetchAll("SELECT * FROM banners ORDER BY position, sort_order, id");
$edit = isset($_GET['edit']) ? $db->fetch("SELECT * FROM banners WHERE id = ?", [intval($_GET['edit'])]) : null;

// Safe getter
function bv($edit, $key, $default = '') { return $edit[$key] ?? $default; }

$heroBanners = array_values(array_filter($banners, fn($b) => $b['position'] === 'hero'));

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.banner-card:hover .banner-overlay { opacity: 1; }
.admin-slide { position:absolute; inset:0; opacity:0; transition:opacity 0.6s ease; pointer-events:none; }
.admin-slide.active { opacity:1; pointer-events:auto; z-index:1; }
</style>

<?php if (isset($_GET['msg'])): ?>
<div class="<?= $_GET['msg']==='no_image' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?> border px-4 py-3 rounded-lg mb-4 text-sm">
    <?= $_GET['msg'] === 'no_image' ? 'Please upload an image.' : 'Action completed.' ?>
</div>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h3 class="text-xl font-bold text-gray-800">🖼️ Banner Management</h3>
        <p class="text-sm text-gray-500 mt-1"><?= count($banners) ?> banners · <?= count($heroBanners) ?> in slider</p>
    </div>
    <a href="?new=1" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">+ Add Banner</a>
</div>

<?php if (isset($_GET['new']) || $edit): ?>
<!-- Add/Edit Form -->
<div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
    <div class="flex items-center justify-between mb-5">
        <h4 class="font-semibold text-gray-800 text-lg"><?= $edit ? 'Edit Banner' : 'Add New Banner' ?></h4>
        <a href="<?= adminUrl('pages/banners.php') ?>" class="text-gray-400 hover:text-gray-600">✕</a>
    </div>
    <form method="POST" enctype="multipart/form-data" class="space-y-5">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="banner_id" value="<?= bv($edit, 'id', 0) ?>">

        <div class="grid lg:grid-cols-2 gap-6">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Banner Image *</label>
                    <?php if ($edit && !empty($edit['image'])): ?>
                    <div class="mb-3 rounded-lg border overflow-hidden bg-gray-100" style="aspect-ratio:16/5">
                        <img src="<?= uploadUrl('banners/' . $edit['image']) ?>" class="w-full h-full object-cover" onerror="this.style.display='none'">
                    </div>
                    <?php endif; ?>
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-blue-400 transition cursor-pointer" onclick="document.getElementById('bannerImage').click()">
                        <p class="text-gray-400">📷 Click to upload</p>
                        <p class="text-xs text-gray-300 mt-1">Any size — auto-resized to fit (max 1920×720)</p>
                    </div>
                    <input type="file" name="image" id="bannerImage" accept="image/*" class="hidden" onchange="previewImg(this,'previewDesktop')">
                    <div id="previewDesktop" class="mt-2"></div>
                    <input type="hidden" name="media_image" id="mediaImage" value="">
                    <button type="button" onclick="openMediaLibrary(onBannerMediaSelected,{multiple:false,folder:'banners',uploadFolder:'banners'})" class="mt-2 text-sm text-blue-600 hover:text-blue-700 font-medium">🖼️ Select from Media Library</button>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Mobile Image (optional)</label>
                    <?php if ($edit && !empty($edit['mobile_image'])): ?>
                    <div class="mb-2"><img src="<?= uploadUrl('banners/' . $edit['mobile_image']) ?>" class="h-24 rounded-lg border object-cover"></div>
                    <?php endif; ?>
                    <input type="file" name="mobile_image" accept="image/*" class="text-sm">
                    <p class="text-xs text-gray-400 mt-1">Square image for mobile (auto-resized max 800×800)</p>
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

                <hr class="my-2">
                <p class="text-sm font-semibold text-gray-600">🎬 Slider Animation</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Slide Speed (ms)</label>
                        <input type="number" name="animation_speed" value="<?= bv($edit,'animation_speed',5000) ?>" step="500" min="1000" max="20000" class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Transition</label>
                        <select name="transition_type" class="w-full px-3 py-2 border rounded-lg text-sm">
                            <option value="slide" <?= bv($edit,'transition_type')==='slide'?'selected':'' ?>>Slide</option>
                            <option value="fade" <?= bv($edit,'transition_type')==='fade'?'selected':'' ?>>Fade</option>
                            <option value="zoom" <?= bv($edit,'transition_type')==='zoom'?'selected':'' ?>>Zoom</option>
                        </select>
                    </div>
                </div>

                <hr class="my-2">
                <p class="text-sm font-semibold text-gray-600">📝 Overlay Content (optional)</p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Heading Text</label>
                    <input type="text" name="overlay_text" value="<?= e(bv($edit,'overlay_text')) ?>" placeholder="Big Sale!" class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subtitle</label>
                    <input type="text" name="overlay_subtitle" value="<?= e(bv($edit,'overlay_subtitle')) ?>" placeholder="Up to 50% off..." class="w-full px-3 py-2 border rounded-lg text-sm">
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

<!-- Hero Slider Preview -->
<?php if (!empty($heroBanners)): ?>
<div class="bg-white rounded-xl shadow-sm border overflow-hidden mb-6">
    <div class="px-5 py-4 border-b flex items-center justify-between">
        <h4 class="font-semibold text-gray-800">🎠 Hero Slider Preview (<?= count($heroBanners) ?> slides)</h4>
        <div class="flex gap-2 items-center">
            <span class="text-xs text-gray-400 mr-2" id="slideCounter">1 / <?= count($heroBanners) ?></span>
            <button type="button" onclick="adminSlider.prev()" class="px-3 py-1 bg-gray-100 rounded-lg text-sm hover:bg-gray-200">◀</button>
            <button type="button" onclick="adminSlider.next()" class="px-3 py-1 bg-gray-100 rounded-lg text-sm hover:bg-gray-200">▶</button>
            <button type="button" onclick="adminSlider.togglePlay()" id="autoplayBtn" class="px-3 py-1 bg-green-100 text-green-700 rounded-lg text-sm hover:bg-green-200">⏸ Pause</button>
        </div>
    </div>
    <div class="relative overflow-hidden bg-gray-100" style="aspect-ratio:16/5;max-height:350px;" id="sliderPreview">
        <?php foreach ($heroBanners as $i => $hb): ?>
        <div class="admin-slide <?= $i===0?'active':'' ?>">
            <img src="<?= uploadUrl('banners/'.$hb['image']) ?>" class="w-full h-full object-cover object-center" onerror="this.parentElement.style.display='none'">
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
        <?php if (count($heroBanners)>1): ?>
        <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-2 z-10">
            <?php for($i=0;$i<count($heroBanners);$i++): ?>
            <button type="button" onclick="adminSlider.go(<?=$i?>)" class="admin-dot w-2.5 h-2.5 rounded-full transition-all <?=$i===0?'bg-white w-6':'bg-white/50'?>"></button>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- All Banners Grid -->
<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($banners as $b): ?>
    <div class="banner-card bg-white rounded-xl shadow-sm border overflow-hidden relative">
        <div class="relative bg-gray-100" style="aspect-ratio:16/9">
            <img src="<?= uploadUrl('banners/'.$b['image']) ?>" class="w-full h-full object-cover object-center" onerror="this.style.display='none'">
            <?php if (!$b['is_active']): ?><div class="absolute inset-0 bg-black/30 flex items-center justify-center"><span class="bg-black/60 text-white px-3 py-1 rounded-full text-sm">Inactive</span></div><?php endif; ?>
            <div class="banner-overlay absolute inset-0 bg-black/40 opacity-0 transition flex items-center justify-center gap-2">
                <a href="?edit=<?=$b['id']?>" class="p-2 bg-white rounded-lg text-blue-600 hover:bg-blue-50 shadow"><i class="fas fa-edit"></i></a>
                <form method="POST" class="inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="banner_id" value="<?=$b['id']?>">
                    <button class="p-2 bg-white rounded-lg text-red-600 hover:bg-red-50 shadow"><i class="fas fa-trash"></i></button>
                </form>
                <form method="POST" class="inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="banner_id" value="<?=$b['id']?>">
                    <button class="p-2 bg-white rounded-lg text-gray-600 hover:bg-gray-50 shadow"><?=$b['is_active']?'🔴':'🟢'?></button>
                </form>
            </div>
        </div>
        <div class="p-3">
            <div class="flex items-center justify-between">
                <h5 class="font-medium text-gray-800 text-sm truncate"><?= e($b['title'] ?: 'Untitled') ?></h5>
                <span class="text-xs px-2 py-0.5 rounded-full <?=$b['is_active']?'bg-green-100 text-green-700':'bg-gray-100 text-gray-500'?>"><?=$b['is_active']?'Active':'Off'?></span>
            </div>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-xs text-gray-400"><?= ucfirst($b['position']) ?></span>
                <span class="text-xs text-gray-300">·</span>
                <span class="text-xs text-gray-400">Order: <?=$b['sort_order']?></span>
            </div>
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
// Admin Slider — fade based, no Tailwind class conflicts
const adminSlider = (function(){
    const slides = document.querySelectorAll('.admin-slide');
    const dots   = document.querySelectorAll('.admin-dot');
    const counter = document.getElementById('slideCounter');
    const total  = slides.length;
    let current  = 0, playing = true, timer = null;

    function show(n) {
        current = ((n % total) + total) % total;
        slides.forEach(function(s, i) {
            if (i === current) s.classList.add('active');
            else s.classList.remove('active');
        });
        dots.forEach(function(d, i) {
            d.className = 'admin-dot w-2.5 h-2.5 rounded-full transition-all ' + (i === current ? 'bg-white w-6' : 'bg-white/50');
        });
        if (counter) counter.textContent = (current + 1) + ' / ' + total;
    }

    function restart() {
        clearInterval(timer);
        if (playing && total > 1) timer = setInterval(function(){ show(current + 1); }, 5000);
    }

    if (total > 1) restart();

    return {
        next: function(){ show(current + 1); restart(); },
        prev: function(){ show(current - 1); restart(); },
        go: function(n){ show(n); restart(); },
        togglePlay: function(){
            playing = !playing;
            var btn = document.getElementById('autoplayBtn');
            if (btn) {
                if (playing) {
                    btn.textContent = '⏸ Pause';
                    btn.className = 'px-3 py-1 bg-green-100 text-green-700 rounded-lg text-sm hover:bg-green-200';
                    restart();
                } else {
                    btn.textContent = '▶ Play';
                    btn.className = 'px-3 py-1 bg-blue-100 text-blue-700 rounded-lg text-sm hover:bg-blue-200';
                    clearInterval(timer);
                }
            }
        }
    };
})();

function previewImg(input, targetId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById(targetId).innerHTML =
                '<div class="rounded-lg border overflow-hidden bg-gray-100" style="aspect-ratio:16/5"><img src="'+e.target.result+'" class="w-full h-full object-cover"></div>' +
                '<p class="text-xs text-green-600 mt-1">✓ Will be auto-resized on upload</p>';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function onBannerMediaSelected(files) {
    if (files.length > 0) {
        document.getElementById('mediaImage').value = files[0].path;
        document.getElementById('previewDesktop').innerHTML =
            '<div class="rounded-lg border overflow-hidden bg-gray-100" style="aspect-ratio:16/5"><img src="'+files[0].url+'" class="w-full h-full object-cover"></div>' +
            '<p class="text-xs text-green-600 mt-1">✓ Selected from media library</p>';
    }
}
</script>

<?php include __DIR__ . '/../includes/media-picker.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
