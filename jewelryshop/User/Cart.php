<?php
// user/cart.php — Luxury Shopping Cart Page
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

$user_id = (int)($_SESSION['user_id'] ?? 0);

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
   3b. Pricing helpers — shipping & loyalty discount
   ------------------------------------------------------------------ */
/**
 * Shipping rule:
 *  - 1 item  → ₱150 shipping fee
 *  - 2+ items → FREE shipping
 *  - 0 items → FREE (nothing to ship)
 */
function calculate_shipping($total_items) {
    if ($total_items <= 0) return 0;
    if ($total_items === 1) return 150;
    return 0;
}

/**
 * Loyalty shop discount (based on total quantity of items in the cart):
 *  - 4+ items → 8%
 *  - 3 items  → 5%
 *  - 2 items  → 3%
 *  - 1 item   → 0%
 * Returns the discount percentage as a float.
 */
function calculate_discount_percent($total_items) {
    if ($total_items >= 4) return 8.0;
    if ($total_items >= 3) return 5.0;
    if ($total_items >= 2) return 3.0;
    return 0.0;
}

/**
 * Full totals calculation used everywhere (initial page load + all AJAX actions).
 */
function calculate_totals($items) {
    $subtotal    = 0;
    $total_items = 0;

    foreach ($items as $it) {
        $qty = isset($it['cart_quantity']) ? (int)$it['cart_quantity'] : (int)$it['quantity'];
        $subtotal    += (float)$it['price'] * $qty;
        $total_items += $qty;
    }

    $shipping         = calculate_shipping($total_items);
    $discount_percent = calculate_discount_percent($total_items);
    $discount_amount  = $subtotal * ($discount_percent / 100);
    $total            = $subtotal - $discount_amount + $shipping;

    return [
        'subtotal'         => $subtotal,
        'total_items'      => $total_items,
        'shipping'         => $shipping,
        'discount_percent' => $discount_percent,
        'discount_amount'  => $discount_amount,
        'total'            => $total,
    ];
}

/* ------------------------------------------------------------------
   4. Handle AJAX POST actions (return JSON)
   ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {

    // --- AJAX update single item quantity ---
    if (isset($_POST['ajax_update_qty'])) {
        header('Content-Type: application/json');
        $cart_id  = (int)($_POST['cart_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 1);
        $response = ['success' => false];

        if ($quantity > 0 && $cart_id > 0) {
            // Stock check using released_stock
            $stockCheck = safe_query($db,
                "SELECT COALESCE(p.released_stock, 0) AS released_stock FROM cart c JOIN products p ON c.product_id = p.id WHERE c.id = :cid AND c.user_id = :uid",
                [':cid' => $cart_id, ':uid' => $user_id]);
            
            if (!empty($stockCheck)) {
                $maxStock = (int)$stockCheck[0]['released_stock'];
                if ($quantity > $maxStock) $quantity = $maxStock;

                safe_exec($db,
                    "UPDATE cart SET quantity = :q WHERE id = :cid AND user_id = :uid",
                    [':q' => $quantity, ':cid' => $cart_id, ':uid' => $user_id]);

                // Fetch updated items
                $items = safe_query($db, "
                    SELECT c.*, p.price, COALESCE(p.released_stock, 0) AS released_stock, c.quantity AS cart_quantity
                    FROM cart c JOIN products p ON c.product_id = p.id
                    WHERE c.user_id = :uid
                ", [':uid' => $user_id]);

                $totals = calculate_totals($items);

                // Get the individual item subtotal and its available stock
                $itemPrice = 0;
                $availableStock = 0;
                $itemCartQty = 0;
                foreach ($items as $it) {
                    if ((int)$it['id'] === $cart_id) {
                        $itemPrice      = (float)$it['price'] * (int)$it['cart_quantity'];
                        $availableStock = (int)$it['released_stock'] - (int)$it['cart_quantity'];
                        $itemCartQty    = (int)$it['cart_quantity'];
                        break;
                    }
                }

                $response = [
                    'success'            => true,
                    'cart_id'            => $cart_id,
                    'quantity'           => $quantity,
                    'item_subtotal'      => number_format($itemPrice, 2),
                    'available_stock'    => $availableStock,
                    'item_cart_qty'      => $itemCartQty,
                    'subtotal'           => number_format($totals['subtotal'], 2),
                    'total_items'        => $totals['total_items'],
                    'total_items_label'  => $totals['total_items'] . ' item' . ($totals['total_items'] == 1 ? '' : 's'),
                    'shipping'           => $totals['shipping'] > 0 ? '₱' . number_format($totals['shipping'], 2) : 'Free',
                    'shipping_raw'       => $totals['shipping'],
                    'discount_percent'   => $totals['discount_percent'],
                    'discount_amount'    => number_format($totals['discount_amount'], 2),
                    'discount_label'     => $totals['discount_percent'] > 0 ? $totals['discount_percent'] . '%' : '—',
                    'total'              => number_format($totals['total'], 2),
                    'subtotal_raw'       => $totals['subtotal'],
                ];
            }
        }
        echo json_encode($response);
        exit();
    }

    // --- AJAX remove single item ---
    if (isset($_POST['ajax_remove_item'])) {
        header('Content-Type: application/json');
        $cart_id = (int)($_POST['cart_id'] ?? 0);
        if ($cart_id > 0) {
            safe_exec($db,
                "DELETE FROM cart WHERE id = :cid AND user_id = :uid",
                [':cid' => $cart_id, ':uid' => $user_id]);
        }
        echo json_encode(['success' => true]);
        exit();
    }

    // --- AJAX clear entire cart ---
    if (isset($_POST['ajax_clear_cart'])) {
        header('Content-Type: application/json');
        safe_exec($db, "DELETE FROM cart WHERE user_id = :uid", [':uid' => $user_id]);
        echo json_encode(['success' => true]);
        exit();
    }

    // --- Non-AJAX fallback (update_cart, remove_item, clear_cart, bulk_update) ---
    if (isset($_POST['update_cart'])) {
        $cart_id  = (int)($_POST['cart_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 1);
        if ($quantity > 0 && $cart_id > 0) {
            safe_exec($db,
                "UPDATE cart SET quantity = :q WHERE id = :cid AND user_id = :uid",
                [':q' => $quantity, ':cid' => $cart_id, ':uid' => $user_id]);
        }
    }

    if (isset($_POST['remove_item'])) {
        $cart_id = (int)($_POST['cart_id'] ?? 0);
        if ($cart_id > 0) {
            safe_exec($db,
                "DELETE FROM cart WHERE id = :cid AND user_id = :uid",
                [':cid' => $cart_id, ':uid' => $user_id]);
        }
    }

    if (isset($_POST['clear_cart'])) {
        safe_exec($db, "DELETE FROM cart WHERE user_id = :uid", [':uid' => $user_id]);
        header("Location: cart.php");
        exit();
    }

    if (isset($_POST['bulk_update'])) {
        $quantities = $_POST['quantities'] ?? [];
        if (is_array($quantities)) {
            foreach ($quantities as $cart_id => $qty) {
                $cart_id = (int)$cart_id;
                $qty = (int)$qty;
                if ($cart_id > 0 && $qty > 0) {
                    $stockCheck = safe_query($db,
                        "SELECT COALESCE(p.released_stock, 0) AS released_stock FROM cart c JOIN products p ON c.product_id = p.id WHERE c.id = :cid AND c.user_id = :uid",
                        [':cid' => $cart_id, ':uid' => $user_id]);
                    if (!empty($stockCheck)) {
                        $maxStock = (int)$stockCheck[0]['released_stock'];
                        if ($qty > $maxStock) $qty = $maxStock;
                    }
                    safe_exec($db,
                        "UPDATE cart SET quantity = :q WHERE id = :cid AND user_id = :uid",
                        [':q' => $qty, ':cid' => $cart_id, ':uid' => $user_id]);
                }
            }
        }
        header("Location: cart.php");
        exit();
    }
}

/* ------------------------------------------------------------------
   5. Fetch cart items
   ------------------------------------------------------------------ */
$cart_items = safe_query($db, "
    SELECT c.*, p.name, p.price, p.image, COALESCE(p.released_stock, 0) AS released_stock, p.category_id, c.quantity AS cart_quantity
    FROM cart c
    JOIN products p ON c.product_id = p.id
    WHERE c.user_id = :uid
    ORDER BY c.id DESC
", [':uid' => $user_id]);

$totals = calculate_totals($cart_items);

$subtotal         = $totals['subtotal'];
$total_items      = $totals['total_items'];
$shipping         = $totals['shipping'];
$discount_percent = $totals['discount_percent'];
$discount_amount  = $totals['discount_amount'];
$total            = $totals['total'];

$category_ids = [];
foreach ($cart_items as $item) {
    if (!empty($item['category_id'])) $category_ids[] = (int)$item['category_id'];
}

/* ------------------------------------------------------------------
   6. Recommended products
   ------------------------------------------------------------------ */
$recommended = [];
if (!empty($category_ids)) {
    $category_ids = array_values(array_unique($category_ids));
    $placeholders = implode(',', array_fill(0, count($category_ids), '?'));
    $recommended = safe_query($db,
        "SELECT * FROM products WHERE category_id IN ($placeholders) AND COALESCE(released_stock, 0) > 0 ORDER BY RAND() LIMIT 4",
        $category_ids);
} else {
    $recommended = safe_query($db,
        "SELECT * FROM products WHERE featured = 1 AND COALESCE(released_stock, 0) > 0 ORDER BY RAND() LIMIT 4");
}

$page_title = 'Shopping Cart | R-Jewel&Gold';
?>
<?php include '../includes/header.php'; ?>

<style>
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
    .cart-hero h1, .summary-head h4,
    .cart-product-info h5, .product-price,
    .summary-row .value, .rec-product-name,
    .rec-product-price, .empty-cart h3,
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

    .cart-hero {
        position: relative;
        background: var(--bg);
        background-image:
            radial-gradient(ellipse at 20% 20%, rgba(184, 148, 31, 0.14), transparent 55%),
            radial-gradient(ellipse at 80% 80%, rgba(91, 95, 212, 0.08), transparent 55%);
        overflow: hidden;
        padding: 4rem 1.5rem 3.5rem;
        text-align: center;
        border-bottom: 1px solid var(--border-light);
    }
    .cart-hero::before {
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
    .cart-hero > * { position: relative; z-index: 2; }

    .hero-diamond {
        width: 62px; height: 62px;
        margin: 0 auto 1rem;
        display: flex; align-items: center; justify-content: center;
    }
    .hero-diamond svg {
        width: 100%; height: 100%;
        filter: drop-shadow(0 6px 20px rgba(184, 148, 31, 0.4));
        animation: floatDiamond 6s ease-in-out infinite;
    }
    @keyframes floatDiamond {
        0%, 100% { transform: translateY(0); }
        50%      { transform: translateY(-8px); }
    }
    .cart-hero .eyebrow {
        font-size: 0.68rem; font-weight: 700; letter-spacing: 0.4em;
        text-transform: uppercase; color: var(--gold); margin-bottom: 0.75rem;
    }
    .cart-hero h1 {
        font-size: clamp(2.1rem, 5vw, 3.2rem);
        margin-bottom: 0.75rem;
        line-height: 1.05;
    }
    .cart-hero h1 em { color: var(--gold); font-style: italic; font-weight: 500; }
    .cart-hero p {
        color: var(--text-muted);
        max-width: 520px;
        margin: 0 auto;
        font-size: 0.95rem;
    }
    .hero-divider {
        display: flex; align-items: center; justify-content: center; gap: 12px;
        max-width: 200px; margin: 1.25rem auto 0;
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

    .btn-lux {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 0.75rem 1.6rem;
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
        position: relative;
        overflow: hidden;
        white-space: nowrap;
    }
    .btn-lux-gold {
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff;
        box-shadow: 0 4px 14px -3px rgba(184, 148, 31, 0.45);
    }
    .btn-lux-gold::before {
        content: '';
        position: absolute;
        top: 0; left: -100%;
        width: 100%; height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
        transition: left 0.6s ease;
    }
    .btn-lux-gold:hover { color:#fff; transform: translateY(-2px); box-shadow: 0 8px 22px -5px rgba(184,148,31,.6); }
    .btn-lux-gold:hover::before { left: 100%; }

    .btn-lux-outline {
        background: transparent;
        color: var(--text-bright);
        border: 1px solid var(--border);
    }
    .btn-lux-outline:hover {
        border-color: var(--gold); color: var(--gold); transform: translateY(-2px);
    }
    .btn-lux-outline-danger {
        background: transparent;
        color: var(--danger);
        border: 1px solid rgba(220, 38, 38, 0.35);
    }
    .btn-lux-outline-danger:hover {
        background: var(--danger); color: #fff; border-color: var(--danger);
    }

    .cart-container { padding: 3.5rem 0 5rem; }

    .cart-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        overflow: hidden;
        transition: border-color 0.3s ease;
    }
    .cart-card:hover { border-color: rgba(184, 148, 31, 0.35); }

    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        width: 100%;
    }
    
    .cart-table { 
        width: 100%; 
        min-width: 100%;
        border-collapse: collapse; 
        table-layout: auto;
    }
    
    .cart-table th {
        background: var(--bg-soft);
        border-bottom: 1px solid var(--border);
        padding: 1rem 1.25rem;
        font-family: 'Inter', sans-serif;
        font-weight: 700;
        color: var(--text-muted);
        font-size: 0.68rem;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        text-align: left;
        white-space: nowrap;
    }
    
    .cart-table td {
        padding: 1.25rem;
        vertical-align: middle;
        border-bottom: 1px solid var(--border-light);
        font-size: 0.88rem;
        color: var(--text);
    }
    
    .cart-table tbody tr { transition: background 0.2s ease; }
    .cart-table tbody tr:hover { background: var(--gold-soft); }
    .cart-table tbody tr:last-child td { border-bottom: none; }

    .cart-product {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    .cart-product-image {
        width: 84px;
        height: 84px;
        border-radius: 12px;
        overflow: hidden;
        background: var(--bg-soft);
        border: 1px solid var(--border);
        flex-shrink: 0;
    }
    .cart-product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.4s ease;
        display: block;
    }
    .cart-product-image:hover img { transform: scale(1.08); }

    .cart-product-info h5 {
        font-size: 1.15rem;
        margin-bottom: 0.2rem;
        line-height: 1.25;
        white-space: normal;
        word-break: break-word;
    }
    .cart-product-info .category {
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--gold);
    }
    .cart-product-info .category.out-of-stock {
        color: var(--danger);
    }

    .product-price {
        font-size: 1.15rem;
        font-weight: 600;
        color: var(--text-bright);
        white-space: nowrap;
    }
    .product-price.subtotal { color: var(--gold); }

    .quantity-selector {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: var(--bg-soft);
        border: 1px solid var(--border);
        border-radius: 30px;
        padding: 4px;
    }
    .quantity-btn {
        width: 32px; height: 32px;
        border: none;
        background: transparent;
        color: var(--text-muted);
        border-radius: 50%;
        cursor: pointer;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
    }
    .quantity-btn:hover:not(:disabled) {
        background: var(--gold);
        color: #fff;
        transform: scale(1.05);
    }
    .quantity-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }
    .quantity-input {
        width: 44px;
        text-align: center;
        border: none;
        background: transparent;
        color: var(--text-bright);
        font-weight: 600;
        font-size: 0.95rem;
        font-family: 'Inter', sans-serif;
        -moz-appearance: textfield;
    }
    .quantity-input::-webkit-outer-spin-button,
    .quantity-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .quantity-input:focus { outline: none; color: var(--gold); }

    .remove-btn {
        width: 38px; height: 38px;
        border-radius: 50%;
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text-muted);
        cursor: pointer;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
    }
    .remove-btn:hover {
        background: rgba(220, 38, 38, 0.08);
        border-color: rgba(220, 38, 38, 0.4);
        color: var(--danger);
        transform: scale(1.05);
    }

    .cart-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        padding: 1.25rem;
        background: var(--bg-soft);
        border-top: 1px solid var(--border);
    }

    .order-summary-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 1.75rem;
        position: sticky;
        top: 20px;
        transition: border-color 0.3s ease;
    }
    .order-summary-card:hover { border-color: rgba(184, 148, 31, 0.35); }

    .summary-head {
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-light);
    }
    .summary-head .eyebrow {
        font-size: 0.65rem; font-weight: 700; letter-spacing: 0.35em;
        text-transform: uppercase; color: var(--gold); margin-bottom: 0.35rem;
    }
    .summary-head h4 {
        font-size: 1.55rem;
        margin: 0;
    }
    .summary-head h4 em { color: var(--gold); font-style: italic; font-weight: 500; }

    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 0.75rem 0;
        font-size: 0.9rem;
        color: var(--text);
    }
    .summary-row .label { color: var(--text-muted); }
    .summary-row .value {
        font-size: 1.05rem;
        font-weight: 600;
        color: var(--text-bright);
    }
    .summary-row.discount .value { color: var(--success); }
    .summary-row.total {
        border-top: 1px dashed var(--border);
        margin-top: 0.5rem;
        padding-top: 1rem;
        font-size: 1rem;
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

    .loyalty-note {
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.25);
        border-radius: 12px;
        padding: 0.9rem 1rem;
        margin-top: 1rem;
        font-size: 0.82rem;
        color: var(--text-bright);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .loyalty-note i { color: var(--gold); font-size: 1.05rem; }
    .loyalty-note strong { color: var(--gold-strong); }
    .loyalty-note .bar {
        height: 5px;
        background: var(--border);
        border-radius: 3px;
        overflow: hidden;
        margin-top: 6px;
        flex: 1;
    }
    .loyalty-note .bar-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--gold), var(--gold-strong));
        border-radius: 3px;
        box-shadow: 0 0 8px rgba(184, 148, 31, 0.55);
        transition: width 0.5s ease;
    }

    .secure-note {
        text-align: center;
        margin-top: 0.85rem;
        font-size: 0.72rem;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    .secure-note i { color: var(--gold); }

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
    }
    .rec-product-img-wrap {
        position: relative;
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
        margin-bottom: 0.85rem;
    }
    .rec-add-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 0.55rem 1.1rem;
        background: linear-gradient(135deg, var(--gold), var(--gold-strong));
        color: #fff;
        border: none;
        border-radius: 7px;
        font-family: 'Inter', sans-serif;
        font-size: 0.68rem;
        font-weight: 600;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        cursor: pointer;
        transition: all 0.25s ease;
        box-shadow: 0 3px 10px -2px rgba(184, 148, 31, 0.4);
    }
    .rec-add-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px -3px rgba(184, 148, 31, 0.55);
    }

    .empty-cart {
        text-align: center;
        padding: 5rem 2rem;
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
    }
    .empty-cart .icon-wrap {
        width: 100px; height: 100px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 1.25rem;
        background: var(--gold-soft);
        border: 1px solid rgba(184, 148, 31, 0.25);
        color: var(--gold);
        font-size: 2rem;
    }
    .empty-cart h3 {
        font-size: 1.9rem;
        margin-bottom: 0.5rem;
    }
    .empty-cart h3 em { color: var(--gold); font-style: italic; font-weight: 500; }
    .empty-cart p {
        color: var(--text-muted);
        margin-bottom: 1.75rem;
        font-size: 0.95rem;
    }

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

    .quantity-selector.loading {
        opacity: 0.6;
        pointer-events: none;
    }

    @media (max-width: 991px) {
        .order-summary-card { position: static; margin-top: 1.5rem; }
    }
    @media (max-width: 768px) {
        .cart-hero { padding: 3rem 1rem 2.5rem; }
        .cart-container { padding: 2.5rem 0 3.5rem; }
        .cart-table th:nth-child(2),
        .cart-table td:nth-child(2) { display: none; }
        .cart-product { min-width: auto; }
        .cart-product-image { width: 64px; height: 64px; }
        .cart-product-info h5 { font-size: 1rem; }
        .product-price { font-size: 1rem; }
        .recommended-section { margin-top: 3.5rem; }
    }
    @media (max-width: 480px) {
        .cart-table th, .cart-table td { padding: 0.75rem 0.65rem; }
        .cart-product { flex-direction: column; text-align: center; gap: 0.6rem; }
        .cart-product-image { width: 90px; height: 90px; }
        .cart-actions { flex-direction: column; align-items: stretch; }
        .cart-actions .btn-lux { justify-content: center; }
        .summary-row.total .value { font-size: 1.35rem; }
    }
    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
    }
</style>

<section class="cart-hero">
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
    <p class="eyebrow">R-Jewel&Gold · Shopping Bag</p>
    <h1>Your <em>Shopping</em> Cart</h1>
    <p>Review and manage your selected pieces before checkout</p>
    <div class="hero-divider">
        <span class="line"></span>
        <span class="diamond"></span>
        <span class="line"></span>
    </div>
</section>

<div class="cart-container">
    <div class="container">
        <?php if (empty($cart_items)): ?>
            <div class="empty-cart">
                <div class="icon-wrap"><i class="fas fa-shopping-bag"></i></div>
                <h3>Your cart is <em>empty</em></h3>
                <p>Looks like you haven't added any pieces to your collection yet.</p>
                <a href="shop.php" class="btn-lux btn-lux-gold">
                    Continue Shopping <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="cart-card">
                        <div class="table-responsive">
                            <table class="cart-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Price</th>
                                        <th>Quantity</th>
                                        <th>Subtotal</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cart_items as $item): 
                                        // Calculate available stock based on released_stock
                                        $released_stock = (int)$item['released_stock'];
                                        $cart_qty       = (int)$item['cart_quantity'];
                                        $available      = $released_stock - $cart_qty;
                                    ?>
                                    <tr data-id="<?php echo (int)$item['id']; ?>" data-price="<?php echo (float)$item['price']; ?>" data-total-stock="<?php echo $released_stock; ?>">
                                        <td>
                                            <div class="cart-product">
                                                <div class="cart-product-image">
                                                    <img src="../uploads/<?php echo htmlspecialchars($item['image'] ?? 'placeholder.jpg'); ?>"
                                                         onerror="this.onerror=null;this.src='https://via.placeholder.com/200x200/ede7db/8a8172?text=Jewelry'"
                                                         alt="<?php echo htmlspecialchars($item['name']); ?>"
                                                         loading="lazy">
                                                </div>
                                                <div class="cart-product-info">
                                                    <h5><?php echo htmlspecialchars($item['name']); ?></h5>
                                                    <div class="category stock-display <?php echo $available <= 0 ? 'out-of-stock' : ''; ?>">
                                                        <?php if ($available > 0): ?>
                                                            Stock <?php echo $available; ?>
                                                        <?php else: ?>
                                                            Out of Stock
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="product-price">₱<?php echo number_format((float)$item['price'], 2); ?></td>
                                        <td>
                                            <div class="quantity-selector" data-cart-id="<?php echo (int)$item['id']; ?>">
                                                <button type="button" class="quantity-btn quantity-minus" data-id="<?php echo (int)$item['id']; ?>">
                                                    <i class="fas fa-minus"></i>
                                                </button>
                                                <input type="number"
                                                       class="quantity-input"
                                                       value="<?php echo $cart_qty; ?>"
                                                       min="1"
                                                       max="<?php echo $released_stock; ?>"
                                                       data-cart-id="<?php echo (int)$item['id']; ?>">
                                                <button type="button" class="quantity-btn quantity-plus" data-id="<?php echo (int)$item['id']; ?>" <?php echo $available <= 0 ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="product-price subtotal subtotal-<?php echo (int)$item['id']; ?>">
                                            ₱<?php echo number_format((float)$item['price'] * $cart_qty, 2); ?>
                                        </td>
                                        <td>
                                            <button type="button"
                                                    class="remove-btn"
                                                    title="Remove item"
                                                    onclick="removeCartItem(<?php echo (int)$item['id']; ?>)">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="cart-actions">
                            <a href="shop.php" class="btn-lux btn-lux-outline">
                                <i class="fas fa-arrow-left"></i> Continue Shopping
                            </a>
                            <button type="button"
                                    class="btn-lux btn-lux-outline-danger"
                                    onclick="clearCart()">
                                <i class="fas fa-trash-alt"></i> Clear Cart
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="order-summary-card">
                        <div class="summary-head">
                            <p class="eyebrow">Checkout</p>
                            <h4>Order <em>Summary</em></h4>
                        </div>

                        <div class="summary-row">
                            <span class="label" id="summary-items-label">Subtotal (<?php echo (int)$total_items; ?> item<?php echo $total_items == 1 ? '' : 's'; ?>)</span>
                            <span class="value" id="summary-subtotal">₱<?php echo number_format($subtotal, 2); ?></span>
                        </div>

                        <div class="summary-row discount" id="discount-row" style="<?php echo $discount_percent > 0 ? '' : 'display:none;'; ?>">
                            <span class="label" id="summary-discount-label">Shop Discount (<?php echo $discount_percent > 0 ? $discount_percent . '%' : '—'; ?>)</span>
                            <span class="value" id="summary-discount">-₱<?php echo number_format($discount_amount, 2); ?></span>
                        </div>

                        <div class="summary-row">
                            <span class="label">Shipping</span>
                            <span class="value" id="summary-shipping"><?php echo $shipping > 0 ? '₱' . number_format($shipping, 2) : 'Free'; ?></span>
                        </div>

                        <div class="summary-row total">
                            <span class="label">Total</span>
                            <span class="value" id="summary-total">₱<?php echo number_format($total, 2); ?></span>
                        </div>

                        <div id="loyalty-container">
                            <?php if ($discount_percent > 0): ?>
                                <div class="loyalty-note">
                                    <i class="fas fa-gem"></i>
                                    <div style="flex:1;">
                                        You unlocked a <strong><?php echo $discount_percent; ?>%</strong> shop discount!
                                        Add more items to reach a higher tier.
                                        <div class="bar">
                                            <div class="bar-fill" style="width: <?php echo min(100, ($total_items / 4) * 100); ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ($total_items > 0): ?>
                                <div class="loyalty-note">
                                    <i class="fas fa-gem"></i>
                                    <div style="flex:1;">
                                        Buy <strong>2 items</strong> for 3% off, <strong>3 items</strong> for 5% off, or <strong>4+ items</strong> for 8% off.
                                        <div class="bar">
                                            <div class="bar-fill" style="width: <?php echo min(100, ($total_items / 4) * 100); ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <a href="checkout.php" class="btn-lux btn-lux-gold" style="width:100%; justify-content:center; margin-top:1.25rem; padding:1rem;">
                            <i class="fas fa-lock"></i> Proceed to Checkout
                        </a>

                        <div class="secure-note">
                            <i class="fas fa-shield-alt"></i> Secure encrypted checkout
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($recommended)): ?>
            <div class="recommended-section">
                <div class="section-head">
                    <p class="eyebrow">Complete Your Look</p>
                    <h2>You May Also <em>Like</em></h2>
                    <p>Beautiful pieces curated just for you</p>
                    <div class="small-divider"><span class="line"></span><span class="diamond"></span><span class="line"></span></div>
                </div>

                <div class="row g-4">
                    <?php foreach ($recommended as $product): ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="rec-product-card">
                            <div class="rec-product-img-wrap">
                                <img src="../uploads/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                                     onerror="this.onerror=null;this.src='https://via.placeholder.com/400x300/ede7db/8a8172?text=Jewelry'"
                                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                                     loading="lazy">
                            </div>
                            <div class="rec-product-info">
                                <div class="rec-product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                <div class="rec-product-price">₱<?php echo number_format((float)$product['price'], 2); ?></div>
                                <button class="rec-add-btn" onclick="addToCart(<?php echo (int)$product['id']; ?>)">
                                    <i class="fas fa-shopping-bag"></i> Add to Cart
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    'use strict';

    /* ----------------------------------------------------------
       Toast
       ---------------------------------------------------------- */
    function showNotification(message, type) {
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
    }

    /* ----------------------------------------------------------
       Update summary panel from AJAX response (no reload)
       ---------------------------------------------------------- */
    function updateSummary(res) {
        var label = document.getElementById('summary-items-label');
        if (label) label.textContent = 'Subtotal (' + res.total_items_label + ')';

        var subEl = document.getElementById('summary-subtotal');
        if (subEl) subEl.textContent = '₱' + res.subtotal;

        var shipEl = document.getElementById('summary-shipping');
        if (shipEl) shipEl.textContent = res.shipping;

        var discountRow   = document.getElementById('discount-row');
        var discountLabel = document.getElementById('summary-discount-label');
        var discountEl    = document.getElementById('summary-discount');
        if (discountRow && discountLabel && discountEl) {
            if (res.discount_percent > 0) {
                discountRow.style.display = '';
                discountLabel.textContent = 'Shop Discount (' + res.discount_percent + '%)';
                discountEl.textContent    = '-₱' + res.discount_amount;
            } else {
                discountRow.style.display = 'none';
            }
        }

        var totalEl = document.getElementById('summary-total');
        if (totalEl) totalEl.textContent = '₱' + res.total;

        // Loyalty block
        var container = document.getElementById('loyalty-container');
        if (container) {
            if (res.discount_percent > 0) {
                container.innerHTML =
                    '<div class="loyalty-note">' +
                        '<i class="fas fa-gem"></i>' +
                        '<div style="flex:1;">' +
                            'You unlocked a <strong>' + res.discount_percent + '%</strong> shop discount! ' +
                            'Add more items to reach a higher tier.' +
                            '<div class="bar">' +
                                '<div class="bar-fill" style="width: ' + Math.min(100, (res.total_items / 4) * 100) + '%"></div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
            } else if (res.total_items > 0) {
                container.innerHTML =
                    '<div class="loyalty-note">' +
                        '<i class="fas fa-gem"></i>' +
                        '<div style="flex:1;">' +
                            'Buy <strong>2 items</strong> for 3% off, <strong>3 items</strong> for 5% off, or <strong>4+ items</strong> for 8% off.' +
                            '<div class="bar">' +
                                '<div class="bar-fill" style="width: ' + Math.min(100, (res.total_items / 4) * 100) + '%"></div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
            } else {
                container.innerHTML = '';
            }
        }
    }

    /* ----------------------------------------------------------
       Core: update quantity via AJAX (NO page reload)
       ---------------------------------------------------------- */
    function updateQuantity(cartId, quantity) {
        var selector = document.querySelector('.quantity-selector[data-cart-id="' + cartId + '"]');
        var row      = document.querySelector('tr[data-id="' + cartId + '"]');
        var input    = document.querySelector('.quantity-input[data-cart-id="' + cartId + '"]');
        if (!row || !input) return;

        var price = parseFloat(row.getAttribute('data-price')) || 0;
        var totalStock = parseInt(row.getAttribute('data-total-stock')) || 999;

        // Optimistically update the item subtotal
        var subEl = row.querySelector('.subtotal-' + cartId);
        if (subEl) subEl.textContent = '₱' + (price * quantity).toFixed(2);

        if (selector) selector.classList.add('loading');

        var body = new URLSearchParams();
        body.append('ajax_update_qty', '1');
        body.append('cart_id', cartId);
        body.append('quantity', quantity);

        fetch('cart.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (selector) selector.classList.remove('loading');

            if (res && res.success) {
                // Sync input value in case server clamped it
                if (input.value != res.quantity) input.value = res.quantity;

                // Update item subtotal precisely from server
                var sEl = row.querySelector('.subtotal-' + cartId);
                if (sEl) sEl.textContent = '₱' + res.item_subtotal;

                // Update available stock display
                var stockDisplay = row.querySelector('.stock-display');
                if (stockDisplay) {
                    if (res.available_stock > 0) {
                        stockDisplay.innerHTML = 'Stock ' + res.available_stock;
                        stockDisplay.classList.remove('out-of-stock');
                    } else {
                        stockDisplay.innerHTML = 'Out of Stock';
                        stockDisplay.classList.add('out-of-stock');
                    }
                }

                // Update the plus button disabled state
                var plusBtn = row.querySelector('.quantity-plus');
                if (plusBtn) {
                    plusBtn.disabled = (res.available_stock <= 0);
                }

                // Update the max attribute for input
                input.setAttribute('max', totalStock);

                // Update the summary panel
                updateSummary(res);

                // Refresh header cart badge if available
                if (typeof window.applyCartCount === 'function') {
                    window.applyCartCount(res.total_items);
                } else if (typeof window.updateCartCount === 'function') {
                    window.updateCartCount();
                }
            } else {
                showNotification('Could not update quantity.', 'error');
            }
        })
        .catch(function () {
            if (selector) selector.classList.remove('loading');
            showNotification('Network error. Please try again.', 'error');
        });
    }

    /* ----------------------------------------------------------
       Quantity buttons & input handlers (event delegation)
       ---------------------------------------------------------- */
    document.addEventListener('click', function (e) {
        var plusBtn  = e.target.closest('.quantity-plus');
        var minusBtn = e.target.closest('.quantity-minus');
        if (!plusBtn && !minusBtn) return;

        var btn      = plusBtn || minusBtn;
        var cartId   = btn.getAttribute('data-id');
        var selector = btn.closest('.quantity-selector');
        var input    = selector ? selector.querySelector('.quantity-input') : null;
        if (!input) return;

        var max = parseInt(input.getAttribute('max'), 10) || 999;
        var val = parseInt(input.value, 10) || 1;

        if (plusBtn) {
            if (val >= max) return;
            val += 1;
        } else {
            if (val <= 1) return;
            val -= 1;
        }

        input.value = val;
        updateQuantity(cartId, val);
    });

    // Direct input change
    document.addEventListener('change', function (e) {
        var input = e.target.closest('.quantity-input');
        if (!input) return;

        var cartId = input.getAttribute('data-cart-id');
        var max    = parseInt(input.getAttribute('max'), 10) || 999;
        var val    = parseInt(input.value, 10);
        if (isNaN(val) || val < 1) val = 1;
        if (val > max) val = max;
        input.value = val;
        updateQuantity(cartId, val);
    });

    /* ----------------------------------------------------------
       Remove item (AJAX, no reload)
       ---------------------------------------------------------- */
    window.removeCartItem = function (cartId) {
        if (!confirm('Remove this item from your cart?')) return;

        var row = document.querySelector('tr[data-id="' + cartId + '"]');
        if (row) row.style.opacity = '0.4';

        var body = new URLSearchParams();
        body.append('ajax_remove_item', '1');
        body.append('cart_id', cartId);

        fetch('cart.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                // If no items left, reload to show empty state
                var remaining = document.querySelectorAll('.cart-table tbody tr').length;
                if (remaining <= 1) {
                    location.reload();
                    return;
                }
                // Remove the row from DOM
                if (row) row.remove();
                // Update totals via a silent refresh of the summary
                refreshTotals();
                if (typeof window.updateCartCount === 'function') window.updateCartCount();
                showNotification('Item removed.', 'success');
            } else {
                if (row) row.style.opacity = '1';
                showNotification('Could not remove item.', 'error');
            }
        })
        .catch(function () {
            if (row) row.style.opacity = '1';
            showNotification('Network error. Please try again.', 'error');
        });
    };

    /* ----------------------------------------------------------
       Clear cart (AJAX, no reload until empty)
       ---------------------------------------------------------- */
    window.clearCart = function () {
        if (!confirm('Clear all items from your cart?')) return;

        var body = new URLSearchParams();
        body.append('ajax_clear_cart', '1');

        fetch('cart.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                if (typeof window.updateCartCount === 'function') window.updateCartCount();
                location.reload();
            }
        })
        .catch(function () { location.reload(); });
    };

    /* ----------------------------------------------------------
       Refresh totals after a removal (fetch fresh page & extract summary)
       ---------------------------------------------------------- */
    function refreshTotals() {
        fetch('cart.php', { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');

            var newSub   = doc.getElementById('summary-subtotal');
            var newShip  = doc.getElementById('summary-shipping');
            var newTotal = doc.getElementById('summary-total');
            var newLabel = doc.getElementById('summary-items-label');
            var newDiscRow = doc.getElementById('discount-row');
            var newDiscLabel = doc.getElementById('summary-discount-label');
            var newDiscEl = doc.getElementById('summary-discount');
            var newLoyalty = doc.getElementById('loyalty-container');

            if (newSub)   document.getElementById('summary-subtotal').textContent = newSub.textContent;
            if (newShip)  document.getElementById('summary-shipping').textContent = newShip.textContent;
            if (newTotal) document.getElementById('summary-total').textContent = newTotal.textContent;
            if (newLabel) document.getElementById('summary-items-label').textContent = newLabel.textContent;

            var curDiscRow = document.getElementById('discount-row');
            if (newDiscRow && curDiscRow) curDiscRow.style.display = newDiscRow.style.display;
            if (newDiscLabel) {
                var cl = document.getElementById('summary-discount-label');
                if (cl) cl.textContent = newDiscLabel.textContent;
            }
            if (newDiscEl) {
                var ce = document.getElementById('summary-discount');
                if (ce) ce.textContent = newDiscEl.textContent;
            }
            if (newLoyalty) {
                var cl2 = document.getElementById('loyalty-container');
                if (cl2) cl2.innerHTML = newLoyalty.innerHTML;
            }
        })
        .catch(function () { /* ignore */ });
    }

    /* ----------------------------------------------------------
       Add to cart (from recommended)
       ---------------------------------------------------------- */
    window.addToCart = function (productId) {
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
                if (typeof res.count !== 'undefined' && typeof window.applyCartCount === 'function') {
                    window.applyCartCount(res.count);
                } else if (typeof window.updateCartCount === 'function') {
                    window.updateCartCount();
                }
                showNotification('Added to cart!', 'success');
                setTimeout(function () { location.reload(); }, 700);
            } else {
                showNotification((res && res.message) || 'Please login to add items', 'error');
            }
        })
        .catch(function () {
            showNotification('Network error. Please try again.', 'error');
        });
    };
})();
</script>

<?php include '../includes/footer.php'; ?>