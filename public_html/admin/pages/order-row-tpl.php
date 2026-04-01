<?php // order-row-tpl.php — single source of truth for order table rows
$__userDisplay = $order['last_action_by'] ?? $order['assigned_name'] ?? '—';
$__rClr=$sr['rate']>=70?'#22c55e':($sr['rate']>=40?'#eab308':'#ef4444');
$__rTxt=$sr['rate']>=70?'#16a34a':($sr['rate']>=40?'#ca8a04':'#dc2626');
$__cBreak=$sr['courier_breakdown']??[];
$__circ=100.53;$__dash=($sr['rate']/100)*$__circ;$__gap=$__circ-$__dash;
// Pre-confirmation = minimal UI with donut; Post-confirmation = detailed/compact
$__preConf = in_array($order['order_status'], ['processing','pending','no_response','good_but_no_response','advance_payment','incomplete']);
$tagColors=['REPEAT'=>'bg-orange-100 text-orange-700','URGENT'=>'bg-red-100 text-red-700','VIP'=>'bg-purple-100 text-purple-700','GIFT'=>'bg-pink-100 text-pink-700','COD VERIFIED'=>'bg-green-100 text-green-700','ADVANCE PAID'=>'bg-emerald-100 text-emerald-700','FOLLOW UP'=>'bg-blue-100 text-blue-700'];
?>
<tr data-order-id="<?= $order['id'] ?>" class="<?= $__preConf ? 'om-pre' : 'om-post' ?>">
    <td style="vertical-align:middle"><input type="checkbox" name="order_ids[]" value="<?= $order['id'] ?>" class="order-check" onchange="updateBulk()"></td>

    <!-- Date -->
    <td data-col="date" style="white-space:nowrap">
        <?php if($__preConf): ?>
            <div style="font-size:13px;color:#374151"><?= date('d M, h:i a', strtotime($order['created_at'])) ?></div>
            <div style="font-size:11px;color:#9ca3af">ID: <?= $order['id'] ?></div>
        <?php else: ?>
            <div style="font-size:11px;font-weight:500;color:#334155"><?= date('d/m/Y, h:i a', strtotime($order['created_at'])) ?></div>
            <div style="font-size:9px;color:#94a3b8">Updated <?= timeAgo($order['updated_at']?:$order['created_at']) ?></div>
        <?php endif; ?>
    </td>

    <!-- Invoice -->
    <td data-col="invoice" style="white-space:nowrap">
        <a href="<?= adminUrl('pages/order-view.php?order='.urlencode($order['order_number'])) ?>" style="font-weight:600;color:#111827;font-size:<?=$__preConf?'13':'12'?>px;text-decoration:none" onmouseover="this.style.color='#2563eb'" onmouseout="this.style.color='#111827'"><?= e($order['order_number']) ?></a>
        <span class="dot-menu" onclick="toggleRowMenu(this,<?= $order['id'] ?>,'<?= e($order['order_number']) ?>','<?= e($order['order_status']) ?>')" style="margin-left:2px">⋮</span>
        <?php if (!empty($order['is_preorder'])): ?><br><span style="font-size:8px;background:#f3e8ff;color:#7c3aed;padding:1px 5px;border-radius:3px;font-weight:600">⏰ PRE</span><?php endif; ?>
    </td>

    <!-- Customer -->
    <td data-col="customer">
        <?php if($__preConf): ?>
            <div style="display:flex;align-items:center;gap:5px;margin-bottom:2px"><span style="color:#9ca3af;font-size:11px">📞</span><span class="cust-phone"><?= e($order['customer_phone']) ?></span>
            <?php if($sr['rate']>0||$sr['total']>0):?><span class="rate-badge" style="background:<?=$sr['rate']>=70?'#dcfce7':($sr['rate']>=40?'#fef9c3':'#fee2e2')?>;color:<?=$__rTxt?>;font-size:10px"><?=$sr['rate']?>%</span><?php endif;?></div>
            <div style="display:flex;align-items:center;gap:5px;margin-bottom:2px"><span style="color:#9ca3af;font-size:11px">👤</span><span class="cust-name"><?= e(mb_strimwidth($order['customer_name'],0,22,'...')) ?></span></div>
            <div style="display:flex;align-items:center;gap:5px"><span style="color:#9ca3af;font-size:11px">📍</span><span class="cust-addr"><?= e(mb_strimwidth($order['customer_address'],0,28,'...')) ?></span></div>
        <?php else: ?>
            <div class="cust-name" style="font-size:12px"><?= e(mb_strimwidth($order['customer_name'],0,20,'...')) ?></div>
            <div style="margin-top:1px"><span class="cust-phone" style="font-size:11px"><?= e($order['customer_phone']) ?></span>
            <?php if($sr['rate']>0||$sr['total']>0):?><span class="rate-badge" style="background:<?=$sr['rate']>=70?'#dcfce7':($sr['rate']>=40?'#fef9c3':'#fee2e2')?>;color:<?=$__rTxt?>;font-size:10px;margin-left:3px"><?=$sr['rate']?>%</span><?php endif;?></div>
            <div style="font-size:10px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px">📍 <?= e(mb_strimwidth($order['customer_address'],0,24,'...')) ?></div>
        <?php endif; ?>
    </td>

    <!-- Note -->
    <td data-col="note">
        <?php
        $hasShipNote=!empty($order['notes']);$hasOrderNote=!empty($order['order_note']);$hasPanelNote=!empty($order['panel_notes'])||!empty($order['admin_notes']);
        if($__preConf): ?>
            <div style="font-size:11px;color:#9ca3af;margin-bottom:2px">Updated <?= timeAgo($order['updated_at']?:$order['created_at']) ?></div>
        <?php endif;
        if($hasShipNote||$hasOrderNote||$hasPanelNote):
            $__noteLen=$__preConf?50:35;
            if($hasShipNote):?><div style="font-size:<?=$__preConf?12:10?>px;color:#ea580c;line-height:1.4;margin-bottom:2px"><?=e(mb_strimwidth($order['notes'],0,$__noteLen,'...'))?></div><?php endif;
            if($hasOrderNote):?><div style="font-size:<?=$__preConf?12:10?>px;color:#2563eb;line-height:1.4;margin-bottom:2px"><?=e(mb_strimwidth($order['order_note'],0,$__noteLen,'...'))?></div><?php endif;
            if($hasPanelNote):?><div style="font-size:<?=$__preConf?12:10?>px;color:#16a34a;line-height:1.4"><?=e(mb_strimwidth($order['panel_notes']??$order['admin_notes']??'',0,$__noteLen,'...'))?></div><?php endif;
        else:?><div style="color:#d1d5db">-</div><?php endif;?>
    </td>

    <!-- Products -->
    <td data-col="products" style="cursor:pointer" onclick='showProductPopup(<?=htmlspecialchars(json_encode(array_map(function($i){return["name"=>$i["product_name"],"variant"=>$i["variant_name"]??"","qty"=>intval($i["quantity"]),"price"=>floatval($i["price"]??0),"image"=>!empty($i["featured_image"])?imgSrc("products",$i["featured_image"]):"","sku"=>$i["sku"]??""];}, $oItems)),ENT_QUOTES)?>, "<?=e($order["order_number"])?>")'> 
        <div style="display:inline-flex;align-items:center;gap:3px;margin-bottom:3px">
            <span class="status-dot" style="background:<?=$sDot?>"></span>
            <span class="tag-badge" style="background:<?=$sDot?>22;color:<?=$sDot?>;font-size:9px;padding:1px 6px"><?=strtoupper(str_replace('_',' ',$oStatus))?></span>
        </div>
        <?php $__thumbSize=$__preConf?'32px':'28px'; foreach(array_slice($oItems,0,2) as $item):?>
        <div style="display:flex;align-items:center;gap:4px;margin-top:2px">
            <img src="<?=!empty($item['featured_image'])?imgSrc('products',$item['featured_image']):'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22><rect fill=%22%23f1f5f9%22 width=%2240%22 height=%2240%22/><text y=%22.75em%22 x=%22.15em%22 font-size=%2224%22>📦</text></svg>'?>" loading="lazy" style="width:<?=$__thumbSize?>;height:<?=$__thumbSize?>;border-radius:6px;object-fit:cover;border:1px solid #e5e7eb;flex-shrink:0">
            <div style="overflow:hidden"><div style="font-size:<?=$__preConf?12:11?>px;color:#374151;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:<?=$__preConf?130:110?>px"><?=e($item['product_name'])?></div><?php if(!empty($item['sku'])):?><div style="font-size:9px;color:#b0b8c4;font-family:monospace"><?=e($item['sku'])?></div><?php endif;?><div style="font-size:<?=$__preConf?10:9?>px;color:#9ca3af;white-space:nowrap"><?php if(!empty($item['variant_name'])):?><?=e(mb_strimwidth($item['variant_name'],0,16,'..'))?> · <?php endif;?>Qty: <?=intval($item['quantity'])?></div></div>
        </div>
        <?php endforeach;if(count($oItems)>2):?><div style="font-size:9px;color:#9ca3af;margin-top:2px">+<?=count($oItems)-2?> more</div><?php endif;?>
    </td>

    <!-- Tags -->
    <td data-col="tags">
        <?php if($sr['total']>1):?><span class="tag-badge" style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;margin-bottom:2px">Repeat</span><br><?php endif;
        foreach(array_slice($tags,0,2) as $tag):$tc2=$tagColors[$tag['tag_name']]??'bg-gray-100 text-gray-600';?><span class="tag-badge <?=$tc2?>" style="margin-bottom:2px"><?=e($tag['tag_name'])?></span><br><?php endforeach;
        if(count($tags)>2):?><span style="font-size:9px;color:#9ca3af">+<?=count($tags)-2?></span><?php endif;?>
    </td>

    <!-- Total -->
    <td data-col="total" style="text-align:right;white-space:nowrap">
        <div style="font-weight:700;color:#111827;font-size:<?=$__preConf?13:12?>px"><?=number_format($order['total'],2)?></div>
        <?php if($creditUsed>0):?><div style="font-size:9px;color:#ca8a04">-৳<?=number_format($creditUsed)?></div><?php endif;?>
    </td>

    <!-- ═══ Success Rate ═══ -->
    <td data-col="rate" style="white-space:nowrap">
        <span class="rate-wrap">
        <?php if($__preConf): ?>
            <!-- PRE-CONFIRMATION: circular donut chart -->
            <div style="display:flex;align-items:center;gap:6px;cursor:pointer">
                <svg width="36" height="36" viewBox="0 0 36 36" style="flex-shrink:0;transform:rotate(-90deg)">
                    <circle cx="18" cy="18" r="16" fill="none" stroke="#e5e7eb" stroke-width="3"/>
                    <circle cx="18" cy="18" r="16" fill="none" stroke="<?=$__rClr?>" stroke-width="3" stroke-dasharray="<?=$__dash?> <?=$__gap?>" stroke-linecap="round"/>
                </svg>
                <div style="line-height:1.3">
                    <div style="font-size:11px;font-weight:700;color:<?=$__rTxt?>">Success: <?=$sr['rate']?>%</div>
                    <div style="font-size:10px;color:#6b7280">Order: <?=$sr['delivered']?>/<?=$sr['total']?></div>
                    <div style="font-size:10px;color:#6b7280">Rating: <?=$sr['total']>0?max(0,intval($sr['rate']*0.74)):0?></div>
                </div>
            </div>
        <?php else: ?>
            <!-- POST-CONFIRMATION: compact badge -->
            <span class="rate-badge" style="background:<?=$sr['rate']>=70?'#dcfce7':($sr['rate']>=40?'#fef9c3':'#fee2e2')?>;color:<?=$__rTxt?>;cursor:pointer"><?=$sr['rate']?>%</span>
        <?php endif; ?>
        <?php if($sr['total']>0):?>
        <div class="rate-popup" onclick="event.stopPropagation()">
            <div style="font-weight:700;font-size:13px;color:#111827;margin-bottom:8px">COURIER RATING</div>
            <div style="display:flex;align-items:baseline;gap:6px;margin-bottom:6px">
                <span style="font-size:24px;font-weight:800;color:<?=$__rTxt?>"><?=number_format($sr['rate'],1)?>%</span>
                <span style="font-size:12px;color:#6b7280">success rate</span>
            </div>
            <div style="background:#e5e7eb;border-radius:6px;height:7px;overflow:hidden;margin-bottom:12px">
                <div style="background:<?=$__rClr?>;height:100%;width:<?=min(100,$sr['rate'])?>%;border-radius:6px"></div>
            </div>
            <div style="display:flex;gap:1px;margin-bottom:12px;background:#e5e7eb;border-radius:8px;overflow:hidden">
                <div style="flex:1;background:#fff;padding:8px 6px;text-align:center"><div style="font-size:8px;color:#9ca3af;text-transform:uppercase;font-weight:700;letter-spacing:.5px">Total</div><div style="font-size:18px;font-weight:800;color:#111827"><?=$sr['total']?></div></div>
                <div style="flex:1;background:#fff;padding:8px 6px;text-align:center"><div style="font-size:8px;color:#16a34a;text-transform:uppercase;font-weight:700;letter-spacing:.5px">Success</div><div style="font-size:18px;font-weight:800;color:#16a34a"><?=$sr['delivered']?></div></div>
                <div style="flex:1;background:#fff;padding:8px 6px;text-align:center"><div style="font-size:8px;color:#dc2626;text-transform:uppercase;font-weight:700;letter-spacing:.5px">Failed</div><div style="font-size:18px;font-weight:800;color:#dc2626"><?=$sr['cancelled']+($sr['returned']??0)?></div></div>
            </div>
            <?php if(!empty($__cBreak)):?><div style="margin-bottom:10px"><div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:5px">Breakdown</div><div style="font-size:11px;color:#374151"><?php $__bp=[];foreach($__cBreak as $cb){$__bp[]='<b>'.e($cb['name']).'</b>: '.$cb['delivered'].'/'.$cb['total'];}echo implode('&nbsp;&nbsp;&nbsp;',$__bp);?></div></div><?php endif;?>
            <div style="display:flex;gap:6px;padding-top:8px;border-top:1px solid #f3f4f6">
                <button onclick="event.stopPropagation();rateRefresh('<?=e($order['customer_phone'])?>',this)" style="flex:1;padding:6px 10px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:11px;color:#374151;cursor:pointer;font-weight:500;display:flex;align-items:center;justify-content:center;gap:4px" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">🔄 Refresh</button>
                <button onclick="event.stopPropagation();rateFetchAll('<?=e($order['customer_phone'])?>',this)" style="flex:1;padding:6px 10px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:11px;color:#374151;cursor:pointer;font-weight:500;display:flex;align-items:center;justify-content:center;gap:4px" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">🔍 Fetch All (<?=$sr['total']?>)</button>
            </div>
        </div>
        <?php endif;?>
        </span>
    </td>

    <!-- Upload -->
    <td data-col="upload">
        <?php if($__link&&$__cid):?>
            <a href="<?=$__link?>" target="_blank" style="font-size:10px;color:#2563eb;text-decoration:none;font-family:monospace;font-weight:500;white-space:nowrap"><?=e(mb_strimwidth($__tid,0,16,'..'))?></a>
            <?php if(!empty($order['courier_status'])):$__cs=$order['courier_status'];$__csc='#9ca3af';
                if(in_array($__cs,['delivered','delivered_approval_pending']))$__csc='#22c55e';
                elseif(in_array($__cs,['cancelled','cancelled_approval_pending']))$__csc='#ef4444';
                elseif($__cs==='hold')$__csc='#eab308';?>
                <div style="font-size:9px;color:<?=$__csc?>;margin-top:1px"><?=e(mb_strimwidth($__cs,0,14,'..'))?></div>
            <?php endif;?>
        <?php elseif($__cid):?><span style="font-size:10px;color:#6b7280;font-family:monospace"><?=e(mb_strimwidth($__cid,0,16,'..'))?></span>
        <?php else:?><span style="color:#d1d5db">—</span><?php endif;?>
    </td>

    <!-- Print -->
    <td data-col="print" style="text-align:center;vertical-align:middle">
        <?php if(intval($order['print_count']??0)>0):?><span style="color:#22c55e;font-size:14px">✓</span>
        <?php else:?><span style="color:#d1d5db">—</span><?php endif;?>
    </td>

    <!-- User -->
    <td data-col="user"><div style="font-size:10px;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:65px" title="<?=e($__userDisplay)?>"><?=e(mb_strimwidth($__userDisplay,0,10,'..'))?></div></td>

    <!-- Source -->
    <td data-col="source" style="white-space:nowrap"><span style="font-size:11px;color:#6b7280"><?=$srcLabel?></span></td>

    <!-- Shipping -->
    <td data-col="shipping">
        <?php if(!empty($order['courier_name'])):?><div style="font-size:10px;font-weight:500;color:#374151"><?=e($order['courier_name'])?></div><?php endif;?>
        <?php if(!empty($order['notes'])):?><div style="font-size:9px;color:#ea580c;line-height:1.3;margin-top:1px"><?=e(mb_strimwidth($order['notes'],0,28,'...'))?></div>
        <?php elseif(empty($order['courier_name'])):?><span style="color:#d1d5db">—</span><?php endif;?>
    </td>

    <!-- Actions -->
    <td style="text-align:center;vertical-align:middle">
        <?php $__isProc=in_array($order['order_status'],['processing','pending','incomplete']);?>
        <?php if($__preConf): ?>
            <a href="<?=adminUrl('pages/order-view.php?order='.urlencode($order['order_number']))?>" class="order-open-link" data-oid="<?=$order['id']?>" style="display:inline-flex;align-items:center;gap:3px;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:500;color:#16a34a;text-decoration:none" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='transparent'">Open <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>
        <?php else: ?>
            <a href="<?=adminUrl('pages/order-view.php?order='.urlencode($order['order_number']))?>" class="order-open-link om-btn om-btn-open" data-oid="<?=$order['id']?>" onclick="return openOrderModal(this.href,event)">Open</a>
        <?php endif; ?>
        <span class="lock-indicator hidden text-[10px] text-pink-600 font-medium" data-lock-oid="<?=$order['id']?>"></span>
    </td>
</tr>
