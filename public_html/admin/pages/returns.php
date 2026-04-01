<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Returns';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_return') {
        $rid = intval($_POST['return_id']);
        $newStatus = sanitize($_POST['status']);
        $refundType = sanitize($_POST['refund_type'] ?? 'cash');
        $refundAmount = floatval($_POST['refund_amount'] ?? 0);
        
        $db->update('return_orders', [
            'return_status' => $newStatus,
            'admin_notes' => sanitize($_POST['admin_notes'] ?? ''),
            'refund_amount' => $refundAmount,
            'refund_type' => $refundType,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$rid]);
        
        // If refunded via store credit, process it
        if ($newStatus === 'refunded' && $refundType === 'store_credit' && $refundAmount > 0) {
            try {
                $result = refundToStoreCredit($rid, $refundAmount, getAdminId());
                if (!$result) {
                    // Customer is guest - fall back to cash
                    $db->update('return_orders', ['refund_type' => 'cash'], 'id = ?', [$rid]);
                }
            } catch (\Throwable $e) {
                error_log("Store credit refund error: " . $e->getMessage());
            }
        }
        
        logActivity(getAdminId(), 'update', 'return_orders', $rid);
        redirect(adminUrl('pages/returns.php?msg=updated'));
    }
}

$status = $_GET['status'] ?? '';
$where = '1=1';
$params = [];
if ($status) { $where .= " AND r.return_status = ?"; $params[] = $status; }

$returns = $db->fetchAll(
    "SELECT r.*, o.order_number, o.customer_name, o.customer_phone, o.customer_id, o.total as order_total,
     c.password as customer_has_password, c.store_credit as customer_credit
     FROM return_orders r 
     LEFT JOIN orders o ON o.id = r.order_id 
     LEFT JOIN customers c ON c.id = o.customer_id
     WHERE {$where} ORDER BY r.created_at DESC LIMIT 50", $params
);

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm">Return updated.</div>
<?php endif; ?>

<div class="flex gap-2 mb-6">
    <a href="?status=" class="px-3 py-1.5 rounded-lg text-xs font-medium <?= !$status ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">All</a>
    <?php foreach (['requested'=>'Requested','approved'=>'Approved','received'=>'Received','refunded'=>'Refunded','rejected'=>'Rejected'] as $k=>$v): ?>
    <a href="?status=<?= $k ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium <?= $status === $k ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>"><?= $v ?></a>
    <?php endforeach; ?>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Return ID</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Order</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Customer</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Reason</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Refund</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Date</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($returns as $r): 
                    $isRegistered = !empty($r['customer_has_password']);
                    $currentRefundType = $r['refund_type'] ?? 'cash';
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium">#RTN-<?= $r['id'] ?></td>
                    <td class="px-4 py-3"><a href="<?= adminUrl('pages/order-view.php?id=' . $r['order_id']) ?>" class="text-blue-600 hover:underline">#<?= e($r['order_number']) ?></a></td>
                    <td class="px-4 py-3">
                        <?= e($r['customer_name']) ?>
                        <?php if ($isRegistered): ?><span class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded-full ml-1">Registered</span><?php endif; ?>
                        <br><span class="text-xs text-gray-500"><?= e($r['customer_phone']) ?></span>
                        <?php if ($isRegistered && ($r['customer_credit'] ?? 0) > 0): ?>
                        <br><span class="text-xs text-yellow-600"><i class="fas fa-coins"></i> Credit: ৳<?= number_format($r['customer_credit']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 max-w-[200px] truncate"><?= e($r['return_reason'] ?? '') ?></td>
                    <td class="px-4 py-3">
                        <form method="POST" class="space-y-1.5" id="return-form-<?= $r['id'] ?>">
                            <input type="hidden" name="action" value="update_return">
                            <input type="hidden" name="return_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="admin_notes" value="<?= e($r['admin_notes'] ?? '') ?>">
                            <input type="number" name="refund_amount" value="<?= $r['refund_amount'] ?>" 
                                   class="w-20 text-xs px-2 py-1 rounded border text-right" step="0.01" placeholder="৳">
                            <!-- Refund Type -->
                            <div class="flex gap-1">
                                <label class="flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded border cursor-pointer has-[:checked]:bg-green-100 has-[:checked]:border-green-400">
                                    <input type="radio" name="refund_type" value="cash" <?= $currentRefundType === 'cash' ? 'checked' : '' ?> class="hidden">
                                    <i class="fas fa-money-bill-wave text-green-500"></i> Cash
                                </label>
                                <?php if ($isRegistered): ?>
                                <label class="flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded border cursor-pointer has-[:checked]:bg-yellow-100 has-[:checked]:border-yellow-400">
                                    <input type="radio" name="refund_type" value="store_credit" <?= $currentRefundType === 'store_credit' ? 'checked' : '' ?> class="hidden">
                                    <i class="fas fa-coins text-yellow-500"></i> Credit
                                </label>
                                <?php endif; ?>
                            </div>
                        </form>
                    </td>
                    <td class="px-4 py-3">
                        <select form="return-form-<?= $r['id'] ?>" name="status" onchange="document.getElementById('return-form-<?= $r['id'] ?>').submit()" class="text-xs px-2 py-1 rounded border">
                            <?php foreach (['requested','approved','received','refunded','rejected'] as $st): ?>
                            <option value="<?= $st ?>" <?= ($r['return_status'] ?? '') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                    <td class="px-4 py-3"><a href="<?= adminUrl('pages/order-view.php?id=' . $r['order_id']) ?>" class="text-blue-600 text-xs hover:underline">View Order</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($returns)): ?><tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">No returns</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
