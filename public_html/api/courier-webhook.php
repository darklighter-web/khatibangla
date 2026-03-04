<?php
/**
 * Unified Courier Webhook Endpoint
 * Receives status updates from Pathao, Steadfast, CarryBee, RedX
 * 
 * URLs to configure in courier dashboards:
 *   Pathao:    https://khatibangla.com/api/courier-webhook.php?courier=pathao
 *   Steadfast: https://khatibangla.com/api/courier-webhook.php?courier=steadfast
 *   CarryBee:  https://khatibangla.com/api/courier-webhook.php?courier=carrybee
 *   RedX:      https://khatibangla.com/api/courier-webhook.php?courier=redx&token=YOUR_TOKEN
 * 
 * PATHAO REQUIREMENTS:
 *   - Must return HTTP 202
 *   - Must return X-Pathao-Merchant-Webhook-Integration-Secret header
 *   - Must respond within 10 seconds
 *   - Integration test sends: {"event": "webhook_integration"}
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

$courier = strtolower($_GET['courier'] ?? '');
$rawPayload = file_get_contents('php://input');
$payload = json_decode($rawPayload, true) ?: $_POST;

// Log every webhook hit immediately
_webhookLog($courier, $rawPayload);
_webhookLogDb($courier, $rawPayload);

$db = Database::getInstance();

// ═══════════════════════════════════════
// PATHAO — Special protocol requirements
// ═══════════════════════════════════════
if ($courier === 'pathao') {
    // 1. Always return HTTP 202 (Pathao requirement)
    http_response_code(202);
    header('Content-Type: application/json');
    
    // 2. Return the webhook integration secret header (Pathao requirement)
    $pathaoWebhookSecret = '';
    try { $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'pathao_webhook_secret'"); $pathaoWebhookSecret = $row['setting_value'] ?? ''; } catch (\Throwable $e) {}
    
    if ($pathaoWebhookSecret) {
        header('X-Pathao-Merchant-Webhook-Integration-Secret: ' . $pathaoWebhookSecret);
    }
    
    // 3. Handle integration test
    $event = $payload['event'] ?? '';
    if ($event === 'webhook_integration') {
        _webhookLogDb($courier, $rawPayload, 'Integration test OK — secret header sent');
        echo json_encode(['status' => 'success', 'message' => 'Webhook integration confirmed']);
        exit;
    }
    
    // 4. Verify X-PATHAO-Signature if secret is configured
    if ($pathaoWebhookSecret) {
        $signature = $_SERVER['HTTP_X_PATHAO_SIGNATURE'] ?? '';
        if ($signature && $signature !== $pathaoWebhookSecret) {
            _webhookLogDb($courier, $rawPayload, 'SIGNATURE MISMATCH');
            echo json_encode(['status' => 'error', 'message' => 'Signature mismatch']);
            exit;
        }
    }
    
    // 5. Empty payload = health check
    if (empty($payload)) {
        echo json_encode(['status' => 'success', 'message' => 'Pathao webhook active']);
        exit;
    }
    
    // 6. Process Pathao webhook event
    try {
        $result = handlePathao($db, $payload);
        _webhookLogDb($courier, $rawPayload, $result);
        echo json_encode(['status' => 'success', 'message' => $result]);
    } catch (\Throwable $e) {
        _webhookLog($courier, 'ERROR: ' . $e->getMessage());
        _webhookLogDb($courier, $rawPayload, 'ERROR: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════
// STEADFAST / CARRYBEE — Standard handling
// ═══════════════════════════════════════
header('Content-Type: application/json');

if (empty($payload)) {
    echo json_encode(['status' => 'success', 'message' => 'Webhook active']);
    exit;
}

try {
    switch ($courier) {
        case 'steadfast':
            $result = handleSteadfast($db, $payload);
            break;
        case 'carrybee':
            $result = handleCarryBee($db, $payload);
            break;
        case 'redx':
            $result = handleRedX($db, $payload);
            break;
        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown courier: ' . $courier]);
            exit;
    }
    _webhookLogDb($courier, $rawPayload, $result);
    echo json_encode(['status' => 'success', 'message' => $result]);
} catch (\Throwable $e) {
    _webhookLog($courier, 'ERROR: ' . $e->getMessage());
    _webhookLogDb($courier, $rawPayload, 'ERROR: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;


// ══════════════════════════════════════════════════════
// PATHAO WEBHOOK HANDLER
// All events from Pathao developer dashboard:
//   Order Created, Order Updated, Pickup Requested,
//   Assigned For Pickup, Pickup, Pickup Failed,
//   Pickup Cancelled, At the Sorting Hub, In Transit,
//   Received at Last Mile Hub, Assigned for Delivery,
//   Delivered, Partial Delivery, Return, Delivery Failed,
//   On Hold, Payment Invoice, Paid Return, Exchange,
//   Store Created, Store Updated
// ══════════════════════════════════════════════════════
function handlePathao($db, $payload) {
    $event = $payload['event'] ?? '';
    
    // Event name → our order_status (only for status-changing events)
    $eventStatusMap = [
        // Delivery
        'delivered'              => 'delivered',
        'partial_delivery'       => 'partial_delivered',
        'payment_invoice'        => 'delivered',
        // Return / Exchange
        'return'                 => 'pending_return',
        'paid_return'            => 'pending_return',
        'exchange'               => 'pending_return',
        // Failure / Cancel
        'delivery_failed'        => 'on_hold',
        'pickup_failed'          => 'on_hold',
        'pickup_cancelled'       => 'pending_cancel',
        // Hold
        'on_hold'                => 'on_hold',
    ];
    
    // order_status field format (Pathao also sends this in payload)
    $orderStatusMap = [
        'Pending'           => null,
        'Picked'            => null,
        'In_Transit'        => null,
        'At_Transit'        => null,
        'Delivery_Ongoing'  => null,
        'Delivered'         => 'delivered',
        'Partial_Delivered'  => 'partial_delivered',
        'Return'            => 'pending_return',
        'Return_Ongoing'    => 'pending_return',
        'Returned'          => 'pending_return',
        'Exchange'          => 'pending_return',
        'Hold'              => 'on_hold',
        'Cancelled'         => 'pending_cancel',
        'Payment_Invoice'   => 'delivered',
    ];
    
    // Skip store events
    if (in_array($event, ['store_created', 'store_updated'])) {
        return "Store event '{$event}' logged";
    }
    
    // Find order
    $consignmentId = $payload['consignment_id'] ?? '';
    $merchantOrderId = $payload['merchant_order_id'] ?? '';
    
    if (empty($consignmentId) && empty($merchantOrderId)) {
        return "No identifier (event: {$event})";
    }
    
    $order = null;
    if ($consignmentId) {
        $order = $db->fetch(
            "SELECT * FROM orders WHERE pathao_consignment_id = ? OR courier_consignment_id = ? OR courier_tracking_id = ?",
            [$consignmentId, $consignmentId, $consignmentId]
        );
    }
    if (!$order && $merchantOrderId) {
        $order = $db->fetch("SELECT * FROM orders WHERE order_number = ?", [$merchantOrderId]);
    }
    if (!$order) {
        return "Order not found (CID:{$consignmentId} MID:{$merchantOrderId} event:{$event})";
    }
    
    // Determine new status
    $newStatus = null;
    $courierStatusRaw = $event;
    
    // Try event-based mapping first
    if (isset($eventStatusMap[$event])) {
        $newStatus = $eventStatusMap[$event];
    }
    
    // Fallback: check order_status field in payload
    $payloadOrderStatus = $payload['order_status'] ?? '';
    if (!$newStatus && $payloadOrderStatus && isset($orderStatusMap[$payloadOrderStatus])) {
        $newStatus = $orderStatusMap[$payloadOrderStatus];
        $courierStatusRaw = $payloadOrderStatus;
    }
    
    // Build update data
    $updateData = [
        'courier_status'           => $courierStatusRaw,
        'courier_tracking_message' => ucwords(str_replace('_', ' ', $event)),
        'updated_at'               => date('Y-m-d H:i:s'),
    ];
    
    // Save consignment_id if missing
    if ($consignmentId && empty($order['courier_consignment_id'])) {
        $updateData['courier_consignment_id'] = $consignmentId;
    }
    if ($consignmentId && empty($order['pathao_consignment_id'])) {
        $updateData['pathao_consignment_id'] = $consignmentId;
    }
    if (empty($order['courier_name'])) {
        $updateData['courier_name'] = 'Pathao';
    }
    
    // No status change — just track
    if ($newStatus === null) {
        try { $db->update('orders', $updateData, 'id = ?', [$order['id']]); } catch (\Throwable $e) {}
        try { $db->insert('order_status_history', ['order_id' => $order['id'], 'status' => $order['order_status'], 'note' => "Pathao: " . ucwords(str_replace('_', ' ', $event))]); } catch (\Throwable $e) {}
        return "#{$order['order_number']}: '{$event}' tracked";
    }
    
    // Prevent backward from terminal states
    $currentStatus = $order['order_status'];
    if (in_array($currentStatus, ['delivered', 'returned', 'cancelled'])) {
        try { $db->update('orders', $updateData, 'id = ?', [$order['id']]); } catch (\Throwable $e) {}
        return "#{$order['order_number']}: blocked — already '{$currentStatus}'";
    }
    
    // Apply status change
    $updateData['order_status'] = $newStatus;
    if ($newStatus === 'delivered') {
        $updateData['delivered_at'] = date('Y-m-d H:i:s');
    }
    
    $db->update('orders', $updateData, 'id = ?', [$order['id']]);
    
    try { $db->insert('order_status_history', ['order_id' => $order['id'], 'status' => $newStatus, 'note' => "Pathao webhook: {$event}" . ($payloadOrderStatus ? " ({$payloadOrderStatus})" : '')]); } catch (\Throwable $e) {}
    
    if ($newStatus === 'delivered') { try { awardOrderCredits($order['id']); } catch (\Throwable $e) {} }
    if (in_array($newStatus, ['pending_cancel', 'pending_return'])) { try { refundOrderCreditsOnCancel($order['id']); } catch (\Throwable $e) {} }
    
    _webhookLog('Pathao', "#{$order['order_number']}: {$currentStatus} → {$newStatus} (event: {$event})");
    return "#{$order['order_number']}: {$currentStatus} → {$newStatus}";
}


// ══════════════════════════════════════════════════════
// STEADFAST WEBHOOK HANDLER
// ══════════════════════════════════════════════════════
function handleSteadfast($db, $payload) {
    // Status map: courier status → our status
    // 'pending' and 'in_review' only transition if order is currently 'ready_to_ship'
    $statusMap = [
        'pending' => null,  // Don't auto-transition on pending
        'in_review' => '_rts_to_shipped', // Special: only from ready_to_ship
        'delivered' => 'delivered', 'delivered_approval_pending' => 'delivered',
        'partial_delivered' => 'partial_delivered', 'partial_delivered_approval_pending' => 'partial_delivered',
        'cancelled' => 'pending_cancel', 'cancelled_approval_pending' => 'pending_cancel',
        'hold' => 'on_hold', 'unknown' => null, 'unknown_approval_pending' => null,
    ];
    
    $consignmentId = $payload['consignment_id'] ?? '';
    $invoice = $payload['invoice'] ?? '';
    $courierStatus = $payload['status'] ?? $payload['delivery_status'] ?? '';
    $trackingMessage = $payload['tracking_message'] ?? '';
    
    if (empty($consignmentId) && empty($invoice)) return 'No identifier';
    
    // Auth check — Bearer token
    $webhookToken = '';
    try { $webhookToken = getSetting('steadfast_webhook_token', ''); } catch (\Throwable $e) {}
    if ($webhookToken) {
        // Try multiple header sources (some servers strip Authorization)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] 
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
            ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? getallheaders()['authorization'] ?? '') : '')
            ?? '';
        $providedToken = '';
        if (stripos($authHeader, 'Bearer ') === 0) {
            $providedToken = trim(substr($authHeader, 7));
        } else {
            $providedToken = trim($authHeader);
        }
        if ($providedToken !== $webhookToken) {
            http_response_code(401);
            return 'Unauthorized — token mismatch';
        }
    }
    
    // Find order
    $order = null;
    if ($invoice) $order = $db->fetch("SELECT * FROM orders WHERE order_number = ?", [$invoice]);
    if (!$order && $consignmentId) $order = $db->fetch("SELECT * FROM orders WHERE courier_consignment_id = ? OR courier_tracking_id = ?", [$consignmentId, $consignmentId]);
    
    // Tracking update only
    if (($payload['notification_type'] ?? '') === 'tracking_update') {
        if ($order && $trackingMessage) {
            try { $db->update('orders', ['courier_tracking_message' => $trackingMessage, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$order['id']]); } catch (\Throwable $e) {}
            try { $db->insert('order_status_history', ['order_id' => $order['id'], 'status' => $order['order_status'], 'note' => "Steadfast: {$trackingMessage}"]); } catch (\Throwable $e) {}
            return "Tracking: #{$order['order_number']}: {$trackingMessage}";
        }
        return 'Tracking update logged';
    }
    
    if (!$order) return 'Order not found';
    
    $extraUpdate = ['updated_at' => date('Y-m-d H:i:s')];
    if ($trackingMessage) $extraUpdate['courier_tracking_message'] = $trackingMessage;
    if (isset($payload['delivery_charge'])) $extraUpdate['courier_delivery_charge'] = floatval($payload['delivery_charge']);
    if (isset($payload['cod_amount'])) $extraUpdate['courier_cod_amount'] = floatval($payload['cod_amount']);
    if ($consignmentId && empty($order['courier_consignment_id'])) $extraUpdate['courier_consignment_id'] = $consignmentId;
    if (empty($order['courier_name'])) $extraUpdate['courier_name'] = 'Steadfast';
    
    return processStatusUpdate($db, $order, $courierStatus, $statusMap, 'Steadfast', $payload, $extraUpdate);
}


// ══════════════════════════════════════════════════════
// CARRYBEE WEBHOOK HANDLER
// ══════════════════════════════════════════════════════
function handleCarryBee($db, $payload) {
    $statusMap = [
        'delivered' => 'delivered', 'returned' => 'pending_return',
        'partial' => 'partial_delivered', 'cancelled' => 'pending_cancel',
        'lost' => 'lost', 'hold' => 'on_hold',
    ];
    
    $trackingId = $payload['tracking_id'] ?? $payload['consignment_id'] ?? '';
    $invoice = $payload['invoice'] ?? $payload['order_id'] ?? '';
    $courierStatus = $payload['status'] ?? $payload['delivery_status'] ?? '';
    
    if (empty($trackingId) && empty($invoice)) return 'No identifier';
    
    $order = null;
    if ($invoice) $order = $db->fetch("SELECT * FROM orders WHERE order_number = ?", [$invoice]);
    if (!$order && $trackingId) $order = $db->fetch("SELECT * FROM orders WHERE courier_tracking_id = ?", [$trackingId]);
    if (!$order) return 'Order not found';
    
    $extraUpdate = ['updated_at' => date('Y-m-d H:i:s')];
    if (empty($order['courier_name'])) $extraUpdate['courier_name'] = 'CarryBee';
    
    return processStatusUpdate($db, $order, $courierStatus, $statusMap, 'CarryBee', $payload, $extraUpdate);
}


// ══════════════════════════════════════════════════════
// CORE STATUS UPDATE (Steadfast + CarryBee)
// ══════════════════════════════════════════════════════
function processStatusUpdate($db, $order, $courierStatus, $map, $courierName, $payload, $extraUpdate = []) {
    $newStatus = $map[$courierStatus] ?? null;
    $extraUpdate['courier_status'] = $courierStatus;
    
    // Special handling: _rts_to_shipped only transitions from ready_to_ship
    if ($newStatus === '_rts_to_shipped') {
        $newStatus = ($order['order_status'] === 'ready_to_ship') ? 'shipped' : null;
    }
    
    if (!$newStatus) {
        try { $db->update('orders', $extraUpdate, 'id = ?', [$order['id']]); } catch (\Throwable $e) {}
        return "#{$order['order_number']}: '{$courierStatus}' logged";
    }
    
    $currentStatus = $order['order_status'];
    if (in_array($currentStatus, ['delivered', 'returned', 'cancelled'])) {
        try { $db->update('orders', $extraUpdate, 'id = ?', [$order['id']]); } catch (\Throwable $e) {}
        return "#{$order['order_number']}: blocked — already '{$currentStatus}'";
    }
    
    $extraUpdate['order_status'] = $newStatus;
    if ($newStatus === 'delivered') $extraUpdate['delivered_at'] = date('Y-m-d H:i:s');
    
    $db->update('orders', $extraUpdate, 'id = ?', [$order['id']]);
    
    try { $db->insert('order_status_history', ['order_id' => $order['id'], 'status' => $newStatus, 'note' => "{$courierName} webhook: {$courierStatus}"]); } catch (\Throwable $e) {}
    if ($newStatus === 'delivered') { try { awardOrderCredits($order['id']); } catch (\Throwable $e) {} }
    if (in_array($newStatus, ['pending_cancel', 'pending_return'])) { try { refundOrderCreditsOnCancel($order['id']); } catch (\Throwable $e) {} }
    
    _webhookLog($courierName, "#{$order['order_number']}: {$currentStatus} → {$newStatus}");
    return "#{$order['order_number']}: {$currentStatus} → {$newStatus}";
}


// ══════════════════════════════════════════════════════
// REDX WEBHOOK HANDLER
// Statuses: ready-for-delivery, delivery-in-progress,
//   delivered, agent-hold, agent-returning, returned,
//   agent-area-change
// ══════════════════════════════════════════════════════
function handleRedX($db, $payload) {
    $statusMap = [
        'ready-for-delivery'   => '_rts_to_shipped',  // Parcel received → ship from RTS
        'delivery-in-progress' => '_rts_to_shipped',   // Dispatched → ship from RTS
        'delivered'            => 'delivered',
        'agent-hold'           => 'on_hold',
        'agent-returning'      => 'pending_return',
        'returned'             => 'pending_return',
        'agent-area-change'    => null,         // Area change in progress — track only
        'cancelled'            => 'pending_cancel',
    ];

    $trackingNumber = $payload['tracking_number'] ?? '';
    $invoiceNumber  = $payload['invoice_number'] ?? '';
    $courierStatus  = $payload['status'] ?? '';
    $messageEn      = $payload['message_en'] ?? '';
    $messageBn      = $payload['message_bn'] ?? '';
    $timestamp      = $payload['timestamp'] ?? '';

    if (empty($trackingNumber) && empty($invoiceNumber)) return 'No identifier';

    // Auth check — verify token from query parameter
    $webhookToken = '';
    try { $webhookToken = getSetting('redx_webhook_token', ''); } catch (\Throwable $e) {}
    if ($webhookToken) {
        $providedToken = $_GET['token'] ?? '';
        if ($providedToken !== $webhookToken) {
            http_response_code(401);
            return 'Unauthorized — invalid webhook token';
        }
    }

    // Find order — try tracking_number first, then invoice_number
    $order = null;
    if ($trackingNumber) {
        $order = $db->fetch(
            "SELECT * FROM orders WHERE courier_tracking_id = ? OR courier_consignment_id = ?",
            [$trackingNumber, $trackingNumber]
        );
    }
    if (!$order && $invoiceNumber) {
        $order = $db->fetch("SELECT * FROM orders WHERE order_number = ?", [$invoiceNumber]);
    }

    if (!$order) return "Order not found (tracking: {$trackingNumber}, invoice: {$invoiceNumber})";

    $extraUpdate = ['updated_at' => date('Y-m-d H:i:s')];
    if ($messageEn) $extraUpdate['courier_tracking_message'] = $messageEn;
    if ($trackingNumber && empty($order['courier_tracking_id'])) $extraUpdate['courier_tracking_id'] = $trackingNumber;
    if ($trackingNumber && empty($order['courier_consignment_id'])) $extraUpdate['courier_consignment_id'] = $trackingNumber;
    if (empty($order['courier_name'])) $extraUpdate['courier_name'] = 'RedX';

    return processStatusUpdate($db, $order, $courierStatus, $statusMap, 'RedX', $payload, $extraUpdate);
}


// ══════════════════════════════════════════════════════
// LOGGING
// ══════════════════════════════════════════════════════
function _webhookLog($courier, $data) {
    try {
        $dir = dirname(__DIR__) . '/tmp';
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/webhook.log', date('Y-m-d H:i:s') . " [{$courier}] {$data}\n", FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {}
}

function _webhookLogDb($courier, $payload, $result = null) {
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
