<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Customer Retention';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

// ── Auto-create tables ─────────────────────────────────────────────────────
try {
    $db->query("CREATE TABLE IF NOT EXISTS customer_retention_log (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id     INT UNSIGNED NOT NULL,
        product_id      INT UNSIGNED NOT NULL,
        order_id        INT UNSIGNED NOT NULL,
        order_item_id   INT UNSIGNED NOT NULL DEFAULT 0,
        due_date        DATE NOT NULL,
        segment         ENUM('due_soon','overdue','at_risk','retained') NOT NULL DEFAULT 'due_soon',
        status          ENUM('pending','contacted','converted','not_interested') NOT NULL DEFAULT 'pending',
        contacted_at    DATETIME NULL,
        contacted_by    INT UNSIGNED NULL,
        assigned_to     INT UNSIGNED NULL,
        notes           TEXT NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_customer (customer_id),
        INDEX idx_product  (product_id),
        INDEX idx_due_date (due_date),
        INDEX idx_segment  (segment),
        INDEX idx_status   (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

try {
    $db->query("CREATE TABLE IF NOT EXISTS customer_retention_profiles (
        id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id            INT UNSIGNED NOT NULL UNIQUE,
        custom_retention_value INT UNSIGNED NULL,
        custom_retention_unit  ENUM('days','weeks','months') NULL DEFAULT 'days',
        notes                  TEXT NULL,
        updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

// ── Column guards ──────────────────────────────────────────────────────────
try { $db->query("SELECT retention_value FROM products LIMIT 0"); }
catch (\Throwable $e) {
    try { $db->query("ALTER TABLE products ADD COLUMN retention_value INT UNSIGNED NULL DEFAULT NULL"); } catch (\Throwable $e2) {}
    try { $db->query("ALTER TABLE products ADD COLUMN retention_unit ENUM('days','weeks','months') NOT NULL DEFAULT 'days'"); } catch (\Throwable $e2) {}
}
try { $db->query("SELECT delivered_at FROM orders LIMIT 0"); }
catch (\Throwable $e) {
    try { $db->query("ALTER TABLE orders ADD COLUMN delivered_at DATETIME NULL DEFAULT NULL"); } catch (\Throwable $e2) {}
}

// ── One-time migration: sync status=converted → segment=retained ───────────
// Fixes records marked converted before segment-sync logic existed
try {
    $db->query("UPDATE customer_retention_log SET segment='retained', updated_at=NOW()
                WHERE status='converted' AND segment != 'retained'");
} catch (\Throwable $e) {}

// ── Helpers ────────────────────────────────────────────────────────────────
function _toDays($val, $unit) {
    $val = (int)$val;
    if (!$val) return 0;
    if ($unit === 'weeks')  return $val * 7;
    if ($unit === 'months') return $val * 30;
    return $val;
}

function retStatusBadge($s) {
    return match($s) {
        'contacted'      => 'bg-blue-100 text-blue-700',
        'converted'      => 'bg-green-100 text-green-700',
        'not_interested' => 'bg-gray-100 text-gray-500',
        default          => 'bg-yellow-100 text-yellow-700',
    };
}

function _getKpi($db): array {
    $kpi = ['overdue'=>0,'due_soon'=>0,'at_risk'=>0,'retained'=>0,
            'total'=>0,'rate'=>0,'contacted'=>0,'converted'=>0];
    foreach (['overdue','due_soon','at_risk','retained'] as $seg) {
        try { $kpi[$seg] = (int)($db->fetch("SELECT COUNT(*) as c FROM customer_retention_log WHERE segment=?", [$seg])['c'] ?? 0); }
        catch (\Throwable $e) {}
    }
    $kpi['total'] = $kpi['overdue'] + $kpi['due_soon'] + $kpi['at_risk'] + $kpi['retained'];
    $kpi['rate']  = $kpi['total'] > 0 ? round($kpi['retained'] / $kpi['total'] * 100, 1) : 0;
    try {
        $kpi['contacted'] = (int)($db->fetch(
            "SELECT COUNT(*) as c FROM customer_retention_log WHERE status='contacted' AND MONTH(contacted_at)=MONTH(NOW()) AND YEAR(contacted_at)=YEAR(NOW())"
        )['c'] ?? 0);
        $kpi['converted'] = (int)($db->fetch(
            "SELECT COUNT(*) as c FROM customer_retention_log WHERE status='converted'"
        )['c'] ?? 0);
    } catch (\Throwable $e) {}
    return $kpi;
}

function _buildWhere($segment, $statusF, $assignedF, $search): array {
    $where  = "crl.segment = ?";
    $params = [$segment];
    if ($statusF)   { $where .= " AND crl.status = ?";      $params[] = $statusF; }
    if ($assignedF) { $where .= " AND crl.assigned_to = ?"; $params[] = $assignedF; }
    if ($search) {
        $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR p.name LIKE ?)";
        $s = '%'.$search.'%';
        array_push($params, $s, $s, $s);
    }
    return [$where, $params];
}

function _queryRows($db, $where, $params, $orderBy, $perPage, $offset): array {
    try {
        return $db->fetchAll(
            "SELECT crl.*,
                    c.name  AS cust_name,  c.phone AS cust_phone, c.email AS cust_email,
                    p.name  AS prod_name,  p.featured_image AS prod_img,
                    p.retention_value,     p.retention_unit,
                    o.order_number,        o.total AS order_total,
                    au.full_name  AS assigned_name,
                    au2.full_name AS contacted_name,
                    DATEDIFF(CURDATE(), crl.due_date) AS days_overdue,
                    DATEDIFF(crl.due_date, CURDATE()) AS days_remaining
             FROM customer_retention_log crl
             JOIN customers c   ON c.id   = crl.customer_id
             JOIN products  p   ON p.id   = crl.product_id
             JOIN orders    o   ON o.id   = crl.order_id
             LEFT JOIN admin_users au  ON au.id  = crl.assigned_to
             LEFT JOIN admin_users au2 ON au2.id = crl.contacted_by
             WHERE $where
             ORDER BY $orderBy
             LIMIT $perPage OFFSET $offset",
            $params
        );
    } catch (\Throwable $e) { return []; }
}

// ── Rebuild helper ─────────────────────────────────────────────────────────
function _rebuildRetentionLog($db): int {
    $globalDefault = intval(getSetting('retention_default_days', 30));
    $count    = 0;
    $phoneMap = [];

    try {
        $deliveredRows = $db->fetchAll(
            "SELECT oi.id AS item_id, oi.order_id, oi.product_id,
                    o.customer_id, o.customer_phone,
                    COALESCE(o.delivered_at, o.updated_at) AS delivered_at
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.order_status = 'delivered'
             ORDER BY delivered_at ASC"
        );
    } catch (\Throwable $e) { return 0; }

    foreach ($deliveredRows as $row) {
        $custId = (int)$row['customer_id'];
        $phone  = trim($row['customer_phone']);

        if (!$custId && $phone) {
            if (isset($phoneMap[$phone])) {
                $custId = $phoneMap[$phone];
            } else {
                $cust = $db->fetch("SELECT id FROM customers WHERE phone = ? LIMIT 1", [$phone]);
                if (!$cust) {
                    $stripped = ltrim($phone, '0+8');
                    $cust = $db->fetch("SELECT id FROM customers WHERE phone LIKE ? LIMIT 1", ['%'.$stripped]);
                }
                $custId = $cust ? (int)$cust['id'] : 0;
                $phoneMap[$phone] = $custId;
            }
        }
        if (!$custId) continue;

        try { $profile = $db->fetch("SELECT custom_retention_value, custom_retention_unit FROM customer_retention_profiles WHERE customer_id=?", [$custId]); }
        catch (\Throwable $e) { $profile = null; }

        try { $prod = $db->fetch("SELECT retention_value, retention_unit FROM products WHERE id=?", [$row['product_id']]); }
        catch (\Throwable $e) { $prod = null; }

        if (!empty($profile['custom_retention_value'])) {
            $days = _toDays($profile['custom_retention_value'], $profile['custom_retention_unit'] ?? 'days');
        } elseif (!empty($prod['retention_value'])) {
            $days = _toDays($prod['retention_value'], $prod['retention_unit'] ?? 'days');
        } else {
            $days = $globalDefault;
        }
        if (!$days) continue;

        $dueDate = date('Y-m-d', strtotime($row['delivered_at'] . ' +' . $days . ' days'));

        $exists = $db->fetch("SELECT id FROM customer_retention_log WHERE order_item_id=? AND product_id=?",
            [$row['item_id'], $row['product_id']]);
        if ($exists) continue;

        $reorder = $db->fetch(
            "SELECT o2.id FROM orders o2 JOIN order_items oi2 ON oi2.order_id=o2.id
             WHERE (o2.customer_id=? OR o2.customer_phone=?) AND oi2.product_id=?
               AND o2.order_status='delivered' AND COALESCE(o2.delivered_at,o2.updated_at) > ? LIMIT 1",
            [$custId, $phone, $row['product_id'], $row['delivered_at']]
        );

        $today = date('Y-m-d');
        if ($reorder)                   $seg = 'retained';
        elseif ($dueDate < $today)      $seg = 'overdue';
        elseif ((strtotime($dueDate)-strtotime($today)) <= 7*86400) $seg = 'due_soon';
        else                             $seg = 'at_risk';

        try {
            $db->insert('customer_retention_log', [
                'customer_id'=>$custId, 'product_id'=>$row['product_id'],
                'order_id'=>$row['order_id'], 'order_item_id'=>$row['item_id'],
                'due_date'=>$dueDate, 'segment'=>$seg, 'status'=>'pending',
                'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s'),
            ]);
            $count++;
        } catch (\Throwable $e) {}
    }

    try {
        // Never touch converted records — they always stay in retained
        $db->query("UPDATE customer_retention_log SET segment='retained',updated_at=NOW()
                    WHERE status='converted' AND segment != 'retained'");
        $db->query("UPDATE customer_retention_log SET segment='overdue',updated_at=NOW()
                    WHERE segment IN ('due_soon','at_risk') AND due_date < CURDATE()
                      AND status NOT IN ('converted')");
        $db->query("UPDATE customer_retention_log SET segment='due_soon',updated_at=NOW()
                    WHERE segment='at_risk'
                      AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
                      AND status NOT IN ('converted')");
    } catch (\Throwable $e) {}

    return $count;
}

// ═══════════════════════════════════════════════════════════════════════════
// AJAX: _profile GET
// ═══════════════════════════════════════════════════════════════════════════
if (!empty($_GET['_profile'])) {
    header('Content-Type: application/json');
    $cid     = intval($_GET['_profile']);
    $profile = $cid ? $db->fetch("SELECT * FROM customer_retention_profiles WHERE customer_id=?", [$cid]) : null;
    echo json_encode(['profile' => $profile]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// AJAX: fragment fetch (_frag=1) — returns rows HTML + KPI + pagination
// ═══════════════════════════════════════════════════════════════════════════
if (!empty($_GET['_frag'])) {
    header('Content-Type: application/json');

    $segment   = $_GET['segment'] ?? 'overdue';
    $statusF   = $_GET['status']  ?? '';
    $search    = trim($_GET['search'] ?? '');
    $assignedF = intval($_GET['assigned'] ?? 0);
    $page      = max(1, intval($_GET['page'] ?? 1));
    $perPage   = 25;
    $validSegs = ['overdue','due_soon','at_risk','retained'];
    if (!in_array($segment, $validSegs)) $segment = 'overdue';

    [$where, $params] = _buildWhere($segment, $statusF, $assignedF, $search);

    $total = 0;
    try {
        $total = (int)($db->fetch(
            "SELECT COUNT(*) as c FROM customer_retention_log crl
             JOIN customers c ON c.id=crl.customer_id JOIN products p ON p.id=crl.product_id
             WHERE $where", $params
        )['c'] ?? 0);
    } catch (\Throwable $e) {}

    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;
    $orderBy    = $segment === 'retained' ? 'crl.updated_at DESC' : 'crl.due_date ASC';

    $adminUsers = [];
    try { $adminUsers = $db->fetchAll("SELECT id, full_name FROM admin_users WHERE is_active=1 ORDER BY full_name"); }
    catch (\Throwable $e) {}

    $rows = _queryRows($db, $where, $params, $orderBy, $perPage, $offset);
    $kpi  = _getKpi($db);

    // Build rows HTML
    ob_start();
    if (empty($rows)) {
        echo '<tr id="ret-empty-row"><td colspan="7" class="text-center py-12 text-gray-400">
            <div class="text-4xl mb-2">📋</div>
            <div class="font-medium text-gray-600">No records in this segment</div>
            <div class="text-xs mt-2 text-gray-400 max-w-xs mx-auto">
                Click <strong class="text-indigo-600">Rebuild Log</strong> to scan all delivered orders.
            </div></td></tr>';
    }
    foreach ($rows as $row):
        $daysOver  = (int)$row['days_overdue'];
        $daysRem   = (int)$row['days_remaining'];
        $ph        = preg_replace('/[^0-9]/', '', $row['cust_phone']);
        $waMsg     = rawurlencode("হ্যালো {$row['cust_name']}! আপনার '{$row['prod_name']}' আবার লাগতে পারে। আমরা সাহায্য করতে পারি? 😊");
        $waLink    = "https://wa.me/880{$ph}?text={$waMsg}";
        $orderLink = adminUrl('pages/order-view.php?id='.$row['order_id']);
        $sbadge    = retStatusBadge($row['status']);
        $custNameE = addslashes(e($row['cust_name']));
        $noteEsc   = addslashes(htmlspecialchars($row['notes'] ?? ''));
    ?>
    <tr class="ret-row hover:bg-gray-50/70 transition-all duration-300"
        data-log-id="<?= $row['id'] ?>" data-segment="<?= $row['segment'] ?>">
      <td class="px-4 py-3">
        <div class="font-semibold text-gray-800 text-sm"><?= e($row['cust_name']) ?></div>
        <div class="text-xs text-gray-400 mt-0.5 font-mono"><?= e($row['cust_phone']) ?></div>
        <?php if ($row['cust_email']): ?><div class="text-[10px] text-gray-400 truncate max-w-[140px]"><?= e($row['cust_email']) ?></div><?php endif; ?>
        <button onclick="RM.openProfile(<?= (int)$row['customer_id'] ?>,'<?= $custNameE ?>')"
                class="text-[10px] text-indigo-500 hover:underline mt-0.5">⚙ Profile Override</button>
      </td>
      <td class="px-3 py-3">
        <div class="flex items-center gap-2">
          <?php if ($row['prod_img']): ?><img src="<?= imgSrc('products',$row['prod_img']) ?>" class="w-8 h-8 rounded object-cover shrink-0"><?php endif; ?>
          <div>
            <div class="font-medium text-gray-800 text-xs leading-tight max-w-[140px]"><?= e($row['prod_name']) ?></div>
            <?php if ($row['retention_value']): ?>
              <div class="text-[10px] text-gray-400">Every <?= (int)$row['retention_value'] ?> <?= e($row['retention_unit']) ?></div>
            <?php else: ?>
              <div class="text-[10px] text-gray-300">Default period</div>
            <?php endif; ?>
          </div>
        </div>
      </td>
      <td class="px-3 py-3">
        <div class="font-medium text-gray-700 text-xs"><?= date('d M Y', strtotime($row['due_date'])) ?></div>
        <?php if ($row['segment']==='overdue' && $daysOver>0): ?>
          <div class="text-[10px] text-red-600 font-semibold mt-0.5">⚠ <?= $daysOver ?>d overdue</div>
        <?php elseif ($row['segment']==='due_soon' && $daysRem>=0): ?>
          <div class="text-[10px] text-amber-600 font-semibold mt-0.5">🕐 <?= $daysRem ?>d left</div>
        <?php elseif ($row['segment']==='retained'): ?>
          <div class="text-[10px] text-green-600 font-semibold mt-0.5">✓ Reordered</div>
        <?php else: ?>
          <div class="text-[10px] text-orange-500 mt-0.5">In <?= abs($daysRem) ?>d</div>
        <?php endif; ?>
      </td>
      <td class="px-3 py-3 hidden md:table-cell">
        <a href="<?= $orderLink ?>" class="text-xs font-mono text-blue-600 hover:underline"><?= e($row['order_number']) ?></a>
        <div class="text-[10px] text-gray-400">৳<?= number_format($row['order_total']) ?></div>
      </td>
      <td class="px-3 py-3">
        <select class="ret-status-select text-xs px-2 py-1 border rounded-lg <?= $sbadge ?>"
                data-log-id="<?= $row['id'] ?>" data-segment="<?= $row['segment'] ?>"
                onchange="RM.updateStatus(this)">
          <?php foreach (['pending'=>'⏳ Pending','contacted'=>'📞 Contacted','converted'=>'✅ Converted','not_interested'=>'🚫 Not Interested'] as $v=>$lbl): ?>
          <option value="<?= $v ?>" <?= $row['status']===$v?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($row['contacted_name']): ?><div class="text-[10px] text-gray-400 mt-0.5">by <?= e($row['contacted_name']) ?></div><?php endif; ?>
        <?php if ($row['notes']): ?><div class="text-[10px] text-blue-500 mt-0.5 max-w-[120px] truncate" title="<?= htmlspecialchars($row['notes']) ?>">📝 <?= e(mb_strimwidth($row['notes'],0,30,'...')) ?></div><?php endif; ?>
      </td>
      <td class="px-3 py-3 hidden lg:table-cell">
        <select class="text-xs px-2 py-1 border rounded-lg" onchange="RM.assignStaff(this,<?= $row['id'] ?>)">
          <option value="">Unassigned</option>
          <?php foreach ($adminUsers as $au): ?>
          <option value="<?= $au['id'] ?>" <?= $row['assigned_to']==$au['id']?'selected':'' ?>><?= e($au['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td class="px-4 py-3 text-right">
        <div class="flex items-center justify-end gap-1.5">
          <a href="<?= $waLink ?>" target="_blank"
             class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-green-100 text-green-600 hover:bg-green-600 hover:text-white transition text-xs" title="WhatsApp">
            <i class="fab fa-whatsapp"></i>
          </a>
          <button onclick="RM.openNote(<?= $row['id'] ?>,`<?= $noteEsc ?>`)"
                  class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-blue-100 text-blue-600 hover:bg-blue-600 hover:text-white transition text-xs" title="Note">
            <i class="fas fa-sticky-note"></i>
          </button>
          <a href="<?= $orderLink ?>"
             class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-600 hover:text-white transition text-xs" title="Order">
            <i class="fas fa-external-link-alt"></i>
          </a>
        </div>
      </td>
    </tr>
    <?php endforeach;
    $rowsHtml = ob_get_clean();

    // Pagination HTML
    ob_start();
    if ($totalPages > 1): ?>
    <div class="flex items-center justify-between mt-3 px-1">
        <p class="text-xs text-gray-500">Page <strong><?= $page ?></strong> of <?= $totalPages ?> · <?= number_format($total) ?> records</p>
        <div class="flex gap-1">
            <?php if ($page > 1): ?>
            <button onclick="RM.go({page:<?= $page-1 ?>})" class="px-2.5 py-1 text-xs rounded bg-white border hover:bg-gray-50">←</button>
            <?php endif; ?>
            <?php for ($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
            <button onclick="RM.go({page:<?= $i ?>})"
                    class="px-2.5 py-1 text-xs rounded <?= $i===$page?'bg-blue-600 text-white':'bg-white border hover:bg-gray-50' ?>"><?= $i ?></button>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <button onclick="RM.go({page:<?= $page+1 ?>})" class="px-2.5 py-1 text-xs rounded bg-white border hover:bg-gray-50">→</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif;
    $pagHtml = ob_get_clean();

    echo json_encode([
        'rows'       => $rowsHtml,
        'pagination' => $pagHtml,
        'kpi'        => $kpi,
        'total'      => $total,
        'page'       => $page,
        'totalPages' => $totalPages,
        'segment'    => $segment,
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// AJAX: POST actions
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_ajax'])) {
    header('Content-Type: application/json');
    $action  = $_POST['action'] ?? '';
    $logId   = intval($_POST['log_id'] ?? 0);
    $adminId = getAdminId();

    if ($action === 'update_status' && $logId) {
        $ns = $_POST['status'] ?? '';
        if (in_array($ns, ['pending','contacted','converted','not_interested'])) {
            // When converted → also move to retained segment
            $newSegment = ($ns === 'converted') ? 'retained' : null;
            if ($newSegment) {
                $db->query("UPDATE customer_retention_log SET status=?, segment=?, contacted_at=NOW(), contacted_by=?, updated_at=NOW() WHERE id=?",
                    [$ns, $newSegment, $adminId, $logId]);
            } else {
                $db->query("UPDATE customer_retention_log SET status=?, contacted_at=NOW(), contacted_by=?, updated_at=NOW() WHERE id=?",
                    [$ns, $adminId, $logId]);
            }
            $kpi = _getKpi($db);
            echo json_encode(['ok' => true, 'kpi' => $kpi, 'new_segment' => $newSegment]);
        } else {
            echo json_encode(['ok' => false, 'err' => 'Invalid status']);
        }
        exit;
    }

    if ($action === 'add_note' && $logId) {
        $note = trim($_POST['note'] ?? '');
        if ($note) {
            $db->query("UPDATE customer_retention_log SET notes=?, updated_at=NOW() WHERE id=?", [$note, $logId]);
            echo json_encode(['ok' => true, 'note' => $note]);
        } else {
            echo json_encode(['ok' => false, 'err' => 'Empty note']);
        }
        exit;
    }

    if ($action === 'assign' && $logId) {
        $toId = intval($_POST['assign_to'] ?? 0) ?: null;
        $db->query("UPDATE customer_retention_log SET assigned_to=?, updated_at=NOW() WHERE id=?", [$toId, $logId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'update_profile') {
        $cid   = intval($_POST['customer_id'] ?? 0);
        $val   = intval($_POST['custom_retention_value'] ?? 0) ?: null;
        $unit  = in_array($_POST['custom_retention_unit'] ?? '', ['days','weeks','months'])
                 ? $_POST['custom_retention_unit'] : 'days';
        $pnote = trim($_POST['notes'] ?? '');
        if ($cid) {
            $db->query(
                "INSERT INTO customer_retention_profiles (customer_id,custom_retention_value,custom_retention_unit,notes)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   custom_retention_value=VALUES(custom_retention_value),
                   custom_retention_unit=VALUES(custom_retention_unit),
                   notes=VALUES(notes), updated_at=NOW()",
                [$cid, $val, $unit, $pnote]
            );
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    if ($action === 'rebuild') {
        $built = _rebuildRetentionLog($db);
        $kpi   = _getKpi($db);
        echo json_encode(['ok' => true, 'built' => $built, 'kpi' => $kpi]);
        exit;
    }

    echo json_encode(['ok' => false, 'err' => 'Unknown action']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// Full page render
// ═══════════════════════════════════════════════════════════════════════════
$segment   = $_GET['segment'] ?? 'overdue';
$statusF   = $_GET['status']  ?? '';
$search    = trim($_GET['search'] ?? '');
$assignedF = intval($_GET['assigned'] ?? 0);
$page      = max(1, intval($_GET['page'] ?? 1));
$perPage   = 25;
$validSegs = ['overdue','due_soon','at_risk','retained'];
if (!in_array($segment, $validSegs)) $segment = 'overdue';

$kpi = _getKpi($db);
[$where, $params] = _buildWhere($segment, $statusF, $assignedF, $search);

$total = 0;
try {
    $total = (int)($db->fetch(
        "SELECT COUNT(*) as c FROM customer_retention_log crl
         JOIN customers c ON c.id=crl.customer_id JOIN products p ON p.id=crl.product_id WHERE $where",
        $params
    )['c'] ?? 0);
} catch (\Throwable $e) {}

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;
$orderBy    = $segment === 'retained' ? 'crl.updated_at DESC' : 'crl.due_date ASC';

$adminUsers = [];
try { $adminUsers = $db->fetchAll("SELECT id, full_name FROM admin_users WHERE is_active=1 ORDER BY full_name"); }
catch (\Throwable $e) {}

$rows = _queryRows($db, $where, $params, $orderBy, $perPage, $offset);

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══════════════════ KPI CARDS ══════════════════ -->
<div id="retKpiCards" class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-4">
<?php
$cardDefs = [
    ['id'=>'kpi-rate',      'lbl'=>'Retention Rate',  'val'=>$kpi['rate'].'%',                'icon'=>'fa-percentage',        'col'=>'blue'],
    ['id'=>'kpi-overdue',   'lbl'=>'Overdue',          'val'=>number_format($kpi['overdue']),  'icon'=>'fa-exclamation-circle', 'col'=>'red'],
    ['id'=>'kpi-due_soon',  'lbl'=>'Due Soon',         'val'=>number_format($kpi['due_soon']), 'icon'=>'fa-clock',              'col'=>'amber'],
    ['id'=>'kpi-at_risk',   'lbl'=>'At Risk',           'val'=>number_format($kpi['at_risk']),  'icon'=>'fa-fire',               'col'=>'orange'],
    ['id'=>'kpi-retained',  'lbl'=>'Retained',          'val'=>number_format($kpi['retained']), 'icon'=>'fa-check-circle',       'col'=>'green'],
    ['id'=>'kpi-contacted', 'lbl'=>'Contacted (mo.)',   'val'=>number_format($kpi['contacted']),'icon'=>'fa-paper-plane',        'col'=>'purple'],
    ['id'=>'kpi-converted', 'lbl'=>'Converted',         'val'=>number_format($kpi['converted']),'icon'=>'fa-trophy',             'col'=>'teal'],
];
$cmap = [
    'blue'  =>['bg-blue-50',  'border-blue-200',  'text-blue-700',  'text-blue-400'],
    'red'   =>['bg-red-50',   'border-red-200',   'text-red-700',   'text-red-400'],
    'amber' =>['bg-amber-50', 'border-amber-200', 'text-amber-700', 'text-amber-400'],
    'orange'=>['bg-orange-50','border-orange-200','text-orange-700','text-orange-400'],
    'green' =>['bg-green-50', 'border-green-200', 'text-green-700', 'text-green-400'],
    'purple'=>['bg-purple-50','border-purple-200','text-purple-700','text-purple-400'],
    'teal'  =>['bg-teal-50',  'border-teal-200',  'text-teal-700',  'text-teal-400'],
];
foreach ($cardDefs as $c):
    [$bg,$border,$txt,$icol] = $cmap[$c['col']];
?>
<div id="<?= $c['id'] ?>" class="<?= $bg ?> border <?= $border ?> rounded-xl p-3 flex flex-col gap-1">
    <div class="flex items-center justify-between">
        <span class="text-[10px] font-semibold uppercase tracking-wide <?= $txt ?> opacity-70"><?= $c['lbl'] ?></span>
        <i class="fas <?= $c['icon'] ?> <?= $icol ?> text-sm"></i>
    </div>
    <div class="kpi-val text-2xl font-black <?= $txt ?>"><?= $c['val'] ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- ══════════════════ BREAKDOWN BAR ══════════════════ -->
<div id="retBreakBar" class="bg-white rounded-xl border p-4 mb-4 <?= $kpi['total']===0?'hidden':'' ?>">
    <div class="flex items-center justify-between mb-2">
        <span class="text-sm font-semibold text-gray-700">Retention Breakdown</span>
        <span id="retTotalLabel" class="text-xs text-gray-400"><?= number_format($kpi['total']) ?> total tracked</span>
    </div>
    <div id="retBar" class="w-full h-4 bg-gray-100 rounded-full overflow-hidden flex">
        <?php foreach ([['retained','bg-green-500'],['due_soon','bg-amber-400'],['overdue','bg-red-500'],['at_risk','bg-orange-400']] as [$seg,$cls]):
            $pct = $kpi['total'] > 0 ? round($kpi[$seg]/$kpi['total']*100,1) : 0;
            if ($pct == 0) continue;
            $display = max($pct, 2); // min 2% so non-zero segments are always visible
        ?>
        <div class="ret-bar-seg <?= $cls ?> h-full transition-all duration-500"
             data-seg="<?= $seg ?>" style="width:<?= $display ?>%"
             title="<?= ucfirst(str_replace('_',' ',$seg)) ?>: <?= $kpi[$seg] ?> (<?= $pct ?>%)"></div>
        <?php endforeach; ?>
    </div>
    <div class="flex gap-4 mt-2 flex-wrap">
        <?php foreach ([['retained','bg-green-500','Retained'],['due_soon','bg-amber-400','Due Soon'],['overdue','bg-red-500','Overdue'],['at_risk','bg-orange-400','At Risk']] as [$seg,$cls,$lbl]): ?>
        <div class="flex items-center gap-1.5 text-xs text-gray-500">
            <span class="inline-block w-2.5 h-2.5 rounded-sm <?= $cls ?>"></span>
            <?= $lbl ?> (<span class="ret-bar-label" data-seg="<?= $seg ?>"><?= number_format($kpi[$seg]) ?></span>)
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══════════════════ TOOLBAR ══════════════════ -->
<div class="bg-white rounded-xl border mb-3">
    <!-- Segment Tabs -->
    <div class="flex border-b overflow-x-auto" id="retSegTabs">
        <?php foreach ([
            'overdue' =>['🔴 Overdue',  $kpi['overdue'],  'text-red-600 border-red-600'],
            'due_soon'=>['🟡 Due Soon', $kpi['due_soon'], 'text-amber-600 border-amber-600'],
            'at_risk' =>['🟠 At Risk',  $kpi['at_risk'],  'text-orange-600 border-orange-600'],
            'retained'=>['🟢 Retained', $kpi['retained'], 'text-green-600 border-green-600'],
        ] as $seg=>[$lbl,$cnt,$acls]):
            $isA = $segment===$seg;
        ?>
        <button onclick="RM.go({segment:'<?= $seg ?>',page:1})"
                data-seg-tab="<?= $seg ?>"
                class="px-4 py-3 text-xs font-semibold whitespace-nowrap border-b-2 transition flex items-center gap-1.5
                       <?= $isA ? $acls : 'text-gray-500 border-transparent hover:text-gray-700' ?>">
            <?= $lbl ?>
            <span class="ret-tab-count px-1.5 py-0.5 rounded text-[10px] <?= $isA?'bg-gray-900/10 font-bold':'bg-gray-100 text-gray-400' ?>"><?= number_format($cnt) ?></span>
        </button>
        <?php endforeach; ?>
        <div class="ml-auto flex items-center px-3">
            <button onclick="RM.rebuild()" title="Rebuild log from all delivered orders"
                    class="text-xs bg-indigo-600 text-white px-3 py-1.5 rounded-lg hover:bg-indigo-700 flex items-center gap-1">
                <i class="fas fa-sync-alt"></i> Rebuild Log
            </button>
        </div>
    </div>
    <!-- Filters -->
    <div class="flex flex-wrap gap-2 p-3" id="retFilters">
        <input type="text" id="retSearch" value="<?= e($search) ?>" placeholder="Search customer, phone, product..."
               class="flex-1 min-w-[180px] px-3 py-1.5 border rounded-lg text-xs"
               onkeydown="if(event.key==='Enter') RM.go({search:this.value,page:1})">
        <select id="retStatusF" class="px-2.5 py-1.5 border rounded-lg text-xs" onchange="RM.go({status:this.value,page:1})">
            <option value="">All Statuses</option>
            <?php foreach (['pending'=>'⏳ Pending','contacted'=>'📞 Contacted','converted'=>'✅ Converted','not_interested'=>'🚫 Not Interested'] as $v=>$lbl): ?>
            <option value="<?= $v ?>" <?= $statusF===$v?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
        <select id="retAssignedF" class="px-2.5 py-1.5 border rounded-lg text-xs" onchange="RM.go({assigned:this.value,page:1})">
            <option value="">All Staff</option>
            <?php foreach ($adminUsers as $au): ?>
            <option value="<?= $au['id'] ?>" <?= $assignedF==$au['id']?'selected':'' ?>><?= e($au['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button onclick="RM.go({search:document.getElementById('retSearch').value,page:1})"
                class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs">Search</button>
        <button id="retClearBtn" onclick="RM.clearFilters()"
                class="px-3 py-1.5 bg-gray-100 text-gray-500 rounded-lg text-xs <?= ($search||$statusF||$assignedF)?'':'hidden' ?>">✕ Clear</button>
    </div>
</div>

<!-- ══════════════════ TABLE ══════════════════ -->
<div class="bg-white rounded-xl border overflow-hidden relative">
    <!-- Loading overlay -->
    <div id="retLoading" class="hidden absolute inset-0 bg-white/70 z-10 flex items-center justify-center rounded-xl">
        <div class="flex items-center gap-2 bg-white border shadow-sm px-4 py-2 rounded-full text-xs text-gray-600">
            <svg class="animate-spin w-4 h-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
            </svg>
            Loading...
        </div>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b">
            <tr>
                <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 uppercase">Customer</th>
                <th class="text-left px-3 py-2.5 text-xs font-semibold text-gray-500 uppercase">Product</th>
                <th class="text-left px-3 py-2.5 text-xs font-semibold text-gray-500 uppercase">Due Date</th>
                <th class="text-left px-3 py-2.5 text-xs font-semibold text-gray-500 uppercase hidden md:table-cell">Order</th>
                <th class="text-left px-3 py-2.5 text-xs font-semibold text-gray-500 uppercase">Status</th>
                <th class="text-left px-3 py-2.5 text-xs font-semibold text-gray-500 uppercase hidden lg:table-cell">Assigned</th>
                <th class="text-right px-4 py-2.5 text-xs font-semibold text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody id="retTbody" class="divide-y divide-gray-50">
            <?php
            if (empty($rows)) {
                echo '<tr id="ret-empty-row"><td colspan="7" class="text-center py-12 text-gray-400">
                    <div class="text-4xl mb-2">📋</div>
                    <div class="font-medium text-gray-600">No records in this segment</div>
                    <div class="text-xs mt-2 text-gray-400 max-w-xs mx-auto">
                        Click <strong class="text-indigo-600">Rebuild Log</strong> to scan all delivered orders.
                    </div></td></tr>';
            }
            foreach ($rows as $row):
                $daysOver  = (int)$row['days_overdue'];
                $daysRem   = (int)$row['days_remaining'];
                $ph        = preg_replace('/[^0-9]/', '', $row['cust_phone']);
                $waMsg     = rawurlencode("হ্যালো {$row['cust_name']}! আপনার '{$row['prod_name']}' আবার লাগতে পারে। আমরা সাহায্য করতে পারি? 😊");
                $waLink    = "https://wa.me/880{$ph}?text={$waMsg}";
                $orderLink = adminUrl('pages/order-view.php?id='.$row['order_id']);
                $sbadge    = retStatusBadge($row['status']);
                $custNameE = addslashes(e($row['cust_name']));
                $noteEsc   = addslashes(htmlspecialchars($row['notes'] ?? ''));
            ?>
            <tr class="ret-row hover:bg-gray-50/70 transition-all duration-300"
                data-log-id="<?= $row['id'] ?>" data-segment="<?= $row['segment'] ?>">
              <td class="px-4 py-3">
                <div class="font-semibold text-gray-800 text-sm"><?= e($row['cust_name']) ?></div>
                <div class="text-xs text-gray-400 mt-0.5 font-mono"><?= e($row['cust_phone']) ?></div>
                <?php if ($row['cust_email']): ?><div class="text-[10px] text-gray-400 truncate max-w-[140px]"><?= e($row['cust_email']) ?></div><?php endif; ?>
                <button onclick="RM.openProfile(<?= (int)$row['customer_id'] ?>,'<?= $custNameE ?>')"
                        class="text-[10px] text-indigo-500 hover:underline mt-0.5">⚙ Profile Override</button>
              </td>
              <td class="px-3 py-3">
                <div class="flex items-center gap-2">
                  <?php if ($row['prod_img']): ?><img src="<?= imgSrc('products',$row['prod_img']) ?>" class="w-8 h-8 rounded object-cover shrink-0"><?php endif; ?>
                  <div>
                    <div class="font-medium text-gray-800 text-xs leading-tight max-w-[140px]"><?= e($row['prod_name']) ?></div>
                    <?php if ($row['retention_value']): ?><div class="text-[10px] text-gray-400">Every <?= (int)$row['retention_value'] ?> <?= e($row['retention_unit']) ?></div>
                    <?php else: ?><div class="text-[10px] text-gray-300">Default period</div><?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="px-3 py-3">
                <div class="font-medium text-gray-700 text-xs"><?= date('d M Y', strtotime($row['due_date'])) ?></div>
                <?php if ($row['segment']==='overdue'&&$daysOver>0): ?><div class="text-[10px] text-red-600 font-semibold mt-0.5">⚠ <?= $daysOver ?>d overdue</div>
                <?php elseif ($row['segment']==='due_soon'&&$daysRem>=0): ?><div class="text-[10px] text-amber-600 font-semibold mt-0.5">🕐 <?= $daysRem ?>d left</div>
                <?php elseif ($row['segment']==='retained'): ?><div class="text-[10px] text-green-600 font-semibold mt-0.5">✓ Reordered</div>
                <?php else: ?><div class="text-[10px] text-orange-500 mt-0.5">In <?= abs($daysRem) ?>d</div><?php endif; ?>
              </td>
              <td class="px-3 py-3 hidden md:table-cell">
                <a href="<?= $orderLink ?>" class="text-xs font-mono text-blue-600 hover:underline"><?= e($row['order_number']) ?></a>
                <div class="text-[10px] text-gray-400">৳<?= number_format($row['order_total']) ?></div>
              </td>
              <td class="px-3 py-3">
                <select class="ret-status-select text-xs px-2 py-1 border rounded-lg <?= $sbadge ?>"
                        data-log-id="<?= $row['id'] ?>" data-segment="<?= $row['segment'] ?>"
                        onchange="RM.updateStatus(this)">
                  <?php foreach (['pending'=>'⏳ Pending','contacted'=>'📞 Contacted','converted'=>'✅ Converted','not_interested'=>'🚫 Not Interested'] as $v=>$lbl): ?>
                  <option value="<?= $v ?>" <?= $row['status']===$v?'selected':'' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if ($row['contacted_name']): ?><div class="text-[10px] text-gray-400 mt-0.5">by <?= e($row['contacted_name']) ?></div><?php endif; ?>
                <?php if ($row['notes']): ?><div class="text-[10px] text-blue-500 mt-0.5 max-w-[120px] truncate" title="<?= htmlspecialchars($row['notes']) ?>">📝 <?= e(mb_strimwidth($row['notes'],0,30,'...')) ?></div><?php endif; ?>
              </td>
              <td class="px-3 py-3 hidden lg:table-cell">
                <select class="text-xs px-2 py-1 border rounded-lg" onchange="RM.assignStaff(this,<?= $row['id'] ?>)">
                  <option value="">Unassigned</option>
                  <?php foreach ($adminUsers as $au): ?>
                  <option value="<?= $au['id'] ?>" <?= $row['assigned_to']==$au['id']?'selected':'' ?>><?= e($au['full_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-1.5">
                  <a href="<?= $waLink ?>" target="_blank"
                     class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-green-100 text-green-600 hover:bg-green-600 hover:text-white transition text-xs" title="WhatsApp">
                    <i class="fab fa-whatsapp"></i>
                  </a>
                  <button onclick="RM.openNote(<?= $row['id'] ?>,`<?= $noteEsc ?>`)"
                          class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-blue-100 text-blue-600 hover:bg-blue-600 hover:text-white transition text-xs" title="Note">
                    <i class="fas fa-sticky-note"></i>
                  </button>
                  <a href="<?= $orderLink ?>"
                     class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-600 hover:text-white transition text-xs" title="Order">
                    <i class="fas fa-external-link-alt"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<div id="retPagination" class="mt-3">
<?php if ($totalPages > 1): ?>
<div class="flex items-center justify-between px-1">
    <p class="text-xs text-gray-500">Page <strong><?= $page ?></strong> of <?= $totalPages ?> · <?= number_format($total) ?> records</p>
    <div class="flex gap-1">
        <?php if ($page > 1): ?><button onclick="RM.go({page:<?= $page-1 ?>})" class="px-2.5 py-1 text-xs rounded bg-white border hover:bg-gray-50">←</button><?php endif; ?>
        <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?>
        <button onclick="RM.go({page:<?= $i ?>})" class="px-2.5 py-1 text-xs rounded <?= $i===$page?'bg-blue-600 text-white':'bg-white border hover:bg-gray-50' ?>"><?= $i ?></button>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><button onclick="RM.go({page:<?= $page+1 ?>})" class="px-2.5 py-1 text-xs rounded bg-white border hover:bg-gray-50">→</button><?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>

<!-- ══════════════════ MODALS ══════════════════ -->
<!-- Note -->
<div id="noteModal" class="fixed inset-0 z-50 hidden bg-black/40 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-5">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2"><i class="fas fa-sticky-note text-blue-500"></i> Follow-up Note</h3>
        <input type="hidden" id="noteLogId">
        <textarea id="noteText" rows="4" class="w-full px-3 py-2 border rounded-lg text-sm resize-none" placeholder="Enter your note..."></textarea>
        <div class="flex gap-2 mt-3">
            <button onclick="RM.saveNote()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 flex-1">Save Note</button>
            <button onclick="RM.closeModal('noteModal')" class="bg-gray-100 text-gray-600 px-4 py-2 rounded-lg text-sm">Cancel</button>
        </div>
    </div>
</div>

<!-- Profile Override -->
<div id="profileModal" class="fixed inset-0 z-50 hidden bg-black/40 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-5">
        <h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2"><i class="fas fa-user-cog text-indigo-500"></i> Customer Retention Profile</h3>
        <p id="profileCustName" class="text-xs text-gray-400 mb-4"></p>
        <input type="hidden" id="profileCustId">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Custom Retention Period <span class="font-normal text-gray-400">(overrides product default)</span></label>
        <div class="flex gap-2 mb-3">
            <input type="number" id="profileRetVal" min="1" max="365" placeholder="e.g. 2" class="w-24 px-3 py-2 border rounded-lg text-sm">
            <select id="profileRetUnit" class="flex-1 px-3 py-2 border rounded-lg text-sm">
                <option value="days">Days</option><option value="weeks">Weeks</option><option value="months">Months</option>
            </select>
        </div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Internal Notes</label>
        <textarea id="profileNotes" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm resize-none mb-4" placeholder="e.g. On subscription, prefers monthly contact..."></textarea>
        <div class="flex gap-2">
            <button onclick="RM.saveProfile()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 flex-1">Save Profile</button>
            <button onclick="RM.closeModal('profileModal')" class="bg-gray-100 text-gray-600 px-4 py-2 rounded-lg text-sm">Cancel</button>
        </div>
    </div>
</div>

<!-- Rebuild overlay -->
<div id="rebuildOverlay" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl p-6 text-center w-72">
        <div class="text-3xl mb-3">⚙️</div>
        <div class="font-bold text-gray-800 mb-1">Rebuilding Log...</div>
        <div class="text-xs text-gray-400">Scanning all delivered orders</div>
    </div>
</div>

<!-- Converted toast -->
<div id="retToast" class="fixed bottom-5 right-5 z-50 hidden">
    <div class="bg-green-600 text-white px-4 py-3 rounded-xl shadow-xl flex items-center gap-3 text-sm font-medium">
        <i class="fas fa-check-circle text-lg"></i>
        <span id="retToastMsg">Marked as Retained!</span>
    </div>
</div>

<script>
const RET_URL  = '<?= adminUrl('pages/customer-retention.php') ?>';
const RET_BASE = <?= json_encode(['overdue'=>$kpi['overdue'],'due_soon'=>$kpi['due_soon'],'at_risk'=>$kpi['at_risk'],'retained'=>$kpi['retained'],'rate'=>$kpi['rate'],'contacted'=>$kpi['contacted'],'converted'=>$kpi['converted']]) ?>;

const RM = {
    state: {
        segment:  '<?= $segment ?>',
        search:   '<?= addslashes($search) ?>',
        status:   '<?= addslashes($statusF) ?>',
        assigned: '<?= $assignedF ?>',
        page:     <?= $page ?>,
    },

    // ── Core fetch ─────────────────────────────────────────────────────
    go(params = {}) {
        Object.assign(RM.state, params);
        const url = new URL(RET_URL);
        for (const [k,v] of Object.entries(RM.state)) if (v) url.searchParams.set(k, v);
        history.pushState(RM.state, '', url);
        RM._fetch();
    },

    _fetch() {
        document.getElementById('retLoading').classList.remove('hidden');
        const url = new URL(RET_URL);
        url.searchParams.set('_frag','1');
        for (const [k,v] of Object.entries(RM.state)) if (v) url.searchParams.set(k, v);

        fetch(url)
            .then(r => r.json())
            .then(d => {
                document.getElementById('retTbody').innerHTML      = d.rows;
                document.getElementById('retPagination').innerHTML = d.pagination;
                RM._updateKpi(d.kpi);
                RM._updateTabs(d.kpi, d.segment);
                RM._syncFilters();
                document.getElementById('retLoading').classList.add('hidden');
            })
            .catch(() => document.getElementById('retLoading').classList.add('hidden'));
    },

    // ── KPI update ─────────────────────────────────────────────────────
    _updateKpi(kpi) {
        const map = {
            'kpi-rate':      kpi.rate + '%',
            'kpi-overdue':   kpi.overdue.toLocaleString(),
            'kpi-due_soon':  kpi.due_soon.toLocaleString(),
            'kpi-at_risk':   kpi.at_risk.toLocaleString(),
            'kpi-retained':  kpi.retained.toLocaleString(),
            'kpi-contacted': kpi.contacted.toLocaleString(),
            'kpi-converted': kpi.converted.toLocaleString(),
        };
        for (const [id, val] of Object.entries(map)) {
            const el = document.getElementById(id);
            if (el) el.querySelector('.kpi-val').textContent = val;
        }
        // Breakdown bar
        const total = kpi.overdue + kpi.due_soon + kpi.at_risk + kpi.retained;
        document.getElementById('retTotalLabel').textContent = total.toLocaleString() + ' total tracked';
        document.getElementById('retBreakBar').classList.toggle('hidden', total === 0);
        document.querySelectorAll('.ret-bar-seg').forEach(el => {
            const seg = el.dataset.seg;
            const count = kpi[seg] || 0;
            const pct   = total > 0 ? Math.round(count / total * 1000) / 10 : 0;
            el.style.width   = pct === 0 ? '0%' : Math.max(pct, 2) + '%';
            el.style.display = pct === 0 ? 'none' : '';
            el.title = seg.replace('_',' ') + ': ' + count + ' (' + pct + '%)';
        });
        document.querySelectorAll('.ret-bar-label').forEach(el => {
            const seg = el.dataset.seg;
            el.textContent = (kpi[seg] || 0).toLocaleString();
        });
    },

    _updateTabs(kpi, activeSeg) {
        document.querySelectorAll('[data-seg-tab]').forEach(btn => {
            const seg = btn.dataset.segTab;
            const isActive = seg === activeSeg;
            const acls = {
                overdue: 'text-red-600 border-red-600',
                due_soon:'text-amber-600 border-amber-600',
                at_risk: 'text-orange-600 border-orange-600',
                retained:'text-green-600 border-green-600',
            };
            btn.className = btn.className
                .replace(/text-\w+-600 border-\w+-600/g,'')
                .replace('text-gray-500 border-transparent hover:text-gray-700','')
                .trim();
            if (isActive) {
                btn.className += ' ' + acls[seg];
            } else {
                btn.className += ' text-gray-500 border-transparent hover:text-gray-700';
            }
            const countEl = btn.querySelector('.ret-tab-count');
            if (countEl) {
                countEl.textContent = (kpi[seg] || 0).toLocaleString();
                countEl.className = 'ret-tab-count px-1.5 py-0.5 rounded text-[10px] ' +
                    (isActive ? 'bg-gray-900/10 font-bold' : 'bg-gray-100 text-gray-400');
            }
        });
    },

    _syncFilters() {
        const s = RM.state;
        document.getElementById('retSearch').value    = s.search   || '';
        document.getElementById('retStatusF').value   = s.status   || '';
        document.getElementById('retAssignedF').value = s.assigned || '';
        document.getElementById('retClearBtn').classList.toggle('hidden', !s.search && !s.status && !s.assigned);
    },

    clearFilters() {
        RM.go({search:'', status:'', assigned:'', page:1});
    },

    // ── Status update with retained transition ─────────────────────────
    updateStatus(sel) {
        const logId  = sel.dataset.logId;
        const status = sel.value;
        const fromSeg = sel.dataset.segment;
        const cmap = {
            pending:        'bg-yellow-100 text-yellow-700',
            contacted:      'bg-blue-100 text-blue-700',
            converted:      'bg-green-100 text-green-700',
            not_interested: 'bg-gray-100 text-gray-500',
        };
        sel.className = 'ret-status-select text-xs px-2 py-1 border rounded-lg ' + (cmap[status] || '');

        RM._post({action:'update_status', log_id:logId, status}, d => {
            if (!d.ok) { alert('Failed to update'); return; }
            RM._updateKpi(d.kpi);
            RM._updateTabs(d.kpi, RM.state.segment);

            if (d.new_segment === 'retained') {
                // Animate row out then refresh current segment
                const row = document.querySelector(`tr[data-log-id="${logId}"]`);
                if (row) {
                    row.style.transition = 'opacity 0.4s, transform 0.4s';
                    row.style.opacity    = '0';
                    row.style.transform  = 'translateX(40px)';
                    // Show toast
                    RM._toast('✓ Moved to Retained!');
                    setTimeout(() => {
                        row.remove();
                        // If tbody now empty, show empty state
                        const tbody = document.getElementById('retTbody');
                        if (!tbody.querySelector('.ret-row')) {
                            tbody.innerHTML = '<tr id="ret-empty-row"><td colspan="7" class="text-center py-12 text-gray-400"><div class="text-4xl mb-2">🎉</div><div class="font-medium text-gray-600">All caught up in this segment!</div></td></tr>';
                        }
                    }, 420);
                }
            }
        });
    },

    assignStaff(sel, logId) {
        RM._post({action:'assign', log_id:logId, assign_to:sel.value}, d => {
            if (!d.ok) alert('Failed to assign');
        });
    },

    // ── Notes ──────────────────────────────────────────────────────────
    openNote(logId, existingNote) {
        document.getElementById('noteLogId').value = logId;
        document.getElementById('noteText').value  = existingNote || '';
        document.getElementById('noteModal').classList.remove('hidden');
        setTimeout(() => document.getElementById('noteText').focus(), 50);
    },

    saveNote() {
        const logId = document.getElementById('noteLogId').value;
        const note  = document.getElementById('noteText').value.trim();
        if (!note) { alert('Please enter a note'); return; }
        RM._post({action:'add_note', log_id:logId, note}, d => {
            if (d.ok) {
                RM.closeModal('noteModal');
                // Update note preview in row without full refresh
                const row = document.querySelector(`tr[data-log-id="${logId}"]`);
                if (row) {
                    let preview = row.querySelector('.ret-note-preview');
                    if (!preview) {
                        const statusCell = row.querySelector('.ret-status-select')?.closest('td');
                        if (statusCell) {
                            preview = document.createElement('div');
                            preview.className = 'ret-note-preview text-[10px] text-blue-500 mt-0.5 max-w-[120px] truncate';
                            statusCell.appendChild(preview);
                        }
                    }
                    if (preview) preview.textContent = '📝 ' + d.note.substring(0,30) + (d.note.length>30?'...':'');
                }
            } else {
                alert('Failed to save note');
            }
        });
    },

    // ── Profile ────────────────────────────────────────────────────────
    openProfile(custId, custName) {
        document.getElementById('profileCustId').value         = custId;
        document.getElementById('profileCustName').textContent = custName;
        document.getElementById('profileRetVal').value         = '';
        document.getElementById('profileRetUnit').value        = 'days';
        document.getElementById('profileNotes').value          = '';
        fetch(RET_URL + '?_profile=' + custId)
            .then(r => r.json())
            .then(d => {
                if (d.profile) {
                    document.getElementById('profileRetVal').value  = d.profile.custom_retention_value || '';
                    document.getElementById('profileRetUnit').value = d.profile.custom_retention_unit  || 'days';
                    document.getElementById('profileNotes').value   = d.profile.notes || '';
                }
            }).catch(() => {});
        document.getElementById('profileModal').classList.remove('hidden');
    },

    saveProfile() {
        RM._post({
            action:                 'update_profile',
            customer_id:            document.getElementById('profileCustId').value,
            custom_retention_value: document.getElementById('profileRetVal').value,
            custom_retention_unit:  document.getElementById('profileRetUnit').value,
            notes:                  document.getElementById('profileNotes').value,
        }, d => {
            if (d.ok) RM.closeModal('profileModal');
            else alert('Failed to save profile');
        });
    },

    // ── Rebuild ────────────────────────────────────────────────────────
    rebuild() {
        const _ok = await window._confirmAsync('Rebuild retention log from all delivered orders?\nThis scans your full order history.'); if(!_ok) return;
        document.getElementById('rebuildOverlay').classList.remove('hidden');
        RM._post({action:'rebuild'}, d => {
            document.getElementById('rebuildOverlay').classList.add('hidden');
            if (d.kpi) {
                RM._updateKpi(d.kpi);
                RM._updateTabs(d.kpi, RM.state.segment);
            }
            RM._toast((d.built || 0) + ' new records added!');
            RM._fetch();
        });
    },

    // ── Utils ──────────────────────────────────────────────────────────
    closeModal(id) { document.getElementById(id).classList.add('hidden'); },

    _toast(msg) {
        const t = document.getElementById('retToast');
        document.getElementById('retToastMsg').textContent = msg;
        t.classList.remove('hidden');
        clearTimeout(RM._toastTimer);
        RM._toastTimer = setTimeout(() => t.classList.add('hidden'), 3000);
    },

    _post(data, cb) {
        data._ajax = 1;
        const fd = new FormData();
        for (const [k,v] of Object.entries(data)) fd.append(k, v ?? '');
        fetch(RET_URL, {method:'POST', body:fd})
            .then(r => r.json()).then(cb)
            .catch(e => alert('Error: '+e.message));
    },
};

// Browser back/forward
window.addEventListener('popstate', e => {
    if (e.state) { RM.state = e.state; RM._fetch(); }
});
history.replaceState(RM.state, '', location.href);

// Close modals on backdrop click
['noteModal','profileModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', e => {
        if (e.target.id === id) RM.closeModal(id);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
