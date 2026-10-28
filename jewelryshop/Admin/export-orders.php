<?php
// admin/export-orders.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';

// Check if user is logged in as admin
if(!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// ---------- Get filters ----------
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_filter   = isset($_GET['date'])   ? $_GET['date']   : '';
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';

// Whitelist status values
$allowed_statuses = ['all', 'pending', 'processing', 'shipped', 'delivered', 'cancelled'];
if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'all';
}

// Validate date format (YYYY-MM-DD) if provided
if ($date_filter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filter)) {
    $date_filter = '';
}

// ---------- Detect optional columns so we don't warn on missing ones ----------
function columnExists(PDO $db, string $table, string $column): bool {
    try {
        $cols = $db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            if (strcasecmp($c['Field'], $column) === 0) return true;
        }
    } catch (Throwable $e) {}
    return false;
}

$has_shipping_address = columnExists($db, 'orders', 'shipping_address');
$has_payment_method   = columnExists($db, 'orders', 'payment_method');
$has_phone            = columnExists($db, 'users',  'phone');
$has_address          = columnExists($db, 'users',  'address');

// ---------- Build query with filters ----------
$where_conditions = ["1=1"];
$params = [];

if($status_filter !== 'all') {
    $where_conditions[] = "o.status = :status";
    $params[':status'] = $status_filter;
}

if($date_filter !== '') {
    $where_conditions[] = "DATE(o.created_at) = :date";
    $params[':date'] = $date_filter;
}

if($search !== '') {
    $where_conditions[] = "(o.id LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search)";
    $params[':search'] = "%$search%";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Build SELECT list with conditional columns
$select  = "SELECT o.*, u.full_name, u.email";
$select .= $has_phone            ? ", u.phone AS user_phone"     : ", '' AS user_phone";
$select .= $has_address          ? ", u.address AS user_address" : ", '' AS user_address";
$select .= $has_shipping_address ? ", o.shipping_address"        : ", '' AS shipping_address";
$select .= $has_payment_method   ? ", o.payment_method"          : ", '' AS payment_method";
$select .= ", (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count";
$select .= " FROM orders o JOIN users u ON o.user_id = u.id";
$select .= " $where_clause ORDER BY o.created_at DESC";

$stmt = $db->prepare($select);
foreach($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- Send headers ----------
$filename = 'orders_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// ---------- Write CSV ----------
$output = fopen('php://output', 'w');

// UTF-8 BOM so Excel renders ₱ and other non-ASCII correctly
fwrite($output, "\xEF\xBB\xBF");

// Column headers
fputcsv($output, [
    'Order ID',
    'Customer Name',
    'Email',
    'Phone',
    'Items Count',
    'Total Amount',
    'Status',
    'Shipping Address',
    'Payment Method',
    'Order Date',
]);

// Helper: prefer orders.shipping_address, fall back to users.address, else blank
function resolveShippingAddress(array $order): string {
    $ship = trim((string)($order['shipping_address'] ?? ''));
    if ($ship !== '') return $ship;
    return trim((string)($order['user_address'] ?? ''));
}

// Helper: prettify payment method or blank
function resolvePaymentMethod(array $order): string {
    $pm = trim((string)($order['payment_method'] ?? ''));
    if ($pm === '') return '';
    return ucfirst(str_replace('_', ' ', $pm));
}

// Data rows
foreach($orders as $order) {
    fputcsv($output, [
        '#' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT),
        $order['full_name'] ?? '',
        $order['email'] ?? '',
        $order['user_phone'] ?? '',
        (int)($order['item_count'] ?? 0),
        '₱' . number_format((float)($order['total_amount'] ?? 0), 2),
        ucfirst((string)($order['status'] ?? '')),
        resolveShippingAddress($order),
        resolvePaymentMethod($order),
        $order['created_at'] ? date('Y-m-d H:i:s', strtotime($order['created_at'])) : '',
    ]);
}

fclose($output);
exit();