<?php 
/**
 * BLOOMINOUS - Product Catalog (Firebase Spoke)
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
    /* Former inline styles, now named classes so they can follow the
       theme. Light values are identical to the old inline ones:
       white -> --surface, #f0f0f0 -> --border-color, #fafafa -> --surface-alt. */
    .catalog-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 3.5rem; }
    .catalog-card { background: var(--surface); border-radius: 35px; border: 1px solid var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.02); }
    .catalog-form-card { display: none; padding: 3rem; margin-bottom: 3.5rem; }
    .catalog-table-card { overflow: hidden; }
    .catalog-form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 2rem; }
    .catalog-label { font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 1.5px; }
    .catalog-input { padding: 15px; border-radius: 15px; border: 1px solid var(--border-color); width: 100%; outline: none; background: var(--surface-alt); color: var(--text-main); font-size: 0.9rem; font-weight: 600; }
    select.catalog-input { cursor: pointer; }
    .catalog-save-btn { width: 100%; background: var(--primary); color: white; margin-top: 3rem; padding: 20px; border-radius: 20px; border: none; font-weight: 900; cursor: pointer; transition: 0.4s; text-transform: uppercase; letter-spacing: 3px; font-size: 0.7rem; box-shadow: 0 10px 20px rgba(233,30,99,0.15); }
    .catalog-save-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .catalog-table { width: 100%; text-align: left; border-collapse: collapse; }
    .catalog-table thead tr { background: var(--surface-alt); border-bottom: 1px solid var(--border-color); }
    .catalog-table th { padding: 25px 20px; font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1.5px; }

    @media (max-width: 700px) {
        .catalog-form-grid { grid-template-columns: 1fr; }
    }

    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>. */
    html[data-theme="dark"] .catalog-card { box-shadow: none; }
    html[data-theme="dark"] .catalog-save-btn { box-shadow: none; }
    html[data-theme="dark"] select.catalog-input { color-scheme: dark; }
    html[data-theme="dark"] .catalog-content .shadow-pink-100 { box-shadow: none; }

    /* Tailwind class remap, scoped to this page */
    html[data-theme="dark"] .catalog-content :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] .catalog-content :is(.text-gray-500, .text-gray-400, .text-gray-300, .text-muted) { color: var(--text-light); }

    /* Row hover: a pale-gray wash would flash white on a dark table */
    html[data-theme="dark"] .catalog-content .hover\:bg-gray-50\/50:hover { background-color: var(--surface-alt); }

    /* Archive button: tinted at rest... */
    html[data-theme="dark"] .catalog-content .bg-amber-50 { background-color: rgba(245, 158, 11, 0.14); }
    html[data-theme="dark"] .catalog-content .text-amber-600 { color: #fcd34d; }
    /* ...and the SAME solid amber hover as light mode. Needed because the
       dark "at rest" rules above are more specific than Tailwind's own
       hover classes and would otherwise block the hover effect. */
    html[data-theme="dark"] .catalog-content .hover\:bg-amber-500:hover { background-color: #f59e0b; }
    html[data-theme="dark"] .catalog-content .hover\:text-white:hover { color: #ffffff; }
</style>

<main class="pos-content catalog-content" style="padding: 1.5rem; max-width: 1400px; margin: 0 auto;">
    <div class="catalog-header">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Product Catalog</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Curate and manage your collection of premium floral artifacts.</p>
        </div>
        <button onclick="toggleForm()" class="btn-primary shadow-lg shadow-pink-100 flex items-center px-8 py-3 rounded-2xl font-black text-xs uppercase tracking-widest">
            <i class="fa-solid fa-plus-circle mr-3"></i> <span>Add Master Entry</span>
        </button>
    </div>

    <!-- FORM BOX -->
    <div id="pform" class="catalog-card catalog-form-card">
        <h4 class="brand-font text-3xl font-black text-gray-800 mb-8">Initialize New Product</h4>
        <form id="addProductForm">
            <div class="catalog-form-grid">
                <div>
                    <label class="catalog-label" for="productName">Product Descriptor</label>
                    <input type="text" id="productName" class="catalog-input" placeholder="e.g. Midnight Serenade Bouquet" required>
                </div>
                <div>
                    <label class="catalog-label" for="category">Classification</label>
                    <select id="category" class="catalog-input">
                        <option>Bouquet</option>
                        <option>Flower Stand</option>
                        <option>Gift Box</option>
                        <option>Single Stem</option>
                        <option>Arrangement</option>
                    </select>
                </div>
                <div>
                    <label class="catalog-label" for="price">Valuation (₱)</label>
                    <input type="number" step="0.01" id="price" class="catalog-input" placeholder="0.00" required>
                </div>
                <div>
                    <label class="catalog-label" for="stockQuantity">Initial Deployment Qty</label>
                    <input type="number" id="stockQuantity" class="catalog-input" placeholder="0" required>
                </div>
            </div>
            <button type="submit" id="saveBtn" class="catalog-save-btn">Commit Product to Master Catalog</button>
        </form>
    </div>

    <div class="catalog-card catalog-table-card">
        <table class="catalog-table">
            <thead>
                <tr>
                    <th>Master Product Details</th>
                    <th>Classification</th>
                    <th>Market Valuation</th>
                    <th>Inventory Readiness</th>
                    <th style="text-align: right;">Operations</th>
                </tr>
            </thead>
            <tbody id="productListData">
                <tr><td colspan="5" style="text-align:center; padding: 80px;" class="text-gray-300 italic font-medium">Synchronizing master records...</td></tr>
            </tbody>
        </table>
    </div>
</main>

<script>
    // Single source for the button's resting label, so the text shown
    // before AND after a save can never drift apart again.
    const SAVE_BTN_LABEL = 'Commit Product to Master Catalog';

    document.addEventListener('DOMContentLoaded', () => {
        const productListData = document.getElementById('productListData');

        // Real-time listener for inventory
        getBranchPath('inventory').orderBy('createdAt', 'desc').onSnapshot(snap => {
            if (snap.empty) {
                productListData.innerHTML = "<tr><td colspan='5' style='text-align:center; padding: 50px;' class='text-muted'>No products found in catalog.</td></tr>";
                return;
            }

            let html = '';
            snap.forEach(doc => {
                const p = doc.data();
                if (p.isDeleted === true || p.status === 'archived') {
                    return;
                }
                const id = doc.id;
                const stock = parseInt(p.stock || 0);
                const dotColor = stock <= 5 ? 'var(--primary)' : (stock <= 15 ? '#f39c12' : '#2ecc71');

                html += `
                <tr class="group hover:bg-gray-50/50 transition-all">
                    <td class="p-8">
                        <div class="font-bold text-gray-800 text-lg">${p.name || 'Unnamed'}</div>
                    </td>
                    <td class="p-8"><span class="badge-capsule" style="background: rgba(123, 121, 242, 0.1); color: var(--secondary);">${(p.category || 'N/A').toUpperCase()}</span></td>
                    <td class="p-8 brand-font font-black text-pink-600 text-xl">₱${parseFloat(p.price || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    <td class="p-8">
                        <div class="flex items-center gap-3">
                            <span class="w-2.5 h-2.5 rounded-full" style="background: ${dotColor}; box-shadow: 0 0 10px ${dotColor}33;"></span>
                            <span class="font-bold text-gray-700 text-sm">${stock} units</span>
                        </div>
                    </td>
                    <td class="p-8 text-right">
                        <button onclick="archiveProduct('${id}')" class="w-10 h-10 inline-flex items-center justify-center rounded-xl bg-amber-50 text-amber-600 hover:bg-amber-500 hover:text-white transition-all transform hover:-translate-y-1" title="Archive Inventory Unit">
                            <i class="fa-solid fa-box-archive"></i>
                        </button>
                    </td>
                </tr>
                `;
            });
            productListData.innerHTML = html;
        });

        document.getElementById('addProductForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('saveBtn');
            btn.disabled = true;
            btn.innerText = 'Saving...';

            try {
                await getBranchPath('inventory').add({
                    name: document.getElementById('productName').value,
                    category: document.getElementById('category').value,
                    price: parseFloat(document.getElementById('price').value),
                    stock: parseInt(document.getElementById('stockQuantity').value),
                    branchId: window.currentBranch,
                    createdAt: firebase.firestore.FieldValue.serverTimestamp(),
                    updatedAt: firebase.firestore.FieldValue.serverTimestamp(),
                    code: "BLOOM-" + Date.now(),
                    image: "",
                    model: ""
                });
                alert('Product added to catalog successfully!');
                document.getElementById('addProductForm').reset();
                toggleForm();
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = SAVE_BTN_LABEL;
            }
        };
    });

    function toggleForm() {
        const x = document.getElementById("pform");
        // getComputedStyle reads the ACTUAL current display value, which
        // now comes from the .catalog-form-card class (display:none)
        // rather than an inline style="display:none".
        if (getComputedStyle(x).display === "none") {
            x.style.display = "block";
            x.scrollIntoView({ behavior: 'smooth' });
        } else {
            x.style.display = "none";
        }
    }

    async function archiveProduct(id) {
        if (confirm('Are you sure you want to deactivate/archive this product? This will hide it from active inventory and selling screens, but preserve its historical record for sales reports.')) {
            try {
                // Soft delete by updating isDeleted and status fields
                await getBranchPath('inventory').doc(id).update({
                    isDeleted: true,
                    status: 'archived',
                    archivedAt: firebase.firestore.FieldValue.serverTimestamp()
                });
                
                try {
                    await db.collection('inventory').doc(id).update({
                        isDeleted: true,
                        status: 'archived',
                        archivedAt: firebase.firestore.FieldValue.serverTimestamp()
                    });
                } catch (err) {
                    console.log("Global doc archive skip or handled: ", err.message);
                }
            } catch (err) {
                alert('Error: ' + err.message);
            }
        }
    }
</script>

<?php include 'templates/footer.php'; ?>