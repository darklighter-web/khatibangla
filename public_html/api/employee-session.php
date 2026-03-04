<?php
/**
 * Employee Session API — Clock In/Out & Leave Management
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../admin/includes/auth.php';

header('Content-Type: application/json');

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$adminId = getAdminId();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

switch ($action) {

    case 'clock_in':
        // Check if already clocked in
        $active = $db->fetch("SELECT id FROM employee_sessions WHERE admin_user_id = ? AND status = 'active'", [$adminId]);
        if ($active) {
            echo json_encode(['success' => false, 'message' => 'Already clocked in']);
            break;
        }
        $db->query("INSERT INTO employee_sessions (admin_user_id, clock_in, ip_address, status) VALUES (?, NOW(), ?, 'active')", [$adminId, $ip]);
        logActivity($adminId, 'clock_in', 'employee_sessions', $db->getConnection()->lastInsertId());
        echo json_encode(['success' => true, 'message' => 'Clocked in successfully', 'time' => date('h:i A')]);
        break;

    case 'clock_out':
        $notes = trim($_POST['notes'] ?? '');
        $active = $db->fetch("SELECT id, clock_in FROM employee_sessions WHERE admin_user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$adminId]);
        if (!$active) {
            echo json_encode(['success' => false, 'message' => 'No active session found']);
            break;
        }
        $hours = round((time() - strtotime($active['clock_in'])) / 3600, 2);
        $db->query("UPDATE employee_sessions SET clock_out = NOW(), hours_worked = ?, status = 'completed', notes = ? WHERE id = ?", [$hours, $notes, $active['id']]);
        logActivity($adminId, 'clock_out', 'employee_sessions', $active['id']);
        echo json_encode(['success' => true, 'message' => 'Clocked out', 'hours' => $hours]);
        break;

    case 'status':
        $active = $db->fetch("SELECT id, clock_in FROM employee_sessions WHERE admin_user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$adminId]);
        $todaySessions = $db->fetchAll("SELECT * FROM employee_sessions WHERE admin_user_id = ? AND DATE(clock_in) = CURDATE() ORDER BY clock_in", [$adminId]);
        $todayHours = 0;
        foreach ($todaySessions as $s) {
            if ($s['status'] === 'active') {
                $todayHours += (time() - strtotime($s['clock_in'])) / 3600;
            } else {
                $todayHours += floatval($s['hours_worked']);
            }
        }
        echo json_encode([
            'success' => true,
            'clocked_in' => $active ? true : false,
            'clock_in_time' => $active ? date('h:i A', strtotime($active['clock_in'])) : null,
            'elapsed' => $active ? round((time() - strtotime($active['clock_in'])) / 3600, 2) : 0,
            'today_hours' => round($todayHours, 2),
            'sessions_today' => count($todaySessions),
        ]);
        break;

    case 'today_activity':
        $userId = intval($_GET['user_id'] ?? $adminId);
        // Only super admin can view others
        if ($userId !== $adminId && !isSuperAdmin()) $userId = $adminId;

        $activities = $db->fetchAll(
            "SELECT action, entity_type, entity_id, created_at FROM activity_logs WHERE admin_user_id = ? AND DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 50",
            [$userId]
        );
        echo json_encode(['success' => true, 'activities' => $activities]);
        break;

    case 'mark_leave':
        if (!isSuperAdmin()) {
            echo json_encode(['success' => false, 'message' => 'Only super admin can mark leaves']);
            break;
        }
        $userId = intval($_POST['user_id'] ?? 0);
        $leaveDate = $_POST['leave_date'] ?? date('Y-m-d');
        $leaveType = $_POST['leave_type'] ?? 'casual';
        $reason = trim($_POST['reason'] ?? '');
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'User required']);
            break;
        }
        try {
            $db->query("INSERT INTO employee_leaves (admin_user_id, leave_date, leave_type, reason, approved_by, status) VALUES (?,?,?,?,?,'approved') ON DUPLICATE KEY UPDATE leave_type=VALUES(leave_type), reason=VALUES(reason)", [$userId, $leaveDate, $leaveType, $reason, $adminId]);
            echo json_encode(['success' => true, 'message' => 'Leave marked']);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'remove_leave':
        if (!isSuperAdmin()) {
            echo json_encode(['success' => false, 'message' => 'Only super admin']);
            break;
        }
        $id = intval($_POST['id'] ?? 0);
        $db->query("DELETE FROM employee_leaves WHERE id = ?", [$id]);
        echo json_encode(['success' => true]);
        break;

    case 'attendance_report':
        // Get attendance summary for date range
        $userId = intval($_GET['user_id'] ?? 0);
        $month = $_GET['month'] ?? date('Y-m');
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        if ($userId && !isSuperAdmin() && $userId !== $adminId) $userId = $adminId;

        $query = "SELECT es.admin_user_id, au.full_name, DATE(es.clock_in) as work_date,
                    MIN(es.clock_in) as first_in, MAX(COALESCE(es.clock_out, NOW())) as last_out,
                    SUM(CASE WHEN es.status='active' THEN TIMESTAMPDIFF(SECOND, es.clock_in, NOW())/3600 ELSE es.hours_worked END) as total_hours,
                    COUNT(*) as sessions
                  FROM employee_sessions es
                  JOIN admin_users au ON au.id = es.admin_user_id
                  WHERE DATE(es.clock_in) BETWEEN ? AND ?";
        $params = [$startDate, $endDate];

        if ($userId) {
            $query .= " AND es.admin_user_id = ?";
            $params[] = $userId;
        }
        $query .= " GROUP BY es.admin_user_id, DATE(es.clock_in) ORDER BY work_date DESC, au.full_name";
        $attendance = $db->fetchAll($query, $params);

        // Leaves
        $leaveQuery = "SELECT el.*, au.full_name FROM employee_leaves el JOIN admin_users au ON au.id = el.admin_user_id WHERE el.leave_date BETWEEN ? AND ?";
        $leaveParams = [$startDate, $endDate];
        if ($userId) {
            $leaveQuery .= " AND el.admin_user_id = ?";
            $leaveParams[] = $userId;
        }
        $leaveQuery .= " ORDER BY el.leave_date DESC";
        $leaves = $db->fetchAll($leaveQuery, $leaveParams);

        echo json_encode(['success' => true, 'attendance' => $attendance, 'leaves' => $leaves]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
