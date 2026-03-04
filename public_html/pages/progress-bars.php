<?php
/**
 * Admin: Checkout Progress Bar Management
 * v2 — Radio selection, color picker, height control, tier delete
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Progress Bar Offers';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// Auto-create table if missing
try {
    $db->query("CREATE TABLE IF NOT EXISTS checkout_progress_bars (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL,
        template TINYINT DEFAULT 1, tiers JSON DEFAULT NULL, config JSON DEFAULT NULL,
        is_active TINYINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\Throwable $e) {}

// Ensure config column exists (safe for old tables)
try { $db->query("SELECT config FROM checkout_progress_bars LIMIT 0"); } catch (\Throwable $e) {
    try { $db->query("ALTER TABLE checkout_progress_bars ADD COLUMN config JSON DEFAULT NULL AFTER tiers"); } catch (\Throwable $e2) {}
}

// Load all bars
$bars = $db->fetchAll("SELECT * FROM checkout_progress_bars ORDER BY is_active DESC, id DESC");
foreach ($bars as &$b) {
    $b['tiers'] = json_decode($b['tiers'] ?? '[]', true) ?: [];
    $b['config'] = json_decode($b['config'] ?? '{}', true) ?: [];
}
unset($b);

// Check enabled from checkout fields (single source of truth)
$enabled = false;
$_cfJson = getSetting('checkout_fields', '');
$_cfArr = $_cfJson ? json_decode($_cfJson, true) : null;
if ($_cfArr && is_array($_cfArr)) {
    foreach ($_cfArr as $_cf) {
        if (($_cf['key'] ?? '') === 'progress_bar') { $enabled = !empty($_cf['enabled']); break; }
    }
} else {
    // No saved fields yet — check legacy setting
    $enabled = getSetting('progress_bar_enabled', '0') === '1';
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.tpl-card{border:2px solid transparent;cursor:pointer;transition:all .15s}
.tpl-card.selected{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.2)}
.tpl-card:hover{border-color:#93c5fd}
.tier-row{animation:fadeIn .2s}
@keyframes fadeIn{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:none}}
.pb-tpl-1 .pb-track{background:#f3f4f6;border-radius:9999px;height:10px;position:relative;overflow:hidden}
.pb-tpl-1 .pb-fill{background:linear-gradient(90deg,#ef4444,#f97316,#22c55e);height:100%;border-radius:9999px;transition:width .5s ease}
.pb-tpl-2 .pb-step{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #e5e7eb;background:#fff;transition:all .3s}
.pb-tpl-2 .pb-step.active{border-color:#22c55e;background:#dcfce7}
.pb-tpl-2 .pb-step.unlocked{border-color:#22c55e;background:#22c55e;color:#fff}
.pb-tpl-3 .pb-track{background:#1e1b4b;border-radius:12px;height:14px;position:relative;overflow:hidden}
.pb-tpl-3 .pb-fill{background:linear-gradient(90deg,#7c3aed,#ec4899,#f59e0b);height:100%;border-radius:12px;transition:width .5s ease;box-shadow:0 0 12px rgba(168,85,247,.4)}
.pb-tpl-4 .pb-track{background:#fef9c3;border-radius:9999px;height:12px;position:relative;overflow:hidden;border:1px solid #fde047}
.pb-tpl-4 .pb-fill{background:linear-gradient(90deg,#facc15,#fb923c,#f43f5e);height:100%;border-radius:9999px;transition:width .5s ease}
.pb-tpl-5 .pb-track{background:#f9fafb;height:4px;position:relative}
.pb-tpl-5 .pb-fill{background:#111827;height:100%;transition:width .5s ease}
.color-swatch{width:32px;height:32px;border-radius:8px;border:2px solid #e5e7eb;cursor:pointer;padding:0;overflow:hidden}
.color-swatch::-webkit-color-swatch-wrapper{padding:0}
.color-swatch::-webkit-color-swatch{border:none;border-radius:6px}
.radio-active{position:relative}
.radio-active::after{content:'';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:10px;height:10px;border-radius:50%;background:#22c55e}
</style>

<!-- Toggle & Status -->
<div class="bg-white rounded-xl border p-5 mb-6 flex items-center justify-between flex-wrap gap-4">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-orange-400 to-pink-500 flex items-center justify-center text-white text-lg">🎁</div>
        <div>
            <h2 class="font-bold text-gray-800">Checkout Progress Bar</h2>
            <p class="text-xs text-gray-400">Motivate customers to add more with reward milestones</p>
        </div>
    </div>
    <div class="flex items-center gap-4">
        <span class="text-xs font-medium <?= $enabled ? 'text-green-600' : 'text-gray-400' ?>"><?= $enabled ? '● Enabled' : '○ Disabled' ?></span>
        <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" id="pb-toggle" <?= $enabled ? 'checked' : '' ?> onchange="toggleEnabled(this.checked)" class="sr-only peer">
            <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
        </label>
    </div>
</div>

<div class="grid lg:grid-cols-5 gap-6">
    <!-- Left: Bar List + Editor -->
    <div class="lg:col-span-3 space-y-4">
        
        <!-- All Progress Bars — Radio Selection -->
        <div class="bg-white rounded-xl border overflow-hidden">
            <div class="px-5 py-3 bg-gray-50 border-b flex items-center justify-between">
                <h3 class="font-semibold text-sm"><i class="fas fa-list mr-1.5 text-blue-500"></i>Progress Bars <span class="text-xs text-gray-400 font-normal ml-1">(select one active)</span></h3>
                <button onclick="showEditor()" class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-blue-700"><i class="fas fa-plus mr-1"></i>Create New</button>
            </div>
            <?php if (empty($bars)): ?>
            <div class="p-8 text-center text-gray-400 text-sm">No progress bars yet. Create one to get started!</div>
            <?php else: ?>
            <form id="active-bar-form">
            <div class="divide-y" id="bar-list">
                <?php foreach ($bars as $bar): ?>
                <div class="px-5 py-3.5 flex items-center gap-4 hover:bg-gray-50 transition" data-bar-id="<?= $bar['id'] ?>">
                    <!-- Radio for active selection -->
                    <label class="flex-shrink-0 cursor-pointer">
                        <input type="radio" name="active_bar" value="<?= $bar['id'] ?>" <?= $bar['is_active'] ? 'checked' : '' ?> onchange="saveActiveBar(<?= $bar['id'] ?>)" class="w-4 h-4 text-green-600 border-gray-300 focus:ring-green-500 cursor-pointer">
                    </label>
                    <div class="w-9 h-9 rounded-lg <?= $bar['is_active'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400' ?> flex items-center justify-center text-lg flex-shrink-0">
                        <?= ($bar['tiers'][0]['icon'] ?? '🎁') ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-sm"><?= e($bar['name']) ?></span>
                            <?php if ($bar['is_active']): ?><span class="text-[10px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full font-medium">ACTIVE</span><?php endif; ?>
                            <span class="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">Template <?= $bar['template'] ?></span>
                            <?php $shrk = intval($bar['config']['shrink'] ?? 0); if ($shrk > 0): ?>
                            <span class="text-[10px] bg-purple-50 text-purple-500 px-1.5 py-0.5 rounded">Shrink <?= $shrk ?>%</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <?php foreach ($bar['tiers'] as $t): ?>
                            <span class="text-[10px] text-gray-400"><?= $t['icon'] ?> ৳<?= number_format($t['min_amount']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="flex gap-1.5 shrink-0">
                        <button type="button" onclick='showEditor(<?= json_encode($bar, JSON_UNESCAPED_UNICODE) ?>)' class="px-2.5 py-1.5 bg-blue-50 text-blue-600 rounded-lg text-xs font-medium hover:bg-blue-100" title="Edit"><i class="fas fa-edit"></i></button>
                        <button type="button" onclick="deleteBar(<?= $bar['id'] ?>)" class="px-2.5 py-1.5 bg-red-50 text-red-600 rounded-lg text-xs font-medium hover:bg-red-100" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            </form>
            <div class="px-5 py-2.5 bg-gray-50 border-t">
                <p class="text-[10px] text-gray-400"><i class="fas fa-info-circle mr-1"></i>Select the radio button to set which progress bar is active. Only one can be active at a time.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Editor (hidden by default) -->
        <div id="bar-editor" class="bg-white rounded-xl border overflow-hidden hidden">
            <div class="px-5 py-3 bg-blue-50 border-b flex items-center justify-between">
                <h3 class="font-semibold text-sm text-blue-800"><i class="fas fa-edit mr-1.5"></i><span id="editor-title">Create Progress Bar</span></h3>
                <button onclick="hideEditor()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-5 space-y-5">
                <input type="hidden" id="edit-id" value="0">
                
                <!-- Name -->
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Name</label>
                    <input type="text" id="edit-name" placeholder="e.g. Summer Sale Offer" class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm outline-none focus:border-blue-400">
                </div>
                
                <!-- Template Selector -->
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">Template Design</label>
                    <div class="grid grid-cols-6 gap-2" id="template-selector">
                        <div class="tpl-card selected rounded-lg border p-2 text-center" data-tpl="1" onclick="selectTemplate(1)">
                            <div class="text-xl mb-1">📊</div>
                            <div class="text-[10px] font-medium text-gray-600">Classic</div>
                        </div>
                        <div class="tpl-card rounded-lg border p-2 text-center" data-tpl="2" onclick="selectTemplate(2)">
                            <div class="text-xl mb-1">⚡</div>
                            <div class="text-[10px] font-medium text-gray-600">Steps</div>
                        </div>
                        <div class="tpl-card rounded-lg border p-2 text-center" data-tpl="3" onclick="selectTemplate(3)">
                            <div class="text-xl mb-1">🌈</div>
                            <div class="text-[10px] font-medium text-gray-600">Gradient</div>
                        </div>
                        <div class="tpl-card rounded-lg border p-2 text-center" data-tpl="4" onclick="selectTemplate(4)">
                            <div class="text-xl mb-1">🎊</div>
                            <div class="text-[10px] font-medium text-gray-600">Festive</div>
                        </div>
                        <div class="tpl-card rounded-lg border p-2 text-center" data-tpl="5" onclick="selectTemplate(5)">
                            <div class="text-xl mb-1">✨</div>
                            <div class="text-[10px] font-medium text-gray-600">Minimal</div>
                        </div>
                        <div class="tpl-card rounded-lg border p-2 text-center" data-tpl="6" onclick="selectTemplate(6)">
                            <div class="text-xl mb-1">🏆</div>
                            <div class="text-[10px] font-medium text-gray-600">Dark Track</div>
                        </div>
                    </div>
                </div>
                
                <!-- Height & Colors -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Vertical Shrink <span id="shrink-value" class="text-blue-600 font-bold">0%</span></label>
                        <input type="range" id="edit-shrink" min="0" max="50" value="0" step="5" oninput="document.getElementById('shrink-value').textContent=this.value+'%';updatePreview()" class="w-full accent-blue-600">
                        <div class="flex justify-between text-[9px] text-gray-400 mt-0.5"><span>Normal</span><span>Max shrink</span></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Track Background</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="edit-color-bg" value="#f3f4f6" class="color-swatch" onchange="updatePreview()">
                            <input type="text" id="edit-color-bg-hex" value="#f3f4f6" class="flex-1 border rounded-lg px-2 py-2 text-xs font-mono" maxlength="7" oninput="syncColor(this,'edit-color-bg');updatePreview()">
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Fill Color (Start)</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="edit-color-from" value="#ef4444" class="color-swatch" onchange="document.getElementById('edit-color-from-hex').value=this.value;updatePreview()">
                            <input type="text" id="edit-color-from-hex" value="#ef4444" class="flex-1 border rounded-lg px-2 py-2 text-xs font-mono" maxlength="7" oninput="syncColor(this,'edit-color-from');updatePreview()">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Fill Color (End)</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="edit-color-to" value="#22c55e" class="color-swatch" onchange="document.getElementById('edit-color-to-hex').value=this.value;updatePreview()">
                            <input type="text" id="edit-color-to-hex" value="#22c55e" class="flex-1 border rounded-lg px-2 py-2 text-xs font-mono" maxlength="7" oninput="syncColor(this,'edit-color-to');updatePreview()">
                        </div>
                    </div>
                </div>
                <button type="button" onclick="resetColors()" class="text-xs text-blue-500 hover:underline"><i class="fas fa-undo mr-1"></i>Reset to template defaults</button>
                
                <!-- Tiers -->
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">Reward Tiers</label>
                    <div id="tiers-container" class="space-y-3"></div>
                    <button onclick="addTier()" class="mt-3 bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2 rounded-lg text-xs font-medium transition w-full">
                        <i class="fas fa-plus mr-1"></i> Add Tier
                    </button>
                </div>
                
                <!-- Save -->
                <div class="flex gap-2 pt-2">
                    <button onclick="saveBar()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition flex-1">
                        <i class="fas fa-save mr-1"></i> Save Progress Bar
                    </button>
                    <button onclick="hideEditor()" class="bg-gray-100 text-gray-600 px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Right: Live Preview -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border sticky top-24 overflow-hidden">
            <div class="px-5 py-3 bg-gray-50 border-b">
                <h3 class="font-semibold text-sm"><i class="fas fa-eye mr-1.5 text-purple-500"></i>Preview</h3>
            </div>
            <div class="p-5">
                <div class="mb-4">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Simulate Cart Total: <span id="preview-amount-label" class="text-blue-600 font-bold">৳750</span></label>
                    <input type="range" id="preview-slider" min="0" max="5000" value="750" step="50" oninput="updatePreview()" class="w-full accent-blue-600">
                </div>
                <div class="bg-gray-50 rounded-xl p-4 border-2 border-dashed border-gray-200" id="preview-container">
                    <p class="text-xs text-gray-400 text-center">Select a progress bar to preview</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const API = '<?= SITE_URL ?>/api/progress-bar.php';
let currentTemplate = 1;
let tierCount = 0;

// Default colors per template
const TPL_DEFAULTS = {
    1: {bg:'#f3f4f6', from:'#ef4444', to:'#22c55e'},
    2: {bg:'#e5e7eb', from:'#22c55e', to:'#22c55e'},
    3: {bg:'#1e1b4b', from:'#7c3aed', to:'#f59e0b'},
    4: {bg:'#fef9c3', from:'#facc15', to:'#f43f5e'},
    5: {bg:'#f9fafb', from:'#111827', to:'#111827'},
    6: {bg:'#374151', from:'#f59e0b', to:'#ef4444'},
};

function syncColor(hexInput, colorId) {
    const v = hexInput.value;
    if (/^#[0-9a-fA-F]{6}$/.test(v)) document.getElementById(colorId).value = v;
}

function getConfig() {
    return {
        shrink: parseInt(document.getElementById('edit-shrink').value) || 0,
        color_fill_from: document.getElementById('edit-color-from').value,
        color_fill_to: document.getElementById('edit-color-to').value,
        color_track_bg: document.getElementById('edit-color-bg').value,
    };
}

function setConfig(cfg) {
    const c = cfg || {};
    const shrink = c.shrink ?? (c.height === 'slim' ? 40 : c.height === 'compact' ? 25 : 0);
    document.getElementById('edit-shrink').value = shrink;
    document.getElementById('shrink-value').textContent = shrink + '%';
    const defaults = TPL_DEFAULTS[currentTemplate] || TPL_DEFAULTS[1];
    const bg = c.color_track_bg || defaults.bg;
    const from = c.color_fill_from || defaults.from;
    const to = c.color_fill_to || defaults.to;
    document.getElementById('edit-color-bg').value = bg;
    document.getElementById('edit-color-bg-hex').value = bg;
    document.getElementById('edit-color-from').value = from;
    document.getElementById('edit-color-from-hex').value = from;
    document.getElementById('edit-color-to').value = to;
    document.getElementById('edit-color-to-hex').value = to;
}

function resetColors() {
    const d = TPL_DEFAULTS[currentTemplate] || TPL_DEFAULTS[1];
    setConfig({shrink: parseInt(document.getElementById('edit-shrink').value) || 0, color_track_bg: d.bg, color_fill_from: d.from, color_fill_to: d.to});
    updatePreview();
}

function selectTemplate(n) {
    currentTemplate = n;
    document.querySelectorAll('.tpl-card').forEach(c => c.classList.remove('selected'));
    document.querySelector(`.tpl-card[data-tpl="${n}"]`)?.classList.add('selected');
    const d = TPL_DEFAULTS[n] || TPL_DEFAULTS[1];
    setConfig({shrink: parseInt(document.getElementById('edit-shrink').value) || 0, color_track_bg: d.bg, color_fill_from: d.from, color_fill_to: d.to});
    updatePreview();
}

function addTier(data) {
    tierCount++;
    const i = tierCount;
    const d = data || {min_amount:'',reward_type:'free_shipping',reward_value:'',free_product_id:'',label_bn:'',icon:'🎁'};
    const productSearch = d.free_product_id ? `#${d.free_product_id}` : '';
    const html = `<div class="tier-row bg-gray-50 rounded-lg border p-3 relative" id="tier-${i}">
        <div class="flex items-center justify-between mb-2">
            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Tier ${document.querySelectorAll('.tier-row').length + 1}</span>
            <button type="button" onclick="removeTier(${i})" class="flex items-center gap-1 px-2 py-1 bg-red-50 text-red-500 rounded-md text-[11px] font-medium hover:bg-red-100 hover:text-red-700 transition" title="Delete this tier">
                <i class="fas fa-trash-alt text-[10px]"></i> Delete
            </button>
        </div>
        <div class="grid grid-cols-2 gap-2 mb-2">
            <div>
                <label class="text-[10px] text-gray-500 font-medium">Min Cart Amount (৳)</label>
                <input type="number" class="tier-min w-full border rounded px-2 py-1.5 text-sm" value="${d.min_amount}" placeholder="500" oninput="updatePreview()">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 font-medium">Reward Type</label>
                <select class="tier-type w-full border rounded px-2 py-1.5 text-sm" onchange="toggleRewardFields(this,${i});updatePreview()">
                    <option value="free_shipping" ${d.reward_type==='free_shipping'?'selected':''}>Free Shipping 🚚</option>
                    <option value="discount_fixed" ${d.reward_type==='discount_fixed'?'selected':''}>Fixed Discount 💰</option>
                    <option value="discount_percent" ${d.reward_type==='discount_percent'?'selected':''}>% Discount 🏷️</option>
                    <option value="free_product" ${d.reward_type==='free_product'?'selected':''}>Free Product 🎁</option>
                </select>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-2">
            <div class="tier-value-wrap ${d.reward_type==='free_shipping'?'opacity-30':''}">
                <label class="text-[10px] text-gray-500 font-medium">Value</label>
                <input type="number" class="tier-value w-full border rounded px-2 py-1.5 text-sm" value="${d.reward_value||''}" placeholder="100" oninput="updatePreview()">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 font-medium">Icon</label>
                <input type="text" class="tier-icon w-full border rounded px-2 py-1.5 text-sm text-center" value="${d.icon||'🎁'}" maxlength="4" oninput="updatePreview()">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 font-medium">Label (BN)</label>
                <input type="text" class="tier-label w-full border rounded px-2 py-1.5 text-sm" value="${d.label_bn||''}" placeholder="ফ্রি শিপিং" oninput="updatePreview()">
            </div>
        </div>
        <div class="tier-product-wrap mt-2 ${d.reward_type==='free_product'?'':'hidden'}">
            <label class="text-[10px] text-gray-500 font-medium">Free Product</label>
            <div class="flex gap-2">
                <input type="text" class="tier-product-search flex-1 border rounded px-2 py-1.5 text-sm" placeholder="Search product..." value="${productSearch}" oninput="searchProduct(this,${i})">
                <input type="hidden" class="tier-product-id" value="${d.free_product_id||''}">
            </div>
            <div class="tier-product-results hidden mt-1 bg-white border rounded shadow-lg max-h-32 overflow-y-auto text-xs"></div>
        </div>
    </div>`;
    document.getElementById('tiers-container').insertAdjacentHTML('beforeend', html);
    renumberTiers();
    updatePreview();
}

function removeTier(i) {
    const row = document.getElementById('tier-' + i);
    if (!row) return;
    const tierRows = document.querySelectorAll('.tier-row');
    if (tierRows.length <= 1) {
        alert('At least one tier is required.');
        return;
    }
    row.style.opacity = '0'; row.style.transform = 'translateY(-10px)'; row.style.transition = 'all .2s';
    setTimeout(() => { row.remove(); renumberTiers(); updatePreview(); }, 200);
}

function renumberTiers() {
    document.querySelectorAll('.tier-row').forEach((row, idx) => {
        const label = row.querySelector('span.text-\\[10px\\].font-bold');
        if (label) label.textContent = 'Tier ' + (idx + 1);
    });
}

function toggleRewardFields(sel, i) {
    const row = document.getElementById('tier-' + i);
    if (!row) return;
    const valWrap = row.querySelector('.tier-value-wrap');
    const prodWrap = row.querySelector('.tier-product-wrap');
    valWrap.classList.toggle('opacity-30', sel.value === 'free_shipping');
    prodWrap.classList.toggle('hidden', sel.value !== 'free_product');
}

function searchProduct(input, tierIdx) {
    const q = input.value.trim();
    const row = document.getElementById('tier-' + tierIdx);
    const results = row.querySelector('.tier-product-results');
    if (q.length < 2) { results.classList.add('hidden'); return; }
    fetch(API + '?action=search_products&q=' + encodeURIComponent(q))
    .then(r=>r.json()).then(d => {
        if (!d.products?.length) { results.innerHTML = '<p class="p-2 text-gray-400">No products found</p>'; results.classList.remove('hidden'); return; }
        results.innerHTML = d.products.map(p => 
            `<div class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b" onclick="selectFreeProduct(${tierIdx},${p.id},'${(p.name||'').replace(/'/g,"\\'")}')">#${p.id} — ${p.name}</div>`
        ).join('');
        results.classList.remove('hidden');
    });
}

function selectFreeProduct(tierIdx, pid, name) {
    const row = document.getElementById('tier-' + tierIdx);
    row.querySelector('.tier-product-id').value = pid;
    row.querySelector('.tier-product-search').value = '#' + pid + ' — ' + name;
    row.querySelector('.tier-product-results').classList.add('hidden');
}

function collectTiers() {
    const tiers = [];
    document.querySelectorAll('.tier-row').forEach(row => {
        tiers.push({
            min_amount: parseFloat(row.querySelector('.tier-min').value) || 0,
            reward_type: row.querySelector('.tier-type').value,
            reward_value: parseFloat(row.querySelector('.tier-value').value) || 0,
            free_product_id: row.querySelector('.tier-product-id')?.value || null,
            label_bn: row.querySelector('.tier-label').value || '',
            icon: row.querySelector('.tier-icon').value || '🎁',
        });
    });
    return tiers.sort((a,b) => a.min_amount - b.min_amount);
}

function showEditor(bar) {
    document.getElementById('bar-editor').classList.remove('hidden');
    tierCount = 0;
    document.getElementById('tiers-container').innerHTML = '';
    
    if (bar) {
        document.getElementById('editor-title').textContent = 'Edit: ' + bar.name;
        document.getElementById('edit-id').value = bar.id;
        document.getElementById('edit-name').value = bar.name;
        currentTemplate = bar.template || 1;
        document.querySelectorAll('.tpl-card').forEach(c => c.classList.remove('selected'));
        document.querySelector(`.tpl-card[data-tpl="${currentTemplate}"]`)?.classList.add('selected');
        setConfig(bar.config || {});
        (bar.tiers || []).forEach(t => addTier(t));
    } else {
        document.getElementById('editor-title').textContent = 'Create Progress Bar';
        document.getElementById('edit-id').value = '0';
        document.getElementById('edit-name').value = '';
        currentTemplate = 1;
        document.querySelectorAll('.tpl-card').forEach(c => c.classList.remove('selected'));
        document.querySelector('.tpl-card[data-tpl="1"]')?.classList.add('selected');
        setConfig({});
        addTier({min_amount:500,reward_type:'free_shipping',reward_value:0,label_bn:'ফ্রি শিপিং',icon:'🚚'});
        addTier({min_amount:1500,reward_type:'discount_fixed',reward_value:100,label_bn:'৳100 ছাড়',icon:'🎉'});
    }
    document.getElementById('bar-editor').scrollIntoView({behavior:'smooth'});
    updatePreview();
}

function hideEditor() {
    document.getElementById('bar-editor').classList.add('hidden');
}

function saveBar() {
    const name = document.getElementById('edit-name').value.trim();
    if (!name) { alert('Name is required'); return; }
    const tiers = collectTiers().filter(t => t.min_amount > 0);
    if (!tiers.length) { alert('Add at least one tier with a valid amount'); return; }
    
    const fd = new FormData();
    fd.append('action', 'admin_save');
    fd.append('id', document.getElementById('edit-id').value);
    fd.append('name', name);
    fd.append('template', currentTemplate);
    fd.append('tiers', JSON.stringify(tiers));
    fd.append('config', JSON.stringify(getConfig()));
    
    console.log('Saving bar:', {name, template: currentTemplate, tiers, config: getConfig()});
    
    fetch(API, {method:'POST', body:fd})
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    })
    .then(text => {
        console.log('API response:', text);
        try {
            const d = JSON.parse(text);
            if (d.success) location.reload();
            else alert(d.message || d.error || 'Save failed');
        } catch(e) {
            alert('API returned invalid response:\n' + text.substring(0, 500));
        }
    })
    .catch(err => alert('Network error: ' + err.message));
}

function saveActiveBar(id) {
    const fd = new FormData();
    fd.append('action', 'admin_activate');
    fd.append('id', id);
    fetch(API, {method:'POST', body:fd}).then(()=>location.reload());
}

function deleteBar(id) {
    if (!confirm('Delete this progress bar?')) return;
    const fd = new FormData();
    fd.append('action', 'admin_delete');
    fd.append('id', id);
    fetch(API, {method:'POST', body:fd}).then(()=>location.reload());
}

function toggleEnabled(checked) {
    const fd = new FormData();
    fd.append('action', 'admin_toggle');
    fd.append('enabled', checked ? '1' : '0');
    fetch(API, {method:'POST', body:fd}).then(()=>location.reload());
}

// ═══════════════════════════════════
// LIVE PREVIEW
// ═══════════════════════════════════

function calcPct(amount, tiers) {
    const n = tiers.length; if (!n) return 0;
    const seg = 100 / n;
    for (let i = 0; i < n; i++) {
        const prev = i === 0 ? 0 : tiers[i-1].min_amount;
        const cur = tiers[i].min_amount;
        if (amount < cur) { const p = cur > prev ? (amount - prev) / (cur - prev) : 0; return Math.min(100, i * seg + p * seg); }
    }
    return 100;
}
function msPct(i, n) { return ((i + 1) / n) * 100; }

function getHeightPx(shrinkPct, base) {
    // shrinkPct: 0-50, only reduces bar/dot thickness
    const s = (shrinkPct || 0) / 100;
    return Math.max(3, Math.round(base * (1 - s)));
}

function updatePreview() {
    const amount = parseInt(document.getElementById('preview-slider').value);
    document.getElementById('preview-amount-label').textContent = '৳' + amount.toLocaleString();
    const tiers = collectTiers().filter(t => t.min_amount > 0);
    if (!tiers.length) {
        document.getElementById('preview-container').innerHTML = '<p class="text-xs text-gray-400 text-center">Add tiers to see preview</p>';
        return;
    }
    const pct = calcPct(amount, tiers);
    const tpl = currentTemplate;
    const n = tiers.length;
    const cfg = getConfig();
    const shrk = cfg.shrink || 0;
    const cBg = cfg.color_track_bg;
    const cFrom = cfg.color_fill_from;
    const cTo = cfg.color_fill_to;
    
    // Shrink calculations — define ALL before use
    const s = shrk / 100;
    const barH = tpl===5 ? getHeightPx(shrk,4) : tpl===3 ? getHeightPx(shrk,14) : getHeightPx(shrk,10);
    const dotSize = Math.max(24, Math.round(32*(1-s)));
    const fontSize = '10px';
    const iconSize = '13px';
    const pad = `${Math.round(10*(1-s))}px ${Math.round(14*(1-s*0.3))}px`;
    const gap = Math.round(6*(1-s)) + 'px';
    const msgMt = Math.round(8*(1-s)) + 'px';
    
    let nextTier = tiers.find(t => amount < t.min_amount);
    let remaining = nextTier ? (nextTier.min_amount - amount) : 0;
    
    let msgHtml = '';
    if (nextTier) {
        msgHtml = `<p style="font-size:10px;font-weight:600;margin-top:${msgMt};color:#ea580c">আরো <strong>৳${remaining.toLocaleString()}</strong> যোগ করলে <strong>${nextTier.label_bn||nextTier.reward_type}</strong> পাবেন! ${nextTier.icon}</p>`;
    } else {
        msgHtml = `<p style="font-size:10px;font-weight:600;margin-top:${msgMt};color:#16a34a">🎉 সব রিওয়ার্ড আনলক হয়েছে!</p>`;
    }
    
    // Milestone tick marks for bar track (evenly spaced)
    function mkTicks(h) {
        return tiers.map((t, i) => {
            const pos = msPct(i, n);
            const done = amount >= t.min_amount;
            return `<div style="position:absolute;left:${pos}%;top:0;bottom:0;width:2px;transform:translateX(-50%);background:${done?'rgba(255,255,255,0.6)':'rgba(0,0,0,0.15)'};z-index:1"></div>`;
        }).join('');
    }
    
    let html = '';
    
    if (tpl >= 1 && tpl <= 5) {
        const tierIcons = tiers.map(t => {
            const done = amount >= t.min_amount;
            return `<div style="text-align:center;flex:1"><span style="font-size:14px;${done?'':'opacity:.6'}">${done?'✅':t.icon}</span><div style="font-size:${fontSize};color:${done?'#16a34a':'#6b7280'};margin-top:1px;font-weight:${done?700:400};line-height:1.2">${t.label_bn}</div><div style="font-size:${fontSize};color:${done?'#22c55e':'#9ca3af'}">৳${t.min_amount.toLocaleString()}</div></div>`;
        }).join('');

        let trackStyle = `background:${cBg};border-radius:9999px;height:${barH}px;overflow:hidden;position:relative`;
        let fillStyle = `background:linear-gradient(90deg,${cFrom},${cTo});height:100%;border-radius:9999px;width:${pct}%;transition:width .5s`;
        
        if (tpl === 3) {
            trackStyle = `background:${cBg};border-radius:12px;height:${barH}px;overflow:hidden;position:relative`;
            fillStyle += `;box-shadow:0 0 12px ${cFrom}66`;
        } else if (tpl === 4) {
            trackStyle += `;border:1px solid #fde047`;
        } else if (tpl === 5) {
            trackStyle = `background:${cBg};height:${barH}px;position:relative`;
            fillStyle = `background:${cFrom};height:100%;width:${pct}%;transition:width .5s`;
        }
        
        const ticks = mkTicks(barH);
        
        if (tpl === 2) {
            const stepLine = `<div style="position:absolute;top:${dotSize/2}px;left:${dotSize/2}px;right:${dotSize/2}px;height:3px;background:${cBg};z-index:0"><div style="height:100%;background:${cFrom};width:${pct}%;transition:width .5s"></div></div>`;
            const steps = tiers.map(t => {
                const done = amount >= t.min_amount;
                return `<div style="text-align:center;z-index:1"><div style="width:${dotSize}px;height:${dotSize}px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:${iconSize};border:2px solid ${done?cFrom:'#e5e7eb'};background:${done?cFrom:'#fff'};color:${done?'#fff':'#374151'};transition:all .3s;margin:0 auto">${done?'✓':t.icon}</div><div style="font-size:${fontSize};margin-top:3px;font-weight:${done?700:500};color:${done?'#16a34a':'#6b7280'}">${t.label_bn}</div><div style="font-size:${fontSize};color:${done?'#22c55e':'#9ca3af'}">৳${t.min_amount.toLocaleString()}</div></div>`;
            }).join('');
            html = `<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:${pad};border:1px solid #fed7aa"><div style="display:flex;align-items:flex-start;justify-content:space-between;position:relative">${stepLine}${steps}</div>${msgHtml}</div>`;
        } else if (tpl === 5) {
            const labels = tiers.map(t => {
                const done = amount >= t.min_amount;
                return `<span style="font-size:${fontSize};${done?'color:#111827;font-weight:700;text-decoration:line-through;text-decoration-color:#22c55e':'color:#9ca3af'}">${t.icon} ${t.label_bn} (৳${t.min_amount.toLocaleString()})</span>`;
            }).join('<span style="color:#d1d5db;margin:0 4px">→</span>');
            html = `<div style="background:#fff;border-radius:12px;padding:${pad};border:1px solid #e5e7eb"><div style="display:flex;align-items:center;flex-wrap:wrap;gap:2px;margin-bottom:${gap}">${labels}</div><div style="${trackStyle}">${ticks}<div style="${fillStyle}"></div></div>${msgHtml}</div>`;
        } else {
            html = `<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:${pad};border:1px solid #fed7aa"><div style="display:flex;justify-content:space-between;margin-bottom:${gap}">${tierIcons}</div><div style="${trackStyle}">${ticks}<div style="${fillStyle}"></div></div>${msgHtml}</div>`;
        }
    } else if (tpl === 6) {
        const dtBarH = getHeightPx(shrk, 8);
        let dots = '';
        tiers.forEach((t, i) => {
            const pos = msPct(i, n);
            const done = amount >= t.min_amount;
            dots += `<div style="position:absolute;left:${pos}%;top:50%;transform:translate(-50%,-50%);z-index:2"><div style="width:${dotSize}px;height:${dotSize}px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:${iconSize};${done?'background:#22c55e;color:#fff;box-shadow:0 0 0 3px rgba(34,197,94,0.3)':'background:#fff;color:#374151;border:2px solid #9ca3af;box-shadow:0 1px 3px rgba(0,0,0,0.15)'};transition:all .3s">${done?'✓':t.icon}</div></div>`;
        });
        let labels = tiers.map((t, i) => {
            const pos = msPct(i, n);
            const done = amount >= t.min_amount;
            return `<div style="position:absolute;left:${pos}%;transform:translateX(-50%);text-align:center;white-space:nowrap"><div style="font-size:${fontSize};font-weight:${done?700:500};color:${done?'#16a34a':'#6b7280'}">${t.label_bn}</div><div style="font-size:${fontSize};color:${done?'#22c55e':'#9ca3af'}">৳${Number(t.min_amount).toLocaleString()}</div></div>`;
        }).join('');
        
        html = `<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:${pad};border:1px solid #fed7aa">
            <div style="text-align:center;margin-bottom:${gap}">${nextTier?`<span style="display:inline-block;background:#1f2937;color:#fff;font-size:11px;padding:3px 12px;border-radius:9999px;font-weight:600">আরো <strong style="color:#fbbf24">৳${remaining.toLocaleString()}</strong> যোগ করলে ${nextTier.label_bn} পাবেন!</span>`:`<span style="display:inline-block;background:#16a34a;color:#fff;font-size:11px;padding:3px 12px;border-radius:9999px;font-weight:600">🎉 সব রিওয়ার্ড আনলক!</span>`}</div>
            <div style="position:relative;height:${dotSize}px;margin:0 16px"><div style="position:absolute;top:50%;left:0;right:0;transform:translateY(-50%);height:${dtBarH}px;background:${cBg};border-radius:9999px;overflow:hidden"><div style="height:100%;width:${pct}%;background:linear-gradient(90deg,${cFrom},${cTo});border-radius:9999px;transition:width .5s ease;box-shadow:0 0 8px ${cFrom}66"></div></div>${dots}</div>
            <div style="position:relative;height:28px;margin:${Math.round(4*(1-s))}px 16px 0">${labels}</div>
        </div>`;
    }
    
    document.getElementById('preview-container').innerHTML = html;
}

updatePreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
