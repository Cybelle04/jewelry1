<?php
// admin/update-category.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) session_start();
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

$id          = (int)($_POST['id'] ?? 0);
$name        = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($id <= 0 || $name === '') {
    $_SESSION['error'] = 'Invalid request.';
    header("Location: categories.php");
    exit();
}

// Fetch existing
$stmt = $db->prepare("SELECT image FROM categories WHERE id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    $_SESSION['error'] = 'Category not found.';
    header("Location: categories.php");
    exit();
}

$newImage = $existing['image'];

// Handle new image upload (if any)
if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/../uploads/categories/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array(mime_content_type($_FILES['image']['tmp_name']), $allowed, true)) {
        $_SESSION['error'] = 'Invalid image type.';
        header("Location: categories.php");
        exit();
    }
    if ($_FILES['image']['size'] > 2 * 1024 * 1024) {
        $_SESSION['error'] = 'Image too large (max 2MB).';
        header("Location: categories.php");
        exit();
    }

    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $newImage = 'cat_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $newImage)) {
        $_SESSION['error'] = 'Could not save image.';
        header("Location: categories.php");
        exit();
    }

    // Delete old image
    if ($existing['image'] && file_exists(__DIR__ . '/../uploads/categories/' . $existing['image'])) {
        @unlink(__DIR__ . '/../uploads/categories/' . $existing['image']);
    }
}

// Update
$upd = $db->prepare("UPDATE categories SET name = :name, description = :desc, image = :img WHERE id = :id");
$upd->bindValue(':name', $name);
$upd->bindValue(':desc', $description);
$upd->bindValue(':img', $newImage);
$upd->bindValue(':id', $id, PDO::PARAM_INT);
$upd->execute();

$_SESSION['message'] = 'Category updated successfully!';
header("Location: categories.php");
exit();