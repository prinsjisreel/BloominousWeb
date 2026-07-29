<?php 
/**
 * BLOOMINOUS - Delivery Status Monitoring (Firebase Spoke)
 *
 * Read-only. This page never writes to `orders` — it only displays each online
 * order's current delivery status and lets staff view the rider's uploaded Proof
 * of Delivery photo. Status changes (Pending -> Processing -> In Transit ->
 * Confirmed) happen from Order Management, which is the single place that owns
 * order status for both online and walk-in orders. Keeping the write path in one
 * place is what removed the duplicate status dropdown that used to live here too.
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
    .badge-confirmed { background: rgba(46, 204, 113, 0.1); color: #2ecc71; }

    .order-id { font-family: 'Inter', sans-serif; font-weight: 900; color: var(--primary); font-size: 0.85rem; letter-spacing: 0.5px; }

    .no-pod { color: #ccc; font-size: 0.78rem; font-weight: 700; font-style: italic; }
    .pod-thumb-btn { width: 44px; height: 44px; border-radius: 12px; object-fit: cover; cursor: pointer; border: 1px solid #f0f0f0; transition: 0.2s; }
    .pod-thumb-btn:hover { transform: scale(1.08); border-color: var(--primary); }

    #podViewOverlay { display: none; position: fixed; inset: 0; background: rgba(20,20,20,0.75); z-index: 600; align-items: center; justify-content: center; padding: 20px; }
    #podViewOverlay.open { display: flex; }
    #podViewCard { background: #fff; border-radius: 24px; padding: 1.5rem; max-width: 420px; width: 100%; text-align: center; }
    #podViewCard img { width: 100%; border-radius: 16px; margin-bottom: 1rem; }
    #podViewMeta { font-size: 0.8rem; color: var(--text-light); font-weight: 600; margin-bottom: 1rem; }
    #podViewCard button { width: 100%; padding: 12px; border-radius: 14px; border: none; background: #f5f5f5; color: var(--text-light); font-weight: 800; cursor: pointer; }
</style>

<main class="delivery-content">
    <div class="header-area">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Delivery Status</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Monitoring only — statuses are changed from Order Management.</p>
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
                    <th>Proof of Delivery</th>
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

<div id="podViewOverlay" onclick="if(event.target === this) closePodViewer()">
    <div id="podViewCard">
        <img id="podViewImg" src="" alt="Delivery proof">
        <div id="podViewMeta"></div>
        <button type="button" onclick="closePodViewer()">Close</button>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const deliveryData = document.getElementById('deliveryData');

        // Walk-in orders are handed over in person and have no delivery leg, so this
        // monitor only ever shows online (type 'WEB') orders.
        db.collection('orders').where('branchId', '==', window.currentBranch).onSnapshot(snap => {
            const onlineDocs = snap.docs.filter(doc => (doc.data().type || 'WEB') !== 'POS');

            if (onlineDocs.length === 0) {
                deliveryData.innerHTML = "<tr><td colspan='5' style='text-align: center; padding: 60px;' class='text-muted'>No online deliveries found.</td></tr>";
                return;
            }

            const docs = onlineDocs.sort((a, b) => {
                const timeA = a.data().timestamp ? a.data().timestamp.toMillis() : 0;
                const timeB = b.data().timestamp ? b.data().timestamp.toMillis() : 0;
                return timeB - timeA;
            });

            let html = '';
            docs.forEach(doc => {
                const o = doc.data();
                const id = doc.id;
                const status = (o.status || 'pending').toLowerCase();

                // Displayed status mirrors exactly what Order Management can set:
                // pending, processing, in transit, confirmed. Anything else (older
                // records) falls back to the closest current meaning.
                let displayStatus;
                if (status === 'confirmed' || status === 'delivered' || status === 'completed') displayStatus = 'Confirmed';
                else if (status === 'in transit') displayStatus = 'In Transit';
                else if (status === 'processing') displayStatus = 'Processing';
                else displayStatus = 'Pending';

                const customerName = o.customer_name || o.customerName || 'Guest Customer';
                const date = o.timestamp ? o.timestamp.toDate().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Recently';

                let badgeClass = 'badge-pending';
                if (displayStatus === 'In Transit' || displayStatus === 'Processing') badgeClass = 'badge-transit';
                if (displayStatus === 'Confirmed') badgeClass = 'badge-confirmed';

                let podCell;
                if (o.podPhotoUrl) {
                    podCell = `<img src="${o.podPhotoUrl}" class="pod-thumb-btn" onclick="viewPodPhoto('${o.podPhotoUrl.replace(/'/g, "\\'")}', '${(o.courierName || '').replace(/'/g, "\\'")}', '${(o.podRecipient || '').replace(/'/g, "\\'")}')" title="View delivery photo">`;
                } else {
                    podCell = `<span class="no-pod">Not yet uploaded</span>`;
                }

                html += `
                <tr>
                    <td><span class="order-id">#${id.substring(0, 8).toUpperCase()}</span></td>
                    <td style="font-weight: 600;">${customerName}</td>
                    <td style="color: #7d8da1;">${date}</td>
                    <td><span class="badge ${badgeClass}">${displayStatus}</span></td>
                    <td>${podCell}</td>
                </tr>
                `;
            });
            deliveryData.innerHTML = html;
        });
    });

    // Clicking the POD thumbnail views the photo — read-only, never writes anything.
    function viewPodPhoto(photoUrl, courier, recipient) {
        document.getElementById('podViewImg').src = photoUrl;
        const metaParts = [];
        if (courier) metaParts.push('Courier: ' + courier);
        if (recipient) metaParts.push('Received by: ' + recipient);
        document.getElementById('podViewMeta').innerText = metaParts.join(' · ') || 'No additional details on file.';
        document.getElementById('podViewOverlay').classList.add('open');
    }

    function closePodViewer() {
        document.getElementById('podViewOverlay').classList.remove('open');
    }
</script>

<?php include 'templates/footer.php'; ?>