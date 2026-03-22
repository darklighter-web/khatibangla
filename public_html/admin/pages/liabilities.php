<?php
/**
 * Liabilities - Track debts, payables, and obligations
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Liabilities';
$db = Database::getInstance();

// Ensure tables exist
try { $db->query("SELECT 1 FROM liabilities LIMIT 1"); } catch (Exception $e) {
    $db->query("CREATE TABLE IF NOT EXISTS liabilities (id int AUTO_INCREMENT PRIMARY KEY, title varchar(200) NOT NULL, amount decimal(12,2) NOT NULL, due_date date, creditor_name varchar(200), creditor_contact varchar(100), status enum('pending','partial','paid','overdue') DEFAULT 'pending', paid_amount decimal(12,2) DEFAULT 0, payment_method varchar(30), category varchar(100), notes text, created_by int, created_at timestamp DEFAULT CURRENT_TIMESTAMP, updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
}
try { $db->query("SELECT 1 FROM liability_payments LIMIT 1"); } catch (Exception $e) {
    $db->query("CREATE TABLE IF NOT EXISTS liability_payments (id int AUTO_INCREMENT PRIMARY KEY, liability_id int NOT NULL, amount decimal(12,2) NOT NULL, payment_date date NOT NULL, payment_method varchar(30), reference varchar(100), notes text, created_by int, created_at timestamp DEFAULT CURRENT_TIMESTAMP, KEY liability_id(liability_id))");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_liability') {
        $db->query("INSERT INTO liabilities (title, amount, due_date, creditor_name, creditor_contact, category, notes, created_by) VALUES (?,?,?,?,?,?,?,?)", [
            sanitize($_POST['title']), (float)$_POST['amount'], $_POST['due_date'] ?: null,
            sanitize($_POST['creditor_name'] ?? ''), sanitize($_POST['creditor_contact'] ?? ''),
            sanitize($_POST['category'] ?? ''), sanitize($_POST['notes'] ?? ''), getAdminId()
        ]);
        redirect(adminUrl('pages/liabilities.php?msg=saved'));
    }

    if ($action === 'make_payment') {
        $liabilityId = (int)$_POST['liability_id'];
        $amount = (float)$_POST['payment_amount'];
        $db->query("INSERT INTO liability_payments (liability_id, amount, payment_date, payment_method, reference, notes, created_by) VALUES (?,?,?,?,?,?,?)", [
            $liabilityId, $amount, $_POST['payment_date'] ?? date('Y-m-d'),
            sanitize($_POST['payment_method'] ?? ''), sanitize($_POST['payment_reference'] ?? ''),
            sanitize($_POST['payment_notes'] ?? ''), getAdminId()
        ]);
        $totalPaid = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM liability_payments WHERE liability_id=?", [$liabilityId])['total'] ?? 0;
        $liability = $db->fetch("SELECT amount FROM liabilities WHERE id=?", [$liabilityId]);
        $newStatus = $totalPaid >= ($liability['amount'] ?? 0) ? 'paid' : ($totalPaid > 0 ? 'partial' : 'pending');
        $db->query("UPDATE liabilities SET paid_amount=?, status=? WHERE id=?", [$totalPaid, $newStatus, $liabilityId]);

        // Record as expense
        $db->query("INSERT INTO accounting_entries (entry_type, amount, reference_type, reference_id, description, entry_date) VALUES ('expense',?,'liability_payment',?,?,?)",
            [$amount, $liabilityId, 'Liability payment: ' . sanitize($_POST['payment_notes'] ?? ''), $_POST['payment_date'] ?? date('Y-m-d')]);
        redirect(adminUrl('pages/liabilities.php?msg=payment_saved'));
    }

    if ($action === 'delete_liability') {
        $id = (int)$_POST['id'];
        $db->query("DELETE FROM liability_payments WHERE liability_id=?", [$id]);
        $db->query("DELETE FROM liabilities WHERE id=?", [$id]);
        redirect(adminUrl('pages/liabilities.php?msg=deleted'));
    }
}

$msg = $_GET['msg'] ?? '';
$tab = $_GET['tab'] ?? 'list';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1));

$where = "WHERE 1=1";
$params = [];
if ($statusFilter) { $where .= " AND l.status=?"; $params[] = $statusFilter; }

// Auto-mark overdue
$db->query("UPDATE liabilities SET status='overdue' WHERE status IN ('pending','partial') AND due_date < CURDATE() AND due_date IS NOT NULL");

$total = $db->fetch("SELECT COUNT(*) as cnt FROM liabilities l $where", $params)['cnt'] ?? 0;
$liabilities = $db->fetchAll("SELECT l.*, au.full_name as created_by_name FROM liabilities l LEFT JOIN admin_users au ON l.created_by=au.id $where ORDER BY FIELD(l.status,'overdue','pending','partial','paid'), l.due_date ASC LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET " . (($page-1)*ADMIN_ITEMS_PER_PAGE), $params);
$pagination = paginate($total, $page, ADMIN_ITEMS_PER_PAGE, adminUrl('pages/liabilities.php?') . http_build_query(array_filter(['status'=>$statusFilter])));

$totalOwed = $db->fetch("SELECT COALESCE(SUM(amount - paid_amount),0) as total FROM liabilities WHERE status IN ('pending','partial','overdue')")['total'] ?? 0;
$totalOverdue = $db->fetch("SELECT COALESCE(SUM(amount - paid_amount),0) as total FROM liabilities WHERE status='overdue'")['total'] ?? 0;
$totalPaidThisMonth = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM liability_payments WHERE DATE_FORMAT(payment_date,'%Y-%m')=?", [date('Y-m')])['total'] ?? 0;

$statusColors = ['pending'=>'bg-yellow-100 text-yellow-700','partial'=>'bg-blue-100 text-blue-700','paid'=>'bg-green-100 text-green-700','overdue'=>'bg-red-100 text-red-700'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center gap-3 mb-4"><a href="<?= adminUrl('pages/accounting.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">&larr; Accounting</a><a href="<?= adminUrl('pages/expenses.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">Expenses</a><a href="<?= adminUrl('pages/income.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">Income</a></div>
<?php if ($msg): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm"><?= $msg === 'payment_saved' ? 'Payment recorded!' : ucfirst($msg) . '!' ?></div><?php endif; ?>

<!-- Summary -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500 mb-1">Total Outstanding</p><p class="text-2xl font-bold text-red-600">৳<?= number_format($totalOwed) ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500 mb-1">Overdue Amount</p><p class="text-2xl font-bold text-red-700">৳<?= number_format($totalOverdue) ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500 mb-1">Paid This Month</p><p class="text-2xl font-bold text-green-600">৳<?= number_format($totalPaidThisMonth) ?></p></div>
</div>

<div class="flex gap-2 mb-6">
    <a href="?tab=list" class="px-4 py-2 rounded-lg text-sm font-medium <?= $tab==='list'?'bg-blue-600 text-white':'bg-gray-100 text-gray-600' ?>">All Liabilities</a>
    <a href="?tab=add" class="px-4 py-2 rounded-lg text-sm font-medium <?= $tab==='add'?'bg-blue-600 text-white':'bg-gray-100 text-gray-600' ?>">Add Liability</a>
    <!-- Status Filters -->
    <div class="ml-auto flex gap-1">
        <a href="?tab=list" class="px-3 py-1.5 rounded text-xs <?= !$statusFilter?'bg-gray-800 text-white':'bg-gray-100 text-gray-600' ?>">All</a>
        <?php foreach (['pending','partial','overdue','paid'] as $s): ?>
        <a href="?tab=list&status=<?= $s ?>" class="px-3 py-1.5 rounded text-xs <?= $statusFilter===$s?'bg-gray-800 text-white':'bg-gray-100 text-gray-600' ?>"><?= ucfirst($s) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($tab === 'add'): ?>
<div class="max-w-xl mx-auto bg-white rounded-xl border shadow-sm p-6">
    <h3 class="font-semibold text-gray-800 mb-4">Add New Liability</h3>
    <form method="POST" class="space-y-4">
        <input type="hidden" name="action" value="save_liability">
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Title *</label><input type="text" name="title" required class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="e.g., Supplier invoice, Loan payment"></div>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Amount (৳) *</label><input type="number" name="amount" step="0.01" min="0" required class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label><input type="date" name="due_date" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Creditor Name</label><input type="text" name="creditor_name" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Creditor Contact</label><input type="text" name="creditor_contact" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
            <select name="category" class="w-full border rounded-lg px-3 py-2.5 text-sm">
                <option value="">Select...</option>
                <option value="supplier">Supplier Payment</option>
                <option value="loan">Loan</option>
                <option value="rent">Rent</option>
                <option value="salary">Salary</option>
                <option value="utility">Utility Bill</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Notes</label><textarea name="notes" rows="2" class="w-full border rounded-lg px-3 py-2.5 text-sm"></textarea></div>
        <button type="submit" class="w-full bg-red-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-red-700">Add Liability</button>
    </form>
</div>

<?php else: ?>
<div class="bg-white rounded-xl border shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Title</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Creditor</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Amount</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Paid</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Remaining</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Due Date</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Status</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($liabilities as $l): ?>
                <?php $remaining = $l['amount'] - $l['paid_amount']; ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3"><p class="font-medium text-gray-800"><?= e($l['title']) ?></p><p class="text-xs text-gray-400"><?= e($l['category'] ?? '') ?></p></td>
                    <td class="px-4 py-3 text-gray-600"><?= e($l['creditor_name'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-right font-semibold">৳<?= number_format($l['amount']) ?></td>
                    <td class="px-4 py-3 text-right text-green-600">৳<?= number_format($l['paid_amount']) ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-red-600">৳<?= number_format($remaining) ?></td>
                    <td class="px-4 py-3 text-gray-500"><?= $l['due_date'] ? date('d M Y', strtotime($l['due_date'])) : '—' ?></td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $statusColors[$l['status']] ?? '' ?>"><?= ucfirst($l['status']) ?></span></td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($l['status'] !== 'paid'): ?>
                        <button onclick="openPaymentModal(<?= $l['id'] ?>, <?= $remaining ?>)" class="text-green-600 hover:text-green-800 text-xs mr-2">Pay</button>
                        <?php endif; ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete_liability"><input type="hidden" name="id" value="<?= $l['id'] ?>"><button class="text-red-600 text-xs">Delete</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($liabilities)): ?><tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">No liabilities found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (($pagination['total_pages'] ?? 0) > 1): ?><div class="p-4 border-t"><?= renderPagination($pagination) ?></div><?php endif; ?>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl p-6 w-full max-w-md shadow-xl">
        <h3 class="font-semibold text-gray-800 mb-4">Record Payment</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="make_payment">
            <input type="hidden" name="liability_id" id="payLiabilityId">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Payment Amount (৳) *</label><input type="number" name="payment_amount" id="payAmount" step="0.01" min="0" required class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Date</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Method</label>
                    <select name="payment_method" class="w-full border rounded-lg px-3 py-2.5 text-sm">
                        <option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="bkash">bKash</option><option value="nagad">Nagad</option>
                    </select>
                </div>
            </div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Reference</label><input type="text" name="payment_reference" class="w-full border rounded-lg px-3 py-2.5 text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Notes</label><textarea name="payment_notes" rows="2" class="w-full border rounded-lg px-3 py-2.5 text-sm"></textarea></div>
            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-green-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-green-700">Record Payment</button>
                <button type="button" onclick="document.getElementById('paymentModal').classList.add('hidden')" class="px-4 py-2.5 bg-gray-100 rounded-lg text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
function openPaymentModal(id, remaining) {
    document.getElementById('payLiabilityId').value = id;
    document.getElementById('payAmount').value = remaining.toFixed(2);
    document.getElementById('payAmount').max = remaining;
    document.getElementById('paymentModal').classList.remove('hidden');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
