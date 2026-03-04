<?php
/**
 * Meta Ads Report (Reports Section) - Quick summary view
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Meta Ads Report';
$db = Database::getInstance();

$period = $_GET['period'] ?? '30';
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime("-{$period} days"));
$dateTo = $_GET['to'] ?? date('Y-m-d');

try {
    $db->query("SELECT 1 FROM ad_expenses LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {
    $tableExists = false;
}

if ($tableExists) {
    $summary = $db->fetch("SELECT 
        COALESCE(SUM(amount_bdt),0) as total_bdt, COALESCE(SUM(amount_usd),0) as total_usd,
        COALESCE(SUM(impressions),0) as impressions, COALESCE(SUM(clicks),0) as clicks,
        COALESCE(SUM(conversions),0) as conversions, COALESCE(SUM(reach),0) as reach,
        COALESCE(AVG(ctr),0) as avg_ctr, COALESCE(AVG(cpc),0) as avg_cpc,
        COUNT(DISTINCT campaign_id) as campaigns, COUNT(DISTINCT account_id) as accounts
        FROM ad_expenses WHERE expense_date BETWEEN ? AND ?", [$dateFrom, $dateTo]);

    $dailySpend = $db->fetchAll("SELECT expense_date as date, SUM(amount_bdt) as spend, SUM(clicks) as clicks, SUM(conversions) as conv
        FROM ad_expenses WHERE expense_date BETWEEN ? AND ? GROUP BY expense_date ORDER BY expense_date", [$dateFrom, $dateTo]);

    $topCampaigns = $db->fetchAll("SELECT campaign_name, SUM(amount_bdt) as spend, SUM(conversions) as conv, AVG(ctr) as ctr
        FROM ad_expenses WHERE expense_date BETWEEN ? AND ? AND campaign_name IS NOT NULL
        GROUP BY campaign_id, campaign_name ORDER BY spend DESC LIMIT 10", [$dateFrom, $dateTo]);

    $orderRevenue = $db->fetch("SELECT COALESCE(SUM(total),0) as revenue FROM orders 
        WHERE order_status='delivered' AND DATE(created_at) BETWEEN ? AND ?", [$dateFrom, $dateTo])['revenue'] ?? 0;
    $roas = ($summary['total_bdt'] ?? 0) > 0 ? round($orderRevenue / $summary['total_bdt'], 2) : 0;
    $cpa = ($summary['conversions'] ?? 0) > 0 ? round(($summary['total_bdt'] ?? 0) / $summary['conversions']) : 0;
} else {
    $summary = ['total_bdt'=>0,'total_usd'=>0,'impressions'=>0,'clicks'=>0,'conversions'=>0,'reach'=>0,'avg_ctr'=>0,'avg_cpc'=>0,'campaigns'=>0,'accounts'=>0];
    $dailySpend = $topCampaigns = [];
    $roas = $cpa = $orderRevenue = 0;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="meta-ads-summary-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Spend (BDT)', $summary['total_bdt']]);
    fputcsv($output, ['Total Spend (USD)', $summary['total_usd']]);
    fputcsv($output, ['Impressions', $summary['impressions']]);
    fputcsv($output, ['Clicks', $summary['clicks']]);
    fputcsv($output, ['Conversions', $summary['conversions']]);
    fputcsv($output, ['ROAS', $roas]);
    fputcsv($output, ['CPA (BDT)', $cpa]);
    fputcsv($output, []);
    fputcsv($output, ['Campaign', 'Spend (BDT)', 'Conversions', 'CTR %']);
    foreach ($topCampaigns as $c) {
        fputcsv($output, [$c['campaign_name'], $c['spend'], $c['conv'], number_format($c['ctr'], 2)]);
    }
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$tableExists): ?>
<div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl">
    <p class="text-amber-800 font-medium"><i class="fas fa-exclamation-triangle mr-2"></i>Run migration first: <code>admin/migrations/002_new_features.sql</code></p>
</div>
<?php endif; ?>

<!-- Filter + Actions -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <input type="hidden" name="from" value="<?= $dateFrom ?>">
        <input type="hidden" name="to" value="<?= $dateTo ?>">
        <div data-kb-datepicker data-from-param="from" data-to-param="to"></div>
        <div class="flex items-center gap-2 ml-auto">
            <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-green-700"><i class="fas fa-download mr-1"></i>CSV</a>
            <a href="<?= adminUrl('pages/meta-ads-report.php') ?>" class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-indigo-700">Full Report →</a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-red-600">৳<?= number_format($summary['total_bdt']) ?></p>
        <p class="text-xs text-gray-500 mt-1">Total Ad Spend</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-green-600"><?= $roas ?>x</p>
        <p class="text-xs text-gray-500 mt-1">ROAS</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-purple-600">৳<?= number_format($cpa) ?></p>
        <p class="text-xs text-gray-500 mt-1">Cost per Acquisition</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-2xl font-bold text-blue-600"><?= number_format($summary['conversions']) ?></p>
        <p class="text-xs text-gray-500 mt-1">Conversions</p>
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-xl font-bold text-gray-800"><?= number_format($summary['impressions']) ?></p>
        <p class="text-xs text-gray-500">Impressions</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-xl font-bold text-gray-800"><?= number_format($summary['clicks']) ?></p>
        <p class="text-xs text-gray-500">Clicks</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-xl font-bold text-gray-800"><?= number_format($summary['avg_ctr'], 2) ?>%</p>
        <p class="text-xs text-gray-500">Avg CTR</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border">
        <p class="text-xl font-bold text-gray-800"><?= $summary['campaigns'] ?></p>
        <p class="text-xs text-gray-500">Campaigns</p>
    </div>
</div>

<!-- Charts -->
<div class="grid md:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Daily Ad Spend vs Conversions</h4>
        <canvas id="spendChart" height="150"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <h4 class="font-semibold text-gray-800 mb-4">Top Campaigns by Spend</h4>
        <?php foreach ($topCampaigns as $i => $c): ?>
        <div class="flex items-center gap-3 py-2 <?= $i < count($topCampaigns)-1 ? 'border-b' : '' ?>">
            <span class="w-6 h-6 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center text-xs font-bold"><?= $i+1 ?></span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium truncate"><?= e($c['campaign_name']) ?></p>
                <p class="text-xs text-gray-500"><?= $c['conv'] ?> conversions · CTR <?= number_format($c['ctr'], 2) ?>%</p>
            </div>
            <span class="font-semibold text-sm text-red-600">৳<?= number_format($c['spend']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($topCampaigns)): ?><p class="text-gray-400 text-center py-4">No campaign data</p><?php endif; ?>
    </div>
</div>

<script>
const daily = <?= json_encode($dailySpend) ?>;
new Chart(document.getElementById('spendChart'), {
    type: 'bar', data: {
        labels: daily.map(d => { const dt = new Date(d.date); return dt.toLocaleDateString('en',{month:'short',day:'numeric'}); }),
        datasets: [
            { label: 'Spend (BDT)', data: daily.map(d => d.spend), backgroundColor: 'rgba(239,68,68,0.5)', borderRadius: 4, yAxisID: 'y' },
            { label: 'Conversions', data: daily.map(d => d.conv), type: 'line', borderColor: '#22c55e', fill: false, tension: 0.4, yAxisID: 'y1' }
        ]
    }, options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } } } }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
