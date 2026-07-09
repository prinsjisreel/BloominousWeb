<?php 
/**
 * BLOOMINOUS - Delivery Status Tracking (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php'; 
?>

<style>
    .delivery-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    
    .header-area { margin-bottom: 3.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; }
    
    .table-card { background: #fff; border-radius: 35px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; overflow: hidden; }

    table { width: 100%; border-collapse: collapse; }
    th { color: var(--text-light); font-size: 0.75rem; text-transform: uppercase; font-weight: 800; padding: 25px 20px; border-bottom: 1px solid #f0f0f0; text-align: left; letter-spacing: 1px; background: #fafafa; }
    td { padding: 20px; color: var(--text-main); font-size: 0.9rem; border-bottom: 1px solid #f8f9fa; transition: 0.2s; font-weight: 500; }
    tr:hover td { background: #fafafa; }

    .badge { padding: 6px 16px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
    .badge-pending { background: #fff9e6; color: #ffbb55; }
    .badge-transit { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .badge-delivered { background: rgba(46, 204, 113, 0.1); color: #2ecc71; }

    .status-select {
        padding: 10px 14px;
        border-radius: 12px;
        border: 1px solid #f0f0f0;
        background: #fafafa;
        font-size: 0.75rem;
        font-weight: 800;
        color: var(--text-main);
        outline: none;
        transition: 0.3s;
        cursor: pointer;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .status-select:focus { border-color: var(--primary); background: #fff; }

    .order-id { font-family: 'Inter', sans-serif; font-weight: 900; color: var(--primary); font-size: 0.85rem; letter-spacing: 0.5px; }
</style>

<main class="delivery-content">
    <div class="header-area">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Delivery Status</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Track and update order delivery statuses.</p>
        </div>
        <div class="bg-white px-8 py-4 border border-gray-100 rounded-3xl font-black text-xs text-gray-800 uppercase tracking-widest shadow-sm flex items-center">
            <i class="fa-solid fa-truck-fast text-pink-500 mr-3 text-sm animate-bounce"></i> Real-time Fleet Status
        </div>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer Name</th>
                    <th>Order Date</th>
                    <th>Delivery Status</th>
                    <th>Update Status</th>
                </tr>
            </thead>
            <tbody id="deliveryData">
                <tr><td colspan="5" style="text-align: center; padding: 60px;" class="text-muted">
                    <i class="fa-solid fa-spinner fa-spin fa-2x mb-3" style="color: #7B79F2;"></i><br>
                    Syncing with logistics database...
                </td></tr>
            </tbody>
        </table>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const deliveryData = document.getElementById('deliveryData');

        // Real-time listener for orders filtered by branch
        db.collection('orders').where('branchId', '==', window.currentBranch).onSnapshot(snap => {
            if (snap.empty) {
                deliveryData.innerHTML = "<tr><td colspan='5' style='text-align: center; padding: 60px;' class='text-muted'>No active deliveries found.</td></tr>";
                return;
            }

            let html = '';
            // Manual sort in JS to be safe
            const docs = snap.docs.sort((a, b) => {
                const timeA = a.data().timestamp ? a.data().timestamp.toMillis() : 0;
                const timeB = b.data().timestamp ? b.data().timestamp.toMillis() : 0;
                return timeB - timeA;
            });

            docs.forEach(doc => {
                const o = doc.data();
                const id = doc.id;
                
                // Harmonize status checks (Checking both 'status' and 'delivery_status' fields)
                const status = (o.status || "").toLowerCase();
                const deliveryStatusRaw = (o.delivery_status || "Pending").toLowerCase();
                
                // Decide what to display
                let displayStatus = "Pending";
                if (deliveryStatusRaw.includes('transit') || status.includes('delivery') || status.includes('progress')) {
                    displayStatus = "In Transit";
                } else if (deliveryStatusRaw === 'delivered' || status === 'completed') {
                    displayStatus = "Delivered";
                } else if (status === 'pending' || deliveryStatusRaw === 'pending') {
                    displayStatus = "Pending";
                } else if (status === 'processing') {
                    displayStatus = "Processing";
                }

                const customerName = o.customer_name || o.customerName || 'Guest Customer';
                const date = o.timestamp ? o.timestamp.toDate().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Recently';

                let badgeClass = 'badge-pending';
                if (displayStatus === 'In Transit' || displayStatus === 'Processing') badgeClass = 'badge-transit';
                if (displayStatus === 'Delivered') badgeClass = 'badge-delivered';

                html += `
                <tr>
                    <td><span class="order-id">#${id.substring(0, 8).toUpperCase()}</span></td>
                    <td style="font-weight: 600;">${customerName}</td>
                    <td style="color: #7d8da1;">${date}</td>
                    <td>
                        <span class="badge ${badgeClass}">
                            ${displayStatus}
                        </span>
                    </td>
                    <td>
                        <select onchange="updateDeliveryStatus('${id}', this.value)" class="status-select">
                            <option value="Pending" ${displayStatus === 'Pending' ? 'selected' : ''}>Pending</option>
                            <option value="Processing" ${displayStatus === 'Processing' ? 'selected' : ''}>Processing</option>
                            <option value="In Transit" ${displayStatus === 'In Transit' ? 'selected' : ''}>In Transit</option>
                            <option value="Delivered" ${displayStatus === 'Delivered' ? 'selected' : ''}>Delivered</option>
                        </select>
                    </td>
                </tr>
                `;
            });
            deliveryData.innerHTML = html;
        });
    });

    async function updateDeliveryStatus(orderId, newStatus) {
        if (!newStatus) return;
        
        try {
            await db.collection('orders').doc(orderId).update({
                delivery_status: newStatus,
                updated_at: firebase.firestore.FieldValue.serverTimestamp()
            });
            // Optional: Show a small toast notification instead of alert
            console.log(`Order ${orderId} updated to ${newStatus}`);
        } catch (err) {
            alert('Error updating status: ' + err.message);
        }
    }
</script>

<?php include 'templates/footer.php'; ?>
