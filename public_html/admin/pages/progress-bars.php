<?php
/**
 * Admin: Checkout Progress Bar Management v4
 * Rebuilt from scratch with diagnostics
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Progress Bar Offers';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// ── Auto-create table ──
try {
    $db->query("CREATE TABLE IF NOT EXISTS `checkout_progress_bars` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL,
        `template` TINYINT DEFAULT 1, `tiers` JSON DEFAULT NULL, `config` JSON DEFAULT NULL,
        `is_active` TINYINT DEFAULT 0, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\Throwable $e) {}

// ── Ensure config column ──
try { $db->fetch("SELECT `config` FROM `checkout_progress_bars` LIMIT 0"); } catch (\Throwable $e) {
    try { $db->query("ALTER TABLE `checkout_progress_bars` ADD COLUMN `config` JSON DEFAULT NULL AFTER `tiers`"); } catch (\Throwable $e2) {}
}

// ── Load bars ──
$bars = [];
$dbError = '';
try {
    $bars = $db->fetchAll("SELECT * FROM `checkout_progress_bars` ORDER BY `is_active` DESC, `id` DESC");
    foreach ($bars as &$b) {
        $t = json_decode($b['tiers'] ?? '[]', true); $b['tiers'] = is_array($t) ? $t : [];
        $c = json_decode($b['config'] ?? '{}', true); $b['config'] = is_array($c) ? $c : [];
    }
    unset($b);
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

// ── Check enabled ──
$enabled = false;
$_cfJson = getSetting('checkout_fields', '');
$_cfArr = $_cfJson ? json_decode($_cfJson, true) : null;
if ($_cfArr && is_array($_cfArr)) {
    foreach ($_cfArr as $_cf) {
        if (($_cf['key'] ?? '') === 'progress_bar') { $enabled = !empty($_cf['enabled']); break; }
    }
} else {
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
.color-swatch{width:32px;height:32px;border-radius:8px;border:2px solid #e5e7eb;cursor:pointer;padding:0;overflow:hidden}
.color-swatch::-webkit-color-swatch-wrapper{padding:0}
.color-swatch::-webkit-color-swatch{border:none;border-radius:6px}
</style>

<?php if ($dbError): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
    <p class="text-red-700 font-bold text-sm">⚠️ Database Error: <?= htmlspecialchars($dbError) ?></p>
</div>
<?php endif; ?>

<!-- Status Banner -->
<div id="pb-status" class="bg-yellow-50 border border-yellow-200 rounded-xl p-3 mb-4 text-xs hidden"></div>

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
                            <span class="text-[10px] bg-blue-50 text-blue-500 px-1.5 py-0.5 rounded"><?= count($bar['tiers']) ?> tiers</span>
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <?php foreach ($bar['tiers'] as $t): ?>
                            <span class="text-[10px] text-gray-400"><?= $t['icon'] ?> ৳<?= number_format($t['min_amount']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="flex gap-1.5 shrink-0">
                        <button type="button" class="px-2.5 py-1.5 bg-blue-50 text-blue-600 rounded-lg text-xs font-medium hover:bg-blue-100 edit-bar-btn" data-bar="<?= htmlspecialchars(json_encode($bar, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" title="Edit"><i class="fas fa-edit"></i></button>
                        <button type="button" onclick="deleteBar(<?= $bar['id'] ?>)" class="px-2.5 py-1.5 bg-red-50 text-red-600 rounded-lg text-xs font-medium hover:bg-red-100" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            </form>
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
                            <input type="color" id="edit-color-bg" value="#f3f4f6" class="color-swatch" onchange="document.getElementById('edit-color-bg-hex').value=this.value;updatePreview()">
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
                    <button onclick="saveBar()" id="save-btn" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition flex-1">
                        <i class="fas fa-save mr-1"></i> Save Progress Bar
                    </button>
                    <button onclick="hideEditor()" class="bg-gray-100 text-gray-600 px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</button>
                </div>
                <div id="save-result" class="text-xs hidden p-2 rounded"></div>
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
/* ═══════════════════════════════════════════
   PROGRESS BAR ADMIN v4 — Rebuilt from scratch
   ═══════════════════════════════════════════ */

const API = '<?= SITE_URL ?>/api/progress-bar.php';
let currentTemplate = 1;
let tierCount = 0;

const TPL_DEFAULTS = {
    1: {bg:'#f3f4f6', from:'#ef4444', to:'#22c55e'},
    2: {bg:'#e5e7eb', from:'#22c55e', to:'#22c55e'},
    3: {bg:'#1e1b4b', from:'#7c3aed', to:'#f59e0b'},
    4: {bg:'#fef9c3', from:'#facc15', to:'#f43f5e'},
    5: {bg:'#f9fafb', from:'#111827', to:'#111827'},
    6: {bg:'#374151', from:'#f59e0b', to:'#ef4444'},
};

/* ── Utility ── */
function syncColor(hexInput, colorId) {
    if (/^#[0-9a-fA-F]{6}$/.test(hexInput.value)) document.getElementById(colorId).value = hexInput.value;
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
    var c = cfg || {};
    var shrink = c.shrink != null ? c.shrink : 0;
    document.getElementById('edit-shrink').value = shrink;
    document.getElementById('shrink-value').textContent = shrink + '%';
    var defaults = TPL_DEFAULTS[currentTemplate] || TPL_DEFAULTS[1];
    var bg = c.color_track_bg || defaults.bg;
    var from = c.color_fill_from || defaults.from;
    var to = c.color_fill_to || defaults.to;
    document.getElementById('edit-color-bg').value = bg;
    document.getElementById('edit-color-bg-hex').value = bg;
    document.getElementById('edit-color-from').value = from;
    document.getElementById('edit-color-from-hex').value = from;
    document.getElementById('edit-color-to').value = to;
    document.getElementById('edit-color-to-hex').value = to;
}
function resetColors() {
    var d = TPL_DEFAULTS[currentTemplate] || TPL_DEFAULTS[1];
    setConfig({shrink: parseInt(document.getElementById('edit-shrink').value) || 0, color_track_bg: d.bg, color_fill_from: d.from, color_fill_to: d.to});
    updatePreview();
}
function selectTemplate(n) {
    currentTemplate = n;
    document.querySelectorAll('.tpl-card').forEach(function(c) { c.classList.remove('selected'); });
    var el = document.querySelector('.tpl-card[data-tpl="'+n+'"]');
    if (el) el.classList.add('selected');
    var d = TPL_DEFAULTS[n] || TPL_DEFAULTS[1];
    setConfig({shrink: parseInt(document.getElementById('edit-shrink').value) || 0, color_track_bg: d.bg, color_fill_from: d.from, color_fill_to: d.to});
    updatePreview();
}

/* ── Tier Management ── */
function addTier(data) {
    tierCount++;
    var i = tierCount;
    var d = data || {min_amount:'',reward_type:'free_shipping',reward_value:'',free_product_id:'',label_bn:'',icon:'🎁'};
    var productSearch = d.free_product_id ? '#' + d.free_product_id : '';
    var minVal = d.min_amount || '';
    var rwdVal = d.reward_value || '';
    var iconVal = d.icon || '🎁';
    var labelVal = d.label_bn || '';
    var rwdType = d.reward_type || 'free_shipping';
    var tierNum = document.querySelectorAll('.tier-row').length + 1;
    
    var html = '<div class="tier-row bg-gray-50 rounded-lg border p-3 relative" id="tier-' + i + '">';
    html += '<div class="flex items-center justify-between mb-2">';
    html += '<span class="tier-number text-xs font-bold text-gray-400 uppercase tracking-wide">Tier ' + tierNum + '</span>';
    html += '<button type="button" onclick="removeTier(' + i + ')" class="flex items-center gap-1 px-2 py-1 bg-red-50 text-red-500 rounded-md text-xs font-medium hover:bg-red-100 hover:text-red-700 transition" title="Delete this tier"><i class="fas fa-trash-alt text-xs"></i> Delete</button>';
    html += '</div>';
    html += '<div class="grid grid-cols-2 gap-2 mb-2">';
    html += '<div><label class="text-xs text-gray-500 font-medium">Min Cart Amount (৳)</label>';
    html += '<input type="number" class="tier-min w-full border rounded px-2 py-1.5 text-sm" value="' + minVal + '" placeholder="500" oninput="updatePreview()"></div>';
    html += '<div><label class="text-xs text-gray-500 font-medium">Reward Type</label>';
    html += '<select class="tier-type w-full border rounded px-2 py-1.5 text-sm" onchange="toggleRewardFields(this,' + i + ');updatePreview()">';
    html += '<option value="free_shipping"' + (rwdType==='free_shipping'?' selected':'') + '>Free Shipping 🚚</option>';
    html += '<option value="discount_fixed"' + (rwdType==='discount_fixed'?' selected':'') + '>Fixed Discount 💰</option>';
    html += '<option value="discount_percent"' + (rwdType==='discount_percent'?' selected':'') + '>% Discount 🏷️</option>';
    html += '<option value="free_product"' + (rwdType==='free_product'?' selected':'') + '>Free Product 🎁</option>';
    html += '</select></div></div>';
    html += '<div class="grid grid-cols-3 gap-2">';
    html += '<div class="tier-value-wrap' + (rwdType==='free_shipping'?' opacity-30':'') + '"><label class="text-xs text-gray-500 font-medium">Value</label>';
    html += '<input type="number" class="tier-value w-full border rounded px-2 py-1.5 text-sm" value="' + rwdVal + '" placeholder="100" oninput="updatePreview()"></div>';
    html += '<div><label class="text-xs text-gray-500 font-medium">Icon</label>';
    html += '<input type="text" class="tier-icon w-full border rounded px-2 py-1.5 text-sm text-center" value="' + iconVal + '" maxlength="4" oninput="updatePreview()"></div>';
    html += '<div><label class="text-xs text-gray-500 font-medium">Label (BN)</label>';
    html += '<input type="text" class="tier-label w-full border rounded px-2 py-1.5 text-sm" value="' + labelVal + '" placeholder="ফ্রি শিপিং" oninput="updatePreview()"></div>';
    html += '</div>';
    html += '<div class="tier-product-wrap mt-2' + (rwdType==='free_product'?'':' hidden') + '">';
    html += '<label class="text-xs text-gray-500 font-medium">Free Product</label>';
    html += '<div class="flex gap-2"><input type="text" class="tier-product-search flex-1 border rounded px-2 py-1.5 text-sm" placeholder="Search product..." value="' + productSearch + '" oninput="searchProduct(this,' + i + ')">';
    html += '<input type="hidden" class="tier-product-id" value="' + (d.free_product_id||'') + '"></div>';
    html += '<div class="tier-product-results hidden mt-1 bg-white border rounded shadow-lg max-h-32 overflow-y-auto text-xs"></div></div>';
    html += '</div>';
    
    document.getElementById('tiers-container').insertAdjacentHTML('beforeend', html);
    renumberTiers();
    updatePreview();
}

function removeTier(i) {
    var row = document.getElementById('tier-' + i);
    if (!row) return;
    if (document.querySelectorAll('.tier-row').length <= 1) { alert('At least one tier is required.'); return; }
    row.remove();
    renumberTiers();
    updatePreview();
}

function renumberTiers() {
    document.querySelectorAll('.tier-row').forEach(function(row, idx) {
        var label = row.querySelector('.tier-number');
        if (label) label.textContent = 'Tier ' + (idx + 1);
    });
}

function toggleRewardFields(sel, i) {
    var row = document.getElementById('tier-' + i);
    if (!row) return;
    var valWrap = row.querySelector('.tier-value-wrap');
    var prodWrap = row.querySelector('.tier-product-wrap');
    if (sel.value === 'free_shipping') valWrap.classList.add('opacity-30'); else valWrap.classList.remove('opacity-30');
    if (sel.value === 'free_product') prodWrap.classList.remove('hidden'); else prodWrap.classList.add('hidden');
}

function searchProduct(input, tierIdx) {
    var q = input.value.trim();
    var row = document.getElementById('tier-' + tierIdx);
    var results = row.querySelector('.tier-product-results');
    if (q.length < 2) { results.classList.add('hidden'); return; }
    fetch(API + '?action=search_products&q=' + encodeURIComponent(q))
    .then(function(r){return r.json()}).then(function(d) {
        if (!d.products || !d.products.length) { results.innerHTML = '<p class="p-2 text-gray-400">No products found</p>'; results.classList.remove('hidden'); return; }
        results.innerHTML = d.products.map(function(p) {
            return '<div class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b" onclick="selectFreeProduct(' + tierIdx + ',' + p.id + ')">#' + p.id + ' — ' + (p.name||'') + '</div>';
        }).join('');
        results.classList.remove('hidden');
    });
}

function selectFreeProduct(tierIdx, pid) {
    var row = document.getElementById('tier-' + tierIdx);
    row.querySelector('.tier-product-id').value = pid;
    row.querySelector('.tier-product-search').value = '#' + pid;
    row.querySelector('.tier-product-results').classList.add('hidden');
}

function collectTiers() {
    var tiers = [];
    document.querySelectorAll('.tier-row').forEach(function(row) {
        var minEl = row.querySelector('.tier-min');
        var typeEl = row.querySelector('.tier-type');
        var valEl = row.querySelector('.tier-value');
        var iconEl = row.querySelector('.tier-icon');
        var labelEl = row.querySelector('.tier-label');
        var pidEl = row.querySelector('.tier-product-id');
        tiers.push({
            min_amount: parseFloat(minEl ? minEl.value : 0) || 0,
            reward_type: typeEl ? typeEl.value : 'free_shipping',
            reward_value: parseFloat(valEl ? valEl.value : 0) || 0,
            free_product_id: pidEl ? pidEl.value : '',
            label_bn: labelEl ? labelEl.value : '',
            icon: iconEl ? iconEl.value : '🎁',
        });
    });
    tiers.sort(function(a, b) { return a.min_amount - b.min_amount; });
    return tiers;
}

/* ── Editor ── */
function showEditor(bar) {
    document.getElementById('bar-editor').classList.remove('hidden');
    document.getElementById('save-result').classList.add('hidden');
    tierCount = 0;
    document.getElementById('tiers-container').innerHTML = '';
    
    if (bar && typeof bar === 'object') {
        document.getElementById('editor-title').textContent = 'Edit: ' + bar.name;
        document.getElementById('edit-id').value = bar.id;
        document.getElementById('edit-name').value = bar.name;
        currentTemplate = bar.template || 1;
        document.querySelectorAll('.tpl-card').forEach(function(c) { c.classList.remove('selected'); });
        var sel = document.querySelector('.tpl-card[data-tpl="'+currentTemplate+'"]');
        if (sel) sel.classList.add('selected');
        setConfig(bar.config || {});
        var tierArr = bar.tiers || [];
        for (var t = 0; t < tierArr.length; t++) addTier(tierArr[t]);
    } else {
        document.getElementById('editor-title').textContent = 'Create Progress Bar';
        document.getElementById('edit-id').value = '0';
        document.getElementById('edit-name').value = '';
        currentTemplate = 1;
        document.querySelectorAll('.tpl-card').forEach(function(c) { c.classList.remove('selected'); });
        var first = document.querySelector('.tpl-card[data-tpl="1"]');
        if (first) first.classList.add('selected');
        setConfig({});
        addTier({min_amount:500,reward_type:'free_shipping',reward_value:0,label_bn:'ফ্রি শিপিং',icon:'🚚'});
        addTier({min_amount:1500,reward_type:'discount_fixed',reward_value:100,label_bn:'৳100 ছাড়',icon:'🎉'});
    }
    document.getElementById('bar-editor').scrollIntoView({behavior:'smooth'});
}

function hideEditor() { document.getElementById('bar-editor').classList.add('hidden'); }

/* ── Save ── */
function saveBar() {
    var name = document.getElementById('edit-name').value.trim();
    if (!name) { alert('Name is required'); return; }
    var tiers = collectTiers().filter(function(t) { return t.min_amount > 0; });
    if (!tiers.length) { alert('Add at least one tier with amount > 0'); return; }
    
    var resultEl = document.getElementById('save-result');
    resultEl.className = 'text-xs p-2 rounded bg-blue-50 text-blue-700';
    resultEl.textContent = 'Saving... (' + tiers.length + ' tiers)';
    resultEl.classList.remove('hidden');
    
    var fd = new FormData();
    fd.append('action', 'admin_save');
    fd.append('id', document.getElementById('edit-id').value);
    fd.append('name', name);
    fd.append('template', currentTemplate);
    fd.append('tiers', JSON.stringify(tiers));
    fd.append('config', JSON.stringify(getConfig()));
    
    fetch(API, {method:'POST', body:fd})
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    })
    .then(function(text) {
        try {
            var d = JSON.parse(text);
            if (d.success) {
                resultEl.className = 'text-xs p-2 rounded bg-green-50 text-green-700';
                resultEl.textContent = '✅ ' + (d.message || 'Saved!');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                resultEl.className = 'text-xs p-2 rounded bg-red-50 text-red-700';
                resultEl.textContent = '❌ ' + (d.message || 'Save failed');
            }
        } catch(e) {
            resultEl.className = 'text-xs p-2 rounded bg-red-50 text-red-700';
            resultEl.textContent = '❌ Invalid response: ' + text.substring(0, 300);
        }
    })
    .catch(function(err) {
        resultEl.className = 'text-xs p-2 rounded bg-red-50 text-red-700';
        resultEl.textContent = '❌ Network error: ' + err.message;
    });
}

function saveActiveBar(id) {
    var fd = new FormData(); fd.append('action', 'admin_activate'); fd.append('id', id);
    fetch(API, {method:'POST', body:fd}).then(function(){ location.reload(); });
}

function deleteBar(id) {
    if (!confirm('Delete this progress bar?')) return;
    var fd = new FormData(); fd.append('action', 'admin_delete'); fd.append('id', id);
    fetch(API, {method:'POST', body:fd}).then(function(){ location.reload(); });
}

function toggleEnabled(checked) {
    var fd = new FormData(); fd.append('action', 'admin_toggle'); fd.append('enabled', checked ? '1' : '0');
    fetch(API, {method:'POST', body:fd}).then(function(){ location.reload(); });
}

/* ═══════════════════════════════════
   LIVE PREVIEW
   ═══════════════════════════════════ */

function calcPct(amount, tiers) {
    var n = tiers.length; if (!n) return 0;
    var seg = 100 / n;
    for (var i = 0; i < n; i++) {
        var prev = i === 0 ? 0 : tiers[i-1].min_amount;
        var cur = tiers[i].min_amount;
        if (amount < cur) { var p = cur > prev ? (amount - prev) / (cur - prev) : 0; return Math.min(100, i * seg + p * seg); }
    }
    return 100;
}

function msPct(i, n) { return ((i + 1) / n) * 100; }

function barHeight(shrinkPct, base) {
    var s = (shrinkPct || 0) / 100;
    return Math.max(3, Math.round(base * (1 - s)));
}

function updatePreview() {
    try {
        var amount = parseInt(document.getElementById('preview-slider').value) || 0;
        document.getElementById('preview-amount-label').textContent = '৳' + amount.toLocaleString();
        var tiers = collectTiers().filter(function(t) { return t.min_amount > 0; });
        
        if (!tiers.length) {
            document.getElementById('preview-container').innerHTML = '<p class="text-xs text-gray-400 text-center">Add tiers to see preview</p>';
            return;
        }
        
        var pct = calcPct(amount, tiers);
        var tpl = currentTemplate;
        var n = tiers.length;
        var cfg = getConfig();
        var shrk = cfg.shrink || 0;
        var cBg = cfg.color_track_bg;
        var cFrom = cfg.color_fill_from;
        var cTo = cfg.color_fill_to;
        var s = shrk / 100;
        var bH = tpl===5 ? barHeight(shrk,4) : tpl===3 ? barHeight(shrk,14) : barHeight(shrk,10);
        var dotSz = Math.max(24, Math.round(32*(1-s)));
        var pad = Math.round(10*(1-s)) + 'px ' + Math.round(14*(1-s*0.3)) + 'px';
        var gap = Math.round(6*(1-s)) + 'px';
        var msgMt = Math.round(8*(1-s)) + 'px';
        
        var nextTier = null, remaining = 0;
        for (var ti = 0; ti < tiers.length; ti++) {
            if (amount < tiers[ti].min_amount) { nextTier = tiers[ti]; remaining = nextTier.min_amount - amount; break; }
        }
        
        var msgHtml = nextTier
            ? '<p style="font-size:10px;font-weight:600;margin-top:'+msgMt+';color:#ea580c">আরো <strong>৳'+remaining.toLocaleString()+'</strong> যোগ করলে <strong>'+(nextTier.label_bn||nextTier.reward_type)+'</strong> পাবেন! '+nextTier.icon+'</p>'
            : '<p style="font-size:10px;font-weight:600;margin-top:'+msgMt+';color:#16a34a">🎉 সব রিওয়ার্ড আনলক হয়েছে!</p>';
        
        // Tick marks
        var ticks = '';
        for (var mi = 0; mi < n; mi++) {
            var pos = msPct(mi, n);
            var done = amount >= tiers[mi].min_amount;
            ticks += '<div style="position:absolute;left:'+pos+'%;top:0;bottom:0;width:2px;transform:translateX(-50%);background:'+(done?'rgba(255,255,255,0.6)':'rgba(0,0,0,0.15)')+';z-index:1"></div>';
        }
        
        var html = '';
        
        if (tpl >= 1 && tpl <= 5) {
            // Tier icons
            var tierIcons = '';
            for (var ii = 0; ii < tiers.length; ii++) {
                var t = tiers[ii], done = amount >= t.min_amount;
                tierIcons += '<div style="text-align:center;flex:1"><span style="font-size:14px;'+(done?'':'opacity:.6')+'">'+(done?'✅':t.icon)+'</span><div style="font-size:10px;color:'+(done?'#16a34a':'#6b7280')+';margin-top:1px;font-weight:'+(done?700:400)+';line-height:1.2">'+t.label_bn+'</div><div style="font-size:10px;color:'+(done?'#22c55e':'#9ca3af')+'">৳'+t.min_amount.toLocaleString()+'</div></div>';
            }
            
            var trackStyle = 'background:'+cBg+';border-radius:9999px;height:'+bH+'px;overflow:hidden;position:relative';
            var fillStyle = 'background:linear-gradient(90deg,'+cFrom+','+cTo+');height:100%;border-radius:9999px;width:'+pct+'%;transition:width .5s';
            
            if (tpl === 3) { trackStyle = 'background:'+cBg+';border-radius:12px;height:'+bH+'px;overflow:hidden;position:relative'; fillStyle += ';box-shadow:0 0 12px '+cFrom+'66'; }
            else if (tpl === 4) { trackStyle += ';border:1px solid #fde047'; }
            else if (tpl === 5) { trackStyle = 'background:'+cBg+';height:'+bH+'px;position:relative'; fillStyle = 'background:'+cFrom+';height:100%;width:'+pct+'%;transition:width .5s'; }
            
            if (tpl === 2) {
                var stepLine = '<div style="position:absolute;top:'+dotSz/2+'px;left:'+dotSz/2+'px;right:'+dotSz/2+'px;height:3px;background:'+cBg+';z-index:0"><div style="height:100%;background:'+cFrom+';width:'+pct+'%;transition:width .5s"></div></div>';
                var steps = '';
                for (var si = 0; si < tiers.length; si++) {
                    var st = tiers[si], dn = amount >= st.min_amount;
                    steps += '<div style="text-align:center;z-index:1"><div style="width:'+dotSz+'px;height:'+dotSz+'px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;border:2px solid '+(dn?cFrom:'#e5e7eb')+';background:'+(dn?cFrom:'#fff')+';color:'+(dn?'#fff':'#374151')+';transition:all .3s;margin:0 auto">'+(dn?'✓':st.icon)+'</div><div style="font-size:10px;margin-top:3px;font-weight:'+(dn?700:500)+';color:'+(dn?'#16a34a':'#6b7280')+'">'+st.label_bn+'</div><div style="font-size:10px;color:'+(dn?'#22c55e':'#9ca3af')+'">৳'+st.min_amount.toLocaleString()+'</div></div>';
                }
                html = '<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:'+pad+';border:1px solid #fed7aa"><div style="display:flex;align-items:flex-start;justify-content:space-between;position:relative">'+stepLine+steps+'</div>'+msgHtml+'</div>';
            } else if (tpl === 5) {
                var labels = '';
                for (var li = 0; li < tiers.length; li++) {
                    var lt = tiers[li], ld = amount >= lt.min_amount;
                    if (li > 0) labels += '<span style="color:#d1d5db;margin:0 4px">→</span>';
                    labels += '<span style="font-size:10px;'+(ld?'color:#111827;font-weight:700;text-decoration:line-through;text-decoration-color:#22c55e':'color:#9ca3af')+'">'+lt.icon+' '+lt.label_bn+' (৳'+lt.min_amount.toLocaleString()+')</span>';
                }
                html = '<div style="background:#fff;border-radius:12px;padding:'+pad+';border:1px solid #e5e7eb"><div style="display:flex;align-items:center;flex-wrap:wrap;gap:2px;margin-bottom:'+gap+'">'+labels+'</div><div style="'+trackStyle+'">'+ticks+'<div style="'+fillStyle+'"></div></div>'+msgHtml+'</div>';
            } else {
                html = '<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:'+pad+';border:1px solid #fed7aa"><div style="display:flex;justify-content:space-between;margin-bottom:'+gap+'">'+tierIcons+'</div><div style="'+trackStyle+'">'+ticks+'<div style="'+fillStyle+'"></div></div>'+msgHtml+'</div>';
            }
        } else if (tpl === 6) {
            var dtBarH = barHeight(shrk, 8);
            var dots = '', labels6 = '';
            for (var di = 0; di < tiers.length; di++) {
                var dt = tiers[di], dpos = msPct(di, n), dd = amount >= dt.min_amount;
                dots += '<div style="position:absolute;left:'+dpos+'%;top:50%;transform:translate(-50%,-50%);z-index:2"><div style="width:'+dotSz+'px;height:'+dotSz+'px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;'+(dd?'background:#22c55e;color:#fff;box-shadow:0 0 0 3px rgba(34,197,94,0.3)':'background:#fff;color:#374151;border:2px solid #9ca3af;box-shadow:0 1px 3px rgba(0,0,0,0.15)')+';transition:all .3s">'+(dd?'✓':dt.icon)+'</div></div>';
                labels6 += '<div style="position:absolute;left:'+dpos+'%;transform:translateX(-50%);text-align:center;white-space:nowrap"><div style="font-size:10px;font-weight:'+(dd?700:500)+';color:'+(dd?'#16a34a':'#6b7280')+'">'+dt.label_bn+'</div><div style="font-size:10px;color:'+(dd?'#22c55e':'#9ca3af')+'">৳'+Number(dt.min_amount).toLocaleString()+'</div></div>';
            }
            var t6msg = nextTier
                ? '<span style="display:inline-block;background:#1f2937;color:#fff;font-size:11px;padding:3px 12px;border-radius:9999px;font-weight:600">আরো <strong style="color:#fbbf24">৳'+remaining.toLocaleString()+'</strong> যোগ করলে '+nextTier.label_bn+' পাবেন!</span>'
                : '<span style="display:inline-block;background:#16a34a;color:#fff;font-size:11px;padding:3px 12px;border-radius:9999px;font-weight:600">🎉 সব রিওয়ার্ড আনলক!</span>';
            html = '<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:'+pad+';border:1px solid #fed7aa">';
            html += '<div style="text-align:center;margin-bottom:'+gap+'">'+t6msg+'</div>';
            html += '<div style="position:relative;height:'+dotSz+'px;margin:0 16px"><div style="position:absolute;top:50%;left:0;right:0;transform:translateY(-50%);height:'+dtBarH+'px;background:'+cBg+';border-radius:9999px;overflow:hidden"><div style="height:100%;width:'+pct+'%;background:linear-gradient(90deg,'+cFrom+','+cTo+');border-radius:9999px;transition:width .5s ease;box-shadow:0 0 8px '+cFrom+'66"></div></div>'+dots+'</div>';
            html += '<div style="position:relative;height:28px;margin:'+Math.round(4*(1-s))+'px 16px 0">'+labels6+'</div>';
            html += '</div>';
        }
        
        document.getElementById('preview-container').innerHTML = html;
    } catch(e) {
        document.getElementById('preview-container').innerHTML = '<p class="text-xs text-red-500 text-center">Preview error: ' + e.message + '</p>';
        console.error('Preview error:', e);
    }
}

/* ── Init: Bind edit buttons + load active bar preview ── */
document.addEventListener('DOMContentLoaded', function() {
    // Bind edit buttons via data attribute (safe JSON passing)
    document.querySelectorAll('.edit-bar-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            try {
                var barData = JSON.parse(this.getAttribute('data-bar'));
                showEditor(barData);
            } catch(e) {
                alert('Failed to parse bar data: ' + e.message);
            }
        });
    });
    
    // Load active bar preview (shows when editor is closed)
    loadActiveBarPreview();
    
    // Run API diagnostic
    fetch(API + '?action=diag')
    .then(function(r){ return r.json(); })
    .then(function(d) {
        if (d.diag) {
            var status = document.getElementById('pb-status');
            var msg = '🔍 DB: ' + (d.diag.table_exists ? '✅ Table OK' : '❌ Table MISSING');
            msg += ' | Bars: ' + (d.diag.bar_count || 0);
            msg += ' | Active: ' + (d.diag.active_bar || 'NONE');
            msg += ' | Config col: ' + (d.diag.config_column ? '✅' : '❌');
            if (d.diag.error) msg += ' | Error: ' + d.diag.error;
            status.innerHTML = msg;
            status.classList.remove('hidden');
            if (d.diag.table_exists && d.diag.bar_count > 0) {
                status.className = 'bg-green-50 border border-green-200 rounded-xl p-3 mb-4 text-xs text-green-700';
            } else if (!d.diag.table_exists) {
                status.className = 'bg-red-50 border border-red-200 rounded-xl p-3 mb-4 text-xs text-red-700';
            } else {
                status.className = 'bg-yellow-50 border border-yellow-200 rounded-xl p-3 mb-4 text-xs text-yellow-700';
            }
        }
    })
    .catch(function(e) {
        var status = document.getElementById('pb-status');
        status.innerHTML = '❌ API unreachable: ' + e.message + ' (URL: ' + API + ')';
        status.className = 'bg-red-50 border border-red-200 rounded-xl p-3 mb-4 text-xs text-red-700';
        status.classList.remove('hidden');
    });
});

/* ── Active Bar Preview (shown when editor is closed) ── */
var activeBarData = null;

function loadActiveBarPreview() {
    fetch(API + '?action=get_active')
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success && d.bar && d.bar.tiers && d.bar.tiers.length) {
            activeBarData = d.bar;
            // Only show if editor is closed
            if (document.getElementById('bar-editor').classList.contains('hidden')) {
                renderActiveBarPreview();
            }
        }
    })
    .catch(function() {});
}

function renderActiveBarPreview() {
    if (!activeBarData || !activeBarData.tiers || !activeBarData.tiers.length) return;
    var amount = parseInt(document.getElementById('preview-slider').value) || 750;
    var tiers = activeBarData.tiers;
    var tpl = activeBarData.template || 1;
    var cfg = activeBarData.config || {};
    var n = tiers.length;
    
    var defaults = TPL_DEFAULTS[tpl] || TPL_DEFAULTS[1];
    var cBg = cfg.color_track_bg || defaults.bg;
    var cFrom = cfg.color_fill_from || defaults.from;
    var cTo = cfg.color_fill_to || defaults.to;
    var shrk = cfg.shrink || 0;
    var s = shrk / 100;
    var bH = tpl===5 ? barHeight(shrk,4) : tpl===3 ? barHeight(shrk,14) : barHeight(shrk,10);
    var dotSz = Math.max(24, Math.round(32*(1-s)));
    var pad = Math.round(10*(1-s)) + 'px ' + Math.round(14*(1-s*0.3)) + 'px';
    var gap = Math.round(6*(1-s)) + 'px';
    var msgMt = Math.round(8*(1-s)) + 'px';
    var pct = calcPct(amount, tiers);
    
    var nextTier = null, remaining = 0;
    for (var ti = 0; ti < tiers.length; ti++) {
        if (amount < tiers[ti].min_amount) { nextTier = tiers[ti]; remaining = nextTier.min_amount - amount; break; }
    }
    
    var msgHtml = nextTier
        ? '<p style="font-size:10px;font-weight:600;margin-top:'+msgMt+';color:#ea580c">আরো <strong>৳'+remaining.toLocaleString()+'</strong> যোগ করলে <strong>'+(nextTier.label_bn||nextTier.reward_type)+'</strong> পাবেন! '+nextTier.icon+'</p>'
        : '<p style="font-size:10px;font-weight:600;margin-top:'+msgMt+';color:#16a34a">🎉 সব রিওয়ার্ড আনলক হয়েছে!</p>';
    
    var ticks = '';
    for (var mi = 0; mi < n; mi++) {
        var pos = msPct(mi, n);
        var done = amount >= tiers[mi].min_amount;
        ticks += '<div style="position:absolute;left:'+pos+'%;top:0;bottom:0;width:2px;transform:translateX(-50%);background:'+(done?'rgba(255,255,255,0.6)':'rgba(0,0,0,0.15)')+';z-index:1"></div>';
    }
    
    var html = '';
    
    if (tpl >= 1 && tpl <= 5) {
        var tierIcons = '';
        for (var ii = 0; ii < tiers.length; ii++) {
            var t = tiers[ii], dn = amount >= t.min_amount;
            tierIcons += '<div style="text-align:center;flex:1"><span style="font-size:14px;'+(dn?'':'opacity:.6')+'">'+(dn?'✅':t.icon)+'</span><div style="font-size:10px;color:'+(dn?'#16a34a':'#6b7280')+';margin-top:1px;font-weight:'+(dn?700:400)+';line-height:1.2">'+t.label_bn+'</div><div style="font-size:10px;color:'+(dn?'#22c55e':'#9ca3af')+'">৳'+t.min_amount.toLocaleString()+'</div></div>';
        }
        var trackStyle = 'background:'+cBg+';border-radius:9999px;height:'+bH+'px;overflow:hidden;position:relative';
        var fillStyle = 'background:linear-gradient(90deg,'+cFrom+','+cTo+');height:100%;border-radius:9999px;width:'+pct+'%;transition:width .5s';
        if (tpl === 3) { trackStyle = 'background:'+cBg+';border-radius:12px;height:'+bH+'px;overflow:hidden;position:relative'; fillStyle += ';box-shadow:0 0 12px '+cFrom+'66'; }
        else if (tpl === 4) { trackStyle += ';border:1px solid #fde047'; }
        else if (tpl === 5) { trackStyle = 'background:'+cBg+';height:'+bH+'px;position:relative'; fillStyle = 'background:'+cFrom+';height:100%;width:'+pct+'%;transition:width .5s'; }
        
        if (tpl === 2) {
            var stepLine = '<div style="position:absolute;top:'+dotSz/2+'px;left:'+dotSz/2+'px;right:'+dotSz/2+'px;height:3px;background:'+cBg+';z-index:0"><div style="height:100%;background:'+cFrom+';width:'+pct+'%;transition:width .5s"></div></div>';
            var steps = '';
            for (var si = 0; si < tiers.length; si++) {
                var st = tiers[si], sd = amount >= st.min_amount;
                steps += '<div style="text-align:center;z-index:1"><div style="width:'+dotSz+'px;height:'+dotSz+'px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;border:2px solid '+(sd?cFrom:'#e5e7eb')+';background:'+(sd?cFrom:'#fff')+';color:'+(sd?'#fff':'#374151')+';transition:all .3s;margin:0 auto">'+(sd?'✓':st.icon)+'</div><div style="font-size:10px;margin-top:3px;font-weight:'+(sd?700:500)+';color:'+(sd?'#16a34a':'#6b7280')+'">'+st.label_bn+'</div><div style="font-size:10px;color:'+(sd?'#22c55e':'#9ca3af')+'">৳'+st.min_amount.toLocaleString()+'</div></div>';
            }
            html = '<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:'+pad+';border:1px solid #fed7aa"><div style="display:flex;align-items:flex-start;justify-content:space-between;position:relative">'+stepLine+steps+'</div>'+msgHtml+'</div>';
        } else if (tpl === 5) {
            var labels5 = '';
            for (var li = 0; li < tiers.length; li++) {
                var lt = tiers[li], ld = amount >= lt.min_amount;
                if (li > 0) labels5 += '<span style="color:#d1d5db;margin:0 4px">→</span>';
                labels5 += '<span style="font-size:10px;'+(ld?'color:#111827;font-weight:700;text-decoration:line-through;text-decoration-color:#22c55e':'color:#9ca3af')+'">'+lt.icon+' '+lt.label_bn+' (৳'+lt.min_amount.toLocaleString()+')</span>';
            }
            html = '<div style="background:#fff;border-radius:12px;padding:'+pad+';border:1px solid #e5e7eb"><div style="display:flex;align-items:center;flex-wrap:wrap;gap:2px;margin-bottom:'+gap+'">'+labels5+'</div><div style="'+trackStyle+'">'+ticks+'<div style="'+fillStyle+'"></div></div>'+msgHtml+'</div>';
        } else {
            html = '<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:'+pad+';border:1px solid #fed7aa"><div style="display:flex;justify-content:space-between;margin-bottom:'+gap+'">'+tierIcons+'</div><div style="'+trackStyle+'">'+ticks+'<div style="'+fillStyle+'"></div></div>'+msgHtml+'</div>';
        }
    } else if (tpl === 6) {
        var dtBarH = barHeight(shrk, 8);
        var dots = '', labels6 = '';
        for (var di = 0; di < tiers.length; di++) {
            var dt = tiers[di], dpos = msPct(di, n), dd = amount >= dt.min_amount;
            dots += '<div style="position:absolute;left:'+dpos+'%;top:50%;transform:translate(-50%,-50%);z-index:2"><div style="width:'+dotSz+'px;height:'+dotSz+'px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;'+(dd?'background:#22c55e;color:#fff;box-shadow:0 0 0 3px rgba(34,197,94,0.3)':'background:#fff;color:#374151;border:2px solid #9ca3af;box-shadow:0 1px 3px rgba(0,0,0,0.15)')+';transition:all .3s">'+(dd?'✓':dt.icon)+'</div></div>';
            labels6 += '<div style="position:absolute;left:'+dpos+'%;transform:translateX(-50%);text-align:center;white-space:nowrap"><div style="font-size:10px;font-weight:'+(dd?700:500)+';color:'+(dd?'#16a34a':'#6b7280')+'">'+dt.label_bn+'</div><div style="font-size:10px;color:'+(dd?'#22c55e':'#9ca3af')+'">৳'+Number(dt.min_amount).toLocaleString()+'</div></div>';
        }
        var t6msg = nextTier
            ? '<span style="display:inline-block;background:#1f2937;color:#fff;font-size:11px;padding:3px 12px;border-radius:9999px;font-weight:600">আরো <strong style="color:#fbbf24">৳'+remaining.toLocaleString()+'</strong> যোগ করলে '+nextTier.label_bn+' পাবেন!</span>'
            : '<span style="display:inline-block;background:#16a34a;color:#fff;font-size:11px;padding:3px 12px;border-radius:9999px;font-weight:600">🎉 সব রিওয়ার্ড আনলক!</span>';
        html = '<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:'+pad+';border:1px solid #fed7aa">';
        html += '<div style="text-align:center;margin-bottom:'+gap+'">'+t6msg+'</div>';
        html += '<div style="position:relative;height:'+dotSz+'px;margin:0 16px"><div style="position:absolute;top:50%;left:0;right:0;transform:translateY(-50%);height:'+dtBarH+'px;background:'+cBg+';border-radius:9999px;overflow:hidden"><div style="height:100%;width:'+pct+'%;background:linear-gradient(90deg,'+cFrom+','+cTo+');border-radius:9999px;transition:width .5s ease;box-shadow:0 0 8px '+cFrom+'66"></div></div>'+dots+'</div>';
        html += '<div style="position:relative;height:28px;margin:'+Math.round(4*(1-s))+'px 16px 0">'+labels6+'</div>';
        html += '</div>';
    }
    
    document.getElementById('preview-container').innerHTML = html;
}

// Override updatePreview to use active bar when editor is closed
var _origUpdatePreview = updatePreview;
updatePreview = function() {
    var editorOpen = !document.getElementById('bar-editor').classList.contains('hidden');
    if (editorOpen) {
        _origUpdatePreview();
    } else if (activeBarData) {
        renderActiveBarPreview();
    }
};

updatePreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
