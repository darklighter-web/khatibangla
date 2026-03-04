<?php
/**
 * Courier Lookup API — Fetches REAL data from:
 *   1) Our own DB (per-courier stats)
 *   2) Pathao / Steadfast APIs (live courier status via consignment IDs)
 *
 * GET /admin/api/courier-lookup.php?phone=01XXXXXXXXX
 * 
 * Groups orders by courier using BOTH courier_name and shipping_method columns
 * Checks pathao_consignment_id for legacy Pathao orders
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();

// Normalize phone to Bangladeshi local format: 01XXXXXXXXX
$phone = preg_replace('/[^0-9]/', '', $_GET['phone'] ?? '');
if (substr($phone, 0, 2) === '88') $phone = substr($phone, 2);
if (strlen($phone) === 10 && ($phone[0] ?? '') !== '0') $phone = '0' . $phone;

if (strlen($phone) < 10) {
    echo json_encode(['error' => 'Invalid phone number']);
    exit;
}

$phoneLike = '%' . substr($phone, -10) . '%';

/* ─── 1. Our DB orders ─── */
$orders = $db->fetchAll(
    "SELECT id, order_number, order_status, courier_name, shipping_method, 
            courier_tracking_id, courier_consignment_id, pathao_consignment_id,
            courier_status, total, created_at
     FROM orders
     WHERE customer_phone LIKE ?
       AND order_status NOT IN ('incomplete', 'pending')
     ORDER BY created_at DESC",
    [$phoneLike]
);

/* ─── Group orders by courier (check BOTH courier_name and shipping_method) ─── */
$courierOrders = ['Pathao' => [], 'Steadfast' => [], 'RedX' => [], 'Other' => []];
foreach ($orders as $o) {
    $cn = strtolower($o['courier_name'] ?? '');
    $sm = strtolower($o['shipping_method'] ?? '');
    $combined = $cn . ' ' . $sm;
    
    if (strpos($combined, 'pathao') !== false)       $courierOrders['Pathao'][] = $o;
    elseif (strpos($combined, 'steadfast') !== false) $courierOrders['Steadfast'][] = $o;
    elseif (strpos($combined, 'redx') !== false)     $courierOrders['RedX'][] = $o;
    else                                              $courierOrders['Other'][] = $o;
}

/* ─── Status mappings ─── */
$pathaoDelivered  = ['Delivered', 'Payment_Invoice'];
$pathaoReturn     = ['Return', 'Returned', 'Return_Ongoing', 'Exchange'];
$pathaoCancelled  = ['Cancelled'];
$sfDelivered      = ['delivered', 'delivered_approval_pending'];
$sfCancelled      = ['cancelled', 'cancelled_approval_pending'];

/* ─── Load courier API classes ─── */
$pathaoApi = null; $sfApi = null;
$pf = __DIR__ . '/../../api/pathao.php';
$sf = __DIR__ . '/../../api/steadfast.php';
if (file_exists($pf)) { try { require_once $pf; $pathaoApi = new PathaoAPI(); } catch (\Throwable $e) {} }
if (file_exists($sf)) { try { require_once $sf; $sfApi = new SteadfastAPI(); } catch (\Throwable $e) {} }

/* ─── Fraud Check APIs (cross-merchant data) ─── */
$fraudData = ['pathao' => null, 'steadfast' => null, 'redx' => null, 'errors' => []];
if ($pathaoApi) {
    // Pathao merchant portal fraud check
    try {
        $pFraud = $pathaoApi->fraudCheckPathao($phone);
        if ($pFraud && !isset($pFraud['error'])) {
            $fraudData['pathao'] = $pFraud;
        } else {
            $fraudData['errors'][] = 'Pathao: ' . ($pFraud['error'] ?? 'empty');
        }
    } catch (\Throwable $e) { $fraudData['errors'][] = 'Pathao: ' . $e->getMessage(); }

    // Steadfast fraud check: try API endpoint first (faster, more reliable), then web scrape
    try {
        $sFraud = null;
        // Strategy A: Direct API endpoint (uses API key, no login needed)
        $sfApiKey = getSetting('steadfast_api_key','');
        $sfSecret = getSetting('steadfast_secret_key','');
        if ($sfApiKey && $sfSecret) {
            $sfHeaders = ['Api-Key: '.trim($sfApiKey), 'Secret-Key: '.trim($sfSecret), 'Content-Type: application/json', 'Accept: application/json'];
            $sfUrls = [
                'https://portal.packzy.com/api/v1/fraud_check/'.urlencode($phone),
                'https://portal.packzy.com/api/v1/courier_score/'.urlencode($phone),
            ];
            foreach ($sfUrls as $sfUrl) {
                $ch = curl_init($sfUrl);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>$sfHeaders,CURLOPT_FOLLOWLOCATION=>true]);
                $sfResp = curl_exec($ch); $sfCode = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                if ($sfCode === 200 && $sfResp) {
                    $sfData = json_decode($sfResp, true);
                    if ($sfData) {
                        $flat = array_merge($sfData, $sfData['data'] ?? []);
                        $totalParcels = intval($flat['total_parcels'] ?? 0);
                        $del = 0;
                        foreach (['total_delivered','delivered','success_count','success'] as $dk) {
                            if (isset($flat[$dk])) { $del = intval($flat[$dk]); break; }
                        }
                        $can = intval($flat['total_cancelled'] ?? $flat['cancelled'] ?? $flat['cancel'] ?? 0);
                        $total = $totalParcels > 0 ? $totalParcels : ($del + $can);
                        if ($total > 0 || $del > 0) {
                            $sFraud = ['total_delivered'=>$del, 'total_cancelled'=>$can, 'total_parcels'=>$total, 'source'=>'api'];
                            break;
                        }
                    }
                }
            }
        }
        // Strategy B: Web scrape fallback
        if (!$sFraud && $pathaoApi) {
            $sfWeb = $pathaoApi->fraudCheckSteadfast($phone);
            if ($sfWeb && !isset($sfWeb['error'])) $sFraud = $sfWeb;
            else $fraudData['errors'][] = 'Steadfast: ' . ($sfWeb['error'] ?? 'empty');
        }
        if ($sFraud) $fraudData['steadfast'] = $sFraud;
        elseif (!$sFraud && !$sfApiKey) $fraudData['errors'][] = 'Steadfast: API keys not configured';
    } catch (\Throwable $e) { $fraudData['errors'][] = 'Steadfast: ' . $e->getMessage(); }

    // RedX API fraud check
    try {
        $rFraud = $pathaoApi->fraudCheckRedx($phone);
        if ($rFraud && !isset($rFraud['error'])) {
            $fraudData['redx'] = $rFraud;
        } else {
            $fraudData['errors'][] = 'RedX: ' . ($rFraud['error'] ?? 'empty');
        }
    } catch (\Throwable $e) { $fraudData['errors'][] = 'RedX: ' . $e->getMessage(); }
}

/* Helper: classify status */
function classifyStatus($status) {
    global $pathaoDelivered, $pathaoReturn, $pathaoCancelled, $sfDelivered, $sfCancelled;
    if (in_array($status, $pathaoDelivered) || in_array($status, $sfDelivered) || $status === 'delivered') return 'delivered';
    if (in_array($status, $pathaoCancelled) || in_array($status, $sfCancelled) || $status === 'cancelled') return 'cancelled';
    if (in_array($status, $pathaoReturn) || in_array($status, ['returned', 'pending_return'])) return 'returned';
    return 'pending';
}

/* ─── 2. Process orders — query courier APIs for live status ─── */
function processOrders($orders, $api, $type, $db) {
    $stats = ['total' => count($orders), 'success' => 0, 'cancelled' => 0, 'returned' => 0, 'pending' => 0, 'rate' => 0, 'api_checked' => 0, 'details' => []];
    foreach ($orders as $o) {
        // For Pathao: check both courier_consignment_id AND pathao_consignment_id
        if ($type === 'pathao') {
            $cid = $o['pathao_consignment_id'] ?: $o['courier_consignment_id'] ?: $o['courier_tracking_id'];
        } else {
            $cid = $o['courier_consignment_id'] ?: $o['courier_tracking_id'];
        }
        $apiStatus = null;

        if ($cid && $api) {
            $configured = false;
            try { $configured = $api->isConfigured(); } catch (\Throwable $e) {}
            if ($configured) {
                try {
                    if ($type === 'pathao') {
                        $resp = $api->getOrderDetails($cid);
                        $apiStatus = $resp['data']['order_status'] ?? $resp['order_status'] ?? null;
                    } else {
                        // Steadfast: consignment_id is numeric, tracking_code is alphanumeric.
                        // Use the correct endpoint based on the identifier we have.
                        if (ctype_digit((string)$cid)) {
                            $resp = $api->getStatusByCid($cid);
                        } else {
                            // fallback: treat as tracking code
                            if (method_exists($api, 'getStatusByTrackingCode')) $resp = $api->getStatusByTrackingCode($cid);
                            else $resp = $api->getStatusByCid($cid);
                        }
                        $apiStatus = $resp['delivery_status'] ?? null;
                    }
                    $stats['api_checked']++;
                    // Update our DB with live status if different
                    if ($apiStatus && $apiStatus !== $o['courier_status']) {
                        try { $db->update('orders', ['courier_status' => $apiStatus, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$o['id']]); } catch (\Throwable $e) {}
                    }
                } catch (\Throwable $e) {}
                usleep(100000); // 100ms throttle
            }
        }

        // Use API status if available, otherwise fall back to our DB
        $status = $apiStatus ?: $o['courier_status'] ?: $o['order_status'];
        $cls = classifyStatus($status);
        if ($cls === 'delivered') $stats['success']++;
        elseif ($cls === 'cancelled') $stats['cancelled']++;
        elseif ($cls === 'returned') $stats['returned']++;
        else $stats['pending']++;

        $stats['details'][] = ['order' => $o['order_number'], 'our_status' => $o['order_status'], 'courier_status' => $apiStatus ?: $o['courier_status'], 'total' => $o['total']];
    }
    if ($stats['total'] > 0) $stats['rate'] = round(($stats['success'] / $stats['total']) * 100);
    return $stats;
}

$result = [];
$result['Pathao']    = processOrders($courierOrders['Pathao'], $pathaoApi, 'pathao', $db);
$result['Steadfast'] = processOrders($courierOrders['Steadfast'], $sfApi, 'steadfast', $db);

// Merge fraud check API data into results (cross-merchant counts)
if ($fraudData['pathao']) {
    $apiTotal   = intval($fraudData['pathao']['total_delivery'] ?? 0);
    $apiSuccess = intval($fraudData['pathao']['successful_delivery'] ?? 0);
    $apiCancel  = $apiTotal - $apiSuccess;
    $result['Pathao']['fraud_api'] = $fraudData['pathao'];
    $canOverride = (($fraudData['pathao']['show_count'] ?? true) === true) && intval($fraudData['pathao']['total_delivery'] ?? 0) > 0;
    if ($canOverride && $apiTotal > $result['Pathao']['total']) {
        $result['Pathao']['total']     = $apiTotal;
        $result['Pathao']['success']   = $apiSuccess;
        $result['Pathao']['cancelled'] = $apiCancel;
        $result['Pathao']['rate']      = $apiTotal > 0 ? round(($apiSuccess / $apiTotal) * 100) : 0;
        $result['Pathao']['data_source'] = 'merchant_portal';
    }
    $result['Pathao']['api_checked'] = max($result['Pathao']['api_checked'], 1);
}
if ($fraudData['steadfast']) {
    $apiDelivered = intval($fraudData['steadfast']['total_delivered'] ?? 0);
    $apiCancelled = intval($fraudData['steadfast']['total_cancelled'] ?? 0);
    // Use total_parcels as authoritative total (includes in-transit), fallback to sum
    $apiTotal     = intval($fraudData['steadfast']['total_parcels'] ?? 0);
    if ($apiTotal < ($apiDelivered + $apiCancelled)) $apiTotal = $apiDelivered + $apiCancelled;
    $result['Steadfast']['fraud_api'] = $fraudData['steadfast'];
    if ($apiTotal > $result['Steadfast']['total']) {
        $result['Steadfast']['total']     = $apiTotal;
        $result['Steadfast']['success']   = $apiDelivered;
        $result['Steadfast']['cancelled'] = $apiCancelled;
        $result['Steadfast']['rate']      = $apiTotal > 0 ? round(($apiDelivered / $apiTotal) * 100) : 0;
        $result['Steadfast']['data_source'] = 'steadfast_api';
    }
    $result['Steadfast']['api_checked'] = max($result['Steadfast']['api_checked'], 1);
}

// RedX: local + fraud API
$rxStats = ['total' => count($courierOrders['RedX']), 'success' => 0, 'cancelled' => 0, 'returned' => 0, 'pending' => 0, 'rate' => 0, 'api_checked' => 0];
foreach ($courierOrders['RedX'] as $o) {
    $cls = classifyStatus($o['courier_status'] ?: $o['order_status']);
    if ($cls === 'delivered') $rxStats['success']++;
    elseif ($cls === 'cancelled') $rxStats['cancelled']++;
    elseif ($cls === 'returned') $rxStats['returned']++;
    else $rxStats['pending']++;
}
if ($rxStats['total'] > 0) $rxStats['rate'] = round(($rxStats['success'] / $rxStats['total']) * 100);
if ($fraudData['redx']) {
    $apiDelivered = intval($fraudData['redx']['deliveredParcels'] ?? 0);
    $apiTotal     = intval($fraudData['redx']['totalParcels'] ?? 0);
    $apiCancel    = $apiTotal - $apiDelivered;
    $rxStats['fraud_api'] = $fraudData['redx'];
    if ($apiTotal > $rxStats['total']) {
        $rxStats['total']     = $apiTotal;
        $rxStats['success']   = $apiDelivered;
        $rxStats['cancelled'] = $apiCancel;
        $rxStats['rate']      = $apiTotal > 0 ? round(($apiDelivered / $apiTotal) * 100) : 0;
        $rxStats['data_source'] = 'redx_api';
    }
    $rxStats['api_checked'] = 1;
}
$result['RedX'] = $rxStats;

/* ─── Overall from OUR data ─── */
$allTotal     = array_sum(array_column($result, 'total')) + count($courierOrders['Other']);
$allSuccess   = array_sum(array_column($result, 'success'));
$allCancelled = array_sum(array_column($result, 'cancelled'));
$allReturned  = array_sum(array_column($result, 'returned'));
// Also count "Other" orders by our DB status
foreach ($courierOrders['Other'] as $o) {
    $cls = classifyStatus($o['courier_status'] ?: $o['order_status']);
    if ($cls === 'delivered') $allSuccess++;
    elseif ($cls === 'cancelled') $allCancelled++;
    elseif ($cls === 'returned') $allReturned++;
}
$overallRate  = $allTotal > 0 ? round(($allSuccess / $allTotal) * 100) : 0;

/* ─── Our Record ─── */
$ourRecord = ['is_new' => true, 'total_orders' => $allTotal, 'total' => 0, 'cancelled' => 0, 'web_orders' => 0, 'web_cancels' => 0, 'first_order' => null, 'total_spent' => 0];
try {
    $ci = $db->fetch(
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN order_status='cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN channel IN ('web','WEB','website') THEN 1 ELSE 0 END) as web_orders,
                SUM(CASE WHEN channel IN ('web','WEB','website') AND order_status='cancelled' THEN 1 ELSE 0 END) as web_cancels,
                MIN(created_at) as first_order,
                SUM(CASE WHEN order_status='delivered' THEN total ELSE 0 END) as total_spent
         FROM orders WHERE customer_phone LIKE ?", [$phoneLike]
    );
    if ($ci) {
        $ourRecord['total_orders'] = intval($ci['total']);
        $ourRecord['total']        = intval($ci['total']);
        $ourRecord['cancelled']    = intval($ci['cancelled']);
        $ourRecord['web_orders']   = intval($ci['web_orders']);
        $ourRecord['web_cancels']  = intval($ci['web_cancels']);
        $ourRecord['first_order']  = $ci['first_order'];
        $ourRecord['total_spent']  = floatval($ci['total_spent']);
        $ourRecord['is_new']       = intval($ci['total']) <= 1;
    }
} catch (\Throwable $e) {}

/* ─── Output ─── */
echo json_encode([
    'phone'   => $phone,
    'overall' => [
        'total' => $allTotal, 'success' => $allSuccess,
        'cancelled' => $allCancelled, 'returned' => $allReturned, 'rate' => $overallRate,
    ],
    'couriers'      => $result,
    'our_record'    => $ourRecord,
    'fraud_data'    => $fraudData,
    'api_available' => [
        'pathao'    => $pathaoApi && method_exists($pathaoApi, 'isConfigured') && $pathaoApi->isConfigured(),
        'steadfast' => $sfApi && method_exists($sfApi, 'isConfigured') && $sfApi->isConfigured(),
    ],
]);
