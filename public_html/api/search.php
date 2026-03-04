<?php
/**
 * Search API — Live autocomplete for header search
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$q = sanitize($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$db = Database::getInstance();
$term = "%{$q}%";
$isAdmin = isset($_GET['admin']);
$activeClause = $isAdmin ? '1=1' : 'p.is_active = 1';
$limit = $isAdmin ? 15 : 8;
$products = $db->fetchAll(
    "SELECT p.id, p.name, p.name_bn, p.slug, p.sku, p.featured_image, p.regular_price, p.sale_price, p.stock_status, p.stock_quantity, p.is_on_sale,
            c.name as category_name
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE {$activeClause} AND (p.name LIKE ? OR p.name_bn LIKE ? OR p.sku LIKE ? OR p.tags LIKE ?)
     ORDER BY p.sales_count DESC, p.name ASC LIMIT {$limit}",
    [$term, $term, $term, $term]
);

$results = [];
foreach ($products as $p) {
    $price = getProductPrice($p);
    $results[] = [
        'id' => $p['id'],
        'name' => $p['name_bn'] ?: $p['name'],
        'slug' => $p['slug'],
        'image' => getProductImage($p),
        'price' => $price,
        'price_formatted' => formatPrice($price),
        'regular_price' => floatval($p['regular_price']),
        'has_discount' => $p['is_on_sale'] && $p['sale_price'] > 0 && $p['sale_price'] < $p['regular_price'],
        'category' => $p['category_name'] ?? '',
        'in_stock' => $p['stock_status'] !== 'out_of_stock',
        'sku' => $p['sku'] ?? '',
        'stock_quantity' => intval($p['stock_quantity'] ?? 0),
    ];
}

echo json_encode(['results' => $results]);
