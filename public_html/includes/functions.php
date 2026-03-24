<?php
/**
 * Core Helper Functions
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Escape HTML output
 */
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// ============================================================
// SETTINGS HELPERS
// ============================================================

function getSetting($key, $default = '') {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    
    $db = Database::getInstance();
    $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
    $cache[$key] = $row ? $row['setting_value'] : $default;
    return $cache[$key];
}

function getAllSettings($group = null) {
    $db = Database::getInstance();
    if ($group) {
        return $db->fetchAll("SELECT * FROM site_settings WHERE setting_group = ? ORDER BY id", [$group]);
    }
    return $db->fetchAll("SELECT * FROM site_settings ORDER BY setting_group, id");
}

function updateSetting($key, $value) {
    $db = Database::getInstance();
    $db->query(
        "INSERT INTO site_settings (setting_key, setting_value, setting_type, setting_group) 
         VALUES (?, ?, 'text', 'general') 
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$key, $value]
    );
}

// ============================================================
// URL & PATH HELPERS
// ============================================================

function url($path = '') {
    return SITE_URL . '/' . ltrim($path, '/');
}

function adminUrl($path = '') {
    return ADMIN_URL . '/' . ltrim($path, '/');
}

function asset($path) {
    return ASSETS_URL . '/' . ltrim($path, '/');
}

function uploadUrl($path) {
    return UPLOAD_URL . '/' . ltrim($path, '/');
}

/**
 * Safe image URL builder — prevents the double-path bug.
 * uploadFile() returns "folder/filename.jpg" but templates prepend the folder again.
 * This helper uses basename() to strip any folder prefix from stored DB values.
 * 
 * Usage: imgSrc('banners', $banner['image'])  → uploads/banners/filename.jpg
 *        imgSrc('products', $product['featured_image'])
 */
function imgSrc($folder, $storedValue) {
    if (empty($storedValue)) return '';
    $file = basename($storedValue); // strips any "folder/" prefix
    return UPLOAD_URL . '/' . trim($folder, '/') . '/' . $file;
}

function redirect($url) {
    header("Location: {$url}");
    exit;
}

/**
 * Safe redirect — only allows redirects to own domain.
 * Falls back to $default if URL is external or malformed.
 */
function safeRedirect($url, $default = '/') {
    $url = trim($url);
    // Must be a relative path or same-domain absolute URL
    if (empty($url)) {
        redirect($default);
    }
    $parsed = parse_url($url);
    // Relative URL (no host) — safe
    if (empty($parsed['host'])) {
        redirect($url);
    }
    // Absolute URL — check domain
    $siteHost = parse_url(SITE_URL, PHP_URL_HOST);
    if (strcasecmp($parsed['host'], $siteHost) === 0 || strcasecmp($parsed['host'], 'www.' . $siteHost) === 0) {
        redirect($url);
    }
    // External URL — reject
    redirect($default);
}

function currentUrl() {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
           . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

// ============================================================
// SECURITY HELPERS
// ============================================================

function sanitize($data) {
    if (is_array($data)) return array_map('sanitize', $data);
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function generateCSRFToken() {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verifyCSRFToken($token = null) {
    if ($token === null) $token = $_POST[CSRF_TOKEN_NAME] ?? $_GET[CSRF_TOKEN_NAME] ?? '';
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function csrfField() {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . generateCSRFToken() . '">';
}

function generateOrderNumber() {
    try {
        $db = Database::getInstance();
        $format = getSetting('order_number_format', 'numeric'); // numeric, prefix_numeric, prefix_sequential
        $prefix = getSetting('order_prefix', '');
        $digits = intval(getSetting('order_number_digits', 5));
        if ($digits < 4) $digits = 4;
        if ($digits > 8) $digits = 8;
        
        if ($format === 'prefix_sequential' && $prefix) {
            // Format: KB163779 - prefix + sequential number (no padding, auto-increment)
            $pattern = $db->fetch("SELECT MAX(CAST(SUBSTRING(order_number, ?) AS UNSIGNED)) as max_num FROM orders WHERE order_number LIKE ?", [strlen($prefix)+1, $prefix.'%']);
            $next = max(intval($pattern['max_num'] ?? 0) + 1, 1);
            $num = $prefix . $next;
            // Ensure unique
            $exists = $db->fetch("SELECT id FROM orders WHERE order_number = ?", [$num]);
            if ($exists) $num = $prefix . ($next + 1);
            return $num;
        } elseif ($format === 'prefix_numeric' && $prefix) {
            // Format: k0013 - prefix + zero-padded number
            $pattern = $db->fetch("SELECT MAX(CAST(REPLACE(SUBSTRING(order_number, ?), '-', '') AS UNSIGNED)) as max_num FROM orders WHERE order_number LIKE ?", [strlen($prefix)+1, $prefix.'%']);
            $next = max(intval($pattern['max_num'] ?? 0) + 1, 1);
            $num = $prefix . str_pad($next, $digits, '0', STR_PAD_LEFT);
            $exists = $db->fetch("SELECT id FROM orders WHERE order_number = ?", [$num]);
            if ($exists) $num = $prefix . str_pad($next + 1, $digits, '0', STR_PAD_LEFT);
            return $num;
        } else {
            // Default numeric: 00123
            $last = $db->fetch("SELECT MAX(CAST(order_number AS UNSIGNED)) as max_num FROM orders WHERE order_number REGEXP '^[0-9]+$'");
            $next = max(intval($last['max_num'] ?? 0) + 1, intval($db->fetch("SELECT MAX(id) as m FROM orders")['m'] ?? 0) + 1);
            $num = str_pad($next, $digits, '0', STR_PAD_LEFT);
            $exists = $db->fetch("SELECT id FROM orders WHERE order_number = ?", [$num]);
            if ($exists) $num = str_pad($next + 1, $digits, '0', STR_PAD_LEFT);
            return $num;
        }
    } catch (\Throwable $e) {
        return date('ymd') . rand(100, 999);
    }
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function getClientIP() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', $_SERVER[$key])[0];
            return trim($ip);
        }
    }
    return '0.0.0.0';
}

function isIPBlocked($ip) {
    $db = Database::getInstance();
    $row = $db->fetch(
        "SELECT id FROM blocked_ips WHERE ip_address = ? AND (is_permanent = 1 OR expires_at > NOW())", 
        [$ip]
    );
    return $row !== false;
}

function isPhoneBlocked($phone) {
    $db = Database::getInstance();
    $row = $db->fetch("SELECT id FROM blocked_phones WHERE phone = ?", [$phone]);
    return $row !== false;
}

// ============================================================
// IMAGE HELPERS
// ============================================================

function getProductImage($product, $size = 'medium') {
    // 1. Check featured_image column on product itself
    if (!empty($product['featured_image'])) {
        $file = basename($product['featured_image']);
        $path = UPLOAD_PATH . 'products/' . $file;
        if (file_exists($path)) {
            return imgSrc('products', $product['featured_image']);
        }
    }
    
    // 2. Check product_images table for primary image
    $db = Database::getInstance();
    $image = $db->fetch(
        "SELECT image_path FROM product_images WHERE product_id = ? AND is_primary = 1 LIMIT 1", 
        [$product['id']]
    );
    if ($image) {
        $file = basename($image['image_path']);
        $path = UPLOAD_PATH . 'products/' . $file;
        if (file_exists($path)) {
            return imgSrc('products', $image['image_path']);
        }
    }
    
    // 3. Check any image in product_images table
    $anyImage = $db->fetch(
        "SELECT image_path FROM product_images WHERE product_id = ? ORDER BY sort_order ASC LIMIT 1", 
        [$product['id']]
    );
    if ($anyImage) {
        $file = basename($anyImage['image_path']);
        $path = UPLOAD_PATH . 'products/' . $file;
        if (file_exists($path)) {
            return imgSrc('products', $anyImage['image_path']);
        }
    }
    
    // 4. Site-wide default product image from settings
    $default = getSetting('default_product_image');
    if ($default && file_exists(UPLOAD_PATH . $default)) {
        return uploadUrl($default);
    }
    
    // 5. Built-in default
    return asset('img/default-product.svg');
}

function getProductImages($productId) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC", 
        [$productId]
    );
}

function uploadFile($file, $directory, $allowedTypes = ['jpg','jpeg','png','gif','webp','svg']) {
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) return false;
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes)) return false;
    
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) return false;
    
    $dir = trim($directory, '/');
    $uploadDir = UPLOAD_PATH . $dir . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $destination = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return $dir . '/' . $filename;
    }
    return false;
}

// ============================================================
// PRODUCT HELPERS
// ============================================================

function getProduct($id) {
    $db = Database::getInstance();
    return $db->fetch("SELECT p.*, c.name as category_name, c.slug as category_slug 
                       FROM products p 
                       LEFT JOIN categories c ON c.id = p.category_id 
                       WHERE p.id = ?", [$id]);
}

function getProductBySlug($slug) {
    $db = Database::getInstance();
    return $db->fetch("SELECT p.*, c.name as category_name, c.slug as category_slug 
                       FROM products p 
                       LEFT JOIN categories c ON c.id = p.category_id 
                       WHERE p.slug = ? AND p.is_active = 1", [$slug]);
}

function getProducts($filters = []) {
    $db = Database::getInstance();
    $where = ["p.is_active = 1"];
    $params = [];
    
    if (!empty($filters['category_id'])) {
        $where[] = "p.category_id = ?";
        $params[] = $filters['category_id'];
    }
    if (!empty($filters['category_slug'])) {
        $where[] = "c.slug = ?";
        $params[] = $filters['category_slug'];
    }
    if (!empty($filters['is_featured'])) {
        $where[] = "p.is_featured = 1";
    }
    if (!empty($filters['is_on_sale'])) {
        $where[] = "p.is_on_sale = 1 AND p.sale_price IS NOT NULL";
    }
    if (!empty($filters['search'])) {
        $where[] = "(p.name LIKE ? OR p.name_bn LIKE ? OR p.tags LIKE ?)";
        $search = "%{$filters['search']}%";
        $params = array_merge($params, [$search, $search, $search]);
    }
    
    $whereStr = implode(' AND ', $where);
    $orderBy = $filters['order_by'] ?? 'p.is_featured DESC, p.created_at DESC';
    $limit = $filters['limit'] ?? ITEMS_PER_PAGE;
    $offset = $filters['offset'] ?? 0;
    
    $sql = "SELECT p.*, c.name as category_name, c.slug as category_slug 
            FROM products p 
            LEFT JOIN categories c ON c.id = p.category_id 
            WHERE {$whereStr} 
            ORDER BY {$orderBy} 
            LIMIT {$limit} OFFSET {$offset}";
    
    return $db->fetchAll($sql, $params);
}

function getProductPrice($product) {
    if ($product['sale_price'] && $product['sale_price'] > 0 && $product['sale_price'] < $product['regular_price']) {
        return $product['sale_price'];
    }
    return $product['regular_price'];
}

function formatPrice($amount) {
    $symbol = getSetting('currency_symbol', '৳');
    return $symbol . ' ' . number_format($amount, 0);
}

function getDiscountPercent($product) {
    if (empty($product['sale_price']) || empty($product['regular_price'])) return 0;
    if ($product['sale_price'] >= $product['regular_price']) return 0;
    return round((($product['regular_price'] - $product['sale_price']) / $product['regular_price']) * 100);
}

function generateProductSKU($productId = null, $categoryName = null) {
    $db = Database::getInstance();
    $prefix = strtoupper(getSetting('sku_prefix', strtoupper(substr(getSetting('site_name', 'SHOP'), 0, 3))));
    $separator = getSetting('sku_separator', '-');
    $digits = intval(getSetting('sku_digits', 5));
    if ($digits < 3) $digits = 3;
    if ($digits > 7) $digits = 7;
    $includeCategory = getSetting('sku_include_category', '0') === '1';

    $id = $productId;
    if (!$id) {
        $id = (intval($db->fetch("SELECT MAX(id) as m FROM products")['m'] ?? 0)) + 1;
    }
    $numPart = str_pad($id, $digits, '0', STR_PAD_LEFT);

    if ($includeCategory && $categoryName) {
        $catCode = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $categoryName), 0, 3));
        if ($catCode) {
            return $prefix . $separator . $catCode . $separator . $numPart;
        }
    }
    return $prefix . $separator . $numPart;
}

function generateVariantSKU($productSku, $variantValue, $variantIndex = 1) {
    $separator = getSetting('sku_separator', '-');
    $varStyle = getSetting('sku_variant_style', 'value');

    if ($varStyle === 'number') {
        $suffix = str_pad($variantIndex, 2, '0', STR_PAD_LEFT);
    } elseif ($varStyle === 'short') {
        $suffix = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $variantValue), 0, 3));
        if (!$suffix) $suffix = 'V' . $variantIndex;
    } else {
        // value (full)
        $suffix = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $variantValue));
        $suffix = substr($suffix, 0, 12);
        if (!$suffix) $suffix = 'V';
    }
    $sku = $productSku . $separator . $suffix;
    
    // Ensure uniqueness
    $db = Database::getInstance();
    $exists = $db->fetch("SELECT id FROM products WHERE sku = ?", [$sku]);
    if ($exists) {
        $existsVar = $db->fetch("SELECT id FROM product_variants WHERE sku = ?", [$sku]);
        if ($existsVar) $sku .= $separator . rand(10, 99);
    }
    return $sku;
}


function searchProductsForAutocomplete($query, $limit = 8) {
    $db = Database::getInstance();
    $term = "%{$query}%";
    return $db->fetchAll(
        "SELECT p.id, p.name, p.name_bn, p.slug, p.featured_image, p.regular_price, p.sale_price, p.stock_status,
                c.name as category_name
         FROM products p LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.is_active = 1 AND (p.name LIKE ? OR p.name_bn LIKE ? OR p.sku LIKE ? OR p.tags LIKE ?)
         ORDER BY p.sales_count DESC, p.name ASC LIMIT ?",
        [$term, $term, $term, $term, $limit]
    );
}

// ============================================================
// CATEGORY HELPERS
// ============================================================

function getCategories($parentId = null, $activeOnly = true) {
    $db = Database::getInstance();
    $where = $activeOnly ? "is_active = 1" : "1=1";
    if ($parentId !== null) {
        $where .= " AND parent_id = " . intval($parentId);
    } else {
        $where .= " AND parent_id IS NULL";
    }
    return $db->fetchAll("SELECT * FROM categories WHERE {$where} ORDER BY sort_order ASC");
}

function getCategoryBySlug($slug) {
    $db = Database::getInstance();
    return $db->fetch("SELECT * FROM categories WHERE slug = ?", [$slug]);
}

// ============================================================
// CART HELPERS (Session-based)
// ============================================================

function getCart() {
    return $_SESSION['cart'] ?? [];
}

function addToCart($productId, $quantity = 1, $variantId = null, $customerUpload = null, $priceOverride = null) {
    $product = getProduct($productId);
    if (!$product) return false;
    
    $price = ($priceOverride !== null) ? $priceOverride : getProductPrice($product);
    $variantNames = [];
    $variantIds = [];
    
    // Handle multiple variant IDs (comma-separated)
    $ids = $variantId ? array_filter(array_map('intval', explode(',', $variantId))) : [];
    
    if (!empty($ids)) {
        $db = Database::getInstance();
        foreach ($ids as $vid) {
            $variant = $db->fetch("SELECT * FROM product_variants WHERE id = ? AND product_id = ? AND is_active = 1", [$vid, $productId]);
            if (!$variant) continue;
            $variantIds[] = $vid;
            $optType = $variant['option_type'] ?? 'addon';
            if ($optType === 'variation' && $variant['absolute_price'] !== null) {
                $price = floatval($variant['absolute_price']); // Variation replaces base
            } else {
                $price += floatval($variant['price_adjustment']); // Addon adds to price
            }
            $variantNames[] = $variant['variant_name'] . ': ' . $variant['variant_value'];
        }
    }
    
    $variantKey = !empty($variantIds) ? implode('_', $variantIds) : '';
    $key = $variantKey ? "{$productId}_{$variantKey}" : (string)$productId;
    $variantName = implode(', ', $variantNames);
    
    if (isset($_SESSION['cart'][$key])) {
        $_SESSION['cart'][$key]['quantity'] += $quantity;
        if ($customerUpload) $_SESSION['cart'][$key]['customer_upload'] = $customerUpload;
    } else {
        $_SESSION['cart'][$key] = [
            'product_id' => $productId,
            'variant_id' => $variantId, // Keep original for reference
            'name' => $product['name_bn'] ?: $product['name'],
            'variant_name' => $variantName,
            'price' => $price,
            'regular_price' => $product['regular_price'],
            'quantity' => $quantity,
            'image' => getProductImage($product),
            'customer_upload' => $customerUpload,
        ];
    }
    
    return true;
}

function updateCartItem($key, $quantity) {
    if (isset($_SESSION['cart'][$key])) {
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$key]);
        } else {
            $_SESSION['cart'][$key]['quantity'] = $quantity;
        }
    }
}

function removeFromCart($key) {
    unset($_SESSION['cart'][$key]);
}

function clearCart() {
    $_SESSION['cart'] = [];
}

function getCartTotal() {
    $total = 0;
    foreach (getCart() as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    return $total;
}

function getCartCount() {
    $count = 0;
    foreach (getCart() as $item) {
        $count += $item['quantity'];
    }
    return $count;
}

// ============================================================
// ORDER HELPERS
// ============================================================

function _creditLog($msg) {
    try {
        static $logFile = null;
        if ($logFile === null) {
            $logDir = dirname(__DIR__) . '/tmp';
            if (!is_dir($logDir)) { mkdir($logDir, 0755, true); }
            $logFile = $logDir . '/credit-debug.log';
        }
        file_put_contents($logFile, date('H:i:s') . " {$msg}\n", FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        // Never let logging crash the order flow
    }
}

function createOrder($data) {
    $db = Database::getInstance();
    $ip = getClientIP();
    _creditLog("ORDER START: store_credit_used=" . ($data['store_credit_used'] ?? 'NOT SET') . ", phone=" . ($data['phone'] ?? '?'));
    
    // Check IP & phone blocks
    if (isIPBlocked($ip)) {
        return ['success' => false, 'message' => 'Your access has been restricted. Please contact support.'];
    }
    if (isPhoneBlocked($data['phone'])) {
        return ['success' => false, 'message' => 'This phone number has been blocked. Please contact support.'];
    }
    
    // Fraud check
    $riskScore = calculateRiskScore($data, $ip);
    if ($riskScore >= 80) {
        logFraud(null, $ip, $data['phone'], 'high_risk', $riskScore, 'Auto-blocked: risk score ' . $riskScore);
        return ['success' => false, 'message' => 'Order could not be placed. Please contact support.'];
    }
    
    // Find or create customer
    // CRITICAL: If user is logged in, ALWAYS use their account ID for the order
    // This handles gift orders where phone/name/address may differ from the account
    $loggedInCustId = (isCustomerLoggedIn() && getCustomerId() > 0) ? getCustomerId() : 0;
    
    $customer = $db->fetch("SELECT * FROM customers WHERE phone = ?", [$data['phone']]);
    if ($customer) {
        $customerId = $customer['id'];
        $db->update('customers', [
            'name' => $data['name'],
            'address' => $data['address'],
            'city' => $data['city'] ?? '',
            'district' => $data['district'] ?? '',
            'total_orders' => $customer['total_orders'] + 1,
        ], 'id = ?', [$customerId]);
    } else {
        $customerId = $db->insert('customers', [
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? '',
            'address' => $data['address'],
            'city' => $data['city'] ?? '',
            'district' => $data['district'] ?? '',
            'ip_address' => $ip,
            'total_orders' => 1,
        ]);
    }
    
    // If logged in, always link order to logged-in customer (even if checkout phone differs)
    // This ensures the order shows in their account and credits work correctly
    if ($loggedInCustId > 0) {
        $customerId = $loggedInCustId;
        // Also increment total_orders on the logged-in customer if different from phone-matched
        if ($loggedInCustId !== ($customer['id'] ?? 0)) {
            $db->query("UPDATE customers SET total_orders = total_orders + 1 WHERE id = ?", [$loggedInCustId]);
        }
    }
    
    $cart = getCart();
    if (empty($cart)) {
        return ['success' => false, 'message' => 'Cart is empty.'];
    }
    
    $subtotal = getCartTotal();
    $shippingArea = $data['shipping_area'] ?? 'outside_dhaka';
    if ($shippingArea === 'inside_dhaka') {
        $shippingCost = getSetting('shipping_inside_dhaka', 70);
    } elseif ($shippingArea === 'dhaka_sub') {
        $shippingCost = getSetting('shipping_dhaka_sub', 100);
    } else {
        $shippingCost = getSetting('shipping_outside_dhaka', 130);
    }
    
    $freeShippingMin = getSetting('free_shipping_minimum', 5000);
    if ($subtotal >= $freeShippingMin) {
        $shippingCost = 0;
    }
    
    $discount = 0;
    $couponCode = trim($data['coupon_code'] ?? '');
    $freeShippingCoupon = false;
    
    // Validate and apply coupon
    if (!empty($couponCode)) {
        $coupon = $db->fetch("SELECT * FROM coupons WHERE code = ? AND is_active = 1", [strtoupper($couponCode)]);
        if ($coupon) {
            $now = date('Y-m-d H:i:s');
            $valid = true;
            if ($coupon['start_date'] && $now < $coupon['start_date']) $valid = false;
            if ($coupon['end_date'] && $now > $coupon['end_date']) $valid = false;
            if ($coupon['usage_limit'] > 0 && $coupon['used_count'] >= $coupon['usage_limit']) $valid = false;
            if ($coupon['min_order_amount'] > 0 && $subtotal < $coupon['min_order_amount']) $valid = false;
            
            if ($valid) {
                if ($coupon['type'] === 'percentage') {
                    $discount = ($subtotal * $coupon['value']) / 100;
                    if ($coupon['max_discount'] > 0 && $discount > $coupon['max_discount']) {
                        $discount = $coupon['max_discount'];
                    }
                } elseif ($coupon['type'] === 'fixed') {
                    $discount = min($coupon['value'], $subtotal);
                } elseif ($coupon['type'] === 'free_shipping') {
                    $freeShippingCoupon = true;
                    $shippingCost = 0;
                }
                $couponCode = $coupon['code'];
                // Increment usage
                $db->query("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?", [$coupon['id']]);
            } else {
                $couponCode = ''; // Invalid coupon, ignore
            }
        } else {
            $couponCode = '';
        }
    }
    
    $total = $subtotal + $shippingCost - $discount;
    
    // ── PROGRESS BAR REWARDS (server-side validation) ──
    $pbDiscount = 0;
    $requestedPbDiscount = floatval($data['progress_bar_discount'] ?? 0);
    try {
        $activeBar = $db->fetch("SELECT * FROM checkout_progress_bars WHERE is_active = 1 LIMIT 1");
        if ($activeBar) {
            $tiers = json_decode($activeBar['tiers'] ?? '[]', true) ?: [];
            $serverDiscount = 0;
            foreach ($tiers as $t) {
                if ($subtotal >= floatval($t['min_amount'] ?? 0)) {
                    if (($t['reward_type'] ?? '') === 'free_shipping') {
                        $shippingCost = 0;
                    } elseif (($t['reward_type'] ?? '') === 'discount_fixed') {
                        $serverDiscount += floatval($t['reward_value'] ?? 0);
                    } elseif (($t['reward_type'] ?? '') === 'discount_percent') {
                        $serverDiscount += round($subtotal * floatval($t['reward_value'] ?? 0) / 100);
                    }
                }
            }
            $pbDiscount = min($serverDiscount, $subtotal);
        }
    } catch (\Throwable $e) { $pbDiscount = 0; }
    
    $discount += $pbDiscount;
    $total = $subtotal + $shippingCost - $discount;
    
    // ── STORE CREDIT (credit → TK conversion) ──
    // Entire block wrapped in try-catch so credit issues never prevent order placement
    $storeCreditUsed = 0;
    $creditsDeducted = 0;
    $requestedCreditTk = floatval($data['store_credit_used'] ?? 0);
    $creditCustomerId = $loggedInCustId > 0 ? $loggedInCustId : $customerId;
    
    // Auto-migrate: ensure all credit tables/columns exist (runs once per request)
    static $creditMigrated = false;
    if (!$creditMigrated) {
        $creditMigrated = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS store_credit_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                type ENUM('earn','spend','refund','admin_adjust','expire') NOT NULL,
                reference_type VARCHAR(30) DEFAULT NULL,
                reference_id INT DEFAULT NULL,
                description VARCHAR(255) DEFAULT NULL,
                balance_after DECIMAL(12,2) DEFAULT 0,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_customer (customer_id),
                INDEX idx_type (type),
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {}
        try {
            $db->query("ALTER TABLE customers ADD COLUMN IF NOT EXISTS store_credit DECIMAL(12,2) DEFAULT 0");
        } catch (\Throwable $e) {}
        try {
            $db->fetch("SELECT store_credit_used FROM orders LIMIT 0");
        } catch (\Throwable $e) {
            try { $db->query("ALTER TABLE orders ADD COLUMN store_credit_used DECIMAL(12,2) DEFAULT 0 AFTER discount_amount"); } catch (\Throwable $e2) {}
        }
    }
    
    try {
        $phoneCustomerId = is_array($customer) ? intval($customer['id']) : 0;
        _creditLog("CREDIT: requestedTk={$requestedCreditTk}, creditCustId={$creditCustomerId}, loggedIn={$loggedInCustId}, phoneCust={$phoneCustomerId}");
        
        $creditsEnabled = getSetting('store_credits_enabled', '1');
        $creditCheckoutEnabled = getSetting('store_credit_checkout', '1');
        _creditLog("CREDIT: enabled_setting='{$creditsEnabled}', checkout_setting='{$creditCheckoutEnabled}'");
        
        $creditsOk = ($creditsEnabled === '1' || $creditsEnabled === 'true' || $creditsEnabled === 'on');
        $checkoutOk = ($creditCheckoutEnabled === '1' || $creditCheckoutEnabled === 'true' || $creditCheckoutEnabled === 'on');
        
        if ($requestedCreditTk > 0 && $creditCustomerId > 0 && $creditsOk && $checkoutOk) {
            $creditRate = floatval(getSetting('store_credit_conversion_rate', '0.75'));
            if ($creditRate <= 0) $creditRate = 0.75;
            
            // Get balance from column
            $columnBalance = 0;
            $custRow = $db->fetch("SELECT store_credit FROM customers WHERE id = ?", [$creditCustomerId]);
            if ($custRow) $columnBalance = floatval($custRow['store_credit'] ?? 0);
            
            // Get balance from transactions (backup)
            $txnBalance = 0;
            try {
                $txnRow = $db->fetch("SELECT COALESCE(SUM(amount), 0) as bal FROM store_credit_transactions WHERE customer_id = ?", [$creditCustomerId]);
                if ($txnRow) $txnBalance = max(0, floatval($txnRow['bal']));
            } catch (\Throwable $txnErr) {
                _creditLog("CREDIT: txn query failed: " . $txnErr->getMessage());
            }
            
            // Use higher balance (covers sync issues)
            $availableCredits = max($columnBalance, $txnBalance);
            _creditLog("CREDIT: colBal={$columnBalance}, txnBal={$txnBalance}, available={$availableCredits}, rate={$creditRate}");
            
            // Sync column if mismatched
            if ($txnBalance > 0 && abs($columnBalance - $txnBalance) > 0.01) {
                try { $db->query("UPDATE customers SET store_credit = ? WHERE id = ?", [$txnBalance, $creditCustomerId]); } catch (\Throwable $e) {}
            }
            
            if ($availableCredits >= 1) {
                $availableTk = round($availableCredits * $creditRate, 2);
                
                $storeCreditUsed = min($requestedCreditTk, $availableTk, $total);
                $storeCreditUsed = max(0, round($storeCreditUsed, 2));
                
                $creditsDeducted = $creditRate > 0 ? round($storeCreditUsed / $creditRate, 2) : 0;
                $creditsDeducted = min($creditsDeducted, $availableCredits);
                
                if ($storeCreditUsed > 0) {
                    $total = $total - $storeCreditUsed;
                    $customerId = $creditCustomerId;
                    _creditLog("CREDIT: APPLIED! tk={$storeCreditUsed}, credits={$creditsDeducted}, newTotal={$total}");
                }
            } else {
                _creditLog("CREDIT: SKIPPED insufficient credits ({$availableCredits})");
            }
        } else {
            if ($requestedCreditTk > 0) {
                _creditLog("CREDIT: SKIPPED condition: requested={$requestedCreditTk}, custId={$creditCustomerId}, enabled={$creditsOk}, checkout={$checkoutOk}");
            }
        }
    } catch (\Throwable $creditError) {
        // Credit processing failed - log but continue with order (no credit applied)
        _creditLog("CREDIT FATAL ERROR: " . $creditError->getMessage() . " in " . $creditError->getFile() . ":" . $creditError->getLine());
        $storeCreditUsed = 0;
        $creditsDeducted = 0;
    }
    
    // ── ORDER MERGING: Check for existing pending order with same phone + address ──
    $mergeEnabled = getSetting('order_merge_enabled', '1') === '1';
    $existingOrder = null;
    $isMerged = false;
    $newTotal = $total; // Default for non-merged path
    
    if ($mergeEnabled) {
        $cleanPhone = sanitize($data['phone']);
        $cleanAddress = trim(sanitize($data['address']));
        
        // Find most recent processing order (not confirmed yet) with same phone AND address
        $existingOrder = $db->fetch(
            "SELECT * FROM orders WHERE customer_phone = ? AND order_status IN ('processing','pending') 
             AND TRIM(customer_address) = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY id DESC LIMIT 1",
            [$cleanPhone, $cleanAddress]
        );
    }
    
    if ($existingOrder) {
        // ── MERGE into existing order ──
        $orderId = $existingOrder['id'];
        $orderNumber = $existingOrder['order_number'];
        $isMerged = true;
        
        // Calculate new totals (add current cart to existing)
        $newSubtotal = floatval($existingOrder['subtotal']) + $subtotal;
        
        // Recalculate shipping on merged total
        $mergedShipping = $shippingCost;
        if ($newSubtotal >= $freeShippingMin) {
            $mergedShipping = 0;
        }
        
        $newDiscount = floatval($existingOrder['discount_amount']) + $discount;
        $newTotal = $newSubtotal + $mergedShipping - $newDiscount - $storeCreditUsed;
        
        // Append notes
        $existingNotes = $existingOrder['notes'] ?? '';
        $newNotes = sanitize($data['notes'] ?? '');
        $mergedNotes = $existingNotes;
        if ($newNotes && $newNotes !== $existingNotes) {
            $mergedNotes = trim($existingNotes . ($existingNotes ? ' | ' : '') . $newNotes);
        }
        
        // Update existing order totals
        $mergeUpdate = [
            'subtotal' => $newSubtotal,
            'shipping_cost' => $mergedShipping,
            'discount_amount' => $newDiscount,
            'coupon_code' => $couponCode ?: $existingOrder['coupon_code'],
            'total' => $newTotal,
            'notes' => $mergedNotes,
        ];
        if ($storeCreditUsed > 0) {
            $mergeUpdate['store_credit_used'] = $storeCreditUsed + floatval($existingOrder['store_credit_used'] ?? 0);
        }
        $db->update('orders', $mergeUpdate, 'id = ?', [$orderId]);
        
        // Log merge in status history
        $db->insert('order_status_history', [
            'order_id' => $orderId,
            'status' => 'processing',
            'note' => 'Items merged from new order (subtotal +৳' . number_format($subtotal) . ')',
        ]);
        
    } else {
        // ── CREATE NEW ORDER ──
        $orderNumber = generateOrderNumber();
    
    $orderData = [
        'order_number' => $orderNumber,
        'customer_id' => $customerId,
        'customer_name' => sanitize($data['name']),
        'customer_phone' => sanitize($data['phone']),
        'customer_email' => sanitize($data['email'] ?? ''),
        'customer_address' => sanitize($data['address']),
        'customer_city' => sanitize($data['city'] ?? ''),
        'customer_district' => sanitize($data['district'] ?? ''),
        'order_status' => 'processing',
        'channel' => $data['channel'] ?? 'website',
        'subtotal' => $subtotal,
        'shipping_cost' => $shippingCost,
        'discount_amount' => $discount,
        'store_credit_used' => $storeCreditUsed,
        'coupon_code' => $couponCode,
        'total' => $total,
        'payment_method' => $data['payment_method'] ?? 'cod',
        'notes' => sanitize($data['notes'] ?? ''),
        'ip_address' => $ip,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ];
    
    // Add store_credit_used safely (remove if column check fails somehow)
    try { $db->query("SELECT store_credit_used FROM orders LIMIT 0"); } catch (\Throwable $e) { unset($orderData['store_credit_used']); }
    
    // Try adding tracking columns (safe if they don't exist yet)
    try {
        $db->query("SELECT visitor_id FROM orders LIMIT 0");
        $orderData['visitor_id'] = $_SESSION['visitor_id'] ?? null;
        $orderData['network_ip'] = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $ip;
        $orderData['device_type'] = detectDeviceFromUA($_SERVER['HTTP_USER_AGENT'] ?? '');
    } catch (Exception $e) {}
    
    $orderId = $db->insert('orders', $orderData);
    } // end else (new order)
    
    // Insert order items (works for both new and merged orders)
    foreach ($cart as $cartKey => $item) {
        try {
            if (!empty($item['is_bundle']) && !empty($item['bundle_items'])) {
                // Bundle: create individual order_items for each component
                $bundleLabel = '[Bundle] ' . mb_substr($item['name'] ?? 'Bundle', 0, 80);
                foreach ($item['bundle_items'] as $bi) {
                    $biQty = max(1, intval($bi['qty'] ?? 1)) * max(1, intval($item['quantity'] ?? 1));
                    $biName = !empty($bi['name']) ? mb_substr($bi['name'], 0, 250) : 'Bundle Item';
                    $biPrice = floatval($bi['price'] ?? 0);
                    $biProductId = intval($bi['product_id'] ?? 0);
                    
                    // Verify product exists to avoid FK constraint failure
                    if ($biProductId > 0) {
                        $exists = $db->fetch("SELECT id FROM products WHERE id = ?", [$biProductId]);
                        if (!$exists) $biProductId = null;
                    } else {
                        $biProductId = null;
                    }
                    
                    $db->insert('order_items', [
                        'order_id' => $orderId,
                        'product_id' => $biProductId,
                        'product_name' => $biName,
                        'variant_name' => mb_substr($bundleLabel, 0, 95),
                        'quantity' => $biQty,
                        'price' => $biPrice,
                        'subtotal' => round($biPrice * $biQty, 2),
                    ]);
                    
                    if ($biProductId) {
                        try {
                            // Only update sales_count on placement. Stock reduced on confirm.
                            $db->query("UPDATE products SET sales_count = sales_count + ? WHERE id = ?", [$biQty, $biProductId]);
                        } catch (\Throwable $stockErr) {}
                    }
                }
            } else {
                $itemProductId = intval($item['product_id'] ?? 0);
                $itemName = !empty($item['name']) ? mb_substr($item['name'], 0, 250) : 'Product';
                $itemVariant = isset($item['variant_name']) && $item['variant_name'] 
                    ? mb_substr($item['variant_name'], 0, 95) : null;
                $itemQty = max(1, intval($item['quantity'] ?? 1));
                $itemPrice = floatval($item['price'] ?? 0);
                
                $db->insert('order_items', [
                    'order_id' => $orderId,
                    'product_id' => $itemProductId ?: null,
                    'product_name' => $itemName,
                    'variant_name' => $itemVariant,
                    'quantity' => $itemQty,
                    'price' => $itemPrice,
                    'subtotal' => round($itemPrice * $itemQty, 2),
                ]);
                
                if ($itemProductId) {
                    try {
                        // Only update sales_count on order placement. Stock is reduced when order is CONFIRMED.
                        $db->query("UPDATE products SET sales_count = sales_count + ? WHERE id = ?", [$itemQty, $itemProductId]);
                    } catch (\Throwable $stockErr) {}
                }
            }
        } catch (\Throwable $itemErr) {
            // Log but don't stop — order row is already created
            error_log("ORDER ITEM INSERT ERROR (key={$cartKey}): " . $itemErr->getMessage());
        }
    }
    
    if (!$isMerged) {
        // Log status (only for new orders - merged orders already have their status entry)
        $db->insert('order_status_history', [
            'order_id' => $orderId,
            'status' => 'processing',
            'note' => 'Order placed via website',
        ]);
        
        // Accounting entry
        $db->insert('accounting_entries', [
            'entry_type' => 'income',
            'amount' => $total,
            'reference_type' => 'order',
            'reference_id' => $orderId,
            'description' => "Order #{$orderNumber}",
            'entry_date' => date('Y-m-d'),
        ]);
        
        // Create notification
        $db->insert('notifications', [
            'type' => 'new_order',
            'title' => 'New Order #' . $orderNumber,
            'message' => "New order from {$data['name']} - Total: " . formatPrice($total),
            'link' => "orders/view/{$orderId}",
        ]);
    } else {
        // For merged orders, update accounting entry
        try {
            $existingAccounting = $db->fetch("SELECT id FROM accounting_entries WHERE reference_type='order' AND reference_id=?", [$orderId]);
            if ($existingAccounting) {
                $mergedTotal = floatval($existingOrder['subtotal']) + $subtotal + $shippingCost - (floatval($existingOrder['discount_amount']) + $discount);
                $db->update('accounting_entries', ['amount' => $mergedTotal], 'id = ?', [$existingAccounting['id']]);
            }
        } catch (\Throwable $e) {}
        
        // Notification for merge
        $db->insert('notifications', [
            'type' => 'order_merged',
            'title' => 'Order Merged #' . $orderNumber,
            'message' => "Items added to existing order from {$data['name']} (+৳" . number_format($subtotal) . ")",
            'link' => "orders/view/{$orderId}",
        ]);
    }
    
    clearCart();
    
    // Deduct store credits if used
    if ($storeCreditUsed > 0 && $creditsDeducted > 0 && $customerId) {
        try {
            $newBal = addStoreCredit(
                $customerId,
                -$creditsDeducted,
                'spend',
                'order',
                $orderId,
                "Order #{$orderNumber} — {$creditsDeducted} credits = ৳{$storeCreditUsed}"
            );
            _creditLog("CREDIT DEDUCTED: custId={$customerId}, credits=-{$creditsDeducted}, tk={$storeCreditUsed}, newBal={$newBal}, orderId={$orderId}");
        } catch (\Throwable $e) {
            _creditLog("CREDIT DEDUCTION FAILED: " . $e->getMessage() . " | custId={$customerId}, credits={$creditsDeducted}");
        }
    } else if ($requestedCreditTk > 0) {
        _creditLog("CREDIT NOT DEDUCTED: storeCreditUsed={$storeCreditUsed}, creditsDeducted={$creditsDeducted}, customerId={$customerId}");
    }
    
    // Update visitor tracking (Feature #8)
    try {
        if (!empty($_SESSION['visitor_id'])) {
            $db->update('visitor_logs', ['order_placed' => 1, 'order_id' => $orderId], 'id = ?', [$_SESSION['visitor_id']]);
        }
    } catch (Exception $e) {}
    
    // Recover incomplete orders (Feature #1) — handle both column names
    try {
        $sessionId = session_id();
        $db->query("UPDATE incomplete_orders SET recovered = 1, recovered_order_id = ? WHERE (session_id = ? OR customer_phone = ?) AND recovered = 0", 
            [$orderId, $sessionId, $data['phone']]);
    } catch (\Exception $e) {
        try {
            $sessionId = session_id();
            $db->query("UPDATE incomplete_orders SET is_recovered = 1, recovered_order_id = ? WHERE (session_id = ? OR customer_phone = ?) AND is_recovered = 0",
                [$orderId, $sessionId, $data['phone']]);
        } catch (\Exception $e2) {}
    }
    
    // Auto-save billing address for logged-in users (new address detection)
    if ($loggedInCustId > 0) {
        try {
            $orderAddress = trim(sanitize($data['address']));
            $orderPhone = sanitize($data['phone']);
            $orderName = sanitize($data['name']);
            $existingAddr = $db->fetch(
                "SELECT id FROM customer_addresses WHERE customer_id = ? AND (TRIM(address) = ? OR phone = ?)",
                [$loggedInCustId, $orderAddress, $orderPhone]
            );
            if (!$existingAddr && !empty($orderAddress)) {
                $db->insert('customer_addresses', [
                    'customer_id' => $loggedInCustId,
                    'label' => 'Order #' . $orderNumber,
                    'name' => $orderName,
                    'phone' => $orderPhone,
                    'address' => $orderAddress,
                    'city' => sanitize($data['city'] ?? ''),
                    'area' => sanitize($data['district'] ?? ''),
                    'is_default' => 0,
                ]);
            }
        } catch (\Throwable $e) {}
    }
    
    _creditLog("ORDER COMPLETE: #{$orderNumber}, total={$total}, creditUsed={$storeCreditUsed}, creditsDeducted={$creditsDeducted}, merged=" . ($isMerged ? 'yes' : 'no'));
    
    // ── Fire Facebook CAPI Purchase Event ──
    try {
        if (file_exists(__DIR__ . '/fb-capi.php')) {
            require_once __DIR__ . '/fb-capi.php';
            if (fbCapiEnabled()) {
                $__fbItems = $db->fetchAll("SELECT oi.product_id, oi.product_name, oi.quantity, oi.price FROM order_items oi WHERE oi.order_id = ?", [$orderId]);
                $__fbEventId = fbEventId();
                fbTrackPurchase([
                    'order_number' => $orderNumber,
                    'total'        => $isMerged ? $newTotal : $total,
                    'items'        => $__fbItems,
                    'email'        => $data['email'] ?? '',
                    'phone'        => $data['phone'] ?? '',
                    'name'         => $data['name'] ?? '',
                    'city'         => $data['city'] ?? '',
                    'district'     => $data['district'] ?? '',
                ], $__fbEventId);
            }
        }
    } catch (\Throwable $e) {
        // Never break order flow for tracking
        error_log('FB CAPI Purchase error: ' . $e->getMessage());
    }
    
    return [
        'success' => true,
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'total' => $isMerged ? $newTotal : $total,
        'merged' => $isMerged,
        'credit_used_tk' => $storeCreditUsed,
        'credits_deducted' => $creditsDeducted,
        'fb_event_id' => $__fbEventId ?? null,
        'message' => $isMerged 
            ? "আপনার পণ্যগুলো পূর্ববর্তী অর্ডার #{$orderNumber}-এ যোগ হয়েছে!" 
            : 'Order placed successfully!',
    ];
}

function detectDeviceFromUA($ua) {
    if (preg_match('/bot|crawl|spider/i', $ua)) return 'bot';
    if (preg_match('/tablet|ipad|playbook/i', $ua)) return 'tablet';
    if (preg_match('/mobile|iphone|android.*mobile|opera m|mobi/i', $ua)) return 'mobile';
    return 'desktop';
}

function calculateRiskScore($data, $ip) {
    $score = 0;
    $db = Database::getInstance();
    
    // Check cancelled orders from same phone
    $cancelledCount = $db->count('orders', "customer_phone = ? AND order_status IN ('cancelled','returned')", [$data['phone']]);
    $score += $cancelledCount * 15;
    
    // Check orders from same IP in last hour
    $recentIPOrders = $db->count('orders', "ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$ip]);
    $score += $recentIPOrders * 20;
    
    // Check if customer is already flagged
    $customer = $db->fetch("SELECT risk_score FROM customers WHERE phone = ?", [$data['phone']]);
    if ($customer) $score += $customer['risk_score'];
    
    return min($score, 100);
}

function logFraud($orderId, $ip, $phone, $type, $score, $details) {
    $db = Database::getInstance();
    $db->insert('fraud_logs', [
        'order_id' => $orderId,
        'ip_address' => $ip,
        'phone' => $phone,
        'fraud_type' => $type,
        'risk_score' => $score,
        'details' => $details,
        'action_taken' => $score >= 80 ? 'blocked' : 'flagged',
    ]);
}

// ============================================================
// PAGINATION HELPER
// ============================================================

function paginate($totalItems, $currentPage, $perPage, $baseUrl) {
    $totalPages = ceil($totalItems / $perPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    
    return [
        'total_items' => $totalItems,
        'total_pages' => $totalPages,
        'current_page' => $currentPage,
        'per_page' => $perPage,
        'offset' => ($currentPage - 1) * $perPage,
        'base_url' => $baseUrl,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages,
    ];
}

function renderPagination($pagination) {
    if ($pagination['total_pages'] <= 1) return '';
    
    $html = '<nav class="flex justify-center mt-8"><ul class="flex space-x-1">';
    
    if ($pagination['has_prev']) {
        $html .= '<li><a href="' . $pagination['base_url'] . '?page=' . ($pagination['current_page'] - 1) . '" class="px-3 py-2 rounded border hover:bg-gray-100">&laquo;</a></li>';
    }
    
    for ($i = max(1, $pagination['current_page'] - 2); $i <= min($pagination['total_pages'], $pagination['current_page'] + 2); $i++) {
        $active = $i === $pagination['current_page'] ? 'bg-red-500 text-white' : 'border hover:bg-gray-100';
        $html .= '<li><a href="' . $pagination['base_url'] . '?page=' . $i . '" class="px-3 py-2 rounded ' . $active . '">' . $i . '</a></li>';
    }
    
    if ($pagination['has_next']) {
        $html .= '<li><a href="' . $pagination['base_url'] . '?page=' . ($pagination['current_page'] + 1) . '" class="px-3 py-2 rounded border hover:bg-gray-100">&raquo;</a></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}

// ============================================================
// FLASH MESSAGES
// ============================================================

function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function renderFlash() {
    $flash = getFlash();
    if (!$flash) return '';
    
    $colors = [
        'success' => 'bg-green-100 text-green-800 border-green-300',
        'error' => 'bg-red-100 text-red-800 border-red-300',
        'warning' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
        'info' => 'bg-blue-100 text-blue-800 border-blue-300',
    ];
    
    $color = $colors[$flash['type']] ?? $colors['info'];
    return '<div class="border rounded-lg p-4 mb-4 ' . $color . '">' . $flash['message'] . '</div>';
}

// ============================================================
// ACTIVITY LOGGING
// ============================================================

function logActivity($userId, $action, $entityType = null, $entityId = null, $oldValues = null, $newValues = null) {
    $db = Database::getInstance();
    $db->insert('activity_logs', [
        'admin_user_id' => $userId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'old_values' => $oldValues ? json_encode($oldValues) : null,
        'new_values' => $newValues ? json_encode($newValues) : null,
        'ip_address' => getClientIP(),
    ]);
}

// ========== CUSTOMER AUTHENTICATION ==========

function customerLogin($phone, $password) {
    $db = Database::getInstance();
    $customer = $db->fetch("SELECT * FROM customers WHERE phone = ? AND is_blocked = 0", [$phone]);
    if ($customer && $customer['password'] && verifyPassword($password, $customer['password'])) {
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['customer_id'] = $customer['id'];
        $_SESSION['customer_name'] = $customer['name'];
        $_SESSION['customer_phone'] = $customer['phone'];
        return $customer;
    }
    return false;
}

function customerRegister($data) {
    $db = Database::getInstance();
    $existing = $db->fetch("SELECT id, password FROM customers WHERE phone = ?", [$data['phone']]);
    if ($existing && $existing['password']) {
        return ['error' => 'এই ফোন নম্বর দিয়ে আগেই একাউন্ট আছে।'];
    }
    $hash = hashPassword($data['password']);
    
    // Build insert/update data with dynamic extra fields
    $saveData = [
        'name' => $data['name'],
        'email' => $data['email'] ?? null,
        'password' => $hash,
    ];
    $extraCols = ['address', 'city', 'district', 'alt_phone'];
    foreach ($extraCols as $col) {
        if (isset($data[$col]) && $data[$col] !== '') {
            $saveData[$col] = $data[$col];
        }
    }
    
    if ($existing) {
        $db->update('customers', $saveData, 'id = ?', [$existing['id']]);
        $id = $existing['id'];
    } else {
        $saveData['phone'] = $data['phone'];
        $saveData['ip_address'] = getClientIP();
        $id = $db->insert('customers', $saveData);
    }
    $_SESSION['customer_id'] = $id;
    $_SESSION['customer_name'] = $data['name'];
    $_SESSION['customer_phone'] = $data['phone'];
    return ['success' => true, 'id' => $id];
}

function customerLogout() {
    unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_phone']);
}

function isCustomerLoggedIn() {
    return isset($_SESSION['customer_id']);
}

function getCustomerId() {
    return $_SESSION['customer_id'] ?? 0;
}

function getCustomerName() {
    return $_SESSION['customer_name'] ?? '';
}

function getCustomer() {
    if (!isCustomerLoggedIn()) return null;
    $db = Database::getInstance();
    return $db->fetch("SELECT * FROM customers WHERE id = ?", [getCustomerId()]);
}

function requireCustomer() {
    if (!isCustomerLoggedIn()) {
        redirect(url('login?redirect=' . urlencode(currentUrl())));
    }
}

function isInWishlist($productId) {
    if (!isCustomerLoggedIn()) return false;
    $db = Database::getInstance();
    return (bool)$db->fetch("SELECT id FROM wishlists WHERE customer_id = ? AND product_id = ?", [getCustomerId(), $productId]);
}

function getWishlistCount() {
    if (!isCustomerLoggedIn()) return 0;
    $db = Database::getInstance();
    return $db->count('wishlists', 'customer_id = ?', [getCustomerId()]);
}

/**
 * Safe image URL - handles both "filename.jpg" and "folder/filename.jpg" formats
 * Always returns correct URL regardless of what's stored in DB
 */
function bannerImg($storedValue) {
    if (empty($storedValue)) return '';
    // If stored value already contains folder path, use it directly
    if (strpos($storedValue, '/') !== false) {
        // Could be "banners/file.jpg" - check if first segment is a known folder
        $parts = explode('/', $storedValue, 2);
        $knownFolders = ['products','banners','logos','categories','general','avatars','expenses'];
        if (in_array($parts[0], $knownFolders)) {
            return UPLOAD_URL . '/' . $storedValue;
        }
    }
    // Just a filename - prepend banners/
    return UPLOAD_URL . '/banners/' . $storedValue;
}

// ═══════════════════════════════════════════
// STORE CREDIT FUNCTIONS
// ═══════════════════════════════════════════

function getStoreCredit($customerId) {
    $db = Database::getInstance();
    $row = $db->fetch("SELECT store_credit FROM customers WHERE id = ?", [$customerId]);
    return floatval($row['store_credit'] ?? 0);
}

function addStoreCredit($customerId, $amount, $type, $refType = null, $refId = null, $description = '', $createdBy = null) {
    if ($amount == 0) return false;
    $db = Database::getInstance();
    
    // Update balance
    if ($amount > 0) {
        $db->query("UPDATE customers SET store_credit = store_credit + ? WHERE id = ?", [abs($amount), $customerId]);
    } else {
        $db->query("UPDATE customers SET store_credit = GREATEST(0, store_credit - ?) WHERE id = ?", [abs($amount), $customerId]);
    }
    
    $newBalance = getStoreCredit($customerId);
    
    $db->insert('store_credit_transactions', [
        'customer_id' => $customerId,
        'amount' => $amount,
        'type' => $type,
        'reference_type' => $refType,
        'reference_id' => $refId,
        'description' => $description,
        'balance_after' => $newBalance,
        'created_by' => $createdBy,
    ]);
    
    return $newBalance;
}

function getCreditTransactions($customerId, $limit = 20) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT * FROM store_credit_transactions WHERE customer_id = ? ORDER BY created_at DESC LIMIT ?",
        [$customerId, $limit]
    );
}

/**
 * Award store credits for a delivered order
 * Called when order status changes to 'delivered'
 */
function awardOrderCredits($orderId) {
    $db = Database::getInstance();
    
    // Check if store credits system is enabled
    if (getSetting('store_credits_enabled', '1') !== '1') return;
    
    $order = $db->fetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
    if (!$order || !$order['customer_id']) return;
    
    // Check if credits already awarded for this order
    $existing = $db->fetch(
        "SELECT id FROM store_credit_transactions WHERE reference_type='order' AND reference_id=? AND type='earn'",
        [$orderId]
    );
    if ($existing) return;
    
    // Check if customer exists
    $customer = $db->fetch("SELECT id FROM customers WHERE id = ?", [$order['customer_id']]);
    if (!$customer) return;
    
    // Calculate credits from order items
    $items = $db->fetchAll("SELECT oi.*, p.store_credit_enabled, p.store_credit_amount FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?", [$orderId]);
    $totalCredits = 0;
    foreach ($items as $item) {
        if ($item['store_credit_enabled'] && $item['store_credit_amount'] > 0) {
            $totalCredits += floatval($item['store_credit_amount']) * intval($item['quantity']);
        }
    }
    
    if ($totalCredits > 0) {
        addStoreCredit(
            $order['customer_id'],
            $totalCredits,
            'earn',
            'order',
            $orderId,
            "Order #{$order['order_number']} delivery credit"
        );
    }
}

/**
 * Process store credit refund
 */
function refundToStoreCredit($returnId, $amount, $adminId = null) {

/**
 * Refund store credits when an order is cancelled
 * Reverses any 'spend' credits used on this order
 */
function refundOrderCreditsOnCancel($orderId) {
    $db = Database::getInstance();
    
    if (getSetting('store_credits_enabled', '1') !== '1') return;
    
    $order = $db->fetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
    if (!$order || !$order['customer_id']) return;
    
    // Check if credits were spent on this order
    $spendTx = $db->fetch(
        "SELECT id, amount FROM store_credit_transactions WHERE reference_type='order' AND reference_id=? AND type='spend'",
        [$orderId]
    );
    if (!$spendTx) return;
    
    // Check if already refunded for cancel
    $alreadyRefunded = $db->fetch(
        "SELECT id FROM store_credit_transactions WHERE reference_type='order_cancel' AND reference_id=? AND type='refund'",
        [$orderId]
    );
    if ($alreadyRefunded) return;
    
    $creditsToRefund = abs(floatval($spendTx['amount']));
    if ($creditsToRefund <= 0) return;
    
    $creditRate = floatval(getSetting('store_credit_conversion_rate', '0.75'));
    if ($creditRate <= 0) $creditRate = 0.75;
    $tkValue = round($creditsToRefund * $creditRate, 2);
    
    addStoreCredit(
        $order['customer_id'],
        $creditsToRefund,
        'refund',
        'order_cancel',
        $orderId,
        "Order #{$order['order_number']} cancelled — {$creditsToRefund} credits (৳{$tkValue}) refunded"
    );
    
    // Also reverse earned credits if they were awarded (order was delivered then cancelled)
    $earnTx = $db->fetch(
        "SELECT id, amount FROM store_credit_transactions WHERE reference_type='order' AND reference_id=? AND type='earn'",
        [$orderId]
    );
    if ($earnTx && floatval($earnTx['amount']) > 0) {
        $alreadyClawed = $db->fetch(
            "SELECT id FROM store_credit_transactions WHERE reference_type='order_cancel_earn' AND reference_id=? AND type='admin_adjust'",
            [$orderId]
        );
        if (!$alreadyClawed) {
            addStoreCredit(
                $order['customer_id'],
                -floatval($earnTx['amount']),
                'admin_adjust',
                'order_cancel_earn',
                $orderId,
                "Order #{$order['order_number']} cancelled — earned credits reversed"
            );
        }
    }
}
    $db = Database::getInstance();
    $return = $db->fetch("SELECT r.*, o.customer_id, o.order_number, o.customer_phone FROM return_orders r JOIN orders o ON o.id = r.order_id WHERE r.id = ?", [$returnId]);
    if (!$return || !$return['customer_id']) return false;
    
    // Check customer is registered
    $customer = $db->fetch("SELECT id, password FROM customers WHERE id = ?", [$return['customer_id']]);
    if (!$customer || empty($customer['password'])) return false;
    
    addStoreCredit(
        $return['customer_id'],
        $amount,
        'refund',
        'return',
        $returnId,
        "Refund for order #{$return['order_number']}",
        $adminId
    );
    
    return true;
}

// ============================================================
// VARIATION SPLIT / MERGE SYSTEM
// ============================================================

/**
 * Ensure parent_product_id and variant_label columns exist in products table.
 */
function ensureVariationSplitColumns() {
    $db = Database::getInstance();
    $cols = ['parent_product_id INT DEFAULT NULL', 'variant_label VARCHAR(100) DEFAULT NULL'];
    foreach ($cols as $colDef) {
        try { $db->query("ALTER TABLE products ADD COLUMN {$colDef}"); } catch (\Throwable $e) {}
    }
    try { $db->query("ALTER TABLE products ADD INDEX idx_parent_product (parent_product_id)"); } catch (\Throwable $e) {}
}

/**
 * Check if variation split mode is enabled.
 */
function isVariationSplitMode() {
    return getSetting('variation_display_mode', 'combined') === 'split';
}

/**
 * Split a single product's variations into separate product entries.
 * Returns array of created child product IDs.
 */
function splitProductVariations($productId) {
    $db = Database::getInstance();
    ensureVariationSplitColumns();

    $product = $db->fetch("SELECT * FROM products WHERE id = ?", [$productId]);
    if (!$product) return [];

    // Only split products that have 'variation' type variants
    $variations = $db->fetchAll(
        "SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1 AND option_type = 'variation' ORDER BY sort_order, id",
        [$productId]
    );
    if (empty($variations)) return [];

    // Check if already split
    $existingSplits = intval($db->fetch("SELECT COUNT(*) as cnt FROM products WHERE parent_product_id = ?", [$productId])['cnt'] ?? 0);
    if ($existingSplits > 0) return [];

    $childIds = [];
    $parentSku = $product['sku'] ?: generateProductSKU($productId);
    $parentImages = $db->fetchAll("SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order", [$productId]);
    $skuSeparator = getSetting('sku_separator', '-');

    foreach ($variations as $vi => $var) {
        $varValue = $var['variant_value'];
        $varPrice = floatval($var['absolute_price'] ?? 0);
        if ($varPrice <= 0) $varPrice = floatval($product['regular_price']);

        // Generate unique SKU using configured variant style
        $childSku = generateVariantSKU($parentSku, $varValue, $vi + 1);

        // Generate unique slug
        $baseSlug = $product['slug'] . '-' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $varValue));
        $slug = $baseSlug;
        $slugCheck = $db->fetch("SELECT id FROM products WHERE slug = ?", [$slug]);
        if ($slugCheck) $slug = $baseSlug . '-' . $var['id'];

        // Build child product name
        $childName = $product['name'] . ' - ' . $varValue;
        $childNameBn = $product['name_bn'] ? ($product['name_bn'] . ' - ' . $varValue) : '';

        $childData = [
            'name'                  => $childName,
            'name_bn'               => $childNameBn,
            'slug'                  => $slug,
            'sku'                   => $childSku,
            'barcode'               => null,
            'short_description'     => $product['short_description'] ?? '',
            'description'           => $product['description'] ?? '',
            'regular_price'         => $varPrice,
            'sale_price'            => null,
            'cost_price'            => $product['cost_price'] ?? 0,
            'category_id'           => $product['category_id'],
            'brand'                 => $product['brand'] ?? null,
            'weight'                => $product['weight'] ?? null,
            'weight_unit'           => $product['weight_unit'] ?? 'kg',
            'stock_quantity'        => intval($var['stock_quantity'] ?? 0),
            'low_stock_threshold'   => $product['low_stock_threshold'] ?? 5,
            'stock_status'          => (intval($var['stock_quantity'] ?? 0) > 0) ? 'in_stock' : 'out_of_stock',
            'manage_stock'          => $product['manage_stock'] ?? 1,
            'is_featured'           => 0,
            'is_active'             => 1,
            'sort_order'            => $product['sort_order'] ?? 0,
            'is_on_sale'            => 0,
            'tags'                  => $product['tags'] ?? '',
            'meta_title'            => $childName,
            'meta_description'      => $product['meta_description'] ?? '',
            'featured_image'        => !empty($var['variant_image']) ? $var['variant_image'] : ($product['featured_image'] ?? null),
            'parent_product_id'     => $productId,
            'variant_label'         => $varValue,
            'require_customer_upload'   => $product['require_customer_upload'] ?? 0,
            'customer_upload_label'     => $product['customer_upload_label'] ?? '',
            'customer_upload_required'  => $product['customer_upload_required'] ?? 0,
            'bundle_name'           => '',
            'store_credit_enabled'  => $product['store_credit_enabled'] ?? 0,
            'store_credit_amount'   => $product['store_credit_amount'] ?? 0,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ];

        $childId = $db->insert('products', $childData);
        if (!$childId) continue;

        // Copy parent images to child
        foreach ($parentImages as $img) {
            $db->insert('product_images', [
                'product_id' => $childId,
                'image_path' => $img['image_path'],
                'alt_text'   => $img['alt_text'] ?? null,
                'sort_order' => $img['sort_order'],
                'is_primary' => $img['is_primary'],
            ]);
        }

        // Copy addons to child
        $addons = $db->fetchAll(
            "SELECT * FROM product_variants WHERE product_id = ? AND option_type = 'addon' AND is_active = 1",
            [$productId]
        );
        foreach ($addons as $addon) {
            $addonSku = $childSku . '-' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', substr($addon['variant_value'], 0, 6)));
            $db->insert('product_variants', [
                'product_id'       => $childId,
                'variant_name'     => $addon['variant_name'],
                'variant_value'    => $addon['variant_value'],
                'option_type'      => 'addon',
                'price_adjustment' => $addon['price_adjustment'],
                'absolute_price'   => null,
                'stock_quantity'   => $addon['stock_quantity'],
                'sku'              => $addonSku,
                'is_active'        => 1,
                'is_default'       => $addon['is_default'] ?? 0,
                'sort_order'       => $addon['sort_order'] ?? 0,
            ]);
        }

        $childIds[] = $childId;
    }

    // Hide parent product
    if (!empty($childIds)) {
        $db->update('products', ['is_active' => 0], 'id = ?', [$productId]);
    }

    return $childIds;
}

/**
 * Merge split child products back into parent.
 */
function mergeProductVariations($parentProductId) {
    $db = Database::getInstance();
    ensureVariationSplitColumns();

    $parent = $db->fetch("SELECT * FROM products WHERE id = ?", [$parentProductId]);
    if (!$parent) return false;

    $children = $db->fetchAll("SELECT * FROM products WHERE parent_product_id = ?", [$parentProductId]);
    if (empty($children)) return false;

    // Sync stock from children back to parent variants
    $variations = $db->fetchAll(
        "SELECT * FROM product_variants WHERE product_id = ? AND option_type = 'variation' ORDER BY id",
        [$parentProductId]
    );
    foreach ($variations as $var) {
        foreach ($children as $child) {
            if (($child['variant_label'] ?? '') === $var['variant_value']) {
                $db->query("UPDATE product_variants SET stock_quantity = ? WHERE id = ?", [$child['stock_quantity'], $var['id']]);
                break;
            }
        }
    }

    // Delete children and their data
    foreach ($children as $child) {
        $db->delete('product_images', 'product_id = ?', [$child['id']]);
        $db->delete('product_variants', 'product_id = ?', [$child['id']]);
        try { $db->delete('product_upsells', 'product_id = ?', [$child['id']]); } catch (\Throwable $e) {}
        try { $db->delete('product_bundles', 'product_id = ?', [$child['id']]); } catch (\Throwable $e) {}
        $db->delete('products', 'id = ?', [$child['id']]);
    }

    // Re-activate parent
    $db->update('products', ['is_active' => 1], 'id = ?', [$parentProductId]);
    return true;
}

/**
 * Split ALL eligible products (when toggle ON).
 */
function autoSplitAllProducts() {
    $db = Database::getInstance();
    ensureVariationSplitColumns();

    $products = $db->fetchAll("
        SELECT DISTINCT p.id FROM products p
        INNER JOIN product_variants pv ON pv.product_id = p.id AND pv.option_type = 'variation' AND pv.is_active = 1
        WHERE p.is_active = 1 AND (p.parent_product_id IS NULL OR p.parent_product_id = 0)
    ");

    $totalSplit = 0;
    foreach ($products as $row) {
        $children = splitProductVariations($row['id']);
        $totalSplit += count($children);
    }
    return $totalSplit;
}

/**
 * Merge ALL split products back (when toggle OFF).
 */
function autoMergeAllProducts() {
    $db = Database::getInstance();
    ensureVariationSplitColumns();

    $parents = $db->fetchAll("SELECT DISTINCT parent_product_id FROM products WHERE parent_product_id IS NOT NULL AND parent_product_id > 0");
    $totalMerged = 0;
    foreach ($parents as $row) {
        if (mergeProductVariations($row['parent_product_id'])) $totalMerged++;
    }
    return $totalMerged;
}
