<?php
/**
 * Landing Pages — List & Manage
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Landing Pages';
require_once __DIR__ . '/../includes/auth.php';
$db = Database::getInstance();

// Auto-create tables
try { $db->fetch("SELECT 1 FROM landing_pages LIMIT 1"); } catch (\Throwable $e) {
    // Trigger API to create tables
    $_GET['action'] = 'list';
    include __DIR__ . '/../../api/landing-pages.php';
    exit;
}

$lpStats = [];
$r = $db->fetch("SELECT COUNT(*) as c FROM landing_pages");
$lpStats['total'] = intval($r['c'] ?? 0);
$r = $db->fetch("SELECT COUNT(*) as c FROM landing_pages WHERE status='active'");
$lpStats['active'] = intval($r['c'] ?? 0);
$r = $db->fetch("SELECT COALESCE(SUM(views),0) as c FROM landing_pages");
$lpStats['total_views'] = intval($r['c'] ?? 0);
$r = $db->fetch("SELECT COALESCE(SUM(orders_count),0) as c FROM landing_pages");
$lpStats['total_orders'] = intval($r['c'] ?? 0);
$r = $db->fetch("SELECT COALESCE(SUM(revenue),0) as c FROM landing_pages");
$lpStats['total_revenue'] = floatval($r['c'] ?? 0);

include __DIR__ . '/../includes/header.php';
?>

<div class="space-y-5">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Landing Pages</h1>
            <p class="text-sm text-gray-500 mt-1">Create high-converting product landing pages</p>
        </div>
        <a href="<?= adminUrl('pages/landing-page-builder.php') ?>" class="px-5 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-semibold hover:bg-blue-700 shadow-lg shadow-blue-200 transition">
            <i class="fas fa-plus mr-1.5"></i> Create Landing Page
        </a>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-5 gap-4">
        <div class="bg-white rounded-xl border p-4">
            <div class="text-xs text-gray-500 font-medium">Total Pages</div>
            <div class="text-2xl font-bold text-gray-800 mt-1"><?= $lpStats['total'] ?></div>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <div class="text-xs text-gray-500 font-medium">Active</div>
            <div class="text-2xl font-bold text-green-600 mt-1"><?= $lpStats['active'] ?></div>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <div class="text-xs text-gray-500 font-medium">Total Views</div>
            <div class="text-2xl font-bold text-blue-600 mt-1"><?= number_format($lpStats['total_views']) ?></div>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <div class="text-xs text-gray-500 font-medium">Total Orders</div>
            <div class="text-2xl font-bold text-purple-600 mt-1"><?= number_format($lpStats['total_orders']) ?></div>
        </div>
        <div class="bg-white rounded-xl border p-4">
            <div class="text-xs text-gray-500 font-medium">Revenue</div>
            <div class="text-2xl font-bold text-emerald-600 mt-1">৳<?= number_format($lpStats['total_revenue']) ?></div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="flex items-center gap-2">
        <button onclick="filterPages('')" class="filter-tab active px-4 py-2 rounded-lg text-sm font-medium bg-blue-600 text-white" data-status="">All</button>
        <button onclick="filterPages('active')" class="filter-tab px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-600 hover:bg-gray-200" data-status="active">Active</button>
        <button onclick="filterPages('draft')" class="filter-tab px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-600 hover:bg-gray-200" data-status="draft">Drafts</button>
        <button onclick="filterPages('paused')" class="filter-tab px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-600 hover:bg-gray-200" data-status="paused">Paused</button>
    </div>

    <!-- Pages Table -->
    <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wider">
                <tr>
                    <th class="px-5 py-3 text-left">Page</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center">Views</th>
                    <th class="px-4 py-3 text-center">Orders</th>
                    <th class="px-4 py-3 text-center">Revenue</th>
                    <th class="px-4 py-3 text-center">Conv. Rate</th>
                    <th class="px-4 py-3 text-center">A/B Test</th>
                    <th class="px-4 py-3 text-center">Updated</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="pagesBody" class="divide-y"></tbody>
        </table>
        <div id="emptyState" class="hidden text-center py-16">
            <div class="text-5xl mb-3">🚀</div>
            <h3 class="text-lg font-semibold text-gray-700">No landing pages yet</h3>
            <p class="text-gray-500 text-sm mt-1 mb-4">Create your first high-converting landing page</p>
            <a href="<?= adminUrl('pages/landing-page-builder.php') ?>" class="inline-block px-5 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-semibold hover:bg-blue-700">Create Landing Page</a>
        </div>
    </div>
</div>

<script>
const API = '<?= SITE_URL ?>/api/landing-pages.php';
const ADMIN = '<?= adminUrl("pages") ?>';
const SITE = '<?= SITE_URL ?>';
let currentFilter = '';

function filterPages(status) {
    currentFilter = status;
    document.querySelectorAll('.filter-tab').forEach(t => {
        const isActive = t.dataset.status === status;
        t.className = 'filter-tab px-4 py-2 rounded-lg text-sm font-medium ' + (isActive ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200');
    });
    loadPages();
}

function loadPages() {
    fetch(API + '?action=list&status=' + currentFilter)
    .then(r => r.json()).then(res => {
        const pages = res.data || [];
        const body = document.getElementById('pagesBody');
        const empty = document.getElementById('emptyState');
        
        if (!pages.length) {
            body.innerHTML = '';
            empty.classList.remove('hidden');
            return;
        }
        empty.classList.add('hidden');
        
        body.innerHTML = pages.map(p => {
            const statusColors = {active:'bg-green-100 text-green-700',draft:'bg-gray-100 text-gray-600',paused:'bg-yellow-100 text-yellow-700'};
            const statusColor = statusColors[p.status] || statusColors.draft;
            const convRate = parseFloat(p.conversion_rate || 0);
            const convColor = convRate >= 5 ? 'text-green-600' : convRate >= 2 ? 'text-blue-600' : 'text-gray-600';
            const url = SITE + '/lp/' + p.slug;
            
            return `<tr class="hover:bg-gray-50 transition">
                <td class="px-5 py-3">
                    <div class="font-semibold text-gray-800">${esc(p.title)}</div>
                    <a href="${url}" target="_blank" class="text-xs text-blue-500 hover:underline">/lp/${esc(p.slug)}</a>
                </td>
                <td class="px-4 py-3 text-center"><span class="px-2.5 py-1 rounded-full text-xs font-semibold ${statusColor}">${p.status}</span></td>
                <td class="px-4 py-3 text-center font-medium">${num(p.views)}</td>
                <td class="px-4 py-3 text-center font-medium">${num(p.orders_count)}</td>
                <td class="px-4 py-3 text-center font-medium">৳${num(p.revenue)}</td>
                <td class="px-4 py-3 text-center font-bold ${convColor}">${convRate}%</td>
                <td class="px-4 py-3 text-center">${p.ab_test_enabled == 1 ? '<span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-medium">A/B</span>' : '—'}</td>
                <td class="px-4 py-3 text-center text-xs text-gray-400">${timeAgo(p.updated_at)}</td>
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <a href="${ADMIN}/landing-page-builder.php?id=${p.id}" class="p-2 rounded-lg hover:bg-blue-50 text-blue-600" title="Edit"><i class="fas fa-pen text-xs"></i></a>
                        <a href="${ADMIN}/landing-page-builder.php?id=${p.id}&tab=analytics" class="p-2 rounded-lg hover:bg-purple-50 text-purple-600" title="Analytics"><i class="fas fa-chart-bar text-xs"></i></a>
                        <a href="${url}" target="_blank" class="p-2 rounded-lg hover:bg-green-50 text-green-600" title="View"><i class="fas fa-external-link-alt text-xs"></i></a>
                        <button onclick="duplicatePage(${p.id})" class="p-2 rounded-lg hover:bg-gray-100 text-gray-500" title="Duplicate"><i class="fas fa-copy text-xs"></i></button>
                        <button onclick="deletePage(${p.id},'${esc(p.title)}')" class="p-2 rounded-lg hover:bg-red-50 text-red-500" title="Delete"><i class="fas fa-trash text-xs"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    });
}

function duplicatePage(id) {
    const fd = new FormData(); fd.append('action','duplicate'); fd.append('id',id);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d => {
        if (d.success) { loadPages(); } else { alert(d.error); }
    });
}

function deletePage(id, title) {
    if (!confirm('Delete "' + title + '"? This cannot be undone.')) return;
    const fd = new FormData(); fd.append('action','delete'); fd.append('id',id);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(() => loadPages());
}

function esc(s) { return (s||'').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function num(n) { return Number(n||0).toLocaleString(); }
function timeAgo(d) {
    if (!d) return '';
    const diff = (Date.now() - new Date(d).getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}

loadPages();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
