<?php
/**
 * Page Builder — Customize Home Page & Shop Page sections
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Page Builder';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCSRFToken();
    $tab = $_POST['tab'] ?? 'home';
    $skipFields = ['action', 'tab', CSRF_TOKEN_NAME];
    
    $checkboxFields = [
        'home' => [
            'home_show_hero', 'home_show_categories', 'home_show_sale',
            'home_show_featured', 'home_show_all', 'home_show_trust',
        ],
        'shop' => [
            'shop_show_banner', 'shop_show_categories', 'shop_show_sort',
        ],
    ];

    foreach ($_POST as $key => $value) {
        if (in_array($key, $skipFields)) continue;
        $val = is_array($value) ? implode(',', array_map('sanitize', $value)) : sanitize($value);
        $db->query(
            "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) 
             VALUES (?, ?, 'text', 'page_builder') ON DUPLICATE KEY UPDATE setting_value = ?",
            [$key, $val, $val]
        );
    }

    if (isset($checkboxFields[$tab])) {
        foreach ($checkboxFields[$tab] as $field) {
            if (!isset($_POST[$field])) {
                $db->query(
                    "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) 
                     VALUES (?, '0', 'text', 'page_builder') ON DUPLICATE KEY UPDATE setting_value = '0'",
                    [$field, 'page_builder']
                );
            }
        }
    }

    logActivity(getAdminId(), 'update', 'settings', 0, "Updated {$tab} page builder settings");
    redirect(adminUrl("pages/page-builder.php?tab={$tab}&msg=saved"));
}

$s = [];
$allSettings = $db->fetchAll("SELECT setting_key, setting_value FROM site_settings");
foreach ($allSettings as $row) $s[$row['setting_key']] = $row['setting_value'];

function pb($s, $key, $default = '') { return $s[$key] ?? $default; }
function pbChecked($s, $key, $default = '1') { return (($s[$key] ?? $default) === '1') ? 'checked' : ''; }

$tab = $_GET['tab'] ?? 'home';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.section-card { transition: all 0.3s; border: 2px solid transparent; }
.section-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.toggle-sw { position: relative; width: 44px; height: 24px; flex-shrink: 0; display:inline-block; }
.toggle-sw input { display: none; }
.toggle-sw .slider { position: absolute; inset: 0; background: #d1d5db; border-radius: 12px; transition: 0.3s; cursor: pointer; }
.toggle-sw .slider::before { content: ''; position: absolute; width: 18px; height: 18px; border-radius: 50%; background: white; top: 3px; left: 3px; transition: 0.3s; }
.toggle-sw input:checked + .slider { background: #3b82f6; }
.toggle-sw input:checked + .slider::before { transform: translateX(20px); }
.edit-panel { max-height: 0; overflow: hidden; transition: max-height 0.4s ease; }
.edit-panel.open { max-height: 800px; }
.si { width:100%; padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; }
.si:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.1); }
.sl { font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
</style>

<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">🏗️ Page Builder</h1>
            <p class="text-sm text-gray-500">Toggle sections, edit headings, and customize your pages</p>
        </div>
        <?php if (isset($_GET['msg'])): ?>
        <div class="bg-green-50 text-green-700 px-4 py-2 rounded-lg text-sm font-medium animate-pulse">
            <i class="fas fa-check-circle mr-1"></i> Saved!
        </div>
        <?php endif; ?>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-6">
        <a href="?tab=home" class="px-5 py-2.5 rounded-xl text-sm font-medium transition <?= $tab === 'home' ? 'bg-blue-600 text-white shadow-sm' : 'bg-white border text-gray-600 hover:bg-gray-50' ?>">
            <i class="fas fa-home mr-1.5"></i> Home Page
        </a>
        <a href="?tab=shop" class="px-5 py-2.5 rounded-xl text-sm font-medium transition <?= $tab === 'shop' ? 'bg-blue-600 text-white shadow-sm' : 'bg-white border text-gray-600 hover:bg-gray-50' ?>">
            <i class="fas fa-store mr-1.5"></i> Shop Page
        </a>
    </div>

    <?php if ($tab === 'home'): ?>
    <!-- ═══════ HOME PAGE ═══════ -->
    <form method="POST" class="space-y-4">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="tab" value="home">
        <?= csrfField() ?>

        <!-- 1. Hero Slider -->
        <div class="section-card bg-white rounded-xl shadow-sm p-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center"><i class="fas fa-images text-purple-600"></i></div>
                    <div>
                        <h3 class="font-semibold text-gray-800">Hero Slider</h3>
                        <p class="text-xs text-gray-400">Banner slideshow at the top of home page</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="toggleEdit('hero')" class="text-xs text-blue-600 hover:text-blue-700 font-medium"><i class="fas fa-cog mr-1"></i>Edit</button>
                    <label class="toggle-sw"><input type="hidden" name="home_show_hero" value="0"><input type="checkbox" name="home_show_hero" value="1" <?= pbChecked($s, 'home_show_hero') ?>><span class="slider"></span></label>
                </div>
            </div>
            <div class="edit-panel" id="edit-hero">
                <div class="mt-4 pt-4 border-t border-gray-100">
                    <a href="<?= adminUrl('pages/banners.php') ?>" class="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-700 font-medium">
                        <i class="fas fa-external-link-alt"></i> Manage Banners
                    </a>
                </div>
            </div>
        </div>

        <!-- 2. Featured Categories -->
        <div class="section-card bg-white rounded-xl shadow-sm p-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center"><i class="fas fa-th-large text-green-600"></i></div>
                    <div>
                        <h3 class="font-semibold text-gray-800">Featured Categories</h3>
                        <p class="text-xs text-gray-400">Category circles/cards below the banner</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="toggleEdit('cats')" class="text-xs text-blue-600 hover:text-blue-700 font-medium"><i class="fas fa-cog mr-1"></i>Edit</button>
                    <label class="toggle-sw"><input type="hidden" name="home_show_categories" value="0"><input type="checkbox" name="home_show_categories" value="1" <?= pbChecked($s, 'home_show_categories') ?>><span class="slider"></span></label>
                </div>
            </div>
            <div class="edit-panel" id="edit-cats">
                <div class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-2 gap-4">
                    <div>
                        <p class="sl">Max Categories</p>
                        <input type="number" name="home_categories_limit" value="<?= pb($s, 'home_categories_limit', '10') ?>" min="2" max="20" class="si">
                    </div>
                    <div class="flex items-end">
                        <a href="<?= adminUrl('pages/categories.php') ?>" class="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-700 font-medium">
                            <i class="fas fa-external-link-alt"></i> Manage Categories
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Sale Products -->
        <div class="section-card bg-white rounded-xl shadow-sm p-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center"><i class="fas fa-fire text-red-500"></i></div>
                    <div>
                        <h3 class="font-semibold text-gray-800">Sale Products</h3>
                        <p class="text-xs text-gray-400">Products marked as "On Sale"</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="toggleEdit('sale')" class="text-xs text-blue-600 hover:text-blue-700 font-medium"><i class="fas fa-cog mr-1"></i>Edit</button>
                    <label class="toggle-sw"><input type="hidden" name="home_show_sale" value="0"><input type="checkbox" name="home_show_sale" value="1" <?= pbChecked($s, 'home_show_sale') ?>><span class="slider"></span></label>
                </div>
            </div>
            <div class="edit-panel" id="edit-sale">
                <div class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-2 gap-4">
                    <div>
                        <p class="sl">Section Title</p>
                        <input type="text" name="home_sale_title" value="<?= e(pb($s, 'home_sale_title', 'বিশেষ অফার')) ?>" class="si">
                    </div>
                    <div>
                        <p class="sl">Icon Class</p>
                        <input type="text" name="home_sale_icon" value="<?= e(pb($s, 'home_sale_icon', 'fas fa-fire')) ?>" class="si" placeholder="fas fa-fire">
                    </div>
                    <div>
                        <p class="sl">"View All" Link Text</p>
                        <input type="text" name="home_sale_link_text" value="<?= e(pb($s, 'home_sale_link_text', 'সব দেখুন')) ?>" class="si">
                    </div>
                    <div>
                        <p class="sl">"View All" Link URL</p>
                        <input type="text" name="home_sale_link_url" value="<?= e(pb($s, 'home_sale_link_url', '/category/offer-zone')) ?>" class="si">
                    </div>
                    <div>
                        <p class="sl">Max Products</p>
                        <input type="number" name="home_sale_limit" value="<?= pb($s, 'home_sale_limit', '8') ?>" min="2" max="50" class="si">
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Featured Products -->
        <div class="section-card bg-white rounded-xl shadow-sm p-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-yellow-100 flex items-center justify-center"><i class="fas fa-star text-yellow-500"></i></div>
                    <div>
                        <h3 class="font-semibold text-gray-800">Featured Products</h3>
                        <p class="text-xs text-gray-400">Products marked as "Featured"</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="toggleEdit('feat')" class="text-xs text-blue-600 hover:text-blue-700 font-medium"><i class="fas fa-cog mr-1"></i>Edit</button>
                    <label class="toggle-sw"><input type="hidden" name="home_show_featured" value="0"><input type="checkbox" name="home_show_featured" value="1" <?= pbChecked($s, 'home_show_featured') ?>><span class="slider"></span></label>
                </div>
            </div>
            <div class="edit-panel" id="edit-feat">
                <div class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-2 gap-4">
                    <div>
                        <p class="sl">Section Title</p>
                        <input type="text" name="home_featured_title" value="<?= e(pb($s, 'home_featured_title', 'জনপ্রিয় পণ্য')) ?>" class="si">
                    </div>
                    <div>
                        <p class="sl">Icon Class</p>
                        <input type="text" name="home_featured_icon" value="<?= e(pb($s, 'home_featured_icon', 'fas fa-star')) ?>" class="si" placeholder="fas fa-star">
                    </div>
                    <div>
                        <p class="sl">Max Products</p>
                        <input type="number" name="home_featured_limit" value="<?= pb($s, 'home_featured_limit', '12') ?>" min="2" max="50" class="si">
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. All Products -->
        <div class="section-card bg-white rounded-xl shadow-sm p-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center"><i class="fas fa-th text-blue-600"></i></div>
                    <div>
                        <h3 class="font-semibold text-gray-800">All Products</h3>
                        <p class="text-xs text-gray-400">Latest products grid</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="toggleEdit('all')" class="text-xs text-blue-600 hover:text-blue-700 font-medium"><i class="fas fa-cog mr-1"></i>Edit</button>
                    <label class="toggle-sw"><input type="hidden" name="home_show_all" value="0"><input type="checkbox" name="home_show_all" value="1" <?= pbChecked($s, 'home_show_all') ?>><span class="slider"></span></label>
                </div>
            </div>
            <div class="edit-panel" id="edit-all">
                <div class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-2 gap-4">
                    <div>
                        <p class="sl">Section Title</p>
                        <input type="text" name="home_all_title" value="<?= e(pb($s, 'home_all_title', 'সকল পণ্য')) ?>" class="si">
                    </div>
                    <div>
                        <p class="sl">Icon Class</p>
                        <input type="text" name="home_all_icon" value="<?= e(pb($s, 'home_all_icon', 'fas fa-th-large')) ?>" class="si" placeholder="fas fa-th-large">
                    </div>
                    <div>
                        <p class="sl">Max Products</p>
                        <input type="number" name="home_all_limit" value="<?= pb($s, 'home_all_limit', '20') ?>" min="4" max="100" class="si">
                    </div>
                </div>
            </div>
        </div>

        <!-- 6. Trust Badges -->
        <div class="section-card bg-white rounded-xl shadow-sm p-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-teal-100 flex items-center justify-center"><i class="fas fa-shield-alt text-teal-600"></i></div>
                    <div>
                        <h3 class="font-semibold text-gray-800">Trust Badges</h3>
                        <p class="text-xs text-gray-400">Trust/feature icons at the bottom</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="toggleEdit('trust')" class="text-xs text-blue-600 hover:text-blue-700 font-medium"><i class="fas fa-cog mr-1"></i>Edit</button>
                    <label class="toggle-sw"><input type="hidden" name="home_show_trust" value="0"><input type="checkbox" name="home_show_trust" value="1" <?= pbChecked($s, 'home_show_trust') ?>><span class="slider"></span></label>
                </div>
            </div>
            <div class="edit-panel" id="edit-trust">
                <div class="mt-4 pt-4 border-t border-gray-100 space-y-4">
                    <?php
                    $trustDefaults = [
                        1 => ['fas fa-leaf', '১০০% খাঁটি', 'সরাসরি উৎপাদক থেকে'],
                        2 => ['fas fa-truck', 'দ্রুত ডেলিভারি', 'সারা বাংলাদেশে'],
                        3 => ['fas fa-money-bill-wave', 'ক্যাশ অন ডেলিভারি', 'পণ্য হাতে পেয়ে পেমেন্ট'],
                        4 => ['fas fa-headset', '২৪/৭ সাপোর্ট', 'যেকোনো সময় কল করুন'],
                    ];
                    for ($i = 1; $i <= 4; $i++):
                        $def = $trustDefaults[$i];
                    ?>
                    <div class="grid grid-cols-3 gap-3 p-3 bg-gray-50 rounded-lg">
                        <div>
                            <p class="sl">Badge <?= $i ?> Icon</p>
                            <input type="text" name="home_trust_<?= $i ?>_icon" value="<?= e(pb($s, "home_trust_{$i}_icon", $def[0])) ?>" class="si" placeholder="fas fa-icon">
                        </div>
                        <div>
                            <p class="sl">Title</p>
                            <input type="text" name="home_trust_<?= $i ?>_title" value="<?= e(pb($s, "home_trust_{$i}_title", $def[1])) ?>" class="si">
                        </div>
                        <div>
                            <p class="sl">Subtitle</p>
                            <input type="text" name="home_trust_<?= $i ?>_subtitle" value="<?= e(pb($s, "home_trust_{$i}_subtitle", $def[2])) ?>" class="si">
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <div class="flex justify-end pt-2">
            <button type="submit" class="px-8 py-3 bg-blue-600 text-white rounded-xl font-semibold hover:bg-blue-700 transition shadow-lg shadow-blue-200 flex items-center gap-2">
                <i class="fas fa-save"></i> Save Home Page Settings
            </button>
        </div>
    </form>

    <?php elseif ($tab === 'shop'): ?>
    <!-- ═══════ SHOP PAGE ═══════ -->
    <form method="POST" class="space-y-4">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="tab" value="shop">
        <?= csrfField() ?>

        <div class="bg-white rounded-xl shadow-sm p-6 space-y-5">
            <h3 class="font-semibold text-gray-800 text-lg"><i class="fas fa-store text-blue-500 mr-2"></i>Shop / Category Page</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <p class="sl">Page Title</p>
                    <input type="text" name="shop_page_title" value="<?= e(pb($s, 'shop_page_title', 'সকল পণ্য')) ?>" class="si">
                </div>
                <div>
                    <p class="sl">Products Per Page</p>
                    <input type="number" name="shop_products_per_page" value="<?= pb($s, 'shop_products_per_page', '20') ?>" min="4" max="100" class="si">
                </div>
                <div>
                    <p class="sl">Grid Columns (Desktop)</p>
                    <select name="shop_grid_cols" class="si">
                        <?php foreach ([3,4,5,6] as $c): ?>
                        <option value="<?= $c ?>" <?= pb($s, 'shop_grid_cols', '4') == $c ? 'selected' : '' ?>><?= $c ?> columns</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pt-4 border-t border-gray-100 space-y-3">
                <h4 class="text-sm font-semibold text-gray-700">Toggles</h4>
                <div class="flex items-center justify-between py-2">
                    <span class="text-sm text-gray-700">Show Sort Dropdown</span>
                    <label class="toggle-sw"><input type="hidden" name="shop_show_sort" value="0"><input type="checkbox" name="shop_show_sort" value="1" <?= pbChecked($s, 'shop_show_sort') ?>><span class="slider"></span></label>
                </div>
                <div class="flex items-center justify-between py-2">
                    <span class="text-sm text-gray-700">Show Category Banner</span>
                    <label class="toggle-sw"><input type="hidden" name="shop_show_banner" value="0"><input type="checkbox" name="shop_show_banner" value="1" <?= pbChecked($s, 'shop_show_banner') ?>><span class="slider"></span></label>
                </div>
                <div class="flex items-center justify-between py-2">
                    <span class="text-sm text-gray-700">Show Sub-Categories</span>
                    <label class="toggle-sw"><input type="hidden" name="shop_show_categories" value="0"><input type="checkbox" name="shop_show_categories" value="1" <?= pbChecked($s, 'shop_show_categories') ?>><span class="slider"></span></label>
                </div>
            </div>

            <div class="pt-4 border-t border-gray-100">
                <p class="text-xs text-gray-400">For card-level customization (buttons, badges, style), go to 
                    <a href="<?= adminUrl('pages/shop-design.php') ?>" class="text-blue-600 hover:underline font-medium">Shop Design</a>
                </p>
            </div>
        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" class="px-8 py-3 bg-blue-600 text-white rounded-xl font-semibold hover:bg-blue-700 transition shadow-lg shadow-blue-200 flex items-center gap-2">
                <i class="fas fa-save"></i> Save Shop Page Settings
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function toggleEdit(id) {
    const el = document.getElementById('edit-' + id);
    if (!el) return;
    el.classList.toggle('open');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
