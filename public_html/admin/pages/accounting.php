<?php
/**
 * Accounting Dashboard — E-commerce Financial Model
 * 
 * Revenue Logic:
 *   Confirmed Revenue = confirmed + ready_to_ship + shipped + delivered (committed sales)
 *   Collected Revenue = delivered only (cash actually received for COD)
 *   Pending Revenue   = confirmed + ready_to_ship + shipped (in pipeline, not yet collected)
 *   Lost Revenue      = cancelled + returned (after confirmation)
 * 
 * COGS: Based on confirmed+ orders using FIFO batch costs or product cost_price
 * Expenses: From expenses table + ad_expenses table
 * Net Profit: Collected Revenue - COGS(delivered) - Expenses - Ad Spend - Refunds + Other Income
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Accounting';
require_once __DIR__ . '/../includes/auth.php';
requirePermission('accounting');
$db = Database::getInstance();

// ── Date Range ──
$range = $_GET['range'] ?? '';
$customFrom = $_GET['from'] ?? '';
$customTo   = $_GET['to'] ?? '';
$today = date('Y-m-d');
if (!$range && ($customFrom || $customTo)) $range = 'custom';
if (!$range) $range = 'this_month';

switch ($range) {
    case 'today':      $dateFrom = $today; $dateTo = $today; $rangeLabel = 'Today'; break;
    case 'yesterday':  $dateFrom = date('Y-m-d', strtotime('-1 day')); $dateTo = $dateFrom; $rangeLabel = 'Yesterday'; break;
    case '7d':         $dateFrom = date('Y-m-d', strtotime('-6 days')); $dateTo = $today; $rangeLabel = 'Last 7 Days'; break;
    case '30d':        $dateFrom = date('Y-m-d', strtotime('-29 days')); $dateTo = $today; $rangeLabel = 'Last 30 Days'; break;
    case 'this_month': $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-t'); $rangeLabel = 'This Month'; break;
    case 'last_month': $dateFrom = date('Y-m-01', strtotime('first day of last month')); $dateTo = date('Y-m-t', strtotime('last day of last month')); $rangeLabel = 'Last Month'; break;
    case 'this_year':  $dateFrom = date('Y-01-01'); $dateTo = $today; $rangeLabel = date('Y'); break;
    case 'lifetime':   $dateFrom = '2020-01-01'; $dateTo = $today; $rangeLabel = 'All Time'; break;
    case 'custom':     $dateFrom = $customFrom ?: date('Y-m-01'); $dateTo = $customTo ?: $today; $rangeLabel = date('d M', strtotime($dateFrom)).' – '.date('d M Y', strtotime($dateTo)); break;
    default:           $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-t'); $rangeLabel = 'This Month'; $range = 'this_month';
}

// ── Fixed Expenses System ──
// Ensure fixed_expenses table exists
try { $db->query("SELECT 1 FROM fixed_expenses LIMIT 1"); } catch (\Throwable $e) {
    $db->query("CREATE TABLE IF NOT EXISTS fixed_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        category_id INT DEFAULT NULL,
        expense_type ENUM('fixed','salary') DEFAULT 'fixed',
        employee_name VARCHAR(200) DEFAULT NULL,
        employee_role VARCHAR(100) DEFAULT NULL,
        frequency ENUM('monthly','quarterly','yearly') DEFAULT 'monthly',
        start_date DATE NOT NULL,
        end_date DATE DEFAULT NULL,
        day_of_month TINYINT DEFAULT 28,
        is_active TINYINT(1) DEFAULT 1,
        notes TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_active (is_active),
        KEY idx_type (expense_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// Migrate existing tables to add salary columns
try { $db->query("ALTER TABLE fixed_expenses ADD COLUMN expense_type ENUM('fixed','salary') DEFAULT 'fixed' AFTER category_id"); } catch (\Throwable $e) {}
try { $db->query("ALTER TABLE fixed_expenses ADD COLUMN employee_name VARCHAR(200) DEFAULT NULL AFTER expense_type"); } catch (\Throwable $e) {}
try { $db->query("ALTER TABLE fixed_expenses ADD COLUMN employee_role VARCHAR(100) DEFAULT NULL AFTER employee_name"); } catch (\Throwable $e) {}

// ── Auto-generate fixed expenses for completed months ──
try {
    $activeFixed = $db->fetchAll("SELECT * FROM fixed_expenses WHERE is_active = 1");
    foreach ($activeFixed as $fe) {
        $startDate = $fe['start_date'];
        $endDate = $fe['end_date'] ?: date('Y-m-d');
        $freq = $fe['frequency'];
        $isSalary = ($fe['expense_type'] ?? 'fixed') === 'salary';

        $current = new DateTime($startDate);
        $now = new DateTime();
        $interval = $freq === 'yearly' ? 'P1Y' : ($freq === 'quarterly' ? 'P3M' : 'P1M');
        
        while ($current <= $now) {
            $genDate = $current->format('Y-m') . '-' . str_pad(min($fe['day_of_month'], intval($current->format('t'))), 2, '0', STR_PAD_LEFT);
            if ($genDate <= date('Y-m-d') && $genDate >= $fe['start_date'] && (!$fe['end_date'] || $genDate <= $fe['end_date'])) {
                $exists = $db->fetch("SELECT id FROM expenses WHERE reference = ? AND expense_date = ?", ['fixed:'.$fe['id'], $genDate]);

                // Calculate amount (subtract active deductions for salary)
                $amount = floatval($fe['amount']);
                if ($isSalary && !empty($fe['admin_user_id'])) {
                    $genMonth = $current->format('Y-m');
                    try {
                        $dedTotal = $db->fetch("SELECT COALESCE(SUM(deduction_amount),0) as t FROM salary_deductions WHERE admin_user_id=? AND month=? AND is_compromised=0", [intval($fe['admin_user_id']), $genMonth]);
                        $amount = max(0, $amount - floatval($dedTotal['t'] ?? 0));
                    } catch (\Throwable $e2) {}
                }

                if (!$exists) {
                    $db->query("INSERT INTO expenses (category_id, title, amount, expense_date, payment_method, reference, notes, created_by) VALUES (?,?,?,?,'auto',?,?,?)", [
                        $fe['category_id'] ?? null,
                        $fe['title'],
                        $amount,
                        $genDate,
                        'fixed:'.$fe['id'],
                        'Auto-generated' . ($isSalary ? ' salary' : ' fixed expense') . ($fe['notes'] ? ': '.$fe['notes'] : ''),
                        $fe['created_by']
                    ]);
                } elseif ($isSalary) {
                    // Update existing salary expense if deductions changed
                    $db->query("UPDATE expenses SET amount=? WHERE id=?", [$amount, $exists['id']]);
                }
            }
            $current->add(new DateInterval($interval));
        }
    }
} catch (\Throwable $e) {}

// ── Handle Fixed Expense POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $qs = http_build_query(array_filter(['range' => $range, 'from' => $customFrom, 'to' => $customTo]));

    if ($action === 'add_fixed') {
        $db->query("INSERT INTO fixed_expenses (title, amount, category_id, expense_type, frequency, start_date, end_date, day_of_month, notes, created_by) VALUES (?,?,?,'fixed',?,?,?,?,?,?)", [
            sanitize($_POST['fe_title']),
            floatval($_POST['fe_amount']),
            intval($_POST['fe_category'] ?? 0) ?: null,
            in_array($_POST['fe_frequency'] ?? '', ['monthly','quarterly','yearly']) ? $_POST['fe_frequency'] : 'monthly',
            $_POST['fe_start_date'] ?: date('Y-m-01'),
            $_POST['fe_end_date'] ?: null,
            min(28, max(1, intval($_POST['fe_day'] ?? 28))),
            sanitize($_POST['fe_notes'] ?? ''),
            getAdminId()
        ]);
        redirect(adminUrl('pages/accounting.php?' . $qs . '&msg=Fixed expense added'));
    }
    if ($action === 'toggle_fixed') {
        $fid = intval($_POST['fe_id']);
        $db->query("UPDATE fixed_expenses SET is_active = NOT is_active WHERE id = ?", [$fid]);
        redirect(adminUrl('pages/accounting.php?' . $qs . '&msg=Fixed expense updated'));
    }
    if ($action === 'delete_fixed') {
        $fid = intval($_POST['fe_id']);
        $db->query("DELETE FROM fixed_expenses WHERE id = ?", [$fid]);
        redirect(adminUrl('pages/accounting.php?' . $qs . '&msg=Fixed expense removed'));
    }
    if ($action === 'delete') {
        $db->delete('accounting_entries', 'id = ?', [intval($_POST['entry_id'])]);
        redirect(adminUrl('pages/accounting.php?' . $qs . '&msg=Entry deleted'));
    }
}

// Load fixed expenses for display
$fixedExpenses = [];
try { $fixedExpenses = $db->fetchAll("SELECT fe.*, ec.name as category_name FROM fixed_expenses fe LEFT JOIN expense_categories ec ON ec.id = fe.category_id ORDER BY fe.expense_type, fe.is_active DESC, fe.title"); } catch (\Throwable $e) {}
$fixedMonthlyTotal = 0;
$salaryMonthlyTotal = 0;
$fixedOnly = [];
$salaryOnly = [];
foreach ($fixedExpenses as $fe) {
    $amt = floatval($fe['amount']);
    $monthly = $fe['frequency'] === 'yearly' ? $amt / 12 : ($fe['frequency'] === 'quarterly' ? $amt / 3 : $amt);
    if (($fe['expense_type'] ?? 'fixed') === 'salary') {
        $salaryOnly[] = $fe;
        if ($fe['is_active']) $salaryMonthlyTotal += $monthly;
    } else {
        $fixedOnly[] = $fe;
        if ($fe['is_active']) $fixedMonthlyTotal += $monthly;
    }
}

// Load expense categories for the fixed expense form
$expCategories = [];
try { $expCategories = $db->fetchAll("SELECT id, name FROM expense_categories ORDER BY name"); } catch (\Throwable $e) {}

// Date expression: use created_at as the order date for all revenue queries
$dateExpr = "DATE(o.created_at)";

// ── Statuses that count as "confirmed" (committed sales) ──
$confirmedStatuses = "'confirmed','ready_to_ship','shipped','delivered'";
$collectedStatuses = "'delivered'";
$pipelineStatuses  = "'confirmed','ready_to_ship','shipped'";
$lostStatuses      = "'cancelled','returned'";

// ── Confirmed Revenue (committed sales = confirmed + RTS + shipped + delivered) ──
$confirmedRevRow = $db->fetch("SELECT COALESCE(SUM(o.total),0) as revenue, COUNT(*) as count FROM orders o WHERE o.order_status IN ({$confirmedStatuses}) AND {$dateExpr} BETWEEN ? AND ?", [$dateFrom, $dateTo]);
$confirmedRevenue = floatval($confirmedRevRow['revenue']);
$confirmedCount = intval($confirmedRevRow['count']);

// ── Collected Revenue (delivered only = actual cash received) ──
$collectedRevRow = $db->fetch("SELECT COALESCE(SUM(o.total),0) as revenue, COUNT(*) as count FROM orders o WHERE o.order_status IN ({$collectedStatuses}) AND {$dateExpr} BETWEEN ? AND ?", [$dateFrom, $dateTo]);
$collectedRevenue = floatval($collectedRevRow['revenue']);
$deliveredCount = intval($collectedRevRow['count']);

// ── Pipeline (confirmed but not yet delivered) ──
$pipelineRevRow = $db->fetch("SELECT COALESCE(SUM(o.total),0) as revenue, COUNT(*) as count FROM orders o WHERE o.order_status IN ({$pipelineStatuses}) AND {$dateExpr} BETWEEN ? AND ?", [$dateFrom, $dateTo]);
$pipelineRevenue = floatval($pipelineRevRow['revenue']);
$pipelineCount = intval($pipelineRevRow['count']);

// ── Lost Revenue (cancelled + returned after being in the system) ──
$lostRevRow = $db->fetch("SELECT COALESCE(SUM(o.total),0) as revenue, COUNT(*) as count FROM orders o WHERE o.order_status IN ({$lostStatuses}) AND {$dateExpr} BETWEEN ? AND ?", [$dateFrom, $dateTo]);
$lostRevenue = floatval($lostRevRow['revenue']);
$lostCount = intval($lostRevRow['count']);

// ── Total orders in period ──
$totalOrdersRow = $db->fetch("SELECT COUNT(*) as count, COALESCE(SUM(total),0) as total FROM orders o WHERE {$dateExpr} BETWEEN ? AND ?", [$dateFrom, $dateTo]);

// ── COGS on confirmed+ orders (FIFO first, fallback to cost_price) ──
$cogs = 0;
try {
    $cogsRow = $db->fetch("SELECT COALESCE(SUM(total_cost),0) as cogs FROM stock_consumption WHERE DATE(consumed_at) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
    $cogs = floatval($cogsRow['cogs']);
} catch (\Throwable $e) {}
if ($cogs == 0 && $confirmedRevenue > 0) {
    try {
        $cogsEst = $db->fetch("SELECT COALESCE(SUM(oi.quantity * COALESCE(p.cost_price,0)),0) as cogs FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN products p ON p.id=oi.product_id WHERE o.order_status IN ({$confirmedStatuses}) AND {$dateExpr} BETWEEN ? AND ?", [$dateFrom, $dateTo]);
        $cogs = floatval($cogsEst['cogs']);
    } catch (\Throwable $e) {}
}

// ── Expenses ──
$expenseTotal = floatval($db->fetch("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE expense_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['t']);
$expenseBreakdown = $db->fetchAll("SELECT COALESCE(ec.name,'Uncategorized') as category, COALESCE(SUM(e.amount),0) as total FROM expenses e LEFT JOIN expense_categories ec ON ec.id=e.category_id WHERE e.expense_date BETWEEN ? AND ? GROUP BY e.category_id ORDER BY total DESC", [$dateFrom, $dateTo]);

// ── Ad Expenses ──
$adExpense = 0;
try { $adExpense = floatval($db->fetch("SELECT COALESCE(SUM(amount_bdt),0) as t FROM ad_expenses WHERE expense_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['t']); } catch (\Throwable $e) {}
// Fallback to 'amount' column if amount_bdt doesn't exist
if ($adExpense == 0) { try { $adExpense = floatval($db->fetch("SELECT COALESCE(SUM(amount),0) as t FROM ad_expenses WHERE expense_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['t']); } catch (\Throwable $e) {} }

// ── Manual Income ──
$manualIncome = 0;
try { $manualIncome = floatval($db->fetch("SELECT COALESCE(SUM(amount),0) as t FROM income WHERE income_date BETWEEN ? AND ?", [$dateFrom, $dateTo])['t']); } catch (\Throwable $e) {}

// ── Refunds (returned orders) ──
$refundTotal = floatval($db->fetch("SELECT COALESCE(SUM(o.total),0) as t FROM orders o WHERE o.order_status = 'returned' AND {$dateExpr} BETWEEN ? AND ?", [$dateFrom, $dateTo])['t']);

// ── Shipping costs collected from customers ──
$shippingCollected = 0;
try { $shippingCollected = floatval($db->fetch("SELECT COALESCE(SUM(o.shipping_cost),0) as t FROM orders o WHERE o.order_status IN ({$confirmedStatuses}) AND {$dateExpr} BETWEEN ? AND ?", [$dateFrom, $dateTo])['t']); } catch (\Throwable $e) {}

// ── Liabilities (all-time, not filtered by date) ──
$liabilitySummary = ['total' => 0, 'paid' => 0, 'pending' => 0];
try {
    $ls = $db->fetch("SELECT COALESCE(SUM(amount),0) as total, COALESCE(SUM(paid_amount),0) as paid FROM liabilities WHERE status != 'cancelled'");
    $liabilitySummary = ['total' => floatval($ls['total']), 'paid' => floatval($ls['paid']), 'pending' => floatval($ls['total']) - floatval($ls['paid'])];
} catch (\Throwable $e) {}

// ── Computed Metrics ──
$totalExpenses = $expenseTotal + $adExpense;
$grossProfit = $confirmedRevenue - $cogs;
$grossMargin = $confirmedRevenue > 0 ? round(($grossProfit / $confirmedRevenue) * 100, 1) : 0;
$netProfit = $collectedRevenue + $manualIncome - $cogs - $totalExpenses - $refundTotal;
$netMargin = ($collectedRevenue + $manualIncome) > 0 ? round(($netProfit / ($collectedRevenue + $manualIncome)) * 100, 1) : 0;
$successRate = $confirmedCount > 0 ? round(($deliveredCount / ($confirmedCount + $lostCount)) * 100, 1) : 0;

// ── Previous period comparison ──
$daysDiff = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400 + 1);
$prevFrom = date('Y-m-d', strtotime($dateFrom . " - {$daysDiff} days"));
$prevTo = date('Y-m-d', strtotime($dateFrom . " - 1 day"));
$prevConfirmed = floatval($db->fetch("SELECT COALESCE(SUM(total),0) as t FROM orders o WHERE o.order_status IN ({$confirmedStatuses}) AND {$dateExpr} BETWEEN ? AND ?", [$prevFrom, $prevTo])['t']);
$prevExpense = floatval($db->fetch("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE expense_date BETWEEN ? AND ?", [$prevFrom, $prevTo])['t']);
$revenueChange = $prevConfirmed > 0 ? round((($confirmedRevenue - $prevConfirmed) / $prevConfirmed) * 100, 1) : 0;
$expenseChange = $prevExpense > 0 ? round((($totalExpenses - $prevExpense) / $prevExpense) * 100, 1) : 0;

// ── Monthly trend (6 months) — confirmed revenue ──
$trendMonths = []; $trendRevArr = []; $trendExpArr = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-{$i} months"));
    $trendMonths[] = date('M', strtotime($m . '-01'));
    $trendRevArr[$m] = 0; $trendExpArr[$m] = 0;
}
$trendData = $db->fetchAll("SELECT DATE_FORMAT(o.created_at, '%Y-%m') as month, SUM(o.total) as revenue FROM orders o WHERE o.order_status IN ({$confirmedStatuses}) AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY month ORDER BY month");
$trendExpData = $db->fetchAll("SELECT DATE_FORMAT(expense_date, '%Y-%m') as month, SUM(amount) as expense FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY month ORDER BY month");
foreach ($trendData as $t) { if (isset($trendRevArr[$t['month']])) $trendRevArr[$t['month']] = floatval($t['revenue']); }
foreach ($trendExpData as $t) { if (isset($trendExpArr[$t['month']])) $trendExpArr[$t['month']] = floatval($t['expense']); }

// ── Recent transactions (confirmed+ orders, expenses, refunds) ──
$recentTxns = [];
try {
    $recentTxns = $db->fetchAll("(SELECT 'order' as source, order_number as ref, total as amount, 'income' as type, order_status as detail, created_at as txn_date FROM orders WHERE order_status IN ({$confirmedStatuses}) ORDER BY created_at DESC LIMIT 8)
    UNION ALL (SELECT 'expense' as source, title as ref, amount, 'expense' as type, '' as detail, expense_date as txn_date FROM expenses ORDER BY expense_date DESC LIMIT 5)
    UNION ALL (SELECT 'refund' as source, order_number as ref, total as amount, 'refund' as type, 'returned' as detail, updated_at as txn_date FROM orders WHERE order_status='returned' ORDER BY updated_at DESC LIMIT 3)
    ORDER BY txn_date DESC LIMIT 15");
} catch (\Throwable $e) {}


require_once __DIR__ . '/../includes/header.php';
$msg = $_GET['msg'] ?? '';
?>

<?php if ($msg): ?>
<div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">✓ <?= e($msg) ?></div>
<?php endif; ?>

<!-- Date Range Bar -->
<div class="bg-white rounded-xl border shadow-sm p-4 mb-5">
    <div class="flex flex-wrap items-center gap-2">
        <?php
        $ranges = ['today'=>'Today','yesterday'=>'Yesterday','7d'=>'7D','30d'=>'30D','this_month'=>'This Month','last_month'=>'Last Month','this_year'=>date('Y'),'lifetime'=>'All Time'];
        foreach ($ranges as $rk => $rl):
        ?>
        <a href="?range=<?= $rk ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium transition <?= $range === $rk ? 'bg-blue-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= $rl ?></a>
        <?php endforeach; ?>
        <div class="ml-auto flex items-center gap-1.5">
            <input type="date" id="acctFrom" value="<?= $range === 'custom' ? e($dateFrom) : '' ?>" class="border rounded-lg px-2.5 py-1.5 text-xs text-gray-600 w-[130px]">
            <span class="text-gray-400 text-xs">—</span>
            <input type="date" id="acctTo" value="<?= $range === 'custom' ? e($dateTo) : '' ?>" class="border rounded-lg px-2.5 py-1.5 text-xs text-gray-600 w-[130px]">
            <button onclick="applyCustomRange()" class="px-3 py-1.5 rounded-lg text-xs font-medium transition <?= $range === 'custom' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">Apply</button>
        </div>
    </div>
    <p class="text-[11px] text-gray-400 mt-2"><?= $rangeLabel ?> · <?= date('d M Y', strtotime($dateFrom)) ?> — <?= date('d M Y', strtotime($dateTo)) ?> · Revenue based on confirmed orders (order creation date)</p>
</div>
<script>
function applyCustomRange() {
    var f = document.getElementById('acctFrom').value, t = document.getElementById('acctTo').value;
    if (!f && !t) return; if (!f) f = t; if (!t) t = f;
    window.location.href = '?range=custom&from=' + f + '&to=' + t;
}
</script>

<!-- KPI Cards — 2 rows -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase tracking-wider mb-1">Confirmed Revenue</p>
        <p class="text-xl font-bold text-blue-700">৳<?= number_format($confirmedRevenue) ?></p>
        <p class="text-[10px] text-gray-400 mt-1"><?= $confirmedCount ?> orders <?php if ($revenueChange != 0): ?><span class="<?= $revenueChange >= 0 ? 'text-green-600' : 'text-red-500' ?>"><?= $revenueChange >= 0 ? '↑' : '↓' ?><?= abs($revenueChange) ?>%</span><?php endif; ?></p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase tracking-wider mb-1">Collected (Delivered)</p>
        <p class="text-xl font-bold text-green-600">৳<?= number_format($collectedRevenue) ?></p>
        <p class="text-[10px] text-gray-400 mt-1"><?= $deliveredCount ?> delivered</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase tracking-wider mb-1">In Pipeline</p>
        <p class="text-xl font-bold text-amber-600">৳<?= number_format($pipelineRevenue) ?></p>
        <p class="text-[10px] text-gray-400 mt-1"><?= $pipelineCount ?> in transit</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase tracking-wider mb-1">Lost (Cancel/Return)</p>
        <p class="text-xl font-bold text-red-500">৳<?= number_format($lostRevenue) ?></p>
        <p class="text-[10px] text-gray-400 mt-1"><?= $lostCount ?> orders · <?= $successRate ?>% success</p>
    </div>
</div>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase tracking-wider mb-1">COGS</p>
        <p class="text-xl font-bold text-orange-600">৳<?= number_format($cogs) ?></p>
        <p class="text-[10px] text-gray-400 mt-1">Product cost</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase tracking-wider mb-1">Gross Profit</p>
        <p class="text-xl font-bold <?= $grossProfit >= 0 ? 'text-green-600' : 'text-red-600' ?>">৳<?= number_format($grossProfit) ?></p>
        <p class="text-[10px] <?= $grossMargin >= 30 ? 'text-green-600' : 'text-amber-600' ?> mt-1"><?= $grossMargin ?>% margin</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-[10px] text-gray-400 uppercase tracking-wider mb-1">Total Expenses</p>
        <p class="text-xl font-bold text-red-600">৳<?= number_format($totalExpenses) ?></p>
        <?php if ($expenseChange != 0): ?><p class="text-[10px] mt-1 <?= $expenseChange <= 0 ? 'text-green-600' : 'text-red-500' ?>"><?= $expenseChange >= 0 ? '↑' : '↓' ?><?= abs($expenseChange) ?>%</p><?php endif; ?>
    </div>
    <div class="bg-<?= $netProfit >= 0 ? 'emerald' : 'red' ?>-50 rounded-xl border border-<?= $netProfit >= 0 ? 'emerald' : 'red' ?>-200 p-4">
        <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Net Profit</p>
        <p class="text-xl font-bold <?= $netProfit >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">৳<?= number_format($netProfit) ?></p>
        <p class="text-[10px] <?= $netMargin >= 0 ? 'text-emerald-600' : 'text-red-500' ?> mt-1"><?= $netMargin ?>% net margin</p>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-5">
    <!-- LEFT COLUMN -->
    <div class="lg:col-span-1 space-y-5">
        <!-- Trend Chart -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-800 mb-3">Revenue vs Expenses (6M)</h3>
            <canvas id="trendChart" height="160"></canvas>
        </div>

        <!-- Expense Breakdown -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-800 mb-3">Expense Breakdown</h3>
            <?php if (empty($expenseBreakdown) && $adExpense == 0): ?>
            <p class="text-xs text-gray-400 py-4 text-center">No expenses this period</p>
            <?php else: ?>
            <div class="space-y-2.5">
                <?php $allExp = $totalExpenses;
                foreach ($expenseBreakdown as $eb):
                    $pct = $allExp > 0 ? round(($eb['total'] / $allExp) * 100) : 0; ?>
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="font-medium text-gray-700"><?= e($eb['category']) ?></span>
                        <span class="text-gray-500">৳<?= number_format($eb['total']) ?> (<?= $pct ?>%)</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5"><div class="bg-red-400 h-1.5 rounded-full" style="width:<?= max(2,$pct) ?>%"></div></div>
                </div>
                <?php endforeach;
                if ($adExpense > 0): $pct = $allExp > 0 ? round(($adExpense / $allExp) * 100) : 0; ?>
                <div>
                    <div class="flex justify-between text-xs mb-1"><span class="font-medium text-gray-700">Ad Spend</span><span class="text-gray-500">৳<?= number_format($adExpense) ?> (<?= $pct ?>%)</span></div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5"><div class="bg-blue-400 h-1.5 rounded-full" style="width:<?= max(2,$pct) ?>%"></div></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- P&L Summary -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-800 mb-3">Profit & Loss Statement</h3>
            <div class="space-y-1 text-xs">
                <div class="flex justify-between py-1.5 border-b border-dashed border-gray-200"><span class="text-gray-600">Confirmed Order Revenue</span><span class="font-semibold text-blue-600">৳<?= number_format($confirmedRevenue) ?></span></div>
                <div class="flex justify-between py-1.5 border-b border-dashed border-gray-200 pl-3"><span class="text-gray-400">└ Collected (Delivered)</span><span class="font-medium text-green-600">৳<?= number_format($collectedRevenue) ?></span></div>
                <div class="flex justify-between py-1.5 border-b border-dashed border-gray-200 pl-3"><span class="text-gray-400">└ In Pipeline</span><span class="font-medium text-amber-600">৳<?= number_format($pipelineRevenue) ?></span></div>
                <div class="flex justify-between py-1.5 border-b border-dashed border-gray-200"><span class="text-gray-600">Other Income</span><span class="font-semibold text-green-600">+৳<?= number_format($manualIncome) ?></span></div>
                <div class="flex justify-between py-1.5 border-b border-dashed border-gray-200"><span class="text-gray-600">COGS (Product Cost)</span><span class="font-semibold text-red-500">-৳<?= number_format($cogs) ?></span></div>
                <div class="flex justify-between py-1.5 bg-gray-50 px-2 rounded font-semibold"><span class="text-gray-700">Gross Profit</span><span class="<?= $grossProfit >= 0 ? 'text-green-600' : 'text-red-600' ?>">৳<?= number_format($grossProfit) ?> <span class="font-normal text-gray-400">(<?= $grossMargin ?>%)</span></span></div>
                <div class="flex justify-between py-1.5 border-b border-dashed border-gray-200"><span class="text-gray-600">Operating Expenses</span><span class="font-semibold text-red-500">-৳<?= number_format($expenseTotal) ?></span></div>
                <div class="flex justify-between py-1.5 border-b border-dashed border-gray-200"><span class="text-gray-600">Ad Spend</span><span class="font-semibold text-red-500">-৳<?= number_format($adExpense) ?></span></div>
                <div class="flex justify-between py-1.5 border-b border-dashed border-gray-200"><span class="text-gray-600">Refunds (Returns)</span><span class="font-semibold text-red-500">-৳<?= number_format($refundTotal) ?></span></div>
                <div class="flex justify-between py-1.5 border-b border-dashed border-gray-200"><span class="text-gray-600">Lost Revenue (Cancelled)</span><span class="font-semibold text-gray-400">৳<?= number_format($lostRevenue - $refundTotal) ?></span></div>
                <div class="flex justify-between py-2.5 bg-<?= $netProfit >= 0 ? 'emerald' : 'red' ?>-50 px-3 rounded-lg mt-2">
                    <span class="font-bold text-gray-800">Net Profit</span>
                    <span class="font-bold text-base <?= $netProfit >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">৳<?= number_format($netProfit) ?></span>
                </div>
            </div>
            <p class="text-[10px] text-gray-400 mt-3">* Net Profit = Collected Revenue + Other Income - COGS - Expenses - Ad Spend - Refunds</p>
        </div>

        <!-- Liabilities -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-800">Liabilities</h3>
                <a href="<?= adminUrl('pages/liabilities.php') ?>" class="text-[10px] text-blue-600 hover:underline">View All →</a>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div class="text-center p-2 bg-red-50 rounded-lg"><p class="text-lg font-bold text-red-600">৳<?= number_format($liabilitySummary['pending']) ?></p><p class="text-[9px] text-gray-500">Outstanding</p></div>
                <div class="text-center p-2 bg-green-50 rounded-lg"><p class="text-lg font-bold text-green-600">৳<?= number_format($liabilitySummary['paid']) ?></p><p class="text-[9px] text-gray-500">Paid</p></div>
                <div class="text-center p-2 bg-gray-50 rounded-lg"><p class="text-lg font-bold text-gray-600">৳<?= number_format($liabilitySummary['total']) ?></p><p class="text-[9px] text-gray-500">Total</p></div>
            </div>
        </div>

        <!-- Employee Salaries (managed from HRM → Salary tab) -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-800">👤 Employee Salaries</h3>
                <div class="flex items-center gap-2">
                    <span class="text-[10px] bg-purple-50 text-purple-600 px-2 py-0.5 rounded-full font-medium">৳<?= number_format($salaryMonthlyTotal) ?>/mo</span>
                    <a href="<?= adminUrl('pages/employees.php?tab=salary') ?>" class="text-[10px] text-blue-600 hover:underline">Manage →</a>
                </div>
            </div>
            <?php if (!empty($salaryOnly)): ?>
            <div class="space-y-2 max-h-[180px] overflow-y-auto">
                <?php foreach ($salaryOnly as $fe): ?>
                <div class="flex items-center gap-2 py-1.5 <?= $fe['is_active'] ? '' : 'opacity-40' ?> border-b border-gray-50 last:border-0">
                    <div class="w-7 h-7 bg-purple-100 rounded-full flex items-center justify-center text-[10px] flex-shrink-0">👤</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-medium text-gray-800 truncate"><?= e($fe['employee_name'] ?? $fe['title']) ?></p>
                        <p class="text-[10px] text-gray-400"><?= e($fe['employee_role'] ?? '') ?> · Day <?= $fe['day_of_month'] ?></p>
                    </div>
                    <span class="text-xs font-bold text-purple-600 whitespace-nowrap">৳<?= number_format($fe['amount']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-xs text-gray-400 text-center py-3">No salaries configured. <a href="<?= adminUrl('pages/employees.php?tab=salary') ?>" class="text-blue-600 hover:underline">Add from HRM →</a></p>
            <?php endif; ?>
        </div>

        <!-- Fixed/Recurring Expenses -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-800">🔄 Fixed Expenses</h3>
                <span class="text-[10px] bg-red-50 text-red-600 px-2 py-0.5 rounded-full font-medium">৳<?= number_format($fixedMonthlyTotal) ?>/mo</span>
            </div>
            <?php if (!empty($fixedOnly)): ?>
            <div class="space-y-2 mb-3 max-h-[180px] overflow-y-auto">
                <?php foreach ($fixedOnly as $fe): ?>
                <div class="flex items-center gap-2 py-1.5 <?= $fe['is_active'] ? '' : 'opacity-40' ?> border-b border-gray-50 last:border-0">
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-medium text-gray-800 truncate"><?= e($fe['title']) ?></p>
                        <p class="text-[10px] text-gray-400"><?= e($fe['category_name'] ?? '') ?> · <?= ucfirst($fe['frequency']) ?> · Day <?= $fe['day_of_month'] ?><?= $fe['end_date'] ? ' · Until '.date('M Y', strtotime($fe['end_date'])) : '' ?></p>
                    </div>
                    <span class="text-xs font-bold text-red-600 whitespace-nowrap">৳<?= number_format($fe['amount']) ?></span>
                    <div class="flex gap-1">
                        <form method="POST" class="inline"><input type="hidden" name="action" value="toggle_fixed"><input type="hidden" name="fe_id" value="<?= $fe['id'] ?>">
                            <button class="text-[10px] px-1.5 py-0.5 rounded <?= $fe['is_active'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500' ?>"><?= $fe['is_active'] ? '✓' : '⏸' ?></button>
                        </form>
                        <form method="POST" class="inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete_fixed"><input type="hidden" name="fe_id" value="<?= $fe['id'] ?>">
                            <button class="text-[10px] px-1.5 py-0.5 rounded bg-red-50 text-red-400">×</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <details class="group">
                <summary class="cursor-pointer text-xs text-blue-600 hover:text-blue-800 font-medium flex items-center gap-1">
                    <span class="group-open:rotate-90 transition-transform text-[10px]">▶</span> Add Fixed Expense
                </summary>
                <form method="POST" class="mt-3 space-y-2.5 bg-gray-50 rounded-lg p-3">
                    <input type="hidden" name="action" value="add_fixed">
                    <input type="text" name="fe_title" required placeholder="Title (e.g., Office Rent, Internet)" class="w-full border rounded-lg px-2.5 py-2 text-xs">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="fe_amount" step="0.01" min="0" required placeholder="Amount (৳)" class="border rounded-lg px-2.5 py-2 text-xs">
                        <select name="fe_category" class="border rounded-lg px-2.5 py-2 text-xs">
                            <option value="">Category</option>
                            <?php foreach ($expCategories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <select name="fe_frequency" class="border rounded-lg px-2.5 py-2 text-xs">
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                        <input type="number" name="fe_day" min="1" max="28" value="28" class="border rounded-lg px-2.5 py-2 text-xs" title="Day (1-28)">
                        <input type="date" name="fe_start_date" value="<?= date('Y-m-01') ?>" required class="border rounded-lg px-2.5 py-2 text-xs">
                    </div>
                    <input type="date" name="fe_end_date" class="w-full border rounded-lg px-2.5 py-2 text-xs" title="Leave empty for ongoing">
                    <input type="text" name="fe_notes" placeholder="Notes" class="w-full border rounded-lg px-2.5 py-2 text-xs">
                    <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-lg text-xs font-medium hover:bg-blue-700">Add Fixed Expense</button>
                </form>
            </details>
            <p class="text-[9px] text-gray-400 mt-2">Salaries & fixed expenses auto-generate in Expenses on their scheduled day.</p>
        </div>
    </div>

    <!-- RIGHT COLUMN -->
    <div class="lg:col-span-2 space-y-5">
        <!-- Recent Transactions -->
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="px-5 py-3 border-b flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-800">Recent Transactions</h3>
                <div class="flex gap-3">
                    <a href="<?= adminUrl('pages/income.php') ?>" class="text-xs text-blue-600 hover:underline">Income</a>
                    <a href="<?= adminUrl('pages/expenses.php') ?>" class="text-xs text-blue-600 hover:underline">Expenses</a>
                    <a href="<?= adminUrl('pages/liabilities.php') ?>" class="text-xs text-blue-600 hover:underline">Liabilities</a>
                </div>
            </div>
            <div class="divide-y">
                <?php foreach ($recentTxns as $tx):
                    $isInc = $tx['type'] === 'income'; $isRef = $tx['type'] === 'refund';
                    $statusLabel = '';
                    if ($isInc && !empty($tx['detail'])) {
                        $sl = ['confirmed'=>'Confirmed','ready_to_ship'=>'RTS','shipped'=>'Shipped','delivered'=>'Delivered'];
                        $statusLabel = $sl[$tx['detail']] ?? '';
                    }
                ?>
                <div class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 transition">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm flex-shrink-0 <?= $isInc ? 'bg-green-100 text-green-600' : ($isRef ? 'bg-orange-100 text-orange-600' : 'bg-red-100 text-red-600') ?>"><?= $isInc ? '↑' : ($isRef ? '↩' : '↓') ?></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-800 truncate"><?= e($tx['ref']) ?></p>
                        <p class="text-[10px] text-gray-400"><?= ucfirst($tx['source']) ?><?= $statusLabel ? ' · '.$statusLabel : '' ?> · <?= $tx['txn_date'] ? date('d M Y', strtotime($tx['txn_date'])) : '—' ?></p>
                    </div>
                    <p class="font-semibold text-sm <?= $isInc ? 'text-green-600' : 'text-red-500' ?>"><?= $isInc ? '+' : '-' ?>৳<?= number_format($tx['amount']) ?></p>
                </div>
                <?php endforeach; ?>
                <?php if (empty($recentTxns)): ?><div class="px-5 py-8 text-center text-gray-400 text-sm">No transactions in this period</div><?php endif; ?>
            </div>
        </div>

        <!-- Ledger Entries -->
        <?php
        $ledgerEntries = $db->fetchAll("SELECT * FROM accounting_entries WHERE entry_date BETWEEN ? AND ? ORDER BY entry_date DESC, id DESC LIMIT 50", [$dateFrom, $dateTo]);
        $groupedByDate = [];
        foreach ($ledgerEntries as $entry) {
            $d = $entry['entry_date'];
            if (!isset($groupedByDate[$d])) $groupedByDate[$d] = ['entries' => [], 'income' => 0, 'expense' => 0, 'refund' => 0];
            $groupedByDate[$d]['entries'][] = $entry;
            $groupedByDate[$d][$entry['entry_type']] += $entry['amount'];
        }
        $typeBadge = ['income' => 'bg-green-100 text-green-700', 'expense' => 'bg-red-100 text-red-700', 'refund' => 'bg-orange-100 text-orange-700'];
        ?>
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="px-5 py-3 border-b"><h3 class="text-sm font-semibold text-gray-800">Ledger Entries</h3></div>
            <?php if (empty($groupedByDate)): ?>
            <div class="px-5 py-8 text-center text-gray-400 text-sm">No ledger entries for this period</div>
            <?php else: ?>
            <div class="divide-y">
                <?php foreach ($groupedByDate as $date => $dayData):
                    $dayNet = $dayData['income'] - $dayData['expense'] - $dayData['refund']; ?>
                <div>
                    <div class="flex items-center gap-3 px-5 py-3 cursor-pointer hover:bg-gray-50 transition date-row" data-date="<?= $date ?>">
                        <svg class="w-4 h-4 text-gray-400 transition-transform duration-200 chevron flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-gray-800"><?= date('d M Y, l', strtotime($date)) ?></p>
                            <p class="text-[10px] text-gray-400"><?= count($dayData['entries']) ?> entries</p>
                        </div>
                        <p class="font-bold text-sm <?= $dayNet >= 0 ? 'text-green-600' : 'text-red-600' ?>"><?= $dayNet >= 0 ? '+' : '' ?>৳<?= number_format($dayNet) ?></p>
                    </div>
                    <div class="date-detail hidden" id="detail-<?= $date ?>">
                        <table class="w-full text-xs"><tbody class="divide-y divide-gray-50">
                            <?php foreach ($dayData['entries'] as $entry): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-2 pl-12"><span class="<?= $typeBadge[$entry['entry_type']] ?> px-2 py-0.5 rounded-full text-[10px] font-medium"><?= ucfirst($entry['entry_type']) ?></span></td>
                                <td class="px-3 py-2 text-gray-700"><?= e($entry['description'] ?: '—') ?></td>
                                <td class="px-3 py-2 text-gray-400"><?= $entry['reference_type'] ? e($entry['reference_type']).'#'.$entry['reference_id'] : '' ?></td>
                                <td class="px-3 py-2 text-right font-semibold <?= $entry['entry_type'] === 'income' ? 'text-green-600' : 'text-red-500' ?>"><?= $entry['entry_type'] === 'income' ? '+' : '-' ?>৳<?= number_format($entry['amount']) ?></td>
                                <td class="px-3 py-2 text-center">
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="entry_id" value="<?= $entry['id'] ?>"><button class="text-red-400 hover:text-red-600 text-sm">×</button></form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.querySelectorAll('.date-row').forEach(row => {
    row.addEventListener('click', function() {
        const d = this.dataset.date, detail = document.getElementById('detail-' + d);
        const chev = this.querySelector('.chevron'), wasOpen = !detail.classList.contains('hidden');
        document.querySelectorAll('.date-detail').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.chevron').forEach(c => c.style.transform = '');
        if (!wasOpen) { detail.classList.remove('hidden'); chev.style.transform = 'rotate(90deg)'; }
    });
});
const ctx = document.getElementById('trendChart');
if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($trendMonths) ?>,
            datasets: [
                { label: 'Revenue', data: <?= json_encode(array_values($trendRevArr)) ?>, backgroundColor: 'rgba(34,197,94,0.75)', borderRadius: 4, barPercentage: 0.7 },
                { label: 'Expenses', data: <?= json_encode(array_values($trendExpArr)) ?>, backgroundColor: 'rgba(239,68,68,0.75)', borderRadius: 4, barPercentage: 0.7 }
            ]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } }, scales: { y: { beginAtZero: true, ticks: { font: { size: 10 } } }, x: { ticks: { font: { size: 10 } } } } }
    });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
