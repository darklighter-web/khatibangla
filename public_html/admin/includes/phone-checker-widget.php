<?php
/**
 * Phone Check Widget v13 — Minimal design matching courier cards style
 * Include: <?php include __DIR__ . '/../includes/phone-checker-widget.php'; ?>
 */
$siteUrl = defined('SITE_URL') ? SITE_URL : '';
?>
<style>
#pcWidget{display:none;margin-bottom:16px;animation:pcSlide .3s ease}
@keyframes pcSlide{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
.pc-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
@media(max-width:900px){.pc-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.pc-grid{grid-template-columns:repeat(2,1fr)}}
.pc-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;display:flex;flex-direction:column;min-height:130px}
.pc-card-h{font-weight:700;font-size:13px;color:#374151;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between}
.pc-rate{font-size:14px;font-weight:700;margin-bottom:6px}
.pc-rate.g{color:#16a34a}.pc-rate.y{color:#ca8a04}.pc-rate.r{color:#dc2626}.pc-rate.b{color:#3b82f6}.pc-rate.x{color:#9ca3af}
.pc-line{font-size:12px;color:#6b7280;line-height:1.6}
.pc-line b{color:#374151}
.pc-bar{height:4px;background:#e5e7eb;border-radius:2px;margin-top:auto;padding-top:8px}
.pc-bar-f{height:4px;border-radius:2px;transition:width .5s ease}
.pc-bar-f.g{background:#22c55e}.pc-bar-f.y{background:#eab308}.pc-bar-f.r{background:#ef4444}.pc-bar-f.b{background:#3b82f6}
.pc-badge{font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600}
.pc-badge.g{background:#dcfce7;color:#166534}.pc-badge.y{background:#fef9c3;color:#854d0e}.pc-badge.r{background:#fee2e2;color:#991b1b}.pc-badge.b{background:#dbeafe;color:#1e40af}
.pc-fill{display:block;margin-top:8px;padding:5px;background:#e5e7eb;color:#374151;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;text-align:center}
.pc-fill:hover{background:#d1d5db}
.pc-note{font-size:10px;color:#9ca3af;font-style:italic;margin-top:4px}
.pc-loading{text-align:center;padding:14px;color:#9ca3af;font-size:13px}
</style>

<div id="pcWidget"><div id="pcContent"></div></div>

<script>
(function(){
    const FAPI = '<?= $siteUrl ?>/api/fraud-checker.php';
    let lastChecked = '', debounceTimer = null;

    function findPhoneInput() {
        const sels = ['input[name="customer_phone"]','input[name="phone"]','input[name="mobile"]','input[name="recipient_phone"]','input[type="tel"]','input[placeholder*="phone" i]','input[placeholder*="mobile" i]','input[placeholder*="নম্বর"]'];
        for (const s of sels) { const el = document.querySelector(s); if (el) return el; }
        return null;
    }

    function rc(rate) { return rate >= 70 ? 'g' : rate >= 40 ? 'y' : 'r'; }

    function courierCard(title, data) {
        const t = parseInt(data.total||0);
        const s = parseInt(data.success||0);
        const ca = parseInt(data.cancel||0);
        const rate = t > 0 ? Math.round((s/t)*100) : 0;
        const c = t > 0 ? rc(rate) : 'b';
        const cr = data.customer_rating;
        const isCross = (data.source||'').includes('cross-merchant');
        const hasApi = !data.error && (cr || t > 0);
        const isFraud = data.is_fraud || (parseInt(data.fraud_count||0) > 0);

        let badge = '';
        if (isFraud) {
            badge = `<span class="pc-badge r" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;animation:pulse 2s infinite">⚠ FRAUD (${data.fraud_count||'!'})</span>`;
        } else if (cr) {
            const m = {excellent_customer:['Excellent','g'],good_customer:['Good','g'],moderate_customer:['Moderate','y'],risky_customer:['Risky','r'],new_customer:['New','b']};
            const [l,cls] = m[cr] || [cr,'x'];
            badge = `<span class="pc-badge ${cls}">${l}</span>`;
        } else if (hasApi) {
            badge = '<span style="font-size:10px;color:#9ca3af">✓API</span>';
        }

        let note = '';
        if (isFraud && data.fraud_reports && data.fraud_reports.length > 0) {
            const rep = data.fraud_reports[0];
            const reason = rep.reason || rep.note || rep.description || rep.message || 'Fraud reported';
            note = `<div class="pc-note" style="color:#dc2626;font-weight:600">${reason.substring(0,60)}</div>`;
        } else if (data.api_note) note = `<div class="pc-note">${data.api_note.substring(0,50)}</div>`;
        else if (isCross) note = '<div class="pc-note">Cross-merchant data</div>';

        let body = '';
        if (t > 0) {
            body = `<div class="pc-rate ${c}">Success Rate: ${rate}%</div>
                <div class="pc-line">Total: <b>${t}</b></div>
                <div class="pc-line">Success: <b>${s}</b></div>
                <div class="pc-line">Cancelled: <b>${ca}</b></div>`;
        } else if (cr) {
            body = '<div class="pc-rate b">Rating only</div>';
        } else if (data.error) {
            body = `<div class="pc-rate x">Unavailable</div><div class="pc-note">${data.error.substring(0,50)}</div>`;
        } else {
            body = '<div class="pc-rate x">No data</div>';
        }

        return `<div class="pc-card">
            <div class="pc-card-h">${title} ${badge}</div>
            ${body}${note}
            <div class="pc-bar"><div class="pc-bar-f ${c}" style="width:${t>0?rate:0}%"></div></div>
        </div>`;
    }

    function checkPhone(phone) {
        phone = phone.replace(/\D/g, '');
        if (phone.length < 11) { document.getElementById('pcWidget').style.display = 'none'; lastChecked = ''; return; }
        if (phone.startsWith('88')) phone = phone.substring(2);
        if (phone.length === 10 && phone[0] !== '0') phone = '0' + phone;
        if (phone.length !== 11 || !phone.match(/^01[3-9]/)) return;
        if (phone === lastChecked) return;
        lastChecked = phone;

        const w = document.getElementById('pcWidget');
        const ct = document.getElementById('pcContent');
        w.style.display = 'block';
        ct.innerHTML = '<div class="pc-loading">Checking ' + phone + '...</div>';

        fetch(FAPI + '?phone=' + encodeURIComponent(phone))
        .then(r => r.json())
        .then(j => {
            if (!j.success) { ct.innerHTML = '<div style="color:#dc2626;padding:8px">' + (j.error||'Failed') + '</div>'; return; }

            const co = j.combined || {};
            const l = j.local || {};
            const rate = parseInt(co.rate||0);
            const c = rc(rate);
            const oTotal = parseInt(co.total||0), oSuccess = parseInt(co.success||0), oCancel = parseInt(co.cancel||0);

            // Check if any courier has fraud report
            const anyFraud = (j.steadfast||{}).is_fraud || (j.pathao||{}).is_fraud || (j.redx||{}).is_fraud;
            const fraudLabel = anyFraud ? '<span class="pc-badge r" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5">⚠ FRAUD</span>' : '';

            let overallCard = `<div class="pc-card"${anyFraud?' style="border:2px solid #fca5a5;background:#fef2f2"':''}>
                <div class="pc-card-h">Overall <span class="pc-badge ${c}">${co.risk_label||co.risk||'New'}</span> ${fraudLabel}</div>
                ${oTotal > 0 ? `
                    <div class="pc-rate ${c}">Success Rate: ${rate}%</div>
                    <div class="pc-line">Total: <b>${oTotal}</b></div>
                    <div class="pc-line">Success: <b>${oSuccess}</b></div>
                    <div class="pc-line">Cancelled: <b>${oCancel}</b></div>
                ` : '<div class="pc-rate b">New Customer</div>'}
                <div class="pc-bar"><div class="pc-bar-f ${c}" style="width:${rate}%"></div></div>
            </div>`;

            const lt = parseInt(l.total||0), ld = parseInt(l.delivered||0), lc = parseInt(l.cancelled||0);
            const lw = parseInt(l.web_cancel||0);
            const lRate = lt > 0 ? Math.round(ld/lt*100) : 0;
            const lC = lt > 0 ? rc(lRate) : 'b';
            let localCard = `<div class="pc-card">
                <div class="pc-card-h">Our Record</div>
                ${lt > 0 ? `
                    <div class="pc-rate ${lC}">Total: <b>${lt}</b></div>
                    <div class="pc-line">Cancelled: <b>${lc}</b></div>
                    <div class="pc-line">Web Order Cancel: <b>${lw}</b></div>
                    <div class="pc-line">Total Spent: ৳${parseFloat(l.total_spent||0).toLocaleString()}</div>
                ` : '<div class="pc-rate b">New Customer</div>'}
                <button class="pc-fill" onclick="pcFill(this)">Fill</button>
                <div class="pc-bar"><div class="pc-bar-f ${lC}" style="width:${lt>0?lRate:0}%"></div></div>
            </div>`;

            ct.innerHTML = `<div class="pc-grid">
                ${overallCard}
                ${courierCard('Pathao', j.pathao||{})}
                ${courierCard('RedX', j.redx||{})}
                ${courierCard('Steadfast', j.steadfast||{})}
                ${localCard}
            </div>`;

            window._pcLocalData = l;
        })
        .catch(e => { ct.innerHTML = '<div style="color:#dc2626;padding:8px">' + e.message + '</div>'; });
    }

    window.pcFill = function(btn) {
        const d = window._pcLocalData;
        if (!d || !d.areas || d.areas.length === 0) { btn.textContent = 'No data'; setTimeout(()=>btn.textContent='Fill',1500); return; }
        const fields = {customer_name:'name',customer_address:'address',customer_district:'district',customer_city:'city'};
        let filled = 0;
        for (const [name] of Object.entries(fields)) {
            const input = document.querySelector(`[name="${name}"]`);
            if (input && d[name]) { input.value = d[name]; input.dispatchEvent(new Event('input',{bubbles:true})); input.dispatchEvent(new Event('change',{bubbles:true})); filled++; }
        }
        btn.textContent = filled > 0 ? 'Filled' : 'No fields';
        btn.style.background = '#bbf7d0';
        setTimeout(()=>{btn.textContent='Fill';btn.style.background='#e5e7eb'},1500);
    };

    function attach() {
        const input = findPhoneInput();
        if (!input) { setTimeout(attach, 1000); return; }
        if (input.dataset.pcAttached) return;
        input.dataset.pcAttached = '1';
        const section = input.closest('form') || input.closest('.card') || input.closest('section') || input.parentNode.parentNode;
        if (section && section.parentNode) section.parentNode.insertBefore(document.getElementById('pcWidget'), section);
        input.addEventListener('input', function() { clearTimeout(debounceTimer); debounceTimer = setTimeout(() => checkPhone(this.value), 400); });
        input.addEventListener('change', function() { checkPhone(this.value); });
        if (input.value.replace(/\D/g,'').length >= 11) checkPhone(input.value);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach);
    else attach();
})();
</script>
