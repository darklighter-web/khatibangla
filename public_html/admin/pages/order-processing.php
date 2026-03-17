<?php
// Order Processing — same UI as order-management, pre-filtered to processing status
// Just sets the default status to 'processing' and redirects there

$status = $_GET['status'] ?? 'processing';
$params = $_GET;
if (!isset($params['status'])) $params['status'] = 'processing';

// Build the redirect URL to order-management with all params
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

// Just include order-management.php with the processing filter pre-set
$_GET['status'] = $status;
$_GET['_proc_view'] = '1'; // marker for nav highlighting

include __DIR__ . '/order-management.php';
