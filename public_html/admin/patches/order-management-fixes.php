<?php
/**
 * PATCH FILE - Order Management Fixes
 * =====================================
 * This file contains the code patches needed for order-management.php
 * 
 * FIXES:
 * 1. Bulk status change - proper JSON output with output buffering fix
 * 2. Courier upload popup - shows detailed progress with consignment IDs
 * 3. Better error handling
 * 
 * APPLY INSTRUCTIONS:
 * Replace the indicated sections in admin/pages/order-management.php
 */

// ============================================================================
// FIX #1: Replace bulk_status handler (lines 71-95) with this:
// ============================================================================
/*
    if ($action === 'bulk_status') {
        // Clear ALL output buffering first
        while (ob_get_level()) ob_end_clean();
        
        $ids = $_POST['order_ids'] ?? [];
        $status = sanitize($_POST['bulk_status'] ?? '');
        
        if (empty($ids) || empty($status)) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'No orders or status selected']);
                exit;
            }
            redirect(adminUrl('pages/order-management.php?msg=error'));
        }
        
        $updated = 0;
        $errors = [];
        
        foreach ($ids as $oid) {
            $oid = intval($oid);
            try {
                $oldOrder = $db->fetch("SELECT order_status FROM orders WHERE id=?", [$oid]);
                if (!$oldOrder) {
                    $errors[] = "Order #{$oid} not found";
                    continue;
                }
                $oldStatus = $oldOrder['order_status'] ?? '';
                
                // Skip if already in target status
                if ($oldStatus === $status) {
                    $updated++;
                    continue;
                }
                
                $db->update('orders', ['order_status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$oid]);
                try { $db->insert('order_status_history', ['order_id' => $oid, 'status' => $status, 'changed_by' => getAdminId()]); } catch (Exception $e) {}
                
                if ($status === 'delivered') {
                    try { awardOrderCredits($oid); } catch (\Throwable $e) {}
                    try { $db->update('orders', ['delivered_at' => date('Y-m-d H:i:s')], 'id = ? AND delivered_at IS NULL', [$oid]); } catch (\Throwable $e) {}
                }
                if (in_array($status, ['cancelled', 'returned'])) {
                    try { refundOrderCreditsOnCancel($oid); } catch (\Throwable $e) {}
                }
                
                // Stock: reduce on confirm, restore on cancel/return
                if ($status === 'confirmed' && in_array($oldStatus, ['processing', 'pending'])) {
                    try { _bulkStockAdjust($db, $oid, 'reduce'); } catch (\Throwable $e) {}
                }
                if (in_array($status, ['cancelled', 'returned']) && in_array($oldStatus, ['confirmed', 'ready_to_ship', 'shipped', 'delivered'])) {
                    try { _bulkStockAdjust($db, $oid, 'restore'); } catch (\Throwable $e) {}
                }
                
                try { $db->query("DELETE FROM order_locks WHERE order_id = ?", [$oid]); } catch (\Throwable $e) {}
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = "Order #{$oid}: " . $e->getMessage();
            }
        }
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'count' => $updated,
                'total' => count($ids),
                'errors' => $errors,
            ]);
            exit;
        }
        redirect(adminUrl('pages/order-management.php?msg=bulk_updated'));
    }
*/

// ============================================================================
// FIX #2: Replace bStatus function (around line 1041) with this improved version:
// ============================================================================
/*
function bStatus(s){
    const ids = getIds();
    if (!ids.length) { alert('Select orders first'); return; }
    if (!confirm('Change ' + ids.length + ' order(s) to "' + s + '"?')) return;
    
    document.getElementById('actionsMenu').classList.add('hidden');
    
    const p = document.getElementById('cProg');
    p.classList.remove('hidden');
    document.getElementById('cProgL').textContent = '⏳ Updating ' + ids.length + ' orders to ' + s + '...';
    document.getElementById('cProgB').style.width = '30%';
    document.getElementById('cErr').classList.add('hidden');
    document.getElementById('cErr').innerHTML = '';
    
    const fd = new FormData();
    fd.append('action', 'bulk_status');
    fd.append('bulk_status', s);
    fd.append('_ajax', '1');
    ids.forEach(i => fd.append('order_ids[]', i));
    
    fetch(location.href.split('?')[0], {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
    })
    .then(r => {
        const contentType = r.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            return r.json();
        }
        return r.text().then(txt => {
            // Try to extract JSON from response
            const jsonMatch = txt.match(/\{[\s\S]*\}/);
            if (jsonMatch) {
                return JSON.parse(jsonMatch[0]);
            }
            throw new Error('Invalid response: ' + txt.substring(0, 100));
        });
    })
    .then(d => {
        document.getElementById('cProgB').style.width = '100%';
        if (d.success) {
            document.getElementById('cProgL').textContent = '✓ Updated ' + (d.count || ids.length) + ' orders to ' + s;
            if (d.errors && d.errors.length) {
                const e = document.getElementById('cErr');
                e.classList.remove('hidden');
                e.innerHTML = d.errors.slice(0, 5).join('<br>');
            }
        } else {
            document.getElementById('cProgL').textContent = '⚠ Error: ' + (d.error || 'Unknown error');
        }
        setTimeout(() => {
            p.classList.add('hidden');
            OM.refresh();
        }, 1500);
    })
    .catch(e => {
        console.error('Bulk status error:', e);
        document.getElementById('cProgL').textContent = '❌ Error: ' + e.message;
        document.getElementById('cErr').classList.remove('hidden');
        document.getElementById('cErr').textContent = e.message;
        setTimeout(() => {
            p.classList.add('hidden');
            OM.refresh();
        }, 3000);
    });
}
*/

// ============================================================================
// FIX #3: Replace bCourier and doCourier functions with this popup version:
// ============================================================================
/*
function bCourier(c) {
    const ids = getIds();
    if (!ids.length) { alert('Select orders first'); return; }
    document.getElementById('actionsMenu').classList.add('hidden');
    openCourierUploadModal(ids, c);
}

function openCourierUploadModal(ids, courier) {
    // Create or show the modal
    let m = document.getElementById('courierUploadModal');
    if (!m) {
        m = document.createElement('div');
        m.id = 'courierUploadModal';
        m.innerHTML = `
            <div style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px">
                <div style="background:#fff;border-radius:16px;max-width:600px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,.25)">
                    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
                        <div>
                            <h3 id="cumTitle" style="font-size:14px;font-weight:700;color:#111827;margin:0">🚀 Uploading to Courier</h3>
                            <p id="cumSubtitle" style="font-size:11px;color:#6b7280;margin:4px 0 0"></p>
                        </div>
                        <button onclick="closeCourierModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af;line-height:1">&times;</button>
                    </div>
                    <div id="cumProgress" style="padding:16px 20px;border-bottom:1px solid #e5e7eb;flex-shrink:0">
                        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
                            <span id="cumStatus" style="font-size:12px;font-weight:500;color:#374151">Preparing...</span>
                            <span id="cumCounter" style="font-size:11px;color:#6b7280">0 / 0</span>
                        </div>
                        <div style="background:#e5e7eb;border-radius:8px;height:8px;overflow:hidden">
                            <div id="cumBar" style="background:linear-gradient(90deg,#3b82f6,#2563eb);height:100%;width:0%;transition:width .3s"></div>
                        </div>
                    </div>
                    <div id="cumResults" style="flex:1;overflow-y:auto;padding:12px 20px;min-height:200px;max-height:400px">
                        <div style="text-align:center;padding:40px 20px;color:#9ca3af">
                            <div style="font-size:32px;margin-bottom:8px">📦</div>
                            <div style="font-size:12px">Waiting to start...</div>
                        </div>
                    </div>
                    <div id="cumFooter" style="padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
                        <div id="cumSummary" style="font-size:11px;color:#6b7280"></div>
                        <div style="display:flex;gap:8px">
                            <button id="cumCloseBtn" onclick="closeCourierModal()" style="padding:8px 16px;border-radius:8px;border:1px solid #d1d5db;background:#fff;font-size:12px;cursor:pointer">Close</button>
                            <button id="cumRefreshBtn" onclick="closeCourierModal();OM.refresh()" style="padding:8px 16px;border-radius:8px;border:none;background:#3b82f6;color:#fff;font-size:12px;font-weight:600;cursor:pointer;display:none">Refresh List</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(m);
    }
    
    m.style.display = 'block';
    document.getElementById('cumTitle').textContent = '🚀 Uploading to ' + courier;
    document.getElementById('cumSubtitle').textContent = ids.length + ' order(s) selected';
    document.getElementById('cumStatus').textContent = 'Starting upload...';
    document.getElementById('cumCounter').textContent = '0 / ' + ids.length;
    document.getElementById('cumBar').style.width = '5%';
    document.getElementById('cumResults').innerHTML = '<div style="text-align:center;padding:40px 20px;color:#9ca3af"><div style="font-size:32px;margin-bottom:8px">⏳</div><div style="font-size:12px">Uploading orders...</div></div>';
    document.getElementById('cumSummary').textContent = '';
    document.getElementById('cumRefreshBtn').style.display = 'none';
    
    // Call the new bulk upload API
    fetch('<?= SITE_URL ?>/api/courier-bulk-upload.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({
            courier: courier.toLowerCase(),
            order_ids: ids
        })
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('cumBar').style.width = '100%';
        document.getElementById('cumStatus').textContent = data.success ? '✓ Upload Complete' : '⚠ Upload Finished with Errors';
        document.getElementById('cumCounter').textContent = data.uploaded + ' / ' + data.total;
        document.getElementById('cumRefreshBtn').style.display = 'inline-block';
        
        // Build results HTML
        let html = '<div style="display:flex;flex-direction:column;gap:8px">';
        
        if (data.orders && data.orders.length) {
            data.orders.forEach(o => {
                const bg = o.success ? '#f0fdf4' : '#fef2f2';
                const border = o.success ? '#86efac' : '#fecaca';
                const icon = o.success ? '✓' : '✗';
                const iconColor = o.success ? '#16a34a' : '#dc2626';
                
                html += `<div style="background:${bg};border:1px solid ${border};border-radius:8px;padding:10px 12px;display:flex;align-items:center;gap:10px">
                    <span style="color:${iconColor};font-weight:700;font-size:14px;flex-shrink:0">${icon}</span>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:12px;font-weight:600;color:#111827">#${o.order_number || o.order_id}</div>
                        <div style="font-size:10px;color:#6b7280;margin-top:2px">${o.message || ''}</div>
                    </div>`;
                
                if (o.success && o.consignment_id) {
                    html += `<div style="text-align:right;flex-shrink:0">
                        <div style="font-size:10px;color:#6b7280">Consignment ID</div>
                        <div style="font-size:11px;font-weight:600;color:#2563eb;font-family:monospace">${o.consignment_id}</div>
                    </div>`;
                }
                
                html += '</div>';
            });
        }
        
        html += '</div>';
        document.getElementById('cumResults').innerHTML = html;
        
        // Summary
        const successColor = data.uploaded > 0 ? '#16a34a' : '#6b7280';
        const failColor = data.failed > 0 ? '#dc2626' : '#6b7280';
        document.getElementById('cumSummary').innerHTML = `<span style="color:${successColor}">✓ ${data.uploaded} uploaded</span> &nbsp;·&nbsp; <span style="color:${failColor}">✗ ${data.failed} failed</span>`;
    })
    .catch(e => {
        document.getElementById('cumBar').style.width = '100%';
        document.getElementById('cumBar').style.background = '#ef4444';
        document.getElementById('cumStatus').textContent = '❌ Error';
        document.getElementById('cumResults').innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px;text-align:center"><div style="color:#dc2626;font-weight:600;margin-bottom:4px">Upload Failed</div><div style="font-size:11px;color:#991b1b">' + e.message + '</div></div>';
        document.getElementById('cumRefreshBtn').style.display = 'inline-block';
    });
}

function closeCourierModal() {
    const m = document.getElementById('courierUploadModal');
    if (m) m.style.display = 'none';
}

// Legacy function - redirect to new popup
function doCourier(ids, c) {
    openCourierUploadModal(ids, c);
}
*/

echo "This is a patch reference file. Do not include directly.\n";
echo "Apply the patches manually to admin/pages/order-management.php\n";
