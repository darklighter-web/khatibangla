<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
refreshAdminPermissions();
requirePermission('orders', 'print');
$db = Database::getInstance();

// Ensure table exists
try { $db->query("CREATE TABLE IF NOT EXISTS print_templates (id INT AUTO_INCREMENT PRIMARY KEY,template_key VARCHAR(100) NOT NULL UNIQUE,template_type ENUM('invoice','sticker') NOT NULL DEFAULT 'invoice',name VARCHAR(100) NOT NULL,description VARCHAR(255) DEFAULT '',base_template VARCHAR(100) NOT NULL DEFAULT 'inv_standard',is_builtin TINYINT(1) NOT NULL DEFAULT 0,config JSON DEFAULT NULL,created_by INT DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_type(template_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(\Throwable $e){}

$isPreview  = !empty($_GET['preview']);
$isExtract  = !empty($_GET['extract']); // output sticker HTML fragment only (for modal fetch preview)
$isModal    = !empty($_GET['modal']); // suppress toolbar in iframe/extract
if ($isExtract) { ob_start(); $isModal = true; } // capture ALL output, hide toolbar
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
// Reusable logo/name helpers — logo always shown if available, never fall back to text name
// Invoice size: max-height 48px
// Sticker size: max-height 28px (fits narrow labels)
function logoOrName(string $logoUrl, string $siteName, string $maxH = '48px', string $extraStyle = ''): string {
    if ($logoUrl) {
        return '<img src="'.htmlspecialchars($logoUrl, ENT_QUOTES).'" style="max-height:'.$maxH.';max-width:140px;object-fit:contain;display:block;'.$extraStyle.'">';
    }
    return '<span style="font-weight:800;font-size:'.($maxH==='24px'?'11':'14').'px">'.htmlspecialchars($siteName, ENT_QUOTES).'</span>';
}
// Shipping note: use custom text from preset, fallback to site setting
// Respects show_shipping_note toggle — if off, force empty
$_shipNoteText = cfg('custom_shipping_note', '') ?: getSetting('invoice_shipping_note', '');
$showShipNote  = cfg('show_shipping_note', true);
$shippingNote  = $showShipNote ? $_shipNoteText : '';
$customFooter = cfg('custom_footer', '');

// Color overrides from custom config
$primaryColor = cfg('primary_color', '#2563eb');
$headerBg = cfg('header_bg', '');
$fontSize = intval(cfg('font_size', 13));
// Sticker width: from URL param (set by size selector in modal), config, or template default
$tplDefaultWidths = [
    'stk_standard'=>288,'stk_detailed'=>288,'stk_courier'=>288,'stk_pos'=>302,'stk_cod'=>288,
    'stk_wide'=>384,'stk_sku'=>288,'stk_note'=>288,'stk_compact'=>192,'stk_thermal'=>280,
    'stk_thermal_m'=>280,'stk_thermal_sku'=>280,'stk_2in'=>192,'stk_3in'=>288,'stk_cod_t'=>280,
    'stk_4x3'=>384,'stk_3in_note'=>288,'stk_3sq'=>288,'stk_mini'=>144,
];
$stickerWidth = intval($_GET['sticker_width'] ?? cfg('sticker_width', $tplDefaultWidths[$baseTemplate] ?? 288));
// Label physical dimensions from modal size selector (for display only — NOT used in @page)
$labelW = preg_replace('/[^0-9a-z.]/', '', $_GET['label_w'] ?? '76.2mm');
$labelH = preg_replace('/[^0-9a-z.]/', '', $_GET['label_h'] ?? '101.6mm');

// Show/hide toggles
$showLogo = cfg('show_logo', true);
$showPhone = cfg('show_phone', true);
$boldText = cfg('bold_text', false); // Sticker text bold toggle
$showAddress = cfg('show_address', true);
$showSku = cfg('show_sku', false);
$showImages = cfg('show_images', false);
$showVariant = cfg('show_variant', true);
$showCourier = cfg('show_courier', true);
$showParcel = cfg('show_parcel', true);
$showNotes = cfg('show_notes', true);
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
// Detect sticker vs invoice
$isSticker = strpos($tpl, 'stk_') === 0;

// Physical label dimensions per template (width × height in mm)
// Die-cut roll printers (HOIN, Xprinter, Zebra, TSC) use @page size
// to tell the printer exactly when to advance to the next label.
// Values are standard label stock sizes.
if ($isSticker) {
    $labelSizes = [
        'stk_standard'    => [76.2, 101.6], // 3×4 inch — default
        'stk_compact'     => [50.8, 76.2],  // 2×3 inch
        'stk_detailed'    => [76.2, 101.6], // 3×4 inch
        'stk_courier'     => [76.2, 101.6], // 3×4 inch
        'stk_pos'         => [80.0,   0],   // 80mm width, continuous
        'stk_cod'         => [76.2, 101.6], // 3×4 inch
        'stk_wide'        => [101.6, 76.2], // 4×3 inch landscape
        'stk_sku'         => [76.2, 101.6], // 3×4 inch
        'stk_note'        => [76.2, 101.6], // 3×4 inch
        'stk_thermal'     => [75.0,  50.0], // 75×50mm
        'stk_thermal_m'   => [75.0,  50.0], // 75×50mm
        'stk_thermal_sku' => [75.0,  50.0], // 75×50mm
        'stk_2in'         => [50.8, 101.6], // 2×4 inch
        'stk_3in'         => [76.2, 101.6], // 3×4 inch
        'stk_cod_t'       => [75.0,  50.0], // 75×50mm
        'stk_4x3'         => [101.6, 76.2], // 4×3 inch
        'stk_3in_note'    => [76.2, 101.6], // 3×4 inch
        'stk_3sq'         => [76.2,  76.2], // 3×3 inch square
        'stk_mini'        => [38.0,  25.0], // 38×25mm
    ];
    $sz  = $labelSizes[$tpl] ?? [76.2, 101.6];
    $pgW = $sz[0].'mm';
    $pgH = $sz[1] > 0 ? $sz[1].'mm' : 'auto';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Print <?= count($orders) ?> Order(s)</title>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
:root{--primary:<?=$primaryColor?>;--font-size:<?=$fontSize?>px;--stk-w:<?=$stickerWidth?>px;--label-w:<?=$labelW?>;--label-h:<?=$labelH?>}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:var(--font-size);color:#333;background:#f9fafb}
.no-print{background:#f3f4f6;padding:10px 20px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid #e5e7eb;position:sticky;top:0;z-index:10}
.no-print button,.no-print a{padding:7px 14px;border-radius:8px;font-size:12px;cursor:pointer;text-decoration:none;border:1px solid #d1d5db;background:white;color:#374151;white-space:nowrap}
.no-print button:hover,.no-print a:hover{background:#f9fafb}.no-print .active{background:var(--primary);color:white;border-color:var(--primary)}
.text-right{text-align:right}.text-center{text-align:center}
.barcode-wrap{margin-top:8px;text-align:center}.barcode-wrap svg{max-width:100%}
.barcode-wrap-sm{margin-top:5px;text-align:center}.barcode-wrap-sm svg{max-width:100%;height:auto}
<?php if ($isSticker): ?>
/* STICKER CSS */
/* Print: @page matches label, content fills label exactly */
@media print{
  .no-print{display:none!important}
  html,body{padding:0;margin:0;background:#fff}
  @page{size:<?= $labelW ?> <?= $labelH ?>;margin:0}
  .sticker{display:block;width:<?= $labelW ?>!important;padding:3mm;margin:0!important;box-sizing:border-box;page-break-inside:avoid;break-inside:avoid}
  .stk-sep{display:block;width:0;height:0;margin:0;padding:0;page-break-before:always;break-before:page}
}
/* Screen: preview box */
@media screen{
  html{overflow-x:hidden}
  body{background:#f4f4f4;padding:8px;overflow-x:hidden;box-sizing:border-box}
  .sticker{display:block;width:var(--stk-w)!important;max-width:calc(100% - 16px)!important;min-height:calc(var(--stk-w) * 1.38);margin:0 auto!important;background:#fff;box-shadow:0 2px 12px rgba(0,0,0,.15);box-sizing:border-box}
  .stk-sep{display:none}
}
/* Base sticker — never overridden by per-template rules below */
.sticker{position:relative;box-sizing:border-box;background:#fff;overflow:hidden}
<?php if($boldText): ?>.sticker *{font-weight:700!important}.sticker strong{font-weight:900!important}<?php endif; ?>
/* Per-template extra styles (tables etc) — no .sticker overrides */
<?php if ($tpl==='stk_pos'): ?>
.sticker{border-bottom:2px dashed #ccc!important;border-left:none!important;border-right:none!important;border-top:none!important;border-radius:0!important;text-align:center}
.stk-pos-tbl{width:100%;font-size:10px;border-collapse:collapse}.stk-pos-tbl th{border-bottom:1px dashed #999;padding:3px 2px;font-size:9px;color:#777}.stk-pos-tbl td{padding:3px 2px;border-bottom:1px dotted #eee}
<?php elseif ($tpl==='stk_cod'): ?>
.sticker{border:3px solid #000!important;border-radius:10px!important}
<?php elseif ($tpl==='stk_cod_t'): ?>
.sticker{border:3px solid #111!important;border-radius:8px!important}
<?php elseif ($tpl==='stk_detailed'): ?>
.stk-ptbl{width:100%;border-collapse:collapse;font-size:10px;margin:5px 0}.stk-ptbl th{text-align:left;border-bottom:1px solid #999;padding:2px 4px;font-size:9px;color:#666}.stk-ptbl td{padding:2px 4px;border-bottom:1px solid #eee}
<?php elseif ($tpl==='stk_sku' || $tpl==='stk_thermal_sku' || $tpl==='stk_3sq'): ?>
.sku-tbl,.tsku-tbl,.s3sq-tbl{width:100%;border-collapse:collapse;font-size:9px;margin:3px 0}.sku-tbl th,.tsku-tbl th,.s3sq-tbl th{background:#f0f0f0;border:1px solid #ccc;padding:2px 4px;font-size:8px}.sku-tbl td,.tsku-tbl td,.s3sq-tbl td{border:1px solid #ddd;padding:2px 4px}
<?php elseif ($tpl==='stk_thermal' || $tpl==='stk_thermal_m' || $tpl==='stk_2in' || $tpl==='stk_3in' || $tpl==='stk_3in_note' || $tpl==='stk_compact'): ?>
.thm-ptbl,.s2in-ptbl,.s3in-ptbl,.s3n-ptbl{width:100%;border-collapse:collapse;font-size:9px;margin:3px 0}.thm-ptbl th,.s2in-ptbl th,.s3in-ptbl th,.s3n-ptbl th{border-bottom:1px solid #999;padding:1px 3px;font-size:8px;color:#555}.thm-ptbl td,.s2in-ptbl td,.s3in-ptbl td,.s3n-ptbl td{padding:1px 3px;border-bottom:1px solid #eee}
<?php elseif ($tpl==='stk_4x3'): ?>
.s4x3-tbl{width:100%;border-collapse:collapse;font-size:10px;margin:4px 0}.s4x3-tbl th{background:#111;color:#fff;padding:3px 5px;font-size:8px;text-transform:uppercase}.s4x3-tbl td{border-bottom:1px solid #ddd;padding:3px 5px}
<?php endif; ?>

<?php else: ?>
/* ── INVOICE / LAYOUT CSS ──────────────────────────── */
<?php if ($layout==='a4_1'): ?>
@media print{
  .no-print{display:none!important}
  body{padding:0;background:#fff}
  @page{size:A4 portrait;margin:10mm}
  .invoice,.picking{page-break-after:always;break-after:page}
  .invoice:last-of-type,.picking:last-of-type{page-break-after:auto;break-after:auto}
  .page-break{page-break-after:always;break-after:page}
}
<?php elseif ($layout==='a3_2'): ?>
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
.layout-a3-row .det-grid{gap:12px}
.layout-a3-row .barcode-wrap svg{transform:scale(.75);transform-origin:right}
@media print{.no-print{display:none!important}body{padding:0;background:#fff}@page{size:A3 landscape;margin:8mm}.layout-a3-row{page-break-after:always;break-after:page}.layout-a3-row:last-of-type{page-break-after:auto;break-after:auto}}
<?php elseif ($layout==='a4_3'): ?>
.layout-a4-triple{display:flex;page-break-after:always;align-items:flex-start;gap:0}
.layout-cell-3{flex:1;min-width:0;border-right:1px dashed #ccc;padding:8px}
.layout-cell-3:last-child{border-right:none}
.layout-a4-triple .invoice{max-width:100%!important;padding:8px!important;margin:0!important}
.layout-a4-triple .invoice *{font-size:85%}
.layout-a4-triple .inv-hdr .logo h1{font-size:14px!important}
.layout-a4-triple .inv-hdr .info h2{font-size:12px!important}
.layout-a4-triple .inv-addr{margin-bottom:8px!important;padding:8px}
.layout-a4-triple .inv-tbl thead th,.layout-a4-triple .mod-tbl thead th{padding:4px 8px!important;font-size:9px!important}
.layout-a4-triple .inv-tbl tbody td,.layout-a4-triple .mod-tbl tbody td{padding:3px 8px!important}
.layout-a4-triple .inv-tots table{width:200px}
.layout-a4-triple .inv-tots td{padding:2px 8px!important}
.layout-a4-triple .inv-tots .grand td{font-size:13px!important}
.layout-a4-triple .inv-note{margin-top:6px!important;padding:5px 8px!important;font-size:10px!important}
.layout-a4-triple .inv-foot{margin-top:8px!important;padding-top:6px!important;font-size:9px!important}
.layout-a4-triple .mod-meta{gap:12px;padding:8px;margin-bottom:10px!important}
.layout-a4-triple .brd-hdr{padding:12px 15px;margin-bottom:10px!important}
.layout-a4-triple .brd-addr{padding:8px;margin-bottom:8px!important;gap:10px}
.layout-a4-triple .det-grid{gap:10px;margin-bottom:8px!important}
.layout-a4-triple .barcode-wrap svg{transform:scale(.7);transform-origin:center}
.layout-a4-triple .barcode-wrap-sm svg{transform:scale(.65);transform-origin:center}
@media print{.no-print{display:none!important}body{padding:0;background:#fff}@page{size:A4 portrait;margin:5mm}.layout-a4-triple{page-break-after:always;break-after:page}.layout-a4-triple:last-of-type{page-break-after:auto;break-after:auto}}
@media screen{.layout-a4-triple{max-width:800px;margin:10px auto;background:#fff;padding:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);border-radius:8px;height:auto}}
<?php endif; ?>
<?php if ($tpl==='inv_standard'): ?>
.invoice{max-width:800px;margin:0 auto;padding:30px}
.inv-hdr{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:25px;border-bottom:3px solid var(--primary);padding-bottom:18px}
.inv-hdr .logo h1{font-size:22px;color:var(--primary)}.inv-hdr .logo p{font-size:11px;color:#666;margin-top:3px}.inv-hdr .logo img{max-height:50px;margin-bottom:6px}
.inv-hdr .info{text-align:right}.inv-hdr .info h2{font-size:18px;color:#333;margin-bottom:4px}.inv-hdr .info p{font-size:12px;color:#666}
.inv-addr{display:flex;justify-content:space-between;margin-bottom:22px}.inv-addr>div{width:48%}.inv-addr h3{font-size:10px;text-transform:uppercase;color:#999;letter-spacing:1px;margin-bottom:6px}.inv-addr p{font-size:var(--font-size);line-height:1.6}
.inv-tbl{width:100%;border-collapse:collapse;margin-bottom:20px}.inv-tbl thead th{background:#f3f4f6;padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:#666}.inv-tbl tbody td{padding:10px 12px;border-bottom:1px solid #eee}
.inv-tots{display:flex;justify-content:flex-end}.inv-tots table{width:280px}.inv-tots td{padding:5px 12px}.inv-tots .grand td{font-weight:bold;font-size:16px;border-top:2px solid #333;padding-top:10px}
.inv-foot{margin-top:30px;text-align:center;font-size:11px;color:#999;border-top:1px solid #eee;padding-top:15px}
.inv-note{margin-top:15px;background:#f9fafb;padding:10px 14px;border-radius:6px;font-size:12px}
<?php elseif ($tpl==='inv_compact'): ?>
.invoice{max-width:400px;margin:10px auto;padding:15px;border:1px solid #ddd;font-size:12px}
.cmp-hdr{text-align:center;border-bottom:2px dashed #999;padding-bottom:10px;margin-bottom:10px}.cmp-hdr h2{font-size:16px;color:var(--primary)}.cmp-hdr p{font-size:11px;color:#666}
.cmp-tbl{width:100%;border-collapse:collapse;margin-bottom:10px;font-size:12px}.cmp-tbl th{text-align:left;border-bottom:1px solid #ccc;padding:4px 6px;font-size:10px;color:#666}.cmp-tbl td{padding:4px 6px;border-bottom:1px solid #f0f0f0}
<?php elseif ($tpl==='inv_modern'): ?>
.invoice{max-width:800px;margin:0 auto;padding:40px}
.mod-badge{background:#111;color:#fff;padding:6px 18px;border-radius:4px;font-size:14px;font-weight:700;letter-spacing:2px}
.mod-meta{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-bottom:25px;padding:20px;background:#f8f9fa;border-radius:10px}.mod-meta h4{font-size:10px;text-transform:uppercase;color:#999;letter-spacing:1px;margin-bottom:6px}.mod-meta p{font-size:var(--font-size);line-height:1.6}
.mod-tbl{width:100%;border-collapse:collapse;margin-bottom:25px}.mod-tbl thead{background:#111}.mod-tbl thead th{color:#fff;padding:12px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.5px}.mod-tbl tbody td{padding:12px 14px;border-bottom:1px solid #eee}.mod-tbl tbody tr:nth-child(even){background:#fafafa}
.mod-tots{margin-left:auto;width:280px}.mod-tots tr td{padding:6px 14px}.mod-tots .grand{background:#111;color:#fff}.mod-tots .grand td{padding:12px 14px;font-size:16px;font-weight:700}
<?php elseif ($tpl==='inv_branded'): ?>
.invoice{max-width:800px;margin:0 auto;padding:30px}
.brd-hdr{background:linear-gradient(135deg,<?= $headerBg ?: $primaryColor ?> 0%,<?= $primaryColor ?> 100%);color:white;padding:25px 30px;border-radius:12px;display:flex;justify-content:space-between;align-items:center;margin-bottom:25px}.brd-hdr h1{font-size:22px;font-weight:800}.brd-hdr img{max-height:45px;filter:brightness(10)}.brd-hdr .right{text-align:right}.brd-hdr .right p{font-size:12px;opacity:.85}
.brd-addr{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;padding:15px;background:<?= $primaryColor ?>08;border-radius:8px;border-left:4px solid var(--primary)}.brd-addr h4{font-size:10px;text-transform:uppercase;color:var(--primary);margin-bottom:4px;font-weight:700;letter-spacing:1px}
.brd-tbl{width:100%;border-collapse:collapse;margin-bottom:20px;border-radius:8px;overflow:hidden}.brd-tbl thead th{background:<?= $primaryColor ?>15;color:var(--primary);padding:10px 14px;font-size:11px;text-transform:uppercase}.brd-tbl tbody td{padding:10px 14px;border-bottom:1px solid <?= $primaryColor ?>15}
.brd-tots{display:flex;justify-content:flex-end}.brd-tots table{width:260px}.brd-tots td{padding:5px 12px}.brd-tots .grand td{font-weight:bold;font-size:16px;color:var(--primary);border-top:2px solid var(--primary);padding-top:10px}
<?php elseif ($tpl==='inv_detailed'): ?>
.invoice{max-width:800px;margin:0 auto;padding:30px}
.det-hdr{display:flex;justify-content:space-between;border-bottom:2px solid #e5e7eb;padding-bottom:15px;margin-bottom:20px}.det-hdr img{max-height:50px}.det-hdr h1{font-size:20px;color:#111}
.det-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}.det-box{padding:12px;border:1px solid #e5e7eb;border-radius:8px}.det-box h4{font-size:10px;text-transform:uppercase;color:#999;margin-bottom:5px;font-weight:700}
.det-tbl{width:100%;border-collapse:collapse;margin-bottom:20px}.det-tbl th{padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:#666;border-bottom:2px solid #e5e7eb}.det-tbl td{padding:10px;border-bottom:1px solid #f3f4f6;vertical-align:middle}.det-tbl .pi{width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb}
.det-tots{margin-left:auto;width:280px}.det-tots td{padding:5px 10px}.det-tots .grand td{font-weight:bold;font-size:16px;border-top:2px solid #111;padding-top:10px}
<?php elseif ($tpl==='inv_minimal'): ?>
.invoice{max-width:700px;margin:0 auto;padding:40px 30px}
.min-tbl{width:100%;border-collapse:collapse;margin-bottom:25px}.min-tbl th{padding:8px 0;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#aaa;border-bottom:1px solid #ddd}.min-tbl td{padding:10px 0;border-bottom:1px solid #f0f0f0}
.min-row{display:flex;justify-content:flex-end;gap:40px;padding:4px 0;color:#666}.min-grand{font-size:20px;font-weight:700;color:#111;border-top:1px solid #111;padding-top:8px;margin-top:4px}
<?php elseif ($tpl==='inv_picking'): ?>
.picking{max-width:800px;margin:0 auto;padding:20px}
.pick-tbl{width:100%;border-collapse:collapse;margin-bottom:20px}.pick-tbl th,.pick-tbl td{border:1px solid #ccc;padding:8px 10px;font-size:12px}.pick-tbl th{background:#f3f4f6;font-size:11px;text-transform:uppercase}
.pick-chk{width:30px;height:30px;border:2px solid #999;display:inline-block}
<?php endif; ?>
<?php endif; /* end isSticker/invoice CSS */ ?>
</style>
</head>
<body>
<?php if(!$isPreview && !$isModal): ?>
<div class="no-print">
    <button onclick="window.print()" style="background:var(--primary);color:white;border-color:var(--primary)">🖨 Print</button>
    <a href="<?=adminUrl('pages/order-management.php')?>">← Back</a>
    <span style="color:#aaa">|</span>
    <?php if ($isSticker): ?>
    <?php
    $stkTpls=['stk_standard'=>'Standard','stk_compact'=>'Compact','stk_detailed'=>'Detail-Stk',
              'stk_courier'=>'Courier','stk_pos'=>'POS','stk_cod'=>'COD','stk_wide'=>'Wide',
              'stk_sku'=>'SKU','stk_note'=>'Note','stk_thermal'=>'Thermal','stk_thermal_m'=>'Multi-Pg',
              'stk_thermal_sku'=>'SKU-Thm','stk_2in'=>'2-inch','stk_3in'=>'3-inch','stk_cod_t'=>'COD-T',
              'stk_4x3'=>'4×3','stk_3in_note'=>'3in+Note','stk_3sq'=>'3×3Sq','stk_mini'=>'Mini'];
    foreach($stkTpls as $t=>$l):?><a href="?<?=$idParam?>&template=<?=$t?>" class="<?=$template===$t?'active':''?>"><?=$l?></a><?php endforeach;?>
    <span style="margin-left:auto;font-size:12px;color:#888"><?=count($orders)?> order(s) · <?=$pgW?> × <?=$pgH?></span>
    <span style="margin-left:8px;font-size:10px;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;white-space:nowrap">
      ⚙ Label: <strong><?=e($labelW)?> × <?=e($labelH)?></strong> · Chrome: Margins=None · Scale=100% · Fit=OFF
    </span>
    <?php else: ?>
    <?php
    $invTpls=['inv_standard'=>'📄Standard','inv_compact'=>'📋Compact','inv_modern'=>'🎨Modern',
              'inv_branded'=>'🏷Branded','inv_detailed'=>'📸Detailed','inv_minimal'=>'✨Minimal','inv_picking'=>'📦Picking'];
    foreach($invTpls as $t=>$l):?><a href="?<?=$idParam?>&template=<?=$t?>&layout=<?=$layout?>" class="<?=$template===$t?'active':''?>"><?=$l?></a><?php endforeach;?>
    <span style="color:#aaa">|</span>
    <span style="font-size:11px;color:#666;font-weight:600">Page:</span>
    <a href="?<?=$idParam?>&template=<?=$template?>&layout=a4_1" class="<?=$layout==='a4_1'?'active':''?>" style="font-size:11px">A4×1</a>
    <a href="?<?=$idParam?>&template=<?=$template?>&layout=a3_2" class="<?=$layout==='a3_2'?'active':''?>" style="font-size:11px">A3×2</a>
    <a href="?<?=$idParam?>&template=<?=$template?>&layout=a4_3" class="<?=$layout==='a4_3'?'active':''?>" style="font-size:11px">A4×3</a>
    <span style="margin-left:auto;font-size:12px;color:#888"><?=count($orders)?> order(s) · <?=strtoupper(str_replace('_','×',$layout))?></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$isSticker = (strpos($tpl, 'stk_') === 0);
// Stickers never use page layouts — force single-label mode
if ($isSticker) { $layout = 'sticker'; }
$perPage   = ($layout==='a3_2') ? 2 : (($layout==='a4_3') ? 3 : 1);
$useLayout = (!$isSticker && $perPage > 1);
$totalOrders = count($orders);
?>
<?php if ($isExtract) $__stickerOutput = ''; ?>
<?php foreach ($orders as $idx => $order): ?>
<?php if ($isExtract) ob_start(); ?>
<?php
    $items      = $allItems[$order['id']] ?? [];
    $discount   = floatval($order['discount'] ?? $order['discount_amount'] ?? 0);
    $advance    = floatval($order['advance_amount'] ?? 0);
    $due        = floatval($order['total']) - $advance;
    $pay        = strtoupper($order['payment_method'] ?? 'COD');
    $courier    = $order['courier_name'] ?? '';
    $parcel     = $order['courier_consignment_id'] ?? $order['courier_tracking_id'] ?? '';
    $dt         = date('d/m/Y', strtotime($order['created_at']));
    $addr       = trim(($order['customer_address']??'').($order['customer_city']?', '.$order['customer_city']:'').($order['customer_district']?', '.$order['customer_district']:''));
    $notes      = $showNotes ? trim(($order['notes']??'').' '.($order['order_note']??'')) : '';
    $aNotes     = $order['admin_notes'] ?? '';
    $orderNote  = $showNotes ? ($order['order_note'] ?? '') : '';
    $barcodeVal = $order['order_number'] ?? ('ORD-'.$order['id']);
    $barcodeId  = 'bc_'.$idx;
    $posInGroup  = $idx % $perPage;
    $isGroupStart= ($posInGroup === 0);
    $isGroupEnd  = ($posInGroup === $perPage - 1) || ($idx === $totalOrders - 1);
    $pb = (!$useLayout && $idx < $totalOrders - 1) ? 'page-break' : '';
?>
<?php if ($useLayout && $isGroupStart): ?>
    <?php if ($layout === 'a3_2'): ?><div class="layout-a3-row"><?php elseif ($layout === 'a4_3'): ?><div class="layout-a4-triple"><?php endif; ?>
<?php endif; ?>
<?php if ($useLayout): ?><div class="layout-cell<?= $perPage===3?'-3':'' ?>"><?php endif; ?>
<?php if ($isSticker && $idx > 0): ?><div class="stk-sep"></div><?php endif; ?>
<?php if($tpl==='inv_standard'):?>
<div class="invoice <?=$pb?>">
    <div class="inv-hdr"><div class="logo"><?= logoOrName($logoUrl,$siteName,'48px') ?><?php if($sitePhone&&$showPhone):?><p>📞 <?=e($sitePhone)?></p><?php endif;?><?php if($siteAddress&&$showAddress):?><p>📍 <?=e($siteAddress)?></p><?php endif;?></div>
    <div class="info"><h2>INVOICE</h2><p><strong>#<?=e($order['order_number'])?></strong></p><p><?=date('d M Y',strtotime($order['created_at']))?></p><p><?=$pay?></p><?php if($showBarcode):?><div class="barcode-wrap" style="margin-top:6px"><svg id="<?=$barcodeId?>" class="barcode-svg" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div></div>
    <div class="inv-addr"><div><h3>Customer</h3><p><strong><?=e($order['customer_name'])?></strong><br>📞 <?=e($order['customer_phone'])?></p></div><div><h3>Ship To</h3><p><?=e($addr)?></p><?php if($showCourier&&$courier):?><p style="font-size:11px;color:#666;margin-top:4px">Courier: <?=e($courier)?><?=$showParcel&&$parcel?' | '.e($parcel):''?></p><?php endif;?></div></div>
    <table class="inv-tbl"><thead><tr><th>#</th><?php if($showImages):?><th></th><?php endif;?><th>Item</th><?php if($showSku):?><th>SKU</th><?php endif;?><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead><tbody>
    <?php foreach($items as $i=>$it):$img=(!empty($it['featured_image']))?SITE_URL.'/uploads/products/'.$it['featured_image']:'';?><tr><td><?=$i+1?></td><?php if($showImages):?><td><?php if($img):?><img src="<?=$img?>" style="width:36px;height:36px;object-fit:cover;border-radius:4px"><?php else:?>📦<?php endif;?></td><?php endif;?><td><strong><?=e($it['product_name'])?></strong><?=$showVariant&&!empty($it['variant_name'])?"<br><span style='color:#666;font-size:11px'>".e($it['variant_name'])."</span>":''?></td><?php if($showSku):?><td style="font-size:11px;color:#666"><?=e($it['sku']??'-')?></td><?php endif;?><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['price'])?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
    </tbody></table>
    <div class="inv-tots"><table><tr><td>Subtotal</td><td class="text-right">৳<?=number_format($order['subtotal'])?></td></tr><tr><td>Shipping</td><td class="text-right">৳<?=number_format($order['shipping_cost'])?></td></tr><?php if($showDiscount&&$discount>0):?><tr><td>Discount</td><td class="text-right" style="color:#dc2626">-৳<?=number_format($discount)?></td></tr><?php endif;?><?php if($showAdvance&&$advance>0):?><tr><td>Advance</td><td class="text-right" style="color:var(--primary)">-৳<?=number_format($advance)?></td></tr><?php endif;?><tr class="grand"><td>Due Amount</td><td class="text-right">৳<?=number_format($due)?></td></tr></table></div>
    <?php if($showNotes&&$orderNote):?><div class="inv-note"><strong>Note:</strong> <?=e($orderNote)?></div><?php endif;?>
    <?php if($showShipNote&&$shippingNote):?><div class="inv-note" style="margin-top:8px"><strong>Shipping:</strong> <?=e($shippingNote)?></div><?php endif;?>
    <?php if(!empty($order['is_preorder'])):?><div class="inv-note" style="margin-top:8px;background:#f5f3ff;border:1px solid #ddd6fe"><strong style="color:#7c3aed"><i>⏰ PREORDER</i></strong><?php if(!empty($order['preorder_date'])):?> — Expected: <?=date('d M Y',strtotime($order['preorder_date']))?><?php endif;?></div><?php endif;?>
    <div class="inv-foot"><?=$customFooter?e($customFooter):'Thank you for your order!'?></div>
</div>

<?php elseif($tpl==='inv_compact'):?>
<div class="invoice <?=$pb?>">
    <div class="cmp-hdr"><?= logoOrName($logoUrl,$siteName,'40px','margin:0 auto') ?><?php if($showPhone&&$sitePhone):?><p><?=e($sitePhone)?></p><?php endif;?></div>
    <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:11px;color:#555"><span>#<?=e($order['order_number'])?></span><span><?=$dt?></span></div>
    <div style="border:1px solid #eee;padding:8px;border-radius:4px;margin-bottom:10px;font-size:12px;line-height:1.5"><strong><?=e($order['customer_name'])?></strong><br>📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
    <table class="cmp-tbl"><thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-right">Total</th></tr></thead><tbody>
    <?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?><?=$showVariant&&!empty($it['variant_name'])?"<br><span style='font-size:10px;color:#777'>".e($it['variant_name'])."</span>":''?></td><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
    </tbody></table>
    <div style="text-align:right;font-size:11px;color:#666">Ship: ৳<?=number_format($order['shipping_cost'])?><?=$showDiscount&&$discount>0?' | Disc: -৳'.number_format($discount):''?></div>
    <div style="text-align:right;font-weight:bold;font-size:16px;margin:10px 0">Due: ৳<?=number_format($due)?></div>
    <?php if($showBarcode):?><div class="barcode-wrap-sm" style="margin:8px 0"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
    <div style="text-align:center;font-size:10px;color:#999;border-top:1px dashed #ccc;padding-top:8px"><?=$customFooter?e($customFooter):$pay.' | Thank you!'?></div>
</div>

<?php elseif($tpl==='inv_modern'):?>
<div class="invoice <?=$pb?>">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:30px"><div><?= logoOrName($logoUrl,$siteName,'45px') ?></div><div class="mod-badge">INVOICE</div></div>
    <div class="mod-meta"><div><h4>Bill To</h4><p><strong><?=e($order['customer_name'])?></strong><br>📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></p></div><div style="text-align:right"><h4>Invoice</h4><p><strong>#<?=e($order['order_number'])?></strong><br><?=date('d M Y',strtotime($order['created_at']))?><br><?=$pay?></p><?php if($showBarcode):?><div class="barcode-wrap" style="margin-top:6px"><svg id="<?=$barcodeId?>" class="barcode-svg" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div></div>
    <table class="mod-tbl"><thead><tr><th>#</th><th>Product</th><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Amount</th></tr></thead><tbody>
    <?php foreach($items as $i=>$it):?><tr><td><?=$i+1?></td><td><?=e($it['product_name'])?><?=$showVariant&&!empty($it['variant_name'])?"<br><span style='font-size:11px;color:#aaa'>".e($it['variant_name'])."</span>":''?></td><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['price'])?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
    </tbody></table>
    <table class="mod-tots"><tr><td>Subtotal</td><td class="text-right">৳<?=number_format($order['subtotal'])?></td></tr><tr><td>Delivery</td><td class="text-right">৳<?=number_format($order['shipping_cost'])?></td></tr><?php if($showDiscount&&$discount>0):?><tr><td>Discount</td><td class="text-right">-৳<?=number_format($discount)?></td></tr><?php endif;?><tr class="grand"><td>Due Amount</td><td class="text-right">৳<?=number_format($due)?></td></tr></table>
    <div style="text-align:center;margin-top:30px;font-size:11px;color:#999"><?=$customFooter?e($customFooter):'Thank you for your order!'?></div>
</div>

<?php elseif($tpl==='inv_branded'):?>
<div class="invoice <?=$pb?>">
    <div class="brd-hdr"><div><?= logoOrName($logoUrl,$siteName,'45px','filter:brightness(10)') ?></div><div class="right"><p style="font-size:20px;font-weight:700;margin-bottom:4px">#<?=e($order['order_number'])?></p><p><?=date('d M Y',strtotime($order['created_at']))?></p><p><?=$pay?></p><?php if($showBarcode):?><div style="margin-top:6px;filter:invert(1)"><svg id="<?=$barcodeId?>" class="barcode-svg" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div></div>
    <div class="brd-addr"><div><h4>Customer</h4><p><strong><?=e($order['customer_name'])?></strong><br>📞 <?=e($order['customer_phone'])?></p></div><div><h4>Ship To</h4><p><?=e($addr)?></p></div></div>
    <table class="brd-tbl"><thead><tr><th>#</th><th>Product</th><th class="text-center">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead><tbody>
    <?php foreach($items as $i=>$it):?><tr><td><?=$i+1?></td><td><?=e($it['product_name'])?><?=$showVariant&&!empty($it['variant_name'])?"<br><span style='font-size:11px;color:#666'>".e($it['variant_name'])."</span>":''?></td><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['price'])?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
    </tbody></table>
    <div class="brd-tots"><table><tr><td>Subtotal</td><td class="text-right">৳<?=number_format($order['subtotal'])?></td></tr><tr><td>Delivery</td><td class="text-right">৳<?=number_format($order['shipping_cost'])?></td></tr><?php if($showDiscount&&$discount>0):?><tr><td>Discount</td><td class="text-right">-৳<?=number_format($discount)?></td></tr><?php endif;?><tr class="grand"><td>Due Amount</td><td class="text-right">৳<?=number_format($due)?></td></tr></table></div>
</div>

<?php elseif($tpl==='inv_detailed'):?>
<div class="invoice <?=$pb?>">
    <div class="det-hdr"><div><?= logoOrName($logoUrl,$siteName,'45px') ?></div><div style="text-align:right"><p><strong>#<?=e($order['order_number'])?></strong></p><p style="font-size:12px;color:#666"><?=date('d M Y',strtotime($order['created_at']))?> | <?=$pay?></p><?php if($showBarcode):?><div class="barcode-wrap" style="margin-top:4px"><svg id="<?=$barcodeId?>" class="barcode-svg" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div></div>
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
    <?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?><?=$showVariant&&!empty($it['variant_name'])?"<br><span style='font-size:11px;color:#aaa'>".e($it['variant_name'])."</span>":''?></td><td class="text-center"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['price'])?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
    </tbody></table>
    <div style="text-align:right;margin-bottom:30px"><div class="min-row"><span>Subtotal</span><span>৳<?=number_format($order['subtotal'])?></span></div><div class="min-row"><span>Shipping</span><span>৳<?=number_format($order['shipping_cost'])?></span></div><?php if($showDiscount&&$discount>0):?><div class="min-row"><span>Discount</span><span>-৳<?=number_format($discount)?></span></div><?php endif;?><div class="min-row min-grand"><span>Due</span><span>৳<?=number_format($due)?></span></div></div>
    <div style="text-align:center;font-size:11px;color:#aaa;margin-top:30px"><?php if($customFooter):?><?=e($customFooter)?><?php elseif($logoUrl):?><img src="<?=$logoUrl?>" style="max-height:24px;object-fit:contain"><?php else:?><?=e($siteName)?><?php endif;?></div>
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
<div style="display:flex;justify-content:space-between;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:6px;font-size:10px"><?= logoOrName($logoUrl,$siteName,'28px') ?><span>#<?=e($order['order_number'])?></span></div>
<div><p style="font-size:14px;font-weight:700"><?=e($order['customer_name'])?></p><p>📞 <?=e($order['customer_phone'])?></p><p>📍 <?=e($addr)?></p>
<div style="font-size:10px;color:#444;margin-top:5px;border-top:1px dashed #999;padding-top:4px"><?php foreach($items as $it):?><?=e($it['product_name'])?> × <?=$it['quantity']?><?=$showVariant&&!empty($it['variant_name'])?' ('.e($it['variant_name']).')':''?><br><?php endforeach;?></div></div>
<div style="font-size:18px;font-weight:900;text-align:right;margin-top:4px">৳<?=number_format($due)?></div>
<?php if($showNotes&&$notes):?><div style="font-size:9px;color:#444;border-top:1px dashed #ccc;padding-top:3px;margin-top:4px"><strong>Note:</strong> <?=e($notes)?></div><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:9px;color:#444;margin-top:2px"><strong>Shipping:</strong> <?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_detailed'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:6px"><?= logoOrName($logoUrl,$siteName,'28px') ?><div style="text-align:right;font-size:9px;color:#555">Date: <?=$dt?><br>IV: <?=e($order['order_number'])?></div></div>
<?=$showCourier&&$courier?"<span style='font-size:9px;background:#000;color:#fff;padding:1px 4px'>Courier: ".e($courier)."</span><br>":""?>
<div style="font-size:13px;font-weight:700"><?=e($order['customer_name'])?></div><div style="font-size:11px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<table class="stk-ptbl"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?></td><td><?=$it['quantity']?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?></tbody></table>
<div style="display:flex;justify-content:space-between;border-top:2px solid #000;padding-top:5px;margin-top:4px"><div style="font-size:10px">Sub: ৳<?=number_format($order['subtotal'])?> | Del: ৳<?=number_format($order['shipping_cost'])?></div><div style="font-size:16px;font-weight:900">৳<?=number_format($due)?></div></div>
<?php if($showNotes&&$notes):?><div style="font-size:9px;color:#444;border-top:1px dashed #ccc;padding-top:3px;margin-top:3px"><strong>Note:</strong> <?=e($notes)?></div><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:9px;color:#444;margin-top:2px"><strong>Shipping:</strong> <?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>d" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_courier'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:4px"><?= logoOrName($logoUrl,$siteName,'28px') ?><div style="text-align:right;font-size:9px"><?=$dt?><br><?=e($order['order_number'])?></div></div>
<?=$showCourier&&$courier?"<div style='background:#000;color:#fff;padding:3px 8px;font-size:10px;font-weight:700;display:inline-block;margin-bottom:6px'>Courier: ".e($courier)."</div>":""?>
<div style="margin:6px 0;line-height:1.4"><div style="font-size:14px;font-weight:800"><?=e($order['customer_name'])?></div>📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<?=$showParcel&&$parcel?"<div style='font-size:10px;color:#555'>Parcel ID: ".e($parcel)."</div>":""?>
<div style="font-size:10px;border-top:1px dashed #999;padding-top:4px;margin-top:4px"><?php foreach($items as $it):?><?=e($it['product_name'])?> × <?=$it['quantity']?><br><?php endforeach;?></div>
<div style="display:flex;justify-content:space-between;align-items:center;border-top:2px solid #000;padding-top:6px;margin-top:6px"><div style="font-size:10px"><?=$pay?></div><div style="font-size:20px;font-weight:900">৳<?=number_format($due)?></div></div>
<?php if($showNotes&&$notes):?><div style="font-size:9px;color:#555;border-top:1px dashed #ccc;padding-top:3px;margin-top:3px"><strong>Note:</strong> <?=e($notes)?></div><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:9px;color:#555;margin-top:2px"><strong>Shipping:</strong> <?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_pos'):?>
<div class="sticker">
<?= logoOrName($logoUrl,$siteName,'30px') ?><?php if($showPhone):?><div style="font-size:10px;color:#666"><?=e($sitePhone)?></div><?php endif;?>
<div style="border-top:1px dashed #999;margin:6px 0"></div>
<div style="display:flex;justify-content:space-between;font-size:10px;color:#555;margin-bottom:4px"><span>#<?=e($order['order_number'])?></span><span><?=$dt?></span></div>
<div style="text-align:left;margin:6px 0;font-size:12px;line-height:1.5"><strong style="font-size:14px"><?=e($order['customer_name'])?></strong><br>📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<div style="border-top:1px dashed #999;margin:6px 0"></div>
<table class="stk-pos-tbl"><thead><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right">Total</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?></td><td class="text-right"><?=$it['quantity']?></td><td class="text-right">৳<?=number_format($it['subtotal'])?></td></tr><?php endforeach;?></tbody></table>
<div style="border-top:1px dashed #999;margin:6px 0"></div>
<div style="text-align:right;margin:6px 0;font-size:11px;line-height:1.6">Sub Total: ৳<?=number_format($order['subtotal'])?><br>Delivery: ৳<?=number_format($order['shipping_cost'])?><br><span style="font-size:18px;font-weight:900">Due: ৳<?=number_format($due)?></span></div>
<?php if($showNotes&&$notes):?><div style="font-size:9px;color:#555;border-top:1px dashed #999;padding-top:3px;margin-top:4px"><strong>Note:</strong> <?=e($notes)?></div><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:9px;color:#555;margin-top:2px"><strong>Shipping:</strong> <?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_cod'):?>
<div class="sticker">
<div style="background:#000;color:#fff;padding:6px 0;text-align:center;font-size:16px;font-weight:900;letter-spacing:2px;margin:-12px -12px 10px;border-bottom:3px solid #000"><?=$pay?> - ৳<?=number_format($due)?></div>
<div style="line-height:1.5;font-size:12px"><p style="font-size:15px;font-weight:700"><?=e($order['customer_name'])?></p><p>📞 <?=e($order['customer_phone'])?></p><p>📍 <?=e($addr)?></p>
<div style="font-size:10px;border-top:1px dashed #aaa;padding-top:5px;margin-top:6px"><?php foreach($items as $it):?><?=e($it['product_name'])?> × <?=$it['quantity']?><br><?php endforeach;?></div></div>
<div style="text-align:center;font-size:22px;font-weight:900;margin-top:8px;padding-top:6px;border-top:2px solid #000">৳<?=number_format($due)?></div>
<?php if($showNotes&&$notes):?><div style="font-size:9px;color:#555;border-top:1px dashed #ccc;padding-top:3px;margin-top:4px"><strong>Note:</strong> <?=e($notes)?></div><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:9px;color:#555;margin-top:2px"><strong>Shipping:</strong> <?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_wide'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:8px"><?= logoOrName($logoUrl,$siteName,'30px') ?><div style="font-size:12px;font-weight:700;text-align:right"><?=e($order['order_number'])?><br><span style="font-size:9px;color:#555;font-weight:400"><?=$dt?></span></div></div>
<div style="display:flex;gap:12px;margin-bottom:8px"><div style="flex:1"><div style="font-size:14px;font-weight:700;margin-bottom:2px"><?=e($order['customer_name'])?></div><p style="font-size:11px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></p></div>
<div style="text-align:right;min-width:80px;display:flex;flex-direction:column;justify-content:center;align-items:flex-end"><div style="font-size:22px;font-weight:900">৳<?=number_format($due)?></div><div style="font-size:9px;background:#000;color:#fff;padding:1px 6px;margin-top:2px"><?=$pay?></div></div></div>
<div style="font-size:10px;border-top:1px dashed #999;padding-top:4px"><?php foreach($items as $it):?><?=e($it['product_name'])?> × <?=$it['quantity']?> — ৳<?=number_format($it['subtotal'])?><br><?php endforeach;?><div style="text-align:right;margin-top:2px">Sub: ৳<?=number_format($order['subtotal'])?> | Del: ৳<?=number_format($order['shipping_cost'])?></div></div>
<?php if($showNotes&&$notes):?><div style="font-size:9px;color:#555;border-top:1px solid #ddd;padding-top:4px;margin-top:4px"><strong>Note:</strong> <?=e($notes)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_sku'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:6px"><?= logoOrName($logoUrl,$siteName,'28px') ?><div style="font-size:10px">IV: <?=e($order['order_number'])?><br><?=$dt?></div></div>
<?=$showCourier&&$courier?"<span style='font-size:9px;background:#000;color:#fff;padding:0 4px'>".e($courier)."</span> ":""?><span style="font-size:13px;font-weight:700"><?=e($order['customer_name'])?></span><br><span style="font-size:11px">📞 <?=e($order['customer_phone'])?></span><br><span style="font-size:10px">📍 <?=e($addr)?></span>
<table class="sku-tbl"><thead><tr><th>SKU</th><th>Price</th><th>Total</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=e($it['sku']??$it['product_name'])?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?></tbody></table>
<div style="display:flex;justify-content:space-between;border-top:2px solid #000;padding-top:5px;margin-top:4px;font-size:10px"><div>Sub: ৳<?=number_format($order['subtotal'])?><br>Del: ৳<?=number_format($order['shipping_cost'])?></div><div style="font-size:16px;font-weight:900">৳<?=number_format($due)?></div></div>
<?php if($showNotes&&$notes):?><div style="font-size:9px;color:#444;border-top:1px dashed #ccc;padding-top:3px;margin-top:3px"><strong>Note:</strong> <?=e($notes)?></div><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:9px;color:#444;margin-top:2px"><strong>Shipping:</strong> <?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_note'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:6px"><?= logoOrName($logoUrl,$siteName,'26px') ?><div style="font-size:10px"><?=e($order['order_number'])?><br><?=$dt?></div></div>
<div style="font-size:14px;font-weight:700"><?=e($order['customer_name'])?></div>
<div style="font-size:11px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<div style="font-size:10px;border-top:1px dashed #999;padding-top:4px;margin:5px 0"><?php foreach($items as $it):?><?=e($it['product_name'])?> × <?=$it['quantity']?> — ৳<?=number_format($it['subtotal'])?><br><?php endforeach;?></div>
<div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:5px"><div>Sub: ৳<?=number_format($order['subtotal'])?> | Del: ৳<?=number_format($order['shipping_cost'])?></div><div style="font-size:16px;font-weight:900">৳<?=number_format($due)?></div></div>
<div style="border-top:1px solid #ccc;padding-top:5px">
<?php if($showNotes&&$notes):?><p style="font-size:8px;text-transform:uppercase;color:#666;font-weight:700;margin-bottom:1px">Order Note:</p><p style="font-size:9px;line-height:1.3"><?=e($notes)?></p><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><p style="font-size:8px;text-transform:uppercase;color:#666;font-weight:700;margin-top:3px;margin-bottom:1px">Shipping Note:</p><p style="font-size:9px;line-height:1.3"><?=e($shippingNote)?></p><?php endif;?>
</div>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?></div>

<?php elseif($tpl==='stk_compact'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;border-bottom:1px solid #000;padding-bottom:3px;margin-bottom:4px"><?= logoOrName($logoUrl,$siteName,'22px') ?><span style="font-size:9px"><?=e($order['order_number'])?></span></div>
<div style="font-size:12px;font-weight:700"><?=e($order['customer_name'])?></div>
<div style="font-size:10px">📞 <?=e($order['customer_phone'])?></div>
<div style="font-size:9px;margin-top:2px">📍 <?=e($addr)?></div>
<?=$showCourier&&$courier?"<div style='font-size:8px;background:#000;color:#fff;display:inline-block;padding:1px 4px;margin-top:2px'>".e($courier)."</div>":"" ?>
<?=$showParcel&&$parcel?"<div style='font-size:8px;color:#555;margin-top:2px'>ID: ".e($parcel)."</div>":"" ?>
<div style="font-size:9px;border-top:1px dashed #999;margin-top:3px;padding-top:2px"><?php foreach($items as $it):?><?=e($it['product_name'])?> ×<?=$it['quantity']?><br><?php endforeach;?></div>
<div style="font-size:14px;font-weight:900;text-align:right;margin-top:3px">৳<?=number_format($due)?></div>
<?php if($showNotes&&$notes):?><div style="font-size:8px;color:#444;border-top:1px dashed #ccc;padding-top:2px;margin-top:3px"><strong>Note:</strong> <?=e($notes)?></div><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:8px;color:#444;margin-top:1px"><strong>Ship:</strong> <?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
</div>

<?php elseif($tpl==='stk_thermal'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:5px">
<?= logoOrName($logoUrl,$siteName,'28px') ?>
<div style="text-align:right;font-size:9px;color:#555"><?=$dt?><br>IV: <?=e($order['order_number'])?></div>
</div>
<?=$showCourier&&$courier?"<span style='font-size:9px;background:#000;color:#fff;padding:1px 5px;font-weight:700'>Courier: ".e($courier)."</span>&nbsp;":"" ?>
<div style="font-size:13px;font-weight:700;margin-top:4px"><?=e($order['customer_name'])?></div>
<div style="font-size:10px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<?=$showParcel&&$parcel?"<div style='font-size:9px;color:#555;margin-top:2px'>Parcel ID: ".e($parcel)."</div>":"" ?>
<table class="thm-ptbl"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>
<?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?></td><td><?=$it['quantity']?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
</tbody></table>
<div style="display:flex;justify-content:space-between;border-top:1px solid #999;padding-top:3px;font-size:9px"><span>Sub: ৳<?=number_format($order['subtotal'])?></span><span>Del: ৳<?=number_format($order['shipping_cost'])?></span><strong style="font-size:13px">৳<?=number_format($due)?></strong></div>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
</div>

<?php elseif($tpl==='stk_thermal_m'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:5px">
<?= logoOrName($logoUrl,$siteName,'28px') ?>
<div style="text-align:right;font-size:9px;color:#555"><?=$dt?><br>IV: <?=e($order['order_number'])?></div>
</div>
<?=$showCourier&&$courier?"<span style='font-size:9px;background:#000;color:#fff;padding:1px 5px;font-weight:700'>Courier: ".e($courier)."</span>&nbsp;":"" ?>
<div style="font-size:13px;font-weight:700;margin-top:4px"><?=e($order['customer_name'])?></div>
<div style="font-size:10px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<?=$showParcel&&$parcel?"<div style='font-size:9px;color:#555;margin-top:2px'>Parcel ID: ".e($parcel)."</div>":"" ?>
<table class="thm-ptbl"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>
<?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?></td><td><?=$it['quantity']?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
</tbody></table>
<div style="display:flex;justify-content:space-between;border-top:1px solid #999;padding-top:3px;font-size:9px"><span>Sub: ৳<?=number_format($order['subtotal'])?></span><span>Del: ৳<?=number_format($order['shipping_cost'])?></span><strong style="font-size:13px">৳<?=number_format($due)?></strong></div>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:8px;border-top:1px solid #ccc;padding-top:3px;margin-top:3px;color:#444"><?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
</div>

<?php elseif($tpl==='stk_thermal_sku'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:5px">
<?= logoOrName($logoUrl,$siteName,'28px') ?>
<div style="text-align:right;font-size:9px;color:#555"><?=$dt?><br>IV: <?=e($order['order_number'])?></div>
</div>
<?=$showCourier&&$courier?"<span style='font-size:9px;background:#000;color:#fff;padding:1px 5px;font-weight:700'>".e($courier)."</span>&nbsp;":"" ?>
<div style="font-size:13px;font-weight:700;margin-top:4px"><?=e($order['customer_name'])?></div>
<div style="font-size:10px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<?=$showParcel&&$parcel?"<div style='font-size:9px;color:#555;margin-top:2px'>Parcel ID: ".e($parcel)."</div>":"" ?>
<table class="tsku-tbl"><thead><tr><th>SKU</th><th>Price</th><th>Total</th></tr></thead><tbody>
<?php foreach($items as $it):?><tr><td><?=e($it['sku']??$it['product_name'])?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
</tbody></table>
<div style="display:flex;justify-content:space-between;border-top:2px solid #000;padding-top:3px;font-size:9px"><span>Sub: ৳<?=number_format($order['subtotal'])?><br>Del: ৳<?=number_format($order['shipping_cost'])?></span><strong style="font-size:13px">৳<?=number_format($due)?></strong></div>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:8px;border-top:1px solid #ccc;padding-top:3px;margin-top:3px"><strong>Shipping Note:</strong> <?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
</div>

<?php elseif($tpl==='stk_2in'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;border-bottom:1px solid #000;padding-bottom:4px;margin-bottom:5px"><?= logoOrName($logoUrl,$siteName,'22px') ?><span style="font-size:8px"><?=e($order['order_number'])?></span></div>
<?=$showCourier&&$courier?"<div style='font-size:8px;background:#000;color:#fff;padding:1px 5px;display:inline-block;margin-bottom:3px'>".e($courier)."</div><br>":"" ?>
<div style="font-size:12px;font-weight:700"><?=e($order['customer_name'])?></div>
<div style="font-size:10px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<?=$showParcel&&$parcel?"<div style='font-size:8px;color:#555;margin-top:2px'>Parcel: ".e($parcel)."</div>":"" ?>
<table class="s2in-ptbl"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>
<?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?></td><td><?=$it['quantity']?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
</tbody></table>
<div style="display:flex;justify-content:space-between;border-top:1px solid #999;padding-top:2px;font-size:8px"><span>Sub: ৳<?=number_format($order['subtotal'])?><br>Del: ৳<?=number_format($order['shipping_cost'])?></span><strong style="font-size:12px">৳<?=number_format($due)?></strong></div>
<?php if($showNotes&&$notes):?><div style="font-size:8px;color:#444;border-top:1px dashed #ccc;padding-top:2px;margin-top:3px"><strong>Note:</strong> <?=e($notes)?></div><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:8px;color:#444;margin-top:1px"><strong>Ship:</strong> <?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
</div>

<?php elseif($tpl==='stk_3in'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:6px"><?= logoOrName($logoUrl,$siteName,'28px') ?><div style="text-align:right;font-size:9px;color:#555"><?=$dt?><br>IV: <?=e($order['order_number'])?></div></div>
<?=$showCourier&&$courier?"<div style='font-size:10px;background:#000;color:#fff;padding:2px 7px;display:inline-block;margin-bottom:5px'>Courier: ".e($courier)."</div><br>":"" ?>
<div style="font-size:14px;font-weight:700"><?=e($order['customer_name'])?></div>
<div style="font-size:11px;line-height:1.5">📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<?=$showParcel&&$parcel?"<div style='font-size:9px;color:#555;margin-top:2px'>Parcel ID: ".e($parcel)."</div>":"" ?>
<table class="s3in-ptbl"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>
<?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?><?=$showVariant&&!empty($it['variant_name'])?"<br><small style='color:#666'>".e($it['variant_name'])."</small>":""?></td><td><?=$it['quantity']?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
</tbody></table>
<div style="display:flex;justify-content:space-between;border-top:2px solid #000;padding-top:5px;margin-top:4px"><div style="font-size:10px">Sub Total: ৳<?=number_format($order['subtotal'])?><br>Delivery: ৳<?=number_format($order['shipping_cost'])?></div><div style="font-size:16px;font-weight:900">Due: ৳<?=number_format($due)?></div></div>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:9px;border-top:1px solid #ccc;padding-top:4px;margin-top:4px"><strong>Shipping Note:</strong><br><?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
</div>

<?php elseif($tpl==='stk_cod_t'):?>
<div class="sticker">
<?= logoOrName($logoUrl,$siteName,'26px','margin:0 auto;display:block') ?>
<div style="font-size:13px;font-weight:700"><?=e($order['customer_name'])?></div>
<div style="font-size:10px;margin-top:2px">📞 <?=e($order['customer_phone'])?></div>
<div style="font-size:9px;margin-top:2px">📍 <?=e($addr)?></div>
<?=$showCourier&&$courier?"<div style='font-size:9px;margin-top:3px'>Courier: <strong>".e($courier)."</strong></div>":"" ?>
<?=$showParcel&&$parcel?"<div style='font-size:9px;margin-top:1px'>Parcel: ".e($parcel)."</div>":"" ?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
<div style="background:#111;color:#fff;border-radius:6px;text-align:center;padding:8px 4px;margin-top:6px;font-size:18px;font-weight:900;letter-spacing:1px">COD - ৳<?=number_format($due)?></div>
</div>

<?php elseif($tpl==='stk_4x3'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:6px">
<?= logoOrName($logoUrl,$siteName,'28px') ?>
<div style="text-align:right;font-size:9px;color:#555"><?=$dt?><br>IV: <?=e($order['order_number'])?></div>
</div>
<?=$showCourier&&$courier?"<div style='font-size:10px;background:#000;color:#fff;padding:2px 7px;display:inline-block;margin-bottom:4px'>".e($courier)."</div><br>":"" ?>
<div style="font-size:16px;font-weight:900"><?=e($order['customer_name'])?></div>
<div style="font-size:14px;font-weight:700;margin-top:1px">📞 <?=e($order['customer_phone'])?></div>
<div style="font-size:10px;margin-top:2px">📍 <?=e($addr)?></div>
<?=$showParcel&&$parcel?"<div style='font-size:9px;color:#555;margin-top:3px'>Parcel ID: ".e($parcel)."</div>":"" ?>
<table class="s4x3-tbl"><thead><tr><th>SKU</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>
<?php foreach($items as $it):?><tr><td style="font-weight:700"><?=e($it['sku']??$it['product_name'])?></td><td style="font-weight:700;font-size:13px"><?=$it['quantity']?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
</tbody></table>
<div style="display:flex;justify-content:space-between;border-top:2px solid #000;padding-top:6px;margin-top:4px"><div style="font-size:10px">Sub: ৳<?=number_format($order['subtotal'])?><br>Del: ৳<?=number_format($order['shipping_cost'])?></div><div style="font-size:18px;font-weight:900">৳<?=number_format($due)?></div></div>
<?php if($showNotes&&$notes):?><div style="font-size:9px;color:#444;border-top:1px dashed #ccc;padding-top:3px;margin-top:3px"><strong>Note:</strong> <?=e($notes)?></div><?php endif;?>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:9px;color:#444;margin-top:2px"><strong>Shipping:</strong> <?=e($shippingNote)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
</div>

<?php elseif($tpl==='stk_3in_note'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:6px"><?= logoOrName($logoUrl,$siteName,'28px') ?><div style="text-align:right;font-size:9px;color:#555"><?=$dt?><br>IV: <?=e($order['order_number'])?></div></div>
<?=$showCourier&&$courier?"<div style='font-size:10px;background:#000;color:#fff;padding:2px 7px;display:inline-block;margin-bottom:5px'>Courier: ".e($courier)."</div><br>":"" ?>
<div style="font-size:14px;font-weight:700"><?=e($order['customer_name'])?></div>
<div style="font-size:11px;line-height:1.5">📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<?=$showParcel&&$parcel?"<div style='font-size:9px;color:#555;margin-top:2px'>Parcel ID: ".e($parcel)."</div>":"" ?>
<table class="s3n-ptbl"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>
<?php foreach($items as $it):?><tr><td><?=e($it['product_name'])?></td><td><?=$it['quantity']?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
</tbody></table>
<div style="display:flex;justify-content:space-between;border-top:2px solid #000;padding-top:5px;margin-top:4px;font-size:10px"><div>Sub Total: ৳<?=number_format($order['subtotal'])?><br>Delivery: ৳<?=number_format($order['shipping_cost'])?></div><div style="font-size:16px;font-weight:900">৳<?=number_format($due)?></div></div>
<?php if($showShipNote&&$shippingNote):?><div style="font-size:9px;border-top:1px dashed #ccc;padding-top:4px;margin-top:4px"><strong style="font-size:8px;text-transform:uppercase;color:#555">Shipping Note:</strong><br><?=e($shippingNote)?></div><?php endif;?>
<?php if($showNotes&&$notes):?><div style="font-size:9px;border-top:1px dashed #ccc;padding-top:4px;margin-top:4px"><strong style="font-size:8px;text-transform:uppercase;color:#555">Order Note:</strong><br><?=e($notes)?></div><?php endif;?>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
</div>

<?php elseif($tpl==='stk_3sq'):?>
<div class="sticker">
<div style="display:flex;justify-content:space-between;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:5px"><?= logoOrName($logoUrl,$siteName,'28px') ?><div style="font-size:9px"><?=e($order['order_number'])?><br><?=$dt?></div></div>
<?=$showCourier&&$courier?"<div style='font-size:9px;background:#000;color:#fff;padding:1px 5px;display:inline-block;margin-bottom:3px'>".e($courier)."</div>":"" ?>
<div style="font-size:13px;font-weight:700"><?=e($order['customer_name'])?></div>
<div style="font-size:10px;line-height:1.4">📞 <?=e($order['customer_phone'])?><br>📍 <?=e($addr)?></div>
<table class="s3sq-tbl"><thead><tr><th>SKU</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>
<?php foreach($items as $it):?><tr><td><?=e($it['sku']??$it['product_name'])?></td><td><?=$it['quantity']?></td><td><?=number_format($it['price'])?></td><td><?=number_format($it['subtotal'])?></td></tr><?php endforeach;?>
</tbody></table>
<div style="display:flex;justify-content:space-between;border-top:1px solid #999;padding-top:3px;margin-top:3px;font-size:9px"><span>Sub: ৳<?=number_format($order['subtotal'])?> | Del: ৳<?=number_format($order['shipping_cost'])?></span><strong style="font-size:13px">৳<?=number_format($due)?></strong></div>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
</div>

<?php elseif($tpl==='stk_mini'):?>
<div class="sticker">
<div style="border-bottom:1px solid #000;padding-bottom:3px;margin-bottom:3px"><?= logoOrName($logoUrl,$siteName,'20px','margin:0 auto') ?></div>
<?php if($showBarcode):?><div class="barcode-wrap-sm"><svg id="<?=$barcodeId?>" class="barcode-svg-sm" data-value="<?=e($barcodeVal)?>"></svg></div><?php endif;?>
<?=$showParcel&&$parcel?"<div style='font-size:9px;font-weight:700;margin:2px 0'>Parcel: ".e($parcel)."</div>":"" ?>
<div style="background:#111;color:#fff;border-radius:4px;padding:4px;margin-top:4px;font-size:12px;font-weight:900">COD: ৳<?=number_format($due)?></div>
<div style="font-size:8px;margin-top:3px"><?=e($order['customer_name'])?> · <?=e($order['customer_phone'])?></div>
</div>

<?php endif; /* end template switch */ ?>
<?php if ($isExtract) { $__stickerOutput .= ob_get_clean(); } ?>
<?php if ($useLayout): ?></div><!-- /layout-cell --><?php endif; ?>
<?php if ($useLayout && $isGroupEnd): ?></div><!-- /layout-row --><?php endif; ?>
<?php endforeach; ?>
<?php if ($isExtract): ?>
<?php
// Capture the rendered sticker HTML
// Discard all the full page HTML that was captured
ob_end_clean();
// The sticker HTML is in $__stickerOutput (collected during foreach)
$stickerHtml = $__stickerOutput ?? '';
$sw = $stickerWidth;
$pc = htmlspecialchars($primaryColor, ENT_QUOTES);
$fs = $fontSize;
?>
<style>
.kh-sticker-preview{font-family:'Segoe UI',Arial,sans-serif;font-size:<?=$fs?>px;color:#333}
.kh-sticker-preview .sticker{display:block;width:<?=$sw?>px!important;max-width:100%!important;padding:8px;margin:0 auto 20px auto!important;background:#fff;border:1px solid #ddd;border-radius:6px;box-shadow:0 3px 12px rgba(0,0,0,.12);box-sizing:border-box;position:relative;overflow:hidden}
.kh-sticker-preview .sticker img{max-width:100%}
.kh-sticker-preview .stk-sep{display:none}
.kh-sticker-preview .barcode-wrap,.kh-sticker-preview .barcode-wrap-sm{text-align:center;margin-top:5px}
.kh-sticker-preview .barcode-wrap svg,.kh-sticker-preview .barcode-wrap-sm svg{max-width:100%;height:auto}
.kh-sticker-preview .text-right{text-align:right}
.kh-sticker-preview .text-center{text-align:center}
<?php if ($boldText): ?>.kh-sticker-preview .sticker *{font-weight:700!important}<?php endif; ?>
<?php if ($tpl==='stk_pos'): ?>
.kh-sticker-preview .sticker{border-bottom:2px dashed #ccc!important;border-left:none!important;border-right:none!important;border-top:none!important;border-radius:0!important;text-align:center}
.kh-sticker-preview .stk-pos-tbl{width:100%;font-size:10px;border-collapse:collapse}.kh-sticker-preview .stk-pos-tbl th{border-bottom:1px dashed #999;padding:3px 2px;font-size:9px;color:#777}.kh-sticker-preview .stk-pos-tbl td{padding:3px 2px;border-bottom:1px dotted #eee}
<?php elseif ($tpl==='stk_cod'): ?>.kh-sticker-preview .sticker{border:3px solid #000!important;border-radius:10px!important}
<?php elseif ($tpl==='stk_cod_t'): ?>.kh-sticker-preview .sticker{border:3px solid #111!important;border-radius:8px!important}
<?php elseif ($tpl==='stk_detailed'): ?>
.kh-sticker-preview .stk-ptbl{width:100%;border-collapse:collapse;font-size:10px;margin:5px 0}.kh-sticker-preview .stk-ptbl th{text-align:left;border-bottom:1px solid #999;padding:2px 4px;font-size:9px;color:#666}.kh-sticker-preview .stk-ptbl td{padding:2px 4px;border-bottom:1px solid #eee}
<?php elseif ($tpl==='stk_sku'||$tpl==='stk_thermal_sku'||$tpl==='stk_3sq'): ?>
.kh-sticker-preview .sku-tbl,.kh-sticker-preview .tsku-tbl,.kh-sticker-preview .s3sq-tbl{width:100%;border-collapse:collapse;font-size:9px;margin:3px 0}.kh-sticker-preview .sku-tbl th,.kh-sticker-preview .tsku-tbl th,.kh-sticker-preview .s3sq-tbl th{background:#f0f0f0;border:1px solid #ccc;padding:2px 4px;font-size:8px}.kh-sticker-preview .sku-tbl td,.kh-sticker-preview .tsku-tbl td,.kh-sticker-preview .s3sq-tbl td{border:1px solid #ddd;padding:2px 4px}
<?php elseif ($tpl==='stk_thermal'||$tpl==='stk_thermal_m'||$tpl==='stk_2in'||$tpl==='stk_3in'||$tpl==='stk_3in_note'||$tpl==='stk_compact'): ?>
.kh-sticker-preview .thm-ptbl,.kh-sticker-preview .s2in-ptbl,.kh-sticker-preview .s3in-ptbl,.kh-sticker-preview .s3n-ptbl{width:100%;border-collapse:collapse;font-size:9px;margin:3px 0}
.kh-sticker-preview .thm-ptbl th,.kh-sticker-preview .s2in-ptbl th,.kh-sticker-preview .s3in-ptbl th,.kh-sticker-preview .s3n-ptbl th{border-bottom:1px solid #999;padding:1px 3px;font-size:8px;color:#555}
.kh-sticker-preview .thm-ptbl td,.kh-sticker-preview .s2in-ptbl td,.kh-sticker-preview .s3in-ptbl td,.kh-sticker-preview .s3n-ptbl td{padding:1px 3px;border-bottom:1px solid #eee}
<?php elseif ($tpl==='stk_4x3'): ?>
.kh-sticker-preview .s4x3-tbl{width:100%;border-collapse:collapse;font-size:10px;margin:4px 0}
.kh-sticker-preview .s4x3-tbl th{background:#111;color:#fff;padding:3px 5px;font-size:8px;text-transform:uppercase}
.kh-sticker-preview .s4x3-tbl td{border-bottom:1px solid #ddd;padding:3px 5px}
<?php endif; ?>
</style>
<div class="kh-sticker-preview"><?= $stickerHtml ?></div>
<?php if ($showBarcode): ?>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>if(typeof JsBarcode!=='undefined'){document.querySelectorAll('.kh-sticker-preview svg[data-value]').forEach(function(svg){var v=svg.getAttribute('data-value');if(v)try{JsBarcode(svg,v,{format:'CODE128',width:1.2,height:32,displayValue:true,fontSize:9});}catch(e){}});}</script>
<?php endif; ?>
<?php exit; ?>
<?php endif; ?>

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
