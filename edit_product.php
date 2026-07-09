<?php 
/**
 * BLOOMINOUS - Edit Product (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

// GET PRODUCT ID FROM URL
$p_id = $_GET['id'] ?? null;
if (!$p_id) {
    header("Location: product_management.php");
    exit();
}

include 'templates/header.php'; 
?>

<style>
    .main-edit-wrapper { padding: 1.5rem; max-width: 1000px; margin: 0 auto; }
    .edit-card { background: white; border-radius: 30px; padding: 3rem; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; }
    .input-box { margin-bottom: 1.5rem; }
    .input-label { display: block; font-weight: 800; color: var(--text-light); font-size: 0.75rem; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; }
    .form-input { width: 100%; padding: 14px 18px; background: #fafafa; border: 1px solid #f0f0f0; border-radius: 12px; outline: none; transition: 0.3s; color: var(--text-main); font-weight: 500; font-size: 0.9rem; }
    .form-input:focus { border-color: var(--primary); background: white; box-shadow: 0 0 15px rgba(233, 30, 99, 0.05); }
    
    .preview-container { border: 2px dashed #f0f0f0; border-radius: 25px; height: 350px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fafafa; position: relative; }
    #imgPreview { width: 100%; height: 100%; object-fit: cover; transition: 0.3s; }
    
    .btn-group { display: flex; justify-content: flex-end; gap: 15px; margin-top: 30px; padding-top: 30px; border-top: 1px solid #f8f9fa; }
    .btn-cancel { background: #fafafa; color: var(--text-light); padding: 16px 35px; border-radius: 15px; text-decoration: none; font-weight: 800; transition: 0.3s; display: flex; align-items: center; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; border: 1px solid #eee; }
    .btn-cancel:hover { background: #f0f0f0; color: var(--text-main); }

    .back-link { display: inline-flex; align-items: center; gap: 10px; color: var(--text-light); text-decoration: none; font-weight: 700; margin-bottom: 2rem; transition: 0.3s; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; }
    .back-link:hover { color: var(--primary); transform: translateX(-5px); }
</style>

<div class="main-edit-wrapper">
    <a href="product_management.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> <span>Archive Control</span>
    </a>

    <div class="edit-card">
        <div class="mb-10">
            <h2 class="brand-font text-4xl font-black text-gray-800">
                <i class="fas fa-pen-nib mr-4 text-pink-500"></i> Modify Artifact
            </h2>
            <p class="text-gray-400 text-sm font-medium mt-1">Refine the master data for this inventory unit.</p>
        </div>

        <form id="editProductForm">
            <div style="display: flex; gap: 40px; flex-wrap: wrap;">
                <!-- Left Side -->
                <div style="flex: 1.5; min-width: 300px;">
                    <div class="input-box">
                        <label class="input-label">Product Name</label>
                        <input type="text" id="name" class="form-input" required placeholder="e.g. Red Rose Bouquet">
                    </div>

                    <div class="input-box">
                        <label class="input-label">Barcode / SKU</label>
                        <input type="text" id="barcode" class="form-input" required placeholder="e.g. BLOOM-001">
                    </div>

                    <div style="display: flex; gap: 20px;">
                        <div style="flex: 1;" class="input-box">
                            <label class="input-label">Unit Price (₱)</label>
                            <input type="number" step="0.01" id="price" class="form-input" required placeholder="0.00">
                        </div>
                        <div style="flex: 1;" class="input-box">
                            <label class="input-label">Stock Quantity</label>
                            <input type="number" id="stock" class="form-input" required placeholder="0">
                        </div>
                    </div>

                    <div class="input-box">
                        <label class="input-label">Category</label>
                        <select id="category" class="form-input">
                            <option value="Bouquets">Bouquets</option>
                            <option value="Flower Baskets">Flower Baskets</option>
                            <option value="Box Arrangements">Box Arrangements</option>
                            <option value="Sympathy Flowers">Sympathy Flowers</option>
                            <option value="Wedding Flowers">Wedding Flowers</option>
                            <option value="Single Stems">Single Stems</option>
                        </select>
                    </div>
                </div>

                <!-- Right Side -->
                <div style="flex: 1; min-width: 280px;">
                    <label class="input-label">Product Image URL</label>
                    <input type="text" id="imageUrl" class="form-input" placeholder="https://example.com/image.jpg" onchange="document.getElementById('imgPreview').src = this.value || 'https://picsum.photos/seed/flower/400/400'">
                    <div class="preview-container" style="margin-top: 15px;">
                        <img id="imgPreview" src="https://picsum.photos/seed/flower/400/400">
                        <div style="position: absolute; bottom: 10px; right: 10px; background: rgba(255,255,255,0.8); padding: 5px 10px; border-radius: 8px; font-size: 0.65rem; font-weight: 700; color: #7B79F2;">PREVIEW</div>
                    </div>
                    <small style="color: #b2bec3; font-size: 0.7rem; margin-top: 10px; display: block;">Images are updated in real-time when the URL changes.</small>
                </div>
            </div>

            <div class="btn-group">
                <a href="product_management.php" class="btn-cancel">Discard</a>
                <button type="submit" id="saveBtn" class="btn-primary px-12 py-5 text-sm uppercase tracking-widest">
                    <i class="fa-solid fa-check-double mr-2"></i> Update Artifact
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const productId = "<?php echo $p_id; ?>";

    document.addEventListener('DOMContentLoaded', () => {
        // Fetch current data from branch-specific inventory
        getBranchPath('inventory').doc(productId).get().then(doc => {
            if (!doc.exists) {
                // Try global if not found in current branch (for migration/fallback)
                db.collection('inventory').doc(productId).get().then(docGlobal => {
                    if (!docGlobal.exists) {
                        alert('Product not found!');
                        window.location.href = 'product_management.php';
                        return;
                    }
                    populateForm(docGlobal.data());
                });
                return;
            }
            populateForm(doc.data());
        });

        function populateForm(p) {
            document.getElementById('name').value = p.name || '';
            document.getElementById('barcode').value = p.code || '';
            document.getElementById('price').value = p.price || 0;
            document.getElementById('stock').value = p.stock || 0;
            document.getElementById('category').value = p.category || 'Bouquets';
            document.getElementById('imageUrl').value = p.image || '';
            document.getElementById('imgPreview').src = p.image || 'https://picsum.photos/seed/flower/400/400';
        }

        document.getElementById('editProductForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('saveBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Updating...';

            try {
                const barcode = document.getElementById('barcode').value.toUpperCase();

                await getBranchPath('inventory').doc(productId).update({
                    name: document.getElementById('name').value,
                    code: barcode,
                    price: parseFloat(document.getElementById('price').value),
                    stock: parseInt(document.getElementById('stock').value),
                    category: document.getElementById('category').value,
                    image: document.getElementById('imageUrl').value || 'https://picsum.photos/seed/flower/400/400',
                    updatedAt: firebase.firestore.FieldValue.serverTimestamp()
                });
                
                alert('Product updated successfully!');
                window.location.href = 'product_management.php';
            } catch (err) {
                alert('Error updating product: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check-double mr-2"></i> Update Product';
            }
        };
    });
</script>

<?php include 'templates/footer.php'; ?>
