<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Chat Settings';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();

// Fix: Ensure site_settings supports emoji (utf8mb4)
try { $db->query("ALTER TABLE site_settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (\Throwable $e) {}

// Ensure tables exist
try { $db->fetch("SELECT 1 FROM chat_conversations LIMIT 1"); } catch (\Throwable $e) {
    $sqlFile = __DIR__ . '/../../database/chat_migration.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        foreach (explode(';', $sql) as $q) { $q = trim($q); if ($q && !empty($q)) try { $db->query($q); } catch (\Throwable $ex) {} }
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_settings') {
        $settings = [
            'chat_enabled' => $_POST['chat_enabled'] ?? '0',
            'chat_bubble_position' => $_POST['chat_bubble_position'] ?? 'bottom-right',
            'chat_bubble_color' => $_POST['chat_bubble_color'] ?? '#3b82f6',
            'chat_welcome_message' => $_POST['chat_welcome_message'] ?? '',
            'chat_bot_name' => $_POST['chat_bot_name'] ?? 'Support',
            'chat_heading_name' => $_POST['chat_heading_name'] ?? '',
            'chat_offline_message' => $_POST['chat_offline_message'] ?? '',
            'chat_auto_reply_enabled' => $_POST['chat_auto_reply_enabled'] ?? '0',
            'chat_require_info' => $_POST['chat_require_info'] ?? '0',
            'chat_sound_enabled' => $_POST['chat_sound_enabled'] ?? '1',
            'chat_guest_ttl' => intval($_POST['chat_guest_ttl'] ?? 24),
        ];
        foreach ($settings as $key => $val) {
            try {
                $db->query(
                    "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) VALUES (?, ?, 'text', 'chat') ON DUPLICATE KEY UPDATE setting_value = ?",
                    [$key, $val, $val]
                );
            } catch (\Throwable $e) {
                // Fallback: strip emojis if charset issue
                $clean = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $val);
                $db->query(
                    "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) VALUES (?, ?, 'text', 'chat') ON DUPLICATE KEY UPDATE setting_value = ?",
                    [$key, $clean, $clean]
                );
            }
        }
        redirect(adminUrl('pages/chat-settings.php?tab=' . ($_POST['tab'] ?? 'settings') . '&msg=saved'));
    }

    if ($action === 'add_reply') {
        $db->insert('chat_auto_replies', [
            'trigger_words' => sanitize($_POST['trigger_words']),
            'match_type' => sanitize($_POST['match_type']),
            'response' => sanitize($_POST['response']),
            'category' => sanitize($_POST['category'] ?? 'general'),
            'priority' => intval($_POST['priority'] ?? 0),
            'is_active' => 1,
            'created_by' => getAdminId(),
        ]);
        redirect(adminUrl('pages/chat-settings.php?tab=training&msg=added'));
    }

    if ($action === 'update_reply') {
        $id = intval($_POST['reply_id']);
        $db->update('chat_auto_replies', [
            'trigger_words' => sanitize($_POST['trigger_words']),
            'match_type' => sanitize($_POST['match_type']),
            'response' => sanitize($_POST['response']),
            'category' => sanitize($_POST['category'] ?? 'general'),
            'priority' => intval($_POST['priority'] ?? 0),
        ], 'id = ?', [$id]);
        redirect(adminUrl('pages/chat-settings.php?tab=training&msg=updated'));
    }

    if ($action === 'toggle_reply') {
        header('Content-Type: application/json');
        $id = intval($_POST['reply_id']);
        $db->query("UPDATE chat_auto_replies SET is_active = NOT is_active WHERE id = ?", [$id]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'delete_reply') {
        header('Content-Type: application/json');
        $db->delete('chat_auto_replies', 'id = ?', [intval($_POST['reply_id'])]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'cleanup_guests') {
        $ttl = intval(getSetting('chat_guest_ttl', 24));
        $deleted = $db->fetch("SELECT COUNT(*) as cnt FROM chat_conversations WHERE is_guest = 1 AND updated_at < NOW() - INTERVAL {$ttl} HOUR")['cnt'];
        $db->query("DELETE FROM chat_conversations WHERE is_guest = 1 AND updated_at < NOW() - INTERVAL {$ttl} HOUR");
        redirect(adminUrl('pages/chat-settings.php?tab=settings&msg=cleaned&count=' . $deleted));
    }
}

$tab = $_GET['tab'] ?? 'settings';
$replies = [];
try { $replies = $db->fetchAll("SELECT * FROM chat_auto_replies ORDER BY priority DESC, id ASC"); } catch (\Throwable $e) {}

$totalConvos = 0; try { $totalConvos = $db->count('chat_conversations'); } catch(\Throwable $e){}
$activeConvos = 0; try { $activeConvos = $db->count('chat_conversations', "status != 'closed'"); } catch(\Throwable $e){}
$totalMessages = 0; try { $totalMessages = $db->count('chat_messages'); } catch(\Throwable $e){}
$botReplies = 0; try { $botReplies = $db->fetch("SELECT SUM(hit_count) as t FROM chat_auto_replies")['t'] ?? 0; } catch(\Throwable $e){}

$s = [];
$chatKeys = ['chat_enabled','chat_bubble_position','chat_bubble_color','chat_welcome_message','chat_bot_name','chat_heading_name','chat_offline_message','chat_auto_reply_enabled','chat_require_info','chat_sound_enabled','chat_guest_ttl'];
foreach ($chatKeys as $k) $s[$k] = getSetting($k, '');
if (!$s['chat_bubble_position']) $s['chat_bubble_position'] = 'bottom-right';
if (!$s['chat_bubble_color']) $s['chat_bubble_color'] = '#3b82f6';
if (!$s['chat_bot_name']) $s['chat_bot_name'] = 'Support';
if (!$s['chat_heading_name']) $s['chat_heading_name'] = '';
if (!$s['chat_welcome_message']) $s['chat_welcome_message'] = 'আসসালামু আলাইকুম! কীভাবে সাহায্য করতে পারি?';
if (!$s['chat_guest_ttl']) $s['chat_guest_ttl'] = '24';

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm">
    ✅ <?= $_GET['msg']==='saved'?'Settings saved successfully!':($_GET['msg']==='added'?'Auto-reply added!':($_GET['msg']==='updated'?'Reply updated!':($_GET['msg']==='cleaned'?'Cleaned '.intval($_GET['count']??0).' expired guest chats.':'Done!'))) ?>
</div>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Chat Settings & Training</h2>
        <p class="text-sm text-gray-500 mt-1">Configure live chat widget, auto-replies, and bot training</p>
    </div>
    <a href="<?= adminUrl('pages/live-chat.php') ?>" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">💬 Open Live Chat</a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4"><p class="text-2xl font-bold text-gray-800"><?= number_format($totalConvos) ?></p><p class="text-xs text-gray-500 mt-1">Total Conversations</p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-2xl font-bold text-green-600"><?= $activeConvos ?></p><p class="text-xs text-gray-500 mt-1">Active Chats</p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-2xl font-bold text-blue-600"><?= number_format($totalMessages) ?></p><p class="text-xs text-gray-500 mt-1">Total Messages</p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-2xl font-bold text-amber-600"><?= number_format($botReplies) ?></p><p class="text-xs text-gray-500 mt-1">Bot Auto-Replies</p></div>
</div>

<div class="flex gap-1 mb-4 border-b pb-2">
    <a href="?tab=settings" class="px-4 py-2 text-sm font-medium rounded-t-lg <?= $tab==='settings'?'bg-blue-600 text-white':'text-gray-500 hover:bg-gray-100' ?>">⚙ Widget Settings</a>
    <a href="?tab=training" class="px-4 py-2 text-sm font-medium rounded-t-lg <?= $tab==='training'?'bg-blue-600 text-white':'text-gray-500 hover:bg-gray-100' ?>">🤖 Auto-Reply Training</a>
</div>

<?php if ($tab === 'settings'): ?>
<form method="POST" id="settingsForm" class="space-y-6">
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="tab" value="settings">

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-5">
            <div class="bg-white rounded-xl border p-5">
                <h3 class="font-semibold text-gray-800 mb-4">General Settings</h3>
                <div class="space-y-4">
                    <label class="flex items-center justify-between">
                        <div><span class="text-sm font-medium text-gray-700">Enable Live Chat</span><p class="text-xs text-gray-400">Show chat widget on your store</p></div>
                        <input type="hidden" name="chat_enabled" value="0">
                        <input type="checkbox" name="chat_enabled" value="1" <?= $s['chat_enabled']=='1'?'checked':'' ?> class="w-5 h-5 rounded text-blue-600">
                    </label>
                    <label class="flex items-center justify-between">
                        <div><span class="text-sm font-medium text-gray-700">Auto-Reply Bot</span><p class="text-xs text-gray-400">Bot responds using trained replies when admin is offline</p></div>
                        <input type="hidden" name="chat_auto_reply_enabled" value="0">
                        <input type="checkbox" name="chat_auto_reply_enabled" value="1" <?= $s['chat_auto_reply_enabled']=='1'?'checked':'' ?> class="w-5 h-5 rounded text-blue-600">
                    </label>
                    <label class="flex items-center justify-between">
                        <div><span class="text-sm font-medium text-gray-700">Notification Sound</span><p class="text-xs text-gray-400">Play sound on new messages in admin panel</p></div>
                        <input type="hidden" name="chat_sound_enabled" value="0">
                        <input type="checkbox" name="chat_sound_enabled" value="1" <?= ($s['chat_sound_enabled']!=='0')?'checked':'' ?> class="w-5 h-5 rounded text-blue-600">
                    </label>
                    <label class="flex items-center justify-between">
                        <div><span class="text-sm font-medium text-gray-700">Require Name/Phone</span><p class="text-xs text-gray-400">Ask guests for name and phone before chatting</p></div>
                        <input type="hidden" name="chat_require_info" value="0">
                        <input type="checkbox" name="chat_require_info" value="1" <?= $s['chat_require_info']=='1'?'checked':'' ?> class="w-5 h-5 rounded text-blue-600">
                    </label>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Guest Chat Auto-Delete After</label>
                        <div class="flex items-center gap-3">
                            <select name="chat_guest_ttl" class="border rounded-lg px-3 py-2 text-sm w-40">
                                <?php foreach([1=>'1 Hour',6=>'6 Hours',12=>'12 Hours',24=>'24 Hours',48=>'48 Hours',72=>'3 Days',168=>'7 Days',720=>'30 Days'] as $hrs => $lbl): ?>
                                <option value="<?= $hrs ?>" <?= intval($s['chat_guest_ttl'])==$hrs?'selected':'' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="text-xs text-gray-400">Guest chats inactive beyond this time are automatically deleted</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Chat Widget Title <span class="text-xs text-gray-400">(shown in header)</span></label>
                        <input type="text" name="chat_heading_name" value="<?= e($s['chat_heading_name']) ?>" placeholder="<?= e(getSetting('site_name', 'Support')) ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bot Name <span class="text-xs text-gray-400">(auto-reply sender name)</span></label>
                        <input type="text" name="chat_bot_name" value="<?= e($s['chat_bot_name']) ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Welcome Message</label>
                        <textarea name="chat_welcome_message" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm"><?= e($s['chat_welcome_message']) ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Offline Message</label>
                        <textarea name="chat_offline_message" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="We're currently offline. Leave a message..."><?= e($s['chat_offline_message']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border p-5">
                <h3 class="font-semibold text-gray-800 mb-4">Bubble Appearance & Position</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Bubble Color</label>
                        <div class="flex items-center gap-3">
                            <input type="color" name="chat_bubble_color" value="<?= e($s['chat_bubble_color']) ?>" class="w-10 h-10 rounded-lg border cursor-pointer" oninput="document.getElementById('colorHex').value=this.value;updatePreviewBubble()">
                            <input type="text" id="colorHex" value="<?= e($s['chat_bubble_color']) ?>" class="border rounded-lg px-3 py-2 text-sm w-28 font-mono" readonly>
                            <div class="flex gap-2">
                                <?php foreach(['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#000000'] as $clr): ?>
                                <button type="button" onclick="setBubbleColor('<?= $clr ?>')" class="w-7 h-7 rounded-full border-2 border-white shadow" style="background:<?= $clr ?>"></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Bubble Position</label>
                        <div class="grid grid-cols-2 gap-3">
                            <?php foreach(['bottom-right'=>'Bottom Right','bottom-left'=>'Bottom Left','top-right'=>'Top Right','top-left'=>'Top Left'] as $pos => $lbl): ?>
                            <label class="flex items-center gap-2 border-2 rounded-xl p-3 cursor-pointer has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 transition">
                                <input type="radio" name="chat_bubble_position" value="<?= $pos ?>" <?= $s['chat_bubble_position']===$pos?'checked':'' ?> class="accent-blue-600" onchange="updatePreviewBubble()">
                                <span class="text-sm font-medium"><?= $lbl ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border p-5">
                <h3 class="font-semibold text-gray-800 mb-3">Maintenance</h3>
                <button type="button" onclick="cleanupGuests()" class="bg-red-50 text-red-600 border border-red-200 px-4 py-2 rounded-lg text-sm hover:bg-red-100">
                    🗑 Clean Up Expired Guest Chats
                </button>
                <p class="text-xs text-gray-400 mt-2">Guest chats older than 24 hours are auto-cleaned, but you can trigger it manually here.</p>
            </div>
        </div>

        <div>
            <div class="sticky top-20 bg-white rounded-xl border p-5">
                <h3 class="font-semibold text-gray-800 mb-3">Preview</h3>
                <div id="bubblePreview" class="relative w-full h-80 bg-gray-100 rounded-xl overflow-hidden border">
                    <div id="previewBubble" class="absolute w-14 h-14 rounded-full shadow-lg flex items-center justify-center cursor-pointer transition-all" style="background:<?= e($s['chat_bubble_color']) ?>">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    </div>
                    <div id="previewWindow" class="absolute bg-white rounded-xl shadow-2xl border w-52 hidden" style="height:200px">
                        <div class="rounded-t-xl px-3 py-2 text-white text-xs font-bold" id="previewHeader" style="background:<?= e($s['chat_bubble_color']) ?>">💬 Chat with us</div>
                        <div class="p-2 text-[10px] text-gray-500"><?= e(mb_strimwidth($s['chat_welcome_message'],0,60,'...')) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg font-medium hover:bg-blue-700">
        <i class="fas fa-save mr-1"></i> Save Settings
    </button>
</form>

<!-- Separate cleanup form (NOT nested) -->
<form id="cleanupForm" method="POST" class="hidden">
    <input type="hidden" name="action" value="cleanup_guests">
</form>

<script>
function setBubbleColor(c) {
    document.querySelector('[name=chat_bubble_color]').value = c;
    document.getElementById('colorHex').value = c;
    updatePreviewBubble();
}
function updatePreviewBubble() {
    const color = document.querySelector('[name=chat_bubble_color]').value;
    const pos = document.querySelector('[name=chat_bubble_position]:checked')?.value || 'bottom-right';
    const bubble = document.getElementById('previewBubble');
    const win = document.getElementById('previewWindow');
    const header = document.getElementById('previewHeader');
    bubble.style.background = color;
    header.style.background = color;
    bubble.style.removeProperty('top'); bubble.style.removeProperty('bottom');
    bubble.style.removeProperty('left'); bubble.style.removeProperty('right');
    win.style.removeProperty('top'); win.style.removeProperty('bottom');
    win.style.removeProperty('left'); win.style.removeProperty('right');
    const p = pos.split('-');
    bubble.style[p[0]] = '12px'; bubble.style[p[1]] = '12px';
    win.style[p[0]] = '80px'; win.style[p[1]] = '12px';
}
updatePreviewBubble();
document.getElementById('previewBubble').onclick = () => document.getElementById('previewWindow').classList.toggle('hidden');
function cleanupGuests() {
    if (!confirm('Delete all expired guest chats older than 24 hours?')) return;
    document.getElementById('cleanupForm').submit();
}
</script>

<?php elseif ($tab === 'training'): ?>

<div class="grid lg:grid-cols-3 gap-6">
    <div>
        <div class="bg-white rounded-xl border p-5 sticky top-20">
            <h3 class="font-semibold text-gray-800 mb-4" id="formTitle">➕ Add Auto-Reply</h3>
            <form method="POST" id="replyForm" class="space-y-3">
                <input type="hidden" name="action" value="add_reply" id="formAction">
                <input type="hidden" name="reply_id" id="editId" value="">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Trigger Words <span class="text-gray-400">(comma separated)</span></label>
                    <input type="text" name="trigger_words" required placeholder="hello, হ্যালো, hi" class="w-full border rounded-lg px-3 py-2 text-sm" id="fTriggers">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Match Type</label>
                    <select name="match_type" class="w-full border rounded-lg px-3 py-2 text-sm" id="fMatch">
                        <option value="contains">Contains (any trigger word found)</option>
                        <option value="exact">Exact Match</option>
                        <option value="starts_with">Starts With</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Bot Response</label>
                    <textarea name="response" required rows="3" placeholder="Auto-reply message..." class="w-full border rounded-lg px-3 py-2 text-sm" id="fResponse"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Category</label>
                        <select name="category" class="w-full border rounded-lg px-3 py-2 text-sm" id="fCategory">
                            <option value="greeting">Greeting</option>
                            <option value="shipping">Shipping</option>
                            <option value="payment">Payment</option>
                            <option value="returns">Returns</option>
                            <option value="orders">Orders</option>
                            <option value="pricing">Pricing</option>
                            <option value="general">General</option>
                            <option value="farewell">Farewell</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Priority <span class="text-gray-400">(higher = first)</span></label>
                        <input type="number" name="priority" value="5" min="0" max="100" class="w-full border rounded-lg px-3 py-2 text-sm" id="fPriority">
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded-lg text-sm font-medium hover:bg-blue-700">Save Reply</button>
                    <button type="button" onclick="resetForm()" class="px-3 py-2 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200">Clear</button>
                </div>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border overflow-hidden">
            <div class="px-5 py-3 border-b bg-gray-50 flex items-center justify-between">
                <h3 class="font-semibold text-gray-800 text-sm">Trained Responses (<?= count($replies) ?>)</h3>
                <span class="text-xs text-gray-400">Higher priority matches first</span>
            </div>
            <div class="divide-y">
                <?php if (empty($replies)): ?>
                <div class="p-8 text-center text-gray-400">No auto-replies configured yet. Add one on the left.</div>
                <?php endif; ?>
                <?php foreach ($replies as $reply): 
                    $catColors = ['greeting'=>'bg-green-100 text-green-700','shipping'=>'bg-blue-100 text-blue-700','payment'=>'bg-purple-100 text-purple-700','returns'=>'bg-orange-100 text-orange-700','orders'=>'bg-indigo-100 text-indigo-700','pricing'=>'bg-amber-100 text-amber-700','farewell'=>'bg-gray-100 text-gray-700','general'=>'bg-gray-100 text-gray-700'];
                    $catCls = $catColors[$reply['category']] ?? 'bg-gray-100 text-gray-700';
                    $replyJson = htmlspecialchars(json_encode($reply, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                <div class="px-5 py-3 hover:bg-gray-50 <?= !$reply['is_active']?'opacity-50':'' ?>" id="reply-<?= $reply['id'] ?>">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs <?= $catCls ?> px-2 py-0.5 rounded-full font-medium"><?= e($reply['category']) ?></span>
                                <span class="text-xs text-gray-400">Priority: <?= $reply['priority'] ?></span>
                                <span class="text-xs text-gray-400">•</span>
                                <span class="text-xs text-gray-400"><?= e($reply['match_type']) ?></span>
                                <span class="text-xs text-gray-400">•</span>
                                <span class="text-xs text-amber-600">🎯 <?= number_format($reply['hit_count']) ?> hits</span>
                            </div>
                            <div class="mt-1.5">
                                <p class="text-sm font-medium text-gray-800">
                                    <span class="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded font-mono mr-1">Triggers:</span>
                                    <?= e(mb_strimwidth($reply['trigger_words'], 0, 80, '...')) ?>
                                </p>
                                <p class="text-sm text-gray-600 mt-1 whitespace-pre-line"><?= e(mb_strimwidth($reply['response'], 0, 150, '...')) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1 flex-shrink-0">
                            <button type="button" onclick='editReply(<?= $replyJson ?>)' class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-lg" title="Edit">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <button type="button" onclick="toggleReply(<?= $reply['id'] ?>,this)" class="p-1.5 <?= $reply['is_active']?'text-green-500':'text-gray-400' ?> hover:bg-gray-50 rounded-lg" title="Toggle">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </button>
                            <button type="button" onclick="deleteReply(<?= $reply['id'] ?>)" class="p-1.5 text-red-400 hover:bg-red-50 rounded-lg" title="Delete">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
function editReply(r) {
    document.getElementById('formTitle').textContent = '✏️ Edit Auto-Reply';
    document.getElementById('formAction').value = 'update_reply';
    document.getElementById('editId').value = r.id;
    document.getElementById('fTriggers').value = r.trigger_words;
    document.getElementById('fMatch').value = r.match_type;
    document.getElementById('fResponse').value = r.response;
    document.getElementById('fCategory').value = r.category;
    document.getElementById('fPriority').value = r.priority;
    document.getElementById('fTriggers').focus();
    window.scrollTo({top: 0, behavior: 'smooth'});
}
function resetForm() {
    document.getElementById('formTitle').textContent = '➕ Add Auto-Reply';
    document.getElementById('formAction').value = 'add_reply';
    document.getElementById('editId').value = '';
    document.getElementById('replyForm').reset();
}
function toggleReply(id, btn) {
    fetch(location.pathname, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=toggle_reply&reply_id='+id})
    .then(r=>r.json()).then(() => { const row = document.getElementById('reply-'+id); row.classList.toggle('opacity-50'); btn.classList.toggle('text-green-500'); btn.classList.toggle('text-gray-400'); });
}
function deleteReply(id) {
    if (!confirm('Delete this auto-reply?')) return;
    fetch(location.pathname, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=delete_reply&reply_id='+id})
    .then(r=>r.json()).then(() => document.getElementById('reply-'+id)?.remove());
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
