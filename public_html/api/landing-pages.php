<?php
/**
 * Landing Pages API
 * Handles: DB setup, CRUD, templates, analytics, orders
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
$db = Database::getInstance();

// ── Ensure 'landing_page' is valid for orders.channel ──
try {
    $colInfo = $db->fetch("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'channel'");
    if ($colInfo && stripos($colInfo['COLUMN_TYPE'], 'enum') !== false && stripos($colInfo['COLUMN_TYPE'], 'landing_page') === false) {
        // Add landing_page to the ENUM
        $type = $colInfo['COLUMN_TYPE'];
        $newType = str_replace(")", ",'landing_page')", $type);
        $db->query("ALTER TABLE orders MODIFY COLUMN channel $newType DEFAULT 'website'");
    }
} catch (\Throwable $e) { /* column may not exist or already VARCHAR */ }

// ── Auto-create tables ──
try {
    $db->fetch("SELECT 1 FROM landing_pages LIMIT 1");
} catch (\Throwable $e) {
    $db->query("CREATE TABLE IF NOT EXISTS landing_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        status VARCHAR(20) DEFAULT 'draft',
        template_id INT NULL,
        sections JSON,
        settings JSON,
        seo_title VARCHAR(255) DEFAULT '',
        seo_description TEXT,
        og_image VARCHAR(500) DEFAULT '',
        ab_test_enabled TINYINT(1) DEFAULT 0,
        ab_variant_b JSON,
        views INT DEFAULT 0,
        orders_count INT DEFAULT 0,
        revenue DECIMAL(12,2) DEFAULT 0,
        conversion_rate DECIMAL(5,2) DEFAULT 0,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_slug (slug),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS landing_page_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        category VARCHAR(100) DEFAULT 'custom',
        preview_image VARCHAR(500) DEFAULT '',
        description TEXT,
        sections JSON,
        settings JSON,
        is_system TINYINT(1) DEFAULT 0,
        use_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS landing_page_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        page_id INT NOT NULL,
        session_id VARCHAR(100) NOT NULL,
        variant VARCHAR(1) DEFAULT 'A',
        event_type VARCHAR(30) NOT NULL,
        event_data JSON,
        device_type VARCHAR(20) DEFAULT '',
        browser VARCHAR(50) DEFAULT '',
        os VARCHAR(50) DEFAULT '',
        ip_address VARCHAR(45) DEFAULT '',
        referrer VARCHAR(500) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_page_event (page_id, event_type),
        INDEX idx_page_session (page_id, session_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS landing_page_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        page_id INT NOT NULL,
        order_id INT NULL,
        session_id VARCHAR(100) DEFAULT '',
        variant VARCHAR(1) DEFAULT 'A',
        customer_name VARCHAR(255) NOT NULL,
        customer_phone VARCHAR(20) NOT NULL,
        customer_address TEXT,
        delivery_area VARCHAR(50) DEFAULT 'inside_dhaka',
        products JSON,
        subtotal DECIMAL(10,2) DEFAULT 0,
        delivery_charge DECIMAL(10,2) DEFAULT 0,
        total DECIMAL(10,2) DEFAULT 0,
        note TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        ip_address VARCHAR(45) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_page (page_id),
        INDEX idx_phone (customer_phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Insert system templates
    _insertSystemTemplates($db);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$isAdmin = !empty($_SESSION['admin_id']);

switch ($action) {
    // ═══ LIST PAGES ═══
    case 'list':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $status = $_GET['status'] ?? '';
        $where = '1=1';
        $params = [];
        if ($status) { $where .= ' AND status = ?'; $params[] = $status; }
        $pages = $db->fetchAll("SELECT id, title, slug, status, views, orders_count, revenue, conversion_rate, 
            ab_test_enabled, created_at, updated_at FROM landing_pages WHERE {$where} ORDER BY updated_at DESC", $params);
        echo json_encode(['success'=>true, 'data'=>$pages ?: []]);
        break;

    // ═══ GET SINGLE PAGE ═══
    case 'get':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $id = intval($_GET['id'] ?? 0);
        $page = $db->fetch("SELECT * FROM landing_pages WHERE id = ?", [$id]);
        if (!$page) { echo json_encode(['error'=>'Not found']); exit; }
        $page['sections'] = json_decode($page['sections'] ?? '[]', true);
        $page['settings'] = json_decode($page['settings'] ?? '{}', true);
        $page['ab_variant_b'] = json_decode($page['ab_variant_b'] ?? 'null', true);
        echo json_encode(['success'=>true, 'data'=>$page]);
        break;

    // ═══ CHECK SLUG AVAILABILITY ═══
    case 'check_slug':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $_GET['slug'] ?? ''), '-'));
        $excludeId = intval($_GET['exclude_id'] ?? 0);
        if (!$slug) { echo json_encode(['available'=>false, 'error'=>'Invalid slug']); exit; }
        $exists = $db->fetch("SELECT id FROM landing_pages WHERE slug = ? AND id != ?", [$slug, $excludeId]);
        echo json_encode(['available'=>!$exists, 'slug'=>$slug, 'conflict_id'=>$exists ? intval($exists['id']) : null]);
        break;

    // ═══ SEARCH SITE PRODUCTS ═══
    case 'search_products':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $q = trim($_GET['q'] ?? '');
        $limit = min(20, intval($_GET['limit'] ?? 10));
        $where = "p.is_active = 1";
        $params = [];
        if ($q) {
            $where .= " AND (p.name LIKE ? OR p.name_bn LIKE ? OR p.sku LIKE ?)";
            $like = "%{$q}%";
            $params = [$like, $like, $like];
        }
        $products = $db->fetchAll("SELECT p.id, p.name, p.name_bn, p.slug, p.regular_price, p.sale_price, 
            p.featured_image, p.is_on_sale, p.sku, c.name as category_name
            FROM products p LEFT JOIN categories c ON c.id = p.category_id
            WHERE {$where} ORDER BY p.is_featured DESC, p.name ASC LIMIT {$limit}", $params);
        
        // Build image URLs
        foreach ($products as &$pr) {
            $img = $pr['featured_image'] ?? '';
            if ($img) {
                $pr['image_url'] = SITE_URL . '/uploads/products/' . basename($img);
            } else {
                // Try product_images table
                $pi = $db->fetch("SELECT image_path FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC LIMIT 1", [$pr['id']]);
                $pr['image_url'] = $pi ? SITE_URL . '/uploads/products/' . basename($pi['image_path']) : '';
            }
            $pr['price'] = ($pr['is_on_sale'] && $pr['sale_price'] > 0 && $pr['sale_price'] < $pr['regular_price']) 
                ? floatval($pr['sale_price']) : floatval($pr['regular_price']);
            $pr['compare_price'] = ($pr['is_on_sale'] && $pr['sale_price'] > 0) ? floatval($pr['regular_price']) : 0;
            $pr['product_url'] = SITE_URL . '/' . ($pr['slug'] ?? 'product/' . $pr['id']);
        }
        unset($pr);
        echo json_encode(['success'=>true, 'data'=>$products ?: []]);
        break;

    // ═══ SAVE PAGE ═══
    case 'save':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $id = intval($_POST['id'] ?? 0);
        $rawSlug = trim($_POST['slug'] ?? $_POST['title'] ?? 'untitled');
        $cleanSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $rawSlug), '-'));
        if (!$cleanSlug) $cleanSlug = 'page-' . time();
        
        // Check slug collision explicitly
        $slugConflict = $db->fetch("SELECT id, title FROM landing_pages WHERE slug = ? AND id != ?", [$cleanSlug, $id]);
        if ($slugConflict) {
            echo json_encode(['error'=>'স্লাগ "' . $cleanSlug . '" ইতিমধ্যে ব্যবহৃত (Page: ' . $slugConflict['title'] . ')', 'slug_conflict'=>true, 'conflict_page'=>$slugConflict['title']]);
            exit;
        }
        
        $data = [
            'title' => trim($_POST['title'] ?? 'Untitled Landing Page'),
            'slug' => $cleanSlug,
            'status' => $_POST['status'] ?? 'draft',
            'sections' => $_POST['sections'] ?? '[]',
            'settings' => $_POST['settings'] ?? '{}',
            'seo_title' => trim($_POST['seo_title'] ?? ''),
            'seo_description' => trim($_POST['seo_description'] ?? ''),
            'og_image' => trim($_POST['og_image'] ?? ''),
            'ab_test_enabled' => intval($_POST['ab_test_enabled'] ?? 0),
            'ab_variant_b' => $_POST['ab_variant_b'] ?? null,
            'template_id' => intval($_POST['template_id'] ?? 0) ?: null,
        ];
        if ($id) {
            $db->update('landing_pages', $data, 'id = ?', [$id]);
        } else {
            $data['created_by'] = $_SESSION['admin_id'] ?? null;
            $id = $db->insert('landing_pages', $data);
        }
        
        // Auto-create/sync temp products for all LP products
        _syncLpProducts($db, $id, $data['sections']);
        
        echo json_encode(['success'=>true, 'id'=>$id, 'slug'=>$data['slug']]);
        break;

    // ═══ DELETE PAGE ═══
    case 'delete':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $id = intval($_POST['id'] ?? 0);
        // Delete temp products created for this LP
        $db->query("DELETE FROM products WHERE sku LIKE ?", ['LP-' . $id . '-%']);
        $db->query("DELETE FROM landing_pages WHERE id = ?", [$id]);
        $db->query("DELETE FROM landing_page_events WHERE page_id = ?", [$id]);
        $db->query("DELETE FROM landing_page_orders WHERE page_id = ?", [$id]);
        echo json_encode(['success'=>true]);
        break;

    // ═══ DUPLICATE PAGE ═══
    case 'duplicate':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $id = intval($_POST['id'] ?? 0);
        $page = $db->fetch("SELECT * FROM landing_pages WHERE id = ?", [$id]);
        if (!$page) { echo json_encode(['error'=>'Not found']); exit; }
        $newSlug = _makeSlug($page['slug'] . '-copy', $db, 0);
        $newId = $db->insert('landing_pages', [
            'title' => $page['title'] . ' (Copy)',
            'slug' => $newSlug,
            'status' => 'draft',
            'template_id' => $page['template_id'],
            'sections' => $page['sections'],
            'settings' => $page['settings'],
            'seo_title' => $page['seo_title'] ?? '',
            'seo_description' => $page['seo_description'] ?? '',
            'og_image' => $page['og_image'] ?? '',
            'created_by' => $_SESSION['admin_id'] ?? null,
        ]);
        echo json_encode(['success'=>true, 'id'=>$newId]);
        break;

    // ═══ TEMPLATES ═══
    case 'templates':
        $templates = $db->fetchAll("SELECT * FROM landing_page_templates ORDER BY is_system DESC, use_count DESC");
        foreach ($templates as &$t) {
            $t['sections'] = json_decode($t['sections'] ?? '[]', true);
            $t['settings'] = json_decode($t['settings'] ?? '{}', true);
        }
        echo json_encode(['success'=>true, 'data'=>$templates ?: []]);
        break;

    case 'load_template':
        $tplId = intval($_GET['id'] ?? 0);
        $tpl = $db->fetch("SELECT * FROM landing_page_templates WHERE id = ?", [$tplId]);
        if (!$tpl) { echo json_encode(['error'=>'Template not found']); exit; }
        $db->query("UPDATE landing_page_templates SET use_count = use_count + 1 WHERE id = ?", [$tplId]);
        $tpl['sections'] = json_decode($tpl['sections'] ?? '[]', true);
        $tpl['settings'] = json_decode($tpl['settings'] ?? '{}', true);
        echo json_encode(['success'=>true, 'data'=>$tpl]);
        break;

    case 'save_template':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $name = trim($_POST['name'] ?? 'My Template');
        $id = $db->insert('landing_page_templates', [
            'name' => $name,
            'slug' => _makeSlug($name, $db, 0, 'landing_page_templates'),
            'category' => trim($_POST['category'] ?? 'custom'),
            'preview_image' => trim($_POST['preview_image'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'sections' => $_POST['sections'] ?? '[]',
            'settings' => $_POST['settings'] ?? '{}',
            'is_system' => 0,
        ]);
        echo json_encode(['success'=>true, 'id'=>$id]);
        break;

    case 'delete_template':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $id = intval($_POST['id'] ?? 0);
        $db->query("DELETE FROM landing_page_templates WHERE id = ? AND is_system = 0", [$id]);
        echo json_encode(['success'=>true]);
        break;

    // ═══ ANALYTICS ═══
    case 'track':
        // Public endpoint — no auth required
        $pageId = intval($_POST['page_id'] ?? 0);
        if (!$pageId) { echo json_encode(['error'=>'Missing page_id']); exit; }
        $eventType = $_POST['event_type'] ?? '';
        $allowed = ['view','click','scroll','section_view','cta_click','product_click','order_start','time_spent'];
        if (!in_array($eventType, $allowed)) { echo json_encode(['error'=>'Invalid event']); exit; }
        
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $db->insert('landing_page_events', [
            'page_id' => $pageId,
            'session_id' => $_POST['session_id'] ?? session_id(),
            'variant' => $_POST['variant'] ?? 'A',
            'event_type' => $eventType,
            'event_data' => $_POST['event_data'] ?? '{}',
            'device_type' => _detectDevice($ua),
            'browser' => _detectBrowser($ua),
            'os' => _detectOS($ua),
            'ip_address' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
            'referrer' => mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
        ]);
        
        // Update view counter
        if ($eventType === 'view') {
            $db->query("UPDATE landing_pages SET views = views + 1 WHERE id = ?", [$pageId]);
        }
        echo json_encode(['success'=>true]);
        break;

    case 'analytics':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $pageId = intval($_GET['page_id'] ?? 0);
        $days = intval($_GET['days'] ?? 30);
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Summary stats
        $views = $db->fetch("SELECT COUNT(DISTINCT session_id) as cnt FROM landing_page_events WHERE page_id = ? AND event_type = 'view' AND created_at >= ?", [$pageId, $since]);
        $orders = $db->fetch("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as revenue FROM landing_page_orders WHERE page_id = ? AND created_at >= ?", [$pageId, $since]);
        $uniqueViews = intval($views['cnt'] ?? 0);
        $totalOrders = intval($orders['cnt'] ?? 0);
        $revenue = floatval($orders['revenue'] ?? 0);
        $convRate = $uniqueViews > 0 ? round(($totalOrders / $uniqueViews) * 100, 2) : 0;
        
        // Update cached stats
        $db->query("UPDATE landing_pages SET orders_count = ?, revenue = ?, conversion_rate = ? WHERE id = ?", 
            [$totalOrders, $revenue, $convRate, $pageId]);
        
        // Daily breakdown
        $daily = $db->fetchAll("SELECT DATE(created_at) as date, 
            COUNT(DISTINCT CASE WHEN event_type='view' THEN session_id END) as views,
            COUNT(CASE WHEN event_type='cta_click' THEN 1 END) as clicks,
            COUNT(CASE WHEN event_type='order_start' THEN 1 END) as order_starts
            FROM landing_page_events WHERE page_id = ? AND created_at >= ?
            GROUP BY DATE(created_at) ORDER BY date", [$pageId, $since]);
        
        // Device breakdown
        $devices = $db->fetchAll("SELECT device_type, COUNT(DISTINCT session_id) as cnt 
            FROM landing_page_events WHERE page_id = ? AND event_type = 'view' AND created_at >= ?
            GROUP BY device_type", [$pageId, $since]);
        
        // Scroll depth distribution
        $scrolls = $db->fetchAll("SELECT JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.depth')) as depth, COUNT(*) as cnt
            FROM landing_page_events WHERE page_id = ? AND event_type = 'scroll' AND created_at >= ?
            GROUP BY depth ORDER BY depth", [$pageId, $since]);
        
        // Click heatmap data (x,y coordinates)
        $clicks = $db->fetchAll("SELECT event_data FROM landing_page_events 
            WHERE page_id = ? AND event_type = 'click' AND created_at >= ? LIMIT 5000", [$pageId, $since]);
        $heatmapData = [];
        foreach ($clicks as $c) {
            $d = json_decode($c['event_data'] ?? '{}', true);
            if (isset($d['x'], $d['y'])) $heatmapData[] = ['x'=>$d['x'], 'y'=>$d['y'], 'section'=>$d['section'] ?? ''];
        }
        
        // Section engagement
        $sectionViews = $db->fetchAll("SELECT JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.section_id')) as section_id,
            JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.section_type')) as section_type,
            COUNT(*) as views, AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.time_visible')) AS DECIMAL)) as avg_time
            FROM landing_page_events WHERE page_id = ? AND event_type = 'section_view' AND created_at >= ?
            GROUP BY section_id, section_type ORDER BY views DESC", [$pageId, $since]);
        
        // A/B test data
        $abData = null;
        $page = $db->fetch("SELECT ab_test_enabled FROM landing_pages WHERE id = ?", [$pageId]);
        if ($page && $page['ab_test_enabled']) {
            $abData = [];
            foreach (['A','B'] as $v) {
                $vViews = $db->fetch("SELECT COUNT(DISTINCT session_id) as cnt FROM landing_page_events WHERE page_id = ? AND variant = ? AND event_type = 'view' AND created_at >= ?", [$pageId, $v, $since]);
                $vOrders = $db->fetch("SELECT COUNT(*) as cnt FROM landing_page_orders WHERE page_id = ? AND variant = ? AND created_at >= ?", [$pageId, $v, $since]);
                $vv = intval($vViews['cnt'] ?? 0);
                $vo = intval($vOrders['cnt'] ?? 0);
                $abData[$v] = ['views'=>$vv, 'orders'=>$vo, 'rate'=>$vv > 0 ? round(($vo/$vv)*100, 2) : 0];
            }
        }
        
        // Avg time on page
        $avgTime = $db->fetch("SELECT AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.seconds')) AS DECIMAL)) as avg_sec
            FROM landing_page_events WHERE page_id = ? AND event_type = 'time_spent' AND created_at >= ?", [$pageId, $since]);
        
        echo json_encode(['success'=>true, 'data'=>[
            'summary' => ['views'=>$uniqueViews, 'orders'=>$totalOrders, 'revenue'=>$revenue, 'conversion_rate'=>$convRate, 'avg_time'=>round(floatval($avgTime['avg_sec'] ?? 0))],
            'daily' => $daily ?: [],
            'devices' => $devices ?: [],
            'scroll_depth' => $scrolls ?: [],
            'heatmap' => $heatmapData,
            'sections' => $sectionViews ?: [],
            'ab_test' => $abData,
        ]]);
        break;

    // ═══ LANDING PAGE ORDER ═══
    case 'order':
        // Public endpoint
        $pageId = intval($_POST['page_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        if (!$name || !$phone || !$address) { echo json_encode(['error'=>'নাম, ফোন ও ঠিকানা দিন']); exit; }
        
        $products = json_decode($_POST['products'] ?? '[]', true);
        $deliveryArea = $_POST['delivery_area'] ?? 'inside_dhaka';
        
        // Get delivery charges from page settings
        $deliveryCharges = ['inside_dhaka'=>70, 'dhaka_sub'=>100, 'outside_dhaka'=>130];
        $lpPage = $db->fetch("SELECT settings FROM landing_pages WHERE id = ?", [$pageId]);
        if ($lpPage) {
            $lpSettings = json_decode($lpPage['settings'] ?? '{}', true);
            $dc = $lpSettings['order_form']['delivery_charges'] ?? [];
            if (!empty($dc)) $deliveryCharges = array_merge($deliveryCharges, $dc);
        }
        $delCharge = intval($deliveryCharges[$deliveryArea] ?? 130);
        
        $subtotal = 0;
        foreach ($products as $p) {
            $subtotal += floatval($p['price'] ?? 0) * intval($p['qty'] ?? 1);
        }
        $total = $subtotal + $delCharge;
        
        $lpOrderId = $db->insert('landing_page_orders', [
            'page_id' => $pageId,
            'session_id' => $_POST['session_id'] ?? session_id(),
            'variant' => $_POST['variant'] ?? 'A',
            'customer_name' => $name,
            'customer_phone' => _formatPhone($phone),
            'customer_address' => $address,
            'delivery_area' => $deliveryArea,
            'products' => json_encode($products),
            'subtotal' => $subtotal,
            'delivery_charge' => $delCharge,
            'total' => $total,
            'note' => trim($_POST['note'] ?? ''),
            'ip_address' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        
        // Also create main order in orders table (use same columns as site's createOrder)
        try {
            $orderNum = generateOrderNumber();
            $fmtPhone = _formatPhone($phone);
            
            // Find or create customer
            $customer = $db->fetch("SELECT * FROM customers WHERE phone = ?", [$fmtPhone]);
            if ($customer) {
                $customerId = intval($customer['id']);
                $db->query("UPDATE customers SET name = ?, address = ?, total_orders = total_orders + 1 WHERE id = ?", [$name, $address, $customerId]);
            } else {
                $customerId = $db->insert('customers', [
                    'name' => $name,
                    'phone' => $fmtPhone,
                    'address' => $address,
                    'ip_address' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
                    'total_orders' => 1,
                ]);
            }
            
            $orderData = [
                'order_number' => $orderNum,
                'customer_id' => $customerId,
                'customer_name' => $name,
                'customer_phone' => $fmtPhone,
                'customer_address' => $address,
                'order_status' => 'processing',
                'channel' => 'landing_page',
                'subtotal' => $subtotal,
                'shipping_cost' => $delCharge,
                'discount_amount' => 0,
                'total' => $total,
                'payment_method' => 'cod',
                'notes' => trim($_POST['note'] ?? '') . " [LP#{$pageId}]",
                'ip_address' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
            ];
            
            $mainOrderId = $db->insert('orders', $orderData);
            
            // Insert order items
            foreach ($products as $p) {
                $pId = intval($p['product_id'] ?? 0);
                $qty = intval($p['qty'] ?? 1);
                $price = floatval($p['price'] ?? 0);
                // Verify product exists if linked
                if ($pId > 0) {
                    $exists = $db->fetch("SELECT id FROM products WHERE id = ?", [$pId]);
                    if (!$exists) $pId = 0;
                }
                $db->insert('order_items', [
                    'order_id' => $mainOrderId,
                    'product_id' => $pId,
                    'product_name' => $p['name'] ?? '',
                    'quantity' => $qty,
                    'price' => $price,
                    'total' => $price * $qty,
                ]);
            }
            $db->query("UPDATE landing_page_orders SET order_id = ? WHERE id = ?", [$mainOrderId, $lpOrderId]);
            // Update page stats
            $db->query("UPDATE landing_pages SET orders_count = orders_count + 1, revenue = revenue + ? WHERE id = ?", [$total, $pageId]);
        } catch (\Throwable $e) {
            // Log error for debugging
            error_log("Landing page order creation error: " . $e->getMessage());
        }
        
        echo json_encode(['success'=>true, 'order_id'=>$lpOrderId, 'message'=>'অর্ডার সফল হয়েছে!']);
        break;

    // ═══ LP ORDERS LIST ═══
    // ═══ FEATURE 4: CREATE TEMP PRODUCT FOR LP CUSTOM PRODUCTS ═══
    case 'create_temp_product':
        $pageId = intval($_POST['page_id'] ?? 0);
        $name = trim($_POST['name'] ?? 'LP Product');
        $price = floatval($_POST['price'] ?? 0);
        $comparePrice = floatval($_POST['compare_price'] ?? 0);
        $image = trim($_POST['image'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        
        if (!$pageId || $price <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid data']);
            break;
        }
        
        // Check if temp product already exists for this LP + name + price combo
        $sku = 'LP-' . $pageId . '-' . substr(md5($name . $price), 0, 8);
        $existing = $db->fetch("SELECT id FROM products WHERE sku = ? LIMIT 1", [$sku]);
        if ($existing) {
            // Update price/name if changed
            $db->query("UPDATE products SET name = ?, name_bn = ?, regular_price = ?, sale_price = ?, description = ?, updated_at = NOW() WHERE id = ?", 
                [$name, $name, $comparePrice > 0 ? $comparePrice : $price, $comparePrice > 0 ? $price : null, $desc, $existing['id']]);
            echo json_encode(['success' => true, 'product_id' => intval($existing['id']), 'existing' => true]);
            break;
        }
        
        // Create hidden product
        $slug = 'lp-' . $pageId . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower(substr($name, 0, 40))) . '-' . substr(uniqid(), -5);
        $imageBasename = '';
        if ($image) {
            // If image is a full URL to our uploads, extract basename
            $imageBasename = basename(parse_url($image, PHP_URL_PATH));
        }
        
        $productData = [
            'name' => $name,
            'name_bn' => $name,
            'slug' => $slug,
            'sku' => $sku,
            'description' => $desc,
            'regular_price' => $comparePrice > 0 ? $comparePrice : $price,
            'sale_price' => $comparePrice > 0 ? $price : null,
            'is_on_sale' => $comparePrice > 0 ? 1 : 0,
            'is_active' => 0, // Hidden from main site
            'is_featured' => 0,
            'stock_status' => 'in_stock',
            'featured_image' => $imageBasename,
            'tags' => 'landing-page,lp-' . $pageId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        
        try {
            $newId = $db->insert('products', $productData);
            echo json_encode(['success' => true, 'product_id' => intval($newId), 'sku' => $sku]);
        } catch (\Throwable $e) {
            error_log("LP temp product creation error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Could not create product']);
        }
        break;

    // ═══ LP ORDER TRACKING (called by frontend after real checkout success) ═══
    case 'lp_order_track':
        $pageId = intval($_POST['page_id'] ?? 0);
        $orderNumber = trim($_POST['order_number'] ?? '');
        if ($pageId && $orderNumber) {
            try {
                // Find the real order
                $realOrder = $db->fetch("SELECT id, total FROM orders WHERE order_number = ? LIMIT 1", [$orderNumber]);
                if ($realOrder) {
                    $db->query("UPDATE landing_pages SET orders_count = orders_count + 1, revenue = revenue + ? WHERE id = ?", 
                        [floatval($realOrder['total']), $pageId]);
                    // Also record in landing_page_orders for analytics
                    try {
                        $db->insert('landing_page_orders', [
                            'page_id' => $pageId,
                            'order_id' => intval($realOrder['id']),
                            'session_id' => $_POST['session_id'] ?? session_id(),
                            'variant' => $_POST['variant'] ?? 'A',
                            'customer_name' => '',
                            'customer_phone' => '',
                            'total' => floatval($realOrder['total']),
                        ]);
                    } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {}
        }
        echo json_encode(['success'=>true]);
        break;

    // ═══ LP ORDERS LIST ═══
    case 'orders':
        if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
        $pageId = intval($_GET['page_id'] ?? 0);
        $where = $pageId ? 'page_id = ?' : '1=1';
        $params = $pageId ? [$pageId] : [];
        $lpOrders = $db->fetchAll("SELECT lo.*, lp.title as page_title FROM landing_page_orders lo 
            LEFT JOIN landing_pages lp ON lp.id = lo.page_id WHERE {$where} ORDER BY lo.created_at DESC LIMIT 200", $params);
        echo json_encode(['success'=>true, 'data'=>$lpOrders ?: []]);
        break;

    default:
        echo json_encode(['error'=>'Unknown action']);
}

// ── HELPERS ──
function _makeSlug($text, $db, $excludeId = 0, $table = 'landing_pages') {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $text), '-'));
    if (!$slug) $slug = 'page-' . time();
    $base = $slug; $i = 1;
    while (true) {
        $exists = $db->fetch("SELECT id FROM {$table} WHERE slug = ? AND id != ?", [$slug, $excludeId]);
        if (!$exists) break;
        $slug = $base . '-' . (++$i);
    }
    return $slug;
}

/**
 * Sync LP products → auto-create temp products in the products table
 * Called after every LP save. Products use SKU pattern LP-{pageId}-{hash}
 * so they're idempotent across multiple saves.
 */
function _syncLpProducts($db, $pageId, $sectionsJson) {
    try {
        $sections = is_array($sectionsJson) ? $sectionsJson : json_decode($sectionsJson, true);
        if (!$sections || !is_array($sections)) return;
        
        $updated = false;
        $usedSkus = []; // Track which SKUs are still in use
        
        foreach ($sections as &$sec) {
            if (($sec['type'] ?? '') !== 'products') continue;
            if (!isset($sec['content']['products'])) continue;
            
            foreach ($sec['content']['products'] as &$p) {
                $rpid = intval($p['real_product_id'] ?? 0);
                $name = trim($p['name'] ?? 'LP Product');
                $price = floatval($p['price'] ?? 0);
                $comparePrice = floatval($p['compare_price'] ?? 0);
                $image = trim($p['image'] ?? '');
                $desc = trim($p['description'] ?? '');
                
                if ($price <= 0 && $comparePrice <= 0) continue;
                
                // Generate deterministic SKU for this product
                $sku = 'LP-' . $pageId . '-' . substr(md5($name . $price), 0, 8);
                $usedSkus[] = $sku;
                
                // Check if temp product already exists
                $existing = $db->fetch("SELECT id FROM products WHERE sku = ? LIMIT 1", [$sku]);
                
                if ($existing) {
                    // Update existing temp product
                    $imgBase = $image ? basename(parse_url($image, PHP_URL_PATH)) : '';
                    $db->query("UPDATE products SET name = ?, name_bn = ?, regular_price = ?, sale_price = ?, 
                        featured_image = CASE WHEN ? != '' THEN ? ELSE featured_image END,
                        description = ?, updated_at = NOW() WHERE id = ?", [
                        $name, $name,
                        $comparePrice > 0 ? $comparePrice : $price,
                        $comparePrice > 0 ? $price : null,
                        $imgBase, $imgBase,
                        $desc, $existing['id']
                    ]);
                    
                    // Link product if not yet linked
                    if ($rpid !== intval($existing['id'])) {
                        $p['real_product_id'] = intval($existing['id']);
                        $updated = true;
                    }
                } else {
                    // Create new temp product
                    $slug = 'lp-' . $pageId . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower(substr($name, 0, 40))) . '-' . substr(uniqid(), -5);
                    $imgBase = $image ? basename(parse_url($image, PHP_URL_PATH)) : '';
                    
                    $newId = $db->insert('products', [
                        'name' => $name,
                        'name_bn' => $name,
                        'slug' => $slug,
                        'sku' => $sku,
                        'description' => $desc,
                        'regular_price' => $comparePrice > 0 ? $comparePrice : $price,
                        'sale_price' => $comparePrice > 0 ? $price : null,
                        'is_on_sale' => $comparePrice > 0 ? 1 : 0,
                        'is_active' => 0, // Hidden from main site catalog
                        'is_featured' => 0,
                        'stock_status' => 'in_stock',
                        'featured_image' => $imgBase,
                        'tags' => 'landing-page,lp-' . $pageId,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    
                    if ($newId) {
                        $p['real_product_id'] = intval($newId);
                        $updated = true;
                    }
                }
            }
            unset($p);
        }
        unset($sec);
        
        // Clean up orphaned temp products (removed from LP but still in DB)
        $likePattern = 'LP-' . $pageId . '-%';
        $orphans = $db->fetchAll("SELECT id, sku FROM products WHERE sku LIKE ? AND is_active = 0", [$likePattern]);
        foreach ($orphans as $orph) {
            if (!in_array($orph['sku'], $usedSkus)) {
                $db->query("DELETE FROM products WHERE id = ?", [$orph['id']]);
            }
        }
        
        // Save updated sections with real_product_ids back to LP
        if ($updated) {
            $db->query("UPDATE landing_pages SET sections = ? WHERE id = ?", [json_encode($sections, JSON_UNESCAPED_UNICODE), $pageId]);
        }
    } catch (\Throwable $e) {
        error_log("LP product sync error (page {$pageId}): " . $e->getMessage());
    }
}

function _formatPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (str_starts_with($phone, '880')) $phone = '0' . substr($phone, 3);
    if (str_starts_with($phone, '+880')) $phone = '0' . substr($phone, 4);
    if (!str_starts_with($phone, '0')) $phone = '0' . $phone;
    return $phone;
}

function _detectDevice($ua) {
    if (preg_match('/mobile|iphone|ipod|android.*mobile|windows.*phone/i', $ua)) return 'mobile';
    if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) return 'tablet';
    return 'desktop';
}
function _detectBrowser($ua) {
    if (preg_match('/Edg\//i', $ua)) return 'Edge';
    if (preg_match('/OPR|Opera/i', $ua)) return 'Opera';
    if (preg_match('/Chrome/i', $ua)) return 'Chrome';
    if (preg_match('/Firefox/i', $ua)) return 'Firefox';
    if (preg_match('/Safari/i', $ua) && !preg_match('/Chrome/i', $ua)) return 'Safari';
    return 'Other';
}
function _detectOS($ua) {
    if (preg_match('/Android/i', $ua)) return 'Android';
    if (preg_match('/iPhone|iPad/i', $ua)) return 'iOS';
    if (preg_match('/Windows/i', $ua)) return 'Windows';
    if (preg_match('/Mac OS/i', $ua)) return 'macOS';
    if (preg_match('/Linux/i', $ua)) return 'Linux';
    return 'Other';
}

function _insertSystemTemplates($db) {
    $existing = $db->fetch("SELECT COUNT(*) as c FROM landing_page_templates WHERE is_system = 1");
    if (intval($existing['c'] ?? 0) > 0) return;
    
    // ── Template 1: Ladies Stylish Handbag ──
    $db->insert('landing_page_templates', [
        'name' => 'Ladies Stylish Handbag',
        'slug' => 'ladies-handbag',
        'category' => 'fashion',
        'description' => 'Elegant product landing page for premium handbags with dark/gold luxury theme',
        'is_system' => 1,
        'sections' => json_encode([
            ['id'=>'hero_1','type'=>'hero','enabled'=>true,'order'=>0,'content'=>[
                'headline'=>'প্রিমিয়াম লেদার হ্যান্ডব্যাগ','subheadline'=>'স্টাইল আর কোয়ালিটির পারফেক্ট কম্বিনেশন — ১০০% জেনুইন লেদার',
                'badge'=>'🔥 সীমিত স্টক','cta_text'=>'এখনই অর্ডার করুন','cta_link'=>'#order',
                'image'=>'','bg_video'=>''],'settings'=>['bg_color'=>'#1a1a2e','text_color'=>'#ffffff','accent_color'=>'#d4af37','overlay_opacity'=>60,'layout'=>'split','padding'=>'80px']],
            ['id'=>'trust_1','type'=>'trust_badges','enabled'=>true,'order'=>1,'content'=>[
                'badges'=>[
                    ['icon'=>'🛡️','text'=>'১০০% অরিজিনাল'],
                    ['icon'=>'🚚','text'=>'ফ্রি ডেলিভারি'],
                    ['icon'=>'↩️','text'=>'৭ দিন রিটার্ন'],
                    ['icon'=>'💳','text'=>'ক্যাশ অন ডেলিভারি']
                ]],'settings'=>['bg_color'=>'#f8f6f0','text_color'=>'#1a1a2e','columns'=>4]],
            ['id'=>'products_1','type'=>'products','enabled'=>true,'order'=>2,'content'=>[
                'headline'=>'আমাদের বেস্ট সেলার কালেকশন','subheadline'=>'প্রতিটি ব্যাগ হ্যান্ডমেড ক্রাফটসম্যানশিপে তৈরি',
                'products'=>[
                    ['name'=>'ক্লাসিক টোট ব্যাগ','price'=>2499,'compare_price'=>3999,'image'=>'','badge'=>'Best Seller','description'=>'প্রিমিয়াম লেদার, স্পেসিয়াস ডিজাইন'],
                    ['name'=>'মিনি ক্রসবডি ব্যাগ','price'=>1899,'compare_price'=>2999,'image'=>'','badge'=>'-37%','description'=>'ক্যাজুয়াল ও পার্টি — দুটোতেই পারফেক্ট'],
                    ['name'=>'অফিস ল্যাপটপ ব্যাগ','price'=>3499,'compare_price'=>4999,'image'=>'','badge'=>'New','description'=>'১৫.৬" ল্যাপটপ ফিট, ওয়াটারপ্রুফ']
                ]],'settings'=>['bg_color'=>'#ffffff','text_color'=>'#1a1a2e','accent_color'=>'#d4af37','columns'=>3,'show_badge'=>true]],
            ['id'=>'features_1','type'=>'features','enabled'=>true,'order'=>3,'content'=>[
                'headline'=>'কেন আমাদের ব্যাগ আলাদা?',
                'features'=>[
                    ['icon'=>'✨','title'=>'প্রিমিয়াম লেদার','desc'=>'ইতালিয়ান ট্যানারি থেকে আমদানিকৃত জেনুইন লেদার'],
                    ['icon'=>'🪡','title'=>'হ্যান্ডমেড ক্রাফট','desc'=>'দক্ষ কারিগরদের হাতে তৈরি প্রতিটি পিস'],
                    ['icon'=>'💎','title'=>'ডিজাইনার লুক','desc'=>'ইন্টারন্যাশনাল ট্রেন্ড অনুসরণ করে ডিজাইন'],
                    ['icon'=>'🔒','title'=>'দীর্ঘস্থায়ী কোয়ালিটি','desc'=>'মিনিমাম ৫ বছরের ওয়ারেন্টি']
                ]],'settings'=>['bg_color'=>'#1a1a2e','text_color'=>'#ffffff','accent_color'=>'#d4af37','columns'=>4,'layout'=>'grid']],
            ['id'=>'testimonials_1','type'=>'testimonials','enabled'=>true,'order'=>4,'content'=>[
                'headline'=>'আমাদের খুশি কাস্টমারদের মতামত',
                'items'=>[
                    ['name'=>'ফারজানা আক্তার','location'=>'ঢাকা','rating'=>5,'text'=>'অসাধারণ কোয়ালিটি! ব্যাগটা দেখে বান্ধবীরা সবাই জানতে চায় কোথা থেকে কিনেছি।','avatar'=>''],
                    ['name'=>'নুসরাত জাহান','location'=>'চট্টগ্রাম','rating'=>5,'text'=>'দাম একটু বেশি মনে হলেও কোয়ালিটি দেখে বুঝলাম টাকাটা সার্থক। ১০০% রিকমেন্ড!','avatar'=>''],
                    ['name'=>'তানিয়া ইসলাম','location'=>'সিলেট','rating'=>5,'text'=>'৩ মাস ইউজ করেছি, একদম নতুনের মতো আছে। ডেলিভারিও ছিল খুব দ্রুত।','avatar'=>'']
                ]],'settings'=>['bg_color'=>'#f8f6f0','text_color'=>'#1a1a2e','columns'=>3]],
            ['id'=>'countdown_1','type'=>'countdown','enabled'=>true,'order'=>5,'content'=>[
                'headline'=>'⏰ অফার শেষ হচ্ছে!','subheadline'=>'এই স্পেশাল প্রাইসে সীমিত স্টক বাকি আছে',
                'end_date'=>date('Y-m-d', strtotime('+3 days')).'T23:59:59','cta_text'=>'এখনই অর্ডার করুন','cta_link'=>'#order'
            ],'settings'=>['bg_color'=>'#d4af37','text_color'=>'#1a1a2e','style'=>'urgent']],
            ['id'=>'faq_1','type'=>'faq','enabled'=>true,'order'=>6,'content'=>[
                'headline'=>'সচরাচর জিজ্ঞাসা',
                'items'=>[
                    ['q'=>'ব্যাগটি কি অরিজিনাল লেদার?','a'=>'হ্যাঁ, আমরা ১০০% জেনুইন লেদার ব্যবহার করি। প্রতিটি ব্যাগের সাথে অথেন্টিসিটি কার্ড দেওয়া হয়।'],
                    ['q'=>'ডেলিভারি কত দিনে পাবো?','a'=>'ঢাকার ভিতরে ১-২ দিন, ঢাকার বাইরে ৩-৫ দিন।'],
                    ['q'=>'রিটার্ন পলিসি কি?','a'=>'পণ্য হাতে পেয়ে ৭ দিনের মধ্যে কোনো সমস্যা থাকলে রিটার্ন করতে পারবেন।'],
                    ['q'=>'পেমেন্ট কিভাবে করবো?','a'=>'ক্যাশ অন ডেলিভারি। পণ্য হাতে পেয়ে পেমেন্ট করুন।']
                ]],'settings'=>['bg_color'=>'#ffffff','text_color'=>'#1a1a2e','accent_color'=>'#d4af37']],
        ]),
        'settings' => json_encode([
            'primary_color'=>'#d4af37','secondary_color'=>'#1a1a2e','bg_color'=>'#ffffff','text_color'=>'#1a1a2e',
            'font_heading'=>'Playfair Display','font_body'=>'Poppins',
            'order_form'=>['enabled'=>true,'title'=>'অর্ডার করুন','delivery_charges'=>['inside_dhaka'=>70,'dhaka_sub'=>100,'outside_dhaka'=>130]],
            'floating_cta'=>['enabled'=>true,'text'=>'এখনই অর্ডার করুন','color'=>'#d4af37'],
            'whatsapp'=>['enabled'=>true,'number'=>'8801828373189'],
        ]),
    ]);

    // ── Template 2: Organic Food ──
    $db->insert('landing_page_templates', [
        'name' => 'Organic Food',
        'slug' => 'organic-food',
        'category' => 'food',
        'description' => 'Fresh, natural theme for organic food products with earthy green tones',
        'is_system' => 1,
        'sections' => json_encode([
            ['id'=>'hero_1','type'=>'hero','enabled'=>true,'order'=>0,'content'=>[
                'headline'=>'১০০% অর্গানিক ও খাঁটি খাবার','subheadline'=>'সরাসরি কৃষক থেকে আপনার দোরগোড়ায় — কোনো কেমিক্যাল নেই, কোনো ভেজাল নেই',
                'badge'=>'🌿 প্রাকৃতিক','cta_text'=>'অর্ডার করুন','cta_link'=>'#order',
                'image'=>''],'settings'=>['bg_color'=>'#f0f7e6','text_color'=>'#2d5016','accent_color'=>'#4a7c23','overlay_opacity'=>40,'layout'=>'split','padding'=>'80px']],
            ['id'=>'trust_1','type'=>'trust_badges','enabled'=>true,'order'=>1,'content'=>[
                'badges'=>[['icon'=>'🌿','text'=>'১০০% অর্গানিক'],['icon'=>'🧪','text'=>'ল্যাব টেস্টেড'],['icon'=>'🚛','text'=>'ফ্রেশ ডেলিভারি'],['icon'=>'💯','text'=>'মানি ব্যাক গ্যারান্টি']]
            ],'settings'=>['bg_color'=>'#2d5016','text_color'=>'#ffffff','columns'=>4]],
            ['id'=>'products_1','type'=>'products','enabled'=>true,'order'=>2,'content'=>[
                'headline'=>'আমাদের জনপ্রিয় পণ্য','subheadline'=>'প্রতিটি পণ্য সরাসরি খামার থেকে সংগ্রহ করা',
                'products'=>[
                    ['name'=>'খাঁটি সরিষার তেল','price'=>450,'compare_price'=>600,'image'=>'','badge'=>'Best Seller','description'=>'ঘানিভাঙা খাঁটি সরিষার তেল - ১ লিটার'],
                    ['name'=>'সুন্দরবনের খাঁটি মধু','price'=>850,'compare_price'=>1200,'image'=>'','badge'=>'-29%','description'=>'১০০% খাঁটি মধু - ৫০০ গ্রাম'],
                    ['name'=>'অর্গানিক ঘি','price'=>750,'compare_price'=>950,'image'=>'','badge'=>'Popular','description'=>'দেশী গরুর দুধের খাঁটি ঘি - ৫০০ মিলি']
                ]],'settings'=>['bg_color'=>'#ffffff','text_color'=>'#2d5016','accent_color'=>'#4a7c23','columns'=>3,'show_badge'=>true]],
            ['id'=>'features_1','type'=>'features','enabled'=>true,'order'=>3,'content'=>[
                'headline'=>'আমরা কেন আলাদা',
                'features'=>[
                    ['icon'=>'🌱','title'=>'সরাসরি কৃষক থেকে','desc'=>'মধ্যস্বত্বভোগী নেই, তাই দাম কম কোয়ালিটি বেশি'],
                    ['icon'=>'🔬','title'=>'ল্যাব টেস্টেড','desc'=>'প্রতিটি ব্যাচ BSTI অনুমোদিত ল্যাবে পরীক্ষিত'],
                    ['icon'=>'📦','title'=>'ফ্রেশ প্যাকেজিং','desc'=>'এয়ারটাইট প্যাকেজিংয়ে পৌঁছায় আপনার কাছে'],
                    ['icon'=>'♻️','title'=>'ইকো-ফ্রেন্ডলি','desc'=>'বায়োডিগ্রেডেবল প্যাকেজিং ব্যবহার করি']
                ]],'settings'=>['bg_color'=>'#f0f7e6','text_color'=>'#2d5016','accent_color'=>'#4a7c23','columns'=>4,'layout'=>'grid']],
            ['id'=>'video_1','type'=>'video','enabled'=>true,'order'=>4,'content'=>[
                'headline'=>'দেখুন কিভাবে আমরা পণ্য সংগ্রহ করি','youtube_id'=>'','poster_image'=>''
            ],'settings'=>['bg_color'=>'#ffffff','text_color'=>'#2d5016']],
            ['id'=>'testimonials_1','type'=>'testimonials','enabled'=>true,'order'=>5,'content'=>[
                'headline'=>'কাস্টমার রিভিউ',
                'items'=>[
                    ['name'=>'আব্দুল করিম','location'=>'ঢাকা','rating'=>5,'text'=>'সরিষার তেলটা অসাধারণ! বাজারের তেলের সাথে তুলনা হয় না।','avatar'=>''],
                    ['name'=>'রুমানা পারভীন','location'=>'রাজশাহী','rating'=>5,'text'=>'মধুটা টেস্ট করেই বুঝেছি খাঁটি। এখন রেগুলার অর্ডার করি।','avatar'=>''],
                    ['name'=>'হাসান মাহমুদ','location'=>'চট্টগ্রাম','rating'=>5,'text'=>'ঘি-টা চমৎকার! রান্নায় দিলে আলাদা স্বাদ পাওয়া যায়।','avatar'=>'']
                ]],'settings'=>['bg_color'=>'#2d5016','text_color'=>'#ffffff','columns'=>3]],
            ['id'=>'faq_1','type'=>'faq','enabled'=>true,'order'=>6,'content'=>[
                'headline'=>'জিজ্ঞাসা',
                'items'=>[
                    ['q'=>'পণ্য কি সত্যিই অর্গানিক?','a'=>'হ্যাঁ, প্রতিটি পণ্য ল্যাব টেস্ট সার্টিফিকেট সহ ডেলিভারি দেওয়া হয়।'],
                    ['q'=>'ডেলিভারি চার্জ কত?','a'=>'ঢাকায় ৭০ টাকা, ঢাকার বাইরে ১৩০ টাকা। ৫০০০ টাকার উপরে অর্ডারে ফ্রি ডেলিভারি।'],
                    ['q'=>'শেলফ লাইফ কত দিন?','a'=>'পণ্যভেদে ৬ মাস থেকে ১ বছর। প্রতিটি পণ্যে তারিখ উল্লেখ থাকে।']
                ]],'settings'=>['bg_color'=>'#f0f7e6','text_color'=>'#2d5016','accent_color'=>'#4a7c23']],
        ]),
        'settings' => json_encode([
            'primary_color'=>'#4a7c23','secondary_color'=>'#2d5016','bg_color'=>'#f0f7e6','text_color'=>'#2d5016',
            'font_heading'=>'Cormorant Garamond','font_body'=>'Open Sans',
            'order_form'=>['enabled'=>true,'title'=>'অর্ডার করুন','delivery_charges'=>['inside_dhaka'=>70,'dhaka_sub'=>100,'outside_dhaka'=>130]],
            'floating_cta'=>['enabled'=>true,'text'=>'অর্ডার করুন','color'=>'#4a7c23'],
            'whatsapp'=>['enabled'=>true,'number'=>'8801828373189'],
        ]),
    ]);

    // ── Template 3: Sunglasses ──
    $db->insert('landing_page_templates', [
        'name' => 'Sunglasses',
        'slug' => 'sunglasses',
        'category' => 'fashion',
        'description' => 'Bold, modern theme for trendy sunglasses with gradient/dark aesthetic',
        'is_system' => 1,
        'sections' => json_encode([
            ['id'=>'hero_1','type'=>'hero','enabled'=>true,'order'=>0,'content'=>[
                'headline'=>'স্টাইল মিটস প্রোটেকশন','subheadline'=>'UV400 প্রোটেক্টেড প্রিমিয়াম সানগ্লাস — আপনার চোখের যত্নে',
                'badge'=>'🕶️ New Collection','cta_text'=>'শপ নাও','cta_link'=>'#order',
                'image'=>''],'settings'=>['bg_color'=>'#0f0f1a','text_color'=>'#ffffff','accent_color'=>'#ff6b35','overlay_opacity'=>70,'layout'=>'center','padding'=>'100px']],
            ['id'=>'trust_1','type'=>'trust_badges','enabled'=>true,'order'=>1,'content'=>[
                'badges'=>[['icon'=>'🛡️','text'=>'UV400 প্রোটেকশন'],['icon'=>'💎','text'=>'প্রিমিয়াম লেন্স'],['icon'=>'📦','text'=>'ফ্রি কেস + ক্লথ'],['icon'=>'🔄','text'=>'৩০ দিন রিটার্ন']]
            ],'settings'=>['bg_color'=>'#1a1a2e','text_color'=>'#ffffff','columns'=>4]],
            ['id'=>'products_1','type'=>'products','enabled'=>true,'order'=>2,'content'=>[
                'headline'=>'ট্রেন্ডিং কালেকশন','subheadline'=>'প্রতিটি ফ্রেম ইউনিক ডিজাইন ও প্রিমিয়াম মেটেরিয়াল',
                'products'=>[
                    ['name'=>'এভিয়েটর ক্লাসিক','price'=>980,'compare_price'=>1500,'image'=>'','badge'=>'Trending','description'=>'গোল্ড ফ্রেম, গ্রিন লেন্স, UV400'],
                    ['name'=>'ওয়েফেয়ারার বোল্ড','price'=>850,'compare_price'=>1299,'image'=>'','badge'=>'-35%','description'=>'ম্যাট ব্ল্যাক, পোলারাইজড লেন্স'],
                    ['name'=>'স্পোর্টস র‍্যাপ','price'=>1250,'compare_price'=>1899,'image'=>'','badge'=>'New','description'=>'লাইটওয়েট, অ্যান্টি-গ্লেয়ার, স্পোর্টস ফিট']
                ]],'settings'=>['bg_color'=>'#0f0f1a','text_color'=>'#ffffff','accent_color'=>'#ff6b35','columns'=>3,'show_badge'=>true]],
            ['id'=>'features_1','type'=>'features','enabled'=>true,'order'=>3,'content'=>[
                'headline'=>'কেন আমাদের সানগ্লাস?',
                'features'=>[
                    ['icon'=>'☀️','title'=>'UV400 প্রোটেকশন','desc'=>'ক্ষতিকর UV রশ্মি থেকে ১০০% সুরক্ষা'],
                    ['icon'=>'🪶','title'=>'সুপার লাইটওয়েট','desc'=>'সারাদিন পরেও আরামদায়ক'],
                    ['icon'=>'💪','title'=>'ডিউরেবল ফ্রেম','desc'=>'ফ্লেক্সিবল ও ব্রেক-রেসিস্ট্যান্ট'],
                    ['icon'=>'🎨','title'=>'ট্রেন্ডি ডিজাইন','desc'=>'ইন্টারন্যাশনাল ফ্যাশন ট্রেন্ড']
                ]],'settings'=>['bg_color'=>'#1a1a2e','text_color'=>'#ffffff','accent_color'=>'#ff6b35','columns'=>4,'layout'=>'grid']],
            ['id'=>'before_after_1','type'=>'before_after','enabled'=>true,'order'=>4,'content'=>[
                'headline'=>'সানগ্লাস থাকলে পার্থক্যটা দেখুন',
                'before_label'=>'সানগ্লাস ছাড়া','after_label'=>'সানগ্লাস সহ',
                'before_image'=>'','after_image'=>''
            ],'settings'=>['bg_color'=>'#0f0f1a','text_color'=>'#ffffff']],
            ['id'=>'testimonials_1','type'=>'testimonials','enabled'=>true,'order'=>5,'content'=>[
                'headline'=>'কাস্টমার ফিডব্যাক',
                'items'=>[
                    ['name'=>'রাফি আহমেদ','location'=>'ঢাকা','rating'=>5,'text'=>'লুক ও কোয়ালিটি দুটোই জোশ! এই দামে এত ভালো সানগ্লাস পাওয়া রেয়ার।','avatar'=>''],
                    ['name'=>'সাদিয়া ইসলাম','location'=>'সিলেট','rating'=>5,'text'=>'পোলারাইজড লেন্স সত্যিই কাজ করে! গাড়ি চালাতে অনেক আরাম।','avatar'=>''],
                    ['name'=>'তানভীর হোসেন','location'=>'খুলনা','rating'=>5,'text'=>'ফ্রেম খুবই স্টার্ডি, ২ মাস পরেও একদম টাইট।','avatar'=>'']
                ]],'settings'=>['bg_color'=>'#ff6b35','text_color'=>'#ffffff','columns'=>3]],
            ['id'=>'countdown_1','type'=>'countdown','enabled'=>true,'order'=>6,'content'=>[
                'headline'=>'🔥 ফ্ল্যাশ সেল চলছে!','subheadline'=>'এই অফার মিস করবেন না',
                'end_date'=>date('Y-m-d', strtotime('+2 days')).'T23:59:59','cta_text'=>'অর্ডার করুন','cta_link'=>'#order'
            ],'settings'=>['bg_color'=>'#0f0f1a','text_color'=>'#ffffff','style'=>'urgent']],
            ['id'=>'faq_1','type'=>'faq','enabled'=>true,'order'=>7,'content'=>[
                'headline'=>'FAQ',
                'items'=>[
                    ['q'=>'লেন্স কি পোলারাইজড?','a'=>'হ্যাঁ, আমাদের সব প্রিমিয়াম মডেলে পোলারাইজড লেন্স আছে।'],
                    ['q'=>'ফ্রেম সাইজ কিভাবে বুঝবো?','a'=>'প্রতিটি প্রোডাক্টে ফ্রেম সাইজ (মিমি) উল্লেখ আছে। স্ট্যান্ডার্ড সাইজ বেশিরভাগ ফেসে ফিট হয়।'],
                    ['q'=>'কেস কি ফ্রি?','a'=>'হ্যাঁ, প্রতিটি সানগ্লাসের সাথে হার্ড কেস + ক্লিনিং ক্লথ ফ্রি।']
                ]],'settings'=>['bg_color'=>'#1a1a2e','text_color'=>'#ffffff','accent_color'=>'#ff6b35']],
        ]),
        'settings' => json_encode([
            'primary_color'=>'#ff6b35','secondary_color'=>'#0f0f1a','bg_color'=>'#0f0f1a','text_color'=>'#ffffff',
            'font_heading'=>'Montserrat','font_body'=>'Inter',
            'order_form'=>['enabled'=>true,'title'=>'অর্ডার করুন','delivery_charges'=>['inside_dhaka'=>70,'dhaka_sub'=>100,'outside_dhaka'=>130]],
            'floating_cta'=>['enabled'=>true,'text'=>'অর্ডার করুন','color'=>'#ff6b35'],
            'whatsapp'=>['enabled'=>true,'number'=>'8801828373189'],
        ]),
    ]);
}
