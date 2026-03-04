<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $db->update('notifications', ['is_read' => 1], 'id = ?', [intval($_POST['notif_id'])]);
        redirect(adminUrl('pages/notifications.php'));
    }
    if ($action === 'mark_all_read') {
        $db->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
        redirect(adminUrl('pages/notifications.php?msg=all_read'));
    }
    if ($action === 'delete_read') {
        $db->query("DELETE FROM notifications WHERE is_read = 1");
        redirect(adminUrl('pages/notifications.php?msg=cleared'));
    }
}

$filter = $_GET['filter'] ?? 'all';
$where = "1=1";
if ($filter === 'unread') $where = "n.is_read = 0";
elseif ($filter === 'read') $where = "n.is_read = 1";

$notifications = $db->fetchAll("SELECT n.* FROM notifications n WHERE $where ORDER BY n.created_at DESC LIMIT 100");
$unreadCount = $db->count('notifications', 'is_read = 0');

require_once __DIR__ . '/../includes/header.php';
$msg = $_GET['msg'] ?? '';

$typeIcons = [
    'order' => '<svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>',
    'stock' => '<svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>',
    'customer' => '<svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
    'system' => '<svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'fraud' => '<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>',
];
?>

<?php if ($msg): ?>
<div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
    <?= $msg === 'all_read' ? 'All marked as read.' : 'Read notifications cleared.' ?>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border shadow-sm">
    <div class="p-4 border-b flex flex-wrap gap-3 items-center justify-between">
        <div class="flex gap-2">
            <?php foreach (['all' => 'All', 'unread' => 'Unread (' . $unreadCount . ')', 'read' => 'Read'] as $k => $v): ?>
            <a href="?filter=<?= $k ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $filter === $k ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= $v ?></a>
            <?php endforeach; ?>
        </div>
        <div class="flex gap-2">
            <?php if ($unreadCount > 0): ?>
            <form method="POST" class="inline"><input type="hidden" name="action" value="mark_all_read">
                <button class="text-blue-600 hover:text-blue-800 text-sm font-medium">Mark All Read</button>
            </form>
            <?php endif; ?>
            <form method="POST" class="inline" onsubmit="return confirm('Delete all read notifications?')">
                <input type="hidden" name="action" value="delete_read">
                <button class="text-red-600 hover:text-red-800 text-sm font-medium">Clear Read</button>
            </form>
        </div>
    </div>
    <div class="divide-y">
        <?php foreach ($notifications as $notif): ?>
        <div class="p-4 flex items-start gap-4 <?= !$notif['is_read'] ? 'bg-blue-50/50' : 'hover:bg-gray-50' ?>">
            <div class="flex-shrink-0 mt-0.5">
                <?= $typeIcons[$notif['type']] ?? $typeIcons['system'] ?>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <h4 class="text-sm font-medium <?= !$notif['is_read'] ? 'text-gray-900' : 'text-gray-600' ?>"><?= e($notif['title']) ?></h4>
                    <?php if (!$notif['is_read']): ?>
                    <span class="w-2 h-2 bg-blue-500 rounded-full flex-shrink-0"></span>
                    <?php endif; ?>
                </div>
                <?php if ($notif['message']): ?>
                <p class="text-sm text-gray-500 mt-0.5"><?= e($notif['message']) ?></p>
                <?php endif; ?>
                <p class="text-xs text-gray-400 mt-1"><?= date('d M Y H:i', strtotime($notif['created_at'])) ?></p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <?php if ($notif['link']): ?>
                <a href="<?= e($notif['link']) ?>" class="text-blue-600 hover:text-blue-800 text-xs">View</a>
                <?php endif; ?>
                <?php if (!$notif['is_read']): ?>
                <form method="POST" class="inline"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notif_id" value="<?= $notif['id'] ?>">
                    <button class="text-gray-400 hover:text-gray-600 text-xs">Mark Read</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($notifications)): ?>
        <div class="p-8 text-center text-gray-400">No notifications</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
