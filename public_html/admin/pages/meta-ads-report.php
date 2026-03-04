<?php
/**
 * Meta Ads Report - Facebook/Meta advertising analytics
 * UI Structure ready for API integration later
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Meta Ads Report';
$db = Database::getInstance();

$activeTab = $_GET['tab'] ?? 'summary';
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['to'] ?? date('Y-m-d');

// Ensure tables exist (graceful fallback)
try {
    $db->query("SELECT 1 FROM ad_expenses LIMIT 1");
    $tablesExist = true;
} catch (Exception $e) {
    $tablesExist = false;
}

if ($tablesExist) {
    // Summary metrics
    $adSummary = $db->fetch("SELECT 
        COALESCE(SUM(amount_usd),0) as total_usd,
        COALESCE(SUM(amount_bdt),0) as total_bdt,
        COALESCE(SUM(impressions),0) as total_impressions,
        COALESCE(SUM(clicks),0) as total_clicks,
        COALESCE(SUM(conversions),0) as total_conversions,
        COALESCE(SUM(reach),0) as total_reach,
        COALESCE(AVG(cpm),0) as avg_cpm,
        COALESCE(AVG(cpc),0) as avg_cpc,
        COALESCE(AVG(ctr),0) as avg_ctr,
        COUNT(DISTINCT campaign_id) as campaign_count,
        COUNT(DISTINCT account_id) as account_count
        FROM ad_expenses WHERE expense_date BETWEEN ? AND ?", [$dateFrom, $dateTo]);

    // Daily spend trend
    $dailySpend = $db->fetchAll("SELECT expense_date as date, 
        SUM(amount_bdt) as spend_bdt, SUM(amount_usd) as spend_usd,
        SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(conversions) as conversions
        FROM ad_expenses WHERE expense_date BETWEEN ? AND ? 
        GROUP BY expense_date ORDER BY expense_date", [$dateFrom, $dateTo]);

    // Campaign performance
    $campaigns = $db->fetchAll("SELECT campaign_name, campaign_id,
        SUM(amount_bdt) as spend_bdt, SUM(amount_usd) as spend_usd,
        SUM(impressions) as impressions, SUM(clicks) as clicks,
        SUM(conversions) as conversions, SUM(reach) as reach,
        AVG(cpm) as avg_cpm, AVG(cpc) as avg_cpc, AVG(ctr) as avg_ctr
        FROM ad_expenses WHERE expense_date BETWEEN ? AND ? AND campaign_name IS NOT NULL
        GROUP BY campaign_id, campaign_name ORDER BY spend_bdt DESC", [$dateFrom, $dateTo]);

    // Account breakdown
    $accounts = $db->fetchAll("SELECT account_name, account_id,
        SUM(amount_bdt) as spend_bdt, SUM(amount_usd) as spend_usd,
        SUM(conversions) as conversions, COUNT(*) as entries
        FROM ad_expenses WHERE expense_date BETWEEN ? AND ? AND account_id IS NOT NULL
        GROUP BY account_id, account_name ORDER BY spend_bdt DESC", [$dateFrom, $dateTo]);

    // Hourly distribution (from expense data)
    $hourlyAds = $db->fetchAll("SELECT HOUR(created_at) as hr, SUM(amount_bdt) as spend, SUM(clicks) as clicks
        FROM ad_expenses WHERE expense_date BETWEEN ? AND ?
        GROUP BY HOUR(created_at) ORDER BY hr", [$dateFrom, $dateTo]);

    // Order correlation (orders within ad period)
    $orderAnalysis = $db->fetch("SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(total),0) as total_revenue,
        COUNT(CASE WHEN order_status='delivered' THEN 1 END) as delivered,
        COUNT(CASE WHEN channel='facebook' OR channel='meta' OR channel='landing_page' THEN 1 END) as ad_orders
        FROM orders WHERE DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);

    $roas = ($adSummary['total_bdt'] ?? 0) > 0 ? round(($orderAnalysis['total_revenue'] ?? 0) / $adSummary['total_bdt'], 2) : 0;
    $cpa = ($adSummary['total_conversions'] ?? 0) > 0 ? round(($adSummary['total_bdt'] ?? 0) / $adSummary['total_conversions']) : 0;

    // Monthly expense breakdown for settings tab
    $monthlyExpenses = $db->fetchAll("SELECT DATE_FORMAT(expense_date,'%Y-%m') as month,
        SUM(amount_bdt) as bdt, SUM(amount_usd) as usd, COUNT(*) as entries
        FROM ad_expenses GROUP BY DATE_FORMAT(expense_date,'%Y-%m') ORDER BY month DESC LIMIT 12");
} else {
    $adSummary = ['total_usd'=>0,'total_bdt'=>0,'total_impressions'=>0,'total_clicks'=>0,'total_conversions'=>0,'total_reach'=>0,'avg_cpm'=>0,'avg_cpc'=>0,'avg_ctr'=>0,'campaign_count'=>0,'account_count'=>0];
    $dailySpend = $campaigns = $accounts = $hourlyAds = $monthlyExpenses = [];
    $orderAnalysis = ['total_orders'=>0,'total_revenue'=>0,'delivered'=>0,'ad_orders'=>0];
    $roas = $cpa = 0;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="meta-ads-report-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Spend (BDT)', 'Spend (USD)', 'Impressions', 'Clicks', 'Conversions']);
    foreach ($dailySpend as $d) {
        fputcsv($output, [$d['date'], $d['spend_bdt'], $d['spend_usd'], $d['impressions'], $d['clicks'], $d['conversions']]);
    }
    fclose($output);
    exit;
}

// Meta accounts for settings
try {
    $metaAccounts = $db->fetchAll("SELECT * FROM meta_ads_accounts ORDER BY account_name");
} catch (Exception $e) {
    $metaAccounts = [];
}

// Handle settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_account') {
    try {
        $db->query("INSERT INTO meta_ads_accounts (account_name, account_id, business_id, access_token, currency, daily_budget_limit, monthly_budget_limit, status)
            VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE account_name=VALUES(account_name), business_id=VALUES(business_id), 
            access_token=VALUES(access_token), daily_budget_limit=VALUES(daily_budget_limit), monthly_budget_limit=VALUES(monthly_budget_limit), status=VALUES(status)", [
            sanitize($_POST['account_name'] ?? ''), sanitize($_POST['account_id'] ?? ''),
            sanitize($_POST['business_id'] ?? ''), sanitize($_POST['access_token'] ?? ''),
            sanitize($_POST['currency'] ?? 'USD'),
            floatval($_POST['daily_budget_limit'] ?? 0), floatval($_POST['monthly_budget_limit'] ?? 0),
            sanitize($_POST['status'] ?? 'active')
        ]);
        redirect(adminUrl('pages/meta-ads-report.php?tab=settings&msg=saved'));
    } catch (Exception $e) { /* table may not exist */ }
}

require_once __DIR__ . '/../includes/header.php';
$tabs = [
    'summary' => 'Summary',
    'performance' => 'Ad Performance',
    'quality' => 'Campaign Quality',
    'expenses' => 'Expenses',
    'orders' => 'Order Analysis',
    'hourly' => 'Hourly Analysis',
    'profit' => 'Estimated Profit',
    'settings' => 'Settings',
];
?>

<?php if (!$tablesExist): ?>
<div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl">
    <p class="text-amber-800 font-medium"><i class="fas fa-exclamation-triangle mr-2"></i>Database tables not found. Please run the migration SQL first:</p>
    <code class="block mt-2 p-2 bg-white rounded text-sm text-gray-700">admin/migrations/002_new_features.sql</code>
</div>
<?php endif; ?>

<!-- Date Filter & Tabs -->
<div class="bg-white rounded-xl shadow-sm border mb-6">
    <div class="p-4 border-b">
        <form method="GET" class="flex flex-wrap items-center gap-3">
            <input type="hidden" name="tab" value="<?= $activeTab ?>">
            <input type="hidden" name="from" value="<?= $dateFrom ?>">
            <input type="hidden" name="to" value="<?= $dateTo ?>">
            <div data-kb-datepicker data-from-param="from" data-to-param="to"></div>
            <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="ml-auto bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-green-700">
                <i class="fas fa-download mr-1"></i>CSV
            </a>
        </form>
    </div>
    <div class="flex overflow-x-auto border-b">
        <?php foreach ($tabs as $key => $label): ?>
        <a href="?tab=<?= $key ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" 
           class="px-4 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition <?= $activeTab === $key ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($activeTab === 'summary'): ?>
<!-- SUMMARY TAB -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-gray-800">৳<?= number_format($adSummary['total_bdt'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Total Spend (BDT)</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-blue-600">$<?= number_format($adSummary['total_usd'] ?? 0, 2) ?></p>
        <p class="text-xs text-gray-500 mt-1">Total Spend (USD)</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-green-600"><?= $roas ?>x</p>
        <p class="text-xs text-gray-500 mt-1">ROAS</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-purple-600">৳<?= number_format($cpa) ?></p>
        <p class="text-xs text-gray-500 mt-1">Cost per Acquisition</p>
    </div>
</div>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-gray-800"><?= number_format($adSummary['total_impressions'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Impressions</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-gray-800"><?= number_format($adSummary['total_clicks'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Clicks</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-gray-800"><?= number_format($adSummary['total_conversions'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Conversions</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-gray-800"><?= number_format($adSummary['total_reach'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Reach</p>
    </div>
</div>
<div class="grid md:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Daily Ad Spend</h4>
        <canvas id="spendChart" height="150"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Clicks vs Conversions</h4>
        <canvas id="convChart" height="150"></canvas>
    </div>
</div>
<script>
const dailyAd = <?= json_encode($dailySpend) ?>;
new Chart(document.getElementById('spendChart'), {
    type: 'bar', data: {
        labels: dailyAd.map(d => { const dt = new Date(d.date); return dt.toLocaleDateString('en',{month:'short',day:'numeric'}); }),
        datasets: [{ label: 'Spend (BDT)', data: dailyAd.map(d => d.spend_bdt), backgroundColor: 'rgba(59,130,246,0.6)', borderRadius: 4 }]
    }, options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
new Chart(document.getElementById('convChart'), {
    type: 'line', data: {
        labels: dailyAd.map(d => { const dt = new Date(d.date); return dt.toLocaleDateString('en',{month:'short',day:'numeric'}); }),
        datasets: [
            { label: 'Clicks', data: dailyAd.map(d => d.clicks), borderColor: '#3b82f6', fill: false, tension: 0.4 },
            { label: 'Conversions', data: dailyAd.map(d => d.conversions), borderColor: '#22c55e', fill: false, tension: 0.4 }
        ]
    }, options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php elseif ($activeTab === 'performance'): ?>
<!-- AD PERFORMANCE TAB -->
<div class="bg-white rounded-xl shadow-sm border mb-6">
    <div class="p-4 border-b"><h3 class="font-semibold text-gray-800">Campaign Performance</h3></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Campaign</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Spend (BDT)</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Impressions</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Clicks</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">CTR %</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Conversions</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">CPC</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">CPM</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($campaigns as $c): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium"><?= e($c['campaign_name'] ?? 'Unknown') ?></td>
                    <td class="px-4 py-3 text-right">৳<?= number_format($c['spend_bdt'] ?? 0) ?></td>
                    <td class="px-4 py-3 text-right"><?= number_format($c['impressions'] ?? 0) ?></td>
                    <td class="px-4 py-3 text-right"><?= number_format($c['clicks'] ?? 0) ?></td>
                    <td class="px-4 py-3 text-right"><?= number_format($c['avg_ctr'] ?? 0, 2) ?>%</td>
                    <td class="px-4 py-3 text-right font-semibold text-green-600"><?= number_format($c['conversions'] ?? 0) ?></td>
                    <td class="px-4 py-3 text-right">$<?= number_format($c['avg_cpc'] ?? 0, 2) ?></td>
                    <td class="px-4 py-3 text-right">$<?= number_format($c['avg_cpm'] ?? 0, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($campaigns)): ?>
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">No campaign data found. Add ad expenses to see performance.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($activeTab === 'quality'): ?>
<!-- CAMPAIGN QUALITY TAB -->
<div class="grid md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-sm text-gray-500 mb-1">Avg CTR</p>
        <p class="text-2xl font-bold <?= ($adSummary['avg_ctr'] ?? 0) >= 1 ? 'text-green-600' : 'text-red-600' ?>"><?= number_format($adSummary['avg_ctr'] ?? 0, 2) ?>%</p>
        <p class="text-xs text-gray-400 mt-1"><?= ($adSummary['avg_ctr'] ?? 0) >= 1 ? 'Good' : 'Below average — optimize ad creatives' ?></p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-sm text-gray-500 mb-1">Avg CPC</p>
        <p class="text-2xl font-bold text-gray-800">$<?= number_format($adSummary['avg_cpc'] ?? 0, 2) ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= ($adSummary['avg_cpc'] ?? 0) <= 0.5 ? 'Competitive' : 'Consider optimizing targeting' ?></p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-sm text-gray-500 mb-1">Avg CPM</p>
        <p class="text-2xl font-bold text-gray-800">$<?= number_format($adSummary['avg_cpm'] ?? 0, 2) ?></p>
        <p class="text-xs text-gray-400 mt-1">Cost per 1,000 impressions</p>
    </div>
</div>
<div class="bg-white rounded-xl shadow-sm border p-5 mb-6">
    <h4 class="font-semibold text-gray-800 mb-4">Campaign Quality Scores</h4>
    <div class="space-y-4">
        <?php foreach ($campaigns as $c):
            $ctr = floatval($c['avg_ctr'] ?? 0);
            $score = $ctr >= 2 ? 'Excellent' : ($ctr >= 1 ? 'Good' : ($ctr >= 0.5 ? 'Average' : 'Poor'));
            $scoreColor = $ctr >= 2 ? 'text-green-600 bg-green-50' : ($ctr >= 1 ? 'text-blue-600 bg-blue-50' : ($ctr >= 0.5 ? 'text-yellow-600 bg-yellow-50' : 'text-red-600 bg-red-50'));
        ?>
        <div class="flex items-center gap-4 p-3 bg-gray-50 rounded-lg">
            <div class="flex-1 min-w-0">
                <p class="font-medium text-sm truncate"><?= e($c['campaign_name'] ?? 'Unknown') ?></p>
                <p class="text-xs text-gray-500">CTR: <?= number_format($ctr, 2) ?>% · CPC: $<?= number_format($c['avg_cpc'] ?? 0, 2) ?> · Reach: <?= number_format($c['reach'] ?? 0) ?></p>
            </div>
            <span class="px-3 py-1 rounded-full text-xs font-bold <?= $scoreColor ?>"><?= $score ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($campaigns)): ?>
        <p class="text-gray-400 text-center py-4">No campaign data available</p>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($activeTab === 'expenses'): ?>
<!-- EXPENSES TAB -->
<div class="grid md:grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-sm text-gray-500 mb-1">Total Ad Spend (BDT)</p>
        <p class="text-2xl font-bold text-red-600">৳<?= number_format($adSummary['total_bdt'] ?? 0) ?></p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-sm text-gray-500 mb-1">Total Ad Spend (USD)</p>
        <p class="text-2xl font-bold text-gray-800">$<?= number_format($adSummary['total_usd'] ?? 0, 2) ?></p>
    </div>
</div>
<div class="bg-white rounded-xl shadow-sm border mb-6">
    <div class="p-4 border-b"><h3 class="font-semibold text-gray-800">Account Spend Breakdown</h3></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Account</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Spend (BDT)</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Spend (USD)</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Conversions</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Entries</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($accounts as $a): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium"><?= e($a['account_name'] ?? $a['account_id'] ?? 'Unknown') ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-red-600">৳<?= number_format($a['spend_bdt'] ?? 0) ?></td>
                    <td class="px-4 py-3 text-right">$<?= number_format($a['spend_usd'] ?? 0, 2) ?></td>
                    <td class="px-4 py-3 text-right text-green-600 font-medium"><?= number_format($a['conversions'] ?? 0) ?></td>
                    <td class="px-4 py-3 text-right text-gray-500"><?= $a['entries'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($accounts)): ?>
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No account data</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="bg-white rounded-xl shadow-sm border p-5 mb-6">
    <h4 class="font-semibold text-gray-800 mb-4">Monthly Spend History</h4>
    <div class="space-y-2">
        <?php foreach ($monthlyExpenses as $me): ?>
        <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium"><?= date('F Y', strtotime($me['month'].'-01')) ?></span>
            <div class="text-right">
                <span class="font-semibold text-sm text-red-600">৳<?= number_format($me['bdt']) ?></span>
                <span class="text-xs text-gray-400 ml-2">($<?= number_format($me['usd'], 2) ?>)</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php elseif ($activeTab === 'orders'): ?>
<!-- ORDER ANALYSIS TAB -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-gray-800"><?= number_format($orderAnalysis['total_orders'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Total Orders (Period)</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-blue-600"><?= number_format($orderAnalysis['ad_orders'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">From Ads/Landing Pages</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-green-600">৳<?= number_format($orderAnalysis['total_revenue'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">Total Revenue</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold <?= $roas >= 2 ? 'text-green-600' : 'text-red-600' ?>"><?= $roas ?>x</p>
        <p class="text-xs text-gray-500 mt-1">ROAS</p>
    </div>
</div>
<div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-200 p-5 mb-6">
    <h4 class="font-semibold text-blue-800 mb-3">Ad Spend vs Revenue Analysis</h4>
    <div class="grid md:grid-cols-3 gap-4 text-sm">
        <div class="bg-white/60 rounded-lg p-3">
            <p class="text-gray-600">Ad Spend</p>
            <p class="font-bold text-lg text-red-600">৳<?= number_format($adSummary['total_bdt'] ?? 0) ?></p>
        </div>
        <div class="bg-white/60 rounded-lg p-3">
            <p class="text-gray-600">Revenue Generated</p>
            <p class="font-bold text-lg text-green-600">৳<?= number_format($orderAnalysis['total_revenue'] ?? 0) ?></p>
        </div>
        <div class="bg-white/60 rounded-lg p-3">
            <p class="text-gray-600">Net After Ads</p>
            <?php $netAfterAds = ($orderAnalysis['total_revenue'] ?? 0) - ($adSummary['total_bdt'] ?? 0); ?>
            <p class="font-bold text-lg <?= $netAfterAds >= 0 ? 'text-green-600' : 'text-red-600' ?>">৳<?= number_format($netAfterAds) ?></p>
        </div>
    </div>
</div>

<?php elseif ($activeTab === 'hourly'): ?>
<!-- HOURLY ANALYSIS TAB -->
<div class="bg-white rounded-xl shadow-sm border p-5 mb-6">
    <h4 class="font-semibold text-gray-800 mb-4">Hourly Ad Spend Distribution</h4>
    <canvas id="hourlyAdChart" height="150"></canvas>
</div>
<script>
const hourlyAd = <?= json_encode($hourlyAds) ?>;
const hLabels = Array.from({length:24}, (_,i) => i+':00');
const hSpend = new Array(24).fill(0);
const hClicks = new Array(24).fill(0);
hourlyAd.forEach(h => { hSpend[parseInt(h.hr)] = parseFloat(h.spend||0); hClicks[parseInt(h.hr)] = parseInt(h.clicks||0); });
new Chart(document.getElementById('hourlyAdChart'), {
    type: 'bar', data: {
        labels: hLabels,
        datasets: [
            { label: 'Spend (BDT)', data: hSpend, backgroundColor: 'rgba(239,68,68,0.5)', borderRadius: 4, yAxisID: 'y' },
            { label: 'Clicks', data: hClicks, type: 'line', borderColor: '#3b82f6', fill: false, tension: 0.4, yAxisID: 'y1' }
        ]
    }, options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } } } }
});
</script>

<?php elseif ($activeTab === 'profit'): ?>
<!-- ESTIMATED PROFIT TAB -->
<?php
$estGrossRevenue = $orderAnalysis['total_revenue'] ?? 0;
$estAdCost = $adSummary['total_bdt'] ?? 0;
$estOtherExpenses = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE expense_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'] ?? 0;
$estCOGS = $db->fetch("SELECT COALESCE(SUM(oi.quantity * COALESCE(p.cost_price,0)),0) as total
    FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN products p ON oi.product_id=p.id
    WHERE o.order_status='delivered' AND DATE(o.created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'] ?? 0;
$estShipping = $db->fetch("SELECT COALESCE(SUM(courier_delivery_charge),0) as total FROM orders WHERE order_status='delivered' AND DATE(created_at) BETWEEN ? AND ? AND courier_delivery_charge > 0", [$dateFrom, $dateTo])['total'] ?? 0;
$estGrossProfit = $estGrossRevenue - $estCOGS;
$estNetProfit = $estGrossProfit - $estAdCost - $estOtherExpenses - $estShipping;
?>
<div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
    <h3 class="font-semibold text-gray-800 mb-6 text-lg">Estimated Profit Breakdown</h3>
    <div class="space-y-4 max-w-lg">
        <div class="flex justify-between items-center py-2">
            <span class="text-gray-700">Gross Revenue</span>
            <span class="font-bold text-green-600 text-lg">৳<?= number_format($estGrossRevenue) ?></span>
        </div>
        <div class="flex justify-between items-center py-2 pl-4 text-sm">
            <span class="text-gray-500">− Cost of Goods (COGS)</span>
            <span class="font-medium text-red-500">−৳<?= number_format($estCOGS) ?></span>
        </div>
        <hr>
        <div class="flex justify-between items-center py-2">
            <span class="text-gray-700 font-medium">Gross Profit</span>
            <span class="font-bold <?= $estGrossProfit >= 0 ? 'text-green-600' : 'text-red-600' ?>">৳<?= number_format($estGrossProfit) ?></span>
        </div>
        <div class="flex justify-between items-center py-2 pl-4 text-sm">
            <span class="text-gray-500">− Ad Expenses</span>
            <span class="font-medium text-red-500">−৳<?= number_format($estAdCost) ?></span>
        </div>
        <div class="flex justify-between items-center py-2 pl-4 text-sm">
            <span class="text-gray-500">− Courier/Shipping Costs</span>
            <span class="font-medium text-red-500">−৳<?= number_format($estShipping) ?></span>
        </div>
        <div class="flex justify-between items-center py-2 pl-4 text-sm">
            <span class="text-gray-500">− Other Expenses</span>
            <span class="font-medium text-red-500">−৳<?= number_format($estOtherExpenses) ?></span>
        </div>
        <hr class="border-2">
        <div class="flex justify-between items-center py-3">
            <span class="text-gray-800 font-bold text-lg">Estimated Net Profit</span>
            <span class="font-bold text-xl <?= $estNetProfit >= 0 ? 'text-green-600' : 'text-red-600' ?>">৳<?= number_format($estNetProfit) ?></span>
        </div>
        <?php $netMargin = $estGrossRevenue > 0 ? round(($estNetProfit / $estGrossRevenue) * 100, 1) : 0; ?>
        <div class="text-center">
            <span class="px-4 py-2 rounded-full text-sm font-bold <?= $netMargin >= 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                Net Margin: <?= $netMargin ?>%
            </span>
        </div>
    </div>
</div>

<?php elseif ($activeTab === 'settings'): ?>
<!-- SETTINGS TAB -->
<?php if (($_GET['msg'] ?? '') === 'saved'): ?>
<div class="mb-4 p-3 bg-green-50 text-green-700 rounded-xl text-sm">Account saved!</div>
<?php endif; ?>

<div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Add/Update Ad Account</h3>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="save_account">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Account Name *</label>
                    <input type="text" name="account_name" required class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="My Business Account">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Account ID *</label>
                    <input type="text" name="account_id" required class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="act_123456789">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Business ID</label>
                <input type="text" name="business_id" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="Optional">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Access Token</label>
                <input type="password" name="access_token" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="For future API integration">
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Currency</label>
                    <select name="currency" class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="USD">USD</option>
                        <option value="BDT">BDT</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Daily Limit</label>
                    <input type="number" name="daily_budget_limit" step="0.01" class="w-full border rounded-lg px-3 py-2 text-sm" value="0">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Monthly Limit</label>
                    <input type="number" name="monthly_budget_limit" step="0.01" class="w-full border rounded-lg px-3 py-2 text-sm" value="0">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                <select name="status" class="w-full border rounded-lg px-3 py-2 text-sm">
                    <option value="active">Active</option>
                    <option value="paused">Paused</option>
                    <option value="disabled">Disabled</option>
                </select>
            </div>
            <button class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">Save Account</button>
        </form>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Connected Accounts</h3>
        <?php if (empty($metaAccounts)): ?>
        <p class="text-gray-400 text-center py-8">No accounts configured yet</p>
        <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($metaAccounts as $ma): ?>
            <div class="p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-medium text-sm"><?= e($ma['account_name']) ?></p>
                        <p class="text-xs text-gray-500"><?= e($ma['account_id']) ?></p>
                    </div>
                    <span class="px-2 py-1 rounded-full text-xs font-bold <?= $ma['status']==='active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= ucfirst($ma['status']) ?></span>
                </div>
                <?php if ($ma['daily_budget_limit'] > 0 || $ma['monthly_budget_limit'] > 0): ?>
                <p class="text-xs text-gray-400 mt-1">Limits: $<?= number_format($ma['daily_budget_limit'],2) ?>/day · $<?= number_format($ma['monthly_budget_limit'],2) ?>/month</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="mt-6 p-4 bg-blue-50 rounded-lg">
            <h4 class="font-medium text-blue-800 text-sm mb-2"><i class="fas fa-info-circle mr-1"></i>API Integration</h4>
            <p class="text-xs text-blue-700">Facebook Marketing API integration will be available soon. For now, add ad expenses manually via the "New Ad Expense" page.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
