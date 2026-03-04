<?php
/**
 * Cart API - AJAX Endpoints
 */
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/functions.php';

// GET request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'get') {
        $cart = getCart();
        $items = [];
        foreach ($cart as $key => $item) {
            $items[] = array_merge($item, ['key' => $key]);
        }
        echo json_encode([
            'success' => true,
            'items' => $items,
            'total' => getCartTotal(),
            'count' => getCartCount(),
        ]);
        exit;
    }
    
    if ($action === 'get_variants') {
        $productId = intval($_GET['product_id'] ?? 0);
        $db = Database::getInstance();
        $product = getProduct($productId);
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not found']);
            exit;
        }
        $variants = $db->fetchAll("SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1 ORDER BY option_type, variant_name, id", [$productId]);
        
        // Group by variant_name and option_type
        $addonGroups = [];
        $variationGroups = [];
        foreach ($variants as $v) {
            $type = $v['option_type'] ?? 'addon';
            if ($type === 'variation') {
                $variationGroups[$v['variant_name']][] = $v;
            } else {
                $addonGroups[$v['variant_name']][] = $v;
            }
        }
        
        echo json_encode([
            'success' => true,
            'has_variants' => !empty($variants),
            'product' => [
                'id' => $product['id'],
                'name' => $product['name_bn'] ?: $product['name'],
                'price' => getProductPrice($product),
                'regular_price' => floatval($product['regular_price']),
                'image' => getProductImage($product),
            ],
            'addon_groups' => $addonGroups,
            'variation_groups' => $variationGroups,
            'variants' => $variants,
        ]);
        exit;
    }
    
    if ($action === 'get_upsells') {
        $productIds = array_filter(array_map('intval', explode(',', $_GET['product_ids'] ?? '')));
        $limit = max(1, min(10, intval($_GET['limit'] ?? 4)));
        $upsellProducts = [];
        if (!empty($productIds)) {
            $db = Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $excludePlaceholders = $placeholders; // same cart product IDs to exclude
            try {
                // Ensure table exists
                $db->query("CREATE TABLE IF NOT EXISTS product_upsells (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id INT NOT NULL,
                    upsell_product_id INT NOT NULL,
                    sort_order INT DEFAULT 0,
                    UNIQUE KEY unique_upsell (product_id, upsell_product_id),
                    INDEX idx_product (product_id)
                ) ENGINE=InnoDB");

                // 1) Try manual upsells first
                $upsells = $db->fetchAll(
                    "SELECT DISTINCT p.id, p.name, p.name_bn, p.featured_image, p.regular_price, p.sale_price
                     FROM product_upsells pu 
                     JOIN products p ON pu.upsell_product_id = p.id 
                     WHERE pu.product_id IN ($placeholders) AND p.is_active = 1 AND p.stock_status = 'in_stock'
                     AND p.id NOT IN ($excludePlaceholders)
                     ORDER BY pu.sort_order LIMIT $limit",
                    array_merge($productIds, $productIds)
                );

                // 2) Fallback: same category products
                if (empty($upsells)) {
                    $cats = $db->fetchAll(
                        "SELECT DISTINCT category_id FROM products WHERE id IN ($placeholders) AND category_id IS NOT NULL",
                        $productIds
                    );
                    $catIds = array_column($cats, 'category_id');
                    if (!empty($catIds)) {
                        $catPH = implode(',', array_fill(0, count($catIds), '?'));
                        $upsells = $db->fetchAll(
                            "SELECT p.id, p.name, p.name_bn, p.featured_image, p.regular_price, p.sale_price
                             FROM products p 
                             WHERE p.category_id IN ($catPH) AND p.is_active = 1 AND p.stock_status = 'in_stock'
                             AND p.id NOT IN ($excludePlaceholders)
                             ORDER BY p.is_featured DESC, RAND() LIMIT $limit",
                            array_merge($catIds, $productIds)
                        );
                    }
                }

                // 3) Fallback: featured/popular products
                if (empty($upsells)) {
                    $upsells = $db->fetchAll(
                        "SELECT p.id, p.name, p.name_bn, p.featured_image, p.regular_price, p.sale_price
                         FROM products p 
                         WHERE p.is_active = 1 AND p.stock_status = 'in_stock'
                         AND p.id NOT IN ($excludePlaceholders)
                         ORDER BY p.is_featured DESC, p.sales_count DESC, p.id DESC LIMIT $limit",
                        $productIds
                    );
                }

                foreach ($upsells as $u) {
                    $upsellProducts[] = [
                        'id' => $u['id'],
                        'name' => $u['name_bn'] ?: $u['name'],
                        'price' => $u['sale_price'] && $u['sale_price'] < $u['regular_price'] ? $u['sale_price'] : $u['regular_price'],
                        'image' => $u['featured_image'] ? imgSrc('products', $u['featured_image']) : asset('img/default-product.svg'),
                    ];
                }
            } catch (\Throwable $e) {
                // Silent fail — just return empty
            }
        }
        echo json_encode(['success' => true, 'products' => $upsellProducts]);
        exit;
    }
}

// POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $productId = intval($input['product_id'] ?? 0);
            $quantity = max(1, intval($input['quantity'] ?? 1));
            $variantId = $input['variant_id'] ?? null;
            $customerUpload = $input['customer_upload'] ?? null;
            
            if (addToCart($productId, $quantity, $variantId, $customerUpload)) {
                echo json_encode([
                    'success' => true,
                    'cart_count' => getCartCount(),
                    'cart_total' => getCartTotal(),
                    'message' => 'Product added to cart',
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Product not found']);
            }
            break;
        
        case 'add_bundle':
            $productId = intval($input['product_id'] ?? 0);
            $quantity = max(1, intval($input['quantity'] ?? 1));
            $variantId = $input['variant_id'] ?? null;
            $customerUpload = $input['customer_upload'] ?? null;
            
            $product = getProduct($productId);
            if (!$product) {
                echo json_encode(['success' => false, 'message' => 'Product not found']);
                break;
            }
            
            $db = Database::getInstance();
            $bundles = $db->fetchAll(
                "SELECT pb.*, p.id as bp_id, p.name, p.name_bn, p.regular_price, p.sale_price, p.featured_image
                 FROM product_bundles pb JOIN products p ON pb.bundle_product_id = p.id 
                 WHERE pb.product_id = ? AND pb.is_active = 1 AND p.is_active = 1 
                 ORDER BY pb.sort_order", [$productId]
            );
            
            if (empty($bundles)) {
                echo json_encode(['success' => false, 'message' => 'No bundle products found']);
                break;
            }
            
            // Calculate main product price
            $mainPrice = getProductPrice($product);
            
            // Handle main product variants
            $variantNames = [];
            if ($variantId) {
                $ids = array_filter(array_map('intval', explode(',', $variantId)));
                foreach ($ids as $vid) {
                    $variant = $db->fetch("SELECT * FROM product_variants WHERE id = ? AND product_id = ? AND is_active = 1", [$vid, $productId]);
                    if (!$variant) continue;
                    $optType = $variant['option_type'] ?? 'addon';
                    if ($optType === 'variation' && $variant['absolute_price'] !== null) {
                        $mainPrice = floatval($variant['absolute_price']);
                    } else {
                        $mainPrice += floatval($variant['price_adjustment']);
                    }
                    $variantNames[] = $variant['variant_name'] . ': ' . $variant['variant_value'];
                }
            }
            
            // Calculate bundle items prices
            // "Separate" = sum of selling prices (what buying individually costs)
            // "Total" = sum after applying bundle-specific discounts
            $bundleSeparate = $mainPrice; // Main product at current selling price
            $bundleTotal = $mainPrice;
            $bundleItems = [];
            
            // First item: main product (no bundle discount on main)
            $bundleItems[] = [
                'product_id' => $productId,
                'name' => $product['name'],
                'qty' => $quantity,
                'price' => $mainPrice,
            ];
            
            foreach ($bundles as $b) {
                $bRegular = floatval($b['regular_price']);
                $bSelling = ($b['sale_price'] && $b['sale_price'] > 0 && $b['sale_price'] < $b['regular_price']) 
                    ? floatval($b['sale_price']) : $bRegular;
                
                // Bundle discount on selling price
                $bBundleDiscount = 0;
                if ($b['discount_type'] === 'percentage') {
                    $bBundleDiscount = round(($bSelling * floatval($b['discount_value'])) / 100, 2);
                } else {
                    $bBundleDiscount = min(floatval($b['discount_value']), $bSelling);
                }
                $bFinalUnit = round(max(0, $bSelling - $bBundleDiscount), 2);
                $bQty = intval($b['bundle_qty']);
                
                $bundleSeparate += $bSelling * $bQty;
                $bundleTotal += $bFinalUnit * $bQty;
                
                $bundleItems[] = [
                    'product_id' => intval($b['bp_id']),
                    'name' => $b['name'],
                    'qty' => $bQty,
                    'price' => $bFinalUnit,
                ];
            }
            
            // Bundle name
            $bundleName = $product['bundle_name'] ?? '';
            if (!$bundleName) $bundleName = ($product['name_bn'] ?: $product['name']) . ' বান্ডেল';
            
            // Store as SINGLE cart item
            $cartKey = 'bundle_' . $productId;
            $_SESSION['cart'][$cartKey] = [
                'product_id' => $productId,
                'is_bundle' => true,
                'name' => $bundleName,
                'variant_name' => implode(', ', $variantNames) ?: null,
                'price' => round($bundleTotal, 2),
                'regular_price' => round($bundleSeparate, 2),
                'quantity' => 1, // Always 1 bundle
                'image' => getProductImage($product),
                'customer_upload' => $customerUpload,
                'bundle_items' => $bundleItems, // For stock deduction & order items
            ];
            
            echo json_encode([
                'success' => true,
                'cart_count' => getCartCount(),
                'cart_total' => getCartTotal(),
                'message' => 'Bundle added',
            ]);
            break;
            
        case 'update':
            $key = $input['key'] ?? '';
            $delta = intval($input['delta'] ?? 0);
            $cart = getCart();
            
            if (isset($cart[$key])) {
                $newQty = $cart[$key]['quantity'] + $delta;
                if ($newQty <= 0) {
                    removeFromCart($key);
                    echo json_encode([
                        'success' => true,
                        'removed' => true,
                        'cart_count' => getCartCount(),
                        'cart_total' => getCartTotal(),
                    ]);
                } else {
                    updateCartItem($key, $newQty);
                    $updatedCart = getCart();
                    echo json_encode([
                        'success' => true,
                        'quantity' => $newQty,
                        'item_total' => $updatedCart[$key]['price'] * $newQty,
                        'cart_count' => getCartCount(),
                        'cart_total' => getCartTotal(),
                    ]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Item not found']);
            }
            break;
            
        case 'remove':
            $key = $input['key'] ?? '';
            removeFromCart($key);
            echo json_encode([
                'success' => true,
                'cart_count' => getCartCount(),
                'cart_total' => getCartTotal(),
            ]);
            break;

        case 'remove_by_product':
            $pid = intval($input['product_id'] ?? 0);
            if ($pid && isset($_SESSION['cart'])) {
                foreach ($_SESSION['cart'] as $k => $item) {
                    if (intval($item['product_id']) === $pid) {
                        unset($_SESSION['cart'][$k]);
                    }
                }
            }
            echo json_encode([
                'success' => true,
                'cart_count' => getCartCount(),
                'cart_total' => getCartTotal(),
            ]);
            break;
            
        case 'clear':
            clearCart();
            echo json_encode(['success' => true, 'cart_count' => 0, 'cart_total' => 0]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed']);
