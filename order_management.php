<?php 
/**
 * BLOOMINOUS - Order Management (Firebase Spoke)
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
    .order-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 3rem; }
    .stat-card { background: #fff; padding: 2.5rem; border-radius: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 20px; border: 1px solid #f0f0f0; }
    .stat-card i { font-size: 1.5rem; width: 65px; height: 65px; border-radius: 20px; display: flex; align-items: center; justify-content: center; }
    .pink-bg { background: rgba(233, 30, 99, 0.1); color: var(--primary); }
    .indigo-bg { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .teal-bg { background: rgba(0, 206, 209, 0.1); color: #00ced1; }
    
    .list-card { background: #fff; border-radius: 35px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; overflow: hidden; }
    
    #orderTable { width: 100%; border-collapse: collapse; }
    #orderTable th { text-align: left; padding: 25px 20px; color: var(--text-light); border-bottom: 1px solid #f0f0f0; text-transform: uppercase; font-size: 0.75rem; font-weight: 800; letter-spacing: 1px; background: #fafafa; }
    #orderTable td { padding: 20px; border-bottom: 1px solid #f8f9fa; color: var(--text-main); font-size: 0.9rem; font-weight: 500; }
    
    .pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
    .pill-cloud { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
    .pill-offline { background: rgba(233, 30, 99, 0.1); color: var(--primary); }
    
    .customer-info { display: flex; align-items: center; gap: 15px; }
    .customer-avatar { width: 45px; height: 45px; background: #fff5f8; color: var(--primary); border: 1px solid rgba(233, 30, 99, 0.1); border-radius: 15px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.1rem; font-family: 'Cormorant Garamond', serif; }
    
    .status-select { padding: 10px 14px; border-radius: 12px; font-size: 0.75rem; font-weight: 800; border: 1px solid #f0f0f0; background: #fafafa; outline: none; cursor: pointer; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; transition: 0.3s; }
    .status-select:focus { border-color: var(--primary); background: #fff; }

    .btn-action { width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; border: none; background: #fff5f8; color: var(--primary); cursor: pointer; transition: 0.3s; }
    .btn-action:hover { background: var(--primary); color: white; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(233, 30, 99, 0.15); }
</style>

<main class="order-content">
    <div class="flex justify-between items-center mb-12">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Orders</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Track and manage customer orders.</p>
        </div>
        <div class="flex gap-4 items-center">
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                <input type="text" id="orderSearch" class="bg-white border border-gray-100 rounded-2xl px-12 py-3 text-sm outline-none focus:border-pink-300 transition-all w-80 shadow-sm" placeholder="Filter Manifests...">
            </div>
            <button class="btn-primary flex items-center px-8 shadow-lg shadow-pink-100 uppercase tracking-widest text-xs font-black" onclick="exportOrders()"><i class="fa-solid fa-file-export mr-3"></i> Sync Export</button>
        </div>
    </div>

    <div class="analytics-grid">
        <div class="stat-card">
            <i class="fa-solid fa-bolt-lightning pink-bg"></i>
            <div>
                <p class="label-text">Commerce Volume</p>
                <h3 class="amount-text" id="totalOrders">...</h3>
            </div>
        </div>
        <div class="stat-card">
            <i class="fa-solid fa-clock-rotate-left indigo-bg"></i>
            <div>
                <p class="label-text">Pending Resolution</p>
                <h3 class="amount-text" id="pendingOrders">...</h3>
            </div>
        </div>
        <div class="stat-card">
            <i class="fa-solid fa-chart-line teal-bg"></i>
            <div>
                <p class="label-text">Liquidity Pipeline</p>
                <h3 class="amount-text" id="totalSales">...</h3>
            </div>
        </div>
    </div>

    <div class="list-card">
        <div class="p-8 border-b border-gray-50 flex justify-between items-center bg-gray-50/30">
            <h4 class="brand-font text-2xl font-black text-gray-800">Live Transaction Log</h4>
        </div>
        <div class="overflow-x-auto">
            <table id="orderTable">
                <thead>
                    <tr>
                        <th>Clientele</th>
                        <th>Payload</th>
                        <th>Valuation</th>
                        <th>Timeline</th>
                        <th>Status</th>
                        <th style="text-align: right;">Operations</th>
                    </tr>
                </thead>
                <tbody id="orderData">
                    <tr><td colspan='6' style='text-align:center; padding: 60px;' class='text-gray-300 font-medium italic'>Connecting to transaction server...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', async () => {
        const orderData = document.getElementById('orderData');

        // Fetch all users and customers to build a fast name-map cache
        const userMap = {};
        async function fetchUserMap() {
            try {
                const usersSnap = await db.collection('users').get();
                usersSnap.forEach(doc => {
                    const d = doc.data();
                    const name = d.username || d.firstName || d.name || d.email || '';
                    if (name) userMap[doc.id] = name;
                });
                const custSnap = await db.collection('customers').get();
                custSnap.forEach(doc => {
                    const d = doc.data();
                    const name = d.name || d.username || d.email || '';
                    if (name) userMap[doc.id] = name;
                });
            } catch (e) {
                console.error("Error loading user name mapping:", e);
            }
        }

        await fetchUserMap();

        // Real-time listener for orders filtered by branch
        db.collection('orders').where('branchId', '==', window.currentBranch).onSnapshot(snap => {
            if (snap.empty) {
                orderData.innerHTML = "<tr><td colspan='6' style='text-align:center; padding: 40px;' class='text-muted'>No orders found.</td></tr>";
                return;
            }

            let html = '';
            let totalCount = snap.size;
            let pendingCount = 0;
            let salesTotal = 0;

            // Sort manually if needed, or just process
            const docs = [];
            snap.forEach(doc => docs.push({id: doc.id, ...doc.data()}));
            
            // Sort by createdAt or timestamp descending
            docs.sort((a, b) => {
                const timeA = a.createdAt || a.timestamp || 0;
                const timeB = b.createdAt || b.timestamp || 0;
                return (timeB.seconds || 0) - (timeA.seconds || 0);
            });

            docs.forEach(data => {
                const id = data.id;
                // Check root status or status in the first item (Flutter app structure)
                const status = (data.status || (data.items && data.items[0] ? data.items[0].status : 'pending')).toLowerCase();
                const statusClass = status.replace(/\s+/g, '-');
                
                if (status === 'pending') pendingCount++;
                
                const amount = parseFloat(data.totalAmount || data.total_amount || data.total_price || (data.items && data.items[0] ? data.items[0].totalAmount || data.items[0].total_amount : 0) || 0);
                
                if (status === 'completed' || status === 'delivered') {
                    salesTotal += amount;
                }

                const dateObj = data.createdAt || data.timestamp;
                const date = dateObj ? dateObj.toDate().toLocaleDateString() : 'N/A';
                const items = (data.items || []).map(i => `${i.name} (${i.qty || 1})`).join(', ');
                
                // Sync status icon
                const syncPill = data.isSynced === false 
                    ? '<span class="pill pill-offline"><i class="fa-solid fa-cloud-arrow-up"></i> OFFLINE</span>' 
                    : '<span class="pill pill-cloud"><i class="fa-solid fa-cloud"></i> CLOUD</span>';

                // Resolve user name using mapped cache
                const uid = data.user_id || data.userId || data.customer_id || data.customerId || '';
                const registeredName = userMap[uid] || '';
                const customerName = data.customer_name || data.customerName || registeredName || data.customer_id || data.customerName || 'Guest Customer';
                
                const recipientName = data.recipientName || '';
                const avatarInitial = customerName.charAt(0).toUpperCase();

                html += `
                <tr>
                    <td>
                        <div class="customer-info">
                            <div class="customer-avatar">${avatarInitial}</div>
                            <div>
                                <span style="font-weight: 700; display: block;" class="text-gray-800">${customerName}</span>
                                ${recipientName && recipientName !== customerName ? `
                                <span style="font-size: 0.75rem; font-weight: 600;" class="text-pink-500 flex items-center gap-1 mt-0.5">
                                    <i class="fa-solid fa-gift text-[9px]"></i> For: ${recipientName}
                                </span>
                                ` : ''}
                            </div>
                        </div>
                    </td>
                    <td style="max-width: 250px; font-size: 0.8rem; color: var(--text-light); font-weight: 600;">${items}</td>
                    <td style="font-weight: 800; color: var(--primary);">₱${amount.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    <td><span style="color: var(--text-light); font-weight: 600;">${date}</span></td>
                    <td>
                        <select class="status-select" onchange="updateStatus('${id}', this.value)">
                            <option value="pending" ${status === 'pending' ? 'selected' : ''}>Pending</option>
                            <option value="confirmed" ${status === 'confirmed' ? 'selected' : ''}>Confirmed</option>
                            <option value="processing" ${status === 'processing' ? 'selected' : ''}>Processing</option>
                            <option value="out for delivery" ${status === 'out for delivery' ? 'selected' : ''}>Out for Delivery</option>
                            <option value="delivered" ${status === 'delivered' ? 'selected' : ''}>Delivered</option>
                            <option value="cancelled" ${status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                        </select>
                    </td>
                    <td style="text-align: right;">
                        <button onclick="deleteOrder('${id}')" class="btn-action" title="Void Transaction">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </td>
                </tr>
                `;
            });

            orderData.innerHTML = html;
            document.getElementById('totalOrders').innerText = totalCount;
            document.getElementById('pendingOrders').innerText = pendingCount;
            document.getElementById('totalSales').innerText = '₱' + salesTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
        });

        // Search functionality
        document.getElementById('orderSearch').addEventListener('keyup', function() {
            let val = this.value.toLowerCase();
            let rows = document.querySelectorAll('#orderData tr');
            rows.forEach(r => {
                r.style.display = r.innerText.toLowerCase().includes(val) ? '' : 'none';
            });
        });
    });

    async function updateStatus(id, newStatus) {
        try {
            await db.collection('orders').doc(id).update({ status: newStatus });
            // Notification is handled by the onSnapshot listener automatically
        } catch (e) {
            alert('Error updating status: ' + e.message);
        }
    }

    async function deleteOrder(id) {
        if (confirm('Are you sure you want to delete this order? This action cannot be undone.')) {
            try {
                await db.collection('orders').doc(id).delete();
            } catch (e) {
                alert('Error deleting order: ' + e.message);
            }
        }
    }
</script>

<?php include 'templates/footer.php'; ?>
