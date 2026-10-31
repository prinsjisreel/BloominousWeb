<?php   
/**
 * BLOOMINOUS - Order Management (Firebase Spoke)
 *
 * Status changes are step-by-step BUTTONS instead of a dropdown, so an order
 * can never jump over a stage (e.g. Pending straight to Confirmed).
 *
 *   Online  : Pending -> Processing -> Prepared -> In Transit -> Confirmed (needs POD photo)
 *   Walk-in : Pending -> Processing -> Prepared -> Confirmed
 *             (+ a separate Cancel button, any time before Confirmed)
 *
 * Every step is written through a Firestore transaction that re-checks the order's
 * CURRENT status first, so two staff clicking at once (or a stale tab) can't skip
 * or double-apply a step.
 *
 * THEME: light mode uses the theme variables from templates/header.php. Dark mode
 * has its own explicit block at the bottom of the <style> using the same palette
 * header.php defines, so the cards can never fall back to white.
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
    .stat-card { background: var(--surface, #fff); padding: 2.5rem; border-radius: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 20px; border: 1px solid var(--border-color, #f0f0f0); }
    .stat-card h3 { color: var(--text-main, #363949); }
    .stat-card i { font-size: 1.5rem; width: 65px; height: 65px; border-radius: 20px; display: flex; align-items: center; justify-content: center; }
    .pink-bg { background: rgba(233, 30, 99, 0.1); color: var(--primary); }
    .indigo-bg { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .teal-bg { background: rgba(0, 206, 209, 0.1); color: #00ced1; }
    .list-card { background: var(--surface, #fff); border-radius: 35px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid var(--border-color, #f0f0f0); overflow: hidden; }
    #orderTable { width: 100%; border-collapse: collapse; }
    #orderTable th { text-align: left; padding: 25px 20px; color: var(--text-light); border-bottom: 1px solid var(--border-color, #f0f0f0); text-transform: uppercase; font-size: 0.75rem; font-weight: 800; letter-spacing: 1px; background: var(--surface-alt, #fafafa); }
    #orderTable td { padding: 20px; border-bottom: 1px solid var(--border-color, #f8f9fa); color: var(--text-main); font-size: 0.9rem; font-weight: 500; }
    .customer-avatar { width: 45px; height: 45px; background: rgba(245, 158, 11, 0.12); color: var(--primary); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 15px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.1rem; font-family: 'Cormorant Garamond', serif; }

    /* Online / Walk-in channel tabs — this is the split the whole page pivots on now:
       every query, stat, and status flow below is scoped to whichever tab is active. */
    .channel-tabs { display: flex; gap: 10px; margin-bottom: 2rem; }
    .channel-tab { padding: 14px 28px; border-radius: 18px; border: 1px solid var(--border-color, #f0f0f0); background: var(--surface, #fff); font-weight: 800; font-size: 0.85rem; cursor: pointer; color: var(--text-light); transition: 0.2s; display: flex; align-items: center; gap: 10px; }
    .channel-tab .count { background: var(--surface-alt, #f5f5f5); color: var(--text-light); padding: 2px 10px; border-radius: 50px; font-size: 0.72rem; }
    .channel-tab.active { border-color: var(--primary); background: rgba(245, 158, 11, 0.1); color: var(--primary); }
    .channel-tab.active .count { background: var(--primary); color: #fff; }

    /* --- Step-by-step status cell (replaces the old <select>) --- */
    .status-cell { display: flex; flex-direction: column; gap: 10px; min-width: 210px; }
    .status-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

    .status-pill { padding: 6px 14px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; white-space: nowrap; }
    .status-pending    { background: rgba(245, 158, 11, 0.14); color: #F59E0B; }
    .status-processing { background: rgba(123, 121, 242, 0.16); color: #8b89f5; }
    .status-prepared   { background: rgba(0, 206, 209, 0.14); color: #00c4c7; }
    .status-in-transit { background: rgba(77, 163, 255, 0.16); color: #4da3ff; }
    .status-confirmed  { background: rgba(46, 204, 113, 0.16); color: #2ecc71; }
    .status-cancelled  { background: rgba(150, 150, 150, 0.18); color: #9a9a9a; text-decoration: line-through; }

    /* Progress dots: one per stage in this channel's flow; filled = already reached */
    .step-dots { display: flex; gap: 5px; align-items: center; }
    .step-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--border-color, #e5e5e5); }
    .step-dot.done { background: var(--primary); }

    .status-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn-next-step { padding: 10px 16px; border-radius: 12px; border: none; background: var(--primary); color: #fff; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
    .btn-next-step:hover { filter: brightness(1.08); transform: translateY(-1px); }
    .btn-cancel-order { padding: 10px 14px; border-radius: 12px; border: 1px solid rgba(248, 113, 113, 0.45); background: transparent; color: #f87171; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
    .btn-cancel-order:hover { background: rgba(248, 113, 113, 0.1); }
    .btn-next-step:disabled, .btn-cancel-order:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
    .status-done-note { font-size: 0.68rem; font-weight: 700; color: var(--text-light); }

    /* Proof of Delivery capture modal (online order Confirm step) */
    #podCaptureOverlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
    #podCaptureOverlay.open { display: flex; }
    #podCaptureModal { background: var(--surface, #fff); color: var(--text-main); border: 1px solid var(--border-color, transparent); border-radius: 30px; padding: 2.5rem; max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 30px 60px rgba(0,0,0,0.25); }
    #podCaptureModal h3 { font-family: 'Cormorant Garamond', serif; font-weight: 900; font-size: 1.6rem; margin: 0 0 0.5rem; color: var(--text-main); }
    #podCaptureModal p.pod-sub { color: var(--text-light); font-size: 0.8rem; font-weight: 600; margin-bottom: 1.5rem; }
    .pod-field { margin-bottom: 1.1rem; }
    .pod-field label { display: block; font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 8px; }
    .pod-field input[type="text"] { width: 100%; padding: 14px 16px; border-radius: 14px; border: 1px solid var(--border-color, #f0f0f0); background: var(--surface-alt, #fafafa); color: var(--text-main); font-weight: 600; font-size: 0.88rem; outline: none; }
    .pod-field input[type="text"]:focus { border-color: var(--primary); }
    #podPreviewWrap { display: none; margin-top: 12px; text-align: center; }
    #podPreviewImg { max-width: 100%; max-height: 220px; border-radius: 14px; border: 1px solid var(--border-color, #f0f0f0); }
    .pod-drop { border: 2px dashed var(--border-color, #f0f0f0); border-radius: 18px; padding: 2rem; text-align: center; cursor: pointer; color: var(--text-light); font-weight: 700; font-size: 0.85rem; background: var(--surface-alt, #fafafa); }
    .pod-drop:hover { border-color: var(--primary); color: var(--primary); }
    #podCaptureError { color: var(--primary); font-size: 0.8rem; font-weight: 700; margin-bottom: 1rem; display: none; }
    .pod-actions { display: flex; gap: 12px; margin-top: 1.5rem; }
    .pod-actions button { flex: 1; padding: 16px; border-radius: 18px; font-weight: 800; border: none; cursor: pointer; }
    .pod-cancel { background: var(--surface-alt, #f5f5f5); color: var(--text-light); }
    .pod-confirm { background: var(--primary); color: #fff; }

    /* Tailwind classes used in the markup, re-pointed at the theme (both modes) */
    .order-content .text-gray-800 { color: var(--text-main); }
    .order-content .text-gray-400, .order-content .text-gray-300 { color: var(--text-light); }
    .order-content .text-pink-600 { color: var(--primary); }          /* peso amounts -> brand amber */
    .order-content .bg-white { background-color: var(--surface, #fff); color: var(--text-main); }
    .order-content .border-gray-100, .order-content .border-gray-50 { border-color: var(--border-color, #f0f0f0); }
    .order-content .bg-gray-50\/30 { background-color: var(--surface-alt, rgba(249, 250, 251, 0.3)); }
    #orderSearch::placeholder { color: var(--text-light); }

    /* =====================================================================
       DARK MODE — explicit and !important on purpose.
       header.php puts data-theme="dark" on <html> and defines this exact palette
       (#2A241D card, #221D17 alt, #3F382F border, #EAE6DF text, #A0998F muted).
       Spelling the colors out here means no card, tab or button on this page can
       ever stay white, even if a variable fails to resolve or another stylesheet
       sets a background after this one.
       ===================================================================== */
    html[data-theme="dark"] .stat-card,
    html[data-theme="dark"] .list-card,
    html[data-theme="dark"] #podCaptureModal {
        background: #2A241D !important;
        border-color: #3F382F !important;
        box-shadow: none !important;
    }
    html[data-theme="dark"] .stat-card h3,
    html[data-theme="dark"] #podCaptureModal h3 { color: #EAE6DF !important; }
    html[data-theme="dark"] #podCaptureModal { color: #EAE6DF !important; }

    /* Table */
    html[data-theme="dark"] .list-card .bg-gray-50\/30 { background: #221D17 !important; border-color: #3F382F !important; }
    html[data-theme="dark"] #orderTable th { background: #221D17 !important; color: #A0998F !important; border-bottom-color: #3F382F !important; }
    html[data-theme="dark"] #orderTable td { color: #EAE6DF !important; border-bottom-color: #3F382F !important; }
    html[data-theme="dark"] #orderTable td .text-gray-400,
    html[data-theme="dark"] #orderTable td.text-gray-400 { color: #A0998F !important; }

    /* Heading + search */
    html[data-theme="dark"] .order-content .text-gray-800 { color: #EAE6DF !important; }
    html[data-theme="dark"] .order-content .text-gray-400,
    html[data-theme="dark"] .order-content .text-gray-300 { color: #A0998F !important; }
    html[data-theme="dark"] #orderSearch { background: #2A241D !important; border-color: #3F382F !important; color: #EAE6DF !important; box-shadow: none !important; }
    html[data-theme="dark"] #orderSearch:focus { border-color: #F59E0B !important; }
    html[data-theme="dark"] #orderSearch::placeholder { color: #A0998F !important; }

    /* Channel tabs (the "card buttons") */
    html[data-theme="dark"] .channel-tab:not(.active) { background: #2A241D !important; border-color: #3F382F !important; color: #A0998F !important; }
    html[data-theme="dark"] .channel-tab:not(.active):hover { border-color: #F59E0B !important; color: #EAE6DF !important; }
    html[data-theme="dark"] .channel-tab .count { background: #221D17 !important; color: #A0998F !important; }
    html[data-theme="dark"] .channel-tab.active {
        background: linear-gradient(rgba(245,158,11,0.14), rgba(245,158,11,0.14)), #2A241D !important;
        border-color: #F59E0B !important;
        color: #F59E0B !important;
    }
    html[data-theme="dark"] .channel-tab.active .count { background: #F59E0B !important; color: #fff !important; }

    /* Step buttons, dots, notes */
    html[data-theme="dark"] .step-dot { background: #3F382F !important; }
    html[data-theme="dark"] .step-dot.done { background: #F59E0B !important; }
    html[data-theme="dark"] .status-done-note { color: #A0998F !important; }
    html[data-theme="dark"] .btn-cancel-order { color: #fca5a5 !important; border-color: rgba(248,113,113,0.45) !important; }

    /* Delivery popup */
    html[data-theme="dark"] #podCaptureModal p.pod-sub,
    html[data-theme="dark"] .pod-field label { color: #A0998F !important; }
    html[data-theme="dark"] .pod-field input[type="text"] { background: #221D17 !important; border-color: #3F382F !important; color: #EAE6DF !important; }
    html[data-theme="dark"] .pod-drop { background: #221D17 !important; border-color: #3F382F !important; color: #A0998F !important; }
    html[data-theme="dark"] .pod-drop:hover { border-color: #F59E0B !important; color: #F59E0B !important; }
    html[data-theme="dark"] .pod-cancel { background: #221D17 !important; color: #A0998F !important; }
    html[data-theme="dark"] #podPreviewImg { border-color: #3F382F !important; }
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
                        <th>Fulfillment Step</th>
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
    // This single flag decides which status flow a row gets and which stat card
    // numbers get computed, so it is checked in exactly one place.
    function isOnlineOrder(o) { return (o.type || 'WEB') !== 'POS'; }

    // The ORDERED list of stages per channel. Position in the array = the step number.
    // The "next" button always moves exactly one position forward, never more.
    const STATUS_FLOW = {
        online: ['pending', 'processing', 'prepared', 'in transit', 'confirmed'],
        walkin: ['pending', 'processing', 'prepared', 'confirmed']
    };
    const STATUS_LABELS = {
        'pending': 'Pending',
        'processing': 'Processing',
        'prepared': 'Prepared',
        'in transit': 'In Transit',
        'confirmed': 'Confirmed',
        'cancelled': 'Cancelled'
    };
    // What the button SAYS for each destination stage (verb form, so staff know what clicking does).
    const NEXT_ACTION_LABELS = {
        'processing': { text: 'Start Processing', icon: 'fa-gears' },
        'prepared':   { text: 'Mark Prepared',    icon: 'fa-box' },
        'in transit': { text: 'Send Out',         icon: 'fa-truck-fast' },
        'confirmed':  { text: 'Confirm',          icon: 'fa-circle-check' }
    };

    let activeChannel = 'online'; // 'online' | 'walkin'
    let allOrders = [];
    const userMap = {};

    // Turns whatever is stored in Firestore (any casing, older names) into one of
    // this channel's known stages — or 'cancelled', which is terminal for both.
    function normalizeStatus(rawValue, online) {
        const raw = (rawValue || 'pending').toString().toLowerCase().trim();
        const flow = online ? STATUS_FLOW.online : STATUS_FLOW.walkin;

        if (raw === 'cancelled') return 'cancelled';
        if (flow.includes(raw)) return raw;

        // Legacy values from older records / other screens
        if (raw === 'delivered' || raw === 'completed') return 'confirmed';
        if (online && (raw === 'shipped' || raw === 'out_for_delivery' || raw === 'on delivery')) return 'in transit';
        return 'pending';
    }

    // Returns the one stage after `status`, or null if the order is finished/cancelled.
    function nextStatusFor(status, online) {
        if (status === 'cancelled') return null;
        const flow = online ? STATUS_FLOW.online : STATUS_FLOW.walkin;
        const index = flow.indexOf(status);
        if (index === -1 || index === flow.length - 1) return null;
        return flow[index + 1];
    }

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

        document.getElementById('orderSearch').addEventListener('keyup', applySearchFilter);
    });

    // Hides rows that don't contain the search text. Called on typing AND after every
    // re-render, so a live Firestore update doesn't wipe out what the user filtered.
    function applySearchFilter() {
        const val = document.getElementById('orderSearch').value.toLowerCase();
        document.querySelectorAll('#orderData tr').forEach(r => {
            r.style.display = r.innerText.toLowerCase().includes(val) ? '' : 'none';
        });
    }

    function setChannelTab(channel) {
        activeChannel = channel;
        document.getElementById('tabOnline').classList.toggle('active', channel === 'online');
        document.getElementById('tabWalkin').classList.toggle('active', channel === 'walkin');
        document.getElementById('logTitle').innerText = (channel === 'online' ? 'Online Orders Log' : 'Walk-in Orders Log');
        document.getElementById('orderSearch').value = '';
        renderOrders();
    }

    // Builds the HTML for the "Fulfillment Step" cell of one order row.
    function buildStatusCell(orderId, status, online) {
        const flow = online ? STATUS_FLOW.online : STATUS_FLOW.walkin;
        const slug = status.replace(/\s+/g, '-');
        const reachedIndex = flow.indexOf(status); // -1 for cancelled

        const dots = flow.map((stage, i) =>
            `<span class="step-dot ${i <= reachedIndex ? 'done' : ''}" title="${STATUS_LABELS[stage]}"></span>`
        ).join('');

        let actions = '';
        const next = nextStatusFor(status, online);

        if (next) {
            const action = NEXT_ACTION_LABELS[next];
            // Online "Confirm" means "the rider delivered it", so say that plainly.
            const text = (next === 'confirmed' && online) ? 'Confirm Delivery' : action.text;
            actions += `
                <button type="button" class="btn-next-step"
                        onclick="advanceOrder('${orderId}', '${online}', '${status}', this)">
                    <i class="fa-solid ${action.icon}"></i> ${text}
                </button>`;
        }

        // Walk-in only: Cancel stays available until the sale is Confirmed.
        if (!online && status !== 'confirmed' && status !== 'cancelled') {
            actions += `
                <button type="button" class="btn-cancel-order"
                        onclick="cancelWalkinOrder('${orderId}', '${status}', this)">
                    <i class="fa-solid fa-ban"></i> Cancel
                </button>`;
        }

        if (!actions) {
            actions = `<span class="status-done-note">${status === 'cancelled' ? 'Order voided — no further steps.' : 'Completed — no further steps.'}</span>`;
        }

        return `
            <div class="status-cell">
                <div class="status-top">
                    <span class="status-pill status-${slug}">${STATUS_LABELS[status]}</span>
                    ${status !== 'cancelled' ? `<span class="step-dots">${dots}</span>` : ''}
                </div>
                <div class="status-actions">${actions}</div>
            </div>`;
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
            const status = normalizeStatus(data.status, online);

            const amount = parseFloat(data.total_price || data.total_amount || 0);
            if (status === 'pending') pendingCount++;
            if (status === 'confirmed') salesTotal += amount;

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
                <td>${buildStatusCell(data.id, status, online)}</td>
            </tr>`;
        });

        orderData.innerHTML = html;
        document.getElementById('totalOrders').innerText = docs.length;
        document.getElementById('pendingOrders').innerText = pendingCount;
        document.getElementById('totalSales').innerText = '₱' + salesTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
        applySearchFilter();
    }

    // The single write path for EVERY status change on this page.
    // It re-reads the order inside a transaction and only writes if the order is
    // still at `expectedFrom`. That's what makes skipping impossible: if someone else
    // (or another tab) already moved it, this refuses instead of jumping ahead.
    async function runGuardedTransition(orderId, expectedFrom, online, updateData) {
        // Finalized invoices can't be edited directly — only the Void module may touch them
        if (typeof window.blockIfLocked === 'function' && await window.blockIfLocked(orderId)) {
            return false;
        }

        const orderRef = db.collection('orders').doc(orderId);

        await db.runTransaction(async (tx) => {
            const snap = await tx.get(orderRef);
            if (!snap.exists) throw new Error('This order no longer exists.');

            const current = normalizeStatus(snap.data().status, online);
            if (current !== expectedFrom) {
                throw new Error(`This order is already "${STATUS_LABELS[current]}". The list has refreshed — please check it again.`);
            }
            tx.update(orderRef, updateData);
        });

        // Accountability record — same action name/shape the Flutter app writes
        // (AdminAuditService 'order_status_change'), so web and mobile changes show up
        // identically in Admin Activity Log. Never blocks the status change itself.
        try {
            await db.collection('admin_actions').add({
                actorUid: firebase.auth().currentUser ? firebase.auth().currentUser.uid : null,
                actorEmail: window.currentUserEmail || null,
                actorRole: window.currentUserRole || null,
                action: 'order_status_change',
                targetUid: orderId,
                targetEmail: null,
                details: `Order status changed to "${STATUS_LABELS[updateData.status] || updateData.status}".`,
                timestamp: firebase.firestore.FieldValue.serverTimestamp()
            });
        } catch (auditError) {
            console.warn('Audit log write failed (status still updated):', auditError);
        }
        return true;
    }

    // Clicked from a row's "next step" button. Moves the order exactly ONE stage forward.
    async function advanceOrder(orderId, onlineStr, fromStatus, btn) {
        const online = onlineStr === 'true';
        const next = nextStatusFor(fromStatus, online);
        if (!next) return;

        // Online "confirmed" is never a plain write — it must go through the rider's
        // proof-of-delivery capture first. The status only flips once that's submitted.
        if (online && next === 'confirmed') {
            openPodCapture(orderId);
            return;
        }

        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

        const updateData = {
            status: next,
            updated_at: firebase.firestore.FieldValue.serverTimestamp()
        };
        if (!online && next === 'confirmed') {
            // Walk-in sale completed at the counter = paid (same rule as order_details.php).
            updateData.paymentStatus = 'Paid';
        }

        try {
            await runGuardedTransition(orderId, fromStatus, online, updateData);
            // No manual UI update needed: the onSnapshot listener fires after the write
            // and re-renders this row with the new pill + the next button.
        } catch (e) {
            alert('Status update rejected: ' + e.message);
        } finally {
            // If the row was re-rendered, this button is already gone — harmless.
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }

    // Walk-in only. Cancelling = a voided sale. No money moves — this business does
    // not refund a cancelled/voided item, it just stops the sale.
    async function cancelWalkinOrder(orderId, fromStatus, btn) {
        if (!confirm('Cancel this walk-in order? It will be marked Voided and locked.')) return;

        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        try {
            await runGuardedTransition(orderId, fromStatus, false, {
                status: 'cancelled',
                paymentStatus: 'Voided',
                locked: true,
                // The mobile app counts today's walk-in cancellations by this field,
                // so web cancellations now count toward the same daily limit.
                cancelledAt: firebase.firestore.FieldValue.serverTimestamp(),
                updated_at: firebase.firestore.FieldValue.serverTimestamp()
            });
        } catch (e) {
            alert('Cancellation rejected: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }

    // --- Proof of Delivery capture (online orders only) ---
    // This is the one and only place an online order becomes "Confirmed." It can only
    // be opened from an order that is currently "In Transit" (the button only appears
    // there), and the save re-checks that inside the transaction too.
    let podTargetOrderId = null;
    let podCompressedDataUrl = null;

    function openPodCapture(orderId) {
        podTargetOrderId = orderId;
        podCompressedDataUrl = null;

        document.getElementById('podCaptureError').style.display = 'none';
        document.getElementById('podFileInput').value = '';
        document.getElementById('podPreviewWrap').style.display = 'none';
        document.getElementById('podRecipientInput').value = '';
        document.getElementById('podCourierInput').value = window.currentUserName || '';

        document.getElementById('podCaptureOverlay').classList.add('open');
    }

    function cancelPodCapture() {
        // Nothing was written, and there's no dropdown to revert anymore — just close.
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
            // reads Confirmed without POD attached, or vice versa. The transaction only
            // allows this if the order is still "In Transit".
            const saved = await runGuardedTransition(podTargetOrderId, 'in transit', true, {
                status: 'confirmed',
                paymentStatus: 'Paid',
                locked: true,
                podPhotoUrl: podCompressedDataUrl,
                podRecipient: recipient,
                courierName: courier || 'Not specified',
                podTimestamp: firebase.firestore.FieldValue.serverTimestamp(),
                updated_at: firebase.firestore.FieldValue.serverTimestamp()
            });

            if (saved) {
                document.getElementById('podCaptureOverlay').classList.remove('open');
                podTargetOrderId = null;
                podCompressedDataUrl = null;
            }
        } catch (err) {
            podShowError('Error saving delivery: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.innerText = 'Confirm Delivery';
        }
    }
</script>
<?php include 'templates/footer.php'; ?>