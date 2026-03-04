<?php
// Redirect to unified Order Management - preserves query params
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: order-management.php' . ($qs ? '?' . $qs : ''));
exit;
