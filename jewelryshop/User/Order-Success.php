<?php
// user/order-success.php — Luxury Order Success Page
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/auth.php';

/* ------------------------------------------------------------------
   1. Appearance from cookie
   ------------------------------------------------------------------ */
$theme         = 'cream';
$accent        = '#5b5fd4';
$sidebar_style = 'dark';

if (!empty($_COOKIE['rjewel_theme'])) {
    $parsed = json_decode(urldecode($_COOKIE['rjewel_theme']), true);
    if (is_array($parsed)) {
        $theme         = $parsed['theme']   ?? 'cream';
        $accent        = $parsed['accent']  ?? '#5b5fd4';
        $sidebar_style = $parsed['sidebar'] ?? 'dark';
    }
}
$theme = in_array($theme, ['cream', 'light', 'dark', 'auto'], true) ? $theme : 'cream';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#5b5fd4';

$resolved_theme = $theme;
if ($resolved_theme === 'auto') $resolved_theme = 'cream';

/* ------------------------------------------------------------------
   2. Database connection
   ------------------------------------------------------------------ */
$db = null;
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Throwable $e) {
    $db = null;
}

if (!$db) {
    die('DB connection failed. Check config/database.php credentials.');
}

$auth = new Auth($db);

if (!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$user_id  = (int)($_SESSION['user_id'] ?? 0);
$order_id = (int)($_GET['id'] ?? 0);

/* ------------------------------------------------------------------
   3. Safe query helper
   ------------------------------------------------------------------ */
function safe_query($db, $sql, $params = []) {
    if (!$db) return [];
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/* ------------------------------------------------------------------
   4. Fetch order (must belong to this user)
   ------------------------------------------------------------------ */
$order_rows = safe_query($db, "
    SELECT o.*, u.full_name, u.email
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.id = :oid AND o.user_id = :uid
", [':oid' => $order_id, ':uid' => $user_id]);

$order = $order_rows[0] ?? null;

if (!$order) {
    header("Location: account.php#orders");
    exit();
}

/* ------------------------------------------------------------------
   5. Fetch order items
   ------------------------------------------------------------------ */
$order_items = safe_query($db, "
    SELECT oi.*, p.name, p.image
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = :oid
", [':oid' => $order_id]);

/* ------------------------------------------------------------------
   6. Totals
   ------------------------------------------------------------------ */
$subtotal = 0;
foreach ($order_items as $item) {
    $subtotal += (float)$item['price'] * (int)$item['quantity'];
}

$free_threshold = 100;
$shipping_cost  = $subtotal >= $free_threshold ? 0 : 10;
$tax            = $subtotal * 0.10;
$computed_total = $subtotal + $shipping_cost + $tax;

$order_total = isset($order['total_amount']) && (float)$order['total_amount'] > 0
    ? (float)$order['total_amount']
    : $computed_total;

/* ------------------------------------------------------------------
   7. Dates
   ------------------------------------------------------------------ */
$order_created  = $order['created_at'] ?? date('Y-m-d H:i:s');
$placed_date    = date('F d, Y', strtotime($order_created));
$shipping_date  = date('M d', strtotime($order_created . ' +2 days'));
$delivery_date  = date('F d, Y', strtotime($order_created . ' +7 days'));
$delivery_short = date('M d', strtotime($order_created . ' +7 days'));

/* ------------------------------------------------------------------
   8. Status handling
   ------------------------------------------------------------------ */
$status = strtolower($order['status'] ?? 'pending');
$status_index = 0;
$status_map   = ['pending' => 0, 'processing' => 1, 'shipped' => 2, 'delivered' => 3];
if (isset($status_map[$status])) $status_index = $status_map[$status];
$is_cancelled = ($status === 'cancelled');

/* ------------------------------------------------------------------
   9. Recommended products
   ------------------------------------------------------------------ */
$recommended = safe_query($db,
    "SELECT * FROM products WHERE featured = 1 AND stock > 0 ORDER BY RAND() LIMIT 4");

/* ------------------------------------------------------------------
   10. Shipping address fallback
   ------------------------------------------------------------------ */
$ship_address = $order['shipping_address'] ?? ($order['address'] ?? '');

$page_title = 'Order Confirmed | R-Jewel&Gold';
?>
<?php include '../includes/header.php'; ?>

<style>
    /* ============================================================
       THEME VARIABLES
       ============================================================ */
    :root {
        --bg: #f2ede4;
        --bg-soft: #faf7f2;
        --card: #ffffff;
        --border: #e5ddd0;
        --border-light: #ede7db;
        --text: #5a5245;
        --text-bright: #2b2620;
        --text-muted: #8a8172;
        --text-dim: #b3a998;
        --primary: <?php echo htmlspecialchars($accent); ?>;
        --primary-soft: <?php echo htmlspecialchars($accent); ?>18;
        --gold: #b8941f;
        --gold-soft: rgba(184, 148, 31, 0.1);
        --gold-strong: #8a6e14;
        --success: #0d9668;
        --danger: #dc2626;
        --info: #5b5fd4;
        --section-alt: #faf7f2;
    }
    <?php if ($resolved_theme === 'dark'): ?>
    :root {
        --bg: #151821; --bg-soft: #1c202b; --card: #222735;
        --border: #343b4d; --border-light: #2d3444;
        --text: #d5d9e2; --text-bright: #ffffff; --text-muted: #a0a8ba;
        --text-dim: #777f91; --section-alt: #1c202b;
    }
    <?php elseif ($resolved_theme === 'light'): ?>
    :root {
        --bg: #f5f7fb; --bg-soft: #ffffff; --card: #ffffff;
        --border: #e2e8f0; --border-light: #edf1f6;
        --text: #475569; --text-bright: #172033; --text-muted: #64748b;
        --text-dim: #94a3b8; --section-alt: #f5f7fb;
    }
    <?php endif; ?>

    /* ============================================================
       BASE
       ============================================================ */
    body, h1, h2, h3, h4, h5, h6,
    .success-hero h1, .card-head .title-text,
    .order-meta .order-num, .step-title,
    .item-name, .item-price,
    .summary-row .value,
    .rec-product-name, .rec-product-price,
    .section-head h2 {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    body {
        background: var(--bg);
        color: var(--text);
        transition: background-color .3s ease, color .3s ease;
    }
    h1, h2, h3, h4, h5, h6 {
        color: var(--text-bright);
        font-weight: 600;
    }

    /* ============================================================
       HERO
       ============================================================ */
    .success-hero {
        position: relative;
        background: var(--bg);
        background-image:
            radial-gradient(ellipse at 20% 20%, rgba(184, 148, 31, 0.16), transparent 55%),
            radial-gradient(ellipse at 80% 80%, rgba(91, 95, 212, 0.08), transparent 55%);
        overflow: hidden;
        padding: 4.5rem 1.5rem 4rem;
        text-align: center;
        border-bottom: 1px solid var(--border-light);
    }
    .success-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            radial-gradient(1.5px 1.5px at 15% 25%, rgba(184, 148, 31, 0.4), transparent),
            radial-gradient(1px 1px at 45% 60%, rgba(138, 129, 114, 0.25), transparent),
            radial-gradient(1.5px 1.5px at 75% 15%, rgba(184, 148, 31, 0.35), transparent),
            radial-gradient(1px 1px at 85% 75%, rgba(138, 129, 114, 0.2), transparent),
            radial-gradient(1px 1px at 30% 85%, rgba(184, 148, 31, 0.3), transparent);
        pointer-events: none;
    }
    .success-hero > * { position: relative; z-index: 2; }

    .success-icon {
        width: 110px;
        height: 110px;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
        box-shadow: 0 20px 45px -15px rgba(184, 148, 31, 0.65),
                    0 0 0 10px rgba(184, 148, 31, 0.08),
                    0 0 0 20px rgba(184, 148, 31, 0.04);
        animation: scaleIn 0.7s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative;
    }
    .success-icon::after {
        content: '';
        position: absolute;
        inset: -8px;
        border-radius: 50%;
        border: 1px dashed rgba(184, 148, 31, 0.45);
        animation: rotateRing 18s linear infinite;
    }
    @keyframes rotateRing {
        from { transform: rotate(0deg); }
        to   { transform: rotate(360deg); }
    }
    .success-icon i {
        font-size: 52px;
        color: #fff;
    }
    @keyframes scaleIn {
        from { transform: scale(0); opacity: 0; }
        to   { transform: scale(1); opacity: 1; }
    }

    .success-hero .eyebrow {
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.4em;
        text-transform: uppercase;
        color: var(--gold);
        margin-bottom: 0.75rem;
    }
    .success-hero h1 {
        font-size: clamp(2rem, 5vw, 3rem);
        margin-bottom: 0.75rem;
        line-height: 1.05;
        animation: fadeInUp 0.6s ease-out 0.2s both;
    }
    .success-hero h1 em { color: var(--gold); font-style: italic; font-weight: 500; }
    .success-hero p {
        font-size: 1rem;
        color: var(--text-muted);
        max-width: 560px;
        margin: 0 auto;
        animation: fadeInUp 0.6s ease-out 0.4s both;
    }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .hero-divider {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        max-width: 220px;
        margin: 1.5rem auto 0;
        animation: fadeInUp 0.6s ease-out 0.55s both;
    }
    .hero-divider .line {
        flex: 1; height: 1px;
        background: linear-gradient(90deg, transparent, rgba(184, 148, 31, 0.6), transparent);
    }
    .hero-divider .diamond {
        width: 6px; height: 6px; background: var(--gold);
        transform: rotate(45deg);
        box-shadow: 0 0 10px rgba(184, 148, 31, 0.7);
    }

    /* ============================================================
       BUTTONS
       ============================================================ */
    .btn-lux {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 0.85rem 1.6rem;
        border-radius: 9px;
        border: none;
        font-family: 'Inter', sans-serif;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.3s ease;
        white-space: nowrap;
    }
    .btn-lux-gold {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff;
        box-shadow: 0 4px 14px -3px rgba(184, 148, 31, 0.45);
    }
    .btn-lux-gold:hover { color:#fff; transform: translateY(-2px); box-shadow: 0 8px 22px -5px rgba(184,148,31,.6); }
    .btn-lux-outline {
        background: transparent;
        color: var(--text-bright);
        border: 1px solid var(--border);
    }
    .btn-lux-outline:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-2px); }
    .btn-lux-outline-gold {
        background: transparent;
        color: var(--gold);
        border: 1px solid rgba(184, 148, 31, 0.4);
    }
    .btn-lux-outline-gold:hover { background: var(--gold); color: #fff; border-color: var(--gold); }

    /* ============================================================
       CONTAINER
       ============================================================ */
    .success-container { padding: 3.5rem 0 5rem; }

    /* ============================================================
       CARDS
       ============================================================ */
    .lux-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 1.75rem;
        margin-bottom: 1.5rem;
        transition: border-color 0.3s ease;
    }
    .lux-card:hover { border-color: rgba(184, 148, 31, 0.35); }

    .card-head {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-light);
    }
    .card-head .icon-circle {
        width: 40px; height: 40px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.25);
        color: var(--gold);
        font-size: 0.9rem;
        flex-shrink: 0;
    }
    .card-head .title-text {
        font-size: 1.4rem;
        font-weight: 600;
        color: var(--text-bright);
        line-height: 1.1;
    }
    .card-head .title-text em { color: var(--gold); font-style: italic; font-weight: 500; }

    /* Order meta strip */
    .order-meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 1rem 1.25rem;
        background: var(--bg-soft);
        border: 1px solid var(--border);
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }
    .order-meta .order-num {
        font-size: 1.35rem;
        font-weight: 600;
        color: var(--text-bright);
    }
    .order-meta .order-num em {
        color: var(--gold);
        font-style: italic;
        font-weight: 500;
        font-size: 0.75em;
        letter-spacing: 0.05em;
        margin-right: 4px;
    }
    .confirmed-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 20px;
        background: rgba(13, 150, 104, 0.12);
        border: 1px solid rgba(13, 150, 104, 0.3);
        color: #0d9668;
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
    }
    .order-meta .order-date {
        font-size: 0.82rem;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .order-meta .order-date i { color: var(--gold); }

    .confirmation-note {
        font-size: 0.9rem;
        color: var(--text);
        margin-bottom: 1.5rem;
        line-height: 1.65;
    }
    .confirmation-note strong { color: var(--text-bright); }

    /* ============================================================
       TIMELINE
       ============================================================ */
    .timeline-progress {
        display: flex;
        justify-content: space-between;
        margin: 2rem 0 1.5rem;
        position: relative;
    }
    .timeline-progress::before {
        content: '';
        position: absolute;
        top: 30px;
        left: 8%;
        right: 8%;
        height: 2px;
        background: var(--border);
        z-index: 0;
    }
    .timeline-progress .fill {
        position: absolute;
        top: 30px;
        left: 8%;
        height: 2px;
        background: linear-gradient(90deg, var(--gold), var(--gold-strong));
        box-shadow: 0 0 10px rgba(184, 148, 31, 0.55);
        z-index: 1;
        transition: width 0.8s ease;
    }

    .timeline-step {
        text-align: center;
        position: relative;
        z-index: 2;
        flex: 1;
        min-width: 0;
    }
    .step-icon {
        width: 60px; height: 60px;
        background: var(--card);
        border: 2px solid var(--border);
        color: var(--text-muted);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 0.75rem;
        transition: all 0.35s ease;
        font-size: 1.15rem;
    }
    .timeline-step.completed .step-icon {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        border-color: var(--gold);
        color: #fff;
        box-shadow: 0 6px 18px -8px rgba(184, 148, 31, 0.7);
    }
    .timeline-step.active .step-icon {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        border-color: var(--gold);
        color: #fff;
        box-shadow: 0 6px 18px -8px rgba(184, 148, 31, 0.7),
                    0 0 0 6px rgba(184, 148, 31, 0.18);
        animation: pulseGold 1.8s ease-in-out infinite;
    }
    @keyframes pulseGold {
        0%, 100% { transform: scale(1); box-shadow: 0 6px 18px -8px rgba(184, 148, 31, 0.7), 0 0 0 6px rgba(184, 148, 31, 0.18); }
        50%      { transform: scale(1.06); box-shadow: 0 6px 18px -8px rgba(184, 148, 31, 0.75), 0 0 0 10px rgba(184, 148, 31, 0.08); }
    }

    .step-title {
        font-size: 1.05rem;
        font-weight: 600;
        color: var(--text-bright);
        margin-top: 0.4rem;
        line-height: 1.2;
    }
    .timeline-step.completed .step-title,
    .timeline-step.active .step-title { color: var(--gold); }
    .step-date {
        font-size: 0.68rem;
        color: var(--text-muted);
        margin-top: 0.2rem;
        letter-spacing: 0.08em;
    }

    /* Delivery note */
    .delivery-note {
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.28);
        border-radius: 12px;
        padding: 1rem 1.25rem;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        font-size: 0.85rem;
        color: var(--text-bright);
        line-height: 1.55;
    }
    .delivery-note i {
        color: var(--gold);
        font-size: 1.2rem;
        margin-top: 2px;
        flex-shrink: 0;
    }
    .delivery-note strong { color: var(--text-bright); }
    .delivery-note small { color: var(--text-muted); display: block; margin-top: 4px; }

    /* ============================================================
       ORDER ITEMS
       ============================================================ */
    .order-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 1rem 0;
        border-bottom: 1px solid var(--border-light);
    }
    .order-item:last-child { border-bottom: none; }

    .order-item img {
        width: 72px;
        height: 72px;
        object-fit: cover;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: var(--bg-soft);
        flex-shrink: 0;
    }
    .item-details { flex: 1; min-width: 0; }
    .item-name {
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--text-bright);
        line-height: 1.2;
        margin-bottom: 0.2rem;
    }
    .item-meta {
        font-size: 0.72rem;
        color: var(--text-muted);
        letter-spacing: 0.08em;
    }
    .item-price {
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--gold);
        white-space: nowrap;
    }

    /* ============================================================
       ACTION BUTTONS
       ============================================================ */
    .action-buttons {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        margin-top: 1.5rem;
    }

    /* ============================================================
       SUMMARY
       ============================================================ */
    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 0.65rem 0;
        font-size: 0.88rem;
        color: var(--text);
    }
    .summary-row .label { color: var(--text-muted); }
    .summary-row .value {
        font-size: 1.05rem;
        font-weight: 600;
        color: var(--text-bright);
    }
    .summary-row.total {
        border-top: 1px dashed var(--border);
        margin-top: 0.5rem;
        padding-top: 1rem;
    }
    .summary-row.total .label {
        font-family: 'Inter', sans-serif;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        font-size: 0.72rem;
        color: var(--text-bright);
        align-self: center;
    }
    .summary-row.total .value {
        font-size: 1.6rem;
        color: var(--gold);
    }

    /* ============================================================
       SHIPPING INFO
       ============================================================ */
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 0.55rem 0;
        font-size: 0.85rem;
        gap: 16px;
    }
    .info-row .key {
        color: var(--text-muted);
        font-weight: 500;
        flex-shrink: 0;
    }
    .info-row .val {
        color: var(--text-bright);
        font-weight: 600;
        text-align: right;
        word-break: break-word;
    }

    .address-block {
        background: var(--bg-soft);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 0.9rem 1rem;
        font-size: 0.85rem;
        line-height: 1.65;
        color: var(--text-bright);
        margin-top: 0.5rem;
    }
    .address-block .label {
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--gold);
        margin-bottom: 0.35rem;
    }

    /* ============================================================
       RECOMMENDED
       ============================================================ */
    .recommended-section { margin-top: 5rem; }

    .section-head {
        text-align: center;
        margin-bottom: 3rem;
    }
    .section-head .eyebrow {
        font-size: 0.68rem; font-weight: 700; letter-spacing: 0.4em;
        text-transform: uppercase; color: var(--gold); margin-bottom: 0.75rem;
    }
    .section-head h2 { font-size: clamp(1.7rem, 3.5vw, 2.3rem); margin-bottom: 0.6rem; }
    .section-head h2 em { color: var(--gold); font-style: italic; font-weight: 500; }
    .section-head p { color: var(--text-muted); max-width: 520px; margin: 0 auto; font-size: 0.92rem; }
    .small-divider {
        display: flex; align-items: center; justify-content: center; gap: 10px;
        max-width: 140px; margin: 0.85rem auto 0;
    }
    .small-divider .line {
        flex: 1; height: 1px;
        background: linear-gradient(90deg, transparent, rgba(184, 148, 31, 0.5), transparent);
    }
    .small-divider .diamond { width: 5px; height: 5px; background: var(--gold); transform: rotate(45deg); }

    .rec-product-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        overflow: hidden;
        transition: all 0.35s ease;
        text-decoration: none;
        color: inherit;
        display: block;
        height: 100%;
    }
    .rec-product-card:hover {
        transform: translateY(-8px);
        border-color: var(--gold);
        box-shadow: 0 20px 40px -18px rgba(90, 82, 69, 0.25);
        color: inherit;
    }
    .rec-product-img-wrap {
        overflow: hidden;
        background: var(--bg-soft);
    }
    .rec-product-card img {
        width: 100%;
        height: 200px;
        object-fit: cover;
        display: block;
        transition: transform 0.6s ease;
    }
    .rec-product-card:hover img { transform: scale(1.06); }
    .rec-product-info {
        padding: 1.1rem 1.1rem 1.25rem;
        text-align: center;
    }
    .rec-product-name {
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--text-bright);
        margin-bottom: 0.25rem;
        line-height: 1.25;
    }
    .rec-product-price {
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--gold);
    }

    /* ============================================================
       CONFETTI
       ============================================================ */
    .confetti {
        position: fixed;
        width: 10px;
        height: 10px;
        top: -10px;
        pointer-events: none;
        z-index: 9998;
        animation: confetti-fall 3s linear forwards;
    }
    @keyframes confetti-fall {
        0%   { transform: translateY(-10px) rotate(0deg); opacity: 1; }
        100% { transform: translateY(100vh) rotate(720deg); opacity: 0; }
    }

    /* ============================================================
       RESPONSIVE
       ============================================================ */
    @media (max-width: 991px) {
        .success-container { padding: 2.5rem 0 3.5rem; }
    }
    @media (max-width: 768px) {
        .success-hero { padding: 3rem 1rem 2.5rem; }
        .success-icon { width: 90px; height: 90px; margin-bottom: 1.25rem; }
        .success-icon i { font-size: 42px; }
        .timeline-progress::before,
        .timeline-progress .fill { left: 12%; right: 12%; }
        .step-icon { width: 48px; height: 48px; font-size: 0.95rem; }
        .step-title { font-size: 0.85rem; }
        .step-date { font-size: 0.6rem; }
        .action-buttons { grid-template-columns: 1fr; }
        .summary-row.total .value { font-size: 1.35rem; }
        .order-item img { width: 60px; height: 60px; }
        .item-name { font-size: 1rem; }
    }
    @media (max-width: 480px) {
        .order-meta { flex-direction: column; align-items: flex-start; }
        .step-date { display: none; }
    }
    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        .confetti { display: none !important; }
    }
</style>

<!-- ============================================================
     HERO
     ============================================================ -->
<section class="success-hero">
    <div class="success-icon">
        <i class="fas fa-check"></i>
    </div>
    <p class="eyebrow">R-Jewel&Gold · Order Confirmed</p>
    <h1>Thank You for Your <em>Order</em></h1>
    <p>Your pieces have been reserved and our team is already preparing them with care.</p>
    <div class="hero-divider">
        <span class="line"></span>
        <span class="diamond"></span>
        <span class="line"></span>
    </div>
</section>

<!-- ============================================================
     CONTENT
     ============================================================ -->
<div class="success-container">
    <div class="container">
        <div class="row g-4">
            <!-- ============ Main ============ -->
            <div class="col-lg-8">

                <!-- Order confirmation card -->
                <div class="lux-card">
                    <div class="order-meta">
                        <div>
                            <div class="order-num"><em>Order</em> #<?php echo str_pad((int)$order['id'], 6, '0', STR_PAD_LEFT); ?></div>
                        </div>
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <span class="confirmed-pill">
                                <i class="fas fa-check-circle"></i> Confirmed
                            </span>
                            <span class="order-date">
                                <i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($placed_date); ?>
                            </span>
                        </div>
                    </div>

                    <p class="confirmation-note">
                        We've sent a confirmation email to <strong><?php echo htmlspecialchars($order['email'] ?? 'your inbox'); ?></strong>
                        with all the details. Keep an eye on it for tracking updates once your order ships.
                    </p>

                    <!-- Timeline -->
                    <?php
                    $fill_pct_map = [0 => 0, 1 => 33.33, 2 => 66.66, 3 => 100];
                    $fill_pct = $is_cancelled ? 0 : ($fill_pct_map[$status_index] ?? 0);

                    $steps = [
                        ['label' => 'Order Placed', 'icon' => 'fa-receipt',      'date' => date('M d', strtotime($order_created))],
                        ['label' => 'Processing',   'icon' => 'fa-cog',           'date' => date('M d', strtotime($order_created . ' +1 day'))],
                        ['label' => 'Shipped',      'icon' => 'fa-truck',         'date' => $shipping_date],
                        ['label' => 'Delivered',    'icon' => 'fa-check-circle',  'date' => $delivery_short],
                    ];
                    ?>
                    <div class="timeline-progress">
                        <div class="fill" style="width: calc((100% - 16%) * <?php echo $fill_pct / 100; ?>);"></div>
                        <?php foreach ($steps as $i => $step):
                            $cls = '';
                            if (!$is_cancelled) {
                                if ($i < $status_index)      $cls = 'completed';
                                elseif ($i === $status_index) $cls = 'active';
                            }
                        ?>
                            <div class="timeline-step <?php echo $cls; ?>">
                                <div class="step-icon"><i class="fas <?php echo $step['icon']; ?>"></i></div>
                                <div class="step-title"><?php echo $step['label']; ?></div>
                                <div class="step-date"><?php echo htmlspecialchars($step['date']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Delivery note -->
                    <?php if ($is_cancelled): ?>
                        <div class="delivery-note" style="background: rgba(220, 38, 38, 0.06); border-color: rgba(220, 38, 38, 0.25);">
                            <i class="fas fa-times-circle" style="color: var(--danger);"></i>
                            <div>
                                <strong>This order was cancelled.</strong>
                                <small>If you have questions, please contact our support team.</small>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="delivery-note">
                            <i class="fas fa-truck-fast"></i>
                            <div>
                                <strong>Estimated Delivery: <?php echo htmlspecialchars($delivery_date); ?></strong>
                                <small>You'll receive tracking information via email once your order ships.</small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Order items -->
                <div class="lux-card">
                    <div class="card-head">
                        <div class="icon-circle"><i class="fas fa-box"></i></div>
                        <div class="title-text">Order <em>Items</em></div>
                    </div>

                    <?php if (empty($order_items)): ?>
                        <p style="color:var(--text-muted); text-align:center; padding:1.5rem 0;">
                            <i class="fas fa-box-open" style="font-size:1.5rem; color:var(--gold); display:block; margin-bottom:0.5rem;"></i>
                            No items recorded for this order.
                        </p>
                    <?php else: ?>
                        <?php foreach ($order_items as $item): ?>
                        <div class="order-item">
                            <img src="../uploads/<?php echo htmlspecialchars($item['image'] ?? 'placeholder.jpg'); ?>"
                                 onerror="this.onerror=null;this.src='https://via.placeholder.com/100x100/ede7db/8a8172?text=J'"
                                 alt="<?php echo htmlspecialchars($item['name']); ?>"
                                 loading="lazy">
                            <div class="item-details">
                                <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="item-meta">Qty <?php echo (int)$item['quantity']; ?> · ₱<?php echo number_format((float)$item['price'], 2); ?> each</div>
                            </div>
                            <div class="item-price">
                                ₱<?php echo number_format((float)$item['price'] * (int)$item['quantity'], 2); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="action-buttons">
                        <a href="shop.php" class="btn-lux btn-lux-gold">
                            <i class="fas fa-shopping-bag"></i> Continue Shopping
                        </a>
                        <a href="account.php#orders" class="btn-lux btn-lux-outline">
                            <i class="fas fa-list"></i> View Orders
                        </a>
                        <a href="account.php" class="btn-lux btn-lux-outline">
                            <i class="fas fa-user"></i> My Account
                        </a>
                    </div>
                </div>
            </div>

            <!-- ============ Sidebar ============ -->
            <div class="col-lg-4">

                <!-- Order summary -->
                <div class="lux-card">
                    <div class="card-head">
                        <div class="icon-circle"><i class="fas fa-receipt"></i></div>
                        <div class="title-text">Order <em>Summary</em></div>
                    </div>

                    <div class="summary-row">
                        <span class="label">Subtotal</span>
                        <span class="value">₱<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="label">Shipping</span>
                        <span class="value"><?php echo $shipping_cost > 0 ? '₱' . number_format($shipping_cost, 2) : 'Free'; ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="label">Tax (10%)</span>
                        <span class="value">₱<?php echo number_format($tax, 2); ?></span>
                    </div>
                    <div class="summary-row total">
                        <span class="label">Total</span>
                        <span class="value">₱<?php echo number_format($order_total, 2); ?></span>
                    </div>
                </div>

                <!-- Shipping information -->
                <div class="lux-card">
                    <div class="card-head">
                        <div class="icon-circle"><i class="fas fa-truck"></i></div>
                        <div class="title-text">Shipping <em>Info</em></div>
                    </div>

                    <div class="info-row">
                        <span class="key">Recipient</span>
                        <span class="val"><?php echo htmlspecialchars($order['full_name'] ?? ''); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="key">Payment</span>
                        <span class="val"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $order['payment_method'] ?? 'N/A'))); ?></span>
                    </div>

                    <div class="address-block">
                        <div class="label">Delivery Address</div>
                        <?php echo nl2br(htmlspecialchars($ship_address ?: 'No address provided.')); ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- ============ Recommended ============ -->
        <?php if (!empty($recommended)): ?>
        <div class="recommended-section">
            <div class="section-head">
                <p class="eyebrow">Complete Your Collection</p>
                <h2>You May Also <em>Like</em></h2>
                <p>Hand-picked pieces to complement your new jewelry</p>
                <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
            </div>

            <div class="row g-4">
                <?php foreach ($recommended as $product): ?>
                <div class="col-md-6 col-lg-3">
                    <a href="product-details.php?id=<?php echo (int)$product['id']; ?>" class="rec-product-card">
                        <div class="rec-product-img-wrap">
                            <img src="../uploads/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                                 onerror="this.onerror=null;this.src='https://via.placeholder.com/400x300/ede7db/8a8172?text=Jewelry'"
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 loading="lazy">
                        </div>
                        <div class="rec-product-info">
                            <div class="rec-product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                            <div class="rec-product-price">₱<?php echo number_format((float)$product['price'], 2); ?></div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    'use strict';

    /* ============================================================
       Confetti — gold + theme colors
       ============================================================ */
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduceMotion) {
        var colors = ['#b8941f', '#8a6e14', '#c9b27a', '#5b5fd4', '#0d9668', '#f5e6b8'];
        var count = 70;
        for (var i = 0; i < count; i++) {
            var c = document.createElement('div');
            c.className = 'confetti';
            c.style.left = Math.random() * 100 + '%';
            c.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
            var size = Math.random() * 8 + 5;
            c.style.width  = size + 'px';
            c.style.height = size * (Math.random() > 0.5 ? 1 : 0.4) + 'px';
            c.style.animationDelay    = (Math.random() * 1.5) + 's';
            c.style.animationDuration = (Math.random() * 2 + 2) + 's';
            c.style.opacity = 0.85;
            document.body.appendChild(c);
            (function (el) {
                setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 6000);
            })(c);
        }
    }

    /* ============================================================
       Analytics — purchase event (safe even if gtag missing)
       ============================================================ */
    if (typeof window.gtag === 'function') {
        window.gtag('event', 'purchase', {
            transaction_id: <?php echo (int)$order['id']; ?>,
            value: <?php echo json_encode(round($order_total, 2)); ?>,
            currency: 'PHP',
            items: <?php
                echo json_encode(array_map(function ($it) {
                    return [
                        'item_id'   => (int)$it['product_id'],
                        'item_name' => $it['name'],
                        'price'     => (float)$it['price'],
                        'quantity'  => (int)$it['quantity'],
                    ];
                }, $order_items));
            ?>
        });
    }

    /* ============================================================
       Refresh the header message badge so the thank-you message
       notification shows immediately (no 30-second wait).
       Uses the public helpers exposed by includes/header.php.
       ============================================================ */
    function refreshHeaderMessages() {
        if (typeof window.refreshMessages === 'function') {
            window.refreshMessages();
        }
        if (typeof window.updateMessageBadge === 'function') {
            window.updateMessageBadge(1); // optimistic bump; real count syncs below
        }

        // Ask the header AJAX endpoint for the real count.
        var url = window.RJEWEL_AJAX_URL;
        if (!url) return;

        fetch(url + '?action=get_message_count', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success && typeof window.updateMessageBadge === 'function') {
                window.updateMessageBadge(res.count);
            }
        })
        .catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshHeaderMessages);
    } else {
        refreshHeaderMessages();
    }
})();
</script>

<?php include '../includes/footer.php'; ?>