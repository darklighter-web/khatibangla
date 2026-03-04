<?php
/**
 * Clerk Authentication Integration for PHP
 *
 * Bridges Clerk JS frontend with existing PHP session system.
 * - Verifies Clerk session JWTs via JWKS (RS256) — no per-request API call
 * - Falls back to Clerk Backend API verification if JWKS fails
 * - Syncs Clerk users to local `customers` table
 * - Maintains backward-compatible PHP sessions
 * - Compatible with CyberPanel / LiteSpeed / OPcache
 */

// ─── Hardcoded Clerk Credentials ───────────────────────────────────────────
define('CLERK_PUBLISHABLE_KEY', 'pk_live_Y2xlcmsua2hhdGliYW5nbGEuY29tJA');
define('CLERK_SECRET_KEY',      'sk_live_kuUyTh25xVPLANJECJb2LEQgv1EsdzgVr315HinTab');
define('CLERK_WEBHOOK_SECRET',  'whsec_gDA5oFRvZnG4IKTIa08RgSh8l/76m8kO');
// ───────────────────────────────────────────────────────────────────────────

/**
 * Get Clerk configuration — hardcoded, no DB lookup needed
 */
function getClerkConfig(): array {
    static $config = null;
    if ($config !== null) return $config;

    $config = [
        'enabled'         => true,
        'publishable_key' => CLERK_PUBLISHABLE_KEY,
        'secret_key'      => CLERK_SECRET_KEY,
        'webhook_secret'  => CLERK_WEBHOOK_SECRET,
        'sign_in_url'     => '/login',
        'after_sign_in'   => '/account',
        'after_sign_up'   => '/account',
        'social_google'   => true,
        'social_facebook' => true,
        'social_phone'    => true,
        'keep_legacy'     => true,
    ];
    return $config;
}

function isClerkEnabled(): bool {
    $c = getClerkConfig();
    return $c['enabled'] && !empty($c['publishable_key']) && !empty($c['secret_key']);
}

function getClerkPublishableKey(): string {
    return getClerkConfig()['publishable_key'];
}

/**
 * Extract the Clerk instance domain from publishable key.
 * e.g. pk_live_Y2xlcmsua2hhdGliYW5nbGEuY29tJA → clerk.khatibangla.com
 */
function getClerkInstanceDomain(): string {
    $pk = getClerkPublishableKey();
    if (empty($pk)) return '';
    
    // Format: pk_live_BASE64 or pk_test_BASE64
    // Decode the base64 part (after pk_live_ or pk_test_)
    $parts = explode('_', $pk, 3);
    if (count($parts) < 3) return '';
    
    $decoded = base64_decode($parts[2], true);
    if ($decoded === false) return '';
    
    // Remove trailing $ if present
    return rtrim($decoded, '$');
}

/**
 * ═══════════════════════════════════════════════════════════════
 * JWT VERIFICATION (Secure approach)
 * 
 * Clerk issues RS256 JWTs. We verify them using Clerk's JWKS endpoint.
 * This is the recommended secure approach — no per-request API call needed.
 * ═══════════════════════════════════════════════════════════════
 */

/**
 * Fetch and cache Clerk's public JWKS keys.
 * Cached in PHP's static variable + file cache for performance.
 */
function getClerkJWKS(): ?array {
    static $jwks = null;
    if ($jwks !== null) return $jwks;
    
    // File cache (5 minute TTL)
    $cacheFile = sys_get_temp_dir() . '/clerk_jwks_' . md5(getClerkPublishableKey()) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && !empty($cached['keys'])) {
            $jwks = $cached;
            return $jwks;
        }
    }
    
    $domain = getClerkInstanceDomain();
    if (empty($domain)) {
        error_log('[Clerk] Cannot determine JWKS URL — invalid publishable key');
        return null;
    }
    
    $url = "https://{$domain}/.well-known/jwks.json";
    $response = clerkHttpGet($url);
    
    if ($response === null) {
        error_log("[Clerk] Failed to fetch JWKS from $url");
        return null;
    }
    
    $data = json_decode($response, true);
    if (empty($data['keys'])) {
        error_log("[Clerk] JWKS response has no keys");
        return null;
    }
    
    // Cache to file
    @file_put_contents($cacheFile, json_encode($data));
    
    $jwks = $data;
    return $jwks;
}

/**
 * Verify a Clerk session token (JWT) and return the claims payload.
 * Returns null if invalid or expired.
 */
function verifyClerkToken(string $token): ?array {
    if (empty($token)) return null;
    
    // Split JWT into parts
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    
    [$headerB64, $payloadB64, $signatureB64] = $parts;
    
    // Decode header to get key ID (kid) and algorithm
    $header = json_decode(base64UrlDecode($headerB64), true);
    if (empty($header['alg']) || empty($header['kid'])) return null;
    
    // We only support RS256 (Clerk's default)
    if ($header['alg'] !== 'RS256') {
        error_log("[Clerk] Unsupported JWT algorithm: {$header['alg']}");
        return null;
    }
    
    // Decode payload
    $payload = json_decode(base64UrlDecode($payloadB64), true);
    if (!is_array($payload)) return null;
    
    // Check expiry
    $now = time();
    if (isset($payload['exp']) && $payload['exp'] < $now) {
        error_log("[Clerk] JWT expired at " . date('c', $payload['exp']));
        return null;
    }
    
    // Check not-before
    if (isset($payload['nbf']) && $payload['nbf'] > $now + 60) {
        error_log("[Clerk] JWT not yet valid (nbf)");
        return null;
    }
    
    // Get the matching public key from JWKS
    $jwks = getClerkJWKS();
    if ($jwks === null) {
        // JWKS fetch failed — fall back to API verification
        return null;
    }
    
    $publicKey = null;
    foreach ($jwks['keys'] as $key) {
        if (($key['kid'] ?? '') === $header['kid']) {
            $publicKey = jwkToPublicKey($key);
            break;
        }
    }
    
    if ($publicKey === null) {
        // Try refreshing JWKS (key may have rotated)
        $cacheFile = sys_get_temp_dir() . '/clerk_jwks_' . md5(getClerkPublishableKey()) . '.json';
        @unlink($cacheFile);
        
        $jwks = getClerkJWKS();
        if ($jwks) {
            foreach ($jwks['keys'] as $key) {
                if (($key['kid'] ?? '') === $header['kid']) {
                    $publicKey = jwkToPublicKey($key);
                    break;
                }
            }
        }
    }
    
    if ($publicKey === null) {
        error_log("[Clerk] No matching public key found for kid: {$header['kid']}");
        return null;
    }
    
    // Verify RS256 signature
    $signingInput = $headerB64 . '.' . $payloadB64;
    $signature = base64UrlDecode($signatureB64);
    
    $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    
    if ($verified !== 1) {
        error_log("[Clerk] JWT signature verification failed (openssl_verify returned $verified)");
        return null;
    }
    
    return $payload;
}

/**
 * Convert a JWK (JSON Web Key) RSA entry to a PEM public key string.
 */
function jwkToPublicKey(array $jwk) {
    if (($jwk['kty'] ?? '') !== 'RSA') return null;
    if (empty($jwk['n']) || empty($jwk['e'])) return null;

    // Build RSA public key PEM via ASN.1 encoding (works on all PHP versions)
    $modulus  = base64UrlDecode($jwk['n']);
    $exponent = base64UrlDecode($jwk['e']);

    $modSeq  = "\x02" . encodeLength(strlen($modulus) + 1) . "\x00" . $modulus;
    $expSeq  = "\x02" . encodeLength(strlen($exponent)) . $exponent;
    $seqData = $modSeq . $expSeq;
    $seq     = "\x30" . encodeLength(strlen($seqData)) . $seqData;

    $algorithmId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $bitString   = "\x03" . encodeLength(strlen($seq) + 1) . "\x00" . $seq;
    $der         = "\x30" . encodeLength(strlen($algorithmId) + strlen($bitString)) . $algorithmId . $bitString;

    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64) . "-----END PUBLIC KEY-----";

    // PHP 8.x: openssl_pkey_get_public() returns OpenSSLAsymmetricKey object.
    // openssl_verify() accepts the object directly — no need to convert to PEM string.
    $key = openssl_pkey_get_public($pem);
    return ($key !== false) ? $key : null;
}

function encodeLength(int $length): string {
    if ($length <= 0x7f) return chr($length);
    $temp = ltrim(pack('N', $length), chr(0));
    return chr(0x80 | strlen($temp)) . $temp;
}

function base64UrlDecode(string $input): string {
    $remainder = strlen($input) % 4;
    if ($remainder) $input .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($input, '-_', '+/'), true) ?: '';
}

/**
 * ═══════════════════════════════════════════════════════════════
 * CLERK BACKEND API  
 * ═══════════════════════════════════════════════════════════════
 */

/**
 * Simple HTTP GET helper (curl + file_get_contents fallback)
 */
function clerkHttpGet(string $url, array $headers = []): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!$err && $code >= 200 && $code < 300) return $response;
    }
    
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'header'  => implode("\r\n", $headers),
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        return @file_get_contents($url, false, $ctx) ?: null;
    }
    
    return null;
}

/**
 * Call Clerk Backend API — uses curl, falls back to file_get_contents
 */
function clerkApiCall(string $endpoint, string $method = 'GET', ?array $body = null): ?array {
    $config = getClerkConfig();
    if (empty($config['secret_key'])) {
        error_log('[Clerk] API: No secret key configured');
        return null;
    }
    
    $url = 'https://api.clerk.com/v1/' . ltrim($endpoint, '/');
    $authHeader = 'Authorization: Bearer ' . $config['secret_key'];
    
    $response = null;
    $httpCode = 0;
    
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $curlHeaders = [$authHeader, 'Content-Type: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) { $response = null; }
    }
    
    if ($response === null && ini_get('allow_url_fopen')) {
        $opts = ['http' => [
            'method'  => $method,
            'header'  => $authHeader . "\r\nContent-Type: application/json",
            'timeout' => 15,
            'ignore_errors' => true,
        ]];
        if ($body && $method === 'POST') $opts['http']['content'] = json_encode($body);
        $ctx = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $m)) $httpCode = (int)$m[1];
            }
        }
    }
    
    if (!$response) return null;
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("[Clerk] API HTTP $httpCode for $endpoint: " . substr($response, 0, 300));
        return null;
    }
    
    return json_decode($response, true);
}

/**
 * Fetch Clerk user details by user ID via Backend API
 */
function fetchClerkUser(string $clerkUserId): ?array {
    if (empty($clerkUserId)) return null;
    $result = clerkApiCall('users/' . urlencode($clerkUserId));
    return ($result && isset($result['id'])) ? $result : null;
}

/**
 * ═══════════════════════════════════════════════════════════════
 * WEBHOOK SIGNATURE VERIFICATION (Svix)
 * ═══════════════════════════════════════════════════════════════
 */

/**
 * Verify a Clerk webhook payload using Svix signature.
 * Returns the verified payload array, or null on failure.
 */
function verifyClerkWebhook(string $rawBody, array $requestHeaders): ?array {
    $webhookSecret = getClerkConfig()['webhook_secret'];
    
    if (empty($webhookSecret)) {
        // If no secret configured, skip verification (development mode)
        error_log('[Clerk] Webhook: No webhook_secret configured — skipping signature verification');
        return json_decode($rawBody, true);
    }
    
    // Svix header names (case-insensitive — normalize to lowercase)
    $headers = array_change_key_case($requestHeaders, CASE_LOWER);
    
    $svixId        = $headers['svix-id'] ?? $headers['http_svix-id'] ?? null;
    $svixTimestamp = $headers['svix-timestamp'] ?? $headers['http_svix-timestamp'] ?? null;
    $svixSignature = $headers['svix-signature'] ?? $headers['http_svix-signature'] ?? null;
    
    // Also check $_SERVER keys (HTTP_ prefixed, underscore format)
    if (!$svixId)        $svixId        = $_SERVER['HTTP_SVIX_ID'] ?? null;
    if (!$svixTimestamp) $svixTimestamp = $_SERVER['HTTP_SVIX_TIMESTAMP'] ?? null;
    if (!$svixSignature) $svixSignature = $_SERVER['HTTP_SVIX_SIGNATURE'] ?? null;
    
    if (!$svixId || !$svixTimestamp || !$svixSignature) {
        error_log('[Clerk] Webhook: Missing Svix headers');
        return null;
    }
    
    // Reject if timestamp is too old (5 minutes)
    $now = time();
    $ts = (int)$svixTimestamp;
    if (abs($now - $ts) > 300) {
        error_log("[Clerk] Webhook: Timestamp too old ($ts vs $now)");
        return null;
    }
    
    // Build the signed content
    $signedContent = "{$svixId}.{$svixTimestamp}.{$rawBody}";
    
    // Decode the webhook secret (strip "whsec_" prefix and base64-decode)
    $secretBase64 = preg_replace('/^whsec_/', '', $webhookSecret);
    $secretBytes = base64_decode($secretBase64, true);
    if ($secretBytes === false) {
        error_log('[Clerk] Webhook: Invalid webhook secret format');
        return null;
    }
    
    // Compute expected signature
    $expectedSig = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));
    
    // Compare against all provided signatures (v1,sig1 v1,sig2 ...)
    $providedSigs = explode(' ', $svixSignature);
    $verified = false;
    foreach ($providedSigs as $sig) {
        // Format: "v1,<base64>"
        $sigParts = explode(',', $sig, 2);
        if (count($sigParts) === 2 && hash_equals($expectedSig, $sigParts[1])) {
            $verified = true;
            break;
        }
    }
    
    if (!$verified) {
        error_log('[Clerk] Webhook: Signature mismatch — possible spoofed webhook');
        return null;
    }
    
    return json_decode($rawBody, true);
}

/**
 * ═══════════════════════════════════════════════════════════════
 * USER SYNC
 * ═══════════════════════════════════════════════════════════════
 */

/**
 * Extract usable data from Clerk user object
 */
function parseClerkUser(array $clerkUser): array {
    $name = trim(($clerkUser['first_name'] ?? '') . ' ' . ($clerkUser['last_name'] ?? ''));
    if (empty($name)) $name = $clerkUser['username'] ?? 'Customer';
    
    // Get primary email
    $email = '';
    $primaryEmailId = $clerkUser['primary_email_address_id'] ?? '';
    foreach ($clerkUser['email_addresses'] ?? [] as $ea) {
        if ($ea['id'] === $primaryEmailId) { $email = $ea['email_address'] ?? ''; break; }
    }
    if (empty($email) && !empty($clerkUser['email_addresses'])) {
        $email = $clerkUser['email_addresses'][0]['email_address'] ?? '';
    }
    
    // Get primary phone
    $phone = '';
    $primaryPhoneId = $clerkUser['primary_phone_number_id'] ?? '';
    foreach ($clerkUser['phone_numbers'] ?? [] as $pn) {
        if ($pn['id'] === $primaryPhoneId) { $phone = $pn['phone_number'] ?? ''; break; }
    }
    if (empty($phone) && !empty($clerkUser['phone_numbers'])) {
        $phone = $clerkUser['phone_numbers'][0]['phone_number'] ?? '';
    }
    
    // Normalize BD phone: +8801XXXXXXXXX → 01XXXXXXXXX
    if (preg_match('/^\+880(\d{10,11})$/', $phone, $m)) {
        $phone = '0' . $m[1];
    }
    
    return [
        'clerk_id' => $clerkUser['id'],
        'name'     => $name,
        'email'    => $email,
        'phone'    => $phone,
        'image'    => $clerkUser['image_url'] ?? $clerkUser['profile_image_url'] ?? '',
    ];
}

/**
 * Sync a Clerk user to the local customers table.
 * Creates new customer or links to existing by phone/email.
 * Returns local customer ID.
 */
function ensureClerkColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = Database::getInstance();
    // Silently add clerk_id and clerk_image columns if they don't exist yet
    try { $db->query("ALTER TABLE customers ADD COLUMN clerk_id VARCHAR(255) NULL DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $db->query("ALTER TABLE customers ADD COLUMN clerk_image VARCHAR(512) NULL DEFAULT NULL"); } catch (\Throwable $e) {}
    // Add index on clerk_id if not exists
    try { $db->query("ALTER TABLE customers ADD INDEX idx_clerk_id (clerk_id)"); } catch (\Throwable $e) {}
}

function syncClerkUser(array $clerkUserData): int {
    ensureClerkColumns();
    $db = Database::getInstance();
    $parsed = parseClerkUser($clerkUserData);
    $clerkId = $parsed['clerk_id'];
    
    // 1. Already linked by clerk_id
    $existing = $db->fetch("SELECT id, name, phone FROM customers WHERE clerk_id = ?", [$clerkId]);
    if ($existing) {
        $db->update('customers', [
            'name'        => $parsed['name'] ?: $existing['name'],
            'email'       => $parsed['email'] ?: null,
            'clerk_image' => $parsed['image'] ?: null,
        ], 'id = ?', [$existing['id']]);
        return (int)$existing['id'];
    }
    
    // 2. Link by phone number
    if (!empty($parsed['phone'])) {
        $byPhone = $db->fetch("SELECT id FROM customers WHERE phone = ? AND (clerk_id IS NULL OR clerk_id = '')", [$parsed['phone']]);
        if ($byPhone) {
            $db->update('customers', [
                'clerk_id'    => $clerkId,
                'name'        => $parsed['name'] ?: null,
                'email'       => $parsed['email'] ?: null,
                'clerk_image' => $parsed['image'] ?: null,
            ], 'id = ?', [$byPhone['id']]);
            return (int)$byPhone['id'];
        }
    }
    
    // 3. Link by email
    if (!empty($parsed['email'])) {
        $byEmail = $db->fetch("SELECT id FROM customers WHERE email = ? AND (clerk_id IS NULL OR clerk_id = '') AND email != ''", [$parsed['email']]);
        if ($byEmail) {
            $db->update('customers', [
                'clerk_id'    => $clerkId,
                'name'        => $parsed['name'] ?: null,
                'phone'       => $parsed['phone'] ?: null,
                'clerk_image' => $parsed['image'] ?: null,
            ], 'id = ?', [$byEmail['id']]);
            return (int)$byEmail['id'];
        }
    }
    
    // 4. Create new customer
    // phone cannot be NULL in the customers table — use a unique placeholder
    // for social login users who have no phone (Google, Facebook, etc.)
    $phone = $parsed['phone'] ?: substr($clerkId, 0, 15);
    return (int)$db->insert('customers', [
        'clerk_id'    => $clerkId,
        'name'        => $parsed['name'],
        'phone'       => $phone,
        'email'       => $parsed['email'] ?: null,
        'clerk_image' => $parsed['image'] ?: null,
        'ip_address'  => getClientIP(),
    ]);
}

/**
 * Set PHP session from Clerk user (backward compat bridge)
 */
function setSessionFromClerk(int $customerId, array $parsed): void {
    $_SESSION['customer_id']    = $customerId;
    $_SESSION['customer_name']  = $parsed['name'];
    $_SESSION['customer_phone'] = $parsed['phone'];
    $_SESSION['clerk_user_id']  = $parsed['clerk_id'];
    $_SESSION['clerk_image']    = $parsed['image'] ?? '';
}

function isClerkSession(): bool {
    return !empty($_SESSION['clerk_user_id']);
}

function getClerkImage(): string {
    return $_SESSION['clerk_image'] ?? '';
}

/**
 * ═══════════════════════════════════════════════════════════════
 * FRONTEND RENDERING
 * ═══════════════════════════════════════════════════════════════
 */

/**
 * Render Clerk JS SDK script tag (in <head>)
 */
function renderClerkHead(): string {
    if (!isClerkEnabled()) return '';
    $pk = htmlspecialchars(getClerkPublishableKey(), ENT_QUOTES);
    
    return <<<HTML
    <!-- Clerk JS SDK -->
    <script
        async
        crossorigin="anonymous"
        data-clerk-publishable-key="{$pk}"
        src="https://cdn.jsdelivr.net/npm/@clerk/clerk-js@latest/dist/clerk.browser.js"
        type="text/javascript"
    ></script>
HTML;
}

/**
 * Render Clerk JS session bridge (before </body>)
 * 
 * KEY CHANGE: Now sends session token (JWT) instead of just clerk_user_id.
 * The backend verifies the JWT cryptographically — much more secure.
 */
function renderClerkInit(): string {
    if (!isClerkEnabled()) return '';
    
    $syncUrl = rtrim(SITE_URL, '/') . '/api/clerk-sync.php';
    
    return <<<HTML
    <script>
    // Clerk → PHP session bridge
    window.__clerkSyncUrl = '{$syncUrl}';
    
    async function clerkSyncSession() {
        try {
            if (!window.Clerk || !window.Clerk.user) return false;
            
            // Get the actual session JWT token (secure — backend will verify this)
            const token = await window.Clerk.session.getToken();
            if (!token) return false;
            
            const res = await fetch(window.__clerkSyncUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ 
                    action: 'sync',
                    token: token,
                    clerk_user_id: window.Clerk.user.id  // kept for fallback
                })
            });
            const data = await res.json();
            return data.success || false;
        } catch(e) { console.error('[Clerk] Sync error:', e); return false; }
    }
    
    async function clerkSignOut() {
        try {
            await fetch(window.__clerkSyncUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'logout' })
            });
            if (window.Clerk) await window.Clerk.signOut();
        } catch(e) {}
        window.location.href = '/';
    }
    </script>
HTML;
}