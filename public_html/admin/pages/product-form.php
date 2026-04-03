<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();
$id = intval($_GET['id'] ?? 0);
$product = $id ? $db->fetch("SELECT * FROM products WHERE id = ?", [$id]) : null;
$pageTitle = $product ? 'Edit Product' : 'Add Product';

// ── Ensure new tables exist (auto-migration for combination system) ──
try { $db->query("SELECT 1 FROM product_attributes LIMIT 0"); } catch (\Throwable $e) {
    try { $db->query("CREATE TABLE IF NOT EXISTS `product_attributes` (`id` int(11) NOT NULL AUTO_INCREMENT, `product_id` int(11) NOT NULL, `attribute_name` varchar(100) NOT NULL, `attribute_values` text NOT NULL, `is_visible` tinyint(1) NOT NULL DEFAULT 1, `is_variation` tinyint(1) NOT NULL DEFAULT 1, `sort_order` int(11) NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `idx_product_attrs` (`product_id`), CONSTRAINT `fk_product_attrs_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"); } catch (\Throwable $e2) {}
}
try { $db->query("SELECT 1 FROM product_variant_combinations LIMIT 0"); } catch (\Throwable $e) {
    try { $db->query("CREATE TABLE IF NOT EXISTS `product_variant_combinations` (`id` int(11) NOT NULL AUTO_INCREMENT, `product_id` int(11) NOT NULL, `combination_key` varchar(500) NOT NULL, `combination_label` varchar(500) NOT NULL, `sku` varchar(100) DEFAULT NULL, `regular_price` decimal(12,2) DEFAULT NULL, `sale_price` decimal(12,2) DEFAULT NULL, `cost_price` decimal(12,2) DEFAULT NULL, `stock_quantity` int(11) NOT NULL DEFAULT 0, `manage_stock` tinyint(1) NOT NULL DEFAULT 1, `weight` decimal(10,4) DEFAULT NULL, `variant_image` varchar(500) DEFAULT NULL, `is_active` tinyint(1) NOT NULL DEFAULT 1, `is_default` tinyint(1) NOT NULL DEFAULT 0, `sort_order` int(11) NOT NULL DEFAULT 0, `created_at` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), KEY `idx_combo_product` (`product_id`), CONSTRAINT `fk_combo_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3"); } catch (\Throwable $e2) {}
}
try { $db->query("SELECT variation_mode FROM products LIMIT 0"); } catch (\Throwable $e) {
    try { $db->query("ALTER TABLE products ADD COLUMN variation_mode ENUM('legacy','combination') NOT NULL DEFAULT 'legacy' AFTER product_type"); } catch (\Throwable $e2) {}
}

// ── AJAX: delete image ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_image') {
    $imgId = intval($_POST['image_id']);
    $img = $db->fetch("SELECT * FROM product_images WHERE id = ? AND product_id = ?", [$imgId, $id]);
    if ($img) {
        $path = UPLOAD_PATH . 'products/' . $img['image_path'];
        if (file_exists($path)) @unlink($path);
        $db->delete('product_images', 'id = ?', [$imgId]);
        if ($img['is_primary']) $db->query("UPDATE products SET featured_image = NULL WHERE id = ?", [$id]);
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ── AJAX: set primary ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_primary') {
    $imgId = intval($_POST['image_id']);
    $img = $db->fetch("SELECT * FROM product_images WHERE id = ? AND product_id = ?", [$imgId, $id]);
    if ($img) {
        $db->query("UPDATE product_images SET is_primary = 0 WHERE product_id = ?", [$id]);
        $db->query("UPDATE product_images SET is_primary = 1 WHERE id = ?", [$imgId]);
        $db->query("UPDATE products SET featured_image = ? WHERE id = ?", [$img['image_path'], $id]);
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// ── AJAX: search products (for upsell/bundle picker) ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'search_products') {
    header('Content-Type: application/json');
    $q = sanitize($_GET['q'] ?? '');
    $exclude = intval($_GET['exclude'] ?? 0);
    $results = $db->fetchAll(
        "SELECT id, name, name_bn, featured_image, regular_price, sale_price, stock_status 
         FROM products WHERE is_active = 1 AND id != ? AND (name LIKE ? OR name_bn LIKE ? OR sku LIKE ?) 
         ORDER BY name LIMIT 20",
        [$exclude, "%$q%", "%$q%", "%$q%"]
    );
    foreach ($results as &$r) {
        $r['image_url'] = $r['featured_image'] ? imgSrc('products', $r['featured_image']) : asset('img/default-product.svg');
        $r['display_price'] = $r['sale_price'] && $r['sale_price'] < $r['regular_price'] ? $r['sale_price'] : $r['regular_price'];
    }
    echo json_encode($results);
    exit;
}

// ── Main Save ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $slug = sanitize($_POST['slug']) ?: strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $_POST['name'])));
    $existingSlug = $db->fetch("SELECT id FROM products WHERE slug = ? AND id != ?", [$slug, $id]);
    if ($existingSlug) $slug .= '-' . time();

    // Get category name for SKU generation
    $catId = intval($_POST['category_id'] ?? 0);
    $catNameForSku = null;
    if ($catId) {
        $catRow = $db->fetch("SELECT name FROM categories WHERE id=?", [$catId]);
        $catNameForSku = $catRow['name'] ?? null;
    }

    $data = [
        'name' => sanitize($_POST['name']),
        'name_bn' => sanitize($_POST['name_bn'] ?? ''),
        'slug' => $slug,
        'sku' => sanitize($_POST['sku'] ?? '') ?: generateProductSKU($id ?: null, $catNameForSku),
        'product_type' => ($_POST['product_type'] ?? 'simple') === 'variable' ? 'variable' : 'simple',
        'variation_mode' => in_array(($_POST['variation_mode'] ?? 'legacy'), ['legacy','combination']) ? $_POST['variation_mode'] : 'legacy',
        'category_id' => $catId ?: null,
        'short_description' => $_POST['short_description'] ?? '',
        'description' => $_POST['description'] ?? '',
        'regular_price' => floatval($_POST['regular_price']) ?: 0,
        'sale_price' => ($_POST['product_type'] ?? 'simple') === 'variable' ? null : (floatval($_POST['sale_price'] ?? 0) ?: null),
        'cost_price' => floatval($_POST[ ($_POST['product_type'] ?? 'simple') === 'variable' ? 'cost_price_var' : 'cost_price' ] ?? 0) ?: null,
        'manage_stock' => ($_POST['product_type'] ?? 'simple') === 'variable' ? 0 : (isset($_POST['manage_stock']) ? 1 : 0),
        'stock_quantity' => ($_POST['product_type'] ?? 'simple') === 'variable' ? 0 : intval($_POST['stock_quantity'] ?? 0),
        'low_stock_threshold' => intval($_POST['low_stock_threshold'] ?? 5),
        'stock_status' => ($_POST['product_type'] ?? 'simple') === 'variable' ? 'in_stock' : ($_POST['stock_status'] ?? 'in_stock'),
        'weight' => floatval($_POST[ ($_POST['product_type'] ?? 'simple') === 'variable' ? 'weight_var' : 'weight' ] ?? 0) ?: null,
        'tags' => sanitize($_POST['tags'] ?? ''),
        'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
        'is_on_sale' => isset($_POST['is_on_sale']) ? 1 : 0,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'hide_header' => isset($_POST['hide_header']) ? 1 : 0,
        'meta_title' => sanitize($_POST['meta_title'] ?? ''),
        'meta_description' => sanitize($_POST['meta_description'] ?? ''),
        'require_customer_upload' => isset($_POST['require_customer_upload']) ? 1 : 0,
        'customer_upload_label' => sanitize($_POST['customer_upload_label'] ?? ''),
        'customer_upload_required' => isset($_POST['customer_upload_required']) ? 1 : 0,
        'bundle_name' => sanitize($_POST['bundle_name'] ?? ''),
        'store_credit_enabled' => isset($_POST['store_credit_enabled']) ? 1 : 0,
        'store_credit_amount' => floatval($_POST['store_credit_amount'] ?? 0),
        'image_slideshow' => isset($_POST['image_slideshow']) ? 1 : 0,
        'retention_value' => intval($_POST['retention_value'] ?? 0) ?: null,
        'retention_unit'  => in_array($_POST['retention_unit'] ?? 'days', ['days','weeks','months']) ? $_POST['retention_unit'] : 'days',
        'stock_unit' => sanitize($_POST['stock_unit'] ?? 'pcs'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if (!empty($_FILES['featured_image']['name'])) {
        $upload = uploadFile($_FILES['featured_image'], 'products');
        if ($upload) $data['featured_image'] = basename($upload);
    }

    // Ensure retention columns exist
    try { $db->query("SELECT retention_value FROM products LIMIT 0"); } catch (\Throwable $e) {
        try { $db->query("ALTER TABLE products ADD COLUMN retention_value INT UNSIGNED NULL DEFAULT NULL AFTER updated_at"); } catch (\Throwable $e2) {}
        try { $db->query("ALTER TABLE products ADD COLUMN retention_unit ENUM('days','weeks','months') NOT NULL DEFAULT 'days' AFTER retention_value"); } catch (\Throwable $e2) {}
    }
    // Ensure image_slideshow column exists
    try { $db->query("SELECT image_slideshow FROM products LIMIT 0"); } catch (\Throwable $e) {
        try { $db->query("ALTER TABLE products ADD COLUMN image_slideshow TINYINT(1) NOT NULL DEFAULT 0 AFTER featured_image"); } catch (\Throwable $e2) {}
    }

    if ($id) { $db->update('products', $data, 'id = ?', [$id]); }
    else { $data['created_at'] = date('Y-m-d H:i:s'); $id = $db->insert('products', $data); }

    // Save featured to product_images
    if (!empty($data['featured_image']) && $id) {
        $imgPath = basename($data['featured_image']);
        $db->query("UPDATE product_images SET is_primary = 0 WHERE product_id = ?", [$id]);
        $db->insert('product_images', ['product_id' => $id, 'image_path' => $imgPath, 'is_primary' => 1, 'sort_order' => 0]);
    }

    // Gallery upload
    if (!empty($_FILES['gallery_images']['name'][0])) {
        $maxSort = $db->fetch("SELECT COALESCE(MAX(sort_order),0) as mx FROM product_images WHERE product_id = ?", [$id])['mx'];
        foreach ($_FILES['gallery_images']['name'] as $key => $name) {
            if (!$name) continue;
            $file = ['name'=>$name, 'type'=>$_FILES['gallery_images']['type'][$key], 'tmp_name'=>$_FILES['gallery_images']['tmp_name'][$key], 'error'=>$_FILES['gallery_images']['error'][$key], 'size'=>$_FILES['gallery_images']['size'][$key]];
            $path = uploadFile($file, 'products');
            if ($path) {
                $path = basename($path);
                $maxSort++;
                $hasPrimary = $db->fetch("SELECT COUNT(*) as c FROM product_images WHERE product_id=? AND is_primary=1", [$id])['c'];
                $db->insert('product_images', ['product_id'=>$id, 'image_path'=>$path, 'is_primary'=>$hasPrimary?0:1, 'sort_order'=>$maxSort]);
                if (!$hasPrimary) $db->query("UPDATE products SET featured_image=? WHERE id=?", [$path, $id]);
            }
        }
    }

    // Gallery from media library
    if (!empty($_POST['gallery_from_media'])) {
        $mediaPaths = array_filter(explode(',', $_POST['gallery_from_media']));
        $maxSort = $db->fetch("SELECT COALESCE(MAX(sort_order),0) as mx FROM product_images WHERE product_id = ?", [$id])['mx'];
        foreach ($mediaPaths as $mpath) {
            $mpath = trim($mpath);
            if (!$mpath) continue;
            // Normalize: remove "products/" prefix if present since we store relative to products/
            $imgPath = preg_replace('#^products/#', '', $mpath);
            $exists = $db->fetch("SELECT id FROM product_images WHERE product_id=? AND image_path=?", [$id, $imgPath]);
            if ($exists) continue;
            $maxSort++;
            $hasPrimary = $db->fetch("SELECT COUNT(*) as c FROM product_images WHERE product_id=? AND is_primary=1", [$id])['c'];
            $db->insert('product_images', ['product_id'=>$id, 'image_path'=>$imgPath, 'is_primary'=>$hasPrimary?0:1, 'sort_order'=>$maxSort]);
            if (!$hasPrimary) $db->query("UPDATE products SET featured_image=? WHERE id=?", [$imgPath, $id]);
        }
    }

    // ══════════════════════════════════════
    // ── Save Combinations (new system) ──
    // ══════════════════════════════════════
    $variationMode = $_POST['variation_mode'] ?? 'legacy';
    if ($variationMode === 'combination' && ($data['product_type'] ?? 'simple') === 'variable') {
        // Save attributes
        try { $db->delete('product_attributes', 'product_id = ?', [$id]); } catch (\Throwable $e) {}
        if (isset($_POST['attr_name'])) {
            foreach ($_POST['attr_name'] as $ai => $aname) {
                $aname = trim(sanitize($aname));
                if (!$aname) continue;
                $avalues = sanitize($_POST['attr_values'][$ai] ?? '');
                if (!$avalues) continue;
                $valsArr = array_filter(array_map('trim', explode('|', $avalues)));
                if (empty($valsArr)) continue;
                $db->insert('product_attributes', [
                    'product_id' => $id, 'attribute_name' => $aname,
                    'attribute_values' => json_encode($valsArr, JSON_UNESCAPED_UNICODE),
                    'is_visible' => isset($_POST['attr_visible'][$ai]) ? 1 : 0,
                    'is_variation' => isset($_POST['attr_for_variation'][$ai]) ? 1 : 0,
                    'sort_order' => $ai,
                ]);
            }
        }
        // Save combinations + sync to legacy product_variants
        try { $db->delete('product_variant_combinations', 'product_id = ?', [$id]); } catch (\Throwable $e) {}
        $db->delete('product_variants', "product_id = ? AND option_type = 'variation'", [$id]);
        
        if (isset($_POST['combo_key'])) {
            $productSku = $data['sku'] ?? generateProductSKU($id, $catNameForSku ?? null);
            $defaultComboIdx = intval($_POST['combo_default'] ?? -1);
            foreach ($_POST['combo_key'] as $ci => $ckey) {
                if (!$ckey) continue;
                $comboLabel = sanitize($_POST['combo_label'][$ci] ?? '');
                $comboSku = sanitize($_POST['combo_sku'][$ci] ?? '');
                if (!$comboSku) $comboSku = $productSku . '-C' . ($ci+1);
                $cReg = floatval($_POST['combo_regular_price'][$ci] ?? 0);
                $cSale = floatval($_POST['combo_sale_price'][$ci] ?? 0);
                $cCost = floatval($_POST['combo_cost_price'][$ci] ?? 0);
                $cStock = intval($_POST['combo_stock'][$ci] ?? 0);
                $cManage = isset($_POST['combo_manage_stock_' . $ci]) ? 1 : 0;
                $cWeight = floatval($_POST['combo_weight'][$ci] ?? 0) ?: null;
                $cImage = sanitize($_POST['combo_image'][$ci] ?? '') ?: null;
                $cAbsPrice = ($cSale > 0 && $cSale < $cReg) ? $cSale : $cReg;
                
                $db->insert('product_variant_combinations', [
                    'product_id'=>$id, 'combination_key'=>$ckey, 'combination_label'=>$comboLabel,
                    'sku'=>$comboSku, 'regular_price'=>$cReg?:null,
                    'sale_price'=>($cSale>0&&$cSale<$cReg)?$cSale:null,
                    'cost_price'=>$cCost?:null, 'stock_quantity'=>$cStock,
                    'manage_stock'=>$cManage, 'weight'=>$cWeight, 'variant_image'=>$cImage,
                    'is_active'=>1, 'is_default'=>($ci==$defaultComboIdx)?1:0, 'sort_order'=>$ci,
                ]);
                // Sync to legacy product_variants for frontend compatibility
                $keyData = json_decode($ckey, true);
                if (is_array($keyData)) {
                    $db->insert('product_variants', [
                        'product_id'=>$id, 'variant_name'=>implode(' / ', array_keys($keyData)),
                        'variant_value'=>implode(' / ', array_values($keyData)),
                        'option_type'=>'variation', 'price_adjustment'=>0,
                        'absolute_price'=>$cAbsPrice, 'var_regular_price'=>$cReg?:null,
                        'var_sale_price'=>($cSale>0&&$cSale<$cReg)?$cSale:null,
                        'stock_quantity'=>$cStock, 'manage_stock'=>$cManage,
                        'sku'=>$comboSku, 'variant_image'=>$cImage,
                        'is_active'=>1, 'is_default'=>($ci==$defaultComboIdx)?1:0,
                        'weight_per_unit'=>$cWeight,
                    ]);
                }
            }
        }
        // Auto-calc product pricing from combinations
        $cp = $db->fetch("SELECT MIN(COALESCE(sale_price,regular_price)) as smin, MAX(regular_price) as rmax, SUM(stock_quantity) as total FROM product_variant_combinations WHERE product_id=? AND is_active=1 AND regular_price>0", [$id]);
        if ($cp && floatval($cp['rmax'])>0) {
            $db->query("UPDATE products SET regular_price=?, sale_price=NULL, stock_quantity=?, stock_status=? WHERE id=?",
                [floatval($cp['rmax']), intval($cp['total']??0), intval($cp['total']??0)>0?'in_stock':'out_of_stock', $id]);
        }
        // Save combo-mode addons
        if (isset($_POST['combo_addon_name'])) {
            $productSku = $data['sku'] ?? generateProductSKU($id, $catNameForSku ?? null);
            // Clear existing addons first
            $db->delete('product_variants', "product_id = ? AND option_type = 'addon'", [$id]);
            foreach ($_POST['combo_addon_name'] as $vi => $vname) {
                if (empty($vname) || empty($_POST['combo_addon_value'][$vi])) continue;
                $db->insert('product_variants', [
                    'product_id' => $id, 'variant_name' => sanitize($vname),
                    'variant_value' => sanitize($_POST['combo_addon_value'][$vi]),
                    'option_type' => 'addon',
                    'price_adjustment' => floatval($_POST['combo_addon_price'][$vi] ?? 0),
                    'absolute_price' => null, 'var_regular_price' => null, 'var_sale_price' => null,
                    'stock_quantity' => intval($_POST['combo_addon_stock'][$vi] ?? 0),
                    'manage_stock' => 1,
                    'sku' => sanitize($_POST['combo_addon_sku'][$vi] ?? '') ?: generateVariantSKU($productSku, $_POST['combo_addon_value'][$vi], $vi + 1),
                    'variant_image' => null, 'is_active' => 1, 'is_default' => 0,
                ]);
            }
        }

        // Skip legacy variant save — jump to upsells
        goto save_upsells;
    }

    // ── Save Addons & Variations (Legacy) ──
    if (isset($_POST['opt_name'])) {
        $db->delete('product_variants', 'product_id = ?', [$id]);
        $productSku = $data['sku'] ?? generateProductSKU($id, $catNameForSku ?? null);
        $defaultIdx = intval($_POST['opt_default'] ?? -1);
        // Ensure variant columns exist
        try { $db->query("SELECT variant_image FROM product_variants LIMIT 0"); } catch (\Throwable $e) {
            try { $db->query("ALTER TABLE product_variants ADD COLUMN variant_image VARCHAR(500) DEFAULT NULL AFTER sku"); } catch (\Throwable $e2) {}
        }
        try { $db->query("SELECT var_regular_price FROM product_variants LIMIT 0"); } catch (\Throwable $e) {
            try { $db->query("ALTER TABLE product_variants ADD COLUMN var_regular_price DECIMAL(12,2) DEFAULT NULL AFTER absolute_price, ADD COLUMN var_sale_price DECIMAL(12,2) DEFAULT NULL AFTER var_regular_price, ADD COLUMN manage_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER stock_quantity"); } catch (\Throwable $e2) {}
        }
        try { $db->query("ALTER TABLE product_variants ADD COLUMN weight_per_unit DECIMAL(10,4) DEFAULT NULL AFTER stock_quantity"); } catch (\Throwable $e) {}
        try { $db->query("ALTER TABLE products ADD COLUMN combined_stock TINYINT(1) DEFAULT 0 AFTER manage_stock"); } catch (\Throwable $e) {}
        $isVariableProduct = ($data['product_type'] ?? 'simple') === 'variable';
        foreach ($_POST['opt_name'] as $vi => $vname) {
            if (empty($vname) || empty($_POST['opt_value'][$vi])) continue;
            $optType = $_POST['opt_type'][$vi] ?? 'addon';
            $varSku = sanitize($_POST['opt_sku'][$vi] ?? '');
            if (!$varSku) $varSku = generateVariantSKU($productSku, $_POST['opt_value'][$vi], $vi + 1);
            $varImage = sanitize($_POST['opt_image'][$vi] ?? '');

            // Calculate absolute_price (effective selling price)
            $varRegPrice = floatval($_POST['opt_var_regular'][$vi] ?? 0);
            $varSalePrice = floatval($_POST['opt_var_sale'][$vi] ?? 0);
            $legacyAbsPrice = floatval($_POST['opt_abs_price'][$vi] ?? 0);
            if ($optType === 'variation') {
                if ($isVariableProduct && $varRegPrice > 0) {
                    // Variable product: compute absolute from regular/sale
                    $absPrice = ($varSalePrice > 0 && $varSalePrice < $varRegPrice) ? $varSalePrice : $varRegPrice;
                } else {
                    // Simple product with variation: use legacy absolute_price
                    $absPrice = $legacyAbsPrice;
                    $varRegPrice = $legacyAbsPrice;
                    $varSalePrice = 0;
                }
            } else {
                $absPrice = null;
                $varRegPrice = 0;
                $varSalePrice = 0;
            }

            $varManageStock = isset($_POST['opt_manage_stock_' . $vi]) ? 1 : 0;
            // For simple product mode or addons, always manage stock if stock > 0
            if (!$isVariableProduct || $optType === 'addon') $varManageStock = 1;

            $db->insert('product_variants', [
                'product_id' => $id,
                'variant_name' => sanitize($vname),
                'variant_value' => sanitize($_POST['opt_value'][$vi]),
                'option_type' => $optType,
                'price_adjustment' => $optType === 'addon' ? floatval($_POST['opt_price'][$vi] ?? 0) : 0,
                'absolute_price' => $absPrice,
                'var_regular_price' => $optType === 'variation' ? ($varRegPrice ?: null) : null,
                'var_sale_price' => $optType === 'variation' && $varSalePrice > 0 ? $varSalePrice : null,
                'stock_quantity' => intval($_POST['opt_stock'][$vi] ?? 0),
                'manage_stock' => $varManageStock,
                'sku' => $varSku,
                'variant_image' => $varImage ?: null,
                'is_active' => 1,
                'is_default' => ($vi == $defaultIdx) ? 1 : 0,
                'weight_per_unit' => !empty($_POST['opt_weight_per_unit'][$vi]) ? floatval($_POST['opt_weight_per_unit'][$vi]) : null,
            ]);
        }
    }

    // ── Auto-calculate product-level pricing for variable products ──
    if ($data['product_type'] === 'variable' && $id) {
        $varPrices = $db->fetch(
            "SELECT MIN(absolute_price) as sell_min, MAX(absolute_price) as sell_max,
                    MAX(var_regular_price) as reg_max
             FROM product_variants WHERE product_id = ? AND option_type = 'variation' AND is_active = 1 AND absolute_price > 0",
            [$id]
        );
        if ($varPrices && floatval($varPrices['reg_max']) > 0) {
            $db->query(
                "UPDATE products SET regular_price = ?, sale_price = NULL WHERE id = ?",
                [floatval($varPrices['reg_max']), $id]
            );
        }
    }

    save_upsells:
    // ── Save Upsells ──
    try {
        $db->delete('product_upsells', 'product_id = ?', [$id]);
        if (!empty($_POST['upsell_ids'])) {
            $upsellIds = array_filter(array_map('intval', explode(',', $_POST['upsell_ids'])));
            foreach ($upsellIds as $si => $uid) {
                if ($uid && $uid != $id) {
                    $db->insert('product_upsells', ['product_id' => $id, 'upsell_product_id' => $uid, 'sort_order' => $si]);
                }
            }
        }
    } catch (\Throwable $e) {}

    // ── Save Bundles ──
    try {
        $db->delete('product_bundles', 'product_id = ?', [$id]);
        if (isset($_POST['bundle_product_id'])) {
            foreach ($_POST['bundle_product_id'] as $bi => $bpid) {
                $bpid = intval($bpid);
                if (!$bpid || $bpid == $id) continue;
                $db->insert('product_bundles', [
                    'product_id' => $id,
                    'bundle_product_id' => $bpid,
                    'bundle_qty' => intval($_POST['bundle_qty'][$bi] ?? 1),
                    'discount_type' => $_POST['bundle_discount_type'][$bi] ?? 'fixed',
                    'discount_value' => floatval($_POST['bundle_discount_value'][$bi] ?? 0),
                    'sort_order' => $bi,
                    'is_active' => 1,
                ]);
            }
        }
    } catch (\Throwable $e) {}

    // ── Auto-split if variation split mode is enabled ──
    try {
        if (isVariationSplitMode() && $id) {
            ensureVariationSplitColumns();
            $hasVariations = intval($db->fetch(
                "SELECT COUNT(*) as cnt FROM product_variants WHERE product_id = ? AND option_type = 'variation' AND is_active = 1",
                [$id]
            )['cnt'] ?? 0);
            if ($hasVariations > 0) {
                // Re-merge first (cleans up old splits), then split fresh
                mergeProductVariations($id);
                splitProductVariations($id);
            }
        }
    } catch (\Throwable $e) {}

    redirect(adminUrl('pages/products.php?msg=saved'));
}

// ── Load Data ──
$categories = $db->fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY name");
$variants = $id ? $db->fetchAll("SELECT * FROM product_variants WHERE product_id = ? ORDER BY option_type, id", [$id]) : [];
$galleryImages = $id ? $db->fetchAll("SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC", [$id]) : [];

// Load attributes and combinations (new system)
$attributes = $id ? (function() use ($db, $id) { try { return $db->fetchAll("SELECT * FROM product_attributes WHERE product_id = ? ORDER BY sort_order", [$id]); } catch (\Throwable $e) { return []; } })() : [];
$combinations = $id ? (function() use ($db, $id) { try { return $db->fetchAll("SELECT * FROM product_variant_combinations WHERE product_id = ? ORDER BY sort_order", [$id]); } catch (\Throwable $e) { return []; } })() : [];
$variationMode = $product['variation_mode'] ?? 'legacy';
if ($variationMode === 'legacy' && !empty($combinations)) $variationMode = 'combination';

// Ensure product_type column exists
try { $db->query("SELECT product_type FROM products LIMIT 0"); } catch (\Throwable $e) {
    try { $db->query("ALTER TABLE products ADD COLUMN product_type VARCHAR(20) NOT NULL DEFAULT 'simple' AFTER slug"); } catch (\Throwable $e2) {}
}

// Determine product type (auto-detect for legacy products)
$productType = 'simple';
if ($product) {
    $productType = $product['product_type'] ?? 'simple';
    // Auto-detect: if product has variation-type variants and type is still 'simple', suggest variable
    if ($productType === 'simple') {
        $hasVarType = false;
        foreach ($variants as $v) {
            if (($v['option_type'] ?? '') === 'variation') { $hasVarType = true; break; }
        }
        if ($hasVarType) $productType = 'variable';
    }
}

// Load upsells
$upsells = [];
try {
    if ($id) {
        $upsells = $db->fetchAll(
            "SELECT p.id, p.name, p.name_bn, p.featured_image, p.regular_price, p.sale_price 
             FROM product_upsells pu JOIN products p ON pu.upsell_product_id = p.id 
             WHERE pu.product_id = ? ORDER BY pu.sort_order", [$id]
        );
    }
} catch (\Throwable $e) {}

// Load bundles
$bundles = [];
try {
    if ($id) {
        $bundles = $db->fetchAll(
            "SELECT pb.*, p.name, p.name_bn, p.featured_image, p.regular_price, p.sale_price 
             FROM product_bundles pb JOIN products p ON pb.bundle_product_id = p.id 
             WHERE pb.product_id = ? ORDER BY pb.sort_order", [$id]
        );
    }
} catch (\Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center gap-3 mb-6">
    <a href="<?= adminUrl('pages/products.php') ?>" class="p-2 rounded-lg hover:bg-gray-100"><i class="fas fa-arrow-left text-gray-500"></i></a>
    <h3 class="text-xl font-bold text-gray-800"><?= $pageTitle ?></h3>
    <?php if ($product): ?><a href="<?= url('product/' . $product['slug']) ?>" target="_blank" class="text-blue-500 text-sm hover:underline ml-2">↗ View</a><?php endif; ?>
</div>

<?php
// ── Split product notices ──
if ($product) {
    try { ensureVariationSplitColumns(); } catch (\Throwable $e) {}
    $__isChild = !empty($product['parent_product_id']);
    $__childCount = 0;
    if (!$__isChild) {
        $__childCount = intval($db->fetch("SELECT COUNT(*) as cnt FROM products WHERE parent_product_id = ?", [$id])['cnt'] ?? 0);
    }
    if ($__isChild): ?>
    <div class="bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-3 mb-4 text-sm flex items-center gap-3">
        <span class="text-2xl">📦</span>
        <div>
            <p class="text-indigo-800 font-medium">This is a split variation product <span class="bg-indigo-200 px-2 py-0.5 rounded text-xs"><?= e($product['variant_label'] ?? '') ?></span></p>
            <p class="text-indigo-600 text-xs mt-0.5">Parent product: #<?= intval($product['parent_product_id']) ?> · Changes here only affect this variation. To edit all variations, edit the parent product and re-save.</p>
        </div>
    </div>
    <?php elseif ($__childCount > 0): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-4 text-sm flex items-center gap-3">
        <span class="text-2xl">🔗</span>
        <div>
            <p class="text-amber-800 font-medium">This product has been split into <?= $__childCount ?> separate variation products</p>
            <p class="text-amber-600 text-xs mt-0.5">This parent is currently hidden from the shop. Saving will re-split all variations with updated data. Switch to "Grouped" mode in <a href="<?= adminUrl('pages/settings.php?tab=checkout') ?>" class="underline font-medium">Settings → Checkout</a> to merge back.</p>
        </div>
    </div>
    <?php endif;
}
?>

<form method="POST" enctype="multipart/form-data" id="productForm" onsubmit="return validateProductForm()">
<input type="hidden" name="variation_mode" id="variationModeInput" value="<?= $variationMode ?>">
<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <!-- Basic Info -->
        <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
            <h4 class="font-semibold text-gray-800">📝 Basic Information</h4>
            <div class="grid md:grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Product Name *</label><input type="text" name="name" value="<?= e($product['name'] ?? '') ?>" required class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Name (বাংলা)</label><input type="text" name="name_bn" value="<?= e($product['name_bn'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
            </div>
            <div class="grid md:grid-cols-3 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">URL Slug</label><input type="text" name="slug" value="<?= e($product['slug'] ?? '') ?>" placeholder="auto-generated" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">SKU</label><input type="text" name="sku" value="<?= e($product['sku'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">Tags (comma sep)</label><input type="text" name="tags" value="<?= e($product['tags'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
            </div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Short Description</label><textarea name="short_description" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"><?= e($product['short_description'] ?? '') ?></textarea></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Full Description</label><textarea name="description" rows="8" class="w-full px-3 py-2 border rounded-lg text-sm"><?= e($product['description'] ?? '') ?></textarea></div>
        </div>

        <!-- Pricing -->
        <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4" id="pricingSection">
            <div class="flex items-center justify-between">
                <h4 class="font-semibold text-gray-800">💰 Pricing</h4>
                <!-- Product Type Toggle -->
                <div class="flex items-center gap-2 bg-gray-100 rounded-lg p-1">
                    <button type="button" id="btnTypeSimple" onclick="setProductType('simple')"
                        class="px-3 py-1.5 rounded-md text-xs font-medium transition-all <?= $productType === 'simple' ? 'bg-white shadow text-blue-700' : 'text-gray-500 hover:text-gray-700' ?>">
                        <i class="fas fa-box mr-1"></i>Simple
                    </button>
                    <button type="button" id="btnTypeVariable" onclick="setProductType('variable')"
                        class="px-3 py-1.5 rounded-md text-xs font-medium transition-all <?= $productType === 'variable' ? 'bg-white shadow text-purple-700' : 'text-gray-500 hover:text-gray-700' ?>">
                        <i class="fas fa-layer-group mr-1"></i>Variable
                    </button>
                </div>
                <input type="hidden" name="product_type" id="productTypeInput" value="<?= $productType ?>">
            </div>

            <!-- Variable mode: info banner -->
            <div id="variablePriceBanner" class="bg-purple-50 border border-purple-200 rounded-lg px-4 py-3 <?= $productType === 'variable' ? '' : 'hidden' ?>">
                <div class="flex items-start gap-3">
                    <i class="fas fa-layer-group text-purple-400 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-medium text-purple-800">Variable Product — Pricing & Stock managed per variation</p>
                        <p class="text-xs text-purple-600 mt-1">Each variation has its own Regular Price, Sale Price, and Stock. Central pricing and inventory are disabled.</p>
                        <div id="variablePricePreview" class="mt-2 text-xs text-purple-700 font-semibold bg-purple-100 inline-block px-2 py-1 rounded hidden"></div>
                    </div>
                </div>
            </div>

            <!-- Simple mode: pricing fields -->
            <div id="simplePricingFields" class="grid md:grid-cols-3 gap-4 <?= $productType === 'variable' ? 'hidden' : '' ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Regular Price (৳) *</label>
                    <input type="number" name="regular_price" id="inputRegularPrice" value="<?= $product['regular_price'] ?? '' ?>" step="0.01" class="w-full px-3 py-2 border rounded-lg text-sm" <?= $productType === 'variable' ? '' : 'required' ?>>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sale Price (৳)</label>
                    <input type="number" name="sale_price" id="inputSalePrice" value="<?= $product['sale_price'] ?? '' ?>" step="0.01" class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cost Price (৳)</label>
                    <input type="number" name="cost_price" value="<?= $product['cost_price'] ?? '' ?>" step="0.01" class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
            </div>
            <!-- Variable mode: only cost price -->
            <div id="variableCostField" class="<?= $productType === 'variable' ? '' : 'hidden' ?>">
                <div class="max-w-xs">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cost Price (৳) <span class="text-xs text-gray-400 font-normal">optional</span></label>
                    <input type="number" name="cost_price_var" value="<?= $product['cost_price'] ?? '' ?>" step="0.01" class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
            </div>
        </div>

        <!-- Inventory -->
        <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4" id="inventorySection">
            <div class="flex items-center justify-between">
                <h4 class="font-semibold text-gray-800">📦 Inventory</h4>
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-600 font-medium whitespace-nowrap">Stock Unit:</label>
                    <select name="stock_unit" class="border rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                        <?php
                        $stockUnits = ['pcs'=>'Pieces (pcs)','kg'=>'Kilogram (kg)','g'=>'Gram (g)','lb'=>'Pound (lb)','dozen'=>'Dozen','box'=>'Box','pack'=>'Pack','set'=>'Set','roll'=>'Roll','litre'=>'Litre (L)','ml'=>'Millilitre (ml)','meter'=>'Meter (m)','yard'=>'Yard','pair'=>'Pair','bundle'=>'Bundle'];
                        $curUnit = $product['stock_unit'] ?? 'pcs';
                        foreach ($stockUnits as $val => $label):
                        ?><option value="<?= $val ?>" <?= $curUnit===$val?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <!-- Variable mode: info -->
            <div id="variableStockBanner" class="bg-purple-50 border border-purple-200 rounded-lg px-3 py-2.5 <?= $productType === 'variable' ? '' : 'hidden' ?>">
                <p class="text-xs text-purple-700"><i class="fas fa-info-circle mr-1"></i>Stock is managed individually per variation below. Enable "Track Stock" on each variation row.</p>
            </div>
            <!-- Simple mode: central stock fields -->
            <div id="simpleStockFields" class="<?= $productType === 'variable' ? 'hidden' : '' ?>">
                <label class="flex items-center gap-2 mb-4"><input type="checkbox" name="manage_stock" value="1" <?= ($product['manage_stock'] ?? 1) ? 'checked' : '' ?> class="rounded"><span class="text-sm">Track stock</span></label>
                <div class="grid md:grid-cols-4 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Stock Qty</label><input type="number" name="stock_quantity" value="<?= $product['stock_quantity'] ?? 0 ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Low Stock Alert</label><input type="number" name="low_stock_threshold" value="<?= $product['low_stock_threshold'] ?? 5 ?>" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Stock Status</label>
                        <select name="stock_status" class="w-full px-3 py-2 border rounded-lg text-sm">
                            <option value="in_stock" <?= ($product['stock_status']??'')=='in_stock'?'selected':'' ?>>In Stock</option>
                            <option value="out_of_stock" <?= ($product['stock_status']??'')=='out_of_stock'?'selected':'' ?>>Out of Stock</option>
                        </select></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Weight (g)</label><input type="number" name="weight" value="<?= $product['weight'] ?? '' ?>" step="0.01" class="w-full px-3 py-2 border rounded-lg text-sm"></div>
                </div>
            </div>
            <!-- Variable mode: weight only -->
            <div id="variableWeightField" class="<?= $productType === 'variable' ? '' : 'hidden' ?>">
                <div class="max-w-xs">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Weight (g)</label>
                    <input type="number" name="weight_var" value="<?= $product['weight'] ?? '' ?>" step="0.01" class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
            </div>
        </div>

        <!-- ═══════ ADDONS & VARIATIONS ═══════ -->
        <div class="bg-white rounded-xl shadow-sm border p-5" id="variationsSection">
            <div class="flex items-center justify-between mb-3">
                <h4 class="font-semibold text-gray-800" id="varSectionTitle">🎨 Addons & Variations</h4>
                <div class="flex items-center gap-2">
                    <!-- Mode Toggle (Variable products only) -->
                    <div id="variationModeToggle" class="flex items-center gap-2 <?= $productType === 'variable' ? '' : 'hidden' ?>">
                        <span class="text-xs text-gray-500">Mode:</span>
                        <div class="flex bg-gray-100 rounded-lg p-0.5">
                            <button type="button" id="btnModeLegacy" onclick="setVariationMode('legacy')"
                                class="px-2.5 py-1 rounded-md text-[11px] font-medium transition-all <?= $variationMode === 'legacy' ? 'bg-white shadow text-blue-700' : 'text-gray-500' ?>">
                                Single
                            </button>
                            <button type="button" id="btnModeCombination" onclick="setVariationMode('combination')"
                                class="px-2.5 py-1 rounded-md text-[11px] font-medium transition-all <?= $variationMode === 'combination' ? 'bg-white shadow text-purple-700' : 'text-gray-500' ?>">
                                <i class="fas fa-th mr-0.5"></i>Combinations
                            </button>
                        </div>
                    </div>
                    <button type="button" id="legacyAddBtn" onclick="addOption()" class="bg-purple-50 text-purple-600 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-purple-100 <?= $variationMode === 'combination' && $productType === 'variable' ? 'hidden' : '' ?>">+ Add Option</button>
                </div>
            </div>

            <!-- ═══════ COMBINATION MODE PANEL ═══════ -->
            <div id="combinationModePanel" class="<?= ($variationMode === 'combination' && $productType === 'variable') ? '' : 'hidden' ?>">
                <!-- Tab Bar -->
                <div class="flex border-b mb-4">
                    <button type="button" onclick="switchComboTab('attributes')" class="combo-tab px-4 py-2 text-sm font-medium border-b-2 border-purple-600 text-purple-700 transition-all" data-tab="attributes"><i class="fas fa-tags mr-1"></i>Attributes</button>
                    <button type="button" onclick="switchComboTab('variations')" class="combo-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 transition-all" data-tab="variations"><i class="fas fa-layer-group mr-1"></i>Variations <span id="comboCountBadge" class="ml-1 bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded-full text-[10px]"><?= count($combinations) ?></span></button>
                    <button type="button" onclick="switchComboTab('addons')" class="combo-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 transition-all" data-tab="addons"><i class="fas fa-puzzle-piece mr-1"></i>Addons</button>
                </div>

                <!-- TAB: Attributes -->
                <div id="comboTabAttributes" class="combo-tab-panel">
                    <p class="text-xs text-gray-400 mb-3">Define product attributes (Frame Color, Lens Color, Size). Check "Used for variations" to include in combination generation.</p>
                    <div id="attributesContainer" class="space-y-3">
                        <?php foreach ($attributes as $ai => $attr):
                            $attrVals = json_decode($attr['attribute_values'], true) ?: [];
                        ?>
                        <div class="attr-row bg-gray-50 rounded-lg border p-3" data-index="<?= $ai ?>">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-medium text-sm text-gray-700 attr-name-display"><?= e($attr['attribute_name']) ?></span>
                                <div class="flex items-center gap-3">
                                    <button type="button" onclick="this.closest('.attr-row').querySelector('.attr-details').classList.toggle('hidden')" class="text-xs text-blue-500 hover:text-blue-700">Expand / Close</button>
                                    <button type="button" onclick="this.closest('.attr-row').remove()" class="text-xs text-red-400 hover:text-red-600">Remove</button>
                                </div>
                            </div>
                            <div class="attr-details">
                                <div class="grid grid-cols-12 gap-3">
                                    <div class="col-span-4"><label class="text-xs text-gray-500">Name:</label><input type="text" name="attr_name[]" value="<?= e($attr['attribute_name']) ?>" class="w-full px-2 py-1.5 border rounded text-sm attr-name-input" oninput="this.closest('.attr-row').querySelector('.attr-name-display').textContent=this.value"></div>
                                    <div class="col-span-8"><label class="text-xs text-gray-500">Value(s): <span class="text-gray-300">separate with |</span></label><input type="text" name="attr_values[]" value="<?= e(implode(' | ', $attrVals)) ?>" class="w-full px-2 py-1.5 border rounded text-sm"></div>
                                </div>
                                <div class="flex items-center gap-4 mt-2">
                                    <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" name="attr_visible[<?= $ai ?>]" value="1" <?= $attr['is_visible'] ? 'checked' : '' ?> class="rounded text-blue-500"><span class="text-xs text-gray-600">Visible on product page</span></label>
                                    <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" name="attr_for_variation[<?= $ai ?>]" value="1" <?= $attr['is_variation'] ? 'checked' : '' ?> class="rounded text-purple-500 attr-for-var-cb"><span class="text-xs text-gray-600 font-medium">Used for variations</span></label>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (empty($attributes)): ?><p id="noAttrMsg" class="text-sm text-gray-400 text-center py-4">No attributes yet. Add attributes like Color, Size, Material.</p><?php endif; ?>
                    <div class="mt-3"><button type="button" onclick="addAttributeRow()" class="bg-blue-50 text-blue-600 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-blue-100">+ Add Attribute</button></div>
                </div>

                <!-- TAB: Variations (Combinations) -->
                <div id="comboTabVariations" class="combo-tab-panel hidden">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs text-gray-400">Each combination has its own price, stock, SKU, and image.</p>
                        <button type="button" onclick="generateCombinations()" class="bg-purple-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-purple-700"><i class="fas fa-sync-alt mr-1"></i>Generate variations</button>
                    </div>
                    <!-- Bulk actions -->
                    <div id="comboBulkActions" class="bg-gray-50 rounded-lg p-2 mb-3 flex items-center gap-3 flex-wrap <?= !empty($combinations) ? '' : 'hidden' ?>">
                        <span class="text-xs text-gray-500 font-medium">Bulk set:</span>
                        <div class="flex items-center gap-1"><input type="number" id="bulkRegPrice" class="w-20 px-2 py-1 border rounded text-xs" placeholder="Regular ৳" step="0.01"><button type="button" onclick="bulkSetField('regular_price')" class="text-xs bg-purple-100 text-purple-700 hover:bg-purple-200 px-2 py-1 rounded font-medium">Apply</button></div>
                        <div class="flex items-center gap-1"><input type="number" id="bulkSalePrice" class="w-20 px-2 py-1 border rounded text-xs" placeholder="Sale ৳" step="0.01"><button type="button" onclick="bulkSetField('sale_price')" class="text-xs bg-purple-100 text-purple-700 hover:bg-purple-200 px-2 py-1 rounded font-medium">Apply</button></div>
                        <div class="flex items-center gap-1"><input type="number" id="bulkCostPrice" class="w-20 px-2 py-1 border rounded text-xs" placeholder="Cost ৳" step="0.01"><button type="button" onclick="bulkSetField('cost_price')" class="text-xs bg-purple-100 text-purple-700 hover:bg-purple-200 px-2 py-1 rounded font-medium">Apply</button></div>
                        <div class="flex items-center gap-1"><input type="number" id="bulkStock" class="w-16 px-2 py-1 border rounded text-xs" placeholder="Stock"><button type="button" onclick="bulkSetField('stock')" class="text-xs bg-purple-100 text-purple-700 hover:bg-purple-200 px-2 py-1 rounded font-medium">Apply</button></div>
                    </div>
                    <div id="combinationsContainer" class="space-y-2">
                        <?php foreach ($combinations as $ci => $combo):
                            $comboData = json_decode($combo['combination_key'], true) ?: [];
                            $cImg = $combo['variant_image'] ?? '';
                            $cImgUrl = $cImg ? (SITE_URL . '/uploads/products/' . $cImg) : '';
                        ?>
                        <div class="combo-row bg-purple-50/40 border border-purple-200 rounded-lg p-3" data-index="<?= $ci ?>">
                            <div class="flex gap-3 items-start">
                                <div class="flex-shrink-0">
                                    <div class="combo-img-wrap relative" style="width:48px;">
                                        <div class="w-12 h-12 rounded-lg border-2 <?= $cImg ? 'border-blue-300 bg-blue-50' : 'border-dashed border-gray-300' ?> flex items-center justify-center cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition overflow-hidden" onclick="pickComboImage(this.closest('.combo-img-wrap'))">
                                            <?php if ($cImg): ?><img src="<?= $cImgUrl ?>" class="w-full h-full object-cover combo-img-preview">
                                            <?php else: ?><img src="" class="w-full h-full object-cover combo-img-preview hidden"><i class="fas fa-image text-gray-300 text-sm combo-img-placeholder"></i><?php endif; ?>
                                        </div>
                                        <input type="hidden" name="combo_image[]" value="<?= e($cImg) ?>">
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <input type="hidden" name="combo_key[]" value="<?= e($combo['combination_key']) ?>">
                                    <input type="hidden" name="combo_label[]" value="<?= e($combo['combination_label']) ?>">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm font-bold text-purple-800"><?php foreach ($comboData as $ak => $av): ?><span class="inline-flex items-center bg-purple-100 text-purple-700 px-2 py-0.5 rounded text-[11px] mr-1"><?= e($ak) ?>: <strong class="ml-0.5"><?= e($av) ?></strong></span><?php endforeach; ?></span>
                                        <div class="flex items-center gap-2">
                                            <label class="flex items-center gap-1 cursor-pointer"><input type="radio" name="combo_default" value="<?= $ci ?>" <?= ($combo['is_default'] ?? 0) ? 'checked' : '' ?> class="accent-purple-600"><span class="text-[10px] text-gray-400">Default</span></label>
                                            <button type="button" onclick="removeComboRow(this)" class="text-red-400 hover:text-red-600 text-sm">✕</button>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-12 gap-2">
                                        <div class="col-span-3"><label class="text-[10px] text-gray-500">Regular ৳ *</label><input type="number" name="combo_regular_price[]" value="<?= $combo['regular_price'] ?>" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm combo-reg-price" oninput="recalcComboPreview()"></div>
                                        <div class="col-span-2"><label class="text-[10px] text-gray-500">Sale ৳</label><input type="number" name="combo_sale_price[]" value="<?= $combo['sale_price'] ?>" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm combo-sale-price" oninput="recalcComboPreview()"></div>
                                        <div class="col-span-2"><label class="text-[10px] text-gray-500">Cost ৳</label><input type="number" name="combo_cost_price[]" value="<?= $combo['cost_price'] ?>" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm combo-cost-price"></div>
                                        <div class="col-span-2"><label class="text-[10px] text-gray-500">Stock</label><input type="number" name="combo_stock[]" value="<?= $combo['stock_quantity'] ?>" class="w-full px-2 py-1.5 border rounded text-sm combo-stock-input" oninput="recalcTotalStock()"></div>
                                        <div class="col-span-3"><label class="text-[10px] text-gray-500">SKU</label><input type="text" name="combo_sku[]" value="<?= e($combo['sku'] ?? '') ?>" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="Auto"></div>
                                    </div>
                                    <div class="flex items-center gap-4 mt-1.5">
                                        <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" name="combo_manage_stock_<?= $ci ?>" value="1" <?= ($combo['manage_stock'] ?? 1) ? 'checked' : '' ?> class="rounded text-purple-500"><span class="text-[10px] text-gray-500">Track Stock</span></label>
                                        <div class="flex items-center gap-1"><label class="text-[10px] text-gray-500">Weight:</label><input type="number" name="combo_weight[]" value="<?= $combo['weight'] ?>" step="0.01" class="w-16 px-1.5 py-0.5 border rounded text-[11px]" placeholder="g"></div>
                                        <div class="combo-price-preview text-[11px] ml-auto"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (empty($combinations)): ?><p id="noComboMsg" class="text-sm text-gray-400 text-center py-6">No combinations yet. Add attributes first, then click "Generate variations".</p><?php endif; ?>
                </div>

                <!-- TAB: Addons (combo mode) -->
                <div id="comboTabAddons" class="combo-tab-panel hidden">
                    <p class="text-xs text-gray-400 mb-3">Addons add extra cost to the variation price (e.g. gift wrap +৳50).</p>
                    <div id="comboAddonsContainer" class="space-y-2">
                        <?php foreach ($variants as $v): if (($v['option_type'] ?? 'addon') !== 'addon') continue; ?>
                        <div class="addon-row bg-gray-50 rounded-lg border p-3">
                            <div class="grid grid-cols-12 gap-2 items-end">
                                <div class="col-span-3"><label class="text-xs text-gray-500">Name</label><input type="text" name="combo_addon_name[]" value="<?= e($v['variant_name']) ?>" class="w-full px-2 py-1.5 border rounded text-sm"></div>
                                <div class="col-span-3"><label class="text-xs text-gray-500">Value</label><input type="text" name="combo_addon_value[]" value="<?= e($v['variant_value']) ?>" class="w-full px-2 py-1.5 border rounded text-sm"></div>
                                <div class="col-span-2"><label class="text-xs text-gray-500">Price ±৳</label><input type="number" name="combo_addon_price[]" value="<?= $v['price_adjustment'] ?>" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm"></div>
                                <div class="col-span-2"><label class="text-xs text-gray-500">Stock</label><input type="number" name="combo_addon_stock[]" value="<?= $v['stock_quantity'] ?>" class="w-full px-2 py-1.5 border rounded text-sm"></div>
                                <div class="col-span-1"><label class="text-xs text-gray-500">SKU</label><input type="text" name="combo_addon_sku[]" value="<?= e($v['sku'] ?? '') ?>" class="w-full px-2 py-1.5 border rounded text-sm"></div>
                                <div class="col-span-1 text-center pt-5"><button type="button" onclick="this.closest('.addon-row').remove()" class="text-red-400 hover:text-red-600 text-lg">✕</button></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addComboAddon()" class="mt-3 bg-blue-50 text-blue-600 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-blue-100">+ Add Addon</button>
                </div>
            </div>

            <!-- ═══════ LEGACY MODE PANEL ═══════ -->
            <div id="legacyModePanel" class="<?= ($variationMode === 'combination' && $productType === 'variable') ? 'hidden' : '' ?>">
            <p class="text-xs text-gray-400 mb-4" id="varSectionHint">
                <strong>Addon</strong> = adds cost to base price (e.g. gift wrap +৳50) &nbsp;|&nbsp; 
                <strong>Variation</strong> = replaces base price entirely (e.g. Size L = ৳899)
            </p>
            <div id="optionsContainer" class="space-y-3">
                <?php foreach ($variants as $vi => $v):
                    $vImg = $v['variant_image'] ?? '';
                    $vImgUrl = $vImg ? (SITE_URL . '/uploads/products/' . $vImg) : '';
                    $isVar = ($v['option_type'] ?? 'addon') === 'variation';
                    $vRegP = floatval($v['var_regular_price'] ?? $v['absolute_price'] ?? 0);
                    $vSaleP = floatval($v['var_sale_price'] ?? 0);
                    $vManageStock = intval($v['manage_stock'] ?? 1);
                ?>
                <div class="option-row rounded-lg border <?= $isVar && $productType === 'variable' ? 'border-purple-200 bg-purple-50/30' : 'border-gray-200 bg-gray-50' ?> p-3" data-index="<?= $vi ?>">
                    <!-- Row 1: Identity + Core Fields -->
                    <div class="flex gap-3 items-start">
                        <!-- Variant Image -->
                        <div class="flex-shrink-0">
                            <label class="text-xs text-gray-500 block mb-1">Image</label>
                            <div class="opt-img-wrap relative" style="width:56px;">
                                <div class="w-14 h-14 rounded-lg border-2 <?= $vImg ? 'border-blue-300 bg-blue-50' : 'border-dashed border-gray-300' ?> flex items-center justify-center cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition overflow-hidden"
                                     onclick="pickVariantImage(this.closest('.opt-img-wrap'))">
                                    <?php if ($vImg): ?>
                                    <img src="<?= $vImgUrl ?>" class="w-full h-full object-cover opt-img-preview">
                                    <?php else: ?>
                                    <img src="" class="w-full h-full object-cover opt-img-preview hidden">
                                    <i class="fas fa-image text-gray-300 text-lg opt-img-placeholder"></i>
                                    <?php endif; ?>
                                </div>
                                <button type="button" onclick="clearVariantImage(this.closest('.opt-img-wrap'))" 
                                    class="opt-img-remove w-full mt-1 text-[9px] text-red-500 hover:text-red-700 hover:bg-red-50 rounded py-0.5 transition <?= $vImg ? '' : 'hidden' ?>">
                                    <i class="fas fa-trash-alt mr-0.5"></i>Remove
                                </button>
                                <input type="hidden" name="opt_image[]" value="<?= e($vImg) ?>">
                            </div>
                        </div>
                        <!-- Fields -->
                        <div class="flex-1 min-w-0">
                            <div class="grid grid-cols-12 gap-2 items-end">
                                <div class="col-span-2">
                                    <label class="text-xs text-gray-500">Type</label>
                                    <select name="opt_type[]" class="w-full px-2 py-1.5 border rounded text-xs opt-type-select" onchange="toggleOptionFields(this)">
                                        <option value="addon" <?= !$isVar ? 'selected' : '' ?>>Addon</option>
                                        <option value="variation" <?= $isVar ? 'selected' : '' ?>>Variation</option>
                                    </select>
                                </div>
                                <div class="col-span-2"><label class="text-xs text-gray-500">Name</label><input type="text" name="opt_name[]" value="<?= e($v['variant_name']) ?>" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="Color, Size..."></div>
                                <div class="col-span-2"><label class="text-xs text-gray-500">Value</label><input type="text" name="opt_value[]" value="<?= e($v['variant_value']) ?>" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="Red, XL..."></div>
                                <!-- Addon: Price adjustment -->
                                <div class="col-span-2 opt-addon-price <?= $isVar ? 'hidden' : '' ?>"><label class="text-xs text-gray-500">Price ±</label><input type="number" name="opt_price[]" value="<?= $v['price_adjustment'] ?>" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm" <?= $isVar ? 'disabled' : '' ?>></div>
                                <!-- Simple variation: single sell price -->
                                <div class="col-span-2 opt-var-simple-price <?= (!$isVar || $productType === 'variable') ? 'hidden' : '' ?>"><label class="text-xs text-gray-500">Sell Price ৳</label><input type="number" name="opt_abs_price[]" value="<?= $v['absolute_price'] ?? 0 ?>" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm" oninput="recalcVariablePrice()" <?= (!$isVar || $productType === 'variable') ? 'disabled' : '' ?>></div>
                                <!-- Variable product variation: Regular + Sale -->
                                <div class="col-span-2 opt-var-reg-price <?= (!$isVar || $productType !== 'variable') ? 'hidden' : '' ?>"><label class="text-xs text-gray-500">Regular ৳ <span class="text-red-400">*</span></label><input type="number" name="opt_var_regular[]" value="<?= $vRegP ?: '' ?>" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm" oninput="recalcVariablePrice()" placeholder="1000" <?= (!$isVar || $productType !== 'variable') ? 'disabled' : '' ?>></div>
                                <div class="col-span-2 opt-var-sale-price <?= (!$isVar || $productType !== 'variable') ? 'hidden' : '' ?>"><label class="text-xs text-gray-500">Sale ৳ <span class="text-gray-300 text-[10px]">optional</span></label><input type="number" name="opt_var_sale[]" value="<?= $vSaleP ?: '' ?>" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm" oninput="recalcVariablePrice()" placeholder="800" <?= (!$isVar || $productType !== 'variable') ? 'disabled' : '' ?>></div>
                                <!-- Stock (simple mode) -->
                                <div class="col-span-1 opt-stock-simple <?= ($isVar && $productType === 'variable') ? 'hidden' : '' ?>"><label class="text-xs text-gray-500">Stock</label><input type="number" name="opt_stock[]" value="<?= $v['stock_quantity'] ?>" class="w-full px-2 py-1.5 border rounded text-sm" <?= ($isVar && $productType === 'variable') ? 'disabled' : '' ?>></div>
                                <!-- SKU (simple mode) -->
                                <div class="col-span-1 opt-sku-simple <?= ($isVar && $productType === 'variable') ? 'hidden' : '' ?>"><label class="text-xs text-gray-500">SKU</label><input type="text" name="opt_sku[]" value="<?= e($v['sku'] ?? '') ?>" class="w-full px-2 py-1.5 border rounded text-sm" <?= ($isVar && $productType === 'variable') ? 'disabled' : '' ?>></div>
                                <div class="col-span-1 text-center pt-4">
                                    <label class="text-xs text-gray-400 block mb-0.5">Default</label>
                                    <input type="radio" name="opt_default" value="<?= $vi ?>" <?= ($v['is_default'] ?? 0) ? 'checked' : '' ?> class="accent-blue-600">
                                </div>
                                <div class="col-span-1 text-center pt-5"><button type="button" onclick="removeOptionRow(this)" class="text-red-400 hover:text-red-600 text-lg">✕</button></div>
                            </div>
                            <!-- Row 2: Variable product extras (Stock + SKU in expanded layout) -->
                            <div class="opt-var-extras mt-2 pt-2 border-t border-purple-100 <?= ($isVar && $productType === 'variable') ? '' : 'hidden' ?>">
                                <div class="flex items-center gap-4 flex-wrap">
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" name="opt_manage_stock_<?= $vi ?>" value="1" class="rounded text-purple-600 opt-manage-stock-cb" <?= $vManageStock ? 'checked' : '' ?> onchange="toggleVarStock(this)">
                                        <span class="text-xs font-medium text-gray-600">Track Stock</span>
                                    </label>
                                    <div class="opt-var-stock-field flex items-center gap-2 <?= $vManageStock ? '' : 'opacity-40 pointer-events-none' ?>">
                                        <label class="text-xs text-gray-500">Qty:</label>
                                        <input type="number" name="opt_stock[]" value="<?= $v['stock_quantity'] ?>" class="w-20 px-2 py-1 border rounded text-sm opt-var-stock-input" <?= (!$isVar || $productType !== 'variable') ? 'disabled' : ($vManageStock ? '' : 'disabled') ?>>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <label class="text-xs text-gray-500">SKU:</label>
                                        <input type="text" name="opt_sku[]" value="<?= e($v['sku'] ?? '') ?>" class="w-28 px-2 py-1 border rounded text-sm" <?= (!$isVar || $productType !== 'variable') ? 'disabled' : '' ?>>
                                    </div>
                                    <div class="flex items-center gap-2" title="How much stock to deduct from inventory per unit ordered. For kg products: e.g. 0.25 = 250g. For normal: leave 0 or empty (deducts 1 per unit).">
                                        <label class="text-xs text-orange-500">⚖ Deduction:</label>
                                        <input type="number" name="opt_weight_per_unit[]" value="<?= floatval($v['weight_per_unit'] ?? 0) ?: '' ?>" step="0.0001" min="0" placeholder="auto" class="w-24 px-2 py-1 border rounded text-sm" <?= (!$isVar || $productType !== 'variable') ? 'disabled' : '' ?>>
                                    </div>
                                    <div class="opt-var-discount-preview text-xs ml-auto"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($variants)): ?><p id="noOptMsg" class="text-sm text-gray-400 text-center py-4">No addons or variations yet.</p><?php endif; ?>
            </div><!-- /legacyModePanel -->
        </div>

        <!-- ═══════ UPSELL PRODUCTS ═══════ -->
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-1">🔥 Upsell Products</h4>
            <p class="text-xs text-gray-400 mb-4">Shown to customers during checkout as "You may also like"</p>
            <input type="hidden" name="upsell_ids" id="upsellIds" value="<?= implode(',', array_column($upsells, 'id')) ?>">
            <div class="relative mb-3">
                <input type="text" id="upsellSearch" placeholder="Search products to add..." class="w-full px-3 py-2 border rounded-lg text-sm pl-9" oninput="searchProducts(this.value, 'upsell')">
                <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
                <div id="upsellDropdown" class="hidden absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto"></div>
            </div>
            <div id="upsellList" class="flex flex-wrap gap-2">
                <?php foreach ($upsells as $u): ?>
                <div class="upsell-tag flex items-center gap-2 bg-blue-50 border border-blue-200 rounded-lg px-3 py-1.5" data-id="<?= $u['id'] ?>">
                    <img src="<?= $u['featured_image'] ? imgSrc('products', $u['featured_image']) : asset('img/default-product.svg') ?>" class="w-8 h-8 rounded object-cover">
                    <span class="text-xs font-medium"><?= e($u['name_bn'] ?: $u['name']) ?></span>
                    <button type="button" onclick="removeUpsell(this)" class="text-red-400 hover:text-red-600 text-xs">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ═══════ BUNDLE PRODUCTS ═══════ -->
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <div class="flex items-center justify-between mb-1">
                <h4 class="font-semibold text-gray-800">📦 Bundle Deal</h4>
                <button type="button" onclick="showBundleSearch()" class="bg-green-50 text-green-600 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-green-100">+ Add Product</button>
            </div>
            <p class="text-xs text-gray-400 mb-3">Create "Buy Together & Save" bundle deals</p>
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-600 mb-1">Bundle Name <span class="text-gray-400">(shown in cart & checkout)</span></label>
                <input type="text" name="bundle_name" value="<?= e($product['bundle_name'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="e.g. চিয়া সিড কম্বো প্যাক">
            </div>
            <div class="relative mb-3 hidden" id="bundleSearchWrap">
                <input type="text" id="bundleSearch" placeholder="Search products to bundle..." class="w-full px-3 py-2 border rounded-lg text-sm pl-9" oninput="searchProducts(this.value, 'bundle')">
                <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
                <div id="bundleDropdown" class="hidden absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto"></div>
            </div>
            <div id="bundleContainer" class="space-y-2">
                <?php foreach ($bundles as $b):
                    $bSelling = ($b['sale_price'] && $b['sale_price'] > 0 && $b['sale_price'] < $b['regular_price']) 
                        ? floatval($b['sale_price']) : floatval($b['regular_price']);
                ?>
                <div class="bundle-row bg-green-50 rounded-lg p-3" data-price="<?= $bSelling ?>">
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <input type="hidden" name="bundle_product_id[]" value="<?= $b['bundle_product_id'] ?>">
                        <div class="col-span-4 flex items-center gap-2">
                            <img src="<?= $b['featured_image'] ? imgSrc('products', $b['featured_image']) : asset('img/default-product.svg') ?>" class="w-10 h-10 rounded object-cover">
                            <div class="min-w-0">
                                <span class="text-sm font-medium truncate block"><?= e($b['name_bn'] ?: $b['name']) ?></span>
                                <span class="text-[10px] text-gray-400">Current: ৳<?= number_format($bSelling) ?></span>
                            </div>
                        </div>
                        <div class="col-span-2"><label class="text-xs text-gray-500">Qty</label><input type="number" name="bundle_qty[]" value="<?= $b['bundle_qty'] ?>" min="1" class="w-full px-2 py-1.5 border rounded text-sm bd-calc" oninput="calcBundleRow(this)"></div>
                        <div class="col-span-2"><label class="text-xs text-gray-500">Discount Type</label>
                            <select name="bundle_discount_type[]" class="w-full px-2 py-1.5 border rounded text-xs bd-calc" onchange="calcBundleRow(this)">
                                <option value="fixed" <?= $b['discount_type'] === 'fixed' ? 'selected' : '' ?>>Fixed ৳</option>
                                <option value="percentage" <?= $b['discount_type'] === 'percentage' ? 'selected' : '' ?>>Percent %</option>
                            </select></div>
                        <div class="col-span-3"><label class="text-xs text-gray-500">Discount</label><input type="number" name="bundle_discount_value[]" value="<?= $b['discount_value'] ?>" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm bd-calc" oninput="calcBundleRow(this)"></div>
                        <div class="col-span-1 text-center"><button type="button" onclick="this.closest('.bundle-row').remove();calcBundleSummary()" class="text-red-400 hover:text-red-600">✕</button></div>
                    </div>
                    <div class="bd-preview mt-2 pt-2 border-t border-green-200 flex items-center justify-between text-xs"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($bundles)): ?><p id="noBundleMsg" class="text-sm text-gray-400 text-center py-4">No bundle products.</p><?php endif; ?>
            <div id="bundleSummary" class="hidden mt-3 p-3 bg-green-100 rounded-lg text-sm"></div>
        </div>

        <!-- Images -->
        <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
            <h4 class="font-semibold text-gray-800">🖼️ Product Images</h4>
            <?php if (!empty($galleryImages)): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Current Images <span class="text-xs text-gray-400">(hover for actions, ★ = featured)</span></label>
                <div class="flex gap-3 flex-wrap" id="currentImages">
                    <?php foreach ($galleryImages as $gi): ?>
                    <div class="relative group" id="img-<?= $gi['id'] ?>">
                        <img src="<?= imgSrc('products', $gi['image_path']) ?>" class="w-24 h-24 object-cover rounded-lg border-2 <?= $gi['is_primary'] ? 'border-blue-500' : 'border-gray-200' ?>" onerror="this.src='<?= asset('img/default-product.svg') ?>'">
                        <?php if ($gi['is_primary']): ?><span class="absolute -top-1 -left-1 bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded-full">★</span><?php endif; ?>
                        <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition rounded-lg flex items-center justify-center gap-1">
                            <button type="button" onclick="setPrimary(<?= $gi['id'] ?>)" class="p-1 bg-white rounded text-yellow-500 hover:text-yellow-600" title="Set featured">★</button>
                            <button type="button" onclick="delImg(<?= $gi['id'] ?>)" class="p-1 bg-white rounded text-red-500 hover:text-red-600" title="Delete">✕</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="flex gap-3">
                <div class="flex-1">
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-blue-400 transition cursor-pointer" onclick="document.getElementById('galleryInput').click()">
                        <p class="text-gray-400 text-sm">📷 Click to upload images</p>
                    </div>
                    <input type="file" name="gallery_images[]" accept="image/*" multiple class="hidden" id="galleryInput" onchange="previewUp(this)">
                </div>
                <button type="button" onclick="openMediaLibrary(onMediaFilesSelected, {multiple:true, folder:'products', uploadFolder:'products'})" 
                        class="px-4 py-2 border-2 border-dashed border-blue-300 rounded-lg text-sm text-blue-600 hover:bg-blue-50 hover:border-blue-400 transition font-medium self-stretch flex items-center gap-2">
                    <i class="fas fa-photo-video"></i> Media Library
                </button>
            </div>
            <input type="hidden" name="gallery_from_media" id="galleryFromMedia" value="">
            <div id="uploadPreviews" class="flex gap-2 flex-wrap"></div>
            
            <!-- Image Slideshow Toggle -->
            <div class="flex items-center gap-3 pt-3 border-t border-gray-100">
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="image_slideshow" value="1" class="sr-only peer" <?= ($product['image_slideshow'] ?? 0) ? 'checked' : '' ?>>
                    <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-500"></div>
                </label>
                <div>
                    <span class="text-sm font-medium text-gray-700">Auto-Slideshow</span>
                    <p class="text-xs text-gray-400">Automatically cycle through product images on the product page</p>
                </div>
            </div>
        </div>



        <!-- SEO -->
        <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
            <h4 class="font-semibold text-gray-800">🔍 SEO — Search Engine Optimization</h4>
            
            <!-- Google Preview -->
            <div class="bg-gray-50 rounded-lg p-4 border border-dashed border-gray-300">
                <p class="text-[10px] text-gray-400 mb-2 uppercase tracking-wider font-semibold">Google Preview</p>
                <div>
                    <div id="seo-preview-title" class="text-[#1a0dab] text-lg font-medium truncate" style="font-family:arial"><?= e($product['meta_title'] ?? $product['name'] ?? 'Product Name') ?></div>
                    <div id="seo-preview-url" class="text-[#006621] text-sm truncate" style="font-family:arial"><?= SITE_URL ?>/product/<?= e($product['slug'] ?? 'product-slug') ?></div>
                    <div id="seo-preview-desc" class="text-[#545454] text-sm mt-0.5" style="font-family:arial;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?= e($product['meta_description'] ?? mb_substr(strip_tags($product['short_description'] ?? $product['description'] ?? ''), 0, 160)) ?></div>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Meta Title <span class="text-gray-400 font-normal" id="seo-title-count"></span></label>
                <input type="text" name="meta_title" value="<?= e($product['meta_title'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Leave empty for auto: Product Name | Site Name" oninput="updateSeoPreview()">
                <p class="text-xs text-gray-400 mt-1">Ideal: 50-60 characters. Shows as the blue link in Google.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Meta Description <span class="text-gray-400 font-normal" id="seo-desc-count"></span></label>
                <textarea name="meta_description" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Leave empty for auto: uses short description" oninput="updateSeoPreview()"><?= e($product['meta_description'] ?? '') ?></textarea>
                <p class="text-xs text-gray-400 mt-1">Ideal: 150-160 characters. Shows below the title in Google.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Focus Keyword</label>
                <input type="text" name="focus_keyword" value="<?= e($product['focus_keyword'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="e.g. সরিষার তেল, organic mustard oil">
                <p class="text-xs text-gray-400 mt-1">Reminder for yourself — what keyword should this product rank for?</p>
            </div>
            
            <script>
            function updateSeoPreview(){
                var t=document.querySelector('[name="meta_title"]').value||document.querySelector('[name="name"]')?.value||'Product';
                var d=document.querySelector('[name="meta_description"]').value||'';
                document.getElementById('seo-preview-title').textContent=t;
                if(d)document.getElementById('seo-preview-desc').textContent=d;
                var tc=document.getElementById('seo-title-count');
                var dc=document.getElementById('seo-desc-count');
                if(tc)tc.textContent='('+t.length+'/60)';
                if(dc)dc.textContent='('+d.length+'/160)';
                if(tc)tc.style.color=t.length>60?'#ef4444':'#9ca3af';
                if(dc)dc.style.color=d.length>160?'#ef4444':'#9ca3af';
            }
            document.addEventListener('DOMContentLoaded',updateSeoPreview);
            </script>
        </div>
    </div>

    <!-- Right Column -->
    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border p-5 space-y-4">
            <h4 class="font-semibold text-gray-800">Publish</h4>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" <?= ($product['is_active'] ?? 1) ? 'checked' : '' ?> class="rounded text-blue-600"><span class="text-sm">Active</span></label>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_featured" value="1" <?= ($product['is_featured'] ?? 0) ? 'checked' : '' ?> class="rounded text-purple-600"><span class="text-sm">Featured</span></label>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_on_sale" value="1" <?= ($product['is_on_sale'] ?? 0) ? 'checked' : '' ?> class="rounded text-green-600"><span class="text-sm">On Sale</span></label>
            <label class="flex items-center gap-2"><input type="checkbox" name="hide_header" value="1" <?= ($product['hide_header'] ?? 0) ? 'checked' : '' ?> class="rounded text-gray-600"><span class="text-sm">Hide Header <span class="text-xs text-gray-400">(LP style)</span></span></label>
            <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">✓ <?= $product ? 'Update' : 'Create' ?> Product</button>
        </div>

        <!-- Customer Upload Setting -->
        <div class="bg-white rounded-xl shadow-sm border p-5 space-y-3">
            <h4 class="font-semibold text-gray-800 flex items-center gap-2"><i class="fas fa-cloud-upload-alt text-purple-500"></i>Customer Upload</h4>
            <p class="text-xs text-gray-400">Allow customers to upload an image/document (e.g. prescription, face photo) when ordering this product.</p>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="require_customer_upload" value="1" <?= ($product['require_customer_upload'] ?? 0) ? 'checked' : '' ?> class="rounded text-purple-600" id="custUploadToggle" onchange="document.getElementById('custUploadOptions').classList.toggle('hidden', !this.checked)">
                <span class="text-sm font-medium">Enable Customer Upload</span>
            </label>
            <div id="custUploadOptions" class="space-y-3 <?= ($product['require_customer_upload'] ?? 0) ? '' : 'hidden' ?>">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Upload Label (shown to customer)</label>
                    <input type="text" name="customer_upload_label" value="<?= e($product['customer_upload_label'] ?? 'আপনার ছবি/ডকুমেন্ট আপলোড করুন') ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Upload your prescription">
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="customer_upload_required" value="1" <?= ($product['customer_upload_required'] ?? 0) ? 'checked' : '' ?> class="rounded text-red-500">
                    <span class="text-xs text-gray-600">Make upload mandatory (cannot order without it)</span>
                </div>
            </div>
        </div>
        <!-- Store Credit Setting -->
        <div class="bg-white rounded-xl shadow-sm border p-5 space-y-3">
            <h4 class="font-semibold text-gray-800 flex items-center gap-2"><i class="fas fa-coins text-yellow-500"></i>Store Credit</h4>
            <p class="text-xs text-gray-400">Award store credits to registered customers when this product is delivered.</p>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="store_credit_enabled" value="1" <?= ($product['store_credit_enabled'] ?? 0) ? 'checked' : '' ?> class="rounded text-yellow-600" id="storeCreditToggle" onchange="document.getElementById('storeCreditOptions').classList.toggle('hidden', !this.checked)">
                <span class="text-sm font-medium">Enable Store Credit</span>
            </label>
            <div id="storeCreditOptions" class="<?= ($product['store_credit_enabled'] ?? 0) ? '' : 'hidden' ?>">
                <label class="block text-xs font-medium text-gray-600 mb-1">Credit Amount (৳) per unit</label>
                <input type="number" name="store_credit_amount" value="<?= e($product['store_credit_amount'] ?? '0') ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="e.g. 50" step="0.01" min="0">
                <p class="text-xs text-gray-400 mt-1">Credited to customer's account after delivery</p>
            </div>
        </div>

        <!-- Retention Period -->
        <div class="bg-white rounded-xl shadow-sm border p-5 space-y-3">
            <h4 class="font-semibold text-gray-800 flex items-center gap-2">
                <i class="fas fa-redo text-teal-500"></i> Retention Period
                <span class="text-[10px] bg-teal-50 text-teal-600 border border-teal-200 px-2 py-0.5 rounded-full font-normal">CRM</span>
            </h4>
            <p class="text-xs text-gray-400">How often does a typical customer need to reorder this product? Used for retention reminders.</p>
            <div class="flex gap-2 items-center">
                <input type="number" name="retention_value" id="retentionValue"
                       value="<?= e($product['retention_value'] ?? '') ?>"
                       min="1" max="365" placeholder="e.g. 30"
                       class="w-24 px-3 py-2 border rounded-lg text-sm">
                <select name="retention_unit" id="retentionUnit" class="flex-1 px-3 py-2 border rounded-lg text-sm">
                    <option value="days"   <?= ($product['retention_unit'] ?? 'days') === 'days'   ? 'selected' : '' ?>>Days</option>
                    <option value="weeks"  <?= ($product['retention_unit'] ?? 'days') === 'weeks'  ? 'selected' : '' ?>>Weeks</option>
                    <option value="months" <?= ($product['retention_unit'] ?? 'days') === 'months' ? 'selected' : '' ?>>Months</option>
                </select>
            </div>
            <p class="text-[10px] text-gray-400">Leave empty to use the global default (<?= getSetting('retention_default_days', 30) ?> days)</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-5">
            <h4 class="font-semibold text-gray-800 mb-3">Category</h4>
            <select name="category_id" id="categorySelect" class="w-full px-3 py-2 border rounded-lg text-sm">
                <option value="">Select Category</option>
                <?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>" <?= ($product['category_id'] ?? 0) == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php if ($product): ?>
        <div class="bg-gray-50 rounded-xl border p-5 text-xs text-gray-500 space-y-1">
            <p>Created: <?= date('M d, Y', strtotime($product['created_at'])) ?></p>
            <p>Views: <?= number_format($product['views']) ?> · Sales: <?= number_format($product['sales_count']) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>
</form>

<?php include __DIR__ . '/../includes/media-picker.php'; ?>

<script>
const SITE_URL='<?= SITE_URL ?>', ADMIN_URL='<?= ADMIN_URL ?>';
const PRODUCT_ID = <?= $id ?: 0 ?>;

// ══════════════════════════════════════════
// ── Product Type Toggle ──────────────────
// ══════════════════════════════════════════
function setProductType(type) {
    const input = document.getElementById('productTypeInput');
    const btnS = document.getElementById('btnTypeSimple');
    const btnV = document.getElementById('btnTypeVariable');
    input.value = type;

    const isVar = type === 'variable';

    // Toggle button styles
    btnS.className = 'px-3 py-1.5 rounded-md text-xs font-medium transition-all ' +
        (isVar ? 'text-gray-500 hover:text-gray-700' : 'bg-white shadow text-blue-700');
    btnV.className = 'px-3 py-1.5 rounded-md text-xs font-medium transition-all ' +
        (isVar ? 'bg-white shadow text-purple-700' : 'text-gray-500 hover:text-gray-700');

    // Pricing section
    toggle('variablePriceBanner', isVar);
    toggle('simplePricingFields', !isVar);
    toggle('variableCostField', isVar);
    const regInput = document.getElementById('inputRegularPrice');
    if (regInput) regInput.required = !isVar;

    // Inventory section
    toggle('variableStockBanner', isVar);
    toggle('simpleStockFields', !isVar);
    toggle('variableWeightField', isVar);

    // Variations section
    const sec = document.getElementById('variationsSection');
    sec.classList.toggle('ring-2', isVar);
    sec.classList.toggle('ring-purple-200', isVar);
    document.getElementById('varSectionTitle').innerHTML = isVar
        ? '🎨 <span class="text-purple-600">Variations</span> & Addons'
        : '🎨 Addons & Variations';

    // Show/hide mode toggle and apply current mode
    toggle('variationModeToggle', isVar);
    if (isVar) {
        setVariationMode(document.getElementById('variationModeInput').value || 'legacy');
    } else {
        toggle('combinationModePanel', false);
        toggle('legacyModePanel', true);
        const lb = document.getElementById('legacyAddBtn'); if(lb) lb.classList.remove('hidden');
        const hint = document.getElementById('varSectionHint');
        if(hint) hint.innerHTML = '<strong>Addon</strong> = adds cost to base price &nbsp;|&nbsp; <strong>Variation</strong> = replaces base price entirely';
    }

    // Re-layout all option rows
    document.querySelectorAll('.option-row').forEach(row => layoutOptionRow(row));
    recalcVariablePrice();
}

// ══════════════════════════════════════════
// ── Variation Mode Toggle ────────────────
// ══════════════════════════════════════════
function setVariationMode(mode) {
    document.getElementById('variationModeInput').value = mode;
    const isComb = mode === 'combination';
    const isVar = document.getElementById('productTypeInput').value === 'variable';
    document.getElementById('btnModeLegacy').className = 'px-2.5 py-1 rounded-md text-[11px] font-medium transition-all ' + (!isComb ? 'bg-white shadow text-blue-700' : 'text-gray-500');
    document.getElementById('btnModeCombination').className = 'px-2.5 py-1 rounded-md text-[11px] font-medium transition-all ' + (isComb ? 'bg-white shadow text-purple-700' : 'text-gray-500');
    toggle('combinationModePanel', isComb && isVar);
    toggle('legacyModePanel', !isComb || !isVar);
    const lb = document.getElementById('legacyAddBtn');
    if(lb) lb.classList.toggle('hidden', isComb && isVar);
    const hint = document.getElementById('varSectionHint');
    if(hint && isComb && isVar) hint.innerHTML = '<span class="text-purple-600 font-medium">⚡ Combination Mode:</span> Use Attributes tab to define options, then generate all combinations.';
    else if(hint && isVar) hint.innerHTML = '<span class="text-purple-600 font-medium">⚡ Variable Product:</span> Each variation has its own pricing & stock.';
    if (isComb) switchComboTab('attributes');
    if (isComb) recalcComboPreview();
}

// ══════════════════════════════════════════
// ── Combination Mode: Tabs ───────────────
// ══════════════════════════════════════════
function switchComboTab(tab) {
    document.querySelectorAll('.combo-tab').forEach(t => {
        const match = t.dataset.tab === tab;
        t.classList.toggle('border-purple-600', match);
        t.classList.toggle('text-purple-700', match);
        t.classList.toggle('border-transparent', !match);
        t.classList.toggle('text-gray-500', !match);
    });
    document.querySelectorAll('.combo-tab-panel').forEach(p => p.classList.add('hidden'));
    const panel = document.getElementById('comboTab' + tab.charAt(0).toUpperCase() + tab.slice(1));
    if (panel) panel.classList.remove('hidden');
}

// ══════════════════════════════════════════
// ── Attributes ───────────────────────────
// ══════════════════════════════════════════
function addAttributeRow() {
    const m = document.getElementById('noAttrMsg'); if (m) m.remove();
    const idx = document.querySelectorAll('.attr-row').length;
    document.getElementById('attributesContainer').insertAdjacentHTML('beforeend',
    `<div class="attr-row bg-gray-50 rounded-lg border p-3" data-index="${idx}">
        <div class="flex items-center justify-between mb-2">
            <span class="font-medium text-sm text-gray-700 attr-name-display">New Attribute</span>
            <div class="flex items-center gap-3">
                <button type="button" onclick="this.closest('.attr-row').querySelector('.attr-details').classList.toggle('hidden')" class="text-xs text-blue-500">Expand / Close</button>
                <button type="button" onclick="this.closest('.attr-row').remove()" class="text-xs text-red-400 hover:text-red-600">Remove</button>
            </div>
        </div>
        <div class="attr-details">
            <div class="grid grid-cols-12 gap-3">
                <div class="col-span-4"><label class="text-xs text-gray-500">Name:</label><input type="text" name="attr_name[]" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="e.g. Frame Color" oninput="this.closest('.attr-row').querySelector('.attr-name-display').textContent=this.value||'New Attribute'"></div>
                <div class="col-span-8"><label class="text-xs text-gray-500">Value(s): <span class="text-gray-300">separate with |</span></label><input type="text" name="attr_values[]" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="Black | Golden | Silver"></div>
            </div>
            <div class="flex items-center gap-4 mt-2">
                <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" name="attr_visible[${idx}]" value="1" checked class="rounded text-blue-500"><span class="text-xs text-gray-600">Visible on product page</span></label>
                <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" name="attr_for_variation[${idx}]" value="1" checked class="rounded text-purple-500 attr-for-var-cb"><span class="text-xs text-gray-600 font-medium">Used for variations</span></label>
            </div>
        </div>
    </div>`);
}

// ══════════════════════════════════════════
// ── Generate Combinations ────────────────
// ══════════════════════════════════════════
function generateCombinations() {
    const attrs = [];
    document.querySelectorAll('.attr-row').forEach(row => {
        const forVar = row.querySelector('.attr-for-var-cb');
        if (!forVar || !forVar.checked) return;
        const name = row.querySelector('[name="attr_name[]"]')?.value?.trim();
        const valsStr = row.querySelector('[name="attr_values[]"]')?.value?.trim();
        if (!name || !valsStr) return;
        const vals = valsStr.split('|').map(v => v.trim()).filter(Boolean);
        if (vals.length) attrs.push({ name, vals });
    });
    if (!attrs.length) { alert('Add attributes with values first (Attributes tab), and check "Used for variations".'); switchComboTab('attributes'); return; }

    // Cartesian product
    const cartesian = arrays => arrays.reduce((acc, curr) => {
        const r = []; acc.forEach(a => curr.forEach(b => r.push([...a, b]))); return r;
    }, [['_']]).map(r => r.slice(1));
    const combos = cartesian(attrs.map(a => a.vals));

    // Preserve existing data
    const existing = {};
    document.querySelectorAll('.combo-row').forEach(row => {
        const key = row.querySelector('[name="combo_key[]"]')?.value;
        if (key) existing[key] = {
            reg: row.querySelector('.combo-reg-price')?.value||'', sale: row.querySelector('.combo-sale-price')?.value||'',
            cost: row.querySelector('[name="combo_cost_price[]"]')?.value||'', stock: row.querySelector('.combo-stock-input')?.value||'0',
            sku: row.querySelector('[name="combo_sku[]"]')?.value||'', image: row.querySelector('[name="combo_image[]"]')?.value||''
        };
    });

    const container = document.getElementById('combinationsContainer');
    container.innerHTML = '';
    const nm = document.getElementById('noComboMsg'); if (nm) nm.remove();

    combos.forEach((combo, ci) => {
        const keyObj = {}; attrs.forEach((a,i) => keyObj[a.name] = combo[i]);
        const keyJson = JSON.stringify(keyObj), label = combo.join(' / '), ex = existing[keyJson] || {};
        const badges = attrs.map((a,i) => `<span class="inline-flex items-center bg-purple-100 text-purple-700 px-2 py-0.5 rounded text-[11px] mr-1">${a.name}: <strong class="ml-0.5">${combo[i]}</strong></span>`).join('');
        container.insertAdjacentHTML('beforeend',
        `<div class="combo-row bg-purple-50/40 border border-purple-200 rounded-lg p-3" data-index="${ci}">
            <div class="flex gap-3 items-start">
                <div class="flex-shrink-0"><div class="combo-img-wrap relative" style="width:48px;">
                    <div class="w-12 h-12 rounded-lg border-2 ${ex.image?'border-blue-300 bg-blue-50':'border-dashed border-gray-300'} flex items-center justify-center cursor-pointer hover:border-blue-400 transition overflow-hidden" onclick="pickComboImage(this.closest('.combo-img-wrap'))">
                        <img src="${ex.image?SITE_URL+'/uploads/products/'+ex.image:''}" class="w-full h-full object-cover combo-img-preview ${ex.image?'':'hidden'}">
                        <i class="fas fa-image text-gray-300 text-sm combo-img-placeholder ${ex.image?'hidden':''}"></i>
                    </div><input type="hidden" name="combo_image[]" value="${ex.image||''}">
                </div></div>
                <div class="flex-1 min-w-0">
                    <input type="hidden" name="combo_key[]" value='${keyJson.replace(/'/g,"&#39;")}'>
                    <input type="hidden" name="combo_label[]" value="${label}">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-bold text-purple-800">${badges}</span>
                        <div class="flex items-center gap-2">
                            <label class="flex items-center gap-1 cursor-pointer"><input type="radio" name="combo_default" value="${ci}" class="accent-purple-600"><span class="text-[10px] text-gray-400">Default</span></label>
                            <button type="button" onclick="removeComboRow(this)" class="text-red-400 hover:text-red-600 text-sm">✕</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-3"><label class="text-[10px] text-gray-500">Regular ৳ *</label><input type="number" name="combo_regular_price[]" value="${ex.reg||''}" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm combo-reg-price" oninput="recalcComboPreview()"></div>
                        <div class="col-span-2"><label class="text-[10px] text-gray-500">Sale ৳</label><input type="number" name="combo_sale_price[]" value="${ex.sale||''}" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm combo-sale-price" oninput="recalcComboPreview()"></div>
                        <div class="col-span-2"><label class="text-[10px] text-gray-500">Cost ৳</label><input type="number" name="combo_cost_price[]" value="${ex.cost||''}" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm combo-cost-price"></div>
                        <div class="col-span-2"><label class="text-[10px] text-gray-500">Stock</label><input type="number" name="combo_stock[]" value="${ex.stock||'0'}" class="w-full px-2 py-1.5 border rounded text-sm combo-stock-input" oninput="recalcTotalStock()"></div>
                        <div class="col-span-3"><label class="text-[10px] text-gray-500">SKU</label><input type="text" name="combo_sku[]" value="${ex.sku||''}" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="Auto"></div>
                    </div>
                    <div class="flex items-center gap-4 mt-1.5">
                        <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" name="combo_manage_stock_${ci}" value="1" checked class="rounded text-purple-500"><span class="text-[10px] text-gray-500">Track Stock</span></label>
                        <div class="flex items-center gap-1"><label class="text-[10px] text-gray-500">Wt:</label><input type="number" name="combo_weight[]" step="0.01" class="w-16 px-1.5 py-0.5 border rounded text-[11px]" placeholder="g"></div>
                        <div class="combo-price-preview text-[11px] ml-auto"></div>
                    </div>
                </div>
            </div>
        </div>`);
    });
    document.getElementById('comboCountBadge').textContent = combos.length;
    toggle('comboBulkActions', combos.length > 0);
    recalcComboPreview(); recalcTotalStock();
    switchComboTab('variations');
}

function removeComboRow(btn) {
    btn.closest('.combo-row').remove();
    document.getElementById('comboCountBadge').textContent = document.querySelectorAll('.combo-row').length;
    recalcComboPreview(); recalcTotalStock();
}

function bulkSetField(field) {
    let targets = [], val = '';
    if (field === 'regular_price') {
        val = document.getElementById('bulkRegPrice').value;
        targets = document.querySelectorAll('.combo-reg-price');
    } else if (field === 'sale_price') {
        val = document.getElementById('bulkSalePrice').value;
        targets = document.querySelectorAll('.combo-sale-price');
    } else if (field === 'cost_price') {
        val = document.getElementById('bulkCostPrice').value;
        targets = document.querySelectorAll('.combo-cost-price');
    } else if (field === 'stock') {
        val = document.getElementById('bulkStock').value;
        targets = document.querySelectorAll('.combo-stock-input');
    }
    if (val === '' && field !== 'sale_price') return;
    let count = 0;
    targets.forEach(inp => {
        inp.value = val;
        // Dispatch native input event so oninput handlers fire
        inp.dispatchEvent(new Event('input', { bubbles: true }));
        // Visual flash
        inp.style.transition = 'background 0.3s';
        inp.style.background = '#e9d5ff';
        setTimeout(() => { inp.style.background = ''; }, 600);
        count++;
    });
    recalcComboPreview();
    recalcTotalStock();
    // Show confirmation
    const btn = event?.target;
    if (btn && count > 0) {
        const origText = btn.textContent;
        btn.textContent = '✓ ' + count;
        btn.classList.add('bg-green-200', 'text-green-800');
        setTimeout(() => { btn.textContent = origText; btn.classList.remove('bg-green-200', 'text-green-800'); }, 1000);
    }
}

function recalcTotalStock() {
    let t=0; document.querySelectorAll('.combo-stock-input').forEach(i=>{t+=parseInt(i.value)||0;});
    const el=document.getElementById('productTotalStock'); if(el)el.value=t;
}

function recalcComboPreview() {
    let sell=[], reg=[];
    document.querySelectorAll('.combo-row').forEach(row => {
        const r=parseFloat(row.querySelector('.combo-reg-price')?.value)||0;
        const s=parseFloat(row.querySelector('.combo-sale-price')?.value)||0;
        if(r>0){reg.push(r);sell.push((s>0&&s<r)?s:r);}
        const prev=row.querySelector('.combo-price-preview');
        if(prev){
            if(r>0&&s>0&&s<r){const d=Math.round((r-s)/r*100);prev.innerHTML=`<span class="text-green-700 font-medium">৳${s.toLocaleString()}</span> <s class="text-gray-400">৳${r.toLocaleString()}</s> <span class="bg-red-100 text-red-600 px-1 py-0.5 rounded text-[9px] font-bold">-${d}%</span>`;}
            else if(r>0){prev.innerHTML=`<span class="text-gray-600">৳${r.toLocaleString()}</span>`;}
            else{prev.innerHTML='';}
        }
    });
    const regI=document.getElementById('inputRegularPrice'), prev=document.getElementById('variablePricePreview');
    if(sell.length>0){
        const sMin=Math.min(...sell),sMax=Math.max(...sell),rMax=Math.max(...reg);
        if(regI)regI.value=rMax;
        let t=sMin===sMax?'৳'+sMin.toLocaleString():'৳'+sMin.toLocaleString()+' – ৳'+sMax.toLocaleString();
        prev.innerHTML='💡 Sell: '+t+` (${sell.length} combination${sell.length>1?'s':''})`;
        prev.classList.remove('hidden');
    } else { if(prev){prev.textContent='⚠️ No prices set yet.';prev.classList.remove('hidden');} }
}

let _comboImgTarget=null;
function pickComboImage(wrap){_comboImgTarget=wrap;openMediaLibrary(onComboImageSelected,{multiple:false,folder:'products',uploadFolder:'products'});}
function onComboImageSelected(files){
    if(!_comboImgTarget||!files.length)return;
    const f=files[0],path=f.path.replace(/^products\//,'');
    const prev=_comboImgTarget.querySelector('.combo-img-preview'),ph=_comboImgTarget.querySelector('.combo-img-placeholder'),inp=_comboImgTarget.querySelector('input[name="combo_image[]"]');
    prev.src=f.url;prev.classList.remove('hidden');if(ph)ph.classList.add('hidden');inp.value=path;_comboImgTarget=null;
}
function addComboAddon(){
    document.getElementById('comboAddonsContainer').insertAdjacentHTML('beforeend',
    `<div class="addon-row bg-gray-50 rounded-lg border p-3"><div class="grid grid-cols-12 gap-2 items-end">
        <div class="col-span-3"><label class="text-xs text-gray-500">Name</label><input type="text" name="combo_addon_name[]" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="Gift wrap"></div>
        <div class="col-span-3"><label class="text-xs text-gray-500">Value</label><input type="text" name="combo_addon_value[]" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="Premium"></div>
        <div class="col-span-2"><label class="text-xs text-gray-500">Price ±৳</label><input type="number" name="combo_addon_price[]" value="0" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm"></div>
        <div class="col-span-2"><label class="text-xs text-gray-500">Stock</label><input type="number" name="combo_addon_stock[]" value="0" class="w-full px-2 py-1.5 border rounded text-sm"></div>
        <div class="col-span-1"><label class="text-xs text-gray-500">SKU</label><input type="text" name="combo_addon_sku[]" class="w-full px-2 py-1.5 border rounded text-sm"></div>
        <div class="col-span-1 text-center pt-5"><button type="button" onclick="this.closest('.addon-row').remove()" class="text-red-400 hover:text-red-600 text-lg">✕</button></div>
    </div></div>`);
}

function toggle(id, show) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('hidden', !show);
}

// ══════════════════════════════════════════
// ── Option Row Layout Logic ──────────────
// ══════════════════════════════════════════
function layoutOptionRow(row) {
    const typeSelect = row.querySelector('.opt-type-select');
    if (!typeSelect) return;
    const isVar = typeSelect.value === 'variation';
    const isVariableProduct = document.getElementById('productTypeInput').value === 'variable';

    // Card border color
    row.classList.toggle('border-purple-200', isVar && isVariableProduct);
    row.classList.toggle('bg-purple-50/30', isVar && isVariableProduct);
    row.classList.toggle('border-gray-200', !(isVar && isVariableProduct));
    row.classList.toggle('bg-gray-50', !(isVar && isVariableProduct));

    // Addon price field
    const addonPrice = row.querySelector('.opt-addon-price');
    if (addonPrice) {
        addonPrice.classList.toggle('hidden', isVar);
        const inp = addonPrice.querySelector('input');
        if (inp) inp.disabled = isVar;
    }

    // Simple variation sell price (single field - used when NOT variable product)
    const simplePrice = row.querySelector('.opt-var-simple-price');
    if (simplePrice) {
        simplePrice.classList.toggle('hidden', !isVar || isVariableProduct);
        const inp = simplePrice.querySelector('input');
        if (inp) inp.disabled = (!isVar || isVariableProduct);
    }

    // Variable product: Regular + Sale price fields
    const regPrice = row.querySelector('.opt-var-reg-price');
    const salePrice = row.querySelector('.opt-var-sale-price');
    if (regPrice) {
        regPrice.classList.toggle('hidden', !isVar || !isVariableProduct);
        const inp = regPrice.querySelector('input');
        if (inp) inp.disabled = (!isVar || !isVariableProduct);
    }
    if (salePrice) {
        salePrice.classList.toggle('hidden', !isVar || !isVariableProduct);
        const inp = salePrice.querySelector('input');
        if (inp) inp.disabled = (!isVar || !isVariableProduct);
    }

    // Stock/SKU in main row (simple mode)
    const stockSimple = row.querySelector('.opt-stock-simple');
    const skuSimple = row.querySelector('.opt-sku-simple');
    if (stockSimple) {
        stockSimple.classList.toggle('hidden', isVar && isVariableProduct);
        const inp = stockSimple.querySelector('input');
        if (inp) inp.disabled = (isVar && isVariableProduct);
    }
    if (skuSimple) {
        skuSimple.classList.toggle('hidden', isVar && isVariableProduct);
        const inp = skuSimple.querySelector('input');
        if (inp) inp.disabled = (isVar && isVariableProduct);
    }

    // Variable product extras row (track stock + sku expanded)
    const extras = row.querySelector('.opt-var-extras');
    if (extras) {
        extras.classList.toggle('hidden', !(isVar && isVariableProduct));
        // Enable/disable extras inputs to prevent duplicate form submission
        extras.querySelectorAll('input[name]').forEach(inp => {
            if (inp.classList.contains('opt-manage-stock-cb')) return; // checkbox always active
            inp.disabled = !(isVar && isVariableProduct);
        });
    }

    // Update discount preview
    updateRowDiscountPreview(row);
}

function toggleOptionFields(sel) {
    const row = sel.closest('.option-row');
    layoutOptionRow(row);
    recalcVariablePrice();
}

function toggleVarStock(cb) {
    const row = cb.closest('.option-row');
    const stockField = row.querySelector('.opt-var-stock-field');
    const stockInput = row.querySelector('.opt-var-stock-input');
    if (stockField) {
        stockField.classList.toggle('opacity-40', !cb.checked);
        stockField.classList.toggle('pointer-events-none', !cb.checked);
    }
    if (stockInput) stockInput.disabled = !cb.checked;
}

function removeOptionRow(btn) {
    btn.closest('.option-row').remove();
    recalcVariablePrice();
}

function updateRowDiscountPreview(row) {
    const preview = row.querySelector('.opt-var-discount-preview');
    if (!preview) return;
    const isVariableProduct = document.getElementById('productTypeInput').value === 'variable';
    const typeSelect = row.querySelector('.opt-type-select');
    if (!typeSelect || typeSelect.value !== 'variation' || !isVariableProduct) {
        preview.innerHTML = '';
        return;
    }
    const regInput = row.querySelector('[name="opt_var_regular[]"]');
    const saleInput = row.querySelector('[name="opt_var_sale[]"]');
    const reg = parseFloat(regInput?.value) || 0;
    const sale = parseFloat(saleInput?.value) || 0;

    if (reg > 0 && sale > 0 && sale < reg) {
        const disc = Math.round((reg - sale) / reg * 100);
        preview.innerHTML = `<span class="text-green-700 font-medium">৳${sale.toLocaleString()}</span> <s class="text-gray-400">৳${reg.toLocaleString()}</s> <span class="bg-red-100 text-red-600 px-1.5 py-0.5 rounded text-[10px] font-bold">-${disc}%</span>`;
    } else if (reg > 0) {
        preview.innerHTML = `<span class="text-gray-600 font-medium">৳${reg.toLocaleString()}</span>`;
    } else {
        preview.innerHTML = '';
    }
}

// ══════════════════════════════════════════
// ── Recalc Price Range Preview ───────────
// ══════════════════════════════════════════
function recalcVariablePrice() {
    if (document.getElementById('productTypeInput').value !== 'variable') return;
    // Delegate to combo preview if in combination mode
    if (document.getElementById('variationModeInput').value === 'combination') { recalcComboPreview(); return; }

    let sellPrices = [], regPrices = [];
    document.querySelectorAll('.option-row').forEach(row => {
        const ts = row.querySelector('.opt-type-select');
        if (!ts || ts.value !== 'variation') return;
        const regI = row.querySelector('[name="opt_var_regular[]"]');
        const saleI = row.querySelector('[name="opt_var_sale[]"]');
        const reg = parseFloat(regI?.value) || 0;
        const sale = parseFloat(saleI?.value) || 0;
        if (reg > 0) {
            regPrices.push(reg);
            sellPrices.push((sale > 0 && sale < reg) ? sale : reg);
        }
        updateRowDiscountPreview(row);
    });

    const regInput = document.getElementById('inputRegularPrice');
    const preview = document.getElementById('variablePricePreview');

    if (sellPrices.length > 0) {
        const sMin = Math.min(...sellPrices), sMax = Math.max(...sellPrices);
        const rMax = Math.max(...regPrices);
        if (regInput) regInput.value = rMax;

        let txt = '';
        if (sMin === sMax) {
            txt = '৳' + sMin.toLocaleString();
        } else {
            txt = '৳' + sMin.toLocaleString() + ' – ৳' + sMax.toLocaleString();
        }
        // Check if any has discount
        const hasDiscount = sellPrices.some((s, i) => s < regPrices[i]);
        if (hasDiscount && rMax > sMin) {
            const maxDisc = Math.round((rMax - sMin) / rMax * 100);
            txt += ` <s class="text-purple-400">৳${rMax.toLocaleString()}</s> <span class="text-red-500">up to -${maxDisc}%</span>`;
        }
        preview.innerHTML = '💡 Sell: ' + txt + ` (${sellPrices.length} variation${sellPrices.length > 1 ? 's' : ''})`;
        preview.classList.remove('hidden');
    } else {
        if (regInput) regInput.value = '';
        preview.textContent = '⚠️ No variation prices set yet. Add variations below.';
        preview.classList.remove('hidden');
    }
}

// ══════════════════════════════════════════
// ── Validation ───────────────────────────
// ══════════════════════════════════════════
function validateProductForm() {
    const type = document.getElementById('productTypeInput').value;
    const mode = document.getElementById('variationModeInput').value;
    if (type === 'variable') {
        if (mode === 'combination') {
            let hasCombo = false;
            document.querySelectorAll('.combo-row').forEach(row => {
                if ((parseFloat(row.querySelector('.combo-reg-price')?.value)||0) > 0) hasCombo = true;
            });
            if (!hasCombo) { alert('Combination mode requires at least one combination with a Regular Price.\n\nAttributes tab → add attributes → Variations tab → Generate → set prices.'); return false; }
            recalcComboPreview();
        } else {
            let hasVarWithPrice = false;
            document.querySelectorAll('.option-row').forEach(row => {
                const ts = row.querySelector('.opt-type-select');
                if (ts && ts.value === 'variation') {
                    const reg = row.querySelector('[name="opt_var_regular[]"]');
                    if (reg && parseFloat(reg.value) > 0) hasVarWithPrice = true;
                }
            });
            if (!hasVarWithPrice) {
                alert('Variable product requires at least one variation with a Regular Price.');
                document.getElementById('variationsSection').scrollIntoView({behavior: 'smooth', block: 'center'});
                return false;
            }
            recalcVariablePrice();
        }
    } else {
        const regInput = document.getElementById('inputRegularPrice');
        if (!regInput.value || parseFloat(regInput.value) <= 0) {
            alert('Simple product requires a Regular Price.');
            regInput.focus();
            return false;
        }
    }
    return true;
}

// ══════════════════════════════════════════
// ── Add Option Row ───────────────────────
// ══════════════════════════════════════════
function addOption() {
    const m = document.getElementById('noOptMsg'); if (m) m.remove();
    const idx = document.querySelectorAll('.option-row').length;
    const isVariableProduct = document.getElementById('productTypeInput').value === 'variable';
    const defaultType = isVariableProduct ? 'variation' : 'addon';
    const isVar = defaultType === 'variation';

    const html = `<div class="option-row rounded-lg border ${isVar && isVariableProduct ? 'border-purple-200 bg-purple-50/30' : 'border-gray-200 bg-gray-50'} p-3" data-index="${idx}">
        <div class="flex gap-3 items-start">
            <div class="flex-shrink-0">
                <label class="text-xs text-gray-500 block mb-1">Image</label>
                <div class="opt-img-wrap relative" style="width:56px;">
                    <div class="w-14 h-14 rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition overflow-hidden"
                         onclick="pickVariantImage(this.closest('.opt-img-wrap'))">
                        <img src="" class="w-full h-full object-cover opt-img-preview hidden">
                        <i class="fas fa-image text-gray-300 text-lg opt-img-placeholder"></i>
                    </div>
                    <button type="button" onclick="clearVariantImage(this.closest('.opt-img-wrap'))"
                        class="opt-img-remove w-full mt-1 text-[9px] text-red-500 hover:text-red-700 hover:bg-red-50 rounded py-0.5 transition hidden">
                        <i class="fas fa-trash-alt mr-0.5"></i>Remove
                    </button>
                    <input type="hidden" name="opt_image[]" value="">
                </div>
            </div>
            <div class="flex-1 min-w-0">
                <div class="grid grid-cols-12 gap-2 items-end">
                    <div class="col-span-2"><label class="text-xs text-gray-500">Type</label>
                        <select name="opt_type[]" class="w-full px-2 py-1.5 border rounded text-xs opt-type-select" onchange="toggleOptionFields(this)">
                            <option value="addon" ${!isVar ? 'selected' : ''}>Addon</option><option value="variation" ${isVar ? 'selected' : ''}>Variation</option>
                        </select></div>
                    <div class="col-span-2"><label class="text-xs text-gray-500">Name</label><input type="text" name="opt_name[]" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="Color, Size..."></div>
                    <div class="col-span-2"><label class="text-xs text-gray-500">Value</label><input type="text" name="opt_value[]" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="Red, XL..."></div>
                    <div class="col-span-2 opt-addon-price ${isVar ? 'hidden' : ''}"><label class="text-xs text-gray-500">Price ±</label><input type="number" name="opt_price[]" value="0" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm"></div>
                    <div class="col-span-2 opt-var-simple-price ${(!isVar || isVariableProduct) ? 'hidden' : ''}"><label class="text-xs text-gray-500">Sell Price ৳</label><input type="number" name="opt_abs_price[]" value="0" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm" oninput="recalcVariablePrice()"></div>
                    <div class="col-span-2 opt-var-reg-price ${(!isVar || !isVariableProduct) ? 'hidden' : ''}"><label class="text-xs text-gray-500">Regular ৳ <span class="text-red-400">*</span></label><input type="number" name="opt_var_regular[]" value="" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm" oninput="recalcVariablePrice()" placeholder="1000"></div>
                    <div class="col-span-2 opt-var-sale-price ${(!isVar || !isVariableProduct) ? 'hidden' : ''}"><label class="text-xs text-gray-500">Sale ৳ <span class="text-gray-300 text-[10px]">optional</span></label><input type="number" name="opt_var_sale[]" value="" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm" oninput="recalcVariablePrice()" placeholder="800"></div>
                    <div class="col-span-1 opt-stock-simple ${(isVar && isVariableProduct) ? 'hidden' : ''}"><label class="text-xs text-gray-500">Stock</label><input type="number" name="opt_stock[]" value="0" class="w-full px-2 py-1.5 border rounded text-sm"></div>
                    <div class="col-span-1 opt-sku-simple ${(isVar && isVariableProduct) ? 'hidden' : ''}"><label class="text-xs text-gray-500">SKU</label><input type="text" name="opt_sku[]" class="w-full px-2 py-1.5 border rounded text-sm" placeholder="Auto"></div>
                    <div class="col-span-1 text-center pt-4"><label class="text-xs text-gray-400 block mb-0.5">Default</label><input type="radio" name="opt_default" value="${idx}" class="accent-blue-600"></div>
                    <div class="col-span-1 text-center pt-5"><button type="button" onclick="removeOptionRow(this)" class="text-red-400 hover:text-red-600 text-lg">✕</button></div>
                </div>
                <div class="opt-var-extras mt-2 pt-2 border-t border-purple-100 ${(isVar && isVariableProduct) ? '' : 'hidden'}">
                    <div class="flex items-center gap-4 flex-wrap">
                        <label class="flex items-center gap-1.5 cursor-pointer">
                            <input type="checkbox" name="opt_manage_stock_${idx}" value="1" class="rounded text-purple-600 opt-manage-stock-cb" checked onchange="toggleVarStock(this)">
                            <span class="text-xs font-medium text-gray-600">Track Stock</span>
                        </label>
                        <div class="opt-var-stock-field flex items-center gap-2">
                            <label class="text-xs text-gray-500">Qty:</label>
                            <input type="number" name="opt_stock[]" value="0" class="w-20 px-2 py-1 border rounded text-sm opt-var-stock-input">
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-xs text-gray-500">SKU:</label>
                            <input type="text" name="opt_sku[]" class="w-28 px-2 py-1 border rounded text-sm" placeholder="Auto">
                        </div>
                        <div class="flex items-center gap-2" title="Stock deduction per unit ordered. For kg products: e.g. 0.25 = 250g.">
                            <label class="text-xs text-orange-500">⚖ Deduction:</label>
                            <input type="number" name="opt_weight_per_unit[]" step="0.0001" min="0" placeholder="auto" class="w-24 px-2 py-1 border rounded text-sm">
                        </div>
                        <div class="opt-var-discount-preview text-xs ml-auto"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>`;
    document.getElementById('optionsContainer').insertAdjacentHTML('beforeend', html);
}

// ══════════════════════════════════════════
// ── Init ─────────────────────────────────
// ══════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    const type = document.getElementById('productTypeInput').value;
    if (type === 'variable') {
        setProductType('variable');
    }
    // Init combo preview if in combination mode
    if (document.getElementById('variationModeInput').value === 'combination') {
        recalcComboPreview(); recalcTotalStock();
    }
});

// ── Variant Image Picker (uses media library) ──
let _varImgTarget = null;
function pickVariantImage(wrap) {
    _varImgTarget = wrap;
    openMediaLibrary(onVariantImageSelected, {multiple: false, folder: 'products', uploadFolder: 'products'});
}
function onVariantImageSelected(files) {
    if (!_varImgTarget || !files.length) return;
    const f = files[0];
    const path = f.path.replace(/^products\//, '');
    const preview = _varImgTarget.querySelector('.opt-img-preview');
    const placeholder = _varImgTarget.querySelector('.opt-img-placeholder');
    const removeBtn = _varImgTarget.querySelector('.opt-img-remove');
    const input = _varImgTarget.querySelector('input[name="opt_image[]"]');
    const box = _varImgTarget.querySelector('.w-14');
    preview.src = f.url;
    preview.classList.remove('hidden');
    if (placeholder) placeholder.classList.add('hidden');
    if (removeBtn) removeBtn.classList.remove('hidden');
    if (box) { box.classList.remove('border-dashed', 'border-gray-300'); box.classList.add('border-blue-300', 'bg-blue-50'); }
    input.value = path;
    _varImgTarget = null;
}
function clearVariantImage(wrap) {
    const preview = wrap.querySelector('.opt-img-preview');
    const placeholder = wrap.querySelector('.opt-img-placeholder');
    const removeBtn = wrap.querySelector('.opt-img-remove');
    const input = wrap.querySelector('input[name="opt_image[]"]');
    const box = wrap.querySelector('.w-14');
    preview.src = '';
    preview.classList.add('hidden');
    if (placeholder) placeholder.classList.remove('hidden');
    if (removeBtn) removeBtn.classList.add('hidden');
    if (box) { box.classList.add('border-dashed', 'border-gray-300'); box.classList.remove('border-blue-300', 'bg-blue-50'); }
    input.value = '';
}

// ── Product Search (Upsell & Bundle) ──
let searchTimer = null;
function searchProducts(q, target) {
    clearTimeout(searchTimer);
    if (q.length < 2) { document.getElementById(target + 'Dropdown').classList.add('hidden'); return; }
    searchTimer = setTimeout(() => {
        fetch(`${ADMIN_URL}/pages/product-form.php?ajax=search_products&q=${encodeURIComponent(q)}&exclude=${PRODUCT_ID}`)
        .then(r => r.json())
        .then(data => {
            const dd = document.getElementById(target + 'Dropdown');
            if (!data.length) { dd.innerHTML = '<div class="p-3 text-sm text-gray-400">No products found</div>'; dd.classList.remove('hidden'); return; }
            let html = '';
            data.forEach(p => {
                html += `<div class="flex items-center gap-3 p-2 hover:bg-gray-50 cursor-pointer" onclick="${target === 'upsell' ? `addUpsell(${p.id}, '${p.name.replace(/'/g,"\\'")}', '${p.image_url}')` : `addBundle(${p.id}, '${p.name.replace(/'/g,"\\'")}', '${p.image_url}', ${p.display_price})`}">
                    <img src="${p.image_url}" class="w-8 h-8 rounded object-cover">
                    <div class="flex-1 min-w-0"><p class="text-sm font-medium truncate">${p.name_bn || p.name}</p><p class="text-xs text-gray-400">৳${Number(p.display_price).toLocaleString()}</p></div>
                    <i class="fas fa-plus text-blue-500 text-xs"></i>
                </div>`;
            });
            dd.innerHTML = html;
            dd.classList.remove('hidden');
        });
    }, 300);
}

// ── Upsells ──
function addUpsell(id, name, img) {
    const ids = document.getElementById('upsellIds').value.split(',').filter(Boolean).map(Number);
    if (ids.includes(id)) return;
    ids.push(id);
    document.getElementById('upsellIds').value = ids.join(',');
    document.getElementById('upsellList').insertAdjacentHTML('beforeend',
        `<div class="upsell-tag flex items-center gap-2 bg-blue-50 border border-blue-200 rounded-lg px-3 py-1.5" data-id="${id}">
            <img src="${img}" class="w-8 h-8 rounded object-cover"><span class="text-xs font-medium">${name}</span>
            <button type="button" onclick="removeUpsell(this)" class="text-red-400 hover:text-red-600 text-xs">✕</button>
        </div>`);
    document.getElementById('upsellSearch').value = '';
    document.getElementById('upsellDropdown').classList.add('hidden');
}

function removeUpsell(btn) {
    const tag = btn.closest('.upsell-tag');
    const id = parseInt(tag.dataset.id);
    const ids = document.getElementById('upsellIds').value.split(',').filter(Boolean).map(Number).filter(x => x !== id);
    document.getElementById('upsellIds').value = ids.join(',');
    tag.remove();
}

// ── Bundles ──
function showBundleSearch() { document.getElementById('bundleSearchWrap').classList.toggle('hidden'); document.getElementById('bundleSearch').focus(); }

function addBundle(id, name, img, price) {
    const m = document.getElementById('noBundleMsg'); if (m) m.remove();
    document.getElementById('bundleContainer').insertAdjacentHTML('beforeend',
        `<div class="bundle-row bg-green-50 rounded-lg p-3" data-price="${price}">
            <div class="grid grid-cols-12 gap-2 items-center">
                <input type="hidden" name="bundle_product_id[]" value="${id}">
                <div class="col-span-4 flex items-center gap-2"><img src="${img}" class="w-10 h-10 rounded object-cover"><div class="min-w-0"><span class="text-sm font-medium truncate block">${name}</span><span class="text-[10px] text-gray-400">Current: ৳${Number(price).toLocaleString()}</span></div></div>
                <div class="col-span-2"><label class="text-xs text-gray-500">Qty</label><input type="number" name="bundle_qty[]" value="1" min="1" class="w-full px-2 py-1.5 border rounded text-sm bd-calc" oninput="calcBundleRow(this)"></div>
                <div class="col-span-2"><label class="text-xs text-gray-500">Discount Type</label>
                    <select name="bundle_discount_type[]" class="w-full px-2 py-1.5 border rounded text-xs bd-calc" onchange="calcBundleRow(this)"><option value="fixed">Fixed ৳</option><option value="percentage">Percent %</option></select></div>
                <div class="col-span-3"><label class="text-xs text-gray-500">Discount</label><input type="number" name="bundle_discount_value[]" value="0" step="0.01" class="w-full px-2 py-1.5 border rounded text-sm bd-calc" oninput="calcBundleRow(this)"></div>
                <div class="col-span-1 text-center"><button type="button" onclick="this.closest('.bundle-row').remove();calcBundleSummary()" class="text-red-400 hover:text-red-600">✕</button></div>
            </div>
            <div class="bd-preview mt-2 pt-2 border-t border-green-200 flex items-center justify-between text-xs"></div>
        </div>`);
    document.getElementById('bundleSearch').value = '';
    document.getElementById('bundleDropdown').classList.add('hidden');
    calcBundleSummary();
}

// ── Bundle Live Calculation ──
const MAIN_PRODUCT_PRICE = <?= json_encode(floatval($product['sale_price'] ?? 0) > 0 && floatval($product['sale_price'] ?? 0) < floatval($product['regular_price'] ?? 0) ? floatval($product['sale_price']) : floatval($product['regular_price'] ?? 0)) ?>;

function calcBundleRow(el) {
    const row = el.closest('.bundle-row');
    if (!row) return;
    const unitPrice = parseFloat(row.dataset.price) || 0;
    const qty = parseInt(row.querySelector('[name="bundle_qty[]"]').value) || 1;
    const discType = row.querySelector('[name="bundle_discount_type[]"]').value;
    const discVal = parseFloat(row.querySelector('[name="bundle_discount_value[]"]').value) || 0;
    
    let discount = 0;
    if (discType === 'percentage') {
        discount = (unitPrice * discVal) / 100;
    } else {
        discount = Math.min(discVal, unitPrice);
    }
    const finalUnit = Math.max(0, unitPrice - discount);
    const finalTotal = finalUnit * qty;
    const saved = discount * qty;
    
    const preview = row.querySelector('.bd-preview');
    if (preview) {
        if (discount > 0) {
            preview.innerHTML = `<span class="text-gray-500"><s>৳${unitPrice.toLocaleString()}</s> → <span class="text-green-700 font-bold">৳${Math.round(finalUnit).toLocaleString()}</span>/unit</span>
                <span class="font-bold text-green-700">Total: ৳${Math.round(finalTotal).toLocaleString()} <span class="text-red-500 text-[10px] ml-1">Save ৳${Math.round(saved).toLocaleString()}</span></span>`;
        } else {
            preview.innerHTML = `<span class="text-gray-500">৳${unitPrice.toLocaleString()}/unit</span><span class="text-gray-600 font-medium">Total: ৳${Math.round(finalTotal).toLocaleString()}</span>`;
        }
    }
    calcBundleSummary();
}

function calcBundleSummary() {
    const rows = document.querySelectorAll('.bundle-row');
    const summary = document.getElementById('bundleSummary');
    if (!rows.length) { summary?.classList.add('hidden'); return; }
    
    let separateTotal = MAIN_PRODUCT_PRICE;
    let bundleTotal = MAIN_PRODUCT_PRICE;
    
    rows.forEach(row => {
        const unitPrice = parseFloat(row.dataset.price) || 0;
        const qty = parseInt(row.querySelector('[name="bundle_qty[]"]')?.value) || 1;
        const discType = row.querySelector('[name="bundle_discount_type[]"]')?.value || 'fixed';
        const discVal = parseFloat(row.querySelector('[name="bundle_discount_value[]"]')?.value) || 0;
        
        let discount = 0;
        if (discType === 'percentage') {
            discount = (unitPrice * discVal) / 100;
        } else {
            discount = Math.min(discVal, unitPrice);
        }
        const finalUnit = Math.max(0, unitPrice - discount);
        
        separateTotal += unitPrice * qty;
        bundleTotal += finalUnit * qty;
    });
    
    const saved = separateTotal - bundleTotal;
    const pct = separateTotal > 0 ? ((saved / separateTotal) * 100) : 0;
    
    if (summary) {
        summary.classList.remove('hidden');
        summary.innerHTML = `<div class="flex items-center justify-between">
            <div>
                <span class="text-gray-600">Main product: ৳${Math.round(MAIN_PRODUCT_PRICE).toLocaleString()}</span>
                <span class="mx-2">+</span>
                <span class="text-gray-600">Bundle items: ৳${Math.round(bundleTotal - MAIN_PRODUCT_PRICE).toLocaleString()}</span>
            </div>
            <div class="text-right">
                ${saved > 0 ? `<span class="line-through text-gray-400 mr-2">৳${Math.round(separateTotal).toLocaleString()}</span>` : ''}
                <span class="font-bold text-green-800 text-base">৳${Math.round(bundleTotal).toLocaleString()}</span>
                ${saved > 0 ? `<span class="ml-2 px-1.5 py-0.5 bg-red-500 text-white text-[10px] font-bold rounded">${pct >= 1 ? Math.round(pct) : pct.toFixed(1)}% OFF · Save ৳${Math.round(saved).toLocaleString()}</span>` : ''}
            </div>
        </div>`;
    }
}

// Init all existing bundle rows
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.bundle-row').forEach(row => {
        const el = row.querySelector('.bd-calc');
        if (el) calcBundleRow(el);
    });
});

// ── Image Helpers ──
async function delImg(id) { const _ok = await window._confirmAsync('Delete?'); if(!_ok) return; fetch(location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=delete_image&image_id=${id}`}).then(r=>r.json()).then(d=>{if(d.success)document.getElementById('img-'+id).remove();}); }
function setPrimary(id) { fetch(location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=set_primary&image_id=${id}`}).then(r=>r.json()).then(d=>{if(d.success)location.reload();}); }
function previewUp(inp) { const c=document.getElementById('uploadPreviews'); Array.from(inp.files).forEach(f=>{const r=new FileReader();r.onload=e=>{c.insertAdjacentHTML('beforeend',`<div class="w-20 h-20 rounded-lg overflow-hidden border-2 border-blue-300"><img src="${e.target.result}" class="w-full h-full object-cover"></div>`);};r.readAsDataURL(f);}); }

// Media Library callback
function onMediaFilesSelected(files) {
    const existing = document.getElementById('galleryFromMedia').value;
    const paths = existing ? existing.split(',').filter(Boolean) : [];
    files.forEach(f => {
        if (!paths.includes(f.path)) paths.push(f.path);
        document.getElementById('uploadPreviews').insertAdjacentHTML('beforeend',
            `<div class="w-20 h-20 rounded-lg overflow-hidden border-2 border-green-300"><img src="${f.url}" class="w-full h-full object-cover"></div>`);
    });
    document.getElementById('galleryFromMedia').value = paths.join(',');
}

// Close dropdowns on click outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('#upsellSearch') && !e.target.closest('#upsellDropdown')) document.getElementById('upsellDropdown')?.classList.add('hidden');
    if (!e.target.closest('#bundleSearch') && !e.target.closest('#bundleDropdown')) document.getElementById('bundleDropdown')?.classList.add('hidden');
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
