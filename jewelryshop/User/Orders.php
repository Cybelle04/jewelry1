<?php
// user/orders.php — Luxury Order History Page
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
$resolved_theme = in_array($theme, ['cream', 'light', 'dark'], true) ? $theme : 'cream';
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) $accent = '#5b5fd4';

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

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    header("Location: login.php");
    exit();
}

/* ------------------------------------------------------------------
   3. Safe query helpers
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

function safe_exec($db, $sql, $params = []) {
    if (!$db) return false;
    try {
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

/* ------------------------------------------------------------------
   4. Handle POST — cancel order (server-side, same file)
   ------------------------------------------------------------------ */
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order']) && $db) {
    $cancel_id = (int)($_POST['order_id'] ?? 0);
    if ($cancel_id > 0) {
        /* Only allow cancelling own pending orders */
        $ok = safe_exec($db,
            "UPDATE orders SET status = 'cancelled'
             WHERE id = :oid AND user_id = :uid AND status = 'pending'",
            [':oid' => $cancel_id, ':uid' => $user_id]);
        $notice = $ok ? 'cancelled' : 'cancel_failed';
    }
    /* Redirect to avoid resubmission on refresh */
    $redirect_qs = http_build_query(array_filter([
        'status' => $_GET['status'] ?? '',
        'year'   => (int)($_GET['year'] ?? 0) ?: '',
        'search' => $_GET['search'] ?? '',
        'notice' => $notice,
    ], function ($v) { return $v !== '' && $v !== null; }));
    header("Location: orders.php" . ($redirect_qs ? "?$redirect_qs" : ''));
    exit();
}

/* ------------------------------------------------------------------
   5. Filters
   ------------------------------------------------------------------ */
$status_filter = $_GET['status'] ?? 'all';
$allowed_statuses = ['all', 'pending', 'processing', 'shipped', 'delivered', 'cancelled'];
if (!in_array($status_filter, $allowed_statuses, true)) $status_filter = 'all';

$year_filter = (int)($_GET['year'] ?? 0);
$search      = trim($_GET['search'] ?? '');

/* ------------------------------------------------------------------
   6. Build query
   ------------------------------------------------------------------ */
$where  = ["user_id = :uid"];
$params = [':uid' => $user_id];

if ($status_filter !== 'all') {
    $where[] = "status = :status";
    $params[':status'] = $status_filter;
}

if ($year_filter > 0) {
    $where[] = "YEAR(created_at) = :year";
    $params[':year'] = $year_filter;
}

if ($search !== '') {
    /* Match order id (as number) OR total_amount */
    $where[] = "(id = :search_id OR total_amount LIKE :search_amt)";
    $params[':search_id']  = ctype_digit($search) ? (int)$search : 0;
    $params[':search_amt'] = '%' . $search . '%';
}

$where_sql = "WHERE " . implode(" AND ", $where);

$orders = safe_query($db,
    "SELECT * FROM orders $where_sql ORDER BY created_at DESC",
    $params);

/* ------------------------------------------------------------------
   7. Stats
   ------------------------------------------------------------------ */
$stats_rows = safe_query($db, "
    SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status = 'pending'    THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing,
        SUM(CASE WHEN status = 'shipped'    THEN 1 ELSE 0 END) AS shipped,
        SUM(CASE WHEN status = 'delivered'  THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN status = 'cancelled'  THEN 1 ELSE 0 END) AS cancelled,
        SUM(total_amount) AS total_spent
    FROM orders WHERE user_id = :uid
", [':uid' => $user_id]);

$stats = array_merge([
    'total_orders' => 0, 'pending' => 0, 'processing' => 0,
    'shipped' => 0, 'delivered' => 0, 'cancelled' => 0, 'total_spent' => 0
], $stats_rows[0] ?? []);

/* ------------------------------------------------------------------
   8. Available years
   ------------------------------------------------------------------ */
$years = safe_query($db,
    "SELECT DISTINCT YEAR(created_at) AS year
     FROM orders WHERE user_id = :uid
     ORDER BY year DESC",
    [':uid' => $user_id]);

/* ------------------------------------------------------------------
   9. Fetch item previews for all listed orders (one query, efficient)
   ------------------------------------------------------------------ */
$order_ids = array_map(function ($o) { return (int)$o['id']; }, $orders);
$previews  = [];
$total_items_map = [];

if (!empty($order_ids)) {
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $items_rows = safe_query($db, "
        SELECT oi.order_id, oi.quantity, oi.price, p.name, p.image, oi.product_id
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.id ASC
    ", $order_ids);

    foreach ($items_rows as $it) {
        $oid = (int)$it['order_id'];
        if (!isset($previews[$oid])) $previews[$oid] = [];
        if (count($previews[$oid]) < 3) {
            $previews[$oid][] = $it;
        }
        $total_items_map[$oid] = ($total_items_map[$oid] ?? 0) + (int)$it['quantity'];
    }
}

$page_title = 'My Orders | R-Jewel&Gold';
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
    .hero-head h1, .order-num, .stat-card .stat-number,
    .meta-item .meta-value, .empty-state h3,
    .preview-item.more, .t-labels {
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
    .orders-hero {
        position: relative;
        background: var(--bg);
        background-image:
            radial-gradient(ellipse at 20% 20%, rgba(184, 148, 31, 0.14), transparent 55%),
            radial-gradient(ellipse at 80% 80%, rgba(91, 95, 212, 0.08), transparent 55%);
        overflow: hidden;
        padding: 4rem 1.5rem 5rem;
        border-bottom: 1px solid var(--border-light);
    }
    .orders-hero::before {
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
    .orders-hero .container { position: relative; z-index: 2; }

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

    .hero-head {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
    }
    .hero-head h1 {
        font-size: clamp(2rem, 4.5vw, 3rem);
        margin: 0 0 0.35rem;
        line-height: 1.05;
    }
    .hero-head h1 em { color: var(--gold); font-style: italic; font-weight: 500; }
    .hero-head p {
        margin: 0;
        color: var(--text-muted);
        font-size: 0.92rem;
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
    .hero-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    /* ============================================================
       BUTTONS
       ============================================================ */
    .btn-lux {
        display: inline-flex;
        align-items: center;
        justify-content: center;
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
    .btn-lux-outline-danger {
        background: transparent;
        color: var(--danger);
        border: 1px solid rgba(220, 38, 38, 0.35);
    }
    .btn-lux-outline-danger:hover { background: var(--danger); color: #fff; border-color: var(--danger); }

    /* ============================================================
       STATS CARDS — pulled up over hero
       ============================================================ */
    .orders-container { padding: 0 0 5rem; margin-top: -3rem; position: relative; z-index: 5; }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 2rem;
    }
    .stat-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 1.5rem 1rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.35s ease;
        position: relative;
        overflow: hidden;
    }
    .stat-card::after {
        content: '';
        position: absolute;
        left: 0; right: 0; bottom: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--gold), var(--gold-strong));
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.35s ease;
    }
    .stat-card:hover {
        transform: translateY(-6px);
        border-color: var(--gold);
        box-shadow: 0 18px 34px -18px rgba(90, 82, 69, 0.25);
    }
    .stat-card:hover::after { transform: scaleX(1); }
    .stat-card.active { border-color: var(--gold); background: var(--gold-soft); }
    .stat-card.active::after { transform: scaleX(1); }

    .stat-icon {
        width: 52px; height: 52px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 0.75rem;
        font-size: 1.1rem;
        color: var(--gold);
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.25);
        transition: transform 0.3s ease;
    }
    .stat-card:hover .stat-icon { transform: scale(1.1) rotate(-4deg); }
    .stat-card .stat-number {
        font-size: 2rem;
        font-weight: 600;
        color: var(--text-bright);
        line-height: 1;
        margin-bottom: 0.35rem;
    }
    .stat-card .stat-label {
        font-size: 0.65rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.2em;
        font-weight: 600;
    }

    /* ============================================================
       FILTER BAR
       ============================================================ */
    .filter-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }
    .filter-row {
        display: grid;
        grid-template-columns: 1fr 1fr 2fr auto auto;
        gap: 12px;
        align-items: center;
    }
    .form-lux {
        width: 100%;
        padding: 0.7rem 1rem;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--bg-soft);
        color: var(--text-bright);
        font-family: 'Inter', sans-serif;
        font-size: 0.85rem;
        transition: all 0.25s ease;
        height: 42px;
    }
    .form-lux:focus {
        outline: none;
        border-color: var(--gold);
        background: var(--card);
        box-shadow: 0 0 0 3px rgba(184, 148, 31, 0.12);
    }
    .form-lux::placeholder { color: var(--text-dim); }

    /* ============================================================
       ORDER CARDS
       ============================================================ */
    .orders-list {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }
    .order-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        overflow: hidden;
        transition: all 0.35s ease;
    }
    .order-card:hover {
        border-color: var(--gold);
        box-shadow: 0 20px 40px -18px rgba(90, 82, 69, 0.22);
        transform: translateY(-4px);
    }

    .order-head {
        padding: 1.1rem 1.5rem;
        background: var(--bg-soft);
        border-bottom: 1px solid var(--border-light);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    .order-num {
        font-size: 1.35rem;
        font-weight: 600;
        color: var(--text-bright);
        margin-right: 0.75rem;
    }
    .order-num em {
        color: var(--gold);
        font-style: italic;
        font-weight: 500;
        font-size: 0.75em;
        letter-spacing: 0.05em;
        margin-right: 4px;
    }
    .order-date {
        font-size: 0.78rem;
        color: var(--text-muted);
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .order-date i { color: var(--gold); }

    .badge-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
    }
    .badge-pending    { background: rgba(184, 148, 31, 0.15); color: var(--gold-strong); border: 1px solid rgba(184, 148, 31, 0.3); }
    .badge-processing { background: rgba(91, 95, 212, 0.12);  color: #5b5fd4; border: 1px solid rgba(91, 95, 212, 0.3); }
    .badge-shipped    { background: rgba(13, 150, 104, 0.12); color: #0d9668; border: 1px solid rgba(13, 150, 104, 0.3); }
    .badge-delivered  { background: rgba(13, 150, 104, 0.18); color: #0a7d56; border: 1px solid rgba(13, 150, 104, 0.4); }
    .badge-cancelled  { background: rgba(220, 38, 38, 0.12);  color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.3); }

    .order-body {
        padding: 1.25rem 1.5rem;
    }
    .order-body-grid {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 1.5rem;
        align-items: center;
    }

    /* Item preview */
    .items-preview {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .preview-item {
        position: relative;
        width: 64px;
        height: 64px;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid var(--border);
        background: var(--bg-soft);
        flex-shrink: 0;
        transition: transform 0.3s ease;
    }
    .preview-item:hover { transform: translateY(-3px) scale(1.04); }
    .preview-item img {
        width: 100%; height: 100%;
        object-fit: cover;
        display: block;
    }
    .preview-item.more {
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--gold-soft);
        border-color: rgba(184, 148, 31, 0.3);
        color: var(--gold-strong);
        font-size: 1.1rem;
        font-weight: 600;
    }

    /* Meta */
    .order-meta-grid {
        display: flex;
        gap: 1.5rem;
        align-items: center;
        flex-wrap: wrap;
    }
    .meta-item .meta-label {
        font-size: 0.6rem;
        font-weight: 700;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 0.2rem;
    }
    .meta-item .meta-value {
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--text-bright);
    }
    .meta-item .meta-value.total {
        color: var(--gold);
        font-size: 1.35rem;
    }

    /* Actions */
    .order-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .order-actions .btn-lux { padding: 0.55rem 1rem; font-size: 0.62rem; letter-spacing: 0.15em; }

    /* Timeline mini */
    .timeline-mini {
        display: flex;
        align-items: center;
        gap: 0;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px dashed var(--border-light);
    }
    .t-dot {
        width: 10px; height: 10px;
        border-radius: 50%;
        background: var(--border);
        flex-shrink: 0;
        transition: all 0.3s ease;
    }
    .t-dot.done {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        box-shadow: 0 0 8px rgba(184, 148, 31, 0.55);
    }
    .t-dot.current {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        box-shadow: 0 0 8px rgba(184, 148, 31, 0.55), 0 0 0 4px rgba(184, 148, 31, 0.18);
        animation: tPulse 1.6s ease-in-out infinite;
    }
    @keyframes tPulse {
        0%, 100% { transform: scale(1); }
        50%      { transform: scale(1.25); }
    }
    .t-line {
        flex: 1;
        height: 2px;
        background: var(--border);
        position: relative;
        overflow: hidden;
    }
    .t-line.done::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, var(--gold), var(--gold-strong));
        border-radius: 2px;
    }
    .t-labels {
        display: flex;
        justify-content: space-between;
        margin-top: 0.5rem;
        font-size: 0.62rem;
        font-weight: 600;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--text-dim);
    }
    .t-labels span.done { color: var(--gold); }

    /* ============================================================
       EMPTY STATE
       ============================================================ */
    .empty-state {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        text-align: center;
        padding: 4rem 2rem;
    }
    .empty-state .icon-wrap {
        width: 100px; height: 100px;
        border-radius: 50%;
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.25);
        color: var(--gold);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.25rem;
        font-size: 2rem;
    }
    .empty-state h3 {
        font-size: 1.9rem;
        margin-bottom: 0.5rem;
    }
    .empty-state h3 em { color: var(--gold); font-style: italic; font-weight: 500; }
    .empty-state p { color: var(--text-muted); margin-bottom: 1.5rem; font-size: 0.95rem; }

    /* ============================================================
       NOTICE (cancelled etc.)
       ============================================================ */
    .notice {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 0.9rem 1.2rem;
        border-radius: 12px;
        margin-bottom: 1.25rem;
        font-size: 0.88rem;
        border: 1px solid;
    }
    .notice.success { background: rgba(13, 150, 104, 0.08); color: var(--success); border-color: rgba(13, 150, 104, 0.25); }
    .notice.error   { background: rgba(220, 38, 38, 0.06); color: var(--danger); border-color: rgba(220, 38, 38, 0.25); }

    /* ============================================================
       TOAST
       ============================================================ */
    .toast-notification {
        position: fixed;
        bottom: 24px; right: 24px;
        z-index: 9999;
        animation: slideInRight 0.35s ease-out;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        background: var(--card);
        border: 1px solid var(--border);
        min-width: 260px;
    }
    .toast-notification .toast-body {
        padding: 0.9rem 1.1rem;
        font-size: 0.85rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .toast-notification.success .toast-body { color: var(--success); }
    .toast-notification.error   .toast-body { color: var(--danger); }
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to   { transform: translateX(0); opacity: 1; }
    }

    /* ============================================================
       RESPONSIVE
       ============================================================ */
    @media (max-width: 991px) {
        .stats-grid { grid-template-columns: repeat(4, 1fr); }
        .filter-row { grid-template-columns: 1fr 1fr; }
        .filter-row .search-cell { grid-column: span 2; }
        .filter-row .btn-cell { grid-column: span 2; display: flex; gap: 8px; }
        .order-body-grid { grid-template-columns: 1fr; gap: 1rem; }
        .order-actions { justify-content: flex-start; }
    }
    @media (max-width: 768px) {
        .orders-hero { padding: 3rem 1rem 4rem; }
        .orders-container { padding: 0 0 3.5rem; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .stat-card .stat-number { font-size: 1.6rem; }
        .order-meta-grid { gap: 1rem; }
        .order-body { padding: 1.1rem 1.15rem; }
        .order-head { padding: 1rem 1.15rem; }
    }
    @media (max-width: 480px) {
        .stats-grid { gap: 10px; }
        .stat-card { padding: 1.1rem 0.75rem; }
        .stat-icon { width: 42px; height: 42px; font-size: 0.9rem; }
        .stat-card .stat-number { font-size: 1.35rem; }
        .stat-card .stat-label { font-size: 0.55rem; letter-spacing: 0.15em; }
        .hero-actions { width: 100%; }
        .hero-actions .btn-lux { flex: 1; }
        .preview-item { width: 54px; height: 54px; }
        .order-actions .btn-lux { flex: 1; }
    }
    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
</style>

<!-- ============================================================
     HERO
     ============================================================ -->
<section class="orders-hero">
    <div class="container">
        <nav class="lux-breadcrumb">
            <a href="index.php">Home</a>
            <span class="sep">·</span>
            <a href="account.php">My Account</a>
            <span class="sep">·</span>
            <span class="current">My Orders</span>
        </nav>

        <div class="hero-head">
            <div>
                <h1>My <em>Orders</em></h1>
                <p>Track and manage all your purchases in one place</p>
                <div class="hero-divider">
                    <span class="line"></span>
                    <span class="diamond"></span>
                    <span class="line"></span>
                </div>
            </div>
            <div class="hero-actions">
                <a href="account.php" class="btn-lux btn-lux-outline">
                    <i class="fas fa-arrow-left"></i> Account
                </a>
                <a href="shop.php" class="btn-lux btn-lux-gold">
                    <i class="fas fa-shopping-bag"></i> Shop Collection
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     CONTENT
     ============================================================ -->
<div class="orders-container">
    <div class="container">

        <!-- Notice after redirect -->
        <?php if (!empty($_GET['notice'])): ?>
            <?php if ($_GET['notice'] === 'cancelled'): ?>
                <div class="notice success">
                    <i class="fas fa-check-circle"></i> Order cancelled successfully.
                </div>
            <?php elseif ($_GET['notice'] === 'cancel_failed'): ?>
                <div class="notice error">
                    <i class="fas fa-exclamation-circle"></i> We couldn't cancel that order. Only pending orders can be cancelled.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>" onclick="filterOrders('all')">
                <div class="stat-icon"><i class="fas fa-shopping-bag"></i></div>
                <div class="stat-number"><?php echo (int)$stats['total_orders']; ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card <?php echo $status_filter === 'delivered' ? 'active' : ''; ?>" onclick="filterOrders('delivered')">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo (int)$stats['delivered']; ?></div>
                <div class="stat-label">Delivered</div>
            </div>
            <div class="stat-card <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" onclick="filterOrders('pending')">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?php echo (int)$stats['pending']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card <?php echo $status_filter === 'processing' ? 'active' : ''; ?>" onclick="filterOrders('processing')">
                <div class="stat-icon"><i class="fas fa-cog"></i></div>
                <div class="stat-number"><?php echo (int)$stats['processing']; ?></div>
                <div class="stat-label">Processing</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="filter-row">
                <select name="status" class="form-lux" onchange="this.form.submit()">
                    <option value="all"        <?php echo $status_filter === 'all'        ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="pending"    <?php echo $status_filter === 'pending'    ? 'selected' : ''; ?>>Pending</option>
                    <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                    <option value="shipped"    <?php echo $status_filter === 'shipped'    ? 'selected' : ''; ?>>Shipped</option>
                    <option value="delivered"  <?php echo $status_filter === 'delivered'  ? 'selected' : ''; ?>>Delivered</option>
                    <option value="cancelled"  <?php echo $status_filter === 'cancelled'  ? 'selected' : ''; ?>>Cancelled</option>
                </select>

                <select name="year" class="form-lux" onchange="this.form.submit()">
                    <option value="0">All Years</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo (int)$y['year']; ?>" <?php echo $year_filter === (int)$y['year'] ? 'selected' : ''; ?>>
                            <?php echo (int)$y['year']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="search-cell">
                    <input type="text" name="search" class="form-lux"
                           placeholder="Search by order # or amount…"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="btn-cell" style="display:flex;gap:8px;">
                    <button type="submit" class="btn-lux btn-lux-gold" style="height:42px;">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <?php if ($status_filter !== 'all' || $year_filter > 0 || $search !== ''): ?>
                        <a href="orders.php" class="btn-lux btn-lux-outline" style="height:42px;">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Orders -->
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <div class="icon-wrap"><i class="fas fa-box-open"></i></div>
                <h3>No <em>orders</em> found</h3>
                <p>
                    <?php if ($status_filter !== 'all' || $year_filter > 0 || $search !== ''): ?>
                        Try adjusting your filters or search.
                    <?php else: ?>
                        You haven't placed any orders yet. Start your collection today.
                    <?php endif; ?>
                </p>
                <a href="shop.php" class="btn-lux btn-lux-gold">
                    <i class="fas fa-shopping-bag"></i> Start Shopping
                </a>
            </div>
        <?php else: ?>
            <div class="orders-list">
                <?php
                /* Map for the mini timeline */
                $status_order = ['pending', 'processing', 'shipped', 'delivered'];
                ?>
                <?php foreach ($orders as $order):
                    $oid           = (int)$order['id'];
                    $ostatus       = strtolower($order['status'] ?? 'pending');
                    $is_cancelled  = ($ostatus === 'cancelled');
                    $current_index = array_search($ostatus, $status_order, true);
                    if ($current_index === false) $current_index = -1;

                    $order_preview = $previews[$oid] ?? [];
                    $total_items   = $total_items_map[$oid] ?? 0;
                    $has_more      = $total_items > count($order_preview);
                ?>
                <div class="order-card">
                    <!-- Head -->
                    <div class="order-head">
                        <div>
                            <span class="order-num"><em>Order</em> #<?php echo str_pad($oid, 6, '0', STR_PAD_LEFT); ?></span>
                            <span class="order-date">
                                <i class="far fa-calendar-alt"></i>
                                <?php echo date('M d, Y', strtotime($order['created_at'] ?? 'now')); ?>
                            </span>
                        </div>
                        <span class="badge-status badge-<?php echo htmlspecialchars($ostatus); ?>">
                            <i class="fas <?php
                                echo $ostatus === 'pending'    ? 'fa-clock' :
                                    ($ostatus === 'processing' ? 'fa-cog' :
                                    ($ostatus === 'shipped'    ? 'fa-truck' :
                                    ($ostatus === 'delivered'  ? 'fa-check-circle' : 'fa-times-circle')));
                            ?>"></i>
                            <?php echo ucfirst(htmlspecialchars($ostatus)); ?>
                        </span>
                    </div>

                    <!-- Body -->
                    <div class="order-body">
                        <div class="order-body-grid">

                            <!-- Item previews -->
                            <div class="items-preview">
                                <?php if (empty($order_preview)): ?>
                                    <div class="preview-item more" style="width:100px; border-radius:10px; font-size:0.75rem;">No items</div>
                                <?php else: ?>
                                    <?php foreach ($order_preview as $it): ?>
                                        <div class="preview-item">
                                            <img src="../uploads/<?php echo htmlspecialchars($it['image'] ?? 'placeholder.jpg'); ?>"
                                                 onerror="this.onerror=null;this.src='https://via.placeholder.com/100x100/ede7db/8a8172?text=J'"
                                                 alt="<?php echo htmlspecialchars($it['name'] ?? 'Product'); ?>"
                                                 loading="lazy">
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if ($has_more): ?>
                                        <div class="preview-item more">+<?php echo $total_items - count($order_preview); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Meta -->
                            <div class="order-meta-grid">
                                <div class="meta-item">
                                    <div class="meta-label">Total</div>
                                    <div class="meta-value total">₱<?php echo number_format((float)($order['total_amount'] ?? 0), 2); ?></div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Items</div>
                                    <div class="meta-value"><?php echo (int)$total_items; ?></div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Payment</div>
                                    <div class="meta-value" style="font-size:0.95rem;"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $order['payment_method'] ?? 'N/A'))); ?></div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="order-actions">
                                <a href="order-details.php?id=<?php echo $oid; ?>" class="btn-lux btn-lux-gold">
                                    <i class="fas fa-eye"></i> View
                                </a>

                                <?php if ($ostatus === 'pending'): ?>
                                    <button type="button" class="btn-lux btn-lux-outline-danger"
                                            onclick="cancelOrder(<?php echo $oid; ?>)">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                <?php elseif ($ostatus === 'delivered' && !empty($order_preview[0]['product_id'])): ?>
                                    <a href="product-details.php?id=<?php echo (int)$order_preview[0]['product_id']; ?>"
                                       class="btn-lux btn-lux-outline-gold">
                                        <i class="fas fa-redo"></i> Reorder
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Mini timeline -->
                        <?php if (!$is_cancelled): ?>
                            <div class="timeline-mini">
                                <?php foreach ($status_order as $i => $st): ?>
                                    <?php if ($i > 0): ?>
                                        <div class="t-line <?php echo $i <= $current_index ? 'done' : ''; ?>"></div>
                                    <?php endif; ?>
                                    <div class="t-dot <?php
                                        if ($i < $current_index)       echo 'done';
                                        elseif ($i === $current_index) echo 'done current';
                                    ?>"></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="t-labels">
                                <?php foreach ($status_order as $i => $st): ?>
                                    <span class="<?php echo $i <= $current_index ? 'done' : ''; ?>">
                                        <?php echo ucfirst($st); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- Hidden form used by cancelOrder() -->
<form id="cancelForm" method="POST" style="display:none;">
    <input type="hidden" name="cancel_order" value="1">
    <input type="hidden" name="order_id" id="cancelOrderId" value="">
</form>

<script>
(function () {
    'use strict';

    /* Filter by clicking a stat card */
    window.filterOrders = function (status) {
        var url = new URL(window.location.href);
        url.searchParams.set('status', status);
        /* Keep year/search? Clear them when clicking a stat card */
        url.searchParams.delete('year');
        url.searchParams.delete('search');
        window.location.href = url.toString();
    };

    /* Cancel order — posts to this same page (no missing endpoint) */
    window.cancelOrder = function (orderId) {
        if (!confirm('Are you sure you want to cancel this order? This cannot be undone.')) return;
        document.getElementById('cancelOrderId').value = orderId;
        document.getElementById('cancelForm').submit();
    };

    /* Toast helper — kept for compatibility, e.g. if you add AJAX later */
    window.showNotification = function (message, type) {
        type = type || 'success';
        var icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        var el = document.createElement('div');
        el.className = 'toast-notification ' + type;
        var body = document.createElement('div');
        body.className = 'toast-body';
        var i = document.createElement('i');
        i.className = 'fas ' + icon;
        var span = document.createElement('span');
        span.textContent = message;
        body.appendChild(i); body.appendChild(span);
        el.appendChild(body);
        document.body.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s ease';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 300);
        }, 3000);
    };
})();
</script>

<?php include '../includes/footer.php'; ?>