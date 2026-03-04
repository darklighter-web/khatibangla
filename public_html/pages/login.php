<?php
/**
 * Login / Register Page
 *
 * Clerk mode  : Google + email via Clerk mountSignIn widget.
 *               The embedded widget handles EVERYTHING internally:
 *               - Normal sign-in (email/password)
 *               - Google/Facebook OAuth (including the #/sso-callback return)
 *               - Inline sign-up ("No account? Sign up" stays in the widget)
 *               Just mount it — do NOT call handleRedirectCallback() (that is
 *               for the React/headless SDK only, not for embedded UI).
 *
 * Legacy mode : Phone + password PHP form (shown when legacy enabled in settings).
 * Toggles     : Admin → Settings → Clerk Auth tab.
 */
$pageTitle = 'লগইন / রেজিস্ট্রেশন';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/clerk.php';

$redirectTo    = sanitize($_GET['redirect'] ?? $_POST['redirect'] ?? url('account'));
$error         = '';
$clerkEnabled  = isClerkEnabled();
$legacyEnabled = (getSetting('clerk_keep_legacy_login', '1') === '1');
$siteName      = getSetting('site_name', 'Khatibangla');

// Handle logout
if (($_GET['action'] ?? '') === 'logout') {
    customerLogout();
    unset($_SESSION['clerk_user_id'], $_SESSION['clerk_image']);
    redirect(url('login'));
}

if (isCustomerLoggedIn()) { redirect($redirectTo); }

// Legacy POST handler
$tab = $_POST['tab'] ?? 'login';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login' && $legacyEnabled) {
        $phone    = sanitize($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        if (empty($phone) || empty($password)) {
            $error = 'ফোন নম্বর ও পাসওয়ার্ড দিন।';
        } elseif (isPhoneBlocked($phone)) {
            $error = 'এই ফোন নম্বর ব্লক করা হয়েছে।';
        } else {
            $customer = customerLogin($phone, $password);
            if ($customer) { redirect($redirectTo); }
            else { $error = 'ফোন নম্বর বা পাসওয়ার্ড ভুল।'; }
        }
    }

    if ($action === 'register' && $legacyEnabled && !$clerkEnabled) {
        $tab             = 'register';
        $name            = sanitize($_POST['name'] ?? '');
        $phone           = sanitize($_POST['phone'] ?? '');
        $email           = sanitize($_POST['email'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        if (empty($name) || empty($phone) || empty($password)) {
            $error = 'সকল প্রয়োজনীয় তথ্য দিন।';
        } elseif (strlen($password) < 6) {
            $error = 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।';
        } elseif ($password !== $confirmPassword) {
            $error = 'পাসওয়ার্ড মিলছে না।';
        } elseif (isPhoneBlocked($phone)) {
            $error = 'এই ফোন নম্বর ব্লক করা হয়েছে।';
        } else {
            $regData = ['name'=>$name,'phone'=>$phone,'email'=>$email,'password'=>$password];
            foreach (['address','city','district','alt_phone'] as $ef) {
                if (!empty($_POST[$ef])) $regData[$ef] = sanitize($_POST[$ef]);
            }
            $result = customerRegister($regData);
            if (isset($result['error'])) { $error = $result['error']; }
            else { redirect($redirectTo); }
        }
    }
}

$seo     = ['type' => 'website', 'noindex' => true];
$syncUrl = rtrim(SITE_URL, '/') . '/api/clerk-sync.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-[70vh] flex items-start justify-center px-4 py-10 bg-gray-50">
<div class="w-full max-w-md space-y-4">

    <div class="text-center">
        <h1 class="text-2xl font-bold text-gray-900">স্বাগতম!</h1>
        <p class="text-sm text-gray-500 mt-1">লগইন বা রেজিস্ট্রেশন করুন</p>
    </div>

    <?php if ($clerkEnabled): ?>
    <!-- ═══════════════════════════════════════════════════
         CLERK — Google + Email. Sign-up is inline.
         ═══════════════════════════════════════════════════ -->
    <div class="bg-white rounded-2xl shadow-sm border">
        <div class="px-6 py-6">
            <div id="clerk-auth" class="min-h-[300px] flex items-center justify-center">
                <div id="clerk-loading" class="text-center py-6">
                    <div class="w-8 h-8 border-2 border-gray-100 border-t-orange-500 rounded-full animate-spin mx-auto mb-3"></div>
                    <p class="text-sm text-gray-400">লোড হচ্ছে...</p>
                </div>
            </div>

            <div id="clerk-error" class="hidden mt-3 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
                <div class="flex items-start gap-2">
                    <i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0 text-red-400"></i>
                    <div>
                        <span id="clerk-error-msg"></span>
                        <button onclick="retryClerk()" class="ml-2 underline text-xs font-medium">আবার চেষ্টা করুন</button>
                    </div>
                </div>
            </div>

            <div id="clerk-status" class="hidden mt-3 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm text-center">
                <div class="inline-block w-4 h-4 border-2 border-green-200 border-t-green-500 rounded-full animate-spin mr-1.5 align-middle"></div>
                একাউন্টে প্রবেশ করা হচ্ছে...
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($clerkEnabled && $legacyEnabled): ?>
    <div class="flex items-center gap-3">
        <div class="flex-1 border-t border-gray-200"></div>
        <span class="text-xs text-gray-400 font-medium">অথবা</span>
        <div class="flex-1 border-t border-gray-200"></div>
    </div>
    <?php endif; ?>

    <?php if ($legacyEnabled): ?>
    <!-- ═══════════════════════════════════════════════════
         LEGACY — Phone + password
         ═══════════════════════════════════════════════════ -->
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden">

        <?php if (!$clerkEnabled): ?>
        <div class="grid grid-cols-2 border-b border-gray-100">
            <button type="button" onclick="switchTab('login')" id="tab-login"
                    class="py-3.5 text-sm font-semibold text-center border-b-2 border-orange-500 text-orange-600">
                লগইন
            </button>
            <button type="button" onclick="switchTab('register')" id="tab-register"
                    class="py-3.5 text-sm font-semibold text-center border-b-2 border-transparent text-gray-400">
                রেজিস্ট্রেশন
            </button>
        </div>
        <?php else: ?>
        <div class="px-5 pt-4 pb-1">
            <p class="text-xs text-gray-400 flex items-center gap-1.5">
                <i class="fas fa-phone-alt text-green-500 text-xs"></i> ফোন নম্বর দিয়ে লগইন
            </p>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="mx-5 mt-3 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm"><?= e($error) ?></div>
        <?php endif; ?>

        <!-- Login form -->
        <div id="form-login" class="p-5 <?= $tab === 'register' ? 'hidden' : '' ?>">
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">ফোন নম্বর</label>
                    <input type="tel" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" required
                           class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 focus:border-orange-400 outline-none transition"
                           placeholder="01XXXXXXXXX">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">পাসওয়ার্ড</label>
                    <div class="relative">
                        <input type="password" name="password" id="pwd-login" required
                               class="w-full border border-gray-300 rounded-xl px-4 py-2.5 pr-10 text-sm focus:ring-2 focus:ring-orange-400 focus:border-orange-400 outline-none transition"
                               placeholder="••••••••">
                        <button type="button" onclick="togglePwd('pwd-login',this)"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="w-full btn-primary py-2.5 rounded-xl text-sm font-semibold">
                    লগইন করুন
                </button>
                <div class="flex items-center justify-between text-xs">
                    <a href="<?= url('forgot-password') ?>" class="text-blue-600 hover:underline">পাসওয়ার্ড ভুলে গেছেন?</a>
                    <?php if (!$clerkEnabled): ?>
                    <button type="button" onclick="switchTab('register')" class="text-gray-400 hover:text-gray-600">রেজিস্ট্রেশন →</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Register form — only when Clerk is disabled -->
        <?php if (!$clerkEnabled): ?>
        <div id="form-register" class="p-5 <?= $tab !== 'register' ? 'hidden' : '' ?>">
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="tab" value="register">
                <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">
                <?php
                $regFieldsJson = getSetting('registration_fields', '');
                $regFields = $regFieldsJson ? json_decode($regFieldsJson, true) : null;
                if (!$regFields) {
                    $regFields = [
                        ['key'=>'name',             'label'=>'নাম',                 'enabled'=>true,'required'=>true, 'placeholder'=>'আপনার পূর্ণ নাম'],
                        ['key'=>'phone',            'label'=>'ফোন নম্বর',           'enabled'=>true,'required'=>true, 'placeholder'=>'01XXXXXXXXX'],
                        ['key'=>'email',            'label'=>'ইমেইল',               'enabled'=>true,'required'=>false,'placeholder'=>'email@example.com'],
                        ['key'=>'password',         'label'=>'পাসওয়ার্ড',          'enabled'=>true,'required'=>true, 'placeholder'=>'কমপক্ষে ৬ অক্ষর'],
                        ['key'=>'confirm_password', 'label'=>'পাসওয়ার্ড নিশ্চিত', 'enabled'=>true,'required'=>true, 'placeholder'=>'পাসওয়ার্ড আবার দিন'],
                    ];
                }
                $_seen = [];
                $regFields = array_values(array_filter($regFields, function($f) use (&$_seen) {
                    $k = $f['key'] ?? ''; if (isset($_seen[$k])) return false; $_seen[$k] = true; return true;
                }));
                foreach ($regFields as $rf):
                    if (!($rf['enabled'] ?? true)) continue;
                    $k   = $rf['key'];
                    $lbl = $rf['label'] ?? $k;
                    $ph  = $rf['placeholder'] ?? '';
                    $req = ($rf['required'] ?? false);
                    $ra  = $req ? 'required' : '';
                    $star = $req ? ' *' : '';
                    $it  = match($k) { 'email'=>'email','phone','alt_phone'=>'tel','password','confirm_password'=>'password',default=>'text' };
                    $ml  = in_array($k, ['password','confirm_password']) ? 'minlength="6"' : '';
                    $pv  = e($_POST[$k] ?? '');
                    if (in_array($k, ['password','confirm_password'])) $pv = '';
                ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5"><?= e($lbl) ?><?= $star ?></label>
                    <?php if ($k === 'address'): ?>
                    <textarea name="<?= $k ?>" <?= $ra ?> placeholder="<?= e($ph) ?>" rows="2"
                              class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none"><?= $pv ?></textarea>
                    <?php else: ?>
                    <input type="<?= $it ?>" name="<?= $k ?>" value="<?= $pv ?>" <?= $ra ?> <?= $ml ?>
                           class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-400 outline-none transition"
                           placeholder="<?= e($ph) ?>">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <button type="submit" class="w-full btn-primary py-2.5 rounded-xl text-sm font-semibold">
                    রেজিস্ট্রেশন করুন
                </button>
                <p class="text-center text-sm text-gray-400">
                    একাউন্ট আছে?
                    <button type="button" onclick="switchTab('login')" class="text-blue-600 font-medium">লগইন করুন</button>
                </p>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$clerkEnabled && !$legacyEnabled): ?>
    <div class="text-center py-12 text-gray-400">
        <i class="fas fa-lock text-4xl mb-3 block"></i>
        <p class="text-sm">লগইন সিস্টেম বন্ধ আছে।</p>
    </div>
    <?php endif; ?>

</div>
</div>

<?php if ($clerkEnabled): ?>
<script>
(function () {
    'use strict';

    const REDIRECT  = <?= json_encode($redirectTo) ?>;
    const SYNC_URL  = <?= json_encode($syncUrl) ?>;
    const SITE_NAME = <?= json_encode($siteName) ?>;
    let syncing = false, initDone = false, poll = null;

    /* ── UI ─────────────────────────────────────────────────── */
    function showError(msg) {
        document.getElementById('clerk-loading')?.remove();
        document.getElementById('clerk-error-msg').textContent = msg;
        document.getElementById('clerk-error').classList.remove('hidden');
    }
    function showStatus() {
        document.getElementById('clerk-status').classList.remove('hidden');
    }
    window.retryClerk = function () {
        document.getElementById('clerk-error').classList.add('hidden');
        const el = document.getElementById('clerk-auth');
        if (el) el.innerHTML = '<div id="clerk-loading" class="text-center py-6">'
            + '<div class="w-8 h-8 border-2 border-gray-100 border-t-orange-500 rounded-full animate-spin mx-auto mb-3"></div>'
            + '<p class="text-sm text-gray-400">লোড হচ্ছে...</p></div>';
        initDone = false; syncing = false;
        initClerk();
    };

    /* ── Sync Clerk session → PHP ───────────────────────────── */
    async function syncAndRedirect() {
        if (syncing) return;
        syncing = true;
        showStatus();
        try {
            const user = window.Clerk?.user;
            if (!user) { syncing = false; return; }
            // token is optional — clerk-sync.php verifies via Clerk Backend API
            let token = null;
            try { token = await window.Clerk.session?.getToken(); } catch(e) {}
            const res = await fetch(SYNC_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'sync', token: token || '', clerk_user_id: user.id })
            });
            if (!res.ok) { showError('সিঙ্ক ব্যর্থ (HTTP ' + res.status + ')'); syncing = false; return; }
            const data = await res.json();
            if (data.success) {
                window.location.href = REDIRECT;
            } else {
                showError(data.error || 'সিঙ্ক ব্যর্থ হয়েছে।');
                syncing = false;
            }
        } catch (e) {
            console.error('[Clerk]', e);
            showError('নেটওয়ার্ক ত্রুটি।');
            syncing = false;
        }
    }

    /* ── Mount ──────────────────────────────────────────────── */
    // Determine whether to show sign-in or sign-up widget
    // Clerk navigates to ?mode=signup when user clicks "Sign up"
    function getMode() {
        const p = new URLSearchParams(window.location.search);
        if (p.get('mode') === 'signup' || window.location.hash.includes('sign-up')) return 'signup';
        return 'signin';
    }

    function mount() {
        const el = document.getElementById('clerk-auth');
        if (!el) return;
        el.innerHTML = '';
        if (poll) { clearInterval(poll); poll = null; }

        const mode = getMode();
        const origin = window.location.origin;

        const appearance = {
            variables: {
                colorPrimary:       getComputedStyle(document.documentElement)
                                    .getPropertyValue('--btn-primary').trim() || '#f97316',
                colorBackground:    '#ffffff',
                colorText:          '#111827',
                colorTextSecondary: '#6b7280',
                colorInputBackground: '#ffffff',
                borderRadius:       '0.75rem',
                fontFamily:         'inherit',
                fontSize:           '14px',
            },
            elements: {
                rootBox:        'w-full',
                card:           'shadow-none border-0 w-full p-0 m-0',
                header:         'pb-2',
                headerTitle:    'text-gray-800 text-lg font-bold',
                headerSubtitle: 'hidden',
                socialButtonsBlockButton: 'rounded-xl border border-gray-200 hover:bg-gray-50 font-medium',
                formButtonPrimary: 'rounded-xl font-semibold',
                formFieldInput: 'rounded-xl',
                footerActionLink: 'font-semibold',
            }
        };

        try {
            if (mode === 'signup') {
                window.Clerk.mountSignUp(el, {
                    routing:  'virtual',
                    signInUrl: origin + '/login',
                    appearance,
                });
            } else {
                window.Clerk.mountSignIn(el, {
                    routing:   'virtual',
                    signUpUrl:  origin + '/login?mode=signup',
                    appearance,
                });
            }
        } catch (e) {
            console.error('[Clerk] mount:', e);
            showError('লগইন উইজেট লোড ব্যর্থ।');
            return;
        }

        /* Poll: fires when user completes sign-in OR sign-up OR OAuth */
        poll = setInterval(async () => {
            if (window.Clerk?.user && !syncing) {
                clearInterval(poll); poll = null;
                await syncAndRedirect();
            }
        }, 500);

        /* Backup: Clerk event listener (fires faster than poll) */
        try {
            window.Clerk.addListener(({ user }) => {
                if (user && !syncing) {
                    if (poll) { clearInterval(poll); poll = null; }
                    syncAndRedirect();
                }
            });
        } catch (_) {}
    }

    /* ── Bootstrap ──────────────────────────────────────────── */
    async function initClerk() {
        if (initDone) return;

        /* Wait up to 5s for Clerk SDK to load from CDN */
        let t = 0;
        while (!window.Clerk && t < 5000) {
            await new Promise(r => setTimeout(r, 150));
            t += 150;
        }
        if (!window.Clerk) {
            showError('সোশ্যাল লগইন লোড হয়নি। ইন্টারনেট চেক করুন।');
            return;
        }

        try {
            /*
             * Clerk v5: Clerk.load() initialises the SDK manually.
             * - publishableKey: passed here (no data-clerk-publishable-key on
             *   the <script> tag, which would trigger auto-init and conflict).
             * - routerPush/routerReplace: required in v5 for embedded (non-SPA)
             *   widgets so Clerk doesn't try to use a client-side router.
             * - afterSignInUrl/afterSignUpUrl are REMOVED in v5 — redirect is
             *   handled by the poll/listener that calls syncAndRedirect().
             * - routing:'virtual' on mountSignIn/mountSignUp tells Clerk not
             *   to try to navigate the page itself after auth completes.
             */
            await window.Clerk.load({
                publishableKey: <?= json_encode(CLERK_PUBLISHABLE_KEY) ?>,
                routerPush:     (to) => { window.location.href = to; },
                routerReplace:  (to) => { window.location.replace(to); },
            });
        } catch (e) {
            console.error('[Clerk] load():', e);
            showError('লগইন সিস্টেম লোড ব্যর্থ।');
            return;
        }

        initDone = true;

        /* User already authenticated (session cookie present) */
        if (window.Clerk.user) {
            await syncAndRedirect();
            return;
        }

        /*
         * Always mount the SignIn widget — even on OAuth return (#/sso-callback).
         *
         * The embedded mountSignIn component handles BOTH:
         *   a) Normal sign-in/sign-up
         *   b) OAuth callback completion (detects PKCE state from sessionStorage)
         *
         * Do NOT call handleRedirectCallback() here — that method belongs to
         * the headless/React SDK (@clerk/clerk-react, @clerk/nextjs), NOT to
         * the embedded UI (@clerk/clerk-js mountSignIn). Calling it on the
         * embedded component causes "failed security validations".
         */
        mount();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initClerk);
    } else {
        initClerk();
    }
})();
</script>
<?php endif; ?>

<script>
function togglePwd(id, btn) {
    const inp = document.getElementById(id);
    const vis = inp.type === 'text';
    inp.type = vis ? 'password' : 'text';
    btn.querySelector('i').className = vis ? 'fas fa-eye text-sm' : 'fas fa-eye-slash text-sm';
}
function switchTab(tab) {
    document.getElementById('form-login')?.classList.toggle('hidden', tab !== 'login');
    document.getElementById('form-register')?.classList.toggle('hidden', tab !== 'register');
    ['login','register'].forEach(t => {
        const b = document.getElementById('tab-' + t);
        if (!b) return;
        b.classList.toggle('border-orange-500', t === tab);
        b.classList.toggle('text-orange-600',   t === tab);
        b.classList.toggle('border-transparent', t !== tab);
        b.classList.toggle('text-gray-400',      t !== tab);
    });
}
<?php if ($tab === 'register'): ?>switchTab('register');<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
