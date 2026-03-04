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
    
    foreach ($_POST as $key => $value) {
        if (in_array($key, $skipFields)) continue;
        $val = is_array($value) ? implode(',', $value) : sanitize($value);
        $db->query(
            "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) 
             VALUES (?, ?, 'text', 'page_builder') ON DUPLICATE KEY UPDATE setting_value = ?",
            [$key, $val, $val]
        );
    }
    
    // Handle unchecked toggles
    $toggleFields = [];
    if ($tab === 'home') {
        $toggleFields = ['home_show_hero','home_show_categories','home_show_sale','home_show_featured','home_show_all','home_show_trust'];
    } elseif ($tab === 'shop') {
        $toggleFields = ['shop_show_banner','shop_show_categories','shop_show_sort'];
    } elseif ($tab === 'menu') {
        // Save menu items as JSON
        $menuItems = [];
        $labels = $_POST['menu_label'] ?? [];
        $urls = $_POST['menu_url'] ?? [];
        $icons = $_POST['menu_icon'] ?? [];
        $actives = $_POST['menu_active'] ?? [];
        $styles = $_POST['menu_style'] ?? [];
        for ($i = 0; $i < count($labels); $i++) {
            if (empty(trim($labels[$i]))) continue;
            $menuItems[] = [
                'label' => trim($labels[$i]),
                'url' => trim($urls[$i] ?? '/'),
                'icon' => trim($icons[$i] ?? ''),
                'active' => in_array((string)$i, $actives) ? 1 : 0,
                'style' => $styles[$i] ?? 'link',
            ];
        }
        $db->query(
            "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) 
             VALUES ('nav_menu_items', ?, 'text', 'page_builder') ON DUPLICATE KEY UPDATE setting_value = ?",
            [json_encode($menuItems, JSON_UNESCAPED_UNICODE), json_encode($menuItems, JSON_UNESCAPED_UNICODE)]
        );
        $toggleFields = ['nav_show_categories','nav_show_shop_link'];
    }
    foreach ($toggleFields as $field) {
        if (!isset($_POST[$field])) {
            $db->query(
                "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) 
                 VALUES (?, '0', 'text', 'page_builder') ON DUPLICATE KEY UPDATE setting_value = '0'",
                [$field]
            );
        }
    }
    
    logActivity(getAdminId(), 'update', 'settings', 0, "Updated page builder ({$tab})");
    redirect(adminUrl("pages/page-builder.php?tab={$tab}&msg=saved"));
}

// Load all settings
$s = [];
$allSettings = $db->fetchAll("SELECT setting_key, setting_value FROM site_settings");
foreach ($allSettings as $row) $s[$row['setting_key']] = $row['setting_value'];

// Defaults
function pb($s, $key, $default = '') { return $s[$key] ?? $default; }
function pbChecked($s, $key, $default = '1') { return (($s[$key] ?? $default) === '1') ? 'checked' : ''; }

$tab = $_GET['tab'] ?? 'home';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.section-card { transition: all 0.3s; border: 2px solid transparent; }
.section-card.active { border-color: #3b82f6; background: #eff6ff; }
.section-card.disabled-card { opacity: 0.5; }
.section-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.drag-handle { cursor: grab; }
.drag-handle:active { cursor: grabbing; }
.section-card .toggle-switch { position: relative; width: 44px; height: 24px; }
.section-card .toggle-switch input { display: none; }
.section-card .toggle-switch .slider { position: absolute; inset: 0; background: #d1d5db; border-radius: 12px; transition: 0.3s; cursor: pointer; }
.section-card .toggle-switch .slider::before { content: ''; position: absolute; width: 18px; height: 18px; border-radius: 50%; background: white; top: 3px; left: 3px; transition: 0.3s; }
.section-card .toggle-switch input:checked + .slider { background: #3b82f6; }
.section-card .toggle-switch input:checked + .slider::before { transform: translateX(20px); }
.preview-strip { background: repeating-linear-gradient(45deg, #f3f4f6, #f3f4f6 10px, #ffffff 10px, #ffffff 20px); }
.edit-panel { max-height: 0; overflow: hidden; transition: max-height 0.4s ease; }
.edit-panel.open { max-height: 600px; }
</style>

<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">🏗️ Page Builder</h1>
            <p class="text-sm text-gray-500">Toggle sections, edit headings, and customize your pages</p>
        </div>
        <?php if (isset($_GET['msg'])): ?>
        <div class="bg-green-50 text-green-700 px-4 py-2 rounded-lg text-sm font-medium">
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
            <i class="fas fa-store mr-1.5"></i> Shop / Category
        </a>
        <a href="?tab=menu" class="px-5 py-2.5 rounded-xl text-sm font-medium transition <?= $tab === 'menu' ? 'bg-blue-600 text-white shadow-sm' : 'bg-white border text-gray-600 hover:bg-gray-50' ?>">
            <i class="fas fa-bars mr-1.5"></i> Main Menu
        </a>
    </div>

    <?php if ($tab === 'home'): ?>
    <!-- ════════════════════════════════════════ -->
    <!--  HOME PAGE BUILDER                       -->
    <!-- ════════════════════════════════════════ -->
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="tab" value="home">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCSRFToken() ?>">
        
        <div class="space-y-3" id="home-sections">
            
            <!-- 1. Hero Slider -->
            <div class="section-card bg-white rounded-xl shadow-sm p-4 <?= pbChecked($s, 'home_show_hero') ? 'active' : 'disabled-card' ?>">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="drag-handle text-gray-300 text-lg"><i class="fas fa-grip-vertical"></i></span>
                        <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                            <i class="fas fa-images text-purple-600"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-800">Hero Slider / Banner</h3>
                            <p class="text-xs text-gray-400">Main banner carousel at the top</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="<?= adminUrl('pages/banners.php') ?>" class="text-xs text-blue-500 hover:text-blue-700">Manage Banners →</a>
                        <label class="toggle-switch">
                            <input type="checkbox" name="home_show_hero" value="1" <?= pbChecked($s, 'home_show_hero') ?> onchange="toggleSection(this)">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- 2. Featured Categories -->
            <div class="section-card bg-white rounded-xl shadow-sm p-4 <?= pbChecked($s, 'home_show_categories') ? 'active' : 'disabled-card' ?>">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="drag-handle text-gray-300 text-lg"><i class="fas fa-grip-vertical"></i></span>
                        <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
                            <i class="fas fa-tags text-green-600"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-800">Featured Categories</h3>
                            <p class="text-xs text-gray-400">Scrollable category circles</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" onclick="toggleEdit(this)" class="text-xs text-gray-400 hover:text-blue-600"><i class="fas fa-pen"></i> Edit</button>
                        <label class="toggle-switch">
                            <input type="checkbox" name="home_show_categories" value="1" <?= pbChecked($s, 'home_show_categories') ?> onchange="toggleSection(this)">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="edit-panel mt-3">
                    <div class="bg-gray-50 rounded-lg p-4 space-y-3 border">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Max Categories to Show</label>
                            <input type="number" name="home_categories_limit" value="<?= pb($s, 'home_categories_limit', '10') ?>" min="3" max="20" class="w-24 px-3 py-2 border rounded-lg text-sm">
                        </div>
                        <p class="text-xs text-gray-400">Mark categories as "Featured" in the Categories page to show them here.</p>
                    </div>
                </div>
            </div>

            <!-- 3. Sale Products -->
            <div class="section-card bg-white rounded-xl shadow-sm p-4 <?= pbChecked($s, 'home_show_sale') ? 'active' : 'disabled-card' ?>">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="drag-handle text-gray-300 text-lg"><i class="fas fa-grip-vertical"></i></span>
                        <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center">
                            <i class="fas fa-fire text-red-500"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-800">Sale / Offer Products</h3>
                            <p class="text-xs text-gray-400">Products with active sale prices</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" onclick="toggleEdit(this)" class="text-xs text-gray-400 hover:text-blue-600"><i class="fas fa-pen"></i> Edit</button>
                        <label class="toggle-switch">
                            <input type="checkbox" name="home_show_sale" value="1" <?= pbChecked($s, 'home_show_sale') ?> onchange="toggleSection(this)">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="edit-panel mt-3">
                    <div class="bg-gray-50 rounded-lg p-4 space-y-3 border">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Section Title</label>
                                <input type="text" name="home_sale_title" value="<?= e(pb($s, 'home_sale_title', 'বিশেষ অফার')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Icon Class</label>
                                <input type="text" name="home_sale_icon" value="<?= e(pb($s, 'home_sale_icon', 'fas fa-fire')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="fas fa-fire">
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">"View All" Text</label>
                                <input type="text" name="home_sale_link_text" value="<?= e(pb($s, 'home_sale_link_text', 'সব দেখুন')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">"View All" URL</label>
                                <input type="text" name="home_sale_link_url" value="<?= e(pb($s, 'home_sale_link_url', '/category/offer-zone')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Max Products</label>
                                <input type="number" name="home_sale_limit" value="<?= pb($s, 'home_sale_limit', '8') ?>" min="2" max="24" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Featured Products -->
            <div class="section-card bg-white rounded-xl shadow-sm p-4 <?= pbChecked($s, 'home_show_featured') ? 'active' : 'disabled-card' ?>">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="drag-handle text-gray-300 text-lg"><i class="fas fa-grip-vertical"></i></span>
                        <div class="w-10 h-10 rounded-lg bg-yellow-100 flex items-center justify-center">
                            <i class="fas fa-star text-yellow-500"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-800">Featured / Popular Products</h3>
                            <p class="text-xs text-gray-400">Products marked as featured</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" onclick="toggleEdit(this)" class="text-xs text-gray-400 hover:text-blue-600"><i class="fas fa-pen"></i> Edit</button>
                        <label class="toggle-switch">
                            <input type="checkbox" name="home_show_featured" value="1" <?= pbChecked($s, 'home_show_featured') ?> onchange="toggleSection(this)">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="edit-panel mt-3">
                    <div class="bg-gray-50 rounded-lg p-4 space-y-3 border">
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Section Title</label>
                                <input type="text" name="home_featured_title" value="<?= e(pb($s, 'home_featured_title', 'জনপ্রিয় পণ্য')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Icon Class</label>
                                <input type="text" name="home_featured_icon" value="<?= e(pb($s, 'home_featured_icon', 'fas fa-star')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Max Products</label>
                                <input type="number" name="home_featured_limit" value="<?= pb($s, 'home_featured_limit', '12') ?>" min="2" max="24" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. All Products -->
            <div class="section-card bg-white rounded-xl shadow-sm p-4 <?= pbChecked($s, 'home_show_all') ? 'active' : 'disabled-card' ?>">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="drag-handle text-gray-300 text-lg"><i class="fas fa-grip-vertical"></i></span>
                        <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-th-large text-blue-500"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-800">All Products</h3>
                            <p class="text-xs text-gray-400">General product listing grid</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" onclick="toggleEdit(this)" class="text-xs text-gray-400 hover:text-blue-600"><i class="fas fa-pen"></i> Edit</button>
                        <label class="toggle-switch">
                            <input type="checkbox" name="home_show_all" value="1" <?= pbChecked($s, 'home_show_all') ?> onchange="toggleSection(this)">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="edit-panel mt-3">
                    <div class="bg-gray-50 rounded-lg p-4 space-y-3 border">
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Section Title</label>
                                <input type="text" name="home_all_title" value="<?= e(pb($s, 'home_all_title', 'সকল পণ্য')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Icon Class</label>
                                <input type="text" name="home_all_icon" value="<?= e(pb($s, 'home_all_icon', 'fas fa-th-large')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Max Products</label>
                                <input type="number" name="home_all_limit" value="<?= pb($s, 'home_all_limit', '20') ?>" min="4" max="50" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 6. Trust Badges -->
            <div class="section-card bg-white rounded-xl shadow-sm p-4 <?= pbChecked($s, 'home_show_trust') ? 'active' : 'disabled-card' ?>">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="drag-handle text-gray-300 text-lg"><i class="fas fa-grip-vertical"></i></span>
                        <div class="w-10 h-10 rounded-lg bg-teal-100 flex items-center justify-center">
                            <i class="fas fa-shield-alt text-teal-600"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-800">Trust Badges / Why Choose Us</h3>
                            <p class="text-xs text-gray-400">4 trust badges at bottom</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="button" onclick="toggleEdit(this)" class="text-xs text-gray-400 hover:text-blue-600"><i class="fas fa-pen"></i> Edit</button>
                        <label class="toggle-switch">
                            <input type="checkbox" name="home_show_trust" value="1" <?= pbChecked($s, 'home_show_trust') ?> onchange="toggleSection(this)">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="edit-panel mt-3">
                    <div class="bg-gray-50 rounded-lg p-4 border">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                            <div class="bg-white rounded-lg p-3 border space-y-2">
                                <p class="text-xs font-bold text-gray-500">Badge <?= $i ?></p>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-0.5">Icon</label>
                                    <input type="text" name="home_trust_<?= $i ?>_icon" 
                                           value="<?= e(pb($s, "home_trust_{$i}_icon", ['','fas fa-check-circle','fas fa-truck','fas fa-money-bill-wave','fas fa-headset'][$i])) ?>" 
                                           class="w-full px-2 py-1.5 border rounded text-xs" placeholder="fas fa-check-circle">
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-0.5">Title</label>
                                    <input type="text" name="home_trust_<?= $i ?>_title" 
                                           value="<?= e(pb($s, "home_trust_{$i}_title", '')) ?>" 
                                           class="w-full px-2 py-1.5 border rounded text-xs">
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-0.5">Subtitle</label>
                                    <input type="text" name="home_trust_<?= $i ?>_subtitle" 
                                           value="<?= e(pb($s, "home_trust_{$i}_subtitle", '')) ?>" 
                                           class="w-full px-2 py-1.5 border rounded text-xs">
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 flex items-center justify-between">
            <a href="<?= url() ?>" target="_blank" class="text-sm text-blue-600 hover:text-blue-800">
                <i class="fas fa-external-link-alt mr-1"></i> Preview Home Page
            </a>
            <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-medium hover:bg-blue-700 shadow-sm">
                <i class="fas fa-save mr-1.5"></i> Save Changes
            </button>
        </div>
    </form>

    <?php elseif ($tab === 'shop'): ?>
    <!-- ════════════════════════════════════════ -->
    <!--  SHOP / CATEGORY PAGE                    -->
    <!-- ════════════════════════════════════════ -->
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="tab" value="shop">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCSRFToken() ?>">
        
        <div class="space-y-4">
            <!-- General -->
            <div class="bg-white rounded-xl shadow-sm p-5 border">
                <h3 class="font-semibold text-gray-800 mb-4"><i class="fas fa-cog mr-1.5 text-gray-400"></i> General Settings</h3>
                <div class="grid sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Default Page Title</label>
                        <input type="text" name="shop_page_title" value="<?= e(pb($s, 'shop_page_title', 'সকল পণ্য')) ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                        <p class="text-xs text-gray-400 mt-0.5">Shown when no category is selected</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Products Per Page</label>
                        <input type="number" name="shop_products_per_page" value="<?= pb($s, 'shop_products_per_page', '20') ?>" min="4" max="60" class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Grid Columns (Desktop)</label>
                        <select name="shop_grid_cols" class="w-full px-3 py-2 border rounded-lg text-sm">
                            <option value="3" <?= pb($s, 'shop_grid_cols', '4') === '3' ? 'selected' : '' ?>>3 Columns</option>
                            <option value="4" <?= pb($s, 'shop_grid_cols', '4') === '4' ? 'selected' : '' ?>>4 Columns</option>
                            <option value="5" <?= pb($s, 'shop_grid_cols', '4') === '5' ? 'selected' : '' ?>>5 Columns</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Toggle Sections -->
            <div class="bg-white rounded-xl shadow-sm p-5 border">
                <h3 class="font-semibold text-gray-800 mb-4"><i class="fas fa-eye mr-1.5 text-gray-400"></i> Show / Hide Elements</h3>
                <div class="grid sm:grid-cols-3 gap-4">
                    <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100 transition">
                        <input type="checkbox" name="shop_show_sort" value="1" <?= pbChecked($s, 'shop_show_sort') ?> class="w-4 h-4 rounded text-blue-600">
                        <div>
                            <span class="text-sm font-medium">Sort Dropdown</span>
                            <p class="text-xs text-gray-400">Price, newest, popular</p>
                        </div>
                    </label>
                    <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100 transition">
                        <input type="checkbox" name="shop_show_banner" value="1" <?= pbChecked($s, 'shop_show_banner', '1') ?> class="w-4 h-4 rounded text-blue-600">
                        <div>
                            <span class="text-sm font-medium">Category Banner</span>
                            <p class="text-xs text-gray-400">Banner image on category pages</p>
                        </div>
                    </label>
                    <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100 transition">
                        <input type="checkbox" name="shop_show_categories" value="1" <?= pbChecked($s, 'shop_show_categories') ?> class="w-4 h-4 rounded text-blue-600">
                        <div>
                            <span class="text-sm font-medium">Sub-categories</span>
                            <p class="text-xs text-gray-400">Show child categories on parent</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Archive page features from shop-design -->
            <div class="bg-white rounded-xl shadow-sm p-5 border">
                <h3 class="font-semibold text-gray-800 mb-3"><i class="fas fa-info-circle mr-1.5 text-gray-400"></i> More Options</h3>
                <p class="text-sm text-gray-500">Product card appearance (buttons, badges, overlays) can be customized in 
                    <a href="<?= adminUrl('pages/shop-design.php?tab=archive_page') ?>" class="text-blue-600 hover:underline font-medium">Shop Design → Archive Page</a>
                </p>
            </div>
        </div>

        <div class="mt-6 flex items-center justify-between">
            <a href="<?= url('category/all') ?>" target="_blank" class="text-sm text-blue-600 hover:text-blue-800">
                <i class="fas fa-external-link-alt mr-1"></i> Preview Shop Page
            </a>
            <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-medium hover:bg-blue-700 shadow-sm">
                <i class="fas fa-save mr-1.5"></i> Save Changes
            </button>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($tab === 'menu'): ?>
    <!-- ════════════════════════════════════════ -->
    <!--  MAIN MENU BUILDER                       -->
    <!-- ════════════════════════════════════════ -->
    <?php
    $menuItems = json_decode(pb($s, 'nav_menu_items', '[]'), true) ?: [];
    $allCats = $db->fetchAll("SELECT id, name, name_bn, slug FROM categories WHERE is_active = 1 ORDER BY sort_order ASC");
    ?>
    <form method="POST" id="menuForm">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="tab" value="menu">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCSRFToken() ?>">

        <!-- General Nav Settings -->
        <div class="bg-white rounded-xl shadow-sm border p-5 mb-5">
            <h4 class="font-semibold text-gray-800 mb-4"><i class="fas fa-cog mr-2 text-gray-400"></i>Navigation Bar Settings</h4>
            <div class="grid md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Menu Alignment</label>
                    <select name="nav_menu_align" class="w-full px-3 py-2 border rounded-lg text-sm">
                        <option value="left" <?= pb($s,'nav_menu_align','left')==='left'?'selected':'' ?>>Left</option>
                        <option value="center" <?= pb($s,'nav_menu_align')==='center'?'selected':'' ?>>Center</option>
                        <option value="right" <?= pb($s,'nav_menu_align')==='right'?'selected':'' ?>>Right</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Background Color</label>
                    <input type="color" name="navbar_bg_color" value="<?= pb($s,'navbar_bg_color','#1A202C') ?>" class="w-full h-10 border rounded-lg cursor-pointer">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Text Color</label>
                    <input type="color" name="navbar_text_color" value="<?= pb($s,'navbar_text_color','#FFFFFF') ?>" class="w-full h-10 border rounded-lg cursor-pointer">
                </div>
            </div>
            <div class="flex flex-wrap gap-6 mt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="nav_show_shop_link" value="1" <?= pbChecked($s,'nav_show_shop_link','1') ?> class="rounded text-blue-600">
                    <span class="text-sm text-gray-700">Show "সকল পণ্য" link</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="nav_show_categories" value="1" <?= pbChecked($s,'nav_show_categories','1') ?> class="rounded text-blue-600">
                    <span class="text-sm text-gray-700">Auto-show categories</span>
                </label>
            </div>
        </div>

        <!-- Menu Items -->
        <div class="bg-white rounded-xl shadow-sm border p-5 mb-5">
            <div class="flex items-center justify-between mb-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-list mr-2 text-gray-400"></i>Custom Menu Items</h4>
                <div class="flex gap-2">
                    <button type="button" onclick="addMenuItem()" class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-blue-700">
                        <i class="fas fa-plus mr-1"></i> Add Item
                    </button>
                    <button type="button" onclick="addFromCategory()" class="bg-green-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-green-700">
                        <i class="fas fa-folder-plus mr-1"></i> Add Category
                    </button>
                </div>
            </div>
            <p class="text-xs text-gray-400 mb-4">Drag to reorder. Custom items appear in the nav bar alongside or instead of auto-categories. "Button" style items get a highlighted background.</p>

            <div id="menuItemsList" class="space-y-2">
                <?php if (empty($menuItems)): ?>
                <div class="text-center py-8 text-gray-400 text-sm border-2 border-dashed rounded-xl" id="menuEmpty">
                    No custom menu items. Click "Add Item" or enable auto-categories above.
                </div>
                <?php endif; ?>
                <?php foreach ($menuItems as $idx => $item): ?>
                <div class="menu-row flex items-center gap-3 bg-gray-50 rounded-xl p-3 border" draggable="true" ondragstart="dragStart(event)" ondragover="dragOver(event)" ondrop="dropItem(event)">
                    <span class="drag-handle text-gray-300 cursor-grab active:cursor-grabbing"><i class="fas fa-grip-vertical"></i></span>
                    <label class="flex items-center flex-shrink-0" title="Active">
                        <input type="checkbox" name="menu_active[]" value="<?= $idx ?>" <?= ($item['active'] ?? 1) ? 'checked' : '' ?> class="rounded text-blue-600">
                    </label>
                    <input type="text" name="menu_label[]" value="<?= e($item['label']) ?>" placeholder="Label" class="flex-1 px-3 py-2 border rounded-lg text-sm min-w-0">
                    <input type="text" name="menu_url[]" value="<?= e($item['url']) ?>" placeholder="/shop or full URL" class="flex-1 px-3 py-2 border rounded-lg text-sm min-w-0">
                    <input type="text" name="menu_icon[]" value="<?= e($item['icon'] ?? '') ?>" placeholder="fas fa-tag" class="w-28 px-3 py-2 border rounded-lg text-sm flex-shrink-0">
                    <select name="menu_style[]" class="w-24 px-2 py-2 border rounded-lg text-sm flex-shrink-0">
                        <option value="link" <?= ($item['style'] ?? 'link') === 'link' ? 'selected' : '' ?>>Link</option>
                        <option value="button" <?= ($item['style'] ?? '') === 'button' ? 'selected' : '' ?>>Button</option>
                    </select>
                    <button type="button" onclick="this.closest('.menu-row').remove();reindexMenu()" class="text-red-400 hover:text-red-600 flex-shrink-0 p-1"><i class="fas fa-trash text-sm"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Category Quick-Add -->
        <div id="catPickerModal" class="hidden fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl w-full max-w-md max-h-[70vh] overflow-y-auto shadow-2xl" onclick="event.stopPropagation()">
                <div class="p-4 border-b font-semibold text-gray-800">Select Categories to Add</div>
                <div class="p-4 space-y-1">
                    <?php foreach ($allCats as $cat): ?>
                    <label class="flex items-center gap-2 py-2 px-3 hover:bg-gray-50 rounded-lg cursor-pointer">
                        <input type="checkbox" class="cat-pick rounded text-blue-600" data-slug="<?= e($cat['slug']) ?>" data-name="<?= e($cat['name_bn'] ?: $cat['name']) ?>">
                        <span class="text-sm"><?= e($cat['name_bn'] ?: $cat['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="p-4 border-t flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('catPickerModal').classList.add('hidden')" class="px-4 py-2 border rounded-lg text-sm">Cancel</button>
                    <button type="button" onclick="addSelectedCategories()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Add Selected</button>
                </div>
            </div>
        </div>

        <!-- Live Preview -->
        <div class="bg-white rounded-xl shadow-sm border p-5 mb-5">
            <h4 class="font-semibold text-gray-800 mb-3"><i class="fas fa-eye mr-2 text-gray-400"></i>Preview</h4>
            <div id="navPreview" class="rounded-xl overflow-hidden" style="background:<?= pb($s,'navbar_bg_color','#1A202C') ?>">
                <div class="px-4 py-1 flex items-center gap-1 overflow-x-auto" id="previewBar">
                    <span class="text-sm text-gray-400 px-3 py-2">Preview will update on save</span>
                </div>
            </div>
        </div>

        <div class="sticky bottom-4 z-10">
            <button class="w-full bg-blue-600 text-white px-6 py-3 rounded-xl text-sm font-semibold hover:bg-blue-700 shadow-lg">
                <i class="fas fa-check mr-2"></i> Save Menu
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function toggleSection(checkbox) {
    const card = checkbox.closest('.section-card');
    if (checkbox.checked) {
        card.classList.add('active');
        card.classList.remove('disabled-card');
    } else {
        card.classList.remove('active');
        card.classList.add('disabled-card');
    }
}

function toggleEdit(btn) {
    const card = btn.closest('.section-card');
    const panel = card.querySelector('.edit-panel');
    panel.classList.toggle('open');
    const icon = btn.querySelector('i');
    if (panel.classList.contains('open')) {
        icon.className = 'fas fa-chevron-up';
        btn.innerHTML = '<i class="fas fa-chevron-up"></i> Close';
    } else {
        btn.innerHTML = '<i class="fas fa-pen"></i> Edit';
    }
}

// ── Menu Builder ──
function addMenuItem(label, url, icon, style) {
    label = label || ''; url = url || '/'; icon = icon || ''; style = style || 'link';
    var empty = document.getElementById('menuEmpty');
    if (empty) empty.remove();
    var list = document.getElementById('menuItemsList');
    var idx = list.querySelectorAll('.menu-row').length;
    var row = document.createElement('div');
    row.className = 'menu-row flex items-center gap-3 bg-gray-50 rounded-xl p-3 border';
    row.draggable = true;
    row.ondragstart = dragStart;
    row.ondragover = dragOver;
    row.ondrop = dropItem;
    row.innerHTML = '<span class="drag-handle text-gray-300 cursor-grab active:cursor-grabbing"><i class="fas fa-grip-vertical"></i></span>'
        + '<label class="flex items-center flex-shrink-0"><input type="checkbox" name="menu_active[]" value="' + idx + '" checked class="rounded text-blue-600"></label>'
        + '<input type="text" name="menu_label[]" value="' + label.replace(/"/g,'&quot;') + '" placeholder="Label" class="flex-1 px-3 py-2 border rounded-lg text-sm min-w-0">'
        + '<input type="text" name="menu_url[]" value="' + url.replace(/"/g,'&quot;') + '" placeholder="/shop" class="flex-1 px-3 py-2 border rounded-lg text-sm min-w-0">'
        + '<input type="text" name="menu_icon[]" value="' + icon.replace(/"/g,'&quot;') + '" placeholder="fas fa-tag" class="w-28 px-3 py-2 border rounded-lg text-sm flex-shrink-0">'
        + '<select name="menu_style[]" class="w-24 px-2 py-2 border rounded-lg text-sm flex-shrink-0">'
        + '<option value="link"' + (style==='link'?' selected':'') + '>Link</option>'
        + '<option value="button"' + (style==='button'?' selected':'') + '>Button</option></select>'
        + '<button type="button" onclick="this.closest(\'.menu-row\').remove();reindexMenu()" class="text-red-400 hover:text-red-600 flex-shrink-0 p-1"><i class="fas fa-trash text-sm"></i></button>';
    list.appendChild(row);
    reindexMenu();
}

function addFromCategory() {
    document.getElementById('catPickerModal').classList.remove('hidden');
}

function addSelectedCategories() {
    document.querySelectorAll('.cat-pick:checked').forEach(function(cb) {
        addMenuItem(cb.dataset.name, '/category/' + cb.dataset.slug, '', 'link');
        cb.checked = false;
    });
    document.getElementById('catPickerModal').classList.add('hidden');
}

function reindexMenu() {
    var rows = document.querySelectorAll('#menuItemsList .menu-row');
    rows.forEach(function(row, i) {
        var cb = row.querySelector('input[name="menu_active[]"]');
        if (cb) cb.value = i;
    });
}

// Drag & drop
var _dragEl = null;
function dragStart(e) { _dragEl = e.target.closest('.menu-row'); e.dataTransfer.effectAllowed = 'move'; }
function dragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }
function dropItem(e) {
    e.preventDefault();
    var target = e.target.closest('.menu-row');
    if (!target || target === _dragEl) return;
    var list = document.getElementById('menuItemsList');
    var items = Array.from(list.querySelectorAll('.menu-row'));
    var dragIdx = items.indexOf(_dragEl);
    var dropIdx = items.indexOf(target);
    if (dragIdx < dropIdx) { target.after(_dragEl); } else { target.before(_dragEl); }
    reindexMenu();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
