<?php
// user/product-details.php — Product page with WORKING reviews + ratings + comments
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/auth.php';

/* ---------------------------------------------------------------
   Theme from cookie
   --------------------------------------------------------------- */
$theme = 'cream'; $accent = '#5b5fd4';
if (!empty($_COOKIE['rjewel_theme'])) {
    $p = json_decode(urldecode($_COOKIE['rjewel_theme']), true);
    if (is_array($p)) {
        $theme  = $p['theme']  ?? 'cream';
        $accent = $p['accent'] ?? '#5b5fd4';
    }
}
$resolved_theme = in_array($theme, ['cream','light','dark'], true) ? $theme : 'cream';

/* ---------------------------------------------------------------
   DB
   --------------------------------------------------------------- */
$db = null;
try { $database = new Database(); $db = $database->getConnection(); } catch (Throwable $e) { $db = null; }

$auth = $db ? new Auth($db) : null;
$is_logged_in = $auth && $auth->isLoggedIn();
$user_id = $is_logged_in ? (int)($_SESSION['user_id'] ?? 0) : 0;

/* ---------------------------------------------------------------
   Helpers
   --------------------------------------------------------------- */
function safe_query($db, $sql, $params = []) {
    if (!$db) return [];
    try { $s = $db->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { error_log("safe_query: " . $e->getMessage()); return []; }
}
function safe_exec($db, $sql, $params = []) {
    if (!$db) return false;
    try { $s = $db->prepare($sql); return $s->execute($params); }
    catch (Throwable $e) { error_log("safe_exec: " . $e->getMessage()); return false; }
}

/* ---------------------------------------------------------------
   Product
   --------------------------------------------------------------- */
$product_id = (int)($_GET['id'] ?? 0);

$rows = safe_query($db, "
    SELECT p.*, c.name AS category_name,
           COALESCE(p.released_stock, 0) AS released_stock
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id = :id
", [':id' => $product_id]);

$product = $rows[0] ?? null;
if (!$product) { header("Location: shop.php"); exit(); }

/* ---------------------------------------------------------------
   POST: submit review
   --------------------------------------------------------------- */
$form_message = '';
$form_type    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {

    if (!$is_logged_in) {
        $form_message = 'Please log in to submit a review.';
        $form_type    = 'error';
    } else {
        $rating  = (int)($_POST['rating'] ?? 0);
        $title   = trim((string)($_POST['title'] ?? ''));
        $comment = trim((string)($_POST['comment'] ?? ''));

        if ($rating < 1 || $rating > 5) {
            $form_message = 'Please select a star rating (1 to 5).';
            $form_type    = 'error';
        } elseif (strlen($comment) < 5) {
            $form_message = 'Please write at least 5 characters in your review.';
            $form_type    = 'error';
        } elseif (strlen($comment) > 2000) {
            $form_message = 'Your review is too long (max 2000 characters).';
            $form_type    = 'error';
        } else {
            $purchase = safe_query($db, "
                SELECT o.id FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE o.user_id = :uid AND oi.product_id = :pid AND o.status = 'delivered'
                LIMIT 1
            ", [':uid' => $user_id, ':pid' => $product_id]);

            $verified = !empty($purchase);
            $order_id = $verified ? (int)$purchase[0]['id'] : null;

            $existing = safe_query($db,
                "SELECT id FROM reviews WHERE user_id = :uid AND product_id = :pid LIMIT 1",
                [':uid' => $user_id, ':pid' => $product_id]);

            if (!empty($existing)) {
                $ok = safe_exec($db, "
                    UPDATE reviews
                    SET rating = :r, title = :t, comment = :c,
                        is_verified = :v, order_id = :o, status = 'approved'
                    WHERE user_id = :uid AND product_id = :pid
                ", [
                    ':r' => $rating,
                    ':t' => $title !== '' ? $title : null,
                    ':c' => $comment,
                    ':v' => $verified ? 1 : 0,
                    ':o' => $order_id,
                    ':uid' => $user_id,
                    ':pid' => $product_id
                ]);
                $form_message = $ok ? 'Your review has been updated. Thank you!' : 'Could not update your review.';
                $form_type    = $ok ? 'success' : 'error';
            } else {
                $ok = safe_exec($db, "
                    INSERT INTO reviews
                        (product_id, user_id, order_id, rating, title, comment, is_verified, status)
                    VALUES
                        (:pid, :uid, :o, :r, :t, :c, :v, 'approved')
                ", [
                    ':pid' => $product_id,
                    ':uid' => $user_id,
                    ':o'   => $order_id,
                    ':r'   => $rating,
                    ':t'   => $title !== '' ? $title : null,
                    ':c'   => $comment,
                    ':v'   => $verified ? 1 : 0
                ]);
                $form_message = $ok ? 'Thank you! Your review has been posted.' : 'Could not save your review.';
                $form_type    = $ok ? 'success' : 'error';
            }
        }
    }
}

/* ---------------------------------------------------------------
   Review stats
   --------------------------------------------------------------- */
$stats_rows = safe_query($db, "
    SELECT COUNT(*) AS total, AVG(rating) AS avg,
           SUM(rating=5) AS r5, SUM(rating=4) AS r4,
           SUM(rating=3) AS r3, SUM(rating=2) AS r2, SUM(rating=1) AS r1
    FROM reviews WHERE product_id = :pid AND status = 'approved'
", [':pid' => $product_id]);

$stats = array_merge(
    ['total' => 0, 'avg' => 0, 'r5' => 0, 'r4' => 0, 'r3' => 0, 'r2' => 0, 'r1' => 0],
    $stats_rows[0] ?? []
);
$avg_rating    = $stats['avg'] ? round((float)$stats['avg'], 1) : 0;
$total_reviews = (int)$stats['total'];

/* ---------------------------------------------------------------
   Reviews list
   --------------------------------------------------------------- */
$per_page    = 5;
$review_page = max(1, (int)($_GET['review_page'] ?? 1));
$offset      = ($review_page - 1) * $per_page;

$reviews = safe_query($db, "
    SELECT r.*, u.full_name
    FROM reviews r
    JOIN users u ON u.id = r.user_id
    WHERE r.product_id = :pid AND r.status = 'approved'
    ORDER BY r.updated_at DESC
    LIMIT $per_page OFFSET $offset
", [':pid' => $product_id]);

/* ---------------------------------------------------------------
   User's existing review + verified
   --------------------------------------------------------------- */
$user_review   = null;
$user_verified = false;

if ($is_logged_in) {
    $rows = safe_query($db,
        "SELECT * FROM reviews WHERE user_id = :uid AND product_id = :pid LIMIT 1",
        [':uid' => $user_id, ':pid' => $product_id]);
    $user_review = $rows[0] ?? null;

    $rows = safe_query($db, "
        SELECT o.id FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.user_id = :uid AND oi.product_id = :pid AND o.status = 'delivered'
        LIMIT 1
    ", [':uid' => $user_id, ':pid' => $product_id]);
    $user_verified = !empty($rows);
}

/* ---------------------------------------------------------------
   Related
   --------------------------------------------------------------- */
$related_products = safe_query($db, "
    SELECT * FROM products
    WHERE category_id = :cid AND id != :pid AND stock > 0
    ORDER BY RAND() LIMIT 4
", [':cid' => (int)$product['category_id'], ':pid' => $product_id]);

/* ---------------------------------------------------------------
   Display values
   --------------------------------------------------------------- */
$stock_total    = (int)($product['stock'] ?? 0);
$released_stock = (int)($product['released_stock'] ?? 0);
if ($released_stock > $stock_total) $released_stock = $stock_total;

$karat          = trim((string)($product['karat'] ?? ''));
$weight_raw     = $product['weight'];
$has_weight     = ($weight_raw !== null && $weight_raw !== '' && (float)$weight_raw > 0);
$weight_display = $has_weight ? rtrim(rtrim(number_format((float)$weight_raw, 2, '.', ''), '0'), '.') : '';

$page_title = htmlspecialchars($product['name']) . ' | R-Jewel&Gold';
?>
<?php include '../includes/header.php'; ?>

<style>
    :root {
        --bg: #f2ede4; --bg-soft: #faf7f2; --card: #ffffff;
        --border: #e5ddd0; --border-light: #ede7db;
        --text: #5a5245; --text-bright: #2b2620; --text-muted: #8a8172; --text-dim: #b3a998;
        --primary: <?php echo htmlspecialchars($accent); ?>;
        --gold: #b8941f; --gold-soft: rgba(184,148,31,0.1); --gold-strong: #8a6e14;
        --success: #0d9668; --danger: #dc2626;
    }
    <?php if ($resolved_theme === 'dark'): ?>
    :root {
        --bg: #151821; --bg-soft: #1c202b; --card: #222735;
        --border: #343b4d; --border-light: #2d3444;
        --text: #d5d9e2; --text-bright: #ffffff; --text-muted: #a0a8ba; --text-dim: #777f91;
    }
    <?php elseif ($resolved_theme === 'light'): ?>
    :root {
        --bg: #f5f7fb; --bg-soft: #ffffff; --card: #ffffff;
        --border: #e2e8f0; --border-light: #edf1f6;
        --text: #475569; --text-bright: #172033; --text-muted: #64748b; --text-dim: #94a3b8;
    }
    <?php endif; ?>

    /* ---------- SIMPLE FONTS: everything Inter ---------- */
    body, h1, h2, h3, h4, h5, h6,
    .product-title-lg, .product-price-lg,
    .section-head h2, .big-rating .score,
    .review-cta .text strong, .review-form-box h4,
    .review-author, .review-title,
    .rec-product-name, .rec-product-price {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    body { background: var(--bg); color: var(--text); }
    h1,h2,h3,h4,h5,h6 { color: var(--text-bright); font-weight: 600; }

    .lux-breadcrumb {
        display: flex; flex-wrap: wrap; gap: 8px;
        font-size: 0.68rem; letter-spacing: 0.18em; text-transform: uppercase;
        color: var(--text-dim); margin-bottom: 2rem; font-weight: 600;
    }
    .lux-breadcrumb a { color: var(--text-muted); text-decoration: none; transition: color .25s; }
    .lux-breadcrumb a:hover { color: var(--gold); }
    .lux-breadcrumb .sep { color: var(--text-dim); }
    .lux-breadcrumb .current { color: var(--gold); }

    .product-container { padding: 2.5rem 0 5rem; }

    /* Gallery */
    .product-gallery {
        background: var(--card); border: 1px solid var(--border);
        border-radius: 14px; padding: 1rem;
        position: sticky; top: 20px; transition: border-color .3s ease;
    }
    .product-gallery:hover { border-color: rgba(184,148,31,0.35); }
    .main-image-wrap {
        border-radius: 10px; overflow: hidden; background: var(--bg-soft); aspect-ratio: 4/3;
    }
    .main-image { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .6s ease; }
    .main-image-wrap:hover .main-image { transform: scale(1.04); }

    /* Info */
    .product-info { padding-left: 1rem; }
    @media (max-width: 991px) { .product-info { padding-left: 0; margin-top: 2rem; } }

    .product-eyebrow {
        font-size: 0.78rem; font-weight: 700; letter-spacing: 0.3em;
        text-transform: uppercase; color: var(--gold); margin-bottom: 0.6rem;
    }

    /* Product title — BIG, simple Inter */
    .product-title-lg {
        font-size: clamp(2rem, 4.5vw, 2.75rem);
        line-height: 1.2;
        margin-bottom: 1rem;
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    /* Karat + Weight chips */
    .spec-chips {
        display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 1.25rem;
    }
    .spec-chip {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 18px; border-radius: 24px;
        font-size: 0.95rem; font-weight: 700; letter-spacing: 0.01em;
        border: 1.5px solid transparent;
    }
    .spec-chip.karat {
        background: rgba(184,148,31,.12); color: var(--gold-strong);
        border-color: rgba(184,148,31,.35);
    }
    .spec-chip.weight {
        background: <?php echo htmlspecialchars($accent); ?>14;
        color: <?php echo htmlspecialchars($accent); ?>;
        border-color: <?php echo htmlspecialchars($accent); ?>44;
    }
    .spec-chip i { font-size: 0.95rem; opacity: 0.9; }

    /* Description */
    .product-description {
        color: var(--text);
        line-height: 1.75;
        font-size: 1.05rem;
        margin-bottom: 1.5rem;
        font-weight: 400;
    }

    /* Price */
    .product-price-lg {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--gold);
        line-height: 1.2;
        margin-bottom: 1rem;
        letter-spacing: 0;
    }

    /* Stock pill (green, big) */
    .stock-chip-row {
        display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 1.5rem;
    }
    .stock-pill {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px; border-radius: 24px;
        font-size: 0.95rem; font-weight: 700; letter-spacing: 0.01em;
    }
    .stock-pill.stock {
        background: rgba(13,150,104,.12);
        color: #0a7a54;
        border: 1.5px solid rgba(13,150,104,.35);
    }
    .stock-pill.stock i { font-size: 0.9rem; }

    /* Rating line */
    .rating-mini {
        display: flex; align-items: center; gap: 10px;
        margin-bottom: 1.25rem; font-size: 0.9rem; color: var(--text-muted);
    }
    .rating-mini .stars { color: var(--gold); letter-spacing: 1px; font-size: 0.95rem; }
    .rating-mini a { color: var(--text-muted); text-decoration: none; border-bottom: 1px dashed var(--border); }
    .rating-mini a:hover { color: var(--gold); border-color: var(--gold); }

    /* Qty + Add to cart */
    .add-to-cart-row { display: grid; grid-template-columns: 130px 1fr; gap: 12px; margin-bottom: 1.25rem; }
    @media (max-width: 575px) { .add-to-cart-row { grid-template-columns: 1fr; } }

    .quantity-selector {
        display: flex; align-items: center;
        border: 1px solid var(--border); border-radius: 10px;
        background: var(--bg-soft); overflow: hidden;
    }
    .quantity-btn {
        width: 42px; height: 46px; border: none; background: transparent;
        color: var(--text-muted); cursor: pointer; transition: all .25s ease; font-size: 0.9rem;
    }
    .quantity-btn:hover { background: var(--gold-soft); color: var(--gold); }
    .quantity-input {
        flex: 1; width: 46px; border: none; text-align: center;
        background: transparent; color: var(--text-bright);
        font-family: 'Inter', sans-serif; font-size: 0.95rem; font-weight: 600;
        -moz-appearance: textfield;
    }
    .quantity-input::-webkit-outer-spin-button,
    .quantity-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .quantity-input:focus { outline: none; }

    .btn-lux {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        padding: 0.85rem 1.6rem; border-radius: 10px; border: none;
        font-family: 'Inter', sans-serif; font-size: 0.78rem; font-weight: 700;
        letter-spacing: 0.18em; text-transform: uppercase;
        text-decoration: none; cursor: pointer; transition: all .3s ease; white-space: nowrap;
    }
    .btn-lux-gold {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff; box-shadow: 0 4px 14px -3px rgba(184,148,31,.45);
    }
    .btn-lux-gold:hover { color:#fff; transform: translateY(-2px); box-shadow: 0 8px 22px -5px rgba(184,148,31,.6); }
    .btn-lux-outline { background: transparent; color: var(--text-bright); border: 1px solid var(--border); }
    .btn-lux-outline:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-2px); }

    /* REVIEWS */
    .reviews-section {
        margin-top: 4rem; background: var(--card);
        border: 1px solid var(--border); border-radius: 14px; padding: 2rem;
    }
    @media (max-width: 768px) { .reviews-section { padding: 1.5rem; } }

    .section-head { text-align: center; margin-bottom: 2.5rem; }
    .section-head .eyebrow {
        font-size: 0.7rem; font-weight: 700; letter-spacing: 0.3em;
        text-transform: uppercase; color: var(--gold); margin-bottom: 0.5rem;
    }
    .section-head h2 {
        font-size: clamp(1.5rem, 3vw, 2rem);
        margin: 0;
        font-weight: 700;
        letter-spacing: -0.01em;
    }
    .section-head h2 em { color: var(--gold); font-style: normal; font-weight: 700; }
    .small-divider { display: flex; align-items: center; justify-content: center; gap: 10px; max-width: 140px; margin: 0.75rem auto 0; }
    .small-divider .line { flex: 1; height: 1px; background: linear-gradient(90deg, transparent, rgba(184,148,31,.5), transparent); }
    .small-divider .diamond { width: 5px; height: 5px; background: var(--gold); transform: rotate(45deg); }

    .ratings-summary {
        display: grid; grid-template-columns: 260px 1fr; gap: 2rem;
        align-items: center; margin-bottom: 2rem;
        padding-bottom: 2rem; border-bottom: 1px solid var(--border-light);
    }
    @media (max-width: 767px) { .ratings-summary { grid-template-columns: 1fr; gap: 1.5rem; } }

    .big-rating { text-align: center; padding: 1rem; }
    .big-rating .score {
        font-size: 3.5rem;
        font-weight: 700;
        color: var(--gold);
        line-height: 1; display: block;
    }
    .big-rating .stars-lg { color: var(--gold); font-size: 1.15rem; letter-spacing: 3px; margin: .5rem 0; }
    .big-rating .count { font-size: .78rem; color: var(--text-muted); letter-spacing: .08em; text-transform: uppercase; font-weight: 600; }
    .big-rating .no-reviews { color: var(--text-muted); font-size: .85rem; margin-top: .5rem; }

    .rating-bars { display: flex; flex-direction: column; gap: 8px; }
    .bar-row {
        display: grid; grid-template-columns: 52px 1fr 48px;
        align-items: center; gap: 12px; font-size: .82rem; color: var(--text-muted);
    }
    .bar-row .label { font-weight: 600; color: var(--text-bright); display: flex; align-items: center; gap: 4px; }
    .bar-row .label i { color: var(--gold); font-size: .72rem; }
    .bar-track { height: 8px; background: var(--border); border-radius: 4px; overflow: hidden; }
    .bar-fill {
        height: 100%; width: 0;
        background: linear-gradient(90deg, var(--gold), var(--gold-strong));
        border-radius: 4px; transition: width .8s ease;
        box-shadow: 0 0 8px rgba(184,148,31,.5);
    }
    .bar-row .pct { font-size: .75rem; color: var(--text-dim); text-align: right; }

    .review-cta {
        background: var(--gold-soft); border: 1px solid rgba(184,148,31,.3);
        border-radius: 14px; padding: 1.5rem;
        display: flex; align-items: center; gap: 1rem;
        flex-wrap: wrap; margin-bottom: 2rem;
    }
    @media (max-width: 575px) { .review-cta { flex-direction: column; text-align: center; } }
    .review-cta .icon {
        width: 48px; height: 48px; border-radius: 50%;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem; flex-shrink: 0;
        box-shadow: 0 6px 16px -6px rgba(184,148,31,.7);
    }
    .review-cta .text { flex: 1; min-width: 200px; }
    .review-cta .text strong {
        display: block;
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--text-bright);
        margin-bottom: 2px;
    }
    .review-cta .text span { font-size: .85rem; color: var(--text-muted); }

    .review-form-box {
        background: var(--bg-soft); border: 1px solid var(--border);
        border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem; display: none;
    }
    .review-form-box.open { display: block; animation: fadeIn .3s ease-out; }
    @keyframes fadeIn { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
    .review-form-box h4 { font-size: 1.15rem; font-weight: 700; margin-bottom: 1rem; }

    .form-row { margin-bottom: 1.1rem; }
    .form-row label {
        display: block; margin-bottom: .5rem; font-size: .72rem;
        font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
        color: var(--text-bright);
    }
    .form-input, .form-textarea {
        width: 100%; padding: .75rem 1rem;
        border: 1px solid var(--border); border-radius: 10px;
        background: var(--card); color: var(--text-bright);
        font-family: 'Inter', sans-serif; font-size: .9rem;
        transition: all .25s ease;
    }
    .form-input:focus, .form-textarea:focus {
        outline: none; border-color: var(--gold);
        box-shadow: 0 0 0 3px rgba(184,148,31,.12);
    }
    .form-textarea { resize: vertical; min-height: 110px; line-height: 1.6; }

    .star-picker { display: flex; gap: 6px; font-size: 1.75rem; color: var(--border); cursor: pointer; user-select: none; }
    .star-picker i { transition: transform .15s ease, color .15s ease; }
    .star-picker i:hover, .star-picker i.hovered, .star-picker i.selected { color: var(--gold); text-shadow: 0 0 8px rgba(184,148,31,.5); }
    .star-picker i:hover { transform: scale(1.15); }
    .rating-hint { font-size: .78rem; color: var(--text-muted); margin-top: 6px; font-weight: 600; letter-spacing: .03em; }

    .form-alert {
        padding: .75rem 1rem; border-radius: 10px; font-size: .85rem;
        margin-bottom: 1rem; display: flex; align-items: center; gap: 10px;
    }
    .form-alert.success { background: rgba(13,150,104,.08); color: var(--success); border: 1px solid rgba(13,150,104,.25); }
    .form-alert.error   { background: rgba(220,38,38,.06);  color: var(--danger);  border: 1px solid rgba(220,38,38,.25); }

    .review-list { display: flex; flex-direction: column; gap: 1.25rem; }
    .review-card {
        background: var(--bg-soft); border: 1px solid var(--border);
        border-radius: 12px; padding: 1.25rem; transition: border-color .25s ease;
    }
    .review-card:hover { border-color: rgba(184,148,31,.35); }
    .review-head { display: flex; align-items: center; gap: 12px; margin-bottom: .75rem; }
    .review-avatar {
        width: 44px; height: 44px; border-radius: 50%;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem; font-weight: 700;
        flex-shrink: 0; box-shadow: 0 4px 12px -4px rgba(184,148,31,.6);
    }
    @media (max-width: 575px) { .review-avatar { width: 38px; height: 38px; font-size: .95rem; } }
    .review-meta { flex: 1; min-width: 0; }
    .review-author {
        font-size: 1rem;
        font-weight: 600; color: var(--text-bright);
        margin-bottom: .15rem; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    }
    .verified-pill {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 2px 8px; border-radius: 20px;
        background: rgba(13,150,104,.12); color: var(--success);
        font-size: .6rem; font-weight: 700;
        letter-spacing: .08em; text-transform: uppercase;
    }
    .your-review-badge {
        display: inline-block; padding: 2px 8px; border-radius: 20px;
        background: var(--gold-soft); color: var(--gold-strong);
        border: 1px solid rgba(184,148,31,.35);
        font-size: .6rem; font-weight: 700;
        letter-spacing: .08em; text-transform: uppercase;
    }
    .review-stars { color: var(--gold); font-size: .82rem; letter-spacing: 2px; }
    .review-date { font-size: .72rem; color: var(--text-dim); margin-top: .15rem; }
    .review-title { font-size: 1rem; font-weight: 600; color: var(--text-bright); margin-bottom: .4rem; }
    .review-body { color: var(--text); line-height: 1.7; font-size: .9rem; margin: 0; }

    .reviews-empty {
        text-align: center; padding: 3rem 1rem;
        background: var(--bg-soft); border: 1px dashed var(--border); border-radius: 12px;
    }
    .reviews-empty i { font-size: 2rem; color: var(--gold); margin-bottom: .75rem; display: block; }
    .reviews-empty h4 { font-size: 1.15rem; font-weight: 600; margin-bottom: .35rem; }
    .reviews-empty p { color: var(--text-muted); font-size: .9rem; margin: 0; }

    .load-more-wrap { text-align: center; margin-top: 1.75rem; }

    /* Related */
    .related-section { margin-top: 5rem; }
    .rec-product-card {
        background: var(--card); border: 1px solid var(--border); border-radius: 14px;
        overflow: hidden; transition: all .35s ease; text-decoration: none;
        color: inherit; display: block; height: 100%;
    }
    .rec-product-card:hover {
        transform: translateY(-8px); border-color: var(--gold);
        box-shadow: 0 20px 40px -18px rgba(90,82,69,.25); color: inherit;
    }
    .rec-product-card img { width: 100%; height: 200px; object-fit: cover; display: block; transition: transform .6s ease; }
    .rec-product-card:hover img { transform: scale(1.06); }
    .rec-product-info { padding: 1.1rem; text-align: center; }
    .rec-product-name { font-size: 1rem; font-weight: 600; color: var(--text-bright); margin-bottom: .25rem; line-height: 1.3; }
    .rec-product-price { font-size: 1rem; font-weight: 700; color: var(--gold); }

    /* Toast */
    .toast-notification {
        position: fixed; bottom: 24px; right: 24px; z-index: 9999;
        animation: slideInRight .35s ease-out; border-radius: 10px; overflow: hidden;
        box-shadow: 0 12px 30px rgba(0,0,0,.15);
        background: var(--card); border: 1px solid var(--border); min-width: 260px;
    }
    .toast-notification .toast-body {
        padding: .9rem 1.1rem; font-size: .85rem; font-weight: 500;
        display: flex; align-items: center; gap: 10px;
    }
    .toast-notification.success .toast-body { color: var(--success); }
    .toast-notification.error   .toast-body { color: var(--danger); }
    @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
</style>

<div class="container product-container">

    <!-- Breadcrumb -->
    <nav class="lux-breadcrumb">
        <a href="index.php">Home</a><span class="sep">·</span>
        <a href="shop.php">Shop</a><span class="sep">·</span>
        <?php if (!empty($product['category_name'])): ?>
            <a href="shop.php?category=<?php echo (int)$product['category_id']; ?>"><?php echo htmlspecialchars($product['category_name']); ?></a>
            <span class="sep">·</span>
        <?php endif; ?>
        <span class="current"><?php echo htmlspecialchars($product['name']); ?></span>
    </nav>

    <div class="row g-4">
        <!-- Gallery -->
        <div class="col-lg-6">
            <div class="product-gallery">
                <div class="main-image-wrap">
                    <img src="../uploads/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                         onerror="this.onerror=null;this.src='https://via.placeholder.com/800x600/ede7db/8a8172?text=Jewelry'"
                         class="main-image" id="mainImage"
                         alt="<?php echo htmlspecialchars($product['name']); ?>">
                </div>
            </div>
        </div>

        <!-- Info -->
        <div class="col-lg-6">
            <div class="product-info">

                <!-- Category -->
                <p class="product-eyebrow"><?php echo htmlspecialchars($product['category_name'] ?? 'Jewelry'); ?></p>

                <!-- Product name -->
                <h1 class="product-title-lg"><?php echo htmlspecialchars($product['name']); ?></h1>

                <!-- Karat + Weight chips -->
                <?php if ($karat !== '' || $has_weight): ?>
                <div class="spec-chips">
                    <?php if ($karat !== ''): ?>
                        <span class="spec-chip karat">
                            <i class="fas fa-certificate"></i> <?php echo htmlspecialchars($karat); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($has_weight): ?>
                        <span class="spec-chip weight">
                            <i class="fas fa-weight-hanging"></i> <?php echo htmlspecialchars($weight_display); ?> grams
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Description -->
                <p class="product-description">
                    <?php echo nl2br(htmlspecialchars($product['description'] ?? 'Exquisitely crafted piece from our signature collection.')); ?>
                </p>

                <!-- Price -->
                <div class="product-price-lg">₱<?php echo number_format((float)$product['price'], 2); ?></div>

                <!-- Stock pill -->
                <div class="stock-chip-row">
                    <span class="stock-pill stock">
                        <i class="fas fa-check-circle"></i> Stock <?php echo $released_stock; ?>
                    </span>
                </div>

                <!-- Rating line -->
                <div class="rating-mini">
                    <span class="stars">
                        <?php
                        for ($i = 1; $i <= 5; $i++) {
                            if ($i <= floor($avg_rating)) echo '<i class="fas fa-star"></i>';
                            elseif ($i - 0.5 <= $avg_rating) echo '<i class="fas fa-star-half-alt"></i>';
                            else echo '<i class="far fa-star"></i>';
                        }
                        ?>
                    </span>
                    <?php if ($total_reviews > 0): ?>
                        <span><?php echo number_format($avg_rating, 1); ?> · <a href="#reviews"><?php echo $total_reviews; ?> review<?php echo $total_reviews == 1 ? '' : 's'; ?></a></span>
                    <?php else: ?>
                        <span><a href="#reviews">Be the first to review</a></span>
                    <?php endif; ?>
                </div>

                <!-- Add to cart -->
                <?php if ($stock_total > 0): ?>
                <div class="add-to-cart-row">
                    <div class="quantity-selector">
                        <button type="button" class="quantity-btn" id="qtyMinus"><i class="fas fa-minus"></i></button>
                        <input type="number" id="quantity" class="quantity-input" value="1" min="1" max="<?php echo $stock_total; ?>">
                        <button type="button" class="quantity-btn" id="qtyPlus"><i class="fas fa-plus"></i></button>
                    </div>
                    <button type="button" class="btn-lux btn-lux-gold"
                            id="addToCartBtn"
                            data-product-id="<?php echo (int)$product['id']; ?>">
                        <i class="fas fa-shopping-bag"></i> Add to Cart
                    </button>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- ============================ REVIEWS ============================ -->
    <section class="reviews-section" id="reviews">
        <div class="section-head">
            <p class="eyebrow">Customer Feedback</p>
            <h2>Ratings & <em>Reviews</em></h2>
            <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
        </div>

        <!-- Summary -->
        <div class="ratings-summary">
            <div class="big-rating">
                <?php if ($total_reviews > 0): ?>
                    <span class="score"><?php echo number_format($avg_rating, 1); ?></span>
                    <div class="stars-lg">
                        <?php
                        for ($i = 1; $i <= 5; $i++) {
                            if ($i <= floor($avg_rating)) echo '<i class="fas fa-star"></i>';
                            elseif ($i - 0.5 <= $avg_rating) echo '<i class="fas fa-star-half-alt"></i>';
                            else echo '<i class="far fa-star"></i>';
                        }
                        ?>
                    </div>
                    <div class="count"><?php echo $total_reviews; ?> review<?php echo $total_reviews == 1 ? '' : 's'; ?></div>
                <?php else: ?>
                    <span class="score">—</span>
                    <div class="stars-lg"><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i><i class="far fa-star"></i></div>
                    <div class="no-reviews">No reviews yet</div>
                <?php endif; ?>
            </div>

            <div class="rating-bars">
                <?php for ($star = 5; $star >= 1; $star--):
                    $count = (int)($stats['r' . $star] ?? 0);
                    $pct   = $total_reviews > 0 ? round(($count / $total_reviews) * 100) : 0;
                ?>
                    <div class="bar-row">
                        <span class="label"><?php echo $star; ?> <i class="fas fa-star"></i></span>
                        <div class="bar-track"><div class="bar-fill" data-pct="<?php echo $pct; ?>"></div></div>
                        <span class="pct"><?php echo $pct; ?>%</span>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- CTA / Form -->
        <?php if (!$is_logged_in): ?>
            <div class="review-cta">
                <div class="icon"><i class="fas fa-sign-in-alt"></i></div>
                <div class="text">
                    <strong>Want to rate this piece?</strong>
                    <span>Log in to give a star rating and write your review.</span>
                </div>
                <a href="login.php?redirect=<?php echo urlencode('product-details.php?id=' . $product_id . '#reviews'); ?>"
                   class="btn-lux btn-lux-gold">
                    <i class="fas fa-sign-in-alt"></i> Log In to Review
                </a>
            </div>

        <?php else: ?>
            <?php
            $already = $user_review !== null;
            $ex_rating  = $already ? (int)$user_review['rating'] : 0;
            $ex_title   = $already ? ($user_review['title'] ?? '') : '';
            $ex_comment = $already ? $user_review['comment'] : '';
            ?>

            <div class="review-cta">
                <div class="icon"><i class="fas fa-star"></i></div>
                <div class="text">
                    <strong><?php echo $already ? 'Your review is live' : 'Share your experience'; ?></strong>
                    <span>
                        <?php if ($already): ?>
                            You rated this <?php echo $ex_rating; ?> star<?php echo $ex_rating == 1 ? '' : 's'; ?>.
                            You can update your review any time.
                        <?php else: ?>
                            Give a star rating and tell other customers what you think.
                        <?php endif; ?>
                    </span>
                </div>
                <button type="button" class="btn-lux btn-lux-gold" id="toggleReviewForm">
                    <i class="fas fa-<?php echo $already ? 'edit' : 'pen'; ?>"></i>
                    <?php echo $already ? 'Edit Review' : 'Write a Review'; ?>
                </button>
            </div>

            <?php if ($form_message): ?>
                <div class="form-alert <?php echo htmlspecialchars($form_type); ?>">
                    <i class="fas <?php echo $form_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($form_message); ?>
                </div>
            <?php endif; ?>

            <div class="review-form-box <?php echo $form_type === 'error' ? 'open' : ''; ?>" id="reviewFormBox">
                <h4><?php echo $already ? 'Edit your review' : 'Write your review'; ?></h4>
                <form method="POST" action="product-details.php?id=<?php echo (int)$product_id; ?>#reviews">
                    <input type="hidden" name="submit_review" value="1">
                    <input type="hidden" name="rating" id="ratingInput" value="<?php echo $ex_rating; ?>">

                    <div class="form-row">
                        <label>Your Rating *</label>
                        <div class="star-picker" id="starPicker">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="<?php echo $i <= $ex_rating ? 'fas fa-star selected' : 'far fa-star'; ?>" data-value="<?php echo $i; ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <div class="rating-hint" id="ratingHint">
                            <?php
                            $labels = [1=>'Poor',2=>'Fair',3=>'Good',4=>'Very Good',5=>'Excellent'];
                            echo $ex_rating ? $labels[$ex_rating] : 'Tap a star to rate';
                            ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <label>Review Title (optional)</label>
                        <input type="text" name="title" class="form-input" maxlength="150"
                               placeholder="Sum it up in a few words"
                               value="<?php echo htmlspecialchars($ex_title); ?>">
                    </div>

                    <div class="form-row">
                        <label>Your Review *</label>
                        <textarea name="comment" class="form-textarea" rows="5" maxlength="2000" required
                                  placeholder="What did you love? How does it look when worn? Anything other customers should know?"><?php echo htmlspecialchars($ex_comment); ?></textarea>
                    </div>

                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button type="submit" class="btn-lux btn-lux-gold">
                            <i class="fas fa-paper-plane"></i>
                            <?php echo $already ? 'Update Review' : 'Submit Review'; ?>
                        </button>
                        <button type="button" class="btn-lux btn-lux-outline" id="cancelReviewForm">Cancel</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Reviews list -->
        <?php if (empty($reviews)): ?>
            <div class="reviews-empty">
                <i class="fas fa-comment-dots"></i>
                <h4>No reviews yet</h4>
                <p>Be the first to share your experience with this piece.</p>
            </div>
        <?php else: ?>
            <div class="review-list">
                <?php foreach ($reviews as $rev): ?>
                    <article class="review-card">
                        <div class="review-head">
                            <div class="review-avatar"><?php echo strtoupper(substr($rev['full_name'] ?? 'U', 0, 1)); ?></div>
                            <div class="review-meta">
                                <div class="review-author">
                                    <?php echo htmlspecialchars($rev['full_name'] ?? 'Anonymous'); ?>
                                    <?php if (!empty($rev['is_verified'])): ?>
                                        <span class="verified-pill"><i class="fas fa-check-circle"></i> Verified</span>
                                    <?php endif; ?>
                                    <?php if ($is_logged_in && (int)$rev['user_id'] === $user_id): ?>
                                        <span class="your-review-badge">Your Review</span>
                                    <?php endif; ?>
                                </div>
                                <div class="review-stars">
                                    <?php
                                    $r = (int)$rev['rating'];
                                    for ($i = 1; $i <= 5; $i++) echo $i <= $r ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>';
                                    ?>
                                </div>
                                <div class="review-date"><?php echo date('F d, Y', strtotime($rev['updated_at'] ?? $rev['created_at'])); ?></div>
                            </div>
                        </div>

                        <?php if (!empty($rev['title'])): ?>
                            <h5 class="review-title"><?php echo htmlspecialchars($rev['title']); ?></h5>
                        <?php endif; ?>

                        <p class="review-body"><?php echo nl2br(htmlspecialchars($rev['comment'])); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($total_reviews > $review_page * $per_page): ?>
                <div class="load-more-wrap">
                    <a href="product-details.php?id=<?php echo (int)$product_id; ?>&review_page=<?php echo $review_page + 1; ?>#reviews"
                       class="btn-lux btn-lux-outline">
                        <i class="fas fa-chevron-down"></i> Load More Reviews
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Related -->
    <?php if (!empty($related_products)): ?>
    <section class="related-section">
        <div class="section-head">
            <p class="eyebrow">You May Also Like</p>
            <h2>Complete Your <em>Collection</em></h2>
            <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
        </div>
        <div class="row g-4">
            <?php foreach ($related_products as $rp): ?>
            <div class="col-md-6 col-lg-3">
                <a href="product-details.php?id=<?php echo (int)$rp['id']; ?>" class="rec-product-card">
                    <img src="../uploads/<?php echo htmlspecialchars($rp['image'] ?? 'placeholder.jpg'); ?>"
                         onerror="this.onerror=null;this.src='https://via.placeholder.com/400x300/ede7db/8a8172?text=Jewelry'"
                         alt="<?php echo htmlspecialchars($rp['name']); ?>" loading="lazy">
                    <div class="rec-product-info">
                        <div class="rec-product-name"><?php echo htmlspecialchars($rp['name']); ?></div>
                        <div class="rec-product-price">₱<?php echo number_format((float)$rp['price'], 2); ?></div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</div>

<script>
(function () {
    'use strict';

    function showNotification(message, type) {
        type = type || 'success';
        var icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        var el = document.createElement('div');
        el.className = 'toast-notification ' + type;
        var body = document.createElement('div');
        body.className = 'toast-body';
        var i = document.createElement('i'); i.className = 'fas ' + icon;
        var span = document.createElement('span'); span.textContent = message;
        body.appendChild(i); body.appendChild(span);
        el.appendChild(body);
        document.body.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s ease'; el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 300);
        }, 3000);
    }

    /* Quantity */
    var qtyInput = document.getElementById('quantity');
    var qtyMinus = document.getElementById('qtyMinus');
    var qtyPlus  = document.getElementById('qtyPlus');
    if (qtyInput && qtyMinus && qtyPlus) {
        var maxQty = parseInt(qtyInput.getAttribute('max'), 10) || 999;
        qtyMinus.addEventListener('click', function () {
            var v = parseInt(qtyInput.value, 10) || 1;
            if (v > 1) qtyInput.value = v - 1;
        });
        qtyPlus.addEventListener('click', function () {
            var v = parseInt(qtyInput.value, 10) || 1;
            if (v < maxQty) qtyInput.value = v + 1;
        });
        qtyInput.addEventListener('input', function () {
            var v = parseInt(this.value, 10);
            if (isNaN(v) || v < 1) this.value = 1;
            else if (v > maxQty) this.value = maxQty;
        });
    }

    /* Add to cart */
    var addBtn = document.getElementById('addToCartBtn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var pid = this.getAttribute('data-product-id');
            var qty = qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;
            var body = new URLSearchParams();
            body.append('product_id', pid);
            body.append('quantity', qty);
            fetch('add-to-cart.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function (r) { return r.text(); })
            .then(function (txt) {
                var res = null; try { res = JSON.parse(txt); } catch (e) {}
                if (res && res.success) {
                    showNotification('Added to cart!', 'success');
                    if (typeof window.updateCartCount === 'function') window.updateCartCount();
                } else {
                    showNotification((res && res.message) || 'Please login to add items', 'error');
                }
            })
            .catch(function () { showNotification('Network error. Please try again.', 'error'); });
        });
    }

    /* Rating bars animate */
    requestAnimationFrame(function () {
        document.querySelectorAll('.bar-fill').forEach(function (el) {
            var pct = parseFloat(el.getAttribute('data-pct')) || 0;
            setTimeout(function () { el.style.width = pct + '%'; }, 100);
        });
    });

    /* Star picker */
    var picker = document.getElementById('starPicker');
    var ratingInput = document.getElementById('ratingInput');
    var ratingHint = document.getElementById('ratingHint');
    if (picker && ratingInput) {
        var labels = {1:'Poor',2:'Fair',3:'Good',4:'Very Good',5:'Excellent'};
        var stars = picker.querySelectorAll('i');

        function paint(n) {
            stars.forEach(function (s, i) {
                if (i < n) { s.classList.remove('far'); s.classList.add('fas','selected'); }
                else       { s.classList.remove('fas','selected'); s.classList.add('far'); }
            });
        }
        stars.forEach(function (star, idx) {
            star.addEventListener('mouseenter', function () {
                stars.forEach(function (s, i) { if (i <= idx) s.classList.add('hovered'); });
                if (ratingHint) ratingHint.textContent = labels[idx + 1];
            });
            star.addEventListener('mouseleave', function () {
                stars.forEach(function (s) { s.classList.remove('hovered'); });
                var c = parseInt(ratingInput.value, 10) || 0;
                if (ratingHint) ratingHint.textContent = c ? labels[c] : 'Tap a star to rate';
            });
            star.addEventListener('click', function () {
                var v = idx + 1;
                ratingInput.value = v;
                paint(v);
                if (ratingHint) ratingHint.textContent = labels[v];
            });
        });
    }

    /* Toggle form */
    var toggleBtn = document.getElementById('toggleReviewForm');
    var formBox = document.getElementById('reviewFormBox');
    var cancelBtn = document.getElementById('cancelReviewForm');
    if (toggleBtn && formBox) {
        toggleBtn.addEventListener('click', function () {
            formBox.classList.toggle('open');
            if (formBox.classList.contains('open'))
                formBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }
    if (cancelBtn && formBox) {
        cancelBtn.addEventListener('click', function () { formBox.classList.remove('open'); });
    }

    /* Smooth scroll for #reviews anchor */
    document.querySelectorAll('a[href^="#"]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            var href = this.getAttribute('href');
            if (href.length < 2) return;
            var t = document.querySelector(href);
            if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
    });
})();
</script>

<?php include '../includes/footer.php'; ?>