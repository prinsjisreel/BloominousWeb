<?php 
/**
 * BLOOMINOUS - Pre-Order & Reservation Management (Firebase Spoke)
 *
 * DATA LAYOUT (shared with the app):
 *   reservations/{reservationId}      <- TOP-LEVEL collection
 *     branchId: "main_branch"         <- the branch is a FIELD, not part of the path
 *
 * Status words (same as the app):
 *   Pending Review -> Reserved -> Confirmed & Sourcing -> Ready for Pickup -> Fulfilled
 *   (or Declined). Old web words 'Approved'/'Completed' display as Reserved/Fulfilled.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php'; 
?>

<style>
    /* ============ PAGE TOKENS ============
       The reservation cards are built by JavaScript with inline
       style="..." strings. Every hardcoded color in those strings that
       has no exact match in header.php's theme variables is named here
       instead. Light values are EXACTLY what the page used before, so
       light mode is unchanged; dark mode swaps them below. */
    :root {
        --res-soft-border: #f8f8f8;
        --res-divider: #fbfbfb;
        --res-track: #eee;
        --res-muted: #ccc;
        --res-faint: #bbb;
        --res-placeholder: #c9c9c9;
        --res-empty: #888;
        --res-loading: #ddd;
        --res-photo-bg: #f5f5f5;
        --res-input-bg: #ffffff;
        --res-success-bg: #f8fff9;
        --res-danger-bg: #fff8f8;
    }
    html[data-theme="dark"] {
        --res-soft-border: var(--border-color);
        --res-divider: var(--border-color);
        --res-track: var(--border-color);
        --res-muted: var(--text-light);
        --res-faint: var(--text-light);
        --res-placeholder: var(--text-light);
        --res-empty: var(--text-light);
        --res-loading: var(--text-light);
        --res-photo-bg: var(--surface-alt);
        --res-input-bg: var(--surface-alt);
        --res-success-bg: rgba(39, 174, 96, 0.12);
        --res-danger-bg: rgba(220, 53, 69, 0.12);
    }

    /* ============ DARK MODE: Tailwind class remap ============
       Scoped to this page's content and its settings modal only, so the
       shared sidebar/topbar are untouched. :is() lists both areas once. */
    html[data-theme="dark"] :is(.pos-content, #reservationSettingsModalContent) :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] :is(.pos-content, #reservationSettingsModalContent) :is(.text-gray-500, .text-gray-400, .text-gray-300) { color: var(--text-light); }
    html[data-theme="dark"] :is(.pos-content, #reservationSettingsModalContent) .bg-white { background-color: var(--surface); }
    html[data-theme="dark"] :is(.pos-content, #reservationSettingsModalContent) :is(.bg-gray-50, .bg-gray-100) { background-color: var(--surface-alt); }
    html[data-theme="dark"] :is(.pos-content, #reservationSettingsModalContent) :is(.border-gray-50, .border-gray-100, .border-gray-200) { border-color: var(--border-color); }
    /* Native controls (dropdown menus, the date picker's calendar icon) */
    html[data-theme="dark"] :is(.pos-content, #reservationSettingsModalContent) :is(input, select) { color-scheme: dark; }
</style>

<main class="pos-content" style="padding: 1.5rem; max-width: 1400px; margin: 0 auto;">
    <div class="flex justify-between items-center mb-12 flex-wrap gap-4">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Event Organizer & Reservation</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Live monitoring of event reservations submitted through the app's AI Visual Stylist — review, reserve, and track fulfillment.</p>
        </div>
        <button onclick="openReservationSettingsModal()" class="flex items-center gap-2 px-6 py-3 rounded-2xl border-2 font-black text-xs uppercase tracking-widest transition-all flex-shrink-0" style="border-color:var(--secondary); color:var(--secondary);" onmouseover="this.style.background='var(--secondary)'; this.style.color='white';" onmouseout="this.style.background='none'; this.style.color='var(--secondary)';">
            <i class="fa-solid fa-gear"></i>
            <span>Reservation Settings</span>
        </button>
    </div>

    <div id="reservationsGrid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap:30px;">
        <div style="grid-column: 1 / -1; text-align: center; padding: 80px; color: var(--res-loading); font-style: italic; font-weight: 500;">Retrieving privilege records...</div>
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
    // =====================================================================
    // STATUS WORDS -- copied from the app (lib/preorder_reservations_page.dart).
    // =====================================================================
    const RES_STATUS = {
        PENDING: 'Pending Review',
        RESERVED: 'Reserved',
        SOURCING: 'Confirmed & Sourcing',
        READY: 'Ready for Pickup',
        FULFILLED: 'Fulfilled',
        DECLINED: 'Declined'
    };

    // Words older versions of THIS web page used to write. Displayed as
    // the app's words; no data is changed.
    const LEGACY_STATUS_MAP = {
        'Approved': RES_STATUS.RESERVED,
        'Completed': RES_STATUS.FULFILLED
    };

    function normalizeStatus(raw) {
        if (!raw) return RES_STATUS.PENDING;
        return LEGACY_STATUS_MAP[raw] || raw;
    }

    const NEXT_STATUS = {
        [RES_STATUS.RESERVED]: RES_STATUS.SOURCING,
        [RES_STATUS.SOURCING]: RES_STATUS.READY,
        [RES_STATUS.READY]: RES_STATUS.FULFILLED
    };

    const STATUS_META = {
        [RES_STATUS.PENDING]:   { badge: 'background:#fff3cd; color:#856404;', action: null },
        [RES_STATUS.RESERVED]:  { badge: 'background:#e7f0ff; color:#1d4ed8;', action: '<i class="fa-solid fa-seedling mr-2"></i> Begin Sourcing' },
        [RES_STATUS.SOURCING]:  { badge: 'background:#d1ecf1; color:#0c5460;', action: '<i class="fa-solid fa-wand-magic-sparkles mr-2"></i> Flag as Ready for Pickup' },
        [RES_STATUS.READY]:     { badge: 'background:#e2e3e5; color:#383d41;', action: '<i class="fa-solid fa-box-open mr-2"></i> Mark as Fulfilled' },
        [RES_STATUS.FULFILLED]: { badge: 'background:#d4edda; color:#155724;', action: null },
        [RES_STATUS.DECLINED]:  { badge: 'background:#f8d7da; color:#721c24;', action: null }
    };

    // Same identity the app records (the signed-in email).
    function currentStaffIdentity() {
        return window.currentUserEmail || window.currentUserName || 'Admin';
    }

    // Reads fulfillment_date like the app: a Firestore Timestamp OR text.
    // "YYYY-MM-DD" is treated as a LOCAL calendar date, so it never shifts a day.
    function parseReservationDate(raw) {
        if (!raw) return null;
        if (typeof raw.toDate === 'function') return raw.toDate();
        if (typeof raw === 'string') {
            const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (m) return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
            const d = new Date(raw);
            return isNaN(d.getTime()) ? null : d;
        }
        return null;
    }

    // =====================================================================
    // WHERE RESERVATIONS LIVE: one top-level collection for every branch.
    // =====================================================================
    const RESERVATIONS = 'reservations';

    function reservationRef(id) {
        return db.collection(RESERVATIONS).doc(id);
    }

    let branchNames = {};          // branchId -> display name (from branch documents)
    let latestReservations = [];   // [{ id, branchId, data }]
    let loadError = null;          // set when the reservations listener fails

    // 'main_branch' -> 'Main Branch' when no branch document has a name for it.
    function branchDisplayName(branchId) {
        if (!branchId) return 'Unassigned';
        if (branchNames[branchId]) return branchNames[branchId];
        return branchId
            .split('_')
            .filter(Boolean)
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    }

    // ---------------------------------------------------------------------
    // Payment ledger rendering. MIRRORS _buildPaymentLedgerSection in the app.
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
                            <i class="fa-solid fa-receipt mr-1" style="color:var(--res-faint);"></i>
                            ${dateStr} &middot; ${methodLabel}${note ? ` (${note})` : ''}
                        </span>
                        <span style="font-weight:800; flex-shrink:0;">₱${amt.toLocaleString(undefined, { maximumFractionDigits: 0 })}</span>
                    </div>
                `;
            }).join('')
            : `<p style="font-size:0.72rem; color:var(--res-faint); font-style:italic; margin:4px 0;">No balance payments recorded yet.</p>`;

        const actionHtml = balance > 0 ? `
            <div style="display:flex; gap:6px; margin-bottom:6px;">
                <input type="number" min="1" id="payAmount_${id}" placeholder="Amount" style="flex:1; padding:8px 10px; border-radius:10px; border:1px solid var(--res-track); background:var(--res-input-bg); color:var(--text-main); font-size:0.75rem; box-sizing:border-box;">
                <select id="payMethod_${id}" style="width:90px; padding:8px 6px; border-radius:10px; border:1px solid var(--res-track); background:var(--res-input-bg); color:var(--text-main); font-size:0.7rem;">
                    <option value="cash">Cash</option>
                    <option value="gcash">GCash</option>
                    <option value="maya">Maya</option>
                </select>
            </div>
            <input type="text" id="payNote_${id}" placeholder="Note (optional)" style="width:100%; padding:8px 10px; border-radius:10px; border:1px solid var(--res-track); background:var(--res-input-bg); color:var(--text-main); font-size:0.72rem; margin-bottom:6px; box-sizing:border-box;">
            <button onclick="recordPayment('${id}')" style="width:100%; background:none; border:2px solid var(--secondary); color:var(--secondary); padding:8px; border-radius:10px; font-size:0.68rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; cursor:pointer;">
                <i class="fa-solid fa-credit-card mr-1"></i> Record Payment
            </button>
        ` : `
            <div style="width:100%; background:var(--res-success-bg); border:1px dashed #27ae60; color:#27ae60; padding:8px; border-radius:10px; font-size:0.68rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; text-align:center;">
                <i class="fa-solid fa-circle-check mr-1"></i> Fully Paid
            </div>
        `;

        return `
            <div style="background:var(--surface-alt); padding:14px; border-radius:15px; border:1px solid var(--res-soft-border); margin-bottom:15px;">
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
                <div style="height:6px; border-radius:4px; background:var(--res-track); overflow:hidden; margin-bottom:10px;">
                    <div style="height:100%; width:${(progress * 100).toFixed(0)}%; background:${balance <= 0 ? '#27ae60' : 'var(--secondary)'};"></div>
                </div>
                ${historyHtml}
                <div style="margin-top:10px;">
                    ${actionHtml}
                </div>
            </div>
        `;
    }

    function renderReservationCard(r) {
        const { id, branchId, data } = r;

        const parsedTarget = parseReservationDate(data.fulfillment_date);
        const targetDate = parsedTarget
            ? parsedTarget.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
            : 'N/A';

        const status = normalizeStatus(data.status);
        const meta = STATUS_META[status] || STATUS_META[RES_STATUS.PENDING];

        const isPendingReview = status === RES_STATUS.PENDING;
        const isDeclined = status === RES_STATUS.DECLINED;
        const isFulfilled = status === RES_STATUS.FULFILLED;
        const isVisualStylistSubmission = data.source === 'visual_stylist';
        const hasPaymentData = data.total_amount !== undefined && data.total_amount !== null;

        return `
        <div style="background:var(--surface); border:1px solid var(--border-color); padding:2.5rem 2rem; border-radius:30px; position:relative; box-shadow: 0 10px 30px rgba(0,0,0,0.01); display:flex; flex-direction:column; height:100%;">
            
            <button onclick="cancelBooking('${id}')" title="Terminate Log" style="position:absolute; top:20px; right:20px; color:var(--res-muted); background:none; border:none; cursor:pointer; font-size:0.95rem; transition:0.2s;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--res-muted)'">
                <i class="fa-solid fa-trash-can"></i>
            </button>

            ${data.style_photo_url ? `
            <div style="margin:-2.5rem -2rem 15px -2rem; height:170px; overflow:hidden; border-radius:30px 30px 0 0; position:relative; background:var(--res-photo-bg);">
                <img src="${data.style_photo_url}" alt="Customer visual style reference" style="width:100%; height:100%; object-fit:cover;">
                ${data.detected_theme ? `
                <div style="position:absolute; bottom:0; left:0; right:0; padding:24px 20px 10px; background:linear-gradient(transparent, rgba(0,0,0,0.65));">
                    <span style="color:white; font-size:0.65rem; font-weight:800; text-transform:uppercase; letter-spacing:1px;"><i class="fa-solid fa-wand-magic-sparkles mr-1"></i> ${data.detected_theme}</span>
                </div>` : ''}
            </div>` : `
            <div style="margin-bottom:12px; display:flex; align-items:center; gap:8px; color:var(--res-placeholder); font-size:0.7rem; font-weight:600; font-style:italic;">
                <i class="fa-regular fa-image"></i> No visual style attached
            </div>`}

            <div style="margin-bottom:15px; display:flex; align-items:center; gap:8px;">
                <span style="font-size:0.6rem; font-weight:900; padding:4px 10px; border-radius:20px; text-transform:uppercase; letter-spacing:0.5px; ${meta.badge}">
                    ${status}
                </span>
                ${isVisualStylistSubmission ? `
                <span title="Submitted via AI Visual Stylist in the app" style="font-size:0.6rem; font-weight:800; padding:4px 10px; border-radius:20px; text-transform:uppercase; background:#f3e8ff; color:#7e22ce;">
                    <i class="fa-solid fa-mobile-screen-button mr-1"></i> App
                </span>` : ''}
            </div>

            <div class="brand-font" style="font-size:1.6rem; font-weight:900; color:var(--text-main); line-height:1.2; margin-bottom:5px;">
                ${data.customer_name || 'Customer'}
            </div>
            <div style="font-size:0.75rem; color:var(--text-light); font-weight:600; margin-bottom:15px;">
                <i class="fa-solid fa-phone mr-1 text-xs"></i> ${data.customer_phone || 'No phone on file'}
            </div>

            ${hasPaymentData ? renderPaymentLedger(id, data) : ''}

            <div style="background:var(--surface-alt); padding:15px; border-radius:15px; font-size:0.8rem; font-weight:600; color:var(--text-main); border:1px solid var(--res-soft-border); margin-bottom:20px; flex-grow:1; min-height:80px;">
                <span style="display:block; font-size:0.6rem; text-transform:uppercase; color:var(--text-light); font-weight:800; letter-spacing:0.5px; margin-bottom:4px;">Custom Design Manifest</span>
                ${data.arrangement_details || 'No notes provided.'}
            </div>

            ${(data.recommended_flowers && data.recommended_flowers.length) ? `
            <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:20px;">
                ${data.recommended_flowers.map(f => `<span style="font-size:0.65rem; font-weight:700; padding:4px 10px; border-radius:20px; background:#fff3cd; color:#856404;">${f}</span>`).join('')}
            </div>` : ''}

            <div style="border-top:1px solid var(--res-divider); padding-top:15px; margin-bottom:20px;">
                <span style="display:block; font-size:0.55rem; text-transform:uppercase; color:var(--text-light); font-weight:800;">Fulfillment Target</span>
                <span style="font-size:0.8rem; font-weight:800; color:var(--text-main);">${targetDate}</span>
                <span style="display:block; font-size:0.65rem; color:var(--text-light); font-style:italic; margin-top:4px;">Branch: ${branchDisplayName(branchId)}</span>
            </div>

            ${isPendingReview ? `
                <div style="display:flex; gap:10px;">
                    <button onclick="reserveBooking('${id}')" style="flex:1; background:var(--secondary); border:2px solid var(--secondary); color:white; padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; cursor:pointer;">
                        <i class="fa-solid fa-calendar-check mr-1"></i> Reserve
                    </button>
                    <button onclick="declineBooking('${id}')" style="flex:1; background:none; border:2px solid #dc3545; color:#dc3545; padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; cursor:pointer;">
                        <i class="fa-solid fa-xmark mr-1"></i> Decline
                    </button>
                </div>
            ` : isDeclined ? `
                <div style="width:100%; background:var(--res-danger-bg); border:1px dashed #dc3545; color:#dc3545; padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; text-align:center;">
                    <i class="fa-solid fa-ban mr-1"></i> Declined
                </div>
            ` : isFulfilled ? `
                <div style="width:100%; background:var(--res-success-bg); border:1px dashed #27ae60; color:#27ae60; padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; text-align:center;">
                    <i class="fa-solid fa-circle-check mr-1"></i> Fulfilled
                </div>
            ` : meta.action ? `
                <button onclick="advanceStatus('${id}')" style="width:100%; background:none; border:2px solid var(--secondary); color:var(--secondary); padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; cursor:pointer; transition:0.3s;" onmouseover="this.style.background='var(--secondary)'; this.style.color='white';" onmouseout="this.style.background='none'; this.style.color='var(--secondary)';">
                    ${meta.action}
                </button>
            ` : `
                <div style="width:100%; padding:10px; border-radius:12px; font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:1px; text-align:center; color:var(--text-light); border:1px dashed var(--border-color);">
                    Unknown status: ${status}
                </div>
            `}
        </div>
        `;
    }

    // Single place that decides what the grid shows. loadError is checked
    // FIRST, so a later redraw can never cover a loading error.
    function renderReservations() {
        const grid = document.getElementById('reservationsGrid');

        if (loadError) {
            grid.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: #dc3545; font-size: 0.85rem; font-weight: 600;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 2rem; margin-bottom: 12px; display:block;"></i>
                    Could not load reservations: ${loadError}
                </div>
            `;
            return;
        }

        if (latestReservations.length === 0) {
            grid.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 60px; color: var(--res-empty);">
                    <i class="fa-solid fa-calendar-check" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3; color: var(--primary);"></i>
                    <p class="font-semibold text-gray-500 text-sm tracking-wider uppercase">No event reservations submitted via the Visual Stylist yet.</p>
                </div>
            `;
            return;
        }

        grid.innerHTML = latestReservations.map(renderReservationCard).join('');
    }

    document.addEventListener('DOMContentLoaded', () => {
        // Branch names, used only for the "Branch: ..." label on each card.
        db.collection('branches').onSnapshot(snap => {
            branchNames = {};
            snap.forEach(doc => {
                branchNames[doc.id] = doc.data().name || doc.id;
            });
            renderReservations();
        }, err => {
            console.warn('Could not load branch names (labels fall back to ids):', err);
        });

        // Every reservation, from every branch, in ONE top-level collection.
        // Sorted here (newest first) instead of with .orderBy(), so a
        // reservation missing created_at is still shown (at the bottom).
        db.collection(RESERVATIONS).onSnapshot(snap => {
            loadError = null;
            latestReservations = snap.docs
                .map(doc => ({
                    id: doc.id,
                    branchId: (doc.data().branchId || '').toString(),
                    data: doc.data()
                }))
                .sort((a, b) => {
                    const aMs = a.data.created_at && a.data.created_at.toMillis ? a.data.created_at.toMillis() : 0;
                    const bMs = b.data.created_at && b.data.created_at.toMillis ? b.data.created_at.toMillis() : 0;
                    return bMs - aMs;
                });
            renderReservations();
        }, err => {
            console.error('Could not load reservations:', err);
            loadError = err.message;
            renderReservations();
        });
    });

    // ---------------------------------------------------------------------
    // ACTIONS -- all target reservations/{id}.
    // ---------------------------------------------------------------------

    // Approval gate: Pending Review -> Reserved (the app's word)
    async function reserveBooking(id) {
        if (confirm('Reserve this event booking? It will move into the fulfillment pipeline.')) {
            try {
                await reservationRef(id).update({
                    status: RES_STATUS.RESERVED,
                    approved_by: currentStaffIdentity(),
                    updated_at: firebase.firestore.FieldValue.serverTimestamp()
                });
            } catch (err) {
                alert('Reservation blocked: ' + err.message);
            }
        }
    }

    async function declineBooking(id) {
        const reason = prompt('Reason for declining this booking (optional):', '');
        if (reason === null) return; // user cancelled the prompt
        try {
            await reservationRef(id).update({
                status: RES_STATUS.DECLINED,
                decline_reason: reason,
                updated_at: firebase.firestore.FieldValue.serverTimestamp()
            });
        } catch (err) {
            alert('Decline update blocked: ' + err.message);
        }
    }

    // Advances using the status stored in Firestore right now, so a stale
    // card can never push an order backwards.
    async function advanceStatus(id) {
        const ref = reservationRef(id);
        try {
            const snap = await ref.get();
            if (!snap.exists) {
                alert('This reservation no longer exists.');
                return;
            }
            const current = normalizeStatus(snap.data().status);
            const next = NEXT_STATUS[current];
            if (!next) {
                alert(`"${current}" has no next stage.`);
                return;
            }
            if (!confirm(`Advance booking state to next milestone: "${next}"?`)) return;

            await db.runTransaction(async (transaction) => {
                const fresh = await transaction.get(ref);
                if (!fresh.exists) throw new Error('This reservation no longer exists.');
                const freshStatus = normalizeStatus(fresh.data().status);
                if (freshStatus !== current) {
                    throw new Error(`It was changed to "${freshStatus}" by someone else. Please review it again.`);
                }
                transaction.update(ref, {
                    status: next,
                    updated_at: firebase.firestore.FieldValue.serverTimestamp()
                });
            });
        } catch (err) {
            alert('State update blocked: ' + err.message);
        }
    }

    async function cancelBooking(id) {
        if (confirm('Are you absolutely certain you want to delete this design pre-order record?')) {
            try {
                await reservationRef(id).delete();
            } catch (err) {
                alert('Purge Failure: ' + err.message);
            }
        }
    }

    // Records a balance payment inside a TRANSACTION (same as the app).
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
            const ref = reservationRef(id);
            await db.runTransaction(async (transaction) => {
                const snap = await transaction.get(ref);
                if (!snap.exists) throw new Error('This reservation no longer exists.');
                const current = snap.data();
                const total = Number(current.total_amount || 0);
                const currentPaid = Number(current.amount_paid || 0);
                const newPaid = currentPaid + amount;
                const newBalance = Math.max(total - newPaid, 0);
                const history = Array.isArray(current.payment_history) ? current.payment_history : [];

                // Timestamp.now(), not serverTimestamp() -- server timestamps
                // aren't allowed inside array elements.
                const entry = {
                    amount: amount,
                    method: method,
                    status: 'confirmed',
                    note: note,
                    recorded_by: currentStaffIdentity(),
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