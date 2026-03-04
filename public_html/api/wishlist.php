<?php
/**
 * Wishlist API
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isCustomerLoggedIn()) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'login_required']);
        exit;
    }
    redirect(url('login?redirect=' . urlencode($_POST['redirect'] ?? url())));
}

$db = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
$customerId = getCustomerId();
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

if ($action === 'toggle') {
    $exists = $db->fetch("SELECT id FROM wishlists WHERE customer_id = ? AND product_id = ?", [$customerId, $productId]);
    if ($exists) {
        $db->delete('wishlists', 'id = ?', [$exists['id']]);
        $added = false;
    } else {
        $db->insert('wishlists', ['customer_id' => $customerId, 'product_id' => $productId]);
        $added = true;
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'added' => $added, 'count' => getWishlistCount()]);
        exit;
    }
    redirect($_POST['redirect'] ?? url());
}

if ($action === 'add') {
    $exists = $db->fetch("SELECT id FROM wishlists WHERE customer_id = ? AND product_id = ?", [$customerId, $productId]);
    if (!$exists) {
        $db->insert('wishlists', ['customer_id' => $customerId, 'product_id' => $productId]);
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'count' => getWishlistCount()]);
        exit;
    }
    redirect($_POST['redirect'] ?? url());
}

if ($action === 'remove') {
    $db->delete('wishlists', 'customer_id = ? AND product_id = ?', [$customerId, $productId]);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'count' => getWishlistCount()]);
        exit;
    }
    redirect($_POST['redirect'] ?? url('account?tab=wishlist'));
}
