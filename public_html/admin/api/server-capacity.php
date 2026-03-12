<?php ob_start(); ini_set('display_errors','0'); error_reporting(0);
/**
 * Site Capacity Benchmark — SSE stream
 * Fires real escalating cURL waves at the site and measures when it degrades.
 * Reports: comfortable capacity, degradation point, breaking point.
 * Auth: session only (no auth.php — it outputs HTML).
 */

require_once __DIR__ . '/../../includes/session.php';
ob_clean();

if (empty($_SESSION['admin_id'])) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../includes/functions.php';
ob_clean();

// ── SSE setup ─────────────────────────────────────────────────────────────────
ob_end_clean();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
set_time_limit(300);

function sse(string $event, array $data): void {
    echo "event: $event\ndata: " . json_encode($data) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ── cURL wave: fire $n parallel requests, return timing stats ─────────────────
function fireWave(string $url, int $n, int $timeout): array {
    $mh = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $n);
    $handles = [];
    for ($i = 0; $i < $n; $i++) {
        $bust = '?_cb=' . microtime(true) * 1000 . $i;
        $ch = curl_init($url . $bust);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'KhatibanglaCapacityBench/1.0',
            CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
        ]);
        $handles[] = ['ch' => $ch, 't' => microtime(true)];
        curl_multi_add_handle($mh, $ch);
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.05);
    } while ($running > 0);

    $times = []; $ok = 0; $err = 0; $codes = [];
    foreach ($handles as $h) {
        $info  = curl_getinfo($h['ch']);
        $error = curl_error($h['ch']);
        $ms    = round((microtime(true) - $h['t']) * 1000);
        $code  = (int)($info['http_code'] ?? 0);
        $times[] = $ms;
        if ($code >= 200 && $code < 400 && !$error) $ok++;
        else $err++;
        $codes[] = $code;
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
    }
    curl_multi_close($mh);

    sort($times);
    $cnt = count($times);
    return [
        'n'    => $n,
        'ok'   => $ok,
        'err'  => $err,
        'sr'   => $cnt > 0 ? round($ok / $cnt * 100, 1) : 0,
        'avg'  => $cnt > 0 ? round(array_sum($times) / $cnt) : 0,
        'p95'  => $cnt > 0 ? $times[max(0, (int)($cnt * .95) - 1)] : 0,
        'p99'  => $cnt > 0 ? $times[max(0, (int)($cnt * .99) - 1)] : 0,
        'min'  => $cnt > 0 ? $times[0]       : 0,
        'max'  => $cnt > 0 ? $times[$cnt - 1] : 0,
        'codes'=> array_count_values($codes),
    ];
}

// ── Benchmark stages ──────────────────────────────────────────────────────────
// Each stage fires 3 waves and averages, to reduce noise.
$target  = rtrim(SITE_URL, '/') . '/';
$timeout = 20;

// Levels: how many concurrent requests per stage
$levels = [1, 3, 5, 10, 15, 20, 30, 40, 50, 75, 100, 150, 200];

sse('start', [
    'target' => $target,
    'levels' => $levels,
    'total'  => count($levels),
    'waves_per_level' => 3,
]);

$results        = [];
$comfortableAt  = null;  // last level where avg < 800ms & sr >= 97%
$degradedAt     = null;  // first level where avg >= 800ms or sr < 97%
$breakingAt     = null;  // first level where avg >= 3000ms or sr < 80%
$maxSustainable = 0;     // highest concurrent with sr >= 95%

foreach ($levels as $idx => $level) {
    if (connection_aborted()) break;

    sse('testing', [
        'level'   => $level,
        'step'    => $idx + 1,
        'total'   => count($levels),
        'pct'     => round(($idx / count($levels)) * 100),
        'msg'     => "Testing $level concurrent requests…",
    ]);

    // Fire 3 waves, collect all times
    $allTimes = []; $totalOk = 0; $totalReqs = 0;
    $waveResults = [];
    for ($w = 0; $w < 3; $w++) {
        if (connection_aborted()) break 2;
        $r = fireWave($target, $level, $timeout);
        $waveResults[] = $r;
        $totalOk   += $r['ok'];
        $totalReqs += $r['n'];
        // small gap between waves
        if ($w < 2) usleep(200000);
    }

    // Aggregate across 3 waves
    $allAvg = round(array_sum(array_column($waveResults, 'avg')) / count($waveResults));
    $allP95 = round(array_sum(array_column($waveResults, 'p95')) / count($waveResults));
    $allP99 = round(array_sum(array_column($waveResults, 'p99')) / count($waveResults));
    $allMin = min(array_column($waveResults, 'min'));
    $allMax = max(array_column($waveResults, 'max'));
    $sr     = $totalReqs > 0 ? round($totalOk / $totalReqs * 100, 1) : 0;

    // Grade this level
    $grade = 'excellent';
    if      ($sr < 80 || $allP95 >= 3000) $grade = 'breaking';
    elseif  ($sr < 90 || $allP95 >= 2000) $grade = 'overloaded';
    elseif  ($sr < 97 || $allAvg >= 1500) $grade = 'degraded';
    elseif  ($allAvg >= 800)              $grade = 'stressed';
    elseif  ($allAvg >= 400)              $grade = 'good';

    // Track thresholds
    if ($grade === 'excellent' || $grade === 'good') {
        $comfortableAt = $level;
    }
    if ($grade === 'stressed' && $degradedAt === null) {
        $degradedAt = $level;
    }
    if (($grade === 'degraded' || $grade === 'overloaded') && $degradedAt === null) {
        $degradedAt = $level;
    }
    if (($grade === 'breaking' || $grade === 'overloaded') && $breakingAt === null) {
        $breakingAt = $level;
    }
    if ($sr >= 95) $maxSustainable = $level;

    $result = compact('level','allAvg','allP95','allP99','allMin','allMax','sr','grade');
    $results[] = $result;

    sse('level', array_merge($result, [
        'step'  => $idx + 1,
        'total' => count($levels),
        'pct'   => round((($idx + 1) / count($levels)) * 100),
    ]));

    // Stop escalating if completely broken for 2+ levels in a row
    $recentGrades = array_column(array_slice($results, -2), 'grade');
    if (count($recentGrades) >= 2 &&
        in_array($recentGrades[0], ['breaking']) &&
        in_array($recentGrades[1], ['breaking'])) {
        sse('info', ['msg' => "Stopping early — server is at breaking point."]);
        break;
    }
}

// ── Final verdict ─────────────────────────────────────────────────────────────
$comfortable = $comfortableAt ?? ($results[0]['level'] ?? 1);
$degraded    = $degradedAt    ?? 'Not reached in test';
$breaking    = $breakingAt    ?? 'Not reached in test';
$sustained   = $maxSustainable ?: $comfortable;

// Throughput estimate: from baseline (1 concurrent) timing
$baseAvg = $results[0]['allAvg'] ?? 500;
$estRps  = $baseAvg > 0 ? round(1000 / $baseAvg * $comfortable) : 0;

sse('done', [
    'results'     => $results,
    'comfortable' => $comfortable,   // concurrent users — good perf
    'degraded'    => $degraded,      // first sign of slowdown
    'breaking'    => $breaking,      // failure starts here
    'sustained'   => $sustained,     // highest with >=95% success
    'estRps'      => $estRps,
    'baseAvg'     => $baseAvg,
    'levels_tested'=> count($results),
]);
