<?php
/**
 * Supplier Management — list, profile, debit/credit ledger, linked products
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Supplier Management';
$db = Database::getInstance();

// ── POST Handlers ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── Save / Update Supplier ── */
    if ($action === 'save_supplier') {
        $id   = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$name) { redirect(adminUrl('pages/suppliers.php?msg=err_name')); }

        $fields = [
            'name'             => sanitize($name),
            'company'          => sanitize($_POST['company'] ?? ''),
            'phone'            => sanitize($_POST['phone'] ?? ''),
            'alt_phone'        => sanitize($_POST['alt_phone'] ?? ''),
            'email'            => sanitize($_POST['email'] ?? ''),
            'address'          => sanitize($_POST['address'] ?? ''),
            'city'             => sanitize($_POST['city'] ?? ''),
            'district'         => sanitize($_POST['district'] ?? ''),
            'payment_details'  => sanitize($_POST['payment_details'] ?? ''),
            'avg_delivery_days'=> intval($_POST['avg_delivery_days'] ?? 0) ?: null,
            'credit_limit'     => floatval($_POST['credit_limit'] ?? 0),
            'notes'            => sanitize($_POST['notes'] ?? ''),
            'is_active'        => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($id > 0) {
            // Build UPDATE
            $sets   = implode(',', array_map(fn($k) => "`$k`=?", array_keys($fields)));
            $values = array_values($fields);
            $values[] = $id;
            $db->query("UPDATE suppliers SET $sets WHERE id=?", $values);
            redirect(adminUrl('pages/suppliers.php?msg=saved'));
        } else {
            // INSERT
            $fields['created_by']      = getAdminId() ?: null;
            $fields['opening_balance'] = floatval($_POST['opening_balance'] ?? 0);
            $cols   = implode(',', array_map(fn($k) => "`$k`", array_keys($fields)));
            $marks  = implode(',', array_fill(0, count($fields), '?'));
            $db->query("INSERT INTO suppliers ($cols) VALUES ($marks)", array_values($fields));
            $newId = $db->lastInsertId();
            if ($fields['opening_balance'] > 0 && $newId) {
                $db->query("INSERT INTO supplier_transactions (supplier_id,type,amount,reference_type,description,transaction_date,created_by) VALUES (?,?,?,'opening_balance','Opening Balance',CURDATE(),?)",
                    [$newId, 'debit', $fields['opening_balance'], $fields['created_by']]);
            }
            redirect(adminUrl('pages/suppliers.php?msg=saved'));
        }
    }

    /* ── Delete Supplier ── */
    if ($action === 'delete_supplier') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $db->query("DELETE FROM supplier_products WHERE supplier_id=?", [$id]);
            $db->query("DELETE FROM supplier_transactions WHERE supplier_id=?", [$id]);
            $db->query("DELETE FROM suppliers WHERE id=?", [$id]);
        }
        redirect(adminUrl('pages/suppliers.php?msg=deleted'));
    }

    /* ── Add Transaction ── */
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
                getAdminId() ?: null,
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

    /* ── Link Product ── */
    if ($action === 'link_product') {
        $sid = intval($_POST['supplier_id'] ?? 0);
        $pid = intval($_POST['product_id'] ?? 0);
        if ($sid && $pid) {
            $exists = $db->fetch("SELECT id FROM supplier_products WHERE supplier_id=? AND product_id=?", [$sid, $pid]);
            if (!$exists) {
                $db->query("INSERT INTO supplier_products (supplier_id,product_id,supplier_sku,cost_price,min_order_qty,lead_days,is_preferred,notes) VALUES (?,?,?,?,?,?,?,?)",
                    [$sid, $pid, sanitize($_POST['supplier_sku'] ?? ''),
                     floatval($_POST['cost_price'] ?? 0) ?: null,
                     intval($_POST['min_order_qty'] ?? 1),
                     intval($_POST['lead_days'] ?? 0) ?: null,
                     isset($_POST['is_preferred']) ? 1 : 0,
                     sanitize($_POST['notes'] ?? '')]);
            }
        }
        redirect(adminUrl("pages/suppliers.php?view={$sid}&tab=products&msg=linked"));
    }

    /* ── Unlink Product ── */
    if ($action === 'unlink_product') {
        $sid = intval($_POST['supplier_id'] ?? 0);
        $db->query("DELETE FROM supplier_products WHERE id=?", [intval($_POST['sp_id'] ?? 0)]);
        redirect(adminUrl("pages/suppliers.php?view={$sid}&tab=products&msg=unlinked"));
    }
}

// ── Route: view single supplier OR list ────────────────────────────────────
$viewId = intval($_GET['view'] ?? 0);
$tab    = $_GET['tab'] ?? 'overview';
$msg    = $_GET['msg'] ?? '';

// ── MSGS ───────────────────────────────────────────────────────────────────
$msgMap = [
    'saved'      => ['green','✓ Supplier saved successfully.'],
    'deleted'    => ['green','Supplier deleted.'],
    'tx_saved'   => ['green','✓ Transaction recorded.'],
    'tx_deleted' => ['green','Transaction deleted.'],
    'linked'     => ['green','✓ Product linked.'],
    'unlinked'   => ['green','Product removed.'],
    'err_name'   => ['red',  'Supplier name is required.'],
];
$msgHtml = '';
if ($msg && isset($msgMap[$msg])) {
    [$mc, $mt] = $msgMap[$msg];
    $msgHtml = "<div class='mb-4 p-3 bg-{$mc}-50 border border-{$mc}-200 text-{$mc}-700 rounded-xl text-sm'>{$mt}</div>";
}

// ═══════════════════════════════════════════════════════════════════════════
// SUPPLIER PROFILE VIEW
// ═══════════════════════════════════════════════════════════════════════════
if ($viewId) {
    $supplier = $db->fetch("SELECT * FROM suppliers WHERE id=?", [$viewId]);
    if (!$supplier) redirect(adminUrl('pages/suppliers.php'));

    $balRow = $db->fetch("SELECT
        COALESCE(SUM(CASE WHEN type='debit'  THEN amount ELSE 0 END),0) as td,
        COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END),0) as tc
        FROM supplier_transactions WHERE supplier_id=?", [$viewId]);
    $totalDebit  = floatval($balRow['td'] ?? 0);
    $totalCredit = floatval($balRow['tc'] ?? 0);
    $balance     = $totalDebit - $totalCredit;

    $ledger = $db->fetchAll("SELECT * FROM supplier_transactions WHERE supplier_id=? ORDER BY transaction_date DESC, id DESC", [$viewId]) ?: [];

    $linkedProducts = $db->fetchAll("SELECT sp.*, p.name, p.sku, p.stock_quantity, p.low_stock_threshold, p.featured_image
        FROM supplier_products sp JOIN products p ON sp.product_id=p.id
        WHERE sp.supplier_id=? ORDER BY p.stock_quantity ASC", [$viewId]) ?: [];

    $linkedIds   = array_column($linkedProducts, 'product_id');
    $allProducts = $db->fetchAll("SELECT id, name, sku FROM products WHERE is_active=1 ORDER BY name") ?: [];
    $unlinkable  = array_filter($allProducts, fn($p) => !in_array($p['id'], $linkedIds));

    require_once __DIR__ . '/../includes/header.php';
?>
<!-- Back breadcrumb -->
<div class="mb-4 flex items-center gap-2 text-sm">
    <a href="<?= adminUrl('pages/suppliers.php') ?>" class="text-gray-400 hover:text-orange-500 flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg> Suppliers
    </a>
    <span class="text-gray-300">/</span>
    <span class="text-gray-700 font-medium"><?= e($supplier['name']) ?></span>
    <?php if (!$supplier['is_active']): ?><span class="ml-1 text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">Inactive</span><?php endif; ?>
</div>
<?= $msgHtml ?>

<!-- Header card -->
<div class="bg-white rounded-2xl border shadow-sm p-5 mb-5">
    <div class="flex flex-col md:flex-row gap-5">
        <div class="w-12 h-12 bg-gradient-to-br from-orange-400 to-orange-600 rounded-xl flex items-center justify-center text-white text-xl font-bold flex-shrink-0">
            <?= strtoupper(mb_substr($supplier['name'],0,1)) ?>
        </div>
        <div class="flex-1">
            <h2 class="text-lg font-bold text-gray-900"><?= e($supplier['name']) ?></h2>
            <?php if ($supplier['company']): ?><p class="text-gray-500 text-sm"><?= e($supplier['company']) ?></p><?php endif; ?>
            <div class="flex flex-wrap gap-4 mt-2 text-xs text-gray-500">
                <?php if ($supplier['phone']): ?><span>📞 <?= e($supplier['phone']) ?></span><?php endif; ?>
                <?php if ($supplier['city']): ?><span>📍 <?= e($supplier['city']) ?><?= $supplier['district'] ? ', '.e($supplier['district']) : '' ?></span><?php endif; ?>
                <?php if ($supplier['avg_delivery_days']): ?><span>🚚 ~<?= $supplier['avg_delivery_days'] ?> days</span><?php endif; ?>
            </div>
        </div>
        <!-- Balance pills -->
        <div class="flex gap-3 flex-shrink-0 items-start flex-wrap">
            <div class="px-4 py-3 bg-red-50 rounded-xl text-center min-w-[90px]">
                <p class="text-[11px] text-gray-400 mb-0.5">Debit</p>
                <p class="font-bold text-red-600">৳<?= number_format($totalDebit) ?></p>
            </div>
            <div class="px-4 py-3 bg-green-50 rounded-xl text-center min-w-[90px]">
                <p class="text-[11px] text-gray-400 mb-0.5">Credit</p>
                <p class="font-bold text-green-600">৳<?= number_format($totalCredit) ?></p>
            </div>
            <div class="px-4 py-3 <?= $balance>0?'bg-orange-50':($balance<0?'bg-blue-50':'bg-gray-50') ?> rounded-xl text-center min-w-[90px]">
                <p class="text-[11px] text-gray-400 mb-0.5"><?= $balance>0?'We Owe':($balance<0?'They Owe':'Settled') ?></p>
                <p class="font-bold <?= $balance>0?'text-orange-600':($balance<0?'text-blue-600':'text-gray-400') ?>">৳<?= number_format(abs($balance)) ?></p>
            </div>
            <button onclick="openSupplierModal(<?= htmlspecialchars(json_encode($supplier),ENT_QUOTES) ?>)" class="btn-secondary text-sm px-4 py-2 self-start">Edit</button>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="flex gap-1 mb-5 bg-gray-100 p-1 rounded-xl w-fit">
    <?php foreach (['overview'=>'Overview','ledger'=>'Ledger','products'=>'Products ('.count($linkedProducts).')'] as $t=>$l): ?>
    <a href="<?= adminUrl("pages/suppliers.php?view={$viewId}&tab={$t}") ?>"
       class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab===$t?'bg-white shadow text-gray-900':'text-gray-500 hover:text-gray-700' ?>"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'overview'): ?>
<div class="grid md:grid-cols-2 gap-5">
    <div class="bg-white rounded-2xl border p-5">
        <h3 class="font-semibold text-gray-800 mb-4 text-sm uppercase tracking-wide text-gray-400">Contact</h3>
        <div class="space-y-2 text-sm">
            <?php foreach ([['Phone',$supplier['phone']],['Alt Phone',$supplier['alt_phone']],['Email',$supplier['email']],['Address',$supplier['address']],['City',$supplier['city']],['District',$supplier['district']]] as [$l,$v]):
                if (!$v) continue; ?>
            <div class="flex gap-3"><span class="text-gray-400 w-20 flex-shrink-0"><?= $l ?></span><span class="text-gray-800"><?= e($v) ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="bg-white rounded-2xl border p-5">
        <h3 class="font-semibold text-gray-800 mb-4 text-sm uppercase tracking-wide text-gray-400">Payment & Terms</h3>
        <div class="space-y-2 text-sm">
            <?php if ($supplier['payment_details']): ?>
            <p class="text-gray-700 whitespace-pre-line"><?= e($supplier['payment_details']) ?></p>
            <?php endif; ?>
            <?php if ($supplier['credit_limit'] > 0): ?>
            <div class="flex gap-3"><span class="text-gray-400 w-24">Credit Limit</span><span class="text-gray-800 font-medium">৳<?= number_format($supplier['credit_limit']) ?></span></div>
            <?php endif; ?>
            <?php if ($supplier['avg_delivery_days']): ?>
            <div class="flex gap-3"><span class="text-gray-400 w-24">Delivery</span><span class="text-gray-800"><?= $supplier['avg_delivery_days'] ?> days avg</span></div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($supplier['notes']): ?>
    <div class="bg-white rounded-2xl border p-5 md:col-span-2">
        <h3 class="font-semibold text-gray-400 mb-2 text-sm uppercase tracking-wide">Notes</h3>
        <p class="text-sm text-gray-700 whitespace-pre-line"><?= e($supplier['notes']) ?></p>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'ledger'): ?>
<div class="flex justify-between items-center mb-4">
    <h3 class="font-semibold text-gray-800">Transaction Ledger</h3>
    <button onclick="document.getElementById('txModal').classList.remove('hidden')" class="btn-primary text-sm px-4 py-2">+ Add Transaction</button>
</div>
<?php if (empty($ledger)): ?>
<div class="bg-white rounded-2xl border p-12 text-center text-gray-400 text-sm">No transactions yet.</div>
<?php else:
    // Build running balance oldest→newest
    $rows = array_reverse($ledger);
    $run  = floatval($supplier['opening_balance'] ?? 0);
    $enriched = [];
    foreach ($rows as $tx) {
        $run += $tx['type']==='debit' ? $tx['amount'] : -$tx['amount'];
        $tx['run'] = $run;
        $enriched[] = $tx;
    }
    $enriched = array_reverse($enriched);
?>
<div class="bg-white rounded-2xl border overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-gray-50 border-b text-xs uppercase text-gray-500">
            <tr>
                <th class="px-4 py-3 text-left">Date</th>
                <th class="px-4 py-3 text-left">Description</th>
                <th class="px-4 py-3 text-left">Type</th>
                <th class="px-4 py-3 text-left">Method</th>
                <th class="px-4 py-3 text-right">Debit</th>
                <th class="px-4 py-3 text-right">Credit</th>
                <th class="px-4 py-3 text-right">Balance</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            <?php foreach ($enriched as $tx): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-500"><?= date('d M Y', strtotime($tx['transaction_date'])) ?></td>
                <td class="px-4 py-3 font-medium text-gray-800 max-w-[200px] truncate"><?= e($tx['description']) ?></td>
                <td class="px-4 py-3">
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full
                        <?= $tx['reference_type']==='payment'?'bg-green-100 text-green-700':
                           ($tx['reference_type']==='purchase'?'bg-blue-100 text-blue-700':'bg-gray-100 text-gray-600') ?>">
                        <?= e(ucfirst($tx['reference_type'] ?? '')) ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-gray-400 text-xs"><?= e($tx['payment_method'] ?? '') ?></td>
                <td class="px-4 py-3 text-right font-medium text-red-500"><?= $tx['type']==='debit' ? '৳'.number_format($tx['amount'],2) : '' ?></td>
                <td class="px-4 py-3 text-right font-medium text-green-600"><?= $tx['type']==='credit' ? '৳'.number_format($tx['amount'],2) : '' ?></td>
                <td class="px-4 py-3 text-right font-semibold <?= $tx['run']>0?'text-orange-600':($tx['run']<0?'text-blue-600':'text-gray-400') ?>">৳<?= number_format($tx['run'],2) ?></td>
                <td class="px-4 py-3">
                    <form method="POST" class="inline" onsubmit="return confirm('Delete transaction?')">
                        <input type="hidden" name="action" value="delete_transaction">
                        <input type="hidden" name="tx_id" value="<?= $tx['id'] ?>">
                        <input type="hidden" name="supplier_id" value="<?= $viewId ?>">
                        <button class="text-xs text-red-400 hover:text-red-600 px-2 py-1">Del</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="bg-gray-50 border-t font-semibold text-sm">
            <tr>
                <td colspan="4" class="px-4 py-3 text-gray-600">Total</td>
                <td class="px-4 py-3 text-right text-red-500">৳<?= number_format($totalDebit,2) ?></td>
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
        <h3 class="font-bold text-gray-900 mb-5 text-lg">Add Transaction</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_transaction">
            <input type="hidden" name="supplier_id" value="<?= $viewId ?>">
            <div class="grid grid-cols-2 gap-3">
                <label class="flex items-center gap-2 p-3 border-2 rounded-xl cursor-pointer has-[:checked]:border-red-400 has-[:checked]:bg-red-50 transition">
                    <input type="radio" name="type" value="debit" checked>
                    <div><p class="font-semibold text-sm text-red-600">Debit</p><p class="text-xs text-gray-400">We owe them</p></div>
                </label>
                <label class="flex items-center gap-2 p-3 border-2 rounded-xl cursor-pointer has-[:checked]:border-green-400 has-[:checked]:bg-green-50 transition">
                    <input type="radio" name="type" value="credit">
                    <div><p class="font-semibold text-sm text-green-600">Credit</p><p class="text-xs text-gray-400">Payment made</p></div>
                </label>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (৳) *</label>
                    <input type="number" name="amount" step="0.01" min="0.01" required class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description *</label>
                <input type="text" name="description" required placeholder="e.g. Invoice #1234, Stock payment" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select name="reference_type" class="w-full border rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-orange-400">
                        <option value="purchase">Purchase</option>
                        <option value="payment">Payment</option>
                        <option value="return">Return</option>
                        <option value="adjustment">Adjustment</option>
                        <option value="manual">Manual</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Via</label>
                    <select name="payment_method" class="w-full border rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-orange-400">
                        <option value="">—</option>
                        <option>Cash</option><option>Bank Transfer</option><option>bKash</option><option>Nagad</option><option>Cheque</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit" class="btn-primary flex-1 py-2.5 text-sm">Save</button>
                <button type="button" onclick="document.getElementById('txModal').classList.add('hidden')" class="btn-secondary flex-1 py-2.5 text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($tab === 'products'): ?>
<div class="flex justify-between items-center mb-4">
    <h3 class="font-semibold text-gray-800">Products from this Supplier</h3>
    <button onclick="document.getElementById('linkModal').classList.remove('hidden')" class="btn-primary text-sm px-4 py-2">+ Link Product</button>
</div>
<?php
$spLow = array_filter($linkedProducts, fn($p) => $p['stock_quantity'] > 0 && $p['stock_quantity'] <= $p['low_stock_threshold']);
$spOut = array_filter($linkedProducts, fn($p) => $p['stock_quantity'] <= 0);
?>
<?php if (!empty($spOut) || !empty($spLow)): ?>
<div class="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm">
    <p class="font-semibold text-amber-700 mb-2">⚠ Stock Alert — Contact supplier to reorder:</p>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($spOut as $p): ?><span class="bg-red-100 text-red-700 px-2 py-0.5 rounded text-xs font-medium"><?= e($p['name']) ?> — OUT</span><?php endforeach; ?>
        <?php foreach ($spLow as $p): ?><span class="bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded text-xs font-medium"><?= e($p['name']) ?> — <?= $p['stock_quantity'] ?> left</span><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php if (empty($linkedProducts)): ?>
<div class="bg-white rounded-2xl border p-12 text-center text-gray-400 text-sm">No products linked yet.</div>
<?php else: ?>
<div class="bg-white rounded-2xl border overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-xs uppercase text-gray-500">
            <tr>
                <th class="px-4 py-3 text-left">Product</th>
                <th class="px-4 py-3 text-left">SKU</th>
                <th class="px-4 py-3 text-left">Sup. SKU</th>
                <th class="px-4 py-3 text-right">Cost</th>
                <th class="px-4 py-3 text-right">Stock</th>
                <th class="px-4 py-3 text-right">MOQ</th>
                <th class="px-4 py-3 text-right">Lead</th>
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
                        <?php if ($lp['featured_image']): ?><img src="<?= uploadUrl($lp['featured_image']) ?>" class="w-7 h-7 rounded object-cover"><?php endif; ?>
                        <div>
                            <p class="font-medium text-gray-800"><?= e($lp['name']) ?></p>
                            <?php if ($lp['is_preferred']): ?><span class="text-xs text-orange-500">★ Preferred</span><?php endif; ?>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3 text-gray-400 text-xs"><?= e($lp['sku'] ?? '—') ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= e($lp['supplier_sku'] ?? '—') ?></td>
                <td class="px-4 py-3 text-right"><?= $lp['cost_price'] ? '৳'.number_format($lp['cost_price'],2) : '—' ?></td>
                <td class="px-4 py-3 text-right">
                    <?php if ($isOut): ?><span class="text-xs font-bold bg-red-100 text-red-600 px-2 py-0.5 rounded-full">Out</span>
                    <?php elseif ($isLow): ?><span class="text-xs font-bold bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full"><?= $lp['stock_quantity'] ?> ⚠</span>
                    <?php else: ?><span class="font-medium text-green-600"><?= $lp['stock_quantity'] ?></span><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right text-gray-500"><?= $lp['min_order_qty'] ?></td>
                <td class="px-4 py-3 text-right text-gray-500"><?= $lp['lead_days'] ? $lp['lead_days'].'d' : '—' ?></td>
                <td class="px-4 py-3">
                    <form method="POST" class="inline" onsubmit="return confirm('Remove this product link?')">
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
                <select name="product_id" required class="w-full border rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-orange-400">
                    <option value="">— Select Product —</option>
                    <?php foreach ($unlinkable as $ap): ?>
                    <option value="<?= $ap['id'] ?>"><?= e($ap['name']) ?><?= $ap['sku'] ? ' ('.$ap['sku'].')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier SKU</label>
                    <input type="text" name="supplier_sku" class="w-full border rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-orange-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cost Price (৳)</label>
                    <input type="number" name="cost_price" step="0.01" class="w-full border rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-orange-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Min Order Qty</label>
                    <input type="number" name="min_order_qty" value="1" min="1" class="w-full border rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-orange-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lead Days</label>
                    <input type="number" name="lead_days" min="0" class="w-full border rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-orange-400">
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm cursor-pointer">
                <input type="checkbox" name="is_preferred" class="accent-orange-500">
                <span class="text-gray-700">Mark as preferred supplier for this product</span>
            </label>
            <div class="flex gap-3 pt-1">
                <button type="submit" class="btn-primary flex-1 py-2.5 text-sm">Link Product</button>
                <button type="button" onclick="document.getElementById('linkModal').classList.add('hidden')" class="btn-secondary flex-1 py-2.5 text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; // tab

// ═══════════════════════════════════════════════════════════════════════════
// LIST VIEW
// ═══════════════════════════════════════════════════════════════════════════
} else {
    $search  = sanitize($_GET['search'] ?? '');
    $status  = $_GET['status'] ?? '';
    $where   = "WHERE 1=1";
    $params  = [];
    if ($search) { $where .= " AND (s.name LIKE ? OR s.company LIKE ? OR s.phone LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }
    if ($status === 'active')   $where .= " AND s.is_active=1";
    if ($status === 'inactive') $where .= " AND s.is_active=0";

    $suppliers = $db->fetchAll("
        SELECT s.*,
            COALESCE((SELECT SUM(CASE WHEN type='debit' THEN amount ELSE -amount END) FROM supplier_transactions WHERE supplier_id=s.id),0) as balance,
            (SELECT COUNT(*) FROM supplier_products WHERE supplier_id=s.id) as product_count,
            (SELECT COUNT(*) FROM supplier_products sp2 JOIN products p2 ON sp2.product_id=p2.id WHERE sp2.supplier_id=s.id AND p2.stock_quantity <= p2.low_stock_threshold AND p2.is_active=1) as low_stock_count
        FROM suppliers s $where ORDER BY s.name", $params) ?: [];

    require_once __DIR__ . '/../includes/header.php';
?>
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Suppliers</h1>
        <p class="text-sm text-gray-400"><?= count($suppliers) ?> supplier<?= count($suppliers)!=1?'s':'' ?></p>
    </div>
    <button onclick="openSupplierModal(null)" class="btn-primary px-5 py-2.5 text-sm">+ Add Supplier</button>
</div>
<?= $msgHtml ?>
<!-- Filters -->
<form method="GET" class="flex gap-2 mb-6 flex-wrap">
    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search name, company, phone..." class="border rounded-xl px-4 py-2 text-sm flex-1 min-w-[200px] focus:ring-2 focus:ring-orange-400 outline-none">
    <select name="status" onchange="this.form.submit()" class="border rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-orange-400">
        <option value="">All Status</option>
        <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
    </select>
    <button class="btn-secondary px-4 py-2 text-sm">Search</button>
</form>
<?php if (empty($suppliers)): ?>
<div class="bg-white rounded-2xl border p-16 text-center">
    <svg class="w-12 h-12 text-gray-200 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
    <p class="text-gray-500 font-medium">No suppliers yet</p>
    <p class="text-gray-300 text-sm mt-1">Click "+ Add Supplier" to get started</p>
</div>
<?php else: ?>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($suppliers as $s):
        $bal = floatval($s['balance']);
    ?>
    <div class="bg-white rounded-2xl border hover:shadow-md transition p-5">
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-br from-orange-400 to-orange-600 rounded-xl flex items-center justify-center text-white font-bold flex-shrink-0">
                    <?= strtoupper(mb_substr($s['name'],0,1)) ?>
                </div>
                <div>
                    <a href="<?= adminUrl("pages/suppliers.php?view={$s['id']}") ?>" class="font-semibold text-gray-900 hover:text-orange-600 leading-tight"><?= e($s['name']) ?></a>
                    <?php if ($s['company']): ?><p class="text-xs text-gray-400"><?= e($s['company']) ?></p><?php endif; ?>
                </div>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full <?= $s['is_active']?'bg-green-100 text-green-600':'bg-gray-100 text-gray-400' ?>"><?= $s['is_active']?'Active':'Inactive' ?></span>
        </div>
        <div class="flex flex-wrap gap-3 text-xs text-gray-400 mb-3">
            <?php if ($s['phone']): ?><span>📞 <?= e($s['phone']) ?></span><?php endif; ?>
            <?php if ($s['city']): ?><span>📍 <?= e($s['city']) ?></span><?php endif; ?>
            <?php if ($s['avg_delivery_days']): ?><span>🚚 <?= $s['avg_delivery_days'] ?>d</span><?php endif; ?>
        </div>
        <div class="flex gap-2 text-xs flex-wrap">
            <span class="bg-gray-100 text-gray-500 px-2 py-1 rounded-lg"><?= $s['product_count'] ?> products</span>
            <?php if ($s['low_stock_count'] > 0): ?>
            <span class="bg-amber-100 text-amber-700 px-2 py-1 rounded-lg font-medium">⚠ <?= $s['low_stock_count'] ?> low stock</span>
            <?php endif; ?>
            <span class="ml-auto font-semibold <?= $bal>0?'text-orange-600':($bal<0?'text-blue-600':'text-gray-400') ?>">
                <?= $bal==0?'Settled':( ($bal>0?'Owe ':'Recv ').'৳'.number_format(abs($bal)) ) ?>
            </span>
        </div>
        <div class="flex gap-2 mt-3 pt-3 border-t">
            <a href="<?= adminUrl("pages/suppliers.php?view={$s['id']}") ?>" class="btn-secondary text-xs py-1.5 flex-1 text-center">Profile</a>
            <a href="<?= adminUrl("pages/suppliers.php?view={$s['id']}&tab=ledger") ?>" class="btn-secondary text-xs py-1.5 flex-1 text-center">Ledger</a>
            <button onclick='openSupplierModal(<?= htmlspecialchars(json_encode($s),ENT_QUOTES) ?>)' class="btn-secondary text-xs py-1.5 px-3">Edit</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php } // end list view ?>

<!-- ══════════════════════════════════════════════════════════════════════
     ADD / EDIT SUPPLIER MODAL — simplified
══════════════════════════════════════════════════════════════════════ -->
<div id="supplierModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h3 class="font-bold text-gray-900 text-lg" id="supModalTitle">Add Supplier</h3>
            <button onclick="document.getElementById('supplierModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="overflow-y-auto flex-1 p-6">
        <form method="POST" id="supForm" class="space-y-4">
            <input type="hidden" name="action" value="save_supplier">
            <input type="hidden" name="id" id="supId" value="0">

            <!-- Row 1: Name + Company -->
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2 sm:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier Name <span class="text-red-400">*</span></label>
                    <input type="text" name="name" id="supName" required placeholder="Full name" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company / Brand</label>
                    <input type="text" name="company" id="supCompany" placeholder="Optional" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
            </div>

            <!-- Row 2: Phone + Alt Phone -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="tel" name="phone" id="supPhone" placeholder="01XXXXXXXXX" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Alt Phone</label>
                    <input type="tel" name="alt_phone" id="supAltPhone" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
            </div>

            <!-- Row 3: Address + City -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <input type="text" name="address" id="supAddress" placeholder="Street / Area" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                    <input type="text" name="city" id="supCity" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">District</label>
                    <input type="text" name="district" id="supDistrict" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
            </div>

            <!-- Row 4: Payment details (single textarea) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Details</label>
                <textarea name="payment_details" id="supPayDetails" rows="3" placeholder="Bank name, account number, bKash/Nagad, routing info..." class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none resize-none"></textarea>
            </div>

            <!-- Row 5: Credit limit + Delivery days -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Credit Limit (৳)</label>
                    <input type="number" name="credit_limit" id="supCreditLimit" step="0.01" value="0" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Avg Delivery (days)</label>
                    <input type="number" name="avg_delivery_days" id="supDeliveryDays" min="0" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                </div>
            </div>

            <!-- Opening balance (new only) -->
            <div id="supObRow">
                <label class="block text-sm font-medium text-gray-700 mb-1">Opening Balance (৳) <span class="text-xs text-gray-400 font-normal">— How much we currently owe them</span></label>
                <input type="number" name="opening_balance" id="supOb" step="0.01" value="0" class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" id="supNotes" rows="2" placeholder="Any other details..." class="w-full border rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none resize-none"></textarea>
            </div>

            <label class="flex items-center gap-2 cursor-pointer text-sm">
                <input type="checkbox" name="is_active" id="supIsActive" checked class="accent-orange-500 w-4 h-4">
                <span class="text-gray-700 font-medium">Active supplier</span>
            </label>
        </form>
        </div>
        <!-- Sticky footer -->
        <div class="px-6 py-4 border-t flex gap-3">
            <button form="supForm" type="submit" class="btn-primary flex-1 py-2.5 text-sm">Save Supplier</button>
            <button type="button" onclick="document.getElementById('supplierModal').classList.add('hidden')" class="btn-secondary py-2.5 px-5 text-sm">Cancel</button>
            <form method="POST" id="supDeleteForm" class="hidden" onsubmit="return confirm('Delete this supplier? All transactions and product links will be removed.')">
                <input type="hidden" name="action" value="delete_supplier">
                <input type="hidden" name="id" id="supDeleteId">
                <button type="submit" class="bg-red-50 text-red-600 border border-red-200 rounded-xl px-4 py-2.5 text-sm hover:bg-red-100">Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
function openSupplierModal(s) {
    const isEdit = s && s.id;
    document.getElementById('supModalTitle').textContent = isEdit ? 'Edit Supplier' : 'Add Supplier';
    document.getElementById('supId').value           = isEdit ? s.id : 0;
    document.getElementById('supName').value         = s?.name || '';
    document.getElementById('supCompany').value      = s?.company || '';
    document.getElementById('supPhone').value        = s?.phone || '';
    document.getElementById('supAltPhone').value     = s?.alt_phone || '';
    document.getElementById('supAddress').value      = s?.address || '';
    document.getElementById('supCity').value         = s?.city || '';
    document.getElementById('supDistrict').value     = s?.district || '';
    document.getElementById('supPayDetails').value   = s?.payment_details || '';
    document.getElementById('supCreditLimit').value  = s?.credit_limit || 0;
    document.getElementById('supDeliveryDays').value = s?.avg_delivery_days || '';
    document.getElementById('supNotes').value        = s?.notes || '';
    document.getElementById('supIsActive').checked   = !isEdit || s.is_active != 0;
    // Opening balance only for new
    document.getElementById('supObRow').style.display = isEdit ? 'none' : '';
    // Delete button
    const df = document.getElementById('supDeleteForm');
    if (isEdit) { df.classList.remove('hidden'); document.getElementById('supDeleteId').value = s.id; }
    else        { df.classList.add('hidden'); }
    document.getElementById('supplierModal').classList.remove('hidden');
}
// Close on backdrop
document.getElementById('supplierModal').addEventListener('click', function(e){ if(e.target===this) this.classList.add('hidden'); });
<?php if ($viewId ?? 0): ?>
document.getElementById('txModal')?.addEventListener('click',   function(e){ if(e.target===this) this.classList.add('hidden'); });
document.getElementById('linkModal')?.addEventListener('click', function(e){ if(e.target===this) this.classList.add('hidden'); });
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
