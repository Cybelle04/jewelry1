<?php
// user/order-details.php — Luxury Order Details Page
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/auth.php';

/* ------------------------------------------------------------------
   1. Appearance from cookie (same as index.php)
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
$resolved_theme = in_array($theme, ['cream', 'light', 'dark'], true) ? $theme : 'cream';

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

$auth = $db ? new Auth($db) : null;

if (!$auth || !$auth->isLoggedIn()) {
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
    SELECT o.*, u.full_name, u.email, u.phone
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.id = :oid AND o.user_id = :uid
", [':oid' => $order_id, ':uid' => $user_id]);

$order = $order_rows[0] ?? null;

if (!$order) {
    /* Fallback: no orders.php yet → redirect to account.php#orders */
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
   6. Status helpers
   ------------------------------------------------------------------ */
$status = strtolower($order['status'] ?? 'pending');
$status_label_map = [
    'pending'    => 'Pending',
    'processing' => 'Processing',
    'shipped'    => 'Shipped',
    'delivered'  => 'Delivered',
    'cancelled'  => 'Cancelled',
];
$status_label = $status_label_map[$status] ?? ucfirst($status);

/* Progress stepper: 0=pending, 1=processing, 2=shipped, 3=delivered */
$stepper_index = 0;
if ($status === 'processing') $stepper_index = 1;
elseif ($status === 'shipped') $stepper_index = 2;
elseif ($status === 'delivered') $stepper_index = 3;
elseif ($status === 'cancelled') $stepper_index = -1;

/* ------------------------------------------------------------------
   7. Totals from line items (fallback if order.total_amount missing)
   ------------------------------------------------------------------ */
$computed_subtotal = 0;
foreach ($order_items as $it) {
    $computed_subtotal += (float)$it['price'] * (int)$it['quantity'];
}

$order_total = isset($order['total_amount']) && $order['total_amount'] > 0
    ? (float)$order['total_amount']
    : $computed_subtotal;

/* Try to reverse-engineer shipping/tax from stored total */
$stored_subtotal = $computed_subtotal;
$stored_shipping = max(0, $order_total - $stored_subtotal); /* rough */

/* If shipping_address missing, fall back to order address field */
$ship_address = $order['shipping_address'] ?? ($order['address'] ?? '');

$page_title = 'Order #' . str_pad($order_id, 6, '0', STR_PAD_LEFT) . ' | R-Jewel&Gold';
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
    body {
        font-family: 'Inter', sans-serif;
        background: var(--bg);
        color: var(--text);
        transition: background-color .3s ease, color .3s ease;
    }
    h1, h2, h3, h4, h5 {
        font-family: 'Cormorant Garamond', serif;
        color: var(--text-bright);
        font-weight: 600;
    }

    /* ============================================================
       HERO
       ============================================================ */
    .order-hero {
        position: relative;
        background: var(--bg);
        background-image:
            radial-gradient(ellipse at 20% 20%, rgba(184, 148, 31, 0.14), transparent 55%),
            radial-gradient(ellipse at 80% 80%, rgba(91, 95, 212, 0.08), transparent 55%);
        overflow: hidden;
        padding: 4rem 1.5rem 3.5rem;
        border-bottom: 1px solid var(--border-light);
    }
    .order-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            radial-gradient(1.5px 1.5px at 15% 25%, rgba(184, 148, 31, 0.35), transparent),
            radial-gradient(1px 1px at 45% 60%, rgba(138, 129, 114, 0.25), transparent),
            radial-gradient(1.5px 1.5px at 75% 15%, rgba(184, 148, 31, 0.3), transparent),
            radial-gradient(1px 1px at 85% 75%, rgba(138, 129, 114, 0.2), transparent);
        pointer-events: none;
    }
    .order-hero .container { position: relative; z-index: 2; }

    .lux-breadcrumb {
        display: flex;
        gap: 8px;
        font-size: 0.7rem;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--text-dim);
        margin-bottom: 1rem;
        font-weight: 600;
        flex-wrap: wrap;
    }
    .lux-breadcrumb a { color: var(--text-muted); text-decoration: none; transition: color .25s; }
    .lux-breadcrumb a:hover { color: var(--gold); }
    .lux-breadcrumb .sep { color: var(--text-dim); }
    .lux-breadcrumb .current { color: var(--gold); }

    .order-hero-head {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 20px;
    }
    .order-hero-head h1 {
        font-size: clamp(2rem, 4.5vw, 3rem);
        margin: 0 0 0.35rem;
        line-height: 1.05;
    }
    .order-hero-head h1 em { color: var(--gold); font-style: italic; font-weight: 500; }
    .order-hero-head p {
        margin: 0;
        color: var(--text-muted);
        font-size: 0.92rem;
    }
    .order-id-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 14px;
        border-radius: 20px;
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.35);
        color: var(--gold-strong);
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        margin-top: 0.75rem;
    }
    .hero-divider {
        display: flex;
        align-items: center;
        gap: 12px;
        max-width: 180px;
        margin-top: 1rem;
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

    .hero-status-wrap {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 30px;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.2em;
        text-transform: uppercase;
    }
    .status-badge i { font-size: 0.9rem; }
    .status-pending    { background: rgba(184, 148, 31, 0.12); color: var(--gold-strong); border: 1px solid rgba(184, 148, 31, 0.3); }
    .status-processing { background: rgba(91, 95, 212, 0.12);  color: #5b5fd4; border: 1px solid rgba(91, 95, 212, 0.3); }
    .status-shipped    { background: rgba(13, 150, 104, 0.12); color: #0d9668; border: 1px solid rgba(13, 150, 104, 0.3); }
    .status-delivered  { background: rgba(13, 150, 104, 0.18); color: #0a7d56; border: 1px solid rgba(13, 150, 104, 0.4); }
    .status-cancelled  { background: rgba(220, 38, 38, 0.12);  color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.3); }

    /* ============================================================
       BUTTONS
       ============================================================ */
    .btn-lux {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 0.7rem 1.4rem;
        border-radius: 9px;
        border: none;
        font-family: 'Inter', sans-serif;
        font-size: 0.72rem;
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
    .order-container { padding: 3.5rem 0 5rem; }

    /* ============================================================
       PROGRESS STEPPER
       ============================================================ */
    .progress-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 2rem 1.5rem;
        margin-bottom: 1.5rem;
        transition: border-color 0.3s ease;
    }
    .progress-card:hover { border-color: rgba(184, 148, 31, 0.35); }

    .progress-head {
        text-align: center;
        margin-bottom: 2rem;
    }
    .progress-head .eyebrow {
        font-size: 0.65rem; font-weight: 700; letter-spacing: 0.35em;
        text-transform: uppercase; color: var(--gold); margin-bottom: 0.4rem;
    }
    .progress-head h3 {
        font-size: 1.6rem;
        margin: 0;
    }
    .progress-head h3 em { color: var(--gold); font-style: italic; font-weight: 500; }

    .stepper {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        position: relative;
        max-width: 720px;
        margin: 0 auto;
    }
    .stepper::before {
        content: '';
        position: absolute;
        top: 22px;
        left: 40px;
        right: 40px;
        height: 2px;
        background: var(--border);
        z-index: 0;
    }
    .stepper .progress-fill {
        position: absolute;
        top: 22px;
        left: 40px;
        height: 2px;
        background: linear-gradient(90deg, var(--gold), var(--gold-strong));
        box-shadow: 0 0 10px rgba(184, 148, 31, 0.5);
        z-index: 1;
        transition: width 0.6s ease;
    }
    .step {
        position: relative;
        z-index: 2;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        flex: 1;
        min-width: 0;
    }
    .step-circle {
        width: 46px; height: 46px;
        border-radius: 50%;
        background: var(--card);
        border: 2px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        font-size: 0.9rem;
        transition: all 0.35s ease;
        margin-bottom: 0.75rem;
    }
    .step.done .step-circle {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        border-color: var(--gold);
        color: #fff;
        box-shadow: 0 6px 20px -8px rgba(184, 148, 31, 0.7);
    }
    .step.done .step-label {
        color: var(--text-bright);
        font-weight: 700;
    }
    .step-label {
        font-size: 0.68rem;
        font-weight: 600;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: var(--text-muted);
        line-height: 1.3;
        transition: color 0.3s ease;
    }
    .step-sub {
        font-size: 0.68rem;
        color: var(--text-dim);
        margin-top: 0.25rem;
    }

    .cancelled-banner {
        background: rgba(220, 38, 38, 0.06);
        border: 1px solid rgba(220, 38, 38, 0.25);
        border-radius: 12px;
        padding: 1rem 1.25rem;
        color: var(--danger);
        font-size: 0.88rem;
        display: flex;
        align-items: center;
        gap: 12px;
        max-width: 720px;
        margin: 0 auto;
    }
    .cancelled-banner i { font-size: 1.2rem; }

    /* ============================================================
       INFO CARDS
       ============================================================ */
    .info-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 1.75rem;
        height: 100%;
        transition: border-color 0.3s ease;
    }
    .info-card:hover { border-color: rgba(184, 148, 31, 0.35); }

    .info-card-head {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-light);
    }
    .info-card-head .icon-circle {
        width: 40px; height: 40px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.25);
        color: var(--gold);
        font-size: 0.9rem;
        flex-shrink: 0;
    }
    .info-card-head .title-text {
        font-family: 'Cormorant Garamond', serif;
        font-size: 1.3rem;
        font-weight: 600;
        color: var(--text-bright);
        line-height: 1.1;
    }
    .info-card-head .title-text em { color: var(--gold); font-style: italic; font-weight: 500; }

    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 0.6rem 0;
        font-size: 0.88rem;
        border-bottom: 1px dashed var(--border-light);
        gap: 16px;
    }
    .info-row:last-child { border-bottom: none; }
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
    .info-row .val.mono {
        font-family: 'Cormorant Garamond', serif;
        font-size: 1.05rem;
    }

    .address-block {
        background: var(--bg-soft);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 1rem;
        font-size: 0.88rem;
        line-height: 1.7;
        color: var(--text-bright);
    }
    .address-block .label {
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--gold);
        margin-bottom: 0.4rem;
    }

    /* ============================================================
       ORDER ITEMS TABLE
       ============================================================ */
    .items-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        overflow: hidden;
        margin-top: 1.5rem;
        transition: border-color 0.3s ease;
    }
    .items-card:hover { border-color: rgba(184, 148, 31, 0.35); }

    .items-card-head {
        padding: 1.5rem 1.75rem 1rem;
        border-bottom: 1px solid var(--border-light);
    }
    .items-card-head .eyebrow {
        font-size: 0.65rem; font-weight: 700; letter-spacing: 0.35em;
        text-transform: uppercase; color: var(--gold); margin-bottom: 0.35rem;
    }
    .items-card-head h3 {
        font-size: 1.55rem;
        margin: 0;
    }
    .items-card-head h3 em { color: var(--gold); font-style: italic; font-weight: 500; }

    .items-table { width: 100%; border-collapse: collapse; }
    .items-table th {
        padding: 0.9rem 1.75rem;
        text-align: left;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        color: var(--text-muted);
        background: var(--bg-soft);
        border-bottom: 1px solid var(--border);
    }
    .items-table th.right,
    .items-table td.right { text-align: right; }
    .items-table td {
        padding: 1.1rem 1.75rem;
        border-bottom: 1px solid var(--border-light);
        vertical-align: middle;
        font-size: 0.88rem;
    }
    .items-table tbody tr { transition: background 0.2s ease; }
    .items-table tbody tr:hover { background: var(--gold-soft); }

    .item-cell {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 220px;
    }
    .item-thumb {
        width: 64px; height: 64px;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid var(--border);
        background: var(--bg-soft);
        flex-shrink: 0;
    }
    .item-thumb img {
        width: 100%; height: 100%;
        object-fit: cover;
        transition: transform 0.35s ease;
        display: block;
    }
    .item-thumb:hover img { transform: scale(1.08); }

    .item-name {
        font-family: 'Cormorant Garamond', serif;
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--text-bright);
        line-height: 1.2;
    }
    .item-qty-chip {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 20px;
        background: var(--gold-soft);
        color: var(--gold-strong);
        border: 1px solid rgba(184, 148, 31, 0.25);
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        margin-top: 4px;
    }

    .price-cell {
        font-family: 'Cormorant Garamond', serif;
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-bright);
        white-space: nowrap;
    }
    .subtotal-cell {
        font-family: 'Cormorant Garamond', serif;
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--gold);
        white-space: nowrap;
    }

    .items-total-row td {
        padding: 1.5rem 1.75rem;
        background: var(--bg-soft);
        border-top: 1px solid var(--border);
        border-bottom: none;
    }
    .totals-block {
        max-width: 320px;
        margin-left: auto;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    .totals-line {
        display: flex;
        justify-content: space-between;
        font-size: 0.85rem;
        color: var(--text-muted);
    }
    .totals-line.grand {
        border-top: 1px dashed var(--border);
        margin-top: 0.35rem;
        padding-top: 0.75rem;
    }
    .totals-line.grand .label {
        font-family: 'Inter', sans-serif;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        font-size: 0.72rem;
        color: var(--text-bright);
    }
    .totals-line.grand .val {
        font-family: 'Cormorant Garamond', serif;
        font-size: 1.6rem;
        font-weight: 600;
        color: var(--gold);
    }

    /* ============================================================
       HELP CARD
       ============================================================ */
    .help-card {
        margin-top: 1.5rem;
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.28);
        border-radius: 14px;
        padding: 1.25rem 1.5rem;
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
    }
    .help-card .icon {
        width: 44px; height: 44px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
        box-shadow: 0 6px 16px -6px rgba(184, 148, 31, 0.7);
    }
    .help-card .text { flex: 1; min-width: 200px; }
    .help-card .text strong {
        display: block;
        font-family: 'Cormorant Garamond', serif;
        font-size: 1.2rem;
        color: var(--text-bright);
        font-weight: 600;
        margin-bottom: 2px;
    }
    .help-card .text span {
        font-size: 0.82rem;
        color: var(--text-muted);
    }

    /* ============================================================
       RESPONSIVE
       ============================================================ */
    @media (max-width: 768px) {
        .order-hero { padding: 3rem 1rem 2.5rem; }
        .order-container { padding: 2.5rem 0 3.5rem; }
        .stepper::before, .stepper .progress-fill { left: 20px; right: 20px; }
        .step-label { font-size: 0.6rem; }
        .step-circle { width: 38px; height: 38px; font-size: 0.8rem; }

        .items-table th, .items-table td { padding: 0.75rem 1rem; }
        .items-table th:nth-child(2),
        .items-table td:nth-child(2) { display: none; } /* hide unit price on mobile */
        .item-thumb { width: 52px; height: 52px; }
        .item-name { font-size: 1rem; }
        .totals-line.grand .val { font-size: 1.35rem; }
    }
    @media (max-width: 480px) {
        .order-hero-head { flex-direction: column; align-items: flex-start; }
        .step-sub { display: none; }
        .items-table th:nth-child(3),
        .items-table td:nth-child(3) { display: none; } /* hide qty on tiny screens */
    }
    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
</style>

<!-- ============================================================
     HERO
     ============================================================ -->
<section class="order-hero">
    <div class="container">
        <nav class="lux-breadcrumb">
            <a href="index.php">Home</a>
            <span class="sep">·</span>
            <a href="account.php">My Account</a>
            <span class="sep">·</span>
            <a href="account.php#orders">My Orders</a>
            <span class="sep">·</span>
            <span class="current">#<?php echo str_pad($order_id, 6, '0', STR_PAD_LEFT); ?></span>
        </nav>

        <div class="order-hero-head">
            <div>
                <h1>Order <em>Details</em></h1>
                <p>Everything about your order at a glance</p>
                <div class="order-id-chip">
                    <i class="fas fa-receipt"></i>
                    #<?php echo str_pad($order_id, 6, '0', STR_PAD_LEFT); ?>
                </div>
                <div class="hero-divider">
                    <span class="line"></span>
                    <span class="diamond"></span>
                    <span class="line"></span>
                </div>
            </div>

            <div class="hero-status-wrap">
                <span class="status-badge status-<?php echo htmlspecialchars($status); ?>">
                    <?php
                    $status_icon = 'fa-clock';
                    if ($status === 'processing') $status_icon = 'fa-cog';
                    elseif ($status === 'shipped')   $status_icon = 'fa-truck';
                    elseif ($status === 'delivered') $status_icon = 'fa-check-circle';
                    elseif ($status === 'cancelled') $status_icon = 'fa-times-circle';
                    ?>
                    <i class="fas <?php echo $status_icon; ?>"></i>
                    <?php echo htmlspecialchars($status_label); ?>
                </span>
                <a href="account.php#orders" class="btn-lux btn-lux-outline">
                    <i class="fas fa-arrow-left"></i> Back to Orders
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     ORDER CONTENT
     ============================================================ -->
<div class="order-container">
    <div class="container">

        <!-- Progress stepper -->
        <div class="progress-card">
            <div class="progress-head">
                <p class="eyebrow">Order Progress</p>
                <h3>Where is my <em>order</em>?</h3>
            </div>

            <?php if ($status === 'cancelled'): ?>
                <div class="cancelled-banner">
                    <i class="fas fa-times-circle"></i>
                    <div>
                        <strong>This order was cancelled.</strong><br>
                        If you believe this is a mistake, please contact our support team.
                    </div>
                </div>
            <?php else: ?>
                <?php
                /* Fill width: 0% / 33% / 66% / 100% */
                $fill_pct = [0, 33.33, 66.66, 100][max(0, $stepper_index)];
                ?>
                <div class="stepper">
                    <div class="progress-fill" style="width: calc((100% - 80px) * <?php echo $fill_pct / 100; ?>);"></div>

                    <div class="step <?php echo $stepper_index >= 0 ? 'done' : ''; ?>">
                        <div class="step-circle"><i class="fas fa-receipt"></i></div>
                        <div class="step-label">Order Placed</div>
                        <div class="step-sub">Confirmed</div>
                    </div>

                    <div class="step <?php echo $stepper_index >= 1 ? 'done' : ''; ?>">
                        <div class="step-circle"><i class="fas fa-cog"></i></div>
                        <div class="step-label">Processing</div>
                        <div class="step-sub">Preparing</div>
                    </div>

                    <div class="step <?php echo $stepper_index >= 2 ? 'done' : ''; ?>">
                        <div class="step-circle"><i class="fas fa-truck"></i></div>
                        <div class="step-label">Shipped</div>
                        <div class="step-sub">On the way</div>
                    </div>

                    <div class="step <?php echo $stepper_index >= 3 ? 'done' : ''; ?>">
                        <div class="step-circle"><i class="fas fa-check"></i></div>
                        <div class="step-label">Delivered</div>
                        <div class="step-sub">Complete</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Info cards -->
        <div class="row g-4">
            <!-- Order info -->
            <div class="col-lg-6">
                <div class="info-card">
                    <div class="info-card-head">
                        <div class="icon-circle"><i class="fas fa-receipt"></i></div>
                        <div class="title-text">Order <em>Information</em></div>
                    </div>

                    <div class="info-row">
                        <span class="key">Order Number</span>
                        <span class="val mono">#<?php echo str_pad($order_id, 6, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="key">Order Date</span>
                        <span class="val"><?php echo date('F d, Y · g:i A', strtotime($order['created_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="key">Status</span>
                        <span class="val">
                            <span class="status-badge status-<?php echo htmlspecialchars($status); ?>" style="padding:5px 12px; font-size:0.62rem;">
                                <i class="fas <?php echo $status_icon; ?>"></i> <?php echo htmlspecialchars($status_label); ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="key">Payment Method</span>
                        <span class="val"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $order['payment_method'] ?? 'N/A'))); ?></span>
                    </div>
                </div>
            </div>

            <!-- Shipping info -->
            <div class="col-lg-6">
                <div class="info-card">
                    <div class="info-card-head">
                        <div class="icon-circle"><i class="fas fa-truck"></i></div>
                        <div class="title-text">Shipping <em>Information</em></div>
                    </div>

                    <div class="info-row">
                        <span class="key">Recipient</span>
                        <span class="val"><?php echo htmlspecialchars($order['full_name'] ?? ''); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="key">Email</span>
                        <span class="val" style="font-size:0.82rem;"><?php echo htmlspecialchars($order['email'] ?? ''); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="key">Phone</span>
                        <span class="val"><?php echo htmlspecialchars($order['phone'] ?? '—'); ?></span>
                    </div>

                    <div class="address-block mt-3">
                        <div class="label">Delivery Address</div>
                        <?php echo nl2br(htmlspecialchars($ship_address ?: 'No address provided.')); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order items -->
        <div class="items-card">
            <div class="items-card-head">
                <p class="eyebrow">Your Pieces</p>
                <h3>Order <em>Items</em></h3>
            </div>

            <div class="table-responsive">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Unit Price</th>
                            <th>Qty</th>
                            <th class="right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($order_items)): ?>
                            <tr>
                                <td colspan="4" style="text-align:center; color:var(--text-muted); padding:2rem;">
                                    <i class="fas fa-box-open" style="font-size:1.5rem; color:var(--gold); display:block; margin-bottom:0.5rem;"></i>
                                    No items recorded for this order.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($order_items as $item): ?>
                            <tr>
                                <td>
                                    <div class="item-cell">
                                        <div class="item-thumb">
                                            <img src="../uploads/<?php echo htmlspecialchars($item['image'] ?? 'placeholder.jpg'); ?>"
                                                 onerror="this.onerror=null;this.src='https://via.placeholder.com/100x100/ede7db/8a8172?text=J'"
                                                 alt="<?php echo htmlspecialchars($item['name']); ?>"
                                                 loading="lazy">
                                        </div>
                                        <div>
                                            <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                            <span class="item-qty-chip">Qty <?php echo (int)$item['quantity']; ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="price-cell">₱<?php echo number_format((float)$item['price'], 2); ?></td>
                                <td><?php echo (int)$item['quantity']; ?></td>
                                <td class="right subtotal-cell">
                                    ₱<?php echo number_format((float)$item['price'] * (int)$item['quantity'], 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                    <?php if (!empty($order_items)): ?>
                    <tfoot>
                        <tr class="items-total-row">
                            <td colspan="4">
                                <div class="totals-block">
                                    <div class="totals-line">
                                        <span class="label">Subtotal</span>
                                        <span>₱<?php echo number_format($stored_subtotal, 2); ?></span>
                                    </div>
                                    <div class="totals-line">
                                        <span class="label">Shipping &amp; Tax</span>
                                        <span>₱<?php echo number_format(max(0, $order_total - $stored_subtotal), 2); ?></span>
                                    </div>
                                    <div class="totals-line grand">
                                        <span class="label">Total</span>
                                        <span class="val">₱<?php echo number_format($order_total, 2); ?></span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Help card -->
        <div class="help-card">
            <div class="icon"><i class="fas fa-headset"></i></div>
            <div class="text">
                <strong>Need help with this order?</strong>
                <span>Our concierge team is available 7 days a week to assist you.</span>
            </div>
            <a href="shop.php" class="btn-lux btn-lux-outline-gold">
                <i class="fas fa-shopping-bag"></i> Continue Shopping
            </a>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>