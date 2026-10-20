<?php 
/**
 * BLOOMINOUS - AI Stock Alerts (Firebase Spoke)
 *
 * Lists products at or below the low-stock threshold, lets staff add stock,
 * and asks Gemini (through ai_restock_suggestion.php) what to reorder.
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
    .alerts-content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
    .alerts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
    .alert-stat { background: var(--surface); border-radius: 30px; padding: 2rem; border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 8px; }
    .alert-label { font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1px; }
    .alert-number { font-family: 'Cormorant Garamond', serif; font-size: 2.6rem; font-weight: 800; color: var(--text-main); line-height: 1; }

    .alert-layout { display: grid; grid-template-columns: 1fr; gap: 2rem; }
    @media (min-width: 1024px) { .alert-layout { grid-template-columns: 2fr 1fr; } }

    .alert-list { background: var(--surface); border-radius: 35px; border: 1px solid var(--border-color); overflow: hidden; }
    .alert-list-head { padding: 1.5rem 2rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
    .alert-row { display: flex; align-items: center; gap: 18px; padding: 1.25rem 2rem; border-bottom: 1px solid var(--border-color); flex-wrap: wrap; }
    .alert-row:last-child { border-bottom: none; }
    .alert-img { width: 56px; height: 56px; border-radius: 14px; object-fit: cover; background: var(--surface-alt); flex-shrink: 0; }
    .alert-info { flex: 1; min-width: 160px; }
    .alert-name { font-weight: 800; color: var(--text-main); font-size: 1rem; }
    .alert-meta { font-size: 0.75rem; color: var(--text-light); font-weight: 600; margin-top: 2px; }
    .alert-bar { height: 6px; border-radius: 50px; background: var(--border-color); overflow: hidden; margin-top: 8px; max-width: 220px; }
    .alert-bar > div { height: 100%; border-radius: 50px; }
    .level-pill { font-size: 0.62rem; font-weight: 900; padding: 3px 10px; border-radius: 50px; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 8px; }
    .lvl-out  { background: rgba(233, 30, 99, 0.12); color: #e91e63; }
    .lvl-crit { background: rgba(255, 87, 34, 0.12); color: #ff5722; }
    .lvl-low  { background: rgba(245, 158, 11, 0.14); color: #f59e0b; }

    .restock-box { display: flex; gap: 8px; align-items: center; }
    .restock-box input { width: 84px; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--border-color); background: var(--surface-alt); color: var(--text-main); font-size: 0.85rem; font-weight: 600; outline: none; }
    .restock-box input:focus { border-color: var(--primary); }
    .btn-restock { background: var(--primary); color: #121212; font-weight: 900; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; padding: 11px 18px; border-radius: 12px; border: none; cursor: pointer; transition: 0.2s; }
    .btn-restock:hover { filter: brightness(0.95); }
    .btn-restock:disabled { opacity: 0.5; cursor: not-allowed; }

    .ai-panel { background: linear-gradient(135deg, #FCD34D 0%, #F59E0B 55%, #B45309 100%); color: #fff; border-radius: 35px; padding: 2.2rem; align-self: start; }
    .ai-result { background: var(--surface); color: var(--text-main); border-radius: 18px; padding: 16px; margin-top: 16px; font-size: 0.85rem; line-height: 1.6; white-space: pre-wrap; }
    .ai-source { display: block; font-size: 0.65rem; font-style: italic; color: var(--text-light); margin-top: 10px; }
    .btn-ai { width: 100%; margin-top: 16px; background: #121212; color: #fff; font-weight: 900; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; padding: 15px; border-radius: 16px; border: none; cursor: pointer; }
    .btn-ai:disabled { opacity: 0.6; cursor: wait; }
    .alert-empty { text-align: center; padding: 60px 20px; color: var(--text-light); }

    .threshold-select { padding: 8px 12px; border-radius: 12px; border: 1px solid var(--border-color); background: var(--surface-alt); color: var(--text-main); font-size: 0.8rem; font-weight: 600; }
</style>

<main class="alerts-content">
    <div class="mb-10">
        <h1 class="brand-font text-5xl font-black" style="color: var(--text-main);">AI Stock Alerts</h1>
        <p class="text-sm font-medium mt-1" style="color: var(--text-light);">Products running low, with restocking advice from Gemini.</p>
    </div>

    <div class="alerts-grid">
        <div class="alert-stat" style="border-top: 5px solid #f59e0b;">
            <div class="alert-label">Low Stock Items</div>
            <div class="alert-number" id="statLow">0</div>
        </div>
        <div class="alert-stat" style="border-top: 5px solid #e91e63;">
            <div class="alert-label">Out of Stock</div>
            <div class="alert-number" id="statOut">0</div>
        </div>
    </div>

    <div class="alert-layout">
        <div class="alert-list">
            <div class="alert-list-head">
                <h5 class="brand-font text-2xl font-black" style="color: var(--text-main); margin: 0;">Needs Restocking</h5>
                <label class="alert-label" style="display:flex; align-items:center; gap:8px; margin:0;">
                    Alert at
                    <select id="thresholdSelect" class="threshold-select">
                        <option value="5">5 or less</option>
                        <option value="10" selected>10 or less</option>
                        <option value="20">20 or less</option>
                    </select>
                </label>
            </div>
            <div id="alertRows">
                <div class="alert-empty">Loading inventory...</div>
            </div>
        </div>

        <div class="ai-panel">
            <i class="fa-solid fa-brain" style="font-size: 2.4rem; opacity: 0.35; margin-bottom: 14px; display:block;"></i>
            <h5 class="brand-font" style="font-size: 1.9rem; font-weight: 900; line-height: 1.1; margin: 0 0 8px 0;">Smart Restocking</h5>
            <p style="font-size: 0.8rem; opacity: 0.85; margin: 0;">Gemini reviews your low-stock items and suggests what to order and how many.</p>
            <div id="aiResult" class="ai-result" style="display:none;"></div>
            <button id="aiBtn" class="btn-ai" onclick="getAiSuggestion()">
                <i class="fa-solid fa-wand-magic-sparkles mr-2"></i> Get AI Restock Suggestion
            </button>
        </div>
    </div>
</main>

<script>
    let lowItems = [];      // products at or below the chosen threshold, lowest stock first
    let allInventory = [];  // every active product in this branch
    let busy = new Set();   // product ids currently being restocked

    // Escapes text before it goes into innerHTML, so a product name like
    // <img onerror=...> can never run as code.
    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function currentThreshold() {
        return parseInt(document.getElementById('thresholdSelect').value, 10) || 10;
    }

    document.addEventListener('DOMContentLoaded', () => {
        // Live listener: any sale, restock or edit updates this page by itself.
        getBranchPath('inventory').onSnapshot(snap => {
            allInventory = [];
            snap.forEach(doc => {
                const p = doc.data();
                if (p.isDeleted === true || p.status === 'archived') return;
                allInventory.push({ id: doc.id, ...p });
            });
            recompute();
        }, err => {
            document.getElementById('alertRows').innerHTML =
                `<div class="alert-empty" style="color:#dc3545;">Could not load inventory: ${esc(err.message)}</div>`;
        });

        document.getElementById('thresholdSelect').addEventListener('change', recompute);

        // One listener for ALL restock buttons (event delegation): the rows
        // are re-drawn on every update, so per-button listeners would be lost.
        document.getElementById('alertRows').addEventListener('click', e => {
            const btn = e.target.closest('[data-restock-id]');
            if (btn) restock(btn.dataset.restockId);
        });
    });

    function recompute() {
        const limit = currentThreshold();
        lowItems = allInventory
            .filter(p => Number(p.stock || 0) <= limit)
            .sort((a, b) => Number(a.stock || 0) - Number(b.stock || 0));
        render(limit);
    }

    function render(limit) {
        const rows = document.getElementById('alertRows');
        document.getElementById('statLow').innerText = lowItems.length;
        document.getElementById('statOut').innerText = lowItems.filter(p => Number(p.stock || 0) <= 0).length;

        if (lowItems.length === 0) {
            rows.innerHTML = `
                <div class="alert-empty">
                    <i class="fa-solid fa-circle-check" style="font-size:3rem; color:#27ae60; opacity:0.6; margin-bottom:12px; display:block;"></i>
                    <p style="font-weight:800;">All stock levels are healthy!</p>
                </div>`;
            return;
        }

        // Keep whatever the user already typed in a quantity box across redraws.
        const typed = {};
        rows.querySelectorAll('input[data-qty-for]').forEach(i => { typed[i.dataset.qtyFor] = i.value; });

        rows.innerHTML = lowItems.map(p => {
            const stock = Number(p.stock || 0);
            const isOut = stock <= 0;
            const isCrit = stock <= 5;
            const level = isOut ? ['lvl-out', 'OUT OF STOCK', '#e91e63'] : (isCrit ? ['lvl-crit', 'CRITICAL', '#ff5722'] : ['lvl-low', 'LOW', '#f59e0b']);
            const pct = Math.max(0, Math.min(100, (stock / limit) * 100));
            const qty = typed[p.id] !== undefined ? typed[p.id] : '20';
            const img = p.image ? esc(p.image) : 'https://picsum.photos/seed/flower/100/100';
            return `
                <div class="alert-row">
                    <img class="alert-img" src="${img}" alt="" onerror="this.style.visibility='hidden'">
                    <div class="alert-info">
                        <div class="alert-name">${esc(p.name || 'Unknown')}</div>
                        <div class="alert-meta">
                            <span class="level-pill ${level[0]}">${level[1]}</span>${stock} left
                        </div>
                        <div class="alert-bar"><div style="width:${pct}%; background:${level[2]};"></div></div>
                    </div>
                    <div class="restock-box">
                        <input type="number" min="1" max="10000" data-qty-for="${esc(p.id)}" value="${esc(qty)}" aria-label="Quantity to add">
                        <button class="btn-restock" data-restock-id="${esc(p.id)}" ${busy.has(p.id) ? 'disabled' : ''}>Restock</button>
                    </div>
                </div>`;
        }).join('');
    }

    async function restock(productId) {
        const input = document.querySelector(`input[data-qty-for="${CSS.escape(productId)}"]`);
        const qty = parseInt(input ? input.value : '', 10);
        if (isNaN(qty) || qty <= 0 || qty > 10000) {
            alert('Enter a quantity between 1 and 10,000.');
            return;
        }
        const product = allInventory.find(p => p.id === productId);
        if (!product) return;

        busy.add(productId);
        render(currentThreshold());
        try {
            // increment() adds on the SERVER's current value, so two people
            // restocking at the same moment can't overwrite each other.
            await getBranchPath('inventory').doc(productId).update({
                stock: firebase.firestore.FieldValue.increment(qty),
                updatedAt: firebase.firestore.FieldValue.serverTimestamp()
            });
            // No manual refresh: onSnapshot fires and the row updates / disappears.
        } catch (err) {
            alert('Could not restock: ' + err.message);
        } finally {
            busy.delete(productId);
            render(currentThreshold());
        }
    }

    async function getAiSuggestion() {
        if (lowItems.length === 0) {
            alert('No low stock items to analyze!');
            return;
        }
        const btn = document.getElementById('aiBtn');
        const box = document.getElementById('aiResult');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Analyzing...';

        try {
            const res = await fetch('ai_restock_suggestion.php', {
                method: 'POST',
                credentials: 'same-origin',   // sends the staff login session cookie
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    items: lowItems.map(p => ({
                        name: p.name || 'Unknown',
                        stock: Number(p.stock || 0),
                        price: Number(p.price || 0),
                        category: p.category || 'Flowers'
                    }))
                })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                throw new Error(data.message || ('HTTP ' + res.status));
            }
            // textContent (not innerHTML): the AI's text is shown as plain text.
            box.style.display = 'block';
            box.textContent = data.text;
            const note = document.createElement('span');
            note.className = 'ai-source';
            note.textContent = data.source === 'gemini'
                ? 'Generated by Gemini'
                : 'Built-in estimate (Gemini is not configured or was unreachable)';
            box.appendChild(note);
        } catch (err) {
            alert('Could not get a suggestion: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles mr-2"></i> Get AI Restock Suggestion';
        }
    }
</script>

<?php include 'templates/footer.php'; ?>