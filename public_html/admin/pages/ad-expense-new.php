<?php
/**
 * New Ad Expense - Track advertising expenses across platforms
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'New Ad Expense';
$db = Database::getInstance();

// Ensure table exists
try { $db->query("SELECT 1 FROM ad_expenses LIMIT 1"); } catch (Exception $e) {
    $db->query("CREATE TABLE IF NOT EXISTS ad_expenses (id int AUTO_INCREMENT PRIMARY KEY, platform enum('meta','google','tiktok','other') DEFAULT 'meta', account_name varchar(200), account_id varchar(100), campaign_name varchar(300), campaign_id varchar(100), amount_usd decimal(12,2) DEFAULT 0, amount_bdt decimal(12,2) NOT NULL, exchange_rate decimal(8,2), expense_date date NOT NULL, impressions int DEFAULT 0, clicks int DEFAULT 0, conversions int DEFAULT 0, cpm decimal(8,2), cpc decimal(8,2), cpa decimal(8,2), notes text, created_by int, created_at timestamp DEFAULT CURRENT_TIMESTAMP, KEY expense_date(expense_date))");
}

// Get exchange rate setting
$defaultRate = 121.50;
try {
    $rateSetting = $db->fetch("SELECT setting_value FROM meta_ads_settings WHERE setting_key='default_exchange_rate'");
    if ($rateSetting) $defaultRate = (float)$rateSetting['setting_value'];
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_ad_expense') {
        $amountUsd = (float)($_POST['amount_usd'] ?? 0);
        $exchangeRate = (float)($_POST['exchange_rate'] ?? $defaultRate);
        $amountBdt = $amountUsd > 0 ? $amountUsd * $exchangeRate : (float)$_POST['amount_bdt'];
        $impressions = (int)($_POST['impressions'] ?? 0);
        $clicks = (int)($_POST['clicks'] ?? 0);
        $conversions = (int)($_POST['conversions'] ?? 0);

        $cpm = $impressions > 0 ? ($amountUsd / $impressions) * 1000 : null;
        $cpc = $clicks > 0 ? $amountUsd / $clicks : null;
        $cpa = $conversions > 0 ? $amountUsd / $conversions : null;

        $db->query("INSERT INTO ad_expenses (platform, account_name, account_id, campaign_name, campaign_id, amount_usd, amount_bdt, exchange_rate, expense_date, impressions, clicks, conversions, cpm, cpc, cpa, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
            sanitize($_POST['platform'] ?? 'meta'),
            sanitize($_POST['account_name'] ?? ''),
            sanitize($_POST['account_id'] ?? ''),
            sanitize($_POST['campaign_name'] ?? ''),
            sanitize($_POST['campaign_id'] ?? ''),
            $amountUsd, $amountBdt, $exchangeRate,
            $_POST['expense_date'],
            $impressions, $clicks, $conversions,
            $cpm, $cpc, $cpa,
            sanitize($_POST['notes'] ?? ''),
            getAdminId()
        ]);
        $adExpenseId = $db->lastInsertId();

        // Also create accounting entry
        $db->query("INSERT INTO accounting_entries (entry_type, amount, reference_type, reference_id, description, entry_date) VALUES ('expense',?,'ad_expense',?,?,?)",
            [$amountBdt, $adExpenseId, sanitize($_POST['platform'] ?? 'meta') . ' Ad: ' . sanitize($_POST['campaign_name'] ?? 'General'), $_POST['expense_date']]);

        logActivity(getAdminId(), 'ad_expense_created', 'ad_expense', $adExpenseId);
        redirect(adminUrl('pages/ad-expense-new.php?msg=saved'));
    }
}

$msg = $_GET['msg'] ?? '';

// Stats
$thisMonth = date('Y-m');
$monthTotalUsd = $db->fetch("SELECT COALESCE(SUM(amount_usd),0) as total FROM ad_expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=?", [$thisMonth])['total'] ?? 0;
$monthTotalBdt = $db->fetch("SELECT COALESCE(SUM(amount_bdt),0) as total FROM ad_expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=?", [$thisMonth])['total'] ?? 0;

// Recent entries
$recentAds = $db->fetchAll("SELECT * FROM ad_expenses ORDER BY expense_date DESC, id DESC LIMIT 5");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center gap-3 mb-4"><a href="<?= adminUrl('pages/accounting.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">&larr; Accounting</a><a href="<?= adminUrl('pages/expenses.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">Expense List</a><a href="<?= adminUrl('pages/income.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">Income</a></div>
<?php if ($msg === 'saved'): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">Ad expense saved successfully!</div><?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm p-6">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/></svg>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">New Ad Expense</h2>
                    <p class="text-sm text-gray-500">Track advertising spend with performance metrics</p>
                </div>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save_ad_expense">
                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Platform *</label>
                        <select name="platform" required class="w-full border rounded-lg px-3 py-2.5 text-sm">
                            <option value="meta">Meta (Facebook/Instagram)</option>
                            <option value="google">Google Ads</option>
                            <option value="tiktok">TikTok Ads</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Account Name</label>
                        <input type="text" name="account_name" class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="Business account">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date *</label>
                        <input type="date" name="expense_date" required value="<?= date('Y-m-d') ?>" class="w-full border rounded-lg px-3 py-2.5 text-sm">
                    </div>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Campaign Name</label>
                        <input type="text" name="campaign_name" class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="Campaign name">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Campaign ID</label>
                        <input type="text" name="campaign_id" class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="Optional">
                    </div>
                </div>

                <div class="p-4 bg-blue-50 rounded-lg">
                    <h4 class="text-sm font-semibold text-blue-800 mb-3">Spending</h4>
                    <div class="grid md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Amount (USD) *</label>
                            <input type="number" name="amount_usd" step="0.01" min="0" required id="amountUsd" class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="0.00">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Exchange Rate</label>
                            <input type="number" name="exchange_rate" step="0.01" value="<?= $defaultRate ?>" id="exchangeRate" class="w-full border rounded-lg px-3 py-2.5 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Amount (BDT)</label>
                            <input type="number" name="amount_bdt" step="0.01" id="amountBdt" class="w-full border rounded-lg px-3 py-2.5 text-sm bg-gray-50" readonly>
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-gray-50 rounded-lg">
                    <h4 class="text-sm font-semibold text-gray-800 mb-3">Performance Metrics (Optional)</h4>
                    <div class="grid md:grid-cols-3 gap-4">
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">Impressions</label><input type="number" name="impressions" min="0" class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="0"></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">Clicks</label><input type="number" name="clicks" min="0" class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="0"></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">Conversions</label><input type="number" name="conversions" min="0" class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="0"></div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full border rounded-lg px-3 py-2.5 text-sm"></textarea>
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">Save Ad Expense</button>
            </form>
        </div>
    </div>

    <div class="space-y-4">
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-3">This Month</h4>
            <div class="space-y-3">
                <div class="flex justify-between py-2 border-b"><span class="text-sm text-gray-500">Total USD</span><span class="font-semibold text-blue-600">$<?= number_format($monthTotalUsd, 2) ?></span></div>
                <div class="flex justify-between py-2"><span class="text-sm text-gray-500">Total BDT</span><span class="font-semibold text-red-600">৳<?= number_format($monthTotalBdt) ?></span></div>
            </div>
        </div>

        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-3">Recent Ad Expenses</h4>
            <div class="space-y-2">
                <?php foreach ($recentAds as $ra): ?>
                <div class="flex justify-between py-1.5 text-sm border-b border-gray-50">
                    <div>
                        <p class="font-medium text-gray-800"><?= e($ra['campaign_name'] ?: ucfirst($ra['platform'])) ?></p>
                        <p class="text-xs text-gray-400"><?= date('d M', strtotime($ra['expense_date'])) ?></p>
                    </div>
                    <span class="font-semibold text-red-600">$<?= number_format($ra['amount_usd'], 2) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($recentAds)): ?><p class="text-sm text-gray-400">No ad expenses yet</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function calcBdt() {
    const usd = parseFloat(document.getElementById('amountUsd').value) || 0;
    const rate = parseFloat(document.getElementById('exchangeRate').value) || 0;
    document.getElementById('amountBdt').value = (usd * rate).toFixed(2);
}
document.getElementById('amountUsd').addEventListener('input', calcBdt);
document.getElementById('exchangeRate').addEventListener('input', calcBdt);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
