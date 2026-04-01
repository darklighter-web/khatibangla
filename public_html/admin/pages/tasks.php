<?php
/**
 * Admin - Task Management
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Tasks';
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_task') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            sanitize($_POST['title']),
            $_POST['description'] ?? '',
            (int)$_POST['assigned_to'] ?: null,
            $_POST['priority'],
            $_POST['status'],
            $_POST['due_date'] ?: null
        ];
        
        if ($id > 0) {
            $completedAt = ($_POST['status'] === 'completed') ? date('Y-m-d H:i:s') : null;
            $data[] = $completedAt;
            $data[] = $id;
            $db->query("UPDATE tasks SET title=?, description=?, assigned_to=?, priority=?, status=?, due_date=?, completed_at=? WHERE id=?", $data);
        } else {
            $data[] = getAdminId();
            $db->query("INSERT INTO tasks (title, description, assigned_to, priority, status, due_date, assigned_by) VALUES (?,?,?,?,?,?,?)", $data);
        }
        logActivity(getAdminId(), 'task_saved', 'task', $id);
        redirect(adminUrl('pages/tasks.php?msg=saved'));
    }
    
    if ($action === 'quick_status') {
        $id = (int)$_POST['id'];
        $status = $_POST['status'];
        $completedAt = ($status === 'completed') ? date('Y-m-d H:i:s') : null;
        $db->query("UPDATE tasks SET status=?, completed_at=? WHERE id=?", [$status, $completedAt, $id]);
        redirect(adminUrl('pages/tasks.php?msg=updated'));
    }
    
    if ($action === 'delete_task') {
        $db->query("DELETE FROM tasks WHERE id=?", [(int)$_POST['id']]);
        redirect(adminUrl('pages/tasks.php?msg=deleted'));
    }
}

$msg = $_GET['msg'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$priorityFilter = $_GET['priority'] ?? '';
$assignedFilter = (int)($_GET['assigned'] ?? 0);

$admins = $db->fetchAll("SELECT id, full_name FROM admin_users WHERE is_active=1 ORDER BY full_name");

$where = "WHERE 1=1";
$params = [];
if ($statusFilter) { $where .= " AND t.status=?"; $params[] = $statusFilter; }
if ($priorityFilter) { $where .= " AND t.priority=?"; $params[] = $priorityFilter; }
if ($assignedFilter) { $where .= " AND t.assigned_to=?"; $params[] = $assignedFilter; }

$tasks = $db->fetchAll("SELECT t.*, 
    assignee.full_name as assigned_to_name,
    assigner.full_name as assigned_by_name
    FROM tasks t 
    LEFT JOIN admin_users assignee ON t.assigned_to=assignee.id
    LEFT JOIN admin_users assigner ON t.assigned_by=assigner.id
    $where ORDER BY FIELD(t.priority,'urgent','high','medium','low'), t.due_date ASC, t.created_at DESC", $params);

// Stats
$taskStats = $db->fetch("SELECT 
    COUNT(*) as total,
    SUM(status='pending') as pending,
    SUM(status='in_progress') as in_progress,
    SUM(status='completed') as completed,
    SUM(priority='urgent' AND status NOT IN ('completed','cancelled')) as urgent
    FROM tasks");

// Edit task
$editTask = null;
if (isset($_GET['edit'])) {
    $editTask = $db->fetch("SELECT * FROM tasks WHERE id=?", [(int)$_GET['edit']]);
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?><div class="mb-4 p-3 bg-green-50 text-green-700 rounded-xl text-sm">Task <?= e($msg) ?>!</div><?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500">Total</p><p class="text-2xl font-bold"><?= $taskStats['total'] ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500">Pending</p><p class="text-2xl font-bold text-yellow-600"><?= $taskStats['pending'] ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500">In Progress</p><p class="text-2xl font-bold text-blue-600"><?= $taskStats['in_progress'] ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500">Completed</p><p class="text-2xl font-bold text-green-600"><?= $taskStats['completed'] ?></p></div>
    <div class="bg-white rounded-xl border p-4"><p class="text-xs text-gray-500">Urgent</p><p class="text-2xl font-bold text-red-600"><?= $taskStats['urgent'] ?></p></div>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Form -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><?= $editTask ? 'Edit' : 'New' ?> Task</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="save_task">
                <?php if ($editTask): ?><input type="hidden" name="id" value="<?= $editTask['id'] ?>"><?php endif; ?>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Title *</label>
                    <input type="text" name="title" required value="<?= e($editTask['title'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Description</label>
                    <textarea name="description" rows="3" class="w-full border rounded-lg px-3 py-2 text-sm"><?= e($editTask['description'] ?? '') ?></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Priority</label>
                        <select name="priority" class="w-full border rounded-lg px-3 py-2 text-sm">
                            <?php foreach (['low','medium','high','urgent'] as $p): ?>
                            <option value="<?= $p ?>" <?= ($editTask['priority'] ?? 'medium')===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                        <select name="status" class="w-full border rounded-lg px-3 py-2 text-sm">
                            <?php foreach (['pending','in_progress','completed','cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= ($editTask['status'] ?? 'pending')===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Assign To</label>
                    <select name="assigned_to" class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="">Unassigned</option>
                        <?php foreach ($admins as $admin): ?>
                        <option value="<?= $admin['id'] ?>" <?= ($editTask['assigned_to'] ?? '')==$admin['id']?'selected':'' ?>><?= e($admin['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Due Date</label>
                    <input type="datetime-local" name="due_date" value="<?= $editTask['due_date'] ? date('Y-m-d\TH:i', strtotime($editTask['due_date'])) : '' ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <?= $editTask ? 'Update' : 'Create' ?> Task
                </button>
            </form>
        </div>
    </div>
    
    <!-- Task List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="p-4 border-b">
                <form class="flex flex-wrap gap-3">
                    <select name="status" class="border rounded-lg px-3 py-2 text-sm">
                        <option value="">All Status</option>
                        <?php foreach (['pending','in_progress','completed','cancelled'] as $s): ?>
                        <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="priority" class="border rounded-lg px-3 py-2 text-sm">
                        <option value="">All Priority</option>
                        <?php foreach (['urgent','high','medium','low'] as $p): ?>
                        <option value="<?= $p ?>" <?= $priorityFilter===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="assigned" class="border rounded-lg px-3 py-2 text-sm">
                        <option value="">All Staff</option>
                        <?php foreach ($admins as $admin): ?>
                        <option value="<?= $admin['id'] ?>" <?= $assignedFilter==$admin['id']?'selected':'' ?>><?= e($admin['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700">Filter</button>
                </form>
            </div>
            <div class="divide-y">
                <?php foreach ($tasks as $task): 
                    $priorityColors = ['low'=>'gray','medium'=>'blue','high'=>'orange','urgent'=>'red'];
                    $statusColors = ['pending'=>'yellow','in_progress'=>'blue','completed'=>'green','cancelled'=>'gray'];
                    $pc = $priorityColors[$task['priority']] ?? 'gray';
                    $sc = $statusColors[$task['status']] ?? 'gray';
                    $isOverdue = $task['due_date'] && strtotime($task['due_date']) < time() && !in_array($task['status'],['completed','cancelled']);
                ?>
                <div class="p-4 hover:bg-gray-50 <?= $task['status']==='completed' ? 'opacity-60' : '' ?>">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="font-medium text-gray-800 <?= $task['status']==='completed' ? 'line-through' : '' ?>"><?= e($task['title']) ?></h4>
                                <span class="px-2 py-0.5 bg-<?= $pc ?>-100 text-<?= $pc ?>-700 rounded-full text-xs"><?= ucfirst($task['priority']) ?></span>
                                <span class="px-2 py-0.5 bg-<?= $sc ?>-100 text-<?= $sc ?>-700 rounded-full text-xs"><?= ucfirst(str_replace('_',' ',$task['status'])) ?></span>
                                <?php if ($isOverdue): ?>
                                <span class="px-2 py-0.5 bg-red-100 text-red-700 rounded-full text-xs">Overdue</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($task['description']): ?>
                            <p class="text-sm text-gray-500 mb-1"><?= e(mb_substr($task['description'], 0, 120)) ?></p>
                            <?php endif; ?>
                            <div class="flex items-center gap-4 text-xs text-gray-400">
                                <?php if ($task['assigned_to_name']): ?>
                                <span>Assigned: <?= e($task['assigned_to_name']) ?></span>
                                <?php endif; ?>
                                <?php if ($task['due_date']): ?>
                                <span class="<?= $isOverdue ? 'text-red-500 font-medium' : '' ?>">Due: <?= date('M d, g:ia', strtotime($task['due_date'])) ?></span>
                                <?php endif; ?>
                                <span>Created <?= date('M d', strtotime($task['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <?php if ($task['status'] !== 'completed'): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="quick_status">
                                <input type="hidden" name="id" value="<?= $task['id'] ?>">
                                <input type="hidden" name="status" value="completed">
                                <button title="Mark complete" class="p-1.5 rounded-lg hover:bg-green-50 text-gray-400 hover:text-green-600">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                            <a href="?edit=<?= $task['id'] ?>" class="p-1.5 rounded-lg hover:bg-blue-50 text-gray-400 hover:text-blue-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </a>
                            <form method="POST" onsubmit="return confirm('Delete?')">
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="id" value="<?= $task['id'] ?>">
                                <button class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($tasks)): ?>
                <div class="p-8 text-center text-gray-400">No tasks found</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
