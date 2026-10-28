<?php
// admin/save-category.php - Handles creating a new category with optional image
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once '../config/database.php';
$db = (new Database())->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: categories.php");
    exit();
}

$name        = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$imageName   = null;

if ($name === '') {
    $_SESSION['error'] = 'Category name is required.';
    header("Location: categories.php");
    exit();
}

// Handle image upload
if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/../uploads/categories/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime    = mime_content_type($_FILES['image']['tmp_name']);

    if (!in_array($mime, $allowed, true)) {
        $_SESSION['error'] = 'Invalid image type. Allowed: JPG, PNG, WEBP, GIF.';
        header("Location: categories.php");
        exit();
    }

    if ($_FILES['image']['size'] > 2 * 1024 * 1024) {
        $_SESSION['error'] = 'Image is too large. Max 2MB.';
        header("Location: categories.php");
        exit();
    }

    $ext      = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $imageName = 'cat_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName)) {
        $_SESSION['error'] = 'Could not save uploaded image.';
        header("Location: categories.php");
        exit();
    }
}

// Insert
$stmt = $db->prepare("
    INSERT INTO categories (name, description, image, created_at)
    VALUES (:name, :description, :image, NOW())
");
$stmt->bindValue(':name', $name);
$stmt->bindValue(':description', $description);
$stmt->bindValue(':image', $imageName);
$stmt->execute();

$_SESSION['message'] = 'Category created successfully!';
header("Location: categories.php");
exit();