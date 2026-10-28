<?php
// admin/order-details.php — Order details as a Bootstrap modal popup
error_reporting(E_ALL);
ini_set('display_errors', 0); // fragment response — never leak warnings into HTML

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth guard
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo "<div class='p-4 text-danger'>Unauthorized.</div>";
    exit();
}

require_once '../config/database.php';

$db = (new Database())->getConnection();

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$modal    = isset($_GET['modal']) && $_GET['modal'] == '1'; // fragment mode

if ($order_id <= 0) {
    echo "<div class='p-4 text-danger'>Invalid order ID.</div>";
    exit();
}

// ---------- Fetch order ----------
$query = "SELECT o.*, u.full_name, u.email, u.phone, u.address AS user_address,
                 (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count
          FROM orders o
          JOIN users u ON o.user_id = u.id
          WHERE o.id = :order_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':order_id', $order_id);
$stmt->execute();
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo "<div class='p-4 text-danger'>Order not found.</div>";
    exit();
}

// ---------- Fetch items ----------
$items_query = "SELECT oi.*, p.name, p.image
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = :order_id";
$items_stmt = $db->prepare($items_query);
$items_stmt->bindParam(':order_id', $order_id);
$items_stmt->execute();
$order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- Order math ----------
$subtotal = 0;
foreach ($order_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$shipping = $subtotal > 100 ? 0 : 10;
$tax      = $subtotal * 0.1;
$total    = $subtotal + $shipping + $tax;

// ---------- Timeline ----------
$timeline = [
    ['status' => 'Order Placed', 'date' => $order['created_at'], 'icon' => 'fa-shopping-cart'],
    ['status' => 'Processing',   'date' => in_array($order['status'], ['processing','shipped','delivered'], true) ? date('Y-m-d H:i:s', strtotime($order['created_at'] . ' +1 hour')) : null, 'icon' => 'fa-cog'],
    ['status' => 'Shipped',      'date' => in_array($order['status'], ['shipped','delivered'], true) ? date('Y-m-d H:i:s', strtotime($order['created_at'] . ' +2 days')) : null, 'icon' => 'fa-truck'],
    ['status' => 'Delivered',    'date' => $order['status'] === 'delivered' ? date('Y-m-d H:i:s', strtotime($order['created_at'] . ' +5 days')) : null, 'icon' => 'fa-check-circle'],
];
$status_order  = ['pending','processing','shipped','delivered'];
$current_index = array_search($order['status'], $status_order);
if ($current_index === false) $current_index = -1;

// ---------- Read persisted appearance from cookie ----------
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
$resolved_theme = in_array($theme, ['cream','light','dark'], true) ? $theme : 'cream';
?>

<?php if (!$modal): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?> | R-Jewel&Gold Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400;1,700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
            --gold-light: #d4af37;
            --success: #0d9668;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #2563eb;
        }
        <?php if ($resolved_theme === 'dark'): ?>
        :root {
            --bg: #151821; --bg-soft: #1c202b; --card: #222735;
            --border: #343b4d; --border-light: #2d3444;
            --text: #d5d9e2; --text-bright: #ffffff;
            --text-muted: #a0a8ba; --text-dim: #777f91;
        }
        <?php elseif ($resolved_theme === 'light'): ?>
        :root {
            --bg: #f5f7fb; --bg-soft: #ffffff; --card: #ffffff;
            --border: #e2e8f0; --border-light: #edf1f6;
            --text: #475569; --text-bright: #172033;
            --text-muted: #64748b; --text-dim: #94a3b8;
        }
        <?php endif; ?>
        body { background: var(--bg); padding: 20px; font-family: 'Inter', sans-serif; }
        .modal { background: rgba(30, 37, 51, .45); backdrop-filter: blur(4px); }
    </style>
</head>
<body>
<?php endif; ?>

<!-- ============================================================
     MODAL CONTENT — Order Details
     ============================================================ -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="
            background: var(--card, #ffffff);
            border: 1px solid var(--border, #e5ddd0);
            border-radius: 16px;
            overflow: hidden;
            font-family: 'Inter', sans-serif;
        ">

            <!-- HEADER -->
            <div class="modal-header" style="
                background: var(--nav-bg, #1e2533);
                color: #ffffff;
                border-bottom: 1px solid var(--nav-border, #2a3142);
                padding: 16px 22px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            ">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="
                        width:38px; height:38px; border-radius:11px;
                        background: linear-gradient(135deg, var(--gold, #b8941f), #8a6e14);
                        display:flex; align-items:center; justify-content:center;
                        color:#fff; font-size:1rem;
                        box-shadow: 0 4px 12px -3px rgba(184,148,31,.5);
                    "><i class="fas fa-receipt"></i></div>
                    <div>
                        <h5 class="modal-title" style="
                            margin:0; color:#ffffff;
                            font-size:.95rem; font-weight:600;
                            display:flex; align-items:center; gap:8px;
                        ">
                            Order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?>
                        </h5>
                        <p style="margin:3px 0 0; font-size:.72rem; color:#8b92a5;">
                            Placed <?php echo date('M d, Y · h:i A', strtotime($order['created_at'])); ?>
                        </p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"
                        style="filter: brightness(0) invert(1); opacity:.6;"></button>
            </div>

            <!-- BODY -->
            <div class="modal-body" style="
                padding: 22px;
                background: var(--card, #ffffff);
                color: var(--text, #5a5245);
            ">

                <!-- Status + Actions -->
                <div style="
                    display:flex; flex-wrap:wrap; gap:12px;
                    justify-content:space-between; align-items:center;
                    background: var(--bg-soft, #faf7f2);
                    border: 1px solid var(--border, #e5ddd0);
                    border-radius: 12px;
                    padding: 14px 16px;
                    margin-bottom: 18px;
                ">
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span style="font-size:.68rem; letter-spacing:.08em; text-transform:uppercase; color:var(--text-dim,#b3a998); font-weight:700;">Status</span>
                        <span class="badge-status badge-<?php echo $order['status']; ?>">
                            <i class="fas <?php
                                echo $order['status'] === 'pending'    ? 'fa-clock' :
                                    ($order['status'] === 'processing' ? 'fa-cog' :
                                    ($order['status'] === 'shipped'    ? 'fa-truck' :
                                    ($order['status'] === 'delivered'  ? 'fa-check-circle' : 'fa-times-circle')));
                            ?>"></i>
                            <?php echo strtoupper($order['status']); ?>
                        </span>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="button" class="btn-outline-modern" onclick="window.open('invoice.php?id=<?php echo $order['id']; ?>', '_blank')">
                            <i class="fas fa-file-invoice"></i> Invoice
                        </button>
                        <button type="button" class="btn-outline-modern" onclick="window.print()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>

                <!-- Status updater -->
                <div style="
                    background: var(--bg-soft, #faf7f2);
                    border: 1px solid var(--border, #e5ddd0);
                    border-radius: 12px;
                    padding: 14px 16px;
                    margin-bottom: 18px;
                    display: flex; flex-wrap:wrap; gap:10px; align-items:center;
                ">
                    <form action="update-order-status.php" method="POST"
                          style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:0; width:100%;">
                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                        <select name="status" class="form-select-modern" style="width:auto;">
                            <option value="pending"    <?php echo $order['status'] === 'pending'    ? 'selected' : ''; ?>>Pending</option>
                            <option value="processing" <?php echo $order['status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                            <option value="shipped"    <?php echo $order['status'] === 'shipped'    ? 'selected' : ''; ?>>Shipped</option>
                            <option value="delivered"  <?php echo $order['status'] === 'delivered'  ? 'selected' : ''; ?>>Delivered</option>
                            <option value="cancelled"  <?php echo $order['status'] === 'cancelled'  ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                        <button type="submit" class="btn-modern">
                            <i class="fas fa-save"></i> Update Status
                        </button>
                    </form>
                </div>

                <!-- Customer + Shipping + Payment grid -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:18px;">
                    <div class="info-card">
                        <h6 class="info-title"><i class="fas fa-user"></i> Customer</h6>
                        <div class="info-value"><?php echo htmlspecialchars($order['full_name']); ?></div>
                        <div class="info-line"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($order['email']); ?></div>
                        <div class="info-line"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($order['phone'] ?: 'Not provided'); ?></div>
                    </div>
                    <div class="info-card">
                        <h6 class="info-title"><i class="fas fa-map-marker-alt"></i> Shipping</h6>
                        <div class="info-value" style="font-size:.85rem;font-weight:500;">
                            <?php echo nl2br(htmlspecialchars($order['shipping_address'] ?: $order['user_address'])); ?>
                        </div>
                    </div>
                    <div class="info-card">
                        <h6 class="info-title"><i class="fas fa-credit-card"></i> Payment</h6>
                        <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></div>
                        <div class="info-line"><i class="fas fa-check-circle" style="color:var(--success,#0d9668);"></i> <strong style="color:var(--success,#0d9668);">Paid</strong></div>
                    </div>
                    <div class="info-card">
                        <h6 class="info-title"><i class="fas fa-box"></i> Summary</h6>
                        <div class="info-value"><?php echo $order['item_count']; ?> item(s)</div>
                        <div class="info-line"><i class="fas fa-peso-sign"></i> Total: <strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong></div>
                    </div>
                </div>

                <!-- Timeline -->
                <h6 class="section-title"><i class="fas fa-history"></i> Order Timeline</h6>
                <div class="timeline">
                    <?php foreach ($timeline as $index => $event):
                        $is_completed = $event['date'] && $index <= $current_index;
                        $is_current   = $index == $current_index && $order['status'] !== 'delivered' && $order['status'] !== 'cancelled';
                        $is_pending   = !$event['date'];
                    ?>
                    <div class="timeline-item <?php echo $is_pending ? 'pending' : ''; ?>">
                        <div class="timeline-icon <?php echo $is_completed ? 'completed' : ($is_current ? 'current' : ''); ?>">
                            <i class="fas <?php echo $event['icon']; ?>"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title"><?php echo $event['status']; ?></div>
                            <div class="timeline-date">
                                <?php if ($event['date']): ?>
                                    <i class="far fa-clock"></i> <?php echo date('M d, Y h:i A', strtotime($event['date'])); ?>
                                <?php else: ?>
                                    <i class="far fa-hourglass"></i> Pending
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Items -->
                <h6 class="section-title" style="margin-top:18px;"><i class="fas fa-gem"></i> Items</h6>
                <div class="items-wrap">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="text-align:right;">Price</th>
                                <th style="text-align:center;">Qty</th>
                                <th style="text-align:right;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order_items as $item): ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <img src="../uploads/<?php echo htmlspecialchars($item['image'] ?: 'placeholder.jpg'); ?>"
                                             class="product-img" alt="">
                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                    </div>
                                </td>
                                <td class="price-cell" style="text-align:right;">₱<?php echo number_format($item['price'], 2); ?></td>
                                <td style="text-align:center;"><span class="qty-badge"><?php echo $item['quantity']; ?></span></td>
                                <td class="price-cell" style="text-align:right;">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Totals -->
                <div class="totals-block">
                    <div class="total-row"><span>Subtotal</span><span>₱<?php echo number_format($subtotal, 2); ?></span></div>
                    <div class="total-row"><span>Shipping</span><span><?php echo $shipping > 0 ? '₱' . number_format($shipping, 2) : 'Free'; ?></span></div>
                    <div class="total-row"><span>Tax (10%)</span><span>₱<?php echo number_format($tax, 2); ?></span></div>
                    <div class="total-row grand"><span>Total</span><span>₱<?php echo number_format($total, 2); ?></span></div>
                </div>

            </div>

            <!-- FOOTER -->
            <div class="modal-footer" style="
                background: var(--bg-soft, #faf7f2);
                border-top: 1px solid var(--border, #e5ddd0);
                padding: 14px 22px;
                display: flex; justify-content: space-between; align-items: center;
                gap: 10px; flex-wrap: wrap;
            ">
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <a href="mailto:<?php echo htmlspecialchars($order['email']); ?>?subject=<?php echo rawurlencode('Order #' . str_pad($order['id'], 6, '0', STR_PAD_LEFT) . ' Update'); ?>"
                       class="btn-outline-modern">
                        <i class="fas fa-envelope"></i> Email Customer
                    </a>
                    <?php if ($order['status'] !== 'delivered' && $order['status'] !== 'cancelled'): ?>
                    <form action="update-order-status.php" method="POST" style="margin:0;"
                          onsubmit="return confirm('Are you sure you want to cancel this order?');">
                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="btn-danger-modern">
                            <i class="fas fa-ban"></i> Cancel Order
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn-modern" data-bs-dismiss="modal">
                    <i class="fas fa-check"></i> Close
                </button>
            </div>

        </div>
    </div>
</div>

<style>
/* ===================================================================
   Scoped modal styling — matches admin theme (cream/light/dark)
   =================================================================== */
#orderDetailsModal .modal-content {
    background: var(--card, #ffffff);
    border: 1px solid var(--border, #e5ddd0);
    border-radius: 16px;
    overflow: hidden;
    font-family: 'Inter', sans-serif;
    box-shadow: 0 24px 60px -20px rgba(90, 82, 69, .35);
}

/* Status badges */
#orderDetailsModal .badge-status {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: .72rem; font-weight: 700;
    display: inline-flex; align-items: center; gap: 6px;
    letter-spacing: .04em; text-transform: uppercase;
}
#orderDetailsModal .badge-pending    { background: rgba(217,119,6,.1);  color: #b45309; }
#orderDetailsModal .badge-processing { background: rgba(37,99,235,.1);  color: #1d4ed8; }
#orderDetailsModal .badge-shipped    { background: rgba(91,95,212,.1);  color: #4f46e5; }
#orderDetailsModal .badge-delivered  { background: rgba(13,150,104,.1); color: #0a7a54; }
#orderDetailsModal .badge-cancelled  { background: rgba(220,38,38,.1);  color: #b91c1c; }

/* Buttons */
#orderDetailsModal .btn-modern {
    background: var(--primary, #5b5fd4);
    border: none; color: #fff;
    border-radius: 9px; font-size: .78rem; font-weight: 600;
    padding: 9px 16px; transition: all .2s ease;
    display: inline-flex; align-items: center; gap: 6px;
    cursor: pointer; font-family: 'Inter', sans-serif;
}
#orderDetailsModal .btn-modern:hover {
    background: #4f46e5; transform: translateY(-1px);
    box-shadow: 0 6px 18px -3px rgba(91,95,212,.35);
}
#orderDetailsModal .btn-outline-modern {
    background: transparent; border: 1px solid var(--border, #e5ddd0);
    color: var(--text-muted, #8a8172);
    border-radius: 9px; font-size: .78rem; font-weight: 600;
    padding: 9px 16px; transition: all .2s ease;
    display: inline-flex; align-items: center; gap: 6px;
    cursor: pointer; font-family: 'Inter', sans-serif; text-decoration: none;
}
#orderDetailsModal .btn-outline-modern:hover {
    background: var(--primary-soft, rgba(91,95,212,.08));
    border-color: var(--primary, #5b5fd4);
    color: var(--primary, #5b5fd4);
}
#orderDetailsModal .btn-danger-modern {
    background: transparent; border: 1px solid rgba(220,38,38,.3);
    color: var(--danger, #dc2626);
    border-radius: 9px; font-size: .78rem; font-weight: 600;
    padding: 9px 16px; transition: all .2s ease;
    display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
    font-family: 'Inter', sans-serif;
}
#orderDetailsModal .btn-danger-modern:hover {
    background: var(--danger, #dc2626); color: #fff;
    box-shadow: 0 6px 18px -4px rgba(220,38,38,.4);
}

#orderDetailsModal .form-select-modern {
    background: var(--card, #fff);
    border: 1px solid var(--border, #e5ddd0);
    color: var(--text-bright, #2b2620);
    border-radius: 9px; font-size: .8rem; font-weight: 500;
    padding: 8px 14px; cursor: pointer;
    font-family: 'Inter', sans-serif;
}
#orderDetailsModal .form-select-modern:focus {
    border-color: var(--primary, #5b5fd4);
    box-shadow: 0 0 0 3px var(--primary-soft, rgba(91,95,212,.08));
    outline: none;
}

/* Info cards */
#orderDetailsModal .info-card {
    background: var(--bg-soft, #faf7f2);
    border: 1px solid var(--border, #e5ddd0);
    border-radius: 12px;
    padding: 14px 16px;
}
#orderDetailsModal .info-title {
    font-size: .66rem; letter-spacing: .08em;
    text-transform: uppercase; color: var(--text-dim, #b3a998);
    font-weight: 700; margin: 0 0 10px;
    display: flex; align-items: center; gap: 7px;
}
#orderDetailsModal .info-title i { color: var(--gold, #b8941f); font-size: .78rem; }
#orderDetailsModal .info-value {
    font-size: .9rem; font-weight: 600; color: var(--text-bright, #2b2620);
    margin-bottom: 5px; line-height: 1.4;
}
#orderDetailsModal .info-line {
    font-size: .75rem; color: var(--text-muted, #8a8172);
    display: flex; align-items: flex-start; gap: 6px;
    line-height: 1.5; margin-bottom: 3px;
}
#orderDetailsModal .info-line i { color: var(--text-dim, #b3a998); font-size: .68rem; width: 12px; text-align: center; margin-top: 3px; flex-shrink: 0; }

/* Section title */
#orderDetailsModal .section-title {
    font-size: .68rem; letter-spacing: .08em;
    text-transform: uppercase; color: var(--text-dim, #b3a998);
    font-weight: 700; margin: 0 0 12px;
    display: flex; align-items: center; gap: 8px;
}
#orderDetailsModal .section-title i { color: var(--gold, #b8941f); font-size: .8rem; }

/* Timeline */
#orderDetailsModal .timeline { position: relative; padding: 4px 0; }
#orderDetailsModal .timeline::before {
    content: ''; position: absolute;
    left: 19px; top: 20px; bottom: 20px;
    width: 2px; background: var(--border, #e5ddd0);
}
#orderDetailsModal .timeline-item {
    display: flex; gap: 16px; padding-bottom: 20px;
    position: relative;
}
#orderDetailsModal .timeline-item:last-child { padding-bottom: 0; }
#orderDetailsModal .timeline-icon {
    width: 40px; height: 40px; border-radius: 50%;
    background: var(--card, #fff);
    border: 2px solid var(--border, #e5ddd0);
    display: flex; align-items: center; justify-content: center;
    font-size: .82rem; color: var(--text-dim, #b3a998);
    z-index: 2; flex-shrink: 0;
    transition: all .25s ease;
}
#orderDetailsModal .timeline-icon.completed {
    background: var(--success, #0d9668);
    border-color: var(--success, #0d9668);
    color: #fff;
    box-shadow: 0 4px 12px -3px rgba(13,150,104,.4);
}
#orderDetailsModal .timeline-icon.current {
    background: var(--primary, #5b5fd4);
    border-color: var(--primary, #5b5fd4);
    color: #fff;
    animation: pulseRing 1.8s infinite;
}
@keyframes pulseRing {
    0%   { box-shadow: 0 0 0 0 rgba(91,95,212,.5); }
    70%  { box-shadow: 0 0 0 12px rgba(91,95,212,0); }
    100% { box-shadow: 0 0 0 0 rgba(91,95,212,0); }
}
#orderDetailsModal .timeline-content { padding-top: 5px; min-width: 0; }
#orderDetailsModal .timeline-title {
    font-weight: 600; font-size: .88rem;
    color: var(--text-bright, #2b2620); margin-bottom: 3px;
}
#orderDetailsModal .timeline-date {
    font-size: .72rem; color: var(--text-dim, #b3a998);
    display: flex; align-items: center; gap: 5px;
}
#orderDetailsModal .timeline-item.pending .timeline-title { color: var(--text-dim, #b3a998); }

/* Items table */
#orderDetailsModal .items-wrap {
    border: 1px solid var(--border, #e5ddd0);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 16px;
}
#orderDetailsModal .items-table { width: 100%; border-collapse: collapse; }
#orderDetailsModal .items-table thead th {
    background: var(--bg-soft, #faf7f2);
    border-bottom: 1px solid var(--border, #e5ddd0);
    padding: 11px 16px;
    font-size: .66rem; text-transform: uppercase;
    letter-spacing: .08em; color: var(--text-dim, #b3a998);
    font-weight: 700; text-align: left; white-space: nowrap;
}
#orderDetailsModal .items-table tbody td {
    padding: 13px 16px;
    border-bottom: 1px solid var(--border, #e5ddd0);
    font-size: .82rem; color: var(--text, #5a5245);
    vertical-align: middle;
}
#orderDetailsModal .items-table tbody tr:last-child td { border-bottom: none; }
#orderDetailsModal .items-table tbody tr:hover { background: var(--bg-soft, #faf7f2); }
#orderDetailsModal .items-table strong { color: var(--text-bright, #2b2620); font-weight: 600; }
#orderDetailsModal .price-cell { color: var(--primary, #5b5fd4); font-weight: 700; }
#orderDetailsModal .product-img {
    width: 48px; height: 48px; object-fit: cover;
    border-radius: 10px; border: 1px solid var(--border, #e5ddd0);
    background: var(--bg-soft, #faf7f2); flex-shrink: 0;
}
#orderDetailsModal .qty-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 24px; padding: 0 10px;
    background: var(--bg-soft, #faf7f2);
    border: 1px solid var(--border, #e5ddd0);
    border-radius: 7px; font-size: .76rem; font-weight: 600;
    color: var(--text-bright, #2b2620);
}

/* Totals */
#orderDetailsModal .totals-block {
    background: var(--bg-soft, #faf7f2);
    border: 1px solid var(--border, #e5ddd0);
    border-radius: 12px;
    padding: 14px 18px;
    margin-top: 4px;
}
#orderDetailsModal .total-row {
    display: flex; justify-content: space-between;
    align-items: center; padding: 6px 0;
    font-size: .82rem; color: var(--text-muted, #8a8172);
}
#orderDetailsModal .total-row span:last-child {
    color: var(--text-bright, #2b2620); font-weight: 600;
}
#orderDetailsModal .total-row.grand {
    border-top: 2px solid var(--border, #e5ddd0);
    margin-top: 6px; padding-top: 12px;
}
#orderDetailsModal .total-row.grand span:first-child {
    color: var(--text-bright, #2b2620);
    font-weight: 700; text-transform: uppercase;
    letter-spacing: .04em; font-size: .8rem;
}
#orderDetailsModal .total-row.grand span:last-child {
    color: var(--primary, #5b5fd4);
    font-weight: 700; font-size: 1.2rem;
}

/* Responsive */
@media (max-width: 576px) {
    #orderDetailsModal .modal-body { padding: 16px; }
    #orderDetailsModal .info-card { padding: 12px 14px; }
    #orderDetailsModal .items-table thead th,
    #orderDetailsModal .items-table tbody td { padding: 10px 10px; font-size: .76rem; }
    #orderDetailsModal .product-img { width: 40px; height: 40px; }
    #orderDetailsModal .timeline-icon { width: 36px; height: 36px; font-size: .78rem; }
    #orderDetailsModal .timeline::before { left: 17px; }
}
</style>

<?php if (!$modal): ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Standalone mode: auto-open the modal
        document.addEventListener('DOMContentLoaded', function () {
            var modalEl = document.getElementById('orderDetailsModal');
            var modal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: true });
            modal.show();
            modalEl.addEventListener('hidden.bs.modal', function () {
                window.location.href = 'orders.php';
            });
        });
    </script>
</body>
</html>
<?php endif; ?>