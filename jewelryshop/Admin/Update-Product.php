<?php
// admin/update-product.php — updates product; preserves stock if not submitted
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

$id           = (int)($_POST['id'] ?? 0);
$name         = trim($_POST['name'] ?? '');
$description  = trim($_POST['description'] ?? '');
$price        = $_POST['price'] ?? 0;
$category_id  = $_POST['category_id'] ?? null;
$featured     = isset($_POST['featured']) ? 1 : 0;

$karat  = trim($_POST['karat'] ?? '');
$karat  = $karat === '' ? null : $karat;

$weightRaw = $_POST['weight'] ?? '';
$weight    = ($weightRaw === '' || $weightRaw === null) ? null : (float)$weightRaw;
if ($weight !== null && $weight < 0) $weight = null;

// ---- Preserve existing stock / image if the form doesn't send them ----
$existing = null;
try {
    $sel = $db->prepare("SELECT stock, released_stock, image FROM products WHERE id = :id");
    $sel->bindValue(':id', $id, PDO::PARAM_INT);
    $sel->execute();
    $existing = $sel->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $existing = null; }

// Total stock is NOT editable from the Edit modal anymore — always preserve
$stock = $existing ? (int)$existing['stock'] : 0;

// Released stock IS editable from the Edit modal
if (array_key_exists('released_stock', $_POST)) {
    $released = max(0, (int)$_POST['released_stock']);
} else {
    $released = $existing ? (int)$existing['released_stock'] : 0;
}

if ($released > $stock) $released = $stock;

// ---- Image handling ----
$image = $_POST['current_image'] ?? ($existing['image'] ?? '');
if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $filename = $_FILES['image']['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $file_size = $_FILES['image']['size'];

    if(in_array($ext, $allowed)) {
        if($file_size <= 2097152) {
            if($image && file_exists("../uploads/" . $image)) unlink("../uploads/" . $image);
            $image = time() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $filename);
            $upload_path = "../uploads/" . $image;
            move_uploaded_file($_FILES['image']['tmp_name'], $upload_path);
        } else {
            $_SESSION['error'] = "Image size must be less than 2MB";
            header("Location: products.php");
            exit();
        }
    } else {
        $_SESSION['error'] = "Invalid image format";
        header("Location: products.php");
        exit();
    }
}

$query = "UPDATE products SET
          name = :name,
          description = :description,
          price = :price,
          category_id = :category_id,
          stock = :stock,
          released_stock = :released_stock,
          karat = :karat,
          weight = :weight,
          image = :image,
          featured = :featured
          WHERE id = :id";

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
$stmt->bindParam(':id', $id, PDO::PARAM_INT);

if($stmt->execute()) {
    $_SESSION['message'] = "Product updated successfully!";
} else {
    $_SESSION['error'] = "Error updating product";
}
header("Location: products.php");
exit();
?>