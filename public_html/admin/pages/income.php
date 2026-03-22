<?php
/**
 * Income Management - Track all income sources
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Income';
$db = Database::getInstance();

// Ensure income table exists
try { $db->query("SELECT 1 FROM income LIMIT 1"); } catch (Exception $e) {
    $db->query("CREATE TABLE IF NOT EXISTS income (id int AUTO_INCREMENT PRIMARY KEY, title varchar(200) NOT NULL, amount decimal(12,2) NOT NULL, income_date date NOT NULL, source varchar(100), payment_method varchar(30), reference varchar(100), reference_type varchar(50), reference_id int, notes text, created_by int, created_at timestamp DEFAULT CURRENT_TIMESTAMP, KEY income_date(income_date))");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_income') {
        $db->query("INSERT INTO income (title, amount, income_date, source, payment_method, reference, notes, created_by) VALUES (?,?,?,?,?,?,?,?)", [
            sanitize($_POST['title']), (float)$_POST['amount'], $_POST['income_date'],
            sanitize($_POST['source'] ?? ''), sanitize($_POST['payment_method'] ?? ''),
            sanitize($_POST['reference'] ?? ''), sanitize($_POST['notes'] ?? ''), getAdminId()
        ]);
        $incId = $db->lastInsertId();
        $db->query("INSERT INTO accounting_entries (entry_type, amount, reference_type, reference_id, description, entry_date) VALUES ('income',?,'manual_income',?,?,?)",
            [(float)$_POST['amount'], $incId, sanitize($_POST['title']), $_POST['income_date']]);
        redirect(adminUrl('pages/income.php?msg=saved'));
    }
    if ($action === 'delete_income') {
        $id = (int)$_POST['id'];
        $db->query("DELETE FROM accounting_entries WHERE reference_type='manual_income' AND reference_id=?", [$id]);
        $db->query("DELETE FROM income WHERE id=?", [$id]);
        redirect(adminUrl('pages/income.php?msg=deleted'));
    }
}

$msg = $_GET['msg'] ?? '';
$tab = $_GET['tab'] ?? 'list';
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$sourceFilter = $_GET['source'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1));

// Combined income: orders + manual income
$where = "WHERE entry_date BETWEEN ? AND ? AND entry_type='income'";
$params = [$dateFrom, $dateTo];

// Order-based income
$orderIncome = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM accounting_entries WHERE entry_type='income' AND reference_type='order' AND entry_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'] ?? 0;
$manualIncome = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM income WHERE income_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'] ?? 0;
$totalIncome = $orderIncome + $manualIncome;

// Manual income list
$incWhere = "WHERE i.income_date BETWEEN ? AND ?";
$incParams = [$dateFrom, $dateTo];
if ($sourceFilter) { $incWhere .= " AND i.source=?"; $incParams[] = $sourceFilter; }

$incTotal = $db->fetch("SELECT COUNT(*) as cnt FROM income i $incWhere", $incParams)['cnt'] ?? 0;
$incomes = $db->fetchAll("SELECT i.*, au.full_name as created_by_name FROM income i LEFT JOIN admin_users au ON i.created_by=au.id $incWhere ORDER BY i.income_date DESC, i.id DESC LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET " . (($page-1)*ADMIN_ITEMS_PER_PAGE), $incParams);
$pagination = paginate($incTotal, $page, ADMIN_ITEMS_PER_PAGE, adminUrl('pages/income.php?') . http_build_query(array_filter(['from'=>$dateFrom,'to'=>$dateTo,'source'=>$sourceFilter])));

// Income by source
$bySource = $db->fetchAll("SELECT source, SUM(amount) as total, COUNT(*) as cnt FROM income WHERE income_date BETWEEN ? AND ? GROUP BY source ORDER BY total DESC", [$dateFrom, $dateTo]);

// Monthly trend
$monthlyTrend = $db->fetchAll("SELECT DATE_FORMAT(entry_date,'%Y-%m') as month, SUM(amount) as total FROM accounting_entries WHERE entry_type='income' AND entry_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY month ORDER BY month");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4"><div class="flex gap-2"><a href="<?= adminUrl('pages/accounting.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">← Accounting</a><a href="<?= adminUrl('pages/expenses.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">Expenses</a><a href="<?= adminUrl('pages/liabilities.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">Liabilities</a></div></div>
<?php if ($msg === 'saved'): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">Income recorded!</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">Income deleted!</div><?php endif; ?>

<!-- Summary -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500 mb-1">Order Revenue</p><p class="text-2xl font-bold text-green-600">৳<?= number_format($orderIncome) ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500 mb-1">Other Income</p><p class="text-2xl font-bold text-blue-600">৳<?= number_format($manualIncome) ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500 mb-1">Total Income</p><p class="text-2xl font-bold text-green-700">৳<?= number_format($totalIncome) ?></p></div>
</div>

<div class="flex gap-2 mb-6">
    <a href="?tab=list" class="px-4 py-2 rounded-lg text-sm font-medium <?= $tab==='list'?'bg-blue-600 text-white':'bg-gray-100 text-gray-600' ?>">Income List</a>
    <a href="?tab=add" class="px-4 py-2 rounded-lg text-sm font-medium <?= $tab==='add'?'bg-blue-600 text-white':'bg-gray-100 text-gray-600' ?>">Add Income</a>
</div>

<?php if ($tab === 'add'): ?>
<div class="max-w-xl mx-auto bg-white rounded-xl border shadow-sm p-6">
    <h3 class="font-semibold text-gray-800 mb-4">Record Manual Income</h3>
    <form method="POST" class="space-y-4">
        <input type="hidden" name="action" value="save_income">
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Title *</label><input type="text" name="title" required class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="Income description"></div>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Amount (৳) *</label><input type="number" name="amount" step="0.01" min="0" required class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Date *</label><input type="date" name="income_date" required value="<?= date('Y-m-d') ?>" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Source</label>
                <select name="source" class="w-full border rounded-lg px-3 py-2.5 text-sm">
                    <option value="">Select...</option>
                    <option value="sales">Direct Sales</option>
                    <option value="service">Service Fee</option>
                    <option value="refund_received">Refund Received</option>
                    <option value="investment">Investment</option>
                    <option value="loan">Loan Received</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                <select name="payment_method" class="w-full border rounded-lg px-3 py-2.5 text-sm">
                    <option value="">Select...</option>
                    <?php foreach (['cash'=>'Cash','bank_transfer'=>'Bank Transfer','bkash'=>'bKash','nagad'=>'Nagad','other'=>'Other'] as $k=>$v): ?>
                    <option value="<?= $k ?>"><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Reference</label><input type="text" name="reference" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Notes</label><textarea name="notes" rows="2" class="w-full border rounded-lg px-3 py-2.5 text-sm"></textarea></div>
        <button type="submit" class="w-full bg-green-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-green-700">Save Income</button>
    </form>
</div>

<?php else: ?>
<div class="grid lg:grid-cols-4 gap-6">
    <div class="lg:col-span-3">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="p-4 border-b">
                <form class="flex flex-wrap gap-3 items-center">
                    <input type="hidden" name="from" value="<?= $dateFrom ?>">
                    <input type="hidden" name="to" value="<?= $dateTo ?>">
                    <input type="date" name="from" value="<?= e($dateFrom) ?>" class="border rounded-lg px-2.5 py-1.5 text-sm"> <span class="text-gray-400">—</span> <input type="date" name="to" value="<?= e($dateTo) ?>" class="border rounded-lg px-2.5 py-1.5 text-sm"> <button type="submit" class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium">Apply</button>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Title</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Source</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Amount</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Method</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        <?php foreach ($incomes as $inc): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-500"><?= date('d M Y', strtotime($inc['income_date'])) ?></td>
                            <td class="px-4 py-3 font-medium"><?= e($inc['title']) ?></td>
                            <td class="px-4 py-3"><span class="px-2 py-1 bg-green-50 text-green-700 rounded-full text-xs"><?= ucfirst(str_replace('_',' ',$inc['source'] ?? 'N/A')) ?></span></td>
                            <td class="px-4 py-3 text-right font-semibold text-green-600">+৳<?= number_format($inc['amount']) ?></td>
                            <td class="px-4 py-3 text-gray-500"><?= ucfirst(str_replace('_',' ',$inc['payment_method'] ?? '-')) ?></td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" class="inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete_income"><input type="hidden" name="id" value="<?= $inc['id'] ?>"><button class="text-red-600 text-xs">Delete</button></form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($incomes)): ?><tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No manual income recorded</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (($pagination['total_pages'] ?? 0) > 1): ?><div class="p-4 border-t"><?= renderPagination($pagination) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="space-y-4">
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-3">By Source</h4>
            <?php foreach ($bySource as $bs): ?>
            <div class="flex justify-between py-1.5 text-sm"><span class="text-gray-600"><?= ucfirst(str_replace('_',' ',$bs['source'] ?: 'Unknown')) ?></span><span class="font-medium text-green-600">৳<?= number_format($bs['total']) ?></span></div>
            <?php endforeach; ?>
        </div>
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-3">6-Month Trend</h4>
            <canvas id="trendChart" height="140"></canvas>
        </div>
    </div>
</div>

<script>
const trendData = <?= json_encode($monthlyTrend) ?>;
new Chart(document.getElementById('trendChart'), {
    type: 'bar', data: {
        labels: trendData.map(d => { const dt=new Date(d.month+'-01'); return dt.toLocaleDateString('en',{month:'short'}); }),
        datasets: [{data: trendData.map(d=>d.total), backgroundColor:'rgba(34,197,94,0.6)', borderRadius:4}]
    }, options: {responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
