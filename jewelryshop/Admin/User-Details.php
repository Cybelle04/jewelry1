<?php
// admin/user-details.php — returns an HTML fragment for the user modal
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo '<div class="modal-body"><p>Forbidden.</p></div>';
    exit();
}

require_once '../config/database.php';

$db = (new Database())->getConnection();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo '<div class="modal-body"><p>Invalid user ID.</p></div>';
    exit();
}

$stmt = $db->prepare("SELECT * FROM users WHERE id = :id AND role = 'user' LIMIT 1");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo '<div class="modal-body"><p>User not found.</p></div>';
    exit();
}

// Order stats
$orderStats = $db->prepare("
    SELECT COUNT(*) AS order_count,
           COALESCE(SUM(total_amount), 0) AS total_spent,
           MAX(created_at) AS last_order
    FROM orders
    WHERE user_id = :id AND status <> 'cancelled'
");
$orderStats->bindValue(':id', $id, PDO::PARAM_INT);
$orderStats->execute();
$stats = $orderStats->fetch(PDO::FETCH_ASSOC) ?: ['order_count'=>0,'total_spent'=>0,'last_order'=>null];

// Recent orders
$recent = $db->prepare("
    SELECT id, total_amount, status, created_at
    FROM orders
    WHERE user_id = :id
    ORDER BY created_at DESC
    LIMIT 10
");
$recent->bindValue(':id', $id, PDO::PARAM_INT);
$recent->execute();
$orders = $recent->fetchAll(PDO::FETCH_ASSOC);

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmtDate($v) { return $v ? (new DateTime($v))->format('M j, Y g:i A') : '—'; }
function fmtMoney($v) { return '₱' . number_format((float)$v, 2); }
?>
<style>
/* Scoped styles for user modal */
#userDetailsContent .um-section {
    background: var(--bg-soft);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 16px;
}
#userDetailsContent .um-section-title {
    display: flex; align-items: center; gap: 8px;
    font-size: .72rem; font-weight: 700;
    letter-spacing: .1em; text-transform: uppercase;
    color: var(--text-dim);
    margin: 0 0 14px;
}
#userDetailsContent .um-section-title i { color: var(--gold); font-size: .8rem; }

#userDetailsContent .um-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
#userDetailsContent .um-stat {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px 18px;
    text-align: center;
}
#userDetailsContent .um-stat h4 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-bright);
    letter-spacing: -.02em;
}
#userDetailsContent .um-stat p {
    margin: 4px 0 0;
    font-size: .72rem;
    color: var(--text-dim);
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: .06em;
}

#userDetailsContent .um-info-row {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 8px 0;
    font-size: .86rem;
    color: var(--text);
    border-bottom: 1px dashed var(--border);
}
#userDetailsContent .um-info-row:last-child { border-bottom: none; }
#userDetailsContent .um-info-row i {
    width: 18px; text-align: center;
    color: var(--text-dim);
    margin-top: 3px;
    font-size: .8rem;
}
#userDetailsContent .um-info-row strong {
    display: block;
    color: var(--text-bright);
    font-weight: 600;
    font-size: .86rem;
}

/* Order table */
#userDetailsContent .um-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .84rem;
}
#userDetailsContent .um-table thead th {
    text-align: left;
    padding: 10px 12px;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text-dim);
    border-bottom: 1px solid var(--border);
    background: transparent;
}
#userDetailsContent .um-table tbody td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    vertical-align: middle;
}
#userDetailsContent .um-table tbody tr:last-child td { border-bottom: none; }
#userDetailsContent .um-table tbody tr:hover { background: var(--card); }
#userDetailsContent .um-order-id {
    font-weight: 700;
    color: var(--text-bright);
}
#userDetailsContent .um-amount {
    font-weight: 700;
    color: var(--success);
}

/* Timeline */
#userDetailsContent .um-timeline {
    position: relative;
    padding-left: 28px;
}
#userDetailsContent .um-timeline::before {
    content: '';
    position: absolute;
    left: 13px; top: 8px; bottom: 8px;
    width: 2px;
    background: var(--border);
}
#userDetailsContent .um-tl-item {
    position: relative;
    padding: 10px 0 14px;
}
#userDetailsContent .um-tl-dot {
    position: absolute;
    left: -28px; top: 10px;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: var(--card);
    border: 2px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    color: var(--text-dim);
    font-size: .7rem;
    z-index: 1;
}
#userDetailsContent .um-tl-item.done .um-tl-dot {
    background: var(--success);
    border-color: var(--success);
    color: #fff;
}
#userDetailsContent .um-tl-item.current .um-tl-dot {
    background: var(--gold);
    border-color: var(--gold);
    color: #fff;
    box-shadow: 0 0 0 4px var(--gold-soft);
}
#userDetailsContent .um-tl-item h5 {
    margin: 0 0 3px;
    font-size: .88rem;
    font-weight: 600;
    color: var(--text-bright);
}
#userDetailsContent .um-tl-item p {
    margin: 0;
    font-size: .76rem;
    color: var(--text-dim);
    display: flex; align-items: center; gap: 6px;
}
#userDetailsContent .um-tl-item p i { font-size: .68rem; }

/* Badges */
#userDetailsContent .badge-status {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
#userDetailsContent .badge-pending    { background: rgba(217,119,6,.12);  color:#b45309; }
#userDetailsContent .badge-processing { background: rgba(37,99,235,.12);  color:#1d4ed8; }
#userDetailsContent .badge-shipped    { background: rgba(91,95,212,.12);  color:#4f46e5; }
#userDetailsContent .badge-delivered  { background: rgba(13,150,104,.12); color:#0a7a54; }
#userDetailsContent .badge-cancelled  { background: rgba(220,38,38,.12);  color:#b91c1c; }
#userDetailsContent .badge-paid       { background: rgba(13,150,104,.12); color:#0a7a54; }
#userDetailsContent .badge-unpaid     { background: rgba(220,38,38,.12);  color:#b91c1c; }

@media (max-width: 576px) {
    #userDetailsContent .um-grid { grid-template-columns: 1fr; }
}
</style>

<div class="modal-body" style="padding: 22px; max-height: 72vh; overflow-y: auto;">

    <!-- Customer header -->
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:18px;border-bottom:1px solid var(--border);">
        <div class="user-avatar" style="width:64px;height:64px;font-size:1.5rem;border-radius:16px;">
            <?= strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) ?>
        </div>
        <div style="min-width:0;flex:1;">
            <h4 style="margin:0;color:var(--text-bright);font-weight:700;font-size:1.15rem;letter-spacing:-.02em;">
                <?= h($user['full_name']) ?>
            </h4>
            <p style="margin:4px 0 0;color:var(--text-dim);font-size:.82rem;">
                @<?= h($user['username'] ?? 'user') ?> &nbsp;·&nbsp;
                <i class="fas fa-envelope" style="font-size:.7rem;"></i> <?= h($user['email']) ?>
            </p>
        </div>
    </div>

    <!-- Stats -->
    <div class="um-grid" style="margin-bottom:16px;">
        <div class="um-stat">
            <h4><?= (int)$stats['order_count'] ?></h4>
            <p><i class="fas fa-shopping-bag"></i> Total Orders</p>
        </div>
        <div class="um-stat">
            <h4 style="color:var(--success);"><?= fmtMoney($stats['total_spent']) ?></h4>
            <p><i class="fas fa-coins"></i> Total Spent</p>
        </div>
    </div>

    <!-- Contact info -->
    <div class="um-section">
        <h6 class="um-section-title">
            <i class="fas fa-id-card"></i> Contact Information
        </h6>
        <div class="um-info-row">
            <i class="fas fa-envelope"></i>
            <div><strong>Email</strong><?= h($user['email']) ?></div>
        </div>
        <div class="um-info-row">
            <i class="fas fa-phone"></i>
            <div><strong>Phone</strong><?= h($user['phone'] ?: 'N/A') ?></div>
        </div>
        <?php if (!empty($user['address'])): ?>
        <div class="um-info-row">
            <i class="fas fa-map-marker-alt"></i>
            <div><strong>Address</strong><?= h($user['address']) ?></div>
        </div>
        <?php endif; ?>
        <div class="um-info-row">
            <i class="fas fa-calendar-alt"></i>
            <div><strong>Joined</strong><?= h(fmtDate($user['created_at'] ?? null)) ?></div>
        </div>
    </div>

    <!-- Activity Timeline -->
    <div class="um-section">
        <h6 class="um-section-title">
            <i class="fas fa-stream"></i> Activity Timeline
        </h6>
        <div class="um-timeline">
            <div class="um-tl-item done">
                <div class="um-tl-dot"><i class="fas fa-user-plus"></i></div>
                <h5>Account Created</h5>
                <p><i class="fas fa-clock"></i> <?= h(fmtDate($user['created_at'] ?? null)) ?></p>
            </div>
            <?php if ($stats['last_order']): ?>
            <div class="um-tl-item current">
                <div class="um-tl-dot"><i class="fas fa-shopping-cart"></i></div>
                <h5>Last Order Placed</h5>
                <p><i class="fas fa-clock"></i> <?= h(fmtDate($stats['last_order'])) ?></p>
            </div>
            <?php else: ?>
            <div class="um-tl-item">
                <div class="um-tl-dot"><i class="fas fa-hourglass-half"></i></div>
                <h5>No Orders Yet</h5>
                <p><i class="fas fa-info-circle"></i> This customer hasn't placed any orders.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Orders -->
    <div class="um-section" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px 0;">
            <h6 class="um-section-title" style="margin-bottom:0;">
                <i class="fas fa-receipt"></i> Order History
            </h6>
        </div>
        <?php if (empty($orders)): ?>
            <div style="padding:30px 20px;text-align:center;color:var(--text-dim);font-size:.86rem;">
                <i class="fas fa-inbox" style="font-size:1.6rem;display:block;margin-bottom:10px;opacity:.5;"></i>
                No orders placed yet.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="um-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o):
                            $st = strtolower($o['status'] ?? 'pending');
                        ?>
                            <tr>
                                <td><span class="um-order-id">#<?= (int)$o['id'] ?></span></td>
                                <td style="color:var(--text-dim);font-size:.8rem;">
                                    <?= h(fmtDate($o['created_at'] ?? null)) ?>
                                </td>
                                <td><span class="um-amount"><?= fmtMoney($o['total_amount']) ?></span></td>
                                <td>
                                    <span class="badge-status badge-<?= h($st) ?>">
                                        <?= h(ucfirst($st)) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-footer" style="border-top:1px solid var(--border);padding:16px 22px;display:flex;justify-content:flex-end;gap:10px;">
    <button type="button" class="btn-cancel" data-bs-dismiss="modal"
            style="background:transparent;border:1px solid var(--border);color:var(--text-muted);border-radius:10px;font-size:.86rem;font-weight:500;padding:12px 24px;cursor:pointer;">
        Close
    </button>
</div>