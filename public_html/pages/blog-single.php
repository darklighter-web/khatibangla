<?php
/**
 * Single Blog Post — 5 Templates
 * Classic | Magazine | Minimal | Modern | Recipe (WoodMart Vegetables style)
 */
$slug = $_GET['slug'] ?? '';
if (!$slug) { http_response_code(404); include __DIR__ . '/404.php'; exit; }

$db = Database::getInstance();
$post = null;
try { $post = $db->fetch("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published'", [$slug]); } catch (\Throwable $e) {}
if (!$post) { http_response_code(404); include __DIR__ . '/404.php'; exit; }

try { $db->query("UPDATE blog_posts SET views = views + 1 WHERE id = ?", [intval($post['id'])]); } catch (\Throwable $e) {}

$lang = 'bn';
$title   = ($lang === 'bn' && !empty($post['title_bn'])) ? $post['title_bn'] : ($post['title'] ?? '');
$content = ($lang === 'bn' && !empty($post['content_bn'])) ? $post['content_bn'] : ($post['content'] ?? '');
$excerpt = ($lang === 'bn' && !empty($post['excerpt_bn'])) ? $post['excerpt_bn'] : ($post['excerpt'] ?? '');

$pageTitle       = !empty($post['meta_title']) ? $post['meta_title'] : $title;
$metaDescription = !empty($post['meta_description']) ? $post['meta_description'] : ($excerpt ?: mb_substr(strip_tags($content), 0, 160));

$template = strtolower(trim($post['template'] ?? 'classic'));
if (!in_array($template, ['classic','magazine','minimal','modern','recipe'])) $template = 'classic';

$img           = !empty($post['featured_image']) ? uploadUrl($post['featured_image']) : '';
$date          = !empty($post['published_at']) ? date($lang === 'bn' ? 'd M, Y' : 'M d, Y', strtotime($post['published_at'])) : '';
$author        = !empty($post['author_name']) ? $post['author_name'] : 'Admin';
$authorInitial = mb_substr($author, 0, 1);
$category      = $post['category'] ?? '';
$tags          = !empty($post['tags']) ? array_map('trim', explode(',', $post['tags'])) : [];
$readTime      = max(1, round(str_word_count(strip_tags($content)) / 200));
$views         = intval($post['views'] ?? 0);
$postUrl       = url('blog/' . ($post['slug'] ?? ''));

// Recipe data
$recipe = ['prep_time'=>'','cook_time'=>'','servings'=>'','difficulty'=>'','calories'=>'','ingredients'=>[],'steps'=>[]];
if (!empty($post['recipe_data'])) {
    $rd = json_decode($post['recipe_data'], true);
    if (is_array($rd)) $recipe = array_merge($recipe, $rd);
}

// Sidebar data: categories + recent posts
$blogCats = []; $recentPosts = [];
try { $blogCats = $db->fetchAll("SELECT bc.*, (SELECT COUNT(*) FROM blog_posts bp WHERE bp.category=bc.name AND bp.status='published') as post_count FROM blog_categories bc WHERE bc.is_active=1 ORDER BY bc.sort_order"); } catch (\Throwable $e) {}
try { $recentPosts = $db->fetchAll("SELECT id,title,title_bn,slug,featured_image,published_at FROM blog_posts WHERE status='published' AND id!=? ORDER BY published_at DESC LIMIT 4", [intval($post['id'])]); } catch (\Throwable $e) {}

// Related posts
$related = [];
try {
    if ($category) $related = $db->fetchAll("SELECT id,title,title_bn,slug,featured_image,published_at,author_name,category,template,recipe_data FROM blog_posts WHERE status='published' AND id!=? AND category=? ORDER BY published_at DESC LIMIT 3", [intval($post['id']), $category]);
    if (count($related) < 3) {
        $exIds = array_merge([intval($post['id'])], array_column($related, 'id'));
        $ph = implode(',', array_fill(0, count($exIds), '?'));
        $more = $db->fetchAll("SELECT id,title,title_bn,slug,featured_image,published_at,author_name,category,template,recipe_data FROM blog_posts WHERE status='published' AND id NOT IN ({$ph}) ORDER BY published_at DESC LIMIT " . (3 - count($related)), $exIds);
        $related = array_merge($related, $more);
    }
} catch (\Throwable $e) {}

// Prev/Next
$pubTs = !empty($post['published_at']) ? $post['published_at'] : ($post['created_at'] ?? date('Y-m-d H:i:s'));
$prevPost = null; $nextPost = null;
try {
    $prevPost = $db->fetch("SELECT slug,title,title_bn,featured_image FROM blog_posts WHERE status='published' AND published_at < ? ORDER BY published_at DESC LIMIT 1", [$pubTs]);
    $nextPost = $db->fetch("SELECT slug,title,title_bn,featured_image FROM blog_posts WHERE status='published' AND published_at > ? ORDER BY published_at ASC LIMIT 1", [$pubTs]);
} catch (\Throwable $e) {}

// SEO: Article structured data
$pageDescription = $metaDescription;
$seo = [
    'type' => 'article',
    'title' => $pageTitle,
    'description' => $metaDescription,
    'image' => $img,
    'published_time' => !empty($post['published_at']) ? date('c', strtotime($post['published_at'])) : '',
    'modified_time' => !empty($post['updated_at']) ? date('c', strtotime($post['updated_at'])) : '',
    'author' => $author,
    'article' => $post,
    'breadcrumbs' => [
        ['name' => 'হোম', 'url' => SITE_URL],
        ['name' => 'ব্লগ', 'url' => SITE_URL . '/blog'],
        ['name' => $title],
    ],
];

require_once __DIR__ . '/../includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Hind+Siliguri:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root { --blog-green: #4a7c59; --blog-green-light: #e8f0e4; --blog-warm: #f9f5ef; --blog-brown: #6b4f3a; --blog-accent: #c8956c; --blog-text: #3d3d3d; --blog-muted: #8a8a8a; }

.post-breadcrumb { background: var(--blog-warm); border-bottom: 1px solid #ece5d8; }
.post-breadcrumb a { color: var(--blog-muted); text-decoration: none; transition: color .2s; }
.post-breadcrumb a:hover { color: var(--blog-green); }

/* Content typography */
.blog-content { font-family: 'DM Sans', 'Hind Siliguri', sans-serif; font-size: 16px; line-height: 1.85; color: var(--blog-text); }
.blog-content h2 { font-family: 'Cormorant Garamond', serif; font-size: 1.6em; font-weight: 700; margin: 1.8em 0 .6em; color: #1a1a1a; }
.blog-content h3 { font-family: 'Cormorant Garamond', serif; font-size: 1.3em; font-weight: 600; margin: 1.5em 0 .5em; color: #2a2a2a; }
.blog-content p { margin-bottom: 1.2em; }
.blog-content img { max-width: 100%; border-radius: 4px; margin: 1.5em auto; display: block; }
.blog-content a { color: var(--blog-green); text-decoration: underline; text-underline-offset: 2px; }
.blog-content blockquote { border-left: 3px solid var(--blog-green); padding: 1em 1.5em; margin: 1.5em 0; background: var(--blog-warm); font-style: italic; color: #555; }
.blog-content ul, .blog-content ol { margin: 1em 0; padding-left: 1.8em; }
.blog-content li { margin-bottom: .5em; }
.blog-content pre { background: #1e293b; color: #e2e8f0; padding: 1.2em; border-radius: 4px; overflow-x: auto; margin: 1.5em 0; font-size: .9em; }
.blog-content code { background: #f1f5f9; padding: .15em .4em; border-radius: 3px; font-size: .9em; }
.blog-content pre code { background: none; padding: 0; }

/* Sidebar */
.sidebar-section { background: #fff; margin-bottom: 24px; }
.sidebar-title { font-family: 'Cormorant Garamond', serif; font-size: 18px; font-weight: 700; color: var(--blog-text); padding-bottom: 12px; border-bottom: 2px solid var(--blog-green); margin-bottom: 14px; }
.sidebar-cat { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f3ede3; font-size: 14px; color: var(--blog-text); text-decoration: none; transition: color .2s; }
.sidebar-cat:hover { color: var(--blog-green); }
.sidebar-cat-count { font-size: 11px; color: var(--blog-muted); }
.recent-post-item { display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f3ede3; text-decoration: none; color: inherit; transition: opacity .2s; }
.recent-post-item:hover { opacity: .8; }
.recent-post-item:last-child { border-bottom: none; }

/* Recipe Ingredients Table */
.ingredients-table { width: 100%; border-collapse: collapse; }
.ingredients-table td { padding: 10px 12px; border-bottom: 1px solid #f0ebe3; font-size: 14.5px; color: var(--blog-text); }
.ingredients-table td:last-child { text-align: right; color: var(--blog-muted); font-size: 13px; white-space: nowrap; }
.ingredients-table tr:last-child td { border-bottom: none; }

/* Recipe Steps — Zigzag */
.recipe-step-row { display: flex; gap: 40px; align-items: flex-start; padding: 40px 0; border-bottom: 1px solid #f0ebe3; }
.recipe-step-row:last-child { border-bottom: none; }
.recipe-step-row.reverse { flex-direction: row-reverse; }
.recipe-step-text { flex: 1; min-width: 0; }
.recipe-step-img { flex: 1; min-width: 0; }
.recipe-step-img img { width: 100%; border-radius: 8px; object-fit: cover; max-height: 340px; }
.recipe-step-number { font-family: 'Cormorant Garamond', serif; font-size: 14px; font-weight: 700; color: var(--blog-green); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
.recipe-step-title { font-family: 'Cormorant Garamond', serif; font-size: 24px; font-weight: 700; color: var(--blog-text); margin-bottom: 14px; line-height: 1.25; }
.recipe-step-desc { font-size: 15px; line-height: 1.8; color: #555; }
@media (max-width: 768px) {
    .recipe-step-row, .recipe-step-row.reverse { flex-direction: column; gap: 20px; }
}

/* Author Bio */
.author-bio { text-align: center; padding: 40px 0; border-top: 1px solid #ece5d8; border-bottom: 1px solid #ece5d8; margin: 40px 0; }
.author-avatar { width: 80px; height: 80px; border-radius: 50%; background: var(--blog-green); color: #fff; display: flex; align-items: center; justify-content: center; font-family: 'Cormorant Garamond', serif; font-size: 32px; font-weight: 700; margin: 0 auto 12px; border: 3px solid #e8e2d6; }

/* Share + Tags */
.share-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; transition: all .2s; text-decoration: none; }
.share-btn:hover { transform: scale(1.1); opacity: .85; }
.post-nav-link { display: block; padding: 16px; border: 1px solid #e0d9ce; border-radius: 4px; text-decoration: none; transition: all .2s; }
.post-nav-link:hover { border-color: var(--blog-green); background: var(--blog-green-light); }

.related-card { text-decoration: none; color: inherit; display: block; transition: transform .4s, box-shadow .4s; }
.related-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px -8px rgba(74,124,89,.12); }
.related-card:hover .rc-title { color: var(--blog-green); }
.related-card:hover .rc-img img { transform: scale(1.05); }
.rc-img { overflow: hidden; border-radius: 4px 4px 0 0; }
.rc-img img { width: 100%; height: 100%; object-fit: cover; transition: transform .6s; }

.fade-up { animation: fadeUp .6s ease-out both; }
@keyframes fadeUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
.line-clamp-2 { display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }
</style>

<!-- Breadcrumb -->
<div class="post-breadcrumb">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center gap-2 text-xs" style="font-family:'DM Sans',sans-serif">
        <a href="<?= url('/') ?>"><?= $lang==='bn' ? 'হোম' : 'Home' ?></a><span style="color:#ccc">/</span>
        <a href="<?= url('blog') ?>"><?= $lang==='bn' ? 'ব্লগ' : 'Blog' ?></a><span style="color:#ccc">/</span>
        <?php if ($category): ?><a href="<?= url('blog?category='.urlencode($category)) ?>"><?= e($category) ?></a><span style="color:#ccc">/</span><?php endif; ?>
        <span style="color:var(--blog-green);font-weight:600" class="line-clamp-2"><?= e($title) ?></span>
    </div>
</div>

<?php
// ══════════════════════════════════════════════════════
// TEMPLATE: RECIPE — WoodMart Vegetables Zigzag Style
// ══════════════════════════════════════════════════════
if ($template === 'recipe'): ?>

<div class="max-w-7xl mx-auto px-4 py-8 md:py-10">
    <div class="flex flex-col lg:flex-row gap-10 lg:gap-12">

        <!-- Main Content -->
        <article class="flex-1 min-w-0 fade-up">

            <!-- Category Badge + Title + Meta -->
            <div style="margin-bottom:24px">
                <?php if ($category): ?>
                <div style="margin-bottom:10px">
                    <span style="display:inline-block;background:var(--blog-green);color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;padding:4px 14px;border-radius:2px"><?= e($category) ?></span>
                </div>
                <?php endif; ?>
                <h1 style="font-family:'Cormorant Garamond',serif;font-size:clamp(28px,4.5vw,42px);font-weight:700;color:var(--blog-text);line-height:1.15;margin:0 0 12px"><?= e($title) ?></h1>
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-family:'DM Sans',sans-serif;font-size:13px;color:var(--blog-muted)">
                    <span>Posted by</span>
                    <span style="font-weight:600;color:var(--blog-text)"><?= e($author) ?></span>
                    <span style="color:#ddd">|</span>
                    <span><?= $date ?></span>
                    <span style="color:#ddd">|</span>
                    <span>👁 <?= number_format($views) ?></span>
                </div>
            </div>

            <!-- Featured Image -->
            <?php if ($img): ?>
            <div style="margin-bottom:32px;border-radius:6px;overflow:hidden">
                <img src="<?= $img ?>" alt="<?= e($title) ?>" style="width:100%;max-height:500px;object-fit:cover;display:block">
            </div>
            <?php endif; ?>

            <!-- Content Body -->
            <?php if (trim($content)): ?>
            <div class="blog-content" style="margin-bottom:40px"><?= $content ?></div>
            <?php endif; ?>

            <!-- Ingredients Section -->
            <?php if (!empty($recipe['ingredients'])): ?>
            <div style="margin-bottom:48px;padding:32px;background:var(--blog-warm);border-radius:8px">
                <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:20px;border-bottom:2px solid var(--blog-green);padding-bottom:12px">
                    <h3 style="font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:700;color:var(--blog-text);margin:0">
                        <?= $lang==='bn' ? 'উপকরণ' : 'Ingredients' ?>
                    </h3>
                    <?php if ($recipe['servings']): ?>
                    <span style="font-size:13px;color:var(--blog-muted)"><?= $lang==='bn' ? 'পরিবেশন' : 'For' ?> <?= e($recipe['servings']) ?> <?= $lang==='bn' ? 'জন' : 'Servings' ?></span>
                    <?php endif; ?>
                </div>
                <table class="ingredients-table">
                    <?php foreach ($recipe['ingredients'] as $ing):
                        $ingName = is_array($ing) ? ($ing['name'] ?? '') : $ing;
                        $ingAmt  = is_array($ing) ? ($ing['amount'] ?? '') : '';
                    ?>
                    <tr>
                        <td><?= e($ingName) ?></td>
                        <td><?= e($ingAmt) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>

            <!-- Steps — Zigzag Layout -->
            <?php if (!empty($recipe['steps'])): ?>
            <div style="margin-bottom:40px">
                <?php foreach ($recipe['steps'] as $si => $step):
                    $sTitle = is_array($step) ? ($step['title'] ?? '') : $step;
                    $sDesc  = is_array($step) ? ($step['description'] ?? '') : '';
                    $sImg   = is_array($step) ? ($step['image'] ?? '') : '';
                    $isEven = ($si % 2 === 1);
                    $hasImg = !empty($sImg);
                ?>
                <?php if ($hasImg): ?>
                <div class="recipe-step-row <?= $isEven ? 'reverse' : '' ?>">
                    <div class="recipe-step-text">
                        <div class="recipe-step-number">Step <?= $si + 1 ?></div>
                        <?php if ($sTitle): ?><div class="recipe-step-title"><?= e($sTitle) ?></div><?php endif; ?>
                        <?php if ($sDesc): ?><div class="recipe-step-desc"><?= nl2br(e($sDesc)) ?></div><?php endif; ?>
                    </div>
                    <div class="recipe-step-img">
                        <img src="<?= uploadUrl($sImg) ?>" alt="Step <?= $si + 1 ?>" loading="lazy">
                    </div>
                </div>
                <?php else: ?>
                <div style="padding:32px 0;border-bottom:1px solid #f0ebe3">
                    <div class="recipe-step-number">Step <?= $si + 1 ?></div>
                    <?php if ($sTitle): ?><div class="recipe-step-title"><?= e($sTitle) ?></div><?php endif; ?>
                    <?php if ($sDesc): ?><div class="recipe-step-desc"><?= nl2br(e($sDesc)) ?></div><?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Author Bio -->
            <div class="author-bio">
                <div class="author-avatar"><?= $authorInitial ?></div>
                <h4 style="font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:700;color:var(--blog-text);margin:0 0 6px"><?= e($author) ?></h4>
                <p style="font-size:13px;color:var(--blog-muted);max-width:500px;margin:0 auto 12px;line-height:1.6"><?= $excerpt ?: ($lang==='bn' ? 'এই লেখকের আরো পোস্ট পড়ুন।' : 'Read more articles by this author.') ?></p>
                <a href="<?= url('blog') ?>" style="font-size:12px;color:var(--blog-green);font-weight:600;text-decoration:none"><?= $lang==='bn' ? 'সব পোস্ট দেখুন →' : 'View all posts by '.e($author).' →' ?></a>
            </div>

            <!-- Tags -->
            <?php if (!empty($tags)): ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;padding:20px 0;border-bottom:1px solid #ece5d8;margin-bottom:20px">
                <?php foreach ($tags as $tg): ?>
                <a href="<?= url('blog?q='.urlencode($tg)) ?>" style="padding:5px 14px;background:var(--blog-warm);color:var(--blog-muted);font-size:12px;border-radius:3px;text-decoration:none;transition:all .2s;border:1px solid #e8e2d6" onmouseover="this.style.borderColor='var(--blog-green)';this.style.color='var(--blog-green)'" onmouseout="this.style.borderColor='#e8e2d6';this.style.color='var(--blog-muted)'"><?= e($tg) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Share -->
            <div style="display:flex;align-items:center;gap:10px;padding:16px 0;margin-bottom:20px">
                <span style="font-size:12px;color:var(--blog-muted);font-weight:600"><?= $lang==='bn' ? 'শেয়ার:' : 'Share:' ?></span>
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($postUrl) ?>" target="_blank" class="share-btn" style="background:#3b5998;color:#fff"><i class="fab fa-facebook-f" style="font-size:13px"></i></a>
                <a href="https://wa.me/?text=<?= urlencode($title.' '.$postUrl) ?>" target="_blank" class="share-btn" style="background:#25d366;color:#fff"><i class="fab fa-whatsapp" style="font-size:13px"></i></a>
                <button onclick="navigator.clipboard.writeText('<?= $postUrl ?>');this.textContent='✓';setTimeout(()=>this.innerHTML='<i class=\'fas fa-link\' style=\'font-size:12px\'></i>',2000)" class="share-btn" style="background:#eee;color:#666;border:none;cursor:pointer"><i class="fas fa-link" style="font-size:12px"></i></button>
            </div>

            <!-- Prev/Next Navigation -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
                <?php if ($prevPost): ?>
                <a href="<?= url('blog/'.($prevPost['slug']??'')) ?>" class="post-nav-link">
                    <div style="font-size:11px;color:var(--blog-muted);margin-bottom:4px">← <?= $lang==='bn'?'আগের পোস্ট':'Previous' ?></div>
                    <div style="font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:600;color:var(--blog-text)" class="line-clamp-2"><?= e(($lang==='bn'&&!empty($prevPost['title_bn']))?$prevPost['title_bn']:($prevPost['title']??'')) ?></div>
                </a>
                <?php else: ?><div></div><?php endif; ?>
                <?php if ($nextPost): ?>
                <a href="<?= url('blog/'.($nextPost['slug']??'')) ?>" class="post-nav-link" style="text-align:right">
                    <div style="font-size:11px;color:var(--blog-muted);margin-bottom:4px"><?= $lang==='bn'?'পরের পোস্ট':'Next' ?> →</div>
                    <div style="font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:600;color:var(--blog-text)" class="line-clamp-2"><?= e(($lang==='bn'&&!empty($nextPost['title_bn']))?$nextPost['title_bn']:($nextPost['title']??'')) ?></div>
                </a>
                <?php else: ?><div></div><?php endif; ?>
            </div>

        </article>

        <!-- RIGHT SIDEBAR -->
        <aside class="lg:w-72 shrink-0 fade-up" style="animation-delay:.15s">
            <div style="position:sticky;top:90px">

                <!-- Categories -->
                <div class="sidebar-section" style="margin-bottom:28px">
                    <h3 class="sidebar-title"><?= $lang==='bn' ? 'ক্যাটাগরি' : 'Categories' ?></h3>
                    <?php foreach ($blogCats as $bc): ?>
                    <a href="<?= url('blog?category='.urlencode($bc['name'] ?? '')) ?>" class="sidebar-cat">
                        <span><?= ($lang==='bn' && !empty($bc['name_bn'])) ? e($bc['name_bn']) : e($bc['name'] ?? '') ?></span>
                        <span class="sidebar-cat-count">(<?= intval($bc['post_count'] ?? 0) ?>)</span>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Recent Posts -->
                <?php if (!empty($recentPosts)): ?>
                <div class="sidebar-section" style="margin-bottom:28px">
                    <h3 class="sidebar-title"><?= $lang==='bn' ? 'সাম্প্রতিক পোস্ট' : 'Recent Posts' ?></h3>
                    <?php foreach ($recentPosts as $rp):
                        $rpImg   = !empty($rp['featured_image']) ? uploadUrl($rp['featured_image']) : '';
                        $rpTitle = ($lang==='bn'&&!empty($rp['title_bn'])) ? $rp['title_bn'] : ($rp['title']??'');
                        $rpDate  = !empty($rp['published_at']) ? date('M d, Y', strtotime($rp['published_at'])) : '';
                    ?>
                    <a href="<?= url('blog/'.($rp['slug']??'')) ?>" class="recent-post-item">
                        <?php if ($rpImg): ?>
                        <img src="<?= $rpImg ?>" alt="" style="width:70px;height:55px;object-fit:cover;border-radius:4px;flex-shrink:0">
                        <?php else: ?>
                        <div style="width:70px;height:55px;background:#f3ede3;border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:20px;opacity:.3">📝</div>
                        <?php endif; ?>
                        <div style="min-width:0">
                            <div style="font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:700;color:var(--blog-text);line-height:1.3" class="line-clamp-2"><?= e($rpTitle) ?></div>
                            <div style="font-size:11px;color:var(--blog-muted);margin-top:3px"><?= $rpDate ?></div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Recipe Info Card (if recipe) -->
                <?php if ($recipe['prep_time'] || $recipe['cook_time'] || $recipe['servings'] || $recipe['difficulty']): ?>
                <div style="background:var(--blog-warm);border-radius:6px;padding:20px;margin-bottom:28px">
                    <h3 style="font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:700;color:var(--blog-text);margin:0 0 12px">🍳 <?= $lang==='bn'?'রেসিপি তথ্য':'Recipe Info' ?></h3>
                    <div style="font-size:13px;color:var(--blog-text);line-height:2">
                        <?php if ($recipe['prep_time']): ?><div style="display:flex;justify-content:space-between"><span style="color:var(--blog-muted)">⏱ Prep</span><strong><?= e($recipe['prep_time']) ?></strong></div><?php endif; ?>
                        <?php if ($recipe['cook_time']): ?><div style="display:flex;justify-content:space-between"><span style="color:var(--blog-muted)">🔥 Cook</span><strong><?= e($recipe['cook_time']) ?></strong></div><?php endif; ?>
                        <?php if ($recipe['servings']): ?><div style="display:flex;justify-content:space-between"><span style="color:var(--blog-muted)">🍽 Servings</span><strong><?= e($recipe['servings']) ?></strong></div><?php endif; ?>
                        <?php if ($recipe['difficulty']): ?><div style="display:flex;justify-content:space-between"><span style="color:var(--blog-muted)">📊 Level</span><strong><?= e($recipe['difficulty']) ?></strong></div><?php endif; ?>
                        <?php if ($recipe['calories']): ?><div style="display:flex;justify-content:space-between"><span style="color:var(--blog-muted)">🔥 Calories</span><strong><?= e($recipe['calories']) ?></strong></div><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </aside>
    </div>
</div>


<?php
// ══════════════════════════════════════════════════════
// TEMPLATE: CLASSIC
// ══════════════════════════════════════════════════════
elseif ($template === 'classic'): ?>

<?php if ($img): ?>
<div style="background:var(--blog-warm)">
    <div class="max-w-4xl mx-auto px-4 pt-6"><img src="<?= $img ?>" alt="<?= e($title) ?>" style="width:100%;max-height:480px;object-fit:cover;border-radius:4px;display:block" class="fade-up"></div>
</div>
<?php endif; ?>
<article class="max-w-3xl mx-auto px-4 py-8 md:py-12 fade-up" style="animation-delay:.1s">
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:16px;font-family:'DM Sans',sans-serif;font-size:12px;color:var(--blog-muted)">
        <?php if ($category): ?><span style="background:var(--blog-green);color:#fff;padding:4px 12px;border-radius:2px;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.5px"><?= e($category) ?></span><?php endif; ?>
        <span><?= $date ?></span><span>·</span><span><?= $readTime ?> min</span><span>·</span><span>👁 <?= number_format($views) ?></span>
    </div>
    <h1 style="font-family:'Cormorant Garamond',serif;font-size:clamp(28px,5vw,44px);font-weight:700;color:var(--blog-text);line-height:1.15;margin-bottom:20px"><?= e($title) ?></h1>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:32px;padding-bottom:24px;border-bottom:1px solid #ece5d8">
        <div style="width:44px;height:44px;border-radius:50%;background:var(--blog-green);display:flex;align-items:center;justify-content:center;color:#fff;font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:700"><?= $authorInitial ?></div>
        <div><div style="font-weight:600;font-size:14px;color:var(--blog-text)"><?= e($author) ?></div><div style="font-size:12px;color:var(--blog-muted)"><?= $date ?></div></div>
    </div>
    <div class="blog-content"><?= $content ?></div>
</article>


<?php
// ══════════════════════════════════════════════════════
// TEMPLATE: MAGAZINE
// ══════════════════════════════════════════════════════
elseif ($template === 'magazine'): ?>

<div class="fade-up" style="position:relative;min-height:55vh;display:flex;align-items:flex-end;<?= $img ? "background:linear-gradient(to bottom,transparent 30%,rgba(0,0,0,.82)),url('{$img}') center/cover no-repeat" : 'background:linear-gradient(135deg,#1a2e1a,#2d4a2d)' ?>">
    <div class="max-w-4xl mx-auto px-4 pb-10 md:pb-14 w-full">
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:12px">
            <?php if ($category): ?><span style="background:rgba(255,255,255,.15);backdrop-filter:blur(4px);color:#fff;padding:4px 12px;border-radius:2px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px"><?= e($category) ?></span><?php endif; ?>
            <span style="color:rgba(255,255,255,.6);font-size:12px"><?= $date ?></span>
        </div>
        <h1 style="font-family:'Cormorant Garamond',serif;font-size:clamp(28px,5vw,52px);font-weight:700;color:#fff;line-height:1.12;margin-bottom:16px;text-shadow:0 2px 16px rgba(0,0,0,.3)"><?= e($title) ?></h1>
        <?php if ($excerpt): ?><p style="color:rgba(255,255,255,.7);font-size:16px;max-width:560px;line-height:1.6;margin-bottom:16px"><?= e($excerpt) ?></p><?php endif; ?>
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;border-radius:50%;background:var(--blog-accent);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px"><?= $authorInitial ?></div>
            <div><div style="font-weight:600;font-size:13px;color:#fff"><?= e($author) ?></div><div style="font-size:11px;color:rgba(255,255,255,.5)">👁 <?= number_format($views) ?></div></div>
        </div>
    </div>
</div>
<article class="max-w-3xl mx-auto px-4 py-10 md:py-14 fade-up" style="animation-delay:.1s">
    <div class="blog-content"><?= $content ?></div>
</article>


<?php
// ══════════════════════════════════════════════════════
// TEMPLATE: MINIMAL
// ══════════════════════════════════════════════════════
elseif ($template === 'minimal'): ?>

<article class="max-w-2xl mx-auto px-4 py-10 md:py-16 fade-up">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;font-family:'DM Sans',sans-serif;font-size:12px">
        <?php if ($category): ?><span style="color:var(--blog-green);font-weight:700;text-transform:uppercase;letter-spacing:1px"><?= e($category) ?></span><span style="color:#ddd">·</span><?php endif; ?>
        <span style="color:var(--blog-muted)"><?= $date ?></span><span style="color:#ddd">·</span><span style="color:var(--blog-muted)"><?= $readTime ?> min</span>
    </div>
    <h1 style="font-family:'Cormorant Garamond',serif;font-size:clamp(26px,4.5vw,40px);font-weight:700;color:var(--blog-text);line-height:1.25;margin-bottom:20px"><?= e($title) ?></h1>
    <?php if ($excerpt): ?><p style="font-size:17px;color:var(--blog-muted);line-height:1.7;margin-bottom:28px;border-left:3px solid var(--blog-green);padding-left:20px"><?= e($excerpt) ?></p><?php endif; ?>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:32px;font-size:13px;color:var(--blog-muted)">
        <div style="width:30px;height:30px;border-radius:50%;background:#e8e8e8;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;color:#666"><?= $authorInitial ?></div>
        <span><?= e($author) ?></span><span style="color:#ddd">·</span><span>👁 <?= number_format($views) ?></span>
    </div>
    <?php if ($img): ?><img src="<?= $img ?>" alt="<?= e($title) ?>" style="width:100%;border-radius:4px;margin-bottom:32px"><?php endif; ?>
    <div class="blog-content"><?= $content ?></div>
</article>


<?php
// ══════════════════════════════════════════════════════
// TEMPLATE: MODERN (Card + TOC sidebar)
// ══════════════════════════════════════════════════════
else: ?>

<?php if ($img): ?>
<div style="background:var(--blog-warm)">
    <div class="max-w-5xl mx-auto px-4 pt-6"><img src="<?= $img ?>" alt="<?= e($title) ?>" style="width:100%;max-height:400px;object-fit:cover;border-radius:4px" class="fade-up"></div>
</div>
<?php endif; ?>
<div class="max-w-5xl mx-auto px-4 py-8 md:py-12">
    <div style="display:flex;gap:32px;flex-wrap:wrap">
        <article style="flex:1;min-width:0" class="fade-up">
            <div style="background:#fff;border:1px solid #e0d9ce;border-radius:4px;padding:clamp(20px,3vw,40px)">
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:14px;font-size:12px;color:var(--blog-muted)">
                    <?php if ($category): ?><span style="background:var(--blog-green);color:#fff;padding:4px 12px;border-radius:2px;font-weight:700;font-size:10px;text-transform:uppercase"><?= e($category) ?></span><?php endif; ?>
                    <span><?= $date ?></span><span>·</span><span><?= $readTime ?> min</span><span>·</span><span>👁 <?= number_format($views) ?></span>
                </div>
                <h1 style="font-family:'Cormorant Garamond',serif;font-size:clamp(24px,4vw,38px);font-weight:700;color:var(--blog-text);line-height:1.2;margin-bottom:20px"><?= e($title) ?></h1>
                <div style="display:flex;align-items:center;gap:10px;padding-bottom:20px;border-bottom:1px solid #ece5d8;margin-bottom:24px">
                    <div style="width:36px;height:36px;border-radius:50%;background:var(--blog-green);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px"><?= $authorInitial ?></div>
                    <div><div style="font-weight:600;font-size:13px;color:var(--blog-text)"><?= e($author) ?></div><div style="font-size:11px;color:var(--blog-muted)"><?= $date ?></div></div>
                </div>
                <div class="blog-content" id="blogContent"><?= $content ?></div>
            </div>
        </article>
        <aside style="width:240px;flex-shrink:0" class="fade-up hidden lg:block" style="animation-delay:.2s">
            <div style="position:sticky;top:90px">
                <div style="background:#fff;border:1px solid #e0d9ce;border-radius:4px;padding:20px;margin-bottom:16px">
                    <h4 style="font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:700;color:var(--blog-text);margin-bottom:12px">📑 <?= $lang==='bn'?'সূচি':'Contents' ?></h4>
                    <nav id="tocNav" style="font-size:13px"></nav>
                </div>
                <div style="background:#fff;border:1px solid #e0d9ce;border-radius:4px;padding:20px">
                    <h4 style="font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:700;margin-bottom:12px">🔗 <?= $lang==='bn'?'শেয়ার':'Share' ?></h4>
                    <div style="display:flex;gap:8px">
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($postUrl) ?>" target="_blank" class="share-btn" style="background:#3b5998;color:#fff"><i class="fab fa-facebook-f" style="font-size:13px"></i></a>
                        <a href="https://wa.me/?text=<?= urlencode($title.' '.$postUrl) ?>" target="_blank" class="share-btn" style="background:#25d366;color:#fff"><i class="fab fa-whatsapp" style="font-size:13px"></i></a>
                        <button onclick="navigator.clipboard.writeText('<?= $postUrl ?>');this.textContent='✓';setTimeout(()=>this.innerHTML='<i class=\'fas fa-link\' style=\'font-size:12px\'></i>',2000)" class="share-btn" style="background:#eee;color:#666;border:none;cursor:pointer"><i class="fas fa-link" style="font-size:12px"></i></button>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var c=document.getElementById('blogContent'),n=document.getElementById('tocNav');
    if(!c||!n)return;var h=c.querySelectorAll('h2,h3');
    if(!h.length){n.innerHTML='<p style="color:#aaa;font-size:12px">No sections</p>';return;}
    h.forEach(function(el,i){var id='s-'+i;el.id=id;var a=document.createElement('a');a.href='#'+id;a.textContent=el.textContent;a.style.cssText='display:block;padding:6px 10px;color:var(--blog-muted);text-decoration:none;border-left:2px solid transparent;transition:all .2s;'+(el.tagName==='H3'?'padding-left:20px;font-size:12px':'font-weight:600');
    a.addEventListener('mouseenter',function(){this.style.borderLeftColor='var(--blog-green)';this.style.color='var(--blog-green)';this.style.background='var(--blog-warm)';});
    a.addEventListener('mouseleave',function(){this.style.borderLeftColor='transparent';this.style.color='var(--blog-muted)';this.style.background='';});
    n.appendChild(a);});
});
</script>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════ -->
<!-- COMMON BOTTOM: Tags, Navigation, Related (non-recipe) -->
<!-- ══════════════════════════════════════════════════════ -->
<?php if ($template !== 'recipe'): ?>
<div class="max-w-<?= $template==='modern'?'5':($template==='minimal'?'2':'3') ?>xl mx-auto px-4 pb-8">
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;padding:20px 0;border-top:1px solid #ece5d8;border-bottom:1px solid #ece5d8">
        <div style="display:flex;flex-wrap:wrap;gap:6px"><?php foreach ($tags as $tg): ?><span style="padding:4px 12px;background:var(--blog-warm);color:var(--blog-muted);font-size:12px;border-radius:2px;font-weight:500">#<?= e($tg) ?></span><?php endforeach; ?></div>
        <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--blog-muted)">
            <span><?= $lang==='bn' ? 'শেয়ার:' : 'Share:' ?></span>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($postUrl) ?>" target="_blank" class="share-btn" style="background:#3b5998;color:#fff;width:32px;height:32px"><i class="fab fa-facebook-f" style="font-size:12px"></i></a>
            <a href="https://wa.me/?text=<?= urlencode($title.' '.$postUrl) ?>" target="_blank" class="share-btn" style="background:#25d366;color:#fff;width:32px;height:32px"><i class="fab fa-whatsapp" style="font-size:12px"></i></a>
            <button onclick="navigator.clipboard.writeText('<?= $postUrl ?>');this.textContent='✓';setTimeout(()=>this.textContent='🔗',2000)" class="share-btn" style="background:#eee;color:#666;width:32px;height:32px;border:none;cursor:pointer">🔗</button>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:20px">
        <?php if ($prevPost): ?><a href="<?= url('blog/'.($prevPost['slug']??'')) ?>" class="post-nav-link"><div style="font-size:11px;color:var(--blog-muted);margin-bottom:4px">← <?= $lang==='bn'?'আগের':'Previous' ?></div><div style="font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:600;color:var(--blog-text)" class="line-clamp-2"><?= e(($lang==='bn'&&!empty($prevPost['title_bn']))?$prevPost['title_bn']:($prevPost['title']??'')) ?></div></a><?php else: ?><div></div><?php endif; ?>
        <?php if ($nextPost): ?><a href="<?= url('blog/'.($nextPost['slug']??'')) ?>" class="post-nav-link" style="text-align:right"><div style="font-size:11px;color:var(--blog-muted);margin-bottom:4px"><?= $lang==='bn'?'পরের':'Next' ?> →</div><div style="font-family:'Cormorant Garamond',serif;font-size:15px;font-weight:600;color:var(--blog-text)" class="line-clamp-2"><?= e(($lang==='bn'&&!empty($nextPost['title_bn']))?$nextPost['title_bn']:($nextPost['title']??'')) ?></div></a><?php else: ?><div></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Related Posts -->
<?php if (!empty($related)): ?>
<section style="background:var(--blog-warm);padding:48px 0;margin-top:20px">
    <div class="max-w-5xl mx-auto px-4">
        <h3 style="font-family:'Cormorant Garamond',serif;font-size:28px;font-weight:700;color:var(--blog-text);margin-bottom:24px"><?= $lang==='bn'?'আরও পড়ুন':'You Might Also Like' ?></h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:24px">
            <?php foreach ($related as $rp):
                $rpImg   = !empty($rp['featured_image']) ? uploadUrl($rp['featured_image']) : '';
                $rpTitle = ($lang==='bn'&&!empty($rp['title_bn'])) ? $rp['title_bn'] : ($rp['title']??'');
                $rpDate  = !empty($rp['published_at']) ? date('M d, Y', strtotime($rp['published_at'])) : '';
                $rpTpl   = $rp['template'] ?? 'classic';
            ?>
            <a href="<?= url('blog/'.($rp['slug']??'')) ?>" class="related-card" style="background:#fff;border-radius:4px;overflow:hidden;border:1px solid #e8e2d6">
                <div class="rc-img" style="height:180px;background:#f0ebe3;position:relative">
                    <?php if ($rpImg): ?><img src="<?= $rpImg ?>" alt="<?= e($rpTitle) ?>" loading="lazy">
                    <?php else: ?><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:40px;opacity:.15">📝</div><?php endif; ?>
                    <?php if ($rpTpl === 'recipe'): ?><span style="position:absolute;top:10px;right:10px;background:var(--blog-accent);color:#fff;font-size:9px;font-weight:700;padding:3px 8px;border-radius:2px">🍳 RECIPE</span><?php endif; ?>
                </div>
                <div style="padding:16px 18px 20px">
                    <?php if (!empty($rp['category'])): ?><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--blog-green);margin-bottom:6px"><?= e($rp['category']) ?></div><?php endif; ?>
                    <h4 class="rc-title line-clamp-2" style="font-family:'Cormorant Garamond',serif;font-size:19px;font-weight:700;color:var(--blog-text);line-height:1.3;margin:0 0 8px;transition:color .2s"><?= e($rpTitle) ?></h4>
                    <div style="font-size:12px;color:var(--blog-muted)"><?= $rpDate ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Back to Blog -->
<div style="text-align:center;padding:32px 0">
    <a href="<?= url('blog') ?>" style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border:1px solid #e0d9ce;border-radius:3px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;color:var(--blog-text);text-decoration:none;transition:all .2s" onmouseover="this.style.borderColor='var(--blog-green)';this.style.color='var(--blog-green)'" onmouseout="this.style.borderColor='#e0d9ce';this.style.color='var(--blog-text)'">
        ← <?= $lang==='bn' ? 'সব পোস্ট দেখুন' : 'Back to Blog' ?>
    </a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
