<?php
/**
 * Load Tester — Real server-side parallel cURL load testing
 * Results streamed back live via SSE from admin/api/load-test-run.php
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Load Tester';
$siteUrl   = rtrim(SITE_URL, '/');

$defaultTargets = [
    ['label' => 'Homepage',  'path' => '/'],
    ['label' => 'Shop',      'path' => '/shop'],
    ['label' => 'Category',  'path' => '/category'],
];

require_once __DIR__ . '/../includes/header.php';
?>
<style>
/* ── Base ── */
.lt-card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:22px; }
.lt-stitle { font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.8px; margin-bottom:14px; }

/* ── Stat blocks ── */
.lt-stat { border-radius:14px; padding:15px 18px; }
.lt-stat-val { font-size:1.85rem; font-weight:800; line-height:1.05; font-variant-numeric:tabular-nums; }
.lt-stat-lbl { font-size:10px; font-weight:600; margin-top:5px; opacity:.7; text-transform:uppercase; letter-spacing:.4px; }

/* ── Badge ── */
.lt-badge { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; padding:4px 12px; border-radius:999px; transition:.2s; }
.lt-idle    { background:#f3f4f6; color:#6b7280; }
.lt-running { background:#fef3c7; color:#b45309; }
.lt-done    { background:#d1fae5; color:#047857; }

/* ── Progress ── */
.lt-prog-wrap { height:8px; background:#f1f5f9; border-radius:9px; overflow:hidden; }
.lt-prog-bar  { height:100%; border-radius:9px; transition:width .4s ease; }

/* ── Log ── */
#lt-log { height:220px; overflow-y:auto; font-size:11px; font-family:'Fira Mono','Cascadia Code',ui-monospace,monospace;
          background:#0d1117; color:#8b949e; border-radius:12px; padding:12px 16px; line-height:1.85; }
#lt-log .ok   { color:#3fb950; }
#lt-log .slow { color:#d29922; }
#lt-log .err  { color:#f85149; }
#lt-log .info { color:#58a6ff; }
#lt-log .warn { color:#e3b341; }

/* ── Sliders ── */
input[type=range] { accent-color:#f97316; width:100%; cursor:pointer; }

/* ── Toggle ── */
.lt-toggle { position:relative; display:inline-block; width:44px; height:24px; flex-shrink:0; }
.lt-toggle input { opacity:0; width:0; height:0; }
.lt-slider-pill { position:absolute; inset:0; background:#d1d5db; border-radius:24px; cursor:pointer; transition:.2s; }
.lt-slider-pill:before { content:''; position:absolute; width:18px; height:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.lt-toggle input:checked + .lt-slider-pill { background:#f97316; }
.lt-toggle input:checked + .lt-slider-pill:before { transform:translateX(20px); }

/* ── Load bars ── */
.lt-bar { flex:1; min-width:0; border-radius:3px 3px 0 0; transition:height .2s ease, background .2s ease; }

/* ── Grade circle ── */
.lt-grade-circle { font-size:1.5rem; font-weight:900; width:56px; height:56px; border-radius:50%;
                   display:flex; align-items:center; justify-content:center; transition:all .3s; }

/* ── Spinner ── */
.lt-spin { width:24px; height:24px; border:3px solid #e5e7eb; border-top-color:#f97316;
           border-radius:50%; animation:ltSpin .65s linear infinite; }
@keyframes ltSpin { to { transform:rotate(360deg); } }

/* ── Danger zone ── */
.lt-danger { border:2px solid #fca5a5; background:#fff5f5; border-radius:16px; padding:20px; }
.lt-danger-title { color:#dc2626; font-weight:800; font-size:14px; display:flex; align-items:center; gap:6px; }

/* ── iframe ── */
#lt-frame { width:100%; height:440px; border:none; border-radius:12px; display:block; }

/* ── Percentile bar ── */
.pct-bar { height:6px; border-radius:9px; }

/* ── Real badge ── */
.lt-real-badge { display:inline-flex; align-items:center; gap:5px; background:#ecfdf5; color:#047857;
                 border:1px solid #6ee7b7; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; }
/* ── Capacity panel ── */
.cap-meter-wrap { height:8px; background:#f1f5f9; border-radius:9px; overflow:hidden; }
.cap-meter-bar  { height:100%; border-radius:9px; transition:width .7s ease; }
.cap-big-num { font-size:3rem; font-weight:900; line-height:1; font-variant-numeric:tabular-nums; }
.cap-big-lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; margin-top:4px; }
.cap-big-sub { font-size:11px; margin-top:3px; }
.cap-row-bar { height:28px; border-radius:8px; transition:width .6s ease, background .3s; min-width:4px; }
.lt-suggest-btn { cursor:pointer; border:2px solid transparent; border-radius:12px; padding:10px 14px; transition:.2s; text-align:left; }
.lt-suggest-btn:hover { border-color:#f97316; }
.lt-suggest-btn.selected { border-color:#f97316; background:#fff7ed; }
.cap-grade-pill { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:800; padding:3px 12px; border-radius:999px; }
.grade-excellent { background:#d1fae5; color:#047857; }
.grade-good      { background:#dbeafe; color:#1d4ed8; }
.grade-stressed  { background:#fef3c7; color:#b45309; }
.grade-degraded  { background:#ffedd5; color:#c2410c; }
.grade-overloaded{ background:#fee2e2; color:#b91c1c; }
.grade-breaking  { background:#7f1d1d; color:#fca5a5; }
.cap-impact-high    { background:#fee2e2; color:#dc2626; }
.cap-impact-critical{ background:#7f1d1d; color:#fca5a5; }
.cap-impact-medium  { background:#fef3c7; color:#92400e; }
</style>

<div class="space-y-5">

<!-- ── Top bar ── -->
<div class="flex items-center justify-between flex-wrap gap-3">
    <div>
        <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <svg class="w-6 h-6 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            Load Tester
        </h1>
        <p class="text-sm text-gray-400 mt-0.5">Real server-side parallel cURL load testing · results stream live</p>
    </div>
    <div class="flex items-center gap-3 flex-wrap">
        <span class="lt-real-badge">
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            Real cURL
        </span>
        <span id="lt-badge" class="lt-badge lt-idle">
            <span id="lt-dot" class="w-2 h-2 rounded-full bg-gray-400 inline-block"></span>
            <span id="lt-badge-txt">Idle</span>
        </span>
        <button id="lt-run-btn" onclick="ltStart()"
            class="bg-orange-500 hover:bg-orange-600 active:bg-orange-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition shadow-sm flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 3l14 9-14 9V3z"/></svg>
            Run Test
        </button>
        <button id="lt-stop-btn" onclick="ltStop()" style="display:none"
            class="bg-red-500 hover:bg-red-600 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition shadow-sm flex items-center gap-2">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><rect x="5" y="5" width="10" height="10" rx="1"/></svg>
            Stop
        </button>
    </div>
</div>

<!-- ── Site Capacity Benchmark Panel ── -->
<div class="lt-card" id="cap-panel">
    <div class="flex items-center justify-between flex-wrap gap-3 mb-5">
        <div>
            <p class="lt-stitle mb-0">Site Capacity Benchmark</p>
            <p class="text-xs text-gray-400 mt-0.5">Fires real cURL requests at escalating concurrency levels to find your actual limits</p>
        </div>
        <button onclick="ltRunCapacity()" id="cap-btn"
            class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-xl transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Run Benchmark
        </button>
    </div>

    <!-- Idle state -->
    <div id="cap-idle" class="flex flex-col items-center justify-center py-10 gap-4 text-center">
        <div class="w-16 h-16 rounded-2xl bg-indigo-50 flex items-center justify-center">
            <svg class="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        </div>
        <div>
            <p class="text-sm font-semibold text-gray-700">Find your site's real capacity limit</p>
            <p class="text-xs text-gray-400 mt-1 max-w-sm">Automatically tests 1 → 3 → 5 → 10 → 20 → 50 → 100 → 200 concurrent users and tells you exactly where performance breaks down</p>
        </div>
    </div>

    <!-- Running state -->
    <div id="cap-running" style="display:none">
        <div class="flex items-center gap-3 mb-3">
            <div class="lt-spin"></div>
            <p class="text-sm font-semibold text-gray-700" id="cap-run-msg">Starting benchmark…</p>
            <span class="ml-auto text-xs text-gray-400 tabular-nums" id="cap-run-pct">0%</span>
        </div>
        <div class="cap-meter-wrap mb-4"><div class="cap-meter-bar bg-indigo-500" id="cap-prog" style="width:0%"></div></div>
        <!-- Live table as results come in -->
        <div id="cap-live-table"></div>
    </div>

    <!-- Results state -->
    <div id="cap-results" style="display:none">

        <!-- 3 big numbers -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="rounded-2xl p-5 bg-green-50 border border-green-100">
                <div class="cap-big-num text-green-600" id="cap-comfortable">—</div>
                <div class="cap-big-lbl text-green-700">Comfortable Load</div>
                <div class="cap-big-sub text-green-500">concurrent users — fast & stable</div>
            </div>
            <div class="rounded-2xl p-5 bg-yellow-50 border border-yellow-100">
                <div class="cap-big-num text-yellow-600" id="cap-degraded">—</div>
                <div class="cap-big-lbl text-yellow-700">Starts Degrading</div>
                <div class="cap-big-sub text-yellow-500">concurrent — noticeably slower</div>
            </div>
            <div class="rounded-2xl p-5 bg-red-50 border border-red-100">
                <div class="cap-big-num text-red-600" id="cap-breaking">—</div>
                <div class="cap-big-lbl text-red-700">Breaking Point</div>
                <div class="cap-big-sub text-red-400">concurrent — errors begin here</div>
            </div>
        </div>

        <!-- Summary bar -->
        <div class="rounded-2xl bg-gray-50 border border-gray-100 p-4 mb-6 flex flex-wrap gap-5 items-center">
            <div class="flex-1 min-w-0">
                <p class="font-bold text-gray-800 text-sm" id="cap-verdict-txt">—</p>
                <p class="text-xs text-gray-400 mt-1" id="cap-verdict-sub">—</p>
            </div>
            <div class="flex gap-5 flex-shrink-0 flex-wrap">
                <div class="text-center">
                    <p class="text-xl font-black text-orange-500 tabular-nums" id="cap-rps">—</p>
                    <p class="text-[10px] text-gray-400 font-semibold uppercase tracking-wider">Est. RPS</p>
                </div>
                <div class="text-center">
                    <p class="text-xl font-black text-blue-500 tabular-nums" id="cap-base-ms">—</p>
                    <p class="text-[10px] text-gray-400 font-semibold uppercase tracking-wider">Baseline Avg</p>
                </div>
                <div class="text-center">
                    <p class="text-xl font-black text-purple-500 tabular-nums" id="cap-sustained">—</p>
                    <p class="text-[10px] text-gray-400 font-semibold uppercase tracking-wider">Max Sustained</p>
                </div>
            </div>
        </div>

        <!-- Horizontal bar chart: response time per concurrency level -->
        <div class="mb-6">
            <p class="text-sm font-semibold text-gray-700 mb-3">Response Time vs Concurrency</p>
            <div id="cap-chart-rows" class="space-y-2"></div>
            <div class="flex justify-between text-[10px] text-gray-300 mt-2 px-[72px]">
                <span>0ms</span><span>500ms</span><span>1s</span><span>2s</span><span>3s+</span>
            </div>
        </div>

        <!-- Apply preset buttons -->
        <div>
            <p class="text-sm font-semibold text-gray-700 mb-3">
                Apply to Load Test
                <span class="text-xs text-gray-400 font-normal ml-2">click to set concurrency based on these findings</span>
            </p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2" id="cap-presets"></div>
        </div>

    </div>
</div>

<!-- ── Main grid ── -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

<!-- ════ LEFT ════ -->
<div class="space-y-5">

    <!-- Standard config -->
    <div class="lt-card">
        <p class="lt-stitle">Configuration</p>
        <div class="space-y-5">

            <!-- Targets -->
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Target Pages</label>
                <div id="lt-targets" class="space-y-2 mb-3">
                    <?php foreach ($defaultTargets as $t): ?>
                    <label class="flex items-center gap-2.5 text-sm cursor-pointer">
                        <input type="checkbox" class="lt-cb accent-orange-500 w-4 h-4 rounded" value="<?= e($siteUrl.$t['path']) ?>" checked>
                        <span class="text-gray-700 font-medium"><?= e($t['label']) ?></span>
                        <span class="text-gray-300 text-xs ml-auto font-mono"><?= e($t['path']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="flex gap-2">
                    <input id="lt-custom" type="text" placeholder="Add URL or /path…"
                        class="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-xs outline-none focus:ring-2 focus:ring-orange-300"
                        onkeydown="if(event.key==='Enter')ltAddUrl()">
                    <button onclick="ltAddUrl()" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg font-medium transition">Add</button>
                </div>
            </div>

            <!-- Concurrency -->
            <div>
                <div class="flex justify-between items-center mb-1.5">
                    <label class="text-sm font-semibold text-gray-700">Concurrent Requests</label>
                    <span id="v-users" class="text-sm font-bold text-orange-500 tabular-nums w-12 text-right">10</span>
                </div>
                <input type="range" id="s-users" min="1" max="200" value="10"
                       oninput="ltSyncUsers(this.value)">
                <div class="flex justify-between text-[10px] text-gray-300 -mt-0.5">
                    <span>1</span><span>50</span><span>100</span><span>150</span><span>200</span>
                </div>
                <p class="text-[10px] text-gray-400 mt-1">Server fires this many <strong>real cURL</strong> requests per wave simultaneously</p>
            </div>

            <!-- Duration -->
            <div>
                <div class="flex justify-between items-center mb-1.5">
                    <label class="text-sm font-semibold text-gray-700">Test Duration</label>
                    <span id="v-dur" class="text-sm font-bold text-orange-500 tabular-nums">20s</span>
                </div>
                <input type="range" id="s-dur" min="5" max="300" value="20" step="5"
                       oninput="document.getElementById('v-dur').textContent=this.value+'s'">
                <div class="flex justify-between text-[10px] text-gray-300 -mt-0.5">
                    <span>5s</span><span>1m</span><span>2m</span><span>5m</span>
                </div>
            </div>

            <!-- Wave interval -->
            <div>
                <div class="flex justify-between items-center mb-1.5">
                    <label class="text-sm font-semibold text-gray-700">Wave Interval</label>
                    <span id="v-int" class="text-sm font-bold text-orange-500 tabular-nums">1000ms</span>
                </div>
                <input type="range" id="s-int" min="0" max="5000" value="1000" step="100"
                       oninput="document.getElementById('v-int').textContent=(this.value==='0'?'Burst (0ms)':this.value+'ms')">
                <div class="flex justify-between text-[10px] text-gray-300 -mt-0.5">
                    <span>Burst</span><span>500ms</span><span>1s</span><span>3s</span><span>5s</span>
                </div>
                <p class="text-[10px] text-gray-400 mt-1">0 = continuous burst with no cooldown between waves</p>
            </div>

            <!-- Per-request timeout -->
            <div>
                <div class="flex justify-between items-center mb-1.5">
                    <label class="text-sm font-semibold text-gray-700">Request Timeout</label>
                    <span id="v-to" class="text-sm font-bold text-orange-500 tabular-nums">15s</span>
                </div>
                <input type="range" id="s-to" min="3" max="60" value="15" step="1"
                       oninput="document.getElementById('v-to').textContent=this.value+'s'">
            </div>

            <!-- Options -->
            <div class="space-y-3 pt-3 border-t border-gray-100">
                <?php $opts = [
                    ['o-nocache','Cache-bust every request','Appends ?_lt=… to bypass CDN/PHP cache', false],
                    ['o-autoref','Auto-refresh preview',    'Reload preview iframe every 15s', true],
                ]; foreach ($opts as [$id,$lbl,$hint,$def]): ?>
                <label class="flex items-center justify-between gap-3 cursor-pointer">
                    <div>
                        <p class="text-sm font-medium text-gray-700"><?= $lbl ?></p>
                        <p class="text-[10px] text-gray-400"><?= $hint ?></p>
                    </div>
                    <label class="lt-toggle">
                        <input type="checkbox" id="<?= $id ?>" <?= $def ? 'checked' : '' ?>>
                        <span class="lt-slider-pill"></span>
                    </label>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- DANGER ZONE — unlimited load -->
    <div class="lt-danger">
        <p class="lt-danger-title">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            Danger Zone — Unlimited Load
        </p>
        <p class="text-xs text-red-700 mt-1 mb-4">These settings can crash or temporarily overload your server. Use only when you deliberately want to find the breaking point.</p>
        <div class="space-y-4">
            <div>
                <div class="flex justify-between items-center mb-1">
                    <label class="text-sm font-semibold text-red-800">Override Concurrency</label>
                    <span id="v-danger" class="text-sm font-bold text-red-600 tabular-nums w-16 text-right" id="v-danger">off</span>
                </div>
                <input type="range" id="s-danger" min="0" max="500" value="0" step="10"
                       style="accent-color:#ef4444"
                       oninput="ltSyncDanger(this.value)">
                <div class="flex justify-between text-[10px] text-red-300 -mt-0.5">
                    <span>Off</span><span>100</span><span>200</span><span>350</span><span>500</span>
                </div>
                <p class="text-[10px] text-red-400 mt-1">0 = use normal slider. Any value here overrides it.</p>
            </div>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-red-800">Continuous Burst Mode</p>
                    <p class="text-[10px] text-red-400">Fire waves non-stop with zero interval until stopped</p>
                </div>
                <label class="lt-toggle">
                    <input type="checkbox" id="o-burst" style="accent-color:#ef4444">
                    <span class="lt-slider-pill" style=""></span>
                </label>
            </div>
        </div>
    </div>

    <!-- Per-URL breakdown -->
    <div class="lt-card">
        <p class="lt-stitle">Per-URL Stats</p>
        <div id="lt-breakdown"><p class="text-xs text-gray-300 italic">Run a test to see per-URL data.</p></div>
    </div>

</div><!-- /left -->

<!-- ════ RIGHT ════ -->
<div class="xl:col-span-2 space-y-5">

    <!-- Live stats grid -->
    <div class="lt-card">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
            <p class="lt-stitle mb-0">Live Performance</p>
            <div class="flex items-center gap-2">
                <div id="lt-grade-circle" class="lt-grade-circle bg-gray-100 text-gray-300 text-2xl">—</div>
                <div>
                    <p class="text-xs text-gray-400 font-semibold">Performance</p>
                    <p class="text-xs text-gray-400 font-semibold">Grade</p>
                </div>
            </div>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
            <div class="lt-stat bg-blue-50"><div class="lt-stat-val text-blue-600" id="st-total">0</div><div class="lt-stat-lbl text-blue-500">Requests</div></div>
            <div class="lt-stat bg-green-50"><div class="lt-stat-val text-green-600" id="st-ok">0</div><div class="lt-stat-lbl text-green-600">Successful</div></div>
            <div class="lt-stat bg-red-50"><div class="lt-stat-val text-red-500" id="st-err">0</div><div class="lt-stat-lbl text-red-500">Failed</div></div>
            <div class="lt-stat bg-orange-50"><div class="lt-stat-val text-orange-500" id="st-rps">0</div><div class="lt-stat-lbl text-orange-500">Req / sec</div></div>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-2 text-sm">
            <div class="flex flex-col"><span class="text-gray-400 text-xs">Avg response</span><span class="font-bold text-gray-800 tabular-nums" id="st-avg">—</span></div>
            <div class="flex flex-col"><span class="text-gray-400 text-xs">P95 response</span><span class="font-bold text-purple-600 tabular-nums" id="st-p95">—</span></div>
            <div class="flex flex-col"><span class="text-gray-400 text-xs">P99 response</span><span class="font-bold text-red-400 tabular-nums" id="st-p99">—</span></div>
            <div class="flex flex-col"><span class="text-gray-400 text-xs">Success rate</span><span class="font-bold tabular-nums" id="st-rate">—</span></div>
            <div class="flex flex-col"><span class="text-gray-400 text-xs">Fastest</span><span class="font-bold text-green-600 tabular-nums" id="st-min">—</span></div>
            <div class="flex flex-col"><span class="text-gray-400 text-xs">Slowest</span><span class="font-bold text-red-500 tabular-nums" id="st-max">—</span></div>
            <div class="flex flex-col"><span class="text-gray-400 text-xs">Avg TTFB</span><span class="font-bold text-blue-500 tabular-nums" id="st-ttfb">—</span></div>
            <div class="flex flex-col"><span class="text-gray-400 text-xs">Server load</span><span class="font-bold text-gray-600 tabular-nums" id="st-load">—</span></div>
        </div>
        <!-- Progress bar -->
        <div class="mt-4">
            <div class="flex justify-between text-xs text-gray-400 mb-1.5">
                <span id="st-prog-lbl">Ready</span>
                <span id="st-prog-pct">0%</span>
            </div>
            <div class="lt-prog-wrap"><div id="st-prog-bar" class="lt-prog-bar bg-orange-500" style="width:0%"></div></div>
        </div>
    </div>

    <!-- Chart -->
    <div class="lt-card">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <p class="lt-stitle mb-0">Response Time (ms) — Real Server Measurements</p>
            <div class="flex gap-3 text-[11px] text-gray-400 flex-wrap">
                <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-orange-400 inline-block"></span>Avg</span>
                <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-purple-400 inline-block"></span>P95</span>
                <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>Max</span>
            </div>
        </div>
        <div style="position:relative;height:200px"><canvas id="lt-chart"></canvas></div>
    </div>

    <!-- Load intensity meter -->
    <div class="lt-card">
        <div class="flex items-end gap-5">
            <div class="flex-1">
                <p class="lt-stitle">Load Intensity</p>
                <div class="flex items-end gap-[2px] h-16" id="lt-bars">
                    <?php for($i=0;$i<60;$i++): ?>
                    <div class="lt-bar bg-gray-100" style="height:15%"></div>
                    <?php endfor; ?>
                </div>
                <div class="flex justify-between text-[10px] text-gray-300 mt-1">
                    <span>Idle</span><span>Light</span><span>Moderate</span><span>Heavy</span><span>🔴 Critical</span>
                </div>
            </div>
            <div class="flex-shrink-0 text-right pb-1">
                <p class="text-[10px] text-gray-400 font-semibold uppercase tracking-wider mb-1">RPS</p>
                <p class="text-2xl font-black text-gray-800 tabular-nums" id="st-rps2">0</p>
            </div>
        </div>
        <div id="lt-verdict" class="mt-4 p-3 bg-gray-50 rounded-xl text-sm text-gray-400 italic">Verdict appears after test completes.</div>
    </div>

    <!-- Request log -->
    <div class="lt-card">
        <div class="flex items-center justify-between mb-3">
            <p class="lt-stitle mb-0">Server Request Log <span class="font-normal normal-case text-gray-400">(real HTTP responses)</span></p>
            <div class="flex items-center gap-3">
                <span class="text-[10px] text-gray-300" id="lt-log-count">0</span>
                <button onclick="document.getElementById('lt-log').innerHTML='';_lc=0;" class="text-xs text-gray-400 hover:text-gray-700">Clear</button>
            </div>
        </div>
        <div id="lt-log"><span class="info">Waiting to start…</span></div>
    </div>

    <!-- Live preview -->
    <div class="lt-card">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <p class="lt-stitle mb-0">Live Preview — See the site under load</p>
            <div class="flex gap-2 items-center">
                <select id="lt-prev-sel" class="border border-gray-200 rounded-lg px-3 py-1.5 text-xs outline-none focus:ring-2 focus:ring-orange-300">
                    <?php foreach ($defaultTargets as $t): ?>
                    <option value="<?= e($siteUrl.$t['path']) ?>"><?= e($t['label']) ?> — <?= e($t['path']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button onclick="ltLoadPreview()" class="text-xs bg-orange-500 hover:bg-orange-600 text-white px-3 py-1.5 rounded-lg font-medium transition">Reload</button>
            </div>
        </div>
        <div class="relative rounded-xl overflow-hidden border border-gray-100" style="min-height:100px">
            <div id="lt-prev-ov" class="absolute inset-0 bg-white/90 flex flex-col items-center justify-center gap-3 z-10">
                <div class="lt-spin"></div>
                <p class="text-xs text-gray-400">Loading preview…</p>
            </div>
            <iframe id="lt-frame" src="about:blank" sandbox="allow-same-origin allow-scripts allow-forms allow-popups"></iframe>
        </div>
        <div class="flex flex-wrap gap-5 mt-3 text-xs text-gray-400">
            <span>Load time: <strong id="lt-prev-time" class="text-gray-700">—</strong></span>
            <span>URL: <strong id="lt-prev-url" class="text-gray-700 font-mono"><?= e($siteUrl) ?>/</strong></span>
            <span>Status: <strong id="lt-prev-status" class="text-gray-700">—</strong></span>
        </div>
    </div>

</div><!-- /right -->
</div><!-- /grid -->
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
'use strict';
// ═══════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════
var SITE    = '<?= e($siteUrl) ?>';
var API_URL = '<?= e($siteUrl) ?>/admin/api/load-test-run.php';

// ── Chart ──────────────────────────────────────────────────────
var ltChart = new Chart(document.getElementById('lt-chart').getContext('2d'), {
    type: 'line',
    data: {
        labels: [],
        datasets: [
            { label:'Avg (ms)',  data:[], borderColor:'#f97316', backgroundColor:'rgba(249,115,22,.12)', borderWidth:2.5, tension:.4, fill:true,  pointRadius:2 },
            { label:'P95 (ms)', data:[], borderColor:'#a855f7', backgroundColor:'transparent',           borderWidth:2,   tension:.4, fill:false, pointRadius:0, borderDash:[5,4] },
            { label:'Max (ms)', data:[], borderColor:'#ef4444', backgroundColor:'transparent',           borderWidth:1.5, tension:.4, fill:false, pointRadius:0, borderDash:[3,3] },
        ]
    },
    options: {
        responsive:true, maintainAspectRatio:false, animation:{ duration:200 },
        interaction:{ mode:'index', intersect:false },
        plugins:{ legend:{ position:'bottom', labels:{ boxWidth:12, font:{ size:11 } } },
                  tooltip:{ callbacks:{ label: c => c.dataset.label+': '+c.parsed.y+'ms' } } },
        scales:{
            x:{ grid:{ color:'#f1f5f9' }, ticks:{ color:'#9ca3af', font:{ size:10 }, maxTicksLimit:12 } },
            y:{ grid:{ color:'#f1f5f9' }, ticks:{ color:'#9ca3af', font:{ size:10 } }, min:0, suggestedMax:1000 }
        }
    }
});

// ═══════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════
var _es = null;         // EventSource
var _running = false;
var _buf = [];          // chart buffer
var _byUrl = {};        // per-url stats from server
var _prevTimer = null;
var _lc = 0;            // log count
var _ttfbSum = 0, _ttfbN = 0;
var _totalReqs = 0, _totalOk = 0;

// ═══════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════
function ms(n){ if(!n && n!==0) return '—'; return n < 1000 ? n+'ms' : (n/1000).toFixed(2)+'s'; }
function set(id,v){ var e=document.getElementById(id); if(e) e.textContent=v; }

function gradeInfo(avg, sr) {
    if (sr < 70)    return { g:'F',  bg:'#fee2e2', fg:'#dc2626', v:'🔴 Critical — server is failing to respond. Too much load.' };
    if (sr < 85)    return { g:'D',  bg:'#fee2e2', fg:'#ef4444', v:'🔴 Very high failure rate. Server is overloaded.' };
    if (avg > 3000) return { g:'D',  bg:'#fee2e2', fg:'#ef4444', v:'🔴 Extremely slow. Server is struggling badly.' };
    if (avg > 1500) return { g:'C',  bg:'#fef3c7', fg:'#d97706', v:'🟠 Slow responses under this load. Add caching or scale up.' };
    if (avg > 700)  return { g:'B',  bg:'#fefce8', fg:'#ca8a04', v:'🟡 Acceptable but noticeable slowdown. Monitor carefully.' };
    if (avg > 300)  return { g:'A',  bg:'#d1fae5', fg:'#059669', v:'🟢 Good performance. Site handles this load well.' };
    return                  { g:'A+', bg:'#d1fae5', fg:'#047857', v:'🟢 Excellent! Site is fast even under this load.' };
}

function setBadge(txt, cls) {
    var b = document.getElementById('lt-badge');
    b.className = 'lt-badge lt-'+cls;
    var dots = { idle:'#9ca3af', running:'#f59e0b', done:'#10b981' };
    document.getElementById('lt-dot').style.background = dots[cls] || '#9ca3af';
    set('lt-badge-txt', txt);
}

function ltLog(msg, cls) {
    var log = document.getElementById('lt-log');
    if (_lc === 0) log.innerHTML = '';
    var t = new Date().toLocaleTimeString('en-GB', { hour12:false });
    var d = document.createElement('div');
    if (cls) d.className = cls;
    d.textContent = '['+t+'] '+msg;
    log.appendChild(d);
    // Keep newest visible
    log.scrollTop = log.scrollHeight;
    // Max 400 lines
    while (log.children.length > 400) log.removeChild(log.firstChild);
    _lc++;
    set('lt-log-count', _lc+' entries');
}

function ltAnimateBars(frac) {
    var bars = document.querySelectorAll('#lt-bars .lt-bar');
    var lf = Math.min(1, Math.max(0, frac));
    bars.forEach((b, i) => {
        var th = i / bars.length, active = th < lf;
        b.style.height = (active ? (30 + Math.random()*70) : (4 + Math.random()*10)) + '%';
        b.style.background = active
            ? (lf < .3 ? '#4ade80' : lf < .55 ? '#facc15' : lf < .8 ? '#fb923c' : '#f87171')
            : '#f1f5f9';
    });
}

function ltSyncUsers(v) {
    set('v-users', v);
    // reset danger if lower than danger
    if (parseInt(document.getElementById('s-danger').value) > 0 &&
        parseInt(v) > parseInt(document.getElementById('s-danger').value)) {
        document.getElementById('s-danger').value = 0;
        set('v-danger', 'off');
    }
}

function ltSyncDanger(v) {
    set('v-danger', v === '0' ? 'off' : v);
    if (v > 0) { set('v-users', v); }
}

// ═══════════════════════════════════════════════════════════════
// START
// ═══════════════════════════════════════════════════════════════
function ltStart() {
    if (_running) return;

    var urls = [...document.querySelectorAll('.lt-cb:checked')].map(c => c.value);
    if (!urls.length) { alert('Select at least one target URL.'); return; }

    // Which concurrency to use
    var dangerVal = parseInt(document.getElementById('s-danger').value);
    var concurrency = dangerVal > 0 ? dangerVal : parseInt(document.getElementById('s-users').value);
    var duration   = parseInt(document.getElementById('s-dur').value);
    var interval   = document.getElementById('o-burst').checked ? 0 : parseInt(document.getElementById('s-int').value);
    var timeout    = parseInt(document.getElementById('s-to').value);
    var nocache    = document.getElementById('o-nocache').checked ? '1' : '0';
    var burst      = (interval === 0) ? '1' : '0';

    // Reset UI
    _running = true; _buf = []; _byUrl = {}; _lc = 0; _ttfbSum = 0; _ttfbN = 0;
    _totalReqs = 0; _totalOk = 0;
    ltChart.data.labels = []; ltChart.data.datasets.forEach(d => d.data = []);
    ltChart.update();
    document.getElementById('lt-run-btn').style.display = 'none';
    document.getElementById('lt-stop-btn').style.display = 'flex';
    document.getElementById('lt-verdict').innerHTML = '<span class="italic text-gray-400">Test running…</span>';
    setBadge('Running…', 'running');

    // Stats reset
    ['st-total','st-ok','st-err','st-rps','st-rps2','st-avg','st-p95','st-p99','st-min','st-max','st-ttfb'].forEach(id => set(id,'—'));
    set('st-total', 0); set('st-ok', 0); set('st-err', 0); set('st-rps', 0); set('st-rps2', 0);
    document.getElementById('st-prog-bar').style.width = '0%';
    set('st-prog-pct', '0%');
    set('st-prog-lbl', 'Running…');

    // Build SSE URL
    var params = new URLSearchParams({
        urls:        urls.join(','),
        concurrency: concurrency,
        duration:    duration,
        interval:    interval,
        timeout:     timeout,
        nocache:     nocache,
        burst:       burst,
    });
    var sseUrl = API_URL + '?' + params.toString();

    ltLog('▶ Real cURL load test starting…', 'info');
    ltLog('  Concurrency: '+concurrency+' parallel requests | Duration: '+duration+'s | Interval: '+(interval?interval+'ms':'burst'), 'info');
    ltLog('  Targets: '+urls.map(u=>u.replace(/^https?:\/\/[^/]+/,'')||'/').join(', '), 'info');

    // Auto-refresh preview
    if (document.getElementById('o-autoref').checked) {
        _prevTimer = setInterval(() => { if (_running) ltLoadPreview(true); }, 15000);
    }

    // Open SSE stream
    _es = new EventSource(sseUrl);

    _es.addEventListener('start', e => {
        var d = JSON.parse(e.data);
        ltLog('  Server confirmed: '+d.concurrency+' concurrent cURL handles ready', 'info');
    });

    _es.addEventListener('wave', e => {
        var d = JSON.parse(e.data);

        // Progress
        document.getElementById('st-prog-bar').style.width = d.progress + '%';
        set('st-prog-pct', d.progress + '%');
        set('st-prog-lbl', d.remaining + 's remaining · wave #' + d.wave);

        // Counters
        _totalReqs = d.total; _totalOk = d.ok;
        set('st-total', d.total);
        set('st-ok',    d.ok);
        set('st-err',   d.err);
        set('st-rps',   d.rps);
        set('st-rps2',  d.rps);
        set('st-avg',   ms(d.avg));
        set('st-p95',   ms(d.p95));
        set('st-p99',   ms(d.p99));
        set('st-min',   ms(d.minMs));
        set('st-max',   ms(d.maxMs));

        // TTFB
        if (d.waveAvgTtfb) {
            _ttfbSum += d.waveAvgTtfb; _ttfbN++;
            set('st-ttfb', ms(Math.round(_ttfbSum / _ttfbN)));
        }

        // Server load
        if (d.load1 !== null) set('st-load', d.load1);
        if (d.memMB)          set('st-load', d.load1 !== null ? d.load1 + ' / ' + d.memMB + 'MB' : d.memMB + 'MB');

        // Success rate + grade
        var sr = d.total ? Math.round(d.ok / d.total * 100) : 100;
        var re = document.getElementById('st-rate');
        re.textContent = sr + '%';
        re.className = 'font-bold tabular-nums ' + (sr >= 95 ? 'text-green-600' : sr >= 80 ? 'text-yellow-600' : 'text-red-500');
        var gi = gradeInfo(d.avg, sr);
        var gc = document.getElementById('lt-grade-circle');
        gc.textContent = gi.g;
        gc.style.background = gi.bg;
        gc.style.color = gi.fg;

        // Chart
        _buf.push({ avg: d.waveAvg, p95: d.p95, max: d.waveMax });
        if (_buf.length > 100) _buf.shift();
        ltChart.data.labels = _buf.map((_, i) => i + 's');
        ltChart.data.datasets[0].data = _buf.map(b => b.avg);
        ltChart.data.datasets[1].data = _buf.map(b => b.p95);
        ltChart.data.datasets[2].data = _buf.map(b => b.max);
        ltChart.update('none');

        // Load bars
        var maxRps = concurrency; // theoretical max
        ltAnimateBars(d.rps / maxRps);

        // Accumulate per-URL stats from wave requests
        if (d.requests) {
            d.requests.forEach(r => {
                var path = r.url.replace(/^https?:\/\/[^/]+/, '') || '/';
                if (!_byUrl[path]) _byUrl[path] = { total:0, ok:0, err:0, times:[], codes:{} };
                _byUrl[path].total++;
                _byUrl[path].times.push(r.ms);
                if (r.ok) _byUrl[path].ok++;
                else       _byUrl[path].err++;
                var cg = Math.floor(r.code / 100) + 'xx';
                _byUrl[path].codes[cg] = (_byUrl[path].codes[cg] || 0) + 1;
                // Log individual requests (first 5 per wave to avoid spam)
            });
            // Log wave summary
            var wok = d.waveOk, werr = d.waveErr, wcnt = d.waveCount;
            var cls = werr > wcnt * .2 ? 'err' : d.waveAvg > 2000 ? 'slow' : d.waveAvg > 500 ? 'slow' : 'ok';
            ltLog('Wave #'+d.wave+' · '+wcnt+' req · '+wok+' ok '+werr+' err · avg '+d.waveAvg+'ms · max '+d.waveMax+'ms · TTFB ~'+d.waveAvgTtfb+'ms', cls);
        }

        // Per-URL breakdown render
        var bd = document.getElementById('lt-breakdown');
        bd.innerHTML = '';
        Object.entries(_byUrl).forEach(([path, u]) => {
            var uavg = u.times.length ? Math.round(u.times.reduce((a,b)=>a+b,0)/u.times.length) : 0;
            var urate = Math.round(u.ok / u.total * 100);
            var codes = Object.entries(u.codes).map(([c,n])=>'<span class="px-1.5 py-0.5 rounded text-[10px] '+(c.startsWith('2')?'bg-green-50 text-green-700':'bg-red-50 text-red-600')+'">'+c+': '+n+'</span>').join(' ');
            bd.innerHTML += '<div class="pb-3 mb-3 border-b border-gray-100 last:border-0 last:mb-0 last:pb-0">'
                +'<div class="flex justify-between mb-1"><span class="text-sm font-semibold text-gray-700 truncate max-w-[60%]" title="'+path+'">'+path+'</span>'
                +'<span class="text-xs font-bold '+(urate>=95?'text-green-600':urate>=80?'text-yellow-600':'text-red-500')+'">'+urate+'%</span></div>'
                +'<div class="flex gap-3 text-xs text-gray-400 mb-1.5 flex-wrap"><span>'+u.total+' req</span><span>avg '+ms(uavg)+'</span><span>'+u.ok+' ok</span><span>'+u.err+' err</span></div>'
                +'<div class="flex gap-1 flex-wrap mb-1.5">'+codes+'</div>'
                +'<div class="lt-prog-wrap"><div class="lt-prog-bar" style="width:'+Math.min(100,uavg/25)+'%;background:'+(uavg<500?'#4ade80':uavg<2000?'#facc15':'#f87171')+'"></div></div>'
                +'</div>';
        });
        if (!Object.keys(_byUrl).length) bd.innerHTML = '<p class="text-xs text-gray-300 italic">No data yet.</p>';
    });

    _es.addEventListener('done', e => {
        var d = JSON.parse(e.data);
        var sr = d.sr;
        var gi = gradeInfo(d.avg, sr);
        document.getElementById('lt-verdict').innerHTML =
            '<div class="font-semibold text-gray-800 mb-1">'+gi.v+'</div>'
            +'<div class="flex flex-wrap gap-3 text-xs text-gray-500">'
            +'<span>'+d.total+' requests</span><span>'+d.waves+' waves</span>'
            +'<span>'+sr+'% success</span><span>'+d.rps+' req/s</span>'
            +'<span>avg '+ms(d.avg)+'</span><span>P95 '+ms(d.p95)+'</span>'
            +'<span>P99 '+ms(d.p99)+'</span><span>max '+ms(d.max)+'</span>'
            +'<span class="font-bold">Grade: '+gi.g+'</span>'
            +'</div>';
        ltLog('■ Test complete · '+d.total+' req · '+sr+'% ok · avg '+ms(d.avg)+' · P95 '+ms(d.p95)+' · P99 '+ms(d.p99)+' · Grade: '+gi.g, 'info');
        ltCleanup(true);
    });

    _es.onerror = err => {
        ltLog('⚠ SSE connection error or server closed stream', 'warn');
        ltCleanup(false);
    };

    // Load preview immediately
    ltLoadPreview(true);
}

// ═══════════════════════════════════════════════════════════════
// STOP / CLEANUP
// ═══════════════════════════════════════════════════════════════
function ltStop() {
    ltLog('■ Stopped by user', 'warn');
    ltCleanup(false);
}

function ltCleanup(done) {
    _running = false;
    if (_es) { _es.close(); _es = null; }
    clearInterval(_prevTimer); _prevTimer = null;
    document.getElementById('lt-run-btn').style.display = 'flex';
    document.getElementById('lt-stop-btn').style.display = 'none';
    document.getElementById('st-prog-bar').style.width = '100%';
    set('st-prog-pct', '100%');
    set('st-prog-lbl', done ? 'Complete' : 'Stopped');
    setBadge(done ? 'Done' : 'Stopped', 'done');
}

// ═══════════════════════════════════════════════════════════════
// CUSTOM URL
// ═══════════════════════════════════════════════════════════════
function ltAddUrl() {
    var inp = document.getElementById('lt-custom');
    var raw = inp.value.trim();
    if (!raw) return;
    var url = raw.startsWith('http') ? raw : SITE + '/' + raw.replace(/^\//, '');
    var path = url.replace(/^https?:\/\/[^/]+/, '') || '/';
    var lbl = document.createElement('label');
    lbl.className = 'flex items-center gap-2.5 text-sm cursor-pointer';
    lbl.innerHTML = '<input type="checkbox" class="lt-cb accent-orange-500 w-4 h-4 rounded" value="'+url+'" checked>'
        +'<span class="text-gray-700 font-medium">'+path+'</span>'
        +'<span class="text-gray-300 text-xs ml-auto font-mono">custom</span>';
    document.getElementById('lt-targets').appendChild(lbl);
    var opt = document.createElement('option');
    opt.value = url; opt.textContent = path + ' (custom)';
    document.getElementById('lt-prev-sel').appendChild(opt);
    inp.value = '';
}

// ═══════════════════════════════════════════════════════════════
// PREVIEW
// ═══════════════════════════════════════════════════════════════
var _pt0 = 0;
function ltLoadPreview(silent) {
    var url = document.getElementById('lt-prev-sel').value;
    var frame = document.getElementById('lt-frame');
    var ov    = document.getElementById('lt-prev-ov');
    _pt0 = performance.now();
    set('lt-prev-url', url);
    if (!silent) ov.style.display = 'flex';
    frame.src = url + '?_ltpv=' + Date.now();
    frame.onload = function() {
        var t = Math.round(performance.now() - _pt0);
        ov.style.display = 'none';
        set('lt-prev-time', ms(t));
        set('lt-prev-status', t < 500 ? '✅ Fast' : t < 2000 ? '⚠️ Slow' : '🔴 Very slow');
    };
}

// Load on page open
window.addEventListener('DOMContentLoaded', () => ltLoadPreview(false));

// ═══════════════════════════════════════════════════════════════
// SITE CAPACITY BENCHMARK
// ═══════════════════════════════════════════════════════════════
var CAP_URL = '<?= e($siteUrl) ?>/admin/api/server-capacity.php';
var _capEs  = null;

var GRADE_COLORS = {
    excellent: { bar:'#10b981', pill:'grade-excellent', label:'Excellent' },
    good:      { bar:'#3b82f6', pill:'grade-good',      label:'Good' },
    stressed:  { bar:'#f59e0b', pill:'grade-stressed',  label:'Stressed' },
    degraded:  { bar:'#f97316', pill:'grade-degraded',  label:'Degraded' },
    overloaded:{ bar:'#ef4444', pill:'grade-overloaded',label:'Overloaded' },
    breaking:  { bar:'#7f1d1d', pill:'grade-breaking',  label:'Breaking' },
};

function ltRunCapacity() {
    if (_capEs) { _capEs.close(); _capEs = null; }

    document.getElementById('cap-idle').style.display    = 'none';
    document.getElementById('cap-running').style.display = 'block';
    document.getElementById('cap-results').style.display = 'none';
    document.getElementById('cap-live-table').innerHTML  = '';
    document.getElementById('cap-btn').disabled = true;
    document.getElementById('cap-btn').innerHTML =
        '<div class="lt-spin" style="width:16px;height:16px;border-width:2px"></div> Running…';

    var _rows = {};

    _capEs = new EventSource(CAP_URL + '?_t=' + Date.now());

    _capEs.addEventListener('start', e => {
        var d = JSON.parse(e.data);
        set('cap-run-msg', 'Testing ' + d.levels.join(' → ') + ' concurrent…');
    });

    _capEs.addEventListener('testing', e => {
        var d = JSON.parse(e.data);
        set('cap-run-msg', d.msg);
        set('cap-run-pct', d.pct + '%');
        document.getElementById('cap-prog').style.width = d.pct + '%';
        // Add pending row
        if (!_rows[d.level]) {
            _rows[d.level] = true;
            var tbl = document.getElementById('cap-live-table');
            var row = document.createElement('div');
            row.id  = 'cap-row-' + d.level;
            row.className = 'flex items-center gap-3 text-xs py-1.5 border-b border-gray-50';
            row.innerHTML =
                '<span class="w-16 text-right font-mono text-gray-500 flex-shrink-0">'+d.level+' users</span>'
                +'<div class="flex-1"><div class="cap-meter-wrap"><div class="cap-meter-bar bg-gray-200 animate-pulse" style="width:100%"></div></div></div>'
                +'<span class="w-20 text-right text-gray-300 italic flex-shrink-0">testing…</span>';
            tbl.appendChild(row);
        }
    });

    _capEs.addEventListener('level', e => {
        var d = JSON.parse(e.data);
        var gc = GRADE_COLORS[d.grade] || GRADE_COLORS.good;
        // Update progress
        set('cap-run-msg', 'Tested ' + d.level + ' concurrent — avg ' + d.allAvg + 'ms, ' + d.sr + '% ok');
        set('cap-run-pct', d.pct + '%');
        document.getElementById('cap-prog').style.width = d.pct + '%';
        // Update live row
        var row = document.getElementById('cap-row-' + d.level);
        if (row) {
            var barW = Math.min(100, Math.round(d.allAvg / 30));
            row.innerHTML =
                '<span class="w-16 text-right font-mono text-gray-600 font-semibold flex-shrink-0">'+d.level+' users</span>'
                +'<div class="flex-1"><div class="cap-meter-wrap" style="height:10px"><div style="width:'+barW+'%;background:'+gc.bar+';height:100%;border-radius:9px;transition:width .5s"></div></div></div>'
                +'<span class="w-16 text-right font-bold tabular-nums flex-shrink-0" style="color:'+gc.bar+'">'+d.allAvg+'ms</span>'
                +'<span class="cap-grade-pill '+gc.pill+' flex-shrink-0">'+gc.label+'</span>'
                +'<span class="w-12 text-right text-gray-400 flex-shrink-0">'+d.sr+'%</span>';
        }
    });

    _capEs.addEventListener('done', e => {
        var d = JSON.parse(e.data);
        _capEs.close(); _capEs = null;
        capShowResults(d);
    });

    _capEs.addEventListener('info', e => {
        var d = JSON.parse(e.data);
        set('cap-run-msg', d.msg);
    });

    _capEs.onerror = () => {
        if (_capEs) _capEs.close(); _capEs = null;
        document.getElementById('cap-running').style.display = 'none';
        document.getElementById('cap-idle').style.display = 'flex';
        document.getElementById('cap-btn').disabled = false;
        document.getElementById('cap-btn').innerHTML =
            '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Run Benchmark';
    };
}

function capShowResults(d) {
    document.getElementById('cap-running').style.display = 'none';
    document.getElementById('cap-results').style.display = 'block';
    document.getElementById('cap-btn').disabled = false;
    document.getElementById('cap-btn').innerHTML =
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Re-run';

    // Big 3 numbers
    set('cap-comfortable', d.comfortable);
    set('cap-degraded',    typeof d.degraded === 'number' ? d.degraded : '—');
    set('cap-breaking',    typeof d.breaking === 'number' ? d.breaking : '✓ None');
    set('cap-rps',         d.estRps + '/s');
    set('cap-base-ms',     d.baseAvg + 'ms');
    set('cap-sustained',   d.sustained);

    // Verdict
    var vt, vs;
    if (typeof d.breaking === 'number' && d.breaking <= 10) {
        vt = '⚠️ Very limited capacity — breaking at only ' + d.breaking + ' concurrent users';
        vs = 'Consider enabling OPcache, increasing PHP workers, or upgrading your hosting plan';
    } else if (typeof d.breaking === 'number' && d.breaking <= 50) {
        vt = '⚡ Moderate capacity — handles light traffic well, struggles at ' + d.breaking + ' concurrent';
        vs = 'Fine for typical e-commerce. Add caching to push the limit further';
    } else if (typeof d.degraded === 'number') {
        vt = '✅ Good capacity — comfortable up to ' + d.comfortable + ' concurrent users';
        vs = 'Site starts slowing at ' + d.degraded + ' concurrent. Breaking point not reached in this test';
    } else {
        vt = '🚀 Excellent capacity — handled all tested levels without breaking';
        vs = 'Max tested: ' + d.sustained + ' concurrent with ≥95% success rate';
    }
    set('cap-verdict-txt', vt);
    set('cap-verdict-sub', vs);

    // Horizontal bar chart
    var maxAvg = Math.max(...d.results.map(r => r.allAvg), 1);
    var maxScale = Math.max(maxAvg, 3000); // 3s = full width
    var chartHtml = d.results.map(r => {
        var gc   = GRADE_COLORS[r.grade] || GRADE_COLORS.good;
        var barW = Math.min(100, Math.round(r.allAvg / maxScale * 100));
        var p95W = Math.min(100, Math.round(r.allP95 / maxScale * 100));
        return '<div class="flex items-center gap-3 group">'
            +'<span class="text-xs font-mono text-gray-500 w-16 text-right flex-shrink-0">'+r.level+' users</span>'
            +'<div class="flex-1 relative" style="height:28px">'
            +  '<div style="position:absolute;inset:0;background:#f8fafc;border-radius:8px"></div>'
            +  '<div style="position:absolute;left:0;top:0;height:100%;width:'+p95W+'%;background:'+gc.bar+'22;border-radius:8px;transition:width .6s"></div>'
            +  '<div style="position:absolute;left:0;top:0;height:100%;width:'+barW+'%;background:'+gc.bar+';border-radius:8px;transition:width .6s;display:flex;align-items:center;padding-left:8px">'
            +    '<span class="text-white text-[11px] font-bold whitespace-nowrap" style="'+(barW<20?'color:'+gc.bar+';position:absolute;left:calc('+barW+'% + 6px)':'')+'">'+r.allAvg+'ms avg</span>'
            +  '</div>'
            +'</div>'
            +'<div class="flex items-center gap-2 flex-shrink-0 w-44">'
            +  '<span class="cap-grade-pill '+gc.pill+'">'+gc.label+'</span>'
            +  '<span class="text-[11px] text-gray-400 tabular-nums">P95: '+r.allP95+'ms</span>'
            +  '<span class="text-[11px] font-semibold '+(r.sr>=97?'text-green-500':r.sr>=90?'text-yellow-500':'text-red-500')+'">'+r.sr+'%</span>'
            +'</div>'
            +'</div>';
    }).join('');
    document.getElementById('cap-chart-rows').innerHTML = chartHtml;

    // Preset apply buttons
    var presets = [
        { label:'Safe',    pct:'Comfortable load',    conc: d.comfortable,                           color:'bg-green-50  border-green-300  text-green-800' },
        { label:'Stress',  pct:'Degradation point',   conc: typeof d.degraded==='number'?d.degraded:d.comfortable*2, color:'bg-yellow-50 border-yellow-300 text-yellow-800' },
        { label:'Spike',   pct:'Breaking point',      conc: typeof d.breaking==='number'?d.breaking:d.sustained,     color:'bg-orange-50 border-orange-300 text-orange-800' },
        { label:'Extreme', pct:'Beyond breaking',     conc: typeof d.breaking==='number'?Math.round(d.breaking*1.5):d.sustained*2, color:'bg-red-50    border-red-300    text-red-800'   },
    ];
    document.getElementById('cap-presets').innerHTML = presets.map(p =>
        '<button class="lt-suggest-btn border '+p.color+'" onclick="ltApplyPreset('+p.conc+')">'
        +'<p class="text-[10px] font-semibold opacity-70">'+p.pct+'</p>'
        +'<p class="text-2xl font-black tabular-nums my-1">'+p.conc+'</p>'
        +'<p class="text-[10px] font-bold">'+p.label+' — Apply ↗</p>'
        +'</button>'
    ).join('');
}

// Apply preset concurrency to test sliders
function ltApplyPreset(concurrency) {
    document.querySelectorAll('.lt-suggest-btn').forEach(b => b.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
    if (concurrency <= 200) {
        document.getElementById('s-users').value = concurrency;
        ltSyncUsers(concurrency);
        document.getElementById('s-danger').value = 0;
        document.getElementById('v-danger').textContent = 'off';
    } else {
        var clamped = Math.min(500, concurrency);
        document.getElementById('s-danger').value = clamped;
        ltSyncDanger(clamped);
    }
    document.getElementById('lt-run-btn').scrollIntoView({ behavior:'smooth', block:'nearest' });
}
</script>