<?php
/**
 * Customer Export API — CSV & XLSX
 * Supports: tab filtering (all/guests/registered), search, status filter, selected IDs
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die('Unauthorized');
}

$db = Database::getInstance();

$format = $_GET['format'] ?? 'csv';
$tab    = $_GET['tab'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? '';
$ids    = !empty($_GET['ids']) ? array_map('intval', array_filter(explode(',', $_GET['ids']))) : [];

// ── Build query ──
$where = '1=1';
$params = [];

// Tab filter
if ($tab === 'guests') {
    $where .= " AND (c.password IS NULL OR c.password = '')";
} elseif ($tab === 'registered') {
    $where .= " AND (c.password IS NOT NULL AND c.password != '')";
}

// Specific IDs (selected export)
if (!empty($ids)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $where .= " AND c.id IN ({$ph})";
    $params = array_merge($params, $ids);
}

// Search
if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.address LIKE ?)";
    $s = "%{$search}%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

// Status filter
if ($filter === 'blocked')    $where .= " AND c.is_blocked = 1";
if ($filter === 'active')     $where .= " AND c.is_blocked = 0";
if ($filter === 'has_orders') $where .= " AND c.total_orders > 0";
if ($filter === 'no_orders')  $where .= " AND (c.total_orders = 0 OR c.total_orders IS NULL)";
if ($filter === 'high_risk')  $where .= " AND c.risk_score >= 70";

$customers = $db->fetchAll("
    SELECT c.*,
        CASE WHEN c.password IS NOT NULL AND c.password != '' THEN 'Registered' ELSE 'Guest' END as user_type,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) as order_count,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.order_status = 'delivered') as delivered_count,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.order_status = 'cancelled') as cancelled_count,
        (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.customer_id = c.id AND o.order_status = 'delivered') as total_revenue,
        (SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c.id) as last_order_at
    FROM customers c
    WHERE {$where}
    ORDER BY c.created_at DESC
", $params);

// ── Headers ──
$headers = ['ID', 'Name', 'Phone', 'Alt Phone', 'Email', 'Type', 'Address', 'City', 'District', 'Postal Code',
            'Total Orders', 'Delivered', 'Cancelled', 'Total Spent', 'Last Order', 'Risk Score', 'Status', 'Joined'];

// ── Build rows ──
$rows = [];
foreach ($customers as $c) {
    $rows[] = [
        $c['id'],
        $c['name'],
        $c['phone'],
        $c['alt_phone'] ?? '',
        $c['email'] ?? '',
        $c['user_type'],
        $c['address'] ?? '',
        $c['city'] ?? '',
        $c['district'] ?? '',
        $c['postal_code'] ?? '',
        $c['order_count'],
        $c['delivered_count'],
        $c['cancelled_count'],
        $c['total_revenue'],
        $c['last_order_at'] ?? '',
        $c['risk_score'] ?? 0,
        ($c['is_blocked'] ?? 0) ? 'Blocked' : 'Active',
        $c['created_at'],
    ];
}

// ── Label for filename ──
$tabLabel = match($tab) { 'guests' => 'guests', 'registered' => 'registered', default => 'all-users' };
$dateSuffix = date('Y-m-d');

if ($format === 'xlsx') {
    // ══════════════════════════════════════
    //  XLSX Export (XML-based, no library)
    // ══════════════════════════════════════
    $filename = "customers-{$tabLabel}-{$dateSuffix}.xlsx";
    $tmpDir = sys_get_temp_dir() . '/xlsx_' . uniqid();
    mkdir($tmpDir, 0755, true);
    mkdir($tmpDir . '/_rels', 0755, true);
    mkdir($tmpDir . '/xl', 0755, true);
    mkdir($tmpDir . '/xl/_rels', 0755, true);
    mkdir($tmpDir . '/xl/worksheets', 0755, true);

    // [Content_Types].xml
    file_put_contents($tmpDir . '/[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>');

    // _rels/.rels
    file_put_contents($tmpDir . '/_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

    // xl/_rels/workbook.xml.rels
    file_put_contents($tmpDir . '/xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>');

    // xl/workbook.xml
    file_put_contents($tmpDir . '/xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets><sheet name="Customers" sheetId="1" r:id="rId1"/></sheets>
</workbook>');

    // xl/styles.xml — header bold + fill, number format for currency
    file_put_contents($tmpDir . '/xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>
    <fonts count="2">
        <font><sz val="11"/><name val="Calibri"/></font>
        <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    </fonts>
    <fills count="3">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/></patternFill></fill>
    </fills>
    <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="3">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
        <xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyFont="1" applyFill="1"/>
        <xf numFmtId="164" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/>
    </cellXfs>
</styleSheet>');

    // Build shared strings
    $strings = [];
    $stringIndex = [];
    $getStringIdx = function($str) use (&$strings, &$stringIndex) {
        $str = (string)$str;
        if (!isset($stringIndex[$str])) {
            $stringIndex[$str] = count($strings);
            $strings[] = $str;
        }
        return $stringIndex[$str];
    };

    // Index all header strings
    foreach ($headers as $h) $getStringIdx($h);
    // Index all row strings
    foreach ($rows as $row) {
        foreach ($row as $cell) {
            if (!is_numeric($cell) || $cell === '') {
                $getStringIdx((string)$cell);
            }
        }
    }

    // xl/sharedStrings.xml
    $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $s) {
        $ssXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1, 'UTF-8') . '</t></si>';
    }
    $ssXml .= '</sst>';
    file_put_contents($tmpDir . '/xl/sharedStrings.xml', $ssXml);

    // xl/worksheets/sheet1.xml
    $colLetters = [];
    for ($i = 0; $i < count($headers); $i++) {
        if ($i < 26) $colLetters[] = chr(65 + $i);
        else $colLetters[] = chr(64 + intdiv($i, 26)) . chr(65 + ($i % 26));
    }

    // Column widths
    $colWidths = [6, 20, 16, 16, 25, 12, 35, 14, 14, 10, 10, 10, 10, 14, 18, 8, 10, 18];

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $sheetXml .= '<cols>';
    for ($i = 0; $i < count($headers); $i++) {
        $w = $colWidths[$i] ?? 14;
        $sheetXml .= '<col min="' . ($i+1) . '" max="' . ($i+1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $sheetXml .= '</cols>';
    $sheetXml .= '<sheetData>';

    // Header row (style 1 = bold white on blue)
    $sheetXml .= '<row r="1">';
    foreach ($headers as $hi => $h) {
        $sheetXml .= '<c r="' . $colLetters[$hi] . '1" t="s" s="1"><v>' . $getStringIdx($h) . '</v></c>';
    }
    $sheetXml .= '</row>';

    // Numeric column indices: ID(0), Total Orders(10), Delivered(11), Cancelled(12), Total Spent(13), Risk Score(15)
    $numericCols = [0, 10, 11, 12, 13, 15];
    $currencyCols = [13]; // Total Spent gets currency format

    // Data rows
    foreach ($rows as $ri => $row) {
        $rowNum = $ri + 2;
        $sheetXml .= '<row r="' . $rowNum . '">';
        foreach ($row as $ci => $cell) {
            $ref = $colLetters[$ci] . $rowNum;
            if (in_array($ci, $numericCols) && is_numeric($cell)) {
                $style = in_array($ci, $currencyCols) ? ' s="2"' : '';
                $sheetXml .= '<c r="' . $ref . '"' . $style . '><v>' . $cell . '</v></c>';
            } else {
                $sheetXml .= '<c r="' . $ref . '" t="s"><v>' . $getStringIdx((string)$cell) . '</v></c>';
            }
        }
        $sheetXml .= '</row>';
    }

    $sheetXml .= '</sheetData>';
    // Auto-filter on header row
    $lastCol = $colLetters[count($headers) - 1];
    $sheetXml .= '<autoFilter ref="A1:' . $lastCol . (count($rows) + 1) . '"/>';
    // Freeze header row
    $sheetXml .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    $sheetXml .= '</worksheet>';
    file_put_contents($tmpDir . '/xl/worksheets/sheet1.xml', $sheetXml);

    // Create ZIP (XLSX is a ZIP)
    $zipPath = sys_get_temp_dir() . '/' . $filename;
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $addDir = function($dir, $base) use (&$addDir, $zip) {
        $files = scandir($dir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $dir . '/' . $f;
            $rel = $base ? $base . '/' . $f : $f;
            if (is_dir($full)) {
                $addDir($full, $rel);
            } else {
                $zip->addFile($full, $rel);
            }
        }
    };
    $addDir($tmpDir, '');
    $zip->close();

    // Clean temp
    $cleanDir = function($dir) use (&$cleanDir) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $cleanDir($p) : unlink($p);
        }
        rmdir($dir);
    };
    $cleanDir($tmpDir);

    // Send file
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($zipPath);
    unlink($zipPath);
    exit;

} else {
    // ══════════════════════════════════════
    //  CSV Export
    // ══════════════════════════════════════
    $filename = "customers-{$tabLabel}-{$dateSuffix}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
