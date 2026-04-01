<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Order Details';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();
$id = intval($_GET['id'] ?? 0);
$orderNum = trim($_GET['order'] ?? '');
$isModal       = !empty($_GET['modal']);         // loaded inside edit modal iframe
$isProcSession = !empty($_GET['proc_session']);   // part of a processing session
$isLockCheck   = !empty($_GET['lock_check']);     // just checking lock status, no page load

// Track which status tab to return to after save/update
$_returnStatus = $_GET['return_status'] ?? '';
if (!$_returnStatus && !empty($_SERVER['HTTP_REFERER'])) {
    preg_match('/status=([a-z_]+)/', $_SERVER['HTTP_REFERER'], $_rm);
    $_returnStatus = $_rm[1] ?? '';
}
$_returnQs = $_returnStatus ? "status={$_returnStatus}&" : '';

// Resolve order_number to id if ?order= param used
if (!$id && $orderNum) {
    $resolved = $db->fetch("SELECT id FROM orders WHERE order_number = ?", [$orderNum]);
    if ($resolved) $id = intval($resolved['id']);
}

// Lock check shortcut: JS pre-checks lock before navigating
if ($isLockCheck && $id && $isProcSession) {
    header('Content-Type: application/json');
    $meId = getAdminId();
    try { $db->query("DELETE FROM order_locks WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 90 SECOND)"); } catch (\Throwable $e) {}
    try {
        $lk = $db->fetch("SELECT admin_user_id, admin_name FROM order_locks WHERE order_id = ? AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 90 SECOND)", [$id]);
        if ($lk && intval($lk['admin_user_id']) !== $meId) {
            echo json_encode(['locked'=>true,'locked_by'=>$lk['admin_name'],'order_id'=>$id]);
        } else {
            echo json_encode(['locked'=>false,'order_id'=>$id]);
        }
    } catch (\Throwable $e) {
        echo json_encode(['locked'=>false,'order_id'=>$id]);
    }
    exit;
}
if (!$id) {
    // If looking for a TEMP order that doesn't exist, redirect to incomplete tab
    if (strpos($orderNum, 'TEMP-') === 0) {
        redirect(adminUrl('pages/order-management.php?status=incomplete&msg=error&detail=' . urlencode('TEMP order not found. Please re-open the incomplete order.')));
    }
    redirect(adminUrl('pages/order-management.php'));
}
$order = $db->fetch("SELECT * FROM orders WHERE id = ?", [$id]);
if (!$order) {
    if (strpos($orderNum, 'TEMP-') === 0) {
        redirect(adminUrl('pages/order-management.php?status=incomplete&msg=error&detail=' . urlencode('TEMP order was removed. Please re-open the incomplete order.')));
    }
    redirect(adminUrl('pages/order-management.php'));
}

/* ─── POST Actions ─── */
$__currentAdminId = getAdminId(); // Needed early for lock conflict check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_order' || $action === 'confirm_order') {
        // ── Post-confirmation immutability: block edits to confirmed+ orders ──
        $immutableStatuses = ['confirmed', 'ready_to_ship', 'shipped', 'delivered', 'returned', 'partial_delivered', 'pending_return', 'pending_cancel'];
        if ($action === 'save_order' && in_array($order['order_status'], $immutableStatuses)) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false)) {
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Cannot edit order after confirmation. Status: ' . $order['order_status']]);
                exit;
            }
            if (!empty($_POST['_iframe'])) {
                while (ob_get_level()) ob_end_clean();
                echo '<html><body><script>window.parent.postMessage({type:"orderError",errors:["Cannot edit order after confirmation"]},"*");</script></body></html>';
                exit;
            }
            redirect(adminUrl("pages/order-view.php?id={$id}&msg=validation_error&detail=" . urlencode('Cannot edit order after confirmation. Status: ' . $order['order_status'])));
        }
        // Conflict check: ensure we still hold the lock before saving
        try {
            $__currentLock = $db->fetch("SELECT admin_user_id FROM order_locks WHERE order_id = ? AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 90 SECOND)", [$id]);
            if ($__currentLock && intval($__currentLock['admin_user_id']) !== $__currentAdminId) {
                $__takenBy = $db->fetch("SELECT full_name FROM admin_users WHERE id=?", [intval($__currentLock['admin_user_id'])]);
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success'=>false,'conflict'=>true,'taken_by'=>($__takenBy['full_name']??'Another user')]);
                exit;
            }
        } catch (\Throwable $e) {}
        $name         = sanitize($_POST['customer_name']    ?? $order['customer_name']);
        $phone        = sanitize($_POST['customer_phone']   ?? $order['customer_phone']);
        $address      = sanitize($_POST['customer_address'] ?? $order['customer_address']);
        $shippingNote = sanitize($_POST['shipping_note']    ?? '');
        $orderNote    = sanitize($_POST['order_note']       ?? '');
        $deliveryMethod = sanitize($_POST['delivery_method'] ?? 'Pathao');
        $channel      = sanitize($_POST['channel']  ?? $order['channel']  ?? 'website');
        $isPreorder   = !empty($_POST['is_preorder']) ? 1 : 0;
        $preorderDate = !empty($_POST['preorder_date']) ? $_POST['preorder_date'] : null;

        $productIds   = $_POST['item_product_id']   ?? [];
        $productNames = $_POST['item_product_name']  ?? [];
        $variantNames = $_POST['item_variant_name']  ?? [];
        $qtys         = $_POST['item_qty']           ?? [];
        $prices       = $_POST['item_price']         ?? [];

        if (!empty($productIds)) {
            $db->delete('order_items', 'order_id = ?', [$id]);
            $subtotal = 0;
            foreach ($productIds as $i => $pid) {
                $qty   = max(1, intval($qtys[$i] ?? 1));
                $price = floatval($prices[$i] ?? 0);
                $line  = $price * $qty;
                $subtotal += $line;
                $db->insert('order_items', [
                    'order_id' => $id, 'product_id' => intval($pid) ?: null,
                    'product_name' => sanitize($productNames[$i] ?? 'Product'),
                    'variant_name' => sanitize($variantNames[$i] ?? '') ?: null,
                    'quantity' => $qty, 'price' => $price, 'subtotal' => $line,
                ]);
            }
        } else {
            $subtotal = floatval($order['subtotal']);
        }

        $discount     = floatval($_POST['discount_amount'] ?? $order['discount_amount'] ?? 0);
        $advance      = floatval($_POST['advance_amount']  ?? 0);
        $shippingCost = floatval($_POST['shipping_cost']   ?? $order['shipping_cost'] ?? 0);
        $total        = max(0, $subtotal + $shippingCost - $discount - $advance);

        $updateData = [
            'customer_name' => $name, 'customer_phone' => $phone, 'customer_address' => $address,
            'shipping_method' => $deliveryMethod, 'courier_name' => $deliveryMethod,
            'subtotal' => $subtotal, 'shipping_cost' => $shippingCost,
            'discount_amount' => $discount, 'advance_amount' => $advance, 'total' => $total,
            'notes' => $shippingNote ?: ($order['notes'] ?? ''),
            'order_note' => $orderNote,
            'channel' => $channel, 'is_preorder' => $isPreorder,
            'preorder_date' => $preorderDate,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($action === 'confirm_order' && in_array($order['order_status'], ['pending','processing','incomplete'])) {
            // ── Strict validation: reject confirmation if required fields are missing ──
            $confirmErrors = [];
            if (empty(trim($name))) $confirmErrors[] = 'Customer name is required';
            if (empty(trim($phone))) $confirmErrors[] = 'Phone number is required';
            if (empty(trim($address))) $confirmErrors[] = 'Delivery address is required';
            if (empty($productIds) || count(array_filter($productIds)) === 0) $confirmErrors[] = 'At least one product is required';
            if ($total <= 0 && $advance <= 0) $confirmErrors[] = 'Order total must be greater than zero';
            // Validate phone format (BD phone)
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($cleanPhone) < 10 || strlen($cleanPhone) > 15) $confirmErrors[] = 'Invalid phone number';
            
            if (!empty($confirmErrors)) {
                // Return validation errors
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false)) {
                    while (ob_get_level()) ob_end_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'validation_errors' => $confirmErrors, 'message' => implode(', ', $confirmErrors)]);
                    exit;
                }
                // For iframe submit (order-view form)
                if (!empty($_POST['_iframe'])) {
                    while (ob_get_level()) ob_end_clean();
                    echo '<html><body><script>window.parent.postMessage({type:"orderError",errors:' . json_encode($confirmErrors) . '},"*");</script></body></html>';
                    exit;
                }
                // Fallback: redirect with error
                redirect(adminUrl("pages/order-view.php?id={$id}&msg=validation_error&detail=" . urlencode(implode(', ', $confirmErrors))));
            }
            
            $updateData['order_status'] = 'confirmed';
            
            // If this is a TEMP order (from incomplete), generate real order number on confirmation
            if (strpos($order['order_number'], 'TEMP-') === 0) {
                $realOrderNumber = generateOrderNumber();
                $updateData['order_number'] = $realOrderNumber;
                $db->insert('order_status_history', ['order_id'=>$id,'status'=>'confirmed','changed_by'=>getAdminId(),'note'=>'Confirmed: TEMP → ' . $realOrderNumber]);
                logActivity(getAdminId(), 'confirm_order', 'orders', $id, "Confirmed: {$order['order_number']} → {$realOrderNumber}");
                
                // Delete from incomplete_orders table
                $tempIncId = intval(str_replace('TEMP-', '', $order['order_number']));
                if ($tempIncId > 0) {
                    try { $db->query("UPDATE incomplete_orders SET is_recovered = 1, recovered_order_id = ? WHERE id = ?", [$id, $tempIncId]); } catch (\Throwable $e) {}
                    try { $db->query("UPDATE incomplete_orders SET recovered = 1, recovered_order_id = ? WHERE id = ?", [$id, $tempIncId]); } catch (\Throwable $e) {}
                }
                
                // Tag as incomplete order source
                try { $db->insert('order_tags', ['order_id'=>$id,'tag_name'=>'INCOMPLETE_ORDER']); } catch (\Throwable $e) {}
            } else {
                $db->insert('order_status_history', ['order_id'=>$id,'status'=>'confirmed','changed_by'=>getAdminId(),'note'=>'Order confirmed']);
                logActivity(getAdminId(), 'confirm_order', 'orders', $id, 'Confirmed order');
            }

            // ── Reduce stock on confirmation (not on order placement) ──
            try {
                $confirmItems = $db->fetchAll("SELECT oi.product_id, oi.quantity, oi.variant_name FROM order_items oi WHERE oi.order_id = ?", [$id]);
                foreach ($confirmItems as $ci) {
                    if (!$ci['product_id']) continue;
                    $prod = $db->fetch("SELECT manage_stock, combined_stock, stock_quantity FROM products WHERE id = ?", [$ci['product_id']]);
                    if (!$prod || !intval($prod['manage_stock'] ?? 1)) continue;
                    $qty = intval($ci['quantity']);

                    if (intval($prod['combined_stock'] ?? 0) && !empty($ci['variant_name'])) {
                        // Combined stock mode: deduct weight_per_unit × qty from parent stock_quantity (in kg)
                        $variant = $db->fetch("SELECT id, weight_per_unit FROM product_variants WHERE product_id = ? AND CONCAT(variant_name, ': ', variant_value) = ? LIMIT 1", [$ci['product_id'], $ci['variant_name']]);
                        if ($variant) {
                            $weight = floatval($variant['weight_per_unit'] ?? 0);
                            if ($weight > 0) {
                                $db->query("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?", [$weight * $qty, $ci['product_id']]);
                            }
                        }
                    } else {
                        // Normal mode: deduct quantity from parent product
                        $db->query("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?", [$qty, $ci['product_id']]);
                        // Also deduct from variant if matched
                        if (!empty($ci['variant_name'])) {
                            try {
                                $variant = $db->fetch("SELECT id FROM product_variants WHERE product_id = ? AND CONCAT(variant_name, ': ', variant_value) = ? LIMIT 1", [$ci['product_id'], $ci['variant_name']]);
                                if ($variant) {
                                    $db->query("UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ?", [$qty, $variant['id']]);
                                }
                            } catch (\Throwable $e) {}
                        }
                    }
                    $db->query("UPDATE products SET stock_status = CASE WHEN stock_quantity > 0 THEN 'in_stock' ELSE 'out_of_stock' END WHERE id = ? AND manage_stock = 1", [$ci['product_id']]);
                }
            } catch (\Throwable $e) { error_log("Stock reduce on confirm failed for order {$id}: " . $e->getMessage()); }
        }

        $db->update('orders', $updateData, 'id = ?', [$id]);
        logActivity(getAdminId(), 'update', 'orders', $id);
        // Release order lock on save/confirm
        try { $db->query("DELETE FROM order_locks WHERE order_id = ?", [$id]); } catch (\Throwable $e) {}
        if ($action === 'confirm_order') {
            if ($isModal) {
                while (ob_get_level()) ob_end_clean();
                echo '<html><body><script>window.parent.postMessage({type:"orderSaved",action:"confirmed",order_id:' . $id . '},"*");</script></body></html>';
                exit;
            }
            if ($isProcSession) {
                // Return JSON for session mode — JS handles navigation
                while (ob_get_level()) ob_end_clean(); // Clear any buffered output
                header('Content-Type: application/json');
                echo json_encode(['success'=>true,'action'=>'confirmed','order_id'=>$id]);
                exit;
            }
            redirect(adminUrl("pages/order-management.php?{$_returnQs}msg=confirmed"));
        }
        if ($isModal) {
            while (ob_get_level()) ob_end_clean();
            echo '<html><body><script>window.parent.postMessage({type:"orderSaved",action:"saved",order_id:' . $id . '},"*");</script></body></html>';
            exit;
        }
        if ($isProcSession) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success'=>true,'action'=>'saved','order_id'=>$id]);
            exit;
        }
        redirect(adminUrl("pages/order-management.php?{$_returnQs}msg=updated&highlight={$id}"));
    }

    if ($action === 'update_status') {
        $newStatus = sanitize($_POST['status']);
        $notes     = sanitize($_POST['notes'] ?? '');
        $oldStatus = $order['order_status'];
        
        // ── Guard: prevent reverting confirmed+ orders to processing/pending ──
        $postConfirmStatuses = ['confirmed', 'ready_to_ship', 'shipped', 'delivered', 'partial_delivered', 'pending_return', 'pending_cancel'];
        $preConfirmStatuses = ['pending', 'processing', 'incomplete'];
        if (in_array($oldStatus, $postConfirmStatuses) && in_array($newStatus, $preConfirmStatuses)) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => "Cannot revert '{$oldStatus}' order to '{$newStatus}'"]);
                exit;
            }
            redirect(adminUrl("pages/order-view.php?id={$id}&msg=validation_error&detail=" . urlencode("Cannot revert '{$oldStatus}' order to '{$newStatus}'")));
        }
        
        $db->update('orders', ['order_status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        $db->insert('order_status_history', ['order_id'=>$id,'status'=>$newStatus,'changed_by'=>getAdminId(),'note'=>$notes]);
        logActivity(getAdminId(), 'update_status', 'orders', $id, "Changed to {$newStatus}");
        if ($newStatus === 'delivered')  {
            try { awardOrderCredits($id); } catch (\Throwable $e) {}
            // Record income accounting entry
            try {
                $existing = $db->fetch("SELECT id FROM accounting_entries WHERE reference_type='order' AND reference_id=? AND entry_type='income'", [$id]);
                if (!$existing) {
                    $db->insert('accounting_entries', [
                        'entry_type' => 'income',
                        'amount' => floatval($order['total']),
                        'reference_type' => 'order',
                        'reference_id' => $id,
                        'description' => 'Order #' . $order['order_number'] . ' delivered',
                        'entry_date' => date('Y-m-d'),
                    ]);
                }
            } catch (\Throwable $e) {}
        }
        if ($newStatus === 'cancelled')  {
            try { refundOrderCreditsOnCancel($id); } catch (\Throwable $e) {}
            // Record refund accounting entry if income was previously recorded
            try {
                $incomeEntry = $db->fetch("SELECT id FROM accounting_entries WHERE reference_type='order' AND reference_id=? AND entry_type='income'", [$id]);
                if ($incomeEntry) {
                    $existing = $db->fetch("SELECT id FROM accounting_entries WHERE reference_type='order' AND reference_id=? AND entry_type='refund'", [$id]);
                    if (!$existing) {
                        $db->insert('accounting_entries', [
                            'entry_type' => 'refund',
                            'amount' => floatval($order['total']),
                            'reference_type' => 'order',
                            'reference_id' => $id,
                            'description' => 'Order #' . $order['order_number'] . ' cancelled (refund)',
                            'entry_date' => date('Y-m-d'),
                        ]);
                    }
                }
            } catch (\Throwable $e) {}
        }

        // ── Stock Management: restore stock on cancel/return ──
        $stockRestoreStatuses = ['cancelled', 'returned'];
        $stockConfirmedStatuses = ['confirmed', 'ready_to_ship', 'shipped', 'delivered'];
        if (in_array($newStatus, $stockRestoreStatuses) && in_array($oldStatus, $stockConfirmedStatuses)) {
            try {
                $orderItems = $db->fetchAll("SELECT oi.product_id, oi.quantity, oi.variant_name FROM order_items oi WHERE oi.order_id = ?", [$id]);
                foreach ($orderItems as $oi) {
                    if (!$oi['product_id']) continue;
                    $prod = $db->fetch("SELECT manage_stock, combined_stock FROM products WHERE id = ?", [$oi['product_id']]);
                    if (!$prod || !intval($prod['manage_stock'] ?? 1)) continue;
                    $qty = intval($oi['quantity']);

                    if (intval($prod['combined_stock'] ?? 0) && !empty($oi['variant_name'])) {
                        $variant = $db->fetch("SELECT id, weight_per_unit FROM product_variants WHERE product_id = ? AND CONCAT(variant_name, ': ', variant_value) = ? LIMIT 1", [$oi['product_id'], $oi['variant_name']]);
                        if ($variant) {
                            $weight = floatval($variant['weight_per_unit'] ?? 0);
                            if ($weight > 0) {
                                $db->query("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?", [$weight * $qty, $oi['product_id']]);
                            }
                        }
                    } else {
                        $db->query("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?", [$qty, $oi['product_id']]);
                        if (!empty($oi['variant_name'])) {
                            try {
                                $variant = $db->fetch("SELECT id FROM product_variants WHERE product_id = ? AND CONCAT(variant_name, ': ', variant_value) = ? LIMIT 1", [$oi['product_id'], $oi['variant_name']]);
                                if ($variant) { $db->query("UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ?", [$qty, $variant['id']]); }
                            } catch (\Throwable $e) {}
                        }
                    }
                    $db->query("UPDATE products SET stock_status = CASE WHEN stock_quantity > 0 THEN 'in_stock' ELSE 'out_of_stock' END WHERE id = ? AND manage_stock = 1", [$oi['product_id']]);
                }
            } catch (\Throwable $e) { error_log("Stock restore failed for order {$id}: " . $e->getMessage()); }
        }

        // Release order lock on status change
        try { $db->query("DELETE FROM order_locks WHERE order_id = ?", [$id]); } catch (\Throwable $e) {}
        if ($isModal) {
            while (ob_get_level()) ob_end_clean();
            echo '<html><body><script>window.parent.postMessage({type:"orderSaved",action:"status_updated",order_id:' . $id . ',new_status:"' . addslashes($newStatus) . '"},"*");</script></body></html>';
            exit;
        }
        if ($isProcSession) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success'=>true,'action'=>'status_updated','new_status'=>$newStatus,'order_id'=>$id]);
            exit;
        }
        redirect(adminUrl("pages/order-management.php?{$_returnQs}highlight={$id}&msg=status_updated"));
    }

    if ($action === 'mark_fake') {
        $db->update('orders', ['is_fake'=>1,'order_status'=>'cancelled'], 'id = ?', [$id]);
        try { refundOrderCreditsOnCancel($id); } catch (\Throwable $e) {}
        $ex = $db->fetch("SELECT id FROM blocked_phones WHERE phone = ?", [$order['customer_phone']]);
        if (!$ex) $db->insert('blocked_phones', ['phone'=>$order['customer_phone'],'reason'=>'Fake order #'.$order['order_number'],'blocked_by'=>getAdminId()]);
        logActivity(getAdminId(), 'mark_fake', 'orders', $id);
        try { $db->query("DELETE FROM order_locks WHERE order_id = ?", [$id]); } catch (\Throwable $e) {}
        redirect(adminUrl("pages/order-management.php?highlight={$id}&msg=marked_fake"));
    }

    if ($action === 'add_note') {
        $note = sanitize($_POST['note_text'] ?? '');
        $isAjax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($note) {
            $ex = $order['panel_notes'] ?? $order['admin_notes'] ?? '';
            $adminName = '';
            try { $au = $db->fetch("SELECT full_name FROM admin_users WHERE id = ?", [getAdminId()]); $adminName = $au['full_name'] ?? ''; } catch (\Throwable $e) {}
            $prefix = date('d M h:i A') . ($adminName ? " ({$adminName})" : '');
            $entry = "{$prefix}: ".$note;
            $new = $ex ? $ex."\n---\n".$entry : $entry;
            $db->update('orders', ['panel_notes'=>$new], 'id = ?', [$id]);
            logActivity(getAdminId(), 'add_note', 'orders', $id, null, $note);
            $db->insert('order_status_history', ['order_id'=>$id,'status'=>$order['order_status'],'changed_by'=>getAdminId(),'note'=>'Panel Note: '.mb_strimwidth($note,0,100,'...')]);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'entry'=>$entry,'time'=>date('d M h:i A'),'admin'=>$adminName]); exit; }
        } else {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Empty note']); exit; }
        }
        redirect(adminUrl("pages/order-management.php?highlight={$id}&msg=note_added"));
    }

    if ($action === 'clear_panel_notes') {
        $isAjax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        $db->update('orders', ['panel_notes'=>null, 'admin_notes'=>null], 'id = ?', [$id]);
        logActivity(getAdminId(), 'clear_notes', 'orders', $id, null, 'Panel notes cleared');
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
        redirect(adminUrl("pages/order-management.php?{$_returnQs}msg=updated&highlight={$id}"));
    }
}

/* ─── Reload data ─── */
$order    = $db->fetch("SELECT * FROM orders WHERE id = ?", [$id]);
$items    = $db->fetchAll("SELECT oi.*, p.slug, p.featured_image, p.sku, p.stock_quantity FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?", [$id]);
$history  = $db->fetchAll("SELECT osh.*, au.full_name as changed_by_name FROM order_status_history osh LEFT JOIN admin_users au ON au.id = osh.changed_by WHERE osh.order_id = ? ORDER BY osh.created_at DESC", [$id]);
$customer = $order['customer_id'] ? $db->fetch("SELECT * FROM customers WHERE id = ?", [$order['customer_id']]) : null;

$activityLogs = [];
try { $activityLogs = $db->fetchAll("SELECT al.*, au.full_name as admin_name FROM activity_logs al LEFT JOIN admin_users au ON au.id = al.admin_user_id WHERE al.entity_type='orders' AND al.entity_id=? ORDER BY al.created_at DESC LIMIT 30", [$id]); } catch (\Throwable $e) {}
try { $lv = $db->fetch("SELECT id FROM activity_logs WHERE admin_user_id=? AND entity_type='orders' AND entity_id=? AND action='view_order' AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)", [getAdminId(), $id]); if (!$lv) logActivity(getAdminId(), 'view_order', 'orders', $id); } catch (\Throwable $e) {}

/* Success rates */
$sr = ['total'=>0,'delivered'=>0,'cancelled'=>0,'returned'=>0,'rate'=>0,'total_spent'=>0];
$ph = '%'.substr(preg_replace('/[^0-9]/','',$order['customer_phone']),-10).'%';
try {
    $r = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN order_status='returned' THEN 1 ELSE 0 END) as returned, SUM(total) as total_spent FROM orders WHERE customer_phone LIKE ?", [$ph]);
    if ($r) $sr = ['total'=>intval($r['total']),'delivered'=>intval($r['delivered']),'cancelled'=>intval($r['cancelled']),'returned'=>intval($r['returned']),'rate'=>$r['total']>0?round($r['delivered']/$r['total']*100):0,'total_spent'=>floatval($r['total_spent']??0)];
} catch (\Throwable $e) {}

$courierRates = [];
foreach (['Pathao','RedX','Steadfast'] as $cn) {
    try {
        $cr = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled FROM orders WHERE customer_phone LIKE ? AND (LOWER(courier_name) LIKE ? OR LOWER(shipping_method) LIKE ?)", [$ph, strtolower($cn).'%', '%'.strtolower($cn).'%']);
        $courierRates[$cn] = ['total'=>intval($cr['total']??0),'delivered'=>intval($cr['delivered']??0),'cancelled'=>intval($cr['cancelled']??0),'rate'=>($cr['total']??0)>0?round($cr['delivered']/$cr['total']*100):0];
    } catch (\Throwable $e) { $courierRates[$cn] = ['total'=>0,'delivered'=>0,'cancelled'=>0,'rate'=>0]; }
}

$webCancels = 0;
try { $wc = $db->fetch("SELECT COUNT(*) as cnt FROM orders WHERE customer_phone LIKE ? AND channel='website' AND order_status='cancelled'", [$ph]); $webCancels = intval($wc['cnt']??0); } catch (\Throwable $e) {}

$createdAgo  = timeAgo($order['created_at']);
$updatedAgo  = timeAgo($order['updated_at'] ?? $order['created_at']);
$isPending   = in_array($order['order_status'], ['pending','processing','incomplete']);
$visitorLog  = null;
try { if (!empty($order['visitor_id'])) $visitorLog = $db->fetch("SELECT * FROM visitor_logs WHERE id = ?", [$order['visitor_id']]); elseif (!empty($order['ip_address'])) $visitorLog = $db->fetch("SELECT * FROM visitor_logs WHERE device_ip=? AND created_at >= DATE_SUB(?, INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 1", [$order['ip_address'], $order['created_at']]); } catch (\Throwable $e) {}
$orderTags   = [];
try { $orderTags = $db->fetchAll("SELECT * FROM order_tags WHERE order_id = ?", [$id]); } catch (\Throwable $e) {}
$custCredit  = 0;
if ($order['customer_id']) { try { $custCredit = getStoreCredit($order['customer_id']); } catch (\Throwable $e) {} }

if ($isModal) {
    // Modal mode: minimal HTML, no sidebar/nav
    $modalCss = file_get_contents(__DIR__ . '/../includes/header.php');
    // Modal mode: minimal standalone page — no sidebar/nav
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Order #<?= e($order['order_number']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<style>
:root{--primary:#f97316;--primary-dark:#ea580c}
*{box-sizing:border-box}
html,body{height:100%;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;font-size:13px;background:#f1f5f9;overflow-x:hidden}
input,select,textarea{font-family:inherit;font-size:13px}
/* Hide full-page nav/sidebar shell */
#adminWrap,#sidebar,.sidebar-scroll,.om-nav,.top-bar{display:none!important}
/* Keep everything in a clean scrollable container */
.modal-page-wrap{width:100%;min-height:100vh;padding:16px;overflow-y:auto}
/* Fix flex/grid layouts to work without sidebar context */
.flex.flex-col.lg\:flex-row{flex-direction:column!important}
/* Standard tailwind resets for forms */
input[type=text],input[type=number],input[type=email],input[type=tel],input[type=date],select,textarea{
  width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;outline:none;
  transition:border-color .15s,box-shadow .15s;
}
input:focus,select:focus,textarea:focus{border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.15)}
</style>
</head>
<body>
<div class="modal-page-wrap">
<?php
} else {
    require_once __DIR__ . '/../includes/header.php';
}

/* ─── ORDER LOCK SYSTEM ─── */
$__lockBlocked = false;
$__lockedByName = '';
$__lockedById = 0;
$__currentAdminId = getAdminId();
$__currentAdminName = '';
try { $__au = $db->fetch("SELECT full_name, username FROM admin_users WHERE id = ?", [$__currentAdminId]); $__currentAdminName = trim($__au['full_name'] ?? '') ?: ($__au['username'] ?? ''); } catch (\Throwable $e) {}
if (!$__currentAdminName) $__currentAdminName = trim($_SESSION['admin_name'] ?? '') ?: 'Admin #'.$__currentAdminId;

// Ensure table exists
try { $db->query("CREATE TABLE IF NOT EXISTS order_locks (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL UNIQUE, admin_user_id INT NOT NULL, admin_name VARCHAR(100) NOT NULL DEFAULT '', locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_heartbeat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_order (order_id), INDEX idx_heartbeat (last_heartbeat)) ENGINE=InnoDB"); } catch (\Throwable $e) {}
// Clean expired locks (90s timeout — generous to avoid false positives) AND corrupt locks
try { $db->query("DELETE FROM order_locks WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 90 SECOND) OR admin_user_id = 0 OR admin_user_id IS NULL OR admin_name = ''"); } catch (\Throwable $e) {}

// Check existing lock — only consider locks with valid admin_user_id and fresh heartbeat
$__existingLock = null;
try { $__existingLock = $db->fetch("SELECT * FROM order_locks WHERE order_id = ? AND admin_user_id > 0 AND admin_name != '' AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 90 SECOND)", [$id]); } catch (\Throwable $e) {}

if ($__existingLock && intval($__existingLock['admin_user_id']) !== $__currentAdminId) {
    // Locked by another admin
    $__lockBlocked = true;
    $__lockedByName = $__existingLock['admin_name'] ?: 'Another user';
    $__lockedById = intval($__existingLock['admin_user_id']);
    // In processing session: signal JS to skip — return JSON, never show lock screen
    if ($isProcSession) {
        // Return a minimal HTML page that skips to next order via sessionStorage
        // (Cannot use JSON — browser page navigation renders JSON as plain text, no JS runs)
        $skipName = htmlspecialchars($__lockedByName ?: 'Another user', ENT_QUOTES);
        $procUrl  = addslashes(adminUrl('pages/order-processing.php'));
        $viewUrl  = addslashes(adminUrl('pages/order-view.php'));
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<script>
(function(){
    var SESSION_KEY='procSession';
    var LOCKED_ID=".intval($id).";
    var LOCKED_BY='$skipName';
    var ps=null;
    try{ps=JSON.parse(sessionStorage.getItem(SESSION_KEY));}catch(e){}
    if(ps&&Array.isArray(ps.queue)){
        if(!ps.skippedInSession)ps.skippedInSession={};
        ps.skippedInSession[LOCKED_BY]=(ps.skippedInSession[LOCKED_BY]||0)+1;
        var pos=ps.queue.indexOf(LOCKED_ID);
        if(pos<0)pos=ps.current||0;
        // Find next unlocked order (skip forward)
        var next=pos+1;
        ps.current=next;
        sessionStorage.setItem(SESSION_KEY,JSON.stringify(ps));
        if(next<ps.queue.length){
            window.location.replace('$viewUrl?id='+ps.queue[next]+'&proc_session=1');
        }else{
            sessionStorage.removeItem(SESSION_KEY);
            window.location.replace('$procUrl');
        }
    }else{
        window.location.replace('$procUrl');
    }
})();
</script>
<title>Skipping...</title></head>
<body style='font-family:sans-serif;padding:40px;text-align:center;color:#666'>
<p>⏭ Skipping locked order...</p>
</body></html>";
        exit;
    }
} else {
    // Acquire / refresh own lock
    try {
        $db->query("INSERT INTO order_locks (order_id, admin_user_id, admin_name, locked_at, last_heartbeat) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE admin_user_id = VALUES(admin_user_id), admin_name = VALUES(admin_name), locked_at = NOW(), last_heartbeat = NOW()", [$id, $__currentAdminId, $__currentAdminName]);
    } catch (\Throwable $e) {
        try { $db->query("DELETE FROM order_locks WHERE order_id = ?", [$id]); $db->insert('order_locks', ['order_id'=>$id,'admin_user_id'=>$__currentAdminId,'admin_name'=>$__currentAdminName,'locked_at'=>date('Y-m-d H:i:s'),'last_heartbeat'=>date('Y-m-d H:i:s')]); } catch (\Throwable $e2) {}
    }
}
?>

<?php if ($__lockBlocked): ?>
<!-- ══════════════════════════ ACCESS RESTRICTED SCREEN ══════════════════════════ -->
<div id="lockScreen" class="max-w-xl mx-auto mt-16 px-4">
    <div class="bg-white border border-gray-200 rounded-2xl shadow-lg overflow-hidden">
        <div class="bg-amber-50 border-b border-amber-200 px-8 py-5 text-center">
            <div class="text-3xl mb-2">🔒</div>
            <h2 class="text-lg font-bold text-gray-800">Order Currently Being Edited</h2>
        </div>
        <div class="px-8 py-6 text-center">
            <p class="text-gray-600 text-sm mb-6">
                <strong class="text-gray-800" id="lockOwnerName"><?= htmlspecialchars($__lockedByName ?: 'Another user') ?></strong> is currently working on this order. You can wait for them to finish, or take over editing.
            </p>
            <div class="flex items-center justify-center gap-3 flex-wrap" id="lockActions">
                <button onclick="doTakeover()" id="btnTakeover"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-2.5 rounded-lg transition text-sm">
                    Take Over
                </button>
                <button onclick="location.reload()" class="bg-white hover:bg-gray-50 text-gray-700 font-semibold px-5 py-2.5 rounded-lg border border-gray-300 transition text-sm">
                    Retry
                </button>
                <a href="<?= adminUrl('pages/order-management.php') ?>" class="text-gray-400 hover:text-gray-600 font-medium px-4 py-2.5 text-sm transition">
                    ← Back to Orders
                </a>
            </div>
            <div class="hidden mt-4" id="confirmTakeoverBox">
                <p class="text-xs text-amber-600 mb-3">This will remove <?= htmlspecialchars($__lockedByName ?: 'the other user') ?>'s access. Are you sure?</p>
                <button onclick="confirmTakeover()" id="btnConfirmTakeover"
                        class="bg-red-600 hover:bg-red-700 text-white font-semibold px-5 py-2.5 rounded-lg transition text-sm">
                    Yes, Take Over
                </button>
                <button onclick="cancelTakeover()" class="bg-white hover:bg-gray-50 text-gray-600 font-medium px-5 py-2.5 rounded-lg border border-gray-300 transition text-sm ml-2">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const LOCK_API = '<?= SITE_URL ?>/api/order-lock.php';
const ORDER_ID = <?= intval($id) ?>;

function doTakeover() {
    document.getElementById('lockActions').classList.add('hidden');
    document.getElementById('confirmTakeoverBox').classList.remove('hidden');
}
function cancelTakeover() {
    document.getElementById('confirmTakeoverBox').classList.add('hidden');
    document.getElementById('lockActions').classList.remove('hidden');
}
function confirmTakeover() {
    const btn = document.getElementById('btnConfirmTakeover');
    btn.disabled = true; btn.textContent = 'Taking over...';
    fetch(LOCK_API, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=takeover&order_id=' + ORDER_ID
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            location.reload();
        } else {
            alert('Failed to take over: ' + (d.error || 'Unknown error'));
            btn.disabled = false; btn.textContent = 'Confirm Take Over';
        }
    })
    .catch(e => { alert('Error: ' + e.message); btn.disabled = false; btn.textContent = 'Confirm Take Over'; });
}
</script>

<?php
if ($isModal) { echo '</div></body></html>'; } else { require_once __DIR__ . '/../includes/footer.php'; }
exit; endif; /* end lockBlocked */ ?>

<?php if (isset($_GET['msg'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm">
    <?= ['status_updated'=>'✓ Status updated.','updated'=>'✓ Order saved.','marked_fake'=>'⚠ Marked as fake.','confirmed'=>'✅ Order confirmed.','note_added'=>'✓ Note added.','validation_error'=>'❌ ' . htmlspecialchars($_GET['detail'] ?? 'Missing required fields')][$_GET['msg']] ?? '✓ Done.' ?>
</div>
<?php endif; ?>

<?php if ($isProcSession): ?>
<!-- ══ PROCESSING SESSION BAR ══ -->
<div id="procSessionBar" class="sticky top-0 z-40 mb-4" style="margin:-4px -4px 16px -4px">
  <div class="bg-gray-900 text-white px-4 py-2.5 flex flex-wrap items-center gap-2 shadow-lg" style="border-radius:0 0 12px 12px">
    <!-- Label + counter -->
    <div class="flex items-center gap-2 flex-shrink-0">
      <div class="w-2 h-2 rounded-full bg-yellow-400 animate-pulse"></div>
      <span class="text-[11px] font-bold text-yellow-400 uppercase tracking-wider hidden sm:inline">Processing Session</span>
    </div>
    <div class="flex items-center gap-1 text-xs bg-white/10 px-2.5 py-1 rounded-full flex-shrink-0">
      <span id="psPosition" class="font-bold text-white">—</span>
      <span class="text-gray-500">/</span>
      <span id="psTotal" class="font-bold text-white">—</span>
    </div>
    <!-- Order info -->
    <div class="text-xs text-gray-300 truncate flex-1 min-w-0">
      #<?= e($order['order_number']) ?> · <?= e($order['customer_name']) ?>
    </div>
    <!-- Session actions: Print sticker, Print invoice, Add tag -->
    <div class="flex items-center gap-1.5 flex-shrink-0">
      <button type="button" onclick="psPrintSticker()"
        class="flex items-center gap-1 px-2.5 py-1.5 bg-orange-500 hover:bg-orange-600 rounded-lg text-[11px] font-semibold transition"
        title="Print Sticker">
        🏷 Sticker
      </button>
      <button type="button" onclick="psPrintInvoice()"
        class="flex items-center gap-1 px-2.5 py-1.5 bg-blue-600 hover:bg-blue-700 rounded-lg text-[11px] font-semibold transition"
        title="Print Invoice">
        📄 Invoice
      </button>
      <button type="button" onclick="psAddTag()"
        class="flex items-center gap-1 px-2.5 py-1.5 bg-white/10 hover:bg-white/20 rounded-lg text-[11px] font-semibold transition"
        title="Add Tag">
        🏷 Tag
      </button>
    </div>
    <div class="w-px h-5 bg-white/20 flex-shrink-0"></div>
    <!-- Prev / Next -->
    <div class="flex items-center gap-1.5 flex-shrink-0">
      <button id="psPrevBtn" type="button" onclick="psNavigate(-1)"
        class="flex items-center gap-1 px-3 py-1.5 bg-white/10 hover:bg-white/20 rounded-lg text-xs font-semibold transition"
        style="opacity:0.4;cursor:not-allowed">
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>Prev
      </button>
      <button id="psNextBtn" type="button" onclick="psNavigate(1)"
        class="flex items-center gap-1 px-3 py-1.5 bg-yellow-500 hover:bg-yellow-400 rounded-lg text-xs font-bold transition">
        Next<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </button>
    </div>
    <div class="w-px h-5 bg-white/20 flex-shrink-0"></div>
    <span id="psProcessed" class="text-xs text-green-400 font-bold flex-shrink-0">0 done</span>
    <button type="button" onclick="psExit()"
      class="text-xs text-gray-500 hover:text-white px-2 py-1 rounded hover:bg-white/10 transition flex-shrink-0">
      ✕ Exit
    </button>
  </div>
  <!-- Progress bar -->
  <div id="psProgressWrap" class="h-1.5 bg-gray-800 overflow-hidden" style="border-radius:0 0 4px 4px">
    <div id="psProgress" class="h-full bg-yellow-400 transition-all duration-300" style="width:0%"></div>
  </div>
</div>
<!-- Tag mini-modal for session -->
<div id="psTagModal" class="hidden fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center" onclick="if(event.target===this)document.getElementById('psTagModal').classList.add('hidden')">
  <div class="bg-white rounded-xl p-5 w-72 shadow-2xl">
    <h3 class="font-bold text-gray-800 text-sm mb-3">Add Tag to #<?= e($order['order_number']) ?></h3>
    <div class="flex flex-wrap gap-1.5 mb-3">
      <?php foreach(['REPEAT','URGENT','VIP','GIFT','FOLLOW UP','COD VERIFIED','ADVANCE PAID'] as $pt): ?>
      <button type="button" onclick="psSubmitTag('<?= $pt ?>')" class="text-[11px] bg-gray-100 hover:bg-blue-100 hover:text-blue-700 px-2.5 py-1.5 rounded-lg font-medium transition"><?= $pt ?></button>
      <?php endforeach; ?>
    </div>
    <div class="flex gap-2">
      <input type="text" id="psTagInput" placeholder="Custom tag..." class="flex-1 px-3 py-2 border rounded-lg text-xs focus:border-blue-400 outline-none" onkeydown="if(event.key==='Enter'){event.preventDefault();psSubmitTag(this.value)}">
      <button type="button" onclick="psSubmitTag(document.getElementById('psTagInput').value)" class="bg-blue-600 text-white px-3 py-2 rounded-lg text-xs font-semibold">Add</button>
    </div>
    <button type="button" onclick="document.getElementById('psTagModal').classList.add('hidden')" class="mt-2 text-[11px] text-gray-400 w-full text-center hover:text-gray-600">Cancel</button>
  </div>
</div>
<?php endif; ?>

<!-- ══ CO-VIEWER BANNER ══ -->
<?php if (!$isModal && !$isProcSession): ?>
<div id="coViewerBanner" class="hidden mb-3 px-4 py-2 bg-blue-50 border border-blue-200 rounded-lg flex items-center gap-3 text-sm text-blue-700">
  <svg class="w-4 h-4 flex-shrink-0 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
  <span id="coViewerText"></span>
</div>
<?php endif; ?>

<!-- ══════════════════════════ HEADER BAR ══════════════════════════ -->
<div class="flex flex-wrap items-center gap-3 mb-4">
    <?php if (!$isModal): ?>
    <a href="<?= adminUrl('pages/order-management.php'.($isPending?'?status=processing':'')) ?>" class="p-1.5 rounded hover:bg-gray-100 transition">
        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <?php endif; ?>
    <h2 class="text-base font-bold text-gray-800"><?= $isPending ? 'Web Order Details' : 'Order Details' ?></h2>
    <div class="flex items-center gap-1.5 ml-auto flex-wrap text-xs">
        <span class="text-gray-500">Created <b><?= $createdAgo ?></b></span>
        <span class="text-gray-500">Updated <b><?= $updatedAgo ?></b></span>
        <span class="text-gray-500">Status</span>
        <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= getOrderStatusBadge($order['order_status']) ?>"><?= strtoupper(getOrderStatusLabel($order['order_status'])) ?></span>
        <span class="text-gray-500">Source</span>
        <span class="bg-gray-100 px-2 py-0.5 rounded text-[10px] font-medium"><?= strtoupper($order['channel']??'WEB')==='WEBSITE'?'WEB':strtoupper($order['channel']??'WEB') ?></span>
    </div>
</div>

<!-- ══════════════════════════ MAIN LAYOUT ══════════════════════════ -->
<?php
$_isShippedLocked = in_array($order['order_status'], ['confirmed', 'ready_to_ship', 'shipped', 'delivered', 'returned', 'partial_delivered', 'pending_return', 'pending_cancel']);
if ($_isShippedLocked): ?>
<div class="bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded-lg mb-4 text-sm flex items-center gap-2">
    <i class="fas fa-lock text-amber-500"></i>
    <span><strong>Read-only:</strong> This order is <strong><?= ucfirst(str_replace('_', ' ', $order['order_status'])) ?></strong> and cannot be edited. Only status changes and courier actions are allowed.</span>
</div>
<?php endif; ?>
<form method="POST" id="orderForm">
<div class="flex flex-col lg:flex-row gap-5">

    <!-- ────────── LEFT COLUMN ────────── -->
<?php if ($_isShippedLocked): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
    // Disable all editable inputs in the order form (except status dropdown and courier actions)
    const form = document.getElementById('orderForm');
    if(!form) return;
    form.querySelectorAll('input:not([type="hidden"]):not([name="action"]), textarea, select:not([name="order_status_change"])').forEach(el => {
        // Allow status dropdown, courier selectors, and note fields
        const nm = el.name || '';
        if(['order_status_change'].includes(nm)) return;
        if(el.closest('#courierSection') || el.closest('#statusChangeSection') || el.closest('.panel-notes-area')) return;
        el.disabled = true;
        el.style.opacity = '0.6';
        el.style.cursor = 'not-allowed';
    });
    // Hide confirm/save buttons for locked orders
    form.querySelectorAll('button[value="save_order"], button[value="confirm_order"]').forEach(btn => {
        btn.disabled = true;
        btn.style.opacity = '0.4';
        btn.title = 'Cannot edit after shipping';
    });
});
</script>
<?php endif; ?>
    <div class="flex-1 min-w-0 space-y-4">

        <!-- ▸ Rate Cards -->
        <div id="courierCards" class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <?php
            $cards = ['Overall'=>$sr]+$courierRates;
            foreach ($cards as $label => $data):
                $rate = $data['rate']??0;
                $rcl  = $rate>=70?'color:#16a34a':($rate>=40?'color:#ca8a04':'color:#dc2626');
                $barColor = $rate>=70?'#22c55e':($rate>=40?'#eab308':'#ef4444');
            ?>
            <div class="bg-white border border-gray-200 rounded-lg p-3" id="card-<?= strtolower($label) ?>">
                <div class="text-sm font-semibold text-gray-800 mb-1"><?= $label ?></div>
                <div class="text-xs font-bold mb-1" style="<?= $rcl ?>" data-rate>Success Rate: <?= $rate ?>%</div>
                <div class="text-[11px] text-gray-500 leading-relaxed" data-total>Total: <?= $data['total'] ?></div>
                <div class="text-[11px] text-gray-500 leading-relaxed" data-success>Success: <?= $data['delivered'] ?></div>
                <div class="text-[11px] text-gray-500 leading-relaxed" data-cancelled>Cancelled: <?= $data['cancelled'] ?></div>
                <div class="h-1.5 bg-gray-100 rounded-full mt-2"><div class="h-full rounded-full transition-all" data-bar style="width:<?= min(100,$rate) ?>%;background:<?= $barColor ?>"></div></div>
            </div>
            <?php endforeach; ?>

            <div class="bg-white border border-gray-200 rounded-lg p-3" id="card-ourrecord">
                <div class="text-sm font-semibold text-gray-800 mb-1">Our Record</div>
                <?php if ($sr['total'] <= 1): ?>
                <div class="text-xs font-bold text-blue-600 mb-1" data-custtype>New Customer</div>
                <?php endif; ?>
                <div class="text-[11px] text-gray-500 leading-relaxed" data-ourtotal>Total: <?= $sr['total'] ?></div>
                <div class="text-[11px] text-gray-500 leading-relaxed" data-ourcancelled>Cancelled: <?= $sr['cancelled'] ?></div>
                <div class="text-[11px] text-gray-500 leading-relaxed" data-webcancel>Web Order Cancel: <?= $webCancels ?></div>
                <div class="text-[11px] text-gray-500 leading-relaxed" data-totalspent>Total Spent: ৳<?= number_format($sr['total_spent']??0) ?></div>
                <button type="button" onclick="fetchCourierData()" id="fillInfoBtn" class="w-full mt-2 py-1.5 bg-gray-200 text-gray-700 rounded-md text-xs font-semibold hover:bg-gray-300 transition">Fill</button>
                <div class="h-1.5 bg-gray-100 rounded-full mt-2"><div class="h-full rounded-full transition-all" style="width:<?= $sr['total']>0?min(100,$sr['rate']):0 ?>%;background:<?= $sr['rate']>=70?'#22c55e':($sr['rate']>=40?'#eab308':'#ef4444') ?>"></div></div>
            </div>
        </div>

        <!-- ▸ Store Credit Banner -->
        <?php if ($custCredit > 0): ?>
        <div class="flex items-center gap-2 bg-yellow-50 border border-yellow-200 rounded-lg px-4 py-2 text-sm">
            <i class="fas fa-coins text-yellow-500"></i>
            <span class="text-yellow-700">Store Credit: <b><?= number_format($custCredit) ?> credits</b> <span class="text-xs">(৳<?= number_format($custCredit * floatval(getSetting('store_credit_conversion_rate','0.75')?:0.75)) ?>)</span></span>
            <?php if ($order['customer_id']): ?><a href="<?= adminUrl('pages/customer-view.php?id='.$order['customer_id'].'&section=credits') ?>" class="ml-auto text-xs text-yellow-600 hover:underline">Manage →</a><?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ▸ Customer Info Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-x-5 gap-y-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">Mobile Number</label>
                    <div class="flex items-center gap-2">
                        <input type="text" name="customer_phone" id="customerPhoneInput" value="<?= e($order['customer_phone']) ?>" class="flex-1 px-3 py-2 border border-gray-200 rounded-md text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-100 outline-none">
                        <a href="tel:<?= e($order['customer_phone']) ?>" class="text-green-600 hover:text-green-700 text-sm"><i class="fas fa-phone"></i></a>
                        <a href="https://wa.me/88<?= preg_replace('/[^0-9]/','',$order['customer_phone']) ?>" target="_blank" class="text-green-600 hover:text-green-700"><i class="fab fa-whatsapp text-base"></i></a>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">Name</label>
                    <input type="text" name="customer_name" value="<?= e($order['customer_name']) ?>" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-100 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">Delivery Method</label>
                    <select name="delivery_method" id="deliveryMethodSelect" onchange="updateUploadBtn()" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-100 outline-none">
                        <?php
                        $__deliveryMethods = getDeliveryMethods();
                        $__currentDm = normalizeCourierName($order['shipping_method'] ?? $order['courier_name'] ?? '');
                        foreach ($__deliveryMethods as $dm):
                            if (empty($dm['enabled']) && $dm['name'] !== $__currentDm) continue;
                        ?>
                        <option value="<?= e($dm['name']) ?>" <?= normalizeCourierName($dm['name']) === $__currentDm || $dm['name'] === ($order['shipping_method'] ?? '') ? 'selected' : '' ?>><?= e($dm['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($order['courier_status'])): ?><div class="text-[10px] text-indigo-600 mt-1">📡 <?= e($order['courier_status']) ?></div><?php endif; ?>
                    <?php if (!empty($order['courier_consignment_id']) || !empty($order['pathao_consignment_id'])):
                        $__ovCn = strtolower($order['courier_name'] ?: ($order['shipping_method'] ?? ''));
                        $__ovCid = $order['courier_consignment_id'] ?: ($order['pathao_consignment_id'] ?? '');
                        $__ovTid = $order['courier_tracking_id'] ?: $__ovCid;
                        if (strpos($__ovCn, 'steadfast') !== false) {
                            $__ovLink = 'https://portal.steadfast.com.bd/find-consignment?consignment_id=' . urlencode($__ovCid);
                        } elseif (strpos($__ovCn, 'pathao') !== false) {
                            $__ovLink = 'https://merchant.pathao.com/courier/orders/' . urlencode($__ovCid);
                        } elseif (strpos($__ovCn, 'redx') !== false) {
                            $__ovLink = 'https://redx.com.bd/track-parcel/?trackingId=' . urlencode($__ovTid);
                        } else { $__ovLink = '#'; }
                    ?>
                    <a href="<?= $__ovLink ?>" target="_blank" class="inline-flex items-center gap-1 mt-1 px-2 py-1 bg-green-50 border border-green-200 rounded text-xs text-green-700 hover:bg-green-100 transition font-mono">
                        📦 <?= e($__ovTid) ?> <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ▸ Address / Notes / Extra Options Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-5 gap-y-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">Address</label>
                    <textarea name="customer_address" rows="2" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-100 outline-none resize-none"><?= e($order['customer_address']) ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1.5">Extra Options</label>
                    <div class="flex items-center gap-1.5 mb-2">
                        <button type="button" onclick="navigator.clipboard.writeText('<?= e($order['order_number']) ?>')" class="p-2 border border-gray-200 rounded-md text-gray-500 hover:bg-gray-50 text-xs" title="Copy"><i class="far fa-copy"></i></button>
                        <a href="<?= adminUrl('pages/order-print.php?id='.$id) ?>" target="_blank" class="p-2 border border-gray-200 rounded-md text-gray-500 hover:bg-gray-50 text-xs" title="Print"><i class="fas fa-print"></i></a>
                        <a href="<?= adminUrl('pages/order-print.php?id='.$id.'&template=sticker') ?>" target="_blank" class="p-2 border border-gray-200 rounded-md text-gray-500 hover:bg-gray-50 text-xs" title="Sticker"><i class="fas fa-tag"></i></a>
                        <?php if (!$order['is_fake']): ?><button type="button" onclick="window._confirmAsync('Mark fake? Phone will be blocked.').then(function(_ok){ if(!_ok)return; document.getElementById('fakeForm').submit() })" class="p-2 border border-red-200 rounded-md text-red-400 hover:bg-red-50 text-xs" title="Fake"><i class="fas fa-ban"></i></button><?php endif; ?>
                    </div>
                    <div class="flex items-center gap-2">
                        <select name="channel" class="flex-1 px-2 py-1.5 border border-gray-200 rounded-md text-xs">
                            <?php foreach(['website'=>'🌐 WEB','facebook'=>'📘 Facebook','phone'=>'📞 Phone','whatsapp'=>'💬 WhatsApp','instagram'=>'📷 Instagram','other'=>'📌 Other'] as $cv=>$cl): ?>
                            <option value="<?= $cv ?>" <?= ($order['channel']??'website')===$cv?'selected':'' ?>><?= $cl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ▸ Three Notes Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Shipping Note -->
                <div>
                    <label class="flex items-center gap-1.5 text-sm font-semibold text-gray-800 mb-1.5">
                        <span class="w-2 h-2 rounded-full bg-orange-400"></span> Shipping Note
                        <button type="button" onclick="showNoteTpl('shipping_note','note_tpl_shipping')" class="ml-1 w-5 h-5 rounded bg-orange-100 text-orange-500 hover:bg-orange-200 flex items-center justify-center text-[10px] font-bold" title="Insert template">+</button>
                        <span class="text-[9px] bg-orange-50 text-orange-600 px-1.5 py-0.5 rounded font-medium ml-auto">→ Courier Only</span>
                    </label>
                    <textarea name="shipping_note" rows="3" class="w-full px-3 py-2 border border-orange-200 rounded-md text-sm focus:border-orange-400 focus:ring-1 focus:ring-orange-100 outline-none resize-none bg-orange-50/30" placeholder="***No Exchange or Return*** — sent to courier panel only"><?= e($order['notes']??'') ?></textarea>
                    <p class="text-[10px] text-orange-400 mt-1"><i class="fas fa-truck mr-0.5"></i> Sent to Pathao/Steadfast/RedX. Not visible on invoice.</p>
                </div>
                <!-- Order Note -->
                <div>
                    <label class="flex items-center gap-1.5 text-sm font-semibold text-gray-800 mb-1.5">
                        <span class="w-2 h-2 rounded-full bg-blue-400"></span> Order Note
                        <button type="button" onclick="showNoteTpl('order_note','note_tpl_order')" class="ml-1 w-5 h-5 rounded bg-blue-100 text-blue-500 hover:bg-blue-200 flex items-center justify-center text-[10px] font-bold" title="Insert template">+</button>
                        <span class="text-[9px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded font-medium ml-auto">→ Invoice Only</span>
                    </label>
                    <textarea name="order_note" rows="3" class="w-full px-3 py-2 border border-blue-200 rounded-md text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-100 outline-none resize-none bg-blue-50/30" placeholder="Special packaging request, gift message... — printed on invoice"><?= e($order['order_note']??'') ?></textarea>
                    <p class="text-[10px] text-blue-400 mt-1"><i class="fas fa-file-invoice mr-0.5"></i> Printed on invoice. Not sent to courier.</p>
                </div>
                <!-- Preorder -->
                <div>
                    <label class="flex items-center gap-1.5 text-sm font-semibold text-gray-800 mb-1.5">
                        <span class="w-2 h-2 rounded-full bg-purple-400"></span> Preorder
                    </label>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 p-2.5 rounded-md border <?= !empty($order['is_preorder']) ? 'border-purple-300 bg-purple-50' : 'border-gray-200 bg-gray-50' ?> cursor-pointer transition">
                            <input type="checkbox" name="is_preorder" value="1" <?= !empty($order['is_preorder'])?'checked':'' ?> class="rounded text-purple-600 w-4 h-4" onchange="document.getElementById('preorderDateWrap').classList.toggle('hidden',!this.checked);this.closest('label').classList.toggle('border-purple-300',this.checked);this.closest('label').classList.toggle('bg-purple-50',this.checked);this.closest('label').classList.toggle('border-gray-200',!this.checked);this.closest('label').classList.toggle('bg-gray-50',!this.checked)">
                            <div>
                                <span class="text-sm font-medium text-gray-700">Mark as Preorder</span>
                                <?php if (!empty($order['is_preorder'])): ?><span class="text-[9px] bg-purple-500 text-white px-1.5 py-0.5 rounded ml-1">ACTIVE</span><?php endif; ?>
                            </div>
                        </label>
                        <div id="preorderDateWrap" class="<?= empty($order['is_preorder']) ? 'hidden' : '' ?>">
                            <label class="block text-xs text-gray-600 mb-1">Expected Delivery Date</label>
                            <input type="date" name="preorder_date" value="<?= e($order['preorder_date'] ?? '') ?>" class="w-full px-3 py-2 border border-purple-200 rounded-md text-sm bg-purple-50/50 focus:border-purple-400 focus:ring-1 focus:ring-purple-100">
                            <?php if (!empty($order['preorder_date'])): ?>
                            <p class="text-[10px] text-purple-500 mt-1"><i class="fas fa-calendar-check mr-0.5"></i> ETA: <?= date('d M Y', strtotime($order['preorder_date'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ▸ Pathao City/Zone/Area Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="flex items-center gap-2 mb-2">
                <p class="text-xs text-blue-600 flex-1">📍 এড্রেস নিচের এই Filed গুলো অটোমেটিক ফিল হবে, যদি না হয় তাহলে সিলেক্ট করে নিন</p>
                <button type="button" onclick="autoDetectLocation()" class="p-1 text-gray-400 hover:text-blue-600" title="Auto-detect"><i class="fas fa-sync-alt text-xs"></i></button>
                <button type="button" onclick="clearAreaSelection()" class="p-1 text-gray-400 hover:text-red-500" title="Clear"><i class="fas fa-trash text-xs"></i></button>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div><label class="block text-xs font-semibold text-gray-700 mb-1">City</label><select id="pCityId" onchange="loadZones(this.value);saveOrderLocation()" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm"><option value="">Select City</option></select></div>
                <div><label class="block text-xs font-semibold text-gray-700 mb-1">Zone</label><select id="pZoneId" onchange="loadAreas(this.value);saveOrderLocation()" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" disabled><option value="">Select Zone</option></select></div>
                <div><label class="block text-xs font-semibold text-gray-700 mb-1">Area</label><select id="pAreaId" onchange="saveOrderLocation()" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" disabled><option value="">Select Area</option></select></div>
            </div>
            <?php
            $storedCityName = $order['delivery_city_name'] ?? '';
            $storedZoneName = $order['delivery_zone_name'] ?? '';
            $storedAreaName = $order['delivery_area_name'] ?? '';
            $hasStoredNames = ($storedCityName !== '' || $storedZoneName !== '' || $storedAreaName !== '');
            ?>
            <div id="areaPathLabel" class="mt-2 text-xs <?= $hasStoredNames ? '' : 'hidden' ?>">
                <span class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md bg-green-50 border border-green-200 text-green-700 font-medium" id="areaPathText">
                    <?php if ($hasStoredNames): ?>
                    ✅ <?= e($storedCityName) ?><?= $storedZoneName ? ' → '.e($storedZoneName) : '' ?><?= $storedAreaName ? ' → '.e($storedAreaName) : '' ?> <span class="text-green-400 text-[10px] ml-1">(saved)</span>
                    <?php endif; ?>
                </span>
            </div>
            <div id="autoDetectResult" class="mt-2 text-xs hidden"></div>
        </div>

        
        <!-- ▸ Courier Tracking Card (Pathao + Steadfast + Any courier) -->
        <?php 
        // Resolve consignment ID from ALL possible columns
        $__cid = $order['courier_consignment_id'] ?? '';
        $__pathaoCid = $order['pathao_consignment_id'] ?? '';
        $__tid = $order['courier_tracking_id'] ?? '';
        $__courierName = strtolower($order['courier_name'] ?: ($order['shipping_method'] ?? ''));
        
        // Normalize: pick best CID available
        if (empty($__cid) && !empty($__pathaoCid)) $__cid = $__pathaoCid;
        if (empty($__tid)) $__tid = $__cid;
        
        $__hasCid = !empty($__cid);
        $__isSf = strpos($__courierName, 'steadfast') !== false;
        $__isPathao = strpos($__courierName, 'pathao') !== false;
        $__isRedx = strpos($__courierName, 'redx') !== false;
        $__canUpload = in_array($order['order_status'], ['processing','confirmed','ready_to_ship','approved']);
        $__isShipped = in_array($order['order_status'], ['ready_to_ship','shipped','on_hold','pending_return','pending_cancel','partial_delivered','delivered']);
        
        // Build portal link
        if ($__isSf && $__hasCid) {
            $__portalLink = 'https://portal.steadfast.com.bd/find-consignment?consignment_id=' . urlencode($__cid);
            $__portalName = 'Steadfast Portal';
            $__trackLink = 'https://steadfast.com.bd/t/' . urlencode($__tid);
        } elseif ($__isPathao && $__hasCid) {
            $__portalLink = 'https://merchant.pathao.com/courier/orders/' . urlencode($__cid);
            $__portalName = 'Pathao Portal';
            $__trackLink = '';
        } elseif ($__isRedx && $__hasCid) {
            $__portalLink = 'https://redx.com.bd/track-parcel/?trackingId=' . urlencode($__tid);
            $__portalName = 'RedX Tracking';
            $__trackLink = 'https://redx.com.bd/track-parcel/?trackingId=' . urlencode($__tid);
        } else {
            $__portalLink = '';
            $__portalName = '';
            $__trackLink = '';
        }
        ?>
        <div id="sf-tracking-card" class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-3">
                <h4 class="text-sm font-bold text-gray-800">📦 Courier Tracking</h4>
                <div class="flex items-center gap-2">
                    <?php if ($__hasCid): ?>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200">✓ Uploaded</span>
                    <button type="button" onclick="courierSync(<?= $order['id'] ?>)" id="syncBtn" class="text-xs px-2 py-1 bg-purple-50 text-purple-700 rounded-lg hover:bg-purple-100 transition">🔄 Sync</button>
                    <?php elseif ($__canUpload): ?>
                    <button type="button" onclick="uploadToCourier(<?= $order['id'] ?>)" id="sfUploadBtn" class="text-xs px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-medium">🚀 Upload to <?= e($order['shipping_method'] ?? $order['courier_name'] ?? 'Courier') ?></button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($__hasCid): ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                <div>
                    <span class="text-gray-500 block">Courier</span>
                    <div class="font-semibold text-gray-800"><?= e(!empty($order['courier_name']) ? $order['courier_name'] : (!empty($order['shipping_method']) ? $order['shipping_method'] : 'Unknown')) ?></div>
                </div>
                <div>
                    <span class="text-gray-500 block">Consignment ID</span>
                    <?php if ($__portalLink): ?>
                    <a href="<?= $__portalLink ?>" target="_blank" class="font-mono font-semibold text-blue-600 hover:underline block"><?= e($__cid) ?> ↗</a>
                    <?php else: ?>
                    <div class="font-mono font-semibold text-gray-800"><?= e($__cid) ?></div>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="text-gray-500 block">Tracking Code</span>
                    <div class="font-mono font-semibold text-gray-800"><?= e($__tid) ?></div>
                </div>
                <div>
                    <span class="text-gray-500 block">Courier Status</span>
                    <?php 
                    $__cStat = $order['courier_status'] ?? 'unknown';
                    $__cStatColor = 'text-gray-600';
                    if (in_array($__cStat, ['delivered','delivered_approval_pending','Delivered','payment_invoice','Payment_Invoice'])) $__cStatColor = 'text-green-600';
                    elseif (in_array($__cStat, ['cancelled','cancelled_approval_pending','Cancelled','pickup_cancelled','pending_cancel'])) $__cStatColor = 'text-red-600';
                    elseif (in_array($__cStat, ['return','Return','Returned','Return_Ongoing','paid_return','exchange','Exchange','pending_return'])) $__cStatColor = 'text-orange-600';
                    elseif (in_array($__cStat, ['hold','Hold','on_hold','delivery_failed','pickup_failed'])) $__cStatColor = 'text-yellow-600';
                    elseif (in_array($__cStat, ['partial_delivery','partial_delivered','Partial_Delivered','partial_delivered_approval_pending'])) $__cStatColor = 'text-cyan-600';
                    elseif (in_array($__cStat, ['in_review','Picked','In_Transit','At_Transit','Delivery_Ongoing','in_transit','at_the_sorting_hub','assigned_for_delivery','received_at_last_mile_hub','pickup','assigned_for_pickup'])) $__cStatColor = 'text-blue-600';
                    elseif (in_array($__cStat, ['order_created','order_updated','pickup_requested'])) $__cStatColor = 'text-indigo-600';
                    ?>
                    <div class="font-semibold <?= $__cStatColor ?>" id="courierStatusVal"><?= e($__cStat) ?></div>
                </div>
            </div>
            
            <!-- Live-fetched data placeholder -->
            <div id="courierLiveData" class="hidden mt-2"></div>
            
            <?php if (!empty($order['courier_tracking_message'])): ?>
            <div class="mt-2 p-2 bg-blue-50 rounded text-xs text-blue-700" id="trackingMsg">📍 <?= e($order['courier_tracking_message']) ?></div>
            <?php else: ?>
            <div class="mt-2 p-2 bg-blue-50 rounded text-xs text-blue-700 hidden" id="trackingMsg"></div>
            <?php endif; ?>
            
            <?php if (!empty($order['courier_delivery_charge']) && floatval($order['courier_delivery_charge']) > 0): ?>
            <div class="mt-2 text-xs text-gray-500" id="courierCharges">Delivery Charge: ৳<?= number_format(floatval($order['courier_delivery_charge'])) ?> | COD: ৳<?= number_format(floatval($order['courier_cod_amount'] ?? 0)) ?></div>
            <?php else: ?>
            <div class="mt-2 text-xs text-gray-500 hidden" id="courierCharges"></div>
            <?php endif; ?>
            
            <?php if (!empty($order['courier_uploaded_at'])): ?>
            <div class="mt-1 text-[10px] text-gray-400">Uploaded: <?= date('d M Y, h:i A', strtotime($order['courier_uploaded_at'])) ?></div>
            <?php endif; ?>
            
            <div class="flex flex-wrap gap-2 mt-3">
                <?php if ($__portalLink): ?>
                <a href="<?= $__portalLink ?>" target="_blank" class="text-xs px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">🔗 <?= $__portalName ?></a>
                <?php endif; ?>
                <?php if ($__trackLink): ?>
                <a href="<?= $__trackLink ?>" target="_blank" class="text-xs px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition">📱 Customer Track</a>
                <?php endif; ?>
            </div>
            
            <?php elseif ($__isShipped): ?>
            <div class="text-xs text-gray-500">
                <span class="block mb-1">Courier: <b><?= e(!empty($order['courier_name']) ? $order['courier_name'] : (!empty($order['shipping_method']) ? $order['shipping_method'] : 'Unknown')) ?></b></span>
                <span class="text-gray-400">No consignment ID stored yet. Status will auto-update via webhook.</span>
            </div>
            
            <?php elseif ($__canUpload): ?>
            <p class="text-xs text-gray-500">Order ready to upload. Select delivery method above, then click the upload button.</p>
            
            <?php else: ?>
            <p class="text-xs text-gray-400">Courier tracking will appear here after upload.</p>
            <?php endif; ?>
        </div>

        <!-- ▸ Products: Ordered + Add -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Ordered Products -->
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-4 py-2.5 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <h4 class="text-sm font-bold text-gray-800">Ordered Products <span id="itemCount" class="bg-blue-500 text-white text-[10px] px-1.5 py-0.5 rounded-full ml-1"><?= count($items) ?></span></h4>
                </div>
                <div id="orderedItems" class="divide-y divide-gray-100 max-h-[450px] overflow-y-auto">
                    <?php foreach ($items as $idx => $item): ?>
                    <div class="p-3 item-row">
                        <input type="hidden" name="item_product_id[]" value="<?= $item['product_id'] ?>">
                        <input type="hidden" name="item_product_name[]" value="<?= e($item['product_name']) ?>">
                        <input type="hidden" name="item_variant_name[]" value="<?= e($item['variant_name']??'') ?>">
                        <div class="flex gap-3">
                            <div class="w-14 h-14 bg-gray-100 rounded-lg overflow-hidden shrink-0">
                                <?php if (!empty($item['featured_image'])): ?><img src="<?= imgSrc('products',$item['featured_image']) ?>" class="w-full h-full object-cover" onerror="this.parentElement.innerHTML='📦'">
                                <?php else: ?><div class="w-full h-full flex items-center justify-center text-xl">📦</div><?php endif; ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex justify-between gap-1">
                                    <div class="min-w-0">
                                        <div class="text-sm font-bold text-gray-800"><?= e($item['sku']??'') ?></div>
                                        <div class="text-xs text-gray-600 truncate"><?= e($item['product_name']) ?></div>
                                        <?php if ($item['variant_name']): ?><div class="text-[10px] text-indigo-600"><?= e($item['variant_name']) ?></div><?php endif; ?>
                                        <?php if (!empty($item['customer_upload'])): ?><a href="<?= SITE_URL ?>/uploads/customer-uploads/<?= e($item['customer_upload']) ?>" target="_blank" class="inline-block mt-0.5 px-1.5 py-0.5 bg-purple-50 text-purple-600 rounded text-[10px]"><i class="fas fa-paperclip mr-0.5"></i>Upload</a><?php endif; ?>
                                    </div>
                                    <button type="button" onclick="removeItem(this)" class="text-red-400 hover:text-red-600 shrink-0 p-0.5"><i class="fas fa-trash text-xs"></i></button>
                                </div>
                                <div class="text-[11px] text-gray-400 mt-0.5">৳<?= number_format($item['price']) ?>  Stock: <?= $item['stock_quantity']??'—' ?></div>
                                <div class="flex items-center gap-2 mt-2 text-xs flex-wrap">
                                    <span class="text-gray-500">Qty</span>
                                    <div class="flex items-center"><button type="button" onclick="changeQty(this,-1)" class="w-7 h-7 border border-gray-200 rounded-l text-gray-500 hover:bg-gray-50 font-bold">−</button><input type="number" name="item_qty[]" value="<?= $item['quantity'] ?>" min="1" class="w-10 h-7 border-t border-b border-gray-200 text-center text-sm item-qty" oninput="calcTotals()"><button type="button" onclick="changeQty(this,1)" class="w-7 h-7 border border-gray-200 rounded-r text-gray-500 hover:bg-gray-50 font-bold">+</button></div>
                                    <span class="text-gray-500 ml-1">Price</span>
                                    <input type="number" name="item_price[]" value="<?= $item['price'] ?>" min="0" step="1" class="w-20 h-7 border border-gray-200 rounded text-center text-sm item-price" oninput="calcTotals()">
                                    <span class="text-gray-500 ml-auto">Total</span>
                                    <span class="item-line-total font-bold"><?= number_format($item['subtotal'],2) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?><div id="noItemsMsg" class="p-8 text-center text-gray-400"><div class="text-2xl mb-1">📦</div><div class="text-sm">No products yet</div></div><?php endif; ?>
                </div>
            </div>

            <!-- Add Products -->
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-4 py-2.5 border-b border-gray-100 bg-gray-50">
                    <h4 class="text-sm font-bold text-gray-800">Click To Add Products</h4>
                </div>
                <div class="p-3">
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div><label class="block text-xs font-semibold text-gray-700 mb-1">Code/sku</label><input type="text" id="searchSku" placeholder="Type to Search.." class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" oninput="searchProducts()"></div>
                        <div><label class="block text-xs font-semibold text-gray-700 mb-1">Name</label><input type="text" id="searchName" placeholder="Type to Search.." class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" oninput="searchProducts()"></div>
                    </div>
                    <div id="productResults" class="divide-y divide-gray-100 max-h-[350px] overflow-y-auto border border-gray-200 rounded-md">
                        <div class="py-4 text-center text-gray-400 text-sm">Type to search products...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attribution text -->
        <?php if ($visitorLog): ?>
        <div class="text-[11px] text-gray-400 leading-relaxed">
            <?php if (!empty($visitorLog['device_type'])): ?>Wc order attribution device type: <?= e(ucfirst($visitorLog['device_type'])) ?><?php endif; ?>
            <?php if (!empty($visitorLog['referrer'])): ?> Wc order attribution referrer: <?= e(mb_strimwidth($visitorLog['referrer'],0,60,'...')) ?><?php endif; ?>
            <?php if (!empty($visitorLog['utm_source'])): ?><br>Wc order attribution session entry: <?= e($visitorLog['utm_source']) ?><?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ▸ Totals Card -->
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 items-end">
                <div><label class="block text-sm font-semibold text-gray-800 mb-1">Discount</label><input type="number" name="discount_amount" id="discountInput" value="<?= floatval($order['discount_amount']??0) ?>" min="0" step="1" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" oninput="calcTotals()"></div>
                <div><label class="block text-sm font-semibold text-gray-800 mb-1">Advance</label><input type="number" name="advance_amount" id="advanceInput" value="<?= floatval($order['advance_amount']??0) ?>" min="0" step="1" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" oninput="calcTotals()"></div>
                <div><label class="block text-sm font-semibold text-gray-800 mb-1">Sub Total</label><div id="subtotalDisplay" class="px-3 py-2 border border-gray-200 rounded-md text-sm bg-gray-50"><?= number_format($order['subtotal']) ?></div></div>
                <div><label class="block text-sm font-semibold text-gray-800 mb-1">DeliveryCharge</label><input type="number" name="shipping_cost" id="shippingInput" value="<?= floatval($order['shipping_cost']??0) ?>" min="0" step="1" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm" oninput="calcTotals()"></div>
                <div><label class="block text-sm font-semibold text-emerald-600 mb-1 italic">Grand Total</label><div id="grandTotalDisplay" class="px-3 py-2 border border-emerald-300 rounded-md text-sm bg-emerald-50 font-bold text-emerald-700"><?= number_format($order['total']) ?></div></div>
            </div>
        </div>

        <!-- ▸ Action Button -->
        <div class="pb-2">
            <?php if ($isPending): ?>
            <button type="submit" name="action" value="confirm_order" onclick="return validateConfirmOrder(event)" class="w-full bg-emerald-500 text-white py-3.5 rounded-xl text-base font-bold hover:bg-emerald-600 transition shadow">Create Order (<span id="confirmTotal"><?= number_format($order['total'],2) ?></span>)</button>
<script>
function validateConfirmOrder(e){
    const form = e.target.closest('form');
    const errs = [];
    const name = (form.querySelector('[name="customer_name"]')?.value||'').trim();
    const phone = (form.querySelector('[name="customer_phone"]')?.value||'').trim();
    const addr = (form.querySelector('[name="customer_address"]')?.value||'').trim();
    const items = form.querySelectorAll('[name="item_product_id[]"]');
    if(!name) errs.push('Customer name');
    if(!phone || phone.replace(/[^0-9]/g,'').length < 10) errs.push('Valid phone number');
    if(!addr) errs.push('Delivery address');
    if(!items.length) errs.push('At least one product');
    if(errs.length){
        e.preventDefault();
        const msg = '❌ Cannot confirm order. Missing:\n• ' + errs.join('\n• ');
        if(window._confirmAsync){ window._confirmAsync(msg,{title:'Validation Error',confirmText:'OK',showCancel:false}); }
        else{ alert(msg); }
        return false;
    }
    return true;
}
</script>
            <button type="submit" name="action" value="save_order" class="w-full mt-2 bg-gray-100 text-gray-600 py-2 rounded-lg text-sm font-medium hover:bg-gray-200 transition">💾 Save Without Confirming</button>
            <?php else: ?>
            <button type="submit" name="action" value="save_order" class="w-full bg-emerald-500 text-white py-3 rounded-xl text-base font-bold hover:bg-emerald-600 transition shadow">💾 Save Changes (৳<span id="saveTotal"><?= number_format($order['total']) ?></span>)</button>
            <?php endif; ?>
        </div>

    </div><!-- END LEFT -->

    <!-- ────────── RIGHT SIDEBAR ────────── -->
    <div class="w-full lg:w-[280px] xl:w-[300px] shrink-0 space-y-4">

        <!-- Order Summary -->
        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div class="px-4 py-2.5 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-800">Order Summary</span>
                <span class="text-[10px] text-gray-400">#<?= e($order['order_number']) ?></span>
            </div>
            <div class="p-4 text-xs space-y-1.5">
                <div class="flex justify-between"><span class="text-gray-500">Date</span><span><?= date('M d, Y, h:i A', strtotime($order['created_at'])) ?></span></div>
                <div class="flex justify-between items-center"><span class="text-gray-500">Status</span><span class="font-bold px-2 py-0.5 rounded text-[10px] <?= getOrderStatusBadge($order['order_status']) ?>"><?= strtoupper(getOrderStatusLabel($order['order_status'])) ?></span></div>
                <?php if (!empty($order['is_preorder'])): ?>
                <div class="flex justify-between items-center"><span class="text-gray-500">Type</span><span class="font-bold px-2 py-0.5 rounded text-[10px] bg-purple-100 text-purple-700"><i class="fas fa-clock mr-0.5"></i>PREORDER</span></div>
                <?php if (!empty($order['preorder_date'])): ?>
                <div class="flex justify-between items-center"><span class="text-gray-500">ETA</span><span class="text-purple-600 font-medium"><?= date('d M Y', strtotime($order['preorder_date'])) ?></span></div>
                <?php endif; ?>
                <?php endif; ?>
                <div class="flex justify-between"><span class="text-gray-500">Payment</span><span class="uppercase"><?= e($order['payment_method']) ?></span></div>
                <div class="flex justify-between items-center"><span class="text-gray-500">Source</span><span><?php $ch=$order['channel']??'website'; if($ch==='facebook') echo '<i class="fab fa-facebook text-blue-600 mr-0.5"></i>Facebook'; elseif($ch==='whatsapp') echo '<i class="fab fa-whatsapp text-green-600 mr-0.5"></i>WhatsApp'; else echo ucfirst($ch==='website'?'Web':$ch); ?></span></div>
                <div class="border-t border-gray-100 my-1.5"></div>
                <div class="flex justify-between"><span class="text-gray-500">Subtotal</span><span><?= number_format($order['subtotal']) ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Delivery</span><span><?= number_format($order['shipping_cost']) ?></span></div>
                <?php if (floatval($order['discount_amount']??0)>0): ?><div class="flex justify-between"><span class="text-gray-500">Discount</span><span class="text-red-600">-<?= number_format($order['discount_amount']) ?></span></div><?php endif; ?>
                <?php if (floatval($order['advance_amount']??0)>0): ?><div class="flex justify-between"><span class="text-gray-500">Advance</span><span class="text-blue-600"><?= number_format($order['advance_amount']) ?></span></div><?php endif; ?>
                <?php $scUsed=floatval($order['store_credit_used']??0); if($scUsed>0): ?><div class="flex justify-between text-yellow-600"><span><i class="fas fa-coins mr-0.5"></i>Credit</span><span>-৳<?= number_format($scUsed) ?></span></div><?php endif; ?>
                <div class="flex justify-between font-bold text-sm pt-1 border-t border-gray-100"><span>Total</span><span><?= number_format($order['total']) ?></span></div>
                <?php
                $creditRate = floatval(getSetting('store_credit_conversion_rate','0.75')?:0.75);
                $creditEarned = $db->fetch("SELECT amount FROM store_credit_transactions WHERE reference_type='order' AND reference_id=? AND type='earn'", [$order['id']]);
                if ($creditEarned): ?><div class="flex justify-between text-yellow-700 bg-yellow-50 rounded px-2 py-1 mt-1 text-[10px]"><span><i class="fas fa-coins mr-0.5"></i>Earned</span><span class="font-bold">+<?= number_format($creditEarned['amount']) ?> credits</span></div><?php endif; ?>
            </div>
        </div>

        <!-- IP / Mobile -->
        <div class="bg-white border border-gray-200 rounded-lg p-3 text-xs space-y-1.5">
            <div class="flex items-center justify-between"><span class="text-gray-500">IP: <?= e($order['ip_address']??'N/A') ?></span><?php if($order['ip_address']):?><span class="text-red-500 text-[10px] cursor-pointer">🔒 Block</span><?php endif;?></div>
            <div class="flex items-center justify-between"><span class="text-gray-500">Mobile: <?= e($order['customer_phone']) ?></span><span class="text-red-500 text-[10px] cursor-pointer">🔒 Block</span></div>
        </div>

        <!-- Order Items compact -->
        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div class="px-4 py-2 border-b border-gray-100 bg-gray-50 text-xs font-semibold text-gray-700">Order Items</div>
            <div class="p-3 space-y-2">
                <?php foreach ($items as $it): ?>
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 bg-gray-100 rounded overflow-hidden shrink-0"><?php if(!empty($it['featured_image'])):?><img src="<?= imgSrc('products',$it['featured_image']) ?>" class="w-full h-full object-cover"><?php else:?><div class="w-full h-full flex items-center justify-center text-[10px]">📦</div><?php endif;?></div>
                    <div class="flex-1 min-w-0"><div class="text-[10px] text-blue-600 font-medium truncate"><?= e($it['sku']??'') ?></div><div class="text-[10px] text-gray-500">৳<?= number_format($it['price']) ?></div></div>
                    <span class="text-[10px] text-gray-400 shrink-0"><?= $it['quantity'] ?>x</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Order Tags -->
        <div class="bg-white border border-gray-200 rounded-lg p-3">
            <div class="text-xs font-semibold text-gray-700 mb-2">Order Tags</div>
            <div class="flex flex-wrap gap-1 mb-1.5"><?php foreach($orderTags as $tg):?><span class="text-[10px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full"><?= e($tg['tag_name']) ?> <button type="button" onclick="removeTag('<?= e($tg['tag_name']) ?>')" class="text-gray-400 hover:text-red-500 ml-0.5">×</button></span><?php endforeach;?></div>
            <button type="button" onclick="addTagPrompt()" class="text-xs text-gray-500 hover:text-blue-600">+ Add Tag</button>
        </div>

        <!-- Order Actions -->
        <div class="bg-white border border-gray-200 rounded-lg p-3 space-y-2">
            <div class="text-xs font-semibold text-gray-700">Order Actions</div>
            <select id="statusSelect" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm">
                <?php
                // Define allowed transitions per status
                $allowedTransitions = [
                    'processing'       => ['processing','confirmed','cancelled','on_hold','no_response','good_but_no_response','advance_payment','pending_cancel'],
                    'pending'          => ['processing','confirmed','cancelled','on_hold','no_response','good_but_no_response','advance_payment','pending_cancel'],
                    'confirmed'        => ['confirmed','ready_to_ship','cancelled','on_hold','pending_cancel'],
                    'ready_to_ship'    => ['ready_to_ship','shipped','cancelled','on_hold','pending_cancel'],
                    'shipped'          => ['shipped','delivered','partial_delivered','cancelled','pending_return','on_hold','lost'],
                    'delivered'        => ['delivered','cancelled','pending_return'],
                    'partial_delivered'=> ['partial_delivered','delivered','cancelled','pending_return','on_hold'],
                    'pending_return'   => ['pending_return','returned','cancelled'],
                    'pending_cancel'   => ['pending_cancel','cancelled'],
                    'on_hold'          => ['on_hold','processing','confirmed','ready_to_ship','cancelled','pending_cancel'],
                    'no_response'      => ['no_response','processing','confirmed','cancelled','pending_cancel'],
                    'good_but_no_response' => ['good_but_no_response','processing','confirmed','cancelled','pending_cancel'],
                    'advance_payment'  => ['advance_payment','processing','confirmed','cancelled'],
                    'cancelled'        => ['cancelled'],
                    'returned'         => ['returned'],
                    'lost'             => ['lost','cancelled'],
                ];
                $currentStatus = $order['order_status'] === 'pending' ? 'processing' : $order['order_status'];
                $allowed = $allowedTransitions[$currentStatus] ?? ['processing','confirmed','ready_to_ship','shipped','delivered','pending_return','pending_cancel','partial_delivered','cancelled','returned','on_hold','no_response','good_but_no_response','advance_payment','lost'];
                foreach($allowed as $s): ?>
                <option value="<?= $s ?>" <?= ($order['order_status']===$s||($s==='processing'&&$order['order_status']==='pending'))?'selected':'' ?>><?= getOrderStatusLabel($s) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="flex items-center justify-between">
                <button type="button" onclick="updateStatus()" class="px-4 py-1.5 bg-emerald-500 text-white rounded-md text-xs font-semibold hover:bg-emerald-600 transition">Update</button>
                <a href="<?= adminUrl('pages/order-management.php') ?>" class="text-xs text-gray-500 hover:text-gray-700">← Back to List</a>
            </div>
        </div>

        <!-- Panel Note (internal only) — AJAX powered -->
        <div class="bg-white border border-gray-200 rounded-lg p-3">
            <div class="flex items-center gap-1.5 mb-1.5">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                <span class="text-xs font-semibold text-gray-700">Panel Note</span>
                <button type="button" onclick="showNoteTpl('panelNoteText','note_tpl_panel')" class="ml-1 w-5 h-5 rounded bg-green-100 text-green-500 hover:bg-green-200 flex items-center justify-center text-[10px] font-bold" title="Insert template">+</button>
                <span class="text-[9px] bg-green-50 text-green-600 px-1.5 py-0.5 rounded font-medium ml-auto">Internal Only</span>
            </div>
            <?php
            $panelNotes = $order['panel_notes'] ?? $order['admin_notes'] ?? '';
            $noteEntries = $panelNotes ? array_filter(explode("\n---\n", $panelNotes)) : [];
            ?>
            <div id="panelNoteHistory" class="max-h-[200px] overflow-y-auto mb-2 space-y-1.5 <?= empty($noteEntries) ? 'hidden' : '' ?>" style="scroll-behavior:smooth">
                <?php foreach (array_reverse($noteEntries) as $ne): ?>
                <div class="pn-entry text-[11px] text-gray-600 bg-green-50/60 rounded px-2.5 py-2 border-l-2 border-green-300 leading-relaxed"><?= nl2br(e(trim($ne))) ?></div>
                <?php endforeach; ?>
            </div>
            <textarea id="panelNoteText" rows="2" class="w-full px-3 py-2 border border-green-200 rounded-md text-sm resize-none mb-2 bg-green-50/30 focus:border-green-400 focus:ring-1 focus:ring-green-100" placeholder="Add internal note for team..." onkeydown="if(event.ctrlKey&&event.key==='Enter'){event.preventDefault();addPanelNote()}"></textarea>
            <div class="flex items-center justify-between">
                <button type="button" id="panelNoteBtn" onclick="addPanelNote()" class="px-3 py-1.5 bg-green-500 text-white rounded-md text-xs font-semibold hover:bg-green-600 transition"><i class="fas fa-plus mr-1"></i>Add Note</button>
                <button type="button" id="clearNotesBtn" onclick="clearPanelNotes()" class="text-[10px] text-red-400 hover:text-red-600 <?= empty($noteEntries) ? 'hidden' : '' ?>"><i class="fas fa-trash mr-0.5"></i>Clear</button>
            </div>
            <p class="text-[9px] text-green-500 mt-1"><i class="fas fa-eye-slash mr-0.5"></i> Not visible on invoice or courier. <span class="text-gray-400">Ctrl+Enter to send</span></p>
        </div>

        <!-- SMS -->
        <div class="flex gap-2">
            <button type="button" onclick="sendSMS('reminder')" class="flex-1 py-2 bg-white border border-gray-200 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 transition">Send Reminder SMS</button>
            <button type="button" onclick="sendSMS('advance')" class="flex-1 py-2 bg-white border border-gray-200 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 transition">Send Advance SMS</button>
        </div>

        <!-- Attribution -->
        <?php if ($visitorLog || !empty($order['channel'])): ?>
        <div class="bg-white border border-gray-200 rounded-lg p-3">
            <div class="text-xs font-semibold text-gray-700 mb-1">🔍 Attribution</div>
            <div class="text-[10px] text-gray-400 mb-2">Track where this order came from</div>
            <?php $src=ucfirst($order['channel']??'website'); $isPaid=!empty($visitorLog['utm_medium'])&&$visitorLog['utm_medium']==='paid'; ?>
            <div class="flex items-center gap-1.5 mb-2">
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium <?= ($order['channel']??'')==='facebook'?'bg-blue-100 text-blue-700':'bg-gray-100 text-gray-700' ?>"><?php if(($order['channel']??'')==='facebook'):?><i class="fab fa-facebook text-blue-600"></i><?php endif;?> <?= e($src) ?><?php if($isPaid):?> (paid)<?php endif;?></span>
                <?php if(!empty($visitorLog['utm_source'])):?><span class="text-[10px] text-gray-400">utm</span><?php endif;?>
            </div>
            <?php if(!empty($visitorLog['utm_campaign'])):?><div class="text-[10px] text-gray-500 mb-1">🎯 Campaign</div><div class="text-[10px] text-gray-400 pl-3 truncate"><?= e($visitorLog['utm_campaign']) ?></div><?php endif;?>
            <?php if($visitorLog):?>
            <div class="border-t border-gray-100 pt-2 mt-2 space-y-1">
                <div class="text-[10px] font-semibold text-gray-600">Session Info</div>
                <div class="text-[10px] text-gray-500"><i class="fas fa-<?= ($visitorLog['device_type']??'')==='Mobile'?'mobile-alt':'desktop' ?> text-gray-400 mr-1"></i><?= e(ucfirst($visitorLog['device_type']??'Unknown')) ?></div>
                <?php if(!empty($visitorLog['referrer'])):?><div class="text-[10px] text-gray-500"><i class="fas fa-external-link-alt text-gray-400 mr-1"></i><a href="<?= e($visitorLog['referrer']) ?>" target="_blank" class="text-blue-500 hover:underline"><?= e(mb_strimwidth($visitorLog['referrer'],0,35,'...')) ?></a></div><?php endif;?>
                <?php if(!empty($visitorLog['landing_page'])):?><div class="text-[9px] text-gray-400 break-all mt-1">Entry URL: <?= e(mb_strimwidth($visitorLog['landing_page'],0,60,'...')) ?></div><?php endif;?>
            </div>
            <?php endif;?>
        </div>
        <?php endif; ?>

        <!-- Activity Log -->
        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div class="px-4 py-2 border-b border-gray-100 bg-gray-50 text-xs font-semibold text-gray-700">Activity Log</div>
            <div class="p-3 max-h-[300px] overflow-y-auto">
                <?php
                $allAct = [];
                foreach ($history as $h) $allAct[] = ['time'=>$h['created_at'],'user'=>$h['changed_by_name']??'System','text'=>'Status → '.getOrderStatusLabel($h['status']).($h['note']?': '.$h['note']:'')];
                foreach ($activityLogs as $al) {
                    $t = match($al['action']){ 'update'=>'Order updated','confirm_order'=>'Order confirmed','mark_fake'=>'Marked as FAKE','update_status'=>$al['new_values']?:'Status changed','view_order'=>'ORDER_VIEWED','add_note'=>'Note: '.($al['new_values']?:''),'send_sms'=>'SMS sent', default=>$al['action'] };
                    $allAct[] = ['time'=>$al['created_at'],'user'=>$al['admin_name']??'System','text'=>$t];
                }
                usort($allAct, fn($a,$b)=>strtotime($b['time'])-strtotime($a['time']));
                $seen=[];
                $allAct = array_filter($allAct, function($a) use(&$seen){ $k=substr($a['time'],0,16).$a['text']; if(isset($seen[$k]))return false; $seen[$k]=true; return true; });
                if(empty($allAct)):?><div class="text-xs text-gray-400 text-center py-2">No activity yet</div>
                <?php else: foreach(array_slice($allAct,0,20) as $a):?>
                <div class="py-1.5 border-b border-dashed border-gray-100 last:border-0">
                    <div class="flex items-center gap-1"><span class="text-[10px] text-gray-400"><?= timeAgo($a['time']) ?></span><span class="text-[10px] bg-blue-50 text-blue-600 px-1 py-0.5 rounded font-medium"><?= e($a['user']) ?></span></div>
                    <div class="text-[11px] text-gray-600 mt-0.5"><?= e($a['text']) ?></div>
                </div>
                <?php endforeach; endif;?>
            </div>
        </div>

    </div><!-- END RIGHT -->

</div><!-- END FLEX -->
</form>

<!-- Hidden Forms -->
<form id="fakeForm" method="POST"><input type="hidden" name="action" value="mark_fake"></form>
<form id="statusForm" method="POST"><input type="hidden" name="action" value="update_status"><input type="hidden" name="status" id="statusVal"><input type="hidden" name="notes" id="statusNote"></form>

<style>@keyframes panelNoteIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}</style>

<script>
const PAPI='<?= SITE_URL ?>/api/pathao-api.php',SAPI='<?= SITE_URL ?>/api/product-search.php',PRODUCT_API='<?= SITE_URL ?>/api/product-search.php';
let searchTimer=null;

let _lastFetchedPhone='';
function fetchCourierData(forcePhone){
    let phone=forcePhone||'';
    if(!phone){const el=document.getElementById('customerPhoneInput');phone=el?el.value:'';}
    phone=phone.replace(/\D/g,'');
    if(phone.startsWith('88')&&phone.length>11) phone=phone.substring(2);
    if(phone.length===10&&phone[0]!=='0') phone='0'+phone;
    if(phone.length<11||!/^01[3-9]/.test(phone)){return;}
    if(phone===_lastFetchedPhone&&!forcePhone) return;
    _lastFetchedPhone=phone;
    const btn=document.getElementById('fillInfoBtn');
    btn.textContent='Fetching...';btn.disabled=true;btn.className='w-full mt-2 py-1.5 bg-yellow-50 text-yellow-700 rounded-md text-xs font-semibold';
    fetch('<?= adminUrl("api/courier-lookup.php") ?>?phone='+phone).then(r=>r.json()).then(d=>{
        if(d.error){btn.textContent='Error';btn.className='w-full mt-2 py-1.5 bg-red-50 text-red-600 rounded-md text-xs font-semibold';return;}
        updCard('overall',d.overall);
        ['Pathao','RedX','Steadfast'].forEach(n=>{if(d.couriers?.[n])updCard(n.toLowerCase(),d.couriers[n]);});
        const orc=document.getElementById('card-ourrecord'),or=d.our_record;
        if(orc&&or){
            const ct=orc.querySelector('[data-custtype]');
            if(ct){if(or.is_new){ct.textContent='New Customer';}else{ct.remove();}}
            const ot=orc.querySelector('[data-ourtotal]');if(ot)ot.textContent='Total: '+(or.total||or.total_orders||0);
            const oc=orc.querySelector('[data-ourcancelled]');if(oc)oc.textContent='Cancelled: '+(or.cancelled||0);
            const wc=orc.querySelector('[data-webcancel]');if(wc)wc.textContent='Web Order Cancel: '+(or.web_cancels||0);
            const ts=orc.querySelector('[data-totalspent]');if(ts)ts.textContent='Total Spent: ৳'+Number(or.total_spent||0).toLocaleString();
        }
        btn.textContent='Updated';btn.className='w-full mt-2 py-1.5 bg-green-50 text-green-700 rounded-md text-xs font-semibold';
    }).catch(()=>{btn.textContent='Error';btn.className='w-full mt-2 py-1.5 bg-red-50 text-red-600 rounded-md text-xs font-semibold';});
}
// Auto-fetch when phone input reaches 11 valid digits
(function(){
    let _phoneTimer=null;
    const el=document.getElementById('customerPhoneInput');
    if(!el) return;
    el.addEventListener('input',function(){
        clearTimeout(_phoneTimer);
        _phoneTimer=setTimeout(()=>{
            let p=el.value.replace(/\D/g,'');
            if(p.startsWith('88')&&p.length>11) p=p.substring(2);
            if(p.length===10&&p[0]!=='0') p='0'+p;
            if(p.length===11&&/^01[3-9]/.test(p)) fetchCourierData(p);
        },400);
    });
})();
function updCard(id,data){
    const c=document.getElementById('card-'+id);if(!c||!data)return;
    const rate=data.rate,rc=rate>=70?'#16a34a':rate>=40?'#ca8a04':'#dc2626';
    const bc=rate>=70?'#22c55e':rate>=40?'#eab308':'#ef4444';
    const re=c.querySelector('[data-rate]');re.textContent='Success Rate: '+rate+'%';re.style.color=rc;
    c.querySelector('[data-total]').textContent='Total: '+data.total;
    c.querySelector('[data-success]').textContent='Success: '+data.success;
    c.querySelector('[data-cancelled]').textContent='Cancelled: '+data.cancelled;
    const bar=c.querySelector('[data-bar]');bar.style.width=Math.min(100,rate)+'%';bar.style.background=bc;
    var badges='';
    if(data.api_checked>0) badges+=' <span style="color:#22c55e;font-size:9px">✓API</span>';
    if(data.data_source==='our_db') badges+=' <span style="color:#f59e0b;font-size:9px">DB</span>';
    if(data.customer_rating!==undefined&&data.customer_rating!==null) badges+=' <span style="background:#f3e8ff;color:#7c3aed;font-size:9px;padding:0 4px;border-radius:4px;margin-left:2px">⭐'+data.customer_rating+'</span>';
    re.innerHTML='Success Rate: '+rate+'%'+badges;
    re.style.color=rc;
}
<?php if(!empty($order['customer_phone'])):?>
document.addEventListener('DOMContentLoaded',()=>setTimeout(fetchCourierData,500));
<?php endif;?>

function searchProducts(){
    clearTimeout(searchTimer);
    const q=document.getElementById('searchSku').value.trim()||document.getElementById('searchName').value.trim();
    if(q.length<2){document.getElementById('productResults').innerHTML='<div class="py-4 text-center text-gray-400 text-sm">Type to search...</div>';return;}
    searchTimer=setTimeout(async()=>{
        try{
            const r=await(await fetch(SAPI+'?q='+encodeURIComponent(q))).json();
            if(!r.success||!r.results?.length){document.getElementById('productResults').innerHTML='<div class="py-3 text-center text-gray-400 text-sm">No products found</div>';return;}
            let h='';
            r.results.forEach(p=>{
                const hasVar = p.has_variants || p.variant_count > 0;
                const varBadge = hasVar ? '<span style="background:#dbeafe;color:#1d4ed8;font-size:9px;padding:1px 5px;border-radius:8px;margin-left:4px">+Variants</span>' : '';
                const clickFn = hasVar 
                    ? `showVariantPicker(${p.id},'${esc(p.name)}',${p.price},'${esc(p.image)}','${esc(p.sku||'')}')`
                    : `addProduct(${p.id},'${esc(p.name)}',${p.price},'${esc(p.image)}','${esc(p.sku||'')}','')`;
                h+=`<div class="flex items-center gap-3 p-2.5 hover:bg-blue-50 cursor-pointer transition" onclick="${clickFn}">
                    <img src="${p.image}" class="w-12 h-12 rounded object-cover border border-gray-200" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22><text y=%22.9em%22 font-size=%2230%22>📦</text></svg>'">
                    <div class="flex-1 min-w-0"><div class="text-sm font-medium text-gray-800 truncate">${esc(p.name)}${varBadge}</div><div class="text-[10px] text-blue-600 font-bold">${p.sku?'SKU: '+esc(p.sku):''}</div><div class="text-[10px] text-gray-500">Price: ৳${p.price.toLocaleString()} · Stock: ${p.stock_quantity??0}</div></div>
                    <span class="text-yellow-400 text-lg shrink-0">${hasVar?'⚡':'★'}</span></div>`;
            });
            document.getElementById('productResults').innerHTML=h;
        }catch(e){console.error('Search error:',e);}
    },300);
}

// Show variant picker modal for products with variants
async function showVariantPicker(productId, name, basePrice, image, sku) {
    // Create modal if not exists
    let m = document.getElementById('variantPickerModal');
    if (!m) {
        m = document.createElement('div');
        m.id = 'variantPickerModal';
        m.innerHTML = `
            <div style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)closeVariantPicker()">
                <div style="background:#fff;border-radius:16px;max-width:500px;width:100%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,.25)" onclick="event.stopPropagation()">
                    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
                        <div style="display:flex;align-items:center;gap:12px">
                            <img id="vpImage" src="" style="width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb">
                            <div>
                                <div id="vpName" style="font-size:14px;font-weight:700;color:#111827"></div>
                                <div id="vpSku" style="font-size:11px;color:#6b7280"></div>
                            </div>
                        </div>
                        <button onclick="closeVariantPicker()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af;line-height:1">&times;</button>
                    </div>
                    <div id="vpContent" style="flex:1;overflow-y:auto;padding:16px 20px">
                        <div style="text-align:center;padding:30px;color:#9ca3af">Loading...</div>
                    </div>
                    <div style="padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;background:#f9fafb">
                        <div>
                            <span style="font-size:11px;color:#6b7280">Total Price:</span>
                            <span id="vpTotalPrice" style="font-size:16px;font-weight:700;color:#059669;margin-left:6px">৳0</span>
                        </div>
                        <button id="vpAddBtn" onclick="addFromVariantPicker()" style="padding:10px 24px;border-radius:8px;border:none;background:#3b82f6;color:#fff;font-size:13px;font-weight:600;cursor:pointer">Add to Order</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(m);
    }
    
    m.style.display = 'block';
    document.getElementById('vpImage').src = image || 'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22><text y=%22.9em%22 font-size=%2230%22>📦</text></svg>';
    document.getElementById('vpName').textContent = name;
    document.getElementById('vpSku').textContent = sku ? 'SKU: ' + sku : '';
    document.getElementById('vpContent').innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af"><div style="font-size:24px;margin-bottom:8px">⏳</div>Loading variants...</div>';
    document.getElementById('vpTotalPrice').textContent = '৳' + basePrice.toLocaleString();
    
    // Store product data
    window._vpProduct = { id: productId, name: name, basePrice: basePrice, image: image, sku: sku };
    window._vpSelectedVariations = {};
    window._vpSelectedAddons = [];
    
    // Fetch variants
    try {
        const resp = await fetch(PRODUCT_API + '?product_id=' + productId);
        const data = await resp.json();
        
        if (!data.success) {
            document.getElementById('vpContent').innerHTML = '<div style="text-align:center;padding:30px;color:#dc2626">Error loading variants</div>';
            return;
        }
        
        let html = '';
        
        // Variations
        if (data.variations && Object.keys(data.variations).length > 0) {
            html += '<div style="margin-bottom:16px">';
            html += '<div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:8px">📦 Select Variation</div>';
            
            for (const [varName, options] of Object.entries(data.variations)) {
                html += '<div style="margin-bottom:12px">';
                html += '<label style="font-size:11px;color:#6b7280;display:block;margin-bottom:4px">' + esc(varName) + '</label>';
                html += '<div style="display:flex;flex-wrap:wrap;gap:6px">';
                
                options.forEach((opt, idx) => {
                    const priceText = opt.absolute_price ? '৳' + Number(opt.absolute_price).toLocaleString() 
                        : (opt.price_adjustment > 0 ? '+৳' + opt.price_adjustment : (opt.price_adjustment < 0 ? '-৳' + Math.abs(opt.price_adjustment) : ''));
                    const isDefault = opt.is_default ? 'border-color:#3b82f6;background:#eff6ff' : '';
                    html += `<button type="button" onclick="selectVariation('${esc(varName)}',${idx})" 
                        id="var_${esc(varName)}_${idx}"
                        style="padding:6px 12px;border:2px solid #e5e7eb;border-radius:8px;font-size:12px;cursor:pointer;background:#fff;transition:all .15s;${isDefault}"
                        data-var-name="${esc(varName)}" data-var-value="${esc(opt.value)}" data-price="${opt.absolute_price || opt.price_adjustment}" data-is-absolute="${opt.absolute_price ? 1 : 0}">
                        ${esc(opt.value)} ${priceText ? '<span style="color:#6b7280;font-size:10px">(' + priceText + ')</span>' : ''}
                    </button>`;
                });
                
                html += '</div></div>';
            }
            html += '</div>';
            
            // Store variations data
            window._vpVariationsData = data.variations;
        }
        
        // Addons
        if (data.addons && data.addons.length > 0) {
            html += '<div>';
            html += '<div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:8px">➕ Add-ons (optional)</div>';
            html += '<div style="display:flex;flex-direction:column;gap:6px">';
            
            data.addons.forEach((addon, idx) => {
                const priceText = addon.absolute_price ? '৳' + Number(addon.absolute_price).toLocaleString() 
                    : (addon.price_adjustment > 0 ? '+৳' + addon.price_adjustment : '');
                html += `<label style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;transition:all .15s" 
                    id="addon_label_${idx}" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">
                    <input type="checkbox" id="addon_${idx}" onchange="toggleAddon(${idx})" 
                        data-addon-name="${esc(addon.name)}" data-addon-value="${esc(addon.value)}" data-price="${addon.absolute_price || addon.price_adjustment}" data-is-absolute="${addon.absolute_price ? 1 : 0}"
                        style="width:16px;height:16px;accent-color:#3b82f6">
                    <span style="flex:1;font-size:12px;color:#374151">${esc(addon.name)}: ${esc(addon.value)}</span>
                    <span style="font-size:11px;color:#059669;font-weight:600">${priceText}</span>
                </label>`;
            });
            
            html += '</div></div>';
            
            window._vpAddonsData = data.addons;
        }
        
        if (!html) {
            html = '<div style="text-align:center;padding:30px;color:#6b7280">No variants available. Click Add to add base product.</div>';
        }
        
        document.getElementById('vpContent').innerHTML = html;
        
        // Auto-select defaults
        if (data.variations) {
            for (const [varName, options] of Object.entries(data.variations)) {
                const defaultIdx = options.findIndex(o => o.is_default);
                if (defaultIdx >= 0) {
                    selectVariation(varName, defaultIdx);
                }
            }
        }
        
        updateVariantPrice();
        
    } catch (e) {
        console.error('Variant fetch error:', e);
        document.getElementById('vpContent').innerHTML = '<div style="text-align:center;padding:30px;color:#dc2626">Error: ' + e.message + '</div>';
    }
}

function selectVariation(varName, idx) {
    // Deselect all in this group
    document.querySelectorAll(`[data-var-name="${varName}"]`).forEach(btn => {
        btn.style.borderColor = '#e5e7eb';
        btn.style.background = '#fff';
    });
    
    // Select this one
    const btn = document.getElementById('var_' + varName + '_' + idx);
    if (btn) {
        btn.style.borderColor = '#3b82f6';
        btn.style.background = '#eff6ff';
        window._vpSelectedVariations[varName] = {
            value: btn.dataset.varValue,
            price: parseFloat(btn.dataset.price) || 0,
            isAbsolute: btn.dataset.isAbsolute === '1'
        };
    }
    
    updateVariantPrice();
}

function toggleAddon(idx) {
    const cb = document.getElementById('addon_' + idx);
    const label = document.getElementById('addon_label_' + idx);
    
    if (cb.checked) {
        label.style.borderColor = '#3b82f6';
        label.style.background = '#eff6ff';
        window._vpSelectedAddons.push({
            name: cb.dataset.addonName,
            value: cb.dataset.addonValue,
            price: parseFloat(cb.dataset.price) || 0,
            isAbsolute: cb.dataset.isAbsolute === '1'
        });
    } else {
        label.style.borderColor = '#e5e7eb';
        label.style.background = '#fff';
        window._vpSelectedAddons = window._vpSelectedAddons.filter(a => !(a.name === cb.dataset.addonName && a.value === cb.dataset.addonValue));
    }
    
    updateVariantPrice();
}

function updateVariantPrice() {
    let price = window._vpProduct.basePrice;
    let hasAbsolute = false;
    
    // Check variations
    for (const sel of Object.values(window._vpSelectedVariations)) {
        if (sel.isAbsolute) {
            price = sel.price;
            hasAbsolute = true;
            break; // Use absolute price from variation
        } else {
            price += sel.price;
        }
    }
    
    // Add addon prices
    for (const addon of window._vpSelectedAddons) {
        if (addon.isAbsolute) {
            price += addon.price;
        } else {
            price += addon.price;
        }
    }
    
    document.getElementById('vpTotalPrice').textContent = '৳' + price.toLocaleString();
    window._vpFinalPrice = price;
}

function addFromVariantPicker() {
    const p = window._vpProduct;
    
    // Build variant string
    let variantParts = [];
    for (const [name, sel] of Object.entries(window._vpSelectedVariations)) {
        variantParts.push(name + ': ' + sel.value);
    }
    for (const addon of window._vpSelectedAddons) {
        variantParts.push(addon.name + ': ' + addon.value);
    }
    
    const variantStr = variantParts.join(', ');
    const finalPrice = window._vpFinalPrice || p.basePrice;
    
    addProduct(p.id, p.name, finalPrice, p.image, p.sku, variantStr);
    closeVariantPicker();
}

function closeVariantPicker() {
    const m = document.getElementById('variantPickerModal');
    if (m) m.style.display = 'none';
}

function addProduct(id,name,price,image,sku,variant){
    const c=document.getElementById('orderedItems'),n=document.getElementById('noItemsMsg');if(n)n.remove();
    const variantDisplay = variant ? '<div class="text-[10px] text-indigo-600 mt-0.5">' + esc(variant) + '</div>' : '';
    const d=document.createElement('div');d.className='p-3 item-row border-t border-gray-100';
    d.innerHTML=`<input type="hidden" name="item_product_id[]" value="${id}"><input type="hidden" name="item_product_name[]" value="${esc(name)}"><input type="hidden" name="item_variant_name[]" value="${esc(variant||'')}">
        <div class="flex gap-3"><div class="w-14 h-14 bg-gray-100 rounded-lg overflow-hidden shrink-0"><img src="${image}" class="w-full h-full object-cover" onerror="this.parentElement.innerHTML='📦'"></div>
        <div class="flex-1 min-w-0"><div class="flex justify-between gap-1"><div class="min-w-0"><div class="text-sm font-bold text-gray-800">${sku?esc(sku):''}</div><div class="text-xs text-gray-600 truncate">${esc(name)}</div>${variantDisplay}</div><button type="button" onclick="removeItem(this)" class="text-red-400 hover:text-red-600 shrink-0 p-0.5"><i class="fas fa-trash text-xs"></i></button></div>
        <div class="text-[11px] text-gray-400 mt-0.5">৳${price.toLocaleString()}</div>
        <div class="flex items-center gap-2 mt-2 text-xs flex-wrap"><span class="text-gray-500">Qty</span><div class="flex items-center"><button type="button" onclick="changeQty(this,-1)" class="w-7 h-7 border border-gray-200 rounded-l text-gray-500 hover:bg-gray-50 font-bold">−</button><input type="number" name="item_qty[]" value="1" min="1" class="w-10 h-7 border-t border-b border-gray-200 text-center text-sm item-qty" oninput="calcTotals()"><button type="button" onclick="changeQty(this,1)" class="w-7 h-7 border border-gray-200 rounded-r text-gray-500 hover:bg-gray-50 font-bold">+</button></div><span class="text-gray-500 ml-1">Price</span><input type="number" name="item_price[]" value="${price}" min="0" step="1" class="w-20 h-7 border border-gray-200 rounded text-center text-sm item-price" oninput="calcTotals()"><span class="text-gray-500 ml-auto">Total</span><span class="item-line-total font-bold">${price.toFixed(2)}</span></div></div></div>`;
    c.appendChild(d);calcTotals();
}
function removeItem(b){b.closest('.item-row').remove();calcTotals();}
function changeQty(b,d){const i=b.closest('.item-row').querySelector('.item-qty');i.value=Math.max(1,parseInt(i.value||1)+d);calcTotals();}
function calcTotals(){
    let sub=0,cnt=0;
    document.querySelectorAll('.item-row').forEach(r=>{
        const q=parseInt(r.querySelector('.item-qty')?.value||1),p=parseFloat(r.querySelector('.item-price')?.value||0),l=q*p;
        const lt=r.querySelector('.item-line-total');if(lt)lt.textContent=l.toFixed(2);sub+=l;cnt++;
    });
    const disc=parseFloat(document.getElementById('discountInput').value||0),adv=parseFloat(document.getElementById('advanceInput').value||0),ship=parseFloat(document.getElementById('shippingInput').value||0),grand=Math.max(0,sub+ship-disc-adv);
    document.getElementById('subtotalDisplay').textContent=sub.toLocaleString();
    document.getElementById('grandTotalDisplay').textContent=grand.toLocaleString();
    document.getElementById('itemCount').textContent=cnt;
    const ct=document.getElementById('confirmTotal');if(ct)ct.textContent=grand.toFixed(2);
    const st=document.getElementById('saveTotal');if(st)st.textContent=grand.toLocaleString();
}

async function updateStatus(){
    const s = document.getElementById('statusSelect').value;
    const n = await window._promptAsync('Note (optional):', '', 'Order Status Update') || '';
    document.getElementById('statusVal').value  = s;
    document.getElementById('statusNote').value = n;
    document.getElementById('statusForm').submit();
}

// ── Panel Note AJAX ──
function addPanelNote(){
    const ta=document.getElementById('panelNoteText');
    const text=ta.value.trim();
    if(!text)return;
    const btn=document.getElementById('panelNoteBtn');
    btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin mr-1"></i>Saving...';
    const fd=new FormData();
    fd.append('action','add_note');
    fd.append('note_text',text);
    fd.append('ajax','1');
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(data=>{
        if(data.ok){
            const hist=document.getElementById('panelNoteHistory');
            hist.classList.remove('hidden');
            const el=document.createElement('div');
            el.className='pn-entry text-[11px] text-gray-600 bg-green-50/60 rounded px-2.5 py-2 border-l-2 border-green-300 leading-relaxed';
            el.style.animation='panelNoteIn .3s ease';
            el.innerHTML=escHtml(data.entry).replace(/\n/g,'<br>');
            hist.insertBefore(el,hist.firstChild);
            hist.scrollTop=0;
            ta.value='';
            ta.focus();
            document.getElementById('clearNotesBtn').classList.remove('hidden');
            // brief flash
            el.style.background='#bbf7d0';setTimeout(()=>{el.style.background='';el.style.transition='background .5s'},600);
        } else { alert('Failed to save note'); }
    }).catch(()=>alert('Network error'))
    .finally(()=>{btn.disabled=false;btn.innerHTML='<i class="fas fa-plus mr-1"></i>Add Note';});
}
async function clearPanelNotes(){
    const ok = await window._confirmAsync('Clear all panel notes?');
    if(!ok)return;
    const fd=new FormData();
    fd.append('action','clear_panel_notes');
    fd.append('ajax','1');
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(data=>{
        if(data.ok){
            const hist=document.getElementById('panelNoteHistory');
            hist.innerHTML='';hist.classList.add('hidden');
            document.getElementById('clearNotesBtn').classList.add('hidden');
        }
    }).catch(()=>alert('Network error'));
}
function escHtml(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
async function sendSMS(type){const ph='<?=e($order['customer_phone'])?>';const ok=await window._confirmAsync('Send '+type+' SMS to '+ph+'?');if(!ok)return;fetch('<?=adminUrl("api/actions.php")?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=send_sms&type='+type+'&order_id=<?=$id?>&phone='+encodeURIComponent(ph)}).then(r=>r.json()).then(d=>{alert(d.success?'SMS sent!':(d.error||'Failed'));if(d.success)location.reload();}).catch(e=>alert(e.message));}
async function addTagPrompt(){
    const t = await window._promptAsync('Tag name:', '', 'Add Tag');
    if (!t) return;
    fetch('<?=adminUrl("api/actions.php")?>', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=add_tag&order_id=<?=$id?>&tag='+encodeURIComponent(t)
    }).then(() => location.reload());
}
function removeTag(t){fetch('<?=adminUrl("api/actions.php")?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=remove_tag&order_id=<?=$id?>&tag='+encodeURIComponent(t)}).then(()=>location.reload());}

async function loadPathaCities(){try{const r=await fetch(PAPI+'?action=get_cities');if(!r.ok)return;const j=await r.json();(j.data?.data||j.data||[]).forEach(c=>{const o=document.createElement('option');o.value=c.city_id;o.textContent=c.city_name;document.getElementById('pCityId').appendChild(o);});}catch(e){}}
async function loadZones(cid){const s=document.getElementById('pZoneId');s.innerHTML='<option>Loading...</option>';s.disabled=true;document.getElementById('pAreaId').innerHTML='<option>Select Area</option>';document.getElementById('pAreaId').disabled=true;if(!cid)return;try{const r=await fetch(PAPI+'?action=get_zones&city_id='+cid);if(!r.ok){s.innerHTML='<option value="">Select Zone</option>';s.disabled=false;return;}const j=await r.json();s.innerHTML='<option value="">Select Zone</option>';(j.data?.data||j.data||[]).forEach(z=>{const o=document.createElement('option');o.value=z.zone_id;o.textContent=z.zone_name;s.appendChild(o);});s.disabled=false;}catch(e){s.innerHTML='<option value="">Select Zone</option>';s.disabled=false;}}
async function loadAreas(zid){const s=document.getElementById('pAreaId');s.innerHTML='<option>Loading...</option>';s.disabled=true;if(!zid)return;try{const r=await fetch(PAPI+'?action=get_areas&zone_id='+zid);if(!r.ok){s.innerHTML='<option value="">Select Area</option>';s.disabled=false;return;}const j=await r.json();s.innerHTML='<option value="">Select Area</option>';(j.data?.data||j.data||[]).forEach(a=>{const o=document.createElement('option');o.value=a.area_id;o.textContent=a.area_name;s.appendChild(o);});s.disabled=false;}catch(e){s.innerHTML='<option value="">Select Area</option>';s.disabled=false;}}
function saveOrderLocation(){
    var cs=document.getElementById('pCityId'), zs=document.getElementById('pZoneId'), as2=document.getElementById('pAreaId');
    var cid=cs?.value||0, zid=zs?.value||0, aid=as2?.value||0;
    var cname=cs?.selectedOptions[0]?.textContent||'', zname=zs?.selectedOptions[0]?.textContent||'', aname=as2?.selectedOptions[0]?.textContent||'';
    if(cname==='Select City')cname=''; if(zname==='Select Zone'||zname==='Loading...')zname=''; if(aname==='Select Area'||aname==='Loading...')aname='';
    if(!cid&&!zid&&!aid)return;
    // Update area path label
    updateAreaPathLabel(cname.trim(), zname.trim(), aname.trim());
    // Save to server
    fetch(PAPI,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_order_location',order_id:<?= intval($order['id']) ?>,city_id:cid,zone_id:zid,area_id:aid,city_name:cname.trim(),zone_name:zname.trim(),area_name:aname.trim()})})
    .then(function(r){return r.json()}).then(function(d){
        if(d.success){var lbl=document.getElementById('areaPathLabel');if(lbl&&!lbl.classList.contains('hidden')){var sp=document.getElementById('areaPathText');if(sp)sp.innerHTML=sp.innerHTML.replace('(saving...)','(saved ✓)');}}
    }).catch(function(){});
}
function updateAreaPathLabel(city, zone, area){
    var lbl=document.getElementById('areaPathLabel'), sp=document.getElementById('areaPathText');
    if(!lbl||!sp)return;
    if(!city&&!zone&&!area){lbl.classList.add('hidden');return;}
    var path='✅ '+city;
    if(zone)path+=' → '+zone;
    if(area)path+=' → '+area;
    path+=' <span class="text-green-400 text-[10px] ml-1">(saving...)</span>';
    sp.innerHTML=path;
    lbl.classList.remove('hidden');
}
function clearAreaSelection(){
    document.getElementById('pCityId').value='';
    document.getElementById('pZoneId').innerHTML='<option>Select Zone</option>';
    document.getElementById('pZoneId').disabled=true;
    document.getElementById('pAreaId').innerHTML='<option>Select Area</option>';
    document.getElementById('pAreaId').disabled=true;
    var lbl=document.getElementById('areaPathLabel');if(lbl)lbl.classList.add('hidden');
    // Clear on server too
    fetch(PAPI,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_order_location',order_id:<?= intval($order['id']) ?>,city_id:0,zone_id:0,area_id:0,city_name:'',zone_name:'',area_name:''})}).catch(function(){});
}
loadPathaCities();
<?php
// Pre-select stored area values
$storedCity = intval($order['pathao_city_id'] ?? 0);
$storedZone = intval($order['pathao_zone_id'] ?? 0);
$storedArea = intval($order['pathao_area_id'] ?? 0);
if ($storedCity): ?>
setTimeout(async function(){
    var cs=document.getElementById('pCityId');
    if(cs){cs.value='<?= $storedCity ?>';if(cs.value==='<?= $storedCity ?>'){await loadZones('<?= $storedCity ?>');
    <?php if ($storedZone): ?>var zs=document.getElementById('pZoneId');if(zs){zs.value='<?= $storedZone ?>';await loadAreas('<?= $storedZone ?>');
    <?php if ($storedArea): ?>var as2=document.getElementById('pAreaId');if(as2)as2.value='<?= $storedArea ?>';<?php endif; ?>}<?php endif; ?>
    }}
}, 600);
<?php else: ?>
// No city saved — auto-detect from address on page load
setTimeout(function(){ autoDetectLocation(true); }, 800);
<?php endif; ?>

async function autoDetectLocation(silent){
    const addrEl=document.querySelector('textarea[name="customer_address"]');
    const res=document.getElementById('autoDetectResult');
    if(!addrEl||!addrEl.value.trim()){
        if(!silent){res.classList.remove('hidden');res.className='mt-2 text-xs bg-orange-50 text-orange-700 p-2 rounded';res.textContent='⚠ Address is empty.';}
        return;
    }

    if(!silent){res.classList.remove('hidden');res.className='mt-2 text-xs bg-yellow-50 text-yellow-700 p-2 rounded';res.textContent='🔍 Detecting...';}

    // ─── Utility: normalize text for matching ───
    // Strips ALL punctuation, normalizes whitespace, lowercases
    function norm(s){return (s||'').toLowerCase().replace(/[''`]/g,'').replace(/[^a-z0-9\u0980-\u09FF\s]/g,' ').replace(/\s+/g,' ').trim();}

    // ─── Bangla → English dictionary (districts + areas) ───
    const bnMap={
        'ঢাকা':'dhaka','চট্টগ্রাম':'chittagong','চিটাগাং':'chittagong','সিলেট':'sylhet','রাজশাহী':'rajshahi',
        'খুলনা':'khulna','বরিশাল':'barishal','বরিশাল':'barisal','রংপুর':'rangpur','ময়মনসিংহ':'mymensingh',
        'কুমিল্লা':'cumilla','কুষ্টিয়া':'kushtia','যশোর':'jessore','জামালপুর':'jamalpur','টাঙ্গাইল':'tangail',
        'গাজীপুর':'gazipur','নারায়ণগঞ্জ':'narayanganj','ফরিদপুর':'faridpur','পাবনা':'pabna','নোয়াখালী':'noakhali',
        'বগুড়া':'bogura','দিনাজপুর':'dinajpur','চাঁদপুর':'chandpur','মানিকগঞ্জ':'manikganj','মুন্সীগঞ্জ':'munshiganj',
        'নরসিংদী':'narsingdi','কিশোরগঞ্জ':'kishoreganj','শেরপুর':'sherpur','নেত্রকোনা':'netrokona',
        'সাতক্ষীরা':'satkhira','নড়াইল':'narail','মাগুরা':'magura','মেহেরপুর':'meherpur','চুয়াডাঙ্গা':'chuadanga',
        'ঝিনাইদহ':'jhenaidah','বাগেরহাট':'bagerhat','পিরোজপুর':'pirojpur','ঝালকাঠি':'jhalokati',
        'ভোলা':'bhola','পটুয়াখালী':'patuakhali','বরগুনা':'barguna','লক্ষ্মীপুর':'lakshmipur',
        'ফেনী':'feni','ব্রাহ্মণবাড়িয়া':'brahmanbaria','হবিগঞ্জ':'habiganj','মৌলভীবাজার':'moulvibazar',
        'সুনামগঞ্জ':'sunamganj','নওগাঁ':'naogaon','নাটোর':'natore','চাঁপাইনবাবগঞ্জ':'chapainawabganj',
        'সিরাজগঞ্জ':'sirajganj','জয়পুরহাট':'joypurhat','গাইবান্ধা':'gaibandha','কুড়িগ্রাম':'kurigram',
        'লালমনিরহাট':'lalmonirhat','নীলফামারী':'nilphamari','ঠাকুরগাঁও':'thakurgaon','পঞ্চগড়':'panchagarh',
        'শরীয়তপুর':'shariatpur','মাদারীপুর':'madaripur','গোপালগঞ্জ':'gopalganj','রাজবাড়ী':'rajbari',
        'কক্সবাজার':'coxs bazar','রাঙামাটি':'rangamati','বান্দরবান':'bandarban','খাগড়াছড়ি':'khagrachhari',
        'সদর':'sadar','উপজেলা':'upazila','জেলা':'district','থানা':'thana'
    };

    // ─── English alias map: common misspellings / variations → canonical name ───
    const aliasMap={
        'coxs bazar':'coxs bazar','coxsbazar':'coxs bazar','cox bazar':'coxs bazar','koxbazar':'coxs bazar',
        'coxbazar':'coxs bazar','koksbazar':'coxs bazar','coxs':'coxs bazar',
        'chattagram':'chittagong','ctg':'chittagong','chottogram':'chittagong','chattogram':'chittagong',
        'comilla':'cumilla','kumilla':'cumilla',
        'bogra':'bogura','bograa':'bogura',
        'jessore':'jashore','joshor':'jashore','jashor':'jashore',
        'barisal':'barishal','borishal':'barishal',
        'mymensing':'mymensingh','mymenshingh':'mymensingh','maimansingh':'mymensingh',
        'noakhali':'noakhali','noakhilla':'noakhali',
        'sylhett':'sylhet','silhet':'sylhet','shilet':'sylhet',
        'narshingdi':'narsingdi','narsindi':'narsingdi',
        'naryangonj':'narayanganj','naranganj':'narayanganj','narayangonj':'narayanganj',
        'munshigonj':'munshiganj','munsiganj':'munshiganj',
        'gajipur':'gazipur','ghazipur':'gazipur',
        'tangaile':'tangail','tangale':'tangail',
        'kushtiya':'kushtia','kustia':'kushtia',
        'laxmipur':'lakshmipur','laksmipur':'lakshmipur',
        'bramanbariya':'brahmanbaria','bbariya':'brahmanbaria','brahmanbaria':'brahmanbaria',
        'chapai':'chapainawabganj','chapainababganj':'chapainawabganj',
        'sirajgonj':'sirajganj','shirajganj':'sirajganj',
        'faridpure':'faridpur','foridpur':'faridpur',
        'bandorban':'bandarban','banderban':'bandarban',
        'khagrachari':'khagrachhari','khagrasori':'khagrachhari',
        'rangpur':'rangpur','rongpur':'rangpur',
        'rajshahi':'rajshahi','razshahi':'rajshahi',
        'dinajpure':'dinajpur','dinajpor':'dinajpur',
        'panchogarh':'panchagarh','ponchoghor':'panchagarh',
        'manikgonj':'manikganj','manikgong':'manikganj',
        'shoriotpur':'shariatpur','shariyatpur':'shariatpur',
        'gopalgonj':'gopalganj','gopalgunj':'gopalganj',
        'rajbari':'rajbari','razbaree':'rajbari',
        'habigonj':'habiganj','hobiganj':'habiganj',
        'moulovibazar':'moulvibazar','molvibazar':'moulvibazar','sylhet moulvibazar':'moulvibazar',
        'sunamgonj':'sunamganj','sunaamganj':'sunamganj',
        'joypurhat':'joypurhat','joipurhat':'joypurhat',
        'gaibanda':'gaibandha','gaibandah':'gaibandha',
        'kurigam':'kurigram','kurigramm':'kurigram',
        'lalmonirhat':'lalmonirhat','lalmonirhat':'lalmonirhat',
        'nilphamary':'nilphamari','neelphamari':'nilphamari',
        'thakurgao':'thakurgaon','thakurgaw':'thakurgaon',
        'savar':'savar','shabar':'savar'
    };

    // ─── Dhaka sub-area keywords → always means Dhaka city ───
    const dhakaAreas=['mirpur','uttara','dhanmondi','gulshan','motijheel','banani','mohammadpur','farmgate',
        'badda','rampura','khilgaon','tejgaon','lalbagh','wari','jatrabari','bashundhara','cantonment','kafrul',
        'pallabi','shah ali','adabor','hazaribagh','shyamoli','kalabagan','lalmatia','elephant road','new market',
        'shahbag','paltan','banglamotor','kakrail','eskaton','siddheshwari','malibagh','mogbazar','nakhalpara',
        'keraniganj','demra','tongi','turag','savar','ashulia','dhamrai','dohar','nawabganj','kamrangirchar',
        'sutrapur','kotwali','ramna','chowk bazar','gandaria','shantinagar','mugda','sabujbag','khilkhet',
        'bimanbandar','dakshinkhan','bhashantek','agargaon','shewrapara','rokeya sarani','banasree'];

    try{
        // ─── Step 0: Build normalized address with Bangla → English translation ───
        const rawAddr = addrEl.value;
        let normAddr = norm(rawAddr);
        // Append English translations of any Bangla words found
        for(const [bn,en] of Object.entries(bnMap)){
            if(rawAddr.includes(bn)) normAddr += ' ' + en;
        }
        // Apply alias normalization: replace known misspellings
        let aliasAddr = normAddr;
        for(const [mis,canon] of Object.entries(aliasMap)){
            if(aliasAddr.includes(mis)) aliasAddr += ' ' + canon;
        }

        // ─── Step 1: Fetch Pathao cities ───
        const cr=await fetch(PAPI+'?action=get_cities');
        if(!cr.ok){if(!silent){res.className='mt-2 text-xs bg-red-50 text-red-700 p-2 rounded';res.textContent='⚠ Pathao API unavailable.';}return;}
        let j; try{j=await cr.json();}catch(e){if(!silent){res.className='mt-2 text-xs bg-red-50 text-red-700 p-2 rounded';res.textContent='⚠ Pathao API returned invalid response.';}return;}
        const cities=j.data?.data||j.data||[];
        if(!cities.length){if(!silent){res.className='mt-2 text-xs bg-red-50 text-red-700 p-2 rounded';res.textContent='⚠ Could not load Pathao city list.';}return;}

        // Build a scoring function
        function scoreCity(cityName){
            const cn = norm(cityName);
            const cnWords = cn.split(' ');
            let score = 0;

            // Exact full name match in address
            if(aliasAddr.includes(cn)) score += 100;

            // Check each word of city name (for multi-word cities like "coxs bazar")
            const matchedWords = cnWords.filter(w => w.length >= 3 && aliasAddr.split(' ').includes(w));
            if(matchedWords.length === cnWords.length && cnWords.length > 0) score += 90;
            else if(matchedWords.length > 0) score += matchedWords.length * 20;

            // Check if address contains "<city> upazila/zila/district/sadar" pattern
            // e.g., "Sadar Cox's Bazar Upazila" or "Cox's Bazar Sadar"
            const patterns = [
                new RegExp('sadar\\s+' + cn.replace(/\s+/g, '\\s*')),
                new RegExp(cn.replace(/\s+/g, '\\s*') + '\\s+(?:sadar|upazila|upozila|zila|zela|district|thana)'),
                new RegExp(cn.replace(/\s+/g, '\\s*') + '\\s+(?:city|town|metro)')
            ];
            for(const p of patterns){
                if(p.test(aliasAddr)) score += 50;
            }

            // Fuzzy: check if any word in address is within edit distance 1 of city words
            if(score === 0 && cn.length >= 4){
                const addrWords = aliasAddr.split(' ');
                for(const aw of addrWords){
                    if(aw.length < 3) continue;
                    for(const cw of cnWords){
                        if(cw.length < 3) continue;
                        if(editDist(aw, cw) <= 1 && cw.length >= 4) score += 30;
                    }
                }
            }
            return score;
        }

        // Simple Levenshtein distance (capped for performance)
        function editDist(a,b){
            if(Math.abs(a.length-b.length)>2) return 99;
            const m=a.length,n=b.length;
            const dp=Array.from({length:m+1},(_,i)=>i);
            for(let j=1;j<=n;j++){
                let prev=dp[0]; dp[0]=j;
                for(let i=1;i<=m;i++){
                    const tmp=dp[i];
                    dp[i]=a[i-1]===b[j-1]?prev:1+Math.min(prev,dp[i],dp[i-1]);
                    prev=tmp;
                }
            }
            return dp[m];
        }

        // ─── Step 2: Score all cities and pick best non-Dhaka match first ───
        let scored = cities.map(c => ({...c, score: scoreCity(c.city_name)})).filter(c => c.score > 0);
        scored.sort((a,b) => b.score - a.score);

        // Prefer non-Dhaka matches when there's a specific district in the address
        // Only fall back to Dhaka if it's the ONLY match or via Dhaka area keywords
        let mc = null;
        const dhakaEntry = scored.find(c => norm(c.city_name) === 'dhaka');
        const nonDhaka = scored.filter(c => norm(c.city_name) !== 'dhaka');

        if(nonDhaka.length > 0 && nonDhaka[0].score >= 30){
            mc = nonDhaka[0]; // Prefer specific district
        } else if(dhakaEntry && dhakaEntry.score >= 50){
            mc = dhakaEntry; // Strong Dhaka match
        } else if(scored.length > 0){
            mc = scored[0]; // Best whatever we have
        }

        // ─── Step 2b: Dhaka sub-area fallback (only if no district matched) ───
        if(!mc){
            const isDhakaSub = dhakaAreas.some(k => aliasAddr.includes(k));
            if(isDhakaSub) mc = cities.find(c => norm(c.city_name) === 'dhaka');
        }

        if(!mc){
            if(!silent){res.className='mt-2 text-xs bg-orange-50 text-orange-700 p-2 rounded';res.textContent='⚠ Could not detect city. Try adding district name in English.';}
            return;
        }

        // ─── Step 3: Set city and match zone ───
        document.getElementById('pCityId').value=mc.city_id;
        await loadZones(mc.city_id);

        const zj=await(await fetch(PAPI+'?action=get_zones&city_id='+mc.city_id)).json();
        const zones=zj.data?.data||zj.data||[];
        let mz=null;

        // Score zones — number-aware to prevent "Mirpur 14" matching "Mirpur 1"
        let scoredZones = zones.map(z=>{
            const zn=norm(z.zone_name);
            let s=0;

            // ── Exact full zone name as a bounded word/phrase in address ──
            // Use word-boundary regex to prevent "mirpur 1" matching inside "mirpur 14"
            const znEsc = zn.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const exactRe = new RegExp('(?:^|\\s)' + znEsc + '(?:\\s|$)');
            if(exactRe.test(aliasAddr)) s+=200;
            // Looser: substring match (lower priority — catches "mirpur-1" etc.)
            else if(aliasAddr.includes(zn)) s+=100;

            const zWords=zn.split(' ').filter(w=>w.length>=1);
            const fw=zWords[0]||'';

            // ── Numbered zone handling (e.g. "Mirpur 14", "Sector 7") ──
            // If zone has a number component, require the number to match too
            const zNum = zn.match(/\d+/);
            if(zNum && s < 200){
                // Check if address has "<first_word> <exact_number>" as bounded match
                const numRe = new RegExp('(?:^|\\s)' + fw.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + '\\s*[-]?\\s*' + zNum[0] + '(?:\\s|$|[^0-9])');
                if(numRe.test(aliasAddr)) s+=180;
                // If address has the first word but a DIFFERENT number, penalize
                else if(fw.length>=4 && aliasAddr.includes(fw)){
                    const otherNum = aliasAddr.match(new RegExp(fw.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + '\\s*[-]?\\s*(\\d+)'));
                    if(otherNum && otherNum[1] !== zNum[0]) s-=50; // wrong number penalty
                    else if(!otherNum) s+=40; // first word match, no number in address
                }
            } else if(!zNum){
                // Non-numbered zone: first word match is fine
                if(fw.length>=4 && aliasAddr.includes(fw)) s+=60;
            }

            // Word intersection (skip short number-only tokens)
            const matched=zWords.filter(w=>w.length>=3 && aliasAddr.includes(w));
            if(matched.length>0) s+=matched.length*25;

            // Fuzzy first word (only for non-numbered or when no other match)
            if(s<=0 && fw.length>=4){
                const addrWords=aliasAddr.split(' ');
                for(const aw of addrWords){
                    if(editDist(aw,fw)<=1) s+=20;
                }
            }
            return {...z,score:s};
        }).filter(z=>z.score>0).sort((a,b)=>b.score-a.score);

        if(scoredZones.length>0) mz=scoredZones[0];

        if(mz){
            document.getElementById('pZoneId').value=mz.zone_id;
            await loadAreas(mz.zone_id);

            // ─── Step 4: Match area ───
            const areaSelect=document.getElementById('pAreaId');
            let bestArea=null, bestAreaScore=0;
            for(const opt of areaSelect.options){
                if(!opt.value) continue;
                const an=norm(opt.textContent);
                let s=0;
                if(aliasAddr.includes(an)) s+=100;
                const fw=an.split(' ')[0];
                if(fw.length>=4 && aliasAddr.includes(fw)) s+=60;
                const aWords=an.split(' ').filter(w=>w.length>=3);
                const matched=aWords.filter(w=>aliasAddr.includes(w));
                if(matched.length>0) s+=matched.length*25;
                if(s>bestAreaScore){bestAreaScore=s;bestArea=opt;}
            }
            if(bestArea) areaSelect.value=bestArea.value;
        }

        // ─── Step 5: Show result and save ───
        const cityName=mc.city_name;
        const zoneName=mz?mz.zone_name:'';
        const areaName=document.getElementById('pAreaId').selectedOptions[0]?.text||'';
        const hasArea=document.getElementById('pAreaId').value;

        res.classList.remove('hidden');
        res.className='mt-2 text-xs bg-green-50 text-green-700 p-2 rounded';
        res.textContent='✅ '+cityName+(zoneName?' → '+zoneName:'')+(hasArea&&areaName&&areaName!=='Select Area'?' → '+areaName:'');
        if(!mz) res.textContent+=' — select zone manually';
        saveOrderLocation();

    }catch(e){
        if(!silent){res.classList.remove('hidden');res.className='mt-2 text-xs bg-red-50 text-red-700 p-2 rounded';res.textContent='Error: '+e.message;}
    }
}
function esc(s){return s?s.replace(/&/g,'&amp;').replace(/'/g,'&#39;').replace(/"/g,'&quot;').replace(/</g,'&lt;'):''}
calcTotals();
</script>
<script>
/* ── Courier Tracking: Upload / Sync / Auto-fetch ── */
var COURIER_API = '<?= SITE_URL ?>/api/steadfast-actions.php';
var PATHAO_API  = '<?= SITE_URL ?>/api/pathao-api.php';

function getSelectedCourier(){
    var sel = document.getElementById('deliveryMethodSelect');
    return sel ? sel.value.toLowerCase() : '<?= strtolower($order['shipping_method'] ?? '') ?>';
}
function updateUploadBtn(){
    var btn = document.getElementById('sfUploadBtn');
    if(!btn) return;
    var sel = document.getElementById('deliveryMethodSelect');
    var name = sel ? sel.value : 'Courier';
    var lc = name.toLowerCase();
    if(lc.indexOf('pathao')!==-1||lc.indexOf('steadfast')!==-1){
        btn.textContent = '🚀 Upload to ' + name;
        btn.disabled = false;
        btn.className = 'text-xs px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-medium';
    } else {
        btn.textContent = '🚀 Upload to ' + name;
        btn.className = 'text-xs px-3 py-1.5 bg-gray-400 text-white rounded-lg cursor-not-allowed font-medium';
    }
}
function uploadToCourier(orderId) {
    var courier = getSelectedCourier();
    var btn = document.getElementById('sfUploadBtn');

    // Route to correct courier
    if (courier.indexOf('pathao') !== -1) {
        uploadToPathao(orderId, btn);
    } else if (courier.indexOf('steadfast') !== -1) {
        uploadToSteadfast(orderId, btn);
    } else if (courier.indexOf('redx') !== -1) {
        uploadToRedX(orderId, btn);
    } else {
        alert('⚠ Upload not supported for "' + (document.querySelector('select[name="delivery_method"]')?.value || courier) + '". Only Pathao, Steadfast, and RedX have API upload.');
    }
}

async function uploadToSteadfast(orderId, btn) {
    const ok = await window._confirmAsync('Upload this order to Steadfast?');
    if (!ok) return;
    if(btn){btn.disabled=true;btn.textContent='⏳ Uploading...';}
    fetch(COURIER_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'upload_order', order_id:orderId})})
    .then(function(r){return r.json()}).then(function(d) {
        if (d.success) { location.reload(); }
        else { alert('❌ ' + (d.message || d.error || 'Upload failed')); if(btn){btn.disabled=false;btn.textContent='🚀 Upload to Steadfast';} }
    }).catch(function(e) { alert('Error: ' + e.message); if(btn){btn.disabled=false;btn.textContent='🚀 Upload to Steadfast';} });
}

async function uploadToRedX(orderId, btn) {
    const ok = await window._confirmAsync('Upload this order to RedX?');
    if (!ok) return;
    if(btn){btn.disabled=true;btn.textContent='⏳ Uploading to RedX...';}
    var REDX_API = '<?=SITE_URL?>/api/redx-actions.php';
    fetch(REDX_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'upload_order', order_id:orderId})})
    .then(function(r){return r.json()}).then(function(d) {
        if (d.success) { location.reload(); }
        else { alert('❌ ' + (d.message || d.error || 'RedX upload failed')); if(btn){btn.disabled=false;btn.textContent='🚀 Upload to RedX';} }
    }).catch(function(e) { alert('Error: ' + e.message); if(btn){btn.disabled=false;btn.textContent='🚀 Upload to RedX';} });
}

async function uploadToPathao(orderId, btn) {
    var cs=document.getElementById('pCityId'), zs=document.getElementById('pZoneId'), as2=document.getElementById('pAreaId');
    var cityId = cs?.value || 0;
    var zoneId = zs?.value || 0;
    var areaId = as2?.value || 0;
    if (!cityId || !zoneId) {
        alert('⚠ Please select City and Zone before uploading to Pathao.');
        return;
    }
    var cityName = cs?.selectedOptions[0]?.textContent?.trim() || '';
    var zoneName = zs?.selectedOptions[0]?.textContent?.trim() || '';
    var areaName = as2?.selectedOptions[0]?.textContent?.trim() || '';
    if(cityName==='Select City')cityName=''; if(zoneName==='Select Zone')zoneName=''; if(areaName==='Select Area')areaName='';
    const ok = await window._confirmAsync('Upload this order to Pathao?');
    if (!ok) return;
    if(btn){btn.disabled=true;btn.textContent='⏳ Uploading to Pathao...';}
    fetch(PATHAO_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
        action:'upload_pathao_order',
        order_id: orderId,
        recipient_city: parseInt(cityId),
        recipient_zone: parseInt(zoneId),
        recipient_area: parseInt(areaId) || 0,
        city_name: cityName,
        zone_name: zoneName,
        area_name: areaName
    })})
    .then(function(r){return r.json()}).then(function(d) {
        if (d.success) { location.reload(); }
        else { alert('❌ ' + (d.message || 'Pathao upload failed')); if(btn){btn.disabled=false;btn.textContent='🚀 Upload to Pathao';} }
    }).catch(function(e) { alert('Error: ' + e.message); if(btn){btn.disabled=false;btn.textContent='🚀 Upload to Pathao';} });
}

function courierSync(orderId) {
    var btn = document.getElementById('syncBtn');
    if(btn){var orig=btn.textContent; btn.textContent='⏳...';}
    fetch(COURIER_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'sync_courier', order_id:orderId})})
    .then(function(r){return r.json()}).then(function(d) {
        if (d.success) { 
            // Update UI inline without reload
            courierUpdateUI(d);
        } else { 
            alert('❌ ' + (d.error || 'Sync failed')); 
        }
        if(btn) btn.textContent='🔄 Sync';
    }).catch(function(e) { alert('Error: ' + e.message); if(btn) btn.textContent='🔄 Sync'; });
}

function courierUpdateUI(d) {
    var el;
    // Update courier status
    if (d.courier_status) {
        el = document.getElementById('courierStatusVal');
        if (el) { el.textContent = d.courier_status; }
    }
    // Update tracking message
    if (d.tracking_message) {
        el = document.getElementById('trackingMsg');
        if (el) { el.textContent = '📍 ' + d.tracking_message; el.classList.remove('hidden'); }
    }
    // Update charges
    if (d.delivery_charge && parseFloat(d.delivery_charge) > 0) {
        el = document.getElementById('courierCharges');
        if (el) { el.textContent = 'Delivery Charge: ৳' + Number(d.delivery_charge).toLocaleString() + ' | COD: ৳' + Number(d.cod_amount||0).toLocaleString(); el.classList.remove('hidden'); }
    }
    // Update live data section
    el = document.getElementById('courierLiveData');
    if (el && d.live_status) {
        el.innerHTML = '<div class="text-[10px] text-gray-500">Live: <b>' + (d.live_status||'') + '</b> — synced just now</div>';
        el.classList.remove('hidden');
    }
}

// ── Auto-fetch courier status on page load ──
<?php if ($__hasCid && $__isShipped): ?>
(function(){
    setTimeout(function(){
        fetch(COURIER_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'sync_courier', order_id:<?= intval($order['id']) ?>})})
        .then(function(r){return r.json()}).then(function(d){
            if(d.success) courierUpdateUI(d);
        }).catch(function(){});
    }, 500);
})();
<?php endif; ?>

// ══════════════════════════ NOTE TEMPLATE PICKER ══════════════════════════
var _noteTplTarget = null;
var _noteTpls = {
    note_tpl_shipping: <?= json_encode(array_filter(array_map('trim', explode("\n", getSetting('note_tpl_shipping', "***No Exchange or Return***\nভাঙ্গলে রিটার্ন হবে না\nHandle with care — fragile item"))))) ?>,
    note_tpl_order: <?= json_encode(array_filter(array_map('trim', explode("\n", getSetting('note_tpl_order', "ধন্যবাদ! আপনার পরবর্তী অর্ডারে ১০% ছাড়\nগিফট র‍্যাপ করা হয়েছে"))))) ?>,
    note_tpl_panel: <?= json_encode(array_filter(array_map('trim', explode("\n", getSetting('note_tpl_panel', "ফোন করে কনফার্ম করা হয়েছে\nকাস্টমার রিপিটার — VIP\nডেলিভারি চার্জ বাকি"))))) ?>
};

function showNoteTpl(targetField, tplKey) {
    _noteTplTarget = targetField;
    var tpls = _noteTpls[tplKey] || [];
    if (!tpls.length) { alert('No templates configured. Add them in Settings → Note Templates.'); return; }
    var m = document.getElementById('noteTplModal');
    if (!m) {
        m = document.createElement('div'); m.id = 'noteTplModal';
        m.style.cssText = 'position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center';
        m.onclick = function(e){ if(e.target===m) m.style.display='none'; };
        m.innerHTML = '<div style="background:#fff;border-radius:12px;max-width:400px;width:90%;max-height:70vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15)" onclick="event.stopPropagation()"><div style="padding:12px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center"><span style="font-weight:700;font-size:13px">📋 টেমপ্লেট বাছাই করুন</span><button onclick="this.closest(\'#noteTplModal\').style.display=\'none\'" style="background:none;border:none;font-size:18px;cursor:pointer;color:#999">&times;</button></div><div id="noteTplList" style="padding:8px"></div></div>';
        document.body.appendChild(m);
    }
    var list = document.getElementById('noteTplList');
    list.innerHTML = '';
    tpls.forEach(function(t){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.style.cssText = 'display:block;width:100%;text-align:left;padding:10px 12px;margin:3px 0;border-radius:8px;border:1px solid #e5e7eb;background:#fafafa;cursor:pointer;font-size:12px;color:#374151;transition:all .15s';
        btn.textContent = t;
        btn.onmouseover = function(){ this.style.background='#eff6ff';this.style.borderColor='#93c5fd'; };
        btn.onmouseout = function(){ this.style.background='#fafafa';this.style.borderColor='#e5e7eb'; };
        btn.onclick = function(){
            var ta = document.querySelector('[name="'+_noteTplTarget+'"]') || document.getElementById(_noteTplTarget);
            if (ta) { ta.value = ta.value ? ta.value + '\n' + t : t; ta.focus(); }
            m.style.display = 'none';
        };
        list.appendChild(btn);
    });
    m.style.display = 'flex';
}

// ══════════════════════════ ORDER LOCK HEARTBEAT ══════════════════════════
(function(){
    const LOCK_API = '<?= SITE_URL ?>/api/order-lock.php';
    const ORDER_ID = <?= intval($id) ?>;
    let _lockLost = false;
    let _isNavigating = false;
    
    function heartbeat() {
        if (_lockLost || _isNavigating) return;
        fetch(LOCK_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=heartbeat&order_id=' + ORDER_ID
        })
        .then(r => r.json())
        .then(d => {
            if (d.taken_over) {
                _lockLost = true;
                showTakeoverScreen(d.taken_by);
            }
        })
        .catch(() => {});
    }
    
    function showTakeoverScreen(takenBy) {
        if (!takenBy || takenBy === 'Unknown') takenBy = 'Another user';
        const overlay = document.createElement('div');
        overlay.id = 'lockOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(255,255,255,0.97);display:flex;align-items:center;justify-content:center';
        overlay.innerHTML = `
            <div style="max-width:480px;width:90%;background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.1);overflow:hidden">
                <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:20px 32px;text-align:center">
                    <div style="font-size:28px;margin-bottom:6px">🔒</div>
                    <h2 style="font-size:17px;font-weight:700;color:#1f2937;margin:0">Order Currently Being Edited</h2>
                </div>
                <div style="padding:24px 32px;text-align:center">
                    <p style="color:#4b5563;font-size:14px;margin-bottom:20px">
                        <strong style="color:#1f2937">${takenBy}</strong> took over this order while you were viewing it.
                    </p>
                    <div id="lockOverlayActions">
                        <button onclick="overlayTakeover()" style="background:#2563eb;color:#fff;font-weight:600;padding:9px 20px;border-radius:8px;border:none;cursor:pointer;font-size:13px;margin-right:8px">
                            Take Over
                        </button>
                        <button onclick="location.reload()" style="background:#fff;color:#374151;font-weight:600;padding:9px 20px;border-radius:8px;border:1px solid #d1d5db;cursor:pointer;font-size:13px">
                            Retry
                        </button>
                    </div>
                    <div id="lockOverlayConfirm" style="display:none">
                        <p style="font-size:12px;color:#d97706;margin-bottom:12px">This will remove ${takenBy}'s access. Are you sure?</p>
                        <button onclick="overlayConfirmTakeover()" id="overlayConfirmBtn" style="background:#dc2626;color:#fff;font-weight:600;padding:9px 20px;border-radius:8px;border:none;cursor:pointer;font-size:13px;margin-right:6px">
                            Yes, Take Over
                        </button>
                        <button onclick="overlayCancelTakeover()" style="background:#fff;color:#6b7280;font-weight:500;padding:9px 16px;border-radius:8px;border:1px solid #d1d5db;cursor:pointer;font-size:13px">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
    }
    
    // Force clear a stale/corrupt lock (when locker is Unknown)
    window.forceClearLock = async function() {
        const ok = await window._confirmAsync('Force clear this stale lock and access the order?');
        if (!ok) return;
        fetch(LOCK_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=takeover&order_id=' + ORDER_ID
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) location.reload();
            else alert('Failed to clear lock. Please retry.');
        })
        .catch(() => { alert('Network error. Please retry.'); });
    };

    window.overlayTakeover = function() {
        document.getElementById('lockOverlayActions').style.display = 'none';
        document.getElementById('lockOverlayConfirm').style.display = '';
    };
    window.overlayCancelTakeover = function() {
        document.getElementById('lockOverlayConfirm').style.display = 'none';
        document.getElementById('lockOverlayActions').style.display = '';
    };
    window.overlayConfirmTakeover = function() {
        const btn = document.getElementById('overlayConfirmBtn');
        btn.disabled = true; btn.textContent = 'Taking over...';
        fetch(LOCK_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=takeover&order_id=' + ORDER_ID
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) location.reload();
            else { alert('Failed'); btn.disabled = false; btn.textContent = 'Confirm Take Over'; }
        })
        .catch(e => { alert('Error: ' + e.message); btn.disabled = false; btn.textContent = 'Confirm Take Over'; });
    };
    
    // ── Co-viewer poll ────────────────────────────────────────────────────
    function pollCoViewers() {
        fetch(LOCK_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=co_viewers&order_id=' + ORDER_ID
        })
        .then(r => r.json())
        .then(d => {
            const banner = document.getElementById('coViewerBanner');
            const text   = document.getElementById('coViewerText');
            if (!banner || !text) return;
            if (d.viewers && d.viewers.length > 0) {
                const names = d.viewers.map(v => '<strong>' + v.admin_name + '</strong>').join(', ');
                text.innerHTML = '👁 ' + names + ' ' + (d.viewers.length === 1 ? 'is' : 'are') + ' also viewing this order';
                banner.classList.remove('hidden');
            } else {
                banner.classList.add('hidden');
            }
        })
        .catch(() => {});
    }
    pollCoViewers();
    setInterval(pollCoViewers, 12000);

    // Heartbeat every 20 seconds (lock expires at 90s — plenty of margin)
    setInterval(heartbeat, 20000);
    
    // Release lock ONLY on actual page navigation away (not refresh/reload)
    window.addEventListener('beforeunload', function() {
        _isNavigating = true;
        navigator.sendBeacon(LOCK_API, new URLSearchParams({action: 'release', order_id: ORDER_ID}));
    });

    // Page Visibility API: keep heartbeating in background, only release after 3 minutes hidden
    var _hiddenTimer = null;
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            // Release lock after 3 minutes of being hidden (generous — avoids false triggers)
            _hiddenTimer = setTimeout(function() {
                navigator.sendBeacon(LOCK_API, new URLSearchParams({action: 'release', order_id: ORDER_ID}));
            }, 180000);
        } else {
            // Tab became visible again — cancel pending release and re-acquire lock
            clearTimeout(_hiddenTimer);
            if (!_lockLost) {
                fetch(LOCK_API, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=acquire&order_id=' + ORDER_ID
                }).then(r => r.json()).then(d => {
                    if (d.locked && !d.success) {
                        // Someone else took over while tab was hidden
                        _lockLost = true;
                        showTakeoverScreen(d.locked_by || 'Another user');
                    }
                }).catch(() => {});
            }
        }
    });
})();
</script>

<?php if ($isProcSession): ?>
<script>
// ── Processing Session Controller ──────────────────────────────────────────
(function() {
    var SESSION_KEY  = 'procSession';
    var CURRENT_ID   = <?= (int)$id ?>;
    // If this page loaded but the order is locked (race condition between lock_check and navigation)
    // immediately skip to next order without showing any lock screen
    var IS_LOCKED_ON_LOAD = <?= $__lockBlocked ? 'true' : 'false' ?>;
    if (IS_LOCKED_ON_LOAD) {
        // Load session and find next order
        var _ps = null;
        try { _ps = JSON.parse(sessionStorage.getItem(SESSION_KEY)); } catch(e) {}
        if (_ps && Array.isArray(_ps.queue)) {
            var _pos = _ps.queue.indexOf(CURRENT_ID);
            if (_pos < 0) _pos = _ps.current || 0;
            var _next = _pos + 1;
            if (_next < _ps.queue.length) {
                _ps.current = _next;
                sessionStorage.setItem(SESSION_KEY, JSON.stringify(_ps));
                window.location.replace('<?= addslashes(adminUrl('pages/order-view.php')) ?>?id=' + _ps.queue[_next] + '&proc_session=1');
            } else {
                // End of queue
                sessionStorage.removeItem(SESSION_KEY);
                window.location.replace('<?= addslashes(adminUrl('pages/order-processing.php')) ?>');
            }
        } else {
            window.location.replace('<?= addslashes(adminUrl('pages/order-processing.php')) ?>');
        }
        return; // Stop executing — we're navigating away
    }
    var ORDER_VIEW  = '<?= addslashes(adminUrl('pages/order-view.php')) ?>';
    var PROC_URL    = '<?= addslashes(adminUrl('pages/order-processing.php')) ?>';
    var PRINT_URL   = '<?= addslashes(adminUrl('pages/order-print.php')) ?>';
    var TAG_URL     = '<?= addslashes(adminUrl('pages/order-management.php')) ?>';
    var ORDER_ID    = <?= (int)$id ?>;

    // ── Load session ───────────────────────────────────────────────────────
    var ps = null;
    try { ps = JSON.parse(sessionStorage.getItem(SESSION_KEY)); } catch(e) {}

    // ── Exit session (define early so button always works) ──────────────────
    window.psExit = function() {
        sessionStorage.removeItem(SESSION_KEY);
        window.location.href = PROC_URL;
    };

    // No session — hide bar and bail
    if (!ps || !Array.isArray(ps.queue) || !ps.queue.length) {
        var bar = document.getElementById('procSessionBar');
        if (bar) bar.style.display = 'none';
        return;
    }

    // Find current position
    var pos = ps.queue.indexOf(CURRENT_ID);
    if (pos < 0) pos = ps.current || 0;
    ps.current = pos;
    if (!ps.processed) ps.processed = 0;

    // ── Update session bar UI ──────────────────────────────────────────────
    function updateBar() {
        var elPos  = document.getElementById('psPosition');
        var elTot  = document.getElementById('psTotal');
        var elDone = document.getElementById('psProcessed');
        var elBar  = document.getElementById('psProgress');
        var elPrev = document.getElementById('psPrevBtn');
        var elNext = document.getElementById('psNextBtn');

        if (elPos)  elPos.textContent  = pos + 1;
        if (elTot)  elTot.textContent  = ps.queue.length;
        if (elDone) {
            var skippedTotal = ps.skippedInSession ? Object.values(ps.skippedInSession).reduce(function(a,b){return a+b;},0) : 0;
            elDone.textContent = ps.processed + ' done' + (skippedTotal > 0 ? ' · ' + skippedTotal + ' skipped' : '');
        }
        if (elBar)  elBar.style.width  = Math.round(((pos + 1) / ps.queue.length) * 100) + '%';

        if (elPrev) {
            elPrev.disabled = pos <= 0;
            elPrev.style.opacity = pos <= 0 ? '0.35' : '1';
            elPrev.style.cursor  = pos <= 0 ? 'not-allowed' : 'pointer';
        }
        var isLast = pos >= ps.queue.length - 1;
        if (elNext) {
            if (isLast) {
                elNext.textContent = '✓ Finish';
                elNext.style.background = '#10b981';
            } else {
                elNext.innerHTML = 'Next <svg style="display:inline;width:12px;height:12px;vertical-align:middle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>';
                elNext.style.background = '#f59e0b';
            }
        }
        sessionStorage.setItem(SESSION_KEY, JSON.stringify(ps));
    }

    // Run after DOM is ready
    function init() {
        updateBar();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ── Navigate prev / next ───────────────────────────────────────────────
    window.psNavigate = function(dir) {
        var next = pos + dir;
        if (next < 0) return;
        if (next >= ps.queue.length) { psShowSummary(); return; }
        ps.current = next;
        sessionStorage.setItem(SESSION_KEY, JSON.stringify(ps));
        // Check if next order is locked before navigating
        psGoToOrder(ps.queue[next]);
    };

    // Navigate to an order — check lock first, skip if locked by someone else
    function psGoToOrder(orderId) {
        fetch(ORDER_VIEW + '?id=' + orderId + '&proc_session=1&lock_check=1', {
            credentials: 'same-origin',
            headers: {'X-Proc-Check': '1'}
        })
        .then(function(r) {
            // If response is JSON it means the order is locked
            var ct = r.headers.get('Content-Type') || '';
            if (ct.indexOf('json') >= 0) return r.json();
            // HTML response means we can go there
            return null;
        })
        .then(function(data) {
            if (data && data.locked) {
                // Order is locked — skip it and move to next
                if (!ps.skippedInSession) ps.skippedInSession = {};
                var name = data.locked_by || 'Someone';
                ps.skippedInSession[name] = (ps.skippedInSession[name] || 0) + 1;
                sessionStorage.setItem(SESSION_KEY, JSON.stringify(ps));
                // Silent skip — just move to next, no toast
                // Find next available in queue
                var nextIdx = ps.current + 1;
                if (nextIdx >= ps.queue.length) { psShowSummary(); return; }
                ps.current = nextIdx;
                sessionStorage.setItem(SESSION_KEY, JSON.stringify(ps));
                psGoToOrder(ps.queue[nextIdx]);
            } else {
                // Not locked or lock check failed (treat as available)
                window.location.href = ORDER_VIEW + '?id=' + orderId + '&proc_session=1';
            }
        })
        .catch(function() {
            // On error just navigate anyway
            window.location.href = ORDER_VIEW + '?id=' + orderId + '&proc_session=1';
        });
    }



    // ── Summary overlay ────────────────────────────────────────────────────
    function psShowSummary() {
        var dur   = ps.startedAt ? Math.round((Date.now() - ps.startedAt) / 60000) : 0;
        var done  = ps.processed;
        var total = ps.queue.length;
        var rem   = Math.max(0, total - done);
        // Count session skips
        var skippedSession = ps.skippedInSession || {};
        var totalSkippedSession = Object.values(skippedSession).reduce(function(a,b){return a+b;},0);
        sessionStorage.removeItem(SESSION_KEY);

        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.9);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';

        var card = document.createElement('div');
        card.style.cssText = 'background:#fff;border-radius:24px;padding:48px 52px;text-align:center;max-width:420px;width:100%;box-shadow:0 32px 64px rgba(0,0,0,.35)';

        var timeStr = dur > 0 ? (' &nbsp;·&nbsp; <strong>' + dur + ' min</strong>') : '';
        var skippedHtml = totalSkippedSession > 0
            ? '<div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:10px 16px;margin-bottom:12px;font-size:12px;color:#991b1b">⏭ <strong>' + totalSkippedSession + '</strong> order' + (totalSkippedSession>1?'s':'') + ' skipped (locked by others)</div>'
            : '';
        var remStr  = skippedHtml + (rem > 0
            ? '<div style="background:#fef3c7;border:1.5px solid #fcd34d;border-radius:12px;padding:12px 20px;margin-bottom:24px;font-size:13px;color:#92400e"><strong>' + rem + '</strong> order' + (rem !== 1 ? 's' : '') + ' remaining</div>'
            : '<div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:12px 20px;margin-bottom:24px;font-size:13px;color:#166534">🎉 Queue complete — nothing left!</div>');

        card.innerHTML = [
            '<div style="font-size:64px;line-height:1;margin-bottom:16px">🎉</div>',
            '<h2 style="font-size:22px;font-weight:800;color:#111827;margin:0 0 6px">Session Complete!</h2>',
            '<p style="color:#6b7280;font-size:14px;margin:0 0 20px">',
              '<strong style="color:#111827;font-size:36px;font-weight:900;display:block;line-height:1.1;margin-bottom:4px">' + done + '</strong>',
              'order' + (done !== 1 ? 's' : '') + ' confirmed' + timeStr,
            '</p>',
            remStr,
            '<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">',
              '<button id="psSummaryBack" style="background:#f59e0b;color:#fff;padding:11px 28px;border-radius:10px;font-weight:700;font-size:14px;border:none;cursor:pointer">Back to Processing</button>',
              '<button id="psSummaryStay" style="background:#f3f4f6;color:#374151;padding:11px 28px;border-radius:10px;font-weight:600;font-size:14px;border:none;cursor:pointer">Stay Here</button>',
            '</div>'
        ].join('');

        overlay.appendChild(card);
        document.body.appendChild(overlay);

        document.getElementById('psSummaryBack').addEventListener('click', function() {
            window.location.href = PROC_URL;
        });
        document.getElementById('psSummaryStay').addEventListener('click', function() {
            overlay.remove();
        });
    }

    // ── Auto-advance after confirm (instant) ───────────────────────────────
    function advance() {
        ps.processed += 1;
        var next = pos + 1;
        if (next >= ps.queue.length) {
            ps.current = pos;
            sessionStorage.setItem(SESSION_KEY, JSON.stringify(ps));
            psShowSummary();
        } else {
            ps.current = next;
            sessionStorage.setItem(SESSION_KEY, JSON.stringify(ps));
            psGoToOrder(ps.queue[next]); // checks lock before navigating
        }
    }

    // ── Print helpers ──────────────────────────────────────────────────────
    window.psPrintSticker = function() {
        window.open(PRINT_URL + '?ids=' + ORDER_ID + '&template=stk_standard', '_blank');
    };
    window.psPrintInvoice = function() {
        window.open(PRINT_URL + '?ids=' + ORDER_ID + '&template=inv_standard', '_blank');
    };

    // ── Tag helpers ────────────────────────────────────────────────────────
    window.psAddTag = function() {
        var modal = document.getElementById('psTagModal');
        var input = document.getElementById('psTagInput');
        if (modal) { modal.classList.remove('hidden'); if (input) { input.value = ''; input.focus(); } }
    };
    window.psSubmitTag = function(tag) {
        tag = (tag || '').trim();
        if (!tag) return;
        var modal = document.getElementById('psTagModal');
        if (modal) modal.classList.add('hidden');
        fetch(TAG_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=add_tag&order_id=' + ORDER_ID + '&tag=' + encodeURIComponent(tag)
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d && d.success) {
                var toast = document.createElement('div');
                toast.textContent = '🏷 Tag: ' + tag;
                toast.style.cssText = 'position:fixed;top:70px;right:20px;z-index:9999;background:#1f2937;color:#fff;padding:10px 18px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.25);transition:opacity .3s';
                document.body.appendChild(toast);
                setTimeout(function() { toast.style.opacity = '0'; setTimeout(function() { toast.remove(); }, 300); }, 2000);
            }
        })
        .catch(function() {});
    };

    // ── Intercept Confirm form submit ──────────────────────────────────────
    function interceptForms() {
        var form = document.getElementById('orderForm');
        if (!form) return;

        // Track which submit button was clicked (multiple approaches for reliability)
        var _clickedAction = '';
        form.querySelectorAll('button[type="submit"][name="action"]').forEach(function(btn){
            btn.addEventListener('click', function(e){
                _clickedAction = this.value;
            });
            // Also intercept via mousedown (fires before submit on all browsers)
            btn.addEventListener('mousedown', function(){ _clickedAction = this.value; });
        });

        form.addEventListener('submit', function(e) {
            // Get action from: submitter (modern), tracked click, or first button fallback
            var action = '';
            if (e.submitter && e.submitter.name === 'action') {
                action = e.submitter.value;
            } else {
                action = _clickedAction || '';
            }
            if (action !== 'confirm_order' && action !== 'save_order') return;

            e.preventDefault();
            var btn = e.submitter || form.querySelector('button[type="submit"][value="' + action + '"]');
            var origHtml = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Saving…'; }

            var fd = new FormData(form);
            fd.set('action', action); // Always set action explicitly

            var url = window.location.href.split('#')[0]; // Use current URL with query params

            fetch(url, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function(r) {
                    var ct = r.headers.get('Content-Type') || '';
                    if (ct.indexOf('json') >= 0) return r.json();
                    return r.text().then(function(t) {
                        if (t.indexOf('"success"') >= 0) {
                            try { return JSON.parse(t.replace(/^[^{]*/, '')); } catch(ex) {}
                        }
                        if (t.indexOf('Warning') >= 0 || t.indexOf('Fatal') >= 0) {
                            console.error('PHP Error in response:', t.substring(0, 500));
                            return { success: false, error: 'Server error — check console' };
                        }
                        return { success: true };
                    });
                })
                .then(function(d) {
                    if (d && d.success) {
                        advance();
                    } else if (d && d.conflict) {
                        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                        var next = pos + 1;
                        if (next >= ps.queue.length) { psShowSummary(); return; }
                        ps.current = next;
                        sessionStorage.setItem(SESSION_KEY, JSON.stringify(ps));
                        psGoToOrder(ps.queue[next]);
                    } else {
                        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                        alert('Save failed: ' + (d && d.error ? d.error : 'Unknown error. Check browser console.'));
                    }
                })
                .catch(function(err) {
                    console.error('Fetch error:', err);
                    if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                    alert('Network error — please try again.');
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', interceptForms);
    } else {
        interceptForms();
    }
})();
</script>
<?php endif; ?>
<?php
if ($isModal) {
    ?>
</div><!-- modal-page-wrap -->
</body></html>
<?php
} else {
    require_once __DIR__ . '/../includes/footer.php';
}
?>
