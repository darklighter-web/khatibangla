<?php
/**
 * Supplier Management — list, profile, debit/credit ledger, linked products
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Supplier Management';
$db = Database::getInstance();
$msg = '';

// ── POST Handlers ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── Save / Update Supplier ── */
    if ($action === 'save_supplier') {
        $id = intval($_POST['id'] ?? 0);
        $data = [
            sanitize($_POST['name'] ?? ''),
            sanitize($_POST['company'] ?? ''),
            sanitize($_POST['phone'] ?? ''),
            sanitize($_POST['alt_phone'] ?? ''),
            sanitize($_POST['email'] ?? ''),
            sanitize($_POST['address'] ?? ''),
            sanitize($_POST['city'] ?? ''),
            sanitize($_POST['district'] ?? ''),
            sanitize($_POST['country'] ?? 'Bangladesh'),
            sanitize($_POST['payment_method'] ?? ''),
            sanitize($_POST['bank_name'] ?? ''),
            sanitize($_POST['bank_account'] ?? ''),
            sanitize($_POST['bank_branch'] ?? ''),
            sanitize($_POST['bkash'] ?? ''),
            sanitize($_POST['nagad'] ?? ''),
            intval($_POST['avg_delivery_days'] ?? 0) ?: null,
            floatval($_POST['credit_limit'] ?? 0),
            sanitize($_POST['notes'] ?? ''),
            isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($id > 0) {
            $data[] = $id;
            $db->query("UPDATE suppliers SET name=?,company=?,phone=?,alt_phone=?,email=?,address=?,city=?,district=?,country=?,payment_method=?,bank_name=?,bank_account=?,bank_branch=?,bkash=?,nagad=?,avg_delivery_days=?,credit_limit=?,notes=?,is_active=? WHERE id=?", $data);
        } else {
            $data[] = getAdminId();
            // opening_balance
            $ob = floatval($_POST['opening_balance'] ?? 0);
            $data[] = $ob;
            $db->query("INSERT INTO suppliers (name,company,phone,alt_phone,email,address,city,district,country,payment_method,bank_name,bank_account,bank_branch,bkash,nagad,avg_delivery_days,credit_limit,notes,is_active,created_by,opening_balance) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $data);
            $newId = $db->lastInsertId();
            // Record opening balance as debit if > 0
            if ($ob > 0 && $newId) {
                $db->query("INSERT INTO supplier_transactions (supplier_id,type,amount,reference_type,description,transaction_date,created_by) VALUES (?,?,?,'opening_balance','Opening Balance',CURDATE(),?)",
                    [$newId, 'debit', $ob, getAdminId()]);
            }
        }
        redirect(adminUrl('pages/suppliers.php?msg=saved'));
    }

    /* ── Delete Supplier ── */
    if ($action === 'delete_supplier') {
        $id = intval($_POST['id'] ?? 0);
        $db->query("DELETE FROM supplier_products WHERE supplier_id=?", [$id]);
        $db->query("DELETE FROM supplier_transactions WHERE supplier_id=?", [$id]);
        $db->query("DELETE FROM suppliers WHERE id=?", [$id]);
        redirect(adminUrl('pages/suppliers.php?msg=deleted'));
    }

    /* ── Add Transaction (Debit/Credit) ── */
    if ($action === 'add_transaction') {
        $sid = intval($_POST['supplier_id'] ?? 0);
        $db->query("INSERT INTO supplier_transactions (supplier_id,type,amount,reference_type,description,transaction_date,payment_method,created_by) VALUES (?,?,?,?,?,?,?,?)",
            [
                $sid,
                $_POST['type'] === 'credit' ? 'credit' : 'debit',
                floatval($_POST['amount']),
                sanitize($_POST['reference_type'] ?? 'manual'),
                sanitize($_POST['description'] ?? ''),
                $_POST['transaction_date'] ?: date('Y-m-d'),
                sanitize($_POST['payment_method'] ?? ''),
                getAdminId()
            ]);
        redirect(adminUrl("pages/suppliers.php?view={$sid}&tab=ledger&msg=tx_saved"));
    }

    /* ── Delete Transaction ── */
    if ($action === 'delete_transaction') {
        $txId = intval($_POST['tx_id'] ?? 0);
        $sid  = intval($_POST['supplier_id'] ?? 0);
        $db->query("DELETE FROM supplier_transactions WHERE id=?", [$txId]);
        redirect(adminUrl("pages/suppliers.php?view={$sid}&tab=ledger&msg=tx_deleted"));
    }

    /* ── Link / Unlink Product ── */
    if ($action === 'link_product') {
        $sid = intval($_POST['supplier_id'] ?? 0);
        $pid = intval($_POST['product_id'] ?? 0);
        if ($sid && $pid) {
            $exists = $db->fetch("SELECT id FROM supplier_products WHERE supplier_id=? AND product_id=?", [$sid, $pid]);
            if (!$exists) {
                $db->query("INSERT INTO supplier_products (supplier_id,product_id,supplier_sku,cost_price,min_order_qty,lead_days,is_preferred,notes) VALUES (?,?,?,?,?,?,?,?)",
                    [$sid, $pid, sanitize($_POST['supplier_sku'] ?? ''), floatval($_POST['cost_price'] ?? 0) ?: null,
                     intval($_POST['min_order_qty'] ?? 1), intval($_POST['lead_days'] ?? 0) ?: null,
                     isset($_POST['is_preferred']) ? 1 : 0, sanitize($_POST['notes'] ?? '')]);
            }
        }
        redirect(adminUrl("pages/suppliers.php?view={$sid}&tab=products&msg=linked"));
    }

    if ($action === 'unlink_product') {
        $sid = intval($_POST['supplier_id'] ?? 0);
        $db->query("DELETE FROM supplier_products WHERE id=?", [intval($_POST['sp_id'] ?? 0)]);
        redirect(adminUrl("pages/suppliers.php?view={$sid}&tab=products&msg=unlinked"));
    }
}

// ── View Mode: Single Supplier Profile ─────────────────────────────────────
$viewId = intval($_GET['view'] ?? 0);
$tab    = $_GET['tab'] ?? 'overview';
$msg    = $_GET['msg'] ?? '';

if ($viewId) {
    $supplier = $db->fetch("SELECT * FROM suppliers WHERE id=?", [$viewId]);
    if (!$supplier) { redirect(adminUrl('pages/suppliers.php')); }

    // Balance calculation
    $balRow = $db->fetch("SELECT
        COALESCE(SUM(CASE WHEN type='debit'  THEN amount ELSE 0 END),0) as total_debit,
        COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END),0) as total_credit
        FROM supplier_transactions WHERE supplier_id=?", [$viewId]);
    $totalDebit  = floatval($balRow['total_debit'] ?? 0);
    $totalCredit = floatval($balRow['total_credit'] ?? 0);
    $balance     = $totalDebit - $totalCredit; // positive = we owe them

    // Ledger
    $ledger = $db->fetchAll("SELECT * FROM supplier_transactions WHERE supplier_id=? ORDER BY transaction_date DESC, id DESC", [$viewId]);

    // Linked products with low stock alert
    $linkedProducts = $db->fetchAll("SELECT sp.*, p.name, p.sku, p.stock_quantity, p.low_stock_threshold, p.featured_image,
        p.regular_price, COALESCE(p.sale_price, p.regular_price) as sell_price
        FROM supplier_products sp
        JOIN products p ON sp.product_id = p.id
        WHERE sp.supplier_id=?
        ORDER BY p.stock_quantity ASC", [$viewId]);

    // All products for linking dropdown (not already linked)
    $linkedIds = array_column($linkedProducts, 'product_id');
    $allProducts = $db->fetchAll("SELECT id, name, sku FROM products WHERE is_active=1 ORDER BY name");
    $unlinkable  = array_filter($allProducts, fn($p) => !in_array($p['id'], $linkedIds));

    require_once __DIR__ . '/../includes/header.php';
    ?>
    <!-- Supplier Profile -->
    <div class="mb-4 flex items-center gap-3">
        <a href="<?= adminUrl('pages/suppliers.php') ?>" class="text-gray-400 hover:text-gray-600 text-sm flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg> Suppliers
        </a>
        <span class="text-gray-300">/</span>
        <span class="font-semibold text-gray-800"><?= e($supplier['name']) ?></span>
        <?php if (!$supplier['is_active']): ?><span class="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full">Inactive</span><?php endif; ?>
    </div>

    <?php if ($msg): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
        <?= ['saved'=>'Saved ✓','tx_saved'=>'Transaction recorded ✓','tx_deleted'=>'Deleted','linked'=>'Product linked ✓','unlinked'=>'Product removed'][$msg] ?? $msg ?>
    </div><?php endif; ?>

    <!-- Profile header card -->
    <div class="bg-white rounded-2xl border shadow-sm p-6 mb-6">
        <div class="flex flex-col md:flex-row md:items-start gap-6">
            <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-blue-700 rounded-2xl flex items-center justify-center text-white text-xl font-bold flex-shrink-0">
                <?= strtoupper(substr($supplier['name'],0,1)) ?>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-xl font-bold text-gray-900"><?= e($supplier['name']) ?></h2>
                <?php if ($supplier['company']): ?><p class="text-gray-500 text-sm"><?= e($supplier['company']) ?></p><?php endif; ?>
                <div class="flex flex-wrap gap-4 mt-3 text-sm">
                    <?php if ($supplier['phone']): ?><span class="flex items-center gap-1 text-gray-600"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg><?= e($supplier['phone']) ?></span><?php endif; ?>
                    <?php if ($supplier['email']): ?><span class="flex items-center gap-1 text-gray-600"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg><?= e($supplier['email']) ?></span><?php endif; ?>
                    <?php if ($supplier['city']): ?><span class="flex items-center gap-1 text-gray-600"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg><?= e($supplier['city']) ?><?= $supplier['district'] ? ', '.e($supplier['district']) : '' ?></span><?php endif; ?>
                    <?php if ($supplier['avg_delivery_days']): ?><span class="flex items-center gap-1 text-blue-600"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>~<?= $supplier['avg_delivery_days'] ?> days delivery</span><?php endif; ?>
                </div>
            </div>
            <!-- Balance summary -->
            <div class="flex gap-4 flex-shrink-0">
                <div class="text-center p-4 bg-red-50 rounded-xl min-w-[100px]">
                    <p class="text-xs text-gray-500 mb-1">Total Debit</p>
                    <p class="text-lg font-bold text-red-600">৳<?= number_format($totalDebit,0) ?></p>
                </div>
                <div class="text-center p-4 bg-green-50 rounded-xl min-w-[100px]">
                    <p class="text-xs text-gray-500 mb-1">Total Credit</p>
                    <p class="text-lg font-bold text-green-600">৳<?= number_format($totalCredit,0) ?></p>
                </div>
                <div class="text-center p-4 <?= $balance > 0 ? 'bg-orange-50' : 'bg-blue-50' ?> rounded-xl min-w-[100px]">
                    <p class="text-xs text-gray-500 mb-1"><?= $balance > 0 ? 'We Owe' : ($balance < 0 ? 'They Owe' : 'Clear') ?></p>
                    <p class="text-lg font-bold <?= $balance > 0 ? 'text-orange-600' : ($balance < 0 ? 'text-blue-600' : 'text-gray-400') ?>">৳<?= number_format(abs($balance),0) ?></p>
                </div>
            </div>
            <div class="flex gap-2 flex-shrink-0">
                <button onclick="openEditSupplier(<?= htmlspecialchars(json_encode($supplier), ENT_QUOTES) ?>)" class="btn-secondary text-sm px-4 py-2">Edit</button>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 mb-6 bg-gray-100 p-1 rounded-xl w-fit">
        <?php foreach (['overview'=>'Overview','ledger'=>'Ledger','products'=>'Products ('.count($linkedProducts).')'] as $t=>$l): ?>
        <a href="<?= adminUrl("pages/suppliers.php?view={$viewId}&tab={$t}") ?>"
           class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab===$t ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700' ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($tab === 'overview'): ?>
    <!-- Overview: full details -->
    <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl border p-5">
            <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2"><svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Contact Details</h3>
            <div class="space-y-3 text-sm">
                <?php foreach ([
                    ['Phone',      $supplier['phone']],
                    ['Alt Phone',  $supplier['alt_phone']],
                    ['Email',      $supplier['email']],
                    ['Address',    $supplier['address']],
                    ['City',       $supplier['city']],
                    ['District',   $supplier['district']],
                    ['Country',    $supplier['country']],
                ] as [$l,$v]): if (!$v) continue; ?>
                <div class="flex gap-3"><span class="text-gray-400 w-24 flex-shrink-0"><?= $l ?></span><span class="text-gray-800"><?= e($v) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="bg-white rounded-2xl border p-5">
            <h3 class="font-semibold text-gray-800 mb-4 flex items-center gap-2"><svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>Payment Details</h3>
            <div class="space-y-3 text-sm">
                <?php foreach ([
                    ['Method',       $supplier['payment_method']],
                    ['Bank',         $supplier['bank_name']],
                    ['Account',      $supplier['bank_account']],
                    ['Branch',       $supplier['bank_branch']],
                    ['bKash',        $supplier['bkash']],
                    ['Nagad',        $supplier['nagad']],
                    ['Credit Limit', $supplier['credit_limit'] > 0 ? '৳'.number_format($supplier['credit_limit']) : null],
                    ['Avg Delivery', $supplier['avg_delivery_days'] ? $supplier['avg_delivery_days'].' days' : null],
                ] as [$l,$v]): if (!$v) continue; ?>
                <div class="flex gap-3"><span class="text-gray-400 w-24 flex-shrink-0"><?= $l ?></span><span class="text-gray-800"><?= e($v) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($supplier['notes']): ?>
        <div class="bg-white rounded-2xl border p-5 md:col-span-2">
            <h3 class="font-semibold text-gray-800 mb-2">Notes</h3>
            <p class="text-sm text-gray-600 whitespace-pre-line"><?= e($supplier['notes']) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($tab === 'ledger'): ?>
    <!-- Ledger -->
    <div class="flex justify-between items-center mb-4">
        <h3 class="font-semibold text-gray-800">Transaction Ledger</h3>
        <button onclick="document.getElementById('txModal').classList.remove('hidden')" class="btn-primary text-sm px-4 py-2">+ Add Transaction</button>
    </div>
    <?php if (empty($ledger)): ?>
    <div class="bg-white rounded-2xl border p-12 text-center text-gray-400 text-sm">No transactions yet.</div>
    <?php else: ?>
    <div class="bg-white rounded-2xl border overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Method</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">Debit</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">Credit</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">Balance</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php
                $runBal = $supplier['opening_balance'] ?? 0;
                $rows = array_reverse($ledger); // oldest first for running balance
                $rowsWithBal = [];
                foreach ($rows as $tx) {
                    if ($tx['type'] === 'debit')  $runBal += $tx['amount'];
                    else                           $runBal -= $tx['amount'];
                    $tx['run_balance'] = $runBal;
                    $rowsWithBal[] = $tx;
                }
                foreach (array_reverse($rowsWithBal) as $tx):
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?= date('d M Y', strtotime($tx['transaction_date'])) ?></td>
                    <td class="px-4 py-3 font-medium text-gray-800"><?= e($tx['description']) ?></td>
                    <td class="px-4 py-3"><span class="text-xs font-semibold px-2 py-0.5 rounded-full <?= $tx['reference_type']==='payment'?'bg-green-100 text-green-700':($tx['reference_type']==='purchase'?'bg-blue-100 text-blue-700':'bg-gray-100 text-gray-600') ?>"><?= e($tx['reference_type'] ?? '') ?></span></td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= e($tx['payment_method'] ?? '') ?></td>
                    <td class="px-4 py-3 text-right font-medium <?= $tx['type']==='debit'?'text-red-600':'' ?>"><?= $tx['type']==='debit' ? '৳'.number_format($tx['amount'],2) : '' ?></td>
                    <td class="px-4 py-3 text-right font-medium <?= $tx['type']==='credit'?'text-green-600':'' ?>"><?= $tx['type']==='credit' ? '৳'.number_format($tx['amount'],2) : '' ?></td>
                    <td class="px-4 py-3 text-right font-semibold <?= $tx['run_balance']>0?'text-orange-600':($tx['run_balance']<0?'text-blue-600':'text-gray-400') ?>">৳<?= number_format($tx['run_balance'],2) ?></td>
                    <td class="px-4 py-3 text-right">
                        <form method="POST" onsubmit="return confirm('Delete?')">
                            <input type="hidden" name="action" value="delete_transaction">
                            <input type="hidden" name="tx_id" value="<?= $tx['id'] ?>">
                            <input type="hidden" name="supplier_id" value="<?= $viewId ?>">
                            <button class="text-xs text-red-400 hover:text-red-600">Del</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-gray-50 border-t font-semibold">
                <tr>
                    <td colspan="4" class="px-4 py-3 text-sm text-gray-600">Total</td>
                    <td class="px-4 py-3 text-right text-red-600">৳<?= number_format($totalDebit,2) ?></td>
                    <td class="px-4 py-3 text-right text-green-600">৳<?= number_format($totalCredit,2) ?></td>
                    <td class="px-4 py-3 text-right <?= $balance>0?'text-orange-600':($balance<0?'text-blue-600':'text-gray-400') ?>">৳<?= number_format($balance,2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- Add Transaction Modal -->
    <div id="txModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <h3 class="font-bold text-gray-900 mb-5">Add Transaction</h3>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_transaction">
                <input type="hidden" name="supplier_id" value="<?= $viewId ?>">
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex items-center gap-2 p-3 border-2 rounded-xl cursor-pointer has-[:checked]:border-red-400 has-[:checked]:bg-red-50">
                        <input type="radio" name="type" value="debit" checked class="accent-red-500">
                        <div><p class="font-semibold text-sm text-red-600">Debit</p><p class="text-xs text-gray-400">We owe them</p></div>
                    </label>
                    <label class="flex items-center gap-2 p-3 border-2 rounded-xl cursor-pointer has-[:checked]:border-green-400 has-[:checked]:bg-green-50">
                        <input type="radio" name="type" value="credit" class="accent-green-500">
                        <div><p class="font-semibold text-sm text-green-600">Credit</p><p class="text-xs text-gray-400">Payment made</p></div>
                    </label>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (৳) *</label>
                    <input type="number" name="amount" step="0.01" min="0.01" required class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description *</label>
                    <input type="text" name="description" required class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none" placeholder="e.g. Invoice #1234">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                        <select name="reference_type" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                            <option value="purchase">Purchase</option>
                            <option value="payment">Payment</option>
                            <option value="return">Return</option>
                            <option value="adjustment">Adjustment</option>
                            <option value="manual">Manual</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                    <select name="payment_method" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                        <option value="">— Select —</option>
                        <option>Cash</option><option>Bank Transfer</option><option>bKash</option><option>Nagad</option><option>Cheque</option><option>Credit</option>
                    </select>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="btn-primary flex-1 py-2.5 text-sm">Save Transaction</button>
                    <button type="button" onclick="document.getElementById('txModal').classList.add('hidden')" class="btn-secondary flex-1 py-2.5 text-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'products'): ?>
    <!-- Products -->
    <div class="flex justify-between items-center mb-4">
        <h3 class="font-semibold text-gray-800">Products from this Supplier</h3>
        <button onclick="document.getElementById('linkModal').classList.remove('hidden')" class="btn-primary text-sm px-4 py-2">+ Link Product</button>
    </div>

    <!-- Low stock alerts for supplier's products -->
    <?php
    $spLow = array_filter($linkedProducts, fn($p) => $p['stock_quantity'] > 0 && $p['stock_quantity'] <= $p['low_stock_threshold']);
    $spOut = array_filter($linkedProducts, fn($p) => $p['stock_quantity'] <= 0);
    ?>
    <?php if (!empty($spOut) || !empty($spLow)): ?>
    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl text-sm">
        <p class="font-semibold text-red-700 mb-2 flex items-center gap-1"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg> Stock Alert</p>
        <?php foreach ($spOut as $p): ?><span class="inline-block bg-red-100 text-red-700 px-2 py-0.5 rounded mr-1 mb-1"><?= e($p['name']) ?> — OUT OF STOCK</span><?php endforeach; ?>
        <?php foreach ($spLow as $p): ?><span class="inline-block bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded mr-1 mb-1"><?= e($p['name']) ?> — <?= $p['stock_quantity'] ?> left</span><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($linkedProducts)): ?>
    <div class="bg-white rounded-2xl border p-12 text-center text-gray-400 text-sm">No products linked yet.</div>
    <?php else: ?>
    <div class="bg-white rounded-2xl border overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Product</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">SKU</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Supplier SKU</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">Cost Price</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">Stock</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">MOQ</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">Lead</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($linkedProducts as $lp):
                    $isLow = $lp['stock_quantity'] > 0 && $lp['stock_quantity'] <= $lp['low_stock_threshold'];
                    $isOut = $lp['stock_quantity'] <= 0;
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <?php if ($lp['featured_image']): ?><img src="<?= uploadUrl($lp['featured_image']) ?>" class="w-8 h-8 rounded object-cover"><?php endif; ?>
                            <div>
                                <p class="font-medium text-gray-800"><?= e($lp['name']) ?></p>
                                <?php if ($lp['is_preferred']): ?><span class="text-xs text-blue-600 font-medium">★ Preferred</span><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-400"><?= e($lp['sku'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-500"><?= e($lp['supplier_sku'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-right"><?= $lp['cost_price'] ? '৳'.number_format($lp['cost_price'],2) : '—' ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($isOut): ?>
                            <span class="text-xs font-bold bg-red-100 text-red-600 px-2 py-0.5 rounded-full">Out</span>
                        <?php elseif ($isLow): ?>
                            <span class="text-xs font-bold bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full"><?= $lp['stock_quantity'] ?> ⚠</span>
                        <?php else: ?>
                            <span class="font-medium text-green-600"><?= $lp['stock_quantity'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right text-gray-500"><?= $lp['min_order_qty'] ?></td>
                    <td class="px-4 py-3 text-right text-gray-500"><?= $lp['lead_days'] ? $lp['lead_days'].'d' : '—' ?></td>
                    <td class="px-4 py-3 text-right">
                        <form method="POST" class="inline" onsubmit="return confirm('Unlink?')">
                            <input type="hidden" name="action" value="unlink_product">
                            <input type="hidden" name="sp_id" value="<?= $lp['id'] ?>">
                            <input type="hidden" name="supplier_id" value="<?= $viewId ?>">
                            <button class="text-xs text-red-400 hover:text-red-600">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Link Product Modal -->
    <div id="linkModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <h3 class="font-bold text-gray-900 mb-5">Link Product to Supplier</h3>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="link_product">
                <input type="hidden" name="supplier_id" value="<?= $viewId ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Product *</label>
                    <select name="product_id" required class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                        <option value="">— Select Product —</option>
                        <?php foreach ($unlinkable as $ap): ?>
                        <option value="<?= $ap['id'] ?>"><?= e($ap['name']) ?><?= $ap['sku'] ? ' ('.$ap['sku'].')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Supplier SKU</label>
                        <input type="text" name="supplier_sku" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cost Price (৳)</label>
                        <input type="number" name="cost_price" step="0.01" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Min Order Qty</label>
                        <input type="number" name="min_order_qty" value="1" min="1" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Lead Days</label>
                        <input type="number" name="lead_days" min="0" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" name="is_preferred" class="accent-orange-500">
                    <span class="text-gray-700">Mark as preferred supplier for this product</span>
                </label>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="btn-primary flex-1 py-2.5 text-sm">Link Product</button>
                    <button type="button" onclick="document.getElementById('linkModal').classList.add('hidden')" class="btn-secondary flex-1 py-2.5 text-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php

// ── Edit Supplier Modal (used in profile view) ──────────────────────────────
} else {
    // ── List View ──────────────────────────────────────────────────────────
    $search = sanitize($_GET['search'] ?? '');
    $status = $_GET['status'] ?? '';

    $where = "WHERE 1=1";
    $params = [];
    if ($search) { $where .= " AND (s.name LIKE ? OR s.company LIKE ? OR s.phone LIKE ?)"; $params = array_fill(0, 3, "%$search%"); }
    if ($status === 'active')   $where .= " AND s.is_active=1";
    if ($status === 'inactive') $where .= " AND s.is_active=0";

    $suppliers = $db->fetchAll("
        SELECT s.*,
            (SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END),0) FROM supplier_transactions WHERE supplier_id=s.id) as balance,
            (SELECT COUNT(*) FROM supplier_products WHERE supplier_id=s.id) as product_count,
            (SELECT COUNT(*) FROM supplier_products sp JOIN products p ON sp.product_id=p.id WHERE sp.supplier_id=s.id AND p.stock_quantity <= p.low_stock_threshold AND p.is_active=1) as low_stock_count
        FROM suppliers s $where
        ORDER BY s.name", $params) ?: [];

    require_once __DIR__ . '/../includes/header.php';
    ?>

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Suppliers</h1>
            <p class="text-sm text-gray-500"><?= count($suppliers) ?> supplier<?= count($suppliers)!=1?'s':'' ?></p>
        </div>
        <button onclick="document.getElementById('supplierModal').classList.remove('hidden')" class="btn-primary px-5 py-2.5 text-sm">+ Add Supplier</button>
    </div>

    <?php if ($msg): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
        <?= ['saved'=>'Saved ✓','deleted'=>'Deleted'][$msg] ?? $msg ?>
    </div><?php endif; ?>

    <!-- Search + filter -->
    <form method="GET" class="flex gap-2 mb-6 flex-wrap">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search name, company, phone..." class="border rounded-xl px-4 py-2 text-sm flex-1 min-w-[200px] focus:ring-2 focus:ring-orange-400 outline-none">
        <select name="status" onchange="this.form.submit()" class="border rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
            <option value="">All Status</option>
            <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
        <button class="btn-secondary px-4 py-2 text-sm">Search</button>
    </form>

    <?php if (empty($suppliers)): ?>
    <div class="bg-white rounded-2xl border p-16 text-center">
        <svg class="w-12 h-12 text-gray-200 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        <p class="text-gray-400 font-medium">No suppliers yet</p>
        <p class="text-gray-300 text-sm mt-1">Click "Add Supplier" to get started</p>
    </div>
    <?php else: ?>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($suppliers as $s):
            $bal = floatval($s['balance'] ?? 0);
        ?>
        <div class="bg-white rounded-2xl border hover:shadow-md transition-shadow p-5">
            <div class="flex items-start justify-between mb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-700 rounded-xl flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                        <?= strtoupper(substr($s['name'],0,1)) ?>
                    </div>
                    <div>
                        <a href="<?= adminUrl("pages/suppliers.php?view={$s['id']}") ?>" class="font-semibold text-gray-900 hover:text-orange-600"><?= e($s['name']) ?></a>
                        <?php if ($s['company']): ?><p class="text-xs text-gray-400"><?= e($s['company']) ?></p><?php endif; ?>
                    </div>
                </div>
                <span class="text-xs px-2 py-0.5 rounded-full <?= $s['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $s['is_active']?'Active':'Inactive' ?></span>
            </div>
            <div class="flex justify-between text-xs text-gray-500 mb-3">
                <?php if ($s['phone']): ?><span>📞 <?= e($s['phone']) ?></span><?php endif; ?>
                <?php if ($s['city']): ?><span>📍 <?= e($s['city']) ?></span><?php endif; ?>
                <?php if ($s['avg_delivery_days']): ?><span>🚚 <?= $s['avg_delivery_days'] ?>d</span><?php endif; ?>
            </div>
            <div class="flex gap-2 text-xs">
                <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded-lg"><?= $s['product_count'] ?> products</span>
                <?php if ($s['low_stock_count'] > 0): ?>
                <span class="bg-yellow-100 text-yellow-700 px-2 py-1 rounded-lg font-medium">⚠ <?= $s['low_stock_count'] ?> low stock</span>
                <?php endif; ?>
                <span class="ml-auto font-semibold <?= $bal>0?'text-orange-600':($bal<0?'text-blue-600':'text-gray-400') ?>">
                    <?= $bal==0 ? 'Settled' : (($bal>0?'Owe: ':'Recv: ').'৳'.number_format(abs($bal))) ?>
                </span>
            </div>
            <div class="flex gap-2 mt-3 pt-3 border-t">
                <a href="<?= adminUrl("pages/suppliers.php?view={$s['id']}") ?>" class="btn-secondary text-xs py-1.5 px-3 flex-1 text-center">View Profile</a>
                <a href="<?= adminUrl("pages/suppliers.php?view={$s['id']}&tab=ledger") ?>" class="btn-secondary text-xs py-1.5 px-3 flex-1 text-center">Ledger</a>
                <button onclick='openEditSupplier(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)' class="btn-secondary text-xs py-1.5 px-3">Edit</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php } ?>

<!-- ── Add/Edit Supplier Modal ─────────────────────────────────────────────── -->
<div id="supplierModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="sticky top-0 bg-white border-b px-6 py-4 flex items-center justify-between rounded-t-2xl">
            <h3 class="font-bold text-gray-900 text-lg" id="modalTitle">Add Supplier</h3>
            <button onclick="document.getElementById('supplierModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="p-6 space-y-5">
            <input type="hidden" name="action" value="save_supplier">
            <input type="hidden" name="id" id="supId" value="0">

            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2 md:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier Name *</label>
                    <input type="text" name="name" id="supName" required class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company</label>
                    <input type="text" name="company" id="supCompany" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="tel" name="phone" id="supPhone" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Alt Phone</label>
                    <input type="tel" name="alt_phone" id="supAltPhone" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="supEmail" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <input type="text" name="address" id="supAddress" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                    <input type="text" name="city" id="supCity" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">District</label>
                    <input type="text" name="district" id="supDistrict" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
            </div>

            <hr class="border-gray-100">
            <p class="text-sm font-semibold text-gray-700">Payment Details</p>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                    <select name="payment_method" id="supPayMethod" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                        <option value="">— Select —</option>
                        <option>Cash</option><option>Bank Transfer</option><option>bKash</option><option>Nagad</option><option>Cheque</option><option>Credit</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Credit Limit (৳)</label>
                    <input type="number" name="credit_limit" id="supCreditLimit" step="0.01" value="0" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bank Name</label>
                    <input type="text" name="bank_name" id="supBankName" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bank Account</label>
                    <input type="text" name="bank_account" id="supBankAccount" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bank Branch</label>
                    <input type="text" name="bank_branch" id="supBankBranch" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">bKash</label>
                    <input type="text" name="bkash" id="supBkash" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nagad</label>
                    <input type="text" name="nagad" id="supNagad" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Avg Delivery (days)</label>
                    <input type="number" name="avg_delivery_days" id="supDeliveryDays" min="0" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div class="col-span-2" id="obRow">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Opening Balance (৳) <span class="text-gray-400 font-normal text-xs">— New supplier only</span></label>
                    <input type="number" name="opening_balance" id="supOb" step="0.01" value="0" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" id="supNotes" rows="3" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none resize-none"></textarea>
            </div>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="is_active" id="supActive" checked class="accent-orange-500">
                <span class="text-sm text-gray-700">Active</span>
            </label>

            <div class="flex gap-3 pt-2 sticky bottom-0 bg-white pb-1">
                <button type="submit" class="btn-primary flex-1 py-2.5 text-sm">Save Supplier</button>
                <button type="button" onclick="document.getElementById('supplierModal').classList.add('hidden')" class="btn-secondary flex-1 py-2.5 text-sm">Cancel</button>
                <form method="POST" class="inline" id="deleteSupForm" style="display:none">
                    <input type="hidden" name="action" value="delete_supplier">
                    <input type="hidden" name="id" id="deleteSupId">
                    <button type="submit" onclick="return confirm('Delete supplier? This removes all transactions and product links.')" class="bg-red-50 text-red-600 border border-red-200 rounded-xl px-4 py-2.5 text-sm hover:bg-red-100">Delete</button>
                </form>
            </div>
        </form>
    </div>
</div>

<script>
function openEditSupplier(s) {
    const m = document.getElementById('supplierModal');
    document.getElementById('modalTitle').textContent = s.id ? 'Edit Supplier' : 'Add Supplier';
    document.getElementById('supId').value          = s.id || 0;
    document.getElementById('supName').value        = s.name || '';
    document.getElementById('supCompany').value     = s.company || '';
    document.getElementById('supPhone').value       = s.phone || '';
    document.getElementById('supAltPhone').value    = s.alt_phone || '';
    document.getElementById('supEmail').value       = s.email || '';
    document.getElementById('supAddress').value     = s.address || '';
    document.getElementById('supCity').value        = s.city || '';
    document.getElementById('supDistrict').value    = s.district || '';
    document.getElementById('supPayMethod').value   = s.payment_method || '';
    document.getElementById('supCreditLimit').value = s.credit_limit || 0;
    document.getElementById('supBankName').value    = s.bank_name || '';
    document.getElementById('supBankAccount').value = s.bank_account || '';
    document.getElementById('supBankBranch').value  = s.bank_branch || '';
    document.getElementById('supBkash').value       = s.bkash || '';
    document.getElementById('supNagad').value       = s.nagad || '';
    document.getElementById('supDeliveryDays').value= s.avg_delivery_days || '';
    document.getElementById('supNotes').value       = s.notes || '';
    document.getElementById('supActive').checked    = s.is_active != 0;
    // Opening balance only for new
    document.getElementById('obRow').style.display  = s.id ? 'none' : '';
    // Delete button
    const df = document.getElementById('deleteSupForm');
    if (s.id) { df.style.display='inline'; document.getElementById('deleteSupId').value = s.id; }
    else       { df.style.display='none'; }
    m.classList.remove('hidden');
}
// Close modal on backdrop click
document.getElementById('supplierModal').addEventListener('click', function(e){
    if (e.target === this) this.classList.add('hidden');
});
<?php if (isset($viewId) && $viewId): ?>
document.getElementById('txModal')?.addEventListener('click', function(e){ if(e.target===this) this.classList.add('hidden'); });
document.getElementById('linkModal')?.addEventListener('click', function(e){ if(e.target===this) this.classList.add('hidden'); });
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
