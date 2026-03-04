<?php
/**
 * Decrease Stock List - History of all stock decreases
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Decrease Stock List';
$db = Database::getInstance();

$search = $_GET['search'] ?? '';
$warehouseFilter = (int)($_GET['warehouse'] ?? 0);
$reasonFilter = $_GET['reason'] ?? '';
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$page = max(1, (int)($_GET['p'] ?? 1));

$where = "WHERE sm.movement_type='out' AND DATE(sm.created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($search) { $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($warehouseFilter) { $where .= " AND sm.warehouse_id=?"; $params[] = $warehouseFilter; }
if ($reasonFilter) { $where .= " AND sm.reference_type=?"; $params[] = $reasonFilter; }

$total = $db->fetch("SELECT COUNT(*) as cnt FROM stock_movements sm LEFT JOIN products p ON sm.product_id=p.id $where", $params)['cnt'] ?? 0;
$items = $db->fetchAll("SELECT sm.*, p.name as product_name, p.sku, p.featured_image, w.name as warehouse_name, au.full_name as created_by_name
    FROM stock_movements sm
    LEFT JOIN products p ON sm.product_id=p.id
    LEFT JOIN warehouses w ON sm.warehouse_id=w.id
    LEFT JOIN admin_users au ON sm.created_by=au.id
    $where ORDER BY sm.created_at DESC
    LIMIT " . ADMIN_ITEMS_PER_PAGE . " OFFSET " . (($page-1)*ADMIN_ITEMS_PER_PAGE), $params);

$totalQty = $db->fetch("SELECT COALESCE(SUM(sm.quantity),0) as total FROM stock_movements sm LEFT JOIN products p ON sm.product_id=p.id $where", $params)['total'] ?? 0;
$warehouses = $db->fetchAll("SELECT * FROM warehouses WHERE is_active=1 ORDER BY name");
$pagination = paginate($total, $page, ADMIN_ITEMS_PER_PAGE, adminUrl('pages/stock-decrease-list.php?') . http_build_query(array_filter(['search'=>$search,'warehouse'=>$warehouseFilter,'reason'=>$reasonFilter,'from'=>$dateFrom,'to'=>$dateTo])));

$reasonLabels = ['damaged'=>'Damaged','expired'=>'Expired','lost'=>'Lost/Missing','theft'=>'Theft','returned_to_supplier'=>'Return to Supplier','sample'=>'Sample/Giveaway','audit_adjustment'=>'Audit Adjustment','other'=>'Other'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="bg-white rounded-xl border shadow-sm">
    <!-- Filters -->
    <div class="p-4 border-b">
        <form class="flex flex-wrap gap-3 items-center">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search product..." class="flex-1 min-w-[150px] border rounded-lg px-3 py-2 text-sm">
            <select name="warehouse" class="border rounded-lg px-3 py-2 text-sm">
                <option value="">All Warehouses</option>
                <?php foreach ($warehouses as $wh): ?>
                <option value="<?= $wh['id'] ?>" <?= $warehouseFilter==$wh['id']?'selected':'' ?>><?= e($wh['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="reason" class="border rounded-lg px-3 py-2 text-sm">
                <option value="">All Reasons</option>
                <?php foreach ($reasonLabels as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $reasonFilter===$k?'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?= $dateFrom ?>" class="border rounded-lg px-3 py-2 text-sm">
            <input type="date" name="to" value="<?= $dateTo ?>" class="border rounded-lg px-3 py-2 text-sm">
            <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700">Filter</button>
            <a href="<?= adminUrl('pages/stock-decrease-new.php') ?>" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-red-700 ml-auto">+ New Decrease</a>
        </form>
    </div>

    <!-- Summary Bar -->
    <div class="px-4 py-3 bg-red-50 border-b flex items-center gap-6 text-sm">
        <span class="text-gray-600">Total Records: <strong class="text-gray-800"><?= number_format($total) ?></strong></span>
        <span class="text-gray-600">Total Units Decreased: <strong class="text-red-600"><?= number_format($totalQty) ?></strong></span>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Product</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Warehouse</th>
                <th class="text-center px-4 py-3 font-medium text-gray-600">Qty</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Reason</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Note</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">By</th>
            </tr></thead>
            <tbody class="divide-y">
                <?php foreach ($items as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap"><?= date('d M Y, h:i A', strtotime($item['created_at'])) ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <?php if ($item['featured_image']): ?>
                            <img src="<?= uploadUrl($item['featured_image']) ?>" class="w-8 h-8 rounded object-cover" alt="">
                            <?php endif; ?>
                            <div>
                                <p class="font-medium text-gray-800"><?= e($item['product_name'] ?? 'Unknown') ?></p>
                                <p class="text-xs text-gray-400"><?= e($item['sku'] ?? '') ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-600"><?= e($item['warehouse_name'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-center"><span class="bg-red-100 text-red-700 px-2 py-0.5 rounded-full text-xs font-semibold">-<?= $item['quantity'] ?></span></td>
                    <td class="px-4 py-3"><span class="px-2 py-1 bg-gray-100 rounded-full text-xs"><?= $reasonLabels[$item['reference_type'] ?? ''] ?? ucfirst($item['reference_type'] ?? 'N/A') ?></span></td>
                    <td class="px-4 py-3 text-gray-500 max-w-[200px] truncate"><?= e($item['note'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-gray-500"><?= e($item['created_by_name'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No decrease records found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (($pagination['total_pages'] ?? 0) > 1): ?>
    <div class="p-4 border-t"><?= renderPagination($pagination) ?></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
