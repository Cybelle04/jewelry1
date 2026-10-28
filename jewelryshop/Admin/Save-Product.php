<?php
// admin/save-product.php — saves products with karat, weight, released_stock
session_start();
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

if($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: products.php");
    exit();
}

// Auto-migrate new columns if missing
try { $db->query("SELECT released_stock FROM products LIMIT 1"); }
catch (Throwable $e) { try { $db->exec("ALTER TABLE products ADD COLUMN released_stock INT DEFAULT 0"); } catch (Throwable $e2) {} }
try { $db->query("SELECT karat FROM products LIMIT 1"); }
catch (Throwable $e) { try { $db->exec("ALTER TABLE products ADD COLUMN karat VARCHAR(20) DEFAULT NULL"); } catch (Throwable $e2) {} }
try { $db->query("SELECT weight FROM products LIMIT 1"); }
catch (Throwable $e) { try { $db->exec("ALTER TABLE products ADD COLUMN weight DECIMAL(10,2) DEFAULT NULL"); } catch (Throwable $e2) {} }

$name         = trim($_POST['name'] ?? '');
$description  = trim($_POST['description'] ?? '');
$price        = $_POST['price'] ?? 0;
$category_id  = $_POST['category_id'] ?? null;
$stock        = max(0, (int)($_POST['stock'] ?? 0));
$released     = max(0, (int)($_POST['released_stock'] ?? 0));
$featured     = isset($_POST['featured']) ? 1 : 0;

$karat  = trim($_POST['karat'] ?? '');
$karat  = $karat === '' ? null : $karat;

$weightRaw = $_POST['weight'] ?? '';
$weight    = ($weightRaw === '' || $weightRaw === null) ? null : (float)$weightRaw;
if ($weight !== null && $weight < 0) $weight = null;

if ($released > $stock) $released = $stock;

$image = '';
if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $filename = $_FILES['image']['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $file_size = $_FILES['image']['size'];

    if(in_array($ext, $allowed)) {
        if($file_size <= 2097152) {
            $image = time() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $filename);
            $upload_path = "../uploads/" . $image;
            if(!move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) $image = '';
        } else {
            $_SESSION['error'] = "Image size must be less than 2MB";
            header("Location: products.php");
            exit();
        }
    } else {
        $_SESSION['error'] = "Invalid image format. Allowed: jpg, jpeg, png, gif, webp";
        header("Location: products.php");
        exit();
    }
}

$query = "INSERT INTO products
            (name, description, price, category_id, stock, released_stock, karat, weight, image, featured, created_at)
          VALUES
            (:name, :description, :price, :category_id, :stock, :released_stock, :karat, :weight, :image, :featured, NOW())";

$stmt = $db->prepare($query);
$stmt->bindParam(':name', $name);
$stmt->bindParam(':description', $description);
$stmt->bindParam(':price', $price);
$stmt->bindParam(':category_id', $category_id);
$stmt->bindParam(':stock', $stock, PDO::PARAM_INT);
$stmt->bindParam(':released_stock', $released, PDO::PARAM_INT);
$stmt->bindValue(':karat', $karat, $karat === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
$stmt->bindValue(':weight', $weight, $weight === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
$stmt->bindParam(':image', $image);
$stmt->bindParam(':featured', $featured, PDO::PARAM_INT);

if($stmt->execute()) {
    $_SESSION['message'] = "Product added successfully! ({$stock} total, {$released} released, " . max(0, $stock - $released) . " unreleased)";
} else {
    $_SESSION['error'] = "Error adding product";
}
header("Location: products.php");
exit();
?>