<?php 
/**
 * BLOOMINOUS - Supplier Management (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SECURITY CHECK
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php"); 
    exit();
}

include 'templates/header.php'; 
?>

<style>
    .supplier-container { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    .header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 3rem; }
    .table-card { background: white; border-radius: 35px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.02); overflow: hidden; border: 1px solid #f0f0f0; }
    table { width: 100%; border-collapse: collapse; }
    th { color: var(--text-light); text-transform: uppercase; font-size: 0.75rem; padding: 25px 20px; border-bottom: 1px solid #f0f0f0; text-align: left; font-weight: 800; letter-spacing: 1px; background: #fafafa; }
    td { padding: 20px; border-bottom: 1px solid #f8f9fa; vertical-align: middle; color: var(--text-main); font-weight: 500; font-size: 0.9rem; }
    .btn-edit { background: rgba(123, 121, 242, 0.1); color: var(--secondary); width: 35px; height: 35px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; margin-right: 10px; transition: 0.2s; }
    .btn-delete { background: rgba(233, 30, 99, 0.1); color: var(--primary); width: 35px; height: 35px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; cursor: pointer; border: none; transition: 0.2s; }
    .btn-edit:hover, .btn-delete:hover { transform: scale(1.1); filter: brightness(0.9); }
</style>

<div class="supplier-container">
    <div class="header-section">
        <div>
            <h1 class="brand-font text-4xl font-black text-gray-800">Suppliers</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Manage your flower suppliers and contact details.</p>
        </div>
        <a href="add_supplier.php" class="btn-primary flex items-center px-8 shadow-lg shadow-pink-100 uppercase tracking-widest text-xs font-black">
            <i class="fas fa-plus mr-3"></i> Enlist Provider
        </a>
    </div>

    <div class="table-card shadow-sm">
        <table>
            <thead>
                <tr>
                    <th>Supplier Name</th>
                    <th>Contact Person</th>
                    <th>Phone Number</th>
                    <th>Address</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="supplierData">
                <tr><td colspan='5' style='text-align:center; padding: 50px;' class='text-muted'>Loading suppliers...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const supplierData = document.getElementById('supplierData');

        // Real-time listener for suppliers
        db.collection('suppliers').onSnapshot(snap => {
            if (snap.empty) {
                supplierData.innerHTML = "<tr><td colspan='5' style='text-align:center; padding: 50px;' class='text-muted'>No suppliers found. Click 'Add New Supplier' to start.</td></tr>";
                return;
            }

            let html = '';
            snap.forEach(doc => {
                const s = doc.data();
                const id = doc.id;
                
                html += `
                <tr>
                    <td><strong>${s.supplier_name}</strong></td>
                    <td>${s.contact_person || 'N/A'}</td>
                    <td><span class="text-muted">${s.phone || 'N/A'}</span></td>
                    <td><small>${s.address || 'N/A'}</small></td>
                    <td>
                        <a href="edit_supplier.php?id=${id}" class="btn-edit" title="Edit"><i class="fas fa-edit"></i></a>
                        <button onclick="deleteSupplier('${id}')" class="btn-delete" title="Delete"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
                `;
            });
            supplierData.innerHTML = html;
        });
    });

    async function deleteSupplier(id) {
        if (confirm('Are you sure you want to delete this supplier?')) {
            try {
                await db.collection('suppliers').doc(id).delete();
                // Notification is handled by the onSnapshot listener automatically
            } catch (e) {
                alert('Error: ' + e.message);
            }
        }
    }
</script>

<?php include 'templates/footer.php'; ?>
