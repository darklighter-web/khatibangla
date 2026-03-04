<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Visitor Analytics';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();

// ── Date filter ──
$days = intval($_GET['days'] ?? 7);
if (!in_array($days, [1, 7, 14, 30, 90])) $days = 7;
$since = "DATE_SUB(NOW(), INTERVAL {$days} DAY)";

// ── Device breakdown ──
$devices = [];
try {
    $rows = $db->fetchAll("SELECT device_type, COUNT(*) as cnt FROM visitor_logs WHERE created_at >= {$since} GROUP BY device_type ORDER BY cnt DESC");
    $total = array_sum(array_column($rows, 'cnt'));
    foreach ($rows as $r) {
        $devices[] = [
            'type'    => ucfirst($r['device_type'] ?? 'unknown'),
            'count'   => intval($r['cnt']),
            'percent' => $total > 0 ? round($r['cnt'] / $total * 100) : 0,
        ];
    }
} catch (\Throwable $e) {}

// ── Browser breakdown ──
$browsers = [];
try {
    $rows = $db->fetchAll("SELECT browser, COUNT(*) as cnt FROM visitor_logs WHERE created_at >= {$since} GROUP BY browser ORDER BY cnt DESC LIMIT 10");
    foreach ($rows as $r) $browsers[] = ['name' => $r['browser'] ?? 'Other', 'count' => intval($r['cnt'])];
} catch (\Throwable $e) {}

// ── OS breakdown ──
$oses = [];
try {
    $rows = $db->fetchAll("SELECT os, COUNT(*) as cnt FROM visitor_logs WHERE created_at >= {$since} GROUP BY os ORDER BY cnt DESC LIMIT 8");
    foreach ($rows as $r) $oses[] = ['name' => $r['os'] ?? 'Other', 'count' => intval($r['cnt'])];
} catch (\Throwable $e) {}

// ── Top Referrers ──
$referrers = [];
try {
    $rows = $db->fetchAll("SELECT COALESCE(NULLIF(referrer,''), '(direct)') as ref, COUNT(*) as cnt FROM visitor_logs WHERE created_at >= {$since} GROUP BY referrer ORDER BY cnt DESC LIMIT 10");
    foreach ($rows as $r) {
        $host = $r['ref'] === '(direct)' ? '(direct)' : (parse_url($r['ref'], PHP_URL_HOST) ?: $r['ref']);
        $referrers[] = ['host' => $host, 'count' => intval($r['cnt'])];
    }
} catch (\Throwable $e) {}

// ── Top Landing Pages ──
$landingPages = [];
try {
    $rows = $db->fetchAll("
        SELECT 
            landing_page,
            COUNT(*) as visitors,
            COALESCE(SUM(order_placed), 0) as orders
        FROM visitor_logs
        WHERE created_at >= {$since}
        GROUP BY landing_page
        ORDER BY visitors DESC
        LIMIT 15
    ");
    foreach ($rows as $r) {
        $v = intval($r['visitors']);
        $o = intval($r['orders']);
        $landingPages[] = [
            'page'      => $r['landing_page'] ?? '/',
            'visitors'  => $v,
            'orders'    => $o,
            'conv_rate' => $v > 0 ? round($o / $v * 100, 1) : 0,
        ];
    }
} catch (\Throwable $e) {}

// ── Summary stats ──
$totalVisitors  = 0; $totalBots = 0; $totalOrders = 0; $uniqueIps = 0;
try {
    $s = $db->fetch("SELECT COUNT(*) as total, SUM(device_type='bot') as bots, SUM(COALESCE(order_placed,0)) as orders, COUNT(DISTINCT device_ip) as ips FROM visitor_logs WHERE created_at >= {$since}");
    $totalVisitors = intval($s['total'] ?? 0);
    $totalBots     = intval($s['bots'] ?? 0);
    $totalOrders   = intval($s['orders'] ?? 0);
    $uniqueIps     = intval($s['ips'] ?? 0);
} catch (\Throwable $e) {}

// ── Recent visitor logs ──
$page     = max(1, intval($_GET['p'] ?? 1));
$perPage  = 30;
$offset   = ($page - 1) * $perPage;
$logTotal = 0; $logs = [];
$searchIp = trim($_GET['search'] ?? '');
try {
    $where  = "1=1";
    $params = [];
    if ($searchIp) { $where .= " AND (device_ip LIKE ? OR customer_phone LIKE ? OR browser LIKE ?)"; $params = ["%{$searchIp}%", "%{$searchIp}%", "%{$searchIp}%"]; }
    $where .= " AND created_at >= {$since}";
    $logTotal = intval($db->fetch("SELECT COUNT(*) as c FROM visitor_logs WHERE {$where}", $params)['c'] ?? 0);
    $logs     = $db->fetchAll("SELECT * FROM visitor_logs WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}", $params) ?: [];
} catch (\Throwable $e) {}

$botPercent = $totalVisitors > 0 ? round($totalBots / $totalVisitors * 100) : 0;

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.stat-card{background:#fff;border-radius:.875rem;border:1px solid #e5e7eb;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.section-card{background:#fff;border-radius:.875rem;border:1px solid #e5e7eb;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.section-head{padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:.5rem;font-weight:600;font-size:.875rem;color:#1f2937}
.bar-row{display:flex;align-items:center;gap:.75rem;padding:.4rem 0;font-size:.8125rem}
.bar-bg{flex:1;background:#f3f4f6;border-radius:9999px;height:8px;overflow:hidden}
.bar-fill{height:100%;border-radius:9999px;transition:width .5s ease}
.tag-bot{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;font-size:.7rem;padding:1px 6px;border-radius:9999px;font-weight:600}
.tag-desktop{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:.7rem;padding:1px 6px;border-radius:9999px;font-weight:600}
.tag-mobile{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-size:.7rem;padding:1px 6px;border-radius:9999px;font-weight:600}
.lp-row:hover{background:#f9fafb}
.conv-good{color:#16a34a;font-weight:700}
.conv-zero{color:#9ca3af}
.vlog-row:hover{background:#f9fafb;cursor:pointer}
.device-icon{width:28px;height:28px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:13px}
.alert-banner{background:linear-gradient(135deg,#fef2f2,#fff5f5);border:1px solid #fecaca;border-radius:.875rem;padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem}
</style>

<div class="max-w-7xl mx-auto space-y-5">

    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white shadow">
                <i class="fas fa-chart-line text-lg"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-gray-800">Visitor Analytics</h2>
                <p class="text-xs text-gray-500">Real-time traffic & bot detection</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <!-- Day filter -->
            <div class="flex rounded-lg border border-gray-200 overflow-hidden text-xs font-medium">
                <?php foreach([1=>'24h',7=>'7d',14=>'14d',30=>'30d',90=>'90d'] as $d=>$label): ?>
                <a href="?days=<?= $d ?>" class="px-3 py-1.5 <?= $days==$d ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50' ?> transition"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            <a href="<?= SITE_URL ?>/admin/pages/security.php" class="px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-medium hover:bg-red-700 transition flex items-center gap-1">
                <i class="fas fa-shield-alt"></i> Security Center
            </a>
        </div>
    </div>

    <?php if ($botPercent >= 30): ?>
    <!-- Bot Alert Banner -->
    <div class="alert-banner">
        <div class="w-9 h-9 bg-red-100 rounded-lg flex items-center justify-center text-red-600 flex-shrink-0">
            <i class="fas fa-robot"></i>
        </div>
        <div class="flex-1">
            <p class="font-semibold text-red-700 text-sm">High Bot Traffic Detected — <?= $botPercent ?>% of visitors are bots</p>
            <p class="text-xs text-red-500 mt-0.5">These bots are probing for vulnerabilities. Your security layer is blocking known attack tools. <a href="<?= SITE_URL ?>/admin/pages/security.php" class="underline font-medium">Review IP rules →</a></p>
        </div>
        <span class="text-2xl font-black text-red-200"><?= $botPercent ?>%</span>
    </div>
    <?php endif; ?>

    <!-- Summary Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="stat-card text-center">
            <p class="text-2xl font-black text-gray-800"><?= number_format($totalVisitors) ?></p>
            <p class="text-xs text-gray-500 mt-1">Total Sessions</p>
        </div>
        <div class="stat-card text-center">
            <p class="text-2xl font-black text-red-600"><?= number_format($totalBots) ?></p>
            <p class="text-xs text-gray-500 mt-1">Bots (<?= $botPercent ?>%)</p>
        </div>
        <div class="stat-card text-center">
            <p class="text-2xl font-black text-blue-600"><?= number_format($uniqueIps) ?></p>
            <p class="text-xs text-gray-500 mt-1">Unique IPs</p>
        </div>
        <div class="stat-card text-center">
            <p class="text-2xl font-black text-green-600"><?= number_format($totalOrders) ?></p>
            <p class="text-xs text-gray-500 mt-1">Orders Placed</p>
        </div>
    </div>

    <!-- Row: Devices + Browsers + OS + Referrers -->
    <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-4">

        <!-- Devices -->
        <div class="section-card">
            <div class="section-head"><i class="fas fa-desktop text-indigo-500"></i> Devices</div>
            <div class="p-4 space-y-1">
                <?php
                $deviceColors = ['Bot'=>'#ef4444','Desktop'=>'#3b82f6','Mobile'=>'#22c55e','Tablet'=>'#f59e0b','Unknown'=>'#9ca3af'];
                $deviceIcons  = ['Bot'=>'fa-robot','Desktop'=>'fa-desktop','Mobile'=>'fa-mobile-alt','Tablet'=>'fa-tablet-alt','Unknown'=>'fa-question'];
                foreach ($devices as $d):
                    $color = $deviceColors[$d['type']] ?? '#9ca3af';
                    $icon  = $deviceIcons[$d['type']] ?? 'fa-globe';
                ?>
                <div class="bar-row">
                    <div class="device-icon" style="background:<?= $color ?>18;color:<?= $color ?>">
                        <i class="fas <?= $icon ?>" style="font-size:11px"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between text-xs mb-1">
                            <span class="font-medium text-gray-700"><?= htmlspecialchars($d['type']) ?></span>
                            <span class="text-gray-500"><?= number_format($d['count']) ?> (<?= $d['percent'] ?>%)</span>
                        </div>
                        <div class="bar-bg">
                            <div class="bar-fill" style="width:<?= $d['percent'] ?>%;background:<?= $color ?>"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($devices)): ?><p class="text-xs text-gray-400 text-center py-2">No data</p><?php endif; ?>
            </div>
        </div>

        <!-- Browsers -->
        <div class="section-card">
            <div class="section-head"><i class="fas fa-globe text-blue-500"></i> Browsers</div>
            <div class="p-4">
                <?php
                $browserMax = $browsers[0]['count'] ?? 1;
                $bColors = ['Chrome'=>'#4285f4','Safari'=>'#1da462','Firefox'=>'#ff7139','Edge'=>'#0078d7','Facebook'=>'#1877f2','Other'=>'#9ca3af'];
                foreach ($browsers as $b):
                    $pct   = $browserMax > 0 ? round($b['count'] / $browserMax * 100) : 0;
                    $color = $bColors[$b['name']] ?? '#9ca3af';
                ?>
                <div class="bar-row">
                    <span class="w-16 text-gray-700 font-medium truncate"><?= htmlspecialchars($b['name']) ?></span>
                    <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
                    <span class="w-10 text-right text-gray-500"><?= number_format($b['count']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($browsers)): ?><p class="text-xs text-gray-400 text-center py-2">No data</p><?php endif; ?>
            </div>
        </div>

        <!-- OS -->
        <div class="section-card">
            <div class="section-head"><i class="fas fa-microchip text-purple-500"></i> Operating Systems</div>
            <div class="p-4">
                <?php
                $osMax    = $oses[0]['count'] ?? 1;
                $osColors = ['Windows 10+'=>'#0078d4','Windows'=>'#0078d4','macOS'=>'#555','Linux'=>'#f7c519','Android'=>'#3ddc84','iOS'=>'#555','Other'=>'#9ca3af'];
                foreach ($oses as $o):
                    $pct   = $osMax > 0 ? round($o['count'] / $osMax * 100) : 0;
                    $color = $osColors[$o['name']] ?? '#9ca3af';
                ?>
                <div class="bar-row">
                    <span class="w-20 text-gray-700 font-medium text-xs truncate"><?= htmlspecialchars($o['name']) ?></span>
                    <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
                    <span class="w-10 text-right text-gray-500 text-xs"><?= number_format($o['count']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($oses)): ?><p class="text-xs text-gray-400 text-center py-2">No data</p><?php endif; ?>
            </div>
        </div>

        <!-- Top Referrers -->
        <div class="section-card">
            <div class="section-head"><i class="fas fa-link text-orange-500"></i> Top Referrers</div>
            <div class="p-4">
                <?php
                $refMax = $referrers[0]['count'] ?? 1;
                foreach ($referrers as $r):
                    $pct = $refMax > 0 ? round($r['count'] / $refMax * 100) : 0;
                ?>
                <div class="bar-row">
                    <span class="flex-1 text-gray-700 font-medium text-xs truncate" title="<?= htmlspecialchars($r['host']) ?>"><?= htmlspecialchars(mb_substr($r['host'], 0, 20)) ?></span>
                    <span class="text-gray-500 text-xs w-8 text-right"><?= number_format($r['count']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($referrers)): ?><p class="text-xs text-gray-400 text-center py-2">No data</p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top Landing Pages -->
    <div class="section-card">
        <div class="section-head">
            <i class="fas fa-flag text-green-500"></i> Top Landing Pages
            <span class="ml-auto text-xs text-gray-400 font-normal">Sorted by visitors</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left py-2.5 px-4 text-xs font-semibold text-gray-500 uppercase tracking-wide">Page</th>
                        <th class="text-right py-2.5 px-4 text-xs font-semibold text-gray-500 uppercase tracking-wide">Visitors</th>
                        <th class="text-right py-2.5 px-4 text-xs font-semibold text-gray-500 uppercase tracking-wide">Orders</th>
                        <th class="text-right py-2.5 px-4 text-xs font-semibold text-gray-500 uppercase tracking-wide">Conv. Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                <?php foreach ($landingPages as $lp):
                    $isSuspect = preg_match('/(wp-login|xmlrpc|\.env|info\.php|wp-admin|feed=rss|stock_status|post_type=product|\.php\?)/i', $lp['page']);
                ?>
                <tr class="lp-row <?= $isSuspect ? 'bg-red-50' : '' ?>">
                    <td class="py-2.5 px-4">
                        <div class="flex items-center gap-2">
                            <?php if ($isSuspect): ?>
                            <span class="inline-flex items-center gap-1 text-red-600 text-xs font-medium"><i class="fas fa-exclamation-triangle text-red-400"></i></span>
                            <?php endif; ?>
                            <a href="https://khatibangla.com<?= htmlspecialchars($lp['page']) ?>" target="_blank"
                               class="text-blue-600 hover:underline font-mono text-xs truncate max-w-xs block"
                               title="<?= htmlspecialchars($lp['page']) ?>">
                                <?= htmlspecialchars(mb_substr($lp['page'], 0, 60)) ?>
                            </a>
                        </div>
                    </td>
                    <td class="py-2.5 px-4 text-right text-gray-700 font-medium"><?= number_format($lp['visitors']) ?></td>
                    <td class="py-2.5 px-4 text-right">
                        <span class="<?= $lp['orders'] > 0 ? 'text-green-600 font-bold' : 'text-gray-300' ?>"><?= $lp['orders'] ?></span>
                    </td>
                    <td class="py-2.5 px-4 text-right">
                        <span class="<?= $lp['conv_rate'] > 0 ? 'conv-good' : 'conv-zero' ?>">
                            <?= $lp['conv_rate'] > 0 ? $lp['conv_rate'].'%' : '0%' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($landingPages)): ?>
                <tr><td colspan="4" class="py-8 text-center text-gray-400 text-sm">No landing page data found</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Visitor Logs -->
    <div class="section-card">
        <div class="section-head">
            <i class="fas fa-list text-gray-500"></i> Visitor Logs
            <span class="ml-2 text-xs text-gray-400 font-normal"><?= number_format($logTotal) ?> entries</span>
            <form method="get" class="ml-auto flex gap-2">
                <input type="hidden" name="days" value="<?= $days ?>">
                <input name="search" value="<?= htmlspecialchars($searchIp) ?>" placeholder="Search IP, phone, browser..."
                       class="border border-gray-200 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-300 w-52">
                <button type="submit" class="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-700 transition">Search</button>
                <?php if ($searchIp): ?><a href="?days=<?= $days ?>" class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-xs font-medium hover:bg-gray-200 transition">Clear</a><?php endif; ?>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left py-2 px-3 font-semibold text-gray-500 uppercase tracking-wide">IP</th>
                        <th class="text-left py-2 px-3 font-semibold text-gray-500 uppercase tracking-wide">Device</th>
                        <th class="text-left py-2 px-3 font-semibold text-gray-500 uppercase tracking-wide">Browser / OS</th>
                        <th class="text-left py-2 px-3 font-semibold text-gray-500 uppercase tracking-wide">Landing Page</th>
                        <th class="text-left py-2 px-3 font-semibold text-gray-500 uppercase tracking-wide">Phone</th>
                        <th class="text-center py-2 px-3 font-semibold text-gray-500 uppercase tracking-wide">Pages</th>
                        <th class="text-center py-2 px-3 font-semibold text-gray-500 uppercase tracking-wide">Order</th>
                        <th class="text-left py-2 px-3 font-semibold text-gray-500 uppercase tracking-wide">Time</th>
                        <th class="text-left py-2 px-3 font-semibold text-gray-500 uppercase tracking-wide">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                <?php foreach ($logs as $log):
                    $isBot = ($log['device_type'] ?? '') === 'bot';
                    $hasOrder = !empty($log['order_placed']) && $log['order_placed'];
                ?>
                <tr class="vlog-row <?= $isBot ? 'bg-red-50' : ($hasOrder ? 'bg-green-50' : '') ?>">
                    <td class="py-2 px-3">
                        <div class="font-mono font-medium text-gray-700"><?= htmlspecialchars($log['device_ip'] ?? '') ?></div>
                        <?php if (!empty($log['network_ip']) && $log['network_ip'] !== $log['device_ip']): ?>
                        <div class="text-gray-400 text-xs"><?= htmlspecialchars($log['network_ip']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 px-3">
                        <?php if ($isBot): ?>
                        <span class="tag-bot"><i class="fas fa-robot mr-1"></i>Bot</span>
                        <?php elseif (($log['device_type'] ?? '') === 'mobile'): ?>
                        <span class="tag-mobile"><i class="fas fa-mobile-alt mr-1"></i>Mobile</span>
                        <?php else: ?>
                        <span class="tag-desktop"><i class="fas fa-desktop mr-1"></i><?= ucfirst($log['device_type'] ?? 'Desktop') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 px-3">
                        <div class="text-gray-700"><?= htmlspecialchars($log['browser'] ?? 'Unknown') ?></div>
                        <div class="text-gray-400"><?= htmlspecialchars($log['os'] ?? '') ?></div>
                    </td>
                    <td class="py-2 px-3 max-w-[180px]">
                        <span class="font-mono text-gray-600 truncate block" title="<?= htmlspecialchars($log['landing_page'] ?? '') ?>">
                            <?= htmlspecialchars(mb_substr($log['landing_page'] ?? '/', 0, 40)) ?>
                        </span>
                    </td>
                    <td class="py-2 px-3">
                        <?php if (!empty($log['customer_phone'])): ?>
                        <a href="?days=<?= $days ?>&search=<?= urlencode($log['customer_phone']) ?>" class="text-blue-600 hover:underline font-medium">
                            <?= htmlspecialchars($log['customer_phone']) ?>
                        </a>
                        <?php else: ?>
                        <span class="text-gray-300">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 px-3 text-center text-gray-600 font-medium"><?= intval($log['pages_viewed'] ?? 1) ?></td>
                    <td class="py-2 px-3 text-center">
                        <?php if ($hasOrder): ?>
                        <a href="<?= SITE_URL ?>/admin/pages/order-view.php?id=<?= intval($log['order_id'] ?? 0) ?>" class="text-green-600 font-bold hover:underline">
                            #<?= intval($log['order_id'] ?? 0) ?>
                        </a>
                        <?php else: ?>
                        <span class="text-gray-300">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 px-3 text-gray-400 whitespace-nowrap">
                        <?= date('d M, H:i', strtotime($log['created_at'] ?? 'now')) ?>
                    </td>
                    <td class="py-2 px-3">
                        <?php if ($isBot || !empty($log['device_ip'])): ?>
                        <button onclick="blockIp('<?= htmlspecialchars($log['device_ip'] ?? '') ?>')"
                                class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs hover:bg-red-200 transition font-medium">
                            <i class="fas fa-ban mr-1"></i>Block
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <tr><td colspan="9" class="py-8 text-center text-gray-400">No visitor logs found</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($logTotal > $perPage):
            $totalPages = ceil($logTotal / $perPage);
        ?>
        <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
            <span>Showing <?= number_format(min($offset + 1, $logTotal)) ?>–<?= number_format(min($offset + $perPage, $logTotal)) ?> of <?= number_format($logTotal) ?></span>
            <div class="flex gap-1">
                <?php for ($pg = max(1, $page-2); $pg <= min($totalPages, $page+3); $pg++): ?>
                <a href="?days=<?= $days ?>&p=<?= $pg ?><?= $searchIp ? '&search='.urlencode($searchIp) : '' ?>"
                   class="px-2.5 py-1 rounded <?= $pg === $page ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?> transition">
                    <?= $pg ?>
                </a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Block IP Modal -->
<div id="blockModal" class="fixed inset-0 bg-black/50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6">
        <h3 class="font-bold text-gray-800 mb-1">Block IP Address</h3>
        <p class="text-sm text-gray-500 mb-4">Block <strong id="blockIpDisplay" class="font-mono text-red-600"></strong> from accessing your site?</p>
        <div class="mb-4">
            <label class="block text-xs font-medium text-gray-600 mb-1">Duration</label>
            <select id="blockDuration" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-300">
                <option value="24">24 hours</option>
                <option value="72">3 days</option>
                <option value="168">7 days</option>
                <option value="720">30 days</option>
                <option value="">Permanent</option>
            </select>
        </div>
        <div class="flex gap-2">
            <button onclick="confirmBlock()" class="flex-1 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition">
                <i class="fas fa-ban mr-1"></i> Block IP
            </button>
            <button onclick="document.getElementById('blockModal').classList.add('hidden')" class="px-4 py-2 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200 transition">Cancel</button>
        </div>
    </div>
</div>

<script>
let pendingIp = '';

function blockIp(ip) {
    pendingIp = ip;
    document.getElementById('blockIpDisplay').textContent = ip;
    document.getElementById('blockModal').classList.remove('hidden');
}

function confirmBlock() {
    const duration = document.getElementById('blockDuration').value;
    if (!pendingIp) return;

    const fd = new FormData();
    fd.append('action', 'add_ip_rule');
    fd.append('ip', pendingIp);
    fd.append('rule_type', 'block');
    fd.append('reason', 'Blocked from Visitor Analytics (bot/suspicious)');
    if (duration) fd.append('duration', duration);

    fetch('<?= SITE_URL ?>/api/security.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            document.getElementById('blockModal').classList.add('hidden');
            if (d.success) {
                showToast('IP blocked: ' + pendingIp, 'success');
                // Highlight the row
                document.querySelectorAll('tr').forEach(row => {
                    if (row.textContent.includes(pendingIp)) {
                        row.style.opacity = '.4';
                        row.style.textDecoration = 'line-through';
                    }
                });
            } else {
                showToast(d.message || 'Failed to block IP', 'error');
            }
        }).catch(() => showToast('Network error', 'error'));
}

function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = 'fixed bottom-4 right-4 z-50 px-4 py-2.5 rounded-lg text-sm font-medium text-white shadow-lg transition-all duration-300 ' + (type === 'success' ? 'bg-green-600' : 'bg-red-600');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
</script>

<?php require_once __DIR__ . '/../includes/header.php'; // footer ?>
