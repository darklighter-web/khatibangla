<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Coupons';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $cid = intval($_POST['coupon_id'] ?? 0);
        $data = [
            'code'             => strtoupper(sanitize($_POST['code'])),
            'type'             => sanitize($_POST['type']),
            'value'            => floatval($_POST['value']),
            'min_order_amount' => floatval($_POST['min_order_amount'] ?? 0),
            'max_discount'     => floatval($_POST['max_discount'] ?? 0) ?: null,
            'usage_limit'      => intval($_POST['usage_limit'] ?? 0),
            'start_date'       => !empty($_POST['start_date']) ? str_replace('T', ' ', $_POST['start_date']) : null,
            'end_date'         => !empty($_POST['end_date']) ? str_replace('T', ' ', $_POST['end_date']) : null,
            'is_active'        => isset($_POST['is_active']) ? 1 : 0,
        ];
        try {
            if ($cid) { $db->update('coupons', $data, 'id = ?', [$cid]); }
            else { $db->insert('coupons', $data); }
            redirect(adminUrl('pages/coupons.php?msg=saved'));
        } catch (\Throwable $e) {
            redirect(adminUrl('pages/coupons.php?msg=error&detail=' . urlencode($e->getMessage())));
        }
    }
    if ($action === 'delete') {
        $db->delete('coupons', 'id = ?', [intval($_POST['coupon_id'])]);
        redirect(adminUrl('pages/coupons.php?msg=deleted'));
    }
}

$coupons = $db->fetchAll("SELECT * FROM coupons ORDER BY created_at DESC");
$edit = isset($_GET['edit']) ? $db->fetch("SELECT * FROM coupons WHERE id = ?", [intval($_GET['edit'])]) : null;

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="<?= $_GET['msg'] === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?> border px-4 py-3 rounded-lg mb-4 text-sm">
    <?= $_GET['msg'] === 'error' ? 'Error: ' . htmlspecialchars($_GET['detail'] ?? '') : 'Action completed.' ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h4 class="font-semibold text-gray-800 mb-4"><?= $edit ? '✏️ Edit Coupon' : '➕ Create Coupon' ?></h4>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="coupon_id" value="<?= $edit['id'] ?? 0 ?>">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Coupon Code *</label>
                <div class="flex gap-2">
                    <input type="text" name="code" id="couponCode" value="<?= e($edit['code'] ?? '') ?>" required class="flex-1 px-3 py-2 border rounded-lg text-sm uppercase" placeholder="e.g. SAVE20">
                    <button type="button" onclick="generateCode()" class="px-3 py-2 bg-gray-100 text-gray-600 rounded-lg text-xs font-medium hover:bg-gray-200" title="Auto-generate"><i class="fas fa-dice"></i></button>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="type" id="couponType" class="w-full px-3 py-2 border rounded-lg text-sm" onchange="toggleFields()">
                        <option value="percentage" <?= ($edit['type'] ?? '') === 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                        <option value="fixed" <?= ($edit['type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed Amount (৳)</option>
                        <option value="free_shipping" <?= ($edit['type'] ?? '') === 'free_shipping' ? 'selected' : '' ?>>Free Shipping</option>
                    </select></div>
                <div id="valueField"><label class="block text-sm font-medium text-gray-700 mb-1">Value</label>
                    <input type="number" name="value" value="<?= $edit['value'] ?? '' ?>" step="0.01" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
            </div>
            
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Min. Order (৳)</label>
                    <input type="number" name="min_order_amount" value="<?= $edit['min_order_amount'] ?? 0 ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                <div id="maxDiscountField"><label class="block text-sm font-medium text-gray-700 mb-1">Max Discount (৳)</label>
                    <input type="number" name="max_discount" value="<?= $edit['max_discount'] ?? '' ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="0 = unlimited"></div>
            </div>
            
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Usage Limit (0 = unlimited)</label>
                <input type="number" name="usage_limit" value="<?= $edit['usage_limit'] ?? 0 ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
            
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                    <input type="datetime-local" name="start_date" value="<?= !empty($edit['start_date']) ? date('Y-m-d\TH:i', strtotime($edit['start_date'])) : '' ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                    <input type="datetime-local" name="end_date" value="<?= !empty($edit['end_date']) ? date('Y-m-d\TH:i', strtotime($edit['end_date'])) : '' ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
            </div>
            
            <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?> class="rounded"><span class="text-sm">Active</span></label>
            
            <div class="flex gap-2">
                <button class="flex-1 bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700"><?= $edit ? '✓ Update' : '+ Create Coupon' ?></button>
                <?php if ($edit): ?><a href="<?= adminUrl('pages/coupons.php') ?>" class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Code</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Type / Value</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Min Order</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Uses</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Dates</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Actions</th>
                </tr></thead>
                <tbody class="divide-y">
                    <?php foreach ($coupons as $c):
                        $isExpired = $c['end_date'] && strtotime($c['end_date']) < time();
                        $isExhausted = $c['usage_limit'] > 0 && $c['used_count'] >= $c['usage_limit'];
                    ?>
                    <tr class="hover:bg-gray-50 <?= ($isExpired || $isExhausted) ? 'opacity-60' : '' ?>">
                        <td class="px-4 py-3"><span class="font-mono font-bold bg-gray-100 px-2 py-0.5 rounded text-xs"><?= e($c['code']) ?></span></td>
                        <td class="px-4 py-3">
                            <?php if ($c['type'] === 'percentage'): ?>
                                <span class="text-green-700 font-semibold"><?= $c['value'] ?>%</span>
                                <?php if ($c['max_discount']): ?><span class="text-xs text-gray-400"> (max ৳<?= number_format($c['max_discount']) ?>)</span><?php endif; ?>
                            <?php elseif ($c['type'] === 'fixed'): ?>
                                <span class="text-blue-700 font-semibold">৳<?= number_format($c['value']) ?></span>
                            <?php else: ?>
                                <span class="text-purple-700 font-semibold">Free Shipping</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-xs"><?= $c['min_order_amount'] > 0 ? '৳' . number_format($c['min_order_amount']) : '—' ?></td>
                        <td class="px-4 py-3 text-xs"><span class="font-semibold"><?= $c['used_count'] ?></span>/<?= $c['usage_limit'] ?: '∞' ?></td>
                        <td class="px-4 py-3 text-xs">
                            <?php if ($c['start_date'] || $c['end_date']): ?>
                                <?= $c['start_date'] ? date('M d', strtotime($c['start_date'])) : '—' ?> → <?= $c['end_date'] ? date('M d, Y', strtotime($c['end_date'])) : '∞' ?>
                                <?php if ($isExpired): ?><br><span class="text-red-500 font-semibold">Expired</span><?php endif; ?>
                            <?php else: ?>No limit<?php endif; ?>
                        </td>
                        <td class="px-4 py-3"><span class="px-2 py-0.5 text-xs rounded-full <?= $c['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td class="px-4 py-3 flex gap-1">
                            <a href="?edit=<?= $c['id'] ?>" class="p-1.5 rounded hover:bg-gray-100"><i class="fas fa-edit text-gray-500 text-xs"></i></a>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="coupon_id" value="<?= $c['id'] ?>"><button class="p-1.5 rounded hover:bg-red-50"><i class="fas fa-trash text-red-400 text-xs"></i></button></form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($coupons)): ?><tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">No coupons yet</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function generateCode() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let code = '';
    for (let i = 0; i < 8; i++) code += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('couponCode').value = code;
}
function toggleFields() {
    const t = document.getElementById('couponType').value;
    document.getElementById('valueField').style.display = t === 'free_shipping' ? 'none' : '';
    document.getElementById('maxDiscountField').style.display = t === 'percentage' ? '' : 'none';
}
toggleFields();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
