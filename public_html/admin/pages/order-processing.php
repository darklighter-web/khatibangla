<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Order Processing';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// ── POST: status update (same as order-management) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'update_status') {
        $id = intval($_POST['order_id']);
        $st = sanitize($_POST['status']);
        $db->update('orders', ['order_status'=>$st,'updated_at'=>date('Y-m-d H:i:s')], 'id=?', [$id]);
        try { $db->insert('order_status_history',['order_id'=>$id,'status'=>$st,'changed_by'=>getAdminId()]); } catch(\Throwable $e){}
        if ($st==='delivered') { try { awardOrderCredits($id); } catch(\Throwable $e){} try { $db->update('orders',['delivered_at'=>date('Y-m-d H:i:s')],'id=? AND delivered_at IS NULL',[$id]); } catch(\Throwable $e){} }
        if (in_array($st,['cancelled','returned'])) { try { refundOrderCreditsOnCancel($id); } catch(\Throwable $e){} }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'bulk_status') {
        $ids = $_POST['order_ids'] ?? []; $st = sanitize($_POST['bulk_status']);
        foreach ($ids as $id) {
            $id = intval($id);
            $db->update('orders',['order_status'=>$st,'updated_at'=>date('Y-m-d H:i:s')],'id=?',[$id]);
            try { $db->insert('order_status_history',['order_id'=>$id,'status'=>$st,'changed_by'=>getAdminId()]); } catch(\Throwable $e){}
            if ($st==='delivered') { try { awardOrderCredits($id); } catch(\Throwable $e){} try { $db->update('orders',['delivered_at'=>date('Y-m-d H:i:s')],'id=? AND delivered_at IS NULL',[$id]); } catch(\Throwable $e){} }
        }
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// ── Data ─────────────────────────────────────────────────────────────────────
$mainFlow = ['processing','confirmed','shipped','delivered'];
$sideFlow = ['pending_return','pending_cancel','partial_delivered','on_hold','no_response','cancelled','returned'];

// Status config
$statusCfg = [
    'processing'        => ['label'=>'Processing',       'color'=>'#f59e0b','bg'=>'#fffbeb','icon'=>'⚙️', 'next'=>'confirmed',  'next_label'=>'Confirm'],
    'confirmed'         => ['label'=>'Confirmed',        'color'=>'#3b82f6','bg'=>'#eff6ff','icon'=>'✅', 'next'=>'shipped',    'next_label'=>'Ship'],
    'shipped'           => ['label'=>'Shipped',          'color'=>'#8b5cf6','bg'=>'#f5f3ff','icon'=>'🚚', 'next'=>'delivered',  'next_label'=>'Deliver'],
    'delivered'         => ['label'=>'Delivered',        'color'=>'#10b981','bg'=>'#ecfdf5','icon'=>'📦', 'next'=>null,         'next_label'=>null],
    'pending_return'    => ['label'=>'Pending Return',   'color'=>'#f97316','bg'=>'#fff7ed','icon'=>'↩',  'next'=>'returned',   'next_label'=>'Confirm Return'],
    'pending_cancel'    => ['label'=>'Pending Cancel',   'color'=>'#ef4444','bg'=>'#fef2f2','icon'=>'✗',  'next'=>'cancelled',  'next_label'=>'Confirm Cancel'],
    'partial_delivered' => ['label'=>'Partial Delivered','color'=>'#06b6d4','bg'=>'#ecfeff','icon'=>'📫', 'next'=>'delivered',  'next_label'=>'Mark Delivered'],
    'on_hold'           => ['label'=>'On Hold',          'color'=>'#6b7280','bg'=>'#f9fafb','icon'=>'⏸',  'next'=>null,         'next_label'=>null],
    'no_response'       => ['label'=>'No Response',      'color'=>'#f43f5e','bg'=>'#fff1f2','icon'=>'📵', 'next'=>null,         'next_label'=>null],
    'cancelled'         => ['label'=>'Cancelled',        'color'=>'#dc2626','bg'=>'#fef2f2','icon'=>'✗',  'next'=>null,         'next_label'=>null],
    'returned'          => ['label'=>'Returned',         'color'=>'#ea580c','bg'=>'#fff7ed','icon'=>'↩',  'next'=>null,         'next_label'=>null],
];

// Filter
$filterStatus = $_GET['status'] ?? 'processing';
$search       = $_GET['search'] ?? '';
$dateFrom     = $_GET['date_from'] ?? '';
$dateTo       = $_GET['date_to'] ?? '';
$page         = max(1, intval($_GET['page'] ?? 1));
$limit        = 50;

// Counts per status
$counts = [];
foreach ($statusCfg as $s => $cfg) {
    try {
        if ($s === 'processing') {
            $counts[$s] = $db->fetch("SELECT COUNT(*) as c FROM orders WHERE order_status IN ('processing','pending')")['c'];
        } else {
            $counts[$s] = $db->fetch("SELECT COUNT(*) as c FROM orders WHERE order_status=?",[$s])['c'];
        }
    } catch(\Throwable $e) { $counts[$s] = 0; }
}

// Orders query
$where = '1=1'; $params = [];
if ($filterStatus === 'processing') {
    $where .= " AND o.order_status IN ('processing','pending')";
} else {
    $where .= " AND o.order_status=?"; $params[] = $filterStatus;
}
if ($search) {
    $where .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)";
    $params = array_merge($params, ["%$search%","%$search%","%$search%"]);
}
if ($dateFrom) { $where .= " AND DATE(o.created_at) >= ?"; $params[] = $dateFrom; }
if ($dateTo)   { $where .= " AND DATE(o.created_at) <= ?"; $params[] = $dateTo; }

$total      = $db->fetch("SELECT COUNT(*) as c FROM orders o WHERE $where", $params)['c'];
$totalPages = ceil($total / $limit);
$offset     = ($page-1) * $limit;

$orders = $db->fetchAll(
    "SELECT o.id, o.order_number, o.customer_name, o.customer_phone,
            o.customer_address, o.customer_city, o.customer_district,
            o.total, o.subtotal, o.shipping_cost, o.discount_amount, o.advance_amount,
            o.payment_method, o.order_status, o.courier_name,
            o.notes, o.order_note, o.created_at, o.updated_at
     FROM orders o WHERE $where ORDER BY o.created_at DESC LIMIT $limit OFFSET $offset",
    $params
);

// Get order items for all orders
$orderIds = array_column($orders, 'id');
$itemsMap = [];
if ($orderIds) {
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $items = $db->fetchAll("SELECT oi.order_id, oi.product_name, oi.variant_name, oi.quantity, oi.price FROM order_items oi WHERE oi.order_id IN ($ph)", $orderIds);
    foreach ($items as $it) { $itemsMap[$it['order_id']][] = $it; }
}

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.proc-card{background:#fff;border-radius:14px;border:1.5px solid #e5e7eb;padding:16px;position:relative;transition:box-shadow .15s,border-color .15s;cursor:pointer}
.proc-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.08);border-color:#d1d5db}
.proc-card.selected{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12)}
.proc-card .card-check{position:absolute;top:14px;right:14px;width:20px;height:20px;border-radius:6px;border:2px solid #d1d5db;background:#fff;display:flex;align-items:center;justify-content:center;transition:.15s}
.proc-card.selected .card-check{background:#6366f1;border-color:#6366f1;color:#fff}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.next-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;border:none;transition:.15s;white-space:nowrap}
.next-btn:hover{opacity:.85;transform:translateY(-1px)}
.tab-btn{padding:7px 16px;border-radius:10px;font-size:12px;font-weight:600;cursor:pointer;transition:.15s;border:1.5px solid transparent;white-space:nowrap;display:flex;align-items:center;gap:6px}
.tab-btn.active{box-shadow:0 2px 8px rgba(0,0,0,.12)}
.tab-btn:not(.active){background:#f9fafb;color:#6b7280;border-color:#e5e7eb}
.tab-btn:not(.active):hover{background:#f3f4f6}
.proc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
@media(max-width:640px){.proc-grid{grid-template-columns:1fr}}
.bulk-bar{position:fixed;bottom:0;left:0;right:0;z-index:50;background:#1e1b4b;color:#fff;padding:12px 24px;display:flex;align-items:center;gap:12px;transform:translateY(100%);transition:transform .25s cubic-bezier(.4,0,.2,1);box-shadow:0 -4px 24px rgba(0,0,0,.25)}
.bulk-bar.show{transform:translateY(0)}
.bulk-action-btn{padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;transition:.15s}
</style>

<div class="p-4 max-w-screen-2xl mx-auto">

  <!-- Header -->
  <div class="flex flex-wrap items-center gap-3 mb-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Order Processing</h1>
      <p class="text-xs text-gray-400 mt-0.5">Manage order workflow · <?= number_format($total) ?> orders</p>
    </div>
    <div class="ml-auto flex items-center gap-2">
      <!-- Search -->
      <form onsubmit="event.preventDefault();goProc({search:document.getElementById('procSearch').value,page:1})" class="flex gap-1.5">
        <input id="procSearch" type="text" value="<?= e($search) ?>" placeholder="Search orders…" class="border rounded-lg px-3 py-1.5 text-xs w-48 focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
        <button class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-indigo-700">Search</button>
        <?php if($search): ?><a href="?status=<?=e($filterStatus)?>" class="px-3 py-1.5 border rounded-lg text-xs text-gray-500 hover:bg-gray-50">✕ Clear</a><?php endif; ?>
      </form>
      <a href="<?= adminUrl('pages/order-management.php') ?>" class="px-3 py-1.5 border rounded-lg text-xs text-gray-500 hover:bg-gray-50 flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>Table View
      </a>
    </div>
  </div>

  <!-- Status Tabs -->
  <div class="flex flex-wrap gap-2 mb-5 pb-4 border-b border-gray-100">
    <!-- Main flow -->
    <?php foreach (['processing','confirmed','shipped','delivered'] as $s):
      $cfg = $statusCfg[$s];
      $cnt = $counts[$s] ?? 0;
      $active = $filterStatus === $s;
    ?>
    <button onclick="goProc({status:'<?=$s?>',page:1})"
            class="tab-btn <?= $active ? 'active' : '' ?>"
            style="<?= $active ? "background:{$cfg['bg']};color:{$cfg['color']};border-color:{$cfg['color']}40" : '' ?>">
      <span><?= $cfg['icon'] ?></span>
      <span><?= $cfg['label'] ?></span>
      <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold <?= $active ? 'bg-white/60' : 'bg-gray-200 text-gray-600' ?>"><?= $cnt ?></span>
    </button>
    <?php endforeach; ?>
    <div class="w-px bg-gray-200 self-stretch mx-1"></div>
    <!-- Side statuses with issues -->
    <?php foreach (['pending_return','pending_cancel','partial_delivered','no_response','on_hold'] as $s):
      $cfg = $statusCfg[$s];
      $cnt = $counts[$s] ?? 0;
      if ($cnt === 0 && $filterStatus !== $s) continue;
      $active = $filterStatus === $s;
    ?>
    <button onclick="goProc({status:'<?=$s?>',page:1})"
            class="tab-btn <?= $active ? 'active' : '' ?>"
            style="<?= $active ? "background:{$cfg['bg']};color:{$cfg['color']};border-color:{$cfg['color']}40" : '' ?>">
      <span><?= $cfg['icon'] ?></span>
      <span><?= $cfg['label'] ?></span>
      <?php if ($cnt > 0): ?>
      <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold <?= $active ? 'bg-white/60' : "bg-red-100 text-red-600" ?>"><?= $cnt ?></span>
      <?php endif; ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- Bulk select bar at top -->
  <div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-3">
      <label class="flex items-center gap-2 cursor-pointer text-xs text-gray-500 hover:text-gray-700">
        <input type="checkbox" id="selectAllCards" onchange="toggleAllCards(this)" class="rounded">
        <span>Select All</span>
      </label>
      <span id="selCount" class="text-xs text-indigo-600 font-semibold hidden"></span>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center gap-1.5 text-xs">
      <?php if ($page > 1): ?>
      <button onclick="goProc({page:<?=$page-1?>})" class="px-2.5 py-1 border rounded-lg hover:bg-gray-50">←</button>
      <?php endif; ?>
      <span class="text-gray-500">Page <?= $page ?> / <?= $totalPages ?> · <?= $total ?> orders</span>
      <?php if ($page < $totalPages): ?>
      <button onclick="goProc({page:<?=$page+1?>})" class="px-2.5 py-1 border rounded-lg hover:bg-gray-50">→</button>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <span class="text-xs text-gray-400"><?= $total ?> order<?= $total!=1?'s':'' ?></span>
    <?php endif; ?>
  </div>

  <!-- Cards Grid -->
  <?php if (empty($orders)): ?>
  <div class="text-center py-20 text-gray-400">
    <div class="text-5xl mb-3">📭</div>
    <p class="font-semibold text-gray-500">No orders in this stage</p>
    <p class="text-sm mt-1">Try a different status or clear the search filter.</p>
  </div>
  <?php else: ?>
  <div class="proc-grid" id="procGrid">
    <?php foreach ($orders as $order):
      $cfg    = $statusCfg[$order['order_status']] ?? $statusCfg['processing'];
      $items  = $itemsMap[$order['id']] ?? [];
      $disc   = floatval($order['discount_amount'] ?? 0);
      $adv    = floatval($order['advance_amount'] ?? 0);
      $due    = floatval($order['total']) - $adv;
      $pay    = strtoupper($order['payment_method'] ?? 'COD');
      $addr   = trim(($order['customer_city']??'').($order['customer_district']?', '.$order['customer_district']:''));
    ?>
    <div class="proc-card" data-id="<?= $order['id'] ?>" onclick="toggleCard(this)" id="card-<?= $order['id'] ?>">
      <!-- Check -->
      <div class="card-check" id="check-<?= $order['id'] ?>">
        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
      </div>

      <!-- Header row -->
      <div class="flex items-start justify-between mb-3 pr-7">
        <div>
          <div class="flex items-center gap-2 mb-1">
            <span class="font-bold text-gray-900 text-sm">#<?= e($order['order_number']) ?></span>
            <span class="status-pill" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>"><?= $cfg['icon'] ?> <?= $cfg['label'] ?></span>
          </div>
          <div class="text-[10px] text-gray-400"><?= date('d M Y · h:i a', strtotime($order['created_at'])) ?></div>
        </div>
        <div class="text-right">
          <div class="font-black text-lg text-gray-900">৳<?= number_format($due) ?></div>
          <div class="text-[10px] font-semibold px-2 py-0.5 rounded <?= $pay==='COD'?'bg-amber-100 text-amber-700':'bg-emerald-100 text-emerald-700' ?>"><?= $pay ?></div>
        </div>
      </div>

      <!-- Customer -->
      <div class="flex items-center gap-2.5 mb-3 pb-3 border-b border-gray-100">
        <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 font-bold text-sm flex items-center justify-center flex-shrink-0">
          <?= mb_strtoupper(mb_substr($order['customer_name'],0,1)) ?>
        </div>
        <div class="min-w-0">
          <div class="font-semibold text-gray-800 text-sm truncate"><?= e($order['customer_name']) ?></div>
          <div class="text-[11px] text-gray-500 flex items-center gap-1.5">
            <span>📞 <?= e($order['customer_phone']) ?></span>
            <?php if ($addr): ?><span class="text-gray-300">·</span><span class="truncate">📍 <?= e($addr) ?></span><?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Items -->
      <div class="mb-3 space-y-1">
        <?php foreach (array_slice($items, 0, 3) as $it): ?>
        <div class="flex justify-between items-start text-xs">
          <span class="text-gray-700 flex-1 min-w-0 pr-2 truncate"><?= e($it['product_name']) ?><?= !empty($it['variant_name'])?' <span class="text-gray-400">('.$it['variant_name'].')</span>':'' ?></span>
          <span class="text-gray-500 flex-shrink-0">×<?= $it['quantity'] ?> · ৳<?= number_format($it['price']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (count($items) > 3): ?>
        <div class="text-[10px] text-indigo-500 font-semibold">+<?= count($items)-3 ?> more items</div>
        <?php endif; ?>
      </div>

      <!-- Totals -->
      <div class="text-[10px] text-gray-400 bg-gray-50 rounded-lg px-3 py-2 mb-3 flex flex-wrap gap-x-3 gap-y-1">
        <span>Sub: ৳<?= number_format($order['subtotal']) ?></span>
        <span>Del: ৳<?= number_format($order['shipping_cost']) ?></span>
        <?php if ($disc > 0): ?><span class="text-red-500">Disc: -৳<?= number_format($disc) ?></span><?php endif; ?>
        <?php if ($adv > 0): ?><span class="text-blue-500">Adv: -৳<?= number_format($adv) ?></span><?php endif; ?>
        <?php if ($order['courier_name']): ?><span class="text-purple-500">📦 <?= e($order['courier_name']) ?></span><?php endif; ?>
      </div>

      <!-- Notes -->
      <?php if (!empty($order['order_note'])): ?>
      <div class="text-[10px] text-blue-700 bg-blue-50 rounded-lg px-2.5 py-1.5 mb-3">📝 <?= e(mb_strimwidth($order['order_note'],0,80,'…')) ?></div>
      <?php endif; ?>
      <?php if (!empty($order['notes'])): ?>
      <div class="text-[10px] text-orange-700 bg-orange-50 rounded-lg px-2.5 py-1.5 mb-3">🚚 <?= e(mb_strimwidth($order['notes'],0,80,'…')) ?></div>
      <?php endif; ?>

      <!-- Action buttons -->
      <div class="flex items-center gap-2 mt-auto pt-2" onclick="event.stopPropagation()">
        <?php if ($cfg['next']): ?>
        <button class="next-btn flex-1 justify-center"
                style="background:<?= $cfg['color'] ?>;color:#fff"
                onclick="quickStatus(<?= $order['id'] ?>,'<?= $cfg['next'] ?>',this)">
          <?= $cfg['icon'] ?> <?= $cfg['next_label'] ?>
        </button>
        <?php endif; ?>
        <a href="<?= adminUrl('pages/order-detail.php?id='.$order['id']) ?>"
           class="px-3 py-1.5 border rounded-lg text-xs text-gray-500 hover:bg-gray-50 flex items-center gap-1">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
        </a>
        <a href="<?= adminUrl('pages/order-management.php?search='.$order['order_number']) ?>"
           class="px-3 py-1.5 border rounded-lg text-xs text-gray-500 hover:bg-gray-50 flex items-center gap-1" title="Open in table view">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Bulk Action Bar -->
<div class="bulk-bar" id="bulkBar">
  <span id="bulkCount" class="text-sm font-bold mr-2"></span>
  <div class="flex flex-wrap gap-2">
    <?php foreach (['confirmed'=>['✅','Confirm','#3b82f6'],'shipped'=>['🚚','Ship','#8b5cf6'],'delivered'=>['📦','Deliver','#10b981'],'cancelled'=>['✗','Cancel','#ef4444']] as $s=>[$ic,$lb,$cl]): ?>
    <button class="bulk-action-btn" style="background:<?=$cl?>;color:#fff" onclick="bulkStatus('<?=$s?>')">
      <?=$ic?> <?=$lb?>
    </button>
    <?php endforeach; ?>
    <button class="bulk-action-btn" style="background:rgba(255,255,255,.15);color:#fff" onclick="openInvPrintProc()">📄 Invoice</button>
    <button class="bulk-action-btn" style="background:rgba(255,255,255,.15);color:#fff" onclick="openStkPrintProc()">🏷 Sticker</button>
    <button class="bulk-action-btn" style="background:rgba(255,255,255,.15);color:#fff" onclick="clearSel()">✕ Clear</button>
  </div>
</div>

<script>
// State
var procState = {
  status: '<?= e($filterStatus) ?>',
  search: '<?= e($search) ?>',
  date_from: '<?= e($dateFrom) ?>',
  date_to: '<?= e($dateTo) ?>',
  page: <?= $page ?>,
};
var selectedIds = new Set();

function goProc(params) {
  Object.assign(procState, params);
  const qs = new URLSearchParams(Object.fromEntries(Object.entries(procState).filter(([,v])=>v!==''&&v!==null)));
  window.location.href = '?' + qs.toString();
}

// Card selection
function toggleCard(card) {
  const id = card.dataset.id;
  if (selectedIds.has(id)) {
    selectedIds.delete(id);
    card.classList.remove('selected');
  } else {
    selectedIds.add(id);
    card.classList.add('selected');
  }
  updateBulkBar();
}

function toggleAllCards(cb) {
  document.querySelectorAll('.proc-card').forEach(card => {
    if (cb.checked) { selectedIds.add(card.dataset.id); card.classList.add('selected'); }
    else { selectedIds.delete(card.dataset.id); card.classList.remove('selected'); }
  });
  updateBulkBar();
}

function clearSel() {
  selectedIds.clear();
  document.querySelectorAll('.proc-card').forEach(c=>c.classList.remove('selected'));
  document.getElementById('selectAllCards').checked = false;
  updateBulkBar();
}

function updateBulkBar() {
  const n = selectedIds.size;
  const bar = document.getElementById('bulkBar');
  const cnt = document.getElementById('bulkCount');
  const sel = document.getElementById('selCount');
  cnt.textContent = n + ' selected';
  sel.textContent = n + ' selected';
  sel.classList.toggle('hidden', n === 0);
  if (n > 0) bar.classList.add('show'); else bar.classList.remove('show');
}

// Quick single status update
function quickStatus(id, status, btn) {
  const orig = btn.innerHTML;
  btn.innerHTML = '…'; btn.disabled = true;
  fetch(location.pathname, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    credentials: 'same-origin',
    body: 'action=update_status&order_id=' + id + '&status=' + encodeURIComponent(status)
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      const card = document.getElementById('card-' + id);
      card.style.transition = 'all .3s';
      card.style.opacity = '0';
      card.style.transform = 'scale(.95)';
      setTimeout(() => { card.remove(); updateGridEmpty(); }, 300);
    } else { btn.innerHTML = orig; btn.disabled = false; }
  })
  .catch(() => { btn.innerHTML = orig; btn.disabled = false; });
}

function updateGridEmpty() {
  const grid = document.getElementById('procGrid');
  if (grid && !grid.querySelector('.proc-card')) {
    grid.innerHTML = '<div class="col-span-full text-center py-20 text-gray-400"><div class="text-5xl mb-3">🎉</div><p class="font-semibold text-gray-500">All done! No more orders here.</p></div>';
  }
}

// Bulk status
function bulkStatus(status) {
  const ids = [...selectedIds];
  if (!ids.length) return;
  if (!confirm('Update ' + ids.length + ' orders to ' + status + '?')) return;
  const fd = new URLSearchParams();
  fd.append('action', 'bulk_status');
  fd.append('bulk_status', status);
  ids.forEach(id => fd.append('order_ids[]', id));
  fetch(location.pathname, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    credentials: 'same-origin',
    body: fd.toString()
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      ids.forEach(id => {
        const card = document.getElementById('card-' + id);
        if (card) { card.style.opacity='0'; card.style.transform='scale(.95)'; setTimeout(()=>card.remove(),300); }
      });
      clearSel();
      setTimeout(updateGridEmpty, 400);
    }
  });
}

// Print from processing page (redirect to order-management print modals)
function openInvPrintProc() {
  const ids = [...selectedIds];
  if (!ids.length) { alert('Select orders first'); return; }
  window.open('<?= adminUrl('pages/order-print.php') ?>?ids=' + ids.join(',') + '&template=inv_standard', '_blank');
}
function openStkPrintProc() {
  const ids = [...selectedIds];
  if (!ids.length) { alert('Select orders first'); return; }
  window.open('<?= adminUrl('pages/order-print.php') ?>?ids=' + ids.join(',') + '&template=stk_standard', '_blank');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
