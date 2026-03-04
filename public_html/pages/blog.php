<?php
/**
 * Blog Listing — WoodMart Vegetables Inspired
 * Organic/natural aesthetic, warm earth tones, clean card grid
 */
$pageTitle = getSetting('blog_page_title', 'ব্লগ — Blog');
$metaDescription = getSetting('blog_meta_description', 'Read our latest articles, tips, and guides.');

$db = Database::getInstance();
try { $db->query("SELECT 1 FROM blog_posts LIMIT 1"); } catch (\Throwable $e) { include __DIR__ . '/404.php'; exit; }

$catFilter   = $_GET['category'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');
$curPage     = max(1, intval($_GET['page'] ?? 1));
$perPage     = 9;
$offset      = ($curPage - 1) * $perPage;

$where = "status = 'published'"; $params = [];
if ($catFilter) { $where .= " AND category = ?"; $params[] = $catFilter; }
if ($searchQuery) { $where .= " AND (title LIKE ? OR title_bn LIKE ? OR excerpt LIKE ? OR tags LIKE ?)"; $sq = '%'.$searchQuery.'%'; $params = array_merge($params, [$sq,$sq,$sq,$sq]); }

$countRow = $db->fetch("SELECT COUNT(*) as cnt FROM blog_posts WHERE {$where}", $params);
$totalPosts = intval($countRow['cnt'] ?? 0);
$totalPages = max(1, ceil($totalPosts / $perPage));

$posts = $db->fetchAll("SELECT * FROM blog_posts WHERE {$where} ORDER BY is_featured DESC, published_at DESC LIMIT {$perPage} OFFSET {$offset}", $params);

$blogCats = [];
try { $blogCats = $db->fetchAll("SELECT bc.*, (SELECT COUNT(*) FROM blog_posts bp WHERE bp.category=bc.name AND bp.status='published') as post_count FROM blog_categories bc WHERE bc.is_active=1 ORDER BY bc.sort_order"); } catch (\Throwable $e) {}

$lang = 'bn';

// SEO
$pageDescription = getSetting('blog_meta_description', '') ?: 'Read our latest articles and blog posts';
$seo = [
    'type' => 'website',
    'title' => $pageTitle . ' | ' . getSetting('site_name'),
    'description' => $pageDescription,
    'breadcrumbs' => [
        ['name' => 'হোম', 'url' => SITE_URL],
        ['name' => 'ব্লগ'],
    ],
];

require_once __DIR__ . '/../includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Hind+Siliguri:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root { --blog-green: #4a7c59; --blog-green-light: #e8f0e4; --blog-warm: #f9f5ef; --blog-brown: #6b4f3a; --blog-accent: #c8956c; --blog-text: #3d3d3d; --blog-muted: #8a8a8a; }

/* Breadcrumb */
.blog-breadcrumb { background: var(--blog-warm); border-bottom: 1px solid #ece5d8; }
.blog-breadcrumb a { color: var(--blog-muted); text-decoration: none; transition: color .2s; }
.blog-breadcrumb a:hover { color: var(--blog-green); }

/* Category sidebar */
.cat-link { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0ebe3; color: var(--blog-text); font-size: 14px; transition: all .2s; text-decoration: none; }
.cat-link:hover, .cat-link.active { color: var(--blog-green); font-weight: 600; }
.cat-link .cat-count { background: var(--blog-green-light); color: var(--blog-green); font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; }
.cat-link.active .cat-count { background: var(--blog-green); color: #fff; }

/* Blog card */
.blog-card { background: #fff; border-radius: 4px; overflow: hidden; transition: box-shadow .4s cubic-bezier(.4,0,.2,1), transform .4s cubic-bezier(.4,0,.2,1); cursor: pointer; text-decoration: none; display: block; color: inherit; }
.blog-card:hover { box-shadow: 0 16px 48px -12px rgba(74,124,89,.15); transform: translateY(-3px); }
.blog-card-img { overflow: hidden; position: relative; }
.blog-card-img img { width: 100%; height: 100%; object-fit: cover; transition: transform .7s cubic-bezier(.4,0,.2,1); }
.blog-card:hover .blog-card-img img { transform: scale(1.06); }
.blog-card-cat { position: absolute; top: 12px; left: 12px; background: var(--blog-green); color: #fff; font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; padding: 4px 10px; border-radius: 2px; }
.blog-card-meta { font-size: 12px; color: var(--blog-muted); display: flex; gap: 12px; align-items: center; }
.blog-card-meta svg { width: 13px; height: 13px; opacity: .5; }
.blog-card-title { font-family: 'Cormorant Garamond', serif; font-size: 22px; font-weight: 700; line-height: 1.3; color: var(--blog-text); margin: 10px 0 12px; transition: color .2s; }
.blog-card:hover .blog-card-title { color: var(--blog-green); }
.blog-card-excerpt { font-size: 14px; line-height: 1.7; color: var(--blog-muted); }
.blog-card-read { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--blog-green); margin-top: 16px; transition: gap .3s; }
.blog-card:hover .blog-card-read { gap: 10px; }

/* Recipe badge */
.recipe-badge { background: var(--blog-accent); color: #fff; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 2px; position: absolute; top: 12px; right: 12px; }

/* Search */
.blog-search { border: 1px solid #e0d9ce; border-radius: 3px; transition: border-color .2s; }
.blog-search:focus-within { border-color: var(--blog-green); }

/* Pagination */
.blog-pag a, .blog-pag span { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; font-size: 13px; font-weight: 600; border-radius: 3px; transition: all .2s; text-decoration: none; }
.blog-pag a { color: var(--blog-text); border: 1px solid #e0d9ce; }
.blog-pag a:hover { background: var(--blog-green); color: #fff; border-color: var(--blog-green); }
.blog-pag .active { background: var(--blog-green); color: #fff; border: 1px solid var(--blog-green); }

.line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.line-clamp-3 { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.fade-in { animation: fadeIn .5s ease both; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
</style>

<!-- Breadcrumb -->
<div class="blog-breadcrumb">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center gap-2 text-xs" style="font-family:'DM Sans',sans-serif">
        <a href="<?= url('/') ?>"><?= $lang==='bn' ? 'হোম' : 'Home' ?></a>
        <span class="text-gray-300">/</span>
        <?php if ($catFilter): ?>
            <a href="<?= url('blog') ?>"><?= $lang==='bn' ? 'ব্লগ' : 'Blog' ?></a>
            <span class="text-gray-300">/</span>
            <span style="color:var(--blog-green);font-weight:600"><?= e($catFilter) ?></span>
        <?php else: ?>
            <span style="color:var(--blog-green);font-weight:600"><?= $lang==='bn' ? 'ব্লগ' : 'Blog' ?></span>
        <?php endif; ?>
    </div>
</div>

<!-- Page Header -->
<div style="background:var(--blog-warm)">
    <div class="max-w-7xl mx-auto px-4 py-8 md:py-12">
        <h1 style="font-family:'Cormorant Garamond',serif;font-size:clamp(28px,5vw,44px);font-weight:700;color:var(--blog-text);line-height:1.15;margin-bottom:8px">
            <?php if ($catFilter): ?><?= e($catFilter) ?>
            <?php elseif ($searchQuery): ?><?= $lang==='bn' ? 'অনুসন্ধান' : 'Search Results' ?>
            <?php else: ?><?= $lang==='bn' ? 'আমাদের ব্লগ' : 'Our Blog' ?><?php endif; ?>
        </h1>
        <p style="font-family:'DM Sans',sans-serif;font-size:15px;color:var(--blog-muted);max-width:480px">
            <?= $lang==='bn' ? 'টিপস, রেসিপি, গাইড এবং আমাদের সর্বশেষ আপডেটগুলি পড়ুন' : 'Tips, recipes, guides, and our latest updates' ?>
        </p>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 py-8 md:py-12">
    <div class="flex flex-col lg:flex-row gap-8 lg:gap-12">

        <!-- Sidebar -->
        <aside class="lg:w-64 shrink-0 lg:order-first">
            <!-- Search -->
            <div class="mb-8">
                <form action="<?= url('blog') ?>" method="GET" class="blog-search flex">
                    <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="<?= $lang==='bn' ? 'খুঁজুন...' : 'Search articles...' ?>"
                           class="flex-1 px-3 py-2.5 text-sm border-0 focus:outline-none bg-transparent" style="font-family:'DM Sans',sans-serif">
                    <button type="submit" class="px-3" style="color:var(--blog-green)"><svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg></button>
                </form>
            </div>

            <!-- Categories -->
            <div class="mb-8">
                <h3 style="font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:700;color:var(--blog-text);margin-bottom:12px;padding-bottom:12px;border-bottom:2px solid var(--blog-green)">
                    <?= $lang==='bn' ? 'ক্যাটাগরি' : 'Categories' ?>
                </h3>
                <a href="<?= url('blog') ?>" class="cat-link <?= !$catFilter ? 'active' : '' ?>">
                    <span><?= $lang==='bn' ? 'সকল পোস্ট' : 'All Posts' ?></span>
                    <span class="cat-count"><?= $totalPosts ?></span>
                </a>
                <?php foreach ($blogCats as $bc):
                    $pcnt = intval($bc['post_count'] ?? 0);
                    $isActive = ($catFilter === ($bc['name'] ?? ''));
                ?>
                <a href="<?= url('blog?category=' . urlencode($bc['name'] ?? '')) ?>" class="cat-link <?= $isActive ? 'active' : '' ?>">
                    <span><?= ($lang==='bn' && !empty($bc['name_bn'])) ? e($bc['name_bn']) : e($bc['name'] ?? '') ?></span>
                    <span class="cat-count"><?= $pcnt ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if ($searchQuery): ?>
            <div class="mb-6 p-3 rounded" style="background:var(--blog-green-light)">
                <p class="text-xs" style="color:var(--blog-green)">
                    <?= $totalPosts ?> <?= $lang==='bn' ? 'ফলাফল' : 'results for' ?> "<strong><?= e($searchQuery) ?></strong>"
                </p>
                <a href="<?= url('blog') ?>" class="text-xs font-bold mt-1 inline-block" style="color:var(--blog-green)">✕ <?= $lang==='bn' ? 'মুছুন' : 'Clear' ?></a>
            </div>
            <?php endif; ?>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 min-w-0">
            <?php if (!empty($posts)): ?>
            <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-6 md:gap-7">
                <?php foreach ($posts as $i => $p):
                    $pImg     = !empty($p['featured_image']) ? uploadUrl($p['featured_image']) : '';
                    $pDate    = !empty($p['published_at']) ? date($lang==='bn' ? 'd M, Y' : 'M d, Y', strtotime($p['published_at'])) : '';
                    $pTitle   = ($lang==='bn' && !empty($p['title_bn'])) ? $p['title_bn'] : ($p['title'] ?? '');
                    $pExcerpt = ($lang==='bn' && !empty($p['excerpt_bn'])) ? $p['excerpt_bn'] : (!empty($p['excerpt']) ? $p['excerpt'] : mb_substr(strip_tags($p['content'] ?? ''), 0, 120));
                    $pCat     = $p['category'] ?? '';
                    $pTpl     = $p['template'] ?? 'classic';
                    $pRead    = max(1, round(str_word_count(strip_tags($p['content'] ?? '')) / 200));
                ?>
                <a href="<?= url('blog/' . ($p['slug'] ?? '')) ?>" class="blog-card fade-in" style="animation-delay:<?= ($i % 9) * 0.06 ?>s">
                    <div class="blog-card-img" style="height:220px;background:#f0ebe3">
                        <?php if ($pImg): ?>
                        <img src="<?= $pImg ?>" alt="<?= e($pTitle) ?>" loading="lazy">
                        <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:48px;opacity:.15">📝</div>
                        <?php endif; ?>
                        <?php if ($pCat): ?><span class="blog-card-cat"><?= e($pCat) ?></span><?php endif; ?>
                        <?php if ($pTpl === 'recipe'): ?><span class="recipe-badge">🍳 <?= $lang==='bn' ? 'রেসিপি' : 'RECIPE' ?></span><?php endif; ?>
                    </div>
                    <div style="padding:20px 20px 24px">
                        <div class="blog-card-meta">
                            <span style="display:flex;align-items:center;gap:4px">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <?= $pDate ?>
                            </span>
                            <span><?= $pRead ?> min</span>
                            <?php if ($pTpl === 'recipe'):
                                $rd = json_decode($p['recipe_data'] ?? '{}', true);
                            ?>
                                <?php if (!empty($rd['cook_time'])): ?><span>🕐 <?= e($rd['cook_time']) ?></span><?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <h3 class="blog-card-title line-clamp-2"><?= e($pTitle) ?></h3>
                        <p class="blog-card-excerpt line-clamp-2"><?= e($pExcerpt) ?></p>
                        <div class="blog-card-read">
                            <?= $lang==='bn' ? 'বিস্তারিত পড়ুন' : 'Read More' ?>
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav class="blog-pag flex items-center justify-center gap-2 mt-12 pt-8" style="border-top:1px solid #ece5d8">
                <?php if ($curPage > 1): ?>
                <a href="<?= url('blog?page='.($curPage-1).($catFilter?'&category='.urlencode($catFilter):'').($searchQuery?'&q='.urlencode($searchQuery):'')) ?>">←</a>
                <?php endif; ?>
                <?php for ($pg = max(1, $curPage-2); $pg <= min($totalPages, $curPage+2); $pg++): ?>
                <?php if ($pg === $curPage): ?><span class="active"><?= $pg ?></span>
                <?php else: ?><a href="<?= url('blog?page='.$pg.($catFilter?'&category='.urlencode($catFilter):'').($searchQuery?'&q='.urlencode($searchQuery):'')) ?>"><?= $pg ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($curPage < $totalPages): ?>
                <a href="<?= url('blog?page='.($curPage+1).($catFilter?'&category='.urlencode($catFilter):'').($searchQuery?'&q='.urlencode($searchQuery):'')) ?>">→</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

            <?php else: ?>
            <div style="text-align:center;padding:80px 0">
                <div style="font-size:64px;opacity:.2;margin-bottom:16px">📭</div>
                <h3 style="font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:700;color:var(--blog-text);margin-bottom:8px"><?= $lang==='bn' ? 'কোনো পোস্ট পাওয়া যায়নি' : 'No posts found' ?></h3>
                <p style="font-size:14px;color:var(--blog-muted);margin-bottom:24px"><?= $lang==='bn' ? 'শীঘ্রই নতুন কন্টেন্ট আসছে!' : 'New content coming soon!' ?></p>
                <?php if ($searchQuery || $catFilter): ?>
                <a href="<?= url('blog') ?>" style="display:inline-block;padding:10px 24px;background:var(--blog-green);color:#fff;border-radius:3px;font-size:13px;font-weight:600;text-decoration:none"><?= $lang==='bn' ? 'সব পোস্ট দেখুন' : 'View All Posts' ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
