<?php 
/**
 * BLOOMINOUS - Pre-Order & Reservation Management (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php'; 
?>

<main class="pos-content" style="padding: 1.5rem; max-width: 1400px; margin: 0 auto;">
    <div class="flex justify-between items-center mb-12">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Event Organizer & Reservation</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Live monitoring of event reservations submitted through the app's AI Visual Stylist — review, approve, and track fulfillment.</p>
        </div>
        <button onclick="openReservationSettingsModal()" class="flex items-center gap-2 px-6 py-3 rounded-2xl border-2 font-black text-xs uppercase tracking-widest transition-all flex-shrink-0" style="border-color:var(--secondary); color:var(--secondary);" onmouseover="this.style.background='var(--secondary)'; this.style.color='white';" onmouseout="this.style.background='none'; this.style.color='var(--secondary)';">
            <i class="fa-solid fa-gear"></i>
            <span>Reservation Settings</span>
        </button>
    </div>

    <div id="reservationsGrid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap:30px;">
        <div style="grid-column: 1 / -1; text-align: center; padding: 80px; color: #ddd; font-style: italic; font-weight: 500;">Retrieving privilege records...</div>
    </div>
</main>

<div id="reservationSettingsModal" class="fixed inset-0 bg-black/40 backdrop-blur-md hidden z-[300] flex items-center justify-center p-4">
    <div class="bg-white rounded-[35px] w-full max-w-lg shadow-2xl overflow-hidden scale-95 opacity-0 transition-all duration-300 transform border border-gray-100" id="reservationSettingsModalContent">
        <div class="p-10 max-h-[85vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-2">
                <h2 class="brand-font text-3xl font-black text-gray-800">Reservation Settings</h2>
                <button onclick="closeReservationSettingsModal()" class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center text-gray-400 hover:text-pink-500 transition-colors">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
            <p class="text-gray-400 text-xs font-medium mb-8">Applies to all branches — booking availability is set once, business-wide.</p>

            <label class="block text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-3 ml-1">Max Reservations Per Day</label>
            <div class="flex gap-3 mb-2">
                <input type="number" min="1" id="maxDailyCapacityInput" placeholder="1" class="flex-1 bg-gray-50 border border-gray-100 rounded-2xl px-6 py-4 focus:outline-none focus:ring-2 focus:ring-pink-500/10 transition-all font-semibold text-sm">
                <button onclick="saveMaxDailyCapacity()" class="btn-primary px-8 rounded-2xl font-black text-xs uppercase tracking-widest">Save</button>
            </div>
            <p class="text-[10.5px] text-gray-400 italic mb-8 px-1">Caps how many reservations can be booked on any single day, counted across every branch combined.</p>

            <hr class="border-gray-100 mb-8">

            <label class="block text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-3 ml-1">Unavailable Dates</label>
            <div class="flex gap-3 mb-2">
                <input type="date" id="blockDateInput" class="flex-1 bg-gray-50 border border-gray-100 rounded-2xl px-6 py-4 focus:outline-none focus:ring-2 focus:ring-pink-500/10 transition-all font-semibold text-sm">
                <button onclick="blockSelectedDate()" class="px-8 rounded-2xl font-black text-xs uppercase tracking-widest border-2 border-red-500 text-red-500 hover:bg-red-500 hover:text-white transition-all">
                    <i class="fa-solid fa-ban mr-1"></i> Block
                </button>
            </div>
            <p class="text-[10.5px] text-gray-400 italic mb-4 px-1">Dates marked here cannot be selected by customers at all, regardless of the daily cap above. Shown here for every branch — this list is not filtered by branch selection.</p>

            <div id="blockedDatesList" class="space-y-2 max-h-52 overflow-y-auto">
                <p class="text-xs text-gray-400 italic py-4 text-center">Loading blocked dates...</p>
            </div>
        </div>
    </div>
</div>

<script>
    // ---------------------------------------------------------------------
    // NEW: Payment ledger rendering, extracted into its own function rather
    // than inlined into the giant reservation-card template literal below.
    // MIRRORS lib/preorder_reservations_page.dart's _buildPaymentLedgerSection
    // exactly: same three fields read (total_amount, amount_paid,
    // balance_due), same fallback math if balance_due isn't set yet, same
    // "hide the form once balance_due <= 0" rule.
    // ---------------------------------------------------------------------
    function renderPaymentLedger(id, data) {
        const total = Number(data.total_amount || 0);
        const paid = Number(data.amount_paid || 0);
        const balance = (data.balance_due !== undefined && data.balance_due !== null)
            ? Number(data.balance_due)
            : Math.max(total - paid, 0);
        const progress = total > 0 ? Math.min(paid / total, 1) : 0;
        const history = Array.isArray(data.payment_history) ? data.payment_history : [];

        const historyHtml = history.length > 0
            ? history.map(entry => {
                const amt = Number(entry.amount || 0);
                const method = (entry.method || '').toString();
                const methodLabel = method ? method.charAt(0).toUpperCase() + method.slice(1) : 'Payment';
                const note = (entry.note || '').toString();
                let dateStr = '';
                if (entry.recorded_at && typeof entry.recorded_at.toDate === 'function') {
                    dateStr = entry.recorded_at.toDate().toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }
                return `
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:4px 0; font-size:0.72rem; color:var(--text-main);">
                        <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; padding-right:8px;">
                            <i class="fa-solid fa-receipt mr-1" style="color:#bbb;"></i>
                            ${dateStr} &middot; ${methodLabel}${note ? ` (${note})` : ''}
                        </span>
                        <span style="font-weight:800; flex-shrink:0;">₱${amt.toLocaleString(undefined, { maximumFractionDigits: 0 })}</span>
                    </div>
                `;
            }).join('')
            : `<p style="font-size:0.72rem; color:#bbb; font-style:italic; margin:4px 0;">No balance payments recorded yet.</p>`;

        const actionHtml = balance > 0 ? `
            <div style="display:flex; gap:6px; margin-bottom:6px;">
                <input type="number" min="1" id="payAmount_${id}" placeholder="Amount" style="flex:1; padding:8px 10px; border-radius:10px; border:1px solid #eee; font-size:0.75rem; box-sizing:border-box;">
                <select id="payMethod_${id}" style="width:90px; padding:8px 6px; border-radius:10px; border:1px solid #eee; font-size:0.7rem;">
                    <option value="cash">Cash</option>
                    <option value="gcash">GCash</option>
                    <option value="maya">Maya</option>
                </select>
            </div>
            <input type="text" id="payNote_${id}" placeholder="Note (optional)" style="width:100%; padding:8px 10px; border-radius:10px; border:1px solid #eee; font-size:0.72rem; margin-bottom:6px; box-sizing:border-box;">
            <button onclick="recordPayment('${id}')" style="width:100%; background:none; border:2px solid var(--secondary); color:var(--secondary); padding:8px; border-radius:10px; font-size:0.68rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; cursor:pointer;">
                <i class="fa-solid fa-credit-card mr-1"></i> Record Payment
            </button>
        ` : `
            <div style="width:100%; background:#f8fff9; border:1px dashed #27ae60; color:#27ae60; padding:8px; border-radius:10px; font-size:0.68rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; text-align:center;">
                <i class="fa-solid fa-circle-check mr-1"></i> Fully Paid
            </div>
        `;

        return `
            <div style="background:#fafafa; padding:14px; border-radius:15px; border:1px solid #f8f8f8; margin-bottom:15px;">
                <span style="display:block; font-size:0.6rem; text-transform:uppercase; color:var(--text-light); font-weight:800; letter-spacing:0.5px; margin-bottom:8px;">Payment Ledger</span>
                <div style="display:flex; gap:10px; margin-bottom:8px;">
                    <div style="flex:1;">
                        <span style="display:block; font-size:0.55rem; text-transform:uppercase; color:var(--text-light); font-weight:800;">Total</span>
                        <span style="font-size:0.8rem; font-weight:900;">₱${total.toLocaleString(undefined, { maximumFractionDigits: 0 })}</span>
                    </div>
                    <div style="flex:1;">
                        <span style="display:block; font-size:0.55rem; text-transform:uppercase; color:var(--text-light); font-weight:800;">Paid</span>
                        <span style="font-size:0.8rem; font-weight:900; color:#27ae60;">₱${paid.toLocaleString(undefined, { maximumFractionDigits: 0 })}</span>
                    </div>
                    <div style="flex:1;">
                        <span style="display:block; font-size:0.55rem; text-transform:uppercase; color:var(--text-light); font-weight:800;">Balance</span>
                        <span style="font-size:0.8rem; font-weight:900; color:${balance > 0 ? '#dc3545' : '#27ae60'};">₱${balance.toLocaleString(undefined, { maximumFractionDigits: 0 })}</span>
                    </div>
                </div>
                <div style="height:6px; border-radius:4px; background:#eee; overflow:hidden; margin-bottom:10px;">
                    <div style="height:100%; width:${(progress * 100).toFixed(0)}%; background:${balance <= 0 ? '#27ae60' : 'var(--secondary)'};"></div>
                </div>
                ${historyHtml}
                <div style="margin-top:10px;">
                    ${actionHtml}
                </div>
            </div>
        `;
    }

    document.addEventListener('DOMContentLoaded', () => {
        const reservationsGrid = document.getElementById('reservationsGrid');

        // Feature 2: Real-time branch data tracking link
        getBranchPath('reservations').orderBy('created_at', 'desc').onSnapshot(snap => {
            if (snap.empty) {
                reservationsGrid.innerHTML = `
                    <div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #888;">
                        <i class="fa-solid fa-calendar-check" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3; color: var(--primary);"></i>
                        <p class="font-semibold text-gray-500 text-sm tracking-wider uppercase">No event reservations submitted via the Visual Stylist yet.</p>
                    </div>
                `;
                return;
            }

            let html = '';
            snap.forEach(doc => {
                const data = doc.data();
                const id = doc.id;
                const targetDate = data.fulfillment_date ? new Date(data.fulfillment_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';
                
                // Color badges matching the specific state flow (Feature 4)
                // Pipeline: Pending Review -> [Approve/Decline gate] -> Approved -> Confirmed & Sourcing -> Ready for Pickup -> Completed
                let badgeStyle = "background:#fff3cd; color:#856404;";
                let actionButtonText = '<i class="fa-solid fa-square-check mr-2"></i> Confirm Bouquet Selection';

                if (data.status === 'Approved') {
                    badgeStyle = "background:#e7f0ff; color:#1d4ed8;";
                    actionButtonText = '<i class="fa-solid fa-seedling mr-2"></i> Begin Sourcing';
                } else if (data.status === 'Confirmed & Sourcing') {
                    badgeStyle = "background:#d1ecf1; color:#0c5460;";
                    actionButtonText = '<i class="fa-solid fa-wand-magic-sparkles mr-2"></i> Flag as Ready for Pickup';
                } else if (data.status === 'Ready for Pickup') {
                    badgeStyle = "background:#e2e3e5; color:#383d41;";
                    actionButtonText = '<i class="fa-solid fa-box-open mr-2"></i> Handover/Complete Order';
                } else if (data.status === 'Completed') {
                    badgeStyle = "background:#d4edda; color:#155724;";
                } else if (data.status === 'Declined') {
                    badgeStyle = "background:#f8d7da; color:#721c24;";
                }

                const isPendingReview = !data.status || data.status === 'Pending Review';
                const isVisualStylistSubmission = data.source === 'visual_stylist';

                // NEW: only reservations that came through the AI Visual
                // Stylist booking flow have total_amount/amount_paid --
                // gated the same way as mobile's `if (totalAmount != null)`.
                const hasPaymentData = data.total_amount !== undefined && data.total_amount !== null;

                html += `
                <div style="background:white; border:1px solid #f0f0f0; padding:2.5rem 2rem; border-radius:30px; position:relative; box-shadow: 0 10px 30px rgba(0,0,0,0.01); display:flex; flex-direction:column; justify-between; height:100%;">
                    
                    <button onclick="cancelBooking('${id}')" title="Terminate Log" style="position:absolute; top:20px; right:20px; color:#ccc; background:none; border:none; cursor:pointer; font-size:0.95rem; transition:0.2s;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='#ccc'">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>

                    ${data.style_photo_url ? `
                    <div style="margin:-2.5rem -2rem 15px -2rem; height:170px; overflow:hidden; border-radius:30px 30px 0 0; position:relative; background:#f5f5f5;">
                        <img src="${data.style_photo_url}" alt="Customer visual style reference" style="width:100%; height:100%; object-fit:cover;">
                        ${data.detected_theme ? `
                        <div style="position:absolute; bottom:0; left:0; right:0; padding:24px 20px 10px; background:linear-gradient(transparent, rgba(0,0,0,0.65));">
                            <span style="color:white; font-size:0.65rem; font-weight:800; text-transform:uppercase; letter-spacing:1px;"><i class="fa-solid fa-wand-magic-sparkles mr-1"></i> ${data.detected_theme}</span>
                        </div>` : ''}
                    </div>` : `
                    <div style="margin-bottom:12px; display:flex; align-items:center; gap:8px; color:#c9c9c9; font-size:0.7rem; font-weight:600; font-style:italic;">
                        <i class="fa-regular fa-image"></i> No visual style attached
                    </div>`}

                    <div style="margin-bottom:15px; display:flex; align-items:center; gap:8px;">
                        <span style="font-size:0.6rem; font-weight:900; padding:4px 10px; border-radius:20px; text-transform:uppercase; tracking-wider; ${badgeStyle}">
                            ${data.status || 'Pending Review'}
                        </span>
                        ${isVisualStylistSubmission ? `
                        <span title="Submitted via AI Visual Stylist in the app" style="font-size:0.6rem; font-weight:800; padding:4px 10px; border-radius:20px; text-transform:uppercase; background:#f3e8ff; color:#7e22ce;">
                            <i class="fa-solid fa-mobile-screen-button mr-1"></i> App
                        </span>` : ''}
                    </div>

                    <div class="brand-font" style="font-size:1.6rem; font-weight:900; color:var(--text-main); line-height:1.2; margin-bottom:5px;">
                        ${data.customer_name}
                    </div>
                    <div style="font-size:0.75rem; color:var(--text-light); font-weight:600; margin-bottom:15px;">
                        <i class="fa-solid fa-phone mr-1 text-xs"></i> ${data.customer_phone}
                    </div>

                    ${hasPaymentData ? renderPaymentLedger(id, data) : ''}

                    <div style="background:#fafafa; padding:15px; border-radius:15px; font-size:0.8rem; font-weight:600; color:var(--text-main); border:1px solid #f8f8f8; margin-bottom:20px; flex-grow:1; min-height:80px;">
                        <span style="display:block; font-size:0.6rem; text-transform:uppercase; color:var(--text-light); font-weight:800; letter-spacing:0.5px; margin-bottom:4px;">Custom Design Manifest</span>
                        ${data.arrangement_details || 'No notes provided.'}
                    </div>

                    ${(data.recommended_flowers && data.recommended_flowers.length) ? `
                    <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:20px;">
                        ${data.recommended_flowers.map(f => `<span style="font-size:0.65rem; font-weight:700; padding:4px 10px; border-radius:20px; background:#fff3cd; color:#856404;">${f}</span>`).join('')}
                    </div>` : ''}

                    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #fbfbfb; padding-top:15px; margin-bottom:20px;">
                        <div>
                            <span style="display:block; font-size:0.55rem; text-transform:uppercase; color:var(--text-light); font-weight:800;">Fulfillment Target</span>
                            <span style="font-size:0.8rem; font-weight:800; color:var(--text-main);">${targetDate}</span>
                        </div>
                    </div>

                    ${isPendingReview ? `
                        <div style="display:flex; gap:10px;">
                            <button onclick="approveBooking('${id}')" style="flex:1; background:var(--secondary); border:2px solid var(--secondary); color:white; padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; cursor:pointer;">
                                <i class="fa-solid fa-check mr-1"></i> Approve
                            </button>
                            <button onclick="declineBooking('${id}')" style="flex:1; background:none; border:2px solid #dc3545; color:#dc3545; padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; cursor:pointer;">
                                <i class="fa-solid fa-xmark mr-1"></i> Decline
                            </button>
                        </div>
                    ` : data.status === 'Declined' ? `
                        <div style="width:100%; background:#fff8f8; border:1px dashed #dc3545; color:#dc3545; padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; text-align:center;">
                            <i class="fa-solid fa-ban mr-1"></i> Declined
                        </div>
                    ` : data.status !== 'Completed' ? `
                        <button onclick="advanceStatus('${id}', '${data.status}')" style="width:100%; background:none; border:2px solid var(--secondary); color:var(--secondary); padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; cursor:pointer; transition:0.3s;" onmouseover="this.style.background='var(--secondary)'; this.style.color='white';" onmouseout="this.style.background='none'; this.style.color='var(--secondary)';">
                            ${actionButtonText}
                        </button>
                    ` : `
                        <div style="width:100%; background:#f8fff9; border:1px dashed #27ae60; color:#27ae60; padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; text-align:center;">
                            <i class="fa-solid fa-circle-check mr-1"></i> Order Closed
                        </div>
                    `}
                </div>
                `;
            });
            reservationsGrid.innerHTML = html;
        });
    });

    // Approval Gate: Pending Review -> Approved / Declined
    async function approveBooking(id) {
        if (confirm('Approve this event booking? It will move into the fulfillment pipeline.')) {
            try {
                await getBranchPath('reservations').doc(id).update({
                    status: 'Approved',
                    approved_by: window.currentUserName || 'Admin',
                    updated_at: firebase.firestore.FieldValue.serverTimestamp()
                });
            } catch (err) {
                alert('Approval blocked: ' + err.message);
            }
        }
    }

    async function declineBooking(id) {
        const reason = prompt('Reason for declining this booking (optional):', '');
        if (reason === null) return; // user cancelled the prompt
        try {
            await getBranchPath('reservations').doc(id).update({
                status: 'Declined',
                decline_reason: reason,
                updated_at: firebase.firestore.FieldValue.serverTimestamp()
            });
        } catch (err) {
            alert('Decline update blocked: ' + err.message);
        }
    }

    // Feature 4 Workflow State Engine Controller (post-approval pipeline)
    async function advanceStatus(id, currentStatus) {
        let nextStatus = 'Confirmed & Sourcing';
        if (currentStatus === 'Approved') nextStatus = 'Confirmed & Sourcing';
        if (currentStatus === 'Confirmed & Sourcing') nextStatus = 'Ready for Pickup';
        if (currentStatus === 'Ready for Pickup') nextStatus = 'Completed';

        if (confirm(`Advance booking state to next milestone: "${nextStatus}"?`)) {
            try {
                await getBranchPath('reservations').doc(id).update({
                    status: nextStatus,
                    updated_at: firebase.firestore.FieldValue.serverTimestamp()
                });
            } catch (err) {
                alert('State machine updates blocked: ' + err.message);
            }
        }
    }

    async function cancelBooking(id) {
        if (confirm('Are you absolutely certain you want to delete this design pre-order record?')) {
            try {
                await getBranchPath('reservations').doc(id).delete();
            } catch (err) {
                alert('Purge Failure: ' + err.message);
            }
        }
    }

    // ---------------------------------------------------------------------
    // NEW: records a balance payment against a reservation. Uses a
    // Firestore TRANSACTION (db.runTransaction), not a plain .update() --
    // same reason as the mobile side: it re-reads amount_paid/balance_due
    // at write time instead of trusting numbers already sitting in this
    // page's onSnapshot cache, which could be stale if someone on the
    // mobile app (or another admin tab) recorded a payment moments ago.
    // ---------------------------------------------------------------------
    async function recordPayment(id) {
        const amountInput = document.getElementById(`payAmount_${id}`);
        const methodSelect = document.getElementById(`payMethod_${id}`);
        const noteInput = document.getElementById(`payNote_${id}`);

        const amount = parseFloat(amountInput.value);
        if (isNaN(amount) || amount <= 0) {
            alert('Enter a valid amount received.');
            return;
        }
        const method = methodSelect.value;
        const note = noteInput.value.trim();

        try {
            const ref = getBranchPath('reservations').doc(id);
            await db.runTransaction(async (transaction) => {
                const snap = await transaction.get(ref);
                if (!snap.exists) throw new Error('This reservation no longer exists.');
                const current = snap.data();
                const total = Number(current.total_amount || 0);
                const currentPaid = Number(current.amount_paid || 0);
                const newPaid = currentPaid + amount;
                const newBalance = Math.max(total - newPaid, 0);
                const history = Array.isArray(current.payment_history) ? current.payment_history : [];

                // Timestamp.now(), not FieldValue.serverTimestamp() -- the
                // same reason mobile's Dart code uses Timestamp.now() here:
                // server-timestamp sentinels aren't valid inside array
                // elements, only as a top-level document field.
                const entry = {
                    amount: amount,
                    method: method,
                    status: 'confirmed',
                    note: note,
                    recorded_by: window.currentUserName || 'Admin',
                    recorded_at: firebase.firestore.Timestamp.now()
                };

                const update = {
                    amount_paid: newPaid,
                    balance_due: newBalance,
                    payment_history: [...history, entry],
                    updated_at: firebase.firestore.FieldValue.serverTimestamp()
                };
                if (newBalance <= 0) update.deposit_paid = true;

                transaction.update(ref, update);
            });
        } catch (err) {
            alert('Could not record payment: ' + err.message);
        }
    }

    const reservationSettingsModal = document.getElementById('reservationSettingsModal');
    const reservationSettingsModalContent = document.getElementById('reservationSettingsModalContent');
    let blockedDatesUnsubscribe = null;

    function openReservationSettingsModal() {
        reservationSettingsModal.classList.remove('hidden');
        setTimeout(() => {
            reservationSettingsModalContent.classList.remove('scale-95', 'opacity-0');
        }, 10);
        loadMaxDailyCapacity();
        subscribeToBlockedDates();
    }

    function closeReservationSettingsModal() {
        reservationSettingsModalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            reservationSettingsModal.classList.add('hidden');
        }, 300);
        if (blockedDatesUnsubscribe) {
            blockedDatesUnsubscribe();
            blockedDatesUnsubscribe = null;
        }
    }

    async function loadMaxDailyCapacity() {
        const input = document.getElementById('maxDailyCapacityInput');
        try {
            const doc = await db.collection('settings').doc('reservation_config').get();
            const value = (doc.exists && typeof doc.data().maxDailyCapacity === 'number')
                ? doc.data().maxDailyCapacity
                : 1;
            input.value = value;
        } catch (err) {
            input.value = 1;
            console.error('Could not load reservation config:', err);
        }
    }

    async function saveMaxDailyCapacity() {
        const input = document.getElementById('maxDailyCapacityInput');
        const parsed = parseInt(input.value, 10);
        const value = (isNaN(parsed) || parsed < 1) ? 1 : parsed;
        try {
            await db.collection('settings').doc('reservation_config').set({
                maxDailyCapacity: value,
                updated_at: firebase.firestore.FieldValue.serverTimestamp()
            }, { merge: true });
            input.value = value;
            alert(`Saved: max ${value} reservation(s) per day`);
        } catch (err) {
            alert('Could not save: ' + err.message);
        }
    }

    function subscribeToBlockedDates() {
        const listEl = document.getElementById('blockedDatesList');
        const todayStr = formatDateStr(new Date());

        if (blockedDatesUnsubscribe) blockedDatesUnsubscribe();

        blockedDatesUnsubscribe = db.collection('blocked_dates').onSnapshot(snap => {
            const dates = snap.docs
                .map(d => ({ date: d.id, ...d.data() }))
                .filter(d => d.date >= todayStr)
                .sort((a, b) => a.date.localeCompare(b.date));

            if (dates.length === 0) {
                listEl.innerHTML = '<p class="text-xs text-gray-400 italic py-4 text-center">No dates are currently blocked.</p>';
                return;
            }

            listEl.innerHTML = dates.map(d => {
                const parsed = new Date(d.date + 'T00:00:00');
                const label = parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                return `
                    <div class="flex justify-between items-center bg-gray-50 rounded-xl px-4 py-3">
                        <span class="text-sm font-bold text-gray-700">${label}</span>
                        <button onclick="unblockDate('${d.date}')" class="text-red-500 hover:text-red-700 text-xs font-black uppercase tracking-wide">
                            <i class="fa-solid fa-trash-can mr-1"></i> Unblock
                        </button>
                    </div>
                `;
            }).join('');
        }, err => {
            listEl.innerHTML = `<p class="text-xs text-red-500 py-4 text-center">Could not load blocked dates: ${err.message}</p>`;
        });
    }

    function formatDateStr(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    async function blockSelectedDate() {
        const input = document.getElementById('blockDateInput');
        const dateStr = input.value;
        if (!dateStr) {
            alert('Please pick a date to block.');
            return;
        }
        try {
            await db.collection('blocked_dates').doc(dateStr).set({
                isBlocked: true,
                blocked_at: firebase.firestore.FieldValue.serverTimestamp()
            });
            input.value = '';
        } catch (err) {
            alert('Could not block this date: ' + err.message);
        }
    }

    async function unblockDate(dateStr) {
        try {
            await db.collection('blocked_dates').doc(dateStr).delete();
        } catch (err) {
            alert('Could not unblock this date: ' + err.message);
        }
    }
</script>

<?php include 'templates/footer.php'; ?>