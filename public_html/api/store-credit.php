<?php
/**
 * Store Credit API
 * Actions: balance, history, admin_adjust
 */
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'balance':
            // Get logged-in customer's balance
            if (!isCustomerLoggedIn()) {
                echo json_encode(['success' => false, 'message' => 'Login required']);
                exit;
            }
            $balance = getStoreCredit(getCustomerId());
            $creditRate = floatval(getSetting('store_credit_conversion_rate', '0.75'));
            if ($creditRate <= 0) $creditRate = 0.75;
            echo json_encode(['success' => true, 'balance' => $balance, 'conversion_rate' => $creditRate, 'balance_tk' => round($balance * $creditRate, 2)]);
            break;

        case 'history':
            if (!isCustomerLoggedIn()) {
                echo json_encode(['success' => false, 'message' => 'Login required']);
                exit;
            }
            $txns = getCreditTransactions(getCustomerId(), 50);
            echo json_encode(['success' => true, 'transactions' => $txns]);
            break;

        case 'admin_adjust':
            // Admin only: adjust customer credit
            if (empty($_SESSION['admin_id'])) {
                echo json_encode(['success' => false, 'message' => 'Admin access required']);
                exit;
            }
            $customerId = intval($_POST['customer_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $description = sanitize($_POST['description'] ?? 'Admin adjustment');
            
            if (!$customerId || !$amount) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }
            
            $newBalance = addStoreCredit($customerId, $amount, 'admin_adjust', null, null, $description, $_SESSION['admin_id']);
            echo json_encode(['success' => true, 'new_balance' => $newBalance, 'message' => 'Credit adjusted']);
            break;

        case 'delete_transaction':
            // Admin only: delete a specific credit transaction and reverse its effect
            if (empty($_SESSION['admin_id'])) {
                echo json_encode(['success' => false, 'message' => 'Admin access required']);
                exit;
            }
            $txId = intval($_POST['transaction_id'] ?? 0);
            if (!$txId) {
                echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
                exit;
            }
            
            $tx = $db->fetch("SELECT * FROM store_credit_transactions WHERE id = ?", [$txId]);
            if (!$tx) {
                echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                exit;
            }
            
            // Reverse the credit effect
            $reverseAmount = -floatval($tx['amount']);
            if ($reverseAmount != 0) {
                if ($reverseAmount > 0) {
                    $db->query("UPDATE customers SET store_credit = store_credit + ? WHERE id = ?", [abs($reverseAmount), $tx['customer_id']]);
                } else {
                    $db->query("UPDATE customers SET store_credit = GREATEST(0, store_credit - ?) WHERE id = ?", [abs($reverseAmount), $tx['customer_id']]);
                }
            }
            
            // Delete the transaction record
            $db->delete('store_credit_transactions', 'id = ?', [$txId]);
            
            $newBalance = getStoreCredit($tx['customer_id']);
            logActivity($_SESSION['admin_id'], 'delete_credit_tx', 'store_credit', $txId, "Deleted tx #{$txId} ({$tx['type']}: {$tx['amount']} credits) for customer #{$tx['customer_id']}");
            
            echo json_encode(['success' => true, 'new_balance' => $newBalance, 'message' => 'Transaction deleted and balance adjusted']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
