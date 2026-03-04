<?php
/**
 * Speed Optimization API
 * Cache clearing, DB optimization, CDN purge, image compression, server health
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

// Admin only
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ═══════════════════════════════════════
    // CACHE CLEARING
    // ═══════════════════════════════════════

    case 'clear_opcache':
        $result = ['cleared' => false, 'message' => 'OPcache not available'];
        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status(false);
            if ($status) {
                opcache_reset();
                $result = [
                    'cleared' => true,
                    'message' => 'OPcache cleared (' . ($status['opcache_statistics']['num_cached_scripts'] ?? 0) . ' scripts reset)',
                ];
            }
        }
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    case 'clear_sessions':
        $sessionPath = session_save_path() ?: '/tmp';
        $count = 0;
        if (is_dir($sessionPath)) {
            foreach (glob($sessionPath . '/sess_*') as $file) {
                if (filemtime($file) < time() - 86400) {
                    @unlink($file);
                    $count++;
                }
            }
        }
        echo json_encode(['success' => true, 'data' => ['removed' => $count, 'message' => "Cleared {$count} expired sessions"]]);
        break;

    case 'clear_temp':
        $tmpDirs = [__DIR__ . '/../cache', __DIR__ . '/../tmp', '/tmp/php-cache'];
        $count = 0; $freed = 0;
        foreach ($tmpDirs as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '/*') as $file) {
                if (is_file($file) && filemtime($file) < time() - 3600) {
                    $freed += filesize($file);
                    @unlink($file);
                    $count++;
                }
            }
        }
        echo json_encode(['success' => true, 'data' => [
            'removed' => $count, 'freed' => round($freed / 1024, 1) . ' KB',
            'message' => "Removed {$count} temp files (" . round($freed / 1024, 1) . " KB)"
        ]]);
        break;

    case 'clear_query_cache':
        $db = Database::getInstance();
        try {
            $db->query("RESET QUERY CACHE");
            echo json_encode(['success' => true, 'data' => ['message' => 'Query cache cleared']]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => true, 'data' => ['message' => 'Query cache not available (MySQL 8+)']]);
        }
        break;

    case 'bust_cache':
        $newVersion = time();
        updateSetting('cache_buster', $newVersion);
        echo json_encode(['success' => true, 'data' => ['version' => $newVersion, 'message' => "Cache buster updated to v{$newVersion}"]]);
        break;

    // ═══════════════════════════════════════
    // DATABASE OPTIMIZATION
    // ═══════════════════════════════════════

    case 'optimize_db':
        $db = Database::getInstance();
        try {
            $tables = $db->fetchAll("SHOW TABLES");
            $optimized = 0; $totalSaved = 0;
            foreach ($tables as $t) {
                $tableName = array_values($t)[0];
                try {
                    $info = $db->fetch("SELECT data_free FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?", [$tableName]);
                    $totalSaved += intval($info['data_free'] ?? 0);
                    $db->query("OPTIMIZE TABLE `{$tableName}`");
                    $optimized++;
                } catch (\Throwable $e) {}
            }
            echo json_encode(['success' => true, 'data' => [
                'tables_optimized' => $optimized,
                'space_reclaimed' => round($totalSaved / 1024, 1) . ' KB',
                'message' => "Optimized {$optimized} tables, reclaimed " . round($totalSaved / 1024, 1) . " KB"
            ]]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'clean_customer_uploads':
        $uploadsDir = __DIR__ . '/../uploads/customer-uploads/';
        $deleted = 0;
        if (is_dir($uploadsDir)) {
            $files = glob($uploadsDir . 'cu_*');
            foreach ($files as $f) {
                if (is_file($f)) { @unlink($f); $deleted++; }
            }
        }
        echo json_encode(['success' => true, 'data' => ['deleted' => $deleted, 'message' => "Deleted $deleted customer upload files"]]);
        break;

    case 'clean_old_data':
        $db = Database::getInstance();
        $cleaned = [];
        
        // Old visitor logs (> 90 days)
        try {
            $r = $db->query("DELETE FROM visitor_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            $cleaned['visitor_logs'] = $r->rowCount();
        } catch (\Throwable $e) { $cleaned['visitor_logs'] = 'skip'; }
        
        // Old activity logs (> 60 days)
        try {
            $r = $db->query("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
            $cleaned['activity_logs'] = $r->rowCount();
        } catch (\Throwable $e) { $cleaned['activity_logs'] = 'skip'; }
        
        // Read notifications (> 30 days)
        try {
            $r = $db->query("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $cleaned['old_notifications'] = $r->rowCount();
        } catch (\Throwable $e) { $cleaned['old_notifications'] = 'skip'; }
        
        // Old incomplete orders (recovered, > 30 days)
        try {
            $recCol = 'is_recovered';
            try { $db->fetch("SELECT is_recovered FROM incomplete_orders LIMIT 1"); } catch (\Throwable $e) { $recCol = 'recovered'; }
            $r = $db->query("DELETE FROM incomplete_orders WHERE {$recCol} = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $cleaned['old_incomplete'] = $r->rowCount();
        } catch (\Throwable $e) { $cleaned['old_incomplete'] = 'skip'; }
        
        $total = array_sum(array_filter($cleaned, 'is_numeric'));
        echo json_encode(['success' => true, 'data' => [
            'details' => $cleaned,
            'message' => "Cleaned {$total} old records"
        ]]);
        break;

    // ═══════════════════════════════════════
    // IMAGE OPTIMIZATION
    // ═══════════════════════════════════════

    case 'convert_webp':
        if (!function_exists('imagewebp')) {
            echo json_encode(['success' => false, 'message' => 'WebP not supported by server']);
            break;
        }
        $converted = 0; $errors = 0; $saved = 0;
        $dirs = ['products', 'banners', 'general', 'logos'];
        foreach ($dirs as $dir) {
            $path = __DIR__ . '/../uploads/' . $dir;
            if (!is_dir($path)) continue;
            foreach (glob($path . '/*.{jpg,jpeg,png}', GLOB_BRACE) as $img) {
                $webpPath = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $img);
                if (file_exists($webpPath)) continue;
                try {
                    $origSize = filesize($img);
                    $ext = strtolower(pathinfo($img, PATHINFO_EXTENSION));

                    if ($ext === 'png') {
                        // PNG needs special handling for transparency & palette
                        $src = imagecreatefrompng($img);
                        if ($src) {
                            $w = imagesx($src);
                            $h = imagesy($src);
                            // Convert palette to true color
                            if (function_exists('imagepalettetotruecolor')) imagepalettetotruecolor($src);
                            // Create true-color canvas with white bg (WebP transparency can be unreliable)
                            $canvas = imagecreatetruecolor($w, $h);
                            $white = imagecolorallocate($canvas, 255, 255, 255);
                            imagefill($canvas, 0, 0, $white);
                            imagealphablending($canvas, true);
                            imagesavealpha($canvas, false);
                            imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);
                            imagedestroy($src);
                            $src = $canvas;
                        }
                    } else {
                        $src = imagecreatefromjpeg($img);
                    }

                    if ($src && imagewebp($src, $webpPath, 82)) {
                        $saved += ($origSize - filesize($webpPath));
                        $converted++;
                    }
                    if ($src) imagedestroy($src);
                } catch (\Throwable $e) { $errors++; }
            }
        }
        echo json_encode(['success' => true, 'data' => [
            'converted' => $converted, 'errors' => $errors,
            'saved' => round($saved / 1048576, 2) . ' MB',
            'message' => "Converted {$converted} images, saved " . round($saved / 1048576, 2) . " MB"
        ]]);
        break;

    case 'compress_images':
        if (!function_exists('imagecreatefromjpeg')) {
            echo json_encode(['success' => false, 'message' => 'GD library not available']);
            break;
        }
        $compressed = 0; $saved = 0; $maxWidth = 1920; $quality = 85;
        $dirs = ['products', 'banners', 'general'];
        foreach ($dirs as $dir) {
            $path = __DIR__ . '/../uploads/' . $dir;
            if (!is_dir($path)) continue;
            foreach (glob($path . '/*.{jpg,jpeg,png}', GLOB_BRACE) as $img) {
                try {
                    $origSize = filesize($img);
                    if ($origSize < 100000) continue; // Skip < 100KB
                    
                    list($w, $h) = getimagesize($img);
                    if ($w <= $maxWidth && $origSize < 500000) continue; // Already small enough
                    
                    $ext = strtolower(pathinfo($img, PATHINFO_EXTENSION));
                    if ($ext === 'png') {
                        $src = imagecreatefrompng($img);
                        if ($src && function_exists('imagepalettetotruecolor')) imagepalettetotruecolor($src);
                    } else {
                        $src = imagecreatefromjpeg($img);
                    }
                    if (!$src) continue;
                    
                    // Resize if wider than max
                    if ($w > $maxWidth) {
                        $newH = intval($h * ($maxWidth / $w));
                        $resized = imagecreatetruecolor($maxWidth, $newH);
                        if ($ext === 'png') {
                            imagealphablending($resized, false);
                            imagesavealpha($resized, true);
                        }
                        imagecopyresampled($resized, $src, 0, 0, 0, 0, $maxWidth, $newH, $w, $h);
                        imagedestroy($src);
                        $src = $resized;
                    }
                    
                    // Save compressed
                    if ($ext === 'png') {
                        imagepng($src, $img, 8); // compression level 8
                    } else {
                        imagejpeg($src, $img, $quality);
                    }
                    imagedestroy($src);
                    
                    $newSize = filesize($img);
                    $saved += ($origSize - $newSize);
                    $compressed++;
                } catch (\Throwable $e) {}
            }
        }
        echo json_encode(['success' => true, 'data' => [
            'compressed' => $compressed,
            'saved' => round($saved / 1048576, 2) . ' MB',
            'message' => "Compressed {$compressed} images, saved " . round($saved / 1048576, 2) . " MB"
        ]]);
        break;

    // ═══════════════════════════════════════
    // CDN MANAGEMENT
    // ═══════════════════════════════════════

    case 'purge_cdn':
        $urls = json_decode($_POST['urls'] ?? '[]', true) ?: [];
        if (empty($urls)) {
            echo json_encode(['success' => false, 'message' => 'No URLs specified']);
            break;
        }
        
        $cfZoneId = getSetting('cf_zone_id', '');
        $cfApiToken = getSetting('cf_api_token', '');
        $cdnUrl = getSetting('cdn_url', '') ?: SITE_URL;
        $results = ['cloudflare' => null, 'local' => null, 'litespeed' => null];
        
        // 1. Cloudflare API purge (if configured)
        if ($cfZoneId && $cfApiToken) {
            try {
                $purgeAll = in_array('*', $urls);
                $cfEndpoint = "https://api.cloudflare.com/client/v4/zones/{$cfZoneId}/purge_cache";
                
                if ($purgeAll) {
                    $body = json_encode(['purge_everything' => true]);
                } else {
                    // Build full URLs
                    $fullUrls = array_map(function($u) use ($cdnUrl) {
                        if (strpos($u, 'http') === 0) return $u;
                        return rtrim($cdnUrl, '/') . '/' . ltrim($u, '/');
                    }, $urls);
                    $body = json_encode(['files' => $fullUrls]);
                }
                
                $ch = curl_init($cfEndpoint);
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $cfApiToken,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_TIMEOUT => 15,
                ]);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                $cfResult = json_decode($resp, true);
                $results['cloudflare'] = [
                    'success' => ($cfResult['success'] ?? false),
                    'http_code' => $httpCode,
                    'errors' => $cfResult['errors'] ?? [],
                ];
            } catch (\Throwable $e) {
                $results['cloudflare'] = ['success' => false, 'error' => $e->getMessage()];
            }
        }
        
        // 2. LiteSpeed Cache purge (if .htaccess LSCACHE enabled)
        $lscacheFile = __DIR__ . '/../.htaccess';
        try {
            if (file_exists($lscacheFile) && strpos(file_get_contents($lscacheFile), 'LiteSpeed') !== false) {
                // Touch a marker file that LiteSpeed watches
                $purgeDir = __DIR__ . '/../cache/lscache';
                if (!is_dir($purgeDir)) @mkdir($purgeDir, 0755, true);
                
                if (in_array('*', $urls)) {
                    // Purge all - write purge-all marker
                    @file_put_contents($purgeDir . '/purge_all.txt', date('Y-m-d H:i:s'));
                    // Also try header-based purge
                    header('X-LiteSpeed-Purge: *');
                    $results['litespeed'] = ['success' => true, 'message' => 'LiteSpeed purge-all requested'];
                } else {
                    // Individual URL purge via headers
                    foreach ($urls as $u) {
                        $path = parse_url($u, PHP_URL_PATH) ?: $u;
                        header('X-LiteSpeed-Purge: ' . $path);
                    }
                    $results['litespeed'] = ['success' => true, 'urls' => count($urls)];
                }
            }
        } catch (\Throwable $e) {}
        
        // 3. Local file cache purge
        $localCacheDir = __DIR__ . '/../cache/pages';
        if (is_dir($localCacheDir)) {
            if (in_array('*', $urls)) {
                $files = glob($localCacheDir . '/*.html');
                foreach ($files as $f) @unlink($f);
                $results['local'] = ['cleared' => count($files)];
            } else {
                $cleared = 0;
                foreach ($urls as $u) {
                    $key = md5(parse_url($u, PHP_URL_PATH) ?: $u);
                    $cached = $localCacheDir . '/' . $key . '.html';
                    if (file_exists($cached)) { @unlink($cached); $cleared++; }
                }
                $results['local'] = ['cleared' => $cleared];
            }
        }
        
        // Also bust browser cache
        updateSetting('cache_buster', time());
        
        $purgeType = in_array('*', $urls) ? 'all pages' : count($urls) . ' page(s)';
        $cfStatus = $results['cloudflare'] ? ($results['cloudflare']['success'] ? ' (CF: ✓)' : ' (CF: ✗)') : '';
        
        echo json_encode(['success' => true, 'data' => [
            'results' => $results,
            'message' => "CDN purge sent for {$purgeType}{$cfStatus}. Cache buster updated."
        ]]);
        break;

    case 'save_cdn_config':
        updateSetting('cdn_url', trim($_POST['cdn_url'] ?? ''));
        updateSetting('cf_zone_id', trim($_POST['cf_zone_id'] ?? ''));
        updateSetting('cf_api_token', trim($_POST['cf_api_token'] ?? ''));
        echo json_encode(['success' => true, 'message' => 'CDN config saved']);
        break;

    case 'test_cdn':
        $zoneId = trim($_POST['cf_zone_id'] ?? '');
        $apiToken = trim($_POST['cf_api_token'] ?? '');
        
        if (!$zoneId || !$apiToken) {
            echo json_encode(['success' => true, 'data' => ['message' => 'No Cloudflare credentials — will use LiteSpeed/local cache purge only']]);
            break;
        }
        
        try {
            $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$zoneId}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $data = json_decode($resp, true);
            if ($data['success'] ?? false) {
                $zoneName = $data['result']['name'] ?? 'Unknown';
                $zoneStatus = $data['result']['status'] ?? 'unknown';
                echo json_encode(['success' => true, 'data' => [
                    'message' => "Connected! Zone: {$zoneName} (Status: {$zoneStatus})"
                ]]);
            } else {
                $error = $data['errors'][0]['message'] ?? 'Unknown error';
                echo json_encode(['success' => false, 'message' => "CF Error: {$error}"]);
            }
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()]);
        }
        break;

    // ═══════════════════════════════════════
    // SPEED SETTINGS
    // ═══════════════════════════════════════

    case 'save_speed_settings':
        $keys = ['lazy_load_images', 'minify_html', 'defer_js', 'dns_prefetch'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) updateSetting($k, $_POST[$k]);
        }
        if (isset($_POST['preconnect_domains'])) {
            updateSetting('preconnect_domains', trim($_POST['preconnect_domains']));
        }
        echo json_encode(['success' => true, 'message' => 'Speed settings saved']);
        break;

    case 'save_preloader':
        updateSetting('preloader_enabled', $_POST['preloader_enabled'] ?? '0');
        updateSetting('preloader_threshold', max(5, min(200, intval($_POST['preloader_threshold'] ?? 50))));
        updateSetting('preloader_message', trim($_POST['preloader_message'] ?? ''));
        echo json_encode(['success' => true, 'message' => 'Preloader settings saved']);
        break;

    // ═══════════════════════════════════════
    // SERVER STATUS
    // ═══════════════════════════════════════

    case 'server_status':
        $data = [];
        
        // PHP
        $data['php_version'] = PHP_VERSION;
        $data['memory_limit'] = ini_get('memory_limit');
        $data['max_execution_time'] = ini_get('max_execution_time');
        $data['upload_max'] = ini_get('upload_max_filesize');
        $data['post_max'] = ini_get('post_max_size');
        
        // OPcache
        $data['opcache_enabled'] = function_exists('opcache_get_status');
        if ($data['opcache_enabled']) {
            $oc = @opcache_get_status(false);
            if ($oc) {
                $data['opcache_hit_rate'] = round($oc['opcache_statistics']['opcache_hit_rate'] ?? 0, 1);
                $data['opcache_scripts'] = $oc['opcache_statistics']['num_cached_scripts'] ?? 0;
            }
        }
        
        // Disk
        $root = __DIR__ . '/..';
        $data['disk_free'] = round(@disk_free_space($root) / 1073741824, 2);
        $data['disk_total'] = round(@disk_total_space($root) / 1073741824, 2);
        
        // Gzip
        $data['gzip_enabled'] = (ini_get('zlib.output_compression') == '1') || 
                                 in_array('ob_gzhandler', ob_list_handlers()) ||
                                 (function_exists('apache_get_modules') && in_array('mod_deflate', apache_get_modules()));
        
        // WebP support
        $data['webp_support'] = function_exists('imagewebp');
        
        // HTTPS
        $data['https_enabled'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443;
        
        // DB size
        $db = Database::getInstance();
        try {
            $dbSize = $db->fetch("SELECT SUM(data_length + index_length) as total FROM information_schema.TABLES WHERE table_schema = DATABASE()");
            $data['db_size'] = round(($dbSize['total'] ?? 0) / 1048576, 2);
        } catch (\Throwable $e) { $data['db_size'] = 0; }
        
        // Sessions
        $sessionPath = session_save_path() ?: '/tmp';
        $data['active_sessions'] = is_dir($sessionPath) ? count(glob($sessionPath . '/sess_*')) : 0;
        
        // Images
        $uploadDirs = ['products', 'banners', 'logos', 'general'];
        $totalImages = 0; $webpCount = 0; $totalImageSize = 0;
        $imageDirSizes = [];
        foreach ($uploadDirs as $dir) {
            $path = __DIR__ . '/../uploads/' . $dir;
            $dirSize = 0; $dirCount = 0;
            if (!is_dir($path)) continue;
            foreach (glob($path . '/*.{jpg,jpeg,png,gif,webp,svg}', GLOB_BRACE) as $img) {
                $totalImages++;
                $dirCount++;
                $fsize = filesize($img);
                $totalImageSize += $fsize;
                $dirSize += $fsize;
                if (pathinfo($img, PATHINFO_EXTENSION) === 'webp') $webpCount++;
            }
            if ($dirCount > 0) {
                $imageDirSizes[$dir] = ['count' => $dirCount, 'size_mb' => round($dirSize / 1048576, 2)];
            }
        }
        $data['total_images'] = $totalImages;
        $data['webp_images'] = $webpCount;
        $data['images_size'] = round($totalImageSize / 1048576, 1);
        $data['webp_percentage'] = $totalImages > 0 ? round(($webpCount / $totalImages) * 100) : 0;
        $data['image_dirs'] = $imageDirSizes;
        
        // Full website size (recursive)
        $siteRoot = realpath(__DIR__ . '/..');
        $websiteSize = 0;
        $folderSizes = [];
        $topFolders = ['uploads', 'admin', 'includes', 'pages', 'api', 'assets', 'css', 'js'];
        foreach ($topFolders as $folder) {
            $folderPath = $siteRoot . '/' . $folder;
            if (!is_dir($folderPath)) continue;
            $fSize = 0;
            try {
                $iter = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($folderPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iter as $file) {
                    if ($file->isFile()) {
                        $fSize += $file->getSize();
                    }
                }
            } catch (\Throwable $e) {}
            $folderSizes[$folder] = round($fSize / 1048576, 2);
            $websiteSize += $fSize;
        }
        // Add root-level files
        foreach (glob($siteRoot . '/*') as $rootItem) {
            if (is_file($rootItem)) {
                $websiteSize += filesize($rootItem);
            }
        }
        $data['website_size_mb'] = round($websiteSize / 1048576, 2);
        $data['website_size_gb'] = round($websiteSize / 1073741824, 3);
        $data['folder_sizes'] = $folderSizes;
        
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ═══════════════════════════════════════
    // LOAD ANALYSIS (on-demand only)
    // ═══════════════════════════════════════

    case 'load_analysis':
        $ld = [];
        $warnings = [];
        
        // 1. System Load Average
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $ld['load_1m'] = round($load[0], 2);
            $ld['load_5m'] = round($load[1], 2);
            $ld['load_15m'] = round($load[2], 2);
        } else {
            $ld['load_1m'] = $ld['load_5m'] = $ld['load_15m'] = 0;
        }
        
        // 2. CPU Cores
        $ld['cpu_cores'] = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $ld['cpu_cores'] = max(1, substr_count($cpuinfo, 'processor'));
            if (preg_match('/model name\s*:\s*(.+)/i', $cpuinfo, $m)) {
                $ld['cpu_model'] = trim($m[1]);
            }
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $ld['cpu_cores'] = max(1, intval(getenv('NUMBER_OF_PROCESSORS')));
        }
        
        // Load warnings
        if ($ld['load_1m'] > $ld['cpu_cores']) {
            $warnings[] = "Load average ({$ld['load_1m']}) exceeds CPU cores ({$ld['cpu_cores']}) — server is overloaded";
        }
        
        // 3. RAM from /proc/meminfo
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            $extract = function($key) use ($meminfo) {
                if (preg_match('/' . $key . ':\s+(\d+)\s+kB/i', $meminfo, $m)) return intval($m[1]);
                return 0;
            };
            $totalKb = $extract('MemTotal');
            $freeKb = $extract('MemFree');
            $buffKb = $extract('Buffers');
            $cachedKb = $extract('Cached');
            $availKb = $extract('MemAvailable') ?: ($freeKb + $buffKb + $cachedKb);
            $usedKb = $totalKb - $availKb;
            
            $ld['ram_total_mb'] = round($totalKb / 1024);
            $ld['ram_used_mb'] = round($usedKb / 1024);
            $ld['ram_free_mb'] = round($availKb / 1024);
            $ld['ram_cached_mb'] = round(($buffKb + $cachedKb) / 1024);
            
            $ramPct = $totalKb > 0 ? round(($usedKb / $totalKb) * 100) : 0;
            if ($ramPct > 90) $warnings[] = "RAM usage is critically high at {$ramPct}%";
            elseif ($ramPct > 80) $warnings[] = "RAM usage is high at {$ramPct}%";
        }
        
        // 4. PHP Memory
        $ld['php_memory_mb'] = round(memory_get_usage(true) / 1048576, 2);
        $ld['php_peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 2);
        $ld['php_memory_limit'] = ini_get('memory_limit');
        $ld['php_max_exec'] = ini_get('max_execution_time');
        $ld['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
        
        // 5. PHP Processes (lsphp / php-fpm)
        $ld['php_processes'] = 0;
        try {
            $procs = @shell_exec("ps aux 2>/dev/null | grep -c '[l]sphp\\|[p]hp-fpm'");
            $ld['php_processes'] = max(0, intval(trim($procs ?: '0')));
        } catch (\Throwable $e) {}
        
        // 6. MySQL Stats
        $db = Database::getInstance();
        try {
            $threads = $db->fetch("SHOW GLOBAL STATUS LIKE 'Threads_connected'");
            $ld['mysql_threads'] = intval($threads['Value'] ?? 0);
            
            $maxConn = $db->fetch("SHOW VARIABLES LIKE 'max_connections'");
            $ld['mysql_max_conn'] = intval($maxConn['Value'] ?? 0);
            
            $queries = $db->fetch("SHOW GLOBAL STATUS LIKE 'Queries'");
            $ld['mysql_queries'] = intval($queries['Value'] ?? 0);
            
            $slow = $db->fetch("SHOW GLOBAL STATUS LIKE 'Slow_queries'");
            $ld['mysql_slow_queries'] = intval($slow['Value'] ?? 0);
            
            $aborted = $db->fetch("SHOW GLOBAL STATUS LIKE 'Aborted_connects'");
            $ld['mysql_aborted'] = intval($aborted['Value'] ?? 0);
            
            $uptime = $db->fetch("SHOW GLOBAL STATUS LIKE 'Uptime'");
            $uptimeSec = intval($uptime['Value'] ?? 0);
            $days = floor($uptimeSec / 86400);
            $hours = floor(($uptimeSec % 86400) / 3600);
            $ld['mysql_uptime'] = $days > 0 ? "{$days}d {$hours}h" : "{$hours}h " . floor(($uptimeSec % 3600) / 60) . "m";
            
            if ($ld['mysql_threads'] > 0 && $ld['mysql_max_conn'] > 0) {
                $connPct = round(($ld['mysql_threads'] / $ld['mysql_max_conn']) * 100);
                if ($connPct > 80) $warnings[] = "MySQL connections at {$connPct}% of max ({$ld['mysql_threads']}/{$ld['mysql_max_conn']})";
            }
            if ($ld['mysql_slow_queries'] > 100) $warnings[] = "High slow query count: {$ld['mysql_slow_queries']} since last restart";
        } catch (\Throwable $e) {
            $ld['mysql_threads'] = $ld['mysql_max_conn'] = $ld['mysql_queries'] = 0;
        }
        
        // 7. Disk 
        $root = __DIR__ . '/..';
        $diskTotal = @disk_total_space($root);
        $diskFree = @disk_free_space($root);
        if ($diskTotal) {
            $diskUsed = $diskTotal - $diskFree;
            $ld['disk_total_gb'] = round($diskTotal / 1073741824, 2);
            $ld['disk_free_gb'] = round($diskFree / 1073741824, 2);
            $ld['disk_used_gb'] = round($diskUsed / 1073741824, 2);
            $ld['disk_used_pct'] = round(($diskUsed / $diskTotal) * 100);
            
            if ($ld['disk_used_pct'] > 90) $warnings[] = "Disk usage critically high at {$ld['disk_used_pct']}%";
            elseif ($ld['disk_used_pct'] > 80) $warnings[] = "Disk usage high at {$ld['disk_used_pct']}%";
        }
        
        // Inodes
        try {
            $dfOutput = @shell_exec("df -i " . escapeshellarg(realpath($root)) . " 2>/dev/null | tail -1");
            if ($dfOutput && preg_match('/\s(\d+)%\s/', $dfOutput, $m)) {
                $ld['inode_used_pct'] = intval($m[1]);
                if ($ld['inode_used_pct'] > 90) $warnings[] = "Inode usage at {$ld['inode_used_pct']}% — too many small files";
            }
        } catch (\Throwable $e) {}
        
        // 8. Network
        if (is_readable('/proc/net/dev')) {
            $netData = file_get_contents('/proc/net/dev');
            $totalRx = 0; $totalTx = 0;
            foreach (explode("\n", $netData) as $line) {
                if (strpos($line, ':') === false) continue;
                $parts = preg_split('/\s+/', trim($line));
                $iface = rtrim($parts[0], ':');
                if ($iface === 'lo') continue; // skip loopback
                $totalRx += intval($parts[1] ?? 0);
                $totalTx += intval($parts[9] ?? 0);
            }
            $ld['net_rx'] = $totalRx >= 1073741824 ? round($totalRx / 1073741824, 2) . ' GB' : round($totalRx / 1048576, 1) . ' MB';
            $ld['net_tx'] = $totalTx >= 1073741824 ? round($totalTx / 1073741824, 2) . ' GB' : round($totalTx / 1048576, 1) . ' MB';
        }
        
        // 9. TCP Connection States
        try {
            $ss = @shell_exec("ss -s 2>/dev/null");
            if ($ss && preg_match('/TCP:\s+(\d+)\s/', $ss, $m)) {
                $ld['tcp_connections'] = intval($m[1]);
            }
            // Count by state
            $netstat = @shell_exec("ss -tan 2>/dev/null | tail -n +2 | awk '{print \$1}' | sort | uniq -c | sort -rn 2>/dev/null");
            if ($netstat) {
                $ld['tcp_established'] = 0;
                $ld['tcp_time_wait'] = 0;
                $ld['tcp_close_wait'] = 0;
                foreach (explode("\n", trim($netstat)) as $line) {
                    $line = trim($line);
                    if (preg_match('/(\d+)\s+(\S+)/', $line, $m)) {
                        $count = intval($m[1]);
                        $state = strtoupper($m[2]);
                        if ($state === 'ESTAB') $ld['tcp_established'] = $count;
                        elseif ($state === 'TIME-WAIT') $ld['tcp_time_wait'] = $count;
                        elseif ($state === 'CLOSE-WAIT') $ld['tcp_close_wait'] = $count;
                    }
                }
                if (($ld['tcp_close_wait'] ?? 0) > 10) $warnings[] = "High CLOSE_WAIT connections ({$ld['tcp_close_wait']}) — possible connection leak";
                if (($ld['tcp_time_wait'] ?? 0) > 500) $warnings[] = "High TIME_WAIT connections ({$ld['tcp_time_wait']}) — consider tuning tcp_tw_reuse";
            }
        } catch (\Throwable $e) {}
        
        // 10. Page Response Times (self-ping internal pages)
        $ld['page_responses'] = [];
        $siteUrl = SITE_URL;
        $testPages = [
            ['url' => '/', 'label' => 'Home'],
            ['url' => '/shop', 'label' => 'Shop'],
            ['url' => '/login', 'label' => 'Login'],
        ];
        foreach ($testPages as $tp) {
            $start = microtime(true);
            try {
                $ch = curl_init($siteUrl . $tp['url']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_NOBODY => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'SpeedCheck/1.0',
                ]);
                $body = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
                $sizeDownload = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
                curl_close($ch);
                
                $ld['page_responses'][] = [
                    'label' => $tp['label'],
                    'time_ms' => round($totalTime * 1000),
                    'status' => $httpCode,
                    'size_kb' => round($sizeDownload / 1024, 1),
                ];
            } catch (\Throwable $e) {
                $ld['page_responses'][] = ['label' => $tp['label'], 'time_ms' => -1, 'status' => 0, 'size_kb' => 0];
            }
        }
        
        // Slow page warnings
        foreach ($ld['page_responses'] as $pr) {
            if ($pr['time_ms'] > 2000) $warnings[] = "{$pr['label']} page took {$pr['time_ms']}ms — very slow";
            elseif ($pr['time_ms'] > 800) $warnings[] = "{$pr['label']} page took {$pr['time_ms']}ms — could be faster";
        }
        
        // 11. Health Score (0-100)
        $score = 100;
        $cores = $ld['cpu_cores'] ?: 1;
        // Load penalty (-0 to -25)
        $loadRatio = $ld['load_1m'] / $cores;
        if ($loadRatio > 2) $score -= 25;
        elseif ($loadRatio > 1) $score -= 15;
        elseif ($loadRatio > 0.7) $score -= 5;
        
        // RAM penalty (-0 to -25)
        if (isset($ld['ram_total_mb']) && $ld['ram_total_mb'] > 0) {
            $ramPct = round(($ld['ram_used_mb'] / $ld['ram_total_mb']) * 100);
            if ($ramPct > 95) $score -= 25;
            elseif ($ramPct > 85) $score -= 15;
            elseif ($ramPct > 75) $score -= 5;
        }
        
        // MySQL penalty (-0 to -15)
        if ($ld['mysql_threads'] > 0 && $ld['mysql_max_conn'] > 0) {
            $connPct = ($ld['mysql_threads'] / $ld['mysql_max_conn']) * 100;
            if ($connPct > 80) $score -= 15;
            elseif ($connPct > 50) $score -= 5;
        }
        
        // Disk penalty (-0 to -15)
        if (isset($ld['disk_used_pct'])) {
            if ($ld['disk_used_pct'] > 95) $score -= 15;
            elseif ($ld['disk_used_pct'] > 85) $score -= 10;
            elseif ($ld['disk_used_pct'] > 75) $score -= 3;
        }
        
        // Response time penalty (-0 to -20)
        $avgMs = 0; $pCount = count($ld['page_responses']);
        foreach ($ld['page_responses'] as $pr) { $avgMs += max(0, $pr['time_ms']); }
        $avgMs = $pCount > 0 ? $avgMs / $pCount : 0;
        if ($avgMs > 2000) $score -= 20;
        elseif ($avgMs > 1000) $score -= 10;
        elseif ($avgMs > 500) $score -= 5;
        
        $ld['health_score'] = max(0, min(100, $score));
        $ld['warnings'] = $warnings;
        
        $notes = [];
        if ($ld['health_score'] >= 80) $notes[] = 'Server is running smoothly';
        elseif ($ld['health_score'] >= 50) $notes[] = 'Some areas need attention';
        else $notes[] = 'Server is under heavy stress';
        $notes[] = '· Avg response: ' . round($avgMs) . 'ms';
        $ld['health_notes'] = implode(' ', $notes);
        
        echo json_encode(['success' => true, 'data' => $ld]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
