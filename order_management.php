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
    .customer-avatar { width: 45px; height: 45px; background: #fff5f8; color: var(--primary); border: 1px solid rgba(233, 30, 99, 0.1); border-radius: 15px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.1rem; font-family: 'Cormorant Garamond', serif; }
    .status-select { padding: 10px 14px; border-radius: 12px; font-size: 0.75rem; font-weight: 800; border: 1px solid #f0f0f0; background: #fafafa; outline: none; cursor: pointer; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; transition: 0.3s; }
    .status-select:focus { border-color: var(--primary); background: #fff; }
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
        </div>
    </div>

    <div class="analytics-grid">
        <div class="stat-card">
            <i class="fa-solid fa-shopping-bag pink-bg"></i>
            <div>
                <p class="text-xs uppercase tracking-wider font-bold text-gray-400">Commerce Volume</p>
                <h3 class="brand-font text-3xl font-black" id="totalOrders">...</h3>
            </div>
        </div>
        <div class="stat-card">
            <i class="fa-solid fa-clock-rotate-left indigo-bg"></i>
            <div>
                <p class="text-xs uppercase tracking-wider font-bold text-gray-400">Pending Resolution</p>
                <h3 class="brand-font text-3xl font-black" id="pendingOrders">...</h3>
            </div>
        </div>
        <div class="stat-card">
            <i class="fa-solid fa-chart-line teal-bg"></i>
            <div>
                <p class="text-xs uppercase tracking-wider font-bold text-gray-400">Liquidity Pipeline</p>
                <h3 class="brand-font text-3xl font-black" id="totalSales">...</h3>
            </div>
        </div>
    </div>

    <div class="list-card">
        <div class="p-8 border-b border-gray-50 bg-gray-50/30">
            <h4 class="brand-font text-2xl font-black text-gray-800">Live Transaction Log</h4>
        </div>
        <div class="overflow-x-auto">
            <table id="orderTable">
                <thead>
                    <tr>
                        <th>Clientele</th>
                        <th>Payload Summary</th>
                        <th>Valuation</th>
                        <th>Timeline</th>
                        <th>Status Selection</th>
                    </tr>
                </thead>
                <tbody id="orderData">
                    <tr><td colspan='5' class='text-center p-12 text-gray-300 italic font-medium'>Connecting to transaction server...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', async () => {
        const orderData = document.getElementById('orderData');
        const userMap = {};

        // Pre-fetch name mappings
        const usersSnap = await db.collection('users').get();
        usersSnap.forEach(d => userMap[d.id] = d.data().username || d.data().firstName || '');
        const custSnap = await db.collection('customers').get();
        custSnap.forEach(d => userMap[d.id] = d.data().name || d.data().fullName || '');

        db.collection('orders').where('branchId', '==', window.currentBranch).onSnapshot(snap => {
            if (snap.empty) {
                orderData.innerHTML = "<tr><td colspan='5' class='text-center p-10 text-gray-400'>No active branch records found.</td></tr>";
                return;
            }
            let html = '';
            let totalCount = snap.size, pendingCount = 0, salesTotal = 0;
            const docs = [];
            
            snap.forEach(doc => docs.push({id: doc.id, ...doc.data()}));
            docs.sort((a,b) => ((b.createdAt || b.timestamp)?.seconds || 0) - ((a.createdAt || a.timestamp)?.seconds || 0));

            docs.forEach(data => {
                const status = (data.status || 'pending').toLowerCase();
                const amount = parseFloat(data.total_price || data.total_amount || 0);
                
                if (status === 'pending') pendingCount++;
                if (status === 'completed' || status === 'delivered') salesTotal += amount;

                const dateObj = data.createdAt || data.timestamp;
                const date = dateObj ? dateObj.toDate().toLocaleDateString() : 'N/A';
                const itemsSummary = (data.items || []).map(i => `${i.name} (${i.qty})`).join(', ');
                const uid = data.user_id || '';
                const customerName = data.customer_name || userMap[uid] || 'Walk-in Customer';
                const avatarInitial = customerName.charAt(0).toUpperCase();

                html += `
                <tr>
                    <td>
                        <div class="flex items-center gap-4">
                            <div class="customer-avatar">${avatarInitial}</div>
                            <span class="font-bold text-gray-800">${customerName}</span>
                        </div>
                    </td>
                    <td class="max-w-[250px] text-xs font-semibold text-gray-400 truncate">${itemsSummary}</td>
                    <td class="font-black text-pink-600">₱${amount.toLocaleString(undefined, {minimumFractionDigits:2})}</td>
                    <td class="text-gray-400 font-semibold text-xs">${date}</td>
                    <td>
                        <select class="status-select" onchange="secureStatusTransition('${data.id}', '${uid}', this.value)">
                            <option value="pending" ${status === 'pending' ? 'selected' : ''}>Pending</option>
                            <option value="confirmed" ${status === 'confirmed' ? 'selected' : ''}>Confirmed</option>
                            <option value="processing" ${status === 'processing' ? 'selected' : ''}>Processing</option>
                            <option value="delivered" ${status === 'delivered' ? 'selected' : ''}>Delivered</option>
                            <option value="cancelled" ${status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                        </select>
                    </td>
                </tr>`;
            });

            orderData.innerHTML = html;
            document.getElementById('totalOrders').innerText = totalCount;
            document.getElementById('pendingOrders').innerText = pendingCount;
            document.getElementById('totalSales').innerText = '₱' + salesTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
        });

        document.getElementById('orderSearch').addEventListener('keyup', function() {
            let val = this.value.toLowerCase();
            document.querySelectorAll('#orderData tr').forEach(r => {
                r.style.display = r.innerText.toLowerCase().includes(val) ? '' : 'none';
            });
        });
    });

    // --- SECURED ROLLING COOLDOWN CANCELLATION TIME-WINDOW MATRIX ---
    async function secureStatusTransition(orderId, customerId, newStatus) {
        try {
            // 1. Mutate the specific target order item status state
            await db.collection('orders').doc(orderId).update({ status: newStatus });

            // 2. Compute dynamic cancellation frequency windows server/client-side safely
            if (newStatus === 'cancelled' && customerId) {
                // Fetch the customer's total history by UID only to guarantee index compliance
                const userOrdersSnapshot = await db.collection('orders')
                    .where('user_id', '==', customerId)
                    .get();

                if (!userOrdersSnapshot.empty) {
                    const rightNowMs = Date.now();
                    const thirtyDaysAgoMs = rightNowMs - (30 * 24 * 60 * 60 * 1000); // Strict 30-Day moving threshold window
                    let activeRollingStrikeCount = 0;

                    userOrdersSnapshot.forEach(oDoc => {
                        const oData = oDoc.data();
                        const statusMatch = (oData.status || '').toLowerCase() === 'cancelled';
                        const oTimestamp = oData.createdAt || oData.timestamp;

                        if (statusMatch && oTimestamp) {
                            const orderTimeMs = oTimestamp.toDate().getTime();
                            // Increment strictly if the cancellation fell inside the rolling 30-day barrier
                            if (orderTimeMs >= thirtyDaysAgoMs && orderTimeMs <= rightNowMs) {
                                activeRollingStrikeCount++;
                            }
                        }
                    });

                    // Invoke automated restriction only if velocity limit is broken inside the current lookback slot
                    if (activeRollingStrikeCount >= 3) {
                        const expiryDate = new Date();
                        expiryDate.setDate(expiryDate.getDate() + 30); // 30-Day Soft Penalty Window

                        await db.collection('customers').doc(customerId).update({
                            fraudScore: 100, 
                            isRestricted: true,
                            restrictedUntil: firebase.firestore.Timestamp.fromDate(expiryDate),
                            fraudFlags: firebase.firestore.FieldValue.arrayUnion("Velocity Check Violation: Exceeded 3 transaction cancellations within a 30-day moving window.")
                        });
                        console.log(`[SECURITY MITIGATION] Restricted client token ${customerId} via lookback velocity rules.`);
                    }
                }
            }
        } catch (e) {
            alert('Fulfillment Mutation Rejected: ' + e.message);
        }
    }
</script>
<?php include 'templates/footer.php'; ?>