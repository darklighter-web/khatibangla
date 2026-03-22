<?php
// Deploy verification — no path disclosure
require_once __DIR__ . '/../includes/auth.php';
if (!isAdminLoggedIn()) { http_response_code(404); exit; }
echo "✅ Deploy OK - " . date('Y-m-d H:i:s');
?>
