<?php
// admin/categories.php - Professional Category Management
// (with global cross-browser/device notification sync)
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if(!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// ============================================================
// INLINE NOTIFICATION SYNC ENDPOINT (self POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_notif_sync'])) {
    header('Content-Type: application/json; charset=utf-8');

    $db->exec("CREATE TABLE IF NOT EXISTS notification_reads (
        notification_id VARCHAR(64) NOT NULL PRIMARY KEY,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS notification_deletes (
        notification_id VARCHAR(64) NOT NULL PRIMARY KEY,
        deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $action = strtolower((string)($_POST['action'] ?? ''));
    $ids    = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [$ids];
    $ids = array_values(array_filter(
        array_map(fn($v) => trim((string)$v), $ids),
        fn($v) => $v !== '' && strlen($v) <= 64
    ));

    if (empty($ids)) {
        echo json_encode(['success' => true, 'count' => 0]);
        exit();
    }

    try {
        if ($action === 'read') {
            $ph = implode(',', array_fill(0, count($ids), '(?, NOW())'));
            $stmt = $db->prepare("INSERT INTO notification_reads (notification_id, read_at) VALUES $ph
                                  ON DUPLICATE KEY UPDATE read_at = read_at");
            $stmt->execute($ids);
            echo json_encode(['success' => true, 'count' => count($ids)]);
        } elseif ($action === 'unread') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM notification_reads WHERE notification_id IN ($ph)");
            $stmt->execute($ids);
            echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
        } elseif ($action === 'delete') {
            $ph = implode(',', array_fill(0, count($ids), '(?, NOW())'));
            $stmt = $db->prepare("INSERT INTO notification_deletes (notification_id, deleted_at) VALUES $ph
                                  ON DUPLICATE KEY UPDATE deleted_at = deleted_at");
            $stmt->execute($ids);
            $stmt2 = $db->prepare("INSERT INTO notification_reads (notification_id, read_at) VALUES $ph
                                   ON DUPLICATE KEY UPDATE read_at = read_at");
            $stmt2->execute($ids);
            echo json_encode(['success' => true, 'count' => count($ids)]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ============================================================
// HELPERS
// ============================================================
function notifReadIds(PDO $db): array {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notification_reads (
            notification_id VARCHAR(64) NOT NULL PRIMARY KEY,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return array_flip($db->query("SELECT notification_id FROM notification_reads")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}
function notifDeletedIds(PDO $db): array {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notification_deletes (
            notification_id VARCHAR(64) NOT NULL PRIMARY KEY,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return array_flip($db->query("SELECT notification_id FROM notification_deletes")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

// ============================================================
// BULK ACTIONS
// ============================================================
if(isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $category_ids = $_POST['category_ids'] ?? [];

    if(!empty($category_ids)) {
        $ids = implode(',', array_map('intval', $category_ids));

        switch($action) {
            case 'delete':
                $sel = $db->prepare("SELECT image FROM categories WHERE id IN ($ids)");
                $sel->execute();
                foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $img) {
                    if ($img && file_exists(__DIR__ . '/../uploads/categories/' . $img)) {
                        @unlink(__DIR__ . '/../uploads/categories/' . $img);
                    }
                }
                $query = "DELETE FROM categories
                          WHERE id IN ($ids)
                          AND id NOT IN (SELECT DISTINCT category_id FROM products WHERE category_id IS NOT NULL)";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $_SESSION['message'] = "Categories deleted successfully! (Categories with products were skipped.)";
                break;
        }
        header("Location: categories.php");
        exit();
    }
}

// ============================================================
// INDIVIDUAL DELETION
// ============================================================
if(isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    $check = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = :id");
    $check->bindParam(':id', $id);
    $check->execute();
    $count = (int)$check->fetchColumn();

    if($count > 0) {
        $_SESSION['error'] = "Cannot delete category — it still has $count product(s) assigned.";
    } else {
        $imgStmt = $db->prepare("SELECT image FROM categories WHERE id = :id");
        $imgStmt->bindParam(':id', $id);
        $imgStmt->execute();
        $imgFile = $imgStmt->fetchColumn();
        if ($imgFile && file_exists(__DIR__ . '/../uploads/categories/' . $imgFile)) {
            @unlink(__DIR__ . '/../uploads/categories/' . $imgFile);
        }

        $stmt = $db->prepare("DELETE FROM categories WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $_SESSION['message'] = "Category deleted successfully!";
    }
    header("Location: categories.php");
    exit();
}

// ============================================================
// FILTERS
// ============================================================
$search = $_GET['search'] ?? '';
$sort   = $_GET['sort']   ?? 'name';

$where = [];
$params = [];
if($search) {
    $where[] = "(c.name LIKE :search OR c.description LIKE :search)";
    $params[':search'] = "%$search%";
}
$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

$order_map = [
    'name'      => 'c.name ASC',
    'name_desc' => 'c.name DESC',
    'products'  => 'product_count DESC, c.name ASC',
    'newest'    => 'c.created_at DESC',
];
$order_by = $order_map[$sort] ?? 'c.name ASC';

$query = "SELECT c.*,
                 (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
          FROM categories c
          $where_clause
          ORDER BY $order_by";
$stmt = $db->prepare($query);
foreach($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_categories         = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$total_products           = $db->query("SELECT COUNT(*) FROM products WHERE category_id IS NOT NULL")->fetchColumn();
$categories_with_products = $db->query("SELECT COUNT(DISTINCT category_id) FROM products WHERE category_id IS NOT NULL")->fetchColumn();
$empty_categories         = max(0, $total_categories - $categories_with_products);

function categoriesFormatTime($value) {
    if (!$value) return '—';
    try { $dt = new DateTime($value); } catch (Throwable $e) { return '—'; }
    return $dt->format('M j, Y g:i A');
}
function categoriesIsRecent($value, $hours = 24) {
    if (!$value) return false;
    $ts = strtotime($value);
    if (!$ts) return false;
    return (time() - $ts) < ($hours * 3600);
}

// ============================================================
// BUILD NOTIFICATION FEED
// ============================================================
$admin_notifications = [];

// Orders
try {
    $rows = $db->query("SELECT o.id, o.total_amount, o.status, o.created_at, u.full_name
                        FROM orders o LEFT JOIN users u ON u.id = o.user_id
                        ORDER BY o.created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $status = strtolower((string)($r['status'] ?? 'pending'));
        $isNew  = in_array($status, ['pending','new','placed'], true) && categoriesIsRecent($r['created_at'] ?? null, 24);
        $admin_notifications[] = [
            'id' => 'order-' . $r['id'], 'icon' => 'shopping-cart', 'iconClass' => 'blue',
            'title' => ($isNew ? 'New order #' : 'Order #') . $r['id'] . ($isNew ? ' received' : ' · ' . ucfirst($status)),
            'desc'  => trim((string)($r['full_name'] ?? 'Customer')) . ' · ' . number_format((float)($r['total_amount'] ?? 0), 2) . ' · Status: ' . ucfirst($status),
            'meta'  => categoriesFormatTime($r['created_at'] ?? null),
            'unread' => $isNew,
            'link' => 'orders.php?id=' . (int)$r['id'],
            '_ts' => strtotime($r['created_at'] ?? '') ?: 0,
        ];
    }
} catch (Throwable $e) {}

// Lay-Away
try {
    $rows = $db->query("SELECT lp.id, lp.total_amount, lp.status, lp.created_at, u.full_name
                        FROM layaway_plans lp
                        LEFT JOIN users u ON u.id = lp.user_id
                        ORDER BY lp.created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $status = strtolower((string)($r['status'] ?? 'pending'));
        $isNew = in_array($status, ['pending','new','active'], true) && categoriesIsRecent($r['created_at'] ?? null, 24);
        $admin_notifications[] = [
            'id'        => 'layaway-' . $r['id'],
            'icon'      => 'hand-holding-heart',
            'iconClass' => 'purple',
            'title'     => ($isNew ? 'New lay-away #LY-' : 'Lay-away #LY-') . str_pad($r['id'], 4, '0', STR_PAD_LEFT) . ($isNew ? ' request' : ' · ' . ucfirst($status)),
            'desc'      => trim((string)($r['full_name'] ?? 'Customer')) . ' · ₱' . number_format((float)($r['total_amount'] ?? 0), 2) . ' · Status: ' . ucfirst($status),
            'meta'      => categoriesFormatTime($r['created_at'] ?? null),
            'unread'    => $isNew,
            'link'      => 'layaway.php?id=' . (int)$r['id'],
            '_ts'       => strtotime($r['created_at'] ?? '') ?: 0,
        ];
    }
} catch (Throwable $e) {}

// Low stock
try {
    $rows = $db->query("SELECT id, name, stock FROM products WHERE stock <= 5 ORDER BY stock ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $admin_notifications[] = [
            'id' => 'stock-' . $r['id'], 'icon' => 'gem', 'iconClass' => 'gold',
            'title' => 'Low stock: ' . (string)$r['name'],
            'desc'  => 'Only ' . (int)$r['stock'] . ' unit(s) remaining.',
            'meta'  => date('M j, Y g:i A'),
            'unread' => true,
            'link' => 'products.php?focus=' . (int)$r['id'],
            '_ts' => time(),
        ];
    }
} catch (Throwable $e) {}

// New users
try {
    $rows = $db->query("SELECT id, full_name, created_at FROM users WHERE role = 'user' ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $isRecent = categoriesIsRecent($r['created_at'] ?? null, 24);
        $admin_notifications[] = [
            'id' => 'customer-' . $r['id'], 'icon' => 'user-plus', 'iconClass' => 'green',
            'title' => 'New user: ' . (string)$r['full_name'],
            'desc'  => 'User account registered.' . ($isRecent ? '' : ' (older)'),
            'meta'  => categoriesFormatTime($r['created_at'] ?? null),
            'unread' => $isRecent,
            'link' => 'users.php?open=' . (int)$r['id'],
            '_ts' => strtotime($r['created_at'] ?? '') ?: 0,
        ];
    }
} catch (Throwable $e) {}

// ============================================================
// APPLY GLOBAL SERVER-SIDE READ / DELETE STATE
// ============================================================
$readIds    = notifReadIds($db);
$deletedIds = notifDeletedIds($db);

$admin_notifications = array_values(array_filter(
    $admin_notifications,
    fn($n) => !isset($deletedIds[(string)$n['id']])
));

foreach ($admin_notifications as &$n) {
    if (isset($readIds[(string)$n['id']])) {
        $n['unread'] = false;
    }
}
unset($n);

usort($admin_notifications, function ($a, $b) {
    return ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0);
});
foreach ($admin_notifications as &$n) { unset($n['_ts']); }
unset($n);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Categories Management | R-Jewel&Gold</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400;1,700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --bg: #f2ede4; --bg-soft: #faf7f2; --card: #ffffff; --card-hover: #faf8f4;
            --border: #e5ddd0; --border-light: #ede7db; --text: #5a5245; --text-bright: #2b2620;
            --text-muted: #8a8172; --text-dim: #b3a998; --primary: #5b5fd4;
            --primary-soft: rgba(91, 95, 212, 0.08); --gold: #b8941f; --gold-light: #d4af37;
            --gold-bright: #f0d060; --gold-ref: #c9a227; --gold-soft: rgba(184, 148, 31, 0.1);
            --success: #0d9668; --warning: #d97706; --danger: #dc2626; --info: #2563eb;
            --nav-bg: #1e2533; --nav-text: #8b92a5; --nav-text-bright: #ffffff;
            --nav-border: #2a3142; --nav-hover: #252d3d; --sidebar-bg: #1a2030;
            --sidebar-hover: #252d3d; --sidebar-active: rgba(91, 95, 212, 0.15);
            --sidebar-text: #8b92a5; --sidebar-text-bright: #ffffff; --sidebar-border: #2a3142;
        }
        * { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color: transparent; }
        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text);
            overflow-x: hidden; min-height: 100vh; font-size: 15px; line-height: 1.5; }
        .sidebar-overlay { display:none; position:fixed; inset:0; background: rgba(30,37,51,0.4);
            backdrop-filter: blur(3px); z-index:998; opacity:0; transition: opacity .3s ease; }
        .sidebar-overlay.active { display:block; opacity:1; }
        .top-nav { position: fixed; top:0; left:340px; right:0; height:80px; background: var(--nav-bg);
            border-bottom: 1px solid var(--nav-border); z-index: 997; display:flex;
            align-items:center; justify-content:space-between; padding: 0 30px;
            transition: left .3s cubic-bezier(.4,0,.2,1); box-shadow: 0 2px 12px rgba(0,0,0,.15); }
        .nav-left { display:flex; align-items:center; gap:16px; flex:1; min-width:0; }
        .nav-menu-btn { display:none; width:44px; height:44px; border-radius:12px;
            background: rgba(255,255,255,.06); border:1px solid var(--nav-border);
            color: var(--nav-text); font-size:1.1rem; align-items:center; justify-content:center;
            cursor:pointer; transition: all .2s ease; flex-shrink:0; }
        .nav-menu-btn:hover { background: rgba(255,255,255,.1); color: var(--nav-text-bright); }
        .nav-search { position:relative; max-width:420px; width:100%; min-width:0; }
        .nav-search i { position:absolute; left:16px; top:50%; transform:translateY(-50%);
            color:#6b7280; font-size:.95rem; pointer-events:none; }
        .nav-search input { width:100%; background: rgba(255,255,255,.06);
            border:1px solid var(--nav-border); border-radius:12px; padding: 11px 16px 11px 44px;
            color: var(--nav-text-bright); font-size:.9rem; font-family:'Inter', sans-serif;
            transition: all .2s ease; }
        .nav-search input::placeholder { color:#6b7280; }
        .nav-search input:focus { outline:none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(91,95,212,.2); background: rgba(255,255,255,.08); }
        .nav-right { display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .nav-dropdown-wrapper { position:relative; }
        .nav-icon-btn { position:relative; width:46px; height:46px; border-radius:12px;
            background: transparent; border:1px solid transparent; color:#8b92a5; font-size:1.1rem;
            display:flex; align-items:center; justify-content:center; cursor:pointer;
            transition: all .2s ease; text-decoration:none; }
        .nav-icon-btn:hover, .nav-icon-btn.active { background: var(--nav-hover);
            border-color: var(--nav-border); color: var(--nav-text-bright); }
        .nav-badge { position:absolute; top:6px; right:6px; min-width:20px; height:20px;
            padding: 0 5px; background: var(--danger); border-radius:10px; color:#fff;
            font-size:.65rem; font-weight:700; display:flex; align-items:center;
            justify-content:center; border:2px solid var(--nav-bg); line-height:1; }
        .nav-badge.hidden { display:none; }
        .nav-dropdown-panel { position:absolute; top:calc(100% + 12px); right:0;
            width: 400px; max-width: calc(100vw - 24px); background: var(--nav-bg);
            border:1px solid var(--nav-border); border-radius:16px;
            box-shadow: 0 20px 40px -10px rgba(0,0,0,.6); opacity:0; visibility:hidden;
            transform: translateY(-8px); transition: all .22s ease; z-index:1001; overflow:hidden; }
        .nav-dropdown-panel.open { opacity:1; visibility:visible; transform: translateY(0); }
        .panel-header { display:flex; justify-content:space-between; align-items:center;
            padding: 16px 18px; border-bottom:1px solid var(--nav-border); }
        .panel-header h5 { margin:0; color: var(--nav-text-bright); font-size:.95rem; font-weight:600; }
        .panel-header .mark-all { background:none; border:none; color:#818cf8;
            font-size:.78rem; font-weight:600; cursor:pointer; padding: 4px 8px;
            border-radius:6px; transition: background .15s ease; }
        .panel-header .mark-all:hover { background: rgba(129,140,248,.1); }
        .panel-body { max-height:400px; overflow-y:auto; overscroll-behavior:contain; }
        .panel-item { display:flex; gap:14px; padding: 14px 18px;
            border-bottom:1px solid rgba(42,49,66,.5); cursor:pointer;
            transition: background .15s ease; text-decoration:none; position:relative;
            color: inherit; }
        .panel-item:last-child { border-bottom:none; }
        .panel-item:hover { background: var(--nav-hover); }
        .panel-item.unread { background: rgba(91,95,212,.06); }
        .panel-item.unread::before { content:''; position:absolute; left:8px; top:50%;
            transform: translateY(-50%); width:6px; height:6px;
            background:#818cf8; border-radius:50%; }
        a.panel-item { cursor: pointer; }
        .item-icon { width:42px; height:42px; border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            font-size:1rem; flex-shrink:0; }
        .item-icon.blue   { background: rgba(37,99,235,.15);  color:#60a5fa; }
        .item-icon.green  { background: rgba(13,150,104,.15); color:#34d399; }
        .item-icon.gold   { background: rgba(184,148,31,.15); color:#d4af37; }
        .item-icon.red    { background: rgba(220,38,38,.15);  color:#f87171; }
        .item-icon.purple { background: rgba(139,92,246,.15); color:#a78bfa; }
        .item-content { flex:1; min-width:0; }
        .item-content p { margin:0; color: var(--nav-text-bright); font-size:.86rem; font-weight:500; line-height:1.4; }
        .item-content .item-meta { margin:5px 0 0; color:#9ca3af; font-size:.74rem;
            display:flex; align-items:center; gap:6px; font-variant-numeric: tabular-nums; }
        .item-content .item-meta i { font-size:.65rem; color:#6b7280; }
        .panel-footer { padding: 12px 18px; border-top:1px solid var(--nav-border); text-align:center; }
        .panel-footer a { color:#818cf8; font-size:.8rem; font-weight:600;
            text-decoration:none; display:inline-flex; align-items:center; gap:6px;
            cursor:pointer; background:none; border:none; padding:0; }
        .panel-footer a:hover { color:#a5b4fc; }
        .panel-empty { padding: 44px 20px; text-align:center; color:#6b7280; }
        .panel-empty i { font-size:2.2rem; margin-bottom:12px; opacity:.5; }
        .panel-empty p { margin:0; font-size:.88rem; }
        .nav-divider { width:1px; height:32px; background: var(--nav-border); margin:0 8px; }
        .nav-user { display:flex; align-items:center; gap:12px;
            padding: 6px 12px 6px 6px; border-radius:12px; cursor:pointer;
            transition: background .2s ease; position:relative; border:1px solid transparent; }
        .nav-user:hover { background: var(--nav-hover); border-color: var(--nav-border); }
        .nav-user-avatar { width:40px; height:40px;
            background: linear-gradient(135deg, var(--gold), #8a6e14);
            border-radius:11px; display:flex; align-items:center; justify-content:center;
            color:#fff; font-weight:600; font-size:1.35rem; flex-shrink:0; line-height:1; }
        .nav-user-info { text-align:left; line-height:1.25; }
        .nav-user-info strong { display:block; color: var(--nav-text-bright);
            font-size:.86rem; font-weight:600; }
        .nav-user-info small { color:#6b7280; font-size:.7rem; }
        .nav-user-chevron { color:#6b7280; font-size:.75rem; transition: transform .2s ease; }
        .nav-user.open .nav-user-chevron { transform: rotate(180deg); }
        .nav-dropdown { position:absolute; top:calc(100% + 10px); right:0;
            min-width:220px; background:#252d3d; border:1px solid var(--nav-border);
            border-radius:12px; padding:8px; box-shadow: 0 15px 35px -10px rgba(0,0,0,.5);
            opacity:0; visibility:hidden; transform: translateY(-6px);
            transition: all .2s ease; z-index:1000; }
        .nav-user.open .nav-dropdown { opacity:1; visibility:visible; transform: translateY(0); }
        .nav-dropdown a { display:flex; align-items:center; gap:12px;
            padding: 10px 14px; border-radius:8px; color:#8b92a5;
            text-decoration:none; font-size:.86rem; font-weight:500; transition: all .15s ease; }
        .nav-dropdown a:hover { background: rgba(255,255,255,.06); color: var(--nav-text-bright); }
        .nav-dropdown a.danger { color:#f87171; }
        .nav-dropdown a.danger:hover { background: rgba(220,38,38,.12); }
        .nav-dropdown a i { width:20px; text-align:center; font-size:.9rem; }
        .nav-dropdown-divider { height:1px; background: var(--nav-border); margin:6px 8px; }
        .sidebar { background: var(--sidebar-bg); border-right:1px solid var(--sidebar-border);
            min-height:100vh; position:fixed; top:0; left:0; z-index:999; width:340px;
            transition: transform .3s cubic-bezier(.4,0,.2,1); display:flex;
            flex-direction:column; box-shadow: 4px 0 25px rgba(0,0,0,.2); }
        .sidebar .brand { padding: 18px 24px; border-bottom: 1px solid var(--sidebar-border);
            display: flex; align-items: center; gap: 14px; flex-shrink: 0; height: 96px;
            background: linear-gradient(180deg, #1f2632 0%, #1a2030 100%); }
        .brand-icon { width: 38px; height: 54px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-icon svg { width: 100%; height: 100%; filter: drop-shadow(0 0 6px rgba(201, 162, 39, 0.35)); }
        .brand-text { min-width: 0; display: flex; flex-direction: column; justify-content: center; }
        .brand-text h4 {
            font-family: 'Playfair Display', 'Georgia', 'Times New Roman', serif;
            font-size: 1.5rem; font-weight: 700; margin: 0;
            line-height: 1.05; letter-spacing: -0.01em; color: #ffffff;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .brand-text h4 .amp {
            font-family: 'Playfair Display', 'Georgia', serif;
            font-style: italic; font-weight: 400;
            color: #c9a227; -webkit-text-fill-color: #c9a227;
            margin: 0 1px; letter-spacing: 0;
        }
        .brand-text p { font-family: 'Inter', sans-serif; font-size: 0.62rem;
            color: #8b92a5; margin: 5px 0 0;
            letter-spacing: 0.24em; text-transform: uppercase; font-weight: 500; white-space: nowrap; }
        .sidebar-nav { padding: 18px 14px; flex:1; overflow-y:auto; overscroll-behavior:contain; }
        .nav-item { margin-bottom:4px; }
        .sidebar .sidebar-nav .nav-link,
        .sidebar .sidebar-nav .nav-link:link,
        .sidebar .sidebar-nav .nav-link:visited {
            color: var(--sidebar-text);
            background: transparent;
            text-decoration: none;
            padding: 14px 18px;
            border-radius: 12px;
            transition: all .2s ease;
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: .94rem;
            font-weight: 500;
            white-space: nowrap;
        }
        .sidebar .sidebar-nav .nav-link i {
            width: 24px; font-size: 1.1rem; text-align: center; flex-shrink: 0;
            color: inherit;
        }
        .sidebar .sidebar-nav .nav-link:hover,
        .sidebar .sidebar-nav .nav-link:hover i {
            background: var(--sidebar-hover);
            color: var(--sidebar-text-bright);
        }
        .sidebar .sidebar-nav .nav-link.active,
        .sidebar .sidebar-nav .nav-link.active i {
            background: var(--sidebar-active);
            color: #a5b4fc;
            font-weight: 600;
            box-shadow: inset 4px 0 0 var(--primary);
        }
        .main-content { margin-left:340px; padding: 104px 30px 30px;
            min-height:100vh; transition: margin-left .3s ease; }
        .page-header { background: var(--card); border-radius:16px;
            padding: 20px 26px; margin-bottom:24px; border:1px solid var(--border);
            display:flex; justify-content:space-between; align-items:center;
            box-shadow: 0 1px 3px rgba(90,82,69,.05); gap:16px; flex-wrap:wrap; }
        .page-header h2 { font-size:1.4rem; font-weight:700; margin:0;
            color: var(--text-bright); letter-spacing:-.02em; }
        .page-header p { margin:4px 0 0; color: var(--text-dim); font-size:.82rem; }
        .page-header-actions { display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .stats-grid { display:grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap:20px; margin-bottom:24px; }
        .stat-card { background: var(--card); border-radius:16px; padding:24px;
            border:1px solid var(--border); transition: all .25s ease;
            box-shadow: 0 1px 3px rgba(90,82,69,.05); position:relative; overflow:hidden; }
        .stat-card::before { content:''; position:absolute; top:0; left:0;
            width:4px; height:100%; background: var(--primary); opacity:.7; }
        .stat-card.gold::before    { background: var(--gold); }
        .stat-card.success::before { background: var(--success); }
        .stat-card.warning::before { background: var(--warning); }
        .stat-card.danger::before  { background: var(--danger); }
        .stat-card.info::before    { background: var(--info); }
        .stat-card:hover { transform: translateY(-3px);
            box-shadow: 0 10px 25px -8px rgba(90,82,69,.15);
            border-color: var(--border-light); }
        .stat-header { display:flex; justify-content:space-between;
            align-items:flex-start; margin-bottom:16px; gap:10px; }
        .stat-icon { width:52px; height:52px; border-radius:14px;
            display:flex; align-items:center; justify-content:center;
            font-size:1.3rem; background: var(--primary-soft); color: var(--primary); flex-shrink:0; }
        .stat-icon.gold    { background: var(--gold-soft); color: var(--gold); }
        .stat-icon.success { background: rgba(13,150,104,.08); color: var(--success); }
        .stat-icon.warning { background: rgba(217,119,6,.08);  color: var(--warning); }
        .stat-icon.danger  { background: rgba(220,38,38,.08);  color: var(--danger); }
        .stat-icon.info    { background: rgba(37,99,235,.08);  color: var(--info); }
        .stat-change { font-size:.72rem; font-weight:600; padding: 5px 11px;
            border-radius:20px; background: rgba(13,150,104,.08); color: var(--success);
            display:inline-flex; align-items:center; gap:4px; white-space:nowrap; }
        .stat-change.down { background: rgba(220,38,38,.08); color: var(--danger); }
        .stat-change.info { background: rgba(37,99,235,.08); color: var(--info); }
        .stat-change.warning { background: rgba(217,119,6,.08); color: var(--warning); }
        .stat-info h3 { font-size:1.8rem; font-weight:700; margin:0;
            color: var(--text-bright); letter-spacing:-.03em; }
        .stat-info p { margin:4px 0 0; color: var(--text-muted); font-size:.82rem; font-weight:500; }
        .card-modern { background: var(--card); border-radius:16px; padding:26px;
            margin-bottom:24px; border:1px solid var(--border);
            box-shadow: 0 1px 3px rgba(90,82,69,.05); }
        .card-header-modern { display:flex; justify-content:space-between;
            align-items:center; margin-bottom:20px; padding-bottom:16px;
            border-bottom:1px solid var(--border); gap:12px; flex-wrap:wrap; }
        .card-header-modern h4 { margin:0; font-weight:700;
            color: var(--text-bright); font-size:1.05rem; letter-spacing:-.01em; }
        .card-header-modern p { margin:4px 0 0; color: var(--text-dim); font-size:.78rem; }
        .filter-bar { background: var(--card); border-radius:16px; padding:20px;
            margin-bottom:24px; border:1px solid var(--border);
            box-shadow: 0 1px 3px rgba(90,82,69,.05); }
        .filter-bar .form-control, .filter-bar .form-select {
            background: var(--bg-soft); border:1px solid var(--border);
            border-radius:10px; padding: 11px 16px; color: var(--text);
            font-size:.86rem; font-family:'Inter', sans-serif; transition: all .2s ease; }
        .filter-bar .form-control:focus, .filter-bar .form-select:focus {
            outline:none; border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft); background: var(--card); }
        .filter-bar .form-control::placeholder { color: var(--text-dim); }
        .search-input-wrapper { position: relative; }
        .search-input-wrapper .search-spinner {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            width: 16px; height: 16px; border: 2px solid var(--border);
            border-top-color: var(--primary); border-radius: 50%;
            animation: spin 0.7s linear infinite;
            opacity: 0; visibility: hidden; transition: opacity .2s ease; pointer-events: none; }
        .search-input-wrapper.is-searching .search-spinner { opacity: 1; visibility: visible; }
        @keyframes spin { to { transform: translateY(-50%) rotate(360deg); } }
        .table-modern { margin-bottom:0; color: var(--text); width:100%; }
        .table-modern th { border:none; border-bottom:1px solid var(--border);
            font-weight:600; color: var(--text-dim); padding: 13px 10px;
            font-size:.74rem; letter-spacing:.08em; text-transform:uppercase;
            background: transparent; white-space:nowrap; }
        .table-modern td { padding: 15px 10px; vertical-align:middle;
            border-bottom:1px solid var(--border); color: var(--text);
            font-size:.88rem; background: transparent; }
        .table-modern tbody tr { transition: background .15s ease; }
        .table-modern tbody tr:hover { background: var(--bg); }
        .table-modern tbody tr:last-child td { border-bottom:none; }
        .table-modern strong { color: var(--text-bright); font-weight:600; }
        .category-icon { width:48px; height:48px;
            background: linear-gradient(135deg, var(--gold-soft), var(--primary-soft));
            border-radius:12px; display:flex; align-items:center; justify-content:center;
            font-size:1.15rem; color: var(--gold); border:1px solid var(--border); }
        .category-thumb { width:56px; height:56px; border-radius:12px;
            object-fit:cover; border:1px solid var(--border);
            background: var(--bg-soft); display: block; }
        .category-thumb-placeholder { width:56px; height:56px; border-radius:12px;
            border:1px solid var(--border);
            background: linear-gradient(135deg, var(--gold-soft), var(--primary-soft));
            display:flex; align-items:center; justify-content:center;
            color: var(--gold); font-size:1.1rem; }
        .product-count-badge { background: var(--primary-soft); color: var(--primary);
            padding: 5px 13px; border-radius:20px;
            font-size:.74rem; font-weight:600; display:inline-flex;
            align-items:center; gap:5px; white-space:nowrap; }
        .product-count-badge.zero { background: var(--bg-soft); color: var(--text-dim); }
        .image-upload-box { border: 2px dashed var(--border);
            border-radius: 12px; padding: 20px; text-align: center;
            transition: all .2s ease; cursor: pointer;
            background: var(--bg-soft); position: relative; }
        .image-upload-box:hover { border-color: var(--primary); background: var(--primary-soft); }
        .image-upload-box.has-image { border-style: solid; border-color: var(--border);
            background: var(--card); padding: 12px; }
        .image-upload-box input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .image-upload-icon { font-size: 2rem; color: var(--text-dim); margin-bottom: 8px; }
        .image-upload-text { font-size: .82rem; color: var(--text-muted); font-weight: 500; }
        .image-upload-hint { font-size: .72rem; color: var(--text-dim); margin-top: 4px; }
        .image-preview { max-width: 100%; max-height: 160px;
            border-radius: 10px; display: block; margin: 0 auto; }
        .image-preview-remove { display: inline-flex; align-items: center; gap: 5px;
            margin-top: 10px; padding: 5px 12px;
            background: rgba(220,38,38,.08);
            border: 1px solid rgba(220,38,38,.2);
            color: var(--danger); border-radius: 8px;
            font-size: .74rem; font-weight: 600; cursor: pointer;
            transition: all .2s ease; }
        .image-preview-remove:hover { background: rgba(220,38,38,.15); }
        .btn-modern { background: var(--primary); border:none; color:#fff;
            border-radius:10px; font-size:.82rem; font-weight:600;
            padding: 10px 20px; transition: all .2s ease;
            text-decoration:none; display:inline-flex; align-items:center;
            gap:8px; white-space:nowrap; cursor:pointer; }
        .btn-modern:hover { background:#4f46e5; color:#fff; transform: translateY(-1px);
            box-shadow: 0 6px 18px -3px rgba(91,95,212,.35); }
        .btn-outline-modern { background: transparent; border:1px solid var(--border);
            color: var(--text-muted); border-radius:8px; font-size:.78rem;
            font-weight:500; padding: 6px 14px; transition: all .2s ease;
            text-decoration:none; display:inline-flex; align-items:center;
            gap:6px; white-space:nowrap; cursor:pointer; }
        .btn-outline-modern:hover { background: var(--primary-soft);
            border-color: var(--primary); color: var(--primary); }
        .btn-outline-modern.danger:hover { background: rgba(220,38,38,.08);
            border-color: var(--danger); color: var(--danger); }
        .btn-sm-modern { padding: 6px 14px; font-size:.76rem; }
        .icon-action { width:36px; height:36px; border-radius:9px;
            background: transparent; border:1px solid var(--border);
            color: var(--text-muted); font-size:.85rem;
            display:inline-flex; align-items:center; justify-content:center;
            cursor:pointer; transition: all .2s ease; text-decoration:none; }
        .icon-action:hover { background: var(--primary-soft);
            border-color: var(--primary); color: var(--primary); }
        .icon-action.danger:hover { background: rgba(220,38,38,.08);
            border-color: var(--danger); color: var(--danger); }
        .icon-action:disabled, .icon-action.disabled {
            opacity:.45; cursor:not-allowed; background: var(--bg-soft); }
        .icon-action:disabled:hover, .icon-action.disabled:hover {
            background: var(--bg-soft); border-color: var(--border); color: var(--text-dim); }
        .form-check-input { border:1px solid var(--border); background-color: var(--bg-soft); }
        .form-check-input:checked { background-color: var(--primary); border-color: var(--primary); }
        .form-check-input:focus { border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft); }
        .alert-modern { border-radius:12px; padding: 14px 18px;
            font-size:.86rem; margin-bottom:20px;
            display:flex; justify-content:space-between; align-items:center; gap:12px; }
        .alert-modern.success { background: rgba(13,150,104,.08);
            border:1px solid rgba(13,150,104,.2); color: #0a7a54; }
        .alert-modern.danger { background: rgba(220,38,38,.06);
            border:1px solid rgba(220,38,38,.2); color: #b91c1c; }
        .alert-modern button { background:none; border:none; color: inherit; cursor:pointer;
            font-size:.9rem; opacity:.7; }
        .alert-modern button:hover { opacity:1; }
        .modal-content { border:none; border-radius:16px; background: var(--card); color: var(--text); }
        .modal-header { background: var(--nav-bg); color: #fff;
            border-radius:16px 16px 0 0; padding: 18px 22px;
            border-bottom:1px solid var(--nav-border); }
        .modal-header .modal-title { font-size:.95rem; font-weight:600;
            display:flex; align-items:center; gap:8px; }
        .modal-header .btn-close { filter: brightness(0) invert(1); opacity:.6; }
        .modal-header .btn-close:hover { opacity:1; }
        .modal-body { padding: 22px; }
        .modal-footer { border-top:1px solid var(--border); padding: 16px 22px; }
        .modal-body .form-label { font-size:.74rem; font-weight:600;
            color: var(--text); margin-bottom:8px; letter-spacing:.04em;
            text-transform:uppercase; }
        .modal-body .form-control, .modal-body .form-select {
            background: var(--bg-soft); border:1px solid var(--border);
            border-radius:10px; padding: 11px 16px; color: var(--text);
            font-size:.88rem; font-family:'Inter', sans-serif; transition: all .2s ease; }
        .modal-body .form-control:focus, .modal-body .form-select:focus {
            outline:none; border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft); background: var(--card); }
        .modal-body textarea.form-control { resize: vertical; }
        .modal-body small { color: var(--text-dim); font-size:.74rem; }
        .modal-note { background: var(--primary-soft); border:1px solid rgba(91,95,212,.2);
            color: var(--primary); border-radius:12px;
            padding: 14px 16px; font-size:.82rem;
            display:flex; align-items:flex-start; gap:12px; margin-top: 6px; }
        .modal-note i { margin-top:2px; }
        .btn-cancel { background: transparent; border:1px solid var(--border);
            color: var(--text-muted); border-radius:10px;
            font-size:.86rem; font-weight:500; padding: 10px 22px;
            cursor:pointer; transition: all .2s ease; }
        .btn-cancel:hover { background: var(--bg-soft);
            border-color: var(--border-light); color: var(--text-bright); }
        .btn-save { background: var(--primary); border:none; color:#fff;
            border-radius:10px; font-size:.86rem; font-weight:600;
            padding: 10px 24px; cursor:pointer; transition: all .2s ease;
            display:inline-flex; align-items:center; gap:7px; }
        .btn-save:hover { background:#4f46e5; transform: translateY(-1px);
            box-shadow: 0 6px 18px -3px rgba(91,95,212,.35); }
        .bulk-bar { display:flex; align-items:center; gap:12px; flex-wrap:wrap;
            padding-top:16px; margin-top:16px;
            border-top:1px solid var(--border); justify-content:space-between; }
        .bulk-bar .form-select { background: var(--bg-soft);
            border:1px solid var(--border); border-radius:10px;
            padding: 9px 16px; color: var(--text); font-size:.82rem; width:auto; }
        .bulk-bar .bulk-info { color: var(--text-dim); font-size:.78rem;
            display:flex; align-items:center; gap:6px; }
        .empty-state { text-align:center; padding: 70px 20px; color: var(--text-dim); }
        .empty-state i { font-size:3rem; margin-bottom:18px; opacity:.4; }
        .empty-state h4 { color: var(--text-bright); font-size:1.05rem;
            margin-bottom:8px; font-weight:700; }
        .empty-state p { font-size:.86rem; margin:0 0 18px; }
        ::-webkit-scrollbar { width:7px; height:7px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-light); border-radius:4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-dim); }
        .panel-body::-webkit-scrollbar-thumb { background: var(--nav-border); }
        .view-all-page { position: fixed; inset: 0; background: var(--bg);
            z-index: 2000; display: none; overflow-y: auto; animation: fadeIn .25s ease; }
        .view-all-page.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .view-all-header { position: sticky; top: 0; background: var(--card);
            border-bottom: 1px solid var(--border); padding: 18px 30px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap; z-index: 10;
            box-shadow: 0 2px 10px rgba(90,82,69,.06); }
        .view-all-title { display: flex; align-items: center; gap: 16px; }
        .back-btn { width: 44px; height: 44px; border-radius: 12px;
            background: var(--card); border: 1px solid var(--border);
            color: var(--text); font-size: 1rem; display: flex;
            align-items: center; justify-content: center; cursor: pointer;
            transition: all .2s ease; flex-shrink: 0; }
        .back-btn:hover { background: var(--bg-soft);
            border-color: var(--border-light); color: var(--text-bright);
            transform: translateX(-2px); }
        .view-all-title h2 { font-size: 1.3rem; font-weight: 700;
            color: var(--text-bright); margin: 0; letter-spacing: -.02em; }
        .view-all-title p { font-size: .8rem; color: var(--text-dim); margin: 3px 0 0; }
        .view-all-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .view-all-search { position: relative; min-width: 240px; }
        .view-all-search i { position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%); color: var(--text-dim); font-size: .88rem;
            pointer-events: none; }
        .view-all-search input { width: 100%; background: var(--bg-soft);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 9px 14px 9px 38px; color: var(--text);
            font-size: .86rem; font-family: 'Inter', sans-serif; transition: all .2s ease; }
        .view-all-search input::placeholder { color: var(--text-dim); }
        .view-all-search input:focus { outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft); background: var(--card); }
        .filter-chip { padding: 8px 16px; border-radius: 20px;
            background: var(--card); border: 1px solid var(--border);
            color: var(--text-muted); font-size: .78rem; font-weight: 500;
            cursor: pointer; transition: all .2s ease; white-space: nowrap; }
        .filter-chip:hover { border-color: var(--primary); color: var(--primary); }
        .filter-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }
        .view-all-body { max-width: 1000px; margin: 0 auto; padding: 28px 30px; }
        .view-all-list { background: var(--card); border-radius: 16px;
            border: 1px solid var(--border); overflow: hidden;
            box-shadow: 0 1px 3px rgba(90,82,69,.05); }
        .view-all-item { display: flex; gap: 18px; padding: 20px 26px;
            border-bottom: 1px solid var(--border); cursor: pointer;
            transition: background .15s ease; position: relative;
            text-decoration: none; color: inherit; }
        .view-all-item:last-child { border-bottom: none; }
        .view-all-item:hover { background: var(--bg-soft); }
        .view-all-item.unread { background: rgba(91,95,212,.04); }
        .view-all-item.unread::before { content: ''; position: absolute;
            left: 10px; top: 50%; transform: translateY(-50%);
            width: 7px; height: 7px; background: var(--primary);
            border-radius: 50%; }
        .view-all-item .item-icon { width: 48px; height: 48px;
            border-radius: 12px; flex-shrink: 0; }
        .view-all-item .item-content { flex: 1; min-width: 0; }
        .view-all-item .item-content p { color: var(--text-bright);
            font-size: .94rem; font-weight: 600; margin: 0; line-height: 1.4; }
        .view-all-item .item-content .item-desc { color: var(--text);
            font-size: .86rem; margin: 5px 0 0; font-weight: 400; }
        .view-all-item .item-content .item-meta { color: var(--text-dim);
            font-size: .78rem; margin: 7px 0 0; display: flex;
            align-items: center; gap: 10px; flex-wrap: wrap;
            font-variant-numeric: tabular-nums; }
        .view-all-item .item-content .item-meta span { display: inline-flex;
            align-items: center; gap: 5px; }
        .view-all-item .item-content .item-meta i { font-size: .7rem; }
        .view-all-item .item-actions { display: flex; align-items: center;
            gap: 8px; flex-shrink: 0; }
        .icon-action-page { width: 38px; height: 38px; border-radius: 10px;
            background: transparent; border: 1px solid var(--border);
            color: var(--text-muted); font-size: .88rem; display: flex;
            align-items: center; justify-content: center; cursor: pointer;
            transition: all .2s ease; }
        .icon-action-page:hover { background: var(--primary-soft);
            border-color: var(--primary); color: var(--primary); }
        .icon-action-page.danger:hover { background: rgba(220,38,38,.08);
            border-color: var(--danger); color: var(--danger); }
        .view-all-empty { padding: 70px 20px; text-align: center; color: var(--text-dim); }
        .view-all-empty i { font-size: 3rem; margin-bottom: 16px; opacity: .4; }
        .view-all-empty p { font-size: .94rem; margin: 0; }
        .settings-layout { max-width: 1100px; margin: 0 auto; padding: 28px 30px;
            display: grid; grid-template-columns: 260px 1fr; gap: 28px; align-items: start; }
        .settings-sidebar { background: var(--card); border: 1px solid var(--border);
            border-radius: 16px; padding: 12px; position: sticky; top: 110px;
            box-shadow: 0 1px 3px rgba(90,82,69,.05); }
        .settings-nav-item { display: flex; align-items: center; gap: 14px;
            padding: 13px 16px; border-radius: 10px;
            color: var(--text-muted); font-size: .88rem; font-weight: 500;
            cursor: pointer; transition: all .2s ease; text-decoration: none;
            border: none; background: transparent; width: 100%; text-align: left; }
        .settings-nav-item:hover { background: var(--bg-soft); color: var(--text-bright); }
        .settings-nav-item.active { background: var(--primary-soft);
            color: var(--primary); font-weight: 600;
            box-shadow: inset 3px 0 0 var(--primary); }
        .settings-nav-item i { width: 22px; text-align: center; font-size: .95rem; }
        .settings-content { background: var(--card); border: 1px solid var(--border);
            border-radius: 16px; padding: 30px; box-shadow: 0 1px 3px rgba(90,82,69,.05); }
        .settings-section { display: none; }
        .settings-section.active { display: block; animation: fadeIn .25s ease; }
        .settings-section h3 { font-size: 1.15rem; font-weight: 700;
            color: var(--text-bright); margin: 0 0 5px; letter-spacing: -.01em; }
        .settings-section .section-desc { color: var(--text-dim);
            font-size: .82rem; margin: 0 0 26px; padding-bottom: 18px;
            border-bottom: 1px solid var(--border); }
        .settings-field { margin-bottom: 20px; }
        .settings-field label { display: block; font-size: .78rem;
            font-weight: 600; color: var(--text); margin-bottom: 8px;
            letter-spacing: .04em; text-transform: uppercase; }
        .settings-field input, .settings-field select, .settings-field textarea {
            width: 100%; background: var(--bg-soft);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 12px 16px; color: var(--text); font-size: .9rem;
            font-family: 'Inter', sans-serif; transition: all .2s ease; }
        .settings-field input:focus, .settings-field select:focus,
        .settings-field textarea:focus { outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft); background: var(--card); }
        .settings-field .field-hint { font-size: .74rem; color: var(--text-dim); margin-top: 6px; }
        .settings-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .settings-toggle { display: flex; justify-content: space-between;
            align-items: center; padding: 16px 0;
            border-bottom: 1px solid var(--border); gap: 18px; }
        .settings-toggle:last-child { border-bottom: none; }
        .toggle-info h5 { margin: 0; font-size: .9rem; font-weight: 600; color: var(--text-bright); }
        .toggle-info p { margin: 4px 0 0; font-size: .78rem; color: var(--text-dim); }
        .toggle-switch { position: relative; width: 50px; height: 28px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; inset: 0;
            background: var(--border); border-radius: 28px; transition: .25s; }
        .toggle-slider::before { content: ''; position: absolute;
            height: 22px; width: 22px; left: 3px; bottom: 3px;
            background: #fff; border-radius: 50%; transition: .25s;
            box-shadow: 0 2px 4px rgba(0,0,0,.15); }
        .toggle-switch input:checked + .toggle-slider { background: var(--primary); }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(22px); }
        .settings-actions { display: flex; justify-content: flex-end; gap: 12px;
            margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--border); }
        .save-toast { position: fixed; bottom: 28px; right: 28px;
            background: var(--success); color: #fff; padding: 14px 24px;
            border-radius: 12px; font-size: .88rem; font-weight: 600;
            box-shadow: 0 10px 30px -8px rgba(13,150,104,.5);
            display: flex; align-items: center; gap: 10px;
            opacity: 0; visibility: hidden; transform: translateY(20px);
            transition: all .3s ease; z-index: 3000; }
        .save-toast.show { opacity: 1; visibility: visible; transform: translateY(0); }
        @media (max-width: 1200px) {
            .main-content { padding: 100px 24px 24px; }
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
            .settings-layout { grid-template-columns: 220px 1fr; gap: 22px; }
            .brand-text h4 { font-size: 1.35rem; }
            .brand-icon { width: 34px; height: 48px; }
        }
        @media (max-width: 1024px) {
            .sidebar { width: 300px; }
            .top-nav { left: 300px; }
            .main-content { margin-left: 300px; padding: 96px 22px 22px; }
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
            .nav-search { max-width: 300px; }
            .settings-layout { grid-template-columns: 200px 1fr; gap: 18px; }
            .brand-text h4 { font-size: 1.25rem; }
            .brand-text p { font-size: 0.56rem; letter-spacing: 0.2em; }
            .brand-icon { width: 32px; height: 46px; }
        }
        @media (max-width: 992px) {
            .nav-menu-btn { display: flex; }
            .sidebar { transform: translateX(-100%);
                box-shadow: 4px 0 30px rgba(30,37,51,.25); width: 320px; }
            .sidebar.active { transform: translateX(0); }
            .top-nav { left: 0; padding: 0 20px; }
            .main-content { margin-left: 0; padding: 96px 22px 22px; }
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
            .stat-info h3 { font-size: 1.5rem; }
            .settings-layout { grid-template-columns: 1fr; padding: 18px; }
            .settings-sidebar { position: static; display: flex; gap: 6px;
                overflow-x: auto; padding: 10px; }
            .settings-nav-item { white-space: nowrap; flex-shrink: 0; }
        }
        @media (max-width: 768px) {
            body { font-size: 14px; }
            .top-nav { height: 70px; padding: 0 16px; }
            .nav-search { display: none; }
            .nav-user-info, .nav-user-chevron { display: none; }
            .nav-user { padding: 5px; }
            .nav-divider { display: none; }
            .nav-dropdown-panel { width: 340px; right: -70px; }
            .main-content { padding: 88px 18px 18px; }
            .page-header { flex-direction: column; align-items: flex-start; padding: 18px; }
            .page-header h2 { font-size: 1.2rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 14px; }
            .stat-card { padding: 18px; }
            .stat-icon { width: 46px; height: 46px; font-size: 1.15rem; }
            .stat-info h3 { font-size: 1.3rem; }
            .card-modern { padding: 20px; border-radius: 14px; }
            .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .table-modern th, .table-modern td { padding: 11px 7px; font-size: .8rem; }
            .settings-content { padding: 24px; }
            .settings-row { grid-template-columns: 1fr; }
            .settings-actions { flex-direction: column-reverse; }
            .btn-save, .btn-cancel { width: 100%; justify-content: center; }
            .view-all-header { padding: 16px 18px; }
            .view-all-title h2 { font-size: 1.1rem; }
            .view-all-body { padding: 18px; }
            .view-all-item { padding: 16px 18px; gap: 14px; }
            .view-all-item .item-icon { width: 42px; height: 42px; }
            .view-all-search { min-width: 100%; }
        }
        @media (max-width: 576px) {
            .top-nav { height: 66px; }
            .nav-dropdown-panel { width: 320px; right: -60px; }
            .panel-item { padding: 12px 16px; }
            .main-content { padding: 82px 14px 14px; }
            .page-header { border-radius: 14px; padding: 16px; }
            .page-header h2 { font-size: 1.1rem; }
            .stats-grid { grid-template-columns: 1fr; gap: 12px; }
            .stat-card { padding: 16px; }
            .stat-info h3 { font-size: 1.25rem; }
            .card-modern { padding: 18px; border-radius: 13px; margin-bottom: 16px; }
            .card-header-modern h4 { font-size: .95rem; }
            .table-modern th, .table-modern td { padding: 10px 6px; font-size: .74rem; }
            .btn-modern { padding: 8px 15px; font-size: .78rem; }
            .sidebar { width: 290px; }
            .brand-text h4 { font-size: 1.15rem; }
            .brand-text p { font-size: 0.55rem; letter-spacing: 0.18em; }
            .brand-icon { width: 28px; height: 42px; }
            .sidebar .brand { padding: 16px 20px; height: 88px; gap: 12px; }
            .view-all-header { padding: 14px; }
            .view-all-title h2 { font-size: 1rem; }
            .view-all-title p { font-size: .74rem; }
            .view-all-item { padding: 14px 16px; }
            .view-all-item .item-content p { font-size: .88rem; }
            .view-all-item .item-actions { flex-direction: column; gap: 5px; }
            .icon-action { width: 34px; height: 34px; font-size: .78rem; }
        }
        @media (max-width: 380px) {
            .top-nav { height: 64px; padding: 0 12px; }
            .nav-menu-btn { width: 40px; height: 40px; font-size: 1rem; }
            .nav-icon-btn { width: 40px; height: 40px; font-size: 1rem; }
            .nav-user-avatar { width: 36px; height: 36px; font-size: 1.15rem; }
            .nav-dropdown-panel { width: 300px; right: -50px; }
            .main-content { padding: 78px 12px 12px; }
            .page-header h2 { font-size: 1.02rem; }
            .stat-info h3 { font-size: 1.15rem; }
            .stat-icon { width: 42px; height: 42px; font-size: 1.05rem; }
            .card-modern { padding: 16px; }
            .brand-text h4 { font-size: 1rem; }
            .brand-text p { font-size: 0.5rem; letter-spacing: 0.15em; }
            .brand-icon { width: 24px; height: 38px; }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .stat-card, .card-modern, .filter-bar { animation: fadeInUp .4s ease-out; }
        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
        body.compact-mode .stat-card, body.compact-mode .card-modern,
        body.compact-mode .settings-section, body.compact-mode .filter-bar { padding-top: 10px; padding-bottom: 10px; }
        body.compact-mode .nav-link { padding-top: 7px; padding-bottom: 7px; }
        body.no-animations *, body.no-animations *::before, body.no-animations *::after {
            animation: none !important; transition: none !important; scroll-behavior: auto !important;
        }
    </style>
</head>
<body>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <header class="top-nav">
        <div class="nav-left">
            <button class="nav-menu-btn" id="menuToggle" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <div class="nav-search">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search categories..." id="navSearchInput"
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="nav-right">
            <div class="nav-dropdown-wrapper">
                <button class="nav-icon-btn" id="notifBtn" title="Notifications" aria-label="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="nav-badge" id="notifBadge">0</span>
                </button>
                <div class="nav-dropdown-panel" id="notifPanel">
                    <div class="panel-header">
                        <h5>Notifications</h5>
                        <button class="mark-all" id="markAllNotif">Mark all as read</button>
                    </div>
                    <div class="panel-body" id="notifList"></div>
                    <div class="panel-footer">
                        <a href="#" id="viewAllNotif"><i class="fas fa-eye"></i> View all notifications</a>
                    </div>
                </div>
            </div>

            <div class="nav-divider"></div>

            <div class="nav-user" id="navUser">
                <div class="nav-user-avatar">👤</div>
                <div class="nav-user-info">
                    <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin User'); ?></strong>
                    <small>Administrator</small>
                </div>
                <i class="fas fa-chevron-down nav-user-chevron"></i>
                <div class="nav-dropdown">
                    <a href="#" id="openSettings"><i class="fas fa-cog"></i> Settings</a>
                    <div class="nav-dropdown-divider"></div>
                    <a href="logout.php" class="danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-icon">
                <svg viewBox="0 0 40 56" xmlns="http://www.w3.org/2000/svg" fill="none">
                    <path d="M4 18 L20 4 L36 18 L20 52 Z"
                          stroke="#c9a227" stroke-width="1.6"
                          stroke-linejoin="round" stroke-linecap="round" fill="none"/>
                    <line x1="4" y1="18" x2="36" y2="18"
                          stroke="#c9a227" stroke-width="1.2" stroke-linecap="round"/>
                    <line x1="4" y1="18" x2="14" y2="30"
                          stroke="#c9a227" stroke-width="0.9" opacity="0.65"/>
                    <line x1="36" y1="18" x2="26" y2="30"
                          stroke="#c9a227" stroke-width="0.9" opacity="0.65"/>
                </svg>
            </div>
            <div class="brand-text">
                <h4>R-Jewel<span class="amp">&amp;Gold</span></h4>
                <p>Admin Panel</p>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-chart-line"></i><span>Dashboard</span></a></div>
            <div class="nav-item"><a href="layaway.php" class="nav-link"><i class="fas fa-hand-holding-heart"></i><span>Lay-Away</span></a></div>
            <div class="nav-item"><a href="products.php" class="nav-link"><i class="fas fa-gem"></i><span>Products</span></a></div>
            <div class="nav-item"><a href="categories.php" class="nav-link active"><i class="fas fa-tags"></i><span>Categories</span></a></div>
            <div class="nav-item"><a href="orders.php" class="nav-link"><i class="fas fa-shopping-cart"></i><span>Orders</span></a></div>
            <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i><span>Customers</span></a></div>
            <div class="nav-item"><a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></div>
        </nav>
    </aside>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h2>Categories Management</h2>
                <p>Organize your jewelry collections</p>
            </div>
            <div class="page-header-actions">
                <button class="btn-modern" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="fas fa-plus"></i> Add New Category
                </button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card gold">
                <div class="stat-header">
                    <div class="stat-icon gold"><i class="fas fa-tags"></i></div>
                    <span class="stat-change"><i class="fas fa-folder"></i> Total</span>
                </div>
                <div class="stat-info"><h3><?php echo $total_categories; ?></h3><p>Total Categories</p></div>
            </div>
            <div class="stat-card success">
                <div class="stat-header">
                    <div class="stat-icon success"><i class="fas fa-gem"></i></div>
                    <span class="stat-change"><i class="fas fa-check"></i> Active</span>
                </div>
                <div class="stat-info"><h3><?php echo $total_products; ?></h3><p>Products in Categories</p></div>
            </div>
            <div class="stat-card info">
                <div class="stat-header">
                    <div class="stat-icon info"><i class="fas fa-chart-line"></i></div>
                    <span class="stat-change info"><i class="fas fa-arrow-up"></i> In Use</span>
                </div>
                <div class="stat-info"><h3><?php echo $categories_with_products; ?></h3><p>Categories with Products</p></div>
            </div>
            <div class="stat-card warning">
                <div class="stat-header">
                    <div class="stat-icon warning"><i class="fas fa-folder-open"></i></div>
                    <span class="stat-change warning"><i class="fas fa-exclamation-triangle"></i> Empty</span>
                </div>
                <div class="stat-info"><h3><?php echo $empty_categories; ?></h3><p>Empty Categories</p></div>
            </div>
        </div>

        <div class="filter-bar">
            <form method="GET" class="row g-3 align-items-end" id="filterForm" onsubmit="return handleFilterSubmit(event)">
                <div class="col-md-8">
                    <div class="search-input-wrapper" id="searchWrapper">
                        <input type="text"
                               name="search"
                               id="liveSearchInput"
                               class="form-control"
                               placeholder="Start typing to search categories..."
                               autocomplete="off"
                               value="<?php echo htmlspecialchars($search); ?>">
                        <span class="search-spinner"></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="sort" class="form-select" id="sortSelect">
                        <option value="name"      <?php echo $sort == 'name' ? 'selected' : ''; ?>>Sort by Name (A-Z)</option>
                        <option value="name_desc" <?php echo $sort == 'name_desc' ? 'selected' : ''; ?>>Sort by Name (Z-A)</option>
                        <option value="products"  <?php echo $sort == 'products' ? 'selected' : ''; ?>>Sort by Product Count</option>
                        <option value="newest"    <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Sort by Newest</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn-modern w-100 justify-content-center" title="Search">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>

        <div class="card-modern" id="categoriesResultsContainer">
            <div class="card-header-modern">
                <div>
                    <h4>Category List</h4>
                    <p><?php echo count($categories); ?> category(s) found</p>
                </div>
                <button class="btn-modern btn-sm-modern" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="fas fa-plus"></i> Add Category
                </button>
            </div>

            <?php if(isset($_SESSION['message'])): ?>
            <div class="alert-modern success" id="flashSuccess">
                <span><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['message']); ?></span>
                <button type="button" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            </div>
            <?php unset($_SESSION['message']); endif; ?>

            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert-modern danger" id="flashError">
                <span><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); ?></span>
                <button type="button" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            </div>
            <?php unset($_SESSION['error']); endif; ?>

            <form method="POST" id="bulkActionForm">
                <div class="table-responsive">
                    <table class="table table-modern">
                        <thead>
                            <tr>
                                <th width="30">
                                    <input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th width="80">Image</th>
                                <th>Category Name</th>
                                <th>Description</th>
                                <th>Products</th>
                                <th>Created Date</th>
                                <th width="120">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($categories)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-folder-open"></i>
                                        <h4>No Categories Found</h4>
                                        <p>Get started by creating your first category</p>
                                        <button class="btn-modern" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                            <i class="fas fa-plus"></i> Create Category
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($categories as $category): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="category_ids[]"
                                           value="<?php echo $category['id']; ?>"
                                           class="category-checkbox form-check-input">
                                </td>
                                <td>
                                    <?php if (!empty($category['image']) && file_exists(__DIR__ . '/../uploads/categories/' . $category['image'])): ?>
                                        <img src="../uploads/categories/<?php echo htmlspecialchars($category['image']); ?>"
                                             alt="<?php echo htmlspecialchars($category['name']); ?>"
                                             class="category-thumb">
                                    <?php else: ?>
                                        <div class="category-thumb-placeholder">
                                            <i class="fas fa-tag"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($category['name']); ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $desc = htmlspecialchars($category['description'] ?? '');
                                    if (empty($desc)) {
                                        echo '<span style="color:var(--text-dim);">No description</span>';
                                    } else {
                                        echo strlen($desc) > 60 ? htmlspecialchars(substr($desc, 0, 60)) . '...' : $desc;
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if($category['product_count'] > 0): ?>
                                        <span class="product-count-badge">
                                            <i class="fas fa-gem"></i> <?php echo $category['product_count']; ?> products
                                        </span>
                                    <?php else: ?>
                                        <span class="product-count-badge zero">
                                            <i class="fas fa-box-open"></i> No products
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="color:var(--text-dim); font-size:.8rem;">
                                        <i class="fas fa-calendar-alt"></i>
                                        <?php echo date('M d, Y', strtotime($category['created_at'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="icon-action" title="Edit"
                                                onclick="editCategory(<?php echo $category['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if($category['product_count'] == 0): ?>
                                            <a href="?delete=<?php echo $category['id']; ?>"
                                               class="icon-action danger" title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this category?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="icon-action disabled"
                                                    title="Cannot delete category with products" disabled>
                                                <i class="fas fa-lock"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if(!empty($categories)): ?>
                <div class="bulk-bar">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <select name="bulk_action" class="form-select">
                            <option value="">Bulk Actions</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        <button type="submit" class="btn-modern btn-sm-modern">
                            <i class="fas fa-check"></i> Apply
                        </button>
                    </div>
                    <div class="bulk-info">
                        <i class="fas fa-info-circle"></i>
                        Categories with products cannot be deleted
                    </div>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </main>

    <!-- ADD CATEGORY MODAL -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle"></i> Add New Category
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="save-category.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Category Name *</label>
                            <input type="text" name="name" class="form-control" required autofocus>
                            <small>Example: Necklaces, Rings, Earrings, etc.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"
                                      placeholder="Describe what this category includes..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Category Image</label>
                            <div class="image-upload-box" id="addImageBox">
                                <input type="file" name="image" accept="image/*"
                                       onchange="previewImage(this, 'addImageBox', 'addImagePreview')">
                                <div id="addImagePreview">
                                    <div class="image-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                    <div class="image-upload-text">Click to upload image</div>
                                    <div class="image-upload-hint">JPG, PNG, WEBP · Max 2MB · Recommended 400×400</div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-note">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>Tip:</strong> The category image will be displayed on the
                                customer-facing shop page, so choose a clear, well-lit picture.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-save"><i class="fas fa-check"></i> Save Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EDIT CATEGORY MODAL -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i> Edit Category
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div id="editCategoryContent"></div>
            </div>
        </div>
    </div>

    <!-- VIEW ALL NOTIFICATIONS PAGE -->
    <div class="view-all-page" id="viewAllNotifPage">
        <div class="view-all-header">
            <div class="view-all-title">
                <button class="back-btn" data-close="viewAllNotifPage" aria-label="Go back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div>
                    <h2>All Notifications</h2>
                    <p><span id="notifCount">0</span> notifications</p>
                </div>
            </div>
            <div class="view-all-actions">
                <div class="view-all-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="notifSearch" placeholder="Search notifications...">
                </div>
                <button class="filter-chip active" data-filter="all" data-target="notif">All</button>
                <button class="filter-chip" data-filter="unread" data-target="notif">Unread</button>
                <button class="btn-outline-modern" id="markAllNotifFull">
                    <i class="fas fa-check-double"></i> Mark all read
                </button>
            </div>
        </div>
        <div class="view-all-body">
            <div class="view-all-list" id="notifFullList"></div>
        </div>
    </div>

    <!-- SETTINGS PAGE -->
    <div class="view-all-page" id="settingsPage">
        <div class="view-all-header">
            <div class="view-all-title">
                <button class="back-btn" data-close="settingsPage" aria-label="Go back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div>
                    <h2>Settings</h2>
                    <p>Manage your account preferences and system configuration</p>
                </div>
            </div>
        </div>

        <div class="settings-layout">
            <aside class="settings-sidebar">
                <button class="settings-nav-item active" data-section="account">
                    <i class="fas fa-user-circle"></i> Account
                </button>
                <button class="settings-nav-item" data-section="security">
                    <i class="fas fa-shield-alt"></i> Security
                </button>
                <button class="settings-nav-item" data-section="notifications">
                    <i class="fas fa-bell"></i> Notifications
                </button>
                <button class="settings-nav-item" data-section="appearance">
                    <i class="fas fa-palette"></i> Appearance
                </button>
            </aside>

            <div class="settings-content">
                <div class="settings-section active" id="section-account">
                    <h3>Account Settings</h3>
                    <p class="section-desc">Update your admin account details.</p>
                    <div class="settings-row">
                        <div class="settings-field">
                            <label>Full Name</label>
                            <input type="text" id="setFullName" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin User'); ?>">
                        </div>
                        <div class="settings-field">
                            <label>Username</label>
                            <input type="text" id="setUsername" value="<?php echo htmlspecialchars($_SESSION['username'] ?? 'admin'); ?>">
                        </div>
                    </div>
                    <div class="settings-field">
                        <label>Email Address</label>
                        <input type="email" id="setEmail" value="admin@rjewelandgold.com">
                        <div class="field-hint">Used for system notifications and password recovery.</div>
                    </div>
                    <div class="settings-row">
                        <div class="settings-field">
                            <label>Phone Number</label>
                            <input type="text" id="setPhone" value="+63 917 123 4567">
                        </div>
                        <div class="settings-field">
                            <label>Role</label>
                            <input type="text" value="Administrator" disabled style="opacity:.6;cursor:not-allowed;">
                        </div>
                    </div>
                    <div class="settings-actions">
                        <button class="btn-cancel" data-reset="account">Cancel</button>
                        <button class="btn-save" data-save="account"><i class="fas fa-check"></i> Save Changes</button>
                    </div>
                </div>

                <div class="settings-section" id="section-security">
                    <h3>Security</h3>
                    <p class="section-desc">Manage your password and account security.</p>
                    <div class="settings-field">
                        <label>Current Password</label>
                        <input type="password" id="setCurrentPass" placeholder="Enter current password">
                    </div>
                    <div class="settings-row">
                        <div class="settings-field">
                            <label>New Password</label>
                            <input type="password" id="setNewPass" placeholder="Enter new password">
                            <div class="field-hint">Minimum 8 characters with letters and numbers.</div>
                        </div>
                        <div class="settings-field">
                            <label>Confirm New Password</label>
                            <input type="password" id="setConfirmPass" placeholder="Re-enter new password">
                        </div>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Two-Factor Authentication</h5>
                            <p>Add an extra layer of security to your account.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggle2FA">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Login Alerts</h5>
                            <p>Get notified when a new device signs in.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleLoginAlerts" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-actions">
                        <button class="btn-cancel" data-reset="security">Cancel</button>
                        <button class="btn-save" data-save="security"><i class="fas fa-check"></i> Update Security</button>
                    </div>
                </div>

                <div class="settings-section" id="section-notifications">
                    <h3>Notification Preferences</h3>
                    <p class="section-desc">Choose what updates you want to receive.</p>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>New Orders</h5>
                            <p>Notify me when a customer places a new order.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleNewOrders" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Low Stock Alerts</h5>
                            <p>Notify me when a product is running low.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleLowStock" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>New Customer Registrations</h5>
                            <p>Notify me when a new customer signs up.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleNewCustomer" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Order Status Updates</h5>
                            <p>Notify me when order status changes.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleOrderStatus">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Weekly Reports</h5>
                            <p>Send me a summary every Monday morning.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleWeekly">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-actions">
                        <button class="btn-cancel" data-reset="notifications">Cancel</button>
                        <button class="btn-save" data-save="notifications"><i class="fas fa-check"></i> Save Preferences</button>
                    </div>
                </div>

                <div class="settings-section" id="section-appearance">
                    <h3>Appearance</h3>
                    <p class="section-desc">Customize how the dashboard looks.</p>
                    <div class="settings-field">
                        <label>Theme</label>
                        <select id="setTheme">
                            <option value="cream" selected>Cream (Default)</option>
                            <option value="light">Light</option>
                            <option value="dark">Dark</option>
                            <option value="auto">Auto (System)</option>
                        </select>
                    </div>
                    <div class="settings-field">
                        <label>Accent Color</label>
                        <select id="setAccent">
                            <option value="#5b5fd4" selected>Indigo (Default)</option>
                            <option value="#b8941f">Gold</option>
                            <option value="#0d9668">Emerald</option>
                            <option value="#dc2626">Ruby</option>
                            <option value="#2563eb">Sapphire</option>
                        </select>
                    </div>
                    <div class="settings-field">
                        <label>Sidebar Style</label>
                        <select id="setSidebar">
                            <option value="dark" selected>Dark (Default)</option>
                            <option value="midnight">Midnight Blue</option>
                            <option value="charcoal">Charcoal</option>
                        </select>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Compact Mode</h5>
                            <p>Reduce padding to fit more content on screen.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleCompact">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Show Animations</h5>
                            <p>Enable smooth transitions and hover effects.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleAnimations" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-actions">
                        <button class="btn-cancel" data-reset="appearance">Cancel</button>
                        <button class="btn-save" data-save="appearance"><i class="fas fa-check"></i> Apply Appearance</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="save-toast" id="saveToast">
        <i class="fas fa-check-circle"></i> <span id="saveToastMsg">Settings saved successfully!</span>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            function openSidebar() {
                sidebar.classList.add('active');
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
            function closeSidebar() {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }

            menuToggle && menuToggle.addEventListener('click', function () {
                sidebar.classList.contains('active') ? closeSidebar() : openSidebar();
            });
            overlay && overlay.addEventListener('click', closeSidebar);

            document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth <= 992) closeSidebar();
                });
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && sidebar.classList.contains('active')) closeSidebar();
            });

            let resizeTimer;
            window.addEventListener('resize', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () {
                    if (window.innerWidth > 992) closeSidebar();
                }, 150);
            });
        })();

        (function () {
            const navUser = document.getElementById('navUser');
            if (!navUser) return;
            navUser.addEventListener('click', function (e) {
                e.stopPropagation();
                navUser.classList.toggle('open');
            });
            document.addEventListener('click', function () {
                navUser.classList.remove('open');
            });
        })();

        let liveSearchTimer = null;
        let liveSearchController = null;

        (function () {
            const filterForm  = document.getElementById('filterForm');
            const searchInput = document.getElementById('liveSearchInput');
            const navSearch   = document.getElementById('navSearchInput');
            if (!filterForm) return;

            if (navSearch) {
                navSearch.addEventListener('input', function () {
                    if (searchInput) searchInput.value = this.value;
                    triggerLiveSearch();
                });
                navSearch.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); triggerLiveSearch(true); }
                });
            }
            if (searchInput) {
                searchInput.addEventListener('input', function () { triggerLiveSearch(); });
                searchInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); triggerLiveSearch(true); }
                });
            }
            const sortSelect = document.getElementById('sortSelect');
            if (sortSelect) {
                sortSelect.addEventListener('change', function () { submitFilterAjax(); });
            }
        })();

        function triggerLiveSearch(immediate) {
            const searchWrap = document.getElementById('searchWrapper');
            if (searchWrap) searchWrap.classList.add('is-searching');
            if (immediate) { clearTimeout(liveSearchTimer); submitFilterAjax(); return; }
            clearTimeout(liveSearchTimer);
            liveSearchTimer = setTimeout(function () { submitFilterAjax(); }, 300);
        }

        function handleFilterSubmit(e) {
            if (e) e.preventDefault();
            submitFilterAjax();
            return false;
        }

        function submitFilterAjax() {
            const filterForm = document.getElementById('filterForm');
            const resultsEl  = document.getElementById('categoriesResultsContainer');
            const searchWrap = document.getElementById('searchWrapper');
            if (!filterForm || !resultsEl) return;

            const formData = new FormData(filterForm);
            const params   = new URLSearchParams();
            for (const [key, value] of formData.entries()) {
                if (value !== '' && value !== null) params.set(key, value);
            }

            const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.history.replaceState({}, '', newUrl);

            if (liveSearchController) liveSearchController.abort();
            liveSearchController = new AbortController();

            resultsEl.style.opacity = '0.5';
            resultsEl.style.pointerEvents = 'none';
            if (searchWrap) searchWrap.classList.add('is-searching');

            fetch(newUrl, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: liveSearchController.signal
            })
                .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
                .then(html => {
                    const parser = new DOMParser();
                    const doc    = parser.parseFromString(html, 'text/html');
                    const fresh  = doc.getElementById('categoriesResultsContainer');
                    if (!fresh) throw new Error('Missing #categoriesResultsContainer in response');
                    resultsEl.innerHTML = fresh.innerHTML;
                    rebindResultsEvents();
                })
                .catch(err => {
                    if (err.name === 'AbortError') return;
                    console.error('[categories.php] AJAX filter failed:', err);
                    window.location.href = newUrl;
                })
                .finally(() => {
                    resultsEl.style.opacity = '';
                    resultsEl.style.pointerEvents = '';
                    if (searchWrap) searchWrap.classList.remove('is-searching');
                });
        }

        function rebindResultsEvents() {
            const selectAll  = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.category-checkbox');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    checkboxes.forEach(cb => cb.checked = this.checked);
                });
            }
            ['flashSuccess', 'flashError'].forEach(id => {
                const el = document.getElementById(id);
                if (el) setTimeout(() => el.remove(), 4000);
            });
        }

        function previewImage(input, boxId, previewId) {
            const box = document.getElementById(boxId);
            const preview = document.getElementById(previewId);
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            if (file.size > 2 * 1024 * 1024) {
                alert('Image is too large. Please choose a file under 2MB.');
                input.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function (e) {
                preview.innerHTML =
                    '<img src="' + e.target.result + '" class="image-preview" alt="Preview">' +
                    '<button type="button" class="image-preview-remove" onclick="removeImage(\'' + boxId + '\', \'' + previewId + '\')">' +
                        '<i class="fas fa-times"></i> Remove image' +
                    '</button>';
                if (box) box.classList.add('has-image');
            };
            reader.readAsDataURL(file);
        }

        function removeImage(boxId, previewId) {
            const box = document.getElementById(boxId);
            const preview = document.getElementById(previewId);
            if (!box || !preview) return;
            const fileInput = box.querySelector('input[type="file"]');
            if (fileInput) fileInput.value = '';
            preview.innerHTML =
                '<div class="image-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>' +
                '<div class="image-upload-text">Click to upload image</div>' +
                '<div class="image-upload-hint">JPG, PNG, WEBP · Max 2MB · Recommended 400×400</div>';
            box.classList.remove('has-image');
        }

        // ============================================================
        // Notifications — GLOBAL SYNC across all browsers/devices
        // (server-side state; no localStorage for read/delete)
        // ============================================================
        (function () {
            const notificationsData = <?php echo json_encode($admin_notifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            const SETTINGS_KEY = 'rjewel_admin_settings_v1';

            const state = {
                notif: {
                    data: notificationsData.map(n => ({ ...n, unread: Boolean(n.unread) })),
                    filter: 'all',
                    search: ''
                }
            };

            function loadPrefs() {
                try { return JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}'); }
                catch (e) { return {}; }
            }

            // POST to self — writes read/delete state to DB
            async function syncServer(action, ids) {
                if (!ids || !ids.length) return;
                try {
                    const fd = new FormData();
                    fd.append('_notif_sync', '1');
                    fd.append('action', action);
                    ids.forEach(id => fd.append('ids[]', String(id)));
                    await fetch(window.location.pathname, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                } catch (e) {
                    console.warn('[notifications] sync failed:', action, e);
                }
            }

            function getVisibleData(key) {
                const prefs = loadPrefs();
                let list = state[key].data.slice();
                if (key === 'notif') {
                    list = list.filter(item => {
                        const id = String(item.id || '');
                        if (id.startsWith('order-')) {
                            const isNew = String(item.title || '').startsWith('New order');
                            if (isNew) return prefs.toggleNewOrders !== false;
                            return prefs.toggleOrderStatus === true;
                        }
                        if (id.startsWith('stock-'))    return prefs.toggleLowStock !== false;
                        if (id.startsWith('customer-')) return prefs.toggleNewCustomer !== false;
                        if (id.startsWith('layaway-'))  return prefs.toggleNewOrders !== false;
                        return true;
                    });
                }
                const f = state[key].filter;
                const q = (state[key].search || '').trim().toLowerCase();
                if (f === 'unread') list = list.filter(i => i.unread);
                if (q) {
                    list = list.filter(i =>
                        String(i.title || '').toLowerCase().includes(q) ||
                        String(i.desc  || '').toLowerCase().includes(q) ||
                        String(i.meta  || '').toLowerCase().includes(q)
                    );
                }
                return list;
            }

            function setActiveFilter(key, filter) {
                document.querySelectorAll('.filter-chip[data-target="notif"]').forEach(function (chip) {
                    chip.classList.toggle('active', chip.dataset.filter === filter);
                });
            }

            function escapeHtml(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function renderDropdown(key) {
                const listEl  = document.getElementById('notifList');
                const badgeEl = document.getElementById('notifBadge');
                const savedFilter = state[key].filter;
                const savedSearch = state[key].search;
                state[key].filter = 'all';
                state[key].search = '';
                const items = getVisibleData(key).slice(0, 6);
                state[key].filter = savedFilter;
                state[key].search = savedSearch;

                listEl.innerHTML = '';
                if (items.length === 0) {
                    listEl.innerHTML = '<div class="panel-empty"><i class="fas fa-inbox"></i><p>No notifications</p></div>';
                } else {
                    items.forEach(function (item) {
                        const el = document.createElement(item.link ? 'a' : 'div');
                        el.className = 'panel-item' + (item.unread ? ' unread' : '');
                        if (item.link) el.href = item.link;
                        el.innerHTML =
                            '<div class="item-icon ' + item.iconClass + '"><i class="fas fa-' + item.icon + '"></i></div>' +
                            '<div class="item-content">' +
                                '<p>' + escapeHtml(item.title) + '</p>' +
                                '<div class="item-meta"><i class="fas fa-clock"></i> ' + escapeHtml(item.meta) + '</div>' +
                            '</div>';

                        el.addEventListener('click', function (e) {
                            if (item.unread) {
                                item.unread = false;
                                renderDropdown(key);
                                renderFullPage(key);
                                syncServer('read', [String(item.id)]); // → DB, syncs all devices
                            }
                            if (!item.link) {
                                e.preventDefault();
                                e.stopPropagation();
                            }
                        });
                        listEl.appendChild(el);
                    });
                }

                const count = state[key].data.filter(i => i.unread).length;
                if (count > 0) {
                    badgeEl.textContent = count > 9 ? '9+' : count;
                    badgeEl.classList.remove('hidden');
                } else {
                    badgeEl.classList.add('hidden');
                }
            }

            function renderFullPage(key) {
                const listEl  = document.getElementById('notifFullList');
                const countEl = document.getElementById('notifCount');
                const items = getVisibleData(key);

                listEl.innerHTML = '';
                countEl.textContent = state[key].data.length;

                if (items.length === 0) {
                    listEl.innerHTML = '<div class="view-all-empty"><i class="fas fa-inbox"></i><p>No notifications found</p></div>';
                    return;
                }

                items.forEach(function (item) {
                    const row = document.createElement(item.link ? 'a' : 'div');
                    row.className = 'view-all-item' + (item.unread ? ' unread' : '');
                    if (item.link) row.href = item.link;
                    row.innerHTML =
                        '<div class="item-icon ' + item.iconClass + '"><i class="fas fa-' + item.icon + '"></i></div>' +
                        '<div class="item-content">' +
                            '<p>' + escapeHtml(item.title) + '</p>' +
                            '<div class="item-desc">' + escapeHtml(item.desc || '') + '</div>' +
                            '<div class="item-meta">' +
                                '<span><i class="fas fa-calendar-alt"></i> ' + escapeHtml(item.meta) + '</span>' +
                                (item.unread ? '<span style="color:var(--primary);font-weight:600;"><i class="fas fa-circle" style="font-size:.4rem;"></i> Unread</span>' : '') +
                            '</div>' +
                        '</div>' +
                        '<div class="item-actions">' +
                            '<button class="icon-action-page" title="Mark as read" data-action="read"><i class="fas fa-check"></i></button>' +
                            '<button class="icon-action-page danger" title="Delete" data-action="delete"><i class="fas fa-trash"></i></button>' +
                        '</div>';

                    row.querySelector('[data-action="read"]').addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (item.unread) {
                            item.unread = false;
                            renderFullPage(key);
                            renderDropdown(key);
                            syncServer('read', [String(item.id)]);
                        }
                    });
                    row.querySelector('[data-action="delete"]').addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const idx = state[key].data.findIndex(d => d.id === item.id);
                        if (idx > -1) state[key].data.splice(idx, 1);
                        renderFullPage(key);
                        renderDropdown(key);
                        syncServer('delete', [String(item.id)]);
                    });
                    row.addEventListener('click', function (e) {
                        if (item.unread) {
                            item.unread = false;
                            renderFullPage(key);
                            renderDropdown(key);
                            syncServer('read', [String(item.id)]);
                        }
                        if (!item.link) {
                            e.preventDefault();
                            e.stopPropagation();
                        }
                    });
                    listEl.appendChild(row);
                });
            }

            function closeAllPanels() {
                document.getElementById('notifPanel').classList.remove('open');
                document.getElementById('notifBtn').classList.remove('active');
            }

            document.getElementById('notifBtn').addEventListener('click', function (e) {
                e.stopPropagation();
                const panel = document.getElementById('notifPanel');
                const isOpen = panel.classList.contains('open');
                closeAllPanels();
                if (!isOpen) { panel.classList.add('open'); this.classList.add('active'); }
            });
            document.addEventListener('click', function (e) {
                if (!e.target.closest('.nav-dropdown-wrapper')) closeAllPanels();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeAllPanels();
            });

            document.getElementById('markAllNotif').addEventListener('click', function (e) {
                e.stopPropagation();
                const unreadIds = state.notif.data.filter(n => n.unread).map(n => String(n.id));
                state.notif.data.forEach(n => n.unread = false);
                renderDropdown('notif');
                renderFullPage('notif');
                syncServer('read', unreadIds);
            });

            document.getElementById('viewAllNotif').addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                closeAllPanels();
                state.notif.filter = 'all';
                state.notif.search = '';
                document.getElementById('notifSearch').value = '';
                setActiveFilter('notif', 'all');
                renderFullPage('notif');
                document.getElementById('viewAllNotifPage').classList.add('active');
                document.body.style.overflow = 'hidden';
            });

            document.querySelectorAll('[data-close]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.getElementById(this.dataset.close).classList.remove('active');
                    document.body.style.overflow = '';
                });
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    ['viewAllNotifPage', 'settingsPage'].forEach(function (id) {
                        const el = document.getElementById(id);
                        if (el && el.classList.contains('active')) {
                            el.classList.remove('active');
                            document.body.style.overflow = '';
                        }
                    });
                }
            });

            document.querySelectorAll('.filter-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    const key = this.dataset.target;
                    if (!key) return;
                    state[key].filter = this.dataset.filter;
                    setActiveFilter(key, this.dataset.filter);
                    renderFullPage(key);
                });
            });

            document.getElementById('notifSearch').addEventListener('input', function () {
                state.notif.search = this.value;
                renderFullPage('notif');
            });

            document.getElementById('markAllNotifFull').addEventListener('click', function () {
                const unreadIds = state.notif.data.filter(n => n.unread).map(n => String(n.id));
                state.notif.data.forEach(n => n.unread = false);
                renderFullPage('notif');
                renderDropdown('notif');
                syncServer('read', unreadIds);
            });

            window.addEventListener('rjewel:settings-changed', function () {
                renderDropdown('notif');
            });

            renderDropdown('notif');
            renderFullPage('notif');
        })();

        (function () {
            const settingsPage = document.getElementById('settingsPage');
            const openSettings = document.getElementById('openSettings');
            if (!settingsPage) return;

            const STORAGE_KEY = 'rjewel_admin_settings_v1';
            const ids = [
                'setFullName','setUsername','setEmail','setPhone',
                'toggle2FA','toggleLoginAlerts',
                'toggleNewOrders','toggleLowStock','toggleNewCustomer',
                'toggleOrderStatus','toggleWeekly',
                'setTheme','setAccent','setSidebar','toggleCompact','toggleAnimations',
            ];
            const passwordIds = ['setCurrentPass','setNewPass','setConfirmPass'];
            const controls = {};
            ids.concat(passwordIds).forEach(id => {
                const el = document.getElementById(id);
                if (el) controls[id] = el;
            });

            function readControls() {
                const data = {};
                Object.keys(controls).forEach(id => {
                    if (passwordIds.includes(id)) return;
                    const el = controls[id];
                    data[id] = el.type === 'checkbox' ? el.checked : el.value;
                });
                return data;
            }
            function applyControls(data) {
                Object.keys(data || {}).forEach(id => {
                    const el = controls[id];
                    if (!el || passwordIds.includes(id)) return;
                    if (el.type === 'checkbox') el.checked = Boolean(data[id]);
                    else if (data[id] !== undefined && data[id] !== null) el.value = data[id];
                });
            }
            function loadSaved() {
                try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); }
                catch (e) { return {}; }
            }
            function saveAll(data) {
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
                    window.dispatchEvent(new Event('rjewel:settings-changed'));
                    return true;
                } catch (e) { showToast('Could not save settings in this browser.'); return false; }
            }

            const saveToast = document.getElementById('saveToast');
            const saveToastMsg = document.getElementById('saveToastMsg');
            let toastTimer;
            function showToast(message) {
                if (!saveToast || !saveToastMsg) { alert(message); return; }
                saveToastMsg.textContent = message || 'Settings saved successfully!';
                saveToast.classList.add('show');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(() => saveToast.classList.remove('show'), 2800);
            }

            const initialValues = readControls();
            applyControls(loadSaved());

            function applyAppearance(data) {
                const root = document.documentElement;
                const theme = data.setTheme || 'cream';
                const accent = data.setAccent || '#5b5fd4';
                const sidebar = data.setSidebar || 'dark';
                const effectiveTheme = theme === 'auto'
                    ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                    : theme;
                root.style.setProperty('--primary', accent);
                root.style.setProperty('--primary-soft', accent + '18');
                root.style.setProperty('--sidebar-active', accent + '26');
                if (effectiveTheme === 'dark') {
                    root.style.setProperty('--bg', '#151821');
                    root.style.setProperty('--bg-soft', '#1c202b');
                    root.style.setProperty('--card', '#222735');
                    root.style.setProperty('--card-hover', '#292f3e');
                    root.style.setProperty('--border', '#343b4d');
                    root.style.setProperty('--border-light', '#2d3444');
                    root.style.setProperty('--text', '#d5d9e2');
                    root.style.setProperty('--text-bright', '#ffffff');
                    root.style.setProperty('--text-muted', '#a0a8ba');
                    root.style.setProperty('--text-dim', '#777f91');
                } else if (effectiveTheme === 'light') {
                    root.style.setProperty('--bg', '#f5f7fb');
                    root.style.setProperty('--bg-soft', '#ffffff');
                    root.style.setProperty('--card', '#ffffff');
                    root.style.setProperty('--card-hover', '#f8fafc');
                    root.style.setProperty('--border', '#e2e8f0');
                    root.style.setProperty('--border-light', '#edf1f6');
                    root.style.setProperty('--text', '#475569');
                    root.style.setProperty('--text-bright', '#172033');
                    root.style.setProperty('--text-muted', '#64748b');
                    root.style.setProperty('--text-dim', '#94a3b8');
                } else {
                    root.style.setProperty('--bg', '#f2ede4');
                    root.style.setProperty('--bg-soft', '#faf7f2');
                    root.style.setProperty('--card', '#ffffff');
                    root.style.setProperty('--card-hover', '#faf8f4');
                    root.style.setProperty('--border', '#e5ddd0');
                    root.style.setProperty('--border-light', '#ede7db');
                    root.style.setProperty('--text', '#5a5245');
                    root.style.setProperty('--text-bright', '#2b2620');
                    root.style.setProperty('--text-muted', '#8a8172');
                    root.style.setProperty('--text-dim', '#b3a998');
                }
                const sidebarColors = {
                    dark: ['#1a2030','#252d3d','#2a3142'],
                    midnight: ['#101b35','#1b2a4a','#293958'],
                    charcoal: ['#292929','#383838','#454545']
                }[sidebar] || ['#1a2030','#252d3d','#2a3142'];
                root.style.setProperty('--sidebar-bg', sidebarColors[0]);
                root.style.setProperty('--sidebar-hover', sidebarColors[1]);
                root.style.setProperty('--sidebar-border', sidebarColors[2]);
                root.style.setProperty('--sidebar-active', accent + '26');
                document.body.classList.toggle('compact-mode', Boolean(data.toggleCompact));
                document.body.classList.toggle('no-animations', data.toggleAnimations === false);
            }
            applyAppearance(readControls());
            if (window.matchMedia) {
                const scheme = window.matchMedia('(prefers-color-scheme: dark)');
                if (scheme.addEventListener) scheme.addEventListener('change', () => {
                    const d = loadSaved(); if ((d.setTheme || 'cream') === 'auto') applyAppearance(d);
                });
            }

            if (openSettings) openSettings.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                const navUser = document.getElementById('navUser');
                if (navUser) navUser.classList.remove('open');
                settingsPage.classList.add('active');
                document.body.style.overflow = 'hidden';
            });

            const navItems = document.querySelectorAll('.settings-nav-item');
            const sections = document.querySelectorAll('.settings-section');
            navItems.forEach(item => item.addEventListener('click', function () {
                navItems.forEach(n => n.classList.remove('active'));
                sections.forEach(section => section.classList.remove('active'));
                this.classList.add('active');
                const section = document.getElementById('section-' + this.dataset.section);
                if (section) section.classList.add('active');
            }));

            ['setTheme','setAccent','setSidebar','toggleCompact','toggleAnimations'].forEach(id => {
                if (controls[id]) controls[id].addEventListener('change', () => {
                    const data = Object.assign({}, loadSaved(), readControls());
                    applyAppearance(data);
                    if (saveAll(data)) showToast('Appearance saved and applied.');
                });
            });

            ['toggleNewOrders','toggleLowStock','toggleNewCustomer','toggleOrderStatus','toggleWeekly'].forEach(id => {
                if (controls[id]) controls[id].addEventListener('change', () => {
                    const data = Object.assign({}, loadSaved(), readControls());
                    if (saveAll(data)) showToast('Notification preferences updated.');
                });
            });

            document.querySelectorAll('[data-save]').forEach(button => button.addEventListener('click', function () {
                const section = this.dataset.save;
                const data = Object.assign({}, loadSaved(), readControls());
                if (section === 'security') {
                    const current = controls.setCurrentPass ? controls.setCurrentPass.value : '';
                    const next = controls.setNewPass ? controls.setNewPass.value : '';
                    const confirm = controls.setConfirmPass ? controls.setConfirmPass.value : '';
                    if (next || confirm || current) {
                        if (!current) return showToast('Enter your current password first.');
                        if (next.length < 8 || !/[A-Za-z]/.test(next) || !/\d/.test(next))
                            return showToast('New password must be 8+ characters with letters and numbers.');
                        if (next !== confirm) return showToast('New password and confirmation do not match.');
                        return showToast('Password form validated. Connect server-side password update to change it securely.');
                    }
                }
                if (section === 'account') {
                    if (controls.setFullName && !controls.setFullName.value.trim()) return showToast('Full name is required.');
                    if (controls.setUsername && !controls.setUsername.value.trim()) return showToast('Username is required.');
                    if (controls.setEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(controls.setEmail.value)) return showToast('Enter a valid email address.');
                }
                if (saveAll(data)) {
                    applyAppearance(data);
                    const labels = {
                        account: 'Account preferences saved in this browser.',
                        security: 'Security preferences saved.',
                        notifications: 'Notification preferences saved.',
                        appearance: 'Appearance saved and applied.'
                    };
                    showToast(labels[section] || 'Settings saved.');
                }
            }));

            document.querySelectorAll('[data-reset]').forEach(button => button.addEventListener('click', function () {
                const section = this.dataset.reset;
                const groups = {
                    account: ['setFullName','setUsername','setEmail','setPhone'],
                    security: ['toggle2FA','toggleLoginAlerts'],
                    notifications: ['toggleNewOrders','toggleLowStock','toggleNewCustomer','toggleOrderStatus','toggleWeekly'],
                    appearance: ['setTheme','setAccent','setSidebar','toggleCompact','toggleAnimations']
                };
                (groups[section] || []).forEach(id => {
                    if (!controls[id]) return;
                    const value = initialValues[id];
                    if (controls[id].type === 'checkbox') controls[id].checked = Boolean(value);
                    else if (value !== undefined) controls[id].value = value;
                });
                passwordIds.forEach(id => { if (controls[id]) controls[id].value = ''; });
                applyAppearance(readControls());
                showToast('Unsaved changes discarded.');
            }));

            document.querySelectorAll('#settingsPage [data-close]').forEach(button => button.addEventListener('click', function () {
                settingsPage.classList.remove('active');
                document.body.style.overflow = '';
            }));

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && settingsPage.classList.contains('active')) {
                    settingsPage.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        })();

        (function () {
            rebindResultsEvents();
            const form = document.getElementById('bulkActionForm');
            if (form) {
                form.addEventListener('submit', function (e) {
                    const action = form.querySelector('select[name="bulk_action"]').value;
                    if (action === 'delete') {
                        const checked = document.querySelectorAll('.category-checkbox:checked').length;
                        if (checked > 0) {
                            if (!confirm('Are you sure you want to delete ' + checked + ' category(s)? This action cannot be undone.')) {
                                e.preventDefault();
                            }
                        } else {
                            alert('Please select at least one category to delete.');
                            e.preventDefault();
                        }
                    }
                });
            }
        })();

        function editCategory(id) {
            fetch('get-category.php?id=' + encodeURIComponent(id))
                .then(r => r.text())
                .then(html => {
                    document.getElementById('editCategoryContent').innerHTML = html;
                    const modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
                    modal.show();
                })
                .catch(() => alert('Could not load category.'));
        }
    </script>
</body>
</html>