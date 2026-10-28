<?php
// admin/invoice.php
session_start();
require_once '../config/database.php';

// Check if user is logged in as admin
if(!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit();
}

$order_id = $_GET['id'] ?? 0;

$database = new Database();
$db = $database->getConnection();

// Get order details
$query = "SELECT o.*, u.full_name, u.email, u.phone, u.address 
          FROM orders o 
          JOIN users u ON o.user_id = u.id 
          WHERE o.id = :order_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':order_id', $order_id);
$stmt->execute();
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$order) {
    die("Order not found!");
}

// Get order items
$items_query = "SELECT oi.*, p.name 
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.id 
                WHERE oi.order_id = :order_id";
$items_stmt = $db->prepare($items_query);
$items_stmt->bindParam(':order_id', $order_id);
$items_stmt->execute();
$order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute status label class for consistency with the rest of the admin
$status_class = in_array($order['status'], ['pending','processing','shipped','delivered','cancelled'], true)
    ? 'badge-' . $order['status']
    : 'badge-processing';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Invoice #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?> | R-Jewel&Gold</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400;1,700&display=swap" rel="stylesheet">
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
            --primary: #5b5fd4;
            --primary-soft: rgba(91, 95, 212, 0.08);
            --gold: #b8941f;
            --gold-light: #d4af37;
            --gold-bright: #f0d060;
            --gold-ref: #c9a227;
            --gold-soft: rgba(184, 148, 31, 0.1);
            --success: #0d9668;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #2563eb;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 28px 20px;
            font-size: 14px;
            line-height: 1.55;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .invoice {
            width: 100%;
            max-width: 820px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 40px 44px;
            box-shadow: 0 20px 50px -20px rgba(90, 82, 69, .2),
                        0 1px 3px rgba(90, 82, 69, .05);
            animation: fadeInUp .4s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ========= HEADER — R-Jewel&Gold diamond logo ========= */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 24px;
            margin-bottom: 26px;
            border-bottom: 2px solid var(--primary);
            flex-wrap: wrap;
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        /* Diamond SVG logo — outlined gold */
        .header-brand-icon {
            width: 52px;
            height: 68px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .header-brand-icon svg {
            width: 100%;
            height: 100%;
            filter: drop-shadow(0 4px 12px rgba(201, 162, 39, 0.4));
        }

        .header-brand-text h1 {
            font-family: 'Playfair Display', 'Georgia', 'Times New Roman', serif;
            font-size: 1.45rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: -.01em;
            line-height: 1.1;
            color: var(--text-bright);
            white-space: nowrap;
        }
        .header-brand-text h1 .amp {
            font-style: italic;
            font-weight: 400;
            color: var(--gold);
            margin: 0 1px;
        }
        .header-brand-text p {
            font-size: .75rem;
            color: var(--text-dim);
            margin: 4px 0 0;
            font-weight: 500;
            letter-spacing: .04em;
        }

        .header-meta {
            text-align: right;
            min-width: 0;
        }
        .header-meta .invoice-label {
            font-size: .68rem;
            font-weight: 700;
            color: var(--text-dim);
            letter-spacing: .1em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .header-meta .invoice-number {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -.02em;
            line-height: 1.1;
        }

        /* ========= COMPANY INFO — Cebu shop details ========= */
        .company-info {
            text-align: center;
            padding: 16px 18px;
            background: var(--bg-soft);
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 26px;
            font-size: .78rem;
            color: var(--text-muted);
            line-height: 1.9;
        }
        .company-info i {
            color: var(--gold);
            font-size: .72rem;
            margin-right: 5px;
        }
        .company-info .sep {
            color: var(--text-dim);
            margin: 0 10px;
            font-weight: 300;
        }
        .company-info .hours {
            display: block;
            margin-top: 4px;
            font-size: .74rem;
            color: var(--text-dim);
        }

        /* ========= ORDER INFO ========= */
        .order-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 28px;
        }
        .info-block {
            background: var(--bg-soft);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px 20px;
        }
        .info-block .info-title {
            font-size: .68rem;
            font-weight: 700;
            color: var(--text-dim);
            letter-spacing: .1em;
            text-transform: uppercase;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-block .info-title i {
            color: var(--gold);
            font-size: .8rem;
        }
        .info-block .info-name {
            font-size: .95rem;
            font-weight: 700;
            color: var(--text-bright);
            margin-bottom: 6px;
        }
        .info-block .info-line {
            font-size: .8rem;
            color: var(--text-muted);
            margin-bottom: 3px;
            display: flex;
            align-items: flex-start;
            gap: 7px;
            line-height: 1.5;
        }
        .info-block .info-line i {
            color: var(--text-dim);
            font-size: .72rem;
            width: 12px;
            text-align: center;
            flex-shrink: 0;
            margin-top: 3px;
        }

        .meta-rows {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }
        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-size: .82rem;
        }
        .meta-row .meta-key {
            color: var(--text-dim);
            font-weight: 500;
        }
        .meta-row .meta-val {
            color: var(--text-bright);
            font-weight: 600;
            text-align: right;
        }

        /* ========= STATUS BADGES ========= */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .badge-pending    { background: rgba(217, 119, 6, .1);  color: #b45309; }
        .badge-processing { background: rgba(37, 99, 235, .1);  color: #1d4ed8; }
        .badge-shipped    { background: rgba(91, 95, 212, .1);  color: #4f46e5; }
        .badge-delivered  { background: rgba(13, 150, 104, .1); color: #0a7a54; }
        .badge-cancelled  { background: rgba(220, 38, 38, .1);  color: #b91c1c; }

        /* ========= ITEMS TABLE ========= */
        .items-wrap {
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead th {
            background: var(--bg-soft);
            padding: 13px 20px;
            text-align: left;
            font-size: .68rem;
            font-weight: 700;
            color: var(--text-dim);
            letter-spacing: .08em;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        thead th.num { text-align: right; }
        tbody td {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            font-size: .84rem;
            color: var(--text);
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background .15s ease; }
        tbody tr:hover { background: var(--bg-soft); }
        tbody td.item-name {
            color: var(--text-bright);
            font-weight: 600;
        }
        tbody td.num {
            text-align: right;
            font-weight: 600;
            color: var(--text-bright);
            font-variant-numeric: tabular-nums;
        }
        tbody td.price {
            color: var(--primary);
            font-weight: 700;
        }

        /* ========= TOTAL BLOCK ========= */
        .total-block {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 26px;
        }
        .total-inner {
            width: 100%;
            max-width: 340px;
            background: var(--bg-soft);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px 22px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            font-size: .82rem;
            color: var(--text-muted);
        }
        .total-row span:last-child {
            color: var(--text-bright);
            font-weight: 600;
        }
        .total-row.grand {
            border-top: 2px solid var(--border);
            margin-top: 8px;
            padding-top: 14px;
        }
        .total-row.grand span:first-child {
            color: var(--text-bright);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            font-size: .78rem;
        }
        .total-row.grand span:last-child {
            color: var(--primary);
            font-size: 1.3rem;
            font-weight: 700;
        }

        /* ========= FOOTER ========= */
        .footer {
            text-align: center;
            padding-top: 22px;
            border-top: 1px solid var(--border);
        }
        .footer .thanks {
            font-family: 'Playfair Display', 'Georgia', serif;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text-bright);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .footer .thanks i {
            color: var(--gold);
            font-size: .9rem;
        }
        .footer .note {
            font-size: .72rem;
            color: var(--text-dim);
            line-height: 1.6;
        }

        /* ========= PRINT BUTTON ========= */
        .btn-print {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 22px auto 0;
            padding: 11px 26px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s ease;
            box-shadow: 0 6px 18px -4px rgba(91, 95, 212, .35);
        }
        .btn-print:hover {
            background: #4f46e5;
            transform: translateY(-1px);
            box-shadow: 0 10px 24px -6px rgba(91, 95, 212, .5);
        }

        /* ========= RESPONSIVE ========= */
        @media (max-width: 640px) {
            body { padding: 16px 12px; }
            .invoice { padding: 26px 22px; border-radius: 14px; }
            .header { flex-direction: column; align-items: flex-start; gap: 14px; }
            .header-meta { text-align: left; }
            .header-brand-icon { width: 44px; height: 58px; }
            .header-brand-text h1 { font-size: 1.2rem; }
            .order-info { grid-template-columns: 1fr; }
            thead th, tbody td { padding: 11px 14px; font-size: .78rem; }
            .total-inner { max-width: 100%; }
            .company-info .sep { display: block; margin: 4px 0; opacity: .4; }
        }

        /* ========= PRINT ========= */
        @media print {
            body {
                background: #fff;
                padding: 0;
                display: block;
            }
            .btn-print { display: none !important; }
            .invoice {
                box-shadow: none;
                border: none;
                border-radius: 0;
                padding: 24px;
                max-width: 100%;
                animation: none;
            }
            .header { border-bottom-color: #5b5fd4; }
            .info-block,
            .items-wrap,
            .total-inner,
            .company-info {
                border-color: #ddd;
                page-break-inside: avoid;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            tbody tr:hover { background: transparent; }
            thead th {
                background: #f5f5f5 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .badge-status {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .header-brand-text h1 {
                -webkit-text-fill-color: #2b2620 !important;
                color: #2b2620 !important;
            }
            .header-brand-text h1 .amp {
                -webkit-text-fill-color: #b8941f !important;
                color: #b8941f !important;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>

    <div class="invoice">

        <!-- Header — R-Jewel&Gold diamond logo -->
        <div class="header">
            <div class="header-brand">
                <div class="header-brand-icon">
                    <svg viewBox="0 0 40 56" xmlns="http://www.w3.org/2000/svg" fill="none">
                        <path d="M4 18 L20 4 L36 18 L20 52 Z"
                              stroke="#c9a227" stroke-width="1.6"
                              stroke-linejoin="round" stroke-linecap="round"
                              fill="none"/>
                        <line x1="4" y1="18" x2="36" y2="18"
                              stroke="#c9a227" stroke-width="1.2"
                              stroke-linecap="round"/>
                        <line x1="4" y1="18" x2="14" y2="30"
                              stroke="#c9a227" stroke-width="0.9" opacity="0.65"/>
                        <line x1="36" y1="18" x2="26" y2="30"
                              stroke="#c9a227" stroke-width="0.9" opacity="0.65"/>
                    </svg>
                </div>
                <div class="header-brand-text">
                    <h1>R-Jewel<span class="amp">&amp;</span>Gold</h1>
                    <p>Fine Jewelry</p>
                </div>
            </div>
            <div class="header-meta">
                <div class="invoice-label">Invoice</div>
                <div class="invoice-number">#<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></div>
            </div>
        </div>

        <!-- Company contact strip — Cebu shop details -->
        <div class="company-info">
            <i class="fas fa-map-marker-alt"></i> Cebu City, Philippines
            <span class="sep">|</span>
            <i class="fas fa-phone"></i> +63 976 468 9323
            <span class="sep">|</span>
            <i class="fas fa-envelope"></i> info@jewelryshop.com
            <span class="hours">
                <i class="fas fa-clock"></i> Mon–Fri &middot; 9AM – 6PM
            </span>
        </div>

        <!-- Order info -->
        <div class="order-info">
            <div class="info-block">
                <div class="info-title"><i class="fas fa-user"></i> Invoice To</div>
                <div class="info-name"><?php echo htmlspecialchars($order['full_name']); ?></div>
                <?php if (!empty($order['email'])): ?>
                <div class="info-line"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($order['email']); ?></div>
                <?php endif; ?>
                <?php if (!empty($order['phone'])): ?>
                <div class="info-line"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($order['phone']); ?></div>
                <?php endif; ?>
                <?php if (!empty($order['address'])): ?>
                <div class="info-line"><i class="fas fa-map-marker-alt"></i> <?php echo nl2br(htmlspecialchars($order['address'])); ?></div>
                <?php endif; ?>
            </div>

            <div class="info-block">
                <div class="info-title"><i class="fas fa-file-invoice"></i> Invoice Details</div>
                <div class="meta-rows">
                    <div class="meta-row">
                        <span class="meta-key">Invoice #</span>
                        <span class="meta-val"><?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-key">Order Date</span>
                        <span class="meta-val"><?php echo date('M d, Y', strtotime($order['created_at'])); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-key">Payment</span>
                        <span class="meta-val"><?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-key">Status</span>
                        <span class="meta-val">
                            <span class="badge-status <?php echo $status_class; ?>">
                                <i class="fas <?php 
                                    echo $order['status'] == 'pending' ? 'fa-clock' : 
                                         ($order['status'] == 'processing' ? 'fa-cog' :
                                         ($order['status'] == 'shipped' ? 'fa-truck' :
                                         ($order['status'] == 'delivered' ? 'fa-check-circle' : 'fa-times-circle'))); 
                                ?>"></i>
                                <?php echo ucfirst($order['status']); ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Items -->
        <div class="items-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit Price</th>
                        <th class="num">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($order_items as $item): ?>
                    <tr>
                        <td class="item-name"><?php echo htmlspecialchars($item['name']); ?></td>
                        <td class="num"><?php echo $item['quantity']; ?></td>
                        <td class="num price">₱<?php echo number_format($item['price'], 2); ?></td>
                        <td class="num price">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="total-block">
            <div class="total-inner">
                <div class="total-row">
                    <span>Shipping</span>
                    <span>Free</span>
                </div>
                <div class="total-row">
                    <span>Tax</span>
                    <span>Included</span>
                </div>
                <div class="total-row grand">
                    <span>Total Amount</span>
                    <span>₱<?php echo number_format($order['total_amount'], 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="thanks">
                <i class="fas fa-gem"></i> Thank you for shopping with R-Jewel&Gold!
            </div>
            <div class="note">
                This is a computer-generated invoice. No signature required.
            </div>
        </div>
    </div>

    <!-- Print button -->
    <button class="btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Invoice
    </button>

    <script>
        // Auto print on load (optional — uncomment to enable)
        // window.print();
    </script>
</body>
</html>