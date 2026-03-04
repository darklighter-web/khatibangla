<?php
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Security Center';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();

// Load all sec_ settings
$sec = [];
try {
    $rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'sec_%'");
    foreach ($rows as $r) $sec[str_replace('sec_', '', $r['setting_key'])] = $r['setting_value'];
} catch (\Throwable $e) {}

$s = function($key, $def = '') use ($sec) { return $sec[$key] ?? $def; };

// Unread breach alert count
$alertCount = 0;
try {
    $alertCount = intval($db->fetch("SELECT COUNT(*) as c FROM notifications WHERE type = 'security_breach' AND is_read = 0")['c'] ?? 0);
} catch (\Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.score-ring{position:relative;width:180px;height:180px}.score-ring svg{transform:rotate(-90deg)}.score-ring .bg{stroke:#e5e7eb;fill:none;stroke-width:12}.score-ring .fg{fill:none;stroke-width:12;stroke-linecap:round;transition:stroke-dashoffset 1s ease}.score-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.tab-btn{padding:.5rem 1rem;font-size:.8125rem;font-weight:600;border-radius:.5rem;transition:all .15s;cursor:pointer;border:1px solid transparent}.tab-btn.active{background:#1e40af;color:#fff;border-color:#1e40af}.tab-btn:not(.active){background:#f3f4f6;color:#6b7280}.tab-btn:not(.active):hover{background:#e5e7eb}
.severity-critical{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.severity-high{background:#fef3c7;color:#92400e;border:1px solid #fde68a}.severity-medium{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}.severity-low{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.pulse-red{animation:pulseR 2s infinite}@keyframes pulseR{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)}50%{box-shadow:0 0 0 8px rgba(239,68,68,0)}}
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;background:#f9fafb;border-radius:.5rem;margin-bottom:.5rem}
.toggle-label .title{font-size:.875rem;font-weight:600;color:#1f2937}.toggle-label .desc{font-size:.75rem;color:#6b7280;margin-top:1px}
</style>

<div class="max-w-7xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center text-white"><i class="fas fa-shield-alt text-lg"></i></div>
            <div>
                <h2 class="text-xl font-bold text-gray-800">Security Center</h2>
                <p class="text-sm text-gray-500">Enterprise-grade protection for your store</p>
            </div>
        </div>
        <div class="flex gap-2">
            <?php if ($alertCount > 0): ?>
            <button onclick="switchTab('alerts')" class="relative px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition pulse-red">
                <i class="fas fa-bell mr-1"></i> <?= $alertCount ?> Alert<?= $alertCount > 1 ? 's' : '' ?>
            </button>
            <?php endif; ?>
            <button onclick="runScan()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                <i class="fas fa-search-plus mr-1"></i> Run Security Scan
            </button>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="flex flex-wrap gap-2">
        <button class="tab-btn active" data-tab="dashboard" onclick="switchTab('dashboard')"><i class="fas fa-tachometer-alt mr-1"></i> Dashboard</button>
        <button class="tab-btn" data-tab="firewall" onclick="switchTab('firewall')"><i class="fas fa-shield-alt mr-1"></i> Firewall</button>
        <button class="tab-btn" data-tab="logs" onclick="switchTab('logs')"><i class="fas fa-clipboard-list mr-1"></i> Threat Logs</button>
        <button class="tab-btn" data-tab="ip_rules" onclick="switchTab('ip_rules')"><i class="fas fa-ban mr-1"></i> IP Rules</button>
        <button class="tab-btn" data-tab="scanner" onclick="switchTab('scanner')"><i class="fas fa-bug mr-1"></i> Scanner</button>
        <button class="tab-btn" data-tab="alerts" onclick="switchTab('alerts')">
            <i class="fas fa-bell mr-1"></i> Alerts
            <?php if ($alertCount > 0): ?><span class="ml-1 bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full"><?= $alertCount ?></span><?php endif; ?>
        </button>
        <button class="tab-btn" data-tab="htaccess" onclick="switchTab('htaccess')"><i class="fas fa-file-code mr-1"></i> .htaccess</button>
    </div>

    <!-- ═══════════ TAB: DASHBOARD ═══════════ -->
    <div id="tab-dashboard" class="tab-content">
        <div class="grid lg:grid-cols-3 gap-6">
            <!-- Security Score -->
            <div class="bg-white rounded-xl border shadow-sm p-6 flex flex-col items-center">
                <h3 class="font-semibold text-gray-800 mb-4">Security Score</h3>
                <div class="score-ring">
                    <svg viewBox="0 0 120 120" width="180" height="180">
                        <circle class="bg" cx="60" cy="60" r="52"/>
                        <circle class="fg" id="score-arc" cx="60" cy="60" r="52" stroke="#22c55e"
                                stroke-dasharray="326.73" stroke-dashoffset="326.73"/>
                    </svg>
                    <div class="score-center">
                        <span id="score-value" class="text-4xl font-black text-gray-800">—</span>
                        <span id="score-grade" class="text-lg font-bold text-gray-400">—</span>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-3">Out of 100 points</p>
            </div>

            <!-- Threat Stats -->
            <div class="bg-white rounded-xl border shadow-sm p-6 lg:col-span-2">
                <h3 class="font-semibold text-gray-800 mb-4">Threat Overview</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                    <div class="bg-red-50 rounded-lg p-3 text-center"><p class="text-2xl font-black text-red-600" id="stat-threats-24h">0</p><p class="text-xs text-red-400">Threats (24h)</p></div>
                    <div class="bg-orange-50 rounded-lg p-3 text-center"><p class="text-2xl font-black text-orange-600" id="stat-blocked-24h">0</p><p class="text-xs text-orange-400">Blocked (24h)</p></div>
                    <div class="bg-purple-50 rounded-lg p-3 text-center"><p class="text-2xl font-black text-purple-600" id="stat-critical-24h">0</p><p class="text-xs text-purple-400">Critical (24h)</p></div>
                    <div class="bg-gray-100 rounded-lg p-3 text-center"><p class="text-2xl font-black text-gray-700" id="stat-blocked-ips">0</p><p class="text-xs text-gray-400">Blocked IPs</p></div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-blue-50 rounded-lg p-3 text-center"><p class="text-lg font-bold text-blue-600" id="stat-threats-7d">0</p><p class="text-xs text-blue-400">Threats (7d)</p></div>
                    <div class="bg-indigo-50 rounded-lg p-3 text-center"><p class="text-lg font-bold text-indigo-600" id="stat-threats-30d">0</p><p class="text-xs text-indigo-400">Threats (30d)</p></div>
                    <div class="bg-yellow-50 rounded-lg p-3 text-center"><p class="text-lg font-bold text-yellow-600" id="stat-failed-logins">0</p><p class="text-xs text-yellow-400">Failed Logins (24h)</p></div>
                </div>
            </div>
        </div>

        <!-- Security Checks -->
        <div class="bg-white rounded-xl border shadow-sm p-5 mt-6">
            <h3 class="font-semibold text-gray-800 mb-4"><i class="fas fa-tasks text-blue-500 mr-2"></i>Security Checklist</h3>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-2" id="security-checks"></div>
        </div>

        <!-- Top Attackers + Threat Types -->
        <div class="grid lg:grid-cols-2 gap-6 mt-6">
            <div class="bg-white rounded-xl border shadow-sm p-5">
                <h3 class="font-semibold text-gray-800 mb-3"><i class="fas fa-crosshairs text-red-500 mr-2"></i>Top Attacking IPs (7d)</h3>
                <div id="top-attackers" class="space-y-2 max-h-64 overflow-y-auto"></div>
            </div>
            <div class="bg-white rounded-xl border shadow-sm p-5">
                <h3 class="font-semibold text-gray-800 mb-3"><i class="fas fa-chart-pie text-blue-500 mr-2"></i>Threat Types (7d)</h3>
                <div id="threat-types" class="space-y-2 max-h-64 overflow-y-auto"></div>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB: FIREWALL SETTINGS ═══════════ -->
    <div id="tab-firewall" class="tab-content hidden">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="px-5 py-4 border-b flex items-center justify-between">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-shield-alt text-blue-500 mr-2"></i>Firewall & Protection Settings</h3>
                <button onclick="saveSettings()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition"><i class="fas fa-save mr-1"></i> Save All</button>
            </div>
            <div class="p-5">
                <!-- Core Protection -->
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide"><i class="fas fa-fire text-red-500 mr-1"></i> Core Protection</h4>
                <div class="mb-5">
                    <div class="toggle-row"><div class="toggle-label"><div class="title">Web Application Firewall (WAF)</div><div class="desc">Master switch for all firewall protections</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="firewall_enabled" <?= $s('firewall_enabled','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="toggle-row"><div class="toggle-label"><div class="title">SQL Injection Protection</div><div class="desc">Block union select, encoded payloads, and SQL attack patterns</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="sqli_protection" <?= $s('sqli_protection','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="toggle-row"><div class="toggle-label"><div class="title">XSS Protection</div><div class="desc">Block script injection, event handler injection, and DOM manipulation</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="xss_protection" <?= $s('xss_protection','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="toggle-row"><div class="toggle-label"><div class="title">CSRF Protection</div><div class="desc">Cross-site request forgery token validation</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="csrf_protection" <?= $s('csrf_protection','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="toggle-row"><div class="toggle-label"><div class="title">Security Headers</div><div class="desc">X-Frame-Options, X-Content-Type, HSTS, CSP, Permissions-Policy</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="security_headers" <?= $s('security_headers','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="toggle-row"><div class="toggle-label"><div class="title">Force HTTPS / HSTS</div><div class="desc">Enforce HTTPS with Strict-Transport-Security header</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="force_https" <?= $s('force_https','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                </div>

                <!-- Access Control -->
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide"><i class="fas fa-user-lock text-purple-500 mr-1"></i> Access Control</h4>
                <div class="mb-5">
                    <div class="toggle-row"><div class="toggle-label"><div class="title">Brute Force Protection</div><div class="desc">Lock out IPs after repeated failed login attempts</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="brute_force_enabled" <?= $s('brute_force_enabled','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="grid md:grid-cols-2 gap-3 ml-4 mb-3">
                        <div><label class="text-xs font-medium text-gray-600">Max Failed Attempts</label><input type="number" class="sec-input w-full mt-1 px-3 py-2 border rounded-lg text-sm" data-key="brute_force_max_attempts" value="<?= e($s('brute_force_max_attempts','5')) ?>" min="3" max="20"></div>
                        <div><label class="text-xs font-medium text-gray-600">Lockout Duration (minutes)</label><input type="number" class="sec-input w-full mt-1 px-3 py-2 border rounded-lg text-sm" data-key="brute_force_lockout_minutes" value="<?= e($s('brute_force_lockout_minutes','30')) ?>" min="5" max="1440"></div>
                    </div>
                    <div class="toggle-row"><div class="toggle-label"><div class="title">Rate Limiting</div><div class="desc">Limit requests per IP to prevent abuse and DDoS</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="rate_limit_enabled" <?= $s('rate_limit_enabled','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="grid md:grid-cols-3 gap-3 ml-4 mb-3">
                        <div><label class="text-xs font-medium text-gray-600">Max Requests</label><input type="number" class="sec-input w-full mt-1 px-3 py-2 border rounded-lg text-sm" data-key="rate_limit_requests" value="<?= e($s('rate_limit_requests','60')) ?>" min="10" max="500"></div>
                        <div><label class="text-xs font-medium text-gray-600">Window (seconds)</label><input type="number" class="sec-input w-full mt-1 px-3 py-2 border rounded-lg text-sm" data-key="rate_limit_window" value="<?= e($s('rate_limit_window','60')) ?>" min="10" max="600"></div>
                        <div><label class="text-xs font-medium text-gray-600">Auto-Block Multiplier</label><input type="number" class="sec-input w-full mt-1 px-3 py-2 border rounded-lg text-sm" data-key="auto_block_threshold" value="<?= e($s('auto_block_threshold','10')) ?>" min="2" max="50"></div>
                    </div>
                    <div class="toggle-row"><div class="toggle-label"><div class="title">Session Protection</div><div class="desc">Prevent session hijacking, fixation, and enforce timeout</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="session_protection" <?= $s('session_protection','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="ml-4 mb-3"><label class="text-xs font-medium text-gray-600">Session Timeout (minutes)</label><input type="number" class="sec-input w-full mt-1 px-3 py-2 border rounded-lg text-sm" data-key="session_timeout_minutes" value="<?= e($s('session_timeout_minutes','120')) ?>" min="15" max="1440" style="max-width:200px"></div>
                </div>

                <!-- Threat Detection -->
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide"><i class="fas fa-robot text-green-500 mr-1"></i> Threat Detection</h4>
                <div class="mb-5">
                    <div class="toggle-row"><div class="toggle-label"><div class="title">Block Malicious Bots & Scanners</div><div class="desc">Auto-detect sqlmap, nikto, nmap, nuclei, hydra, and 15+ scanner tools</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="block_bad_bots" <?= $s('block_bad_bots','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="toggle-row"><div class="toggle-label"><div class="title">Honeypot Traps</div><div class="desc">Hidden form fields to automatically detect and block bot submissions</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="honeypot_enabled" <?= $s('honeypot_enabled','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="toggle-row"><div class="toggle-label"><div class="title">Breach Alert Notifications</div><div class="desc">Get admin bell notifications for critical security events</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="breach_alerts" <?= $s('breach_alerts','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                </div>

                <!-- Upload Security -->
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide"><i class="fas fa-upload text-orange-500 mr-1"></i> Upload Security</h4>

                <!-- Admin Access Gate -->
                <h4 class="text-sm font-bold text-gray-700 mb-3 mt-5 uppercase tracking-wide"><i class="fas fa-door-closed text-indigo-500 mr-1"></i> Admin Panel Hidden Path</h4>
                <div class="mb-5 p-4 bg-indigo-50 rounded-lg border border-indigo-200">
                    <p class="text-xs text-indigo-700 mb-3">Your admin login is hidden behind a secret key. Bots and attackers see a <strong>fake 404 page</strong> when trying <code>/admin/</code>.</p>
                    <div class="grid md:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium text-gray-600">Secret Access Key</label>
                            <input type="text" id="admin-secret-key" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm font-mono" value="<?= e(getSetting('admin_secret_key', 'menzio2026')) ?>">
                        </div>
                        <div class="flex items-end gap-2">
                            <button onclick="saveAdminKey()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition"><i class="fas fa-save mr-1"></i> Save Key</button>
                            <button onclick="genRandomKey()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-300 transition"><i class="fas fa-random mr-1"></i> Generate</button>
                        </div>
                    </div>
                    <div class="mt-3 p-2 bg-white rounded border">
                        <p class="text-xs text-gray-600"><strong>Your admin login URL:</strong></p>
                        <code class="text-xs text-indigo-700 break-all" id="admin-url-display"><?= SITE_URL ?>/admin/login.php?access=<?= e(getSetting('admin_secret_key', 'menzio2026')) ?></code>
                        <button onclick="navigator.clipboard.writeText(document.getElementById('admin-url-display').textContent);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',2000)" class="ml-2 text-xs text-blue-600 hover:text-blue-800">Copy</button>
                    </div>
                </div>

                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide"><i class="fas fa-upload text-orange-500 mr-1"></i> Upload Security</h4>
                <div class="mb-5">
                    <div class="toggle-row"><div class="toggle-label"><div class="title">File Upload Malware Scanning</div><div class="desc">Scan uploads for PHP shells, encoded payloads, and MIME type spoofing</div></div><label class="relative inline-flex items-center cursor-pointer"><input type="checkbox" class="sr-only peer sec-toggle" data-key="file_upload_scan" <?= $s('file_upload_scan','1')==='1'?'checked':'' ?>><div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div></label></div>
                    <div class="grid md:grid-cols-2 gap-3 ml-4">
                        <div><label class="text-xs font-medium text-gray-600">Allowed File Types</label><input type="text" class="sec-input w-full mt-1 px-3 py-2 border rounded-lg text-sm font-mono" data-key="allowed_upload_types" value="<?= e($s('allowed_upload_types','jpg,jpeg,png,gif,webp,pdf')) ?>"></div>
                        <div><label class="text-xs font-medium text-gray-600">Max Upload Size (MB)</label><input type="number" class="sec-input w-full mt-1 px-3 py-2 border rounded-lg text-sm" data-key="max_upload_size_mb" value="<?= e($s('max_upload_size_mb','10')) ?>" min="1" max="100"></div>
                    </div>
                </div>

                <!-- Admin Panel Protection -->
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide"><i class="fas fa-door-closed text-indigo-500 mr-1"></i> Admin Panel Protection</h4>
                <div class="mb-5 bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                    <p class="text-xs text-indigo-600 mb-3"><i class="fas fa-info-circle mr-1"></i> Your admin panel is hidden behind a secret URL. Anyone visiting <code>/admin/</code> directly will see a fake 404 page.</p>
                    <div class="grid md:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium text-gray-600">Secret Entry URL</label>
                            <div class="flex items-center gap-2 mt-1">
                                <code class="flex-1 bg-white px-3 py-2 border rounded-lg text-sm text-indigo-700 font-mono"><?= e(SITE_URL) ?>/menzio-panel.php?key=<span id="secret-key-display"><?= e(getSetting('admin_secret_key', 'menzio2026')) ?></span></code>
                                <button onclick="copyAdminUrl()" class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-xs hover:bg-indigo-700"><i class="fas fa-copy"></i></button>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-600">Secret Key (change to make your admin harder to find)</label>
                            <input type="text" id="admin-secret-key" value="<?= e(getSetting('admin_secret_key', 'menzio2026')) ?>" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm font-mono" placeholder="Your secret key">
                            <button onclick="saveAdminKey()" class="mt-2 px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs hover:bg-indigo-700"><i class="fas fa-save mr-1"></i> Update Secret Key</button>
                        </div>
                    </div>
                    <p class="text-xs text-red-500 mt-2"><i class="fas fa-exclamation-triangle mr-1"></i> After changing the key, you must use the new URL to access admin. Bookmark it!</p>
                </div>

                <!-- Content Security Policy -->
                <h4 class="text-sm font-bold text-gray-700 mb-3 uppercase tracking-wide"><i class="fas fa-code text-gray-500 mr-1"></i> Content Security Policy</h4>
                <div class="mb-5">
                    <textarea class="sec-input w-full px-3 py-2 border rounded-lg text-xs font-mono" data-key="content_security_policy" rows="3" placeholder="default-src 'self'; ..."><?= e($s('content_security_policy','')) ?></textarea>
                    <p class="text-xs text-gray-400 mt-1">Leave blank for no CSP header. Be careful — a misconfigured CSP can break page functionality.</p>
                </div>

                <div class="flex items-center gap-3">
                    <button onclick="saveSettings()" class="px-5 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition"><i class="fas fa-save mr-1"></i> Save All Settings</button>
                    <button onclick="enableAll()" class="px-5 py-2.5 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition"><i class="fas fa-check-double mr-1"></i> Enable All</button>
                    <span id="settings-msg" class="text-sm text-green-600 hidden"><i class="fas fa-check-circle mr-1"></i> Saved!</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB: THREAT LOGS ═══════════ -->
    <div id="tab-logs" class="tab-content hidden">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="px-5 py-4 border-b flex items-center justify-between flex-wrap gap-3">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-clipboard-list text-blue-500 mr-2"></i>Security Threat Logs</h3>
                <div class="flex gap-2">
                    <select id="log-severity" onchange="loadLogs()" class="text-sm border rounded-lg px-3 py-1.5"><option value="">All Severity</option><option value="critical">Critical</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select>
                    <select id="log-type" onchange="loadLogs()" class="text-sm border rounded-lg px-3 py-1.5"><option value="">All Types</option><option value="sql_injection">SQL Injection</option><option value="xss_attempt">XSS</option><option value="brute_force">Brute Force</option><option value="rate_limit">Rate Limit</option><option value="ip_blocked">IP Blocked</option><option value="login_failed">Login Failed</option><option value="malware_detected">Malware</option><option value="bad_bot">Bad Bot</option><option value="path_traversal">Path Traversal</option></select>
                    <input type="text" id="log-search" placeholder="Search IP or payload..." class="text-sm border rounded-lg px-3 py-1.5 w-40" onkeyup="if(event.key==='Enter')loadLogs()">
                    <button onclick="clearLogs('7')" class="px-3 py-1.5 bg-red-100 text-red-600 rounded-lg text-xs font-medium hover:bg-red-200">Clear 7d+</button>
                    <button onclick="clearLogs('all')" class="px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-medium hover:bg-red-700">Clear All</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase"><tr><th class="px-4 py-3 text-left">Time</th><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-left">Severity</th><th class="px-4 py-3 text-left">IP</th><th class="px-4 py-3 text-left">Details</th><th class="px-4 py-3 text-left">Blocked</th><th class="px-4 py-3 text-left">Action</th></tr></thead>
                    <tbody id="logs-body" class="divide-y"></tbody>
                </table>
            </div>
            <div class="px-5 py-3 border-t flex items-center justify-between">
                <span class="text-xs text-gray-500" id="logs-count">—</span>
                <div class="flex gap-2" id="logs-pagination"></div>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB: IP RULES ═══════════ -->
    <div id="tab-ip_rules" class="tab-content hidden">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="px-5 py-4 border-b">
                <h3 class="font-semibold text-gray-800 mb-3"><i class="fas fa-plus-circle text-green-500 mr-2"></i>Add IP Rule</h3>
                <div class="flex flex-wrap gap-3">
                    <input type="text" id="ip-input" placeholder="IP Address (e.g. 192.168.1.1)" class="px-3 py-2 border rounded-lg text-sm w-48">
                    <select id="ip-type" class="px-3 py-2 border rounded-lg text-sm"><option value="block">🚫 Block</option><option value="allow">✅ Allow (Whitelist)</option><option value="watch">👁️ Watch</option></select>
                    <input type="text" id="ip-reason" placeholder="Reason" class="px-3 py-2 border rounded-lg text-sm w-48">
                    <select id="ip-duration" class="px-3 py-2 border rounded-lg text-sm"><option value="">Permanent</option><option value="1">1 Hour</option><option value="24">24 Hours</option><option value="168">7 Days</option><option value="720">30 Days</option></select>
                    <button onclick="addIpRule()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Add Rule</button>
                    <button onclick="clearAutoBlocks()" class="px-4 py-2 bg-orange-500 text-white rounded-lg text-sm font-medium hover:bg-orange-600" title="Remove all auto-blocked IPs (from rate limits, scanners). Manual blocks are preserved."><i class="fas fa-unlock mr-1"></i>Clear Auto-Blocks</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase"><tr><th class="px-4 py-3 text-left">IP Address</th><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-left">Reason</th><th class="px-4 py-3 text-left">Hits</th><th class="px-4 py-3 text-left">Expires</th><th class="px-4 py-3 text-left">Created</th><th class="px-4 py-3 text-left">Action</th></tr></thead>
                    <tbody id="ip-rules-body" class="divide-y"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB: SCANNER ═══════════ -->
    <div id="tab-scanner" class="tab-content hidden">
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-bug text-red-500 mr-2"></i>Security Scanner</h3>
                <button onclick="runScan()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700"><i class="fas fa-play mr-1"></i> Run Full Scan</button>
            </div>
            <div id="scan-results" class="space-y-4">
                <p class="text-sm text-gray-500 text-center py-10">Click "Run Full Scan" to check your server for vulnerabilities</p>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB: ALERTS ═══════════ -->
    <div id="tab-alerts" class="tab-content hidden">
        <div class="bg-white rounded-xl border shadow-sm">
            <div class="px-5 py-4 border-b flex items-center justify-between">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-bell text-red-500 mr-2"></i>Breach Alert Notifications</h3>
                <button onclick="dismissAllAlerts()" class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-xs font-medium hover:bg-gray-200">Dismiss All</button>
            </div>
            <div id="alerts-list" class="p-5 space-y-3"></div>
        </div>
    </div>

    <!-- ═══════════ TAB: .HTACCESS ═══════════ -->
    <div id="tab-htaccess" class="tab-content hidden">
        <div class="bg-white rounded-xl border shadow-sm p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800"><i class="fas fa-file-code text-blue-500 mr-2"></i>Server Security Rules (.htaccess)</h3>
                <button onclick="copyHtaccess()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700"><i class="fas fa-copy mr-1"></i> Copy Rules</button>
            </div>
            <p class="text-sm text-gray-600 mb-3">Add these rules to your <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs font-mono">.htaccess</code> file for server-level protection:</p>
            <pre id="htaccess-code" class="bg-gray-900 text-green-400 rounded-lg p-4 text-xs font-mono overflow-x-auto max-h-96 overflow-y-auto">Loading...</pre>
        </div>
    </div>
</div>

<script>
const API = '<?= SITE_URL ?>/api/security.php';
let logsPage = 1;

// ── Tab Management ──
function switchTab(tab) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tab)?.classList.remove('hidden');
    document.querySelector(`[data-tab="${tab}"]`)?.classList.add('active');
    
    if (tab === 'dashboard') loadDashboard();
    if (tab === 'logs') loadLogs();
    if (tab === 'ip_rules') loadIpRules();
    if (tab === 'alerts') loadAlerts();
    if (tab === 'htaccess') loadHtaccess();
}

// ── Dashboard ──
function loadDashboard() {
    fetch(API + '?action=dashboard').then(r => r.json()).then(res => {
        const d = res.data;
        
        // Score animation
        const pct = d.score / d.max_score;
        const circ = 326.73;
        document.getElementById('score-arc').style.strokeDashoffset = circ - (circ * pct);
        document.getElementById('score-arc').style.stroke = d.score >= 80 ? '#22c55e' : d.score >= 50 ? '#f59e0b' : '#ef4444';
        document.getElementById('score-value').textContent = d.score;
        document.getElementById('score-grade').textContent = d.grade;
        document.getElementById('score-grade').className = 'text-lg font-bold ' + (d.score >= 80 ? 'text-green-500' : d.score >= 50 ? 'text-yellow-500' : 'text-red-500');
        
        // Stats
        document.getElementById('stat-threats-24h').textContent = d.threats_24h;
        document.getElementById('stat-blocked-24h').textContent = d.blocked_24h;
        document.getElementById('stat-critical-24h').textContent = d.critical_24h;
        document.getElementById('stat-blocked-ips').textContent = d.blocked_ips;
        document.getElementById('stat-threats-7d').textContent = d.threats_7d;
        document.getElementById('stat-threats-30d').textContent = d.threats_30d;
        document.getElementById('stat-failed-logins').textContent = d.failed_logins_24h;
        
        // Security checklist
        const checksEl = document.getElementById('security-checks');
        checksEl.innerHTML = Object.entries(d.checks).map(([k, v]) => `
            <div class="flex items-center gap-2 p-2.5 rounded-lg ${v.pass ? 'bg-green-50' : 'bg-red-50'}">
                <i class="fas fa-${v.pass ? 'check-circle text-green-500' : 'times-circle text-red-400'} text-sm"></i>
                <span class="text-sm ${v.pass ? 'text-green-700' : 'text-red-600'} font-medium flex-1">${v.label}</span>
                <span class="text-xs ${v.pass ? 'text-green-400' : 'text-red-300'}">${v.weight}pt</span>
            </div>
        `).join('');
        
        // Top attackers
        const attackersEl = document.getElementById('top-attackers');
        if (d.top_attackers.length) {
            attackersEl.innerHTML = d.top_attackers.map(a => `
                <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                    <div class="flex items-center gap-2">
                        <code class="text-xs font-mono bg-gray-200 px-2 py-0.5 rounded">${a.ip_address}</code>
                        <span class="text-xs severity-${a.max_severity} px-1.5 py-0.5 rounded">${a.max_severity}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500">${a.cnt} events</span>
                        <button onclick="blockIpFromLog('${a.ip_address}')" class="text-xs text-red-500 hover:text-red-700"><i class="fas fa-ban"></i></button>
                    </div>
                </div>
            `).join('');
        } else {
            attackersEl.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No threats detected</p>';
        }
        
        // Threat types
        const typesEl = document.getElementById('threat-types');
        if (d.threat_types.length) {
            const maxCnt = Math.max(...d.threat_types.map(t => t.cnt));
            typesEl.innerHTML = d.threat_types.map(t => `
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-600 w-28 truncate font-mono">${t.event_type}</span>
                    <div class="flex-1 bg-gray-100 rounded-full h-4 overflow-hidden">
                        <div class="bg-blue-500 h-4 rounded-full transition-all" style="width:${(t.cnt/maxCnt)*100}%"></div>
                    </div>
                    <span class="text-xs font-bold text-gray-700 w-10 text-right">${t.cnt}</span>
                </div>
            `).join('');
        } else {
            typesEl.innerHTML = '<p class="text-sm text-gray-400 text-center py-4">No threats logged yet</p>';
        }
    });
}

// ── Logs ──
function loadLogs(page) {
    if (page) logsPage = page;
    const severity = document.getElementById('log-severity').value;
    const type = document.getElementById('log-type').value;
    const search = document.getElementById('log-search').value;
    const params = new URLSearchParams({action:'logs', page:logsPage, severity, event_type:type, search});
    
    fetch(API + '?' + params).then(r => r.json()).then(res => {
        const d = res.data;
        document.getElementById('logs-count').textContent = `${d.total} events · Page ${d.page}/${d.pages}`;
        
        document.getElementById('logs-body').innerHTML = d.logs.length ? d.logs.map(l => `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 text-xs text-gray-500 whitespace-nowrap">${l.created_at}</td>
                <td class="px-4 py-2"><span class="text-xs font-mono bg-gray-100 px-1.5 py-0.5 rounded">${l.event_type}</span></td>
                <td class="px-4 py-2"><span class="text-xs severity-${l.severity} px-2 py-0.5 rounded font-medium">${l.severity}</span></td>
                <td class="px-4 py-2 font-mono text-xs">${l.ip_address || '—'}</td>
                <td class="px-4 py-2 text-xs text-gray-600 max-w-xs truncate">${(l.payload || '').substring(0,80)}</td>
                <td class="px-4 py-2">${l.blocked == 1 ? '<span class="text-xs bg-red-100 text-red-600 px-1.5 py-0.5 rounded">Blocked</span>' : '<span class="text-xs text-gray-400">—</span>'}</td>
                <td class="px-4 py-2">${l.ip_address ? `<button onclick="blockIpFromLog('${l.ip_address}')" class="text-xs text-red-500 hover:text-red-700"><i class="fas fa-ban"></i> Block</button>` : ''}</td>
            </tr>
        `).join('') : '<tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No logs found</td></tr>';
        
        // Pagination
        let pagHtml = '';
        if (d.page > 1) pagHtml += `<button onclick="loadLogs(${d.page-1})" class="px-3 py-1 text-xs bg-gray-100 rounded hover:bg-gray-200">← Prev</button>`;
        if (d.page < d.pages) pagHtml += `<button onclick="loadLogs(${d.page+1})" class="px-3 py-1 text-xs bg-gray-100 rounded hover:bg-gray-200">Next →</button>`;
        document.getElementById('logs-pagination').innerHTML = pagHtml;
    });
}

function clearLogs(period) {
    if (!confirm('Delete logs?')) return;
    const fd = new FormData(); fd.append('action','clear_logs'); fd.append('period',period);
    fetch(API, {method:'POST',body:fd}).then(r=>r.json()).then(()=>loadLogs(1));
}

// ── IP Rules ──
function loadIpRules() {
    fetch(API+'?action=ip_rules').then(r=>r.json()).then(res => {
        const rules = res.data || [];
        document.getElementById('ip-rules-body').innerHTML = rules.length ? rules.map(r => {
            const typeColor = r.rule_type === 'block' ? 'red' : r.rule_type === 'allow' ? 'green' : 'yellow';
            const typeIcon = r.rule_type === 'block' ? '🚫' : r.rule_type === 'allow' ? '✅' : '👁️';
            const autoTag = (r.auto_blocked == 1) ? ' <span class="text-[9px] bg-orange-100 text-orange-600 px-1 rounded">auto</span>' : '';
            return `<tr class="hover:bg-gray-50">
                <td class="px-4 py-2 font-mono text-xs font-bold">${r.ip_address}</td>
                <td class="px-4 py-2"><span class="text-xs bg-${typeColor}-100 text-${typeColor}-600 px-2 py-0.5 rounded font-medium">${typeIcon} ${r.rule_type}</span>${autoTag}</td>
                <td class="px-4 py-2 text-xs text-gray-600">${r.reason || '—'}</td>
                <td class="px-4 py-2 text-xs text-gray-600">${r.hit_count}</td>
                <td class="px-4 py-2 text-xs text-gray-500">${r.expires_at || 'Permanent'}</td>
                <td class="px-4 py-2 text-xs text-gray-400">${r.created_at}</td>
                <td class="px-4 py-2"><button onclick="removeIpRule(${r.id})" class="text-xs text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button></td>
            </tr>`;
        }).join('') : '<tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No IP rules configured</td></tr>';
    });
}

function addIpRule() {
    const fd = new FormData();
    fd.append('action','add_ip_rule');
    fd.append('ip', document.getElementById('ip-input').value);
    fd.append('rule_type', document.getElementById('ip-type').value);
    fd.append('reason', document.getElementById('ip-reason').value);
    fd.append('duration', document.getElementById('ip-duration').value);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d => {
        if (d.success) { document.getElementById('ip-input').value = ''; loadIpRules(); }
        else alert(d.message);
    });
}

function removeIpRule(id) {
    if (!confirm('Remove this IP rule?')) return;
    const fd = new FormData(); fd.append('action','remove_ip_rule'); fd.append('id',id);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(()=>loadIpRules());
}

function clearAutoBlocks() {
    if (!confirm('Clear all auto-blocked IPs?\n\nThis removes IPs blocked by rate limits and scanner detection.\nManual blocks are preserved.\n\nThis will fix "Access Denied" for iOS Safari and other devices.')) return;
    const fd = new FormData(); fd.append('action','clear_auto_blocks');
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d => { alert(d.message); loadIpRules(); loadDashboard(); });
}

function blockIpFromLog(ip) {
    if (!confirm('Block IP ' + ip + ' permanently?')) return;
    const fd = new FormData(); fd.append('action','block_ip_from_log'); fd.append('ip',ip);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d => { alert(d.message); loadDashboard(); });
}

// ── Scanner ──
function runScan() {
    const container = document.getElementById('scan-results');
    container.innerHTML = '<div class="text-center py-10"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-3"></i><p class="text-sm text-gray-500">Scanning server for vulnerabilities...</p></div>';
    switchTab('scanner');
    
    const fd = new FormData(); fd.append('action','run_scan');
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(res => {
        const d = res.data;
        let html = '';
        
        // Summary
        const issueColor = d.issues_found === 0 ? 'green' : d.issues_found <= 3 ? 'yellow' : 'red';
        html += `<div class="p-4 bg-${issueColor}-50 border border-${issueColor}-200 rounded-lg flex items-center gap-3">
            <i class="fas fa-${d.issues_found === 0 ? 'check-circle text-green-500' : 'exclamation-triangle text-'+issueColor+'-500'} text-xl"></i>
            <div><p class="font-semibold text-${issueColor}-800">Scan Complete — ${d.issues_found} Issue${d.issues_found !== 1 ? 's' : ''} Found</p>
            <p class="text-xs text-${issueColor}-600">Scanned at ${d.scan_time}</p></div>
        </div>`;
        
        // Suspicious PHP in uploads
        html += scanSection('PHP Files in Uploads', d.upload_php_files, 'critical',
            items => items.map(f => `<div class="flex items-center justify-between p-2 bg-red-50 rounded text-xs"><code>${f}</code><button onclick="deleteFile('${f}')" class="text-red-600 hover:text-red-800"><i class="fas fa-trash mr-1"></i>Delete</button></div>`).join(''));
        
        // Suspicious code
        html += scanSection('Suspicious Code Patterns', d.suspicious_code, 'critical',
            items => items.map(i => `<div class="p-2 bg-red-50 rounded text-xs"><code class="font-bold">${i.file}</code> — ${i.threat}<br><span class="text-red-400">Pattern: ${escHtml(i.pattern)}</span></div>`).join(''));
        
        // World-writable files
        html += scanSection('World-Writable Files', d.world_writable, 'high',
            items => items.map(f => `<div class="p-2 bg-yellow-50 rounded text-xs"><code>${f}</code> — <span class="text-yellow-700">chmod 644 recommended</span></div>`).join(''));
        
        // Exposed files
        html += scanSection('Exposed Sensitive Files', d.exposed_files, 'high',
            items => items.map(f => `<div class="p-2 bg-yellow-50 rounded text-xs"><code>${f}</code> — <span class="text-yellow-700">Delete or block access via .htaccess</span></div>`).join(''));
        
        // Directory listing
        html += `<div class="bg-white border rounded-lg p-4"><h4 class="font-semibold text-sm text-gray-700 mb-2"><i class="fas fa-folder-open text-blue-500 mr-1"></i> Directory Listing Protection</h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">${d.directory_listing.map(dl => `<div class="flex items-center gap-2 p-2 rounded text-xs ${dl.protected ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}"><i class="fas fa-${dl.protected ? 'check' : 'times'}-circle"></i><code>/${dl.dir}/</code></div>`).join('')}</div></div>`;
        
        // PHP settings
        html += `<div class="bg-white border rounded-lg p-4"><h4 class="font-semibold text-sm text-gray-700 mb-2"><i class="fas fa-cog text-gray-500 mr-1"></i> PHP Configuration</h4>
            <div class="grid md:grid-cols-2 gap-2">
                ${phpCheck('Display Errors', !d.php_settings.display_errors, d.php_settings.display_errors ? 'ON — disable in production' : 'OFF')}
                ${phpCheck('Expose PHP', !d.php_settings.expose_php, d.php_settings.expose_php ? 'ON — reveals PHP version' : 'OFF')}
                ${phpCheck('allow_url_include', !d.php_settings.allow_url_include, d.php_settings.allow_url_include ? 'ON — remote code risk!' : 'OFF')}
                ${phpCheck('open_basedir', d.php_settings.open_basedir !== 'Not set', d.php_settings.open_basedir)}
            </div></div>`;
        
        // Session security
        html += `<div class="bg-white border rounded-lg p-4"><h4 class="font-semibold text-sm text-gray-700 mb-2"><i class="fas fa-key text-purple-500 mr-1"></i> Session Security</h4>
            <div class="grid md:grid-cols-2 gap-2">
                ${phpCheck('cookie_httponly', d.session.cookie_httponly, d.session.cookie_httponly ? 'Enabled' : 'Disabled — cookies readable by JS')}
                ${phpCheck('cookie_secure', d.session.cookie_secure, d.session.cookie_secure ? 'Enabled' : 'Disabled — cookies sent over HTTP')}
                ${phpCheck('use_strict_mode', d.session.use_strict_mode, d.session.use_strict_mode ? 'Enabled' : 'Disabled')}
                ${phpCheck('cookie_samesite', d.session.cookie_samesite !== 'None', d.session.cookie_samesite || 'None')}
            </div></div>`;
        
        // Dangerous functions
        html += `<div class="bg-white border rounded-lg p-4"><h4 class="font-semibold text-sm text-gray-700 mb-2"><i class="fas fa-terminal text-red-500 mr-1"></i> Dangerous PHP Functions</h4>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">${d.dangerous_functions.map(f => `<div class="flex items-center gap-2 p-2 rounded text-xs ${f.disabled ? 'bg-green-50 text-green-700' : 'bg-yellow-50 text-yellow-700'}"><i class="fas fa-${f.disabled ? 'lock' : 'unlock'}-alt"></i><code>${f.function}()</code><span>${f.disabled ? 'Disabled' : 'Enabled'}</span></div>`).join('')}</div></div>`;
        
        // Uploads .htaccess
        html += `<div class="flex items-center gap-2 p-3 ${d.uploads_htaccess ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'} rounded-lg text-sm">
            <i class="fas fa-${d.uploads_htaccess ? 'check' : 'times'}-circle"></i>
            <strong>Uploads PHP Execution Block</strong> — ${d.uploads_htaccess ? 'Protected (uploads/.htaccess exists)' : 'UNPROTECTED — no .htaccess in uploads/'}
        </div>`;
        
        // Cookie flags
        if (d.cookie_flags) {
            html += `<div class="bg-white border rounded-lg p-4"><h4 class="font-semibold text-sm text-gray-700 mb-2"><i class="fas fa-cookie text-amber-500 mr-1"></i> Cookie Security</h4>
            <div class="grid md:grid-cols-3 gap-2">
                ${phpCheck('HttpOnly', d.cookie_flags.httponly, d.cookie_flags.httponly ? 'Enabled' : 'Disabled — JS can steal cookies')}
                ${phpCheck('Secure Flag', d.cookie_flags.secure, d.cookie_flags.secure ? 'Enabled' : 'Disabled — sent over HTTP')}
                ${phpCheck('SameSite', d.cookie_flags.samesite && d.cookie_flags.samesite !== 'None', d.cookie_flags.samesite || 'None')}
            </div></div>`;
        }
        
        // .htaccess rules audit
        if (d.htaccess_rules && d.htaccess_rules.length) {
            const hs = d.htaccess_score || {passed:0, total:0};
            const hPct = hs.total > 0 ? Math.round((hs.passed / hs.total) * 100) : 0;
            const hColor = hPct >= 80 ? 'green' : hPct >= 50 ? 'yellow' : 'red';
            html += `<div class="bg-white border rounded-lg p-4">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="font-semibold text-sm text-gray-700"><i class="fas fa-shield-alt text-indigo-500 mr-1"></i> .htaccess Security Audit</h4>
                    <span class="text-xs font-bold px-2 py-1 rounded-full bg-${hColor}-100 text-${hColor}-700">${hs.passed}/${hs.total} rules (${hPct}%)</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 mb-3"><div class="h-2 rounded-full bg-${hColor}-500 transition-all" style="width:${hPct}%"></div></div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                    ${d.htaccess_rules.map(r => `<div class="flex items-center gap-1.5 p-2 rounded text-xs ${r.found ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}"><i class="fas fa-${r.found ? 'check' : 'times'}-circle"></i>${r.rule}</div>`).join('')}
                </div>
            </div>`;
        }
        
        container.innerHTML = html;
    });
}

function scanSection(title, items, severity, renderer) {
    if (!items || !items.length) return `<div class="flex items-center gap-2 p-3 bg-green-50 rounded-lg text-sm text-green-700"><i class="fas fa-check-circle"></i> ${title} — No issues</div>`;
    return `<div class="bg-white border border-${severity==='critical'?'red':'yellow'}-200 rounded-lg p-4"><h4 class="font-semibold text-sm text-${severity==='critical'?'red':'yellow'}-700 mb-2"><i class="fas fa-exclamation-triangle mr-1"></i> ${title} (${items.length})</h4><div class="space-y-2">${renderer(items)}</div></div>`;
}

function phpCheck(label, pass, value) {
    return `<div class="flex items-center gap-2 p-2 rounded text-xs ${pass ? 'bg-green-50 text-green-700' : 'bg-yellow-50 text-yellow-700'}"><i class="fas fa-${pass ? 'check' : 'exclamation'}-circle"></i><strong>${label}:</strong> ${value}</div>`;
}

function escHtml(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function deleteFile(file) {
    if (!confirm('Delete suspicious file: ' + file + '?')) return;
    const fd = new FormData(); fd.append('action','delete_suspicious_file'); fd.append('file',file);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d => { alert(d.message); runScan(); });
}

// ── Alerts ──
function loadAlerts() {
    fetch(API+'?action=breach_alerts').then(r=>r.json()).then(res => {
        const alerts = res.data || [];
        const el = document.getElementById('alerts-list');
        if (!alerts.length) { el.innerHTML = '<p class="text-sm text-gray-400 text-center py-8">No breach alerts</p>'; return; }
        el.innerHTML = alerts.map(a => `
            <div class="flex items-start gap-3 p-4 rounded-lg ${a.is_read == 0 ? 'bg-red-50 border border-red-200' : 'bg-gray-50 border'}">
                <i class="fas fa-shield-alt ${a.is_read == 0 ? 'text-red-500' : 'text-gray-400'} mt-0.5"></i>
                <div class="flex-1">
                    <p class="text-sm font-semibold ${a.is_read == 0 ? 'text-red-800' : 'text-gray-700'}">${a.title || 'Security Alert'}</p>
                    <p class="text-xs text-gray-600 mt-0.5">${a.message}</p>
                    <p class="text-xs text-gray-400 mt-1">${a.created_at}</p>
                </div>
                ${a.is_read == 0 ? `<button onclick="dismissAlert(${a.id})" class="text-xs text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>` : ''}
            </div>
        `).join('');
    });
}

function dismissAlert(id) {
    const fd = new FormData(); fd.append('action','dismiss_alert'); fd.append('id',id);
    fetch(API,{method:'POST',body:fd}).then(()=>loadAlerts());
}

function dismissAllAlerts() {
    const fd = new FormData(); fd.append('action','dismiss_all_alerts');
    fetch(API,{method:'POST',body:fd}).then(()=>loadAlerts());
}

// ── .htaccess ──
function loadHtaccess() {
    fetch(API+'?action=get_htaccess_rules').then(r=>r.json()).then(res => {
        document.getElementById('htaccess-code').textContent = res.data?.rules || '';
    });
}

function copyHtaccess() {
    navigator.clipboard.writeText(document.getElementById('htaccess-code').textContent).then(() => alert('Rules copied!'));
}

// ── Settings ──
function saveSettings() {
    const fd = new FormData();
    fd.append('action', 'save_settings');
    document.querySelectorAll('.sec-toggle').forEach(t => fd.append(t.dataset.key, t.checked ? '1' : '0'));
    document.querySelectorAll('.sec-input').forEach(i => fd.append(i.dataset.key, i.value));
    
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(() => {
        const msg = document.getElementById('settings-msg');
        msg.classList.remove('hidden');
        setTimeout(() => msg.classList.add('hidden'), 3000);
    });
}

function enableAll() {
    document.querySelectorAll('.sec-toggle').forEach(t => t.checked = true);
    saveSettings();
}

function saveAdminKey() {
    const key = document.getElementById('admin-secret-key').value.trim();
    if (!key || key.length < 6) { alert('Key must be at least 6 characters'); return; }
    const fd = new FormData();
    fd.append('action', 'save_admin_key');
    fd.append('key', key);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d => {
        if (d.success) {
            document.getElementById('admin-url-display').textContent = '<?= SITE_URL ?>/admin/login.php?access=' + key;
            alert('Admin access key updated! Bookmark your new login URL.');
        } else alert(d.message);
    });
}

function genRandomKey() {
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    let key = '';
    for (let i = 0; i < 16; i++) key += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('admin-secret-key').value = key;
}

// ── Admin Key ──
function copyAdminUrl() {
    const key = document.getElementById('admin-secret-key').value;
    const url = '<?= SITE_URL ?>/menzio-panel.php?key=' + key;
    navigator.clipboard.writeText(url).then(() => alert('Admin URL copied! Bookmark this URL.'));
}

function saveAdminKey() {
    const key = document.getElementById('admin-secret-key').value.trim();
    if (!key || key.length < 6) { alert('Key must be at least 6 characters'); return; }
    if (!confirm('Are you sure? You will need the new URL to access admin:\n\n<?= SITE_URL ?>/menzio-panel.php?key=' + key + '\n\nBookmark it now!')) return;
    const fd = new FormData(); fd.append('action','save_admin_key'); fd.append('key', key);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d => {
        if (d.success) {
            document.getElementById('secret-key-display').textContent = key;
            alert('Secret key updated! New URL:\n<?= SITE_URL ?>/menzio-panel.php?key=' + key);
        } else alert(d.message);
    });
}

// ── Init ──
loadDashboard();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
