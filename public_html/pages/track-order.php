<?php
$pageTitle = 'ট্র্যাক অর্ডার';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();
$order = null;
$items = [];
$statusHistory = [];
$shipment = null;
$error = '';

if ($_GET['q'] ?? $_POST['order_number'] ?? '') {
    $orderNum = sanitize($_GET['q'] ?? $_POST['order_number']);
    $order = $db->fetch("SELECT * FROM orders WHERE order_number = ?", [$orderNum]);
    if ($order) {
        $items = $db->fetchAll("SELECT oi.*, p.slug, p.featured_image, p.store_credit_enabled, p.store_credit_amount,
            (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = oi.product_id AND pi.is_primary = 1 LIMIT 1) as image 
            FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?", [$order['id']]);
        // Fallback: use featured_image when product_images has no primary
        foreach ($items as &$_it) {
            if (empty($_it['image']) && !empty($_it['featured_image'])) {
                $_it['image'] = $_it['featured_image'];
            }
        }
        unset($_it);
        $statusHistory = $db->fetchAll("SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC", [$order['id']]);
        $shipment = $db->fetch("SELECT cs.*, cp.name as courier_name FROM courier_shipments cs LEFT JOIN courier_providers cp ON cp.id = cs.courier_id WHERE cs.order_id = ?", [$order['id']]);
    } else {
        $error = 'অর্ডারটি খুঁজে পাওয়া যায়নি। অর্ডার নম্বর চেক করুন।';
    }
}

require_once __DIR__ . '/../includes/header.php';

// ── Track UI Style ──
$trackUi = getSetting('track_order_ui', 'glow');
$brandColor = getSetting('primary_color', '#E53E3E');
$r = hexdec(substr($brandColor, 1, 2));
$g = hexdec(substr($brandColor, 3, 2));
$b = hexdec(substr($brandColor, 5, 2));
$r2 = ($r + 128) % 256; $g2 = ($g + 64) % 256; $b2 = ($b + 128) % 256;
$brandHex2 = sprintf("#%02x%02x%02x", $r2, $g2, $b2);
$brandDark = sprintf("#%02x%02x%02x", max(0,$r-40), max(0,$g-40), max(0,$b-40));
$brandRgb = "$r,$g,$b";
// Glass settings
$glassBlur = intval(getSetting('track_glass_blur', '20'));
$glassOpacity = intval(getSetting('track_glass_opacity', '70'));
$glassBorder = intval(getSetting('track_glass_border', '30'));
$glassTint = intval(getSetting('track_glass_tint', '10'));
$glassAnimBg = getSetting('track_glass_animated_bg', '1') === '1';

$statusSteps = ['processing', 'confirmed', 'shipped', 'delivered'];
$statusConfig = [
    'pending'    => ['প্রসেসিং', 'bg-yellow-100 text-yellow-700 border-yellow-300', 'fas fa-cog', '#eab308'],
    'processing' => ['প্রসেসিং', 'bg-yellow-100 text-yellow-700 border-yellow-300', 'fas fa-cog', '#eab308'],
    'confirmed'  => ['কনফার্মড', 'bg-blue-100 text-blue-700 border-blue-300', 'fas fa-check-circle', '#3b82f6'],
    'shipped'    => ['শিপড', 'bg-purple-100 text-purple-700 border-purple-300', 'fas fa-truck', '#8b5cf6'],
    'delivered'  => ['ডেলিভারড', 'bg-green-100 text-green-700 border-green-300', 'fas fa-box-open', '#22c55e'],
    'cancelled'  => ['ক্যান্সেলড', 'bg-red-100 text-red-700 border-red-300', 'fas fa-times-circle', '#ef4444'],
    'returned'   => ['রিটার্নড', 'bg-orange-100 text-orange-700 border-orange-300', 'fas fa-undo', '#f97316'],
    'pending_return' => ['রিটার্ন পেন্ডিং', 'bg-amber-100 text-amber-700 border-amber-300', 'fas fa-sync', '#f59e0b'],
    'pending_cancel' => ['ক্যান্সেল পেন্ডিং', 'bg-pink-100 text-pink-700 border-pink-300', 'fas fa-hourglass-half', '#ec4899'],
    'partial_delivered' => ['আংশিক ডেলিভারি', 'bg-cyan-100 text-cyan-700 border-cyan-300', 'fas fa-box', '#06b6d4'],
    'lost'       => ['লস্ট', 'bg-stone-100 text-stone-700 border-stone-300', 'fas fa-exclamation-triangle', '#78716c'],
    'on_hold'    => ['হোল্ড', 'bg-gray-100 text-gray-700 border-gray-300', 'fas fa-pause-circle', '#6b7280'],
];
$currentStatus = $order['order_status'] ?? '';
if ($currentStatus === 'pending') $currentStatus = 'processing';
$conf = $statusConfig[$currentStatus] ?? ['অজানা', 'bg-gray-100 text-gray-700', 'fas fa-question', '#6b7280'];
$creditUsed = floatval($order['store_credit_used'] ?? 0);
$discountAmt = floatval($order['discount_amount'] ?? 0);
$totalItems = 0;
foreach ($items as $it) $totalItems += intval($it['quantity']);
$totalCreditsEarnable = 0;
foreach ($items as $it) {
    if (!empty($it['store_credit_enabled']) && floatval($it['store_credit_amount'] ?? 0) > 0) {
        $totalCreditsEarnable += floatval($it['store_credit_amount']) * intval($it['quantity']);
    }
}
?>

<style>
/* ── Dark / Light Theme Variables ── */
.track-wrapper { --track-bg: #f9fafb; --track-card-bg: #ffffff; --track-card-border: #e5e7eb; --track-text: #1f2937; --track-text-secondary: #6b7280; --track-text-muted: #9ca3af; --track-shadow: rgba(0,0,0,0.05); transition: all 0.3s ease; }
.track-wrapper.dark-theme { --track-bg: #0f172a; --track-card-bg: #1e293b; --track-card-border: #334155; --track-text: #f1f5f9; --track-text-secondary: #94a3b8; --track-text-muted: #64748b; --track-shadow: rgba(0,0,0,0.3); }
.track-wrapper.dark-theme { background-color: var(--track-bg); }
.track-wrapper.dark-theme .bg-white { background-color: var(--track-card-bg) !important; }
.track-wrapper.dark-theme .border { border-color: var(--track-card-border) !important; }
.track-wrapper.dark-theme .text-gray-800, .track-wrapper.dark-theme .text-gray-700 { color: var(--track-text) !important; }
.track-wrapper.dark-theme .text-gray-500, .track-wrapper.dark-theme .text-gray-600 { color: var(--track-text-secondary) !important; }
.track-wrapper.dark-theme .text-gray-400, .track-wrapper.dark-theme .text-gray-300 { color: var(--track-text-muted) !important; }
.track-wrapper.dark-theme .bg-gray-50 { background-color: #1e293b !important; }
.track-wrapper.dark-theme .bg-gray-100 { background-color: #334155 !important; }
.track-wrapper.dark-theme .shadow-sm { box-shadow: 0 1px 3px var(--track-shadow) !important; }
.track-wrapper.dark-theme .border-t, .track-wrapper.dark-theme .border-b, .track-wrapper.dark-theme .border-dashed { border-color: var(--track-card-border) !important; }
.track-wrapper.dark-theme .tl-item::before { background: #475569 !important; }
.track-wrapper.dark-theme .track-step .step-dot { border-color: #475569; background: #1e293b; }
.track-wrapper.dark-theme .track-line { background: #475569 !important; }
.track-theme-toggle { position:fixed; bottom:80px; right:16px; z-index:40; width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; border:2px solid #e5e7eb; background:#fff; box-shadow:0 2px 12px rgba(0,0,0,0.1); transition:all .3s; }
.track-wrapper.dark-theme .track-theme-toggle { background:#1e293b; border-color:#475569; color:#f1f5f9; }

/* ── Timeline (shared) ── */
.track-progress{display:flex;align-items:flex-start;position:relative;padding:0 8px}
.track-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;z-index:2}
.track-step .step-dot{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;border:3px solid #e5e7eb;background:#fff;transition:all .3s}
.track-step.done .step-dot{border-color:#22c55e;background:#22c55e;color:#fff}
.track-step.active .step-dot{border-color:var(--primary,#3b82f6);background:var(--primary,#3b82f6);color:#fff;box-shadow:0 0 0 4px rgba(59,130,246,.2)}
.track-line{position:absolute;top:18px;left:0;right:0;height:3px;background:#e5e7eb;z-index:1}
.track-line-fill{height:100%;background:linear-gradient(90deg,#22c55e,var(--primary,#3b82f6));border-radius:4px;transition:width .6s ease}
.tl-item{position:relative;padding-left:28px;padding-bottom:20px}
.tl-item:last-child{padding-bottom:0}
.tl-item::before{content:'';position:absolute;left:7px;top:24px;bottom:0;width:2px;background:#e5e7eb}
.tl-item:last-child::before{display:none}
.tl-dot{position:absolute;left:0;top:4px;width:16px;height:16px;border-radius:50%;border:3px solid;display:flex;align-items:center;justify-content:center}

<?php if ($trackUi === 'glow'): ?>
/* ══════════════════════════
   GLOW STYLE — Neon Search
   ══════════════════════════ */
@keyframes glowSpin{0%{transform:translate(-50%,-50%) rotate(0deg)}100%{transform:translate(-50%,-50%) rotate(360deg)}}
@keyframes searchPulse{0%,100%{opacity:.7;transform:scale(1)}50%{opacity:1;transform:scale(1.08)}}
@keyframes orbitDot{0%{transform:rotate(0deg) translateX(11px)}100%{transform:rotate(360deg) translateX(11px)}}
@keyframes glowPulse{0%,100%{box-shadow:0 0 15px <?=$brandColor?>40,0 0 30px <?=$brandColor?>20}50%{box-shadow:0 0 25px <?=$brandColor?>60,0 0 50px <?=$brandColor?>30}}

.glow-search-wrap{position:relative;display:flex;align-items:center;justify-content:center}
.glow-search-wrap .glow-layer{position:absolute;z-index:0;overflow:hidden;height:100%;width:100%;border-radius:16px;filter:blur(3px)}
.glow-search-wrap .glow-layer::before{content:'';position:absolute;z-index:-1;width:600px;height:600px;top:50%;left:50%;transform:translate(-50%,-50%) rotate(80deg);background:conic-gradient(transparent,<?=$brandColor?>,transparent 10%,transparent 50%,<?=$brandHex2?>,transparent 60%);transition:transform 2s ease}
.glow-search-wrap:hover .glow-layer::before{transform:translate(-50%,-50%) rotate(-100deg)}
.glow-search-wrap:focus-within .glow-layer::before{transform:translate(-50%,-50%) rotate(440deg);transition-duration:4s}
.glow-search-wrap .glow-inner{position:absolute;z-index:0;overflow:hidden;height:calc(100% - 4px);width:calc(100% - 4px);border-radius:14px;filter:blur(.5px)}
.glow-search-wrap .glow-inner::before{content:'';position:absolute;z-index:-1;width:600px;height:600px;top:50%;left:50%;transform:translate(-50%,-50%) rotate(70deg);background:conic-gradient(#1c191c,<?=$brandColor?> 5%,#1c191c 14%,#1c191c 50%,<?=$brandHex2?> 60%,#1c191c 64%);filter:brightness(1.3);transition:transform 2s ease}
.glow-search-wrap:hover .glow-inner::before{transform:translate(-50%,-50%) rotate(-110deg)}
.glow-search-wrap:focus-within .glow-inner::before{transform:translate(-50%,-50%) rotate(430deg);transition-duration:4s}
.glow-search-wrap .glow-accent{position:absolute;z-index:0;overflow:hidden;height:calc(100% - 2px);width:calc(100% - 2px);border-radius:15px;filter:blur(2px)}
.glow-search-wrap .glow-accent::before{content:'';position:absolute;z-index:-1;width:600px;height:600px;top:50%;left:50%;transform:translate(-50%,-50%) rotate(82deg);background:conic-gradient(transparent 0%,<?=$brandColor?>80,transparent 8%,transparent 50%,<?=$brandHex2?>80,transparent 58%);filter:brightness(1.4);transition:transform 2s ease}
.glow-search-wrap:hover .glow-accent::before{transform:translate(-50%,-50%) rotate(-98deg)}
.glow-search-wrap:focus-within .glow-accent::before{transform:translate(-50%,-50%) rotate(442deg);transition-duration:4s}
.glow-input{position:relative;z-index:1;background:#010201;border:none;width:100%;height:56px;border-radius:14px;color:#fff;padding:0 52px;font-size:15px;outline:none;letter-spacing:.3px}
.glow-input::placeholder{color:#888;letter-spacing:.5px}
.glow-input:focus{background:#0a0a0a}
.glow-mask{pointer-events:none;position:absolute;width:80px;height:20px;background:linear-gradient(to right,transparent,#010201);top:18px;left:60px;z-index:1}
.glow-search-wrap:focus-within .glow-mask{display:none}
.search-anim svg{animation:searchPulse 2s ease-in-out infinite}
.orbit-dot{position:absolute;top:50%;left:50%;width:3px;height:3px;border-radius:50%;background:<?=$brandColor?>;animation:orbitDot 3s linear infinite;opacity:.6}
.orbit-dot:nth-child(2){animation-delay:-1s;background:<?=$brandHex2?>}
.orbit-dot:nth-child(3){animation-delay:-2s;opacity:.4}
.glow-submit{position:absolute;top:7px;right:7px;z-index:2;height:42px;width:42px;border-radius:10px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,<?=$brandColor?>,<?=$brandDark?>);color:#fff;transition:all .2s;overflow:hidden;animation:glowPulse 3s ease-in-out infinite}
.glow-submit:hover{transform:scale(1.05);filter:brightness(1.15)}
.glow-submit:active{transform:scale(.95)}
.glow-submit .spin-ring{position:absolute;inset:0;border-radius:10px;overflow:hidden;pointer-events:none}
.glow-submit .spin-ring::before{content:'';position:absolute;width:200px;height:200px;top:50%;left:50%;background:conic-gradient(transparent,rgba(255,255,255,.3),transparent 30%,transparent 50%,rgba(255,255,255,.2),transparent 80%);animation:glowSpin 3s linear infinite}

<?php elseif ($trackUi === 'glass'): ?>
/* ══════════════════════════
   FROSTED GLASS STYLE
   ══════════════════════════ */
@keyframes blobFloat1{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(30px,-20px) scale(1.1)}66%{transform:translate(-20px,15px) scale(.9)}}
@keyframes blobFloat2{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(-25px,20px) scale(1.15)}66%{transform:translate(15px,-25px) scale(.85)}}
@keyframes blobFloat3{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(20px,10px) scale(1.1)}}
@keyframes glassSearchPulse{0%,100%{transform:scale(1);opacity:.8}50%{transform:scale(1.06);opacity:1}}
@keyframes glassRingPulse{0%{box-shadow:0 0 0 0 rgba(<?=$brandRgb?>,.3)}70%{box-shadow:0 0 0 8px rgba(<?=$brandRgb?>,0)}100%{box-shadow:0 0 0 0 rgba(<?=$brandRgb?>,0)}}
@keyframes glassBorderShimmer{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}

.glass-search-area{position:relative;overflow:hidden;border-radius:24px;padding:32px 20px}
<?php if ($glassAnimBg): ?>
.glass-blob{position:absolute;border-radius:50%;filter:blur(60px);opacity:.5;pointer-events:none}
.glass-blob-1{width:160px;height:160px;background:<?=$brandColor?>;top:-40px;left:-20px;animation:blobFloat1 8s ease-in-out infinite}
.glass-blob-2{width:120px;height:120px;background:<?=$brandHex2?>;bottom:-30px;right:-10px;animation:blobFloat2 10s ease-in-out infinite}
.glass-blob-3{width:100px;height:100px;background:<?=$brandColor?>80;top:50%;left:50%;transform:translate(-50%,-50%);animation:blobFloat3 7s ease-in-out infinite}
<?php endif; ?>
.glass-card{position:relative;z-index:1;backdrop-filter:blur(<?=$glassBlur?>px);-webkit-backdrop-filter:blur(<?=$glassBlur?>px);
    background:rgba(255,255,255,<?=($glassOpacity/100)?>);
    border:1px solid rgba(<?=$brandRgb?>,<?=($glassBorder/100)?>);
    border-radius:18px;padding:24px;box-shadow:0 8px 32px rgba(0,0,0,.06)}
.glass-input-wrap{position:relative;border-radius:14px;overflow:hidden;
    background:rgba(255,255,255,.6);border:1.5px solid rgba(<?=$brandRgb?>,.2);transition:all .3s}
.glass-input-wrap:focus-within{border-color:rgba(<?=$brandRgb?>,.5);box-shadow:0 0 0 3px rgba(<?=$brandRgb?>,.1);background:rgba(255,255,255,.8)}
.glass-input{width:100%;height:52px;background:transparent;border:none;padding:0 50px 0 48px;font-size:15px;color:#1a1a2e;outline:none}
.glass-input::placeholder{color:rgba(<?=$brandRgb?>,.4)}
.glass-search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);z-index:2}
.glass-search-icon svg{animation:glassSearchPulse 2.5s ease-in-out infinite}
.glass-submit{position:absolute;top:5px;right:5px;z-index:2;height:42px;width:42px;border-radius:11px;border:none;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,<?=$brandColor?>,<?=$brandDark?>);
    color:#fff;transition:all .2s;animation:glassRingPulse 2s infinite}
.glass-submit:hover{transform:scale(1.05);filter:brightness(1.1)}
.glass-submit:active{transform:scale(.95)}
.glass-shimmer-border{position:absolute;inset:-1px;border-radius:19px;padding:1px;z-index:0;
    background:linear-gradient(90deg,transparent,rgba(<?=$brandRgb?>,.3),transparent,rgba(<?=$brandRgb?>,.15),transparent);
    background-size:200% 200%;animation:glassBorderShimmer 4s ease infinite;-webkit-mask:linear-gradient(#fff 0 0) content-box,linear-gradient(#fff 0 0);-webkit-mask-composite:xor;mask-composite:exclude}

<?php endif; ?>
</style>

<div class="track-wrapper" id="trackWrapper">
<div class="max-w-2xl mx-auto py-6 px-4">

    <!-- Theme Toggle Button -->
    <button class="track-theme-toggle" id="trackThemeToggle" onclick="toggleTrackTheme()" title="ডার্ক/লাইট মোড">
        <i class="fas fa-moon text-lg" id="trackThemeIcon"></i>
    </button>

    <!-- ═══════ SEARCH FORM ═══════ -->
    <div class="mb-6">
        <div class="text-center mb-5">
            <h1 class="text-xl font-bold text-gray-800 mb-1">অর্ডার ট্র্যাক করুন</h1>
            <p class="text-xs text-gray-400">আপনার অর্ডার নম্বর দিয়ে অবস্থা দেখুন</p>
        </div>

        <?php if ($trackUi === 'classic'): ?>
        <!-- ── CLASSIC STYLE ── -->
        <div class="bg-white rounded-2xl shadow-sm border p-5">
            <form method="POST">
                <div class="relative">
                    <div class="absolute left-4 top-1/2 -translate-y-1/2">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="<?=$brandColor?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </div>
                    <input name="order_number" type="text" value="<?= e($_POST['order_number'] ?? $_GET['q'] ?? '') ?>" required
                           placeholder="অর্ডার নম্বর — ORD-XXXXX"
                           class="w-full border border-gray-200 rounded-xl pl-12 pr-4 py-3 text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400 outline-none">
                </div>
                <button type="submit" class="w-full mt-3 py-2.5 rounded-xl font-semibold text-sm text-white hover:shadow-lg transition-all active:scale-[0.98]"
                        style="background:linear-gradient(135deg,<?=$brandColor?>,<?=$brandDark?>)">
                    <i class="fas fa-search mr-2"></i>ট্র্যাক করুন
                </button>
            </form>
        </div>

        <?php elseif ($trackUi === 'glow'): ?>
        <!-- ── NEON GLOW STYLE ── -->
        <form method="POST" class="flex justify-center">
            <div class="glow-search-wrap w-full max-w-md group">
                <div class="glow-layer"></div>
                <div class="glow-accent"></div>
                <div class="glow-accent"></div>
                <div class="glow-inner"></div>
                <div class="relative w-full" style="z-index:1">
                    <input name="order_number" type="text" value="<?= e($_POST['order_number'] ?? $_GET['q'] ?? '') ?>" required
                           placeholder="অর্ডার নম্বর — ORD-XXXXX" class="glow-input" autocomplete="off">
                    <div class="glow-mask"></div>
                    <div class="search-anim absolute left-4 top-1/2 -translate-y-1/2 z-2 w-6 h-6">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" stroke="url(#sg1)"/><line x1="21" y1="21" x2="16.65" y2="16.65" stroke="url(#sg2)"/>
                            <defs><linearGradient id="sg1" gradientTransform="rotate(50)"><stop offset="0%" stop-color="<?=$brandColor?>"/><stop offset="100%" stop-color="<?=$brandHex2?>"/></linearGradient>
                            <linearGradient id="sg2"><stop offset="0%" stop-color="<?=$brandHex2?>"/><stop offset="100%" stop-color="#666"/></linearGradient></defs>
                        </svg>
                        <div class="orbit-dot"></div><div class="orbit-dot"></div><div class="orbit-dot"></div>
                    </div>
                    <button type="submit" class="glow-submit" title="ট্র্যাক করুন">
                        <div class="spin-ring"></div>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="position:relative;z-index:1"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                </div>
            </div>
        </form>

        <?php elseif ($trackUi === 'glass'): ?>
        <!-- ── FROSTED GLASS STYLE ── -->
        <div class="glass-search-area">
            <?php if ($glassAnimBg): ?>
            <div class="glass-blob glass-blob-1"></div>
            <div class="glass-blob glass-blob-2"></div>
            <div class="glass-blob glass-blob-3"></div>
            <?php endif; ?>
            <div class="glass-card">
                <div class="glass-shimmer-border"></div>
                <form method="POST">
                    <div class="glass-input-wrap">
                        <div class="glass-search-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" stroke="<?=$brandColor?>"/>
                                <line x1="21" y1="21" x2="16.65" y2="16.65" stroke="<?=$brandColor?>90"/>
                            </svg>
                        </div>
                        <input name="order_number" type="text" value="<?= e($_POST['order_number'] ?? $_GET['q'] ?? '') ?>" required
                               placeholder="অর্ডার নম্বর — ORD-XXXXX" class="glass-input" autocomplete="off">
                        <button type="submit" class="glass-submit" title="ট্র্যাক করুন">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded-xl text-center text-sm">
        <i class="fas fa-exclamation-circle mr-1"></i><?= $error ?>
    </div>
    <?php endif; ?>

    <?php if ($order): ?>

    <!-- Order Header Card -->
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden mb-4">
        <div class="p-5 pb-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <h2 class="font-bold text-base text-gray-800">#<?= e($order['order_number']) ?></h2>
                        <button onclick="navigator.clipboard.writeText('<?= e($order['order_number']) ?>').then(()=>{this.innerHTML='<i class=\'fas fa-check text-green-500\'></i>';setTimeout(()=>this.innerHTML='<i class=\'fas fa-copy\'></i>',1200)})" class="text-gray-300 hover:text-gray-500 text-xs p-1" title="কপি"><i class="fas fa-copy"></i></button>
                    </div>
                    <p class="text-xs text-gray-400">
                        <i class="far fa-calendar-alt mr-1"></i><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
                        <span class="mx-1.5">•</span>
                        <i class="fas fa-box mr-1"></i><?= $totalItems ?> পণ্য
                    </p>
                </div>
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full border <?= $conf[1] ?>">
                    <i class="<?= $conf[2] ?> text-[10px]"></i><?= $conf[0] ?>
                </span>
            </div>
        </div>
        <?php if (!in_array($currentStatus, ['cancelled','returned','pending_return','pending_cancel','lost','on_hold'])): ?>
        <div class="px-5 pb-5">
            <div class="track-progress">
                <div class="track-line"><div class="track-line-fill" style="width:<?php
                    $stepIdx = array_search($currentStatus, $statusSteps);
                    if ($stepIdx === false) $stepIdx = 0;
                    echo ($stepIdx / (count($statusSteps) - 1)) * 100;
                ?>%"></div></div>
                <?php foreach ($statusSteps as $si => $step):
                    $sConf = $statusConfig[$step]; $isDone = $stepIdx > $si; $isActive = $stepIdx == $si;
                ?>
                <div class="track-step <?= $isDone ? 'done' : ($isActive ? 'active' : '') ?>">
                    <div class="step-dot"><i class="<?= $sConf[2] ?> text-xs"></i></div>
                    <span class="text-[10px] mt-1.5 text-center font-medium <?= ($isDone || $isActive) ? 'text-gray-700' : 'text-gray-400' ?>"><?= $sConf[0] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($shipment && !empty($shipment['tracking_id'])): ?>
        <div class="border-t mx-5"></div>
        <div class="p-4 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-purple-50 flex items-center justify-center"><i class="fas fa-truck text-purple-500 text-sm"></i></div>
                <div><p class="text-xs font-semibold text-gray-700"><?= e($shipment['courier_name'] ?? 'Courier') ?></p>
                <p class="text-[11px] text-gray-400">Tracking: <?= e($shipment['tracking_id']) ?></p></div>
            </div>
            <?php if (!empty($shipment['tracking_url'])): ?><a href="<?= e($shipment['tracking_url']) ?>" target="_blank" class="text-xs font-medium text-purple-600 hover:underline">ট্র্যাক করুন →</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Customer Info -->
    <div class="bg-white rounded-2xl shadow-sm border p-5 mb-4">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center"><i class="fas fa-user text-blue-500 text-sm"></i></div>
            <h3 class="font-semibold text-sm text-gray-800">গ্রাহক তথ্য</h3>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            <div><span class="text-gray-400 text-xs">নাম:</span><p class="font-medium text-gray-700"><?= e($order['customer_name']) ?></p></div>
            <div><span class="text-gray-400 text-xs">ফোন:</span><p class="font-medium text-gray-700"><?= e($order['customer_phone']) ?></p></div>
            <div class="sm:col-span-2"><span class="text-gray-400 text-xs">ঠিকানা:</span><p class="font-medium text-gray-700"><?= e($order['shipping_address'] ?? $order['customer_address'] ?? 'N/A') ?></p></div>
            <?php if (!empty($order['shipping_district'])): ?><div><span class="text-gray-400 text-xs">জেলা:</span><p class="font-medium text-gray-700"><?= e($order['shipping_district']) ?></p></div><?php endif; ?>
        </div>
    </div>

    <!-- Order Items -->
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden mb-4">
        <div class="p-5 pb-3">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-lg bg-green-50 flex items-center justify-center"><i class="fas fa-shopping-bag text-green-500 text-sm"></i></div>
                <h3 class="font-semibold text-sm text-gray-800">অর্ডার আইটেম</h3>
            </div>
            <div class="space-y-3">
                <?php foreach ($items as $item): 
                    $itemImageUrl = !empty($item['image']) ? uploadUrl($item['image']) : '';
                    $itemLink = !empty($item['slug']) ? url($item['slug']) : '#';
                ?>
                <div class="flex items-start gap-3">
                    <a href="<?= $itemLink ?>" class="flex-shrink-0">
                        <?php if ($itemImageUrl): ?>
                        <img src="<?= $itemImageUrl ?>" class="w-14 h-14 rounded-lg object-cover border" alt="<?= e($item['product_name']) ?>" loading="lazy" onerror="this.onerror=null;this.parentElement.innerHTML='<div class=\'w-14 h-14 rounded-lg bg-gray-100 flex items-center justify-center\'><i class=\'fas fa-box text-gray-300 text-lg\'></i></div>';">
                        <?php else: ?>
                        <div class="w-14 h-14 rounded-lg bg-gray-100 flex items-center justify-center"><i class="fas fa-box text-gray-300 text-lg"></i></div>
                        <?php endif; ?>
                    </a>
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-sm text-gray-800 leading-tight" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?= e($item['product_name']) ?></p>
                        <?php if (!empty($item['variant_name'])): ?><p class="text-[11px] text-gray-400 mt-0.5"><?= e($item['variant_name']) ?></p><?php endif; ?>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs text-gray-500"><?= formatPrice($item['price']) ?> × <?= intval($item['quantity']) ?></span>
                            <?php if (!empty($item['store_credit_enabled']) && floatval($item['store_credit_amount'] ?? 0) > 0 && !in_array($currentStatus, ['delivered','cancelled','returned'])): ?>
                            <span class="text-[9px] bg-yellow-100 text-yellow-700 px-1.5 py-0.5 rounded-full font-medium"><i class="fas fa-coins mr-0.5"></i><?= intval($item['store_credit_amount'] * $item['quantity']) ?> ক্রেডিট</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="font-bold text-sm text-gray-800 flex-shrink-0"><?= formatPrice($item['subtotal']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="border-t mx-5"></div>
        <div class="p-5 space-y-2 text-sm">
            <div class="flex justify-between text-gray-500"><span>সাবটোটাল</span><span class="font-medium text-gray-700"><?= formatPrice($order['subtotal']) ?></span></div>
            <?php if ($discountAmt > 0): ?><div class="flex justify-between text-green-600"><span><i class="fas fa-tag mr-1 text-xs"></i>ডিসকাউন্ট <?= !empty($order['coupon_code']) ? '(' . e($order['coupon_code']) . ')' : '' ?></span><span class="font-medium">-<?= formatPrice($discountAmt) ?></span></div><?php endif; ?>
            <?php if ($creditUsed > 0): ?><div class="flex justify-between items-center"><span class="flex items-center gap-1.5 text-yellow-700"><span class="w-5 h-5 rounded-full bg-yellow-100 flex items-center justify-center flex-shrink-0"><i class="fas fa-coins text-[9px] text-yellow-600"></i></span>স্টোর ক্রেডিট ব্যবহৃত</span><span class="font-semibold text-yellow-700">-<?= formatPrice($creditUsed) ?></span></div><?php endif; ?>
            <div class="flex justify-between text-gray-500"><span><i class="fas fa-truck mr-1 text-xs"></i>ডেলিভারি চার্জ</span><span class="font-medium text-gray-700"><?= floatval($order['shipping_cost']) > 0 ? formatPrice($order['shipping_cost']) : '<span class="text-green-600 font-semibold">ফ্রি</span>' ?></span></div>
            <div class="flex justify-between items-center font-bold text-base pt-2 border-t border-dashed"><span class="text-gray-800">মোট পরিশোধযোগ্য</span><span style="color:var(--primary,#2563eb)"><?= formatPrice($order['total']) ?></span></div>
            <?php $totalSaved = $discountAmt + $creditUsed; if ($totalSaved > 0): ?>
            <div class="flex justify-between items-center bg-green-50 rounded-xl px-3 py-2 -mx-1 mt-1"><span class="text-green-700 text-xs font-semibold"><i class="fas fa-piggy-bank mr-1"></i>আপনি সেভ করেছেন</span><span class="text-green-700 font-bold"><?= formatPrice($totalSaved) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($totalCreditsEarnable > 0 && !in_array($currentStatus, ['delivered','cancelled','returned'])): ?>
    <div class="bg-gradient-to-r from-yellow-50 to-amber-50 border border-yellow-200 rounded-2xl p-4 mb-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-yellow-100 flex items-center justify-center flex-shrink-0"><i class="fas fa-gift text-yellow-600"></i></div>
            <div><p class="text-sm font-semibold text-yellow-800">ডেলিভারির পর পাবেন <?= number_format($totalCreditsEarnable, 0) ?> ক্রেডিট পয়েন্ট!</p><p class="text-xs text-yellow-600 mt-0.5">পরবর্তী অর্ডারে ব্যবহার করতে পারবেন</p></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($statusHistory)): ?>
    <div class="bg-white rounded-2xl shadow-sm border p-5 mb-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center"><i class="fas fa-history text-gray-500 text-sm"></i></div>
            <h3 class="font-semibold text-sm text-gray-800">অর্ডার টাইমলাইন</h3>
        </div>
        <div>
            <?php foreach (array_reverse($statusHistory) as $idx => $sh):
                $shConf = $statusConfig[$sh['status']] ?? ['অজানা','bg-gray-100 text-gray-500','fas fa-circle','#6b7280'];
                $isFirst = $idx === 0;
            ?>
            <div class="tl-item">
                <div class="tl-dot" style="border-color:<?= $shConf[3] ?>;background:<?= $isFirst ? $shConf[3] : '#fff' ?>">
                    <?php if ($isFirst): ?><div style="width:6px;height:6px;border-radius:50%;background:#fff"></div><?php endif; ?>
                </div>
                <div class="<?= $isFirst ? '' : 'opacity-70' ?>">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold" style="color:<?= $shConf[3] ?>"><?= $shConf[0] ?></span>
                        <?php if ($isFirst): ?><span class="text-[9px] bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-full font-medium">সর্বশেষ</span><?php endif; ?>
                    </div>
                    <?php if (!empty($sh['note'])): ?><p class="text-xs text-gray-500 mt-0.5"><?= e($sh['note']) ?></p><?php endif; ?>
                    <p class="text-[11px] text-gray-400 mt-1"><i class="far fa-clock mr-0.5"></i><?= date('d M Y, h:i A', strtotime($sh['created_at'])) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-gray-50 rounded-2xl border border-gray-200 p-5">
        <div class="text-center">
            <p class="text-sm text-gray-500 mb-2">অর্ডার সংক্রান্ত কোনো সমস্যা?</p>
            <div class="flex items-center justify-center gap-3">
                <?php $supportPhone = getSetting('support_phone', ''); if ($supportPhone): ?>
                <a href="tel:<?= e($supportPhone) ?>" class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700"><i class="fas fa-phone-alt text-xs"></i>কল করুন</a>
                <span class="text-gray-300">|</span>
                <?php endif; ?>
                <?php $whatsapp = getSetting('whatsapp_number', ''); if ($whatsapp): ?>
                <a href="https://wa.me/<?= e($whatsapp) ?>?text=<?= urlencode('আমার অর্ডার #' . ($order['order_number'] ?? '') . ' নিয়ে জানতে চাই') ?>" target="_blank" class="inline-flex items-center gap-1.5 text-sm font-medium text-green-600 hover:text-green-700"><i class="fab fa-whatsapp"></i>WhatsApp</a>
                <?php endif; ?>
                <?php if (!$supportPhone && !$whatsapp): ?><span class="text-sm text-gray-400">আমাদের সাথে যোগাযোগ করুন</span><?php endif; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>
</div><!-- /track-wrapper -->

<script>
function toggleTrackTheme() {
    const w = document.getElementById('trackWrapper');
    const icon = document.getElementById('trackThemeIcon');
    const isDark = w.classList.toggle('dark-theme');
    icon.className = isDark ? 'fas fa-sun text-lg text-yellow-400' : 'fas fa-moon text-lg';
    localStorage.setItem('track_theme', isDark ? 'dark' : 'light');
}
// Restore saved theme
(function(){
    var saved = localStorage.getItem('track_theme');
    if (saved === 'dark') {
        var w = document.getElementById('trackWrapper');
        var icon = document.getElementById('trackThemeIcon');
        if (w) { w.classList.add('dark-theme'); }
        if (icon) { icon.className = 'fas fa-sun text-lg text-yellow-400'; }
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
