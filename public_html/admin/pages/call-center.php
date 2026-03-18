<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();
$pageTitle = 'Call Center';
$tab = $_GET['tab'] ?? 'dashboard';

// ══════════════════════════════════════════
// ── Auto-create / migrate tables ─────────
// ══════════════════════════════════════════
try {
// ManyDial settings
$db->query("CREATE TABLE IF NOT EXISTS md_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Caller IDs registered with ManyDial
$db->query("CREATE TABLE IF NOT EXISTS md_caller_ids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL DEFAULT '',
    caller_id VARCHAR(50) NOT NULL,
    business_name VARCHAR(150) DEFAULT NULL,
    status ENUM('pending','approved','rejected','unknown') DEFAULT 'pending',
    manydial_response JSON DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_caller (caller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Call automation logs
$db->query("CREATE TABLE IF NOT EXISTS md_call_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_type ENUM('automation','click_to_call','call_center') NOT NULL DEFAULT 'automation',
    caller_id VARCHAR(50) DEFAULT NULL,
    phone_number VARCHAR(50) DEFAULT NULL,
    customer_name VARCHAR(200) DEFAULT NULL,
    customer_id INT DEFAULT NULL,
    order_id INT DEFAULT NULL,
    agent_id INT DEFAULT NULL,
    agent_email VARCHAR(150) DEFAULT NULL,
    per_call_duration INT DEFAULT NULL,
    status ENUM('initiated','answered','missed','failed','busy','no_answer','completed') DEFAULT 'initiated',
    duration_seconds INT DEFAULT 0,
    actions_taken JSON DEFAULT NULL,
    sms_sent TEXT DEFAULT NULL,
    recording_url VARCHAR(500) DEFAULT NULL,
    forward_number VARCHAR(50) DEFAULT NULL,
    delivery_hook_payload JSON DEFAULT NULL,
    call_payload VARCHAR(500) DEFAULT NULL,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phone (phone_number),
    INDEX idx_status (status),
    INDEX idx_started (started_at),
    INDEX idx_order (order_id),
    INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Call Center agents
$db->query("CREATE TABLE IF NOT EXISTS md_agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caller_id VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    call_direction ENUM('inbound','outbound','both') DEFAULT 'both',
    phone_type ENUM('WEBPHONE','CELLPHONE','SOFTPHONE') DEFAULT 'WEBPHONE',
    auto_connect TINYINT(1) DEFAULT 1,
    expires_at DATE DEFAULT NULL,
    manydial_agent_id VARCHAR(100) DEFAULT NULL,
    status ENUM('active','inactive','deleted') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_email_caller (email, caller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Call automation templates
$db->query("CREATE TABLE IF NOT EXISTS md_call_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    caller_id VARCHAR(50) NOT NULL,
    per_call_duration INT DEFAULT 5,
    messages JSON NOT NULL,
    buttons JSON DEFAULT NULL,
    delivery_hook VARCHAR(500) DEFAULT NULL,
    call_payload VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (\Throwable $e) {}

// ─── Helper: get MD setting ───────────────────────────────────────────────
function mdSetting($key, $default = '') {
    global $db;
    try {
        $row = $db->fetch("SELECT setting_value FROM md_settings WHERE setting_key=?", [$key]);
        return $row['setting_value'] ?? $default;
    } catch (\Throwable $e) { return $default; }
}
function mdSetSetting($key, $value) {
    global $db;
    try {
        $db->query("INSERT INTO md_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()", [$key, $value]);
    } catch (\Throwable $e) {}
}

// ─── ManyDial API helper ──────────────────────────────────────────────────
function mdApiCall($endpoint, $method = 'POST', $data = [], $isJson = true) {
    $apiKey = mdSetting('md_api_key');
    if (!$apiKey) return ['success'=>false,'error'=>'API key not configured'];

    $baseUrl = 'https://api.manydial.com/v1';
    $url = $baseUrl . $endpoint;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . $apiKey,
        $isJson ? 'Content-Type: application/json' : 'Content-Type: multipart/form-data',
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $isJson ? json_encode($data) : $data);
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        if (!empty($data)) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    // GET: no body needed, params already in URL

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return ['success'=>false,'error'=>$curlError];
    $decoded = json_decode($response, true);
    return $decoded ?? ['success'=>false,'error'=>'Invalid response','raw'=>$response];
}

// FormData version for Caller ID registration
function mdApiCallFormData($endpoint, $data = []) {
    $apiKey = mdSetting('md_api_key');
    if (!$apiKey) return ['success'=>false,'error'=>'API key not configured'];

    $url = 'https://api.manydial.com/v1' . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . $apiKey]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) return ['success'=>false,'error'=>$curlError];
    return json_decode($response, true) ?? ['success'=>false,'error'=>'Invalid response'];
}

// ══════════════════════════════════════════
// ── POST action handlers ──────────────────
// ══════════════════════════════════════════
$msg = '';
$msgType = 'success';

// ── GET API test handler ──
if (($_GET['action'] ?? '') === 'api_test') {
    header('Content-Type: application/json');
    // Try a simple call to verify API key works
    // We pass a dummy caller_id to get a structured response (even 4xx confirms connection)
    $apiKey = mdSetting('md_api_key');
    if (!$apiKey) {
        echo json_encode(['success'=>false,'error'=>'No API key configured']);
        exit;
    }
    $ch = curl_init('https://api.manydial.com/v1/portal/call-center/agent-list?callerId=%2B8800000000000');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_HTTPHEADER=>["x-api-key: $apiKey",'Content-Type: application/json']]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $parsed = json_decode($resp, true);
    // Any structured JSON response means the API is reachable and key was processed
    $connected = !$err && $httpCode > 0 && is_array($parsed);
    echo json_encode(['success'=>$connected,'http_code'=>$httpCode,'response'=>$parsed,'api_key_set'=>true,'curl_error'=>$err?:null]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Save API settings ──
    if ($action === 'save_settings') {
        mdSetSetting('md_api_key', sanitize($_POST['md_api_key'] ?? ''));
        mdSetSetting('md_delivery_hook', sanitize($_POST['md_delivery_hook'] ?? ''));
        mdSetSetting('md_default_caller_id', sanitize($_POST['md_default_caller_id'] ?? ''));
        mdSetSetting('md_default_duration', intval($_POST['md_default_duration'] ?? 5));
        header('Location: ?tab=settings&msg=Settings+saved'); exit;
    }

    // ── Register Caller ID ──
    if ($action === 'register_caller_id') {
        $formData = [
            'ownerName'         => sanitize($_POST['ownerName'] ?? ''),
            'businessName'      => sanitize($_POST['businessName'] ?? ''),
            'email'             => sanitize($_POST['email'] ?? ''),
            'phone'             => sanitize($_POST['phone'] ?? ''),
            'passportOrIdImage' => sanitize($_POST['passportOrIdImage'] ?? ''),
            'nid'               => sanitize($_POST['nid'] ?? ''),
            'dob'               => sanitize($_POST['dob'] ?? ''),
            'fullNo'            => sanitize($_POST['fullNo'] ?? ''),
            'doc'               => sanitize($_POST['doc'] ?? ''),
            'fatherName'        => sanitize($_POST['fatherName'] ?? ''),
            'countryChrCode'    => sanitize($_POST['countryChrCode'] ?? ''),
            'nameOrFirmName'    => sanitize($_POST['nameOrFirmName'] ?? ''),
            'stateOrVillage'    => sanitize($_POST['stateOrVillage'] ?? ''),
            'genderDivision'    => sanitize($_POST['genderDivision'] ?? ''),
            'district'          => sanitize($_POST['district'] ?? ''),
            'upazilaThan'       => sanitize($_POST['upazilaThan'] ?? ''),
            'postCode'          => sanitize($_POST['postCode'] ?? ''),
            'redirectingCallback' => sanitize($_POST['redirectingCallback'] ?? ''),
            'smsEnabled'        => sanitize($_POST['smsEnabled'] ?? 'No'),
            'callerPayload'     => sanitize($_POST['callerPayload'] ?? ''),
        ];

        // Handle file upload for signature
        if (!empty($_FILES['signature']['name']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK) {
            $formData['signature'] = new CURLFile(
                $_FILES['signature']['tmp_name'],
                $_FILES['signature']['type'] ?: 'image/jpeg',
                $_FILES['signature']['name']
            );
        } else {
            unset($formData['signature']); // Don't send empty file field
        }

        $result = mdApiCallFormData('/portal/callerid', $formData);
        $callerPhone = sanitize($_POST['phone'] ?? '');
        
        if (!empty($result['success']) || !empty($result['callerid'])) {
            try {
                $db->query("INSERT INTO md_caller_ids (label, caller_id, business_name, status, manydial_response) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE manydial_response=VALUES(manydial_response), updated_at=NOW()",
                    [$formData['businessName'] ?: $callerPhone, $callerPhone, $formData['businessName'], 'pending', json_encode($result)]);
            } catch (\Throwable $e) {}
            header('Location: ?tab=caller-ids&msg=Caller+ID+registration+submitted'); exit;
        } else {
            $msg = 'Registration failed: ' . ($result['message'] ?? $result['error'] ?? 'Unknown error');
            $msgType = 'error';
        }
    }

    // ── Create Call Automation ──
    if ($action === 'create_call_automation') {
        $callerId   = sanitize($_POST['caller_id'] ?? '');
        $phone      = sanitize($_POST['phone_number'] ?? '');
        $duration   = intval($_POST['per_call_duration'] ?? 5);
        $deliveryHook = sanitize($_POST['delivery_hook'] ?? '');
        $callPayload  = sanitize($_POST['call_payload'] ?? '');

        // Parse messages JSON
        $messagesJson = trim($_POST['messages_json'] ?? '');
        $messages = json_decode($messagesJson, true);
        if (!is_array($messages)) { $msg = 'Invalid messages JSON'; $msgType = 'error'; goto done; }

        // Parse buttons JSON (optional)
        $buttonsJson = trim($_POST['buttons_json'] ?? '');
        $buttons = $buttonsJson ? json_decode($buttonsJson, true) : null;

        $payload = [
            'callerId'       => $callerId,
            'phoneNumber'    => $phone,
            'perCallDuration'=> $duration,
            'messages'       => $messages,
        ];
        if ($deliveryHook) $payload['deliveryHook'] = $deliveryHook;
        if ($buttons)      $payload['buttons']      = $buttons;
        if ($callPayload)  $payload['callPayload']  = $callPayload;

        $result = mdApiCall('/portal/callIdDispatch', 'POST', $payload);

        // Log the attempt
        $custId = null; $orderId = null;
        // Try to find customer by phone
        try {
            $cust = $db->fetch("SELECT id FROM customers WHERE customer_phone LIKE ?", ['%'.preg_replace('/[^0-9]/','',$phone).'%']);
            $custId = $cust['id'] ?? null;
        } catch (\Throwable $e) {}

        try {
            $db->insert('md_call_logs', [
                'call_type'        => 'automation',
                'caller_id'        => $callerId,
                'phone_number'     => $phone,
                'customer_id'      => $custId,
                'per_call_duration'=> $duration,
                'call_payload'     => $callPayload ?: $deliveryHook, // store delivery hook as payload ref
                'status'           => (!empty($result['success'])) ? 'initiated' : 'failed',
                'delivery_hook_payload' => json_encode($result),
            ]);
        } catch (\Throwable $e) {}

        if (!empty($result['success'])) {
            header('Location: ?tab=call-logs&msg=Call+initiated+successfully'); exit;
        } else {
            $msg = 'Call failed: ' . ($result['message'] ?? $result['error'] ?? 'Unknown error');
            $msgType = 'error';
        }
    }

    // ── Save Call Template ──
    if ($action === 'save_template') {
        $data = [
            'name'             => sanitize($_POST['tpl_name'] ?? ''),
            'caller_id'        => sanitize($_POST['tpl_caller_id'] ?? ''),
            'per_call_duration'=> intval($_POST['tpl_duration'] ?? 5),
            'messages'         => $_POST['tpl_messages'] ?? '[]',
            'buttons'          => $_POST['tpl_buttons'] ?? null,
            'delivery_hook'    => sanitize($_POST['tpl_delivery_hook'] ?? ''),
            'call_payload'     => sanitize($_POST['tpl_call_payload'] ?? ''),
            'is_active'        => 1,
        ];
        $id = intval($_POST['tpl_id'] ?? 0);
        if ($id) {
            $db->update('md_call_templates', $data, 'id=?', [$id]);
        } else {
            $db->insert('md_call_templates', $data);
        }
        header('Location: ?tab=templates&msg=Template+saved'); exit;
    }

    // ── Delete Template ──
    if ($action === 'delete_template') {
        $db->delete('md_call_templates', 'id=?', [intval($_POST['tpl_id'] ?? 0)]);
        header('Location: ?tab=templates&msg=Template+deleted'); exit;
    }

    // ── Create Call Center ──
    if ($action === 'create_call_center') {
        $ccCallerId = trim(sanitize($_POST['cc_caller_id_manual'] ?? '')) ?: sanitize($_POST['cc_caller_id'] ?? '');
        $payload = [
            'callerId'    => $ccCallerId,
            'callPrefix'  => sanitize($_POST['cc_call_prefix'] ?? ''),
            'totalAgent'  => intval($_POST['cc_total_agents'] ?? 5),
            'statusHook'  => sanitize($_POST['cc_status_hook'] ?? ''),
            'endCallHook' => sanitize($_POST['cc_endcall_hook'] ?? ''),
            'redirectUrl' => sanitize($_POST['cc_redirect_url'] ?? ''),
            'domainUrl'   => sanitize($_POST['cc_domain_url'] ?? ''),
        ];
        $result = mdApiCall('/portal/call-center', 'POST', $payload);
        if (!empty($result['success'])) {
            header('Location: ?tab=call-center&msg=Call+Center+created'); exit;
        } else {
            $msg = 'Failed: ' . ($result['message'] ?? $result['error'] ?? 'Unknown error');
            $msgType = 'error';
        }
    }

    // ── Renew Call Center ──
    if ($action === 'renew_call_center') {
        $payload = [
            'callerId'   => sanitize($_POST['cc_caller_id'] ?? ''),
            'expireDate' => sanitize($_POST['cc_expire_date'] ?? ''),
        ];
        $result = mdApiCall('/portal/call-center/renew', 'POST', $payload);
        if (!empty($result['success'])) {
            header('Location: ?tab=call-center&msg=Call+Center+renewed'); exit;
        } else {
            $msg = 'Failed: ' . ($result['message'] ?? $result['error'] ?? 'Unknown');
            $msgType = 'error';
        }
    }

    // ── Create Agent ──
    if ($action === 'create_agent') {
        $payload = [
            'callerId'          => sanitize($_POST['ag_caller_id'] ?? ''),
            'name'              => sanitize($_POST['ag_name'] ?? ''),
            'email'             => sanitize($_POST['ag_email'] ?? ''),
            'phone'             => sanitize($_POST['ag_phone'] ?? ''),
            'password'          => $_POST['ag_password'] ?? '',
            'callDirection'     => sanitize($_POST['ag_direction'] ?? 'both'),
            'isAutoCallConnect' => ($_POST['ag_auto_connect'] ?? 'true') === 'true',
            'phoneType'         => sanitize($_POST['ag_phone_type'] ?? 'WEBPHONE'),
            'expiresOn'         => sanitize($_POST['ag_expires'] ?? date('Y-m-d', strtotime('+1 year'))),
        ];
        $result = mdApiCall('/portal/agent-request', 'POST', $payload);
        if (!empty($result['success'])) {
            // Save locally
            try {
                $db->insert('md_agents', [
                    'caller_id'   => $payload['callerId'],
                    'name'        => $payload['name'],
                    'email'       => $payload['email'],
                    'phone'       => $payload['phone'],
                    'call_direction' => $payload['callDirection'],
                    'auto_connect'   => $payload['isAutoCallConnect'] ? 1 : 0,
                    'phone_type'     => $payload['phoneType'],
                    'expires_at'     => $payload['expiresOn'],
                    'status'         => 'active',
                ]);
            } catch (\Throwable $e) {}
            header('Location: ?tab=agents&msg=Agent+created'); exit;
        } else {
            $msg = 'Agent creation failed: ' . ($result['message'] ?? $result['error'] ?? 'Unknown');
            $msgType = 'error';
        }
    }

    // ── Delete Agent ──
    if ($action === 'delete_agent') {
        $callerId = sanitize($_POST['ag_caller_id'] ?? '');
        $email    = sanitize($_POST['ag_email'] ?? '');
        $agentId  = intval($_POST['ag_id'] ?? 0);
        $localId  = intval($_POST['ag_local_id'] ?? 0);
        $url = '/portal/agents/delete?' . http_build_query(['email'=>$email,'callerId'=>$callerId]);
        $result = mdApiCall($url, 'DELETE');
        if (!empty($result['success'])) {
            if ($localId) $db->update('md_agents', ['status'=>'deleted'], 'id=?', [$localId]);
            header('Location: ?tab=agents&msg=Agent+deleted'); exit;
        } else {
            $msg = 'Delete failed: ' . ($result['message'] ?? $result['error'] ?? 'Unknown');
            $msgType = 'error';
        }
    }

    // ── Click to Call ──
    if ($action === 'click_to_call') {
        $callerId = sanitize($_POST['ctc_caller_id'] ?? '');
        $email    = sanitize($_POST['ctc_email'] ?? '');
        $number   = sanitize($_POST['ctc_number'] ?? '');
        $payload  = sanitize($_POST['ctc_payload'] ?? '');

        $data = ['callerId'=>$callerId,'email'=>$email,'number'=>$number];
        if ($payload) $data['payload'] = $payload;

        $result = mdApiCall('/portal/click-to-call', 'POST', $data);

        // Log it
        try {
            $db->insert('md_call_logs', [
                'call_type'  => 'click_to_call',
                'caller_id'  => $callerId,
                'phone_number' => $number,
                'agent_email'  => $email,
                'call_payload' => $payload,
                'status'       => (!empty($result['success'])) ? 'initiated' : 'failed',
                'delivery_hook_payload' => json_encode($result),
            ]);
        } catch (\Throwable $e) {}

        header('Content-Type: application/json');
        echo json_encode($result); exit;
    }

    done:
}

// ── Fetch data for display ────────────────────────────────────────────────
$mdApiKey     = mdSetting('md_api_key');
$mdDeliveryHook = mdSetting('md_delivery_hook');
$mdDefaultCaller = mdSetting('md_default_caller_id');
$mdDefaultDuration = mdSetting('md_default_duration', 5);

$callerIds    = [];
$callLogs     = [];
$agents       = [];
$templates    = [];

try { $callerIds = $db->fetchAll("SELECT * FROM md_caller_ids ORDER BY created_at DESC"); } catch (\Throwable $e) {}
try { $callLogs  = $db->fetchAll("SELECT l.*, c.customer_name as cname, o.order_number FROM md_call_logs l LEFT JOIN customers c ON c.id=l.customer_id LEFT JOIN orders o ON o.id=l.order_id ORDER BY l.created_at DESC LIMIT 200"); } catch (\Throwable $e) {}
try { $agents    = $db->fetchAll("SELECT * FROM md_agents WHERE status != 'deleted' ORDER BY created_at DESC"); } catch (\Throwable $e) {}
try { $templates = $db->fetchAll("SELECT * FROM md_call_templates WHERE is_active=1 ORDER BY created_at DESC"); } catch (\Throwable $e) {}

// Stats
$totalCalls    = count($callLogs);
$todayCalls    = count(array_filter($callLogs, fn($l) => date('Y-m-d', strtotime($l['created_at'])) === date('Y-m-d')));
$successCalls  = count(array_filter($callLogs, fn($l) => in_array($l['status'], ['answered','completed','initiated'])));
$failedCalls   = count(array_filter($callLogs, fn($l) => in_array($l['status'], ['failed','missed','busy','no_answer'])));

require_once __DIR__ . '/../includes/header.php';
$urlMsg = $_GET['msg'] ?? '';
?>

<style>
:root { --md-primary:#6366f1; --md-accent:#f59e0b; --md-success:#10b981; --md-danger:#ef4444; }
.md-tab-btn { padding:8px 18px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; transition:.15s; border:1.5px solid transparent; white-space:nowrap; }
.md-tab-btn.active { background:var(--md-primary); color:#fff; }
.md-tab-btn:not(.active) { background:#f8fafc; color:#64748b; border-color:#e2e8f0; }
.md-tab-btn:not(.active):hover { background:#f1f5f9; color:#374151; }
.md-card { background:#fff; border-radius:14px; border:1.5px solid #e2e8f0; overflow:hidden; }
.md-badge { display:inline-flex; align-items:center; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.md-badge.pending  { background:#fef3c7; color:#92400e; }
.md-badge.approved { background:#d1fae5; color:#065f46; }
.md-badge.rejected { background:#fee2e2; color:#991b1b; }
.md-badge.initiated{ background:#e0e7ff; color:#3730a3; }
.md-badge.answered { background:#d1fae5; color:#065f46; }
.md-badge.failed   { background:#fee2e2; color:#991b1b; }
.md-badge.missed   { background:#fef3c7; color:#92400e; }
.md-input { width:100%; padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:13px; outline:none; transition:border-color .15s; }
.md-input:focus { border-color:var(--md-primary); box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.md-btn { padding:8px 18px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; border:none; transition:.15s; }
.md-btn-primary { background:var(--md-primary); color:#fff; }
.md-btn-primary:hover { background:#4f46e5; }
.md-btn-success { background:var(--md-success); color:#fff; }
.md-btn-danger  { background:var(--md-danger); color:#fff; }
.md-btn-secondary { background:#f1f5f9; color:#374151; border:1.5px solid #e2e8f0; }
.md-label { font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; display:block; }
.md-section-title { font-size:14px; font-weight:700; color:#1e293b; border-bottom:2px solid #f1f5f9; padding-bottom:8px; margin-bottom:16px; }
.stat-card { background:#fff; border-radius:12px; border:1.5px solid #e2e8f0; padding:16px 20px; }
.json-editor { font-family:'Courier New',monospace; font-size:12px; background:#0f172a; color:#e2e8f0; border-radius:8px; padding:12px; resize:vertical; min-height:120px; border:none; width:100%; }
</style>

<div class="p-4 max-w-screen-xl mx-auto">

<?php if ($urlMsg): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm flex items-center gap-2">
  <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
  <?= htmlspecialchars(urldecode($urlMsg)) ?>
</div>
<?php endif; ?>
<?php if ($msg): ?>
<div class="<?= $msgType==='error'?'bg-red-50 border-red-200 text-red-700':'bg-green-50 border-green-200 text-green-700' ?> border px-4 py-3 rounded-lg mb-4 text-sm">
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<?php if (!$mdApiKey && $tab !== 'settings'): ?>
<div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 mb-4 flex items-start gap-3">
  <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5C2.962 18.333 3.924 20 5.464 20z"/></svg>
  <div>
    <p class="text-sm font-semibold text-amber-800">ManyDial API key not configured</p>
    <p class="text-xs text-amber-600 mt-0.5">Go to <a href="?tab=settings" class="underline font-medium">Settings</a> to add your API key and start using ManyDial features.</p>
  </div>
</div>
<?php endif; ?>

<!-- Header -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
  <div>
    <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
      <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
      ManyDial Call Center
    </h1>
    <p class="text-xs text-gray-400 mt-0.5">Automated calls · Call center · Click to call · Caller ID management</p>
  </div>
  <?php if ($mdApiKey): ?>
  <div class="flex items-center gap-2 px-3 py-1.5 bg-green-50 border border-green-200 rounded-lg text-xs text-green-700 font-semibold">
    <span class="w-2 h-2 rounded-full bg-green-500"></span>
    API Connected
  </div>
  <?php endif; ?>
</div>

<!-- Stats row -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
  <div class="stat-card"><p class="text-2xl font-bold text-gray-900"><?= $totalCalls ?></p><p class="text-xs text-gray-400 mt-0.5">Total Calls</p></div>
  <div class="stat-card"><p class="text-2xl font-bold text-indigo-600"><?= $todayCalls ?></p><p class="text-xs text-gray-400 mt-0.5">Today</p></div>
  <div class="stat-card"><p class="text-2xl font-bold text-green-600"><?= $successCalls ?></p><p class="text-xs text-gray-400 mt-0.5">Successful</p></div>
  <div class="stat-card"><p class="text-2xl font-bold text-red-500"><?= $failedCalls ?></p><p class="text-xs text-gray-400 mt-0.5">Failed/Missed</p></div>
</div>

<!-- Tabs -->
<div class="flex flex-wrap gap-2 mb-5 overflow-x-auto pb-1">
  <?php
  $tabs = [
    'dashboard'   => ['📊','Dashboard'],
    'call-automation'=>['📞','Call Automation'],
    'click-to-call'=>['🖱','Click to Call'],
    'call-center' => ['🏢','Call Center'],
    'agents'      => ['👥','Agents'],
    'caller-ids'  => ['🪪','Caller IDs'],
    'templates'   => ['📋','Templates'],
    'call-logs'   => ['📜','Call Logs'],
    'settings'    => ['⚙️','Settings'],
  ];
  foreach ($tabs as $key => [$icon,$label]):
  ?>
  <a href="?tab=<?= $key ?>" class="md-tab-btn <?= $tab===$key?'active':'' ?>">
    <?= $icon ?> <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ══════════════ DASHBOARD ══════════════ -->
<?php if ($tab === 'dashboard'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Recent calls -->
  <div class="md-card">
    <div class="px-5 py-3 border-b bg-gray-50 font-semibold text-sm text-gray-700">Recent Calls</div>
    <div class="overflow-x-auto">
      <table class="w-full text-xs">
        <thead><tr class="bg-gray-50 border-b">
          <th class="px-4 py-2 text-left text-gray-500">Number</th>
          <th class="px-4 py-2 text-left text-gray-500">Type</th>
          <th class="px-4 py-2 text-left text-gray-500">Status</th>
          <th class="px-4 py-2 text-left text-gray-500">Time</th>
        </tr></thead>
        <tbody>
          <?php foreach (array_slice($callLogs, 0, 10) as $log): ?>
          <tr class="border-b hover:bg-gray-50">
            <td class="px-4 py-2 font-mono"><?= e($log['phone_number']) ?></td>
            <td class="px-4 py-2"><?= ucwords(str_replace('_',' ',$log['call_type'])) ?></td>
            <td class="px-4 py-2"><span class="md-badge <?= $log['status'] ?>"><?= $log['status'] ?></span></td>
            <td class="px-4 py-2 text-gray-400"><?= date('d M H:i', strtotime($log['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($callLogs)): ?>
          <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No calls yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Quick actions -->
  <div class="space-y-4">
    <div class="md-card p-5">
      <h3 class="md-section-title">Quick Call</h3>
      <form method="POST" class="space-y-3">
        <input type="hidden" name="action" value="create_call_automation">
        <div>
          <label class="md-label">Caller ID</label>
          <select name="caller_id" class="md-input" required>
            <option value="">Select...</option>
            <?php foreach ($callerIds as $cid): ?>
            <option value="<?= e($cid['caller_id']) ?>" <?= $cid['caller_id']===$mdDefaultCaller?'selected':'' ?>>
              <?= e($cid['label'] ?: $cid['caller_id']) ?>
            </option>
            <?php endforeach; ?>
            <?php if ($mdDefaultCaller && empty($callerIds)): ?>
            <option value="<?= e($mdDefaultCaller) ?>" selected><?= e($mdDefaultCaller) ?></option>
            <?php endif; ?>
          </select>
        </div>
        <div>
          <label class="md-label">Phone Number to Call</label>
          <input type="text" name="phone_number" class="md-input" placeholder="+8801XXXXXXXXX" required>
        </div>
        <div>
          <label class="md-label">Duration per call (minutes)</label>
          <input type="number" name="per_call_duration" value="<?= $mdDefaultDuration ?>" min="1" max="30" class="md-input">
        </div>
        <div>
          <label class="md-label">Messages (JSON)</label>
          <textarea name="messages_json" class="json-editor" rows="6" required placeholder='[{"welcome":"Hello, this is a test call.","repeat":1,"sms":"Thank you for calling.","forward":"+880XXXXXXXXX","menuMessage1":"Press 1 for orders, Press 2 to cancel.","repeat1":2,"noCall1":"no","noCall1Message":"Our team will contact you."}]'></textarea>
          <p class="text-[10px] text-gray-400 mt-1">JSON array. See <a href="?tab=call-automation" class="text-indigo-500">Call Automation</a> for full structure.</p>
        </div>
        <input type="hidden" name="delivery_hook" value="<?= e($mdDeliveryHook) ?>">
        <button type="submit" class="md-btn md-btn-primary w-full">📞 Initiate Call</button>
      </form>
    </div>

    <!-- Caller IDs summary -->
    <div class="md-card p-5">
      <h3 class="md-section-title">Caller IDs (<?= count($callerIds) ?>)</h3>
      <div class="space-y-2">
        <?php foreach (array_slice($callerIds, 0, 5) as $cid): ?>
        <div class="flex items-center justify-between text-xs">
          <span class="font-mono text-gray-700"><?= e($cid['caller_id']) ?></span>
          <span class="md-badge <?= $cid['status'] ?>"><?= $cid['status'] ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($callerIds)): ?>
        <p class="text-xs text-gray-400 text-center py-2">No caller IDs registered yet</p>
        <?php endif; ?>
        <a href="?tab=caller-ids" class="block text-center text-xs text-indigo-500 hover:text-indigo-700 mt-2">Manage Caller IDs →</a>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════ CALL AUTOMATION ══════════════ -->
<?php elseif ($tab === 'call-automation'): ?>
<div class="grid grid-cols-1 xl:grid-cols-5 gap-5">
  <!-- Form -->
  <div class="xl:col-span-3 md-card p-5">
    <h2 class="md-section-title">Trigger Call Automation</h2>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="create_call_automation">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="md-label">Caller ID *</label>
          <select name="caller_id" class="md-input" required>
            <option value="">Select Caller ID...</option>
            <?php foreach ($callerIds as $cid): ?>
            <option value="<?= e($cid['caller_id']) ?>" <?= $cid['caller_id']===$mdDefaultCaller?'selected':'' ?>>
              <?= e($cid['label'] ?: $cid['caller_id']) ?>
            </option>
            <?php endforeach; ?>
            <?php if ($mdDefaultCaller): ?><option value="<?= e($mdDefaultCaller) ?>">Default: <?= e($mdDefaultCaller) ?></option><?php endif; ?>
          </select>
        </div>
        <div>
          <label class="md-label">Phone Number *</label>
          <input type="text" name="phone_number" class="md-input" placeholder="+8801XXXXXXXXX" required>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="md-label">Per Call Duration (min)</label>
          <input type="number" name="per_call_duration" value="5" min="1" max="30" class="md-input">
        </div>
        <div>
          <label class="md-label">Delivery Hook (webhook URL)</label>
          <input type="url" name="delivery_hook" value="<?= e($mdDeliveryHook) ?>" class="md-input" placeholder="https://your-site.com/webhook">
        </div>
      </div>
      <div>
        <label class="md-label">Custom Payload (optional)</label>
        <input type="text" name="call_payload" class="md-input" placeholder='{"orderId":"123","customer":"John"}'>
      </div>

      <!-- Messages JSON builder -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="md-label mb-0">Messages JSON *</label>
          <button type="button" onclick="loadSampleMessages()" class="text-xs text-indigo-500 hover:text-indigo-700">Load Sample</button>
        </div>
        <textarea name="messages_json" id="messagesJson" class="json-editor" rows="12" required></textarea>
        <p class="text-[10px] text-gray-400 mt-1">Must be a valid JSON array. Use the structure reference on the right.</p>
      </div>

      <!-- Buttons JSON (optional) -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="md-label mb-0">Buttons JSON (optional)</label>
          <button type="button" onclick="loadSampleButtons()" class="text-xs text-indigo-500 hover:text-indigo-700">Load Sample</button>
        </div>
        <textarea name="buttons_json" id="buttonsJson" class="json-editor" rows="6"></textarea>
      </div>

      <div class="flex gap-3 pt-2">
        <button type="submit" class="md-btn md-btn-primary flex-1">📞 Initiate Call Automation</button>
        <button type="button" onclick="validateJson()" class="md-btn md-btn-secondary">✓ Validate JSON</button>
      </div>
    </form>
  </div>

  <!-- Reference -->
  <div class="xl:col-span-2 space-y-4">
    <div class="md-card p-5">
      <h3 class="md-section-title">Messages Object Structure</h3>
      <div class="space-y-2 text-xs">
        <?php
        $fields = [
          ['welcome','Welcome message when call is answered'],
          ['repeat','Times to repeat the welcome (default:1)'],
          ['sms','SMS sent when customer answers'],
          ['forward','Forward number after welcome message'],
          ['menuMessage1','Prompt for press 1'],
          ['repeat1','Repeat count for menuMessage1'],
          ['noCall1','Send SMS after press 1 (yes/no)'],
          ['noCall1Message','SMS content for press 1'],
          ['menuMessage2','Prompt for press 2'],
          ['repeat2','Repeat count for menuMessage2'],
          ['noCall2','Send SMS after press 2 (yes/no)'],
          ['noCall2Message','SMS for press 2'],
          ['smsct1','SMS when customer presses 1 again'],
          ['noCall3','Send SMS after press 3'],
          ['smsMessage2','SMS after menuMessage2 played'],
        ];
        foreach ($fields as [$key,$desc]): ?>
        <div class="flex gap-2">
          <code class="text-indigo-400 bg-slate-800 px-1.5 py-0.5 rounded text-[10px] flex-shrink-0"><?= $key ?></code>
          <span class="text-gray-500"><?= $desc ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="md-card p-5">
      <h3 class="md-section-title">Delivery Hook Response</h3>
      <div class="space-y-1.5 text-xs text-gray-500">
        <div><code class="text-indigo-400 text-[10px]">callbackhook</code> — Your reference string</div>
        <div><code class="text-indigo-400 text-[10px]">callerid</code> — The caller ID used</div>
        <div><code class="text-indigo-400 text-[10px]">number</code> — Called number</div>
        <div><code class="text-indigo-400 text-[10px]">buttons</code> — Options pressed by user</div>
        <div><code class="text-indigo-400 text-[10px]">userResponse</code> — Sequence of presses</div>
        <div><code class="text-indigo-400 text-[10px]">actions</code> — All actions taken</div>
        <div><code class="text-indigo-400 text-[10px]">sms</code> — SMS messages sent</div>
        <div><code class="text-indigo-400 text-[10px]">duration</code> — Call duration (ms)</div>
        <div><code class="text-indigo-400 text-[10px]">status</code> — NO_ANSWER / ANSWER / ELSE</div>
        <div><code class="text-indigo-400 text-[10px]">forwardNumber</code> — Forward destination</div>
        <div><code class="text-indigo-400 text-[10px]">recordAudioUrl</code> — Recording URL</div>
      </div>
    </div>

    <!-- Quick template loader -->
    <?php if ($templates): ?>
    <div class="md-card p-5">
      <h3 class="md-section-title">Load from Template</h3>
      <div class="space-y-2">
        <?php foreach ($templates as $tpl): ?>
        <button type="button" onclick="loadTemplate(<?= htmlspecialchars(json_encode($tpl)) ?>)"
          class="w-full text-left px-3 py-2 bg-gray-50 hover:bg-indigo-50 rounded-lg text-xs font-medium text-gray-700 border border-gray-200 transition">
          📋 <?= e($tpl['name']) ?> <span class="text-gray-400 font-normal">(<?= e($tpl['caller_id']) ?>)</span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ══════════════ CLICK TO CALL ══════════════ -->
<?php elseif ($tab === 'click-to-call'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <div class="md-card p-5">
    <h2 class="md-section-title">Click to Call</h2>
    <p class="text-xs text-gray-500 mb-4">Initiate a call from an agent to a customer number using the registered Caller ID and agent email.</p>
    <div id="ctcResult" class="hidden mb-4 p-3 rounded-lg text-sm"></div>
    <form id="ctcForm" class="space-y-4">
      <input type="hidden" name="action" value="click_to_call">
      <div>
        <label class="md-label">Caller ID *</label>
        <select name="ctc_caller_id" class="md-input" required>
          <option value="">Select Caller ID...</option>
          <?php foreach ($callerIds as $cid): ?>
          <option value="<?= e($cid['caller_id']) ?>" <?= $cid['caller_id']===$mdDefaultCaller?'selected':'' ?>>
            <?= e($cid['label'] ?: $cid['caller_id']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="md-label">Agent Email *</label>
        <select name="ctc_email" class="md-input">
          <option value="">Select Agent...</option>
          <?php foreach ($agents as $ag): ?>
          <option value="<?= e($ag['email']) ?>"><?= e($ag['name']) ?> (<?= e($ag['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <p class="text-[10px] text-gray-400 mt-1">Or type manually:</p>
        <input type="email" name="ctc_email_manual" class="md-input mt-1" placeholder="agent@example.com" oninput="this.previousElementSibling.previousElementSibling.value=''">
      </div>
      <div>
        <label class="md-label">Customer Number * <span class="text-gray-400 font-normal">(start with 01 not +88)</span></label>
        <input type="text" name="ctc_number" class="md-input" placeholder="01934567890" required>
      </div>
      <div>
        <label class="md-label">Custom Payload (optional)</label>
        <input type="text" name="ctc_payload" class="md-input" placeholder='{"orderId":"123","customer":"John Doe"}'>
      </div>
      <button type="button" onclick="doClickToCall()" class="md-btn md-btn-primary w-full">
        📞 Initiate Click to Call
      </button>
    </form>
  </div>

  <div class="space-y-4">
    <div class="md-card p-5">
      <h3 class="md-section-title">How it works</h3>
      <div class="space-y-3 text-sm text-gray-600">
        <div class="flex gap-3">
          <span class="w-6 h-6 bg-indigo-100 text-indigo-700 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-bold">1</span>
          <p>Agent selects a customer from the call center interface or this form</p>
        </div>
        <div class="flex gap-3">
          <span class="w-6 h-6 bg-indigo-100 text-indigo-700 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-bold">2</span>
          <p>ManyDial calls the agent first via the registered call center</p>
        </div>
        <div class="flex gap-3">
          <span class="w-6 h-6 bg-indigo-100 text-indigo-700 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-bold">3</span>
          <p>Once agent picks up, ManyDial dials the customer number</p>
        </div>
        <div class="flex gap-3">
          <span class="w-6 h-6 bg-indigo-100 text-indigo-700 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-bold">4</span>
          <p>Call is bridged — agent and customer are connected</p>
        </div>
      </div>
    </div>

    <!-- Recent click-to-call logs -->
    <div class="md-card">
      <div class="px-5 py-3 border-b text-sm font-semibold text-gray-700">Recent Click-to-Call</div>
      <div class="divide-y">
        <?php
        $ctcLogs = array_filter($callLogs, fn($l) => $l['call_type'] === 'click_to_call');
        foreach (array_slice($ctcLogs, 0, 5) as $log): ?>
        <div class="px-4 py-2.5 flex items-center justify-between text-xs">
          <div>
            <div class="font-mono font-medium"><?= e($log['phone_number']) ?></div>
            <div class="text-gray-400"><?= e($log['agent_email']) ?></div>
          </div>
          <div class="text-right">
            <span class="md-badge <?= $log['status'] ?>"><?= $log['status'] ?></span>
            <div class="text-gray-400 mt-0.5"><?= date('d M H:i', strtotime($log['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($ctcLogs)): ?>
        <div class="px-4 py-6 text-center text-xs text-gray-400">No click-to-call records</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════ CALL CENTER MANAGEMENT ══════════════ -->
<?php elseif ($tab === 'call-center'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Create Call Center -->
  <div class="md-card p-5">
    <h2 class="md-section-title">Create Call Center</h2>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="create_call_center">
      <div>
        <label class="md-label">Caller ID * <span class="text-gray-400 font-normal">(+XXXXXXXXXXXXX)</span></label>
        <select name="cc_caller_id" class="md-input" required onchange="document.getElementById('ccCallerIdInput').value=this.value">
          <option value="">Select...</option>
          <?php foreach ($callerIds as $cid): ?>
          <option value="<?= e($cid['caller_id']) ?>"><?= e($cid['label'] ?: $cid['caller_id']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" id="ccCallerIdInput" name="cc_caller_id_manual" class="md-input mt-1" placeholder="Or type: +8809600000000" oninput="this.previousElementSibling.value=''">
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="md-label">Call Prefix</label>
          <input type="text" name="cc_call_prefix" class="md-input" placeholder="000" value="000">
        </div>
        <div>
          <label class="md-label">Max Agents</label>
          <input type="number" name="cc_total_agents" class="md-input" value="5" min="1">
        </div>
      </div>
      <div>
        <label class="md-label">Status Hook URL</label>
        <input type="url" name="cc_status_hook" class="md-input" placeholder="https://your-site.com/cc-status">
      </div>
      <div>
        <label class="md-label">End Call Hook URL</label>
        <input type="url" name="cc_endcall_hook" class="md-input" placeholder="https://your-site.com/cc-endcall">
      </div>
      <div>
        <label class="md-label">Redirect URL (on new agent)</label>
        <input type="url" name="cc_redirect_url" class="md-input" placeholder="https://your-site.com/call-center">
      </div>
      <div>
        <label class="md-label">Domain URL</label>
        <input type="url" name="cc_domain_url" class="md-input" placeholder="https://your-site.com/call-center">
      </div>
      <button type="submit" class="md-btn md-btn-primary w-full">🏢 Create Call Center</button>
    </form>
  </div>

  <!-- Renew + iFrame embed -->
  <div class="space-y-4">
    <div class="md-card p-5">
      <h2 class="md-section-title">Renew Call Center</h2>
      <form method="POST" class="space-y-4">
        <input type="hidden" name="action" value="renew_call_center">
        <div>
          <label class="md-label">Caller ID</label>
          <select name="cc_caller_id" class="md-input">
            <?php foreach ($callerIds as $cid): ?>
            <option value="<?= e($cid['caller_id']) ?>"><?= e($cid['label'] ?: $cid['caller_id']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="md-label">New Expire Date (YYYY-MM-DD)</label>
          <input type="date" name="cc_expire_date" class="md-input" value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
        </div>
        <button type="submit" class="md-btn md-btn-success w-full">🔄 Renew Call Center</button>
      </form>
    </div>

    <div class="md-card p-5">
      <h3 class="md-section-title">Embed Call Center iFrame</h3>
      <p class="text-xs text-gray-500 mb-3">Embed the ManyDial call center directly into your admin panel.</p>
      <div>
        <label class="md-label">Agent Email</label>
        <input type="email" id="iframeEmail" class="md-input" placeholder="agent@example.com">
      </div>
      <div class="mt-3">
        <label class="md-label">Caller ID</label>
        <select id="iframeCallerId" class="md-input">
          <?php foreach ($callerIds as $cid): ?>
          <option value="<?= e($cid['caller_id']) ?>"><?= e($cid['caller_id']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="button" onclick="loadCCIframe()" class="md-btn md-btn-primary mt-3 w-full">Load Call Center</button>
      <div id="ccIframeWrap" class="hidden mt-4">
        <iframe id="ccIframe" style="width:100%;height:600px;border:1.5px solid #e2e8f0;border-radius:10px;" src="about:blank"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════ AGENTS ══════════════ -->
<?php elseif ($tab === 'agents'): ?>
<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
  <!-- Agent form -->
  <div class="md-card p-5">
    <h2 class="md-section-title">Add Agent</h2>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="create_agent">
      <div>
        <label class="md-label">Caller ID *</label>
        <select name="ag_caller_id" class="md-input" required>
          <?php foreach ($callerIds as $cid): ?>
          <option value="<?= e($cid['caller_id']) ?>"><?= e($cid['label'] ?: $cid['caller_id']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="md-label">Name *</label>
        <input type="text" name="ag_name" class="md-input" placeholder="John Doe" required>
      </div>
      <div>
        <label class="md-label">Email *</label>
        <input type="email" name="ag_email" class="md-input" placeholder="agent@example.com" required>
      </div>
      <div>
        <label class="md-label">Phone *</label>
        <input type="text" name="ag_phone" class="md-input" placeholder="+8801XXXXXXXXX">
      </div>
      <div>
        <label class="md-label">Password *</label>
        <input type="password" name="ag_password" class="md-input" placeholder="••••••••" required>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="md-label">Direction</label>
          <select name="ag_direction" class="md-input">
            <option value="both">Both</option>
            <option value="inbound">Inbound</option>
            <option value="outbound">Outbound</option>
          </select>
        </div>
        <div>
          <label class="md-label">Phone Type</label>
          <select name="ag_phone_type" class="md-input">
            <option value="WEBPHONE">Web Phone</option>
            <option value="CELLPHONE">Cell Phone</option>
            <option value="SOFTPHONE">Soft Phone</option>
          </select>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="md-label">Auto Connect</label>
          <select name="ag_auto_connect" class="md-input">
            <option value="true">Yes</option>
            <option value="false">No</option>
          </select>
        </div>
        <div>
          <label class="md-label">Expires On</label>
          <input type="date" name="ag_expires" class="md-input" value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
        </div>
      </div>
      <button type="submit" class="md-btn md-btn-primary w-full">👤 Create Agent</button>
    </form>
  </div>

  <!-- Agent list -->
  <div class="xl:col-span-2 md-card">
    <div class="px-5 py-3 border-b font-semibold text-sm text-gray-700">
      Agents (<?= count($agents) ?>)
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-xs">
        <thead><tr class="bg-gray-50 border-b">
          <th class="px-4 py-2 text-left">Name</th>
          <th class="px-4 py-2 text-left">Email</th>
          <th class="px-4 py-2 text-left">Caller ID</th>
          <th class="px-4 py-2 text-left">Type</th>
          <th class="px-4 py-2 text-left">Expires</th>
          <th class="px-4 py-2 text-left">Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($agents as $ag): ?>
          <tr class="border-b hover:bg-gray-50">
            <td class="px-4 py-2 font-medium"><?= e($ag['name']) ?></td>
            <td class="px-4 py-2 text-gray-500"><?= e($ag['email']) ?></td>
            <td class="px-4 py-2 font-mono text-[11px]"><?= e($ag['caller_id']) ?></td>
            <td class="px-4 py-2"><?= e($ag['phone_type']) ?></td>
            <td class="px-4 py-2 <?= $ag['expires_at'] && $ag['expires_at'] < date('Y-m-d') ? 'text-red-500 font-medium' : 'text-gray-500' ?>">
              <?= $ag['expires_at'] ? date('d M Y', strtotime($ag['expires_at'])) : '—' ?>
            </td>
            <td class="px-4 py-2">
              <form method="POST" class="inline" onsubmit="return confirm('Delete this agent?')">
                <input type="hidden" name="action" value="delete_agent">
                <input type="hidden" name="ag_caller_id" value="<?= e($ag['caller_id']) ?>">
                <input type="hidden" name="ag_email" value="<?= e($ag['email']) ?>">
                <input type="hidden" name="ag_local_id" value="<?= $ag['id'] ?>">
                <button type="submit" class="text-red-500 hover:text-red-700 text-[10px] font-medium px-2 py-1 rounded border border-red-200 hover:bg-red-50">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($agents)): ?>
          <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No agents yet. Create your first agent above.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════ CALLER IDs ══════════════ -->
<?php elseif ($tab === 'caller-ids'): ?>
<div class="grid grid-cols-1 xl:grid-cols-5 gap-5">
  <!-- Registration form -->
  <div class="xl:col-span-2 md-card p-5">
    <h2 class="md-section-title">Register New Caller ID</h2>
    <form method="POST" enctype="multipart/form-data" class="space-y-3">
      <input type="hidden" name="action" value="register_caller_id">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="md-label">Owner Name *</label>
          <input type="text" name="ownerName" class="md-input" required placeholder="Full name">
        </div>
        <div>
          <label class="md-label">Business Name *</label>
          <input type="text" name="businessName" class="md-input" required placeholder="Firm name">
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="md-label">Email *</label>
          <input type="email" name="email" class="md-input" required>
        </div>
        <div>
          <label class="md-label">Phone (Caller ID) *</label>
          <input type="text" name="phone" class="md-input" required placeholder="+8809600000000">
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="md-label">NID / Passport No *</label>
          <input type="text" name="nid" class="md-input" required>
        </div>
        <div>
          <label class="md-label">Date of Birth *</label>
          <input type="date" name="dob" class="md-input" required>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="md-label">Father's Name *</label>
          <input type="text" name="fatherName" class="md-input" required>
        </div>
        <div>
          <label class="md-label">Gender *</label>
          <select name="genderDivision" class="md-input" required>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="md-label">Country Code (Chr)</label>
          <input type="text" name="countryChrCode" class="md-input" value="BD" placeholder="BD">
        </div>
        <div>
          <label class="md-label">Full Address No</label>
          <input type="text" name="fullNo" class="md-input" placeholder="Flat 2">
        </div>
      </div>
      <div>
        <label class="md-label">Doc (Address)</label>
        <input type="text" name="doc" class="md-input" placeholder="Street address">
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="md-label">Name/Firm</label>
          <input type="text" name="nameOrFirmName" class="md-input" placeholder="User 1">
        </div>
        <div>
          <label class="md-label">State/Village</label>
          <input type="text" name="stateOrVillage" class="md-input" placeholder="Dhaka">
        </div>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="md-label">District</label>
          <input type="text" name="district" class="md-input" placeholder="Dhaka">
        </div>
        <div>
          <label class="md-label">Upazila</label>
          <input type="text" name="upazilaThan" class="md-input" placeholder="Savar">
        </div>
        <div>
          <label class="md-label">Post Code</label>
          <input type="text" name="postCode" class="md-input" placeholder="012">
        </div>
      </div>
      <div>
        <label class="md-label">Passport/ID Image URL</label>
        <input type="text" name="passportOrIdImage" class="md-input" placeholder="https://...">
      </div>
      <div>
        <label class="md-label">Signature (image file, optional)</label>
        <input type="file" name="signature" class="md-input py-1.5" accept="image/*">
      </div>
      <div>
        <label class="md-label">Redirect Callback URL</label>
        <input type="url" name="redirectingCallback" class="md-input" placeholder="https://your-site.com/callback">
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="md-label">SMS Enabled</label>
          <select name="smsEnabled" class="md-input">
            <option value="No">No</option>
            <option value="Yes">Yes</option>
          </select>
        </div>
        <div>
          <label class="md-label">Caller Payload</label>
          <input type="text" name="callerPayload" class="md-input" placeholder="Sample payload">
        </div>
      </div>
      <button type="submit" class="md-btn md-btn-primary w-full">🪪 Submit Registration</button>
    </form>
  </div>

  <!-- Existing caller IDs -->
  <div class="xl:col-span-3 md-card">
    <div class="px-5 py-3 border-b font-semibold text-sm text-gray-700">Registered Caller IDs (<?= count($callerIds) ?>)</div>
    <div class="overflow-x-auto">
      <table class="w-full text-xs">
        <thead><tr class="bg-gray-50 border-b">
          <th class="px-4 py-2 text-left">Label</th>
          <th class="px-4 py-2 text-left">Caller ID</th>
          <th class="px-4 py-2 text-left">Business</th>
          <th class="px-4 py-2 text-left">Status</th>
          <th class="px-4 py-2 text-left">Registered</th>
        </tr></thead>
        <tbody>
          <?php foreach ($callerIds as $cid): ?>
          <tr class="border-b hover:bg-gray-50">
            <td class="px-4 py-2 font-medium"><?= e($cid['label']) ?></td>
            <td class="px-4 py-2 font-mono text-[11px]"><?= e($cid['caller_id']) ?></td>
            <td class="px-4 py-2 text-gray-500"><?= e($cid['business_name']) ?></td>
            <td class="px-4 py-2"><span class="md-badge <?= $cid['status'] ?>"><?= $cid['status'] ?></span></td>
            <td class="px-4 py-2 text-gray-400"><?= date('d M Y', strtotime($cid['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($callerIds)): ?>
          <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No caller IDs registered. Use the form to register one.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════ TEMPLATES ══════════════ -->
<?php elseif ($tab === 'templates'): ?>
<div class="grid grid-cols-1 xl:grid-cols-5 gap-5">
  <div class="xl:col-span-2 md-card p-5">
    <h2 class="md-section-title" id="tplFormTitle">New Template</h2>
    <form method="POST" id="tplForm" class="space-y-3">
      <input type="hidden" name="action" value="save_template">
      <input type="hidden" name="tpl_id" id="tplId" value="0">
      <div>
        <label class="md-label">Template Name *</label>
        <input type="text" name="tpl_name" id="tplName" class="md-input" required placeholder="Order confirmation call">
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="md-label">Caller ID *</label>
          <select name="tpl_caller_id" id="tplCallerId" class="md-input" required>
            <?php foreach ($callerIds as $cid): ?>
            <option value="<?= e($cid['caller_id']) ?>"><?= e($cid['caller_id']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="md-label">Duration (min)</label>
          <input type="number" name="tpl_duration" id="tplDuration" class="md-input" value="5" min="1">
        </div>
      </div>
      <div>
        <label class="md-label">Messages JSON *</label>
        <textarea name="tpl_messages" id="tplMessages" class="json-editor" rows="8" required placeholder="[]"></textarea>
      </div>
      <div>
        <label class="md-label">Buttons JSON (optional)</label>
        <textarea name="tpl_buttons" id="tplButtons" class="json-editor" rows="4" placeholder="null or []"></textarea>
      </div>
      <div>
        <label class="md-label">Delivery Hook URL</label>
        <input type="url" name="tpl_delivery_hook" id="tplHook" class="md-input" value="<?= e($mdDeliveryHook) ?>">
      </div>
      <div>
        <label class="md-label">Call Payload</label>
        <input type="text" name="tpl_call_payload" id="tplPayload" class="md-input">
      </div>
      <div class="flex gap-3">
        <button type="submit" class="md-btn md-btn-primary flex-1">💾 Save Template</button>
        <button type="button" onclick="resetTplForm()" class="md-btn md-btn-secondary">✕ Clear</button>
      </div>
    </form>
  </div>

  <div class="xl:col-span-3 md-card">
    <div class="px-5 py-3 border-b font-semibold text-sm text-gray-700">Templates (<?= count($templates) ?>)</div>
    <div class="divide-y">
      <?php foreach ($templates as $tpl): ?>
      <div class="p-4 hover:bg-gray-50">
        <div class="flex items-start justify-between gap-3">
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm text-gray-800"><?= e($tpl['name']) ?></div>
            <div class="text-xs text-gray-500 mt-0.5">
              Caller: <span class="font-mono"><?= e($tpl['caller_id']) ?></span>
              · Duration: <?= $tpl['per_call_duration'] ?>min
              <?php if ($tpl['delivery_hook']): ?> · Hook: <?= e(parse_url($tpl['delivery_hook'],PHP_URL_HOST)) ?><?php endif; ?>
            </div>
          </div>
          <div class="flex gap-2 flex-shrink-0">
            <button onclick="editTemplate(<?= htmlspecialchars(json_encode($tpl)) ?>)"
              class="md-btn md-btn-secondary text-xs py-1 px-3">Edit</button>
            <button onclick="useTemplate(<?= htmlspecialchars(json_encode($tpl)) ?>)"
              class="md-btn md-btn-primary text-xs py-1 px-3">📞 Use</button>
            <form method="POST" class="inline" onsubmit="return confirm('Delete template?')">
              <input type="hidden" name="action" value="delete_template">
              <input type="hidden" name="tpl_id" value="<?= $tpl['id'] ?>">
              <button type="submit" class="md-btn md-btn-danger text-xs py-1 px-3">✕</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($templates)): ?>
      <div class="p-8 text-center text-sm text-gray-400">No templates yet. Create one using the form.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ══════════════ CALL LOGS ══════════════ -->
<?php elseif ($tab === 'call-logs'): ?>
<div class="md-card">
  <div class="px-5 py-3 border-b flex items-center justify-between">
    <div class="font-semibold text-sm text-gray-700">Call Logs (<?= count($callLogs) ?>)</div>
    <div class="flex gap-2">
      <input type="text" id="logSearch" oninput="filterLogs()" placeholder="Search number, type..." class="md-input w-48 text-xs py-1.5">
      <select id="logStatus" onchange="filterLogs()" class="md-input w-32 text-xs py-1.5">
        <option value="">All status</option>
        <option>initiated</option><option>answered</option><option>completed</option>
        <option>missed</option><option>failed</option><option>busy</option><option>no_answer</option>
      </select>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-xs" id="logsTable">
      <thead><tr class="bg-gray-50 border-b">
        <th class="px-4 py-2 text-left">Time</th>
        <th class="px-4 py-2 text-left">Type</th>
        <th class="px-4 py-2 text-left">Number</th>
        <th class="px-4 py-2 text-left">Caller ID</th>
        <th class="px-4 py-2 text-left">Customer</th>
        <th class="px-4 py-2 text-left">Order</th>
        <th class="px-4 py-2 text-left">Status</th>
        <th class="px-4 py-2 text-left">Details</th>
      </tr></thead>
      <tbody>
        <?php foreach ($callLogs as $log):
          $hookData = $log['delivery_hook_payload'] ? json_decode($log['delivery_hook_payload'], true) : null;
        ?>
        <tr class="border-b hover:bg-gray-50 log-row" data-search="<?= strtolower(e($log['phone_number'].' '.$log['call_type'].' '.$log['caller_id'])) ?>" data-status="<?= $log['status'] ?>">
          <td class="px-4 py-2 text-gray-400 whitespace-nowrap"><?= date('d M H:i', strtotime($log['created_at'])) ?></td>
          <td class="px-4 py-2 capitalize"><?= str_replace('_',' ',$log['call_type']) ?></td>
          <td class="px-4 py-2 font-mono font-medium"><?= e($log['phone_number']) ?></td>
          <td class="px-4 py-2 font-mono text-[10px]"><?= e($log['caller_id']) ?></td>
          <td class="px-4 py-2"><?= e($log['cname'] ?? '—') ?></td>
          <td class="px-4 py-2"><?= e($log['order_number'] ?? '—') ?></td>
          <td class="px-4 py-2"><span class="md-badge <?= $log['status'] ?>"><?= $log['status'] ?></span></td>
          <td class="px-4 py-2">
            <?php if ($hookData): ?>
            <button onclick="showLogDetail(this)" data-detail="<?= htmlspecialchars(json_encode($hookData)) ?>"
              class="text-indigo-500 hover:text-indigo-700 text-[10px] font-medium px-2 py-0.5 border border-indigo-200 rounded">
              View
            </button>
            <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($callLogs)): ?>
        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">No call logs yet</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Log detail modal -->
<div id="logDetailModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
  <div class="bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-700 flex items-center justify-between">
      <h3 class="text-white font-semibold text-sm">Call Detail</h3>
      <button onclick="document.getElementById('logDetailModal').classList.add('hidden')" class="text-gray-400 hover:text-white">✕</button>
    </div>
    <pre id="logDetailContent" class="p-5 text-green-400 text-xs overflow-auto max-h-[calc(80vh-60px)] font-mono"></pre>
  </div>
</div>

<!-- ══════════════ SETTINGS ══════════════ -->
<?php elseif ($tab === 'settings'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <div class="md-card p-5">
    <h2 class="md-section-title">ManyDial API Configuration</h2>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="save_settings">
      <div>
        <label class="md-label">ManyDial API Key *</label>
        <input type="text" name="md_api_key" value="<?= e($mdApiKey) ?>" class="md-input font-mono" placeholder="YOUR_SECRET_KEY" required>
        <p class="text-[10px] text-gray-400 mt-1">Sent as <code>x-api-key</code> header with every API request</p>
      </div>
      <div>
        <label class="md-label">Default Delivery Hook URL</label>
        <input type="url" name="md_delivery_hook" value="<?= e($mdDeliveryHook) ?>" class="md-input" placeholder="https://khatibangla.com/api/md-webhook.php">
        <p class="text-[10px] text-gray-400 mt-1">Webhook URL that receives call completion data from ManyDial</p>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="md-label">Default Caller ID</label>
          <input type="text" name="md_default_caller_id" value="<?= e($mdDefaultCaller) ?>" class="md-input font-mono" placeholder="+8809600000000">
        </div>
        <div>
          <label class="md-label">Default Call Duration (min)</label>
          <input type="number" name="md_default_duration" value="<?= $mdDefaultDuration ?>" min="1" max="30" class="md-input">
        </div>
      </div>
      <button type="submit" class="md-btn md-btn-primary w-full">💾 Save Settings</button>
    </form>
  </div>

  <div class="space-y-4">
    <!-- Test connection -->
    <div class="md-card p-5">
      <h3 class="md-section-title">Test API Connection</h3>
      <button type="button" onclick="testApiConnection()" class="md-btn md-btn-secondary w-full">🔌 Test Connection</button>
      <div id="testResult" class="hidden mt-3 p-3 rounded-lg text-xs font-mono"></div>
    </div>

    <!-- Webhook info -->
    <div class="md-card p-5">
      <h3 class="md-section-title">Webhook Endpoint</h3>
      <p class="text-xs text-gray-500 mb-3">Point ManyDial to this URL to receive delivery reports:</p>
      <div class="bg-gray-900 rounded-lg p-3 font-mono text-xs text-green-400 flex items-center justify-between gap-2">
        <span id="webhookUrl"><?= rtrim(SITE_URL,'/') ?>/api/md-webhook.php</span>
        <button onclick="copyWebhook()" class="text-gray-400 hover:text-white flex-shrink-0">📋</button>
      </div>
      <p class="text-[10px] text-gray-400 mt-2">This endpoint processes ManyDial's delivery hook responses and updates call logs automatically.</p>
    </div>

    <!-- API endpoints reference -->
    <div class="md-card p-5">
      <h3 class="md-section-title">API Endpoints</h3>
      <div class="space-y-2 text-xs">
        <?php
        $endpoints = [
          ['POST','Caller ID Registration','/portal/callerid','caller-ids'],
          ['POST','Call Automation','/portal/callIdDispatch','call-automation'],
          ['POST','Call Center Create','/portal/call-center','call-center'],
          ['POST','Call Center Renew','/portal/call-center/renew','call-center'],
          ['POST','Agent Create','/portal/agent-request','agents'],
          ['DELETE','Agent Delete','/portal/agents/delete','agents'],
          ['GET','Agent List','/portal/call-center/agent-list','agents'],
          ['POST','Click to Call','/portal/click-to-call','click-to-call'],
        ];
        foreach ($endpoints as [$method,$label,$path,$tabLink]): ?>
        <div class="flex items-center gap-2">
          <span class="px-1.5 py-0.5 rounded text-[10px] font-bold <?= $method==='POST'?'bg-blue-100 text-blue-700':($method==='DELETE'?'bg-red-100 text-red-700':'bg-green-100 text-green-700') ?>"><?= $method ?></span>
          <a href="?tab=<?= $tabLink ?>" class="text-indigo-500 hover:text-indigo-700 font-medium"><?= $label ?></a>
          <code class="text-gray-400 text-[10px]"><?= $path ?></code>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

</div>

<script>
// ── Sample data ────────────────────────────────────────────────────────────
const SAMPLE_MESSAGES = [{"welcome":"আপনাকে স্বাগতম! আমরা কথাবাংলা থেকে কল করছি।","repeat":1,"sms":"আমাদের সেবা ব্যবহার করার জন্য আপনাকে ধন্যবাদ।","forward":"","menuMessage1":"আপনার অর্ডার কনফার্ম করতে ১ চাপুন, বাতিল করতে ২ চাপুন।","repeat1":2,"noCall1":"no","noCall1Message":"আপনার অর্ডার কনফার্ম করা হয়েছে।","menuMessage2":"আপনার অর্ডার বাতিল করতে ২ চাপুন।","repeat2":2,"noCall2":"yes","noCall2Message":"আপনার অর্ডার বাতিল করা হয়েছে। আমরা শীঘ্রই যোগাযোগ করব।","smsct1":"Our service... Press 1 for more, Press 2 to return to the main menu.","noCall3":"no","smsMessage2":"Thank you for being with us. Press 1 to know our services. Press 2 to talk to an agent."}];
const SAMPLE_BUTTONS = [{"id":"menuMessage1","key":"1","value":"Bangla"},{"id":"menuMessage1","key":"2","value":"English"},{"id":"menuMessage21","key":"1","value":"Bangla Service"},{"id":"menuMessage22","key":"2","value":"English Service"}];

function loadSampleMessages() {
    const el = document.getElementById('messagesJson');
    if (el) el.value = JSON.stringify(SAMPLE_MESSAGES, null, 2);
}
function loadSampleButtons() {
    const el = document.getElementById('buttonsJson');
    if (el) el.value = JSON.stringify(SAMPLE_BUTTONS, null, 2);
}

function validateJson() {
    const mj = document.getElementById('messagesJson');
    const bj = document.getElementById('buttonsJson');
    try {
        JSON.parse(mj.value);
        if (bj && bj.value.trim()) JSON.parse(bj.value);
        alert('✅ JSON is valid!');
    } catch(e) {
        alert('❌ Invalid JSON: ' + e.message);
    }
}

function loadTemplate(tpl) {
    document.getElementById('messagesJson').value = typeof tpl.messages === 'string' ? tpl.messages : JSON.stringify(tpl.messages, null, 2);
    if (tpl.buttons) document.getElementById('buttonsJson').value = typeof tpl.buttons === 'string' ? tpl.buttons : JSON.stringify(tpl.buttons, null, 2);
    const callerSel = document.querySelector('[name="caller_id"]');
    if (callerSel) callerSel.value = tpl.caller_id;
    const durInp = document.querySelector('[name="per_call_duration"]');
    if (durInp) durInp.value = tpl.per_call_duration || 5;
}

function editTemplate(tpl) {
    document.getElementById('tplFormTitle').textContent = 'Edit Template';
    document.getElementById('tplId').value = tpl.id;
    document.getElementById('tplName').value = tpl.name;
    document.getElementById('tplCallerId').value = tpl.caller_id;
    document.getElementById('tplDuration').value = tpl.per_call_duration;
    document.getElementById('tplMessages').value = typeof tpl.messages === 'string' ? tpl.messages : JSON.stringify(tpl.messages, null, 2);
    document.getElementById('tplButtons').value = tpl.buttons ? (typeof tpl.buttons === 'string' ? tpl.buttons : JSON.stringify(tpl.buttons, null, 2)) : '';
    document.getElementById('tplHook').value = tpl.delivery_hook || '';
    document.getElementById('tplPayload').value = tpl.call_payload || '';
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function resetTplForm() {
    document.getElementById('tplFormTitle').textContent = 'New Template';
    document.getElementById('tplId').value = '0';
    document.getElementById('tplForm').reset();
}

function useTemplate(tpl) {
    window.location.href = '?tab=call-automation';
    sessionStorage.setItem('autoLoadTemplate', JSON.stringify(tpl));
}

// Click to call
function doClickToCall() {
    const form = document.getElementById('ctcForm');
    const fd = new FormData(form);
    // Use manual email if agent select is empty
    const selEmail = fd.get('ctc_email') || '';
    const manualEmail = fd.get('ctc_email_manual') || '';
    const agEmail = selEmail.trim() || manualEmail.trim();
    if (!agEmail) { alert('Please select or enter an agent email'); btn.textContent = '📞 Initiate Click to Call'; btn.disabled = false; return; }
    fd.set('ctc_email', agEmail);
    fd.delete('ctc_email_manual');

    const btn = form.querySelector('button[type="button"]');
    btn.textContent = '⏳ Initiating...'; btn.disabled = true;

    fetch(location.pathname, {method:'POST', credentials:'same-origin', body: fd})
        .then(r => r.json())
        .then(data => {
            const div = document.getElementById('ctcResult');
            div.classList.remove('hidden');
            if (data.success) {
                div.className = 'mb-4 p-3 rounded-lg text-sm bg-green-50 border border-green-200 text-green-700';
                div.textContent = '✅ ' + (data.message || 'Call requested successfully');
            } else {
                div.className = 'mb-4 p-3 rounded-lg text-sm bg-red-50 border border-red-200 text-red-700';
                div.textContent = '❌ ' + (data.message || data.error || 'Failed to initiate call');
            }
        })
        .catch(e => {
            const div = document.getElementById('ctcResult');
            div.classList.remove('hidden');
            div.className = 'mb-4 p-3 rounded-lg text-sm bg-red-50 border border-red-200 text-red-700';
            div.textContent = '❌ Network error: ' + e.message;
        })
        .finally(() => { btn.textContent = '📞 Initiate Click to Call'; btn.disabled = false; });
}

// Call center iframe
function loadCCIframe() {
    const email = document.getElementById('iframeEmail').value.trim();
    const callerId = document.getElementById('iframeCallerId').value;
    if (!email || !callerId) { alert('Please enter agent email and select caller ID'); return; }
    const url = `https://call-center.manydial.com/embed/${encodeURIComponent(callerId)}?component=phoneDialer&email=${encodeURIComponent(email)}&callerId=${encodeURIComponent(callerId)}`;
    document.getElementById('ccIframe').src = url;
    document.getElementById('ccIframeWrap').classList.remove('hidden');
}

// Log filtering
function filterLogs() {
    const q = (document.getElementById('logSearch')?.value || '').toLowerCase();
    const s = (document.getElementById('logStatus')?.value || '').toLowerCase();
    document.querySelectorAll('.log-row').forEach(row => {
        const matchQ = !q || row.dataset.search.includes(q);
        const matchS = !s || row.dataset.status === s;
        row.style.display = matchQ && matchS ? '' : 'none';
    });
}

// Log detail modal
function showLogDetail(btn) {
    const data = JSON.parse(btn.dataset.detail);
    document.getElementById('logDetailContent').textContent = JSON.stringify(data, null, 2);
    document.getElementById('logDetailModal').classList.remove('hidden');
}

// Test API connection
function testApiConnection() {
    const btn = document.querySelector('[onclick="testApiConnection()"]');
    btn.textContent = '⏳ Testing...'; btn.disabled = true;
    const div = document.getElementById('testResult');
    div.classList.remove('hidden');
    div.className = 'mt-3 p-3 rounded-lg text-xs font-mono bg-gray-100 text-gray-600';
    div.textContent = 'Connecting to ManyDial API...';

    fetch('?action=api_test&tab=settings', {credentials:'same-origin'})
        .then(r => r.json())
        .then(data => {
            div.className = 'mt-3 p-3 rounded-lg text-xs font-mono ' + (data.success ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200');
            div.textContent = JSON.stringify(data, null, 2);
        })
        .catch(e => {
            div.className = 'mt-3 p-3 rounded-lg text-xs font-mono bg-red-50 text-red-700 border border-red-200';
            div.textContent = 'Error: ' + e.message;
        })
        .finally(() => { btn.textContent = '🔌 Test Connection'; btn.disabled = false; });
}

function copyWebhook() {
    const url = document.getElementById('webhookUrl').textContent;
    navigator.clipboard.writeText(url).then(() => alert('Webhook URL copied!'));
}

// Auto-load template from sessionStorage (when "Use" clicked from templates tab)
window.addEventListener('load', function() {
    const stored = sessionStorage.getItem('autoLoadTemplate');
    if (stored && location.search.includes('tab=call-automation')) {
        try { loadTemplate(JSON.parse(stored)); } catch(e) {}
        sessionStorage.removeItem('autoLoadTemplate');
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
