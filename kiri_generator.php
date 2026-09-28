<?php
/**
 * BLOOMINOUS - 3D Realism Hub (Web)
 * Generates a 3D model for an EXISTING inventory product from an
 * uploaded reference photo, and saves the resulting .glb URL directly
 * onto that product -- the web equivalent of the mobile app's
 * "AUTO-GENERATE 3D" flow in inventory_page.dart.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php';
?>

<!-- model-viewer: the web equivalent of mobile's ModelViewer widget --
     renders and lets you rotate/AR-preview a .glb file directly. -->
<script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/3.5.0/model-viewer.min.js"></script>

<style>
    .kiri-content { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }
    .kiri-card { background: var(--surface); border-radius: 28px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid var(--border-color); }
    .kiri-label { font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 1.5px; }
    .kiri-select, .kiri-input { width: 100%; padding: 15px 18px; border: 1px solid var(--border-color); border-radius: 15px; outline: none; font-size: 0.9rem; background: var(--surface-alt); color: var(--text-main); font-weight: 600; }
    .kiri-dropzone { border: 2px dashed #e5e5e5; border-radius: 20px; padding: 40px 20px; text-align: center; cursor: pointer; transition: 0.3s; background: var(--surface-alt); }
    .kiri-dropzone:hover { border-color: var(--primary); background: #fff9f0; }
    .kiri-status { font-size: 0.8rem; color: var(--text-light); text-align: center; margin-top: 12px; font-weight: 600; }
    .kiri-history-item { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-radius: 16px; background: var(--surface-alt); margin-bottom: 8px; border: 1px solid var(--border-color); }
    .kiri-history-url { font-size: 0.7rem; color: #3b82f6; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* Side-by-side layout for the input card + preview card; stacks
       into one column below 900px. */
    .kiri-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
    @media (max-width: 900px) {
        .kiri-grid { grid-template-columns: 1fr; }
    }

    /* Preview box: white in light mode (as requested earlier), with a
       subtle border so an all-white .glb model still has a visible edge.
       In dark mode it follows the page surface instead (see below). */
    .kiri-preview-box {
        height: 420px;
        border-radius: 20px;
        overflow: hidden;
        background: var(--surface);
        border: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .kiri-preview-placeholder { color: #b0b0b0; text-align: center; font-size: 0.8rem; }
    .kiri-preview-placeholder i { color: #d0d0d0; }

    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>. */
    html[data-theme="dark"] .kiri-card { box-shadow: none; }
    html[data-theme="dark"] .kiri-dropzone { border-color: var(--border-color); }
    html[data-theme="dark"] .kiri-dropzone:hover { border-color: var(--primary); background: rgba(245, 158, 11, 0.08); }
    html[data-theme="dark"] .kiri-select { color-scheme: dark; }
    html[data-theme="dark"] .kiri-preview-box { background: var(--surface-alt); }
    html[data-theme="dark"] .kiri-preview-placeholder,
    html[data-theme="dark"] .kiri-preview-placeholder i { color: var(--text-light); }
    html[data-theme="dark"] .kiri-history-url { color: #93c5fd; }

    /* Tailwind class remap, scoped to this page's content */
    html[data-theme="dark"] .kiri-content :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] .kiri-content :is(.text-gray-500, .text-gray-400, .text-gray-300) { color: var(--text-light); }
</style>

<main class="kiri-content">
    <div class="mb-10">
        <h1 class="brand-font text-5xl font-black text-gray-800">3D Realism Hub</h1>
        <p class="text-gray-400 text-sm font-medium mt-1">Generate a realistic 3D model for an existing product from a reference photo.</p>
    </div>

    <div class="kiri-grid mb-8">
        <div class="kiri-card">
            <label class="kiri-label">1. Select Product from Inventory</label>
            <select id="productSelect" class="kiri-select mb-6">
                <option value="">Loading inventory...</option>
            </select>

            <label class="kiri-label">2. Upload Flower Reference Photo</label>
            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
            <div id="dropzone" class="kiri-dropzone mb-2" onclick="document.getElementById('photoInput').click()">
                <div id="dropzoneContent">
                    <i class="fa-solid fa-image text-3xl text-gray-300 mb-2"></i>
                    <p class="text-sm text-gray-500 font-semibold">Click to select a clear, well-lit photo of the flower</p>
                    <p class="text-xs text-gray-400 mt-1">Plain background works best. JPEG, PNG, or WEBP, max 10MB.</p>
                </div>
            </div>

            <button id="generateBtn" class="btn-primary w-full py-4 mt-6 text-sm uppercase tracking-widest" disabled>
                <i class="fa-solid fa-wand-magic-sparkles mr-2"></i> Generate 3D Model
            </button>
            <p id="statusText" class="kiri-status" style="display:none;"></p>
        </div>

        <div class="kiri-card">
            <label class="kiri-label">3D Model Preview</label>
            <div class="kiri-preview-box" id="previewBox">
                <div class="kiri-preview-placeholder">
                    <i class="fa-solid fa-cube text-3xl mb-2 block"></i>
                    Your generated model will appear here
                </div>
            </div>
        </div>
    </div>

    <div class="kiri-card">
        <label class="kiri-label">Generation History</label>
        <div id="historyList">
            <p class="text-xs text-gray-400 italic py-4 text-center">Loading history...</p>
        </div>
    </div>
</main>

<script>
    let selectedFile = null;
    let productMap = {};

    // --- Load inventory for the product dropdown, same source the
    // rest of the admin panel already uses. ---
    getBranchPath('inventory').onSnapshot(snap => {
        productMap = {};
        let options = '<option value="">-- Select a product --</option>';
        snap.forEach(doc => {
            const p = doc.data();
            if (p.isDeleted === true || p.status === 'archived') return;
            productMap[doc.id] = p;
            options += `<option value="${doc.id}">${p.name}${p.model ? ' (has 3D model)' : ''}</option>`;
        });
        document.getElementById('productSelect').innerHTML = options;
        updateGenerateButtonState();
    });

    document.getElementById('productSelect').addEventListener('change', updateGenerateButtonState);

    document.getElementById('photoInput').addEventListener('change', (e) => {
        selectedFile = e.target.files[0] || null;
        const dropzoneContent = document.getElementById('dropzoneContent');
        if (selectedFile) {
            const reader = new FileReader();
            reader.onload = (ev) => {
                dropzoneContent.innerHTML = `<img src="${ev.target.result}" style="max-height:160px; border-radius:12px; margin:0 auto;">`;
            };
            reader.readAsDataURL(selectedFile);
        }
        updateGenerateButtonState();
    });

    function updateGenerateButtonState() {
        const productId = document.getElementById('productSelect').value;
        document.getElementById('generateBtn').disabled = !(productId && selectedFile);
    }

    function setStatus(msg) {
        const el = document.getElementById('statusText');
        el.style.display = msg ? 'block' : 'none';
        el.innerText = msg;
    }

    function showPreview(glbUrl) {
        document.getElementById('previewBox').innerHTML = `
            <model-viewer src="${glbUrl}" alt="Generated 3D flower model" ar
                auto-rotate camera-controls style="width:100%; height:100%; background:transparent;">
            </model-viewer>
        `;
    }

    document.getElementById('generateBtn').addEventListener('click', async () => {
        const productId = document.getElementById('productSelect').value;
        const product = productMap[productId];
        if (!productId || !selectedFile || !product) return;

        const btn = document.getElementById('generateBtn');
        btn.disabled = true;
        setStatus('Uploading reference photo...');

        try {
            // --- Step 1: submit ---
            const formData = new FormData();
            formData.append('image', selectedFile);
            const submitResp = await fetch('generate_3d_model.php', { method: 'POST', body: formData });
            const submitResult = await submitResp.json();
            if (!submitResult.success) throw new Error(submitResult.message || 'Submission failed.');

            const { uuid, subscriptionKey } = submitResult;

            // --- Step 2: poll status every 5s, up to 10 minutes ---
            setStatus('Hyper3D is reconstructing the 3D model... (this can take a minute)');
            let attempts = 0;
            let done = false;
            while (attempts < 120 && !done) {
                await new Promise(res => setTimeout(res, 5000));
                attempts++;
                setStatus(`Hyper3D is reconstructing the 3D model... (attempt ${attempts}/120)`);

                const statusResp = await fetch('check_3d_model_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ subscriptionKey })
                });
                const statusResult = await statusResp.json();
                if (!statusResult.success) throw new Error(statusResult.message || 'Status check failed.');
                if (statusResult.failed) throw new Error('Hyper3D reported the generation failed.');
                done = statusResult.done;
            }
            if (!done) throw new Error('Generation timed out after 10 minutes.');

            // --- Step 3: download result ---
            setStatus('Fetching the finished 3D model...');
            const downloadResp = await fetch('download_3d_model_result.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ uuid })
            });
            const downloadResult = await downloadResp.json();
            if (!downloadResult.success) throw new Error(downloadResult.message || 'Could not fetch the result.');

            const glbUrl = downloadResult.url;

            // --- Save: attach the model to the selected product, and
            // log the generation -- same two writes the mobile app does,
            // performed via the admin's own signed-in Firestore session. ---
            await getBranchPath('inventory').doc(productId).update({
                model: glbUrl,
                updatedAt: firebase.firestore.FieldValue.serverTimestamp()
            });

            await db.collection('kiri_history').add({
                name: `${product.name} - 3D Model`,
                url: glbUrl,
                type: 'hyper3d_image_to_3d',
                productId: productId,
                userId: firebase.auth().currentUser ? firebase.auth().currentUser.uid : 'admin_uploader',
                createdAt: firebase.firestore.FieldValue.serverTimestamp()
            });

            showPreview(glbUrl);
            setStatus('');
            alert(`3D model generated and attached to "${product.name}"!`);
        } catch (err) {
            setStatus('');
            alert('Generation failed: ' + err.message);
        } finally {
            btn.disabled = false;
        }
    });

    // --- Generation history list ---
    db.collection('kiri_history').orderBy('createdAt', 'desc').limit(20).onSnapshot(snap => {
        const listEl = document.getElementById('historyList');
        if (snap.empty) {
            listEl.innerHTML = '<p class="text-xs text-gray-400 italic py-4 text-center">No generated models yet.</p>';
            return;
        }
        listEl.innerHTML = snap.docs.map(doc => {
            const d = doc.data();
            return `
                <div class="kiri-history-item">
                    <i class="fa-solid fa-cube text-[var(--primary)]"></i>
                    <div style="flex:1; min-width:0;">
                        <p style="font-weight:700; font-size:0.8rem; margin:0;">${d.name || 'Generated Model'}</p>
                        <p class="kiri-history-url">${d.url || ''}</p>
                    </div>
                    <button onclick="window.__previewFromHistory('${(d.url || '').replace(/'/g, "\\'")}')"
                        style="background:none; border:none; color:#999; cursor:pointer;" title="Preview">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            `;
        }).join('');
    }, err => {
        // Shown on the page, so a rules/permission problem is visible
        // instead of the list silently staying on "Loading history...".
        document.getElementById('historyList').innerHTML =
            `<p class="text-xs text-red-400 py-4 text-center">Could not load history: ${err.message}</p>`;
    });

    window.__previewFromHistory = (url) => { if (url) showPreview(url); };
</script>

<?php include 'templates/footer.php'; ?>