<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Select Sticker Template';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();

// Ensure table exists
try { $db->query("CREATE TABLE IF NOT EXISTS print_templates (id INT AUTO_INCREMENT PRIMARY KEY,template_key VARCHAR(100) NOT NULL UNIQUE,template_type ENUM('invoice','sticker') NOT NULL DEFAULT 'invoice',name VARCHAR(100) NOT NULL,description VARCHAR(255) DEFAULT '',base_template VARCHAR(100) NOT NULL DEFAULT 'inv_standard',is_builtin TINYINT(1) NOT NULL DEFAULT 0,config JSON DEFAULT NULL,created_by INT DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_type(template_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(\Throwable $e){}

// === AJAX Handlers ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'select') {
        $tpl = sanitize($_POST['template'] ?? '');
        if ($tpl) {
            $db->query("INSERT INTO site_settings (setting_key, setting_value) VALUES ('selected_sticker_template', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$tpl, $tpl]);
            if (function_exists('clearSettingsCache')) clearSettingsCache();
            echo json_encode(['success' => true]);
        } else { echo json_encode(['success' => false, 'error' => 'Invalid template']); }
        exit;
    }

    if ($action === 'save_preset') {
        $key = sanitize($_POST['template_key'] ?? '');
        $name = sanitize($_POST['name'] ?? '');
        $desc = sanitize($_POST['description'] ?? '');
        $base = sanitize($_POST['base_template'] ?? 'stk_standard');
        $config = $_POST['config'] ?? '{}';
        if (!$name) { echo json_encode(['success'=>false,'error'=>'Name is required']); exit; }
        if (!$key) $key = 'cust_stk_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name)) . '_' . time();
        $db->query("INSERT INTO print_templates (template_key, template_type, name, description, base_template, config, created_by)
            VALUES (?, 'sticker', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), base_template=VALUES(base_template), config=VALUES(config)",
            [$key, $name, $desc, $base, $config, $_SESSION['user_id'] ?? 0]);
        echo json_encode(['success' => true, 'key' => $key]);
        exit;
    }

    if ($action === 'delete_preset') {
        $key = sanitize($_POST['template_key'] ?? '');
        if ($key && strpos($key, 'cust_') === 0) {
            $db->query("DELETE FROM print_templates WHERE template_key = ? AND is_builtin = 0", [$key]);
            $cur = getSetting('selected_sticker_template', 'stk_standard');
            if ($cur === $key) {
                $db->query("INSERT INTO site_settings (setting_key, setting_value) VALUES ('selected_sticker_template', 'stk_standard') ON DUPLICATE KEY UPDATE setting_value = 'stk_standard'");
                if (function_exists('clearSettingsCache')) clearSettingsCache();
            }
            echo json_encode(['success' => true]);
        } else { echo json_encode(['success' => false, 'error' => 'Cannot delete built-in templates']); }
        exit;
    }

    if ($action === 'get_preset') {
        $key = sanitize($_POST['template_key'] ?? '');
        $row = $db->fetch("SELECT * FROM print_templates WHERE template_key = ?", [$key]);
        echo json_encode(['success' => !!$row, 'data' => $row]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}

$current = getSetting('selected_sticker_template', 'stk_standard');
$customPresets = $db->fetchAll("SELECT * FROM print_templates WHERE template_type = 'sticker' AND is_builtin = 0 AND template_key NOT LIKE '\\_preview\\_%' ORDER BY name") ?: [];

require_once __DIR__ . '/../includes/header.php';

$builtins = [
    'stk_standard' => ['name'=>'Standard Sticker','desc'=>'280px · Classic label','icon'=>'🏷️'],
    'stk_detailed' => ['name'=>'Detailed Sticker','desc'=>'300px · Product table','icon'=>'📋'],
    'stk_courier'  => ['name'=>'Courier Sticker','desc'=>'300px · Courier badge','icon'=>'🚚'],
    'stk_pos'      => ['name'=>'POS Receipt','desc'=>'80mm thermal · Receipt style','icon'=>'🧾'],
    'stk_cod'      => ['name'=>'COD Sticker','desc'=>'260px · Bold payment badge','icon'=>'💵'],
    'stk_wide'     => ['name'=>'Wide Sticker','desc'=>'360px · 3-inch label','icon'=>'↔️'],
    'stk_sku'      => ['name'=>'SKU Sticker','desc'=>'310px · SKU-based table','icon'=>'🔖'],
    'stk_note'     => ['name'=>'Note Sticker','desc'=>'300px · With order notes','icon'=>'📝'],
];
$baseOptions = ['stk_standard'=>'Standard Sticker','stk_detailed'=>'Detailed Sticker','stk_courier'=>'Courier Sticker','stk_pos'=>'POS Receipt','stk_cod'=>'COD Sticker','stk_wide'=>'Wide Sticker','stk_sku'=>'SKU Sticker','stk_note'=>'Note Sticker'];
?>
<style>
.tpl-card{transition:all .2s}.tpl-card:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,.1)}
.tpl-card.active{border-color:#0d9488!important;box-shadow:0 0 0 3px rgba(13,148,136,.2)}
.preview-wrap{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff}
.preview-wrap iframe{width:200%;height:200%;transform:scale(.5);transform-origin:top left;border:none;pointer-events:none}
.toggle-sw{position:relative;width:38px;height:20px;background:#d1d5db;border-radius:10px;cursor:pointer;transition:.2s;flex-shrink:0}
.toggle-sw.on{background:#0d9488}
.toggle-sw::after{content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.15)}
.toggle-sw.on::after{left:20px}
.cdot{width:24px;height:24px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:.15s;flex-shrink:0}
.cdot:hover,.cdot.act{border-color:#111;transform:scale(1.15)}
.builder-sec{border-bottom:1px solid #f3f4f6;padding-bottom:14px;margin-bottom:14px}
.builder-sec:last-child{border-bottom:none;margin-bottom:0}
.sec-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#9ca3af;font-weight:700;margin-bottom:8px;display:block}
</style>

<!-- Main Layout -->
<div class="flex gap-6" style="min-height:calc(100vh - 140px)">

<!-- LEFT: Template Cards -->
<div class="flex-1 min-w-0">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h2 class="text-lg font-bold text-gray-800">Sticker Templates</h2>
            <p class="text-xs text-gray-500 mt-0.5">Click to preview · Click "Set as Default" to activate</p>
        </div>
        <button onclick="openBuilder()" class="flex items-center gap-2 px-4 py-2.5 bg-teal-600 text-white rounded-xl text-sm font-semibold hover:bg-teal-700 shadow-md hover:shadow-lg transition-all">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Create Custom
        </button>
    </div>

    <!-- Built-in -->
    <p class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-3">Built-in Templates</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">
    <?php foreach ($builtins as $key => $bt): $isAct = ($current === $key); ?>
    <div class="tpl-card relative cursor-pointer rounded-xl border-2 bg-white overflow-hidden <?= $isAct ? 'active border-teal-500' : 'border-gray-200' ?>"
         data-key="<?= $key ?>" onclick="previewTpl('<?= $key ?>')">
        <?php if ($isAct): ?>
        <div class="ck-badge absolute top-2.5 right-2.5 z-10 w-6 h-6 bg-teal-500 rounded-full flex items-center justify-center shadow-md">
            <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
        </div>
        <?php endif; ?>
        <div class="p-3.5 flex items-start gap-3">
            <div class="text-xl mt-0.5"><?= $bt['icon'] ?></div>
            <div class="min-w-0"><h3 class="font-bold text-gray-800 text-sm truncate"><?= $bt['name'] ?></h3><p class="text-[11px] text-gray-400"><?= $bt['desc'] ?></p></div>
        </div>
        <div class="px-3.5 pb-3 flex gap-2">
            <button onclick="event.stopPropagation();selectTpl('<?= $key ?>')" class="sel-btn text-[11px] px-2.5 py-1 rounded-lg border border-gray-200 hover:bg-teal-50 hover:text-teal-600 hover:border-teal-200 transition-colors"><?= $isAct ? '✓ Selected' : 'Set as Default' ?></button>
            <button onclick="event.stopPropagation();previewTpl('<?= $key ?>')" class="text-[11px] px-2.5 py-1 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors">👁</button>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Custom Presets -->
    <div id="custSec" class="<?= empty($customPresets) ? 'hidden' : '' ?>">
        <p class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-3">Custom Presets</p>
        <div id="custGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">
        <?php foreach ($customPresets as $cp):
            $cpK = $cp['template_key']; $isAct = ($current === $cpK);
            $cpCfg = json_decode($cp['config'] ?? '{}', true) ?: [];
            $accent = $cpCfg['primary_color'] ?? '#0d9488';
        ?>
        <div class="tpl-card relative cursor-pointer rounded-xl border-2 bg-white overflow-hidden <?= $isAct ? 'active border-teal-500' : 'border-gray-200' ?>"
             data-key="<?= e($cpK) ?>" onclick="previewTpl('<?= e($cpK) ?>')">
            <?php if ($isAct): ?>
            <div class="ck-badge absolute top-2.5 right-2.5 z-10 w-6 h-6 bg-teal-500 rounded-full flex items-center justify-center shadow-md">
                <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
            </div>
            <?php endif; ?>
            <div class="p-3.5 flex items-start gap-3">
                <div class="w-7 h-7 rounded-lg flex-shrink-0 flex items-center justify-center text-white text-xs font-bold" style="background:<?= e($accent) ?>"><?= mb_strtoupper(mb_substr($cp['name'], 0, 1)) ?></div>
                <div class="min-w-0"><h3 class="font-bold text-gray-800 text-sm truncate"><?= e($cp['name']) ?></h3><p class="text-[11px] text-gray-400"><?= e($cp['description'] ?: 'Based on ' . ($baseOptions[$cp['base_template']] ?? $cp['base_template'])) ?></p></div>
            </div>
            <div class="px-3.5 pb-3 flex gap-2">
                <button onclick="event.stopPropagation();selectTpl('<?= e($cpK) ?>')" class="sel-btn text-[11px] px-2.5 py-1 rounded-lg border border-gray-200 hover:bg-teal-50 hover:text-teal-600 hover:border-teal-200 transition-colors"><?= $isAct ? '✓ Selected' : 'Set as Default' ?></button>
                <button onclick="event.stopPropagation();openBuilder('<?= e($cpK) ?>')" class="text-[11px] px-2.5 py-1 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors">✏️</button>
                <button onclick="event.stopPropagation();delPreset('<?= e($cpK) ?>','<?= e(addslashes($cp['name'])) ?>')" class="text-[11px] px-2.5 py-1 rounded-lg border border-red-200 text-red-500 hover:bg-red-50 transition-colors">🗑</button>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- RIGHT: Live Preview Panel -->
<div class="w-[380px] flex-shrink-0 sticky top-4 self-start">
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50">
            <div><p class="text-xs font-bold text-gray-700" id="pvTitle">Preview</p><p class="text-[10px] text-gray-400" id="pvSub">Select a template</p></div>
            <div class="flex gap-1.5">
                <button onclick="refreshPv()" class="w-7 h-7 rounded-lg border border-gray-200 bg-white flex items-center justify-center hover:bg-gray-50 text-gray-500" title="Refresh">🔄</button>
                <button onclick="window.open('order-print.php?preview=1&template='+encodeURIComponent(curPv),'_blank')" class="w-7 h-7 rounded-lg border border-gray-200 bg-white flex items-center justify-center hover:bg-gray-50 text-gray-500" title="Full size">🔗</button>
            </div>
        </div>
        <div class="preview-wrap relative" style="height:480px">
            <div id="pvLoad" class="absolute inset-0 bg-white/80 flex items-center justify-center z-10 hidden">
                <div class="flex items-center gap-2 text-sm text-gray-500"><svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg> Loading…</div>
            </div>
            <iframe id="pvFrame" src="order-print.php?preview=1&template=<?= urlencode($current) ?>" onload="document.getElementById('pvLoad').classList.add('hidden')" style="width:200%;height:200%;transform:scale(.5);transform-origin:top left;border:none;pointer-events:none"></iframe>
        </div>
    </div>
</div>
</div>

<!-- ================================ -->
<!-- BUILDER MODAL                    -->
<!-- ================================ -->
<div id="bldModal" class="hidden fixed inset-0 z-[9999] flex items-center justify-center bg-black/60" onclick="if(event.target===this)closeBld()">
<div class="bg-white rounded-2xl shadow-2xl w-[95vw] max-w-5xl max-h-[92vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-teal-50 to-emerald-50 flex-shrink-0">
        <div><h2 class="text-lg font-bold text-gray-800" id="bldTitle">Create Custom Sticker</h2><p class="text-xs text-gray-500">Customize colors, elements, width &amp; preview live</p></div>
        <button onclick="closeBld()" class="w-8 h-8 rounded-lg hover:bg-white/80 flex items-center justify-center text-gray-400 hover:text-gray-600 text-lg">✕</button>
    </div>

    <div class="flex flex-1 overflow-hidden">
        <!-- Config Panel -->
        <div class="w-[400px] flex-shrink-0 border-r border-gray-100 overflow-y-auto p-5">

            <div class="builder-sec">
                <span class="sec-label">Preset Info</span>
                <label class="text-xs text-gray-600 mb-1 block">Name *</label>
                <input type="text" id="bN" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-300 focus:border-teal-400 mb-2" placeholder="e.g. My COD Sticker" maxlength="100">
                <label class="text-xs text-gray-600 mb-1 block">Description</label>
                <input type="text" id="bD" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-300 focus:border-teal-400" placeholder="Optional" maxlength="255">
            </div>

            <div class="builder-sec">
                <span class="sec-label">Base Template</span>
                <select id="bBase" onchange="dbPv()" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-300">
                    <?php foreach($baseOptions as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
                </select>
            </div>

            <div class="builder-sec">
                <span class="sec-label">Sticker Width</span>
                <div class="flex items-center gap-3">
                    <input type="range" id="bSW" min="200" max="420" step="10" value="280" oninput="document.getElementById('swV').textContent=this.value+'px';bCfg.sticker_width=this.value;dbPv()" class="flex-1">
                    <span id="swV" class="text-xs text-gray-500 w-12 text-right">280px</span>
                </div>
                <div class="flex gap-2 mt-2">
                    <button onclick="document.getElementById('bSW').value=260;document.getElementById('swV').textContent='260px';bCfg.sticker_width='260';dbPv()" class="text-[10px] px-2 py-1 border rounded hover:bg-gray-50">260px</button>
                    <button onclick="document.getElementById('bSW').value=280;document.getElementById('swV').textContent='280px';bCfg.sticker_width='280';dbPv()" class="text-[10px] px-2 py-1 border rounded hover:bg-gray-50">280px</button>
                    <button onclick="document.getElementById('bSW').value=310;document.getElementById('swV').textContent='310px';bCfg.sticker_width='310';dbPv()" class="text-[10px] px-2 py-1 border rounded hover:bg-gray-50">310px</button>
                    <button onclick="document.getElementById('bSW').value=360;document.getElementById('swV').textContent='360px';bCfg.sticker_width='360';dbPv()" class="text-[10px] px-2 py-1 border rounded hover:bg-gray-50">360px (Wide)</button>
                </div>
            </div>

            <div class="builder-sec">
                <span class="sec-label">Colors</span>
                <label class="text-xs text-gray-600 mb-1.5 block">Primary Color</label>
                <div class="flex items-center gap-2 flex-wrap">
                    <?php foreach(['#000000','#2563eb','#059669','#dc2626','#7c3aed','#ea580c','#0891b2','#4f46e5','#be185d'] as $c): ?>
                    <div class="cdot" style="background:<?=$c?>" onclick="setClr('primary_color','<?=$c?>',this)"></div>
                    <?php endforeach; ?>
                    <input type="color" id="bClr1" value="#000000" onchange="setClr('primary_color',this.value)" class="w-7 h-7 rounded cursor-pointer border-0 p-0">
                </div>
            </div>

            <div class="builder-sec">
                <span class="sec-label">Font Size</span>
                <div class="flex items-center gap-3">
                    <input type="range" id="bFS" min="9" max="16" value="13" oninput="document.getElementById('fsV').textContent=this.value+'px';bCfg.font_size=this.value;dbPv()" class="flex-1">
                    <span id="fsV" class="text-xs text-gray-500 w-10 text-right">13px</span>
                </div>
            </div>

            <div class="builder-sec">
                <span class="sec-label">Show / Hide Elements</span>
                <div class="space-y-2">
                <?php
                $toggles = ['show_phone'=>['Phone Number',true],'show_barcode'=>['Order Barcode',true],'show_variant'=>['Variant Details',true],'show_courier'=>['Courier Name',true],'show_parcel'=>['Parcel / Tracking ID',true],'show_notes'=>['Order Notes',true],'show_shipping_note'=>['Shipping Note',true],'show_advance'=>['Advance Amount',true],'show_discount'=>['Discount',true],'show_sku'=>['SKU',false]];
                foreach($toggles as $tk=>[$tl,$td]): ?>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-700"><?=$tl?></span>
                    <div class="toggle-sw <?=$td?'on':''?>" data-key="<?=$tk?>" onclick="this.classList.toggle('on');bCfg[this.dataset.key]=this.classList.contains('on');dbPv()"></div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>

            <div class="builder-sec">
                <span class="sec-label">Custom Text</span>
                <label class="text-xs text-gray-600 mb-1 block">Shipping Note</label>
                <input type="text" id="bSN" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="e.g. Handle with care" oninput="bCfg.custom_shipping_note=this.value" maxlength="200">
            </div>
        </div>

        <!-- Live Preview -->
        <div class="flex-1 bg-gray-50 flex flex-col overflow-hidden">
            <div class="px-4 py-2 border-b border-gray-100 bg-white flex items-center justify-between flex-shrink-0">
                <p class="text-xs font-medium text-gray-500">📐 Live Preview (sample data)</p>
                <button onclick="updateBldPv()" class="text-[11px] px-3 py-1 rounded-lg border border-gray-200 hover:bg-gray-50">🔄 Refresh</button>
            </div>
            <div class="flex-1 overflow-hidden relative">
                <div id="bPvLoad" class="absolute inset-0 bg-white/80 flex items-center justify-center z-10 hidden">
                    <div class="flex items-center gap-2 text-sm text-gray-500"><svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg> Updating…</div>
                </div>
                <iframe id="bPvFrame" style="width:180%;height:180%;transform:scale(.556);transform-origin:top left;border:none;pointer-events:none" onload="document.getElementById('bPvLoad').classList.add('hidden')"></iframe>
            </div>
        </div>
    </div>

    <div class="px-6 py-3 border-t border-gray-100 flex items-center justify-between bg-gray-50 flex-shrink-0">
        <button onclick="closeBld()" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
        <div class="flex gap-3">
            <button onclick="updateBldPv()" class="px-4 py-2 text-sm border border-gray-300 rounded-xl hover:bg-white transition">🔄 Refresh Preview</button>
            <button onclick="savePreset()" class="px-6 py-2 text-sm bg-teal-600 text-white rounded-xl font-semibold hover:bg-teal-700 shadow-md transition">💾 Save Preset</button>
        </div>
    </div>
</div>
</div>

<script>
let curPv='<?= e($current) ?>',editKey=null,bCfg={},_dbT=null;

function previewTpl(key) {
    curPv=key;document.getElementById('pvLoad').classList.remove('hidden');
    document.getElementById('pvFrame').src='order-print.php?preview=1&template='+encodeURIComponent(key);
    const card=document.querySelector('[data-key="'+key+'"]');
    document.getElementById('pvTitle').textContent=card?card.querySelector('h3')?.textContent:key;
    document.getElementById('pvSub').textContent=key.startsWith('cust_')?'Custom preset':'Built-in template';
    document.querySelectorAll('.tpl-card').forEach(c=>c.style.background='');
    if(card)card.style.background='#f0fdfa';
}

function refreshPv(){document.getElementById('pvLoad').classList.remove('hidden');const f=document.getElementById('pvFrame');f.src=f.src;}

function selectTpl(key) {
    fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=select&template='+encodeURIComponent(key)})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            document.querySelectorAll('.tpl-card').forEach(c=>{
                c.classList.remove('active','border-teal-500');c.classList.add('border-gray-200');
                const cb=c.querySelector('.ck-badge');if(cb)cb.remove();
                c.querySelectorAll('.sel-btn').forEach(b=>{if(b.textContent.includes('Selected'))b.textContent='Set as Default';});
            });
            const card=document.querySelector('[data-key="'+key+'"]');
            if(card){card.classList.remove('border-gray-200');card.classList.add('active','border-teal-500');
                const b=document.createElement('div');b.className='ck-badge absolute top-2.5 right-2.5 z-10 w-6 h-6 bg-teal-500 rounded-full flex items-center justify-center shadow-md';
                b.innerHTML='<svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>';
                card.appendChild(b);card.querySelector('.sel-btn').textContent='✓ Selected';
            }
            toast('Sticker template saved!','green');previewTpl(key);
        }
    });
}

function delPreset(key,name){
    if(!confirm('Delete "'+name+'"? This cannot be undone.'))return;
    fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=delete_preset&template_key='+encodeURIComponent(key)})
    .then(r=>r.json()).then(d=>{
        if(d.success){const card=document.querySelector('[data-key="'+key+'"]');
            if(card){card.style.transition='all .3s';card.style.opacity='0';card.style.transform='scale(.95)';
                setTimeout(()=>{card.remove();if(!document.querySelector('#custGrid .tpl-card'))document.getElementById('custSec').classList.add('hidden');},300);}
            toast('Preset deleted','red');
        }
    });
}

function openBuilder(eKey){
    editKey=eKey||null;
    bCfg={primary_color:'#000000',font_size:'13',sticker_width:'280',show_phone:true,show_barcode:true,show_variant:true,show_courier:true,show_parcel:true,show_notes:true,show_shipping_note:true,show_advance:true,show_discount:true,show_sku:false,custom_shipping_note:''};
    document.getElementById('bN').value='';document.getElementById('bD').value='';document.getElementById('bBase').value='stk_standard';
    document.getElementById('bClr1').value='#000000';document.getElementById('bFS').value='13';document.getElementById('fsV').textContent='13px';
    document.getElementById('bSW').value='280';document.getElementById('swV').textContent='280px';document.getElementById('bSN').value='';
    resetToggles();document.querySelectorAll('.cdot').forEach(d=>d.classList.remove('act'));

    if(eKey){
        document.getElementById('bldTitle').textContent='Edit Custom Sticker';
        fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=get_preset&template_key='+encodeURIComponent(eKey)})
        .then(r=>r.json()).then(d=>{
            if(d.success&&d.data){const p=d.data,cfg=JSON.parse(p.config||'{}');
                document.getElementById('bN').value=p.name||'';document.getElementById('bD').value=p.description||'';
                document.getElementById('bBase').value=p.base_template||'stk_standard';bCfg={...bCfg,...cfg};
                if(cfg.primary_color)document.getElementById('bClr1').value=cfg.primary_color;
                if(cfg.font_size){document.getElementById('bFS').value=cfg.font_size;document.getElementById('fsV').textContent=cfg.font_size+'px';}
                if(cfg.sticker_width){document.getElementById('bSW').value=cfg.sticker_width;document.getElementById('swV').textContent=cfg.sticker_width+'px';}
                if(cfg.custom_shipping_note)document.getElementById('bSN').value=cfg.custom_shipping_note;
                document.querySelectorAll('.toggle-sw').forEach(ts=>{const k=ts.dataset.key;if(k in cfg){cfg[k]?ts.classList.add('on'):ts.classList.remove('on');}});
                updateBldPv();
            }
        });
    } else { document.getElementById('bldTitle').textContent='Create Custom Sticker'; }
    document.getElementById('bldModal').classList.remove('hidden');
    setTimeout(()=>updateBldPv(),150);
}

function closeBld(){
    document.getElementById('bldModal').classList.add('hidden');document.getElementById('bPvFrame').src='about:blank';editKey=null;
    fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=delete_preset&template_key=cust__preview_tmp_stk'}).catch(()=>{});
}

function resetToggles(){
    const defs={show_phone:true,show_barcode:true,show_variant:true,show_courier:true,show_parcel:true,show_notes:true,show_shipping_note:true,show_advance:true,show_discount:true,show_sku:false};
    document.querySelectorAll('.toggle-sw').forEach(ts=>{defs[ts.dataset.key]?ts.classList.add('on'):ts.classList.remove('on');});
}

function setClr(field,val,dot){bCfg[field]=val;if(dot){document.querySelectorAll('.cdot').forEach(d=>d.classList.remove('act'));dot.classList.add('act');}if(val)document.getElementById('bClr1').value=val;dbPv();}
function dbPv(){clearTimeout(_dbT);_dbT=setTimeout(updateBldPv,500);}

function updateBldPv(){
    bCfg.primary_color=document.getElementById('bClr1').value;
    bCfg.font_size=document.getElementById('bFS').value;
    bCfg.sticker_width=document.getElementById('bSW').value;
    bCfg.custom_shipping_note=document.getElementById('bSN').value;
    document.querySelectorAll('.toggle-sw').forEach(ts=>{bCfg[ts.dataset.key]=ts.classList.contains('on');});
    const base=document.getElementById('bBase').value;
    const tmpK='cust__preview_tmp_stk';
    const fd=new URLSearchParams();
    fd.set('action','save_preset');fd.set('template_key',tmpK);fd.set('name','_tmp');fd.set('description','');
    fd.set('base_template',base);fd.set('config',JSON.stringify(bCfg));
    document.getElementById('bPvLoad').classList.remove('hidden');
    fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd.toString()})
    .then(r=>r.json()).then(d=>{if(d.success)document.getElementById('bPvFrame').src='order-print.php?preview=1&template='+encodeURIComponent(d.key||tmpK);});
}

function savePreset(){
    const name=document.getElementById('bN').value.trim();
    if(!name){alert('Please enter a name');document.getElementById('bN').focus();return;}
    bCfg.primary_color=document.getElementById('bClr1').value;
    bCfg.font_size=document.getElementById('bFS').value;
    bCfg.sticker_width=document.getElementById('bSW').value;
    bCfg.custom_shipping_note=document.getElementById('bSN').value;
    document.querySelectorAll('.toggle-sw').forEach(ts=>{bCfg[ts.dataset.key]=ts.classList.contains('on');});
    const fd=new URLSearchParams();
    fd.set('action','save_preset');fd.set('template_key',editKey||'');fd.set('name',name);
    fd.set('description',document.getElementById('bD').value.trim());
    fd.set('base_template',document.getElementById('bBase').value);
    fd.set('config',JSON.stringify(bCfg));
    fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd.toString()})
    .then(r=>r.json()).then(d=>{
        if(d.success){toast(editKey?'Preset updated!':'Custom preset created!','green');closeBld();setTimeout(()=>location.reload(),400);}
        else alert(d.error||'Error saving');
    });
}

function toast(msg,clr){
    const t=document.createElement('div');t.className='fixed bottom-6 right-6 '+(clr==='red'?'bg-red-600':'bg-teal-600')+' text-white px-5 py-3 rounded-xl shadow-2xl text-sm font-medium z-[99999] flex items-center gap-2';
    t.innerHTML='<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> '+msg;
    document.body.appendChild(t);setTimeout(()=>t.remove(),2500);
}

document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!document.getElementById('bldModal').classList.contains('hidden'))closeBld();});
previewTpl('<?= e($current) ?>');
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
