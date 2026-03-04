<?php
/**
 * Maintenance Mode Page — Dual Game Support
 * Game 1: Space Runner (dark) — Chrome dino style
 * Game 2: Banana Jump (light) — Monkey platformer
 */
require_once __DIR__ . '/includes/functions.php';
$siteName   = getSetting('site_name', 'KhatiBangla');
$siteLogo   = getSetting('site_logo', '');
$maintMsg   = getSetting('maintenance_message', '');
$maintEta   = getSetting('maintenance_eta', '');
$maintTitle = getSetting('maintenance_title', '');
$gameType   = getSetting('maintenance_game', 'space');
// Build logo URL same way as main header.php
$siteLogoUrl = '';
if ($siteLogo) {
    if (str_starts_with($siteLogo, 'http')) {
        $siteLogoUrl = $siteLogo;
    } else {
        $siteLogoUrl = uploadUrl($siteLogo);
    }
}
http_response_code(503);
header('Retry-After: 3600');
$isDark = ($gameType === 'space');
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($siteName) ?> — রক্ষণাবেক্ষণ চলছে</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
html{height:100%;-webkit-text-size-adjust:100%}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;min-height:100%;min-height:100dvh;overflow-x:hidden;overflow-y:auto;
  background:<?= $isDark ? '#0b0f1a' : '#fef9ef' ?>;color:<?= $isDark ? '#e2e8f0' : '#3d2c1e' ?>;
  -webkit-tap-highlight-color:transparent;touch-action:manipulation;-webkit-touch-callout:none;user-select:none}

.wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;min-height:100dvh;padding:24px 16px;position:relative;z-index:1;gap:0}
.logo-area{text-align:center;margin-bottom:10px}
.logo-area img{height:48px;border-radius:12px;padding:5px;background:<?= $isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.04)' ?>}
.site-name{font-size:22px;font-weight:800;letter-spacing:-.5px;margin-top:6px;
  <?php if($isDark): ?>background:linear-gradient(135deg,#e2e8f0,#818cf8);-webkit-background-clip:text;-webkit-text-fill-color:transparent<?php else: ?>color:#5b3a1a<?php endif; ?>}
.badge{display:inline-block;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;margin-bottom:8px;
  background:<?= $isDark ? 'rgba(239,68,68,.15)' : 'rgba(245,158,11,.12)' ?>;
  border:1px solid <?= $isDark ? 'rgba(239,68,68,.3)' : 'rgba(245,158,11,.3)' ?>;
  color:<?= $isDark ? '#fca5a5' : '#b45309' ?>}
.message{font-size:14px;line-height:1.6;max-width:440px;text-align:center;margin-bottom:14px;padding:0 8px;color:<?= $isDark ? '#94a3b8' : '#78716c' ?>}
.eta{font-size:12px;margin-bottom:6px;color:<?= $isDark ? '#64748b' : '#a8a29e' ?>}

/* Game Box */
.game-box{border-radius:16px;padding:8px;width:100%;max-width:600px;
  background:<?= $isDark ? 'rgba(255,255,255,.04)' : '#fff' ?>;
  border:1px solid <?= $isDark ? 'rgba(255,255,255,.06)' : '#e7e5e4' ?>;
  <?php if(!$isDark): ?>box-shadow:0 4px 24px rgba(0,0,0,.06)<?php endif; ?>}
.game-hud{display:flex;justify-content:space-between;align-items:center;padding:4px 6px 6px;font-size:12px;color:<?= $isDark ? '#64748b' : '#a8a29e' ?>}
.game-hud .val{font-weight:800;font-size:14px;margin-left:3px;color:<?= $isDark ? '#fff' : '#3d2c1e' ?>}

/* Canvas */
.canvas-wrap{position:relative;cursor:pointer;-webkit-tap-highlight-color:transparent;touch-action:manipulation;border-radius:12px;overflow:hidden}
#gameCanvas{display:block;width:100%;height:auto;border-radius:12px;background:<?= $isDark ? '#080c16' : '#f0fdf4' ?>}

/* Overlays */
.g-overlay{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;border-radius:12px;z-index:5;transition:opacity .3s;-webkit-tap-highlight-color:transparent;padding:10px}
.g-overlay.hidden{opacity:0;pointer-events:none}
#startOverlay{background:<?= $isDark ? 'rgba(8,12,22,.8)' : 'rgba(240,253,244,.88)' ?>}
#deadOverlay{background:<?= $isDark ? 'rgba(8,12,22,.85)' : 'rgba(254,249,239,.9)' ?>}
.g-overlay .icon{font-size:36px;margin-bottom:4px;animation:bob 2s ease-in-out infinite;line-height:1}
@keyframes bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
.g-overlay .txt{font-size:14px;font-weight:700;color:<?= $isDark ? '#e2e8f0' : '#3d2c1e' ?>}
.g-overlay .sub{font-size:10px;margin-top:3px;color:<?= $isDark ? '#94a3b8' : '#78716c' ?>;max-width:260px;text-align:center;line-height:1.4}
.key-hint{display:inline-block;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;margin-top:6px;
  border:1px solid <?= $isDark ? '#475569' : '#d6d3d1' ?>;color:<?= $isDark ? '#e2e8f0' : '#57534e' ?>;
  background:<?= $isDark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.03)' ?>;animation:pulse 2s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:.7}50%{opacity:1}}
.restart-btn{margin-top:8px;padding:8px 22px;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:transform .15s;
  background:<?= $isDark ? 'linear-gradient(135deg,#6366f1,#8b5cf6)' : 'linear-gradient(135deg,#f59e0b,#f97316)' ?>;color:#fff}
.restart-btn:hover,.restart-btn:active{transform:scale(1.05)}
.dead-score{font-size:24px;font-weight:900;margin:3px 0;color:<?= $isDark ? '#fff' : '#3d2c1e' ?>}
.dead-best{font-size:11px;color:<?= $isDark ? '#94a3b8' : '#a8a29e' ?>}
.footer-note{font-size:11px;margin-top:14px;text-align:center;color:<?= $isDark ? '#334155' : '#d6d3d1' ?>}

/* ═══ MOBILE: Small phones (≤380px) ═══ */
@media(max-width:380px){
  .wrap{padding:12px 8px;justify-content:flex-start;padding-top:max(12px,env(safe-area-inset-top))}
  .logo-area{margin-bottom:6px}
  .logo-area img{height:36px;padding:3px}
  .site-name{font-size:18px}
  .badge{font-size:10px;padding:3px 10px;margin-bottom:5px}
  .message{font-size:11px;line-height:1.5;margin-bottom:8px;padding:0 4px}
  .eta{font-size:10px}
  .game-box{padding:5px;border-radius:12px}
  .game-hud{font-size:10px;padding:2px 4px 4px}
  .game-hud .val{font-size:11px;margin-left:2px}
  .canvas-wrap{border-radius:8px}
  #gameCanvas{border-radius:8px}
  .g-overlay{padding:6px;border-radius:8px}
  .g-overlay .icon{font-size:24px;margin-bottom:2px}
  .g-overlay .txt{font-size:11px}
  .g-overlay .sub{font-size:8px;max-width:200px}
  .key-hint{font-size:9px;padding:2px 8px;margin-top:4px}
  .restart-btn{padding:6px 18px;font-size:11px;margin-top:5px;border-radius:8px}
  .dead-score{font-size:18px}
  .dead-best{font-size:9px}
  .footer-note{font-size:9px;margin-top:8px}
}

/* ═══ MOBILE: Regular phones (381-480px) ═══ */
@media(min-width:381px) and (max-width:480px){
  .wrap{padding:14px 10px}
  .logo-area img{height:40px}
  .site-name{font-size:19px}
  .message{font-size:12px;margin-bottom:10px}
  .game-box{padding:6px;border-radius:14px}
  .game-hud{font-size:11px;padding:2px 5px 5px}
  .game-hud .val{font-size:12px}
  .g-overlay .icon{font-size:28px}
  .g-overlay .txt{font-size:12px}
  .g-overlay .sub{font-size:9px}
  .key-hint{font-size:10px}
  .restart-btn{padding:7px 20px;font-size:12px}
  .dead-score{font-size:20px}
}

/* ═══ MOBILE: Landscape ═══ */
@media(max-height:420px) and (orientation:landscape){
  .wrap{padding:8px 16px;flex-direction:row;flex-wrap:wrap;justify-content:center;gap:8px}
  .logo-area{margin-bottom:0;width:100%;display:flex;align-items:center;justify-content:center;gap:10px}
  .logo-area img{height:28px}
  .site-name{font-size:16px;margin-top:0}
  .badge{margin-bottom:0;font-size:10px}
  .message{font-size:11px;margin-bottom:4px;max-width:100%}
  .eta{margin-bottom:2px}
  .game-box{max-width:500px;padding:4px}
  .game-hud{padding:1px 4px 3px;font-size:10px}
  .game-hud .val{font-size:11px}
  .g-overlay .icon{font-size:22px;margin-bottom:2px}
  .g-overlay .txt{font-size:11px}
  .g-overlay .sub{font-size:8px;margin-top:1px}
  .key-hint{margin-top:3px;font-size:9px;padding:2px 6px}
  .restart-btn{margin-top:4px;padding:5px 16px;font-size:10px}
  .dead-score{font-size:16px;margin:1px 0}
  .footer-note{display:none}
}

/* ═══ Short viewport (e.g. older iPhones, keyboard open) ═══ */
@media(max-height:560px) and (orientation:portrait){
  .wrap{justify-content:flex-start;padding-top:10px}
  .logo-area{margin-bottom:4px}
  .logo-area img{height:32px}
  .site-name{font-size:17px}
  .badge{margin-bottom:4px}
  .message{font-size:11px;margin-bottom:6px;line-height:1.4}
  .footer-note{margin-top:6px}
}

/* ═══ Tablet/Desktop ═══ */
@media(min-width:768px){
  .wrap{padding:32px 24px}
  .site-name{font-size:26px}
  .message{font-size:15px}
  .game-box{padding:12px}
  .game-hud{font-size:14px;padding:4px 10px 10px}
  .game-hud .val{font-size:16px}
}

<?php if($isDark): ?>
.stars-layer{position:fixed;inset:0;pointer-events:none;z-index:0}
.sl{position:absolute;border-radius:50%;background:#fff}
.sl-1{animation:drift 80s linear infinite}
.sl-2{animation:drift 50s linear infinite}
@keyframes drift{from{transform:translateX(0)}to{transform:translateX(-1500px)}}
<?php else: ?>
.cloud-layer{position:fixed;inset:0;pointer-events:none;z-index:0;overflow:hidden}
.cloud{position:absolute;background:#fff;border-radius:50%;opacity:.45}
.cloud::before,.cloud::after{content:'';position:absolute;background:#fff;border-radius:50%}
.cl-1{width:80px;height:30px;top:10%;animation:floatC 40s linear infinite}
.cl-1::before{width:40px;height:40px;top:-20px;left:15px}.cl-1::after{width:50px;height:35px;top:-15px;left:35px}
.cl-2{width:60px;height:22px;top:25%;animation:floatC 55s linear infinite;animation-delay:-15s}
.cl-2::before{width:30px;height:30px;top:-15px;left:10px}.cl-2::after{width:35px;height:25px;top:-10px;left:25px}
@keyframes floatC{from{left:110%}to{left:-200px}}
<?php endif; ?>
</style>
</head>
<body>

<?php if($isDark): ?>
<div class="stars-layer" id="bgLayer"></div>
<?php else: ?>
<div class="cloud-layer">
    <div class="cloud cl-1" style="left:20%"></div>
    <div class="cloud cl-2" style="left:60%"></div>
    <div class="cloud cl-1" style="left:140%;top:18%"></div>
</div>
<?php endif; ?>

<div class="wrap">
    <div class="logo-area">
        <?php if ($siteLogoUrl): ?><img src="<?= htmlspecialchars($siteLogoUrl) ?>" alt="<?= htmlspecialchars($siteName) ?>"><?php endif; ?>
        <div class="site-name"><?= htmlspecialchars($siteName) ?></div>
    </div>
    <div class="badge"><?= $isDark ? '🔧' : '🍌' ?> <?= htmlspecialchars($maintTitle ?: 'রক্ষণাবেক্ষণ চলছে') ?></div>
    <p class="message"><?php
        if ($maintMsg) {
            echo nl2br(htmlspecialchars($maintMsg));
        } else {
            echo 'আমাদের সাইটটি এই মুহূর্তে আপডেট হচ্ছে।<br>শীঘ্রই আমরা ফিরে আসবো। ততক্ষণ গেমটি উপভোগ করুন! 🎮';
        }
    ?></p>
    <?php if ($maintEta): ?><div class="eta">🕐 আনুমানিক সময়: <?= htmlspecialchars($maintEta) ?></div><?php endif; ?>

    <div class="game-box">
        <div class="game-hud">
            <div><?= $isDark ? '🚀' : '🐒' ?> স্কোর: <span class="val" id="hScore">0</span></div>
            <div>⚡ গতি: <span class="val" id="hSpeed">1</span>x</div>
            <div>🏆 সেরা: <span class="val" id="hBest">0</span></div>
        </div>
        <div class="canvas-wrap" id="canvasWrap">
            <canvas id="gameCanvas" width="600" height="200"></canvas>

            <!-- Start Screen -->
            <div class="g-overlay" id="startOverlay">
                <div class="icon"><?= $isDark ? '🚀' : '🐒' ?></div>
                <div class="txt"><?= $isDark ? 'স্পেস রানার' : 'বানানা জাম্প' ?></div>
                <div class="sub">
                    <?php if($isDark): ?>
                    বাধা এড়িয়ে যতদূর সম্ভব উড়ুন!<br>🕹️ জাম্প করুন (ডবল জাম্প সাপোর্ট)
                    <?php else: ?>
                    বাধা এড়ান, কলা সংগ্রহ করুন!<br>🕹️ জাম্প করুন (ডবল জাম্প সাপোর্ট)
                    <?php endif; ?>
                </div>
                <div class="key-hint" id="startHint">👆 যেকোনো জায়গায় TAP করুন</div>
            </div>

            <!-- Game Over Screen -->
            <div class="g-overlay hidden" id="deadOverlay">
                <div style="font-size:28px;line-height:1">💥</div>
                <div class="txt">গেম ওভার!</div>
                <div class="dead-score" id="deadScore">0</div>
                <div class="dead-best" id="deadBest">সেরা: 0</div>
                <button class="restart-btn" id="restartBtn">🔄 আবার খেলুন</button>
            </div>
        </div>
    </div>
    <div class="footer-note">শীঘ্রই ফিরে আসছি ❤️</div>
</div>

<script>
// ═══════════════════════════════════════
//  SPACE RUNNER / BANANA JUMP ENGINE
// ═══════════════════════════════════════
var GAME = '<?= $gameType ?>';
var cvs = document.getElementById('gameCanvas');
var ctx = cvs.getContext('2d');
var W = 600, H = 200;
cvs.width = W; cvs.height = H;

function fitCanvas(){
    var p = cvs.parentElement;
    var maxW = p.clientWidth - (window.innerWidth <= 380 ? 4 : 10);
    var s = Math.min(1, maxW / W);
    cvs.style.width = Math.floor(W*s)+'px';
    cvs.style.height = Math.floor(H*s)+'px';
}
fitCanvas();
window.addEventListener('resize', fitCanvas);

var GROUND = H - 24;
var GRAV = 0.6;
var JUMP_F = -11;
var DJUMP_F = -9;
var state = 'idle';
var score = 0, best = 0, speed = 4, speedMul = 1, frame = 0;
try { best = parseInt(localStorage.getItem('maint_best_'+GAME)||'0',10)||0; } catch(e){}
document.getElementById('hBest').textContent = best;

var P = {x:60, y:GROUND, w:28, h:28, vy:0, grounded:true, jumps:0, flame:0, anim:0};
var OBS = [], COINS = [], PARTS = [];
var nextObs = 80, nextCoin = 40;

var bgItems = [];
for(var i=0;i<40;i++) bgItems.push({x:Math.random()*W, y:Math.random()*(H-30), s:Math.random()*1.5+.5, sp:Math.random()*.5+.2, a:Math.random()});
var groundDots = [];
for(var i=0;i<25;i++) groundDots.push({x:Math.random()*W, sp:Math.random()+1});

var startOv = document.getElementById('startOverlay');
var deadOv = document.getElementById('deadOverlay');

// ─── GAME ACTIONS ───
function startGame(){
    startOv.style.display = 'none';
    deadOv.classList.add('hidden');
    state = 'running';
    score = 0; speed = 4; speedMul = 1; frame = 0;
    P.x=60; P.y=GROUND; P.vy=0; P.grounded=true; P.jumps=0; P.h=28; P.w=28; P.flame=0; P.anim=0;
    OBS=[]; COINS=[]; PARTS=[]; nextObs=60; nextCoin=30;
}

function doJump(){
    if(state === 'idle' || state === 'dead'){
        startGame();
        return;
    }
    if(state === 'running' && P.jumps < 2){
        P.vy = P.jumps === 0 ? JUMP_F : DJUMP_F;
        P.grounded = false;
        P.jumps++;
        P.flame = 8;
    }
}

function die(){
    state = 'dead';
    if(score > best){
        best = score;
        try{ localStorage.setItem('maint_best_'+GAME, String(best)); }catch(e){}
        document.getElementById('hBest').textContent = best;
    }
    // Explosion particles
    for(var i=0;i<20;i++){
        var a = Math.random()*Math.PI*2, sp = 1+Math.random()*4;
        var colors = GAME==='space' ? ['#f59e0b','#ef4444','#fff'] : ['#f59e0b','#84cc16','#a16207'];
        PARTS.push({x:P.x+P.w/2, y:P.y-P.h/2, vx:Math.cos(a)*sp, vy:Math.sin(a)*sp-2, r:2+Math.random()*3, life:1, c:colors[Math.floor(Math.random()*3)]});
    }
    // Show dead overlay
    deadOv.classList.remove('hidden');
    document.getElementById('deadScore').textContent = score;
    document.getElementById('deadBest').textContent = 'সেরা: ' + best;
}

// ─── INPUT HANDLERS ───
// Keyboard
document.addEventListener('keydown', function(e){
    if(e.code === 'Space' || e.key === ' ' || e.code === 'ArrowUp'){
        e.preventDefault();
        doJump();
    }
});

// Touch — ENTIRE SCREEN is tap zone on mobile for best experience
document.addEventListener('touchstart', function(e){
    // Don't intercept touches on restart button (let it handle itself)
    if(e.target && e.target.id === 'restartBtn') return;
    e.preventDefault();
    doJump();
}, {passive: false});

// Mouse click on game area only (desktop)
document.getElementById('canvasWrap').addEventListener('click', function(e){
    e.preventDefault();
    doJump();
});

// Restart button
document.getElementById('restartBtn').addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    doJump();
});

// ─── SPAWNERS ───
function spawnObs(){
    if(GAME === 'space'){
        var types = ['ast','ast','ast','sat','ufo'];
        if(speedMul >= 1.5) types.push('ufo','dbl');
        var t = types[Math.floor(Math.random()*types.length)];
        if(t === 'dbl'){
            OBS.push({x:W+10,y:GROUND,w:18,h:18,type:'ast',passed:false});
            OBS.push({x:W+40,y:GROUND,w:18,h:18,type:'ast',passed:false});
        } else if(t === 'ufo'){
            var fy = GROUND-30-Math.random()*40;
            OBS.push({x:W+10,y:fy,w:30,h:14,type:'ufo',passed:false,baseY:fy,ph:Math.random()*6});
        } else if(t === 'sat'){
            OBS.push({x:W+10,y:GROUND-26-Math.random()*20,w:14,h:28,type:'sat',passed:false,ang:0});
        } else {
            var big = Math.random()<.3, sz = big?24:16;
            OBS.push({x:W+10,y:GROUND,w:sz,h:sz,type:'ast',passed:false});
        }
    } else {
        var types = ['cactus','cactus','rock','bird'];
        if(speedMul >= 1.5) types.push('bird','dbl');
        var t = types[Math.floor(Math.random()*types.length)];
        if(t === 'dbl'){
            OBS.push({x:W+10,y:GROUND,w:16,h:24,type:'cactus',passed:false});
            OBS.push({x:W+42,y:GROUND,w:16,h:20,type:'cactus',passed:false});
        } else if(t === 'bird'){
            var fy = GROUND-28-Math.random()*35;
            OBS.push({x:W+10,y:fy,w:26,h:16,type:'bird',passed:false,baseY:fy,ph:Math.random()*6,wing:0});
        } else if(t === 'rock'){
            OBS.push({x:W+10,y:GROUND,w:22,h:16,type:'rock',passed:false});
        } else {
            OBS.push({x:W+10,y:GROUND,w:16,h:20+Math.random()*12,type:'cactus',passed:false});
        }
    }
    nextObs = 50 + Math.random()*60 / Math.min(speedMul, 2.5);
}

function spawnCoin(){
    var yOff = Math.random()<.4 ? 40+Math.random()*30 : 10+Math.random()*15;
    COINS.push({x:W+10, y:GROUND-yOff, r:7, alive:true, bob:Math.random()*6});
    nextCoin = 30 + Math.random()*50;
}

// ─── UPDATE ───
function update(){
    if(state !== 'running') return;
    frame++;
    score = Math.floor(frame * speedMul * 0.3) + COINS.filter(function(c){return !c.alive;}).length * 5;
    speedMul = 1 + Math.floor(score / 100) * 0.15;
    speed = 4 * speedMul;
    document.getElementById('hScore').textContent = score;
    document.getElementById('hSpeed').textContent = speedMul.toFixed(1);

    // Player physics
    P.vy += GRAV;
    P.y += P.vy;
    P.anim += speedMul * 0.15;
    if(P.y >= GROUND){ P.y = GROUND; P.vy = 0; P.grounded = true; P.jumps = 0; }
    if(P.flame > 0) P.flame--;

    // Spawning
    nextObs--;
    if(nextObs <= 0) spawnObs();
    if(GAME === 'monkey'){ nextCoin--; if(nextCoin <= 0) spawnCoin(); }

    // Obstacles
    for(var i = OBS.length-1; i >= 0; i--){
        var o = OBS[i];
        o.x -= speed;
        if(o.type === 'ufo' || o.type === 'bird') o.y = o.baseY + Math.sin(frame*.08 + (o.ph||0))*10;
        if(o.type === 'sat') o.ang = (o.ang||0) + 0.05;
        if(o.type === 'bird') o.wing = (o.wing||0) + 0.2;
        if(o.x + o.w < -10){ OBS.splice(i,1); continue; }
        // Collision (forgiving hitbox)
        var pad = 5;
        if(P.x+pad < o.x+o.w-3 && P.x+P.w-pad > o.x+3 && P.y-P.h+pad < o.y-3 && P.y-pad > o.y-o.h+3){
            die(); return;
        }
    }

    // Coins (monkey mode)
    for(var i = COINS.length-1; i >= 0; i--){
        var c = COINS[i];
        if(!c.alive){ if(c.x < -20) COINS.splice(i,1); continue; }
        c.x -= speed;
        if(c.x < -20){ COINS.splice(i,1); continue; }
        var dx = P.x+P.w/2 - c.x, dy = P.y-P.h/2 - c.y;
        if(Math.sqrt(dx*dx+dy*dy) < P.w/2 + c.r){
            c.alive = false;
            for(var j=0;j<8;j++){
                var a = Math.random()*Math.PI*2;
                PARTS.push({x:c.x,y:c.y,vx:Math.cos(a)*2,vy:Math.sin(a)*2-1,r:2,life:1,c:'#f59e0b'});
            }
        }
    }

    // BG movement
    for(var i=0;i<bgItems.length;i++){
        bgItems[i].x -= bgItems[i].sp * speedMul;
        if(bgItems[i].x < -5){ bgItems[i].x = W+5; bgItems[i].y = Math.random()*(H-30); }
    }
    for(var i=0;i<groundDots.length;i++){
        groundDots[i].x -= groundDots[i].sp * speedMul;
        if(groundDots[i].x < -5) groundDots[i].x = W + Math.random()*20;
    }
}

// ─── DRAW ───
function draw(){
    ctx.clearRect(0,0,W,H);

    if(GAME === 'space') drawSpaceBG(); else drawJungleBG();

    // Particles
    for(var i = PARTS.length-1; i >= 0; i--){
        var p = PARTS[i];
        p.x += p.vx; p.y += p.vy; p.vy += 0.12; p.life -= 0.025;
        if(p.life <= 0){ PARTS.splice(i,1); continue; }
        ctx.globalAlpha = p.life;
        ctx.fillStyle = p.c;
        ctx.beginPath(); ctx.arc(p.x, p.y, p.r*p.life, 0, Math.PI*2); ctx.fill();
    }
    ctx.globalAlpha = 1;

    // Coins (monkey)
    for(var i=0;i<COINS.length;i++){
        var c = COINS[i];
        if(!c.alive) continue;
        var bobY = c.y + Math.sin(frame*0.06 + (c.bob||0))*4;
        ctx.save(); ctx.translate(c.x, bobY); ctx.rotate(-0.3);
        ctx.fillStyle = '#facc15';
        ctx.beginPath(); ctx.ellipse(0,0,c.r,c.r*0.45,0,0,Math.PI*2); ctx.fill();
        ctx.fillStyle = '#a16207';
        ctx.fillRect(-1,-c.r*0.45,2,2);
        ctx.restore();
    }

    // Obstacles
    for(var i=0;i<OBS.length;i++){
        ctx.save();
        if(GAME === 'space') drawSpaceObs(OBS[i]); else drawJungleObs(OBS[i]);
        ctx.restore();
    }

    // Player
    if(state !== 'dead'){
        if(GAME === 'space') drawRocket(); else drawMonkey();
    }

    // Speed milestone flash
    if(state === 'running' && score > 0 && score % 100 < 3 && frame % 4 < 2){
        ctx.fillStyle = GAME==='space' ? 'rgba(99,102,241,0.08)' : 'rgba(245,158,11,0.08)';
        ctx.fillRect(0,0,W,H);
    }
}

// ═══ SPACE THEME DRAWING ═══
function drawSpaceBG(){
    var sky = ctx.createLinearGradient(0,0,0,H);
    sky.addColorStop(0,'#050816'); sky.addColorStop(0.7,'#0b1026'); sky.addColorStop(1,'#111833');
    ctx.fillStyle = sky; ctx.fillRect(0,0,W,H);
    for(var i=0;i<bgItems.length;i++){
        var s = bgItems[i];
        ctx.globalAlpha = 0.3 + 0.5*Math.sin(frame*0.03 + s.a*10);
        ctx.fillStyle = '#fff';
        ctx.fillRect(Math.floor(s.x), Math.floor(s.y), Math.ceil(s.s), Math.ceil(s.s));
    }
    ctx.globalAlpha = 1;
    ctx.fillStyle = '#1e293b'; ctx.fillRect(0, GROUND+1, W, H-GROUND);
    ctx.strokeStyle = '#334155'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(0, GROUND+1); ctx.lineTo(W, GROUND+1); ctx.stroke();
}

function drawSpaceObs(o){
    var cx = o.x+o.w/2, cy = o.y-o.h/2;
    if(o.type === 'ast'){
        ctx.fillStyle = 'rgba(239,68,68,.08)'; ctx.beginPath(); ctx.arc(cx,cy,o.w,0,Math.PI*2); ctx.fill();
        ctx.fillStyle = '#78350f'; ctx.beginPath();
        for(var i=0;i<8;i++){
            var a=(i/8)*Math.PI*2, r=o.w/2*(0.7+0.3*Math.sin(i*2.7+o.x*0.1));
            i===0 ? ctx.moveTo(cx+Math.cos(a)*r, cy+Math.sin(a)*r) : ctx.lineTo(cx+Math.cos(a)*r, cy+Math.sin(a)*r);
        }
        ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#92400e'; ctx.beginPath(); ctx.arc(cx-o.w*0.1,cy-o.w*0.1,o.w*0.25,0,Math.PI*2); ctx.fill();
    } else if(o.type === 'ufo'){
        ctx.fillStyle = 'rgba(16,185,129,.05)';
        ctx.beginPath(); ctx.moveTo(cx-8,cy+6); ctx.lineTo(cx+8,cy+6); ctx.lineTo(cx+18,GROUND); ctx.lineTo(cx-18,GROUND); ctx.fill();
        ctx.fillStyle = '#475569'; ctx.beginPath(); ctx.ellipse(cx,cy+2,o.w/2,4,0,0,Math.PI*2); ctx.fill();
        ctx.fillStyle = '#10b981'; ctx.beginPath(); ctx.ellipse(cx,cy-2,8,6,0,Math.PI,0); ctx.fill();
    } else if(o.type === 'sat'){
        ctx.translate(cx,cy); ctx.rotate(o.ang||0);
        ctx.fillStyle = '#64748b'; ctx.fillRect(-4,-6,8,12);
        ctx.fillStyle = '#3b82f6'; ctx.fillRect(-14,-3,9,6); ctx.fillRect(5,-3,9,6);
        ctx.fillStyle = '#ef4444'; ctx.beginPath(); ctx.arc(0,-12,1.5,0,Math.PI*2); ctx.fill();
    }
}

function drawRocket(){
    ctx.save();
    ctx.translate(P.x+P.w/2, P.y-P.h/2);
    ctx.rotate(P.grounded ? 0 : P.vy*0.015);
    if(!P.grounded || P.flame>0 || frame%6<3){
        var fl = 6+Math.random()*8+(P.flame>0?6:0);
        ctx.fillStyle = '#f59e0b'; ctx.beginPath(); ctx.moveTo(-P.w/2-2,2); ctx.lineTo(-P.w/2-fl,0); ctx.lineTo(-P.w/2-2,-2); ctx.fill();
    }
    ctx.fillStyle = '#e2e8f0'; ctx.beginPath();
    ctx.moveTo(P.w/2+4,0); ctx.lineTo(P.w/4,-P.h/2+2); ctx.lineTo(-P.w/2,-P.h/3); ctx.lineTo(-P.w/2,P.h/3); ctx.lineTo(P.w/4,P.h/2-2);
    ctx.closePath(); ctx.fill();
    ctx.fillStyle = '#6366f1'; ctx.fillRect(-2,-P.h/3+2,6,P.h/1.6-4);
    ctx.fillStyle = '#38bdf8'; ctx.beginPath(); ctx.arc(P.w/6,0,4,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = '#ef4444';
    ctx.beginPath(); ctx.moveTo(-P.w/2,-P.h/3); ctx.lineTo(-P.w/2-5,-P.h/2-2); ctx.lineTo(-P.w/4,-P.h/3); ctx.fill();
    ctx.beginPath(); ctx.moveTo(-P.w/2,P.h/3); ctx.lineTo(-P.w/2-5,P.h/2+2); ctx.lineTo(-P.w/4,P.h/3); ctx.fill();
    ctx.restore();
}

// ═══ MONKEY/JUNGLE THEME DRAWING ═══
function drawJungleBG(){
    var sky = ctx.createLinearGradient(0,0,0,H);
    sky.addColorStop(0,'#dbeafe'); sky.addColorStop(0.5,'#ecfccb'); sky.addColorStop(1,'#f0fdf4');
    ctx.fillStyle = sky; ctx.fillRect(0,0,W,H);
    ctx.fillStyle = '#86efac';
    for(var i=0;i<8;i++){
        var tx = ((i*90 + frame*0.3*bgItems[i].sp) % 750) - 50;
        ctx.beginPath(); ctx.moveTo(tx,GROUND-10); ctx.lineTo(tx+12,GROUND-35-bgItems[i].s*10); ctx.lineTo(tx+24,GROUND-10); ctx.fill();
    }
    ctx.fillStyle = '#84cc16'; ctx.fillRect(0,GROUND+1,W,4);
    ctx.fillStyle = '#65a30d'; ctx.fillRect(0,GROUND+5,W,H-GROUND-5);
    ctx.fillStyle = '#4ade80';
    for(var i=0;i<groundDots.length;i++) ctx.fillRect(Math.floor(groundDots[i].x),GROUND-2,2,4);
}

function drawJungleObs(o){
    var cx = o.x+o.w/2, cy = o.y-o.h/2;
    if(o.type === 'cactus'){
        ctx.fillStyle = '#dc2626'; ctx.beginPath();
        ctx.moveTo(o.x+o.w/2,o.y-o.h); ctx.lineTo(o.x+o.w,o.y); ctx.lineTo(o.x,o.y); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#b91c1c'; ctx.beginPath();
        ctx.moveTo(o.x+o.w/2,o.y-o.h+4); ctx.lineTo(o.x+o.w-3,o.y); ctx.lineTo(o.x+3,o.y); ctx.closePath(); ctx.fill();
        ctx.strokeStyle = '#fca5a5'; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(cx,o.y-o.h); ctx.lineTo(cx,o.y-o.h-5); ctx.stroke();
    } else if(o.type === 'rock'){
        ctx.fillStyle = '#78716c'; ctx.beginPath(); ctx.ellipse(cx,o.y-o.h/2,o.w/2,o.h/2,0,0,Math.PI*2); ctx.fill();
        ctx.fillStyle = '#57534e'; ctx.beginPath(); ctx.arc(cx-3,cy-2,4,0,Math.PI*2); ctx.fill();
    } else if(o.type === 'bird'){
        ctx.fillStyle = '#dc2626'; ctx.beginPath(); ctx.ellipse(cx,cy,10,6,0,0,Math.PI*2); ctx.fill();
        var wAngle = Math.sin(o.wing||0)*0.5;
        ctx.fillStyle = '#ef4444'; ctx.beginPath(); ctx.ellipse(cx-3,cy-5,8,3,wAngle,0,Math.PI*2); ctx.fill();
        ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(cx+6,cy-2,2,0,Math.PI*2); ctx.fill();
        ctx.fillStyle = '#000'; ctx.beginPath(); ctx.arc(cx+6.5,cy-2,1,0,Math.PI*2); ctx.fill();
        ctx.fillStyle = '#f59e0b'; ctx.beginPath(); ctx.moveTo(cx+11,cy); ctx.lineTo(cx+16,cy+1); ctx.lineTo(cx+11,cy+2); ctx.fill();
    }
}

function drawMonkey(){
    ctx.save();
    ctx.translate(P.x+P.w/2, P.y-P.h/2);
    var bounce = P.grounded ? Math.sin(P.anim*2)*2 : 0;
    ctx.translate(0, bounce);
    ctx.rotate(P.grounded ? 0 : P.vy*0.01);
    // Body
    ctx.fillStyle = '#a16207'; ctx.beginPath(); ctx.ellipse(0,2,10,12,0,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = '#fbbf24'; ctx.beginPath(); ctx.ellipse(0,5,7,8,0,0,Math.PI*2); ctx.fill();
    // Head
    ctx.fillStyle = '#a16207'; ctx.beginPath(); ctx.arc(0,-12,9,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = '#fbbf24'; ctx.beginPath(); ctx.ellipse(0,-10,6,5,0,0,Math.PI*2); ctx.fill();
    // Eyes
    ctx.fillStyle = '#fff';
    ctx.beginPath(); ctx.arc(-3,-13,2.5,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(3,-13,2.5,0,Math.PI*2); ctx.fill();
    ctx.fillStyle = '#1c1917';
    ctx.beginPath(); ctx.arc(-2.5,-13,1.2,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(3.5,-13,1.2,0,Math.PI*2); ctx.fill();
    // Mouth
    ctx.strokeStyle = '#78350f'; ctx.lineWidth = 1; ctx.beginPath(); ctx.arc(0,-8,3,0.2,Math.PI-0.2); ctx.stroke();
    // Ears
    ctx.fillStyle = '#fbbf24';
    ctx.beginPath(); ctx.arc(-9,-12,3,0,Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc(9,-12,3,0,Math.PI*2); ctx.fill();
    // Tail
    ctx.strokeStyle = '#a16207'; ctx.lineWidth = 2.5; ctx.lineCap = 'round';
    var tailWag = Math.sin(P.anim*3)*5;
    ctx.beginPath(); ctx.moveTo(-8,10); ctx.quadraticCurveTo(-18,8+tailWag,-14,-2+tailWag); ctx.stroke();
    // Arms
    ctx.strokeStyle = '#a16207'; ctx.lineWidth = 3;
    var armSwing = P.grounded ? Math.sin(P.anim*3)*8 : -15;
    ctx.beginPath(); ctx.moveTo(-8,0); ctx.lineTo(-14,armSwing>0?8:-2); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(8,0); ctx.lineTo(14,armSwing<0?8:-2); ctx.stroke();
    // Legs
    if(P.grounded){
        var legL = Math.sin(P.anim*3)*6;
        ctx.beginPath(); ctx.moveTo(-5,12); ctx.lineTo(-7+legL,18); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(5,12); ctx.lineTo(7-legL,18); ctx.stroke();
    } else {
        ctx.beginPath(); ctx.moveTo(-5,12); ctx.lineTo(-8,18); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(5,12); ctx.lineTo(8,18); ctx.stroke();
    }
    ctx.restore();
}

// ─── Stars (dark theme) ───
if(GAME === 'space'){
    var bgEl = document.getElementById('bgLayer');
    if(bgEl){
        for(var i=0;i<70;i++){
            var d = document.createElement('div');
            d.className = 'sl ' + (i%2 ? 'sl-1' : 'sl-2');
            d.style.cssText = 'left:'+(Math.random()*150)+'%;top:'+(Math.random()*100)+'%;width:'+(1+Math.random()*1.5)+'px;height:'+(1+Math.random()*1.5)+'px;opacity:'+(0.2+Math.random()*0.5);
            bgEl.appendChild(d);
        }
    }
}

// ─── Device-aware hints ───
var isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
var hintEl = document.getElementById('startHint');
if(hintEl){
    hintEl.textContent = isTouchDevice ? '👆 যেকোনো জায়গায় TAP করুন' : '▶ শুরু করতে SPACE চাপুন';
}

// Handle orientation change
window.addEventListener('orientationchange', function(){ setTimeout(fitCanvas, 100); });

// ─── GAME LOOP ───
function gameLoop(){
    update();
    draw();
    requestAnimationFrame(gameLoop);
}
gameLoop();
</script>
</body>
</html>
