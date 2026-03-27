<?php // order-row-tpl.php - included from order-management.php for each $order ?>
<tr data-order-id="<?= $order['id'] ?>">
    <td><input type="checkbox" name="order_ids[]" value="<?= $order['id'] ?>" class="order-check" onchange="updateBulk()"></td>
    <td>
        <div style="font-size:11px;font-weight:500;color:#334155"><?= date('d/m/Y,', strtotime($order['created_at'])) ?></div>
        <div style="font-size:10px;color:#64748b"><?= date('h:i a', strtotime($order['created_at'])) ?></div>
        <div style="font-size:9px;color:#94a3b8">Updated <?= timeAgo($order['updated_at']?:$order['created_at']) ?></div>
    </td>
    <td>
        <div style="display:flex;align-items:center;gap:4px">
            <a href="<?= adminUrl('pages/order-view.php?order='.urlencode($order['order_number'])) ?>" style="font-weight:700;color:#0f172a;font-size:12px;text-decoration:none" onmouseover="this.style.color='#2563eb'" onmouseout="this.style.color='#0f172a'"><?= e($order['order_number']) ?></a>
            <span class="dot-menu" onclick="toggleRowMenu(this,<?= $order['id'] ?>,'<?= e($order['order_number']) ?>')">⋮</span>
        </div>
        <?php if (!empty($order['is_preorder'])): ?>
        <span style="font-size:8px;background:#f3e8ff;color:#7c3aed;padding:1px 5px;border-radius:3px;font-weight:600;display:inline-block;margin-top:1px">⏰ PRE<?php if(!empty($order['preorder_date'])): ?> · <?= date('d M', strtotime($order['preorder_date'])) ?><?php endif; ?></span>
        <?php endif; ?>
    </td>
    <td style="white-space:normal">
        <div style="display:flex;align-items:center;gap:4px">
            <span class="cust-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:130px;display:inline-block"><?= e($order['customer_name']) ?></span>
        </div>
        <div style="margin-top:1px;display:flex;align-items:center;gap:3px">
            <span class="cust-phone"><?= e($order['customer_phone']) ?></span>
            <?php
                $__rBg = $sr['rate']>=70?'#dcfce7':($sr['rate']>=40?'#fef9c3':'#fee2e2');
                $__rClr = $sr['rate']>=70?'#166534':($sr['rate']>=40?'#854d0e':'#991b1b');
                $__rBar = $sr['rate']>=70?'#22c55e':($sr['rate']>=40?'#eab308':'#ef4444');
                $__cBreak = $sr['courier_breakdown'] ?? [];
            ?>
            <span class="rate-wrap">
                <span class="rate-badge" style="background:<?= $__rBg ?>;color:<?= $__rClr ?>"><?= $sr['rate'] ?>%</span>
                <?php if ($sr['total'] > 0): ?>
                <div class="rate-popup" onclick="event.stopPropagation()">
                    <div style="font-weight:700;font-size:13px;color:#111827;margin-bottom:8px">COURIER RATING</div>
                    <div style="display:flex;align-items:baseline;gap:6px;margin-bottom:6px">
                        <span style="font-size:24px;font-weight:800;color:<?= $__rClr ?>"><?= number_format($sr['rate'],1) ?>%</span>
                        <span style="font-size:12px;color:#6b7280">success rate</span>
                    </div>
                    <div style="background:#e5e7eb;border-radius:6px;height:7px;overflow:hidden;margin-bottom:12px">
                        <div style="background:<?= $__rBar ?>;height:100%;width:<?= min(100,$sr['rate']) ?>%;border-radius:6px"></div>
                    </div>
                    <div style="display:flex;gap:1px;margin-bottom:12px;background:#e5e7eb;border-radius:8px;overflow:hidden">
                        <div style="flex:1;background:#fff;padding:8px 6px;text-align:center">
                            <div style="font-size:8px;color:#9ca3af;text-transform:uppercase;font-weight:700;letter-spacing:.5px">Total</div>
                            <div style="font-size:18px;font-weight:800;color:#111827"><?= $sr['total'] ?></div>
                        </div>
                        <div style="flex:1;background:#fff;padding:8px 6px;text-align:center">
                            <div style="font-size:8px;color:#16a34a;text-transform:uppercase;font-weight:700;letter-spacing:.5px">Success</div>
                            <div style="font-size:18px;font-weight:800;color:#16a34a"><?= $sr['delivered'] ?></div>
                        </div>
                        <div style="flex:1;background:#fff;padding:8px 6px;text-align:center">
                            <div style="font-size:8px;color:#dc2626;text-transform:uppercase;font-weight:700;letter-spacing:.5px">Failed</div>
                            <div style="font-size:18px;font-weight:800;color:#dc2626"><?= $sr['cancelled'] + ($sr['returned'] ?? 0) ?></div>
                        </div>
                    </div>
                    <?php if (!empty($__cBreak)): ?>
                    <div style="margin-bottom:10px">
                        <div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:5px">Breakdown</div>
                        <div style="font-size:11px;color:#374151"><?php
                            $__bParts = [];
                            foreach ($__cBreak as $cb) { $__bParts[] = '<b>' . e($cb['name']) . '</b>: ' . $cb['delivered'] . '/' . $cb['total']; }
                            echo implode('&nbsp;&nbsp;&nbsp;', $__bParts);
                        ?></div>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex;gap:6px;padding-top:8px;border-top:1px solid #f3f4f6">
                        <button onclick="event.stopPropagation();rateRefresh('<?= e($order['customer_phone']) ?>',this)" style="flex:1;padding:6px 10px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:11px;color:#374151;cursor:pointer;font-weight:500;display:flex;align-items:center;justify-content:center;gap:4px" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">🔄 Refresh</button>
                        <button onclick="event.stopPropagation();rateFetchAll('<?= e($order['customer_phone']) ?>',this)" style="flex:1;padding:6px 10px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:11px;color:#374151;cursor:pointer;font-weight:500;display:flex;align-items:center;justify-content:center;gap:4px" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">🔍 Fetch All (<?= $sr['total'] ?>)</button>
                    </div>
                </div>
                <?php endif; ?>
            </span>
        </div>
        <div class="cust-addr" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">📍 <?= e(mb_strimwidth($order['customer_address'],0,30,'...')) ?></div>
    </td>
    <td style="white-space:normal">
        <?php 
        $hasShipNote = !empty($order['notes']);
        $hasOrderNote = !empty($order['order_note']);
        $hasPanelNote = !empty($order['panel_notes']) || !empty($order['admin_notes']);
        if ($hasShipNote || $hasOrderNote || $hasPanelNote): ?>
            <?php if($hasShipNote):?><div style="font-size:10px;color:#ea580c;line-height:1.3;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis" title="<?= e($order['notes']) ?>"><span style="display:inline-block;width:5px;height:5px;border-radius:50%;background:#f97316;margin-right:3px;vertical-align:middle"></span><?= e(mb_strimwidth($order['notes'],0,35,'...')) ?></div><?php endif;?>
            <?php if($hasOrderNote):?><div style="font-size:10px;color:#2563eb;line-height:1.3;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis" title="<?= e($order['order_note']) ?>"><span style="display:inline-block;width:5px;height:5px;border-radius:50%;background:#3b82f6;margin-right:3px;vertical-align:middle"></span><?= e(mb_strimwidth($order['order_note'],0,35,'...')) ?></div><?php endif;?>
            <?php if($hasPanelNote):?><div style="font-size:10px;color:#16a34a;line-height:1.3;overflow:hidden;text-overflow:ellipsis" title="<?= e($order['panel_notes'] ?? $order['admin_notes'] ?? '') ?>"><span style="display:inline-block;width:5px;height:5px;border-radius:50%;background:#22c55e;margin-right:3px;vertical-align:middle"></span><?= e(mb_strimwidth($order['panel_notes'] ?? $order['admin_notes'] ?? '',0,35,'...')) ?></div><?php endif;?>
        <?php else: ?>
            <span style="color:#d1d5db">—</span>
        <?php endif; ?>
    </td>
    <td style="cursor:pointer;white-space:normal" onclick='showProductPopup(<?= htmlspecialchars(json_encode(array_map(function($i){return["name"=>$i["product_name"],"variant"=>$i["variant_name"]??"","qty"=>intval($i["quantity"]),"price"=>floatval($i["price"]??0),"image"=>!empty($i["featured_image"])?imgSrc("products",$i["featured_image"]):"","sku"=>$i["sku"]??""];}, $oItems)),ENT_QUOTES) ?>, "<?= e($order["order_number"]) ?>")'> 
        <div style="display:inline-flex;align-items:center;gap:3px;margin-bottom:3px">
            <span class="status-dot" style="background:<?= $sDot ?>"></span>
            <span class="tag-badge" style="background:<?= $sDot ?>22;color:<?= $sDot ?>;font-size:9px;padding:1px 6px"><?= strtoupper(str_replace('_',' ',$oStatus)) ?></span>
        </div>
        <?php foreach(array_slice($oItems,0,2) as $item): ?>
        <div style="display:flex;align-items:center;gap:4px;margin-top:2px">
            <img src="<?= !empty($item['featured_image'])?imgSrc('products',$item['featured_image']):'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22><rect fill=%22%23f1f5f9%22 width=%2240%22 height=%2240%22/><text y=%22.75em%22 x=%22.15em%22 font-size=%2224%22>📦</text></svg>' ?>" class="prod-thumb" loading="lazy">
            <div style="overflow:hidden">
                <div style="font-size:11px;color:#334155;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100px"><?= e($item['product_name']) ?></div>
                <div style="font-size:9px;color:#94a3b8;white-space:nowrap"><?php if(!empty($item['variant_name'])): ?><?= e(mb_strimwidth($item['variant_name'],0,18,'..')) ?> · <?php endif; ?>Qty: <?= intval($item['quantity']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(count($oItems)>2): ?><div style="font-size:9px;color:#94a3b8;margin-top:2px">+<?= count($oItems)-2 ?> more</div><?php endif; ?>
    </td>
    <td style="white-space:normal">
        <?php
        $tagColors = ['REPEAT'=>'bg-orange-100 text-orange-700','URGENT'=>'bg-red-100 text-red-700','VIP'=>'bg-purple-100 text-purple-700','GIFT'=>'bg-pink-100 text-pink-700','COD VERIFIED'=>'bg-green-100 text-green-700','ADVANCE PAID'=>'bg-emerald-100 text-emerald-700','FOLLOW UP'=>'bg-blue-100 text-blue-700'];
        if ($sr['total'] > 1): ?>
            <span class="tag-badge" style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;margin-bottom:2px">Repeat</span><br>
        <?php endif;
        foreach(array_slice($tags,0,2) as $tag):
            $tc2 = $tagColors[$tag['tag_name']] ?? 'bg-gray-100 text-gray-600';
        ?>
            <span class="tag-badge <?= $tc2 ?>" style="margin-bottom:2px"><?= e($tag['tag_name']) ?></span><br>
        <?php endforeach;
        if(count($tags)>2): ?><span style="font-size:9px;color:#94a3b8">+<?= count($tags)-2 ?></span><?php endif; ?>
    </td>
    <td style="text-align:right">
        <div style="font-weight:700;color:#0f172a;font-size:12px"><?= number_format($order['total'],2) ?></div>
        <?php if ($creditUsed > 0): ?><div style="font-size:9px;color:#ca8a04">-৳<?= number_format($creditUsed) ?></div><?php endif; ?>
    </td>
    <td style="white-space:normal">
        <?php if($__link && $__cid): ?>
            <a href="<?= $__link ?>" target="_blank" style="font-size:10px;color:#2563eb;text-decoration:none;font-family:monospace;font-weight:500;word-break:break-all" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'"><?= e($__tid) ?></a>
            <?php if(!empty($order['courier_status'])):
                $__cs = $order['courier_status']; $__csc = '#94a3b8';
                if (in_array($__cs, ['delivered','delivered_approval_pending'])) $__csc = '#22c55e';
                elseif (in_array($__cs, ['cancelled','cancelled_approval_pending'])) $__csc = '#ef4444';
                elseif ($__cs === 'hold') $__csc = '#eab308';
            ?>
                <div style="font-size:9px;color:<?= $__csc ?>;margin-top:1px"><?= e($__cs) ?></div>
            <?php endif; ?>
        <?php elseif($__cid): ?>
            <span style="font-size:10px;color:#64748b;font-family:monospace;word-break:break-all"><?= e($__cid) ?></span>
        <?php else: ?>
            <span style="color:#d1d5db">—</span>
        <?php endif; ?>
    </td>
    <td style="text-align:center">
        <?php $__printed = intval($order['print_count'] ?? 0); ?>
        <?php if ($__printed > 0): ?>
            <span style="color:#22c55e;font-size:14px" title="Printed <?= $__printed ?> time(s)">✓</span>
        <?php else: ?>
            <span style="color:#d1d5db;font-size:12px" title="Not printed">—</span>
        <?php endif; ?>
    </td>
    <td>
        <span style="font-size:10px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block"><?= e($order['assigned_name'] ?? '—') ?></span>
    </td>
    <td>
        <span style="font-size:9px;font-weight:600;color:#64748b"><?= $srcLabel ?></span>
    </td>
    <td style="white-space:normal">
        <?php if (!empty($order['courier_name'])): ?>
            <div style="font-size:10px;font-weight:600;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($order['courier_name']) ?></div>
        <?php endif; ?>
        <?php if (!empty($order['notes'])): ?>
            <div style="font-size:9px;color:#ea580c;line-height:1.3;margin-top:1px;overflow:hidden;text-overflow:ellipsis" title="<?= e($order['notes']) ?>"><?= e(mb_strimwidth($order['notes'],0,40,'...')) ?></div>
        <?php elseif (empty($order['courier_name'])): ?>
            <span style="color:#d1d5db">—</span>
        <?php endif; ?>
    </td>
    <td class="om-action-cell">
        <?php $__isProc = in_array($order['order_status'], ['processing','pending']); ?>
        <a href="<?= adminUrl('pages/order-view.php?order='.urlencode($order['order_number'])) ?>" 
           class="order-open-link om-btn om-btn-open"
           data-oid="<?= $order['id'] ?>"
           <?= !$__isProc ? 'onclick="return openOrderModal(this.href,event)"' : '' ?>>Open</a>
        <span class="lock-indicator hidden text-[10px] text-pink-600 font-medium ml-1" data-lock-oid="<?= $order['id'] ?>"></span>
    </td>
</tr>
