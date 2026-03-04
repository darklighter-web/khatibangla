<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Checkout Fields';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// Default fields
$defaultFields = [
    ['key'=>'cart_summary','label'=>'পণ্যের তালিকা','label_en'=>'Product Summary','type'=>'system','enabled'=>true,'required'=>false,'placeholder'=>'','width'=>'full','icon'=>'fa-shopping-bag'],
    ['key'=>'progress_bar','label'=>'প্রোগ্রেস বার অফার','label_en'=>'Progress Bar Offer','type'=>'system','enabled'=>true,'required'=>false,'placeholder'=>'','width'=>'full','icon'=>'fa-gift'],
    ['key'=>'name','label'=>'আপনার নাম','label_en'=>'Customer Name','type'=>'text','enabled'=>true,'required'=>true,'placeholder'=>'সম্পূর্ণ নাম লিখুন','width'=>'full','icon'=>'fa-user'],
    ['key'=>'phone','label'=>'মোবাইল নম্বর','label_en'=>'Mobile Number','type'=>'tel','enabled'=>true,'required'=>true,'placeholder'=>'01XXXXXXXXX','width'=>'full','icon'=>'fa-phone'],
    ['key'=>'email','label'=>'ইমেইল','label_en'=>'Email Address','type'=>'email','enabled'=>false,'required'=>false,'placeholder'=>'your@email.com','width'=>'full','icon'=>'fa-envelope'],
    ['key'=>'address','label'=>'সম্পূর্ণ ঠিকানা','label_en'=>'Full Address','type'=>'textarea','enabled'=>true,'required'=>true,'placeholder'=>'বাসা/রোড নং, এলাকা, থানা, জেলা','width'=>'full','icon'=>'fa-map-marker-alt'],
    ['key'=>'shipping_area','label'=>'ডেলিভারি এরিয়া','label_en'=>'Delivery Area','type'=>'radio','enabled'=>true,'required'=>true,'placeholder'=>'','width'=>'full','icon'=>'fa-truck'],
    ['key'=>'coupon','label'=>'কুপন কোড আছে?','label_en'=>'Coupon Code','type'=>'system','enabled'=>true,'required'=>false,'placeholder'=>'কুপন কোড লিখুন','width'=>'full','icon'=>'fa-tag'],
    ['key'=>'store_credit','label'=>'স্টোর ক্রেডিট ব্যবহার করুন','label_en'=>'Use Store Credit','type'=>'system','enabled'=>true,'required'=>false,'placeholder'=>'','width'=>'full','icon'=>'fa-coins'],
    ['key'=>'upsells','label'=>'এটাও নিতে পারেন','label_en'=>'Upsell Products','type'=>'system','enabled'=>true,'required'=>false,'placeholder'=>'','width'=>'full','icon'=>'fa-fire'],
    ['key'=>'order_total','label'=>'অর্ডার সামারি','label_en'=>'Order Total','type'=>'system','enabled'=>true,'required'=>false,'placeholder'=>'','width'=>'full','icon'=>'fa-calculator'],
    ['key'=>'notes','label'=>'অতিরিক্ত নোট','label_en'=>'Additional Notes','type'=>'text','enabled'=>false,'required'=>false,'placeholder'=>'বিশেষ কোনো নির্দেশনা থাকলে লিখুন','width'=>'full','icon'=>'fa-sticky-note'],
];

// Load saved config
$savedJson = getSetting('checkout_fields', '');
$fields = $savedJson ? json_decode($savedJson, true) : null;

// Merge defaults with saved
if (!$fields) {
    $fields = $defaultFields;
} else {
    // Deduplicate by key (keep first occurrence only)
    $seen = [];
    $fields = array_filter($fields, function($f) use (&$seen) {
        $k = $f['key'] ?? '';
        if (isset($seen[$k])) return false;
        $seen[$k] = true;
        return true;
    });
    $fields = array_values($fields);
    
    $savedKeys = array_column($fields, 'key');
    foreach ($defaultFields as $df) {
        if (!in_array($df['key'], $savedKeys)) {
            $fields[] = $df;
        }
    }
    foreach ($fields as &$f) {
        $defMatch = array_filter($defaultFields, fn($d) => $d['key'] === $f['key']);
        if ($defMatch) {
            $def = reset($defMatch);
            foreach ($def as $k => $v) {
                if (!isset($f[$k])) $f[$k] = $v;
            }
        }
    }
    unset($f);
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_fields') {
    $newOrder = json_decode($_POST['field_order'] ?? '[]', true);
    if (!is_array($newOrder) || empty($newOrder)) {
        redirect(adminUrl('pages/checkout-fields.php?msg=error'));
    }

    $updatedFields = [];
    $savedKeys = [];
    foreach ($newOrder as $item) {
        $key = $item['key'] ?? '';
        if (!$key || in_array($key, $savedKeys)) continue;
        $savedKeys[] = $key;
        $existing = array_filter($fields, fn($f) => $f['key'] === $key);
        if (empty($existing)) continue;
        $field = reset($existing);
        
        $field['label'] = trim($item['label'] ?? $field['label']);
        $field['placeholder'] = trim($item['placeholder'] ?? $field['placeholder'] ?? '');
        $field['enabled'] = (bool)($item['enabled'] ?? false);
        $field['required'] = (bool)($item['required'] ?? false);
        $field['width'] = $item['width'] ?? 'full';
        if ($key === 'upsells' && isset($item['upsell_count'])) {
            $field['upsell_count'] = max(1, min(10, intval($item['upsell_count'])));
        }
        $updatedFields[] = $field;
    }

    if (!empty($updatedFields)) {
        $json = json_encode($updatedFields, JSON_UNESCAPED_UNICODE);
        $db->query(
            "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) 
             VALUES ('checkout_fields', ?, 'json', 'checkout') ON DUPLICATE KEY UPDATE setting_value = ?",
            [$json, $json]
        );
        logActivity(getAdminId(), 'update', 'settings', 0, 'Updated checkout fields');
    }
    redirect(adminUrl('pages/checkout-fields.php?msg=saved'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="<?= $_GET['msg']==='saved' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700' ?> border px-4 py-3 rounded-lg mb-4 text-sm">
    <?= $_GET['msg']==='saved' ? '✅ Checkout fields saved successfully!' : '❌ Error saving fields.' ?>
</div>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Checkout Field Editor</h2>
        <p class="text-sm text-gray-500 mt-1">Drag to reorder, rename labels, enable/disable fields</p>
    </div>
    <div class="flex gap-2">
        <button onclick="resetToDefault()" class="bg-gray-100 text-gray-600 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">
            <i class="fas fa-undo mr-1"></i> Reset Default
        </button>
        <button onclick="saveFields()" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
            <i class="fas fa-save mr-1"></i> Save Changes
        </button>
    </div>
</div>

<div class="grid lg:grid-cols-5 gap-6">
    <!-- Left: Field Editor -->
    <div class="lg:col-span-3">
        <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
            <div class="px-5 py-3 bg-gray-50 border-b flex items-center justify-between">
                <h3 class="font-semibold text-gray-800 text-sm"><i class="fas fa-list-ul mr-2 text-blue-500"></i>Field Order & Configuration</h3>
                <span class="text-xs text-gray-400"><i class="fas fa-grip-vertical mr-1"></i>Drag to reorder</span>
            </div>
            <div id="fieldList" class="divide-y">
                <?php foreach ($fields as $i => $field): 
                    $isSystem = in_array($field['key'], ['cart_summary','order_total']);
                    $isToggleable = !$isSystem;
                    $isInput = in_array($field['type'], ['text','tel','email','textarea']);
                ?>
                <div class="field-item <?= !$field['enabled'] ? 'opacity-50' : '' ?>"
                     data-key="<?= $field['key'] ?>" data-type="<?= $field['type'] ?>">
                    
                    <!-- Main Row (drag handle here) -->
                    <div class="field-row px-4 py-3 flex items-center gap-3 hover:bg-gray-50 transition">
                        <div class="drag-handle flex-shrink-0 text-gray-300 hover:text-gray-500 cursor-grab active:cursor-grabbing">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                        </div>

                        <div class="w-9 h-9 rounded-lg <?= $field['enabled'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400' ?> flex items-center justify-center flex-shrink-0">
                            <i class="fas <?= $field['icon'] ?> text-sm"></i>
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="field-label-display text-sm font-semibold text-gray-800 truncate"><?= e($field['label']) ?></span>
                                <?php if ($isSystem): ?><span class="text-[10px] bg-purple-100 text-purple-600 px-1.5 py-0.5 rounded font-medium flex-shrink-0">SYSTEM</span><?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="text-[10px] text-gray-400 uppercase"><?= $field['key'] ?></span>
                                <span class="text-[10px] text-gray-400">•</span>
                                <span class="text-[10px] text-gray-400"><?= $field['type'] ?></span>
                                <?php if ($field['placeholder']): ?>
                                <span class="text-[10px] text-gray-400">•</span>
                                <span class="field-ph-display text-[10px] text-gray-400 truncate max-w-[120px]"><?= e($field['placeholder']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 flex-shrink-0">
                            <?php if (!$isSystem && $isInput): ?>
                            <label class="flex items-center gap-1 cursor-pointer" title="Required">
                                <span class="text-[10px] text-gray-400">Required</span>
                                <div class="relative">
                                    <input type="checkbox" class="field-required sr-only" <?= $field['required']?'checked':'' ?>>
                                    <div class="w-8 h-4 bg-gray-200 rounded-full toggle-track transition"></div>
                                    <div class="absolute left-0.5 top-0.5 w-3 h-3 bg-white rounded-full shadow toggle-dot transition"></div>
                                </div>
                            </label>
                            <?php endif; ?>

                            <?php if ($isToggleable): ?>
                            <label class="flex items-center gap-1 cursor-pointer" title="Enabled">
                                <span class="text-[10px] text-gray-400">Show</span>
                                <div class="relative">
                                    <input type="checkbox" class="field-enabled sr-only" <?= $field['enabled']?'checked':'' ?>>
                                    <div class="w-8 h-4 bg-gray-200 rounded-full toggle-track transition"></div>
                                    <div class="absolute left-0.5 top-0.5 w-3 h-3 bg-white rounded-full shadow toggle-dot transition"></div>
                                </div>
                            </label>
                            <?php endif; ?>

                            <button type="button" class="expand-btn p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition">
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Detail Panel — CHILD of field-item (not sibling) so querySelector works -->
                    <div class="field-detail hidden bg-gray-50 px-4 py-3 border-t text-sm space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Label (shown on form)</label>
                                <input type="text" class="detail-label w-full px-3 py-2 border rounded-lg text-sm" value="<?= e($field['label']) ?>">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Placeholder text</label>
                                <input type="text" class="detail-placeholder w-full px-3 py-2 border rounded-lg text-sm" value="<?= e($field['placeholder'] ?? '') ?>">
                            </div>
                        </div>
                        <?php if ($field['key'] === 'upsells'): ?>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1"><i class="fas fa-th-list mr-1 text-orange-400"></i> Products to show</label>
                            <select class="detail-upsell-count px-3 py-2 border rounded-lg text-sm bg-white w-full max-w-[200px]">
                                <?php $uc = intval($field['upsell_count'] ?? 4); ?>
                                <?php for ($n = 1; $n <= 10; $n++): ?>
                                <option value="<?= $n ?>" <?= $uc === $n ? 'selected' : '' ?>><?= $n ?> product<?= $n > 1 ? 's' : '' ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="text-xs text-gray-400">Field key: <code class="bg-gray-200 px-1 py-0.5 rounded"><?= $field['key'] ?></code> · Type: <code class="bg-gray-200 px-1 py-0.5 rounded"><?= $field['type'] ?></code></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Right: Live Preview -->
    <div class="lg:col-span-2">
        <div class="sticky top-20">
            <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
                <div class="px-5 py-3 bg-gray-50 border-b">
                    <h3 class="font-semibold text-gray-800 text-sm"><i class="fas fa-eye mr-2 text-green-500"></i>Live Preview</h3>
                </div>
                <div id="livePreview" class="p-5 space-y-4 max-h-[600px] overflow-y-auto"></div>
            </div>

            <div class="mt-4 bg-blue-50 border border-blue-200 rounded-xl p-4">
                <h4 class="text-sm font-semibold text-blue-800 mb-2"><i class="fas fa-info-circle mr-1"></i> Tips</h4>
                <ul class="text-xs text-blue-700 space-y-1">
                    <li>• <strong>Drag</strong> fields to change their order</li>
                    <li>• <strong>Click labels</strong> to rename in Bangla or English</li>
                    <li>• <strong>Product Summary</strong> and <strong>Order Total</strong> are always shown</li>
                    <li>• <strong>Name</strong>, <strong>Phone</strong>, <strong>Address</strong> are recommended as required</li>
                    <li>• Disabled fields won't appear on checkout</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<form id="saveForm" method="POST" class="hidden">
    <input type="hidden" name="action" value="save_fields">
    <input type="hidden" name="field_order" id="fieldOrderInput">
</form>

<style>
.toggle-track { transition: background 0.2s; }
.field-enabled:checked ~ .toggle-track { background: #22c55e; }
.field-required:checked ~ .toggle-track { background: #f97316; }
.field-enabled:checked ~ .toggle-dot,
.field-required:checked ~ .toggle-dot { transform: translateX(16px); }
.toggle-dot { transition: transform 0.2s; }
.sortable-ghost { opacity: 0.3; background: #dbeafe !important; }
.sortable-chosen { box-shadow: 0 8px 25px rgba(0,0,0,0.15); z-index: 10; }
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
const fieldList = document.getElementById('fieldList');

// ── SortableJS ──
new Sortable(fieldList, {
    handle: '.drag-handle',
    animation: 200,
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
    draggable: '.field-item',
    onEnd: function() { updatePreview(); }
});

// ══════════════════════════════════════
// EVENT DELEGATION — single listener handles everything
// ══════════════════════════════════════

// Expand/Collapse (click)
fieldList.addEventListener('click', function(e) {
    const btn = e.target.closest('.expand-btn');
    if (!btn) return;
    
    const item = btn.closest('.field-item');
    if (!item) return;
    
    const detail = item.querySelector('.field-detail');
    const icon = btn.querySelector('i');
    if (!detail || !icon) return;
    
    const isOpen = !detail.classList.contains('hidden');
    
    // Close all others first (accordion behavior)
    fieldList.querySelectorAll('.field-item').forEach(other => {
        if (other === item) return;
        const od = other.querySelector('.field-detail');
        const oi = other.querySelector('.expand-btn i');
        if (od) od.classList.add('hidden');
        if (oi) { oi.classList.remove('fa-chevron-up'); oi.classList.add('fa-chevron-down'); }
    });
    
    // Toggle this one
    detail.classList.toggle('hidden', isOpen);
    icon.classList.toggle('fa-chevron-down', isOpen);
    icon.classList.toggle('fa-chevron-up', !isOpen);
});

// Input sync (input)
fieldList.addEventListener('input', function(e) {
    const item = e.target.closest('.field-item');
    if (!item) return;
    
    if (e.target.classList.contains('detail-label')) {
        const display = item.querySelector('.field-label-display');
        if (display) display.textContent = e.target.value;
    }
    if (e.target.classList.contains('detail-placeholder')) {
        const display = item.querySelector('.field-ph-display');
        if (display) display.textContent = e.target.value;
    }
    updatePreview();
});

// Toggle change (change)
fieldList.addEventListener('change', function(e) {
    if (e.target.classList.contains('field-enabled')) {
        const item = e.target.closest('.field-item');
        if (item) item.classList.toggle('opacity-50', !e.target.checked);
    }
    updatePreview();
});

// ══════════════════════════════════════
// LIVE PREVIEW
// ══════════════════════════════════════

function updatePreview() {
    const preview = document.getElementById('livePreview');
    let html = '';
    
    fieldList.querySelectorAll('.field-item').forEach(item => {
        const key = item.dataset.key;
        const type = item.dataset.type;
        const enabled = item.querySelector('.field-enabled')?.checked ?? true;
        if (!enabled) return;
        
        const required = item.querySelector('.field-required')?.checked ?? false;
        const label = item.querySelector('.detail-label')?.value || item.querySelector('.field-label-display')?.textContent || key;
        const placeholder = item.querySelector('.detail-placeholder')?.value || '';
        const star = required ? ' <span class="text-red-500">*</span>' : '';
        
        switch (key) {
            case 'cart_summary':
                html += `<div><label class="block text-sm font-medium text-gray-700 mb-1">${esc(label)}</label><div class="bg-gray-50 rounded-xl p-3 text-center text-xs text-gray-400 border border-dashed"><i class="fas fa-shopping-bag mr-1"></i> Cart items will appear here</div></div>`;
                break;
            case 'order_total':
                html += `<div class="bg-gray-50 rounded-xl p-3 space-y-1.5 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">সাবটোটাল:</span><span class="font-medium">৳ 980</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">ডেলিভারি:</span><span class="font-medium">৳ 130</span></div>
                    <div class="flex justify-between font-bold border-t pt-1.5 mt-1.5"><span>মোট:</span><span class="text-red-600">৳ 1,110</span></div></div>`;
                break;
            case 'shipping_area':
                html += `<div><label class="block text-sm font-medium text-gray-700 mb-2">${esc(label)}${star}</label>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="border-2 rounded-xl p-2 text-center text-xs"><span class="font-medium block">ঢাকার ভিতরে</span><span class="text-gray-400">৳70</span></div>
                        <div class="border-2 rounded-xl p-2 text-center text-xs"><span class="font-medium block">ঢাকা উপশহর</span><span class="text-gray-400">৳100</span></div>
                        <div class="border-2 border-red-400 bg-red-50 rounded-xl p-2 text-center text-xs"><span class="font-medium block">ঢাকার বাইরে</span><span class="text-gray-400">৳130</span></div>
                    </div></div>`;
                break;
            case 'coupon':
                html += `<div class="border rounded-xl overflow-hidden"><button type="button" class="w-full flex items-center justify-between px-3 py-2 text-xs text-gray-500"><span><i class="fas fa-tag mr-1 text-orange-400"></i> ${esc(label)}</span><i class="fas fa-chevron-down text-[10px]"></i></button></div>`;
                break;
            case 'store_credit':
                html += `<div class="flex items-center justify-between bg-yellow-50 rounded-lg px-3 py-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" class="rounded text-yellow-600" disabled>
                        <span class="text-yellow-700"><i class="fas fa-coins mr-1"></i>${esc(label)}</span>
                    </label>
                    <div class="text-right">
                        <span class="text-xs text-yellow-600 font-semibold block">100 ক্রেডিট</span>
                        <span class="text-[10px] text-yellow-500">= ৳75</span>
                    </div>
                </div>`;
                break;
            case 'upsells':
                const uc = parseInt(item.querySelector('.detail-upsell-count')?.value) || 4;
                let skeletons = '';
                for (let i = 0; i < Math.min(uc, 4); i++) {
                    const w1 = [75,66,80,60][i % 4], w2 = [25,33,20,40][i % 4];
                    skeletons += `<div class="flex items-center gap-2"><div class="w-8 h-8 bg-orange-100 rounded-lg flex-shrink-0"></div><div class="flex-1"><div class="h-2.5 bg-orange-100 rounded" style="width:${w1}%"></div><div class="h-2 bg-orange-100 rounded mt-1" style="width:${w2}%"></div></div><div class="w-14 h-6 bg-orange-200 rounded-lg"></div></div>`;
                }
                if (uc > 4) skeletons += `<div class="text-[10px] text-orange-400 text-center">+${uc - 4} more</div>`;
                html += `<div><p class="text-xs font-semibold text-gray-600 mb-1"><i class="fas fa-fire text-orange-400 mr-1"></i> ${esc(label)} <span class="text-[10px] text-gray-400 font-normal">(${uc} products)</span></p><div class="bg-orange-50 border border-orange-200 border-dashed rounded-lg p-3 space-y-2">${skeletons}</div></div>`;
                break;
            case 'notes':
            default:
                if (type === 'textarea') {
                    html += `<div><label class="block text-sm font-medium text-gray-700 mb-1">${esc(label)}${star}</label>
                        <textarea class="w-full border rounded-xl px-3 py-2.5 text-sm bg-gray-50" rows="2" placeholder="${esc(placeholder)}" disabled></textarea></div>`;
                } else {
                    html += `<div><label class="block text-sm font-medium text-gray-700 mb-1">${esc(label)}${star}</label>
                        <input type="${type === 'tel' ? 'tel' : type === 'email' ? 'email' : 'text'}" class="w-full border rounded-xl px-3 py-2.5 text-sm bg-gray-50" placeholder="${esc(placeholder)}" disabled></div>`;
                }
        }
    });
    
    html += `<button class="w-full py-3 rounded-xl text-white font-bold text-sm bg-red-600 opacity-80 cursor-default">
        <i class="fas fa-check-circle mr-1"></i> ক্যাশ অন ডেলিভারিতে অর্ডার করুন</button>`;
    
    preview.innerHTML = html;
}

// ══════════════════════════════════════
// SAVE & RESET
// ══════════════════════════════════════

function saveFields() {
    const fields = [];
    fieldList.querySelectorAll('.field-item').forEach(item => {
        const obj = {
            key: item.dataset.key,
            label: item.querySelector('.detail-label')?.value || item.querySelector('.field-label-display')?.textContent || '',
            placeholder: item.querySelector('.detail-placeholder')?.value || '',
            enabled: item.querySelector('.field-enabled')?.checked ?? true,
            required: item.querySelector('.field-required')?.checked ?? false,
            width: 'full'
        };
        // Save upsell_count for upsells field
        const ucSelect = item.querySelector('.detail-upsell-count');
        if (ucSelect) obj.upsell_count = parseInt(ucSelect.value) || 4;
        fields.push(obj);
    });
    document.getElementById('fieldOrderInput').value = JSON.stringify(fields);
    document.getElementById('saveForm').submit();
}

function resetToDefault() {
    if (!confirm('Reset all fields to default? Your customizations will be lost.')) return;
    document.getElementById('fieldOrderInput').value = JSON.stringify(<?= json_encode(array_map(fn($f) => ['key'=>$f['key'],'label'=>$f['label'],'placeholder'=>$f['placeholder'],'enabled'=>$f['enabled'],'required'=>$f['required'],'width'=>'full'], $defaultFields), JSON_UNESCAPED_UNICODE) ?>);
    document.getElementById('saveForm').submit();
}

function esc(s) { if (!s) return ''; const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// Init
updatePreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
