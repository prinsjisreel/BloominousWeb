<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php';
?>

<style>
    .pos-content { padding: 20px; display: grid; grid-template-columns: 1fr 400px; gap: 20px; height: calc(100vh - 100px); }
    .product-grid-container { background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); overflow-y: auto; }
    .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; }
    .pos-product-card { background: #f8f9fa; border-radius: 15px; padding: 15px; text-align: center; cursor: pointer; transition: 0.3s; border: 2px solid transparent; }
    .pos-product-card:hover { border-color: #7380ec; background: #fff; transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
    .pos-product-card img { width: 100%; height: 120px; object-fit: cover; border-radius: 10px; margin-bottom: 10px; }
    .pos-product-card h4 { font-size: 0.9rem; font-weight: 700; color: #363949; margin-bottom: 5px; }
    .pos-product-card p { font-size: 0.85rem; font-weight: 800; color: #7380ec; }
    .cart-container { background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); display: flex; flex-direction: column; }
    .cart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f3f5; padding-bottom: 10px; }
    .cart-items { flex: 1; overflow-y: auto; margin-bottom: 20px; }
    .cart-item { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #f8f9fa; }
    .cart-item-info { flex: 1; }
    .cart-item-info h5 { font-size: 0.85rem; font-weight: 700; margin-bottom: 2px; }
    .cart-item-info p { font-size: 0.75rem; color: #b2bec3; }
    .cart-item-qty { display: flex; align-items: center; gap: 10px; }
    .qty-btn { width: 25px; height: 25px; border-radius: 5px; border: 1px solid #ddd; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; }
    .cart-summary { border-top: 2px dashed #f1f3f5; padding-top: 20px; }
    .summary-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.9rem; }
    .summary-total { font-size: 1.2rem; font-weight: 800; color: #363949; margin-top: 10px; border-top: 1px solid #f1f3f5; padding-top: 10px; }
    .checkout-btn { background: #7380ec; color: #fff; border: none; padding: 15px; border-radius: 12px; width: 100%; font-weight: 800; cursor: pointer; margin-top: 20px; transition: 0.3s; }
    .checkout-btn:hover { background: #5a65c1; }

    /* Sales Anomaly Gate modal */
    #anomalyOverlay { display: none; position: fixed; inset: 0; background: rgba(20,20,20,0.55); z-index: 500; align-items: center; justify-content: center; padding: 20px; }
    #anomalyOverlay.open { display: flex; }
    #anomalyModal { background: #fff; border-radius: 24px; padding: 2rem; max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 30px 60px rgba(0,0,0,0.2); }
    #anomalyModal h3 { font-size: 1.3rem; font-weight: 900; margin: 0 0 1rem; display: flex; align-items: center; gap: 10px; }
    #anomalyModal h3.severity-medium { color: #b45309; }
    #anomalyModal h3.severity-critical { color: #b91c1c; }
    #anomalyFlagsList { background: #fafafa; border-radius: 14px; padding: 14px 16px; margin-bottom: 1.1rem; font-size: 0.8rem; color: #444; }
    #anomalyFlagsList div { margin-bottom: 6px; }
    #anomalyFlagsList div:last-child { margin-bottom: 0; }
    .anomaly-field { margin-bottom: 1rem; }
    .anomaly-field label { display: block; font-size: 0.65rem; font-weight: 800; color: #999; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 8px; }
    .anomaly-field textarea, .anomaly-field input { width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid #f0f0f0; background: #fafafa; font-weight: 600; font-size: 0.85rem; outline: none; box-sizing: border-box; }
    .anomaly-field textarea:focus, .anomaly-field input:focus { border-color: #7380ec; background: #fff; }
    #anomalyError { color: #b91c1c; font-size: 0.78rem; font-weight: 700; margin-bottom: 0.9rem; display: none; }
    .anomaly-actions { display: flex; gap: 12px; margin-top: 0.5rem; }
    .anomaly-actions button { flex: 1; padding: 14px; border-radius: 14px; font-weight: 800; cursor: pointer; border: none; }
    .anomaly-cancel-btn { background: #f5f5f5; color: #888; }
    .anomaly-confirm-btn { background: #7380ec; color: #fff; }
</style>

<div class="pos-content">
    <div class="product-grid-container">
        <div class="flex justify-between items-center mb-6">
            <div class="flex items-center gap-4">
                <a href="pos.php" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fa-solid fa-arrow-left text-xl"></i>
                </a>
                <h2 class="text-xl font-black text-gray-800 uppercase tracking-tight">Select Products</h2>
            </div>
            <input type="text" id="productSearch" placeholder="Search products..." class="px-4 py-2 rounded-xl border border-gray-100 outline-none focus:ring-2 focus:ring-indigo-100 text-sm w-64">
        </div>
        
        <div class="product-grid" id="productGrid">
            <!-- Products will be injected here -->
            <div class="text-center py-20 text-gray-300 italic text-sm col-span-full">Loading products...</div>
        </div>
    </div>

    <div class="cart-container">
        <div class="cart-header">
            <h3 class="font-bold text-gray-800">Current Order</h3>
            <button onclick="clearCart()" class="text-red-400 text-xs font-bold uppercase">Clear</button>
        </div>

        <div class="cart-items" id="cartItems">
            <div class="text-center py-20 text-gray-300 italic text-sm">Cart is empty</div>
        </div>

        <div class="px-2 mb-4">
            <label class="block text-[10px] font-black uppercase text-gray-400 tracking-wider mb-2">Customer Details</label>
            <input type="text" id="posCustomerName" placeholder="Customer Name (Optional)" class="w-full px-3 py-2 text-xs rounded-lg border border-gray-100 outline-none focus:ring-2 focus:ring-indigo-100 mb-2">
            <input type="text" id="posRecipientName" placeholder="Recipient Name (Optional)" class="w-full px-3 py-2 text-xs rounded-lg border border-gray-100 outline-none focus:ring-2 focus:ring-indigo-100">
        </div>

        <div class="px-2 mb-4">
            <label class="block text-[10px] font-black uppercase text-gray-400 tracking-wider mb-2">Payment Method</label>
            <select id="posPaymentMethod" class="w-full px-3 py-2 text-xs rounded-lg border border-gray-100 outline-none focus:ring-2 focus:ring-indigo-100 font-bold text-gray-700">
                <option value="Cash">Cash</option>
                <option value="GCash">GCash</option>
                <option value="Card">Card</option>
            </select>
        </div>

        <div class="px-2 mb-4">
            <label class="block text-[10px] font-black uppercase text-gray-400 tracking-wider mb-2">Manual Discount (%)</label>
            <input type="number" id="posDiscountPercent" min="0" max="100" step="1" value="0" placeholder="0" class="w-full px-3 py-2 text-xs rounded-lg border border-gray-100 outline-none focus:ring-2 focus:ring-indigo-100" oninput="renderCart()">
            <p class="text-[10px] text-gray-300 mt-1">Discounts above the authorized threshold will require a justification note or manager PIN.</p>
        </div>

        <div class="cart-summary">
            <div class="summary-row">
                <span>Subtotal</span>
                <span id="subtotal">₱0.00</span>
            </div>
            <div class="summary-row">
                <span>Discount</span>
                <span id="discountLine">-₱0.00</span>
            </div>
            <div class="summary-row summary-total">
                <span>Total</span>
                <span id="total">₱0.00</span>
            </div>
            
            <button class="checkout-btn" id="checkoutBtn" onclick="processCheckout()">
                Process Transaction
            </button>
        </div>
    </div>
</div>

<!-- Sales Anomaly Gate: shown only when a check flags this transaction -->
<div id="anomalyOverlay">
    <div id="anomalyModal">
        <h3 id="anomalyTitle"><i class="fa-solid fa-triangle-exclamation"></i> Review Required</h3>
        <div id="anomalyFlagsList"></div>
        <div id="anomalyError"></div>

        <div class="anomaly-field" id="anomalyNoteField" style="display:none;">
            <label>Justification Note (required)</label>
            <textarea id="anomalyNote" rows="3" placeholder="Explain why this transaction is unusual..."></textarea>
        </div>

        <div id="anomalyPinFields" style="display:none;">
            <div class="anomaly-field">
                <label>Approving Manager Email</label>
                <input type="email" id="anomalyApproverEmail" placeholder="manager@bloom.com">
            </div>
            <div class="anomaly-field">
                <label>Manager PIN (password)</label>
                <input type="password" id="anomalyApproverPin" placeholder="••••••••">
            </div>
        </div>

        <div class="anomaly-actions">
            <button type="button" class="anomaly-cancel-btn" id="anomalyCancelBtn">Cancel Transaction</button>
            <button type="button" class="anomaly-confirm-btn" id="anomalyConfirmBtn">Confirm & Proceed</button>
        </div>
    </div>
</div>

<script>
    let cart = [];
    let allProducts = [];

    document.addEventListener('DOMContentLoaded', () => {
        const grid = document.getElementById('productGrid');
        const searchInput = document.getElementById('productSearch');

        // Always use branch-specific inventory in POS Terminal
        getBranchPath('inventory').where('stock', '>', 0).onSnapshot(snap => {
            allProducts = [];
            snap.forEach(doc => {
                const data = doc.data();
                if (data.isDeleted === true || data.status === 'archived') {
                    return;
                }
                allProducts.push({ id: doc.id, ...data });
            });
            renderProducts(allProducts);
        }, (error) => {
            console.error("Error fetching inventory:", error);
            grid.innerHTML = '<div class="text-center py-20 text-red-300 italic text-sm col-span-full">Error loading inventory. Check permissions.</div>';
        });

        searchInput.oninput = (e) => {
            const term = e.target.value.toLowerCase();
            const filtered = allProducts.filter(p => (p.name || "").toLowerCase().includes(term));
            renderProducts(filtered);
        };
    });

    function renderProducts(products) {
        const grid = document.getElementById('productGrid');
        if (products.length === 0) {
            grid.innerHTML = '<div class="text-center py-20 text-gray-300 italic text-sm col-span-full">No products found</div>';
            return;
        }

        let html = '';
        products.forEach(p => {
            const img = p.image ? p.image : 'https://via.placeholder.com/150?text=Bloom';
            html += `
                <div class="pos-product-card" onclick="addToCart('${p.id}', '${(p.name || "Unnamed").replace(/'/g, "\\'")}', ${p.price})">
                    <img src="${img}" onerror="this.src='https://via.placeholder.com/150'">
                    <h4>${p.name || 'Unnamed'}</h4>
                    <p>₱${parseFloat(p.price || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</p>
                    <small class="text-[10px] text-gray-400">Stock: ${p.stock || 0}</small>
                </div>
            `;
        });
        grid.innerHTML = html;
    }

    function addToCart(id, name, price) {
        const existing = cart.find(item => item.id === id);
        if (existing) {
            existing.qty++;
        } else {
            cart.push({ id, name, price, qty: 1 });
        }
        renderCart();
    }

    function updateQty(id, delta) {
        const item = cart.find(item => item.id === id);
        if (item) {
            item.qty += delta;
            if (item.qty <= 0) {
                cart = cart.filter(i => i.id !== id);
            }
        }
        renderCart();
    }

    function clearCart() {
        cart = [];
        renderCart();
    }

    // Reads the discount % input, clamped to a sane 0-100 range so a typo
    // (like "500") can't produce a negative total.
    function getDiscountPercent() {
        const raw = parseFloat(document.getElementById('posDiscountPercent').value);
        if (isNaN(raw)) return 0;
        return Math.min(100, Math.max(0, raw));
    }

    function renderCart() {
        const container = document.getElementById('cartItems');
        if (cart.length === 0) {
            container.innerHTML = '<div class="text-center py-20 text-gray-300 italic text-sm">Cart is empty</div>';
            document.getElementById('subtotal').innerText = '₱0.00';
            document.getElementById('discountLine').innerText = '-₱0.00';
            document.getElementById('total').innerText = '₱0.00';
            return;
        }

        let html = '';
        let subtotal = 0;
        cart.forEach(item => {
            const itemTotal = item.price * item.qty;
            subtotal += itemTotal;
            html += `
                <div class="cart-item">
                    <div class="cart-item-info">
                        <h5>${item.name}</h5>
                        <p>₱${item.price.toLocaleString()} x ${item.qty}</p>
                    </div>
                    <div class="cart-item-qty">
                        <button class="qty-btn" onclick="updateQty('${item.id}', -1)">-</button>
                        <span class="text-sm font-bold">${item.qty}</span>
                        <button class="qty-btn" onclick="updateQty('${item.id}', 1)">+</button>
                    </div>
                    <div class="text-right ml-4">
                        <span class="text-xs font-bold text-gray-800">₱${itemTotal.toLocaleString()}</span>
                    </div>
                </div>
            `;
        });

        const discountPercent = getDiscountPercent();
        const discountAmount = subtotal * (discountPercent / 100);
        const total = subtotal - discountAmount;

        container.innerHTML = html;
        document.getElementById('subtotal').innerText = '₱' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('discountLine').innerText = '-₱' + discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2}) + (discountPercent > 0 ? ` (${discountPercent}%)` : '');
        document.getElementById('total').innerText = '₱' + total.toLocaleString(undefined, {minimumFractionDigits: 2});
    }

    /**
     * Shows the anomaly gate modal and resolves once the cashier either
     * satisfies it or cancels. Returns a small result object the caller
     * uses to decide whether to proceed and what to log:
     *   { proceed: false }                                  // cashier cancelled
     *   { proceed: true, justificationNote, overriddenBy }   // cleared the gate
     *
     * severity determines which extra field is required:
     *   'medium'   -> a typed justification note
     *   'critical' -> a verified manager email + PIN
     * (low-severity flags never reach this function — they're logged
     * silently by the caller instead, per the spec's severity tiers.)
     */
    function showAnomalyGate(severity, flaggedChecks) {
        return new Promise((resolve) => {
            const overlay = document.getElementById('anomalyOverlay');
            const title = document.getElementById('anomalyTitle');
            const flagsList = document.getElementById('anomalyFlagsList');
            const noteField = document.getElementById('anomalyNoteField');
            const pinFields = document.getElementById('anomalyPinFields');
            const errorBox = document.getElementById('anomalyError');
            const noteInput = document.getElementById('anomalyNote');
            const emailInput = document.getElementById('anomalyApproverEmail');
            const pinInput = document.getElementById('anomalyApproverPin');
            const confirmBtn = document.getElementById('anomalyConfirmBtn');
            const cancelBtn = document.getElementById('anomalyCancelBtn');

            // Reset modal state every time it opens
            errorBox.style.display = 'none';
            noteInput.value = '';
            emailInput.value = '';
            pinInput.value = '';
            title.className = 'severity-' + severity;
            title.innerHTML = severity === 'critical'
                ? '<i class="fa-solid fa-ban"></i> Critical Risk — Manager Override Required'
                : '<i class="fa-solid fa-triangle-exclamation"></i> Medium Risk — Justification Required';
            flagsList.innerHTML = flaggedChecks.map(c => `<div>&bull; ${c.detail}</div>`).join('');
            noteField.style.display = severity === 'medium' ? 'block' : 'none';
            pinFields.style.display = severity === 'critical' ? 'block' : 'none';

            overlay.classList.add('open');

            function cleanup() {
                overlay.classList.remove('open');
                confirmBtn.onclick = null;
                cancelBtn.onclick = null;
            }

            cancelBtn.onclick = () => {
                cleanup();
                resolve({ proceed: false });
            };

            confirmBtn.onclick = async () => {
                if (severity === 'medium') {
                    const note = noteInput.value.trim();
                    if (!note) {
                        errorBox.innerText = 'A justification note is required to proceed.';
                        errorBox.style.display = 'block';
                        return;
                    }
                    cleanup();
                    resolve({ proceed: true, justificationNote: note, overriddenBy: null });
                    return;
                }

                // severity === 'critical'
                confirmBtn.disabled = true;
                confirmBtn.innerText = 'Verifying...';
                const verifiedManager = await SalesAnomalies.verifyManagerPin(emailInput.value, pinInput.value);
                confirmBtn.disabled = false;
                confirmBtn.innerText = 'Confirm & Proceed';
                if (!verifiedManager) {
                    errorBox.innerText = 'Invalid manager credentials — override denied.';
                    errorBox.style.display = 'block';
                    return;
                }
                cleanup();
                resolve({ proceed: true, justificationNote: null, overriddenBy: verifiedManager });
            };
        });
    }

    async function processCheckout() {
        if (cart.length === 0) {
            alert('Cart is empty!');
            return;
        }

        const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
        const discountPercent = getDiscountPercent();
        const discountAmount = subtotal * (discountPercent / 100);
        const total = subtotal - discountAmount;

        if (!confirm('Confirm transaction?')) return;

        const btn = document.getElementById('checkoutBtn');
        btn.disabled = true;
        btn.innerText = 'Checking for anomalies...';

        try {
            // --- Sales Anomalies Detection runs BEFORE the sale is written ---
            // Each check independently decides if it's flagged, and at what
            // severity. We run them in parallel since they're independent
            // reads (no shared state), then take the worst severity found.
            const checks = await Promise.all([
                SalesAnomalies.checkValueSpike(window.currentBranch, total),
                SalesAnomalies.checkOffHours(),
                SalesAnomalies.checkDiscount(discountPercent)
            ]);
            const flaggedChecks = checks.filter(c => c.flagged);
            const severity = SalesAnomalies.worstSeverity(checks);

            let justificationNote = null;
            let overriddenBy = null;

            if (severity === 'medium' || severity === 'critical') {
                btn.innerText = 'Awaiting review...';
                const gateResult = await showAnomalyGate(severity, flaggedChecks);
                if (!gateResult.proceed) {
                    // Cashier backed out — nothing was written, sale never happened.
                    btn.disabled = false;
                    btn.innerText = 'Process Transaction';
                    return;
                }
                justificationNote = gateResult.justificationNote;
                overriddenBy = gateResult.overriddenBy;
            }
            // severity === 'low' (or null) falls through here with no gate —
            // Low risk items are logged silently below, matching the spec.

            // Log every triggered flag, regardless of tier, so the dashboard
            // has a complete picture even for the silent Low-risk ones.
            for (const c of flaggedChecks) {
                await SalesAnomalies.logAnomaly({
                    type: c.type,
                    severity: c.severity,
                    detail: c.detail,
                    branchId: window.currentBranch,
                    justificationNote,
                    overriddenBy
                });
            }

            btn.innerText = 'Processing...';

            const orderId = 'POS-' + Date.now();
            const typedCustomer = document.getElementById('posCustomerName').value.trim() || 'walk-in';
            const typedRecipient = document.getElementById('posRecipientName').value.trim() || typedCustomer;
            const selectedPaymentMethod = document.getElementById('posPaymentMethod').value;

            // Sequential invoice number (INV-2026-0001, ...) — transaction-safe, never collides
            const invoiceId = await window.generateInvoiceId();

            // Create Order in Firestore
            const orderRef = await db.collection('orders').add({
                order_id: orderId,
                invoiceId: invoiceId,
                customer_id: typedCustomer,
                customer_name: typedCustomer,
                customerName: typedCustomer, // support both formats
                recipientName: typedRecipient,
                subtotal: subtotal,
                discountPercent: discountPercent,
                discountAmount: discountAmount,
                total_amount: total,
                status: 'completed',
                paymentStatus: 'Paid', // walk-in sales settle immediately at the register
                payment_method: selectedPaymentMethod, // FIX: was never recorded for POS sales before
                locked: true, // completed invoice — from now on only the Void module may touch it
                type: 'POS',
                items: cart,
                branchId: window.currentBranch, // SAVE BRANCH ID
                cashierEmail: window.currentUserEmail || null,
                cashierName: window.currentUserName || null,
                anomalySeverity: severity || null,
                timestamp: firebase.firestore.FieldValue.serverTimestamp(),
                createdAt: firebase.firestore.FieldValue.serverTimestamp()
            });

            // Update Stocks in the branch-specific inventory
            for (const item of cart) {
                const productRef = getBranchPath('inventory').doc(item.id);
                await db.runTransaction(async (transaction) => {
                    const doc = await transaction.get(productRef);
                    if (!doc.exists) throw "Product does not exist in this branch!";
                    const newStock = (doc.data().stock || 0) - item.qty;
                    transaction.update(productRef, { stock: newStock });
                });
            }

            // Push notification
            await db.collection('notifications').add({
                title: 'New POS Sale',
                message: `[${window.currentBranch}] Order #${orderId} completed for ₱${total.toLocaleString()}`,
                type: 'sale',
                branchId: window.currentBranch,
                timestamp: firebase.firestore.FieldValue.serverTimestamp(),
                read: false
            });

            alert(`Transaction completed successfully! Invoice: ${invoiceId}`);
            document.getElementById('posCustomerName').value = '';
            document.getElementById('posRecipientName').value = '';
            document.getElementById('posPaymentMethod').value = 'Cash';
            document.getElementById('posDiscountPercent').value = '0';
            clearCart();
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred: ' + error.message);
        } finally {
            btn.disabled = false;
            btn.innerText = 'Process Transaction';
        }
    }
</script>

<?php include 'templates/footer.php'; ?>

<?php include 'templates/footer.php'; ?>