<?php
// user/account.php — Luxury Account Dashboard with functional wishlist
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$user = $auth->getCurrentUser();
if (!is_array($user) || empty($user)) {
    header("Location: logout.php");
    exit();
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    header("Location: logout.php");
    exit();
}

$theme = 'cream';
$accent = '#5b5fd4';
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
function safe_one($db, $sql, $params = []) {
    $rows = safe_query($db, $sql, $params);
    return $rows[0] ?? null;
}

function compact_money($amount) {
    $amount = (float)$amount;
    if ($amount >= 1000000) {
        return '₱' . rtrim(rtrim(number_format($amount / 1000000, 2, '.', ''), '0'), '.') . 'M';
    }
    if ($amount >= 1000) {
        return '₱' . rtrim(rtrim(number_format($amount / 1000, 1, '.', ''), '0'), '.') . 'K';
    }
    return '₱' . number_format($amount, 0);
}

$layaway_action = $_POST['layaway_action'] ?? '';
if ($layaway_action !== '') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Unknown action'];

    try {
        switch ($layaway_action) {
            case 'submit_payment':
                $planId    = (int)($_POST['plan_id'] ?? 0);
                $amount    = (float)($_POST['amount'] ?? 0);
                $method    = trim($_POST['payment_method'] ?? '');
                $reference = trim($_POST['reference_no'] ?? '');
                $notes     = trim($_POST['notes'] ?? '');

                if ($planId <= 0 || $amount <= 0) throw new Exception('Invalid payment details.');
                if ($method === '') throw new Exception('Please choose a payment method.');

                $stmt = $db->prepare("SELECT id, balance, status FROM layaway_plans WHERE id = ? AND user_id = ?");
                $stmt->execute([$planId, $uid]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan) throw new Exception('Layaway plan not found.');
                if (!in_array($plan['status'], ['active','approved','overdue'], true)) {
                    throw new Exception('This plan is not currently accepting payments.');
                }
                if ($amount > (float)$plan['balance'] + 0.01) {
                    throw new Exception('Amount exceeds the remaining balance of ₱' . number_format((float)$plan['balance'], 2));
                }

                $proofImage = null;
                if (!empty($_FILES['proof_image']['name']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
                    $allowed = ['jpg','jpeg','png','webp','gif'];
                    $ext = strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed, true)) throw new Exception('Proof image must be JPG, PNG, WEBP, or GIF.');
                    if ($_FILES['proof_image']['size'] > 5 * 1024 * 1024) throw new Exception('Proof image must be under 5MB.');

                    $uploadDir = __DIR__ . '/../uploads/layaway_proofs/';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
                    $filename = 'proof_' . $planId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($_FILES['proof_image']['tmp_name'], $uploadDir . $filename)) {
                        throw new Exception('Failed to save proof image.');
                    }
                    $proofImage = 'uploads/layaway_proofs/' . $filename;
                } else {
                    throw new Exception('Please upload your proof of payment.');
                }

                $stmt = $db->prepare("INSERT INTO layaway_payments 
                    (plan_id, amount, payment_method, reference_no, proof_image, status, notes)
                    VALUES (?, ?, ?, ?, ?, 'pending', ?)");
                $stmt->execute([$planId, $amount, $method, $reference, $proofImage, $notes]);

                $response = [
                    'success' => true,
                    'message' => 'Payment submitted! Admin will verify it shortly.',
                    'id' => $db->lastInsertId()
                ];
                break;

            case 'apply_layaway':
                $productId   = (int)($_POST['product_id'] ?? 0);
                $downPayment = (float)($_POST['down_payment'] ?? 0);
                $periodDays  = (int)($_POST['period_days'] ?? 60);
                $notes       = trim($_POST['notes'] ?? '');

                if ($productId <= 0) throw new Exception('Please select a product.');

                $stmt = $db->prepare("SELECT id, name, price, stock FROM products WHERE id = ?");
                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) throw new Exception('Product not found.');

                $settings = $db->query("SELECT * FROM layaway_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
                $minPct = (float)($settings['min_down_payment_percent'] ?? 20);
                $total  = (float)$product['price'];
                $minDown = round($total * ($minPct / 100), 2);

                if ($downPayment < $minDown) {
                    throw new Exception("Minimum down payment is ₱" . number_format($minDown, 2) . " ({$minPct}%).");
                }
                if ($downPayment >= $total) {
                    throw new Exception('Down payment cannot equal or exceed the total amount.');
                }

                $balance = $total - $downPayment;

                $check = $db->prepare("SELECT id FROM layaway_plans 
                                       WHERE user_id = ? AND product_id = ? 
                                       AND status IN ('pending','active','approved','overdue') LIMIT 1");
                $check->execute([$uid, $productId]);
                if ($check->fetch()) {
                    throw new Exception('You already have an active layaway for this item.');
                }

                $stmt = $db->prepare("INSERT INTO layaway_plans 
                    (user_id, product_id, total_amount, down_payment, balance, period_days, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
                $stmt->execute([$uid, $productId, $total, $downPayment, $balance, $periodDays, $notes]);

                $response = [
                    'success' => true,
                    'message' => 'Layaway application submitted! Awaiting admin approval.',
                    'id' => $db->lastInsertId()
                ];
                break;

            case 'cancel_my_plan':
                $planId = (int)($_POST['plan_id'] ?? 0);
                $stmt = $db->prepare("SELECT id, status FROM layaway_plans WHERE id = ? AND user_id = ?");
                $stmt->execute([$planId, $uid]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan) throw new Exception('Plan not found.');
                if ($plan['status'] !== 'pending') {
                    throw new Exception('Only pending applications can be cancelled.');
                }
                $db->prepare("UPDATE layaway_plans SET status='cancelled' WHERE id=?")->execute([$planId]);
                $response = ['success' => true, 'message' => 'Layaway application cancelled.'];
                break;

            default:
                $response = ['success' => false, 'message' => 'Action not implemented: ' . $layaway_action];
        }
    } catch (Throwable $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

$order_stats = safe_one($db, "
    SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) AS pending_orders,
        SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) AS processing_orders,
        SUM(CASE WHEN status='shipped'    THEN 1 ELSE 0 END) AS shipped_orders,
        SUM(CASE WHEN status='delivered'  THEN 1 ELSE 0 END) AS delivered_orders,
        SUM(CASE WHEN status='cancelled'  THEN 1 ELSE 0 END) AS cancelled_orders,
        COALESCE(SUM(total_amount), 0) AS total_spent
    FROM orders WHERE user_id = :uid
", [':uid' => $uid]);

if (!is_array($order_stats)) $order_stats = [];
$order_stats = array_merge([
    'total_orders'      => 0,
    'pending_orders'    => 0,
    'processing_orders' => 0,
    'shipped_orders'    => 0,
    'delivered_orders'  => 0,
    'cancelled_orders'  => 0,
    'total_spent'       => 0,
], $order_stats);

$recent_orders = safe_query($db, "
    SELECT * FROM orders WHERE user_id = :uid
    ORDER BY created_at DESC LIMIT 5
", [':uid' => $uid]);

$cart_row = safe_one($db, "SELECT COALESCE(SUM(quantity),0) AS total FROM cart WHERE user_id = :uid", [':uid' => $uid]);
$cart_count = (int)($cart_row['total'] ?? 0);
$total_spent = (float)($order_stats['total_spent'] ?? 0);

$stmt = $db->prepare("
    SELECT lp.*, p.name AS product_name, p.price AS product_price, p.image AS product_image
    FROM layaway_plans lp
    LEFT JOIN products p ON p.id = lp.product_id
    WHERE lp.user_id = ? AND lp.status IN ('active','approved','pending','overdue')
    ORDER BY lp.due_date ASC, lp.created_at DESC
");
$stmt->execute([$uid]);
$layaway_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$layaway_stats = [
    'active_plans'  => 0,
    'pending_plans' => 0,
    'total_balance' => 0,
    'total_paid'    => 0,
];
foreach ($layaway_plans as $plan) {
    $planTotal = (float)$plan['total_amount'];
    $planPaid  = $planTotal - (float)$plan['balance'];
    $layaway_stats['total_paid'] += $planPaid;

    if (in_array($plan['status'], ['active','approved','overdue'], true)) {
        $layaway_stats['active_plans']++;
        $layaway_stats['total_balance'] += (float)$plan['balance'];
    } elseif ($plan['status'] === 'pending') {
        $layaway_stats['pending_plans']++;
    }
}

$stmt = $db->prepare("
    SELECT lpay.*, lp.id AS plan_ref, p.name AS product_name
    FROM layaway_payments lpay
    JOIN layaway_plans lp ON lp.id = lpay.plan_id
    LEFT JOIN products p ON p.id = lp.product_id
    WHERE lp.user_id = ?
    ORDER BY lpay.paid_at DESC
    LIMIT 10
");
$stmt->execute([$uid]);
$layaway_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$layaway_products = safe_query($db, "SELECT id, name, price FROM products WHERE stock > 0 ORDER BY name");

$layaway_settings = safe_one($db, "SELECT * FROM layaway_settings WHERE id=1") ?: [
    'periods' => '30,60,90,120',
    'min_down_payment_percent' => 20,
];

/* ------------------------------------------------------------------
   Wishlist data
   ------------------------------------------------------------------ */
$wishlist_items = safe_query($db, "
    SELECT w.product_id, w.created_at,
           p.name, p.price, p.image, p.stock,
           c.name AS category_name
    FROM wishlist w
    JOIN products p ON p.id = w.product_id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE w.user_id = :uid
    ORDER BY w.created_at DESC
", [':uid' => $uid]);

$wishlist_count = count($wishlist_items);

$user_full_name = $user['full_name'] ?? ($user['username'] ?? 'Member');
$user_email     = $user['email']     ?? '';
$user_username  = $user['username']  ?? '';
$user_phone     = $user['phone']     ?? '';
$user_address   = $user['address']   ?? '';
$user_initial   = strtoupper(substr(trim($user_full_name), 0, 1));
$user_first     = explode(' ', trim($user_full_name))[0] ?? $user_full_name;

$page_title = 'My Account | R-Jewel&Gold';
?>
<?php include '../includes/header.php'; ?>

<style>
:root {
    --bg: #f2ede4; --bg-soft: #faf7f2; --card: #ffffff;
    --border: #e5ddd0; --border-light: #ede7db;
    --text: #5a5245; --text-bright: #2b2620;
    --text-muted: #8a8172; --text-dim: #b3a998;
    --primary: <?php echo htmlspecialchars($accent); ?>;
    --primary-soft: <?php echo htmlspecialchars($accent); ?>18;
    --gold: #b8941f; --gold-soft: rgba(184,148,31,0.1); --gold-strong: #8a6e14;
    --success: #0d9668; --danger: #dc2626; --warning: #d97706;
    --section-alt: #faf7f2;
}
<?php if ($resolved_theme === 'dark'): ?>
:root {
    --bg: #151821; --bg-soft: #1c202b; --card: #222735;
    --border: #343b4d; --border-light: #2d3444;
    --text: #d5d9e2; --text-bright: #ffffff;
    --text-muted: #a0a8ba; --text-dim: #777f91;
    --section-alt: #1c202b;
}
<?php elseif ($resolved_theme === 'light'): ?>
:root {
    --bg: #f5f7fb; --bg-soft: #ffffff; --card: #ffffff;
    --border: #e2e8f0; --border-light: #edf1f6;
    --text: #475569; --text-bright: #172033;
    --text-muted: #64748b; --text-dim: #94a3b8;
    --section-alt: #f5f7fb;
}
<?php endif; ?>

body, h1, h2, h3, h4, h5, h6,
.welcome-text h1, .user-name, .stat-number,
.stat-number, .layaway-step-num, .user-avatar {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
body { background: var(--bg); color: var(--text); transition: background-color .3s ease, color .3s ease; }
h1, h2, h3, h4, h5 { color: var(--text-bright); font-weight: 600; }

.account-hero {
    position: relative; padding: 4.5rem 1.5rem 3.5rem; background: var(--bg);
    background-image:
        radial-gradient(ellipse at 20% 20%, rgba(184,148,31,0.14), transparent 55%),
        radial-gradient(ellipse at 80% 80%, rgba(91,95,212,0.08), transparent 55%);
    overflow: hidden; border-bottom: 1px solid var(--border-light);
}
.account-hero::before {
    content: ''; position: absolute; inset: 0;
    background-image:
        radial-gradient(1.5px 1.5px at 15% 25%, rgba(184,148,31,.35), transparent),
        radial-gradient(1px 1px at 45% 60%, rgba(138,129,114,.25), transparent),
        radial-gradient(1.5px 1.5px at 75% 15%, rgba(184,148,31,.3), transparent),
        radial-gradient(1px 1px at 85% 75%, rgba(138,129,114,.2), transparent),
        radial-gradient(1px 1px at 30% 85%, rgba(184,148,31,.25), transparent);
    pointer-events: none;
}
.welcome-section {
    position: relative; z-index: 2;
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 24px; max-width: 1280px; margin: 0 auto;
}
.welcome-eyebrow {
    font-size: 0.68rem; font-weight: 700; letter-spacing: 0.4em;
    text-transform: uppercase; color: var(--gold); margin-bottom: 0.5rem;
}
.welcome-text h1 { font-size: clamp(2rem, 4vw, 3rem); line-height: 1.05; margin-bottom: 0.35rem; }
.welcome-text h1 em { color: var(--gold); font-style: italic; font-weight: 500; }
.welcome-text p { font-size: 1rem; color: var(--text-muted); margin: 0; }

.container-lux { max-width: 1280px; margin: 0 auto; padding: 0 24px; }
.dashboard-grid {
    display: grid; grid-template-columns: 280px 1fr; gap: 28px;
    align-items: start; padding: 2.5rem 0 4rem;
}

.account-sidebar {
    background: var(--card); border-radius: 14px; border: 1px solid var(--border);
    overflow: hidden; position: sticky; top: 24px;
    transition: border-color .3s ease, box-shadow .3s ease;
}
.account-sidebar:hover { border-color: rgba(184,148,31,.4); box-shadow: 0 20px 40px -22px rgba(90,82,69,.25); }
.user-profile {
    padding: 28px 20px 20px; text-align: center;
    background: var(--bg-soft); border-bottom: 1px solid var(--border-light);
}
.user-avatar {
    width: 88px; height: 88px; margin: 0 auto 16px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 2.2rem; font-weight: 600;
    color: #fff; background: linear-gradient(135deg, var(--gold), var(--gold-strong));
    box-shadow: 0 12px 22px -10px rgba(184,148,31,.6); transition: transform .25s ease;
}
.user-avatar:hover { transform: scale(1.04); }
.user-name { font-size: 1.35rem; font-weight: 600; color: var(--text-bright); margin-bottom: 2px; }
.user-email { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px; }
.nav-menu { padding: 14px 10px; display: flex; flex-direction: column; gap: 3px; }
.nav-item {
    display: flex; align-items: center; gap: 13px; padding: 11px 16px;
    border-radius: 9px; color: var(--text); font-weight: 500; font-size: 0.92rem;
    cursor: pointer; text-decoration: none; transition: all .2s ease; border: 1px solid transparent;
}
.nav-item i { width: 20px; font-size: 1.05rem; color: var(--text-muted); transition: color .2s; }
.nav-item:hover { background: var(--gold-soft); color: var(--gold-strong); border-color: rgba(184,148,31,.2); }
.nav-item:hover i { color: var(--gold); }
.nav-item.active {
    background: linear-gradient(135deg, var(--gold), var(--gold-strong));
    color: #fff; box-shadow: 0 8px 20px -10px rgba(184,148,31,.7);
}
.nav-item.active i { color: #fff; }
.nav-badge {
    margin-left: auto; padding: 2px 10px; border-radius: 40px;
    font-size: 0.72rem; font-weight: 700;
    background: rgba(255,255,255,.25); color: #fff;
}
.nav-item:not(.active) .nav-badge { background: var(--bg-soft); color: var(--text-muted); border: 1px solid var(--border); }

.account-content {
    background: var(--card); border-radius: 14px; padding: 32px 34px;
    border: 1px solid var(--border); min-height: 560px; transition: border-color .3s ease;
}
.account-content:hover { border-color: rgba(184,148,31,.3); }
.panel { display: none; animation: fadeSlide .25s ease-out; }
.panel.active { display: block; }
@keyframes fadeSlide { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: translateY(0); } }

.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 32px; }
.stat-card {
    background: var(--bg-soft); border: 1px solid var(--border); border-radius: 14px;
    padding: 22px 16px; text-align: center; transition: all .3s ease;
    min-width: 0;
}
.stat-card:hover { transform: translateY(-4px); border-color: var(--gold); box-shadow: 0 16px 32px -20px rgba(184,148,31,.45); }
.stat-card i { font-size: 1.6rem; color: var(--gold); margin-bottom: 10px; display: block; }
.stat-number {
    font-size: 1.9rem; font-weight: 600; color: var(--text-bright); line-height: 1.1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-variant-numeric: tabular-nums;
}
.stat-number.stat-number-compact {
    font-size: 1.5rem;
    letter-spacing: -0.01em;
}
.stat-label { font-size: 0.68rem; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase; color: var(--text-muted); margin-top: 4px; }

.orders-table { width: 100%; overflow-x: auto; border: 1px solid var(--border); border-radius: 12px; }
.orders-table table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.orders-table th {
    text-align: left; padding: 13px 16px; background: var(--bg-soft);
    font-weight: 700; color: var(--text-muted); font-size: 0.7rem;
    text-transform: uppercase; letter-spacing: 0.12em; border-bottom: 1px solid var(--border);
}
.orders-table td { padding: 14px 16px; border-bottom: 1px solid var(--border-light); color: var(--text); }
.orders-table tr:last-child td { border-bottom: none; }
.orders-table tbody tr { transition: background .2s; }
.orders-table tbody tr:hover { background: var(--gold-soft); }

.badge-status { padding: 4px 11px; border-radius: 100px; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; display: inline-block; }
.badge-pending    { background: rgba(184,148,31,.12); color:#8a6e14; }
.badge-processing { background: rgba(91,95,212,.12);  color:#4a4eb8; }
.badge-shipped    { background: rgba(13,150,104,.12); color:#0a7a54; }
.badge-delivered  { background: rgba(13,150,104,.18); color:#065f46; }
.badge-cancelled  { background: rgba(220,38,38,.12);  color:#991b1b; }
.badge-active     { background: rgba(13,150,104,.18); color:#065f46; }
.badge-overdue    { background: rgba(220,38,38,.12);  color:#991b1b; }
.badge-approved   { background: rgba(91,95,212,.12);  color:#4a4eb8; }
.badge-rejected   { background: rgba(220,38,38,.12);  color:#991b1b; }
.badge-completed  { background: rgba(13,150,104,.18); color:#065f46; }

.btn-lux {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 0.75rem 1.6rem; border-radius: 9px; border: none;
    font-family: 'Inter', sans-serif; font-size: 0.72rem; font-weight: 600;
    letter-spacing: 0.18em; text-transform: uppercase;
    text-decoration: none; cursor: pointer; transition: all .3s ease;
}
.btn-lux-gold { background: linear-gradient(135deg, var(--gold), var(--gold-strong)); color: #fff; box-shadow: 0 4px 14px -4px rgba(184,148,31,.5); }
.btn-lux-gold:hover { color: #fff; transform: translateY(-2px); box-shadow: 0 8px 22px -6px rgba(184,148,31,.65); }
.btn-lux-outline { background: transparent; color: var(--text-bright); border: 1px solid var(--border); }
.btn-lux-outline:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-2px); }
.btn-sm-lux { padding: 0.5rem 1rem; font-size: 0.66rem; }

.profile-form .form-group { margin-bottom: 20px; }
.profile-form label { display: block; margin-bottom: 6px; font-weight: 600; color: var(--text); font-size: 0.78rem; letter-spacing: 0.08em; text-transform: uppercase; }
.profile-form input, .profile-form textarea, .profile-form select {
    width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 10px;
    font-family: 'Inter', sans-serif; font-size: 0.92rem; background: var(--card); color: var(--text-bright); transition: all .2s;
}
.profile-form input:focus, .profile-form textarea:focus, .profile-form select:focus {
    outline: none; border-color: var(--gold); box-shadow: 0 0 0 4px var(--gold-soft);
}
.profile-form input:disabled { background: var(--bg-soft); color: var(--text-muted); }
hr { border: none; border-top: 1px solid var(--border-light); margin: 28px 0 20px; }

h3 { font-size: 1.9rem; letter-spacing: -0.01em; }
h4 { font-size: 1.35rem; }
h5 { font-size: 1.15rem; }
.text-muted { color: var(--text-muted); }
.small { font-size: 0.82rem; }

.toast-notification {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    animation: slideInRight .35s ease-out; border-radius: 10px; overflow: hidden;
    box-shadow: 0 12px 30px rgba(0,0,0,.15);
    background: var(--card); border: 1px solid var(--border); min-width: 260px;
}
.toast-notification .toast-body { padding: .9rem 1.1rem; font-size: .85rem; font-weight: 500; display: flex; align-items: center; gap: 10px; }
.toast-notification.success .toast-body { color: var(--success); }
.toast-notification.error   .toast-body { color: var(--danger); }
@keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

.layaway-progress { height: 6px; background: var(--border); border-radius: 100px; overflow: hidden; margin-top: 6px; }
.layaway-progress-bar { height: 100%; background: linear-gradient(90deg, var(--gold), var(--gold-strong)); border-radius: 100px; transition: width .4s ease; }

.layaway-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px; }
.layaway-step {
    background: var(--bg-soft); border: 1px solid var(--border); border-radius: 12px;
    padding: 24px 16px; text-align: center; transition: all .3s ease;
}
.layaway-step:hover { transform: translateY(-4px); border-color: var(--gold); box-shadow: 0 16px 32px -20px rgba(184,148,31,.45); }
.layaway-step-num { font-size: 2rem; font-weight: 600; color: var(--gold); line-height: 1; margin-bottom: 8px; }

/* ---------- Wishlist grid ---------- */
.wishlist-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 20px;
}
.wishlist-card {
    background: var(--bg-soft);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    transition: all .3s ease;
    display: flex;
    flex-direction: column;
}
.wishlist-card:hover {
    transform: translateY(-6px);
    border-color: var(--gold);
    box-shadow: 0 18px 36px -20px rgba(184,148,31,.5);
}
.wishlist-img-wrap {
    position: relative;
    aspect-ratio: 4 / 3;
    background: var(--card);
    overflow: hidden;
}
.wishlist-img-wrap img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .5s ease;
}
.wishlist-card:hover .wishlist-img-wrap img { transform: scale(1.06); }

.wishlist-flag {
    position: absolute;
    top: 10px; left: 10px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: #fff;
    z-index: 2;
}
.wishlist-flag-out { background: linear-gradient(135deg, #6b7280, #4b5563); }

.wishlist-remove-btn {
    position: absolute;
    top: 10px; right: 10px;
    width: 32px; height: 32px;
    border-radius: 50%;
    background: rgba(255,255,255,.95);
    border: 1px solid var(--border);
    color: var(--text-muted);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    transition: all .2s ease;
    z-index: 3;
    box-shadow: 0 3px 10px -3px rgba(0,0,0,.25);
}
.wishlist-remove-btn:hover {
    background: var(--danger);
    color: #fff;
    border-color: transparent;
    transform: rotate(90deg);
}

.wishlist-info {
    padding: 0.9rem 1rem 1.1rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    flex: 1;
}
.wishlist-category {
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--gold);
}
.wishlist-name {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--text-bright);
    margin: 0 0 0.15rem;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.wishlist-price {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--gold);
    margin-bottom: 0.55rem;
}
.wishlist-actions {
    display: flex;
    gap: 6px;
    margin-top: auto;
}
.wishlist-actions .btn-lux {
    flex: 1;
    justify-content: center;
    padding: 0.5rem 0.6rem;
    font-size: 0.62rem;
    letter-spacing: 0.1em;
}

/* ============================================================
   PAYMENT MODAL
   ============================================================ */
.modal-lux {
    display: none; position: fixed; inset: 0;
    background: rgba(31,27,22,.55); backdrop-filter: blur(4px);
    z-index: 3000; align-items: center; justify-content: center;
    padding: 1rem; overflow-y: auto;
}
.modal-lux.active { display: flex; }

.modal-lux-content {
    background: var(--card); border-radius: 16px;
    max-width: 620px; width: 100%;
    padding: 1.75rem 1.85rem 1.5rem;
    box-shadow: 0 25px 60px -12px rgba(0,0,0,.35);
    border: 1px solid var(--border);
    position: relative;
    margin: auto;
}
.modal-lux-content h3 { margin: 0 0 4px; font-size: 1.5rem; }
.modal-lux-content .modal-desc { color: var(--text-muted); font-size: .85rem; margin: 0 0 1rem; }

.pay-form .form-group { margin-bottom: 0.75rem; }
.pay-form label {
    display: block; margin-bottom: 0.3rem;
    font-weight: 700; color: var(--text);
    font-size: 0.62rem; letter-spacing: 0.1em; text-transform: uppercase;
}
.pay-form input,
.pay-form textarea,
.pay-form select {
    width: 100%; padding: 0.6rem 0.85rem;
    border: 1px solid var(--border); border-radius: 8px;
    font-family: 'Inter', sans-serif; font-size: 0.84rem;
    background: var(--card); color: var(--text-bright);
    transition: border-color .2s, box-shadow .2s;
}
.pay-form input:focus,
.pay-form textarea:focus,
.pay-form select:focus {
    outline: none; border-color: var(--gold);
    box-shadow: 0 0 0 3px var(--gold-soft);
}
.pay-form input:read-only { background: var(--bg-soft); color: var(--text-muted); }
.pay-form textarea { resize: none; }
.pay-form input[type="file"] {
    padding: 0.45rem 0.7rem; font-size: 0.78rem;
    cursor: pointer;
}

.pay-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 0.85rem;
}
.pay-grid .pay-col-full { grid-column: 1 / -1; }

.pay-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.6rem;
    margin-top: 0.85rem;
    padding-top: 0.85rem;
    border-top: 1px solid var(--border-light);
}
.pay-actions .btn-lux {
    justify-content: center;
    padding: 0.7rem 1rem;
    margin: 0;
}
.pay-balance-hint {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-top: 0.2rem;
    display: block;
}

@media (max-width: 620px) {
    .modal-lux-content {
        padding: 1.4rem 1.35rem 1.15rem;
        border-radius: 14px;
    }
    .pay-grid { grid-template-columns: 1fr; gap: 0; }
    .pay-grid .pay-col-full { grid-column: auto; }
}
@media (max-width: 380px) {
    .modal-lux-content { padding: 1.2rem 1.1rem 1rem; }
    .pay-actions { grid-template-columns: 1fr; }
}

@media (max-width: 992px) {
    .dashboard-grid { grid-template-columns: 1fr; }
    .account-sidebar { position: static; }
    .nav-menu { flex-direction: row; flex-wrap: wrap; justify-content: center; }
    .nav-item { padding: 10px 14px; }
}
@media (max-width: 768px) {
    .account-hero { padding: 3rem 1rem 2.5rem; }
    .account-content { padding: 24px 20px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .row { grid-template-columns: 1fr !important; }
}
@media (max-width: 480px) {
    .container-lux { padding: 0 16px; }
    .stats-grid { grid-template-columns: 1fr; }
    .stat-number { font-size: 1.6rem; }
    .stat-number.stat-number-compact { font-size: 1.35rem; }
    .wishlist-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
    .wishlist-name { font-size: 0.95rem; }
    .wishlist-price { font-size: 0.95rem; }
    .wishlist-actions { flex-direction: column; }
}
@media (prefers-reduced-motion: reduce) { * { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
</style>

<section class="account-hero">
    <div class="welcome-section">
        <div class="welcome-text">
            <p class="welcome-eyebrow">R-Jewel&Gold · Member Area</p>
            <h1>Welcome back, <em><?php echo htmlspecialchars($user_first); ?></em></h1>
            <p>Manage your profile, orders, lay away plans, and preferences</p>
        </div>
    </div>
</section>

<div class="container-lux">
    <div class="dashboard-grid">
        <aside class="account-sidebar">
            <div class="user-profile">
                <div class="user-avatar"><?php echo htmlspecialchars($user_initial); ?></div>
                <div class="user-name"><?php echo htmlspecialchars($user_full_name); ?></div>
                <div class="user-email"><?php echo htmlspecialchars($user_email); ?></div>
            </div>
            <nav class="nav-menu">
                <div class="nav-item active" data-panel="orders">
                    <i class="fas fa-bag-shopping"></i><span>My Orders</span>
                    <span class="nav-badge"><?php echo (int)($order_stats['total_orders'] ?? 0); ?></span>
                </div>
                <div class="nav-item" data-panel="layaway">
                    <i class="fas fa-hand-holding-usd"></i><span>Lay Away</span>
                    <?php if ($layaway_stats['active_plans'] + $layaway_stats['pending_plans'] > 0): ?>
                        <span class="nav-badge"><?php echo (int)($layaway_stats['active_plans'] + $layaway_stats['pending_plans']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-item" data-panel="profile">
                    <i class="fas fa-user-pen"></i><span>Profile Settings</span>
                </div>
                <div class="nav-item" data-panel="wishlist">
                    <i class="fas fa-heart"></i><span>Wishlist</span>
                    <?php if ($wishlist_count > 0): ?>
                        <span class="nav-badge"><?php echo (int)$wishlist_count; ?></span>
                    <?php endif; ?>
                </div>
                <a href="logout.php" class="nav-item">
                    <i class="fas fa-arrow-right-from-bracket"></i><span>Logout</span>
                </a>
            </nav>
        </aside>

        <main class="account-content">
            <div class="panel active" id="orders-panel">
                <p class="welcome-eyebrow">Overview</p>
                <h3 class="mb-4" style="margin-bottom:24px;">Welcome back, <?php echo htmlspecialchars($user_full_name); ?></h3>

                <div class="stats-grid">
                    <div class="stat-card">
                        <i class="fas fa-bag-shopping"></i>
                        <div class="stat-number"><?php echo (int)($order_stats['total_orders'] ?? 0); ?></div>
                        <div class="stat-label">Total Orders</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-peso-sign"></i>
                        <div class="stat-number stat-number-compact" title="₱<?php echo number_format($total_spent, 2); ?>">
                            <?php echo compact_money($total_spent); ?>
                        </div>
                        <div class="stat-label">Total Spent</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-clock"></i>
                        <div class="stat-number"><?php echo (int)($order_stats['pending_orders'] ?? 0); ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-circle-check"></i>
                        <div class="stat-number"><?php echo (int)($order_stats['delivered_orders'] ?? 0); ?></div>
                        <div class="stat-label">Delivered</div>
                    </div>
                </div>

                <p class="welcome-eyebrow">Recent Activity</p>
                <h4 style="margin-bottom:16px;">Recent Orders</h4>

                <?php if (empty($recent_orders)): ?>
                <div class="text-center" style="padding:40px 0;">
                    <i class="fas fa-box-open" style="font-size:2.5rem;color:var(--text-dim);margin-bottom:14px;display:block;"></i>
                    <p class="text-muted">You haven't placed any orders yet.</p>
                    <a href="shop.php" class="btn-lux btn-lux-gold" style="margin-top:12px;">
                        Start Shopping <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                <?php else: ?>
                <div class="orders-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Order #</th><th>Date</th><th>Total</th><th>Status</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td>#<?php echo str_pad((int)$order['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                <td>₱<?php echo number_format((float)$order['total_amount'], 2); ?></td>
                                <td>
                                    <span class="badge-status badge-<?php echo htmlspecialchars($order['status']); ?>">
                                        <?php echo ucfirst(htmlspecialchars($order['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="order-details.php?id=<?php echo (int)$order['id']; ?>" class="btn-lux btn-lux-outline btn-sm-lux">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (($order_stats['total_orders'] ?? 0) > 5): ?>
                <div class="text-center" style="margin-top:20px;">
                    <a href="orders.php" class="btn-lux btn-lux-outline">View All Orders</a>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="panel" id="layaway-panel">
                <p class="welcome-eyebrow">Flexible Payment</p>
                <h3 style="margin-bottom:24px;">Lay Away Plan</h3>

                <div class="stats-grid" style="margin-bottom: 32px;">
                    <div class="stat-card">
                        <i class="fas fa-layer-group"></i>
                        <div class="stat-number"><?php echo (int)$layaway_stats['active_plans']; ?></div>
                        <div class="stat-label">Active Plans</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-wallet"></i>
                        <div class="stat-number stat-number-compact" title="₱<?php echo number_format($layaway_stats['total_paid'], 2); ?>">
                            <?php echo compact_money($layaway_stats['total_paid']); ?>
                        </div>
                        <div class="stat-label">Total Paid</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-scale-balanced"></i>
                        <div class="stat-number stat-number-compact" title="₱<?php echo number_format($layaway_stats['total_balance'], 2); ?>">
                            <?php echo compact_money($layaway_stats['total_balance']); ?>
                        </div>
                        <div class="stat-label">Remaining Balance</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-percent"></i>
                        <div class="stat-number">0%</div>
                        <div class="stat-label">Interest</div>
                    </div>
                </div>

                <p class="welcome-eyebrow">How It Works</p>
                <h4 style="margin-bottom: 16px;">Lay Away in 4 Easy Steps</h4>

                <div class="layaway-steps">
                    <div class="layaway-step">
                        <div class="layaway-step-num">1</div>
                        <h5 style="margin: 0 0 6px;">Browse Products</h5>
                        <p class="text-muted small" style="margin: 0;">Explore our collection and pick the piece you love.</p>
                    </div>
                    <div class="layaway-step">
                        <div class="layaway-step-num">2</div>
                        <h5 style="margin: 0 0 6px;">Visit the Store</h5>
                        <p class="text-muted small" style="margin: 0;">Come to our store to set up your lay away plan in person.</p>
                    </div>
                    <div class="layaway-step">
                        <div class="layaway-step-num">3</div>
                        <h5 style="margin: 0 0 6px;">Pay 20% Down</h5>
                        <p class="text-muted small" style="margin: 0;">Secure your item with a 20% down payment.</p>
                    </div>
                    <div class="layaway-step">
                        <div class="layaway-step-num">4</div>
                        <h5 style="margin: 0 0 6px;">Get Your Item</h5>
                        <p class="text-muted small" style="margin: 0;">We'll hand over the item, then you continue paying monthly until the balance ends.</p>
                    </div>
                </div>

                <p class="welcome-eyebrow">Your Plans</p>
                <h4 style="margin-bottom: 16px;">My Lay Away Plans</h4>

                <?php if (empty($layaway_plans)): ?>
                <div class="text-center" style="padding:40px 0;">
                    <i class="fas fa-hand-holding-usd" style="font-size:2.5rem;color:var(--text-dim);margin-bottom:14px;display:block;"></i>
                    <p class="text-muted">You don't have any lay away plans yet.</p>
                </div>
                <?php else: ?>
                <div class="orders-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Progress</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($layaway_plans as $plan):
                                $planTotal   = (float)$plan['total_amount'];
                                $planPaid    = $planTotal - (float)$plan['balance'];
                                $planBalance = (float)$plan['balance'];
                                $planPct     = $planTotal > 0 ? min(100, round(($planPaid / $planTotal) * 100)) : 0;
                                $dueDate     = !empty($plan['due_date']) ? date('M d, Y', strtotime($plan['due_date'])) : '—';
                                $status      = $plan['status'];
                                $isOverdue   = ($status === 'overdue');
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($plan['product_name'] ?? 'Jewelry Item'); ?></td>
                                <td>₱<?php echo number_format($planTotal, 2); ?></td>
                                <td>₱<?php echo number_format($planPaid, 2); ?></td>
                                <td>₱<?php echo number_format($planBalance, 2); ?></td>
                                <td style="min-width:120px;">
                                    <div class="small text-muted" style="margin-bottom:2px;"><?php echo $planPct; ?>%</div>
                                    <div class="layaway-progress">
                                        <div class="layaway-progress-bar" style="width: <?php echo $planPct; ?>%;"></div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($isOverdue): ?>
                                        <span class="badge-status badge-overdue"><?php echo $dueDate; ?></span>
                                    <?php else: ?>
                                        <?php echo $dueDate; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-status badge-<?php echo htmlspecialchars($status); ?>">
                                        <?php echo ucfirst(htmlspecialchars($status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (in_array($status, ['active','approved','overdue'], true)): ?>
                                        <button type="button" class="btn-lux btn-lux-gold btn-sm-lux"
                                                onclick="openPayModal(<?php echo (int)$plan['id']; ?>, <?php echo (float)$planBalance; ?>, '<?php echo htmlspecialchars(addslashes($plan['product_name'] ?? 'Item')); ?>');">
                                            <i class="fas fa-money-bill"></i> Pay
                                        </button>
                                    <?php elseif ($status === 'pending'): ?>
                                        <button type="button" class="btn-lux btn-lux-outline btn-sm-lux"
                                                onclick="cancelPlan(<?php echo (int)$plan['id']; ?>);">
                                            Cancel
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($layaway_payments)): ?>
                <p class="welcome-eyebrow" style="margin-top:36px;">Payment History</p>
                <h4 style="margin-bottom: 16px;">Recent Payments</h4>
                <div class="orders-table">
                    <table>
                        <thead>
                            <tr><th>Date</th><th>Plan</th><th>Amount</th><th>Method</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($layaway_payments as $pay): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($pay['paid_at'])); ?></td>
                                <td>#LY-<?php echo str_pad((int)$pay['plan_ref'], 4, '0', STR_PAD_LEFT); ?></td>
                                <td>₱<?php echo number_format((float)$pay['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($pay['payment_method'] ?: '—'); ?></td>
                                <td>
                                    <span class="badge-status badge-<?php echo $pay['status'] === 'verified' ? 'completed' : ($pay['status'] === 'rejected' ? 'rejected' : 'pending'); ?>">
                                        <?php echo ucfirst($pay['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div class="panel" id="profile-panel">
                <p class="welcome-eyebrow">Account</p>
                <h3 style="margin-bottom:24px;">Profile Settings</h3>
                <form class="profile-form" id="profileForm" action="update-profile.php" method="POST">
                    <div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" value="<?php echo htmlspecialchars($user_username); ?>" disabled>
                            <div class="small text-muted" style="margin-top:6px;">Username cannot be changed</div>
                        </div>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($user_full_name); ?>" required>
                        </div>
                    </div>
                    <div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                        <div class="form-group">
                            <label>Email Address *</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($user_email); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($user_phone); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" rows="3"><?php echo htmlspecialchars($user_address); ?></textarea>
                    </div>

                    <hr>
                    <h5>Change Password</h5>
                    <p class="text-muted small" style="margin-bottom:16px;">Leave blank if you don't want to change password</p>

                    <div class="row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;">
                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" name="current_password">
                        </div>
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password">
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password">
                        </div>
                    </div>

                    <button type="submit" class="btn-lux btn-lux-gold" style="margin-top:8px;">
                        <i class="fas fa-floppy-disk"></i> Update Profile
                    </button>
                </form>
            </div>

            <div class="panel" id="wishlist-panel">
                <p class="welcome-eyebrow">Saved</p>
                <h3 style="margin-bottom:24px;">My Wishlist</h3>

                <?php if (empty($wishlist_items)): ?>
                    <div class="text-center" style="padding:50px 0;">
                        <i class="fas fa-heart" style="font-size:2.5rem;color:var(--text-dim);margin-bottom:14px;display:block;"></i>
                        <p class="text-muted">Your wishlist is empty.</p>
                        <a href="shop.php" class="btn-lux btn-lux-gold" style="margin-top:12px;">Browse Products</a>
                    </div>
                <?php else: ?>
                    <div class="wishlist-grid" id="wishlistGrid">
                        <?php foreach ($wishlist_items as $w):
                            $stock  = (int)($w['stock'] ?? 0);
                            $is_out = $stock <= 0;
                            $imgFile = trim((string)($w['image'] ?? ''));
                            $imgDisk = __DIR__ . '/../uploads/' . $imgFile;
                            $imgUrl  = ($imgFile !== '' && file_exists($imgDisk))
                                ? '../uploads/' . htmlspecialchars($imgFile, ENT_QUOTES, 'UTF-8')
                                : '';
                        ?>
                        <div class="wishlist-card" data-product-id="<?php echo (int)$w['product_id']; ?>">
                            <div class="wishlist-img-wrap">
                                <?php if ($imgUrl !== ''): ?>
                                    <img src="<?php echo $imgUrl; ?>"
                                         alt="<?php echo htmlspecialchars($w['name']); ?>"
                                         loading="lazy"
                                         onerror="this.onerror=null;this.src='https://via.placeholder.com/400x300/ede7db/8a8172?text=Jewelry'">
                                <?php else: ?>
                                    <img src="https://via.placeholder.com/400x300/ede7db/8a8172?text=Jewelry"
                                         alt="<?php echo htmlspecialchars($w['name']); ?>">
                                <?php endif; ?>

                                <?php if ($is_out): ?>
                                    <span class="wishlist-flag wishlist-flag-out">Sold Out</span>
                                <?php endif; ?>

                                <button type="button"
                                        class="wishlist-remove-btn"
                                        title="Remove from wishlist"
                                        onclick="removeFromWishlist(<?php echo (int)$w['product_id']; ?>, this)">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="wishlist-info">
                                <div class="wishlist-category"><?php echo htmlspecialchars($w['category_name'] ?? 'Jewelry'); ?></div>
                                <h5 class="wishlist-name"><?php echo htmlspecialchars($w['name']); ?></h5>
                                <div class="wishlist-price">₱<?php echo number_format((float)$w['price'], 2); ?></div>
                                <div class="wishlist-actions">
                                    <a href="product-details.php?id=<?php echo (int)$w['product_id']; ?>"
                                       class="btn-lux btn-lux-outline btn-sm-lux">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if (!$is_out): ?>
                                        <button type="button"
                                                class="btn-lux btn-lux-gold btn-sm-lux"
                                                onclick="addToCartFromWishlist(<?php echo (int)$w['product_id']; ?>)">
                                            <i class="fas fa-shopping-bag"></i> Add
                                        </button>
                                    <?php else: ?>
                                        <span class="btn-lux btn-lux-outline btn-sm-lux" style="opacity:.5;cursor:not-allowed;">
                                            Sold Out
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<div class="modal-lux" id="payModal">
    <div class="modal-lux-content">
        <h3>Submit a Payment</h3>
        <p class="modal-desc">Upload your proof — admin will verify it shortly.</p>

        <form id="payForm" class="pay-form" enctype="multipart/form-data">
            <input type="hidden" id="payPlanId" value="0">

            <div class="pay-grid">
                <div class="form-group pay-col-full">
                    <label>Item</label>
                    <input type="text" id="payItem" readonly>
                </div>

                <div class="form-group">
                    <label>Amount (₱) *</label>
                    <input type="number" id="payAmount" step="0.01" min="0.01" required value="0">
                    <span class="pay-balance-hint">Remaining: ₱<span id="payBalance">0.00</span></span>
                </div>

                <div class="form-group">
                    <label>Payment Method *</label>
                    <select id="payMethod" required>
                        <option value="">Select method...</option>
                        <option value="GCash">GCash</option>
                        <option value="Maya">Maya</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cash">Cash (in-store)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Reference No.</label>
                    <input type="text" id="payRef" placeholder="e.g. GCash reference">
                </div>

                <div class="form-group">
                    <label>Proof of Payment *</label>
                    <input type="file" id="payProof" name="proof_image" accept="image/*" required>
                </div>

                <div class="form-group pay-col-full">
                    <label>Notes (optional)</label>
                    <textarea id="payNotes" rows="2" placeholder="Any additional info..."></textarea>
                </div>
            </div>

            <div class="pay-actions">
                <button type="button" class="btn-lux btn-lux-outline" onclick="document.getElementById('payModal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn-lux btn-lux-gold"><i class="fas fa-upload"></i> Submit Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    const MIN_PCT = <?php echo (float)($layaway_settings['min_down_payment_percent'] ?? 20); ?>;

    function showNotification(message, type) {
        type = type || 'success';
        var icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        var el = document.createElement('div');
        el.className = 'toast-notification ' + type;
        el.innerHTML = '<div class="toast-body"><i class="fas ' + icon + '"></i><span></span></div>';
        el.querySelector('span').textContent = message;
        document.body.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s ease';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 300);
        }, 3000);
    }

    document.querySelectorAll('.nav-item[data-panel]').forEach(function (item) {
        item.addEventListener('click', function () {
            var panel = this.getAttribute('data-panel');
            document.querySelectorAll('.nav-item').forEach(function (n) { n.classList.remove('active'); });
            this.classList.add('active');
            document.querySelectorAll('.panel').forEach(function (p) { p.classList.remove('active'); });
            var target = document.getElementById(panel + '-panel');
            if (target) target.classList.add('active');
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                url.hash = panel;
                window.history.replaceState({}, '', url.toString());
            }
        });
    });

    /* Deep-link to #wishlist etc */
    (function deepLink() {
        var hash = (window.location.hash || '').replace('#', '');
        if (!hash) return;
        var nav = document.querySelector('.nav-item[data-panel="' + hash + '"]');
        if (nav) nav.click();
    })();

    window.openPayModal = function (planId, balance, itemName) {
        document.getElementById('payPlanId').value = planId;
        document.getElementById('payItem').value = itemName;
        document.getElementById('payBalance').textContent = parseFloat(balance).toFixed(2);
        document.getElementById('payAmount').value = parseFloat(balance).toFixed(2);
        document.getElementById('payAmount').max = parseFloat(balance).toFixed(2);
        document.getElementById('payModal').classList.add('active');
    };

    var payForm = document.getElementById('payForm');
    if (payForm) {
        payForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData();
            fd.append('layaway_action', 'submit_payment');
            fd.append('plan_id', document.getElementById('payPlanId').value);
            fd.append('amount', document.getElementById('payAmount').value);
            fd.append('payment_method', document.getElementById('payMethod').value);
            fd.append('reference_no', document.getElementById('payRef').value);
            fd.append('notes', document.getElementById('payNotes').value);
            var proof = document.getElementById('payProof').files[0];
            if (proof) fd.append('proof_image', proof);

            fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    showNotification(data.message, data.success ? 'success' : 'error');
                    if (data.success) setTimeout(function () { location.reload(); }, 1200);
                })
                .catch(function () { showNotification('Network error.', 'error'); });
        });
    }

    window.cancelPlan = function (planId) {
        if (!confirm('Cancel this layaway application?')) return;
        var fd = new FormData();
        fd.append('layaway_action', 'cancel_my_plan');
        fd.append('plan_id', planId);
        fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                showNotification(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(function () { location.reload(); }, 1000);
            })
            .catch(function () { showNotification('Network error.', 'error'); });
    };

    document.querySelectorAll('.modal-lux').forEach(function (m) {
        m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('active'); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-lux.active').forEach(function (m) { m.classList.remove('active'); });
        }
    });

    var form = document.getElementById('profileForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var newPass = form.querySelector('input[name="new_password"]').value;
            var confirmPass = form.querySelector('input[name="confirm_password"]').value;
            if (newPass !== confirmPass) {
                showNotification('New password and confirmation do not match!', 'error');
                return;
            }
            fetch('update-profile.php', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) {
                return r.text().then(function (text) {
                    try { return { ok: r.ok, status: r.status, data: JSON.parse(text) }; }
                    catch (err) { return { ok: r.ok, status: r.status, raw: text }; }
                });
            })
            .then(function (res) {
                if (res.data && res.data.success) {
                    showNotification('Profile updated successfully!', 'success');
                    setTimeout(function () { location.reload(); }, 1500);
                } else if (res.data && res.data.message) {
                    showNotification(res.data.message, 'error');
                } else {
                    showNotification('Server error (HTTP ' + res.status + ').', 'error');
                }
            })
            .catch(function () { showNotification('Network error.', 'error'); });
        });
    }

    /* =========================================================
       WISHLIST ACTIONS
       ========================================================= */
    window.removeFromWishlist = function (productId, btn) {
        if (!confirm('Remove this item from your wishlist?')) return;
        if (btn) btn.disabled = true;

        var fd = new URLSearchParams();
        fd.append('action', 'remove');
        fd.append('product_id', productId);

        fetch('wishlist.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: fd.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) {
                showNotification((res && res.message) || 'Could not remove.', 'error');
                if (btn) btn.disabled = false;
                return;
            }

            var card = btn ? btn.closest('.wishlist-card') : null;
            if (card) {
                card.style.transition = 'opacity .25s, transform .25s';
                card.style.opacity = '0';
                card.style.transform = 'scale(0.9)';
                setTimeout(function () {
                    card.remove();
                    var grid = document.getElementById('wishlistGrid');
                    var remaining = grid ? grid.querySelectorAll('.wishlist-card').length : 0;
                    if (remaining === 0) location.reload();
                }, 250);
            }

            if (typeof window.refreshWishlist === 'function') window.refreshWishlist();
            showNotification('Removed from wishlist.', 'success');
        })
        .catch(function () {
            showNotification('Network error.', 'error');
            if (btn) btn.disabled = false;
        });
    };

    window.addToCartFromWishlist = function (productId) {
        var body = new URLSearchParams();
        body.append('product_id', productId);
        body.append('quantity', 1);

        fetch('add-to-cart.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                showNotification('Added to cart!', 'success');
                if (typeof window.applyCartCount === 'function' && typeof res.count !== 'undefined') {
                    window.applyCartCount(res.count);
                } else if (typeof window.updateCartCount === 'function') {
                    window.updateCartCount();
                }
            } else {
                showNotification((res && res.message) || 'Could not add to cart.', 'error');
            }
        })
        .catch(function () { showNotification('Network error.', 'error'); });
    };

    if (typeof window.refreshWishlist === 'function') {
        window.refreshWishlist();
    }
})();
</script>

<?php include '../includes/footer.php'; ?>