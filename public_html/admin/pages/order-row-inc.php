<?php // order-row-inc.php — row template for incomplete orders in order-management ?>
<tr class="om-pre <?= $isRec?'opacity-50':'' ?>">
    <td style="vertical-align:middle"><input type="checkbox" class="order-check" value="inc-<?= $inc['id'] ?>"></td>
    <td data-col="date" style="white-space:nowrap">
        <div style="font-size:13px;color:#374151"><?= date('d M, h:i a', strtotime($inc['created_at'])) ?></div>
        <div style="font-size:11px;color:#9ca3af">TEMP-<?= $inc['id'] ?></div>
    </td>
    <td data-col="invoice" style="white-space:nowrap"><span style="font-weight:600;color:#64748b;font-size:13px">TEMP-<?= $inc['id'] ?></span></td>
    <td data-col="customer">
        <?php if(!empty($inc['customer_phone'])):?><div style="display:flex;align-items:center;gap:5px;margin-bottom:2px"><span style="color:#9ca3af;font-size:11px">📞</span><span style="font-size:12px;color:#374151;font-weight:500"><?= e($inc['customer_phone']) ?></span><a href="tel:<?= e($ph) ?>" style="color:#3b82f6;font-size:11px">📱</a><a href="https://wa.me/88<?= $ph ?>" target="_blank" style="color:#22c55e;font-size:11px">💬</a></div><?php endif;?>
        <?php if(!empty($inc['customer_name'])):?><div style="display:flex;align-items:center;gap:5px;margin-bottom:2px"><span style="color:#9ca3af;font-size:11px">👤</span><span style="font-size:13px;font-weight:500;color:#374151"><?= e($inc['customer_name']) ?></span></div><?php endif;?>
        <?php if(!empty($inc['customer_address'])):?><div style="display:flex;align-items:center;gap:5px"><span style="color:#9ca3af;font-size:11px">📍</span><span style="font-size:12px;color:#9ca3af"><?= e(mb_strimwidth($inc['customer_address'],0,28,'...')) ?></span></div>
        <?php elseif(empty($inc['customer_name'])&&empty($inc['customer_phone'])):?><span style="font-size:12px;color:#d1d5db">Unknown visitor</span><?php endif;?>
    </td>
    <td data-col="note">
        <div style="font-size:11px;color:#9ca3af;margin-bottom:2px">Updated <?= timeAgo($inc['created_at']) ?></div>
        <?php if($__isTooNew):?><div style="font-size:10px;color:#f59e0b;font-weight:600">⚠️ <?= $__ageMin ?>m ago</div><?php endif;?>
    </td>
    <td data-col="products" style="cursor:pointer" onclick='showIncProducts(<?= htmlspecialchars(json_encode($cart, JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES) ?>, "TEMP-<?= $inc['id'] ?>")'>
        <div style="display:inline-flex;align-items:center;gap:3px;margin-bottom:3px">
            <span style="width:7px;height:7px;border-radius:50%;background:<?=$stepClr?>"></span>
            <span style="font-size:9px;font-weight:600;padding:1px 6px;border-radius:12px;background:<?=$stepClr?>22;color:<?=$stepClr?>"><?= strtoupper(str_replace('_',' ',$inc['step_reached']??'CART')) ?></span>
        </div>
        <?php foreach(array_slice($cart,0,2) as $ci): $pid=intval($ci['product_id']??$ci['id']??0); $pImg=$incProductImages[$pid]['featured_image']??''; $pName=$ci['name']??$ci['product_name']??($incProductImages[$pid]['name']??'Product');?>
        <div style="display:flex;align-items:center;gap:5px;margin-top:3px">
            <div style="width:32px;height:32px;border-radius:6px;border:1px solid #e5e7eb;overflow:hidden;flex-shrink:0;background:#f9fafb"><?php if($pImg):?><img src="<?=imgSrc('products',$pImg)?>" style="width:100%;height:100%;object-fit:cover" loading="lazy"><?php else:?><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#d1d5db;font-size:14px">📦</div><?php endif;?></div>
            <div style="overflow:hidden"><div style="font-size:12px;color:#374151;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px"><?=e($pName)?></div><div style="font-size:10px;color:#9ca3af"><?php if(!empty($ci['variant_name']??$ci['variant']??'')):?><?=e(mb_strimwidth($ci['variant_name']??$ci['variant']??'',0,16,'..'))?>·<?php endif;?>Qty: <?=intval($ci['qty']??$ci['quantity']??1)?></div></div>
        </div>
        <?php endforeach; if(count($cart)>2):?><div style="font-size:10px;color:#9ca3af;margin-top:2px">+<?=count($cart)-2?> more</div><?php endif;?>
    </td>
    <td data-col="tags"><span style="font-size:9px;padding:2px 6px;border-radius:10px;background:#f1f5f9;color:#64748b;font-weight:600">Incomplete</span></td>
    <td data-col="total" style="text-align:right;white-space:nowrap"><div style="font-weight:700;color:#111827;font-size:13px">৳<?=number_format($cartTotal)?></div></td>
    <td data-col="rate" style="white-space:nowrap">
        <?php if($sr['total']>0):?>
        <div style="display:flex;align-items:center;gap:6px">
            <svg width="36" height="36" viewBox="0 0 36 36" style="flex-shrink:0;transform:rotate(-90deg)"><circle cx="18" cy="18" r="16" fill="none" stroke="#e5e7eb" stroke-width="3"/><circle cx="18" cy="18" r="16" fill="none" stroke="<?=$__rClr?>" stroke-width="3" stroke-dasharray="<?=$__dash?> <?=$__gap?>" stroke-linecap="round"/></svg>
            <div style="line-height:1.3"><div style="font-size:11px;font-weight:700;color:<?=$__rTxt?>">Success: <?=$sr['rate']?>%</div><div style="font-size:10px;color:#6b7280">Order: <?=$sr['delivered']?>/<?=$sr['total']?></div></div>
        </div>
        <?php else:?><span style="color:#d1d5db">0</span><?php endif;?>
    </td>
    <td data-col="upload"><span style="color:#d1d5db">—</span></td>
    <td data-col="print" style="text-align:center"><span style="color:#d1d5db">—</span></td>
    <td data-col="user"><span style="color:#d1d5db">—</span></td>
    <td data-col="source"><span style="font-size:9px;font-weight:600;color:#64748b">WEB</span></td>
    <td data-col="shipping"><span style="color:#d1d5db">—</span></td>
    <td style="text-align:center;vertical-align:middle">
        <?php if(!$isRec):?>
        <div style="display:flex;align-items:center;justify-content:center;gap:4px">
            <button onclick='openIncomplete(<?=json_encode($inc,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>,<?=json_encode($cart,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>,<?=$__isTooNew?'true':'false'?>,<?=$__ageMin?>)' style="display:inline-flex;align-items:center;gap:3px;font-size:11px;color:#16a34a;background:transparent;padding:5px 10px;border-radius:6px;border:none;cursor:pointer;font-weight:500">Open <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></button>
            <?php if(!empty($inc['customer_phone'])):?>
            <button onclick='confirmIncomplete(<?=json_encode($inc,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>,<?=json_encode($cart,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>,<?=$__isTooNew?'true':'false'?>,<?=$__ageMin?>)' style="font-size:11px;background:#16a34a;color:#fff;padding:5px 10px;border-radius:6px;border:none;cursor:pointer;font-weight:600">✓ Confirm</button>
            <?php endif;?>
        </div>
        <?php else:?><span style="font-size:11px;color:#16a34a">✅ Recovered</span><?php endif;?>
    </td>
</tr>
