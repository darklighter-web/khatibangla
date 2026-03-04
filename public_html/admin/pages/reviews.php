<?php
/**
 * Admin: Reviews & Q&A Management
 */
require_once __DIR__ . '/../../includes/session.php';
$pageTitle = 'Reviews & Q&A';
require_once __DIR__ . '/../includes/auth.php';

$db = Database::getInstance();
$activeTab = $_GET['tab'] ?? 'reviews';
$filter = $_GET['filter'] ?? 'pending';

// Handle quick actions via GET
if (!empty($_GET['approve'])) {
    $id = intval($_GET['approve']);
    $type = $_GET['type'] ?? 'review';
    if ($type === 'review') {
        $db->query("UPDATE product_reviews SET is_approved = 1 WHERE id = ?", [$id]);
        $rev = $db->fetch("SELECT * FROM product_reviews WHERE id = ?", [$id]);
        if ($rev && !$rev['credit_awarded'] && $rev['customer_id'] && !$rev['is_dummy']) {
            $reward = intval(getSetting('review_credit_reward', '50'));
            if ($reward > 0) {
                addStoreCredit($rev['customer_id'], $reward, 'earn', 'review', $id, "রিভিউ বোনাস (পণ্য #{$rev['product_id']})");
                $db->query("UPDATE product_reviews SET credit_awarded = 1 WHERE id = ?", [$id]);
            }
        }
    } elseif ($type === 'question') {
        $db->query("UPDATE product_questions SET is_approved = 1 WHERE id = ?", [$id]);
    } elseif ($type === 'answer') {
        $db->query("UPDATE product_answers SET is_approved = 1 WHERE id = ?", [$id]);
        $ans = $db->fetch("SELECT * FROM product_answers WHERE id = ?", [$id]);
        if ($ans && $ans['customer_id'] && !$ans['credit_awarded']) {
            $reward = intval(getSetting('qna_credit_reward', '20'));
            if ($reward > 0) {
                addStoreCredit($ans['customer_id'], $reward, 'earn', 'qna', $id, "প্রশ্ন-উত্তর বোনাস");
                $db->query("UPDATE product_answers SET credit_awarded = 1 WHERE id = ?", [$id]);
            }
        }
    }
    header('Location: ' . adminUrl("pages/reviews.php?tab={$activeTab}&filter={$filter}&msg=approved")); exit;
}
if (!empty($_GET['reject'])) {
    $id = intval($_GET['reject']); $type = $_GET['type'] ?? 'review';
    if ($type === 'review') $db->query("UPDATE product_reviews SET is_approved = 0 WHERE id = ?", [$id]);
    elseif ($type === 'question') $db->query("UPDATE product_questions SET is_approved = 0 WHERE id = ?", [$id]);
    elseif ($type === 'answer') $db->query("UPDATE product_answers SET is_approved = 0 WHERE id = ?", [$id]);
    header('Location: ' . adminUrl("pages/reviews.php?tab={$activeTab}&filter={$filter}&msg=rejected")); exit;
}
if (!empty($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']); $type = $_GET['type'] ?? 'review';
    if ($type === 'review') { $db->delete('review_likes','review_id=?',[$id]); $db->delete('product_reviews','id=?',[$id]); }
    elseif ($type === 'question') { $db->delete('product_answers','question_id=?',[$id]); $db->delete('product_questions','id=?',[$id]); }
    elseif ($type === 'answer') { $db->delete('product_answers','id=?',[$id]); }
    header('Location: ' . adminUrl("pages/reviews.php?tab={$activeTab}&filter={$filter}&msg=deleted")); exit;
}

// Handle settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $keys = ['reviews_enabled','qna_enabled','review_min_words','review_credit_reward','qna_credit_reward','review_auto_approve','qna_auto_approve','review_require_purchase','review_images_max'];
    foreach ($keys as $k) {
        $val = $_POST[$k] ?? '0';
        try { $db->query("INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?", [$k,$val,$val]); } catch (\Throwable $e) {}
    }
    header('Location: ' . adminUrl("pages/reviews.php?tab=settings&msg=saved")); exit;
}

// Handle dummy review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_dummy') {
    $productId = intval($_POST['product_id'] ?? 0);
    $rating = max(1, min(5, intval($_POST['rating'] ?? 5)));
    $text = trim($_POST['review_text'] ?? '');
    $name = trim($_POST['reviewer_name'] ?? 'Customer');
    
    if ($productId && $text) {
        $images = [];
        $uploadsDir = rtrim(UPLOAD_PATH, '/') . '/review-images/';
        if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
        
        if (!empty($_FILES['images'])) {
            $files = $_FILES['images'];
            for ($i = 0; $i < min(count($files['name']), 5); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK || $files['size'][$i] > 5*1024*1024) continue;
                $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) continue;
                $fname = 'dummy_' . $productId . '_' . time() . '_' . $i . '.' . $ext;
                if (move_uploaded_file($files['tmp_name'][$i], $uploadsDir . $fname)) {
                    $images[] = '/uploads/review-images/' . $fname;
                }
            }
        }
        
        $db->insert('product_reviews', [
            'product_id'=>$productId,'rating'=>$rating,'review_text'=>$text,'images'=>json_encode($images),
            'reviewer_name'=>$name,'is_approved'=>1,'is_dummy'=>1,
            'ip_address'=>$_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }
    header('Location: ' . adminUrl("pages/reviews.php?tab=dummy&msg=added")); exit;
}

// Handle admin answer submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_answer') {
    $qid = intval($_POST['question_id'] ?? 0);
    $text = trim($_POST['answer_text'] ?? '');
    if ($qid && $text) {
        $db->insert('product_answers', [
            'question_id'=>$qid,'answerer_name'=>getAdminName().' (Admin)','answer_text'=>$text,
            'is_admin'=>1,'admin_id'=>$_SESSION['admin_id'],'is_approved'=>1,
        ]);
    }
    header('Location: ' . adminUrl("pages/reviews.php?tab=qna&filter={$filter}&msg=answered")); exit;
}

// ── All redirects done, now output HTML ──
require_once __DIR__ . '/../includes/header.php';

// Stats
$pendingReviews = $db->fetch("SELECT COUNT(*) as c FROM product_reviews WHERE is_approved = 0")['c'] ?? 0;
$totalReviews = $db->fetch("SELECT COUNT(*) as c FROM product_reviews")['c'] ?? 0;
$pendingQ = $db->fetch("SELECT COUNT(*) as c FROM product_questions WHERE is_approved = 0")['c'] ?? 0;
$pendingA = $db->fetch("SELECT COUNT(*) as c FROM product_answers WHERE is_approved = 0")['c'] ?? 0;
$msg = $_GET['msg'] ?? '';
?>

<?php if ($msg): ?>
<div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm flex items-center gap-2">
    <i class="fas fa-check-circle"></i>
    <?= $msg === 'approved' ? 'Approved!' : ($msg === 'rejected' ? 'Rejected.' : ($msg === 'deleted' ? 'Deleted.' : ($msg === 'saved' ? 'Settings saved.' : ($msg === 'added' ? 'Dummy review added.' : ($msg === 'answered' ? 'Answer posted.' : 'Done.'))))) ?>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-xl border p-4">
        <div class="text-2xl font-bold text-gray-800"><?= $totalReviews ?></div>
        <div class="text-xs text-gray-400 mt-1">Total Reviews</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="text-2xl font-bold text-orange-600"><?= $pendingReviews ?></div>
        <div class="text-xs text-gray-400 mt-1">Pending Reviews</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="text-2xl font-bold text-blue-600"><?= $pendingQ ?></div>
        <div class="text-xs text-gray-400 mt-1">Pending Questions</div>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <div class="text-2xl font-bold text-purple-600"><?= $pendingA ?></div>
        <div class="text-xs text-gray-400 mt-1">Pending Answers</div>
    </div>
</div>

<!-- Tabs -->
<div class="flex gap-1 mb-6 bg-gray-100 rounded-xl p-1 overflow-x-auto">
    <?php foreach (['reviews'=>'Reviews','qna'=>'Q&A','dummy'=>'Add Dummy Review','settings'=>'Settings'] as $tk=>$tl): ?>
    <a href="?tab=<?= $tk ?>" class="px-4 py-2 text-sm font-medium rounded-lg whitespace-nowrap <?= $activeTab===$tk ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500 hover:text-gray-700' ?>"><?= $tl ?>
        <?php if ($tk==='reviews' && $pendingReviews>0): ?><span class="ml-1 text-[10px] bg-red-500 text-white px-1.5 py-0.5 rounded-full"><?= $pendingReviews ?></span><?php endif; ?>
        <?php if ($tk==='qna' && ($pendingQ+$pendingA)>0): ?><span class="ml-1 text-[10px] bg-orange-500 text-white px-1.5 py-0.5 rounded-full"><?= $pendingQ+$pendingA ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($activeTab === 'reviews'): ?>
<!-- ════════ REVIEWS TAB ════════ -->
<div class="flex gap-2 mb-4">
    <?php foreach (['pending'=>'Pending','approved'=>'Approved','all'=>'All'] as $fk=>$fl): ?>
    <a href="?tab=reviews&filter=<?= $fk ?>" class="px-3 py-1.5 text-xs font-medium rounded-lg <?= $filter===$fk ? 'bg-blue-600 text-white' : 'bg-white border text-gray-600 hover:bg-gray-50' ?>"><?= $fl ?></a>
    <?php endforeach; ?>
</div>

<?php
$where = 'WHERE 1=1';
if ($filter === 'pending') $where .= ' AND r.is_approved = 0';
elseif ($filter === 'approved') $where .= ' AND r.is_approved = 1';
$reviews = $db->fetchAll("SELECT r.*, p.name as product_name, p.slug as product_slug FROM product_reviews r LEFT JOIN products p ON p.id = r.product_id {$where} ORDER BY r.created_at DESC LIMIT 100");
?>

<?php if (empty($reviews)): ?>
<div class="bg-white rounded-xl border p-8 text-center text-gray-400">No reviews found.</div>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($reviews as $r): ?>
<div class="bg-white rounded-xl border p-4">
    <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1 flex-wrap">
                <span class="font-semibold text-sm text-gray-800"><?= e($r['reviewer_name']) ?></span>
                <?php if ($r['is_dummy']): ?><span class="text-[10px] bg-yellow-100 text-yellow-700 px-1.5 py-0.5 rounded-full font-medium">DUMMY</span><?php endif; ?>
                <?php if ($r['is_approved']): ?><span class="text-[10px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full">Approved</span><?php else: ?><span class="text-[10px] bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded-full">Pending</span><?php endif; ?>
                <?php if ($r['credit_awarded']): ?><span class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded-full"><i class="fas fa-coins mr-0.5"></i>Credit Given</span><?php endif; ?>
                <span class="text-[11px] text-gray-400"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></span>
            </div>
            <a href="<?= url($r['product_slug'] ?? '') ?>" target="_blank" class="text-xs text-blue-600 hover:underline mb-1 inline-block"><?= e($r['product_name'] ?? 'Product #'.$r['product_id']) ?></a>
            <div class="flex items-center gap-0.5 mb-1"><?php for($i=1;$i<=5;$i++): ?><span class="text-sm <?= $i<=$r['rating'] ? 'text-yellow-400' : 'text-gray-300' ?>">★</span><?php endfor; ?></div>
            <p class="text-sm text-gray-700"><?= e($r['review_text']) ?></p>
            <?php $imgs = json_decode($r['images'] ?? '[]', true); if (!empty($imgs)): ?>
            <div class="flex gap-2 mt-2"><?php foreach ($imgs as $img): ?><img src="<?= SITE_URL . $img ?>" class="w-16 h-16 object-cover rounded-lg border"><?php endforeach; ?></div>
            <?php endif; ?>
            <div class="text-[11px] text-gray-400 mt-1"><i class="fas fa-thumbs-up mr-1"></i><?= $r['likes_count'] ?> likes · IP: <?= $r['ip_address'] ?><?php if ($r['customer_id']): ?> · Customer #<?= $r['customer_id'] ?><?php endif; ?></div>
        </div>
        <div class="flex gap-1 shrink-0">
            <?php if (!$r['is_approved']): ?>
            <a href="?tab=reviews&filter=<?= $filter ?>&approve=<?= $r['id'] ?>&type=review" class="px-2.5 py-1.5 bg-green-50 text-green-600 rounded-lg text-xs font-medium hover:bg-green-100" title="Approve"><i class="fas fa-check"></i></a>
            <?php else: ?>
            <a href="?tab=reviews&filter=<?= $filter ?>&reject=<?= $r['id'] ?>&type=review" class="px-2.5 py-1.5 bg-orange-50 text-orange-600 rounded-lg text-xs font-medium hover:bg-orange-100" title="Unapprove"><i class="fas fa-ban"></i></a>
            <?php endif; ?>
            <a href="?tab=reviews&filter=<?= $filter ?>&delete_id=<?= $r['id'] ?>&type=review" onclick="return confirm('Delete this review?')" class="px-2.5 py-1.5 bg-red-50 text-red-600 rounded-lg text-xs font-medium hover:bg-red-100" title="Delete"><i class="fas fa-trash"></i></a>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($activeTab === 'qna'): ?>
<!-- ════════ Q&A TAB ════════ -->
<div class="flex gap-2 mb-4">
    <?php foreach (['pending'=>'Pending','approved'=>'Approved','all'=>'All'] as $fk=>$fl): ?>
    <a href="?tab=qna&filter=<?= $fk ?>" class="px-3 py-1.5 text-xs font-medium rounded-lg <?= $filter===$fk ? 'bg-blue-600 text-white' : 'bg-white border text-gray-600 hover:bg-gray-50' ?>"><?= $fl ?></a>
    <?php endforeach; ?>
</div>

<?php
$qWhere = 'WHERE 1=1';
if ($filter === 'pending') $qWhere .= ' AND q.is_approved = 0';
elseif ($filter === 'approved') $qWhere .= ' AND q.is_approved = 1';
$questions = $db->fetchAll("SELECT q.*, p.name as product_name, p.slug as product_slug FROM product_questions q LEFT JOIN products p ON p.id = q.product_id {$qWhere} ORDER BY q.created_at DESC LIMIT 100");
?>

<?php if (empty($questions)): ?>
<div class="bg-white rounded-xl border p-8 text-center text-gray-400">No questions found.</div>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($questions as $q): 
    $answers = $db->fetchAll("SELECT * FROM product_answers WHERE question_id = ? ORDER BY created_at ASC", [$q['id']]);
?>
<div class="bg-white rounded-xl border p-4">
    <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1 flex-wrap">
                <span class="text-xs font-semibold text-orange-600 bg-orange-50 px-1.5 py-0.5 rounded">Q</span>
                <span class="font-semibold text-sm"><?= e($q['asker_name']) ?></span>
                <?php if ($q['is_approved']): ?><span class="text-[10px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full">Approved</span><?php else: ?><span class="text-[10px] bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded-full">Pending</span><?php endif; ?>
                <span class="text-[11px] text-gray-400"><?= date('d M Y', strtotime($q['created_at'])) ?></span>
            </div>
            <a href="<?= url($q['product_slug'] ?? '') ?>" target="_blank" class="text-xs text-blue-600 hover:underline mb-1 inline-block"><?= e($q['product_name'] ?? 'Product #'.$q['product_id']) ?></a>
            <p class="text-sm text-gray-700 mb-2"><?= e($q['question_text']) ?></p>
            
            <?php foreach ($answers as $a): ?>
            <div class="ml-6 mb-2 bg-blue-50/60 rounded-lg p-3 border border-blue-100/50 flex items-start justify-between gap-2">
                <div class="flex-1">
                    <div class="flex items-center gap-1.5 mb-0.5">
                        <span class="text-xs font-semibold text-blue-600 bg-blue-100 px-1.5 py-0.5 rounded">A</span>
                        <span class="text-xs font-semibold"><?= e($a['answerer_name']) ?></span>
                        <?php if ($a['is_admin']): ?><span class="text-[9px] bg-blue-200 text-blue-700 px-1 py-0.5 rounded">Admin</span><?php endif; ?>
                        <?php if (!$a['is_approved']): ?><span class="text-[9px] bg-orange-100 text-orange-600 px-1 py-0.5 rounded">Pending</span><?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-700"><?= e($a['answer_text']) ?></p>
                </div>
                <div class="flex gap-1 shrink-0">
                    <?php if (!$a['is_approved']): ?>
                    <a href="?tab=qna&filter=<?= $filter ?>&approve=<?= $a['id'] ?>&type=answer" class="text-green-600 hover:text-green-700 text-xs" title="Approve"><i class="fas fa-check"></i></a>
                    <?php endif; ?>
                    <a href="?tab=qna&filter=<?= $filter ?>&delete_id=<?= $a['id'] ?>&type=answer" onclick="return confirm('Delete?')" class="text-red-400 hover:text-red-600 text-xs" title="Delete"><i class="fas fa-trash"></i></a>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Quick Admin Answer -->
            <form method="POST" class="ml-6 mt-2 flex gap-2">
                <input type="hidden" name="action" value="admin_answer">
                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                <input type="text" name="answer_text" placeholder="Write admin answer..." class="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none focus:border-blue-400">
                <button type="submit" class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-blue-700">Reply</button>
            </form>
        </div>
        <div class="flex gap-1 shrink-0">
            <?php if (!$q['is_approved']): ?>
            <a href="?tab=qna&filter=<?= $filter ?>&approve=<?= $q['id'] ?>&type=question" class="px-2.5 py-1.5 bg-green-50 text-green-600 rounded-lg text-xs font-medium hover:bg-green-100"><i class="fas fa-check"></i></a>
            <?php endif; ?>
            <a href="?tab=qna&filter=<?= $filter ?>&delete_id=<?= $q['id'] ?>&type=question" onclick="return confirm('Delete question + all answers?')" class="px-2.5 py-1.5 bg-red-50 text-red-600 rounded-lg text-xs font-medium hover:bg-red-100"><i class="fas fa-trash"></i></a>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($activeTab === 'dummy'): ?>
<!-- ════════ ADD DUMMY REVIEW TAB ════════ -->
<div class="bg-white rounded-xl border p-6 max-w-2xl">
    <h3 class="font-bold text-gray-800 mb-4"><i class="fas fa-star text-yellow-400 mr-1.5"></i>Add Dummy Review</h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_dummy">
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-600 mb-1">Product *</label>
            <select name="product_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm outline-none focus:border-blue-400" id="dummy-product">
                <option value="">Search and select...</option>
            </select>
            <input type="text" id="dummy-product-search" placeholder="Type product name to search..." class="mt-2 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400" oninput="searchProducts(this.value)">
            <div id="dummy-product-results" class="mt-1 bg-white border rounded-lg shadow-lg max-h-48 overflow-y-auto hidden"></div>
        </div>
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-600 mb-1">Reviewer Name *</label>
            <input type="text" name="reviewer_name" required value="Customer" class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm outline-none focus:border-blue-400">
        </div>
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-600 mb-1">Rating *</label>
            <select name="rating" class="border border-gray-200 rounded-lg px-3 py-2 text-sm">
                <option value="5">★★★★★ (5)</option>
                <option value="4">★★★★☆ (4)</option>
                <option value="3">★★★☆☆ (3)</option>
                <option value="2">★★☆☆☆ (2)</option>
                <option value="1">★☆☆☆☆ (1)</option>
            </select>
        </div>
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-600 mb-1">Review Text *</label>
            <textarea name="review_text" rows="4" required class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm outline-none focus:border-blue-400 resize-none" placeholder="Write the review..."></textarea>
        </div>
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-600 mb-1">Images (optional, max 5)</label>
            <input type="file" name="images[]" multiple accept="image/*" class="text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-600">
        </div>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition">Add Dummy Review</button>
    </form>
</div>

<script>
function searchProducts(q) {
    const res = document.getElementById('dummy-product-results');
    if (q.length < 2) { res.classList.add('hidden'); return; }
    fetch('<?= SITE_URL ?>/api/reviews.php?action=search_products&q=' + encodeURIComponent(q))
    .then(r=>r.json()).then(d => {
        if (!d.products || !d.products.length) { res.innerHTML = '<p class="p-3 text-sm text-gray-400">No products found</p>'; res.classList.remove('hidden'); return; }
        let h = '';
        d.products.forEach(p => {
            h += `<div class="px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm border-b" onclick="selectProduct(${p.id},'${p.name.replace(/'/g,"\\'")}')">
                <span class="font-medium">#${p.id}</span> — ${p.name}
            </div>`;
        });
        res.innerHTML = h; res.classList.remove('hidden');
    }).catch(()=>{});
}
function selectProduct(id, name) {
    const sel = document.getElementById('dummy-product');
    sel.innerHTML = `<option value="${id}" selected>#${id} — ${name}</option>`;
    document.getElementById('dummy-product-results').classList.add('hidden');
    document.getElementById('dummy-product-search').value = name;
}
</script>

<?php elseif ($activeTab === 'settings'): ?>
<!-- ════════ SETTINGS TAB ════════ -->
<div class="bg-white rounded-xl border p-6 max-w-2xl">
    <h3 class="font-bold text-gray-800 mb-4"><i class="fas fa-cog text-gray-400 mr-1.5"></i>Reviews & Q&A Settings</h3>
    <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        
        <div class="space-y-5">
            <div class="flex items-center justify-between py-3 border-b">
                <div><p class="font-medium text-sm">Reviews Enabled</p><p class="text-xs text-gray-400">Allow customers to submit reviews</p></div>
                <label class="relative inline-flex items-center cursor-pointer"><input type="hidden" name="reviews_enabled" value="0"><input type="checkbox" name="reviews_enabled" value="1" <?= getSetting('reviews_enabled','1')==='1'?'checked':'' ?> class="sr-only peer"><div class="w-10 h-5 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div></label>
            </div>
            
            <div class="flex items-center justify-between py-3 border-b">
                <div><p class="font-medium text-sm">Q&A Enabled</p><p class="text-xs text-gray-400">Allow questions and answers on products</p></div>
                <label class="relative inline-flex items-center cursor-pointer"><input type="hidden" name="qna_enabled" value="0"><input type="checkbox" name="qna_enabled" value="1" <?= getSetting('qna_enabled','1')==='1'?'checked':'' ?> class="sr-only peer"><div class="w-10 h-5 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div></label>
            </div>
            
            <div class="flex items-center justify-between py-3 border-b">
                <div><p class="font-medium text-sm">Require Purchase for Review</p><p class="text-xs text-gray-400">Only customers who bought & received the product can review</p></div>
                <label class="relative inline-flex items-center cursor-pointer"><input type="hidden" name="review_require_purchase" value="0"><input type="checkbox" name="review_require_purchase" value="1" <?= getSetting('review_require_purchase','1')==='1'?'checked':'' ?> class="sr-only peer"><div class="w-10 h-5 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div></label>
            </div>
            
            <div class="flex items-center justify-between py-3 border-b">
                <div><p class="font-medium text-sm">Auto-Approve Reviews</p><p class="text-xs text-gray-400">Publish reviews without manual approval</p></div>
                <label class="relative inline-flex items-center cursor-pointer"><input type="hidden" name="review_auto_approve" value="0"><input type="checkbox" name="review_auto_approve" value="1" <?= getSetting('review_auto_approve','0')==='1'?'checked':'' ?> class="sr-only peer"><div class="w-10 h-5 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div></label>
            </div>
            
            <div class="flex items-center justify-between py-3 border-b">
                <div><p class="font-medium text-sm">Auto-Approve Q&A</p><p class="text-xs text-gray-400">Publish questions & answers automatically</p></div>
                <label class="relative inline-flex items-center cursor-pointer"><input type="hidden" name="qna_auto_approve" value="0"><input type="checkbox" name="qna_auto_approve" value="1" <?= getSetting('qna_auto_approve','1')==='1'?'checked':'' ?> class="sr-only peer"><div class="w-10 h-5 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div></label>
            </div>
            
            <div class="grid sm:grid-cols-2 gap-4 py-3 border-b">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Minimum Words for Review</label>
                    <input type="number" name="review_min_words" value="<?= getSetting('review_min_words','10') ?>" min="1" max="500" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Max Review Images</label>
                    <input type="number" name="review_images_max" value="<?= getSetting('review_images_max','3') ?>" min="0" max="10" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400">
                </div>
            </div>
            
            <div class="grid sm:grid-cols-2 gap-4 py-3">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Review Credit Reward</label>
                    <div class="flex items-center gap-1">
                        <input type="number" name="review_credit_reward" value="<?= getSetting('review_credit_reward','50') ?>" min="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400">
                        <span class="text-xs text-gray-400 whitespace-nowrap">credits</span>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">0 = no reward</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Q&A Answer Credit Reward</label>
                    <div class="flex items-center gap-1">
                        <input type="number" name="qna_credit_reward" value="<?= getSetting('qna_credit_reward','20') ?>" min="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-400">
                        <span class="text-xs text-gray-400 whitespace-nowrap">credits</span>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">0 = no reward</p>
                </div>
            </div>
        </div>
        
        <button type="submit" class="mt-6 bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition">Save Settings</button>
    </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
