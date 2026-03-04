<?php
/**
 * API Health Monitor — Visual dashboard for courier API errors & status
 * Features:
 *   - Real-time error log with human-readable explanations
 *   - Token & rate limit status per courier
 *   - Live connectivity testing
 *   - "Copy Debug Report" for sharing with developer
 */
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
if (empty($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
include __DIR__ . '/../includes/header.php';
?>

<style>
.health-wrap { max-width: 1200px; margin: 0 auto; padding: 20px; }
.health-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
@media (max-width: 900px) { .health-grid { grid-template-columns: 1fr; } }

/* Status Cards */
.h-card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.08); border-left: 4px solid #e2e8f0; }
.h-card.ok { border-left-color: #22c55e; }
.h-card.warn { border-left-color: #f59e0b; }
.h-card.err { border-left-color: #ef4444; }
.h-card.off { border-left-color: #94a3b8; }
.h-card-title { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: #64748b; margin-bottom: 8px; font-weight: 600; }
.h-card-value { font-size: 22px; font-weight: 700; color: #1e293b; }
.h-card-sub { font-size: 13px; color: #64748b; margin-top: 4px; }

/* Rate meter */
.rate-bar { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-top: 8px; }
.rate-fill { height: 100%; border-radius: 4px; transition: width .3s; }
.rate-fill.low { background: #22c55e; }
.rate-fill.mid { background: #f59e0b; }
.rate-fill.high { background: #ef4444; }

/* Error Log Table */
.err-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.err-table th { text-align: left; padding: 10px 12px; background: #f8fafc; border-bottom: 2px solid #e2e8f0; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #64748b; position: sticky; top: 0; }
.err-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.err-table tr:hover { background: #f8fafc; }

/* Severity badges */
.sev { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.sev-critical { background: #fef2f2; color: #991b1b; }
.sev-error { background: #fff7ed; color: #9a3412; }
.sev-warning { background: #fffbeb; color: #92400e; }
.sev-info { background: #eff6ff; color: #1e40af; }

/* Courier badge */
.cb { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.cb-pathao { background: #e0f2fe; color: #0369a1; }
.cb-steadfast { background: #fce7f3; color: #9d174d; }
.cb-redx { background: #fee2e2; color: #991b1b; }
.cb-unknown { background: #f1f5f9; color: #475569; }

/* Reason tooltip */
.reason-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 8px 12px; margin-top: 6px; font-size: 12px; color: #166534; }
.fix-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 8px 12px; margin-top: 4px; font-size: 12px; color: #1e40af; }

/* Buttons */
.h-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all .2s; }
.h-btn-primary { background: #3b82f6; color: #fff; }
.h-btn-primary:hover { background: #2563eb; }
.h-btn-green { background: #22c55e; color: #fff; }
.h-btn-green:hover { background: #16a34a; }
.h-btn-red { background: #ef4444; color: #fff; }
.h-btn-red:hover { background: #dc2626; }
.h-btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
.h-btn-outline:hover { background: #f9fafb; }

.section-title { font-size: 18px; font-weight: 700; color: #1e293b; margin: 28px 0 14px; display: flex; align-items: center; gap: 8px; }
.section-title span { font-size: 20px; }

/* Toast */
.toast { position: fixed; bottom: 20px; right: 20px; background: #1e293b; color: #fff; padding: 12px 20px; border-radius: 8px; font-size: 14px; z-index: 9999; display: none; animation: fadeIn .3s; }
@keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

/* Loading spinner */
.spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Debug report */
.debug-pre { background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px; font-size: 12px; font-family: 'Courier New', monospace; max-height: 400px; overflow: auto; white-space: pre; line-height: 1.5; }

/* Token status */
.token-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
.token-dot { width: 10px; height: 10px; border-radius: 50%; }
.token-dot.active { background: #22c55e; }
.token-dot.expired { background: #ef4444; }
.token-dot.configured { background: #3b82f6; }
.token-dot.not_set { background: #94a3b8; }

/* Empty state */
.empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
.empty-state .icon { font-size: 48px; margin-bottom: 12px; }
</style>

<div class="health-wrap">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
        <h1 style="font-size:24px; font-weight:700; color:#1e293b; margin:0;">🏥 API Health Monitor</h1>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button class="h-btn h-btn-primary" onclick="runTests()">⚡ Test All APIs</button>
            <button class="h-btn h-btn-green" onclick="generateReport()">📋 Copy Debug Report</button>
            <button class="h-btn h-btn-outline" onclick="loadData()">🔄 Refresh</button>
        </div>
    </div>

    <!-- STATUS CARDS -->
    <div id="statusCards" class="health-grid">
        <div class="h-card off"><div class="h-card-title">Loading...</div><div class="h-card-value">—</div></div>
        <div class="h-card off"><div class="h-card-title">Loading...</div><div class="h-card-value">—</div></div>
        <div class="h-card off"><div class="h-card-title">Loading...</div><div class="h-card-value">—</div></div>
    </div>

    <!-- TOKEN STATUS -->
    <div class="section-title"><span>🔑</span> Token & Auth Status</div>
    <div id="tokenStatus" style="background:#fff; border-radius:12px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:24px;">
        <div style="color:#94a3b8; padding:10px;">Loading...</div>
    </div>

    <!-- RATE LIMIT METERS -->
    <div class="section-title"><span>📊</span> Rate Limit Usage (current minute)</div>
    <div id="rateMeters" class="health-grid" style="margin-bottom:24px;">
        <div class="h-card off"><div class="h-card-title">Loading...</div></div>
        <div class="h-card off"><div class="h-card-title">Loading...</div></div>
        <div class="h-card off"><div class="h-card-title">Loading...</div></div>
    </div>

    <!-- ERROR LOG -->
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <div class="section-title" style="margin:0;"><span>🚨</span> API Error Log</div>
        <button class="h-btn h-btn-red" onclick="clearLog()" style="font-size:12px; padding:6px 14px;">🗑️ Clear Log</button>
    </div>
    <div id="errorLog" style="background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-top:12px; overflow:hidden; max-height:600px; overflow-y:auto;">
        <div style="color:#94a3b8; padding:30px; text-align:center;">Loading...</div>
    </div>

    <!-- CACHE STATUS -->
    <div class="section-title"><span>💾</span> Active Caches</div>
    <div id="cacheStatus" style="background:#fff; border-radius:12px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:24px;">
        <div style="color:#94a3b8; padding:10px;">Loading...</div>
    </div>

    <!-- DEBUG REPORT -->
    <div id="debugSection" style="display:none; margin-top:24px;">
        <div class="section-title"><span>📋</span> Debug Report <small style="font-weight:400; color:#64748b;">(copy & paste to developer)</small></div>
        <pre id="debugReport" class="debug-pre"></pre>
        <button class="h-btn h-btn-green" onclick="copyReport()" style="margin-top:10px;">📋 Copy to Clipboard</button>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
const API_BASE = '../api/api-health.php';

async function loadData() {
    try {
        const resp = await fetch(`${API_BASE}?action=overview`);
        const data = await resp.json();
        renderStatusCards(data);
        renderTokens(data.tokens);
        renderRateMeters(data.rates);
        renderErrorLog(data.errors);
        renderCaches(data.caches);
    } catch (e) {
        showToast('Failed to load health data: ' + e.message, true);
    }
}

function renderStatusCards(data) {
    const errors = data.errors?.entries || [];
    const recent1h = errors.filter(e => {
        const t = new Date(e.time);
        return (Date.now() - t) < 3600000;
    });
    const couriers = ['pathao', 'steadfast', 'redx'];
    let html = '';

    couriers.forEach(c => {
        const errs = recent1h.filter(e => e.courier === c);
        const rate = data.rates?.[c] || {};
        const has429 = errs.some(e => e.code == '429');
        const has401 = errs.some(e => e.code == '401');
        const cls = has429 ? 'err' : (errs.length > 0 ? 'warn' : 'ok');
        const icon = has429 ? '🔴' : (errs.length > 0 ? '🟡' : '🟢');
        const status = has429 ? 'RATE LIMITED' : (has401 ? 'AUTH ERROR' : (errs.length > 0 ? `${errs.length} error(s)` : 'Healthy'));

        html += `<div class="h-card ${cls}">
            <div class="h-card-title">${icon} ${c.toUpperCase()}</div>
            <div class="h-card-value">${status}</div>
            <div class="h-card-sub">${rate.count || 0}/${rate.limit || '?'} req/min used</div>
        </div>`;
    });

    document.getElementById('statusCards').innerHTML = html;
}

function renderTokens(tokens) {
    if (!tokens) return;
    let html = '';
    const labels = {
        pathao_shipping: 'Pathao Shipping Token',
        pathao_fraud: 'Pathao Fraud Check Token',
        redx_fraud: 'RedX Fraud Check Token',
        steadfast: 'Steadfast API Key',
        redx_shipping: 'RedX Shipping Token',
    };

    Object.entries(tokens).forEach(([key, tok]) => {
        const label = labels[key] || key;
        const status = tok.status || 'unknown';
        const dotCls = status === 'active' ? 'active' : (status === 'configured' ? 'configured' : (status === 'expired' ? 'expired' : 'not_set'));
        const statusText = status === 'active' ? `Active (${tok.ttl_min || 0} min left)` :
                          status === 'configured' ? 'Configured ✓' :
                          status === 'expired' ? 'Expired ⚠️' :
                          status === 'not set' ? 'Not Set' : status;
        const type = tok.type || '';

        html += `<div class="token-row">
            <div class="token-dot ${dotCls}"></div>
            <div style="flex:1;">
                <div style="font-weight:600; font-size:13px; color:#1e293b;">${label}</div>
                <div style="font-size:12px; color:#64748b;">${type}</div>
            </div>
            <div style="font-size:13px; font-weight:500; color:${dotCls === 'active' || dotCls === 'configured' ? '#16a34a' : '#dc2626'};">${statusText}</div>
        </div>`;
    });

    document.getElementById('tokenStatus').innerHTML = html;
}

function renderRateMeters(rates) {
    if (!rates) return;
    let html = '';
    Object.entries(rates).forEach(([courier, r]) => {
        const pct = r.pct || 0;
        const fillCls = pct < 50 ? 'low' : (pct < 80 ? 'mid' : 'high');
        const cardCls = pct < 50 ? 'ok' : (pct < 80 ? 'warn' : 'err');

        html += `<div class="h-card ${cardCls}">
            <div class="h-card-title">${courier.toUpperCase()}</div>
            <div class="h-card-value">${r.count || 0} <span style="font-size:14px;font-weight:400;color:#64748b;">/ ${r.limit} req/min</span></div>
            <div class="rate-bar"><div class="rate-fill ${fillCls}" style="width:${Math.min(pct,100)}%"></div></div>
            <div class="h-card-sub">${r.remaining || 0} remaining · Window: ${r.window || '?'}</div>
        </div>`;
    });

    document.getElementById('rateMeters').innerHTML = html;
}

function renderErrorLog(errors) {
    const entries = errors?.entries || [];
    const el = document.getElementById('errorLog');

    if (!errors?.exists) {
        el.innerHTML = `<div class="empty-state"><div class="icon">✅</div><div style="font-size:16px; font-weight:600; color:#1e293b;">No Error Log File</div><div style="margin-top:6px;">This means no API errors have occurred yet. Good news!</div></div>`;
        return;
    }
    if (entries.length === 0) {
        el.innerHTML = `<div class="empty-state"><div class="icon">📭</div><div style="font-size:16px; font-weight:600; color:#1e293b;">Log is Empty</div><div style="margin-top:6px;">No API errors recorded. All systems working.</div></div>`;
        return;
    }

    let html = `<table class="err-table">
        <thead><tr><th style="width:140px;">Time</th><th style="width:90px;">Courier</th><th style="width:60px;">Code</th><th>What Happened & How to Fix</th></tr></thead><tbody>`;

    entries.forEach(e => {
        const sevCls = 'sev-' + (e.severity || 'warning');
        const cbCls = 'cb-' + (e.courier || 'unknown');
        const timeAgo = getTimeAgo(e.time);

        html += `<tr>
            <td><div style="font-weight:500;">${e.time || '?'}</div><div style="font-size:11px;color:#94a3b8;">${timeAgo}</div></td>
            <td><span class="cb ${cbCls}">${(e.courier || '?').toUpperCase()}</span></td>
            <td><span class="sev ${sevCls}">${e.code}</span></td>
            <td>
                <div style="font-weight:500; color:#1e293b; margin-bottom:4px;">${escHtml(e.reason || 'Unknown error')}</div>
                ${e.fix ? `<div class="fix-box">💡 <strong>Fix:</strong> ${escHtml(e.fix)}</div>` : ''}
                ${e.endpoint ? `<div style="font-size:11px; color:#94a3b8; margin-top:4px; font-family:monospace;">${escHtml(e.endpoint)}</div>` : ''}
                ${e.response && e.response.length > 5 ? `<details style="margin-top:4px;"><summary style="font-size:11px;color:#94a3b8;cursor:pointer;">Raw response</summary><pre style="font-size:11px;background:#f8fafc;padding:8px;border-radius:4px;margin-top:4px;overflow-x:auto;white-space:pre-wrap;">${escHtml(e.response)}</pre></details>` : ''}
            </td>
        </tr>`;
    });

    html += '</tbody></table>';
    html += `<div style="padding:10px 16px;font-size:12px;color:#94a3b8;border-top:1px solid #f1f5f9;">Showing ${entries.length} of ${errors.total_lines || '?'} entries · Log size: ${formatBytes(errors.size || 0)}</div>`;
    el.innerHTML = html;
}

function renderCaches(caches) {
    const el = document.getElementById('cacheStatus');
    if (!caches || caches.length === 0) {
        el.innerHTML = '<div style="color:#94a3b8; padding:10px;">No active caches</div>';
        return;
    }

    let html = '<div style="display:grid; gap:6px;">';
    caches.forEach(c => {
        const isActive = c.status === 'active';
        html += `<div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #f1f5f9;">
            <div class="token-dot ${isActive ? 'active' : 'expired'}"></div>
            <div style="flex:1;font-size:13px;font-family:monospace;color:#475569;">${escHtml(c.key)}</div>
            <div style="font-size:12px;color:${isActive ? '#16a34a' : '#dc2626'};">${isActive ? c.ttl_min + ' min left' : 'expired'}</div>
        </div>`;
    });
    html += '</div>';
    el.innerHTML = html;
}

// Live API Tests
async function runTests() {
    const btn = event.target;
    btn.innerHTML = '<span class="spinner"></span> Testing...';
    btn.disabled = true;

    try {
        const resp = await fetch(`${API_BASE}?action=test`);
        const results = await resp.json();

        let msg = [];
        Object.entries(results).forEach(([courier, r]) => {
            const icon = r.status === 'ok' ? '✅' : (r.status === 'not_configured' ? '⚪' : '❌');
            msg.push(`${icon} ${courier.toUpperCase()}: ${r.detail || r.status} (${r.ms}ms)`);
        });

        showToast(msg.join('\n'));
        loadData(); // Refresh dashboard
    } catch (e) {
        showToast('Test failed: ' + e.message, true);
    } finally {
        btn.innerHTML = '⚡ Test All APIs';
        btn.disabled = false;
    }
}

// Debug Report
async function generateReport() {
    const btn = event.target;
    btn.innerHTML = '<span class="spinner"></span> Generating...';
    btn.disabled = true;

    try {
        const resp = await fetch(`${API_BASE}?action=debug_report`);
        const data = await resp.json();
        document.getElementById('debugReport').textContent = data.report;
        document.getElementById('debugSection').style.display = 'block';
        document.getElementById('debugSection').scrollIntoView({ behavior: 'smooth' });

        // Auto-copy
        await navigator.clipboard.writeText(data.report);
        showToast('📋 Debug report copied to clipboard! Paste it to your developer.');
    } catch (e) {
        showToast('Failed: ' + e.message, true);
    } finally {
        btn.innerHTML = '📋 Copy Debug Report';
        btn.disabled = false;
    }
}

async function copyReport() {
    const text = document.getElementById('debugReport').textContent;
    try {
        await navigator.clipboard.writeText(text);
        showToast('📋 Copied to clipboard!');
    } catch (e) {
        // Fallback
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('📋 Copied!');
    }
}

async function clearLog() {
    if (!confirm('Clear all API error logs?')) return;
    try {
        await fetch(`${API_BASE}?action=clear_log`);
        showToast('🗑️ Error log cleared');
        loadData();
    } catch (e) {
        showToast('Failed: ' + e.message, true);
    }
}

// Helpers
function getTimeAgo(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T'));
    const diff = (Date.now() - d) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
    if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
    return Math.floor(diff / 86400) + ' days ago';
}

function formatBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(1) + ' MB';
}

function escHtml(s) {
    const el = document.createElement('span');
    el.textContent = s;
    return el.innerHTML;
}

function showToast(msg, isError = false) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.style.background = isError ? '#ef4444' : '#1e293b';
    el.style.display = 'block';
    el.style.whiteSpace = 'pre-line';
    setTimeout(() => { el.style.display = 'none'; }, isError ? 5000 : 3000);
}

// Auto-load
loadData();
// Auto-refresh every 30 seconds
setInterval(loadData, 30000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
