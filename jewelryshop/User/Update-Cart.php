<?php
// user/update-cart.php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit();
}

$cart_id = $_POST['cart_id'] ?? null;
$quantity = $_POST['quantity'] ?? null;

if(!$cart_id || !$quantity) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Check if cart item belongs to user
$check_query = "SELECT c.*, p.stock FROM cart c 
                JOIN products p ON c.product_id = p.id 
                WHERE c.id = :cart_id AND c.user_id = :user_id";
$check_stmt = $db->prepare($check_query);
$check_stmt->bindParam(':cart_id', $cart_id);
$check_stmt->bindParam(':user_id', $_SESSION['user_id']);
$check_stmt->execute();

if($check_stmt->rowCount() == 0) {
    echo json_encode(['success' => false, 'message' => 'Cart item not found']);
    exit();
}

$cart_item = $check_stmt->fetch(PDO::FETCH_ASSOC);

if($quantity > $cart_item['stock']) {
    echo json_encode(['success' => false, 'message' => 'Not enough stock']);
    exit();
}

// Update quantity
$query = "UPDATE cart SET quantity = :quantity WHERE id = :cart_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':quantity', $quantity);
$stmt->bindParam(':cart_id', $cart_id);

if($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error updating cart']);
}
?>