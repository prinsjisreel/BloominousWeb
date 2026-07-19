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
            <h1 class="brand-font text-5xl font-black text-gray-800">Pre-Orders & Reservations</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Track event advanced customer arrangements, bouquet design requests, and live fulfillment lifecycles.</p>
        </div>
        <button onclick="toggleReservationForm()" class="btn-primary hover:scale-105 active:scale-95 transition-all flex items-center px-8 shadow-lg shadow-pink-100 uppercase tracking-widest text-xs font-black">
            <i class="fa-solid fa-plus mr-3"></i> Log Advanced Request
        </button>
    </div>

    <div id="reservationFormBox" style="display:none; background:white; padding:3rem; border-radius:35px; margin-bottom:3rem; border:1px solid var(--primary); border-style:dashed;">
        <h4 class="brand-font text-2xl font-black mb-8 text-gray-800">Advanced Floral Logging Protocol</h4>
        <form id="addReservationForm" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px;">
            <div>
                <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 1px;">Client Name</label>
                <input type="text" id="custName" placeholder="e.g. Juan Ddela Cruz" required style="width:100%; padding:14px 18px; border-radius:12px; border:1px solid #f0f0f0; outline: none; background:#fafafa; font-weight:600; font-size:0.9rem;">
            </div>
            <div>
                <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 1px;">Contact Number</label>
                <input type="tel" id="custPhone" placeholder="e.g. 09123456789" required style="width:100%; padding:14px 18px; border-radius:12px; border:1px solid #f0f0f0; outline: none; background:#fafafa; font-weight:600; font-size:0.9rem;">
            </div>
            <div>
                <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 1px;">Fulfillment/Event Date</label>
                <input type="date" id="fulfillmentDate" required style="width:100%; padding:14px 18px; border-radius:12px; border:1px solid #f0f0f0; outline: none; background:#fafafa; font-weight:600; font-size:0.9rem;">
            </div>
            <div style="grid-column: span 3;">
                <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 1px;">Customization Specs & Flower Types</label>
                <textarea id="arrangementDetails" placeholder="Specify arrangement style, floral varieties, wrappers, ribbons, and special greeting messages..." required style="width:100%; padding:14px 18px; border-radius:12px; border:1px solid #f0f0f0; outline: none; background:#fafafa; font-weight:600; font-size:0.9rem; min-height:100px; font-family:inherit;"></textarea>
            </div>
            <button type="submit" id="saveBookingBtn" class="btn-primary" style="grid-column: span 3; padding: 20px; text-transform: uppercase; letter-spacing: 2px; font-size: 0.7rem;">Authorize Order Manifest</button>
        </form>
    </div>

    <div id="reservationsGrid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap:30px;">
        <div style="grid-column: 1 / -1; text-align: center; padding: 80px; color: #ddd; font-style: italic; font-weight: 500;">Retrieving privilege records...</div>
    </div>
</main>

<script>
    function toggleReservationForm() {
        const box = document.getElementById('reservationFormBox');
        box.style.display = box.style.display === 'none' ? 'block' : 'none';
    }

    document.addEventListener('DOMContentLoaded', () => {
        const reservationsGrid = document.getElementById('reservationsGrid');

        // Feature 2: Real-time branch data tracking link
        getBranchPath('reservations').orderBy('created_at', 'desc').onSnapshot(snap => {
            if (snap.empty) {
                reservationsGrid.innerHTML = `
                    <div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #888;">
                        <i class="fa-solid fa-calendar-check" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3; color: var(--primary);"></i>
                        <p class="font-semibold text-gray-500 text-sm tracking-wider uppercase">No active advanced bookings mapped to this branch node.</p>
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
                let badgeStyle = "background:#fff3cd; color:#856404;"; 
                let actionButtonText = '<i class="fa-solid fa-square-check mr-2"></i> Confirm Bouquet Selection';
                
                if (data.status === 'Confirmed & Sourcing') {
                    badgeStyle = "background:#d1ecf1; color:#0c5460;";
                    actionButtonText = '<i class="fa-solid fa-wand-magic-sparkles mr-2"></i> Flag as Ready for Pickup';
                } else if (data.status === 'Ready for Pickup') {
                    badgeStyle = "background:#e2e3e5; color:#383d41;";
                    actionButtonText = '<i class="fa-solid fa-box-open mr-2"></i> Handover/Complete Order';
                } else if (data.status === 'Completed') {
                    badgeStyle = "background:#d4edda; color:#155724;";
                }

                html += `
                <div style="background:white; border:1px solid #f0f0f0; padding:2.5rem 2rem; border-radius:30px; position:relative; box-shadow: 0 10px 30px rgba(0,0,0,0.01); display:flex; flex-direction:column; justify-between; height:100%;">
                    
                    <button onclick="cancelBooking('${id}')" title="Terminate Log" style="position:absolute; top:20px; right:20px; color:#ccc; background:none; border:none; cursor:pointer; font-size:0.95rem; transition:0.2s;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='#ccc'">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>

                    <div style="margin-bottom:15px;">
                        <span style="font-size:0.6rem; font-weight:900; padding:4px 10px; border-radius:20px; text-transform:uppercase; tracking-wider; ${badgeStyle}">
                            ${data.status || 'Pending Review'}
                        </span>
                    </div>

                    <div class="brand-font" style="font-size:1.6rem; font-weight:900; color:var(--text-main); line-height:1.2; margin-bottom:5px;">
                        ${data.customer_name}
                    </div>
                    <div style="font-size:0.75rem; color:var(--text-light); font-weight:600; margin-bottom:15px;">
                        <i class="fa-solid fa-phone mr-1 text-xs"></i> ${data.customer_phone}
                    </div>

                    <div style="background:#fafafa; padding:15px; border-radius:15px; font-size:0.8rem; font-weight:600; color:var(--text-main); border:1px solid #f8f8f8; margin-bottom:20px; flex-grow:1; min-height:80px;">
                        <span style="display:block; font-size:0.6rem; text-transform:uppercase; color:var(--text-light); font-weight:800; letter-spacing:0.5px; margin-bottom:4px;">Custom Design Manifest</span>
                        ${data.arrangement_details}
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #fbfbfb; padding-top:15px; margin-bottom:20px;">
                        <div>
                            <span style="display:block; font-size:0.55rem; text-transform:uppercase; color:var(--text-light); font-weight:800;">Fulfillment Target</span>
                            <span style="font-size:0.8rem; font-weight:800; color:var(--text-main);">${targetDate}</span>
                        </div>
                    </div>

                    ${data.status !== 'Completed' ? `
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

        // Feature 1: Process inputs smoothly on database push
        document.getElementById('addReservationForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('saveBookingBtn');
            btn.disabled = true;
            btn.innerText = 'Processing Order...';

            try {
                await getBranchPath('reservations').add({
                    customer_name: document.getElementById('custName').value,
                    customer_phone: document.getElementById('custPhone').value,
                    fulfillment_date: document.getElementById('fulfillmentDate').value,
                    arrangement_details: document.getElementById('arrangementDetails').value,
                    status: 'Pending Review',
                    created_at: firebase.firestore.FieldValue.serverTimestamp()
                });

                alert('Advanced Request Manifest Added Successfully!');
                document.getElementById('addReservationForm').reset();
                toggleReservationForm();
            } catch (err) {
                alert('Database Input Rejection: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = 'Authorize Order Manifest';
            }
        };
    });

    // Feature 4 Workflow State Engine Controller
    async function advanceStatus(id, currentStatus) {
        let nextStatus = 'Confirmed & Sourcing';
        if (currentStatus === 'Confirmed & Sourcing') nextStatus = 'Ready for Pickup';
        if (currentStatus === 'Ready for Pickup') nextStatus = 'Completed';

        if (confirm(`Advance pre-order state to next milestone: "${nextStatus}"?`)) {
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