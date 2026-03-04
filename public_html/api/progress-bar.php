<?php
/**
 * Progress Bar API v4 — Rebuilt from scratch
 * Uses: $db->insert(), $db->update(), $db->query(), $db->fetch(), $db->fetchAll()
 */
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();
$action = $_REQUEST['action'] ?? '';
$isAdmin = !empty($_SESSION['admin_id']);

// ── Auto-create table on every request (IF NOT EXISTS is near-zero cost) ──
try {
    $db->query("CREATE TABLE IF NOT EXISTS `checkout_progress_bars` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `template` TINYINT DEFAULT 1,
        `tiers` JSON DEFAULT NULL,
        `config` JSON DEFAULT NULL,
        `is_active` TINYINT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\Throwable $e) {
    // Table might already exist with different engine, that's OK
}

// ── Ensure config column exists ──
try {
    $db->fetch("SELECT `config` FROM `checkout_progress_bars` LIMIT 0");
} catch (\Throwable $e) {
    try { $db->query("ALTER TABLE `checkout_progress_bars` ADD COLUMN `config` JSON DEFAULT NULL AFTER `tiers`"); } catch (\Throwable $e2) {}
}

switch ($action) {

// ══════════ PUBLIC: Get active bar ══════════
case 'get_active':
    try {
        $bar = $db->fetch("SELECT * FROM `checkout_progress_bars` WHERE `is_active` = 1 LIMIT 1");
        if ($bar) {
            $t = json_decode($bar['tiers'] ?? '[]', true); $bar['tiers'] = is_array($t) ? $t : [];
            $c = json_decode($bar['config'] ?? '{}', true); $bar['config'] = is_array($c) ? $c : [];
        }
        echo json_encode(['success'=>true,'bar'=>$bar]);
    } catch (\Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage(),'bar'=>null]);
    }
    break;

// ══════════ PUBLIC: Sync free gifts ══════════
case 'sync_gifts':
    try {
        $cart = getCart();
        $subtotal = 0;
        foreach ($cart as $k => $item) {
            if (!empty($item['is_free_gift'])) continue;
            $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
        }
        $bar = $db->fetch("SELECT * FROM `checkout_progress_bars` WHERE `is_active` = 1 LIMIT 1");
        if ($bar) { $t = json_decode($bar['tiers'] ?? '[]', true); $bar['tiers'] = is_array($t) ? $t : []; }
        $shouldHave = [];
        if ($bar && !empty($bar['tiers'])) {
            foreach ($bar['tiers'] as $tier) {
                if ($tier['reward_type'] === 'free_product' && !empty($tier['free_product_id']) && $subtotal >= $tier['min_amount']) {
                    $shouldHave[intval($tier['free_product_id'])] = $tier;
                }
            }
        }
        $changed = false;
        foreach ($cart as $k => $item) {
            if (!empty($item['is_free_gift'])) {
                $pid = intval($item['product_id']);
                if (!isset($shouldHave[$pid])) { unset($_SESSION['cart'][$k]); $changed = true; }
                else { unset($shouldHave[$pid]); }
            }
        }
        foreach ($shouldHave as $pid => $tier) {
            $product = getProduct($pid);
            if (!$product) continue;
            $_SESSION['cart']['_freegift_' . $pid] = [
                'product_id' => $pid, 'variant_id' => null,
                'name' => ($product['name_bn'] ?: $product['name']) . ' (ফ্রি গিফট)', 'variant_name' => '',
                'price' => 0, 'regular_price' => getProductPrice($product),
                'quantity' => 1, 'image' => getProductImage($product),
                'is_free_gift' => true, 'progress_bar_tier' => $tier['min_amount'],
            ];
            $changed = true;
        }
        $items = [];
        foreach (getCart() as $key => $item) { $items[] = array_merge($item, ['key' => $key]); }
        echo json_encode(['success'=>true,'changed'=>$changed,'items'=>$items,'total'=>getCartTotal(),'count'=>getCartCount(),'subtotal_no_gifts'=>$subtotal]);
    } catch (\Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

// ══════════ ADMIN: List all bars ══════════
case 'admin_list':
    if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
    try {
        $bars = $db->fetchAll("SELECT * FROM `checkout_progress_bars` ORDER BY `is_active` DESC, `id` DESC");
        foreach ($bars as &$b) {
            $t = json_decode($b['tiers'] ?? '[]', true); $b['tiers'] = is_array($t) ? $t : [];
            $c = json_decode($b['config'] ?? '{}', true); $b['config'] = is_array($c) ? $c : [];
        }
        echo json_encode(['success'=>true,'bars'=>$bars]);
    } catch (\Throwable $e) {
        echo json_encode(['success'=>false,'bars'=>[],'message'=>$e->getMessage()]);
    }
    break;

// ══════════ ADMIN: Save (create or update) ══════════
case 'admin_save':
    if (!$isAdmin) { echo json_encode(['success'=>false,'message'=>'Not logged in as admin']); exit; }
    try {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $template = max(1, min(6, intval($_POST['template'] ?? 1)));
        $rawTiers = $_POST['tiers'] ?? '[]';
        $rawConfig = $_POST['config'] ?? '{}';
        
        $tiers = json_decode($rawTiers, true);
        $config = json_decode($rawConfig, true);
        
        if (!$name) { echo json_encode(['success'=>false,'message'=>'Name is required']); exit; }
        if (!is_array($tiers) || empty($tiers)) { echo json_encode(['success'=>false,'message'=>'At least one tier required. Raw: '.substr($rawTiers,0,200)]); exit; }
        
        // Sanitize tiers
        $cleanTiers = [];
        foreach ($tiers as $t) {
            $cleanTiers[] = [
                'min_amount' => max(0, floatval($t['min_amount'] ?? 0)),
                'reward_type' => in_array($t['reward_type'] ?? '', ['free_shipping','discount_fixed','discount_percent','free_product']) ? $t['reward_type'] : 'free_shipping',
                'reward_value' => max(0, floatval($t['reward_value'] ?? 0)),
                'free_product_id' => !empty($t['free_product_id']) ? intval($t['free_product_id']) : null,
                'label_bn' => trim($t['label_bn'] ?? ''),
                'label_en' => trim($t['label_en'] ?? ''),
                'icon' => trim($t['icon'] ?? '🎁'),
            ];
        }
        usort($cleanTiers, fn($a,$b) => $a['min_amount'] <=> $b['min_amount']);
        $tiersJson = json_encode($cleanTiers, JSON_UNESCAPED_UNICODE);
        
        // Sanitize config
        $cleanConfig = [
            'shrink' => max(0, min(50, intval(($config['shrink'] ?? 0)))),
            'color_fill_from' => preg_match('/^#[0-9a-fA-F]{6}$/', $config['color_fill_from'] ?? '') ? $config['color_fill_from'] : null,
            'color_fill_to' => preg_match('/^#[0-9a-fA-F]{6}$/', $config['color_fill_to'] ?? '') ? $config['color_fill_to'] : null,
            'color_track_bg' => preg_match('/^#[0-9a-fA-F]{6}$/', $config['color_track_bg'] ?? '') ? $config['color_track_bg'] : null,
        ];
        $configJson = json_encode($cleanConfig, JSON_UNESCAPED_UNICODE);
        
        if ($id > 0) {
            // Update using $db->update('table', data, where, params)
            $db->update('checkout_progress_bars', [
                'name' => $name,
                'template' => $template,
                'tiers' => $tiersJson,
                'config' => $configJson,
            ], 'id = ?', [$id]);
        } else {
            // Insert using $db->insert('table', data) — returns new ID
            $id = $db->insert('checkout_progress_bars', [
                'name' => $name,
                'template' => $template,
                'tiers' => $tiersJson,
                'config' => $configJson,
                'is_active' => 0,
            ]);
        }
        
        // Verify save worked
        $verify = $db->fetch("SELECT id, name, tiers FROM `checkout_progress_bars` WHERE id = ?", [$id]);
        $savedTierCount = 0;
        if ($verify) {
            $st = json_decode($verify['tiers'] ?? '[]', true);
            $savedTierCount = is_array($st) ? count($st) : 0;
        }
        
        echo json_encode([
            'success' => true,
            'id' => $id,
            'message' => "Saved! {$savedTierCount} tiers stored.",
            'debug' => ['tier_count' => $savedTierCount, 'name_saved' => $verify['name'] ?? '']
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success'=>false,'message'=>'Save error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    }
    break;

// ══════════ ADMIN: Activate ══════════
case 'admin_activate':
    if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
    try {
        $id = intval($_POST['id'] ?? 0);
        $db->query("UPDATE `checkout_progress_bars` SET `is_active` = 0 WHERE 1");
        if ($id > 0) $db->query("UPDATE `checkout_progress_bars` SET `is_active` = 1 WHERE `id` = ?", [$id]);
        echo json_encode(['success'=>true]);
    } catch (\Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

// ══════════ ADMIN: Delete ══════════
case 'admin_delete':
    if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
    try {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) $db->query("DELETE FROM `checkout_progress_bars` WHERE `id` = ?", [$id]);
        echo json_encode(['success'=>true]);
    } catch (\Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

// ══════════ ADMIN: Toggle enabled ══════════
case 'admin_toggle':
    if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
    try {
        $val = $_POST['enabled'] ?? '0';
        $enabled = $val === '1';
        $fieldsJson = getSetting('checkout_fields', '');
        $fields = $fieldsJson ? json_decode($fieldsJson, true) : null;
        if ($fields && is_array($fields)) {
            foreach ($fields as &$f) {
                if (($f['key'] ?? '') === 'progress_bar') { $f['enabled'] = $enabled; break; }
            }
            unset($f);
            $db->query("UPDATE `site_settings` SET `setting_value` = ? WHERE `setting_key` = 'checkout_fields'", [json_encode($fields, JSON_UNESCAPED_UNICODE)]);
        }
        $db->query("INSERT INTO `site_settings` (`setting_key`, `setting_value`, `setting_type`, `setting_group`) VALUES ('progress_bar_enabled',?,'boolean','checkout') ON DUPLICATE KEY UPDATE `setting_value`=?", [$val, $val]);
        echo json_encode(['success'=>true,'enabled'=>$val]);
    } catch (\Throwable $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    break;

// ══════════ ADMIN: Search products ══════════
case 'search_products':
    if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(['products'=>[]]); exit; }
    try {
        $products = $db->fetchAll("SELECT id, name, name_bn, slug, featured_image, sale_price, regular_price FROM products WHERE is_active = 1 AND (name LIKE ? OR name_bn LIKE ? OR id = ?) ORDER BY id DESC LIMIT 15", ["%{$q}%", "%{$q}%", intval($q)]);
        echo json_encode(['products'=>$products]);
    } catch (\Throwable $e) {
        echo json_encode(['products'=>[]]);
    }
    break;

// ══════════ ADMIN: Diagnostic ══════════
case 'diag':
    if (!$isAdmin) { echo json_encode(['error'=>'Unauthorized']); exit; }
    $diag = [];
    try {
        $diag['table_exists'] = true;
        $diag['bar_count'] = count($db->fetchAll("SELECT id FROM `checkout_progress_bars`"));
        $active = $db->fetch("SELECT id, name FROM `checkout_progress_bars` WHERE `is_active` = 1 LIMIT 1");
        $diag['active_bar'] = $active ? $active['name'] . ' (id:' . $active['id'] . ')' : 'NONE';
        $diag['config_column'] = true;
        try { $db->fetch("SELECT config FROM checkout_progress_bars LIMIT 0"); } catch (\Throwable $e) { $diag['config_column'] = false; }
    } catch (\Throwable $e) {
        $diag['table_exists'] = false;
        $diag['error'] = $e->getMessage();
    }
    echo json_encode(['success'=>true,'diag'=>$diag]);
    break;

default:
    echo json_encode(['success'=>false,'message'=>'Unknown action: '.$action]);
}
