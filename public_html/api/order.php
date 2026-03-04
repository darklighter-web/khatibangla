<?php
/**
 * Order API - Place Order via AJAX
 * Wrapped in full error handling to always return JSON
 */

// Catch ALL errors/warnings as JSON (but respect @ suppression)
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false; // Respect @ error suppression operator
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

ob_start(); // Buffer any stray output

try {
    require_once __DIR__ . '/../includes/session.php';
    header('Content-Type: application/json');
    require_once __DIR__ . '/../includes/functions.php';

    // Ensure 'landing_page' channel exists in orders table ENUM
    if (($_POST['channel'] ?? '') === 'landing_page') {
        try {
            $dbc = Database::getInstance();
            $ci = $dbc->fetch("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'channel'");
            if ($ci && stripos($ci['COLUMN_TYPE'], 'enum') !== false && stripos($ci['COLUMN_TYPE'], 'landing_page') === false) {
                $nt = str_replace(")", ",'landing_page')", $ci['COLUMN_TYPE']);
                $dbc->query("ALTER TABLE orders MODIFY COLUMN channel $nt DEFAULT 'website'");
            }
        } catch (\Throwable $e) { /* ignore */ }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Get form data
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $shippingArea = $_POST['shipping_area'] ?? 'outside_dhaka';
    $notes = sanitize($_POST['notes'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $district = sanitize($_POST['district'] ?? '');

    // Validation
    $errors = [];
    if (empty($name)) $errors[] = 'নাম দিন';
    if (empty($phone) || !preg_match('/^01[0-9]{9}$/', $phone)) $errors[] = 'সঠিক মোবাইল নম্বর দিন';
    if (empty($address)) $errors[] = 'ঠিকানা দিন';

    if (!empty($errors)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        exit;
    }

    // CSRF check
    if (!verifyCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'সিকিউরিটি টোকেন মেলেনি। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।']);
        exit;
    }

    // Create order
    $result = createOrder([
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'address' => $address,
        'city' => $city,
        'district' => $district,
        'shipping_area' => $shippingArea,
        'payment_method' => 'cod',
        'channel' => in_array($_POST['channel'] ?? '', ['website','facebook','phone','whatsapp','instagram','landing_page']) ? $_POST['channel'] : 'website',
        'notes' => $notes . (($_POST['lp_page_id'] ?? '') ? ' [LP#' . intval($_POST['lp_page_id']) . ']' : ''),
        'coupon_code' => sanitize($_POST['coupon_code'] ?? ''),
        'store_credit_used' => floatval($_POST['store_credit_used'] ?? 0),
        'progress_bar_discount' => floatval($_POST['progress_bar_discount'] ?? 0),
    ]);

    ob_end_clean();
    echo json_encode($result);

} catch (\Throwable $e) {
    // Catch ANY error — PHP fatal, DB errors, etc — always return JSON
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(200); // Force 200 so JS .json() works

    // Log the real error for debugging
    $errorDetail = $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
    error_log('ORDER API ERROR: ' . $errorDetail);

    echo json_encode([
        'success' => false,
        'message' => 'অর্ডার প্রক্রিয়ায় সমস্যা হয়েছে। আবার চেষ্টা করুন।',
        'debug' => $errorDetail, // Remove after debugging
    ]);
}
