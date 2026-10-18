<?php

/**

 * BLOOMINOUS - 3D Realism Hub (Web)

 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }



if (!isset($_SESSION['admin_id'])) {

    header("Location: index.php");

    exit();

}



include 'templates/header.php';

?>



<script type="module" src="https\://ajax.googleapis.com/ajax/libs/model-viewer/3.5.0/model-viewer.min.js"></script>



<style>

    .kiri-content { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }

    .kiri-card { background: var(--surface); border-radius: 28px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.02); border: 1px solid var(--border-color); color: var(--text-main); transition: background-color 0.3s, border-color 0.3s; }

    .kiri-label { font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 1.5px; }



    /* Custom Dropdown */

    .custom-dropdown-container { position: relative; margin-bottom: 24px; }

    .custom-dropdown-btn { display: flex; align-items: center; gap: 12px; width: 100%; padding: 12px 16px; border: 1px solid var(--border-color); border-radius: 15px; background: var(--surface-alt); color: var(--text-main); cursor: pointer; font-weight: 600; font-size: 0.9rem; transition: border-color 0.2s; }

    .custom-dropdown-btn:hover { border-color: var(--primary); }

    .custom-dropdown-btn img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; background: #eee; }

    .custom-dropdown-menu { display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 8px; background: var(--surface); border: 1px solid var(--border-color); border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); max-height: 250px; overflow-y: auto; z-index: 100; }

    .custom-dropdown-menu.open { display: block; }

    .custom-dropdown-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; cursor: pointer; transition: background 0.2s; border-bottom: 1px solid var(--border-color); }

    .custom-dropdown-item:last-child { border-bottom: none; }

    .custom-dropdown-item:hover { background: var(--surface-alt); }

    .custom-dropdown-item img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; background: #eee; }

    .custom-dropdown-item .info { flex: 1; }

    .custom-dropdown-item .name { font-weight: 700; font-size: 0.85rem; color: var(--text-main); }

    .custom-dropdown-item .stock { font-size: 0.7rem; color: var(--text-light); font-weight: 600; }



    .kiri-dropzone { border: 2px dashed var(--border-color); border-radius: 20px; padding: 20px; text-align: center; cursor: pointer; transition: 0.3s; background: var(--surface-alt); min-height: 180px; display: flex; align-items: center; justify-content: center; overflow: hidden; }

    .kiri-dropzone:hover { border-color: var(--primary); background: rgba(245, 158, 11, 0.05); }



    .kiri-status { font-size: 0.8rem; color: var(--text-light); text-align: center; margin-top: 12px; font-weight: 600; }



    .kiri-history-item { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-radius: 16px; background: var(--surface-alt); margin-bottom: 8px; border: 1px solid var(--border-color); color: var(--text-main); cursor: pointer; transition: 0.2s; }

    .kiri-history-item:hover { background: var(--border-color); transform: translateY(-2px); }



    .kiri-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }

    @media (max-width: 900px) { .kiri-grid { grid-template-columns: 1fr; } }



    /* Grey background so white models are visible */

    .kiri-preview-box { height: 420px; border-radius: 20px; overflow: hidden; background: #e5e5e5; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; position: relative; }

    .kiri-preview-placeholder { color: #888; text-align: center; font-size: 0.8rem; }

    .kiri-preview-placeholder i { color: #aaa; opacity: 0.5; margin-bottom: 10px; display: block; font-size: 2rem;}

</style>



<main class="kiri-content">

    <div class="mb-10">

        <h1 class="brand-font text-5xl font-black" style="color: var(--text-main);">3D Realism Hub</h1>

        <p class="text-sm font-medium mt-1" style="color: var(--text-light);">Generate a realistic 3D model for an existing product from a reference photo.</p>

    </div>



    <div class="kiri-grid mb-8">

        <div class="kiri-card">

            <label class="kiri-label">1. Select Product from Inventory</label>



            <div class="custom-dropdown-container" id="customDropdown">

                <div class="custom-dropdown-btn" onclick="toggleDropdown()">

                    <img id="selectedImage" src="https\://via.placeholder.com/40?text=?" alt="Product">

                    <span id="selectedText" style="flex:1; text-align:left;">-- Select a product --</span>

                    <i class="fa-solid fa-chevron-down" style="font-size:0.8rem; color:var(--text-light);"></i>

                </div>

                <div class="custom-dropdown-menu" id="dropdownMenu">

                    <div style="padding: 16px; text-align: center; color: var(--text-light);">Loading products...</div>

                </div>

            </div>



            <select id="productSelect" style="display:none;">

                <option value="">-- Select a product --</option>

            </select>



            <label class="kiri-label">2. Upload Flower Reference Photo</label>

            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" style="display:none;">

            <div id="dropzone" class="kiri-dropzone mb-2" onclick="document.getElementById('photoInput').click()">

                <div id="dropzoneContent" class="w-full">

                    <i class="fa-solid fa-image text-3xl mb-2"></i>

                    <p class="text-sm font-semibold" style="color: var(--text-main);">Click to select a clear, well-lit photo of the flower</p>

                    <p class="text-xs mt-1" style="color: var(--text-light);">Plain background works best. JPEG, PNG, or WEBP, max 10MB.</p>

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

                    <i class="fa-solid fa-cube"></i>

                    Your generated model will appear here

                </div>

            </div>

        </div>

    </div>



    <div class="kiri-card">

        <label class="kiri-label">Generation History (Click to Preview)</label>

        <div id="historyList">

            <p class="text-xs italic py-4 text-center" style="color: var(--text-light);">Loading history...</p>

        </div>

    </div>

</main>



<script>

    let selectedFile = null;

    let selectedProductId = "";

    let productMap = {};



    function loadInventory() {

        if (typeof db === 'undefined') {

            setTimeout(loadInventory, 500);

            return;

        }



        getBranchPath('inventory').onSnapshot(snap => {

            productMap = {};

            const menu = document.getElementById('dropdownMenu');

            const hiddenSelect = document.getElementById('productSelect');



            let menuHtml = '';

            let selectHtml = '<option value="">-- Select a product --</option>';



            snap.forEach(doc => {

                const p = doc.data();

                if (p.isDeleted === true || p.status === 'archived') return;



                productMap[doc.id] = p;

                const imgUrl = p.image && p.image.trim() !== '' ? p.image : 'https\://via.placeholder.com/40?text=Flower';



                menuHtml += `

                    <div class="custom-dropdown-item" onclick="selectProduct('${doc.id}', '${p.name.replace(/'/g, "\\\\'")}', '${imgUrl}')">

                        <img src="${imgUrl}" onerror="this.src='https\://via.placeholder.com/40?text=Flower'">

                        <div class="info">

                            <div class="name">${p.name}</div>

                            <div class="stock">Stock: ${p.stock || 0} • ₱${parseFloat(p.price || 0).toFixed(2)}</div>

                        </div>

                    </div>

                `;

                selectHtml += `<option value="${doc.id}">${p.name}</option>`;

            });



            menu.innerHTML = menuHtml || '<div style="padding:16px; color:var(--text-light); text-align:center;">No products found.</div>';

            hiddenSelect.innerHTML = selectHtml;

        });

    }



    document.addEventListener('DOMContentLoaded', loadInventory);



    function toggleDropdown() {

        document.getElementById('dropdownMenu').classList.toggle('open');

    }



    window.addEventListener('click', function(e) {

        const container = document.getElementById('customDropdown');

        if (!container.contains(e.target)) {

            document.getElementById('dropdownMenu').classList.remove('open');

        }

    });



    function selectProduct(id, name, image) {
        document.getElementById('productSelect').value = id;
        selectedProductId = id;
        document.getElementById('selectedText').innerText = name;
        document.getElementById('selectedImage').src = image;
        document.getElementById('dropdownMenu').classList.remove('open');
        updateGenerateButtonState();

        // Load the model already saved in this inventory record. This does NOT call Tripo.
        const modelUrl = (productMap[id]?.model || '').trim();
        if (modelUrl && /^https?:\/\//i.test(modelUrl)) {
            showPreview(modelUrl);
        } else {
            document.getElementById('previewBox').innerHTML = `
                <div class="kiri-preview-placeholder">
                    <i class="fa-solid fa-cube"></i>
                    <p>No saved 3D model for ${escapeHtml(name)}.</p>
                    <p>Upload a reference photo only if you want to generate a new model.</p>
                </div>`;
        }
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
    }

    document.getElementById('photoInput').addEventListener('change', (e) => {

        selectedFile = e.target.files[0] || null;

        const dropzoneContent = document.getElementById('dropzoneContent');



        if (selectedFile) {

            const reader = new FileReader();

            reader.onload = (ev) => {

                dropzoneContent.innerHTML = `<img src="${ev.target.result}" style="max-height:140px; max-width:100%; border-radius:12px; object-fit:contain;">`;

            };

            reader.readAsDataURL(selectedFile);

        }

        updateGenerateButtonState();

    });



    function updateGenerateButtonState() {

        const btn = document.getElementById('generateBtn');

        if (selectedProductId && selectedProductId !== "" && selectedFile) {

            btn.disabled = false;

        } else {

            btn.disabled = true;

        }

    }



    function setStatus(msg) {

        const el = document.getElementById('statusText');

        el.style.display = msg ? 'block' : 'none';

        el.innerText = msg;

    }



    // Added a visible error message if the URL fails to load

    function showPreview(glbUrl) {
        const box = document.getElementById('previewBox');
        if (!glbUrl || !/^https?:\/\//i.test(glbUrl)) {
            box.textContent = 'Invalid 3D model URL.';
            return;
        }

        box.innerHTML = `<div style="text-align:center;color:#888;"><i class="fa-solid fa-spinner fa-spin"></i><p>Loading 3D model...</p></div>`;
        const viewer = document.createElement('model-viewer');
        viewer.setAttribute('src', glbUrl);
        viewer.setAttribute('alt', '3D model preview');
        viewer.setAttribute('camera-controls', '');
        viewer.setAttribute('auto-rotate', '');
        viewer.setAttribute('loading', 'eager');
        viewer.style.width = '100%';
        viewer.style.height = '100%';
        let settled = false;

        viewer.addEventListener('load', () => {
            settled = true;
            box.replaceChildren(viewer);
        }, { once: true });

        viewer.addEventListener('error', () => {
            settled = true;
            box.innerHTML = `<div style="padding:20px;text-align:center;color:#c62828;">
                <i class="fa-solid fa-triangle-exclamation text-2xl"></i>
                <p>Could not load this 3D model.</p>
                <small>Check that the URL points directly to a valid GLB/GLTF file and is publicly accessible.</small>
            </div>`;
        }, { once: true });

        box.replaceChildren(viewer);
        setTimeout(() => {
            if (!settled && !viewer.loaded) {
                box.innerHTML = `<div style="padding:20px;text-align:center;color:#c62828;">
                    <i class="fa-solid fa-clock text-2xl"></i>
                    <p>Model loading timed out.</p>
                    <small>The link may be unavailable, expired, or not a direct 3D model file.</small>
                </div>`;
            }
        }, 15000);
    }

    document.getElementById('generateBtn').addEventListener('click', async () => {

        // ... (Keep the generate logic exactly as it is)

        // [Removed for brevity, it's the exact same as the previous message]

        const product = productMap[selectedProductId];

        if (!selectedProductId || !selectedFile || !product) return;



        const btn = document.getElementById('generateBtn');

        btn.disabled = true;

        setStatus('Uploading reference photo to Tripo...');



        try {

            const formData = new FormData();

            formData.append('image', selectedFile);

            const submitResp = await fetch('generate_3d_model.php', { method: 'POST', body: formData });

            const submitResult = await submitResp.json();

            if (!submitResult.success) throw new Error(submitResult.message || 'Submission failed.');



            const { taskId } = submitResult;

            setStatus('Tripo is reconstructing the 3D model...');

            let attempts = 0;

            let done = false;



            while (attempts < 120 && !done) {

                await new Promise(res => setTimeout(res, 5000));

                attempts++;

                setStatus(`Tripo is reconstructing... (attempt ${attempts}/120)`);



                const statusResp = await fetch('check_3d_model_status.php', {

                    method: 'POST',

                    headers: { 'Content-Type': 'application/json' },

                    body: JSON.stringify({ taskId })

                });

                const statusResult = await statusResp.json();

                if (!statusResult.success) throw new Error(statusResult.message || 'Status check failed.');

                if (statusResult.failed) throw new Error('Tripo reported the generation failed.');



                if (statusResult.statusText === 'running' && statusResult.progress !== undefined) {

                     setStatus(`Tripo is reconstructing... (${statusResult.progress}% complete)`);

                }

                done = statusResult.done;

            }

            if (!done) throw new Error('Generation timed out.');



            setStatus('Fetching the finished 3D model...');

            const downloadResp = await fetch('download_3d_model_result.php', {

                method: 'POST',

                headers: { 'Content-Type': 'application/json' },

                body: JSON.stringify({ taskId })

            });

            const downloadResult = await downloadResp.json();

            if (!downloadResult.success) throw new Error(downloadResult.message || 'Could not fetch the result.');



            const glbUrl = downloadResult.url;

            setStatus('Saving URL to Firestore...');



            await getBranchPath('inventory').doc(selectedProductId).update({

                model: glbUrl,

                updatedAt: new Date().toISOString()

            });



            await db.collection('kiri_history').add({

                name: `${product.name} - 3D Model`,

                url: glbUrl,

                type: 'tripo_image_to_3d',

                productId: selectedProductId,

                userId: 'admin_uploader',

                createdAt: new Date().toISOString()

            });



            showPreview(glbUrl);

            setStatus('');

            alert(`3D model generated and saved to ${product.name}!`);

        } catch (err) {

            console.error(err);

            setStatus('');

            alert('Generation failed: ' + err.message);

        } finally {

            btn.disabled = false;

        }

    });



    db.collection('kiri_history').orderBy('createdAt', 'desc').limit(20).onSnapshot(snap => {

        const listEl = document.getElementById('historyList');

        if (snap.empty) {

            listEl.innerHTML = '<p class="text-xs italic py-4 text-center" style="color: var(--text-light);">No generated models yet.</p>';

            return;

        }

        listEl.innerHTML = snap.docs.map(doc => {

            const d = doc.data();

            const safeUrl = (d.url || '').replace(/'/g, "\\\\'");



            return `

                <div class="kiri-history-item" onclick="window.loadHistoryModel('${safeUrl}')">

                    <i class="fa-solid fa-cube" style="color: var(--primary); font-size: 1.2rem;"></i>

                    <div style="flex:1; min-width:0; padding-left: 10px;">

                        <p style="font-weight:700; font-size:0.85rem; margin:0; color: var(--text-main);">${d.name || 'Generated Model'}</p>

                        <p style="font-size:0.7rem; color:#3b82f6; margin:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${d.url || 'No URL'}</p>

                    </div>

                    <button style="background:none; border:none; color:var(--text-light); cursor:pointer;" title="Preview">

                        <i class="fa-solid fa-eye"></i>

                    </button>

                </div>

            `;

        }).join('');

    });



    window.loadHistoryModel = (url) => { 

        if (url && url.startsWith('http')) {

            showPreview(url); 

            document.getElementById('previewBox').scrollIntoView({ behavior: 'smooth', block: 'center' });

        } else {

            alert("This history entry doesn't have a valid 3D URL.");

        }

    };

</script>



<?php include 'templates/footer.php'; ?>