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

    /* Void modal (no refund path — this business does not return money on a voided order) */
    #vrOverlay { display: none; position: fixed; inset: 0; background: rgba(20,20,20,0.55); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
    #vrOverlay.open { display: flex; }
    #vrModal { background: #fff; border-radius: 30px; padding: 2.5rem; max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 30px 60px rgba(0,0,0,0.2); }
    #vrModal h3 { font-family: 'Cormorant Garamond', serif; font-weight: 900; font-size: 1.8rem; margin: 0 0 1.5rem; }
    .vr-toggle-row { display: flex; gap: 10px; margin-bottom: 1.25rem; }
    .vr-toggle { flex: 1; text-align: center; padding: 14px; border-radius: 16px; border: 1px solid #f0f0f0; background: #fafafa; font-weight: 800; font-size: 0.8rem; cursor: pointer; transition: 0.2s; color: var(--text-light); }
    .vr-toggle.active { border-color: var(--primary); background: rgba(233,30,99,0.06); color: var(--primary); }
    .vr-field { margin-bottom: 1.1rem; }
    .vr-field label { display: block; font-size: 0.65rem; font-weight: 800; color: #ccc; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 8px; }
    .vr-field select, .vr-field input, .vr-field textarea { width: 100%; padding: 14px 16px; border-radius: 14px; border: 1px solid #f0f0f0; background: #fafafa; font-weight: 600; font-size: 0.88rem; outline: none; }
    .vr-field select:focus, .vr-field input:focus { border-color: var(--primary); background: #fff; }
    .vr-item-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f8f9fa; font-size: 0.85rem; font-weight: 600; }
    .vr-item-row input[type="checkbox"] { width: auto; }
    .vr-item-row input[type="number"] { width: 70px; padding: 8px; text-align: center; }
    #vrPinSection { background: #fff9e6; border-radius: 16px; padding: 1.1rem 1.25rem; margin-bottom: 1.1rem; display: none; }
    #vrPinSection.show { display: block; }
    .vr-anomaly-banner { border-radius: 14px; padding: 12px 16px; margin-bottom: 1.1rem; font-size: 0.78rem; font-weight: 700; display: none; }
    .vr-anomaly-medium { background: #fffbeb; color: #78350f; border: 1px solid #fef3c7; }
    .vr-anomaly-critical { background: #fef2f2; color: #7f1d1d; border: 1px solid #fee2e2; }
    #vrError { color: var(--primary); font-size: 0.8rem; font-weight: 700; margin-bottom: 1rem; display: none; }
    .vr-actions { display: flex; gap: 12px; margin-top: 1.5rem; }
    .vr-actions button { flex: 1; }
    .btn-cancel-vr { background: #f5f5f5; color: var(--text-light); border: none; padding: 16px; border-radius: 18px; font-weight: 800; cursor: pointer; }

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

<div class="details-content">
    <a href="order_management.php" class="back-link">
        <i class="fa-solid fa-chevron-left"></i> Order Registry
    </a>

    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-16 gap-8">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Telemetry Summary</h1>
            <p class="text-gray-400 font-bold text-xs uppercase tracking-widest mt-2 italic">Real-time fulfillment and transaction forensics</p>
        </div>
        <div class="flex items-center gap-4" id="statusBadgeContainer">
            <a href="invoice.php?id=<?php echo htmlspecialchars($order_id, ENT_QUOTES); ?>" class="btn-primary" style="text-decoration:none;">
                <i class="fa-solid fa-receipt"></i> View Invoice
            </a>
            <?php if (in_array($user_role, ['admin', 'super-admin'], true)): ?>
            <button type="button" class="btn-update" style="width:auto;padding:10px 20px;background:#fff;color:var(--primary);border:1px solid rgba(233,30,99,0.2);box-shadow:none;" id="openVoidBtn" onclick="openVoidModal()">
                <i class="fa-solid fa-ban"></i> Void Order
            </button>
            <?php endif; ?>
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
                <p class="text-[11px] font-bold text-gray-400 mb-3" id="channelHint">...</p>
                <form id="updateStatusForm">
                    <select id="statusSelect" class="status-select">
                        <!-- options populated by JS: online orders get Pending/Processing/In Transit/Confirmed,
                             walk-in orders get Pending/Processing/Confirmed/Cancelled. See ORDER_STATUS_OPTIONS. -->
                    </select>
                    <button type="submit" id="updateBtn" class="btn-update">
                        Update Status
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

<div id="vrOverlay">
    <div id="vrModal">
        <h3>Void Order</h3>
        <p style="margin:-1rem 0 1.5rem; color:var(--text-light); font-size:0.8rem; font-weight:600;">
            Voiding cancels the sale for the selected items. No money is returned through the
            system — this business does not process refunds on a cancelled/voided order.
        </p>
        <div id="vrError"></div>

        <div class="vr-toggle-row">
            <div class="vr-toggle active" id="vrScopeFull" onclick="setVrScope('full')">Full Order</div>
            <div class="vr-toggle" id="vrScopePartial" onclick="setVrScope('partial')">Partial (select items)</div>
        </div>

        <div id="vrItemsSection" style="display:none; margin-bottom:1.1rem;">
            <label style="display:block;font-size:0.65rem;font-weight:800;color:#ccc;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;">Items to Return</label>
            <div id="vrItemsList"></div>
        </div>

        <div id="vrAnomalyBanner" class="vr-anomaly-banner"></div>

        <div class="vr-field">
            <label>Reason</label>
            <select id="vrReason">
                <option value="Customer Changed Mind">Customer Changed Mind</option>
                <option value="Wrong Item Scanned">Wrong Item Scanned</option>
                <option value="Defective Item">Defective Item</option>
                <option value="System Error">System Error</option>
                <option value="Other">Other</option>
            </select>
        </div>

        <div class="vr-field">
            <label>Stock Disposition</label>
            <select id="vrRestockAction">
                <option value="restock">Restock to Inventory</option>
                <option value="writeoff">Write-off / Damaged</option>
            </select>
        </div>

        <div id="vrPinSection">
            <div class="vr-field" style="margin-bottom:0.75rem;">
                <label>Approving Manager Email</label>
                <input type="email" id="vrApproverEmail" placeholder="manager@bloom.com">
            </div>
            <div class="vr-field" style="margin-bottom:0;">
                <label>Manager PIN (password)</label>
                <input type="password" id="vrApproverPin" placeholder="••••••••">
            </div>
        </div>

        <div class="vr-actions">
            <button type="button" class="btn-cancel-vr" onclick="closeVoidModal()">Cancel</button>
            <button type="button" class="btn-update" id="vrSubmitBtn" onclick="submitVoid()">Confirm Void</button>
        </div>
    </div>
</div>

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
    const orderId = "<?php echo $order_id; ?>";
    let vrScope = 'full';
    let vrOrderData = null;

    // Online (type 'WEB') vs Walk-in (type 'POS') orders follow different lifecycles:
    // - Online orders are delivered by a rider, so "Confirmed" must be backed by proof
    //   of delivery (POD photo) — it can never be picked freely from the dropdown.
    // - Walk-in orders are handed over in person at the counter, so no POD is needed,
    //   and only walk-in staff can mark an order Cancelled (= voided) from here; online
    //   customers cancel their own orders from their account, not from this panel.
    const ORDER_STATUS_OPTIONS = {
        online: ['Pending', 'Processing', 'In Transit', 'Confirmed'],
        walkin: ['Pending', 'Processing', 'Confirmed', 'Cancelled']
    };
    function isOnlineOrder(o) { return (o.type || 'WEB') !== 'POS'; }

    function populateStatusSelect(o) {
        const online = isOnlineOrder(o);
        const options = online ? ORDER_STATUS_OPTIONS.online : ORDER_STATUS_OPTIONS.walkin;
        const select = document.getElementById('statusSelect');
        const current = (o.status || 'Pending');
        // Normalize casing so it matches one of our option values (older docs may store lowercase).
        const currentMatch = options.find(opt => opt.toLowerCase() === current.toLowerCase()) || options[0];
        select.innerHTML = options.map(opt => `<option value="${opt}" ${opt === currentMatch ? 'selected' : ''}>${opt}</option>`).join('');
        document.getElementById('channelHint').innerText = online
            ? 'Online order — Confirmed requires the rider\'s proof of delivery.'
            : 'Walk-in order — Cancelled is treated as a voided sale.';
    }

    document.addEventListener('DOMContentLoaded', () => {
        // Real-time listener for order details
        db.collection('orders').doc(orderId).onSnapshot(doc => {
            if (!doc.exists) {
                alert('Order not found!');
                window.location.href = 'order_management.php';
                return;
            }
            const o = doc.data();
            vrOrderData = o;
            const status = o.status || 'Pending';
             
            document.getElementById('orderStatusBadge').innerText = status;
            
            // Update badge class
            const badge = document.getElementById('orderStatusBadge');
            badge.className = 'badge badge-' + status.toLowerCase();
            
            populateStatusSelect(o);
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
                if (await window.blockIfLocked(orderId)) {
                    return;
                }

                const newStatus = document.getElementById('statusSelect').value;
                const online = isOnlineOrder(vrOrderData || {});

                // Online "Confirmed" is never a plain write — it must go through the
                // rider's proof-of-delivery capture first. The status only flips to
                // Confirmed automatically once that proof is submitted.
                if (online && newStatus === 'Confirmed') {
                    openPodCapture(orderId);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-2"></i> Update Status';
                    return;
                }

                const updateData = {
                    status: newStatus,
                    updated_at: firebase.firestore.FieldValue.serverTimestamp()
                };
                if (!online && newStatus === 'Cancelled') {
                    // Walk-in cancellation = voided sale. No money moves — this business
                    // does not refund a cancelled/voided item, it just stops the sale.
                    updateData.paymentStatus = 'Voided';
                    updateData.locked = true;
                } else if (!online && newStatus === 'Confirmed') {
                    updateData.paymentStatus = 'Paid';
                }

                await db.collection('orders').doc(orderId).update(updateData);
                console.log('Order status updated successfully');
            } catch (err) {
                alert('Error updating order: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-2"></i> Update Status';
            }
        };
    });

    // --- Proof of Delivery capture (online orders only) ---
    // Confirming an online order always requires a photo + recipient name from the
    // rider. This is the same capture flow that used to live on the Delivery Status
    // page — it now lives wherever status is actually changed, since Delivery Status
    // is monitoring-only.
    let podTargetOrderId = null;
    let podCompressedDataUrl = null;

    function openPodCapture(orderIdToConfirm) {
        podTargetOrderId = orderIdToConfirm;
        podCompressedDataUrl = null;
        document.getElementById('podCaptureError').style.display = 'none';
        document.getElementById('podFileInput').value = '';
        document.getElementById('podPreviewWrap').style.display = 'none';
        document.getElementById('podRecipientInput').value = '';
        document.getElementById('podCourierInput').value = window.currentUserName || '';
        document.getElementById('podCaptureOverlay').classList.add('open');
    }

    function cancelPodCapture() {
        // Nothing was written — put the visible dropdown back to the real saved status
        // so it doesn't visually claim "Confirmed" while Firestore still says otherwise.
        if (vrOrderData) populateStatusSelect(vrOrderData);
        document.getElementById('podCaptureOverlay').classList.remove('open');
        podTargetOrderId = null;
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
                status: 'Confirmed',
                paymentStatus: 'Paid',
                locked: true,
                podPhotoUrl: podCompressedDataUrl,
                podRecipient: recipient,
                courierName: courier || 'Not specified',
                podTimestamp: firebase.firestore.FieldValue.serverTimestamp(),
                updated_at: firebase.firestore.FieldValue.serverTimestamp()
            });
            document.getElementById('podCaptureOverlay').classList.remove('open');
            podTargetOrderId = null;
            podCompressedDataUrl = null;
        } catch (err) {
            podShowError('Error saving delivery: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.innerText = 'Confirm Delivery';
        }
    }

    // --- Void workflow (no refunds — voiding never returns money) ---

    function setVrScope(s) {
        vrScope = s;
        document.getElementById('vrScopeFull').classList.toggle('active', s === 'full');
        document.getElementById('vrScopePartial').classList.toggle('active', s === 'partial');
        document.getElementById('vrItemsSection').style.display = s === 'partial' ? 'block' : 'none';
        if (s === 'partial') renderVrItems();
    }

    function renderVrItems() {
        const items = (vrOrderData && vrOrderData.items) || [];
        const list = document.getElementById('vrItemsList');
        list.innerHTML = items.map((item, idx) => `
            <div class="vr-item-row">
                <input type="checkbox" id="vrItem${idx}" data-idx="${idx}">
                <label for="vrItem${idx}" style="flex:1;margin:0;text-transform:none;font-weight:600;font-size:0.85rem;color:var(--text-main);letter-spacing:0;">${item.name}</label>
                <span style="color:#ccc;font-size:0.75rem;">of ${item.qty}</span>
                <input type="number" id="vrItemQty${idx}" min="1" max="${item.qty}" value="1">
            </div>
        `).join('') || '<p style="color:#ccc;font-size:0.85rem;">No items on this order.</p>';
    }

    // Set by openVoidModal(), read by submitVoid() — tracks
    // whether THIS void attempt is itself part of a frequency spike
    // for the currently logged-in staff account.
    let vrAnomalySeverity = null;
    let vrAnomalyDetail = null;

    async function openVoidModal() {
        // Business rule: only managers (admin/super-admin) may void an
        // order — cashiers/employees never have this power. The button
        // itself is already hidden from employees in the PHP above; this
        // check is a second layer in case someone calls this function
        // directly (e.g. from the browser console).
        if (!['admin', 'super-admin'].includes(window.currentUserRole)) {
            alert('Only managers can void orders. Please ask a manager to assist.');
            return;
        }

        if (!vrOrderData) { alert('Order still loading — try again in a moment.'); return; }
        if (vrOrderData.paymentStatus === 'Voided') {
            alert('This invoice is already voided — no further action needed.');
            return;
        }
        document.getElementById('vrError').style.display = 'none';
        setVrScope('full');
        document.getElementById('vrApproverEmail').value = '';
        document.getElementById('vrApproverPin').value = '';

        // --- Sales Anomalies Detection: void/cancellation frequency check ---
        // Counts how many voids THIS manager account has already
        // initiated in the rolling window (see SalesAnomalies.checkVoidFrequency).
        // This runs every time the modal opens, before anything is submitted,
        // so the risk is visible up front rather than after the fact.
        vrAnomalySeverity = null;
        vrAnomalyDetail = null;
        const banner = document.getElementById('vrAnomalyBanner');
        banner.style.display = 'none';
        banner.className = 'vr-anomaly-banner';
        try {
            const voidCheck = await SalesAnomalies.checkVoidFrequency(window.currentUserEmail);
            if (voidCheck.flagged) {
                vrAnomalySeverity = voidCheck.severity;
                vrAnomalyDetail = voidCheck.detail;
                banner.classList.add('vr-anomaly-' + voidCheck.severity);
                banner.innerText = (voidCheck.severity === 'critical' ? '⚠ Critical: ' : '⚠ Notice: ') + voidCheck.detail;
                banner.style.display = 'block';
            }
        } catch (e) {
            console.warn('Void frequency check failed, continuing without it.', e);
        }

        // A manager can normally approve their own void. The only
        // exception: if THIS manager's own recent void activity just
        // tripped the Critical frequency threshold, a second manager's
        // credentials are required — one manager alone can't wave through
        // their own suspicious pattern.
        const pinSection = document.getElementById('vrPinSection');
        if (vrAnomalySeverity === 'critical') {
            pinSection.classList.add('show');
        } else {
            pinSection.classList.remove('show');
        }

        document.getElementById('vrOverlay').classList.add('open');
    }

    function closeVoidModal() {
        document.getElementById('vrOverlay').classList.remove('open');
    }

    function vrShowError(msg) {
        const el = document.getElementById('vrError');
        el.innerText = msg;
        el.style.display = 'block';
    }

    async function submitVoid() {
        const btn = document.getElementById('vrSubmitBtn');
        document.getElementById('vrError').style.display = 'none';

        try {
            // Step 1 — approval gate. Only managers (admin/super-admin) ever
            // reach this point (enforced in openVoidModal()). Normally a
            // manager approves their own void with no extra step. The one
            // exception: if this manager's own recent void activity just
            // tripped the Critical frequency threshold, a SECOND manager's
            // email + password must be entered to proceed.
            let approvedBy = null;
            const pinRequired = vrAnomalySeverity === 'critical';
            if (pinRequired) {
                const approverEmail = document.getElementById('vrApproverEmail').value.trim();
                const approverPin = document.getElementById('vrApproverPin').value;
                if (!approverEmail || !approverPin) {
                    vrShowError('A second manager\'s email and PIN are required to authorize this action.');
                    return;
                }
                const verifiedManager = await SalesAnomalies.verifyManagerPin(approverEmail, approverPin);
                if (!verifiedManager) {
                    vrShowError('Invalid manager credentials — approval denied.');
                    return;
                }
                approvedBy = verifiedManager;
            } else {
                approvedBy = window.currentUserEmail || window.currentUserRole; // manager approving their own action
            }

            btn.disabled = true;
            btn.innerText = 'Processing...';

            // Step 2 — collect what's being voided
            const reasonCode = document.getElementById('vrReason').value;
            const restockAction = document.getElementById('vrRestockAction').value;
            let voidItems = [];
            if (vrScope === 'partial') {
                const allItems = vrOrderData.items || [];
                allItems.forEach((item, idx) => {
                    const checkbox = document.getElementById('vrItem' + idx);
                    if (checkbox && checkbox.checked) {
                        const qtyInput = document.getElementById('vrItemQty' + idx);
                        voidItems.push({ productId: item.id, name: item.name, qty: parseInt(qtyInput.value) || 1, price: item.price });
                    }
                });
                if (voidItems.length === 0) {
                    vrShowError('Select at least one item for a partial void.');
                    btn.disabled = false;
                    btn.innerText = 'Confirm Void';
                    return;
                }
            }

            // Step 3 — the original invoice is never edited. A new document records what happened.
            const voidId = await window.generateVoidId();
            await db.collection('voidRecords').add({
                voidId: voidId,
                invoiceId: vrOrderData.invoiceId || orderId,
                orderId: orderId,
                scope: vrScope,
                reasonCode: reasonCode,
                items: vrScope === 'partial' ? voidItems : (vrOrderData.items || []),
                restockAction: restockAction,
                initiatedBy: window.currentUserRole,
                initiatedByEmail: window.currentUserEmail || null,
                approvedBy: approvedBy,
                branchId: vrOrderData.branchId || window.currentBranch,
                timestamp: firebase.firestore.FieldValue.serverTimestamp()
            });

            // Step 4 — one narrow, permitted status flip on the original order. Content
            // untouched. No 'Refunded' state exists — voiding never returns money.
            await db.collection('orders').doc(orderId).update({ paymentStatus: 'Voided', status: 'Cancelled' });

            // Step 5 — restock/write-off, run as the same transaction pattern used at sale time
            const itemsToProcess = vrScope === 'partial' ? voidItems : (vrOrderData.items || []);
            if (restockAction === 'restock') {
                for (const item of itemsToProcess) {
                    const pid = item.productId || item.id;
                    if (!pid) continue;
                    const productRef = getBranchPath('inventory').doc(pid);
                    await db.runTransaction(async (transaction) => {
                        const pdoc = await transaction.get(productRef);
                        if (!pdoc.exists) return;
                        const newStock = (pdoc.data().stock || 0) + (item.qty || 0);
                        transaction.update(productRef, { stock: newStock });
                    });
                }
            }

            // Step 6 — negative ledger entry so daily sales totals stay accurate without touching the receipt
            const voidValue = itemsToProcess.reduce((sum, it) => sum + (parseFloat(it.price || 0) * parseInt(it.qty || 0)), 0);
            await db.collection('salesLedger').add({
                voidId: voidId,
                orderId: orderId,
                branchId: vrOrderData.branchId || window.currentBranch,
                amount: -Math.abs(voidValue || vrOrderData.total_price || vrOrderData.total_amount || 0),
                type: 'void',
                timestamp: firebase.firestore.FieldValue.serverTimestamp()
            });

            await db.collection('notifications').add({
                title: 'Order Voided',
                message: `${voidId} — ${reasonCode} (approved by ${approvedBy})`,
                type: 'warning',
                branchId: vrOrderData.branchId || window.currentBranch,
                created_at: firebase.firestore.FieldValue.serverTimestamp(),
                read: false
            });

            // Step 7 — if this void tripped the frequency check, record it on the
            // Sales Anomalies dashboard. overriddenBy is only set when the PIN
            // gate was actually shown (employee, or a Critical spike).
            if (vrAnomalySeverity) {
                await SalesAnomalies.logAnomaly({
                    type: 'void_spike',
                    severity: vrAnomalySeverity,
                    detail: vrAnomalyDetail,
                    branchId: vrOrderData.branchId || window.currentBranch,
                    orderId: orderId,
                    invoiceId: vrOrderData.invoiceId || orderId,
                    overriddenBy: pinRequired ? approvedBy : null
                });
            }

            closeVoidModal();
        } catch (err) {
            vrShowError('Error: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.innerText = 'Confirm Void';
        }
    }
</script>

<?php include 'templates/footer.php'; ?>