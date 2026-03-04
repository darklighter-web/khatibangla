<?php
/**
 * Admin Search Orders Page
 * Search by Invoice, Phone Number, Customer Name, or Courier ID
 */
$pageTitle = 'Search Orders';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();
$q = trim($_GET['q'] ?? '');
$field = $_GET['field'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$results = [];
$total = 0;

if ($q !== '') {
    $where = [];
    $params = [];
    $cleanPhone = preg_replace('/[^0-9]/', '', $q);

    if ($field === 'invoice') {
        $where[] = "(order_number LIKE ? OR order_number LIKE ?)";
        $params[] = "%{$q}%";
        $params[] = "%M{$q}%";
    } elseif ($field === 'phone') {
        $where[] = "customer_phone LIKE ?";
        $params[] = "%{$cleanPhone}%";
    } elseif ($field === 'name') {
        $where[] = "customer_name LIKE ?";
        $params[] = "%{$q}%";
    } else {
        $conds = ["order_number LIKE ?", "customer_name LIKE ?", "courier_tracking_id LIKE ?", "courier_consignment_id LIKE ?"];
        $params = array_merge($params, ["%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%"]);
        if (strlen($cleanPhone) >= 4) {
            $conds[] = "customer_phone LIKE ?";
            $params[] = "%{$cleanPhone}%";
        }
        $where[] = "(" . implode(" OR ", $conds) . ")";
    }
    $whereStr = implode(' AND ', $where);
    
    try {
        $cnt = $db->fetch("SELECT COUNT(*) as c FROM orders WHERE {$whereStr}", $params);
        $total = intval($cnt['c']);
    } catch (\Throwable $e) {}
    
    $offset = ($page - 1) * $perPage;
    try {
        $results = $db->fetchAll(
            "SELECT o.*, 
                    (SELECT GROUP_CONCAT(CONCAT(oi.product_name, ' (', oi.sku, ') x', oi.quantity) SEPARATOR ' | ') FROM order_items oi WHERE oi.order_id = o.id) as products_text,
                    (SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id) as item_count
             FROM orders o WHERE {$whereStr} ORDER BY o.created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
    } catch (\Throwable $e) { $results = []; }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-[1400px] mx-auto">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Search Orders</h1>
            <p class="text-xs text-gray-500">Search by Invoice, Phone Number, Customer Name, or Courier ID</p>
        </div>
        <?php if ($total > 0): ?>
        <span class="text-sm text-gray-500"><?= number_format($total) ?> result<?= $total !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </div>

    <!-- Search Tabs -->
    <div class="flex gap-1 mb-3 border-b">
        <?php foreach (['all'=>'All Fields', 'invoice'=>'Invoice', 'phone'=>'Mobile Number', 'name'=>'Customer Name'] as $fk => $fl): ?>
        <a href="?q=<?= urlencode($q) ?>&field=<?= $fk ?>" class="px-4 py-2 text-sm font-medium border-b-2 transition <?= $field === $fk ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>"><?= $fl ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Search Input -->
    <form method="GET" class="relative mb-5" id="searchForm">
        <input type="hidden" name="field" value="<?= e($field) ?>">
        <div class="relative">
            <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" name="q" id="searchInput" value="<?= e($q) ?>" placeholder="Type to search..." autofocus
                class="w-full pl-12 pr-12 py-3 border rounded-xl text-sm bg-white focus:ring-2 focus:ring-blue-200 focus:border-blue-400 outline-none">
            <?php if ($q): ?>
            <a href="?field=<?= e($field) ?>" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></a>
            <?php endif; ?>
        </div>
    </form>
    
    <!-- Live Results Container (for auto-search) -->
    <div id="liveResultsWrap" class="<?= $q ? 'hidden' : '' ?>">
        <div id="liveResults" class="bg-white rounded-xl border shadow-sm overflow-hidden"></div>
    </div>

    <script>
    let _searchTimer = null, _searchAbort = null;
    const searchInput = document.getElementById('searchInput');
    const liveWrap = document.getElementById('liveResultsWrap');
    const liveBox = document.getElementById('liveResults');
    const staticResults = document.getElementById('staticResults');
    const field = '<?= e($field) ?>';
    
    searchInput.addEventListener('input', function() {
        const q = this.value.trim();
        clearTimeout(_searchTimer);
        
        if (q.length < 2) {
            liveWrap.classList.add('hidden');
            if (staticResults) staticResults.classList.remove('hidden');
            return;
        }
        
        // Hide static results, show live
        if (staticResults) staticResults.classList.add('hidden');
        
        _searchTimer = setTimeout(() => {
            if (_searchAbort) _searchAbort.abort();
            _searchAbort = new AbortController();
            
            liveBox.innerHTML = '<div class="p-6 text-center text-gray-400 text-sm"><i class="fas fa-spinner fa-spin mr-2"></i>Searching...</div>';
            liveWrap.classList.remove('hidden');
            
            fetch('<?= adminUrl("api/search.php") ?>?q=' + encodeURIComponent(q) + '&limit=20&field=' + field, {signal: _searchAbort.signal})
            .then(r => r.json())
            .then(d => {
                if (!d.results || !d.results.length) {
                    liveBox.innerHTML = '<div class="p-8 text-center"><svg class="w-12 h-12 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><p class="text-gray-400">No results for "' + q + '"</p></div>';
                    return;
                }
                
                let h = '<div class="px-4 py-2 bg-gray-50 border-b flex items-center justify-between"><span class="text-xs font-semibold text-gray-500">' + d.total + ' result' + (d.total !== 1 ? 's' : '') + '</span><a href="?q=' + encodeURIComponent(q) + '&field=' + field + '" class="text-xs text-blue-600 font-medium">View full results →</a></div>';
                h += '<table class="w-full text-sm"><thead><tr class="bg-gray-50 text-left border-b"><th class="px-3 py-2 text-[10px] font-semibold text-gray-500">Date</th><th class="px-3 py-2 text-[10px] font-semibold text-gray-500">Invoice</th><th class="px-3 py-2 text-[10px] font-semibold text-gray-500">Customer</th><th class="px-3 py-2 text-[10px] font-semibold text-gray-500">Courier</th><th class="px-3 py-2 text-[10px] font-semibold text-gray-500">Products</th><th class="px-3 py-2 text-[10px] font-semibold text-gray-500">Status</th><th class="px-3 py-2 text-[10px] font-semibold text-gray-500 text-right">Total</th><th class="px-3 py-2 text-[10px] font-semibold text-gray-500">Actions</th></tr></thead><tbody>';
                
                d.results.forEach(r => {
                    h += `<tr class="border-b border-gray-50 hover:bg-blue-50/30 transition cursor-pointer" onclick="window.location='${r.url}'">
                        <td class="px-3 py-2.5"><p class="text-xs text-gray-700">${r.date.split(',')[0]}</p><p class="text-[10px] text-gray-400">${r.date.split(',')[1]||''}</p></td>
                        <td class="px-3 py-2.5"><span class="font-bold text-gray-800 text-xs">${r.order_number}</span></td>
                        <td class="px-3 py-2.5">
                            <p class="text-xs font-medium text-gray-800">${r.customer_name||'—'}</p>
                            <p class="text-[10px] text-gray-500">${r.customer_phone||''}</p>
                        </td>
                        <td class="px-3 py-2.5"><span class="text-[10px] text-gray-600">${r.courier_name||'—'}</span>${r.courier_status ? '<p class="text-[9px] text-gray-400">'+r.courier_status+'</p>' : ''}</td>
                        <td class="px-3 py-2.5"><p class="text-[10px] text-gray-600 truncate max-w-[150px]">${r.products||'—'}</p></td>
                        <td class="px-3 py-2.5"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${r.status_badge}">${(r.status||'').toUpperCase()}</span></td>
                        <td class="px-3 py-2.5 text-right"><span class="text-xs font-bold text-gray-800">৳${r.total}</span></td>
                        <td class="px-3 py-2.5">
                            <a href="${r.url}" class="p-1 rounded hover:bg-blue-100 text-blue-600 inline-block" onclick="event.stopPropagation()"><i class="fas fa-eye text-[10px]"></i></a>
                        </td>
                    </tr>`;
                });
                
                h += '</tbody></table>';
                if (d.total > 20) h += '<div class="px-4 py-2 bg-gray-50 border-t text-center"><a href="?q=' + encodeURIComponent(q) + '&field=' + field + '" class="text-xs text-blue-600 font-semibold">Show all ' + d.total + ' results →</a></div>';
                liveBox.innerHTML = h;
            })
            .catch(() => {});
        }, 250);
    });
    
    // On form submit, do full page search
    document.getElementById('searchForm').addEventListener('submit', function(e) {
        // Allow normal form submission for full page results
    });
    </script>

    <?php if ($q && count($results) > 0): ?>
    <!-- Static Results Table -->
    <div id="staticResults" class="bg-white rounded-xl border shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 border-b text-left">
                        <th class="px-3 py-2.5 font-semibold text-gray-600 text-xs">Date</th>
                        <th class="px-3 py-2.5 font-semibold text-gray-600 text-xs">Invoice</th>
                        <th class="px-3 py-2.5 font-semibold text-gray-600 text-xs">Customer</th>
                        <th class="px-3 py-2.5 font-semibold text-gray-600 text-xs">Delivery Method</th>
                        <th class="px-3 py-2.5 font-semibold text-gray-600 text-xs">Note</th>
                        <th class="px-3 py-2.5 font-semibold text-gray-600 text-xs">Products</th>
                        <th class="px-3 py-2.5 font-semibold text-gray-600 text-xs">Qty</th>
                        <th class="px-3 py-2.5 font-semibold text-gray-600 text-xs">Status</th>
                        <th class="px-3 py-2.5 font-semibold text-gray-600 text-xs text-right">Total</th>
                        <th class="px-3 py-2.5 font-semibold text-gray-600 text-xs">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($results as $r): 
                        // Success rate for this customer
                        $cPhone = preg_replace('/[^0-9]/', '', $r['customer_phone']);
                        $successRate = 0;
                        if (strlen($cPhone) >= 10) {
                            $ph = '%' . substr($cPhone, -10) . '%';
                            try {
                                $sr = $db->fetch("SELECT COUNT(*) as t, SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as d FROM orders WHERE customer_phone LIKE ?", [$ph]);
                                if ($sr['t'] > 0) $successRate = round(($sr['d'] / $sr['t']) * 100);
                            } catch (\Throwable $e) {}
                        }
                        $srColor = $successRate >= 70 ? 'text-green-600' : ($successRate >= 40 ? 'text-yellow-600' : 'text-red-500');
                    ?>
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-3 py-3">
                            <p class="text-xs text-gray-800"><?= date('d/m/Y', strtotime($r['created_at'])) ?></p>
                            <p class="text-[10px] text-gray-400"><?= date('g:i a', strtotime($r['created_at'])) ?></p>
                            <?php if ($r['updated_at']): ?><p class="text-[10px] text-gray-400">Updated <?= timeAgo($r['updated_at']) ?></p><?php endif; ?>
                        </td>
                        <td class="px-3 py-3">
                            <a href="<?= adminUrl('pages/order-view.php?id=' . $r['id']) ?>" class="font-bold text-gray-800 hover:text-blue-600"><?= e($r['order_number']) ?></a>
                        </td>
                        <td class="px-3 py-3">
                            <p class="text-sm font-medium text-gray-800">👤 <?= e($r['customer_name']) ?></p>
                            <p class="text-xs text-gray-500">📞 <?= e($r['customer_phone']) ?> <span class="font-bold <?= $srColor ?>"><?= $successRate ?>%</span></p>
                            <p class="text-[10px] text-gray-400 truncate max-w-[200px]" title="<?= e($r['customer_address']) ?>">📍 <?= e($r['customer_address']) ?></p>
                        </td>
                        <td class="px-3 py-3">
                            <span class="text-xs text-gray-700"><?= e($r['courier_name'] ?: $r['shipping_method'] ?: '—') ?></span>
                            <?php if ($r['courier_status']): ?><p class="text-[10px] text-gray-400"><?= e($r['courier_status']) ?></p><?php endif; ?>
                        </td>
                        <td class="px-3 py-3">
                            <p class="text-xs text-gray-500 truncate max-w-[120px]" title="<?= e($r['notes'] ?? '') ?>"><?= e($r['notes'] ?? '') ?: '—' ?></p>
                        </td>
                        <td class="px-3 py-3">
                            <div class="max-w-[200px]">
                                <?php 
                                $prods = explode(' | ', $r['products_text'] ?? '');
                                foreach (array_slice($prods, 0, 2) as $p): if (empty(trim($p))) continue; ?>
                                <p class="text-xs text-gray-700 truncate"><span class="px-1.5 py-0.5 rounded text-[10px] font-bold <?= getOrderStatusBadge($r['order_status']) ?>"><?= getOrderStatusLabel($r['order_status']) ?></span> <?= e($p) ?></p>
                                <?php endforeach; 
                                if (count($prods) > 2): ?><p class="text-[10px] text-gray-400">+<?= count($prods) - 2 ?> more</p><?php endif; ?>
                            </div>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <span class="text-sm font-medium"><?= intval($r['item_count'] ?? 0) ?></span>
                        </td>
                        <td class="px-3 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?= getOrderStatusBadge($r['order_status']) ?>"><?= getOrderStatusLabel($r['order_status']) ?></span>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <span class="font-bold text-gray-800">৳<?= number_format(floatval($r['total'])) ?></span>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex items-center gap-1">
                                <a href="<?= adminUrl('pages/order-view.php?id=' . $r['id']) ?>" class="p-1.5 rounded hover:bg-blue-50 text-blue-600" title="View"><i class="fas fa-eye text-xs"></i></a>
                                <a href="<?= adminUrl('pages/order-print.php?id=' . $r['id']) ?>" target="_blank" class="p-1.5 rounded hover:bg-gray-100 text-gray-500" title="Print"><i class="fas fa-print text-xs"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total > $perPage): ?>
        <div class="flex items-center justify-between px-4 py-3 border-t bg-gray-50">
            <p class="text-xs text-gray-500">Showing <?= $offset + 1 ?>-<?= min($offset + $perPage, $total) ?> of <?= $total ?></p>
            <div class="flex gap-1">
                <?php if ($page > 1): ?>
                <a href="?q=<?= urlencode($q) ?>&field=<?= $field ?>&page=<?= $page - 1 ?>" class="px-3 py-1 text-xs bg-white border rounded hover:bg-gray-50">← Prev</a>
                <?php endif; ?>
                <?php if ($page * $perPage < $total): ?>
                <a href="?q=<?= urlencode($q) ?>&field=<?= $field ?>&page=<?= $page + 1 ?>" class="px-3 py-1 text-xs bg-white border rounded hover:bg-gray-50">Next →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($q && count($results) === 0): ?>
    <div class="bg-white rounded-xl border p-12 text-center">
        <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <p class="text-gray-500 text-lg font-medium">No orders found for "<?= e($q) ?>"</p>
        <p class="text-gray-400 text-sm mt-1">Try a different search term or filter</p>
    </div>

    <?php elseif (!$q): ?>
    <div class="bg-white rounded-xl border p-12 text-center">
        <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <p class="text-gray-500 text-lg font-medium">Search for orders</p>
        <p class="text-gray-400 text-sm mt-1">Enter an invoice number, phone number, customer name, or courier tracking ID</p>
        <div class="flex flex-wrap justify-center gap-2 mt-6">
            <span class="px-3 py-1.5 bg-gray-100 rounded-full text-xs text-gray-600">📋 Invoice: M12345</span>
            <span class="px-3 py-1.5 bg-gray-100 rounded-full text-xs text-gray-600">📞 Phone: 01XXXXXXXXX</span>
            <span class="px-3 py-1.5 bg-gray-100 rounded-full text-xs text-gray-600">👤 Name: Customer name</span>
            <span class="px-3 py-1.5 bg-gray-100 rounded-full text-xs text-gray-600">🚚 Courier ID</span>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
