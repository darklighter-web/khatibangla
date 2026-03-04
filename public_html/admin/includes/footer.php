        </main>
    </div>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('-translate-x-full');
    overlay.classList.toggle('hidden');
}

// Close dropdowns on outside click
document.addEventListener('click', function(e) {
    document.querySelectorAll('.relative .absolute').forEach(d => {
        if (!d.parentElement.contains(e.target)) d.classList.add('hidden');
    });
});

// Toast notification
function showToast(msg, type = 'success') {
    const toast = document.createElement('div');
    const colors = { success: 'bg-green-500', error: 'bg-red-500', info: 'bg-blue-500', warning: 'bg-yellow-500' };
    toast.className = `fixed bottom-4 right-4 ${colors[type] || colors.info} text-white px-6 py-3 rounded-lg shadow-lg z-[60] transition-all transform translate-y-2 opacity-0`;
    toast.textContent = msg;
    document.body.appendChild(toast);
    requestAnimationFrame(() => { toast.classList.remove('translate-y-2', 'opacity-0'); });
    setTimeout(() => {
        toast.classList.add('translate-y-2', 'opacity-0');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Confirm delete
function confirmDelete(url, name) {
    if (confirm('Are you sure you want to delete "' + name + '"? This action cannot be undone.')) {
        window.location.href = url;
    }
}

// AJAX helper
async function apiCall(url, data = null, method = 'POST') {
    const opts = { method, headers: { 'Content-Type': 'application/json' } };
    if (data) opts.body = JSON.stringify(data);
    const res = await fetch(url, opts);
    return res.json();
}

// Format currency
function formatPrice(amount) {
    return '৳' + parseFloat(amount).toLocaleString('en-BD');
}

// ═══════════════════════════════════════════════════════════
// KBDatePicker — Reusable Date Range Picker Component
// Usage: <div data-kb-datepicker data-from-param="from" data-to-param="to"></div>
// ═══════════════════════════════════════════════════════════
(function(){
    const pickers = document.querySelectorAll('[data-kb-datepicker]');
    if (!pickers.length) return;

    // Inject styles once
    const style = document.createElement('style');
    style.textContent = `
    .kbdp{position:relative;display:inline-block;font-family:inherit}
    .kbdp-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:500;color:#334155;cursor:pointer;transition:all .15s;white-space:nowrap}
    .kbdp-btn:hover{border-color:#94a3b8;background:#f8fafc}
    .kbdp-btn.active{border-color:#0d9488;box-shadow:0 0 0 2px rgba(13,148,136,.15)}
    .kbdp-btn svg{width:16px;height:16px;color:#64748b;flex-shrink:0}
    .kbdp-btn .kbdp-chevron{width:12px;height:12px;transition:transform .2s}
    .kbdp-btn.active .kbdp-chevron{transform:rotate(180deg)}
    .kbdp-drop{position:absolute;right:0;top:calc(100% + 6px);width:240px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.12);z-index:50;padding:8px 0;display:none;max-height:480px;overflow-y:auto}
    .kbdp-drop.show{display:block;animation:kbdpIn .15s ease}
    @keyframes kbdpIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
    .kbdp-section{padding:4px 14px;font-size:10px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;margin-top:4px}
    .kbdp-item{display:flex;align-items:center;gap:10px;padding:9px 14px;font-size:13px;color:#475569;cursor:pointer;transition:background .1s}
    .kbdp-item:hover{background:#f0fdfa}
    .kbdp-item.selected{background:#f0fdfa;color:#0d9488;font-weight:600}
    .kbdp-item.selected::after{content:'';margin-left:auto;width:20px;height:20px;border-radius:50%;background:#0d9488;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='white'%3E%3Cpath fill-rule='evenodd' d='M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z'/%3E%3C/svg%3E");background-size:14px;background-position:center;background-repeat:no-repeat}
    .kbdp-item svg{width:16px;height:16px;color:#94a3b8;flex-shrink:0}
    .kbdp-item.selected svg{color:#0d9488}
    .kbdp-sep{height:1px;background:#f1f5f9;margin:4px 0}
    .kbdp-custom{padding:10px 14px}
    .kbdp-custom label{display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:4px}
    .kbdp-custom input[type=date]{width:100%;padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;color:#334155;margin-bottom:8px}
    .kbdp-custom input[type=date]:focus{outline:none;border-color:#0d9488;box-shadow:0 0 0 2px rgba(13,148,136,.12)}
    .kbdp-custom .kbdp-apply{width:100%;padding:7px;background:#0d9488;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s}
    .kbdp-custom .kbdp-apply:hover{background:#0f766e}
    `;
    document.head.appendChild(style);

    const today = new Date(); today.setHours(0,0,0,0);
    const fmt = d => d.toISOString().slice(0,10);
    const fmtLabel = d => d.toLocaleDateString('en-GB',{day:'numeric',month:'short'});
    const fmtLabelFull = d => d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'});

    function daysAgo(n){const d=new Date(today);d.setDate(d.getDate()-n);return d;}
    function monthStart(offset=0){const d=new Date(today.getFullYear(),today.getMonth()+offset,1);return d;}
    function monthEnd(offset=0){const d=new Date(today.getFullYear(),today.getMonth()+1+offset,0);return d;}
    function yearStart(offset=0){return new Date(today.getFullYear()+offset,0,1);}
    function yearEnd(offset=0){return new Date(today.getFullYear()+offset,11,31);}

    const presets = [
        {section:'Quick Select'},
        {key:'today',label:'Today',icon:'clock',from:()=>today,to:()=>today},
        {key:'yesterday',label:'Yesterday',icon:'clock',from:()=>daysAgo(1),to:()=>daysAgo(1)},
        {key:'7d',label:'Last 7 days',icon:'calendar',from:()=>daysAgo(6),to:()=>today},
        {key:'30d',label:'Last 30 days',icon:'calendar',from:()=>daysAgo(29),to:()=>today},
        {section:'Extended Range'},
        {key:'this_month',label:'This month',icon:'calendar',from:()=>monthStart(0),to:()=>today},
        {key:'last_month',label:'Last month',icon:'calendar',from:()=>monthStart(-1),to:()=>monthEnd(-1)},
        {key:'this_year',label:'This year',icon:'calendar',from:()=>yearStart(0),to:()=>today},
        {key:'last_year',label:'Last year',icon:'calendar',from:()=>yearStart(-1),to:()=>yearEnd(-1)},
        {key:'lifetime',label:'Lifetime',icon:'sparkles',from:()=>new Date(2020,0,1),to:()=>today},
    ];

    const icons = {
        clock:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        calendar:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        sparkles:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8z"/></svg>',
        date:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><rect x="7" y="14" width="3" height="3" rx=".5"/></svg>'
    };

    pickers.forEach(el => {
        const fromParam = el.dataset.fromParam || 'from';
        const toParam = el.dataset.toParam || 'to';
        const preserveParams = (el.dataset.preserveParams || '').split(',').filter(Boolean);
        const formId = el.dataset.formId || '';

        // Read current values from URL
        const urlP = new URLSearchParams(window.location.search);
        let curFrom = urlP.get(fromParam) || '';
        let curTo = urlP.get(toParam) || '';

        // Detect which preset matches
        function detectPreset(){
            if(!curFrom && !curTo) return 'today';
            for(const p of presets){
                if(!p.key) continue;
                const pf=fmt(p.from()), pt=fmt(p.to());
                if(curFrom===pf && curTo===pt) return p.key;
            }
            return 'custom';
        }
        let activeKey = detectPreset();

        function getLabel(){
            const p = presets.find(x=>x.key===activeKey);
            if(p) return p.label;
            if(curFrom && curTo){
                const df=new Date(curFrom+'T00:00:00'), dt=new Date(curTo+'T00:00:00');
                if(curFrom===curTo) return fmtLabel(df);
                return fmtLabel(df)+' – '+fmtLabel(dt);
            }
            if(curFrom) return 'From '+fmtLabel(new Date(curFrom+'T00:00:00'));
            return 'Today';
        }

        // Build DOM
        el.classList.add('kbdp');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'kbdp-btn';
        btn.innerHTML = `${icons.calendar}<span class="kbdp-label">${getLabel()}</span><svg class="kbdp-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>`;

        const drop = document.createElement('div');
        drop.className = 'kbdp-drop';

        // Build items
        let html = '';
        for(const p of presets){
            if(p.section){html += `<div class="kbdp-section">${p.section}</div>`;continue;}
            const sel = activeKey===p.key ? ' selected' : '';
            html += `<div class="kbdp-item${sel}" data-key="${p.key}">${icons[p.icon]||''}<span>${p.label}</span></div>`;
        }
        html += '<div class="kbdp-sep"></div>';
        html += '<div class="kbdp-section">Custom</div>';
        html += `<div class="kbdp-item" data-key="pick_date">${icons.date}<span>Select a date</span></div>`;
        html += `<div class="kbdp-item" data-key="custom_range">${icons.calendar}<span>Custom range</span></div>`;
        // Custom date panel (hidden)
        html += `<div class="kbdp-custom" id="kbdp-single-${fromParam}" style="display:none">
            <label>Date</label><input type="date" class="kbdp-date-single" value="${curFrom||fmt(today)}">
            <button type="button" class="kbdp-apply" data-action="apply-single">Apply</button></div>`;
        html += `<div class="kbdp-custom" id="kbdp-range-${fromParam}" style="display:none">
            <label>From</label><input type="date" class="kbdp-date-from" value="${curFrom||fmt(today)}">
            <label>To</label><input type="date" class="kbdp-date-to" value="${curTo||fmt(today)}">
            <button type="button" class="kbdp-apply" data-action="apply-range">Apply</button></div>`;

        drop.innerHTML = html;
        el.appendChild(btn);
        el.appendChild(drop);

        // Toggle
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const isOpen = drop.classList.contains('show');
            // Close all other pickers
            document.querySelectorAll('.kbdp-drop.show').forEach(d=>d.classList.remove('show'));
            document.querySelectorAll('.kbdp-btn.active').forEach(b=>b.classList.remove('active'));
            if(!isOpen){drop.classList.add('show');btn.classList.add('active');}
        });

        // Navigate
        function navigate(from, to){
            if(formId){
                // Form mode: set hidden inputs and submit
                const form = document.getElementById(formId);
                if(form){
                    let fi = form.querySelector(`[name="${fromParam}"]`);
                    let ti = form.querySelector(`[name="${toParam}"]`);
                    if(fi) fi.value = from; else { fi=document.createElement('input');fi.type='hidden';fi.name=fromParam;fi.value=from;form.appendChild(fi); }
                    if(ti) ti.value = to; else { ti=document.createElement('input');ti.type='hidden';ti.name=toParam;ti.value=to;form.appendChild(ti); }
                    form.submit(); return;
                }
            }
            // URL mode
            const u = new URLSearchParams(window.location.search);
            u.set(fromParam, from);
            u.set(toParam, to);
            // Remove period/range params if exists (override old navigation)
            u.delete('period');
            u.delete('range');
            u.delete('page');
            window.location.search = u.toString();
        }

        // Item clicks
        drop.addEventListener('click', e => {
            const item = e.target.closest('.kbdp-item');
            const applyBtn = e.target.closest('.kbdp-apply');

            if(item){
                const key = item.dataset.key;
                // Hide custom panels
                drop.querySelectorAll('.kbdp-custom').forEach(c=>c.style.display='none');

                if(key==='pick_date'){
                    drop.querySelector(`#kbdp-single-${fromParam}`).style.display='block';
                    return;
                }
                if(key==='custom_range'){
                    drop.querySelector(`#kbdp-range-${fromParam}`).style.display='block';
                    return;
                }
                const p = presets.find(x=>x.key===key);
                if(p) navigate(fmt(p.from()), fmt(p.to()));
            }

            if(applyBtn){
                const action = applyBtn.dataset.action;
                if(action==='apply-single'){
                    const v = drop.querySelector('.kbdp-date-single').value;
                    if(v) navigate(v, v);
                }
                if(action==='apply-range'){
                    const f = drop.querySelector('.kbdp-date-from').value;
                    const t = drop.querySelector('.kbdp-date-to').value;
                    if(f && t) navigate(f, t);
                }
            }
        });

        // Close on outside click
        document.addEventListener('click', e => {
            if(!el.contains(e.target)){drop.classList.remove('show');btn.classList.remove('active');}
        });
    });
})();
</script>
<script>
// ═══════ Global Chart Scale (reads localStorage, applies via CSS) ═══════
(function(){
    const SCALE = {S:0.6, M:0.8, L:1.0};
    const saved = localStorage.getItem('kb_chart_scale') || 'M';
    const scale = SCALE[saved] || 0.8;
    const style = document.createElement('style');
    style.textContent = `
        canvas[height] { max-height: ${Math.round(150 * scale)}px !important; }
        [data-chart-h] { height: ${Math.round(160 * scale)}px !important; }
        [data-doughnut] { width: ${Math.round(160 * scale)}px !important; height: ${Math.round(160 * scale)}px !important; }
    `;
    document.head.appendChild(style);
})();
</script>
</body>
</html>
