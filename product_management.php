<?php 
/**
 * BLOOMINOUS - Product Management (Firebase Spoke)
 */
session_start();

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php'; 
?>

<!-- JSBarcode Library -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<style>
    .main-content-area { padding: 1.5rem; margin: 0 auto; max-width: 1400px; }
    .inventory-card { background: white; border-radius: 30px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; }
    .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; }
    
    table { width: 100%; border-collapse: collapse; }
    table th { text-align: left; padding: 18px; color: var(--text-light); font-size: 0.75rem; text-transform: uppercase; border-bottom: 1px solid #f0f0f0; font-weight: 800; letter-spacing: 1px; }
    table td { padding: 18px; border-bottom: 1px solid #f8f9fa; color: var(--text-main); font-size: 0.9rem; font-weight: 500; }
    
    .product-img { width: 50px; height: 50px; border-radius: 15px; object-fit: cover; box-shadow: 0 5px 10px rgba(0,0,0,0.05); }
    .status-badge { padding: 6px 12px; border-radius: 50px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
    .in-stock { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
    .low-stock { background: rgba(255, 177, 66, 0.1); color: #f39c12; }
    .out-stock { background: rgba(233, 30, 99, 0.1); color: var(--primary); }
    
    .action-icons a { width: 35px; height: 35px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; margin-right: 5px; transition: 0.2s; }
    .edit-icon { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .edit-icon:hover { background: var(--secondary); color: white; transform: translateY(-2px); }
    .archive-icon { background: rgba(52, 152, 219, 0.1); color: #2980b9; }
    .archive-icon:hover { background: #2980b9; color: white; transform: translateY(-2px); }
    .spoil-icon { background: rgba(255, 177, 66, 0.1); color: #f39c12; }
    .spoil-icon:hover { background: #f39c12; color: white; transform: translateY(-2px); }
</style>

<div class="main-content-area">
    <div class="header-flex">
        <div>
            <h2 class="brand-font text-4xl font-black text-gray-800">Product Inventory</h2>
            <p class="text-gray-400 text-sm font-medium mt-1">Real-time stock monitoring and replenishment.</p>
        </div>
        <a href="add_product.php" class="btn-primary shadow-lg shadow-pink-100">
            <i class="fas fa-plus-circle"></i> <span>Catalog Item</span>
        </a>
    </div>

    <div class="inventory-card">
        <div class="overflow-x-auto">
            <table>
                <thead>
                    <tr>
                        <th>Artifact</th>
                        <th>Identity</th>
                        <th>Scan Code</th>
                        <th>Classification</th>
                        <th>Valuation</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th style="text-align: right;">Operations</th>
                    </tr>
                </thead>
                <tbody id="product-list">
                    <tr><td colspan='8' style='text-align:center; padding: 60px;' class='text-gray-300 font-medium italic'>Synchronizing inventory records...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const productList = document.getElementById('product-list');

        // Real-time listener for inventory
        getBranchPath('inventory').orderBy('name').onSnapshot(snap => {
            if (snap.empty) {
                productList.innerHTML = "<tr><td colspan='8' style='text-align:center; padding: 60px;' class='text-gray-300 font-medium'>No inventory units mapped to this branch.</td></tr>";
                return;
            }

            let html = '';
            snap.forEach(doc => {
                const data = doc.data();
                if (data.isDeleted === true || data.status === 'archived') {
                    return;
                }
                const id = doc.id;
                const stock = parseInt(data.stock || 0);
                
                let statusClass = "in-stock";
                let statusText = "Stable";
                if (stock <= 0) {
                    statusClass = "out-stock";
                    statusText = "Depleted";
                } else if (stock <= 10) {
                    statusClass = "low-stock";
                    statusText = "Critical";
                }

                const img = data.image ? data.image : 'https://via.placeholder.com/150?text=No+Image';

                html += `
                <tr>
                    <td><img src="${img}" class="product-img" onerror="this.src='https://via.placeholder.com/50'"></td>
                    <td>
                        <div style="font-weight: 700; color: var(--text-main); font-size: 0.95rem;">${data.name || 'Unnamed'}</div>
                    </td>
                    <td>
                        <svg id="barcode-${id}"></svg>
                    </td>
                    <td><span style="font-size: 0.8rem; font-weight: 600; color: var(--text-light);">${data.category || 'Standard'}</span></td>
                    <td style="font-weight: 800; color: var(--primary);">₱${parseFloat(data.price || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    <td style="font-weight: 700;">${stock} units</td>
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                    <td class="action-icons" style="text-align: right;">
                        <a href="edit_product.php?id=${id}" class="edit-icon" title="Edit Master Data"><i class="fas fa-pen-nib"></i></a>
                        <a href="spoilage_tracking.php?productId=${id}" class="spoil-icon" title="Log Spoilage Incident"><i class="fas fa-dumpster"></i></a>
                        <a href="#" onclick="archiveProduct('${id}')" class="archive-icon" title="Deactivate & Archive Item"><i class="fas fa-box-archive"></i></a>
                    </td>
                </tr>
                `;
            });

            productList.innerHTML = html;

            // Generate Barcodes
            setTimeout(() => {
                snap.forEach(doc => {
                    const data = doc.data();
                    if (data.code) {
                        try {
                            JsBarcode(`#barcode-${doc.id}`, data.code, {
                                format: "CODE128",
                                height: 20,
                                width: 1,
                                displayValue: false
                            });
                        } catch (e) {}
                    }
                });
            }, 100);
        });
    });

    async function archiveProduct(id) {
        if (confirm('Are you sure you want to deactivate/archive this product? This will hide it from the active inventory and selling screens, but preserve its historical record for sales reports.')) {
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
            } catch (e) {
                alert('Error archiving product: ' + e.message);
            }
        }
    }
</script>

<?php include 'templates/footer.php'; ?>
