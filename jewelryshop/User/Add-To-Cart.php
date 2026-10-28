<?php
// user/add-to-cart.php
// Adds a product to the cart and returns the fresh cart count.
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first', 'count' => 0]);
    exit();
}

$product_id = $_POST['product_id'] ?? null;
$quantity   = max(1, (int)($_POST['quantity'] ?? 1));
$user_id    = (int)$_SESSION['user_id'];

if (!$product_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid product', 'count' => 0]);
    exit();
}

try {
    $database = new Database();
    $db       = $database->getConnection();

    if (!$db) {
        echo json_encode(['success' => false, 'message' => 'Database unavailable', 'count' => 0]);
        exit();
    }

    /* ---------------- Check product stock ---------------- */
    $check_stmt = $db->prepare("SELECT stock FROM products WHERE id = :product_id");
    $check_stmt->bindParam(':product_id', $product_id);
    $check_stmt->execute();
    $product = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found', 'count' => 0]);
        exit();
    }

    /* ---------------- Check if already in cart ---------------- */
    $stmt = $db->prepare("SELECT * FROM cart WHERE user_id = :user_id AND product_id = :product_id");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':product_id', $product_id);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $cart_item    = $stmt->fetch(PDO::FETCH_ASSOC);
        $new_quantity = (int)$cart_item['quantity'] + $quantity;

        if ($new_quantity > (int)$product['stock']) {
            echo json_encode(['success' => false, 'message' => 'Not enough stock', 'count' => 0]);
            exit();
        }

        $upd = $db->prepare("UPDATE cart SET quantity = :quantity WHERE user_id = :user_id AND product_id = :product_id");
        $upd->bindParam(':quantity', $new_quantity);
        $upd->bindParam(':user_id', $user_id);
        $upd->bindParam(':product_id', $product_id);
        $ok = $upd->execute();
    } else {
        if ($quantity > (int)$product['stock']) {
            echo json_encode(['success' => false, 'message' => 'Not enough stock', 'count' => 0]);
            exit();
        }

        $ins = $db->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (:user_id, :product_id, :quantity)");
        $ins->bindParam(':user_id', $user_id);
        $ins->bindParam(':product_id', $product_id);
        $ins->bindParam(':quantity', $quantity);
        $ok = $ins->execute();
    }

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Error adding to cart', 'count' => 0]);
        exit();
    }

    /* ---------------- Return fresh cart total ---------------- */
    $total_stmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) AS total FROM cart WHERE user_id = :uid");
    $total_stmt->bindParam(':uid', $user_id, PDO::PARAM_INT);
    $total_stmt->execute();
    $new_count = (int)($total_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    echo json_encode([
        'success' => true,
        'message' => 'Product added to cart',
        'count'   => $new_count
    ]);

} catch (Throwable $e) {
    error_log('[add-to-cart.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error', 'count' => 0]);
}