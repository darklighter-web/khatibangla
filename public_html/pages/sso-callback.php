<?php
/**
 * SSO Callback — OAuth completion page
 *
 * After Google/Facebook OAuth, Clerk redirects the browser here.
 * This is a clean page with NO other Clerk widget mounted.
 * Calling Clerk.load() on this page completes the PKCE verification.
 *
 * IMPORTANT: Must go through index.php router (not loaded directly)
 * so that session.php is included and $_SESSION is available for sync.
 */
// session.php MUST be loaded before this — it is when routed through index.php
// but we add a safety check here
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session.php';
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/clerk.php';

// PHP session already exists → skip straight to account
if (isCustomerLoggedIn()) {
    redirect(url('account'));
}

$syncUrl    = rtrim(SITE_URL, '/') . '/api/clerk-sync.php';
$accountUrl = url('account');
$loginUrl   = url('login');
$siteName   = getSetting('site_name', 'Khatibangla');

$pk = htmlspecialchars(getClerkPublishableKey(), ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>লগইন হচ্ছে... — <?= getSetting('site_name', 'Khatibangla') ?></title>
<meta name="robots" content="noindex">
<script
    async
    crossorigin="anonymous"
    data-clerk-publishable-key="<?= $pk ?>"
    src="https://clerk.khatibangla.com/npm/@clerk/clerk-js@latest/dist/clerk.browser.js"
    type="text/javascript"
></script>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; background: #f9fafb; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 1rem; padding: 2.5rem; text-align: center; width: 100%; max-width: 360px; }
  .spinner { width: 3rem; height: 3rem; border: 3px solid #e5e7eb; border-top-color: #f97316; border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto 1.25rem; }
  @keyframes spin { to { transform: rotate(360deg); } }
  h2 { font-size: 1.1rem; font-weight: 600; color: #111827; margin-bottom: .4rem; }
  p { font-size: .875rem; color: #6b7280; }
  .error { display: none; }
  .error .icon { font-size: 2rem; margin-bottom: 1rem; }
  .btn { display: inline-block; margin-top: 1.25rem; padding: .6rem 1.5rem; background: #f97316; color: #fff; border-radius: .75rem; text-decoration: none; font-size: .875rem; font-weight: 600; }
  .success { display: none; }
  .check { width: 3rem; height: 3rem; background: #d1fae5; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; font-size: 1.5rem; }
</style>
</head>
<body>
<div class="card">
  <div id="st-loading">
    <div class="spinner"></div>
    <h2>লগইন হচ্ছে...</h2>
    <p>অনুগ্রহ করে অপেক্ষা করুন</p>
  </div>

  <div id="st-success" class="success">
    <div class="check">✓</div>
    <h2>সফল হয়েছে!</h2>
    <p>একাউন্টে প্রবেশ করা হচ্ছে...</p>
  </div>

  <div id="st-error" class="error">
    <div class="icon">✗</div>
    <h2>লগইন ব্যর্থ হয়েছে</h2>
    <p id="err-msg">অনুগ্রহ করে আবার চেষ্টা করুন।</p>
    <a href="<?= $loginUrl ?>" class="btn">আবার চেষ্টা করুন</a>
  </div>
</div>

<script>
(function () {
    const SYNC_URL   = <?= json_encode($syncUrl) ?>;
    const ACCOUNT    = <?= json_encode($accountUrl) ?>;
    const LOGIN      = <?= json_encode($loginUrl) ?>;
    let syncing = false;

    function show(state, msg) {
        document.getElementById('st-loading').style.display = 'none';
        document.getElementById('st-success').style.display = 'none';
        document.getElementById('st-error').style.display   = 'none';
        if (state === 'error') {
            document.getElementById('st-error').style.display = 'block';
            if (msg) document.getElementById('err-msg').textContent = msg;
        } else {
            document.getElementById('st-' + state).style.display = 'block';
        }
    }

    async function syncAndRedirect() {
        if (syncing) return;
        syncing = true;
        show('success');
        try {
            const user  = window.Clerk.user;
            // token optional — clerk-sync.php verifies via Backend API
            let token = null;
            try { token = await window.Clerk.session?.getToken(); } catch(e) {}
            const res   = await fetch(SYNC_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'sync', token: token || '', clerk_user_id: user.id })
            });
            const data  = await res.json();
            if (data.success) {
                window.location.replace(ACCOUNT);
            } else {
                show('error', data.error || 'সিঙ্ক ব্যর্থ।');
            }
        } catch (e) {
            console.error('[Clerk SSO]', e);
            show('error', 'নেটওয়ার্ক ত্রুটি।');
        }
    }

    async function init() {
        // Wait for Clerk SDK (data-clerk-publishable-key auto-inits it)
        let t = 0;
        while (!window.Clerk && t < 8000) {
            await new Promise(r => setTimeout(r, 150));
            t += 150;
        }
        if (!window.Clerk) {
            show('error', 'লগইন সিস্টেম লোড হয়নি।');
            return;
        }

        try {
            /*
             * Clerk.load() on this clean page (no other widgets mounted)
             * automatically detects the OAuth callback params in the URL
             * (the __clerk_* query params or hash state) and completes
             * the PKCE verification. This is the correct way to handle
             * OAuth callbacks with @clerk/clerk-js CDN build.
             *
             * No mountSignIn() call here — mounting would restart auth
             * instead of completing the existing OAuth flow.
             */
            await window.Clerk.load();
        } catch (e) {
            console.error('[Clerk] load() error:', e);
            show('error', 'লগইন সম্পন্ন হয়নি।');
            return;
        }

        // If Clerk completed the OAuth, user is now set
        if (window.Clerk.user) {
            await syncAndRedirect();
            return;
        }

        // Poll briefly (Clerk may set user async after load())
        let tries = 0;
        const p = setInterval(async () => {
            tries++;
            if (window.Clerk?.user) {
                clearInterval(p);
                await syncAndRedirect();
            } else if (tries > 40) { // 6s
                clearInterval(p);
                show('error', 'লগইন সম্পন্ন হয়নি। আবার চেষ্টা করুন।');
            }
        }, 150);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
</body>
</html>
