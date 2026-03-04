<?php
/**
 * Facebook Conversions API (Server-Side Tracking) Engine
 * ═══════════════════════════════════════════════════════
 * 
 * Events: PageView, ViewContent, AddToCart, InitiateCheckout, 
 *         Purchase, Search, Lead, CompleteRegistration, Contact
 * 
 * Features:
 * - Event deduplication with event_id (matches browser pixel)
 * - SHA-256 hashed PII (email, phone, name, city, country)
 * - fbp/fbc cookie forwarding
 * - IP + User-Agent passthrough  
 * - Advanced matching parameters
 * - Test event code support
 * - Non-blocking async option
 * - Detailed error/success logging
 */

defined('FB_CAPI_LOADED') || define('FB_CAPI_LOADED', true);

// ────────────────────────────────────
//  HELPERS
// ────────────────────────────────────

function fbHash(string $val): string {
    $val = trim(strtolower($val));
    return $val !== '' ? hash('sha256', $val) : '';
}

function fbEventId(): string {
    return 'evt_' . bin2hex(random_bytes(12));
}

function fbCapiEventEnabled(string $event): bool {
    return getSetting('fb_ss_evt_' . strtolower($event), '1') === '1';
}

function fbPixelEventEnabled(string $event): bool {
    return getSetting('fb_cs_evt_' . strtolower($event), '1') === '1';
}

function fbCapiEnabled(): bool {
    $token = getSetting('fb_access_token', '');
    $pixelId = getSetting('fb_pixel_id', '') ?: getSetting('facebook_pixel_id', '') ?: getSetting('facebook_pixel', '');
    return !empty($token) && !empty($pixelId);
}

function fbGetPixelId(): string {
    return getSetting('fb_pixel_id', '') ?: getSetting('facebook_pixel_id', '') ?: getSetting('facebook_pixel', '');
}

// ────────────────────────────────────
//  USER DATA BUILDER
// ────────────────────────────────────

function fbBuildUserData(array $extra = []): array {
    $ud = [];

    // IP (unhashed — required by FB)
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    $ud['client_ip_address'] = $ip;

    // User Agent (unhashed — required)
    $ud['client_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // FB Cookies
    if (!empty($_COOKIE['_fbp'])) $ud['fbp'] = $_COOKIE['_fbp'];
    if (!empty($_COOKIE['_fbc'])) {
        $ud['fbc'] = $_COOKIE['_fbc'];
    } elseif (!empty($_GET['fbclid'])) {
        $ud['fbc'] = 'fb.1.' . (time() * 1000) . '.' . $_GET['fbclid'];
    }

    // External ID
    $extId = $_SESSION['visitor_id'] ?? ($_SESSION['customer_id'] ?? null);
    if ($extId) $ud['external_id'] = [fbHash((string)$extId)];

    // ── Hashed PII from $extra ──
    if (!empty($extra['email'])) {
        $em = fbHash(trim($extra['email']));
        if ($em) $ud['em'] = [$em];
    }
    if (!empty($extra['phone'])) {
        $ph = preg_replace('/[^0-9]/', '', $extra['phone']);
        if (strlen($ph) === 11 && str_starts_with($ph, '0')) $ph = '880' . substr($ph, 1);
        if (strlen($ph) === 10 && !str_starts_with($ph, '0')) $ph = '880' . $ph;
        $hashed = fbHash($ph);
        if ($hashed) $ud['ph'] = [$hashed];
    }
    if (!empty($extra['name'])) {
        $parts = preg_split('/\s+/', trim($extra['name']), 2);
        $fn = fbHash($parts[0]);
        if ($fn) $ud['fn'] = [$fn];
        if (isset($parts[1])) {
            $ln = fbHash($parts[1]);
            if ($ln) $ud['ln'] = [$ln];
        }
    }
    if (!empty($extra['city'])) {
        $ct = fbHash(preg_replace('/[^a-z0-9\x{0980}-\x{09FF}]/u', '', strtolower(trim($extra['city']))));
        if ($ct) $ud['ct'] = [$ct];
    }
    if (!empty($extra['district'])) {
        $st = fbHash(trim($extra['district']));
        if ($st) $ud['st'] = [$st];
    }
    if (!empty($extra['zip'])) {
        $zp = fbHash(trim($extra['zip']));
        if ($zp) $ud['zp'] = [$zp];
    }

    // Country — always BD
    $ud['country'] = [fbHash('bd')];

    // Gender if available
    if (!empty($extra['gender'])) {
        $g = strtolower(trim($extra['gender']));
        if (in_array($g, ['m', 'f'])) $ud['ge'] = [fbHash($g)];
    }

    // Date of birth
    if (!empty($extra['dob'])) {
        $ud['db'] = [fbHash(str_replace(['-', '/'], '', $extra['dob']))];
    }

    return $ud;
}

// ────────────────────────────────────
//  CORE SEND FUNCTION
// ────────────────────────────────────

function fbCapiSend(string $eventName, array $customData = [], array $userDataExtra = [], ?string $eventId = null, ?int $eventTime = null): array {
    $pixelId = fbGetPixelId();
    $accessToken = getSetting('fb_access_token', '');

    if (empty($pixelId) || empty($accessToken)) {
        return ['success' => false, 'error' => 'Missing pixel ID or access token'];
    }
    if (!fbCapiEventEnabled($eventName)) {
        return ['success' => false, 'error' => "Event '{$eventName}' is disabled in server-side settings"];
    }

    $eventTime = $eventTime ?? time();
    $eventId = $eventId ?? fbEventId();
    $sourceUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');

    $event = [
        'event_name'       => $eventName,
        'event_time'       => $eventTime,
        'event_id'         => $eventId,
        'event_source_url' => $sourceUrl,
        'action_source'    => 'website',
        'user_data'        => fbBuildUserData($userDataExtra),
    ];

    if (!empty($customData)) {
        $event['custom_data'] = $customData;
    }

    $body = ['data' => [$event]];

    // Test event code
    $testCode = getSetting('fb_test_event_code', '');
    if (!empty(trim($testCode))) {
        $body['test_event_code'] = trim($testCode);
    }

    $url = "https://graph.facebook.com/v21.0/{$pixelId}/events?access_token={$accessToken}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // ── Logging ──
    $logEnabled = getSetting('fb_event_logging', '0') === '1';
    if ($logEnabled || $httpCode !== 200) {
        $logDir = __DIR__ . '/../logs';
        @mkdir($logDir, 0755, true);
        $line = date('Y-m-d H:i:s')
            . " | {$eventName}"
            . " | eid={$eventId}"
            . " | HTTP {$httpCode}"
            . ($curlErr ? " | cURL:{$curlErr}" : '')
            . ($httpCode !== 200 ? " | Resp:" . mb_substr($response ?? '', 0, 300) : '')
            . "\n";
        @file_put_contents($logDir . '/fb-capi.log', $line, FILE_APPEND | LOCK_EX);
    }

    $decoded = json_decode($response ?? '', true);
    return [
        'success'    => $httpCode === 200 && !isset($decoded['error']),
        'http_code'  => $httpCode,
        'event_id'   => $eventId,
        'events_received' => $decoded['events_received'] ?? 0,
        'messages'   => $decoded['messages'] ?? [],
        'error'      => $decoded['error']['message'] ?? $curlErr ?: null,
        'fbtrace_id' => $decoded['fbtrace_id'] ?? null,
    ];
}

// ────────────────────────────────────
//  CONVENIENCE: ONE-LINER PER EVENT
// ────────────────────────────────────

function fbTrackPageView(?string $eid = null): array {
    return fbCapiSend('PageView', [], [], $eid);
}

function fbTrackViewContent(array $product, ?string $eid = null): array {
    $price = floatval($product['sale_price'] ?? $product['regular_price'] ?? 0);
    return fbCapiSend('ViewContent', [
        'content_ids'      => [(string)($product['id'] ?? '')],
        'content_type'     => 'product',
        'content_name'     => $product['name_bn'] ?? $product['name'] ?? '',
        'content_category' => $product['category_name'] ?? '',
        'value'            => $price,
        'currency'         => 'BDT',
    ], [], $eid);
}

function fbTrackAddToCart(array $product, int $qty = 1, ?string $eid = null): array {
    $price = floatval($product['sale_price'] ?? $product['regular_price'] ?? 0);
    return fbCapiSend('AddToCart', [
        'content_ids'  => [(string)($product['id'] ?? '')],
        'content_type' => 'product',
        'content_name' => $product['name_bn'] ?? $product['name'] ?? '',
        'value'        => $price * $qty,
        'currency'     => 'BDT',
        'num_items'    => $qty,
    ], [], $eid);
}

function fbTrackInitiateCheckout(float $value = 0, int $numItems = 0, array $contentIds = [], ?string $eid = null): array {
    return fbCapiSend('InitiateCheckout', [
        'content_ids'  => $contentIds,
        'content_type' => 'product',
        'value'        => $value,
        'currency'     => 'BDT',
        'num_items'    => $numItems,
    ], [], $eid);
}

function fbTrackPurchase(array $orderData, ?string $eid = null): array {
    $contentIds = [];
    $contents = [];
    if (!empty($orderData['items'])) {
        foreach ($orderData['items'] as $item) {
            $pid = (string)($item['product_id'] ?? $item['id'] ?? '');
            $contentIds[] = $pid;
            $contents[] = [
                'id'       => $pid,
                'quantity' => intval($item['quantity'] ?? 1),
                'item_price' => floatval($item['price'] ?? 0),
            ];
        }
    }
    return fbCapiSend('Purchase', [
        'content_ids'  => $contentIds,
        'contents'     => $contents,
        'content_type' => 'product',
        'value'        => floatval($orderData['total'] ?? 0),
        'currency'     => 'BDT',
        'order_id'     => $orderData['order_number'] ?? '',
        'num_items'    => count($contentIds),
    ], [
        'email'    => $orderData['email'] ?? '',
        'phone'    => $orderData['phone'] ?? '',
        'name'     => $orderData['name'] ?? '',
        'city'     => $orderData['city'] ?? '',
        'district' => $orderData['district'] ?? '',
    ], $eid);
}

function fbTrackSearch(string $query, ?string $eid = null): array {
    return fbCapiSend('Search', [
        'search_string' => $query,
        'content_type'  => 'product',
    ], [], $eid);
}

function fbTrackLead(array $ud = [], ?string $eid = null): array {
    return fbCapiSend('Lead', ['content_name' => 'Contact/Lead'], $ud, $eid);
}

function fbTrackCompleteRegistration(array $ud = [], ?string $eid = null): array {
    return fbCapiSend('CompleteRegistration', [
        'content_name' => 'Customer Registration',
        'status'       => 'completed',
    ], $ud, $eid);
}

function fbTrackContact(array $ud = [], ?string $eid = null): array {
    return fbCapiSend('Contact', ['content_name' => 'Contact Click'], $ud, $eid);
}
