<?php
/**
 * Shop Design — Customize single product page & archive layouts
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Shop Design';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCSRFToken();
    $section = $_POST['section'] ?? '';
    $skipFields = ['action', 'section', CSRF_TOKEN_NAME];
    
    foreach ($_POST as $key => $value) {
        if (in_array($key, $skipFields)) continue;
        $val = sanitize($value);
        $db->query(
            "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) 
             VALUES (?, ?, 'text', ?) ON DUPLICATE KEY UPDATE setting_value = ?",
            [$key, $val, $section, $val]
        );
    }
    
    // Handle checkbox fields (unchecked = not sent)
    $checkboxFields = [
        'product_page' => [
            'sp_show_order_btn', 'sp_show_cart_btn', 'sp_show_buynow_btn', 
            'sp_show_call_btn', 'sp_show_whatsapp_btn', 'sp_show_stock_status',
            'sp_show_discount_badge', 'sp_show_related', 'sp_show_bundles',
            'sp_show_tabs', 'sp_show_share', 'sp_show_qty_selector',
            'sp_show_wishlist_btn',
        ],
        'archive_page' => [
            'ar_show_card_buttons', 'ar_show_overlay', 'ar_show_discount_badge',
            'ar_show_sort', 'ar_show_order_btn', 'ar_show_cart_btn',
            'ar_clean_card_border', 'ar_clean_old_price', 'ar_clean_show_offered',
        ],
    ];
    
    if (isset($checkboxFields[$section])) {
        foreach ($checkboxFields[$section] as $field) {
            if (!isset($_POST[$field])) {
                $db->query(
                    "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) 
                     VALUES (?, '0', 'text', ?) ON DUPLICATE KEY UPDATE setting_value = '0'",
                    [$field, $section]
                );
            }
        }
    }
    
    logActivity(getAdminId(), 'update', 'settings', 0, "Updated {$section} shop design");
    redirect(adminUrl("pages/shop-design.php?tab={$section}&msg=saved"));
}

$tab = $_GET['tab'] ?? 'product_page';
$s = [];
$allSettings = $db->fetchAll("SELECT setting_key, setting_value FROM site_settings");
foreach ($allSettings as $row) $s[$row['setting_key']] = $row['setting_value'];

// Helper
function checked($s, $key, $default = '1') {
    return (($s[$key] ?? $default) === '1') ? 'checked' : '';
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Shop Design</h1>
            <p class="text-sm text-gray-500">Customize product pages and archive layouts</p>
        </div>
        <?php if (isset($_GET['msg'])): ?>
        <div class="bg-green-50 text-green-700 px-4 py-2 rounded-lg text-sm font-medium animate-pulse">
            <i class="fas fa-check-circle mr-1"></i> Settings saved!
        </div>
        <?php endif; ?>
    </div>

    <div class="flex gap-6">
        <!-- Tabs -->
        <div class="w-56 flex-shrink-0">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <?php 
                $tabs = [
                    'product_page' => ['Single Product', 'fa-box-open'],
                    'archive_page' => ['Product Archive', 'fa-th-large'],
                ];
                foreach ($tabs as $tkey => $tdata): ?>
                <a href="?tab=<?= $tkey ?>" class="flex items-center gap-3 px-4 py-3.5 text-sm font-medium border-b last:border-b-0 transition
                    <?= $tab === $tkey ? 'bg-blue-50 text-blue-700 border-l-2 border-l-blue-600' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <i class="fas <?= $tdata[1] ?> w-5 text-center"></i>
                    <?= $tdata[0] ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-1">
            <form method="POST">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="section" value="<?= $tab ?>">

                <?php if ($tab === 'product_page'): ?>
                <!-- ═══════════════════════ SINGLE PRODUCT PAGE ═══════════════════════ -->
                
                <!-- Button Visibility -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5">
                    <h4 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-hand-pointer text-blue-500"></i> Button Visibility
                    </h4>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <?php
                        $buttons = [
                            'sp_show_order_btn' => ['Order (COD) Button', '1'],
                            'sp_show_cart_btn' => ['Add to Cart Button', '1'],
                            'sp_show_buynow_btn' => ['Buy Now Button', '1'],
                            'sp_show_call_btn' => ['Call Button', '1'],
                            'sp_show_whatsapp_btn' => ['WhatsApp Button', '1'],
                            'sp_show_qty_selector' => ['Quantity Selector', '1'],
                            'sp_show_wishlist_btn' => ['Wishlist ❤️ Button', '1'],
                        ];
                        foreach ($buttons as $key => $info): ?>
                        <label class="flex items-center gap-3 p-3 rounded-lg border hover:bg-gray-50 cursor-pointer transition">
                            <input type="hidden" name="<?= $key ?>" value="0">
                            <input type="checkbox" name="<?= $key ?>" value="1" class="w-4 h-4 text-blue-600 rounded" <?= checked($s, $key, $info[1]) ?>>
                            <span class="text-sm text-gray-700"><?= $info[0] ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Button Labels -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5">
                    <h4 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-tag text-green-500"></i> Button Labels
                    </h4>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Order Button Text</label>
                            <input type="text" name="btn_order_cod_label" value="<?= e($s['btn_order_cod_label'] ?? 'ক্যাশ অন ডেলিভারিতে অর্ডার করুন') ?>" 
                                   class="w-full px-3 py-2.5 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Add to Cart Text</label>
                            <input type="text" name="btn_add_to_cart_label" value="<?= e($s['btn_add_to_cart_label'] ?? 'কার্টে যোগ করুন') ?>" 
                                   class="w-full px-3 py-2.5 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Buy Now Text</label>
                            <input type="text" name="btn_buy_now_label" value="<?= e($s['btn_buy_now_label'] ?? 'এখনই কিনুন') ?>" 
                                   class="w-full px-3 py-2.5 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Call Button Text</label>
                            <input type="text" name="btn_call_label" value="<?= e($s['btn_call_label'] ?? 'কল করুন') ?>" 
                                   class="w-full px-3 py-2.5 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">WhatsApp Button Text</label>
                            <input type="text" name="btn_whatsapp_label" value="<?= e($s['btn_whatsapp_label'] ?? 'WhatsApp') ?>" 
                                   class="w-full px-3 py-2.5 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Archive Card Order Text</label>
                            <input type="text" name="btn_archive_order_label" value="<?= e($s['btn_archive_order_label'] ?? 'অর্ডার') ?>" 
                                   class="w-full px-3 py-2.5 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">
                        </div>
                    </div>
                </div>

                <!-- Page Sections -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5">
                    <h4 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-puzzle-piece text-purple-500"></i> Page Sections
                    </h4>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <?php
                        $sections = [
                            'sp_show_stock_status' => ['Stock Status Badge', '1'],
                            'sp_show_discount_badge' => ['Discount Badge', '1'],
                            'sp_show_related' => ['Related Products', '1'],
                            'sp_show_bundles' => ['Bundle Deals', '1'],
                            'sp_show_tabs' => ['Description/Review Tabs', '1'],
                            'sp_show_share' => ['Social Share Buttons', '1'],
                        ];
                        foreach ($sections as $key => $info): ?>
                        <label class="flex items-center gap-3 p-3 rounded-lg border hover:bg-gray-50 cursor-pointer transition">
                            <input type="hidden" name="<?= $key ?>" value="0">
                            <input type="checkbox" name="<?= $key ?>" value="1" class="w-4 h-4 text-blue-600 rounded" <?= checked($s, $key, $info[1]) ?>>
                            <span class="text-sm text-gray-700"><?= $info[0] ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Button Layout -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5">
                    <h4 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-columns text-orange-500"></i> Button Layout
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php $layout = $s['sp_button_layout'] ?? 'standard'; ?>
                        <label class="relative border-2 rounded-xl p-4 cursor-pointer transition hover:border-blue-300 <?= $layout === 'standard' ? 'border-blue-500 bg-blue-50' : '' ?>">
                            <input type="radio" name="sp_button_layout" value="standard" class="sr-only" <?= $layout === 'standard' ? 'checked' : '' ?>>
                            <div class="text-center">
                                <div class="mb-2 space-y-1.5">
                                    <div class="h-8 bg-red-200 rounded-lg"></div>
                                    <div class="grid grid-cols-2 gap-1.5">
                                        <div class="h-7 bg-orange-200 rounded-lg"></div>
                                        <div class="h-7 bg-gray-200 rounded-lg border"></div>
                                    </div>
                                </div>
                                <p class="text-xs font-medium text-gray-700">Standard</p>
                                <p class="text-[10px] text-gray-400">Order + Cart/Buy</p>
                            </div>
                        </label>
                        <label class="relative border-2 rounded-xl p-4 cursor-pointer transition hover:border-blue-300 <?= $layout === 'two_buttons' ? 'border-blue-500 bg-blue-50' : '' ?>">
                            <input type="radio" name="sp_button_layout" value="two_buttons" class="sr-only" <?= $layout === 'two_buttons' ? 'checked' : '' ?>>
                            <div class="text-center">
                                <div class="mb-2 space-y-1.5">
                                    <div class="h-8 bg-red-200 rounded-lg"></div>
                                    <div class="h-8 bg-orange-200 rounded-lg"></div>
                                </div>
                                <p class="text-xs font-medium text-gray-700">Two Full</p>
                                <p class="text-[10px] text-gray-400">Order + Cart only</p>
                            </div>
                        </label>
                        <label class="relative border-2 rounded-xl p-4 cursor-pointer transition hover:border-blue-300 <?= $layout === 'order_only' ? 'border-blue-500 bg-blue-50' : '' ?>">
                            <input type="radio" name="sp_button_layout" value="order_only" class="sr-only" <?= $layout === 'order_only' ? 'checked' : '' ?>>
                            <div class="text-center">
                                <div class="mb-2 space-y-1.5">
                                    <div class="h-10 bg-red-200 rounded-lg"></div>
                                </div>
                                <p class="text-xs font-medium text-gray-700">Order Only</p>
                                <p class="text-[10px] text-gray-400">Single CTA</p>
                            </div>
                        </label>
                    </div>
                </div>

                <?php elseif ($tab === 'archive_page'): ?>
                <!-- ═══════════════════════ ARCHIVE / SHOP PAGE ═══════════════════════ -->
                
                <!-- Grid Layout -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5">
                    <h4 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-th text-blue-500"></i> Grid Layout
                    </h4>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Desktop Columns (lg+)</label>
                            <select name="ar_grid_cols_desktop" class="w-full px-3 py-2.5 border rounded-lg text-sm">
                                <?php $cols = $s['ar_grid_cols_desktop'] ?? '5';
                                foreach (['3' => '3 Columns', '4' => '4 Columns', '5' => '5 Columns', '6' => '6 Columns'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $cols === $v ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Tablet Columns (md)</label>
                            <select name="ar_grid_cols_tablet" class="w-full px-3 py-2.5 border rounded-lg text-sm">
                                <?php $cols = $s['ar_grid_cols_tablet'] ?? '4';
                                foreach (['2' => '2 Columns', '3' => '3 Columns', '4' => '4 Columns'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $cols === $v ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Mobile Columns</label>
                            <select name="ar_grid_cols_mobile" class="w-full px-3 py-2.5 border rounded-lg text-sm">
                                <?php $cols = $s['ar_grid_cols_mobile'] ?? '2';
                                foreach (['1' => '1 Column', '2' => '2 Columns'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $cols === $v ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">Products Per Page</label>
                            <select name="ar_products_per_page" class="w-full px-3 py-2.5 border rounded-lg text-sm">
                                <?php $pp = $s['ar_products_per_page'] ?? '20';
                                foreach (['12', '16', '20', '24', '30', '40'] as $v): ?>
                                <option value="<?= $v ?>" <?= $pp === $v ? 'selected' : '' ?>><?= $v ?> Products</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Card Features -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5">
                    <h4 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-layer-group text-green-500"></i> Card Features
                    </h4>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <?php
                        $cardFeatures = [
                            'ar_show_card_buttons' => ['Card Action Buttons', '1'],
                            'ar_show_cart_btn' => ['Cart Button on Card', '1'],
                            'ar_show_order_btn' => ['Order Button on Card', '1'],
                            'ar_show_overlay' => ['Hover Overlay (Desktop)', '1'],
                            'ar_show_discount_badge' => ['Discount Badge', '1'],
                            'ar_show_sort' => ['Sort Dropdown', '1'],
                        ];
                        foreach ($cardFeatures as $key => $info): ?>
                        <label class="flex items-center gap-3 p-3 rounded-lg border hover:bg-gray-50 cursor-pointer transition">
                            <input type="hidden" name="<?= $key ?>" value="0">
                            <input type="checkbox" name="<?= $key ?>" value="1" class="w-4 h-4 text-blue-600 rounded" <?= checked($s, $key, $info[1]) ?>>
                            <span class="text-sm text-gray-700"><?= $info[0] ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Card Style -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5">
                    <h4 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-palette text-purple-500"></i> Card Style
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php $cardStyle = $s['ar_card_style'] ?? 'standard'; ?>
                        <label class="relative border-2 rounded-xl p-4 cursor-pointer transition hover:border-blue-300 <?= $cardStyle === 'standard' ? 'border-blue-500 bg-blue-50' : '' ?>">
                            <input type="radio" name="ar_card_style" value="standard" class="sr-only" <?= $cardStyle === 'standard' ? 'checked' : '' ?>>
                            <div class="text-center">
                                <div class="w-full aspect-square bg-gray-100 rounded-lg mb-2"></div>
                                <div class="h-3 bg-gray-200 rounded w-3/4 mx-auto mb-1.5"></div>
                                <div class="h-3 bg-red-200 rounded w-1/2 mx-auto mb-2"></div>
                                <div class="grid grid-cols-2 gap-1">
                                    <div class="h-5 bg-orange-200 rounded"></div>
                                    <div class="h-5 bg-red-200 rounded"></div>
                                </div>
                                <p class="text-xs font-medium text-gray-700 mt-2">Standard</p>
                            </div>
                        </label>
                        <label class="relative border-2 rounded-xl p-4 cursor-pointer transition hover:border-blue-300 <?= $cardStyle === 'minimal' ? 'border-blue-500 bg-blue-50' : '' ?>">
                            <input type="radio" name="ar_card_style" value="minimal" class="sr-only" <?= $cardStyle === 'minimal' ? 'checked' : '' ?>>
                            <div class="text-center">
                                <div class="w-full aspect-square bg-gray-100 rounded-lg mb-2"></div>
                                <div class="h-3 bg-gray-200 rounded w-3/4 mx-auto mb-1.5"></div>
                                <div class="h-3 bg-red-200 rounded w-1/2 mx-auto"></div>
                                <p class="text-xs font-medium text-gray-700 mt-2">Minimal</p>
                                <p class="text-[10px] text-gray-400">No buttons</p>
                            </div>
                        </label>
                        <label class="relative border-2 rounded-xl p-4 cursor-pointer transition hover:border-blue-300 <?= $cardStyle === 'detailed' ? 'border-blue-500 bg-blue-50' : '' ?>">
                            <input type="radio" name="ar_card_style" value="detailed" class="sr-only" <?= $cardStyle === 'detailed' ? 'checked' : '' ?>>
                            <div class="text-center">
                                <div class="w-full aspect-square bg-gray-100 rounded-lg mb-2"></div>
                                <div class="h-2 bg-gray-200 rounded w-full mb-1"></div>
                                <div class="h-2 bg-gray-200 rounded w-5/6 mb-1.5"></div>
                                <div class="h-3 bg-red-200 rounded w-1/2 mx-auto mb-2"></div>
                                <div class="h-6 bg-red-200 rounded w-full"></div>
                                <p class="text-xs font-medium text-gray-700 mt-2">Detailed</p>
                                <p class="text-[10px] text-gray-400">Full-width order</p>
                            </div>
                        </label>
                        <label class="relative border-2 rounded-xl p-4 cursor-pointer transition hover:border-blue-300 <?= $cardStyle === 'clean' ? 'border-blue-500 bg-blue-50' : '' ?>">
                            <input type="radio" name="ar_card_style" value="clean" class="sr-only" <?= $cardStyle === 'clean' ? 'checked' : '' ?>>
                            <div class="text-center">
                                <div class="w-full aspect-square bg-white rounded-lg mb-2 border border-gray-200 relative flex items-center justify-center p-3">
                                    <div class="w-8 h-8 bg-gray-100 rounded"></div>
                                    <span class="absolute top-1 right-1 bg-green-500 text-white text-[6px] px-1 rounded font-bold">Save</span>
                                </div>
                                <div class="h-2 bg-gray-200 rounded w-3/4 mx-auto mb-1"></div>
                                <div class="flex gap-1 justify-center mb-2"><div class="h-2 bg-orange-200 rounded w-8"></div><div class="h-2 bg-gray-100 rounded w-6 line-through"></div></div>
                                <div class="h-5 border border-orange-300 rounded text-[7px] text-orange-500 flex items-center justify-center font-bold">🛒 Add To Cart</div>
                                <p class="text-xs font-medium text-gray-700 mt-2">Clean</p>
                                <p class="text-[10px] text-gray-400">Outlined buttons</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- ═══ Clean Card Settings (shown when "Clean" is selected) ═══ -->
                <div id="cleanCardSettings" class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5 <?= $cardStyle !== 'clean' ? 'hidden' : '' ?>">
                    <h4 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-sliders-h text-emerald-500"></i> Clean Card Settings
                        <span class="ml-auto text-[10px] text-gray-400 font-normal">Only applies when "Clean" style is selected</span>
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Button Style</label>
                            <select name="ar_clean_btn_style" class="w-full px-3 py-2 border rounded-lg text-sm">
                                <option value="outlined" <?= ($s['ar_clean_btn_style'] ?? 'outlined') === 'outlined' ? 'selected' : '' ?>>Outlined (border)</option>
                                <option value="filled" <?= ($s['ar_clean_btn_style'] ?? '') === 'filled' ? 'selected' : '' ?>>Filled (solid)</option>
                                <option value="ghost" <?= ($s['ar_clean_btn_style'] ?? '') === 'ghost' ? 'selected' : '' ?>>Ghost (gray bg)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Button Label</label>
                            <input type="text" name="ar_clean_btn_label" value="<?= e($s['ar_clean_btn_label'] ?? 'Add To Cart') ?>" class="w-full px-3 py-2 border rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Button Icon</label>
                            <select name="ar_clean_btn_icon" class="w-full px-3 py-2 border rounded-lg text-sm">
                                <?php $btnIcons = ['fa-cart-arrow-down'=>'Cart Arrow','fa-cart-plus'=>'Cart Plus','fa-shopping-cart'=>'Shopping Cart','fa-shopping-bag'=>'Shopping Bag','fa-shopping-basket'=>'Basket','fa-plus'=>'Plus','fa-heart'=>'Heart','fa-bolt'=>'Bolt'];
                                foreach ($btnIcons as $ic => $il): ?>
                                <option value="<?= $ic ?>" <?= ($s['ar_clean_btn_icon'] ?? 'fa-cart-arrow-down') === $ic ? 'selected' : '' ?>><?= $il ?> (<?= $ic ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Badge Position</label>
                            <select name="ar_clean_badge_pos" class="w-full px-3 py-2 border rounded-lg text-sm">
                                <option value="top_right" <?= ($s['ar_clean_badge_pos'] ?? 'top_right') === 'top_right' ? 'selected' : '' ?>>Top Right</option>
                                <option value="top_left" <?= ($s['ar_clean_badge_pos'] ?? '') === 'top_left' ? 'selected' : '' ?>>Top Left</option>
                                <option value="bottom_right" <?= ($s['ar_clean_badge_pos'] ?? '') === 'bottom_right' ? 'selected' : '' ?>>Bottom Right</option>
                                <option value="bottom_left" <?= ($s['ar_clean_badge_pos'] ?? '') === 'bottom_left' ? 'selected' : '' ?>>Bottom Left</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Image Fit</label>
                            <select name="ar_clean_img_fit" class="w-full px-3 py-2 border rounded-lg text-sm">
                                <option value="contain" <?= ($s['ar_clean_img_fit'] ?? 'contain') === 'contain' ? 'selected' : '' ?>>Contain (full image, no crop)</option>
                                <option value="cover" <?= ($s['ar_clean_img_fit'] ?? '') === 'cover' ? 'selected' : '' ?>>Cover (fill, may crop)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Image Background</label>
                            <input type="color" name="ar_clean_img_bg" value="<?= e($s['ar_clean_img_bg'] ?? '#ffffff') ?>" class="h-10 w-full rounded-lg border cursor-pointer">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Card Border Radius (px)</label>
                            <input type="number" name="ar_clean_card_radius" value="<?= e($s['ar_clean_card_radius'] ?? '8') ?>" class="w-full px-3 py-2 border rounded-lg text-sm" min="0" max="30">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Product Name Lines</label>
                            <select name="ar_clean_name_lines" class="w-full px-3 py-2 border rounded-lg text-sm">
                                <option value="1" <?= ($s['ar_clean_name_lines'] ?? '1') === '1' ? 'selected' : '' ?>>1 line</option>
                                <option value="2" <?= ($s['ar_clean_name_lines'] ?? '') === '2' ? 'selected' : '' ?>>2 lines</option>
                                <option value="3" <?= ($s['ar_clean_name_lines'] ?? '') === '3' ? 'selected' : '' ?>>3 lines</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Badge Color</label>
                            <input type="color" name="ar_clean_badge_color" value="<?= e($s['ar_clean_badge_color'] ?? '#22c55e') ?>" class="h-10 w-full rounded-lg border cursor-pointer">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Badge Text Color</label>
                            <input type="color" name="ar_clean_badge_text_color" value="<?= e($s['ar_clean_badge_text_color'] ?? '#ffffff') ?>" class="h-10 w-full rounded-lg border cursor-pointer">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">"Offered" Label</label>
                            <input type="text" name="ar_clean_offered_label" value="<?= e($s['ar_clean_offered_label'] ?? 'Offered Items') ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Offered Items">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        <label class="flex items-center gap-2 p-3 rounded-lg border hover:bg-gray-50 cursor-pointer">
                            <input type="hidden" name="ar_clean_card_border" value="0">
                            <input type="checkbox" name="ar_clean_card_border" value="1" class="w-4 h-4 text-blue-600 rounded" <?= ($s['ar_clean_card_border'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="text-sm">Card Border</span>
                        </label>
                        <label class="flex items-center gap-2 p-3 rounded-lg border hover:bg-gray-50 cursor-pointer">
                            <input type="hidden" name="ar_clean_old_price" value="0">
                            <input type="checkbox" name="ar_clean_old_price" value="1" class="w-4 h-4 text-blue-600 rounded" <?= ($s['ar_clean_old_price'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="text-sm">Show Old Price</span>
                        </label>
                        <label class="flex items-center gap-2 p-3 rounded-lg border hover:bg-gray-50 cursor-pointer">
                            <input type="hidden" name="ar_clean_show_offered" value="0">
                            <input type="checkbox" name="ar_clean_show_offered" value="1" class="w-4 h-4 text-blue-600 rounded" <?= ($s['ar_clean_show_offered'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="text-sm">Show "Offered" Badge</span>
                        </label>
                    </div>
                </div>
                <script>
                document.querySelectorAll('input[name="ar_card_style"]').forEach(r => {
                    r.addEventListener('change', function(){
                        document.getElementById('cleanCardSettings').classList.toggle('hidden', this.value !== 'clean');
                        // Update radio card active states
                        document.querySelectorAll('input[name="ar_card_style"]').forEach(r2 => {
                            const lbl = r2.closest('label');
                            lbl.classList.toggle('border-blue-500', r2.checked);
                            lbl.classList.toggle('bg-blue-50', r2.checked);
                        });
                    });
                });
                </script>

                <?php endif; ?>

                <!-- ═══════ LIVE PREVIEW ═══════ -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5">
                    <h4 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-eye text-blue-500"></i> Live Preview
                        <span class="text-xs font-normal text-gray-400 ml-auto">Updates as you change settings above</span>
                    </h4>
                    <div class="bg-gray-50 rounded-xl p-6">
                        <div id="previewGrid" class="grid gap-4" style="grid-template-columns: repeat(3, 1fr); max-width: 540px; margin: 0 auto;">
                            <?php for ($pi = 0; $pi < 3; $pi++): 
                                $previewNames = ['জৈব মধু ৫০০ মি.লি.', 'কালোজিরা তেল ২০০ মি.লি.', 'খাঁটি ঘি ৪০০ গ্রাম'];
                                $previewPrices = [450, 320, 680];
                                $previewOld = [550, 400, 0];
                            ?>
                            <div class="preview-card bg-white rounded-xl overflow-hidden shadow-sm border border-gray-100" data-idx="<?= $pi ?>">
                                <div class="aspect-square bg-gradient-to-br from-gray-100 to-gray-200 relative flex items-center justify-center">
                                    <i class="fas fa-image text-gray-300 text-3xl"></i>
                                    <?php if ($pi < 2): ?>
                                    <span class="preview-badge absolute top-2 left-2 bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded">-<?= round(($previewOld[$pi] - $previewPrices[$pi]) / $previewOld[$pi] * 100) ?>%</span>
                                    <?php endif; ?>
                                </div>
                                <div class="p-2.5">
                                    <p class="preview-name text-xs font-semibold text-gray-800 leading-tight mb-1 line-clamp-2"><?= $previewNames[$pi] ?></p>
                                    <div class="flex items-baseline gap-1.5 mb-2">
                                        <span class="preview-price text-sm font-bold text-red-500">৳<?= $previewPrices[$pi] ?></span>
                                        <?php if ($previewOld[$pi] > 0): ?>
                                        <span class="preview-old text-[10px] text-gray-400 line-through">৳<?= $previewOld[$pi] ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="preview-buttons space-y-1.5">
                                        <div class="preview-btn-row flex gap-1.5">
                                            <button class="preview-cart-btn flex-1 flex items-center justify-center gap-1 bg-orange-500 text-white text-[10px] font-semibold py-1.5 rounded-md">
                                                <i class="fas fa-cart-plus text-[8px]"></i> কার্ট
                                            </button>
                                            <button class="preview-order-btn flex-1 flex items-center justify-center gap-1 bg-red-500 text-white text-[10px] font-semibold py-1.5 rounded-md">
                                                <i class="fas fa-shopping-bag text-[8px]"></i> অর্ডার
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="bg-blue-600 text-white px-8 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition shadow-sm">
                        <i class="fas fa-save mr-2"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Visual feedback for radio card selection
document.querySelectorAll('input[type="radio"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const name = this.name;
        document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
            r.closest('label').classList.remove('border-blue-500', 'bg-blue-50');
        });
        this.closest('label').classList.add('border-blue-500', 'bg-blue-50');
        updatePreview();
    });
});

// ── Live Preview Logic ──
function updatePreview() {
    const cards = document.querySelectorAll('.preview-card');
    if (!cards.length) return;
    
    const tab = '<?= $tab ?>';
    
    if (tab === 'archive_page') {
        const showButtons = document.querySelector('input[name="ar_show_card_buttons"][type="checkbox"]')?.checked ?? true;
        const showCart = document.querySelector('input[name="ar_show_cart_btn"][type="checkbox"]')?.checked ?? true;
        const showOrder = document.querySelector('input[name="ar_show_order_btn"][type="checkbox"]')?.checked ?? true;
        const showBadge = document.querySelector('input[name="ar_show_discount_badge"][type="checkbox"]')?.checked ?? true;
        const cardStyle = document.querySelector('[name="ar_card_style"]:checked')?.value ?? 'standard';
        
        cards.forEach(card => {
            // Badge
            const badge = card.querySelector('.preview-badge');
            if (badge) badge.style.display = showBadge ? '' : 'none';
            
            // Buttons area
            const btnArea = card.querySelector('.preview-buttons');
            if (!btnArea) return;
            
            if (!showButtons || cardStyle === 'minimal') {
                btnArea.style.display = 'none';
                return;
            }
            btnArea.style.display = '';
            
            const cartBtn = card.querySelector('.preview-cart-btn');
            const orderBtn = card.querySelector('.preview-order-btn');
            
            if (cardStyle === 'detailed') {
                // Detailed = full-width order only
                if (cartBtn) cartBtn.style.display = 'none';
                if (orderBtn) { orderBtn.style.display = ''; orderBtn.style.flex = '1 1 100%'; }
            } else {
                // Standard — respect cart/order toggles
                let sc = showCart, so = showOrder;
                if (!sc && !so) so = true; // fallback
                if (cartBtn) { cartBtn.style.display = sc ? '' : 'none'; cartBtn.style.flex = '1'; }
                if (orderBtn) { orderBtn.style.display = so ? '' : 'none'; orderBtn.style.flex = '1'; }
            }
        });
    } else if (tab === 'product_page') {
        const layout = document.querySelector('[name="sp_button_layout"]:checked')?.value ?? 'standard';
        cards.forEach(card => {
            const cartBtn = card.querySelector('.preview-cart-btn');
            const orderBtn = card.querySelector('.preview-order-btn');
            const btnArea = card.querySelector('.preview-buttons');
            if (!btnArea) return;
            btnArea.style.display = '';
            
            if (layout === 'order_only') {
                if (cartBtn) cartBtn.style.display = 'none';
                if (orderBtn) { orderBtn.style.display = ''; orderBtn.style.flex = '1 1 100%'; }
            } else if (layout === 'two_buttons') {
                if (cartBtn) { cartBtn.style.display = ''; cartBtn.style.flex = '1'; }
                if (orderBtn) { orderBtn.style.display = ''; orderBtn.style.flex = '1'; }
            } else {
                if (cartBtn) { cartBtn.style.display = ''; cartBtn.style.flex = '1'; }
                if (orderBtn) { orderBtn.style.display = ''; orderBtn.style.flex = '1'; }
            }
        });
    }
}

// Listen to ALL checkbox and radio changes
document.querySelectorAll('input[type="checkbox"], input[type="radio"], select').forEach(el => {
    el.addEventListener('change', updatePreview);
});

// Initial preview state
document.addEventListener('DOMContentLoaded', updatePreview);
updatePreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
