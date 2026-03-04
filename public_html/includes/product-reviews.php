<?php
/**
 * Product Reviews & Q&A Section
 * Included inside product.php — uses $product variable
 */
$reviewsEnabled = getSetting('reviews_enabled', '1') === '1';
$qnaEnabled = getSetting('qna_enabled', '1') === '1';
$reviewCredit = intval(getSetting('review_credit_reward', '50'));
$reviewMinWords = intval(getSetting('review_min_words', '10'));
$maxImages = intval(getSetting('review_images_max', '3'));
$canReview = false;
$custIdForReview = null;

if ($reviewsEnabled && isCustomerLoggedIn()) {
    $custIdForReview = getCustomerId();
    $dbr = Database::getInstance();
    if (getSetting('review_require_purchase', '1') === '1') {
        $hasPurchased = $dbr->fetch(
            "SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id = o.id 
             WHERE (o.customer_id = ? OR o.customer_phone = (SELECT phone FROM customers WHERE id = ?))
             AND o.order_status = 'delivered' AND oi.product_id = ?", [$custIdForReview, $custIdForReview, $product['id']]
        );
        $hasReviewed = $dbr->fetch("SELECT 1 FROM product_reviews WHERE customer_id = ? AND product_id = ? AND is_dummy = 0", [$custIdForReview, $product['id']]);
        $canReview = $hasPurchased && !$hasReviewed;
    } else {
        $hasReviewed = $dbr->fetch("SELECT 1 FROM product_reviews WHERE customer_id = ? AND product_id = ? AND is_dummy = 0", [$custIdForReview, $product['id']]);
        $canReview = !$hasReviewed;
    }
}
?>

<!-- ═══ TABS: Description / Reviews / Q&A ═══ -->
<div class="mt-10">
    <div class="border-b mb-6 flex gap-0 overflow-x-auto" style="scrollbar-width:none">
        <button class="tab-btn px-5 py-3 font-semibold text-sm border-b-2 border-red-500 text-red-600 whitespace-nowrap" data-tab="description">বিবরণ</button>
        <?php if ($reviewsEnabled): ?>
        <button class="tab-btn px-5 py-3 font-semibold text-sm text-gray-500 hover:text-gray-700 whitespace-nowrap" data-tab="reviews">
            রিভিউ <span id="review-count-badge" class="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full ml-1">0</span>
        </button>
        <?php endif; ?>
        <?php if ($qnaEnabled): ?>
        <button class="tab-btn px-5 py-3 font-semibold text-sm text-gray-500 hover:text-gray-700 whitespace-nowrap" data-tab="qna">
            প্রশ্ন-উত্তর <span id="qna-count-badge" class="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full ml-1">0</span>
        </button>
        <?php endif; ?>
    </div>
    
    <!-- DESCRIPTION TAB -->
    <div id="tab-description" class="tab-content bg-white rounded-xl p-6 shadow-sm border border-gray-100">
        <div class="prose max-w-none">
            <?= $product['description'] ?: '<p class="text-gray-500">কোনো বিবরণ যুক্ত করা হয়নি।</p>' ?>
        </div>
    </div>
    
    <?php if ($reviewsEnabled): ?>
    <!-- REVIEWS TAB -->
    <div id="tab-reviews" class="tab-content hidden">
        <!-- Stats Summary -->
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100 mb-4">
            <div class="flex flex-col sm:flex-row gap-6 items-start sm:items-center">
                <div class="text-center sm:min-w-[120px]">
                    <div id="rev-avg" class="text-4xl font-extrabold text-gray-800">0.0</div>
                    <div id="rev-stars-summary" class="flex justify-center gap-0.5 my-1 text-yellow-400 text-lg"></div>
                    <div id="rev-total" class="text-xs text-gray-400">0 রিভিউ</div>
                </div>
                <div class="flex-1 w-full space-y-1.5" id="rev-bars">
                    <?php for ($s = 5; $s >= 1; $s--): ?>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-8 text-right text-gray-500 font-medium"><?= $s ?> ★</span>
                        <div class="flex-1 bg-gray-100 rounded-full h-2.5 overflow-hidden">
                            <div id="bar-<?= $s ?>" class="h-full bg-yellow-400 rounded-full transition-all" style="width:0%"></div>
                        </div>
                        <span id="cnt-<?= $s ?>" class="w-6 text-gray-400 text-right">0</span>
                    </div>
                    <?php endfor; ?>
                </div>
                <?php if ($canReview): ?>
                <div class="text-center sm:min-w-[160px]">
                    <button onclick="openReviewForm()" class="bg-red-500 hover:bg-red-600 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition">
                        <i class="fas fa-pen mr-1.5"></i>রিভিউ লিখুন
                    </button>
                    <?php if ($reviewCredit > 0): ?>
                    <p class="text-[11px] text-green-600 mt-1.5"><i class="fas fa-coins mr-1"></i><?= $reviewCredit ?> ক্রেডিট পাবেন!</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Write Review Form (hidden) -->
        <?php if ($canReview): ?>
        <div id="review-form-wrap" class="hidden bg-white rounded-xl p-5 shadow-sm border border-blue-100 mb-4">
            <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-star text-yellow-400 mr-1.5"></i>আপনার রিভিউ দিন</h3>
            <form id="reviewForm" enctype="multipart/form-data" onsubmit="submitReview(event)">
                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                <!-- Star Rating -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-2">রেটিং দিন *</label>
                    <div id="star-picker" class="flex gap-1 text-3xl text-gray-300 cursor-pointer">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span data-star="<?= $i ?>" onmouseover="hoverStar(<?= $i ?>)" onmouseout="hoverStar(0)" onclick="pickStar(<?= $i ?>)">★</span>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="rating" id="review-rating" value="0">
                </div>
                <!-- Review Text -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-1">রিভিউ লিখুন * <span class="text-gray-400 font-normal">(কমপক্ষে <?= $reviewMinWords ?> শব্দ)</span></label>
                    <textarea name="review_text" id="review-text" rows="4" class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-blue-400 transition resize-none" placeholder="পণ্যটি সম্পর্কে আপনার অভিজ্ঞতা লিখুন..." required></textarea>
                    <div class="text-right text-xs text-gray-400 mt-1"><span id="word-count">0</span>/<?= $reviewMinWords ?> শব্দ</div>
                </div>
                <!-- Images -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-1">ছবি যোগ করুন <span class="text-gray-400 font-normal">(ঐচ্ছিক, সর্বোচ্চ <?= $maxImages ?>টি)</span></label>
                    <input type="file" name="images[]" multiple accept="image/*" max="<?= $maxImages ?>" class="text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100">
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" id="review-submit-btn" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl text-sm font-semibold transition">জমা দিন</button>
                    <button type="button" onclick="document.getElementById('review-form-wrap').classList.add('hidden')" class="text-gray-500 hover:text-gray-700 text-sm">বাতিল</button>
                    <div id="review-msg" class="text-sm ml-auto"></div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Reviews List -->
        <div id="reviews-list" class="space-y-3"></div>
        <div id="reviews-loading" class="text-center py-8 text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>লোড হচ্ছে...</div>
        <div id="reviews-empty" class="hidden text-center py-10 bg-white rounded-xl border border-gray-100">
            <i class="far fa-comment-dots text-4xl text-gray-300 mb-3"></i>
            <p class="text-gray-400">এখনো কোনো রিভিউ নেই।</p>
            <?php if ($canReview): ?><button onclick="openReviewForm()" class="mt-3 text-blue-600 text-sm font-semibold hover:underline">প্রথম রিভিউ দিন!</button><?php endif; ?>
        </div>
        <div id="reviews-more" class="hidden text-center mt-4">
            <button onclick="loadMoreReviews()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2.5 rounded-xl text-sm font-medium transition">আরও দেখুন</button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($qnaEnabled): ?>
    <!-- Q&A TAB -->
    <div id="tab-qna" class="tab-content hidden">
        <!-- Ask Question Form -->
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100 mb-4">
            <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-question-circle text-blue-500 mr-1.5"></i>প্রশ্ন করুন</h3>
            <form id="qnaForm" onsubmit="submitQuestion(event)">
                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                <?php if (!isCustomerLoggedIn()): ?>
                <div class="mb-3">
                    <input type="text" name="asker_name" placeholder="আপনার নাম *" required class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-blue-400 transition">
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <textarea name="question_text" rows="2" placeholder="আপনার প্রশ্ন লিখুন..." required class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-blue-400 transition resize-none"></textarea>
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-xl text-sm font-semibold transition">প্রশ্ন জমা দিন</button>
                    <div id="qna-msg" class="text-sm"></div>
                </div>
            </form>
        </div>

        <!-- Questions List -->
        <div id="qna-list" class="space-y-3"></div>
        <div id="qna-loading" class="text-center py-8 text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>লোড হচ্ছে...</div>
        <div id="qna-empty" class="hidden text-center py-10 bg-white rounded-xl border border-gray-100">
            <i class="far fa-comment-dots text-4xl text-gray-300 mb-3"></i>
            <p class="text-gray-400">এখনো কোনো প্রশ্ন নেই।</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ REVIEWS & Q&A JAVASCRIPT ═══ -->
<script>
const REVIEW_PRODUCT_ID = <?= $product['id'] ?>;
const REVIEW_MIN_WORDS = <?= $reviewMinWords ?>;
const REVIEWS_ENABLED = <?= $reviewsEnabled ? 'true' : 'false' ?>;
const QNA_ENABLED = <?= $qnaEnabled ? 'true' : 'false' ?>;
let reviewPage = 1, reviewTotal = 0;

// ── Star Rating Picker ──
let pickedStar = 0;
function hoverStar(n) {
    document.querySelectorAll('#star-picker span').forEach((s,i) => {
        s.style.color = (i < (n || pickedStar)) ? '#facc15' : '#d1d5db';
    });
}
function pickStar(n) {
    pickedStar = n;
    document.getElementById('review-rating').value = n;
    hoverStar(n);
}

// ── Word Counter ──
document.getElementById('review-text')?.addEventListener('input', function() {
    const wc = this.value.trim().split(/\s+/).filter(w=>w).length;
    const el = document.getElementById('word-count');
    el.textContent = wc;
    el.className = wc >= REVIEW_MIN_WORDS ? 'text-green-600 font-semibold' : '';
});

function openReviewForm() {
    document.getElementById('review-form-wrap')?.classList.remove('hidden');
    document.getElementById('review-form-wrap')?.scrollIntoView({behavior:'smooth', block:'center'});
}

// ── Stars HTML helper ──
function starsHtml(rating, size='text-sm') {
    let h = '';
    for (let i = 1; i <= 5; i++) {
        h += `<span class="${size} ${i <= rating ? 'text-yellow-400' : 'text-gray-300'}">★</span>`;
    }
    return h;
}

function timeAgo(d) {
    const now = new Date(), t = new Date(d), diff = Math.floor((now - t) / 1000);
    if (diff < 60) return 'এইমাত্র';
    if (diff < 3600) return Math.floor(diff/60) + ' মিনিট আগে';
    if (diff < 86400) return Math.floor(diff/3600) + ' ঘন্টা আগে';
    if (diff < 2592000) return Math.floor(diff/86400) + ' দিন আগে';
    return t.toLocaleDateString('bn-BD');
}

// ════════════════════════════════════
// LOAD REVIEWS
// ════════════════════════════════════
function loadReviews(page = 1) {
    if (!REVIEWS_ENABLED) return;
    fetch(`<?= SITE_URL ?>/api/reviews.php?action=get_reviews&product_id=${REVIEW_PRODUCT_ID}&page=${page}`)
    .then(r=>r.json()).then(d => {
        if (!d.success) return;
        
        const s = d.stats;
        reviewTotal = d.total;
        reviewPage = page;
        
        // Update stats
        document.getElementById('rev-avg').textContent = parseFloat(s.avg_rating || 0).toFixed(1);
        document.getElementById('rev-total').textContent = `${s.total || 0} রিভিউ`;
        document.getElementById('review-count-badge').textContent = s.total || 0;
        
        let starsH = '';
        for (let i = 1; i <= 5; i++) starsH += `<span class="${i <= Math.round(s.avg_rating||0) ? 'text-yellow-400' : 'text-gray-300'}">★</span>`;
        document.getElementById('rev-stars-summary').innerHTML = starsH;
        
        const total = parseInt(s.total) || 1;
        for (let i = 1; i <= 5; i++) {
            const cnt = parseInt(s['s'+i]) || 0;
            document.getElementById('bar-'+i).style.width = ((cnt/total)*100) + '%';
            document.getElementById('cnt-'+i).textContent = cnt;
        }
        
        // Render reviews
        const list = document.getElementById('reviews-list');
        if (page === 1) list.innerHTML = '';
        
        d.reviews.forEach(r => {
            const imgs = (r.images||[]).map(img => 
                `<img src="<?= SITE_URL ?>${img}" class="w-16 h-16 object-cover rounded-lg border cursor-pointer hover:opacity-80 transition" onclick="document.getElementById('viewer-image').src=this.src;document.getElementById('image-viewer').classList.remove('hidden')">`
            ).join('');
            
            list.innerHTML += `
            <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-start justify-between mb-2">
                    <div>
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center text-sm font-bold text-gray-500">${(r.reviewer_name||'U')[0].toUpperCase()}</div>
                            <div>
                                <p class="text-sm font-semibold text-gray-800">${r.reviewer_name || 'Customer'}</p>
                                <div class="flex items-center gap-1">${starsHtml(r.rating, 'text-xs')}<span class="text-[11px] text-gray-400 ml-1">${timeAgo(r.created_at)}</span></div>
                            </div>
                        </div>
                    </div>
                    ${r.is_dummy ? '' : '<span class="text-[10px] bg-green-50 text-green-600 px-2 py-0.5 rounded-full font-medium"><i class="fas fa-check-circle mr-0.5"></i>যাচাইকৃত ক্রেতা</span>'}
                </div>
                <p class="text-sm text-gray-700 leading-relaxed mb-2">${r.review_text}</p>
                ${imgs ? `<div class="flex gap-2 mb-2 flex-wrap">${imgs}</div>` : ''}
                <button onclick="likeReview(${r.id}, this)" class="flex items-center gap-1.5 text-xs ${r.user_liked ? 'text-blue-600' : 'text-gray-400 hover:text-blue-500'} transition">
                    <i class="${r.user_liked ? 'fas' : 'far'} fa-thumbs-up"></i>
                    <span>সহায়ক (${r.likes_count || 0})</span>
                </button>
            </div>`;
        });
        
        document.getElementById('reviews-loading').classList.add('hidden');
        if (d.total === 0) document.getElementById('reviews-empty').classList.remove('hidden');
        else document.getElementById('reviews-empty').classList.add('hidden');
        
        document.getElementById('reviews-more').classList.toggle('hidden', d.reviews.length < 10 || (page * 10) >= d.total);
    }).catch(() => { document.getElementById('reviews-loading').classList.add('hidden'); });
}
function loadMoreReviews() { loadReviews(reviewPage + 1); }

// ── Submit Review ──
function submitReview(e) {
    e.preventDefault();
    const form = document.getElementById('reviewForm');
    const btn = document.getElementById('review-submit-btn');
    const msgEl = document.getElementById('review-msg');
    
    if (!document.getElementById('review-rating').value || document.getElementById('review-rating').value === '0') {
        msgEl.innerHTML = '<span class="text-red-500">রেটিং দিন</span>'; return;
    }
    
    btn.disabled = true; btn.textContent = 'জমা হচ্ছে...';
    const fd = new FormData(form);
    fd.append('action', 'submit_review');
    
    fetch('<?= SITE_URL ?>/api/reviews.php', {method:'POST', body:fd})
    .then(r=>r.json()).then(d => {
        if (d.success) {
            msgEl.innerHTML = `<span class="text-green-600"><i class="fas fa-check-circle mr-1"></i>${d.message}</span>`;
            form.reset(); pickedStar = 0; hoverStar(0);
            setTimeout(() => { document.getElementById('review-form-wrap').classList.add('hidden'); loadReviews(1); }, 2000);
        } else {
            msgEl.innerHTML = `<span class="text-red-500">${d.message}</span>`;
        }
        btn.disabled = false; btn.textContent = 'জমা দিন';
    }).catch(() => { btn.disabled = false; btn.textContent = 'জমা দিন'; });
}

// ── Like ──
function likeReview(id, btn) {
    fetch('<?= SITE_URL ?>/api/reviews.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=like_review&review_id=${id}`
    }).then(r=>r.json()).then(d => {
        if (d.success) {
            const icon = btn.querySelector('i');
            const span = btn.querySelector('span');
            const cnt = parseInt(span.textContent.match(/\d+/)?.[0] || 0);
            if (d.liked) {
                btn.classList.remove('text-gray-400'); btn.classList.add('text-blue-600');
                icon.classList.remove('far'); icon.classList.add('fas');
                span.textContent = `সহায়ক (${cnt+1})`;
            } else {
                btn.classList.add('text-gray-400'); btn.classList.remove('text-blue-600');
                icon.classList.add('far'); icon.classList.remove('fas');
                span.textContent = `সহায়ক (${Math.max(0,cnt-1)})`;
            }
        }
    });
}

// ════════════════════════════════════
// LOAD Q&A
// ════════════════════════════════════
function loadQnA() {
    if (!QNA_ENABLED) return;
    fetch(`<?= SITE_URL ?>/api/reviews.php?action=get_questions&product_id=${REVIEW_PRODUCT_ID}`)
    .then(r=>r.json()).then(d => {
        if (!d.success) return;
        document.getElementById('qna-count-badge').textContent = d.total;
        document.getElementById('qna-loading').classList.add('hidden');
        
        const list = document.getElementById('qna-list');
        list.innerHTML = '';
        
        if (d.questions.length === 0) {
            document.getElementById('qna-empty').classList.remove('hidden');
            return;
        }
        
        d.questions.forEach(q => {
            let answersHtml = '';
            (q.answers||[]).forEach(a => {
                answersHtml += `
                <div class="ml-8 mt-2 bg-blue-50/50 rounded-lg p-3 border border-blue-100/50">
                    <div class="flex items-center gap-1.5 mb-1">
                        <span class="text-xs font-semibold ${a.is_admin ? 'text-blue-600' : 'text-gray-700'}">${a.answerer_name}</span>
                        ${a.is_admin ? '<span class="text-[10px] bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-full">Admin</span>' : ''}
                        <span class="text-[11px] text-gray-400">${timeAgo(a.created_at)}</span>
                    </div>
                    <p class="text-sm text-gray-700">${a.answer_text}</p>
                </div>`;
            });
            
            list.innerHTML += `
            <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-orange-100 rounded-full flex items-center justify-center text-sm font-bold text-orange-500 shrink-0">Q</div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-sm font-semibold text-gray-800">${q.asker_name}</span>
                            <span class="text-[11px] text-gray-400">${timeAgo(q.created_at)}</span>
                        </div>
                        <p class="text-sm text-gray-700 mb-1">${q.question_text}</p>
                        ${answersHtml}
                    </div>
                </div>
            </div>`;
        });
    }).catch(() => { document.getElementById('qna-loading').classList.add('hidden'); });
}

// ── Submit Question ──
function submitQuestion(e) {
    e.preventDefault();
    const form = document.getElementById('qnaForm');
    const fd = new FormData(form);
    const msgEl = document.getElementById('qna-msg');
    
    fetch('<?= SITE_URL ?>/api/reviews.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=ask_question&product_id=${fd.get('product_id')}&asker_name=${encodeURIComponent(fd.get('asker_name')||'')}&question_text=${encodeURIComponent(fd.get('question_text')||'')}`
    }).then(r=>r.json()).then(d => {
        if (d.success) {
            msgEl.innerHTML = `<span class="text-green-600"><i class="fas fa-check-circle mr-1"></i>${d.message}</span>`;
            form.reset();
            setTimeout(() => { msgEl.innerHTML = ''; loadQnA(); }, 2000);
        } else {
            msgEl.innerHTML = `<span class="text-red-500">${d.message}</span>`;
        }
    });
}

// ── Load on page ready ──
document.addEventListener('DOMContentLoaded', () => {
    if (REVIEWS_ENABLED) loadReviews(1);
    if (QNA_ENABLED) loadQnA();
});
</script>
