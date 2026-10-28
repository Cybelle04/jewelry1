<?php
// admin/update-order-status.php
session_start();
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

if($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: orders.php");
    exit();
}

$order_id = $_POST['order_id'];
$status = $_POST['status'];

$query = "UPDATE orders SET status = :status WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':status', $status);
$stmt->bindParam(':id', $order_id);

if($stmt->execute()) {
    $_SESSION['message'] = "Order #$order_id updated to " . ucfirst($status) . "!";
} else {
    $_SESSION['error'] = "Error updating order!";
}

header("Location: orders.php");
exit();
?>