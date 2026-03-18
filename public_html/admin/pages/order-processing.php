<?php
// Order Processing page — same as order-management, pre-filtered to processing
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Processing';
$_isProcessingView = true; // hides bulk status actions irrelevant to processing
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_GET['status'])) {
    $_GET['status'] = 'processing';
}

include __DIR__ . '/order-management.php';
