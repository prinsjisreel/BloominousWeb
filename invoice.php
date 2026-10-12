<?php
/**
 * BLOOMINOUS - Invoice Viewer (single receipt)
 *
 * Read-only. Renders ONE order document as a formal invoice, and handles
 * Print + Download PDF. invoice_portal.php lists every invoice and links here:
 *   invoice.php?id=ORDER_ID                  -> view
 *   invoice.php?id=ORDER_ID&action=print     -> view, then open the print dialog
 *   invoice.php?id=ORDER_ID&action=download  -> view, then save a PDF
 *
 * Reads orders written by BOTH platforms:
 *   web POS / web checkout : type 'POS'/'WEB', total_amount/total_price, items[].qty, payment_method
 *   BloominousApp POS      : type 'pos',       totalAmount,              items[].quantity, paymentMethod
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security check — same gate used by order_details.php / order_management.php
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

// Firestore document IDs are letters, numbers, '-' and '_'. Anything else is
// rejected here, before it can reach the page.
$order_id = $_GET['id'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $order_id)) {
    header("Location: invoice_portal.php");
    exit();
}

// Send the Back link to whichever allowed page the user came from.
// Defaults to the Invoice Portal.
$back_pages = [
    'invoice_portal.php'   => 'Invoice Portal',
    'order_management.php' => 'Order Registry',
    'order_details.php'    => 'Order Details',
];
$referer_page = basename(parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: '');
$back_href  = array_key_exists($referer_page, $back_pages) ? $referer_page : 'invoice_portal.php';
$back_label = $back_pages[$back_href];
if ($back_href === 'order_details.php') {
    $back_href .= '?id=' . urlencode($order_id);
}

include 'templates/header.php';
?>

<style>
    .invoice-content { padding: 1.5rem; max-width: 950px; margin: 0 auto; }
    .back-link { display: inline-flex; align-items: center; gap: 10px; color: var(--primary); text-decoration: none; font-weight: 800; margin-bottom: 2rem; transition: 0.3s; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 2px; }
    .back-link:hover { transform: translateX(-5px); filter: brightness(1.2); }

    .invoice-toolbar { display: flex; justify-content: flex-end; gap: 12px; margin-bottom: 1.5rem; }
    .btn-outline { background: var(--surface); color: var(--text-main); border: 1px solid var(--border-color); padding: 12px 22px; border-radius: 14px; font-weight: 800; font-size: 0.8rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; }
    .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
    .btn-solid { background: var(--primary); color: #fff; border: none; padding: 12px 22px; border-radius: 14px; font-weight: 800; font-size: 0.8rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
    .btn-solid:hover { opacity: 0.9; }
    .btn-solid:disabled, .btn-outline:disabled { opacity: 0.5; cursor: not-allowed; }

    /* The receipt is always "paper": white with dark ink, even in dark mode,
       so what you see on screen matches what prints and what goes in the PDF.
       Overriding the theme variables INSIDE the card is what makes that work. */
    #invoiceCard {
        --text-main: #363949;
        --text-light: #7d8da1;
        background: #fff; color: #363949;
        border-radius: 28px; padding: 3.5rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid #f0f0f0;
        position: relative; overflow: hidden;
    }

    .inv-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2.5rem; padding-bottom: 2.5rem; border-bottom: 2px dashed #f0f0f0; gap: 1rem; }
    .inv-brand p { margin: 0; }
    .inv-brand-name { font-family: 'Cormorant Garamond', serif; font-size: 1.9rem; font-weight: 900; color: #363949; margin-bottom: 6px !important; }
    .inv-num { text-align: right; }
    .inv-num h2 { margin: 0; font-size: 2.2rem; color: #363949; }
    .inv-num p { margin: 4px 0 0; color: var(--text-light); font-size: 0.75rem; font-weight: 700; letter-spacing: 1px; }

    .badge { padding: 8px 18px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; display: inline-block; }
    .badge-paid { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
    .badge-pending { background: #fff9e6; color: #f39c12; }
    .badge-voided, .badge-refunded, .badge-partially-refunded { background: #f2f2f2; color: #888; }
    .badge-voided { text-decoration: line-through; }
    .badge-channel-pos { background: rgba(123, 121, 242, 0.12); color: #7B79F2; }
    .badge-channel-web { background: rgba(245, 158, 11, 0.12); color: #d97706; }

    .inv-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2.5rem; }
    .info-label { color: #b5b5b5; font-size: 0.62rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 6px; }
    .info-value { color: var(--text-main); font-weight: 700; font-size: 0.95rem; line-height: 1.5; }

    table.inv-items { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
    table.inv-items th { text-align: left; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; color: var(--text-light); font-weight: 800; padding: 10px 8px; border-bottom: 2px solid #f0f0f0; }
    table.inv-items td { padding: 14px 8px; border-bottom: 1px solid #f8f9fa; font-size: 0.9rem; color: var(--text-main); font-weight: 600; }
    table.inv-items td.num, table.inv-items th.num { text-align: right; }

    .inv-totals { margin-left: auto; width: 320px; max-width: 100%; }
    .inv-totals .row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 0.9rem; color: var(--text-light); font-weight: 700; }
    .inv-totals .row.grand { border-top: 2px solid #f0f0f0; margin-top: 8px; padding-top: 14px; font-size: 1.3rem; color: var(--text-main); font-weight: 900; }

    .watermark { position: absolute; top: 45%; left: 50%; transform: translate(-50%,-50%) rotate(-25deg); font-size: 6rem; font-weight: 900; color: rgba(230,50,50,0.08); letter-spacing: 10px; pointer-events: none; user-select: none; white-space: nowrap; z-index: 1; }

    .lock-note { display: inline-flex; align-items: center; gap: 6px; font-size: 0.7rem; color: var(--text-light); font-weight: 700; margin-top: 1.5rem; }

    #invoiceLoadError { display: none; background: var(--surface); border: 1px solid var(--border-color); border-radius: 20px; padding: 2rem; text-align: center; color: var(--text-light); font-weight: 700; }

    @media (max-width: 640px) {
        #invoiceCard { padding: 1.5rem; }
        .inv-head { flex-direction: column; }
        .inv-num { text-align: left; }
        .inv-meta-grid { grid-template-columns: 1fr; }
    }

    /* Print: hide the admin chrome (sidebar, top bar, buttons) so only the receipt prints. */
    @media print {
        .sidebar, .kiri-topbar, .invoice-toolbar, .back-link, .no-print { display: none !important; }
        .main-content { margin-left: 0 !important; padding: 0 !important; }
        .invoice-content { padding: 0 !important; max-width: none !important; }
        #invoiceCard { box-shadow: none !important; border: none !important; padding: 0 !important; border-radius: 0 !important; }
        html, body { background: #fff !important; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }

    html[data-theme="dark"] #invoiceCard { box-shadow: none; border-color: var(--border-color); }
</style>

<div class="invoice-content">
    <a href="<?php echo htmlspecialchars($back_href, ENT_QUOTES); ?>" class="back-link">
        <i class="fa-solid fa-chevron-left"></i> <?php echo htmlspecialchars($back_label, ENT_QUOTES); ?>
    </a>

    <div class="invoice-toolbar no-print">
        <button type="button" class="btn-outline" id="printBtn" onclick="printInvoice()" disabled>
            <i class="fa-solid fa-print"></i> Print
        </button>
        <button type="button" class="btn-solid" id="downloadPdfBtn" onclick="downloadInvoicePdf()" disabled>
            <i class="fa-solid fa-file-arrow-down"></i> Download PDF
        </button>
    </div>

    <div id="invoiceLoadError"></div>

    <div id="invoiceCard">
        <div id="invoiceWatermark"></div>

        <div class="inv-head">
            <div class="inv-brand">
                <p class="inv-brand-name">BLOOMINOUS</p>
                <p class="info-value" id="invBranch">—</p>
                <p class="info-label" style="margin-top:10px;">Payment Method</p>
                <p class="info-value" id="invPaymentMethod">—</p>
            </div>
            <div class="inv-num">
                <h2 id="invId">Loading…</h2>
                <p id="invDate">—</p>
                <div style="margin-top:14px;">
                    <span class="badge" id="invChannelBadge">—</span>
                    <span class="badge" id="invStatusBadge">—</span>
                </div>
            </div>
        </div>

        <div class="inv-meta-grid">
            <div>
                <p class="info-label">Billed To</p>
                <p class="info-value" style="font-weight:900;" id="invCustomerName">—</p>
                <p class="info-value" id="invCustomerEmail">—</p>
            </div>
            <div>
                <p class="info-label">Recipient / Delivery Address</p>
                <p class="info-value" id="invAddress">—</p>
            </div>
        </div>

        <table class="inv-items">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="num">Qty</th>
                    <th class="num">Unit Price</th>
                    <th class="num">Line Total</th>
                </tr>
            </thead>
            <tbody id="invItemsBody">
                <tr><td colspan="4" style="text-align:center; padding:30px; color:#ccc;">Loading invoice…</td></tr>
            </tbody>
        </table>

        <div class="inv-totals">
            <div class="row"><span>Subtotal</span><span id="invSubtotal">₱0.00</span></div>
            <div class="row" id="invDiscountRow" style="display:none;"><span id="invDiscountLabel">Discount</span><span id="invDiscount">-₱0.00</span></div>
            <div class="row" id="invShippingRow" style="display:none;"><span>Delivery Fee</span><span id="invShipping">₱0.00</span></div>
            <div class="row grand"><span>Total</span><span id="invTotal">₱0.00</span></div>
        </div>

        <div id="podSection" style="display:none; margin-top:2.5rem; padding-top:2.5rem; border-top:2px dashed #f0f0f0;">
            <p class="info-label" style="margin-bottom:14px;"><i class="fa-solid fa-truck-fast"></i> Proof of Delivery</p>
            <div style="display:grid; grid-template-columns:150px 1fr; gap:1.5rem; align-items:center;">
                <img id="podThumb" src="" alt="Delivery proof" style="width:150px;height:150px;object-fit:cover;border-radius:18px;border:1px solid #f0f0f0;cursor:pointer;" onclick="document.getElementById('podModal').style.display='flex'">
                <div>
                    <p class="info-value" style="margin-bottom:6px;">Courier: <span id="podCourier">—</span></p>
                    <p class="info-value" style="font-weight:500;color:var(--text-light);font-size:0.85rem;margin-bottom:6px;">Delivered: <span id="podTimestamp">—</span></p>
                    <p class="info-value" style="font-weight:500;color:var(--text-light);font-size:0.85rem;">Received by: <span id="podRecipient">—</span></p>
                </div>
            </div>
        </div>

        <p class="lock-note" id="lockNote" style="display:none;">
            <i class="fa-solid fa-lock"></i> This invoice is finalized — changes can only be made through the Void module.
        </p>
    </div>
</div>

<div id="podModal" class="no-print" onclick="this.style.display='none'" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:999;align-items:center;justify-content:center;">
    <img src="" id="podModalImg" alt="Delivery proof, full size" style="max-width:90vw;max-height:90vh;border-radius:12px;">
</div>

<!-- PDF export libraries (loaded only on this page) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
    // json_encode turns the PHP string into a safe JavaScript string literal.
    const orderId = <?php echo json_encode($order_id); ?>;
    let currentInvoiceId = orderId;
    let invoiceReady = false;   // true once the order has been drawn at least once
    let autoActionDone = false; // ?action=print|download runs only once per page load

    function peso(n) {
        return '₱' + (parseFloat(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Item names are typed by staff, so escape them before putting them into HTML.
    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Both platforms write a slightly different shape — read either one.
    const isWalkIn   = o => String(o.type || '').toUpperCase() === 'POS';   // 'POS' (web) or 'pos' (app)
    const itemQty    = i => parseInt(i.qty ?? i.quantity ?? 0, 10) || 0;    // web: qty, app: quantity
    const itemPrice  = i => parseFloat(i.price) || 0;

    function formatDateTime(ts) {
        return (ts && typeof ts.toDate === 'function')
            ? ts.toDate().toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true })
            : 'N/A';
    }

    function showLoadError(message) {
        const box = document.getElementById('invoiceLoadError');
        box.innerText = message;
        box.style.display = 'block';
        document.getElementById('invoiceCard').style.display = 'none';
    }

    document.addEventListener('DOMContentLoaded', () => {
        db.collection('orders').doc(orderId).onSnapshot(doc => {
            if (!doc.exists) {
                showLoadError('This invoice could not be found. It may have been deleted, or it belongs to another branch.');
                return;
            }
            const o = doc.data();
            const walkIn = isWalkIn(o);

            // Same fallback as invoice_portal.php, so the badge always matches the list.
            const paymentStatus = o.paymentStatus || (o.status === 'cancelled' ? 'Voided' : (o.status === 'completed' || o.status === 'delivered' ? 'Paid' : 'Pending'));
            const channel = walkIn ? 'Walk-In' : 'Online';

            currentInvoiceId = o.invoiceId || o.orderId || o.order_id || orderId;
            document.getElementById('invId').innerText = o.invoiceId || ('#' + (o.orderId || o.order_id || orderId).slice(0, 16));
            document.getElementById('invBranch').innerText = o.branchId || window.currentBranch || 'Main Branch';
            document.getElementById('invPaymentMethod').innerText = o.payment_method || o.paymentMethod || 'Not recorded';
            document.getElementById('invDate').innerText = formatDateTime(o.createdAt || o.timestamp);

            const channelBadge = document.getElementById('invChannelBadge');
            channelBadge.innerText = channel;
            channelBadge.className = 'badge ' + (walkIn ? 'badge-channel-pos' : 'badge-channel-web');

            const statusBadge = document.getElementById('invStatusBadge');
            statusBadge.innerText = paymentStatus;
            statusBadge.className = 'badge badge-' + paymentStatus.toLowerCase().replace(/\s+/g, '-');

            document.getElementById('invCustomerName').innerText = o.customer_name || o.customerName || 'Walk-in Customer';
            document.getElementById('invCustomerEmail').innerText = o.email || o.customer_email || (walkIn ? 'Walk-in (no email on file)' : 'N/A');
            document.getElementById('invAddress').innerText = o.address || (walkIn ? 'Walk-in — no delivery' : 'No delivery address provided');

            // Items: one table row per item.
            const items = Array.isArray(o.items) ? o.items : [];
            let rows = '';
            items.forEach(item => {
                const qty = itemQty(item);
                const price = itemPrice(item);
                rows += `
                    <tr>
                        <td>${escapeHtml(item.name || 'Item')}</td>
                        <td class="num">${qty}</td>
                        <td class="num">${peso(price)}</td>
                        <td class="num">${peso(price * qty)}</td>
                    </tr>`;
            });
            document.getElementById('invItemsBody').innerHTML = rows || '<tr><td colspan="4" style="text-align:center; padding:30px; color:#ccc;">No items on this invoice.</td></tr>';

            // Totals
            const computedSubtotal = items.reduce((sum, i) => sum + itemPrice(i) * itemQty(i), 0);
            const subtotal = (o.subtotal !== undefined && o.subtotal !== null) ? o.subtotal : computedSubtotal;
            document.getElementById('invSubtotal').innerText = peso(subtotal);

            const discountRow = document.getElementById('invDiscountRow');
            if (parseFloat(o.discountAmount) > 0) {
                discountRow.style.display = 'flex';
                document.getElementById('invDiscountLabel').innerText = o.discountPercent ? `Discount (${o.discountPercent}%)` : 'Discount';
                document.getElementById('invDiscount').innerText = '-' + peso(o.discountAmount);
            } else {
                discountRow.style.display = 'none';
            }

            const shippingRow = document.getElementById('invShippingRow');
            if (parseFloat(o.shipping_fee) > 0) {
                shippingRow.style.display = 'flex';
                document.getElementById('invShipping').innerText = peso(o.shipping_fee);
            } else {
                shippingRow.style.display = 'none';
            }

            document.getElementById('invTotal').innerText = peso(o.total_price ?? o.total_amount ?? o.totalAmount ?? subtotal);

            // Watermark for voided / refunded invoices
            const wm = document.getElementById('invoiceWatermark');
            if (['Voided', 'Refunded'].includes(paymentStatus)) {
                wm.innerText = paymentStatus.toUpperCase();
                wm.className = 'watermark';
            } else {
                wm.innerText = '';
                wm.className = '';
            }

            document.getElementById('lockNote').style.display = o.locked ? 'inline-flex' : 'none';

            // Proof of Delivery (online orders) — only shown if the fields exist.
            const podSection = document.getElementById('podSection');
            const podUrl = o.podPhotoUrl || o.deliveryPhoto;
            if (podUrl) {
                podSection.style.display = 'block';
                document.getElementById('podThumb').src = podUrl;
                document.getElementById('podModalImg').src = podUrl;
                document.getElementById('podCourier').innerText = o.courierName || o.podCourier || '—';
                document.getElementById('podRecipient').innerText = o.podRecipient || o.recipientName || '—';
                document.getElementById('podTimestamp').innerText = formatDateTime(o.podTimestamp || o.deliveredAt);
            } else {
                podSection.style.display = 'none';
            }

            // The receipt is drawn — Print and Download can now work.
            invoiceReady = true;
            document.getElementById('printBtn').disabled = false;
            document.getElementById('downloadPdfBtn').disabled = false;

            // One-time auto action from the Invoice Portal's print/download icons.
            // autoActionDone stops a later live update from printing or downloading again.
            if (!autoActionDone) {
                autoActionDone = true;
                const action = new URLSearchParams(window.location.search).get('action');
                if (action === 'print') {
                    setTimeout(printInvoice, 400);
                } else if (action === 'download') {
                    setTimeout(downloadInvoicePdf, 400);
                }
            }
        }, err => {
            console.error('Invoice load error:', err);
            showLoadError('Could not load this invoice: ' + err.message);
        });
    });

    function printInvoice() {
        if (!invoiceReady) return;
        window.print();
    }

    async function downloadInvoicePdf() {
        if (!invoiceReady) return;
        if (!window.html2canvas || !window.jspdf) {
            alert('The PDF tools could not load. Check your internet connection and refresh the page.');
            return;
        }

        const btn = document.getElementById('downloadPdfBtn');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing…';
        try {
            const card = document.getElementById('invoiceCard');
            // Take a picture of the receipt card...
            const canvas = await html2canvas(card, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
            const imgData = canvas.toDataURL('image/png');

            // ...and place it on A4 pages, adding pages if the receipt is long.
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF('p', 'pt', 'a4');
            const pageWidth = pdf.internal.pageSize.getWidth();
            const pageHeight = pdf.internal.pageSize.getHeight();
            const imgWidth = pageWidth;
            const imgHeight = (canvas.height * imgWidth) / canvas.width;

            let heightLeft = imgHeight;
            let position = 0;

            pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
            heightLeft -= pageHeight;

            while (heightLeft > 0) {
                position = heightLeft - imgHeight;
                pdf.addPage();
                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
            }

            // File name: invoice number, with anything unusual swapped for '-'.
            pdf.save(`${String(currentInvoiceId).replace(/[^A-Za-z0-9_-]/g, '-')}.pdf`);
        } catch (err) {
            console.error(err);
            alert('PDF export failed: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    }
</script>

<?php include 'templates/footer.php'; ?>