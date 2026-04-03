<?php
/**
 * Product Card Component — Multi-Template, Fully Editable
 * Templates: standard | minimal | detailed | clean
 */
$imgUrl = getProductImage($product);
$price = getProductPrice($product);
$discount = getDiscountPercent($product);
$isOnSale = $product['sale_price'] && $product['sale_price'] > 0 && $product['sale_price'] < $product['regular_price'];
$btnAddLabel = getSetting('btn_add_to_cart_label', 'কার্টে যোগ করুন');
$btnArchiveOrder = getSetting('btn_archive_order_label', 'অর্ডার করুন');

$arShowButtons = getSetting('ar_show_card_buttons', '1') === '1';
$arShowOverlay = getSetting('ar_show_overlay', '1') === '1';
$arShowDiscount = getSetting('ar_show_discount_badge', '1') === '1';
$arShowOrderBtn = getSetting('ar_show_order_btn', '1') === '1';
$arShowCartBtn = getSetting('ar_show_cart_btn', '1') === '1';
$arCardStyle = getSetting('ar_card_style', 'standard');

// Clean card editable settings
$cleanBtnStyle    = getSetting('ar_clean_btn_style', 'outlined');
$cleanBtnLabel    = getSetting('ar_clean_btn_label', 'Add To Cart');
$cleanBadgePos    = getSetting('ar_clean_badge_pos', 'top_right');
$cleanShowOffered = getSetting('ar_clean_show_offered', '1') === '1';
$cleanOfferedLabel= getSetting('ar_clean_offered_label', 'Offered Items');
$cleanCardBorder  = getSetting('ar_clean_card_border', '1') === '1';
$cleanCardRadius  = intval(getSetting('ar_clean_card_radius', '8'));
$cleanImgFit      = getSetting('ar_clean_img_fit', 'contain');
$cleanImgBg       = getSetting('ar_clean_img_bg', '#ffffff');
$cleanNameLines   = intval(getSetting('ar_clean_name_lines', '1'));
$cleanOldPrice    = getSetting('ar_clean_old_price', '1') === '1';
$cleanBtnIcon     = getSetting('ar_clean_btn_icon', 'fa-cart-arrow-down');
$cleanBadgeColor  = getSetting('ar_clean_badge_color', '#22c55e');
$cleanBadgeTxt    = getSetting('ar_clean_badge_text_color', '#ffffff');

// ── Variant / price logic (shared) ──
$_hasVariants = false; $_varPriceRange = null; $_varDiscountRange = null; $_varRegMax = null;
try {
    static $_variantCache = [];
    if (!isset($_variantCache[$product['id']])) {
        $db = Database::getInstance();
        $_comboData = null;
        try {
            $prodVarMode = $db->fetch("SELECT variation_mode FROM products WHERE id = ?", [$product['id']]);
            if ($prodVarMode && ($prodVarMode['variation_mode'] ?? '') === 'combination') {
                $_comboData = $db->fetch(
                    "SELECT MIN(CASE WHEN sale_price > 0 AND sale_price < regular_price THEN sale_price ELSE regular_price END) as pmin,
                            MAX(CASE WHEN sale_price > 0 AND sale_price < regular_price THEN sale_price ELSE regular_price END) as pmax,
                            MAX(regular_price) as reg_max, COUNT(*) as cnt
                     FROM product_variant_combinations WHERE product_id = ? AND is_active = 1", [$product['id']]);
            }
        } catch (\Throwable $e) {}
        $legacyHas = $db->fetch("SELECT COUNT(*) as cnt FROM product_variants WHERE product_id = ? AND is_active = 1", [$product['id']])['cnt'] > 0;
        $legacyRange = $db->fetch("SELECT MIN(absolute_price) as pmin, MAX(absolute_price) as pmax, MAX(COALESCE(var_regular_price, absolute_price)) as reg_max FROM product_variants WHERE product_id = ? AND is_active = 1 AND option_type = 'variation' AND absolute_price > 0", [$product['id']]);
        if ($_comboData && $_comboData['cnt'] > 0 && $_comboData['pmin'] > 0) {
            $_variantCache[$product['id']] = ['has' => true, 'range' => $_comboData];
        } else {
            $_variantCache[$product['id']] = ['has' => $legacyHas, 'range' => $legacyRange];
        }
    }
    $_hasVariants = $_variantCache[$product['id']]['has'];
    $r = $_variantCache[$product['id']]['range'];
    if ($r && $r['pmin'] > 0 && $r['pmax'] > 0) {
        $_varPriceRange = ['min' => floatval($r['pmin']), 'max' => floatval($r['pmax'])];
        $regP = floatval($r['reg_max'] ?? $product['regular_price'] ?? 0);
        $_varRegMax = $regP;
        if ($regP > 0 && $regP > $_varPriceRange['min']) {
            $discMax = round(($regP - $_varPriceRange['min']) / $regP * 100);
            $discMin = round(($regP - $_varPriceRange['max']) / $regP * 100);
            if ($discMax > 0) $_varDiscountRange = ['min' => max(0, $discMin), 'max' => $discMax];
        }
    }
} catch (\Throwable $e) {}

$_outOfStock = ($product['stock_status'] ?? '') === 'out_of_stock';
$_productUrl = url('product/' . $product['slug']);
$_productName = htmlspecialchars($product['name_bn'] ?: $product['name']);
$_discountText = '';
if ($_varDiscountRange) {
    $_discountText = ($_varDiscountRange['min'] === $_varDiscountRange['max'] || $_varDiscountRange['min'] <= 0) ? $_varDiscountRange['max'].'%' : $_varDiscountRange['min'].'~'.$_varDiscountRange['max'].'%';
} elseif ($isOnSale && $discount > 0) {
    $_discountText = $discount.'%';
}
$_isFeatured = !empty($product['is_featured']);
$__variantLabel = $product['variant_label'] ?? null;
?>

<?php if ($arCardStyle === 'clean'): ?>
<div class="pcc-card">
    <a href="<?= $_productUrl ?>" class="pcc-img-wrap" style="background:<?= e($cleanImgBg) ?>">
        <img src="<?= $imgUrl ?>" alt="<?= $_productName ?>" loading="lazy"
             onerror="this.onerror=null;this.src='<?= asset('img/default-product.svg') ?>'">
        <?php if ($arShowDiscount && $_discountText): ?>
        <span class="pcc-badge pcc-badge-<?= $cleanBadgePos ?>">Save <?= $_discountText ?></span>
        <?php endif; ?>
        <?php if ($cleanShowOffered && $_isFeatured && $cleanOfferedLabel): ?>
        <span class="pcc-offered pcc-offered-<?= ($cleanBadgePos === 'top_right') ? 'top_left' : 'top_right' ?>"><?= e($cleanOfferedLabel) ?></span>
        <?php endif; ?>
        <?php if ($__variantLabel): ?>
        <span class="pcc-offered pcc-offered-top_left"><?= htmlspecialchars($__variantLabel) ?></span>
        <?php endif; ?>
        <?php if ($_outOfStock): ?>
        <div class="pcc-soldout"><span>স্টক শেষ</span></div>
        <?php endif; ?>
    </a>
    <div class="pcc-info">
        <a href="<?= $_productUrl ?>" class="pcc-name card-name"><?= $_productName ?></a>
        <div class="pcc-price">
            <?php if ($_varPriceRange): ?>
            <span class="pcc-price-now"><?= ($_varPriceRange['min'] == $_varPriceRange['max']) ? formatPrice($_varPriceRange['min']) : formatPrice($_varPriceRange['min']).' – '.formatPrice($_varPriceRange['max']) ?></span>
            <?php if ($cleanOldPrice && $_varDiscountRange): ?><span class="pcc-price-was"><?= formatPrice($_varRegMax ?? $product['regular_price']) ?></span><?php endif; ?>
            <?php else: ?>
            <span class="pcc-price-now"><?= formatPrice($price) ?></span>
            <?php if ($cleanOldPrice && $isOnSale): ?><span class="pcc-price-was"><?= formatPrice($product['regular_price']) ?></span><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($arShowButtons && !$_outOfStock): ?>
        <div class="pcc-actions">
            <?php if ($arShowCartBtn): ?>
            <button onclick="smartAddToCart(<?= $product['id'] ?>)" class="pcc-btn pcc-btn-<?= $cleanBtnStyle ?> card-button">
                <i class="fas <?= e($cleanBtnIcon) ?>"></i> <span><?= e($cleanBtnLabel) ?></span>
            </button>
            <?php endif; ?>
            <?php if ($arShowOrderBtn): ?>
            <button onclick="smartOrder(<?= $product['id'] ?>)" class="pcc-btn pcc-btn-filled card-button">
                <i class="fas fa-shopping-bag"></i> <span><?= e($btnArchiveOrder) ?></span>
            </button>
            <?php endif; ?>
        </div>
        <?php elseif ($_outOfStock): ?>
        <div class="pcc-actions"><span class="pcc-btn-sold">স্টক শেষ</span></div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<div class="pc-card group">
    <a href="<?= $_productUrl ?>" class="pc-img-wrap">
        <img src="<?= $imgUrl ?>" alt="<?= $_productName ?>" loading="lazy"
             onerror="this.onerror=null;this.src='<?= asset('img/default-product.svg') ?>'">
        <?php if ($arShowDiscount && $_varDiscountRange): ?>
        <span class="pc-badge card-badge"><?php echo ($_varDiscountRange['min'] === $_varDiscountRange['max'] || $_varDiscountRange['min'] <= 0) ? '-'.$_varDiscountRange['max'].'%' : '-'.$_varDiscountRange['min'].'~'.$_varDiscountRange['max'].'%'; ?></span>
        <?php elseif ($arShowDiscount && $isOnSale && $discount > 0): ?>
        <span class="pc-badge card-badge">-<?= $discount ?>%</span>
        <?php endif; ?>
        <?php if ($__variantLabel): ?><span class="pc-variant-badge"><?= htmlspecialchars($__variantLabel) ?></span><?php endif; ?>
        <?php if ($_outOfStock): ?><div class="pc-soldout"><span>স্টক শেষ</span></div><?php endif; ?>
        <?php if ($arShowOverlay && !$_outOfStock): ?>
        <div class="pc-hover-actions">
            <button onclick="event.preventDefault();event.stopPropagation();smartAddToCart(<?= $product['id'] ?>)" title="<?= $btnAddLabel ?>"><i class="fas fa-cart-plus"></i></button>
            <button onclick="event.preventDefault();event.stopPropagation();smartOrder(<?= $product['id'] ?>)" title="<?= $btnArchiveOrder ?>"><i class="fas fa-shopping-bag"></i></button>
        </div>
        <?php endif; ?>
    </a>
    <div class="pc-info">
        <a href="<?= $_productUrl ?>" class="pc-name card-name"><?= $_productName ?></a>
        <div class="pc-price">
            <?php if ($_varPriceRange): ?>
            <span class="pc-price-now"><?= ($_varPriceRange['min'] == $_varPriceRange['max']) ? formatPrice($_varPriceRange['min']) : formatPrice($_varPriceRange['min']).' – '.formatPrice($_varPriceRange['max']) ?></span>
            <?php if ($_varDiscountRange): ?><span class="pc-price-was"><?= formatPrice($_varRegMax ?? $product['regular_price']) ?></span><?php endif; ?>
            <?php else: ?>
            <span class="pc-price-now"><?= formatPrice($price) ?></span>
            <?php if ($isOnSale): ?><span class="pc-price-was"><?= formatPrice($product['regular_price']) ?></span><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($arShowButtons && !$_outOfStock): ?>
        <?php if ($arCardStyle === 'minimal'): ?>
        <?php elseif ($arCardStyle === 'detailed'): ?>
        <div class="pc-actions"><button onclick="smartOrder(<?= $product['id'] ?>)" class="pc-btn-order pc-btn-full card-button"><i class="fas fa-shopping-bag"></i> <span><?= $btnArchiveOrder ?></span></button></div>
        <?php else: ?>
        <?php $showCart=$arShowCartBtn; $showOrder=$arShowOrderBtn; if(!$showCart&&!$showOrder)$showOrder=true; ?>
        <?php if ($showCart && $showOrder): ?>
        <div class="pc-actions">
            <button onclick="smartAddToCart(<?= $product['id'] ?>)" class="pc-btn-cart card-button"><i class="fas fa-cart-plus"></i> <span><?= $btnAddLabel ?></span></button>
            <button onclick="smartOrder(<?= $product['id'] ?>)" class="pc-btn-order card-button"><i class="fas fa-shopping-bag"></i> <span><?= $btnArchiveOrder ?></span></button>
        </div>
        <?php elseif ($showOrder): ?>
        <div class="pc-actions"><button onclick="smartOrder(<?= $product['id'] ?>)" class="pc-btn-order pc-btn-full card-button"><i class="fas fa-shopping-bag"></i> <span><?= $btnArchiveOrder ?></span></button></div>
        <?php elseif ($showCart): ?>
        <div class="pc-actions"><button onclick="smartAddToCart(<?= $product['id'] ?>)" class="pc-btn-cart pc-btn-full card-button"><i class="fas fa-cart-plus"></i> <span><?= $btnAddLabel ?></span></button></div>
        <?php endif; ?>
        <?php endif; ?>
        <?php elseif ($_outOfStock): ?>
        <div class="pc-actions"><span class="pc-btn-sold">স্টক শেষ</span></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (empty($GLOBALS['_pcStylesPrinted'])): $GLOBALS['_pcStylesPrinted'] = true; ?>
<style>
/* ═══ CLEAN CARD (screenshot design) ═══ */
.pcc-card{background:#fff;border-radius:<?= $cleanCardRadius ?>px;overflow:hidden;display:flex;flex-direction:column;height:100%;<?= $cleanCardBorder ? 'border:1px solid #e5e7eb;' : 'box-shadow:0 1px 3px rgba(0,0,0,.06);' ?>transition:box-shadow .25s,transform .25s;position:relative}
.pcc-img-wrap{display:block;position:relative;aspect-ratio:1/1;overflow:hidden;flex-shrink:0;padding:12px}
.pcc-img-wrap img{width:100%;height:100%;object-fit:<?= $cleanImgFit ?>;transition:transform .4s ease}
.pcc-badge{position:absolute;background:<?= e($cleanBadgeColor) ?>;color:<?= e($cleanBadgeTxt) ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:4px;z-index:2;line-height:1.4;white-space:nowrap}
.pcc-badge-top_right{top:10px;right:10px}.pcc-badge-top_left{top:10px;left:10px}.pcc-badge-bottom_right{bottom:10px;right:10px}.pcc-badge-bottom_left{bottom:10px;left:10px}
.pcc-offered{position:absolute;background:<?= e($cleanBadgeColor) ?>;color:<?= e($cleanBadgeTxt) ?>;font-size:10px;font-weight:700;padding:3px 10px;border-radius:4px;z-index:2;line-height:1.4;white-space:nowrap}
.pcc-offered-top_right{top:10px;right:10px}.pcc-offered-top_left{top:10px;left:10px}
.pcc-soldout{position:absolute;inset:0;background:rgba(255,255,255,.75);display:flex;align-items:center;justify-content:center;z-index:3}
.pcc-soldout span{background:#f3f4f6;color:#6b7280;padding:6px 18px;border-radius:20px;font-size:13px;font-weight:700}
.pcc-info{padding:0 14px 14px;display:flex;flex-direction:column;flex:1;gap:4px}
.pcc-name{display:-webkit-box;-webkit-line-clamp:<?= $cleanNameLines ?>;-webkit-box-orient:vertical;overflow:hidden;font-size:var(--fs-card-name,14px);font-weight:500;line-height:1.4;color:#1f2937;text-decoration:none}
.pcc-price{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-top:2px}
.pcc-price-now{font-size:var(--fs-card-price,16px);font-weight:700;color:var(--price-color,var(--primary,#E53E3E))}
.pcc-price-was{font-size:var(--fs-card-old-price,13px);color:var(--old-price-color,#9ca3af);text-decoration:line-through}
.pcc-actions{display:flex;gap:6px;margin-top:auto;padding-top:10px}
.pcc-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 8px;border-radius:6px;cursor:pointer;font-size:var(--fs-card-button,13px);font-family:inherit;font-weight:500;line-height:1.2;transition:all .2s;white-space:nowrap}
.pcc-btn i{font-size:13px;flex-shrink:0}
.pcc-btn-outlined{background:transparent;border:1.5px solid var(--primary,#E53E3E);color:var(--primary,#E53E3E)}
.pcc-btn-outlined:hover{background:var(--primary,#E53E3E);color:#fff}
.pcc-btn-filled{background:var(--btn-primary,#E53E3E);color:var(--btn-primary-text,#fff);border:1.5px solid transparent}
.pcc-btn-filled:hover{filter:brightness(1.1)}
.pcc-btn-ghost{background:#f3f4f6;color:#374151;border:1.5px solid transparent}
.pcc-btn-ghost:hover{background:#e5e7eb}
.pcc-btn:active{transform:scale(.96)}
.pcc-btn-sold{display:block;text-align:center;width:100%;padding:9px;border-radius:6px;background:#f3f4f6;color:#9ca3af;font-size:13px;font-weight:600}
@media(min-width:1024px){.pcc-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);transform:translateY(-2px)}.pcc-card:hover .pcc-img-wrap img{transform:scale(1.04)}}
@media(max-width:380px){.pcc-info{padding:0 10px 10px}.pcc-name{font-size:12px}.pcc-price-now{font-size:14px}.pcc-btn{padding:7px 4px;font-size:11px}.pcc-img-wrap{padding:8px}}

/* ═══ ORIGINAL CARDS (standard/minimal/detailed) ═══ */
.pc-card{background:#fff;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;height:100%;box-shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);transition:box-shadow .25s,transform .25s;position:relative}
.pc-img-wrap{display:block;position:relative;aspect-ratio:1/1;overflow:hidden;background:#f9fafb;flex-shrink:0}
.pc-img-wrap img{width:100%;height:100%;object-fit:cover;transition:transform .5s cubic-bezier(.25,.46,.45,.94)}
.pc-badge{position:absolute;top:8px;left:8px;background:var(--sale-badge,#E53E3E);color:var(--sale-badge-text,#fff);font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;line-height:1.4;z-index:2}
.pc-variant-badge{position:absolute;top:8px;right:8px;background:rgba(79,70,229,.88);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;line-height:1.4;z-index:2;backdrop-filter:blur(4px)}
.pc-soldout{position:absolute;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:3}
.pc-soldout span{background:#fff;color:#374151;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700}
.pc-hover-actions{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:center;gap:8px;padding:12px;background:linear-gradient(to top,rgba(0,0,0,.55)0%,transparent 100%);opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s;z-index:4}
.pc-hover-actions button{width:40px;height:40px;border-radius:50%;background:#fff;color:#374151;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:15px;box-shadow:0 2px 8px rgba(0,0,0,.15);transition:background .2s,color .2s,transform .15s}
.pc-hover-actions button:first-child:hover{background:var(--btn-cart,#DD6B20);color:#fff;transform:scale(1.1)}
.pc-hover-actions button:last-child:hover{background:var(--btn-primary,#E53E3E);color:#fff;transform:scale(1.1)}
.pc-info{padding:10px;display:flex;flex-direction:column;flex:1;gap:4px}
.pc-name{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:var(--fs-card-name,13px);font-weight:600;line-height:1.4;color:#1f2937;text-decoration:none;min-height:2.8em;transition:color .2s}
.pc-price{display:flex;align-items:baseline;gap:6px;flex-wrap:wrap;margin-top:2px}
.pc-price-now{font-size:var(--fs-card-price,16px);font-weight:700;color:var(--price-color,var(--primary,#E53E3E));letter-spacing:-.02em}
.pc-price-was{font-size:var(--fs-card-old-price,12px);color:var(--old-price-color,#9ca3af);text-decoration:line-through}
.pc-actions{display:flex;gap:6px;margin-top:auto;padding-top:8px}
.pc-btn-cart,.pc-btn-order{flex:1;display:flex;align-items:center;justify-content:center;gap:5px;padding:8px 4px;border-radius:8px;border:none;cursor:pointer;font-size:12px;font-family:inherit;font-weight:600;line-height:1.2;transition:filter .2s,transform .1s;white-space:nowrap;overflow:hidden}
.pc-btn-cart{background:var(--btn-cart,#DD6B20);color:var(--btn-cart-text,#fff)}
.pc-btn-order{background:var(--btn-primary,#E53E3E);color:var(--btn-primary-text,#fff)}
.pc-btn-cart i,.pc-btn-order i{font-size:12px;flex-shrink:0}
.pc-btn-cart span,.pc-btn-order span{overflow:hidden;text-overflow:ellipsis}
.pc-btn-cart:active,.pc-btn-order:active{transform:scale(.96)}
.pc-btn-full{flex:1 1 100%}
.pc-btn-sold{display:block;text-align:center;width:100%;padding:8px;border-radius:8px;background:#f3f4f6;color:#9ca3af;font-size:12px;font-weight:600}
@media(min-width:640px){.pc-info{padding:12px;gap:5px}.pc-name{font-size:14px}.pc-price-now{font-size:17px}.pc-price-was{font-size:13px}.pc-btn-cart,.pc-btn-order{padding:9px 6px;font-size:13px;border-radius:9px}.pc-actions{gap:8px}}
@media(min-width:1024px){.pc-card:hover{box-shadow:0 8px 25px rgba(0,0,0,.1),0 4px 10px rgba(0,0,0,.05);transform:translateY(-2px)}.pc-card:hover .pc-img-wrap img{transform:scale(1.06)}.pc-card:hover .pc-hover-actions{opacity:1;transform:translateY(0)}.pc-card:hover .pc-name{color:var(--primary,#E53E3E)}.pc-btn-cart:hover,.pc-btn-order:hover{filter:brightness(1.1)}.pc-info{padding:14px}.pc-name{font-size:14px}.pc-price-now{font-size:18px}.pc-btn-cart,.pc-btn-order{padding:10px 8px;font-size:13px;border-radius:10px}}
@media(max-width:380px){.pc-info{padding:8px}.pc-name{font-size:12px;min-height:2.6em}.pc-price-now{font-size:15px}.pc-price-was{font-size:11px}.pc-btn-cart span,.pc-btn-order span{font-size:11px}.pc-btn-cart,.pc-btn-order{padding:7px 3px;gap:3px}.pc-badge{font-size:10px;padding:1px 6px}}
</style>
<?php endif; ?>
