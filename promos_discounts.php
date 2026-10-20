<?php 
/**
 * BLOOMINOUS - Voucher Management (Firebase Spoke)
 *
 * Promos created here double as REWARDS in the customer app's catalog:
 *   - promo_code          -> internal only, never shown to customers
 *   - discount_percentage -> shown to customers as "X% Off Voucher"
 *   - points_cost         -> how many Bloom Points it costs (0 = not in catalog)
 *   - expiry_date         -> hides the reward from the catalog after this date
 * Loyalty rules (settings/loyalty_config) decide how customers EARN points.
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
    /* Former inline styles, now classes so they can follow the theme.
       Light values are identical to the old inline ones. */
    .promo-label { font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 1px; }
    .promo-input { width: 100%; padding: 14px 18px; border-radius: 12px; border: 1px solid var(--border-color); outline: none; background: var(--surface-alt); color: var(--text-main); font-weight: 600; font-size: 0.9rem; }
    .promo-hint { font-size: 0.65rem; color: #aaa; margin-top: 4px; font-weight: 600; }

    .promo-form-card { display: none; background: var(--surface); padding: 3rem; border-radius: 35px; margin-bottom: 3rem; border: 1px dashed var(--primary); }
    /* 4 columns now: code, discount %, points cost, expiry */
    .promo-form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 20px; }
    .promo-form-grid .span-all { grid-column: span 4; }

    .loyalty-card { background: var(--surface); padding: 3rem; border-radius: 35px; margin-bottom: 3rem; border: 2px solid #7B79F2; box-shadow: 0 15px 45px rgba(123, 121, 242, 0.05); }
    .loyalty-head { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
    .loyalty-icon { background: rgba(123, 121, 242, 0.1); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #7B79F2; flex-shrink: 0; }
    .loyalty-sub { font-size: 0.75rem; color: #888; margin: 4px 0 0 0; }
    .loyalty-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .loyalty-form-grid .span-all { grid-column: span 2; }
    .loyalty-save-btn { padding: 18px; text-transform: uppercase; letter-spacing: 2px; font-size: 0.7rem; background: #7B79F2; box-shadow: 0 10px 20px rgba(123, 121, 242, 0.2); }

    .promos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 30px; }
    .promos-status { grid-column: 1 / -1; text-align: center; }
    .promos-loading { padding: 80px; color: #ddd; font-style: italic; font-weight: 500; }
    .promos-empty { padding: 50px; color: #888; }

    .promo-card { background: var(--surface); border: 2px dashed var(--primary); padding: 2rem; border-radius: 30px; text-align: center; position: relative; box-shadow: 0 10px 30px rgba(233,30,99,0.03); transition: 0.3s; overflow: hidden; }
    .promo-delete { position: absolute; top: 20px; right: 20px; color: #aaa; background: none; border: none; cursor: pointer; font-size: 1rem; transition: 0.2s; }
    .promo-delete:hover { color: var(--primary); }
    .promo-eyebrow { font-size: 0.65rem; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 3px; margin-bottom: 15px; }
    .promo-code { font-size: 2.5rem; font-weight: 900; color: var(--text-main); line-height: 1; }
    .promo-amount { font-size: 1.8rem; font-weight: 800; margin: 15px 0; color: var(--primary); display: flex; align-items: center; justify-content: center; gap: 8px; }
    .promo-amount small { font-size: 0.8rem; font-weight: 400; color: var(--text-light); text-transform: uppercase; letter-spacing: 1px; }
    .promo-expiry { font-size: 0.7rem; color: var(--text-light); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
    .promo-glow { position: absolute; bottom: -15px; left: 50%; transform: translateX(-50%); width: 120%; height: 20px; background: var(--primary); opacity: 0.05; filter: blur(10px); }

    /* Points-cost badge + button (new) */
    .promo-cost { display: inline-block; margin-top: 14px; padding: 6px 14px; border-radius: 20px; font-size: 0.7rem; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; background: rgba(123, 121, 242, 0.12); color: #7B79F2; }
    .promo-cost.promo-cost-off { background: rgba(150, 150, 150, 0.15); color: #888; }
    .promo-setcost { display: block; margin: 12px auto 0; background: none; border: 1px solid var(--border-color); color: var(--text-light); border-radius: 12px; padding: 6px 14px; font-size: 0.65rem; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; cursor: pointer; transition: 0.2s; }
    .promo-setcost:hover { border-color: #7B79F2; color: #7B79F2; }

    @media (max-width: 800px) {
        .promo-form-grid, .loyalty-form-grid { grid-template-columns: 1fr; }
        .promo-form-grid .span-all, .loyalty-form-grid .span-all { grid-column: auto; }
    }

    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>. */
    html[data-theme="dark"] :is(.promo-hint, .loyalty-sub, .promos-loading, .promos-empty, .promo-delete) { color: var(--text-light); }
    html[data-theme="dark"] .promo-delete:hover { color: var(--primary); }
    html[data-theme="dark"] :is(.loyalty-card, .promo-card) { box-shadow: none; }
    html[data-theme="dark"] .loyalty-save-btn { box-shadow: none; }
    html[data-theme="dark"] .promo-glow { opacity: 0.12; }
    html[data-theme="dark"] .promo-input { color-scheme: dark; }
    html[data-theme="dark"] .promos-content .shadow-pink-100 { box-shadow: none; }

    /* Tailwind class remap, scoped to this page */
    html[data-theme="dark"] .promos-content :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] .promos-content :is(.text-gray-500, .text-gray-400, .text-gray-300) { color: var(--text-light); }
</style>

<main class="pos-content promos-content" style="padding: 1.5rem; max-width: 1400px; margin: 0 auto;">
    <div class="flex justify-between items-center mb-12">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Promos & Discounts</h1>
            <p class="text-gray-400 text-sm font-medium mt-1">Vouchers created here appear in the customer app's Rewards Catalog, redeemable with Bloom Points.</p>
        </div>
        <button onclick="document.getElementById('promoform').style.display='block'" class="btn-primary hover:scale-105 active:scale-95 transition-all flex items-center px-8 shadow-lg shadow-pink-100 uppercase tracking-widest text-xs font-black">
            <i class="fa-solid fa-plus mr-3"></i> Create New Promo
        </button>
    </div>

    <div id="promoform" class="promo-form-card">
        <h4 class="brand-font text-2xl font-black mb-8 text-gray-800">Create New Promo Code</h4>
        <form id="addPromoForm" class="promo-form-grid">
            <div>
                <label class="promo-label" for="promoCode">Spectral Code</label>
                <input type="text" id="promoCode" class="promo-input" placeholder="CODE (e.g. BLOOM20)" required>
                <p class="promo-hint">Internal only. Customers never see this code.</p>
            </div>
            <div>
                <label class="promo-label" for="discount">Percentage Ratio (%)</label>
                <input type="number" id="discount" class="promo-input" placeholder="Discount %" min="1" max="100" required>
            </div>
            <div>
                <label class="promo-label" for="pointsCost">Points Cost</label>
                <input type="number" id="pointsCost" class="promo-input" placeholder="e.g. 100" min="0" required>
                <p class="promo-hint">Bloom Points needed to redeem it. Use 0 to keep it out of the app catalog.</p>
            </div>
            <div>
                <label class="promo-label" for="expiry">Sunset Date</label>
                <input type="date" id="expiry" class="promo-input" required>
            </div>
            <button type="submit" id="saveBtn" class="btn-primary span-all" style="padding: 20px; text-transform: uppercase; letter-spacing: 2px; font-size: 0.7rem;">Authorize Protocol</button>
        </form>
    </div>

    <!-- Loyalty Points Rules Configuration -->
    <div class="loyalty-card">
        <div class="loyalty-head">
            <div class="loyalty-icon">
                <i class="fa-solid fa-star-half-stroke" style="font-size: 1.2rem;"></i>
            </div>
            <div>
                <h4 class="brand-font text-2xl font-black text-gray-800" style="font-size: 1.5rem; font-weight: 800; margin: 0;">Loyalty Points Authority Rules</h4>
                <p class="loyalty-sub font-medium">Controls how customers EARN Bloom Points. Customers spend them on the vouchers below.</p>
            </div>
        </div>
        <form id="loyaltyRulesForm" class="loyalty-form-grid">
            <div>
                <label class="promo-label" for="maxProductsEligible">Max Eligible Products Limit</label>
                <input type="number" id="maxProductsEligible" class="promo-input" placeholder="e.g. 5" required>
                <p class="promo-hint">The maximum number of flowers/products purchased to trigger reward points.</p>
            </div>
            <div>
                <label class="promo-label" for="pointsPerMaxPurchase">Points Gained on Max Purchase</label>
                <input type="number" id="pointsPerMaxPurchase" class="promo-input" placeholder="e.g. 50" required>
                <p class="promo-hint">The specific points given to the customer once they reach this item limit.</p>
            </div>
            <button type="submit" id="saveLoyaltyBtn" class="btn-primary loyalty-save-btn span-all">Save Loyalty Configuration</button>
        </form>
    </div>

    <div id="promosList" class="promos-grid">
        <div class="promos-status promos-loading">Retrieving privilege records...</div>
    </div>
</main>

<script>
    // Single source for each button's resting label, so the text shown
    // before AND after an action can never drift apart.
    const PROMO_BTN_LABEL = 'Authorize Protocol';
    const LOYALTY_BTN_LABEL = 'Save Loyalty Configuration';

    document.addEventListener('DOMContentLoaded', () => {
        const promosList = document.getElementById('promosList');

        // Real-time listener for promos
        db.collection('promos').orderBy('created_at', 'desc').onSnapshot(snap => {
            if (snap.empty) {
                promosList.innerHTML = `
                    <div class="promos-status promos-empty">
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

                // Older promos have no points_cost, so treat them as 0 (not in the catalog).
                const cost = Number(p.points_cost) || 0;
                const costBadge = cost > 0
                    ? `<div class="promo-cost">${cost} pts in app catalog</div>`
                    : `<div class="promo-cost promo-cost-off">Not in app catalog</div>`;

                html += `
                <div class="promo-card">
                    <button onclick="deletePromo('${id}')" class="promo-delete" title="Delete voucher">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                    <div class="promo-eyebrow">Voucher Asset · Code hidden from customers</div>
                    <div class="brand-font promo-code">${p.promo_code}</div>
                    <div class="brand-font promo-amount">
                        <span>${p.discount_percentage}%</span>
                        <small>Reduction</small>
                    </div>
                    <div class="promo-expiry">Expiring: ${expiry}</div>
                    ${costBadge}
                    <button onclick="setPromoCost('${id}', ${cost})" class="promo-setcost">Set Points Cost</button>
                    <div class="promo-glow"></div>
                </div>
                `;
            });
            promosList.innerHTML = html;
        });

        // Load Loyalty Point rules configuration in real-time
        db.collection('settings').doc('loyalty_config').onSnapshot(doc => {
            if (doc.exists) {
                const data = doc.data();
                document.getElementById('maxProductsEligible').value = data.max_products_eligible || 5;
                document.getElementById('pointsPerMaxPurchase').value = data.points_per_max_purchase || 50;
            } else {
                // Initialize default config if missing
                db.collection('settings').doc('loyalty_config').set({
                    max_products_eligible: 5,
                    points_per_max_purchase: 50,
                    updated_at: firebase.firestore.FieldValue.serverTimestamp()
                });
            }
        });

        // Save Loyalty Point rules configuration
        document.getElementById('loyaltyRulesForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('saveLoyaltyBtn');
            btn.disabled = true;
            btn.innerText = 'Saving Configuration...';

            try {
                await db.collection('settings').doc('loyalty_config').set({
                    max_products_eligible: parseInt(document.getElementById('maxProductsEligible').value),
                    points_per_max_purchase: parseInt(document.getElementById('pointsPerMaxPurchase').value),
                    updated_at: firebase.firestore.FieldValue.serverTimestamp()
                }, { merge: true });
                alert('Loyalty Rules Updated Successfully!');
            } catch (err) {
                alert('Error saving config: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = LOYALTY_BTN_LABEL;
            }
        };

        document.getElementById('addPromoForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('saveBtn');
            btn.disabled = true;
            btn.innerText = 'Creating...';

            try {
                const code = document.getElementById('promoCode').value.toUpperCase();
                const discount = parseInt(document.getElementById('discount').value);
                const pointsCost = parseInt(document.getElementById('pointsCost').value);

                // Validate numbers before they ever reach the database
                if (!(discount >= 1 && discount <= 100)) {
                    alert('Discount must be between 1 and 100.');
                    return; // the finally block below resets the button
                }
                if (!(pointsCost >= 0)) {
                    alert('Points cost must be 0 or higher.');
                    return;
                }
                
                // Check if code exists
                const check = await db.collection('promos').where('promo_code', '==', code).get();
                if (!check.empty) {
                    alert('Promo code already exists!');
                    return; // the finally block below resets the button
                }

                await db.collection('promos').add({
                    promo_code: code,
                    discount_percentage: discount,
                    points_cost: pointsCost,
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
                btn.innerText = PROMO_BTN_LABEL;
            }
        };
    });

    // Lets staff add or change the points cost of an existing promo.
    // Entering 0 removes it from the app catalog.
    async function setPromoCost(id, currentCost) {
        const input = prompt('Points cost for this voucher (0 = hide from app catalog):', currentCost);
        if (input === null) return; // staff pressed Cancel

        const newCost = parseInt(input);
        if (isNaN(newCost) || newCost < 0) {
            alert('Please enter a whole number, 0 or higher.');
            return;
        }

        try {
            await db.collection('promos').doc(id).update({ points_cost: newCost });
        } catch (err) {
            alert('Error: ' + err.message);
        }
    }

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