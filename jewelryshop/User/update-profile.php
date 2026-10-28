<?php
// user/update-profile.php
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid session.']);
    exit;
}

$user = $auth->getCurrentUser();
if (!is_array($user) || empty($user)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

$full_name        = trim($_POST['full_name'] ?? '');
$email            = trim($_POST['email'] ?? '');
$phone            = trim($_POST['phone'] ?? '');
$address          = trim($_POST['address'] ?? '');
$current_password = $_POST['current_password'] ?? '';
$new_password     = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if ($full_name === '' || $email === '') {
    echo json_encode(['success' => false, 'message' => 'Full name and email are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

try {
    // Check email uniqueness (excluding self)
    $stmt = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :uid LIMIT 1");
    $stmt->execute([':email' => $email, ':uid' => $uid]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'That email is already in use.']);
        exit;
    }

    // Password change requested?
    if ($new_password !== '' || $confirm_password !== '' || $current_password !== '') {
        if ($new_password === '' || $confirm_password === '' || $current_password === '') {
            echo json_encode(['success' => false, 'message' => 'All password fields are required to change password.']);
            exit;
        }
        if ($new_password !== $confirm_password) {
            echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
            exit;
        }
        if (strlen($new_password) < 6) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
            exit;
        }

        $stmt = $db->prepare("SELECT password FROM users WHERE id = :uid LIMIT 1");
        $stmt->execute([':uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $stored = $row['password'] ?? '';
        $verified = false;

        if ($stored && password_verify($current_password, $stored)) {
            $verified = true;
        } elseif ($stored && hash_equals($stored, $current_password)) {
            $verified = true; // legacy plaintext
        }

        if (!$verified) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }

        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET full_name = :full_name, email = :email, phone = :phone, address = :address, password = :password WHERE id = :uid");
        $stmt->execute([
            ':full_name' => $full_name,
            ':email'     => $email,
            ':phone'     => $phone,
            ':address'   => $address,
            ':password'  => $hash,
            ':uid'       => $uid,
        ]);
    } else {
        $stmt = $db->prepare("UPDATE users SET full_name = :full_name, email = :email, phone = :phone, address = :address WHERE id = :uid");
        $stmt->execute([
            ':full_name' => $full_name,
            ':email'     => $email,
            ':phone'     => $phone,
            ':address'   => $address,
            ':uid'       => $uid,
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Profile updated.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}