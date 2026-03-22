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
<!-- ══════ Page Guide Modal ══════ -->
<div id="pageGuideModal" class="fixed inset-0 z-[9999] hidden bg-black/40 flex items-center justify-center" onclick="if(event.target===this)closePageGuide()">
    <div class="bg-white rounded-2xl shadow-2xl w-[95vw] max-w-lg max-h-[80vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between px-5 py-3 border-b bg-blue-50 shrink-0">
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 bg-blue-100 rounded-lg flex items-center justify-center text-sm">📖</div>
                <h3 class="font-semibold text-gray-800 text-sm" id="guideTitle">Page Guide</h3>
            </div>
            <button onclick="closePageGuide()" class="text-gray-400 hover:text-gray-600 text-lg leading-none">&times;</button>
        </div>
        <div class="flex-1 overflow-y-auto px-5 py-4 text-sm text-gray-700 leading-relaxed" id="guideBody">
            <div class="text-center text-gray-400 py-8">Loading...</div>
        </div>
    </div>
</div>
<script>
function openPageGuide(){
    document.getElementById('pageGuideModal').classList.remove('hidden');
    var page = '<?= basename($_SERVER['PHP_SELF'] ?? '', '.php') ?>';
    document.getElementById('guideTitle').textContent = (document.querySelector('h2')?.textContent?.trim() || 'Page') + ' — Guide';
    var guides = {
        'dashboard': {
            title: 'Dashboard',
            sections: [
                {h:'Overview', t:'The dashboard shows a snapshot of your store\'s performance for today and recent periods. All numbers update in real-time as orders come in.'},
                {h:'Summary Cards', t:'Top cards show today\'s orders, revenue, processing/confirmed/shipped counts. Click any card to jump to those orders.'},
                {h:'Recent Orders', t:'Shows the latest 10 orders with status, customer, and amount. Click any order to view details.'},
            ]
        },
        'accounting': {
            title: 'Accounting Dashboard',
            sections: [
                {h:'What This Shows', t:'A complete financial overview pulling real data from your orders, expenses, income, and liabilities. All numbers are computed in real-time — not manually entered.'},
                {h:'Revenue', t:'Total from delivered orders only. Uses the date the order was delivered (or last updated if delivery date isn\'t set). This is your actual collected revenue.'},
                {h:'COGS (Cost of Goods Sold)', t:'Calculated from FIFO batch costs when available. Falls back to each product\'s cost_price × quantity sold. Set cost prices in Products or via Inventory → Stock In batches.'},
                {h:'Gross Profit', t:'Revenue minus COGS. This tells you how much you earn after product costs, before operating expenses.'},
                {h:'Expenses', t:'Pulled from the Expenses section (operating costs) plus Ad Expenses (Meta/Google ad spend). Add expenses via Accounting → New Expense.'},
                {h:'Net Profit', t:'Gross Profit + Other Income - Expenses - Ad Spend - Refunds. This is your bottom line.'},
                {h:'Liabilities', t:'Total outstanding debts from the Liabilities section. The "paid" amount shows how much has been settled.'},
                {h:'Date Range', t:'Use the preset buttons (Today, 7D, This Month, etc.) or enter custom dates on the right. All KPIs, charts, and the P&L recalculate for the selected period.'},
                {h:'Trend Chart', t:'6-month bar chart comparing Revenue (green) vs Expenses (red) by month.'},
                {h:'Recent Transactions', t:'Combined feed of the latest delivered orders (income), expenses, and refunds across all sources.'},
            ]
        },
        'expenses': {
            title: 'Expenses List',
            sections: [
                {h:'What This Shows', t:'All recorded business expenses with category, amount, payment method, and who created it.'},
                {h:'Adding Expenses', t:'Use the "+ New Expense" button to record operating costs (shipping, salary, rent, etc.) or "+ New Ad Expense" for advertising spend.'},
                {h:'Categories', t:'Filter by category to see spending breakdowns. Add new categories from the left panel. Categories help the Accounting dashboard show expense breakdowns.'},
                {h:'Date Filter', t:'Use the date inputs to filter expenses by period. The total at the bottom reflects the filtered results.'},
                {h:'Data Connection', t:'Every expense automatically creates an accounting_entries record, which the Accounting dashboard reads for its P&L calculations.'},
            ]
        },
        'expense-new': {
            title: 'New Expense',
            sections: [
                {h:'Purpose', t:'Record a business operating expense — anything that isn\'t product cost (COGS) or ad spend.'},
                {h:'Examples', t:'Shipping charges, courier fees, office rent, salaries, packaging materials, utilities, software subscriptions.'},
                {h:'Required Fields', t:'Title, Amount, Category, and Date are required. Payment method and receipt are optional but help with reconciliation.'},
                {h:'Auto-Sync', t:'Saved expenses automatically appear in the Accounting dashboard under "Expenses" and in the Expenses List.'},
            ]
        },
        'ad-expense-new': {
            title: 'New Ad Expense',
            sections: [
                {h:'Purpose', t:'Track advertising spend across platforms (Meta/Facebook, Google, TikTok, etc.) with campaign-level detail.'},
                {h:'USD/BDT', t:'Enter the USD amount and exchange rate — BDT is calculated automatically. Or enter BDT directly.'},
                {h:'Metrics', t:'Impressions, clicks, and conversions help calculate CPM, CPC, and CPA for campaign performance analysis.'},
                {h:'Data Connection', t:'Ad expenses feed into the Accounting dashboard\'s "Ad Spend" line item and the Meta Ads Report.'},
            ]
        },
        'income': {
            title: 'Income',
            sections: [
                {h:'What This Shows', t:'Manual income entries — revenue sources outside of regular orders (investments, service fees, refunds received, etc.).'},
                {h:'Order Revenue', t:'Order-based revenue (from delivered orders) is tracked automatically. You don\'t need to enter it here — it appears in the Accounting dashboard.'},
                {h:'When to Use', t:'Record income from: direct cash sales not in the system, service fees, loan disbursements, investment returns, refunds received from suppliers.'},
                {h:'Data Connection', t:'Manual income entries appear in the Accounting dashboard under "Other Income" and feed into the Net Profit calculation.'},
            ]
        },
        'liabilities': {
            title: 'Liabilities',
            sections: [
                {h:'What This Shows', t:'Outstanding debts, payables, and financial obligations your business owes.'},
                {h:'Status Flow', t:'Pending → Partial (some paid) → Paid (fully settled). Overdue is auto-set when due date passes.'},
                {h:'Making Payments', t:'Click "Pay" on any liability to record a payment. Partial payments are supported — the system tracks remaining balance.'},
                {h:'Data Connection', t:'Outstanding liabilities appear in the Accounting dashboard\'s "Liabilities" card. Payments are recorded as expenses in accounting_entries.'},
            ]
        },
        'inventory': {
            title: 'Inventory Management',
            sections: [
                {h:'Stock Levels', t:'Shows all products with current stock, threshold, and status (In Stock / Low / Out). Filter by warehouse or stock status.'},
                {h:'Adjust Stock', t:'Add or remove stock. "Stock In" creates a FIFO batch with cost per unit. "Stock Out" consumes from the oldest batch first.'},
                {h:'FIFO Batches', t:'First In, First Out costing. Each stock-in creates a batch with a purchase cost. When stock is consumed, the system uses the oldest batch\'s cost to calculate COGS accurately.'},
                {h:'Cost Per Unit', t:'When adding stock, enter the purchase cost per unit. This is used for COGS calculations in the Accounting dashboard. If left blank, the product\'s default cost_price is used.'},
                {h:'Variant Stock', t:'Manage stock for individual product variants (sizes, colors, etc.) with quick +/- adjustment buttons.'},
                {h:'Movements', t:'Full audit trail of all stock changes — who did what, when, and why.'},
                {h:'Warehouses', t:'Multi-warehouse support. Each product\'s stock is tracked per warehouse and aggregated for the total.'},
            ]
        },
        'inventory-dashboard': {
            title: 'Inventory Dashboard',
            sections: [
                {h:'Overview', t:'Visual summary of stock health across all products and warehouses.'},
                {h:'Stock Health', t:'Pie chart showing the proportion of products that are In Stock, Low Stock, or Out of Stock.'},
                {h:'Attention Required', t:'Lists products that need restocking or are completely out of stock.'},
            ]
        },
        'order-management': {
            title: 'Order Management',
            sections: [
                {h:'Status Flow', t:'Orders flow: Processing → Confirmed → Shipped → Delivered. Side statuses include: On Hold, No Response, Cancelled, Returned.'},
                {h:'Status Tabs', t:'Click any status tab to filter. The count badge shows how many orders are in each status.'},
                {h:'Courier Sub-Tabs', t:'When viewing a specific status, filter by courier (Pathao, Steadfast, etc.) to see which orders are assigned where.'},
                {h:'Bulk Actions', t:'Select multiple orders with checkboxes, then use the Actions menu for bulk status changes, printing, or courier upload.'},
                {h:'Search', t:'Search by order number, customer name, or phone number. Use Advanced Filters for date range, channel, and staff assignment.'},
                {h:'Open Button', t:'Click "Open" to view full order details, edit, and manage the order.'},
            ]
        },
        'order-view': {
            title: 'Order Details',
            sections: [
                {h:'Order Info', t:'Full order details including customer info, products, pricing, status history, and courier tracking.'},
                {h:'Status Changes', t:'Use the status dropdown to move orders through the flow. Each change is logged in the status history.'},
                {h:'Courier Upload', t:'Assign to a courier (Pathao, Steadfast, RedX) to generate a consignment and tracking number.'},
                {h:'Order Lock', t:'When you open an order, it\'s locked so other team members can\'t edit simultaneously. The lock releases when you leave the page.'},
                {h:'Notes', t:'Three note types: Shipping Note (sent to courier), Order Note (printed on invoice), Panel Note (internal only).'},
            ]
        },
        'customers': {
            title: 'Customers',
            sections: [
                {h:'Overview', t:'All customers with order history, registration status, and contact info.'},
                {h:'Tabs', t:'All / Guests (no account) / Registered (have password or Clerk login).'},
                {h:'Customer Profile', t:'Click a customer to see their full order history, delivery success rate, and store credit balance.'},
            ]
        },
        'products': {
            title: 'Products',
            sections: [
                {h:'Product List', t:'All products with price, stock, status, and category. Click to edit.'},
                {h:'Product Types', t:'Simple (single item), Variable (has size/color variants), Bundle (multiple products sold together).'},
                {h:'Stock', t:'Stock is managed via the Inventory section. The number shown here is the aggregate across all warehouses.'},
            ]
        },
    };
    var g = guides[page] || {title: document.querySelector('h2')?.textContent || 'This Page', sections: [{h:'About', t:'This page is part of the admin panel. Use the navigation sidebar to access different sections.'}]};
    var html = '';
    g.sections.forEach(function(s){
        html += '<div class="mb-4"><h4 class="font-semibold text-gray-800 mb-1">' + s.h + '</h4><p class="text-gray-600 text-[13px] leading-relaxed">' + s.t + '</p></div>';
    });
    document.getElementById('guideBody').innerHTML = html;
}
function closePageGuide(){ document.getElementById('pageGuideModal').classList.add('hidden'); }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePageGuide(); });
</script>
</body>
</html>
