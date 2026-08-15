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

<main class="pos-content" style="padding: 1.5rem; max-width: 1400px; margin: 0 auto;">
    <div style="display:flex; justify-content:space-between; align-items: center; margin-bottom: 3.5rem;">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Product Catalog</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Curate and manage your collection of premium floral artifacts.</p>
        </div>
        <button onclick="toggleForm()" class="btn-primary shadow-lg shadow-pink-100 flex items-center px-8 py-3 rounded-2xl font-black text-xs uppercase tracking-widest">
            <i class="fa-solid fa-plus-circle mr-3"></i> <span>Add Master Entry</span>
        </button>
    </div>

    <!-- FORM BOX -->
    <div id="pform" style="display:none; background:white; padding:3rem; border-radius:35px; margin-bottom:3.5rem; border:1px solid #f0f0f0; box-shadow: 0 10px 30px rgba(0,0,0,0.02);">
        <h4 class="brand-font text-3xl font-black text-gray-800 mb-8">Initialize New Product</h4>
        <form id="addProductForm">
            <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:2rem;">
                <div>
                    <label style="font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 1.5px;">Product Descriptor</label>
                    <input type="text" id="productName" placeholder="e.g. Midnight Serenade Bouquet" required style="padding:15px; border-radius:15px; border:1px solid #f0f0f0; width: 100%; outline: none; background: #fafafa; font-size: 0.9rem; font-weight: 600;">
                </div>
                <div>
                    <label style="font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 1.5px;">Classification</label>
                    <select id="category" style="padding:15px; border-radius:15px; border:1px solid #f0f0f0; width: 100%; outline: none; background: #fafafa; font-size: 0.9rem; font-weight: 600; cursor: pointer;">
                        <option>Bouquet</option>
                        <option>Flower Stand</option>
                        <option>Gift Box</option>
                        <option>Single Stem</option>
                        <option>Arrangement</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 1.5px;">Valuation (₱)</label>
                    <input type="number" step="0.01" id="price" placeholder="0.00" required style="padding:15px; border-radius:15px; border:1px solid #f0f0f0; width: 100%; outline: none; background: #fafafa; font-size: 0.9rem; font-weight: 600;">
                </div>
                <div>
                    <label style="font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 1.5px;">Initial Deployment Qty</label>
                    <input type="number" id="stockQuantity" placeholder="0" required style="padding:15px; border-radius:15px; border:1px solid #f0f0f0; width: 100%; outline: none; background: #fafafa; font-size: 0.9rem; font-weight: 600;">
                </div>
            </div>
            <button type="submit" id="saveBtn" style="width:100%; background:var(--primary); color:white; margin-top:3rem; padding:20px; border-radius:20px; border:none; font-weight:900; cursor:pointer; transition: 0.4s; text-transform: uppercase; letter-spacing: 3px; font-size: 0.7rem; box-shadow: 0 10px 20px rgba(233,30,99,0.15);">Commit Product to Master Catalog</button>
        </form>
    </div>

    <div style="background:white; border-radius:35px; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; overflow: hidden;">
        <table style="width:100%; text-align:left; border-collapse:collapse;">
            <thead>
                <tr style="background: #fafafa; border-bottom: 1px solid #f0f0f0;">
                    <th style="padding:25px 20px; font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1.5px;">Master Product Details</th>
                    <th style="padding:25px 20px; font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1.5px;">Classification</th>
                    <th style="padding:25px 20px; font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1.5px;">Market Valuation</th>
                    <th style="padding:25px 20px; font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1.5px;">Inventory Readiness</th>
                    <th style="padding:25px 20px; font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1.5px; text-align: right;">Operations</th>
                </tr>
            </thead>
            <tbody id="productListData">
                <tr><td colspan="5" style="text-align:center; padding: 80px;" class="text-gray-300 italic font-medium">Synchronizing master records...</td></tr>
            </tbody>
        </table>
    </div>
</main>

<script>
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
                const colorClass = stock <= 5 ? 'critical' : (stock <= 15 ? 'warning' : 'healthy');
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
                btn.innerText = 'Save Product to Catalog';
            }
        };
    });

    function toggleForm() {
        const x = document.getElementById("pform");
        if (x.style.display === "none" || x.style.display === "") {
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
