<?php 
/**
 * BLOOMINOUS - Product Management (Firebase Spoke)
 *
 * ONE table for every view:
 *  - A normal branch shows that branch's inventory.
 *  - Main Branch (main_branch) is the COMBINED view: it listens to every
 *    REAL branch's inventory and merges copies of the same product (same
 *    product code, or same document id) into one row, summing their stock
 *    and showing a per-branch breakdown.
 *  - The old inventory stored directly under main_branch is NOT shown.
 *    A banner lets admins archive those legacy items (soft archive).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php'; 
?>

<!-- JSBarcode Library -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<style>
    .main-content-area { padding: 1.5rem; margin: 0 auto; max-width: 1400px; }
    .inventory-card { background: var(--surface); border-radius: 30px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid var(--border-color); }
    .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; gap: 1rem; flex-wrap: wrap; }

    .view-pill { display: inline-flex; align-items: center; gap: 8px; margin-top: 10px; padding: 6px 14px; border-radius: 50px; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); color: var(--primary); font-size: 0.65rem; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; }

    /* Legacy cleanup banner (Main Branch only) */
    .legacy-banner { display: none; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 1.5rem; padding: 16px 20px; border-radius: 18px; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); }
    .legacy-banner p { margin: 0; font-size: 0.8rem; font-weight: 600; color: var(--text-main); line-height: 1.5; }
    .legacy-banner strong { color: var(--primary); }
    .legacy-btn { background: var(--primary); color: #fff; border: none; padding: 10px 18px; border-radius: 12px; font-weight: 800; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; cursor: pointer; white-space: nowrap; }
    .legacy-btn:hover { opacity: 0.9; }
    .legacy-btn:disabled { opacity: 0.6; cursor: not-allowed; }

    table { width: 100%; border-collapse: collapse; }
    table th { text-align: left; padding: 16px; color: var(--text-light); font-size: 0.75rem; text-transform: uppercase; border-bottom: 1px solid var(--border-color); font-weight: 800; letter-spacing: 1px; white-space: nowrap; }
    table td { padding: 14px 16px; border-bottom: 1px solid #f8f9fa; color: var(--text-main); font-size: 0.9rem; font-weight: 500; vertical-align: middle; }

    .col-scan { width: 170px; }
    .col-ops { white-space: nowrap; }

    .product-img { width: 50px; height: 50px; border-radius: 15px; object-fit: cover; box-shadow: 0 5px 10px rgba(0,0,0,0.05); background: var(--surface-alt); display: block; }
    .status-badge { padding: 6px 12px; border-radius: 50px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
    .in-stock { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
    .low-stock { background: rgba(255, 177, 66, 0.1); color: #f39c12; }
    .out-stock { background: rgba(233, 30, 99, 0.1); color: var(--primary); }

    .product-name { font-weight: 700; color: var(--text-main); font-size: 0.95rem; }
    .branch-line { margin-top: 6px; font-size: 0.68rem; font-weight: 700; color: var(--text-light); line-height: 1.5; }
    .branch-line b { color: var(--primary); font-weight: 800; }

    /* The barcode: capped size so it can never stretch the row. */
    .barcode { display: block; max-width: 150px; height: 38px; border-radius: 6px; }
    .no-code { display: inline-block; padding: 5px 12px; border-radius: 50px; border: 1px dashed var(--border-color); color: var(--text-light); font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
    .code-text { display: block; margin-top: 4px; font-family: 'Courier New', monospace; font-size: 0.65rem; font-weight: 800; letter-spacing: 1px; color: var(--text-light); }

    .action-icons a { width: 35px; height: 35px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; margin-right: 5px; transition: 0.2s; }
    .edit-icon { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .edit-icon:hover { background: var(--secondary); color: white; transform: translateY(-2px); }
    .archive-icon { background: rgba(52, 152, 219, 0.1); color: #2980b9; }
    .archive-icon:hover { background: #2980b9; color: white; transform: translateY(-2px); }
    .spoil-icon { background: rgba(255, 177, 66, 0.1); color: #f39c12; }
    .spoil-icon:hover { background: #f39c12; color: white; transform: translateY(-2px); }

    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>. */
    html[data-theme="dark"] table td { border-bottom-color: var(--border-color); }
    html[data-theme="dark"] .inventory-card { box-shadow: none; }
    html[data-theme="dark"] .archive-icon { background: rgba(52, 152, 219, 0.15); color: #7cc4f5; }
    html[data-theme="dark"] .main-content-area .shadow-pink-100 { box-shadow: none; }
    html[data-theme="dark"] .main-content-area :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] .main-content-area :is(.text-gray-500, .text-gray-400, .text-gray-300) { color: var(--text-light); }
</style>

<div class="main-content-area">
    <div class="header-flex">
        <div>
            <h2 class="brand-font text-4xl font-black text-gray-800">Product Inventory</h2>
            <p class="text-gray-400 text-sm font-medium mt-1">Real-time stock monitoring and replenishment.</p>
            <span id="viewPill" class="view-pill" style="display:none;"></span>
        </div>
        <a href="add_product.php" class="btn-primary shadow-lg shadow-pink-100">
            <i class="fas fa-plus-circle"></i> <span>Catalog Item</span>
        </a>
    </div>

    <div id="legacyBanner" class="legacy-banner">
        <p><strong id="legacyCount">0</strong> old item(s) are still stored directly under Main Branch. They are not shown here, because Main Branch now combines the real branches. Archive them to clean up.</p>
        <button type="button" id="legacyBtn" class="legacy-btn">Archive legacy items</button>
    </div>

    <div class="inventory-card">
        <div class="overflow-x-auto">
            <table>
                <thead>
                    <tr>
                        <th>Artifact</th>
                        <th>Identity</th>
                        <th class="col-scan">Scan Code</th>
                        <th>Classification</th>
                        <th>Valuation</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th class="col-ops" style="text-align: right;">Operations</th>
                    </tr>
                </thead>
                <tbody id="product-list">
                    <tr><td colspan='8' style='text-align:center; padding: 60px;' class='text-gray-300 font-medium italic'>Synchronizing inventory records...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // ---------- Settings ----------
    const MAIN_BRANCH_ID = 'main_branch';
    const IS_MAIN_VIEW = window.currentBranch === MAIN_BRANCH_ID;
    const IS_ADMIN = ['admin', 'super-admin'].includes(window.currentUserRole);
    const LOW_STOCK_LIMIT = 10;
    // Built-in placeholder (no external website needed).
    const PLACEHOLDER_IMG = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100%" height="100%" rx="20" fill="#cfc8bb"/><text x="50%" y="62%" font-size="44" text-anchor="middle">🌸</text></svg>'
    );

    // ---------- Page state ----------
    const docsByBranch = new Map();   // branchId -> [{ docId, data }]
    let branchNames = {};             // branchId -> display name
    let currentItems = [];            // the rows currently on screen
    let legacyDocs = [];              // active docs still sitting under main_branch

    const productList = document.getElementById('product-list');

    // ---------- Helpers ----------
    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function isArchived(data) {
        return data.isDeleted === true || data.status === 'archived';
    }

    function branchLabel(id) {
        if (id === MAIN_BRANCH_ID) return 'Main Branch';
        return branchNames[id] || id;
    }

    function branchInventory(branchId) {
        return db.collection('branches').doc(branchId).collection('inventory');
    }

    // ---------- Step 1: turn raw documents into table rows ----------
    // Normal branch: one row per document.
    // Main Branch: copies of one product become ONE row. The match is the
    // product code, or the document id for products without a code (the Add
    // Product page uses the same id in every branch).
    function buildItems() {
        const merged = new Map();

        docsByBranch.forEach((docs, branchId) => {
            docs.forEach(({ docId, data }) => {
                const code = String(data.code || '').trim().toUpperCase();
                let key;
                if (!IS_MAIN_VIEW) key = 'id:' + branchId + ':' + docId;
                else key = code ? 'code:' + code : 'id:' + docId;

                let item = merged.get(key);
                if (!item) {
                    item = { key, name: '', code, category: '', price: 0, stock: 0, image: '', availableBranchIds: [], branches: [] };
                    merged.set(key, item);
                }

                const stock = parseInt(data.stock || 0, 10) || 0;
                item.name = item.name || data.name || '';
                item.category = item.category || data.category || '';
                item.price = item.price || parseFloat(data.price || 0) || 0;
                item.image = item.image || data.image || '';
                if (Array.isArray(data.availableBranchIds) && data.availableBranchIds.length > item.availableBranchIds.length) {
                    item.availableBranchIds = data.availableBranchIds;
                }
                item.stock += stock;
                item.branches.push({ branchId, docId, stock });
            });
        });

        return Array.from(merged.values())
            .sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    }

    // ---------- Step 2: draw the rows ----------
    function rowHtml(item, index) {
        const stock = item.stock;
        let statusClass = 'in-stock';
        let statusText = 'Stable';
        if (stock <= 0) { statusClass = 'out-stock'; statusText = 'Depleted'; }
        else if (stock <= LOW_STOCK_LIMIT) { statusClass = 'low-stock'; statusText = 'Critical'; }

        // Small line under the name: where this product is available.
        let branchLine = '';
        if (IS_MAIN_VIEW) {
            const parts = item.branches
                .slice().sort((a, b) => branchLabel(a.branchId).localeCompare(branchLabel(b.branchId)))
                .map(b => `${escapeHtml(branchLabel(b.branchId))} <b>${b.stock}</b>`);
            branchLine = `<div class="branch-line">${parts.join(' &middot; ')}</div>`;
        } else if (item.availableBranchIds.length > 1) {
            const others = item.availableBranchIds
                .filter(id => id !== window.currentBranch)
                .map(id => escapeHtml(branchLabel(id)));
            if (others.length) {
                branchLine = `<div class="branch-line">Also in: ${others.join(', ')}</div>`;
            }
        }

        // Scan code: a barcode only when a code exists, otherwise a quiet tag.
        const scanCell = item.code
            ? `<svg id="barcode-${index}" class="barcode"></svg><span class="code-text">${escapeHtml(item.code)}</span>`
            : `<span class="no-code">No code</span>`;

        return `
        <tr>
            <td><img src="${escapeHtml(item.image || PLACEHOLDER_IMG)}" class="product-img" alt="" data-fallback="1"></td>
            <td>
                <div class="product-name">${escapeHtml(item.name || 'Unnamed')}</div>
                ${branchLine}
            </td>
            <td class="col-scan">${scanCell}</td>
            <td><span style="font-size: 0.8rem; font-weight: 600; color: var(--text-light);">${escapeHtml(item.category || 'Standard')}</span></td>
            <td style="font-weight: 800; color: var(--primary); white-space: nowrap;">₱${item.price.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
            <td style="font-weight: 700; white-space: nowrap;">${stock} units</td>
            <td><span class="status-badge ${statusClass}">${statusText}</span></td>
            <td class="action-icons col-ops" style="text-align: right;">
                <a href="#" data-action="edit" data-index="${index}" class="edit-icon" title="Edit Master Data"><i class="fas fa-pen-nib"></i></a>
                <a href="#" data-action="spoil" data-index="${index}" class="spoil-icon" title="Log Spoilage Incident"><i class="fas fa-dumpster"></i></a>
                <a href="#" data-action="archive" data-index="${index}" class="archive-icon" title="Deactivate & Archive Item"><i class="fas fa-box-archive"></i></a>
            </td>
        </tr>`;
    }

    function render() {
        currentItems = buildItems();

        if (currentItems.length === 0) {
            productList.innerHTML = "<tr><td colspan='8' style='text-align:center; padding: 60px;' class='text-gray-300 font-medium'>No inventory units mapped to this branch.</td></tr>";
            return;
        }

        productList.innerHTML = currentItems.map(rowHtml).join('');

        // Broken photo links fall back to the built-in placeholder.
        productList.querySelectorAll('img[data-fallback]').forEach(img => {
            img.addEventListener('error', () => { img.src = PLACEHOLDER_IMG; }, { once: true });
        });

        // Draw barcodes AFTER the rows exist in the page.
        currentItems.forEach((item, index) => {
            if (!item.code) return;
            try {
                JsBarcode(`#barcode-${index}`, item.code, {
                    format: "CODE128",
                    height: 20,
                    width: 1,
                    displayValue: false,
                    background: "#ffffff",
                    lineColor: "#000000",
                    margin: 6
                });
            } catch (e) {
                // A code the barcode format can't draw falls back to text only.
                const svg = document.getElementById(`barcode-${index}`);
                if (svg) svg.remove();
            }
        });
    }

    // ---------- Step 3: listen to Firestore ----------
    function listenToBranch(branchId) {
        return branchInventory(branchId).onSnapshot(snap => {
            docsByBranch.set(
                branchId,
                snap.docs
                    .filter(doc => !isArchived(doc.data()))
                    .map(doc => ({ docId: doc.id, data: doc.data() }))
            );
            render();
        }, err => {
            console.error('Inventory listener error (' + branchId + '):', err);
            productList.innerHTML = `<tr><td colspan='8' style='text-align:center; padding: 40px; color:#DC2626; font-weight:700;'>Could not load inventory: ${escapeHtml(err.message)}</td></tr>`;
        });
    }

    // Counts old items still stored directly under main_branch (NOT shown in the table).
    async function checkLegacyItems() {
        if (!IS_ADMIN) return;
        try {
            const snap = await branchInventory(MAIN_BRANCH_ID).get();
            legacyDocs = snap.docs.filter(doc => !isArchived(doc.data()));
            if (legacyDocs.length > 0) {
                document.getElementById('legacyCount').textContent = legacyDocs.length;
                document.getElementById('legacyBanner').style.display = 'flex';
            }
        } catch (err) {
            console.warn('Could not check legacy main_branch items:', err);
        }
    }

    async function startListening() {
        const pill = document.getElementById('viewPill');

        if (!IS_MAIN_VIEW) {
            listenToBranch(window.currentBranch);
            return;
        }

        pill.innerHTML = '<i class="fa-solid fa-layer-group"></i> Combined view of all branches';
        pill.style.display = 'inline-flex';

        try {
            const branchSnap = await db.collection('branches').get();
            const ids = [];
            branchSnap.forEach(doc => {
                branchNames[doc.id] = doc.data().name || doc.id;
                if (doc.id !== MAIN_BRANCH_ID) ids.push(doc.id); // real branches only
            });

            if (ids.length === 0) {
                productList.innerHTML = "<tr><td colspan='8' style='text-align:center; padding: 60px;' class='text-gray-300 font-medium'>No branches found. Create a branch first.</td></tr>";
            }
            ids.forEach(listenToBranch);
            checkLegacyItems();
        } catch (err) {
            console.error('Could not load branches:', err);
            productList.innerHTML = `<tr><td colspan='8' style='text-align:center; padding: 40px; color:#DC2626; font-weight:700;'>Could not load branches: ${escapeHtml(err.message)}</td></tr>`;
        }
    }

    // Wait until Firebase has restored the login before reading Firestore.
    let started = false;
    firebase.auth().onAuthStateChanged(user => {
        if (user && !started) {
            started = true;
            startListening();
        }
    });

    // ---------- Step 4: actions ----------
    // Edit and Spoilage pages work on ONE branch at a time. In the combined
    // view we switch to the branch that holds the product, then open the page.
    function goToBranchPage(branchId, url) {
        if (branchId !== window.currentBranch) {
            localStorage.setItem('bloom_branch_id', branchId);
        }
        window.location.href = url;
    }

    async function archiveItem(item) {
        const where = item.branches.map(b => branchLabel(b.branchId)).join(', ');
        const extra = item.branches.length > 1
            ? `\n\nThis will archive it in ALL of these branches: ${where}.`
            : '';
        if (!confirm(`Archive "${item.name || 'this product'}"? It will be hidden from active inventory and selling screens, but its history stays for sales reports.${extra}`)) {
            return;
        }

        try {
            const batch = db.batch();
            item.branches.forEach(b => {
                batch.update(branchInventory(b.branchId).doc(b.docId), {
                    isDeleted: true,
                    status: 'archived',
                    archivedAt: firebase.firestore.FieldValue.serverTimestamp()
                });
            });
            await batch.commit();

            // Old global inventory copy (best-effort, may not exist).
            for (const b of item.branches) {
                try {
                    await db.collection('inventory').doc(b.docId).update({
                        isDeleted: true,
                        status: 'archived',
                        archivedAt: firebase.firestore.FieldValue.serverTimestamp()
                    });
                } catch (err) {
                    console.log('Global doc archive skipped: ', err.message);
                }
            }

            // Accountability record: same action name as the Flutter app.
            try {
                await db.collection('admin_actions').add({
                    actorUid: firebase.auth().currentUser ? firebase.auth().currentUser.uid : null,
                    actorEmail: window.currentUserEmail || null,
                    actorRole: window.currentUserRole || null,
                    action: 'inventory_item_archived',
                    targetUid: item.branches[0].docId,
                    targetEmail: null,
                    details: `Archived "${item.name || 'Unnamed'}"${item.code ? ' (' + item.code + ')' : ''} in: ${where}.`,
                    timestamp: firebase.firestore.FieldValue.serverTimestamp()
                });
            } catch (auditError) {
                console.warn('Audit log write failed (product still archived):', auditError);
            }
        } catch (e) {
            alert('Error archiving product: ' + e.message);
        }
    }

    // Archives the old items stored directly under main_branch (soft archive).
    async function archiveLegacyItems() {
        if (legacyDocs.length === 0) return;
        const names = legacyDocs.map(d => d.data().name || 'Unnamed').join(', ');
        if (!confirm(`Archive ${legacyDocs.length} old item(s) stored under Main Branch?\n\n${names}\n\nThey will be hidden, not deleted. Make sure these products already exist in your real branches first.`)) {
            return;
        }

        const btn = document.getElementById('legacyBtn');
        btn.disabled = true;
        btn.textContent = 'Archiving...';

        try {
            const batch = db.batch();
            legacyDocs.forEach(doc => {
                batch.update(doc.ref, {
                    isDeleted: true,
                    status: 'archived',
                    archivedAt: firebase.firestore.FieldValue.serverTimestamp()
                });
            });
            await batch.commit();

            try {
                await db.collection('admin_actions').add({
                    actorUid: firebase.auth().currentUser ? firebase.auth().currentUser.uid : null,
                    actorEmail: window.currentUserEmail || null,
                    actorRole: window.currentUserRole || null,
                    action: 'inventory_item_archived',
                    targetUid: MAIN_BRANCH_ID,
                    targetEmail: null,
                    details: `Archived ${legacyDocs.length} legacy Main Branch item(s): ${names}.`,
                    timestamp: firebase.firestore.FieldValue.serverTimestamp()
                });
            } catch (auditError) {
                console.warn('Audit log write failed (items still archived):', auditError);
            }

            legacyDocs = [];
            document.getElementById('legacyBanner').style.display = 'none';
        } catch (err) {
            alert('Could not archive legacy items: ' + err.message);
            btn.disabled = false;
            btn.textContent = 'Archive legacy items';
        }
    }
    document.getElementById('legacyBtn').addEventListener('click', archiveLegacyItems);

    // One click listener for the whole table (rows are redrawn often).
    productList.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action]');
        if (!button) return;
        e.preventDefault();

        const item = currentItems[Number(button.dataset.index)];
        if (!item || item.branches.length === 0) return;
        const target = item.branches[0]; // the branch we switch to for Edit / Spoilage

        if (button.dataset.action === 'edit') {
            goToBranchPage(target.branchId, 'edit_product.php?id=' + encodeURIComponent(target.docId));
        } else if (button.dataset.action === 'spoil') {
            goToBranchPage(target.branchId, 'spoilage_tracking.php?productId=' + encodeURIComponent(target.docId));
        } else if (button.dataset.action === 'archive') {
            archiveItem(item);
        }
    });
</script>

<?php include 'templates/footer.php'; ?>