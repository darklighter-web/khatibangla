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
<!-- ══════ Page Guide Modal (Employee Training) ══════ -->
<div id="pageGuideModal" class="fixed inset-0 z-[9999] hidden bg-black/50 flex items-center justify-center" onclick="if(event.target===this)closePageGuide()">
    <div class="bg-white rounded-2xl shadow-2xl w-[95vw] max-w-xl max-h-[85vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between px-6 py-4 border-b bg-gradient-to-r from-blue-600 to-blue-700 shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                </div>
                <div>
                    <h3 class="font-bold text-white text-sm" id="guideTitle">Page Guide</h3>
                    <p class="text-blue-200 text-[11px]">Employee Training Guide</p>
                </div>
            </div>
            <button onclick="closePageGuide()" class="text-white/70 hover:text-white text-xl leading-none p-1">&times;</button>
        </div>
        <div class="flex-1 overflow-y-auto px-6 py-5" id="guideBody">
            <div class="text-center text-gray-400 py-8">Loading...</div>
        </div>
        <div class="px-6 py-3 border-t bg-gray-50 shrink-0 flex items-center justify-between">
            <p class="text-[10px] text-gray-400">Khatibangla Admin · Training Guide</p>
            <button onclick="closePageGuide()" class="px-4 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-300 transition">Got it</button>
        </div>
    </div>
</div>
<script>
function openPageGuide(){
    document.getElementById('pageGuideModal').classList.remove('hidden');
    var page = '<?= basename($_SERVER['PHP_SELF'] ?? '', '.php') ?>';
    var pageLabel = (document.querySelector('h2')?.textContent?.trim() || 'Page');
    document.getElementById('guideTitle').textContent = pageLabel;
    var guides = {
        'dashboard': {
            sections: [
                {h:'📊 What is this page?', t:'The Dashboard is your store\'s command center. It shows a real-time snapshot of today\'s performance — orders received, revenue earned, and which orders need attention.'},
                {h:'📦 Summary Cards', t:'The top row shows today\'s total orders, revenue, and counts by status (Processing, Confirmed, Shipped). Click any card to jump directly to those orders.'},
                {h:'📋 Recent Orders', t:'The table below shows the latest 10 orders with status badges, customer info, and amount. Click any order number to open full details.'},
                {h:'💡 Quick Tip', t:'Check this page first thing every morning. If "Processing" count is high, those orders need to be confirmed and shipped. Use Order Management for bulk processing.'},
            ]
        },
        'accounting': {
            sections: [
                {h:'📊 What is this page?', t:'The Accounting Dashboard shows your store\'s complete financial picture. All numbers are pulled automatically from real order data, expenses, and income — nothing needs to be manually calculated.'},
                {h:'💰 Revenue', t:'This is the total amount from delivered orders only. It uses the date the order was actually delivered (or last updated). If you see ৳0 for "This Month" but expect data, try "Last Month" or "All Time" to see older orders.'},
                {h:'🏭 COGS (Cost of Goods Sold)', t:'This is how much the products cost you to buy/make. It\'s calculated from FIFO batch costs (set when you do "Stock In" in Inventory) or falls back to each product\'s cost_price. To get accurate COGS, make sure products have cost prices set.'},
                {h:'📈 Gross Profit', t:'Revenue minus COGS = Gross Profit. This tells you how much you earn from sales BEFORE paying for rent, salaries, shipping, etc. A healthy store should have 30%+ gross margin.'},
                {h:'💸 Expenses', t:'Total of operating expenses (from Expenses section) plus advertising spend (from Ad Expenses). Add new expenses via the sidebar menu → New Expense.'},
                {h:'✅ Net Profit', t:'Gross Profit + Other Income - All Expenses - Refunds = Net Profit. This is your actual bottom line — how much money the business is really making.'},
                {h:'📅 Date Range', t:'Use the preset buttons (Today, 7D, This Month, etc.) to change the period. All numbers, charts, and the P&L section update automatically. Use the date inputs on the right for a custom range.'},
                {h:'📊 Trend Chart', t:'The bar chart compares Revenue (green) vs Expenses (red) over the last 6 months. Watch for months where red bars approach green — that means margins are shrinking.'},
                {h:'⚠️ Important', t:'Revenue only counts DELIVERED orders. Pending, processing, or cancelled orders are not included. This gives you the true collected revenue.'},
            ]
        },
        'expenses': {
            sections: [
                {h:'📊 What is this page?', t:'This page shows all recorded business expenses — shipping costs, salaries, rent, office supplies, etc. Use this to track where money is going.'},
                {h:'➕ Adding Expenses', t:'Click "+ New Expense" for operating costs (rent, salary, shipping) or "+ New Ad Expense" for advertising spend (Facebook, Google ads). Each expense is automatically counted in the Accounting dashboard.'},
                {h:'🏷️ Categories', t:'Every expense should have a category (Shipping, Marketing, Salary, Rent, etc.). This helps the Accounting dashboard show you which category is eating the most money. You can add new categories from the left panel.'},
                {h:'🔍 Filtering', t:'Use the date inputs and category dropdown to filter. The total amount at the bottom reflects your filtered view only.'},
                {h:'💡 Quick Tip', t:'Enter expenses daily as they happen — don\'t wait until month end. This keeps the Accounting dashboard accurate and helps you spot overspending early.'},
            ]
        },
        'expense-new': {
            sections: [
                {h:'📊 What is this page?', t:'Use this form to record a business operating expense — anything that costs money but isn\'t product cost (COGS) or ad spend.'},
                {h:'📝 What to Enter Here', t:'Shipping/courier charges, office rent, employee salaries, packaging materials, utility bills, software subscriptions, equipment purchases, travel costs.'},
                {h:'⚠️ What NOT to Enter Here', t:'Product purchase costs → use Inventory → Stock In with cost price instead. Ad spend → use New Ad Expense. Customer refunds → these are tracked automatically when orders are marked as "Returned".'},
                {h:'✅ Required Fields', t:'Title, Amount, Category, and Date are mandatory. Payment method and receipt image are optional but help when reconciling accounts later.'},
                {h:'🔄 Auto-Sync', t:'Once saved, the expense immediately appears in: Expenses List, Accounting Dashboard (under Expenses), and the Accounting Ledger.'},
            ]
        },
        'ad-expense-new': {
            sections: [
                {h:'📊 What is this page?', t:'Record advertising expenses with campaign-level detail. Supports Meta (Facebook/Instagram), Google, TikTok, and other platforms.'},
                {h:'💱 USD / BDT', t:'If you spend in USD, enter the USD amount and exchange rate — the BDT amount calculates automatically. Or just enter the BDT amount directly if you already know it.'},
                {h:'📈 Performance Metrics', t:'Enter impressions, clicks, and conversions if available. The system calculates CPM (cost per 1000 views), CPC (cost per click), and CPA (cost per conversion) automatically.'},
                {h:'🔄 Data Connection', t:'Ad expenses show up in the Accounting dashboard as "Ad Spend" and in the Meta Ads Report for campaign performance analysis.'},
            ]
        },
        'income': {
            sections: [
                {h:'📊 What is this page?', t:'Track manual income — money coming in from sources OTHER than regular customer orders. Order revenue is tracked automatically and doesn\'t need to be entered here.'},
                {h:'📝 When to Use', t:'Record income from: direct cash sales not in the system, service fees charged, supplier refunds received, investment/loan money received, rental income, or any other non-order income.'},
                {h:'⚠️ Don\'t Double-Count', t:'Order-based revenue (from delivered orders) is already counted automatically. Don\'t add it here again — it would double your income numbers.'},
                {h:'🔄 Data Connection', t:'Manual income entries appear in the Accounting dashboard under "Other Income" and add to the Net Profit calculation.'},
            ]
        },
        'liabilities': {
            sections: [
                {h:'📊 What is this page?', t:'Track money your business owes — supplier invoices, loans, rent due, salary payables, or any other debts.'},
                {h:'🔄 Status Flow', t:'<strong>Pending</strong> → money owed but not yet paid. <strong>Partial</strong> → some amount paid. <strong>Paid</strong> → fully settled. <strong>Overdue</strong> → automatically set when due date passes without full payment.'},
                {h:'💳 Making Payments', t:'Click the "Pay" button on any unpaid liability. Enter the amount being paid now — partial payments are fully supported. The system tracks the remaining balance.'},
                {h:'🔄 Data Connection', t:'Outstanding liabilities show in the Accounting dashboard under "Liabilities". Each payment is also recorded as an expense entry.'},
                {h:'💡 Quick Tip', t:'Add liabilities as soon as you receive an invoice or take on a debt. Set the due date so the system can auto-mark overdue items.'},
            ]
        },
        'inventory': {
            sections: [
                {h:'📊 What is this page?', t:'Manage your product stock across warehouses. The FIFO (First In, First Out) system tracks the cost of each batch of stock you receive.'},
                {h:'📦 Stock Levels Tab', t:'Shows all products with current stock count, low-stock threshold, and status. Red = Out of Stock, Yellow = Low Stock, Green = In Stock.'},
                {h:'➕ Adjust Stock Tab', t:'Add or remove stock here. When doing "Stock In", always enter the <strong>Cost Per Unit</strong> — this is critical for accurate profit calculations. Each Stock In creates a FIFO batch.'},
                {h:'🔢 FIFO Batches Tab', t:'Shows all stock batches with their purchase cost and remaining quantity. When products sell, the system automatically uses the OLDEST batch first (First In, First Out). This gives you accurate Cost of Goods Sold.'},
                {h:'🎨 Variant Stock Tab', t:'For products with sizes/colors — manage stock per variant with quick +/- buttons.'},
                {h:'📋 Movements Tab', t:'Full audit trail: every stock change is logged with who did it, when, and why.'},
                {h:'🏢 Warehouses Tab', t:'Create and manage multiple warehouses. Each product\'s stock is tracked per warehouse.'},
                {h:'💡 Key Concept: Cost Price', t:'When you add stock, the "Cost Per Unit" you enter is what the Accounting page uses to calculate COGS (Cost of Goods Sold). Higher accuracy here = more accurate profit numbers.'},
            ]
        },
        'inventory-dashboard': {
            sections: [
                {h:'📊 What is this page?', t:'Visual overview of your entire inventory health — which products need attention, stock value, and movement patterns.'},
                {h:'🔴 Attention Required', t:'Products listed here need restocking. Sort by priority — Out of Stock items should be restocked first.'},
                {h:'💡 Quick Tip', t:'Check this page weekly. If many products are in "Low Stock", place bulk supplier orders to avoid stockouts.'},
            ]
        },
        'order-management': {
            sections: [
                {h:'📊 What is this page?', t:'The main hub for managing all customer orders. This is where you\'ll spend most of your time — confirming orders, assigning couriers, and tracking deliveries.'},
                {h:'🔄 Order Status Flow', t:'<strong>Processing</strong> → new order received. <strong>Confirmed</strong> → verified and ready to pack. <strong>Shipped</strong> → handed to courier. <strong>Delivered</strong> → customer received it.'},
                {h:'📑 Status Tabs', t:'Click any tab to filter orders by status. The badge number shows the count. Typically you\'ll work through Processing → Confirmed → assign courier.'},
                {h:'🚚 Courier Sub-Tabs', t:'After selecting a status (e.g., Shipped), filter by courier (Pathao, Steadfast, etc.) to see which orders went where.'},
                {h:'☑️ Bulk Actions', t:'Check multiple orders → click "Actions" dropdown → Bulk status change, print invoices/stickers, or upload to courier. Saves time when processing many orders at once.'},
                {h:'🔍 Search', t:'Search by order number (e.g., k0003), customer name, or phone number. Use "Filters" button for date range, channel, tags, and staff assignment.'},
                {h:'🟢 Open Button', t:'Click "Open" to view full order details — edit customer info, change status, add notes, or upload to courier.'},
                {h:'🔒 Order Locking', t:'When you open an order, it\'s locked so other team members can\'t edit the same order simultaneously. The lock releases automatically when you leave.'},
                {h:'💡 Daily Workflow', t:'1. Check Processing tab → confirm legitimate orders. 2. Move to Confirmed → assign courier and ship. 3. Check No Response → follow up. 4. Review Returned/Cancelled for patterns.'},
            ]
        },
        'order-view': {
            sections: [
                {h:'📊 What is this page?', t:'Complete details of a single order — customer info, products ordered, pricing breakdown, courier tracking, and status history.'},
                {h:'✏️ Editing', t:'You can edit customer name, phone, address, and notes directly. Changes are saved when you click Save.'},
                {h:'🔄 Status Change', t:'Use the status dropdown to move the order forward (Confirm → Ship → Deliver). Each change is logged in the history below.'},
                {h:'🚚 Courier', t:'Assign a courier provider (Pathao, Steadfast, RedX) to generate a consignment number and tracking link.'},
                {h:'📝 Three Note Types', t:'<strong>Shipping Note</strong> (orange dot) — sent to the courier. <strong>Order Note</strong> (blue dot) — printed on the invoice. <strong>Panel Note</strong> (green dot) — internal only, never visible to customer or courier.'},
                {h:'🔒 Order Lock', t:'While you\'re viewing this order, it\'s locked for other team members. If someone else has it open, you\'ll see a message and can choose to take over.'},
            ]
        },
        'customers': {
            sections: [
                {h:'📊 What is this page?', t:'All customers who have placed orders or registered accounts. Use this to look up customer history and manage accounts.'},
                {h:'👤 Customer Types', t:'<strong>Guests</strong> — placed orders without creating an account. <strong>Registered</strong> — have a password or logged in via Google/Facebook.'},
                {h:'📋 Customer Profile', t:'Click a customer to see their full order history, delivery success rate, total spent, and store credit balance.'},
                {h:'🔍 Search', t:'Search by name, phone number, or email. Use tabs to filter by type (All/Guest/Registered).'},
            ]
        },
        'products': {
            sections: [
                {h:'📊 What is this page?', t:'All products in your store with price, stock count, category, and status.'},
                {h:'📦 Product Types', t:'<strong>Simple</strong> — single item, one price. <strong>Variable</strong> — has variants like size/color with individual stock. <strong>Bundle</strong> — multiple products sold together at a package price.'},
                {h:'📈 Stock Numbers', t:'The stock count shown here is the total across all warehouses. Manage stock details via Inventory section.'},
                {h:'💰 Cost Price', t:'Each product has a "Cost Price" field — this is used for COGS calculations in Accounting when FIFO batch data isn\'t available. Keep it accurate.'},
            ]
        },
        'courier': {
            sections: [
                {h:'📊 What is this page?', t:'Manage courier provider settings — API credentials, default settings, and provider-specific configuration for Pathao, Steadfast, RedX, etc.'},
                {h:'🔧 Setup', t:'Each courier needs API credentials (key/secret/token) entered here. Contact the courier provider to get your merchant API access.'},
            ]
        },
        'settings': {
            sections: [
                {h:'📊 What is this page?', t:'Global site settings — store name, contact info, logos, payment methods, SEO, and feature toggles.'},
                {h:'⚠️ Careful', t:'Changes here affect the entire store immediately. Double-check before saving, especially payment and shipping settings.'},
            ]
        },
        'reports': {
            sections: [
                {h:'📊 What is this page?', t:'Analytics and reports hub. Access detailed reports on orders, products, employees, and business performance.'},
                {h:'📋 Available Reports', t:'Order Reports (status breakdown by date), Product Reports (top sellers, stock analysis), Employee Reports (performance tracking), Profit & Sales (P&L by period), Business Report (comprehensive overview).'},
            ]
        },
        'blog': {
            sections: [
                {h:'📊 What is this page?', t:'Manage blog posts for SEO and content marketing. Create, edit, and publish articles that appear on your store\'s blog section.'},
            ]
        },
        'reviews': {
            sections: [
                {h:'📊 What is this page?', t:'Manage customer product reviews and Q&A. Approve, reject, or respond to customer feedback.'},
                {h:'✅ Approval', t:'New reviews need approval before they appear on the product page. Auto-approve can be enabled in Settings.'},
            ]
        },
        'coupons': {
            sections: [
                {h:'📊 What is this page?', t:'Create and manage discount coupons — percentage off, fixed amount, or free shipping. Set usage limits, date ranges, and minimum order amounts.'},
            ]
        },
        'security': {
            sections: [
                {h:'📊 What is this page?', t:'Security settings — firewall rules, rate limiting, brute force protection, IP blocking, and security headers.'},
                {h:'⚠️ Careful', t:'Incorrect settings can lock you out of the admin panel. Don\'t change settings you don\'t understand.'},
            ]
        },
    };
    var g = guides[page];
    var html = '';
    if (g && g.sections) {
        g.sections.forEach(function(s, i){
            html += '<div class="mb-5 pb-4 ' + (i < g.sections.length - 1 ? 'border-b border-gray-100' : '') + '">';
            html += '<h4 class="font-semibold text-gray-800 text-[13px] mb-1.5">' + s.h + '</h4>';
            html += '<p class="text-gray-600 text-[12.5px] leading-[1.7]">' + s.t + '</p>';
            html += '</div>';
        });
    } else {
        html = '<div class="text-center py-10"><div class="text-3xl mb-3">📖</div><p class="text-gray-500 text-sm">No specific guide available for this page yet.</p><p class="text-gray-400 text-xs mt-1">Contact your admin to add training content for this section.</p></div>';
    }
    document.getElementById('guideBody').innerHTML = html;
}
function closePageGuide(){ document.getElementById('pageGuideModal').classList.add('hidden'); }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePageGuide(); });
</script>
</body>
</html>
