<?php 
/**
 * BLOOMINOUS - Add Product (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php'; 
?>

<style>
    .add-content { padding: 1.5rem; max-width: 800px; margin: 0 auto; }
    .form-card { background: #fff; border-radius: 30px; padding: 3rem; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; }
    .form-group { margin-bottom: 1.5rem; }
    .form-group label { display: block; font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; letter-spacing: 1px; }
    .form-control { width: 100%; padding: 14px 18px; border: 1px solid #f0f0f0; border-radius: 12px; outline: none; transition: 0.3s; background: #fafafa; color: var(--text-main); font-weight: 500; font-size: 0.9rem; }
    .form-control:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 15px rgba(233, 30, 99, 0.05); }
    
    .back-link { display: inline-flex; align-items: center; gap: 10px; color: var(--text-light); text-decoration: none; font-weight: 700; margin-bottom: 2rem; transition: 0.3s; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; }
    .back-link:hover { color: var(--primary); transform: translateX(-5px); }
</style>

<div class="add-content">
    <a href="product_management.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> <span>Catalog Overview</span>
    </a>

    <div class="mb-10">
        <h1 class="brand-font text-4xl font-black text-gray-800">New Artifact Enrollment</h1>
        <p class="text-gray-400 text-sm font-medium mt-1">Populate your collection with artisan floral arrangements.</p>
    </div>

    <div class="form-card">
        <form id="addProductForm">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label>Designation / Name</label>
                    <input type="text" id="name" class="form-control" placeholder="e.g. Red Rose Bouquet" required>
                </div>

                <div class="form-group">
                    <label>Category</label>
                    <select id="category" class="form-control" required>
                        <option value="">Select Category</option>
                        <option value="Bouquets">Bouquets</option>
                        <option value="Flower Baskets">Flower Baskets</option>
                        <option value="Box Arrangements">Box Arrangements</option>
                        <option value="Sympathy Flowers">Sympathy Flowers</option>
                        <option value="Wedding Flowers">Wedding Flowers</option>
                        <option value="Single Stems">Single Stems</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Product Code / SKU</label>
                    <input type="text" id="barcode" class="form-control" placeholder="e.g. BLOOM-001" required>
                </div>

                <div class="form-group">
                    <label>Unit Price (₱)</label>
                    <input type="number" step="0.01" id="price" class="form-control" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label>Initial Stock Quantity</label>
                    <input type="number" id="stock" class="form-control" placeholder="0" required>
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>Product Image URL (Optional)</label>
                    <input type="text" id="imageUrl" class="form-control" placeholder="https://example.com/image.jpg">
                    <small style="color: #b2bec3; font-size: 0.7rem; margin-top: 5px; display: block;">Leave blank to use a default placeholder image.</small>
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>3D Model Path (.glb URL / Optional)</label>
                    <input type="text" id="modelUrl" class="form-control" placeholder="https://example.com/flower_model.glb">
                    <small style="color: #b2bec3; font-size: 0.7rem; margin-top: 5px; display: block;">Link to a .glb file to enable AR immersive view in the mobile app.</small>
                </div>

                <div class="p-6 rounded-2xl border border-amber-100 bg-[#FFFBEB]" style="grid-column: span 2; margin-top: 10px;">
                    <h3 class="font-bold text-[#F59E0B] text-sm flex items-center gap-2 mb-2">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Adding 3D Flower Models (Hyper3D.ai)
                    </h3>
                    <p class="text-xs text-gray-600 leading-relaxed mb-4">
                        You can easily add bespoke 3D flower arrangements to your inventory for immersive AR previews. No coding experience needed:
                    </p>
                    <ul class="list-disc list-inside text-xs text-gray-600 space-y-2">
                        <li><strong>Step 1:</strong> Visit <a href="https://hyper3d.ai" target="_blank" class="underline font-bold text-[#F59E0B] hover:opacity-85">hyper3d.ai</a> to generate/sculpt your 3D flower asset.</li>
                        <li><strong>Step 2:</strong> Download or export the model in <strong>.glb</strong> format.</li>
                        <li><strong>Step 3:</strong> Upload the <code>.glb</code> file to any cloud storage or web server (e.g. Firebase, Github, Dropbox, Discord) to get a public URL.</li>
                        <li><strong>Step 4:</strong> Paste that public URL into the <strong>3D Model Path (.glb URL)</strong> field above!</li>
                    </ul>
                </div>
            </div>

            <button type="submit" id="saveBtn" class="btn-primary w-full py-5 text-sm uppercase tracking-widest mt-6">
                <i class="fa-solid fa-cloud-arrow-up mr-2"></i> Commit to Inventory
            </button>
        </form>
    </div>
</div>

<script>
    document.getElementById('addProductForm').onsubmit = async (e) => {
        e.preventDefault();
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Saving to Database...';

        try {
            const barcode = document.getElementById('barcode').value.toUpperCase();
            
            // Check if barcode/SKU already exists in this branch
            const check = await getBranchPath('inventory').where('code', '==', barcode).get();
            if (!check.empty) {
                alert('A product with this SKU/Barcode already exists in this branch!');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up mr-2"></i> Save Product to Inventory';
                return;
            }

            await getBranchPath('inventory').add({
                name: document.getElementById('name').value,
                category: document.getElementById('category').value,
                code: barcode,
                price: parseFloat(document.getElementById('price').value),
                stock: parseInt(document.getElementById('stock').value),
                image: document.getElementById('imageUrl').value || 'https://picsum.photos/seed/flower/400/400',
                branchId: window.currentBranch,
                createdAt: firebase.firestore.FieldValue.serverTimestamp(),
                updatedAt: firebase.firestore.FieldValue.serverTimestamp(),
                model: document.getElementById('modelUrl').value || "" 
            });
            
            alert('Product added to inventory successfully!');
            window.location.href = 'product_management.php';
        } catch (err) {
            alert('Error saving product: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up mr-2"></i> Save Product to Inventory';
        }
    };
</script>

<?php include 'templates/footer.php'; ?>