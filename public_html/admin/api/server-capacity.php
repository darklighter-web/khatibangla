<?php ob_start(); ini_set('display_errors','0'); error_reporting(0);
/**
 * Server Capability Analyzer — bulletproof JSON API
 * ob_start() is on line 1, NO auth.php included (it outputs HTML).
 * Auth done manually via session only.
 */

require_once __DIR__ . '/../../includes/session.php';
ob_clean(); // clear anything session.php printed

if (empty($_SESSION['admin_id'])) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../includes/functions.php';
ob_clean(); // clear anything functions.php printed

set_time_limit(60);

// ── Helpers (all prefixed sc_ to avoid any name collision) ───────────────────

function sc_file(string $p): string { return @file_exists($p) ? (string)@file_get_contents($p) : ''; }

function sc_meminfo(): array {
    $d = [];
    foreach (explode("\n", sc_file('/proc/meminfo')) as $l)
        if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) $d[$m[1]] = (int)$m[2];
    return $d;
}

function sc_cpu(): array {
    $raw = sc_file('/proc/cpuinfo');
    $cores = substr_count($raw, 'processor');
    $model = $mhz = $cache = '';
    foreach (explode("\n", $raw) as $l) {
        if (!$model && preg_match('/^model name\s*:\s*(.+)/', $l, $m)) $model = trim($m[1]);
        if (!$mhz   && preg_match('/^cpu MHz\s*:\s*([\d.]+)/', $l, $m)) $mhz   = trim($m[1]);
        if (!$cache  && preg_match('/^cache size\s*:\s*(.+)/', $l, $m)) $cache  = trim($m[1]);
    }
    return ['cores'=>max(1,(int)$cores),'model'=>$model,'mhz'=>(float)$mhz,'cache'=>$cache];
}

function sc_load(): array {
    if (function_exists('sys_getloadavg')) {
        $l = sys_getloadavg();
        return ['1m'=>round($l[0],2),'5m'=>round($l[1],2),'15m'=>round($l[2],2)];
    }
    $p = explode(' ', sc_file('/proc/loadavg'));
    return ['1m'=>(float)($p[0]??0),'5m'=>(float)($p[1]??0),'15m'=>(float)($p[2]??0)];
}

function sc_net(): array {
    $rx = $tx = 0;
    foreach (explode("\n", sc_file('/proc/net/dev')) as $l) {
        $l = trim($l);
        if (!$l || !str_contains($l,':')) continue;
        [$face,$stats] = explode(':',$l,2);
        if (trim($face)==='lo') continue;
        $p = preg_split('/\s+/',trim($stats));
        $rx += (int)($p[0]??0); $tx += (int)($p[8]??0);
    }
    return ['rx_mb'=>round($rx/1048576,1),'tx_mb'=>round($tx/1048576,1)];
}

function sc_disk(): array {
    $r=$w=0;
    foreach (explode("\n", sc_file('/proc/diskstats')) as $l) {
        $p = preg_split('/\s+/',trim($l));
        if (count($p)<14||!preg_match('/^(sd[a-z]|vd[a-z]|nvme\d+n\d+|xvd[a-z])$/',$p[2])) continue;
        $r += (int)$p[5]/2; $w += (int)$p[9]/2;
    }
    return ['read_kb'=>round($r),'write_kb'=>round($w)];
}

function sc_bytes(string $v): int {
    $v=trim($v); $l=strtolower($v[strlen($v)-1]); $n=(int)$v;
    if($l==='g')$n*=1073741824; elseif($l==='m')$n*=1048576; elseif($l==='k')$n*=1024;
    return $n;
}

function sc_php(): array {
    return [
        'version'         => PHP_VERSION,
        'memory_limit'    => ini_get('memory_limit'),
        'memory_limit_b'  => sc_bytes(ini_get('memory_limit')),
        'max_exec_time'   => ini_get('max_execution_time'),
        'opcache'         => extension_loaded('Zend OPcache')||extension_loaded('opcache'),
        'opcache_enabled' => (bool)ini_get('opcache.enable'),
        'apcu'            => extension_loaded('apcu'),
        'redis'           => extension_loaded('redis'),
        'curl_multi'      => function_exists('curl_multi_init'),
        'sapi'            => PHP_SAPI,
    ];
}

function sc_db(): array {
    try {
        $db = Database::getInstance();
        $vars = $db->fetchAll("SHOW VARIABLES WHERE Variable_name IN (
            'max_connections','innodb_buffer_pool_size','version','thread_cache_size')");
        $status = $db->fetchAll("SHOW GLOBAL STATUS WHERE Variable_name IN (
            'Threads_connected','Threads_running','Slow_queries','Uptime',
            'Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests','Aborted_connects')");
        $v=[]; $s=[];
        foreach($vars as $r) $v[$r['Variable_name']]=$r['Value'];
        foreach($status as $r) $s[$r['Variable_name']]=$r['Value'];
        $reads=(int)($s['Innodb_buffer_pool_reads']??0);
        $reqs=max(1,(int)($s['Innodb_buffer_pool_read_requests']??1));
        return [
            'vars'=>$v,'status'=>$s,
            'buffer_pool_hit_rate'=>round((1-$reads/$reqs)*100,2),
            'connections_used_pct'=>(int)($v['max_connections']??1)>0
                ? round((int)($s['Threads_connected']??0)/(int)($v['max_connections']??1)*100,1) : null,
        ];
    } catch(Throwable $e) { return ['error'=>$e->getMessage(),'vars'=>[],'status'=>[],'buffer_pool_hit_rate'=>null,'connections_used_pct'=>null]; }
}

function sc_workers(): array {
    $sw = $_SERVER['SERVER_SOFTWARE']??'';
    $name = stripos($sw,'litespeed')!==false?'LiteSpeed':(stripos($sw,'nginx')!==false?'Nginx':(stripos($sw,'apache')!==false?'Apache':'Unknown'));
    $active=null;
    foreach(['lsphp','php-fpm','php8','php7','httpd','apache2'] as $p){
        $n=(int)@shell_exec('pgrep -c '.escapeshellarg($p).' 2>/dev/null');
        if($n>0){$active=$n;break;}
    }
    return ['server'=>$name,'active'=>$active];
}

function sc_bench(): array {
    $t=microtime(true); $x=0;
    for($i=1;$i<=500000;$i++) $x+=sqrt($i)*log($i);
    $cpuMs=round((microtime(true)-$t)*1000,2);

    $t=microtime(true); $a=[];
    for($i=0;$i<10000;$i++) $a[]=str_repeat('x',100); unset($a);
    $memMs=round((microtime(true)-$t)*1000,2);

    $t=microtime(true); $s=str_repeat('The quick brown fox. ',1000);
    for($i=0;$i<100;$i++) preg_match_all('/\b\w{4,}\b/',$s,$m);
    $strMs=round((microtime(true)-$t)*1000,2);

    return compact('cpuMs','memMs','strMs');
}

function sc_estimate(array $cpu, array $mr, array $php, array $db, array $bn, array $ld): array {
    $cores    = $cpu['cores'];
    $totalMB  = ($mr['MemTotal']??0)/1024;
    $availMB  = ($mr['MemAvailable']??0)/1024;
    $phpMemMB = max(1, ($php['memory_limit_b']??134217728)/1048576);
    $dbMax    = max(1,(int)(($db['vars']??[])['max_connections']??150));
    $fromRam  = max(1,min(500,(int)floor($availMB/$phpMemMB)));
    $cpuFact  = $bn['cpuMs']>0?min(5,max(.1,200/$bn['cpuMs'])):1;
    $loadHead = max(.05,($cores-(float)($ld['1m']??0))/$cores);
    $opcBoost = ($php['opcache']&&$php['opcache_enabled'])?1.8:1.0;
    $maxConc  = max(1,(int)round(min($fromRam,$dbMax*.8)*$loadHead));
    $maxRps   = max(1,(int)round($cores*20*$cpuFact*$opcBoost*$loadHead));
    $bpHit    = $db['buffer_pool_hit_rate']??100;

    $bots=[];
    if(!($php['opcache']&&$php['opcache_enabled']))
        $bots[]=['label'=>'OPcache disabled','impact'=>'high','fix'=>'Enable opcache.enable=1 in php.ini — can double throughput'];
    if($phpMemMB<128)
        $bots[]=['label'=>'Low PHP memory limit ('.(int)$phpMemMB.'MB)','impact'=>'high','fix'=>'Set memory_limit = 256M in php.ini'];
    if($availMB<256)
        $bots[]=['label'=>'Low available RAM ('.round($availMB).'MB free)','impact'=>'critical','fix'=>'Close other processes or upgrade RAM'];
    if($dbMax<100)
        $bots[]=['label'=>'Low DB max_connections ('.$dbMax.')','impact'=>'medium','fix'=>'Increase max_connections in MariaDB config'];
    if($bpHit!==null&&$bpHit<90&&$bpHit>0)
        $bots[]=['label'=>'Low InnoDB buffer pool hit rate ('.round((float)$bpHit).'%)','impact'=>'high','fix'=>'Increase innodb_buffer_pool_size in MariaDB'];
    if($bn['cpuMs']>300)
        $bots[]=['label'=>'Slow CPU benchmark ('.$bn['cpuMs'].'ms)','impact'=>'medium','fix'=>'CPU is underpowered — consider upgrading hosting plan'];

    $rating = $totalMB>=8192&&$cores>=8?'high-performance':($totalMB>=4096&&$cores>=4?'good':($totalMB>=2048&&$cores>=2?'standard':($totalMB>=1024?'basic':'minimal')));

    return [
        'maxConc'=>$maxConc,'maxRps'=>$maxRps,'fromRam'=>$fromRam,
        'cpuFactor'=>round($cpuFact,2),'loadHead'=>round($loadHead,2),
        'opcBoost'=>$opcBoost,'dbMaxConn'=>$dbMax,'phpMemLimitMB'=>round($phpMemMB),
        'cores'=>$cores,'totalRamMB'=>round($totalMB),'availRamMB'=>round($availMB),
        'rating'=>$rating,'bottlenecks'=>$bots,
        'buffer_pool_hit_rate'=>$bpHit,
        'suggested'=>[
            'light' =>['concurrency'=>max(1,(int)($maxConc*.2)),'label'=>'Light (20%)'],
            'normal'=>['concurrency'=>max(1,(int)($maxConc*.7)),'label'=>'Normal (70%)'],
            'stress'=>['concurrency'=>max(1,(int)($maxConc*.9)),'label'=>'Stress (90%)'],
            'spike' =>['concurrency'=>$maxConc,                 'label'=>'Max (100%)'],
            'danger'=>['concurrency'=>max(1,(int)($maxConc*1.5)),'label'=>'Beyond Max (150%)'],
        ],
    ];
}

// ── Execute ───────────────────────────────────────────────────────────────────
try {
    $cpu    = sc_cpu();
    $mr     = sc_meminfo();
    $load   = sc_load();
    $php    = sc_php();
    $db     = sc_db();
    $bench  = sc_bench();
    $cap    = sc_estimate($cpu,$mr,$php,$db,$bench,$load);

    $out = [
        'cpu'       => $cpu,
        'mem'       => [
            'total_mb'     => round(($mr['MemTotal']??0)/1024),
            'available_mb' => round(($mr['MemAvailable']??0)/1024),
            'used_mb'      => round((($mr['MemTotal']??0)-($mr['MemAvailable']??0))/1024),
            'buffers_mb'   => round(($mr['Buffers']??0)/1024),
            'cached_mb'    => round(($mr['Cached']??0)/1024),
            'swap_total_mb'=> round(($mr['SwapTotal']??0)/1024),
            'swap_used_mb' => round((($mr['SwapTotal']??0)-($mr['SwapFree']??0))/1024),
            'used_pct'     => ($mr['MemTotal']??0)>0 ? round((1-($mr['MemAvailable']??0)/($mr['MemTotal']))*100,1) : null,
        ],
        'load'      => $load,
        'disk'      => sc_disk(),
        'net'       => sc_net(),
        'php'       => $php,
        'db'        => $db,
        'bench'     => $bench,
        'workers'   => sc_workers(),
        'capacity'  => $cap,
        'timestamp' => date('Y-m-d H:i:s'),
        'hostname'  => gethostname(),
        'os'        => PHP_OS,
    ];

    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['error'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);
}
