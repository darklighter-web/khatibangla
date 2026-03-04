<?php
require_once __DIR__ . '/../../includes/session.php';
/**
 * Admin - Expense Tracking
 */
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Expenses';
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_expense') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            (int)$_POST['category_id'],
            sanitize($_POST['title']),
            (float)$_POST['amount'],
            $_POST['expense_date'],
            sanitize($_POST['payment_method'] ?? ''),
            sanitize($_POST['reference'] ?? ''),
            sanitize($_POST['notes'] ?? '')
        ];
        
        $receiptImage = null;
        if (!empty($_FILES['receipt_image']['name'])) {
            $receiptImage = uploadFile($_FILES['receipt_image'], 'expenses');
        }
        
        if ($id > 0) {
            if ($receiptImage) {
                $data[] = $receiptImage;
                $db->query("UPDATE expenses SET category_id=?, title=?, amount=?, expense_date=?, payment_method=?, reference=?, notes=?, receipt_image=? WHERE id=?", [...$data, $id]);
            } else {
                $db->query("UPDATE expenses SET category_id=?, title=?, amount=?, expense_date=?, payment_method=?, reference=?, notes=? WHERE id=?", [...$data, $id]);
            }
            // Update accounting entry
            $db->query("UPDATE accounting_entries SET amount=?, entry_date=?, description=? WHERE reference_type='expense' AND reference_id=?", 
                [(float)$_POST['amount'], $_POST['expense_date'], sanitize($_POST['title']), $id]);
        } else {
            $data[] = $receiptImage;
            $data[] = getAdminId();
            $db->query("INSERT INTO expenses (category_id, title, amount, expense_date, payment_method, reference, notes, receipt_image, created_by) VALUES (?,?,?,?,?,?,?,?,?)", $data);
            $expenseId = $db->lastInsertId();
            // Auto-create accounting entry
            $db->query("INSERT INTO accounting_entries (entry_type, amount, reference_type, reference_id, description, entry_date) VALUES ('expense',?,'expense',?,?,?)",
                [(float)$_POST['amount'], $expenseId, sanitize($_POST['title']), $_POST['expense_date']]);
        }
        logActivity(getAdminId(), 'expense_saved', 'expense', $id);
        redirect(adminUrl('pages/expenses.php?msg=saved'));
    }
    
    if ($action === 'delete_expense') {
        $id = (int)$_POST['id'];
        $db->query("DELETE FROM accounting_entries WHERE reference_type='expense' AND reference_id=?", [$id]);
        $db->query("DELETE FROM expenses WHERE id=?", [$id]);
        redirect(adminUrl('pages/expenses.php?msg=deleted'));
    }
    
    if ($action === 'save_category') {
        $name = sanitize($_POST['cat_name']);
        $slug = strtolower(str_replace(' ', '-', $name));
        $db->query("INSERT INTO expense_categories (name, slug) VALUES (?,?)", [$name, $slug]);
        redirect(adminUrl('pages/expenses.php?msg=cat_saved'));
    }
}

$msg = $_GET['msg'] ?? '';
$search = $_GET['search'] ?? '';
$catFilter = (int)($_GET['category'] ?? 0);
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$page = max(1, (int)($_GET['p'] ?? 1));

$categories = $db->fetchAll("SELECT * FROM expense_categories ORDER BY name");

$where = "WHERE e.expense_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($search) { $where .= " AND (e.title LIKE ? OR e.reference LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter) { $where .= " AND e.category_id = ?"; $params[] = $catFilter; }

$total = $db->fetch("SELECT COUNT(*) as cnt FROM expenses e $where", $params)['cnt'];
$expenses = $db->fetchAll("SELECT e.*, ec.name as category_name, au.full_name as created_by_name 
    FROM expenses e 
    LEFT JOIN expense_categories ec ON e.category_id=ec.id 
    LEFT JOIN admin_users au ON e.created_by=au.id 
    $where ORDER BY e.expense_date DESC, e.id DESC 
    LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET " . (($page-1)*ADMIN_ITEMS_PER_PAGE), $params);

$totalAmount = $db->fetch("SELECT SUM(amount) as total FROM expenses e $where", $params)['total'] ?? 0;
$pagination = paginate($total, $page, ADMIN_ITEMS_PER_PAGE, adminUrl('pages/expenses.php?') . http_build_query(array_filter(['search'=>$search,'category'=>$catFilter,'from'=>$dateFrom,'to'=>$dateTo])));

// Monthly summary
$monthlySummary = $db->fetchAll("SELECT ec.name, SUM(e.amount) as total 
    FROM expenses e JOIN expense_categories ec ON e.category_id=ec.id 
    WHERE e.expense_date BETWEEN ? AND ? 
    GROUP BY ec.id ORDER BY total DESC", [$dateFrom, $dateTo]);

// Edit expense
$editExpense = null;
if (isset($_GET['edit'])) {
    $editExpense = $db->fetch("SELECT * FROM expenses WHERE id=?", [(int)$_GET['edit']]);
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg === 'saved'): ?><div class="mb-4 p-3 bg-green-50 text-green-700 rounded-xl text-sm">Expense saved!</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="mb-4 p-3 bg-green-50 text-green-700 rounded-xl text-sm">Expense deleted!</div><?php endif; ?>

<div class="grid lg:grid-cols-4 gap-6">
    <!-- Left: Form -->
    <div class="lg:col-span-1 space-y-4">
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><?= $editExpense ? 'Edit' : 'Add' ?> Expense</h3>
            <form method="POST" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="action" value="save_expense">
                <?php if ($editExpense): ?><input type="hidden" name="id" value="<?= $editExpense['id'] ?>"><?php endif; ?>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Title *</label>
                    <input type="text" name="title" required value="<?= e($editExpense['title'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Amount *</label>
                    <input type="number" name="amount" required step="0.01" value="<?= $editExpense['amount'] ?? '' ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Category *</label>
                    <select name="category_id" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="">Select</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= ($editExpense['category_id'] ?? '')==$cat['id']?'selected':'' ?>><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Date *</label>
                    <input type="date" name="expense_date" required value="<?= $editExpense['expense_date'] ?? date('Y-m-d') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Payment Method</label>
                    <select name="payment_method" class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="">Select</option>
                        <?php foreach (['cash','bank_transfer','bkash','nagad','credit_card','other'] as $pm): ?>
                        <option value="<?= $pm ?>" <?= ($editExpense['payment_method'] ?? '')===$pm?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$pm)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Reference</label>
                    <input type="text" name="reference" value="<?= e($editExpense['reference'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="Invoice/Receipt #">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm"><?= e($editExpense['notes'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Receipt Image</label>
                    <input type="file" name="receipt_image" accept="image/*" class="w-full text-sm">
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <?= $editExpense ? 'Update' : 'Add' ?> Expense
                </button>
            </form>
        </div>
        
        <!-- Category Summary -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-3">By Category</h4>
            <?php foreach ($monthlySummary as $ms): ?>
            <div class="flex justify-between py-1.5 text-sm">
                <span class="text-gray-600"><?= e($ms['name']) ?></span>
                <span class="font-medium"><?= formatPrice($ms['total']) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="flex justify-between pt-2 mt-2 border-t text-sm font-bold">
                <span>Total</span>
                <span class="text-red-600"><?= formatPrice($totalAmount) ?></span>
            </div>
        </div>
        
        <!-- Add Category -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-3">Add Category</h4>
            <form method="POST" class="flex gap-2">
                <input type="hidden" name="action" value="save_category">
                <input type="text" name="cat_name" required placeholder="Category name" class="flex-1 border rounded-lg px-3 py-2 text-sm">
                <button type="submit" class="bg-gray-100 px-3 py-2 rounded-lg text-sm hover:bg-gray-200">Add</button>
            </form>
        </div>
    </div>
    
    <!-- Right: List -->
    <div class="lg:col-span-3">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="p-4 border-b">
                <form class="flex flex-wrap gap-3 items-center">
                    <input type="hidden" name="from" value="<?= $dateFrom ?>">
                    <input type="hidden" name="to" value="<?= $dateTo ?>">
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search expenses..." class="flex-1 min-w-[150px] border rounded-lg px-3 py-2 text-sm">
                    <select name="category" class="border rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $catFilter==$cat['id']?'selected':'' ?>><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div data-kb-datepicker data-from-param="from" data-to-param="to"></div>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Title</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Category</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Amount</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">Method</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600">By</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        <?php foreach ($expenses as $exp): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-500"><?= date('M d, Y', strtotime($exp['expense_date'])) ?></td>
                            <td class="px-4 py-3 font-medium text-gray-800">
                                <?= e($exp['title']) ?>
                                <?php if ($exp['reference']): ?><br><span class="text-xs text-gray-400">Ref: <?= e($exp['reference']) ?></span><?php endif; ?>
                            </td>
                            <td class="px-4 py-3"><span class="px-2 py-1 bg-gray-100 rounded-full text-xs"><?= e($exp['category_name'] ?? 'N/A') ?></span></td>
                            <td class="px-4 py-3 text-right font-semibold text-red-600"><?= formatPrice($exp['amount']) ?></td>
                            <td class="px-4 py-3 text-gray-500"><?= ucfirst(str_replace('_',' ',$exp['payment_method'] ?? '-')) ?></td>
                            <td class="px-4 py-3 text-gray-500"><?= e($exp['created_by_name'] ?? '') ?></td>
                            <td class="px-4 py-3 text-right">
                                <a href="?edit=<?= $exp['id'] ?>" class="text-blue-600 hover:text-blue-800 text-xs mr-2">Edit</a>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="action" value="delete_expense">
                                    <input type="hidden" name="id" value="<?= $exp['id'] ?>">
                                    <button class="text-red-600 hover:text-red-800 text-xs">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($expenses)): ?>
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No expenses found</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($expenses)): ?>
                    <tfoot class="bg-gray-50">
                        <tr><td colspan="3" class="px-4 py-3 font-semibold text-right">Total:</td>
                        <td class="px-4 py-3 text-right font-bold text-red-600"><?= formatPrice($totalAmount) ?></td>
                        <td colspan="3"></td></tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            <?php if ($pagination['total_pages'] > 1): ?>
            <div class="p-4 border-t"><?= renderPagination($pagination) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
