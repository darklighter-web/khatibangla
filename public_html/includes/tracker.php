<?php
/**
 * Visitor Tracking Middleware
 * Include this at the top of public-facing pages (index.php)
 * Tracks: device IP, network IP, user agent, browser, OS, device type
 * All functions guarded with function_exists to prevent redeclaration
 */

if (!function_exists('trackVisitor')) {
function trackVisitor($pageType = 'page', $extraData = []) {
    try {
        $db = Database::getInstance();
        
        $deviceIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $networkIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $deviceIp;
        if (strpos($networkIp, ',') !== false) {
            $networkIp = trim(explode(',', $networkIp)[0]);
        }
        
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browser = detectBrowser($ua);
        $os = detectOS($ua);
        $deviceType = detectDeviceType($ua);
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';
        $landingPage = $_SERVER['REQUEST_URI'] ?? '/';
        
        $sessionId = session_id();
        $phone = $_SESSION['customer_phone'] ?? ($extraData['phone'] ?? null);
        $customerId = $_SESSION['customer_id'] ?? null;
        
        $existing = $db->fetch(
            "SELECT id, pages_viewed, cart_items FROM visitor_logs WHERE session_id = ? AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE) ORDER BY id DESC LIMIT 1",
            [$sessionId]
        );
        
        if ($existing) {
            $updateData = [
                'pages_viewed' => $existing['pages_viewed'] + 1,
                'last_activity' => date('Y-m-d H:i:s'),
            ];
            if ($phone) $updateData['customer_phone'] = $phone;
            if ($customerId) $updateData['customer_id'] = $customerId;
            if (isset($extraData['cart_items'])) $updateData['cart_items'] = $extraData['cart_items'];
            if (isset($extraData['order_placed'])) {
                $updateData['order_placed'] = 1;
                $updateData['order_id'] = $extraData['order_id'] ?? null;
            }
            $db->update('visitor_logs', $updateData, 'id = ?', [$existing['id']]);
            $_SESSION['visitor_id'] = $existing['id'];
            return $existing['id'];
        } else {
            $visitorId = $db->insert('visitor_logs', [
                'session_id' => $sessionId,
                'device_ip' => $deviceIp,
                'network_ip' => $networkIp,
                'user_agent' => mb_substr($ua, 0, 500),
                'device_type' => $deviceType,
                'browser' => $browser,
                'os' => $os,
                'referrer' => mb_substr($referrer, 0, 500),
                'landing_page' => mb_substr($landingPage, 0, 500),
                'customer_id' => $customerId,
                'customer_phone' => $phone,
                'pages_viewed' => 1,
                'cart_items' => $extraData['cart_items'] ?? 0,
            ]);
            $_SESSION['visitor_id'] = $visitorId;
            return $visitorId;
        }
    } catch (\Throwable $e) {
        return null;
    }
}
}

if (!function_exists('trackIncompleteOrder')) {
function trackIncompleteOrder($cartData, $step = 'cart', $customerInfo = []) {
    try {
        $db = Database::getInstance();
        $sessionId = session_id();
        $phone = $customerInfo['phone'] ?? $_SESSION['customer_phone'] ?? null;
        
        // Detect column name
        $recCol = 'is_recovered';
        try { $db->fetch("SELECT is_recovered FROM incomplete_orders LIMIT 1"); } catch (\Throwable $e) { $recCol = 'recovered'; }
        
        $existing = $db->fetch(
            "SELECT id FROM incomplete_orders WHERE session_id = ? AND {$recCol} = 0 ORDER BY id DESC LIMIT 1",
            [$sessionId]
        );
        
        $data = [
            'cart_data' => json_encode($cartData),
            'step_reached' => $step,
            'customer_phone' => $phone,
            'customer_name' => $customerInfo['name'] ?? null,
        ];
        // Optional columns
        try { $db->query("SELECT cart_total FROM incomplete_orders LIMIT 0"); $data['cart_total'] = floatval($customerInfo['total'] ?? 0); } catch (\Throwable $e) {}
        try { $db->query("SELECT customer_address FROM incomplete_orders LIMIT 0"); $data['customer_address'] = $customerInfo['address'] ?? null; } catch (\Throwable $e) {}
        
        if ($existing) {
            $db->update('incomplete_orders', $data, 'id = ?', [$existing['id']]);
            return $existing['id'];
        } else {
            $data['session_id'] = $sessionId;
            try { $db->query("SELECT visitor_id FROM incomplete_orders LIMIT 0"); $data['visitor_id'] = $_SESSION['visitor_id'] ?? null; } catch (\Throwable $e) {}
            try { $db->query("SELECT device_ip FROM incomplete_orders LIMIT 0"); $data['device_ip'] = $_SERVER['REMOTE_ADDR'] ?? ''; } catch (\Throwable $e) {}
            try { $db->query("SELECT user_agent FROM incomplete_orders LIMIT 0"); $data['user_agent'] = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500); } catch (\Throwable $e) {}
            return $db->insert('incomplete_orders', $data);
        }
    } catch (\Throwable $e) {
        return null;
    }
}
}

if (!function_exists('recoverIncompleteOrder')) {
function recoverIncompleteOrder($orderId) {
    try {
        $db = Database::getInstance();
        $sessionId = session_id();
        // Detect column name
        $recCol = 'is_recovered';
        try { $db->fetch("SELECT is_recovered FROM incomplete_orders LIMIT 1"); } catch (\Throwable $e) { $recCol = 'recovered'; }
        $db->query(
            "UPDATE incomplete_orders SET {$recCol} = 1, recovered_order_id = ? WHERE session_id = ? AND {$recCol} = 0",
            [$orderId, $sessionId]
        );
    } catch (\Throwable $e) {}
}
}

// === Browser / OS / Device Detection ===

if (!function_exists('detectBrowser')) {
function detectBrowser($ua) {
    if (preg_match('/Edg\//i', $ua)) return 'Edge';
    if (preg_match('/OPR|Opera/i', $ua)) return 'Opera';
    if (preg_match('/Chrome/i', $ua)) return 'Chrome';
    if (preg_match('/Firefox/i', $ua)) return 'Firefox';
    if (preg_match('/Safari/i', $ua) && !preg_match('/Chrome/i', $ua)) return 'Safari';
    if (preg_match('/MSIE|Trident/i', $ua)) return 'IE';
    if (preg_match('/facebookexternalhit|Facebot/i', $ua)) return 'Facebook';
    return 'Other';
}
}

if (!function_exists('detectOS')) {
function detectOS($ua) {
    if (preg_match('/Windows NT 10/i', $ua)) return 'Windows 10+';
    if (preg_match('/Windows/i', $ua)) return 'Windows';
    if (preg_match('/Android/i', $ua)) return 'Android';
    if (preg_match('/iPhone|iPad/i', $ua)) return 'iOS';
    if (preg_match('/Mac OS/i', $ua)) return 'macOS';
    if (preg_match('/Linux/i', $ua)) return 'Linux';
    return 'Other';
}
}

if (!function_exists('detectDeviceType')) {
function detectDeviceType($ua) {
    if (preg_match('/bot|crawl|spider|slurp|facebook|whatsapp/i', $ua)) return 'bot';
    if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) return 'tablet';
    if (preg_match('/mobile|iphone|ipod|android.*mobile|windows.*phone|opera m|mobi/i', $ua)) return 'mobile';
    return 'desktop';
}
}
