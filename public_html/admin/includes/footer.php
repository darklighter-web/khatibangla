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
<!-- ══════ Floating Page Guide Button (fixed, independent of layout) ══════ -->
<button onclick="openPageGuide()" id="pageGuideBtn" style="position:fixed;bottom:24px;right:24px;z-index:9990;width:44px;height:44px;border-radius:50%;background:#3b82f6;color:#fff;border:none;cursor:pointer;box-shadow:0 4px 14px rgba(59,130,246,0.4);display:flex;align-items:center;justify-content:center;transition:all 0.2s" onmouseover="this.style.transform='scale(1.1)';this.style.boxShadow='0 6px 20px rgba(59,130,246,0.5)'" onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 4px 14px rgba(59,130,246,0.4)'" title="পেইজ গাইড">
    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M12 18h.01"/></svg>
</button>

<!-- ══════ Page Guide Modal (Employee Training) ══════ -->
<div id="pageGuideModal" class="fixed inset-0 z-[9999] hidden bg-black/50 flex items-center justify-center" onclick="if(event.target===this)closePageGuide()">
    <div class="bg-white rounded-2xl shadow-2xl w-[95vw] max-w-xl max-h-[85vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between px-6 py-4 border-b bg-gradient-to-r from-blue-600 to-blue-700 shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                </div>
                <div>
                    <h3 class="font-bold text-white text-sm" id="guideTitle">পেইজ গাইড</h3>
                    <p class="text-blue-200 text-[11px]">কর্মী প্রশিক্ষণ গাইড</p>
                </div>
            </div>
            <button onclick="closePageGuide()" class="text-white/70 hover:text-white text-xl leading-none p-1">&times;</button>
        </div>
        <div class="flex-1 overflow-y-auto px-6 py-5" id="guideBody">
            <div class="text-center text-gray-400 py-8">লোড হচ্ছে...</div>
        </div>
        <div class="px-6 py-3 border-t bg-gray-50 shrink-0 flex items-center justify-between">
            <p class="text-[10px] text-gray-400">খাটিবাংলা অ্যাডমিন · প্রশিক্ষণ গাইড</p>
            <button onclick="closePageGuide()" class="px-4 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-300 transition">বুঝেছি</button>
        </div>
    </div>
</div>
<script>
function openPageGuide(){
    document.getElementById('pageGuideModal').classList.remove('hidden');
    var page = '<?= basename($_SERVER['PHP_SELF'] ?? '', '.php') ?>';
    var pageLabel = (document.querySelector('h2')?.textContent?.trim() || 'Page');
    document.getElementById('guideTitle').textContent = pageLabel + ' — গাইড';
    var guides = {
        'dashboard': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'ড্যাশবোর্ড হলো আপনার স্টোরের কমান্ড সেন্টার। এখানে আজকের সব তথ্য দেখা যায় — কতটি অর্ডার এসেছে, কত টাকা আয় হয়েছে, এবং কোন অর্ডারগুলো দ্রুত প্রসেস করা দরকার।'},
                {h:'📦 সামারি কার্ড', t:'উপরের কার্ডগুলো আজকের অর্ডার সংখ্যা, রেভিনিউ, Processing/Confirmed/Shipped কাউন্ট দেখায়। যেকোনো কার্ডে ক্লিক করলে সেই অর্ডারগুলোতে সরাসরি যেতে পারবেন।'},
                {h:'📋 সাম্প্রতিক অর্ডার', t:'নিচের টেবিলে সর্বশেষ ১০টি অর্ডার দেখা যায় — স্ট্যাটাস, কাস্টমার নাম, এবং মোট টাকা। অর্ডার নম্বরে ক্লিক করলে বিস্তারিত দেখা যাবে।'},
                {h:'💡 প্রতিদিনের কাজ', t:'প্রতিদিন সকালে প্রথমে ড্যাশবোর্ড চেক করুন। Processing সংখ্যা বেশি থাকলে দ্রুত Order Management-এ গিয়ে অর্ডার কনফার্ম করুন।'},
            ]
        },
        'accounting': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'অ্যাকাউন্টিং ড্যাশবোর্ড আপনার স্টোরের সম্পূর্ণ আর্থিক চিত্র দেখায়। সব সংখ্যা অটোমেটিক ক্যালকুলেট হয় — অর্ডার, খরচ, আয়, এবং দায় থেকে।'},
                {h:'💰 Confirmed Revenue', t:'কনফার্ম হওয়া অর্ডার থেকে মোট আয় (Confirmed + Ready to Ship + Shipped + Delivered)। এটি আপনার প্রতিশ্রুত বিক্রয়।'},
                {h:'✅ Collected (Delivered)', t:'শুধুমাত্র ডেলিভার হওয়া অর্ডার থেকে আসল সংগৃহীত টাকা। COD বিজনেসে এটিই আপনার হাতে আসা টাকা।'},
                {h:'⏳ In Pipeline', t:'কনফার্ম হয়েছে কিন্তু এখনো ডেলিভার হয়নি। এই টাকা পথে আছে।'},
                {h:'❌ Lost Revenue', t:'ক্যান্সেল ও রিটার্ন হওয়া অর্ডার। Success Rate দেখায় কত শতাংশ অর্ডার সফলভাবে ডেলিভার হচ্ছে।'},
                {h:'🏭 COGS', t:'পণ্যের খরচ। FIFO ব্যাচ কস্ট থেকে হিসাব হয়, না থাকলে পণ্যের cost_price ব্যবহার হয়।'},
                {h:'🔄 Fixed Expenses', t:'নিয়মিত খরচ যা প্রতি মাসে/ত্রৈমাসিক/বছরে অটো যোগ হয় (ভাড়া, ইন্টারনেট, বেতন ইত্যাদি)। "Add Fixed Expense" থেকে যোগ করুন। নির্ধারিত তারিখে Expenses-এ অটো তৈরি হয়। Pause/Delete করা যায়। মোট মাসিক খরচ ব্যাজে দেখায়।'},
                {h:'✅ Net Profit', t:'Collected Revenue + Other Income - COGS - Expenses - Ad Spend - Refunds = নিট লাভ। Fixed Expenses অটো Expenses-এ যোগ হয় তাই আলাদা গণনা লাগে না।'},
                {h:'📅 তারিখ', t:'সব হিসাব অর্ডার তৈরির তারিখ অনুযায়ী। বাটন বা কাস্টম তারিখ ব্যবহার করুন।'},
            ]
        },
        'expenses': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'সব ব্যবসায়িক খরচের তালিকা — শিপিং, বেতন, ভাড়া, অফিস সামগ্রী ইত্যাদি। ক্যাটেগরি, পরিমাণ, এবং কে যোগ করেছে দেখা যায়।'},
                {h:'➕ খরচ যোগ করা', t:'"+ New Expense" বাটনে ক্লিক করুন অপারেটিং খরচের জন্য। বিজ্ঞাপন খরচের জন্য "+ New Ad Expense" ব্যবহার করুন।'},
                {h:'🏷️ ক্যাটেগরি', t:'প্রতিটি খরচে ক্যাটেগরি দিন (Shipping, Marketing, Salary ইত্যাদি)। এটি Accounting ড্যাশবোর্ডে খরচের ব্রেকডাউন দেখাতে সাহায্য করে।'},
                {h:'💡 গুরুত্বপূর্ণ', t:'প্রতিদিন খরচ লিখুন — মাসের শেষে করবেন না। এতে Accounting ড্যাশবোর্ড সবসময় সঠিক থাকবে।'},
            ]
        },
        'expense-new': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'নতুন ব্যবসায়িক খরচ যোগ করার ফর্ম। শিপিং, বেতন, ভাড়া, প্যাকেজিং ইত্যাদি।'},
                {h:'✅ প্রয়োজনীয় তথ্য', t:'Title (শিরোনাম), Amount (টাকা), Category (ক্যাটেগরি), এবং Date (তারিখ) অবশ্যই দিতে হবে।'},
                {h:'⚠️ যা এখানে দেবেন না', t:'পণ্য কেনার খরচ → Inventory → Stock In ব্যবহার করুন। বিজ্ঞাপন খরচ → New Ad Expense ব্যবহার করুন।'},
                {h:'🔄 অটো সিঙ্ক', t:'সেভ করলে খরচটি স্বয়ংক্রিয়ভাবে Expenses List এবং Accounting Dashboard-এ দেখাবে।'},
            ]
        },
        'ad-expense-new': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'বিজ্ঞাপন খরচ রেকর্ড করুন — Meta (Facebook/Instagram), Google, TikTok ইত্যাদি প্ল্যাটফর্মে।'},
                {h:'💱 USD / BDT', t:'USD-তে খরচ হলে USD এমাউন্ট ও এক্সচেঞ্জ রেট দিন — BDT অটো ক্যালকুলেট হবে। বা সরাসরি BDT দিন।'},
                {h:'📈 পারফরম্যান্স মেট্রিক্স', t:'Impressions, Clicks, Conversions দিলে CPM, CPC, CPA অটো ক্যালকুলেট হবে।'},
            ]
        },
        'income': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'অর্ডার ছাড়া অন্যান্য আয় রেকর্ড করুন — সার্ভিস ফি, বিনিয়োগ, সাপ্লায়ার রিফান্ড ইত্যাদি।'},
                {h:'⚠️ সতর্কতা', t:'অর্ডার থেকে আয় অটোমেটিক ট্র্যাক হয়। এখানে আবার যোগ করবেন না — তাহলে ডাবল কাউন্ট হবে।'},
                {h:'🔄 কখন ব্যবহার করবেন', t:'সিস্টেমে নেই এমন ক্যাশ সেল, সার্ভিস চার্জ, লোন প্রাপ্তি, বিনিয়োগ রিটার্ন ইত্যাদি।'},
            ]
        },
        'liabilities': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'ব্যবসার সব দায় ট্র্যাক করুন — সাপ্লায়ার বকেয়া, লোন, ভাড়া বকেয়া, বেতন বকেয়া।'},
                {h:'🔄 স্ট্যাটাস', t:'<strong>Pending</strong> = বকেয়া আছে। <strong>Partial</strong> = কিছু দেওয়া হয়েছে। <strong>Paid</strong> = পুরো পরিশোধ। <strong>Overdue</strong> = মেয়াদ শেষ।'},
                {h:'💳 পেমেন্ট করা', t:'"Pay" বাটনে ক্লিক করুন। আংশিক পেমেন্ট সাপোর্ট করে — বাকি ব্যালেন্স অটো ট্র্যাক হয়।'},
                {h:'💡 পরামর্শ', t:'ইনভয়েস পাওয়ার সাথে সাথে এখানে যোগ করুন। Due date দিন যাতে অটো overdue হয়।'},
            ]
        },
        'inventory': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'পণ্যের স্টক ম্যানেজমেন্ট। FIFO (First In, First Out) সিস্টেমে প্রতিটি স্টক ইন-এর খরচ ট্র্যাক হয়।'},
                {h:'📦 Stock Levels', t:'সব পণ্যের বর্তমান স্টক দেখুন। লাল = স্টক নেই, হলুদ = কম স্টক, সবুজ = পর্যাপ্ত স্টক।'},
                {h:'➕ Adjust Stock', t:'Stock In করলে <strong>Cost Per Unit অবশ্যই দিন</strong> — এটি Accounting-এ COGS হিসাবের জন্য জরুরি। Stock Out করলে সবচেয়ে পুরনো ব্যাচ থেকে প্রথমে কাটা হয়।'},
                {h:'🔢 FIFO Batches', t:'প্রতিটি Stock In একটি ব্যাচ তৈরি করে (কস্ট + পরিমাণ সহ)। বিক্রি হলে পুরনো ব্যাচ আগে ব্যবহার হয়। এটি সঠিক COGS দেয়।'},
                {h:'💡 গুরুত্বপূর্ণ', t:'Cost Per Unit সঠিক দিলে Accounting-এ লাভ-ক্ষতির হিসাব সঠিক হবে। ভুল দিলে প্রফিট ভুল দেখাবে।'},
            ]
        },
        'inventory-dashboard': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'ইনভেন্টরির সামগ্রিক অবস্থা — কোন পণ্যে স্টক কম, কোনটিতে নেই, মোট স্টক ভ্যালু কত।'},
                {h:'🔴 মনোযোগ দরকার', t:'এখানে তালিকাভুক্ত পণ্যগুলো রিস্টক করা দরকার। Out of Stock আইটেমগুলো আগে রিস্টক করুন।'},
                {h:'💡 পরামর্শ', t:'প্রতি সপ্তাহে চেক করুন। অনেক পণ্য Low Stock-এ থাকলে সাপ্লায়ারকে বাল্ক অর্ডার দিন।'},
            ]
        },
        'order-management': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'অর্ডার ম্যানেজমেন্ট হলো আপনার প্রধান কর্মক্ষেত্র। এখান থেকে সব অর্ডার কনফার্ম, শিপ, এবং ট্র্যাক করবেন। দিনের বেশিরভাগ কাজ এই পেইজে হবে।'},
                {h:'🔄 অর্ডার স্ট্যাটাস ফ্লো', t:'<strong>Processing</strong> → নতুন অর্ডার এসেছে, যাচাই করা দরকার।<br><strong>Confirmed</strong> → যাচাই শেষ, প্যাক করার জন্য প্রস্তুত।<br><strong>Ready to Ship</strong> → প্যাকিং শেষ, কুরিয়ারের জন্য প্রস্তুত।<br><strong>Shipped</strong> → কুরিয়ারে দেওয়া হয়েছে।<br><strong>Delivered</strong> → কাস্টমার পেয়ে গেছে।<br><strong>On Hold</strong> → সাময়িকভাবে থামানো।<br><strong>No Response</strong> → কাস্টমার ফোন ধরছে না।<br><strong>Cancelled</strong> → বাতিল হয়েছে।<br><strong>Returned</strong> → পণ্য ফেরত এসেছে।'},
                {h:'📑 স্ট্যাটাস ট্যাব', t:'উপরে ট্যাবগুলো ক্লিক করে স্ট্যাটাস অনুযায়ী ফিল্টার করুন। ব্যাজে সংখ্যা দেখায় কতটি অর্ডার সেই স্ট্যাটাসে আছে। ALL ক্লিক করলে সব অর্ডার দেখায়।'},
                {h:'🚚 কুরিয়ার সাব-ট্যাব', t:'কোনো স্ট্যাটাস সিলেক্ট করার পর (যেমন Shipped), কুরিয়ার অনুযায়ী ফিল্টার করুন — Pathao, Steadfast, RedX, বা Unassigned।'},
                {h:'☑️ বাল্ক অ্যাকশন', t:'একাধিক অর্ডার সিলেক্ট করুন (চেকবক্স) → "Actions" ড্রপডাউন ক্লিক করুন → বাল্ক স্ট্যাটাস চেঞ্জ, ইনভয়েস প্রিন্ট, স্টিকার প্রিন্ট, বা কুরিয়ারে আপলোড করুন।'},
                {h:'🔍 সার্চ', t:'অর্ডার নম্বর (যেমন k0003), কাস্টমার নাম, বা ফোন নম্বর দিয়ে সার্চ করুন। "Filters" বাটনে ক্লিক করলে ডেট রেঞ্জ, চ্যানেল, ট্যাগ, ও স্টাফ ফিল্টার পাবেন।'},
                {h:'🟢 Open বাটন', t:'প্রতিটি অর্ডারের পাশে "Open" বাটন আছে। ক্লিক করলে অর্ডারের সম্পূর্ণ বিবরণ দেখা যায় — এডিট, স্ট্যাটাস চেঞ্জ, নোট যোগ, কুরিয়ার আপলোড সব সেখানে।'},
                {h:'🔒 অর্ডার লক', t:'যখন আপনি একটি অর্ডার ওপেন করবেন, সেটি লক হয়ে যায় যাতে অন্য কর্মী একই সাথে এডিট করতে না পারে। পেইজ ছেড়ে গেলে অটো আনলক হয়।'},
                {h:'📄 প্রিন্টিং', t:'অর্ডার সিলেক্ট করে Actions → Print Invoice (ইনভয়েস) বা Print Sticker (স্টিকার) ক্লিক করুন। একাধিক অর্ডার একসাথে প্রিন্ট করা যায়।'},
                {h:'🔍 ফ্রড চেক', t:'"Check" বাটনে ক্লিক করে কাস্টমারের ফোন নম্বর দিয়ে ডেলিভারি সাকসেস রেট দেখুন। Pathao, Steadfast, RedX, ও লোকাল ডেটা থেকে তথ্য আসে।'},
                {h:'💡 প্রতিদিনের কাজের ধাপ', t:'<strong>ধাপ ১:</strong> Processing ট্যাব চেক করুন → বৈধ অর্ডার কনফার্ম করুন।<br><strong>ধাপ ২:</strong> Confirmed ট্যাব → কুরিয়ার অ্যাসাইন করুন ও শিপ করুন।<br><strong>ধাপ ৩:</strong> No Response ট্যাব → কাস্টমারকে ফলো আপ করুন।<br><strong>ধাপ ৪:</strong> Returned/Cancelled চেক করুন — প্যাটার্ন দেখুন।'},
            ]
        },
        'order-view': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'একটি অর্ডারের সম্পূর্ণ বিবরণ — কাস্টমার তথ্য, পণ্য, মূল্য, কুরিয়ার ট্র্যাকিং, ও স্ট্যাটাস ইতিহাস।'},
                {h:'✏️ এডিট করা', t:'কাস্টমারের নাম, ফোন, ঠিকানা, নোট সরাসরি পরিবর্তন করা যায়। পরিবর্তন করে Save ক্লিক করুন।'},
                {h:'🔄 স্ট্যাটাস পরিবর্তন', t:'স্ট্যাটাস ড্রপডাউন ব্যবহার করে অর্ডার এগিয়ে নিন (Confirm → Ship → Deliver)। প্রতিটি পরিবর্তন ইতিহাসে রেকর্ড হয়।'},
                {h:'🚚 কুরিয়ার', t:'কুরিয়ার প্রোভাইডার (Pathao, Steadfast, RedX) সিলেক্ট করলে কনসাইনমেন্ট নম্বর ও ট্র্যাকিং লিংক তৈরি হবে।'},
                {h:'📝 তিন ধরনের নোট', t:'<strong>🟠 Shipping Note</strong> — কুরিয়ারে পাঠানো হয়।<br><strong>🔵 Order Note</strong> — ইনভয়েসে প্রিন্ট হয়।<br><strong>🟢 Panel Note</strong> — শুধু অ্যাডমিনের জন্য, কাস্টমার বা কুরিয়ার দেখতে পায় না।'},
                {h:'🔒 লক সিস্টেম', t:'আপনি যখন এই অর্ডার দেখছেন, অন্য কর্মীরা এটি এডিট করতে পারবে না। আপনি চলে গেলে অটো আনলক হবে।'},
            ]
        },
        'order-processing': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'Processing অর্ডারগুলো কার্ড ভিউতে দেখায়। এখান থেকে দ্রুত একটি একটি করে অর্ডার প্রসেস করা যায়।'},
                {h:'▶️ Start Processing', t:'"Start Processing" বাটনে ক্লিক করলে Processing অর্ডারগুলো একটি কিউতে আসবে। একটি শেষ হলে পরেরটি অটো লোড হয়।'},
                {h:'💡 পরামর্শ', t:'অনেক অর্ডার থাকলে এই ভিউ ব্যবহার করুন — দ্রুত কনফার্ম/ক্যান্সেল করা যায়।'},
            ]
        },
        'approved-orders': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'এটি Order Management-এর confirmed স্ট্যাটাসে রিডাইরেক্ট করে।'},
            ]
        },
        'incomplete-orders': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'অসম্পূর্ণ অর্ডারের তালিকা — কাস্টমার চেকআউট শুরু করেছে কিন্তু অর্ডার প্লেস করেনি। ফোন নম্বর, কার্টের আইটেম, ও ডিভাইস তথ্য দেখা যায়।'},
                {h:'📞 ফলো আপ', t:'এই কাস্টমারদের ফোন করে অর্ডার নিশ্চিত করুন। "Convert" বাটনে ক্লিক করলে অসম্পূর্ণ অর্ডার থেকে নতুন অর্ডার তৈরি হয়।'},
                {h:'💡 পরামর্শ', t:'প্রতিদিন চেক করুন। দ্রুত ফলো আপ করলে রিকভারি রেট বেশি হয়।'},
            ]
        },
        'customers': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'সব কাস্টমারের তালিকা — অর্ডার হিস্ট্রি, রেজিস্ট্রেশন স্ট্যাটাস, ও যোগাযোগের তথ্য।'},
                {h:'👤 কাস্টমার ধরন', t:'<strong>Guest</strong> = অ্যাকাউন্ট ছাড়া অর্ডার দিয়েছে। <strong>Registered</strong> = অ্যাকাউন্ট আছে (পাসওয়ার্ড বা Google/Facebook লগইন)।'},
                {h:'📋 প্রোফাইল', t:'কাস্টমারে ক্লিক করলে সম্পূর্ণ অর্ডার হিস্ট্রি, ডেলিভারি সাকসেস রেট, মোট খরচ, ও স্টোর ক্রেডিট দেখা যায়।'},
            ]
        },
        'products': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'স্টোরের সব পণ্যের তালিকা — দাম, স্টক, ক্যাটেগরি, ও স্ট্যাটাস।'},
                {h:'📦 পণ্যের ধরন', t:'<strong>Simple</strong> = একটি আইটেম, একটি দাম। <strong>Variable</strong> = সাইজ/কালার ভ্যারিয়েন্ট আছে। <strong>Bundle</strong> = একাধিক পণ্য একসাথে প্যাকেজ দামে।'},
                {h:'💰 Cost Price', t:'প্রতিটি পণ্যে "Cost Price" ফিল্ড আছে। এটি Accounting-এ COGS ক্যালকুলেশনে ব্যবহার হয়। সঠিক রাখুন।'},
            ]
        },
        'courier': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'কুরিয়ার প্রোভাইডার সেটিংস — API ক্রেডেনশিয়াল, ডিফল্ট সেটিংস, Pathao/Steadfast/RedX কনফিগারেশন।'},
                {h:'🔧 সেটআপ', t:'প্রতিটি কুরিয়ারের API key/secret/token এখানে দিতে হবে। মার্চেন্ট API অ্যাক্সেসের জন্য কুরিয়ার প্রোভাইডারের সাথে যোগাযোগ করুন।'},
            ]
        },
        'settings': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'স্টোরের গ্লোবাল সেটিংস — নাম, যোগাযোগ, লোগো, পেমেন্ট, SEO, ও ফিচার টগল।'},
                {h:'📝 Note Templates', t:'Note Templates ট্যাবে Shipping Note, Order Note, ও Panel Note-এর জন্য আগে থেকে তৈরি টেমপ্লেট সেট করুন। অর্ডার এডিট পেইজে "+" বাটনে ক্লিক করলে এই টেমপ্লেট থেকে বাছাই করা যাবে।'},
                {h:'⚠️ সতর্কতা', t:'এখানে পরিবর্তন করলে পুরো সাইটে প্রভাব পড়বে। সেভ করার আগে দুইবার চেক করুন, বিশেষ করে পেমেন্ট ও শিপিং সেটিংস।'},
            ]
        },
        'reports': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'রিপোর্ট ও অ্যানালিটিক্স হাব। অর্ডার, পণ্য, কর্মী, ও ব্যবসায়িক পারফরম্যান্সের বিস্তারিত রিপোর্ট।'},
            ]
        },
        'blog': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'ব্লগ পোস্ট ম্যানেজ করুন। SEO ও কন্টেন্ট মার্কেটিং-এর জন্য আর্টিকেল তৈরি, এডিট ও পাবলিশ করুন।'},
            ]
        },
        'reviews': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'কাস্টমারের পণ্য রিভিউ ও প্রশ্ন ম্যানেজ করুন। অনুমোদন, রিজেক্ট, বা উত্তর দিন।'},
                {h:'✅ অনুমোদন', t:'নতুন রিভিউ প্রথমে অনুমোদন দরকার। Settings-এ অটো-অনুমোদন চালু করা যায়।'},
            ]
        },
        'coupons': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'ডিসকাউন্ট কুপন তৈরি ও ম্যানেজ করুন — শতাংশ ছাড়, নির্দিষ্ট পরিমাণ, বা ফ্রি শিপিং।'},
            ]
        },
        'security': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'সিকিউরিটি সেটিংস — ফায়ারওয়াল, রেট লিমিটিং, ব্রুট ফোর্স প্রোটেকশন, IP ব্লকিং।'},
                {h:'⚠️ সতর্কতা', t:'ভুল সেটিংস দিলে আপনি নিজেই অ্যাডমিন প্যানেল থেকে লক আউট হতে পারেন। না বুঝলে পরিবর্তন করবেন না।'},
            ]
        },
        'scan-to-update': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'বারকোড/QR কোড স্ক্যান করে অর্ডার স্ট্যাটাস আপডেট করুন। শিপিং বা ডেলিভারি কনফার্ম করতে দ্রুত।'},
            ]
        },
        'call-center': {
            sections: [
                {h:'📊 এই পেইজ কী?', t:'কল সেন্টার ভিউ — কাস্টমারদের ফোন করার জন্য অর্ডার তালিকা। No Response ও Follow Up অর্ডার এখানে দেখুন।'},
            ]
        },
    };
    var g = guides[page];
    var html = '';
    if (g && g.sections) {
        g.sections.forEach(function(s, i){
            html += '<div class="mb-5 pb-4 ' + (i < g.sections.length - 1 ? 'border-b border-gray-100' : '') + '">';
            html += '<h4 class="font-semibold text-gray-800 text-[13px] mb-1.5">' + s.h + '</h4>';
            html += '<p class="text-gray-600 text-[12.5px] leading-[1.8]">' + s.t + '</p>';
            html += '</div>';
        });
    } else {
        html = '<div class="text-center py-10"><div class="text-3xl mb-3">📖</div><p class="text-gray-500 text-sm">এই পেইজের জন্য গাইড এখনো তৈরি হয়নি।</p><p class="text-gray-400 text-xs mt-1">অ্যাডমিনকে জানান এই সেকশনে ট্রেনিং কন্টেন্ট যোগ করতে।</p></div>';
    }
    document.getElementById('guideBody').innerHTML = html;
}
function closePageGuide(){ document.getElementById('pageGuideModal').classList.add('hidden'); }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePageGuide(); });

// ── Global CSRF Token Helper ──
window._csrfToken = function(){ return document.querySelector('meta[name="csrf-token"]')?.content || ''; };
// Auto-inject CSRF token into all fetch() POST requests
(function(){
    const _origFetch = window.fetch;
    window.fetch = function(url, opts) {
        if (opts && opts.method && opts.method.toUpperCase() === 'POST' && opts.body instanceof FormData) {
            if (!opts.body.has('_csrf_token')) opts.body.append('_csrf_token', window._csrfToken());
        }
        return _origFetch.apply(this, arguments);
    };
}());
</script>
</body>
</html>
