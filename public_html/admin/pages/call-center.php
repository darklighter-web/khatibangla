<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();
$pageTitle = 'Call Center';
$tab = $_GET['tab'] ?? 'dashboard';

// Helper: safe fetch single value
function ccVal($db, $sql, $params, $col, $default = 0) {
    $row = $db->fetch($sql, $params);
    return ($row && isset($row[$col])) ? $row[$col] : $default;
}

// ══════════════════════════════════════════
// ── Auto-create tables if not exist ──────
// ══════════════════════════════════════════
try {
$db->query("CREATE TABLE IF NOT EXISTS cc_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_type ENUM('sip','whatsapp','messenger','sms','viber','telegram') NOT NULL,
    channel_name VARCHAR(100) NOT NULL,
    config JSON DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS cc_numbers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL,
    label VARCHAR(100) DEFAULT NULL,
    provider VARCHAR(100) DEFAULT NULL,
    pbx_type VARCHAR(30) DEFAULT 'generic',
    sip_server VARCHAR(255) DEFAULT NULL,
    sip_port INT DEFAULT 5060,
    ws_uri VARCHAR(500) DEFAULT NULL,
    sip_username VARCHAR(100) DEFAULT NULL,
    sip_password VARCHAR(255) DEFAULT NULL,
    sip_transport ENUM('ws','wss','tcp','udp') DEFAULT 'wss',
    sip_realm VARCHAR(255) DEFAULT NULL,
    stun_server VARCHAR(255) DEFAULT 'stun:stun.l.google.com:19302',
    turn_server VARCHAR(255) DEFAULT NULL,
    turn_user VARCHAR(100) DEFAULT NULL,
    turn_pass VARCHAR(255) DEFAULT NULL,
    caller_id VARCHAR(50) DEFAULT NULL,
    channel_id INT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_health_check DATETIME DEFAULT NULL,
    last_health_status ENUM('ok','warn','fail','unknown') DEFAULT 'unknown',
    last_health_message TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_channel (channel_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS cc_call_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    direction ENUM('inbound','outbound','internal') NOT NULL DEFAULT 'outbound',
    channel_type VARCHAR(30) DEFAULT 'sip',
    caller_number VARCHAR(50) DEFAULT NULL,
    callee_number VARCHAR(50) DEFAULT NULL,
    customer_name VARCHAR(200) DEFAULT NULL,
    customer_id INT DEFAULT NULL,
    order_id INT DEFAULT NULL,
    number_id INT DEFAULT NULL,
    agent_id INT DEFAULT NULL,
    agent_name VARCHAR(100) DEFAULT NULL,
    status ENUM('initiated','ringing','answered','completed','missed','failed','busy','no_answer','voicemail') NOT NULL DEFAULT 'initiated',
    duration_seconds INT DEFAULT 0,
    recording_url VARCHAR(500) DEFAULT NULL,
    recording_file VARCHAR(500) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    tags VARCHAR(255) DEFAULT NULL,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    answered_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    INDEX idx_direction (direction),
    INDEX idx_status (status),
    INDEX idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Safe column addition for existing tables
$existingCols = [];
try { foreach ($db->fetchAll("SHOW COLUMNS FROM cc_numbers") ?: [] as $cr) $existingCols[] = $cr['Field'] ?? ''; } catch (\Throwable $e) {}
$adds = [
    'pbx_type' => "ADD COLUMN pbx_type VARCHAR(30) DEFAULT 'generic' AFTER provider",
    'ws_uri' => "ADD COLUMN ws_uri VARCHAR(500) DEFAULT NULL AFTER sip_port",
    'sip_realm' => "ADD COLUMN sip_realm VARCHAR(255) DEFAULT NULL AFTER sip_transport",
    'turn_server' => "ADD COLUMN turn_server VARCHAR(255) DEFAULT NULL AFTER stun_server",
    'turn_user' => "ADD COLUMN turn_user VARCHAR(100) DEFAULT NULL AFTER turn_server",
    'turn_pass' => "ADD COLUMN turn_pass VARCHAR(255) DEFAULT NULL AFTER turn_user",
    'caller_id' => "ADD COLUMN caller_id VARCHAR(50) DEFAULT NULL AFTER turn_pass",
];
foreach ($adds as $col => $sql) { if (!in_array($col, $existingCols)) { try { $db->query("ALTER TABLE cc_numbers $sql"); } catch (\Throwable $e) {} } }
} catch (\Throwable $e) {}

// ══════════════════════════════════════════
// ── AJAX Handlers ────────────────────────
// ══════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_channel') {
        $chId = intval($_POST['ch_id'] ?? 0);
        $data = [
            'channel_type' => sanitize($_POST['channel_type'] ?? 'sip'),
            'channel_name' => sanitize($_POST['channel_name'] ?? ''),
            'config' => json_encode(['api_key'=>sanitize($_POST['ch_api_key']??''),'api_secret'=>sanitize($_POST['ch_api_secret']??''),'webhook_url'=>sanitize($_POST['ch_webhook_url']??''),'phone_number'=>sanitize($_POST['ch_phone_number']??''),'page_id'=>sanitize($_POST['ch_page_id']??''),'access_token'=>sanitize($_POST['ch_access_token']??''),'extra'=>sanitize($_POST['ch_extra']??'')]),
            'is_active' => isset($_POST['ch_active']) ? 1 : 0,
            'sort_order' => intval($_POST['ch_sort_order'] ?? 0),
        ];
        if ($chId) $db->update('cc_channels', $data, 'id = ?', [$chId]);
        else { $db->insert('cc_channels', $data); $chId = $db->lastInsertId(); }
        echo json_encode(['success'=>true,'id'=>$chId]); exit;
    }
    if ($action === 'delete_channel') { $db->delete('cc_channels','id = ?',[intval($_POST['ch_id']??0)]); echo json_encode(['success'=>true]); exit; }

    if ($action === 'save_number') {
        $numId = intval($_POST['num_id'] ?? 0);
        $data = ['number'=>sanitize($_POST['num_number']??''),'label'=>sanitize($_POST['num_label']??''),'provider'=>sanitize($_POST['num_provider']??''),'pbx_type'=>sanitize($_POST['num_pbx_type']??'generic'),'sip_server'=>sanitize($_POST['num_sip_server']??''),'sip_port'=>intval($_POST['num_sip_port']??5060),'ws_uri'=>sanitize($_POST['num_ws_uri']??''),'sip_username'=>sanitize($_POST['num_sip_username']??''),'sip_transport'=>sanitize($_POST['num_sip_transport']??'wss'),'sip_realm'=>sanitize($_POST['num_sip_realm']??''),'stun_server'=>sanitize($_POST['num_stun_server']??'stun:stun.l.google.com:19302'),'turn_server'=>sanitize($_POST['num_turn_server']??''),'turn_user'=>sanitize($_POST['num_turn_user']??''),'turn_pass'=>$_POST['num_turn_pass']??'','caller_id'=>sanitize($_POST['num_caller_id']??''),'channel_id'=>intval($_POST['num_channel_id']??0)?:null,'is_active'=>intval($_POST['num_active']??1)];
        // Password: use raw POST value, no sanitize (may contain @#$ etc)
        $sipPass = $_POST['num_sip_password'] ?? '';
        if ($numId) {
            // Edit: only update password if user typed something
            if (strlen($sipPass) > 0) $data['sip_password'] = $sipPass;
            $db->update('cc_numbers', $data, 'id = ?', [$numId]);
        } else {
            // New: always set password (even if empty)
            $data['sip_password'] = $sipPass;
            $db->insert('cc_numbers', $data);
            $numId = $db->lastInsertId();
        }
        // Verify password was saved
        $check = $db->fetch("SELECT LENGTH(sip_password) as pw_len FROM cc_numbers WHERE id = ?", [$numId]);
        $pwLen = intval($check['pw_len'] ?? 0);
        echo json_encode(['success'=>true,'id'=>$numId,'password_saved'=>$pwLen > 0,'password_length'=>$pwLen]); exit;
    }
    if ($action === 'delete_number') { $db->delete('cc_numbers','id = ?',[intval($_POST['num_id']??0)]); echo json_encode(['success'=>true]); exit; }

    if ($action === 'get_sip_creds') {
        $num = $db->fetch("SELECT * FROM cc_numbers WHERE id = ? AND is_active = 1", [intval($_POST['num_id']??0)]);
        if (!$num) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
        $wsUri = $num['ws_uri'] ?? '';
        $server = $num['sip_server'] ?? '';
        $ip = !empty($server) ? gethostbyname($server) : '';

        // Build list of WS URIs to try (primary + fallbacks)
        $wsUris = [];
        if (!empty($wsUri)) $wsUris[] = $wsUri;

        if (!empty($server)) {
            // PortSIP PBX: 5066(ws), 5067(wss)
            // FreePBX/Asterisk: 8089(wss), 8088(ws)
            // 3CX: 5090(wss) | Common: 443, 7443
            $tryPorts = [8089,5067,5066,5090,7443,8443,443,8088,8900,10443];
            $paths = ['/ws','/wss','/websocket',''];

            // Scan open ports
            $openPorts = [];
            foreach ($tryPorts as $p) {
                $fp = @fsockopen($ip ?: $server, $p, $en, $es, 2);
                if ($fp) { fclose($fp); $openPorts[] = $p; }
            }

            // Generate candidate URIs from open ports
            foreach ($openPorts as $p) {
                $scheme = in_array($p, [5066, 8088, 80]) ? 'ws' : 'wss';
                foreach ($paths as $path) {
                    $candidate = "{$scheme}://{$server}:{$p}{$path}";
                    if (!in_array($candidate, $wsUris)) $wsUris[] = $candidate;
                }
            }

            // Save first discovered URI if none configured
            if (empty($num['ws_uri'] ?? '') && !empty($wsUris)) {
                $db->update('cc_numbers', ['ws_uri' => $wsUris[0]], 'id = ?', [intval($num['id']??0)]);
            }
        }

        $realm = ($num['sip_realm'] ?? '') ?: ($server);
        echo json_encode([
            'success'=>true,
            'ws_uri'=>$wsUris[0] ?? '',
            'ws_uris'=>$wsUris, // All candidates for fallback
            'sip_server'=>$server,
            'sip_user'=>$num['sip_username']??'',
            'sip_pass'=>$num['sip_password']??'',
            'sip_realm'=>$realm,
            'stun'=>$num['stun_server']??'stun:stun.l.google.com:19302',
            'turn'=>$num['turn_server']??'',
            'turn_user'=>$num['turn_user']??'',
            'turn_pass'=>$num['turn_pass']??'',
            'caller_id'=>($num['caller_id']??'')?:($num['number']??''),
            'number'=>$num['number']??'',
            'label'=>$num['label']??'',
            'ip'=>$ip,
        ]);
        exit;
    }

    if ($action === 'health_check') {
        $num = $db->fetch("SELECT * FROM cc_numbers WHERE id = ?", [intval($_POST['num_id']??0)]);
        if (!$num) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
        $server=$num['sip_server']??''; $port=intval($num['sip_port']??5060); $status='unknown'; $message='';
        if (empty($server)) { $status='fail'; $message='No SIP server'; }
        else {
            $ip = gethostbyname($server);
            if ($ip===$server && !filter_var($server,FILTER_VALIDATE_IP)) { $status='fail'; $message="DNS failed: $server"; }
            else {
                $sipOk=false; $wsOk=false; $foundWsPort=0;
                $fp=@fsockopen($ip,$port,$en,$es,4); if($fp){fclose($fp);$sipOk=true;}
                // Try specific WS URI first, then scan common ports
                $ws=$num['ws_uri']??'';
                if(!empty($ws)&&preg_match('/:(\d+)/',$ws,$m)){
                    $wsPort=intval($m[1]);
                    $fp2=@fsockopen($ip,$wsPort,$e2,$es2,3);if($fp2){fclose($fp2);$wsOk=true;$foundWsPort=$wsPort;}
                }
                if(!$wsOk){
                    // Auto-scan common WebSocket ports
                    $scanPorts=[8089,5067,5066,5090,443,7443,8088,5060,5061,80,8443,8900,10443];
                    foreach($scanPorts as $sp){
                        $fp3=@fsockopen($ip,$sp,$e3,$es3,2);
                        if($fp3){fclose($fp3);$wsOk=true;$foundWsPort=$sp;break;}
                    }
                }
                // Auto-save discovered WS port if we found one and didn't have one configured
                if($wsOk && $foundWsPort && empty($num['ws_uri']??'')){
                    $scheme=($num['sip_transport']??'wss')==='wss'?'wss':'ws';
                    $autoUri="{$scheme}://{$server}:{$foundWsPort}/ws";
                    $db->update('cc_numbers',['ws_uri'=>$autoUri],'id = ?',[intval($num['id']??0)]);
                }
                if($sipOk&&$wsOk){$status='ok';$message="✓ DNS($ip) · SIP:$port ✓ · WS:$foundWsPort ✓";}
                elseif($wsOk){$status='warn';$message="⚠ WS:$foundWsPort OK, SIP:$port unreachable";}
                elseif($sipOk){$status='warn';$message="⚠ SIP:$port OK, but no WebSocket port found. Scanned: 8089,5090,443,7443,8088";}
                else{$status='fail';$message="✗ SIP:$port unreachable, no WS port found at $server($ip)";}
            }
        }
        $db->update('cc_numbers',['last_health_check'=>date('Y-m-d H:i:s'),'last_health_status'=>$status,'last_health_message'=>$message],'id = ?',[intval($num['id']??0)]);
        echo json_encode(['success'=>true,'status'=>$status,'message'=>$message]); exit;
    }

    // ── Port Scan (find WebSocket port) ──
    if ($action === 'port_scan') {
        $server = sanitize($_POST['scan_server'] ?? '');
        if (empty($server)) { echo json_encode(['success'=>false,'message'=>'No server']); exit; }
        $ip = gethostbyname($server);
        if ($ip===$server && !filter_var($server,FILTER_VALIDATE_IP)) { echo json_encode(['success'=>false,'message'=>"DNS failed: $server"]); exit; }
        $open = [];
        $scanPorts = [5060,5061,8089,5067,5066,5090,443,7443,8088,80,8443,8900,10443];
        foreach ($scanPorts as $p) {
            $fp = @fsockopen($ip, $p, $en, $es, 2);
            if ($fp) { fclose($fp); $open[] = $p; }
        }
        echo json_encode(['success'=>true,'ip'=>$ip,'open_ports'=>$open,'scanned'=>$scanPorts]);
        exit;
    }

    if ($action === 'health_check_all') {
        $results = [];
        foreach ($db->fetchAll("SELECT id FROM cc_numbers WHERE is_active = 1") ?: [] as $n) {
            $num=$db->fetch("SELECT * FROM cc_numbers WHERE id = ?",[$n['id']]); if(!$num)continue;
            $server=$num['sip_server']??''; $port=intval($num['sip_port']??5060); $st='unknown'; $msg='';
            if(empty($server)){$st='fail';$msg='No SIP server';}else{$ip=gethostbyname($server);if($ip===$server&&!filter_var($server,FILTER_VALIDATE_IP)){$st='fail';$msg="DNS failed: $server";}else{$fp=@fsockopen($ip,$port,$en,$es,3);if($fp){fclose($fp);$st='ok';$msg="✓ $server:$port ($ip)";}else{$st='fail';$msg="✗ $server:$port unreachable";}}}
            $db->update('cc_numbers',['last_health_check'=>date('Y-m-d H:i:s'),'last_health_status'=>$st,'last_health_message'=>$msg],'id = ?',[$n['id']]);
            $results[]=['id'=>$n['id'],'number'=>$num['number']??'','status'=>$st,'message'=>$msg];
        }
        echo json_encode(['success'=>true,'results'=>$results]); exit;
    }

    if ($action === 'log_call') {
        $logData = ['direction'=>sanitize($_POST['log_direction']??'outbound'),'channel_type'=>sanitize($_POST['log_channel_type']??'sip'),'caller_number'=>sanitize($_POST['log_caller']??''),'callee_number'=>sanitize($_POST['log_callee']??''),'customer_name'=>sanitize($_POST['log_customer_name']??''),'customer_id'=>intval($_POST['log_customer_id']??0)?:null,'order_id'=>intval($_POST['log_order_id']??0)?:null,'number_id'=>intval($_POST['log_number_id']??0)?:null,'agent_id'=>intval($_SESSION['admin_id']??0)?:null,'agent_name'=>$_SESSION['admin_name']??'System','status'=>sanitize($_POST['log_status']??'initiated'),'duration_seconds'=>intval($_POST['log_duration']??0),'notes'=>sanitize($_POST['log_notes']??''),'tags'=>sanitize($_POST['log_tags']??''),'started_at'=>sanitize($_POST['log_started_at']??date('Y-m-d H:i:s'))];
        if(!empty($_POST['log_answered_at']))$logData['answered_at']=sanitize($_POST['log_answered_at']);
        if(!empty($_POST['log_ended_at']))$logData['ended_at']=sanitize($_POST['log_ended_at']);
        $logId=intval($_POST['log_id']??0);
        if($logId)$db->update('cc_call_logs',$logData,'id = ?',[$logId]);
        else{$db->insert('cc_call_logs',$logData);$logId=$db->lastInsertId();}
        echo json_encode(['success'=>true,'id'=>$logId]); exit;
    }
    if ($action === 'update_call_status') {
        $logId=intval($_POST['log_id']??0); $u=['status'=>sanitize($_POST['log_status']??'completed')];
        if(!empty($_POST['log_duration']))$u['duration_seconds']=intval($_POST['log_duration']);
        if(!empty($_POST['log_ended_at']))$u['ended_at']=sanitize($_POST['log_ended_at']);
        if(!empty($_POST['log_answered_at']))$u['answered_at']=sanitize($_POST['log_answered_at']);
        if(isset($_POST['log_notes'])&&$_POST['log_notes']!=='')$u['notes']=sanitize($_POST['log_notes']);
        if(!empty($_POST['log_tags']))$u['tags']=sanitize($_POST['log_tags']);
        $db->update('cc_call_logs',$u,'id = ?',[$logId]); echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'delete_call_log') { $db->delete('cc_call_logs','id = ?',[intval($_POST['log_id']??0)]); echo json_encode(['success'=>true]); exit; }
    if ($action === 'customer_lookup') {
        $q=sanitize($_POST['q']??''); if(strlen($q)<2){echo json_encode(['success'=>true,'results'=>[]]);exit;}
        $r=$db->fetchAll("SELECT id,name,phone,email FROM customers WHERE phone LIKE ? OR name LIKE ? ORDER BY name LIMIT 10",["%$q%","%$q%"])?: [];
        echo json_encode(['success'=>true,'results'=>$r]); exit;
    }
    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ══════════════════════════════════════════
// ── Load Stats (PHP 8 safe) ──────────────
// ══════════════════════════════════════════
$today = date('Y-m-d');
$todayCalls     = intval(ccVal($db,"SELECT COUNT(*) as c FROM cc_call_logs WHERE DATE(started_at)=?",[$today],'c'));
$todayAnswered  = intval(ccVal($db,"SELECT COUNT(*) as c FROM cc_call_logs WHERE DATE(started_at)=? AND status='completed'",[$today],'c'));
$todayMissed    = intval(ccVal($db,"SELECT COUNT(*) as c FROM cc_call_logs WHERE DATE(started_at)=? AND status IN('missed','no_answer')",[$today],'c'));
$todayDuration  = intval(ccVal($db,"SELECT COALESCE(SUM(duration_seconds),0) as s FROM cc_call_logs WHERE DATE(started_at)=? AND status='completed'",[$today],'s'));
$activeChannels = intval(ccVal($db,"SELECT COUNT(*) as c FROM cc_channels WHERE is_active=1",[],'c'));
$activeNumbers  = intval(ccVal($db,"SELECT COUNT(*) as c FROM cc_numbers WHERE is_active=1",[],'c'));
$healthyNumbers = intval(ccVal($db,"SELECT COUNT(*) as c FROM cc_numbers WHERE is_active=1 AND last_health_status='ok'",[],'c'));
$todayRate      = $todayCalls>0 ? round(($todayAnswered/$todayCalls)*100) : 0;

$channels    = $db->fetchAll("SELECT * FROM cc_channels ORDER BY sort_order,id") ?: [];
$numbers     = $db->fetchAll("SELECT n.*,COALESCE(c.channel_name,'') as channel_name, LENGTH(n.sip_password) as pw_len FROM cc_numbers n LEFT JOIN cc_channels c ON n.channel_id=c.id ORDER BY n.is_active DESC,n.id") ?: [];
$recentCalls = $db->fetchAll("SELECT * FROM cc_call_logs ORDER BY started_at DESC LIMIT 50") ?: [];
$filteredCalls = [];
if ($tab === 'history') {
    $w=['1=1']; $hp=[];
    if(!empty($_GET['dir'])){$w[]='direction=?';$hp[]=$_GET['dir'];}
    if(!empty($_GET['status'])){$w[]='status=?';$hp[]=$_GET['status'];}
    if(!empty($_GET['from'])){$w[]='DATE(started_at)>=?';$hp[]=$_GET['from'];}
    if(!empty($_GET['to'])){$w[]='DATE(started_at)<=?';$hp[]=$_GET['to'];}
    if(!empty($_GET['q'])){$sq='%'.sanitize($_GET['q']).'%';$w[]='(callee_number LIKE ? OR caller_number LIKE ? OR customer_name LIKE ?)';array_push($hp,$sq,$sq,$sq);}
    $filteredCalls=$db->fetchAll("SELECT * FROM cc_call_logs WHERE ".implode(' AND ',$w)." ORDER BY started_at DESC LIMIT 200",$hp)?:[];
}
$stColors=['completed'=>'bg-green-100 text-green-700','answered'=>'bg-green-100 text-green-700','missed'=>'bg-red-100 text-red-700','no_answer'=>'bg-red-100 text-red-700','failed'=>'bg-red-100 text-red-700','busy'=>'bg-yellow-100 text-yellow-700','ringing'=>'bg-blue-100 text-blue-700','initiated'=>'bg-gray-100 text-gray-600'];

include __DIR__ . '/../includes/header.php';
?>
<style>
.cc-tab{display:inline-flex;align-items:center;padding:10px 16px;font-size:14px;font-weight:500;border-bottom:2px solid transparent;transition:all .2s;border-radius:8px 8px 0 0;cursor:pointer;text-decoration:none;color:#6b7280}
.cc-tab:hover{color:#374151;background:#f9fafb}
.cc-tab.active{color:#2563eb;border-color:#2563eb;background:#eff6ff}
.dial-btn{width:64px;height:64px;border-radius:16px;font-size:24px;font-weight:600;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;user-select:none;border:none}
.dial-btn:active{transform:scale(.95)}
@keyframes pulse-ring{0%{transform:scale(1);opacity:1}100%{transform:scale(1.5);opacity:0}}
.pulse-ring{animation:pulse-ring 1.5s ease-out infinite}
.health-ok{background:#dcfce7;color:#15803d}.health-warn{background:#fef9c3;color:#a16207}.health-fail{background:#fee2e2;color:#b91c1c}.health-unknown{background:#f3f4f6;color:#6b7280}
</style>

<div class="bg-white rounded-t-xl border border-b-0 shadow-sm">
    <div class="flex items-center gap-1 px-4 pt-3 overflow-x-auto">
        <a href="?tab=dashboard" class="cc-tab <?= $tab==='dashboard'?'active':'' ?>"><i class="fas fa-tachometer-alt mr-1.5"></i>Dashboard</a>
        <a href="?tab=channels" class="cc-tab <?= $tab==='channels'?'active':'' ?>"><i class="fas fa-project-diagram mr-1.5"></i>Channels</a>
        <a href="?tab=history" class="cc-tab <?= $tab==='history'?'active':'' ?>"><i class="fas fa-history mr-1.5"></i>Call History</a>
        <a href="?tab=dialer" class="cc-tab <?= $tab==='dialer'?'active':'' ?>"><i class="fas fa-phone-alt mr-1.5"></i>Make Call</a>
        <a href="?tab=numbers" class="cc-tab <?= $tab==='numbers'?'active':'' ?>"><i class="fas fa-server mr-1.5"></i>Numbers & Health</a>
    </div>
</div>
<div class="bg-white rounded-b-xl border border-t-0 shadow-sm p-5 min-h-[500px]">

<?php if ($tab==='dashboard'): ?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border shadow-sm p-4 flex items-center gap-4"><div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-lg"><i class="fas fa-phone-alt"></i></div><div><div class="text-2xl font-bold"><?= $todayCalls ?></div><div class="text-xs text-gray-500">Today's Calls</div></div></div>
    <div class="bg-white rounded-xl border shadow-sm p-4 flex items-center gap-4"><div class="w-12 h-12 rounded-xl bg-green-100 text-green-600 flex items-center justify-center text-lg"><i class="fas fa-check-circle"></i></div><div><div class="text-2xl font-bold"><?= $todayAnswered ?></div><div class="text-xs text-gray-500">Answered</div></div></div>
    <div class="bg-white rounded-xl border shadow-sm p-4 flex items-center gap-4"><div class="w-12 h-12 rounded-xl bg-red-100 text-red-600 flex items-center justify-center text-lg"><i class="fas fa-phone-slash"></i></div><div><div class="text-2xl font-bold"><?= $todayMissed ?></div><div class="text-xs text-gray-500">Missed</div></div></div>
    <div class="bg-white rounded-xl border shadow-sm p-4 flex items-center gap-4"><div class="w-12 h-12 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center text-lg"><i class="fas fa-clock"></i></div><div><div class="text-2xl font-bold"><?= floor($todayDuration/60) ?>:<span class="text-lg"><?= str_pad($todayDuration%60,2,'0',STR_PAD_LEFT) ?></span></div><div class="text-xs text-gray-500">Talk Time</div></div></div>
</div>
<div class="grid md:grid-cols-3 gap-5 mb-6">
    <div class="bg-white rounded-xl border p-5 text-center">
        <svg width="120" height="120" viewBox="0 0 120 120" class="mx-auto"><circle cx="60" cy="60" r="50" fill="none" stroke="#e5e7eb" stroke-width="10"/><circle cx="60" cy="60" r="50" fill="none" stroke="<?= $todayRate>=80?'#22c55e':($todayRate>=50?'#eab308':'#ef4444') ?>" stroke-width="10" stroke-linecap="round" stroke-dasharray="<?= round($todayRate*3.14) ?> 314" transform="rotate(-90 60 60)"/><text x="60" y="60" text-anchor="middle" dy="8" fill="#1f2937" style="font-size:24px;font-weight:bold"><?= $todayRate ?>%</text></svg>
        <p class="text-sm text-gray-500 mt-2">Answer Rate</p>
    </div>
    <div class="bg-white rounded-xl border p-5">
        <h4 class="font-semibold text-gray-700 mb-3"><i class="fas fa-project-diagram mr-1.5 text-blue-500"></i>Active Channels (<?= $activeChannels ?>)</h4>
        <?php if(empty($channels)):?><p class="text-sm text-gray-400 text-center py-4">No channels. <a href="?tab=channels" class="text-blue-500 underline">Add one</a></p>
        <?php else: foreach($channels as $ch): $ci=['sip'=>'fas fa-headset','whatsapp'=>'fab fa-whatsapp','messenger'=>'fab fa-facebook-messenger','sms'=>'fas fa-sms','viber'=>'fab fa-viber','telegram'=>'fab fa-telegram']; ?>
        <div class="flex items-center gap-3 py-2 border-b last:border-0"><span class="w-8 h-8 rounded-lg flex items-center justify-center text-sm <?= ($ch['is_active']??0)?'bg-green-100 text-green-600':'bg-gray-100 text-gray-400' ?>"><i class="<?= $ci[$ch['channel_type']??'']??'fas fa-phone' ?>"></i></span><div class="flex-1 min-w-0"><p class="text-sm font-medium text-gray-800 truncate"><?= e($ch['channel_name']??'') ?></p></div><span class="w-2 h-2 rounded-full <?= ($ch['is_active']??0)?'bg-green-500':'bg-gray-300' ?>"></span></div>
        <?php endforeach; endif; ?>
    </div>
    <div class="bg-white rounded-xl border p-5">
        <h4 class="font-semibold text-gray-700 mb-3"><i class="fas fa-heartbeat mr-1.5 text-red-500"></i>Number Health</h4>
        <div class="flex gap-3 mb-3"><div class="flex-1 text-center p-2 bg-green-50 rounded-lg"><div class="text-lg font-bold text-green-600"><?= $healthyNumbers ?></div><div class="text-[10px] text-green-500">Healthy</div></div><div class="flex-1 text-center p-2 bg-red-50 rounded-lg"><div class="text-lg font-bold text-red-600"><?= max(0,$activeNumbers-$healthyNumbers) ?></div><div class="text-[10px] text-red-500">Issues</div></div><div class="flex-1 text-center p-2 bg-blue-50 rounded-lg"><div class="text-lg font-bold text-blue-600"><?= $activeNumbers ?></div><div class="text-[10px] text-blue-500">Total</div></div></div>
        <a href="?tab=numbers" class="text-xs text-blue-500 hover:underline">View all →</a>
    </div>
</div>
<!-- Recent Calls -->
<div class="bg-white rounded-xl border"><div class="flex items-center justify-between px-4 py-3 border-b"><h4 class="font-semibold text-gray-700"><i class="fas fa-clock mr-1.5"></i>Recent Calls</h4><a href="?tab=history" class="text-xs text-blue-500 hover:underline">View All</a></div>
<div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Dir</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Number</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Customer</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Status</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Duration</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Time</th></tr></thead><tbody>
<?php if(empty($recentCalls)):?><tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No calls yet</td></tr>
<?php else: foreach(array_slice($recentCalls,0,15) as $c): $d=$c['direction']??'outbound';$dur=intval($c['duration_seconds']??0);$cs=$c['status']??'initiated'; ?>
<tr class="border-b hover:bg-gray-50"><td class="px-4 py-2"><?= $d==='inbound'?'<span class="text-green-600 text-xs font-bold">↙IN</span>':'<span class="text-blue-600 text-xs font-bold">↗OUT</span>' ?></td><td class="px-4 py-2 font-mono text-xs"><?= e($d==='inbound'?($c['caller_number']??''):($c['callee_number']??'')) ?></td><td class="px-4 py-2"><?= e($c['customer_name']??'')?: '—' ?></td><td class="px-4 py-2"><span class="text-[10px] font-medium px-2 py-0.5 rounded-full <?= $stColors[$cs]??'bg-gray-100 text-gray-600' ?>"><?= ucfirst($cs) ?></span></td><td class="px-4 py-2 text-xs"><?= $dur>0?floor($dur/60).':'.str_pad($dur%60,2,'0',STR_PAD_LEFT):'—' ?></td><td class="px-4 py-2 text-xs text-gray-500"><?= date('M d, H:i',strtotime($c['started_at']??'now')) ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>

<?php elseif ($tab==='channels'): ?>
<div class="flex items-center justify-between mb-5"><div><h3 class="text-lg font-bold text-gray-800">Multi-Channel Configuration</h3><p class="text-xs text-gray-500 mt-0.5">SIP/VoIP, WhatsApp, Messenger, SMS, Viber, Telegram</p></div><button onclick="openChannelModal(0)" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700"><i class="fas fa-plus mr-1"></i>Add Channel</button></div>
<?php if(empty($channels)):?><div class="text-center py-16"><div class="w-20 h-20 bg-gray-100 rounded-2xl flex items-center justify-center mx-auto mb-4"><i class="fas fa-project-diagram text-3xl text-gray-300"></i></div><p class="text-sm text-gray-400 mb-4">No channels yet</p><button onclick="openChannelModal(0)" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm">Add Channel</button></div>
<?php else:?><div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4"><?php foreach($channels as $ch): $cfg=json_decode($ch['config']??'{}',true)?:[];$tm=['sip'=>['fas fa-headset','blue'],'whatsapp'=>['fab fa-whatsapp','green'],'messenger'=>['fab fa-facebook-messenger','indigo'],'sms'=>['fas fa-sms','amber'],'viber'=>['fab fa-viber','purple'],'telegram'=>['fab fa-telegram','cyan']];$ti=$tm[$ch['channel_type']??'']??['fas fa-phone','gray']; ?>
<div class="bg-white rounded-xl border shadow-sm overflow-hidden hover:shadow-md transition"><div class="p-4"><div class="flex items-start justify-between"><div class="flex items-center gap-3"><div class="w-10 h-10 rounded-xl bg-<?= $ti[1] ?>-100 text-<?= $ti[1] ?>-600 flex items-center justify-center"><i class="<?= $ti[0] ?> text-lg"></i></div><div><h5 class="font-semibold text-gray-800"><?= e($ch['channel_name']??'') ?></h5><p class="text-[10px] text-gray-400"><?= ucfirst($ch['channel_type']??'') ?></p></div></div><span class="w-3 h-3 rounded-full mt-1 <?= ($ch['is_active']??0)?'bg-green-400':'bg-gray-300' ?>"></span></div><?php if(!empty($cfg['phone_number'])):?><div class="mt-3 text-xs text-gray-500"><i class="fas fa-phone-alt mr-1"></i><?= e($cfg['phone_number']) ?></div><?php endif;?></div><div class="border-t px-4 py-2.5 bg-gray-50 flex justify-between items-center"><span class="text-[10px] text-gray-400"><?= date('M d, Y',strtotime($ch['created_at']??'now')) ?></span><div class="flex gap-2"><button onclick='openChannelModal(<?= intval($ch["id"]??0) ?>,<?= htmlspecialchars(json_encode($ch),ENT_QUOTES) ?>)' class="text-xs text-blue-500"><i class="fas fa-edit"></i></button><button onclick="deleteChannel(<?= intval($ch['id']??0) ?>)" class="text-xs text-red-400"><i class="fas fa-trash"></i></button></div></div></div>
<?php endforeach;?></div><?php endif;?>
<!-- Channel Modal -->
<div id="channelModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4"><div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto"><div class="p-5 border-b"><h3 class="font-bold text-gray-800" id="channelModalTitle">Add Channel</h3></div><form id="channelForm" class="p-5 space-y-4"><input type="hidden" name="action" value="save_channel"><input type="hidden" name="ch_id" id="chId" value="0"><div class="grid grid-cols-2 gap-4"><div><label class="text-sm font-medium text-gray-700 block mb-1">Type</label><select name="channel_type" id="chType" class="w-full px-3 py-2 border rounded-lg text-sm" required><option value="sip">SIP/VoIP</option><option value="whatsapp">WhatsApp</option><option value="messenger">Messenger</option><option value="sms">SMS</option><option value="viber">Viber</option><option value="telegram">Telegram</option></select></div><div><label class="text-sm font-medium text-gray-700 block mb-1">Name</label><input type="text" name="channel_name" id="chName" class="w-full px-3 py-2 border rounded-lg text-sm" required></div></div><div><label class="text-sm font-medium text-gray-700 block mb-1">Phone</label><input type="text" name="ch_phone_number" id="chPhone" class="w-full px-3 py-2 border rounded-lg text-sm"></div><div class="grid grid-cols-2 gap-4"><div><label class="text-sm font-medium text-gray-700 block mb-1">API Key</label><input type="text" name="ch_api_key" id="chApiKey" class="w-full px-3 py-2 border rounded-lg text-sm"></div><div><label class="text-sm font-medium text-gray-700 block mb-1">API Secret</label><input type="password" name="ch_api_secret" id="chApiSecret" class="w-full px-3 py-2 border rounded-lg text-sm"></div></div><div><label class="text-sm font-medium text-gray-700 block mb-1">Webhook URL</label><input type="url" name="ch_webhook_url" id="chWebhook" class="w-full px-3 py-2 border rounded-lg text-sm"></div><div class="grid grid-cols-2 gap-4"><div><label class="text-sm font-medium text-gray-700 block mb-1">Page/Bot ID</label><input type="text" name="ch_page_id" id="chPageId" class="w-full px-3 py-2 border rounded-lg text-sm"></div><div><label class="text-sm font-medium text-gray-700 block mb-1">Access Token</label><input type="text" name="ch_access_token" id="chToken" class="w-full px-3 py-2 border rounded-lg text-sm"></div></div><div><label class="text-sm font-medium text-gray-700 block mb-1">Extra JSON</label><textarea name="ch_extra" id="chExtra" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm font-mono"></textarea></div><div class="flex items-center gap-4"><label class="flex items-center gap-2"><input type="checkbox" name="ch_active" id="chActive" checked class="rounded"><span class="text-sm">Active</span></label><div><label class="text-sm">Sort:</label><input type="number" name="ch_sort_order" id="chSort" value="0" class="w-16 px-2 py-1 border rounded text-sm ml-1"></div></div></form><div class="p-5 border-t bg-gray-50 flex justify-end gap-3"><button onclick="closeChannelModal()" class="px-4 py-2 border rounded-lg text-sm text-gray-600">Cancel</button><button onclick="saveChannel()" class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Save</button></div></div></div>

<?php elseif ($tab==='history'): ?>
<div class="flex items-center justify-between mb-4"><h3 class="text-lg font-bold text-gray-800">Call History & Recordings</h3><button onclick="openLogCallModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700"><i class="fas fa-plus mr-1"></i>Log Call</button></div>
<form method="GET" class="bg-gray-50 rounded-xl p-4 mb-4"><input type="hidden" name="tab" value="history"><div class="flex flex-wrap gap-3 items-end"><div><label class="text-xs text-gray-500 block mb-1">Search</label><input type="text" name="q" value="<?= e($_GET['q']??'') ?>" class="px-3 py-1.5 border rounded-lg text-sm w-48" placeholder="Phone, name..."></div><div><label class="text-xs text-gray-500 block mb-1">Direction</label><select name="dir" class="px-3 py-1.5 border rounded-lg text-sm"><option value="">All</option><option value="inbound" <?= ($_GET['dir']??'')==='inbound'?'selected':'' ?>>In</option><option value="outbound" <?= ($_GET['dir']??'')==='outbound'?'selected':'' ?>>Out</option></select></div><div><label class="text-xs text-gray-500 block mb-1">Status</label><select name="status" class="px-3 py-1.5 border rounded-lg text-sm"><option value="">All</option><option value="completed" <?= ($_GET['status']??'')==='completed'?'selected':'' ?>>Completed</option><option value="missed" <?= ($_GET['status']??'')==='missed'?'selected':'' ?>>Missed</option><option value="no_answer" <?= ($_GET['status']??'')==='no_answer'?'selected':'' ?>>No Answer</option></select></div><div><label class="text-xs text-gray-500 block mb-1">From</label><input type="date" name="from" value="<?= e($_GET['from']??'') ?>" class="px-3 py-1.5 border rounded-lg text-sm"></div><div><label class="text-xs text-gray-500 block mb-1">To</label><input type="date" name="to" value="<?= e($_GET['to']??'') ?>" class="px-3 py-1.5 border rounded-lg text-sm"></div><button type="submit" class="px-4 py-1.5 bg-blue-600 text-white rounded-lg text-sm"><i class="fas fa-search mr-1"></i>Filter</button><a href="?tab=history" class="px-3 py-1.5 border rounded-lg text-sm text-gray-500">Clear</a></div></form>
<div class="overflow-x-auto rounded-xl border"><table class="w-full text-sm"><thead class="bg-gray-50"><tr><th class="px-3 py-2.5 text-left text-xs font-medium text-gray-500">Dir</th><th class="px-3 py-2.5 text-left text-xs font-medium text-gray-500">Ch</th><th class="px-3 py-2.5 text-left text-xs font-medium text-gray-500">Number</th><th class="px-3 py-2.5 text-left text-xs font-medium text-gray-500">Customer</th><th class="px-3 py-2.5 text-left text-xs font-medium text-gray-500">Agent</th><th class="px-3 py-2.5 text-left text-xs font-medium text-gray-500">Status</th><th class="px-3 py-2.5 text-left text-xs font-medium text-gray-500">Dur</th><th class="px-3 py-2.5 text-left text-xs font-medium text-gray-500">Rec</th><th class="px-3 py-2.5 text-left text-xs font-medium text-gray-500">Time</th><th class="px-3 py-2.5 text-left text-xs font-medium text-gray-500">Notes</th><th class="px-3 py-2.5"></th></tr></thead><tbody>
<?php $dc=!empty($filteredCalls)?$filteredCalls:$recentCalls; if(empty($dc)):?><tr><td colspan="11" class="px-4 py-12 text-center text-gray-400">No calls found</td></tr>
<?php else: foreach($dc as $c): $d=$c['direction']??'outbound';$dur=intval($c['duration_seconds']??0);$cs=$c['status']??'initiated'; ?>
<tr class="border-b hover:bg-gray-50"><td class="px-3 py-2"><?= $d==='inbound'?'<span class="text-green-600 text-xs font-bold">↙IN</span>':'<span class="text-blue-600 text-xs font-bold">↗OUT</span>' ?></td><td class="px-3 py-2 text-xs text-gray-500"><?= ucfirst($c['channel_type']??'sip') ?></td><td class="px-3 py-2 font-mono text-xs"><?= e($d==='inbound'?($c['caller_number']??''):($c['callee_number']??'')) ?></td><td class="px-3 py-2 text-xs"><?= e($c['customer_name']??'')?: '—' ?></td><td class="px-3 py-2 text-xs text-gray-500"><?= e($c['agent_name']??'')?: '—' ?></td><td class="px-3 py-2"><span class="text-[10px] font-medium px-2 py-0.5 rounded-full <?= $stColors[$cs]??'bg-gray-100 text-gray-600' ?>"><?= ucfirst($cs) ?></span></td><td class="px-3 py-2 text-xs"><?= $dur>0?floor($dur/60).':'.str_pad($dur%60,2,'0',STR_PAD_LEFT):'—' ?></td><td class="px-3 py-2"><?php if(!empty($c['recording_url'])):?><a href="<?= e($c['recording_url']) ?>" target="_blank" class="text-blue-500 text-xs"><i class="fas fa-play-circle"></i></a><?php else:?><span class="text-gray-300 text-xs">—</span><?php endif;?></td><td class="px-3 py-2 text-xs text-gray-500"><?= date('M d H:i',strtotime($c['started_at']??'now')) ?></td><td class="px-3 py-2 text-xs text-gray-500 max-w-[100px] truncate"><?= e($c['notes']??'') ?></td><td class="px-3 py-2"><button onclick="deleteCallLog(<?= intval($c['id']??0) ?>)" class="text-red-400 text-xs"><i class="fas fa-trash"></i></button></td></tr>
<?php endforeach; endif;?></tbody></table></div>
<!-- Log Modal --><div id="logCallModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4"><div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto"><div class="p-5 border-b"><h3 class="font-bold">Log a Call</h3></div><form id="logCallForm" class="p-5 space-y-4"><input type="hidden" name="action" value="log_call"><div class="grid grid-cols-2 gap-4"><div><label class="text-sm font-medium block mb-1">Direction</label><select name="log_direction" class="w-full px-3 py-2 border rounded-lg text-sm"><option value="outbound">Out</option><option value="inbound">In</option></select></div><div><label class="text-sm font-medium block mb-1">Channel</label><select name="log_channel_type" class="w-full px-3 py-2 border rounded-lg text-sm"><option value="sip">Phone</option><option value="whatsapp">WhatsApp</option><option value="sms">SMS</option></select></div></div><div class="grid grid-cols-2 gap-4"><div><label class="text-sm font-medium block mb-1">Phone*</label><input type="text" name="log_callee" class="w-full px-3 py-2 border rounded-lg text-sm" required></div><div><label class="text-sm font-medium block mb-1">Customer</label><input type="text" name="log_customer_name" class="w-full px-3 py-2 border rounded-lg text-sm"></div></div><div class="grid grid-cols-3 gap-4"><div><label class="text-sm font-medium block mb-1">Status</label><select name="log_status" class="w-full px-3 py-2 border rounded-lg text-sm"><option value="completed">Completed</option><option value="missed">Missed</option><option value="no_answer">No Answer</option><option value="busy">Busy</option></select></div><div><label class="text-sm font-medium block mb-1">Duration(s)</label><input type="number" name="log_duration" value="0" class="w-full px-3 py-2 border rounded-lg text-sm"></div><div><label class="text-sm font-medium block mb-1">Order#</label><input type="number" name="log_order_id" class="w-full px-3 py-2 border rounded-lg text-sm"></div></div><div><label class="text-sm font-medium block mb-1">Notes</label><textarea name="log_notes" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"></textarea></div><div><label class="text-sm font-medium block mb-1">Tags</label><input type="text" name="log_tags" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="follow-up, payment..."></div></form><div class="p-5 border-t bg-gray-50 flex justify-end gap-3"><button onclick="document.getElementById('logCallModal').classList.add('hidden')" class="px-4 py-2 border rounded-lg text-sm">Cancel</button><button onclick="saveCallLog()" class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Save</button></div></div></div>

<?php elseif ($tab==='dialer'): ?>
<script src="https://cdn.jsdelivr.net/npm/jssip/dist/jssip.min.js" onerror="this.src='https://unpkg.com/jssip@3.10.0/lib/JsSIP.js'"></script>
<div class="max-w-4xl mx-auto">
<div id="sipStatusBar" class="mb-4 rounded-xl border p-3 bg-gray-50"><div class="flex items-center justify-between"><div class="flex items-center gap-3"><div id="sipStatusDot" class="w-3 h-3 rounded-full bg-gray-400"></div><span id="sipStatusText" class="text-sm text-gray-500">Not connected — Select a number below</span></div><div class="flex gap-2"><button onclick="browserWsProbe()" id="btnWsProbe" class="text-xs text-blue-500 hover:text-blue-700 border border-blue-200 px-2 py-1 rounded"><i class="fas fa-stethoscope mr-1"></i>Test WebSocket</button><button onclick="sipDisconnect()" id="btnSipDisconnect" class="text-xs text-red-500 hidden"><i class="fas fa-plug mr-1"></i>Disconnect</button></div></div></div>
<div class="grid md:grid-cols-5 gap-6">
<div class="md:col-span-3 bg-white rounded-2xl border shadow-sm p-6">
    <h4 class="font-bold text-gray-800 text-center mb-1"><i class="fas fa-phone-alt mr-1.5 text-green-500"></i>Direct Call</h4>
    <p class="text-[10px] text-center text-gray-400 mb-4">WebRTC SIP — calls from your browser via Cloud PBX</p>
    <div class="mb-4"><label class="text-xs text-gray-500 block mb-1">Call from (SIP Number):</label><select id="dialFromNumber" class="w-full px-3 py-2 border rounded-lg text-sm" onchange="onSelectSipNumber()"><option value="">— Select SIP number —</option><?php foreach($numbers as $n):if($n['is_active']??0):?><option value="<?= intval($n['id']??0) ?>"><?= e(($n['label']??'')?:($n['number']??'')) ?> — <?= e($n['provider']??'SIP') ?> (<?= strtoupper($n['pbx_type']??'generic') ?>)</option><?php endif;endforeach;?></select></div>
    <div class="bg-gray-50 rounded-xl p-4 mb-4"><input type="text" id="dialNumber" class="w-full text-2xl font-mono text-center border-0 bg-transparent focus:outline-none tracking-wider" placeholder="Enter number..."></div>
    <div class="grid grid-cols-3 gap-3 mb-4 max-w-[240px] mx-auto"><?php foreach(['1','2','3','4','5','6','7','8','9','*','0','#'] as $k):?><button onclick="dialPress('<?= $k ?>')" class="dial-btn bg-gray-100 text-gray-800 hover:bg-gray-200"><?= $k ?></button><?php endforeach;?></div>
    <div class="flex gap-3 max-w-[240px] mx-auto"><button onclick="dialBackspace()" class="flex-1 dial-btn bg-gray-100 text-gray-600 hover:bg-gray-200"><i class="fas fa-backspace"></i></button><button onclick="startSipCall()" id="btnStartCall" class="flex-[2] dial-btn bg-green-500 text-white hover:bg-green-600"><i class="fas fa-phone-alt"></i></button><button onclick="endSipCall()" id="btnEndCall" class="flex-[2] dial-btn bg-red-500 text-white hover:bg-red-600 hidden"><i class="fas fa-phone-slash"></i></button></div>
    <div id="callStatusBar" class="mt-4 hidden"><div class="bg-green-50 border border-green-200 rounded-xl p-3 text-center"><div class="inline-block relative"><div class="w-3 h-3 bg-green-500 rounded-full inline-block"></div><div class="w-3 h-3 bg-green-500 rounded-full absolute top-0 left-0 pulse-ring"></div></div><span id="callStatusText" class="text-sm font-medium text-green-700 ml-2">Connecting...</span><div id="callTimer" class="text-xl font-mono font-bold text-green-800 mt-1">00:00</div></div></div>
    <audio id="remoteAudio" autoplay></audio>
</div>
<div class="md:col-span-2 space-y-4">
    <div class="bg-white rounded-2xl border shadow-sm p-5"><h4 class="font-semibold text-gray-700 mb-3"><i class="fas fa-bolt mr-1.5 text-amber-500"></i>Quick Dial</h4><div class="relative mb-3"><input type="text" id="quickDialSearch" class="w-full px-3 py-2 border rounded-lg text-sm pl-9" placeholder="Search customer..." oninput="quickDialLookup(this.value)"><i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-sm"></i></div><div id="quickDialResults" class="space-y-1 max-h-48 overflow-y-auto"></div></div>
    <div id="activeCallNotes" class="bg-white rounded-2xl border shadow-sm p-5 hidden"><h4 class="font-semibold text-gray-700 mb-3"><i class="fas fa-sticky-note mr-1.5 text-yellow-500"></i>Call Notes</h4><textarea id="callNotes" rows="3" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Notes..."></textarea><select id="callDisposition" class="w-full px-3 py-1.5 border rounded-lg text-xs mt-2"><option value="">Tag...</option><option value="follow-up">Follow-up</option><option value="resolved">Resolved</option><option value="payment">Payment</option><option value="complaint">Complaint</option><option value="order-query">Order query</option></select></div>
    <div class="bg-white rounded-2xl border shadow-sm p-5"><h4 class="font-semibold text-gray-700 mb-3"><i class="fas fa-history mr-1.5 text-gray-400"></i>Recent</h4><div class="space-y-1 max-h-52 overflow-y-auto"><?php foreach(array_slice($recentCalls,0,8) as $rc):$rn=($rc['callee_number']??'')?:($rc['caller_number']??'');$rok=($rc['status']??'')==='completed';?><button onclick="document.getElementById('dialNumber').value='<?= e($rn) ?>'" class="w-full text-left flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50"><div class="w-8 h-8 rounded-lg <?= $rok?'bg-green-50':'bg-red-50' ?> flex items-center justify-center"><i class="fas fa-phone-alt text-xs <?= $rok?'text-green-500':'text-red-500' ?>"></i></div><div class="flex-1 min-w-0"><p class="text-sm text-gray-800 truncate"><?= e(($rc['customer_name']??'')?:$rn) ?></p><p class="text-[10px] text-gray-400"><?= date('M d, H:i',strtotime($rc['started_at']??'now')) ?></p></div></button><?php endforeach;if(empty($recentCalls)):?><p class="text-xs text-gray-400 text-center py-4">No recent calls</p><?php endif;?></div></div>
</div></div></div>

<?php elseif ($tab==='numbers'): ?>
<div class="flex items-center justify-between mb-5"><div><h3 class="text-lg font-bold text-gray-800">IP Numbers & Health Check</h3><p class="text-xs text-gray-500 mt-0.5">Add your SIP credentials — Server IP, Username, Password — that's it</p></div><div class="flex gap-2"><button onclick="healthCheckAll()" class="bg-green-50 text-green-600 px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-100" id="btnHealthAll"><i class="fas fa-heartbeat mr-1"></i>Check All</button><button onclick="openNumberModal(0)" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700"><i class="fas fa-plus mr-1"></i>Add Number</button></div></div>
<div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-5"><h5 class="font-semibold text-blue-800 text-sm mb-2"><i class="fas fa-info-circle mr-1"></i>Quick Setup</h5><p class="text-xs text-blue-700">Just enter your <strong>Server IP</strong>, <strong>Username</strong>, and <strong>Password</strong> — we'll auto-detect the WebSocket port and configure everything else. Click "Auto-Detect Ports" in the form to scan your server.</p></div>
<?php if(empty($numbers)):?><div class="text-center py-16"><div class="w-20 h-20 bg-gray-100 rounded-2xl flex items-center justify-center mx-auto mb-4"><i class="fas fa-server text-3xl text-gray-300"></i></div><p class="text-sm text-gray-400 mb-4">Add your SIP credentials to start calling</p><button onclick="openNumberModal(0)" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm">Add Number</button></div>
<?php else:?><div class="space-y-3"><?php foreach($numbers as $n): $hs=$n['last_health_status']??'unknown'; $hi=['ok'=>'fa-check-circle','warn'=>'fa-exclamation-triangle','fail'=>'fa-times-circle','unknown'=>'fa-question-circle']; $hb=['ok'=>'bg-green-50 border-green-200','warn'=>'bg-yellow-50 border-yellow-200','fail'=>'bg-red-50 border-red-200','unknown'=>'bg-gray-50 border-gray-200']; $pbx=$n['pbx_type']??'generic'; $pc=['3cx'=>'bg-blue-100 text-blue-700','freepbx'=>'bg-orange-100 text-orange-700','asterisk'=>'bg-red-100 text-red-700','voipbd'=>'bg-green-100 text-green-700','generic'=>'bg-gray-100 text-gray-600']; ?>
<div class="rounded-xl border <?= $hb[$hs]??$hb['unknown'] ?> p-4" id="numRow_<?= intval($n['id']??0) ?>"><div class="flex items-center justify-between flex-wrap gap-3"><div class="flex items-center gap-4"><div class="w-10 h-10 rounded-xl health-<?= $hs ?> flex items-center justify-center"><i class="fas <?= $hi[$hs]??$hi['unknown'] ?> text-lg"></i></div><div><div class="flex items-center gap-2 flex-wrap"><span class="font-bold text-gray-900 font-mono"><?= e($n['number']??'') ?></span><?php if(!empty($n['label'])):?><span class="text-xs bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded"><?= e($n['label']) ?></span><?php endif;?><span class="text-[10px] font-semibold px-1.5 py-0.5 rounded <?= $pc[$pbx]??$pc['generic'] ?>"><?= strtoupper($pbx) ?></span><?php if(!($n['is_active']??1)):?><span class="text-[10px] bg-gray-200 text-gray-500 px-1.5 py-0.5 rounded">OFF</span><?php endif;?><?php if(intval($n['pw_len']??0)>0):?><span class="text-[10px] bg-green-100 text-green-600 px-1.5 py-0.5 rounded"><i class="fas fa-key"></i> PW</span><?php else:?><span class="text-[10px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded"><i class="fas fa-exclamation-triangle"></i> No PW</span><?php endif;?></div><p class="text-xs text-gray-500 mt-0.5"><?= e($n['provider']??'') ?> · <span class="font-mono"><?= e($n['sip_server']??'') ?>:<?= intval($n['sip_port']??5060) ?></span> · <?= strtoupper($n['sip_transport']??'wss') ?><?php if(!empty($n['ws_uri'])):?> · <span class="text-blue-500 font-mono text-[10px]"><?= e($n['ws_uri']) ?></span><?php endif;?></p></div></div><div class="flex items-center gap-3"><div class="text-right" id="healthInfo_<?= intval($n['id']??0) ?>"><?php if(!empty($n['last_health_check'])):?><p class="text-xs font-medium health-<?= $hs ?> px-2 py-0.5 rounded inline-block"><?= ucfirst($hs) ?></p><p class="text-[10px] text-gray-400 mt-0.5"><?= date('M d, H:i',strtotime($n['last_health_check'])) ?></p><?php if(!empty($n['last_health_message'])):?><p class="text-[10px] text-gray-500 mt-0.5 max-w-xs truncate" title="<?= e($n['last_health_message']) ?>"><?= e($n['last_health_message']) ?></p><?php endif; else:?><p class="text-xs text-gray-400">Never checked</p><?php endif;?></div><?php $nSafe = $n; unset($nSafe['sip_password']); // Don't expose password in HTML ?>
<div class="flex gap-1"><button onclick="healthCheckSingle(<?= intval($n['id']??0) ?>)" class="p-2 rounded-lg hover:bg-white text-green-600 text-sm"><i class="fas fa-heartbeat"></i></button><button onclick='openNumberModal(<?= intval($n["id"]??0) ?>,<?= htmlspecialchars(json_encode($nSafe),ENT_QUOTES) ?>)' class="p-2 rounded-lg hover:bg-white text-blue-500 text-sm"><i class="fas fa-edit"></i></button><button onclick="deleteNumber(<?= intval($n['id']??0) ?>)" class="p-2 rounded-lg hover:bg-white text-red-400 text-sm"><i class="fas fa-trash"></i></button></div></div></div></div>
<?php endforeach;?></div><?php endif;?>
<!-- Number Modal -->
<div id="numberModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
<div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-bold text-gray-800" id="numberModalTitle">Add SIP Number</h3>
    <button onclick="closeNumberModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
</div>
<form id="numberForm" class="p-5 space-y-4">
    <input type="hidden" name="action" value="save_number">
    <input type="hidden" name="num_id" id="numId" value="0">
    <input type="hidden" name="num_pbx_type" id="numPbxType" value="generic">
    <input type="hidden" name="num_channel_id" id="numChannelId" value="0">

    <!-- Quick Setup: Only 3 fields needed -->
    <div class="bg-green-50 border border-green-200 rounded-xl p-4">
        <h5 class="font-semibold text-green-800 text-sm mb-3"><i class="fas fa-bolt mr-1.5"></i>SIP Credentials</h5>
        <div class="space-y-3">
            <div>
                <label class="text-sm font-medium text-gray-700 block mb-1">Server / Domain IP <span class="text-red-400">*</span></label>
                <input type="text" name="num_sip_server" id="numSipServer" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" required placeholder="123.0.31.250 or sip.provider.com">
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 block mb-1">Username <span class="text-red-400">*</span></label>
                <input type="text" name="num_sip_username" id="numSipUser" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" required placeholder="09644228011">
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 block mb-1">Password <span class="text-red-400">*</span></label>
                <div class="relative">
                    <input type="password" name="num_sip_password" id="numSipPass" class="w-full px-3 py-2.5 border rounded-lg text-sm pr-20" placeholder="Enter password">
                    <button type="button" onclick="const p=document.getElementById('numSipPass');p.type=p.type==='password'?'text':'password';this.innerHTML=p.type==='password'?'<i class=\'fas fa-eye\'></i>':'<i class=\'fas fa-eye-slash\'></i>'" class="absolute right-2 top-2.5 text-gray-400 hover:text-gray-600 text-sm px-1"><i class="fas fa-eye"></i></button>
                </div>
                <div id="numPassHint" class="text-[10px] mt-1 hidden"></div>
            </div>
        </div>
    </div>

    <!-- Port Scan Button + Result -->
    <button type="button" onclick="scanServerPorts()" id="btnScanPorts" class="w-full py-2.5 bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-lg text-sm font-medium text-blue-700 transition">
        <i class="fas fa-search mr-1.5"></i>Auto-Detect Ports & Configure
    </button>
    <div id="scanResult" class="hidden"></div>

    <!-- Optional Label -->
    <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs text-gray-500 block mb-1">Label (optional)</label><input type="text" name="num_label" id="numLabel" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Main Line"></div>
        <div><label class="text-xs text-gray-500 block mb-1">Provider (optional)</label><input type="text" name="num_provider" id="numProvider" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="My SIP"></div>
    </div>

    <!-- Hidden defaults that get auto-filled -->
    <input type="hidden" name="num_number" id="numNumber" value="">
    <input type="hidden" name="num_sip_port" id="numSipPort" value="5060">
    <input type="hidden" name="num_ws_uri" id="numWsUri" value="">
    <input type="hidden" name="num_sip_transport" id="numSipTransport" value="wss">
    <input type="hidden" name="num_sip_realm" id="numSipRealm" value="">
    <input type="hidden" name="num_caller_id" id="numCallerId" value="">
    <input type="hidden" name="num_stun_server" id="numStun" value="stun:stun.l.google.com:19302">
    <input type="hidden" name="num_turn_server" id="numTurn" value="">
    <input type="hidden" name="num_turn_user" id="numTurnUser" value="">
    <input type="hidden" name="num_turn_pass" id="numTurnPass" value="">
    <input type="hidden" name="num_active" id="numActive" value="1">

    <!-- Advanced Toggle -->
    <div>
        <button type="button" onclick="document.getElementById('advancedSection').classList.toggle('hidden')" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
            <i class="fas fa-cog"></i> Advanced Settings
            <i class="fas fa-chevron-down text-[8px]"></i>
        </button>
        <div id="advancedSection" class="hidden mt-3 space-y-3 bg-gray-50 rounded-xl p-4 border">
            <div class="grid grid-cols-3 gap-3">
                <div><label class="text-xs text-gray-500 block mb-1">SIP Port</label><input type="number" id="numSipPortAdv" value="5060" class="w-full px-3 py-2 border rounded-lg text-sm" onchange="document.getElementById('numSipPort').value=this.value"></div>
                <div><label class="text-xs text-gray-500 block mb-1">Transport</label>
                    <select id="numSipTransportAdv" class="w-full px-3 py-2 border rounded-lg text-sm" onchange="document.getElementById('numSipTransport').value=this.value">
                        <option value="wss">WSS</option><option value="ws">WS</option><option value="tcp">TCP</option><option value="udp">UDP</option>
                    </select></div>
                <div><label class="text-xs text-gray-500 block mb-1">SIP Realm</label><input type="text" id="numSipRealmAdv" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Auto" onchange="document.getElementById('numSipRealm').value=this.value"></div>
            </div>
            <div><label class="text-xs text-gray-500 block mb-1">WebSocket URI</label><input type="text" id="numWsUriAdv" class="w-full px-3 py-2 border rounded-lg text-sm font-mono" placeholder="Auto-detected" onchange="document.getElementById('numWsUri').value=this.value"></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-xs text-gray-500 block mb-1">Caller ID</label><input type="text" id="numCallerIdAdv" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Same as username" onchange="document.getElementById('numCallerId').value=this.value"></div>
                <div><label class="text-xs text-gray-500 block mb-1">STUN Server</label><input type="text" id="numStunAdv" value="stun:stun.l.google.com:19302" class="w-full px-3 py-2 border rounded-lg text-sm" onchange="document.getElementById('numStun').value=this.value"></div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div><label class="text-xs text-gray-500 block mb-1">TURN Server</label><input type="text" id="numTurnAdv" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Optional" onchange="document.getElementById('numTurn').value=this.value"></div>
                <div><label class="text-xs text-gray-500 block mb-1">TURN User</label><input type="text" id="numTurnUserAdv" class="w-full px-3 py-2 border rounded-lg text-sm" onchange="document.getElementById('numTurnUser').value=this.value"></div>
                <div><label class="text-xs text-gray-500 block mb-1">TURN Pass</label><input type="password" id="numTurnPassAdv" class="w-full px-3 py-2 border rounded-lg text-sm" onchange="document.getElementById('numTurnPass').value=this.value"></div>
            </div>
            <div><label class="text-xs text-gray-500 block mb-1">PBX Type</label>
                <select id="numPbxTypeAdv" class="w-full px-3 py-2 border rounded-lg text-sm" onchange="document.getElementById('numPbxType').value=this.value">
                    <option value="generic">Generic SIP</option><option value="3cx">3CX</option><option value="freepbx">FreePBX</option><option value="asterisk">Asterisk</option><option value="voipbd">VoIP Bangladesh</option>
                </select></div>
        </div>
    </div>
</form>
<div class="p-5 border-t bg-gray-50 flex justify-end gap-3">
    <button onclick="closeNumberModal()" class="px-4 py-2 border rounded-lg text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
    <button onclick="saveNumber()" class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700"><i class="fas fa-save mr-1"></i>Save & Connect</button>
</div>
</div></div>

<?php endif;?>
</div>
<?php include __DIR__.'/../includes/footer.php'; ?>

<script>
async function ccPost(d){const fd=new FormData();for(const[k,v]of Object.entries(d))fd.append(k,v);try{const r=await fetch(location.pathname,{method:'POST',body:fd});return await r.json();}catch(e){return{success:false};}}
// Channels
function openChannelModal(id,data){document.getElementById('channelModalTitle').textContent=id?'Edit Channel':'Add Channel';document.getElementById('chId').value=id||0;if(data){const cfg=typeof data.config==='string'?JSON.parse(data.config||'{}'):(data.config||{});document.getElementById('chType').value=data.channel_type||'sip';document.getElementById('chName').value=data.channel_name||'';document.getElementById('chPhone').value=cfg.phone_number||'';document.getElementById('chApiKey').value=cfg.api_key||'';document.getElementById('chApiSecret').value=cfg.api_secret||'';document.getElementById('chWebhook').value=cfg.webhook_url||'';document.getElementById('chPageId').value=cfg.page_id||'';document.getElementById('chToken').value=cfg.access_token||'';document.getElementById('chExtra').value=cfg.extra||'';document.getElementById('chActive').checked=data.is_active==1;document.getElementById('chSort').value=data.sort_order||0;}else{document.getElementById('channelForm').reset();document.getElementById('chId').value=0;}document.getElementById('channelModal').classList.remove('hidden');}
function closeChannelModal(){document.getElementById('channelModal').classList.add('hidden');}
async function saveChannel(){const fd=new FormData(document.getElementById('channelForm'));const r=await fetch(location.pathname,{method:'POST',body:fd});const j=await r.json();if(j.success)location.reload();else alert(j.message||'Error');}
async function deleteChannel(id){if(!confirm('Delete?'))return;const j=await ccPost({action:'delete_channel',ch_id:id});if(j.success)location.reload();}
// Numbers — Simplified
function openNumberModal(id,data){
    document.getElementById('numberModalTitle').textContent=id?'Edit Number':'Add SIP Number';
    document.getElementById('numId').value=id||0;
    document.getElementById('scanResult').classList.add('hidden');
    document.getElementById('scanResult').innerHTML='';
    if(document.getElementById('advancedSection'))document.getElementById('advancedSection').classList.add('hidden');
    const hint=document.getElementById('numPassHint');
    if(data){
        document.getElementById('numSipServer').value=data.sip_server||'';
        document.getElementById('numSipUser').value=data.sip_username||'';
        document.getElementById('numSipPass').value='';
        document.getElementById('numLabel').value=data.label||'';
        document.getElementById('numProvider').value=data.provider||'';
        document.getElementById('numNumber').value=data.number||'';
        document.getElementById('numSipPort').value=data.sip_port||5060;
        document.getElementById('numWsUri').value=data.ws_uri||'';
        document.getElementById('numSipTransport').value=data.sip_transport||'wss';
        document.getElementById('numSipRealm').value=data.sip_realm||'';
        document.getElementById('numCallerId').value=data.caller_id||'';
        document.getElementById('numStun').value=data.stun_server||'stun:stun.l.google.com:19302';
        document.getElementById('numTurn').value=data.turn_server||'';
        document.getElementById('numTurnUser').value=data.turn_user||'';
        document.getElementById('numTurnPass').value='';
        document.getElementById('numPbxType').value=data.pbx_type||'generic';
        document.getElementById('numChannelId').value=data.channel_id||0;
        document.getElementById('numActive').value=data.is_active==1?'1':'0';
        // Show password status
        const hasPw=parseInt(data.pw_len||0)>0;
        if(hint){hint.classList.remove('hidden');hint.innerHTML=hasPw?'<span class="text-green-600"><i class="fas fa-check-circle mr-1"></i>Password saved ('+data.pw_len+' chars). Leave empty to keep, or type new one to change.</span>':'<span class="text-red-500"><i class="fas fa-exclamation-triangle mr-1"></i>No password saved! Enter your SIP password.</span>';}
        // Sync advanced fields
        const adv={numSipPortAdv:'numSipPort',numSipTransportAdv:'numSipTransport',numSipRealmAdv:'numSipRealm',numWsUriAdv:'numWsUri',numCallerIdAdv:'numCallerId',numStunAdv:'numStun',numTurnAdv:'numTurn',numTurnUserAdv:'numTurnUser',numPbxTypeAdv:'numPbxType'};
        for(const[a,h]of Object.entries(adv)){const el=document.getElementById(a);if(el)el.value=document.getElementById(h)?.value||'';}
    } else {
        document.getElementById('numberForm').reset();
        document.getElementById('numId').value=0;
        if(hint){hint.classList.remove('hidden');hint.innerHTML='<span class="text-gray-400">Enter your SIP password (e.g. ASD@#45d)</span>';}
        document.getElementById('numActive').value='1';
        document.getElementById('numSipPort').value='5060';
        document.getElementById('numSipTransport').value='wss';
        document.getElementById('numStun').value='stun:stun.l.google.com:19302';
    }
    document.getElementById('numberModal').classList.remove('hidden');
}
function closeNumberModal(){document.getElementById('numberModal').classList.add('hidden');}
async function saveNumber(){
    // Auto-fill number from username if empty
    const un=document.getElementById('numSipUser')?.value||'';
    if(!document.getElementById('numNumber').value)document.getElementById('numNumber').value=un;
    if(!document.getElementById('numCallerId').value)document.getElementById('numCallerId').value=un;
    if(!document.getElementById('numSipRealm').value)document.getElementById('numSipRealm').value=document.getElementById('numSipServer')?.value||'';
    const fd=new FormData(document.getElementById('numberForm'));
    const r=await fetch(location.pathname,{method:'POST',body:fd});const j=await r.json();
    if(j.success){
        // Show password confirmation
        if(j.password_saved){
            console.log('[SIP] Number saved. Password stored ('+j.password_length+' chars)');
        } else {
            console.warn('[SIP] Number saved but NO PASSWORD stored!');
        }
        location.reload();
    } else alert(j.message||'Error');
}
async function deleteNumber(id){if(!confirm('Delete?'))return;const j=await ccPost({action:'delete_number',num_id:id});if(j.success)location.reload();}
// Port Scan
async function scanServerPorts(){
    const server=document.getElementById('numSipServer')?.value?.trim();
    if(!server){alert('Enter the Server IP first');return;}
    const btn=document.getElementById('btnScanPorts');
    btn.innerHTML='<i class="fas fa-spinner fa-spin mr-1.5"></i>Scanning ports...';btn.disabled=true;
    const box=document.getElementById('scanResult');box.classList.remove('hidden');
    box.innerHTML='<div class="bg-gray-50 border rounded-xl p-3 text-xs text-gray-500"><i class="fas fa-spinner fa-spin mr-1"></i>Scanning 10 common SIP/WebSocket ports on '+server+'...</div>';
    const j=await ccPost({action:'port_scan',scan_server:server});
    btn.innerHTML='<i class="fas fa-search mr-1.5"></i>Auto-Detect Ports & Configure';btn.disabled=false;
    if(!j.success){box.innerHTML='<div class="bg-red-50 border border-red-200 rounded-xl p-3 text-xs text-red-600"><i class="fas fa-times-circle mr-1"></i>'+(j.message||'Scan failed')+'</div>';return;}
    const open=j.open_ports||[];
    if(!open.length){box.innerHTML='<div class="bg-red-50 border border-red-200 rounded-xl p-3 text-xs text-red-600"><i class="fas fa-times-circle mr-1"></i>No open ports found on '+server+'. Server may be unreachable or firewall is blocking.</div>';return;}
    // Determine best WS port
    const wsPriority=[8089,5090,7443,8443,443,8088,80];
    let wsPort=0;
    for(const p of wsPriority){if(open.includes(p)){wsPort=p;break;}}
    const sipPort=open.includes(5060)?5060:(open.includes(5061)?5061:0);
    // Auto-configure
    if(wsPort){
        const scheme=wsPort===80||wsPort===8088?'ws':'wss';
        const uri=scheme+'://'+server+':'+wsPort+'/ws';
        document.getElementById('numWsUri').value=uri;
        document.getElementById('numSipTransport').value=scheme==='wss'?'wss':'ws';
        if(document.getElementById('numWsUriAdv'))document.getElementById('numWsUriAdv').value=uri;
        if(document.getElementById('numSipTransportAdv'))document.getElementById('numSipTransportAdv').value=scheme==='wss'?'wss':'ws';
    }
    if(sipPort){
        document.getElementById('numSipPort').value=sipPort;
        if(document.getElementById('numSipPortAdv'))document.getElementById('numSipPortAdv').value=sipPort;
    }
    // Show result
    let html='<div class="bg-blue-50 border border-blue-200 rounded-xl p-3 text-xs">';
    html+='<p class="font-semibold text-blue-800 mb-2"><i class="fas fa-check-circle mr-1"></i>Scan Complete — '+server+' ('+j.ip+')</p>';
    html+='<div class="flex flex-wrap gap-1.5 mb-2">';
    (j.scanned||[]).forEach(p=>{const isOpen=open.includes(p);html+='<span class="px-2 py-0.5 rounded '+(isOpen?'bg-green-100 text-green-700 font-medium':'bg-gray-100 text-gray-400')+'">'+p+(isOpen?' ✓':'')+'</span>';});
    html+='</div>';
    if(wsPort)html+='<p class="text-green-700"><i class="fas fa-check mr-1"></i>WebSocket port auto-set to <strong>'+wsPort+'</strong></p>';
    else html+='<p class="text-red-600"><i class="fas fa-exclamation-triangle mr-1"></i>No WebSocket port found. Your SIP provider may not support browser calling (WebRTC). You may need to ask them for the WebSocket URI.</p>';
    if(sipPort)html+='<p class="text-green-700"><i class="fas fa-check mr-1"></i>SIP port: <strong>'+sipPort+'</strong></p>';
    html+='</div>';
    box.innerHTML=html;
}
// Health
async function healthCheckSingle(id){const info=document.getElementById('healthInfo_'+id);if(info)info.innerHTML='<p class="text-xs text-blue-500"><i class="fas fa-spinner fa-spin mr-1"></i>Checking...</p>';const j=await ccPost({action:'health_check',num_id:id});if(j.success){const cls={ok:'health-ok',warn:'health-warn',fail:'health-fail'}[j.status]||'health-unknown';const bg={ok:'bg-green-50 border-green-200',warn:'bg-yellow-50 border-yellow-200',fail:'bg-red-50 border-red-200'}[j.status]||'bg-gray-50 border-gray-200';const row=document.getElementById('numRow_'+id);if(row)row.className='rounded-xl border p-4 '+bg;if(info)info.innerHTML=`<p class="text-xs font-medium ${cls} px-2 py-0.5 rounded inline-block">${j.status.toUpperCase()}</p><p class="text-[10px] text-gray-500 mt-1">${j.message||''}</p>`;}}
async function healthCheckAll(){const btn=document.getElementById('btnHealthAll');btn.innerHTML='<i class="fas fa-spinner fa-spin mr-1"></i>Checking...';btn.disabled=true;const j=await ccPost({action:'health_check_all'});if(j.success&&j.results)j.results.forEach(r=>{const info=document.getElementById('healthInfo_'+r.id);if(info){const cls={ok:'health-ok',warn:'health-warn',fail:'health-fail'}[r.status]||'health-unknown';info.innerHTML=`<p class="text-xs font-medium ${cls} px-2 py-0.5 rounded inline-block">${r.status.toUpperCase()}</p><p class="text-[10px] text-gray-500 mt-1">${r.message||''}</p>`;}});btn.innerHTML='<i class="fas fa-heartbeat mr-1"></i>Check All';btn.disabled=false;}
// WebRTC SIP Dialer
let _sipUA=null,_sipSession=null,_callTimer=null,_callSeconds=0,_currentCallLogId=null,_sipCreds={};
function dialPress(k){const i=document.getElementById('dialNumber');if(i)i.value+=k;}
function dialBackspace(){const i=document.getElementById('dialNumber');if(i)i.value=i.value.slice(0,-1);}
function setSipStatus(s,t){const dot=document.getElementById('sipStatusDot'),txt=document.getElementById('sipStatusText'),disc=document.getElementById('btnSipDisconnect');if(!dot)return;const c={connected:'bg-green-500',connecting:'bg-yellow-400',disconnected:'bg-gray-400',error:'bg-red-500'};dot.className='w-3 h-3 rounded-full '+(c[s]||'bg-gray-400');if(txt)txt.textContent=t;if(disc)disc.classList.toggle('hidden',s!=='connected');}
async function onSelectSipNumber(){const sel=document.getElementById('dialFromNumber');if(!sel||!sel.value){sipDisconnect();return;}setSipStatus('connecting','Loading SIP credentials & scanning ports...');showDebug('Fetching SIP credentials...');try{const creds=await ccPost({action:'get_sip_creds',num_id:sel.value});if(!creds.success){setSipStatus('error','Failed: '+(creds.message||''));return;}
showDebug('Server: '+creds.sip_server+' (IP: '+(creds.ip||'?')+')');
showDebug('User: '+creds.sip_user+' | Password: '+(creds.sip_pass?'***'+creds.sip_pass.length+'chars***':'EMPTY!'));
showDebug('WS URIs to try: '+(creds.ws_uris||[]).length);
(creds.ws_uris||[]).forEach((u,i)=>showDebug('  ['+(i+1)+'] '+u));
if(!creds.ws_uri&&!(creds.ws_uris||[]).length){setSipStatus('error','No WebSocket port found. Go to Numbers & Health → run Health Check.');return;}
_sipCreds=creds;sipConnectWithFallback(creds);}catch(e){setSipStatus('error','Network error: '+e.message);}}

let _tryIndex=0,_wsUris=[],_tryConfigs=[];
function sipConnectWithFallback(cr){
    if(_sipUA){try{_sipUA.stop();}catch(e){}_sipUA=null;}
    if(typeof JsSIP==='undefined'){setSipStatus('error','JsSIP not loaded — refresh page');return;}

    // Build all configs to try: different URIs × different realm/auth combos
    _wsUris=cr.ws_uris||[cr.ws_uri];
    _tryConfigs=[];
    _wsUris.forEach(uri=>{
        // Try 1: realm = server domain
        _tryConfigs.push({ws:uri,realm:cr.sip_realm||cr.sip_server,authUser:cr.sip_user});
        // Try 2: realm = server IP (if different)
        if(cr.ip&&cr.ip!==cr.sip_server) _tryConfigs.push({ws:uri,realm:cr.ip,authUser:cr.sip_user});
        // Try 3: no realm (let server challenge set it)
        _tryConfigs.push({ws:uri,realm:'',authUser:cr.sip_user});
    });
    _tryIndex=0;
    tryNextConfig(cr);
}

function tryNextConfig(cr){
    if(_tryIndex>=_tryConfigs.length){
        setSipStatus('error','All connection attempts failed. Check credentials or ask your SIP provider if they support WebSocket/WebRTC.');
        showDebug('❌ All '+_tryConfigs.length+' attempts failed.');
        showDebug('💡 Your SIP provider may not support WebSocket connections.');
        showDebug('💡 Ask them: "Do you support WebRTC or SIP over WebSocket?"');
        showDebug('💡 If they give you a specific WebSocket URL, enter it in Advanced Settings.');
        return;
    }
    const cfg=_tryConfigs[_tryIndex];
    const attemptNum=_tryIndex+1;
    showDebug('');
    showDebug('🔄 Attempt '+attemptNum+'/'+_tryConfigs.length+': '+cfg.ws+(cfg.realm?' realm='+cfg.realm:' (no realm)'));
    setSipStatus('connecting','Attempt '+attemptNum+'/'+_tryConfigs.length+': trying '+cfg.ws+'...');

    if(_sipUA){try{_sipUA.stop();}catch(e){}_sipUA=null;}
    let sock;
    try{sock=new JsSIP.WebSocketInterface(cfg.ws);}catch(e){
        showDebug('  ✗ Invalid URI: '+e.message);
        _tryIndex++;tryNextConfig(cr);return;
    }
    // Set socket connect timeout
    sock.via_transport='auto';

    const sipRealm=cfg.realm||cr.sip_server;
    const sipUri='sip:'+cr.sip_user+'@'+sipRealm;
    showDebug('  URI: '+sipUri);

    const uaCfg={
        sockets:[sock],
        uri:sipUri,
        password:cr.sip_pass,
        display_name:cr.label||cr.caller_id||cr.number||cr.sip_user,
        register:true,
        session_timers:false,
        connection_recovery_min_interval:2,
        connection_recovery_max_interval:10,
        register_expires:120,
    };
    // If realm is different from URI host, set authorization_user
    if(cfg.authUser) uaCfg.authorization_user=cfg.authUser;
    // If no realm specified, let JsSIP use what server sends
    if(!cfg.realm) delete uaCfg.realm;

    let resolved=false;
    const failTimeout=setTimeout(()=>{
        if(!resolved){
            resolved=true;
            showDebug('  ✗ Timeout (8s) — moving to next');
            if(_sipUA){try{_sipUA.stop();}catch(e){}_sipUA=null;}
            _tryIndex++;tryNextConfig(cr);
        }
    },8000);

    try{
        _sipUA=new JsSIP.UA(uaCfg);
    }catch(e){
        clearTimeout(failTimeout);
        showDebug('  ✗ UA create failed: '+e.message);
        _tryIndex++;tryNextConfig(cr);return;
    }

    _sipUA.on('connected',()=>{showDebug('  ✓ WebSocket connected');});
    _sipUA.on('registered',()=>{
        if(resolved)return;resolved=true;clearTimeout(failTimeout);
        showDebug('  ✅ REGISTERED successfully!');
        showDebug('  URI: '+cfg.ws+' | realm: '+(cfg.realm||'auto'));
        setSipStatus('connected','✓ Registered: '+cr.sip_user+'@'+sipRealm+' — Ready to call');
        // Save working URI
        if(cfg.ws!==cr.ws_uri){
            showDebug('  💾 Saving working URI: '+cfg.ws);
            ccPost({action:'save_number',num_id:document.getElementById('dialFromNumber')?.value||'',num_ws_uri:cfg.ws,num_sip_realm:cfg.realm||'',num_number:cr.number,num_sip_server:cr.sip_server,num_sip_username:cr.sip_user});
        }
    });
    _sipUA.on('registrationFailed',(e)=>{
        if(resolved)return;resolved=true;clearTimeout(failTimeout);
        const cause=e.cause||'Unknown';
        showDebug('  ✗ Registration failed: '+cause);
        if(cause.includes('Rejected')||cause.includes('Authentication'))showDebug('    (auth error — trying next config)');
        if(_sipUA){try{_sipUA.stop();}catch(e){}_sipUA=null;}
        _tryIndex++;tryNextConfig(cr);
    });
    _sipUA.on('disconnected',(e)=>{
        if(resolved)return;
        showDebug('  ✗ Disconnected'+(e?.error?' — '+e.error:''));
        // Don't auto-advance on disconnect if we haven't timed out yet
    });
    _sipUA.on('newRTCSession',(data)=>{if(data.originator==='remote'){const s=data.session;const caller=s.remote_identity?.uri?.user||'Unknown';if(confirm('Incoming: '+caller+' — Answer?')){s.answer({mediaConstraints:{audio:true,video:false},pcConfig:{iceServers:buildIce(cr)}});handleSession(s,'inbound',caller);}else s.terminate();}});

    try{_sipUA.start();showDebug('  Connecting to WebSocket...');}catch(e){
        clearTimeout(failTimeout);
        showDebug('  ✗ Start failed: '+e.message);
        _tryIndex++;tryNextConfig(cr);
    }
}
// Keep old sipConnect as alias
function sipConnect(cr){sipConnectWithFallback(cr);}

// Debug log panel
function showDebug(msg){
    let box=document.getElementById('sipDebugLog');
    if(!box){
        const bar=document.getElementById('sipStatusBar');
        if(!bar)return;
        const wrap=document.createElement('div');
        wrap.innerHTML='<div class="mt-2"><button onclick="document.getElementById(\'sipDebugPanel\').classList.toggle(\'hidden\')" class="text-[10px] text-gray-400 hover:text-gray-600"><i class="fas fa-terminal mr-1"></i>Show/Hide SIP Debug Log</button><div id="sipDebugPanel" class="bg-gray-900 text-green-400 rounded-lg p-3 mt-1 max-h-48 overflow-y-auto font-mono text-[11px] leading-relaxed"><pre id="sipDebugLog" class="whitespace-pre-wrap"></pre></div></div>';
        bar.after(wrap);
        box=document.getElementById('sipDebugLog');
    }
    if(!msg){box.textContent='';return;}
    const ts=new Date().toLocaleTimeString('en',{hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
    box.textContent+=ts+' '+msg+'\n';
    const panel=document.getElementById('sipDebugPanel');
    if(panel)panel.scrollTop=panel.scrollHeight;
    console.log('[SIP]',msg);
}
function sipDisconnect(){if(_sipUA){try{_sipUA.stop();}catch(e){}_sipUA=null;}setSipStatus('disconnected','Disconnected');showDebug('Disconnected');}

// Browser-side WebSocket probe — tests actual WS connections from user's browser
async function browserWsProbe(server){
    if(!server){
        // Get server from selected number or first number
        const sel=document.getElementById('dialFromNumber');
        if(sel&&sel.value){
            const creds=await ccPost({action:'get_sip_creds',num_id:sel.value});
            if(creds.success)server=creds.sip_server;
        }
        if(!server){alert('Select a SIP number first or enter a server');return;}
    }
    showDebug('');
    showDebug('═══════════════════════════════════');
    showDebug('🔍 BROWSER WebSocket Probe: '+server);
    showDebug('  Testing actual WS connections from YOUR browser');
    showDebug('  (This is more reliable than server-side TCP scan)');
    showDebug('═══════════════════════════════════');
    setSipStatus('connecting','Probing WebSocket ports on '+server+'...');

    const ports=[5060,5061,5066,5067,8089,8088,5090,443,7443,8443,8900,10443,80,8080];
    const paths=['/ws','/wss','/websocket',''];
    const schemes=['wss','ws'];
    let found=[];
    let tested=0;
    const total=ports.length*paths.length*schemes.length;

    for(const port of ports){
        for(const scheme of schemes){
            for(const path of paths){
                const url=scheme+'://'+server+':'+port+path;
                tested++;
                try{
                    const result=await testWsConnection(url,3000);
                    if(result.connected){
                        showDebug('  ✅ '+url+' — WebSocket CONNECTED!');
                        found.push(url);
                    } else if(result.error&&!result.error.includes('timeout')){
                        // Only log non-timeout errors for open ports
                        if(result.opened) showDebug('  ⚠ '+url+' — opened then closed: '+result.error);
                    }
                }catch(e){}
            }
        }
        // Update status
        setSipStatus('connecting','Probing: '+tested+'/'+total+' combos tested, '+found.length+' found...');
    }

    showDebug('');
    showDebug('═══ Probe Complete ═══');
    if(found.length){
        showDebug('✅ Found '+found.length+' working WebSocket URL(s):');
        found.forEach((u,i)=>showDebug('  ['+(i+1)+'] '+u));
        showDebug('');
        showDebug('💡 Copy one of these URLs to Advanced Settings → WebSocket URI');
        showDebug('   Then try connecting again.');
        setSipStatus('disconnected','Found '+found.length+' WebSocket URL(s)! Check debug log below.');
    } else {
        showDebug('❌ No WebSocket connections accepted on any port/path.');
        showDebug('');
        showDebug('This means your SIP server does NOT support WebSocket/WebRTC.');
        showDebug('Your PortSIP app works because it uses native SIP (UDP/TCP),');
        showDebug('which is different from WebSocket SIP needed for browser calling.');
        showDebug('');
        showDebug('📞 Ask your provider:');
        showDebug('   "I need to make SIP calls from a web browser.');
        showDebug('    Do you support SIP over WebSocket (WSS) for WebRTC?');
        showDebug('    If yes, what is the WebSocket URL/port?"');
        showDebug('');
        showDebug('💡 Alternative: Use a WebRTC gateway like:');
        showDebug('   - webrtc2sip (free, self-hosted)');
        showDebug('   - Ooma, Twilio, or VoIP.ms (WebRTC-enabled providers)');
        setSipStatus('error','No WebSocket support found. See debug log for details.');
    }
}

function testWsConnection(url,timeoutMs){
    return new Promise(resolve=>{
        let ws,timer,opened=false;
        try{
            ws=new WebSocket(url);
        }catch(e){
            resolve({connected:false,error:'create failed: '+e.message,opened:false});
            return;
        }
        timer=setTimeout(()=>{
            try{ws.close();}catch(e){}
            resolve({connected:false,error:'timeout',opened:opened});
        },timeoutMs);
        ws.onopen=()=>{
            opened=true;
            clearTimeout(timer);
            try{ws.close();}catch(e){}
            resolve({connected:true,error:null,opened:true});
        };
        ws.onerror=(e)=>{
            if(!opened){
                clearTimeout(timer);
                resolve({connected:false,error:'connection refused',opened:false});
            }
        };
        ws.onclose=(e)=>{
            if(!opened){
                clearTimeout(timer);
                resolve({connected:false,error:'closed (code: '+e.code+')',opened:false});
            }
        };
    });
}
function buildIce(cr){const s=[];if(cr.stun)s.push({urls:cr.stun});if(cr.turn)s.push({urls:cr.turn,username:cr.turn_user||'',credential:cr.turn_pass||''});if(!s.length)s.push({urls:'stun:stun.l.google.com:19302'});return s;}
async function startSipCall(){const num=document.getElementById('dialNumber')?.value?.trim();if(!num){alert('Enter a number');return;}if(!_sipUA||!_sipUA.isRegistered()){alert('Not connected. Select a SIP number first.');return;}
const sel=document.getElementById('dialFromNumber');const log=await ccPost({action:'log_call',log_direction:'outbound',log_channel_type:'sip',log_callee:num,log_status:'initiated',log_number_id:sel?.value||'',log_started_at:new Date().toISOString().slice(0,19).replace('T',' ')});_currentCallLogId=log.id;
const realm=_sipCreds.sip_realm||_sipCreds.sip_server||'';const session=_sipUA.call('sip:'+num+'@'+realm,{mediaConstraints:{audio:true,video:false},pcConfig:{iceServers:buildIce(_sipCreds)}});handleSession(session,'outbound',num);}
function handleSession(session,dir,remote){_sipSession=session;document.getElementById('btnStartCall')?.classList.add('hidden');document.getElementById('btnEndCall')?.classList.remove('hidden');document.getElementById('callStatusBar')?.classList.remove('hidden');document.getElementById('activeCallNotes')?.classList.remove('hidden');const st=document.getElementById('callStatusText');if(st)st.textContent=dir==='inbound'?'Incoming: '+remote:'Calling '+remote+'...';
_callSeconds=0;_callTimer=setInterval(()=>{_callSeconds++;const el=document.getElementById('callTimer');if(el)el.textContent=String(Math.floor(_callSeconds/60)).padStart(2,'0')+':'+String(_callSeconds%60).padStart(2,'0');},1000);
session.on('progress',()=>{if(st)st.textContent='Ringing...';});
session.on('accepted',()=>{if(st)st.textContent='🟢 Connected — '+remote;if(_currentCallLogId)ccPost({action:'update_call_status',log_id:_currentCallLogId,log_status:'answered',log_answered_at:new Date().toISOString().slice(0,19).replace('T',' ')});});
session.on('peerconnection',(e)=>{e.peerconnection.ontrack=(ev)=>{const a=document.getElementById('remoteAudio');if(a&&ev.streams[0])a.srcObject=ev.streams[0];};});
session.on('ended',()=>finishCall('completed'));session.on('failed',(e)=>finishCall(e.cause==='Canceled'?'missed':'failed'));}
async function endSipCall(){if(_sipSession)try{_sipSession.terminate();}catch(e){}else finishCall('completed');}
async function finishCall(status){clearInterval(_callTimer);if(_currentCallLogId){await ccPost({action:'update_call_status',log_id:_currentCallLogId,log_status:status,log_duration:_callSeconds,log_ended_at:new Date().toISOString().slice(0,19).replace('T',' '),log_notes:document.getElementById('callNotes')?.value||'',log_tags:document.getElementById('callDisposition')?.value||''});}
document.getElementById('btnStartCall')?.classList.remove('hidden');document.getElementById('btnEndCall')?.classList.add('hidden');document.getElementById('callStatusBar')?.classList.add('hidden');document.getElementById('activeCallNotes')?.classList.add('hidden');const t=document.getElementById('callTimer');if(t)t.textContent='00:00';const n=document.getElementById('callNotes');if(n)n.value='';const a=document.getElementById('remoteAudio');if(a)a.srcObject=null;_sipSession=null;_currentCallLogId=null;_callSeconds=0;}
// Quick dial
let _qdT;function quickDialLookup(q){clearTimeout(_qdT);if(q.length<2){document.getElementById('quickDialResults').innerHTML='';return;}_qdT=setTimeout(async()=>{const j=await ccPost({action:'customer_lookup',q:q});const b=document.getElementById('quickDialResults');if(j.success&&j.results?.length){b.innerHTML=j.results.map(c=>`<button onclick="document.getElementById('dialNumber').value='${c.phone||''}';" class="w-full text-left flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50"><div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center"><i class="fas fa-user text-xs text-blue-500"></i></div><div class="flex-1"><p class="text-sm">${c.name||''}</p><p class="text-[10px] text-gray-400">${c.phone||''}</p></div></button>`).join('');}else b.innerHTML='<p class="text-xs text-gray-400 text-center py-2">No results</p>';},300);}
// History
function openLogCallModal(){document.getElementById('logCallModal')?.classList.remove('hidden');}
async function saveCallLog(){const fd=new FormData(document.getElementById('logCallForm'));const r=await fetch(location.pathname,{method:'POST',body:fd});const j=await r.json();if(j.success)location.reload();else alert(j.message||'Error');}
async function deleteCallLog(id){if(!confirm('Delete?'))return;const j=await ccPost({action:'delete_call_log',log_id:id});if(j.success)location.reload();}

// Init: verify JsSIP loaded & auto-connect
document.addEventListener('DOMContentLoaded',()=>{
    const dot=document.getElementById('sipStatusDot'),txt=document.getElementById('sipStatusText');
    if(dot&&txt){
        if(typeof JsSIP!=='undefined'){
            txt.textContent='✓ JsSIP '+JsSIP.version+' loaded — Select a number to connect';
            dot.className='w-3 h-3 rounded-full bg-yellow-400';
            // Auto-connect if number is already selected
            const sel=document.getElementById('dialFromNumber');
            if(sel&&sel.value)onSelectSipNumber();
        } else {
            txt.textContent='⚠ JsSIP library loading...';
            dot.className='w-3 h-3 rounded-full bg-yellow-400';
            // Retry after a moment
            setTimeout(()=>{
                if(typeof JsSIP!=='undefined'){
                    txt.textContent='✓ JsSIP '+JsSIP.version+' loaded — Select a number to connect';
                    const sel=document.getElementById('dialFromNumber');
                    if(sel&&sel.value)onSelectSipNumber();
                } else {
                    txt.textContent='✗ JsSIP failed to load. Check your internet connection.';
                    dot.className='w-3 h-3 rounded-full bg-red-500';
                }
            },3000);
        }
    }
});
</script>
