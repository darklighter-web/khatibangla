<?php
/**
 * Server Capability Analyzer
 * Gathers real hardware + software metrics and estimates maximum load capacity.
 * Auth: admin session required.
 */

// Capture ALL output (errors, warnings, notices) so nothing breaks JSON
ob_start();

// Suppress display errors — we'll catch them ourselves
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);

// Custom error handler — store errors silently
$_lt_errors = [];
set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$_lt_errors) {
    $_lt_errors[] = $errstr;
    return true;
});

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Discard anything the includes may have printed
ob_clean();

if (empty($_SESSION['admin_id'])) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
set_time_limit(60);

// ── Helpers ───────────────────────────────────────────────────────────────────
function readFile(string $path): string {
    return @file_exists($path) ? @file_get_contents($path) : '';
}

function parseMeminfo(): array {
    $raw = readFile('/proc/meminfo');
    $data = [];
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
            $data[$m[1]] = (int)$m[2]; // kB
        }
    }
    return $data;
}

function parseCpuinfo(): array {
    $raw = readFile('/proc/cpuinfo');
    $cores   = substr_count($raw, 'processor');
    $model   = '';
    $mhz     = 0;
    $cache   = '';
    foreach (explode("\n", $raw) as $line) {
        if (!$model   && preg_match('/^model name\s*:\s*(.+)/', $line, $m)) $model = trim($m[1]);
        if (!$mhz     && preg_match('/^cpu MHz\s*:\s*([\d.]+)/', $line, $m)) $mhz = (float)$m[1];
        if (!$cache   && preg_match('/^cache size\s*:\s*(.+)/', $line, $m)) $cache = trim($m[1]);
    }
    return compact('cores', 'model', 'mhz', 'cache');
}

function getLoadAvg(): array {
    if (function_exists('sys_getloadavg')) {
        $l = sys_getloadavg();
        return ['1m' => round($l[0],2), '5m' => round($l[1],2), '15m' => round($l[2],2)];
    }
    $raw = readFile('/proc/loadavg');
    if ($raw) {
        $parts = explode(' ', $raw);
        return ['1m' => (float)$parts[0], '5m' => (float)$parts[1], '15m' => (float)$parts[2]];
    }
    return ['1m' => null, '5m' => null, '15m' => null];
}

function getDiskIO(): array {
    // /proc/diskstats — get read/write sectors for main disk
    $raw = readFile('/proc/diskstats');
    $totalReadKB = 0; $totalWriteKB = 0;
    foreach (explode("\n", $raw) as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) < 14) continue;
        $dev = $p[2];
        // Skip partitions, keep whole disks (sda, vda, nvme0n1, etc.)
        if (!preg_match('/^(sd[a-z]|vd[a-z]|nvme\d+n\d+|xvd[a-z])$/', $dev)) continue;
        $totalReadKB  += (int)$p[5]  / 2; // sectors × 512 / 1024
        $totalWriteKB += (int)$p[9]  / 2;
    }
    return ['read_kb' => round($totalReadKB), 'write_kb' => round($totalWriteKB)];
}

function getNetStats(): array {
    $raw = readFile('/proc/net/dev');
    $rxBytes = 0; $txBytes = 0;
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!$line || strpos($line, ':') === false) continue;
        [$iface, $stats] = explode(':', $line, 2);
        $iface = trim($iface);
        if (in_array($iface, ['lo'])) continue;
        $p = preg_split('/\s+/', trim($stats));
        $rxBytes += (int)($p[0] ?? 0);
        $txBytes += (int)($p[8] ?? 0);
    }
    return ['rx_mb' => round($rxBytes / 1048576, 1), 'tx_mb' => round($txBytes / 1048576, 1)];
}

function countApacheWorkers(): array {
    // Try Apache server-status mod (may not be available)
    $workers = ['active' => null, 'idle' => null, 'max' => null, 'server' => 'unknown'];

    // Detect web server from env/headers
    $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
    if (stripos($server, 'apache') !== false) $workers['server'] = 'Apache';
    elseif (stripos($server, 'nginx') !== false) $workers['server'] = 'Nginx';
    elseif (stripos($server, 'litespeed') !== false || stripos($server, 'openlitespeed') !== false) $workers['server'] = 'LiteSpeed';

    // Try LiteSpeed LSAPI active connections via process count
    if (PHP_SAPI === 'litespeed' || stripos($server, 'litespeed') !== false) {
        $workers['server'] = 'LiteSpeed';
        $lsProc = (int)shell_exec("pgrep -c lsphp 2>/dev/null") ?: null;
        if ($lsProc) $workers['active'] = $lsProc;
    }

    // Try counting PHP-FPM processes
    $fpmProc = (int)@shell_exec("pgrep -c 'php-fpm|php8' 2>/dev/null") ?: null;
    if ($fpmProc) $workers['active'] = $fpmProc;

    // Try httpd/apache workers
    $apacheProc = (int)@shell_exec("pgrep -c 'httpd|apache2' 2>/dev/null") ?: null;
    if ($apacheProc) $workers['active'] = $apacheProc;

    return $workers;
}

function getPhpConfig(): array {
    return [
        'version'        => PHP_VERSION,
        'memory_limit'   => ini_get('memory_limit'),
        'memory_limit_b' => return_bytes(ini_get('memory_limit')),
        'max_exec_time'  => ini_get('max_execution_time'),
        'max_input_time' => ini_get('max_input_time'),
        'upload_max'     => ini_get('upload_max_filesize'),
        'post_max'       => ini_get('post_max_size'),
        'opcache'        => extension_loaded('Zend OPcache') || extension_loaded('opcache'),
        'opcache_enabled'=> (bool)(ini_get('opcache.enable')),
        'apcu'           => extension_loaded('apcu'),
        'redis'          => extension_loaded('redis'),
        'curl'           => extension_loaded('curl'),
        'curl_multi'     => function_exists('curl_multi_init'),
        'sapi'           => PHP_SAPI,
    ];
}

function return_bytes(string $val): int {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $num  = (int)$val;
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
}

function getDbStats(): array {
    try {
        $db = Database::getInstance();
        $vars = $db->fetchAll("SHOW VARIABLES WHERE Variable_name IN (
            'max_connections','innodb_buffer_pool_size','query_cache_size',
            'innodb_flush_log_at_trx_commit','thread_cache_size','table_open_cache',
            'version','version_compile_os'
        )");
        $status = $db->fetchAll("SHOW GLOBAL STATUS WHERE Variable_name IN (
            'Threads_connected','Threads_running','Questions',
            'Slow_queries','Uptime','Bytes_received','Bytes_sent',
            'Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests',
            'Connections','Aborted_connects'
        )");
        $result = [];
        foreach ($vars   as $r) $result['vars'][$r['Variable_name']]   = $r['Value'];
        foreach ($status as $r) $result['status'][$r['Variable_name']] = $r['Value'];

        // Buffer pool hit rate
        $reads = (int)($result['status']['Innodb_buffer_pool_reads'] ?? 0);
        $reqs  = (int)($result['status']['Innodb_buffer_pool_read_requests'] ?? 1);
        $result['buffer_pool_hit_rate'] = $reqs > 0 ? round((1 - $reads/$reqs)*100, 2) : null;

        // Connections used %
        $maxConn = (int)($result['vars']['max_connections'] ?? 150);
        $curConn = (int)($result['status']['Threads_connected'] ?? 0);
        $result['connections_used_pct'] = $maxConn > 0 ? round($curConn/$maxConn*100, 1) : null;

        return $result;
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

// ── Benchmark: measure PHP execution speed ───────────────────────────────────
function runMicroBenchmark(): array {
    // CPU: compute-intensive loop
    $t0 = microtime(true);
    $x = 0;
    for ($i = 0; $i < 500000; $i++) { $x += sqrt($i) * log($i + 1); }
    $cpuMs = round((microtime(true) - $t0) * 1000, 2);

    // Memory: allocate and free
    $t0 = microtime(true);
    $arr = [];
    for ($i = 0; $i < 10000; $i++) $arr[] = str_repeat('x', 100);
    unset($arr);
    $memMs = round((microtime(true) - $t0) * 1000, 2);

    // String: regex / str ops
    $t0 = microtime(true);
    $s = str_repeat('The quick brown fox jumps over the lazy dog. ', 1000);
    for ($i = 0; $i < 100; $i++) preg_match_all('/\b\w{4,}\b/', $s, $m);
    $strMs = round((microtime(true) - $t0) * 1000, 2);

    return compact('cpuMs', 'memMs', 'strMs');
}

// ── Capacity estimator ────────────────────────────────────────────────────────
function estimateCapacity(array $cpu, array $mem, array $php, array $db, array $bench, array $load): array {
    $cores       = max(1, $cpu['cores'] ?? 1);
    $totalRamMB  = ($mem['MemTotal'] ?? 0) / 1024;
    $availRamMB  = ($mem['MemAvailable'] ?? 0) / 1024;
    $phpMemLimitMB = ($php['memory_limit_b'] ?? 128*1024*1024) / 1048576;

    // How many PHP processes can run simultaneously given RAM?
    $phpProcessesFromRam = $phpMemLimitMB > 0 ? (int)floor($availRamMB / $phpMemLimitMB) : 10;
    $phpProcessesFromRam = max(1, min($phpProcessesFromRam, 500));

    // CPU-based estimate: assume each request uses ~50ms CPU time avg
    // Requests per second = cores × (1000ms / avg_cpu_time_per_req)
    // Use benchmark to calibrate: fast PHP = lighter per-request load
    $cpuSpeedFactor = $bench['cpuMs'] > 0 ? (200 / $bench['cpuMs']) : 1; // 200ms baseline
    $cpuSpeedFactor = max(0.1, min(5, $cpuSpeedFactor));
    $rpsFromCpu = (int)round($cores * 20 * $cpuSpeedFactor); // 20 rps/core baseline

    // DB max connections cap
    $dbMaxConn = (int)($db['vars']['max_connections'] ?? 150);

    // Current load headroom
    $currentLoad = (float)($load['1m'] ?? 0);
    $loadHeadroom = max(0.1, ($cores - $currentLoad) / max(0.1, $cores));

    // OPcache multiplier (huge impact)
    $opcacheBoost = ($php['opcache'] && $php['opcache_enabled']) ? 1.8 : 1.0;

    // Combine estimates
    $maxConcurrent = (int)round(min($phpProcessesFromRam, $dbMaxConn * 0.8) * $loadHeadroom);
    $maxRps        = (int)round($rpsFromCpu * $opcacheBoost * $loadHeadroom);
    $maxConcurrent = max(1, $maxConcurrent);
    $maxRps        = max(1, $maxRps);

    // Safe load = 70% of max
    $safeConcurrent = (int)round($maxConcurrent * 0.7);
    $safeRps        = (int)round($maxRps * 0.7);

    // Stress threshold = 90%
    $stressThreshold = (int)round($maxConcurrent * 0.9);

    // Rating
    $rating = 'unknown';
    if ($totalRamMB >= 8192 && $cores >= 8)       $rating = 'high-performance';
    elseif ($totalRamMB >= 4096 && $cores >= 4)   $rating = 'good';
    elseif ($totalRamMB >= 2048 && $cores >= 2)   $rating = 'standard';
    elseif ($totalRamMB >= 1024)                  $rating = 'basic';
    else                                           $rating = 'minimal';

    // Bottleneck detection
    $bottlenecks = [];
    if (!($php['opcache'] && $php['opcache_enabled']))           $bottlenecks[] = ['label'=>'OPcache disabled','impact'=>'high','fix'=>'Enable opcache.enable=1 in php.ini — can double throughput'];
    if ($phpMemLimitMB < 128)                                    $bottlenecks[] = ['label'=>'Low PHP memory limit','impact'=>'high','fix'=>'Increase memory_limit to at least 256M in php.ini'];
    if ($availRamMB < 256)                                       $bottlenecks[] = ['label'=>'Low available RAM','impact'=>'critical','fix'=>'Server is memory-constrained — close other processes or upgrade RAM'];
    if ($dbMaxConn < 100)                                        $bottlenecks[] = ['label'=>'Low DB max_connections','impact'=>'medium','fix'=>'Increase max_connections in MariaDB config'];
    $bpHit = $db['buffer_pool_hit_rate'] ?? 100;
    if ($bpHit < 90 && $bpHit > 0)                              $bottlenecks[] = ['label'=>'Low InnoDB buffer pool hit rate ('.round($bpHit).'%)','impact'=>'high','fix'=>'Increase innodb_buffer_pool_size in MariaDB config'];
    if ($bench['cpuMs'] > 300)                                   $bottlenecks[] = ['label'=>'Slow CPU benchmark','impact'=>'medium','fix'=>'CPU is underpowered for this workload — consider upgrading plan'];

    // Recommendations for suggested load test settings
    $suggested = [
        'light'   => ['concurrency' => max(1, (int)($maxConcurrent * .2)), 'label' => 'Light (20%)'],
        'normal'  => ['concurrency' => max(1, $safeConcurrent),            'label' => 'Normal (70%)'],
        'stress'  => ['concurrency' => max(1, $stressThreshold),           'label' => 'Stress (90%)'],
        'spike'   => ['concurrency' => max(1, $maxConcurrent),             'label' => 'Max (100%)'],
        'danger'  => ['concurrency' => max(1, (int)($maxConcurrent * 1.5)),'label' => 'Beyond Max (150%)'],
    ];

    return compact(
        'maxConcurrent','maxRps','safeConcurrent','safeRps',
        'stressThreshold','phpProcessesFromRam','rpsFromCpu',
        'opcacheBoost','loadHeadroom','rating','bottlenecks','suggested',
        'phpMemLimitMB','cores','totalRamMB','availRamMB','dbMaxConn','cpuSpeedFactor'
    );
}

// ── Assemble all data ─────────────────────────────────────────────────────────
try {
$cpu   = parseCpuinfo();
$mem   = parseMeminfo();
$load  = getLoadAvg();
$disk  = getDiskIO();
$net   = getNetStats();
$php   = getPhpConfig();
$db    = getDbStats();
$bench = runMicroBenchmark();
$cap   = estimateCapacity($cpu, $mem, $php, $db, $bench, $load);
$workers = countApacheWorkers();

// Discard any stray output (PHP notices etc) before sending JSON
ob_end_clean();
header('Content-Type: application/json');

echo json_encode([
    'cpu'      => $cpu,
    'mem'      => [
        'total_mb'   => round(($mem['MemTotal']     ?? 0) / 1024, 0),
        'available_mb'=> round(($mem['MemAvailable'] ?? 0) / 1024, 0),
        'used_mb'    => round((($mem['MemTotal'] ?? 0) - ($mem['MemAvailable'] ?? 0)) / 1024, 0),
        'buffers_mb' => round(($mem['Buffers']       ?? 0) / 1024, 0),
        'cached_mb'  => round(($mem['Cached']        ?? 0) / 1024, 0),
        'swap_total_mb' => round(($mem['SwapTotal']  ?? 0) / 1024, 0),
        'swap_used_mb'  => round((($mem['SwapTotal'] ?? 0) - ($mem['SwapFree'] ?? 0)) / 1024, 0),
        'used_pct'   => ($mem['MemTotal'] ?? 0) > 0
                        ? round((($mem['MemTotal']-($mem['MemAvailable']??0)) / $mem['MemTotal'])*100, 1)
                        : null,
    ],
    'load'     => $load,
    'disk'     => $disk,
    'net'      => $net,
    'php'      => $php,
    'db'       => $db,
    'bench'    => $bench,
    'workers'  => $workers,
    'capacity' => $cap,
    'timestamp'=> date('Y-m-d H:i:s'),
    'hostname' => gethostname(),
    'os'       => PHP_OS,
], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getFile().':'.$e->getLine()]);
}
