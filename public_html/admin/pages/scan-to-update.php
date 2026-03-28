<?php
/**
 * Scan To Update - Barcode/invoice scanner for bulk order status updates
 * Tabs: Scan To Shipping, Scan To Return, Scan To RTS (Return To Sender)
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Scan To Update';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();

// Handle AJAX scan request
if (isset($_POST['ajax_scan'])) {
    header('Content-Type: application/json');
    $barcode = trim($_POST['barcode'] ?? '');
    $scanMode = sanitize($_POST['scan_mode'] ?? 'shipping');
    $searchAll = intval($_POST['search_all'] ?? 0);

    if (!$barcode) {
        echo json_encode(['success' => false, 'message' => 'No barcode provided']);
        exit;
    }

    // Find the order by order_number, courier_consignment_id, or courier_tracking_id
    $order = $db->fetch("SELECT id, order_number, order_status, customer_name, customer_phone, 
            courier_name, courier_consignment_id, courier_tracking_id, total, payment_method
        FROM orders WHERE order_number = ? OR courier_consignment_id = ? OR courier_tracking_id = ? 
        LIMIT 1", [$barcode, $barcode, $barcode]);

    if (!$order) {
        // Try partial match
        $order = $db->fetch("SELECT id, order_number, order_status, customer_name, customer_phone,
                courier_name, courier_consignment_id, courier_tracking_id, total, payment_method
            FROM orders WHERE order_number LIKE ? OR courier_consignment_id LIKE ? 
            LIMIT 1", ["%$barcode%", "%$barcode%"]);
    }

    if (!$order) {
        echo json_encode([
            'success' => false,
            'message' => 'Order not found: ' . $barcode,
            'barcode' => $barcode,
        ]);
        exit;
    }

    // Determine target status based on scan mode
    $targetStatus = '';
    $statusLabel = '';
    $allowedFrom = [];

    switch ($scanMode) {
        case 'shipping':
            $targetStatus = 'shipped';
            $statusLabel = 'Shipped';
            $allowedFrom = ['processing', 'confirmed', 'pending', 'on_hold', 'advance_payment', 'ready_to_ship'];
            break;
        case 'pending_return':
            // Parcel physically received back from courier — mark as pending return for admin review
            $targetStatus = 'pending_return';
            $statusLabel = 'Pending Return';
            $allowedFrom = ['shipped', 'delivered', 'partial_delivered', 'on_hold'];
            break;
        case 'return':
            // Admin confirms the return after reviewing — only from pending_return
            $targetStatus = 'returned';
            $statusLabel = 'Returned';
            $allowedFrom = ['pending_return'];
            break;
        case 'rts':
            // Return To Sender — parcel received, mark pending return (not directly returned)
            $targetStatus = 'pending_return';
            $statusLabel = 'Pending Return (RTS)';
            $allowedFrom = ['shipped', 'pending_return', 'on_hold'];
            break;
        case 'delivered':
            $targetStatus = 'delivered';
            $statusLabel = 'Delivered';
            $allowedFrom = ['shipped', 'partial_delivered'];
            break;
        case 'cancelled':
            $targetStatus = 'cancelled';
            $statusLabel = 'Cancelled';
            $allowedFrom = ['pending', 'processing', 'confirmed', 'on_hold', 'no_response', 'good_but_no_response', 'pending_cancel'];
            break;
    }

    // Check if already in target status
    if ($order['order_status'] === $targetStatus) {
        echo json_encode([
            'success' => false,
            'message' => "Already {$statusLabel}",
            'barcode' => $barcode,
            'order' => $order,
            'reason' => 'already_done',
        ]);
        exit;
    }

    // Check if transition is allowed
    if (!in_array($order['order_status'], $allowedFrom)) {
        echo json_encode([
            'success' => false,
            'message' => "Cannot change from '{$order['order_status']}' to '{$targetStatus}'",
            'barcode' => $barcode,
            'order' => $order,
            'reason' => 'invalid_transition',
        ]);
        exit;
    }

    // Perform the update
    $updateData = ['order_status' => $targetStatus];
    if ($targetStatus === 'shipped') {
        $updateData['shipped_at'] = date('Y-m-d H:i:s');
    } elseif ($targetStatus === 'delivered') {
        $updateData['delivered_at'] = date('Y-m-d H:i:s');
    }

    $db->update('orders', $updateData, 'id = ?', [$order['id']]);
    $db->insert('order_status_history', [
        'order_id' => $order['id'],
        'status' => $targetStatus,
        'note' => "Scan to {$scanMode}: barcode {$barcode}",
        'changed_by' => getAdminId(),
    ]);
    logActivity(getAdminId(), 'scan_update', 'orders', $order['id']);

    // Award/refund credits
    if ($targetStatus === 'delivered') {
        try { awardOrderCredits($order['id']); } catch (\Throwable $e) {}
    }
    if ($targetStatus === 'cancelled') {
        try { refundOrderCreditsOnCancel($order['id']); } catch (\Throwable $e) {}
    }

    echo json_encode([
        'success' => true,
        'message' => "#{$order['order_number']} → {$statusLabel}",
        'barcode' => $barcode,
        'order' => $order,
        'new_status' => $targetStatus,
    ]);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
$tab = $_GET['tab'] ?? 'shipping';
?>

<style>
@keyframes scanPulse { 0%{box-shadow:0 0 0 0 rgba(59,130,246,0.5)} 70%{box-shadow:0 0 0 12px rgba(59,130,246,0)} 100%{box-shadow:0 0 0 0 rgba(59,130,246,0)} }
.scan-pulse { animation: scanPulse 1s ease-out; }
@keyframes slideIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
.slide-in { animation: slideIn 0.25s ease-out; }
.scan-input:focus { box-shadow: 0 0 0 3px rgba(59,130,246,0.3); }
</style>

<!-- Tabs -->
<div class="border-b mb-6">
    <div class="flex gap-0">
        <?php
        $tabs = [
            'shipping'       => ['Scan To Shipping', 'fa-truck', 'blue'],
            'pending_return' => ['Scan To Pending Return', 'fa-box-open', 'amber'],
            'return'         => ['Confirm Return', 'fa-undo-alt', 'orange'],
            'rts'            => ['Scan To RTS', 'fa-reply-all', 'red'],
            'delivered'      => ['Scan To Delivered', 'fa-check-circle', 'green'],
            'cancelled'      => ['Scan To Cancel', 'fa-times-circle', 'gray'],
        ];
        foreach ($tabs as $tKey => $tData):
            $isActive = ($tab === $tKey);
        ?>
        <a href="?tab=<?= $tKey ?>" class="px-5 py-3 text-sm font-medium border-b-2 transition <?= $isActive 
            ? "border-{$tData[2]}-600 text-{$tData[2]}-700 bg-{$tData[2]}-50/50" 
            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
            <i class="fas <?= $tData[1] ?> mr-1.5"></i><?= $tData[0] ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="max-w-4xl mx-auto">
    <!-- Scan Header -->
    <div class="text-center mb-4">
        <h2 class="text-xl font-bold text-gray-800"><?= $tabs[$tab][0] ?></h2>
        <p class="text-sm text-gray-500 mt-1">
            <?php
            $modeDescriptions = [
                'shipping' => 'Scan orders to mark as <strong>Shipped</strong>. Accepts: Processing, Confirmed, Pending orders.',
                'return' => 'Scan orders to mark as <strong>Returned</strong>. Accepts: Shipped, Delivered orders.',
                'rts' => 'Scan orders for <strong>Return To Sender</strong>. Accepts: Shipped, Pending Return orders.',
                'delivered' => 'Scan orders to mark as <strong>Delivered</strong>. Accepts: Shipped orders.',
                'cancelled' => 'Scan orders to mark as <strong>Cancelled</strong>. Accepts: Pending, Processing orders.',
            ];
            echo $modeDescriptions[$tab];
            ?>
        </p>
    </div>

    <!-- Scanner Input -->
    <div class="bg-white rounded-2xl border shadow-sm p-6 mb-5">
        <div class="flex items-center gap-2 text-gray-400 text-xs mb-2">
            <i class="fas fa-qrcode"></i>
            <span>Click input to activate scanner or type manually</span>
        </div>
        <div class="relative">
            <input type="text" id="scanInput" autofocus
                class="scan-input w-full text-lg px-5 py-4 border-2 border-gray-200 rounded-xl focus:border-blue-500 outline-none transition placeholder-gray-300"
                placeholder="Scan or type invoice/order number and press Enter..."
                autocomplete="off" autocorrect="off" spellcheck="false">
            <div class="absolute right-4 top-1/2 -translate-y-1/2 flex items-center gap-2">
                <kbd class="text-[10px] text-gray-400 bg-gray-100 border border-gray-200 px-1.5 py-0.5 rounded font-mono">Enter</kbd>
                <i class="fas fa-barcode text-gray-300 text-xl"></i>
            </div>
        </div>

        <!-- Debug manual input -->
        <div class="mt-3 flex items-center gap-2">
            <span class="text-xs text-gray-400 font-medium">Debug:</span>
            <div class="flex-1 flex gap-2">
                <input type="text" id="manualInput" class="flex-1 border rounded-lg px-3 py-2 text-sm" placeholder="Enter invoice # manually...">
                <button onclick="processManualInput()" class="bg-gray-100 hover:bg-gray-200 px-3 py-2 rounded-lg text-gray-600 transition">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-4 mb-5">
        <div class="bg-white rounded-xl border p-4 flex items-center justify-between">
            <div>
                <p class="text-xs text-gray-400 font-medium">Total Scans</p>
                <p class="text-2xl font-bold text-gray-800" id="statTotal">0</p>
            </div>
            <i class="fas fa-sync-alt text-gray-300 text-xl"></i>
        </div>
        <div class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center justify-between">
            <div>
                <p class="text-xs text-green-600 font-medium">Successful</p>
                <p class="text-2xl font-bold text-green-600" id="statSuccess">0</p>
            </div>
            <i class="fas fa-check-circle text-green-300 text-xl"></i>
        </div>
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-center justify-between">
            <div>
                <p class="text-xs text-red-600 font-medium">Failed</p>
                <p class="text-2xl font-bold text-red-600" id="statFailed">0</p>
            </div>
            <i class="fas fa-times-circle text-red-300 text-xl"></i>
        </div>
    </div>

    <!-- Controls -->
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-3">
            <!-- Sound toggle -->
            <button id="soundToggle" onclick="toggleSound()" class="flex items-center gap-1.5 px-3 py-1.5 bg-white border rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50 transition">
                <i class="fas fa-volume-up" id="soundIcon"></i>
                <span id="soundLabel">On</span>
            </button>
            <!-- Volume buttons -->
            <div class="flex bg-white border rounded-lg overflow-hidden text-xs">
                <button onclick="setVolume(0.3)" class="px-2.5 py-1.5 hover:bg-gray-50 volume-btn" data-vol="0.3">Low</button>
                <button onclick="setVolume(0.6)" class="px-2.5 py-1.5 hover:bg-gray-50 border-x volume-btn bg-blue-50 text-blue-600" data-vol="0.6">Med</button>
                <button onclick="setVolume(1.0)" class="px-2.5 py-1.5 hover:bg-gray-50 volume-btn" data-vol="1.0">High</button>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="clearResults()" class="px-3 py-1.5 bg-white border rounded-lg text-xs font-medium text-gray-500 hover:bg-gray-50 hover:text-red-600 transition">
                <i class="fas fa-trash-alt mr-1"></i>Clear
            </button>
            <select id="statusFilter" onchange="filterResults()" class="border rounded-lg px-3 py-1.5 text-xs bg-white">
                <option value="">All Status</option>
                <option value="success">Success Only</option>
                <option value="failed">Failed Only</option>
                <option value="already">Already Done</option>
            </select>
        </div>
    </div>

    <!-- Scan Results -->
    <div class="bg-white rounded-xl border shadow-sm">
        <div class="px-4 py-3 border-b">
            <h3 class="font-semibold text-gray-800 text-sm">Scan Results</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="text-left px-4 py-2.5 font-medium">Time</th>
                        <th class="text-left px-4 py-2.5 font-medium">Barcode / Order</th>
                        <th class="text-left px-4 py-2.5 font-medium">Customer</th>
                        <th class="text-center px-4 py-2.5 font-medium">Status</th>
                        <th class="text-left px-4 py-2.5 font-medium">Message</th>
                        <th class="text-right px-4 py-2.5 font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody id="scanResults" class="divide-y">
                    <tr id="emptyRow"><td colspan="6" class="px-4 py-10 text-center text-gray-400">No items scanned yet</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Audio elements (generated via Web Audio API) -->
<script>
const SCAN_MODE = '<?= $tab ?>';
let soundEnabled = true;
let volume = 0.6;
let totalScans = 0, successScans = 0, failedScans = 0;
const scannedBarcodes = new Set();

// Audio context for beeps
const AudioCtx = window.AudioContext || window.webkitAudioContext;
let audioCtx;
function ensureAudio() { if (!audioCtx) audioCtx = new AudioCtx(); }

function playBeep(frequency, duration, type = 'sine') {
    if (!soundEnabled) return;
    ensureAudio();
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.type = type;
    osc.frequency.value = frequency;
    gain.gain.value = volume * 0.3;
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.start();
    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
    osc.stop(audioCtx.currentTime + duration);
}

function playSuccess() {
    playBeep(880, 0.15);
    setTimeout(() => playBeep(1320, 0.2), 100);
}
function playError() {
    playBeep(300, 0.2, 'square');
    setTimeout(() => playBeep(200, 0.3, 'square'), 150);
}
function playDuplicate() {
    playBeep(440, 0.1);
    setTimeout(() => playBeep(440, 0.1), 120);
}

function toggleSound() {
    soundEnabled = !soundEnabled;
    document.getElementById('soundIcon').className = 'fas ' + (soundEnabled ? 'fa-volume-up' : 'fa-volume-mute');
    document.getElementById('soundLabel').textContent = soundEnabled ? 'On' : 'Off';
}

function setVolume(v) {
    volume = v;
    document.querySelectorAll('.volume-btn').forEach(b => {
        b.classList.remove('bg-blue-50', 'text-blue-600');
        if (parseFloat(b.dataset.vol) === v) b.classList.add('bg-blue-50', 'text-blue-600');
    });
    playBeep(660, 0.1); // test beep
}

// Scanner input handler
const scanInput = document.getElementById('scanInput');
let scanBuffer = '';
let scanTimeout;

scanInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const val = this.value.trim();
        if (val) processScan(val);
        this.value = '';
    }
});

// Keep focus on scanner input
document.addEventListener('click', function(e) {
    if (!e.target.closest('#manualInput') && !e.target.closest('select') && !e.target.closest('a') && !e.target.closest('button')) {
        scanInput.focus();
    }
});

function processManualInput() {
    const input = document.getElementById('manualInput');
    const val = input.value.trim();
    if (val) processScan(val);
    input.value = '';
    scanInput.focus();
}

document.getElementById('manualInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); processManualInput(); }
});

function processScan(barcode) {
    // Check duplicate
    if (scannedBarcodes.has(barcode)) {
        playDuplicate();
        addResult({
            success: false,
            barcode: barcode,
            message: 'Already scanned in this session',
            reason: 'duplicate',
        });
        return;
    }

    // Visual feedback
    scanInput.classList.add('scan-pulse');
    setTimeout(() => scanInput.classList.remove('scan-pulse'), 1000);

    // Send AJAX
    const fd = new FormData();
    fd.append('ajax_scan', '1');
    fd.append('barcode', barcode);
    fd.append('scan_mode', SCAN_MODE);

    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            scannedBarcodes.add(barcode);
            addResult(data);
            if (data.success) { playSuccess(); } else { playError(); }
        })
        .catch(err => {
            playError();
            addResult({ success: false, barcode: barcode, message: 'Network error: ' + err.message });
        });
}

function addResult(data) {
    totalScans++;
    if (data.success) successScans++; else failedScans++;
    updateStats();

    // Remove empty row
    const emptyRow = document.getElementById('emptyRow');
    if (emptyRow) emptyRow.remove();

    const tbody = document.getElementById('scanResults');
    const tr = document.createElement('tr');
    tr.className = 'slide-in hover:bg-gray-50 scan-row';
    tr.dataset.status = data.success ? 'success' : (data.reason === 'already_done' || data.reason === 'duplicate' ? 'already' : 'failed');

    const now = new Date();
    const time = now.toLocaleTimeString('en', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
    const order = data.order || {};
    const orderNum = order.order_number || data.barcode || '—';
    const customer = order.customer_name || '—';
    const phone = order.customer_phone || '';
    const amount = order.total ? '৳' + parseFloat(order.total).toLocaleString() : '—';

    let statusBadge = '';
    if (data.success) {
        statusBadge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700"><i class="fas fa-check text-[10px]"></i>Updated</span>';
    } else if (data.reason === 'already_done' || data.reason === 'duplicate') {
        statusBadge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700"><i class="fas fa-exclamation text-[10px]"></i>Skipped</span>';
    } else {
        statusBadge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700"><i class="fas fa-times text-[10px]"></i>Failed</span>';
    }

    tr.innerHTML = `
        <td class="px-4 py-3 text-xs text-gray-400 font-mono">${time}</td>
        <td class="px-4 py-3">
            <p class="font-semibold text-gray-800">${escHtml(orderNum)}</p>
            ${data.barcode !== orderNum ? '<p class="text-[11px] text-gray-400">Scanned: ' + escHtml(data.barcode) + '</p>' : ''}
        </td>
        <td class="px-4 py-3">
            <p class="text-gray-700 text-sm">${escHtml(customer)}</p>
            ${phone ? '<p class="text-[11px] text-gray-400">' + escHtml(phone) + '</p>' : ''}
        </td>
        <td class="px-4 py-3 text-center">${statusBadge}</td>
        <td class="px-4 py-3 text-sm ${data.success ? 'text-green-600' : 'text-red-600'}">${escHtml(data.message)}</td>
        <td class="px-4 py-3 text-right font-medium text-gray-700">${amount}</td>
    `;

    tbody.insertBefore(tr, tbody.firstChild);

    // Flash row
    tr.style.background = data.success ? '#f0fdf4' : '#fef2f2';
    setTimeout(() => tr.style.background = '', 2000);
}

function updateStats() {
    document.getElementById('statTotal').textContent = totalScans;
    document.getElementById('statSuccess').textContent = successScans;
    document.getElementById('statFailed').textContent = failedScans;
}

async function clearResults() {
    const _ok = await window._confirmAsync('Clear all scan results?'); if(!_ok) return;
    document.getElementById('scanResults').innerHTML = '<tr id="emptyRow"><td colspan="6" class="px-4 py-10 text-center text-gray-400">No items scanned yet</td></tr>';
    totalScans = successScans = failedScans = 0;
    scannedBarcodes.clear();
    updateStats();
    scanInput.focus();
}

function filterResults() {
    const filter = document.getElementById('statusFilter').value;
    document.querySelectorAll('.scan-row').forEach(row => {
        if (!filter || row.dataset.status === filter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// Keyboard shortcut: / to focus scanner
document.addEventListener('keydown', function(e) {
    if (e.key === '/' && !e.target.matches('input,textarea,select')) {
        e.preventDefault();
        scanInput.focus();
    }
});

// Auto-focus on page load
scanInput.focus();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
