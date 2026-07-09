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

        <div class="cart-summary">
            <div class="summary-row">
                <span>Subtotal</span>
                <span id="subtotal">₱0.00</span>
            </div>
            <div class="summary-row">
                <span>Tax (0%)</span>
                <span>₱0.00</span>
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

    function renderCart() {
        const container = document.getElementById('cartItems');
        if (cart.length === 0) {
            container.innerHTML = '<div class="text-center py-20 text-gray-300 italic text-sm">Cart is empty</div>';
            document.getElementById('subtotal').innerText = '₱0.00';
            document.getElementById('total').innerText = '₱0.00';
            return;
        }

        let html = '';
        let total = 0;
        cart.forEach(item => {
            const itemTotal = item.price * item.qty;
            total += itemTotal;
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

        container.innerHTML = html;
        document.getElementById('subtotal').innerText = '₱' + total.toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('total').innerText = '₱' + total.toLocaleString(undefined, {minimumFractionDigits: 2});
    }

    async function processCheckout() {
        if (cart.length === 0) {
            alert('Cart is empty!');
            return;
        }
        
        if (confirm('Confirm transaction?')) {
            const btn = document.getElementById('checkoutBtn');
            btn.disabled = true;
            btn.innerText = 'Processing...';

            try {
                const total = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
                const orderId = 'POS-' + Date.now();
                const typedCustomer = document.getElementById('posCustomerName').value.trim() || 'walk-in';
                const typedRecipient = document.getElementById('posRecipientName').value.trim() || typedCustomer;

                // Create Order in Firestore
                const orderRef = await db.collection('orders').add({
                    order_id: orderId,
                    customer_id: typedCustomer,
                    customer_name: typedCustomer,
                    customerName: typedCustomer, // support both formats
                    recipientName: typedRecipient,
                    total_amount: total,
                    status: 'completed',
                    type: 'POS',
                    items: cart,
                    branchId: window.currentBranch, // SAVE BRANCH ID
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

                alert('Transaction completed successfully!');
                document.getElementById('posCustomerName').value = '';
                document.getElementById('posRecipientName').value = '';
                clearCart();
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerText = 'Process Transaction';
            }
        }
    }
</script>

<?php include 'templates/footer.php'; ?>

<?php include 'templates/footer.php'; ?>
