<?php
// user/wishlist.php — JSON endpoint for wishlist toggle / list / remove / ids
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function wl_db() {
    try {
        $database = new Database();
        return $database->getConnection();
    } catch (Throwable $e) {
        return null;
    }
}

$db  = wl_db();
$uid = (int)($_SESSION['user_id'] ?? 0);

if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database unavailable']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---------------- TOGGLE ---------------- */
if ($action === 'toggle') {
    if ($uid <= 0) {
        echo json_encode([
            'success'    => false,
            'need_login' => true,
            'message'    => 'Please sign in to save items to your wishlist.'
        ]);
        exit;
    }

    $pid = (int)($_POST['product_id'] ?? 0);
    if ($pid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product.']);
        exit;
    }

    try {
        $chk = $db->prepare("SELECT id FROM products WHERE id = ?");
        $chk->execute([$pid]);
        if (!$chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Product not found.']);
            exit;
        }

        $look = $db->prepare("SELECT id FROM wishlist WHERE user_id = ? AND product_id = ?");
        $look->execute([$uid, $pid]);
        $existing = $look->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $db->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?")
               ->execute([$uid, $pid]);
            $in = false;
        } else {
            $db->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)")
               ->execute([$uid, $pid]);
            $in = true;
        }

        $cnt = $db->prepare("SELECT COUNT(*) AS c FROM wishlist WHERE user_id = ?");
        $cnt->execute([$uid]);
        $total = (int)($cnt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        echo json_encode([
            'success'     => true,
            'in_wishlist' => $in,
            'count'       => $total,
            'message'     => $in ? 'Added to wishlist.' : 'Removed from wishlist.'
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ---------------- REMOVE ---------------- */
if ($action === 'remove') {
    if ($uid <= 0) {
        echo json_encode(['success' => false, 'need_login' => true]);
        exit;
    }
    $pid = (int)($_POST['product_id'] ?? 0);
    if ($pid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product.']);
        exit;
    }
    try {
        $db->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?")
           ->execute([$uid, $pid]);
        $cnt = $db->prepare("SELECT COUNT(*) AS c FROM wishlist WHERE user_id = ?");
        $cnt->execute([$uid]);
        echo json_encode([
            'success' => true,
            'count'   => (int)($cnt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0),
            'message' => 'Removed.'
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ---------------- LIST ---------------- */
if ($action === 'list') {
    if ($uid <= 0) {
        echo json_encode(['success' => false, 'need_login' => true, 'items' => []]);
        exit;
    }
    try {
        $stmt = $db->prepare("
            SELECT w.product_id, w.created_at,
                   p.name, p.price, p.image, p.stock,
                   c.name AS category_name
            FROM wishlist w
            JOIN products p ON p.id = w.product_id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE w.user_id = ?
            ORDER BY w.created_at DESC
        ");
        $stmt->execute([$uid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'items' => $rows]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'items' => [], 'message' => $e->getMessage()]);
    }
    exit;
}

/* ---------------- IDS ---------------- */
if ($action === 'ids') {
    if ($uid <= 0) {
        echo json_encode(['success' => true, 'ids' => []]);
        exit;
    }
    try {
        $stmt = $db->prepare("SELECT product_id FROM wishlist WHERE user_id = ?");
        $stmt->execute([$uid]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        echo json_encode(['success' => true, 'ids' => $ids]);
    } catch (Throwable $e) {
        echo json_encode(['success' => true, 'ids' => []]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);