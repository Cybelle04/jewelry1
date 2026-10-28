<?php
// user/auth-modal.php — AJAX handler for login + register modals

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';

$response = ['success' => false, 'message' => 'Unknown action'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    $auth = new Auth($db);

    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = 'user';

        if ($username === '' || $password === '') {
            $response = ['success' => false, 'message' => 'Please fill in all fields.'];
        } elseif ($auth->login($username, $password, $role)) {
            $response = ['success' => true, 'message' => 'Login successful', 'redirect' => 'index.php'];
        } else {
            $response = ['success' => false, 'message' => 'Invalid username or password.'];
        }
        echo json_encode($response);
        exit();
    }

    if ($action === 'register') {
        $data = [
            'username'  => trim($_POST['username'] ?? ''),
            'email'     => trim($_POST['email'] ?? ''),
            'password'  => $_POST['password'] ?? '',
            'full_name' => trim($_POST['full_name'] ?? ''),
            'phone'     => trim($_POST['phone'] ?? ''),
            'address'   => trim($_POST['address'] ?? ''),
        ];

        $errors = [];
        if (strlen($data['username']) < 3) $errors[] = 'Username must be at least 3 characters.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
        if (strlen($data['password']) < 6) $errors[] = 'Password must be at least 6 characters.';
        if ($data['full_name'] === '') $errors[] = 'Full name is required.';

        $check = $db->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
        $check->execute([':username' => $data['username'], ':email' => $data['email']]);
        if ($check->rowCount() > 0) $errors[] = 'Username or email already exists.';

        if (!empty($errors)) {
            $response = ['success' => false, 'message' => implode('<br>', $errors)];
        } elseif ($auth->register($data)) {
            $auth->login($data['username'], $data['password'], 'user');
            $response = ['success' => true, 'message' => 'Account created successfully!', 'redirect' => 'index.php'];
        } else {
            $response = ['success' => false, 'message' => 'Registration failed. Please try again.'];
        }

        echo json_encode($response);
        exit();
    }

    echo json_encode($response);
    exit();

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit();
}