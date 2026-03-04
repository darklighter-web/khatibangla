<?php
/**
 * Live Chat API
 * Handles: init, send, poll, close, history
 * Supports: guest (session-based, 24hr TTL) + logged-in customers
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$sessionId = session_id();
$customerId = $_SESSION['customer_id'] ?? null;
$isGuest = !$customerId;

// Auto-cleanup: guest chats past TTL (run on 1% of requests)
if (rand(1, 100) === 1) {
    try {
        $ttl = intval(getSetting('chat_guest_ttl', 24));
        $db->query("DELETE FROM chat_conversations WHERE is_guest = 1 AND updated_at < NOW() - INTERVAL {$ttl} HOUR");
    } catch (\Throwable $e) {}
}

switch ($action) {

// ── Initialize or resume conversation ──
case 'init':
    $convo = null;
    if ($customerId) {
        $convo = $db->fetch("SELECT * FROM chat_conversations WHERE customer_id = ? AND status != 'closed' ORDER BY updated_at DESC LIMIT 1", [$customerId]);
    }
    if (!$convo) {
        $convo = $db->fetch("SELECT * FROM chat_conversations WHERE session_id = ? AND status != 'closed' ORDER BY updated_at DESC LIMIT 1", [$sessionId]);
    }

    if (!$convo) {
        // New conversation
        $db->insert('chat_conversations', [
            'session_id' => $sessionId,
            'customer_id' => $customerId,
            'visitor_name' => $customerId ? ($_SESSION['customer_name'] ?? 'Customer') : 'Guest',
            'visitor_phone' => $_SESSION['customer_phone'] ?? null,
            'is_guest' => $isGuest ? 1 : 0,
            'status' => 'waiting',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'page_url' => $_POST['page_url'] ?? '',
        ]);
        $convoId = $db->getConnection()->lastInsertId();

        // Welcome message
        $welcome = getSetting('chat_welcome_message', 'আসসালামু আলাইকুম! কীভাবে সাহায্য করতে পারি?');
        $db->insert('chat_messages', [
            'conversation_id' => $convoId,
            'sender_type' => 'bot',
            'sender_name' => getSetting('chat_bot_name', 'Support'),
            'message' => $welcome,
        ]);
        $db->update('chat_conversations', [
            'last_message' => $welcome,
            'last_message_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$convoId]);

        $convo = $db->fetch("SELECT * FROM chat_conversations WHERE id = ?", [$convoId]);
    }

    // Mark user messages as read
    $db->query("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND sender_type IN ('admin','bot') AND is_read = 0", [$convo['id']]);
    $db->update('chat_conversations', ['unread_user' => 0], 'id = ?', [$convo['id']]);

    $messages = $db->fetchAll("SELECT id, sender_type, sender_name, message, message_type, created_at FROM chat_messages WHERE conversation_id = ? ORDER BY created_at ASC", [$convo['id']]);

    echo json_encode(['success' => true, 'conversation_id' => $convo['id'], 'status' => $convo['status'], 'messages' => $messages]);
    break;

// ── Send message ──
case 'send':
    $convoId = intval($_POST['conversation_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if (!$convoId || !$message) {
        echo json_encode(['success' => false, 'message' => 'Missing data']);
        break;
    }

    // Verify conversation belongs to this session/customer
    $convo = $db->fetch("SELECT * FROM chat_conversations WHERE id = ? AND (session_id = ? OR customer_id = ?)", [$convoId, $sessionId, $customerId ?: 0]);
    if (!$convo) {
        echo json_encode(['success' => false, 'message' => 'Invalid conversation']);
        break;
    }

    // Save visitor info if provided
    $vName = trim($_POST['visitor_name'] ?? '');
    $vPhone = trim($_POST['visitor_phone'] ?? '');
    if ($vName || $vPhone) {
        $upd = [];
        if ($vName) $upd['visitor_name'] = $vName;
        if ($vPhone) $upd['visitor_phone'] = $vPhone;
        if (!empty($upd)) $db->update('chat_conversations', $upd, 'id = ?', [$convoId]);
    }

    // Insert user message
    $senderName = $convo['visitor_name'] ?: ($_SESSION['customer_name'] ?? 'Guest');
    $db->insert('chat_messages', [
        'conversation_id' => $convoId,
        'sender_type' => 'user',
        'sender_id' => $customerId,
        'sender_name' => $senderName,
        'message' => $message,
    ]);

    $db->update('chat_conversations', [
        'last_message' => mb_substr($message, 0, 200),
        'last_message_at' => date('Y-m-d H:i:s'),
        'unread_admin' => $convo['unread_admin'] + 1,
        'status' => $convo['status'] === 'closed' ? 'waiting' : $convo['status'],
    ], 'id = ?', [$convoId]);

    // Check auto-reply
    $botReply = findAutoReply($db, $message);
    $botMsg = null;
    if ($botReply) {
        $db->insert('chat_messages', [
            'conversation_id' => $convoId,
            'sender_type' => 'bot',
            'sender_name' => getSetting('chat_bot_name', 'Support'),
            'message' => $botReply['response'],
        ]);
        $db->query("UPDATE chat_auto_replies SET hit_count = hit_count + 1 WHERE id = ?", [$botReply['id']]);
        $db->update('chat_conversations', [
            'last_message' => mb_substr($botReply['response'], 0, 200),
            'last_message_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$convoId]);
        $botMsg = [
            'sender_type' => 'bot',
            'sender_name' => getSetting('chat_bot_name', 'Support'),
            'message' => $botReply['response'],
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    echo json_encode(['success' => true, 'bot_reply' => $botMsg]);
    break;

// ── Poll for new messages ──
case 'poll':
    $convoId = intval($_GET['conversation_id'] ?? 0);
    $afterId = intval($_GET['after_id'] ?? 0);
    if (!$convoId) { echo json_encode(['messages' => []]); break; }

    $messages = $db->fetchAll(
        "SELECT id, sender_type, sender_name, message, message_type, created_at FROM chat_messages WHERE conversation_id = ? AND id > ? AND sender_type IN ('admin','bot') ORDER BY created_at ASC",
        [$convoId, $afterId]
    );

    // Mark as read
    if (!empty($messages)) {
        $db->query("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND sender_type IN ('admin','bot') AND is_read = 0", [$convoId]);
        $db->update('chat_conversations', ['unread_user' => 0], 'id = ?', [$convoId]);
    }

    $convo = $db->fetch("SELECT status FROM chat_conversations WHERE id = ?", [$convoId]);
    echo json_encode(['messages' => $messages, 'status' => $convo['status'] ?? 'closed']);
    break;

// ── Close conversation ──
case 'close':
    $convoId = intval($_POST['conversation_id'] ?? 0);
    if ($convoId) {
        $db->update('chat_conversations', ['status' => 'closed'], 'id = ? AND (session_id = ? OR customer_id = ?)', [$convoId, $sessionId, $customerId ?: 0]);
    }
    echo json_encode(['success' => true]);
    break;

// ── Customer chat history (logged in only) ──
case 'history':
    if (!$customerId) { echo json_encode(['conversations' => []]); break; }
    $convos = $db->fetchAll("SELECT id, status, last_message, last_message_at, created_at FROM chat_conversations WHERE customer_id = ? ORDER BY updated_at DESC LIMIT 20", [$customerId]);
    echo json_encode(['conversations' => $convos]);
    break;

// ── ADMIN: List all conversations ──
case 'admin_list':
    // Verify admin session
    if (empty($_SESSION['admin_id'])) { echo json_encode(['error' => 'Unauthorized']); break; }
    $statusFilter = $_GET['status'] ?? '';
    $w = "1=1"; $p = [];
    if ($statusFilter) { $w .= " AND c.status = ?"; $p[] = $statusFilter; }
    $convos = $db->fetchAll("SELECT c.*, 
        (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = c.id) as msg_count
        FROM chat_conversations c WHERE {$w} ORDER BY 
        CASE WHEN c.status = 'waiting' THEN 0 WHEN c.status = 'active' THEN 1 ELSE 2 END,
        c.last_message_at DESC LIMIT 100", $p);
    echo json_encode(['conversations' => $convos]);
    break;

// ── ADMIN: Get conversation messages ──
case 'admin_messages':
    if (empty($_SESSION['admin_id'])) { echo json_encode(['error' => 'Unauthorized']); break; }
    $convoId = intval($_GET['conversation_id'] ?? 0);
    if (!$convoId) { echo json_encode(['messages' => []]); break; }

    // Mark admin messages as read
    $db->query("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND sender_type = 'user' AND is_read = 0", [$convoId]);
    $db->update('chat_conversations', ['unread_admin' => 0], 'id = ?', [$convoId]);

    $messages = $db->fetchAll("SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY created_at ASC", [$convoId]);
    $convo = $db->fetch("SELECT * FROM chat_conversations WHERE id = ?", [$convoId]);
    echo json_encode(['messages' => $messages, 'conversation' => $convo]);
    break;

// ── ADMIN: Send reply ──
case 'admin_send':
    if (empty($_SESSION['admin_id'])) { echo json_encode(['error' => 'Unauthorized']); break; }
    $convoId = intval($_POST['conversation_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if (!$convoId || !$message) { echo json_encode(['success' => false]); break; }

    $adminName = $_SESSION['admin_name'] ?? 'Admin';
    $db->insert('chat_messages', [
        'conversation_id' => $convoId,
        'sender_type' => 'admin',
        'sender_id' => $_SESSION['admin_id'],
        'sender_name' => $adminName,
        'message' => $message,
    ]);
    $convo = $db->fetch("SELECT unread_user FROM chat_conversations WHERE id = ?", [$convoId]);
    $db->update('chat_conversations', [
        'last_message' => mb_substr($message, 0, 200),
        'last_message_at' => date('Y-m-d H:i:s'),
        'unread_user' => ($convo['unread_user'] ?? 0) + 1,
        'status' => 'active',
        'assigned_to' => $_SESSION['admin_id'],
    ], 'id = ?', [$convoId]);

    echo json_encode(['success' => true]);
    break;

// ── ADMIN: Poll new messages for a conversation ──
case 'admin_poll':
    if (empty($_SESSION['admin_id'])) { echo json_encode(['error' => 'Unauthorized']); break; }
    $convoId = intval($_GET['conversation_id'] ?? 0);
    $afterId = intval($_GET['after_id'] ?? 0);
    $messages = [];
    if ($convoId) {
        $messages = $db->fetchAll("SELECT * FROM chat_messages WHERE conversation_id = ? AND id > ? ORDER BY created_at ASC", [$convoId, $afterId]);
        if (!empty($messages)) {
            $db->query("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND sender_type = 'user' AND is_read = 0", [$convoId]);
            $db->update('chat_conversations', ['unread_admin' => 0], 'id = ?', [$convoId]);
        }
    }
    // Also return updated conversation list counts
    $totalUnread = $db->fetch("SELECT SUM(unread_admin) as t FROM chat_conversations WHERE status != 'closed'")['t'] ?? 0;
    $waitingCount = $db->count('chat_conversations', "status = 'waiting'");
    echo json_encode(['messages' => $messages, 'total_unread' => intval($totalUnread), 'waiting' => $waitingCount]);
    break;

// ── ADMIN: Close conversation ──
case 'admin_close':
    if (empty($_SESSION['admin_id'])) { echo json_encode(['error' => 'Unauthorized']); break; }
    $convoId = intval($_POST['conversation_id'] ?? 0);
    if ($convoId) {
        $db->update('chat_conversations', ['status' => 'closed'], 'id = ?', [$convoId]);
        $db->insert('chat_messages', [
            'conversation_id' => $convoId,
            'sender_type' => 'system',
            'message' => 'Chat closed by admin',
            'message_type' => 'system',
        ]);
    }
    echo json_encode(['success' => true]);
    break;

default:
    echo json_encode(['error' => 'Invalid action']);
}

/**
 * Find matching auto-reply for a message
 */
function findAutoReply($db, $message) {
    $msg = mb_strtolower(trim($message));
    try {
        $replies = $db->fetchAll("SELECT * FROM chat_auto_replies WHERE is_active = 1 ORDER BY priority DESC, id ASC");
    } catch (\Throwable $e) { return null; }

    foreach ($replies as $reply) {
        $triggers = array_map('trim', explode(',', mb_strtolower($reply['trigger_words'])));
        foreach ($triggers as $trigger) {
            if (empty($trigger)) continue;
            $matched = false;
            switch ($reply['match_type']) {
                case 'exact': $matched = ($msg === $trigger); break;
                case 'starts_with': $matched = (mb_strpos($msg, $trigger) === 0); break;
                case 'contains': default: $matched = (mb_strpos($msg, $trigger) !== false); break;
            }
            if ($matched) return $reply;
        }
    }
    return null;
}
