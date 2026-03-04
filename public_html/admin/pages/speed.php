<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Speed & Performance';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();

// Get current settings
$preloaderEnabled = getSetting('preloader_enabled', '0');
$preloaderThreshold = getSetting('preloader_threshold', '50');
$preloaderMessage = getSetting('preloader_message', 'সার্ভারে প্রচুর ভিজিটর আছে, অনুগ্রহ করে অপেক্ষা করুন...');
$cdnUrl = getSetting('cdn_url', '');
$cfZoneId = getSetting('cf_zone_id', '');
$cfApiToken = getSetting('cf_api_token', '');
$lazyLoad = getSetting('lazy_load_images', '1');
$minifyHtml = getSetting('minify_html', '0');
$cacheBuster = getSetting('cache_buster', time());
$deferJs = getSetting('defer_js', '1');
$preconnectDomains = getSetting('preconnect_domains', '');

// Get list of site pages for CDN purge
$pages = [
    ['url' => '/', 'label' => 'Home Page', 'icon' => 'fas fa-home'],
    ['url' => '/shop', 'label' => 'Shop / All Products', 'icon' => 'fas fa-store'],
    ['url' => '/search', 'label' => 'Search Page', 'icon' => 'fas fa-search'],
    ['url' => '/cart', 'label' => 'Cart Page', 'icon' => 'fas fa-shopping-cart'],
    ['url' => '/login', 'label' => 'Login Page', 'icon' => 'fas fa-sign-in-alt'],
    ['url' => '/register', 'label' => 'Register Page', 'icon' => 'fas fa-user-plus'],
    ['url' => '/track-order', 'label' => 'Track Order', 'icon' => 'fas fa-truck'],
];

// Get categories for CDN purge
try {
    $cats = $db->fetchAll("SELECT slug, name FROM categories WHERE is_active = 1 ORDER BY name LIMIT 20");
    foreach ($cats as $c) {
        $pages[] = ['url' => '/category/' . $c['slug'], 'label' => 'Category: ' . $c['name'], 'icon' => 'fas fa-folder'];
    }
} catch (\Throwable $e) {}

// Top 10 products
try {
    $prods = $db->fetchAll("SELECT slug, name FROM products WHERE is_active = 1 ORDER BY sales_count DESC LIMIT 10");
    foreach ($prods as $p) {
        $pages[] = ['url' => '/' . $p['slug'], 'label' => 'Product: ' . mb_substr($p['name'], 0, 30), 'icon' => 'fas fa-box'];
    }
} catch (\Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-xl font-bold text-gray-800">⚡ Speed & Performance</h2>
            <p class="text-sm text-gray-500 mt-1">Cache, CDN, optimization, and server health management</p>
        </div>
        <div class="flex gap-2">
            <button onclick="loadServerStatus()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                <i class="fas fa-sync-alt mr-1"></i> Refresh
            </button>
            <button onclick="clearAll()" class="bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-900 transition">
                <i class="fas fa-fire mr-1"></i> Clear All Caches
            </button>
        </div>
    </div>

    <!-- Server Status Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
        <div class="bg-white rounded-xl border p-3 text-center">
            <i class="fas fa-microchip text-blue-500 text-lg mb-1"></i>
            <p class="text-xs text-gray-500">PHP</p>
            <p class="text-sm font-bold text-gray-800" id="st-php">—</p>
        </div>
        <div class="bg-white rounded-xl border p-3 text-center">
            <i class="fas fa-database text-green-500 text-lg mb-1"></i>
            <p class="text-xs text-gray-500">DB Size</p>
            <p class="text-sm font-bold text-gray-800" id="st-db">—</p>
        </div>
        <div class="bg-white rounded-xl border p-3 text-center">
            <i class="fas fa-globe text-indigo-500 text-lg mb-1"></i>
            <p class="text-xs text-gray-500">Website Size</p>
            <p class="text-sm font-bold text-gray-800" id="st-website">—</p>
        </div>
        <div class="bg-white rounded-xl border p-3 text-center">
            <i class="fas fa-image text-pink-500 text-lg mb-1"></i>
            <p class="text-xs text-gray-500">Image Size</p>
            <p class="text-sm font-bold text-gray-800" id="st-img-total">—</p>
        </div>
        <div class="bg-white rounded-xl border p-3 text-center">
            <i class="fas fa-hdd text-purple-500 text-lg mb-1"></i>
            <p class="text-xs text-gray-500">Disk Free</p>
            <p class="text-sm font-bold text-gray-800" id="st-disk">—</p>
        </div>
        <div class="bg-white rounded-xl border p-3 text-center">
            <i class="fas fa-bolt text-yellow-500 text-lg mb-1"></i>
            <p class="text-xs text-gray-500">OPcache Hit</p>
            <p class="text-sm font-bold text-gray-800" id="st-opcache-hit">—</p>
        </div>
        <div class="bg-white rounded-xl border p-3 text-center">
            <i class="fas fa-users text-orange-500 text-lg mb-1"></i>
            <p class="text-xs text-gray-500">Sessions</p>
            <p class="text-sm font-bold text-gray-800" id="st-sessions">—</p>
        </div>
        <div class="bg-white rounded-xl border p-3 text-center">
            <i class="fas fa-images text-teal-500 text-lg mb-1"></i>
            <p class="text-xs text-gray-500">Images</p>
            <p class="text-sm font-bold text-gray-800" id="st-images">—</p>
        </div>
    </div>

    <!-- Storage Breakdown -->
    <div class="grid lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-folder-open text-indigo-500 mr-1"></i> Folder Size Breakdown</h3>
            <div id="folder-breakdown" class="space-y-2">
                <div class="text-xs text-gray-400 text-center py-4">Loading...</div>
            </div>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-camera text-pink-500 mr-1"></i> Image Storage by Folder</h3>
            <div id="image-breakdown" class="space-y-2">
                <div class="text-xs text-gray-400 text-center py-4">Loading...</div>
            </div>
        </div>
    </div>

    <!-- Server Health Checks -->
    <div class="bg-white rounded-xl border p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-heartbeat text-red-500 mr-1"></i> Server Health</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2" id="health-checks">
            <div class="flex items-center gap-2 p-2 rounded-lg bg-gray-50" id="check-gzip"><i class="fas fa-circle text-gray-300 text-xs"></i><span class="text-xs text-gray-600">Gzip</span></div>
            <div class="flex items-center gap-2 p-2 rounded-lg bg-gray-50" id="check-opcache"><i class="fas fa-circle text-gray-300 text-xs"></i><span class="text-xs text-gray-600">OPcache</span></div>
            <div class="flex items-center gap-2 p-2 rounded-lg bg-gray-50" id="check-memory"><i class="fas fa-circle text-gray-300 text-xs"></i><span class="text-xs text-gray-600">Memory</span></div>
            <div class="flex items-center gap-2 p-2 rounded-lg bg-gray-50" id="check-webp"><i class="fas fa-circle text-gray-300 text-xs"></i><span class="text-xs text-gray-600">WebP Support</span></div>
            <div class="flex items-center gap-2 p-2 rounded-lg bg-gray-50" id="check-https"><i class="fas fa-circle text-gray-300 text-xs"></i><span class="text-xs text-gray-600">HTTPS</span></div>
            <div class="flex items-center gap-2 p-2 rounded-lg bg-gray-50" id="check-upload"><i class="fas fa-circle text-gray-300 text-xs"></i><span class="text-xs text-gray-600">Upload Limit</span></div>
        </div>
    </div>

    <!-- ═══ CURRENT LOAD ANALYSIS — runs ONCE on page visit, not background ═══ -->
    <div class="bg-white rounded-xl border shadow-sm">
        <div class="px-5 py-4 border-b flex items-center justify-between flex-wrap gap-3">
            <div>
                <h3 class="font-semibold text-gray-800"><i class="fas fa-tachometer-alt text-red-500 mr-2"></i>Current Server Load</h3>
                <p class="text-xs text-gray-500 mt-0.5">Live snapshot — only measured when you open this page. No background polling.</p>
            </div>
            <div class="flex items-center gap-2">
                <span id="load-timestamp" class="text-[10px] text-gray-400 font-mono"></span>
                <button onclick="runLoadAnalysis()" id="btn-load-refresh" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 transition">
                    <i class="fas fa-sync-alt mr-1"></i> Re-analyze
                </button>
            </div>
        </div>
        <div class="p-5 space-y-5" id="load-container">
            <!-- Loading skeleton -->
            <div id="load-skeleton" class="space-y-4">
                <div class="flex items-center gap-3 text-sm text-gray-500"><i class="fas fa-spinner fa-spin text-red-500"></i> Analyzing server load... (this runs only now, not in background)</div>
                <div class="grid md:grid-cols-3 gap-3">
                    <div class="h-24 bg-gray-100 rounded-xl animate-pulse"></div>
                    <div class="h-24 bg-gray-100 rounded-xl animate-pulse"></div>
                    <div class="h-24 bg-gray-100 rounded-xl animate-pulse"></div>
                </div>
            </div>
            <!-- Results filled by JS -->
            <div id="load-results" class="hidden space-y-5"></div>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <!-- Cache & Cleanup -->
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="px-5 py-4 border-b">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-broom text-red-500 mr-2"></i>Cache & Cleanup</h3>
            </div>
            <div class="p-5 space-y-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">OPcache</p>
                        <p class="text-xs text-gray-500">Clear compiled PHP bytecode · <span id="st-opcache-scripts">—</span> scripts cached</p>
                    </div>
                    <button onclick="runAction('clear_opcache', this)" class="px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-medium hover:bg-red-700 transition flex-shrink-0">
                        <i class="fas fa-trash-alt mr-1"></i> Clear
                    </button>
                </div>
                
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">Expired Sessions</p>
                        <p class="text-xs text-gray-500">Remove sessions older than 24 hours</p>
                    </div>
                    <button onclick="runAction('clear_sessions', this)" class="px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-medium hover:bg-red-700 transition flex-shrink-0">
                        <i class="fas fa-trash-alt mr-1"></i> Clear
                    </button>
                </div>

                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">Temp Files</p>
                        <p class="text-xs text-gray-500">Remove cache/tmp files older than 1 hour</p>
                    </div>
                    <button onclick="runAction('clear_temp', this)" class="px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-medium hover:bg-red-700 transition flex-shrink-0">
                        <i class="fas fa-trash-alt mr-1"></i> Clear
                    </button>
                </div>

                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">Query Cache</p>
                        <p class="text-xs text-gray-500">Reset MySQL query cache</p>
                    </div>
                    <button onclick="runAction('clear_query_cache', this)" class="px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-medium hover:bg-red-700 transition flex-shrink-0">
                        <i class="fas fa-trash-alt mr-1"></i> Clear
                    </button>
                </div>

                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">Browser Cache Buster</p>
                        <p class="text-xs text-gray-500">Force all visitors to reload CSS/JS by updating version stamp</p>
                        <p class="text-xs text-gray-400 mt-0.5">Current: v<?= $cacheBuster ?></p>
                    </div>
                    <button onclick="runAction('bust_cache', this)" class="px-3 py-1.5 bg-orange-600 text-white rounded-lg text-xs font-medium hover:bg-orange-700 transition flex-shrink-0">
                        <i class="fas fa-sync mr-1"></i> Update
                    </button>
                </div>
            </div>
        </div>

        <!-- Database & Image Optimization -->
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="px-5 py-4 border-b">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-database text-green-500 mr-2"></i>Database & Images</h3>
            </div>
            <div class="p-5 space-y-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">Optimize Tables</p>
                        <p class="text-xs text-gray-500">Defragment all tables and reclaim space</p>
                    </div>
                    <button onclick="runAction('optimize_db', this)" class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700 transition flex-shrink-0">
                        <i class="fas fa-magic mr-1"></i> Optimize
                    </button>
                </div>

                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">Clean Old Data</p>
                        <p class="text-xs text-gray-500">Remove visitor logs > 90d, old sessions, expired carts</p>
                    </div>
                    <button onclick="runAction('clean_old_data', this)" class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700 transition flex-shrink-0">
                        <i class="fas fa-eraser mr-1"></i> Clean
                    </button>
                </div>

                <div class="p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-800">Clean Customer Uploads</p>
                            <p class="text-xs text-gray-500">Delete all customer-uploaded files (prescriptions, photos)</p>
                        </div>
                        <button onclick="if(confirm('This will permanently delete ALL customer upload files. Continue?')){fetch('<?= SITE_URL ?>/api/speed.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'clean_customer_uploads'})}).then(r=>r.json()).then(d=>{showToast(d.success?'Deleted '+d.data.deleted+' files':'Failed',d.success?'success':'error');}).catch(()=>showToast('Error','error'));}" class="px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-medium hover:bg-red-700 transition flex-shrink-0">
                            <i class="fas fa-trash-alt mr-1"></i> Clean
                        </button>
                    </div>
                </div>

                <div class="p-3 bg-gray-50 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-sm font-medium text-gray-800">Convert Images to WebP</p>
                            <p class="text-xs text-gray-500">Batch convert JPG/PNG for faster loading</p>
                        </div>
                        <button onclick="runAction('convert_webp', this)" class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700 transition flex-shrink-0">
                            <i class="fas fa-images mr-1"></i> Convert
                        </button>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <span class="text-gray-500">Total: <strong id="st-total-img">—</strong></span>
                        <span class="text-green-600">WebP: <strong id="st-webp">—</strong></span>
                        <span class="text-blue-600">Size: <strong id="st-img-size">—</strong></span>
                    </div>
                    <div class="mt-2 w-full bg-gray-200 rounded-full h-1.5">
                        <div id="webp-progress" class="bg-green-500 h-1.5 rounded-full transition-all" style="width:0%"></div>
                    </div>
                </div>

                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">Compress Large Images</p>
                        <p class="text-xs text-gray-500">Resize images > 1920px width & reduce quality to 85%</p>
                    </div>
                    <button onclick="runAction('compress_images', this)" class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-xs font-medium hover:bg-green-700 transition flex-shrink-0">
                        <i class="fas fa-compress mr-1"></i> Compress
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- CDN & Page Cache Management -->
    <div class="bg-white rounded-xl border shadow-sm">
        <div class="px-5 py-4 border-b flex items-center justify-between flex-wrap gap-3">
            <div>
                <h3 class="font-semibold text-gray-800"><i class="fas fa-globe text-blue-500 mr-2"></i>CDN & Page Cache Management</h3>
                <p class="text-xs text-gray-500 mt-1">Purge CDN cache for specific pages or all content. Supports Cloudflare & custom CDN.</p>
            </div>
            <div class="flex gap-2">
                <button onclick="purgeSelectedPages()" id="btn-purge-selected" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    <i class="fas fa-sync mr-1"></i> Purge Selected
                </button>
                <button onclick="purgeAllCdn()" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition">
                    <i class="fas fa-fire mr-1"></i> Purge All CDN
                </button>
            </div>
        </div>
        <div class="p-5">
            <!-- CDN Configuration -->
            <div class="mb-5 p-4 bg-blue-50 rounded-lg border border-blue-200">
                <h4 class="text-sm font-semibold text-blue-800 mb-3"><i class="fas fa-cog mr-1"></i> CDN Configuration</h4>
                <div class="grid md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">CDN / Site URL</label>
                        <input type="text" id="cdn_url" value="<?= e($cdnUrl ?: SITE_URL) ?>" placeholder="https://khatibangla.com" class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Cloudflare Zone ID <span class="text-gray-400">(optional)</span></label>
                        <input type="text" id="cf_zone_id" value="<?= e($cfZoneId) ?>" placeholder="abc123..." class="w-full px-3 py-2 border rounded-lg text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Cloudflare API Token <span class="text-gray-400">(optional)</span></label>
                        <input type="password" id="cf_api_token" value="<?= e($cfApiToken) ?>" placeholder="Bearer token..." class="w-full px-3 py-2 border rounded-lg text-sm font-mono">
                    </div>
                </div>
                <div class="flex items-center gap-3 mt-3">
                    <button onclick="saveCdnConfig()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-xs font-medium hover:bg-blue-700 transition">
                        <i class="fas fa-save mr-1"></i> Save CDN Config
                    </button>
                    <button onclick="testCdnConnection()" class="px-4 py-2 bg-gray-100 border text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-200 transition">
                        <i class="fas fa-plug mr-1"></i> Test Connection
                    </button>
                    <span id="cdn-config-msg" class="text-xs text-green-600 hidden"><i class="fas fa-check mr-1"></i> Saved!</span>
                    <span id="cdn-test-msg" class="text-xs hidden"></span>
                </div>
            </div>

            <!-- Custom URL Purge -->
            <div class="mb-5 p-4 bg-gray-50 rounded-lg">
                <h4 class="text-sm font-semibold text-gray-700 mb-2"><i class="fas fa-link mr-1"></i> Purge Custom URL</h4>
                <div class="flex gap-2">
                    <input type="text" id="custom-purge-url" placeholder="Enter full URL or path, e.g. /my-product-page" class="flex-1 px-3 py-2 border rounded-lg text-sm">
                    <button onclick="purgeCustomUrl()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition flex-shrink-0">
                        <i class="fas fa-sync mr-1"></i> Purge
                    </button>
                </div>
            </div>

            <!-- Page List for Selective Purge -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-sm font-semibold text-gray-700"><i class="fas fa-file mr-1"></i> Site Pages</h4>
                    <label class="flex items-center gap-2 text-xs text-gray-500 cursor-pointer">
                        <input type="checkbox" id="select-all-pages" onclick="toggleAllPages(this)" class="rounded"> Select All
                    </label>
                </div>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-2 max-h-80 overflow-y-auto" id="page-list">
                    <?php foreach ($pages as $i => $pg): ?>
                    <label class="flex items-center gap-2 p-2.5 bg-gray-50 rounded-lg hover:bg-gray-100 cursor-pointer transition text-sm group">
                        <input type="checkbox" class="page-cb rounded" value="<?= e($pg['url']) ?>" onchange="updatePurgeBtn()">
                        <i class="<?= $pg['icon'] ?> text-gray-400 group-hover:text-blue-500 w-4 text-center text-xs"></i>
                        <span class="flex-1 truncate text-gray-700"><?= e($pg['label']) ?></span>
                        <button type="button" onclick="event.preventDefault(); purgeSinglePage('<?= e($pg['url']) ?>', this)" class="opacity-0 group-hover:opacity-100 text-xs text-blue-500 hover:text-blue-700 transition px-1.5 py-0.5 rounded bg-blue-50 flex-shrink-0">
                            <i class="fas fa-sync"></i>
                        </button>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Page Speed Optimization Settings -->
    <div class="bg-white rounded-xl border shadow-sm">
        <div class="px-5 py-4 border-b">
            <h3 class="font-semibold text-gray-800"><i class="fas fa-tachometer-alt text-green-500 mr-2"></i>Page Speed Optimization</h3>
            <p class="text-xs text-gray-500 mt-1">Toggle frontend optimizations for faster page loads</p>
        </div>
        <div class="p-5 space-y-4">
            <div class="grid md:grid-cols-2 gap-4">
                <!-- Lazy Loading -->
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">Lazy Load Images</p>
                        <p class="text-xs text-gray-500">Defer loading images until visible in viewport</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer speed-toggle" data-key="lazy_load_images" <?= $lazyLoad === '1' ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                    </label>
                </div>

                <!-- Minify HTML -->
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">Minify HTML Output</p>
                        <p class="text-xs text-gray-500">Remove whitespace from HTML to reduce page size</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer speed-toggle" data-key="minify_html" <?= $minifyHtml === '1' ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                    </label>
                </div>

                <!-- Defer JS -->
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">Defer JavaScript</p>
                        <p class="text-xs text-gray-500">Add defer attribute to non-critical scripts</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer speed-toggle" data-key="defer_js" <?= $deferJs === '1' ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                    </label>
                </div>

                <!-- Preconnect -->
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-medium text-gray-800">DNS Prefetch</p>
                        <p class="text-xs text-gray-500">Preconnect to CDN & third-party domains</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer speed-toggle" data-key="dns_prefetch" <?= getSetting('dns_prefetch', '1') === '1' ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                    </label>
                </div>
            </div>

            <!-- Preconnect Domains -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Preconnect Domains <span class="text-xs text-gray-400">(one per line)</span></label>
                <textarea id="preconnect_domains" rows="3" class="w-full px-3 py-2 border rounded-lg text-sm font-mono" placeholder="https://fonts.googleapis.com&#10;https://cdnjs.cloudflare.com&#10;https://cdn.yoursite.com"><?= e($preconnectDomains) ?></textarea>
            </div>

            <button onclick="saveSpeedSettings()" class="bg-green-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition">
                <i class="fas fa-save mr-1"></i> Save Speed Settings
            </button>
            <span id="speed-save-msg" class="text-sm text-green-600 hidden ml-2"><i class="fas fa-check mr-1"></i> Saved!</span>
        </div>
    </div>

    <!-- Queue Preloader Settings -->
    <div class="bg-white rounded-xl border shadow-sm">
        <div class="px-5 py-4 border-b">
            <h3 class="font-semibold text-gray-800"><i class="fas fa-hourglass-half text-purple-500 mr-2"></i>Queue Preloader</h3>
            <p class="text-xs text-gray-500 mt-1">Show a wait page when too many visitors are on the site</p>
        </div>
        <div class="p-5 space-y-4">
            <div class="flex items-center gap-4">
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="preloader_enabled" <?= $preloaderEnabled === '1' ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                </label>
                <div>
                    <p class="text-sm font-medium text-gray-800">Enable Queue Preloader</p>
                    <p class="text-xs text-gray-500">When connections exceed threshold, new visitors see a wait page</p>
                </div>
            </div>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Connection Threshold</label>
                    <div class="flex items-center gap-3">
                        <input type="range" id="preloader_threshold" min="5" max="200" value="<?= intval($preloaderThreshold) ?>" 
                               class="flex-1" oninput="document.getElementById('threshold-val').textContent=this.value">
                        <span id="threshold-val" class="text-lg font-bold text-purple-600 w-10 text-center"><?= intval($preloaderThreshold) ?></span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Queue Message</label>
                    <textarea id="preloader_message" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"><?= htmlspecialchars($preloaderMessage) ?></textarea>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="savePreloader()" class="bg-purple-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-purple-700 transition"><i class="fas fa-save mr-1"></i> Save</button>
                <button onclick="previewPreloader()" class="border border-gray-300 text-gray-700 px-5 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition"><i class="fas fa-eye mr-1"></i> Preview</button>
                <span id="preloader-msg" class="text-sm text-green-600 hidden"><i class="fas fa-check mr-1"></i> Saved!</span>
            </div>
        </div>
    </div>

    <!-- .htaccess Performance Rules -->
    <div class="bg-white rounded-xl border shadow-sm">
        <div class="px-5 py-4 border-b">
            <h3 class="font-semibold text-gray-800"><i class="fas fa-file-code text-blue-500 mr-2"></i>Performance .htaccess Rules</h3>
        </div>
        <div class="p-5">
            <p class="text-sm text-gray-600 mb-3">Add to <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs font-mono">.htaccess</code> for optimal performance:</p>
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-xs font-mono overflow-x-auto relative max-h-60 overflow-y-auto">
                <button onclick="copyHtaccess()" class="absolute top-2 right-2 text-gray-400 hover:text-white text-xs bg-gray-800 px-2 py-1 rounded"><i class="fas fa-copy mr-1"></i>Copy</button>
                <pre id="htaccess-code"># === GZIP COMPRESSION ===
&lt;IfModule mod_deflate.c&gt;
  AddOutputFilterByType DEFLATE text/html text/css text/javascript
  AddOutputFilterByType DEFLATE application/javascript application/json
  AddOutputFilterByType DEFLATE image/svg+xml application/xml
&lt;/IfModule&gt;

# === BROWSER CACHING ===
&lt;IfModule mod_expires.c&gt;
  ExpiresActive On
  ExpiresByType image/jpg "access plus 1 year"
  ExpiresByType image/jpeg "access plus 1 year"
  ExpiresByType image/png "access plus 1 year"
  ExpiresByType image/webp "access plus 1 year"
  ExpiresByType image/gif "access plus 1 year"
  ExpiresByType image/svg+xml "access plus 1 month"
  ExpiresByType text/css "access plus 1 month"
  ExpiresByType application/javascript "access plus 1 month"
  ExpiresByType application/font-woff2 "access plus 1 year"
&lt;/IfModule&gt;

# === KEEP-ALIVE & SECURITY HEADERS ===
&lt;IfModule mod_headers.c&gt;
  Header set Connection keep-alive
  Header set X-Content-Type-Options "nosniff"
  Header set X-XSS-Protection "1; mode=block"
  Header set Referrer-Policy "strict-origin-when-cross-origin"
&lt;/IfModule&gt;

# === ETag Removal (reduce headers) ===
&lt;IfModule mod_headers.c&gt;
  Header unset ETag
&lt;/IfModule&gt;
FileETag None

# === WEBP AUTO-SERVE ===
&lt;IfModule mod_rewrite.c&gt;
  RewriteEngine On
  RewriteCond %{HTTP_ACCEPT} image/webp
  RewriteCond %{REQUEST_FILENAME} (.*)\.(jpe?g|png)$
  RewriteCond %1.webp -f
  RewriteRule (.+)\.(jpe?g|png)$ $1.webp [T=image/webp,L]
&lt;/IfModule&gt;</pre>
            </div>
        </div>
    </div>

    <!-- Action Log -->
    <div id="action-log" class="hidden bg-white rounded-xl border shadow-sm">
        <div class="px-5 py-4 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800"><i class="fas fa-clipboard-list text-gray-500 mr-2"></i>Action Log</h3>
            <button onclick="document.getElementById('log-entries').innerHTML=''; document.getElementById('action-log').classList.add('hidden')" class="text-xs text-gray-400 hover:text-gray-600">Clear</button>
        </div>
        <div id="log-entries" class="p-3 space-y-2 max-h-60 overflow-y-auto"></div>
    </div>
</div>

<script>
const API = '<?= SITE_URL ?>/api/speed.php';

function runAction(action, btn) {
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Running...';
    
    const fd = new FormData();
    fd.append('action', action);
    
    return fetch(API, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        const msg = data.data?.message || data.message || 'Done';
        addLog(action, msg, data.success !== false);
        
        btn.classList.add('!bg-emerald-500');
        btn.innerHTML = '<i class="fas fa-check mr-1"></i> Done!';
        setTimeout(() => { btn.innerHTML = origHtml; btn.classList.remove('!bg-emerald-500'); }, 2000);
        return data;
    })
    .catch(err => { btn.disabled = false; btn.innerHTML = origHtml; addLog(action, 'Error: ' + err.message, false); });
}

async function clearAll() {
    const actions = ['clear_opcache', 'clear_sessions', 'clear_temp', 'clear_query_cache', 'bust_cache'];
    for (const action of actions) {
        const btn = document.querySelector(`[onclick*="'${action}'"]`);
        if (btn) await runAction(action, btn);
    }
    addLog('clear_all', '🔥 All caches cleared!', true);
    loadServerStatus();
}

function loadServerStatus() {
    fetch(API + '?action=server_status')
    .then(r => r.json())
    .then(res => {
        const d = res.data;
        document.getElementById('st-php').textContent = d.php_version || '—';
        document.getElementById('st-db').textContent = (d.db_size || 0) + ' MB';
        document.getElementById('st-disk').textContent = (d.disk_free || 0) + ' GB';
        document.getElementById('st-opcache-hit').textContent = (d.opcache_hit_rate || 0) + '%';
        document.getElementById('st-sessions').textContent = d.active_sessions || 0;
        document.getElementById('st-opcache-scripts').textContent = d.opcache_scripts || 0;
        document.getElementById('st-images').textContent = (d.total_images || 0) + ' (' + (d.webp_images || 0) + ' webp)';
        document.getElementById('st-total-img').textContent = d.total_images || 0;
        document.getElementById('st-webp').textContent = d.webp_images || 0;
        document.getElementById('st-img-size').textContent = (d.images_size || 0) + ' MB';
        document.getElementById('webp-progress').style.width = (d.webp_percentage || 0) + '%';
        
        // Website size & image size cards
        const wsMb = d.website_size_mb || 0;
        document.getElementById('st-website').textContent = wsMb >= 1024 ? (d.website_size_gb || 0) + ' GB' : wsMb + ' MB';
        document.getElementById('st-img-total').textContent = (d.images_size || 0) + ' MB';
        
        // Folder breakdown
        const folderEl = document.getElementById('folder-breakdown');
        if (d.folder_sizes && Object.keys(d.folder_sizes).length) {
            const total = wsMb || 1;
            const icons = {uploads:'fa-cloud-upload-alt text-pink-500',admin:'fa-cog text-blue-500',includes:'fa-code text-green-500',pages:'fa-file text-indigo-500',api:'fa-plug text-orange-500',assets:'fa-palette text-purple-500',css:'fa-paint-brush text-teal-500',js:'fa-js-square text-yellow-500'};
            let html = '';
            // Sort by size desc
            const sorted = Object.entries(d.folder_sizes).sort((a,b) => b[1] - a[1]);
            sorted.forEach(([folder, sizeMb]) => {
                const pct = Math.min(100, Math.round((sizeMb / total) * 100));
                const ic = icons[folder] || 'fa-folder text-gray-400';
                html += `<div>
                    <div class="flex items-center justify-between text-xs mb-0.5">
                        <span class="flex items-center gap-1.5 font-medium text-gray-700"><i class="fas ${ic} w-3.5 text-center"></i> /${folder}</span>
                        <span class="text-gray-500 font-mono">${sizeMb} MB <span class="text-gray-400">(${pct}%)</span></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5"><div class="h-1.5 rounded-full bg-indigo-400 transition-all" style="width:${pct}%"></div></div>
                </div>`;
            });
            html += `<div class="pt-2 mt-2 border-t flex items-center justify-between text-xs font-semibold text-gray-800">
                <span><i class="fas fa-globe text-indigo-500 mr-1"></i> Total Website</span>
                <span class="font-mono">${wsMb >= 1024 ? (d.website_size_gb || 0) + ' GB' : wsMb + ' MB'}</span>
            </div>`;
            folderEl.innerHTML = html;
        } else {
            folderEl.innerHTML = '<div class="text-xs text-gray-400 text-center py-4">No data</div>';
        }
        
        // Image breakdown by folder
        const imgEl = document.getElementById('image-breakdown');
        if (d.image_dirs && Object.keys(d.image_dirs).length) {
            const totalImgMb = d.images_size || 1;
            const imgIcons = {products:'fa-box text-blue-500',banners:'fa-image text-orange-500',logos:'fa-copyright text-purple-500',general:'fa-folder text-green-500'};
            let ihtml = '';
            const imgSorted = Object.entries(d.image_dirs).sort((a,b) => b[1].size_mb - a[1].size_mb);
            imgSorted.forEach(([folder, info]) => {
                const pct = Math.min(100, Math.round((info.size_mb / totalImgMb) * 100));
                const ic = imgIcons[folder] || 'fa-folder text-gray-400';
                ihtml += `<div>
                    <div class="flex items-center justify-between text-xs mb-0.5">
                        <span class="flex items-center gap-1.5 font-medium text-gray-700"><i class="fas ${ic} w-3.5 text-center"></i> ${folder}</span>
                        <span class="text-gray-500"><span class="font-mono">${info.size_mb} MB</span> · ${info.count} files <span class="text-gray-400">(${pct}%)</span></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5"><div class="h-1.5 rounded-full bg-pink-400 transition-all" style="width:${pct}%"></div></div>
                </div>`;
            });
            ihtml += `<div class="pt-2 mt-2 border-t flex items-center justify-between text-xs font-semibold text-gray-800">
                <span><i class="fas fa-images text-pink-500 mr-1"></i> Total Images</span>
                <span><span class="font-mono">${d.images_size || 0} MB</span> · ${d.total_images || 0} files</span>
            </div>`;
            imgEl.innerHTML = ihtml;
        } else {
            imgEl.innerHTML = '<div class="text-xs text-gray-400 text-center py-4">No images found</div>';
        }
        
        setCheck('check-gzip', d.gzip_enabled, 'Gzip ' + (d.gzip_enabled ? '✓' : '✗'));
        setCheck('check-opcache', d.opcache_enabled, 'OPcache ' + (d.opcache_enabled ? '✓' : '✗'));
        const memLimit = parseInt(d.memory_limit) || 0;
        setCheck('check-memory', memLimit >= 128, 'Memory: ' + d.memory_limit);
        setCheck('check-webp', d.webp_support, 'WebP ' + (d.webp_support ? '✓' : '✗'));
        setCheck('check-https', d.https_enabled, 'HTTPS ' + (d.https_enabled ? '✓' : '✗'));
        const uploadMb = parseInt(d.upload_max) || 0;
        setCheck('check-upload', uploadMb >= 10, 'Upload: ' + d.upload_max);
    });
}

function setCheck(id, ok, label) {
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = `<i class="fas fa-${ok ? 'check-circle text-green-500' : 'exclamation-circle text-yellow-500'} text-xs"></i><span class="text-xs ${ok ? 'text-green-700' : 'text-yellow-700'} font-medium">${label}</span>`;
    el.className = 'flex items-center gap-2 p-2 rounded-lg ' + (ok ? 'bg-green-50' : 'bg-yellow-50');
}

function addLog(action, message, success) {
    const log = document.getElementById('action-log');
    const entries = document.getElementById('log-entries');
    log.classList.remove('hidden');
    const time = new Date().toLocaleTimeString();
    const c = success ? 'green' : 'red';
    entries.innerHTML = `<div class="flex items-center gap-2 text-xs p-2 bg-${c}-50 rounded-lg">
        <i class="fas fa-${success ? 'check-circle' : 'times-circle'} text-${c}-500"></i>
        <span class="text-gray-400">${time}</span>
        <span class="font-semibold text-gray-700">${action.replace(/_/g, ' ')}</span>
        <span class="text-gray-500">— ${message}</span>
    </div>` + entries.innerHTML;
}

// CDN Purge Functions
function toggleAllPages(cb) {
    document.querySelectorAll('.page-cb').forEach(c => c.checked = cb.checked);
    updatePurgeBtn();
}

function updatePurgeBtn() {
    const checked = document.querySelectorAll('.page-cb:checked').length;
    document.getElementById('btn-purge-selected').disabled = checked === 0;
    document.getElementById('btn-purge-selected').querySelector('span')?.remove();
    if (checked > 0) {
        document.getElementById('btn-purge-selected').innerHTML = `<i class="fas fa-sync mr-1"></i> Purge Selected (${checked})`;
    } else {
        document.getElementById('btn-purge-selected').innerHTML = '<i class="fas fa-sync mr-1"></i> Purge Selected';
    }
}

function purgeSelectedPages() {
    const urls = [...document.querySelectorAll('.page-cb:checked')].map(c => c.value);
    if (!urls.length) return;
    doPurge(urls, document.getElementById('btn-purge-selected'));
}

function purgeSinglePage(url, btn) {
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    const fd = new FormData();
    fd.append('action', 'purge_cdn');
    fd.append('urls', JSON.stringify([url]));
    
    fetch(API, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = '<i class="fas fa-check text-green-500"></i>';
        addLog('cdn_purge', data.data?.message || 'Purged: ' + url, data.success);
        setTimeout(() => btn.innerHTML = origHtml, 2000);
    })
    .catch(() => { btn.innerHTML = origHtml; });
}

function purgeAllCdn() {
    if (!confirm('Purge ALL CDN cache? This will force all pages to reload from origin.')) return;
    const btn = event.target.closest('button');
    doPurge(['*'], btn);
}

function purgeCustomUrl() {
    let url = document.getElementById('custom-purge-url').value.trim();
    if (!url) return;
    if (!url.startsWith('http')) {
        const base = document.getElementById('cdn_url').value.trim() || '<?= SITE_URL ?>';
        url = base.replace(/\/$/, '') + (url.startsWith('/') ? '' : '/') + url;
    }
    const btn = event.target.closest('button');
    doPurge([url], btn);
    document.getElementById('custom-purge-url').value = '';
}

function doPurge(urls, btn) {
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Purging...';
    
    const fd = new FormData();
    fd.append('action', 'purge_cdn');
    fd.append('urls', JSON.stringify(urls));
    
    fetch(API, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check mr-1"></i> Done!';
        addLog('cdn_purge', data.data?.message || 'CDN purged', data.success !== false);
        setTimeout(() => btn.innerHTML = origHtml, 2000);
        // Uncheck all
        document.querySelectorAll('.page-cb').forEach(c => c.checked = false);
        document.getElementById('select-all-pages').checked = false;
        updatePurgeBtn();
    })
    .catch(err => { btn.disabled = false; btn.innerHTML = origHtml; addLog('cdn_purge', 'Error: ' + err.message, false); });
}

function saveCdnConfig() {
    const fd = new FormData();
    fd.append('action', 'save_cdn_config');
    fd.append('cdn_url', document.getElementById('cdn_url').value.trim());
    fd.append('cf_zone_id', document.getElementById('cf_zone_id').value.trim());
    fd.append('cf_api_token', document.getElementById('cf_api_token').value.trim());
    
    fetch(API, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(() => {
        const msg = document.getElementById('cdn-config-msg');
        msg.classList.remove('hidden');
        setTimeout(() => msg.classList.add('hidden'), 3000);
        addLog('cdn_config', 'CDN configuration saved', true);
    });
}

function testCdnConnection() {
    const fd = new FormData();
    fd.append('action', 'test_cdn');
    fd.append('cf_zone_id', document.getElementById('cf_zone_id').value.trim());
    fd.append('cf_api_token', document.getElementById('cf_api_token').value.trim());
    
    const msg = document.getElementById('cdn-test-msg');
    msg.classList.remove('hidden');
    msg.className = 'text-xs text-gray-500';
    msg.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Testing...';
    
    fetch(API, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            msg.className = 'text-xs text-green-600';
            msg.innerHTML = '<i class="fas fa-check-circle mr-1"></i> ' + (data.data?.message || 'Connected!');
        } else {
            msg.className = 'text-xs text-red-600';
            msg.innerHTML = '<i class="fas fa-times-circle mr-1"></i> ' + (data.message || 'Failed');
        }
    });
}

// Speed Settings
function saveSpeedSettings() {
    const fd = new FormData();
    fd.append('action', 'save_speed_settings');
    document.querySelectorAll('.speed-toggle').forEach(t => {
        fd.append(t.dataset.key, t.checked ? '1' : '0');
    });
    fd.append('preconnect_domains', document.getElementById('preconnect_domains').value.trim());
    
    fetch(API, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(() => {
        const msg = document.getElementById('speed-save-msg');
        msg.classList.remove('hidden');
        setTimeout(() => msg.classList.add('hidden'), 3000);
        addLog('speed_settings', 'Speed optimization settings saved', true);
    });
}

// Preloader
function savePreloader() {
    const fd = new FormData();
    fd.append('action', 'save_preloader');
    fd.append('preloader_enabled', document.getElementById('preloader_enabled').checked ? '1' : '0');
    fd.append('preloader_threshold', document.getElementById('preloader_threshold').value);
    fd.append('preloader_message', document.getElementById('preloader_message').value);
    
    fetch(API, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(() => {
        const msg = document.getElementById('preloader-msg');
        msg.classList.remove('hidden');
        setTimeout(() => msg.classList.add('hidden'), 3000);
    });
}

function previewPreloader() {
    const msg = document.getElementById('preloader_message').value;
    const threshold = document.getElementById('preloader_threshold').value;
    const w = window.open('', '_blank', 'width=500,height=600');
    w.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Queue Preview</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:linear-gradient(135deg,#667eea,#764ba2);min-height:100vh;display:flex;align-items:center;justify-content:center;color:#fff}
    .c{text-align:center;padding:2rem;max-width:450px}.spinner{width:60px;height:60px;margin:0 auto 1.5rem;border:4px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:s .8s linear infinite}
    @keyframes s{to{transform:rotate(360deg)}}h1{font-size:1.5rem;margin-bottom:.75rem}p{opacity:.9;line-height:1.6;margin-bottom:1.5rem}
    .bar{width:100%;height:4px;background:rgba(255,255,255,.2);border-radius:2px;margin-top:1.5rem;overflow:hidden}.fill{height:100%;background:#fff;border-radius:2px;animation:p 3s ease-in-out infinite;width:30%}
    @keyframes p{0%{transform:translateX(-100%)}100%{transform:translateX(400%)}}</style></head><body><div class="c">
    <div class="spinner"></div><h1>অপেক্ষা করুন</h1><p>${msg}</p>
    <div class="bar"><div class="fill"></div></div><p style="margin-top:1rem;font-size:.8rem;opacity:.7">Threshold: ${threshold} connections</p>
    </div></body></html>`);
}

function copyHtaccess() {
    navigator.clipboard.writeText(document.getElementById('htaccess-code').textContent).then(() => {
        const btn = event.target.closest('button');
        btn.innerHTML = '<i class="fas fa-check mr-1"></i>Copied!';
        setTimeout(() => btn.innerHTML = '<i class="fas fa-copy mr-1"></i>Copy', 2000);
    });
}

// ══════════════════════════════════════════
// CURRENT LOAD ANALYSIS
// ══════════════════════════════════════════
function runLoadAnalysis() {
    const skeleton = document.getElementById('load-skeleton');
    const results = document.getElementById('load-results');
    const btn = document.getElementById('btn-load-refresh');
    skeleton.classList.remove('hidden');
    results.classList.add('hidden');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Analyzing...';
    
    fetch(API + '?action=load_analysis')
    .then(r => r.json())
    .then(res => {
        skeleton.classList.add('hidden');
        results.classList.remove('hidden');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt mr-1"></i> Re-analyze';
        document.getElementById('load-timestamp').textContent = '⏱ ' + new Date().toLocaleTimeString();
        
        if (!res.success) { results.innerHTML = '<p class="text-red-500 text-sm">Error: ' + (res.message || 'Unknown') + '</p>'; return; }
        const d = res.data;
        let html = '';
        
        // ── Row 1: System load gauges ──
        html += '<div><h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2"><i class="fas fa-server text-gray-400 mr-1"></i> System Load Average</h4>';
        html += '<div class="grid grid-cols-3 gap-3">';
        const loadPeriods = [['1 min', d.load_1m], ['5 min', d.load_5m], ['15 min', d.load_15m]];
        const cores = d.cpu_cores || 1;
        loadPeriods.forEach(([label, val]) => {
            const pct = Math.min(100, Math.round((val / cores) * 100));
            const color = pct < 50 ? 'green' : pct < 80 ? 'yellow' : 'red';
            const ring = `conic-gradient(var(--tw-gradient-stops))`;
            html += `<div class="bg-gray-50 rounded-xl p-4 text-center">
                <div class="relative w-16 h-16 mx-auto mb-2">
                    <svg viewBox="0 0 36 36" class="w-16 h-16 transform -rotate-90">
                        <circle cx="18" cy="18" r="15.915" fill="none" stroke="#e5e7eb" stroke-width="3"/>
                        <circle cx="18" cy="18" r="15.915" fill="none" stroke="${color === 'green' ? '#22c55e' : color === 'yellow' ? '#eab308' : '#ef4444'}" stroke-width="3" stroke-dasharray="${pct} ${100 - pct}" stroke-linecap="round"/>
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span class="text-sm font-bold text-gray-800">${val}</span>
                    </div>
                </div>
                <p class="text-xs font-medium text-gray-600">${label}</p>
                <p class="text-[10px] text-gray-400">${pct}% of ${cores} cores</p>
            </div>`;
        });
        html += '</div></div>';
        
        // ── Row 2: RAM + PHP Memory + MySQL ──
        html += '<div class="grid lg:grid-cols-3 gap-4">';
        
        // RAM
        html += '<div class="bg-gray-50 rounded-xl p-4"><h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3"><i class="fas fa-memory text-blue-400 mr-1"></i> RAM Usage</h4>';
        if (d.ram_total_mb) {
            const ramPct = Math.round((d.ram_used_mb / d.ram_total_mb) * 100);
            const ramColor = ramPct < 60 ? '#22c55e' : ramPct < 85 ? '#eab308' : '#ef4444';
            html += `<div class="flex items-center gap-3 mb-3"><div class="relative w-14 h-14">
                <svg viewBox="0 0 36 36" class="w-14 h-14 transform -rotate-90"><circle cx="18" cy="18" r="15.915" fill="none" stroke="#e5e7eb" stroke-width="3.5"/><circle cx="18" cy="18" r="15.915" fill="none" stroke="${ramColor}" stroke-width="3.5" stroke-dasharray="${ramPct} ${100-ramPct}" stroke-linecap="round"/></svg>
                <div class="absolute inset-0 flex items-center justify-center"><span class="text-xs font-bold">${ramPct}%</span></div>
            </div><div class="text-xs space-y-0.5">
                <p class="text-gray-700"><strong>Used:</strong> ${d.ram_used_mb} MB</p>
                <p class="text-gray-500"><strong>Free:</strong> ${d.ram_free_mb} MB</p>
                <p class="text-gray-500"><strong>Cached:</strong> ${d.ram_cached_mb || 0} MB</p>
                <p class="text-gray-400"><strong>Total:</strong> ${d.ram_total_mb} MB</p>
            </div></div>`;
            html += `<div class="w-full bg-gray-200 rounded-full h-2"><div class="h-2 rounded-full transition-all" style="width:${ramPct}%;background:${ramColor}"></div></div>`;
        } else {
            html += '<p class="text-xs text-gray-400 italic">Not available (Windows or restricted)</p>';
        }
        html += '</div>';
        
        // PHP Memory
        html += '<div class="bg-gray-50 rounded-xl p-4"><h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3"><i class="fab fa-php text-indigo-400 mr-1"></i> PHP Process</h4>';
        html += `<div class="space-y-2 text-xs">
            <div class="flex justify-between"><span class="text-gray-500">Current Memory</span><span class="font-mono font-semibold text-gray-800">${d.php_memory_mb || 0} MB</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Peak Memory</span><span class="font-mono font-semibold text-gray-800">${d.php_peak_mb || 0} MB</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Memory Limit</span><span class="font-mono text-gray-600">${d.php_memory_limit || '—'}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Max Execution</span><span class="font-mono text-gray-600">${d.php_max_exec || '—'}s</span></div>
            <div class="flex justify-between"><span class="text-gray-500">PHP Workers</span><span class="font-mono font-semibold text-gray-800">${d.php_processes || '—'}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Server Software</span><span class="font-mono text-gray-600 text-[10px] truncate max-w-[120px]">${d.server_software || '—'}</span></div>
        </div>`;
        html += '</div>';
        
        // MySQL
        html += '<div class="bg-gray-50 rounded-xl p-4"><h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3"><i class="fas fa-database text-green-400 mr-1"></i> MySQL</h4>';
        html += `<div class="space-y-2 text-xs">
            <div class="flex justify-between"><span class="text-gray-500">Active Connections</span><span class="font-mono font-semibold text-gray-800">${d.mysql_threads || '—'}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Max Connections</span><span class="font-mono text-gray-600">${d.mysql_max_conn || '—'}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Total Queries</span><span class="font-mono text-gray-600">${formatNum(d.mysql_queries)}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Slow Queries</span><span class="font-mono ${(d.mysql_slow_queries||0)>0?'text-red-600 font-semibold':'text-gray-600'}">${d.mysql_slow_queries || 0}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Uptime</span><span class="font-mono text-gray-600">${d.mysql_uptime || '—'}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Aborted Connects</span><span class="font-mono ${(d.mysql_aborted||0)>10?'text-yellow-600':'text-gray-600'}">${d.mysql_aborted || 0}</span></div>
        </div>`;
        html += '</div>';
        html += '</div>';
        
        // ── Row 3: Disk I/O + Network + Connections ──
        html += '<div class="grid lg:grid-cols-3 gap-4">';
        
        // Disk I/O
        html += '<div class="bg-gray-50 rounded-xl p-4"><h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3"><i class="fas fa-hdd text-purple-400 mr-1"></i> Disk</h4>';
        html += `<div class="space-y-2 text-xs">
            <div class="flex justify-between"><span class="text-gray-500">Total Space</span><span class="font-mono font-semibold text-gray-800">${d.disk_total_gb || '—'} GB</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Used Space</span><span class="font-mono text-gray-800">${d.disk_used_gb || '—'} GB</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Free Space</span><span class="font-mono text-green-600">${d.disk_free_gb || '—'} GB</span></div>`;
        if (d.disk_used_pct !== undefined) {
            const dColor = d.disk_used_pct < 70 ? '#22c55e' : d.disk_used_pct < 90 ? '#eab308' : '#ef4444';
            html += `<div class="pt-1"><div class="w-full bg-gray-200 rounded-full h-2"><div class="h-2 rounded-full" style="width:${d.disk_used_pct}%;background:${dColor}"></div></div><p class="text-[10px] text-gray-400 mt-0.5 text-right">${d.disk_used_pct}% used</p></div>`;
        }
        if (d.inode_used_pct !== undefined) {
            html += `<div class="flex justify-between"><span class="text-gray-500">Inodes Used</span><span class="font-mono text-gray-600">${d.inode_used_pct}%</span></div>`;
        }
        html += '</div></div>';
        
        // Network
        html += '<div class="bg-gray-50 rounded-xl p-4"><h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3"><i class="fas fa-network-wired text-cyan-400 mr-1"></i> Network</h4>';
        html += `<div class="space-y-2 text-xs">
            <div class="flex justify-between"><span class="text-gray-500">Total Received</span><span class="font-mono font-semibold text-gray-800">${d.net_rx || '—'}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Total Sent</span><span class="font-mono font-semibold text-gray-800">${d.net_tx || '—'}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">TCP Connections</span><span class="font-mono text-gray-800">${d.tcp_connections || '—'}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">ESTABLISHED</span><span class="font-mono text-green-600">${d.tcp_established || '—'}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">TIME_WAIT</span><span class="font-mono text-yellow-600">${d.tcp_time_wait || '—'}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">CLOSE_WAIT</span><span class="font-mono ${(d.tcp_close_wait||0)>5?'text-red-600':'text-gray-600'}">${d.tcp_close_wait || '—'}</span></div>
        </div>`;
        html += '</div>';
        
        // Page Response
        html += '<div class="bg-gray-50 rounded-xl p-4"><h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3"><i class="fas fa-stopwatch text-orange-400 mr-1"></i> Response Time</h4>';
        if (d.page_responses && d.page_responses.length) {
            html += '<div class="space-y-1.5">';
            d.page_responses.forEach(p => {
                const ms = p.time_ms;
                const color = ms < 300 ? 'text-green-600' : ms < 800 ? 'text-yellow-600' : 'text-red-600';
                const bar = Math.min(100, Math.round(ms / 20)); // 2000ms = 100%
                html += `<div>
                    <div class="flex items-center justify-between text-xs mb-0.5">
                        <span class="text-gray-600 truncate max-w-[110px]">${p.label}</span>
                        <span class="font-mono font-semibold ${color}">${ms < 0 ? 'ERR' : ms + 'ms'}</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-1"><div class="h-1 rounded-full ${ms<300?'bg-green-400':ms<800?'bg-yellow-400':'bg-red-400'}" style="width:${bar}%"></div></div>
                </div>`;
            });
            html += '</div>';
        } else {
            html += '<p class="text-xs text-gray-400 italic">Could not measure</p>';
        }
        html += '</div>';
        html += '</div>';
        
        // ── Overall health score ──
        const score = d.health_score || 0;
        const sColor = score >= 80 ? 'green' : score >= 50 ? 'yellow' : 'red';
        const sEmoji = score >= 80 ? '🟢' : score >= 50 ? '🟡' : '🔴';
        html += `<div class="bg-gradient-to-r ${score>=80?'from-green-50 to-emerald-50 border-green-200':score>=50?'from-yellow-50 to-amber-50 border-yellow-200':'from-red-50 to-rose-50 border-red-200'} border rounded-xl p-4 flex items-center justify-between">
            <div>
                <h4 class="text-sm font-bold text-gray-800">${sEmoji} Server Health Score</h4>
                <p class="text-xs text-gray-500 mt-0.5">${d.health_notes || 'Based on load avg, RAM, MySQL threads, disk usage, and response times'}</p>
            </div>
            <div class="text-right">
                <span class="text-3xl font-black ${score>=80?'text-green-600':score>=50?'text-yellow-600':'text-red-600'}">${score}</span>
                <span class="text-sm text-gray-400 font-medium">/100</span>
            </div>
        </div>`;
        
        // ── Warnings ──
        if (d.warnings && d.warnings.length) {
            html += '<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-3">';
            html += '<h4 class="text-xs font-bold text-yellow-800 mb-2"><i class="fas fa-exclamation-triangle mr-1"></i> Warnings</h4>';
            html += '<ul class="space-y-1">';
            d.warnings.forEach(w => { html += `<li class="text-xs text-yellow-700 flex items-start gap-1.5"><i class="fas fa-caret-right text-yellow-500 mt-0.5"></i>${w}</li>`; });
            html += '</ul></div>';
        }
        
        results.innerHTML = html;
    })
    .catch(err => {
        document.getElementById('load-skeleton').classList.add('hidden');
        results.classList.remove('hidden');
        results.innerHTML = `<p class="text-red-500 text-sm"><i class="fas fa-times-circle mr-1"></i> Load analysis failed: ${err.message}</p>`;
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt mr-1"></i> Re-analyze';
    });
}

function formatNum(n) {
    if (!n && n !== 0) return '—';
    if (n >= 1000000000) return (n/1000000000).toFixed(1) + 'B';
    if (n >= 1000000) return (n/1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n/1000).toFixed(1) + 'K';
    return String(n);
}

// Init
loadServerStatus();
runLoadAnalysis();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
