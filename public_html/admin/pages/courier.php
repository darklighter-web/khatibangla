<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Courier Management';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_provider') {
        $pid = intval($_POST['provider_id'] ?? 0);
        $data = ['name'=>sanitize($_POST['name']),'api_url'=>sanitize($_POST['api_url']??''),'api_key'=>sanitize($_POST['api_key']??''),'api_secret'=>sanitize($_POST['api_secret']??''),'is_active'=>isset($_POST['is_active'])?1:0];
        if ($pid) { $db->update('courier_providers', $data, 'id = ?', [$pid]); }
        else { $data['code'] = strtolower(preg_replace('/[^a-z0-9]+/','_',strtolower($data['name']))); $e=$db->fetch("SELECT id FROM courier_providers WHERE code=?",[$data['code']]); if($e)$data['code'].='_'.time(); $db->insert('courier_providers',$data); }
        redirect(adminUrl('pages/courier.php?tab=providers&msg=saved'));
    }
    if ($action === 'update_shipment') { try{$db->update('shipments',['status'=>sanitize($_POST['status'])],'id=?',[intval($_POST['shipment_id'])]);}catch(Exception$e){} redirect(adminUrl('pages/courier.php?tab=shipments&msg=updated')); }
    if ($action === 'delete_provider') { $db->delete('courier_providers','id=?',[intval($_POST['provider_id'])]); redirect(adminUrl('pages/courier.php?tab=providers&msg=deleted')); }
}

$tab = $_GET['tab'] ?? 'pathao';
$providers = []; try{$providers=$db->fetchAll("SELECT cp.*, (SELECT COUNT(*) FROM shipments s WHERE s.courier_provider_id=cp.id) as shipment_count FROM courier_providers cp ORDER BY cp.name");}catch(Exception$e){try{$providers=$db->fetchAll("SELECT*,0 as shipment_count FROM courier_providers ORDER BY name");}catch(Exception$e2){}}
$shipments = []; try{$shipments=$db->fetchAll("SELECT s.*,o.order_number,o.customer_name,o.customer_phone,o.total,cp.name as courier_name FROM shipments s LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN courier_providers cp ON cp.id=s.courier_provider_id ORDER BY s.created_at DESC LIMIT 50");}catch(Exception$e){}
$editProvider = isset($_GET['edit']) ? $db->fetch("SELECT * FROM courier_providers WHERE id=?",[intval($_GET['edit'])]) : null;

$pc = [
    'client_id'      => getSetting('pathao_client_id',''),
    'client_secret'  => getSetting('pathao_client_secret',''),
    'username'       => getSetting('pathao_username',''),
    'password'       => getSetting('pathao_password',''),
    'environment'    => getSetting('pathao_environment','production'),
    'store_id'       => getSetting('pathao_store_id',''),
    'webhook_secret' => getSetting('pathao_webhook_secret',''),
];
$tokenExp = intval(getSetting('pathao_token_expiry','0'));
$connected = !empty($pc['client_id']) && !empty(getSetting('pathao_access_token','')) && $tokenExp > time();

// Steadfast settings
$sf = [
    'api_key'    => getSetting('steadfast_api_key',''),
    'secret_key' => getSetting('steadfast_secret_key',''),
    'webhook_token' => getSetting('steadfast_webhook_token',''),
];
$sfConnected = !empty($sf['api_key']) && !empty($sf['secret_key']);
$sfEmail = getSetting('steadfast_email','');
$sfPass = getSetting('steadfast_password','');
$sfDefaultNote = getSetting('steadfast_default_note','');
$sfSendProducts = getSetting('steadfast_send_product_names','1');
$sfActive = getSetting('steadfast_active','1');
$sfBalance = '';


if ($sfConnected) {
    try {
        @require_once __DIR__ . '/../../api/steadfast.php';
        $__sf = new SteadfastAPI();
        $__bal = $__sf->getBalance();
        $sfBalance = $__bal['current_balance'] ?? $__bal['balance'] ?? '';
    } catch (\Throwable $e) { $sfBalance = ''; }
}
// Steadfast stats
$sfStats = ['total'=>0,'shipped'=>0,'delivered'=>0,'cancelled'=>0];
try {
    $ss = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN order_status='shipped' THEN 1 ELSE 0 END) as shipped, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status IN ('cancelled','pending_cancel') THEN 1 ELSE 0 END) as cancelled FROM orders WHERE (LOWER(courier_name) LIKE 'steadfast%' OR LOWER(shipping_method) LIKE '%steadfast%')");
    if ($ss) $sfStats = $ss;
} catch (\Throwable $e) {}
$sfRate = intval($sfStats['total'])>0 ? round(intval($sfStats['delivered'])/intval($sfStats['total'])*100) : 0;

// CarryBee settings
$cb = [
    'api_key'    => getSetting('carrybee_api_key',''),
    'secret_key' => getSetting('carrybee_secret_key',''),
];
$cbConnected = !empty($cb['api_key']);

// RedX settings
$rx = [
    'api_token'     => getSetting('redx_api_token',''),
    'environment'   => getSetting('redx_environment','production'),
    'webhook_token' => getSetting('redx_webhook_token',''),
];
$rxConnected = !empty($rx['api_token']);
$rxActive = getSetting('redx_active','1');
$rxDefaultNote = getSetting('redx_default_note','');
$rxDefaultWeight = getSetting('redx_default_weight','500');
$rxSendProducts = getSetting('redx_send_product_names','1');
$rxDefaultPickupStore = getSetting('redx_default_pickup_store_id','');

// RedX stats
$rxStats = ['total'=>0,'shipped'=>0,'delivered'=>0,'cancelled'=>0];
try {
    $rs = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN order_status='shipped' THEN 1 ELSE 0 END) as shipped, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status IN ('cancelled','pending_cancel') THEN 1 ELSE 0 END) as cancelled FROM orders WHERE (LOWER(courier_name) LIKE '%redx%' OR LOWER(shipping_method) LIKE '%redx%')");
    if ($rs) $rxStats = $rs;
} catch (\Throwable $e) {}
$rxRate = intval($rxStats['total'])>0 ? round(intval($rxStats['delivered'])/intval($rxStats['total'])*100) : 0;

require_once __DIR__ . '/../includes/header.php';
?>

<style>.tab-on{background:#2563eb;color:#fff}.pulse-d{animation:pd 2s infinite}@keyframes pd{0%,100%{opacity:1}50%{opacity:.4}}</style>

<?php if(isset($_GET['msg'])): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm">✅ Action completed.</div><?php endif; ?>

<!-- TABS -->
<div class="flex flex-wrap gap-2 mb-6">
    <?php foreach(['pathao'=>'🚀 Pathao API','steadfast'=>'📦 Steadfast','redx'=>'🔴 RedX','carrybee'=>'🐝 CarryBee','webhooks'=>'🔗 Webhooks','customer_check'=>'🔍 Customer Verify','area_map'=>'📊 Area Analytics','providers'=>'📦 Providers','shipments'=>'🚚 Shipments'] as $k=>$v): ?>
    <a href="?tab=<?=$k?>" class="px-4 py-2 rounded-lg text-sm font-medium <?=$tab===$k?'tab-on':'bg-gray-100 text-gray-600 hover:bg-gray-200'?>"><?=$v?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'pathao'): ?>
<!-- ========================================= -->
<!-- PATHAO API CONNECTION -->
<!-- ========================================= -->
<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <!-- Connection Card -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-red-50 rounded-xl flex items-center justify-center"><span class="text-2xl font-bold text-red-600">P</span></div>
                    <div>
                        <h3 class="font-bold text-gray-800 text-lg">Pathao Merchant API</h3>
                        <p class="text-xs text-gray-500">Get credentials from <a href="https://merchant.pathao.com/courier/developer-api" target="_blank" class="text-blue-600 underline">merchant.pathao.com → Developer API</a></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-full <?=$connected?'bg-green-50 border border-green-200':'bg-gray-50 border'?>">
                    <span class="w-2.5 h-2.5 rounded-full <?=$connected?'bg-green-500 pulse-d':'bg-gray-300'?>"></span>
                    <span class="text-xs font-semibold <?=$connected?'text-green-700':'text-gray-500'?>"><?=$connected?'Connected':'Disconnected'?></span>
                </div>
            </div>

            <!-- Credential Fields -->
            <div class="bg-gray-50 rounded-xl p-5 mb-5 border border-dashed border-gray-300">
                <h4 class="text-sm font-semibold text-gray-700 mb-3">🔑 Merchant API Credentials</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Client ID <span class="text-red-500">*</span></label>
                        <input type="text" id="p_client_id" value="<?=e($pc['client_id'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono bg-white focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="e.g. 267">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Client Secret <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" id="p_client_secret" value="<?=e($pc['client_secret'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono bg-white pr-10" placeholder="Your client secret key">
                            <button onclick="togglePass(this)" class="absolute right-2 top-2.5 text-gray-400 hover:text-gray-600">👁</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-blue-50 rounded-xl p-5 mb-5 border border-dashed border-blue-200">
                <h4 class="text-sm font-semibold text-blue-700 mb-3">👤 Merchant Login Credentials</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Merchant Email <span class="text-red-500">*</span></label>
                        <input type="email" id="p_username" value="<?=e($pc['username'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-white" placeholder="your@merchant-email.com">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Password <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" id="p_password" value="<?=e($pc['password'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-white pr-10" placeholder="Merchant password">
                            <button onclick="togglePass(this)" class="absolute right-2 top-2.5 text-gray-400 hover:text-gray-600">👁</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-yellow-50 rounded-xl p-5 mb-5 border border-dashed border-yellow-200">
                <h4 class="text-sm font-semibold text-yellow-700 mb-3">⚙️ Configuration</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Environment</label>
                        <select id="p_environment" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-white">
                            <option value="production" <?=($pc['environment']??'')==='production'?'selected':''?>>🟢 Production — api-hermes.pathao.com</option>
                            <option value="sandbox" <?=($pc['environment']??'')==='sandbox'?'selected':''?>>🟡 Sandbox — hermes-api.p-stageenv.xyz</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Default Store ID</label>
                        <div class="flex gap-2">
                            <input type="text" id="p_store_id" value="<?=e($pc['store_id'])?>" class="flex-1 px-3 py-2.5 border rounded-lg text-sm font-mono bg-white" placeholder="Auto-fetched">
                            <button onclick="fetchStores()" class="px-3 py-2 bg-white border rounded-lg text-sm hover:bg-gray-50 font-medium" title="Fetch stores">🏪 Fetch</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-green-50 rounded-xl p-5 mb-5 border border-dashed border-green-200">
                <h4 class="text-sm font-semibold text-green-700 mb-3">🔗 Webhook Integration</h4>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Webhook Secret</label>
                        <div class="relative">
                            <input type="password" id="p_webhook_secret" value="<?=e($pc['webhook_secret'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono bg-white pr-10" placeholder="From Pathao Dashboard → Webhook Integration → Secret">
                            <button onclick="togglePass(this)" class="absolute right-2 top-2.5 text-gray-400 hover:text-gray-600">👁</button>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Enter the same secret here that you set in <a href="https://merchant.pathao.com/courier/developer-api" target="_blank" class="text-blue-600 underline">Pathao → Developer API → Webhook</a>. This is returned as <code class="bg-white px-1 rounded">X-Pathao-Merchant-Webhook-Integration-Secret</code> header.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Callback URL <span class="text-green-600">(copy this to Pathao)</span></label>
                        <div class="flex gap-2">
                            <code class="flex-1 block text-xs font-mono text-gray-700 bg-white px-3 py-2.5 rounded-lg border break-all"><?= e(SITE_URL) ?>/api/courier-webhook.php?courier=pathao</code>
                            <button onclick="copyUrl('<?= e(SITE_URL) ?>/api/courier-webhook.php?courier=pathao', this)" class="px-3 py-2 bg-white border rounded-lg text-xs hover:bg-gray-50 whitespace-nowrap">📋 Copy</button>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-3 border text-xs text-gray-600 space-y-1">
                        <p class="font-medium text-gray-700">📋 Pathao Setup Instructions:</p>
                        <p>1. Go to <a href="https://merchant.pathao.com/courier/developer-api" target="_blank" class="text-blue-600 underline">Pathao → Developer API → Webhook Integration</a></p>
                        <p>2. Paste the <b>Callback URL</b> above</p>
                        <p>3. Enter any <b>Secret</b> (e.g. a UUID) and paste the same here</p>
                        <p>4. Check <b>Select All</b> events → Click <b>Add Webhook</b></p>
                        <p>5. Pathao will send a test request — if secret matches, webhook is active ✅</p>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap gap-3">
                <button onclick="savePathaoConfig()" id="saveBtn" class="bg-blue-600 text-white px-8 py-3 rounded-lg text-sm font-semibold hover:bg-blue-700 shadow-sm">💾 Save & Connect</button>
                <button onclick="testConn()" class="bg-white border-2 border-gray-200 text-gray-700 px-6 py-3 rounded-lg text-sm font-semibold hover:bg-gray-50">🔌 Test Connection</button>
            </div>
            <div id="connMsg" class="hidden mt-4 px-4 py-3 rounded-lg text-sm font-medium"></div>
        </div>

        <!-- Available Endpoints -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="font-semibold text-gray-800 mb-4">📡 API Endpoints Integrated</h4>
            <div class="grid md:grid-cols-2 gap-2 text-sm">
                <?php foreach([
                    ['POST','Issue Token','OAuth2 Authentication','✅'],
                    ['GET','City List','All BD cities','✅'],
                    ['GET','Zone List','Zones per city','✅'],
                    ['GET','Area List','Areas per zone','✅'],
                    ['GET','Store List','Your pickup stores','✅'],
                    ['POST','Create Order','Book courier pickup','✅'],
                    ['POST','Price Plan','Delivery cost calc','✅'],
                    ['POST','Customer Check','Phone verification','✅'],
                ] as $ep): ?>
                <div class="flex items-center gap-2 p-2.5 bg-gray-50 rounded-lg">
                    <span class="px-2 py-0.5 <?=$ep[0]==='GET'?'bg-green-100 text-green-700':'bg-blue-100 text-blue-700'?> rounded text-xs font-mono font-bold"><?=$ep[0]?></span>
                    <span class="text-gray-800 font-medium"><?=$ep[1]?></span>
                    <span class="ml-auto text-xs text-gray-400"><?=$ep[2]?></span>
                    <span class="text-green-500"><?=$ep[3]?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="space-y-5">
        <!-- Connection Status -->
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-4">🔗 Connection Status</h4>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between"><span class="text-gray-500">API</span><span class="font-semibold <?=$connected?'text-green-600':'text-red-500'?>"><?=$connected?'✅ Active':'❌ Inactive'?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Environment</span><span class="font-medium"><?=ucfirst($pc['environment']?:'N/A')?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Store ID</span><span class="font-mono text-xs"><?=$pc['store_id']?:'—'?></span></div>
                <?php $daysLeft = $tokenExp > time() ? max(0,round(($tokenExp-time())/86400)) : 0; ?>
                <div class="flex justify-between"><span class="text-gray-500">Token Expires</span><span class="font-medium <?=$daysLeft<3?'text-red-600':'text-green-600'?>"><?=$tokenExp?$daysLeft.'d left':'Not set'?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Base URL</span><span class="text-xs text-gray-400 truncate max-w-[160px]"><?=($pc['environment']??'')==='sandbox'?'hermes-api.p-stageenv.xyz':'api-hermes.pathao.com'?></span></div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-3">⚡ Quick Actions</h4>
            <div class="space-y-2">
                <button onclick="fetchStores()" class="w-full text-left px-3 py-2.5 bg-gray-50 rounded-lg text-sm hover:bg-gray-100 transition">🏪 Fetch Stores</button>
                <button onclick="loadCities()" class="w-full text-left px-3 py-2.5 bg-gray-50 rounded-lg text-sm hover:bg-gray-100 transition">🏙️ Load City List</button>
                <button onclick="testConn()" class="w-full text-left px-3 py-2.5 bg-gray-50 rounded-lg text-sm hover:bg-gray-100 transition">🔄 Refresh Token</button>
            </div>
        </div>

        <!-- Stores -->
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-3">🏪 Your Pathao Stores</h4>
            <div id="storesList" class="space-y-2 text-sm text-gray-400"><p>Click "Fetch Stores" to load</p></div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'steadfast'): ?>
<!-- ========================================= -->
<!-- STEADFAST API — FULL INTEGRATION -->
<!-- ========================================= -->
<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <!-- Connection Card -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-green-50 rounded-xl flex items-center justify-center"><span class="text-2xl font-bold text-green-600">S</span></div>
                    <div>
                        <h3 class="font-bold text-gray-800 text-lg">Steadfast Courier API</h3>
                        <p class="text-xs text-gray-500">Get credentials from <a href="https://portal.packzy.com/user/api" target="_blank" class="text-blue-600 underline">portal.packzy.com → API</a></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-full <?=$sfConnected?'bg-green-50 border border-green-200':'bg-gray-50 border'?>">
                    <span class="w-2.5 h-2.5 rounded-full <?=$sfConnected?'bg-green-500 pulse-d':'bg-gray-300'?>"></span>
                    <span class="text-xs font-semibold <?=$sfConnected?'text-green-700':'text-gray-500'?>"><?=$sfConnected?'Connected':'Disconnected'?></span>
                </div>
            </div>

            <!-- API Credentials -->
            <div class="bg-gray-50 rounded-xl p-5 mb-5 border border-dashed border-gray-300">
                <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center justify-between">
                    <span>🔑 API Credentials</span>
                    <button type="button" id="sf_toggle_all_btn" onclick="sfToggleAll()" class="text-xs font-medium text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3 py-1 rounded-full border border-blue-200 transition-colors">👁 Show All</button>
                </h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">API Key <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" id="sf_api_key" value="<?=e($sf['api_key'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono bg-white pr-10" placeholder="Your Steadfast API Key">
                            <button type="button" onclick="sfTogglePw(this)" class="sf-pw-toggle absolute right-2 top-2 text-gray-400 hover:text-gray-600" title="Show"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Secret Key <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" id="sf_secret_key" value="<?=e($sf['secret_key'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono bg-white pr-10" placeholder="Your Steadfast Secret Key">
                            <button type="button" onclick="sfTogglePw(this)" class="sf-pw-toggle absolute right-2 top-2 text-gray-400 hover:text-gray-600" title="Show"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Webhook Bearer Token</label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <input type="password" id="sf_webhook_token" value="<?=e($sf['webhook_token'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono bg-white pr-10" placeholder="Token for webhook authentication">
                            <button type="button" onclick="sfTogglePw(this)" class="sf-pw-toggle absolute right-2 top-2 text-gray-400 hover:text-gray-600" title="Show"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                        <button type="button" onclick="sfGenToken()" class="px-3 py-2 bg-gray-100 border rounded-lg text-xs hover:bg-gray-200 whitespace-nowrap" title="Generate a random token">🔑 Generate</button>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1">Auto-generate a token, save settings, then paste the same token in <a href="https://portal.packzy.com/user/webhook/add" target="_blank" class="text-blue-500 underline">Steadfast Webhook Settings</a> → Auth Token (Bearer)</p>
                </div>
            </div>

            <!-- Login Credentials (Optional) -->
            <div class="bg-yellow-50 rounded-xl p-5 mb-5 border border-yellow-200">
                <h4 class="text-sm font-semibold text-yellow-800 mb-1">⚠️ Steadfast Login Credentials (Optional)</h4>
                <p class="text-xs text-yellow-700 mb-3">সম্প্রতি স্টেডফাস্ট তাদের সিস্টেমে কিছু পরিবর্তন এনেছে, যার ফলে লগইন ডিটেইলস ছাড়া কাস্টমারের রেটিং চেক করা কঠিন হয়ে পড়েছে। নিরবচ্ছিন্নভাবে কাস্টমারের কুরিয়ার রেটিং দেখতে আপনার স্টেডফাস্ট ইমেইল ও পাসওয়ার্ড দিয়ে কানেক্ট করুন।</p>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Steadfast Account Email</label>
                        <input type="email" id="sf_email" value="<?=e($sfEmail)?>" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-white" placeholder="your-email@example.com">
                        <p class="text-[10px] text-gray-400 mt-0.5">Your Steadfast portal login email</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Steadfast Account Password</label>
                        <div class="relative">
                            <input type="password" id="sf_password" value="<?=e($sfPass)?>" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-white pr-10" placeholder="Enter your Steadfast password">
                            <button type="button" onclick="sfTogglePw(this)" class="sf-pw-toggle absolute right-2 top-2 text-gray-400 hover:text-gray-600" title="Show"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                        <p class="text-[10px] text-gray-400 mt-0.5">Your Steadfast portal login password</p>
                    </div>
                </div>
            </div>

            <!-- Default Shipping Note -->
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-1">Default Shipping Note</label>
                <textarea id="sf_default_note" rows="2" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-white" placeholder="Default Shipping Note"><?=e($sfDefaultNote)?></textarea>
                <p class="text-[10px] text-gray-400 mt-1">এই নোটটি প্রতিটি অর্ডারের সাথে কুরিয়ার কোম্পানির কাছে ডেলিভারি নোট হিসেবে যাবে আপনি এটি অর্ডার নেওয়ার সময় চেঞ্জ করতে পারবেন</p>
            </div>

            <!-- Toggles -->
            <div class="space-y-3 mb-5">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border">
                    <div><span class="text-sm font-medium text-gray-800">Active</span><p class="text-[10px] text-gray-500">If you want to activate this delivery method, turn this on.</p></div>
                    <label class="relative inline-block w-11 h-6 cursor-pointer"><input type="checkbox" id="sf_active" <?=$sfActive==='1'?'checked':''?> class="sr-only peer"><div class="w-11 h-6 bg-gray-300 peer-checked:bg-green-500 rounded-full after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-5 after:h-5 after:bg-white after:rounded-full after:transition-all peer-checked:after:translate-x-5"></div></label>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border">
                    <div><span class="text-sm font-medium text-gray-800">Send Product Names to Courier</span><p class="text-[10px] text-gray-500">When enabled, product names are sent as item description. Disable to keep product info private.</p></div>
                    <label class="relative inline-block w-11 h-6 cursor-pointer"><input type="checkbox" id="sf_send_products" <?=$sfSendProducts!=='0'?'checked':''?> class="sr-only peer"><div class="w-11 h-6 bg-gray-300 peer-checked:bg-green-500 rounded-full after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-5 after:h-5 after:bg-white after:rounded-full after:transition-all peer-checked:after:translate-x-5"></div></label>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap gap-3">
                <button onclick="sfSaveSettings()" class="bg-green-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-green-700 transition">💾 Save Settings</button>
                <button onclick="sfTestConnection()" class="bg-gray-100 text-gray-700 px-4 py-2.5 rounded-lg text-sm hover:bg-gray-200 transition">🔌 Test Connection</button>
                <button onclick="sfCheckBalance()" class="bg-blue-50 text-blue-700 px-4 py-2.5 rounded-lg text-sm hover:bg-blue-100 transition">💰 Check Balance</button>
                <button onclick="sfSyncAll()" class="bg-purple-50 text-purple-700 px-4 py-2.5 rounded-lg text-sm hover:bg-purple-100 transition">🔄 Sync All Orders</button>
            </div>
            <div id="sf_result" class="hidden mt-3 p-3 rounded-lg text-sm"></div>
        </div>

        <!-- Webhook Info -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🔗 Webhook Configuration</h4>
            <p class="text-xs text-gray-500 mb-3">Set this URL in your <a href="https://portal.packzy.com/user/webhook/add" target="_blank" class="text-blue-600 underline">Steadfast Webhook Settings</a>:</p>
            <div class="bg-blue-50 rounded-lg border border-blue-200 p-3">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-semibold text-blue-700">Callback URL</span>
                    <button onclick="navigator.clipboard.writeText(document.getElementById('sf-wh-url').textContent);this.textContent='✅ Copied!';setTimeout(()=>this.textContent='📋 Copy',2000)" class="text-xs bg-white px-2 py-1 rounded border hover:bg-gray-50">📋 Copy</button>
                </div>
                <code id="sf-wh-url" class="block text-xs font-mono text-gray-700 bg-white px-3 py-2 rounded border break-all"><?= e(SITE_URL) ?>/api/courier-webhook.php?courier=steadfast</code>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 text-xs text-gray-500">
                <div><strong>Delivery Status Update:</strong> Auto-updates order status when courier delivers/cancels/holds</div>
                <div><strong>Tracking Update:</strong> Shows tracking messages like "Package arrived at sorting center"</div>
            </div>
        </div>

        <!-- Consignment Lookup -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🔍 Consignment Lookup</h4>
            <div class="flex gap-2">
                <input type="text" id="sf_lookup_cid" class="flex-1 px-3 py-2 border rounded-lg text-sm font-mono" placeholder="Enter Consignment ID or Invoice Number">
                <button onclick="sfLookup()" class="bg-gray-800 text-white px-4 py-2 rounded-lg text-sm hover:bg-gray-700">Search</button>
            </div>
            <div id="sf_lookup_result" class="hidden mt-3 p-3 rounded-lg text-sm"></div>
        </div>
    </div>

    <!-- Right Sidebar -->
    <div class="space-y-6">
        <!-- Balance Card -->
        <div class="bg-white rounded-xl shadow-sm border p-6 text-center">
            <h4 class="text-sm font-bold text-gray-800 mb-2">💰 Account Balance</h4>
            <div class="text-3xl font-bold text-green-600" id="sf-balance"><?=$sfBalance?'৳'.number_format(floatval($sfBalance)):'—'?></div>
            <p class="text-[10px] text-gray-400 mt-1">Current Steadfast balance</p>
            <button onclick="sfCheckBalance()" class="mt-3 w-full py-2 bg-gray-100 text-gray-700 rounded-lg text-xs hover:bg-gray-200">🔄 Refresh</button>
        </div>

        <!-- Stats -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="text-sm font-bold text-gray-800 mb-3">📊 Steadfast Orders</h4>
            <div class="space-y-2">
                <div class="flex justify-between text-sm"><span class="text-gray-600">Total Uploaded</span><b><?= number_format(intval($sfStats['total'])) ?></b></div>
                <div class="flex justify-between text-sm"><span class="text-gray-600">In Transit</span><b class="text-blue-600"><?= number_format(intval($sfStats['shipped'])) ?></b></div>
                <div class="flex justify-between text-sm"><span class="text-gray-600">Delivered</span><b class="text-green-600"><?= number_format(intval($sfStats['delivered'])) ?></b></div>
                <div class="flex justify-between text-sm"><span class="text-gray-600">Cancelled</span><b class="text-red-600"><?= number_format(intval($sfStats['cancelled'])) ?></b></div>
                <div class="h-2 bg-gray-100 rounded-full mt-2"><div class="h-full bg-green-500 rounded-full" style="width:<?= min(100,$sfRate) ?>%"></div></div>
                <div class="text-center text-xs font-bold <?=$sfRate>=70?'text-green-600':($sfRate>=40?'text-yellow-600':'text-red-600')?>">Success Rate: <?= $sfRate ?>%</div>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🔗 Quick Links</h4>
            <div class="space-y-2">
                <a href="https://portal.packzy.com" target="_blank" class="block text-xs text-blue-600 hover:underline">📦 Steadfast Portal</a>
                <a href="https://portal.packzy.com/user/api" target="_blank" class="block text-xs text-blue-600 hover:underline">🔑 API Settings</a>
                <a href="https://portal.packzy.com/user/webhook/add" target="_blank" class="block text-xs text-blue-600 hover:underline">🔗 Webhook Settings</a>
                <a href="https://portal.packzy.com/user/consignments" target="_blank" class="block text-xs text-blue-600 hover:underline">📋 All Consignments</a>
            </div>
        </div>

        <!-- Recent Webhook Logs -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="text-sm font-bold text-gray-800 mb-3">📝 Recent Webhooks</h4>
            <div id="sf-wh-logs" class="space-y-1 text-xs text-gray-500 max-h-48 overflow-auto">Loading...</div>
        </div>
    </div>
</div>

<script>
var SF_API = '<?=SITE_URL?>/api/steadfast-actions.php';
function sfPost(a,d){return fetch(SF_API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action:a},d||{}))}).then(function(r){return r.json()})}
function sfMsg(m,ok){var e=document.getElementById('sf_result');e.classList.remove('hidden');e.className='mt-3 p-3 rounded-lg text-sm '+(ok?'bg-green-50 text-green-700 border border-green-200':'bg-red-50 text-red-700 border border-red-200');e.textContent=(ok?'✅ ':'❌ ')+m}
var _eyeOpen='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
var _eyeClosed='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
function sfTogglePw(btn){var inp=btn.closest('.relative').querySelector('input');if(!inp)return;var show=inp.type==='password';inp.type=show?'text':'password';btn.innerHTML=show?_eyeClosed:_eyeOpen;btn.title=show?'Hide':'Show'}
function sfToggleAll(){var fields=document.querySelectorAll('.sf-pw-toggle');var anyHidden=false;fields.forEach(function(btn){var inp=btn.closest('.relative').querySelector('input');if(inp&&inp.type==='password')anyHidden=true});fields.forEach(function(btn){var inp=btn.closest('.relative').querySelector('input');if(!inp)return;if(anyHidden){inp.type='text';btn.innerHTML=_eyeClosed;btn.title='Hide'}else{inp.type='password';btn.innerHTML=_eyeOpen;btn.title='Show'}});var ab=document.getElementById('sf_toggle_all_btn');if(ab)ab.textContent=anyHidden?'🙈 Hide All':'👁 Show All'}
function sfSaveSettings(){sfPost('save_settings',{api_key:document.getElementById('sf_api_key').value,secret_key:document.getElementById('sf_secret_key').value,webhook_token:document.getElementById('sf_webhook_token').value,email:document.getElementById('sf_email').value,password:document.getElementById('sf_password').value,default_note:document.getElementById('sf_default_note').value,send_product_names:document.getElementById('sf_send_products').checked?'1':'0',active:document.getElementById('sf_active').checked?'1':'0'}).then(function(d){sfMsg(d.message||'Saved!',d.success!==false);if(d.success!==false)setTimeout(function(){location.reload()},1200)}).catch(function(e){sfMsg(e.message,false)})}
function sfTestConnection(){sfPost('test_connection',{api_key:document.getElementById('sf_api_key').value,secret_key:document.getElementById('sf_secret_key').value}).then(function(d){if(d.success){sfMsg('Connected! Balance: ৳'+Number(d.balance).toLocaleString(),true);document.getElementById('sf-balance').textContent='৳'+Number(d.balance).toLocaleString()}else sfMsg(d.error||'Connection failed',false)}).catch(function(e){sfMsg(e.message,false)})}
function sfCheckBalance(){sfPost('check_balance').then(function(d){if(d.success){document.getElementById('sf-balance').textContent='৳'+Number(d.balance).toLocaleString();sfMsg('Balance: ৳'+Number(d.balance).toLocaleString(),true)}else sfMsg(d.error||'Failed',false)}).catch(function(e){sfMsg(e.message,false)})}
function sfSyncAll(){sfMsg('Syncing...',true);sfPost('bulk_sync',{limit:50}).then(function(d){sfMsg('Synced '+(d.total||0)+' orders: '+(d.updated||0)+' updated, '+(d.errors||0)+' errors',!d.errors)}).catch(function(e){sfMsg(e.message,false)})}
function sfGenToken(){var s='kb-sf-';for(var i=0;i<24;i++)s+='0123456789abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random()*36)];var inp=document.getElementById('sf_webhook_token');inp.value=s;inp.type='text';sfMsg('Token generated! Click "Save Settings" then copy the same token to Steadfast webhook settings.',true)}
function sfLookup(){var c=document.getElementById('sf_lookup_cid').value.trim();if(!c)return;var e=document.getElementById('sf_lookup_result');e.classList.remove('hidden');e.className='mt-3 p-3 rounded-lg text-sm bg-blue-50 text-blue-700';e.textContent='🔍 Searching...';sfPost('check_consignment',{consignment_id:c}).then(function(d){if(d.success&&d.data){var i=d.data;e.innerHTML='<b>CID: '+(i.consignment_id||c)+'</b><br>Status: <b>'+(i.delivery_status||'?')+'</b> | Invoice: '+(i.invoice||'—')+' | COD: ৳'+(i.cod_amount||0)+(i.tracking_message?'<br>📍 '+i.tracking_message:'')+'<br><a href="https://steadfast.com.bd/user/consignment/'+(i.consignment_id||c)+'" target="_blank" class="text-blue-600 underline text-xs">Open in Steadfast →</a>'}else{e.className='mt-3 p-3 rounded-lg text-sm bg-red-50 text-red-700';e.textContent='❌ '+(d.error||'Not found')}}).catch(function(x){e.className='mt-3 p-3 rounded-lg text-sm bg-red-50 text-red-700';e.textContent='❌ '+x.message})}
// Load webhook logs
sfPost('webhook_logs',{limit:10}).then(function(d){var e=document.getElementById('sf-wh-logs');if(d.logs&&d.logs.length){e.innerHTML=d.logs.map(function(l){var p='';try{var j=JSON.parse(l.payload);p=(j.status||j.notification_type||'')+' '+(j.invoice||'')}catch(x){}return '<div class="py-1 border-b border-gray-100"><span class="text-gray-400">'+(l.created_at||'').substring(5,16)+'</span> '+p+(l.result?' → <b>'+l.result.substring(0,50)+'</b>':'')+'</div>'}).join('')}else e.innerHTML='<p class="text-gray-400">No webhook logs yet</p>'}).catch(function(){document.getElementById('sf-wh-logs').innerHTML='<p class="text-gray-400">—</p>'});
</script>


<?php elseif ($tab === 'redx'): ?>
<!-- ========================================= -->
<!-- REDX API — FULL INTEGRATION -->
<!-- ========================================= -->
<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <!-- Connection Card -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-red-50 rounded-xl flex items-center justify-center"><span class="text-2xl font-bold text-red-500">R</span></div>
                    <div>
                        <h3 class="font-bold text-gray-800 text-lg">RedX Delivery API</h3>
                        <p class="text-xs text-gray-500">Get credentials from <a href="https://redx.com.bd/developer-api/" target="_blank" class="text-blue-600 underline">redx.com.bd → Developer API</a></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-full <?=$rxConnected?'bg-green-50 border border-green-200':'bg-gray-50 border'?>">
                    <span class="w-2.5 h-2.5 rounded-full <?=$rxConnected?'bg-green-500 pulse-d':'bg-gray-300'?>"></span>
                    <span class="text-xs font-semibold <?=$rxConnected?'text-green-700':'text-gray-500'?>"><?=$rxConnected?'Connected':'Disconnected'?></span>
                </div>
            </div>

            <!-- API Token -->
            <div class="bg-gray-50 rounded-xl p-5 mb-5 border border-dashed border-gray-300">
                <h4 class="text-sm font-semibold text-gray-700 mb-3">🔑 API Credentials</h4>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">API Access Token <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="password" id="rx_api_token" value="<?=e($rx['api_token'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono bg-white pr-10" placeholder="Your RedX API token from developer portal">
                        <button type="button" onclick="sfTogglePw(this)" class="sf-pw-toggle absolute right-2 top-2 text-gray-400 hover:text-gray-600" title="Show"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1">Copy the production token from <a href="https://redx.com.bd/developer-api/" target="_blank" class="text-blue-500 underline">RedX Developer API page</a></p>
                </div>

                <div class="mt-4">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Environment</label>
                    <select id="rx_environment" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-white">
                        <option value="production" <?=$rx['environment']==='production'?'selected':''?>>🟢 Production (Live)</option>
                        <option value="sandbox" <?=$rx['environment']==='sandbox'?'selected':''?>>🟡 Sandbox (Testing)</option>
                    </select>
                </div>

                <div class="mt-4">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Webhook Token</label>
                    <div class="flex gap-2">
                        <input type="text" id="rx_webhook_token" value="<?=e($rx['webhook_token'])?>" class="flex-1 px-3 py-2.5 border rounded-lg text-sm font-mono bg-white" placeholder="Secret token for webhook verification">
                        <button onclick="rxGenerateToken()" class="px-3 py-2 bg-gray-100 border rounded-lg text-xs hover:bg-gray-200" title="Generate random token">🔑</button>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1">This token will be appended to your webhook URL as <code class="bg-gray-100 px-1 rounded">?courier=redx&token=YOUR_TOKEN</code></p>
                </div>
            </div>

            <!-- Default Settings -->
            <div class="bg-blue-50 rounded-xl p-5 mb-5 border border-dashed border-blue-200">
                <h4 class="text-sm font-semibold text-blue-700 mb-3">📦 Default Parcel Settings</h4>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Default Pickup Store ID</label>
                        <input type="text" id="rx_default_pickup_store_id" value="<?=e($rxDefaultPickupStore)?>" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-white" placeholder="e.g. 1">
                        <p class="text-[10px] text-gray-400 mt-0.5">Click "Load Stores" to find your store IDs</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Default Parcel Weight (grams)</label>
                        <input type="number" id="rx_default_weight" value="<?=e($rxDefaultWeight)?>" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-white" placeholder="500" min="100" step="50">
                    </div>
                </div>
            </div>

            <!-- Default Note -->
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-1">Default Shipping Note</label>
                <textarea id="rx_default_note" rows="2" class="w-full px-3 py-2.5 border rounded-lg text-sm bg-white" placeholder="Default instruction sent with every parcel"><?=e($rxDefaultNote)?></textarea>
                <p class="text-[10px] text-gray-400 mt-1">এই নোটটি প্রতিটি অর্ডারের সাথে RedX-এর কাছে ডেলিভারি ইনস্ট্রাকশন হিসেবে যাবে</p>
            </div>

            <!-- Toggles -->
            <div class="space-y-3 mb-5">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border">
                    <div><span class="text-sm font-medium text-gray-800">Active</span><p class="text-[10px] text-gray-500">Enable RedX as a delivery option</p></div>
                    <label class="relative inline-block w-11 h-6 cursor-pointer"><input type="checkbox" id="rx_active" <?=$rxActive==='1'?'checked':''?> class="sr-only peer"><div class="w-11 h-6 bg-gray-300 peer-checked:bg-green-500 rounded-full after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-5 after:h-5 after:bg-white after:rounded-full after:transition-all peer-checked:after:translate-x-5"></div></label>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border">
                    <div><span class="text-sm font-medium text-gray-800">Send Product Names</span><p class="text-[10px] text-gray-500">Include product names as parcel instruction</p></div>
                    <label class="relative inline-block w-11 h-6 cursor-pointer"><input type="checkbox" id="rx_send_products" <?=$rxSendProducts!=='0'?'checked':''?> class="sr-only peer"><div class="w-11 h-6 bg-gray-300 peer-checked:bg-green-500 rounded-full after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-5 after:h-5 after:bg-white after:rounded-full after:transition-all peer-checked:after:translate-x-5"></div></label>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap gap-3">
                <button onclick="rxSaveSettings()" class="bg-red-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-red-700">💾 Save Settings</button>
                <button onclick="rxTestConnection()" class="bg-gray-100 text-gray-700 px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-200 border">🔌 Test Connection</button>
                <button onclick="rxLoadStores()" class="bg-blue-50 text-blue-700 px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-100 border border-blue-200">🏪 Load Stores</button>
                <button onclick="rxSyncAll()" class="bg-purple-50 text-purple-700 px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-purple-100 border border-purple-200">🔄 Sync All Orders</button>
            </div>
            <div id="rx_result" class="hidden mt-3 p-3 rounded-lg text-sm"></div>

            <!-- Pickup Stores List -->
            <div id="rx_stores_panel" class="hidden mt-5">
                <h4 class="text-sm font-bold text-gray-800 mb-2">🏪 Your Pickup Stores</h4>
                <div id="rx_stores_list" class="space-y-2"></div>
            </div>
        </div>

        <!-- Parcel Lookup -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🔍 Track / Lookup Parcel</h4>
            <div class="flex gap-2">
                <input type="text" id="rx_lookup_tid" class="flex-1 px-3 py-2.5 border rounded-lg text-sm font-mono" placeholder="Enter RedX Tracking ID">
                <button onclick="rxLookup()" class="bg-red-600 text-white px-5 py-2 rounded-lg text-sm hover:bg-red-700">🔍 Track</button>
                <button onclick="rxGetInfo()" class="bg-gray-100 text-gray-700 px-5 py-2 rounded-lg text-sm hover:bg-gray-200 border">📋 Info</button>
            </div>
            <div id="rx_lookup_result" class="hidden mt-3 p-3 rounded-lg text-sm"></div>
        </div>

        <!-- Webhook URL -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🔗 Webhook Configuration</h4>
            <p class="text-xs text-gray-500 mb-3">Paste this URL into RedX developer portal → Webhook section → Callback URL, then click "Save Link"</p>
            <?php
                $rxWhUrl = SITE_URL . '/api/redx-webhook.php' . (!empty($rx['webhook_token']) ? '?token=' . urlencode($rx['webhook_token']) : '');
            ?>
            <div class="flex gap-2">
                <code id="rx-wh-url" class="flex-1 block text-sm font-mono text-gray-800 bg-gray-50 px-4 py-3 rounded-lg border break-all select-all"><?= e($rxWhUrl) ?></code>
                <button onclick="navigator.clipboard.writeText(document.getElementById('rx-wh-url').textContent).then(()=>this.textContent='✅ Copied').catch(()=>{})" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700 whitespace-nowrap">📋 Copy</button>
            </div>
            <div class="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-xs text-yellow-700">
                <p class="font-semibold mb-1">⚠️ Setup Steps:</p>
                <p>1. Go to <a href="https://redx.com.bd/developer-api/" target="_blank" class="text-blue-600 underline">redx.com.bd/developer-api</a></p>
                <p>2. Scroll down to "Webhook" section</p>
                <p>3. Paste the callback URL above and click "Save Link"</p>
                <p>4. RedX will send POST requests to this URL whenever parcel status changes</p>
            </div>

            <!-- Status Mapping Reference -->
            <div class="mt-4">
                <button onclick="document.getElementById('rx-status-ref').classList.toggle('hidden')" class="text-xs text-blue-600 hover:underline">📋 Show Status Mapping Reference</button>
                <div id="rx-status-ref" class="hidden mt-2 bg-gray-50 rounded-lg p-3 text-xs">
                    <table class="w-full">
                        <thead><tr class="text-left text-gray-500 border-b"><th class="py-1">RedX Status</th><th class="py-1">→ Our Status</th><th class="py-1">Meaning</th></tr></thead>
                        <tbody class="text-gray-700">
                            <tr class="border-b"><td class="py-1"><code>ready-for-delivery</code></td><td>—</td><td>Parcel received from merchant</td></tr>
                            <tr class="border-b"><td class="py-1"><code>delivery-in-progress</code></td><td>—</td><td>Dispatched to rider</td></tr>
                            <tr class="border-b"><td class="py-1"><code>delivered</code></td><td class="text-green-600 font-bold">delivered</td><td>Delivered by rider</td></tr>
                            <tr class="border-b"><td class="py-1"><code>agent-hold</code></td><td class="text-yellow-600 font-bold">on_hold</td><td>On hold with agent</td></tr>
                            <tr class="border-b"><td class="py-1"><code>agent-returning</code></td><td class="text-orange-600 font-bold">pending_return</td><td>Return in progress</td></tr>
                            <tr class="border-b"><td class="py-1"><code>returned</code></td><td class="text-red-600 font-bold">pending_return</td><td>Parcel returned</td></tr>
                            <tr><td class="py-1"><code>agent-area-change</code></td><td>—</td><td>Area change requested</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Sidebar -->
    <div class="space-y-6">
        <!-- Stats -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="text-sm font-bold text-gray-800 mb-3">📊 RedX Orders</h4>
            <div class="space-y-2">
                <div class="flex justify-between text-sm"><span class="text-gray-600">Total Uploaded</span><b><?= number_format(intval($rxStats['total'])) ?></b></div>
                <div class="flex justify-between text-sm"><span class="text-gray-600">In Transit</span><b class="text-blue-600"><?= number_format(intval($rxStats['shipped'])) ?></b></div>
                <div class="flex justify-between text-sm"><span class="text-gray-600">Delivered</span><b class="text-green-600"><?= number_format(intval($rxStats['delivered'])) ?></b></div>
                <div class="flex justify-between text-sm"><span class="text-gray-600">Cancelled</span><b class="text-red-600"><?= number_format(intval($rxStats['cancelled'])) ?></b></div>
                <div class="h-2 bg-gray-100 rounded-full mt-2"><div class="h-full bg-red-500 rounded-full" style="width:<?= min(100,$rxRate) ?>%"></div></div>
                <div class="text-center text-xs font-bold <?=$rxRate>=70?'text-green-600':($rxRate>=40?'text-yellow-600':'text-red-600')?>">Success Rate: <?= $rxRate ?>%</div>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="text-sm font-bold text-gray-800 mb-3">🔗 Quick Links</h4>
            <div class="space-y-2">
                <a href="https://redx.com.bd" target="_blank" class="block text-xs text-blue-600 hover:underline">📦 RedX Dashboard</a>
                <a href="https://redx.com.bd/developer-api/" target="_blank" class="block text-xs text-blue-600 hover:underline">🔑 Developer API Portal</a>
                <a href="https://redx.com.bd/track-parcel" target="_blank" class="block text-xs text-blue-600 hover:underline">📍 Track Parcel (Public)</a>
            </div>
        </div>

        <!-- Recent Webhook Logs -->
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="text-sm font-bold text-gray-800 mb-3">📝 Recent Webhooks</h4>
            <div id="rx-wh-logs" class="space-y-1 text-xs text-gray-500 max-h-48 overflow-auto">Loading...</div>
        </div>
    </div>
</div>

<script>
var RX_API = '<?=SITE_URL?>/api/redx-actions.php';
function rxPost(a,d){return fetch(RX_API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action:a},d||{}))}).then(function(r){return r.json()})}
function rxMsg(m,ok){var e=document.getElementById('rx_result');e.classList.remove('hidden');e.className='mt-3 p-3 rounded-lg text-sm '+(ok?'bg-green-50 text-green-700 border border-green-200':'bg-red-50 text-red-700 border border-red-200');e.textContent=(ok?'✅ ':'❌ ')+m}

function rxSaveSettings(){
    rxPost('save_settings',{
        api_token: document.getElementById('rx_api_token').value,
        environment: document.getElementById('rx_environment').value,
        webhook_token: document.getElementById('rx_webhook_token').value,
        default_pickup_store_id: document.getElementById('rx_default_pickup_store_id').value,
        default_weight: document.getElementById('rx_default_weight').value,
        default_note: document.getElementById('rx_default_note').value,
        send_product_names: document.getElementById('rx_send_products').checked?'1':'0',
        active: document.getElementById('rx_active').checked?'1':'0'
    }).then(function(d){rxMsg(d.message||'Saved!',d.success!==false);if(d.success!==false)setTimeout(function(){location.reload()},1200)}).catch(function(e){rxMsg(e.message,false)})
}

function rxTestConnection(){
    rxMsg('Testing connection...',true);
    rxPost('test_connection',{
        api_token: document.getElementById('rx_api_token').value,
        environment: document.getElementById('rx_environment').value
    }).then(function(d){
        if(d.success){
            rxMsg(d.message||'Connected!',true);
            if(d.pickup_stores&&d.pickup_stores.length) rxRenderStores(d.pickup_stores);
        } else rxMsg(d.error||'Connection failed',false)
    }).catch(function(e){rxMsg(e.message,false)})
}

function rxLoadStores(){
    rxPost('get_pickup_stores').then(function(d){
        if(d.success&&d.data){
            var stores=d.data.pickup_stores||[];
            rxRenderStores(stores);
            rxMsg('Found '+stores.length+' pickup store(s)',true);
        }else rxMsg(d.error||'Failed to load stores',false)
    }).catch(function(e){rxMsg(e.message,false)})
}

function rxRenderStores(stores){
    var panel=document.getElementById('rx_stores_panel');
    var list=document.getElementById('rx_stores_list');
    panel.classList.remove('hidden');
    if(!stores.length){list.innerHTML='<p class="text-gray-400 text-xs">No pickup stores found</p>';return}
    list.innerHTML=stores.map(function(s){
        return '<div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border">'+
            '<div><b class="text-sm">'+s.name+'</b><br><span class="text-xs text-gray-500">ID: '+s.id+' | '+s.address+' | '+(s.area_name||'')+'</span></div>'+
            '<button onclick="document.getElementById(\'rx_default_pickup_store_id\').value=\''+s.id+'\'" class="px-3 py-1.5 bg-red-50 text-red-600 rounded text-xs hover:bg-red-100 border border-red-200">Use This</button>'+
        '</div>'
    }).join('')
}

function rxSyncAll(){
    rxMsg('Syncing RedX orders...',true);
    rxPost('bulk_sync',{limit:50}).then(function(d){
        rxMsg('Synced '+(d.total||0)+' orders: '+(d.updated||0)+' updated, '+(d.errors||0)+' errors',!(d.errors>0))
    }).catch(function(e){rxMsg(e.message,false)})
}

function rxLookup(){
    var tid=document.getElementById('rx_lookup_tid').value.trim();if(!tid)return;
    var e=document.getElementById('rx_lookup_result');
    e.classList.remove('hidden');e.className='mt-3 p-3 rounded-lg text-sm bg-blue-50 text-blue-700';e.textContent='🔍 Tracking...';
    rxPost('track_parcel',{tracking_id:tid}).then(function(d){
        if(d.success&&d.data&&d.data.tracking){
            var items=d.data.tracking;
            e.innerHTML='<b>Tracking: '+tid+'</b> ('+items.length+' events)<div class="mt-2 space-y-1 max-h-40 overflow-auto">'+
                items.map(function(t){return '<div class="py-1 border-b border-blue-100"><span class="text-gray-400 text-xs">'+(t.time||'').substring(0,16).replace('T',' ')+'</span> '+t.message_en+'</div>'}).join('')+'</div>';
        }else{e.className='mt-3 p-3 rounded-lg text-sm bg-red-50 text-red-700';e.textContent='❌ '+(d.error||'Not found')}
    }).catch(function(x){e.className='mt-3 p-3 rounded-lg text-sm bg-red-50 text-red-700';e.textContent='❌ '+x.message})
}

function rxGetInfo(){
    var tid=document.getElementById('rx_lookup_tid').value.trim();if(!tid)return;
    var e=document.getElementById('rx_lookup_result');
    e.classList.remove('hidden');e.className='mt-3 p-3 rounded-lg text-sm bg-blue-50 text-blue-700';e.textContent='🔍 Loading...';
    rxPost('parcel_info',{tracking_id:tid}).then(function(d){
        if(d.success&&d.data&&d.data.parcel){
            var p=d.data.parcel;
            e.innerHTML='<b>'+tid+'</b> — Status: <b class="'+(p.status==='delivered'?'text-green-600':'text-orange-600')+'">'+p.status+'</b><br>'+
                'Customer: '+p.customer_name+' | 📞 '+p.customer_phone+'<br>'+
                'COD: ৳'+Number(p.cash_collection_amount||0).toLocaleString()+' | Charge: ৳'+Number(p.charge||0).toLocaleString()+' | Weight: '+(p.parcel_weight||0)+'g<br>'+
                'Area: '+p.delivery_area+' | Invoice: '+(p.merchant_invoice_id||'—')+'<br>'+
                '<a href="https://redx.com.bd/track-parcel/'+tid+'" target="_blank" class="text-blue-600 underline text-xs mt-1 inline-block">Open in RedX →</a>';
        }else{e.className='mt-3 p-3 rounded-lg text-sm bg-red-50 text-red-700';e.textContent='❌ '+(d.error||'Not found')}
    }).catch(function(x){e.className='mt-3 p-3 rounded-lg text-sm bg-red-50 text-red-700';e.textContent='❌ '+x.message})
}

function rxGenerateToken(){
    var t='';for(var i=0;i<32;i++)t+='abcdefghijklmnopqrstuvwxyz0123456789'.charAt(Math.floor(Math.random()*36));
    document.getElementById('rx_webhook_token').value=t;
}

// Load webhook logs
rxPost('webhook_logs',{limit:10}).then(function(d){
    var e=document.getElementById('rx-wh-logs');
    if(d.logs&&d.logs.length){
        e.innerHTML=d.logs.map(function(l){
            var p='';try{var j=JSON.parse(l.payload);p=(j.status||'')+' '+(j.tracking_number||j.invoice_number||'')}catch(x){}
            return '<div class="py-1 border-b border-gray-100"><span class="text-gray-400">'+(l.created_at||'').substring(5,16)+'</span> '+p+(l.result?' → <b>'+l.result.substring(0,50)+'</b>':'')+'</div>'
        }).join('')
    }else e.innerHTML='<p class="text-gray-400">No webhook logs yet</p>'
}).catch(function(){document.getElementById('rx-wh-logs').innerHTML='<p class="text-gray-400">—</p>'});
</script>


<?php elseif ($tab === 'carrybee'): ?>
<!-- ========================================= -->
<!-- CARRYBEE -->
<!-- ========================================= -->
<div class="max-w-2xl space-y-6">
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-green-50 rounded-xl flex items-center justify-center"><span class="text-2xl">🐝</span></div>
                <div>
                    <h3 class="font-bold text-gray-800 text-lg">CarryBee Courier</h3>
                    <p class="text-xs text-gray-500">Configure CarryBee API credentials</p>
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5 rounded-full <?=$cbConnected?'bg-green-50 border border-green-200':'bg-gray-50 border'?>">
                <span class="w-2.5 h-2.5 rounded-full <?=$cbConnected?'bg-green-500':'bg-gray-300'?>"></span>
                <span class="text-xs font-semibold <?=$cbConnected?'text-green-700':'text-gray-500'?>"><?=$cbConnected?'Configured':'Not Configured'?></span>
            </div>
        </div>
        <div class="space-y-4">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">API Key</label>
                <input type="text" id="cb_api_key" value="<?=e($cb['api_key'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" placeholder="CarryBee API Key">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Secret Key</label>
                <input type="password" id="cb_secret_key" value="<?=e($cb['secret_key'])?>" class="w-full px-3 py-2.5 border rounded-lg text-sm font-mono" placeholder="CarryBee Secret Key">
            </div>
            <button onclick="saveCarryBee()" class="bg-green-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-green-700">💾 Save</button>
            <div id="cb_result" class="hidden mt-3 p-3 rounded-lg text-sm"></div>
        </div>
    </div>
</div>
<script>
function saveCarryBee(){
    fetch('<?=SITE_URL?>/api/pathao-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_carrybee',api_key:document.getElementById('cb_api_key').value,secret_key:document.getElementById('cb_secret_key').value})}).then(r=>r.json()).then(d=>{const el=document.getElementById('cb_result');el.classList.remove('hidden');el.className='mt-3 p-3 rounded-lg text-sm bg-green-50 text-green-700';el.textContent='✅ CarryBee settings saved!';setTimeout(()=>location.reload(),1000)}).catch(e=>{const el=document.getElementById('cb_result');el.classList.remove('hidden');el.className='mt-3 p-3 rounded-lg text-sm bg-red-50 text-red-700';el.textContent='Error: '+e.message});
}
</script>

<?php elseif ($tab === 'webhooks'): ?>
<!-- ========================================= -->
<!-- WEBHOOK MANAGEMENT CENTER -->
<!-- ========================================= -->
<?php
$baseUrl = SITE_URL . '/api/courier-webhook.php';
$pathaoWhUrl = $baseUrl . '?courier=pathao';
$sfWhUrl = $baseUrl . '?courier=steadfast';
$cbWhUrl = $baseUrl . '?courier=carrybee';
$rxWhUrlBase = SITE_URL . '/api/redx-webhook.php' . (!empty($rx['webhook_token']) ? '?token=' . urlencode($rx['webhook_token']) : '');
$pathaoSecretSet = !empty($pc['webhook_secret']);
// Fetch recent webhook logs
$whLogs = [];
try { $whLogs = $db->fetchAll("SELECT * FROM courier_webhook_log ORDER BY id DESC LIMIT 20"); } catch (\Throwable $e) {}
$pathaoLogCount = 0; $sfLogCount = 0; $cbLogCount = 0; $rxLogCount = 0;
foreach ($whLogs as $wl) {
    if ($wl['courier'] === 'pathao') $pathaoLogCount++;
    elseif ($wl['courier'] === 'steadfast') $sfLogCount++;
    elseif ($wl['courier'] === 'carrybee') $cbLogCount++;
    elseif ($wl['courier'] === 'redx') $rxLogCount++;
}
?>
<div class="max-w-4xl space-y-6">

    <!-- ═══ PATHAO WEBHOOK (Primary) ═══ -->
    <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
        <div class="bg-red-600 px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center"><span class="text-xl font-bold text-white">P</span></div>
                <div>
                    <h3 class="font-bold text-white text-lg">Pathao Webhook</h3>
                    <p class="text-red-200 text-xs">Real-time order status updates from Pathao courier</p>
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5 rounded-full <?= $pathaoSecretSet ? 'bg-green-500/20' : 'bg-yellow-500/20' ?>">
                <span class="w-2 h-2 rounded-full <?= $pathaoSecretSet ? 'bg-green-300' : 'bg-yellow-300' ?>"></span>
                <span class="text-xs font-medium text-white"><?= $pathaoSecretSet ? 'Secret Set' : 'Not Configured' ?></span>
            </div>
        </div>
        <div class="p-6 space-y-5">
            
            <!-- Pathao Requirements -->
            <div class="bg-red-50 rounded-xl border border-red-200 p-4">
                <h4 class="text-sm font-bold text-red-700 mb-2">📋 Pathao Webhook Requirements</h4>
                <div class="grid md:grid-cols-2 gap-2 text-xs text-red-600">
                    <p>✅ URL must be reachable (HTTPS)</p>
                    <p>✅ Must resolve within 3 redirections</p>
                    <p>✅ Must respond within 10 seconds</p>
                    <p>✅ Must return HTTP status <b>202</b></p>
                    <p>✅ Must return <code class="bg-white px-1 rounded text-[10px]">X-Pathao-Merchant-Webhook-Integration-Secret</code> header</p>
                    <p>✅ Header value must match your webhook secret</p>
                </div>
                <p class="text-xs text-green-700 mt-2 font-medium">✅ All requirements are handled automatically by your webhook endpoint.</p>
            </div>

            <!-- Callback URL -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Callback URL <span class="text-red-500 font-bold">(paste into Pathao)</span></label>
                <div class="flex gap-2">
                    <code id="pathao-wh-url" class="flex-1 block text-sm font-mono text-gray-800 bg-gray-50 px-4 py-3 rounded-lg border break-all select-all"><?= e($pathaoWhUrl) ?></code>
                    <button onclick="copyUrl('<?= e($pathaoWhUrl) ?>', this)" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700 whitespace-nowrap">📋 Copy</button>
                </div>
            </div>

            <!-- Webhook Secret -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Webhook Secret</label>
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input type="password" id="wh_pathao_secret" value="<?= e($pc['webhook_secret']) ?>" class="w-full px-4 py-3 border rounded-lg text-sm font-mono bg-white pr-10" placeholder="Enter your webhook secret (same as in Pathao dashboard)">
                        <button onclick="togglePass(this)" class="absolute right-3 top-3 text-gray-400 hover:text-gray-600">👁</button>
                    </div>
                    <button onclick="generateSecret()" class="px-4 py-2 bg-gray-100 border rounded-lg text-sm hover:bg-gray-200 whitespace-nowrap" title="Generate a random secret">🔑 Generate</button>
                    <button onclick="saveWhSecret()" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700 whitespace-nowrap">💾 Save</button>
                </div>
                <p class="text-[10px] text-gray-500 mt-1">This secret is returned as the <code class="bg-gray-100 px-1 rounded">X-Pathao-Merchant-Webhook-Integration-Secret</code> header. Must match what you entered in Pathao's "Secret" field.</p>
            </div>

            <!-- Test Webhook -->
            <div class="flex items-center gap-3">
                <button onclick="testPathaoWebhook()" class="bg-red-100 text-red-700 px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-red-200 border border-red-200">🔌 Test Webhook Endpoint</button>
                <span id="pathaoWhTest" class="text-sm"></span>
            </div>

            <!-- Setup Steps -->
            <div class="bg-gray-50 rounded-xl border p-4">
                <h4 class="text-xs font-bold text-gray-700 mb-3 uppercase tracking-wider">Setup Steps</h4>
                <div class="space-y-2 text-sm text-gray-600">
                    <div class="flex items-start gap-2"><span class="bg-red-100 text-red-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">1</span><span>Go to <a href="https://merchant.pathao.com/courier/developer-api" target="_blank" class="text-blue-600 underline font-medium">merchant.pathao.com → Developer API</a></span></div>
                    <div class="flex items-start gap-2"><span class="bg-red-100 text-red-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">2</span><span>Paste the <b>Callback URL</b> from above</span></div>
                    <div class="flex items-start gap-2"><span class="bg-red-100 text-red-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">3</span><span>Enter the <b>Secret</b> (same value as above) — or click "Generate" to create one</span></div>
                    <div class="flex items-start gap-2"><span class="bg-red-100 text-red-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">4</span><span>Check <b>Select All</b> events (all 20 events)</span></div>
                    <div class="flex items-start gap-2"><span class="bg-red-100 text-red-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">5</span><span>Click <b>Add Webhook</b> — Pathao sends <code class="bg-white px-1 rounded text-xs">{"event":"webhook_integration"}</code> test</span></div>
                    <div class="flex items-start gap-2"><span class="bg-green-100 text-green-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">✓</span><span>If your secret matches, webhook is <b class="text-green-600">active</b>! Orders auto-update in real-time.</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ STEADFAST WEBHOOK (Full) ═══ -->
    <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
        <div class="bg-blue-600 px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center"><span class="text-xl font-bold text-white">S</span></div>
                <div>
                    <h3 class="font-bold text-white text-lg">Steadfast Webhook</h3>
                    <p class="text-blue-200 text-xs">Real-time order status updates from Steadfast courier</p>
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5 rounded-full <?= !empty($sf['webhook_token']) ? 'bg-green-500/20' : 'bg-yellow-500/20' ?>">
                <span class="w-2 h-2 rounded-full <?= !empty($sf['webhook_token']) ? 'bg-green-300' : 'bg-yellow-300' ?>"></span>
                <span class="text-xs font-medium text-white"><?= !empty($sf['webhook_token']) ? 'Token Set' : 'No Token' ?></span>
            </div>
        </div>
        <div class="p-6 space-y-5">

            <!-- Callback URL -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Callback URL <span class="text-blue-500 font-bold">(paste into Steadfast)</span></label>
                <div class="flex gap-2">
                    <code id="sf-wh-url2" class="flex-1 block text-sm font-mono text-gray-800 bg-gray-50 px-4 py-3 rounded-lg border break-all select-all"><?= e($sfWhUrl) ?></code>
                    <button onclick="copyUrl('<?= e($sfWhUrl) ?>', this)" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 whitespace-nowrap">📋 Copy</button>
                </div>
            </div>

            <!-- Bearer Token -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Auth Token (Bearer)</label>
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input type="password" id="wh_sf_token" value="<?= e($sf['webhook_token']) ?>" class="w-full px-4 py-3 border rounded-lg text-sm font-mono bg-white pr-10" placeholder="Bearer token for webhook authentication">
                        <button onclick="togglePass(this)" class="absolute right-3 top-3 text-gray-400 hover:text-gray-600">👁</button>
                    </div>
                    <button onclick="generateSfWhToken()" class="px-4 py-2 bg-gray-100 border rounded-lg text-sm hover:bg-gray-200 whitespace-nowrap" title="Generate a random token">🔑 Generate</button>
                    <button onclick="saveSfWhToken()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 whitespace-nowrap">💾 Save</button>
                </div>
                <p class="text-[10px] text-gray-500 mt-1">This token must match the "Auth Token (Bearer)" field in <a href="https://portal.packzy.com/user/webhook/add" target="_blank" class="text-blue-600 underline">Steadfast Webhook Settings</a>. Steadfast sends <code class="bg-gray-100 px-1 rounded">Authorization: Bearer YOUR_TOKEN</code> with each webhook request.</p>
            </div>

            <!-- Test Webhook -->
            <div class="flex items-center gap-3">
                <button onclick="testSfWebhook()" class="bg-blue-100 text-blue-700 px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-200 border border-blue-200">🔌 Test Webhook Endpoint</button>
                <span id="sfWhTest" class="text-sm"></span>
            </div>

            <!-- Setup Steps -->
            <div class="bg-gray-50 rounded-xl border p-4">
                <h4 class="text-xs font-bold text-gray-700 mb-3 uppercase tracking-wider">Setup Steps</h4>
                <div class="space-y-2 text-sm text-gray-600">
                    <div class="flex items-start gap-2"><span class="bg-blue-100 text-blue-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">1</span><span>Click <b>🔑 Generate</b> to create a secure token, then click <b>💾 Save</b></span></div>
                    <div class="flex items-start gap-2"><span class="bg-blue-100 text-blue-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">2</span><span>Go to <a href="https://portal.packzy.com/user/webhook/add" target="_blank" class="text-blue-600 underline font-medium">portal.packzy.com → Webhook Settings → Add Webhook</a></span></div>
                    <div class="flex items-start gap-2"><span class="bg-blue-100 text-blue-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">3</span><span>Paste the <b>Callback URL</b> from above</span></div>
                    <div class="flex items-start gap-2"><span class="bg-blue-100 text-blue-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">4</span><span>Paste the same <b>Auth Token (Bearer)</b> — it must match exactly</span></div>
                    <div class="flex items-start gap-2"><span class="bg-blue-100 text-blue-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">5</span><span>Select events: <b>Delivery Status Update</b> + <b>Tracking Update</b></span></div>
                    <div class="flex items-start gap-2"><span class="bg-blue-100 text-blue-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">6</span><span>Click <b>🔌 Test Webhook Endpoint</b> above to verify connection</span></div>
                    <div class="flex items-start gap-2"><span class="bg-green-100 text-green-700 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">✓</span><span>Once connected, order statuses auto-update when Steadfast delivers/cancels/holds parcels.</span></div>
                </div>
            </div>

            <!-- Recent hits -->
            <p class="text-xs"><span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-medium"><?= $sfLogCount ?> recent webhook hits</span></p>
        </div>
    </div>

    <!-- ═══ REDX & CARRYBEE WEBHOOKS ═══ -->
    <div class="grid md:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center"><span class="text-sm font-bold text-red-500">R</span></div>
                <h3 class="font-bold text-gray-800">RedX Webhook</h3>
            </div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Callback URL</label>
            <div class="flex gap-2 mb-2">
                <code class="flex-1 text-xs font-mono text-gray-700 bg-red-50 px-3 py-2 rounded-lg border break-all"><?= e($rxWhUrlBase) ?></code>
                <button onclick="copyUrl('<?= e($rxWhUrlBase) ?>', this)" class="text-xs bg-white px-2 py-1 rounded border hover:bg-gray-50">📋</button>
            </div>
            <p class="text-[10px] text-gray-500 mb-2">Set in <a href="https://redx.com.bd/developer-api/" target="_blank" class="text-red-600 underline">RedX → Developer API → Webhook</a></p>
            <p class="text-xs text-gray-400">Auth: Token in URL query param</p>
            <p class="text-xs mt-2"><span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-medium"><?= $rxLogCount ?> recent hits</span></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center"><span class="text-sm font-bold text-green-600">C</span></div>
                <h3 class="font-bold text-gray-800">CarryBee Webhook</h3>
            </div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Callback URL</label>
            <div class="flex gap-2 mb-2">
                <code class="flex-1 text-xs font-mono text-gray-700 bg-green-50 px-3 py-2 rounded-lg border break-all"><?= e($cbWhUrl) ?></code>
                <button onclick="copyUrl('<?= e($cbWhUrl) ?>', this)" class="text-xs bg-white px-2 py-1 rounded border hover:bg-gray-50">📋</button>
            </div>
            <p class="text-[10px] text-gray-500 mb-2">Set in CarryBee Dashboard → Settings → Webhook URL</p>
            <p class="text-xs mt-2"><span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium"><?= $cbLogCount ?> recent hits</span></p>
        </div>
    </div>

    <!-- ═══ EVENT MAPPING TABLE ═══ -->
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-bold text-gray-800 text-lg mb-2">📊 Pathao Event → Order Status Mapping</h3>
        <p class="text-sm text-gray-500 mb-4">All 20 Pathao events are captured. Events that change your order status are shown below:</p>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-600">Pathao Event</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-600">→ Your Status</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-600">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y text-xs">
                <tr class="bg-green-50"><td class="px-3 py-2 font-medium">Delivered</td><td class="px-3 py-2"><span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full">delivered</span></td><td class="px-3 py-2 text-gray-500">Auto-updates + awards credits</td></tr>
                <tr class="bg-green-50"><td class="px-3 py-2 font-medium">Payment Invoice</td><td class="px-3 py-2"><span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full">delivered</span></td><td class="px-3 py-2 text-gray-500">Payment confirmed = delivered</td></tr>
                <tr class="bg-cyan-50"><td class="px-3 py-2 font-medium">Partial Delivery</td><td class="px-3 py-2"><span class="bg-cyan-100 text-cyan-700 px-2 py-0.5 rounded-full">partial_delivered</span></td><td class="px-3 py-2 text-gray-500">Staff decides next step</td></tr>
                <tr class="bg-amber-50"><td class="px-3 py-2 font-medium">Return / Paid Return / Exchange</td><td class="px-3 py-2"><span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">pending_return</span></td><td class="px-3 py-2 text-gray-500">Staff confirms manually</td></tr>
                <tr class="bg-red-50"><td class="px-3 py-2 font-medium">Delivery Failed / Pickup Failed</td><td class="px-3 py-2"><span class="bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full">on_hold</span></td><td class="px-3 py-2 text-gray-500">Needs attention</td></tr>
                <tr class="bg-red-50"><td class="px-3 py-2 font-medium">Pickup Cancelled</td><td class="px-3 py-2"><span class="bg-pink-100 text-pink-700 px-2 py-0.5 rounded-full">pending_cancel</span></td><td class="px-3 py-2 text-gray-500">Staff confirms cancel</td></tr>
                <tr class="bg-red-50"><td class="px-3 py-2 font-medium">On Hold</td><td class="px-3 py-2"><span class="bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full">on_hold</span></td><td class="px-3 py-2 text-gray-500">Courier holding parcel</td></tr>
                <tr class="bg-gray-50"><td class="px-3 py-2 font-medium text-gray-500">Order Created / Updated, Pickup Requested, Assigned For Pickup, Pickup, At Sorting Hub, In Transit, Received at Hub, Assigned for Delivery</td><td class="px-3 py-2"><span class="bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">— tracked only</span></td><td class="px-3 py-2 text-gray-400">Logged in history, no status change</td></tr>
            </tbody>
        </table>
        </div>
    </div>

    <!-- ═══ AUTO-SYNC (POLLING BACKUP) ═══ -->
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-bold text-gray-800 text-lg mb-2">🔄 Auto-Sync (Polling Backup)</h3>
        <p class="text-sm text-gray-500 mb-4">As a backup to webhooks, poll courier APIs for status updates. Useful if a webhook was missed or delayed.</p>
        
        <div class="bg-gray-50 rounded-xl border border-dashed p-4 mb-4">
            <label class="block text-xs font-medium text-gray-600 mb-1">Cron Job URL (every 30 min)</label>
            <?php $cronKey = getSetting('courier_sync_key',''); if(empty($cronKey)){$cronKey=bin2hex(random_bytes(16)); try{$db->query("INSERT INTO site_settings (setting_key,setting_value,setting_type,setting_group,label) VALUES ('courier_sync_key',?,'text','courier','Courier Sync Key') ON DUPLICATE KEY UPDATE setting_value=?",[$cronKey,$cronKey]);}catch(\Throwable $e){}} ?>
            <code class="block text-xs font-mono text-gray-700 bg-white px-3 py-2 rounded-lg border break-all mb-2"><?= e(SITE_URL) ?>/api/courier-sync.php?key=<?= e($cronKey) ?></code>
            <p class="text-[10px] text-gray-500"><code class="bg-white px-1 rounded">*/30 * * * * curl -s "<?= e(SITE_URL) ?>/api/courier-sync.php?key=<?= e($cronKey) ?>" > /dev/null</code></p>
        </div>
        
        <button onclick="runSync()" class="bg-indigo-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-indigo-700">🔄 Run Sync Now</button>
        <div id="syncResult" class="hidden mt-3 p-3 rounded-lg text-sm"></div>
    </div>

    <!-- ═══ WEBHOOK LOG ═══ -->
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-gray-800 text-lg">📜 Recent Webhook Activity</h3>
            <span class="text-xs text-gray-400">Last 20 hits</span>
        </div>
        <?php if (empty($whLogs)): ?>
            <p class="text-gray-400 text-sm text-center py-6">No webhook activity yet. Configure webhooks above and status updates will appear here.</p>
        <?php else: ?>
        <div class="space-y-1.5 max-h-96 overflow-y-auto">
            <?php foreach ($whLogs as $wl):
                $isPathao = $wl['courier'] === 'pathao';
                $isSf = $wl['courier'] === 'steadfast';
                $pdata = json_decode($wl['payload'] ?? '{}', true) ?: [];
                $evName = $pdata['event'] ?? $pdata['status'] ?? $pdata['notification_type'] ?? '—';
                $cid = $pdata['consignment_id'] ?? $pdata['invoice'] ?? '';
                $bgC = $isPathao ? 'bg-red-50 border-red-100' : ($isSf ? 'bg-blue-50 border-blue-100' : 'bg-green-50 border-green-100');
                $lblC = $isPathao ? 'bg-red-100 text-red-700' : ($isSf ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700');
                $hasResult = !empty($wl['result']) && $wl['result'] !== 'null';
                $isError = $hasResult && strpos($wl['result'], 'ERROR') !== false;
            ?>
            <div class="<?= $bgC ?> rounded-lg border px-3 py-2 flex items-center gap-3 text-xs">
                <span class="<?= $lblC ?> px-2 py-0.5 rounded-full font-bold uppercase text-[10px] flex-shrink-0"><?= e(ucfirst($wl['courier'])) ?></span>
                <span class="text-gray-400 flex-shrink-0 w-28"><?= date('d M H:i:s', strtotime($wl['created_at'] ?? 'now')) ?></span>
                <span class="font-medium text-gray-700 flex-shrink-0"><?= e($evName) ?></span>
                <?php if ($cid): ?><span class="text-gray-400 truncate">CID: <?= e(substr($cid, 0, 20)) ?></span><?php endif; ?>
                <?php if ($hasResult): ?><span class="ml-auto font-medium truncate max-w-[200px] <?= $isError ? 'text-red-600' : 'text-green-600' ?>"><?= e(substr($wl['result'], 0, 60)) ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyUrl(url,btn){navigator.clipboard.writeText(url);var o=btn.textContent;btn.textContent='✅ Copied!';setTimeout(()=>btn.textContent=o,2000)}

function generateSecret(){
    var s='';for(var i=0;i<32;i++)s+='0123456789abcdef'[Math.floor(Math.random()*16)];
    document.getElementById('wh_pathao_secret').value=s;
    document.getElementById('wh_pathao_secret').type='text';
}

function saveWhSecret(){
    var secret=document.getElementById('wh_pathao_secret').value;
    fetch('<?=SITE_URL?>/api/pathao-api.php?action=save_config',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({webhook_secret:secret})
    }).then(r=>r.json()).then(d=>{
        alert('✅ Webhook secret saved! Make sure the same secret is set in Pathao dashboard.');
        location.reload();
    }).catch(e=>alert('Error: '+e.message));
}

function testPathaoWebhook(){
    var el=document.getElementById('pathaoWhTest');
    el.textContent='⏳ Testing...';el.className='text-sm text-blue-600';
    fetch('<?= e($pathaoWhUrl) ?>',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({event:'webhook_integration'})
    }).then(r=>{
        var ok=r.status===202;
        var secretHeader=r.headers.get('X-Pathao-Merchant-Webhook-Integration-Secret');
        return r.json().then(j=>({ok,secretHeader,status:r.status,body:j}));
    }).then(d=>{
        var msgs=[];
        msgs.push(d.ok?'✅ HTTP 202':'❌ HTTP '+d.status+' (need 202)');
        msgs.push(d.secretHeader?'✅ Secret header returned':'⚠️ No secret header (set secret first)');
        msgs.push(d.body?.status==='success'?'✅ Integration test passed':'❌ '+JSON.stringify(d.body));
        el.innerHTML=msgs.join('<br>');
        el.className='text-sm '+(d.ok?'text-green-600':'text-red-600');
    }).catch(e=>{el.textContent='❌ '+e.message;el.className='text-sm text-red-600';});
}

function generateSfWhToken(){
    var s='kb-sf-';for(var i=0;i<24;i++)s+='0123456789abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random()*36)];
    var inp=document.getElementById('wh_sf_token');
    inp.value=s;inp.type='text';
}

function saveSfWhToken(){
    var token=document.getElementById('wh_sf_token').value.trim();
    fetch('<?=SITE_URL?>/api/steadfast-actions.php',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'save_settings',webhook_token:token})
    }).then(r=>r.json()).then(d=>{
        if(d.success!==false){
            alert('✅ Webhook token saved!\n\nNow paste this same token into Steadfast → Webhook Settings → Auth Token (Bearer):\n\n'+token);
            location.reload();
        }else{alert('❌ '+(d.message||'Failed'));}
    }).catch(e=>alert('Error: '+e.message));
}

function testSfWebhook(){
    var el=document.getElementById('sfWhTest');
    el.textContent='⏳ Testing...';el.className='text-sm text-blue-600';
    var token=document.getElementById('wh_sf_token').value.trim();
    var headers={'Content-Type':'application/json'};
    if(token)headers['Authorization']='Bearer '+token;
    fetch('<?= e($sfWhUrl) ?>',{
        method:'POST',headers:headers,
        body:JSON.stringify({status:'pending',invoice:'WEBHOOK-TEST-'+Date.now(),consignment_id:'TEST-000',notification_type:'test'})
    }).then(r=>r.json().then(j=>({status:r.status,body:j}))).then(d=>{
        var msgs=[];
        msgs.push(d.status===200?'✅ HTTP 200 — Endpoint reachable':'❌ HTTP '+d.status);
        if(d.status===401){
            msgs.push('❌ Unauthorized — Token mismatch! Make sure the saved token matches what Steadfast sends.');
        }else{
            msgs.push('✅ Auth passed'+(token?' (Bearer token verified)':' (no token required)'));
            msgs.push(d.body?.status==='success'?'✅ Webhook processed: '+d.body.message:'ℹ️ Response: '+JSON.stringify(d.body));
        }
        el.innerHTML=msgs.join('<br>');
        el.className='text-sm '+(d.status===200?'text-green-600':'text-red-600');
    }).catch(e=>{el.textContent='❌ Network error: '+e.message;el.className='text-sm text-red-600';});
}

function runSync(){
    var el=document.getElementById('syncResult');el.classList.remove('hidden');el.className='mt-3 p-3 rounded-lg text-sm bg-blue-50 text-blue-700';el.textContent='🔄 Syncing...';
    fetch('<?=SITE_URL?>/api/courier-sync.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({limit:50})})
    .then(r=>r.json()).then(d=>{
        el.className='mt-3 p-3 rounded-lg text-sm bg-green-50 text-green-700';
        el.innerHTML='✅ Synced '+d.total+' orders: <strong>'+d.updated+'</strong> updated, '+d.skipped+' skipped, '+d.errors+' errors'+(d.details?.length?'<br><small>'+d.details.join('<br>')+'</small>':'');
    }).catch(e=>{el.className='mt-3 p-3 rounded-lg text-sm bg-red-50 text-red-700';el.textContent='Error: '+e.message;});
}

function togglePass(btn){var i=btn.previousElementSibling||btn.closest('.relative').querySelector('input');if(!i)i=btn.parentElement.querySelector('input');if(i){i.type=i.type==='password'?'text':'password';}}
</script>

<?php elseif ($tab === 'customer_check'): ?>
<!-- ========================================= -->
<!-- CUSTOMER VERIFICATION -->
<!-- ========================================= -->
<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h3 class="font-bold text-gray-800 text-lg mb-1">🔍 Customer Delivery Verification</h3>
            <p class="text-sm text-gray-500 mb-5">Check customer's courier delivery history using phone number. Combines your local order data + Pathao & Steadfast API data to build a fraud detection profile.</p>
            <div class="flex gap-3">
                <div class="relative flex-1">
                    <span class="absolute left-3 top-3.5 text-gray-400">📱</span>
                    <input type="tel" id="checkPhone" placeholder="01XXXXXXXXX" class="w-full pl-10 pr-4 py-3 border-2 rounded-xl text-lg font-mono focus:border-blue-500 focus:ring-2 focus:ring-blue-200" onkeydown="if(event.key==='Enter')checkCustomer()">
                </div>
                <button onclick="checkCustomer()" id="checkBtn" class="bg-blue-600 text-white px-8 py-3 rounded-xl font-semibold hover:bg-blue-700 shadow-sm whitespace-nowrap">🔍 Verify</button>
            </div>
            <div class="mt-2 flex items-center gap-3 text-xs">
                <a href="api-diagnostic.php" target="_blank" class="text-blue-500 hover:underline">🔧 Run API Diagnostic</a>
                <span class="text-gray-300">|</span>
                <span class="text-gray-400">Data sources: Local DB + Pathao (merchant.pathao.com) + Steadfast (steadfast.com.bd) + RedX (redx.com.bd)</span>
            </div>
        </div>
        <div id="customerResult" class="hidden"></div>
    </div>
    <div class="space-y-5">
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-4">📊 Top Order Areas <span class="text-xs text-gray-400 font-normal">(90 days)</span></h4>
            <div id="areaStats" class="space-y-2 text-sm"><p class="text-gray-400">Loading...</p></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-3">🔑 Fraud Check API Credentials</h4>
            <p class="text-[10px] text-gray-400 mb-3">Enter your merchant portal login credentials to enable cross-merchant fraud checking.</p>
            <?php
            $fc_sf_email = getSetting('steadfast_merchant_email','') ?: getSetting('steadfast_email','');
            $fc_sf_pass  = getSetting('steadfast_merchant_password','') ?: getSetting('steadfast_password','');
            $fc_rx_phone = getSetting('redx_phone','');
            $fc_rx_pass  = getSetting('redx_password','');
            ?>
            <div class="space-y-3">
                <div class="bg-red-50 rounded-lg p-3 border border-red-200">
                    <p class="text-xs font-bold text-red-700 mb-1.5">Pathao</p>
                    <p class="text-[10px] text-gray-500">Uses same email/password from Pathao API tab ✅</p>
                </div>
                <div class="bg-blue-50 rounded-lg p-3 border border-blue-200">
                    <p class="text-xs font-bold text-blue-700 mb-1.5">Steadfast</p>
                    <input type="email" id="fc_sf_email" value="<?=e($fc_sf_email)?>" placeholder="Steadfast login email" class="w-full px-2 py-1.5 border rounded text-xs mb-1">
                    <div class="relative">
                        <input type="password" id="fc_sf_pass" value="<?=e($fc_sf_pass)?>" placeholder="Steadfast login password" class="w-full px-2 py-1.5 border rounded text-xs pr-8">
                        <button type="button" onclick="sfTogglePw(this)" class="sf-pw-toggle absolute right-1.5 top-1 text-gray-400 hover:text-gray-600" title="Show"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1">steadfast.com.bd login (for fraud check)</p>
                </div>
                <div class="bg-red-50 rounded-lg p-3 border border-red-200">
                    <p class="text-xs font-bold text-red-700 mb-1.5">RedX</p>
                    <input type="tel" id="fc_rx_phone" value="<?=e($fc_rx_phone)?>" placeholder="RedX login phone (01...)" class="w-full px-2 py-1.5 border rounded text-xs mb-1">
                    <div class="relative">
                        <input type="password" id="fc_rx_pass" value="<?=e($fc_rx_pass)?>" placeholder="RedX login password" class="w-full px-2 py-1.5 border rounded text-xs pr-8">
                        <button type="button" onclick="sfTogglePw(this)" class="sf-pw-toggle absolute right-1.5 top-1 text-gray-400 hover:text-gray-600" title="Show"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1">redx.com.bd login (for fraud check)</p>
                </div>
                <button onclick="saveFraudConfig()" id="fcSaveBtn" class="w-full bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-semibold hover:bg-indigo-700">💾 Save Fraud Check Credentials</button>
                <div id="fcResult" class="hidden text-xs"></div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-3">ℹ️ How It Works</h4>
            <div class="text-xs text-gray-500 space-y-2">
                <p>1. Enter customer phone number</p>
                <p>2. <b>Pathao Portal</b> → merchant.pathao.com (rating & delivery count)</p>
                <p>3. <b>Steadfast Portal</b> → steadfast.com.bd/user/frauds/check (delivered & cancelled)</p>
                <p>4. <b>RedX API</b> → redx.com.bd (delivered & total parcels)</p>
                <p>5. <b>Local DB</b> → your own order history</p>
                <p>6. Combined risk score: <span class="text-green-600 font-medium">Low</span>, <span class="text-yellow-600 font-medium">Medium</span>, <span class="text-red-600 font-medium">High</span>, or <span class="text-blue-600 font-medium">New</span></p>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-3">🛡️ Fraud Signals</h4>
            <div class="text-xs text-gray-500 space-y-2">
                <p>• <b>Pathao:</b> Cross-merchant success/total delivery count</p>
                <p>• <b>Steadfast:</b> Cross-merchant delivered vs cancelled</p>
                <p>• <b>RedX:</b> Cross-merchant parcel success rate</p>
                <p>• <b>Cancel rate:</b> High cancellations = higher risk</p>
                <p>• <b>Web cancels:</b> 3+ web cancels = auto high-risk</p>
                <p>• <b>Blocked list:</b> Manual blocklist check</p>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'area_map'): ?>
<!-- ========================================= -->
<!-- AREA ANALYTICS -->
<!-- ========================================= -->
<div class="space-y-6">
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <?php
        $backfillEnabled = getSetting('area_backfill_enabled', '0') === '1';
        $backfillLastRun = getSetting('area_backfill_last_run', '');
        $pendingBackfill = 0;
        try { $pendingBackfill = intval($db->fetch("SELECT COUNT(*) as cnt FROM orders WHERE pathao_city_id IS NOT NULL AND pathao_city_id > 0 AND (delivery_city_name IS NULL OR delivery_city_name = '')")['cnt'] ?? 0); } catch (\Throwable $e) {}
        ?>
        <!-- Auto-Backfill Status Card -->
        <div class="bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-xl p-4 mb-5" id="backfillCard">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center text-lg">🔄</div>
                    <div>
                        <h4 class="text-sm font-bold text-gray-800">Auto Backfill Area Names</h4>
                        <p class="text-xs text-gray-500">Resolves Pathao City/Zone/Area names for orders every 24 hours via cron</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Status badges -->
                    <div class="flex items-center gap-2 text-xs">
                        <?php if ($pendingBackfill > 0): ?>
                        <span class="px-2.5 py-1 rounded-full bg-yellow-100 text-yellow-700 border border-yellow-200 font-medium" id="pendingBadge"><?= $pendingBackfill ?> pending</span>
                        <?php else: ?>
                        <span class="px-2.5 py-1 rounded-full bg-green-100 text-green-700 border border-green-200 font-medium" id="pendingBadge">✓ All resolved</span>
                        <?php endif; ?>
                        <?php if ($backfillLastRun): ?>
                        <span class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 border border-gray-200" title="Last run: <?= e($backfillLastRun) ?>">Last: <?= date('M d, h:i A', strtotime($backfillLastRun)) ?></span>
                        <?php endif; ?>
                    </div>
                    <!-- Manual backfill button -->
                    <button type="button" onclick="backfillAreaNames()" id="backfillBtn" class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-700 transition shadow-sm">🚀 Run Now</button>
                    <!-- Toggle -->
                    <label class="flex items-center gap-2 cursor-pointer select-none" title="Enable auto-backfill every 24 hours via cron sync">
                        <div class="relative">
                            <input type="checkbox" id="backfillToggle" onchange="toggleAutoBackfill(this.checked)" <?= $backfillEnabled ? 'checked' : '' ?> class="sr-only peer">
                            <div class="w-10 h-5 bg-gray-300 rounded-full peer-checked:bg-indigo-600 transition-colors"></div>
                            <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-5 shadow"></div>
                        </div>
                        <span class="text-xs font-medium text-gray-700" id="toggleLabel"><?= $backfillEnabled ? 'Auto: ON' : 'Auto: OFF' ?></span>
                    </label>
                </div>
            </div>
            <div id="backfillResult" class="hidden mt-3 p-3 rounded-lg text-sm"></div>
        </div>

        <div class="flex items-center justify-between mb-5">
            <div>
                <h3 class="font-bold text-gray-800 text-lg">📊 Order Area Analytics</h3>
                <p class="text-sm text-gray-500">Delivery area data from Pathao City → Zone → Area selection</p>
            </div>
            <select id="areaDays" onchange="loadAreaChart()" class="px-3 py-2 border rounded-lg text-sm">
                <option value="30">Last 30 days</option>
                <option value="90" selected>Last 90 days</option>
                <option value="180">Last 180 days</option>
                <option value="365">Last year</option>
            </select>
        </div>
        <div id="areaChartContainer">
            <div class="text-center py-8 text-gray-400">Loading area data...</div>
        </div>
    </div>

    <!-- Bangladesh Map Visualization -->
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="font-bold text-gray-800 text-lg">🗺️ Bangladesh Delivery Performance Map</h3>
                <p class="text-sm text-gray-500">City-level success &amp; return rates — <span class="text-green-600 font-medium">green = better delivery</span>, <span class="text-red-500 font-medium">red = higher returns</span></p>
            </div>
            <div class="flex items-center gap-2">
                <select id="mapDays" onchange="loadCityMapData()" class="px-2.5 py-1.5 border rounded-lg text-xs text-gray-600">
                    <option value="30">30 days</option>
                    <option value="90" selected>90 days</option>
                    <option value="180">180 days</option>
                    <option value="365">1 year</option>
                </select>
            </div>
        </div>

        <div class="grid lg:grid-cols-4 gap-5">
            <!-- Map SVG -->
            <div class="lg:col-span-3 relative">
                <div id="bdMapContainer" class="bg-gradient-to-br from-slate-50 to-gray-50 rounded-xl border border-gray-200 overflow-hidden relative select-none" style="height:380px;">
                    <svg id="bdMapSvg" viewBox="0 0 600 780" preserveAspectRatio="xMidYMid meet" class="w-full h-full" style="cursor:grab;transform-origin:0 0;"></svg>
                </div>
                <!-- Zoom Controls -->
                <div class="absolute top-3 right-3 flex flex-col gap-1 z-30">
                    <button onclick="mapZoom(1.3)" class="w-8 h-8 bg-white border border-gray-300 rounded-lg shadow-sm flex items-center justify-center text-gray-600 hover:bg-gray-50 active:bg-gray-100 text-lg font-bold leading-none" title="Zoom in">+</button>
                    <button onclick="mapZoom(0.77)" class="w-8 h-8 bg-white border border-gray-300 rounded-lg shadow-sm flex items-center justify-center text-gray-600 hover:bg-gray-50 active:bg-gray-100 text-lg font-bold leading-none" title="Zoom out">−</button>
                    <button onclick="mapReset()" class="w-8 h-8 bg-white border border-gray-300 rounded-lg shadow-sm flex items-center justify-center text-gray-500 hover:bg-gray-50 active:bg-gray-100 text-xs" title="Reset view">⟲</button>
                </div>
                <!-- Zoom level indicator -->
                <div id="mapZoomBadge" class="absolute bottom-3 left-3 px-2 py-1 bg-white/80 backdrop-blur border border-gray-200 rounded-md text-[10px] text-gray-500 z-30 pointer-events-none">100%</div>
                <!-- Tooltip -->
                <div id="mapTooltip" class="hidden absolute pointer-events-none z-50 bg-gray-900/95 backdrop-blur text-white text-xs rounded-xl shadow-2xl px-4 py-3 max-w-64" style="transition:opacity 0.15s;">
                    <div id="ttName" class="font-bold text-sm mb-0.5"></div>
                    <div id="ttDiv" class="text-gray-400 text-[10px] mb-2"></div>
                    <div id="ttStats" class="space-y-1"></div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Color Legend -->
                <div class="bg-gray-50 rounded-xl p-4 border">
                    <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-3">Delivery Performance</p>
                    <div class="h-3.5 rounded-full overflow-hidden flex border border-gray-200" id="legendGradient">
                        <div class="flex-1" style="background:#dc2626"></div>
                        <div class="flex-1" style="background:#f97316"></div>
                        <div class="flex-1" style="background:#eab308"></div>
                        <div class="flex-1" style="background:#84cc16"></div>
                        <div class="flex-1" style="background:#22c55e"></div>
                        <div class="flex-1" style="background:#16a34a"></div>
                    </div>
                    <div class="flex justify-between mt-1.5">
                        <span class="text-[9px] text-red-500 font-medium">High Returns</span>
                        <span class="text-[9px] text-green-600 font-medium">High Success</span>
                    </div>
                    <div class="flex items-center gap-2 mt-3 pt-3 border-t border-gray-200">
                        <div class="w-4 h-3 rounded border border-gray-300" style="background:repeating-linear-gradient(45deg,#f1f5f9,#f1f5f9 2px,#e2e8f0 2px,#e2e8f0 4px)"></div>
                        <span class="text-[10px] text-gray-400">No order data</span>
                    </div>
                </div>

                <!-- City Rankings -->
                <div class="bg-gray-50 rounded-xl p-4 border">
                    <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-3">City Rankings</p>
                    <div id="cityRanking" class="space-y-2 text-xs">
                        <p class="text-gray-400">Loading...</p>
                    </div>
                </div>

                <!-- Hovered City Detail -->
                <div class="bg-gray-50 rounded-xl p-4 border">
                    <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">City Detail</p>
                    <div id="selectedCityInfo" class="text-xs text-gray-400">
                        <p>Hover over the map to see details</p>
                    </div>
                </div>

                <!-- Map Stats Summary -->
                <div class="bg-gray-50 rounded-xl p-4 border">
                    <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-3">Summary</p>
                    <div id="mapSummary" class="space-y-2 text-xs">
                        <p class="text-gray-400">Loading...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="font-semibold text-gray-800 mb-4">📈 Top Performing Areas</h4>
            <div id="topAreas" class="space-y-3 text-sm"><p class="text-gray-400">Loading...</p></div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <h4 class="font-semibold text-gray-800 mb-4">⚠️ Highest Failure Rate</h4>
            <div id="worstAreas" class="space-y-3 text-sm"><p class="text-gray-400">Loading...</p></div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'providers'): ?>
<!-- ========================================= -->
<!-- PROVIDERS -->
<!-- ========================================= -->
<div class="grid lg:grid-cols-3 gap-6">
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h4 class="font-semibold text-gray-800 mb-4"><?=$editProvider?'Edit':'Add'?> Provider</h4>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save_provider"><input type="hidden" name="provider_id" value="<?=$editProvider['id']??0?>">
            <div><label class="block text-xs font-medium text-gray-600 mb-1">Name *</label><input type="text" name="name" value="<?=e($editProvider['name']??'')?>" required class="w-full px-3 py-2 border rounded-lg text-sm"></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">API URL</label><input type="url" name="api_url" value="<?=e($editProvider['api_url']??'')?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">API Key</label><input type="text" name="api_key" value="<?=e($editProvider['api_key']??'')?>" class="w-full px-3 py-2 border rounded-lg text-sm font-mono"></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">API Secret</label><input type="password" name="api_secret" value="<?=e($editProvider['api_secret']??'')?>" class="w-full px-3 py-2 border rounded-lg text-sm font-mono"></div>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" <?=($editProvider['is_active']??1)?'checked':''?> class="rounded"><span class="text-sm">Active</span></label>
            <button class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700"><?=$editProvider?'Update':'Add Provider'?></button>
        </form>
    </div>
    <div class="lg:col-span-2">
        <div class="grid md:grid-cols-2 gap-4">
            <?php foreach($providers as $p): ?>
            <div class="bg-white rounded-xl shadow-sm border p-5">
                <div class="flex items-center justify-between mb-2"><h5 class="font-semibold"><?=e($p['name'])?></h5><span class="px-2 py-0.5 text-xs rounded-full <?=$p['is_active']?'bg-green-100 text-green-700':'bg-gray-100 text-gray-500'?>"><?=$p['is_active']?'Active':'Off'?></span></div>
                <p class="text-sm text-gray-600 mb-3">Shipments: <strong><?=$p['shipment_count']?></strong></p>
                <div class="flex gap-3"><a href="?tab=providers&edit=<?=$p['id']?>" class="text-blue-600 text-sm hover:underline">Edit</a>
                <form method="POST" class="inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete_provider"><input type="hidden" name="provider_id" value="<?=$p['id']?>"><button class="text-red-600 text-sm hover:underline">Delete</button></form></div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($providers)):?><div class="col-span-2 text-center py-8 text-gray-400">No providers</div><?php endif;?>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ========================================= -->
<!-- SHIPMENTS -->
<!-- ========================================= -->
<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-gray-50"><tr>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Order</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Customer</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Courier</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Tracking</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">COD</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
            <th class="px-4 py-3 text-left font-medium text-gray-600">Date</th>
        </tr></thead>
        <tbody class="divide-y">
            <?php foreach($shipments as $sh): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3"><a href="<?=adminUrl('pages/order-view.php?id='.$sh['order_id'])?>" class="text-blue-600 hover:underline">#<?=e($sh['order_number'])?></a></td>
                <td class="px-4 py-3"><?=e($sh['customer_name'])?><br><span class="text-xs text-gray-400"><?=e($sh['customer_phone'])?></span></td>
                <td class="px-4 py-3"><?=e($sh['courier_name'])?></td>
                <td class="px-4 py-3 font-mono text-xs"><?=e($sh['tracking_number'])?></td>
                <td class="px-4 py-3 font-medium">৳<?=number_format($sh['cod_amount'])?></td>
                <td class="px-4 py-3">
                    <form method="POST"><input type="hidden" name="action" value="update_shipment"><input type="hidden" name="shipment_id" value="<?=$sh['id']?>">
                    <select name="status" onchange="this.form.submit()" class="text-xs px-2 py-1 rounded border">
                        <?php foreach(['pending','picked_up','in_transit','out_for_delivery','delivered','returned','cancelled'] as $st):?>
                        <option value="<?=$st?>" <?=$sh['status']===$st?'selected':''?>><?=ucfirst(str_replace('_',' ',$st))?></option>
                        <?php endforeach;?>
                    </select></form>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500"><?=date('M d, h:i A',strtotime($sh['created_at']))?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($shipments)):?><tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">No shipments</td></tr><?php endif;?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<script>
const PAPI = '<?=SITE_URL?>/api/pathao-api.php';
const FAPI = '<?=SITE_URL?>/api/fraud-checker.php';

function togglePass(btn) { const i = btn.previousElementSibling; i.type = i.type==='password'?'text':'password'; }

// ========== PATHAO CONFIG ==========
async function savePathaoConfig() {
    const btn = document.getElementById('saveBtn');
    btn.disabled=true; btn.textContent='⏳ Connecting...';
    try {
        const res = await fetch(PAPI+'?action=save_config', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                client_id: el('p_client_id').value,
                client_secret: el('p_client_secret').value,
                username: el('p_username').value,
                password: el('p_password').value,
                environment: el('p_environment').value,
                store_id: el('p_store_id').value,
                webhook_secret: el('p_webhook_secret').value,
            })
        });
        const j=await res.json();
        showMsg(j.success, j.message||(j.success?'✅ Connected!':'❌ Failed'));
        if(j.success) setTimeout(()=>location.reload(), 1500);
    } catch(e) { showMsg(false, e.message); }
    btn.disabled=false; btn.textContent='💾 Save & Connect';
}

async function testConn() {
    showMsg(null,'⏳ Testing connection...');
    try { const r=await(await fetch(PAPI+'?action=test_connection')).json(); showMsg(r.success, r.message); }
    catch(e) { showMsg(false, e.message); }
}

function showMsg(ok, msg) {
    const e=el('connMsg'); e.classList.remove('hidden');
    e.className='mt-4 px-4 py-3 rounded-lg text-sm font-medium border '+(ok===null?'bg-blue-50 text-blue-700 border-blue-200':ok?'bg-green-50 text-green-700 border-green-200':'bg-red-50 text-red-700 border-red-200');
    e.textContent=msg;
}

async function fetchStores() {
    const sl=el('storesList'); sl.innerHTML='<p class="text-gray-400">Loading...</p>';
    try {
        const j=await(await fetch(PAPI+'?action=get_stores')).json();
        const stores=j.data?.data||j.data||[];
        if(!stores.length){sl.innerHTML='<p class="text-gray-400">No stores found</p>';return;}
        sl.innerHTML=stores.map(s=>`<div class="p-2.5 bg-gray-50 rounded-lg cursor-pointer hover:bg-blue-50 transition border" onclick="el('p_store_id').value='${s.store_id}'">
            <p class="font-medium text-gray-800 text-xs">${s.store_name}</p><p class="text-xs text-gray-400">ID: ${s.store_id} · ${s.store_address||''}</p></div>`).join('');
    } catch(e) { sl.innerHTML=`<p class="text-red-500 text-xs">${e.message}</p>`; }
}

async function loadCities() {
    try { const j=await(await fetch(PAPI+'?action=get_cities')).json(); const c=j.data?.data||j.data||[]; alert('✅ Loaded '+c.length+' cities from Pathao API'); }
    catch(e) { alert('Error: '+e.message); }
}

function el(id){return document.getElementById(id);}

async function saveFraudConfig() {
    const btn=el('fcSaveBtn'); btn.disabled=true; btn.textContent='⏳ Saving...';
    const res=el('fcResult');
    try {
        const j=await(await fetch(FAPI+'?action=save_credentials',{
            method:'POST',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                steadfast_merchant_email: el('fc_sf_email').value,
                steadfast_merchant_password: el('fc_sf_pass').value,
                redx_phone: el('fc_rx_phone').value,
                redx_password: el('fc_rx_pass').value,
            })
        })).json();
        res.classList.remove('hidden');
        res.className='text-xs mt-2 p-2 rounded '+(j.success?'bg-green-50 text-green-700':'bg-red-50 text-red-700');
        res.textContent=j.success?'✅ Credentials saved! Fraud check will now use these.':'❌ '+(j.message||'Failed');
    } catch(e) { res.classList.remove('hidden'); res.className='text-xs mt-2 p-2 rounded bg-red-50 text-red-700'; res.textContent='Error: '+e.message; }
    btn.disabled=false; btn.textContent='💾 Save Fraud Check Credentials';
}



// ========== CUSTOMER CHECK ==========
async function checkCustomer() {
    const phone=el('checkPhone').value.trim();
    if(!phone||phone.length<10){alert('Enter valid phone number');return;}
    const btn=el('checkBtn'); btn.disabled=true; btn.innerHTML='⏳ Checking...';
    const div=el('customerResult'); div.classList.remove('hidden');
    div.innerHTML='<div class="bg-white rounded-xl border p-8 text-center"><div class="animate-pulse text-gray-400">🔍 Checking Pathao + Steadfast + RedX + Local DB...</div></div>';
    try {
        const resp=await fetch(FAPI+'?phone='+encodeURIComponent(phone));
        const j=await resp.json();
        if(!j.success){
            div.innerHTML=`<div class="bg-red-50 border border-red-200 rounded-xl p-6"><p class="font-bold text-red-700 mb-2">❌ Error</p><p class="text-red-600">${j.error||'Unknown error'}</p></div>`;
            return;
        }
        const p=j.pathao||{}, s=j.steadfast||{}, r=j.redx||{}, l=j.local||{}, co=j.combined||{};
        const risk=co.risk||'new', rateVal=co.rate||0;
        const riskColors={low:'green',medium:'yellow',high:'red',new:'blue',blocked:'red'};
        const rc=riskColors[risk]||'gray';

        function srcBadge(name, data, color) {
            if (data.error && data.total === undefined) return `<span class="bg-${color}-50 text-${color}-400 border border-${color}-200 px-2.5 py-1 rounded-full text-[10px]">❌ ${name}</span>`;
            const t = data.total||0, s = data.success||0;
            const cr = data.customer_rating;
            const rLabels={excellent_customer:'⭐ Excellent',good_customer:'✅ Good',moderate_customer:'⚠️ Moderate',risky_customer:'🚫 Risky',new_customer:'🆕 New'};
            let extra = cr ? ` · ${rLabels[cr]||cr}` : '';
            return `<span class="bg-${color}-100 text-${color}-700 border border-${color}-200 px-2.5 py-1 rounded-full text-[10px] font-bold">✅ ${name}: ${s}/${t}${extra}</span>`;
        }
        function card(label, data, barColor) {
            if (!data || (data.total===0 && !data.success && !data.customer_rating)) {
                return `<div class="bg-white border border-gray-200 rounded-lg p-3 opacity-50">
                    <div class="text-sm font-semibold text-gray-800 mb-1">${label}</div>
                    <div class="text-xs text-gray-400 mb-1">No data</div>
                    <div class="h-1 bg-gray-100 rounded-full mt-2"></div></div>`;
            }
            const total=data.total||0, success=data.success||0, cancel=data.cancel||0;
            const rate=total>0?Math.round(success/total*100):0;
            const rateCls=rate>=70?'text-green-600':rate>=40?'text-yellow-600':'text-red-600';
            const cr = data.customer_rating;
            const rLabels={excellent_customer:'⭐ Excellent',good_customer:'✅ Good',moderate_customer:'⚠️ Moderate',risky_customer:'🚫 Risky'};
            let ratingHtml = cr ? `<div class="text-[10px] font-bold ${rate>=70||cr==='excellent_customer'||cr==='good_customer'?'text-green-600':cr==='moderate_customer'?'text-yellow-600':'text-red-600'}">${rLabels[cr]||cr}</div>` : '';
            let xmHtml = '';
            if (data.cross_merchant_total > 0) xmHtml = `<div class="text-[9px] text-gray-400 mt-1">All merchants: ${data.cross_merchant_total} orders</div>`;
            else if (data.api_note) xmHtml = `<div class="text-[9px] text-gray-400 mt-1">⚠ Cross-merchant N/A</div>`;

            return `<div class="bg-white border border-gray-200 rounded-lg p-3">
                <div class="text-sm font-semibold text-gray-800 mb-1">${label} ${total>0?'<span class="text-green-500 text-[9px]">✓</span>':''}</div>
                ${ratingHtml}
                ${total > 0 ? `
                    <div class="text-xs font-bold ${rateCls} mb-1">Success: ${rate}%</div>
                    <div class="text-[11px] text-gray-500">Total: ${total} · ✅ ${success} · ❌ ${cancel}</div>
                ` : `<div class="text-xs text-gray-400">No orders via this courier</div>`}
                ${xmHtml}
                <div class="h-1 bg-gray-100 rounded-full mt-2"><div class="h-full rounded-full" style="width:${Math.min(100,rate)}%;background:${barColor}"></div></div></div>`;
        }

        div.innerHTML=`
        <div class="flex flex-wrap gap-1.5 mb-3">
            ${srcBadge('Pathao', p, 'red')} ${srcBadge('Steadfast', s, 'blue')} ${srcBadge('RedX', r, 'orange')}
            <span class="bg-gray-100 text-gray-700 border border-gray-200 px-2.5 py-1 rounded-full text-[10px] font-bold">📋 Local: ${l.total||0} orders</span>
        </div>
        <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r ${risk==='high'||risk==='blocked'?'from-red-50 to-red-100/50':risk==='medium'?'from-yellow-50 to-yellow-100/50':risk==='new'?'from-blue-50 to-blue-100/50':'from-green-50 to-green-100/50'} border-b flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h4 class="font-bold text-gray-800 text-lg">📋 ${j.phone}</h4>
                    <p class="text-xs text-gray-500 mt-0.5">First: ${l.first_order?new Date(l.first_order).toLocaleDateString():'—'} · Last: ${l.last_order?new Date(l.last_order).toLocaleDateString():'—'} · Local: ${l.total||0}</p>
                </div>
                <div class="flex gap-2 items-center">
                    ${l.is_blocked?'<span class="bg-red-600 text-white px-3 py-1.5 rounded-full text-xs font-bold">🚫 BLOCKED</span>':''}
                    ${co.pathao_rating?`<span class="bg-purple-100 text-purple-800 px-3 py-1.5 rounded-full text-xs font-bold border border-purple-200">Pathao: ${{excellent_customer:'⭐ Excellent',good_customer:'✅ Good',moderate_customer:'⚠️ Moderate',risky_customer:'🚫 Risky',new_customer:'🆕 New'}[co.pathao_rating]||co.pathao_rating}</span>`:''}
                    <span class="bg-${rc}-100 text-${rc}-800 px-4 py-2 rounded-full text-sm font-bold border border-${rc}-200">${co.risk_label||'Unknown'}</span>
                </div>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                    ${card('Overall', {total:co.total,success:co.success,cancel:co.cancel}, '#ef4444')} ${card('Pathao', p, '#3b82f6')} ${card('Steadfast', s, '#8b5cf6')} ${card('RedX', r, '#ef4444')}
                    <div class="bg-white border border-green-200 rounded-lg p-3">
                        <div class="text-sm font-semibold text-gray-800 mb-1">Our Record</div>
                        <div class="text-xs font-bold ${(l.total||0)<=1?'text-blue-600':'text-green-600'} mb-1">${(l.total||0)<=1?'New':'Returning'}</div>
                        <div class="text-[11px] text-gray-500">Spent: ৳${Number(l.total_spent||0).toLocaleString()}</div>
                        <div class="text-[11px] text-gray-500">✅${l.delivered||0} ❌${l.cancelled||0} 🔄${l.returned||0}</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                    <div class="text-center p-4 bg-gray-50 rounded-xl border"><p class="text-3xl font-bold text-gray-800">${co.total||0}</p><p class="text-xs text-gray-500 mt-1">API Total</p></div>
                    <div class="text-center p-4 bg-green-50 rounded-xl border border-green-100"><p class="text-3xl font-bold text-green-600">${co.success||0}</p><p class="text-xs text-gray-500 mt-1">Delivered ✅</p></div>
                    <div class="text-center p-4 bg-red-50 rounded-xl border border-red-100"><p class="text-3xl font-bold text-red-600">${co.cancel||0}</p><p class="text-xs text-gray-500 mt-1">Cancelled ❌</p></div>
                    <div class="text-center p-4 bg-orange-50 rounded-xl border border-orange-100"><p class="text-3xl font-bold text-orange-600">${l.returned||0}</p><p class="text-xs text-gray-500 mt-1">Returned 🔄</p></div>
                    <div class="text-center p-4 bg-blue-50 rounded-xl border border-blue-100"><p class="text-3xl font-bold text-blue-600">৳${Number(l.total_spent||0).toLocaleString()}</p><p class="text-xs text-gray-500 mt-1">Total Spent</p></div>
                </div>
                <div class="mb-5">
                    <div class="flex justify-between text-sm mb-2"><span class="text-gray-700 font-semibold">Cross-Merchant Success Rate</span><span class="text-lg font-bold ${rateVal>=70?'text-green-600':rateVal>=40?'text-yellow-600':'text-red-600'}">${rateVal}%</span></div>
                    <div class="w-full h-4 bg-gray-200 rounded-full overflow-hidden"><div class="h-full rounded-full transition-all duration-500 ${rateVal>=70?'bg-green-500':rateVal>=40?'bg-yellow-500':'bg-red-500'}" style="width:${rateVal}%"></div></div>
                </div>
                ${l.is_blocked?`<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm mb-4 font-medium">🚫 <strong>Blocked:</strong> ${l.block_reason||'No reason'}</div>`:''}
                ${(p.api_note||s.api_note||r.api_note)?`<details class="bg-gray-50 border rounded-xl px-4 py-3 mb-4 text-xs"><summary class="cursor-pointer font-semibold text-gray-700">📡 API Details</summary><div class="mt-2 space-y-1 text-gray-500"><p>Pathao: ${p.customer_rating?'✅ Rating: '+p.customer_rating:'❌ '+(p.error||'N/A')}${p.cross_merchant_total?' · Cross-merchant: '+p.cross_merchant_total+' orders':''}</p><p>Steadfast: ${s.total>0?'✅ Own DB: '+s.success+'/'+s.total:'📋 No Steadfast orders'}${s.api_note?' · ⚠ '+s.api_note:s.cross_merchant_total?' · Cross-merchant: '+s.cross_merchant_total:''}</p><p>RedX: ${r.total>0?'✅ Own DB: '+r.success+'/'+r.total:'📋 No RedX orders'}${r.api_note?' · ⚠ '+r.api_note:r.cross_merchant_total?' · Cross-merchant: '+r.cross_merchant_total:''}</p><p>Local DB: ${l.total||0} total orders</p></div></details>`:''}
                ${l.areas?.length?`<div class="mt-4"><p class="text-sm font-semibold text-gray-700 mb-2">📍 Areas</p><div class="flex flex-wrap gap-2">${l.areas.map(a=>`<span class="bg-gray-100 px-3 py-1.5 rounded-full text-xs font-medium border">${a.area} (${a.cnt})</span>`).join('')}</div></div>`:''}
            </div>
        </div>`;
    } catch(e) {
        div.innerHTML=`<div class="bg-red-50 border border-red-200 rounded-xl p-6"><p class="font-bold text-red-700 mb-2">❌ Connection Error</p><p class="text-red-600 mb-3">${e.message}</p><p class="text-sm text-red-500">Check that <code>api/fraud-checker.php</code> exists and you're logged in as admin.</p></div>`;
    }
    btn.disabled=false; btn.innerHTML='🔍 Verify';
}

// ========== AREA STATS (sidebar & analytics) ==========
async function loadAreaStats(target='areaStats', days=90) {
    try {
        const j=await(await fetch(PAPI+'?action=area_stats&days='+days)).json();
        const e=document.getElementById(target);
        if(!e) return j.data;
        if(j.data?.length) {
            const mx=Math.max(...j.data.map(d=>parseInt(d.total_orders)));
            e.innerHTML=j.data.slice(0,15).map(a=>{
                const p=Math.round((a.total_orders/mx)*100);
                const s=a.total_orders>0?Math.round((a.delivered/a.total_orders)*100):0;
                return `<div class="p-2 rounded-lg hover:bg-gray-50 transition">
                    <div class="flex justify-between mb-1"><span class="font-medium text-gray-700 text-xs truncate" style="max-width:140px">${a.area_name}</span><span class="text-xs text-gray-500 font-medium">${a.total_orders} orders</span></div>
                    <div class="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-blue-500 rounded-full" style="width:${p}%"></div></div>
                    <div class="flex justify-between mt-0.5"><span class="text-xs ${s>=70?'text-green-600':s>=40?'text-yellow-600':'text-red-600'}">${s}% success</span><span class="text-xs text-gray-400">৳${Number(a.revenue||0).toLocaleString()}</span></div>
                </div>`;
            }).join('');
        } else e.innerHTML='<p class="text-gray-400">No data</p>';
        return j.data;
    } catch(e) { console.error(e); return []; }
}

<?php if($tab==='customer_check'):?>loadAreaStats();<?php endif;?>

<?php if($tab==='area_map'):?>
async function loadAreaChart() {
    const days=el('areaDays').value;
    const data = await loadAreaStats(null, days);
    if(!data?.length){el('areaChartContainer').innerHTML='<p class="text-center py-8 text-gray-400">No data</p>';return;}
    const mx=Math.max(...data.map(d=>parseInt(d.total_orders)));
    el('areaChartContainer').innerHTML=`
        <div class="space-y-2">${data.map(a=>{
            const pct=Math.round((a.total_orders/mx)*100);
            const s=a.total_orders>0?Math.round((a.delivered/a.total_orders)*100):0;
            const f=a.total_orders>0?Math.round((a.failed/a.total_orders)*100):0;
            return `<div class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50">
                <span class="w-32 text-sm font-medium text-gray-700 truncate">${a.area_name}</span>
                <div class="flex-1 h-6 bg-gray-100 rounded-full overflow-hidden flex">
                    <div class="h-full bg-green-500 transition-all" style="width:${s*pct/100}%" title="${s}% success"></div>
                    <div class="h-full bg-red-400 transition-all" style="width:${f*pct/100}%" title="${f}% failed"></div>
                </div>
                <span class="text-sm font-bold text-gray-800 w-16 text-right">${a.total_orders}</span>
                <span class="text-xs w-20 text-right ${s>=70?'text-green-600':s>=40?'text-yellow-600':'text-red-600'}">${s}% ✓</span>
                <span class="text-xs text-gray-400 w-24 text-right">৳${Number(a.revenue||0).toLocaleString()}</span>
            </div>`;
        }).join('')}</div>`;
    const sorted=[...data].filter(a=>a.total_orders>=2);
    sorted.sort((a,b)=>{const sa=a.total_orders>0?(a.delivered/a.total_orders):0;const sb=b.total_orders>0?(b.delivered/b.total_orders):0;return sb-sa;});
    el('topAreas').innerHTML=sorted.slice(0,8).map(a=>{const s=Math.round((a.delivered/a.total_orders)*100);return `<div class="flex justify-between items-center"><span class="text-gray-700">${a.area_name}</span><div class="flex items-center gap-2"><span class="text-green-600 font-bold">${s}%</span><span class="text-xs text-gray-400">${a.total_orders} orders</span></div></div>`;}).join('');
    sorted.reverse();
    el('worstAreas').innerHTML=sorted.slice(0,8).map(a=>{const f=Math.round((a.failed/a.total_orders)*100);return `<div class="flex justify-between items-center"><span class="text-gray-700">${a.area_name}</span><div class="flex items-center gap-2"><span class="text-red-600 font-bold">${f}% fail</span><span class="text-xs text-gray-400">${a.total_orders} orders</span></div></div>`;}).join('');
}

/* ═══════════════════════════════════════════════
   BANGLADESH DELIVERY PERFORMANCE MAP
   City data → green (success) / red (returns)
   ═══════════════════════════════════════════════ */
const CITY_TO_DISTRICT={
    "dhaka":"Dhaka","chittagong":"Chittagong","chattogram":"Chittagong","ctg":"Chittagong",
    "barishal":"Barisal","barisal":"Barisal","cumilla":"Comilla","comilla":"Comilla",
    "bogura":"Bogra","bogra":"Bogra","jashore":"Jessore","jessore":"Jessore",
    "cox's bazar":"Cox's Bazar","coxs bazar":"Cox's Bazar","coxsbazar":"Cox's Bazar",
    "b. baria":"Brahamanbaria","brahmanbaria":"Brahamanbaria",
    "n.ganj":"Narayanganj","narayanganj":"Narayanganj",
    "rajshahi":"Rajshahi","sylhet":"Sylhet","khulna":"Khulna","rangpur":"Rangpur",
    "mymensingh":"Mymensingh","gazipur":"Gazipur","tangail":"Tangail","faridpur":"Faridpur",
    "dinajpur":"Dinajpur","kishoreganj":"Kishoreganj","narsingdi":"Narsingdi","munshiganj":"Munshiganj",
    "manikganj":"Manikganj","sirajganj":"Sirajganj","pabna":"Pabna","kushtia":"Kushtia",
    "jhenaidah":"Jhenaidah","satkhira":"Satkhira","habiganj":"Habiganj","sunamganj":"Sunamganj",
    "moulvibazar":"Maulvibazar","chandpur":"Chandpur","feni":"Feni","noakhali":"Noakhali",
    "lakshmipur":"Lakshmipur","bhola":"Bhola","pirojpur":"Pirojpur","patuakhali":"Patuakhali",
    "barguna":"Barguna","gopalganj":"Gopalganj","madaripur":"Madaripur","shariatpur":"Shariatpur",
    "rajbari":"Rajbari","kurigram":"Kurigram","gaibandha":"Gaibandha","nilphamari":"Nilphamari",
    "lalmonirhat":"Lalmonirhat","panchagarh":"Panchagarh","thakurgaon":"Thakurgaon",
    "jamalpur":"Jamalpur","sherpur":"Sherpur","netrokona":"Netrakona","netrakona":"Netrakona",
    "rangamati":"Rangamati","khagrachhari":"Khagrachhari","bandarban":"Bandarban",
    "bagerhat":"Bagerhat","narail":"Narail","magura":"Magura","meherpur":"Meherpur",
    "chuadanga":"Chuadanga","joypurhat":"Joypurhat","naogaon":"Naogaon","natore":"Natore","nawabganj":"Nawabganj"
};
let _bdGeo=null, _cityStats={};

function cityToDistrict(cityName){
    const lc=(cityName||'').toLowerCase().trim();
    if(CITY_TO_DISTRICT[lc]) return CITY_TO_DISTRICT[lc];
    if(_bdGeo) for(const f of _bdGeo.features){
        const dn=f.properties.name.toLowerCase();
        if(lc===dn||lc.includes(dn)||dn.includes(lc)) return f.properties.name;
    }
    return null;
}

// Success rate → color gradient: red (high returns) → yellow → green (high success)
function successColor(rate){
    if(rate>=85) return '#15803d'; // green-700
    if(rate>=75) return '#16a34a'; // green-600
    if(rate>=65) return '#22c55e'; // green-500
    if(rate>=55) return '#84cc16'; // lime-500
    if(rate>=45) return '#eab308'; // yellow-500
    if(rate>=35) return '#f59e0b'; // amber-500
    if(rate>=25) return '#f97316'; // orange-500
    if(rate>=15) return '#ef4444'; // red-500
    return '#dc2626'; // red-600
}

function projectPt(lon,lat){return[((lon-87.8)/(92.8-87.8))*600,(1-(lat-20.4)/(26.8-20.4))*780];}
function geoPath(geo){
    function r2d(r){if(!r||r.length<3)return'';let d='M';r.forEach((p,i)=>{const[x,y]=projectPt(p[0],p[1]);d+=(i?'L':'')+(x|0)+','+(y|0);});return d+'Z';}
    let p='';
    if(geo.type==='Polygon')geo.coordinates.forEach(r=>{p+=r2d(r);});
    else if(geo.type==='MultiPolygon')geo.coordinates.forEach(pl=>pl.forEach(r=>{p+=r2d(r);}));
    return p;
}
function geoCenter(geo){
    let sx=0,sy=0,n=0;
    function add(r){r.forEach(p=>{sx+=p[0];sy+=p[1];n++;});}
    if(geo.type==='Polygon')geo.coordinates.forEach(add);
    else if(geo.type==='MultiPolygon')geo.coordinates.forEach(p=>p.forEach(add));
    return n?projectPt(sx/n,sy/n):[300,390];
}

async function initBdMap(){
    try{
        const resp=await fetch('<?= SITE_URL ?>/api/bd-map-data.json');
        if(!resp.ok)throw new Error(resp.status);
        _bdGeo=await resp.json();
        loadCityMapData();
    }catch(e){
        console.error('Map load error:',e);
        document.getElementById('bdMapContainer').innerHTML='<div class="flex flex-col items-center justify-center py-16 text-gray-400"><p class="text-3xl mb-2">🗺️</p><p class="text-sm font-medium">Map data not available</p><p class="text-xs mt-1">Place <code class="bg-gray-100 px-1 rounded">bd-map-data.json</code> in your /api/ folder</p></div>';
    }
}

async function loadCityMapData(){
    if(!_bdGeo)return;
    const days=document.getElementById('mapDays')?.value||90;
    try{
        const resp=await fetch(PAPI+'?action=city_stats&days='+days);
        const json=await resp.json();
        const data=json.data||[];

        _cityStats={};
        let totalOrders=0,totalDelivered=0,totalFailed=0,totalRevenue=0,matched=0;

        data.forEach(c=>{
            const district=cityToDistrict(c.city_name);
            if(!district)return;
            matched++;
            if(!_cityStats[district]) _cityStats[district]={city:c.city_name,orders:0,delivered:0,failed:0,revenue:0,success_rate:0,return_rate:0};
            _cityStats[district].orders+=parseInt(c.total_orders)||0;
            _cityStats[district].delivered+=parseInt(c.delivered)||0;
            _cityStats[district].failed+=parseInt(c.failed)||0;
            _cityStats[district].revenue+=parseFloat(c.revenue)||0;
            totalOrders+=parseInt(c.total_orders)||0;
            totalDelivered+=parseInt(c.delivered)||0;
            totalFailed+=parseInt(c.failed)||0;
            totalRevenue+=parseFloat(c.revenue)||0;
        });
        Object.values(_cityStats).forEach(s=>{
            s.success_rate=s.orders>0?Math.round((s.delivered/s.orders)*100):0;
            s.return_rate=s.orders>0?Math.round((s.failed/s.orders)*100):0;
        });

        renderCityMap();
        renderCityRanking();
        renderMapSummary(totalOrders,totalDelivered,totalFailed,totalRevenue,matched,data.length);
    }catch(e){console.error('City stats error:',e);}
}

function renderCityMap(){
    if(!_bdGeo)return;
    const svg=document.getElementById('bdMapSvg');
    const divPaths={};
    let html='';

    _bdGeo.features.forEach((feat,i)=>{
        const name=feat.properties.name, div=feat.properties.division||'';
        const d=geoPath(feat.geometry);
        if(!d)return;
        const s=_cityStats[name];
        let fill='#f1f5f9', strokeClr='rgba(148,163,184,0.3)', strokeW='0.5';
        if(s&&s.orders>0){
            fill=successColor(s.success_rate);
            strokeClr='rgba(255,255,255,0.6)';
            strokeW='0.8';
        }
        html+=`<path d="${d}" fill="${fill}" stroke="${strokeClr}" stroke-width="${strokeW}" class="cursor-pointer transition-all duration-150" style="stroke-linejoin:round" data-district="${name}" data-division="${div}" onmouseenter="showCityTT(event,'${name.replace(/'/g,"\\'")}','${div}')" onmousemove="moveCityTT(event)" onmouseleave="hideCityTT()"/>`;
        if(!divPaths[div])divPaths[div]='';
        divPaths[div]+=d;
    });

    // Division borders
    Object.values(divPaths).forEach(d=>{
        html+=`<path d="${d}" fill="none" stroke="rgba(30,41,59,0.4)" stroke-width="1.8" style="pointer-events:none;stroke-linejoin:round"/>`;
    });

    // City labels on districts with order data
    _bdGeo.features.forEach(feat=>{
        const name=feat.properties.name, s=_cityStats[name];
        if(!s||s.orders<1)return;
        const[cx,cy]=geoCenter(feat.geometry);
        const label=(s.city||name);
        const short=label.length>12?label.slice(0,10)+'…':label;
        const fs=s.orders>=20?'10':'8.5';
        html+=`<text x="${cx}" y="${cy-4}" text-anchor="middle" style="font-size:${fs}px;font-weight:700;fill:#1e293b;pointer-events:none;text-shadow:0 0 3px rgba(255,255,255,0.95),0 1px 2px rgba(255,255,255,0.9)">${short}</text>`;
        html+=`<text x="${cx}" y="${cy+8}" text-anchor="middle" style="font-size:7.5px;fill:#475569;pointer-events:none;text-shadow:0 0 3px rgba(255,255,255,0.9)">${s.orders} · ${s.success_rate}%✓ · ${s.return_rate}%✗</text>`;
    });

    svg.innerHTML=html;
}

function renderCityRanking(){
    const box=document.getElementById('cityRanking');
    const entries=Object.entries(_cityStats).filter(([k,v])=>v.orders>0).sort((a,b)=>b[1].orders-a[1].orders);
    if(!entries.length){box.innerHTML='<p class="text-gray-400">No city data</p>';return;}
    box.innerHTML=entries.slice(0,12).map(([dist,s])=>{
        const clr=successColor(s.success_rate);
        const name=s.city||dist;
        return `<div class="flex items-center gap-2 py-1.5 cursor-pointer hover:bg-gray-100 rounded px-1 -mx-1 transition" onmouseenter="hlDistrict('${dist.replace(/'/g,"\\'")}')" onmouseleave="ulDistrict()">
            <div class="w-3 h-3 rounded-sm flex-shrink-0 border border-white shadow-sm" style="background:${clr}"></div>
            <span class="flex-1 text-gray-700 truncate font-medium" title="${name}">${name}</span>
            <span class="text-gray-400 tabular-nums text-[10px]">${s.orders}</span>
            <span class="font-bold tabular-nums w-10 text-right text-[11px]" style="color:${clr}">${s.success_rate}%</span>
        </div>`;
    }).join('');
}

function renderMapSummary(total,delivered,failed,revenue,matched,rawCount){
    const box=document.getElementById('mapSummary');
    const sr=total>0?Math.round((delivered/total)*100):0;
    const rr=total>0?Math.round((failed/total)*100):0;
    box.innerHTML=`
        <div class="flex justify-between"><span class="text-gray-500">Total Orders</span><span class="font-bold text-gray-800">${total.toLocaleString()}</span></div>
        <div class="flex justify-between"><span class="text-gray-500">Delivered</span><span class="font-bold text-green-600">${delivered.toLocaleString()} (${sr}%)</span></div>
        <div class="flex justify-between"><span class="text-gray-500">Returned</span><span class="font-bold text-red-500">${failed.toLocaleString()} (${rr}%)</span></div>
        <div class="flex justify-between"><span class="text-gray-500">Revenue</span><span class="font-bold text-indigo-600">৳${Number(revenue).toLocaleString('en',{maximumFractionDigits:0})}</span></div>
        <div class="flex justify-between pt-2 mt-2 border-t border-gray-200"><span class="text-gray-400">Cities mapped</span><span class="text-gray-500">${matched}/${rawCount}</span></div>`;
}

function showCityTT(e,district,division){
    const tt=document.getElementById('mapTooltip');
    const s=_cityStats[district];
    const name=s?.city||district;
    document.getElementById('ttName').textContent=name;
    document.getElementById('ttDiv').textContent='📍 '+division+' Division'+(s?.city&&s.city!==district?' · '+district:'');
    if(s&&s.orders>0){
        document.getElementById('ttStats').innerHTML=
            `<div class="flex justify-between gap-6"><span class="text-gray-400">Orders</span><span class="font-bold">${s.orders}</span></div>`+
            `<div class="flex justify-between gap-6"><span class="text-gray-400">Delivered</span><span class="text-green-400 font-medium">${s.delivered} (${s.success_rate}%)</span></div>`+
            `<div class="flex justify-between gap-6"><span class="text-gray-400">Returned</span><span class="text-red-400 font-medium">${s.failed} (${s.return_rate}%)</span></div>`+
            `<div class="flex justify-between gap-6"><span class="text-gray-400">Revenue</span><span>৳${Number(s.revenue).toLocaleString('en',{maximumFractionDigits:0})}</span></div>`+
            `<div class="mt-1.5 h-2 rounded-full overflow-hidden bg-gray-700 flex"><div class="h-full" style="width:${s.success_rate}%;background:${successColor(s.success_rate)}"></div></div>`+
            `<div class="flex justify-between mt-0.5"><span class="text-[9px] text-red-400">${s.return_rate}% returns</span><span class="text-[9px] text-green-400">${s.success_rate}% success</span></div>`;
    }else{
        document.getElementById('ttStats').innerHTML='<p class="text-gray-500">No orders from this city</p>';
    }
    const card=document.getElementById('selectedCityInfo');
    if(s&&s.orders>0){
        card.innerHTML=`
            <p class="font-bold text-gray-800 text-sm">${name}</p>
            <p class="text-gray-400 text-[10px] mb-2">${division} Division</p>
            <div class="space-y-1.5">
                <div class="flex justify-between"><span class="text-gray-500">Orders</span><span class="font-bold text-gray-800">${s.orders}</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Success</span><span class="font-bold" style="color:${successColor(s.success_rate)}">${s.success_rate}%</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Returns</span><span class="font-bold text-red-500">${s.return_rate}%</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Revenue</span><span class="font-bold text-indigo-600">৳${Number(s.revenue).toLocaleString('en',{maximumFractionDigits:0})}</span></div>
            </div>
            <div class="mt-2.5 h-2.5 rounded-full overflow-hidden bg-gray-200 border border-gray-300">
                <div class="h-full rounded-full transition-all" style="width:${s.success_rate}%;background:${successColor(s.success_rate)}"></div>
            </div>
            <div class="flex justify-between mt-1"><span class="text-[9px] text-red-400">Returns ${s.return_rate}%</span><span class="text-[9px] text-green-500">Success ${s.success_rate}%</span></div>`;
    }else{
        card.innerHTML=`<p class="font-medium text-gray-600">${district}</p><p class="text-gray-400 text-[10px]">${division} Division</p><p class="text-gray-400 text-[10px] mt-1">No orders yet</p>`;
    }
    tt.classList.remove('hidden');moveCityTT(e);
}
function moveCityTT(e){
    const tt=document.getElementById('mapTooltip'),rect=document.getElementById('bdMapContainer').getBoundingClientRect();
    let x=e.clientX-rect.left+14,y=e.clientY-rect.top-10;
    if(x+260>rect.width)x=e.clientX-rect.left-270;
    if(y<0)y=10;
    tt.style.left=x+'px';tt.style.top=y+'px';
}
function hideCityTT(){document.getElementById('mapTooltip').classList.add('hidden');}
function hlDistrict(name){document.querySelectorAll('#bdMapSvg path[data-district]').forEach(p=>{if(p.dataset.district===name){p.style.filter='brightness(0.8) drop-shadow(0 0 4px rgba(0,0,0,0.3))';p.style.strokeWidth='2';}else p.style.opacity='0.25';});}
function ulDistrict(){document.querySelectorAll('#bdMapSvg path[data-district]').forEach(p=>{p.style.filter='';p.style.opacity='';p.style.strokeWidth='';});}

initBdMap();
loadAreaChart();

/* ── Map Zoom & Pan (Google Maps style) ── */
(function(){
    const container=document.getElementById('bdMapContainer');
    const svg=document.getElementById('bdMapSvg');
    if(!container||!svg)return;

    let scale=1, panX=0, panY=0;
    let isDragging=false, startX=0, startY=0, startPanX=0, startPanY=0;
    const MIN_SCALE=0.6, MAX_SCALE=6;

    function applyTransform(){
        svg.style.transform=`translate(${panX}px,${panY}px) scale(${scale})`;
        const badge=document.getElementById('mapZoomBadge');
        if(badge) badge.textContent=Math.round(scale*100)+'%';
    }

    // Scroll to zoom (centered on cursor)
    container.addEventListener('wheel',function(e){
        e.preventDefault();
        const rect=container.getBoundingClientRect();
        const mx=e.clientX-rect.left;
        const my=e.clientY-rect.top;

        const delta=e.deltaY>0?0.85:1.18;
        const newScale=Math.min(MAX_SCALE,Math.max(MIN_SCALE,scale*delta));
        const ratio=newScale/scale;

        // Zoom toward cursor position
        panX=mx-(mx-panX)*ratio;
        panY=my-(my-panY)*ratio;
        scale=newScale;
        applyTransform();
    },{passive:false});

    // Drag to pan
    container.addEventListener('mousedown',function(e){
        if(e.button!==0)return;
        isDragging=true;
        startX=e.clientX; startY=e.clientY;
        startPanX=panX; startPanY=panY;
        svg.style.cursor='grabbing';
        e.preventDefault();
    });
    window.addEventListener('mousemove',function(e){
        if(!isDragging)return;
        panX=startPanX+(e.clientX-startX);
        panY=startPanY+(e.clientY-startY);
        applyTransform();
    });
    window.addEventListener('mouseup',function(){
        if(isDragging){isDragging=false;svg.style.cursor='grab';}
    });

    // Touch support (pinch zoom + drag)
    let lastTouchDist=0, lastTouchCenter=null, touchStartPanX=0, touchStartPanY=0;
    container.addEventListener('touchstart',function(e){
        if(e.touches.length===1){
            isDragging=true;
            startX=e.touches[0].clientX; startY=e.touches[0].clientY;
            startPanX=panX; startPanY=panY;
        }else if(e.touches.length===2){
            isDragging=false;
            const dx=e.touches[0].clientX-e.touches[1].clientX;
            const dy=e.touches[0].clientY-e.touches[1].clientY;
            lastTouchDist=Math.sqrt(dx*dx+dy*dy);
            lastTouchCenter={x:(e.touches[0].clientX+e.touches[1].clientX)/2,y:(e.touches[0].clientY+e.touches[1].clientY)/2};
            touchStartPanX=panX; touchStartPanY=panY;
        }
        e.preventDefault();
    },{passive:false});
    container.addEventListener('touchmove',function(e){
        if(e.touches.length===1&&isDragging){
            panX=startPanX+(e.touches[0].clientX-startX);
            panY=startPanY+(e.touches[0].clientY-startY);
            applyTransform();
        }else if(e.touches.length===2&&lastTouchDist>0){
            const dx=e.touches[0].clientX-e.touches[1].clientX;
            const dy=e.touches[0].clientY-e.touches[1].clientY;
            const dist=Math.sqrt(dx*dx+dy*dy);
            const ratio=dist/lastTouchDist;
            const newScale=Math.min(MAX_SCALE,Math.max(MIN_SCALE,scale*ratio));

            const rect=container.getBoundingClientRect();
            const cx=lastTouchCenter.x-rect.left;
            const cy=lastTouchCenter.y-rect.top;
            const r2=newScale/scale;
            panX=cx-(cx-panX)*r2;
            panY=cy-(cy-panY)*r2;
            scale=newScale;
            lastTouchDist=dist;
            applyTransform();
        }
        e.preventDefault();
    },{passive:false});
    container.addEventListener('touchend',function(){isDragging=false;lastTouchDist=0;});

    // Double-click to zoom in
    container.addEventListener('dblclick',function(e){
        e.preventDefault();
        const rect=container.getBoundingClientRect();
        const mx=e.clientX-rect.left, my=e.clientY-rect.top;
        const newScale=Math.min(MAX_SCALE,scale*1.5);
        const ratio=newScale/scale;
        panX=mx-(mx-panX)*ratio;
        panY=my-(my-panY)*ratio;
        scale=newScale;
        applyTransform();
    });

    // Expose global functions for buttons
    window.mapZoom=function(factor){
        const rect=container.getBoundingClientRect();
        const cx=rect.width/2, cy=rect.height/2;
        const newScale=Math.min(MAX_SCALE,Math.max(MIN_SCALE,scale*factor));
        const ratio=newScale/scale;
        panX=cx-(cx-panX)*ratio;
        panY=cy-(cy-panY)*ratio;
        scale=newScale;
        applyTransform();
    };
    window.mapReset=function(){
        scale=1; panX=0; panY=0;
        applyTransform();
    };
})();

async function backfillAreaNames(){
    const btn=document.getElementById('backfillBtn');
    const res=document.getElementById('backfillResult');
    if(btn){btn.disabled=true;btn.textContent='⏳ Resolving...';}
    res.classList.remove('hidden');
    res.className='mt-3 p-3 rounded-lg text-sm bg-blue-50 text-blue-700 border border-blue-200';
    res.textContent='Fetching Pathao area names for orders... this may take a moment.';
    let totalResolved=0, calls=0;
    try{
        while(calls<10){
            const j=await(await fetch(PAPI,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'backfill_area_names'})})).json();
            if(!j.success){res.className='mt-3 p-3 rounded-lg text-sm bg-red-50 text-red-700 border border-red-200';res.textContent='❌ '+j.message;break;}
            totalResolved+=j.resolved||0;
            calls++;
            if((j.remaining||0)<=0){
                res.className='mt-3 p-3 rounded-lg text-sm bg-green-50 text-green-700 border border-green-200';
                res.textContent='✅ Done! Resolved '+totalResolved+' orders total. All area names are now populated.';
                updatePendingBadge(0);
                loadAreaChart();
                break;
            }else{
                res.textContent='⏳ Resolved '+totalResolved+' orders so far, '+j.remaining+' remaining...';
                updatePendingBadge(j.remaining);
            }
        }
        if(calls>=10){res.textContent+=' (stopped after 10 batches — click again for more)';}
    }catch(e){
        res.className='mt-3 p-3 rounded-lg text-sm bg-red-50 text-red-700 border border-red-200';
        res.textContent='❌ Error: '+e.message;
    }
    if(btn){btn.disabled=false;btn.textContent='🚀 Run Now';}
}

function updatePendingBadge(count){
    const badge=document.getElementById('pendingBadge');
    if(!badge)return;
    if(count>0){
        badge.textContent=count+' pending';
        badge.className='px-2.5 py-1 rounded-full bg-yellow-100 text-yellow-700 border border-yellow-200 font-medium text-xs';
    }else{
        badge.textContent='✓ All resolved';
        badge.className='px-2.5 py-1 rounded-full bg-green-100 text-green-700 border border-green-200 font-medium text-xs';
    }
}

async function toggleAutoBackfill(enabled){
    const label=document.getElementById('toggleLabel');
    if(label)label.textContent=enabled?'Auto: ON':'Auto: OFF';
    try{
        const j=await(await fetch(PAPI,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle_area_backfill',enabled:enabled?1:0})})).json();
        if(!j.success){alert('❌ '+(j.message||'Failed to save'));document.getElementById('backfillToggle').checked=!enabled;if(label)label.textContent=!enabled?'Auto: ON':'Auto: OFF';}
    }catch(e){alert('Error: '+e.message);document.getElementById('backfillToggle').checked=!enabled;if(label)label.textContent=!enabled?'Auto: ON':'Auto: OFF';}
}

// Silent auto-trigger: if auto-backfill is enabled and there are pending orders, check via cron-compatible endpoint
<?php if ($backfillEnabled && $pendingBackfill > 0):
    $shouldAutoRun = true;
    if ($backfillLastRun) { $shouldAutoRun = (time() - strtotime($backfillLastRun)) >= 86400; }
    if ($shouldAutoRun): ?>
(function(){
    // Auto-backfill overdue — run silently in background
    var res=document.getElementById('backfillResult');
    if(res){res.classList.remove('hidden');res.className='mt-3 p-3 rounded-lg text-sm bg-blue-50 text-blue-700 border border-blue-200';res.textContent='⏳ Auto-backfill starting (24h overdue)...';}
    setTimeout(function(){ backfillAreaNames(); }, 1500);
})();
<?php endif; endif; ?>
<?php endif;?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
