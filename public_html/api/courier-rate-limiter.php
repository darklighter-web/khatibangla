<?php
/**
 * Courier API Rate Limiter & Protection Helpers
 * Shared across pathao.php, steadfast.php, redx.php, courier-sync.php, fraud-checker.php
 *
 * Implements:
 *   1. Exponential backoff retry on 429/503 (max 5 retries: 1s, 2s, 4s, 8s, 16s)
 *   2. API error logging (429, 401, 500+ → tmp/api-errors.log)
 *   3. Per-courier rate limiter (token bucket: 100 req/min)
 *   4. Static data caching (cities, zones, areas → site_settings, 24h TTL)
 *   5. Per-consignment polling throttle (15 min)
 *
 * ┌─────────────────────────────────────────────────────────────────┐
 * │              SAFE RATE LIMITS PER COURIER                      │
 * ├──────────────┬──────────────────────────────────────────────────┤
 * │ Pathao       │ 100 req/min  │ 10 req/sec  │ Token: 5 days     │
 * │ Steadfast    │  80 req/min  │ 10 req/sec  │ API Key auth       │
 * │ RedX         │  80 req/min  │ 10 req/sec  │ Token: cache 12h   │
 * │ CarryBee     │  80 req/min  │ 10 req/sec  │ API Key auth       │
 * └──────────────┴──────────────┴─────────────┴────────────────────┘
 */

if (!defined('COURIER_RATE_LIMITER_LOADED')) {
    define('COURIER_RATE_LIMITER_LOADED', true);

    /**
     * Execute a cURL request with exponential backoff retry on 429/503
     *
     * @param resource $ch        cURL handle (already configured)
     * @param string   $courier   Courier name for logging (pathao|steadfast|redx)
     * @param string   $endpoint  Endpoint description for logging
     * @param int      $maxRetries Max retry attempts (default 5)
     * @return array ['response'=>string, 'http_code'=>int, 'error'=>string]
     */
    function courierCurlExec($ch, $courier = 'unknown', $endpoint = '', $maxRetries = 5) {
        $resp = null;
        $httpCode = 0;
        $curlError = '';

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 1s, 2s, 4s, 8s, 16s
                $wait = min(pow(2, $attempt - 1), 16);
                sleep($wait);
            }

            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            // Log rate limit and auth errors
            if (in_array($httpCode, [429, 401, 500, 502, 503])) {
                courierLogApiError($courier, $httpCode, $endpoint, $resp, $attempt);
            }

            // Only retry on 429 (rate limited) or 503 (service unavailable)
            if ($httpCode !== 429 && $httpCode !== 503) {
                break;
            }

            // If server tells us how long to wait, respect it
            // (cURL doesn't expose Retry-After easily, so backoff is our fallback)
        }

        return [
            'response'  => $resp,
            'http_code' => $httpCode,
            'error'     => $curlError,
            'retries'   => $attempt,
        ];
    }

    /**
     * Log API errors to tmp/api-errors.log
     * Logs: 429, 401, 500, 502, 503 responses
     */
    function courierLogApiError($courier, $httpCode, $endpoint, $response, $attempt = 0) {
        try {
            $dir = dirname(__DIR__) . '/tmp';
            @mkdir($dir, 0755, true);
            $retryNote = $attempt > 0 ? " (retry #{$attempt})" : '';
            $line = date('Y-m-d H:i:s')
                . " [{$courier}] HTTP {$httpCode} {$endpoint}{$retryNote}: "
                . substr($response ?? '', 0, 300) . "\n";
            @file_put_contents($dir . '/api-errors.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Silent fail — logging should never break the app
        }
    }

    /**
     * Per-courier rate limiter (token bucket style using DB)
     * Returns true if request is allowed, false if rate limited
     *
     * Uses site_settings to track: {courier}_rate_counter = JSON {count, window_start}
     * Window: 60 seconds. Max: configured per courier.
     *
     * @param string $courier    Courier identifier
     * @param int    $maxPerMin  Maximum requests per minute (default 100)
     * @return bool  true = allowed, false = rate limited
     */
    function courierRateCheck($courier, $maxPerMin = 100) {
        try {
            $db = Database::getInstance();
            $key = $courier . '_rate_counter';
            $now = time();

            $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
            $data = $row ? json_decode($row['setting_value'] ?? '', true) : null;

            // Reset window if expired or missing
            if (!$data || ($now - ($data['window_start'] ?? 0)) >= 60) {
                $data = ['count' => 0, 'window_start' => $now];
            }

            // Check limit
            if ($data['count'] >= $maxPerMin) {
                courierLogApiError($courier, 'RATE_LIMIT', 'self-throttled', "Blocked: {$data['count']}/{$maxPerMin} in window");
                return false;
            }

            // Increment counter
            $data['count']++;
            $json = json_encode($data);

            if ($row) {
                $db->update('site_settings', ['setting_value' => $json], 'setting_key = ?', [$key]);
            } else {
                $db->insert('site_settings', [
                    'setting_key'   => $key,
                    'setting_value' => $json,
                    'setting_type'  => 'text',
                    'setting_group' => 'courier',
                    'label'         => ucfirst($courier) . ' Rate Counter',
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            // If DB fails, allow the request (fail open)
            return true;
        }
    }

    /**
     * Check if a consignment was polled recently (within $minIntervalSec)
     * Returns true if enough time has passed, false if too soon
     *
     * @param int    $orderId         Order ID
     * @param int    $minIntervalSec  Minimum seconds between polls (default 900 = 15 min)
     * @return bool  true = safe to poll, false = too soon
     */
    function courierPollThrottle($orderId, $minIntervalSec = 900) {
        try {
            $db = Database::getInstance();
            $row = $db->fetch(
                "SELECT updated_at FROM orders WHERE id = ?",
                [$orderId]
            );
            if ($row && !empty($row['updated_at'])) {
                $lastUpdate = strtotime($row['updated_at']);
                if ($lastUpdate && (time() - $lastUpdate) < $minIntervalSec) {
                    return false; // Too soon
                }
            }
        } catch (\Throwable $e) {
            // Fail open
        }
        return true;
    }

    /**
     * Cache static courier data (cities, zones, areas, pricing) in site_settings
     * TTL: 24 hours by default
     *
     * @param string   $cacheKey   Cache key (e.g. 'pathao_cities', 'pathao_zones_1')
     * @param callable $fetcher    Function that returns data if cache miss
     * @param int      $ttl        Cache TTL in seconds (default 86400 = 24h)
     * @return mixed   Cached or freshly fetched data
     */
    function courierCacheStatic($cacheKey, callable $fetcher, $ttl = 86400) {
        try {
            $db = Database::getInstance();
            $fullKey = 'cache_' . $cacheKey;

            // Check cache
            $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$fullKey]);
            if ($row && !empty($row['setting_value'])) {
                $cached = json_decode($row['setting_value'], true);
                if ($cached && isset($cached['expires']) && $cached['expires'] > time()) {
                    return $cached['data'];
                }
            }

            // Cache miss — fetch fresh
            $data = $fetcher();

            // Store in cache
            $json = json_encode([
                'data'      => $data,
                'expires'   => time() + $ttl,
                'cached_at' => date('Y-m-d H:i:s'),
            ]);

            if ($row) {
                $db->update('site_settings', ['setting_value' => $json], 'setting_key = ?', [$fullKey]);
            } else {
                $db->insert('site_settings', [
                    'setting_key'   => $fullKey,
                    'setting_value' => $json,
                    'setting_type'  => 'text',
                    'setting_group' => 'courier',
                    'label'         => 'Cache: ' . $cacheKey,
                ]);
            }

            return $data;
        } catch (\Throwable $e) {
            // If caching fails, still return fresh data
            try { return $fetcher(); } catch (\Throwable $e2) { return null; }
        }
    }

    /**
     * Get per-courier rate limit config
     * @param string $courier
     * @return int Max requests per minute
     */
    function courierRateLimit($courier) {
        $limits = [
            'pathao'    => 100,
            'steadfast' => 80,
            'redx'      => 80,
            'carrybee'  => 80,
        ];
        return $limits[strtolower($courier)] ?? 80;
    }
}
