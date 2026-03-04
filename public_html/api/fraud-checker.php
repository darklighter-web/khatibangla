<?php
/**
 * Courier Fraud Checker v13 — Own DB + Direct Courier APIs (with rate limit protection)
 * 
 * Data sources (per courier):
 *   OWN DB  → orders table with courier_name/shipping_method (primary — always has real counts)
 *   Pathao  → merchant.pathao.com (cross-merchant rating)
 *   Steadfast → API/web scrape (cross-merchant counts — often blocked with 403)
 *   RedX    → api.redx.com.bd (cross-merchant counts)
 *
 * ┌─────────────────────────────────────────────────────────────────┐
 * │              COURIER API RATE LIMITS (researched)               │
 * ├──────────────┬──────────────────────────────────────────────────┤
 * │ Pathao       │ Auth: OAuth2 (merchant.pathao.com/api/v1/login) │
 * │              │ Token lifetime: ~5 days (432,000 sec)            │
 * │              │ Login rate limit: undocumented, ~10 req/min      │
 * │              │ API rate limit: ~60 req/min (community pkg)      │
 * │              │ OUR CACHE: token 4h, results 30min per-phone     │
 * ├──────────────┼──────────────────────────────────────────────────┤
 * │ Steadfast    │ Auth: API Key + Secret OR web session login      │
 * │              │ courier_score API: frequently returns 403         │
 * │              │ Web scrape: session-based, CSRF required          │
 * │              │   Login rate: aggressive blocking (IP-based)      │
 * │              │   Fraud check: limit ~20/day per account          │
 * │              │   WordPress plugin warns "Check Limit Message"    │
 * │              │ OUR CACHE: session 1h, results 30min per-phone   │
 * ├──────────────┼──────────────────────────────────────────────────┤
 * │ RedX         │ Auth: phone+password (api.redx.com.bd/v4/auth)   │
 * │              │ Login rate limit: VERY strict, ~3-5 req/10min     │
 * │              │   429 "temporarily blocked for abusing service"   │
 * │              │ Data query rate: ~30 req/min (estimated)          │
 * │              │ OpenAPI (shipping): separate Bearer token, less   │
 * │              │   strict, but no fraud check endpoint             │
 * │              │ OUR CACHE: token 12h, results 30min per-phone    │
 * ├──────────────┼──────────────────────────────────────────────────┤
 * │ Per-phone    │ All external API results cached 30 min            │
 * │ result cache │ Same phone = instant response from DB cache       │
 * │              │ No login, no API call, no rate limit risk         │
 * └──────────────┴──────────────────────────────────────────────────┘
 *
 * Usage: GET /api/fraud-checker.php?phone=01XXXXXXXXX
 */
error_reporting(0); ini_set('display_errors', 0);
header('Content-Type: application/json');
ob_start();
try { require_once __DIR__.'/../includes/session.php'; require_once __DIR__.'/../includes/functions.php'; } catch(Exception $e) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit; }
ob_end_clean();
if (empty($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }

$action = $_GET['action'] ?? 'check';
$phone  = preg_replace('/[^0-9]/', '', $_GET['phone'] ?? $_POST['phone'] ?? '');
if (substr($phone,0,2)==='88') $phone = substr($phone,2);
if (strlen($phone)===10 && $phone[0]!=='0') $phone = '0'.$phone;

// ── Save credentials ──
if ($action === 'save_credentials') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $db = Database::getInstance();
    foreach (['steadfast_merchant_email'=>'Steadfast Email','steadfast_merchant_password'=>'Steadfast Password','redx_phone'=>'RedX Phone','redx_password'=>'RedX Password'] as $key=>$label) {
        if (isset($input[$key])) {
            $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key]);
            if ($exists) $db->update('site_settings', ['setting_value'=>$input[$key]], 'setting_key = ?', [$key]);
            else $db->insert('site_settings', ['setting_key'=>$key,'setting_value'=>$input[$key],'setting_type'=>'text','setting_group'=>'courier','label'=>$label]);
        }
    }
    echo json_encode(['success'=>true,'message'=>'Saved']); exit;
}

if (!preg_match('/^01[3-9][0-9]{8}$/', $phone)) { echo json_encode(['success'=>false,'error'=>'Invalid BD phone (01XXXXXXXXX)']); exit; }
$db = Database::getInstance();

// Load shared rate limiter (exponential backoff, logging, rate checks)
require_once __DIR__ . '/courier-rate-limiter.php';

// Legacy aliases for backward compatibility within this file
function _curlExec($ch, $label = 'unknown') {
    return courierCurlExec($ch, $label, $label, 5);
}
function _logApiError($courier, $httpCode, $response) {
    courierLogApiError($courier, $httpCode, '', $response);
}

// ══════════════════════════════════════════
// 1) OWN DATABASE — per-courier breakdown (primary source, always works)
// ══════════════════════════════════════════
$local     = _checkLocal($phone, $db);
$localByCourier = _checkLocalByCourier($phone, $db);

// ══════════════════════════════════════════
// 2) EXTERNAL APIs — cross-merchant data (supplement)
//    With per-phone result caching (30 min) to avoid hammering APIs
// ══════════════════════════════════════════
$cacheKey = 'fraud_cache_' . $phone;
$cacheTTL = 1800; // 30 minutes
$forceRefresh = isset($_GET['fresh']) || isset($_GET['force']);
$cached = $forceRefresh ? null : _getResultCache($db, $cacheKey, $cacheTTL);

// If cached Steadfast result has an error but API key exists, force re-check
if ($cached && isset($cached['steadfast']['error']) && getSetting('steadfast_api_key','')) {
    $cached = null; // Force re-fetch since we now have the /fraud_check/ endpoint
}

if ($cached) {
    // Use cached API results
    $pathaoApi    = $cached['pathao'] ?? ['error'=>'cache miss'];
    $steadfastApi = $cached['steadfast'] ?? ['error'=>'cache miss'];
    $redxApi      = $cached['redx'] ?? ['error'=>'cache miss'];
} else {
    // Fresh API calls — rate limited
    $pathaoApi    = _checkPathao($phone);
    $steadfastApi = _checkSteadfast($phone);
    $redxApi      = _checkRedx($phone);
    
    // Cache results
    _setResultCache($db, $cacheKey, [
        'pathao'    => $pathaoApi,
        'steadfast' => $steadfastApi,
        'redx'      => $redxApi,
    ]);
}

// ══════════════════════════════════════════
// 3) MERGE: Own DB counts + External API data
// ══════════════════════════════════════════
$dbPathao    = $localByCourier['pathao'] ?? ['total'=>0,'delivered'=>0,'cancelled'=>0];
$dbSteadfast = $localByCourier['steadfast'] ?? ['total'=>0,'delivered'=>0,'cancelled'=>0];
$dbRedx      = $localByCourier['redx'] ?? ['total'=>0,'delivered'=>0,'cancelled'=>0];

// Pathao: show API rating + own DB counts
$pathao = [
    'total'           => intval($dbPathao['total']),
    'success'         => intval($dbPathao['delivered']),
    'cancel'          => intval($dbPathao['cancelled']),
    'customer_rating' => $pathaoApi['customer_rating'] ?? null,
    'show_count'      => true, // we always show counts from own DB
    'api_show_count'  => $pathaoApi['show_count'] ?? true,
    'source'          => 'own_db + pathao_api',
];
// If API also has counts, use max of own DB and API
if (!isset($pathaoApi['error']) && ($pathaoApi['show_count']??true)===true && intval($pathaoApi['total']??0) > 0) {
    $pathao['cross_merchant_total']   = intval($pathaoApi['total']);
    $pathao['cross_merchant_success'] = intval($pathaoApi['success']);
    $pathao['cross_merchant_cancel']  = intval($pathaoApi['cancel']);
    // Promote API data if it has more
    if (intval($pathaoApi['total']) > $pathao['total']) {
        $pathao['total']   = intval($pathaoApi['total']);
        $pathao['success'] = intval($pathaoApi['success']);
        $pathao['cancel']  = intval($pathaoApi['cancel']);
    }
}

// Steadfast: own DB counts (primary) + API cross-merchant (if available)
$steadfast = [
    'total'   => intval($dbSteadfast['total']),
    'success' => intval($dbSteadfast['delivered']),
    'cancel'  => intval($dbSteadfast['cancelled']),
    'source'  => 'own_db',
];
if (!isset($steadfastApi['error']) && intval($steadfastApi['total']??0) > 0) {
    $steadfast['cross_merchant_total']   = intval($steadfastApi['total']);
    $steadfast['cross_merchant_success'] = intval($steadfastApi['success']);
    $steadfast['cross_merchant_cancel']  = intval($steadfastApi['cancel']);
    $steadfast['source'] = 'own_db + steadfast_api';
    // Always promote API data if it has MORE data than own DB (cross-merchant includes our orders too)
    if (intval($steadfastApi['total']) > $steadfast['total']) {
        $steadfast['total']   = intval($steadfastApi['total']);
        $steadfast['success'] = intval($steadfastApi['success']);
        $steadfast['cancel']  = intval($steadfastApi['cancel']);
        $steadfast['source']  = $steadfast['total'] > intval($dbSteadfast['total']) ? 'steadfast_api (cross-merchant)' : 'own_db + steadfast_api';
    }
} elseif (isset($steadfastApi['error'])) {
    $steadfast['api_note'] = $steadfastApi['error'];
}
// Carry through Steadfast fraud report data if present
if (!empty($steadfastApi['is_fraud'])) {
    $steadfast['is_fraud'] = true;
    $steadfast['fraud_count'] = intval($steadfastApi['fraud_count'] ?? 0);
    if (!empty($steadfastApi['fraud_reports'])) {
        $steadfast['fraud_reports'] = $steadfastApi['fraud_reports'];
    }
}

// RedX: own DB counts + API cross-merchant
$redx = [
    'total'   => intval($dbRedx['total']),
    'success' => intval($dbRedx['delivered']),
    'cancel'  => intval($dbRedx['cancelled']),
    'source'  => 'own_db',
];
if (!isset($redxApi['error']) && intval($redxApi['total']??0) > 0) {
    $redx['cross_merchant_total']   = intval($redxApi['total']);
    $redx['cross_merchant_success'] = intval($redxApi['success']);
    $redx['cross_merchant_cancel']  = intval($redxApi['cancel']);
    $redx['source'] = 'own_db + redx_api';
    // Always promote API data if it has MORE data than own DB
    if (intval($redxApi['total']) > $redx['total']) {
        $redx['total']   = intval($redxApi['total']);
        $redx['success'] = intval($redxApi['success']);
        $redx['cancel']  = intval($redxApi['cancel']);
        $redx['source']  = $redx['total'] > intval($dbRedx['total']) ? 'redx_api (cross-merchant)' : 'own_db + redx_api';
    }
} elseif (isset($redxApi['error'])) {
    $redx['api_note'] = $redxApi['error'];
}

$results = ['phone'=>$phone, 'pathao'=>$pathao, 'steadfast'=>$steadfast, 'redx'=>$redx, 'local'=>$local];

// ── Combined stats ──
// Use BOTH own DB totals AND cross-merchant API data
$ownTotal   = intval($local['total']??0);
$ownSuccess = intval($local['delivered']??0);
$ownCancel  = intval($local['cancelled']??0);

// Cross-merchant totals (from APIs, for customers who ordered from OTHER shops too)
$xTotal = $xSuccess = $xCancel = 0;
foreach (['pathao'=>$pathaoApi, 'redx'=>$redxApi, 'steadfast'=>$steadfastApi] as $src=>$d) {
    if (isset($d['error'])) continue;
    if ($src==='pathao' && ($d['show_count']??true)===false) continue;
    $xTotal   += intval($d['total']??0);
    $xSuccess += intval($d['success']??0);
    $xCancel  += intval($d['cancel']??0);
}

// Grand total: own + cross-merchant (avoid double-counting own orders)
$grandTotal   = max($ownTotal, $xTotal);
$grandSuccess = max($ownSuccess, $xSuccess);
$grandCancel  = max($ownCancel, $xCancel);
$grandRate    = $grandTotal > 0 ? round(($grandSuccess / $grandTotal) * 100) : 0;

$pr = $pathaoApi['customer_rating'] ?? null;

// If only Pathao rating available (no counts anywhere)
if ($grandTotal === 0 && $pr) {
    $rr = ['excellent_customer'=>95,'good_customer'=>80,'moderate_customer'=>55,'risky_customer'=>25,'new_customer'=>0];
    $grandRate = $rr[$pr] ?? 50;
}

// Risk assessment
if ($grandTotal === 0 && !$pr) {
    $risk = 'new'; $rl = 'New Customer';
} elseif ($grandTotal === 0 && $pr) {
    $rm = ['excellent_customer'=>['low','Excellent (Pathao)'],'good_customer'=>['low','Good (Pathao)'],'moderate_customer'=>['medium','Moderate (Pathao)'],'risky_customer'=>['high','High Risk (Pathao)'],'new_customer'=>['new','New Customer']];
    [$risk, $rl] = $rm[$pr] ?? ['new','New Customer'];
} elseif ($grandTotal > 0) {
    if ($grandRate >= 70) { $risk='low'; $rl='Trusted Customer'; }
    elseif ($grandRate >= 40) { $risk='medium'; $rl='Moderate Risk'; }
    else { $risk='high'; $rl='High Risk'; }
    if ($pr === 'excellent_customer' && $risk === 'medium') { $risk='low'; $rl .= ' ✓ Pathao Excellent'; }
} else {
    $risk = 'new'; $rl = 'New Customer';
}
if (!empty($local['is_blocked'])) { $risk='blocked'; $rl='BLOCKED: '.($local['block_reason']??''); }

$results['combined'] = [
    'total'           => $grandTotal,
    'success'         => $grandSuccess,
    'cancel'          => $grandCancel,
    'rate'            => $grandRate,
    'risk'            => $risk,
    'risk_label'      => $rl,
    'pathao_rating'   => $pr,
    'own_total'       => $ownTotal,
    'cross_total'     => $xTotal,
];
$results['success'] = true;
echo json_encode($results, JSON_PRETTY_PRINT);
exit;

// ═══════════════════════════════════════════════════
// LOCAL: Total across all couriers
// ═══════════════════════════════════════════════════
function _checkLocal($phone, $db) {
    $pl = '%' . substr($phone, -10) . '%';
    try {
        $r = $db->fetch("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered,
                   SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled,
                   SUM(CASE WHEN order_status='returned' THEN 1 ELSE 0 END) as returned,
                   SUM(CASE WHEN order_status='delivered' THEN total ELSE 0 END) as total_spent,
                   MIN(created_at) as first_order, MAX(created_at) as last_order
            FROM orders WHERE customer_phone LIKE ? AND order_status NOT IN ('incomplete')
        ", [$pl]);

        $bl = null;
        try { $bl = $db->fetch("SELECT reason FROM blocked_phones WHERE phone LIKE ?", [$pl]); } catch(\Throwable $e) {}
        $ar = [];
        try { $ar = $db->fetchAll("
            SELECT COALESCE(NULLIF(customer_district,''), COALESCE(NULLIF(customer_city,''),'Unknown')) as area, COUNT(*) as cnt
            FROM orders WHERE customer_phone LIKE ? GROUP BY area ORDER BY cnt DESC LIMIT 5
        ", [$pl]); } catch(\Throwable $e) {}

        return [
            'total'        => intval($r['total']??0),
            'delivered'    => intval($r['delivered']??0),
            'cancelled'    => intval($r['cancelled']??0),
            'returned'     => intval($r['returned']??0),
            'total_spent'  => floatval($r['total_spent']??0),
            'first_order'  => $r['first_order'] ?? null,
            'last_order'   => $r['last_order'] ?? null,
            'is_blocked'   => !empty($bl),
            'block_reason' => $bl['reason'] ?? null,
            'areas'        => $ar,
        ];
    } catch (\Throwable $e) {
        return ['error'=>'DB: '.$e->getMessage(), 'total'=>0];
    }
}

// ═══════════════════════════════════════════════════
// LOCAL: Per-courier breakdown from own orders
// ═══════════════════════════════════════════════════
function _checkLocalByCourier($phone, $db) {
    $pl = '%' . substr($phone, -10) . '%';
    $result = ['pathao'=>['total'=>0,'delivered'=>0,'cancelled'=>0], 'steadfast'=>['total'=>0,'delivered'=>0,'cancelled'=>0], 'redx'=>['total'=>0,'delivered'=>0,'cancelled'=>0]];

    $courierPatterns = [
        'pathao'    => ['pathao'],
        'steadfast' => ['steadfast'],
        'redx'      => ['redx','red-x','red x'],
    ];

    foreach ($courierPatterns as $key => $patterns) {
        // Build OR conditions for matching courier name
        $conditions = [];
        $params = [$pl];
        foreach ($patterns as $p) {
            $conditions[] = "LOWER(COALESCE(courier_name,'')) LIKE ?";
            $conditions[] = "LOWER(COALESCE(shipping_method,'')) LIKE ?";
            $params[] = '%'.$p.'%';
            $params[] = '%'.$p.'%';
        }
        $where = implode(' OR ', $conditions);

        try {
            $r = $db->fetch("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered,
                       SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled
                FROM orders
                WHERE customer_phone LIKE ?
                  AND order_status NOT IN ('incomplete')
                  AND ($where)
            ", $params);

            $result[$key] = [
                'total'     => intval($r['total']??0),
                'delivered' => intval($r['delivered']??0),
                'cancelled' => intval($r['cancelled']??0),
            ];
        } catch (\Throwable $e) {
            // Keep defaults (0)
        }
    }

    return $result;
}

// ═══════════════════════════════════════════════════
// PATHAO API — cross-merchant rating (with token caching)
// Token lifetime: 5 days. Cache for 4 hours to be safe.
// Rate limit: ~60 req/min (login endpoint much stricter)
// ═══════════════════════════════════════════════════
function _checkPathao($phone) {
    $u = getSetting('pathao_username','');
    $p = getSetting('pathao_password','');
    if (!$u || !$p) return ['error'=>'Pathao credentials not set'];

    $token = _getPathaoCachedToken($u, $p);
    if (!$token) return ['error'=>'Pathao login failed'];

    $ch = curl_init('https://merchant.pathao.com/api/v1/user/success');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','Authorization: Bearer '.$token],
        CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone]),
    ]);
    $result = _curlExec($ch, 'pathao_fraud');
    $r = $result['response']; $c = $result['http_code']; curl_close($ch);
    
    // Token expired — clear cache and retry once
    if ($c === 401) {
        $db = Database::getInstance();
        try { $db->update('site_settings', ['setting_value'=>''], 'setting_key = ?', ['pathao_fraud_token']); } catch(\Throwable $e) {}
        $token = _getPathaoCachedToken($u, $p);
        if (!$token) return ['error'=>'Pathao re-login failed'];
        
        $ch = curl_init('https://merchant.pathao.com/api/v1/user/success');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','Authorization: Bearer '.$token],
            CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone]),
        ]);
        $result = _curlExec($ch, 'pathao_fraud_retry');
        $r = $result['response']; $c = $result['http_code']; curl_close($ch);
    }
    
    if ($c>=400) return ['error'=>"Pathao HTTP $c"];

    $res = json_decode($r,true);
    $cu  = $res['data']['customer'] ?? $res['data'] ?? [];

    return [
        'success'         => intval($cu['successful_delivery']??0),
        'cancel'          => intval(($cu['total_delivery']??0) - ($cu['successful_delivery']??0)),
        'total'           => intval($cu['total_delivery']??0),
        'show_count'      => $cu['show_count'] ?? true,
        'customer_rating' => $cu['customer_rating'] ?? null,
        'source'          => 'pathao_api',
    ];
}

function _getPathaoCachedToken($username, $password) {
    $db = Database::getInstance();
    
    // Check cache (4 hours)
    try {
        $cached = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'pathao_fraud_token'");
        if ($cached && !empty($cached['setting_value'])) {
            $data = json_decode($cached['setting_value'], true);
            if ($data && !empty($data['token']) && ($data['expires'] ?? 0) > time()) {
                return $data['token'];
            }
        }
    } catch (\Throwable $e) {}
    
    // Login once
    $ch = curl_init('https://merchant.pathao.com/api/v1/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['username'=>$username,'password'=>$password]),
    ]);
    $r = curl_exec($ch); $c = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $d = json_decode($r,true);
    $token = trim($d['access_token']??'');
    if (!$token) return null;
    
    // Cache for 4 hours (token actually lasts 5 days)
    $tokenJson = json_encode(['token'=>$token, 'expires'=>time()+14400]);
    try {
        $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = 'pathao_fraud_token'");
        if ($exists) $db->update('site_settings', ['setting_value'=>$tokenJson], 'setting_key = ?', ['pathao_fraud_token']);
        else $db->insert('site_settings', ['setting_key'=>'pathao_fraud_token','setting_value'=>$tokenJson,'setting_type'=>'text','setting_group'=>'courier','label'=>'Pathao Fraud Token Cache']);
    } catch (\Throwable $e) {}
    
    return $token;
}

// ═══════════════════════════════════════════════════
// STEADFAST — try API key first, then cached web scrape
// Rate limit: API endpoints often return 403/blocked
// Web scrape: fresh login = 4+ requests, very aggressive
//   Session cached to avoid re-login every phone check
// ═══════════════════════════════════════════════════
function _checkSteadfast($phone) {
    $apiResult = _steadfastApiCheck($phone);
    if ($apiResult && !isset($apiResult['error'])) return $apiResult;

    $webResult = _steadfastWebScrape($phone);
    if ($webResult && !isset($webResult['error'])) return $webResult;

    $apiErr = $apiResult['error'] ?? 'N/A';
    $webErr = $webResult['error'] ?? 'N/A';
    return ['error' => "Cross-merchant unavailable (API: $apiErr)"];
}

function _steadfastApiCheck($phone) {
    $apiKey = getSetting('steadfast_api_key','');
    $secretKey = getSetting('steadfast_secret_key','');
    if (!$apiKey || !$secretKey) return ['error'=>'API keys not set'];

    $headers = ['Api-Key: '.trim($apiKey), 'Secret-Key: '.trim($secretKey), 'Content-Type: application/json', 'Accept: application/json'];
    // Try both known working endpoints (fraud_check found by diagnostic, courier_score is documented)
    $urls = [
        'https://portal.packzy.com/api/v1/fraud_check/'.urlencode($phone),
        'https://portal.packzy.com/api/v1/courier_score/'.urlencode($phone),
    ];

    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>true]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 404 || $code === 405 || $code === 0) continue;
        if ($code === 200 && $resp) {
            $data = json_decode($resp, true);
            if (!$data) continue;
            $flat = array_merge($data, $data['data'] ?? []);
            
            // Handle total_parcels as the authoritative total (from /fraud_check/ endpoint)
            $totalParcels = intval($flat['total_parcels'] ?? 0);
            
            // Find delivered count from various possible keys
            $del = 0;
            foreach (['total_delivered','delivered','success_count','success'] as $dk) {
                if (isset($flat[$dk])) { $del = intval($flat[$dk]); break; }
            }
            // Find cancelled count
            $can = intval($flat['total_cancelled'] ?? $flat['cancelled'] ?? $flat['cancel'] ?? 0);
            
            // Use total_parcels if available (more accurate), otherwise sum delivered+cancelled
            $total = $totalParcels > 0 ? $totalParcels : ($del + $can);
            
            if ($total > 0 || $del > 0) {
                $result = ['success'=>$del, 'cancel'=>$can, 'total'=>$total, 'source'=>'steadfast_api'];
                // Carry fraud report data if present
                $fraudReports = $flat['total_fraud_reports'] ?? $flat['fraud_reports'] ?? null;
                if ($fraudReports && is_array($fraudReports) && count($fraudReports) > 0) {
                    $result['is_fraud'] = true;
                    $result['fraud_count'] = count($fraudReports);
                    $result['fraud_reports'] = $fraudReports;
                }
                return $result;
            }
        }
    }
    return ['error'=>'No API endpoint found'];
}

function _steadfastWebScrape($phone) {
    $em = getSetting('steadfast_merchant_email','') ?: getSetting('steadfast_email','');
    $pw = getSetting('steadfast_merchant_password','') ?: getSetting('steadfast_password','');
    if (!$em || !$pw) return ['error'=>'Web login not configured'];

    // Use a persistent cookie file per merchant email (session caching)
    $cf = sys_get_temp_dir().'/sf_session_'.md5($em).'.txt';
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    $bh = ["User-Agent: $ua",'Accept-Language: en-US,en;q=0.9','Sec-Ch-Ua: "Chromium";v="131"','Sec-Ch-Ua-Mobile: ?0','Sec-Ch-Ua-Platform: "Windows"'];

    // Try existing session first (skip login if cookies are fresh)
    $needLogin = true;
    if (is_file($cf) && (time() - filemtime($cf)) < 3600) { // session < 1 hour old
        // Test if session is still valid with a quick fraud check
        $xsrf = _getSteadfastXsrf($cf);
        $csrf = _getSteadfastCsrf($cf, $bh);
        if ($csrf) {
            $result = _steadfastDoFraudCheck($phone, $cf, $bh, $csrf, $xsrf);
            if ($result !== null) return $result;
        }
        // Session expired, need fresh login
    }

    // Full login flow (expensive: 4 requests)
    @unlink($cf); // Clear stale cookies
    
    $ch = curl_init('https://steadfast.com.bd/login');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEJAR=>$cf,CURLOPT_COOKIEFILE=>$cf,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_ENCODING=>'',CURLOPT_HTTPHEADER=>array_merge($bh,['Accept: text/html,*/*'])]);
    $lp = curl_exec($ch); curl_close($ch);

    $csrf = '';
    if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/', $lp, $m)) $csrf = $m[1];
    elseif (preg_match('/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/', $lp, $m)) $csrf = $m[1];
    if (!$csrf) { @unlink($cf); return ['error'=>'CSRF not found']; }

    $ch = curl_init('https://steadfast.com.bd/login');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEJAR=>$cf,CURLOPT_COOKIEFILE=>$cf,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_ENCODING=>'',
        CURLOPT_HTTPHEADER=>array_merge($bh,['Content-Type: application/x-www-form-urlencoded','Accept: text/html,*/*','Referer: https://steadfast.com.bd/login','Origin: https://steadfast.com.bd']),
        CURLOPT_POSTFIELDS=>http_build_query(['_token'=>$csrf,'email'=>$em,'password'=>$pw]),
    ]);
    $lr = curl_exec($ch); $lu = curl_getinfo($ch,CURLINFO_EFFECTIVE_URL); curl_close($ch);

    if (strpos($lu,'/login')!==false && strpos($lr,'credentials')!==false) { @unlink($cf); return ['error'=>'Wrong password']; }

    $csrf2=$csrf;
    if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/', $lr, $m)) $csrf2=$m[1];

    $ch = curl_init('https://steadfast.com.bd/user/frauds/check');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEJAR=>$cf,CURLOPT_COOKIEFILE=>$cf,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_ENCODING=>'',
        CURLOPT_HTTPHEADER=>array_merge($bh,['Accept: text/html,*/*','Referer: https://steadfast.com.bd/user/dashboard']),
    ]);
    $fp = curl_exec($ch); curl_close($ch);
    if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/', $fp, $m)) $csrf2=$m[1];

    $xsrf = _getSteadfastXsrf($cf);
    $result = _steadfastDoFraudCheck($phone, $cf, $bh, $csrf2, $xsrf);
    if ($result !== null) return $result;

    return ['error'=>'Steadfast fraud check failed (HTTP 403) — access denied'];
}

// Helper: extract XSRF token from cookie file
function _getSteadfastXsrf($cookieFile) {
    try {
        if (is_file($cookieFile)) {
            $txt = file_get_contents($cookieFile);
            if ($txt && preg_match('/\tXSRF-TOKEN\t([^\n\r]+)/', $txt, $m)) {
                return urldecode(trim($m[1]));
            }
        }
    } catch(\Throwable $e) {}
    return '';
}

// Helper: get CSRF from fraud check page using existing session
function _getSteadfastCsrf($cookieFile, $bh) {
    $ch = curl_init('https://steadfast.com.bd/user/frauds/check');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_ENCODING=>'',
        CURLOPT_HTTPHEADER=>array_merge($bh,['Accept: text/html,*/*']),
    ]);
    $page = curl_exec($ch); $url = curl_getinfo($ch,CURLINFO_EFFECTIVE_URL); curl_close($ch);
    // If redirected to login, session expired
    if (strpos($url, '/login') !== false) return null;
    if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/', $page, $m)) return $m[1];
    return null;
}

// Helper: perform the actual fraud check AJAX call
function _steadfastDoFraudCheck($phone, $cookieFile, $bh, $csrf, $xsrf) {
    $phoneVars = array_values(array_unique([$phone, ltrim($phone,'0'), '88'.ltrim($phone,'0')]));

    foreach ($phoneVars as $pv) {
        $hdr = array_merge($bh,[
            'Accept: application/json, text/html, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
            'X-CSRF-TOKEN: '.$csrf,
            'Referer: https://steadfast.com.bd/user/frauds/check',
            'Sec-Fetch-Dest: empty','Sec-Fetch-Mode: cors','Sec-Fetch-Site: same-origin'
        ]);
        if ($xsrf) $hdr[] = 'X-XSRF-TOKEN: '.$xsrf;

        $ch = curl_init('https://steadfast.com.bd/user/frauds/check/'.urlencode($pv));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_ENCODING=>'',
            CURLOPT_HTTPHEADER=>$hdr,
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code===200 && $resp) {
            $data = json_decode($resp, true);
            if ($data) {
                $del = intval($data['total_delivered'] ?? $data['delivered'] ?? $data['success'] ?? 0);
                $can = intval($data['total_cancelled'] ?? $data['cancelled'] ?? $data['cancel'] ?? 0);
                $totalParcels = intval($data['total_parcels'] ?? 0);
                $total = $totalParcels > 0 ? $totalParcels : ($del + $can);
                $result = ['success'=>$del,'cancel'=>$can,'total'=>$total,'source'=>'steadfast_web'];
                
                // Capture fraud reports if present
                $fraudReports = $data['fraud_reports'] ?? $data['reports'] ?? $data['frauds'] ?? null;
                if ($fraudReports && is_array($fraudReports) && count($fraudReports) > 0) {
                    $result['fraud_reports'] = $fraudReports;
                    $result['fraud_count'] = count($fraudReports);
                    $result['is_fraud'] = true;
                }
                // Check other fraud flags
                if (isset($data['is_fraud']) && $data['is_fraud']) $result['is_fraud'] = true;
                if (isset($data['fraud']) && $data['fraud']) $result['is_fraud'] = true;
                if (isset($data['fraud_count']) && intval($data['fraud_count']) > 0) {
                    $result['fraud_count'] = intval($data['fraud_count']);
                    $result['is_fraud'] = true;
                }
                
                if ($del+$can > 0 || !empty($result['is_fraud'])) return $result;
            }
            // If response is HTML, try to parse it for fraud data
            if (strpos($resp, '<') !== false) {
                $parsed = _parseSteadfastFraudHtml($resp);
                if ($parsed) return $parsed;
            }
        }
    }

    // Try POST fallback
    $hdr2 = array_merge($bh,['Accept: application/json, text/html, */*; q=0.01','Content-Type: application/x-www-form-urlencoded','X-Requested-With: XMLHttpRequest','X-CSRF-TOKEN: '.$csrf,'Referer: https://steadfast.com.bd/user/frauds/check','Sec-Fetch-Dest: empty','Sec-Fetch-Mode: cors','Sec-Fetch-Site: same-origin']);
    if ($xsrf) $hdr2[] = 'X-XSRF-TOKEN: '.$xsrf;

    $ch = curl_init('https://steadfast.com.bd/user/frauds/check');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>10,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_ENCODING=>'',
        CURLOPT_HTTPHEADER=>$hdr2,
        CURLOPT_POSTFIELDS=>http_build_query(['_token'=>$csrf,'phone'=>$phone]),
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

    if ($code===200 && $resp) {
        $data = json_decode($resp, true);
        if ($data) {
            $del = intval($data['total_delivered'] ?? $data['delivered'] ?? $data['success'] ?? 0);
            $can = intval($data['total_cancelled'] ?? $data['cancelled'] ?? $data['cancel'] ?? 0);
            $totalParcels = intval($data['total_parcels'] ?? 0);
            $total = $totalParcels > 0 ? $totalParcels : ($del + $can);
            $result = ['success'=>$del,'cancel'=>$can,'total'=>$total,'source'=>'steadfast_web'];
            $fraudReports = $data['fraud_reports'] ?? $data['reports'] ?? $data['frauds'] ?? null;
            if ($fraudReports && is_array($fraudReports) && count($fraudReports) > 0) {
                $result['fraud_reports'] = $fraudReports;
                $result['fraud_count'] = count($fraudReports);
                $result['is_fraud'] = true;
            }
            if (isset($data['is_fraud']) && $data['is_fraud']) $result['is_fraud'] = true;
            if (isset($data['fraud']) && $data['fraud']) $result['is_fraud'] = true;
            if ($total > 0 || !empty($result['is_fraud'])) return $result;
        }
        if (strpos($resp, '<') !== false) {
            $parsed = _parseSteadfastFraudHtml($resp);
            if ($parsed) return $parsed;
        }
    }

    return null; // null = no result (not error, caller decides)
}

// Helper: parse fraud data from HTML response (fallback when Steadfast returns HTML)
function _parseSteadfastFraudHtml($html) {
    $result = ['success'=>0,'cancel'=>0,'total'=>0,'source'=>'steadfast_web_html'];
    $found = false;
    
    // Look for delivery count patterns in HTML
    if (preg_match('/(?:total[_\s]?deliver(?:ed|y))[^\d]*(\d+)/i', $html, $m)) {
        $result['success'] = intval($m[1]); $found = true;
    }
    if (preg_match('/(?:total[_\s]?cancel(?:led)?)[^\d]*(\d+)/i', $html, $m)) {
        $result['cancel'] = intval($m[1]); $found = true;
    }
    if (preg_match('/(?:total[_\s]?(?:order|parcel)s?)[^\d]*(\d+)/i', $html, $m)) {
        $result['total'] = intval($m[1]); $found = true;
    }
    
    // Check for fraud report section in HTML
    if (preg_match('/fraud[_\s]?report|reported[_\s]?fraud|fraud[_\s]?count/i', $html)) {
        $result['is_fraud'] = true;
        if (preg_match('/fraud[_\s]?(?:report|count)[^\d]*(\d+)/i', $html, $m)) {
            $result['fraud_count'] = intval($m[1]);
        }
    }
    
    if (!$found) return null;
    if ($result['total'] === 0) $result['total'] = $result['success'] + $result['cancel'];
    return $result;
}

// ═══════════════════════════════════════════════════
// REDX API — cross-merchant data (with token caching)
// ═══════════════════════════════════════════════════
function _getRedxCachedToken() {
    $db = Database::getInstance();
    
    // Check for cached token (valid for 12 hours)
    try {
        $cached = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'redx_fraud_token'");
        if ($cached && !empty($cached['setting_value'])) {
            $tokenData = json_decode($cached['setting_value'], true);
            if ($tokenData && !empty($tokenData['token']) && ($tokenData['expires'] ?? 0) > time()) {
                return $tokenData['token'];
            }
        }
    } catch (\Throwable $e) {}
    
    // Token expired or missing — login once
    $rp  = getSetting('redx_phone','');
    $rpw = getSetting('redx_password','');
    if (!$rp || !$rpw) return null;
    
    $cleanRp = preg_replace('/[^0-9]/', '', $rp);
    if (substr($cleanRp, 0, 2) === '88') $cleanRp = substr($cleanRp, 2);
    if ($cleanRp[0] !== '0') $cleanRp = '0' . $cleanRp;
    $loginPhone = '88' . $cleanRp;
    
    $ch = curl_init('https://api.redx.com.bd/v4/auth/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['phone'=>$loginPhone,'password'=>$rpw]),
    ]);
    $r = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    
    if ($httpCode === 429) return '__rate_limited__';
    
    $d = json_decode($r, true);
    $token = $d['data']['accessToken'] ?? '';
    if (!$token) return null;
    
    // Cache token for 12 hours
    $tokenJson = json_encode(['token' => $token, 'expires' => time() + 43200]);
    try {
        $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = 'redx_fraud_token'");
        if ($exists) {
            $db->update('site_settings', ['setting_value' => $tokenJson], 'setting_key = ?', ['redx_fraud_token']);
        } else {
            $db->insert('site_settings', [
                'setting_key' => 'redx_fraud_token',
                'setting_value' => $tokenJson,
                'setting_type' => 'text',
                'setting_group' => 'courier',
                'label' => 'RedX Fraud Token Cache',
            ]);
        }
    } catch (\Throwable $e) {}
    
    return $token;
}

function _checkRedx($phone) {
    $rp  = getSetting('redx_phone','');
    $rpw = getSetting('redx_password','');
    if (!$rp || !$rpw) return ['error'=>'RedX credentials not configured'];
    
    $token = _getRedxCachedToken();
    if ($token === '__rate_limited__') return ['error'=>'RedX rate limited (429) — wait 10 min'];
    if (!$token) return ['error'=>'RedX login failed — check credentials'];
    
    // Query phone: 8801XXXXXXXXX format
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($cleanPhone, 0, 2) === '88') $cleanPhone = substr($cleanPhone, 2);
    if ($cleanPhone[0] !== '0') $cleanPhone = '0' . $cleanPhone;
    $queryPhone = '88' . $cleanPhone;
    
    $ch = curl_init('https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=' . urlencode($queryPhone));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$token],
    ]);
    $r = curl_exec($ch); $c = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    
    // If token expired (401), clear cache and retry once
    if ($c === 401) {
        try {
            $db = Database::getInstance();
            $db->update('site_settings', ['setting_value' => ''], 'setting_key = ?', ['redx_fraud_token']);
        } catch (\Throwable $e) {}
        
        $token = _getRedxCachedToken();
        if (!$token || $token === '__rate_limited__') return ['error'=>'RedX re-login failed'];
        
        $ch = curl_init('https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=' . urlencode($queryPhone));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$token],
        ]);
        $r = curl_exec($ch); $c = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    }
    
    $res = json_decode($r, true);
    if (!$res || $c >= 400) return ['error'=>"RedX HTTP $c"];
    
    $data = $res['data'] ?? $res;
    $delivered = intval($data['deliveredParcels'] ?? 0);
    $total     = intval($data['totalParcels'] ?? 0);
    return ['success'=>$delivered,'cancel'=>$total-$delivered,'total'=>$total,'source'=>'redx_api'];
}

// ═══════════════════════════════════════════════════════════
// RESULT CACHE — per-phone caching to avoid redundant API calls
// ═══════════════════════════════════════════════════════════
// Stores fraud check results in site_settings as JSON
// Same phone checked again within TTL returns cached data instantly
// No login, no API call, no rate limit risk
//
// ┌─────────────────────────────────────────────────────────┐
// │           COURIER API RATE LIMITS REFERENCE             │
// ├──────────────┬──────────────────────────────────────────┤
// │ Pathao       │ OAuth token: 5 day lifetime              │
// │              │ Login: ~10 req/min (undocumented)         │
// │              │ API: ~60 req/min (community estimate)     │
// │              │ → Token cached 4 hours                    │
// ├──────────────┼──────────────────────────────────────────┤
// │ Steadfast    │ API fraud check: BLOCKED (403)            │
// │              │ Web scrape: session-based, aggressive     │
// │              │   CSRF + cookie rotation                  │
// │              │ → Recommend: cache results 1 hour         │
// │              │ → Currently blocked, fallback to own DB   │
// ├──────────────┼──────────────────────────────────────────┤
// │ RedX         │ Login (v4/auth/login): ~3-5 req/10min    │
// │              │   VERY strict, 429 after rapid logins     │
// │              │ Data query: ~30 req/min (estimated)       │
// │              │ → Token cached 12 hours                   │
// ├──────────────┼──────────────────────────────────────────┤
// │ ALL          │ Per-phone result cache: 30 min TTL        │
// │              │ Same phone = instant response from cache  │
// └──────────────┴──────────────────────────────────────────┘

function _getResultCache($db, $key, $ttl) {
    try {
        $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
        if ($row && !empty($row['setting_value'])) {
            $data = json_decode($row['setting_value'], true);
            if ($data && isset($data['expires']) && $data['expires'] > time()) {
                return $data['results'] ?? null;
            }
        }
    } catch (\Throwable $e) {}
    return null;
}

function _setResultCache($db, $key, $results) {
    $json = json_encode([
        'results' => $results,
        'expires' => time() + 1800, // 30 min
        'cached_at' => date('Y-m-d H:i:s'),
    ]);
    try {
        $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key]);
        if ($exists) {
            $db->update('site_settings', ['setting_value' => $json], 'setting_key = ?', [$key]);
        } else {
            $db->insert('site_settings', [
                'setting_key'   => $key,
                'setting_value' => $json,
                'setting_type'  => 'text',
                'setting_group' => 'courier',
                'label'         => 'Fraud Check Cache',
            ]);
        }
    } catch (\Throwable $e) {}
}

// ═══════════════════════════════════════════════════════════
// CLEANUP — Remove expired fraud caches (call periodically)
// ═══════════════════════════════════════════════════════════
function _cleanupFraudCache($db) {
    try {
        $rows = $db->fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'fraud_cache_%'");
        $deleted = 0;
        foreach ($rows as $row) {
            $data = json_decode($row['setting_value'] ?? '', true);
            if (!$data || ($data['expires'] ?? 0) < time()) {
                $db->query("DELETE FROM site_settings WHERE setting_key = ?", [$row['setting_key']]);
                $deleted++;
            }
        }
        return $deleted;
    } catch (\Throwable $e) { return 0; }
}
