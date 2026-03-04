<?php
// Clerk Debug Page — DELETE after fixing
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/clerk.php';
$pk = CLERK_PUBLISHABLE_KEY;
$siteUrl = rtrim(SITE_URL, '/');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Clerk Debug</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: monospace; background: #0f0f0f; color: #e2e2e2; padding: 20px; }
h2 { color: #f97316; margin-bottom: 16px; font-size: 18px; }
h3 { color: #60a5fa; margin: 20px 0 8px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
.card { background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
.row { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 8px; font-size: 13px; }
.label { color: #9ca3af; min-width: 180px; flex-shrink: 0; }
.val { color: #f3f4f6; word-break: break-all; }
.ok  { color: #22c55e; font-weight: bold; }
.err { color: #ef4444; font-weight: bold; }
.warn{ color: #f59e0b; font-weight: bold; }
#log { background: #0a0a0a; border: 1px solid #2a2a2a; border-radius: 6px; padding: 12px; font-size: 12px; max-height: 340px; overflow-y: auto; }
.log-ok   { color: #22c55e; }
.log-err  { color: #ef4444; }
.log-info { color: #93c5fd; }
.log-warn { color: #fbbf24; }
pre { white-space: pre-wrap; word-break: break-all; font-size: 12px; color: #d1d5db; background: #0a0a0a; padding: 10px; border-radius: 4px; margin-top: 6px; }
#mount-target { border: 2px dashed #374151; border-radius: 8px; padding: 20px; min-height: 100px; margin-top: 8px; }
</style>
</head>
<body>

<h2>🔍 Clerk Auth Debug Page</h2>
<p style="color:#6b7280;font-size:12px;margin-bottom:20px">Delete <code>clerk-debug.php</code> after fixing. URL: <a href="<?= $siteUrl ?>/clerk-debug.php" style="color:#60a5fa"><?= $siteUrl ?>/clerk-debug.php</a></p>

<!-- PHP Checks -->
<div class="card">
  <h3>PHP Config</h3>
  <div class="row"><span class="label">PHP Version</span><span class="val"><?= PHP_VERSION ?></span></div>
  <div class="row"><span class="label">CLERK_PUBLISHABLE_KEY</span><span class="val"><?= $pk ? '<span class="ok">✓ SET</span> <span style="color:#6b7280">'.substr($pk,0,20).'...</span>' : '<span class="err">✗ EMPTY</span>' ?></span></div>
  <div class="row"><span class="label">CLERK_SECRET_KEY</span><span class="val"><?= defined('CLERK_SECRET_KEY') && CLERK_SECRET_KEY ? '<span class="ok">✓ SET</span>' : '<span class="err">✗ EMPTY</span>' ?></span></div>
  <div class="row"><span class="label">isClerkEnabled()</span><span class="val"><?= isClerkEnabled() ? '<span class="ok">✓ TRUE</span>' : '<span class="err">✗ FALSE</span>' ?></span></div>
  <div class="row"><span class="label">SITE_URL</span><span class="val"><?= $siteUrl ?></span></div>
  <div class="row"><span class="label">clerk_enabled (DB)</span><span class="val" id="db-check">checking...</span></div>
</div>

<!-- Response Headers -->
<div class="card">
  <h3>Response Headers (sent by PHP)</h3>
  <?php
  $hdrs = headers_list();
  $cspFound = false;
  if (empty($hdrs)) { echo '<span class="warn">No headers captured yet (output buffering off)</span>'; }
  foreach ($hdrs as $h) {
      $cls = stripos($h,'Content-Security-Policy') !== false ? 'err' : 'ok';
      if (stripos($h,'Content-Security-Policy') !== false) $cspFound = true;
      echo "<div class='row'><span class='label'></span><span class='val $cls'>".htmlspecialchars($h)."</span></div>\n";
  }
  if ($cspFound) {
      echo "<div class='row'><span class='label'></span><span class='err'>⚠ CSP header found — may block Clerk CDN</span></div>";
  }
  ?>
</div>

<!-- JS Runtime Log -->
<div class="card">
  <h3>JavaScript Runtime Log</h3>
  <div id="log"><span class="log-info">Running checks...</span></div>
</div>

<!-- Live Mount Test -->
<div class="card">
  <h3>Live Mount Test</h3>
  <p style="font-size:12px;color:#6b7280;margin-bottom:8px">Attempting to actually mount the Clerk sign-in widget below:</p>
  <div id="mount-target"><span style="color:#6b7280;font-size:12px">Waiting for Clerk...</span></div>
</div>

<!-- Clerk SDK -->
<script
    async
    crossorigin="anonymous"
    data-clerk-publishable-key="<?= htmlspecialchars($pk, ENT_QUOTES) ?>"
    src="https://cdn.jsdelivr.net/npm/@clerk/clerk-js@latest/dist/clerk.browser.js"
    type="text/javascript"
></script>

<script>
const PK = <?= json_encode($pk) ?>;
const SITE = <?= json_encode($siteUrl) ?>;
const log = (msg, cls='log-info') => {
    const d = document.getElementById('log');
    d.innerHTML += `<div class="${cls}">[${new Date().toISOString().substr(11,12)}] ${msg}</div>`;
    d.scrollTop = d.scrollHeight;
};
const ok   = msg => log('✓ ' + msg, 'log-ok');
const err  = msg => log('✗ ' + msg, 'log-err');
const warn = msg => log('⚠ ' + msg, 'log-warn');
const info = msg => log('ℹ ' + msg, 'log-info');

// Check CSP from JS side
info('Checking Content-Security-Policy...');
const metaCsp = document.querySelector('meta[http-equiv="Content-Security-Policy"]');
if (metaCsp) {
    warn('CSP meta tag found: ' + metaCsp.getAttribute('content').substring(0,100));
} else {
    info('No CSP meta tag (headers may still be set server-side)');
}

// Check DB setting via fetch
fetch('/api/clerk-sync.php?action=ping').then(r => r.text()).then(t => {
    document.getElementById('db-check').innerHTML = '<span style="color:#9ca3af">'+t.substring(0,80)+'</span>';
}).catch(e => {
    document.getElementById('db-check').innerHTML = '<span class="err">fetch error: '+e.message+'</span>';
});

// Wait for Clerk SDK
info('Waiting for window.Clerk (SDK loading from cdn.jsdelivr.net)...');
let t = 0;
const wait = setInterval(async () => {
    t += 200;
    if (t > 8000) {
        clearInterval(wait);
        err('window.Clerk NEVER loaded after 8s — CDN blocked or network error');
        err('→ Check CSP headers, .htaccess, or ad blocker');
        document.getElementById('mount-target').innerHTML = '<span style="color:#ef4444">SDK did not load</span>';
        return;
    }
    if (!window.Clerk) return;
    clearInterval(wait);
    ok('window.Clerk loaded in ' + t + 'ms');

    // Check Clerk version
    info('Clerk SDK version: ' + (window.Clerk.version || 'unknown'));
    if (window.Clerk.version && parseInt(window.Clerk.version) >= 5) {
        warn('Clerk v5 detected — afterSignInUrl/afterSignUpUrl removed from mount() in v5');
    } else if (window.Clerk.version) {
        ok('Clerk v' + window.Clerk.version + ' — pre-v5, mount options should work');
    }

    // Try Clerk.load()
    info('Calling Clerk.load({ publishableKey })...');
    try {
        await window.Clerk.load({
            publishableKey: PK,
            signInUrl:  SITE + '/login',
            signUpUrl:  SITE + '/login',
            routerPush: (to) => { window.location.href = to; },
            routerReplace: (to) => { window.location.replace(to); },
        });
        ok('Clerk.load() succeeded');
    } catch(e) {
        err('Clerk.load() FAILED: ' + e.message);
        document.getElementById('mount-target').innerHTML = '<span style="color:#ef4444">load() failed: ' + e.message + '</span>';
        return;
    }

    // Check if already signed in
    if (window.Clerk.user) {
        warn('User already signed in: ' + window.Clerk.user.id);
    } else {
        info('No active session');
    }

    // Try mountSignIn with v4 options
    info('Attempting mountSignIn() with afterSignInUrl (v4 style)...');
    const el = document.getElementById('mount-target');
    el.innerHTML = '';
    try {
        window.Clerk.mountSignIn(el, {
            afterSignInUrl:  SITE + '/sso-callback',
            afterSignUpUrl:  SITE + '/sso-callback',
            signUpUrl:       SITE + '/login?mode=signup',
        });
        ok('mountSignIn() with afterSignInUrl SUCCEEDED — v4 API works!');
    } catch(e) {
        err('mountSignIn() with v4 options FAILED: ' + e.message);
        warn('→ This confirms Clerk v5 breaking change');
        info('Retrying with v5 routing:virtual style...');
        el.innerHTML = '';
        try {
            window.Clerk.mountSignIn(el, {
                routing: 'virtual',
                signUpUrl: SITE + '/login?mode=signup',
            });
            ok('mountSignIn() with routing:virtual SUCCEEDED — need v5 fix in login.php');
        } catch(e2) {
            err('mountSignIn() v5 style also FAILED: ' + e2.message);
        }
    }

}, 200);
</script>

</body>
</html>
