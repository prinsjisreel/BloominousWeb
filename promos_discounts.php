<?php 
/**
 * BLOOMINOUS - Voucher Management (Firebase Spoke)
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
            <h1 class="brand-font text-5xl font-black text-gray-800">Promos & Discounts</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Manage discount coupon codes and loyalty promotions.</p>
        </div>
        <button onclick="document.getElementById('promoform').style.display='block'" class="btn-primary hover:scale-105 active:scale-95 transition-all flex items-center px-8 shadow-lg shadow-pink-100 uppercase tracking-widest text-xs font-black">
            <i class="fa-solid fa-plus mr-3"></i> Create New Promo
        </button>
    </div>

    <div id="promoform" style="display:none; background:white; padding:3rem; border-radius:35px; margin-bottom:3rem; border:1px solid var(--primary); border-style:dashed;">
        <h4 class="brand-font text-2xl font-black mb-8 text-gray-800">Create New Promo Code</h4>
        <form id="addPromoForm" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px;">
            <div>
                <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 1px;">Spectral Code</label>
                <input type="text" id="promoCode" placeholder="CODE (e.g. BLOOM20)" required style="width:100%; padding:14px 18px; border-radius:12px; border:1px solid #f0f0f0; outline: none; background:#fafafa; font-weight:600; font-size:0.9rem;">
            </div>
            <div>
                <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 1px;">Percentage Ratio (%)</label>
                <input type="number" id="discount" placeholder="Discount %" required style="width:100%; padding:14px 18px; border-radius:12px; border:1px solid #f0f0f0; outline: none; background:#fafafa; font-weight:600; font-size:0.9rem;">
            </div>
            <div>
                <label style="font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 1px;">Sunset Date</label>
                <input type="date" id="expiry" required style="width:100%; padding:14px 18px; border-radius:12px; border:1px solid #f0f0f0; outline: none; background:#fafafa; font-weight:600; font-size:0.9rem;">
            </div>
            <button type="submit" id="saveBtn" class="btn-primary" style="grid-column: span 3; padding: 20px; text-transform: uppercase; letter-spacing: 2px; font-size: 0.7rem;">Authorize Protocol</button>
        </form>
    </div>

    <div id="promosList" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap:30px;">
        <div style="grid-column: 1 / -1; text-align: center; padding: 80px; color: #ddd; font-style: italic; font-weight: 500;">Retrieving privilege records...</div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const promosList = document.getElementById('promosList');

        // Real-time listener for promos
        db.collection('promos').orderBy('created_at', 'desc').onSnapshot(snap => {
            if (snap.empty) {
                promosList.innerHTML = `
                    <div style="grid-column: 1 / -1; text-align: center; padding: 50px; color: #888;">
                        <i class="fa-solid fa-ticket-simple" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                        <p>No active vouchers found. Create one to get started!</p>
                    </div>
                `;
                return;
            }

            let html = '';
            snap.forEach(doc => {
                const p = doc.data();
                const id = doc.id;
                const expiry = p.expiry_date ? new Date(p.expiry_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';

                html += `
                <div style="background:white; border:2px dashed var(--primary); padding:2rem; border-radius:30px; text-align:center; position:relative; box-shadow: 0 10px 30px rgba(233,30,99,0.03); transition: 0.3s; overflow:hidden;">
                    <button onclick="deletePromo('${id}')" style="position:absolute; top:20px; right:20px; color:#aaa; background:none; border:none; cursor:pointer; font-size: 1rem; transition: 0.2s;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='#aaa'">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                    <div style="font-size:0.65rem; font-weight:800; color:var(--primary); text-transform:uppercase; letter-spacing:3px; margin-bottom:15px;">Voucher Asset</div>
                    <div class="brand-font" style="font-size:2.5rem; font-weight:900; color:var(--text-main); line-height:1;">${p.promo_code}</div>
                    <div class="brand-font" style="font-size:1.8rem; font-weight:800; margin:15px 0; color:var(--primary); display:flex; align-items:center; justify-content:center; gap:8px;">
                        <span>${p.discount_percentage}%</span>
                        <span style="font-size:0.8rem; font-weight:400; color:var(--text-light); text-transform:uppercase; letter-spacing:1px;">Reduction</span>
                    </div>
                    <div style="font-size:0.7rem; color:var(--text-light); font-weight:700; text-transform:uppercase; letter-spacing:1px;">Expiring: ${expiry}</div>
                    <div style="position:absolute; bottom:-15px; left:50%; transform:translateX(-50%); width:120%; height:20px; background:var(--primary); opacity:0.05; filter:blur(10px);"></div>
                </div>
                `;
            });
            promosList.innerHTML = html;
        });

        document.getElementById('addPromoForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('saveBtn');
            btn.disabled = true;
            btn.innerText = 'Creating...';

            try {
                const code = document.getElementById('promoCode').value.toUpperCase();
                
                // Check if code exists
                const check = await db.collection('promos').where('promo_code', '==', code).get();
                if (!check.empty) {
                    alert('Promo code already exists!');
                    btn.disabled = false;
                    btn.innerText = 'Create Promo Code';
                    return;
                }

                await db.collection('promos').add({
                    promo_code: code,
                    discount_percentage: parseInt(document.getElementById('discount').value),
                    expiry_date: document.getElementById('expiry').value,
                    created_at: firebase.firestore.FieldValue.serverTimestamp()
                });
                alert('Voucher Created Successfully!');
                document.getElementById('addPromoForm').reset();
                document.getElementById('promoform').style.display = 'none';
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = 'Create Promo Code';
            }
        };
    });

    async function deletePromo(id) {
        if (confirm('Are you sure you want to delete this voucher?')) {
            try {
                await db.collection('promos').doc(id).delete();
            } catch (err) {
                alert('Error: ' + err.message);
            }
        }
    }
</script>

<?php include 'templates/footer.php'; ?>
