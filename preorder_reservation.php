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
    </div>

    <div id="reservationsGrid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap:30px;">
        <div style="grid-column: 1 / -1; text-align: center; padding: 80px; color: #ddd; font-style: italic; font-weight: 500;">Retrieving privilege records...</div>
    </div>
</main>

<script>
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
</script>

<?php include 'templates/footer.php'; ?>