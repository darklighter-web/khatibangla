<?php
/**
 * Coupon API — Validate and apply coupons
 */
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'validate') {
    $code = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));
    $subtotal = floatval($_GET['subtotal'] ?? $_POST['subtotal'] ?? 0);
    
    if (empty($code)) {
        echo json_encode(['success' => false, 'message' => 'কুপন কোড দিন']);
        exit;
    }
    
    $db = Database::getInstance();
    
    // Try exact match first, then case-insensitive
    $coupon = $db->fetch("SELECT * FROM coupons WHERE UPPER(code) = ? AND is_active = 1", [$code]);
    
    if (!$coupon) {
        // Maybe coupon exists but is_active = 0
        $inactive = $db->fetch("SELECT id FROM coupons WHERE UPPER(code) = ?", [$code]);
        if ($inactive) {
            echo json_encode(['success' => false, 'message' => 'এই কুপন নিষ্ক্রিয় করা হয়েছে']);
        } else {
            echo json_encode(['success' => false, 'message' => 'কুপন কোড সঠিক নয়']);
        }
        exit;
    }
    
    // Check start date — only if a real date is set
    $startDate = trim($coupon['start_date'] ?? '');
    if ($startDate && $startDate !== '0000-00-00 00:00:00' && $startDate !== '0000-00-00') {
        $startTs = strtotime($startDate);
        if ($startTs !== false && $startTs > time()) {
            echo json_encode(['success' => false, 'message' => 'এই কুপন ' . date('d M Y', $startTs) . ' থেকে সক্রিয় হবে']);
            exit;
        }
    }
    
    // Check end date — only if a real date is set
    $endDate = trim($coupon['end_date'] ?? '');
    if ($endDate && $endDate !== '0000-00-00 00:00:00' && $endDate !== '0000-00-00') {
        $endTs = strtotime($endDate);
        if ($endTs !== false && $endTs < time()) {
            echo json_encode(['success' => false, 'message' => 'এই কুপনের মেয়াদ শেষ হয়ে গেছে']);
            exit;
        }
    }
    
    // Check usage limit
    if ($coupon['usage_limit'] > 0 && $coupon['used_count'] >= $coupon['usage_limit']) {
        echo json_encode(['success' => false, 'message' => 'এই কুপন ব্যবহার সীমা শেষ']);
        exit;
    }
    
    // Check minimum order
    if ($coupon['min_order_amount'] > 0 && $subtotal < $coupon['min_order_amount']) {
        echo json_encode([
            'success' => false, 
            'message' => 'সর্বনিম্ন অর্ডার ৳' . number_format($coupon['min_order_amount']) . ' হতে হবে'
        ]);
        exit;
    }
    
    // Calculate discount
    $discount = 0;
    $discountLabel = '';
    
    if ($coupon['type'] === 'percentage') {
        $discount = ($subtotal * $coupon['value']) / 100;
        if ($coupon['max_discount'] > 0 && $discount > $coupon['max_discount']) {
            $discount = $coupon['max_discount'];
        }
        $discountLabel = $coupon['value'] . '% ছাড়';
    } elseif ($coupon['type'] === 'fixed') {
        $discount = min($coupon['value'], $subtotal);
        $discountLabel = '৳' . number_format($coupon['value']) . ' ছাড়';
    } elseif ($coupon['type'] === 'free_shipping') {
        $discount = 0;
        $discountLabel = 'ফ্রি ডেলিভারি';
    }
    
    echo json_encode([
        'success' => true,
        'code' => $coupon['code'],
        'type' => $coupon['type'],
        'value' => floatval($coupon['value']),
        'discount' => round($discount, 2),
        'discount_label' => $discountLabel,
        'free_shipping' => $coupon['type'] === 'free_shipping',
        'message' => 'কুপন প্রয়োগ হয়েছে! ' . $discountLabel
    ]);
    exit;
}

// Debug endpoint — remove after testing
if ($action === 'debug' && isset($_GET['code'])) {
    $db = Database::getInstance();
    $code = strtoupper(trim($_GET['code']));
    $coupon = $db->fetch("SELECT * FROM coupons WHERE UPPER(code) = ?", [$code]);
    if ($coupon) {
        echo json_encode([
            'found' => true,
            'code' => $coupon['code'],
            'is_active' => $coupon['is_active'],
            'start_date' => $coupon['start_date'],
            'end_date' => $coupon['end_date'],
            'start_ts' => $coupon['start_date'] ? strtotime($coupon['start_date']) : null,
            'now_ts' => time(),
            'server_time' => date('Y-m-d H:i:s'),
            'usage' => $coupon['used_count'] . '/' . ($coupon['usage_limit'] ?: 'unlimited'),
        ]);
    } else {
        echo json_encode(['found' => false, 'code' => $code]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
