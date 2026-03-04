<?php
/**
 * RedX Webhook Callback Endpoint
 * ================================
 * Receives real-time parcel status updates from RedX.
 * 
 * CALLBACK URL to paste in RedX Developer Portal → Webhook section:
 *   https://khatibangla.com/api/redx-webhook.php?token=YOUR_WEBHOOK_TOKEN
 * 
 * RedX sends POST requests with JSON payload:
 * {
 *   "tracking_number": "21A427TU4BN3R",
 *   "timestamp": "2025-02-18T12:30:00.000Z",
 *   "status": "delivered",
 *   "message_en": "Parcels delivered by rider",
 *   "message_bn": "রাইডার দ্বারা পার্সেল ডেলিভারি হয়েছে",
 *   "invoice_number": "ORD-20260218-38D54"
 * }
 * 
 * STATUS MAPPING:
 *   ready-for-delivery   → (tracked only, no status change)
 *   delivery-in-progress → (tracked only)
 *   delivered            → delivered
 *   agent-hold           → on_hold
 *   agent-returning      → pending_return
 *   returned             → pending_return  
 *   agent-area-change    → (tracked only)
 *   cancelled            → pending_cancel
 */

// Respond quickly — RedX expects fast responses
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

$rawPayload = file_get_contents('php://input');
$payload    = json_decode($rawPayload, true) ?: [];

// ── Log every hit immediately ──
webhookLog('redx', $rawPayload);
webhookLogDb('redx', $rawPayload);

$db = Database::getInstance();

// ═══════════════════════════════════════════════
// 1. AUTHENTICATE — verify ?token= query param
// ═══════════════════════════════════════════════
$webhookToken = '';
try {
    $webhookToken = getSetting('redx_webhook_token', '');
} catch (\Throwable $e) {}

if ($webhookToken) {
    $providedToken = $_GET['token'] ?? '';
    if ($providedToken !== $webhookToken) {
        http_response_code(401);
        webhookLogDb('redx', $rawPayload, 'UNAUTHORIZED — token mismatch');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
}

// ═══════════════════════════════════════════════
// 2. HEALTH CHECK — empty payload = ping
// ═══════════════════════════════════════════════
if (empty($payload)) {
    echo json_encode(['status' => 'success', 'message' => 'RedX webhook active']);
    exit;
}

// ═══════════════════════════════════════════════
// 3. PROCESS WEBHOOK
// ═══════════════════════════════════════════════
try {
    $result = processRedXWebhook($db, $payload);
    webhookLogDb('redx', $rawPayload, $result);
    echo json_encode(['status' => 'success', 'message' => $result]);
} catch (\Throwable $e) {
    webhookLog('redx', 'ERROR: ' . $e->getMessage());
    webhookLogDb('redx', $rawPayload, 'ERROR: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;


// ══════════════════════════════════════════════════════════
// REDX WEBHOOK PROCESSOR
// ══════════════════════════════════════════════════════════
function processRedXWebhook($db, $payload) {
    
    // RedX status → our order_status
    $statusMap = [
        'ready-for-delivery'   => null,            // Parcel received from merchant — track only
        'delivery-in-progress' => null,            // Dispatched to rider — track only
        'delivered'            => 'delivered',      // ✅ Delivered
        'agent-hold'           => 'on_hold',        // ⏸ On hold with agent
        'agent-returning'      => 'pending_return', // ↩ Return in progress
        'returned'             => 'pending_return', // ↩ Returned
        'agent-area-change'    => null,            // Area change — track only
        'cancelled'            => 'pending_cancel', // ❌ Cancelled
    ];
    
    // Extract payload fields
    $trackingNumber = trim($payload['tracking_number'] ?? '');
    $invoiceNumber  = trim($payload['invoice_number'] ?? '');
    $courierStatus  = trim($payload['status'] ?? '');
    $messageEn      = trim($payload['message_en'] ?? '');
    $messageBn      = trim($payload['message_bn'] ?? '');
    $timestamp      = trim($payload['timestamp'] ?? '');
    
    if (empty($trackingNumber) && empty($invoiceNumber)) {
        return 'No identifier in payload';
    }
    
    // ── Find order ──
    $order = null;
    
    // Try tracking number first (RedX tracking ID)
    if ($trackingNumber) {
        $order = $db->fetch(
            "SELECT * FROM orders WHERE courier_tracking_id = ? OR courier_consignment_id = ?",
            [$trackingNumber, $trackingNumber]
        );
    }
    
    // Fallback: try invoice number (our order_number)
    if (!$order && $invoiceNumber) {
        $order = $db->fetch(
            "SELECT * FROM orders WHERE order_number = ?",
            [$invoiceNumber]
        );
    }
    
    if (!$order) {
        return "Order not found (tracking: {$trackingNumber}, invoice: {$invoiceNumber})";
    }
    
    // ── Build update data ──
    $updateData = [
        'courier_status'  => $courierStatus,
        'updated_at'      => date('Y-m-d H:i:s'),
    ];
    
    // Save tracking message
    if ($messageEn) {
        $updateData['courier_tracking_message'] = $messageEn;
    }
    
    // Fill in courier identifiers if missing
    if ($trackingNumber && empty($order['courier_tracking_id'])) {
        $updateData['courier_tracking_id'] = $trackingNumber;
    }
    if ($trackingNumber && empty($order['courier_consignment_id'])) {
        $updateData['courier_consignment_id'] = $trackingNumber;
    }
    if (empty($order['courier_name'])) {
        $updateData['courier_name'] = 'RedX';
    }
    if (empty($order['shipping_method'])) {
        $updateData['shipping_method'] = 'RedX';
    }
    
    // ── Determine new order status ──
    $newStatus = $statusMap[$courierStatus] ?? null;
    
    // No status change — just log the tracking event
    if ($newStatus === null) {
        try { $db->update('orders', $updateData, 'id = ?', [$order['id']]); } catch (\Throwable $e) {}
        try {
            $db->insert('order_status_history', [
                'order_id' => $order['id'],
                'status'   => $order['order_status'],
                'note'     => "RedX: {$messageEn}" ?: "RedX: " . ucwords(str_replace('-', ' ', $courierStatus)),
            ]);
        } catch (\Throwable $e) {}
        return "#{$order['order_number']}: '{$courierStatus}' tracked";
    }
    
    // ── Prevent backward from terminal states ──
    $currentStatus = $order['order_status'] ?? '';
    if (in_array($currentStatus, ['delivered', 'returned', 'cancelled'])) {
        try { $db->update('orders', $updateData, 'id = ?', [$order['id']]); } catch (\Throwable $e) {}
        return "#{$order['order_number']}: blocked — already '{$currentStatus}'";
    }
    
    // ── Apply status change ──
    $updateData['order_status'] = $newStatus;
    
    if ($newStatus === 'delivered') {
        $updateData['delivered_at'] = $timestamp ? date('Y-m-d H:i:s', strtotime($timestamp)) : date('Y-m-d H:i:s');
    }
    
    $db->update('orders', $updateData, 'id = ?', [$order['id']]);
    
    // Log status history
    try {
        $db->insert('order_status_history', [
            'order_id' => $order['id'],
            'status'   => $newStatus,
            'note'     => "RedX webhook: {$courierStatus}" . ($messageEn ? " — {$messageEn}" : ''),
        ]);
    } catch (\Throwable $e) {}
    
    // ── Trigger credit/refund actions ──
    if ($newStatus === 'delivered') {
        try { awardOrderCredits($order['id']); } catch (\Throwable $e) {}
    }
    if (in_array($newStatus, ['pending_cancel', 'pending_return'])) {
        try { refundOrderCreditsOnCancel($order['id']); } catch (\Throwable $e) {}
    }
    
    // ── Auto-create return record for returned parcels ──
    if (in_array($newStatus, ['pending_return']) && in_array($courierStatus, ['returned', 'agent-returning'])) {
        try {
            $existingReturn = $db->fetch("SELECT id FROM return_orders WHERE order_id = ?", [$order['id']]);
            if (!$existingReturn) {
                $db->insert('return_orders', [
                    'order_id'      => $order['id'],
                    'return_reason' => "RedX auto-return: {$courierStatus}" . ($messageEn ? " — {$messageEn}" : ''),
                    'return_status' => 'requested',
                    'refund_amount' => 0,
                    'admin_notes'   => "Auto-created from RedX webhook on " . date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {}
    }
    
    webhookLog('redx', "#{$order['order_number']}: {$currentStatus} → {$newStatus} (status: {$courierStatus})");
    return "#{$order['order_number']}: {$currentStatus} → {$newStatus}";
}


// ══════════════════════════════════════════════════════════
// LOGGING HELPERS
// ══════════════════════════════════════════════════════════
function webhookLog($courier, $data) {
    try {
        $dir = dirname(__DIR__) . '/tmp';
        @mkdir($dir, 0755, true);
        @file_put_contents(
            $dir . '/webhook.log',
            date('Y-m-d H:i:s') . " [{$courier}] {$data}\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (\Throwable $e) {}
}

function webhookLogDb($courier, $payload, $result = null) {
    try {
        $db = Database::getInstance();
        $db->insert('courier_webhook_log', [
            'courier'    => $courier,
            'payload'    => substr($payload, 0, 4000),
            'result'     => $result ? substr($result, 0, 255) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (\Throwable $e) {}
}
