<?php
/**
 * Cart Page
 */
$pageTitle = 'Shopping Cart | ' . getSetting('site_name');
$seo = ['type' => 'website', 'noindex' => true];
include ROOT_PATH . 'includes/header.php';
$cart = getCart();
$cartTotal = getCartTotal();
$btnCheckoutLabel = getSetting('btn_checkout_label', 'চেকআউট');
$btnContinueLabel = getSetting('btn_continue_shopping', 'শপিং চালিয়ে যান');
?>

<main class="max-w-5xl mx-auto px-4 py-6">
    <h1 class="text-2xl font-bold mb-6 flex items-center gap-2">
        <i class="fas fa-shopping-bag" style="color:var(--primary)"></i> শপিং কার্ট
    </h1>
    
    <?php if (empty($cart)): ?>
    <div class="text-center py-16 bg-white rounded-2xl shadow-sm border">
        <div class="w-24 h-24 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
            <i class="fas fa-shopping-bag text-4xl text-gray-300"></i>
        </div>
        <h3 class="text-lg font-medium text-gray-600 mb-2">আপনার কার্ট খালি আছে</h3>
        <a href="<?= url() ?>" class="inline-block mt-3 px-6 py-2.5 rounded-xl btn-primary font-medium"><?= $btnContinueLabel ?></a>
    </div>
    <?php else: ?>
    
    <?php
    // Progress bar on cart page
    $_pbBar = null;
    try {
        $_pbDb = Database::getInstance();
        $_pbDb->query("CREATE TABLE IF NOT EXISTS checkout_progress_bars (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL,
            template TINYINT DEFAULT 1, tiers JSON DEFAULT NULL, config JSON DEFAULT NULL,
            is_active TINYINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $_pbBar = $_pbDb->fetch("SELECT * FROM checkout_progress_bars WHERE is_active = 1 LIMIT 1");
        if ($_pbBar) {
            $_pbBar['tiers'] = json_decode($_pbBar['tiers'] ?? '[]', true) ?: [];
            $_pbBar['config'] = json_decode($_pbBar['config'] ?? '{}', true) ?: [];
        }
    } catch (\Throwable $e) {}
    if ($_pbBar && !empty($_pbBar['tiers'])):
    ?>
    <div id="cart-progress-bar" class="mb-4" data-template="<?= intval($_pbBar['template']) ?>" data-tiers='<?= json_encode($_pbBar['tiers'], JSON_UNESCAPED_UNICODE) ?>' data-config='<?= json_encode($_pbBar['config'] ?? [], JSON_UNESCAPED_UNICODE) ?>' data-cart-total="<?= $cartTotal ?>"></div>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const el = document.getElementById('cart-progress-bar');
        if(!el) return;
        const tiers = JSON.parse(el.dataset.tiers || '[]');
        const tpl = parseInt(el.dataset.template) || 1;
        const amount = parseFloat(el.dataset.cartTotal) || 0;
        const cfg = JSON.parse(el.dataset.config || '{}');
        const n = tiers.length;
        if(!n) return;
        
        // Evenly-spaced milestone calculation
        const seg = 100/n;
        let pct = 100;
        for(let i=0;i<n;i++){const prev=i===0?0:tiers[i-1].min_amount;const cur=tiers[i].min_amount;if(amount<cur){const p=cur>prev?(amount-prev)/(cur-prev):0;pct=Math.min(100,i*seg+p*seg);break;}}
        function msPct(i){return((i+1)/n)*100;}
        
        // Custom colors
        const DEFS={1:{bg:'#f3f4f6',f:'#ef4444',t:'#22c55e'},2:{bg:'#e5e7eb',f:'#22c55e',t:'#22c55e'},3:{bg:'#1e1b4b',f:'#7c3aed',t:'#f59e0b'},4:{bg:'#fef9c3',f:'#facc15',t:'#f43f5e'},5:{bg:'#f9fafb',f:'#111827',t:'#111827'},6:{bg:'#374151',f:'#f59e0b',t:'#ef4444'}};
        const d=DEFS[tpl]||DEFS[1];
        const cBg=cfg.color_track_bg||d.bg, cFrom=cfg.color_fill_from||d.f, cTo=cfg.color_fill_to||d.t;
        const hgt=cfg.height||'normal';
        const shrk=cfg.shrink||(hgt==='slim'?40:hgt==='compact'?25:0);
        const s=shrk/100;
        const pad=`${Math.round(12*(1-s))}px ${Math.round(16*(1-s*0.3))}px`;
        const barH=Math.max(3,Math.round(10*(1-s)));
        const gap=Math.round(6*(1-s))+'px';
        const msgMt=Math.round(8*(1-s))+'px';
        const dotSz=Math.max(24,Math.round(36*(1-s)));
        const fSz='11px';const iSz='14px';
        
        let nextTier = tiers.find(t=>amount<t.min_amount);
        let remaining = nextTier?(nextTier.min_amount-amount):0;
        let msg = nextTier
            ? `<p style="font-size:11px;font-weight:600;margin-top:${msgMt};color:#ea580c">আরো <strong>৳${remaining.toLocaleString()}</strong> যোগ করলে <strong>${nextTier.label_bn||''}</strong> পাবেন! ${nextTier.icon}</p>`
            : `<p style="font-size:11px;font-weight:600;margin-top:${msgMt};color:#16a34a">🎉 সব রিওয়ার্ড আনলক হয়েছে!</p>`;
        
        // Template 6: Dark Track
        if(tpl===6){
            const dtH=Math.max(3,Math.round(8*(1-s)));
            let dots='';
            tiers.forEach((t,i)=>{const pos=msPct(i);const done=amount>=t.min_amount;
                dots+=`<div style="position:absolute;left:${pos}%;top:50%;transform:translate(-50%,-50%);z-index:2"><div style="width:${dotSz}px;height:${dotSz}px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:${iSz};${done?'background:#22c55e;color:#fff;box-shadow:0 0 0 3px rgba(34,197,94,0.3)':'background:#fff;color:#374151;border:2px solid #9ca3af;box-shadow:0 1px 3px rgba(0,0,0,0.15)'};transition:all .3s">${done?'✓':t.icon}</div></div>`;
            });
            let labels=tiers.map((t,i)=>{const pos=msPct(i);const done=amount>=t.min_amount;return`<div style="position:absolute;left:${pos}%;transform:translateX(-50%);text-align:center;white-space:nowrap"><div style="font-size:${fSz};font-weight:${done?700:500};color:${done?'#16a34a':'#6b7280'};margin-top:2px">${t.label_bn}</div><div style="font-size:${fSz};color:${done?'#22c55e':'#9ca3af'}">৳${Number(t.min_amount).toLocaleString()}</div></div>`;}).join('');
            el.innerHTML=`<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:${pad};border:1px solid #fed7aa"><div style="text-align:center;margin-bottom:${gap}">${nextTier?`<span style="display:inline-block;background:#1f2937;color:#fff;font-size:11px;padding:3px 12px;border-radius:9999px;font-weight:600">আরো <strong style="color:#fbbf24">৳${remaining.toLocaleString()}</strong> যোগ করলে ${nextTier.label_bn} পাবেন!</span>`:`<span style="display:inline-block;background:#16a34a;color:#fff;font-size:11px;padding:3px 12px;border-radius:9999px;font-weight:600">🎉 সব রিওয়ার্ড আনলক!</span>`}</div><div style="position:relative;height:${dotSz}px;margin:0 18px"><div style="position:absolute;top:50%;left:0;right:0;transform:translateY(-50%);height:${dtH}px;background:${cBg};border-radius:9999px;overflow:hidden"><div style="height:100%;width:${pct}%;background:linear-gradient(90deg,${cFrom},${cTo});border-radius:9999px;transition:width .5s ease;box-shadow:0 0 8px ${cFrom}66"></div></div>${dots}</div><div style="position:relative;height:30px;margin:${Math.round(4*(1-s))}px 18px 0">${labels}</div></div>`;
            return;
        }
        
        // Templates 1-5 with custom colors
        // Milestone tick marks
        const ticks=tiers.map((t,i)=>{const pos=msPct(i);const done=amount>=t.min_amount;return`<div style="position:absolute;left:${pos}%;top:0;bottom:0;width:2px;transform:translateX(-50%);background:${done?'rgba(255,255,255,0.6)':'rgba(0,0,0,0.12)'};z-index:1"></div>`;}).join('');
        const tierHtml = t => {const done=amount>=t.min_amount;return`<div style="text-align:center;flex:1"><span style="font-size:14px;${done?'':'opacity:.6'}">${done?'✅':t.icon}</span><div style="font-size:11px;color:${done?'#16a34a':'#6b7280'};margin-top:1px;font-weight:${done?700:400};line-height:1.2">${t.label_bn}</div><div style="font-size:10px;color:${done?'#22c55e':'#9ca3af'}">৳${Number(t.min_amount).toLocaleString()}</div></div>`;};
        let bS=`background:${cBg};border-radius:9999px;height:${barH}px;overflow:hidden;position:relative`;
        let fS=`background:linear-gradient(90deg,${cFrom},${cTo});height:100%;border-radius:9999px;width:${pct}%;transition:width .5s`;
        if(tpl===3){bS=`background:${cBg};border-radius:12px;height:${barH}px;overflow:hidden;position:relative`;fS+=`;box-shadow:0 0 12px ${cFrom}66`;}
        else if(tpl===4){bS+=`;border:1px solid #fde047`;}
        else if(tpl===5){bS=`background:${cBg};height:${barH}px;position:relative`;fS=`background:${cFrom};height:100%;width:${pct}%;transition:width .5s`;}
        
        if(tpl===2){
            const stepLine=`<div style="position:absolute;top:${dotSz/2}px;left:${dotSz/2}px;right:${dotSz/2}px;height:3px;background:${cBg};z-index:0"><div style="height:100%;background:${cFrom};width:${pct}%;transition:width .5s"></div></div>`;
            const steps=tiers.map(t=>{const done=amount>=t.min_amount;return`<div style="text-align:center;z-index:1"><div style="width:${dotSz}px;height:${dotSz}px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid ${done?cFrom:'#e5e7eb'};background:${done?cFrom:'#fff'};color:${done?'#fff':'#374151'};transition:all .3s;margin:0 auto">${done?'✓':t.icon}</div><div style="font-size:11px;margin-top:2px;font-weight:${done?700:500};color:${done?'#16a34a':'#6b7280'}">${t.label_bn}</div><div style="font-size:10px;color:${done?'#22c55e':'#9ca3af'}">৳${Number(t.min_amount).toLocaleString()}</div></div>`;}).join('');
            el.innerHTML=`<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:${pad};border:1px solid #fed7aa"><div style="display:flex;align-items:flex-start;justify-content:space-between;position:relative">${stepLine}${steps}</div>${msg}</div>`;
        } else {
            el.innerHTML=`<div style="background:linear-gradient(135deg,#fefce8,#fff7ed);border-radius:12px;padding:${pad};border:1px solid #fed7aa"><div style="display:flex;justify-content:space-between;margin-bottom:${gap}">${tiers.map(tierHtml).join('')}</div><div style="${bS}">${ticks}<div style="${fS}"></div></div>${msg}</div>`;
        }
    });
    </script>
    <?php endif; ?>
    
    <div class="grid lg:grid-cols-3 gap-6">
        <!-- Cart Items -->
        <div class="lg:col-span-2 space-y-3" id="cart-items">
            <?php foreach ($cart as $key => $item): ?>
            <div class="cart-item bg-white rounded-xl p-4 shadow-sm border flex gap-4 items-center" data-key="<?= $key ?>">
                <img src="<?= $item['image'] ?>" alt="" class="w-20 h-20 rounded-lg object-cover border flex-shrink-0"
                     onerror="this.src='<?= asset('img/default-product.svg') ?>'">
                <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-800 truncate"><?= htmlspecialchars($item['name']) ?></h3>
                    <?php if (!empty($item['variant_name'])): ?>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($item['variant_name']) ?></p>
                    <?php endif; ?>
                    <p class="text-sm mt-1" style="color:var(--primary)"><?= formatPrice($item['price']) ?></p>
                    <?php if (!empty($item['regular_price']) && $item['regular_price'] > $item['price']): ?>
                    <p class="text-xs text-gray-400 line-through"><?= formatPrice($item['regular_price']) ?></p>
                    <?php endif; ?>
                </div>
                
                <div class="flex items-center gap-2">
                    <div class="inline-flex items-center border rounded-lg overflow-hidden">
                        <button onclick="updateCartQty('<?= $key ?>', -1)" class="w-8 h-8 flex items-center justify-center hover:bg-gray-100 text-gray-500">
                            <i class="fas fa-minus text-xs"></i>
                        </button>
                        <span class="w-10 h-8 flex items-center justify-center font-medium text-sm cart-qty"><?= $item['quantity'] ?></span>
                        <button onclick="updateCartQty('<?= $key ?>', 1)" class="w-8 h-8 flex items-center justify-center hover:bg-gray-100 text-gray-500">
                            <i class="fas fa-plus text-xs"></i>
                        </button>
                    </div>
                    
                    <span class="font-bold text-gray-800 w-24 text-right cart-item-total"><?= formatPrice($item['price'] * $item['quantity']) ?></span>
                    
                    <button onclick="removeCartItem('<?= $key ?>')" class="p-2 text-gray-400 hover:text-red-500 transition">
                        <i class="fas fa-trash-alt text-sm"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Cart Summary -->
        <div>
            <div class="bg-white rounded-xl p-5 shadow-sm border sticky top-24">
                <h3 class="font-bold text-lg mb-4">অর্ডার সারাংশ</h3>
                
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-600">সাবটোটাল:</span><span class="font-medium" id="cart-subtotal"><?= formatPrice($cartTotal) ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-600">ডেলিভারি:</span><span class="font-medium text-gray-500">চেকআউটে নির্ধারিত হবে</span></div>
                </div>
                
                <div class="border-t mt-3 pt-3 flex justify-between text-lg font-bold">
                    <span>মোট:</span>
                    <span style="color:var(--primary)" id="cart-total"><?= formatPrice($cartTotal) ?></span>
                </div>
                
                <button onclick="openCheckoutPopup()" 
                        class="w-full mt-4 py-3 rounded-xl btn-primary font-bold text-base transition transform active:scale-[0.98]">
                    <i class="fas fa-check-circle mr-2"></i> <?= $btnCheckoutLabel ?>
                </button>
                
                <a href="<?= url() ?>" class="block text-center mt-3 text-sm text-gray-500 hover:text-red-600 transition">
                    <i class="fas fa-arrow-left mr-1"></i> <?= $btnContinueLabel ?>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- Cart-specific JS (qty/remove only — checkout handled by footer popup) -->
<script>
function updateCartQty(key, delta) {
    fetch(SITE_URL + '/api/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update', key: key, delta: delta })
    })
    .then(r => r.json())
    .then(data => {
        if (data.removed) {
            document.querySelector(`.cart-item[data-key="${key}"]`)?.remove();
        } else if (data.success) {
            const item = document.querySelector(`.cart-item[data-key="${key}"]`);
            if (item) {
                item.querySelector('.cart-qty').textContent = data.quantity;
                item.querySelector('.cart-item-total').textContent = CURRENCY + ' ' + Number(data.item_total).toLocaleString();
            }
        }
        const sub = document.getElementById('cart-subtotal');
        const tot = document.getElementById('cart-total');
        if (sub) sub.textContent = CURRENCY + ' ' + Number(data.cart_total).toLocaleString();
        if (tot) tot.textContent = CURRENCY + ' ' + Number(data.cart_total).toLocaleString();
        document.querySelectorAll('.cart-count').forEach(el => el.textContent = data.cart_count);
        if (data.cart_count === 0) location.reload();
    });
}

function removeCartItem(key) {
    fetch(SITE_URL + '/api/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove', key: key })
    })
    .then(r => r.json())
    .then(data => {
        document.querySelector(`.cart-item[data-key="${key}"]`)?.remove();
        document.querySelectorAll('.cart-count').forEach(el => el.textContent = data.cart_count);
        if (data.cart_count === 0) location.reload();
        else {
            const sub = document.getElementById('cart-subtotal');
            const tot = document.getElementById('cart-total');
            if (sub) sub.textContent = CURRENCY + ' ' + Number(data.cart_total).toLocaleString();
            if (tot) tot.textContent = CURRENCY + ' ' + Number(data.cart_total).toLocaleString();
        }
    });
}
</script>

<!-- Track incomplete on page unload during checkout -->
<script>
window.addEventListener('beforeunload', function() {
    const popup = document.getElementById('checkout-popup');
    if (popup && !popup.classList.contains('hidden')) {
        try {
            const form = document.getElementById('checkout-form');
            navigator.sendBeacon(SITE_URL + '/api/track.php', new URLSearchParams({
                action: 'track_incomplete',
                step: 'abandoned',
                total: '<?= $cartTotal ?>',
                phone: form?.querySelector('[name=phone]')?.value || '',
                name: form?.querySelector('[name=name]')?.value || '',
                address: form?.querySelector('[name=address]')?.value || ''
            }));
        } catch(e) {}
    }
});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
