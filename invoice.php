<?php
/**
 * BLOOMINOUS - Invoice Viewer (Phase 2)
 * Read-only. Renders the finalized/live order document as a formal invoice.
 * Staff-only: templates/header.php already redirects customer/delivery sessions away.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security check — same gate used by order_details.php / order_management.php
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

$order_id = $_GET['id'] ?? null;
if (!$order_id) {
    header("Location: order_management.php");
    exit();
}

include 'templates/header.php';
?>

<style>
    .invoice-content { padding: 1.5rem; max-width: 950px; margin: 0 auto; }
    .back-link { display: inline-flex; align-items: center; gap: 10px; color: var(--primary); text-decoration: none; font-weight: 800; margin-bottom: 2rem; transition: 0.3s; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 2px; }
    .back-link:hover { transform: translateX(-5px); filter: brightness(1.2); }

    .invoice-toolbar { display: flex; justify-content: flex-end; gap: 12px; margin-bottom: 1.5rem; }
    .btn-outline { background: #fff; color: var(--text-main); border: 1px solid #eee; padding: 12px 22px; border-radius: 14px; font-weight: 800; font-size: 0.8rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; }
    .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
    .btn-solid { background: var(--primary); color: #fff; border: none; padding: 12px 22px; border-radius: 14px; font-weight: 800; font-size: 0.8rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
    .btn-solid:hover { opacity: 0.9; }

    #invoiceCard { background: #fff; border-radius: 28px; padding: 3.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid #f0f0f0; position: relative; overflow: hidden; }

    .inv-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2.5rem; padding-bottom: 2.5rem; border-bottom: 2px dashed #f0f0f0; }
    .inv-brand p { margin: 0; }
    .inv-num { text-align: right; }
    .inv-num h2 { margin: 0; font-size: 2.2rem; }
    .inv-num p { margin: 4px 0 0; color: var(--text-light); font-size: 0.75rem; font-weight: 700; letter-spacing: 1px; }

    .badge { padding: 8px 18px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; display: inline-block; }
    .badge-paid { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
    .badge-pending { background: #fff9e6; color: #f39c12; }
    .badge-voided { background: #f2f2f2; color: #888; text-decoration: line-through; }
    .badge-channel-pos { background: rgba(123, 121, 242, 0.12); color: var(--secondary); }
    .badge-channel-web { background: rgba(245, 158, 11, 0.12); color: var(--primary); }

    .inv-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2.5rem; }
    .info-label { color: #ccc; font-size: 0.62rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 6px; }
    .info-value { color: var(--text-main); font-weight: 700; font-size: 0.95rem; line-height: 1.5; }

    table.inv-items { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
    table.inv-items th { text-align: left; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; color: var(--text-light); font-weight: 800; padding: 10px 8px; border-bottom: 2px solid #f0f0f0; }
    table.inv-items td { padding: 14px 8px; border-bottom: 1px solid #f8f9fa; font-size: 0.9rem; color: var(--text-main); font-weight: 600; }
    table.inv-items td.num, table.inv-items th.num { text-align: right; }

    .inv-totals { margin-left: auto; width: 320px; }
    .inv-totals .row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 0.9rem; color: var(--text-light); font-weight: 700; }
    .inv-totals .row.grand { border-top: 2px solid #f0f0f0; margin-top: 8px; padding-top: 14px; font-size: 1.3rem; color: var(--text-main); font-weight: 900; }

    .watermark { position: absolute; top: 45%; left: 50%; transform: translate(-50%,-50%) rotate(-25deg); font-size: 6rem; font-weight: 900; color: rgba(230,50,50,0.08); letter-spacing: 10px; pointer-events: none; user-select: none; white-space: nowrap; z-index: 1; }

    .not-found-locked-note { display: inline-flex; align-items: center; gap: 6px; font-size: 0.7rem; color: var(--text-light); font-weight: 700; margin-top: 6px; }

    @media print {
        .sidebar, .invoice-toolbar, .back-link, .main-content > .flex.justify-end.items-center.mb-8 { display: none !important; }
        .main-content { margin-left: 0 !important; }
        #invoiceCard { box-shadow: none !important; border: none !important; padding: 0 !important; }
        body { background: #fff !important; }
    }
</style>

<div class="invoice-content">
    <a href="order_management.php" class="back-link">
        <i class="fa-solid fa-chevron-left"></i> Order Registry
    </a>

    <div class="invoice-toolbar no-print">
        <button class="btn-outline" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Reprint
        </button>
        <button class="btn-solid" id="downloadPdfBtn" onclick="downloadInvoicePdf()">
            <i class="fa-solid fa-file-arrow-down"></i> Download PDF
        </button>
    </div>

    <div id="invoiceCard">
        <div id="invoiceWatermark"></div>

        <div class="inv-head">
            <div class="inv-brand">
                <p class="brand-font text-3xl font-black text-gray-800" style="margin-bottom:6px;">BLOOMINOUS</p>
                <p class="info-value" id="invBranch">—</p>
                <p class="info-label" style="margin-top:10px;">Payment Method</p>
                <p class="info-value" id="invPaymentMethod">—</p>
            </div>
            <div class="inv-num">
                <h2 id="invId">INV-—</h2>
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
                <p class="info-value font-black" id="invCustomerName">—</p>
                <p class="info-value" id="invCustomerEmail">—</p>
            </div>
            <div>
                <p class="info-label">Recipient / Delivery Address</p>
                <p class="info-value" id="invAddress">Walk-in — no delivery</p>
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

        <?php if (!empty($_SESSION['role']) || !empty($_SESSION['admin_role'])): ?>
        <p class="not-found-locked-note" id="lockNote" style="display:none;">
            <i class="fa-solid fa-lock"></i> This invoice is finalized — changes can only be made through the Void module.
        </p>
        <?php endif; ?>
    </div>
</div>

<div id="podModal" onclick="this.style.display='none'" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:999;align-items:center;justify-content:center;">
    <img src="" id="podModalImg" style="max-width:90vw;max-height:90vh;border-radius:12px;">
</div>

<!-- PDF export libs (loaded only on this page) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
    const orderId = "<?php echo htmlspecialchars($order_id, ENT_QUOTES); ?>";
    let currentInvoiceId = orderId;
    let autoActionDone = false;

    function peso(n) {
        return '₱' + parseFloat(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
    }

    document.addEventListener('DOMContentLoaded', () => {
        db.collection('orders').doc(orderId).onSnapshot(doc => {
            if (!doc.exists) {
                alert('Order not found!');
                window.location.href = 'order_management.php';
                return;
            }
            const o = doc.data();
            const paymentStatus = o.paymentStatus || (o.status === 'cancelled' ? 'Voided' : 'Pending');
            const channel = o.type === 'POS' ? 'Walk-In' : 'Online';

            currentInvoiceId = o.invoiceId || orderId;
            document.getElementById('invId').innerText = o.invoiceId || ('#' + orderId.slice(0, 10));
            document.getElementById('invBranch').innerText = o.branchId || window.currentBranch || 'Main Branch';
            document.getElementById('invPaymentMethod').innerText = o.payment_method || 'Not recorded';

            const dateObj = o.createdAt || o.timestamp;
            document.getElementById('invDate').innerText = dateObj ? dateObj.toDate().toLocaleString('en-US', {
                month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true
            }) : 'N/A';

            const channelBadge = document.getElementById('invChannelBadge');
            channelBadge.innerText = channel;
            channelBadge.className = 'badge ' + (o.type === 'POS' ? 'badge-channel-pos' : 'badge-channel-web');

            const statusBadge = document.getElementById('invStatusBadge');
            statusBadge.innerText = paymentStatus;
            statusBadge.className = 'badge badge-' + paymentStatus.toLowerCase().replace(/\s+/g, '-');

            document.getElementById('invCustomerName').innerText = o.customer_name || o.customerName || 'Walk-in Customer';
            document.getElementById('invCustomerEmail').innerText = o.email || o.customer_email || (o.type === 'POS' ? 'Walk-in (no email on file)' : 'N/A');
            document.getElementById('invAddress').innerText = o.address || (o.type === 'POS' ? 'Walk-in — no delivery' : 'No delivery address provided');

            // Items
            const items = o.items || [];
            let rows = '';
            items.forEach(item => {
                const lineTotal = parseFloat(item.price || 0) * parseInt(item.qty || 0);
                rows += `
                    <tr>
                        <td>${item.name || 'Item'}</td>
                        <td class="num">${item.qty || 0}</td>
                        <td class="num">${peso(item.price)}</td>
                        <td class="num">${peso(lineTotal)}</td>
                    </tr>`;
            });
            document.getElementById('invItemsBody').innerHTML = rows || '<tr><td colspan="4" style="text-align:center; padding:30px; color:#ccc;">No items on this invoice.</td></tr>';

            // Totals
            const subtotal = o.subtotal !== undefined ? o.subtotal : items.reduce((s, i) => s + (parseFloat(i.price || 0) * parseInt(i.qty || 0)), 0);
            document.getElementById('invSubtotal').innerText = peso(subtotal);
            if (o.shipping_fee) {
                document.getElementById('invShippingRow').style.display = 'flex';
                document.getElementById('invShipping').innerText = peso(o.shipping_fee);
            }
            document.getElementById('invTotal').innerText = peso(o.total_price || o.total_amount || subtotal);

            // Watermark + lock note for voided invoices
            const wm = document.getElementById('invoiceWatermark');
            if (paymentStatus === 'Voided') {
                wm.innerText = paymentStatus.toUpperCase();
                wm.className = 'watermark';
            } else {
                wm.innerText = '';
            }

            const lockNote = document.getElementById('lockNote');
            if (lockNote) lockNote.style.display = o.locked ? 'inline-flex' : 'none';

            // Proof of Delivery — purely additive: only shows if these fields exist on the order.
            // No write ever happens from this page, so an order with no POD data renders exactly as before.
            const podSection = document.getElementById('podSection');
            const podUrl = o.podPhotoUrl || o.deliveryPhoto;
            if (podUrl) {
                podSection.style.display = 'block';
                document.getElementById('podThumb').src = podUrl;
                document.getElementById('podModalImg').src = podUrl;
                document.getElementById('podCourier').innerText = o.courierName || o.podCourier || '—';
                document.getElementById('podRecipient').innerText = o.podRecipient || o.recipientName || '—';
                const podDate = o.podTimestamp || o.deliveredAt;
                document.getElementById('podTimestamp').innerText = podDate ? podDate.toDate().toLocaleString('en-US', {
                    month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true
                }) : '—';
            } else {
                podSection.style.display = 'none';
            }

            // One-time auto action, driven by ?action=print|download in the URL.
            // `autoActionDone` guards this so a later onSnapshot re-fire (e.g. someone
            // else edits the order while this tab is open) never re-triggers a print/download.
            if (!autoActionDone) {
                autoActionDone = true;
                const params = new URLSearchParams(window.location.search);
                const action = params.get('action');
                if (action === 'print') {
                    setTimeout(() => window.print(), 400);
                } else if (action === 'download') {
                    setTimeout(() => downloadInvoicePdf(), 400);
                }
            }
        }, err => {
            console.error('Invoice load error:', err);
            alert('Could not load invoice: ' + err.message);
        });
    });

    async function downloadInvoicePdf() {
        const btn = document.getElementById('downloadPdfBtn');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing…';
        try {
            const card = document.getElementById('invoiceCard');
            const canvas = await html2canvas(card, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
            const imgData = canvas.toDataURL('image/png');

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

            pdf.save(`${currentInvoiceId}.pdf`);
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