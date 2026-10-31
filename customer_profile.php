<?php
/**
 * BLOOMINOUS - Customer Profile (Firebase Spoke)
 *
 * Data flow:
 *   customers/{id}                 -> name, email, member-since, loyalty points
 *   orders where user_id == {id}   -> ledger rows + LIFETIME INVESTMENT
 *
 * Lifetime Investment = the sum of this customer's SUCCESSFUL orders only
 * (completed / delivered / confirmed). Pending, processing, in-transit and
 * cancelled orders are listed in the ledger but are NOT counted.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

// GET CUSTOMER ID FROM URL
$c_id = $_GET['id'] ?? null;
if (!$c_id) {
    header("Location: customer.php");
    exit();
}

include 'templates/header.php';
?>

<style>
    /* All colours come from the shared theme variables (header.php), so this
       page follows light / dark mode automatically. Amber = var(--primary). */
    .profile-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    .back-btn { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; color: var(--primary); font-weight: 800; margin-bottom: 2rem; transition: 0.3s; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 2px; }
    .back-btn:hover { transform: translateX(-5px); filter: brightness(1.2); }
    .back-btn i { font-size: 1rem; }

    /* minmax(0, 1fr) lets the right column shrink instead of pushing the page wider */
    .profile-grid { display: grid; grid-template-columns: minmax(280px, 400px) minmax(0, 1fr); gap: 2rem; align-items: start; }

    .info-card { background: var(--surface); border-radius: 35px; padding: 3rem 2rem; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.04); height: fit-content; border: 1px solid var(--border-color); position: relative; overflow: hidden; }
    .avatar-circle { width: 140px; height: 140px; background: rgba(245, 158, 11, 0.14); color: var(--primary); border-radius: 45px; display: flex; align-items: center; justify-content: center; margin: 0 auto 2rem; font-size: 4rem; font-weight: 900; border: 1px solid rgba(245, 158, 11, 0.35); font-family: 'Cormorant Garamond', serif; transition: 0.4s; }
    .info-card:hover .avatar-circle { transform: scale(1.05) rotate(-3deg); box-shadow: 0 15px 35px rgba(245, 158, 11, 0.18); }
    .profile-name { font-size: clamp(1.6rem, 3vw, 2.25rem); line-height: 1.15; font-weight: 900; color: var(--text-main); margin-bottom: 0.5rem; overflow-wrap: anywhere; }
    .profile-email { color: var(--text-light); font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 2rem; overflow-wrap: anywhere; }

    .stats-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .stat-box { background: var(--surface); padding: 2rem; border-radius: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.04); border: 1px solid var(--border-color); transition: 0.4s; min-width: 0; }
    .stat-box:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
    .stat-label { font-size: 0.65rem; font-weight: 900; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 0.5rem; }
    .stat-value { font-family: 'Cormorant Garamond', serif; font-weight: 900; line-height: 1.1; font-size: clamp(1.9rem, 4vw, 3rem); color: var(--text-main); overflow-wrap: anywhere; }
    .stat-value.accent { color: var(--primary); }
    .stat-sub { margin-top: 0.5rem; font-size: 0.7rem; font-weight: 700; color: var(--text-light); }

    .history-card { background: var(--surface); border-radius: 35px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.04); border: 1px solid var(--border-color); overflow: hidden; }
    .history-head { padding: 2rem; background: var(--surface-alt); }
    .history-head h4 { font-size: 1.5rem; font-weight: 900; color: var(--text-main); }
    .history-head p { color: var(--text-light); font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.15em; margin-top: 0.25rem; }
    .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .order-table { width: 100%; border-collapse: collapse; min-width: 420px; }
    .order-table th { text-align: left; padding: 20px; color: var(--text-light); border-bottom: 1px solid var(--border-color); border-top: 1px solid var(--border-color); text-transform: uppercase; font-size: 0.7rem; font-weight: 800; letter-spacing: 1.5px; background: var(--surface-alt); white-space: nowrap; }
    .order-table td { padding: 18px 20px; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; color: var(--text-main); font-weight: 600; }
    .order-table tbody tr:hover td { background: var(--surface-alt); }
    .order-table td.muted { color: var(--text-light); font-weight: 500; }
    .order-table td.amount { font-weight: 800; white-space: nowrap; }
    .order-table td.amount.not-counted { color: var(--text-light); font-weight: 600; }
    .order-table td.amount.void { text-decoration: line-through; }
    .empty-row { text-align: center; padding: 50px 20px !important; color: var(--text-light); font-style: italic; font-weight: 500; }
    .empty-row i { display: block; font-size: 2rem; margin-bottom: 10px; opacity: 0.35; font-style: normal; }

    .badge-status { display: inline-block; padding: 6px 16px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; white-space: nowrap; }
    .status-success   { background: rgba(34, 197, 94, 0.14);  color: #22c55e; }
    .status-pending   { background: rgba(245, 158, 11, 0.16); color: #f59e0b; }
    .status-cancelled { background: rgba(239, 68, 68, 0.14);  color: #ef4444; }

    .meta-box { text-align: left; background: var(--surface-alt); padding: 1.5rem; border-radius: 25px; border: 1px solid var(--border-color); margin-top: 1.5rem; }
    .meta-item { display: flex; flex-direction: column; gap: 5px; margin-bottom: 1.25rem; }
    .meta-item:last-child { margin-bottom: 0; }
    .meta-label { font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1.5px; }
    .meta-value { font-size: 0.85rem; font-weight: 700; color: var(--text-main); }

    /* ---------- Responsive ---------- */
    /* Tablets: stack the profile card on top of the stats + ledger */
    @media (max-width: 1024px) {
        .profile-grid { grid-template-columns: minmax(0, 1fr); }
        .info-card { padding: 2rem 1.5rem; }
        .avatar-circle { width: 110px; height: 110px; font-size: 3rem; border-radius: 35px; }
    }
    /* Phones: single column stats, tighter paddings, smaller corners */
    @media (max-width: 640px) {
        .profile-content { padding: 1rem; }
        .stats-row { grid-template-columns: minmax(0, 1fr); gap: 1rem; }
        .stat-box { padding: 1.5rem; border-radius: 28px; }
        .info-card, .history-card { border-radius: 28px; }
        .history-head { padding: 1.5rem; }
        .order-table th, .order-table td { padding: 14px 14px; }
        .hide-sm { display: none; }
        .order-table { min-width: 0; }
    }
</style>

<main class="profile-content">
    <a href="customer.php" class="back-btn">
        <i class="fa-solid fa-chevron-left"></i> <span>Return to Grid</span>
    </a>

    <div class="profile-grid">
        <!-- LEFT COLUMN: USER INFO -->
        <div class="info-card" id="customerInfo">
            <div class="avatar-circle" id="avatar">?</div>
            <h2 class="brand-font profile-name" id="username">Loading...</h2>
            <p class="profile-email" id="email">...</p>

            <div class="meta-box">
                <div class="meta-item">
                    <span class="meta-label">Relationship Status</span>
                    <span class="flex items-center gap-2 text-green-500 font-black text-xs uppercase tracking-wider">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span> Active Member
                    </span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Onboarded Since</span>
                    <span id="memberSince" class="meta-value">...</span>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: STATS & TRANSACTIONS -->
        <div class="main-details">
            <div class="stats-row">
                <div class="stat-box">
                    <p class="stat-label">Loyalty Currency</p>
                    <h2 class="stat-value accent" id="totalPoints">0</h2>
                    <p class="stat-sub">Current points balance</p>
                </div>
                <div class="stat-box">
                    <p class="stat-label">Lifetime Investment</p>
                    <h2 class="stat-value" id="totalSpend">₱0.00</h2>
                    <p class="stat-sub" id="spendSub">Successful orders only</p>
                </div>
            </div>

            <div class="history-card">
                <div class="history-head">
                    <h4 class="brand-font">Order Ledger</h4>
                    <p>Historical Transaction Telemetry</p>
                </div>

                <div class="table-container">
                    <table class="order-table">
                        <thead>
                            <tr>
                                <th>Telemetry Date</th>
                                <th class="hide-sm">Order</th>
                                <th>Valuation</th>
                                <th style="text-align: right;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="orderHistoryData">
                            <tr><td colspan="4" class="empty-row">Synchronizing transaction logs...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    // json_encode = safe quoting, so a crafted ?id= can't inject script.
    const customerId = <?php echo json_encode((string)$c_id); ?>;

    // Statuses that count as a SUCCESSFUL order (compared in lower case,
    // because the app writes 'COMPLETED' and the website writes 'completed').
    const SUCCESS_STATUSES = ['completed', 'delivered', 'confirmed'];

    const peso = (n) => '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Escapes text before it goes into innerHTML.
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

    // Accepts a Firestore Timestamp, a Date, a number or a text date.
    function toJsDate(v) {
        if (!v) return null;
        if (typeof v.toDate === 'function') return v.toDate();
        const d = new Date(v);
        return isNaN(d) ? null : d;
    }

    function orderStatusClass(status) {
        if (SUCCESS_STATUSES.includes(status)) return 'status-success';
        if (status === 'cancelled') return 'status-cancelled';
        return 'status-pending'; // pending, processing, in transit, ...
    }

    document.addEventListener('DOMContentLoaded', () => {
        // ---- 1. Customer details (name, email, since, points) ----
        db.collection('customers').doc(customerId).onSnapshot(doc => {
            if (!doc.exists) {
                alert('Customer not found!');
                window.location.href = 'customer.php';
                return;
            }
            const c = doc.data();
            const displayName = c.username || c.name || c.email || 'N/A';
            document.getElementById('username').innerText = displayName;
            document.getElementById('email').innerText = c.email || 'N/A';
            document.getElementById('avatar').innerText = displayName.charAt(0).toUpperCase();

            const sinceDate = toJsDate(c.lastLogin) || toJsDate(c.created_at);
            document.getElementById('memberSince').innerText = sinceDate
                ? sinceDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
                : 'N/A';

            // Points are a live balance (the app deducts them when the
            // customer redeems a reward), so they stay as stored.
            document.getElementById('totalPoints').innerText = Number(c.points || 0).toLocaleString();
        }, err => {
            console.error('Customer load failed:', err);
            document.getElementById('username').innerText = 'Could not load customer';
        });

        // ---- 2. Orders: ledger + Lifetime Investment ----
        // No orderBy() here on purpose: combining where() with orderBy() on a
        // different field needs a composite index, and without it the query
        // fails silently (the old "Synchronizing..." that never finished).
        // We sort in the browser instead.
        db.collection('orders').where('user_id', '==', customerId).onSnapshot(snap => {
            const body = document.getElementById('orderHistoryData');

            if (snap.empty) {
                body.innerHTML = "<tr><td colspan='4' class='empty-row'><i class='fa-solid fa-box-open'></i>No purchase history found.</td></tr>";
                document.getElementById('totalSpend').innerText = peso(0);
                document.getElementById('spendSub').innerText = '0 successful orders';
                return;
            }

            // Turn each Firestore doc into a plain object we can sort / total.
            const orders = snap.docs.map(d => {
                const o = d.data();
                return {
                    id: d.id,
                    date: toJsDate(o.createdAt) || toJsDate(o.timestamp) || toJsDate(o.created_at),
                    total: parseFloat(o.total_price ?? o.totalAmount ?? o.total_amount ?? 0) || 0,
                    status: String(o.status || 'pending').trim().toLowerCase(),
                    ref: o.invoiceId || o.invoice_id || d.id.substring(0, 8).toUpperCase()
                };
            });

            // Newest first; rows with no date sink to the bottom.
            orders.sort((a, b) => (b.date ? b.date.getTime() : 0) - (a.date ? a.date.getTime() : 0));

            // Lifetime Investment = total of the SUCCESSFUL orders only.
            let spend = 0, successCount = 0;
            orders.forEach(o => {
                if (SUCCESS_STATUSES.includes(o.status)) {
                    spend += o.total;
                    successCount++;
                }
            });
            document.getElementById('totalSpend').innerText = peso(spend);
            document.getElementById('spendSub').innerText =
                successCount + ' successful order' + (successCount === 1 ? '' : 's');

            body.innerHTML = orders.map(o => {
                const dateText = o.date
                    ? o.date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                    : 'N/A';
                const counted = SUCCESS_STATUSES.includes(o.status);
                const amountClass = 'amount' + (counted ? '' : ' not-counted') + (o.status === 'cancelled' ? ' void' : '');
                return `
                <tr>
                    <td class="muted">${esc(dateText)}</td>
                    <td class="muted hide-sm">#${esc(o.ref)}</td>
                    <td class="${amountClass}">${peso(o.total)}</td>
                    <td style="text-align: right;"><span class="badge-status ${orderStatusClass(o.status)}">${esc(o.status)}</span></td>
                </tr>`;
            }).join('');
        }, err => {
            // Without this, a failed query left the page stuck on "Synchronizing...".
            console.error('Order history failed:', err);
            document.getElementById('orderHistoryData').innerHTML =
                "<tr><td colspan='4' class='empty-row'><i class='fa-solid fa-triangle-exclamation'></i>Could not load order history.<br><small>" + esc(err.code || err.message) + "</small></td></tr>";
        });
    });
</script>

<?php include 'templates/footer.php'; ?>