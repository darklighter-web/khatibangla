<?php
/**
 * Load Test Runner — Server-Side SSE Stream
 * Fires real parallel cURL requests from the server, streams results back.
 * Auth: admin session required.
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../admin/includes/auth.php';
requireAdmin();
refreshAdminPermissions();
if (!hasPermission('settings')) { header('HTTP/1.1 403 Forbidden'); echo json_encode(['error'=>'Permission denied']); exit; }
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
    exit;
}

// ── Input ────────────────────────────────────────────────────────────────────
$urls        = array_filter(array_map('trim', explode(',', $_GET['urls'] ?? '')));
$concurrency = max(1, min(500, intval($_GET['concurrency'] ?? 10)));
$duration    = max(5,  min(300, intval($_GET['duration'] ?? 20)));
$burstMode   = ($_GET['burst'] ?? '0') === '1';   // fire all at once, no cooldown
$interval    = max(0,  min(10000, intval($_GET['interval'] ?? 1000))); // ms between waves
$timeout     = max(5,  min(60, intval($_GET['timeout'] ?? 15)));       // per-request cURL timeout
$noCache     = ($_GET['nocache'] ?? '0') === '1';

if (empty($urls)) {
    die("data: " . json_encode(['error' => 'No URLs']) . "\n\n");
}

// Validate URLs are on our site only (security)
$allowedHost = parse_url(SITE_URL, PHP_URL_HOST);
foreach ($urls as $u) {
    $host = parse_url($u, PHP_URL_HOST);
    if ($host && $host !== $allowedHost) {
        die("data: " . json_encode(['error' => 'External URLs not allowed: ' . $host]) . "\n\n");
    }
}

// ── SSE headers ──────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');   // Nginx: disable buffering
header('Connection: keep-alive');
@set_time_limit($duration + 30);
@ini_set('output_buffering', 0);
@ini_set('zlib.output_compression', 0);
if (ob_get_level()) ob_end_clean();

function sseEvent(string $type, array $data): void {
    echo "event: $type\n";
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ── Fire a single cURL wave: $count parallel requests across $urls ───────────
function fireWave(array $urls, int $count, bool $noCache, int $timeout, bool $random): array {
    $mh = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $count);

    $handles = [];
    for ($i = 0; $i < $count; $i++) {
        $url = $random ? $urls[array_rand($urls)] : $urls[$i % count($urls)];
        if ($noCache) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . '_lt=' . microtime(true) * 1000;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,     // GET full response
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_USERAGENT      => 'KhatibanglaLoadTester/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: bn-BD,bn;q=0.9,en;q=0.8',
                'Cache-Control: ' . ($noCache ? 'no-cache' : 'max-age=0'),
                'Connection: keep-alive',
            ],
        ]);
        $handles[] = ['ch' => $ch, 'url' => $url, 'start' => microtime(true)];
        curl_multi_add_handle($mh, $ch);
    }

    // Execute all in parallel
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.1);
    } while ($running > 0 && $status === CURLM_OK);

    // Collect results
    $results = [];
    foreach ($handles as $item) {
        $ch    = $item['ch'];
        $ms    = round((microtime(true) - $item['start']) * 1000);
        $info  = curl_getinfo($ch);
        $err   = curl_error($ch);
        $code  = (int)($info['http_code'] ?? 0);
        $size  = (int)($info['size_download'] ?? 0);
        $ttfb  = round(($info['starttransfer_time'] ?? 0) * 1000);

        $results[] = [
            'url'    => preg_replace('/\?_lt=[\d.]+/', '', $item['url']), // strip cache-bust
            'ms'     => $ms,
            'ttfb'   => $ttfb,
            'code'   => $code,
            'size'   => $size,
            'ok'     => ($code >= 200 && $code < 400 && !$err),
            'err'    => $err ?: ($code >= 400 ? 'HTTP '.$code : ''),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
}

// ── Main test loop ────────────────────────────────────────────────────────────
$startTime  = microtime(true);
$endTime    = $startTime + $duration;
$waveNum    = 0;
$totalReqs  = 0;
$totalOk    = 0;
$totalErr   = 0;
$allTimes   = [];
$random     = true; // always randomise for real load

sseEvent('start', [
    'urls'        => $urls,
    'concurrency' => $concurrency,
    'duration'    => $duration,
    'interval'    => $interval,
    'timeout'     => $timeout,
    'nocache'     => $noCache,
    'burstMode'   => $burstMode,
]);

while (microtime(true) < $endTime && !connection_aborted()) {
    $waveNum++;
    $elapsed = microtime(true) - $startTime;
    $remaining = $endTime - microtime(true);

    $results = fireWave($urls, $concurrency, $noCache, $timeout, $random);

    $waveTimes = array_column($results, 'ms');
    $waveOk    = count(array_filter($results, fn($r) => $r['ok']));
    $waveErr   = count($results) - $waveOk;
    $waveAvg   = $waveTimes ? round(array_sum($waveTimes) / count($waveTimes)) : 0;
    $waveMax   = $waveTimes ? max($waveTimes) : 0;
    $waveMin   = $waveTimes ? min($waveTimes) : 0;
    $ttfbs     = array_column($results, 'ttfb');
    $waveAvgTtfb = $ttfbs ? round(array_sum($ttfbs) / count($ttfbs)) : 0;

    $totalReqs += count($results);
    $totalOk   += $waveOk;
    $totalErr  += $waveErr;
    $allTimes   = array_merge($allTimes, $waveTimes);

    // Keep allTimes from getting huge — keep last 2000
    if (count($allTimes) > 2000) {
        $allTimes = array_slice($allTimes, -2000);
    }

    // Compute running stats
    sort($allTimes);
    $n   = count($allTimes);
    $avg = $n ? round(array_sum($allTimes) / $n) : 0;
    $p95 = $n ? $allTimes[max(0, (int)($n * 0.95) - 1)] : 0;
    $p99 = $n ? $allTimes[max(0, (int)($n * 0.99) - 1)] : 0;
    $min = $n ? $allTimes[0] : 0;
    $max = $n ? $allTimes[$n - 1] : 0;

    $rps = $elapsed > 0 ? round($totalReqs / $elapsed, 1) : 0;

    // Memory & server load (if available)
    $memUsed = memory_get_usage(true);
    $loadAvg = function_exists('sys_getloadavg') ? sys_getloadavg() : null;

    sseEvent('wave', [
        'wave'       => $waveNum,
        'elapsed'    => round($elapsed, 1),
        'remaining'  => round(max(0, $remaining), 1),
        'progress'   => min(100, round($elapsed / $duration * 100)),
        // Wave stats
        'waveCount'  => count($results),
        'waveOk'     => $waveOk,
        'waveErr'    => $waveErr,
        'waveAvg'    => $waveAvg,
        'waveMax'    => $waveMax,
        'waveMin'    => $waveMin,
        'waveAvgTtfb'=> $waveAvgTtfb,
        // Running totals
        'total'      => $totalReqs,
        'ok'         => $totalOk,
        'err'        => $totalErr,
        'rps'        => $rps,
        // Percentiles
        'avg'        => $avg,
        'p95'        => $p95,
        'p99'        => $p99,
        'minMs'      => $min,
        'maxMs'      => $max,
        // Server-side info
        'memMB'      => round($memUsed / 1048576, 1),
        'load1'      => $loadAvg ? round($loadAvg[0], 2) : null,
        // Per-request log (send up to 10 per wave to avoid flooding)
        'requests'   => array_slice($results, 0, 10),
    ]);

    // Interval between waves (skip in burst mode or if already over time)
    if (!$burstMode && $interval > 0 && microtime(true) < $endTime) {
        $sleepMs = min($interval, ($endTime - microtime(true)) * 1000);
        if ($sleepMs > 0) usleep((int)($sleepMs * 1000));
    }
}

// ── Final summary ─────────────────────────────────────────────────────────────
$totalElapsed = round(microtime(true) - $startTime, 2);
sort($allTimes);
$n   = count($allTimes);
$avg = $n ? round(array_sum($allTimes) / $n) : 0;
$p95 = $n ? $allTimes[max(0, (int)($n * 0.95) - 1)] : 0;
$p99 = $n ? $allTimes[max(0, (int)($n * 0.99) - 1)] : 0;
$min = $n ? $allTimes[0] : 0;
$max = $n ? $allTimes[$n - 1] : 0;
$sr  = $totalReqs ? round($totalOk / $totalReqs * 100, 1) : 0;
$rps = $totalElapsed > 0 ? round($totalReqs / $totalElapsed, 1) : 0;

sseEvent('done', [
    'waves'    => $waveNum,
    'total'    => $totalReqs,
    'ok'       => $totalOk,
    'err'      => $totalErr,
    'elapsed'  => $totalElapsed,
    'rps'      => $rps,
    'sr'       => $sr,
    'avg'      => $avg,
    'p95'      => $p95,
    'p99'      => $p99,
    'min'      => $min,
    'max'      => $max,
]);
