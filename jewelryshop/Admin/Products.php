<?php
// admin/products.php — Professional Product Management
// Inventory Model:
//   stock            = Total Allocation (Admin controlled)
//   unreleased_stock = Vault/Backroom (Admin controlled)
//   released_stock   = Shop Display (derived = stock - unreleased_stock)
// Syncs with index.php: notifications, preferences, account, security.
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
// SHARED HELPERS (same contract as index.php)
// ============================================================
function productsFormatTime($value) {
    if (!$value) return '—';
    try { $dt = new DateTime($value); } catch (Throwable $e) { return '—'; }
    return $dt->format('M j, Y g:i A');
}
function productsIsRecent($value, $hours = 24) {
    if (!$value) return false;
    $ts = strtotime($value);
    if (!$ts) return false;
    return (time() - $ts) < ($hours * 3600);
}
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
function adminPrefs(PDO $db): array {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS admin_preferences (
            pref_key VARCHAR(64) NOT NULL PRIMARY KEY,
            pref_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = $db->query("SELECT pref_key, pref_value FROM admin_preferences")->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    } catch (Throwable $e) { return []; }
}
function getCurrentAdmin(PDO $db, int $userId): array {
    $default = [
        'full_name'          => $_SESSION['full_name'] ?? 'Admin User',
        'username'           => $_SESSION['username']  ?? 'admin',
        'email'              => '',
        'phone'              => '',
        'two_factor_enabled' => 0,
        'login_alerts'       => 1,
    ];
    if ($userId <= 0) return $default;
    try {
        $stmt = $db->prepare("SELECT full_name, username, email, phone,
                                     COALESCE(two_factor_enabled,0) AS two_factor_enabled,
                                     COALESCE(login_alerts,1)       AS login_alerts
                              FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return array_merge($default, $row);
    } catch (Throwable $e) {}
    return $default;
}

// ============================================================
// INLINE SYNC ENDPOINT
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
    $db->exec("CREATE TABLE IF NOT EXISTS admin_preferences (
        pref_key VARCHAR(64) NOT NULL PRIMARY KEY,
        pref_value TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $action = strtolower((string)($_POST['action'] ?? ''));

    try {
        if (in_array($action, ['save_appearance','save_prefs','save_all_prefs'], true)) {
            $prefs = [];
            $appearanceKeys = ['setTheme','setAccent','setSidebar','toggleCompact','toggleAnimations'];
            $notifKeys      = ['toggleNewOrders','toggleLowStock','toggleNewCustomer','toggleOrderStatus','toggleWeekly'];
            if ($action === 'save_appearance' || $action === 'save_all_prefs') {
                foreach ($appearanceKeys as $k) {
                    if (in_array($k, ['toggleCompact','toggleAnimations'], true)) {
                        $prefs[$k] = (($_POST[$k] ?? '0') === '1') ? '1' : '0';
                    } else if (isset($_POST[$k])) {
                        $prefs[$k] = (string)$_POST[$k];
                    }
                }
            }
            if ($action === 'save_prefs' || $action === 'save_all_prefs') {
                foreach ($notifKeys as $k) $prefs[$k] = (($_POST[$k] ?? '0') === '1') ? '1' : '0';
            }
            if (!empty($prefs)) {
                $stmt = $db->prepare("INSERT INTO admin_preferences (pref_key, pref_value) VALUES (?, ?)
                                      ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)");
                foreach ($prefs as $k => $v) $stmt->execute([$k, $v]);
            }
            echo json_encode(['success' => true, 'saved' => $prefs]); exit();
        }

        if ($action === 'save_account') {
            $userId   = (int)$_SESSION['user_id'];
            $fullName = trim((string)($_POST['setFullName'] ?? ''));
            $username = trim((string)($_POST['setUsername'] ?? ''));
            $email    = trim((string)($_POST['setEmail']    ?? ''));
            $phone    = trim((string)($_POST['setPhone']    ?? ''));
            if ($fullName === '' || $username === '') { echo json_encode(['success'=>false,'error'=>'Full name and username are required.']); exit(); }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'error'=>'Enter a valid email address.']); exit(); }
            try {
                $dup = $db->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id <> ? LIMIT 1");
                $dup->execute([$email, $username, $userId]);
                if ($dup->fetchColumn()) { echo json_encode(['success'=>false,'error'=>'Email or username is already taken.']); exit(); }
                $db->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ? WHERE id = ?")
                   ->execute([$fullName, $username, $email, $phone ?: null, $userId]);
                $_SESSION['full_name'] = $fullName;
                $_SESSION['username']  = $username;
                $_SESSION['email']     = $email;
                $_SESSION['phone']     = $phone;
                echo json_encode(['success' => true]);
            } catch (Throwable $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            exit();
        }

        if ($action === 'save_password') {
            $userId  = (int)$_SESSION['user_id'];
            $current = (string)($_POST['setCurrentPass'] ?? '');
            $next    = (string)($_POST['setNewPass']     ?? '');
            $confirm = (string)($_POST['setConfirmPass'] ?? '');
            if ($current === '' || $next === '' || $confirm === '') { echo json_encode(['success'=>false,'error'=>'All password fields are required.']); exit(); }
            if ($next !== $confirm) { echo json_encode(['success'=>false,'error'=>'New password and confirmation do not match.']); exit(); }
            if (strlen($next) < 8 || !preg_match('/[A-Za-z]/', $next) || !preg_match('/\d/', $next)) {
                echo json_encode(['success'=>false,'error'=>'New password must be 8+ characters with letters and numbers.']); exit();
            }
            try {
                $stmt = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $hash = $stmt->fetchColumn();
                $ok = false;
                if (is_string($hash) && $hash !== '') {
                    if (password_verify($current, $hash)) $ok = true;
                    elseif (hash_equals($hash, md5($current))) $ok = true;
                    elseif (hash_equals($hash, sha1($current))) $ok = true;
                }
                if (!$ok) { echo json_encode(['success'=>false,'error'=>'Current password is incorrect.']); exit(); }
                $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($next, PASSWORD_DEFAULT), $userId]);
                echo json_encode(['success' => true]);
            } catch (Throwable $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            exit();
        }

        if ($action === 'save_security') {
            $userId      = (int)$_SESSION['user_id'];
            $twoFA       = (($_POST['toggle2FA']         ?? '0') === '1') ? 1 : 0;
            $loginAlerts = (($_POST['toggleLoginAlerts'] ?? '0') === '1') ? 1 : 0;
            try {
                try { $db->exec("ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
                try { $db->exec("ALTER TABLE users ADD COLUMN login_alerts TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
                $db->prepare("UPDATE users SET two_factor_enabled = ?, login_alerts = ? WHERE id = ?")
                   ->execute([$twoFA, $loginAlerts, $userId]);
                echo json_encode(['success' => true]);
            } catch (Throwable $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            exit();
        }

        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [$ids];
        $ids = array_values(array_filter(
            array_map(fn($v) => trim((string)$v), $ids),
            fn($v) => $v !== '' && strlen($v) <= 64
        ));
        if (empty($ids)) { echo json_encode(['success' => true, 'count' => 0]); exit(); }

        if ($action === 'read') {
            $ph = implode(',', array_fill(0, count($ids), '(?, NOW())'));
            $db->prepare("INSERT INTO notification_reads (notification_id, read_at) VALUES $ph
                          ON DUPLICATE KEY UPDATE read_at = read_at")->execute($ids);
            echo json_encode(['success' => true, 'count' => count($ids)]);
        } elseif ($action === 'unread') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM notification_reads WHERE notification_id IN ($ph)");
            $stmt->execute($ids);
            echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
        } elseif ($action === 'delete') {
            $ph = implode(',', array_fill(0, count($ids), '(?, NOW())'));
            $db->prepare("INSERT INTO notification_deletes (notification_id, deleted_at) VALUES $ph
                          ON DUPLICATE KEY UPDATE deleted_at = deleted_at")->execute($ids);
            $db->prepare("INSERT INTO notification_reads (notification_id, read_at) VALUES $ph
                          ON DUPLICATE KEY UPDATE read_at = read_at")->execute($ids);
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
// SAFE AUTO-MIGRATION
// ============================================================
try { $db->query("SELECT released_stock FROM products LIMIT 1"); }
catch (Throwable $e) { try { $db->exec("ALTER TABLE products ADD COLUMN released_stock INT DEFAULT 0"); } catch (Throwable $e2) {} }

try { $db->query("SELECT unreleased_stock FROM products LIMIT 1"); }
catch (Throwable $e) {
    try {
        $db->exec("ALTER TABLE products ADD COLUMN unreleased_stock INT DEFAULT 0");
        $db->exec("UPDATE products SET unreleased_stock = GREATEST(0, stock - released_stock)");
    } catch (Throwable $e2) {}
}

try { $db->query("SELECT karat FROM products LIMIT 1"); }
catch (Throwable $e) { try { $db->exec("ALTER TABLE products ADD COLUMN karat VARCHAR(20) DEFAULT NULL"); } catch (Throwable $e2) {} }

try { $db->query("SELECT weight FROM products LIMIT 1"); }
catch (Throwable $e) { try { $db->exec("ALTER TABLE products ADD COLUMN weight DECIMAL(10,2) DEFAULT NULL"); } catch (Throwable $e2) {} }

// ============================================================
// BULK ACTIONS
// ============================================================
if(isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $product_ids = $_POST['product_ids'] ?? [];

    if(!empty($product_ids)) {
        $ids = implode(',', array_map('intval', $product_ids));

        switch($action) {
            case 'delete':
                $db->prepare("DELETE FROM products WHERE id IN ($ids)")->execute();
                $_SESSION['message'] = "Products deleted successfully!";
                break;
            case 'featured':
                $db->prepare("UPDATE products SET featured = 1 WHERE id IN ($ids)")->execute();
                $_SESSION['message'] = "Products marked as featured!";
                break;
            case 'unfeatured':
                $db->prepare("UPDATE products SET featured = 0 WHERE id IN ($ids)")->execute();
                $_SESSION['message'] = "Products removed from featured!";
                break;
        }
        header("Location: products.php");
        exit();
    }
}

// ============================================================
// INDIVIDUAL DELETE
// ============================================================
if(isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $img_stmt = $db->prepare("SELECT image FROM products WHERE id = :id");
    $img_stmt->bindParam(':id', $id);
    $img_stmt->execute();
    $product = $img_stmt->fetch(PDO::FETCH_ASSOC);

    if($product['image'] && file_exists("../uploads/" . $product['image'])) {
        unlink("../uploads/" . $product['image']);
    }

    $stmt = $db->prepare("DELETE FROM products WHERE id = :id");
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $_SESSION['message'] = "Product deleted successfully!";
    header("Location: products.php");
    exit();
}

// ============================================================
// QUICK STOCK ADJUST
// ============================================================
if(isset($_POST['quick_stock_update'])) {
    $pid          = (int)($_POST['product_id'] ?? 0);
    $monthlyIn    = max(0, (int)($_POST['monthly_stock'] ?? 0));
    $unreleasedIn = max(0, (int)($_POST['unreleased_stock'] ?? 0));

    $cur = $db->prepare("SELECT stock FROM products WHERE id = :id");
    $cur->execute([':id' => $pid]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $currentStock = (int)$row['stock'];
        $finalStock = $monthlyIn > 0 ? $monthlyIn : $currentStock;

        $finalUnreleased = $unreleasedIn;
        if ($finalUnreleased > $finalStock) $finalUnreleased = $finalStock;

        $finalReleased = max(0, $finalStock - $finalUnreleased);

        $upd = $db->prepare("UPDATE products SET stock = :s, unreleased_stock = :u, released_stock = :r WHERE id = :id");
        $upd->bindValue(':s',  $finalStock,      PDO::PARAM_INT);
        $upd->bindValue(':u',  $finalUnreleased, PDO::PARAM_INT);
        $upd->bindValue(':r',  $finalReleased,   PDO::PARAM_INT);
        $upd->bindValue(':id', $pid,             PDO::PARAM_INT);

        if($upd->execute()) {
            $_SESSION['message'] = "Stock updated: {$finalStock} total · {$finalReleased} shop · {$finalUnreleased} vault.";
        } else {
            $_SESSION['error'] = "Could not update stock.";
        }
    } else {
        $_SESSION['error'] = "Product not found.";
    }
    header("Location: products.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit();
}

// ============================================================
// FILTERS
// ============================================================
$search          = $_GET['search']   ?? '';
$category_filter = $_GET['category'] ?? '';
$stock_filter    = $_GET['stock']    ?? '';
$featured_filter = $_GET['featured'] ?? '';
$focus_id        = isset($_GET['focus']) ? (int)$_GET['focus'] : 0;

$where_conditions = [];
$params = [];

if($search) {
    $where_conditions[] = "(p.name LIKE :search OR p.description LIKE :search)";
    $params[':search'] = "%$search%";
}
if($category_filter) {
    $where_conditions[] = "p.category_id = :category";
    $params[':category'] = $category_filter;
}
if($stock_filter == 'low') {
    $where_conditions[] = "COALESCE(p.released_stock,0) <= 5 AND COALESCE(p.released_stock,0) > 0";
}
if($stock_filter == 'out') {
    $where_conditions[] = "COALESCE(p.released_stock,0) <= 0";
}
if($featured_filter) {
    $where_conditions[] = "p.featured = 1";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$query = "SELECT p.*, c.name as category_name,
                 COALESCE(p.released_stock, 0) AS released_stock,
                 COALESCE(p.unreleased_stock, 0) AS unreleased_stock
          FROM products p
          LEFT JOIN categories c ON p.category_id = c.id
          $where_clause
          ORDER BY p.created_at DESC";
$stmt = $db->prepare($query);
foreach($params as $key => $value) $stmt->bindValue($key, $value);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$total_products    = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$low_stock         = $db->query("SELECT COUNT(*) FROM products
                                  WHERE COALESCE(released_stock,0) <= 5
                                    AND COALESCE(released_stock,0) > 0")->fetchColumn();
$out_stock         = $db->query("SELECT COUNT(*) FROM products
                                  WHERE COALESCE(released_stock,0) <= 0")->fetchColumn();
$featured_products = $db->query("SELECT COUNT(*) FROM products WHERE featured = 1")->fetchColumn();

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
        $isNew  = in_array($status, ['pending','new','placed'], true) && productsIsRecent($r['created_at'] ?? null, 24);
        $admin_notifications[] = [
            'id' => 'order-' . $r['id'], 'icon' => 'shopping-cart', 'iconClass' => 'blue',
            'title' => ($isNew ? 'New order #' : 'Order #') . $r['id'] . ($isNew ? ' received' : ' · ' . ucfirst($status)),
            'desc'  => trim((string)($r['full_name'] ?? 'Customer')) . ' · ' . number_format((float)($r['total_amount'] ?? 0), 2) . ' · Status: ' . ucfirst($status),
            'meta'  => productsFormatTime($r['created_at'] ?? null),
            'unread' => $isNew,
            'link' => 'orders.php?id=' . (int)$r['id'],
            '_ts' => strtotime($r['created_at'] ?? '') ?: 0,
        ];
    }
} catch (Throwable $e) {}

// Lay-away
try {
    $rows = $db->query("SELECT lp.id, lp.total_amount, lp.status, lp.created_at, u.full_name
                        FROM layaway_plans lp
                        LEFT JOIN users u ON u.id = lp.user_id
                        ORDER BY lp.created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $status = strtolower((string)($r['status'] ?? 'pending'));
        $isNew  = in_array($status, ['pending','new','active'], true) && productsIsRecent($r['created_at'] ?? null, 24);
        $admin_notifications[] = [
            'id'        => 'layaway-' . $r['id'],
            'icon'      => 'hand-holding-heart',
            'iconClass' => 'purple',
            'title'     => ($isNew ? 'New lay-away #LY-' : 'Lay-away #LY-') . str_pad($r['id'], 4, '0', STR_PAD_LEFT) . ($isNew ? ' request' : ' · ' . ucfirst($status)),
            'desc'      => trim((string)($r['full_name'] ?? 'Customer')) . ' · ₱' . number_format((float)($r['total_amount'] ?? 0), 2) . ' · Status: ' . ucfirst($status),
            'meta'      => productsFormatTime($r['created_at'] ?? null),
            'unread'    => $isNew,
            'link'      => 'layaway.php?id=' . (int)$r['id'],
            '_ts'       => strtotime($r['created_at'] ?? '') ?: 0,
        ];
    }
} catch (Throwable $e) {}

// Low stock — includes vault count + ?focus link
try {
    $rows = $db->query("SELECT id, name, stock,
                               COALESCE(released_stock,0)   AS released_stock,
                               COALESCE(unreleased_stock,0) AS unreleased_stock
                        FROM products
                        WHERE COALESCE(released_stock,0) <= 5
                        ORDER BY COALESCE(released_stock,0) ASC
                        LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $shop = max(0, (int)$r['released_stock']);
        $vault = max(0, (int)$r['unreleased_stock']);
        $admin_notifications[] = [
            'id' => 'stock-' . $r['id'], 'icon' => 'gem', 'iconClass' => 'gold',
            'title' => 'Low stock: ' . (string)$r['name'],
            'desc'  => $shop . ' unit(s) in shop · ' . $vault . ' in vault (total ' . (int)$r['stock'] . ').',
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
        $isRecent = productsIsRecent($r['created_at'] ?? null, 24);
        $admin_notifications[] = [
            'id' => 'customer-' . $r['id'], 'icon' => 'user-plus', 'iconClass' => 'green',
            'title' => 'New user: ' . (string)$r['full_name'],
            'desc'  => 'User account registered.' . ($isRecent ? '' : ' (older)'),
            'meta'  => productsFormatTime($r['created_at'] ?? null),
            'unread' => $isRecent,
            'link' => 'users.php?open=' . (int)$r['id'],
            '_ts' => strtotime($r['created_at'] ?? '') ?: 0,
        ];
    }
} catch (Throwable $e) {}

// ============================================================
// APPLY GLOBAL SERVER STATE
// ============================================================
$readIds      = notifReadIds($db);
$deletedIds   = notifDeletedIds($db);
$savedPrefs   = adminPrefs($db);
$currentAdmin = getCurrentAdmin($db, (int)$_SESSION['user_id']);

$admin_notifications = array_values(array_filter(
    $admin_notifications,
    fn($n) => !isset($deletedIds[(string)$n['id']])
));
foreach ($admin_notifications as &$n) {
    if (isset($readIds[(string)$n['id']])) $n['unread'] = false;
}
unset($n);

usort($admin_notifications, function ($a, $b) {
    return ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0);
});
foreach ($admin_notifications as &$n) { unset($n['_ts']); }
unset($n);

$savedPrefs = array_merge([
    'setTheme'          => 'cream',
    'setAccent'         => '#5b5fd4',
    'setSidebar'        => 'dark',
    'toggleCompact'     => '0',
    'toggleAnimations'  => '1',
    'toggleNewOrders'   => '1',
    'toggleLowStock'    => '1',
    'toggleNewCustomer' => '1',
    'toggleOrderStatus' => '0',
    'toggleWeekly'      => '0',
], $savedPrefs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Products Management | R-Jewel&Gold</title>
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
            color: var(--nav-text-bright); font-size:.9rem; font-family:'Inter', sans-serif; transition: all .2s ease; }
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
        .item-content p { margin:0; color: var(--nav-text-bright); font-size:.86rem;
            font-weight:500; line-height:1.4; }
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
        .brand-icon { width: 38px; height: 54px; display: flex;
            align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-icon svg { width: 100%; height: 100%;
            filter: drop-shadow(0 0 6px rgba(201, 162, 39, 0.35)); }
        .brand-text { min-width: 0; display: flex; flex-direction: column;
            justify-content: center; }
        .brand-text h4 { font-family: 'Playfair Display', 'Georgia', 'Times New Roman', serif;
            font-size: 1.5rem; font-weight: 700; margin: 0; line-height: 1.05;
            letter-spacing: -0.01em; color: #ffffff; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis; }
        .brand-text h4 .amp { font-family: 'Playfair Display', 'Georgia', serif;
            font-style: italic; font-weight: 400; color: #c9a227;
            -webkit-text-fill-color: #c9a227; margin: 0 1px; letter-spacing: 0; }
        .brand-text p { font-family: 'Inter', sans-serif; font-size: 0.62rem;
            color: #8b92a5; margin: 5px 0 0; letter-spacing: 0.24em;
            text-transform: uppercase; font-weight: 500; white-space: nowrap; }
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
            width: 24px;
            font-size: 1.1rem;
            text-align: center;
            flex-shrink: 0;
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
            font-size:1.3rem; background: var(--primary-soft); color: var(--primary);
            flex-shrink:0; }
        .stat-icon.gold    { background: var(--gold-soft); color: var(--gold); }
        .stat-icon.success { background: rgba(13,150,104,.08); color: var(--success); }
        .stat-icon.warning { background: rgba(217,119,6,.08);  color: var(--warning); }
        .stat-icon.danger  { background: rgba(220,38,38,.08);  color: var(--danger); }
        .stat-icon.info    { background: rgba(37,99,235,.08);  color: var(--info); }
        .stat-change { font-size:.72rem; font-weight:600; padding: 5px 11px;
            border-radius:20px; background: rgba(13,150,104,.08); color: var(--success);
            display:inline-flex; align-items:center; gap:4px; white-space:nowrap; }
        .stat-change.down { background: rgba(220,38,38,.08); color: var(--danger); }
        .stat-info h3 { font-size:1.8rem; font-weight:700; margin:0;
            color: var(--text-bright); letter-spacing:-.03em; }
        .stat-info p { margin:4px 0 0; color: var(--text-muted);
            font-size:.82rem; font-weight:500; }
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
        .table-modern tbody tr.focus-row {
            background: rgba(91,95,212,.08) !important;
            box-shadow: inset 4px 0 0 var(--primary);
            animation: focusPulse 1.4s ease-out;
        }
        @keyframes focusPulse {
            0%   { background: rgba(91,95,212,.22); }
            100% { background: rgba(91,95,212,.08); }
        }
        .product-image { width:58px; height:58px; object-fit:cover;
            border-radius:11px; border:1px solid var(--border); }
        .badge-stock { padding: 5px 13px; border-radius:20px; font-size:.72rem;
            font-weight:600; display:inline-block; white-space:nowrap; }
        .badge-stock.high   { background: rgba(13,150,104,.1); color:#0a7a54; }
        .badge-stock.medium { background: rgba(217,119,6,.1);  color:#b45309; }
        .badge-stock.low    { background: rgba(220,38,38,.1);  color:#b91c1c; }
        .badge-released { background: rgba(37,99,235,.1); color:#1d4ed8;
            padding: 4px 11px; border-radius:20px; font-size:.72rem;
            font-weight:600; display:inline-block; white-space:nowrap; }
        .featured-badge { background: var(--gold-soft); color: var(--gold);
            padding: 4px 10px; border-radius:20px; font-size:.68rem;
            font-weight:600; display:inline-block; margin-top:6px; }
        .btn-modern { background: var(--primary); border:none; color:#fff;
            border-radius:10px; font-size:.82rem; font-weight:600;
            padding: 10px 20px; transition: all .2s ease;
            text-decoration:none; display:inline-flex; align-items:center;
            gap:8px; white-space:nowrap; cursor:pointer; }
        .btn-modern:hover { background:#4f46e5; color:#fff;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px -3px rgba(91,95,212,.35); }
        .btn-outline-modern { background: transparent; border:1px solid var(--border);
            color: var(--text-muted); border-radius:8px; font-size:.78rem;
            font-weight:500; padding: 6px 14px; transition: all .2s ease;
            text-decoration:none; display:inline-flex; align-items:center;
            gap:6px; white-space:nowrap; cursor:pointer; }
        .btn-outline-modern:hover { background: var(--primary-soft);
            border-color: var(--primary); color: var(--primary); }
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
        .icon-action.success:hover { background: rgba(13,150,104,.08);
            border-color: var(--success); color: var(--success); }
        .form-check-input { border:1px solid var(--border);
            background-color: var(--bg-soft); }
        .form-check-input:checked { background-color: var(--primary);
            border-color: var(--primary); }
        .form-check-input:focus { border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft); }
        .alert-modern { border-radius:12px; padding: 14px 18px;
            font-size:.86rem; margin-bottom:20px; display:flex;
            justify-content:space-between; align-items:center; gap:12px; }
        .alert-modern.success { background: rgba(13,150,104,.08);
            border:1px solid rgba(13,150,104,.2); color: #0a7a54; }
        .alert-modern.danger { background: rgba(220,38,38,.06);
            border:1px solid rgba(220,38,38,.2); color: #b91c1c; }
        .alert-modern button { background:none; border:none; color: inherit;
            cursor:pointer; font-size:.9rem; opacity:.7; }
        .alert-modern button:hover { opacity:1; }
        .modal-content { border:none; border-radius:16px; background: var(--card);
            color: var(--text); }
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
        .btn-cancel { background: transparent; border:1px solid var(--border);
            color: var(--text-muted); border-radius:10px; font-size:.86rem;
            font-weight:500; padding: 10px 22px; cursor:pointer; transition: all .2s ease; }
        .btn-cancel:hover { background: var(--bg-soft);
            border-color: var(--border-light); color: var(--text-bright); }
        .btn-save { background: var(--primary); border:none; color:#fff;
            border-radius:10px; font-size:.86rem; font-weight:600;
            padding: 10px 24px; cursor:pointer; transition: all .2s ease;
            display:inline-flex; align-items:center; gap:7px; }
        .btn-save:hover { background:#4f46e5; transform: translateY(-1px);
            box-shadow: 0 6px 18px -3px rgba(91,95,212,.35); }
        .image-preview { max-width:180px; margin-top:10px;
            border-radius:10px; border:1px solid var(--border); }
        .bulk-bar { display:flex; align-items:center; gap:12px; flex-wrap:wrap;
            padding-top:16px; margin-top:16px; border-top:1px solid var(--border); }
        .bulk-bar .form-select { background: var(--bg-soft);
            border:1px solid var(--border); border-radius:10px;
            padding: 9px 16px; color: var(--text); font-size:.82rem; width:auto; }
        .empty-state { text-align:center; padding: 70px 20px; color: var(--text-dim); }
        .empty-state i { font-size:3rem; margin-bottom:18px; opacity:.4; }
        .empty-state h5 { color: var(--text-bright); font-size:1.05rem;
            margin-bottom:8px; font-weight:700; }
        .empty-state p { font-size:.86rem; margin:0; }
        ::-webkit-scrollbar { width:7px; height:7px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-light);
            border-radius:4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-dim); }
        .panel-body::-webkit-scrollbar-thumb { background: var(--nav-border); }
        .view-all-page { position: fixed; inset: 0; background: var(--bg);
            z-index: 2000; display: none; overflow-y: auto;
            animation: fadeIn .25s ease; }
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
        .view-all-actions { display: flex; align-items: center; gap: 10px;
            flex-wrap: wrap; }
        .view-all-search { position: relative; min-width: 240px; }
        .view-all-search i { position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%); color: var(--text-dim);
            font-size: .88rem; pointer-events: none; }
        .view-all-search input { width: 100%; background: var(--bg-soft);
            border: 1px solid var(--border); border-radius: 10px;
            padding: 9px 14px 9px 38px; color: var(--text);
            font-size: .86rem; font-family: 'Inter', sans-serif;
            transition: all .2s ease; }
        .view-all-search input::placeholder { color: var(--text-dim); }
        .view-all-search input:focus { outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft); background: var(--card); }
        .filter-chip { padding: 8px 16px; border-radius: 20px;
            background: var(--card); border: 1px solid var(--border);
            color: var(--text-muted); font-size: .78rem; font-weight: 500;
            cursor: pointer; transition: all .2s ease; white-space: nowrap; }
        .filter-chip:hover { border-color: var(--primary); color: var(--primary); }
        .filter-chip.active { background: var(--primary);
            border-color: var(--primary); color: #fff; }
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
        .view-all-item .item-actions { display: flex;
            align-items: center; gap: 8px; flex-shrink: 0; }
        .icon-action-page { width: 38px; height: 38px; border-radius: 10px;
            background: transparent; border: 1px solid var(--border);
            color: var(--text-muted); font-size: .88rem; display: flex;
            align-items: center; justify-content: center;
            cursor: pointer; transition: all .2s ease; }
        .icon-action-page:hover { background: var(--primary-soft);
            border-color: var(--primary); color: var(--primary); }
        .icon-action-page.danger:hover { background: rgba(220,38,38,.08);
            border-color: var(--danger); color: var(--danger); }
        .view-all-empty { padding: 70px 20px; text-align: center;
            color: var(--text-dim); }
        .view-all-empty i { font-size: 3rem; margin-bottom: 16px; opacity: .4; }
        .view-all-empty p { font-size: .94rem; margin: 0; }
        .settings-layout { max-width: 1100px; margin: 0 auto;
            padding: 28px 30px; display: grid;
            grid-template-columns: 260px 1fr; gap: 28px; align-items: start; }
        .settings-sidebar { background: var(--card);
            border: 1px solid var(--border); border-radius: 16px;
            padding: 12px; position: sticky; top: 110px;
            box-shadow: 0 1px 3px rgba(90,82,69,.05); }
        .settings-nav-item { display: flex; align-items: center; gap: 14px;
            padding: 13px 16px; border-radius: 10px;
            color: var(--text-muted); font-size: .88rem; font-weight: 500;
            cursor: pointer; transition: all .2s ease; text-decoration: none;
            border: none; background: transparent; width: 100%; text-align: left; }
        .settings-nav-item:hover { background: var(--bg-soft);
            color: var(--text-bright); }
        .settings-nav-item.active { background: var(--primary-soft);
            color: var(--primary); font-weight: 600;
            box-shadow: inset 3px 0 0 var(--primary); }
        .settings-nav-item i { width: 22px; text-align: center; font-size: .95rem; }
        .settings-content { background: var(--card);
            border: 1px solid var(--border); border-radius: 16px;
            padding: 30px; box-shadow: 0 1px 3px rgba(90,82,69,.05); }
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
        .settings-field .field-hint { font-size: .74rem;
            color: var(--text-dim); margin-top: 6px; }
        .settings-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .settings-toggle { display: flex; justify-content: space-between;
            align-items: center; padding: 16px 0;
            border-bottom: 1px solid var(--border); gap: 18px; }
        .settings-toggle:last-child { border-bottom: none; }
        .toggle-info h5 { margin: 0; font-size: .9rem; font-weight: 600;
            color: var(--text-bright); }
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
        .settings-actions { display: flex; justify-content: flex-end;
            gap: 12px; margin-top: 28px; padding-top: 20px;
            border-top: 1px solid var(--border); }
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
            * { animation-duration: .01ms !important;
                transition-duration: .01ms !important; }
        }
        body.compact-mode .stat-card, body.compact-mode .card-modern,
        body.compact-mode .settings-section, body.compact-mode .filter-bar {
            padding-top: 10px; padding-bottom: 10px; }
        body.compact-mode .nav-link { padding-top: 7px; padding-bottom: 7px; }
        body.no-animations *, body.no-animations *::before,
        body.no-animations *::after {
            animation: none !important; transition: none !important;
            scroll-behavior: auto !important; }

        .stock-pill { display:inline-flex; align-items:center; gap:6px;
            padding: 4px 12px; border-radius: 20px; font-size:.74rem;
            font-weight: 600; }
        .stock-pill.total    { background: rgba(91,95,212,.1); color: var(--primary); }
        .stock-pill.released { background: rgba(37,99,235,.1); color:#1d4ed8; }
        .stock-pill.unreleased { background: rgba(217,119,6,.12); color:#b45309; }
        .stock-calc { display:flex; gap:8px; flex-wrap:wrap;
            padding: 10px 12px; background: var(--bg-soft);
            border:1px solid var(--border); border-radius:10px; margin-bottom:14px; }
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
                <input type="text" placeholder="Search products..." id="navSearchInput"
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
                    <strong id="headerAdminName"><?php echo htmlspecialchars($currentAdmin['full_name']); ?></strong>
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
            <div class="nav-item"><a href="products.php" class="nav-link active"><i class="fas fa-gem"></i><span>Products</span></a></div>
            <div class="nav-item"><a href="categories.php" class="nav-link"><i class="fas fa-tags"></i><span>Categories</span></a></div>
            <div class="nav-item"><a href="orders.php" class="nav-link"><i class="fas fa-shopping-cart"></i><span>Orders</span></a></div>
            <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i><span>Customers</span></a></div>
            <div class="nav-item"><a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></div>
        </nav>
    </aside>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h2>Products Management</h2>
                <p>Manage your jewelry collection</p>
            </div>
            <div class="page-header-actions">
                <button class="btn-modern" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus"></i> Add New Product
                </button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card gold">
                <div class="stat-header">
                    <div class="stat-icon gold"><i class="fas fa-gem"></i></div>
                    <span class="stat-change"><i class="fas fa-boxes"></i> Total</span>
                </div>
                <div class="stat-info"><h3><?php echo $total_products; ?></h3><p>Total Products</p></div>
            </div>
            <div class="stat-card success">
                <div class="stat-header">
                    <div class="stat-icon success"><i class="fas fa-star"></i></div>
                    <span class="stat-change"><i class="fas fa-arrow-up"></i> Featured</span>
                </div>
                <div class="stat-info"><h3><?php echo $featured_products; ?></h3><p>Featured Items</p></div>
            </div>
            <div class="stat-card warning">
                <div class="stat-header">
                    <div class="stat-icon warning"><i class="fas fa-exclamation-triangle"></i></div>
                    <span class="stat-change down"><i class="fas fa-arrow-down"></i> Restock</span>
                </div>
                <div class="stat-info"><h3><?php echo $low_stock; ?></h3><p>Low Stock (≤5 shop)</p></div>
            </div>
            <div class="stat-card danger">
                <div class="stat-header">
                    <div class="stat-icon danger"><i class="fas fa-times-circle"></i></div>
                    <span class="stat-change down"><i class="fas fa-ban"></i> Sold Out</span>
                </div>
                <div class="stat-info"><h3><?php echo $out_stock; ?></h3><p>Out of Stock</p></div>
            </div>
        </div>

        <div class="filter-bar">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="stock" class="form-select">
                        <option value="">All Stock</option>
                        <option value="low" <?php echo $stock_filter == 'low' ? 'selected' : ''; ?>>Low (≤5 shop)</option>
                        <option value="out" <?php echo $stock_filter == 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="featured" class="form-select">
                        <option value="">All Products</option>
                        <option value="1" <?php echo $featured_filter == '1' ? 'selected' : ''; ?>>Featured Only</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn-modern w-100 justify-content-center">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>

        <div class="card-modern">
            <div class="card-header-modern">
                <div>
                    <h4>Product List</h4>
                    <p><?php echo count($products); ?> product(s) found</p>
                </div>
                <button class="btn-modern btn-sm-modern" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus"></i> Add Product
                </button>
            </div>

            <?php if(isset($_SESSION['message'])): ?>
            <div class="alert-modern success" id="flashMessage">
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
                                <th width="30"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                                <th width="80">Image</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Monthly Stock</th>
                                <th>Released</th>
                                <th>Unreleased</th>
                                <th>Status</th>
                                <th width="160">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($products)): ?>
                            <tr>
                                <td colspan="10">
                                    <div class="empty-state">
                                        <i class="fas fa-box-open"></i>
                                        <h5>No products found</h5>
                                        <p>Try adjusting your filters or add a new product.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($products as $product): ?>
                            <?php
                                $monthlyStock    = (int)$product['stock'];
                                $releasedStock   = (int)($product['released_stock'] ?? 0);
                                $unreleasedStock = (int)($product['unreleased_stock'] ?? 0);
                                $isFocusRow      = ($focus_id > 0 && (int)$product['id'] === $focus_id);
                            ?>
                            <tr<?php echo $isFocusRow ? ' class="focus-row" id="focusRow"' : ''; ?>>
                                <td>
                                    <input type="checkbox" name="product_ids[]" value="<?php echo $product['id']; ?>" class="product-checkbox form-check-input">
                                </td>
                                <td>
                                    <img src="../uploads/<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>"
                                         class="product-image" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                    <?php if($product['featured']): ?>
                                        <br><span class="featured-badge"><i class="fas fa-star"></i> Featured</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                                <td><strong>₱<?php echo number_format($product['price'], 2); ?></strong></td>
                                <td>
                                    <span class="badge-stock high">
                                        <i class="fas fa-cubes"></i> <?php echo $monthlyStock; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-released">
                                        <i class="fas fa-truck-loading"></i> <?php echo $releasedStock; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $badge_class = 'high';
                                    $badge_text  = 'Available';
                                    if ($unreleasedStock <= 0)      { $badge_class = 'low';    $badge_text = 'None'; }
                                    elseif ($unreleasedStock <= 5)  { $badge_class = 'medium'; $badge_text = 'Low';  }
                                    ?>
                                    <span class="badge-stock <?php echo $badge_class; ?>">
                                        <i class="fas fa-clock"></i> <?php echo $unreleasedStock; ?> <?php echo $badge_text; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if($releasedStock > 0): ?>
                                        <span class="badge-stock high"><i class="fas fa-check-circle"></i> Active</span>
                                    <?php else: ?>
                                        <span class="badge-stock low"><i class="fas fa-times-circle"></i> Sold Out</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="icon-action success" title="Adjust Stock"
                                                onclick='openStockModal(<?php echo json_encode([
                                                    "id"       => (int)$product["id"],
                                                    "name"     => $product["name"],
                                                    "stock"    => (int)$product["stock"],
                                                    "released" => (int)$releasedStock,
                                                    "unreleased" => (int)$unreleasedStock,
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                            <i class="fas fa-boxes"></i>
                                        </button>
                                        <button type="button" class="icon-action" title="Edit"
                                                onclick="editProduct(<?php echo $product['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?delete=<?php echo $product['id']; ?>"
                                           class="icon-action danger" title="Delete"
                                           onclick="return confirm('Are you sure you want to delete this product?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if(!empty($products)): ?>
                <div class="bulk-bar">
                    <select name="bulk_action" class="form-select">
                        <option value="">Bulk Actions</option>
                        <option value="delete">Delete Selected</option>
                        <option value="featured">Mark as Featured</option>
                        <option value="unfeatured">Remove from Featured</option>
                    </select>
                    <button type="submit" class="btn-modern btn-sm-modern">
                        <i class="fas fa-check"></i> Apply
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </main>

    <!-- ADD PRODUCT MODAL -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="save-product.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">Product Name *</label>
                                    <input type="text" name="name" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Category *</label>
                                    <select name="category_id" class="form-select" required>
                                        <option value="">Select</option>
                                        <?php foreach($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Karat</label>
                                    <input type="text" name="karat" class="form-control" placeholder="e.g. 18K, 24K, 14K">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Weight (grams)</label>
                                    <input type="number" step="0.01" min="0" name="weight" class="form-control" placeholder="e.g. 5.25">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Price *</label>
                                    <input type="number" step="0.01" name="price" class="form-control" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Monthly Stock (Total) *</label>
                                    <input type="number" name="stock" class="form-control" min="0" value="0" required id="addStockInput">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Unreleased (Vault) *</label>
                                    <input type="number" name="unreleased_stock" class="form-control" value="0" min="0" id="addUnreleasedInput" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Released (Shop - auto)</label>
                                    <input type="text" class="form-control" value="0" readonly style="opacity:.6;cursor:not-allowed;" id="autoReleasedDisplay">
                                    <input type="hidden" name="released_stock" id="autoReleasedHidden" value="0">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Product Image</label>
                            <input type="file" name="image" class="form-control" accept="image/*" id="productImage">
                            <small style="color:var(--text-dim); font-size:.76rem;">Recommended: 500x500px. Max 2MB.</small>
                            <div id="imagePreview"></div>
                        </div>

                        <div class="form-check">
                            <input type="checkbox" name="featured" class="form-check-input" value="1" id="featured">
                            <label class="form-check-label" for="featured" style="font-size:.88rem; margin-left:6px;">
                                <i class="fas fa-star" style="color:var(--gold);"></i> Feature this product
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-save"><i class="fas fa-check"></i> Save Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EDIT PRODUCT MODAL -->
    <div class="modal fade" id="editProductModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div id="editProductContent"></div>
            </div>
        </div>
    </div>

    <!-- QUICK ADJUST STOCK MODAL -->
    <div class="modal fade" id="stockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-boxes"></i> Adjust Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="products.php<?php echo !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" method="POST" id="stockForm">
                    <input type="hidden" name="quick_stock_update" value="1">
                    <input type="hidden" name="product_id" id="smProductId">
                    <div class="modal-body" style="padding:20px 22px;">
                        <div class="mb-3" style="margin-bottom:14px;">
                            <label class="form-label">Product</label>
                            <input type="text" class="form-control" id="smProductName" disabled style="opacity:.75;">
                        </div>

                        <div class="row g-2" style="--bs-gutter-x: 12px; margin-bottom:14px;">
                            <div class="col-6">
                                <label class="form-label">Monthly Stock (Total)</label>
                                <input type="number" min="0" name="monthly_stock" id="smMonthly" class="form-control" required
                                       style="font-size:1rem; font-weight:600; padding:11px 14px;">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Unreleased (Vault)</label>
                                <input type="number" min="0" name="unreleased_stock" id="smUnreleased" class="form-control" required
                                       style="font-size:1rem; font-weight:600; padding:11px 14px;">
                            </div>
                        </div>

                        <div class="row g-2" style="--bs-gutter-x: 12px; margin-bottom:14px;">
                            <div class="col-6">
                                <label class="form-label">Released (Shop - auto)</label>
                                <input type="number" id="smReleased" class="form-control" readonly
                                       style="opacity:.7; cursor:not-allowed; background:var(--bg);">
                            </div>
                        </div>

                        <div class="stock-calc" id="smCalc" style="margin-bottom:0;">
                            <span class="stock-pill total"><i class="fas fa-cubes"></i> <span id="smPillTotal">0</span> Total</span>
                            <span class="stock-pill released"><i class="fas fa-truck-loading"></i> <span id="smPillReleased">0</span> Shop</span>
                            <span class="stock-pill unreleased"><i class="fas fa-clock"></i> <span id="smPillUnreleased">0</span> Vault</span>
                        </div>
                        <small style="color:var(--text-dim); font-size:.72rem; display:block; margin-top:10px; line-height:1.5;">
                            <i class="fas fa-info-circle"></i> The Shop field is the active inventory customers buy from. Changing Total or Vault will automatically recalculate the Shop stock.
                        </small>
                    </div>
                    <div class="modal-footer" style="padding:14px 22px;">
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-save"><i class="fas fa-check"></i> Save Stock</button>
                    </div>
                </form>
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
                    <p>All preferences sync across every browser and device</p>
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
                    <p class="section-desc">Update your admin account details. Changes sync across all devices.</p>
                    <div class="settings-row">
                        <div class="settings-field">
                            <label>Full Name</label>
                            <input type="text" id="setFullName" value="<?php echo htmlspecialchars($currentAdmin['full_name']); ?>">
                        </div>
                        <div class="settings-field">
                            <label>Username</label>
                            <input type="text" id="setUsername" value="<?php echo htmlspecialchars($currentAdmin['username']); ?>">
                        </div>
                    </div>
                    <div class="settings-field">
                        <label>Email Address</label>
                        <input type="email" id="setEmail" value="<?php echo htmlspecialchars($currentAdmin['email'] ?? ''); ?>">
                        <div class="field-hint">Used for system notifications and password recovery.</div>
                    </div>
                    <div class="settings-row">
                        <div class="settings-field">
                            <label>Phone Number</label>
                            <input type="text" id="setPhone" value="<?php echo htmlspecialchars($currentAdmin['phone'] ?? ''); ?>">
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
                    <p class="section-desc">Manage your password and account security. Changes sync across all devices.</p>
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
                            <input type="checkbox" id="toggle2FA" <?php echo !empty($currentAdmin['two_factor_enabled']) ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Login Alerts</h5>
                            <p>Get notified when a new device signs in.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleLoginAlerts" <?php echo !empty($currentAdmin['login_alerts']) ? 'checked' : ''; ?>>
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
                    <p class="section-desc">Choose what updates you want to receive. Changes sync across all devices.</p>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>New Orders</h5>
                            <p>Notify me when a customer places a new order.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleNewOrders" <?php echo ($savedPrefs['toggleNewOrders'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Low Stock Alerts</h5>
                            <p>Notify me when a product is running low.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleLowStock" <?php echo ($savedPrefs['toggleLowStock'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>New Customer Registrations</h5>
                            <p>Notify me when a new customer signs up.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleNewCustomer" <?php echo ($savedPrefs['toggleNewCustomer'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Order Status Updates</h5>
                            <p>Notify me when order status changes.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleOrderStatus" <?php echo ($savedPrefs['toggleOrderStatus'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Weekly Reports</h5>
                            <p>Send me a summary every Monday morning.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleWeekly" <?php echo ($savedPrefs['toggleWeekly'] === '1') ? 'checked' : ''; ?>>
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
                    <p class="section-desc">Customize how the dashboard looks. Changes sync across all devices.</p>
                    <div class="settings-field">
                        <label>Theme</label>
                        <select id="setTheme">
                            <option value="cream" <?php echo ($savedPrefs['setTheme'] === 'cream') ? 'selected' : ''; ?>>Cream (Default)</option>
                            <option value="light" <?php echo ($savedPrefs['setTheme'] === 'light') ? 'selected' : ''; ?>>Light</option>
                            <option value="dark"  <?php echo ($savedPrefs['setTheme'] === 'dark')  ? 'selected' : ''; ?>>Dark</option>
                            <option value="auto"  <?php echo ($savedPrefs['setTheme'] === 'auto')  ? 'selected' : ''; ?>>Auto (System)</option>
                        </select>
                    </div>
                    <div class="settings-field">
                        <label>Accent Color</label>
                        <select id="setAccent">
                            <option value="#5b5fd4" <?php echo ($savedPrefs['setAccent'] === '#5b5fd4') ? 'selected' : ''; ?>>Indigo (Default)</option>
                            <option value="#b8941f" <?php echo ($savedPrefs['setAccent'] === '#b8941f') ? 'selected' : ''; ?>>Gold</option>
                            <option value="#0d9668" <?php echo ($savedPrefs['setAccent'] === '#0d9668') ? 'selected' : ''; ?>>Emerald</option>
                            <option value="#dc2626" <?php echo ($savedPrefs['setAccent'] === '#dc2626') ? 'selected' : ''; ?>>Ruby</option>
                            <option value="#2563eb" <?php echo ($savedPrefs['setAccent'] === '#2563eb') ? 'selected' : ''; ?>>Sapphire</option>
                        </select>
                    </div>
                    <div class="settings-field">
                        <label>Sidebar Style</label>
                        <select id="setSidebar">
                            <option value="dark"     <?php echo ($savedPrefs['setSidebar'] === 'dark')     ? 'selected' : ''; ?>>Dark (Default)</option>
                            <option value="midnight" <?php echo ($savedPrefs['setSidebar'] === 'midnight') ? 'selected' : ''; ?>>Midnight Blue</option>
                            <option value="charcoal" <?php echo ($savedPrefs['setSidebar'] === 'charcoal') ? 'selected' : ''; ?>>Charcoal</option>
                        </select>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Compact Mode</h5>
                            <p>Reduce padding to fit more content on screen.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleCompact" <?php echo ($savedPrefs['toggleCompact'] === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle">
                        <div class="toggle-info">
                            <h5>Show Animations</h5>
                            <p>Enable smooth transitions and hover effects.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="toggleAnimations" <?php echo ($savedPrefs['toggleAnimations'] === '1') ? 'checked' : ''; ?>>
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
        const SERVER_PREFS = <?php echo json_encode($savedPrefs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const SERVER_ADMIN = <?php echo json_encode($currentAdmin, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        /* ========== Apply appearance immediately on page load ========== */
        (function () {
            const data = SERVER_PREFS;
            const root = document.documentElement;
            const theme = data.setTheme || 'cream';
            const accent = data.setAccent || '#5b5fd4';
            const sidebar = data.setSidebar || 'dark';
            const compact = data.toggleCompact === '1';
            const animsOn = data.toggleAnimations !== '0';

            const effectiveTheme = theme === 'auto'
                ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : theme;

            root.style.setProperty('--primary', accent);
            root.style.setProperty('--primary-soft', accent + '18');
            root.style.setProperty('--sidebar-active', accent + '26');

            if (effectiveTheme === 'dark') {
                root.style.setProperty('--bg', '#151821'); root.style.setProperty('--bg-soft', '#1c202b');
                root.style.setProperty('--card', '#222735'); root.style.setProperty('--card-hover', '#292f3e');
                root.style.setProperty('--border', '#343b4d'); root.style.setProperty('--border-light', '#2d3444');
                root.style.setProperty('--text', '#d5d9e2'); root.style.setProperty('--text-bright', '#ffffff');
                root.style.setProperty('--text-muted', '#a0a8ba'); root.style.setProperty('--text-dim', '#777f91');
            } else if (effectiveTheme === 'light') {
                root.style.setProperty('--bg', '#f5f7fb'); root.style.setProperty('--bg-soft', '#ffffff');
                root.style.setProperty('--card', '#ffffff'); root.style.setProperty('--card-hover', '#f8fafc');
                root.style.setProperty('--border', '#e2e8f0'); root.style.setProperty('--border-light', '#edf1f6');
                root.style.setProperty('--text', '#475569'); root.style.setProperty('--text-bright', '#172033');
                root.style.setProperty('--text-muted', '#64748b'); root.style.setProperty('--text-dim', '#94a3b8');
            } else {
                root.style.setProperty('--bg', '#f2ede4'); root.style.setProperty('--bg-soft', '#faf7f2');
                root.style.setProperty('--card', '#ffffff'); root.style.setProperty('--card-hover', '#faf8f4');
                root.style.setProperty('--border', '#e5ddd0'); root.style.setProperty('--border-light', '#ede7db');
                root.style.setProperty('--text', '#5a5245'); root.style.setProperty('--text-bright', '#2b2620');
                root.style.setProperty('--text-muted', '#8a8172'); root.style.setProperty('--text-dim', '#b3a998');
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

            document.body.classList.toggle('compact-mode', compact);
            document.body.classList.toggle('no-animations', !animsOn);

            if (window.matchMedia) {
                const scheme = window.matchMedia('(prefers-color-scheme: dark)');
                if (scheme.addEventListener) scheme.addEventListener('change', () => {
                    if (theme === 'auto') location.reload();
                });
            }
        })();

        /* ========== SIDEBAR ========== */
        (function () {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            function openSidebar() { sidebar.classList.add('active'); overlay.classList.add('active'); document.body.style.overflow = 'hidden'; }
            function closeSidebar() { sidebar.classList.remove('active'); overlay.classList.remove('active'); document.body.style.overflow = ''; }
            menuToggle && menuToggle.addEventListener('click', function () {
                sidebar.classList.contains('active') ? closeSidebar() : openSidebar();
            });
            overlay && overlay.addEventListener('click', closeSidebar);
            document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
                link.addEventListener('click', function () { if (window.innerWidth <= 992) closeSidebar(); });
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && sidebar.classList.contains('active')) closeSidebar();
            });
            let resizeTimer;
            window.addEventListener('resize', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () { if (window.innerWidth > 992) closeSidebar(); }, 150);
            });
        })();

        /* ========== USER DROPDOWN ========== */
        (function () {
            const navUser = document.getElementById('navUser');
            if (!navUser) return;
            navUser.addEventListener('click', function (e) { e.stopPropagation(); navUser.classList.toggle('open'); });
            document.addEventListener('click', function () { navUser.classList.remove('open'); });
        })();

        /* ========== NAV SEARCH ========== */
        (function () {
            const navSearch = document.getElementById('navSearchInput');
            if (!navSearch) return;
            navSearch.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    const url = new URL(window.location.href);
                    url.searchParams.set('search', this.value);
                    window.location.href = url.toString();
                }
            });
        })();

        /* ========== SHARED TOAST + SERVER SYNC ========== */
        function showToast(msg, type) {
            const toast = document.getElementById('saveToast');
            const toastMsg = document.getElementById('saveToastMsg');
            if (!toast || !toastMsg) return;
            toastMsg.textContent = msg || 'Settings saved successfully!';
            toast.style.background = type === 'error' ? 'var(--danger)' : 'var(--success)';
            toast.style.boxShadow = type === 'error' ? '0 10px 30px -8px rgba(220,38,38,.5)' : '0 10px 30px -8px rgba(13,150,104,.5)';
            toast.classList.add('show');
            clearTimeout(window.__toastTimer);
            window.__toastTimer = setTimeout(() => toast.classList.remove('show'), 3000);
        }

        async function syncServerPrefs(action, extraFields) {
            const fd = new FormData();
            fd.append('_notif_sync', '1');
            fd.append('action', action);
            Object.keys(extraFields || {}).forEach(k => fd.append(k, extraFields[k]));
            try {
                const res = await fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
                return await res.json();
            } catch (e) {
                console.warn('sync failed:', e);
                return { success: false };
            }
        }

        /* ========== NOTIFICATIONS — global server sync ========== */
        (function () {
            const notificationsData = <?php echo json_encode($admin_notifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            const state = { notif: { data: notificationsData.map(n => ({ ...n, unread: Boolean(n.unread) })), filter: 'all', search: '' } };

            function getFiltered(key) {
                const { data, filter, search } = state[key];
                let list = data.slice();
                const prefs = SERVER_PREFS || {};
                if (key === 'notif') {
                    list = list.filter(item => {
                        const id = String(item.id || '');
                        if (id.startsWith('order-')) {
                            if (String(item.title || '').startsWith('New order')) return prefs.toggleNewOrders !== '0';
                            return prefs.toggleOrderStatus === '1';
                        }
                        if (id.startsWith('stock-'))    return prefs.toggleLowStock !== '0';
                        if (id.startsWith('customer-')) return prefs.toggleNewCustomer !== '0';
                        if (id.startsWith('layaway-'))  return prefs.toggleNewOrders !== '0';
                        return true;
                    });
                }
                if (filter === 'unread') list = list.filter(i => i.unread);
                if (search.trim()) {
                    const q = search.trim().toLowerCase();
                    list = list.filter(i =>
                        String(i.title || '').toLowerCase().includes(q) ||
                        String(i.desc  || '').toLowerCase().includes(q) ||
                        String(i.meta  || '').toLowerCase().includes(q)
                    );
                }
                return list;
            }

            function escapeHtml(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            async function syncServer(action, ids) {
                if (!ids || !ids.length) return;
                try {
                    const fd = new FormData();
                    fd.append('_notif_sync', '1');
                    fd.append('action', action);
                    ids.forEach(id => fd.append('ids[]', String(id)));
                    await fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                } catch (e) { console.warn('[notifications] sync failed:', action, e); }
            }

            function renderDropdown(key) {
                const listEl  = document.getElementById('notifList');
                const badgeEl = document.getElementById('notifBadge');
                const items = getFiltered(key);
                listEl.innerHTML = '';
                if (items.length === 0) {
                    listEl.innerHTML = '<div class="panel-empty"><i class="fas fa-inbox"></i><p>No notifications</p></div>';
                } else {
                    items.slice(0, 6).forEach(function (item) {
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
                                renderDropdown(key); renderFullPage(key);
                                syncServer('read', [String(item.id)]);
                            }
                            if (!item.link) { e.preventDefault(); e.stopPropagation(); }
                        });
                        listEl.appendChild(el);
                    });
                }
                const count = state[key].data.filter(i => i.unread).length;
                if (count > 0) { badgeEl.textContent = count > 9 ? '9+' : count; badgeEl.classList.remove('hidden'); }
                else { badgeEl.classList.add('hidden'); }
            }

            function renderFullPage(key) {
                const listEl  = document.getElementById('notifFullList');
                const countEl = document.getElementById('notifCount');
                const items = getFiltered(key);
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
                        e.preventDefault(); e.stopPropagation();
                        if (item.unread) {
                            item.unread = false;
                            renderFullPage(key); renderDropdown(key);
                            syncServer('read', [String(item.id)]);
                        }
                    });
                    row.querySelector('[data-action="delete"]').addEventListener('click', function (e) {
                        e.preventDefault(); e.stopPropagation();
                        const idx = state[key].data.findIndex(d => d.id === item.id);
                        if (idx > -1) state[key].data.splice(idx, 1);
                        renderFullPage(key); renderDropdown(key);
                        syncServer('delete', [String(item.id)]);
                    });
                    row.addEventListener('click', function (e) {
                        if (item.unread) {
                            item.unread = false;
                            renderFullPage(key); renderDropdown(key);
                            syncServer('read', [String(item.id)]);
                        }
                        if (!item.link) { e.preventDefault(); e.stopPropagation(); }
                    });
                    listEl.appendChild(row);
                });
            }

            function setActiveFilter(key, filter) {
                document.querySelectorAll('.filter-chip[data-target="notif"]').forEach(function (chip) {
                    chip.classList.toggle('active', chip.dataset.filter === filter);
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
            document.addEventListener('click', function (e) { if (!e.target.closest('.nav-dropdown-wrapper')) closeAllPanels(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAllPanels(); });

            document.getElementById('markAllNotif').addEventListener('click', function (e) {
                e.stopPropagation();
                const unreadIds = state.notif.data.filter(n => n.unread).map(n => String(n.id));
                state.notif.data.forEach(n => n.unread = false);
                renderDropdown('notif'); renderFullPage('notif');
                syncServer('read', unreadIds);
            });

            document.getElementById('viewAllNotif').addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                closeAllPanels();
                state.notif.filter = 'all'; state.notif.search = '';
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
                        if (el && el.classList.contains('active')) { el.classList.remove('active'); document.body.style.overflow = ''; }
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
                renderFullPage('notif'); renderDropdown('notif');
                syncServer('read', unreadIds);
            });

            window.addEventListener('rjewel:settings-changed', function () {
                renderDropdown('notif'); renderFullPage('notif');
            });

            renderDropdown('notif');
            renderFullPage('notif');
        })();

        /* ========== SETTINGS PAGE — every change syncs to server ========== */
        (function () {
            const settingsPage = document.getElementById('settingsPage');
            const openSettings = document.getElementById('openSettings');
            if (!settingsPage) return;

            const ids = [
                'setFullName','setUsername','setEmail','setPhone',
                'toggle2FA','toggleLoginAlerts',
                'toggleNewOrders','toggleLowStock','toggleNewCustomer',
                'toggleOrderStatus','toggleWeekly',
                'setTheme','setAccent','setSidebar','toggleCompact','toggleAnimations',
            ];
            const appearanceIds = ['setTheme','setAccent','setSidebar','toggleCompact','toggleAnimations'];
            const notifPrefIds   = ['toggleNewOrders','toggleLowStock','toggleNewCustomer','toggleOrderStatus','toggleWeekly'];
            const passwordIds    = ['setCurrentPass','setNewPass','setConfirmPass'];
            const controls = {};
            ids.concat(passwordIds).forEach(id => {
                const el = document.getElementById(id);
                if (el) controls[id] = el;
            });

            function applyAppearance(data) {
                const root = document.documentElement;
                const theme = data.setTheme || 'cream';
                const accent = data.setAccent || '#5b5fd4';
                const sidebar = data.setSidebar || 'dark';
                const compact = data.toggleCompact === '1' || data.toggleCompact === true;
                const animsOn = data.toggleAnimations !== '0' && data.toggleAnimations !== false;

                const effectiveTheme = theme === 'auto'
                    ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                    : theme;

                root.style.setProperty('--primary', accent);
                root.style.setProperty('--primary-soft', accent + '18');
                root.style.setProperty('--sidebar-active', accent + '26');

                if (effectiveTheme === 'dark') {
                    root.style.setProperty('--bg', '#151821'); root.style.setProperty('--bg-soft', '#1c202b');
                    root.style.setProperty('--card', '#222735'); root.style.setProperty('--card-hover', '#292f3e');
                    root.style.setProperty('--border', '#343b4d'); root.style.setProperty('--border-light', '#2d3444');
                    root.style.setProperty('--text', '#d5d9e2'); root.style.setProperty('--text-bright', '#ffffff');
                    root.style.setProperty('--text-muted', '#a0a8ba'); root.style.setProperty('--text-dim', '#777f91');
                } else if (effectiveTheme === 'light') {
                    root.style.setProperty('--bg', '#f5f7fb'); root.style.setProperty('--bg-soft', '#ffffff');
                    root.style.setProperty('--card', '#ffffff'); root.style.setProperty('--card-hover', '#f8fafc');
                    root.style.setProperty('--border', '#e2e8f0'); root.style.setProperty('--border-light', '#edf1f6');
                    root.style.setProperty('--text', '#475569'); root.style.setProperty('--text-bright', '#172033');
                    root.style.setProperty('--text-muted', '#64748b'); root.style.setProperty('--text-dim', '#94a3b8');
                } else {
                    root.style.setProperty('--bg', '#f2ede4'); root.style.setProperty('--bg-soft', '#faf7f2');
                    root.style.setProperty('--card', '#ffffff'); root.style.setProperty('--card-hover', '#faf8f4');
                    root.style.setProperty('--border', '#e5ddd0'); root.style.setProperty('--border-light', '#ede7db');
                    root.style.setProperty('--text', '#5a5245'); root.style.setProperty('--text-bright', '#2b2620');
                    root.style.setProperty('--text-muted', '#8a8172'); root.style.setProperty('--text-dim', '#b3a998');
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

                document.body.classList.toggle('compact-mode', compact);
                document.body.classList.toggle('no-animations', !animsOn);
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

            // Appearance auto-save
            appearanceIds.forEach(id => {
                if (controls[id]) controls[id].addEventListener('change', async () => {
                    const payload = {};
                    appearanceIds.forEach(k => {
                        const el = controls[k];
                        if (el) payload[k] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
                    });
                    applyAppearance(Object.assign({}, SERVER_PREFS, payload));
                    const res = await syncServerPrefs('save_appearance', payload);
                    if (res.success) {
                        Object.keys(payload).forEach(k => { SERVER_PREFS[k] = payload[k]; });
                        showToast('Appearance saved — synced across all devices.');
                    } else {
                        showToast('Could not save appearance.', 'error');
                    }
                });
            });

            // Notification filter prefs auto-save
            notifPrefIds.forEach(id => {
                if (controls[id]) controls[id].addEventListener('change', async () => {
                    const payload = {};
                    notifPrefIds.forEach(k => {
                        const el = controls[k];
                        if (el) payload[k] = el.checked ? '1' : '0';
                    });
                    const res = await syncServerPrefs('save_prefs', payload);
                    if (res.success) {
                        Object.keys(payload).forEach(k => { SERVER_PREFS[k] = payload[k]; });
                        window.dispatchEvent(new Event('rjewel:settings-changed'));
                        showToast('Notification preferences saved — synced across all devices.');
                    } else {
                        showToast('Could not save notification preferences.', 'error');
                    }
                });
            });

            // Security toggles auto-save
            ['toggle2FA','toggleLoginAlerts'].forEach(id => {
                if (controls[id]) controls[id].addEventListener('change', async () => {
                    const payload = {
                        toggle2FA:         controls.toggle2FA?.checked         ? '1' : '0',
                        toggleLoginAlerts: controls.toggleLoginAlerts?.checked ? '1' : '0'
                    };
                    const res = await syncServerPrefs('save_security', payload);
                    if (res.success) showToast('Security setting saved — synced across all devices.');
                    else showToast('Could not save security setting.', 'error');
                });
            });

            // Save buttons
            document.querySelectorAll('[data-save]').forEach(button => button.addEventListener('click', async function () {
                const section = this.dataset.save;

                if (section === 'account') {
                    const fullName = (controls.setFullName?.value || '').trim();
                    const username = (controls.setUsername?.value || '').trim();
                    const email    = (controls.setEmail?.value    || '').trim();
                    const phone    = (controls.setPhone?.value    || '').trim();

                    if (!fullName) return showToast('Full name is required.', 'error');
                    if (!username) return showToast('Username is required.', 'error');
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return showToast('Enter a valid email address.', 'error');

                    const res = await syncServerPrefs('save_account', {
                        setFullName: fullName, setUsername: username, setEmail: email, setPhone: phone
                    });
                    if (!res.success) return showToast(res.error || 'Could not save account.', 'error');

                    SERVER_ADMIN.full_name = fullName;
                    SERVER_ADMIN.username  = username;
                    SERVER_ADMIN.email     = email;
                    SERVER_ADMIN.phone     = phone;
                    const headerName = document.getElementById('headerAdminName');
                    if (headerName) headerName.textContent = fullName;
                    showToast('Account updated — synced across all devices.');
                    return;
                }

                if (section === 'security') {
                    const current = controls.setCurrentPass?.value || '';
                    const next    = controls.setNewPass?.value     || '';
                    const confirm = controls.setConfirmPass?.value || '';

                    if (current || next || confirm) {
                        if (!current) return showToast('Enter your current password first.', 'error');
                        if (next.length < 8 || !/[A-Za-z]/.test(next) || !/\d/.test(next))
                            return showToast('New password must be 8+ characters with letters and numbers.', 'error');
                        if (next !== confirm) return showToast('New password and confirmation do not match.', 'error');

                        const res = await syncServerPrefs('save_password', {
                            setCurrentPass: current, setNewPass: next, setConfirmPass: confirm
                        });
                        if (!res.success) return showToast(res.error || 'Could not update password.', 'error');
                        passwordIds.forEach(id => { if (controls[id]) controls[id].value = ''; });
                        showToast('Password updated — synced across all devices.');
                    }

                    const secRes = await syncServerPrefs('save_security', {
                        toggle2FA:         controls.toggle2FA?.checked         ? '1' : '0',
                        toggleLoginAlerts: controls.toggleLoginAlerts?.checked ? '1' : '0'
                    });
                    if (secRes.success && !current && !next && !confirm) {
                        showToast('Security preferences saved — synced across all devices.');
                    }
                    return;
                }

                if (section === 'notifications') {
                    const payload = {};
                    notifPrefIds.forEach(k => {
                        const el = controls[k];
                        if (el) payload[k] = el.checked ? '1' : '0';
                    });
                    const res = await syncServerPrefs('save_prefs', payload);
                    if (!res.success) return showToast(res.error || 'Could not save notification prefs.', 'error');
                    Object.keys(payload).forEach(k => { SERVER_PREFS[k] = payload[k]; });
                    window.dispatchEvent(new Event('rjewel:settings-changed'));
                    showToast('Notification preferences saved — synced across all devices.');
                    return;
                }

                if (section === 'appearance') {
                    const payload = {};
                    appearanceIds.forEach(k => {
                        const el = controls[k];
                        if (el) payload[k] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
                    });
                    applyAppearance(Object.assign({}, SERVER_PREFS, payload));
                    const res = await syncServerPrefs('save_appearance', payload);
                    if (!res.success) return showToast(res.error || 'Could not save appearance.', 'error');
                    Object.keys(payload).forEach(k => { SERVER_PREFS[k] = payload[k]; });
                    showToast('Appearance saved — synced across all devices.');
                    return;
                }
            }));

            // Reset buttons
            document.querySelectorAll('[data-reset]').forEach(button => button.addEventListener('click', function () {
                const section = this.dataset.reset;
                const groups = {
                    account: ['setFullName','setUsername','setEmail','setPhone'],
                    security: ['toggle2FA','toggleLoginAlerts'],
                    notifications: notifPrefIds,
                    appearance: appearanceIds
                };
                (groups[section] || []).forEach(id => {
                    const el = controls[id];
                    if (!el) return;
                    if (id === 'setFullName') el.value = SERVER_ADMIN.full_name || '';
                    else if (id === 'setUsername') el.value = SERVER_ADMIN.username || '';
                    else if (id === 'setEmail') el.value = SERVER_ADMIN.email || '';
                    else if (id === 'setPhone') el.value = SERVER_ADMIN.phone || '';
                    else if (id === 'toggle2FA') el.checked = !!Number(SERVER_ADMIN.two_factor_enabled);
                    else if (id === 'toggleLoginAlerts') el.checked = !!Number(SERVER_ADMIN.login_alerts);
                    else if (notifPrefIds.includes(id) || appearanceIds.includes(id)) {
                        if (el.type === 'checkbox') el.checked = SERVER_PREFS[id] === '1';
                        else el.value = SERVER_PREFS[id] || el.value;
                    }
                });
                passwordIds.forEach(id => { if (controls[id]) controls[id].value = ''; });
                applyAppearance(SERVER_PREFS);
                showToast('Unsaved changes discarded.');
            }));

            document.querySelectorAll('#settingsPage [data-close]').forEach(button => button.addEventListener('click', function () {
                settingsPage.classList.remove('active');
                document.body.style.overflow = '';
            }));
        })();

        /* ========== PRODUCTS TABLE ========== */
        (function () {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.product-checkbox');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    checkboxes.forEach(cb => cb.checked = this.checked);
                });
            }
            const productImage = document.getElementById('productImage');
            if (productImage) {
                productImage.addEventListener('change', function () {
                    const file = this.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function (e) {
                            document.getElementById('imagePreview').innerHTML =
                                '<img src="' + e.target.result + '" class="image-preview">';
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }

            // ADD PRODUCT: Derive Shop Inventory (Released) = Total - Vault
            const addStock = document.getElementById('addStockInput');
            const addUnreleased = document.getElementById('addUnreleasedInput');
            const autoReleasedDisplay = document.getElementById('autoReleasedDisplay');
            const autoReleasedHidden = document.getElementById('autoReleasedHidden');

            function updateAddCalc() {
                if (!addStock || !addUnreleased || !autoReleasedDisplay) return;
                const total = parseInt(addStock.value, 10) || 0;
                let unreleased = parseInt(addUnreleased.value, 10) || 0;

                if (unreleased > total) {
                    unreleased = total;
                    addUnreleased.value = total;
                }

                const released = Math.max(0, total - unreleased);
                autoReleasedDisplay.value = released;
                if (autoReleasedHidden) autoReleasedHidden.value = released;
            }
            if (addStock) addStock.addEventListener('input', updateAddCalc);
            if (addUnreleased) addUnreleased.addEventListener('input', updateAddCalc);
            updateAddCalc();

            const flash = document.getElementById('flashMessage');
            if (flash) setTimeout(() => flash.remove(), 3500);
            const flashErr = document.getElementById('flashError');
            if (flashErr) setTimeout(() => flashErr.remove(), 3500);

            // Focus-row highlight from ?focus=<id> (low-stock notification link)
            const focusRow = document.getElementById('focusRow');
            if (focusRow) {
                setTimeout(() => {
                    focusRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 350);
            }
        })();

        /* ========== QUICK STOCK MODAL ========== */
        let stockModalInstance = null;
        function smNum(id) { const v = parseInt(document.getElementById(id).value, 10); return isNaN(v) || v < 0 ? 0 : v; }
        function smSet(id, v) { v = Math.max(0, parseInt(v, 10) || 0); document.getElementById(id).value = v; return v; }

        function updateStockPills(total, unreleased, released) {
            document.getElementById('smPillTotal').textContent = total;
            document.getElementById('smPillUnreleased').textContent = unreleased;
            document.getElementById('smPillReleased').textContent = released;
        }

        function recalcStockModal() {
            let total = smNum('smMonthly');
            let unreleased = smNum('smUnreleased');

            if (unreleased > total) {
                unreleased = total;
                smSet('smUnreleased', unreleased);
            }

            const released = Math.max(0, total - unreleased);
            smSet('smReleased', released);

            updateStockPills(total, unreleased, released);
        }

        function openStockModal(product) {
            document.getElementById('smProductId').value = product.id;
            document.getElementById('smProductName').value = product.name;

            document.getElementById('smMonthly').value    = product.stock;
            document.getElementById('smUnreleased').value = product.unreleased;

            recalcStockModal();

            if (!stockModalInstance) stockModalInstance = new bootstrap.Modal(document.getElementById('stockModal'));
            stockModalInstance.show();
        }

        document.getElementById('smMonthly').addEventListener('input', recalcStockModal);
        document.getElementById('smUnreleased').addEventListener('input', recalcStockModal);

        /* ========== EDIT PRODUCT ========== */
        function editProduct(id) {
            fetch('get-product.php?id=' + encodeURIComponent(id))
                .then(r => r.text())
                .then(html => {
                    document.getElementById('editProductContent').innerHTML = html;
                    const modal = new bootstrap.Modal(document.getElementById('editProductModal'));
                    modal.show();
                })
                .catch(() => alert('Could not load product.'));
        }
    </script>
</body>
</html>