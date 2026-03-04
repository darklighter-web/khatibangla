<?php
/**
 * Landing Page Builder — Visual drag-and-drop page builder
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Landing Page Builder';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();
$pageId = intval($_GET['id'] ?? 0);
$existingPage = null;
if ($pageId) {
    $existingPage = $db->fetch("SELECT * FROM landing_pages WHERE id = ?", [$pageId]);
    if ($existingPage) {
        $existingPage['sections'] = json_decode($existingPage['sections'] ?? '[]', true);
        $existingPage['settings'] = json_decode($existingPage['settings'] ?? '{}', true);
        $existingPage['ab_variant_b'] = json_decode($existingPage['ab_variant_b'] ?? 'null', true);
    }
}
$activeTab = $_GET['tab'] ?? ($existingPage ? 'builder' : 'templates');
include __DIR__ . '/../includes/header.php';
?>
<style>
    .builder-wrap { display:grid; grid-template-columns:280px 1fr 320px; height:calc(100vh - 70px); overflow:hidden; margin:-20px -24px -20px -24px; }
    .panel { overflow-y:auto; background:#f8fafc; border-right:1px solid #e2e8f0; }
    .panel-right { border-right:none; border-left:1px solid #e2e8f0; }
    .canvas { overflow-y:auto; background:#f1f5f9; padding:20px; }
    .section-card { background:white; border:2px solid transparent; border-radius:12px; margin-bottom:12px; transition:all .2s; position:relative; }
    .section-card:hover { border-color:#3b82f6; box-shadow:0 4px 12px rgba(59,130,246,0.15); }
    .section-card.selected { border-color:#3b82f6; box-shadow:0 4px 16px rgba(59,130,246,0.2); }
    .section-card.disabled { opacity:.5; }
    .section-card .drag-handle { cursor:grab; }
    .section-card .drag-handle:active { cursor:grabbing; }
    .add-section-btn { border:2px dashed #cbd5e1; border-radius:12px; padding:16px; text-align:center; cursor:pointer; transition:all .2s; }
    .add-section-btn:hover { border-color:#3b82f6; background:#eff6ff; }
    .color-input { width:36px; height:36px; border:2px solid #e2e8f0; border-radius:8px; cursor:pointer; padding:2px; }
    .setting-group { background:white; border-radius:10px; padding:14px; margin-bottom:10px; border:1px solid #e2e8f0; }
    .setting-label { font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }
    .setting-input { width:100%; padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; }
    .setting-input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.1); }
    .setting-textarea { min-height:60px; resize:vertical; }
    .toggle-switch { position:relative; width:40px; height:22px; }
    .toggle-switch input { opacity:0; width:0; height:0; }
    .toggle-slider { position:absolute; cursor:pointer; inset:0; background:#cbd5e1; border-radius:22px; transition:.3s; }
    .toggle-slider:before { content:''; position:absolute; height:16px; width:16px; left:3px; bottom:3px; background:white; border-radius:50%; transition:.3s; }
    /* LP Checkout Field Editor Modal */
    #lpCfModal { display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);backdrop-filter:blur(2px); }
    #lpCfModal.active { display:flex;align-items:center;justify-content:center; }
    #lpCfModal .cf-panel { background:#fff;width:94vw;max-width:960px;height:88vh;border-radius:16px;display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,.2);overflow:hidden; }
    #lpCfModal .cf-header { display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid #e5e7eb;flex-shrink:0; }
    #lpCfModal .cf-body { display:grid;grid-template-columns:3fr 2fr;flex:1;overflow:hidden; }
    #lpCfModal .cf-col { overflow-y:auto;padding:20px; }
    #lpCfModal .cf-col-right { border-left:1px solid #e5e7eb;background:#f8fafc; }
    .cf-field { border:1px solid #e5e7eb;border-radius:10px;background:#fff;margin-bottom:8px;overflow:hidden;transition:all .15s; }
    .cf-field:hover { border-color:#93c5fd; }
    .cf-field.cf-off { opacity:.45; }
    .cf-field .cf-row { display:flex;align-items:center;gap:10px;padding:10px 12px;cursor:default; }
    .cf-field .cf-drag { cursor:grab;color:#cbd5e1;display:flex;padding:2px; }
    .cf-field .cf-drag:active { cursor:grabbing; }
    .cf-field .cf-icon { width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:#eff6ff;color:#3b82f6;font-size:13px;flex-shrink:0; }
    .cf-field.cf-off .cf-icon { background:#f1f5f9;color:#94a3b8; }
    .cf-field .cf-info { flex:1;min-width:0; }
    .cf-field .cf-label { font-size:13px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
    .cf-field .cf-key { font-size:10px;color:#94a3b8;text-transform:uppercase;margin-top:1px; }
    .cf-field .cf-acts { display:flex;align-items:center;gap:6px;flex-shrink:0; }
    .cf-toggle { position:relative;width:32px;height:18px;display:inline-block; }
    .cf-toggle input { opacity:0;width:0;height:0; }
    .cf-toggle .cf-track { position:absolute;inset:0;background:#cbd5e1;border-radius:18px;transition:.2s;cursor:pointer; }
    .cf-toggle .cf-track:before { content:'';position:absolute;height:14px;width:14px;left:2px;bottom:2px;background:#fff;border-radius:50%;transition:.2s; }
    .cf-toggle input:checked + .cf-track { background:#22c55e; }
    .cf-toggle input:checked + .cf-track:before { transform:translateX(14px); }
    .cf-toggle.cf-req input:checked + .cf-track { background:#f97316; }
    .cf-expand-btn { width:28px;height:28px;border:none;background:transparent;border-radius:6px;cursor:pointer;color:#94a3b8;display:flex;align-items:center;justify-content:center;transition:.15s; }
    .cf-expand-btn:hover { background:#f1f5f9;color:#64748b; }
    .cf-detail { display:none;padding:12px 16px;background:#f8fafc;border-top:1px solid #e5e7eb; }
    .cf-detail.open { display:block; }
    .cf-detail input,.cf-detail textarea { width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;outline:none; }
    .cf-detail input:focus,.cf-detail textarea:focus { border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1); }
    .cf-field.sortable-ghost { opacity:0.25;background:#dbeafe !important; }
    .cf-field.sortable-chosen { box-shadow:0 8px 25px rgba(0,0,0,.12);z-index:10; }
    /* Preview */
    .cfp-input { width:100%;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:13px;background:#fff;color:#6b7280; }
    .cfp-label { display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px; }
    .cfp-area { display:flex;gap:6px; }
    .cfp-area label { flex:1;text-align:center;padding:8px 4px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:11px;cursor:default; }
    .cfp-area label.active { border-color:#ef4444;background:#fef2f2; }
    .toggle-switch input:checked + .toggle-slider { background:#3b82f6; }
    .toggle-switch input:checked + .toggle-slider:before { transform:translateX(18px); }
    .img-picker { width:100%; aspect-ratio:16/9; border:2px dashed #e2e8f0; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer; overflow:hidden; background:#f8fafc; transition:all .2s; }
    .img-picker:hover { border-color:#3b82f6; background:#eff6ff; }
    .img-picker img { width:100%; height:100%; object-fit:cover; }
    .template-card { border:2px solid #e2e8f0; border-radius:14px; overflow:hidden; cursor:pointer; transition:all .2s; }
    .template-card:hover { border-color:#3b82f6; transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,0,0,0.1); }
    .tab-btn { padding:8px 16px; font-size:13px; font-weight:600; border-radius:8px; cursor:pointer; transition:all .2s; border:none; background:transparent; color:#6b7280; }
    .tab-btn.active { background:#3b82f6; color:white; }
    .dragging { opacity:.5; }
    .drag-over { border-top:3px solid #3b82f6; }
    #builderTopBar { position:sticky; top:0; z-index:40; background:white; border-bottom:1px solid #e2e8f0; padding:10px 20px; display:flex; align-items:center; justify-content:space-between; }
    .section-palette-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:10px; cursor:pointer; transition:all .15s; border:1px solid transparent; }
    .section-palette-item:hover { background:#eff6ff; border-color:#bfdbfe; }
</style>

<!-- Top Bar -->
<div id="builderTopBar" style="margin:-20px -24px 0 -24px; padding:12px 20px;">
    <div class="flex items-center gap-3" style="min-width:0;flex:1">
        <a href="<?= adminUrl('pages/landing-pages.php') ?>" class="p-2 rounded-lg hover:bg-gray-100 text-gray-500"><i class="fas fa-arrow-left"></i></a>
        <div style="min-width:0;flex:1">
            <input type="text" id="pageTitle" placeholder="Landing Page Title" value="<?= htmlspecialchars($existingPage['title'] ?? '') ?>" class="text-lg font-bold text-gray-800 border-0 focus:outline-none focus:ring-0 bg-transparent w-full" />
            <div class="flex items-center gap-1.5 mt-0.5" style="font-size:12px">
                <span class="text-gray-400 flex-shrink-0"><?= SITE_URL ?>/lp/</span>
                <input type="text" id="pageSlugTop" value="<?= htmlspecialchars($existingPage['slug'] ?? '') ?>" placeholder="my-page" class="border-0 border-b border-dashed border-gray-300 focus:border-blue-400 focus:outline-none bg-transparent text-blue-600 font-medium px-0 py-0" style="font-size:12px;min-width:80px;max-width:200px;width:auto" oninput="syncSlug(this.value,'top')" />
                <span id="slugStatusTop" class="flex-shrink-0"></span>
                <?php if (!empty($existingPage['slug'])): ?>
                <button onclick="copyLpUrl()" class="text-gray-400 hover:text-blue-500 flex-shrink-0" title="Copy URL"><i class="fas fa-copy text-[10px]"></i></button>
                <a href="<?= SITE_URL ?>/lp/<?= $existingPage['slug'] ?>" target="_blank" class="text-gray-400 hover:text-green-500 flex-shrink-0" title="Open"><i class="fas fa-external-link-alt text-[10px]"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <button onclick="switchMainTab('templates')" class="tab-btn <?= $activeTab==='templates'?'active':'' ?>">Templates</button>
        <button onclick="switchMainTab('builder')" class="tab-btn <?= $activeTab==='builder'?'active':'' ?>">Builder</button>
        <button onclick="switchMainTab('analytics')" class="tab-btn <?= $activeTab==='analytics'?'active':'' ?>" <?= !$pageId ? 'disabled style="opacity:.4"' : '' ?>>Analytics</button>
        <div class="w-px h-6 bg-gray-200 mx-2"></div>
        <button onclick="previewPage()" class="px-3 py-2 rounded-lg text-sm font-medium border border-gray-200 hover:bg-gray-50 text-gray-700"><i class="fas fa-eye mr-1"></i> Preview</button>
        <select id="pageStatus" class="px-3 py-2 rounded-lg text-sm font-medium border border-gray-200 bg-white">
            <option value="draft" <?= ($existingPage['status'] ?? 'draft')==='draft'?'selected':'' ?>>Draft</option>
            <option value="active" <?= ($existingPage['status'] ?? '')==='active'?'selected':'' ?>>Active</option>
            <option value="paused" <?= ($existingPage['status'] ?? '')==='paused'?'selected':'' ?>>Paused</option>
        </select>
        <button onclick="savePage()" class="px-5 py-2 rounded-lg text-sm font-semibold bg-blue-600 text-white hover:bg-blue-700 shadow-sm"><i class="fas fa-save mr-1"></i> Save</button>
    </div>
</div>

<!-- Templates Tab -->
<div id="tab-templates" class="<?= $activeTab!=='templates'?'hidden':'' ?>" style="padding:30px 20px; margin:-20px -24px;">
    <div class="max-w-5xl mx-auto">
        <h2 class="text-xl font-bold text-gray-800 mb-2">Choose a Template</h2>
        <p class="text-gray-500 text-sm mb-6">Start with a pre-designed template or build from scratch</p>
        <div id="templateGrid" class="grid grid-cols-3 gap-6">
            <!-- Blank template -->
            <div class="template-card" onclick="loadTemplate(0)">
                <div class="aspect-[4/3] bg-gray-50 flex items-center justify-center">
                    <div class="text-center">
                        <div class="text-4xl mb-2">📝</div>
                        <div class="font-semibold text-gray-700">Blank Page</div>
                        <div class="text-xs text-gray-400 mt-1">Start from scratch</div>
                    </div>
                </div>
            </div>
        </div>
        <div id="customTemplates" class="mt-8 hidden">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Your Saved Templates</h3>
            <div id="customTemplateGrid" class="grid grid-cols-3 gap-6"></div>
        </div>
    </div>
</div>

<!-- Builder Tab -->
<div id="tab-builder" class="<?= $activeTab!=='builder'?'hidden':'' ?>">
    <div class="builder-wrap">
        <!-- LEFT PANEL: Section Palette -->
        <div class="panel p-4">
            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Add Section</h3>
            <div class="space-y-1" id="sectionPalette">
                <div class="section-palette-item" onclick="addSection('hero')"><span class="text-lg">🎯</span><div><div class="text-sm font-semibold text-gray-700">Hero Banner</div><div class="text-[10px] text-gray-400">Main headline with CTA</div></div></div>
                <div class="section-palette-item" onclick="addSection('products')"><span class="text-lg">🛍️</span><div><div class="text-sm font-semibold text-gray-700">Product Cards</div><div class="text-[10px] text-gray-400">Products with prices</div></div></div>
                <div class="section-palette-item" onclick="addSection('features')"><span class="text-lg">⭐</span><div><div class="text-sm font-semibold text-gray-700">Features/Benefits</div><div class="text-[10px] text-gray-400">Icon + text grid</div></div></div>
                <div class="section-palette-item" onclick="addSection('testimonials')"><span class="text-lg">💬</span><div><div class="text-sm font-semibold text-gray-700">Testimonials</div><div class="text-[10px] text-gray-400">Customer reviews</div></div></div>
                <div class="section-palette-item" onclick="addSection('faq')"><span class="text-lg">❓</span><div><div class="text-sm font-semibold text-gray-700">FAQ Accordion</div><div class="text-[10px] text-gray-400">Questions & answers</div></div></div>
                <div class="section-palette-item" onclick="addSection('countdown')"><span class="text-lg">⏰</span><div><div class="text-sm font-semibold text-gray-700">Countdown Timer</div><div class="text-[10px] text-gray-400">Urgency timer</div></div></div>
                <div class="section-palette-item" onclick="addSection('video')"><span class="text-lg">🎬</span><div><div class="text-sm font-semibold text-gray-700">Video Embed</div><div class="text-[10px] text-gray-400">YouTube/custom video</div></div></div>
                <div class="section-palette-item" onclick="addSection('before_after')"><span class="text-lg">🔄</span><div><div class="text-sm font-semibold text-gray-700">Before/After</div><div class="text-[10px] text-gray-400">Comparison slider</div></div></div>
                <div class="section-palette-item" onclick="addSection('trust_badges')"><span class="text-lg">🛡️</span><div><div class="text-sm font-semibold text-gray-700">Trust Badges</div><div class="text-[10px] text-gray-400">Trust indicators</div></div></div>
                <div class="section-palette-item" onclick="addSection('custom_html')"><span class="text-lg">🧩</span><div><div class="text-sm font-semibold text-gray-700">Custom HTML</div><div class="text-[10px] text-gray-400">Raw HTML block</div></div></div>
            </div>
            
            <hr class="my-4 border-gray-200">
            
            <!-- Section Order List -->
            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Sections Order</h3>
            <div id="sectionOrderList" class="space-y-1"></div>
            
            <hr class="my-4 border-gray-200">
            
            <!-- Global Settings -->
            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Page Settings</h3>
            <div class="space-y-3">
                <div>
                    <div class="setting-label">Slug (URL)</div>
                    <div class="relative">
                        <input type="text" id="pageSlug" class="setting-input pr-16" placeholder="my-landing-page" value="<?= htmlspecialchars($existingPage['slug'] ?? '') ?>" oninput="syncSlug(this.value,'panel')">
                        <span id="slugStatus" class="absolute right-2 top-1/2 -translate-y-1/2"></span>
                    </div>
                    <?php if (!empty($existingPage['slug'])): ?>
                    <div class="flex items-center gap-2 mt-1">
                        <a href="<?= SITE_URL ?>/lp/<?= $existingPage['slug'] ?>" target="_blank" class="text-[10px] text-blue-500 hover:underline truncate flex-1"><?= SITE_URL ?>/lp/<?= $existingPage['slug'] ?></a>
                        <button onclick="copyLpUrl()" class="text-[10px] text-gray-400 hover:text-blue-500"><i class="fas fa-copy"></i> Copy</button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <div class="setting-label">Primary Color</div>
                        <input type="color" id="settPrimary" class="color-input" value="<?= $existingPage['settings']['primary_color'] ?? '#3b82f6' ?>" onchange="updateGlobalSettings()">
                    </div>
                    <div>
                        <div class="setting-label">Secondary Color</div>
                        <input type="color" id="settSecondary" class="color-input" value="<?= $existingPage['settings']['secondary_color'] ?? '#1e293b' ?>" onchange="updateGlobalSettings()">
                    </div>
                </div>
                <div>
                    <div class="setting-label">Heading Font</div>
                    <select id="settFontHeading" class="setting-input" onchange="updateGlobalSettings()">
                        <option value="Poppins">Poppins</option>
                        <option value="Montserrat">Montserrat</option>
                        <option value="Playfair Display">Playfair Display</option>
                        <option value="Cormorant Garamond">Cormorant Garamond</option>
                        <option value="Inter">Inter</option>
                        <option value="Open Sans">Open Sans</option>
                        <option value="Roboto">Roboto</option>
                        <option value="Noto Sans Bengali">Noto Sans Bengali</option>
                    </select>
                </div>
                <div>
                    <div class="setting-label">Body Font</div>
                    <select id="settFontBody" class="setting-input" onchange="updateGlobalSettings()">
                        <option value="Inter">Inter</option>
                        <option value="Open Sans">Open Sans</option>
                        <option value="Poppins">Poppins</option>
                        <option value="Roboto">Roboto</option>
                        <option value="Noto Sans Bengali">Noto Sans Bengali</option>
                    </select>
                </div>
                <div class="flex items-center justify-between">
                    <div class="setting-label mb-0">Show Site Header</div>
                    <label class="toggle-switch"><input type="checkbox" id="settShowHeader" checked onchange="updateGlobalSettings()"><span class="toggle-slider"></span></label>
                </div>
                <div class="flex items-center justify-between">
                    <div class="setting-label mb-0">Show Site Footer</div>
                    <label class="toggle-switch"><input type="checkbox" id="settShowFooter" checked onchange="updateGlobalSettings()"><span class="toggle-slider"></span></label>
                </div>
                <div class="flex items-center justify-between">
                    <div class="setting-label mb-0">Floating CTA Button</div>
                    <label class="toggle-switch"><input type="checkbox" id="settFloatingCTA" checked onchange="updateGlobalSettings()"><span class="toggle-slider"></span></label>
                </div>
                <div class="flex items-center justify-between">
                    <div class="setting-label mb-0">WhatsApp Button</div>
                    <label class="toggle-switch"><input type="checkbox" id="settWhatsApp" checked onchange="updateGlobalSettings()"><span class="toggle-slider"></span></label>
                </div>
                <div class="flex items-center justify-between">
                    <div class="setting-label mb-0">A/B Testing</div>
                    <label class="toggle-switch"><input type="checkbox" id="settABTest" <?= ($existingPage['ab_test_enabled'] ?? 0) ? 'checked' : '' ?> onchange="updateGlobalSettings()"><span class="toggle-slider"></span></label>
                </div>
                
                <hr class="border-gray-200">
                <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Product Click Action</h4>
                <select id="settProductClick" class="setting-input" onchange="updateGlobalSettings()">
                    <option value="regular_checkout">Regular Website Checkout</option>
                    <option value="landing_popup">Landing Page Popup</option>
                    <option value="scroll_to_order">Scroll to Order Section</option>
                    <option value="product_link">Open Product Link</option>
                </select>
                <p class="text-[10px] text-gray-400 mt-1" id="productClickHint"></p>
                
                <hr class="border-gray-200">
                <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Checkout / Order Form</h4>
                <div>
                    <div class="setting-label">Checkout Mode</div>
                    <select id="settCheckoutMode" class="setting-input" onchange="updateGlobalSettings()">
                        <option value="landing">Landing Page Form</option>
                        <option value="regular">Regular Website Checkout</option>
                        <option value="hidden">Hidden (No form)</option>
                    </select>
                    <p class="text-[10px] text-gray-400 mt-1" id="checkoutModeHint"></p>
                </div>
                <div>
                    <div class="setting-label">Default Product (pre-selected)</div>
                    <select id="settDefaultProduct" class="setting-input" onchange="updateGlobalSettings()">
                        <option value="">— None —</option>
                    </select>
                    <p class="text-[10px] text-gray-400 mt-1">This product will be pre-added to the cart when the page loads</p>
                </div>
                <div>
                    <div class="setting-label">Form Title</div>
                    <input type="text" id="settOrderTitle" class="setting-input" placeholder="অর্ডার করুন" onchange="updateGlobalSettings()">
                </div>
                <div>
                    <div class="setting-label">Subtitle</div>
                    <input type="text" id="settOrderSubtitle" class="setting-input" placeholder="Optional subtitle" onchange="updateGlobalSettings()">
                </div>
                <div>
                    <div class="setting-label">Button Text</div>
                    <input type="text" id="settOrderButton" class="setting-input" placeholder="ক্যাশ অন ডেলিভারিতে অর্ডার করুন" onchange="updateGlobalSettings()">
                </div>
                <div>
                    <div class="setting-label">Button Color</div>
                    <input type="color" id="settOrderBtnColor" class="color-input" onchange="updateGlobalSettings()">
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div><div class="setting-label">Dhaka (৳)</div><input type="number" id="settDelDhaka" class="setting-input text-xs" onchange="updateGlobalSettings()"></div>
                    <div><div class="setting-label">Sub (৳)</div><input type="number" id="settDelSub" class="setting-input text-xs" onchange="updateGlobalSettings()"></div>
                    <div><div class="setting-label">Outside (৳)</div><input type="number" id="settDelOut" class="setting-input text-xs" onchange="updateGlobalSettings()"></div>
                </div>
                <div>
                    <div class="setting-label">Success Title</div>
                    <input type="text" id="settSuccessTitle" class="setting-input" placeholder="অর্ডার সফল হয়েছে!" onchange="updateGlobalSettings()">
                </div>
                <div>
                    <div class="setting-label">Success Message</div>
                    <input type="text" id="settSuccessMsg" class="setting-input" placeholder="শীঘ্রই যোগাযোগ করবে" onchange="updateGlobalSettings()">
                </div>
                
                <hr class="border-gray-200">
                <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">After Order Placed</h4>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-[10px] text-gray-500">Redirect to URL</label>
                    <label class="toggle-switch" style="transform:scale(.7)"><input type="checkbox" id="settRedirectEnabled" onchange="updateGlobalSettings()"><span class="toggle-slider"></span></label>
                </div>
                <div id="redirectUrlWrap" class="hidden">
                    <input type="url" id="settRedirectUrl" class="setting-input text-xs" placeholder="https://example.com/thank-you" onchange="updateGlobalSettings()">
                    <p class="text-[10px] text-gray-400 mt-1">Customer will be redirected here after successful order</p>
                </div>
                
                <hr class="border-gray-200">
                <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider"><i class="fas fa-list-check mr-1"></i> Checkout Form Fields</h4>
                <button type="button" onclick="lpCfOpen()" class="w-full py-2.5 rounded-lg text-sm font-semibold border-2 border-blue-200 text-blue-600 hover:bg-blue-50 transition flex items-center justify-center gap-2">
                    <i class="fas fa-pen-to-square"></i> Edit Checkout Form
                </button>
                <p class="text-[10px] text-gray-400 mt-1">Drag to reorder, edit labels, toggle fields, live preview</p>
                
                <hr class="border-gray-200">
                <h4 class="text-[10px] font-bold text-gray-400 uppercase tracking-wider"><i class="fas fa-fire mr-1" style="color:#f97316"></i> Upsell Products</h4>
                <p class="text-[10px] text-gray-400 mb-2">Pick products to show as upsells. Leave empty for auto-suggestions.</p>
                <div id="lpUpList" class="space-y-1.5 mb-2"></div>
                <button type="button" onclick="lpUpPickerOpen()" class="w-full py-2 rounded-lg text-xs font-semibold border border-dashed border-orange-300 text-orange-600 hover:bg-orange-50 transition flex items-center justify-center gap-1.5">
                    <i class="fas fa-plus"></i> Add Upsell Product
                </button>
                
                <hr class="border-gray-200">
                <div>
                    <div class="setting-label">SEO Title</div>
                    <input type="text" id="seoTitle" class="setting-input" value="<?= htmlspecialchars($existingPage['seo_title'] ?? '') ?>">
                </div>
                <div>
                    <div class="setting-label">SEO Description</div>
                    <textarea id="seoDesc" class="setting-input setting-textarea"><?= htmlspecialchars($existingPage['seo_description'] ?? '') ?></textarea>
                </div>
                <button onclick="saveAsTemplate()" class="w-full py-2.5 rounded-lg text-sm font-semibold border-2 border-dashed border-blue-300 text-blue-600 hover:bg-blue-50 transition">
                    <i class="fas fa-bookmark mr-1"></i> Save as Template
                </button>
            </div>
        </div>

        <!-- CENTER: Canvas -->
        <div class="canvas" id="builderCanvas">
            <div id="sectionsContainer"></div>
            <div class="add-section-btn mt-4" onclick="document.getElementById('sectionPalette').scrollIntoView({behavior:'smooth'})">
                <i class="fas fa-plus text-gray-400 text-lg"></i>
                <div class="text-sm text-gray-500 mt-1">Add a section from the left panel</div>
            </div>
        </div>

        <!-- RIGHT PANEL: Section Settings -->
        <div class="panel panel-right p-4" id="rightPanel">
            <div id="noSectionSelected" class="text-center py-16">
                <div class="text-3xl mb-2">👈</div>
                <p class="text-sm text-gray-500">Click a section to edit its settings</p>
            </div>
            <div id="sectionSettings" class="hidden"></div>
        </div>
    </div>
</div>

<!-- Analytics Tab -->
<div id="tab-analytics" class="<?= $activeTab!=='analytics'?'hidden':'' ?>" style="padding:20px;">
    <div id="analyticsContent" class="max-w-6xl mx-auto">
        <?php if ($pageId): ?>
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-xl font-bold text-gray-800">Analytics & Behavior</h2>
            <select id="analyticsDays" onchange="loadAnalytics()" class="px-3 py-2 rounded-lg text-sm border">
                <option value="7">Last 7 days</option>
                <option value="30" selected>Last 30 days</option>
                <option value="90">Last 90 days</option>
            </select>
        </div>
        <div id="analyticsData" class="space-y-5"></div>
        <?php else: ?>
        <p class="text-center text-gray-500 py-20">Save the page first to see analytics</p>
        <?php endif; ?>
    </div>
</div>

<!-- Media Gallery Modal -->
<div id="mediaModal" class="hidden fixed inset-0 z-50 bg-black/50 flex items-center justify-center" onclick="if(event.target===this)closeMediaPicker()">
    <div class="bg-white rounded-2xl shadow-2xl w-[800px] max-h-[80vh] overflow-hidden flex flex-col">
        <div class="flex items-center justify-between px-5 py-3 border-b">
            <h3 class="font-bold text-gray-800">Select Image</h3>
            <div class="flex items-center gap-3">
                <select id="mediaFolder" onchange="loadMediaImages()" class="text-sm border rounded-lg px-3 py-1.5">
                    <option value="all">All</option>
                    <option value="products">Products</option>
                    <option value="banners">Banners</option>
                    <option value="logos">Logos</option>
                    <option value="general">General</option>
                </select>
                <input type="file" id="mediaUpload" accept="image/*" class="hidden" onchange="uploadMediaFile(this)">
                <button onclick="document.getElementById('mediaUpload').click()" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-sm font-medium">Upload</button>
                <button onclick="closeMediaPicker()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
        </div>
        <div id="mediaGrid" class="grid grid-cols-5 gap-3 p-5 overflow-y-auto flex-1"></div>
    </div>
</div>

<!-- ═══ CHECKOUT FORM EDITOR MODAL ═══ -->
<div id="lpCfModal">
    <div class="cf-panel">
        <div class="cf-header">
            <div>
                <h3 style="font-size:16px;font-weight:700;color:#1e293b;margin:0">Checkout Form Editor</h3>
                <p style="font-size:11px;color:#94a3b8;margin:2px 0 0">Drag to reorder, edit labels, toggle fields on/off</p>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <button onclick="lpCfResetModal()" style="padding:6px 14px;border:1px solid #e5e7eb;border-radius:8px;font-size:12px;font-weight:500;background:#fff;color:#6b7280;cursor:pointer"><i class="fas fa-rotate-left mr-1"></i>Reset Default</button>
                <button onclick="lpCfSaveClose()" style="padding:6px 18px;border:none;border-radius:8px;font-size:12px;font-weight:600;background:#3b82f6;color:#fff;cursor:pointer"><i class="fas fa-check mr-1"></i>Save & Close</button>
                <button onclick="lpCfClose()" style="width:32px;height:32px;border:none;background:#f3f4f6;border-radius:8px;cursor:pointer;color:#6b7280;font-size:16px;display:flex;align-items:center;justify-content:center">✕</button>
            </div>
        </div>
        <div class="cf-body">
            <!-- LEFT: Field List -->
            <div class="cf-col">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding:8px 12px;background:#f8fafc;border-radius:8px;border:1px solid #e5e7eb">
                    <span style="font-size:12px;font-weight:600;color:#475569"><i class="fas fa-list-ul mr-1 text-blue-500"></i>Field Order & Configuration</span>
                    <span style="font-size:10px;color:#94a3b8"><i class="fas fa-grip-vertical mr-1"></i>Drag to reorder</span>
                </div>
                <div id="lpCfFieldList"></div>
            </div>
            <!-- RIGHT: Live Preview -->
            <div class="cf-col cf-col-right">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:16px;padding:8px 12px;background:#fff;border-radius:8px;border:1px solid #e5e7eb">
                    <i class="fas fa-eye text-green-500" style="font-size:12px"></i>
                    <span style="font-size:12px;font-weight:600;color:#475569">Live Preview</span>
                </div>
                <div id="lpCfPreview" style="background:#fff;border-radius:12px;border:1px solid #e5e7eb;padding:20px;space-y:16px"></div>
                <!-- Tips -->
                <div style="margin-top:16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px">
                    <h4 style="font-size:12px;font-weight:600;color:#1e40af;margin:0 0 6px"><i class="fas fa-info-circle mr-1"></i>Tips</h4>
                    <ul style="font-size:11px;color:#3b82f6;list-style:none;padding:0;margin:0;line-height:1.8">
                        <li>• <strong>Drag</strong> fields to change their order</li>
                        <li>• <strong>Click labels</strong> to rename in Bangla or English</li>
                        <li>• <strong>Name, Phone, Address</strong> are recommended as required</li>
                        <li>• Disabled fields won't appear on LP checkout</li>
                        <li>• System fields (cart, coupons, upsells) are site-only</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<?php
// Load site-wide checkout fields for default
$_bCfJson = getSetting('checkout_fields', '');
$_bCf = $_bCfJson ? json_decode($_bCfJson, true) : null;
if (!$_bCf) {
    $_bCf = [
        ['key'=>'product_selector','label'=>'পণ্য নির্বাচন','type'=>'system','enabled'=>true,'required'=>false,'placeholder'=>''],
        ['key'=>'name','label'=>'আপনার নাম','type'=>'text','enabled'=>true,'required'=>true,'placeholder'=>'সম্পূর্ণ নাম লিখুন'],
        ['key'=>'phone','label'=>'মোবাইল নম্বর','type'=>'tel','enabled'=>true,'required'=>true,'placeholder'=>'01XXXXXXXXX'],
        ['key'=>'email','label'=>'ইমেইল','type'=>'email','enabled'=>false,'required'=>false,'placeholder'=>'your@email.com'],
        ['key'=>'address','label'=>'সম্পূর্ণ ঠিকানা','type'=>'textarea','enabled'=>true,'required'=>true,'placeholder'=>'বাসা/রোড নং, এলাকা, থানা, জেলা'],
        ['key'=>'shipping_area','label'=>'ডেলিভারি এরিয়া','type'=>'radio','enabled'=>true,'required'=>true,'placeholder'=>''],
        ['key'=>'lp_upsells','label'=>'এটাও নিতে পারেন','type'=>'system','enabled'=>true,'required'=>false,'placeholder'=>''],
        ['key'=>'notes','label'=>'অতিরিক্ত নোট','type'=>'text','enabled'=>false,'required'=>false,'placeholder'=>'বিশেষ কোনো নির্দেশনা থাকলে লিখুন'],
    ];
}
// Ensure LP-specific fields exist
$_bKeys = array_column($_bCf, 'key');
if (!in_array('product_selector', $_bKeys)) {
    array_unshift($_bCf, ['key'=>'product_selector','label'=>'পণ্য নির্বাচন','type'=>'system','enabled'=>true,'required'=>false,'placeholder'=>'']);
}
if (!in_array('lp_upsells', $_bKeys)) {
    $_bCf[] = ['key'=>'lp_upsells','label'=>'এটাও নিতে পারেন','type'=>'system','enabled'=>true,'required'=>false,'placeholder'=>''];
}
// Filter to LP-usable fields
$_bSkip = ['cart_summary','progress_bar','coupon','store_credit','upsells','order_total'];
$_bFields = [];
foreach ($_bCf as $f) {
    if (in_array($f['key'] ?? '', $_bSkip)) continue;
    $_bFields[] = $f;
}
?>
<script>
const SITE_CF_DEFAULTS = <?= json_encode($_bFields, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>
const API = '<?= SITE_URL ?>/api/landing-pages.php';
const MEDIA_API = '<?= adminUrl("pages/media.php") ?>';
const SITE_URL = '<?= SITE_URL ?>';
const PAGE_ID = <?= $pageId ?>;
let sections = <?= json_encode($existingPage['sections'] ?? []) ?>;
let pageSettings = <?= json_encode($existingPage['settings'] ?? ['primary_color'=>'#3b82f6','secondary_color'=>'#1e293b','font_heading'=>'Poppins','font_body'=>'Inter','floating_cta'=>['enabled'=>true],'whatsapp'=>['enabled'=>true,'number'=>'8801828373189'],'order_form'=>['enabled'=>true,'title'=>'অর্ডার করুন','delivery_charges'=>['inside_dhaka'=>70,'dhaka_sub'=>100,'outside_dhaka'=>130]]]) ?>;
let selectedSectionId = null;
let _mediaCallback = null;

// ═══ TAB SWITCHING ═══
function switchMainTab(tab) {
    ['templates','builder','analytics'].forEach(t => {
        document.getElementById('tab-'+t).classList.toggle('hidden', t !== tab);
        document.querySelectorAll('.tab-btn').forEach(b => {
            if (b.textContent.trim().toLowerCase().includes(t.slice(0,4))) b.classList.toggle('active', t === tab);
        });
    });
    if (tab === 'analytics' && PAGE_ID) loadAnalytics();
    if (tab === 'templates') loadTemplates();
}

// ═══ TEMPLATES ═══
function loadTemplates() {
    fetch(API+'?action=templates').then(r=>r.json()).then(res => {
        const grid = document.getElementById('templateGrid');
        const systemTpls = (res.data||[]).filter(t => t.is_system == 1);
        const customTpls = (res.data||[]).filter(t => t.is_system != 1);
        
        // Keep blank card, add system templates
        const blankCard = grid.children[0];
        grid.innerHTML = '';
        grid.appendChild(blankCard);
        
        systemTpls.forEach(t => {
            const catIcons = {fashion:'👜',food:'🌿',default:'📄'};
            const icon = catIcons[t.category] || catIcons.default;
            const colors = t.settings?.primary_color ? `background:linear-gradient(135deg, ${t.settings.primary_color}, ${t.settings.secondary_color || '#333'})` : 'background:#f1f5f9';
            grid.innerHTML += `<div class="template-card" onclick="loadTemplate(${t.id})">
                <div class="aspect-[4/3] flex items-center justify-center" style="${colors}">
                    <div class="text-center text-white"><div class="text-5xl mb-2">${icon}</div><div class="font-bold text-lg drop-shadow">${esc(t.name)}</div></div>
                </div>
                <div class="p-3"><p class="text-xs text-gray-500">${esc(t.description||'')}</p><p class="text-[10px] text-gray-400 mt-1">Used ${t.use_count||0} times</p></div>
            </div>`;
        });
        
        // Custom templates
        if (customTpls.length) {
            document.getElementById('customTemplates').classList.remove('hidden');
            const cGrid = document.getElementById('customTemplateGrid');
            cGrid.innerHTML = customTpls.map(t => `<div class="template-card" onclick="loadTemplate(${t.id})">
                <div class="aspect-[4/3] bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                    <div class="text-center"><div class="text-4xl mb-2">📄</div><div class="font-semibold text-gray-700">${esc(t.name)}</div></div>
                </div>
                <div class="p-3 flex items-center justify-between">
                    <span class="text-xs text-gray-500">${esc(t.category)}</span>
                    <button onclick="event.stopPropagation();deleteTemplate(${t.id})" class="text-xs text-red-400 hover:text-red-600"><i class="fas fa-trash"></i></button>
                </div>
            </div>`).join('');
        }
    });
}

function loadTemplate(id) {
    if (id === 0) {
        sections = [];
        pageSettings = {primary_color:'#3b82f6',secondary_color:'#1e293b',font_heading:'Poppins',font_body:'Inter',show_header:true,show_footer:true,floating_cta:{enabled:true},whatsapp:{enabled:true,number:'8801828373189'},order_form:{enabled:true,title:'অর্ডার করুন',button_text:'ক্যাশ অন ডেলিভারিতে অর্ডার করুন',delivery_charges:{inside_dhaka:70,dhaka_sub:100,outside_dhaka:130}},product_click_action:'regular_checkout',checkout_mode:'regular'};
        renderSections();
        applyGlobalSettings();
        switchMainTab('builder');
        return;
    }
    fetch(API+'?action=load_template&id='+id).then(r=>r.json()).then(res => {
        if (!res.success) return alert(res.error);
        sections = res.data.sections || [];
        pageSettings = res.data.settings || {};
        if (!document.getElementById('pageTitle').value) document.getElementById('pageTitle').value = res.data.name || '';
        renderSections();
        applyGlobalSettings();
        switchMainTab('builder');
    });
}

function deleteTemplate(id) {
    if (!confirm('Delete this template?')) return;
    const fd = new FormData(); fd.append('action','delete_template'); fd.append('id',id);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(() => loadTemplates());
}

// ═══ SECTIONS MANAGEMENT ═══
const SECTION_DEFAULTS = {
    hero: {content:{headline:'আপনার প্রোডাক্ট হেডলাইন',subheadline:'প্রোডাক্টের বিবরণ লিখুন',badge:'',cta_text:'এখনই অর্ডার করুন',cta_link:'#order',image:''},settings:{bg_color:'#1a1a2e',text_color:'#ffffff',accent_color:'#3b82f6',layout:'split',padding:'80px'}},
    products: {content:{headline:'আমাদের পণ্যসমূহ',subheadline:'',products:[{name:'প্রোডাক্ট ১',price:999,compare_price:1499,image:'',badge:'',description:'পণ্যের বিবরণ'}]},settings:{bg_color:'#ffffff',text_color:'#1a1a2e',accent_color:'#3b82f6',columns:3,show_badge:true}},
    features: {content:{headline:'কেন আমাদের বেছে নেবেন?',features:[{icon:'⭐',title:'ফিচার ১',desc:'বিবরণ'},{icon:'🚚',title:'ফিচার ২',desc:'বিবরণ'},{icon:'💎',title:'ফিচার ৩',desc:'বিবরণ'},{icon:'🛡️',title:'ফিচার ৪',desc:'বিবরণ'}]},settings:{bg_color:'#f8fafc',text_color:'#1a1a2e',accent_color:'#3b82f6',columns:4,layout:'grid'}},
    testimonials: {content:{headline:'কাস্টমার রিভিউ',items:[{name:'কাস্টমার',location:'ঢাকা',rating:5,text:'অসাধারণ পণ্য!',avatar:''}]},settings:{bg_color:'#f8fafc',text_color:'#1a1a2e',columns:3}},
    faq: {content:{headline:'জিজ্ঞাসা',items:[{q:'প্রশ্ন ১',a:'উত্তর ১'}]},settings:{bg_color:'#ffffff',text_color:'#1a1a2e',accent_color:'#3b82f6'}},
    countdown: {content:{headline:'⏰ অফার শেষ হচ্ছে!',subheadline:'সীমিত সময়ের জন্য',end_date:new Date(Date.now()+3*86400000).toISOString().slice(0,16),cta_text:'এখনই অর্ডার করুন',cta_link:'#order'},settings:{bg_color:'#dc2626',text_color:'#ffffff',style:'urgent'}},
    video: {content:{headline:'ভিডিও দেখুন',youtube_id:'',poster_image:''},settings:{bg_color:'#ffffff',text_color:'#1a1a2e'}},
    before_after: {content:{headline:'আগে ও পরে',before_label:'Before',after_label:'After',before_image:'',after_image:''},settings:{bg_color:'#f8fafc',text_color:'#1a1a2e'}},
    trust_badges: {content:{badges:[{icon:'🛡️',text:'ট্রাস্ট ১'},{icon:'🚚',text:'ট্রাস্ট ২'},{icon:'↩️',text:'ট্রাস্ট ৩'},{icon:'💳',text:'ট্রাস্ট ৪'}]},settings:{bg_color:'#f8fafc',text_color:'#1a1a2e',columns:4}},
    custom_html: {content:{html:'<div style="padding:40px;text-align:center"><h2>Custom Section</h2><p>Your HTML here</p></div>'},settings:{bg_color:'#ffffff'}},
};

function uid() { return 'sec_' + Math.random().toString(36).substr(2,9); }

function addSection(type) {
    const def = JSON.parse(JSON.stringify(SECTION_DEFAULTS[type] || {}));
    const sec = { id:uid(), type, enabled:true, order:sections.length, content:def.content||{}, settings:def.settings||{} };
    sections.push(sec);
    renderSections();
    selectSection(sec.id);
}

function removeSection(id) {
    if (!confirm('Remove this section?')) return;
    sections = sections.filter(s => s.id !== id);
    if (selectedSectionId === id) { selectedSectionId = null; showNoSelection(); }
    renderSections();
}

function toggleSection(id) {
    const sec = sections.find(s => s.id === id);
    if (sec) sec.enabled = !sec.enabled;
    renderSections();
}

function moveSection(id, dir) {
    const idx = sections.findIndex(s => s.id === id);
    if (idx < 0) return;
    const newIdx = idx + dir;
    if (newIdx < 0 || newIdx >= sections.length) return;
    [sections[idx], sections[newIdx]] = [sections[newIdx], sections[idx]];
    renderSections();
}

function selectSection(id) {
    selectedSectionId = id;
    document.querySelectorAll('.section-card').forEach(c => c.classList.toggle('selected', c.dataset.id === id));
    renderSectionSettings(id);
}

// ═══ RENDER SECTIONS IN CANVAS ═══
function renderSections() {
    const container = document.getElementById('sectionsContainer');
    const orderList = document.getElementById('sectionOrderList');
    const typeNames = {hero:'Hero Banner',products:'Product Cards',features:'Features',testimonials:'Testimonials',faq:'FAQ',countdown:'Countdown',video:'Video',before_after:'Before/After',trust_badges:'Trust Badges',custom_html:'Custom HTML'};
    const typeIcons = {hero:'🎯',products:'🛍️',features:'⭐',testimonials:'💬',faq:'❓',countdown:'⏰',video:'🎬',before_after:'🔄',trust_badges:'🛡️',custom_html:'🧩'};
    
    container.innerHTML = sections.map(s => {
        const preview = getSectionPreview(s);
        return `<div class="section-card ${s.enabled?'':'disabled'} ${selectedSectionId===s.id?'selected':''}" data-id="${s.id}" onclick="selectSection('${s.id}')">
            <div class="absolute top-2 right-2 flex items-center gap-1 z-10">
                <button onclick="event.stopPropagation();moveSection('${s.id}',-1)" class="p-1.5 rounded-md hover:bg-gray-100 text-gray-400 text-xs"><i class="fas fa-chevron-up"></i></button>
                <button onclick="event.stopPropagation();moveSection('${s.id}',1)" class="p-1.5 rounded-md hover:bg-gray-100 text-gray-400 text-xs"><i class="fas fa-chevron-down"></i></button>
                <label class="toggle-switch" style="transform:scale(.8)" onclick="event.stopPropagation()"><input type="checkbox" ${s.enabled?'checked':''} onchange="toggleSection('${s.id}')"><span class="toggle-slider"></span></label>
                <button onclick="event.stopPropagation();removeSection('${s.id}')" class="p-1.5 rounded-md hover:bg-red-50 text-red-400 text-xs"><i class="fas fa-trash"></i></button>
            </div>
            <div class="absolute top-2 left-3 text-xs font-semibold text-gray-400 flex items-center gap-1.5 z-10">
                <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                ${typeIcons[s.type]||'📄'} ${typeNames[s.type]||s.type}
            </div>
            <div class="pt-8 overflow-hidden rounded-b-xl" style="min-height:80px;${s.settings?.bg_color?'background:'+s.settings.bg_color:''}">${preview}</div>
        </div>`;
    }).join('');
    
    // Order list in sidebar
    orderList.innerHTML = sections.map(s => `<div class="flex items-center gap-2 px-2 py-1.5 rounded-lg text-xs cursor-pointer hover:bg-blue-50 ${selectedSectionId===s.id?'bg-blue-50 text-blue-700':'text-gray-600'}" onclick="selectSection('${s.id}')">
        <span>${typeIcons[s.type]||'📄'}</span>
        <span class="flex-1 truncate">${typeNames[s.type]||s.type}</span>
        <span class="${s.enabled?'text-green-500':'text-gray-300'}">●</span>
    </div>`).join('');
    refreshDefaultProductDropdown();
}

function getSectionPreview(s) {
    const c = s.content || {};
    const st = s.settings || {};
    const tc = st.text_color || '#1a1a2e';
    const ac = st.accent_color || pageSettings.primary_color || '#3b82f6';
    
    switch (s.type) {
        case 'hero':
            return `<div style="padding:30px 24px;color:${tc};text-align:${st.layout==='center'?'center':'left'}">
                ${c.badge?`<span style="background:${ac};color:white;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700">${esc(c.badge)}</span>`:''} 
                <h2 style="font-size:22px;font-weight:800;margin:10px 0 6px">${esc(c.headline||'')}</h2>
                <p style="font-size:13px;opacity:.8">${esc(c.subheadline||'')}</p>
                <button style="margin-top:14px;background:${ac};color:white;padding:10px 24px;border-radius:8px;font-weight:700;font-size:13px;border:none">${esc(c.cta_text||'Order Now')}</button>
            </div>`;
        case 'products':
            const prods = (c.products||[]).slice(0,3);
            return `<div style="padding:20px 24px;color:${tc}">
                <h3 style="font-size:16px;font-weight:700;text-align:center;margin-bottom:12px">${esc(c.headline||'')}</h3>
                <div style="display:grid;grid-template-columns:repeat(${Math.min(prods.length,3)},1fr);gap:10px">
                    ${prods.map(p => `<div style="background:rgba(0,0,0,.03);border-radius:8px;padding:12px;text-align:center">
                        <div style="width:60px;height:60px;background:#e5e7eb;border-radius:8px;margin:0 auto 8px;overflow:hidden">${p.image?`<img src="${p.image}" style="width:100%;height:100%;object-fit:cover">`:''}</div>
                        <div style="font-size:12px;font-weight:600">${esc(p.name||'')}</div>
                        <div style="font-size:14px;font-weight:800;color:${ac};margin-top:4px">৳${p.price||0}</div>
                    </div>`).join('')}
                </div>
            </div>`;
        case 'features':
            return `<div style="padding:20px 24px;color:${tc}">
                <h3 style="font-size:16px;font-weight:700;text-align:center;margin-bottom:12px">${esc(c.headline||'')}</h3>
                <div style="display:grid;grid-template-columns:repeat(${st.columns||4},1fr);gap:8px">
                    ${(c.features||[]).map(f => `<div style="text-align:center;padding:8px"><div style="font-size:20px">${f.icon||'⭐'}</div><div style="font-size:11px;font-weight:700;margin-top:4px">${esc(f.title||'')}</div></div>`).join('')}
                </div>
            </div>`;
        case 'testimonials':
            return `<div style="padding:20px 24px;color:${tc}"><h3 style="font-size:16px;font-weight:700;text-align:center;margin-bottom:8px">${esc(c.headline||'')}</h3>
                <div style="display:flex;gap:8px;justify-content:center">${(c.items||[]).slice(0,3).map(t => `<div style="background:rgba(0,0,0,.05);border-radius:8px;padding:10px;flex:1;font-size:10px"><div style="font-weight:700">${esc(t.name||'')}</div><div style="margin-top:4px;opacity:.7">"${esc((t.text||'').slice(0,50))}"</div></div>`).join('')}</div></div>`;
        case 'countdown':
            return `<div style="padding:20px 24px;text-align:center;color:${tc}"><h3 style="font-size:16px;font-weight:700">${esc(c.headline||'')}</h3><div style="display:flex;gap:10px;justify-content:center;margin-top:10px"><span style="background:rgba(0,0,0,.2);padding:8px 14px;border-radius:8px;font-size:18px;font-weight:800">00</span><span style="background:rgba(0,0,0,.2);padding:8px 14px;border-radius:8px;font-size:18px;font-weight:800">00</span><span style="background:rgba(0,0,0,.2);padding:8px 14px;border-radius:8px;font-size:18px;font-weight:800">00</span></div></div>`;
        case 'faq':
            return `<div style="padding:20px 24px;color:${tc}"><h3 style="font-size:16px;font-weight:700;text-align:center;margin-bottom:8px">${esc(c.headline||'')}</h3>${(c.items||[]).slice(0,2).map(q => `<div style="background:rgba(0,0,0,.03);border-radius:8px;padding:10px;margin-bottom:6px;font-size:12px"><strong>${esc(q.q||'')}</strong></div>`).join('')}</div>`;
        case 'trust_badges':
            return `<div style="padding:16px 24px;display:flex;justify-content:space-around;color:${tc}">${(c.badges||[]).map(b => `<div style="text-align:center"><div style="font-size:20px">${b.icon||'🛡️'}</div><div style="font-size:10px;font-weight:600;margin-top:2px">${esc(b.text||'')}</div></div>`).join('')}</div>`;
        case 'video':
            return `<div style="padding:20px 24px;color:${tc};text-align:center"><h3 style="font-size:16px;font-weight:700;margin-bottom:8px">${esc(c.headline||'')}</h3><div style="background:#000;border-radius:10px;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center"><span style="font-size:40px">▶️</span></div></div>`;
        case 'before_after':
            return `<div style="padding:20px 24px;color:${tc};text-align:center"><h3 style="font-size:16px;font-weight:700;margin-bottom:8px">${esc(c.headline||'')}</h3><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><div style="background:#e5e7eb;border-radius:8px;padding:20px;font-size:12px">${esc(c.before_label||'Before')}</div><div style="background:#dcfce7;border-radius:8px;padding:20px;font-size:12px">${esc(c.after_label||'After')}</div></div></div>`;
        case 'custom_html':
            return `<div style="padding:16px 24px;font-size:11px;color:#6b7280;text-align:center"><i class="fas fa-code"></i> Custom HTML Block</div>`;
        default:
            return `<div style="padding:20px;text-align:center;color:#9ca3af">Unknown section</div>`;
    }
}

// ═══ SECTION SETTINGS PANEL ═══
function renderSectionSettings(id) {
    const sec = sections.find(s => s.id === id);
    if (!sec) return showNoSelection();
    
    document.getElementById('noSectionSelected').classList.add('hidden');
    const panel = document.getElementById('sectionSettings');
    panel.classList.remove('hidden');
    
    const c = sec.content || {};
    const st = sec.settings || {};
    const typeNames = {hero:'Hero Banner',products:'Product Cards',features:'Features/Benefits',testimonials:'Testimonials',faq:'FAQ',countdown:'Countdown',video:'Video',before_after:'Before/After',trust_badges:'Trust Badges',custom_html:'Custom HTML'};
    
    let html = `<h3 class="text-sm font-bold text-gray-800 mb-3">${typeNames[sec.type]||sec.type} Settings</h3>`;
    
    // Common color settings
    html += `<div class="setting-group">
        <div class="setting-label">Colors</div>
        <div class="grid grid-cols-3 gap-2">
            <div><label class="text-[10px] text-gray-400">Background</label><input type="color" class="color-input w-full" value="${st.bg_color||'#ffffff'}" onchange="updateSetting('${id}','settings.bg_color',this.value)"></div>
            <div><label class="text-[10px] text-gray-400">Text</label><input type="color" class="color-input w-full" value="${st.text_color||'#1a1a2e'}" onchange="updateSetting('${id}','settings.text_color',this.value)"></div>
            <div><label class="text-[10px] text-gray-400">Accent</label><input type="color" class="color-input w-full" value="${st.accent_color||'#3b82f6'}" onchange="updateSetting('${id}','settings.accent_color',this.value)"></div>
        </div>
    </div>`;
    
    // Type-specific settings
    switch (sec.type) {
        case 'hero':
            html += settGroup('Content', `
                ${settInput('Badge','content.badge',c.badge)}
                ${settInput('Headline','content.headline',c.headline)}
                ${settTextarea('Subheadline','content.subheadline',c.subheadline)}
                ${settInput('CTA Text','content.cta_text',c.cta_text)}
                ${settInput('CTA Link','content.cta_link',c.cta_link)}
                ${settImage('Hero Image','content.image',c.image)}
                ${settSelect('Layout','settings.layout',st.layout,[['split','Split (Image + Text)'],['center','Centered'],['fullwidth','Full Width Image']])}
                ${settInput('Padding','settings.padding',st.padding||'80px')}
            `);
            break;
        case 'products':
            html += settGroup('Content', `
                ${settInput('Headline','content.headline',c.headline)}
                ${settInput('Subheadline','content.subheadline',c.subheadline)}
                ${settSelect('Columns (Desktop)','settings.columns',st.columns,[['2','2'],['3','3'],['4','4']])}
                ${settSelect('Columns (Mobile)','settings.mobile_columns',st.mobile_columns||'2',[['1','1 (List)'],['2','2 (Grid)']])}
            `);
            html += settGroup('📱 Mobile Carousel', `
                ${settToggle('Enable on Mobile','settings.mobile_carousel',st.mobile_carousel)}
                ${st.mobile_carousel ? settSelect('Auto-slide Speed','settings.carousel_speed',st.carousel_speed||'3000',[['2000','Fast (2s)'],['3000','Medium (3s)'],['5000','Slow (5s)'],['8000','Very Slow (8s)']]) : ''}
            `);
            html += `<div class="setting-group"><div class="setting-label">Products</div><div id="productsList"></div>
                <div class="flex gap-2 mt-2">
                    <button onclick="addProduct('${id}')" class="flex-1 py-2 rounded-lg text-xs font-semibold border border-dashed border-blue-300 text-blue-600 hover:bg-blue-50">+ Manual</button>
                    <button onclick="addProductFromSite('${id}')" class="flex-1 py-2 rounded-lg text-xs font-semibold border border-dashed border-green-300 text-green-600 hover:bg-green-50"><i class="fas fa-link mr-1"></i> From Site</button>
                </div></div>`;
            break;
        case 'features':
            html += settGroup('Content', `
                ${settInput('Headline','content.headline',c.headline)}
                ${settSelect('Columns (Desktop)','settings.columns',st.columns,[['2','2'],['3','3'],['4','4']])}
                ${settSelect('Columns (Mobile)','settings.mobile_columns',st.mobile_columns||'2',[['1','1 (Stacked)'],['2','2 (Grid)']])}
                ${settSelect('Layout','settings.layout',st.layout,[['grid','Grid'],['list','List']])}
            `);
            html += settGroup('📱 Mobile Carousel', `
                ${settToggle('Enable on Mobile','settings.mobile_carousel',st.mobile_carousel)}
                ${st.mobile_carousel ? settSelect('Auto-slide Speed','settings.carousel_speed',st.carousel_speed||'3000',[['2000','Fast (2s)'],['3000','Medium (3s)'],['5000','Slow (5s)'],['8000','Very Slow (8s)']]) : ''}
            `);
            html += `<div class="setting-group"><div class="setting-label">Features</div><div id="featuresList"></div>
                <button onclick="addFeature('${id}')" class="w-full mt-2 py-2 rounded-lg text-xs font-semibold border border-dashed border-blue-300 text-blue-600 hover:bg-blue-50">+ Add Feature</button></div>`;
            break;
        case 'testimonials':
            html += settGroup('Content', `
                ${settInput('Headline','content.headline',c.headline)}
                ${settSelect('Columns (Desktop)','settings.columns',st.columns,[['1','1'],['2','2'],['3','3']])}
                ${settSelect('Columns (Mobile)','settings.mobile_columns',st.mobile_columns||'1',[['1','1 (Full Width)'],['2','2 (Grid)']])}
            `);
            html += settGroup('📱 Mobile Carousel', `
                ${settToggle('Enable on Mobile','settings.mobile_carousel',st.mobile_carousel)}
                ${st.mobile_carousel ? settSelect('Auto-slide Speed','settings.carousel_speed',st.carousel_speed||'3000',[['2000','Fast (2s)'],['3000','Medium (3s)'],['5000','Slow (5s)'],['8000','Very Slow (8s)']]) : ''}
            `);
            html += `<div class="setting-group"><div class="setting-label">Reviews</div><div id="testimonialsList"></div>
                <button onclick="addTestimonial('${id}')" class="w-full mt-2 py-2 rounded-lg text-xs font-semibold border border-dashed border-blue-300 text-blue-600 hover:bg-blue-50">+ Add Review</button></div>`;
            break;
        case 'faq':
            html += settGroup('Content', `${settInput('Headline','content.headline',c.headline)}`);
            html += `<div class="setting-group"><div class="setting-label">Questions</div><div id="faqList"></div>
                <button onclick="addFaqItem('${id}')" class="w-full mt-2 py-2 rounded-lg text-xs font-semibold border border-dashed border-blue-300 text-blue-600 hover:bg-blue-50">+ Add Question</button></div>`;
            break;
        case 'countdown':
            html += settGroup('Content', `
                ${settInput('Headline','content.headline',c.headline)}
                ${settInput('Subheadline','content.subheadline',c.subheadline)}
                <div><div class="setting-label">End Date/Time</div><input type="datetime-local" class="setting-input" value="${c.end_date||''}" onchange="updateSetting('${id}','content.end_date',this.value)"></div>
                ${settInput('CTA Text','content.cta_text',c.cta_text)}
            `);
            break;
        case 'video':
            html += settGroup('Content', `
                ${settInput('Headline','content.headline',c.headline)}
                ${settInput('YouTube Video ID','content.youtube_id',c.youtube_id)}
                ${settImage('Poster Image','content.poster_image',c.poster_image)}
            `);
            break;
        case 'before_after':
            html += settGroup('Content', `
                ${settInput('Headline','content.headline',c.headline)}
                ${settInput('Before Label','content.before_label',c.before_label)}
                ${settInput('After Label','content.after_label',c.after_label)}
                ${settImage('Before Image','content.before_image',c.before_image)}
                ${settImage('After Image','content.after_image',c.after_image)}
            `);
            break;
        case 'trust_badges':
            html += settGroup('Settings', `
                ${settSelect('Columns (Desktop)','settings.columns',st.columns,[['2','2'],['3','3'],['4','4'],['5','5']])}
                ${settSelect('Columns (Mobile)','settings.mobile_columns',st.mobile_columns||'2',[['2','2'],['3','3'],['4','4']])}
            `);
            html += settGroup('📱 Mobile Carousel', `
                ${settToggle('Enable on Mobile','settings.mobile_carousel',st.mobile_carousel)}
                ${st.mobile_carousel ? settSelect('Auto-slide Speed','settings.carousel_speed',st.carousel_speed||'3000',[['2000','Fast (2s)'],['3000','Medium (3s)'],['5000','Slow (5s)'],['8000','Very Slow (8s)']]) : ''}
            `);
            html += `<div class="setting-group"><div class="setting-label">Badges</div><div id="badgesList"></div>
                <button onclick="addBadge('${id}')" class="w-full mt-2 py-2 rounded-lg text-xs font-semibold border border-dashed border-blue-300 text-blue-600 hover:bg-blue-50">+ Add Badge</button></div>`;
            break;
        case 'custom_html':
            html += settGroup('HTML Code', `<textarea class="setting-input setting-textarea" style="min-height:200px;font-family:monospace;font-size:12px" onchange="updateSetting('${id}','content.html',this.value)">${esc(c.html||'')}</textarea>`);
            break;
    }
    
    panel.innerHTML = html;
    
    // Render dynamic lists
    if (sec.type === 'products') renderProductsList(id);
    if (sec.type === 'features') renderFeaturesList(id);
    if (sec.type === 'testimonials') renderTestimonialsList(id);
    if (sec.type === 'faq') renderFaqList(id);
    if (sec.type === 'trust_badges') renderBadgesList(id);
}

// ═══ SETTING HELPERS ═══
function settGroup(title, inner) { return `<div class="setting-group"><div class="setting-label">${title}</div>${inner}</div>`; }
function settToggle(label, path, value) {
    const secId = selectedSectionId;
    const checked = value ? 'checked' : '';
    return `<div class="mb-2 flex items-center justify-between"><label class="text-[10px] text-gray-500">${label}</label><label class="toggle-switch" style="transform:scale(.7)"><input type="checkbox" ${checked} onchange="updateSetting('${secId}','${path}',this.checked)"><span class="toggle-slider"></span></label></div>`;
}
function settInput(label, path, value) {
    const secId = selectedSectionId;
    return `<div class="mb-2"><label class="text-[10px] text-gray-400">${label}</label><input type="text" class="setting-input" value="${esc(value||'')}" onchange="updateSetting('${secId}','${path}',this.value)"></div>`;
}
function settTextarea(label, path, value) {
    const secId = selectedSectionId;
    return `<div class="mb-2"><label class="text-[10px] text-gray-400">${label}</label><textarea class="setting-input setting-textarea" onchange="updateSetting('${secId}','${path}',this.value)">${esc(value||'')}</textarea></div>`;
}
function settSelect(label, path, value, options) {
    const secId = selectedSectionId;
    return `<div class="mb-2"><label class="text-[10px] text-gray-400">${label}</label><select class="setting-input" onchange="updateSetting('${secId}','${path}',this.value)">${options.map(o => `<option value="${o[0]}" ${value==o[0]?'selected':''}>${o[1]}</option>`).join('')}</select></div>`;
}
function settImage(label, path, value) {
    const secId = selectedSectionId;
    return `<div class="mb-2"><label class="text-[10px] text-gray-400">${label}</label>
        <div class="img-picker" onclick="openMediaForSetting('${secId}','${path}')">
            ${value ? `<img src="${value}">` : '<span class="text-gray-400 text-sm"><i class="fas fa-image mr-1"></i> Click to select</span>'}
        </div>
        ${value ? `<button onclick="updateSetting('${secId}','${path}','');renderSectionSettings('${secId}')" class="text-[10px] text-red-400 hover:text-red-600 mt-1">Remove image</button>` : ''}
    </div>`;
}

function updateSetting(secId, path, value) {
    const sec = sections.find(s => s.id === secId);
    if (!sec) return;
    const parts = path.split('.');
    let obj = sec;
    for (let i = 0; i < parts.length - 1; i++) {
        if (!obj[parts[i]]) obj[parts[i]] = {};
        obj = obj[parts[i]];
    }
    obj[parts[parts.length-1]] = value;
    renderSections();
}

function showNoSelection() {
    document.getElementById('noSectionSelected').classList.remove('hidden');
    document.getElementById('sectionSettings').classList.add('hidden');
}

// ═══ DYNAMIC LIST RENDERERS ═══
function renderProductsList(secId) {
    const sec = sections.find(s => s.id === secId);
    const el = document.getElementById('productsList');
    if (!el || !sec) return;
    el.innerHTML = (sec.content.products||[]).map((p, i) => `<div class="border rounded-lg p-3 mb-2 ${p.real_product_id ? 'bg-green-50 border-green-200' : 'bg-gray-50'}">
        ${p.real_product_id ? `<div class="flex items-center gap-1 mb-2 text-[10px] font-bold text-green-600"><i class="fas fa-link"></i> Linked: Product #${p.real_product_id} <button onclick="unlinkProduct('${secId}',${i})" class="ml-auto text-red-400 hover:text-red-600" title="Unlink"><i class="fas fa-unlink"></i></button></div>` : ''}
        <div class="flex items-start gap-2 mb-2">
            <div class="w-14 h-14 rounded-lg overflow-hidden bg-gray-200 flex-shrink-0 cursor-pointer" onclick="openMediaForArray('${secId}','content.products',${i},'image')">
                ${p.image ? `<img src="${p.image}" class="w-full h-full object-cover">` : '<div class="w-full h-full flex items-center justify-center text-gray-400 text-xs">📷</div>'}
            </div>
            <div class="flex-1 space-y-1">
                <input class="setting-input text-xs" value="${esc(p.name||'')}" placeholder="Name" onchange="updateArrayItem('${secId}','content.products',${i},'name',this.value)">
                <div class="grid grid-cols-2 gap-1">
                    <input class="setting-input text-xs" type="number" value="${p.price||0}" placeholder="Price" onchange="updateArrayItem('${secId}','content.products',${i},'price',Number(this.value))">
                    <input class="setting-input text-xs" type="number" value="${p.compare_price||0}" placeholder="Compare" onchange="updateArrayItem('${secId}','content.products',${i},'compare_price',Number(this.value))">
                </div>
                <input class="setting-input text-xs" value="${esc(p.description||'')}" placeholder="Description" onchange="updateArrayItem('${secId}','content.products',${i},'description',this.value)">
                <input class="setting-input text-xs" value="${esc(p.badge||'')}" placeholder="Badge (e.g. Best Seller)" onchange="updateArrayItem('${secId}','content.products',${i},'badge',this.value)">
                <input class="setting-input text-xs" value="${esc(p.product_link||'')}" placeholder="Product Link URL" onchange="updateArrayItem('${secId}','content.products',${i},'product_link',this.value)" style="border-color:#dbeafe">
            </div>
            <button onclick="removeArrayItem('${secId}','content.products',${i})" class="text-red-400 hover:text-red-600 text-xs p-1"><i class="fas fa-trash"></i></button>
        </div>
        ${!p.real_product_id ? `<button onclick="openProductPicker('${secId}',${i})" class="w-full py-1.5 rounded text-[11px] font-semibold border border-dashed border-green-300 text-green-600 hover:bg-green-50"><i class="fas fa-link mr-1"></i> Link Site Product</button>` : ''}
    </div>`).join('');
}

function renderFeaturesList(secId) {
    const sec = sections.find(s => s.id === secId);
    const el = document.getElementById('featuresList');
    if (!el || !sec) return;
    el.innerHTML = (sec.content.features||[]).map((f, i) => `<div class="border rounded-lg p-2 mb-2 bg-gray-50 flex gap-2">
        <input class="w-10 text-center text-lg border rounded p-1" value="${f.icon||''}" onchange="updateArrayItem('${secId}','content.features',${i},'icon',this.value)">
        <div class="flex-1 space-y-1">
            <input class="setting-input text-xs" value="${esc(f.title||'')}" placeholder="Title" onchange="updateArrayItem('${secId}','content.features',${i},'title',this.value)">
            <input class="setting-input text-xs" value="${esc(f.desc||'')}" placeholder="Description" onchange="updateArrayItem('${secId}','content.features',${i},'desc',this.value)">
        </div>
        <button onclick="removeArrayItem('${secId}','content.features',${i})" class="text-red-400 text-xs p-1"><i class="fas fa-trash"></i></button>
    </div>`).join('');
}

function renderTestimonialsList(secId) {
    const sec = sections.find(s => s.id === secId);
    const el = document.getElementById('testimonialsList');
    if (!el || !sec) return;
    el.innerHTML = (sec.content.items||[]).map((t, i) => `<div class="border rounded-lg p-2 mb-2 bg-gray-50 space-y-1">
        <div class="flex gap-2">
            <input class="setting-input text-xs flex-1" value="${esc(t.name||'')}" placeholder="Name" onchange="updateArrayItem('${secId}','content.items',${i},'name',this.value)">
            <input class="setting-input text-xs w-20" value="${esc(t.location||'')}" placeholder="Location" onchange="updateArrayItem('${secId}','content.items',${i},'location',this.value)">
        </div>
        <textarea class="setting-input text-xs" rows="2" placeholder="Review text" onchange="updateArrayItem('${secId}','content.items',${i},'text',this.value)">${esc(t.text||'')}</textarea>
        <div class="flex justify-between items-center">
            <select class="setting-input text-xs w-20" onchange="updateArrayItem('${secId}','content.items',${i},'rating',Number(this.value))">
                ${[5,4,3,2,1].map(r => `<option value="${r}" ${t.rating==r?'selected':''}>${'★'.repeat(r)}</option>`).join('')}
            </select>
            <button onclick="removeArrayItem('${secId}','content.items',${i})" class="text-red-400 text-xs"><i class="fas fa-trash"></i></button>
        </div>
    </div>`).join('');
}

function renderFaqList(secId) {
    const sec = sections.find(s => s.id === secId);
    const el = document.getElementById('faqList');
    if (!el || !sec) return;
    el.innerHTML = (sec.content.items||[]).map((q, i) => `<div class="border rounded-lg p-2 mb-2 bg-gray-50 space-y-1">
        <input class="setting-input text-xs font-semibold" value="${esc(q.q||'')}" placeholder="Question" onchange="updateArrayItem('${secId}','content.items',${i},'q',this.value)">
        <textarea class="setting-input text-xs" rows="2" placeholder="Answer" onchange="updateArrayItem('${secId}','content.items',${i},'a',this.value)">${esc(q.a||'')}</textarea>
        <button onclick="removeArrayItem('${secId}','content.items',${i})" class="text-red-400 text-xs"><i class="fas fa-trash"></i></button>
    </div>`).join('');
}

function renderBadgesList(secId) {
    const sec = sections.find(s => s.id === secId);
    const el = document.getElementById('badgesList');
    if (!el || !sec) return;
    el.innerHTML = (sec.content.badges||[]).map((b, i) => `<div class="flex gap-2 mb-2 items-center">
        <input class="w-10 text-center text-lg border rounded p-1" value="${b.icon||''}" onchange="updateArrayItem('${secId}','content.badges',${i},'icon',this.value)">
        <input class="setting-input text-xs flex-1" value="${esc(b.text||'')}" placeholder="Text" onchange="updateArrayItem('${secId}','content.badges',${i},'text',this.value)">
        <button onclick="removeArrayItem('${secId}','content.badges',${i})" class="text-red-400 text-xs"><i class="fas fa-trash"></i></button>
    </div>`).join('');
}

// Array helpers
function addProduct(secId) { const s=sections.find(x=>x.id===secId); if(s){if(!s.content.products)s.content.products=[];s.content.products.push({name:'নতুন পণ্য',price:999,compare_price:0,image:'',badge:'',description:'',product_link:'',real_product_id:0});renderSectionSettings(secId);renderSections();}}

// ═══ PRODUCT PICKER ═══
let _pickerSecId='', _pickerIdx=-1;
function openProductPicker(secId, idx) {
    _pickerSecId = secId; _pickerIdx = idx;
    let modal = document.getElementById('productPickerModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'productPickerModal';
        modal.innerHTML = `<div style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;display:flex;align-items:center;justify-content:center" onclick="if(event.target===this)closeProductPicker()">
            <div style="background:white;border-radius:16px;width:90%;max-width:500px;max-height:80vh;display:flex;flex-direction:column;overflow:hidden" onclick="event.stopPropagation()">
                <div style="padding:16px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:10px">
                    <div style="flex:1;position:relative">
                        <input type="text" id="productSearchInput" placeholder="🔍 পণ্য খুঁজুন... (নাম বা SKU)" style="width:100%;padding:10px 14px;border:2px solid #e5e7eb;border-radius:10px;font-size:14px;outline:none" oninput="searchProducts(this.value)">
                    </div>
                    <button onclick="closeProductPicker()" style="width:32px;height:32px;border:none;background:#f3f4f6;border-radius:50%;cursor:pointer;font-size:16px">✕</button>
                </div>
                <div id="productSearchResults" style="flex:1;overflow-y:auto;padding:12px;min-height:200px">
                    <div style="text-align:center;color:#9ca3af;padding:40px 0;font-size:13px">Search for products or browse all</div>
                </div>
            </div>
        </div>`;
        document.body.appendChild(modal);
    }
    modal.style.display = 'block';
    document.getElementById('productSearchInput').value = '';
    searchProducts('');
    setTimeout(() => document.getElementById('productSearchInput').focus(), 100);
}
function closeProductPicker() { const m=document.getElementById('productPickerModal'); if(m) m.style.display='none'; }

let _searchTimer = null;
function searchProducts(q) {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => {
        fetch(`${API}?action=search_products&q=${encodeURIComponent(q)}&limit=15`)
        .then(r=>r.json()).then(d => {
            const el = document.getElementById('productSearchResults');
            if (!d.success || !d.data.length) { el.innerHTML = '<div style="text-align:center;color:#9ca3af;padding:40px 0;font-size:13px">কোনো পণ্য পাওয়া যায়নি</div>'; return; }
            el.innerHTML = d.data.map(p => `<div onclick="selectSiteProduct(${p.id})" style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:6px;cursor:pointer;transition:all .15s" onmouseover="this.style.background='#f0fdf4';this.style.borderColor='#86efac'" onmouseout="this.style.background='';this.style.borderColor='#e5e7eb'">
                <div style="width:48px;height:48px;border-radius:8px;overflow:hidden;background:#f1f5f9;flex-shrink:0">
                    ${p.image_url ? `<img src="${p.image_url}" style="width:100%;height:100%;object-fit:cover">` : '<div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:18px;color:#ccc">📦</div>'}
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.name_bn||p.name)}</div>
                    <div style="font-size:11px;color:#6b7280">${p.category_name||''} ${p.sku?'· '+p.sku:''}</div>
                </div>
                <div style="text-align:right;flex-shrink:0">
                    <div style="font-size:14px;font-weight:800;color:#16a34a">৳${Number(p.price).toLocaleString()}</div>
                    ${p.compare_price > 0 ? `<div style="font-size:10px;text-decoration:line-through;color:#9ca3af">৳${Number(p.compare_price).toLocaleString()}</div>` : ''}
                </div>
            </div>`).join('');
        }).catch(()=>{});
    }, 300);
}

function selectSiteProduct(productId) {
    fetch(`${API}?action=search_products&q=&limit=50`)
    .then(r=>r.json()).then(d => {
        const p = (d.data||[]).find(x => x.id == productId);
        if (!p) return;
        const sec = sections.find(s => s.id === _pickerSecId);
        if (!sec || !sec.content.products[_pickerIdx]) return;
        const prod = sec.content.products[_pickerIdx];
        prod.real_product_id = p.id;
        prod.name = p.name_bn || p.name;
        prod.price = p.price;
        prod.compare_price = p.compare_price || 0;
        prod.image = p.image_url || '';
        prod.product_link = p.product_url || '';
        closeProductPicker();
        renderSectionSettings(_pickerSecId);
        renderSections();
        showToast('✓ Product linked: ' + prod.name, 'green');
    });
}

function unlinkProduct(secId, idx) {
    const sec = sections.find(s => s.id === secId);
    if (!sec || !sec.content.products[idx]) return;
    sec.content.products[idx].real_product_id = 0;
    renderSectionSettings(secId);
    showToast('Product unlinked', 'yellow');
}

// Add product from site directly (opens picker with new product slot)
function addProductFromSite(secId) {
    const s = sections.find(x => x.id === secId);
    if (!s) return;
    if (!s.content.products) s.content.products = [];
    s.content.products.push({name:'',price:0,compare_price:0,image:'',badge:'',description:'',product_link:'',real_product_id:0});
    const newIdx = s.content.products.length - 1;
    openProductPicker(secId, newIdx);
}
function addFeature(secId) { const s=sections.find(x=>x.id===secId); if(s){if(!s.content.features)s.content.features=[];s.content.features.push({icon:'⭐',title:'নতুন ফিচার',desc:'বিবরণ'});renderSectionSettings(secId);renderSections();}}
function addTestimonial(secId) { const s=sections.find(x=>x.id===secId); if(s){if(!s.content.items)s.content.items=[];s.content.items.push({name:'কাস্টমার',location:'ঢাকা',rating:5,text:'রিভিউ',avatar:''});renderSectionSettings(secId);renderSections();}}
function addFaqItem(secId) { const s=sections.find(x=>x.id===secId); if(s){if(!s.content.items)s.content.items=[];s.content.items.push({q:'নতুন প্রশ্ন',a:'উত্তর'});renderSectionSettings(secId);renderSections();}}
function addBadge(secId) { const s=sections.find(x=>x.id===secId); if(s){if(!s.content.badges)s.content.badges=[];s.content.badges.push({icon:'🛡️',text:'নতুন ব্যাজ'});renderSectionSettings(secId);renderSections();}}

function updateArrayItem(secId, path, idx, key, value) {
    const sec = sections.find(s => s.id === secId);
    if (!sec) return;
    const parts = path.split('.');
    let obj = sec;
    parts.forEach(p => obj = obj[p]);
    if (obj[idx]) obj[idx][key] = value;
    renderSections();
}

function removeArrayItem(secId, path, idx) {
    const sec = sections.find(s => s.id === secId);
    if (!sec) return;
    const parts = path.split('.');
    let obj = sec;
    parts.forEach(p => obj = obj[p]);
    obj.splice(idx, 1);
    renderSectionSettings(secId);
    renderSections();
}

// ═══ MEDIA GALLERY ═══
function openMediaForSetting(secId, path) {
    _mediaCallback = (url) => { updateSetting(secId, path, url); renderSectionSettings(secId); };
    openMediaPicker();
}
function openMediaForArray(secId, path, idx, key) {
    _mediaCallback = (url) => { updateArrayItem(secId, path, idx, key, url); renderSectionSettings(secId); };
    openMediaPicker();
}
function openMediaPicker() {
    document.getElementById('mediaModal').classList.remove('hidden');
    loadMediaImages();
}
function closeMediaPicker() {
    document.getElementById('mediaModal').classList.add('hidden');
    _mediaCallback = null;
}
function loadMediaImages() {
    const folder = document.getElementById('mediaFolder').value;
    const grid = document.getElementById('mediaGrid');
    grid.innerHTML = '<div class="col-span-full text-center py-16 text-gray-400"><i class="fas fa-spinner fa-spin text-2xl"></i></div>';
    fetch(MEDIA_API+'?api=list&folder='+folder).then(r=>r.json()).then(data => {
        if (!data.success || !data.files?.length) { grid.innerHTML = '<div class="col-span-full text-center py-16 text-gray-400">No images</div>'; return; }
        grid.innerHTML = data.files.map(f => `<div class="relative group cursor-pointer rounded-lg overflow-hidden border-2 border-transparent hover:border-blue-500 bg-gray-100" style="aspect-ratio:1" onclick="selectMediaImage('${f.url}')">
            <img src="${f.url}" class="w-full h-full object-cover" loading="lazy">
            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center"><span class="text-white text-xs font-bold bg-blue-600 px-3 py-1.5 rounded-lg">✓ Select</span></div>
        </div>`).join('');
    });
}
function selectMediaImage(url) {
    if (_mediaCallback) _mediaCallback(url);
    closeMediaPicker();
}
function uploadMediaFile(input) {
    const file = input.files[0]; if (!file) return;
    const fd = new FormData(); fd.append('file', file); fd.append('folder', 'general');
    fetch('<?= SITE_URL ?>/api/media.php?action=upload', {method:'POST',body:fd}).then(r=>r.json()).then(d => {
        if (d.success || d.url) { if (_mediaCallback) { _mediaCallback(d.url); closeMediaPicker(); } else { loadMediaImages(); }}
    });
}

// ═══ GLOBAL SETTINGS ═══
function updateGlobalSettings() {
    pageSettings.primary_color = document.getElementById('settPrimary').value;
    pageSettings.secondary_color = document.getElementById('settSecondary').value;
    pageSettings.font_heading = document.getElementById('settFontHeading').value;
    pageSettings.font_body = document.getElementById('settFontBody').value;
    pageSettings.floating_cta = pageSettings.floating_cta || {};
    pageSettings.floating_cta.enabled = document.getElementById('settFloatingCTA').checked;
    pageSettings.show_header = document.getElementById('settShowHeader').checked;
    pageSettings.show_footer = document.getElementById('settShowFooter').checked;
    pageSettings.whatsapp = pageSettings.whatsapp || {};
    pageSettings.whatsapp.enabled = document.getElementById('settWhatsApp').checked;
    
    // Product click & checkout settings
    pageSettings.product_click_action = document.getElementById('settProductClick').value;
    pageSettings.checkout_mode = document.getElementById('settCheckoutMode').value;
    pageSettings.default_product = document.getElementById('settDefaultProduct').value !== '' ? parseInt(document.getElementById('settDefaultProduct').value) : -1;
    pageSettings.order_form = pageSettings.order_form || {};
    pageSettings.order_form.enabled = pageSettings.checkout_mode !== 'hidden';
    pageSettings.order_form.title = document.getElementById('settOrderTitle').value || 'অর্ডার করুন';
    pageSettings.order_form.subtitle = document.getElementById('settOrderSubtitle').value || '';
    pageSettings.order_form.button_text = document.getElementById('settOrderButton').value || 'ক্যাশ অন ডেলিভারিতে অর্ডার করুন';
    pageSettings.order_form.button_color = document.getElementById('settOrderBtnColor').value;
    pageSettings.order_form.success_title = document.getElementById('settSuccessTitle').value || 'অর্ডার সফল হয়েছে!';
    pageSettings.order_form.success_message = document.getElementById('settSuccessMsg').value || 'শীঘ্রই আমাদের টিম আপনার সাথে যোগাযোগ করবে।';
    pageSettings.order_form.delivery_charges = {
        inside_dhaka: parseInt(document.getElementById('settDelDhaka').value) || 70,
        dhaka_sub: parseInt(document.getElementById('settDelSub').value) || 100,
        outside_dhaka: parseInt(document.getElementById('settDelOut').value) || 130,
    };
    updateCheckoutHint();
    
    // checkout_fields are saved via the modal (lpCfSaveClose), no action needed here
    // lp_upsell_products are managed via lpUpRender, no action needed here
    
    // Post-order redirect
    pageSettings.redirect_enabled = document.getElementById('settRedirectEnabled').checked;
    pageSettings.redirect_url = document.getElementById('settRedirectUrl').value || '';
    document.getElementById('redirectUrlWrap').classList.toggle('hidden', !pageSettings.redirect_enabled);
    
    // Product click hint
    updateProductClickHint();
}
function applyGlobalSettings() {
    if (pageSettings.primary_color) document.getElementById('settPrimary').value = pageSettings.primary_color;
    if (pageSettings.secondary_color) document.getElementById('settSecondary').value = pageSettings.secondary_color;
    if (pageSettings.font_heading) document.getElementById('settFontHeading').value = pageSettings.font_heading;
    if (pageSettings.font_body) document.getElementById('settFontBody').value = pageSettings.font_body;
    document.getElementById('settFloatingCTA').checked = pageSettings.floating_cta?.enabled !== false;
    document.getElementById('settShowHeader').checked = pageSettings.show_header !== false;
    document.getElementById('settShowFooter').checked = pageSettings.show_footer !== false;
    document.getElementById('settWhatsApp').checked = pageSettings.whatsapp?.enabled !== false;
    document.getElementById('settABTest').checked = !!pageSettings.ab_test_enabled;
    
    // Product click & checkout settings
    document.getElementById('settProductClick').value = pageSettings.product_click_action || 'regular_checkout';
    document.getElementById('settCheckoutMode').value = pageSettings.checkout_mode || 'landing';
    refreshDefaultProductDropdown();
    document.getElementById('settDefaultProduct').value = (pageSettings.default_product >= 0) ? pageSettings.default_product : '';
    const of = pageSettings.order_form || {};
    document.getElementById('settOrderTitle').value = of.title || 'অর্ডার করুন';
    document.getElementById('settOrderSubtitle').value = of.subtitle || '';
    document.getElementById('settOrderButton').value = of.button_text || 'ক্যাশ অন ডেলিভারিতে অর্ডার করুন';
    document.getElementById('settOrderBtnColor').value = of.button_color || pageSettings.primary_color || '#3b82f6';
    document.getElementById('settDelDhaka').value = of.delivery_charges?.inside_dhaka ?? 70;
    document.getElementById('settDelSub').value = of.delivery_charges?.dhaka_sub ?? 100;
    document.getElementById('settDelOut').value = of.delivery_charges?.outside_dhaka ?? 130;
    document.getElementById('settSuccessTitle').value = of.success_title || 'অর্ডার সফল হয়েছে!';
    document.getElementById('settSuccessMsg').value = of.success_message || 'শীঘ্রই আমাদের টিম আপনার সাথে যোগাযোগ করবে।';
    updateCheckoutHint();
    
    // Redirect
    document.getElementById('settRedirectEnabled').checked = !!pageSettings.redirect_enabled;
    document.getElementById('settRedirectUrl').value = pageSettings.redirect_url || '';
    document.getElementById('redirectUrlWrap').classList.toggle('hidden', !pageSettings.redirect_enabled);
    
    // Upsell products
    lpUpRender();
    
    // Product click hint
    updateProductClickHint();
}

function updateProductClickHint() {
    const mode = document.getElementById('settProductClick').value;
    const hint = document.getElementById('productClickHint');
    if (hint) {
        const msgs = {
            regular_checkout: '🛒 Opens site checkout popup (with coupons, cart, progress bar)',
            landing_popup: '📝 Opens LP quick-order popup (name, phone, address)',
            scroll_to_order: '⬇️ Scrolls down to bottom order form',
            product_link: '🔗 Opens the product page link'
        };
        hint.textContent = msgs[mode] || '';
    }
}

function updateCheckoutHint() {
    const mode = document.getElementById('settCheckoutMode').value;
    const hint = document.getElementById('checkoutModeHint');
    if (hint) {
        const msgs = {
            landing: '📝 Inline form with LP delivery charges (set below)',
            regular: '📝 Inline form with site-wide delivery charges',
            hidden: '🚫 No checkout — info/content page only'
        };
        hint.textContent = msgs[mode] || '';
    }
}

function refreshDefaultProductDropdown() {
    const sel = document.getElementById('settDefaultProduct');
    if (!sel) return;
    const curVal = sel.value;
    let opts = '<option value="">— None —</option>';
    let idx = 0;
    sections.forEach(sec => {
        if (sec.type === 'products') {
            (sec.content.products || []).forEach((p, i) => {
                const name = p.name || ('Product ' + (i + 1));
                const price = p.price ? ' (৳' + Number(p.price).toLocaleString() + ')' : '';
                opts += `<option value="${idx}">${esc(name)}${price}</option>`;
                idx++;
            });
        }
    });
    sel.innerHTML = opts;
    sel.value = curVal;
}

// ═══ SAVE ═══
function savePage() {
    updateGlobalSettings();
    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('id', PAGE_ID);
    fd.append('title', document.getElementById('pageTitle').value || 'Untitled');
    fd.append('slug', document.getElementById('pageSlug').value || '');
    fd.append('status', document.getElementById('pageStatus').value);
    fd.append('sections', JSON.stringify(sections));
    var _ss=JSON.parse(JSON.stringify(pageSettings));delete _ss._lp_upsell_data;fd.append('settings',JSON.stringify(_ss));
    fd.append('seo_title', document.getElementById('seoTitle')?.value || '');
    fd.append('seo_description', document.getElementById('seoDesc')?.value || '');
    fd.append('ab_test_enabled', document.getElementById('settABTest').checked ? 1 : 0);
    
    fetch(API, {method:'POST',body:fd}).then(r=>r.json()).then(d => {
        if (d.success) {
            if (!PAGE_ID && d.id) window.location.href = '<?= adminUrl("pages/landing-page-builder.php") ?>?id=' + d.id + '&tab=builder';
            else {
                showToast('Saved!');
                if (d.slug) document.getElementById('pageSlug').value = d.slug;
                // Reload sections to pick up auto-created product IDs
                fetch(API + '?action=get&id=' + PAGE_ID).then(r=>r.json()).then(g => {
                    if (g.success && g.data && g.data.sections) {
                        sections = g.data.sections;
                        renderSections();
                    }
                }).catch(function(){});
            }
        } else if (d.slug_conflict) {
            showToast(d.error || 'Slug already exists! Choose a different slug.', 'red');
            document.getElementById('pageSlug').focus();
            document.getElementById('pageSlug').style.borderColor = '#ef4444';
            setTimeout(() => document.getElementById('pageSlug').style.borderColor = '', 3000);
        } else {
            showToast(d.error || 'Save failed', 'red');
        }
    });
}

function saveAsTemplate() {
    const name = prompt('Template name:', document.getElementById('pageTitle').value || 'My Template');
    if (!name) return;
    updateGlobalSettings();
    const fd = new FormData();
    fd.append('action', 'save_template');
    fd.append('name', name);
    fd.append('sections', JSON.stringify(sections));
    var _ss=JSON.parse(JSON.stringify(pageSettings));delete _ss._lp_upsell_data;fd.append('settings',JSON.stringify(_ss));
    fetch(API, {method:'POST',body:fd}).then(r=>r.json()).then(d => {
        if (d.success) showToast('Template saved!');
    });
}

function previewPage() {
    updateGlobalSettings();
    const slug = document.getElementById('pageSlug').value;
    const token = 'preview_token'; // admin session handles auth
    
    // Save first, then open preview
    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('id', PAGE_ID);
    fd.append('title', document.getElementById('pageTitle').value || 'Untitled');
    fd.append('slug', slug || document.getElementById('pageTitle').value || 'untitled');
    fd.append('status', document.getElementById('pageStatus').value);
    fd.append('sections', JSON.stringify(sections));
    var _ss=JSON.parse(JSON.stringify(pageSettings));delete _ss._lp_upsell_data;fd.append('settings',JSON.stringify(_ss));
    fd.append('seo_title', document.getElementById('seoTitle')?.value || '');
    fd.append('seo_description', document.getElementById('seoDesc')?.value || '');
    fd.append('ab_test_enabled', document.getElementById('settABTest').checked ? 1 : 0);
    
    fetch(API, {method:'POST',body:fd}).then(r=>r.json()).then(d => {
        if (d.success) {
            const finalSlug = d.slug || slug;
            if (d.slug) document.getElementById('pageSlug').value = d.slug;
            if (!PAGE_ID && d.id) {
                window.location.href = '<?= adminUrl("pages/landing-page-builder.php") ?>?id=' + d.id + '&tab=builder';
            } else {
                window.open(SITE_URL + '/lp/' + finalSlug + '?preview=1', '_blank');
            }
        } else {
            showToast(d.error || 'Save failed — cannot preview', 'red');
        }
    });
}

// ═══ SLUG VALIDATION ═══
// ═══ SLUG SYNC + VALIDATION ═══
let slugCheckTimer = null;
function syncSlug(val, source) {
    // Clean the slug
    const clean = val.toLowerCase().replace(/[^a-z0-9\u0980-\u09FF]+/g, '-').replace(/^-+|-+$/g, '');
    // Sync both fields
    if (source !== 'panel') document.getElementById('pageSlug').value = clean;
    if (source !== 'top') { const t = document.getElementById('pageSlugTop'); if (t) t.value = clean; }
    // Check availability
    clearTimeout(slugCheckTimer);
    const indicators = [document.getElementById('slugStatus'), document.getElementById('slugStatusTop')].filter(Boolean);
    if (!clean) { indicators.forEach(el => el.innerHTML = ''); return; }
    indicators.forEach(el => el.innerHTML = '<span class="text-gray-400 text-[10px]">...</span>');
    slugCheckTimer = setTimeout(() => {
        fetch(API + '?action=check_slug&slug=' + encodeURIComponent(clean) + '&exclude_id=' + PAGE_ID)
            .then(r => r.json()).then(d => {
                const html = d.available
                    ? '<span class="text-green-600 text-[10px]">✓</span>'
                    : '<span class="text-red-500 text-[10px]">✕ taken</span>';
                indicators.forEach(el => el.innerHTML = html);
            });
    }, 400);
}
function copyLpUrl() {
    const slug = document.getElementById('pageSlug').value || document.getElementById('pageSlugTop')?.value || '';
    if (!slug) { showToast('Save the page first', 'yellow'); return; }
    const url = SITE_URL + '/lp/' + slug;
    navigator.clipboard.writeText(url).then(() => showToast('URL copied!')).catch(() => {
        // Fallback
        const ta = document.createElement('textarea'); ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
        showToast('URL copied!');
    });
}

function showToast(msg, color='green') {
    const t = document.createElement('div');
    const bg = color === 'red' ? 'bg-red-600' : color === 'yellow' ? 'bg-yellow-500' : 'bg-green-600';
    t.className = `fixed bottom-6 right-6 ${bg} text-white px-5 py-3 rounded-xl shadow-xl font-semibold text-sm z-[999]`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

// ═══ ANALYTICS ═══
function loadAnalytics() {
    if (!PAGE_ID) return;
    const days = document.getElementById('analyticsDays')?.value || 30;
    const container = document.getElementById('analyticsData');
    container.innerHTML = '<div class="text-center py-16"><i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i></div>';
    
    fetch(API+'?action=analytics&page_id='+PAGE_ID+'&days='+days).then(r=>r.json()).then(res => {
        if (!res.success) { container.innerHTML = '<p class="text-red-500">Error loading analytics</p>'; return; }
        const d = res.data;
        const s = d.summary || {};
        
        container.innerHTML = `
            <div class="grid grid-cols-5 gap-4">
                <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Unique Views</div><div class="text-2xl font-bold mt-1">${s.views||0}</div></div>
                <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Orders</div><div class="text-2xl font-bold text-green-600 mt-1">${s.orders||0}</div></div>
                <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Revenue</div><div class="text-2xl font-bold text-emerald-600 mt-1">৳${Number(s.revenue||0).toLocaleString()}</div></div>
                <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Conversion Rate</div><div class="text-2xl font-bold ${s.conversion_rate>=5?'text-green-600':s.conversion_rate>=2?'text-blue-600':'text-gray-600'} mt-1">${s.conversion_rate||0}%</div></div>
                <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Avg Time</div><div class="text-2xl font-bold mt-1">${s.avg_time||0}s</div></div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-white rounded-xl border p-5">
                    <h3 class="font-bold text-gray-800 mb-3">Device Breakdown</h3>
                    <div class="space-y-2">${(d.devices||[]).map(dv => {
                        const total = (d.devices||[]).reduce((s,x) => s+parseInt(x.cnt),0);
                        const pct = total ? Math.round(parseInt(dv.cnt)/total*100) : 0;
                        return `<div class="flex items-center gap-3"><span class="text-sm w-16 font-medium">${dv.device_type||'?'}</span><div class="flex-1 bg-gray-100 rounded-full h-5"><div class="bg-blue-500 h-5 rounded-full text-[10px] text-white font-bold flex items-center justify-center" style="width:${pct}%">${pct}%</div></div><span class="text-xs text-gray-500 w-10 text-right">${dv.cnt}</span></div>`;
                    }).join('')}</div>
                </div>
                <div class="bg-white rounded-xl border p-5">
                    <h3 class="font-bold text-gray-800 mb-3">Scroll Depth</h3>
                    <div class="space-y-2">${(d.scroll_depth||[]).map(sd => {
                        const maxC = Math.max(...(d.scroll_depth||[]).map(x => parseInt(x.cnt)),1);
                        const pct = Math.round(parseInt(sd.cnt)/maxC*100);
                        return `<div class="flex items-center gap-3"><span class="text-sm w-12 font-medium">${sd.depth||0}%</span><div class="flex-1 bg-gray-100 rounded-full h-4"><div class="bg-green-500 h-4 rounded-full" style="width:${pct}%"></div></div><span class="text-xs text-gray-500 w-10 text-right">${sd.cnt}</span></div>`;
                    }).join('')}${(d.scroll_depth||[]).length===0?'<p class="text-sm text-gray-400">No scroll data yet</p>':''}</div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl border p-5">
                <h3 class="font-bold text-gray-800 mb-3">Section Engagement</h3>
                <div class="space-y-2">${(d.sections||[]).map(se => `<div class="flex items-center gap-4 py-2 border-b border-gray-50">
                    <span class="text-sm font-medium w-32">${se.section_type||'?'}</span>
                    <div class="flex-1"><div class="text-xs text-gray-500">Views: ${se.views||0}</div></div>
                    <div class="text-xs text-gray-500">Avg time: ${Math.round(se.avg_time||0)}s</div>
                </div>`).join('')}${(d.sections||[]).length===0?'<p class="text-sm text-gray-400">No section data yet</p>':''}</div>
            </div>
            
            ${d.ab_test ? `<div class="bg-white rounded-xl border p-5">
                <h3 class="font-bold text-gray-800 mb-3">A/B Test Results</h3>
                <div class="grid grid-cols-2 gap-4">
                    ${['A','B'].map(v => `<div class="border rounded-xl p-4 ${d.ab_test[v]?.rate > (d.ab_test[v==='A'?'B':'A']?.rate||0) ? 'border-green-300 bg-green-50' : ''}">
                        <div class="text-lg font-bold">Variant ${v}</div>
                        <div class="mt-2 space-y-1 text-sm">
                            <div>Views: <b>${d.ab_test[v]?.views||0}</b></div>
                            <div>Orders: <b>${d.ab_test[v]?.orders||0}</b></div>
                            <div>Conv Rate: <b class="text-lg">${d.ab_test[v]?.rate||0}%</b></div>
                        </div>
                    </div>`).join('')}
                </div>
            </div>` : ''}
            
            <div class="bg-white rounded-xl border p-5">
                <h3 class="font-bold text-gray-800 mb-3">Click Heatmap</h3>
                <p class="text-sm text-gray-500 mb-3">${(d.heatmap||[]).length} click events recorded</p>
                ${(d.heatmap||[]).length > 0 ? `<div id="heatmapWrap" style="position:relative;width:100%;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb">
                    <iframe id="heatmapFrame" src="${SITE_URL}/lp/${document.getElementById('pageSlug')?.value || ''}?preview=1" style="width:100%;height:600px;border:0;pointer-events:none;display:block" onload="drawHeatmapOverlay()"></iframe>
                    <div id="heatmapOverlay" style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:10"></div>
                </div>
                <div style="margin-top:8px;display:flex;align-items:center;gap:12px">
                    <span class="text-xs text-gray-400">Intensity:</span>
                    <span style="display:inline-block;width:80px;height:8px;border-radius:4px;background:linear-gradient(90deg,rgba(0,0,255,0.3),rgba(0,255,0,0.5),rgba(255,255,0,0.7),rgba(255,0,0,0.9))"></span>
                    <span class="text-xs text-gray-400">Low → High</span>
                </div>` : '<p class="text-sm text-gray-400">No click data yet — data will appear after visitors interact with the page.</p>'}
            </div>
        `;
        
        // Store heatmap data for overlay rendering
        window._heatmapData = d.heatmap || [];
    });
}

function drawHeatmapOverlay() {
    const overlay = document.getElementById('heatmapOverlay');
    const frame = document.getElementById('heatmapFrame');
    if (!overlay || !frame || !window._heatmapData?.length) return;
    
    // Match overlay to iframe scrollable height
    const frameHeight = frame.clientHeight;
    const frameWidth = frame.clientWidth;
    overlay.innerHTML = '';
    
    // Count clicks per area for intensity
    const grid = {};
    window._heatmapData.forEach(pt => {
        const gx = Math.round(parseFloat(pt.x) / 2) * 2;
        const gy = Math.round(parseFloat(pt.y) / 2) * 2;
        const key = gx + '_' + gy;
        grid[key] = (grid[key] || 0) + 1;
    });
    const maxCount = Math.max(...Object.values(grid), 1);
    
    window._heatmapData.forEach(pt => {
        const x = (parseFloat(pt.x) || 0) / 100 * frameWidth;
        const y = (parseFloat(pt.y) || 0) / 100 * frameHeight;
        const gx = Math.round(parseFloat(pt.x) / 2) * 2;
        const gy = Math.round(parseFloat(pt.y) / 2) * 2;
        const intensity = (grid[gx + '_' + gy] || 1) / maxCount;
        
        const dot = document.createElement('div');
        const r = Math.round(255 * intensity);
        const g = Math.round(255 * (1 - intensity) * 0.7);
        const size = 16 + intensity * 20;
        dot.style.cssText = `position:absolute;left:${x}px;top:${y}px;width:${size}px;height:${size}px;border-radius:50%;background:radial-gradient(circle,rgba(${r},${g},0,${0.3+intensity*0.5}) 0%,rgba(${r},${g},0,0) 70%);transform:translate(-50%,-50%);pointer-events:none`;
        overlay.appendChild(dot);
    });
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ═══ CHECKOUT FORM FIELD EDITOR (Full Modal) ═══
const CF_ICONS = {product_selector:'fa-box-open',name:'fa-user',phone:'fa-phone',email:'fa-envelope',address:'fa-location-dot',shipping_area:'fa-truck',lp_upsells:'fa-fire',notes:'fa-sticky-note'};
let _cfFields = [];
let _cfSortable = null;

function lpCfOpen() {
    _cfFields = JSON.parse(JSON.stringify(pageSettings.checkout_fields || SITE_CF_DEFAULTS));
    _cfRenderList();
    _cfRenderPreview();
    _cfInitSortable();
    document.getElementById('lpCfModal').classList.add('active');
}

function lpCfClose() {
    document.getElementById('lpCfModal').classList.remove('active');
}

function lpCfSaveClose() {
    // Read current state from DOM
    _cfReadFromDom();
    pageSettings.checkout_fields = JSON.parse(JSON.stringify(_cfFields));
    lpCfClose();
}

function lpCfResetModal() {
    if (!confirm('Reset to site-wide defaults? Your LP-specific changes will be lost.')) return;
    _cfFields = JSON.parse(JSON.stringify(SITE_CF_DEFAULTS));
    _cfRenderList();
    _cfRenderPreview();
    _cfInitSortable();
}

function _cfInitSortable() {
    const list = document.getElementById('lpCfFieldList');
    if (!list || typeof Sortable === 'undefined') return;
    if (_cfSortable) _cfSortable.destroy();
    _cfSortable = new Sortable(list, {
        handle: '.cf-drag',
        animation: 200,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        draggable: '.cf-field',
        onEnd: function() {
            _cfReadFromDom();
            _cfRenderPreview();
        }
    });
}

function _cfRenderList() {
    const list = document.getElementById('lpCfFieldList');
    if (!list) return;
    let html = '';
    _cfFields.forEach(function(f) {
        const on = f.enabled !== false;
        const req = !!f.required;
        const icon = CF_ICONS[f.key] || 'fa-grip-lines';
        const isInput = ['text','tel','email','textarea'].indexOf(f.type || 'text') !== -1;

        html += '<div class="cf-field' + (on ? '' : ' cf-off') + '" data-key="' + f.key + '" data-type="' + (f.type || 'text') + '">';
        // Main row
        html += '<div class="cf-row">';
        html +=   '<span class="cf-drag"><svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 100 4 2 2 0 000-4zm6 0a2 2 0 100 4 2 2 0 000-4zM7 8a2 2 0 100 4 2 2 0 000-4zm6 0a2 2 0 100 4 2 2 0 000-4zM7 14a2 2 0 100 4 2 2 0 000-4zm6 0a2 2 0 100 4 2 2 0 000-4z"/></svg></span>';
        html +=   '<span class="cf-icon"><i class="fas ' + icon + '"></i></span>';
        html +=   '<div class="cf-info">';
        html +=     '<span class="cf-label">' + esc(f.label || f.key) + '</span>';
        html +=     '<div class="cf-key">' + f.key + ' • ' + (f.type || 'text') + (f.placeholder ? ' • ' + esc(f.placeholder).substring(0, 20) : '') + '</div>';
        html +=   '</div>';
        html +=   '<div class="cf-acts">';
        if (isInput) {
            html += '<label style="display:flex;align-items:center;gap:3px;cursor:pointer" title="Required"><span style="font-size:10px;color:#94a3b8">Required</span><span class="cf-toggle cf-req"><input type="checkbox" class="cf-req-cb"' + (req ? ' checked' : '') + '><span class="cf-track"></span></span></label>';
        }
        html +=   '<label style="display:flex;align-items:center;gap:3px;cursor:pointer" title="Show"><span style="font-size:10px;color:#94a3b8">Show</span><span class="cf-toggle"><input type="checkbox" class="cf-show-cb"' + (on ? ' checked' : '') + '><span class="cf-track"></span></span></label>';
        html +=   '<button type="button" class="cf-expand-btn" onclick="cfToggleDetail(this)"><i class="fas fa-chevron-down" style="font-size:11px"></i></button>';
        html +=   '</div>';
        html += '</div>';

        // Detail panel
        html += '<div class="cf-detail">';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">';
        html += '<div><label style="display:block;font-size:10px;font-weight:600;color:#6b7280;margin-bottom:3px">Label (shown on form)</label><input class="cf-d-label" type="text" value="' + esc(f.label || '') + '"></div>';
        html += '<div><label style="display:block;font-size:10px;font-weight:600;color:#6b7280;margin-bottom:3px">Placeholder text</label><input class="cf-d-ph" type="text" value="' + esc(f.placeholder || '') + '"></div>';
        html += '</div>';
        
        // Special: Upsell product picker for lp_upsells
        if (f.key === 'lp_upsells') {
            html += '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #e5e7eb">';
            html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">';
            html += '<label style="font-size:11px;font-weight:600;color:#f97316"><i class="fas fa-fire mr-1"></i>Upsell Products</label>';
            html += '<button type="button" onclick="cfUpsellSearch()" style="font-size:10px;padding:3px 10px;border:1px solid #f97316;border-radius:6px;background:#fff7ed;color:#f97316;cursor:pointer;font-weight:600"><i class="fas fa-plus mr-1"></i>Add Product</button>';
            html += '</div>';
            html += '<div id="cfUpsellProducts" style="display:flex;flex-direction:column;gap:4px">';
            var ups = pageSettings.lp_upsell_products || [];
            if (ups.length === 0) {
                html += '<div style="text-align:center;padding:12px;background:#fff7ed;border:1px dashed #fed7aa;border-radius:8px;font-size:11px;color:#f97316">No upsell products selected — will auto-suggest from product database</div>';
            } else {
                ups.forEach(function(u, ui) {
                    html += '<div class="cf-up-item" style="display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid #e5e7eb;border-radius:8px;background:#fff">';
                    html += '<div style="width:32px;height:32px;background:#f1f5f9;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center"><i class="fas fa-box" style="color:#f97316;font-size:11px"></i></div>';
                    html += '<div style="flex:1;min-width:0"><div style="font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(u.name) + '</div><div style="font-size:10px;color:#f97316;font-weight:700">৳' + (u.price || 0) + '</div></div>';
                    html += '<button type="button" onclick="cfUpsellRemove(' + ui + ')" style="width:24px;height:24px;border:none;background:#fee2e2;border-radius:6px;color:#ef4444;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center"><i class="fas fa-times"></i></button>';
                    html += '</div>';
                });
            }
            html += '</div>';
            html += '</div>';
        }
        
        html += '<div style="margin-top:6px;font-size:10px;color:#94a3b8">Field key: <code style="background:#f1f5f9;padding:1px 4px;border-radius:3px">' + f.key + '</code> · Type: <code style="background:#f1f5f9;padding:1px 4px;border-radius:3px">' + (f.type || 'text') + '</code></div>';
        html += '</div>';

        html += '</div>';
    });
    list.innerHTML = html;

    // Wire up change events
    list.querySelectorAll('.cf-show-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            const field = this.closest('.cf-field');
            field.classList.toggle('cf-off', !this.checked);
            _cfReadFromDom();
            _cfRenderPreview();
        });
    });
    list.querySelectorAll('.cf-req-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            _cfReadFromDom();
            _cfRenderPreview();
        });
    });
    list.querySelectorAll('.cf-d-label, .cf-d-ph').forEach(function(inp) {
        inp.addEventListener('input', function() {
            const field = this.closest('.cf-field');
            if (this.classList.contains('cf-d-label')) {
                field.querySelector('.cf-label').textContent = this.value;
            }
            _cfReadFromDom();
            _cfRenderPreview();
        });
    });
}

function cfToggleDetail(btn) {
    const field = btn.closest('.cf-field');
    const detail = field.querySelector('.cf-detail');
    const icon = btn.querySelector('i');
    const isOpen = detail.classList.contains('open');
    // Close all others (accordion)
    document.querySelectorAll('#lpCfFieldList .cf-detail.open').forEach(function(d) {
        d.classList.remove('open');
        const bi = d.closest('.cf-field').querySelector('.cf-expand-btn i');
        if (bi) { bi.classList.remove('fa-chevron-up'); bi.classList.add('fa-chevron-down'); }
    });
    if (!isOpen) {
        detail.classList.add('open');
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
    }
}

function _cfReadFromDom() {
    const list = document.getElementById('lpCfFieldList');
    if (!list) return;
    const newFields = [];
    list.querySelectorAll('.cf-field').forEach(function(el) {
        const key = el.dataset.key;
        const orig = _cfFields.find(function(f){ return f.key === key; });
        if (!orig) return;
        const f = JSON.parse(JSON.stringify(orig));
        f.enabled = el.querySelector('.cf-show-cb')?.checked ?? true;
        const reqCb = el.querySelector('.cf-req-cb');
        if (reqCb) f.required = reqCb.checked;
        const labelInp = el.querySelector('.cf-d-label');
        if (labelInp) f.label = labelInp.value;
        const phInp = el.querySelector('.cf-d-ph');
        if (phInp) f.placeholder = phInp.value;
        newFields.push(f);
    });
    _cfFields = newFields;
}

function _cfRenderPreview() {
    const prev = document.getElementById('lpCfPreview');
    if (!prev) return;
    // Get delivery charges from settings panel
    const dDhk = parseInt(document.getElementById('settDelDhaka')?.value) || 70;
    const dSub = parseInt(document.getElementById('settDelSub')?.value) || 100;
    const dOut = parseInt(document.getElementById('settDelOut')?.value) || 130;
    const btnColor = document.getElementById('settOrderBtnColor')?.value || '#ef4444';
    const btnText = document.getElementById('settOrderButton')?.value || 'ক্যাশ অন ডেলিভারিতে অর্ডার করুন';

    let html = '';
    _cfFields.forEach(function(f) {
        if (f.enabled === false) return;
        const req = f.required ? ' <span style="color:#ef4444">*</span>' : '';
        const lbl = esc(f.label || f.key);
        const ph = esc(f.placeholder || '');

        switch (f.key) {
            case 'product_selector':
                html += '<div style="margin-bottom:14px"><span class="cfp-label">' + lbl + '</span>';
                html += '<div style="border:1.5px solid ' + btnColor + ';border-radius:10px;padding:10px;display:flex;align-items:center;gap:10px;background:' + btnColor + '08">';
                html += '<div style="width:48px;height:48px;background:#e2e8f0;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center"><i class="fas fa-image" style="color:#94a3b8"></i></div>';
                html += '<div style="flex:1;min-width:0"><div style="font-weight:700;font-size:13px">প্রোডাক্ট নাম</div><div style="color:' + btnColor + ';font-weight:800;font-size:15px">৳980</div></div>';
                html += '<div style="display:flex;align-items:center;gap:4px;border:1px solid #e5e7eb;border-radius:8px;padding:2px 4px;background:#fff"><button disabled style="width:24px;height:24px;border:none;background:#f1f5f9;border-radius:5px;font-size:13px;color:#6b7280">−</button><span style="width:24px;text-align:center;font-size:13px;font-weight:600">1</span><button disabled style="width:24px;height:24px;border:none;background:#f1f5f9;border-radius:5px;font-size:13px;color:#6b7280">+</button></div>';
                html += '</div>';
                html += '<div style="margin-top:6px;border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;display:flex;align-items:center;gap:10px;opacity:.6">';
                html += '<div style="width:36px;height:36px;background:#f1f5f9;border-radius:6px;flex-shrink:0"></div>';
                html += '<div style="flex:1"><div style="font-size:12px;color:#6b7280">অন্য প্রোডাক্ট</div><div style="font-size:12px;color:#94a3b8">৳750</div></div>';
                html += '<div style="width:16px;height:16px;border:1.5px solid #cbd5e1;border-radius:50%"></div>';
                html += '</div></div>';
                break;
            case 'lp_upsells':
                html += '<div style="margin-bottom:14px"><span class="cfp-label"><i class="fas fa-fire" style="color:#f97316;margin-right:4px"></i>' + lbl + '</span>';
                html += '<div style="background:#fff7ed;border:1px dashed #fed7aa;border-radius:10px;padding:10px">';
                var ups = pageSettings.lp_upsell_products || [];
                if (ups.length > 0) {
                    ups.forEach(function(u, ui) {
                        html += '<div style="display:flex;align-items:center;gap:8px;' + (ui > 0 ? 'margin-top:6px;' : '') + '"><div style="width:36px;height:36px;background:#ffedd5;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center"><i class="fas fa-box" style="color:#f97316;font-size:11px"></i></div><div style="flex:1"><div style="font-size:11px;font-weight:600">' + esc(u.name) + '</div><div style="font-size:11px;color:#f97316;font-weight:700">৳' + (u.price || 0) + '</div></div><div style="width:28px;height:28px;border:1.5px solid #e5e7eb;border-radius:6px;display:flex;align-items:center;justify-content:center;background:#fff"><i class="fas fa-plus" style="color:#cbd5e1;font-size:10px"></i></div></div>';
                    });
                } else {
                    html += '<div style="text-align:center;padding:8px;font-size:11px;color:#f97316">Auto-suggest from product database</div>';
                }
                html += '</div></div>';
                break;
            case 'name':
                html += '<div style="margin-bottom:14px"><span class="cfp-label">' + lbl + req + '</span><input class="cfp-input" placeholder="' + ph + '" disabled></div>';
                break;
            case 'phone':
                html += '<div style="margin-bottom:14px"><span class="cfp-label">' + lbl + req + '</span><input class="cfp-input" placeholder="' + ph + '" disabled></div>';
                break;
            case 'email':
                html += '<div style="margin-bottom:14px"><span class="cfp-label">' + lbl + req + '</span><input class="cfp-input" placeholder="' + ph + '" disabled></div>';
                break;
            case 'address':
                html += '<div style="margin-bottom:14px"><span class="cfp-label">' + lbl + req + '</span><textarea class="cfp-input" rows="2" placeholder="' + ph + '" disabled style="resize:vertical"></textarea></div>';
                break;
            case 'shipping_area':
                html += '<div style="margin-bottom:14px"><span class="cfp-label">' + lbl + req + '</span>';
                html += '<div class="cfp-area">';
                html += '<label><div style="font-weight:600">ঢাকার ভিতরে</div><div style="color:#94a3b8">৳' + dDhk + '</div></label>';
                html += '<label><div style="font-weight:600">ঢাকা উপশহর</div><div style="color:#94a3b8">৳' + dSub + '</div></label>';
                html += '<label class="active"><div style="font-weight:600">ঢাকার বাইরে</div><div style="color:#94a3b8">৳' + dOut + '</div></label>';
                html += '</div></div>';
                break;
            case 'notes':
                html += '<div style="margin-bottom:14px"><span class="cfp-label">' + lbl + req + '</span><input class="cfp-input" placeholder="' + ph + '" disabled></div>';
                break;
        }
    });
    // Order total preview
    html += '<div style="background:#f8fafc;border-radius:10px;padding:12px 16px;margin-bottom:14px">';
    html += '<div style="display:flex;justify-content:space-between;font-size:12px;color:#6b7280;margin-bottom:4px"><span>সাবটোটাল</span><span>৳980</span></div>';
    html += '<div style="display:flex;justify-content:space-between;font-size:12px;color:#6b7280;margin-bottom:4px"><span>ডেলিভারি</span><span>৳' + dOut + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;font-size:15px;font-weight:700;border-top:1px solid #e5e7eb;padding-top:8px;margin-top:4px"><span>মোট</span><span style="color:#ef4444">৳' + (980 + dOut) + '</span></div>';
    html += '</div>';
    // Button
    html += '<button disabled style="width:100%;padding:14px;border:none;border-radius:12px;font-size:15px;font-weight:700;color:#fff;background:' + btnColor + ';opacity:.85;cursor:default">' + esc(btnText) + '</button>';
    html += '<p style="text-align:center;font-size:11px;color:#94a3b8;margin-top:6px">নাম দিন, সঠিক মোবাইল নম্বর দিন, ঠিকানা দিন</p>';

    prev.innerHTML = html;
}

// ═══ UPSELL PRODUCT PICKER ═══
var _cfUpSearchTimer = null;

function cfUpsellSearch() {
    // Show inline search within the modal
    var container = document.getElementById('cfUpsellProducts');
    if (!container) return;
    
    // Check if search already open
    if (document.getElementById('cfUpSearchBox')) { document.getElementById('cfUpSearchInput').focus(); return; }
    
    var searchHtml = '<div id="cfUpSearchBox" style="border:2px solid #f97316;border-radius:10px;overflow:hidden;margin-bottom:4px">';
    searchHtml += '<input id="cfUpSearchInput" type="text" placeholder="🔍 পণ্য খুঁজুন..." style="width:100%;padding:8px 12px;border:none;font-size:12px;outline:none" oninput="cfUpDoSearch(this.value)">';
    searchHtml += '<div id="cfUpSearchResults" style="max-height:200px;overflow-y:auto;border-top:1px solid #e5e7eb"></div>';
    searchHtml += '</div>';
    container.insertAdjacentHTML('beforebegin', searchHtml);
    document.getElementById('cfUpSearchInput').focus();
    cfUpDoSearch(''); // Load initial results
}

function cfUpDoSearch(q) {
    clearTimeout(_cfUpSearchTimer);
    _cfUpSearchTimer = setTimeout(function() {
        fetch(API + '?action=search_products&q=' + encodeURIComponent(q) + '&limit=10')
        .then(function(r){ return r.json(); })
        .then(function(d) {
            var el = document.getElementById('cfUpSearchResults');
            if (!el) return;
            if (!d.success || !d.data.length) {
                el.innerHTML = '<div style="text-align:center;padding:16px;font-size:11px;color:#9ca3af">কোনো পণ্য পাওয়া যায়নি</div>';
                return;
            }
            var existing = (pageSettings.lp_upsell_products || []).map(function(u){ return u.id; });
            el.innerHTML = d.data.map(function(p) {
                var added = existing.indexOf(p.id) >= 0;
                return '<div class="cf-up-result" data-pid="' + p.id + '" data-pname="' + esc(p.name_bn || p.name) + '" data-pprice="' + (p.price || 0) + '" data-pimg="' + esc(p.image_url || '') + '" style="display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:' + (added ? 'default' : 'pointer') + ';transition:all .15s;opacity:' + (added ? '.5' : '1') + '">' +
                    '<div style="width:36px;height:36px;border-radius:6px;overflow:hidden;background:#f1f5f9;flex-shrink:0">' +
                        (p.image_url ? '<img src="' + p.image_url + '" style="width:100%;height:100%;object-fit:cover">' : '<div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:14px;color:#ccc">📦</div>') +
                    '</div>' +
                    '<div style="flex:1;min-width:0">' +
                        '<div style="font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(p.name_bn || p.name) + '</div>' +
                        '<div style="font-size:10px;color:#6b7280">' + (p.category_name || '') + '</div>' +
                    '</div>' +
                    '<div style="font-size:12px;font-weight:700;color:' + (added ? '#94a3b8' : '#f97316') + ';flex-shrink:0">' + (added ? '✓ Added' : '৳' + Number(p.price).toLocaleString()) + '</div>' +
                '</div>';
            }).join('');
            // Attach click handlers via delegation
            el.querySelectorAll('.cf-up-result').forEach(function(row) {
                if (row.style.opacity === '.5') return; // already added
                row.addEventListener('click', function() {
                    cfUpsellAdd(parseInt(this.dataset.pid), this.dataset.pname, parseFloat(this.dataset.pprice), this.dataset.pimg);
                });
                row.addEventListener('mouseover', function() { this.style.background = '#fff7ed'; });
                row.addEventListener('mouseout', function() { this.style.background = ''; });
            });
        }).catch(function(){});
    }, 300);
}

function cfUpsellAdd(id, name, price, image) {
    if (!pageSettings.lp_upsell_products) pageSettings.lp_upsell_products = [];
    // Check duplicate
    for (var i = 0; i < pageSettings.lp_upsell_products.length; i++) {
        if (pageSettings.lp_upsell_products[i].id === id) return;
    }
    pageSettings.lp_upsell_products.push({id: id, name: name, price: price, image: image});
    // Remove search box
    var sb = document.getElementById('cfUpSearchBox');
    if (sb) sb.remove();
    // Re-render
    _cfRenderList();
    _cfRenderPreview();
    _cfInitSortable();
    // Auto-expand the lp_upsells detail
    setTimeout(function() {
        var upField = document.querySelector('.cf-field[data-key="lp_upsells"]');
        if (upField) {
            var detail = upField.querySelector('.cf-detail');
            if (detail && !detail.classList.contains('open')) {
                var btn = upField.querySelector('.cf-expand-btn');
                if (btn) cfToggleDetail(btn);
            }
        }
    }, 100);
    showToast('✓ Upsell added: ' + name, 'orange');
}

function cfUpsellRemove(idx) {
    if (!pageSettings.lp_upsell_products) return;
    var removed = pageSettings.lp_upsell_products.splice(idx, 1);
    _cfRenderList();
    _cfRenderPreview();
    _cfInitSortable();
    setTimeout(function() {
        var upField = document.querySelector('.cf-field[data-key="lp_upsells"]');
        if (upField) {
            var detail = upField.querySelector('.cf-detail');
            if (detail && !detail.classList.contains('open')) {
                var btn = upField.querySelector('.cf-expand-btn');
                if (btn) cfToggleDetail(btn);
            }
        }
    }, 100);
    if (removed.length) showToast('Upsell removed', 'yellow');
}

// Close modal on outside click
document.getElementById('lpCfModal')?.addEventListener('click', function(e) {
    if (e.target === this) lpCfClose();
});

// ═══ UPSELL PRODUCT PICKER ═══
function lpUpRender() {
    const list = document.getElementById('lpUpList');
    if (!list) return;
    const ups = pageSettings.lp_upsell_products || [];
    if (!pageSettings._lp_upsell_data) pageSettings._lp_upsell_data = {};
    const upData = pageSettings._lp_upsell_data;
    if (!ups.length) {
        list.innerHTML = '<div style="text-align:center;padding:8px;font-size:10px;color:#94a3b8;background:#f8fafc;border-radius:8px;border:1px dashed #e5e7eb">Auto-suggest (based on selected product)</div>';
        return;
    }
    // Check if we need to fetch product data
    var needFetch = ups.filter(function(id) { return !upData[id]; });
    if (needFetch.length > 0) {
        fetch(API + '?action=search_products&q=&limit=50')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            (d.data || []).forEach(function(p) {
                if (ups.indexOf(p.id) >= 0 && !upData[p.id]) {
                    upData[p.id] = { name: p.name_bn || p.name, price: p.price, image: p.image_url || '' };
                }
            });
            _lpUpRenderList(list, ups, upData);
        })
        .catch(function() { _lpUpRenderList(list, ups, upData); });
    } else {
        _lpUpRenderList(list, ups, upData);
    }
}

function _lpUpRenderList(list, ups, upData) {
    if (!ups.length) {
        list.innerHTML = '<div style="text-align:center;padding:8px;font-size:10px;color:#94a3b8;background:#f8fafc;border-radius:8px;border:1px dashed #e5e7eb">Auto-suggest (based on selected product)</div>';
        return;
    }
    let html = '';
    ups.forEach(function(pid, i) {
        const d = upData[pid] || {};
        html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 8px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;font-size:11px">';
        if (d.image) html += '<img src="' + esc(d.image) + '" style="width:28px;height:28px;border-radius:6px;object-fit:cover;flex-shrink:0">';
        else html += '<div style="width:28px;height:28px;background:#f1f5f9;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center"><i class="fas fa-box" style="color:#94a3b8;font-size:9px"></i></div>';
        html += '<div style="flex:1;min-width:0"><div style="font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(d.name || 'Product #' + pid) + '</div>';
        if (d.price) html += '<div style="font-size:10px;color:#f97316;font-weight:700">৳' + Math.round(d.price) + '</div>';
        html += '</div>';
        html += '<button onclick="lpUpRemove(' + i + ')" style="width:22px;height:22px;border:none;background:#fee2e2;border-radius:5px;cursor:pointer;color:#ef4444;font-size:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0" title="Remove"><i class="fas fa-times"></i></button>';
        html += '</div>';
    });
    list.innerHTML = html;
}

function lpUpRemove(idx) {
    const ups = pageSettings.lp_upsell_products || [];
    const removed = ups[idx];
    ups.splice(idx, 1);
    pageSettings.lp_upsell_products = ups;
    if (removed && pageSettings._lp_upsell_data) delete pageSettings._lp_upsell_data[removed];
    lpUpRender();
}

function lpUpPickerOpen() {
    // Reuse the existing product picker modal
    let modal = document.getElementById('upsellPickerModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'upsellPickerModal';
        modal.style.cssText = 'position:fixed;inset:0;z-index:10001;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)';
        modal.onclick = function(e) { if (e.target === modal) lpUpPickerClose(); };
        modal.innerHTML = `
            <div style="background:white;border-radius:16px;width:90%;max-width:500px;max-height:80vh;display:flex;flex-direction:column;overflow:hidden" onclick="event.stopPropagation()">
                <div style="padding:16px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:10px">
                    <div style="flex:1;position:relative">
                        <input type="text" id="upSearchInput" placeholder="🔍 পণ্য খুঁজুন (আপসেলের জন্য)..." style="width:100%;padding:10px 14px;border:2px solid #f97316;border-radius:10px;font-size:14px;outline:none" oninput="lpUpSearch(this.value)">
                    </div>
                    <button onclick="lpUpPickerClose()" style="width:32px;height:32px;border:none;background:#f3f4f6;border-radius:50%;cursor:pointer;font-size:16px">✕</button>
                </div>
                <div id="upSearchResults" style="flex:1;overflow-y:auto;padding:12px;min-height:200px">
                    <div style="text-align:center;color:#9ca3af;padding:40px 0;font-size:13px">Search for products to add as upsells</div>
                </div>
            </div>`;
        document.body.appendChild(modal);
    }
    modal.style.display = 'flex';
    document.getElementById('upSearchInput').value = '';
    lpUpSearch('');
    setTimeout(function() { document.getElementById('upSearchInput').focus(); }, 100);
}

function lpUpPickerClose() {
    const m = document.getElementById('upsellPickerModal');
    if (m) m.style.display = 'none';
}

let _upSearchTimer = null;
function lpUpSearch(q) {
    clearTimeout(_upSearchTimer);
    _upSearchTimer = setTimeout(function() {
        fetch(API + '?action=search_products&q=' + encodeURIComponent(q) + '&limit=15')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            const el = document.getElementById('upSearchResults');
            if (!d.success || !d.data.length) {
                el.innerHTML = '<div style="text-align:center;color:#9ca3af;padding:40px 0;font-size:13px">কোনো পণ্য পাওয়া যায়নি</div>';
                return;
            }
            const existing = pageSettings.lp_upsell_products || [];
            el.innerHTML = d.data.map(function(p) {
                const already = existing.indexOf(p.id) >= 0;
                return '<div onclick="' + (already ? '' : 'lpUpSelect(' + p.id + ',this)') + '" style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid ' + (already ? '#bbf7d0' : '#e5e7eb') + ';border-radius:10px;margin-bottom:6px;cursor:' + (already ? 'default' : 'pointer') + ';transition:all .15s;background:' + (already ? '#f0fdf4' : 'white') + '" ' + (already ? '' : 'onmouseover="this.style.background=\'#fff7ed\';this.style.borderColor=\'#fed7aa\'" onmouseout="this.style.background=\'\';this.style.borderColor=\'#e5e7eb\'"') + '>' +
                    '<div style="width:48px;height:48px;border-radius:8px;overflow:hidden;background:#f1f5f9;flex-shrink:0">' +
                        (p.image_url ? '<img src="' + esc(p.image_url) + '" style="width:100%;height:100%;object-fit:cover">' : '<div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:18px;color:#ccc">📦</div>') +
                    '</div>' +
                    '<div style="flex:1;min-width:0">' +
                        '<div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(p.name_bn || p.name) + '</div>' +
                        '<div style="font-size:11px;color:#6b7280">' + (p.category_name || '') + (p.sku ? ' · ' + p.sku : '') + '</div>' +
                    '</div>' +
                    '<div style="text-align:right;flex-shrink:0">' +
                        '<div style="font-size:14px;font-weight:800;color:#f97316">৳' + Number(p.price).toLocaleString() + '</div>' +
                        (already ? '<div style="font-size:10px;color:#22c55e;font-weight:600">✓ Added</div>' : '') +
                    '</div>' +
                '</div>';
            }).join('');
        }).catch(function() {});
    }, 300);
}

function lpUpSelect(productId, el) {
    if (!pageSettings.lp_upsell_products) pageSettings.lp_upsell_products = [];
    if (!pageSettings._lp_upsell_data) pageSettings._lp_upsell_data = {};
    if (pageSettings.lp_upsell_products.indexOf(productId) >= 0) return;
    
    // Get product info from search results
    fetch(API + '?action=search_products&q=&limit=50')
    .then(function(r) { return r.json(); })
    .then(function(d) {
        const p = (d.data || []).find(function(x) { return x.id == productId; });
        if (p) {
            pageSettings._lp_upsell_data[productId] = {
                name: p.name_bn || p.name,
                price: p.price,
                image: p.image_url || ''
            };
        }
        pageSettings.lp_upsell_products.push(productId);
        lpUpRender();
        lpUpPickerClose();
        showToast('✓ Upsell added: ' + (p ? (p.name_bn || p.name) : 'Product #' + productId), 'orange');
    });
}

// ═══ INIT ═══
applyGlobalSettings();
renderSections();
if ('<?= $activeTab ?>' === 'templates') loadTemplates();
if ('<?= $activeTab ?>' === 'analytics') loadAnalytics();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
