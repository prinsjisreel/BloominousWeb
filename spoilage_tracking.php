<?php 
/**
 * BLOOMINOUS - Spoilage Tracking (Firebase Spoke)
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
    .pos-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 3rem; }
    .stat-card { background: #fff; padding: 2rem; border-radius: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 20px; border: 1px solid #f0f0f0; }
    .icon-box { width: 65px; height: 65px; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
    
    .red-bg { background: rgba(233, 30, 99, 0.1); color: var(--primary); }
    .orange-bg { background: rgba(255, 177, 66, 0.1); color: #f39c12; }
    
    .amount-text { font-family: 'Cormorant Garamond', serif; font-size: 2.2rem; font-weight: 800; color: var(--text-main); line-height: 1; }
    .table-card { background: #fff; border-radius: 35px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; overflow: hidden; }
    
    .form-box { background: #fff; padding: 3rem; border-radius: 35px; margin-bottom: 3rem; border: 1px solid var(--primary); border-style: dashed; }
    label { font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 1px; }
    input, select, textarea { width: 100%; padding: 14px 18px; border-radius: 12px; border: 1px solid #f0f0f0; outline: none; background: #fafafa; transition: 0.3s; font-size: 0.9rem; font-weight: 500; color: var(--text-main); }
    input:focus { border-color: var(--primary); background: #fff; }
</style>

<main class="pos-content">
    <div class="header-area flex justify-between items-center mb-10">
        <div>
            <h1 class="brand-font text-4xl font-black text-gray-800">Spoilage Log</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Quantifying entropy and business preservation metrics.</p>
        </div>
        <button onclick="toggleForm()" class="btn-primary flex items-center px-8 shadow-lg shadow-pink-100 uppercase tracking-widest text-xs font-black">
            <i class="fa-solid fa-plus mr-3"></i> Log Anomalies
        </button>
    </div>

    <!-- HIDDEN FORM FOR ADDING DATA -->
    <div id="spoilageForm" class="form-box" style="display: none;">
        <h4 class="brand-font text-2xl font-black mb-8 text-gray-800"><i class="fa-solid fa-leaf mr-2 text-pink-500"></i> Discard Verification</h4>
        <form id="addSpoilageForm">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                <div>
                    <label>Floral Entity</label>
                    <select id="productId" required onchange="updateLossAmount(this)">
                        <option value="">-- Catalog Selection --</option>
                    </select>
                </div>
                <div>
                    <label>Magnitude / Qty</label>
                    <input type="number" id="quantity" min="1" required oninput="calculateLoss()">
                </div>
                <div>
                    <label>Fiscal Impact (₱)</label>
                    <input type="number" step="0.01" id="lossAmount" required readonly>
                    <small id="priceHint" style="color: var(--primary); font-size: 0.65rem; font-weight: 800; text-transform: uppercase; margin-top: 5px; display: block;"></small>
                </div>
                <div>
                    <label>Rationale</label>
                    <select id="reason" required onchange="calculateLoss()">
                        <option value="Natural Wilting">Natural Wilting</option>
                        <option value="Damaged during delivery">Logistic Compromise</option>
                        <option value="Handling Error">Handling Discrepancy</option>
                        <option value="Pest/Disease">Biological Stress</option>
                        <option value="Salvaged / Reusable Scraps">Resource Recovery (Salvage)</option>
                        <option value="Other">Miscellaneous</option>
                    </select>
                </div>
            </div>
            <button type="submit" id="saveBtn" class="btn-primary mt-8 w-full py-5 text-sm uppercase tracking-widest">Execute Depletion & Reconciliation</button>
        </form>
    </div>

    <div class="analytics-grid">
        <div class="stat-card">
            <div class="icon-box red-bg"><i class="fa-solid fa-skull-crossbones"></i></div>
            <div>
                <p class="label-text">Total Fiscal Leakage</p>
                <h3 class="amount-text" id="totalLoss">₱0.00</h3>
            </div>
        </div>
        <div class="stat-card">
            <div class="icon-box orange-bg"><i class="fa-solid fa-weight-hanging"></i></div>
            <div>
                <p class="label-text">Volume Displacement</p>
                <h3 class="amount-text" style="color:#f39c12;" id="totalWasted">0 units</h3>
            </div>
        </div>
    </div>

    <div class="table-card">
        <h4 style="font-weight: 800; margin-bottom: 25px; color: #363949;">Spoilage History</h4>
        <table style="width: 100%; text-align: left; border-collapse: collapse;">
            <thead style="border-bottom: 2px solid #f0f2f5; color: #b2bec3; font-size: 0.75rem; text-transform: uppercase;">
                <tr>
                    <th style="padding:15px;">Date</th>
                    <th style="padding:15px;">Flower</th>
                    <th style="padding:15px;">Qty</th>
                    <th style="padding:15px;">Reason</th>
                    <th style="padding:15px;">Loss</th>
                    <th style="padding:15px;">Reported By</th>
                </tr>
            </thead>
            <tbody id="spoilageData">
                <tr><td colspan="6" style="text-align:center; padding:50px; color:#b2bec3;">Loading history...</td></tr>
            </tbody>
        </table>
    </div>
</main>

<script>
    let inventoryData = [];

    document.addEventListener('DOMContentLoaded', () => {
        const spoilageData = document.getElementById('spoilageData');
        const productSelect = document.getElementById('productId');
        const urlParams = new URLSearchParams(window.location.search);
        const preSelectedId = urlParams.get('productId');

        // Fetch Inventory for dropdown
        getBranchPath('inventory').onSnapshot(snap => {
            inventoryData = [];
            let options = '<option value="">-- Select Product --</option>';
            snap.forEach(doc => {
                const p = doc.data();
                if (p.isDeleted === true || p.status === 'archived') {
                    return;
                }
                inventoryData.push({ id: doc.id, ...p });
                const selected = doc.id === preSelectedId ? 'selected' : '';
                options += `<option value="${doc.id}" ${selected}>${p.name} (Stock: ${p.stock || 0})</option>`;
            });
            productSelect.innerHTML = options;
            
            if (preSelectedId) {
                updateLossAmount(productSelect);
                toggleForm();
            }
        });

        // Real-time listener for spoilage
        getBranchPath('spoilage').orderBy('created_at', 'desc').onSnapshot(snap => {
            if (snap.empty) {
                spoilageData.innerHTML = `
                    <tr><td colspan="6" style="text-align:center; padding:50px; color:#b2bec3;">
                        <i class="fa-solid fa-leaf" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.2;"></i>
                        <p>No spoilage recorded yet.</p>
                    </td></tr>
                `;
                document.getElementById('totalLoss').innerText = '₱0.00';
                document.getElementById('totalWasted').innerText = '0 pcs';
                return;
            }

            let html = '';
            let totalLoss = 0;
            let totalWasted = 0;

            snap.forEach(doc => {
                const s = doc.data();
                const loss = parseFloat(s.loss_amount || 0);
                const qty = parseInt(s.quantity || 0);
                totalLoss += loss;
                totalWasted += qty;

                const date = s.created_at ? s.created_at.toDate().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';

                const isSalvaged = s.is_salvaged === true || s.reason === 'Salvaged / Reusable Scraps';
                const statusBadge = isSalvaged 
                    ? '<span style="background: #e8f5e9; color: #2e7d32; padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; margin-left: 10px;">Salvaged</span>' 
                    : '';

                html += `
                <tr style="border-bottom: 1px solid #f8f9fa; transition: 0.2s;">
                    <td style="padding:15px; font-size: 0.85rem; color:#7d8da1;">${date}</td>
                    <td style="padding:15px; font-weight:700; color: #363949;">${s.flower_name} ${statusBadge}</td>
                    <td style="padding:15px; font-weight:600; color: #363949;">${qty}</td>
                    <td style="padding:15px; color:#7d8da1; font-size: 0.85rem; font-style: italic;">${s.reason || 'N/A'}</td>
                    <td style="padding:15px; color: ${isSalvaged ? '#2e7d32' : '#c62828'}; font-weight:800;">₱${loss.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    <td style="padding:15px; color:#7d8da1; font-size: 0.8rem;">${s.reported_by || 'System'}</td>
                </tr>
                `;
            });

            spoilageData.innerHTML = html;
            document.getElementById('totalLoss').innerText = '₱' + totalLoss.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('totalWasted').innerText = totalWasted.toLocaleString() + ' pcs';
        });

        document.getElementById('addSpoilageForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('saveBtn');
            const pId = document.getElementById('productId').value;
            const qty = parseInt(document.getElementById('quantity').value);
            const loss = parseFloat(document.getElementById('lossAmount').value);
            const reason = document.getElementById('reason').value;

            const product = inventoryData.find(p => p.id === pId);
            if (!product) return alert('Please select a product');
            if (qty > (product.stock || 0)) return alert('Quantity exceeds current stock!');

            btn.disabled = true;
            btn.innerText = 'Processing...';

            try {
                let currentLeftovers = 0;
                let existingBouquetRef = null;

                if (reason === 'Salvaged / Reusable Scraps') {
                    const recQuery = await getBranchPath('inventory').where('name', '==', 'Recycled Bouquet').limit(1).get();
                    if (!recQuery.empty) {
                        const existingDoc = recQuery.docs[0];
                        existingBouquetRef = existingDoc.ref;
                        currentLeftovers = existingDoc.data().leftoverFlowers || 0;
                    }
                }

                const batch = db.batch();

                // 1. Add Spoilage/Salvage Record
                const spoilageRef = getBranchPath('spoilage').doc();
                batch.set(spoilageRef, {
                    product_id: pId,
                    flower_name: product.name,
                    quantity: qty,
                    loss_amount: loss,
                    reason: reason,
                    reported_by: '<?php echo $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Staff'; ?>',
                    is_salvaged: reason === 'Salvaged / Reusable Scraps',
                    created_at: firebase.firestore.FieldValue.serverTimestamp()
                });

                // 2. Deduct Original Inventory
                const inventoryRef = getBranchPath('inventory').doc(pId);
                batch.update(inventoryRef, {
                    stock: firebase.firestore.FieldValue.increment(-qty)
                });

                let newBouquets = 0;
                let newLeftovers = 0;

                // 3. Increment Recycled Inventory if salvaged
                if (reason === 'Salvaged / Reusable Scraps') {
                    const totalFlowers = qty + currentLeftovers;
                    newBouquets = Math.floor(totalFlowers / 4);
                    newLeftovers = totalFlowers % 4;
                    const description = "This bouquet is composed of salvaged flowers (Mixed varieties). Limited offer: Only good for two days.";
                    
                    if (existingBouquetRef) {
                        batch.update(existingBouquetRef, {
                            stock: firebase.firestore.FieldValue.increment(newBouquets),
                            leftoverFlowers: newLeftovers,
                            description: description,
                            price: 150.0,
                            category: 'Bouquets',
                            updatedAt: firebase.firestore.FieldValue.serverTimestamp()
                        });
                    } else {
                        // Create new 'Recycled Bouquet' item
                        const recRef = getBranchPath('inventory').doc();
                        batch.set(recRef, {
                            name: 'Recycled Bouquet',
                            price: 150.0,
                            stock: newBouquets,
                            leftoverFlowers: newLeftovers,
                            category: 'Bouquets',
                            description: description,
                            image: 'https://images.unsplash.com/photo-1582794543139-8ac9cb0f7b11?q=80&w=200&auto=format&fit=crop',
                            branchId: window.currentBranch,
                            createdAt: firebase.firestore.FieldValue.serverTimestamp(),
                            updatedAt: firebase.firestore.FieldValue.serverTimestamp()
                        });
                    }
                }

                // 4. Add Notification
                const notifRef = db.collection('notifications').doc();
                const notifTitle = reason === 'Salvaged / Reusable Scraps' ? 'Bouquet Created from Salvage' : 'Spoilage Reported';
                let notifMsg = '';
                if (reason === 'Salvaged / Reusable Scraps') {
                    if (newBouquets > 0) {
                        notifMsg = `[${window.currentBranch}] ${qty} pcs of ${product.name} salvaged. Built ${newBouquets} Recycled Bouquet(s) (leftover: ${newLeftovers} flower(s)).`;
                    } else {
                        notifMsg = `[${window.currentBranch}] ${qty} pcs of ${product.name} salvaged to pool (leftover: ${newLeftovers} flower(s)). Needs 4 flowers for 1 bouquet.`;
                    }
                } else {
                    notifMsg = `[${window.currentBranch}] ${qty} pcs of ${product.name} reported as spoiled (${reason}).`;
                }

                batch.set(notifRef, {
                    title: notifTitle,
                    message: notifMsg,
                    type: reason === 'Salvaged / Reusable Scraps' ? 'success' : 'warning',
                    branchId: window.currentBranch,
                    created_at: firebase.firestore.FieldValue.serverTimestamp(),
                    read: false
                });

                await batch.commit();
                
                let successMsg = '';
                if (reason === 'Salvaged / Reusable Scraps') {
                    if (newBouquets > 0) {
                        successMsg = `Salvaged! Built ${newBouquets} Recycled Bouquet(s) from your salvaged stash (with ${newLeftovers} flower(s) leftover in the pool).`;
                    } else {
                        successMsg = `Salvaged and added to pool! You now have ${newLeftovers} flower(s) accumulated. Need 4 flowers to construct a Recycled Bouquet.`;
                    }
                } else {
                    successMsg = 'Spoilage recorded and stock updated!';
                }
                
                alert(successMsg);
                document.getElementById('addSpoilageForm').reset();
                document.getElementById('priceHint').innerText = '';
                toggleForm();
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = 'Save Report & Deduct Stock';
            }
        };
    });

    function updateLossAmount(select) {
        const pId = select.value;
        const product = inventoryData.find(p => p.id === pId);
        if (product) {
            const price = parseFloat(product.price || 0);
            document.getElementById('priceHint').innerText = `Unit Price: ₱${price.toLocaleString()}`;
            calculateLoss();
        } else {
            document.getElementById('priceHint').innerText = '';
            document.getElementById('lossAmount').value = '';
        }
    }

    function calculateLoss() {
        const pId = document.getElementById('productId').value;
        const qty = parseInt(document.getElementById('quantity').value) || 0;
        const reason = document.getElementById('reason').value;
        const product = inventoryData.find(p => p.id === pId);
        
        if (product) {
            const price = parseFloat(product.price || 0);
            // Salvaged items are not a "loss" because they are added back to inventory for sale
            if (reason === 'Salvaged / Reusable Scraps') {
                document.getElementById('lossAmount').value = "0.00";
            } else {
                document.getElementById('lossAmount').value = (price * qty).toFixed(2);
            }
        }
    }

    function toggleForm() {
        var x = document.getElementById("spoilageForm");
        if (x.style.display === "none" || x.style.display === "") { 
            x.style.display = "block"; 
            x.scrollIntoView({ behavior: 'smooth' });
        } 
        else { 
            x.style.display = "none"; 
        }
    }
</script>

<?php include 'templates/footer.php'; ?>
