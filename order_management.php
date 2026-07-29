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
    .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
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

    /* Online / Walk-in channel tabs — this is the split the whole page pivots on now:
       every query, stat, and status-option set below is scoped to whichever tab is active. */
    .channel-tabs { display: flex; gap: 10px; margin-bottom: 2rem; }
    .channel-tab { padding: 14px 28px; border-radius: 18px; border: 1px solid #f0f0f0; background: #fff; font-weight: 800; font-size: 0.85rem; cursor: pointer; color: var(--text-light); transition: 0.2s; display: flex; align-items: center; gap: 10px; }
    .channel-tab .count { background: #f5f5f5; color: var(--text-light); padding: 2px 10px; border-radius: 50px; font-size: 0.72rem; }
    .channel-tab.active { border-color: var(--primary); background: rgba(233,30,99,0.06); color: var(--primary); }
    .channel-tab.active .count { background: var(--primary); color: #fff; }

    /* Proof of Delivery capture modal (online order Confirm step) */
    #podCaptureOverlay { display: none; position: fixed; inset: 0; background: rgba(20,20,20,0.55); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
    #podCaptureOverlay.open { display: flex; }
    #podCaptureModal { background: #fff; border-radius: 30px; padding: 2.5rem; max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 30px 60px rgba(0,0,0,0.2); }
    #podCaptureModal h3 { font-family: 'Cormorant Garamond', serif; font-weight: 900; font-size: 1.6rem; margin: 0 0 0.5rem; }
    #podCaptureModal p.pod-sub { color: var(--text-light); font-size: 0.8rem; font-weight: 600; margin-bottom: 1.5rem; }
    .pod-field { margin-bottom: 1.1rem; }
    .pod-field label { display: block; font-size: 0.65rem; font-weight: 800; color: #ccc; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 8px; }
    .pod-field input[type="text"] { width: 100%; padding: 14px 16px; border-radius: 14px; border: 1px solid #f0f0f0; background: #fafafa; font-weight: 600; font-size: 0.88rem; outline: none; }
    .pod-field input[type="text"]:focus { border-color: var(--primary); background: #fff; }
    #podPreviewWrap { display: none; margin-top: 12px; text-align: center; }
    #podPreviewImg { max-width: 100%; max-height: 220px; border-radius: 14px; border: 1px solid #f0f0f0; }
    .pod-drop { border: 2px dashed #f0f0f0; border-radius: 18px; padding: 2rem; text-align: center; cursor: pointer; color: var(--text-light); font-weight: 700; font-size: 0.85rem; background: #fafafa; }
    .pod-drop:hover { border-color: var(--primary); color: var(--primary); }
    #podCaptureError { color: var(--primary); font-size: 0.8rem; font-weight: 700; margin-bottom: 1rem; display: none; }
    .pod-actions { display: flex; gap: 12px; margin-top: 1.5rem; }
    .pod-actions button { flex: 1; padding: 16px; border-radius: 18px; font-weight: 800; border: none; cursor: pointer; }
    .pod-cancel { background: #f5f5f5; color: var(--text-light); }
    .pod-confirm { background: var(--primary); color: #fff; }
</style>

<main class="order-content">
    <div class="flex justify-between items-center mb-8">
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

    <div class="channel-tabs">
        <button type="button" class="channel-tab active" id="tabOnline" onclick="setChannelTab('online')">
            <i class="fa-solid fa-globe"></i> Online Orders <span class="count" id="onlineCount">0</span>
        </button>
        <button type="button" class="channel-tab" id="tabWalkin" onclick="setChannelTab('walkin')">
            <i class="fa-solid fa-store"></i> Walk-in Orders <span class="count" id="walkinCount">0</span>
        </button>
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
            <h4 class="brand-font text-2xl font-black text-gray-800" id="logTitle">Online Orders Log</h4>
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

<div id="podCaptureOverlay">
    <div id="podCaptureModal">
        <h3>Confirm Delivery</h3>
        <p class="pod-sub">A photo and recipient name are required before this order can be marked Confirmed.</p>
        <div id="podCaptureError"></div>

        <div class="pod-field">
            <label>Delivery Photo</label>
            <div class="pod-drop" onclick="document.getElementById('podFileInput').click()">
                <i class="fa-solid fa-camera"></i> Tap to take or choose a photo
            </div>
            <input type="file" id="podFileInput" accept="image/*" capture="environment" style="display:none;" onchange="handlePodFileSelect(event)">
            <div id="podPreviewWrap">
                <img id="podPreviewImg" src="" alt="Preview">
            </div>
        </div>

        <div class="pod-field">
            <label>Received By (Recipient Name)</label>
            <input type="text" id="podRecipientInput" placeholder="e.g. Maria Santos">
        </div>

        <div class="pod-field">
            <label>Courier / Delivered By</label>
            <input type="text" id="podCourierInput">
        </div>

        <div class="pod-actions">
            <button type="button" class="pod-cancel" onclick="cancelPodCapture()">Cancel</button>
            <button type="button" class="pod-confirm" id="podConfirmBtn" onclick="confirmPodCapture()">Confirm Delivery</button>
        </div>
    </div>
</div>

<script>
    // Online (type 'WEB', or missing type — legacy web orders) vs Walk-in (type 'POS').
    // This single flag decides which status options a row gets and which stat card
    // numbers get computed, so it is checked in exactly one place.
    function isOnlineOrder(o) { return (o.type || 'WEB') !== 'POS'; }

    const ORDER_STATUS_OPTIONS = {
        online: ['pending', 'processing', 'in transit'],
        walkin: ['pending', 'processing', 'confirmed', 'cancelled']
    };
    const STATUS_LABELS = { 'pending': 'Pending', 'processing': 'Processing', 'in transit': 'In Transit', 'confirmed': 'Confirmed', 'cancelled': 'Cancelled' };

    let activeChannel = 'online'; // 'online' | 'walkin'
    let allOrders = [];
    const userMap = {};

    document.addEventListener('DOMContentLoaded', async () => {
        // Pre-fetch name mappings
        const usersSnap = await db.collection('users').get();
        usersSnap.forEach(d => userMap[d.id] = d.data().username || d.data().firstName || '');
        const custSnap = await db.collection('customers').get();
        custSnap.forEach(d => userMap[d.id] = d.data().name || d.data().fullName || '');

        db.collection('orders').where('branchId', '==', window.currentBranch).onSnapshot(snap => {
            allOrders = [];
            snap.forEach(doc => allOrders.push({ id: doc.id, ...doc.data() }));
            allOrders.sort((a, b) => ((b.createdAt || b.timestamp)?.seconds || 0) - ((a.createdAt || a.timestamp)?.seconds || 0));
            renderOrders();
        });

        document.getElementById('orderSearch').addEventListener('keyup', function() {
            let val = this.value.toLowerCase();
            document.querySelectorAll('#orderData tr').forEach(r => {
                r.style.display = r.innerText.toLowerCase().includes(val) ? '' : 'none';
            });
        });
    });

    function setChannelTab(channel) {
        activeChannel = channel;
        document.getElementById('tabOnline').classList.toggle('active', channel === 'online');
        document.getElementById('tabWalkin').classList.toggle('active', channel === 'walkin');
        document.getElementById('logTitle').innerText = (channel === 'online' ? 'Online Orders Log' : 'Walk-in Orders Log');
        document.getElementById('orderSearch').value = '';
        renderOrders();
    }

    function renderOrders() {
        const orderData = document.getElementById('orderData');
        const onlineOrders = allOrders.filter(isOnlineOrder);
        const walkinOrders = allOrders.filter(o => !isOnlineOrder(o));
        document.getElementById('onlineCount').innerText = onlineOrders.length;
        document.getElementById('walkinCount').innerText = walkinOrders.length;

        const docs = activeChannel === 'online' ? onlineOrders : walkinOrders;

        if (docs.length === 0) {
            orderData.innerHTML = "<tr><td colspan='5' class='text-center p-10 text-gray-400'>No " + (activeChannel === 'online' ? 'online' : 'walk-in') + " orders found for this branch.</td></tr>";
            document.getElementById('totalOrders').innerText = 0;
            document.getElementById('pendingOrders').innerText = 0;
            document.getElementById('totalSales').innerText = '₱0.00';
            return;
        }

        let html = '';
        let pendingCount = 0, salesTotal = 0;

        docs.forEach(data => {
            const online = isOnlineOrder(data);
            const rawStatus = (data.status || 'pending').toLowerCase();
            const allowedOptions = online ? ORDER_STATUS_OPTIONS.online : ORDER_STATUS_OPTIONS.walkin;
            // Fold legacy values (e.g. old 'delivered'/'completed' docs) onto the closest current option
            // so the dropdown never silently shows the wrong thing for older records.
            let status = allowedOptions.includes(rawStatus) ? rawStatus
                : (rawStatus === 'delivered' || rawStatus === 'completed') ? 'confirmed'
                : 'pending';

            const amount = parseFloat(data.total_price || data.total_amount || 0);
            if (status === 'pending') pendingCount++;
            if (status === 'confirmed') salesTotal += amount;

            const dateObj = data.createdAt || data.timestamp;
            const date = dateObj ? dateObj.toDate().toLocaleDateString() : 'N/A';
            const itemsSummary = (data.items || []).map(i => `${i.name} (${i.qty})`).join(', ');
            const uid = data.user_id || '';
            const customerName = data.customer_name || userMap[uid] || 'Walk-in Customer';
            const avatarInitial = customerName.charAt(0).toUpperCase();

            const optionsHtml = allowedOptions.map(opt =>
                `<option value="${opt}" ${status === opt ? 'selected' : ''}>${STATUS_LABELS[opt]}</option>`
            ).join('');

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
                    <select class="status-select" data-prev-status="${status}" onchange="handleStatusChange('${data.id}', '${online}', this.value, this)">
                        ${optionsHtml}
                    </select>
                </td>
            </tr>`;
        });

        orderData.innerHTML = html;
        document.getElementById('totalOrders').innerText = docs.length;
        document.getElementById('pendingOrders').innerText = pendingCount;
        document.getElementById('totalSales').innerText = '₱' + salesTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
    }

    // Routes a dropdown change to the right handler for the row's channel.
    async function handleStatusChange(orderId, onlineStr, newStatus, selectEl) {
        const online = onlineStr === 'true';

        // Online "confirmed" is never a plain write — it must go through the rider's
        // proof-of-delivery capture first. The dropdown reverts until that's done, and
        // the status only flips to Confirmed automatically once the proof is submitted.
        if (online && newStatus === 'confirmed') {
            openPodCapture(orderId, selectEl);
            return;
        }

        await secureStatusTransition(orderId, newStatus, selectEl);
    }

    async function secureStatusTransition(orderId, newStatus, selectEl) {
        try {
            // Finalized invoices can't be edited directly — only the Void module may touch them
            if (await window.blockIfLocked(orderId)) {
                selectEl.value = selectEl.dataset.prevStatus;
                return;
            }

            const updateData = { status: newStatus, updated_at: firebase.firestore.FieldValue.serverTimestamp() };
            if (newStatus === 'cancelled') {
                // Walk-in cancellation = a voided sale. No money moves — this business
                // does not refund a cancelled/voided item, it just stops the sale.
                // (Online customers cancel their own orders from their account, so
                // 'cancelled' never appears as an option on an online row at all.)
                updateData.paymentStatus = 'Voided';
                updateData.locked = true;
            }
            await db.collection('orders').doc(orderId).update(updateData);
            selectEl.dataset.prevStatus = newStatus;
        } catch (e) {
            alert('Fulfillment Mutation Rejected: ' + e.message);
            selectEl.value = selectEl.dataset.prevStatus;
        }
    }

    // --- Proof of Delivery capture (online orders only) ---
    // This is the one and only place an online order becomes "Confirmed." It used to
    // live on the Delivery Status page as a status dropdown option; that page is now
    // monitoring-only, so the actual write happens here, next to every other status change.
    let podTargetOrderId = null;
    let podTargetSelectEl = null;
    let podCompressedDataUrl = null;

    function openPodCapture(orderId, selectEl) {
        podTargetOrderId = orderId;
        podTargetSelectEl = selectEl;
        podCompressedDataUrl = null;

        document.getElementById('podCaptureError').style.display = 'none';
        document.getElementById('podFileInput').value = '';
        document.getElementById('podPreviewWrap').style.display = 'none';
        document.getElementById('podRecipientInput').value = '';
        document.getElementById('podCourierInput').value = window.currentUserName || '';

        document.getElementById('podCaptureOverlay').classList.add('open');
    }

    function cancelPodCapture() {
        if (podTargetSelectEl) podTargetSelectEl.value = podTargetSelectEl.dataset.prevStatus;
        document.getElementById('podCaptureOverlay').classList.remove('open');
        podTargetOrderId = null;
        podTargetSelectEl = null;
        podCompressedDataUrl = null;
    }

    function podShowError(msg) {
        const el = document.getElementById('podCaptureError');
        el.innerText = msg;
        el.style.display = 'block';
    }

    // Firestore caps a document at 1 MiB and this photo shares that document with the
    // rest of the order's fields, so a full-resolution phone photo needs to be
    // downscaled/re-encoded client-side before it can be saved.
    function compressImageFile(file, maxDimension, quality) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                const img = new Image();
                img.onload = () => {
                    let { width, height } = img;
                    if (width > height && width > maxDimension) {
                        height = Math.round(height * (maxDimension / width));
                        width = maxDimension;
                    } else if (height > maxDimension) {
                        width = Math.round(width * (maxDimension / height));
                        height = maxDimension;
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    canvas.getContext('2d').drawImage(img, 0, 0, width, height);
                    resolve(canvas.toDataURL('image/jpeg', quality));
                };
                img.onerror = () => reject(new Error('Could not read the selected image.'));
                img.src = e.target.result;
            };
            reader.onerror = () => reject(new Error('Could not read the selected file.'));
            reader.readAsDataURL(file);
        });
    }

    async function handlePodFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;
        try {
            let dataUrl = await compressImageFile(file, 800, 0.6);
            if (dataUrl.length > 700000) dataUrl = await compressImageFile(file, 500, 0.45);
            if (dataUrl.length > 700000) {
                podShowError('This photo is still too large after compression — try a different photo.');
                return;
            }
            podCompressedDataUrl = dataUrl;
            document.getElementById('podPreviewImg').src = dataUrl;
            document.getElementById('podPreviewWrap').style.display = 'block';
            document.getElementById('podCaptureError').style.display = 'none';
        } catch (err) {
            podShowError(err.message);
        }
    }

    async function confirmPodCapture() {
        const btn = document.getElementById('podConfirmBtn');
        document.getElementById('podCaptureError').style.display = 'none';

        const recipient = document.getElementById('podRecipientInput').value.trim();
        const courier = document.getElementById('podCourierInput').value.trim();

        if (!podCompressedDataUrl) { podShowError('A delivery photo is required.'); return; }
        if (!recipient) { podShowError('Recipient name is required.'); return; }

        btn.disabled = true;
        btn.innerText = 'Saving...';
        try {
            // One write: status flips to Confirmed, payment settles, the order locks,
            // and the proof lands together — there is never a moment where the order
            // reads Confirmed without POD attached, or vice versa.
            await db.collection('orders').doc(podTargetOrderId).update({
                status: 'confirmed',
                paymentStatus: 'Paid',
                locked: true,
                podPhotoUrl: podCompressedDataUrl,
                podRecipient: recipient,
                courierName: courier || 'Not specified',
                podTimestamp: firebase.firestore.FieldValue.serverTimestamp(),
                updated_at: firebase.firestore.FieldValue.serverTimestamp()
            });

            if (podTargetSelectEl) podTargetSelectEl.dataset.prevStatus = 'confirmed';

            document.getElementById('podCaptureOverlay').classList.remove('open');
            podTargetOrderId = null;
            podTargetSelectEl = null;
            podCompressedDataUrl = null;
        } catch (err) {
            podShowError('Error saving delivery: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.innerText = 'Confirm Delivery';
        }
    }
</script>
<?php include 'templates/footer.php'; ?>