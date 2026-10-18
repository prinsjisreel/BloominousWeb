<?php 
/**
 * BLOOMINOUS - Delivery Status Monitoring (Firebase Spoke)
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
    
    .table-card { background: var(--surface); border-radius: 35px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid var(--border-color); overflow: hidden; }

    table { width: 100%; border-collapse: collapse; }
    th { color: var(--text-light); font-size: 0.75rem; text-transform: uppercase; font-weight: 800; padding: 25px 20px; border-bottom: 1px solid var(--border-color); text-align: left; letter-spacing: 1px; background: var(--surface-alt); }
    td { padding: 20px; color: var(--text-main); font-size: 0.9rem; border-bottom: 1px solid #f8f9fa; transition: 0.2s; font-weight: 500; }
    tr:hover td { background: var(--surface-alt); }

    .badge { padding: 6px 16px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
    .badge-pending { background: #fff9e6; color: #ffbb55; }
    .badge-transit { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .badge-confirmed { background: rgba(46, 204, 113, 0.1); color: #2ecc71; }

    .order-id { font-family: 'Inter', sans-serif; font-weight: 900; color: var(--primary); font-size: 0.85rem; letter-spacing: 0.5px; }
    .order-date { color: #7d8da1; }

    .no-pod { color: #ccc; font-size: 0.78rem; font-weight: 700; font-style: italic; }
    .pod-thumb-btn { 
        width: 50px; height: 50px; border-radius: 12px; object-fit: cover; cursor: pointer; 
        border: 2px solid transparent; transition: 0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    .pod-thumb-btn:hover { transform: scale(1.1); border-color: var(--primary); }

    #podViewOverlay { 
        display: none; position: fixed; inset: 0; background: rgba(15,15,15,0.85); 
        backdrop-filter: blur(5px); z-index: 600; align-items: center; justify-content: center; padding: 20px; 
    }
    #podViewOverlay.open { display: flex; }
    #podViewCard { 
        background: var(--surface); border-radius: 24px; padding: 1.5rem; max-width: 500px; width: 100%; 
        text-align: center; box-shadow: 0 30px 60px rgba(0,0,0,0.3); 
    }
    #podViewCard img { width: 100%; max-height: 60vh; object-fit: contain; border-radius: 16px; margin-bottom: 1rem; background: #000; }
    #podViewMeta { font-size: 0.85rem; color: var(--text-light); font-weight: 600; margin-bottom: 1rem; line-height: 1.5; }
    #podViewCard button { 
        width: 100%; padding: 14px; border-radius: 14px; border: none; background: var(--primary); 
        color: white; font-weight: 800; cursor: pointer; transition: 0.2s;
    }
    #podViewCard button:hover { background: #d81b60; }

    /* ============ DARK MODE ============ */
    html[data-theme="dark"] .delivery-content td { border-bottom-color: var(--border-color); }
    html[data-theme="dark"] .table-card { box-shadow: none; }
    html[data-theme="dark"] .badge-pending { background: rgba(255, 187, 85, 0.15); color: #fcd34d; }
    html[data-theme="dark"] .badge-confirmed { background: rgba(46, 204, 113, 0.15); color: #6ee7b7; }
    html[data-theme="dark"] .order-date, html[data-theme="dark"] .no-pod { color: var(--text-light); }
    html[data-theme="dark"] #podViewCard { border: 1px solid var(--border-color); }
    html[data-theme="dark"] .delivery-content :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] .delivery-content :is(.text-gray-500, .text-gray-400, .text-gray-300, .text-muted) { color: var(--text-light); }
    html[data-theme="dark"] .delivery-content .bg-white { background-color: var(--surface); }
    html[data-theme="dark"] .delivery-content :is(.border-gray-50, .border-gray-100) { border-color: var(--border-color); }
    html[data-theme="dark"] .delivery-content .shadow-sm { box-shadow: none; }
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
        <button type="button" onclick="closePodViewer()">
            <i class="fa-solid fa-xmark mr-2"></i> Close
        </button>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const deliveryData = document.getElementById('deliveryData');

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

                // CHANGED: Check for both podPhotoUrl (new) and proofOfDeliveryPhoto (old)
                const photoUrl = o.podPhotoUrl || o.proofOfDeliveryPhoto;
                let podCell = `<span class="no-pod">Not yet uploaded</span>`;

                if (photoUrl) {
                    const safeUrl = photoUrl.replace(/'/g, "\\'");
                    const safeCourier = (o.courierName || '').replace(/'/g, "\\'");
                    const safeRecipient = (o.podRecipient || '').replace(/'/g, "\\'");
                    
                    podCell = `
                        <div style="display:flex; align-items:center; gap:10px;">
                            <img src="${photoUrl}" class="pod-thumb-btn" onclick="viewPodPhoto('${safeUrl}', '${safeCourier}', '${safeRecipient}')" title="View Proof of Delivery">
                            <span style="font-size:0.7rem; font-weight:700; color:#2ecc71;"><i class="fa-solid fa-check-circle"></i> Verified</span>
                        </div>
                    `;
                }

                html += `
                <tr>
                    <td><span class="order-id">#${id.substring(0, 8).toUpperCase()}</span></td>
                    <td style="font-weight: 600;">${customerName}</td>
                    <td class="order-date">${date}</td>
                    <td><span class="badge ${badgeClass}">${displayStatus}</span></td>
                    <td>${podCell}</td>
                </tr>
                `;
            });
            deliveryData.innerHTML = html;
        });
    });

    function viewPodPhoto(photoUrl, courier, recipient) {
        document.getElementById('podViewImg').src = photoUrl;
        const metaParts = [];
        if (courier) metaParts.push(`<strong>Courier:</strong> ${courier}`);
        if (recipient) metaParts.push(`<strong>Received by:</strong> ${recipient}`);
        
        document.getElementById('podViewMeta').innerHTML = metaParts.length > 0 
            ? metaParts.join('<br>') 
            : 'No additional details on file.';
            
        document.getElementById('podViewOverlay').classList.add('open');
    }

    function closePodViewer() {
        document.getElementById('podViewOverlay').classList.remove('open');
    }
</script>

<?php include 'templates/footer.php'; ?>