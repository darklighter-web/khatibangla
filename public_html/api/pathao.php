<?php
/**
 * Pathao Courier Merchant API Integration
 * Production: https://api-hermes.pathao.com
 * Sandbox:    https://courier-api-sandbox.pathao.com
 */
require_once __DIR__ . '/courier-rate-limiter.php';

class PathaoAPI {
    private $baseUrl;
    private $clientId;
    private $clientSecret;
    private $username;
    private $password;
    private $accessToken;
    private $tokenExpiry;
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->clientId     = $this->setting('pathao_client_id');
        $this->clientSecret = $this->setting('pathao_client_secret');
        $this->username     = $this->setting('pathao_username');
        $this->password     = $this->setting('pathao_password');
        $this->accessToken  = $this->setting('pathao_access_token');
        $this->tokenExpiry  = intval($this->setting('pathao_token_expiry'));
        $env = $this->setting('pathao_environment') ?: 'production';
        $this->baseUrl = $env === 'sandbox'
            ? 'https://courier-api-sandbox.pathao.com'
            : 'https://api-hermes.pathao.com';
    }

    public function setting($key) {
        try {
            $row = $this->db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
            return $row ? $row['setting_value'] : '';
        } catch (Exception $e) {
            return '';
        }
    }

    public function saveSetting($key, $value, $group = 'pathao') {
        try {
            $exists = $this->db->fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key]);
            if ($exists) {
                $this->db->update('site_settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
            } else {
                $this->db->insert('site_settings', [
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'setting_type' => 'text',
                    'setting_group' => $group,
                    'label' => ucwords(str_replace(['pathao_','_'], ['','  '], $key))
                ]);
            }
        } catch (Exception $e) {
            error_log("PathaoAPI saveSetting error: " . $e->getMessage());
        }
    }

    public function saveConfig($data) {
        $fields = ['client_id','client_secret','username','password','environment','store_id','webhook_secret'];
        foreach ($fields as $f) {
            if (isset($data[$f])) {
                $this->saveSetting('pathao_' . $f, $data[$f]);
            }
        }
        // Reload credentials after save
        $this->clientId     = $data['client_id'] ?? $this->clientId;
        $this->clientSecret = $data['client_secret'] ?? $this->clientSecret;
        $this->username     = $data['username'] ?? $this->username;
        $this->password     = $data['password'] ?? $this->password;
        $env = $data['environment'] ?? 'production';
        $this->baseUrl = $env === 'sandbox'
            ? 'https://courier-api-sandbox.pathao.com'
            : 'https://api-hermes.pathao.com';
    }

    public function isConfigured() {
        return !empty($this->clientId) && !empty($this->clientSecret)
            && !empty($this->username) && !empty($this->password);
    }

    public function isConnected() {
        return $this->isConfigured() && !empty($this->accessToken) && $this->tokenExpiry > time();
    }

    // ========================
    // AUTH - Issue Token
    // ========================
    public function getAccessToken($force = false) {
        if (!$force && $this->accessToken && $this->tokenExpiry > time()) {
            return $this->accessToken;
        }
        if (!$this->isConfigured()) {
            throw new Exception('Pathao API not configured. Fill in all credential fields.');
        }
        $res = $this->http('POST', '/aladdin/api/v1/issue-token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username'      => $this->username,
            'password'      => $this->password,
            'grant_type'    => 'password',
        ], false);

        if (!empty($res['access_token'])) {
            $this->accessToken = $res['access_token'];
            $this->tokenExpiry = time() + ($res['expires_in'] ?? 86400);
            $this->saveSetting('pathao_access_token',  $this->accessToken);
            $this->saveSetting('pathao_token_expiry',  (string)$this->tokenExpiry);
            $this->saveSetting('pathao_refresh_token', $res['refresh_token'] ?? '');
            return $this->accessToken;
        }

        $errMsg = $res['message'] ?? $res['error'] ?? 'Unknown authentication error';
        if (isset($res['errors'])) {
            $errMsg .= ' - ' . json_encode($res['errors']);
        }
        throw new Exception('Pathao auth failed: ' . $errMsg);
    }

    public function testConnection() {
        try {
            $token = $this->getAccessToken(true);
            return $token
                ? ['success' => true,  'message' => 'Connected to Pathao successfully! Token is valid.']
                : ['success' => false, 'message' => 'Authentication failed. Check your credentials.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ========================
    // LOCATION ENDPOINTS
    // ========================
    // Static data endpoints — cached 24 hours to reduce API calls
    public function getCities() {
        return courierCacheStatic('pathao_cities', function() {
            return $this->authed('GET', '/aladdin/api/v1/city-list');
        }, 86400);
    }
    public function getZones($cityId) {
        return courierCacheStatic("pathao_zones_{$cityId}", function() use ($cityId) {
            return $this->authed('GET', "/aladdin/api/v1/cities/{$cityId}/zone-list");
        }, 86400);
    }
    public function getAreas($zoneId) {
        return courierCacheStatic("pathao_areas_{$zoneId}", function() use ($zoneId) {
            return $this->authed('GET', "/aladdin/api/v1/zones/{$zoneId}/area-list");
        }, 86400);
    }

    // ========================
    // STORES
    // ========================
    public function getStores($page = 1) { return $this->authed('GET', "/aladdin/api/v1/stores?page={$page}"); }

    // ========================
    // ORDERS
    // ========================
    public function createOrder($data)               { return $this->authed('POST', '/aladdin/api/v1/orders', $data); }
    public function getOrderDetails($consignmentId)   { return $this->authed('GET', "/aladdin/api/v1/orders/{$consignmentId}/info"); }
    public function getPriceCalculation($data)        { return $this->authed('POST', '/aladdin/api/v1/merchant/price-plan', $data); }

    // ========================
    // FRAUD CHECK: PATHAO MERCHANT PORTAL
    // Auth: merchant.pathao.com/api/v1/login (email + password only)
    // Data: merchant.pathao.com/api/v1/user/success
    // ========================
    public function checkCustomerPhone($phone) {
        $phone = self::normalizePhone($phone);
        // Try merchant portal first (has the real fraud data)
        $portalData = $this->fraudCheckPathao($phone);
        if ($portalData && !isset($portalData['error'])) {
            return ['data' => ['customer' => $portalData]];
        }
        // Fallback to Hermes API customer-check
        try {
            return $this->authed('POST', '/aladdin/api/v1/merchant/customer-check', ['phone' => $phone]);
        } catch (\Throwable $e) {
            return $portalData ? ['data' => ['customer' => $portalData]] : null;
        }
    }

    /**
     * Pathao Merchant Portal fraud check
     * Uses merchant.pathao.com login (just email + password, no client_id needed)
     * Returns: successful_delivery, total_delivery
     */
    public function fraudCheckPathao($phone) {
        $phone = self::normalizePhone($phone);
        $username = $this->username; // pathao_username (merchant email)
        $password = $this->password; // pathao_password

        if (empty($username) || empty($password)) {
            return ['error' => 'Pathao merchant credentials not set'];
        }

        // Step 1: Login to merchant portal
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://merchant.pathao.com/api/v1/login',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['username' => $username, 'password' => $password]),
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $loginResp = curl_exec($ch);
        $loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loginErr  = curl_error($ch);
        curl_close($ch);

        if ($loginErr) return ['error' => 'Pathao portal login curl error: ' . $loginErr];
        $loginData = json_decode($loginResp, true);
        $portalToken = trim($loginData['access_token'] ?? '');
        if (!$portalToken) {
            return ['error' => 'Pathao portal login failed: ' . ($loginData['message'] ?? 'no token'), 'http' => $loginCode];
        }

        // Step 2: Get customer fraud data
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://merchant.pathao.com/api/v1/user/success',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $portalToken,
            ],
            CURLOPT_POSTFIELDS => json_encode(['phone' => $phone]),
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $dataResp = curl_exec($ch);
        $dataCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $dataErr  = curl_error($ch);
        curl_close($ch);

        if ($dataErr) return ['error' => 'Pathao portal data curl error: ' . $dataErr];
        $result = json_decode($dataResp, true);

        if ($dataCode >= 400 || !$result) {
            return ['error' => 'Pathao portal data error (HTTP ' . $dataCode . ')', 'raw' => substr($dataResp, 0, 300)];
        }

        // Extract stats from response
        $customer = $result['data']['customer'] ?? $result['data'] ?? $result;
        
        // Pathao v2: show_count=false means counts are hidden, only rating available
        $showCount = $customer['show_count'] ?? true;
        $version   = $customer['version'] ?? 'v1';
        $rating    = $customer['customer_rating'] ?? null;
        
        if ($showCount === false || $version === 'v2') {
            // v2 mode: rating only, no delivery counts
            return [
                'successful_delivery' => 0,
                'total_delivery'      => 0,
                'cancel'              => 0,
                'show_count'          => false,
                'customer_rating'     => $rating,
                'version'             => $version,
                'source'              => 'pathao_merchant_portal',
                'raw'                 => $customer,
            ];
        }
        
        return [
            'successful_delivery' => intval($customer['successful_delivery'] ?? 0),
            'total_delivery'      => intval($customer['total_delivery'] ?? 0),
            'cancel'              => intval(($customer['total_delivery'] ?? 0) - ($customer['successful_delivery'] ?? 0)),
            'show_count'          => true,
            'customer_rating'     => $rating,
            'source'              => 'pathao_merchant_portal',
            'raw'                 => $customer,
        ];
    }

    /**
     * Steadfast fraud check
     * Uses web session login + /user/frauds/check/{phone}
     * Returns: total_delivered, total_cancelled
     */
    public function fraudCheckSteadfast($phone) {
        $phone = self::normalizePhone($phone);
        $email    = $this->setting('steadfast_merchant_email') ?: $this->setting('steadfast_email');
        $password = $this->setting('steadfast_merchant_password') ?: $this->setting('steadfast_password');

        if (empty($email) || empty($password)) {
            return ['error' => 'Steadfast merchant login not configured'];
        }

        $cookieFile = sys_get_temp_dir() . '/sf_cookie_' . md5($email) . '.txt';
        @unlink($cookieFile);
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        // Step 1: GET login page for CSRF token
        $ch = curl_init('https://steadfast.com.bd/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ["User-Agent: $ua", 'Accept: text/html'],
        ]);
        $loginPage = curl_exec($ch); curl_close($ch);

        $csrfToken = '';
        if (preg_match('/<input[^>]+name="_token"[^>]+value="([^"]+)"/', $loginPage, $m)) $csrfToken = $m[1];
        elseif (preg_match('/<input[^>]+value="([^"]+)"[^>]+name="_token"/', $loginPage, $m)) $csrfToken = $m[1];
        elseif (preg_match('/<meta[^>]+name="csrf-token"[^>]+content="([^"]+)"/', $loginPage, $m)) $csrfToken = $m[1];
        if (!$csrfToken) { @unlink($cookieFile); return ['error' => 'Steadfast CSRF token not found']; }

        // Step 2: POST login
        $ch = curl_init('https://steadfast.com.bd/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ["User-Agent: $ua", 'Content-Type: application/x-www-form-urlencoded',
                'Referer: https://steadfast.com.bd/login', 'Origin: https://steadfast.com.bd'],
            CURLOPT_POSTFIELDS => http_build_query(['_token' => $csrfToken, 'email' => $email, 'password' => $password]),
        ]);
        $loginResp = curl_exec($ch);
        $finalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if (strpos($finalUrl, '/login') !== false && strpos($loginResp, 'credentials') !== false) {
            @unlink($cookieFile); return ['error' => 'Steadfast login failed — wrong email/password'];
        }

        // Get fresh CSRF token
        $newCsrf = '';
        if (preg_match('/<meta[^>]+name="csrf-token"[^>]+content="([^"]+)"/', $loginResp, $m)) $newCsrf = $m[1];

        // Step 3: Visit fraud check page first (establish session)
        $ch = curl_init('https://steadfast.com.bd/user/frauds/check');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ["User-Agent: $ua", 'Referer: https://steadfast.com.bd/user/dashboard'],
        ]);
        $fraudPage = curl_exec($ch); curl_close($ch);
        if (preg_match('/<meta[^>]+name="csrf-token"[^>]+content="([^"]+)"/', $fraudPage, $m)) $newCsrf = $m[1];

        // Step 4: GET fraud data with proper headers
        $headers = ["User-Agent: $ua", 'Accept: application/json, text/javascript, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest', 'Referer: https://steadfast.com.bd/user/frauds/check'];
        if ($newCsrf) $headers[] = 'X-CSRF-TOKEN: ' . $newCsrf;

        $ch = curl_init('https://steadfast.com.bd/user/frauds/check/' . urlencode($phone));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $fraudResp = curl_exec($ch);
        $fraudCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Logout + cleanup
        try { if ($newCsrf) {
            $ch = curl_init('https://steadfast.com.bd/logout');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>5,
                CURLOPT_COOKIEFILE=>$cookieFile,CURLOPT_SSL_VERIFYPEER=>false,
                CURLOPT_POSTFIELDS=>http_build_query(['_token'=>$newCsrf])]);
            curl_exec($ch); curl_close($ch);
        }} catch (\Throwable $e) {}
        @unlink($cookieFile);

        $fraudData = json_decode($fraudResp, true);
        if (!$fraudData || $fraudCode >= 400) {
            $errMsg = 'Steadfast fraud check failed (HTTP ' . $fraudCode . ')';
            if ($fraudCode === 403) $errMsg .= ' — access denied';
            return ['error' => $errMsg, 'raw' => substr($fraudResp, 0, 300)];
        }

        return [
            'total_delivered'  => intval($fraudData['total_delivered'] ?? 0),
            'total_cancelled'  => intval($fraudData['total_cancelled'] ?? 0),
            'total'            => intval($fraudData['total_delivered'] ?? 0) + intval($fraudData['total_cancelled'] ?? 0),
            'source'           => 'steadfast_merchant_portal',
            'raw'              => $fraudData,
        ];
    }

    /**
     * RedX fraud check
     * Uses api.redx.com.bd login + customer-success-return-rate endpoint
     * Returns: deliveredParcels, totalParcels
     */
    public function fraudCheckRedx($phone) {
        $phone = self::normalizePhone($phone);
        $redxPhone    = $this->setting('redx_phone');
        $redxPassword = $this->setting('redx_password');

        if (empty($redxPhone) || empty($redxPassword)) {
            return ['error' => 'RedX credentials not configured'];
        }

        // Get cached token (or login once and cache for 12 hours)
        $token = $this->getRedxCachedToken($redxPhone, $redxPassword);
        if ($token === '__rate_limited__') return ['error' => 'RedX rate limited (429) — wait 10 min'];
        if (!$token) return ['error' => 'RedX login failed — check credentials'];

        // Query phone: 8801XXXXXXXXX format
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (substr($cleanPhone, 0, 2) === '88') $cleanPhone = substr($cleanPhone, 2);
        if ($cleanPhone[0] !== '0') $cleanPhone = '0' . $cleanPhone;
        $queryPhone = '88' . $cleanPhone;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=' . urlencode($queryPhone),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain, */*',
                'Authorization: Bearer ' . $token,
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $dataResp = curl_exec($ch);
        $dataCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // If token expired (401), clear cache and retry once
        if ($dataCode === 401) {
            try { $this->db->update('site_settings', ['setting_value' => ''], 'setting_key = ?', ['redx_fraud_token']); } catch (\Throwable $e) {}
            $token = $this->getRedxCachedToken($redxPhone, $redxPassword);
            if (!$token || $token === '__rate_limited__') return ['error' => 'RedX re-login failed'];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=' . urlencode($queryPhone),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json, text/plain, */*',
                    'Authorization: Bearer ' . $token,
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $dataResp = curl_exec($ch);
            $dataCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        $result = json_decode($dataResp, true);
        if (!$result || $dataCode >= 400) {
            return ['error' => 'RedX fraud check failed (HTTP ' . $dataCode . ')', 'raw' => substr($dataResp, 0, 300)];
        }

        $data = $result['data'] ?? $result;
        return [
            'deliveredParcels' => intval($data['deliveredParcels'] ?? 0),
            'totalParcels'     => intval($data['totalParcels'] ?? 0),
            'cancel'           => intval(($data['totalParcels'] ?? 0) - ($data['deliveredParcels'] ?? 0)),
            'source'           => 'redx_api',
            'raw'              => $data,
        ];
    }

    /**
     * Get RedX token from cache or login once and cache for 12 hours
     */
    private function getRedxCachedToken($redxPhone, $redxPassword) {
        // Check for cached token
        try {
            $cached = $this->db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'redx_fraud_token'");
            if ($cached && !empty($cached['setting_value'])) {
                $tokenData = json_decode($cached['setting_value'], true);
                if ($tokenData && !empty($tokenData['token']) && ($tokenData['expires'] ?? 0) > time()) {
                    return $tokenData['token'];
                }
            }
        } catch (\Throwable $e) {}

        // Token expired/missing — login once
        $cleanRp = preg_replace('/[^0-9]/', '', $redxPhone);
        if (substr($cleanRp, 0, 2) === '88') $cleanRp = substr($cleanRp, 2);
        if ($cleanRp[0] !== '0') $cleanRp = '0' . $cleanRp;
        $loginPhone = '88' . $cleanRp;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.redx.com.bd/v4/auth/login',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json, text/plain, */*',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            ],
            CURLOPT_POSTFIELDS => json_encode(['phone' => $loginPhone, 'password' => $redxPassword]),
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $loginResp = curl_exec($ch);
        $loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($loginCode === 429) return '__rate_limited__';

        $loginData = json_decode($loginResp, true);
        $token = $loginData['data']['accessToken'] ?? '';
        if (!$token) return null;

        // Cache for 12 hours
        $tokenJson = json_encode(['token' => $token, 'expires' => time() + 43200]);
        try {
            $exists = $this->db->fetch("SELECT id FROM site_settings WHERE setting_key = 'redx_fraud_token'");
            if ($exists) {
                $this->db->update('site_settings', ['setting_value' => $tokenJson], 'setting_key = ?', ['redx_fraud_token']);
            } else {
                $this->db->insert('site_settings', [
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

    // ========================
    // CUSTOMER PROFILE (local DB + merchant portal fraud checks)
    // ========================
    public function getCustomerProfile($phone) {
        $phone = self::normalizePhone($phone);
        $phoneLike = '%' . substr($phone, -10) . '%';
        $apiNotes = [];

        // ── 1. LOCAL DB: All orders for this customer ──
        $localAll = [];
        try {
            $localAll = $this->db->fetchAll("
                SELECT id, order_number, order_status, courier_name, shipping_method,
                       courier_status, courier_tracking_id, courier_consignment_id, pathao_consignment_id,
                       total, created_at
                FROM orders WHERE customer_phone LIKE ? AND order_status NOT IN ('incomplete')
                ORDER BY created_at DESC
            ", [$phoneLike]);
        } catch (\Throwable $e) { $apiNotes[] = 'DB: ' . $e->getMessage(); }

        // Group orders by courier
        $byC = ['Pathao' => [], 'Steadfast' => [], 'RedX' => [], 'Other' => []];
        foreach ($localAll as $o) {
            $cn = strtolower(($o['courier_name'] ?: $o['shipping_method']) ?? '');
            if (strpos($cn, 'pathao') !== false)        $byC['Pathao'][] = $o;
            elseif (strpos($cn, 'steadfast') !== false)  $byC['Steadfast'][] = $o;
            elseif (strpos($cn, 'redx') !== false)       $byC['RedX'][] = $o;
            else                                          $byC['Other'][] = $o;
        }

        // ── 2. PATHAO MERCHANT PORTAL: Cross-merchant fraud data ──
        $pathaoFraud = null;
        try {
            $pf = $this->fraudCheckPathao($phone);
            if ($pf && !isset($pf['error'])) {
                $pathaoFraud = $pf;
                $showCount = $pf['show_count'] ?? true;
                if ($showCount) {
                    $apiNotes[] = 'Pathao merchant portal: OK (total: ' . ($pf['total_delivery'] ?? 0) . ', success: ' . ($pf['successful_delivery'] ?? 0) . ')';
                } else {
                    $apiNotes[] = 'Pathao merchant portal: OK (rating: ' . ($pf['customer_rating'] ?? 'unknown') . ', v2 — counts hidden)';
                }
            } else {
                $apiNotes[] = 'Pathao merchant portal: ' . ($pf['error'] ?? 'empty response');
                // Fallback: try Hermes API customer-check
                if ($this->isConfigured()) {
                    try {
                        $pd = $this->authed('POST', '/aladdin/api/v1/merchant/customer-check', ['phone' => $phone]);
                        if ($pd) {
                            $d = $pd['data'] ?? $pd;
                            if (isset($d['data']) && is_array($d['data'])) $d = $d['data'];
                            $pathaoFraud = [
                                'successful_delivery' => intval($d['success'] ?? $d['delivered'] ?? $d['successful_delivery'] ?? 0),
                                'total_delivery'      => intval($d['total'] ?? $d['total_orders'] ?? $d['total_delivery'] ?? 0),
                                'cancel'              => intval($d['cancel'] ?? $d['cancelled'] ?? 0),
                                'source'              => 'hermes_api',
                                'customer_rating'     => $d['customer_rating'] ?? null,
                                'risk_level'          => $d['risk_level'] ?? null,
                                'message'             => $d['message'] ?? null,
                                'raw'                 => $d,
                            ];
                            $apiNotes[] = 'Pathao Hermes API fallback: OK';
                        }
                    } catch (\Throwable $e) {
                        $apiNotes[] = 'Pathao Hermes API fallback: ' . $e->getMessage();
                    }
                }
            }
        } catch (\Throwable $e) {
            $apiNotes[] = 'Pathao portal error: ' . $e->getMessage();
        }

        // ── 3. STEADFAST MERCHANT PORTAL: Fraud check ──
        $sfFraud = null;
        try {
            $sf = $this->fraudCheckSteadfast($phone);
            if ($sf && !isset($sf['error'])) {
                $sfFraud = $sf;
                $apiNotes[] = 'Steadfast fraud check: OK (delivered: ' . ($sf['total_delivered'] ?? 0) . ', cancelled: ' . ($sf['total_cancelled'] ?? 0) . ')';
            } else {
                $apiNotes[] = 'Steadfast fraud check: ' . ($sf['error'] ?? 'empty');
            }
        } catch (\Throwable $e) {
            $apiNotes[] = 'Steadfast fraud: ' . $e->getMessage();
        }

        // ── 4. REDX API: Fraud check ──
        $redxFraud = null;
        try {
            $rx = $this->fraudCheckRedx($phone);
            if ($rx && !isset($rx['error'])) {
                $redxFraud = $rx;
                $apiNotes[] = 'RedX fraud check: OK (delivered: ' . ($rx['deliveredParcels'] ?? 0) . ', total: ' . ($rx['totalParcels'] ?? 0) . ')';
            } else {
                $apiNotes[] = 'RedX fraud check: ' . ($rx['error'] ?? 'empty');
            }
        } catch (\Throwable $e) {
            $apiNotes[] = 'RedX fraud: ' . $e->getMessage();
        }

        // ── 5. CALCULATE STATS per courier (merge local + API) ──
        $deliveredStatuses = ['delivered', 'Delivered', 'Payment_Invoice', 'delivered_approval_pending'];
        $cancelledStatuses = ['cancelled', 'Cancelled', 'cancelled_approval_pending'];
        $returnedStatuses  = ['returned', 'pending_return', 'Return', 'Returned', 'Return_Ongoing'];

        $courierCards = [];
        foreach (['Pathao', 'Steadfast', 'RedX'] as $cKey) {
            $orders = $byC[$cKey];
            $s = ['total' => count($orders), 'success' => 0, 'cancelled' => 0, 'returned' => 0, 'pending' => 0, 'api_synced' => 0];
            foreach ($orders as $o) {
                $status = $o['courier_status'] ?? $o['order_status'];
                if (in_array($status, $deliveredStatuses))     $s['success']++;
                elseif (in_array($status, $cancelledStatuses)) $s['cancelled']++;
                elseif (in_array($status, $returnedStatuses))  $s['returned']++;
                else                                            $s['pending']++;
            }
            $s['rate'] = $s['total'] > 0 ? round(($s['success'] / $s['total']) * 100) : 0;
            $s['api_data'] = false;
            $s['data_type'] = 'local';

            // Merge API fraud data (cross-merchant, higher counts = more accurate)
            if ($cKey === 'Pathao' && $pathaoFraud) {
                $showCount = $pathaoFraud['show_count'] ?? true;
                $s['api_data'] = true;
                $s['api_source']     = $pathaoFraud['source'] ?? 'pathao';
                $s['pathao_rating']  = $pathaoFraud['customer_rating'] ?? null;
                $s['pathao_risk']    = $pathaoFraud['risk_level'] ?? null;
                $s['pathao_message'] = $pathaoFraud['message'] ?? null;
                
                if ($showCount) {
                    // v1: has actual delivery counts
                    $apiTotal   = intval($pathaoFraud['total_delivery'] ?? 0);
                    $apiSuccess = intval($pathaoFraud['successful_delivery'] ?? 0);
                    $apiCancel  = intval($pathaoFraud['cancel'] ?? 0);
                    $s['api_total']   = $apiTotal;
                    $s['api_success'] = $apiSuccess;
                    $s['api_cancel']  = $apiCancel;
                    if ($apiTotal > $s['total']) {
                        $s['total']     = $apiTotal;
                        $s['success']   = $apiSuccess;
                        $s['cancelled'] = $apiCancel;
                        $s['rate']      = $apiTotal > 0 ? round(($apiSuccess / $apiTotal) * 100) : 0;
                        $s['data_type'] = 'api';
                    }
                } else {
                    // v2: rating only, no counts — mark as rating mode
                    $s['data_type'] = 'rating';
                    // Map rating to estimated success rate for display
                    $ratingRates = [
                        'excellent_customer' => 95,
                        'good_customer'      => 80,
                        'moderate_customer'  => 55,
                        'risky_customer'     => 25,
                        'new_customer'       => 0,
                    ];
                    $s['rate'] = $ratingRates[$pathaoFraud['customer_rating'] ?? ''] ?? 50;
                }
            }
            if ($cKey === 'Steadfast' && $sfFraud) {
                $apiDelivered  = intval($sfFraud['total_delivered'] ?? 0);
                $apiCancelled  = intval($sfFraud['total_cancelled'] ?? 0);
                $apiTotal      = $apiDelivered + $apiCancelled;
                $s['api_data'] = true;
                $s['api_total']     = $apiTotal;
                $s['api_success']   = $apiDelivered;
                $s['api_cancel']    = $apiCancelled;
                $s['api_source']    = 'steadfast_portal';
                if ($apiTotal > $s['total']) {
                    $s['total']     = $apiTotal;
                    $s['success']   = $apiDelivered;
                    $s['cancelled'] = $apiCancelled;
                    $s['rate']      = $apiTotal > 0 ? round(($apiDelivered / $apiTotal) * 100) : 0;
                    $s['data_type'] = 'api';
                }
            }
            if ($cKey === 'RedX' && $redxFraud) {
                $apiDelivered = intval($redxFraud['deliveredParcels'] ?? 0);
                $apiTotal     = intval($redxFraud['totalParcels'] ?? 0);
                $apiCancel    = $apiTotal - $apiDelivered;
                $s['api_data'] = true;
                $s['api_total']   = $apiTotal;
                $s['api_success'] = $apiDelivered;
                $s['api_cancel']  = $apiCancel;
                $s['api_source']  = 'redx_api';
                if ($apiTotal > $s['total']) {
                    $s['total']     = $apiTotal;
                    $s['success']   = $apiDelivered;
                    $s['cancelled'] = $apiCancel;
                    $s['rate']      = $apiTotal > 0 ? round(($apiDelivered / $apiTotal) * 100) : 0;
                    $s['data_type'] = 'api';
                }
            }

            $courierCards[$cKey] = $s;
        }

        // ── 6. OVERALL STATS ──
        $localTotal  = count($localAll);
        $totalOrders = 0; $totalSuccess = 0; $totalCancel = 0; $totalReturn = 0;
        foreach (['Pathao', 'Steadfast', 'RedX'] as $cKey) {
            $cs = $courierCards[$cKey];
            // Skip rating-only data from totals (v2 Pathao returns 0/0/0)
            if (($cs['data_type'] ?? '') === 'rating') continue;
            $totalOrders  += $cs['total'];
            $totalSuccess += $cs['success'];
            $totalCancel  += $cs['cancelled'];
            $totalReturn  += $cs['returned'] ?? 0;
        }
        // Add Other courier orders
        foreach ($byC['Other'] as $o) {
            $totalOrders++;
            $status = $o['courier_status'] ?? $o['order_status'];
            if (in_array($status, $deliveredStatuses)) $totalSuccess++;
            elseif (in_array($status, $cancelledStatuses)) $totalCancel++;
            elseif (in_array($status, $returnedStatuses)) $totalReturn++;
        }
        $overallRate = $totalOrders > 0 ? round(($totalSuccess / $totalOrders) * 100) : 0;

        $totalSpent = 0; $firstOrder = null; $lastOrder = null;
        foreach ($localAll as $o) {
            if ($o['order_status'] === 'delivered') $totalSpent += floatval($o['total']);
            if (!$firstOrder || $o['created_at'] < $firstOrder) $firstOrder = $o['created_at'];
            if (!$lastOrder || $o['created_at'] > $lastOrder) $lastOrder = $o['created_at'];
        }

        // Web cancels
        $webCancels = 0;
        try {
            $wc = $this->db->fetch("SELECT COUNT(*) as cnt FROM orders WHERE customer_phone LIKE ? AND order_status='cancelled'", [$phoneLike]);
            $webCancels = intval($wc['cnt'] ?? 0);
        } catch (\Throwable $e) {}

        // ── 7. RISK ASSESSMENT ──
        $pr = $pathaoFraud['customer_rating'] ?? null;
        $pathaoV2 = ($pathaoFraud['show_count'] ?? true) === false;
        
        if ($totalOrders === 0 && !$pathaoFraud && !$sfFraud && !$redxFraud) {
            $risk = 'new'; $label = 'New Customer';
        } elseif ($pr && $pathaoV2 && $totalOrders === 0) {
            // Pathao v2: rating only, no counts — use rating directly
            $ratingMap = [
                'excellent_customer' => ['low',    'Excellent (Pathao)'],
                'good_customer'      => ['low',    'Good (Pathao)'],
                'moderate_customer'  => ['medium', 'Moderate (Pathao)'],
                'risky_customer'     => ['high',   'High Risk (Pathao)'],
                'new_customer'       => ['new',    'New Customer'],
            ];
            [$risk, $label] = $ratingMap[$pr] ?? ['new', 'New Customer'];
        } elseif ($totalOrders === 0 && ($pathaoFraud || $sfFraud || $redxFraud)) {
            // Has API data with counts but no local orders
            if ($overallRate >= 70) { $risk = 'low';    $label = 'Trusted (Courier Data)'; }
            elseif ($overallRate >= 40) { $risk = 'medium'; $label = 'Moderate (Courier Data)'; }
            else { $risk = 'high'; $label = 'High Risk (Courier Data)'; }
        } else {
            if ($overallRate >= 80)    { $risk = 'low';    $label = 'Trusted Customer'; }
            elseif ($overallRate >= 50) { $risk = 'medium'; $label = 'Moderate Risk'; }
            else                        { $risk = 'high';   $label = 'High Risk'; }
        }
        
        // Pathao v2 rating can upgrade risk for customers with some local orders
        if ($pr && $totalOrders > 0) {
            if ($pr === 'excellent_customer' && $risk === 'medium') {
                $risk = 'low'; $label .= ' ✓ Pathao Excellent';
            } elseif ($pr === 'good_customer' && $risk === 'high') {
                $risk = 'medium'; $label .= ' ↑ Pathao Good';
            }
        }

        // Pathao risky flag override
        if ($pr === 'risky_customer' && $risk !== 'high') {
            $risk = 'high'; $label .= ' ⚠ Pathao Flagged';
        }
        if ($webCancels >= 3 && $risk !== 'high') {
            $risk = 'high'; $label .= ' ⚠ Cancel: ' . $webCancels;
        }

        // Blocked check
        $blocked = null;
        try { $blocked = $this->db->fetch("SELECT id, reason FROM blocked_phones WHERE phone LIKE ?", [$phoneLike]); } catch (\Throwable $e) {}

        // Order areas
        $areas = [];
        try {
            $areas = $this->db->fetchAll("
                SELECT COALESCE(NULLIF(customer_district,''), COALESCE(NULLIF(customer_city,''),'Unknown')) as area_name,
                       COUNT(*) as cnt
                FROM orders WHERE customer_phone LIKE ?
                GROUP BY area_name ORDER BY cnt DESC LIMIT 5
            ", [$phoneLike]);
        } catch (\Throwable $e) {}

        // Build pathao_rating for backward compatibility
        $pathaoRating = null;
        if ($pathaoFraud) {
            $pathaoRating = [
                'customer_rating' => $pathaoFraud['customer_rating'] ?? null,
                'risk_level'      => $pathaoFraud['risk_level'] ?? null,
                'message'         => $pathaoFraud['message'] ?? null,
                'total'           => intval($pathaoFraud['total_delivery'] ?? 0),
                'success'         => intval($pathaoFraud['successful_delivery'] ?? 0),
                'cancel'          => intval($pathaoFraud['cancel'] ?? 0),
                'source'          => $pathaoFraud['source'] ?? 'pathao',
                'show_count'      => $pathaoFraud['show_count'] ?? true,
                'version'         => $pathaoFraud['version'] ?? 'v1',
            ];
        }

        return [
            'phone'        => $phone,
            'total_orders' => $totalOrders,
            'delivered'    => $totalSuccess,
            'cancelled'    => $totalCancel,
            'returned'     => $totalReturn,
            'success_rate' => $overallRate,
            'total_spent'  => $totalSpent,
            'first_order'  => $firstOrder,
            'last_order'   => $lastOrder,
            'risk_level'   => $risk,
            'risk_label'   => $label,
            'is_blocked'   => !empty($blocked),
            'block_reason' => $blocked['reason'] ?? null,
            'pathao_rating'=> $pathaoRating,
            'couriers'     => $courierCards,
            'local_orders' => $localTotal,
            'web_cancels'  => $webCancels,
            'api_synced'   => 0,
            'api_notes'    => $apiNotes,
            'areas'        => $areas,
            // Raw API responses for debugging
            'fraud_data'   => [
                'pathao'    => $pathaoFraud,
                'steadfast' => $sfFraud,
                'redx'      => $redxFraud,
            ],
        ];
    }


    // ========================
    // AREA STATS FOR DASHBOARD
    // ========================
    public function getAreaStats($days = 90) {
        // First ensure name columns exist (safe migration)
        foreach (['delivery_city_name VARCHAR(100) DEFAULT NULL', 'delivery_zone_name VARCHAR(100) DEFAULT NULL', 'delivery_area_name VARCHAR(100) DEFAULT NULL'] as $colDef) {
            try { $this->db->query("ALTER TABLE orders ADD COLUMN {$colDef}"); } catch (\Throwable $e) {}
        }
        try {
            return $this->db->fetchAll("
                SELECT 
                    COALESCE(
                        NULLIF(delivery_area_name,''),
                        NULLIF(delivery_zone_name,''),
                        NULLIF(delivery_city_name,''),
                        NULLIF(customer_district,''),
                        NULLIF(customer_city,''),
                        'Unknown'
                    ) as area_name,
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN order_status IN ('cancelled','returned') THEN 1 ELSE 0 END) as failed,
                    COALESCE(SUM(total), 0) as revenue
                FROM orders
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY area_name
                ORDER BY total_orders DESC
                LIMIT 25
            ", [$days]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get city-level stats for Bangladesh map visualization.
     * Groups by delivery_city_name (Pathao city), falls back to customer_district.
     */
    public function getCityStats($days = 90) {
        foreach (['delivery_city_name VARCHAR(100) DEFAULT NULL', 'delivery_zone_name VARCHAR(100) DEFAULT NULL', 'delivery_area_name VARCHAR(100) DEFAULT NULL'] as $colDef) {
            try { $this->db->query("ALTER TABLE orders ADD COLUMN {$colDef}"); } catch (\Throwable $e) {}
        }
        try {
            return $this->db->fetchAll("
                SELECT 
                    COALESCE(
                        NULLIF(delivery_city_name,''),
                        NULLIF(customer_district,''),
                        NULLIF(customer_city,''),
                        'Unknown'
                    ) as city_name,
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN order_status IN ('cancelled','returned') THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN order_status IN ('cancelled','returned') THEN 1 ELSE 0 END) as returned,
                    ROUND(
                        SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0), 1
                    ) as success_rate,
                    ROUND(
                        SUM(CASE WHEN order_status IN ('cancelled','returned') THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0), 1
                    ) as return_rate,
                    COALESCE(SUM(total), 0) as revenue
                FROM orders
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY city_name
                HAVING city_name != 'Unknown'
                ORDER BY total_orders DESC
            ", [$days]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ========================
    // ADDRESS VERIFICATION
    // ========================
    public function verifyAddress($cityId, $zoneId = 0, $areaId = 0) {
        $errors = [];
        $cityName = $zoneName = $areaName = '';

        if ($cityId) {
            $cities = $this->getCities();
            $cityList = $cities['data']['data'] ?? $cities['data'] ?? [];
            $found = false;
            foreach ($cityList as $c) {
                if (($c['city_id'] ?? 0) == $cityId) { $found = true; $cityName = $c['city_name'] ?? ''; break; }
            }
            if (!$found) $errors[] = 'Invalid city ID';
        }

        if ($cityId && $zoneId && empty($errors)) {
            $zones = $this->getZones($cityId);
            $zoneList = $zones['data']['data'] ?? $zones['data'] ?? [];
            $found = false;
            foreach ($zoneList as $z) {
                if (($z['zone_id'] ?? 0) == $zoneId) { $found = true; $zoneName = $z['zone_name'] ?? ''; break; }
            }
            if (!$found) $errors[] = 'Invalid zone for selected city';
        }

        if ($zoneId && $areaId && empty($errors)) {
            $areas = $this->getAreas($zoneId);
            $areaList = $areas['data']['data'] ?? $areas['data'] ?? [];
            $found = false;
            foreach ($areaList as $a) {
                if (($a['area_id'] ?? 0) == $areaId) { $found = true; $areaName = $a['area_name'] ?? ''; break; }
            }
            if (!$found) $errors[] = 'Invalid area for selected zone';
        }

        return [
            'verified' => empty($errors),
            'errors' => $errors,
            'city_name' => $cityName,
            'zone_name' => $zoneName,
            'area_name' => $areaName,
        ];
    }

    // ========================
    // HELPERS
    // ========================
    public static function normalizePhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (substr($phone, 0, 2) === '88') $phone = substr($phone, 2);
        if (substr($phone, 0, 1) !== '0') $phone = '0' . $phone;
        return $phone;
    }

    private function authed($method, $path, $data = []) {
        $token = $this->getAccessToken();
        if (!$token) throw new Exception('Pathao auth failed');
        return $this->http($method, $path, $data, true);
    }

    private function http($method, $path, $data = [], $auth = true) {
        // Rate limit check (100 req/min for Pathao)
        if (!courierRateCheck('pathao', courierRateLimit('pathao'))) {
            throw new Exception('Pathao rate limit reached (100/min) — try again shortly');
        }

        $url = $this->baseUrl . $path;
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($auth && $this->accessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        // Execute with exponential backoff retry on 429/503
        $result = courierCurlExec($ch, 'pathao', "{$method} {$path}", 5);
        curl_close($ch);

        $response = $result['response'];
        $httpCode = $result['http_code'];
        $error    = $result['error'];

        if ($error) throw new Exception("Curl error: {$error}");

        // Token expired — try refreshing once
        if ($httpCode === 401 && $auth) {
            $this->accessToken = null;
            $this->tokenExpiry = 0;
            $token = $this->getAccessToken(true);
            if ($token) {
                // Retry the request with new token
                $headers = array_map(function($h) use ($token) {
                    return strpos($h, 'Authorization:') === 0 ? 'Authorization: Bearer ' . $token : $h;
                }, $headers);
                $ch2 = curl_init();
                curl_setopt_array($ch2, [
                    CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false,
                ]);
                if ($method === 'POST') {
                    curl_setopt($ch2, CURLOPT_POST, true);
                    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($data));
                }
                $result2 = courierCurlExec($ch2, 'pathao', "{$method} {$path} (token-refresh)", 2);
                curl_close($ch2);
                $response = $result2['response'];
                $httpCode = $result2['http_code'];
            }
        }

        // Safe log - silently fail if table doesn't exist
        try {
            $this->db->insert('pathao_api_logs', [
                'method' => $method,
                'endpoint' => $path,
                'http_code' => $httpCode,
                'response_summary' => substr($response ?? '', 0, 500),
            ]);
        } catch (Exception $e) {
            // Table may not exist yet - that's OK
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && !empty($response)) {
            throw new Exception("Invalid JSON from Pathao (HTTP {$httpCode}): " . substr($response, 0, 200));
        }

        return $decoded ?: [];
    }
}
