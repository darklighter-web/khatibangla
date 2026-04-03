<?php
/**
 * Facebook Conversions API — Server-Side Event Relay
 * POST /api/fb-event.php
 * 
 * Fires server-side events triggered by client JS.
 * Returns event_id for client-side deduplication.
 */
require_once __DIR__ . '/../includes/functions.php';
if (!file_exists(__DIR__ . '/../includes/fb-capi.php')) {
    echo json_encode(['success' => false, 'error' => 'CAPI not installed']);
    exit;
}
require_once __DIR__ . '/../includes/fb-capi.php';
header('Content-Type: application/json');

if (!fbCapiEnabled()) {
    echo json_encode(['success' => false, 'error' => 'CAPI not configured']);
    exit;
}

$event = $_POST['event'] ?? '';
$data = [];
if (!empty($_POST['data'])) {
    $data = is_string($_POST['data']) ? (json_decode($_POST['data'], true) ?? []) : $_POST['data'];
}
$eventId = $_POST['event_id'] ?? fbEventId();

$allowed = ['InitiateCheckout', 'Contact', 'Lead', 'AddPaymentInfo', 'Search', 'ViewContent', 'AddToCart', 'Purchase', 'CompleteRegistration'];
if (!in_array($event, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Event not allowed: ' . $event]);
    exit;
}

// Build custom_data from posted data
$customData = [];
$userData = [];

switch ($event) {
    case 'InitiateCheckout':
        $customData = [
            'content_ids'  => $data['content_ids'] ?? [],
            'content_type' => 'product',
            'value'        => floatval($data['value'] ?? 0),
            'currency'     => 'BDT',
            'num_items'    => intval($data['num_items'] ?? 0),
        ];
        break;

    case 'Contact':
        $customData = ['content_name' => $data['content_name'] ?? 'Contact Click'];
        break;

    case 'Lead':
        $customData = ['content_name' => $data['content_name'] ?? 'Lead Form'];
        $userData = [
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'name'  => $data['name'] ?? '',
        ];
        break;

    case 'AddPaymentInfo':
        $customData = [
            'content_category' => $data['payment_method'] ?? 'cod',
            'value'            => floatval($data['value'] ?? 0),
            'currency'         => 'BDT',
        ];
        break;

    case 'Search':
        $customData = [
            'search_string' => $data['search_string'] ?? '',
            'content_type'  => 'product',
        ];
        break;

    case 'ViewContent':
        $customData = [
            'content_ids'  => $data['content_ids'] ?? [],
            'content_type' => 'product',
            'content_name' => $data['content_name'] ?? '',
            'value'        => floatval($data['value'] ?? 0),
            'currency'     => 'BDT',
        ];
        break;

    case 'AddToCart':
        $customData = [
            'content_ids'  => $data['content_ids'] ?? [],
            'content_type' => 'product',
            'content_name' => $data['content_name'] ?? '',
            'value'        => floatval($data['value'] ?? 0),
            'currency'     => 'BDT',
            'num_items'    => intval($data['num_items'] ?? 1),
        ];
        break;

    case 'Purchase':
        $customData = [
            'content_ids'  => $data['content_ids'] ?? [],
            'content_type' => 'product',
            'value'        => floatval($data['value'] ?? 0),
            'currency'     => 'BDT',
            'num_items'    => intval($data['num_items'] ?? 0),
            'order_id'     => $data['order_id'] ?? '',
        ];
        $userData = [
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'name'  => $data['name'] ?? '',
        ];
        break;

    case 'CompleteRegistration':
        $customData = [
            'content_name' => $data['content_name'] ?? 'Customer Registration',
            'status'       => $data['status'] ?? 'completed',
        ];
        $userData = [
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'name'  => $data['name'] ?? '',
        ];
        break;
}

$result = fbCapiSend($event, $customData, $userData, $eventId);

echo json_encode([
    'success'  => $result['success'],
    'event_id' => $result['event_id'] ?? $eventId,
    'error'    => $result['error'] ?? null,
]);
