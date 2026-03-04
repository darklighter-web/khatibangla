<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Accounting';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// --- Date range logic ---
$range = $_GET['range'] ?? 'this_month';
$type  = $_GET['type'] ?? 'all';
$customFrom = $_GET['from'] ?? '';
$customTo   = $_GET['to'] ?? '';

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// If from/to provided directly without range, use them
if (!$range && ($customFrom || $customTo)) {
    $dateFrom = $customFrom ?: date('Y-m-01');
    $dateTo   = $customTo ?: $today;
    $rangeLabel = '';
} else {
switch ($range) {
    case 'today':
        $dateFrom = $today; $dateTo = $today;
        $rangeLabel = 'Today';
        break;
    case 'yesterday':
        $dateFrom = $yesterday; $dateTo = $yesterday;
        $rangeLabel = 'Yesterday';
        break;
    case '7d':
        $dateFrom = date('Y-m-d', strtotime('-6 days')); $dateTo = $today;
        $rangeLabel = 'Last 7 Days';
        break;
    case '30d':
        $dateFrom = date('Y-m-d', strtotime('-29 days')); $dateTo = $today;
        $rangeLabel = 'Last 30 Days';
        break;
    case 'this_month':
        $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-t');
        $rangeLabel = 'This Month';
        break;
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo   = date('Y-m-t', strtotime('last day of last month'));
        $rangeLabel = 'Last Month';
        break;
    case 'this_year':
        $dateFrom = date('Y-01-01'); $dateTo = $today;
        $rangeLabel = 'This Year';
        break;
    case 'last_year':
        $dateFrom = date('Y-01-01', strtotime('-1 year'));
        $dateTo   = date('Y-12-31', strtotime('-1 year'));
        $rangeLabel = 'Last Year';
        break;
    case 'lifetime':
        $dateFrom = '2020-01-01'; $dateTo = $today;
        $rangeLabel = 'Lifetime';
        break;
    case 'single':
        $dateFrom = $customFrom ?: $today;
        $dateTo   = $dateFrom;
        $rangeLabel = date('d M Y', strtotime($dateFrom));
        break;
    case 'custom':
        $dateFrom = $customFrom ?: date('Y-m-01');
        $dateTo   = $customTo ?: $today;
        $rangeLabel = date('d M', strtotime($dateFrom)) . ' – ' . date('d M Y', strtotime($dateTo));
        break;
    default:
        $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-t');
        $rangeLabel = 'This Month';
        $range = 'this_month';
}
} // end else

$where = "ae.entry_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($type !== 'all') {
    $where .= " AND ae.entry_type = ?";
    $params[] = $type;
}

$entries = $db->fetchAll("SELECT ae.* FROM accounting_entries ae WHERE $where ORDER BY ae.entry_date DESC, ae.id DESC", $params);

// Summary for selected range
$income  = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM accounting_entries WHERE entry_type='income' AND entry_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'];
$expense = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM accounting_entries WHERE entry_type='expense' AND entry_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'];
$refund  = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM accounting_entries WHERE entry_type='refund' AND entry_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['total'];
$netProfit = $income - $expense - $refund;

// 6-month trend
$trendData = $db->fetchAll("SELECT DATE_FORMAT(entry_date, '%Y-%m') as month, entry_type, SUM(amount) as total FROM accounting_entries WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY month, entry_type ORDER BY month");
$trendMonths = [];
foreach ($trendData as $td) {
    $trendMonths[$td['month']][$td['entry_type']] = $td['total'];
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $qs = http_build_query(array_filter(['range' => $range, 'type' => $type, 'from' => $customFrom, 'to' => $customTo]));
    if ($action === 'add_entry') {
        $db->insert('accounting_entries', [
            'entry_type' => $_POST['entry_type'],
            'amount' => floatval($_POST['amount']),
            'description' => sanitize($_POST['description']),
            'entry_date' => $_POST['entry_date'],
            'reference_type' => sanitize($_POST['reference_type']) ?: null,
            'reference_id' => intval($_POST['reference_id']) ?: null,
        ]);
        redirect(adminUrl('pages/accounting.php?' . $qs . '&msg=added'));
    }
    if ($action === 'delete') {
        $db->delete('accounting_entries', 'id = ?', [intval($_POST['entry_id'])]);
        redirect(adminUrl('pages/accounting.php?' . $qs . '&msg=deleted'));
    }
}

require_once __DIR__ . '/../includes/header.php';
$msg = $_GET['msg'] ?? '';
?>

<?php if ($msg): ?>
<div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">Entry <?= $msg ?>.</div>
<?php endif; ?>

<!-- Date Picker Bar + Summary -->
<div class="bg-white rounded-xl border shadow-sm p-4 mb-6">
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <div data-kb-datepicker data-from-param="from" data-to-param="to"></div>

        <!-- Type Filter + Print -->
        <div class="ml-auto flex items-center gap-2">
            <select id="typeFilter" onchange="applyTypeFilter(this.value)" class="border rounded-lg px-3 py-2 text-sm">
                <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Types</option>
                <option value="income" <?= $type === 'income' ? 'selected' : '' ?>>Income</option>
                <option value="expense" <?= $type === 'expense' ? 'selected' : '' ?>>Expenses</option>
                <option value="refund" <?= $type === 'refund' ? 'selected' : '' ?>>Refunds</option>
            </select>
            <button onclick="window.print()" class="border rounded-lg px-3 py-2 text-gray-500 hover:bg-gray-50" title="Print">
                <i class="fas fa-print text-sm"></i>
            </button>
        </div>
    </div>

    <!-- Range indicator -->
    <p class="text-xs text-gray-400 mb-3">
        Showing: <span class="font-medium text-gray-600"><?= $rangeLabel ?></span>
        <span class="text-gray-300 mx-1">|</span>
        <?= date('d M Y', strtotime($dateFrom)) ?> — <?= date('d M Y', strtotime($dateTo)) ?>
    </p>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-green-50 rounded-lg p-3">
            <p class="text-[11px] text-green-600 font-medium mb-0.5">Income</p>
            <p class="text-xl font-bold text-green-700">৳<?= number_format($income) ?></p>
        </div>
        <div class="bg-red-50 rounded-lg p-3">
            <p class="text-[11px] text-red-600 font-medium mb-0.5">Expenses</p>
            <p class="text-xl font-bold text-red-700">৳<?= number_format($expense) ?></p>
        </div>
        <div class="bg-orange-50 rounded-lg p-3">
            <p class="text-[11px] text-orange-600 font-medium mb-0.5">Refunds</p>
            <p class="text-xl font-bold text-orange-700">৳<?= number_format($refund) ?></p>
        </div>
        <div class="<?= $netProfit >= 0 ? 'bg-emerald-50' : 'bg-red-50' ?> rounded-lg p-3">
            <p class="text-[11px] <?= $netProfit >= 0 ? 'text-emerald-600' : 'text-red-600' ?> font-medium mb-0.5">Net Profit</p>
            <p class="text-xl font-bold <?= $netProfit >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">৳<?= number_format($netProfit) ?></p>
        </div>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Trend + Manual Entry -->
    <div class="lg:col-span-1 space-y-4">
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-3">6-Month Trend</h3>
            <canvas id="trendChart" height="150"></canvas>
        </div>
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-3">Manual Entry</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="add_entry">
                <div>
                    <label class="block text-sm font-medium mb-1">Type *</label>
                    <select name="entry_type" required class="border rounded-lg px-3 py-2 text-sm w-full">
                        <option value="income">Income</option>
                        <option value="expense">Expense</option>
                        <option value="refund">Refund</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium mb-1">Amount *</label>
                        <input type="number" name="amount" step="0.01" min="0" required class="border rounded-lg px-3 py-2 text-sm w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Date *</label>
                        <input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required class="border rounded-lg px-3 py-2 text-sm w-full">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Description</label>
                    <input type="text" name="description" class="border rounded-lg px-3 py-2 text-sm w-full">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium mb-1">Ref Type</label>
                        <input type="text" name="reference_type" placeholder="order, manual" class="border rounded-lg px-3 py-2 text-sm w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Ref ID</label>
                        <input type="number" name="reference_id" class="border rounded-lg px-3 py-2 text-sm w-full">
                    </div>
                </div>
                <button class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">Add Entry</button>
            </form>
        </div>
    </div>

    <!-- Date-wise Ledger -->
    <?php
    $groupedByDate = [];
    foreach ($entries as $entry) {
        $d = $entry['entry_date'];
        if (!isset($groupedByDate[$d])) {
            $groupedByDate[$d] = ['entries' => [], 'income' => 0, 'expense' => 0, 'refund' => 0, 'count' => 0];
        }
        $groupedByDate[$d]['entries'][] = $entry;
        $groupedByDate[$d][$entry['entry_type']] += $entry['amount'];
        $groupedByDate[$d]['count']++;
    }
    $expandDate = $_GET['date'] ?? '';
    $typeBadge = ['income' => 'bg-green-100 text-green-700', 'expense' => 'bg-red-100 text-red-700', 'refund' => 'bg-orange-100 text-orange-700'];
    ?>
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm">
            <?php if (empty($groupedByDate)): ?>
            <div class="px-4 py-8 text-center text-gray-400">No entries for this period</div>
            <?php else: ?>
            <div class="divide-y">
                <?php foreach ($groupedByDate as $date => $dayData):
                    $dayNet = $dayData['income'] - $dayData['expense'] - $dayData['refund'];
                    $isExpanded = ($expandDate === $date);
                ?>
                <div class="group">
                    <div class="flex items-center gap-3 px-4 py-3.5 cursor-pointer hover:bg-gray-50 transition-colors date-row <?= $isExpanded ? 'bg-blue-50' : '' ?>" data-date="<?= $date ?>">
                        <div class="w-5 text-gray-400 transition-transform duration-200 chevron <?= $isExpanded ? 'rotate-90' : '' ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-gray-800"><?= date('d M Y, l', strtotime($date)) ?></p>
                            <div class="flex items-center gap-3 mt-0.5">
                                <span class="text-xs text-gray-400"><?= $dayData['count'] ?> <?= $dayData['count'] === 1 ? 'entry' : 'entries' ?></span>
                                <?php if ($dayData['income'] > 0): ?><span class="text-xs text-green-600">Income: ৳<?= number_format($dayData['income']) ?></span><?php endif; ?>
                                <?php if ($dayData['expense'] > 0): ?><span class="text-xs text-red-600">Expense: ৳<?= number_format($dayData['expense']) ?></span><?php endif; ?>
                                <?php if ($dayData['refund'] > 0): ?><span class="text-xs text-orange-600">Refund: ৳<?= number_format($dayData['refund']) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-sm <?= $dayNet >= 0 ? 'text-green-600' : 'text-red-600' ?>"><?= $dayNet >= 0 ? '+' : '-' ?>৳<?= number_format(abs($dayNet)) ?></p>
                            <p class="text-[10px] text-gray-400 mt-0.5">Net</p>
                        </div>
                    </div>
                    <div class="date-detail overflow-hidden transition-all duration-300 <?= $isExpanded ? '' : 'hidden' ?>" id="detail-<?= $date ?>">
                        <div class="bg-gray-50/70 border-t border-b border-gray-100">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-[11px] text-gray-500 uppercase tracking-wider">
                                        <th class="text-left px-4 py-2 pl-12 font-medium">Type</th>
                                        <th class="text-left px-4 py-2 font-medium">Description</th>
                                        <th class="text-left px-4 py-2 font-medium">Reference</th>
                                        <th class="text-right px-4 py-2 font-medium">Amount</th>
                                        <th class="text-center px-4 py-2 font-medium w-16">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($dayData['entries'] as $entry): ?>
                                    <tr class="hover:bg-white/80">
                                        <td class="px-4 py-2.5 pl-12"><span class="<?= $typeBadge[$entry['entry_type']] ?> px-2 py-0.5 rounded-full text-xs font-medium"><?= ucfirst($entry['entry_type']) ?></span></td>
                                        <td class="px-4 py-2.5 text-gray-700"><?= e($entry['description'] ?: '-') ?></td>
                                        <td class="px-4 py-2.5 text-gray-400 text-xs">
                                            <?php if ($entry['reference_type']): ?>
                                            <?= e($entry['reference_type']) ?>#<?= $entry['reference_id'] ?>
                                            <?php else: ?>—<?php endif; ?>
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-semibold <?= $entry['entry_type'] === 'income' ? 'text-green-600' : 'text-red-600' ?>">
                                            <?= $entry['entry_type'] === 'income' ? '+' : '-' ?>৳<?= number_format($entry['amount']) ?>
                                        </td>
                                        <td class="px-4 py-2.5 text-center">
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this entry?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="entry_id" value="<?= $entry['id'] ?>">
                                                <button class="text-red-400 hover:text-red-600 text-xs">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-gray-100/80 text-xs font-semibold text-gray-600">
                                        <td colspan="3" class="px-4 py-2 pl-12">Day Total</td>
                                        <td class="px-4 py-2 text-right <?= $dayNet >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= $dayNet >= 0 ? '+' : '-' ?>৳<?= number_format(abs($dayNet)) ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Type filter
function applyTypeFilter(val) {
    const url = new URL(window.location.href);
    url.searchParams.set('type', val);
    window.location = url.toString();
}

// Date-row accordion
document.querySelectorAll('.date-row').forEach(row => {
    row.addEventListener('click', function() {
        const date = this.dataset.date;
        const detail = document.getElementById('detail-' + date);
        const chevron = this.querySelector('.chevron');
        const isOpen = !detail.classList.contains('hidden');
        document.querySelectorAll('.date-detail').forEach(d => d.classList.add('hidden'));
        document.querySelectorAll('.date-row').forEach(r => { r.classList.remove('bg-blue-50'); r.querySelector('.chevron').classList.remove('rotate-90'); });
        if (!isOpen) { detail.classList.remove('hidden'); this.classList.add('bg-blue-50'); chevron.classList.add('rotate-90'); }
    });
});
</script>

<script>
const trendMonths = <?= json_encode(array_keys($trendMonths)) ?>;
const trendIncome = trendMonths.map(m => <?= json_encode(array_map(fn($m) => $m['income'] ?? 0, $trendMonths)) ?>[m] || 0);
const trendExpense = trendMonths.map(m => <?= json_encode(array_map(fn($m) => $m['expense'] ?? 0, $trendMonths)) ?>[m] || 0);

new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: trendMonths.map(m => { const d = new Date(m+'-01'); return d.toLocaleDateString('en',{month:'short',year:'2-digit'}); }),
        datasets: [
            { label: 'Income', data: trendIncome, backgroundColor: '#22c55e' },
            { label: 'Expense', data: trendExpense, backgroundColor: '#ef4444' }
        ]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true } }, plugins: { legend: { position: 'bottom' } } }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
