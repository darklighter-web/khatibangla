<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Select Sticker';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();

try { $db->query("CREATE TABLE IF NOT EXISTS print_templates (id INT AUTO_INCREMENT PRIMARY KEY,template_key VARCHAR(100) NOT NULL UNIQUE,template_type ENUM('invoice','sticker') NOT NULL DEFAULT 'invoice',name VARCHAR(100) NOT NULL,description VARCHAR(255) DEFAULT '',base_template VARCHAR(100) NOT NULL DEFAULT 'stk_standard',is_builtin TINYINT(1) NOT NULL DEFAULT 0,config JSON DEFAULT NULL,created_by INT DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_type(template_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(\Throwable $e){}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    if ($action === 'select') {
        $tpl = sanitize($_POST['template'] ?? '');
        if ($tpl) { $db->query("INSERT INTO site_settings (setting_key,setting_value) VALUES ('selected_sticker_template',?) ON DUPLICATE KEY UPDATE setting_value=?",[$tpl,$tpl]); if(function_exists('clearSettingsCache'))clearSettingsCache(); echo json_encode(['success'=>true]); } else { echo json_encode(['success'=>false]); } exit;
    }
    if ($action === 'save_preset') {
        $key=$_POST['template_key']??''; $name=sanitize($_POST['name']??''); $desc=sanitize($_POST['description']??''); $base=sanitize($_POST['base_template']??'stk_standard'); $cfg=$_POST['config']??'{}';
        if(!$name){echo json_encode(['success'=>false,'error'=>'Name required']);exit;}
        if(!$key)$key='cust_stk_'.strtolower(preg_replace('/[^a-zA-Z0-9]/','_',$name)).'_'.time();
        $db->query("INSERT INTO print_templates (template_key,template_type,name,description,base_template,config,created_by) VALUES (?,'sticker',?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),base_template=VALUES(base_template),config=VALUES(config)",[$key,$name,$desc,$base,$cfg,$_SESSION['user_id']??0]);
        echo json_encode(['success'=>true,'key'=>$key]); exit;
    }
    if ($action === 'delete_preset') {
        $key=sanitize($_POST['template_key']??'');
        if($key&&strpos($key,'cust_')===0){$db->query("DELETE FROM print_templates WHERE template_key=? AND is_builtin=0",[$key]);$cur=getSetting('selected_sticker_template','stk_standard');if($cur===$key){$db->query("INSERT INTO site_settings (setting_key,setting_value) VALUES ('selected_sticker_template','stk_standard') ON DUPLICATE KEY UPDATE setting_value='stk_standard'");if(function_exists('clearSettingsCache'))clearSettingsCache();}echo json_encode(['success'=>true]);}
        else{echo json_encode(['success'=>false,'error'=>'Cannot delete built-in']);}exit;
    }
    if ($action === 'get_preset') {
        $key=sanitize($_POST['template_key']??''); $row=$db->fetch("SELECT * FROM print_templates WHERE template_key=?",[$key]); echo json_encode(['success'=>!!$row,'data'=>$row]); exit;
    }
    echo json_encode(['success'=>false]); exit;
}

$current      = getSetting('selected_sticker_template','stk_standard');
$customPresets= $db->fetchAll("SELECT * FROM print_templates WHERE template_type='sticker' AND is_builtin=0 AND template_key NOT LIKE '\\_preview\\_%' ORDER BY name") ?: [];
require_once __DIR__ . '/../includes/header.php';

$builtins = [
    'stk_compact'    =>['name'=>'Sticker 1',           'dim'=>'2 inch width',            'desc'=>'Compact 2-inch sticker'],
    'stk_detailed'   =>['name'=>'Sticker 2',           'dim'=>'3 inch · product table',  'desc'=>'Detailed with product table'],
    'stk_pos'        =>['name'=>'POS Sticker',         'dim'=>'80mm thermal receipt',    'desc'=>'POS receipt style'],
    'stk_note'       =>['name'=>'Sticker 4',           'dim'=>'With order note',         'desc'=>'Standard + order note'],
    'stk_standard'   =>['name'=>'Sticker 5',           'dim'=>'Classic · no barcode',    'desc'=>'Standard sticker label'],
    'stk_thermal'    =>['name'=>'Sticker 7',           'dim'=>'75mm × 50mm',             'desc'=>'Thermal with barcode'],
    'stk_thermal_m'  =>['name'=>'Sticker 8 Multi Page','dim'=>'75mm × 50mm',             'desc'=>'Multi-page supported'],
    'stk_thermal_sku'=>['name'=>'Sticker 9 Enhanced',  'dim'=>'75mm × 50mm · SKU',       'desc'=>'SKU & shipping note'],
    'stk_2in'        =>['name'=>'Sticker 10 · 2 inch', 'dim'=>'2 inch · auto height',    'desc'=>'2 inch unlimited height'],
    'stk_3in'        =>['name'=>'Sticker 11 · 3 inch', 'dim'=>'3 inch · auto height',    'desc'=>'3 inch unlimited height'],
    'stk_cod_t'      =>['name'=>'Sticker 12 COD',      'dim'=>'75mm × 50mm · COD',       'desc'=>'Big COD badge sticker'],
    'stk_courier'    =>['name'=>'Sticker 13 Courier',  'dim'=>'Courier badge',           'desc'=>'Courier-focused layout'],
    'stk_4x3'        =>['name'=>'Sticker 14 · 4×3',    'dim'=>'4×3 inch · SKU rows',     'desc'=>'Bold phone + SKU rows'],
    'stk_3in_note'   =>['name'=>'Sticker 15 · 3 inch', 'dim'=>'3 inch + order & ship note','desc'=>'Full notes sticker'],
    'stk_3sq'        =>['name'=>'Sticker 16 · 3×3',    'dim'=>'3×3 inch square',         'desc'=>'Square · SKU rows'],
    'stk_wide'       =>['name'=>'Sticker Wide',        'dim'=>'3.5 inch wide',           'desc'=>'Wide format label'],
    'stk_sku'        =>['name'=>'Sticker SKU',         'dim'=>'SKU table',               'desc'=>'SKU-based product table'],
    'stk_cod'        =>['name'=>'Sticker COD Bold',    'dim'=>'Bold COD header',         'desc'=>'COD-focused layout'],
    'stk_mini'       =>['name'=>'Sticker 17 · 38×25mm','dim'=>'38×25mm mini',            'desc'=>'Tiny: barcode + parcel + COD'],
];
$baseOptions=[
    'stk_compact'=>'Sticker 1 (2 inch)','stk_detailed'=>'Sticker 2 (Detailed)','stk_pos'=>'POS Sticker','stk_note'=>'Sticker 4 (With Note)',
    'stk_standard'=>'Sticker 5 (Standard)','stk_thermal'=>'Sticker 7 (Thermal 75mm)','stk_thermal_m'=>'Sticker 8 (Multi-Page)',
    'stk_thermal_sku'=>'Sticker 9 (Thermal SKU)','stk_2in'=>'Sticker 10 (2 inch)','stk_3in'=>'Sticker 11 (3 inch)',
    'stk_cod_t'=>'Sticker 12 (COD Thermal)','stk_courier'=>'Sticker 13 (Courier)','stk_4x3'=>'Sticker 14 (4×3 SKU)',
    'stk_3in_note'=>'Sticker 15 (3 inch + Note)','stk_3sq'=>'Sticker 16 (3×3 Square)',
    'stk_wide'=>'Sticker Wide','stk_sku'=>'Sticker SKU','stk_cod'=>'Sticker COD','stk_mini'=>'Sticker 17 (38×25mm)',
];
?>
<style>
.stk-card{border-radius:14px;border:2px solid #e5e7eb;background:#fff;transition:all .2s;cursor:pointer;overflow:hidden;position:relative}
.stk-card:hover{border-color:#6366f1;box-shadow:0 6px 20px rgba(99,102,241,.15);transform:translateY(-2px)}
.stk-card.active{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.2)}
.stk-prev-thumb{background:#f8f9fa;border-bottom:1px solid #e5e7eb;overflow:hidden;position:relative;height:200px}
.stk-prev-thumb iframe{width:260%;height:260%;transform:scale(.385);transform-origin:top left;border:none;pointer-events:none}
.pv-load{position:absolute;inset:0;background:#f8f9fa;display:flex;align-items:center;justify-content:center}
.stk-check{position:absolute;top:10px;right:10px;z-index:10;background:#6366f1;color:#fff;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(99,102,241,.4)}
.stk-info{padding:12px 14px 8px}
.stk-title{font-weight:800;font-size:13px;color:#111}
.stk-dim{font-size:10px;color:#6366f1;font-weight:600;margin-top:2px}
.stk-desc{font-size:10px;color:#9ca3af;margin-top:1px}
.stk-actions{display:flex;gap:6px;padding:0 14px 12px}
.stk-btn{flex:1;text-align:center;font-size:11px;font-weight:600;padding:6px 4px;border-radius:8px;border:1px solid;cursor:pointer;transition:.15s;background:#fff}
.stk-btn-select{border-color:#6366f1;color:#6366f1}
.stk-btn-select:hover,.stk-btn-select.sel{background:#6366f1;color:#fff}
.stk-btn-edit{border-color:#e5e7eb;color:#6b7280}
.stk-btn-edit:hover{border-color:#6366f1;color:#6366f1;background:#eef2ff}
.stk-btn-del{border-color:#fca5a5;color:#dc2626}
.stk-btn-del:hover{background:#fef2f2}
.sec-lbl{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.sec-lbl::after{content:'';flex:1;height:1px;background:#e5e7eb}
.pv-panel{background:#fff;border-radius:16px;border:1px solid #e5e7eb;overflow:hidden}
.pv-frame-wrap{height:500px;overflow:hidden;position:relative;background:#f8f9fa}
.pv-frame-wrap iframe{width:200%;height:200%;transform:scale(.5);transform-origin:top left;border:none;pointer-events:none}
.bld-sec{border-bottom:1px solid #f3f4f6;padding-bottom:14px;margin-bottom:14px}
.bld-sec:last-child{border-bottom:none;margin:0;padding:0}
.bld-lbl{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;font-weight:700;margin-bottom:8px;display:block}
.toggle-sw{position:relative;width:38px;height:22px;background:#d1d5db;border-radius:11px;cursor:pointer;transition:.2s;flex-shrink:0}
.toggle-sw.on{background:#6366f1}
.toggle-sw::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.toggle-sw.on::after{left:19px}
.cdot{width:24px;height:24px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:.15s;flex-shrink:0}
.cdot:hover,.cdot.act{border-color:#111;transform:scale(1.15)}
.note-row{background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;padding:8px 12px}
</style>

<div class="flex gap-5" style="min-height:calc(100vh - 160px)">

<!-- LEFT -->
<div class="flex-1 min-w-0 space-y-6">
<div class="flex items-center justify-between">
    <div><h2 class="text-lg font-bold text-gray-800">Select Sticker</h2><p class="text-xs text-gray-400 mt-0.5">Tap a style to preview · Set Default to activate</p></div>
    <button onclick="openBuilder()" class="flex items-center gap-2 px-4 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-700 shadow-md transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>Create Custom
    </button>
</div>

<div>
<p class="sec-lbl">Built-in Templates</p>
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
<?php foreach($builtins as $key=>$bt): $isAct=($current===$key); ?>
<div class="stk-card <?=$isAct?'active':''?>" data-key="<?=e($key)?>" data-name="<?=e($bt['name'])?>" onclick="stkPreview('<?=e($key)?>')">
    <?php if($isAct):?><div class="stk-check"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></div><?php endif;?>
    <div class="stk-prev-thumb">
        <div class="pv-load"><svg class="w-5 h-5 text-gray-300 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></div>
        <iframe data-src="order-print.php?preview=1&template=<?=urlencode($key)?>" onload="this.previousElementSibling.style.display='none'"></iframe>
    </div>
    <div class="stk-info">
        <div class="stk-title"><?=e($bt['name'])?></div>
        <div class="stk-dim"><?=e($bt['dim'])?></div>
        <div class="stk-desc"><?=e($bt['desc'])?></div>
    </div>
    <div class="stk-actions">
        <button class="stk-btn stk-btn-select <?=$isAct?'sel':''?>" onclick="event.stopPropagation();stkSelect('<?=e($key)?>')"><?=$isAct?'✓ Default':'Set Default'?></button>
        <button class="stk-btn stk-btn-edit" onclick="event.stopPropagation();openBuilder('<?=e($key)?>', true)" title="Customize this template">✏ Edit</button>
    </div>
</div>
<?php endforeach;?>
</div>
</div>

<div id="custSec" class="<?=empty($customPresets)?'hidden':''?>">
<p class="sec-lbl">Custom Presets</p>
<div id="custGrid" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
<?php foreach($customPresets as $cp):
    $cpK=$cp['template_key'];$isAct=($current===$cpK);
    $cpCfg=json_decode($cp['config']??'{}',true)?:[];$accent=$cpCfg['primary_color']??'#6366f1';?>
<div class="stk-card <?=$isAct?'active':''?>" data-key="<?=e($cpK)?>" data-name="<?=e($cp['name'])?>" onclick="stkPreview('<?=e($cpK)?>')">
    <?php if($isAct):?><div class="stk-check"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></div><?php endif;?>
    <div class="stk-prev-thumb">
        <div class="pv-load"><svg class="w-5 h-5 text-gray-300 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></div>
        <iframe data-src="order-print.php?preview=1&template=<?=urlencode($cpK)?>" onload="this.previousElementSibling.style.display='none'"></iframe>
    </div>
    <div class="stk-info">
        <div class="flex items-center gap-2"><div class="w-5 h-5 rounded flex-shrink-0 text-white text-[9px] font-bold flex items-center justify-center" style="background:<?=e($accent)?>"><?=mb_strtoupper(mb_substr($cp['name'],0,1))?></div><div class="stk-title truncate"><?=e($cp['name'])?></div></div>
        <div class="stk-dim"><?=e($baseOptions[$cp['base_template']]??$cp['base_template'])?></div>
        <div class="stk-desc"><?=e($cp['description']?:'Custom preset')?></div>
    </div>
    <div class="stk-actions">
        <button class="stk-btn stk-btn-select <?=$isAct?'sel':''?>" onclick="event.stopPropagation();stkSelect('<?=e($cpK)?>')"><?=$isAct?'✓ Default':'Set Default'?></button>
        <button class="stk-btn stk-btn-edit" onclick="event.stopPropagation();openBuilder('<?=e($cpK)?>')">✏</button>
        <button class="stk-btn stk-btn-del" onclick="event.stopPropagation();delPreset('<?=e($cpK)?>','<?=e(addslashes($cp['name']))?>')">🗑</button>
    </div>
</div>
<?php endforeach;?>
</div>
</div>
</div>

<!-- RIGHT -->
<div class="w-[360px] flex-shrink-0 sticky top-4 self-start">
<div class="pv-panel shadow-sm">
    <div class="px-4 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <div><p class="text-xs font-bold text-gray-700" id="pvTitle">Preview</p><p class="text-[10px] text-gray-400 mt-0.5" id="pvSub">Select a template</p></div>
        <div class="flex gap-1.5">
            <button onclick="refreshPv()" class="w-7 h-7 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 flex items-center justify-center text-sm" title="Refresh">🔄</button>
            <button onclick="window.open('order-print.php?preview=1&template='+encodeURIComponent(curPv),'_blank')" class="w-7 h-7 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 flex items-center justify-center text-sm" title="Full size">🔗</button>
        </div>
    </div>
    <div class="pv-frame-wrap relative">
        <div id="pvLoad" class="absolute inset-0 bg-gray-50/80 flex items-center justify-center z-10 hidden">
            <svg class="w-5 h-5 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
        </div>
        <iframe id="pvFrame" src="order-print.php?preview=1&template=<?=urlencode($current)?>" onload="document.getElementById('pvLoad').classList.add('hidden')"></iframe>
    </div>
</div>
</div>
</div>

<!-- BUILDER MODAL -->
<div id="bldModal" class="hidden fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 backdrop-blur-sm" onclick="if(event.target===this)closeBld()">
<div class="bg-white rounded-2xl shadow-2xl w-[95vw] max-w-5xl max-h-[92vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-indigo-50 to-purple-50 flex-shrink-0">
        <div><h2 class="text-base font-bold text-gray-800" id="bldTitle">Customize Sticker</h2><p class="text-[11px] text-gray-400 mt-0.5" id="bldSub">Saves as a new custom preset</p></div>
        <button onclick="closeBld()" class="w-8 h-8 rounded-lg hover:bg-white/80 flex items-center justify-center text-gray-400 hover:text-gray-700 text-lg">✕</button>
    </div>
    <div class="flex flex-1 overflow-hidden">
        <!-- Config -->
        <div class="w-[380px] flex-shrink-0 border-r border-gray-100 overflow-y-auto p-5">
            <div class="bld-sec">
                <span class="bld-lbl">Preset Name</span>
                <input id="bN" type="text" placeholder="e.g. My 3-inch Sticker" maxlength="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 mb-2 focus:border-indigo-400">
                <input id="bD" type="text" placeholder="Description (optional)" maxlength="255" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
            </div>
            <div class="bld-sec">
                <span class="bld-lbl">Base Template</span>
                <select id="bBase" onchange="dbPv()" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-300">
                    <?php foreach($baseOptions as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach;?>
                </select>
            </div>
            <div class="bld-sec">
                <span class="bld-lbl">Sticker Width</span>
                <div class="flex items-center gap-3">
                    <input id="bSW" type="range" min="144" max="420" step="8" value="288" oninput="document.getElementById('swV').textContent=this.value+'px';bCfg.sticker_width=this.value;dbPv()" class="flex-1">
                    <span id="swV" class="text-xs text-gray-500 w-14 text-right font-mono">288px</span>
                </div>
                <div class="flex flex-wrap gap-1.5 mt-2">
                    <button onclick="setBW(192)" class="text-[10px] px-2 py-1 border border-gray-200 rounded hover:bg-gray-50">2 inch</button>
                    <button onclick="setBW(288)" class="text-[10px] px-2 py-1 border border-gray-200 rounded hover:bg-gray-50">3 inch</button>
                    <button onclick="setBW(360)" class="text-[10px] px-2 py-1 border border-gray-200 rounded hover:bg-gray-50">Wide</button>
                    <button onclick="setBW(280)" class="text-[10px] px-2 py-1 border border-gray-200 rounded hover:bg-gray-50">75mm</button>
                    <button onclick="setBW(302)" class="text-[10px] px-2 py-1 border border-gray-200 rounded hover:bg-gray-50">80mm</button>
                </div>
            </div>
            <div class="bld-sec">
                <span class="bld-lbl">Primary Color</span>
                <div class="flex items-center gap-2 flex-wrap">
                    <?php foreach(['#000000','#1d4ed8','#059669','#dc2626','#7c3aed','#ea580c','#0891b2','#be185d'] as $cl):?>
                    <div class="cdot" style="background:<?=$cl?>" onclick="setClr('<?=$cl?>',this)"></div>
                    <?php endforeach;?>
                    <input id="bClr" type="color" value="#000000" onchange="setClr(this.value)" class="w-7 h-7 rounded cursor-pointer border-0 p-0">
                </div>
            </div>
            <div class="bld-sec">
                <span class="bld-lbl">Font Size</span>
                <div class="flex items-center gap-3">
                    <input id="bFS" type="range" min="9" max="16" value="12" oninput="document.getElementById('fsV').textContent=this.value+'px';bCfg.font_size=this.value;dbPv()" class="flex-1">
                    <span id="fsV" class="text-xs text-gray-500 w-10 text-right font-mono">12px</span>
                </div>
            </div>

            <!-- Bold text toggle -->
            <div class="bld-sec">
                <span class="bld-lbl">Text Style</span>
                <div class="flex items-center justify-between p-3 rounded-xl border border-gray-100 bg-gray-50">
                    <div>
                        <p class="text-sm font-semibold text-gray-700">Bold All Text</p>
                        <p class="text-[10px] text-gray-400">Make all sticker text bold for easier reading</p>
                    </div>
                    <div class="toggle-sw" data-key="bold_text" onclick="this.classList.toggle('on');bCfg[this.dataset.key]=this.classList.contains('on');dbPv()"></div>
                </div>
            </div>

            <!-- ★ Notes — highlighted section -->
            <div class="bld-sec">
                <span class="bld-lbl">📝 Order & Shipping Notes</span>
                <div class="space-y-2">
                    <div class="note-row flex items-center justify-between">
                        <div><p class="text-sm font-semibold text-violet-800">Order Note</p><p class="text-[10px] text-violet-400">Customer note printed on sticker</p></div>
                        <div class="toggle-sw on" data-key="show_notes" onclick="this.classList.toggle('on');bCfg[this.dataset.key]=this.classList.contains('on');dbPv()"></div>
                    </div>
                    <div class="note-row flex items-center justify-between">
                        <div><p class="text-sm font-semibold text-violet-800">Shipping Note</p><p class="text-[10px] text-violet-400">Delivery instructions on sticker</p></div>
                        <div class="toggle-sw on" data-key="show_shipping_note" onclick="this.classList.toggle('on');bCfg[this.dataset.key]=this.classList.contains('on');dbPv()"></div>
                    </div>
                    <div class="mt-2">
                        <label class="text-xs text-gray-600 mb-1 block font-medium">Custom Shipping Note Text</label>
                        <input id="bSN" type="text" placeholder="e.g. Handle with care · Don't bend"
                            class="w-full border border-violet-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-violet-300"
                            oninput="bCfg.custom_shipping_note=this.value;dbPv()" maxlength="200">
                    </div>
                </div>
            </div>

            <!-- Other toggles -->
            <div class="bld-sec">
                <span class="bld-lbl">Show / Hide Elements</span>
                <div class="space-y-2">
                <?php foreach(['show_phone'=>['Phone Number',true],'show_barcode'=>['Order Barcode',true],'show_variant'=>['Variant Details',true],'show_courier'=>['Courier Name',true],'show_parcel'=>['Parcel / Tracking ID',true],'show_advance'=>['Advance Amount',true],'show_discount'=>['Discount',true],'show_sku'=>['SKU Code',false]] as $tk=>[$tl,$td]):?>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-700"><?=$tl?></span>
                    <div class="toggle-sw <?=$td?'on':''?>" data-key="<?=$tk?>" onclick="this.classList.toggle('on');bCfg[this.dataset.key]=this.classList.contains('on');dbPv()"></div>
                </div>
                <?php endforeach;?>
                </div>
            </div>
        </div>

        <!-- Live preview -->
        <div class="flex-1 bg-gray-50 flex flex-col overflow-hidden">
            <div class="px-4 py-2 bg-white border-b border-gray-100 flex items-center justify-between flex-shrink-0">
                <p class="text-xs font-medium text-gray-500">📐 Live Preview</p>
                <button onclick="updateBldPv()" class="text-[11px] px-3 py-1 rounded-lg border border-gray-200 hover:bg-gray-50">🔄 Refresh</button>
            </div>
            <div class="flex-1 overflow-hidden relative">
                <div id="bPvLoad" class="absolute inset-0 bg-white/70 flex items-center justify-center z-10 hidden">
                    <svg class="w-5 h-5 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                </div>
                <iframe id="bPvFrame" style="width:180%;height:180%;transform:scale(.556);transform-origin:top left;border:none;pointer-events:none" onload="document.getElementById('bPvLoad').classList.add('hidden')"></iframe>
            </div>
        </div>
    </div>
    <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between flex-shrink-0">
        <button onclick="closeBld()" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">Cancel</button>
        <div class="flex gap-3">
            <button onclick="updateBldPv()" class="px-4 py-2 text-sm border border-gray-200 rounded-xl hover:bg-white transition">🔄 Refresh</button>
            <button onclick="savePreset()" class="px-6 py-2 text-sm bg-indigo-600 text-white rounded-xl font-semibold hover:bg-indigo-700 shadow-md transition">💾 Save Preset</button>
        </div>
    </div>
</div>
</div>

<script>
'use strict';
var curPv='<?=e($current)?>',editKey=null,bCfg={},_dbT=null;

// Lazy-load card iframes
(function(){
    var ob=new IntersectionObserver(function(entries){entries.forEach(function(e){if(e.isIntersecting){var fr=e.target,src=fr.getAttribute('data-src');if(src){fr.src=src;fr.removeAttribute('data-src');}ob.unobserve(fr);}});},{rootMargin:'200px'});
    document.querySelectorAll('iframe[data-src]').forEach(function(fr){ob.observe(fr);});
})();

function stkPreview(key){
    curPv=key;document.getElementById('pvLoad').classList.remove('hidden');
    document.getElementById('pvFrame').src='order-print.php?preview=1&template='+encodeURIComponent(key);
    var card=document.querySelector('[data-key="'+key+'"]');
    document.getElementById('pvTitle').textContent=card?card.dataset.name:key;
    document.getElementById('pvSub').textContent=key.startsWith('cust_')?'Custom preset':'Built-in template';
}
function refreshPv(){document.getElementById('pvLoad').classList.remove('hidden');var f=document.getElementById('pvFrame');f.src=f.src;}

function stkSelect(key){
    fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=select&template='+encodeURIComponent(key)})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success)return;
        document.querySelectorAll('.stk-card').forEach(function(c){
            c.classList.remove('active');var ck=c.querySelector('.stk-check');if(ck)ck.remove();
            c.querySelectorAll('.stk-btn-select').forEach(function(b){b.classList.remove('sel');b.textContent='Set Default';});
        });
        var card=document.querySelector('[data-key="'+key+'"]');
        if(card){card.classList.add('active');var ck=document.createElement('div');ck.className='stk-check';ck.innerHTML='<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>';card.appendChild(ck);var sb=card.querySelector('.stk-btn-select');if(sb){sb.classList.add('sel');sb.textContent='✓ Default';}}
        stkPreview(key);stkToast('Template set as default!','indigo');
    });
}

function delPreset(key,name){
    if(!confirm('Delete "'+name+'"?'))return;
    fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=delete_preset&template_key='+encodeURIComponent(key)})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){var c=document.querySelector('[data-key="'+key+'"]');if(c){c.style.transition='all .3s';c.style.opacity='0';c.style.transform='scale(.9)';setTimeout(function(){c.remove();if(!document.querySelector('#custGrid .stk-card'))document.getElementById('custSec').classList.add('hidden');},300);}stkToast('Deleted','red');}
    });
}

function openBuilder(eKey,isBuiltin){
    editKey=eKey&&!isBuiltin?eKey:null;
    bCfg={primary_color:'#000000',font_size:'12',sticker_width:'288',
        show_phone:true,show_barcode:true,show_variant:true,show_courier:true,
        show_parcel:true,show_notes:true,show_shipping_note:true,
        show_advance:true,show_discount:true,show_sku:false,
        bold_text:false,custom_shipping_note:''};
    document.getElementById('bN').value='';document.getElementById('bD').value='';document.getElementById('bClr').value='#000000';
    setBW(288,true);document.getElementById('bFS').value='12';document.getElementById('fsV').textContent='12px';document.getElementById('bSN').value='';
    resetToggles();document.querySelectorAll('.cdot').forEach(function(d){d.classList.remove('act');});

    if(eKey&&!isBuiltin){
        document.getElementById('bldTitle').textContent='Edit Custom Sticker';
        document.getElementById('bldSub').textContent='Editing: '+eKey;
        fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=get_preset&template_key='+encodeURIComponent(eKey)})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success&&d.data){var p=d.data,cfg=JSON.parse(p.config||'{}');
                document.getElementById('bN').value=p.name||'';document.getElementById('bD').value=p.description||'';
                document.getElementById('bBase').value=p.base_template||'stk_standard';bCfg=Object.assign(bCfg,cfg);
                if(cfg.primary_color)document.getElementById('bClr').value=cfg.primary_color;
                if(cfg.font_size){document.getElementById('bFS').value=cfg.font_size;document.getElementById('fsV').textContent=cfg.font_size+'px';}
                if(cfg.sticker_width)setBW(parseInt(cfg.sticker_width),true);
                if(cfg.custom_shipping_note)document.getElementById('bSN').value=cfg.custom_shipping_note;
                document.querySelectorAll('.toggle-sw[data-key]').forEach(function(ts){
                    var k=ts.dataset.key;
                    if(k in cfg){
                        cfg[k]?ts.classList.add('on'):ts.classList.remove('on');
                        bCfg[k]=!!cfg[k];
                    }
                });
                updateBldPv();}
        });
    } else if(eKey&&isBuiltin){
        var card=document.querySelector('[data-key="'+eKey+'"]');
        document.getElementById('bldTitle').textContent='Customize: '+(card?card.dataset.name:eKey);
        document.getElementById('bldSub').textContent='Will save as new custom preset based on this template';
        document.getElementById('bBase').value=eKey;
    } else {
        document.getElementById('bldTitle').textContent='Create Custom Sticker';
        document.getElementById('bldSub').textContent='Customize and save as a new preset';
    }
    document.getElementById('bldModal').classList.remove('hidden');
    setTimeout(updateBldPv,200);
}

function closeBld(){
    document.getElementById('bldModal').classList.add('hidden');document.getElementById('bPvFrame').src='about:blank';editKey=null;
    fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=delete_preset&template_key=cust__preview_tmp_stk'}).catch(function(){});
}

function resetToggles(){
    var df={show_phone:true,show_barcode:true,show_variant:true,show_courier:true,
            show_parcel:true,show_notes:true,show_shipping_note:true,
            show_advance:true,show_discount:true,show_sku:false,bold_text:false};
    document.querySelectorAll('.toggle-sw').forEach(function(ts){
        var k=ts.dataset.key;
        if(k===undefined)return;
        // If key not in df, default to true (show by default)
        var val = (k in df) ? df[k] : true;
        val ? ts.classList.add('on') : ts.classList.remove('on');
        bCfg[k] = val;
    });
}
function setBW(w,silent){document.getElementById('bSW').value=w;document.getElementById('swV').textContent=w+'px';bCfg.sticker_width=String(w);if(!silent)dbPv();}
function setClr(val,dot){bCfg.primary_color=val;document.getElementById('bClr').value=val;if(dot){document.querySelectorAll('.cdot').forEach(function(d){d.classList.remove('act');});dot.classList.add('act');}dbPv();}
function dbPv(){clearTimeout(_dbT);_dbT=setTimeout(updateBldPv,500);}

function updateBldPv(){
    bCfg.primary_color=document.getElementById('bClr').value;
    bCfg.font_size=document.getElementById('bFS').value;
    bCfg.sticker_width=document.getElementById('bSW').value;
    bCfg.custom_shipping_note=document.getElementById('bSN').value;
    // Read ALL toggle states including note toggles
    document.querySelectorAll('.toggle-sw[data-key]').forEach(function(ts){
        bCfg[ts.dataset.key]=ts.classList.contains('on');
    });
    var base=document.getElementById('bBase').value,tmpK='cust__preview_tmp_stk',fd=new URLSearchParams();
    fd.set('action','save_preset');fd.set('template_key',tmpK);fd.set('name','_tmp');fd.set('description','');fd.set('base_template',base);fd.set('config',JSON.stringify(bCfg));
    document.getElementById('bPvLoad').classList.remove('hidden');
    fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd.toString()})
    .then(function(r){return r.json();}).then(function(d){if(d.success)document.getElementById('bPvFrame').src='order-print.php?preview=1&template='+encodeURIComponent(d.key||tmpK);});
}

function savePreset(){
    var name=document.getElementById('bN').value.trim();
    if(!name){alert('Please enter a name');document.getElementById('bN').focus();return;}
    bCfg.primary_color=document.getElementById('bClr').value;bCfg.font_size=document.getElementById('bFS').value;
    bCfg.sticker_width=document.getElementById('bSW').value;bCfg.custom_shipping_note=document.getElementById('bSN').value;
    document.querySelectorAll('.toggle-sw[data-key]').forEach(function(ts){bCfg[ts.dataset.key]=ts.classList.contains('on');});
    var fd=new URLSearchParams();fd.set('action','save_preset');fd.set('template_key',editKey||'');fd.set('name',name);
    fd.set('description',document.getElementById('bD').value.trim());fd.set('base_template',document.getElementById('bBase').value);fd.set('config',JSON.stringify(bCfg));
    fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd.toString()})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){stkToast(editKey?'Preset updated!':'Custom preset created!','indigo');closeBld();setTimeout(function(){location.reload();},400);}
        else alert(d.error||'Error saving');
    });
}

function stkToast(msg,clr){
    var t=document.createElement('div');var bg=clr==='red'?'bg-red-500':'bg-indigo-600';
    t.className='fixed bottom-6 right-6 '+bg+' text-white px-5 py-3 rounded-xl shadow-2xl text-sm font-semibold z-[99999] flex items-center gap-2';
    t.innerHTML='<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>'+msg;
    document.body.appendChild(t);setTimeout(function(){t.style.transition='opacity .3s';t.style.opacity='0';setTimeout(function(){t.remove();},300);},2200);
}

document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!document.getElementById('bldModal').classList.contains('hidden'))closeBld();});
stkPreview('<?=e($current)?>');
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
