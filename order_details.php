<?php
/**
 * BLOOMINOUS - Order Details (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

// Get order details
$order_id = $_GET['id'] ?? null;
if (!$order_id) {
    header("Location: order_management.php");
    exit();
}

include 'templates/header.php';
?>

<style>
    .details-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    .details-grid { display: grid; grid-template-columns: 1fr 400px; gap: 2.5rem; }
    
    .card { background: #fff; border-radius: 35px; padding: 2.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.02); margin-bottom: 2.5rem; border: 1px solid #f0f0f0; }
    .card-title { font-size: 0.7rem; font-weight: 800; color: #ccc; margin-bottom: 2rem; text-transform: uppercase; letter-spacing: 2px; display: flex; align-items: center; gap: 12px; }
    .card-title i { color: var(--primary); font-size: 1rem; }
    
    .item-row { display: flex; align-items: center; gap: 25px; padding: 20px 0; border-bottom: 1px solid #f8f9fa; }
    .item-row:last-child { border-bottom: none; }
    .item-img { width: 85px; height: 85px; border-radius: 25px; object-fit: cover; box-shadow: 0 8px 20px rgba(0,0,0,0.04); border: 1px solid #f0f0f0; }
    
    .status-select { width: 100%; padding: 18px 22px; border-radius: 20px; border: 1px solid #f0f0f0; background: #fafafa; outline: none; margin-bottom: 1.5rem; font-weight: 700; color: var(--text-main); transition: 0.4s; font-size: 0.95rem; }
    .status-select:focus { border-color: var(--primary); background: #fff; box-shadow: 0 10px 25px rgba(233, 30, 99, 0.05); }
    
    .btn-update { background: var(--primary); color: #fff; border: none; padding: 18px; border-radius: 20px; width: 100%; font-weight: 900; cursor: pointer; transition: 0.4s; box-shadow: 0 15px 35px rgba(233, 30, 99, 0.2); text-transform: uppercase; letter-spacing: 2px; font-size: 0.85rem; }
    .btn-update:hover { background: #d81b60; transform: translateY(-3px); box-shadow: 0 20px 45px rgba(233, 30, 99, 0.3); }
    
    .badge { padding: 8px 18px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; }
    .badge-pending { background: #fff9e6; color: #f39c12; }
    .badge-paid { background: rgba(46, 204, 113, 0.1); color: #2ecc71; }
    .badge-shipped { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .badge-delivered { background: #e6fff6; color: #2ecc71; }
    .badge-cancelled { background: #fff5f8; color: var(--primary); }

    .info-label { color: #ccc; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 8px; }
    .info-value { color: var(--text-main); font-weight: 700; font-size: 1rem; line-height: 1.6; }

    .back-link { display: inline-flex; align-items: center; gap: 10px; color: var(--primary); text-decoration: none; font-weight: 800; margin-bottom: 2.5rem; transition: 0.3s; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 2px; }
    .back-link:hover { transform: translateX(-5px); filter: brightness(1.2); }

    @media (max-width: 992px) {
        .details-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="details-content">
    <a href="order_management.php" class="back-link">
        <i class="fa-solid fa-chevron-left"></i> Order Registry
    </a>

    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-16 gap-8">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Telemetry Summary</h1>
            <p class="text-gray-400 font-bold text-xs uppercase tracking-widest mt-2 italic">Real-time fulfillment and transaction forensics</p>
        </div>
        <div id="statusBadgeContainer">
            <span class="badge badge-pending shadow-lg" id="orderStatusBadge">Loading...</span>
        </div>
    </div>

    <div class="details-grid">
        <div class="left-col">
            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-shopping-bag"></i> manifest list</h3>
                <div id="orderItemsList">
                    <p style="text-align: center; padding: 80px;" class="text-gray-300 italic font-medium">
                        Synchronizing manifest data...
                    </p>
                </div>
                
                <div class="mt-12 pt-12 border-t border-gray-50 flex justify-between items-center">
                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Aggregate Valuation</span>
                    <span class="brand-font text-5xl font-black text-gray-800" id="totalPrice">₱0.00</span>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-location-dot"></i> Logistical Coordinates</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                    <div>
                        <p class="info-label">Entity Name</p>
                        <p class="info-value font-black" id="customerName">...</p>
                    </div>
                    <div>
                        <p class="info-label">Access Endpoint</p>
                        <p class="info-value" id="customerEmail">...</p>
                    </div>
                    <div class="md:col-span-2">
                        <p class="info-label">Spatial Destination</p>
                        <p class="info-value" id="deliveryAddress">...</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="right-col">
            <div class="card bg-pink-50/20 border-pink-100">
                <h3 class="card-title mb-6"><i class="fa-solid fa-toggle-on"></i> Control State</h3>
                <form id="updateStatusForm">
                    <select id="statusSelect" class="status-select">
                        <option value="Pending">Pending</option>
                        <option value="Paid">Paid</option>
                        <option value="Shipped">Shipped</option>
                        <option value="Delivered">Delivered</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                    <button type="submit" id="updateBtn" class="btn-update">
                        Update Reality
                    </button>
                </form>
            </div>

            <div class="card">
                <h3 class="card-title"><i class="fa-solid fa-fingerprint"></i> transaction metadata</h3>
                <div class="flex flex-col gap-8">
                    <div>
                        <p class="info-label">Protocol</p>
                        <p class="info-value font-black text-indigo-500">PAYMONGO_GATEWAY</p>
                    </div>
                    
                    <div>
                        <p class="info-label">Injection Timestamp</p>
                        <p class="info-value" id="transactionDate">...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const orderId = "<?php echo $order_id; ?>";

    document.addEventListener('DOMContentLoaded', () => {
        // Real-time listener for order details
        db.collection('orders').doc(orderId).onSnapshot(doc => {
            if (!doc.exists) {
                alert('Order not found!');
                window.location.href = 'order_management.php';
                return;
            }
            const o = doc.data();
            const status = o.status || 'Pending';
             
            document.getElementById('orderStatusBadge').innerText = status;
            
            // Update badge class
            const badge = document.getElementById('orderStatusBadge');
            badge.className = 'badge badge-' + status.toLowerCase();
            
            document.getElementById('statusSelect').value = status;
            document.getElementById('totalPrice').innerText = '₱' + parseFloat(o.total_price || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('customerName').innerText = o.customer_name || 'Guest Customer';
            document.getElementById('customerEmail').innerText = o.customer_email || 'N/A';
            document.getElementById('deliveryAddress').innerText = o.address || 'No delivery address provided';
            
            const date = o.timestamp ? o.timestamp.toDate().toLocaleString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric', 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: true 
            }) : 'N/A';
            document.getElementById('transactionDate').innerText = date;

            // Load Items
            let itemsHtml = '';
            if (o.items && o.items.length > 0) {
                o.items.forEach(item => {
                    itemsHtml += `
                    <div class="item-row">
                        <img src="${item.image || 'https://picsum.photos/seed/flower/100/100'}" class="item-img" alt="Product">
                        <div style="flex: 1;">
                            <h4 style="font-weight: 800; color: #363949; margin: 0; font-size: 1rem;">${item.name}</h4>
                            <p style="font-size: 0.75rem; color: #b2bec3; font-weight: 600; margin-top: 3px;">
                                ₱${parseFloat(item.price || 0).toLocaleString(undefined, {minimumFractionDigits: 2})} per unit
                            </p>
                        </div>
                        <div style="text-align: center; padding: 0 20px;">
                            <span style="color: #7d8da1; font-weight: 700; font-size: 0.85rem;">x${item.qty}</span>
                        </div>
                        <div style="text-align: right; min-width: 100px;">
                            <span style="font-weight: 800; color: #363949;">₱${(parseFloat(item.price || 0) * parseInt(item.qty || 0)).toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                        </div>
                    </div>
                    `;
                });
            } else {
                itemsHtml = '<div style="text-align: center; padding: 40px; color: #b2bec3; font-weight: 600;">No items found in this order.</div>';
            }
            document.getElementById('orderItemsList').innerHTML = itemsHtml;
        });

        document.getElementById('updateStatusForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('updateBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Updating...';

            try {
                await db.collection('orders').doc(orderId).update({
                    status: document.getElementById('statusSelect').value,
                    updated_at: firebase.firestore.FieldValue.serverTimestamp()
                });
                // Optional: Show a toast instead of alert
                console.log('Order status updated successfully');
            } catch (err) {
                alert('Error updating order: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-2"></i> Save Status';
            }
        };
    });
</script>

<?php include 'templates/footer.php'; ?>
