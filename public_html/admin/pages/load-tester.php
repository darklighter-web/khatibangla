<?php
/**
 * Load Tester — Simulate concurrent traffic, measure performance, preview site live
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$pageTitle = 'Load Tester';
$siteUrl   = rtrim(SITE_URL, '/');
$siteLogo  = getSetting('site_logo');

$defaultTargets = [
    ['label' => 'Homepage',  'path' => '/'],
    ['label' => 'Shop',      'path' => '/shop'],
    ['label' => 'Category',  'path' => '/category'],
];

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.lt-card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:22px; }
.lt-stitle { font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.7px; margin-bottom:14px; }
.lt-stat { border-radius:14px; padding:16px 18px; }
.lt-stat-val   { font-size:1.9rem; font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
.lt-stat-lbl   { font-size:10px; font-weight:600; margin-top:5px; opacity:.7; text-transform:uppercase; letter-spacing:.4px; }
.lt-badge { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; padding:4px 12px; border-radius:999px; }
.lt-idle    { background:#f3f4f6; color:#6b7280; }
.lt-running { background:#fef3c7; color:#b45309; }
.lt-done    { background:#d1fae5; color:#047857; }
.lt-prog-wrap { height:7px; background:#f1f5f9; border-radius:9px; overflow:hidden; }
.lt-prog-bar  { height:100%; border-radius:9px; transition:width .35s ease; }
#lt-log { max-height:260px; overflow-y:auto; font-size:11.5px; font-family:'Fira Mono',monospace;
          background:#0d1117; color:#8b949e; border-radius:12px; padding:14px 16px; line-height:1.8; }
#lt-log .ok   { color:#3fb950; }
#lt-log .slow { color:#d29922; }
#lt-log .err  { color:#f85149; }
#lt-log .info { color:#58a6ff; }
input[type=range] { accent-color:#f97316; width:100%; cursor:pointer; }
.lt-toggle { position:relative; display:inline-block; width:44px; height:24px; flex-shrink:0; }
.lt-toggle input { opacity:0; width:0; height:0; }
.lt-slider { position:absolute; inset:0; background:#d1d5db; border-radius:24px; cursor:pointer; transition:.2s; }
.lt-slider:before { content:''; position:absolute; width:18px; height:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.lt-toggle input:checked + .lt-slider { background:#f97316; }
.lt-toggle input:checked + .lt-slider:before { transform:translateX(20px); }
.lt-bar { flex:1; min-width:0; border-radius:3px 3px 0 0; transition:height .25s ease, background .25s ease; }
.lt-grade-circle { font-size:1.5rem; font-weight:900; width:54px; height:54px; border-radius:50%;
                   display:flex; align-items:center; justify-content:center; }
#lt-frame { width:100%; border:none; border-radius:12px; height:490px; display:block; }
.lt-spin { width:26px; height:26px; border:3px solid #e5e7eb; border-top-color:#f97316; border-radius:50%; animation:ltSpin .7s linear infinite; }
@keyframes ltSpin { to { transform:rotate(360deg); } }
</style>

<div class="space-y-5">

<!-- Warning -->
<div class="flex gap-3 items-start bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 text-sm">
    <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
    <div><strong>Simulator only.</strong> Requests fire from your browser — not from real distributed IPs. Results show your server's response time accurately, but don't replicate true distributed load. Keep concurrent users ≤ 20 to avoid stressing a live server. <strong>Run during low-traffic hours for clean results.</strong></div>
</div>

<!-- Header -->
<div class="flex items-center justify-between flex-wrap gap-3">
    <div>
        <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <svg class="w-6 h-6 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            Load Tester
        </h1>
        <p class="text-sm text-gray-400 mt-0.5">Simulate traffic bursts · measure response times · preview site live</p>
    </div>
    <div class="flex items-center gap-3">
        <span id="lt-badge" class="lt-badge lt-idle">
            <span id="lt-dot" class="w-2 h-2 rounded-full bg-gray-400 inline-block"></span>
            <span id="lt-badge-txt">Idle</span>
        </span>
        <button id="lt-run-btn" onclick="ltStart()" class="bg-orange-500 hover:bg-orange-600 text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition shadow-sm flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 3l14 9-14 9V3z"/></svg>
            Run Test
        </button>
        <button id="lt-stop-btn" onclick="ltStop(false)" style="display:none" class="bg-red-500 hover:bg-red-600 text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition shadow-sm flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6h12v12H6z"/></svg>
            Stop
        </button>
    </div>
</div>

<!-- Grid -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

<!-- ══ LEFT ══ -->
<div class="space-y-5">

<!-- Config -->
<div class="lt-card">
    <p class="lt-stitle">Configuration</p>
    <div class="space-y-5">

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

        <div>
            <div class="flex justify-between items-center mb-1.5">
                <label class="text-sm font-semibold text-gray-700">Concurrent Users</label>
                <span id="v-users" class="text-sm font-bold text-orange-500 tabular-nums">10</span>
            </div>
            <input type="range" id="s-users" min="1" max="50" value="10" oninput="document.getElementById('v-users').textContent=this.value">
            <div class="flex justify-between text-[10px] text-gray-300 -mt-0.5"><span>1</span><span>10</span><span>25</span><span>50</span></div>
        </div>

        <div>
            <div class="flex justify-between items-center mb-1.5">
                <label class="text-sm font-semibold text-gray-700">Test Duration</label>
                <span id="v-dur" class="text-sm font-bold text-orange-500 tabular-nums">20s</span>
            </div>
            <input type="range" id="s-dur" min="5" max="120" value="20" step="5" oninput="document.getElementById('v-dur').textContent=this.value+'s'">
            <div class="flex justify-between text-[10px] text-gray-300 -mt-0.5"><span>5s</span><span>30s</span><span>60s</span><span>120s</span></div>
        </div>

        <div>
            <div class="flex justify-between items-center mb-1.5">
                <label class="text-sm font-semibold text-gray-700">Ramp-up Period</label>
                <span id="v-ramp" class="text-sm font-bold text-orange-500 tabular-nums">3s</span>
            </div>
            <input type="range" id="s-ramp" min="0" max="20" value="3" step="1" oninput="document.getElementById('v-ramp').textContent=this.value+'s'">
            <p class="text-[10px] text-gray-300 mt-1">Gradually increase load over this time instead of spiking instantly</p>
        </div>

        <div>
            <div class="flex justify-between items-center mb-1.5">
                <label class="text-sm font-semibold text-gray-700">Wave Interval</label>
                <span id="v-int" class="text-sm font-bold text-orange-500 tabular-nums">1000ms</span>
            </div>
            <input type="range" id="s-int" min="200" max="5000" value="1000" step="200" oninput="document.getElementById('v-int').textContent=this.value+'ms'">
            <p class="text-[10px] text-gray-300 mt-1">Delay between each wave of concurrent requests</p>
        </div>

        <div class="space-y-3 pt-3 border-t border-gray-100">
            <?php
            $opts = [
                ['o-rand',    'Randomise targets',    'Pick a random URL each wave', true],
                ['o-bust',    'Cache-bust requests',  'Adds ?_t=… to bypass CDN cache', false],
                ['o-autoref', 'Auto-refresh preview', 'Reload preview every 10s during test', true],
            ];
            foreach ($opts as [$id,$lbl,$hint,$def]): ?>
            <label class="flex items-center justify-between gap-3 cursor-pointer">
                <div>
                    <p class="text-sm font-medium text-gray-700"><?= $lbl ?></p>
                    <p class="text-[10px] text-gray-400"><?= $hint ?></p>
                </div>
                <label class="lt-toggle">
                    <input type="checkbox" id="<?= $id ?>" <?= $def ? 'checked' : '' ?>>
                    <span class="lt-slider"></span>
                </label>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Live stats -->
<div class="lt-card">
    <p class="lt-stitle">Live Stats</p>
    <div class="grid grid-cols-2 gap-3 mb-5">
        <div class="lt-stat bg-blue-50"><div class="lt-stat-val text-blue-600" id="st-total">0</div><div class="lt-stat-lbl text-blue-500">Requests</div></div>
        <div class="lt-stat bg-green-50"><div class="lt-stat-val text-green-600" id="st-ok">0</div><div class="lt-stat-lbl text-green-600">Successful</div></div>
        <div class="lt-stat bg-red-50"><div class="lt-stat-val text-red-500" id="st-err">0</div><div class="lt-stat-lbl text-red-500">Failed</div></div>
        <div class="lt-stat bg-orange-50"><div class="lt-stat-val text-orange-500" id="st-rps">0</div><div class="lt-stat-lbl text-orange-500">Req / sec</div></div>
    </div>
    <div class="space-y-2.5 text-sm">
        <div class="flex justify-between items-center"><span class="text-gray-400">Avg response</span><span class="font-bold text-gray-800" id="st-avg">—</span></div>
        <div class="flex justify-between items-center"><span class="text-gray-400">P95 response</span><span class="font-bold text-purple-600" id="st-p95">—</span></div>
        <div class="flex justify-between items-center"><span class="text-gray-400">Fastest</span><span class="font-bold text-green-600" id="st-min">—</span></div>
        <div class="flex justify-between items-center"><span class="text-gray-400">Slowest</span><span class="font-bold text-red-500" id="st-max">—</span></div>
        <div class="flex justify-between items-center"><span class="text-gray-400">Success rate</span><span class="font-bold" id="st-rate">—</span></div>
        <div class="flex justify-between items-center pt-2 border-t border-gray-100">
            <span class="text-gray-500 font-semibold">Performance Grade</span>
            <span class="font-black text-2xl" id="st-grade">—</span>
        </div>
    </div>
    <div class="mt-5">
        <div class="flex justify-between text-xs text-gray-400 mb-1.5">
            <span id="st-prog-lbl">Ready</span>
            <span id="st-prog-pct">0%</span>
        </div>
        <div class="lt-prog-wrap"><div id="st-prog-bar" class="lt-prog-bar bg-orange-500" style="width:0%"></div></div>
    </div>
</div>

<!-- Per-URL breakdown -->
<div class="lt-card">
    <p class="lt-stitle">Per-URL Breakdown</p>
    <div id="lt-breakdown"><p class="text-xs text-gray-300 italic">Run a test to see per-URL stats.</p></div>
</div>

</div><!-- /left -->

<!-- ══ RIGHT ══ -->
<div class="xl:col-span-2 space-y-5">

<!-- Chart -->
<div class="lt-card">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <p class="lt-stitle mb-0">Response Time Over Test</p>
        <div class="flex gap-3 text-[11px] text-gray-400">
            <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-400 inline-block"></span>&lt;500ms</span>
            <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-yellow-400 inline-block"></span>500–2s</span>
            <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>&gt;2s</span>
        </div>
    </div>
    <div style="position:relative;height:210px"><canvas id="lt-chart"></canvas></div>
</div>

<!-- Load meter + verdict -->
<div class="lt-card">
    <div class="flex items-end gap-5">
        <div class="flex-1">
            <p class="lt-stitle">Load Intensity</p>
            <div class="flex items-end gap-[3px] h-16" id="lt-bars">
                <?php for($i=0;$i<50;$i++): ?>
                <div class="lt-bar bg-gray-100" style="height:15%"></div>
                <?php endfor; ?>
            </div>
            <div class="flex justify-between text-[10px] text-gray-300 mt-1">
                <span>Idle</span><span>Light</span><span>Moderate</span><span>Heavy</span><span>Peak</span>
            </div>
        </div>
        <div class="text-center pb-1 flex-shrink-0">
            <p class="text-[10px] text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Grade</p>
            <div id="lt-grade-circle" class="lt-grade-circle bg-gray-100 text-gray-300 mx-auto">—</div>
        </div>
    </div>
    <div id="lt-verdict" class="mt-3 text-sm text-gray-400 italic">Verdict will appear after test completes.</div>
</div>

<!-- Request log -->
<div class="lt-card">
    <div class="flex items-center justify-between mb-3">
        <p class="lt-stitle mb-0">Live Request Log</p>
        <div class="flex items-center gap-3">
            <span class="text-[10px] text-gray-300" id="lt-log-count">0 entries</span>
            <button onclick="ltClearLog()" class="text-xs text-gray-400 hover:text-gray-700 transition">Clear</button>
        </div>
    </div>
    <div id="lt-log"><span class="info">Waiting to start…</span></div>
</div>

<!-- Live preview -->
<div class="lt-card">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <p class="lt-stitle mb-0">Live Site Preview</p>
        <div class="flex gap-2 items-center">
            <select id="lt-prev-sel" class="border border-gray-200 rounded-lg px-3 py-1.5 text-xs outline-none focus:ring-2 focus:ring-orange-300">
                <?php foreach ($defaultTargets as $t): ?>
                <option value="<?= e($siteUrl.$t['path']) ?>"><?= e($t['label']) ?> — <?= e($t['path']) ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="ltLoadPreview(false)" class="text-xs bg-orange-500 hover:bg-orange-600 text-white px-3 py-1.5 rounded-lg font-medium transition">Load</button>
        </div>
    </div>
    <div class="relative rounded-xl overflow-hidden border border-gray-100" style="min-height:120px">
        <div id="lt-prev-ov" class="absolute inset-0 bg-white/90 flex flex-col items-center justify-center gap-3 z-10">
            <div class="lt-spin"></div>
            <p class="text-xs text-gray-400">Loading preview…</p>
        </div>
        <iframe id="lt-frame" src="about:blank" sandbox="allow-same-origin allow-scripts allow-forms"></iframe>
    </div>
    <div class="flex flex-wrap gap-5 mt-3 text-xs text-gray-400">
        <span>Load time: <strong id="lt-prev-time" class="text-gray-700">—</strong></span>
        <span>URL: <strong id="lt-prev-url" class="text-gray-700 font-mono truncate max-w-xs"><?= e($siteUrl) ?>/</strong></span>
        <span>Status: <strong id="lt-prev-status" class="text-gray-700">—</strong></span>
    </div>
</div>

</div><!-- /right -->
</div><!-- /grid -->
</div><!-- /wrap -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
'use strict';
var SITE = '<?= e($siteUrl) ?>';

// ── State ──────────────────────────────────────────────────
var LT = {
    running:false, startTs:0, wave:0,
    waveTimer:null, progTimer:null, prevTimer:null, timers:[],
    stats:{ total:0, ok:0, err:0, times:[], byUrl:{} },
    buf:[]
};

// ── Chart ──────────────────────────────────────────────────
var ltChart = new Chart(document.getElementById('lt-chart').getContext('2d'), {
    type:'line',
    data:{
        labels:[],
        datasets:[
            {label:'Avg (ms)',data:[],borderColor:'#f97316',backgroundColor:'rgba(249,115,22,.1)',borderWidth:2.5,tension:.45,fill:true,pointRadius:3},
            {label:'P95 (ms)',data:[],borderColor:'#a855f7',backgroundColor:'transparent',borderWidth:2,tension:.45,borderDash:[5,4],pointRadius:0},
            {label:'Max (ms)',data:[],borderColor:'#ef4444',backgroundColor:'transparent',borderWidth:1.5,tension:.45,borderDash:[3,3],pointRadius:0},
        ]
    },
    options:{
        responsive:true,maintainAspectRatio:false,animation:{duration:250},
        interaction:{mode:'index',intersect:false},
        plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}},
                 tooltip:{callbacks:{label:c=>c.dataset.label+': '+c.parsed.y+'ms'}}},
        scales:{
            x:{grid:{color:'#f1f5f9'},ticks:{color:'#9ca3af',font:{size:10}}},
            y:{grid:{color:'#f1f5f9'},ticks:{color:'#9ca3af',font:{size:10}},min:0,suggestedMax:1500}
        }
    }
});

// ── Helpers ────────────────────────────────────────────────
function ms(n){ return (!n&&n!==0)?'—':(n<1000?n+'ms':(n/1000).toFixed(2)+'s'); }
function pct(a,b){ return b?Math.round(a/b*100):0; }
function urls(){ return [...document.querySelectorAll('.lt-cb:checked')].map(c=>c.value); }
function p95(arr){ if(!arr.length)return 0; var s=[...arr].sort((a,b)=>a-b); return s[Math.floor(s.length*.95)]||s[s.length-1]; }
function gradeInfo(avg,sr){
    if(sr<80) return {g:'F',bg:'#fee2e2',fg:'#dc2626',v:'🔴 Critical — high failure rate. Server cannot handle this load.'};
    if(avg>3000) return {g:'D',bg:'#fee2e2',fg:'#ef4444',v:'🔴 Very slow. Server is under severe stress.'};
    if(avg>1500) return {g:'C',bg:'#fef3c7',fg:'#d97706',v:'🟠 Noticeable slowdowns. Review DB queries and enable caching.'};
    if(avg>700)  return {g:'B',bg:'#fefce8',fg:'#ca8a04',v:'🟡 Acceptable but some pages feel slow. Add full-page caching.'};
    if(avg>300)  return {g:'A',bg:'#d1fae5',fg:'#059669',v:'🟢 Good performance under this load.'};
    return             {g:'A+',bg:'#d1fae5',fg:'#047857',v:'🟢 Excellent! Site handles this load with ease.'};
}
function setBadge(txt,cls){
    var b=document.getElementById('lt-badge');
    b.className='lt-badge lt-'+cls;
    var dots={idle:'#9ca3af',running:'#f59e0b',done:'#10b981'};
    document.getElementById('lt-dot').style.background=dots[cls]||'#9ca3af';
    document.getElementById('lt-badge-txt').textContent=txt;
}
function set(id,v){ var el=document.getElementById(id); if(el) el.textContent=v; }

var _logN=0;
function ltLog(msg,cls){
    var log=document.getElementById('lt-log');
    if(log.querySelector('.info')&&log.querySelector('.info').textContent.startsWith('Waiting')) log.innerHTML='';
    var t=new Date().toLocaleTimeString('en-GB',{hour12:false});
    var d=document.createElement('div');
    if(cls)d.className=cls;
    d.textContent='['+t+'] '+msg;
    log.appendChild(d);
    log.scrollTop=log.scrollHeight;
    while(log.children.length>300)log.removeChild(log.firstChild);
    set('lt-log-count',++_logN+' entries');
}
function ltClearLog(){
    document.getElementById('lt-log').innerHTML='<span class="info">Log cleared.</span>';
    _logN=0; set('lt-log-count','0 entries');
}

// ── Fetch one URL ──────────────────────────────────────────
function ltFetch(url){
    var u=document.getElementById('o-bust').checked?url+(url.includes('?')?'&':'?')+'_t='+Date.now():url;
    var t0=performance.now();
    return fetch(u,{method:'GET',mode:'no-cors',cache:document.getElementById('o-bust').checked?'no-store':'default',credentials:'omit'})
        .then(()=>({ok:true,ms:Math.round(performance.now()-t0),url}))
        .catch(e=>({ok:false,ms:Math.round(performance.now()-t0),url,msg:e.message}));
}

// ── Update stats panel ─────────────────────────────────────
function ltUpdateStats(){
    var s=LT.stats, t=s.times;
    var avg=t.length?Math.round(t.reduce((a,b)=>a+b,0)/t.length):0;
    var mn=t.length?Math.min(...t):0, mx=t.length?Math.max(...t):0, p=p95(t);
    var sr=pct(s.ok,s.total);
    var el=Math.max(1,(Date.now()-LT.startTs)/1000);
    var rps=(s.total/el).toFixed(1);
    var gi=gradeInfo(avg,sr);
    set('st-total',s.total); set('st-ok',s.ok); set('st-err',s.err); set('st-rps',rps);
    set('st-avg',ms(avg)); set('st-p95',ms(p));
    set('st-min',t.length?ms(mn):'—'); set('st-max',t.length?ms(mx):'—');
    var re=document.getElementById('st-rate');
    re.textContent=s.total?sr+'%':'—';
    re.className='font-bold '+(sr>=95?'text-green-600':sr>=80?'text-yellow-600':'text-red-500');
    var ge=document.getElementById('st-grade');
    ge.textContent=s.total?gi.g:'—'; ge.style.color=gi.fg||'';
    var gc=document.getElementById('lt-grade-circle');
    gc.textContent=s.total?gi.g:'—';
    gc.style.background=s.total?gi.bg:'#f3f4f6'; gc.style.color=s.total?gi.fg:'#d1d5db';
    // Breakdown
    var bd=document.getElementById('lt-breakdown');
    bd.innerHTML='';
    Object.entries(s.byUrl).forEach(([u,d])=>{
        var path=u.replace(/^https?:\/\/[^/]+/,'')||'/';
        var uavg=d.times.length?Math.round(d.times.reduce((a,b)=>a+b,0)/d.times.length):0;
        var urate=pct(d.ok,d.total);
        bd.innerHTML+='<div class="pb-3 mb-3 border-b border-gray-100 last:border-0 last:mb-0 last:pb-0">'
            +'<div class="flex justify-between mb-1"><span class="text-sm font-semibold text-gray-700 truncate max-w-[65%]" title="'+u+'">'+path+'</span>'
            +'<span class="text-xs font-bold '+(urate>=95?'text-green-600':urate>=80?'text-yellow-600':'text-red-500')+'">'+urate+'%</span></div>'
            +'<div class="flex gap-3 text-xs text-gray-400 mb-1.5"><span>'+d.total+' req</span><span>avg '+ms(uavg)+'</span><span>'+d.ok+' ok</span><span>'+d.err+' err</span></div>'
            +'<div class="lt-prog-wrap"><div class="lt-prog-bar" style="width:'+Math.min(100,uavg/20)+'%;background:'+(uavg<500?'#4ade80':uavg<2000?'#facc15':'#f87171')+'"></div></div>'
            +'</div>';
    });
    if(!Object.keys(s.byUrl).length) bd.innerHTML='<p class="text-xs text-gray-300 italic">No data yet.</p>';
}

// ── Push to chart ──────────────────────────────────────────
function ltPushChart(avg,p,mx){
    LT.buf.push({avg,p,mx});
    if(LT.buf.length>80)LT.buf.shift();
    ltChart.data.labels=LT.buf.map((_,i)=>i+'s');
    ltChart.data.datasets[0].data=LT.buf.map(d=>d.avg);
    ltChart.data.datasets[1].data=LT.buf.map(d=>d.p);
    ltChart.data.datasets[2].data=LT.buf.map(d=>d.mx);
    ltChart.update('none');
}

// ── Animate load bars ──────────────────────────────────────
function ltAnimateBars(frac){
    var bars=document.querySelectorAll('#lt-bars .lt-bar');
    var lf=Math.min(1,Math.max(0,frac));
    bars.forEach((b,i)=>{
        var th=i/bars.length, active=th<lf;
        b.style.height=(active?(35+Math.random()*65):(4+Math.random()*10))+'%';
        b.style.background=active?(lf<.35?'#4ade80':lf<.6?'#facc15':lf<.85?'#fb923c':'#f87171'):'#f1f5f9';
    });
}

// ── Fire one wave ──────────────────────────────────────────
function ltWave(){
    if(!LT.running)return;
    var us=urls(); if(!us.length){ltStop(false);return;}
    var users=parseInt(document.getElementById('s-users').value);
    var intv=parseInt(document.getElementById('s-int').value);
    var ramp=parseInt(document.getElementById('s-ramp').value);
    var el=(Date.now()-LT.startTs)/1000;
    var rf=ramp>0?Math.min(1,el/ramp):1;
    var active=Math.max(1,Math.round(users*rf));
    LT.wave++;
    var batch=[];
    for(var i=0;i<active;i++){
        var url=document.getElementById('o-rand').checked?us[Math.floor(Math.random()*us.length)]:us[LT.wave%us.length];
        batch.push(ltFetch(url));
    }
    LT.waveTimer=setTimeout(ltWave,intv);
    Promise.allSettled(batch).then(results=>{
        var wms=[];
        results.forEach(r=>{
            if(r.status!=='fulfilled')return;
            var res=r.value;
            LT.stats.total++; LT.stats.times.push(res.ms); wms.push(res.ms);
            if(!LT.stats.byUrl[res.url])LT.stats.byUrl[res.url]={total:0,ok:0,err:0,times:[]};
            LT.stats.byUrl[res.url].total++; LT.stats.byUrl[res.url].times.push(res.ms);
            if(res.ok){
                LT.stats.ok++; LT.stats.byUrl[res.url].ok++;
                ltLog('GET '+(res.url.replace(/^https?:\/\/[^/]+/,'')||'/')+' → '+res.ms+'ms', res.ms<500?'ok':res.ms<2000?'slow':'err');
            } else {
                LT.stats.err++; LT.stats.byUrl[res.url].err++;
                ltLog('FAIL '+(res.url.replace(/^https?:\/\/[^/]+/,''))+' ('+res.ms+'ms'+(res.msg?' – '+res.msg:'')+')','err');
            }
        });
        if(wms.length){
            var wavg=Math.round(wms.reduce((a,b)=>a+b,0)/wms.length);
            var wp=p95(wms), wmx=Math.max(...wms);
            ltPushChart(wavg,wp,wmx);
            var el2=Math.max(1,(Date.now()-LT.startTs)/1000);
            ltAnimateBars((LT.stats.total/el2)/(users*(1000/intv)));
        }
        ltUpdateStats();
    });
}

// ── Start ──────────────────────────────────────────────────
function ltStart(){
    if(LT.running)return;
    if(!urls().length){alert('Please select at least one target URL.');return;}
    LT.running=true; LT.startTs=Date.now(); LT.wave=0;
    LT.stats={total:0,ok:0,err:0,times:[],byUrl:{}}; LT.buf=[];
    ltChart.data.labels=[]; ltChart.data.datasets.forEach(d=>d.data=[]); ltChart.update();
    document.getElementById('lt-run-btn').style.display='none';
    document.getElementById('lt-stop-btn').style.display='flex';
    setBadge('Running…','running');
    document.getElementById('lt-verdict').textContent='';
    var dur=parseInt(document.getElementById('s-dur').value)*1000;
    ltLog('▶ Test started · '+urls().length+' target(s) · '+document.getElementById('s-users').value+' users · '+dur/1000+'s','info');
    // Progress
    LT.progTimer=setInterval(()=>{
        if(!LT.running){clearInterval(LT.progTimer);return;}
        var el=Date.now()-LT.startTs, p=Math.min(100,Math.round(el/dur*100));
        document.getElementById('st-prog-bar').style.width=p+'%';
        set('st-prog-pct',p+'%');
        set('st-prog-lbl',Math.max(0,Math.ceil((dur-el)/1000))+'s remaining');
    },300);
    // Auto-stop
    LT.timers.push(setTimeout(()=>ltStop(true),dur));
    // Auto-refresh preview
    if(document.getElementById('o-autoref').checked)
        LT.prevTimer=setInterval(()=>{ if(LT.running)ltLoadPreview(true); },10000);
    ltWave();
    ltLoadPreview(true);
}

// ── Stop ───────────────────────────────────────────────────
function ltStop(auto){
    if(!LT.running)return;
    LT.running=false;
    clearTimeout(LT.waveTimer); clearInterval(LT.progTimer); clearInterval(LT.prevTimer);
    LT.timers.forEach(t=>clearTimeout(t)); LT.timers=[];
    document.getElementById('lt-run-btn').style.display='flex';
    document.getElementById('lt-stop-btn').style.display='none';
    document.getElementById('st-prog-bar').style.width='100%';
    set('st-prog-pct','100%'); set('st-prog-lbl','Complete');
    setBadge('Done','done');
    ltUpdateStats();
    var s=LT.stats, avg=s.times.length?Math.round(s.times.reduce((a,b)=>a+b,0)/s.times.length):0;
    var sr=pct(s.ok,s.total), gi=gradeInfo(avg,sr);
    document.getElementById('lt-verdict').innerHTML=
        '<span class="font-semibold text-gray-800">'+gi.v+'</span>'
        +'<span class="text-gray-400 ml-3">'+s.total+' requests · '+sr+'% success · avg '+ms(avg)+' · Grade: <strong>'+gi.g+'</strong></span>';
    ltLog('■ '+(auto?'Auto-stopped':'Stopped')+' · '+s.total+' req · '+sr+'% ok · avg '+ms(avg)+' · Grade '+gi.g,'info');
    ltAnimateBars(sr/100);
}

// ── Add custom URL ─────────────────────────────────────────
function ltAddUrl(){
    var inp=document.getElementById('lt-custom'), raw=inp.value.trim();
    if(!raw)return;
    var url=raw.startsWith('http')?raw:SITE+'/'+raw.replace(/^\//,'');
    var path=url.replace(/^https?:\/\/[^/]+/,'')||'/';
    var lbl=document.createElement('label');
    lbl.className='flex items-center gap-2.5 text-sm cursor-pointer';
    lbl.innerHTML='<input type="checkbox" class="lt-cb accent-orange-500 w-4 h-4 rounded" value="'+url+'" checked>'
        +'<span class="text-gray-700 font-medium">'+path+'</span><span class="text-gray-300 text-xs ml-auto font-mono">custom</span>';
    document.getElementById('lt-targets').appendChild(lbl);
    var opt=document.createElement('option');
    opt.value=url; opt.textContent=path+' (custom)';
    document.getElementById('lt-prev-sel').appendChild(opt);
    inp.value='';
    ltLog('Added target: '+url,'info');
}

// ── Preview ────────────────────────────────────────────────
var _pt0=0;
function ltLoadPreview(silent){
    var url=document.getElementById('lt-prev-sel').value;
    var frame=document.getElementById('lt-frame');
    var ov=document.getElementById('lt-prev-ov');
    if(!silent)ov.style.display='flex';
    _pt0=performance.now();
    set('lt-prev-url',url);
    frame.src=url+(document.getElementById('o-bust').checked?(url.includes('?')?'&':'?')+'_ltpv='+Date.now():'');
    frame.onload=function(){
        var t=Math.round(performance.now()-_pt0);
        ov.style.display='none';
        set('lt-prev-time',ms(t));
        set('lt-prev-status',t<500?'✅ Fast':t<2000?'⚠️ Slow':'🔴 Very slow');
        if(!silent)ltLog('Preview loaded: '+url+' → '+t+'ms','info');
    };
}

// Initial preview on load
window.addEventListener('DOMContentLoaded',()=>{
    ltLoadPreview(false);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
