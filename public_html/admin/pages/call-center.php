<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();
$pageTitle = 'Call Center';
$tab = $_GET['tab'] ?? 'calls';

// ── Tables ──────────────────────────────────────────────────────────────────
try {
    $db->query("CREATE TABLE IF NOT EXISTS md_call_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        call_type ENUM('automation','click_to_call') NOT NULL DEFAULT 'automation',
        caller_id VARCHAR(50) DEFAULT NULL,
        phone_number VARCHAR(50) NOT NULL,
        customer_name VARCHAR(200) DEFAULT NULL,
        order_number VARCHAR(50) DEFAULT NULL,
        messages_summary TEXT DEFAULT NULL,
        status ENUM('initiated','answered','missed','failed','no_answer','busy','completed') DEFAULT 'initiated',
        duration_seconds INT DEFAULT 0,
        recording_url VARCHAR(500) DEFAULT NULL,
        actions_taken TEXT DEFAULT NULL,
        webhook_payload JSON DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phone (phone_number),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS md_caller_ids (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(100) NOT NULL,
        caller_id VARCHAR(50) NOT NULL UNIQUE,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

// ── Settings helpers ─────────────────────────────────────────────────────────
function mdGet($key, $default = '') {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    global $db;
    try { $r = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key=?", ['md_'.$key]); return $cache[$key] = ($r['setting_value'] ?? $default); } catch (\Throwable $e) { return $default; }
}
function mdSet($key, $val) {
    global $db;
    try { $db->query("INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", ['md_'.$key, $val]); } catch (\Throwable $e) {}
}

// ── ManyDial API call ─────────────────────────────────────────────────────────
function mdApi($endpoint, $data = [], $method = 'POST') {
    $key = mdGet('api_key');
    if (!$key) return ['success'=>false, 'error'=>'API key not set'];
    $ch = curl_init('https://api.manydial.com/v1'.$endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['x-api-key: '.$key, 'Content-Type: application/json'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['success'=>false,'error'=>$err];
    return json_decode($res, true) ?? ['success'=>false,'error'=>'Bad response'];
}

// ── FormData API (for caller ID registration) ─────────────────────────────────
function mdApiForm($endpoint, $data = []) {
    $key = mdGet('api_key');
    if (!$key) return ['success'=>false,'error'=>'API key not set'];
    $ch = curl_init('https://api.manydial.com/v1'.$endpoint);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$data, CURLOPT_HTTPHEADER=>['x-api-key: '.$key]]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['success'=>false,'error'=>$err];
    return json_decode($res, true) ?? ['success'=>false,'error'=>'Bad response'];
}

// ── POST handlers ─────────────────────────────────────────────────────────────
$flash = ''; $flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // Save settings
    if ($act === 'save_settings') {
        mdSet('api_key', sanitize($_POST['api_key'] ?? ''));
        mdSet('default_caller_id', sanitize($_POST['default_caller_id'] ?? ''));
        mdSet('webhook_url', sanitize($_POST['webhook_url'] ?? ''));
        header('Location: ?tab=settings&ok=Settings+saved'); exit;
    }

    // Register caller ID
    if ($act === 'register_caller_id') {
        $formData = array_map('sanitize', [
            'ownerName'    => $_POST['ownerName']    ?? '',
            'businessName' => $_POST['businessName'] ?? '',
            'email'        => $_POST['email']        ?? '',
            'phone'        => $_POST['phone']        ?? '',
            'nid'          => $_POST['nid']          ?? '',
            'dob'          => $_POST['dob']          ?? '',
            'fatherName'   => $_POST['fatherName']   ?? '',
            'gender'       => $_POST['gender']       ?? 'Male',
            'district'     => $_POST['district']     ?? '',
            'postCode'     => $_POST['postCode']     ?? '',
            'smsEnabled'   => 'No',
        ]);
        $result = mdApiForm('/portal/callerid', $formData);
        if (!empty($result['success']) || !empty($result['callerid'])) {
            try { $db->query("INSERT IGNORE INTO md_caller_ids (label,caller_id,status) VALUES (?,?,'pending')", [$formData['businessName']?:$formData['phone'], $formData['phone']]); } catch (\Throwable $e) {}
            header('Location: ?tab=caller-ids&ok=Caller+ID+submitted+for+approval'); exit;
        }
        $flash = 'Registration failed: '.($result['message'] ?? $result['error'] ?? 'Unknown error');
        $flashType = 'err';
    }

    // Make automated call
    if ($act === 'make_call') {
        $callerId = sanitize($_POST['caller_id'] ?? mdGet('default_caller_id'));
        $phone    = sanitize($_POST['phone'] ?? '');
        $welcome  = sanitize($_POST['welcome'] ?? '');
        $sms      = sanitize($_POST['sms'] ?? '');
        $forward  = sanitize($_POST['forward'] ?? '');
        $duration = intval($_POST['duration'] ?? 5);
        $hook     = mdGet('webhook_url');
        $orderNum = sanitize($_POST['order_number'] ?? '');
        $custName = sanitize($_POST['customer_name'] ?? '');

        // Build messages object
        $messages = [['welcome'=>$welcome,'repeat'=>1]];
        if ($sms) $messages[0]['sms'] = $sms;
        if ($forward) $messages[0]['forward'] = $forward;

        $payload = ['callerId'=>$callerId,'phoneNumber'=>$phone,'perCallDuration'=>$duration,'messages'=>$messages];
        if ($hook) $payload['deliveryHook'] = $hook;

        $result = mdApi('/portal/callIdDispatch', $payload);

        // Log it
        try {
            $db->insert('md_call_logs', [
                'call_type'       => 'automation',
                'caller_id'       => $callerId,
                'phone_number'    => $phone,
                'customer_name'   => $custName,
                'order_number'    => $orderNum,
                'messages_summary'=> mb_strimwidth($welcome, 0, 200, '…'),
                'status'          => (!empty($result['success'])) ? 'initiated' : 'failed',
                'webhook_payload' => json_encode($result),
            ]);
        } catch (\Throwable $e) {}

        if (!empty($result['success'])) {
            header('Location: ?tab=calls&ok=Call+initiated+to+'.urlencode($phone)); exit;
        }
        $flash = 'Call failed: '.($result['message'] ?? $result['error'] ?? 'Unknown');
        $flashType = 'err';
    }

    // Click to call
    if ($act === 'click_to_call') {
        header('Content-Type: application/json');
        $data = ['callerId'=>sanitize($_POST['caller_id']??''), 'email'=>sanitize($_POST['email']??''), 'number'=>sanitize($_POST['number']??'')];
        if ($_POST['payload'] ?? '') $data['payload'] = sanitize($_POST['payload']);
        $result = mdApi('/portal/click-to-call', $data);
        try { $db->insert('md_call_logs', ['call_type'=>'click_to_call','caller_id'=>$data['callerId'],'phone_number'=>$data['number'],'status'=>!empty($result['success'])?'initiated':'failed','webhook_payload'=>json_encode($result)]); } catch (\Throwable $e) {}
        echo json_encode($result); exit;
    }
}

// ── GET: API test ─────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'test_api') {
    header('Content-Type: application/json');
    $key = mdGet('api_key');
    if (!$key) { echo json_encode(['success'=>false,'error'=>'No API key']); exit; }
    $ch = curl_init('https://api.manydial.com/v1/portal/call-center/agent-list?callerId=%2B88000');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_HTTPHEADER=>["x-api-key: $key"]]);
    $res = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    echo json_encode(['success'=>!$err&&$code>0, 'http_code'=>$code, 'error'=>$err?:null, 'response'=>json_decode($res,true)]);
    exit;
}

// ── Data ──────────────────────────────────────────────────────────────────────
$apiKey       = mdGet('api_key');
$defaultCID   = mdGet('default_caller_id');
$webhookUrl   = mdGet('webhook_url');
$callerIds    = [];
$callLogs     = [];
try { $callerIds = $db->fetchAll("SELECT * FROM md_caller_ids ORDER BY created_at DESC"); } catch (\Throwable $e) {}
try { $callLogs  = $db->fetchAll("SELECT * FROM md_call_logs ORDER BY created_at DESC LIMIT 100"); } catch (\Throwable $e) {}

$todayCalls = count(array_filter($callLogs, fn($l)=>str_starts_with($l['created_at'],date('Y-m-d'))));
$initiated  = count(array_filter($callLogs, fn($l)=>in_array($l['status'],['initiated','answered','completed'])));

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.cc-tab { padding:7px 16px; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; border:1.5px solid #e2e8f0; background:#f8fafc; color:#64748b; text-decoration:none; display:inline-block; }
.cc-tab.active { background:#6366f1; color:#fff; border-color:#6366f1; }
.cc-card { background:#fff; border-radius:12px; border:1.5px solid #e2e8f0; }
.cc-input { width:100%; padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:13px; outline:none; }
.cc-input:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.cc-btn { padding:8px 20px; border-radius:8px; font-size:13px; font-weight:600; border:none; cursor:pointer; }
.cc-btn-primary { background:#6366f1; color:#fff; }
.cc-btn-primary:hover { background:#4f46e5; }
.cc-label { font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.cc-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; }
.badge-initiated,.badge-answered { background:#e0e7ff; color:#3730a3; }
.badge-failed,.badge-missed,.badge-no_answer,.badge-busy { background:#fee2e2; color:#991b1b; }
.badge-completed { background:#d1fae5; color:#065f46; }
.badge-pending { background:#fef3c7; color:#92400e; }
.badge-approved { background:#d1fae5; color:#065f46; }
</style>

<div class="p-4 max-w-screen-xl mx-auto">

<?php if ($flash): ?>
<div class="<?= $flashType==='err'?'bg-red-50 border-red-200 text-red-700':'bg-green-50 border-green-200 text-green-700' ?> border px-4 py-3 rounded-lg mb-4 text-sm"><?= e($flash) ?></div>
<?php endif; ?>
<?php if ($ok = $_GET['ok'] ?? ''): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm">✓ <?= e(urldecode($ok)) ?></div>
<?php endif; ?>

<?php if (!$apiKey && $tab !== 'settings'): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-4 text-sm text-amber-800">
  ⚠ ManyDial API key not set. <a href="?tab=settings" class="font-semibold underline">Go to Settings →</a>
</div>
<?php endif; ?>

<!-- Header + Tabs -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <div>
    <h1 class="text-xl font-bold text-gray-900">📞 Call Center <span class="text-sm font-normal text-gray-400 ml-1">powered by ManyDial</span></h1>
    <div class="text-xs text-gray-400 mt-0.5"><?= $todayCalls ?> calls today · <?= $initiated ?> successful total</div>
  </div>
  <?php if ($apiKey): ?><span class="text-xs bg-green-100 text-green-700 px-2.5 py-1 rounded-full font-semibold">● Connected</span><?php endif; ?>
</div>

<div class="flex flex-wrap gap-2 mb-5">
  <?php foreach (['calls'=>'📞 Make Call','history'=>'📜 History','caller-ids'=>'🪪 Caller IDs','settings'=>'⚙️ Settings'] as $k=>$l): ?>
  <a href="?tab=<?= $k ?>" class="cc-tab <?= $tab===$k?'active':'' ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<!-- ═══ MAKE CALL ═══ -->
<?php if ($tab === 'calls'): ?>
<div class="grid grid-cols-1 lg:grid-cols-5 gap-5">

  <!-- Automated call form -->
  <div class="lg:col-span-3 cc-card p-5">
    <h2 class="font-bold text-gray-800 text-sm mb-4 pb-2 border-b">Make Automated Call</h2>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="make_call">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="cc-label">Caller ID *</label>
          <select name="caller_id" class="cc-input" required>
            <option value="">Select...</option>
            <?php foreach ($callerIds as $c): ?><option value="<?= e($c['caller_id']) ?>" <?= $c['caller_id']===$defaultCID?'selected':'' ?>><?= e($c['label'].' ('.$c['caller_id'].')') ?></option><?php endforeach; ?>
            <?php if ($defaultCID && empty($callerIds)): ?><option value="<?= e($defaultCID) ?>" selected><?= e($defaultCID) ?></option><?php endif; ?>
          </select>
        </div>
        <div>
          <label class="cc-label">Customer Phone *</label>
          <input type="text" name="phone" class="cc-input" placeholder="+8801XXXXXXXXX" required>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="cc-label">Customer Name</label>
          <input type="text" name="customer_name" class="cc-input" placeholder="Optional">
        </div>
        <div>
          <label class="cc-label">Order #</label>
          <input type="text" name="order_number" class="cc-input" placeholder="Optional">
        </div>
      </div>
      <div>
        <label class="cc-label">Voice Message * <span class="text-gray-400 font-normal">(what the customer hears)</span></label>
        <textarea name="welcome" class="cc-input" rows="3" required placeholder="আপনার অর্ডার নিশ্চিত করতে ধন্যবাদ। আপনার অর্ডার প্রস্তুত করা হচ্ছে।"></textarea>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="cc-label">SMS to send (optional)</label>
          <input type="text" name="sms" class="cc-input" placeholder="Thank you for your order!">
        </div>
        <div>
          <label class="cc-label">Forward to number (optional)</label>
          <input type="text" name="forward" class="cc-input" placeholder="+8801XXXXXXXXX">
        </div>
      </div>
      <div>
        <label class="cc-label">Max call duration (minutes)</label>
        <input type="number" name="duration" class="cc-input" value="5" min="1" max="30" style="width:120px">
      </div>
      <button type="submit" class="cc-btn cc-btn-primary w-full">📞 Make Call</button>
    </form>
  </div>

  <!-- Click to call + info -->
  <div class="lg:col-span-2 space-y-4">
    <div class="cc-card p-5">
      <h2 class="font-bold text-gray-800 text-sm mb-4 pb-2 border-b">Click to Call (Agent → Customer)</h2>
      <p class="text-xs text-gray-500 mb-3">Connects an agent to a customer through ManyDial's call center.</p>
      <div id="ctcMsg" class="hidden text-sm mb-3 p-3 rounded-lg"></div>
      <div class="space-y-3">
        <div>
          <label class="cc-label">Caller ID</label>
          <select id="ctcCid" class="cc-input">
            <?php foreach ($callerIds as $c): ?><option value="<?= e($c['caller_id']) ?>"><?= e($c['caller_id']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="cc-label">Agent Email</label>
          <input id="ctcEmail" type="email" class="cc-input" placeholder="agent@example.com">
        </div>
        <div>
          <label class="cc-label">Customer Number <span class="text-gray-400 font-normal">(start with 01)</span></label>
          <input id="ctcNumber" type="text" class="cc-input" placeholder="01934567890">
        </div>
        <button onclick="doCtc()" class="cc-btn cc-btn-primary w-full">📲 Connect Call</button>
      </div>
    </div>

    <!-- Recent calls mini -->
    <div class="cc-card">
      <div class="px-4 py-3 border-b text-xs font-semibold text-gray-600">Recent Calls</div>
      <div class="divide-y max-h-64 overflow-y-auto">
        <?php foreach (array_slice($callLogs, 0, 8) as $l): ?>
        <div class="px-4 py-2.5 flex items-center justify-between text-xs">
          <div>
            <div class="font-mono font-medium text-gray-800"><?= e($l['phone_number']) ?></div>
            <div class="text-gray-400"><?= date('d M H:i', strtotime($l['created_at'])) ?></div>
          </div>
          <span class="cc-badge badge-<?= $l['status'] ?>"><?= $l['status'] ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($callLogs)): ?><div class="px-4 py-6 text-center text-xs text-gray-400">No calls yet</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══ HISTORY ═══ -->
<?php elseif ($tab === 'history'): ?>
<div class="cc-card">
  <div class="px-5 py-3 border-b flex items-center justify-between">
    <span class="font-semibold text-sm text-gray-700">Call History (<?= count($callLogs) ?>)</span>
    <input type="text" oninput="filterRows(this.value)" placeholder="Filter by phone..." class="cc-input text-xs py-1.5" style="width:200px">
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-xs" id="histTable">
      <thead><tr class="bg-gray-50 border-b text-gray-500">
        <th class="px-4 py-2 text-left">Time</th>
        <th class="px-4 py-2 text-left">Phone</th>
        <th class="px-4 py-2 text-left">Customer</th>
        <th class="px-4 py-2 text-left">Order</th>
        <th class="px-4 py-2 text-left">Type</th>
        <th class="px-4 py-2 text-left">Message</th>
        <th class="px-4 py-2 text-left">Status</th>
      </tr></thead>
      <tbody>
        <?php foreach ($callLogs as $l): ?>
        <tr class="border-b hover:bg-gray-50">
          <td class="px-4 py-2 text-gray-400 whitespace-nowrap"><?= date('d M H:i', strtotime($l['created_at'])) ?></td>
          <td class="px-4 py-2 font-mono font-medium"><?= e($l['phone_number']) ?></td>
          <td class="px-4 py-2"><?= e($l['customer_name'] ?: '—') ?></td>
          <td class="px-4 py-2"><?= e($l['order_number'] ?: '—') ?></td>
          <td class="px-4 py-2 capitalize"><?= str_replace('_',' ',$l['call_type']) ?></td>
          <td class="px-4 py-2 max-w-xs truncate text-gray-500"><?= e(mb_strimwidth($l['messages_summary']??'',0,60,'…')) ?></td>
          <td class="px-4 py-2"><span class="cc-badge badge-<?= $l['status'] ?>"><?= $l['status'] ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($callLogs)): ?><tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No calls yet</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ CALLER IDs ═══ -->
<?php elseif ($tab === 'caller-ids'): ?>
<div class="grid grid-cols-1 lg:grid-cols-5 gap-5">
  <!-- Registration form -->
  <div class="lg:col-span-2 cc-card p-5">
    <h2 class="font-bold text-gray-800 text-sm mb-4 pb-2 border-b">Register Caller ID</h2>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="register_caller_id">
      <div class="grid grid-cols-2 gap-3">
        <div><label class="cc-label">Owner Name *</label><input type="text" name="ownerName" class="cc-input" required placeholder="Full name"></div>
        <div><label class="cc-label">Business Name *</label><input type="text" name="businessName" class="cc-input" required placeholder="Company name"></div>
      </div>
      <div><label class="cc-label">Email *</label><input type="email" name="email" class="cc-input" required></div>
      <div>
        <label class="cc-label">Phone (this will be your Caller ID) *</label>
        <input type="text" name="phone" class="cc-input" required placeholder="+8809600000000">
        <p class="text-[10px] text-gray-400 mt-1">Must include country code. e.g. +880XXXXXXXXXX</p>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="cc-label">NID Number *</label><input type="text" name="nid" class="cc-input" required></div>
        <div><label class="cc-label">Date of Birth *</label><input type="date" name="dob" class="cc-input" required></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="cc-label">Father's Name *</label><input type="text" name="fatherName" class="cc-input" required></div>
        <div><label class="cc-label">Gender *</label>
          <select name="gender" class="cc-input"><option>Male</option><option>Female</option></select>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="cc-label">District</label><input type="text" name="district" class="cc-input" placeholder="Dhaka"></div>
        <div><label class="cc-label">Post Code</label><input type="text" name="postCode" class="cc-input" placeholder="1000"></div>
      </div>
      <button type="submit" class="cc-btn cc-btn-primary w-full">Submit Registration</button>
      <p class="text-[10px] text-gray-400 text-center">ManyDial will review and approve within 1-3 business days</p>
    </form>
  </div>

  <!-- List -->
  <div class="lg:col-span-3 cc-card">
    <div class="px-5 py-3 border-b font-semibold text-sm text-gray-700">Your Caller IDs</div>
    <div class="divide-y">
      <?php foreach ($callerIds as $c): ?>
      <div class="px-5 py-3 flex items-center justify-between">
        <div>
          <div class="font-mono font-semibold text-gray-800"><?= e($c['caller_id']) ?></div>
          <div class="text-xs text-gray-400"><?= e($c['label']) ?> · Added <?= date('d M Y', strtotime($c['created_at'])) ?></div>
        </div>
        <span class="cc-badge badge-<?= $c['status'] ?>"><?= $c['status'] ?></span>
      </div>
      <?php endforeach; ?>
      <?php if (empty($callerIds)): ?><div class="px-5 py-8 text-center text-sm text-gray-400">No caller IDs yet. Register one using the form.</div><?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══ SETTINGS ═══ -->
<?php elseif ($tab === 'settings'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <div class="cc-card p-5">
    <h2 class="font-bold text-gray-800 text-sm mb-4 pb-2 border-b">ManyDial Settings</h2>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="save_settings">
      <div>
        <label class="cc-label">API Key *</label>
        <input type="text" name="api_key" value="<?= e($apiKey) ?>" class="cc-input font-mono" placeholder="YOUR_SECRET_KEY">
        <p class="text-[10px] text-gray-400 mt-1">Get this from your ManyDial dashboard. Sent as <code>x-api-key</code> header.</p>
      </div>
      <div>
        <label class="cc-label">Default Caller ID</label>
        <select name="default_caller_id" class="cc-input">
          <option value="">— None —</option>
          <?php foreach ($callerIds as $c): ?><option value="<?= e($c['caller_id']) ?>" <?= $c['caller_id']===$defaultCID?'selected':'' ?>><?= e($c['caller_id'].' ('.$c['label'].')') ?></option><?php endforeach; ?>
          <?php if ($defaultCID): ?><option value="<?= e($defaultCID) ?>" selected><?= e($defaultCID) ?></option><?php endif; ?>
        </select>
        <p class="text-[10px] text-gray-400 mt-1">Pre-selected when making calls</p>
      </div>
      <div>
        <label class="cc-label">Delivery Webhook URL</label>
        <input type="url" name="webhook_url" value="<?= e($webhookUrl ?: rtrim(SITE_URL,'/').'/api/md-webhook.php') ?>" class="cc-input" placeholder="https://...">
        <p class="text-[10px] text-gray-400 mt-1">ManyDial will POST call results to this URL automatically</p>
      </div>
      <button type="submit" class="cc-btn cc-btn-primary w-full">Save Settings</button>
    </form>
  </div>

  <div class="space-y-4">
    <div class="cc-card p-5">
      <h3 class="font-semibold text-sm text-gray-700 mb-3">Test Connection</h3>
      <button onclick="testApi()" class="cc-btn" style="background:#f1f5f9;color:#374151;border:1.5px solid #e2e8f0;width:100%">🔌 Test API Key</button>
      <div id="testRes" class="hidden mt-3 p-3 rounded-lg text-xs font-mono bg-gray-900 text-green-400 whitespace-pre-wrap"></div>
    </div>
    <div class="cc-card p-5">
      <h3 class="font-semibold text-sm text-gray-700 mb-2">Webhook URL</h3>
      <div class="bg-gray-900 rounded-lg p-3 flex items-center justify-between gap-2">
        <code class="text-green-400 text-xs break-all" id="whUrl"><?= e(rtrim(SITE_URL,'/').'/api/md-webhook.php') ?></code>
        <button onclick="navigator.clipboard.writeText(document.getElementById('whUrl').textContent).then(()=>alert('Copied!'))" class="text-gray-400 hover:text-white flex-shrink-0 text-lg">📋</button>
      </div>
      <p class="text-[10px] text-gray-400 mt-2">Point your ManyDial delivery hook to this URL to auto-update call statuses.</p>
    </div>
    <div class="cc-card p-5">
      <h3 class="font-semibold text-sm text-gray-700 mb-2">How to get started</h3>
      <ol class="text-xs text-gray-600 space-y-1.5 list-decimal list-inside">
        <li>Enter your ManyDial API key above and save</li>
        <li>Go to <strong>Caller IDs</strong> and register a phone number</li>
        <li>Wait for approval (1-3 business days)</li>
        <li>Once approved, go to <strong>Make Call</strong> and start calling</li>
      </ol>
    </div>
  </div>
</div>
<?php endif; ?>
</div>

<script>
function doCtc() {
    const cid = document.getElementById('ctcCid').value;
    const email = document.getElementById('ctcEmail').value.trim();
    const number = document.getElementById('ctcNumber').value.trim();
    const msg = document.getElementById('ctcMsg');
    if (!email || !number) { alert('Please fill email and number'); return; }
    const fd = new FormData();
    fd.append('action','click_to_call');
    fd.append('caller_id', cid);
    fd.append('email', email);
    fd.append('number', number);
    msg.className = 'text-sm mb-3 p-3 rounded-lg bg-blue-50 text-blue-700';
    msg.classList.remove('hidden');
    msg.textContent = '⏳ Connecting...';
    fetch(location.pathname, {method:'POST', credentials:'same-origin', body:fd})
        .then(r=>r.json())
        .then(d=>{
            msg.className = 'text-sm mb-3 p-3 rounded-lg ' + (d.success ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700');
            msg.textContent = d.success ? '✅ Call connected successfully' : '❌ ' + (d.message || d.error || 'Failed');
        })
        .catch(e=>{ msg.className='text-sm mb-3 p-3 rounded-lg bg-red-50 text-red-700'; msg.textContent='❌ Error: '+e.message; });
}

function filterRows(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#histTable tbody tr').forEach(r => {
        r.style.display = !q || r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

function testApi() {
    const el = document.getElementById('testRes');
    el.classList.remove('hidden');
    el.textContent = 'Testing...';
    fetch('?action=test_api&tab=settings', {credentials:'same-origin'})
        .then(r=>r.json())
        .then(d=>{ el.textContent = JSON.stringify(d, null, 2); })
        .catch(e=>{ el.textContent = 'Error: '+e.message; });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
