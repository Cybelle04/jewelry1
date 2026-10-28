<?php
// admin/get-product.php
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$id = $_GET['id'] ?? 0;

$query = "SELECT * FROM products WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$product) {
    echo "<div class='modal-body'><div class='text-danger'>Product not found</div></div>";
    exit();
}

$cat_query = "SELECT * FROM categories ORDER BY name";
$cat_stmt = $db->prepare($cat_query);
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStock    = (int)$product['stock'];
$releasedStock = (int)($product['released_stock'] ?? 0);
if ($releasedStock > $totalStock) $releasedStock = $totalStock;

$karat  = (string)($product['karat'] ?? '');
$weight = $product['weight'];
?>

<style>
/* -------- Modal shell (fits, no scroll) -------- */
.modal-content:has(.product-form) {
    background: var(--card, #ffffff);
    border: 1px solid var(--border, #e5ddd0);
    border-radius: 16px;
    box-shadow: 0 24px 60px -20px rgba(90, 82, 69, .35),
                0 1px 3px rgba(90, 82, 69, .05);
    overflow: hidden;
    font-family: 'Inter', sans-serif;
}
.modal-content:has(.product-form) .modal-header {
    background: var(--card, #ffffff);
    border-bottom: 1px solid var(--border, #e5ddd0);
    padding: 14px 22px;
}
.modal-content:has(.product-form) .modal-header h5 {
    color: var(--text-bright, #2b2620);
    font-weight: 700; font-size: .95rem;
    letter-spacing: -.01em; margin: 0;
    display: flex; align-items: center; gap: 10px;
}
.modal-content:has(.product-form) .modal-header h5 i {
    color: var(--gold, #b8941f); font-size: .9rem;
}
.modal-content:has(.product-form) .modal-header .btn-close {
    opacity: .5; transition: opacity .2s ease;
}
.modal-content:has(.product-form) .modal-header .btn-close:hover { opacity: 1; }

.product-form .modal-body {
    padding: 20px 22px;
    background: var(--card, #ffffff);
    max-height: none;
    overflow: visible;
}
.product-form .modal-footer {
    background: var(--bg-soft, #faf7f2);
    border-top: 1px solid var(--border, #e5ddd0);
    padding: 12px 22px;
    gap: 10px;
}

.product-form .form-label {
    display: block; font-size: .68rem; font-weight: 600;
    color: var(--text, #5a5245); margin-bottom: 5px;
    letter-spacing: .05em; text-transform: uppercase;
}
.product-form .form-control,
.product-form .form-select {
    width: 100%;
    background: var(--bg-soft, #faf7f2);
    border: 1px solid var(--border, #e5ddd0);
    border-radius: 9px;
    padding: 9px 12px;
    color: var(--text-bright, #2b2620);
    font-size: .85rem; font-family: 'Inter', sans-serif;
    font-weight: 500; transition: all .2s ease;
}
.product-form .form-control::placeholder {
    color: var(--text-dim, #b3a998); font-weight: 400;
}
.product-form .form-control:focus,
.product-form .form-select:focus {
    outline: none; border-color: var(--primary, #5b5fd4);
    box-shadow: 0 0 0 3px var(--primary-soft, rgba(91,95,212,.08));
    background: var(--card, #ffffff);
}
.product-form textarea.form-control {
    resize: vertical; min-height: 60px; line-height: 1.5;
}
.product-form input[type="file"].form-control {
    padding: 9px 12px; font-size: .82rem; cursor: pointer;
}

.product-form .row { --bs-gutter-x: 12px; --bs-gutter-y: 10px; }
.product-form .mb-3 { margin-bottom: .7rem !important; }
.product-form .mb-2 { margin-bottom: .5rem !important; }

/* -------- Stock summary pills (read-only) -------- */
.stock-calc {
    display: flex; gap: 8px; flex-wrap: wrap;
    padding: 8px 10px;
    background: var(--bg-soft, #faf7f2);
    border: 1px solid var(--border, #e5ddd0);
    border-radius: 10px;
    margin-bottom: 14px;
}
.stock-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 20px;
    font-size: .7rem; font-weight: 600;
}
.stock-pill.total    { background: rgba(91,95,212,.1); color: var(--primary, #5b5fd4); }
.stock-pill.released { background: rgba(37,99,235,.1); color:#1d4ed8; }

/* -------- Featured checkbox -------- */
.product-form .form-check {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    background: var(--bg-soft, #faf7f2);
    border: 1px solid var(--border, #e5ddd0);
    border-radius: 10px; margin-bottom: 0;
}
.product-form .form-check-input {
    width: 16px; height: 16px; margin: 0;
    accent-color: var(--primary, #5b5fd4);
    cursor: pointer; flex-shrink: 0;
}
.product-form .form-check-label {
    font-size: .82rem; font-weight: 600;
    color: var(--text-bright, #2b2620);
    cursor: pointer; display: flex;
    align-items: center; gap: 6px;
}
.product-form .form-check-label .text-warning {
    color: var(--gold, #b8941f) !important;
}

/* -------- Buttons -------- */
.product-form .btn-modern {
    background: var(--primary, #5b5fd4);
    border: none; color: #fff; border-radius: 9px;
    font-size: .82rem; font-weight: 600;
    padding: 9px 20px; transition: all .2s ease;
    display: inline-flex; align-items: center; gap: 6px;
    cursor: pointer; font-family: 'Inter', sans-serif;
}
.product-form .btn-modern:hover {
    background: #4f46e5; color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px -3px rgba(91, 95, 212, .35);
}
.product-form .btn-outline-modern {
    background: transparent;
    border: 1px solid var(--border, #e5ddd0);
    color: var(--text-muted, #8a8172);
    border-radius: 9px; font-size: .82rem; font-weight: 600;
    padding: 9px 20px; transition: all .2s ease;
    display: inline-flex; align-items: center; gap: 6px;
    cursor: pointer; font-family: 'Inter', sans-serif;
}
.product-form .btn-outline-modern:hover {
    background: var(--primary-soft, rgba(91,95,212,.08));
    border-color: var(--primary, #5b5fd4);
    color: var(--primary, #5b5fd4);
}

.modal-body .text-danger {
    display: flex; align-items: center; gap: 10px;
    background: rgba(220, 38, 38, .08); color: #b91c1c;
    border: 1px solid rgba(220, 38, 38, .18);
    border-radius: 10px; padding: 12px 14px;
    font-size: .82rem; font-weight: 500;
    font-family: 'Inter', sans-serif;
}

/* ============================================================
   BIG IMAGE SECTION (200 x 200)
   ============================================================ */
.product-form .image-block {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    padding: 16px;
    background: var(--bg-soft, #faf7f2);
    border: 1px solid var(--border, #e5ddd0);
    border-radius: 14px;
}

.product-form .image-frame {
    width: 200px;
    height: 200px;
    flex-shrink: 0;
    border-radius: 12px;
    border: 2px solid var(--border, #e5ddd0);
    background: var(--card, #ffffff);
    box-shadow: 0 4px 14px -6px rgba(90, 82, 69, .18);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.product-form .image-frame img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
}
.product-form .image-frame.placeholder {
    color: var(--text-dim, #b3a998);
    font-size: 3.5rem;
    background: linear-gradient(135deg, #faf7f2 0%, #f0eae0 100%);
}
.product-form .image-frame::after {
    content: '';
    position: absolute; inset: 0;
    border-radius: 10px;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.5);
    pointer-events: none;
}

.product-form .image-controls {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-top: 4px;
}
.product-form .image-controls .form-label {
    margin-bottom: 2px;
}
.product-form .image-controls small {
    color: var(--text-dim, #b3a998);
    font-size: .72rem;
    line-height: 1.5;
}
.product-form .image-controls .hint-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    background: rgba(91,95,212,.08);
    color: var(--primary, #5b5fd4);
    border-radius: 20px;
    font-size: .7rem;
    font-weight: 600;
    width: fit-content;
}

.modal-dialog.modal-dialog-centered { margin: 1rem auto; }
@media (min-width: 992px) { .modal-xl { max-width: 920px; } }

/* Mobile: stack image block */
@media (max-width: 576px) {
    .product-form .image-block { flex-direction: column; align-items: stretch; }
    .product-form .image-frame { width: 100%; height: 260px; }
}
</style>

<form action="update-product.php" method="POST" enctype="multipart/form-data" class="product-form">
    <input type="hidden" name="id" value="<?php echo $product['id']; ?>">

    <div class="modal-body">

        <!-- Read-only stock summary (Total + Released) -->
        <div class="stock-calc">
            <span class="stock-pill total"><i class="fas fa-cubes"></i> <?php echo $totalStock; ?> Total</span>
            <span class="stock-pill released"><i class="fas fa-check-circle"></i> <?php echo $releasedStock; ?> Released</span>
        </div>

        <!-- 1) NAME + CATEGORY -->
        <div class="row g-3">
            <div class="col-md-7">
                <div class="mb-3">
                    <label class="form-label">Product Name *</label>
                    <input type="text" name="name" class="form-control"
                           placeholder="e.g. Gold Diamond Ring"
                           value="<?php echo htmlspecialchars($product['name']); ?>" required>
                </div>
            </div>
            <div class="col-md-5">
                <div class="mb-3">
                    <label class="form-label">Category *</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">Select Category</option>
                        <?php foreach($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>" <?php echo $category['id'] == $product['category_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- 2) DESCRIPTION -->
        <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2"
                      placeholder="Short product description"><?php echo htmlspecialchars($product['description']); ?></textarea>
        </div>

        <!-- 3) KARAT · WEIGHT · PRICE · RELEASED STOCK -->
        <div class="row g-3">
            <div class="col-md-3 col-6">
                <div class="mb-3">
                    <label class="form-label">Karat</label>
                    <input type="text" name="karat" class="form-control"
                           placeholder="18K, 24K"
                           value="<?php echo htmlspecialchars($karat); ?>">
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="mb-3">
                    <label class="form-label">Weight (g)</label>
                    <input type="number" step="0.01" min="0" name="weight" class="form-control"
                           placeholder="0.00"
                           value="<?php echo $weight !== null && $weight !== '' ? htmlspecialchars(number_format((float)$weight, 2, '.', '')) : ''; ?>">
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="mb-3">
                    <label class="form-label">Price *</label>
                    <input type="number" step="0.01" min="0" name="price" class="form-control"
                           placeholder="0.00"
                           value="<?php echo $product['price']; ?>" required>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="mb-3">
                    <label class="form-label">Released Stock</label>
                    <input type="number" min="0" max="<?php echo $totalStock; ?>"
                           name="released_stock" id="editReleasedInput" class="form-control"
                           placeholder="0"
                           value="<?php echo $releasedStock; ?>">
                    <small style="color:var(--text-dim); font-size:.68rem; display:block; margin-top:4px;">
                        Max: <?php echo $totalStock; ?> (Total)
                    </small>
                </div>
            </div>
        </div>

        <!-- 4) BIG IMAGE BLOCK -->
        <div class="image-block mb-3">
            <div class="image-frame <?php echo empty($product['image']) ? 'placeholder' : ''; ?>" id="editImageFrame">
                <?php if($product['image']): ?>
                    <img src="../uploads/<?php echo htmlspecialchars($product['image']); ?>" alt="Current image" id="editImageCurrent">
                <?php else: ?>
                    <i class="fas fa-image"></i>
                <?php endif; ?>
            </div>
            <div class="image-controls">
                <label class="form-label">Product Image</label>
                <span class="hint-badge"><i class="fas fa-image"></i> 500 × 500px · Max 2MB</span>
                <input type="file" name="image" class="form-control" accept="image/*" id="editImage">
                <small><i class="fas fa-info-circle"></i> Leave empty to keep the current image. New upload replaces it.</small>
                <?php if($product['image']): ?>
                    <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($product['image']); ?>">
                <?php endif; ?>
            </div>
        </div>

        <!-- 5) FEATURED -->
        <div class="form-check">
            <input type="checkbox" name="featured" class="form-check-input" value="1" id="editFeatured" <?php echo $product['featured'] ? 'checked' : ''; ?>>
            <label class="form-check-label" for="editFeatured">
                <i class="fas fa-star text-warning"></i> Feature this product
            </label>
        </div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn-outline-modern" data-bs-dismiss="modal">
            <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="btn-modern">
            <i class="fas fa-check"></i> Update Product
        </button>
    </div>
</form>

<script>
(function () {
    /* ---- Released stock: cap at total ---- */
    const releasedEl = document.getElementById('editReleasedInput');
    const totalStock = <?php echo (int)$totalStock; ?>;
    if (releasedEl) {
        releasedEl.addEventListener('input', function () {
            let v = parseInt(this.value, 10);
            if (isNaN(v) || v < 0) v = 0;
            if (v > totalStock) v = totalStock;
            this.value = v;
        });
    }

    /* ---- Image preview: swap inside the big frame ---- */
    const fileInput = document.getElementById('editImage');
    const frame     = document.getElementById('editImageFrame');
    if (fileInput && frame) {
        fileInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function (e) {
                frame.classList.remove('placeholder');
                frame.innerHTML = '<img src="' + e.target.result + '" alt="New image preview">';
            };
            reader.readAsDataURL(file);
        });
    }
})();
</script>