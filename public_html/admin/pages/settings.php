<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/auth.php';
// Load FB CAPI for ads tab
if (file_exists(__DIR__ . '/../../includes/fb-capi.php')) {
    require_once __DIR__ . '/../../includes/fb-capi.php';
}

$db = Database::getInstance();

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';
    
    // Handle file uploads (traditional)
    $fileFields = ['site_logo', 'site_favicon', 'footer_logo'];
    foreach ($fileFields as $ff) {
        if (!empty($_FILES[$ff]['name'])) {
            $upload = uploadFile($_FILES[$ff], 'logos');
            if ($upload) updateSetting($ff, $upload);
        }
    }
    
    // Handle custom font upload (.woff2, .woff, .ttf, .otf)
    if (!empty($_FILES['custom_font_file']['name'])) {
        $fontFile = $_FILES['custom_font_file'];
        $ext = strtolower(pathinfo($fontFile['name'], PATHINFO_EXTENSION));
        $allowedFontExts = ['woff2', 'woff', 'ttf', 'otf'];
        if (in_array($ext, $allowedFontExts) && $fontFile['size'] <= 5 * 1024 * 1024) {
            $fontsDir = ROOT_PATH . 'uploads/fonts';
            if (!is_dir($fontsDir)) mkdir($fontsDir, 0755, true);
            $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fontFile['name']);
            $destPath = $fontsDir . '/' . $safeName;
            if (move_uploaded_file($fontFile['tmp_name'], $destPath)) {
                $fontName = $_POST['custom_font_name'] ?? pathinfo($safeName, PATHINFO_FILENAME);
                $fontName = trim($fontName) ?: pathinfo($safeName, PATHINFO_FILENAME);
                // Load existing custom fonts
                $customFonts = json_decode(getSetting('custom_fonts', '[]'), true) ?: [];
                $customFonts[] = [
                    'name' => $fontName,
                    'file' => 'fonts/' . $safeName,
                    'format' => $ext,
                    'uploaded' => date('Y-m-d H:i:s'),
                ];
                updateSetting('custom_fonts', json_encode($customFonts, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    
    // Handle custom font deletion
    if (!empty($_POST['delete_custom_font'])) {
        $delIdx = intval($_POST['delete_custom_font']);
        $customFonts = json_decode(getSetting('custom_fonts', '[]'), true) ?: [];
        if (isset($customFonts[$delIdx])) {
            $delFile = ROOT_PATH . 'uploads/' . $customFonts[$delIdx]['file'];
            if (file_exists($delFile)) @unlink($delFile);
            array_splice($customFonts, $delIdx, 1);
            updateSetting('custom_fonts', json_encode($customFonts, JSON_UNESCAPED_UNICODE));
        }
    }
    
    // Handle media-library selected images
    $mediaFields = ['site_logo_media', 'site_favicon_media', 'footer_logo_media'];
    foreach ($mediaFields as $mf) {
        $realKey = str_replace('_media', '', $mf);
        if (!empty($_POST[$mf])) {
            updateSetting($realKey, $_POST[$mf]);
        }
    }
    
    // Build footer links JSON from arrays
    for ($col = 1; $col <= 2; $col++) {
        $labels = $_POST["footer_links_col{$col}_label"] ?? [];
        $urls = $_POST["footer_links_col{$col}_url"] ?? [];
        $links = [];
        foreach ($labels as $i => $label) {
            if (trim($label) || trim($urls[$i] ?? '')) {
                $links[] = ['label' => trim($label), 'url' => trim($urls[$i] ?? '')];
            }
        }
        updateSetting("footer_links_col{$col}", json_encode($links, JSON_UNESCAPED_UNICODE));
        unset($_POST["footer_links_col{$col}_label"], $_POST["footer_links_col{$col}_url"]);
    }
    
    // Save all text/color/other settings
    $skipFields = ['section', 'action', 'site_logo', 'site_favicon', 'footer_logo',
                   'site_logo_media', 'site_favicon_media', 'footer_logo_media'];
    foreach ($_POST as $key => $val) {
        if (in_array($key, $skipFields)) continue;
        if (is_array($val)) {
            updateSetting($key, json_encode($val, JSON_UNESCAPED_UNICODE));
        } else {
            updateSetting($key, $val);
        }
    }
    
    // Handle checkbox fields
    $checkboxMap = [
        'general' => [],
        'advanced' => ['maintenance_mode'],
        'ads' => [
            'fb_cs_evt_pageview','fb_cs_evt_viewcontent','fb_cs_evt_addtocart','fb_cs_evt_initiatecheckout',
            'fb_cs_evt_purchase','fb_cs_evt_search','fb_cs_evt_lead','fb_cs_evt_completeregistration','fb_cs_evt_contact',
            'fb_ss_evt_pageview','fb_ss_evt_viewcontent','fb_ss_evt_addtocart','fb_ss_evt_initiatecheckout',
            'fb_ss_evt_purchase','fb_ss_evt_search','fb_ss_evt_lead','fb_ss_evt_completeregistration','fb_ss_evt_contact',
            'fb_event_logging','fb_advanced_matching',
        ],
        'shipping' => ['auto_detect_location'],
        'print' => ['print_show_barcode'],
        'checkout' => ['checkout_note_enabled','order_now_clear_cart','order_merge_enabled','store_credits_enabled','store_credit_checkout'],
        'email' => ['smtp_enabled'],
        'social' => ['fab_enabled','fab_call_enabled','fab_chat_enabled','fab_whatsapp_enabled','fab_messenger_enabled'],
        'clerk' => ['clerk_enabled','clerk_social_google','clerk_social_facebook','clerk_social_phone','clerk_keep_legacy_login'],
        'retention' => ['retention_auto_whatsapp','retention_auto_sms','retention_rebuild_on_delivery'],
    ];
    if (isset($checkboxMap[$section])) {
        foreach ($checkboxMap[$section] as $cb) {
            if (!isset($_POST[$cb])) updateSetting($cb, '0');
        }
    }
    
    try {
        $adminId = getAdminId();
        if ($adminId) {
            logActivity($adminId, 'update', 'settings', 0, "Updated {$section} settings");
        }
    } catch (Exception $e) {
        // Silently skip if admin ID doesn't match
    }

    // Auto-split/merge products when variation display mode changes
    if ($section === 'checkout' && isset($_POST['variation_display_mode'])) {
        $newMode = $_POST['variation_display_mode'];
        try {
            if ($newMode === 'split') {
                $count = autoSplitAllProducts();
                if ($count > 0) {
                    redirect(adminUrl("pages/settings.php?tab=checkout&msg=split&count={$count}"));
                }
            } else {
                $count = autoMergeAllProducts();
                if ($count > 0) {
                    redirect(adminUrl("pages/settings.php?tab=checkout&msg=merged&count={$count}"));
                }
            }
        } catch (\Throwable $e) {}
    }

    redirect(adminUrl("pages/settings.php?tab={$section}&msg=saved"));
}

$tab = $_GET['tab'] ?? 'general';
$allSettings = $db->fetchAll("SELECT * FROM site_settings");
$s = [];
foreach ($allSettings as $row) { $s[$row['setting_key']] = $row['setting_value']; }

function settingImgUrl($s, $key) {
    $val = $s[$key] ?? '';
    if (!$val) return '';
    if (strpos($val, '/') !== false) return uploadUrl($val);
    return imgSrc('logos', $val);
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<?php $msgType = $_GET['msg']; $msgCount = intval($_GET['count'] ?? 0); ?>
<div class="<?= $msgType==='split' ? 'bg-indigo-50 border-indigo-200 text-indigo-700' : ($msgType==='merged' ? 'bg-amber-50 border-amber-200 text-amber-700' : 'bg-green-50 border-green-200 text-green-700') ?> border px-4 py-3 rounded-lg mb-4 text-sm">
    <?php if ($msgType === 'split'): ?>
    <i class="fas fa-check-circle mr-1"></i> Settings saved. <strong><?= $msgCount ?> variation products</strong> created from split mode.
    <?php elseif ($msgType === 'merged'): ?>
    <i class="fas fa-check-circle mr-1"></i> Settings saved. <strong><?= $msgCount ?> products</strong> merged back to combined mode.
    <?php else: ?>
    <i class="fas fa-check-circle mr-1"></i> Settings saved successfully.
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="flex flex-col lg:flex-row gap-6">
    <div class="lg:w-56 flex-shrink-0">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <?php
            $tabs = [
                'general' => ['General', 'fa-cog'],
                'colors' => ['Colors & Design', 'fa-palette'],
                'fontsizes' => ['Typography', 'fa-text-height'],
                'header' => ['Header & Nav', 'fa-bars'],
                'footer' => ['Footer', 'fa-window-minimize'],
                'shipping' => ['Shipping', 'fa-truck'],
                'social' => ['Social & Contact', 'fa-share-alt'],
                'ads' => ['Ads Tracking', 'fa-bullhorn'],
                'checkout' => ['Checkout & Labels', 'fa-shopping-cart'],
                'ordersku' => ['Order & SKU', 'fa-barcode'],
                'print' => ['Print & Invoice', 'fa-print'],
                'registration' => ['Registration Fields', 'fa-user-plus'],
                'clerk' => ['Clerk Auth', 'fa-shield-alt'],
                'seo' => ['SEO & Meta', 'fa-search'],
                'advanced' => ['Advanced', 'fa-tools'],
                'email' => ['Email / SMTP', 'fa-envelope'],
                'retention' => ['Retention CRM', 'fa-redo'],
                'performance' => ['Performance & UX', 'fa-tachometer-alt'],
            ];
            foreach ($tabs as $tkey => $tdata): ?>
            <a href="?tab=<?= $tkey ?>" class="flex items-center gap-3 px-4 py-3 text-sm font-medium border-b last:border-b-0 transition
                <?= $tab === $tkey ? 'bg-blue-50 text-blue-700 border-l-2 border-l-blue-600' : 'text-gray-600 hover:bg-gray-50' ?>">
                <i class="fas <?= $tdata[1] ?> w-4 text-center"></i> <?= $tdata[0] ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="flex-1">
        <form method="POST" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="section" value="<?= $tab ?>">

            <?php if ($tab === 'general'): ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-store mr-2 text-blue-500"></i>Store Information</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Site Name *</label>
                        <input type="text" name="site_name" value="<?= e($s['site_name'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" required></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Tagline</label>
                        <input type="text" name="site_tagline" value="<?= e($s['site_tagline'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                </div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Site Description</label>
                    <textarea name="site_description" rows="2" class="w-full px-3 py-2.5 border rounded-lg text-sm"><?= e($s['site_description'] ?? '') ?></textarea></div>
                <div class="grid md:grid-cols-3 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Currency Symbol</label>
                        <input type="text" name="currency_symbol" value="<?= e($s['currency_symbol'] ?? '৳') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm max-w-[100px]"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Site Phone</label>
                        <input type="text" name="site_phone" value="<?= e($s['site_phone'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">WhatsApp</label>
                        <input type="text" name="site_whatsapp" value="<?= e($s['site_whatsapp'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-image mr-2 text-green-500"></i>Logo & Favicon</h4>
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Site Logo</label>
                        <div class="border-2 border-dashed rounded-xl p-4 text-center">
                            <?php $logoUrl = settingImgUrl($s, 'site_logo'); ?>
                            <?php if ($logoUrl): ?><img src="<?= $logoUrl ?>" class="h-16 mx-auto mb-2" id="logo-preview-img">
                            <?php else: ?><div class="text-gray-400 py-4" id="logo-placeholder"><i class="fas fa-image text-3xl mb-2 block"></i>No logo</div>
                                <img src="" class="h-16 mx-auto mb-2 hidden" id="logo-preview-img"><?php endif; ?>
                            <input type="hidden" name="site_logo_media" id="site_logo_media" value="">
                            <div class="flex gap-2 justify-center mt-2">
                                <button type="button" onclick="pickSettingImage('site_logo')" class="px-3 py-1.5 bg-blue-500 text-white rounded-lg text-xs hover:bg-blue-600"><i class="fas fa-photo-video mr-1"></i>Media Library</button>
                                <label class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs hover:bg-gray-200 cursor-pointer"><i class="fas fa-upload mr-1"></i>Upload
                                    <input type="file" name="site_logo" accept="image/*" class="hidden" onchange="previewFile(this,'logo-preview-img','logo-placeholder')"></label>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Favicon</label>
                        <div class="border-2 border-dashed rounded-xl p-4 text-center">
                            <?php $favUrl = settingImgUrl($s, 'site_favicon'); ?>
                            <?php if ($favUrl): ?><img src="<?= $favUrl ?>" class="w-12 h-12 mx-auto mb-2" id="favicon-preview-img">
                            <?php else: ?><div class="text-gray-400 py-4" id="favicon-placeholder"><i class="fas fa-image text-3xl mb-2 block"></i>No favicon</div>
                                <img src="" class="w-12 h-12 mx-auto mb-2 hidden" id="favicon-preview-img"><?php endif; ?>
                            <input type="hidden" name="site_favicon_media" id="site_favicon_media" value="">
                            <div class="flex gap-2 justify-center mt-2">
                                <button type="button" onclick="pickSettingImage('site_favicon')" class="px-3 py-1.5 bg-blue-500 text-white rounded-lg text-xs hover:bg-blue-600"><i class="fas fa-photo-video mr-1"></i>Media Library</button>
                                <label class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs hover:bg-gray-200 cursor-pointer"><i class="fas fa-upload mr-1"></i>Upload
                                    <input type="file" name="site_favicon" accept="image/*" class="hidden" onchange="previewFile(this,'favicon-preview-img','favicon-placeholder')"></label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Preferences (localStorage-based) -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-chart-bar mr-2 text-teal-500"></i>Dashboard Preferences</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Chart Size</label>
                        <p class="text-xs text-gray-400 mb-3">Controls the size of all charts and graphs across the admin panel.</p>
                        <div class="flex gap-2" id="chartSizePicker">
                            <button type="button" data-sz="S" onclick="setChartPref('S')" class="csz-btn flex-1 px-4 py-3 rounded-lg border-2 text-center transition">
                                <div class="text-lg font-bold">S</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Small</div>
                            </button>
                            <button type="button" data-sz="M" onclick="setChartPref('M')" class="csz-btn flex-1 px-4 py-3 rounded-lg border-2 text-center transition">
                                <div class="text-lg font-bold">M</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Medium</div>
                            </button>
                            <button type="button" data-sz="L" onclick="setChartPref('L')" class="csz-btn flex-1 px-4 py-3 rounded-lg border-2 text-center transition">
                                <div class="text-lg font-bold">L</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Large</div>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            (function(){
                const cur = localStorage.getItem('kb_chart_scale') || 'M';
                function update(sz) {
                    localStorage.setItem('kb_chart_scale', sz);
                    document.querySelectorAll('.csz-btn').forEach(b => {
                        const active = b.dataset.sz === sz;
                        b.className = 'csz-btn flex-1 px-4 py-3 rounded-lg border-2 text-center transition ' +
                            (active ? 'border-teal-500 bg-teal-50 text-teal-700' : 'border-gray-200 bg-white text-gray-500 hover:border-gray-300');
                    });
                }
                window.setChartPref = update;
                update(cur);
            })();
            </script>

            <?php elseif ($tab === 'colors'): ?>
            <!-- ═══════ COLOR PRESETS ═══════ -->
            <?php
            $colorPresets = [
                'khatibangla' => [
                    'name' => 'খাটি বাংলা',
                    'desc' => 'Your brand — saffron & green',
                    'colors' => [
                        'primary_color'=>'#F58020','secondary_color'=>'#4A6F15','accent_color'=>'#A4C955',
                        'button_bg_color'=>'#F58020','button_text_color'=>'#FFFFFF','button_hover_color'=>'#D96D10',
                        'checkout_btn_color'=>'#4A6F15','checkout_btn_text'=>'#FFFFFF',
                        'btn_cart_color'=>'#6B8E23','btn_cart_text'=>'#FFFFFF',
                        'price_color'=>'#D96D10','old_price_color'=>'#A8A29E',
                        'sale_badge_bg'=>'#F58020','sale_badge_text'=>'#FFFFFF',
                        'topbar_bg_color'=>'#3D2517','topbar_text_color'=>'#FED7AA',
                        'navbar_bg_color'=>'#FFFFFF','navbar_text_color'=>'#292524',
                        'footer_bg_color'=>'#2C1810','footer_text_color'=>'#D6D3D1','footer_heading_color'=>'#F58020',
                        'mobile_nav_bg'=>'#FFFFFF','mobile_nav_active'=>'#F58020',
                    ],
                    'preview' => ['#F58020','#A4C955','#4A6F15','#3D2517','#2C1810'],
                ],
                'rose' => [
                    'name' => 'Rose Red',
                    'desc' => 'Bold & warm — classic e-commerce',
                    'colors' => [
                        'primary_color'=>'#E11D48','secondary_color'=>'#1E293B','accent_color'=>'#16A34A',
                        'button_bg_color'=>'#E11D48','button_text_color'=>'#FFFFFF','button_hover_color'=>'#BE123C',
                        'checkout_btn_color'=>'#E11D48','checkout_btn_text'=>'#FFFFFF',
                        'btn_cart_color'=>'#EA580C','btn_cart_text'=>'#FFFFFF',
                        'price_color'=>'#E11D48','old_price_color'=>'#9CA3AF',
                        'sale_badge_bg'=>'#E11D48','sale_badge_text'=>'#FFFFFF',
                        'topbar_bg_color'=>'#1E293B','topbar_text_color'=>'#F1F5F9',
                        'navbar_bg_color'=>'#FFFFFF','navbar_text_color'=>'#0F172A',
                        'footer_bg_color'=>'#0F172A','footer_text_color'=>'#CBD5E1','footer_heading_color'=>'#FFFFFF',
                        'mobile_nav_bg'=>'#FFFFFF','mobile_nav_active'=>'#E11D48',
                    ],
                    'preview' => ['#E11D48','#1E293B','#EA580C','#16A34A','#0F172A'],
                ],
                'ocean' => [
                    'name' => 'Ocean Blue',
                    'desc' => 'Professional & trustworthy',
                    'colors' => [
                        'primary_color'=>'#2563EB','secondary_color'=>'#1E40AF','accent_color'=>'#059669',
                        'button_bg_color'=>'#2563EB','button_text_color'=>'#FFFFFF','button_hover_color'=>'#1D4ED8',
                        'checkout_btn_color'=>'#1D4ED8','checkout_btn_text'=>'#FFFFFF',
                        'btn_cart_color'=>'#0891B2','btn_cart_text'=>'#FFFFFF',
                        'price_color'=>'#2563EB','old_price_color'=>'#94A3B8',
                        'sale_badge_bg'=>'#DC2626','sale_badge_text'=>'#FFFFFF',
                        'topbar_bg_color'=>'#1E3A5F','topbar_text_color'=>'#E0F2FE',
                        'navbar_bg_color'=>'#FFFFFF','navbar_text_color'=>'#0F172A',
                        'footer_bg_color'=>'#0F172A','footer_text_color'=>'#94A3B8','footer_heading_color'=>'#E2E8F0',
                        'mobile_nav_bg'=>'#FFFFFF','mobile_nav_active'=>'#2563EB',
                    ],
                    'preview' => ['#2563EB','#1E3A5F','#0891B2','#DC2626','#0F172A'],
                ],
                'emerald' => [
                    'name' => 'Emerald Green',
                    'desc' => 'Fresh & natural — health & organic',
                    'colors' => [
                        'primary_color'=>'#059669','secondary_color'=>'#064E3B','accent_color'=>'#D97706',
                        'button_bg_color'=>'#059669','button_text_color'=>'#FFFFFF','button_hover_color'=>'#047857',
                        'checkout_btn_color'=>'#047857','checkout_btn_text'=>'#FFFFFF',
                        'btn_cart_color'=>'#D97706','btn_cart_text'=>'#FFFFFF',
                        'price_color'=>'#059669','old_price_color'=>'#9CA3AF',
                        'sale_badge_bg'=>'#DC2626','sale_badge_text'=>'#FFFFFF',
                        'topbar_bg_color'=>'#064E3B','topbar_text_color'=>'#D1FAE5',
                        'navbar_bg_color'=>'#FFFFFF','navbar_text_color'=>'#1F2937',
                        'footer_bg_color'=>'#022C22','footer_text_color'=>'#A7F3D0','footer_heading_color'=>'#ECFDF5',
                        'mobile_nav_bg'=>'#FFFFFF','mobile_nav_active'=>'#059669',
                    ],
                    'preview' => ['#059669','#064E3B','#D97706','#DC2626','#022C22'],
                ],
                'royal' => [
                    'name' => 'Royal Purple',
                    'desc' => 'Premium & luxurious feel',
                    'colors' => [
                        'primary_color'=>'#7C3AED','secondary_color'=>'#4C1D95','accent_color'=>'#F59E0B',
                        'button_bg_color'=>'#7C3AED','button_text_color'=>'#FFFFFF','button_hover_color'=>'#6D28D9',
                        'checkout_btn_color'=>'#6D28D9','checkout_btn_text'=>'#FFFFFF',
                        'btn_cart_color'=>'#DB2777','btn_cart_text'=>'#FFFFFF',
                        'price_color'=>'#7C3AED','old_price_color'=>'#A1A1AA',
                        'sale_badge_bg'=>'#F59E0B','sale_badge_text'=>'#1C1917',
                        'topbar_bg_color'=>'#2E1065','topbar_text_color'=>'#EDE9FE',
                        'navbar_bg_color'=>'#FFFFFF','navbar_text_color'=>'#18181B',
                        'footer_bg_color'=>'#1C1033','footer_text_color'=>'#C4B5FD','footer_heading_color'=>'#F5F3FF',
                        'mobile_nav_bg'=>'#FFFFFF','mobile_nav_active'=>'#7C3AED',
                    ],
                    'preview' => ['#7C3AED','#2E1065','#DB2777','#F59E0B','#1C1033'],
                ],
                'sunset' => [
                    'name' => 'Sunset Orange',
                    'desc' => 'Energetic & attention-grabbing',
                    'colors' => [
                        'primary_color'=>'#EA580C','secondary_color'=>'#9A3412','accent_color'=>'#0D9488',
                        'button_bg_color'=>'#EA580C','button_text_color'=>'#FFFFFF','button_hover_color'=>'#C2410C',
                        'checkout_btn_color'=>'#C2410C','checkout_btn_text'=>'#FFFFFF',
                        'btn_cart_color'=>'#0D9488','btn_cart_text'=>'#FFFFFF',
                        'price_color'=>'#EA580C','old_price_color'=>'#A8A29E',
                        'sale_badge_bg'=>'#DC2626','sale_badge_text'=>'#FFFFFF',
                        'topbar_bg_color'=>'#431407','topbar_text_color'=>'#FED7AA',
                        'navbar_bg_color'=>'#FFFBEB','navbar_text_color'=>'#292524',
                        'footer_bg_color'=>'#1C1917','footer_text_color'=>'#D6D3D1','footer_heading_color'=>'#FAFAF9',
                        'mobile_nav_bg'=>'#FFFBEB','mobile_nav_active'=>'#EA580C',
                    ],
                    'preview' => ['#EA580C','#431407','#0D9488','#DC2626','#1C1917'],
                ],
            ];
            ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h4 class="font-semibold text-gray-800"><i class="fas fa-swatchbook mr-2 text-indigo-500"></i>Color Presets</h4>
                    <span class="text-xs text-gray-400">Click a preset to apply — you can still customize after</span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3" id="presetGrid">
                    <?php foreach ($colorPresets as $pid => $preset): ?>
                    <button type="button" onclick="applyPreset('<?= $pid ?>')" 
                            class="preset-card group relative rounded-xl border-2 <?= $pid === 'khatibangla' ? 'border-orange-300 bg-orange-50/50 ring-1 ring-orange-200' : 'border-gray-200' ?> hover:border-indigo-400 p-3 text-left transition-all hover:shadow-md active:scale-[0.97]"
                            data-preset="<?= $pid ?>">
                        <?php if ($pid === 'khatibangla'): ?>
                        <span class="absolute -top-2 left-3 bg-orange-500 text-white text-[9px] font-bold px-2 py-0.5 rounded-full shadow-sm">YOUR BRAND</span>
                        <?php endif; ?>
                        <!-- Color preview dots -->
                        <div class="flex gap-1 mb-2.5">
                            <?php foreach ($preset['preview'] as $i => $c): ?>
                            <div class="<?= $i === 0 ? 'w-8 h-8 rounded-lg' : 'w-5 h-5 rounded-md' ?> shadow-sm border border-black/10" style="background:<?= $c ?>"></div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-sm font-bold text-gray-800 leading-tight"><?= $preset['name'] ?></p>
                        <p class="text-[10px] text-gray-400 mt-0.5 leading-snug"><?= $preset['desc'] ?></p>
                        <!-- Active indicator -->
                        <div class="preset-active hidden absolute top-2 right-2 w-5 h-5 bg-indigo-600 rounded-full flex items-center justify-center shadow">
                            <i class="fas fa-check text-white text-[9px]"></i>
                        </div>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <script>
            const COLOR_PRESETS = <?= json_encode(array_map(fn($p) => $p['colors'], $colorPresets)) ?>;
            
            function applyPreset(id) {
                const preset = COLOR_PRESETS[id];
                if (!preset) return;
                
                // Fill all color inputs
                Object.entries(preset).forEach(([key, val]) => {
                    const input = document.querySelector(`input[name="${key}"]`);
                    if (input) {
                        input.value = val;
                        // Update the hex code display
                        const code = input.closest('.flex')?.querySelector('code');
                        if (code) code.textContent = val;
                    }
                });
                
                // Highlight active preset card
                document.querySelectorAll('.preset-card').forEach(c => {
                    c.classList.remove('border-indigo-500','ring-2','ring-indigo-200');
                    c.querySelector('.preset-active')?.classList.add('hidden');
                });
                const card = document.querySelector(`.preset-card[data-preset="${id}"]`);
                if (card) {
                    card.classList.add('border-indigo-500','ring-2','ring-indigo-200');
                    card.querySelector('.preset-active')?.classList.remove('hidden');
                }
                
                // Toast feedback
                const toast = document.createElement('div');
                toast.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 bg-indigo-600 text-white px-5 py-2.5 rounded-xl shadow-lg text-sm font-medium z-50 flex items-center gap-2';
                toast.innerHTML = '<i class="fas fa-palette"></i> Preset applied — click Save to keep changes';
                document.body.appendChild(toast);
                setTimeout(() => { toast.style.opacity='0'; toast.style.transition='opacity .3s'; }, 2500);
                setTimeout(() => toast.remove(), 3000);
            }
            </script>

            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-6">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-palette mr-2 text-purple-500"></i>Color Customization</h4>
                
                <!-- Brand Colors -->
                <div>
                    <h5 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-paint-brush mr-1"></i>Brand Colors</h5>
                    <div class="grid md:grid-cols-2 gap-3">
                    <?php $brandColors = [
                        'primary_color'=>['Primary Color','#E53E3E','Used for links, active states, price highlights'],
                        'secondary_color'=>['Secondary Color','#2D3748','Used for secondary elements'],
                        'accent_color'=>['Accent Color','#38A169','Used for success states, badges'],
                    ];
                    foreach ($brandColors as $key => $cf): ?>
                    <div class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-gray-50 border border-gray-100">
                        <input type="color" name="<?= $key ?>" value="<?= e($s[$key] ?? $cf[1]) ?>" class="w-10 h-10 rounded cursor-pointer border-0 p-0 flex-shrink-0" onchange="this.closest('.flex').querySelector('code').textContent=this.value">
                        <div><label class="block text-sm font-medium text-gray-700"><?= $cf[0] ?></label>
                            <code class="text-xs text-gray-400"><?= e($s[$key] ?? $cf[1]) ?></code>
                            <p class="text-[10px] text-gray-400 mt-0.5"><?= $cf[2] ?></p></div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>

                <!-- Button Colors -->
                <div>
                    <h5 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-mouse-pointer mr-1"></i>Button Colors</h5>
                    <div class="grid md:grid-cols-2 gap-3">
                    <?php $btnColors = [
                        'button_bg_color'=>['Order Button BG','#E53E3E','Product page অর্ডার করুন button'],
                        'button_text_color'=>['Order Button Text','#FFFFFF','Order button text color'],
                        'checkout_btn_color'=>['Checkout Button BG','#E53E3E','Checkout popup confirm button'],
                        'checkout_btn_text'=>['Checkout Button Text','#FFFFFF','Checkout confirm button text'],
                        'button_hover_color'=>['Button Hover','#C53030','All buttons on hover'],
                        'btn_cart_color'=>['Cart Button BG','#DD6B20','কার্টে যোগ করুন button background'],
                        'btn_cart_text'=>['Cart Button Text','#FFFFFF','Cart button text color'],
                    ];
                    foreach ($btnColors as $key => $cf): ?>
                    <div class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-gray-50 border border-gray-100">
                        <input type="color" name="<?= $key ?>" value="<?= e($s[$key] ?? $cf[1]) ?>" class="w-10 h-10 rounded cursor-pointer border-0 p-0 flex-shrink-0" onchange="this.closest('.flex').querySelector('code').textContent=this.value">
                        <div><label class="block text-sm font-medium text-gray-700"><?= $cf[0] ?></label>
                            <code class="text-xs text-gray-400"><?= e($s[$key] ?? $cf[1]) ?></code>
                            <p class="text-[10px] text-gray-400 mt-0.5"><?= $cf[2] ?></p></div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>

                <!-- Product Colors -->
                <div>
                    <h5 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-tag mr-1"></i>Product & Price</h5>
                    <div class="grid md:grid-cols-2 gap-3">
                    <?php $productColors = [
                        'price_color'=>['Price Color','#E53E3E','Current/sale price on cards and product page'],
                        'old_price_color'=>['Old Price Color','#9CA3AF','Strikethrough original price'],
                        'sale_badge_bg'=>['Sale Badge BG','#E53E3E','Discount badge background'],
                        'sale_badge_text'=>['Sale Badge Text','#FFFFFF','Discount badge text'],
                    ];
                    foreach ($productColors as $key => $cf): ?>
                    <div class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-gray-50 border border-gray-100">
                        <input type="color" name="<?= $key ?>" value="<?= e($s[$key] ?? $cf[1]) ?>" class="w-10 h-10 rounded cursor-pointer border-0 p-0 flex-shrink-0" onchange="this.closest('.flex').querySelector('code').textContent=this.value">
                        <div><label class="block text-sm font-medium text-gray-700"><?= $cf[0] ?></label>
                            <code class="text-xs text-gray-400"><?= e($s[$key] ?? $cf[1]) ?></code>
                            <p class="text-[10px] text-gray-400 mt-0.5"><?= $cf[2] ?></p></div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>

                <!-- Header & Nav Colors -->
                <div>
                    <h5 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-window-maximize mr-1"></i>Header & Navigation</h5>
                    <div class="grid md:grid-cols-2 gap-3">
                    <?php $headerColors = [
                        'topbar_bg_color'=>['Top Bar BG','#E53E3E','Announcement bar background'],
                        'topbar_text_color'=>['Top Bar Text','#FFFFFF','Announcement bar text'],
                        'navbar_bg_color'=>['Navbar BG','#FFFFFF','Main header background'],
                        'navbar_text_color'=>['Navbar Text','#1A202C','Main header text and icons'],
                        'mobile_nav_bg'=>['Mobile Nav BG','#FFFFFF','Mobile bottom navigation background'],
                        'mobile_nav_active'=>['Mobile Nav Active','#E53E3E','Active icon in mobile bottom nav'],
                    ];
                    foreach ($headerColors as $key => $cf): ?>
                    <div class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-gray-50 border border-gray-100">
                        <input type="color" name="<?= $key ?>" value="<?= e($s[$key] ?? $cf[1]) ?>" class="w-10 h-10 rounded cursor-pointer border-0 p-0 flex-shrink-0" onchange="this.closest('.flex').querySelector('code').textContent=this.value">
                        <div><label class="block text-sm font-medium text-gray-700"><?= $cf[0] ?></label>
                            <code class="text-xs text-gray-400"><?= e($s[$key] ?? $cf[1]) ?></code>
                            <p class="text-[10px] text-gray-400 mt-0.5"><?= $cf[2] ?></p></div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>

                <!-- Footer Colors -->
                <div>
                    <h5 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3"><i class="fas fa-window-minimize mr-1"></i>Footer</h5>
                    <div class="grid md:grid-cols-2 gap-3">
                    <?php $footerColors = [
                        'footer_bg_color'=>['Footer BG','#1A202C','Footer background color'],
                        'footer_text_color'=>['Footer Text','#E2E8F0','Footer paragraph and link text'],
                        'footer_heading_color'=>['Footer Heading','#FFFFFF','Footer section heading color'],
                    ];
                    foreach ($footerColors as $key => $cf): ?>
                    <div class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-gray-50 border border-gray-100">
                        <input type="color" name="<?= $key ?>" value="<?= e($s[$key] ?? $cf[1]) ?>" class="w-10 h-10 rounded cursor-pointer border-0 p-0 flex-shrink-0" onchange="this.closest('.flex').querySelector('code').textContent=this.value">
                        <div><label class="block text-sm font-medium text-gray-700"><?= $cf[0] ?></label>
                            <code class="text-xs text-gray-400"><?= e($s[$key] ?? $cf[1]) ?></code>
                            <p class="text-[10px] text-gray-400 mt-0.5"><?= $cf[2] ?></p></div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>

            </div>

            <?php elseif ($tab === 'fontsizes'): ?>
            <!-- ═══════ FONT FAMILY PICKER ═══════ -->
            <?php
            $currentFont = $s['site_font_family'] ?? 'Hind Siliguri';
            $currentWeight = $s['site_font_weight'] ?? '400';
            $currentHeadingFont = $s['site_heading_font'] ?? '';
            $currentHeadingWeight = $s['site_heading_weight'] ?? '700';
            $customFonts = json_decode($s['custom_fonts'] ?? '[]', true) ?: [];

            // Font list: [name, google_url, category, bangla, weights[], buggy_weights]
            $fontList = [
                // ── Bangla ──
                ['Hind Siliguri', 'Hind+Siliguri:wght@300;400;500;600;700', 'bangla', true, [300,400,500,600,700], [400,500,600]],
                ['Noto Sans Bengali', 'Noto+Sans+Bengali:wght@300;400;500;600;700', 'bangla', true, [300,400,500,600,700], []],
                ['Noto Serif Bengali', 'Noto+Serif+Bengali:wght@300;400;500;600;700', 'bangla', true, [300,400,500,600,700], []],
                ['Baloo Da 2', 'Baloo+Da+2:wght@400;500;600;700;800', 'bangla', true, [400,500,600,700,800], []],
                ['Galada', 'Galada', 'bangla', true, [400], []],
                ['Atma', 'Atma:wght@300;400;500;600;700', 'bangla', true, [300,400,500,600,700], []],
                ['Mina', 'Mina:wght@400;700', 'bangla', true, [400,700], []],
                ['Anek Bangla', 'Anek+Bangla:wght@300;400;500;600;700', 'bangla', true, [300,400,500,600,700], []],
                ['Tiro Bangla', 'Tiro+Bangla:ital@0;1', 'bangla', true, [400], []],
                // ── Sans-Serif ──
                ['Inter', 'Inter:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Poppins', 'Poppins:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Roboto', 'Roboto:wght@300;400;500;700;900', 'sans', false, [300,400,500,700,900], []],
                ['Open Sans', 'Open+Sans:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Nunito', 'Nunito:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Nunito Sans', 'Nunito+Sans:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Lato', 'Lato:wght@300;400;700;900', 'sans', false, [300,400,700,900], []],
                ['Montserrat', 'Montserrat:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Raleway', 'Raleway:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Rubik', 'Rubik:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Work Sans', 'Work+Sans:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['DM Sans', 'DM+Sans:wght@400;500;700', 'sans', false, [400,500,700], []],
                ['Plus Jakarta Sans', 'Plus+Jakarta+Sans:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Outfit', 'Outfit:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Figtree', 'Figtree:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Manrope', 'Manrope:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Source Sans 3', 'Source+Sans+3:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Quicksand', 'Quicksand:wght@300;400;500;600;700', 'sans', false, [300,400,500,600,700], []],
                ['Lexend', 'Lexend:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Sora', 'Sora:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                ['Space Grotesk', 'Space+Grotesk:wght@300;400;500;600;700', 'sans', false, [300,400,500,600,700], []],
                ['Albert Sans', 'Albert+Sans:wght@300;400;500;600;700;800', 'sans', false, [300,400,500,600,700,800], []],
                // ── Serif ──
                ['Playfair Display', 'Playfair+Display:wght@400;500;600;700;800', 'serif', false, [400,500,600,700,800], []],
                ['Merriweather', 'Merriweather:wght@300;400;700;900', 'serif', false, [300,400,700,900], []],
                ['Lora', 'Lora:wght@400;500;600;700', 'serif', false, [400,500,600,700], []],
                ['PT Serif', 'PT+Serif:wght@400;700', 'serif', false, [400,700], []],
                ['Crimson Text', 'Crimson+Text:wght@400;600;700', 'serif', false, [400,600,700], []],
                ['Libre Baskerville', 'Libre+Baskerville:wght@400;700', 'serif', false, [400,700], []],
                ['Source Serif 4', 'Source+Serif+4:wght@300;400;500;600;700', 'serif', false, [300,400,500,600,700], []],
                ['DM Serif Display', 'DM+Serif+Display', 'serif', false, [400], []],
                // ── Display ──
                ['Oswald', 'Oswald:wght@300;400;500;600;700', 'display', false, [300,400,500,600,700], []],
                ['Bebas Neue', 'Bebas+Neue', 'display', false, [400], []],
                ['Anton', 'Anton', 'display', false, [400], []],
                ['Righteous', 'Righteous', 'display', false, [400], []],
                ['Archivo Black', 'Archivo+Black', 'display', false, [400], []],
                ['Barlow Condensed', 'Barlow+Condensed:wght@400;500;600;700;800', 'display', false, [400,500,600,700,800], []],
                // ── Rounded ──
                ['Comfortaa', 'Comfortaa:wght@300;400;500;600;700', 'rounded', false, [300,400,500,600,700], []],
                ['Varela Round', 'Varela+Round', 'rounded', false, [400], []],
                ['Fredoka', 'Fredoka:wght@300;400;500;600;700', 'rounded', false, [300,400,500,600,700], []],
                // ── Mono ──
                ['JetBrains Mono', 'JetBrains+Mono:wght@300;400;500;600;700', 'mono', false, [300,400,500,600,700], []],
                ['Fira Code', 'Fira+Code:wght@300;400;500;600;700', 'mono', false, [300,400,500,600,700], []],
                // ── System ──
                ['Arial', '', 'system', false, [400,700], []],
                ['Georgia', '', 'system', false, [400,700], []],
                ['Verdana', '', 'system', false, [400,700], []],
                ['Tahoma', '', 'system', false, [400,700], []],
                ['Segoe UI', '', 'system', false, [300,400,600,700], []],
                ['system-ui', '', 'system', false, [300,400,500,600,700], []],
            ];
            $catMeta = [
                'bangla'  => ['Bangla', 'fas fa-globe-asia', 'green'],
                'sans'    => ['Sans-Serif', 'fas fa-font', 'blue'],
                'serif'   => ['Serif', 'fas fa-feather-alt', 'purple'],
                'display' => ['Display', 'fas fa-heading', 'red'],
                'rounded' => ['Rounded', 'fas fa-circle', 'pink'],
                'mono'    => ['Monospace', 'fas fa-code', 'gray'],
                'system'  => ['System', 'fas fa-desktop', 'gray'],
                'custom'  => ['Custom', 'fas fa-upload', 'orange'],
            ];
            $weightLabels = [100=>'Thin',200=>'ExtraLight',300=>'Light',400=>'Regular',500=>'Medium',600=>'SemiBold',700=>'Bold',800=>'ExtraBold',900=>'Black'];

            // Merge custom fonts
            $allFonts = $fontList;
            foreach ($customFonts as $ci => $cf) {
                $allFonts[] = [$cf['name'], '__custom__' . $ci, 'custom', false, $cf['weights'] ?? [400,700], []];
            }
            ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h4 class="font-semibold text-gray-800"><i class="fas fa-font mr-2 text-purple-500"></i>Font Family</h4>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-400">Current:</span>
                        <strong class="text-xs text-purple-700 bg-purple-50 px-2 py-0.5 rounded-full" id="currentFontLabel"><?= e($currentFont) ?> (<?= e($weightLabels[(int)$currentWeight] ?? $currentWeight) ?>)</strong>
                    </div>
                </div>

                <!-- Category Filter -->
                <div class="flex flex-wrap gap-1.5" id="fontCatFilters">
                    <button type="button" onclick="filterFonts('all')" class="fcat-btn active" data-cat="all">
                        <i class="fas fa-th-large mr-1"></i>All (<?= count($allFonts) ?>)
                    </button>
                    <?php foreach ($catMeta as $ck => $cv):
                        $cc = 0;
                        if ($ck === 'custom') { $cc = count($customFonts); }
                        else { foreach ($fontList as $fl) { if ($fl[2] === $ck) $cc++; } }
                        if ($cc === 0 && $ck !== 'custom') continue;
                    ?>
                    <button type="button" onclick="filterFonts('<?= $ck ?>')" class="fcat-btn" data-cat="<?= $ck ?>">
                        <i class="<?= $cv[1] ?> mr-1"></i><?= $cv[0] ?> (<?= $cc ?>)
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Search -->
                <input type="text" id="fontSearchInput" placeholder="Search fonts..." oninput="searchFonts(this.value)"
                       class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-300">

                <!-- Hidden Inputs -->
                <input type="hidden" name="site_font_family" id="siteFontFamily" value="<?= e($currentFont) ?>">
                <input type="hidden" name="site_font_url" id="siteFontUrl" value="<?= e($s['site_font_url'] ?? '') ?>">
                <input type="hidden" name="site_font_weight" id="siteFontWeight" value="<?= e($currentWeight) ?>">

                <!-- Font Grid -->
                <div class="space-y-2 max-h-[540px] overflow-y-auto pr-1" id="fontGrid" style="scrollbar-width:thin">
                    <?php foreach ($allFonts as $fi => $font):
                        $fname = $font[0]; $furl = $font[1]; $fcat = $font[2]; $fbn = $font[3]; $fweights = $font[4]; $fbuggy = $font[5] ?? [];
                        $isSelected = ($currentFont === $fname);
                        $isCustom = (strpos($furl, '__custom__') === 0);
                        $fallback = in_array($fcat, ['serif','display']) ? 'serif' : ($fcat === 'mono' ? 'monospace' : 'sans-serif');
                    ?>
                    <div class="font-card border rounded-xl overflow-hidden cursor-pointer transition-all hover:border-purple-300 hover:shadow-sm <?= $isSelected ? 'ring-2 ring-purple-500 border-purple-400 bg-purple-50/50' : 'border-gray-200' ?>"
                         data-font="<?= e($fname) ?>" data-url="<?= e($furl) ?>" data-cat="<?= $fcat ?>"
                         data-weights='<?= json_encode($fweights) ?>'
                         data-buggy='<?= json_encode($fbuggy) ?>'
                         onclick="selectFont(this)">
                        <!-- Top Row -->
                        <div class="flex items-center justify-between px-3 pt-2.5 pb-1">
                            <div class="flex items-center gap-2">
                                <span class="font-card-check w-5 h-5 rounded-full border-2 flex items-center justify-center text-[10px] flex-shrink-0 transition <?= $isSelected ? 'bg-purple-500 border-purple-500 text-white' : 'border-gray-300' ?>">
                                    <?= $isSelected ? '<i class="fas fa-check"></i>' : '' ?>
                                </span>
                                <span class="text-sm font-semibold text-gray-800"><?= e($fname) ?></span>
                                <?php if ($fbn): ?><span class="text-[10px] px-1.5 py-0.5 bg-green-100 text-green-700 rounded font-medium">BN</span><?php endif; ?>
                                <?php if (!empty($fbuggy)): ?><span class="text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded font-medium" title="Bengali ১ broken at <?= implode('/', $fbuggy) ?>">⚠ Bug</span><?php endif; ?>
                                <?php if ($isCustom): ?><span class="text-[10px] px-1.5 py-0.5 bg-orange-100 text-orange-700 rounded font-medium">Custom</span><?php endif; ?>
                            </div>
                            <span class="text-[10px] text-gray-400"><?= count($fweights) ?> weight<?= count($fweights) > 1 ? 's' : '' ?></span>
                        </div>
                        <!-- Preview -->
                        <div class="px-3 pb-1.5">
                            <p class="font-preview text-lg text-gray-600 truncate" data-gurl="<?= e($isCustom ? '' : $furl) ?>"
                               style="font-family:'<?= e($fname) ?>',<?= $fallback ?>">
                                <?= $fbn ? 'বাংলা প্রিভিউ — ৳১,২৩৪ ' : '' ?>The quick fox ৳0123456789
                            </p>
                        </div>
                        <!-- Weight Variations (shown when selected) -->
                        <div class="font-weights px-3 pb-2.5 flex flex-wrap gap-1 <?= $isSelected ? '' : 'hidden' ?>">
                            <?php foreach ($fweights as $w): ?>
                            <?php $isBuggyW = in_array($w, $fbuggy); ?>
                            <button type="button"
                                    class="wt-btn px-2 py-0.5 text-[11px] rounded border transition <?= ($isSelected && (int)$currentWeight === $w) ? 'bg-purple-600 text-white border-purple-600' : ($isBuggyW ? 'border-amber-300 text-amber-600 bg-amber-50' : 'border-gray-300 text-gray-600 hover:border-purple-400') ?>"
                                    style="font-family:'<?= e($fname) ?>',<?= $fallback ?>;font-weight:<?= $w ?>"
                                    data-w="<?= $w ?>"
                                    onclick="event.stopPropagation();selectWeight(this, <?= $w ?>)"
                                    <?= $isBuggyW ? 'title="⚠ Known bug: Bengali ১ renders incorrectly at this weight"' : '' ?>>
                                <?= $isBuggyW ? '<i class="fas fa-exclamation-triangle text-amber-500 mr-0.5"></i>' : '' ?><?= $weightLabels[$w] ?? $w ?> <small class="opacity-60">(<?= $w ?>)</small>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ═══════ CUSTOM FONT UPLOAD ═══════ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-upload mr-2 text-orange-500"></i>Upload Custom Font</h4>
                <p class="text-xs text-gray-500">Upload .woff2, .woff, .ttf, or .otf files (max 5MB). After upload, your font appears in the "Custom" category above.</p>
                <div class="grid md:grid-cols-3 gap-3 items-end">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Font Name</label>
                        <input type="text" name="custom_font_name" placeholder="e.g. My Brand Font"
                               class="w-full px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-orange-300">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Font File</label>
                        <input type="file" name="custom_font_file" accept=".woff2,.woff,.ttf,.otf"
                               class="w-full px-3 py-1.5 border rounded-lg text-sm file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:bg-orange-50 file:text-orange-700 file:font-medium file:cursor-pointer">
                    </div>
                    <button type="submit" name="action" value="upload_font" class="px-4 py-2 bg-orange-500 text-white rounded-lg text-sm font-semibold hover:bg-orange-600 transition">
                        <i class="fas fa-upload mr-1"></i>Upload Font
                    </button>
                </div>
                <?php if (!empty($customFonts)): ?>
                <div class="border-t pt-3 mt-2">
                    <h5 class="text-xs font-semibold text-gray-500 uppercase mb-2">Uploaded Fonts</h5>
                    <div class="space-y-2">
                        <?php foreach ($customFonts as $ci => $cf): ?>
                        <div class="flex items-center justify-between p-2.5 bg-orange-50 rounded-lg border border-orange-100">
                            <div class="flex items-center gap-3">
                                <i class="fas fa-font text-orange-500"></i>
                                <div>
                                    <span class="text-sm font-semibold text-gray-800"><?= e($cf['name']) ?></span>
                                    <span class="text-xs text-gray-400 ml-2"><?= e($cf['file']) ?> &middot; <?= strtoupper($cf['format'] ?? 'woff2') ?></span>
                                </div>
                            </div>
                            <button type="submit" name="delete_custom_font" value="<?= $ci ?>"
                                    onclick="return confirm('Delete this font?')"
                                    class="text-xs text-red-500 hover:text-red-700 font-medium px-2 py-1 rounded hover:bg-red-50">
                                <i class="fas fa-trash mr-1"></i>Delete
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ═══════ HEADING FONT (Optional) ═══════ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <div class="flex items-center gap-2">
                    <i class="fas fa-heading text-indigo-400"></i>
                    <h4 class="font-semibold text-gray-800">Heading Font <span class="text-xs text-gray-400 font-normal">(optional — leave blank to use body font for headings)</span></h4>
                </div>
                <input type="hidden" name="site_heading_font" id="siteHeadingFont" value="<?= e($currentHeadingFont) ?>">
                <input type="hidden" name="site_heading_font_url" id="siteHeadingFontUrl" value="<?= e($s['site_heading_font_url'] ?? '') ?>">
                <input type="hidden" name="site_heading_weight" id="siteHeadingWeight" value="<?= e($currentHeadingWeight) ?>">
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 max-h-52 overflow-y-auto" style="scrollbar-width:thin">
                    <div class="hfont-card border rounded-lg p-2.5 cursor-pointer text-center transition <?= empty($currentHeadingFont) ? 'ring-2 ring-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-indigo-300' ?>"
                         data-font="" data-url="" onclick="selectHeadingFont(this)">
                        <span class="text-xs font-medium text-gray-500"><i class="fas fa-equals mr-1"></i>Same as body</span>
                    </div>
                    <?php foreach ($allFonts as $font):
                        $fname = $font[0]; $furl = $font[1]; $fcat = $font[2];
                        $isCustomH = (strpos($furl, '__custom__') === 0);
                        if ($fcat === 'system' && !$isCustomH) continue;
                        $fallback = in_array($fcat, ['serif','display']) ? 'serif' : 'sans-serif';
                        $isSelH = ($currentHeadingFont === $fname);
                    ?>
                    <div class="hfont-card border rounded-lg p-2.5 cursor-pointer text-center transition <?= $isSelH ? 'ring-2 ring-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-indigo-300' ?>"
                         data-font="<?= e($fname) ?>" data-url="<?= e($isCustomH ? '' : $furl) ?>" onclick="selectHeadingFont(this)">
                        <span class="text-xs font-semibold" style="font-family:'<?= e($fname) ?>',<?= $fallback ?>"><?= e($fname) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ═══════ LIVE PREVIEW ═══════ -->
            <div id="buggyWeightWarn" class="hidden text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                <i class="fas fa-exclamation-triangle text-amber-500 mr-1"></i>
                <strong>Note:</strong> Hind Siliguri has a known bug with Bengali numeral ১ at weights 400/500/600. 
                Use <strong>300 (Light)</strong> or <strong>700 (Bold)</strong> for clean Bengali digits. 
                Latin numerals (0-9) are automatically rendered with Inter font for crisp display.
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-3">
                <h5 class="text-xs font-semibold text-gray-500 uppercase tracking-wide"><i class="fas fa-eye mr-1"></i>Live Preview</h5>
                <div class="border rounded-xl p-5 bg-gradient-to-br from-gray-50 to-white" id="fontLivePreview">
                    <h2 id="previewHeading" class="text-2xl font-bold text-gray-800 mb-2"
                        style="font-family:'<?= e($currentHeadingFont ?: $currentFont) ?>',sans-serif;font-weight:<?= e($currentHeadingWeight) ?>">
                        Premium Quality Products — প্রিমিয়াম মানের পণ্য
                    </h2>
                    <p id="previewBody" class="text-base text-gray-600 mb-3"
                       style="font-family:'<?= e($currentFont) ?>',sans-serif;font-weight:<?= e($currentWeight) ?>">
                        আমাদের দোকানে সেরা মানের পণ্য পাবেন। The quick brown fox jumps over the lazy dog.
                    </p>
                    <p id="previewBnDigits" class="text-base text-gray-700 mb-3"
                       style="font-family:'<?= e($currentFont) ?>',sans-serif;font-weight:<?= e($currentWeight) ?>">
                        <span class="text-xs text-gray-400 mr-2">Bengali Digits:</span>
                        ০ ১ ২ ৩ ৪ ৫ ৬ ৭ ৮ ৯ &nbsp; ১২৩৪৫ &nbsp; ৳১,২৯৯
                    </p>
                    <div class="flex gap-3 items-center flex-wrap">
                        <span id="previewPrice" class="text-xl font-bold text-red-600" style="font-family:'<?= e($currentFont) ?>',sans-serif">৳1,299</span>
                        <span class="text-sm text-gray-400 line-through" style="font-family:'<?= e($currentFont) ?>',sans-serif">৳1,999</span>
                        <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-white" id="previewBtn"
                                style="background:var(--primary,#e53e3e);font-family:'<?= e($currentFont) ?>',sans-serif;font-weight:<?= e($currentWeight) ?>">
                            অর্ডার করুন / Order Now
                        </button>
                    </div>
                </div>
                <!-- Weight preview row -->
                <div class="flex flex-wrap gap-2" id="weightPreviewRow">
                    <span class="text-xs text-gray-400">Weight preview:</span>
                    <span id="wp300" class="text-sm text-gray-600" style="font-family:'<?= e($currentFont) ?>',sans-serif;font-weight:300">Light 300</span>
                    <span id="wp400" class="text-sm text-gray-600" style="font-family:'<?= e($currentFont) ?>',sans-serif;font-weight:400">Regular 400</span>
                    <span id="wp500" class="text-sm text-gray-600" style="font-family:'<?= e($currentFont) ?>',sans-serif;font-weight:500">Medium 500</span>
                    <span id="wp600" class="text-sm text-gray-600" style="font-family:'<?= e($currentFont) ?>',sans-serif;font-weight:600">SemiBold 600</span>
                    <span id="wp700" class="text-sm text-gray-600" style="font-family:'<?= e($currentFont) ?>',sans-serif;font-weight:700">Bold 700</span>
                    <span id="wp800" class="text-sm text-gray-600" style="font-family:'<?= e($currentFont) ?>',sans-serif;font-weight:800">ExtraBold 800</span>
                </div>
            </div>

            <!-- ═══════ TYPOGRAPHY CSS & JS ═══════ -->
            <style>
            .fcat-btn{background:#f9fafb;color:#6b7280;border:1.5px solid #e5e7eb;padding:4px 10px;border-radius:9999px;font-size:11px;font-weight:600;transition:all .2s;cursor:pointer}
            .fcat-btn.active{background:#7c3aed;color:#fff;border-color:#7c3aed}
            .fcat-btn:hover:not(.active){background:#f3f0ff;border-color:#c4b5fd;color:#6d28d9}
            </style>
            <?php
            // Inject @font-face for custom fonts
            if (!empty($customFonts)):
            ?>
            <style>
            <?php foreach ($customFonts as $cf):
                $cfUrl = '<?= SITE_URL ?>/uploads/' . $cf['file'];
                $fmt = $cf['format'] ?? 'woff2';
                $fmtMap = ['woff2'=>'woff2','woff'=>'woff','ttf'=>'truetype','otf'=>'opentype'];
                $fmtStr = $fmtMap[$fmt] ?? 'woff2';
            ?>
            @font-face {
                font-family: '<?= e($cf['name']) ?>';
                src: url('<?= htmlspecialchars(SITE_URL . '/uploads/' . $cf['file']) ?>') format('<?= $fmtStr ?>');
                font-display: swap;
            }
            <?php endforeach; ?>
            </style>
            <?php endif; ?>
            <script>
            const _loadedFonts = new Set();
            function loadGFont(name, url) {
                if (!url || url.startsWith('__custom__') || _loadedFonts.has(name)) return;
                const lnk = document.createElement('link');
                lnk.rel = 'stylesheet';
                lnk.href = 'https://fonts.googleapis.com/css2?family=' + url + '&display=swap';
                document.head.appendChild(lnk);
                _loadedFonts.add(name);
            }
            // Lazy-load fonts on scroll
            const fObs = new IntersectionObserver((entries) => {
                entries.forEach(e => { if (e.isIntersecting) {
                    const p = e.target.querySelector('.font-preview');
                    if (p && p.dataset.gurl) loadGFont(e.target.dataset.font, p.dataset.gurl);
                    fObs.unobserve(e.target);
                }});
            }, { rootMargin: '200px' });
            document.querySelectorAll('.font-card').forEach(c => fObs.observe(c));

            function selectFont(card) {
                const font = card.dataset.font, url = card.dataset.url;
                if (url && !url.startsWith('__custom__')) loadGFont(font, url);
                document.getElementById('siteFontFamily').value = font;
                document.getElementById('siteFontUrl').value = (url && !url.startsWith('__custom__')) ? url : '';
                // Visual update
                document.querySelectorAll('.font-card').forEach(c => {
                    c.classList.remove('ring-2','ring-purple-500','border-purple-400','bg-purple-50/50');
                    c.classList.add('border-gray-200');
                    const ck = c.querySelector('.font-card-check');
                    ck.className = 'font-card-check w-5 h-5 rounded-full border-2 flex items-center justify-center text-[10px] flex-shrink-0 transition border-gray-300';
                    ck.innerHTML = '';
                    c.querySelector('.font-weights')?.classList.add('hidden');
                });
                card.classList.add('ring-2','ring-purple-500','border-purple-400','bg-purple-50/50');
                card.classList.remove('border-gray-200');
                const ck = card.querySelector('.font-card-check');
                ck.className = 'font-card-check w-5 h-5 rounded-full border-2 flex items-center justify-center text-[10px] flex-shrink-0 transition bg-purple-500 border-purple-500 text-white';
                ck.innerHTML = '<i class="fas fa-check"></i>';
                card.querySelector('.font-weights')?.classList.remove('hidden');
                // Auto-select 400 weight or first available
                const btn400 = card.querySelector('.wt-btn[data-w="400"]') || card.querySelector('.wt-btn');
                if (btn400) selectWeight(btn400, parseInt(btn400.dataset.w) || 400);
                updatePreview();
                updateLabel();
            }

            function selectWeight(btn, w) {
                if (!btn) return;
                const card = btn.closest('.font-card');
                // Reset all weight buttons
                card.querySelectorAll('.wt-btn').forEach(b => {
                    const buggy = JSON.parse(card.dataset.buggy || '[]');
                    const bw = parseInt(b.dataset.w);
                    if (buggy.includes(bw)) {
                        b.className = 'wt-btn px-2 py-0.5 text-[11px] rounded border transition border-amber-300 text-amber-600 bg-amber-50';
                    } else {
                        b.className = 'wt-btn px-2 py-0.5 text-[11px] rounded border transition border-gray-300 text-gray-600 hover:border-purple-400';
                    }
                });
                btn.classList.add('bg-purple-600','text-white','border-purple-600');
                btn.classList.remove('border-gray-300','text-gray-600','border-amber-300','text-amber-600','bg-amber-50');
                document.getElementById('siteFontWeight').value = w;
                // Show warning for buggy weights
                const buggyWeights = JSON.parse(card.dataset.buggy || '[]');
                const warnEl = document.getElementById('buggyWeightWarn');
                if (warnEl) {
                    if (buggyWeights.includes(w)) {
                        warnEl.classList.remove('hidden');
                        warnEl.innerHTML = '<i class="fas fa-exclamation-triangle text-amber-500 mr-1"></i>' +
                            '<strong>Note:</strong> Bengali digit ১ may render incorrectly at weight ' + w + ' in ' + card.dataset.font + '. ' +
                            'Use <strong>300 (Light)</strong> or <strong>700 (Bold)</strong> for clean Bengali digits. ' +
                            'Latin numerals (0-9) are automatically rendered with Inter for crisp display.';
                    } else {
                        warnEl.classList.add('hidden');
                    }
                }
                updatePreview();
                updateLabel();
            }

            const WL = {100:'Thin',200:'ExtraLight',300:'Light',400:'Regular',500:'Medium',600:'SemiBold',700:'Bold',800:'ExtraBold',900:'Black'};
            function updateLabel() {
                const f = document.getElementById('siteFontFamily').value;
                const w = document.getElementById('siteFontWeight').value;
                document.getElementById('currentFontLabel').textContent = f + ' (' + (WL[w]||w) + ')';
            }

            function updatePreview() {
                const f = document.getElementById('siteFontFamily').value;
                const w = document.getElementById('siteFontWeight').value;
                const hf = document.getElementById('siteHeadingFont').value || f;
                const hw = document.getElementById('siteHeadingWeight').value || '700';
                const ff = "'" + f + "', sans-serif";
                const hff = "'" + hf + "', sans-serif";
                const ph = document.getElementById('previewHeading');
                if (ph) { ph.style.fontFamily = hff; ph.style.fontWeight = hw; }
                ['previewBody','previewBnDigits','previewPrice','previewBtn'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) { el.style.fontFamily = ff; el.style.fontWeight = w; }
                });
                // Update weight preview row
                [300,400,500,600,700,800].forEach(ww => {
                    const el = document.getElementById('wp' + ww);
                    if (el) el.style.fontFamily = ff;
                });
            }

            function selectHeadingFont(card) {
                const font = card.dataset.font, url = card.dataset.url;
                if (url) loadGFont(font, url);
                document.getElementById('siteHeadingFont').value = font;
                document.getElementById('siteHeadingFontUrl').value = url || '';
                document.querySelectorAll('.hfont-card').forEach(c => {
                    c.classList.remove('ring-2','ring-indigo-500','bg-indigo-50');
                    c.classList.add('border-gray-200');
                });
                card.classList.add('ring-2','ring-indigo-500','bg-indigo-50');
                card.classList.remove('border-gray-200');
                updatePreview();
            }

            function filterFonts(cat) {
                document.querySelectorAll('.fcat-btn').forEach(b => b.classList.toggle('active', b.dataset.cat === cat));
                document.querySelectorAll('.font-card').forEach(c => {
                    c.style.display = (cat === 'all' || c.dataset.cat === cat) ? '' : 'none';
                });
            }
            function searchFonts(q) {
                q = q.toLowerCase().trim();
                document.querySelectorAll('.font-card').forEach(c => {
                    c.style.display = (!q || c.dataset.font.toLowerCase().includes(q) || c.dataset.cat.includes(q)) ? '' : 'none';
                });
            }
            </script>

            <!-- ═══════ FONT SIZES ═══════ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <div class="flex items-center justify-between">
                    <h4 class="font-semibold text-gray-800"><i class="fas fa-text-height mr-2 text-indigo-500"></i>Website Font Sizes</h4>
                    <button type="button" onclick="resetFontSizes()" class="text-xs text-red-500 hover:text-red-700 font-medium"><i class="fas fa-undo mr-1"></i>Reset to Default</button>
                </div>
                <p class="text-xs text-gray-500">Control text sizes across your website. Values in pixels (px). Changes apply instantly after saving.</p>

                <?php
                $fontSizeGroups = [
                    'Header & Navigation' => [
                        'fs_announcement'     => ['Announcement Bar', '13', 'Top bar text (phone, hotline)'],
                        'fs_nav_menu'         => ['Navigation Menu', '14', 'Category/menu links in navbar'],
                        'fs_mobile_menu'      => ['Mobile Menu Items', '15', 'Side drawer menu links'],
                        'fs_search_input'     => ['Search Input', '14', 'Search box placeholder & text'],
                    ],
                    'Home Page Sections' => [
                        'fs_section_heading'  => ['Section Headings', '22', 'Sale, Featured, All Products titles'],
                        'fs_section_link'     => ['Section "View All" Link', '14', '"আরো দেখুন" type links'],
                        'fs_banner_title'     => ['Banner Title', '28', 'Hero slider overlay text'],
                        'fs_banner_subtitle'  => ['Banner Subtitle', '16', 'Hero slider subtitle text'],
                        'fs_category_name'    => ['Category Names', '12', 'Circle category labels'],
                        'fs_trust_title'      => ['Trust Badge Title', '14', 'Trust section headings'],
                        'fs_trust_subtitle'   => ['Trust Badge Subtitle', '12', 'Trust section descriptions'],
                    ],
                    'Product Card' => [
                        'fs_card_name'        => ['Product Name', '14', 'Product title on cards'],
                        'fs_card_price'       => ['Product Price', '16', 'Current price on cards'],
                        'fs_card_old_price'   => ['Old/Strike Price', '12', 'Crossed-out price on cards'],
                        'fs_card_badge'       => ['Discount Badge', '12', '"-20%" badge on cards'],
                        'fs_card_button'      => ['Card Buttons', '13', 'Add to Cart / Order buttons'],
                    ],
                    'Product Detail Page' => [
                        'fs_product_title'    => ['Product Title', '26', 'Main title on product page'],
                        'fs_product_price'    => ['Product Price', '30', 'Price on product page'],
                        'fs_product_desc'     => ['Description Text', '15', 'Product description body'],
                        'fs_order_button'     => ['Order Button', '16', 'COD Order / Cart button text'],
                    ],
                    'Footer' => [
                        'fs_footer_heading'   => ['Footer Headings', '20', 'Column titles in footer'],
                        'fs_footer_text'      => ['Footer Body Text', '14', 'Footer links, about text'],
                        'fs_footer_copyright' => ['Copyright Text', '14', 'Bottom bar copyright'],
                    ],
                    'Global / Body' => [
                        'fs_body'             => ['Body Base Font', '15', 'Default text size sitewide'],
                        'fs_button_global'    => ['Global Button Text', '14', 'Default button label size'],
                        'fs_price_global'     => ['Price Text (Global)', '16', 'Prices across entire site'],
                    ],
                ];
                foreach ($fontSizeGroups as $groupName => $fields): ?>
                <div class="border border-gray-100 rounded-xl overflow-hidden">
                    <div class="bg-gray-50 px-4 py-2.5 border-b border-gray-100">
                        <h5 class="text-sm font-semibold text-gray-700"><?= $groupName ?></h5>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <?php foreach ($fields as $key => $info):
                            $curVal = $s[$key] ?? $info[1];
                        ?>
                        <div class="flex items-center justify-between px-4 py-3 hover:bg-gray-50 transition">
                            <div class="flex-1 min-w-0">
                                <label class="block text-sm font-medium text-gray-700"><?= $info[0] ?></label>
                                <span class="text-xs text-gray-400"><?= $info[2] ?></span>
                            </div>
                            <div class="flex items-center gap-2 ml-4">
                                <button type="button" onclick="adjustFs('<?= $key ?>', -1)" class="w-7 h-7 rounded-lg bg-gray-100 hover:bg-gray-200 flex items-center justify-center text-gray-600 text-xs font-bold transition">−</button>
                                <input type="number" name="<?= $key ?>" id="fs_<?= $key ?>" value="<?= e($curVal) ?>" min="8" max="60"
                                       class="w-16 text-center border border-gray-200 rounded-lg py-1.5 text-sm font-mono font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-300 fs-input"
                                       data-default="<?= $info[1] ?>">
                                <button type="button" onclick="adjustFs('<?= $key ?>', 1)" class="w-7 h-7 rounded-lg bg-gray-100 hover:bg-gray-200 flex items-center justify-center text-gray-600 text-xs font-bold transition">+</button>
                                <span class="text-xs text-gray-400 w-6">px</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <script>
            function adjustFs(key, delta) {
                const inp = document.getElementById('fs_' + key);
                if (!inp) return;
                let v = parseInt(inp.value) || 14;
                v = Math.max(8, Math.min(60, v + delta));
                inp.value = v;
                const def = parseInt(inp.dataset.default) || 14;
                inp.classList.toggle('ring-2', v !== def);
                inp.classList.toggle('ring-indigo-400', v !== def);
            }
            function resetFontSizes() {
                if (!confirm('Reset all font sizes to defaults?')) return;
                document.querySelectorAll('.fs-input').forEach(inp => {
                    inp.value = inp.dataset.default;
                    inp.classList.remove('ring-2', 'ring-indigo-400');
                });
            }
            </script>


            <?php elseif ($tab === 'header'): ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-bullhorn mr-2 text-yellow-500"></i>Top Bar</h4>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Announcement Text</label>
                    <input type="text" name="announcement_content" value="<?= e($s['announcement_content'] ?? $s['announcement_text'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="Free delivery on orders over ৳1000!"></div>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Header Phone</label>
                        <input type="text" name="header_phone" value="<?= e($s['header_phone'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Hotline</label>
                        <input type="text" name="hotline_number" value="<?= e($s['hotline_number'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                </div>
            </div>

            <!-- Main Header Style -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-palette mr-2 text-indigo-500"></i>Header Style</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Header Background Style</label>
                        <select name="header_bg_style" class="w-full px-3 py-2.5 border rounded-lg text-sm" onchange="toggleHeaderStyle(this.value)">
                            <option value="solid" <?= ($s['header_bg_style'] ?? 'solid') === 'solid' ? 'selected' : '' ?>>Solid Color</option>
                            <option value="glass" <?= ($s['header_bg_style'] ?? 'solid') === 'glass' ? 'selected' : '' ?>>Glass Effect (Frosted)</option>
                        </select>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Logo Max Height (px)</label>
                        <input type="number" name="logo_max_height" value="<?= e($s['logo_max_height'] ?? '50') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                </div>
                <div id="header-glass-options" class="grid md:grid-cols-2 gap-4 <?= ($s['header_bg_style'] ?? 'solid') !== 'glass' ? 'hidden' : '' ?>">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Glass Opacity (0-100)</label>
                        <input type="number" name="header_glass_opacity" value="<?= e($s['header_glass_opacity'] ?? '85') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" min="0" max="100"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Glass Blur (px)</label>
                        <input type="number" name="header_glass_blur" value="<?= e($s['header_glass_blur'] ?? '12') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" min="0" max="50"></div>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Header Height Desktop (px)</label>
                        <input type="number" name="header_height_desktop" value="<?= e($s['header_height_desktop'] ?? '80') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" min="50" max="120"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Header Height Mobile (px)</label>
                        <input type="number" name="header_height_mobile" value="<?= e($s['header_height_mobile'] ?? '64') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" min="40" max="100"></div>
                </div>
                <!-- Show/Hide Elements -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Header Elements</label>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="header_show_search" value="1" <?= ($s['header_show_search'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded text-blue-600"><span>Search Bar</span></label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="header_show_login" value="1" <?= ($s['header_show_login'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded text-blue-600"><span>Login/Account</span></label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="header_show_wishlist" value="1" <?= ($s['header_show_wishlist'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded text-blue-600"><span>Wishlist</span></label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="header_show_whatsapp" value="1" <?= ($s['header_show_whatsapp'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded text-blue-600"><span>WhatsApp</span></label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="header_show_cart" value="1" <?= ($s['header_show_cart'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded text-blue-600"><span>Cart Icon</span></label>
                    </div>
                </div>
            </div>

            <!-- Category Nav Bar Style -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-bars mr-2 text-blue-500"></i>Category Navigation Bar</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nav Bar Background Style</label>
                        <select name="navbar_bg_style" class="w-full px-3 py-2.5 border rounded-lg text-sm" onchange="toggleNavStyle(this.value)">
                            <option value="solid" <?= ($s['navbar_bg_style'] ?? 'solid') === 'solid' ? 'selected' : '' ?>>Solid Color</option>
                            <option value="glass" <?= ($s['navbar_bg_style'] ?? 'solid') === 'glass' ? 'selected' : '' ?>>Glass Effect (Frosted)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Menu Alignment</label>
                        <select name="nav_menu_align" class="w-full px-3 py-2.5 border rounded-lg text-sm">
                            <option value="left" <?= ($s['nav_menu_align'] ?? 'left') === 'left' ? 'selected' : '' ?>>Left</option>
                            <option value="center" <?= ($s['nav_menu_align'] ?? 'left') === 'center' ? 'selected' : '' ?>>Center</option>
                            <option value="right" <?= ($s['nav_menu_align'] ?? 'left') === 'right' ? 'selected' : '' ?>>Right</option>
                        </select>
                    </div>
                </div>
                <div id="nav-glass-options" class="grid md:grid-cols-2 gap-4 <?= ($s['navbar_bg_style'] ?? 'solid') !== 'glass' ? 'hidden' : '' ?>">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Glass Opacity (0-100)</label>
                        <input type="number" name="navbar_glass_opacity" value="<?= e($s['navbar_glass_opacity'] ?? '75') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" min="0" max="100"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Glass Blur (px)</label>
                        <input type="number" name="navbar_glass_blur" value="<?= e($s['navbar_glass_blur'] ?? '10') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" min="0" max="50"></div>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Search Placeholder</label>
                        <input type="text" name="search_placeholder" value="<?= e($s['search_placeholder'] ?? 'পণ্য খুঁজুন...') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Max Categories in Nav</label>
                        <input type="number" name="nav_max_categories" value="<?= e($s['nav_max_categories'] ?? '8') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="nav_show_shop_link" value="1" <?= ($s['nav_show_shop_link'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded text-blue-600"><span>Show "All Products" Link</span></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="nav_show_categories" value="1" <?= ($s['nav_show_categories'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded text-blue-600"><span>Show Categories</span></label>
                </div>
            </div>

            <!-- Mobile Product Page Sticky Bar -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-mobile-alt mr-2 text-green-500"></i>Mobile Product Page Design</h4>
                <p class="text-xs text-gray-500">Special mobile-optimized product page with a sticky buy bar replacing the navbar</p>
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="mobile_product_sticky_bar" value="1" <?= ($s['mobile_product_sticky_bar'] ?? '0') === '1' ? 'checked' : '' ?> class="rounded text-green-600" id="stickyBarToggle" onchange="document.getElementById('stickyBarOptions').classList.toggle('hidden', !this.checked)">
                    <span class="text-sm font-medium">Enable Sticky Buy Bar on Mobile Product Page</span>
                </label>
                <div id="stickyBarOptions" class="space-y-3 <?= ($s['mobile_product_sticky_bar'] ?? '0') === '1' ? '' : 'hidden' ?>">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="mobile_hide_nav_product" value="1" <?= ($s['mobile_hide_nav_product'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded text-green-600">
                        <span class="text-sm">Hide Navbar on Product Page (Mobile Only)</span>
                    </label>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Sticky Bar Background Style</label>
                        <select name="mobile_sticky_bg_style" class="w-full px-3 py-2.5 border rounded-lg text-sm" onchange="toggleStickyStyle(this.value)">
                            <option value="solid" <?= ($s['mobile_sticky_bg_style'] ?? 'solid') === 'solid' ? 'selected' : '' ?>>Solid Color</option>
                            <option value="glass" <?= ($s['mobile_sticky_bg_style'] ?? 'solid') === 'glass' ? 'selected' : '' ?>>Glass UI (Frosted)</option>
                        </select>
                    </div>
                    <div id="stickyBgColorWrap">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Sticky Bar Background Color</label>
                        <input type="color" name="mobile_sticky_bg_color" value="<?= e($s['mobile_sticky_bg_color'] ?? '#ffffff') ?>" class="w-12 h-10 rounded border cursor-pointer">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Sticky Bar Text Color</label>
                        <input type="color" name="mobile_sticky_text_color" value="<?= e($s['mobile_sticky_text_color'] ?? '#1f2937') ?>" class="w-12 h-10 rounded border cursor-pointer">
                    </div>
                </div>
            </div>

            <script>
            function toggleHeaderStyle(v){ document.getElementById('header-glass-options').classList.toggle('hidden', v!=='glass'); }
            function toggleNavStyle(v){ document.getElementById('nav-glass-options').classList.toggle('hidden', v!=='glass'); }
            function toggleStickyStyle(v){ document.getElementById('stickyBgColorWrap').classList.toggle('hidden', v==='glass'); }
            </script>

            <?php elseif ($tab === 'footer'): ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-info-circle mr-2 text-blue-500"></i>Footer Content</h4>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Footer About Text</label>
                    <textarea name="footer_about" rows="3" class="w-full px-3 py-2.5 border rounded-lg text-sm"><?= e($s['footer_about'] ?? '') ?></textarea></div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Footer Logo</label>
                    <div class="border-2 border-dashed rounded-xl p-4 text-center">
                        <?php $fLogoUrl = settingImgUrl($s, 'footer_logo'); ?>
                        <?php if ($fLogoUrl): ?><img src="<?= $fLogoUrl ?>" class="h-12 mx-auto mb-2" id="flogo-preview-img">
                        <?php else: ?><div class="text-gray-400 py-2" id="flogo-placeholder"><i class="fas fa-image text-2xl"></i></div>
                            <img src="" class="h-12 mx-auto mb-2 hidden" id="flogo-preview-img"><?php endif; ?>
                        <input type="hidden" name="footer_logo_media" id="footer_logo_media" value="">
                        <div class="flex gap-2 justify-center mt-2">
                            <button type="button" onclick="pickSettingImage('footer_logo')" class="px-3 py-1.5 bg-blue-500 text-white rounded-lg text-xs hover:bg-blue-600"><i class="fas fa-photo-video mr-1"></i>Media Library</button>
                            <label class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs hover:bg-gray-200 cursor-pointer"><i class="fas fa-upload mr-1"></i>Upload
                                <input type="file" name="footer_logo" accept="image/*" class="hidden" onchange="previewFile(this,'flogo-preview-img','flogo-placeholder')"></label>
                        </div>
                    </div>
                </div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Copyright Text</label>
                    <input type="text" name="copyright_text" value="<?= e($s['copyright_text'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Footer Address</label>
                    <textarea name="footer_address" rows="2" class="w-full px-3 py-2.5 border rounded-lg text-sm"><?= e($s['footer_address'] ?? '') ?></textarea></div>
            </div>

            <?php for ($col = 1; $col <= 2; $col++):
                $links = json_decode($s["footer_links_col{$col}"] ?? '[]', true) ?: [];
            ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-link mr-2 text-green-500"></i>Footer Links Column <?= $col ?></h4>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Column Title</label>
                    <input type="text" name="footer_links_col<?= $col ?>_title" value="<?= e($s["footer_links_col{$col}_title"] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                <div id="footer-links-<?= $col ?>" class="space-y-2">
                    <?php foreach ($links as $link): ?>
                    <div class="footer-link-row flex gap-2 items-center">
                        <input type="text" name="footer_links_col<?= $col ?>_label[]" value="<?= e($link['label'] ?? '') ?>" placeholder="Link Text" class="flex-1 px-3 py-2 border rounded-lg text-sm">
                        <input type="text" name="footer_links_col<?= $col ?>_url[]" value="<?= e($link['url'] ?? '') ?>" placeholder="/page or https://..." class="flex-1 px-3 py-2 border rounded-lg text-sm">
                        <button type="button" onclick="this.closest('.footer-link-row').remove()" class="text-red-400 hover:text-red-600 px-2"><i class="fas fa-trash"></i></button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" onclick="addFooterLink(<?= $col ?>)" class="text-blue-600 text-sm font-medium hover:underline"><i class="fas fa-plus mr-1"></i>Add Link</button>
            </div>
            <?php endfor; ?>

            <?php elseif ($tab === 'shipping'): ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-truck mr-2 text-blue-500"></i>Delivery Charges</h4>
                <div class="grid md:grid-cols-3 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Inside Dhaka (৳)</label>
                        <input type="number" name="shipping_inside_dhaka" value="<?= e($s['shipping_inside_dhaka'] ?? '60') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Dhaka Subdivision (৳)</label>
                        <input type="number" name="shipping_dhaka_sub" value="<?= e($s['shipping_dhaka_sub'] ?? '100') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Outside Dhaka (৳)</label>
                        <input type="number" name="shipping_outside_dhaka" value="<?= e($s['shipping_outside_dhaka'] ?? '120') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Free Shipping Minimum (৳)</label>
                        <input type="number" name="free_shipping_minimum" value="<?= e($s['free_shipping_minimum'] ?? '0') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="0 = disabled"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Estimated Delivery</label>
                        <input type="text" name="estimated_delivery" value="<?= e($s['estimated_delivery'] ?? '2-5 days') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-shipping-fast mr-2 text-green-500"></i>Courier & Invoice</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Default Courier</label>
                        <select name="default_courier" class="w-full px-3 py-2.5 border rounded-lg text-sm">
                            <?php foreach (['pathao'=>'Pathao','steadfast'=>'Steadfast','redx'=>'RedX','personal'=>'Personal'] as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= ($s['default_courier'] ?? 'pathao') === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Invoice & Print Settings</label>
                        <a href="?tab=print" class="inline-flex items-center gap-2 px-4 py-2.5 border border-blue-200 bg-blue-50 rounded-lg text-sm text-blue-700 hover:bg-blue-100 transition">
                            <i class="fas fa-print"></i> Go to Print & Invoice Settings
                        </a>
                        <p class="text-xs text-gray-400 mt-1">Templates, layout, barcode & more</p>
                    </div>
                </div>
                <label class="flex items-center gap-2"><input type="hidden" name="auto_detect_location" value="0">
                    <input type="checkbox" name="auto_detect_location" value="1" class="rounded" <?= ($s['auto_detect_location'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <span class="text-sm text-gray-700">Auto-detect courier location from customer address</span></label>
            </div>

            <?php elseif ($tab === 'social'): ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-share-alt mr-2 text-pink-500"></i>Social & Contact</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <?php foreach (['contact_phone'=>'Contact Phone','contact_email'=>'Contact Email','whatsapp_number'=>'WhatsApp','facebook_url'=>'Facebook URL','instagram_url'=>'Instagram URL','youtube_url'=>'YouTube URL','tiktok_url'=>'TikTok URL','twitter_url'=>'Twitter/X URL'] as $key => $label): ?>
                    <div><label class="block text-xs font-medium text-gray-500 mb-0.5"><?= $label ?></label>
                        <input type="text" name="<?= $key ?>" value="<?= e($s[$key] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Floating Contact Button -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h4 class="font-semibold text-gray-800"><i class="fas fa-headset mr-2 text-blue-500"></i>Floating Contact Button</h4>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="fab_enabled" value="1" class="sr-only peer" <?= ($s['fab_enabled'] ?? '0') === '1' ? 'checked' : '' ?> onchange="document.getElementById('fabOptions').classList.toggle('hidden',!this.checked)">
                        <div class="w-9 h-5 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                </div>
                <p class="text-xs text-gray-500">Replaces the default chat bubble with a unified contact menu. Customers tap once to expand all your contact options.</p>

                <div id="fabOptions" class="space-y-3 <?= ($s['fab_enabled'] ?? '0') !== '1' ? 'hidden' : '' ?>">
                    <div class="grid md:grid-cols-2 gap-3">
                        <div><label class="block text-xs font-medium text-gray-500 mb-0.5">Button Color</label>
                            <input type="color" name="fab_color" value="<?= e($s['fab_color'] ?? '#3b82f6') ?>" class="h-9 w-full rounded-lg border cursor-pointer"></div>
                        <div><label class="block text-xs font-medium text-gray-500 mb-0.5">Position</label>
                            <select name="fab_position" class="w-full px-3 py-2 border rounded-lg text-sm">
                                <option value="right" <?= ($s['fab_position'] ?? 'right') === 'right' ? 'selected' : '' ?>>Bottom Right</option>
                                <option value="left" <?= ($s['fab_position'] ?? '') === 'left' ? 'selected' : '' ?>>Bottom Left</option>
                            </select></div>
                    </div>

                    <!-- Call -->
                    <div class="flex items-center gap-3 p-3 rounded-lg border bg-gray-50">
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="checkbox" name="fab_call_enabled" value="1" class="sr-only peer" <?= ($s['fab_call_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <div class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-500"></div>
                        </label>
                        <i class="fas fa-phone-alt text-green-500 w-5 text-center"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-700">Call</div>
                            <div class="text-[10px] text-gray-400">Uses Contact Phone from above</div>
                        </div>
                        <div class="text-xs text-gray-400 truncate max-w-[120px]"><?= e($s['contact_phone'] ?? 'Not set') ?></div>
                    </div>

                    <!-- Chat -->
                    <div class="flex items-center gap-3 p-3 rounded-lg border bg-gray-50">
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="checkbox" name="fab_chat_enabled" value="1" class="sr-only peer" <?= ($s['fab_chat_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <div class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-500"></div>
                        </label>
                        <i class="fas fa-comments text-blue-500 w-5 text-center"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-700">Live Chat</div>
                            <div class="text-[10px] text-gray-400">Opens your site's built-in chat widget</div>
                        </div>
                        <div class="text-xs <?= ($s['chat_enabled'] ?? '0') === '1' ? 'text-green-500' : 'text-red-400' ?>"><?= ($s['chat_enabled'] ?? '0') === '1' ? 'Chat ON' : 'Chat OFF' ?></div>
                    </div>

                    <!-- WhatsApp -->
                    <div class="flex items-center gap-3 p-3 rounded-lg border bg-gray-50">
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="checkbox" name="fab_whatsapp_enabled" value="1" class="sr-only peer" <?= ($s['fab_whatsapp_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <div class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-500"></div>
                        </label>
                        <i class="fab fa-whatsapp text-green-500 w-5 text-center text-lg"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-700">WhatsApp</div>
                            <div class="text-[10px] text-gray-400">Uses WhatsApp number from above</div>
                        </div>
                        <div class="text-xs text-gray-400 truncate max-w-[120px]"><?= e($s['whatsapp_number'] ?? 'Not set') ?></div>
                    </div>

                    <!-- Messenger -->
                    <div class="flex items-center gap-3 p-3 rounded-lg border bg-gray-50">
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="checkbox" name="fab_messenger_enabled" value="1" class="sr-only peer" <?= ($s['fab_messenger_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <div class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                        <i class="fab fa-facebook-messenger text-blue-600 w-5 text-center text-lg"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-700">Messenger</div>
                            <div class="text-[10px] text-gray-400">Enter Facebook Page username or ID</div>
                        </div>
                    </div>
                    <div class="pl-12">
                        <input type="text" name="fab_messenger_id" value="<?= e($s['fab_messenger_id'] ?? '') ?>" placeholder="Page username or numeric ID" class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>

                    <div class="p-3 rounded-lg bg-blue-50 border border-blue-100">
                        <p class="text-xs text-blue-600"><i class="fas fa-info-circle mr-1"></i> This replaces the standalone chat bubble with a single unified button. When only one option is enabled, it opens directly. With multiple options, it expands into a menu.</p>
                    </div>
                </div>
            </div>

            <?php elseif ($tab === 'ads'): ?>
            <?php
            // Event definitions
            $fbEvents = [
                'PageView'             => ['label'=>'PageView',             'icon'=>'👁️', 'desc'=>'প্রতিটি পেজ ভিজিটে ফায়ার হয়'],
                'ViewContent'          => ['label'=>'ViewContent',          'icon'=>'🛍️', 'desc'=>'প্রোডাক্ট পেজ দেখলে ফায়ার হয়'],
                'AddToCart'            => ['label'=>'AddToCart',            'icon'=>'🛒', 'desc'=>'কার্টে যোগ করলে ফায়ার হয়'],
                'InitiateCheckout'     => ['label'=>'InitiateCheckout',     'icon'=>'💳', 'desc'=>'চেকআউট শুরু করলে ফায়ার হয়'],
                'Purchase'             => ['label'=>'Purchase',             'icon'=>'✅', 'desc'=>'অর্ডার সফল হলে ফায়ার হয় (সবচেয়ে গুরুত্বপূর্ণ)'],
                'Search'               => ['label'=>'Search',              'icon'=>'🔍', 'desc'=>'সার্চ করলে ফায়ার হয়'],
                'Lead'                 => ['label'=>'Lead',                'icon'=>'📋', 'desc'=>'কন্টাক্ট ফর্ম সাবমিট করলে'],
                'CompleteRegistration' => ['label'=>'CompleteRegistration', 'icon'=>'📝', 'desc'=>'রেজিস্ট্রেশন সম্পন্ন হলে'],
                'Contact'              => ['label'=>'Contact',             'icon'=>'📞', 'desc'=>'কন্টাক্ট বাটনে ক্লিক করলে'],
            ];
            ?>

            <!-- ═══ CREDENTIALS ═══ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <div class="flex items-center justify-between">
                    <h4 class="font-semibold text-gray-800"><i class="fab fa-facebook mr-2 text-blue-600"></i>Facebook Pixel & Conversions API</h4>
                    <?php if (fbGetPixelId() && getSetting('fb_access_token','')): ?>
                    <span class="text-xs bg-green-100 text-green-700 px-3 py-1 rounded-full font-semibold">✅ কানেক্টেড</span>
                    <?php else: ?>
                    <span class="text-xs bg-red-100 text-red-600 px-3 py-1 rounded-full font-semibold">❌ সেটআপ করুন</span>
                    <?php endif; ?>
                </div>

                <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800 space-y-1">
                    <p><strong>📌 সেটআপ গাইড:</strong></p>
                    <p>1. <a href="https://business.facebook.com/events_manager" target="_blank" class="underline font-semibold">Events Manager</a> → আপনার Pixel সিলেক্ট করুন</p>
                    <p>2. Settings → <strong>Conversions API</strong> → Generate Access Token</p>
                    <p>3. নিচে Pixel ID ও Access Token বসান, তারপর "🧪 Test" বাটনে ক্লিক করে verify করুন</p>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Facebook Pixel ID <span class="text-red-500">*</span></label>
                        <input type="text" name="fb_pixel_id" value="<?= e($s['fb_pixel_id'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" placeholder="123456789012345">
                        <p class="text-xs text-gray-400 mt-1">Events Manager → Data Sources → Pixel → Pixel ID</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Access Token (CAPI) <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" name="fb_access_token" id="fbAccessToken" value="<?= e($s['fb_access_token'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono pr-10" placeholder="EAAxxxxxxx...">
                            <button type="button" onclick="var el=document.getElementById('fbAccessToken');el.type=el.type==='password'?'text':'password'" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-xs"><i class="fas fa-eye"></i></button>
                        </div>
                        <p class="text-xs text-gray-400 mt-1">Events Manager → Settings → Conversions API → Generate Token</p>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Test Event Code (ঐচ্ছিক)</label>
                        <input type="text" name="fb_test_event_code" value="<?= e($s['fb_test_event_code'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" placeholder="TEST12345">
                        <p class="text-xs text-gray-400 mt-1">Events Manager → Test Events → Test Code লিখুন। Production-এ খালি রাখুন।</p>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="button" id="fbTestBtn" onclick="testFbCapi()" class="px-5 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition whitespace-nowrap">
                            <i class="fas fa-flask mr-1"></i> 🧪 Test Connection
                        </button>
                        <div id="fbTestResult" class="text-xs flex-1 hidden p-2 rounded-lg"></div>
                    </div>
                </div>
            </div>

            <!-- ═══ CLIENT-SIDE (Browser Pixel) EVENTS ═══ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <div class="flex items-center gap-2 mb-1">
                    <h4 class="font-semibold text-gray-800"><i class="fas fa-desktop mr-2 text-purple-500"></i>Client-Side Events (Browser Pixel)</h4>
                    <span class="text-[10px] bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-bold">fbq()</span>
                </div>
                <p class="text-xs text-gray-500">ব্রাউজারে Facebook Pixel এর মাধ্যমে ফায়ার হয়। ভিজিটরের ব্রাউজারে চলে।</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($fbEvents as $evKey => $evInfo): ?>
                    <label class="flex items-start gap-3 p-3 border rounded-lg hover:bg-gray-50 cursor-pointer transition group">
                        <input type="hidden" name="fb_cs_evt_<?= strtolower($evKey) ?>" value="0">
                        <input type="checkbox" name="fb_cs_evt_<?= strtolower($evKey) ?>" value="1" class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                            <?= ($s['fb_cs_evt_' . strtolower($evKey)] ?? '1') === '1' ? 'checked' : '' ?>>
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-gray-800"><?= $evInfo['icon'] ?> <?= $evInfo['label'] ?></div>
                            <div class="text-[10px] text-gray-400 leading-tight mt-0.5"><?= $evInfo['desc'] ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ═══ SERVER-SIDE (CAPI) EVENTS ═══ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <div class="flex items-center gap-2 mb-1">
                    <h4 class="font-semibold text-gray-800"><i class="fas fa-server mr-2 text-green-600"></i>Server-Side Events (Conversions API)</h4>
                    <span class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-bold">CAPI</span>
                </div>
                <p class="text-xs text-gray-500">সার্ভার থেকে সরাসরি Facebook-এ পাঠানো হয়। Ad Blocker বাইপাস করে, ডেটা ক্ষতি কমায়।</p>

                <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700">
                    <strong>⚠️ গুরুত্বপূর্ণ:</strong> সর্বোচ্চ ট্র্যাকিং পেতে Client + Server দুটোই চালু রাখুন। Facebook স্বয়ংক্রিয়ভাবে event_id দিয়ে ডুপ্লিকেট বাদ দেবে (Deduplication)।
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($fbEvents as $evKey => $evInfo): ?>
                    <label class="flex items-start gap-3 p-3 border rounded-lg hover:bg-gray-50 cursor-pointer transition group">
                        <input type="hidden" name="fb_ss_evt_<?= strtolower($evKey) ?>" value="0">
                        <input type="checkbox" name="fb_ss_evt_<?= strtolower($evKey) ?>" value="1" class="mt-0.5 rounded border-gray-300 text-green-600 focus:ring-green-500"
                            <?= ($s['fb_ss_evt_' . strtolower($evKey)] ?? '1') === '1' ? 'checked' : '' ?>>
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-gray-800"><?= $evInfo['icon'] ?> <?= $evInfo['label'] ?></div>
                            <div class="text-[10px] text-gray-400 leading-tight mt-0.5"><?= $evInfo['desc'] ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ═══ ADVANCED OPTIONS ═══ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-cogs mr-2 text-gray-500"></i>Advanced Settings</h4>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <label class="flex items-start gap-3 p-3 border rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="hidden" name="fb_advanced_matching" value="0">
                        <input type="checkbox" name="fb_advanced_matching" value="1" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            <?= ($s['fb_advanced_matching'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <div>
                            <div class="text-sm font-semibold text-gray-800">🎯 Advanced Matching</div>
                            <div class="text-[10px] text-gray-400">Hashed email/phone পিক্সেলে পাঠায়। Match rate বাড়ায়।</div>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 border rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="hidden" name="fb_event_logging" value="0">
                        <input type="checkbox" name="fb_event_logging" value="1" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            <?= ($s['fb_event_logging'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <div>
                            <div class="text-sm font-semibold text-gray-800">📝 Event Logging</div>
                            <div class="text-[10px] text-gray-400">সব CAPI ইভেন্ট log ফাইলে রেকর্ড করে। Debug-এ কাজে লাগে।</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- ═══ EVENT VERIFICATION MATRIX ═══ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h4 class="font-semibold text-gray-800"><i class="fas fa-clipboard-check mr-2 text-emerald-500"></i>Event Status Overview</h4>
                    <span class="text-[10px] text-gray-400">Client + Server event_id দিয়ে ডুপ্লিকেশন বাদ হয়</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left py-2 px-2 text-xs text-gray-500 font-semibold">Event</th>
                                <th class="text-center py-2 px-2 text-xs text-purple-600 font-semibold"><i class="fas fa-desktop mr-1"></i>Client</th>
                                <th class="text-center py-2 px-2 text-xs text-green-600 font-semibold"><i class="fas fa-server mr-1"></i>Server</th>
                                <th class="text-left py-2 px-2 text-xs text-gray-500 font-semibold">Trigger Point</th>
                                <th class="text-left py-2 px-2 text-xs text-gray-500 font-semibold">Data Sent</th>
                            </tr>
                        </thead>
                        <tbody class="text-xs">
                            <?php
                            $triggerMap = [
                                'PageView' => ['trigger'=>'প্রতিটি পেজ লোড', 'data'=>'URL, Referrer, fbp, fbc'],
                                'ViewContent' => ['trigger'=>'প্রোডাক্ট পেজ ভিজিট', 'data'=>'product_id, name, price, category'],
                                'AddToCart' => ['trigger'=>'কার্টে যোগ', 'data'=>'product_id, name, price, quantity'],
                                'InitiateCheckout' => ['trigger'=>'চেকআউট ফর্ম ওপেন', 'data'=>'cart_value, num_items, content_ids'],
                                'Purchase' => ['trigger'=>'অর্ডার সফল (createOrder)', 'data'=>'order_id, total, items, phone, email, city'],
                                'Search' => ['trigger'=>'সার্চ করলে', 'data'=>'search_string'],
                                'Lead' => ['trigger'=>'কন্টাক্ট ফর্ম', 'data'=>'name, phone, email'],
                                'CompleteRegistration' => ['trigger'=>'রেজিস্ট্রেশন সম্পন্ন', 'data'=>'name, phone, email'],
                                'Contact' => ['trigger'=>'কন্টাক্ট বাটন ক্লিক', 'data'=>'content_name'],
                            ];
                            foreach ($fbEvents as $evKey => $evInfo):
                                $csOn = ($s['fb_cs_evt_' . strtolower($evKey)] ?? '1') === '1';
                                $ssOn = ($s['fb_ss_evt_' . strtolower($evKey)] ?? '1') === '1';
                                $trig = $triggerMap[$evKey] ?? ['trigger'=>'—','data'=>'—'];
                            ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="py-2 px-2 font-semibold text-gray-700"><?= $evInfo['icon'] ?> <?= $evInfo['label'] ?></td>
                                <td class="py-2 px-2 text-center"><?= $csOn ? '<span class="text-green-600 font-bold">✅</span>' : '<span class="text-red-400">❌</span>' ?></td>
                                <td class="py-2 px-2 text-center"><?= $ssOn ? '<span class="text-green-600 font-bold">✅</span>' : '<span class="text-red-400">❌</span>' ?></td>
                                <td class="py-2 px-2 text-gray-500"><?= $trig['trigger'] ?></td>
                                <td class="py-2 px-2 text-gray-400 max-w-[200px] truncate" title="<?= $trig['data'] ?>"><?= $trig['data'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-3 bg-gray-50 border rounded-lg text-xs text-gray-500 space-y-1">
                    <p>🔄 <strong>Deduplication:</strong> প্রতিটি ইভেন্টে একটি unique <code class="bg-white px-1 rounded">event_id</code> থাকে। Client ও Server থেকে একই ID পাঠালে Facebook একবারই count করে।</p>
                    <p>🛡️ <strong>User Data Hashing:</strong> Phone, Email, Name সব SHA-256 hash করে পাঠানো হয়। Raw data Facebook-এ যায় না।</p>
                    <p>🌐 <strong>Server-Side (CAPI):</strong> Ad Blocker থাকলেও কাজ করে, iOS 14+ restrictions বাইপাস করে। <strong>Purchase ইভেন্ট অবশ্যই সার্ভার-সাইড থেকে পাঠানো উচিত।</strong></p>
                </div>
            </div>

            <!-- ═══ LOGS ═══ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h4 class="font-semibold text-gray-800"><i class="fas fa-terminal mr-2 text-gray-500"></i>CAPI Event Logs</h4>
                    <div class="flex gap-2">
                        <button type="button" onclick="loadFbLogs()" class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs font-semibold hover:bg-gray-200"><i class="fas fa-sync-alt mr-1"></i>Load Logs</button>
                        <button type="button" onclick="clearFbLogs()" class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg text-xs font-semibold hover:bg-red-100"><i class="fas fa-trash mr-1"></i>Clear</button>
                    </div>
                </div>
                <pre id="fbLogs" class="bg-gray-900 text-green-400 text-[10px] rounded-lg p-4 overflow-x-auto max-h-60 font-mono leading-relaxed">(Click "Load Logs" to view recent CAPI events)</pre>
            </div>

            <script>
            async function testFbCapi(){
                const btn = document.getElementById('fbTestBtn');
                const res = document.getElementById('fbTestResult');
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Testing...';
                res.classList.remove('hidden','bg-green-100','bg-red-100','text-green-700','text-red-700');
                try {
                    const r = await fetch('<?= SITE_URL ?>/api/fb-test.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=test'});
                    const d = await r.json();
                    if(d.success){
                        res.className = 'text-xs flex-1 p-2 rounded-lg bg-green-100 text-green-700';
                        res.innerHTML = '✅ <strong>সফল!</strong> Events received: '+d.events_received+'<br>Event ID: <code>'+d.event_id+'</code>'+(d.test_code?'<br>Test Code: '+d.test_code:'');
                    } else {
                        res.className = 'text-xs flex-1 p-2 rounded-lg bg-red-100 text-red-700';
                        res.innerHTML = '❌ <strong>ব্যর্থ:</strong> '+(d.error||'Unknown error')+'<br>HTTP: '+(d.http_code||'—');
                    }
                    res.classList.remove('hidden');
                } catch(e){
                    res.className = 'text-xs flex-1 p-2 rounded-lg bg-red-100 text-red-700';
                    res.innerHTML = '❌ Network error: '+e.message;
                    res.classList.remove('hidden');
                }
                btn.disabled = false; btn.innerHTML = '<i class="fas fa-flask mr-1"></i> 🧪 Test Connection';
            }
            async function loadFbLogs(){
                const el = document.getElementById('fbLogs');
                el.textContent = 'Loading...';
                try {
                    const r = await fetch('<?= SITE_URL ?>/api/fb-test.php?action=logs');
                    const d = await r.json();
                    el.textContent = d.logs || '(empty)';
                    if(d.total_lines) el.textContent += '\n\n— Showing last 50 of '+d.total_lines+' entries —';
                } catch(e){ el.textContent = 'Error: '+e.message; }
            }
            async function clearFbLogs(){
                if(!confirm('সব CAPI logs মুছে ফেলবেন?')) return;
                try {
                    await fetch('<?= SITE_URL ?>/api/fb-test.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=clear_logs'});
                    document.getElementById('fbLogs').textContent = '(Logs cleared)';
                } catch(e){}
            }
            </script>

            <!-- ═══ OTHER TRACKING ═══ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-chart-line mr-2 text-indigo-500"></i>Other Analytics & Pixels</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Google Analytics ID</label>
                        <input type="text" name="google_analytics_id" value="<?= e($s['google_analytics_id'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="G-XXXXXXXXXX"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Google Tag Manager</label>
                        <input type="text" name="gtm_id" value="<?= e($s['gtm_id'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="GTM-XXXXXXX"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">TikTok Pixel ID</label>
                        <input type="text" name="tiktok_pixel_id" value="<?= e($s['tiktok_pixel_id'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                </div>
            </div>

            <!-- ═══ CUSTOM CODE ═══ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-code mr-2 text-gray-500"></i>Custom Tracking Code</h4>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Custom Header Code <span class="text-xs text-gray-400">(যেকোনো &lt;head&gt; tag-এ inject হবে)</span></label>
                    <textarea name="custom_header_code" rows="4" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" placeholder="<!-- Paste your tracking script here -->"><?= e($s['custom_header_code'] ?? '') ?></textarea></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Custom Footer Code <span class="text-xs text-gray-400">(&lt;/body&gt; এর আগে inject হবে)</span></label>
                    <textarea name="custom_footer_code" rows="4" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" placeholder="<!-- Paste your tracking script here -->"><?= e($s['custom_footer_code'] ?? '') ?></textarea></div>
            </div>

            <?php elseif ($tab === 'checkout'): ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-cash-register mr-2 text-green-500"></i>Checkout Labels</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Order Button Text</label>
                        <input type="text" name="btn_order_cod_label" value="<?= e($s['btn_order_cod_label'] ?? 'ক্যাশ অন ডেলিভারিতে অর্ডার করুন') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Add to Cart Text</label>
                        <input type="text" name="btn_add_to_cart_label" value="<?= e($s['btn_add_to_cart_label'] ?? 'কার্টে যোগ করুন') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Buy Now Text</label>
                        <input type="text" name="btn_buy_now_label" value="<?= e($s['btn_buy_now_label'] ?? 'এখনই কিনুন') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Popup Title</label>
                        <input type="text" name="checkout_popup_title" value="<?= e($s['checkout_popup_title'] ?? 'আপনার অর্ডার সম্পন্ন করুন') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                </div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Order Success Message</label>
                    <textarea name="order_success_message" rows="2" class="w-full px-3 py-2.5 border rounded-lg text-sm"><?= e($s['order_success_message'] ?? 'আপনার অর্ডার সফলভাবে সম্পন্ন হয়েছে!') ?></textarea></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">COD Note</label>
                    <textarea name="cod_note" rows="2" class="w-full px-3 py-2.5 border rounded-lg text-sm"><?= e($s['cod_note'] ?? '') ?></textarea></div>
                <label class="flex items-center gap-2"><input type="hidden" name="checkout_note_enabled" value="0">
                    <input type="checkbox" name="checkout_note_enabled" value="1" class="rounded" <?= ($s['checkout_note_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <span class="text-sm text-gray-700">Allow customer order notes</span></label>
            </div>

            <!-- Order Now Button Behavior -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-shopping-cart mr-2 text-blue-500"></i>Order Now Button</h4>
                <p class="text-xs text-gray-500">Controls what happens when a customer clicks "অর্ডার করুন" (Order Now). When enabled, the cart is cleared first and only the selected product is shown in checkout. When disabled, the product is added to existing cart items.</p>
                <label class="flex items-center gap-2"><input type="hidden" name="order_now_clear_cart" value="0">
                    <input type="checkbox" name="order_now_clear_cart" value="1" class="rounded text-blue-600" <?= ($s['order_now_clear_cart'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <span class="text-sm font-medium text-gray-700">Clear cart on "Order Now" (show only selected product)</span></label>
            </div>

            <!-- Order Merge -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-object-group mr-2 text-purple-500"></i>Order Merging</h4>
                <p class="text-xs text-gray-500">When enabled, if the same customer (same phone + same address) places another order while their previous order is still pending, the new items will be merged into the existing order instead of creating a new one.</p>
                <label class="flex items-center gap-2"><input type="hidden" name="order_merge_enabled" value="0">
                    <input type="checkbox" name="order_merge_enabled" value="1" class="rounded text-purple-600" <?= ($s['order_merge_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <span class="text-sm font-medium text-gray-700">Enable Order Merging</span></label>
            </div>

            <!-- Product Variation Display Mode -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-layer-group mr-2 text-indigo-500"></i>Product Variation Display</h4>
                <p class="text-xs text-gray-500">Controls how product variations (e.g. sizes, colors) appear in your storefront</p>
                <?php
                $varMode = $s['variation_display_mode'] ?? 'combined';
                // Count current split status
                try {
                    ensureVariationSplitColumns();
                    $splitChildCount = intval($db->fetch("SELECT COUNT(*) as cnt FROM products WHERE parent_product_id IS NOT NULL AND parent_product_id > 0")['cnt'] ?? 0);
                    $splitParentCount = intval($db->fetch("SELECT COUNT(DISTINCT parent_product_id) as cnt FROM products WHERE parent_product_id IS NOT NULL AND parent_product_id > 0")['cnt'] ?? 0);
                } catch (\Throwable $e) { $splitChildCount = 0; $splitParentCount = 0; }
                ?>
                <?php if ($splitChildCount > 0): ?>
                <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 text-xs text-indigo-700">
                    <i class="fas fa-info-circle mr-1"></i> Currently <strong><?= $splitChildCount ?> split variation products</strong> from <?= $splitParentCount ?> parent product(s).
                </div>
                <?php endif; ?>
                <div class="grid sm:grid-cols-2 gap-4">
                    <label class="relative flex flex-col cursor-pointer rounded-xl border-2 p-4 transition hover:shadow-md var-mode-card <?= $varMode==='combined' ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300' ?>">
                        <input type="radio" name="variation_display_mode" value="combined" <?= $varMode==='combined'?'checked':'' ?> class="sr-only" onchange="document.querySelectorAll('.var-mode-card').forEach(c=>{c.classList.remove('border-indigo-500','bg-indigo-50');c.classList.add('border-gray-200')});this.closest('label').classList.remove('border-gray-200');this.closest('label').classList.add('border-indigo-500','bg-indigo-50')">
                        <?php if ($varMode==='combined'): ?><div class="absolute top-2 right-2 w-5 h-5 bg-indigo-500 rounded-full flex items-center justify-center"><svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></div><?php endif; ?>
                        <div class="text-lg mb-1">📦 Grouped (Combined)</div>
                        <div class="text-xs text-gray-500">All variations shown as options on a single product page. Customer selects from dropdown/buttons.</div>
                        <div class="text-[10px] text-gray-400 mt-2">Recommended for most stores</div>
                    </label>
                    <label class="relative flex flex-col cursor-pointer rounded-xl border-2 p-4 transition hover:shadow-md var-mode-card <?= $varMode==='split' ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-gray-300' ?>">
                        <input type="radio" name="variation_display_mode" value="split" <?= $varMode==='split'?'checked':'' ?> class="sr-only" onchange="document.querySelectorAll('.var-mode-card').forEach(c=>{c.classList.remove('border-indigo-500','bg-indigo-50');c.classList.add('border-gray-200')});this.closest('label').classList.remove('border-gray-200');this.closest('label').classList.add('border-indigo-500','bg-indigo-50')">
                        <?php if ($varMode==='split'): ?><div class="absolute top-2 right-2 w-5 h-5 bg-indigo-500 rounded-full flex items-center justify-center"><svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></div><?php endif; ?>
                        <div class="text-lg mb-1">🔀 Split (Separate Products)</div>
                        <div class="text-xs text-gray-500">Each variation becomes its own product listing. Parent product is hidden. Good for Facebook catalogs.</div>
                        <div class="text-[10px] text-amber-600 mt-2">⚠ Creates separate product entries per variation</div>
                    </label>
                </div>
                <p class="text-xs text-gray-400"><i class="fas fa-exclamation-triangle mr-1 text-amber-400"></i> Changing this and clicking <strong>Save Settings</strong> will automatically split/merge <strong>all</strong> products with variations.</p>
            </div>

            <!-- Store Credits -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-coins mr-2 text-yellow-500"></i>Store Credits</h4>
                <p class="text-xs text-gray-500">Registered customers can earn store credits when their orders are delivered. Credits are set per-product in the product editor.</p>
                <label class="flex items-center gap-2"><input type="hidden" name="store_credits_enabled" value="0">
                    <input type="checkbox" name="store_credits_enabled" value="1" class="rounded text-yellow-600" <?= ($s['store_credits_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <span class="text-sm font-medium text-gray-700">Enable Store Credits System</span></label>
                <label class="flex items-center gap-2"><input type="hidden" name="store_credit_checkout" value="0">
                    <input type="checkbox" name="store_credit_checkout" value="1" class="rounded text-yellow-600" <?= ($s['store_credit_checkout'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <span class="text-sm text-gray-700">Allow customers to spend credits at checkout</span></label>
                <div class="border-t pt-3 mt-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-exchange-alt mr-1 text-yellow-500"></i> Credit Conversion Rate</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-600">1 Credit =</span>
                        <input type="number" name="store_credit_conversion_rate" step="0.01" min="0.01" max="1000" 
                            value="<?= e($s['store_credit_conversion_rate'] ?? '0.75') ?>" 
                            class="w-24 border rounded-lg px-3 py-2 text-sm text-center font-semibold">
                        <span class="text-sm text-gray-600">৳ (TK)</span>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Example: If rate is 0.75, a customer with 100 credits can use ৳75 at checkout.</p>
                </div>
            </div>

            <?php elseif ($tab === 'registration'): ?>
            <?php
            // Registration field definitions
            $regDefaults = [
                ['key'=>'name','label'=>'নাম','label_en'=>'Full Name','type'=>'text','enabled'=>true,'required'=>true,'placeholder'=>'আপনার নাম','icon'=>'fa-user','system'=>true],
                ['key'=>'phone','label'=>'ফোন নম্বর','label_en'=>'Phone Number','type'=>'tel','enabled'=>true,'required'=>true,'placeholder'=>'01XXXXXXXXX','icon'=>'fa-phone','system'=>true],
                ['key'=>'email','label'=>'ইমেইল','label_en'=>'Email','type'=>'email','enabled'=>true,'required'=>false,'placeholder'=>'email@example.com','icon'=>'fa-envelope','system'=>false],
                ['key'=>'password','label'=>'পাসওয়ার্ড','label_en'=>'Password','type'=>'password','enabled'=>true,'required'=>true,'placeholder'=>'কমপক্ষে ৬ অক্ষর','icon'=>'fa-lock','system'=>true],
                ['key'=>'confirm_password','label'=>'পাসওয়ার্ড নিশ্চিত করুন','label_en'=>'Confirm Password','type'=>'password','enabled'=>true,'required'=>true,'placeholder'=>'আবার পাসওয়ার্ড দিন','icon'=>'fa-lock','system'=>true],
                ['key'=>'address','label'=>'ঠিকানা','label_en'=>'Address','type'=>'textarea','enabled'=>false,'required'=>false,'placeholder'=>'বাসা/রোড নং, এলাকা, থানা','icon'=>'fa-map-marker-alt','system'=>false],
                ['key'=>'city','label'=>'শহর','label_en'=>'City','type'=>'text','enabled'=>false,'required'=>false,'placeholder'=>'শহরের নাম','icon'=>'fa-city','system'=>false],
                ['key'=>'district','label'=>'জেলা','label_en'=>'District','type'=>'text','enabled'=>false,'required'=>false,'placeholder'=>'জেলার নাম','icon'=>'fa-map','system'=>false],
                ['key'=>'alt_phone','label'=>'বিকল্প ফোন','label_en'=>'Alternative Phone','type'=>'tel','enabled'=>false,'required'=>false,'placeholder'=>'বিকল্প নম্বর','icon'=>'fa-phone-alt','system'=>false],
            ];
            $regJson = getSetting('registration_fields', '');
            $regFields = $regJson ? json_decode($regJson, true) : null;
            if (!$regFields) {
                $regFields = $regDefaults;
            } else {
                $seen = [];
                $regFields = array_values(array_filter($regFields, function($f) use (&$seen) { $k = $f['key'] ?? ''; if (isset($seen[$k])) return false; $seen[$k] = true; return true; }));
                $savedKeys = array_column($regFields, 'key');
                foreach ($regDefaults as $df) {
                    if (!in_array($df['key'], $savedKeys)) $regFields[] = $df;
                }
                foreach ($regFields as &$rf) {
                    $defMatch = array_filter($regDefaults, fn($d) => $d['key'] === $rf['key']);
                    if ($defMatch) { $def = reset($defMatch); foreach ($def as $dk => $dv) { if (!isset($rf[$dk])) $rf[$dk] = $dv; } }
                }
                unset($rf);
            }
            ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="font-semibold text-gray-800"><i class="fas fa-user-plus mr-2 text-blue-500"></i>Registration Form Fields</h4>
                        <p class="text-sm text-gray-500 mt-1">Customize which fields appear when customers create an account</p>
                    </div>
                    <button type="button" onclick="resetRegFields()" class="text-xs text-gray-500 hover:text-gray-700 bg-gray-100 px-3 py-1.5 rounded-lg"><i class="fas fa-undo mr-1"></i>Reset Default</button>
                </div>

                <div class="grid lg:grid-cols-5 gap-5">
                    <!-- Field List -->
                    <div class="lg:col-span-3">
                        <div id="regFieldList" class="space-y-2">
                            <?php foreach ($regFields as $rf): 
                                $isSys = $rf['system'] ?? false;
                            ?>
                            <div class="reg-field-item border rounded-xl p-3 flex items-center gap-3 transition <?= !($rf['enabled'] ?? true) ? 'opacity-50 bg-gray-50' : 'bg-white hover:shadow-sm' ?>"
                                 data-key="<?= $rf['key'] ?>" data-system="<?= $isSys ? '1' : '0' ?>">
                                <div class="drag-handle flex-shrink-0 text-gray-300 hover:text-gray-500 cursor-grab active:cursor-grabbing">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                                </div>
                                <div class="w-8 h-8 rounded-lg <?= ($rf['enabled'] ?? true) ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400' ?> flex items-center justify-center flex-shrink-0">
                                    <i class="fas <?= $rf['icon'] ?? 'fa-input-text' ?> text-xs"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <input type="text" class="reg-label text-sm font-semibold text-gray-800 bg-transparent border-0 border-b border-transparent hover:border-gray-300 focus:border-blue-500 focus:outline-none px-0 py-0.5 w-full max-w-[180px]" value="<?= e($rf['label']) ?>">
                                        <?php if ($isSys): ?><span class="text-[10px] bg-purple-100 text-purple-600 px-1.5 py-0.5 rounded font-medium">CORE</span><?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="text-[10px] text-gray-400 uppercase"><?= $rf['key'] ?></span>
                                        <span class="text-[10px] text-gray-400">•</span>
                                        <input type="text" class="reg-placeholder text-[10px] text-gray-400 bg-transparent border-0 border-b border-transparent hover:border-gray-300 focus:border-blue-500 focus:outline-none px-0 py-0 max-w-[140px]" value="<?= e($rf['placeholder'] ?? '') ?>" placeholder="Placeholder...">
                                    </div>
                                </div>
                                <div class="flex items-center gap-2.5 flex-shrink-0">
                                    <?php if (!$isSys): ?>
                                    <label class="flex items-center gap-1 cursor-pointer" title="Required">
                                        <span class="text-[10px] text-gray-400">Required</span>
                                        <div class="relative">
                                            <input type="checkbox" class="reg-required sr-only" <?= ($rf['required'] ?? false) ? 'checked' : '' ?>>
                                            <div class="w-7 h-3.5 bg-gray-200 rounded-full toggle-track transition"></div>
                                            <div class="absolute left-0.5 top-0.5 w-2.5 h-2.5 bg-white rounded-full shadow toggle-dot transition"></div>
                                        </div>
                                    </label>
                                    <label class="flex items-center gap-1 cursor-pointer" title="Show">
                                        <span class="text-[10px] text-gray-400">Show</span>
                                        <div class="relative">
                                            <input type="checkbox" class="reg-enabled sr-only" <?= ($rf['enabled'] ?? true) ? 'checked' : '' ?>>
                                            <div class="w-7 h-3.5 bg-gray-200 rounded-full toggle-track transition"></div>
                                            <div class="absolute left-0.5 top-0.5 w-2.5 h-2.5 bg-white rounded-full shadow toggle-dot transition"></div>
                                        </div>
                                    </label>
                                    <?php else: ?>
                                    <span class="text-[10px] text-gray-400 italic">Always shown</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Live Preview -->
                    <div class="lg:col-span-2">
                        <div class="sticky top-20">
                            <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
                                <div class="px-4 py-2.5 bg-gray-50 border-b">
                                    <h5 class="font-semibold text-gray-700 text-xs"><i class="fas fa-eye mr-1 text-green-500"></i>Registration Preview</h5>
                                </div>
                                <div id="regPreview" class="p-4 space-y-3 max-h-[500px] overflow-y-auto"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="registration_fields" id="regFieldsInput" value="">
            </div>

            <style>
            .reg-field-item .toggle-track { transition: background 0.2s; }
            .reg-enabled:checked ~ .toggle-track { background: #22c55e; }
            .reg-required:checked ~ .toggle-track { background: #f97316; }
            .reg-enabled:checked ~ .toggle-dot, .reg-required:checked ~ .toggle-dot { transform: translateX(14px); }
            .reg-field-item .toggle-dot { transition: transform 0.2s; }
            .reg-field-item.sortable-ghost { opacity: 0.3; background: #dbeafe !important; }
            .reg-field-item.sortable-chosen { box-shadow: 0 4px 15px rgba(0,0,0,0.1); z-index: 10; }
            </style>
            <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
            <script>
            (function(){
                const list = document.getElementById('regFieldList');
                new Sortable(list, { handle: '.drag-handle', animation: 200, ghostClass: 'sortable-ghost', chosenClass: 'sortable-chosen', onEnd: updateRegPreview });

                list.addEventListener('change', function(e) {
                    if (e.target.classList.contains('reg-enabled')) {
                        const item = e.target.closest('.reg-field-item');
                        if (item) { item.classList.toggle('opacity-50', !e.target.checked); item.classList.toggle('bg-gray-50', !e.target.checked); item.classList.toggle('bg-white', e.target.checked); }
                    }
                    updateRegPreview();
                });
                list.addEventListener('input', updateRegPreview);

                function updateRegPreview() {
                    const preview = document.getElementById('regPreview');
                    let html = '';
                    list.querySelectorAll('.reg-field-item').forEach(item => {
                        const key = item.dataset.key;
                        const isSys = item.dataset.system === '1';
                        const enabled = isSys || (item.querySelector('.reg-enabled')?.checked ?? true);
                        if (!enabled) return;
                        const required = isSys || (item.querySelector('.reg-required')?.checked ?? false);
                        const label = item.querySelector('.reg-label')?.value || key;
                        const ph = item.querySelector('.reg-placeholder')?.value || '';
                        const star = required ? ' <span class="text-red-500">*</span>' : '';
                        const esc = s => { if(!s) return ''; const d=document.createElement('div'); d.textContent=s; return d.innerHTML; };

                        if (key === 'address') {
                            html += '<div><label class="block text-xs font-medium text-gray-700 mb-0.5">'+esc(label)+star+'</label><textarea class="w-full border rounded-lg px-2.5 py-1.5 text-xs bg-gray-50" rows="2" placeholder="'+esc(ph)+'" disabled></textarea></div>';
                        } else {
                            const type = (key.includes('password')) ? 'password' : (key === 'email' ? 'email' : (key === 'phone' || key === 'alt_phone' ? 'tel' : 'text'));
                            html += '<div><label class="block text-xs font-medium text-gray-700 mb-0.5">'+esc(label)+star+'</label><input type="'+type+'" class="w-full border rounded-lg px-2.5 py-1.5 text-xs bg-gray-50" placeholder="'+esc(ph)+'" disabled></div>';
                        }
                    });
                    html += '<button class="w-full py-2 rounded-lg text-white font-bold text-xs bg-blue-600 opacity-80 cursor-default mt-2">রেজিস্ট্রেশন করুন</button>';
                    preview.innerHTML = html;
                    
                    // Also update hidden input for form save
                    serializeRegFields();
                }

                function serializeRegFields() {
                    const fields = [];
                    list.querySelectorAll('.reg-field-item').forEach(item => {
                        const isSys = item.dataset.system === '1';
                        fields.push({
                            key: item.dataset.key,
                            label: item.querySelector('.reg-label')?.value || '',
                            placeholder: item.querySelector('.reg-placeholder')?.value || '',
                            enabled: isSys || (item.querySelector('.reg-enabled')?.checked ?? true),
                            required: isSys || (item.querySelector('.reg-required')?.checked ?? false),
                        });
                    });
                    document.getElementById('regFieldsInput').value = JSON.stringify(fields);
                }

                window.resetRegFields = function() {
                    if (!confirm('Reset registration fields to default?')) return;
                    document.getElementById('regFieldsInput').value = JSON.stringify(<?= json_encode(array_map(fn($f) => ['key'=>$f['key'],'label'=>$f['label'],'placeholder'=>$f['placeholder'],'enabled'=>$f['enabled'],'required'=>$f['required']], $regDefaults), JSON_UNESCAPED_UNICODE) ?>);
                    document.querySelector('form').submit();
                };

                updateRegPreview();
            })();
            </script>

            <?php elseif ($tab === 'clerk'): ?>
            <!-- ═══════ CLERK AUTH ═══════ -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <div class="flex items-center justify-between">
                    <h4 class="font-semibold text-gray-800"><i class="fas fa-shield-alt mr-2 text-indigo-500"></i>Clerk Authentication</h4>
                    <a href="https://clerk.com/docs" target="_blank" class="text-xs text-indigo-500 hover:text-indigo-700"><i class="fas fa-external-link-alt mr-1"></i>Clerk Docs</a>
                </div>
                <p class="text-xs text-gray-500">Clerk provides social login (Google, Facebook), phone OTP, email/password, and user management. Enable Clerk to replace the default login system.</p>
                
                <!-- Master Toggle -->
                <div class="flex items-center gap-3 p-4 rounded-xl border-2 <?= ($s['clerk_enabled'] ?? '0') === '1' ? 'border-green-300 bg-green-50' : 'border-gray-200 bg-gray-50' ?>">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="clerk_enabled" value="1" <?= ($s['clerk_enabled'] ?? '0') === '1' ? 'checked' : '' ?> class="sr-only peer"
                               onchange="document.getElementById('clerk-fields').classList.toggle('hidden', !this.checked);this.closest('.flex').classList.toggle('border-green-300',this.checked);this.closest('.flex').classList.toggle('bg-green-50',this.checked);this.closest('.flex').classList.toggle('border-gray-200',!this.checked);this.closest('.flex').classList.toggle('bg-gray-50',!this.checked)">
                        <div class="w-11 h-6 bg-gray-300 peer-checked:bg-green-500 rounded-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"></div>
                    </label>
                    <div>
                        <span class="font-semibold text-gray-800">Enable Clerk Authentication</span>
                        <p class="text-xs text-gray-500">Social login, phone OTP, and modern auth for customers</p>
                    </div>
                </div>

                <div id="clerk-fields" class="space-y-4 <?= ($s['clerk_enabled'] ?? '0') !== '1' ? 'hidden' : '' ?>">
                    <!-- API Keys -->
                    <div class="bg-indigo-50 rounded-xl p-4 space-y-3">
                        <h5 class="text-sm font-semibold text-indigo-800"><i class="fas fa-key mr-1"></i>API Keys</h5>
                        <p class="text-xs text-indigo-600">Get these from <a href="https://dashboard.clerk.com/last-active?path=api-keys" target="_blank" class="underline font-medium">Clerk Dashboard → API Keys</a></p>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Publishable Key</label>
                            <input type="text" name="clerk_publishable_key" value="<?= e($s['clerk_publishable_key'] ?? '') ?>" 
                                   class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" placeholder="pk_live_...">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Secret Key</label>
                            <input type="password" name="clerk_secret_key" value="<?= e($s['clerk_secret_key'] ?? '') ?>" 
                                   class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" placeholder="sk_live_...">
                        </div>
                    </div>

                    <!-- Social Login Providers -->
                    <div class="bg-white rounded-xl border p-4 space-y-3">
                        <h5 class="text-sm font-semibold text-gray-800"><i class="fas fa-users mr-1"></i>Social Login Providers</h5>
                        <p class="text-xs text-gray-500">Enable these in your <a href="https://dashboard.clerk.com/last-active?path=user-authentication/social-connections" target="_blank" class="text-indigo-600 underline">Clerk Dashboard</a> first, then toggle here.</p>
                        <div class="grid sm:grid-cols-3 gap-3">
                            <label class="flex items-center gap-2 p-3 rounded-lg border cursor-pointer hover:bg-gray-50">
                                <input type="checkbox" name="clerk_social_google" value="1" <?= ($s['clerk_social_google'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded">
                                <i class="fab fa-google text-red-500"></i>
                                <span class="text-sm font-medium">Google</span>
                            </label>
                            <label class="flex items-center gap-2 p-3 rounded-lg border cursor-pointer hover:bg-gray-50">
                                <input type="checkbox" name="clerk_social_facebook" value="1" <?= ($s['clerk_social_facebook'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded">
                                <i class="fab fa-facebook text-blue-600"></i>
                                <span class="text-sm font-medium">Facebook</span>
                            </label>
                            <label class="flex items-center gap-2 p-3 rounded-lg border cursor-pointer hover:bg-gray-50">
                                <input type="checkbox" name="clerk_social_phone" value="1" <?= ($s['clerk_social_phone'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded">
                                <i class="fas fa-phone-alt text-green-600"></i>
                                <span class="text-sm font-medium">Phone OTP</span>
                            </label>
                        </div>
                    </div>

                    <!-- Behavior -->
                    <div class="bg-white rounded-xl border p-4 space-y-3">
                        <h5 class="text-sm font-semibold text-gray-800"><i class="fas fa-cog mr-1"></i>Behavior</h5>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="clerk_keep_legacy_login" value="1" <?= ($s['clerk_keep_legacy_login'] ?? '1') === '1' ? 'checked' : '' ?> class="rounded">
                            <span class="text-sm text-gray-700">Keep legacy phone/password login as fallback</span>
                        </label>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">After Sign In → Redirect To</label>
                                <input type="text" name="clerk_after_sign_in" value="<?= e($s['clerk_after_sign_in'] ?? '/account') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="/account">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">After Sign Up → Redirect To</label>
                                <input type="text" name="clerk_after_sign_up" value="<?= e($s['clerk_after_sign_up'] ?? '/account') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="/account">
                            </div>
                        </div>
                    </div>

                    <!-- Webhook URL -->
                    <div class="bg-amber-50 rounded-xl p-4">
                        <h5 class="text-sm font-semibold text-amber-800 mb-1"><i class="fas fa-link mr-1"></i>Webhook URL</h5>
                        <p class="text-xs text-amber-700 mb-2">Add this URL in your <a href="https://dashboard.clerk.com/last-active?path=webhooks" target="_blank" class="underline font-medium">Clerk Dashboard → Webhooks</a>. Subscribe to: <code>user.created</code>, <code>user.updated</code>, <code>user.deleted</code></p>
                        <div class="flex items-center gap-2">
                            <code class="flex-1 bg-white border px-3 py-2 rounded-lg text-xs font-mono text-gray-700 select-all"><?= SITE_URL ?>/api/clerk-sync.php</code>
                            <button type="button" onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/api/clerk-sync.php').then(()=>this.innerHTML='<i class=\'fas fa-check text-green-500\'></i>')" class="px-3 py-2 bg-white border rounded-lg text-xs hover:bg-gray-50"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif ($tab === 'seo'): ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-search mr-2 text-teal-500"></i>SEO & Meta Tags</h4>
                <p class="text-xs text-gray-500">These settings control how your site appears in Google, Facebook, and other platforms.</p>
                
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Site Meta Title</label>
                        <input type="text" name="meta_title" value="<?= e($s['meta_title'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="Your Site Name — Tagline">
                        <p class="text-xs text-gray-400 mt-1">Shows in Google search results & browser tab (50-60 chars recommended)</p></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Site Meta Description</label>
                        <textarea name="meta_description" rows="2" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="Brief description of your business..."><?= e($s['meta_description'] ?? '') ?></textarea>
                        <p class="text-xs text-gray-400 mt-1">Shows below title in Google (150-160 chars recommended)</p></div>
                </div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Default Meta Keywords</label>
                    <input type="text" name="meta_keywords" value="<?= e($s['meta_keywords'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="keyword1, keyword2, keyword3">
                    <p class="text-xs text-gray-400 mt-1">Comma-separated keywords (less important for Google now, but used by some engines)</p></div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fab fa-facebook mr-2 text-blue-500"></i>Open Graph (Social Sharing)</h4>
                <p class="text-xs text-gray-500">Controls how links look when shared on Facebook, WhatsApp, Messenger, etc.</p>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Default OG Image</label>
                    <input type="text" name="og_image" value="<?= e($s['og_image'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="https://khatibangla.com/uploads/og-image.jpg">
                    <p class="text-xs text-gray-400 mt-1">Used when sharing pages without their own image. Recommended: 1200×630px</p></div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-check-circle mr-2 text-green-500"></i>Search Engine Verification</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Google Search Console</label>
                        <input type="text" name="google_site_verification" value="<?= e($s['google_site_verification'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="Verification code from Google">
                        <p class="text-xs text-gray-400 mt-1">From <a href="https://search.google.com/search-console" target="_blank" class="text-blue-500 underline">Google Search Console</a> → Settings → Ownership verification → HTML tag</p></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Bing Webmaster</label>
                        <input type="text" name="bing_site_verification" value="<?= e($s['bing_site_verification'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="Verification code from Bing">
                        <p class="text-xs text-gray-400 mt-1">From <a href="https://www.bing.com/webmasters" target="_blank" class="text-blue-500 underline">Bing Webmaster Tools</a></p></div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-robot mr-2 text-purple-500"></i>Robots & Sitemap</h4>
                <p class="text-xs text-gray-500">Your sitemap is auto-generated at <a href="<?= SITE_URL ?>/sitemap.xml" target="_blank" class="text-blue-500 underline"><?= SITE_URL ?>/sitemap.xml</a></p>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Custom Robots.txt</label>
                    <textarea name="robots_txt" rows="6" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" placeholder="Leave empty for smart defaults"><?= e($s['robots_txt'] ?? '') ?></textarea>
                    <p class="text-xs text-gray-400 mt-1">Leave empty for auto-generated robots.txt. View at <a href="<?= SITE_URL ?>/robots.txt" target="_blank" class="text-blue-500 underline">/robots.txt</a></p></div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-globe mr-2 text-indigo-500"></i>Social Media Profiles</h4>
                <p class="text-xs text-gray-500">Used in structured data (Schema.org) for Google Knowledge Panel</p>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1"><i class="fab fa-facebook text-blue-600 mr-1"></i>Facebook Page URL</label>
                        <input type="url" name="social_facebook" value="<?= e($s['social_facebook'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="https://facebook.com/yourpage"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1"><i class="fab fa-instagram text-pink-500 mr-1"></i>Instagram URL</label>
                        <input type="url" name="social_instagram" value="<?= e($s['social_instagram'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="https://instagram.com/yourpage"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1"><i class="fab fa-youtube text-red-600 mr-1"></i>YouTube Channel URL</label>
                        <input type="url" name="social_youtube" value="<?= e($s['social_youtube'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="https://youtube.com/@yourchannel"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1"><i class="fab fa-tiktok mr-1"></i>TikTok URL</label>
                        <input type="url" name="social_tiktok" value="<?= e($s['social_tiktok'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="https://tiktok.com/@yourpage"></div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-map-marker-alt mr-2 text-red-500"></i>Local Business Info (for Google)</h4>
                <p class="text-xs text-gray-500">Helps Google show your business in local search results</p>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Business Address</label>
                        <input type="text" name="site_address" value="<?= e($s['site_address'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="123 Main St, Dhaka, Bangladesh"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Business Email</label>
                        <input type="email" name="site_email" value="<?= e($s['site_email'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="info@khatibangla.com"></div>
                </div>
            </div>

            <?php elseif ($tab === 'advanced'): ?>
            <!-- Maintenance Mode -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-hard-hat mr-2 text-amber-500"></i>Maintenance Mode</h4>
                <p class="text-xs text-gray-500">ভিজিটররা মিনি-গেমসহ একটি মেইনটেন্যান্স পেজ দেখবে। অ্যাডমিনরা স্বাভাবিকভাবে সাইট দেখতে পারবেন।</p>
                
                <!-- Toggle -->
                <div class="flex items-center gap-3 p-3 rounded-lg <?= ($s['maintenance_mode'] ?? '0') === '1' ? 'bg-amber-50 border border-amber-300' : 'bg-gray-50 border border-gray-200' ?>">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="hidden" name="maintenance_mode" value="0">
                        <input type="checkbox" name="maintenance_mode" value="1" <?= ($s['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?> class="rounded border-gray-300 text-amber-600 focus:ring-amber-500 w-5 h-5">
                        <span class="text-sm font-semibold <?= ($s['maintenance_mode'] ?? '0') === '1' ? 'text-amber-700' : 'text-gray-700' ?>">
                            <?= ($s['maintenance_mode'] ?? '0') === '1' ? '🟡 সাইটটি মেইনটেন্যান্স মোডে আছে' : 'মেইনটেন্যান্স মোড চালু করুন' ?>
                        </span>
                    </label>
                </div>
                
                <?php if (($s['maintenance_mode'] ?? '0') === '1'): ?>
                <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg">
                    <p class="text-xs text-amber-700"><strong>⚠️ সাইটটি এখন মেইনটেন্যান্স মোডে আছে।</strong> ভিজিটররা গেম পেজ দেখছে।</p>
                    <?php if (!empty($s['maintenance_bypass_key'])): ?>
                    <p class="text-xs text-amber-600 mt-1">শেয়ার লিংক: <code class="bg-white px-1 py-0.5 rounded text-xs select-all"><?= SITE_URL ?>?bypass=<?= e($s['maintenance_bypass_key']) ?></code></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Game Selector -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">🎮 গেম সিলেক্ট করুন</label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="relative cursor-pointer">
                            <input type="radio" name="maintenance_game" value="space" <?= ($s['maintenance_game'] ?? 'space') === 'space' ? 'checked' : '' ?> class="peer sr-only" onchange="updateMaintPreview()">
                            <div class="p-4 rounded-xl border-2 transition-all peer-checked:border-indigo-500 peer-checked:bg-indigo-50 border-gray-200 hover:border-gray-300 text-center">
                                <div class="text-3xl mb-1">🚀</div>
                                <div class="text-sm font-bold text-gray-800">স্পেস রানার</div>
                                <div class="text-[10px] text-gray-500 mt-1">ডার্ক থিম • মহাকাশ</div>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="maintenance_game" value="monkey" <?= ($s['maintenance_game'] ?? 'space') === 'monkey' ? 'checked' : '' ?> class="peer sr-only" onchange="updateMaintPreview()">
                            <div class="p-4 rounded-xl border-2 transition-all peer-checked:border-amber-500 peer-checked:bg-amber-50 border-gray-200 hover:border-gray-300 text-center">
                                <div class="text-3xl mb-1">🐒</div>
                                <div class="text-sm font-bold text-gray-800">বানানা জাম্প</div>
                                <div class="text-[10px] text-gray-500 mt-1">লাইট থিম • জঙ্গল</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Text Editor -->
                <div class="border-t pt-4 space-y-3">
                    <h5 class="text-sm font-semibold text-gray-700"><i class="fas fa-pen-fancy mr-1 text-gray-400"></i>টেক্সট কাস্টমাইজ</h5>
                    <div class="grid md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">শিরোনাম (ব্যাজ টেক্সট)</label>
                            <input type="text" name="maintenance_title" value="<?= e($s['maintenance_title'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="রক্ষণাবেক্ষণ চলছে" oninput="updateMaintPreview()">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">আনুমানিক সময়</label>
                            <input type="text" name="maintenance_eta" value="<?= e($s['maintenance_eta'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="যেমন: ৩০ মিনিট" oninput="updateMaintPreview()">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">মূল বার্তা</label>
                        <textarea name="maintenance_message" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="আমাদের সাইটটি আপডেট হচ্ছে। কিছুক্ষণের মধ্যেই ফিরে আসবে।" oninput="updateMaintPreview()"><?= e($s['maintenance_message'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Live Preview -->
                <div class="border-t pt-4">
                    <div class="flex items-center justify-between mb-3">
                        <h5 class="text-sm font-semibold text-gray-700"><i class="fas fa-eye mr-1 text-gray-400"></i>প্রিভিউ</h5>
                        <a href="<?= SITE_URL ?>/maintenance-preview" target="_blank" class="text-xs text-blue-600 hover:underline"><i class="fas fa-external-link-alt mr-1"></i>ফুল প্রিভিউ</a>
                    </div>
                    <div id="maintPreview" class="rounded-xl overflow-hidden border" style="height:240px">
                        <div id="mpInner" style="transform:scale(0.5);transform-origin:top left;width:200%;height:200%">
                            <!-- Filled by JS -->
                        </div>
                    </div>
                </div>

                <!-- Bypass Key -->
                <div class="border-t pt-4">
                    <label class="block text-xs font-medium text-gray-600 mb-1">🔑 বাইপাস কী (ঐচ্ছিক)</label>
                    <div class="flex gap-2">
                        <input type="text" name="maintenance_bypass_key" value="<?= e($s['maintenance_bypass_key'] ?? '') ?>" class="flex-1 px-3 py-2 border rounded-lg text-sm font-mono" placeholder="সিক্রেট কী" id="bypassKeyInput">
                        <button type="button" onclick="document.getElementById('bypassKeyInput').value=Math.random().toString(36).substr(2,10)" class="px-3 py-2 bg-gray-100 border rounded-lg text-xs font-medium hover:bg-gray-200 whitespace-nowrap">Generate</button>
                    </div>
                    <p class="text-xs text-gray-400 mt-1"><code class="bg-gray-100 px-1 rounded">?bypass=KEY</code> দিয়ে নির্দিষ্ট ব্যক্তিরা সাইট দেখতে পারবে</p>
                </div>
            </div>

            <script>
            function updateMaintPreview(){
                const game = document.querySelector('[name="maintenance_game"]:checked')?.value || 'space';
                const title = document.querySelector('[name="maintenance_title"]')?.value || 'রক্ষণাবেক্ষণ চলছে';
                const msg = document.querySelector('[name="maintenance_message"]')?.value || 'আমাদের সাইটটি আপডেট হচ্ছে। ততক্ষণ গেমটি উপভোগ করুন! 🎮';
                const eta = document.querySelector('[name="maintenance_eta"]')?.value || '';
                const isDark = game === 'space';
                const icon = isDark ? '🚀' : '🐒';
                const gameName = isDark ? 'স্পেস রানার' : 'বানানা জাম্প';
                const bg = isDark ? '#0b0f1a' : '#fef9ef';
                const txt = isDark ? '#e2e8f0' : '#3d2c1e';
                const sub = isDark ? '#94a3b8' : '#78716c';
                const badgeBg = isDark ? 'rgba(239,68,68,.15)' : 'rgba(245,158,11,.12)';
                const badgeBorder = isDark ? 'rgba(239,68,68,.3)' : 'rgba(245,158,11,.3)';
                const badgeColor = isDark ? '#fca5a5' : '#b45309';
                const boxBg = isDark ? 'rgba(255,255,255,.04)' : '#fff';
                const boxBorder = isDark ? 'rgba(255,255,255,.06)' : '#e7e5e4';
                const canvasBg = isDark ? '#080c16' : '#f0fdf4';

                document.getElementById('mpInner').innerHTML = `
                <div style="background:${bg};min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px;font-family:Segoe UI,system-ui,sans-serif">
                    <div style="font-size:36px;font-weight:900;color:${txt};margin-bottom:8px"><?= htmlspecialchars($siteName) ?></div>
                    <div style="display:inline-block;padding:6px 18px;border-radius:20px;font-size:15px;font-weight:600;margin-bottom:16px;background:${badgeBg};border:1px solid ${badgeBorder};color:${badgeColor}">
                        ${isDark?'🔧':'🍌'} ${title}
                    </div>
                    <p style="font-size:17px;color:${sub};line-height:1.7;text-align:center;max-width:500px;margin-bottom:16px">${msg.replace(/\\n/g,'<br>')}</p>
                    ${eta ? '<div style="font-size:14px;color:'+sub+';margin-bottom:12px">🕐 আনুমানিক সময়: '+eta+'</div>' : ''}
                    <div style="background:${boxBg};border:1px solid ${boxBorder};border-radius:18px;padding:16px;width:100%;max-width:500px">
                        <div style="display:flex;justify-content:space-between;font-size:15px;color:${sub};margin-bottom:10px">
                            <span>${icon} স্কোর: <b style="color:${txt}">0</b></span>
                            <span>⚡ গতি: <b style="color:${txt}">1</b>x</span>
                            <span>🏆 সেরা: <b style="color:${txt}">0</b></span>
                        </div>
                        <div style="background:${canvasBg};border-radius:12px;height:120px;display:flex;align-items:center;justify-content:center;position:relative">
                            <div style="text-align:center">
                                <div style="font-size:60px;animation:none">${icon}</div>
                                <div style="font-size:17px;font-weight:700;color:${txt}">${gameName}</div>
                                <div style="font-size:13px;color:${sub};margin-top:4px">SPACE / TAP</div>
                            </div>
                        </div>
                    </div>
                    <div style="font-size:13px;color:${isDark?'#334155':'#d6d3d1'};margin-top:20px">শীঘ্রই ফিরে আসছি ❤️</div>
                </div>`;
            }
            document.addEventListener('DOMContentLoaded', updateMaintPreview);
            </script>

            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-tools mr-2 text-gray-500"></i>Advanced Settings</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Items Per Page</label>
                        <input type="number" name="items_per_page" value="<?= e($s['items_per_page'] ?? '20') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Order & SKU Settings</label>
                        <a href="?tab=ordersku" class="inline-flex items-center gap-2 px-4 py-2.5 border border-blue-200 bg-blue-50 rounded-lg text-sm text-blue-700 hover:bg-blue-100 transition">
                            <i class="fas fa-barcode"></i> Go to Order & SKU Settings
                        </a>
                        <p class="text-xs text-gray-400 mt-1">Order number format, SKU prefix, digits & more</p>
                    </div>
                </div>
            </div>
            <?php elseif ($tab === 'email'): ?>
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-envelope mr-2 text-blue-500"></i>Email / SMTP Settings</h4>
                <p class="text-sm text-gray-500">Configure email for password reset, order notifications, etc. By default uses PHP mail(). Enable SMTP for better deliverability.</p>
                
                <div class="flex items-center gap-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="hidden" name="smtp_enabled" value="0">
                        <input type="checkbox" name="smtp_enabled" value="1" <?= ($s['smtp_enabled'] ?? '0') === '1' ? 'checked' : '' ?> class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" onchange="document.getElementById('smtp-fields').style.display=this.checked?'block':'none'">
                        <span class="text-sm font-medium text-blue-700">Enable SMTP</span>
                    </label>
                    <span class="text-xs text-blue-500">(Unchecked = uses PHP mail() which works on cPanel by default)</span>
                </div>
                
                <div id="smtp-fields" style="display:<?= ($s['smtp_enabled'] ?? '0') === '1' ? 'block' : 'none' ?>">
                    <div class="grid md:grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">SMTP Host *</label>
                            <input type="text" name="smtp_host" value="<?= e($s['smtp_host'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="mail.yourdomain.com"></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">SMTP Port *</label>
                            <input type="number" name="smtp_port" value="<?= e($s['smtp_port'] ?? '587') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="587"></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">SMTP Username *</label>
                            <input type="text" name="smtp_username" value="<?= e($s['smtp_username'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="email@yourdomain.com"></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">SMTP Password *</label>
                            <input type="password" name="smtp_password" value="<?= e($s['smtp_password'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="••••••"></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
                            <select name="smtp_encryption" class="w-full px-3 py-2.5 border rounded-lg text-sm">
                                <option value="tls" <?= ($s['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
                                <option value="ssl" <?= ($s['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                                <option value="none" <?= ($s['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                            </select></div>
                    </div>
                </div>
                
                <div class="border-t pt-4">
                    <h5 class="font-medium text-gray-700 text-sm mb-3">Sender Info</h5>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">From Email</label>
                            <input type="email" name="smtp_from_email" value="<?= e($s['smtp_from_email'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="noreply@yourdomain.com"></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">From Name</label>
                            <input type="text" name="smtp_from_name" value="<?= e($s['smtp_from_name'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="<?= e($s['site_name'] ?? 'MyShop') ?>"></div>
                    </div>
                </div>
                
                <div class="border-t pt-4">
                    <h5 class="font-medium text-gray-700 text-sm mb-3">Test Email</h5>
                    <div class="flex gap-2">
                        <input type="email" id="test-email" class="flex-1 px-3 py-2.5 border rounded-lg text-sm" placeholder="your@email.com">
                        <button type="button" onclick="sendTestEmail()" class="bg-green-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-green-700">
                            <i class="fas fa-paper-plane mr-1"></i>Send Test
                        </button>
                        <button type="button" onclick="runDiagnose()" class="bg-gray-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-700">
                            <i class="fas fa-stethoscope mr-1"></i>Diagnose
                        </button>
                    </div>
                    <div id="test-result" class="mt-2 text-sm hidden"></div>
                    <div id="diagnose-result" class="mt-3 hidden"></div>
                </div>
            </div>
            
            <script>
            function sendTestEmail() {
                const email = document.getElementById('test-email').value;
                if (!email) { alert('Enter a test email address'); return; }
                const r = document.getElementById('test-result');
                r.className = 'mt-2 text-sm text-gray-500';
                r.textContent = 'Sending...';
                r.classList.remove('hidden');
                
                const fd = new FormData();
                fd.append('action', 'test_email');
                fd.append('email', email);
                fetch('<?= adminUrl("api/email-test.php") ?>', {method:'POST', body: fd})
                    .then(res => res.json())
                    .then(d => {
                        r.className = 'mt-2 text-sm ' + (d.success ? 'text-green-600' : 'text-red-600');
                        r.textContent = d.message;
                    })
                    .catch(e => { r.className = 'mt-2 text-sm text-red-600'; r.textContent = 'Error: ' + e.message; });
            }
            function runDiagnose() {
                const r = document.getElementById('diagnose-result');
                r.classList.remove('hidden');
                r.innerHTML = '<p class="text-gray-500 text-sm">Running diagnostics...</p>';
                
                const fd = new FormData();
                fd.append('action', 'diagnose');
                fetch('<?= adminUrl("api/email-test.php") ?>', {method:'POST', body: fd})
                    .then(res => res.json())
                    .then(d => {
                        if (d.diagnostics) {
                            let html = '<div class="bg-gray-50 border rounded-lg p-4 text-xs font-mono space-y-1">';
                            html += '<p class="font-semibold text-gray-700 text-sm mb-2">📋 Email Diagnostics</p>';
                            for (const [k, v] of Object.entries(d.diagnostics)) {
                                const label = k.replace(/_/g, ' ').replace(/^port /, '');
                                const color = String(v).includes('✅') ? 'text-green-700' : String(v).includes('❌') ? 'text-red-600' : 'text-gray-600';
                                html += '<div class="flex gap-2"><span class="text-gray-500 w-40 flex-shrink-0">' + label + ':</span><span class="' + color + '">' + v + '</span></div>';
                            }
                            html += '</div>';
                            r.innerHTML = html;
                        }
                    })
                    .catch(e => { r.innerHTML = '<p class="text-red-600 text-sm">Error: ' + e.message + '</p>'; });
            }
            </script>

            <?php endif; ?>

            <?php if ($tab === 'print'): ?>
            <?php
            $curInvTpl = $s['selected_invoice_template'] ?? 'inv_standard';
            $curStkTpl = $s['selected_sticker_template'] ?? 'stk_standard';
            $curLayout = $s['print_default_layout'] ?? 'a4_1';
            $curBarcode = ($s['print_show_barcode'] ?? '1') === '1';
            $curShipNote = $s['invoice_shipping_note'] ?? '';
            $curFooter = $s['invoice_custom_footer'] ?? '';

            $invNames = ['inv_standard'=>'📄 Standard','inv_compact'=>'📋 Compact/Thermal','inv_modern'=>'🎨 Modern','inv_branded'=>'🏷 Branded','inv_detailed'=>'📸 Detailed','inv_minimal'=>'✨ Minimal','inv_picking'=>'📦 Picking Sheet'];
            $stkNames = ['stk_standard'=>'🏷 Standard','stk_detailed'=>'📋 Detailed','stk_courier'=>'🚚 Courier','stk_pos'=>'🧾 POS Receipt','stk_cod'=>'💵 COD Badge','stk_wide'=>'↔ Wide','stk_sku'=>'🔖 SKU','stk_note'=>'📝 Note'];
            // Load custom presets
            $custInv = $db->fetchAll("SELECT template_key, name FROM print_templates WHERE template_type='invoice' AND is_builtin=0 AND template_key NOT LIKE '\\_preview\\_%' ORDER BY name") ?: [];
            $custStk = $db->fetchAll("SELECT template_key, name FROM print_templates WHERE template_type='sticker' AND is_builtin=0 AND template_key NOT LIKE '\\_preview\\_%' ORDER BY name") ?: [];
            ?>

            <!-- Default Templates -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-file-invoice mr-2 text-blue-500"></i>Default Templates</h4>
                <p class="text-xs text-gray-500 -mt-3">Choose which template is used by default when printing orders</p>

                <div class="grid md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Invoice Template</label>
                        <select name="selected_invoice_template" class="w-full px-3 py-2.5 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300">
                            <optgroup label="Built-in">
                            <?php foreach ($invNames as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= $curInvTpl===$k?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                            </optgroup>
                            <?php if (!empty($custInv)): ?>
                            <optgroup label="Custom Presets">
                            <?php foreach ($custInv as $ci): ?>
                            <option value="<?= e($ci['template_key']) ?>" <?= $curInvTpl===$ci['template_key']?'selected':'' ?>>⚡ <?= e($ci['name']) ?></option>
                            <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                        <a href="<?= adminUrl('pages/select-invoice.php') ?>" class="inline-flex items-center gap-1 text-xs text-blue-600 hover:underline mt-1.5">
                            <i class="fas fa-external-link-alt text-[10px]"></i> Preview &amp; manage templates
                        </a>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Sticker Template</label>
                        <select name="selected_sticker_template" class="w-full px-3 py-2.5 border rounded-lg text-sm focus:ring-2 focus:ring-blue-300">
                            <optgroup label="Built-in">
                            <?php foreach ($stkNames as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= $curStkTpl===$k?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                            </optgroup>
                            <?php if (!empty($custStk)): ?>
                            <optgroup label="Custom Presets">
                            <?php foreach ($custStk as $cs): ?>
                            <option value="<?= e($cs['template_key']) ?>" <?= $curStkTpl===$cs['template_key']?'selected':'' ?>>⚡ <?= e($cs['name']) ?></option>
                            <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                        <a href="<?= adminUrl('pages/select-sticker.php') ?>" class="inline-flex items-center gap-1 text-xs text-teal-600 hover:underline mt-1.5">
                            <i class="fas fa-external-link-alt text-[10px]"></i> Preview &amp; manage templates
                        </a>
                    </div>
                </div>
            </div>

            <!-- Page Layout -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-columns mr-2 text-purple-500"></i>Page Layout (Invoice only)</h4>
                <p class="text-xs text-gray-500 -mt-3">How many invoices fit on each printed page</p>

                <div class="grid sm:grid-cols-3 gap-4">
                    <?php
                    $layouts = [
                        'a4_1' => ['A4 × 1','1 invoice per A4 page (portrait)','📄','Standard full-size print'],
                        'a3_2' => ['A3 × 2','2 invoices side-by-side on A3 (landscape)','📰','Saves paper, slightly smaller text'],
                        'a4_3' => ['A4 × 3','3 invoices stacked on A4 (portrait)','📋','Maximum paper saving, compact text'],
                    ];
                    foreach ($layouts as $lk => $ld): $isActive = ($curLayout === $lk); ?>
                    <label class="relative flex flex-col cursor-pointer rounded-xl border-2 p-4 transition hover:shadow-md <?= $isActive ? 'border-purple-500 bg-purple-50 shadow-sm' : 'border-gray-200 hover:border-gray-300' ?>">
                        <input type="radio" name="print_default_layout" value="<?= $lk ?>" <?= $isActive?'checked':'' ?> class="sr-only" onchange="document.querySelectorAll('.layout-card').forEach(c=>{c.classList.remove('border-purple-500','bg-purple-50','shadow-sm');c.classList.add('border-gray-200')});this.closest('label').classList.remove('border-gray-200');this.closest('label').classList.add('border-purple-500','bg-purple-50','shadow-sm')">
                        <?php if ($isActive): ?>
                        <div class="absolute top-2 right-2 w-5 h-5 bg-purple-500 rounded-full flex items-center justify-center">
                            <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <?php endif; ?>
                        <div class="text-2xl mb-2"><?= $ld[2] ?></div>
                        <div class="font-bold text-sm text-gray-800"><?= $ld[0] ?></div>
                        <div class="text-xs text-gray-500 mt-1"><?= $ld[1] ?></div>
                        <div class="text-[10px] text-gray-400 mt-1"><?= $ld[3] ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Barcode & Extra Options -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-barcode mr-2 text-green-500"></i>Barcode &amp; Options</h4>
                <div class="space-y-4">
                    <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition cursor-pointer">
                        <input type="hidden" name="print_show_barcode" value="0">
                        <input type="checkbox" name="print_show_barcode" value="1" class="rounded text-blue-600 w-4 h-4" <?= $curBarcode?'checked':'' ?>>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Show barcode on invoices &amp; stickers</span>
                            <p class="text-xs text-gray-400">Code128 barcode with order number, scannable by barcode readers</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Custom Text -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-pen-fancy mr-2 text-orange-500"></i>Default Text</h4>
                <p class="text-xs text-gray-500 -mt-3">These are used when no custom text is set in a custom template preset</p>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Shipping Note</label>
                        <input type="text" name="invoice_shipping_note" value="<?= e($curShipNote) ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="e.g. Allow 3-5 business days for delivery">
                        <p class="text-xs text-gray-400 mt-1">Shown on templates where "Shipping Note" is enabled</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Footer Message</label>
                        <input type="text" name="invoice_custom_footer" value="<?= e($curFooter) ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="e.g. Thank you for shopping with us!">
                        <p class="text-xs text-gray-400 mt-1">Shown at the bottom of invoices</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'ordersku'): ?>
            <!-- Order Number Settings -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-receipt mr-2 text-blue-500"></i>Order Number Format</h4>
                <p class="text-xs text-gray-500 -mt-3">Configure how order numbers are generated for new orders. Changes only affect future orders.</p>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Format Type</label>
                    <div class="grid sm:grid-cols-3 gap-3">
                        <?php
                        $ordFmt = $s['order_number_format'] ?? 'numeric';
                        $ordFmtOptions = [
                            'numeric' => ['Sequential Number', 'e.g. 00001, 00002, 00003', 'fa-sort-numeric-down'],
                            'prefix_numeric' => ['Prefix + Padded Number', 'e.g. ORD-00001, ORD-00002', 'fa-font'],
                            'prefix_sequential' => ['Prefix + Auto Number', 'e.g. KB1, KB2, KB3', 'fa-bolt'],
                        ];
                        foreach ($ordFmtOptions as $fKey => $fData): ?>
                        <label class="relative flex flex-col items-center gap-1.5 p-4 border-2 rounded-xl cursor-pointer transition hover:border-blue-300 ord-fmt-label <?= $ordFmt === $fKey ? 'border-blue-500 bg-blue-50' : 'border-gray-200' ?>">
                            <input type="radio" name="order_number_format" value="<?= $fKey ?>" <?= $ordFmt === $fKey ? 'checked' : '' ?> class="sr-only" onchange="updateOrderPreview()">
                            <i class="fas <?= $fData[2] ?> text-lg ord-fmt-icon <?= $ordFmt === $fKey ? 'text-blue-600' : 'text-gray-400' ?>"></i>
                            <span class="text-sm font-medium text-gray-800"><?= $fData[0] ?></span>
                            <span class="text-[11px] text-gray-400 text-center"><?= $fData[1] ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Prefix</label>
                        <input type="text" name="order_prefix" id="order_prefix" value="<?= e($s['order_prefix'] ?? '') ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm" placeholder="e.g. ORD-, KB, INV-" maxlength="10" oninput="updateOrderPreview()">
                        <p class="text-[11px] text-gray-400 mt-1">Leave empty for no prefix. Max 10 chars.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Number of Digits</label>
                        <select name="order_number_digits" id="order_number_digits" class="w-full px-3 py-2.5 border rounded-lg text-sm" onchange="updateOrderPreview()">
                            <?php $ordDigits = intval($s['order_number_digits'] ?? 5); ?>
                            <?php for ($d = 4; $d <= 8; $d++): ?>
                            <option value="<?= $d ?>" <?= $ordDigits === $d ? 'selected' : '' ?>><?= $d ?> digits (<?= str_pad('1', $d, '0', STR_PAD_LEFT) ?>)</option>
                            <?php endfor; ?>
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">Zero-padding. Applies to numeric &amp; prefix+padded.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Next Number</label>
                        <?php
                        $nextOrd = 1;
                        try {
                            $maxOrd = $db->fetch("SELECT MAX(id) as m FROM orders")['m'] ?? 0;
                            $nextOrd = max($maxOrd + 1, 1);
                        } catch (Exception $e) {}
                        ?>
                        <input type="text" value="<?= $nextOrd ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-gray-50 text-gray-500" readonly>
                        <p class="text-[11px] text-gray-400 mt-1">Auto-calculated. Cannot be edited.</p>
                    </div>
                </div>

                <!-- Live Preview -->
                <div class="bg-gray-50 border border-dashed border-gray-300 rounded-xl p-4">
                    <p class="text-xs font-medium text-gray-500 mb-2"><i class="fas fa-eye mr-1"></i>LIVE PREVIEW</p>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-400">Next order:</span>
                        <span id="orderPreview" class="text-lg font-bold text-blue-700 font-mono tracking-wide">—</span>
                    </div>
                    <div class="flex items-center gap-3 mt-1">
                        <span class="text-xs text-gray-400">Sequence:</span>
                        <span id="orderSequence" class="text-sm text-gray-600 font-mono">—</span>
                    </div>
                </div>
            </div>

            <!-- SKU Format Settings -->
            <div class="bg-white rounded-xl shadow-sm border p-5 space-y-5">
                <h4 class="font-semibold text-gray-800"><i class="fas fa-barcode mr-2 text-green-500"></i>Product SKU Format</h4>
                <p class="text-xs text-gray-500 -mt-3">Configure how SKUs are auto-generated for new products. Changes only affect future products.</p>

                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">SKU Prefix</label>
                        <input type="text" name="sku_prefix" id="sku_prefix" value="<?= e($s['sku_prefix'] ?? strtoupper(substr($s['site_name'] ?? 'SHOP', 0, 3))) ?>" class="w-full px-3 py-2.5 border rounded-lg text-sm uppercase" placeholder="e.g. KB, PROD" maxlength="10" oninput="this.value=this.value.toUpperCase();updateSkuPreview()">
                        <p class="text-[11px] text-gray-400 mt-1">Default: first 3 letters of site name.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Separator</label>
                        <select name="sku_separator" id="sku_separator" class="w-full px-3 py-2.5 border rounded-lg text-sm" onchange="updateSkuPreview()">
                            <?php $skuSep = $s['sku_separator'] ?? '-'; ?>
                            <option value="-" <?= $skuSep === '-' ? 'selected' : '' ?>>Hyphen ( - )</option>
                            <option value="_" <?= $skuSep === '_' ? 'selected' : '' ?>>Underscore ( _ )</option>
                            <option value="" <?= $skuSep === '' ? 'selected' : '' ?>>None</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Number of Digits</label>
                        <select name="sku_digits" id="sku_digits" class="w-full px-3 py-2.5 border rounded-lg text-sm" onchange="updateSkuPreview()">
                            <?php $skuDigits = intval($s['sku_digits'] ?? 5); ?>
                            <?php for ($d = 3; $d <= 7; $d++): ?>
                            <option value="<?= $d ?>" <?= $skuDigits === $d ? 'selected' : '' ?>><?= $d ?> digits (<?= str_pad('1', $d, '0', STR_PAD_LEFT) ?>)</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Include Category Code</label>
                        <select name="sku_include_category" id="sku_include_category" class="w-full px-3 py-2.5 border rounded-lg text-sm" onchange="updateSkuPreview()">
                            <?php $skuCat = $s['sku_include_category'] ?? '0'; ?>
                            <option value="0" <?= $skuCat === '0' ? 'selected' : '' ?>>No — Prefix + Number only</option>
                            <option value="1" <?= $skuCat === '1' ? 'selected' : '' ?>>Yes — Prefix + Category + Number</option>
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">Adds first 3 letters of category. e.g. KB-ELE-00001</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Variant Suffix Style</label>
                        <select name="sku_variant_style" id="sku_variant_style" class="w-full px-3 py-2.5 border rounded-lg text-sm" onchange="updateSkuPreview()">
                            <?php $skuVarStyle = $s['sku_variant_style'] ?? 'value'; ?>
                            <option value="value" <?= $skuVarStyle === 'value' ? 'selected' : '' ?>>Full Value — KB-00001-RED</option>
                            <option value="short" <?= $skuVarStyle === 'short' ? 'selected' : '' ?>>Short (3 chars) — KB-00001-RED</option>
                            <option value="number" <?= $skuVarStyle === 'number' ? 'selected' : '' ?>>Numeric — KB-00001-01</option>
                        </select>
                    </div>
                </div>

                <!-- Live Preview -->
                <div class="bg-gray-50 border border-dashed border-gray-300 rounded-xl p-4">
                    <p class="text-xs font-medium text-gray-500 mb-2"><i class="fas fa-eye mr-1"></i>LIVE PREVIEW</p>
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400 w-24">Product SKU:</span>
                            <span id="skuPreview" class="text-lg font-bold text-green-700 font-mono tracking-wide">—</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400 w-24">With category:</span>
                            <span id="skuCatPreview" class="text-sm text-gray-600 font-mono">—</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400 w-24">Variant SKU:</span>
                            <span id="skuVariantPreview" class="text-sm text-gray-600 font-mono">—</span>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            const nextOrdNum = <?= $nextOrd ?>;
            function updateOrderPreview() {
                const fmt = document.querySelector('input[name="order_number_format"]:checked')?.value || 'numeric';
                const prefix = document.getElementById('order_prefix').value;
                const digits = parseInt(document.getElementById('order_number_digits').value);
                // Update radio card visuals
                document.querySelectorAll('.ord-fmt-label').forEach(label => {
                    const r = label.querySelector('input[type="radio"]');
                    const icon = label.querySelector('.ord-fmt-icon');
                    if (r.checked) {
                        label.className = label.className.replace('border-gray-200','').replace(/border-blue-500|bg-blue-50/g,'') + ' border-blue-500 bg-blue-50';
                        icon.classList.add('text-blue-600'); icon.classList.remove('text-gray-400');
                    } else {
                        label.className = label.className.replace(/border-blue-500|bg-blue-50/g,'') + ' border-gray-200';
                        icon.classList.remove('text-blue-600'); icon.classList.add('text-gray-400');
                    }
                });
                let preview = '', seq = '';
                const pad = (n) => String(n).padStart(digits, '0');
                if (fmt === 'numeric') {
                    preview = pad(nextOrdNum);
                    seq = [pad(nextOrdNum), pad(nextOrdNum+1), pad(nextOrdNum+2)].join(', ') + ', ...';
                } else if (fmt === 'prefix_numeric') {
                    preview = prefix + pad(nextOrdNum);
                    seq = [prefix+pad(nextOrdNum), prefix+pad(nextOrdNum+1), prefix+pad(nextOrdNum+2)].join(', ') + ', ...';
                } else if (fmt === 'prefix_sequential') {
                    preview = prefix + nextOrdNum;
                    seq = [prefix+nextOrdNum, prefix+(nextOrdNum+1), prefix+(nextOrdNum+2)].join(', ') + ', ...';
                }
                document.getElementById('orderPreview').textContent = preview || '—';
                document.getElementById('orderSequence').textContent = seq || '—';
            }
            function updateSkuPreview() {
                const prefix = document.getElementById('sku_prefix').value || 'KB';
                const sep = document.getElementById('sku_separator').value;
                const digits = parseInt(document.getElementById('sku_digits').value);
                const inclCat = document.getElementById('sku_include_category').value === '1';
                const varStyle = document.getElementById('sku_variant_style').value;
                const pad = (n) => String(n).padStart(digits, '0');
                const num = pad(42);
                const base = prefix + sep + num;
                const catSku = prefix + sep + 'ELE' + sep + num;
                document.getElementById('skuPreview').textContent = base;
                document.getElementById('skuCatPreview').textContent = inclCat ? catSku : '(disabled)';
                const srcSku = inclCat ? catSku : base;
                let varSku = '';
                if (varStyle === 'value') varSku = srcSku + sep + 'RED';
                else if (varStyle === 'short') varSku = srcSku + sep + 'RED';
                else if (varStyle === 'number') varSku = srcSku + sep + '01';
                document.getElementById('skuVariantPreview').textContent = varSku;
            }
            updateOrderPreview();
            updateSkuPreview();
            </script>
            

            <?php elseif ($tab === 'retention'): ?>
            <div class="space-y-5">
                <div class="bg-white rounded-xl border p-5">
                    <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-redo text-teal-500"></i> Retention CRM Settings
                    </h3>
                    <div class="mb-5">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Global Default Retention Period</label>
                        <p class="text-xs text-gray-400 mb-2">Used for products with no retention period set.</p>
                        <div class="flex items-center gap-2">
                            <input type="number" name="retention_default_days" min="1" max="730" value="<?= e(getSetting('retention_default_days','30')) ?>" class="w-24 px-3 py-2 border rounded-lg text-sm">
                            <span class="text-sm text-gray-500">days</span>
                        </div>
                    </div>
                    <div class="mb-5">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Due Soon Window</label>
                        <p class="text-xs text-gray-400 mb-2">Days before due date to show as "Due Soon".</p>
                        <div class="flex items-center gap-2">
                            <input type="number" name="retention_due_soon_days" min="1" max="30" value="<?= e(getSetting('retention_due_soon_days','7')) ?>" class="w-24 px-3 py-2 border rounded-lg text-sm">
                            <span class="text-sm text-gray-500">days</span>
                        </div>
                    </div>
                    <hr class="my-5">
                    <h4 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                        <i class="fas fa-robot text-indigo-400 text-sm"></i> Auto Outreach
                        <span class="text-[10px] bg-orange-100 text-orange-600 px-2 py-0.5 rounded-full font-normal ml-1">Messaging not connected — settings saved for future</span>
                    </h4>
                    <div class="space-y-3">
                        <div class="flex items-start gap-3 p-3 bg-green-50 rounded-lg border border-green-200">
                            <div class="mt-0.5"><input type="hidden" name="retention_auto_whatsapp" value="0">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="retention_auto_whatsapp" value="1" <?= getSetting('retention_auto_whatsapp','0')==='1'?'checked':'' ?> class="sr-only peer">
                                    <div class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-green-500 after:content-[\'\'] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all"></div>
                                </label></div>
                            <div><div class="flex items-center gap-1.5"><i class="fab fa-whatsapp text-green-600"></i><span class="text-sm font-semibold text-gray-800">Auto WhatsApp Reminder</span></div>
                            <p class="text-xs text-gray-500 mt-0.5">Fire WhatsApp automatically when retention due date is reached.</p></div>
                        </div>
                        <div class="flex items-start gap-3 p-3 bg-blue-50 rounded-lg border border-blue-200">
                            <div class="mt-0.5"><input type="hidden" name="retention_auto_sms" value="0">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="retention_auto_sms" value="1" <?= getSetting('retention_auto_sms','0')==='1'?'checked':'' ?> class="sr-only peer">
                                    <div class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-blue-500 after:content-[\'\'] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all"></div>
                                </label></div>
                            <div><div class="flex items-center gap-1.5"><i class="fas fa-sms text-blue-600"></i><span class="text-sm font-semibold text-gray-800">Auto SMS Reminder</span></div>
                            <p class="text-xs text-gray-500 mt-0.5">Fire SMS automatically when retention due date is reached.</p></div>
                        </div>
                        <div class="flex items-start gap-3 p-3 bg-indigo-50 rounded-lg border border-indigo-200">
                            <div class="mt-0.5"><input type="hidden" name="retention_rebuild_on_delivery" value="0">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="retention_rebuild_on_delivery" value="1" <?= getSetting('retention_rebuild_on_delivery','1')==='1'?'checked':'' ?> class="sr-only peer">
                                    <div class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-indigo-500 after:content-[\'\'] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all"></div>
                                </label></div>
                            <div><div class="flex items-center gap-1.5"><i class="fas fa-sync-alt text-indigo-600"></i><span class="text-sm font-semibold text-gray-800">Auto-add to Retention Log on Delivery</span></div>
                            <p class="text-xs text-gray-500 mt-0.5">Automatically create a retention record when an order is marked as delivered.</p></div>
                        </div>
                    </div>
                    <hr class="my-5">
                    <div class="mb-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">WhatsApp Reminder Template</label>
                        <p class="text-xs text-gray-400 mb-2">Variables: <code class="bg-gray-100 px-1 rounded">{name}</code> <code class="bg-gray-100 px-1 rounded">{product}</code> <code class="bg-gray-100 px-1 rounded">{days}</code></p>
                        <textarea name="retention_wa_template" rows="3" class="w-full px-3 py-2 border rounded-lg text-sm"><?= e(getSetting('retention_wa_template','হ্যালো {name}! আপনার {product} শেষ হওয়ার সময় হয়েছে। আবার অর্ডার করতে আমাদের সাথে যোগাযোগ করুন 😊')) ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">SMS Reminder Template</label>
                        <p class="text-xs text-gray-400 mb-2">Keep under 160 characters.</p>
                        <textarea name="retention_sms_template" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"><?= e(getSetting('retention_sms_template','{name}, your {product} needs a refill. Order now!')) ?></textarea>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="bg-teal-600 text-white px-6 py-2.5 rounded-lg font-medium hover:bg-teal-700">Save Retention Settings</button>
                </div>
            </div>

            <?php elseif ($tab === 'performance'): ?>
            <div class="space-y-5">

                <!-- Skeleton Loading -->
                <div class="bg-white rounded-xl border p-5">
                    <h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2">
                        <i class="fas fa-layer-group text-orange-500"></i> Skeleton Loading
                    </h3>
                    <p class="text-xs text-gray-400 mb-5">Shows placeholder shimmer cards while page content loads. Improves perceived speed.</p>

                    <div class="space-y-4">
                        <!-- Enable skeleton -->
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl border">
                            <div>
                                <p class="text-sm font-semibold text-gray-800">Enable Skeleton Loading</p>
                                <p class="text-xs text-gray-400 mt-0.5">Show shimmer placeholders on product listings, homepage, and category pages</p>
                            </div>
                            <div>
                                <input type="hidden" name="perf_skeleton_enabled" value="0">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="perf_skeleton_enabled" value="1" <?= getSetting('perf_skeleton_enabled','1')==='1'?'checked':'' ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-orange-500 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
                                </label>
                            </div>
                        </div>

                        <!-- Skeleton color -->
                        <div class="grid md:grid-cols-2 gap-4 pl-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Skeleton Base Color</label>
                                <input type="color" name="perf_skeleton_base" value="<?= e(getSetting('perf_skeleton_base','#e5e7eb')) ?>" class="h-9 w-full rounded-lg border cursor-pointer">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Skeleton Shimmer Color</label>
                                <input type="color" name="perf_skeleton_shimmer" value="<?= e(getSetting('perf_skeleton_shimmer','#f3f4f6')) ?>" class="h-9 w-full rounded-lg border cursor-pointer">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Page Loader -->
                <div class="bg-white rounded-xl border p-5">
                    <h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2">
                        <i class="fas fa-spinner text-blue-500"></i> Page Loader
                    </h3>
                    <p class="text-xs text-gray-400 mb-5">Full-screen overlay shown when a page takes too long to load. Activates after a delay threshold.</p>

                    <div class="space-y-4">
                        <!-- Enable loader -->
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl border">
                            <div>
                                <p class="text-sm font-semibold text-gray-800">Enable Page Loader</p>
                                <p class="text-xs text-gray-400 mt-0.5">Show a full-screen spinner overlay if load exceeds the threshold</p>
                            </div>
                            <div>
                                <input type="hidden" name="perf_loader_enabled" value="0">
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="perf_loader_enabled" value="1" <?= getSetting('perf_loader_enabled','1')==='1'?'checked':'' ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-orange-500 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
                                </label>
                            </div>
                        </div>

                        <div class="grid md:grid-cols-3 gap-4 pl-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Trigger Delay (ms)</label>
                                <p class="text-xs text-gray-400 mb-1">Show loader after this many ms</p>
                                <input type="number" name="perf_loader_delay" value="<?= e(getSetting('perf_loader_delay','800')) ?>" min="200" max="5000" step="100" class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Loader BG Color</label>
                                <p class="text-xs text-gray-400 mb-1">Overlay background</p>
                                <input type="color" name="perf_loader_bg" value="<?= e(getSetting('perf_loader_bg','#ffffff')) ?>" class="h-9 w-full rounded-lg border cursor-pointer">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Spinner Color</label>
                                <p class="text-xs text-gray-400 mb-1">Spinner / brand accent</p>
                                <input type="color" name="perf_loader_color" value="<?= e(getSetting('perf_loader_color','#f97316')) ?>" class="h-9 w-full rounded-lg border cursor-pointer">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Loader Style</label>
                                <p class="text-xs text-gray-400 mb-1">Spinner style</p>
                                <select name="perf_loader_style" class="w-full px-3 py-2 border rounded-lg text-sm">
                                    <?php foreach(['spinner'=>'Spinner Ring','dots'=>'Bouncing Dots','bar'=>'Progress Bar','logo'=>'Logo Pulse'] as $v=>$l): ?>
                                    <option value="<?= $v ?>" <?= getSetting('perf_loader_style','spinner')===$v?'selected':'' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Loader Text</label>
                                <p class="text-xs text-gray-400 mb-1">Optional message below spinner</p>
                                <input type="text" name="perf_loader_text" value="<?= e(getSetting('perf_loader_text','')) ?>" placeholder="e.g. Loading..." class="w-full px-3 py-2 border rounded-lg text-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Preview -->
                <div class="bg-white rounded-xl border p-5">
                    <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-eye text-gray-400"></i> Live Preview
                    </h3>
                    <div class="flex gap-4 flex-wrap">
                        <div>
                            <p class="text-xs text-gray-400 mb-2">Skeleton Card</p>
                            <div id="skeletonPreview" class="w-40 rounded-xl overflow-hidden border">
                                <div class="h-28 skeleton-prev"></div>
                                <div class="p-3 space-y-2">
                                    <div class="h-3 rounded skeleton-prev w-3/4"></div>
                                    <div class="h-3 rounded skeleton-prev w-1/2"></div>
                                    <div class="h-5 rounded skeleton-prev w-2/3 mt-1"></div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400 mb-2">Loader Overlay</p>
                            <div id="loaderPreview" class="w-40 h-28 rounded-xl border flex items-center justify-center flex-col gap-2" style="background:#fff">
                                <div id="previewSpinner" class="w-8 h-8 rounded-full border-4 border-gray-200 border-t-orange-500 animate-spin"></div>
                                <p id="previewLoaderText" class="text-xs text-gray-400"></p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <style>
            .skeleton-prev { background: linear-gradient(90deg, var(--sk-base,#e5e7eb) 25%, var(--sk-shimmer,#f3f4f6) 50%, var(--sk-base,#e5e7eb) 75%); background-size: 200% 100%; animation: skPrev 1.4s ease-in-out infinite; }
            @keyframes skPrev { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
            </style>
            <script>
            (function(){
                function updatePreview(){
                    var base    = document.querySelector('[name=perf_skeleton_base]')?.value || '#e5e7eb';
                    var shimmer = document.querySelector('[name=perf_skeleton_shimmer]')?.value || '#f3f4f6';
                    var bg      = document.querySelector('[name=perf_loader_bg]')?.value || '#ffffff';
                    var color   = document.querySelector('[name=perf_loader_color]')?.value || '#f97316';
                    var text    = document.querySelector('[name=perf_loader_text]')?.value || '';
                    var style   = document.querySelector('[name=perf_loader_style]')?.value || 'spinner';
                    document.documentElement.style.setProperty('--sk-base', base);
                    document.documentElement.style.setProperty('--sk-shimmer', shimmer);
                    var lp = document.getElementById('loaderPreview');
                    if(lp) lp.style.background = bg;
                    var sp = document.getElementById('previewSpinner');
                    if(sp){
                        sp.style.borderTopColor = color;
                        sp.style.display = style === 'bar' ? 'none' : '';
                    }
                    var pt = document.getElementById('previewLoaderText');
                    if(pt){ pt.textContent = text; pt.style.color = color; }
                }
                document.querySelectorAll('[name^=perf_]').forEach(function(el){
                    el.addEventListener('input', updatePreview);
                    el.addEventListener('change', updatePreview);
                });
                updatePreview();
            })();
            </script>


            <?php endif; ?>

            <div class="flex justify-end">
                <button type="submit" class="bg-blue-600 text-white px-8 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition shadow-sm">
                    <i class="fas fa-save mr-2"></i>Save Settings</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/media-picker.php'; ?>

<script>
function pickSettingImage(field) {
    openMediaLibrary(function(files) {
        if (files.length) {
            const file = files[0];
            document.getElementById(field + '_media').value = file.path;
            const map = {site_logo:'logo',site_favicon:'favicon',footer_logo:'flogo'};
            const prefix = map[field] || field;
            const img = document.getElementById(prefix + '-preview-img');
            const ph = document.getElementById(prefix + '-placeholder');
            if (img) { img.src = file.url; img.classList.remove('hidden'); }
            if (ph) ph.classList.add('hidden');
        }
    }, {multiple: false, folder: 'logos'});
}
function previewFile(input, imgId, placeholderId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.getElementById(imgId);
            const ph = document.getElementById(placeholderId);
            if (img) { img.src = e.target.result; img.classList.remove('hidden'); }
            if (ph) ph.classList.add('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}
function addFooterLink(col) {
    const html = `<div class="footer-link-row flex gap-2 items-center">
        <input type="text" name="footer_links_col${col}_label[]" placeholder="Link Text" class="flex-1 px-3 py-2 border rounded-lg text-sm">
        <input type="text" name="footer_links_col${col}_url[]" placeholder="/page or https://..." class="flex-1 px-3 py-2 border rounded-lg text-sm">
        <button type="button" onclick="this.closest('.footer-link-row').remove()" class="text-red-400 hover:text-red-600 px-2"><i class="fas fa-trash"></i></button>
    </div>`;
    document.getElementById('footer-links-' + col).insertAdjacentHTML('beforeend', html);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
