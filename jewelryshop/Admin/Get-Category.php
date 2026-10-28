<?php
// admin/get-category.php - Returns HTML for the edit-category modal
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit();
}

require_once '../config/database.php';
$db = (new Database())->getConnection();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo 'Invalid ID'; exit(); }

$stmt = $db->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$cat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cat) { echo 'Category not found'; exit(); }

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
$hasImage = !empty($cat['image']) && file_exists(__DIR__ . '/../uploads/categories/' . $cat['image']);
?>
<form action="update-category.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">

    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Category Name *</label>
            <input type="text" name="name" class="form-control" required value="<?= h($cat['name']) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3"><?= h($cat['description']) ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Category Image</label>
            <div class="image-upload-box <?= $hasImage ? 'has-image' : '' ?>" id="editImageBox">
                <input type="file" name="image" accept="image/*"
                       onchange="previewImage(this, 'editImageBox', 'editImagePreview')">
                <div id="editImagePreview">
                    <?php if ($hasImage): ?>
                        <img src="../uploads/categories/<?= h($cat['image']) ?>" class="image-preview" alt="Current image">
                        <button type="button" class="image-preview-remove"
                                onclick="removeImage('editImageBox', 'editImagePreview')">
                            <i class="fas fa-times"></i> Remove image
                        </button>
                    <?php else: ?>
                        <div class="image-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="image-upload-text">Click to upload image</div>
                        <div class="image-upload-hint">JPG, PNG, WEBP · Max 2MB · Recommended 400×400</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn-save"><i class="fas fa-check"></i> Update Category</button>
    </div>
</form>