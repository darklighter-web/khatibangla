<?php
/**
 * ManyDial Delivery Hook Webhook
 * Receives call completion payloads from ManyDial and updates call logs
 * 
 * ManyDial POSTs JSON with fields:
 * callbackhook, callerid, number, buttons, userResponse, actions, sms,
 * duration, status, forwardNumber, recordAudioUrl, callOffNumber, 
 * createdAt, updatedAt
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Log raw payload
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    // Try form data
    $data = $_POST;
}

if (empty($data)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Empty payload']);
    exit;
}

try {
    $db = Database::getInstance();

    // Map ManyDial status to our status enum
    $statusMap = [
        'ANSWER'    => 'answered',
        'NO_ANSWER' => 'no_answer',
        'BUSY'      => 'busy',
        'FAILED'    => 'failed',
        'ELSE'      => 'completed',
    ];
    $mdStatus = strtoupper($data['status'] ?? 'ELSE');
    $ourStatus = $statusMap[$mdStatus] ?? 'completed';

    $phone       = $data['number'] ?? '';
    $callerId    = $data['callerid'] ?? '';
    $callbackHook = $data['callbackhook'] ?? '';
    $duration    = intval($data['duration'] ?? 0); // milliseconds
    $durationSec = $duration > 1000 ? intval($duration/1000) : $duration;
    $forwardNo   = $data['forwardNumber'] ?? '';
    $recordUrl   = $data['recordAudioUrl'] ?? '';
    $actions     = $data['actions'] ?? '';
    $sms         = $data['sms'] ?? '';
    $buttons     = is_array($data['buttons'] ?? null) ? json_encode($data['buttons']) : ($data['buttons'] ?? '');
    $userResp    = $data['userResponse'] ?? '';

    // Try to match existing call log by phone + caller_id (most recent)
    $existing = null;
    try {
        if ($phone && $callerId) {
            $existing = $db->fetch(
                "SELECT id FROM md_call_logs WHERE phone_number LIKE ? AND caller_id=? ORDER BY created_at DESC LIMIT 1",
                ['%'.preg_replace('/[^0-9]/','',substr($phone,-10)).'%', $callerId]
            );
        }
    } catch (\Throwable $e) {}

    $updateData = [
        'status'           => $ourStatus,
        'duration_seconds' => $durationSec,
        'recording_url'    => $recordUrl,
        'forward_number'   => $forwardNo,
        'actions_taken'    => $actions,
        'sms_sent'         => $sms,
        'ended_at'         => date('Y-m-d H:i:s'),
        'delivery_hook_payload' => json_encode($data),
    ];

    if ($existing) {
        $db->update('md_call_logs', $updateData, 'id=?', [$existing['id']]);
    } else {
        // New log entry from webhook
        $updateData['call_type']    = 'automation';
        $updateData['phone_number'] = $phone;
        $updateData['caller_id']    = $callerId;
        $updateData['call_payload'] = $callbackHook;
        $db->insert('md_call_logs', $updateData);
    }

    // If call was answered and buttons pressed → check for order actions
    if ($ourStatus === 'answered' && !empty($userResp)) {
        // Try to find customer by phone number
        try {
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            $cust = $db->fetch("SELECT id, customer_name FROM customers WHERE REGEXP_REPLACE(customer_phone,'[^0-9]','') LIKE ?", ['%'.substr($cleanPhone,-10).'%']);
            if ($cust && $existing) {
                $db->update('md_call_logs', ['customer_id'=>$cust['id'],'customer_name'=>$cust['customer_name']], 'id=?', [$existing['id']]);
            }
        } catch (\Throwable $e) {}
    }

    // Log to webhook_logs table if exists
    try {
        $db->query("INSERT INTO courier_webhook_log (courier, payload, result, ip_address) VALUES ('manydial',?,?,?)",
            [json_encode($data), json_encode(['matched'=>!!$existing,'status'=>$ourStatus]), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\Throwable $e) {}

    echo json_encode(['success'=>true,'status'=>$ourStatus,'matched'=>!!$existing]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
