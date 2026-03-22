<?php
/**
 * New Expense - Dedicated expense creation form
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'New Expense';
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_expense') {
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
        $data[] = $receiptImage;
        $data[] = getAdminId();
        $db->query("INSERT INTO expenses (category_id, title, amount, expense_date, payment_method, reference, notes, receipt_image, created_by) VALUES (?,?,?,?,?,?,?,?,?)", $data);
        $expenseId = $db->lastInsertId();
        $db->query("INSERT INTO accounting_entries (entry_type, amount, reference_type, reference_id, description, entry_date) VALUES ('expense',?,'expense',?,?,?)",
            [(float)$_POST['amount'], $expenseId, sanitize($_POST['title']), $_POST['expense_date']]);
        logActivity(getAdminId(), 'expense_created', 'expense', $expenseId);
        redirect(adminUrl('pages/expense-new.php?msg=saved'));
    }
    if ($action === 'save_category') {
        $name = sanitize($_POST['cat_name']);
        $slug = strtolower(str_replace(' ', '-', $name));
        $db->query("INSERT INTO expense_categories (name, slug) VALUES (?,?)", [$name, $slug]);
        redirect(adminUrl('pages/expense-new.php?msg=cat_saved'));
    }
}

$msg = $_GET['msg'] ?? '';
$categories = $db->fetchAll("SELECT * FROM expense_categories ORDER BY name");

// This month summary
$thisMonth = date('Y-m');
$monthTotal = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=?", [$thisMonth])['total'] ?? 0;
$todayTotal = $db->fetch("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE expense_date=CURDATE()")['total'] ?? 0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center gap-3 mb-4"><a href="<?= adminUrl('pages/accounting.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">&larr; Accounting</a><a href="<?= adminUrl('pages/expenses.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">Expense List</a><a href="<?= adminUrl('pages/income.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">Income</a><a href="<?= adminUrl('pages/liabilities.php') ?>" class="text-xs text-gray-500 hover:text-blue-600">Liabilities</a></div>
<?php if ($msg === 'saved'): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">Expense saved successfully!</div><?php endif; ?>
<?php if ($msg === 'cat_saved'): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">Category added!</div><?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Main Form -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm p-6">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Add New Expense</h2>
                    <p class="text-sm text-gray-500">Record a business expense with category and payment details</p>
                </div>
            </div>
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="save_expense">
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Expense Title *</label>
                        <input type="text" name="title" required class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="e.g., Office supplies, Courier charges">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (৳) *</label>
                        <input type="number" name="amount" required step="0.01" min="0" class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="0.00">
                    </div>
                </div>
                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category *</label>
                        <select name="category_id" required class="w-full border rounded-lg px-3 py-2.5 text-sm">
                            <option value="">Select category...</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date *</label>
                        <input type="date" name="expense_date" required value="<?= date('Y-m-d') ?>" class="w-full border rounded-lg px-3 py-2.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                        <select name="payment_method" class="w-full border rounded-lg px-3 py-2.5 text-sm">
                            <option value="">Select...</option>
                            <?php foreach (['cash'=>'Cash','bank_transfer'=>'Bank Transfer','bkash'=>'bKash','nagad'=>'Nagad','credit_card'=>'Credit Card','other'=>'Other'] as $k=>$v): ?>
                            <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reference / Invoice #</label>
                        <input type="text" name="reference" class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="Optional">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Receipt Image</label>
                        <input type="file" name="receipt_image" accept="image/*" class="w-full text-sm border rounded-lg px-3 py-2 file:mr-3 file:py-1 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 file:text-gray-700">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="3" class="w-full border rounded-lg px-3 py-2.5 text-sm" placeholder="Additional details..."></textarea>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-red-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-red-700">Save Expense</button>
                    <a href="<?= adminUrl('pages/expenses.php') ?>" class="px-4 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-200">View All Expenses</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="space-y-4">
        <!-- Quick Stats -->
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h4 class="font-semibold text-gray-800 mb-3">Expense Summary</h4>
            <div class="space-y-3">
                <div class="flex justify-between py-2 border-b">
                    <span class="text-sm text-gray-500">Today</span>
                    <span class="font-semibold text-red-600"><?= formatPrice($todayTotal) ?></span>
                </div>
                <div class="flex justify-between py-2">
                    <span class="text-sm text-gray-500">This Month</span>
                    <span class="font-semibold text-red-600"><?= formatPrice($monthTotal) ?></span>
                </div>
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
            <div class="mt-3 flex flex-wrap gap-1">
                <?php foreach ($categories as $cat): ?>
                <span class="px-2 py-1 bg-gray-50 rounded text-xs text-gray-600"><?= e($cat['name']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
