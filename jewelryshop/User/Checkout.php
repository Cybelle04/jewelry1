<?php
// user/checkout.php — Luxury Checkout Page (GCash + COD, 1% tax) + notifications
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/notify.php';

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
$resolved_theme = in_array($theme, ['cream', 'light', 'dark', 'auto'], true) ? $theme : 'cream';
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

$auth = $db ? new Auth($db) : null;

if (!$auth || !$auth->isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

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

/* ==================================================================
   3b. AJAX — Place order (GCash w/ proof, or COD) + notifications
        Stock model:
          products.stock          = total monthly stock (NOT touched here)
          products.released_stock = shop inventory (DECREMENTED by qty)
          available               = released_stock
   ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_place_order'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Unknown error'];

    $proof_path = null;
    $filename   = null;

    try {
        if (!$db) throw new Exception('Database unavailable.');

        $cart_items = safe_query($db, "
            SELECT c.*, p.name, p.price, p.image, p.stock, p.category_id,
                   COALESCE(p.released_stock, 0) AS released_stock
            FROM cart c JOIN products p ON c.product_id = p.id
            WHERE c.user_id = :uid
        ", [':uid' => $user_id]);
        if (empty($cart_items)) throw new Exception('Your cart is empty.');

        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $phone     = trim($_POST['phone']     ?? '');
        $address   = trim($_POST['address']   ?? '');
        $notes     = trim($_POST['notes']     ?? '');
        $method    = trim($_POST['payment_method'] ?? 'gcash');

        if ($full_name === '' || $email === '' || $phone === '' || $address === '') {
            throw new Exception('Please complete all required shipping fields.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }
        if (!in_array($method, ['gcash', 'cod'], true)) {
            throw new Exception('Invalid payment method.');
        }

        $subtotal = 0; $total_items = 0;
        foreach ($cart_items as $it) {
            $subtotal    += (float)$it['price'] * (int)$it['quantity'];
            $total_items += (int)$it['quantity'];
        }
        $shipping = $subtotal >= 100 ? 0 : 10;
        $tax      = $subtotal * 0.01;
        $total    = $subtotal + $shipping + $tax;

        $gcash_reference = null;

        if ($method === 'gcash') {
            $gcash_reference = trim($_POST['gcash_reference'] ?? '');
            if ($gcash_reference === '') throw new Exception('GCash reference number is required.');
            if (!preg_match('/^[0-9\s\-]{6,30}$/', $gcash_reference)) {
                throw new Exception('Please enter a valid GCash reference number.');
            }

            if (empty($_FILES['gcash_proof']['name']) || $_FILES['gcash_proof']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please upload your proof of payment.');
            }
            if ($_FILES['gcash_proof']['size'] > 5 * 1024 * 1024) {
                throw new Exception('Proof image must be under 5MB.');
            }
            $allowed = ['image/jpeg','image/jpg','image/png','image/webp'];
            $ext = strtolower(pathinfo($_FILES['gcash_proof']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                throw new Exception('Proof must be JPG, PNG, or WEBP.');
            }

            $uploadDir = __DIR__ . '/../uploads/payment_proofs/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
            $filename = 'gcash_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['gcash_proof']['tmp_name'], $uploadDir . $filename)) {
                throw new Exception('Failed to save proof image.');
            }
            $proof_path = 'uploads/payment_proofs/' . $filename;
        }

        /* -------- Transaction: lock, decrement shop inventory, create order -------- */
        $db->beginTransaction();

        $lockStmt = $db->prepare("
            SELECT id, name, stock, COALESCE(released_stock, 0) AS released_stock
            FROM products
            WHERE id = :pid
            FOR UPDATE
        ");

        // FIXED: Subtract from released_stock (shop inventory)
        $relStmt = $db->prepare("
            UPDATE products
            SET released_stock = COALESCE(released_stock, 0) - :qty
            WHERE id = :pid
              AND COALESCE(released_stock, 0) >= :qty_check
        ");

        foreach ($cart_items as $it) {
            $pid = (int)$it['product_id'];
            $qty = (int)$it['quantity'];

            if ($pid <= 0 || $qty <= 0) {
                throw new Exception('Invalid item in cart. Please refresh and try again.');
            }

            $lockStmt->execute([':pid' => $pid]);
            $row = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception('A product in your cart is no longer available.');
            }

            // Available is now just the released_stock
            $available = max(0, (int)$row['released_stock']);
            if ($available < $qty) {
                throw new Exception(
                    'Sorry — only ' . $available . ' of "' . $row['name'] .
                    '" remain available. Please update your cart.'
                );
            }

            $relStmt->execute([
                ':qty'       => $qty,
                ':pid'       => $pid,
                ':qty_check' => $qty,
            ]);

            if ($relStmt->rowCount() !== 1) {
                throw new Exception('Stock changed during checkout. Please try again.');
            }
        }

        $stmt = $db->prepare("
            INSERT INTO orders
                (user_id, total_amount, status, payment_method, payment_reference, payment_proof,
                 shipping_address, phone, notes, created_at)
            VALUES
                (:uid, :total, 'pending', :method, :ref, :proof,
                 :addr, :phone, :notes, NOW())
        ");
        $stmt->execute([
            ':uid'    => $user_id,
            ':total'  => $total,
            ':method' => $method,
            ':ref'    => $gcash_reference,
            ':proof'  => $proof_path,
            ':addr'   => $address,
            ':phone'  => $phone,
            ':notes'  => $notes,
        ]);
        $order_id = (int)$db->lastInsertId();

        $itemStmt = $db->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, price)
            VALUES (:oid, :pid, :qty, :price)
        ");
        foreach ($cart_items as $it) {
            $itemStmt->execute([
                ':oid'   => $order_id,
                ':pid'   => (int)$it['product_id'],
                ':qty'   => (int)$it['quantity'],
                ':price' => (float)$it['price'],
            ]);
        }

        $db->prepare("DELETE FROM cart WHERE user_id = :uid")->execute([':uid' => $user_id]);
        $db->commit();

        /* ---------- NOTIFICATIONS (buyer + admins) ---------- */
        $order_ref = '#' . str_pad((string)$order_id, 6, '0', STR_PAD_LEFT);
        $method_label = $method === 'gcash' ? 'GCash' : 'Cash on Delivery';

        rjewel_notify(
            $db,
            $user_id,
            'order',
            'Order placed successfully',
            'Your order ' . $order_ref . ' was received. Payment: ' . $method_label .
                ' · Total: ₱' . number_format($total, 2) .
                '. We\'ll update you as it moves along.',
            'order-details.php?id=' . $order_id
        );

        rjewel_notify_admins(
            $db,
            'order',
            'New order received',
            'Customer placed order ' . $order_ref . ' (' . $method_label . ') — total ₱' .
                number_format($total, 2) . '.',
            '../admin/orders.php'
        );

        $response = [
            'success'  => true,
            'message'  => $method === 'gcash'
                ? 'Order placed! We will verify your GCash payment shortly.'
                : 'Order placed! Please prepare cash upon delivery.',
            'order_id' => $order_id,
        ];
    } catch (Throwable $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        if (!empty($proof_path) && !empty($filename)) {
            $f = __DIR__ . '/../uploads/payment_proofs/' . $filename;
            if (is_file($f)) @unlink($f);
        }
        $response = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

/* ------------------------------------------------------------------
   4. Fetch user & cart
   ------------------------------------------------------------------ */
$user_rows = safe_query($db, "SELECT * FROM users WHERE id = :uid", [':uid' => $user_id]);
$user = $user_rows[0] ?? ['full_name' => 'Customer', 'email' => '', 'phone' => '', 'address' => ''];

$cart_items = safe_query($db, "
    SELECT c.*, p.name, p.price, p.image, p.stock, p.category_id,
           COALESCE(p.released_stock, 0) AS released_stock
    FROM cart c JOIN products p ON c.product_id = p.id
    WHERE c.user_id = :uid ORDER BY c.id DESC
", [':uid' => $user_id]);

if (empty($cart_items)) {
    header("Location: cart.php");
    exit();
}

/* ------------------------------------------------------------------
   5. Totals (1% tax)
   ------------------------------------------------------------------ */
$subtotal     = 0;
$total_items  = 0;
foreach ($cart_items as $item) {
    $subtotal    += $item['price'] * $item['quantity'];
    $total_items += $item['quantity'];
}
$free_threshold = 100;
$shipping       = ($subtotal >= $free_threshold) ? 0 : 10;
$tax            = $subtotal * 0.01;
$total          = $subtotal + $shipping + $tax;

$error_msg = $_SESSION['error'] ?? '';
unset($_SESSION['error']);

$page_title = 'Checkout | R-Jewel&Gold';
?>
<?php include '../includes/header.php'; ?>

<style>
    :root {
        --bg: #f2ede4; --bg-soft: #faf7f2; --card: #ffffff;
        --border: #e5ddd0; --border-light: #ede7db;
        --text: #5a5245; --text-bright: #2b2620; --text-muted: #8a8172; --text-dim: #b3a998;
        --primary: <?php echo htmlspecialchars($accent); ?>;
        --primary-soft: <?php echo htmlspecialchars($accent); ?>18;
        --gold: #b8941f; --gold-soft: rgba(184,148,31,0.1); --gold-strong: #8a6e14;
        --success: #0d9668; --danger: #dc2626; --info: #5b5fd4;
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

    body, h1, h2, h3, h4, h5, h6,
    .checkout-hero h1, .card-title .title-text,
    .payment-method .method-name, .summary-head h4,
    .order-item-name, .order-item-total, .summary-row .value, .loading-text {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    body { background: var(--bg); color: var(--text); transition: background-color .3s, color .3s; }
    h1, h2, h3, h4, h5, h6 { color: var(--text-bright); font-weight: 600; }

    .checkout-hero {
        position: relative; background: var(--bg);
        background-image:
            radial-gradient(ellipse at 20% 20%, rgba(184,148,31,0.14), transparent 55%),
            radial-gradient(ellipse at 80% 80%, rgba(91,95,212,0.08), transparent 55%);
        overflow: hidden; padding: 2.5rem 1.5rem 2rem; text-align: center;
        border-bottom: 1px solid var(--border-light);
    }
    .checkout-hero::before {
        content: ''; position: absolute; inset: 0;
        background-image:
            radial-gradient(1.5px 1.5px at 15% 25%, rgba(184,148,31,.35), transparent),
            radial-gradient(1px 1px at 45% 60%, rgba(138,129,114,.25), transparent),
            radial-gradient(1.5px 1.5px at 75% 15%, rgba(184,148,31,.3), transparent),
            radial-gradient(1px 1px at 85% 75%, rgba(138,129,114,.2), transparent);
        pointer-events: none;
    }
    .checkout-hero > * { position: relative; z-index: 2; }
    .hero-diamond { width: 48px; height: 48px; margin: 0 auto .6rem; }
    .hero-diamond svg { width: 100%; height: 100%; filter: drop-shadow(0 6px 20px rgba(184,148,31,.4)); }
    .checkout-hero .eyebrow { font-size: .62rem; font-weight: 700; letter-spacing: .4em; text-transform: uppercase; color: var(--gold); margin-bottom: .5rem; }
    .checkout-hero h1 { font-size: clamp(1.7rem, 4vw, 2.4rem); margin-bottom: .4rem; line-height: 1.1; }
    .checkout-hero h1 em { color: var(--gold); font-style: italic; font-weight: 500; }
    .checkout-hero p { color: var(--text-muted); max-width: 480px; margin: 0 auto; font-size: .88rem; }
    .hero-divider { display: flex; align-items: center; justify-content: center; gap: 12px; max-width: 160px; margin: .85rem auto 0; }
    .hero-divider .line { flex: 1; height: 1px; background: linear-gradient(90deg, transparent, rgba(184,148,31,.6), transparent); }
    .hero-divider .diamond { width: 6px; height: 6px; background: var(--gold); transform: rotate(45deg); box-shadow: 0 0 10px rgba(184,148,31,.7); }

    .btn-lux {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        padding: .7rem 1.4rem; border-radius: 9px; border: none;
        font-family: 'Inter', sans-serif; font-size: .72rem; font-weight: 600;
        letter-spacing: .2em; text-transform: uppercase; text-decoration: none;
        cursor: pointer; transition: all .3s; white-space: nowrap;
    }
    .btn-lux-gold { background: linear-gradient(135deg, var(--gold), var(--gold-strong)); color: #fff; box-shadow: 0 4px 14px -3px rgba(184,148,31,.45); }
    .btn-lux-gold:hover { color: #fff; transform: translateY(-2px); box-shadow: 0 8px 22px -5px rgba(184,148,31,.6); }
    .btn-lux-gold:disabled { opacity: .7; cursor: not-allowed; transform: none; }

    .checkout-container { padding: 2rem 0 3rem; }

    .checkout-card {
        background: var(--card); border: 1px solid var(--border); border-radius: 14px;
        padding: 1.35rem 1.5rem; margin-bottom: 1rem; transition: border-color .3s;
    }
    .checkout-card:hover { border-color: rgba(184,148,31,.35); }

    .card-title {
        display: flex; align-items: center; gap: 14px;
        margin-bottom: 1.25rem; padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-light);
    }
    .card-title .icon-circle {
        width: 52px; height: 52px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: var(--gold-soft); border: 1px solid rgba(184,148,31,.25);
        color: var(--gold); font-size: 1.25rem; flex-shrink: 0;
    }
    .card-title .title-text {
        font-size: 2rem; font-weight: 700; color: var(--text-bright);
        line-height: 1.15; letter-spacing: -0.02em;
    }
    .card-title .title-text em { color: var(--gold); font-style: italic; font-weight: 600; }

    .form-group { margin-bottom: .9rem; }
    .form-group label {
        display: block; margin-bottom: .35rem; font-weight: 600;
        color: var(--text-bright); font-size: .72rem;
        letter-spacing: .15em; text-transform: uppercase;
    }
    .form-control {
        width: 100%; padding: .7rem .9rem; border: 1px solid var(--border);
        border-radius: 9px; background: var(--bg-soft); color: var(--text-bright);
        font-family: 'Inter', sans-serif; font-size: .9rem; transition: all .25s;
    }
    .form-control::placeholder { color: var(--text-dim); }
    .form-control:focus {
        outline: none; border-color: var(--gold); background: var(--card);
        box-shadow: 0 0 0 3px rgba(184,148,31,.12);
    }
    .form-control.error { border-color: var(--danger); box-shadow: 0 0 0 3px rgba(220,38,38,.1); }
    textarea.form-control { resize: vertical; min-height: 60px; }

    .payment-methods { display: flex; flex-direction: column; gap: 10px; }
    .payment-method {
        position: relative; border: 1px solid var(--border); border-radius: 10px;
        padding: .85rem 1rem; cursor: pointer; transition: all .25s;
        display: flex; align-items: center; gap: 14px; background: var(--bg-soft);
    }
    .payment-method:hover { border-color: rgba(184,148,31,.5); background: var(--gold-soft); }
    .payment-method.selected { border-color: var(--gold); background: var(--gold-soft); box-shadow: 0 0 0 3px rgba(184,148,31,.1); }
    .payment-method input[type="radio"] { width: 18px; height: 18px; accent-color: var(--gold); cursor: pointer; flex-shrink: 0; }
    .payment-method .method-icon {
        font-size: 1.15rem; width: 40px; height: 40px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: var(--card); border: 1px solid var(--border);
        color: var(--gold); flex-shrink: 0; transition: all .25s;
    }
    .payment-method.selected .method-icon { background: linear-gradient(135deg, var(--gold), var(--gold-strong)); color: #fff; border-color: transparent; }
    .payment-method .method-info { flex: 1; min-width: 0; }
    .payment-method .method-name { font-weight: 600; font-size: 1.05rem; color: var(--text-bright); margin-bottom: .15rem; line-height: 1.2; }
    .payment-method .method-desc { font-size: .78rem; color: var(--text-muted); line-height: 1.35; }

    .gcash-details {
        margin-top: .6rem; padding: 1rem 1.1rem;
        background: var(--bg-soft); border: 1px solid var(--border);
        border-radius: 10px; display: none;
    }
    .gcash-details.active { display: block; animation: fadeIn .3s ease-out; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

    .gcash-instructions {
        background: linear-gradient(135deg, rgba(0,112,201,.08), rgba(0,112,201,.03));
        border: 1px solid rgba(0,112,201,.22); border-radius: 8px;
        padding: .75rem .95rem; margin-bottom: .85rem;
        font-size: .82rem; line-height: 1.6; color: var(--text-bright);
    }
    .gcash-instructions h6 {
        font-size: .68rem; font-weight: 700; letter-spacing: .12em;
        text-transform: uppercase; color: #0070c9; margin: 0 0 .35rem;
        display: flex; align-items: center; gap: 6px;
    }
    .gcash-instructions ol { margin: 0; padding-left: 1.15rem; }
    .gcash-instructions li { margin-bottom: .15rem; }
    .gcash-instructions strong { color: #0070c9; }
    .gcash-number {
        display: inline-flex; align-items: center; gap: 6px;
        background: var(--card); border: 1px dashed rgba(0,112,201,.4);
        border-radius: 6px; padding: 3px 10px; font-weight: 700;
        color: #0070c9; font-size: .85rem; margin: 2px 0;
    }
    .gcash-number i { font-size: .65rem; }

    .pay-form .form-group { margin-bottom: .65rem; }
    .pay-form .form-group:last-child { margin-bottom: 0; }
    .pay-form label {
        display: block; margin-bottom: .3rem; font-weight: 700;
        color: var(--text); font-size: .65rem;
        letter-spacing: .1em; text-transform: uppercase;
    }
    .pay-form input[type="text"] {
        width: 100%; padding: .55rem .8rem; border: 1px solid var(--border);
        border-radius: 7px; font-family: 'Inter', sans-serif;
        font-size: .85rem; background: var(--card); color: var(--text-bright);
        transition: border-color .2s, box-shadow .2s;
    }
    .pay-form input[type="text"]:focus {
        outline: none; border-color: var(--gold);
        box-shadow: 0 0 0 3px var(--gold-soft);
    }
    .pay-form input[type="text"].error { border-color: var(--danger); box-shadow: 0 0 0 3px rgba(220,38,38,.1); }

    .file-input-wrap {
        position: relative; display: block; width: 100%;
        border: 1px dashed var(--border); border-radius: 7px;
        background: var(--card); padding: .6rem .8rem;
        cursor: pointer; transition: all .2s;
        font-size: .8rem; color: var(--text-muted);
        text-align: center;
    }
    .file-input-wrap:hover { border-color: var(--gold); color: var(--gold); }
    .file-input-wrap.has-file { border-style: solid; border-color: var(--success); color: var(--success); }
    .file-input-wrap.error { border-color: var(--danger); color: var(--danger); }
    .file-input-wrap input[type="file"] {
        position: absolute; inset: 0; opacity: 0;
        cursor: pointer; width: 100%; height: 100%;
    }
    .file-input-wrap i { margin-right: 6px; }
    .file-input-preview { margin-top: .55rem; text-align: center; display: none; }
    .file-input-preview.show { display: block; }
    .file-input-preview img {
        max-height: 100px; max-width: 100%; border-radius: 6px;
        border: 1px solid var(--border);
    }
    .file-input-preview .filename {
        display: block; font-size: .7rem; color: var(--text-muted);
        margin-top: .3rem; word-break: break-all;
    }

    .secure-inline {
        display: flex; align-items: center; gap: 7px;
        font-size: .72rem; color: var(--text-muted); margin-top: .6rem;
    }
    .secure-inline i { color: var(--gold); }

    .cod-details {
        margin-top: .6rem; padding: .85rem 1rem;
        background: var(--bg-soft); border: 1px solid var(--border);
        border-radius: 10px; display: none;
        font-size: .85rem; line-height: 1.55; color: var(--text-bright);
    }
    .cod-details.active { display: block; animation: fadeIn .3s ease-out; }
    .cod-details .cod-head {
        display: flex; align-items: center; gap: 7px;
        font-weight: 700; color: var(--gold-strong); margin-bottom: .25rem;
        font-size: .72rem; letter-spacing: .12em; text-transform: uppercase;
    }
    .cod-details .cod-head i { color: var(--gold); }
    .cod-details small { color: var(--text-muted); }

    .order-summary {
        background: var(--card); border: 1px solid var(--border);
        border-radius: 14px; padding: 1.5rem;
        position: sticky; top: 20px; transition: border-color .3s;
    }
    .order-summary:hover { border-color: rgba(184,148,31,.35); }

    .summary-head {
        margin-bottom: 1.15rem; padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-light);
    }
    .summary-head .eyebrow {
        font-size: .68rem; font-weight: 700; letter-spacing: .35em;
        text-transform: uppercase; color: var(--gold); margin-bottom: .35rem;
    }
    .summary-head h4 {
        font-size: 2rem; font-weight: 700; margin: 0;
        letter-spacing: -0.02em; line-height: 1.15;
    }
    .summary-head h4 em { color: var(--gold); font-style: italic; font-weight: 600; }

    .order-items {
        max-height: 300px; overflow-y: auto; margin-bottom: .9rem; padding-right: 4px;
    }
    .order-items::-webkit-scrollbar { width: 4px; }
    .order-items::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

    .order-item {
        display: flex; gap: 11px; padding: .65rem 0;
        border-bottom: 1px solid var(--border-light); align-items: center;
    }
    .order-item:last-child { border-bottom: none; }
    .order-item img {
        width: 52px; height: 52px; object-fit: cover;
        border-radius: 8px; border: 1px solid var(--border);
        background: var(--bg-soft); flex-shrink: 0;
    }
    .order-item-details { flex: 1; min-width: 0; }
    .order-item-name {
        font-size: .95rem; font-weight: 600; color: var(--text-bright);
        margin-bottom: .1rem; line-height: 1.2;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .order-item-meta { font-size: .72rem; color: var(--text-muted); letter-spacing: .05em; }
    .order-item-total { font-size: .95rem; font-weight: 600; color: var(--gold); white-space: nowrap; }

    .summary-row { display: flex; justify-content: space-between; padding: .55rem 0; font-size: .9rem; }
    .summary-row .label { color: var(--text-muted); }
    .summary-row .value { font-size: 1rem; font-weight: 600; color: var(--text-bright); }
    .summary-row.total {
        border-top: 1px dashed var(--border); margin-top: .45rem; padding-top: .9rem;
    }
    .summary-row.total .label {
        font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
        font-size: .74rem; color: var(--text-bright); align-self: center;
    }
    .summary-row.total .value { font-size: 1.55rem; color: var(--gold); }

    .free-shipping {
        background: var(--gold-soft); border: 1px solid rgba(184,148,31,.25);
        border-radius: 10px; padding: .7rem .9rem; margin-top: .9rem;
        font-size: .82rem; color: var(--text-bright);
        display: flex; align-items: center; gap: 9px;
    }
    .free-shipping i { color: var(--gold); font-size: 1rem; }

    .delivery-note {
        text-align: center; margin-top: .9rem; font-size: .76rem;
        color: var(--text-muted); display: flex;
        align-items: center; justify-content: center; gap: 6px;
    }
    .delivery-note i { color: var(--gold); }

    .secure-badge {
        text-align: center; margin-top: 1rem; padding-top: 1rem;
        border-top: 1px solid var(--border-light); font-size: .76rem;
        color: var(--text-muted); display: flex;
        align-items: center; justify-content: center; gap: 7px;
    }
    .secure-badge i { color: var(--success); }

    .place-order-btn { width: 100%; padding: 1rem; font-size: .82rem; letter-spacing: .25em; }

    .loading-overlay {
        position: fixed; inset: 0; background: rgba(20,18,15,.75);
        backdrop-filter: blur(4px); z-index: 9999;
        display: none; justify-content: center; align-items: center; flex-direction: column;
    }
    .loading-overlay.active { display: flex; }
    .loading-spinner {
        width: 52px; height: 52px; border: 3px solid rgba(184,148,31,.2);
        border-top-color: var(--gold); border-radius: 50%;
        animation: spin .9s linear infinite; margin-bottom: 1rem;
    }
    .loading-text { color: #fff; font-size: 1.2rem; letter-spacing: .1em; }
    @keyframes spin { to { transform: rotate(360deg); } }

    .error-message { color: var(--danger); font-size: .72rem; margin-top: .3rem; display: none; }
    .error-message.show { display: block; }

    .toast-notification {
        position: fixed; bottom: 24px; right: 24px; z-index: 9999;
        animation: slideInRight .35s ease-out; border-radius: 10px;
        overflow: hidden; box-shadow: 0 12px 30px rgba(0,0,0,.15);
        background: var(--card); border: 1px solid var(--border); min-width: 260px;
    }
    .toast-notification .toast-body {
        padding: .85rem 1rem; font-size: .85rem; font-weight: 500;
        display: flex; align-items: center; gap: 10px;
    }
    .toast-notification.success .toast-body { color: var(--success); }
    .toast-notification.error   .toast-body { color: var(--danger); }
    @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

    @media (max-width: 991px) {
        .order-summary { position: static; margin-top: 1rem; }
    }
    @media (max-width: 768px) {
        .checkout-hero { padding: 2rem 1rem 1.5rem; }
        .checkout-container { padding: 1.5rem 0 2rem; }
        .checkout-card { padding: 1.1rem 1.15rem; }
        .card-title .icon-circle { width: 46px; height: 46px; font-size: 1.1rem; }
        .card-title .title-text { font-size: 1.55rem; }
        .summary-head h4 { font-size: 1.55rem; }
        .summary-row.total .value { font-size: 1.3rem; }
    }
    @media (max-width: 480px) {
        .card-title .icon-circle { width: 42px; height: 42px; font-size: 1rem; }
        .card-title .title-text { font-size: 1.35rem; }
        .summary-head h4 { font-size: 1.35rem; }
        .order-item img { width: 46px; height: 46px; }
        .order-item-name { font-size: .88rem; }
        .order-item-total { font-size: .88rem; }
    }
    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
</style>

<section class="checkout-hero">
    <div class="hero-diamond">
        <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M50 5 L90 35 L50 95 L10 35 Z" stroke="#b8941f" stroke-width="1.8" fill="none" opacity="0.95"/>
            <path d="M50 5 L90 35 L50 35 Z" fill="#b8941f" opacity="0.18"/>
            <path d="M50 5 L10 35 L50 35 Z" fill="#b8941f" opacity="0.1"/>
            <path d="M10 35 L50 95 L50 35 Z" fill="#b8941f" opacity="0.06"/>
            <path d="M90 35 L50 95 L50 35 Z" fill="#b8941f" opacity="0.12"/>
            <path d="M10 35 L90 35" stroke="#b8941f" stroke-width="1.2" opacity="0.75"/>
            <circle cx="50" cy="5" r="2.2" fill="#b8941f"/>
            <circle cx="90" cy="35" r="1.6" fill="#b8941f" opacity="0.85"/>
            <circle cx="10" cy="35" r="1.6" fill="#b8941f" opacity="0.85"/>
            <circle cx="50" cy="95" r="2.2" fill="#b8941f"/>
        </svg>
    </div>
    <p class="eyebrow">R-Jewel&Gold · Final Step</p>
    <h1>Secure <em>Checkout</em></h1>
    <p>Complete your purchase with confidence</p>
    <div class="hero-divider">
        <span class="line"></span>
        <span class="diamond"></span>
        <span class="line"></span>
    </div>
</section>

<div class="checkout-container">
    <div class="container">

        <?php if ($error_msg): ?>
            <div style="background:rgba(220,38,38,0.08);border:1px solid rgba(220,38,38,0.3);color:#dc2626;padding:.85rem 1.1rem;border-radius:10px;margin-bottom:1rem;font-size:.85rem;">
                <i class="fas fa-exclamation-circle" style="margin-right:6px;"></i>
                <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-7">
                <form id="checkoutForm" action="checkout.php" method="POST" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="ajax_place_order" value="1">

                    <div class="checkout-card">
                        <div class="card-title">
                            <div class="icon-circle"><i class="fas fa-truck"></i></div>
                            <div class="title-text">Shipping <em>Information</em></div>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Full Name *</label>
                                    <input type="text" name="full_name" class="form-control"
                                           value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                                    <div class="error-message">Full name is required</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Email Address *</label>
                                    <input type="email" name="email" class="form-control"
                                           value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                                    <div class="error-message">Email is required</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Phone Number *</label>
                                    <input type="tel" name="phone" class="form-control"
                                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                                    <div class="error-message">Phone is required</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Shipping Address *</label>
                            <textarea name="address" class="form-control" rows="2" required><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                            <div class="error-message">Shipping address is required</div>
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label>Order Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Special instructions..."></textarea>
                        </div>
                    </div>

                    <div class="checkout-card">
                        <div class="card-title">
                            <div class="icon-circle"><i class="fas fa-credit-card"></i></div>
                            <div class="title-text">Payment <em>Method</em></div>
                        </div>

                        <div class="payment-methods">
                            <label class="payment-method selected" data-method="gcash">
                                <input type="radio" name="payment_method" value="gcash" checked>
                                <div class="method-icon"><i class="fas fa-mobile-alt"></i></div>
                                <div class="method-info">
                                    <div class="method-name">GCash</div>
                                    <div class="method-desc">Send payment & upload your receipt</div>
                                </div>
                            </label>

                            <div class="gcash-details active" id="gcashDetails">
                                <div class="gcash-instructions">
                                    <h6><i class="fas fa-info-circle"></i> How to pay via GCash</h6>
                                    <ol>
                                        <li>Open GCash → tap <strong>Send Money</strong>.</li>
                                        <li>Send the exact total to:
                                            <span class="gcash-number"><i class="fas fa-phone"></i> 0917 123 4567</span>
                                            <br><small>Account: <strong>R-Jewel &amp; Gold</strong></small>
                                        </li>
                                        <li>Take a screenshot of the receipt.</li>
                                        <li>Upload it below, then place your order.</li>
                                    </ol>
                                </div>

                                <div class="pay-form">
                                    <div class="form-group">
                                        <label>GCash Reference Number *</label>
                                        <input type="text" name="gcash_reference" id="gcashReference"
                                               placeholder="e.g. 1234 5678 9012" inputmode="numeric" maxlength="20">
                                        <div class="error-message">GCash reference number is required</div>
                                    </div>

                                    <div class="form-group">
                                        <label>Proof of Payment (Screenshot) *</label>
                                        <label class="file-input-wrap" id="gcashProofWrap">
                                            <input type="file" name="gcash_proof" id="gcashProof"
                                                   accept="image/png,image/jpeg,image/jpg,image/webp">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                            <span id="gcashProofText">Tap to upload your receipt</span>
                                        </label>
                                        <div class="error-message">Proof of payment is required</div>
                                        <div class="file-input-preview" id="gcashProofPreview">
                                            <img id="gcashProofImg" src="" alt="Proof preview">
                                            <span class="filename" id="gcashProofName"></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="secure-inline">
                                    <i class="fas fa-lock"></i> Encrypted & only visible to our team
                                </div>
                            </div>

                            <label class="payment-method" data-method="cod">
                                <input type="radio" name="payment_method" value="cod">
                                <div class="method-icon"><i class="fas fa-hand-holding-usd"></i></div>
                                <div class="method-info">
                                    <div class="method-name">Cash on Delivery</div>
                                    <div class="method-desc">Pay in cash when your order arrives</div>
                                </div>
                            </label>

                            <div class="cod-details" id="codDetails">
                                <div class="cod-head">
                                    <i class="fas fa-truck"></i> Pay when your order arrives
                                </div>
                                Prepare the exact amount of <strong>₱<?php echo number_format($total, 2); ?></strong> for the rider.
                                <br>
                                <small>COD may not be available in some remote areas.</small>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-lux btn-lux-gold place-order-btn" id="placeOrderBtn">
                        <i class="fas fa-lock"></i> Place Order
                    </button>
                </form>
            </div>

            <div class="col-lg-5">
                <div class="order-summary">
                    <div class="summary-head">
                        <p class="eyebrow">Your Order</p>
                        <h4>Order <em>Summary</em></h4>
                    </div>

                    <div class="order-items">
                        <?php foreach ($cart_items as $item): ?>
                        <div class="order-item">
                            <img src="../uploads/<?php echo htmlspecialchars($item['image'] ?? 'placeholder.jpg'); ?>"
                                 onerror="this.onerror=null;this.src='https://via.placeholder.com/80x80/ede7db/8a8172?text=J'"
                                 alt="<?php echo htmlspecialchars($item['name']); ?>"
                                 loading="lazy">
                            <div class="order-item-details">
                                <div class="order-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="order-item-meta">₱<?php echo number_format((float)$item['price'], 2); ?> · Qty <?php echo (int)$item['quantity']; ?></div>
                            </div>
                            <div class="order-item-total">
                                ₱<?php echo number_format((float)$item['price'] * (int)$item['quantity'], 2); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="summary-row">
                        <span class="label">Subtotal (<?php echo (int)$total_items; ?> item<?php echo $total_items == 1 ? '' : 's'; ?>)</span>
                        <span class="value">₱<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="label">Shipping</span>
                        <span class="value"><?php echo $shipping > 0 ? '₱' . number_format($shipping, 2) : 'Free'; ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="label">Tax (1%)</span>
                        <span class="value">₱<?php echo number_format($tax, 2); ?></span>
                    </div>
                    <div class="summary-row total">
                        <span class="label">Total</span>
                        <span class="value">₱<?php echo number_format($total, 2); ?></span>
                    </div>

                    <?php if ($shipping > 0): ?>
                        <div class="free-shipping">
                            <i class="fas fa-truck"></i>
                            <span>Add ₱<?php echo number_format($free_threshold - $subtotal, 2); ?> more for <strong>FREE shipping</strong></span>
                        </div>
                    <?php else: ?>
                        <div class="free-shipping">
                            <i class="fas fa-check-circle"></i>
                            <span>You qualify for <strong>FREE shipping</strong>!</span>
                        </div>
                    <?php endif; ?>

                    <div class="delivery-note">
                        <i class="fas fa-clock"></i> Estimated delivery: 3–5 business days
                    </div>

                    <div class="secure-badge">
                        <i class="fas fa-shield-alt"></i> Secure SSL encrypted checkout
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-text">Processing your order…</div>
</div>

<script>
(function () {
    'use strict';

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

    var paymentMethods = document.querySelectorAll('.payment-method');
    var gcashDetails   = document.getElementById('gcashDetails');
    var codDetails     = document.getElementById('codDetails');

    function syncPanels(method) {
        if (method === 'gcash') {
            gcashDetails.classList.add('active');
            codDetails.classList.remove('active');
        } else {
            gcashDetails.classList.remove('active');
            codDetails.classList.add('active');
        }
    }

    paymentMethods.forEach(function (method) {
        var radio = method.querySelector('input[type="radio"]');
        if (radio.checked) method.classList.add('selected');

        method.addEventListener('click', function (e) {
            if (e.target.tagName !== 'INPUT') radio.checked = true;
            paymentMethods.forEach(function (m) { m.classList.remove('selected'); });
            method.classList.add('selected');
            syncPanels(radio.value);
        });
        radio.addEventListener('change', function () {
            paymentMethods.forEach(function (m) { m.classList.remove('selected'); });
            method.classList.add('selected');
            syncPanels(radio.value);
        });
    });

    var initialMethod = document.querySelector('input[name="payment_method"]:checked');
    if (initialMethod) syncPanels(initialMethod.value);

    var MAX_PROOF_BYTES = 5 * 1024 * 1024;
    var gcashProof       = document.getElementById('gcashProof');
    var gcashProofWrap   = document.getElementById('gcashProofWrap');
    var gcashProofText   = document.getElementById('gcashProofText');
    var gcashProofPreview= document.getElementById('gcashProofPreview');
    var gcashProofImg    = document.getElementById('gcashProofImg');
    var gcashProofName   = document.getElementById('gcashProofName');

    if (gcashProof) {
        gcashProof.addEventListener('change', function () {
            var file = this.files && this.files[0];
            if (!file) {
                gcashProofWrap.classList.remove('has-file', 'error');
                gcashProofPreview.classList.remove('show');
                gcashProofImg.src = '';
                gcashProofName.textContent = '';
                gcashProofText.textContent = 'Tap to upload your receipt';
                return;
            }
            if (file.size > MAX_PROOF_BYTES) {
                showNotification('Screenshot is too large (max 5MB).', 'error');
                this.value = '';
                gcashProofWrap.classList.remove('has-file');
                gcashProofWrap.classList.add('error');
                gcashProofPreview.classList.remove('show');
                gcashProofText.textContent = 'Tap to upload your receipt';
                return;
            }
            var allowed = ['image/png','image/jpeg','image/jpg','image/webp'];
            if (allowed.indexOf(file.type) === -1) {
                showNotification('Please upload a JPG, PNG, or WEBP image.', 'error');
                this.value = '';
                gcashProofWrap.classList.remove('has-file');
                gcashProofWrap.classList.add('error');
                gcashProofPreview.classList.remove('show');
                gcashProofText.textContent = 'Tap to upload your receipt';
                return;
            }
            gcashProofWrap.classList.remove('error');
            gcashProofWrap.classList.add('has-file');
            gcashProofText.textContent = 'Screenshot attached ✓';
            gcashProofName.textContent = file.name;

            var reader = new FileReader();
            reader.onload = function (ev) {
                gcashProofImg.src = ev.target.result;
                gcashProofPreview.classList.add('show');
            };
            reader.readAsDataURL(file);

            var err = gcashProofWrap.parentNode.querySelector('.error-message');
            if (err) err.classList.remove('show');
        });
    }

    var gcashReference = document.getElementById('gcashReference');
    if (gcashReference) {
        gcashReference.addEventListener('input', function () {
            this.value = this.value.replace(/[^\d\s\-]/g, '').slice(0, 20);
            this.classList.remove('error');
            var err = this.parentNode.querySelector('.error-message');
            if (err) err.classList.remove('show');
        });
    }

    document.querySelectorAll('.form-control').forEach(function (input) {
        input.addEventListener('input', function () {
            this.classList.remove('error');
            var err = this.parentNode.querySelector('.error-message');
            if (err) err.classList.remove('show');
        });
    });

    var form           = document.getElementById('checkoutForm');
    var loadingOverlay = document.getElementById('loadingOverlay');
    var placeOrderBtn  = document.getElementById('placeOrderBtn');

    function validateRequiredFields() {
        var ok = true;
        form.querySelectorAll('[required]').forEach(function (field) {
            if (!field.value.trim()) {
                field.classList.add('error');
                var err = field.parentNode.querySelector('.error-message');
                if (err) err.classList.add('show');
                ok = false;
            } else {
                field.classList.remove('error');
                var err = field.parentNode.querySelector('.error-message');
                if (err) err.classList.remove('show');
            }
        });
        return ok;
    }

    function validateGCash() {
        var method = form.querySelector('input[name="payment_method"]:checked');
        if (!method || method.value !== 'gcash') return true;
        var ok = true;

        if (!gcashReference.value.trim()) {
            gcashReference.classList.add('error');
            var refErr = gcashReference.parentNode.querySelector('.error-message');
            if (refErr) refErr.classList.add('show');
            ok = false;
        } else {
            gcashReference.classList.remove('error');
            var refErr2 = gcashReference.parentNode.querySelector('.error-message');
            if (refErr2) refErr2.classList.remove('show');
        }

        var hasFile = gcashProof.files && gcashProof.files.length > 0;
        if (!hasFile) {
            gcashProofWrap.classList.add('error');
            var proofErr = gcashProofWrap.parentNode.querySelector('.error-message');
            if (proofErr) proofErr.classList.add('show');
            ok = false;
        } else {
            gcashProofWrap.classList.remove('error');
            var proofErr2 = gcashProofWrap.parentNode.querySelector('.error-message');
            if (proofErr2) proofErr2.classList.remove('show');
        }
        return ok;
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var okFields = validateRequiredFields();
            var okGcash  = validateGCash();

            if (!okFields || !okGcash) {
                showNotification('Please complete the highlighted fields.', 'error');
                var firstErr = form.querySelector('.form-control.error, .file-input-wrap.error');
                if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            loadingOverlay.classList.add('active');
            placeOrderBtn.disabled = true;

            fetch('checkout.php', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    showNotification(data.message, 'success');
                    if (typeof window.updateCartCount === 'function') window.updateCartCount();
                    if (typeof window.refreshMessageCount === 'function') {
                        try { window.refreshMessageCount(); } catch (e) {}
                    }
                    setTimeout(function () {
                        window.location.href = 'order-success.php?id=' + data.order_id;
                    }, 900);
                } else {
                    loadingOverlay.classList.remove('active');
                    placeOrderBtn.disabled = false;
                    showNotification((data && data.message) || 'Could not place order.', 'error');
                }
            })
            .catch(function () {
                loadingOverlay.classList.remove('active');
                placeOrderBtn.disabled = false;
                showNotification('Network error. Please try again.', 'error');
            });
        });
    }
})();
</script>

<?php include '../includes/footer.php'; ?>