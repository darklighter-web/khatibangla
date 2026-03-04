<?php
/**
 * Server Queue / Preloader Middleware
 * Lightweight file-based concurrent connection tracking.
 * Shows a "please wait" preloader when server is under heavy load.
 * 
 * Include at the very top of index.php BEFORE any other includes.
 * Uses file locking for atomic counter operations - zero DB overhead.
 */

// Skip for API/AJAX requests, admin, and static assets
$uri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($uri, '/api/') !== false || 
    strpos($uri, '/admin/') !== false || 
    strpos($uri, '/uploads/') !== false ||
    strpos($uri, '/assets/') !== false ||
    !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    return;
}

// Config from settings (with hardcoded defaults for zero-DB fallback)
$queueFile = sys_get_temp_dir() . '/site_queue_' . md5(__DIR__) . '.json';

/**
 * Read queue data atomically
 */
function readQueue($file) {
    if (!file_exists($file)) return ['active' => 0, 'connections' => []];
    $fp = @fopen($file, 'r');
    if (!$fp) return ['active' => 0, 'connections' => []];
    flock($fp, LOCK_SH);
    $data = json_decode(fread($fp, filesize($file) ?: 1), true) ?: ['active' => 0, 'connections' => []];
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

/**
 * Write queue data atomically
 */
function writeQueue($file, $data) {
    $fp = @fopen($file, 'c');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// Generate unique connection ID
$connId = session_id() ?: md5($_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT'] ?? '');

// Register this connection
$queue = readQueue($queueFile);
$now = time();

// Clean stale connections (older than 30 seconds)
$queue['connections'] = array_filter($queue['connections'] ?? [], function($ts) use ($now) {
    return ($now - $ts) < 30;
});

// Add/update current connection
$queue['connections'][$connId] = $now;
$queue['active'] = count($queue['connections']);
writeQueue($queueFile, $queue);

// Unregister on shutdown
register_shutdown_function(function() use ($queueFile, $connId) {
    $queue = readQueue($queueFile);
    unset($queue['connections'][$connId]);
    $queue['active'] = count($queue['connections']);
    writeQueue($queueFile, $queue);
});

// Check if preloader is enabled and threshold exceeded
$preloaderEnabled = false;
$threshold = 50;
$message = 'সার্ভারে প্রচুর ভিজিটর আছে, অনুগ্রহ করে অপেক্ষা করুন...';

// Try to read settings from DB (with graceful fallback)
try {
    if (class_exists('Database')) {
        $db = Database::getInstance();
        $settingsRows = $db->fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('preloader_enabled','preloader_threshold','preloader_message')");
        foreach ($settingsRows as $r) {
            if ($r['setting_key'] === 'preloader_enabled') $preloaderEnabled = $r['setting_value'] === '1';
            if ($r['setting_key'] === 'preloader_threshold') $threshold = max(5, intval($r['setting_value']));
            if ($r['setting_key'] === 'preloader_message') $message = $r['setting_value'];
        }
    }
} catch (\Throwable $e) {
    // Silently fallback to defaults
}

// If not enabled or under threshold, continue normally
if (!$preloaderEnabled || $queue['active'] < $threshold) {
    return;
}

// Calculate queue position and estimated wait
$position = max(1, $queue['active'] - $threshold);
$estimatedWait = ceil($position * 2); // ~2 seconds per position

// Show preloader page and exit
http_response_code(200);
header('Retry-After: ' . $estimatedWait);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>অনুগ্রহ করে অপেক্ষা করুন...</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .queue-container {
            text-align: center;
            padding: 2rem;
            max-width: 450px;
        }
        .spinner {
            width: 60px; height: 60px;
            margin: 0 auto 1.5rem;
            border: 4px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .queue-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
        }
        .queue-message {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }
        .queue-stats {
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            display: inline-flex;
            gap: 2rem;
            backdrop-filter: blur(10px);
        }
        .stat { text-align: center; }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            display: block;
        }
        .stat-label {
            font-size: 0.75rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .progress-bar {
            width: 100%;
            height: 4px;
            background: rgba(255,255,255,0.2);
            border-radius: 2px;
            margin-top: 1.5rem;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #fff;
            border-radius: 2px;
            animation: progress 3s ease-in-out infinite;
            width: 30%;
        }
        @keyframes progress {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(400%); }
        }
        .retry-note {
            margin-top: 1rem;
            font-size: 0.8rem;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="queue-container">
        <div class="spinner"></div>
        <h1 class="queue-title">অপেক্ষা করুন</h1>
        <p class="queue-message"><?= htmlspecialchars($message) ?></p>
        
        <div class="queue-stats">
            <div class="stat">
                <span class="stat-value" id="position"><?= $position ?></span>
                <span class="stat-label">কিউ পজিশন</span>
            </div>
            <div class="stat">
                <span class="stat-value" id="wait">~<?= $estimatedWait ?>s</span>
                <span class="stat-label">আনুমানিক সময়</span>
            </div>
        </div>
        
        <div class="progress-bar"><div class="progress-fill"></div></div>
        <p class="retry-note">পেজটি স্বয়ংক্রিয়ভাবে রিফ্রেশ হবে...</p>
    </div>

    <script>
        // Auto-retry with exponential backoff
        let retryCount = 0;
        const maxRetry = 20;
        
        function tryReload() {
            retryCount++;
            if (retryCount > maxRetry) {
                document.querySelector('.queue-message').textContent = 'দীর্ঘ অপেক্ষার জন্য দুঃখিত। পেজটি রিফ্রেশ করুন।';
                return;
            }
            
            // Check if queue has cleared via lightweight HEAD request
            fetch(window.location.href, { method: 'HEAD', cache: 'no-store' })
            .then(r => {
                if (r.ok && !r.headers.get('Retry-After')) {
                    window.location.reload();
                } else {
                    // Update position estimate
                    const pos = Math.max(1, <?= $position ?> - retryCount);
                    document.getElementById('position').textContent = pos;
                    document.getElementById('wait').textContent = '~' + Math.max(2, pos * 2) + 's';
                    setTimeout(tryReload, 3000 + (retryCount * 500));
                }
            })
            .catch(() => {
                setTimeout(tryReload, 5000);
            });
        }
        
        // First retry after estimated wait time
        setTimeout(tryReload, <?= min($estimatedWait * 1000, 10000) ?>);
    </script>
</body>
</html>
<?php
exit;
