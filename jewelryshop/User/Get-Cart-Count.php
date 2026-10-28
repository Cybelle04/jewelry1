<?php
// user/get-cart-count.php — returns JSON { count: int }
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function respond($count, $status = 200) {
    http_response_code($status);
    echo json_encode(['count' => (int)$count], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

try {
    $configPath = __DIR__ . '/../config/database.php';

    if (!file_exists($configPath)) {
        respond(0, 500);
    }

    require_once $configPath;

    if (!class_exists('Database')) {
        respond(0, 500);
    }

    $database = new Database();
    $db       = $database->getConnection();

    if (!$db) {
        respond(0, 500);
    }

    if (empty($_SESSION['user_id'])) {
        respond(0);
    }

    $uid = (int)$_SESSION['user_id'];

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(quantity), 0) AS total
        FROM cart
        WHERE user_id = :uid
    ");
    $stmt->bindParam(':uid', $uid, PDO::PARAM_INT);
    $stmt->execute();

    $row   = $stmt->fetch(PDO::FETCH_ASSOC);
    $count = (int)($row['total'] ?? 0);

    respond($count);

} catch (Throwable $e) {
    error_log('[get-cart-count.php] ' . $e->getMessage());
    respond(0, 500);
}