<?php
/**
 * API Connection Diagnostic v10.1 — Tests fraud check pipeline
 * Upload to: admin/pages/api-diagnostic.php
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireAdmin();
refreshAdminPermissions();
requirePermission('settings', 'view');
$db = Database::getInstance();
$phone = preg_replace('/[^0-9]/', '', $_GET['phone'] ?? '');
$phoneLike = $phone ? '%' . substr($phone, -10) . '%' : '';
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html><head><title>API Diagnostic</title>
<style>body{font-family:system-ui;max-width:960px;margin:20px auto;padding:0 20px;background:#f8f9fa}
.ok{background:#d4edda;border:1px solid #c3e6cb;padding:12px;border-radius:8px;margin:8px 0}
.err{background:#f8d7da;border:1px solid #f5c6cb;padding:12px;border-radius:8px;margin:8px 0}
.info{background:#d1ecf1;border:1px solid #bee5eb;padding:12px;border-radius:8px;margin:8px 0}
.warn{background:#fff3cd;border:1px solid #ffc107;padding:12px;border-radius:8px;margin:8px 0}
pre{background:#1a1a2e;color:#e0e0e0;padding:15px;border-radius:8px;overflow-x:auto;font-size:12px;max-height:300px}
h2{margin-top:30px;padding-bottom:8px;border-bottom:2px solid #dee2e6}
code{background:#e9ecef;padding:2px 6px;border-radius:4px;font-size:13px}
.g{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:700px){.g{grid-template-columns:1fr}}
.badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600}
.badge-green{background:#d4edda;color:#155724}.badge-red{background:#f8d7da;color:#721c24}
.badge-yellow{background:#fff3cd;color:#856404}.badge-blue{background:#cce5ff;color:#004085}
</style></head><body>
<h1>🔧 Courier Fraud Check Diagnostic <span class="badge badge-blue">v12</span></h1>
<form method="get" style="margin:15px 0;display:flex;gap:10px">
<input type="text" name="phone" value="<?=htmlspecialchars($phone)?>" placeholder="01XXXXXXXXX" style="padding:10px;border:2px solid #dee2e6;border-radius:8px;font-size:16px;font-family:monospace;flex:1">
<button type="submit" style="padding:10px 30px;background:#007bff;color:#fff;border:none;border-radius:8px;font-size:16px;cursor:pointer">🔍 Test</button></form>

<?php
// ═══════════════════════════════════════
// 1. SETTINGS CHECK
// ═══════════════════════════════════════
echo '<h2>1️⃣ Settings</h2><div class="g">';

// Pathao — uses pathao_username / pathao_password
$pu = getSetting('pathao_username',''); $pp = getSetting('pathao_password','');
echo '<div class="'.($pu?'ok':'err').'">'.($pu?'✅':'❌').' Pathao Email*: '.($pu?'<code>'.htmlspecialchars(substr($pu,0,3)).'•••</code>':'NOT SET').'</div>';
echo '<div class="'.($pp?'ok':'err').'">'.($pp?'✅':'❌').' Pathao Password*: '.($pp?'<code>•••</code>':'NOT SET').'</div>';

$pci = getSetting('pathao_client_id',''); $pcs = getSetting('pathao_client_secret','');
echo '<div class="'.($pci?'ok':'info').'">'.($pci?'✅':'ℹ️').' Pathao Client ID: '.($pci?'<code>'.htmlspecialchars(substr($pci,0,5)).'•••</code>':'not set (optional)').'</div>';
echo '<div class="'.($pcs?'ok':'info').'">'.($pcs?'✅':'ℹ️').' Pathao Client Secret: '.($pcs?'<code>•••</code>':'not set (optional)').'</div>';

$te = getSetting('pathao_token_expiry','');
if ($te) { $exp=intval($te); echo '<div class="'.($exp>time()?'ok':'warn').'">✅ Token Expiry: '.date('M d H:i',$exp).($exp>time()?' ✓':' ⚠EXPIRED').'</div>'; }
else echo '<div class="info">ℹ️ Token Expiry: not set</div>';

// Steadfast — check BOTH key variants
$se1 = getSetting('steadfast_merchant_email',''); $sp1 = getSetting('steadfast_merchant_password','');
$se2 = getSetting('steadfast_email','');          $sp2 = getSetting('steadfast_password','');
$sfEmail = $se1 ?: $se2; $sfPass = $sp1 ?: $sp2;

if ($sfEmail) {
    $src = $se1 ? 'steadfast_merchant_email' : 'steadfast_email';
    echo '<div class="ok">✅ Steadfast Email*: <code>'.htmlspecialchars(substr($sfEmail,0,5)).'•••</code> <span class="badge badge-blue">key: '.$src.'</span></div>';
} else {
    echo '<div class="err">❌ Steadfast Email*: NOT SET <small>(checked both steadfast_merchant_email AND steadfast_email)</small></div>';
}
if ($sfPass) {
    $src = $sp1 ? 'steadfast_merchant_password' : 'steadfast_password';
    echo '<div class="ok">✅ Steadfast Password*: <code>•••</code> <span class="badge badge-blue">key: '.$src.'</span></div>';
} else {
    echo '<div class="err">❌ Steadfast Password*: NOT SET</div>';
}

$sak = getSetting('steadfast_api_key','');
echo '<div class="'.($sak?'ok':'info').'">'.($sak?'✅':'ℹ️').' Steadfast API Key: '.($sak?'<code>'.htmlspecialchars(substr($sak,0,4)).'•••</code>':'not set').'</div>';

$rp = getSetting('redx_phone',''); $rpw = getSetting('redx_password','');
echo '<div class="'.($rp?'ok':'info').'">'.($rp?'✅':'ℹ️').' RedX Phone: '.($rp?'<code>'.htmlspecialchars(substr($rp,0,4)).'•••</code>':'NOT SET').'</div>';
echo '<div class="'.($rpw?'ok':'info').'">'.($rpw?'✅':'ℹ️').' RedX Password: '.($rpw?'<code>•••</code>':'NOT SET').'</div>';

echo '</div>';

if (!$phone) { echo '<div class="warn" style="margin-top:30px;text-align:center;font-size:18px">👆 Enter a phone number to test</div></body></html>'; exit; }

// ═══════════════════════════════════════
// 2. LOCAL DB CHECK
// ═══════════════════════════════════════
echo '<h2>2️⃣ Local Database</h2>';
try {
    $orders=$db->fetchAll("SELECT id,order_number,order_status,courier_name,shipping_method,courier_consignment_id,total,created_at FROM orders WHERE customer_phone LIKE ? ORDER BY created_at DESC LIMIT 10",[$phoneLike]);
    echo $orders?'<div class="ok">✅ '.count($orders).' orders found</div>':'<div class="info">📋 No local orders for this phone</div>';
    if($orders){echo '<pre>';foreach($orders as $i=>$o)echo($i+1).". #{$o['order_number']} | {$o['order_status']} | ".($o['courier_name']?:$o['shipping_method']?:'—')." | ৳{$o['total']}\n";echo '</pre>';}
} catch(\Throwable $e){echo '<div class="err">❌ DB: '.$e->getMessage().'</div>';}

// ═══════════════════════════════════════
// 2b. PER-COURIER DB BREAKDOWN (your own orders)
// ═══════════════════════════════════════
echo '<h2>2️⃣b Per-Courier Stats (Your Database)</h2>';
echo '<div class="info">These are REAL counts from your own orders table — always accurate</div>';
$courierPatterns = ['Pathao'=>['pathao'], 'Steadfast'=>['steadfast'], 'RedX'=>['redx','red-x','red x']];
echo '<div class="g">';
foreach ($courierPatterns as $label => $patterns) {
    $conditions = []; $params = [$phoneLike];
    foreach ($patterns as $pat) {
        $conditions[] = "LOWER(COALESCE(courier_name,'')) LIKE ?";
        $conditions[] = "LOWER(COALESCE(shipping_method,'')) LIKE ?";
        $params[] = '%'.$pat.'%'; $params[] = '%'.$pat.'%';
    }
    $where = implode(' OR ', $conditions);
    try {
        $cr = $db->fetch("SELECT COUNT(*) as total, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered, SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled FROM orders WHERE customer_phone LIKE ? AND order_status NOT IN ('incomplete') AND ($where)", $params);
        $t = intval($cr['total']??0); $d = intval($cr['delivered']??0); $c = intval($cr['cancelled']??0);
        $rate = $t > 0 ? round(($d/$t)*100) : 0;
        $cls = $t > 0 ? ($rate >= 70 ? 'ok' : ($rate >= 40 ? 'warn' : 'err')) : 'info';
        echo '<div class="'.$cls.'">'.$label.': <b>'.$d.'</b> delivered / <b>'.$c.'</b> cancelled / <b>'.$t.'</b> total'.($t>0?' — <b>'.$rate.'%</b> success rate':'').'</div>';
    } catch (\Throwable $e) {
        echo '<div class="err">'.$label.': DB error — '.$e->getMessage().'</div>';
    }
}
echo '</div>';

require_once __DIR__.'/../../api/pathao.php';
$p=new PathaoAPI();

// ═══════════════════════════════════════
// 3. PATHAO MERCHANT PORTAL
// ═══════════════════════════════════════
echo '<h2>3️⃣ Pathao Merchant Portal</h2>';
echo '<div class="info">merchant.pathao.com/api/v1/login → /api/v1/user/success</div>';
try {
    $t=microtime(true); $r=$p->fraudCheckPathao($phone); $ms=round((microtime(true)-$t)*1000);
    if ($r && !isset($r['error'])) {
        $showCount = $r['show_count'] ?? true;
        $rating = $r['customer_rating'] ?? null;
        
        if ($showCount && intval($r['total_delivery']??0) > 0) {
            echo '<div class="ok">✅ SUCCESS ('.$ms.'ms) — Delivered: '.($r['successful_delivery']??0).' / Total: '.($r['total_delivery']??0).'</div>';
        } else {
            // v2 mode: rating only from API
            $ratingColors = ['excellent_customer'=>'badge-green','good_customer'=>'badge-green','moderate_customer'=>'badge-yellow','risky_customer'=>'badge-red','new_customer'=>'badge-blue'];
            $ratingLabels = ['excellent_customer'=>'⭐ Excellent','good_customer'=>'✅ Good','moderate_customer'=>'⚠️ Moderate','risky_customer'=>'🚫 Risky','new_customer'=>'🆕 New'];
            $bc = $ratingColors[$rating] ?? 'badge-blue';
            $rl = $ratingLabels[$rating] ?? $rating;
            echo '<div class="ok">✅ SUCCESS ('.$ms.'ms) — <span class="badge '.$bc.'">'.$rl.'</span></div>';
            echo '<div class="warn">💡 Pathao API returned <code>show_count: false</code> — counts are hidden by Pathao.<br>';
            echo '<b>Your own DB counts (shown in section 2b above) are used instead.</b></div>';
        }
        echo '<pre>'.htmlspecialchars(json_encode($r, JSON_PRETTY_PRINT)).'</pre>';
    } else {
        echo '<div class="err">❌ FAILED ('.$ms.'ms): '.htmlspecialchars($r['error']??'unknown').'</div>';
        if(isset($r['raw']))echo '<pre>'.htmlspecialchars(json_encode($r,JSON_PRETTY_PRINT)).'</pre>';
    }
} catch(\Throwable $e){echo '<div class="err">❌ Exception: '.$e->getMessage().'</div>';}

// ═══════════════════════════════════════
// 4. STEADFAST — TRY API ENDPOINT FIRST, THEN WEB SCRAPE
// ═══════════════════════════════════════
echo '<h2>4️⃣ Steadfast Courier</h2>';

// 4a. Try API endpoint (using existing API key/secret)
echo '<h3 style="color:#666;font-size:14px">Strategy A: API Endpoint (using your API key)</h3>';
$sfApiKey = getSetting('steadfast_api_key','');
$sfSecKey = getSetting('steadfast_secret_key','');
if ($sfApiKey && $sfSecKey) {
    echo '<div class="info">Trying courier_score endpoints on portal.packzy.com</div>';
    $sfHeaders = ['Api-Key: '.$sfApiKey, 'Secret-Key: '.$sfSecKey, 'Content-Type: application/json', 'Accept: application/json'];
    $sfBases = ['https://portal.packzy.com/api/v1'];
    $sfEndpoints = ['/courier_score/','/fraud_check/','/courier-score/','/fraud-check/','/check_score/','/score/'];
    $sfFound = false;
    foreach ($sfBases as $sfBase) {
        if ($sfFound) break;
        foreach ($sfEndpoints as $sfEp) {
            $sfUrl = $sfBase . $sfEp . urlencode($phone);
            $t = microtime(true);
            $ch = curl_init($sfUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>6,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>$sfHeaders,CURLOPT_FOLLOWLOCATION=>true]);
            $sfResp = curl_exec($ch);
            $sfCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $sfMs = round((microtime(true)-$t)*1000);

            if ($sfCode === 200 && $sfResp) {
                $sfData = json_decode($sfResp, true);
                if ($sfData) {
                    echo '<div class="ok">✅ FOUND ENDPOINT ('.$sfMs.'ms): '.htmlspecialchars($sfEp).' on '.parse_url($sfBase,PHP_URL_HOST).'</div>';
                    echo '<pre>'.htmlspecialchars(json_encode($sfData,JSON_PRETTY_PRINT)).'</pre>';
                    $sfFound = true; break;
                }
            }
            if ($sfCode !== 404 && $sfCode !== 0) {
                echo '<div class="warn">'.htmlspecialchars($sfEp).' → HTTP '.$sfCode.' ('.$sfMs.'ms)</div>';
            }
        }
    }
    if (!$sfFound) echo '<div class="err">❌ No working API endpoint found. Steadfast may not expose fraud check via API.</div>';
} else {
    echo '<div class="warn">⚠️ Steadfast API key/secret not configured — skipping API approach</div>';
}

// 4b. Web scraping fallback
echo '<h3 style="color:#666;font-size:14px;margin-top:12px">Strategy B: Web Scraping (login to portal)</h3>';
echo '<div class="info">steadfast.com.bd/login → /user/frauds/check/{phone}</div>';
try {
    $t=microtime(true); $r=$p->fraudCheckSteadfast($phone); $ms=round((microtime(true)-$t)*1000);
    if ($r && !isset($r['error'])) {
        echo '<div class="ok">✅ SUCCESS ('.$ms.'ms) — Delivered: '.($r['total_delivered']??0).' / Cancelled: '.($r['total_cancelled']??0).' / Total: '.($r['total']??0).'</div>';
        echo '<pre>'.htmlspecialchars(json_encode($r,JSON_PRETTY_PRINT)).'</pre>';
    } else {
        $err = $r['error'] ?? 'unknown';
        echo '<div class="err">❌ FAILED ('.$ms.'ms): '.htmlspecialchars($err).'</div>';
        if (strpos($err, '403') !== false) {
            echo '<div class="warn">💡 <b>Steadfast blocks server-to-server requests (HTTP 403).</b><br>';
            echo '• Their WAF/firewall rejects requests from server IPs<br>';
            echo '• All known PHP packages (Laravel, WP plugins) face this same issue<br>';
            echo '• <b>Fix options:</b> Contact Steadfast support to whitelist your server IP ('.htmlspecialchars($_SERVER['SERVER_ADDR']??'unknown').')<br>';
            echo '• Or ask Steadfast about their "Courier Score" API endpoint (used in their official WP plugin)</div>';
        }
    }
} catch(\Throwable $e){echo '<div class="err">❌ Exception: '.$e->getMessage().'</div>';}

// ═══════════════════════════════════════
// 5. REDX API
// ═══════════════════════════════════════
echo '<h2>5️⃣ RedX API</h2>';
echo '<div class="info">api.redx.com.bd/v4/auth/login → customer-success-return-rate</div>';
try {
    $t=microtime(true); $r=$p->fraudCheckRedx($phone); $ms=round((microtime(true)-$t)*1000);
    if ($r && !isset($r['error'])) {
        echo '<div class="ok">✅ SUCCESS ('.$ms.'ms) — Delivered: '.($r['deliveredParcels']??0).' / Total: '.($r['totalParcels']??0).'</div>';
        echo '<pre>'.htmlspecialchars(json_encode($r,JSON_PRETTY_PRINT)).'</pre>';
    } else {
        echo '<div class="err">❌ FAILED ('.$ms.'ms): '.htmlspecialchars($r['error']??'unknown').'</div>';
    }
} catch(\Throwable $e){echo '<div class="err">❌ Exception: '.$e->getMessage().'</div>';}

// ═══════════════════════════════════════
// 6. FULL PROFILE
// ═══════════════════════════════════════
echo '<h2>6️⃣ Full Customer Profile</h2>';
try {
    $t=microtime(true); $pr=$p->getCustomerProfile($phone); $ms=round((microtime(true)-$t)*1000);
    echo '<div class="ok">✅ Profile built ('.$ms.'ms)</div>';
    
    $riskColors = ['low'=>'badge-green','medium'=>'badge-yellow','high'=>'badge-red','new'=>'badge-blue'];
    $rc = $riskColors[$pr['risk_level']??''] ?? 'badge-blue';
    
    echo '<div class="g">';
    echo '<div class="ok">Orders: <b>'.$pr['total_orders'].'</b></div>';
    echo '<div class="ok">Delivered: <b>'.$pr['delivered'].'</b></div>';
    echo '<div class="ok">Cancelled: <b>'.$pr['cancelled'].'</b></div>';
    echo '<div class="ok">Rate: <b>'.$pr['success_rate'].'%</b></div>';
    echo '<div class="ok">Risk: <span class="badge '.$rc.'"><b>'.$pr['risk_level'].'</b></span></div>';
    echo '<div class="ok">Label: <b>'.$pr['risk_label'].'</b></div>';
    echo '</div>';
    
    // Pathao rating highlight
    if (!empty($pr['pathao_rating']['customer_rating'])) {
        $cr = $pr['pathao_rating']['customer_rating'];
        $ratingColors2 = ['excellent_customer'=>'badge-green','good_customer'=>'badge-green','moderate_customer'=>'badge-yellow','risky_customer'=>'badge-red'];
        $ratingLabels2 = ['excellent_customer'=>'⭐ Excellent Customer','good_customer'=>'✅ Good Customer','moderate_customer'=>'⚠️ Moderate','risky_customer'=>'🚫 Risky Customer'];
        $bc2 = $ratingColors2[$cr] ?? 'badge-blue';
        $rl2 = $ratingLabels2[$cr] ?? $cr;
        echo '<div class="ok" style="text-align:center;font-size:16px">Pathao Rating: <span class="badge '.$bc2.'" style="font-size:14px">'.$rl2.'</span>';
        if (($pr['pathao_rating']['show_count'] ?? true) === false) {
            echo '<br><small style="color:#666">v2 mode — delivery counts are hidden by Pathao, only rating available</small>';
        }
        echo '</div>';
    }
    
    // API notes
    if (!empty($pr['api_notes'])) {
        echo '<div class="info"><b>📡 API Notes:</b><br>';
        foreach ($pr['api_notes'] as $n) {
            $bad = stripos($n,'error')!==false||stripos($n,'fail')!==false||stripos($n,'not configured')!==false;
            echo '<span style="color:'.($bad?'#dc3545':'#28a745').'">• '.htmlspecialchars($n).'</span><br>';
        }
        echo '</div>';
    }
    
    // Courier cards
    if (!empty($pr['couriers'])) {
        echo '<div class="g">';
        foreach ($pr['couriers'] as $name => $c) {
            $dt = $c['data_type'] ?? 'local';
            $badge = '';
            if ($dt === 'rating') $badge = ' <span class="badge badge-blue">Rating Only</span>';
            elseif ($dt === 'api') $badge = ' <span class="badge badge-green">✓API</span>';
            elseif ($c['api_data'] ?? false) $badge = ' <span class="badge badge-green">✓API</span>';
            
            echo '<div class="info"><b>'.$name.':</b> ';
            if ($dt === 'rating') {
                echo 'Rating: '.($c['pathao_rating']??'—').' | Rate: '.$c['rate'].'%'.$badge;
            } else {
                echo 'Total='.$c['total'].' Success='.$c['success'].' Cancel='.$c['cancelled'].' Rate='.$c['rate'].'%'.$badge;
            }
            echo '</div>';
        }
        echo '</div>';
    }
    
    echo '<details><summary style="cursor:pointer;color:#007bff;margin:10px 0">📋 Full JSON</summary><pre>'.htmlspecialchars(json_encode($pr,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details>';
} catch(\Throwable $e){echo '<div class="err">❌ '.$e->getMessage().'</div>';}

echo '<hr><p style="color:#888;text-align:center">Done — <a href="courier.php?tab=customer_check">Back to Customer Verify</a></p></body></html>';
