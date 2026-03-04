<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
refreshAdminPermissions();
requirePermission('orders', 'print');
$db = Database::getInstance();

// Ensure table exists
try { $db->query("CREATE TABLE IF NOT EXISTS print_templates (id INT AUTO_INCREMENT PRIMARY KEY,template_key VARCHAR(100) NOT NULL UNIQUE,template_type ENUM('invoice','sticker') NOT NULL DEFAULT 'invoice',name VARCHAR(100) NOT NULL,description VARCHAR(255) DEFAULT '',base_template VARCHAR(100) NOT NULL DEFAULT 'inv_standard',is_builtin TINYINT(1) NOT NULL DEFAULT 0,config JSON DEFAULT NULL,created_by INT DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_type(template_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(\Throwable $e){}

$isPreview = !empty($_GET['preview']);
$template = $_GET['template'] ?? getSetting('selected_invoice_template', 'inv_standard');
$layout = $_GET['layout'] ?? getSetting('print_default_layout', 'a4_1'); // a4_1 | a3_2 | a4_3
if (!in_array($layout, ['a4_1','a3_2','a4_3'])) $layout = 'a4_1';
$legacyMap = ['standard'=>'inv_standard','compact'=>'inv_compact','sticker'=>'stk_standard','picking'=>'inv_picking'];
if (isset($legacyMap[$template])) $template = $legacyMap[$template];

// Load custom template config if applicable
$customConfig = null;
$baseTemplate = $template;
if (strpos($template, 'cust_') === 0) {
    $row = $db->fetch("SELECT * FROM print_templates WHERE template_key = ?", [$template]);
    if ($row) {
        $customConfig = json_decode($row['config'] ?? '{}', true) ?: [];
        $baseTemplate = $row['base_template'] ?? 'inv_standard';
    } else {
        $baseTemplate = 'inv_standard';
    }
}

// Helper: get config value with fallback
function cfg($key, $default = null) {
    global $customConfig;
    if ($customConfig === null) return $default;
    return $customConfig[$key] ?? $default;
}

$siteName = getSetting('site_name', 'E-Commerce');
$sitePhone = getSetting('site_phone', getSetting('contact_phone', ''));
$siteAddress = getSetting('footer_address', '');
$siteLogo = getSetting('site_logo', '');
$logoUrl = '';
if ($siteLogo) {
    $logoUrl = (strpos($siteLogo, '/') !== false) ? SITE_URL.'/uploads/'.$siteLogo : SITE_URL.'/uploads/logos/'.$siteLogo;
}
$shippingNote = cfg('custom_shipping_note', getSetting('invoice_shipping_note', ''));
$customFooter = cfg('custom_footer', '');

// Color overrides from custom config
$primaryColor = cfg('primary_color', '#2563eb');
$headerBg = cfg('header_bg', '');
$fontSize = intval(cfg('font_size', 13));
$stickerWidth = intval(cfg('sticker_width', 280));

// Show/hide toggles
$showLogo = cfg('show_logo', true);
$showPhone = cfg('show_phone', true);
$showAddress = cfg('show_address', true);
$showSku = cfg('show_sku', false);
$showImages = cfg('show_images', false);
$showVariant = cfg('show_variant', true);
$showCourier = cfg('show_courier', true);
$showParcel = cfg('show_parcel', true);
$showNotes = cfg('show_notes', true);
$showShipNote = cfg('show_shipping_note', true);
$showAdvance = cfg('show_advance', true);
$showDiscount = cfg('show_discount', true);
$showBarcode = cfg('show_barcode', getSetting('print_show_barcode', '1') === '1');

if ($isPreview) {
    // Generate sample preview data
    $orders = [[
        'id'=>0, 'order_number'=>'KB-20260001', 'customer_name'=>'রহিম উদ্দিন',
        'customer_phone'=>'01712345678', 'customer_email'=>'rahim@example.com',
        'customer_address'=>'House 12, Road 5, Dhanmondi', 'customer_city'=>'Dhaka',
        'customer_district'=>'Dhaka', 'payment_method'=>'cod', 'subtotal'=>1590,
        'shipping_cost'=>120, 'discount'=>0, 'discount_amount'=>100, 'advance_amount'=>200,
        'total'=>1610, 'notes'=>'Please call before delivery', 'admin_notes'=>'VIP customer', 'order_note'=>'Please wrap as gift with card', 'is_preorder'=>0, 'preorder_date'=>null, 'panel_notes'=>'',
        'courier_name'=>'Steadfast', 'courier_consignment_id'=>'SF-98706927',
        'courier_tracking_id'=>'SF-98706927', 'order_status'=>'confirmed',
        'created_at'=>date('Y-m-d H:i:s'), 'shipped_at'=>null, 'delivered_at'=>null,
        'store_credit_used'=>0
    ]];
    $allItems = [0 => [
        ['product_name'=>'Premium Polarized Sunglasses','variant_name'=>'Black Frame','quantity'=>1,'price'=>790,'subtotal'=>790,'sku'=>'SG-BLK-001','featured_image'=>''],
        ['product_name'=>'Leather Wallet for Men','variant_name'=>'Brown','quantity'=>1,'price'=>699,'subtotal'=>699,'sku'=>'WL-BRN-042','featured_image'=>''],
        ['product_name'=>'USB-C Fast Charger','variant_name'=>'','quantity'=>1,'price'=>201,'subtotal'=>201,'sku'=>'CHG-USB-C','featured_image'=>''],
    ]];
    $ids = [0];
    $idParam = 'preview=1';
} else {
    $ids = [];
    if (!empty($_GET['id'])) $ids = [intval($_GET['id'])];
    elseif (!empty($_GET['ids'])) $ids = array_map('intval', explode(',', $_GET['ids']));
    if (empty($ids)) redirect(adminUrl('pages/order-management.php'));

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $orders = $db->fetchAll("SELECT * FROM orders WHERE id IN ({$ph}) ORDER BY id DESC", $ids);
    $allItems = [];
    foreach ($orders as $o) {
        $allItems[$o['id']] = $db->fetchAll("SELECT oi.*, p.sku, p.featured_image FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?", [$o['id']]);
    }
    $idParam = count($ids) === 1 ? 'id='.$ids[0] : 'ids='.implode(',', $ids);
}

// Use base template for rendering
$tpl = $baseTemplate;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Print <?= count($orders) ?> Order(s)</title>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
:root{--primary:<?=$primaryColor?>;--font-size:<?=$fontSize?>px;--stk-w:<?=$stickerWidth?>px}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:var(--font-size);color:#333;background:#f9fafb}
.no-print{background:#f3f4f6;padding:10px 20px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid #e5e7eb;position:sticky;top:0;z-index:10}
.no-print button,.no-print a{padding:7px 14px;border-radius:8px;font-size:12px;cursor:pointer;text-decoration:none;border:1px solid #d1d5db;background:white;color:#374151;white-space:nowrap}
.no-print button:hover,.no-print a:hover{background:#f9fafb}.no-print .active{background:var(--primary);color:white;border-color:var(--primary)}
.text-right{text-align:right}.text-center{text-align:center}
.barcode-wrap{margin-top:8px;text-align:center}.barcode-wrap svg{max-width:100%}
.barcode-wrap-sm{margin-top:5px;text-align:center}.barcode-wrap-sm svg{max-width:100%;height:auto}

/* ===== LAYOUT: A4 × 1 (default) ===== */
<?php if($layout==='a4_1'): ?>
@media print{.no-print{display:none!important}.page-break{page-break-after:always}body{padding:0;background:#fff}@page{size:A4 portrait;margin:10mm}}
<?php elseif($layout==='a3_2'): ?>
/* ===== LAYOUT: A3 × 2 (landscape, side by side) ===== */
.layout-a3-row{display:flex;gap:0;page-break-after:always;align-items:flex-start}
.layout-a3-row>.layout-cell{flex:1;min-width:0;border-right:1px dashed #ccc;padding-right:8px}
.layout-a3-row>.layout-cell:last-child{border-right:none;padding-right:0;padding-left:8px}
.layout-a3-row .invoice,.layout-a3-row .picking{max-width:100%!important;padding:15px!important;margin:0!important}
.layout-a3-row .invoice *,.layout-a3-row .picking *{font-size:90%}
.layout-a3-row .inv-hdr .logo h1{font-size:18px!important}
.layout-a3-row .inv-hdr .info h2{font-size:15px!important}
.layout-a3-row .inv-tots table{width:220px}
.layout-a3-row .mod-meta{gap:15px;padding:12px}
.layout-a3-row .brd-hdr{padding:15px 20px}
.layout-a3-row .barcode-wrap svg,.layout-a3-row .barcode-wrap-sm svg{transform:scale(.85);transform-origin:center}
@media print{.no-print{display:none!important}body{padding:0;background:#fff}@page{size:A3 landscape;margin:8mm}.layout-a3-row:last-child{page-break-after:auto}}
@media screen{.layout-a3-row{max-width:1200px;margin:10px auto;background:#fff;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,.08);border-radius:8px}}
<?php elseif($layout==='a4_3'): ?>
/* ===== LAYOUT: A4 × 3 (portrait, 3 stacked) ===== */
.layout-a4-triple{page-break-after:always;display:flex;flex-direction:column;height:277mm;overflow:hidden}
.layout-a4-triple>.layout-cell-3{flex:1;min-height:0;overflow:hidden;border-bottom:1px dashed #ccc;position:relative}
.layout-a4-triple>.layout-cell-3:last-child{border-bottom:none}
.layout-a4-triple .invoice,.layout-a4-triple .picking{max-width:100%!important;padding:8px 15px!important;margin:0!important}
.layout-a4-triple .invoice *,.layout-a4-triple .picking *{font-size:78%}
.layout-a4-triple .inv-hdr{margin-bottom:10px!important;padding-bottom:8px!important}
.layout-a4-triple .inv-hdr .logo h1{font-size:15px!important}
.layout-a4-triple .inv-hdr .info h2{font-size:13px!important}
.layout-a4-triple .inv-addr{margin-bottom:8px!important}
.layout-a4-triple .inv-tbl thead th,.layout-a4-triple .mod-tbl thead th,.layout-a4-triple .brd-tbl thead th,.layout-a4-triple .det-tbl th{padding:4px 8px!important;font-size:9px!important}
.layout-a4-triple .inv-tbl tbody td,.layout-a4-triple .mod-tbl tbody td,.layout-a4-triple .brd-tbl tbody td,.layout-a4-triple .det-tbl td{padding:3px 8px!important}
.layout-a4-triple .inv-tots table,.layout-a4-triple .mod-tots,.layout-a4-triple .brd-tots table,.layout-a4-triple .det-tots{width:200px}
.layout-a4-triple .inv-tots td,.layout-a4-triple .mod-tots td,.layout-a4-triple .brd-tots td,.layout-a4-triple .det-tots td{padding:2px 8px!important}
.layout-a4-triple .inv-tots .grand td,.layout-a4-triple .mod-tots .grand td{font-size:13px!important}
.layout-a4-triple .inv-note{margin-top:6px!important;padding:5px 8px!important;font-size:10px!important}
.layout-a4-triple .inv-foot{margin-top:8px!important;padding-top:6px!important;font-size:9px!important}
.layout-a4-triple .mod-meta{gap:12px;padding:8px;margin-bottom:10px!important}
.layout-a4-triple .brd-hdr{padding:12px 15px;margin-bottom:10px!important}
.layout-a4-triple .brd-addr{padding:8px;margin-bottom:8px!important;gap:10px}
.layout-a4-triple .det-grid{gap:10px;margin-bottom:8px!important}
.layout-a4-triple .barcode-wrap svg{transform:scale(.7);transform-origin:center}
.layout-a4-triple .barcode-wrap-sm svg{transform:scale(.65);transform-origin:center}
@media print{.no-print{display:none!important}body{padding:0;background:#fff}@page{size:A4 portrait;margin:5mm}.layout-a4-triple:last-child{page-break-after:auto}}
@media screen{.layout-a4-triple{max-width:800px;margin:10px auto;background:#fff;padding:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);border-radius:8px;height:auto}}
<?php endif; ?>

<?php if($tpl==='inv_standard'):?>
.invoice{max-width:800px;margin:0 auto;padding:30px}
.inv-hdr{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:25px;border-bottom:3px solid var(--primary);padding-bottom:18px}
.inv-hdr .logo h1{font-size:22px;color:var(--primary)}.inv-hdr .logo p{font-size:11px;color:#666;margin-top:3px}.inv-hdr .logo img{max-height:50px;margin-bottom:6px}
.inv-hdr .info{text-align:right}.inv-hdr .info h2{font-size:18px;color:#333;margin-bottom:4px}.inv-hdr .info p{font-size:12px;color:#666}
.inv-addr{display:flex;justify-content:space-between;margin-bottom:22px}.inv-addr>div{width:48%}.inv-addr h3{font-size:10px;text-transform:uppercase;color:#999;letter-spacing:1px;margin-bottom:6px}.inv-addr p{font-size:var(--font-size);line-height:1.6}
.inv-tbl{width:100%;border-collapse:collapse;margin-bottom:20px}.inv-tbl thead th{background:#f3f4f6;padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:#666}.inv-tbl tbody td{padding:10px 12px;border-bottom:1px solid #eee}
.inv-tots{display:flex;justify-content:flex-end}.inv-tots table{width:280px}.inv-tots td{padding:5px 12px}.inv-tots .grand td{font-weight:bold;font-size:16px;border-top:2px solid #333;padding-top:10px}
.inv-foot{margin-top:30px;text-align:center;font-size:11px;color:#999;border-top:1px solid #eee;padding-top:15px}
.inv-note{margin-top:15px;background:#f9fafb;padding:10px 14px;border-radius:6px;font-size:12px}
<?php elseif($tpl==='inv_compact'):?>
.invoice{max-width:400px;margin:10px auto;padding:15px;border:1px solid #ddd;font-size:12px}
.cmp-hdr{text-align:center;border-bottom:2px dashed #999;padding-bottom:10px;margin-bottom:10px}.cmp-hdr h2{font-size:16px;color:var(--primary)}.cmp-hdr p{font-size:11px;color:#666}
.cmp-tbl{width:100%;border-collapse:collapse;margin-bottom:10px;font-size:12px}.cmp-tbl th{text-align:left;border-bottom:1px solid #ccc;padding:4px 6px;font-size:10px;color:#666}.cmp-tbl td{padding:4px 6px;border-bottom:1px solid #f0f0f0}
<?php elseif($tpl==='inv_modern'):?>
.invoice{max-width:800px;margin:0 auto;padding:40px}
.mod-badge{background:#111;color:#fff;padding:6px 18px;border-radius:4px;font-size:14px;font-weight:700;letter-spacing:2px}
.mod-meta{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-bottom:25px;padding:20px;background:#f8f9fa;border-radius:10px}.mod-meta h4{font-size:10px;text-transform:uppercase;color:#999;letter-spacing:1px;margin-bottom:6px}.mod-meta p{font-size:var(--font-size);line-height:1.6}
.mod-tbl{width:100%;border-collapse:collapse;margin-bottom:25px}.mod-tbl thead{background:#111}.mod-tbl thead th{color:#fff;padding:12px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.5px}.mod-tbl tbody td{padding:12px 14px;border-bottom:1px solid #eee}.mod-tbl tbody tr:nth-child(even){background:#fafafa}
.mod-tots{margin-left:auto;width:280px}.mod-tots tr td{padding:6px 14px}.mod-tots .grand{background:#111;color:#fff}.mod-tots .grand td{padding:12px 14px;font-size:16px;font-weight:700}
<?php elseif($tpl==='inv_branded'):?>
.invoice{max-width:800px;margin:0 auto;padding:30px}
.brd-hdr{background:linear-gradient(135deg,<?=$headerBg?:$primaryColor?> 0%,<?=$primaryColor?> 100%);color:white;padding:25px 30px;border-radius:12px;display:flex;justify-content:space-between;align-items:center;margin-bottom:25px}.brd-hdr h1{font-size:22px;font-weight:800}.brd-hdr img{max-height:45px;filter:brightness(10)}.brd-hdr .right{text-align:right}.brd-hdr .right p{font-size:12px;opacity:.85}
.brd-addr{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;padding:15px;background:<?=$primaryColor?>08;border-radius:8px;border-left:4px solid var(--primary)}.brd-addr h4{font-size:10px;text-transform:uppercase;color:var(--primary);margin-bottom:4px;font-weight:700;letter-spacing:1px}
.brd-tbl{width:100%;border-collapse:collapse;margin-bottom:20px;border-radius:8px;overflow:hidden}.brd-tbl thead th{background:<?=$primaryColor?>15;color:var(--primary);padding:10px 14px;font-size:11px;text-transform:uppercase}.brd-tbl tbody td{padding:10px 14px;border-bottom:1px solid <?=$primaryColor?>15}
.brd-tots{display:flex;justify-content:flex-end}.brd-tots table{width:260px}.brd-tots td{padding:5px 12px}.brd-tots .grand td{font-weight:bold;font-size:16px;color:var(--primary);border-top:2px solid var(--primary);padding-top:10px}
<?php elseif($tpl==='inv_detailed'):?>
.invoice{max-width:800px;margin:0 auto;padding:30px}
.det-hdr{display:flex;justify-content:space-between;border-bottom:2px solid #e5e7eb;padding-bottom:15px;margin-bottom:20px}.det-hdr img{max-height:50px}.det-hdr h1{font-size:20px;color:#111}
.det-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}.det-box{padding:12px;border:1px solid #e5e7eb;border-radius:8px}.det-box h4{font-size:10px;text-transform:uppercase;color:#999;margin-bottom:5px;font-weight:700}
.det-tbl{width:100%;border-collapse:collapse;margin-bottom:20px}.det-tbl th{padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:#666;border-bottom:2px solid #e5e7eb}.det-tbl td{padding:10px;border-bottom:1px solid #f3f4f6;vertical-align:middle}.det-tbl .pi{width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb}
.det-tots{margin-left:auto;width:280px}.det-tots td{padding:5px 10px}.det-tots .grand td{font-weight:bold;font-size:16px;border-top:2px solid #111;padding-top:10px}
<?php elseif($tpl==='inv_minimal'):?>
.invoice{max-width:700px;margin:0 auto;padding:40px 30px}
.min-tbl{width:100%;border-collapse:collapse;margin-bottom:25px}.min-tbl th{padding:8px 0;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#aaa;border-bottom:1px solid #ddd}.min-tbl td{padding:10px 0;border-bottom:1px solid #f0f0f0}
.min-row{display:flex;justify-content:flex-end;gap:40px;padding:4px 0;color:#666}.min-grand{font-size:20px;font-weight:700;color:#111;border-top:1px solid #111;padding-top:8px;margin-top:4px}
<?php elseif($tpl==='inv_picking'):?>
.picking{max-width:800px;margin:0 auto;padding:20px}
.pick-tbl{width:100%;border-collapse:collapse;margin-bottom:20px}.pick-tbl th,.pick-tbl td{border:1px solid #ccc;padding:8px 10px;font-size:12px}.pick-tbl th{background:#f3f4f6;font-size:11px;text-transform:uppercase}
.pick-chk{width:30px;height:30px;border:2px solid #999;display:inline-block}
<?php else: /* STICKER BASE STYLES */ ?>
.sticker{page-break-inside:avoid;display:inline-block;vertical-align:top;margin:6px;font-size:var(--font-size)}
<?php if($tpl==='stk_standard'):?>.sticker{width:var(--stk-w);border:2px solid #000;padding:10px;position:relative}
<?php elseif($tpl==='stk_detailed'):?>.sticker{width:var(--stk-w);border:2px solid #000;padding:10px}
.stk-ptbl{width:100%;border-collapse:collapse;font-size:10px;margin:5px 0}.stk-ptbl th{text-align:left;border-bottom:1px solid #999;padding:2px 4px;font-size:9px;color:#666}.stk-ptbl td{padding:2px 4px;border-bottom:1px solid #eee}
<?php elseif($tpl==='stk_courier'):?>.sticker{width:var(--stk-w);border:2px solid #000;padding:10px}
<?php elseif($tpl==='stk_pos'):?>.sticker{width:var(--stk-w);padding:10px;display:block;margin:4px auto;border-bottom:2px dashed #ccc;text-align:center}
.stk-pos-tbl{width:100%;font-size:10px;border-collapse:collapse;text-align:left}.stk-pos-tbl th{border-bottom:1px dashed #999;padding:3px 2px;font-size:9px;color:#777}.stk-pos-tbl td{padding:3px 2px;border-bottom:1px dotted #eee}
<?php elseif($tpl==='stk_cod'):?>.sticker{width:var(--stk-w);border:3px solid #000;border-radius:10px;padding:12px;position:relative;overflow:hidden}
<?php elseif($tpl==='stk_wide'):?>.sticker{width:calc(var(--stk-w) + 80px);border:2px solid #000;padding:10px 12px}
<?php elseif($tpl==='stk_sku'):?>.sticker{width:var(--stk-w);border:2px solid #000;padding:10px}
.sku-tbl{width:100%;border-collapse:collapse;font-size:10px;margin:5px 0}.sku-tbl th{text-align:left;background:#f0f0f0;padding:3px 5px;font-size:9px;border:1px solid #ccc}.sku-tbl td{padding:3px 5px;border:1px solid #ddd}
<?php elseif($tpl==='stk_note'):?>.sticker{width:var(--stk-w);border:2px solid #000;padding:10px}
<?php endif;endif;?>
</style>
</head>
<body>
<?php if(!$isPreview): ?>
<div class="no-print">
    <button onclick="window.print()" style="background:var(--primary);color:white;border-color:var(--primary)">🖨 Print</button>
    <a href="<?=adminUrl('pages/order-management.php')?>">← Back</a>
    <span style="color:#aaa">|</span>
    <?php
    $allTpls=['inv_standard'=>'📄Standard','inv_compact'=>'📋Compact','inv_modern'=>'🎨Modern','inv_branded'=>'🏷Branded','inv_detailed'=>'📸Detailed','inv_minimal'=>'✨Minimal','inv_picking'=>'📦Picking','stk_standard'=>'🏷Sticker','stk_detailed'=>'📋Detail-Stk','stk_courier'=>'🚚Courier','stk_pos'=>'🧾POS','stk_cod'=>'💵COD','stk_wide'=>'↔Wide','stk_sku'=>'🔖SKU','stk_note'=>'📝Note'];
    foreach($allTpls as $t=>$l):?><a href="?<?=$idParam?>&template=<?=$t?>&layout=<?=$layout?>" class="<?=$template===$t?'active':''?>"><?=$l?></a><?php endforeach;?>
    <span style="color:#aaa">|</span>
    <span style="font-size:11px;color:#666;font-weight:600">Page:</span>
    <a href="?<?=$idParam?>&template=<?=$template?>&layout=a4_1" class="<?=$layout==='a4_1'?'active':''?>" style="font-size:11px" title="A4 Portrait — 1 invoice per page">A4×1</a>
    <a href="?<?=$idParam?>&template=<?=$template?>&layout=a3_2" class="<?=$layout==='a3_2'?'active':''?>" style="font-size:11px" title="A3 Landscape — 2 invoices side by side">A3×2</a>
    <a href="?<?=$idParam?>&template=<?=$template?>&layout=a4_3" class="<?=$layout==='a4_3'?'active':''?>" style="font-size:11px" title="A4 Portrait — 3 invoices stacked per page">A4×3</a>
    <span style="margin-left:auto;font-size:12px;color:#888"><?=count($orders)?> order(s) · <?=strtoupper(str_replace('_','×',$layout))?></span>
</div>
<?php endif; ?>

<?php
$isSticker = (strpos($tpl, 'stk_') === 0);
$perPage = ($layout==='a3_2') ? 2 : (($layout==='a4_3') ? 3 : 1);
$useLayout = (!$isSticker && $perPage > 1);
$totalOrders = count($orders);

foreach($orders as $idx=>$order):
    $items=$allItems[$order['id']]??[];
    $discount=floatval($order['discount']??$order['discount_amount']??0);
    $advance=floatval($order['advance_amount']??0);
    $due=floatval($order['total'])-$advance;
    $pay=strtoupper($order['payment_method']??'COD');
    $courier=$order['courier_name']??'';
    $parcel=$order['courier_consignment_id']??$order['courier_tracking_id']??'';
    $dt=date('d/m/Y',strtotime($order['created_at']));
    $addr=trim(($order['customer_address']??'').($order['customer_city']?', '.$order['customer_city']:'').($order['customer_district']?', '.$order['customer_district']:''));
    $notes=$order['notes']??'';$aNotes=$order['admin_notes']??'';$orderNote=$order['order_note']??'';
    $barcodeVal=$order['order_number']??('ORD-'.$order['id']);
    $barcodeId='bc_'.$idx;

    // Layout wrapper: open at start of each group
    $posInGroup = $idx % $perPage;
    $isGroupStart = ($posInGroup === 0);
    $isGroupEnd = ($posInGroup === $perPage - 1) || ($idx === $totalOrders - 1);

    // Page break: only for a4_1 layout (traditional), others handled by wrapper
    $pb = '';
    if (!$useLayout && $idx < $totalOrders - 1) $pb = 'page-break';

    if ($useLayout && $isGroupStart):
        if ($layout === 'a3_2'): ?><div class="layout-a3-row"><?php
        elseif ($layout === 'a4_3'): ?><div class="layout-a4-triple"><?php
        endif;
    endif;

    if ($useLayout): ?><div class="layout-cell<?=$perPage===3?'-3':''?>"><?php endif;
?>

<?php if($tpl==='inv_standard'):?>
<div class="invoice <?=$pb?>">
    <div class="inv-hdr"><div class="logo"><?php if($logoUrl&&$showLogo):?><img src="<?=$logoUrl?>"><?php endif;?><h1><?=e($siteName)?></h1><?php if($sitePhone&&$showPhone):?><p>📞 <?=e($sitePhone)?></p><?php endif;?><?php if($siteAddress&&$showAddress):?><p>📍 <?=e($siteAddress)?></p><?php endif;?></div>
    <div class="info"><h2>INVOICE</h2><p><strong>#<?=e($order['order_number'])?></strong></p><p><?=date('d M Y',strtotime($order['created_at']))?></p><p><?=$pay?></p><?php if($showBarcode):?><div class="barcode-wrap" style="margin-top:6px"><svg id="<?=$barcodeId?>" class="barcode-svg" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div></div>
    <div class="inv-addr"><div><h3>Customer</h3><p><strong><?=e($order['customer_name'])?></strong><br>📞 <?=e($order['customer_phone'])?></p></div><div><h3>Ship To</h3><p><?=e($addr)?></p><?php if($showCourier&&$courier):?><p style="font-size:11px;color:#666;margin-top:4px">Courier: <?=e($courier)?><?=$showParcel&&$parcel?' | '.e($parcel):''?></p><?php endif;?></div></div>
    <table class="inv-tbl"><thead><tr><th>#</th><?php if($showImages):?><th></th><?php endif;?><th>Item</th><?php if($showSku):?><th>SKU</th><?php endif;?><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead><tbody>
    <?php foreach($items as $i=>$it):$img=(!empty($it['featured_image']))?SITE_URL.'/uploads/products/'.$it['featured_image']:'';?><tr><td><?=$i+1?></td><?php if($showImages):?><td><?php if($img):?><img src="<?=$img?>" style="width:36px;height:36px;object-fit:cover;border-radius:4px"><?php else:?>📦<?php endif;?></td><?php endif;?><td><strong><?=e($it['product_name'])?></strong><?=$showVariant&&!empty($it['variant_name'])?"<br><span style='color:#666;font-size:11px'>".e($it['variant_name'])."</span>":''?></td><?php if($showSku):?><td style="font-size:11px;color:#666"><?=e($it['sku']??'-')?></td><?php endif;?><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['price'])?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
    </tbody></table>
    <div class="inv-tots"><table><tr><td>Subtotal</td><td class="text-right">৳<?=number_format($order['subtotal'])?></td></tr><tr><td>Shipping</td><td class="text-right">৳<?=number_format($order['shipping_cost'])?></td></tr><?php if($showDiscount&&$discount>0):?><tr><td>Discount</td><td class="text-right" style="color:#dc2626">-৳<?=number_format($discount)?></td></tr><?php endif;?><?php if($showAdvance&&$advance>0):?><tr><td>Advance</td><td class="text-right" style="color:var(--primary)">-৳<?=number_format($advance)?></td></tr><?php endif;?><tr class="grand"><td>Due Amount</td><td class="text-right">৳<?=number_format($due)?></td></tr></table></div>
    <?php if($showNotes&&$orderNote):?><div class="inv-note"><strong>Note:</strong> <?=e($orderNote)?></div><?php endif;?>
    <?php if($showShipNote&&$shippingNote):?><div class="inv-note" style="margin-top:8px"><strong>Shipping:</strong> <?=e($shippingNote)?></div><?php endif;?>
    <?php if(!empty($order['is_preorder'])):?><div class="inv-note" style="margin-top:8px;background:#f5f3ff;border:1px solid #ddd6fe"><strong style="color:#7c3aed"><i>⏰ PREORDER</i></strong><?php if(!empty($order['preorder_date'])):?> — Expected: <?=date('d M Y',strtotime($order['preorder_date']))?><?php endif;?></div><?php endif;?>
    <div class="inv-foot"><?=$customFooter?e($customFooter):'Thank you for your order! | '.e($siteName)?></div>
</div>

<?php elseif($tpl==='inv_compact'):?>
<div class="invoice <?=$pb?>">
    <div class="cmp-hdr"><h2><?=e($siteName)?></h2><?php if($showPhone&&$sitePhone):?><p><?=e($sitePhone)?></p><?php endif;?></div>
    <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:11px;color:#555"><span>#<?=e($order['order_number'])?></span><span><?=$dt?></span></div>
    <div style="border:1px solid #eee;padding:8px;border-radius:4px;margin-bottom:10px;font-size:12px;line-height:1.5"><strong><?=e($order['customer_name'])?></strong><br>📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
    <table class="cmp-tbl"><thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-right">Total</th></tr></thead><tbody>
    <?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?></td><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
    </tbody></table>
    <div style="text-align:right;font-size:11px;color:#666">Ship: ৳<?=number_format($order['shipping_cost'])?><?=$showDiscount&&$discount>0?' | Disc: -৳'.number_format($discount):''?></div>
    <div style="text-align:right;font-weight:bold;font-size:16px;margin:10px 0">Due: ৳<?=number_format($due)?></div>
    <?php if($showBarcode):?><div class="barcode-wrap-sm" style="margin:8px 0"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
    <div style="text-align:center;font-size:10px;color:#999;border-top:1px dashed #ccc;padding-top:8px"><?=$customFooter?e($customFooter):$pay.' | Thank you!'?></div>
</div>

<?php elseif($tpl==='inv_modern'):?>
<div class="invoice <?=$pb?>">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:30px"><div><?php if($logoUrl&&$showLogo):?><img src="<?=$logoUrl?>" style="max-height:45px"><?php else:?><h1 style="font-size:20px;font-weight:800"><?=e($siteName)?></h1><?php endif;?></div><div class="mod-badge">INVOICE</div></div>
    <div class="mod-meta"><div><h4>Bill To</h4><p><strong><?=e($order['customer_name'])?></strong><br>📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></p></div><div style="text-align:right"><h4>Invoice</h4><p><strong>#<?=e($order['order_number'])?></strong><br><?=date('d M Y',strtotime($order['created_at']))?><br><?=$pay?></p><?php if($showBarcode):?><div class="barcode-wrap" style="margin-top:6px"><svg id="<?=$barcodeId?>" class="barcode-svg" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div></div>
    <table class="mod-tbl"><thead><tr><th>#</th><th>Product</th><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Amount</th></tr></thead><tbody>
    <?php foreach($items as $i=>$it):?><tr><td><?=$i+1?></td><td><?=e($it['product_name'])?></td><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['price'])?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
    </tbody></table>
    <table class="mod-tots"><tr><td>Subtotal</td><td class="text-right">৳<?=number_format($order['subtotal'])?></td></tr><tr><td>Delivery</td><td class="text-right">৳<?=number_format($order['shipping_cost'])?></td></tr><?php if($showDiscount&&$discount>0):?><tr><td>Discount</td><td class="text-right">-৳<?=number_format($discount)?></td></tr><?php endif;?><tr class="grand"><td>Due Amount</td><td class="text-right">৳<?=number_format($due)?></td></tr></table>
    <div style="text-align:center;margin-top:30px;font-size:11px;color:#999"><?=$customFooter?e($customFooter):e($siteName).' | Thank you'?></div>
</div>

<?php elseif($tpl==='inv_branded'):?>
<div class="invoice <?=$pb?>">
    <div class="brd-hdr"><div><?php if($logoUrl&&$showLogo):?><img src="<?=$logoUrl?>"><?php else:?><h1><?=e($siteName)?></h1><?php endif;?></div><div class="right"><p style="font-size:20px;font-weight:700;margin-bottom:4px">#<?=e($order['order_number'])?></p><p><?=date('d M Y',strtotime($order['created_at']))?></p><p><?=$pay?></p><?php if($showBarcode):?><div style="margin-top:6px;filter:invert(1)"><svg id="<?=$barcodeId?>" class="barcode-svg" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div></div>
    <div class="brd-addr"><div><h4>Customer</h4><p><strong><?=e($order['customer_name'])?></strong><br>📞 <?=e($order['customer_phone'])?></p></div><div><h4>Ship To</h4><p><?=e($addr)?></p></div></div>
    <table class="brd-tbl"><thead><tr><th>#</th><th>Product</th><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead><tbody>
    <?php foreach($items as $i=>$it):?><tr><td><?=$i+1?></td><td><?=e($it['product_name'])?></td><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['price'])?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
    </tbody></table>
    <div class="brd-tots"><table><tr><td>Subtotal</td><td class="text-right">৳<?=number_format($order['subtotal'])?></td></tr><tr><td>Delivery</td><td class="text-right">৳<?=number_format($order['shipping_cost'])?></td></tr><?php if($showDiscount&&$discount>0):?><tr><td>Discount</td><td class="text-right">-৳<?=number_format($discount)?></td></tr><?php endif;?><tr class="grand"><td>Due Amount</td><td class="text-right">৳<?=number_format($due)?></td></tr></table></div>
</div>

<?php elseif($tpl==='inv_detailed'):?>
<div class="invoice <?=$pb?>">
    <div class="det-hdr"><div><?php if($logoUrl&&$showLogo):?><img src="<?=$logoUrl?>"><?php else:?><h1><?=e($siteName)?></h1><?php endif;?></div><div style="text-align:right"><p><strong>#<?=e($order['order_number'])?></strong></p><p style="font-size:12px;color:#666"><?=date('d M Y',strtotime($order['created_at']))?> | <?=$pay?></p><?php if($showBarcode):?><div class="barcode-wrap" style="margin-top:4px"><svg id="<?=$barcodeId?>" class="barcode-svg" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div></div>
    <div class="det-grid"><div class="det-box"><h4>Customer</h4><p><strong><?=e($order['customer_name'])?></strong><br><?=e($order['customer_phone'])?></p></div><div class="det-box"><h4>Delivery</h4><p><?=e($addr)?><?=$showCourier&&$courier?"<br>Courier: ".e($courier):""?><?=$showParcel&&$parcel?"<br>Parcel: ".e($parcel):""?></p></div></div>
    <table class="det-tbl"><thead><tr><th style="width:50px"></th><th>Product</th><?php if($showSku):?><th>SKU</th><?php endif;?><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead><tbody>
    <?php foreach($items as $it):$img=(!empty($it['featured_image']))?SITE_URL.'/uploads/products/'.$it['featured_image']:'';?>
    <tr><td><?php if($img):?><img src="<?=$img?>" class="pi"><?php else:?><div style="width:40px;height:40px;background:#f3f4f6;border-radius:6px;text-align:center;line-height:40px">📦</div><?php endif;?></td><td><strong><?=e($it['product_name'])?></strong><?=$showVariant&&!empty($it['variant_name'])?"<br><span style='font-size:11px;color:#666'>".e($it['variant_name'])."</span>":""?></td><?php if($showSku):?><td style="font-size:11px;color:#666"><?=e($it['sku']??'-')?></td><?php endif;?><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['price'])?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr>
    <?php endforeach;?></tbody></table>
    <table class="det-tots"><tr><td>Subtotal</td><td class="text-right">৳<?=number_format($order['subtotal'])?></td></tr><tr><td>Shipping</td><td class="text-right">৳<?=number_format($order['shipping_cost'])?></td></tr><?php if($showDiscount&&$discount>0):?><tr><td>Discount</td><td class="text-right" style="color:#dc2626">-৳<?=number_format($discount)?></td></tr><?php endif;?><tr class="grand"><td>Due</td><td class="text-right">৳<?=number_format($due)?></td></tr></table>
</div>

<?php elseif($tpl==='inv_minimal'):?>
<div class="invoice <?=$pb?>">
    <div style="display:flex;justify-content:space-between;margin-bottom:40px"><h1 style="font-size:28px;font-weight:300;color:#111;letter-spacing:2px">Invoice</h1><div style="font-size:12px;color:#888;text-align:right;line-height:1.8"><strong style="color:#333">#<?=e($order['order_number'])?></strong><br><?=date('d M Y',strtotime($order['created_at']))?><br><?=$pay?><?php if($showBarcode):?><div class="barcode-wrap" style="margin-top:4px"><svg id="<?=$barcodeId?>" class="barcode-svg" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div></div>
    <div style="margin-bottom:30px;line-height:1.7"><div style="font-size:10px;color:#aaa;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Bill To</div><strong><?=e($order['customer_name'])?></strong><br><?=e($order['customer_phone'])?><br><?=e($addr)?></div>
    <table class="min-tbl"><thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Amount</th></tr></thead><tbody>
    <?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?></td><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['price'])?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
    </tbody></table>
    <div style="text-align:right;margin-bottom:30px"><div class="min-row"><span>Subtotal</span><span>৳<?=number_format($order['subtotal'])?></span></div><div class="min-row"><span>Shipping</span><span>৳<?=number_format($order['shipping_cost'])?></span></div><?php if($showDiscount&&$discount>0):?><div class="min-row"><span>Discount</span><span>-৳<?=number_format($discount)?></span></div><?php endif;?><div class="min-row min-grand"><span>Due</span><span>৳<?=number_format($due)?></span></div></div>
    <div style="text-align:center;font-size:11px;color:#aaa;margin-top:30px"><?=$customFooter?e($customFooter):e($siteName)?></div>
</div>

<?php elseif($tpl==='inv_picking'):?>
<div class="picking <?=$pb?>">
    <div style="display:flex;justify-content:space-between;margin-bottom:15px;border-bottom:2px solid #333;padding-bottom:10px"><div><h2 style="font-size:16px">PICKING SHEET</h2><p style="font-size:12px;color:#666"><?=date('d M Y, h:i A')?></p></div><div style="text-align:right"><p><strong>#<?=e($order['order_number'])?></strong></p><p style="font-size:12px"><?=$dt?></p><?php if($showBarcode):?><div class="barcode-wrap" style="margin-top:4px"><svg id="<?=$barcodeId?>" class="barcode-svg" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div></div>
    <div style="display:flex;justify-content:space-between;margin-bottom:12px;padding:10px;background:#f9fafb;border-radius:6px"><div><strong><?=e($order['customer_name'])?></strong> | <?=e($order['customer_phone'])?></div><div><strong><?=$pay?></strong> | ৳<?=number_format($order['total'])?></div></div>
    <table class="pick-tbl"><thead><tr><th style="width:40px">✓</th><th>Product</th><th>SKU</th><th>Variant</th><th class="text-center">Qty</th><th>Location</th></tr></thead><tbody>
    <?php foreach($items as $it):?><tr><td class="text-center"><div class="pick-chk"></div></td><td><strong><?=e($it['product_name'])?></strong></td><td><?=e($it['sku']??'-')?></td><td><?=!empty($it['variant_name'])?e($it['variant_name']):'-'?></td><td class="text-center" style="font-size:16px;font-weight:bold"><?=$it['quantity']?></td><td></td></tr><?php endforeach;?>
    </tbody></table>
</div>

<?php elseif($tpl==='stk_standard'):?>
<div class="sticker"><div style="position:absolute;top:6px;right:8px;background:#000;color:#fff;padding:1px 8px;font-size:10px;font-weight:700"><?=$pay?></div>
<div style="display:flex;justify-content:space-between;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:6px;font-size:10px"><strong style="font-size:13px"><?=e($siteName)?></strong><span>#<?=e($order['order_number'])?></span></div>
<div><p style="font-size:14px;font-weight:700"><?=e($order['customer_name'])?></p><p>📞 <?=e($order['customer_phone'])?></p><p>📍 <?=e(mb_strimwidth($addr,0,100,'...'))?></p>
<div style="font-size:10px;color:#444;margin-top:5px;border-top:1px dashed #999;padding-top:4px"><?php foreach($items as $it):?><?=e($it['product_name'])?> × <?=$it['quantity']?><?=$showVariant&&!empty($it['variant_name'])?' ('.e($it['variant_name']).')':''?><br><?php endforeach;?></div></div>
<div style="font-size:18px;font-weight:900;text-align:right;margin-top:4px">৳<?=number_format($due)?></div><?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_detailed'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:6px"><div style="font-size:14px;font-weight:800"><?=e($siteName)?></div><div style="text-align:right;font-size:9px;color:#555">Date: <?=$dt?><br>IV: <?=e($order['order_number'])?></div></div>
<?=$showCourier&&$courier?"<span style='font-size:9px;background:#000;color:#fff;padding:1px 4px'>Courier: ".e($courier)."</span><br>":""?>
<div style="font-size:13px;font-weight:700"><?=e($order['customer_name'])?></div><div style="font-size:11px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e(mb_strimwidth($addr,0,80,'...'))?></div>
<table class="stk-ptbl"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=e(mb_strimwidth($it['product_name'],0,22,'...'))?></td><td><?=$it['quantity']?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?></tbody></table>
<div style="display:flex;justify-content:space-between;border-top:2px solid #000;padding-top:5px;margin-top:4px"><div style="font-size:10px">Sub: ৳<?=number_format($order['subtotal'])?> | Del: ৳<?=number_format($order['shipping_cost'])?></div><div style="font-size:16px;font-weight:900">৳<?=number_format($due)?></div></div>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>d" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_courier'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:4px"><strong style="font-size:14px"><?=e($siteName)?></strong><div style="text-align:right;font-size:9px"><?=$dt?><br><?=e($order['order_number'])?></div></div>
<?=$showCourier&&$courier?"<div style='background:#000;color:#fff;padding:3px 8px;font-size:10px;font-weight:700;display:inline-block;margin-bottom:6px'>Courier: ".e($courier)."</div>":""?>
<div style="margin:6px 0;line-height:1.4"><div style="font-size:14px;font-weight:800"><?=e($order['customer_name'])?></div>📞 <?=e($order['customer_phone'])?><br>📍 <?=e(mb_strimwidth($addr,0,80,'...'))?></div>
<?=$showParcel&&$parcel?"<div style='font-size:10px;color:#555'>Parcel ID: ".e($parcel)."</div>":""?>
<div style="font-size:10px;border-top:1px dashed #999;padding-top:4px;margin-top:4px"><?php foreach($items as $it):?><?=e($it['product_name'])?> × <?=$it['quantity']?><br><?php endforeach;?></div>
<div style="display:flex;justify-content:space-between;align-items:center;border-top:2px solid #000;padding-top:6px;margin-top:6px"><div style="font-size:10px"><?=$pay?></div><div style="font-size:20px;font-weight:900">৳<?=number_format($due)?></div></div>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_pos'):?>
<div class="sticker">
<div style="font-size:16px;font-weight:800"><?=e($siteName)?></div><?php if($showPhone):?><div style="font-size:10px;color:#666"><?=e($sitePhone)?></div><?php endif;?>
<div style="border-top:1px dashed #999;margin:6px 0"></div>
<div style="display:flex;justify-content:space-between;font-size:10px;color:#555;margin-bottom:4px"><span>#<?=e($order['order_number'])?></span><span><?=$dt?></span></div>
<div style="text-align:left;margin:6px 0;font-size:12px;line-height:1.5"><strong style="font-size:14px"><?=e($order['customer_name'])?></strong><br>📞 <?=e($order['customer_phone'])?><br>📍 <?=e(mb_strimwidth($addr,0,80,'...'))?></div>
<div style="border-top:1px dashed #999;margin:6px 0"></div>
<table class="stk-pos-tbl"><thead><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right">Total</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=e(mb_strimwidth($it['product_name'],0,22,'...'))?></td><td class="text-right"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?></tbody></table>
<div style="border-top:1px dashed #999;margin:6px 0"></div>
<div style="text-align:right;margin:6px 0;font-size:11px;line-height:1.6">Sub Total: ৳<?=number_format($order['subtotal'])?><br>Delivery: ৳<?=number_format($order['shipping_cost'])?><br><span style="font-size:18px;font-weight:900">Due: ৳<?=number_format($due)?></span></div>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_cod'):?>
<div class="sticker">
<div style="background:#000;color:#fff;padding:6px 0;text-align:center;font-size:16px;font-weight:900;letter-spacing:2px;margin:-12px -12px 10px;border-bottom:3px solid #000"><?=$pay?> - ৳<?=number_format($due)?></div>
<div style="line-height:1.5;font-size:12px"><p style="font-size:15px;font-weight:700"><?=e($order['customer_name'])?></p><p>📞 <?=e($order['customer_phone'])?></p><p>📍 <?=e(mb_strimwidth($addr,0,90,'...'))?></p>
<div style="font-size:10px;border-top:1px dashed #aaa;padding-top:5px;margin-top:6px"><?php foreach($items as $it):?><?=e($it['product_name'])?> × <?=$it['quantity']?><br><?php endforeach;?></div></div>
<div style="text-align:center;font-size:22px;font-weight:900;margin-top:8px;padding-top:6px;border-top:2px solid #000">৳<?=number_format($due)?></div>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_wide'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:8px"><div style="font-size:16px;font-weight:800"><?=e($siteName)?></div><div style="font-size:12px;font-weight:700;text-align:right"><?=e($order['order_number'])?><br><span style="font-size:9px;color:#555;font-weight:400"><?=$dt?></span></div></div>
<div style="display:flex;gap:12px;margin-bottom:8px"><div style="flex:1"><div style="font-size:14px;font-weight:700;margin-bottom:2px"><?=e($order['customer_name'])?></div><p style="font-size:11px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e(mb_strimwidth($addr,0,80,'...'))?></p></div>
<div style="text-align:right;min-width:80px;display:flex;flex-direction:column;justify-content:center;align-items:flex-end"><div style="font-size:22px;font-weight:900">৳<?=number_format($due)?></div><div style="font-size:9px;background:#000;color:#fff;padding:1px 6px;margin-top:2px"><?=$pay?></div></div></div>
<div style="font-size:10px;border-top:1px dashed #999;padding-top:4px"><?php foreach($items as $it):?><?=e($it['product_name'])?> × <?=$it['quantity']?> — ৳<?=number_format($it['subtotal'])?><br><?php endforeach;?><div style="text-align:right;margin-top:2px">Sub: ৳<?=number_format($order['subtotal'])?> | Del: ৳<?=number_format($order['shipping_cost'])?></div></div>
<?php if($showNotes&&$notes):?><div style="font-size:9px;color:#555;border-top:1px solid #ddd;padding-top:4px;margin-top:4px"><strong>Note:</strong> <?=e(mb_strimwidth($notes,0,80,'...'))?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_sku'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:6px"><div style="font-size:14px;font-weight:800"><?=e($siteName)?></div><div style="font-size:10px">IV: <?=e($order['order_number'])?><br><?=$dt?></div></div>
<?=$showCourier&&$courier?"<span style='font-size:9px;background:#000;color:#fff;padding:0 4px'>".e($courier)."</span> ":""?><span style="font-size:13px;font-weight:700"><?=e($order['customer_name'])?></span><br><span style="font-size:11px">📞 <?=e($order['customer_phone'])?></span><br><span style="font-size:10px">📍 <?=e(mb_strimwidth($addr,0,70,'...'))?></span>
<table class="sku-tbl"><thead><tr><th>SKU</th><th>Price</th><th>Total</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=e($it['sku']??mb_strimwidth($it['product_name'],0,18,'...'))?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?></tbody></table>
<div style="display:flex;justify-content:space-between;border-top:2px solid #000;padding-top:5px;margin-top:4px;font-size:10px"><div>Sub: ৳<?=number_format($order['subtotal'])?><br>Del: ৳<?=number_format($order['shipping_cost'])?></div><div style="font-size:16px;font-weight:900">৳<?=number_format($due)?></div></div>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_note'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:6px"><div style="font-size:13px;font-weight:800"><?=e($siteName)?></div><div style="font-size:10px"><?=e($order['order_number'])?><br><?=$dt?></div></div>
<div style="font-size:14px;font-weight:700"><?=e($order['customer_name'])?></div>
<div style="font-size:11px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e(mb_strimwidth($addr,0,70,'...'))?></div>
<div style="font-size:10px;border-top:1px dashed #999;padding-top:4px;margin:5px 0"><?php foreach($items as $it):?><?=e($it['product_name'])?> × <?=$it['quantity']?> — ৳<?=number_format($it['subtotal'])?><br><?php endforeach;?></div>
<div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:5px"><div>Sub: ৳<?=number_format($order['subtotal'])?> | Del: ৳<?=number_format($order['shipping_cost'])?></div><div style="font-size:16px;font-weight:900">৳<?=number_format($due)?></div></div>
<div style="border-top:1px solid #ccc;padding-top:5px">
<?php if($showNotes&&$notes):?><p style="font-size:8px;text-transform:uppercase;color:#666;font-weight:700;margin-bottom:1px">Order Note:</p><p style="font-size:9px;line-height:1.3"><?=e(mb_strimwidth($notes,0,120,'...'))?></p><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><p style="font-size:8px;text-transform:uppercase;color:#666;font-weight:700;margin-top:3px;margin-bottom:1px">Shipping Note:</p><p style="font-size:9px;line-height:1.3"><?=e(mb_strimwidth($shippingNote,0,120,'...'))?></p><?php endif;?>
</div>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php endif; /* end template switch */ ?>

<?php
    // Close layout cell
    if ($useLayout): ?></div><!-- /layout-cell --><?php endif;

    // Close layout group wrapper at group end
    if ($useLayout && $isGroupEnd): ?></div><!-- /layout-row --><?php endif;
?>
<?php endforeach;?>

<?php if($showBarcode): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
    // Find all barcode SVGs and render them
    document.querySelectorAll('svg[data-value]').forEach(function(svg){
        var val = svg.getAttribute('data-value');
        if(!val) return;
        var isSmall = svg.classList.contains('barcode-svg-sm');
        try {
            JsBarcode(svg, val, {
                format: "CODE128",
                width: isSmall ? 1.2 : 1.5,
                height: isSmall ? 28 : 40,
                displayValue: true,
                fontSize: isSmall ? 10 : 12,
                font: "monospace",
                textMargin: 2,
                margin: 0,
                background: "transparent"
            });
        } catch(e) {
            // Fallback: show text if barcode fails
            svg.parentNode.innerHTML = '<span style="font-family:monospace;font-size:11px;letter-spacing:1px">'+val+'</span>';
        }
    });
});
</script>
<?php endif; ?>

</body></html>
