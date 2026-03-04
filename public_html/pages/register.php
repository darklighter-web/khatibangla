<?php
/**
 * Customer Registration — Clerk SignUp widget
 * Signup is handled entirely by Clerk (Google, email, phone).
 * After signup, syncs to PHP session and redirects to account.
 */
$pageTitle = 'রেজিস্ট্রেশন';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/clerk.php';

$redirectTo   = sanitize($_GET['redirect'] ?? url('account'));
$clerkEnabled = isClerkEnabled();

// Already logged in
if (isCustomerLoggedIn()) { redirect(url('account')); }

// Clerk disabled → redirect to login which has legacy register
if (!$clerkEnabled) { redirect(url('login')); }

$seo = ['type' => 'website', 'noindex' => true];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-md mx-auto px-4 py-8">
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden">
        <div class="p-6">
            <h2 class="text-xl font-bold text-gray-800 text-center mb-1">রেজিস্ট্রেশন করুন</h2>
            <p class="text-sm text-gray-500 text-center mb-5">নতুন একাউন্ট তৈরি করুন</p>

            <!-- Clerk SignUp widget mounts here -->
            <div id="clerk-auth" class="min-h-[360px] flex items-center justify-center">
                <div id="clerk-loading" class="text-center text-gray-400">
                    <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                    <p class="text-sm">Loading...</p>
                </div>
            </div>

            <div id="clerk-error" class="hidden mt-4 p-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg text-sm">
                <div class="flex items-start gap-2">
                    <i class="fas fa-exclamation-triangle mt-0.5 flex-shrink-0"></i>
                    <div>
                        <span id="clerk-error-msg">রেজিস্ট্রেশন সিস্টেম লোড হয়নি।</span>
                        <button onclick="retryClerk()" class="ml-2 text-blue-600 underline text-xs">আবার চেষ্টা করুন</button>
                    </div>
                </div>
            </div>

            <div id="clerk-status" class="hidden mt-4 p-3 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg text-sm text-center">
                <i class="fas fa-spinner fa-spin mr-1"></i> একাউন্ট তৈরি হচ্ছে...
            </div>

            <div class="mt-4 text-center text-sm text-gray-500">
                একাউন্ট আছে?
                <a href="<?= url('login') ?>" class="text-blue-600 font-medium hover:underline">লগইন করুন</a>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const REDIRECT  = '<?= e($redirectTo) ?>';
    const SYNC_URL  = '<?= rtrim(SITE_URL, "/") ?>/api/clerk-sync.php';
    const SITE_BASE = '<?= rtrim(SITE_URL, "/") ?>';
    let syncing = false, clerkInitDone = false, activePoll = null;

    function showError(msg) {
        document.getElementById('clerk-error-msg').textContent = msg;
        document.getElementById('clerk-error').classList.remove('hidden');
    }
    function showStatus(msg) {
        const el = document.getElementById('clerk-status');
        el.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ' + msg;
        el.classList.remove('hidden');
    }
    window.retryClerk = function () {
        document.getElementById('clerk-error').classList.add('hidden');
        document.getElementById('clerk-auth').innerHTML =
            '<div id="clerk-loading" class="text-center text-gray-400"><i class="fas fa-spinner fa-spin text-2xl mb-2"></i><p class="text-sm">Loading...</p></div>';
        clerkInitDone = false; syncing = false;
        initClerk();
    };

    async function syncAndRedirect() {
        if (syncing) return;
        syncing = true;
        showStatus('একাউন্ট তৈরি হচ্ছে...');
        try {
            const user = window.Clerk.user;
            if (!user) { syncing = false; return; }
            const token = await window.Clerk.session.getToken();
            const res = await fetch(SYNC_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'sync', token, clerk_user_id: user.id })
            });
            if (!res.ok) { showError('সিঙ্ক ব্যর্থ (HTTP ' + res.status + ')।'); syncing = false; return; }
            const data = await res.json();
            if (data.success) {
                showStatus('সফল! একাউন্টে প্রবেশ করা হচ্ছে...');
                window.location.href = REDIRECT;
            } else {
                showError('সিঙ্ক ব্যর্থ: ' + (data.error || 'Unknown'));
                syncing = false;
            }
        } catch (e) {
            console.error('[Clerk]', e);
            showError('নেটওয়ার্ক ত্রুটি।');
            syncing = false;
        }
    }

    function mountSignUp() {
        const el = document.getElementById('clerk-auth');
        if (!el) return;
        el.innerHTML = '';
        if (activePoll) { clearInterval(activePoll); activePoll = null; }

        try {
            window.Clerk.mountSignUp(el, {
                signInUrl: SITE_BASE + '/login',
                appearance: {
                    variables: {
                        colorPrimary: getComputedStyle(document.documentElement)
                            .getPropertyValue('--btn-primary').trim() || '#f97316',
                        borderRadius: '0.75rem'
                    },
                    elements: { rootBox: 'w-full', card: 'shadow-none border-0 w-full !p-0' }
                }
            });
        } catch (e) {
            console.error('[Clerk] mountSignUp error:', e);
            showError('রেজিস্ট্রেশন সিস্টেম উপলব্ধ নেই।');
            return;
        }

        activePoll = setInterval(async () => {
            if (window.Clerk.user && !syncing) {
                clearInterval(activePoll); activePoll = null;
                await syncAndRedirect();
            }
        }, 800);

        try {
            window.Clerk.addListener(({ user }) => {
                if (user && !syncing) {
                    if (activePoll) { clearInterval(activePoll); activePoll = null; }
                    syncAndRedirect();
                }
            });
        } catch (e) {}
    }

    async function initClerk() {
        if (clerkInitDone) return;

        let waited = 0;
        while (!window.Clerk && waited < 5000) {
            await new Promise(r => setTimeout(r, 200));
            waited += 200;
        }
        if (!window.Clerk) {
            document.getElementById('clerk-loading')?.classList.add('hidden');
            showError('রেজিস্ট্রেশন সিস্টেম লোড হয়নি।');
            return;
        }

        try {
            // routerPush/routerReplace: prevents page reload during SSO (Google) callback
            // which would destroy the PKCE nonce and cause "failed security validations"
            await window.Clerk.load({
                routerPush:    (to) => history.pushState({}, '', to),
                routerReplace: (to) => history.replaceState({}, '', to),
            });
        } catch (e) {
            console.error('[Clerk] load() error:', e);
            document.getElementById('clerk-loading')?.classList.add('hidden');
            showError('সিস্টেম লোড ব্যর্থ।');
            return;
        }

        clerkInitDone = true;

        if (window.Clerk.user) {
            await syncAndRedirect();
            return;
        }

        mountSignUp();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initClerk);
    } else {
        initClerk();
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
