<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Live Chat';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();

// Ensure tables exist
try { $db->fetch("SELECT 1 FROM chat_conversations LIMIT 1"); } catch (\Throwable $e) {
    $sql = file_get_contents(__DIR__ . '/../../database/chat_migration.sql');
    foreach (explode(';', $sql) as $q) { $q = trim($q); if ($q) try { $db->query($q); } catch (\Throwable $ex) {} }
}

$chatApiUrl = SITE_URL . '/api/chat.php';
$adminName = $_SESSION['admin_name'] ?? 'Admin';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex h-[calc(100vh-80px)] -mt-2 -mx-2 lg:-mx-4 bg-white rounded-xl border overflow-hidden">
    <!-- LEFT: Conversation List -->
    <div id="convoList" class="w-80 flex-shrink-0 border-r flex flex-col bg-gray-50">
        <div class="p-4 border-b bg-white">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-lg font-bold text-gray-800">💬 Live Chat</h2>
                <div class="flex items-center gap-1">
                    <span id="waitingBadge" class="hidden bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full"></span>
                    <span id="onlineIndicator" class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                </div>
            </div>
            <div class="relative">
                <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" id="searchConvo" placeholder="Search chats..." class="w-full pl-9 pr-3 py-2 border rounded-lg text-sm" oninput="filterConvos()">
            </div>
            <div class="flex gap-1 mt-2">
                <button onclick="filterStatus('')" class="filter-btn active text-xs px-2.5 py-1 rounded-full bg-blue-600 text-white">All</button>
                <button onclick="filterStatus('waiting')" class="filter-btn text-xs px-2.5 py-1 rounded-full bg-gray-200 text-gray-600">⏳ Waiting</button>
                <button onclick="filterStatus('active')" class="filter-btn text-xs px-2.5 py-1 rounded-full bg-gray-200 text-gray-600">🟢 Active</button>
                <button onclick="filterStatus('closed')" class="filter-btn text-xs px-2.5 py-1 rounded-full bg-gray-200 text-gray-600">Closed</button>
            </div>
        </div>
        <div id="conversations" class="flex-1 overflow-y-auto"></div>
    </div>

    <!-- RIGHT: Chat Area -->
    <div class="flex-1 flex flex-col">
        <!-- Empty State -->
        <div id="emptyState" class="flex-1 flex items-center justify-center text-gray-400">
            <div class="text-center">
                <div class="text-6xl mb-4">💬</div>
                <p class="font-medium text-gray-500">Select a conversation</p>
                <p class="text-sm mt-1">Messages will appear here in real-time</p>
            </div>
        </div>

        <!-- Active Chat -->
        <div id="chatArea" class="hidden flex-1 flex flex-col">
            <!-- Chat Header -->
            <div id="chatHeader" class="px-5 py-3 border-b bg-white flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div id="chatAvatar" class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold text-sm">G</div>
                    <div>
                        <h3 id="chatName" class="font-semibold text-gray-800 text-sm"></h3>
                        <div class="flex items-center gap-2">
                            <span id="chatPhone" class="text-xs text-gray-500"></span>
                            <span id="chatStatus" class="text-xs px-1.5 py-0.5 rounded-full"></span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span id="chatPage" class="text-xs text-gray-400 max-w-[200px] truncate" title=""></span>
                    <button onclick="closeChat()" class="p-2 hover:bg-red-50 text-red-500 rounded-lg text-sm" title="Close chat">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <!-- Messages -->
            <div id="chatMessages" class="flex-1 overflow-y-auto px-5 py-4 space-y-3 bg-gray-50"></div>

            <!-- Input -->
            <div class="px-5 py-3 border-t bg-white">
                <div class="flex items-end gap-2">
                    <div class="flex-1 relative">
                        <textarea id="msgInput" rows="1" placeholder="Type a message..." 
                            class="w-full border rounded-xl px-4 py-2.5 text-sm resize-none max-h-24 focus:ring-2 focus:ring-blue-400 focus:border-blue-400 outline-none"
                            onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendAdminMsg()}"
                            oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px'"></textarea>
                    </div>
                    <button onclick="sendAdminMsg()" class="bg-blue-600 text-white p-2.5 rounded-xl hover:bg-blue-700 transition flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    </button>
                </div>
                <!-- Quick Replies -->
                <div class="flex flex-wrap gap-1 mt-2">
                    <?php
                    $quickReplies = ['আসসালামু আলাইকুম!','অর্ডার কনফার্ম করা হয়েছে ✅','ডেলিভারি ১-২ দিনে পৌঁছাবে','আর কিছু জানতে চাইলে বলুন','ধন্যবাদ! 😊'];
                    foreach ($quickReplies as $qr): ?>
                    <button onclick="quickReply('<?= addslashes($qr) ?>')" class="text-[11px] bg-gray-100 hover:bg-blue-100 text-gray-600 px-2 py-1 rounded-full transition"><?= e(mb_strimwidth($qr, 0, 30, '...')) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.convo-item.active { background: #eff6ff; border-left: 3px solid #3b82f6; }
.convo-item { border-left: 3px solid transparent; }
.msg-bubble { max-width: 75%; word-wrap: break-word; white-space: pre-wrap; }
#chatMessages::-webkit-scrollbar { width: 5px; }
#chatMessages::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
</style>

<script>
const API = '<?= $chatApiUrl ?>';
let currentConvo = null;
let lastMsgId = 0;
let pollTimer = null;
let listTimer = null;
let statusFilter = '';

// ══════ CONVERSATION LIST ══════
async function loadConversations() {
    try {
        const r = await fetch(API + '?action=admin_list&status=' + statusFilter);
        const d = await r.json();
        if (d.error) return;
        renderConvoList(d.conversations || []);
    } catch(e) {}
}

function renderConvoList(convos) {
    const search = document.getElementById('searchConvo').value.toLowerCase();
    const el = document.getElementById('conversations');
    let waiting = 0;
    let html = '';
    convos.forEach(c => {
        if (c.status === 'waiting') waiting++;
        const name = c.visitor_name || 'Guest';
        if (search && !name.toLowerCase().includes(search) && !(c.visitor_phone||'').includes(search) && !(c.last_message||'').toLowerCase().includes(search)) return;
        const isActive = currentConvo && currentConvo.id == c.id;
        const unread = parseInt(c.unread_admin) || 0;
        const statusDot = c.status === 'waiting' ? '🟡' : c.status === 'active' ? '🟢' : '⚫';
        const timeAgo = formatTime(c.last_message_at);
        const avatar = name.charAt(0).toUpperCase();
        const avatarBg = c.is_guest == 1 ? 'bg-gray-200 text-gray-600' : 'bg-blue-100 text-blue-600';

        html += `<div class="convo-item ${isActive?'active':''} px-4 py-3 cursor-pointer hover:bg-blue-50 transition border-b border-gray-100" onclick="openConvo(${c.id})">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full ${avatarBg} flex items-center justify-center font-bold text-sm flex-shrink-0">${avatar}</div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-semibold text-gray-800 truncate">${statusDot} ${esc(name)}</span>
                        <span class="text-[10px] text-gray-400 flex-shrink-0">${timeAgo}</span>
                    </div>
                    <p class="text-xs text-gray-400 truncate mt-0.5">${esc(c.last_message || 'No messages')}</p>
                    <div class="flex items-center gap-2 mt-0.5">
                        ${c.visitor_phone ? `<span class="text-[10px] text-gray-400">${esc(c.visitor_phone)}</span>` : ''}
                        ${unread > 0 ? `<span class="bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">${unread}</span>` : ''}
                        ${c.is_guest == 1 ? '<span class="text-[10px] bg-orange-100 text-orange-600 px-1 rounded">Guest</span>' : '<span class="text-[10px] bg-green-100 text-green-600 px-1 rounded">Customer</span>'}
                    </div>
                </div>
            </div>
        </div>`;
    });
    if (!html) html = '<div class="p-8 text-center text-gray-400 text-sm">No conversations</div>';
    el.innerHTML = html;

    const badge = document.getElementById('waitingBadge');
    if (waiting > 0) { badge.textContent = waiting; badge.classList.remove('hidden'); }
    else badge.classList.add('hidden');
}

function filterStatus(s) {
    statusFilter = s;
    document.querySelectorAll('.filter-btn').forEach(b => { b.className = 'filter-btn text-xs px-2.5 py-1 rounded-full bg-gray-200 text-gray-600'; });
    event.target.className = 'filter-btn text-xs px-2.5 py-1 rounded-full bg-blue-600 text-white';
    loadConversations();
}
function filterConvos() { loadConversations(); }

// ══════ OPEN CONVERSATION ══════
async function openConvo(id) {
    try {
        const r = await fetch(API + '?action=admin_messages&conversation_id=' + id);
        const d = await r.json();
        if (d.error) return;

        currentConvo = d.conversation;
        document.getElementById('emptyState').classList.add('hidden');
        document.getElementById('chatArea').classList.remove('hidden');

        // Header
        const name = currentConvo.visitor_name || 'Guest';
        document.getElementById('chatAvatar').textContent = name.charAt(0).toUpperCase();
        document.getElementById('chatAvatar').className = `w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm ${currentConvo.is_guest == 1 ? 'bg-gray-200 text-gray-600' : 'bg-blue-100 text-blue-600'}`;
        document.getElementById('chatName').textContent = name;
        document.getElementById('chatPhone').textContent = currentConvo.visitor_phone || currentConvo.ip_address || '';
        const st = currentConvo.status;
        const stEl = document.getElementById('chatStatus');
        stEl.textContent = st;
        stEl.className = 'text-xs px-1.5 py-0.5 rounded-full ' + (st==='waiting'?'bg-yellow-100 text-yellow-700':st==='active'?'bg-green-100 text-green-700':'bg-gray-100 text-gray-500');
        document.getElementById('chatPage').textContent = currentConvo.page_url || '';
        document.getElementById('chatPage').title = currentConvo.page_url || '';

        renderMessages(d.messages || []);
        lastMsgId = d.messages?.length ? Math.max(...d.messages.map(m => m.id)) : 0;

        document.getElementById('msgInput').focus();
        loadConversations(); // Refresh list (unread counts)
        startPolling();
    } catch(e) { console.error(e); }
}

function renderMessages(msgs) {
    const el = document.getElementById('chatMessages');
    let html = '';
    msgs.forEach(m => {
        html += msgHTML(m);
    });
    el.innerHTML = html;
    el.scrollTop = el.scrollHeight;
}

function msgHTML(m) {
    const isUser = m.sender_type === 'user';
    const isSystem = m.sender_type === 'system';
    const isBot = m.sender_type === 'bot';
    const time = new Date(m.created_at).toLocaleTimeString('en-US', {hour:'numeric',minute:'2-digit',hour12:true});

    if (isSystem) {
        return `<div class="text-center"><span class="text-[10px] bg-gray-200 text-gray-500 px-2 py-0.5 rounded-full">${esc(m.message)} · ${time}</span></div>`;
    }

    const align = isUser ? 'items-start' : 'items-end';
    const bubbleCls = isUser ? 'bg-white border text-gray-800' : isBot ? 'bg-amber-50 border border-amber-200 text-gray-800' : 'bg-blue-600 text-white';
    const nameCls = isUser ? 'text-gray-500' : isBot ? 'text-amber-600' : 'text-blue-600';
    const label = isBot ? '🤖 ' + (m.sender_name||'Bot') : (m.sender_name||'');

    return `<div class="flex flex-col ${align}">
        <span class="text-[10px] ${nameCls} mb-0.5 px-1">${esc(label)}</span>
        <div class="msg-bubble rounded-2xl px-4 py-2.5 text-sm ${bubbleCls}">${esc(m.message)}</div>
        <span class="text-[10px] text-gray-400 mt-0.5 px-1">${time}</span>
    </div>`;
}

// ══════ SEND MESSAGE ══════
async function sendAdminMsg() {
    const inp = document.getElementById('msgInput');
    const msg = inp.value.trim();
    if (!msg || !currentConvo) return;
    inp.value = ''; inp.style.height = 'auto';

    // Optimistic render
    const el = document.getElementById('chatMessages');
    el.innerHTML += msgHTML({sender_type:'admin', sender_name:'<?= addslashes($adminName) ?>', message:msg, created_at:new Date().toISOString()});
    el.scrollTop = el.scrollHeight;

    const fd = new FormData();
    fd.append('action', 'admin_send');
    fd.append('conversation_id', currentConvo.id);
    fd.append('message', msg);
    try {
        const r = await fetch(API, {method:'POST', body:fd});
        const d = await r.json();
        if (d.message_id) lastMsgId = Math.max(lastMsgId, d.message_id);
    } catch(e) {}
    loadConversations();
}

function quickReply(msg) {
    document.getElementById('msgInput').value = msg;
    sendAdminMsg();
}

// ══════ POLLING ══════
function startPolling() {
    clearInterval(pollTimer);
    pollTimer = setInterval(pollMessages, 3000);
}

async function pollMessages() {
    if (!currentConvo) return;
    try {
        const r = await fetch(API + `?action=admin_poll&conversation_id=${currentConvo.id}&after_id=${lastMsgId}`);
        const d = await r.json();
        if (d.messages?.length) {
            const el = document.getElementById('chatMessages');
            const wasAtBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 50;
            d.messages.forEach(m => {
                el.innerHTML += msgHTML(m);
                lastMsgId = Math.max(lastMsgId, m.id);
            });
            if (wasAtBottom) el.scrollTop = el.scrollHeight;
        }
    } catch(e) {}
}

// ══════ CLOSE CHAT ══════
async function closeChat() {
    if (!currentConvo || !confirm('Close this conversation?')) return;
    const fd = new FormData();
    fd.append('action', 'admin_close');
    fd.append('conversation_id', currentConvo.id);
    await fetch(API, {method:'POST', body:fd});
    currentConvo = null;
    clearInterval(pollTimer);
    document.getElementById('chatArea').classList.add('hidden');
    document.getElementById('emptyState').classList.remove('hidden');
    loadConversations();
}

// ══════ HELPERS ══════
function esc(s) { if (!s) return ''; const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function formatTime(dt) {
    if (!dt) return '';
    const d = new Date(dt); const now = new Date();
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'now';
    if (diff < 3600) return Math.floor(diff/60) + 'm';
    if (diff < 86400) return Math.floor(diff/3600) + 'h';
    return d.toLocaleDateString('en-GB', {day:'2-digit', month:'short'});
}

// ══════ INIT ══════
loadConversations();
listTimer = setInterval(loadConversations, 5000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
