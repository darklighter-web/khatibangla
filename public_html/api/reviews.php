<?php
/**
 * Reviews & Q&A API
 * Handles: submit review, like review, submit question, submit answer, admin actions
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$db = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
$ip = explode(',', $ip)[0];

// ── Helper: get pending review count for customer ──
function getPendingReviewCount($customerId) {
    $db = Database::getInstance();
    try {
        $r = $db->fetch(
            "SELECT COUNT(*) as cnt FROM orders o 
             JOIN order_items oi ON oi.order_id = o.id
             WHERE (o.customer_id = ? OR o.customer_phone = (SELECT phone FROM customers WHERE id = ?))
             AND o.order_status = 'delivered'
             AND oi.product_id NOT IN (
                 SELECT product_id FROM product_reviews WHERE customer_id = ? AND is_dummy = 0
             )", [$customerId, $customerId, $customerId]
        );
        return intval($r['cnt'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

// ── Helper: get products eligible for review ──
function getReviewableProducts($customerId) {
    $db = Database::getInstance();
    try {
        return $db->fetchAll(
            "SELECT DISTINCT p.id, p.name, p.name_bn, p.slug, p.featured_image, p.sale_price, p.regular_price
             FROM orders o 
             JOIN order_items oi ON oi.order_id = o.id
             JOIN products p ON p.id = oi.product_id
             WHERE (o.customer_id = ? OR o.customer_phone = (SELECT phone FROM customers WHERE id = ?))
             AND o.order_status = 'delivered'
             AND p.id NOT IN (
                 SELECT product_id FROM product_reviews WHERE customer_id = ? AND is_dummy = 0
             )
             ORDER BY o.delivered_at DESC, o.updated_at DESC", [$customerId, $customerId, $customerId]
        );
    } catch (\Throwable $e) { return []; }
}

switch ($action) {

// ════════════════════════════════════
// GET REVIEWS FOR A PRODUCT
// ════════════════════════════════════
case 'get_reviews':
    $productId = intval($_GET['product_id'] ?? 0);
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    if (!$productId) { echo json_encode(['success'=>false,'message'=>'Missing product_id']); exit; }
    
    $reviews = $db->fetchAll(
        "SELECT r.*, 
                (SELECT COUNT(*) FROM review_likes rl WHERE rl.review_id = r.id) as likes_count
         FROM product_reviews r 
         WHERE r.product_id = ? AND r.is_approved = 1 
         ORDER BY r.is_dummy = 0 DESC, r.created_at DESC 
         LIMIT ? OFFSET ?", [$productId, $limit, $offset]
    );
    
    $total = $db->fetch("SELECT COUNT(*) as cnt FROM product_reviews WHERE product_id = ? AND is_approved = 1", [$productId]);
    $stats = $db->fetch(
        "SELECT COUNT(*) as total, ROUND(AVG(rating),1) as avg_rating,
                SUM(rating=5) as s5, SUM(rating=4) as s4, SUM(rating=3) as s3, SUM(rating=2) as s2, SUM(rating=1) as s1
         FROM product_reviews WHERE product_id = ? AND is_approved = 1", [$productId]
    );
    
    // Check if current user already liked
    $custId = isCustomerLoggedIn() ? getCustomerId() : null;
    foreach ($reviews as &$rev) {
        $rev['images'] = json_decode($rev['images'] ?? '[]', true) ?: [];
        $rev['user_liked'] = false;
        if ($custId) {
            $liked = $db->fetch("SELECT id FROM review_likes WHERE review_id = ? AND customer_id = ?", [$rev['id'], $custId]);
            $rev['user_liked'] = !!$liked;
        } else {
            $liked = $db->fetch("SELECT id FROM review_likes WHERE review_id = ? AND ip_address = ?", [$rev['id'], $ip]);
            $rev['user_liked'] = !!$liked;
        }
    }
    
    // Can current user review?
    $canReview = false;
    if ($custId && getSetting('review_require_purchase', '1') === '1') {
        $hasPurchased = $db->fetch(
            "SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id = o.id 
             WHERE (o.customer_id = ? OR o.customer_phone = (SELECT phone FROM customers WHERE id = ?))
             AND o.order_status = 'delivered' AND oi.product_id = ?", [$custId, $custId, $productId]
        );
        $hasReviewed = $db->fetch("SELECT 1 FROM product_reviews WHERE customer_id = ? AND product_id = ? AND is_dummy = 0", [$custId, $productId]);
        $canReview = $hasPurchased && !$hasReviewed;
    } elseif ($custId) {
        $hasReviewed = $db->fetch("SELECT 1 FROM product_reviews WHERE customer_id = ? AND product_id = ? AND is_dummy = 0", [$custId, $productId]);
        $canReview = !$hasReviewed;
    }
    
    echo json_encode([
        'success' => true,
        'reviews' => $reviews,
        'stats' => $stats ?: ['total'=>0,'avg_rating'=>0,'s5'=>0,'s4'=>0,'s3'=>0,'s2'=>0,'s1'=>0],
        'total' => intval($total['cnt'] ?? 0),
        'page' => $page,
        'can_review' => $canReview,
        'credit_reward' => intval(getSetting('review_credit_reward', '50')),
    ]);
    break;

// ════════════════════════════════════
// SUBMIT A REVIEW
// ════════════════════════════════════
case 'submit_review':
    if (!isCustomerLoggedIn()) { echo json_encode(['success'=>false,'message'=>'লগইন করুন']); exit; }
    if (getSetting('reviews_enabled', '1') !== '1') { echo json_encode(['success'=>false,'message'=>'রিভিউ বন্ধ আছে']); exit; }
    
    $custId = getCustomerId();
    $productId = intval($_POST['product_id'] ?? 0);
    $rating = intval($_POST['rating'] ?? 0);
    $text = trim($_POST['review_text'] ?? '');
    $minWords = intval(getSetting('review_min_words', '10'));
    
    if (!$productId || $rating < 1 || $rating > 5) { echo json_encode(['success'=>false,'message'=>'রেটিং দিন (১-৫)']); exit; }
    
    $wordCount = count(preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY));
    if ($wordCount < $minWords) { echo json_encode(['success'=>false,'message'=>"কমপক্ষে {$minWords} শব্দ লিখুন ({$wordCount} লেখা হয়েছে)"]); exit; }
    
    // Check purchase requirement
    if (getSetting('review_require_purchase', '1') === '1') {
        $hasPurchased = $db->fetch(
            "SELECT o.id FROM orders o JOIN order_items oi ON oi.order_id = o.id 
             WHERE (o.customer_id = ? OR o.customer_phone = (SELECT phone FROM customers WHERE id = ?))
             AND o.order_status = 'delivered' AND oi.product_id = ? LIMIT 1", [$custId, $custId, $productId]
        );
        if (!$hasPurchased) { echo json_encode(['success'=>false,'message'=>'এই পণ্যটি কিনে ডেলিভারি পাওয়ার পরে রিভিউ দিতে পারবেন']); exit; }
    }
    
    // Check duplicate
    $exists = $db->fetch("SELECT 1 FROM product_reviews WHERE customer_id = ? AND product_id = ? AND is_dummy = 0", [$custId, $productId]);
    if ($exists) { echo json_encode(['success'=>false,'message'=>'আপনি এই পণ্যে আগেই রিভিউ দিয়েছেন']); exit; }
    
    // Handle image uploads
    $images = [];
    $maxImages = intval(getSetting('review_images_max', '3'));
    $uploadsDir = rtrim(UPLOAD_PATH, '/') . '/review-images/';
    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }
    
    if (!empty($_FILES['images'])) {
        $files = $_FILES['images'];
        $count = min(count($files['name']), $maxImages);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ($files['size'][$i] > 5 * 1024 * 1024) continue;
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) continue;
            $fname = 'rev_' . $custId . '_' . $productId . '_' . time() . '_' . $i . '.' . $ext;
            if (move_uploaded_file($files['tmp_name'][$i], $uploadsDir . $fname)) {
                $images[] = '/uploads/review-images/' . $fname;
            }
        }
    }
    
    $customer = getCustomer();
    $autoApprove = getSetting('review_auto_approve', '0') === '1';
    
    $db->insert('product_reviews', [
        'product_id' => $productId,
        'customer_id' => $custId,
        'order_id' => intval($hasPurchased['id'] ?? 0),
        'rating' => $rating,
        'review_text' => $text,
        'images' => json_encode($images),
        'reviewer_name' => $customer['name'],
        'is_approved' => $autoApprove ? 1 : 0,
        'is_dummy' => 0,
        'ip_address' => $ip,
    ]);
    
    $msg = $autoApprove 
        ? 'রিভিউ প্রকাশিত হয়েছে! ধন্যবাদ।' 
        : 'রিভিউ জমা হয়েছে! অনুমোদনের পর প্রকাশিত হবে।';
    
    // Award credit immediately if auto-approve
    if ($autoApprove) {
        $reward = intval(getSetting('review_credit_reward', '50'));
        if ($reward > 0) {
            $reviewId = $db->lastInsertId();
            addStoreCredit($custId, $reward, 'earn', 'review', $reviewId, "রিভিউ বোনাস (পণ্য #{$productId})");
            $db->query("UPDATE product_reviews SET credit_awarded = 1 WHERE id = ?", [$reviewId]);
            $msg .= " ৳{$reward} ক্রেডিট যোগ হয়েছে!";
        }
    }
    
    echo json_encode(['success'=>true,'message'=>$msg]);
    break;

// ════════════════════════════════════
// LIKE A REVIEW
// ════════════════════════════════════
case 'like_review':
    $reviewId = intval($_POST['review_id'] ?? 0);
    if (!$reviewId) { echo json_encode(['success'=>false]); exit; }
    
    $custId = isCustomerLoggedIn() ? getCustomerId() : null;
    
    try {
        if ($custId) {
            $exists = $db->fetch("SELECT id FROM review_likes WHERE review_id = ? AND customer_id = ?", [$reviewId, $custId]);
            if ($exists) {
                $db->delete('review_likes', 'id = ?', [$exists['id']]);
                echo json_encode(['success'=>true,'liked'=>false]);
            } else {
                $db->insert('review_likes', ['review_id'=>$reviewId,'customer_id'=>$custId,'ip_address'=>$ip]);
                echo json_encode(['success'=>true,'liked'=>true]);
            }
        } else {
            $exists = $db->fetch("SELECT id FROM review_likes WHERE review_id = ? AND ip_address = ?", [$reviewId, $ip]);
            if ($exists) {
                $db->delete('review_likes', 'id = ?', [$exists['id']]);
                echo json_encode(['success'=>true,'liked'=>false]);
            } else {
                $db->insert('review_likes', ['review_id'=>$reviewId,'ip_address'=>$ip]);
                echo json_encode(['success'=>true,'liked'=>true]);
            }
        }
        // Update count
        $cnt = $db->fetch("SELECT COUNT(*) as c FROM review_likes WHERE review_id = ?", [$reviewId]);
        $db->query("UPDATE product_reviews SET likes_count = ? WHERE id = ?", [intval($cnt['c']), $reviewId]);
    } catch (\Throwable $e) {
        echo json_encode(['success'=>false,'message'=>'Already liked']);
    }
    break;

// ════════════════════════════════════
// GET Q&A
// ════════════════════════════════════
case 'get_questions':
    $productId = intval($_GET['product_id'] ?? 0);
    if (!$productId) { echo json_encode(['success'=>false]); exit; }
    
    $questions = $db->fetchAll(
        "SELECT q.*, 
                (SELECT COUNT(*) FROM product_answers a WHERE a.question_id = q.id AND a.is_approved = 1) as answer_count
         FROM product_questions q 
         WHERE q.product_id = ? AND q.is_approved = 1 
         ORDER BY q.created_at DESC LIMIT 50", [$productId]
    );
    
    foreach ($questions as &$q) {
        $q['answers'] = $db->fetchAll(
            "SELECT * FROM product_answers WHERE question_id = ? AND is_approved = 1 ORDER BY is_admin DESC, created_at ASC", [$q['id']]
        );
    }
    
    echo json_encode(['success'=>true,'questions'=>$questions,'total'=>count($questions)]);
    break;

// ════════════════════════════════════
// ASK A QUESTION
// ════════════════════════════════════
case 'ask_question':
    if (getSetting('qna_enabled', '1') !== '1') { echo json_encode(['success'=>false,'message'=>'প্রশ্ন-উত্তর বন্ধ আছে']); exit; }
    
    $productId = intval($_POST['product_id'] ?? 0);
    $name = trim($_POST['asker_name'] ?? '');
    $text = trim($_POST['question_text'] ?? '');
    $custId = isCustomerLoggedIn() ? getCustomerId() : null;
    
    if ($custId && !$name) {
        $cust = getCustomer();
        $name = $cust['name'];
    }
    
    if (!$productId || !$name || strlen($text) < 5) {
        echo json_encode(['success'=>false,'message'=>'নাম এবং প্রশ্ন লিখুন (কমপক্ষে ৫ অক্ষর)']);
        exit;
    }
    
    $autoApprove = getSetting('qna_auto_approve', '1') === '1';
    
    $db->insert('product_questions', [
        'product_id' => $productId,
        'customer_id' => $custId,
        'asker_name' => $name,
        'question_text' => $text,
        'is_approved' => $autoApprove ? 1 : 0,
        'ip_address' => $ip,
    ]);
    
    $msg = $autoApprove ? 'প্রশ্ন জমা হয়েছে!' : 'প্রশ্ন জমা হয়েছে, অনুমোদনের পর প্রকাশিত হবে।';
    echo json_encode(['success'=>true,'message'=>$msg]);
    break;

// ════════════════════════════════════
// ANSWER A QUESTION
// ════════════════════════════════════
case 'answer_question':
    $questionId = intval($_POST['question_id'] ?? 0);
    $text = trim($_POST['answer_text'] ?? '');
    $custId = isCustomerLoggedIn() ? getCustomerId() : null;
    $isAdmin = !empty($_SESSION['admin_id']);
    
    if (!$questionId || strlen($text) < 2) {
        echo json_encode(['success'=>false,'message'=>'উত্তর লিখুন']);
        exit;
    }
    
    $name = '';
    if ($isAdmin) {
        $name = getAdminName() . ' (Admin)';
    } elseif ($custId) {
        $cust = getCustomer();
        $name = $cust['name'];
    } else {
        $name = trim($_POST['answerer_name'] ?? 'Anonymous');
    }
    
    $autoApprove = $isAdmin ? 1 : (getSetting('qna_auto_approve', '1') === '1' ? 1 : 0);
    
    $db->insert('product_answers', [
        'question_id' => $questionId,
        'answerer_name' => $name,
        'answer_text' => $text,
        'is_admin' => $isAdmin ? 1 : 0,
        'admin_id' => $isAdmin ? $_SESSION['admin_id'] : null,
        'customer_id' => $custId,
        'is_approved' => $autoApprove,
    ]);
    
    echo json_encode(['success'=>true,'message'=>'উত্তর জমা হয়েছে!']);
    break;

// ════════════════════════════════════
// GET REVIEWABLE PRODUCTS FOR CUSTOMER
// ════════════════════════════════════
case 'reviewable_products':
    if (!isCustomerLoggedIn()) { echo json_encode(['success'=>false,'products'=>[]]); exit; }
    $products = getReviewableProducts(getCustomerId());
    $reward = intval(getSetting('review_credit_reward', '50'));
    echo json_encode(['success'=>true,'products'=>$products,'credit_reward'=>$reward,'count'=>count($products)]);
    break;

// ════════════════════════════════════
// ADMIN: APPROVE / REJECT REVIEW
// ════════════════════════════════════
case 'admin_review_action':
    if (empty($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
    $reviewId = intval($_POST['review_id'] ?? 0);
    $act = $_POST['admin_action'] ?? '';
    
    if ($act === 'approve') {
        $db->query("UPDATE product_reviews SET is_approved = 1 WHERE id = ?", [$reviewId]);
        // Award credit if not yet awarded
        $rev = $db->fetch("SELECT * FROM product_reviews WHERE id = ?", [$reviewId]);
        if ($rev && !$rev['credit_awarded'] && $rev['customer_id'] && !$rev['is_dummy']) {
            $reward = intval(getSetting('review_credit_reward', '50'));
            if ($reward > 0) {
                addStoreCredit($rev['customer_id'], $reward, 'earn', 'review', $reviewId, "রিভিউ বোনাস (পণ্য #{$rev['product_id']})");
                $db->query("UPDATE product_reviews SET credit_awarded = 1 WHERE id = ?", [$reviewId]);
            }
        }
        echo json_encode(['success'=>true,'message'=>'Approved']);
    } elseif ($act === 'reject') {
        $db->query("UPDATE product_reviews SET is_approved = 0 WHERE id = ?", [$reviewId]);
        echo json_encode(['success'=>true,'message'=>'Rejected']);
    } elseif ($act === 'delete') {
        $db->delete('review_likes', 'review_id = ?', [$reviewId]);
        $db->delete('product_reviews', 'id = ?', [$reviewId]);
        echo json_encode(['success'=>true,'message'=>'Deleted']);
    }
    break;

// ════════════════════════════════════
// ADMIN: ADD DUMMY REVIEW
// ════════════════════════════════════
case 'admin_add_dummy':
    if (empty($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
    
    $productId = intval($_POST['product_id'] ?? 0);
    $rating = intval($_POST['rating'] ?? 5);
    $text = trim($_POST['review_text'] ?? '');
    $name = trim($_POST['reviewer_name'] ?? 'Customer');
    
    if (!$productId || !$text) { echo json_encode(['success'=>false,'message'=>'Product and review text required']); exit; }
    
    // Handle image uploads for dummy reviews
    $images = [];
    $uploadsDir = rtrim(UPLOAD_PATH, '/') . '/review-images/';
    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0755, true); }
    
    if (!empty($_FILES['images'])) {
        $files = $_FILES['images'];
        $count = min(count($files['name']), 5);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) continue;
            $fname = 'dummy_' . $productId . '_' . time() . '_' . $i . '.' . $ext;
            if (move_uploaded_file($files['tmp_name'][$i], $uploadsDir . $fname)) {
                $images[] = '/uploads/review-images/' . $fname;
            }
        }
    }
    
    $db->insert('product_reviews', [
        'product_id' => $productId,
        'rating' => max(1, min(5, $rating)),
        'review_text' => $text,
        'images' => json_encode($images),
        'reviewer_name' => $name,
        'is_approved' => 1,
        'is_dummy' => 1,
        'ip_address' => $ip,
    ]);
    
    echo json_encode(['success'=>true,'message'=>'Dummy review added']);
    break;

// ════════════════════════════════════
// ADMIN: Q&A ACTIONS
// ════════════════════════════════════
case 'admin_qna_action':
    if (empty($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
    $type = $_POST['type'] ?? ''; // question or answer
    $id = intval($_POST['id'] ?? 0);
    $act = $_POST['admin_action'] ?? '';
    
    $table = $type === 'question' ? 'product_questions' : 'product_answers';
    
    if ($act === 'approve') {
        $db->query("UPDATE {$table} SET is_approved = 1 WHERE id = ?", [$id]);
        // Award credit for answers
        if ($type === 'answer') {
            $ans = $db->fetch("SELECT * FROM product_answers WHERE id = ?", [$id]);
            if ($ans && $ans['customer_id'] && !$ans['credit_awarded']) {
                $reward = intval(getSetting('qna_credit_reward', '20'));
                if ($reward > 0) {
                    addStoreCredit($ans['customer_id'], $reward, 'earn', 'qna', $id, "প্রশ্ন-উত্তর বোনাস");
                    $db->query("UPDATE product_answers SET credit_awarded = 1 WHERE id = ?", [$id]);
                }
            }
        }
    } elseif ($act === 'delete') {
        if ($type === 'question') {
            $db->delete('product_answers', 'question_id = ?', [$id]);
        }
        $db->delete($table, 'id = ?', [$id]);
    }
    echo json_encode(['success'=>true]);
    break;

default:
    // Admin product search for dummy reviews
    if ($action === 'search_products' && !empty($_SESSION['admin_id'])) {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode(['products'=>[]]); exit; }
        $products = $db->fetchAll(
            "SELECT id, name, name_bn, slug, featured_image FROM products WHERE is_active = 1 AND (name LIKE ? OR name_bn LIKE ? OR id = ?) ORDER BY id DESC LIMIT 15",
            ["%{$q}%", "%{$q}%", intval($q)]
        );
        echo json_encode(['products'=>$products]);
        exit;
    }
    echo json_encode(['success'=>false,'message'=>'Unknown action']);
}
