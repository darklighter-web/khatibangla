<?php
// Order Processing page — same as order-management, pre-filtered to processing
// Independent page with its own nav highlighting
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Processing';
require_once __DIR__ . '/../includes/auth.php';

// Pre-set the status filter
if (!isset($_GET['status'])) {
    $_GET['status'] = 'processing';
}

// Include the full order management page
// All AJAX/POST handlers work via the same file
include __DIR__ . '/order-management.php';
