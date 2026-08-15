<?php
/**
 * BLOOMINOUS - Invoice Portal
 *
 * A read-only registry of every invoice (order) so staff can locate,
 * view, print, or download any receipt without needing to already
 * know which order it belongs to. This page never writes to `orders` —
 * it only lists and links out to invoice.php, which owns all invoice
 * rendering/print/PDF logic. Keeping that logic in one place means
 * there is exactly one source of truth for what an invoice looks like.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security check — same gate used across the rest of the admin panel
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php';
?>

<style>
    .portal-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    .list-card { background: #fff; border-radius: 35px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; overflow: hidden; }
    #invoiceTable { width: 100%; border-collapse: collapse; }
    #invoiceTable th { text-align: left; padding: 22px 20px; color: var(--text-light); border-bottom: 1px solid #f0f0f0; text-transform: uppercase; font-size: 0.72rem; font-weight: 800; letter-spacing: 1px; background: #fafafa; }
    #invoiceTable td { padding: 18px 20px; border-bottom: 1px solid #f8f9fa; color: var(--text-main); font-size: 0.88rem; font-weight: 500; }

    .badge { padding: 6px 16px; border-radius: 50px; font-size: 0.62rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; display: inline-block; }
    .badge-paid { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
    .badge-pending { background: #fff9e6; color: #f39c12; }
    .badge-voided, .badge-refunded, .badge-partially-refunded { background: #f2f2f2; color: #888; }
    .badge-channel-pos { background: rgba(123, 121, 242, 0.12); color: var(--secondary); }
    .badge-channel-web { background: rgba(245, 158, 11, 0.12); color: var(--primary); }

    .row-actions { display: flex; gap: 8px; }
    .row-actions a { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; text-decoration: none; border: 1px solid #f0f0f0; background: #fafafa; color: var(--text-light); transition: 0.2s; }
    .row-actions a:hover { border-color: var(--primary); color: var(--primary); }

    .filter-select { padding: 12px 16px; border-radius: 14px; border: 1px solid #f0f0f0; background: #fff; font-weight: 700; font-size: 0.82rem; outline: none; cursor: pointer; }
</style>

<main class="portal-content">
    <div class="flex justify-between items-center mb-10 flex-wrap gap-4">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Invoice Portal</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Every receipt, in one searchable place — view, print, or download.</p>
        </div>
        <div class="flex gap-3 items-center flex-wrap">
            <select id="statusFilter" class="filter-select">
                <option value="">All Payment Statuses</option>
                <option value="Paid">Paid</option>
                <option value="Pending">Pending</option>
                <option value="Voided">Voided</option>
                <option value="Refunded">Refunded</option>
                <option value="Partially Refunded">Partially Refunded</option>
            </select>
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                <input type="text" id="invoiceSearch" class="bg-white border border-gray-100 rounded-2xl px-12 py-3 text-sm outline-none focus:border-pink-300 transition-all w-80 shadow-sm" placeholder="Search invoice #, customer...">
            </div>
        </div>
    </div>

    <div class="list-card">
        <div class="overflow-x-auto">
            <table id="invoiceTable">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Channel</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Payment Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="invoiceRows">
                    <tr><td colspan="7" class="text-center p-12 text-gray-300 italic font-medium">Loading invoices...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    let allInvoiceRows = []; // cached order docs so search/filter never re-hits Firestore

    document.addEventListener('DOMContentLoaded', () => {
        const tbody = document.getElementById('invoiceRows');

        db.collection('orders').where('branchId', '==', window.currentBranch).onSnapshot(snap => {
            if (snap.empty) {
                tbody.innerHTML = "<tr><td colspan='7' class='text-center p-10 text-gray-400'>No invoices found for this branch.</td></tr>";
                allInvoiceRows = [];
                return;
            }

            allInvoiceRows = [];
            snap.forEach(doc => allInvoiceRows.push({ id: doc.id, ...doc.data() }));
            allInvoiceRows.sort((a, b) => ((b.createdAt || b.timestamp)?.seconds || 0) - ((a.createdAt || a.timestamp)?.seconds || 0));

            renderInvoiceRows();
        });

        document.getElementById('invoiceSearch').addEventListener('keyup', renderInvoiceRows);
        document.getElementById('statusFilter').addEventListener('change', renderInvoiceRows);
    });

    function renderInvoiceRows() {
        const tbody = document.getElementById('invoiceRows');
        const searchVal = document.getElementById('invoiceSearch').value.toLowerCase();
        const statusVal = document.getElementById('statusFilter').value;

        let html = '';
        let visibleCount = 0;

        allInvoiceRows.forEach(o => {
            // Same fallback logic as invoice.php, so the badge here always matches
            // what you'd see after clicking through — no inconsistent statuses.
            const paymentStatus = o.paymentStatus || (o.status === 'cancelled' ? 'Voided' : (o.status === 'completed' || o.status === 'delivered' ? 'Paid' : 'Pending'));

            if (statusVal && paymentStatus !== statusVal) return;

            const invoiceId = o.invoiceId || ('#' + o.id.slice(0, 10));
            const channel = o.type === 'POS' ? 'Walk-In' : 'Online';
            const customerName = o.customer_name || o.customerName || 'Walk-in Customer';
            const amount = parseFloat(o.total_price ?? o.total_amount ?? 0);
            const dateObj = o.createdAt || o.timestamp;
            const dateStr = dateObj ? dateObj.toDate().toLocaleDateString() : 'N/A';

            // Cheap client-side search across the columns actually shown — mirrors
            // order_management.php's row.innerText filter, just scoped to a haystack
            // string so it also matches the raw invoiceId even though it's inside an <a> tag.
            const haystack = `${invoiceId} ${customerName} ${channel} ${paymentStatus}`.toLowerCase();
            if (searchVal && !haystack.includes(searchVal)) return;

            visibleCount++;
            html += `
                <tr>
                    <td class="font-bold text-gray-800">${invoiceId}</td>
                    <td><span class="badge ${o.type === 'POS' ? 'badge-channel-pos' : 'badge-channel-web'}">${channel}</span></td>
                    <td>${customerName}</td>
                    <td class="text-gray-400 text-xs font-semibold">${dateStr}</td>
                    <td class="font-black text-pink-600">₱${amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                    <td><span class="badge badge-${paymentStatus.toLowerCase().replace(/\s+/g, '-')}">${paymentStatus}</span></td>
                    <td>
                        <div class="row-actions">
                            <a href="invoice.php?id=${o.id}" title="View Invoice"><i class="fa-solid fa-eye"></i></a>
                            <a href="invoice.php?id=${o.id}&action=print" target="_blank" title="Print"><i class="fa-solid fa-print"></i></a>
                            <a href="invoice.php?id=${o.id}&action=download" target="_blank" title="Download PDF"><i class="fa-solid fa-file-arrow-down"></i></a>
                        </div>
                    </td>
                </tr>`;
        });

        tbody.innerHTML = html || "<tr><td colspan='7' class='text-center p-10 text-gray-400'>No invoices match your search/filter.</td></tr>";
    }
</script>

<?php include 'templates/footer.php'; ?>