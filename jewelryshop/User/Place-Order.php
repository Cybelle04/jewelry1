<?php
// user/place-order.php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: checkout.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

/* ------------------------------------------------------------------
   Validate inputs
   ------------------------------------------------------------------ */
$address        = trim($_POST['address'] ?? '');
$payment_method = trim($_POST['payment_method'] ?? '');
$notes          = trim($_POST['notes'] ?? '');

if ($address === '') {
    $_SESSION['error'] = "Shipping address is required.";
    header("Location: checkout.php");
    exit();
}

$allowed_methods = ['credit_card', 'paypal', 'bank_transfer'];
if (!in_array($payment_method, $allowed_methods, true)) {
    $payment_method = 'credit_card';
}

/* ------------------------------------------------------------------
   Fetch cart
   ------------------------------------------------------------------ */
$cart_stmt = $db->prepare("
    SELECT c.*, p.price, p.stock, p.name
    FROM cart c
    JOIN products p ON c.product_id = p.id
    WHERE c.user_id = :user_id
");
$cart_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$cart_stmt->execute();
$cart_items = $cart_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($cart_items)) {
    header("Location: cart.php");
    exit();
}

/* ------------------------------------------------------------------
   Totals (must match checkout.php)
   ------------------------------------------------------------------ */
$subtotal = 0;
foreach ($cart_items as $item) {
    $subtotal += (float)$item['price'] * (int)$item['quantity'];
}
$shipping = ($subtotal >= 100) ? 0 : 10;
$tax      = $subtotal * 0.10;
$total    = $subtotal + $shipping + $tax;

/* ------------------------------------------------------------------
   Create order
   ------------------------------------------------------------------ */
$db->beginTransaction();

try {
    /* -- Stock check first (fail early) -- */
    foreach ($cart_items as $item) {
        if ((int)$item['quantity'] > (int)$item['stock']) {
            throw new Exception("Not enough stock for: " . $item['name']);
        }
    }

    /* -- Insert order -- */
    $order_stmt = $db->prepare("
        INSERT INTO orders (user_id, total_amount, shipping_address, payment_method, status)
        VALUES (:user_id, :total, :address, :payment_method, 'pending')
    ");
    $order_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $order_stmt->bindParam(':total', $total);
    $order_stmt->bindParam(':address', $address);
    $order_stmt->bindParam(':payment_method', $payment_method);
    $order_stmt->execute();

    $order_id = (int)$db->lastInsertId();

    /* -- Insert order items + decrement stock -- */
    $item_stmt  = $db->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, price)
        VALUES (:order_id, :product_id, :quantity, :price)
    ");
    $stock_stmt = $db->prepare("
        UPDATE products SET stock = stock - :quantity WHERE id = :product_id
    ");

    foreach ($cart_items as $item) {
        $item_stmt->execute([
            ':order_id'   => $order_id,
            ':product_id' => (int)$item['product_id'],
            ':quantity'   => (int)$item['quantity'],
            ':price'      => (float)$item['price'],
        ]);

        $stock_stmt->execute([
            ':quantity'   => (int)$item['quantity'],
            ':product_id' => (int)$item['product_id'],
        ]);
    }

    /* -- Clear cart -- */
    $clear_stmt = $db->prepare("DELETE FROM cart WHERE user_id = :user_id");
    $clear_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $clear_stmt->execute();

    /* ------------------------------------------------------------------
       🔔 Thank-you notification + message (inside transaction = atomic)
       ------------------------------------------------------------------
       Requires a `notifications` table like:
         id, user_id, type, title, message, link, is_read, created_at
    ------------------------------------------------------------------ */
    try {
        $order_number = 'ORD-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);

        /* -- Bell notification -- */
        $notif_stmt = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
            VALUES (:uid, 'order', :title, :message, :link, 0, NOW())
        ");
        $notif_stmt->execute([
            ':uid'     => $user_id,
            ':title'   => 'Order Placed Successfully! 🎉',
            ':message' => "Thank you for your purchase! Your order {$order_number} totalling ₱" .
                          number_format($total, 2) . " has been received and is now being processed.",
            ':link'    => 'order-success.php?id=' . $order_id,
        ]);

        /* -- In-app message -- */
        $msg_stmt = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
            VALUES (:uid, 'system', :title, :message, :link, 0, NOW())
        ");
        $msg_stmt->execute([
            ':uid'     => $user_id,
            ':title'   => 'Order Confirmation ' . $order_number,
            ':message' => "Thank you for your purchase! We've received your order and will notify you when it ships. " .
                          "A confirmation email has also been sent.",
            ':link'    => 'order-success.php?id=' . $order_id,
        ]);
    } catch (Throwable $notifyErr) {
        /* Never let notification failure roll back a valid order.
           Log it and continue. */
        error_log('Notification insert failed: ' . $notifyErr->getMessage());
    }

    $db->commit();

    $_SESSION['last_order_id'] = $order_id;
    header("Location: order-success.php?id=" . $order_id);
    exit();

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['error'] = "Order failed: " . $e->getMessage();
    header("Location: checkout.php");
    exit();
}