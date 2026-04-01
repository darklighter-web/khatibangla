<?php
/**
 * Stock Transfer - Transfer stock between warehouses with approval workflow
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Stock Transfer';
$db = Database::getInstance();

// Ensure tables exist
try { $db->query("SELECT 1 FROM stock_transfers LIMIT 1"); } catch (Exception $e) {
    $db->query("CREATE TABLE IF NOT EXISTS stock_transfers (id int AUTO_INCREMENT PRIMARY KEY, transfer_number varchar(30) NOT NULL, from_warehouse_id int NOT NULL, to_warehouse_id int NOT NULL, notes text, status enum('pending','approved','completed','cancelled') DEFAULT 'pending', created_by int, approved_by int, approved_at datetime, completed_at datetime, created_at timestamp DEFAULT CURRENT_TIMESTAMP)");
}
try { $db->query("SELECT 1 FROM stock_transfer_items LIMIT 1"); } catch (Exception $e) {
    $db->query("CREATE TABLE IF NOT EXISTS stock_transfer_items (id int AUTO_INCREMENT PRIMARY KEY, transfer_id int NOT NULL, product_id int NOT NULL, quantity int NOT NULL DEFAULT 0, received_quantity int DEFAULT 0, KEY transfer_id(transfer_id))");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_transfer') {
        $fromWh = (int)($_POST['from_warehouse_id'] ?? 0);
        $toWh = (int)($_POST['to_warehouse_id'] ?? 0);
        $notes = sanitize($_POST['notes'] ?? '');
        $productIds = $_POST['product_ids'] ?? [];
        $quantities = $_POST['quantities'] ?? [];

        if ($fromWh && $toWh && $fromWh !== $toWh && !empty($productIds)) {
            $transferNum = 'TRF-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5));
            $db->query("INSERT INTO stock_transfers (transfer_number, from_warehouse_id, to_warehouse_id, notes, status, created_by) VALUES (?,?,?,?,'pending',?)",
                [$transferNum, $fromWh, $toWh, $notes, getAdminId()]);
            $transferId = $db->lastInsertId();

            foreach ($productIds as $i => $pid) {
                $qty = (int)($quantities[$i] ?? 0);
                if ($pid && $qty > 0) {
                    $db->query("INSERT INTO stock_transfer_items (transfer_id, product_id, quantity) VALUES (?,?,?)", [$transferId, (int)$pid, $qty]);
                }
            }
            logActivity(getAdminId(), 'transfer_created', 'stock_transfer', $transferId);
            redirect(adminUrl('pages/stock-transfer.php?msg=created'));
        }
    }

    if ($action === 'approve_transfer') {
        $id = (int)$_POST['transfer_id'];
        $db->query("UPDATE stock_transfers SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND status='pending'", [getAdminId(), $id]);
        logActivity(getAdminId(), 'transfer_approved', 'stock_transfer', $id);
        redirect(adminUrl('pages/stock-transfer.php?msg=approved'));
    }

    if ($action === 'complete_transfer') {
        $id = (int)$_POST['transfer_id'];
        $transfer = $db->fetch("SELECT * FROM stock_transfers WHERE id=? AND status='approved'", [$id]);
        if ($transfer) {
            $items = $db->fetchAll("SELECT * FROM stock_transfer_items WHERE transfer_id=?", [$id]);
            foreach ($items as $item) {
                // Decrease from source warehouse
                $db->query("UPDATE warehouse_stock SET quantity=GREATEST(0,quantity-?) WHERE warehouse_id=? AND product_id=?",
                    [$item['quantity'], $transfer['from_warehouse_id'], $item['product_id']]);

                // Increase in destination warehouse
                $existing = $db->fetch("SELECT id FROM warehouse_stock WHERE warehouse_id=? AND product_id=?",
                    [$transfer['to_warehouse_id'], $item['product_id']]);
                if ($existing) {
                    $db->query("UPDATE warehouse_stock SET quantity=quantity+? WHERE id=?", [$item['quantity'], $existing['id']]);
                } else {
                    $db->query("INSERT INTO warehouse_stock (warehouse_id, product_id, quantity) VALUES (?,?,?)",
                        [$transfer['to_warehouse_id'], $item['product_id'], $item['quantity']]);
                }

                // Record movements
                $db->query("INSERT INTO stock_movements (warehouse_id, product_id, movement_type, quantity, reference_type, reference_id, note, created_by) VALUES (?,?,'out',?,'transfer',?,?,?)",
                    [$transfer['from_warehouse_id'], $item['product_id'], $item['quantity'], $id, "Transfer to WH#".$transfer['to_warehouse_id'], getAdminId()]);
                $db->query("INSERT INTO stock_movements (warehouse_id, product_id, movement_type, quantity, reference_type, reference_id, note, created_by) VALUES (?,?,'in',?,'transfer',?,?,?)",
                    [$transfer['to_warehouse_id'], $item['product_id'], $item['quantity'], $id, "Transfer from WH#".$transfer['from_warehouse_id'], getAdminId()]);

                // Update main product stock
                $totalStock = $db->fetch("SELECT COALESCE(SUM(quantity),0) as total FROM warehouse_stock WHERE product_id=?", [$item['product_id']]);
                $db->query("UPDATE products SET stock_quantity=? WHERE id=?", [$totalStock['total'] ?? 0, $item['product_id']]);

                $db->query("UPDATE stock_transfer_items SET received_quantity=? WHERE id=?", [$item['quantity'], $item['id']]);
            }
            $db->query("UPDATE stock_transfers SET status='completed', completed_at=NOW() WHERE id=?", [$id]);
            logActivity(getAdminId(), 'transfer_completed', 'stock_transfer', $id);
        }
        redirect(adminUrl('pages/stock-transfer.php?msg=completed'));
    }

    if ($action === 'cancel_transfer') {
        $id = (int)$_POST['transfer_id'];
        $db->query("UPDATE stock_transfers SET status='cancelled' WHERE id=? AND status IN ('pending','approved')", [$id]);
        redirect(adminUrl('pages/stock-transfer.php?msg=cancelled'));
    }
}

$msg = $_GET['msg'] ?? '';
$tab = $_GET['tab'] ?? 'list';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1));

$warehouses = $db->fetchAll("SELECT * FROM warehouses WHERE is_active=1 ORDER BY is_default DESC, name");
$products = $db->fetchAll("SELECT id, name, sku, stock_quantity FROM products WHERE is_active=1 ORDER BY name LIMIT 500");

$where = "WHERE 1=1";
$params = [];
if ($statusFilter) { $where .= " AND st.status=?"; $params[] = $statusFilter; }

$total = $db->fetch("SELECT COUNT(*) as cnt FROM stock_transfers st $where", $params)['cnt'] ?? 0;
$transfers = $db->fetchAll("SELECT st.*, wf.name as from_name, wt.name as to_name, au.full_name as created_by_name, au2.full_name as approved_by_name,
    (SELECT COUNT(*) FROM stock_transfer_items WHERE transfer_id=st.id) as item_count,
    (SELECT SUM(quantity) FROM stock_transfer_items WHERE transfer_id=st.id) as total_qty
    FROM stock_transfers st
    LEFT JOIN warehouses wf ON st.from_warehouse_id=wf.id
    LEFT JOIN warehouses wt ON st.to_warehouse_id=wt.id
    LEFT JOIN admin_users au ON st.created_by=au.id
    LEFT JOIN admin_users au2 ON st.approved_by=au2.id
    $where ORDER BY st.created_at DESC
    LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET " . (($page-1)*ADMIN_ITEMS_PER_PAGE), $params);

$pagination = paginate($total, $page, ADMIN_ITEMS_PER_PAGE, adminUrl('pages/stock-transfer.php?') . http_build_query(array_filter(['status'=>$statusFilter])));

$statusColors = ['pending'=>'bg-yellow-100 text-yellow-700','approved'=>'bg-blue-100 text-blue-700','in_transit'=>'bg-purple-100 text-purple-700','completed'=>'bg-green-100 text-green-700','cancelled'=>'bg-red-100 text-red-700'];

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?><div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">Transfer <?= e($msg) ?> successfully!</div><?php endif; ?>

<!-- Tabs -->
<div class="flex gap-2 mb-6">
    <a href="?tab=list" class="px-4 py-2 rounded-lg text-sm font-medium <?= $tab==='list'?'bg-blue-600 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">Transfer List</a>
    <a href="?tab=new" class="px-4 py-2 rounded-lg text-sm font-medium <?= $tab==='new'?'bg-blue-600 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">New Transfer</a>
</div>

<?php if ($tab === 'new'): ?>
<!-- New Transfer Form -->
<div class="bg-white rounded-xl border shadow-sm p-6">
    <h3 class="font-semibold text-gray-800 mb-4">Create Stock Transfer</h3>
    <form method="POST" id="transferForm">
        <input type="hidden" name="action" value="create_transfer">
        <div class="grid md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">From Warehouse *</label>
                <select name="from_warehouse_id" required class="w-full border rounded-lg px-3 py-2.5 text-sm">
                    <option value="">Select source...</option>
                    <?php foreach ($warehouses as $wh): ?>
                    <option value="<?= $wh['id'] ?>"><?= e($wh['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">To Warehouse *</label>
                <select name="to_warehouse_id" required class="w-full border rounded-lg px-3 py-2.5 text-sm">
                    <option value="">Select destination...</option>
                    <?php foreach ($warehouses as $wh): ?>
                    <option value="<?= $wh['id'] ?>"><?= e($wh['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">Products to Transfer</label>
            <div id="transferItems" class="space-y-2">
                <div class="flex gap-2 items-center transfer-row">
                    <select name="product_ids[]" class="flex-1 border rounded-lg px-3 py-2 text-sm">
                        <option value="">Select product...</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (Stock: <?= $p['stock_quantity'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="quantities[]" min="1" placeholder="Qty" class="w-24 border rounded-lg px-3 py-2 text-sm">
                    <button type="button" onclick="this.closest('.transfer-row').remove()" class="text-red-500 hover:text-red-700 p-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
            </div>
            <button type="button" onclick="addTransferRow()" class="mt-2 text-blue-600 hover:text-blue-800 text-sm font-medium">+ Add Product</button>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea name="notes" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm"></textarea>
        </div>

        <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">Create Transfer Request</button>
    </form>
</div>

<script>
function addTransferRow() {
    const row = document.querySelector('.transfer-row').cloneNode(true);
    row.querySelectorAll('select,input').forEach(el => el.value = '');
    document.getElementById('transferItems').appendChild(row);
}
</script>

<?php else: ?>
<!-- Transfer List -->
<div class="bg-white rounded-xl border shadow-sm">
    <div class="p-4 border-b flex gap-3">
        <a href="?tab=list" class="px-3 py-1.5 rounded-lg text-xs font-medium <?= !$statusFilter?'bg-blue-600 text-white':'bg-gray-100 text-gray-600' ?>">All</a>
        <?php foreach (['pending','approved','completed','cancelled'] as $s): ?>
        <a href="?tab=list&status=<?= $s ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium <?= $statusFilter===$s?'bg-blue-600 text-white':'bg-gray-100 text-gray-600' ?>"><?= ucfirst($s) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Transfer #</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">From → To</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Items</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Total Qty</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Status</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Created</th>
                <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($transfers as $t): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-sm font-medium"><?= e($t['transfer_number']) ?></td>
                    <td class="px-4 py-3"><?= e($t['from_name'] ?? '-') ?> <span class="text-gray-400">→</span> <?= e($t['to_name'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-center"><?= $t['item_count'] ?></td>
                    <td class="px-4 py-3 text-center font-semibold"><?= $t['total_qty'] ?></td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $statusColors[$t['status']] ?? 'bg-gray-100' ?>"><?= ucfirst($t['status']) ?></span></td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= date('d M Y', strtotime($t['created_at'])) ?><br>by <?= e($t['created_by_name'] ?? '') ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($t['status'] === 'pending'): ?>
                        <form method="POST" class="inline"><input type="hidden" name="action" value="approve_transfer"><input type="hidden" name="transfer_id" value="<?= $t['id'] ?>">
                            <button class="text-blue-600 hover:text-blue-800 text-xs mr-2" onclick="return confirm('Approve this transfer?')">Approve</button></form>
                        <form method="POST" class="inline"><input type="hidden" name="action" value="cancel_transfer"><input type="hidden" name="transfer_id" value="<?= $t['id'] ?>">
                            <button class="text-red-600 hover:text-red-800 text-xs" onclick="return confirm('Cancel?')">Cancel</button></form>
                        <?php elseif ($t['status'] === 'approved'): ?>
                        <form method="POST" class="inline"><input type="hidden" name="action" value="complete_transfer"><input type="hidden" name="transfer_id" value="<?= $t['id'] ?>">
                            <button class="text-green-600 hover:text-green-800 text-xs" onclick="return confirm('Complete this transfer? Stock will be moved.')">Complete</button></form>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($transfers)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No transfers found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (($pagination['total_pages'] ?? 0) > 1): ?>
    <div class="p-4 border-t"><?= renderPagination($pagination) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
