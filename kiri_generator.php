<?php
/**
 * BLOOMINOUS - 3D Realism Hub (Web)
 *
 * Flow: pick an inventory product -> upload a reference photo ->
 * generate_3d_model.php (submit to Hyper3D Rodin via Pixazo; THE CREDIT IS SPENT HERE) ->
 * check_3d_model_status.php (poll) ->
 * download_3d_model_result.php (copy the GLB to Supabase Storage, or to
 * Firebase Storage / Cloudinary if Supabase isn't working) -> REPLACE
 * branches/{branch}/inventory/{id}.model -> REPLACE that product's single
 * kiri_history row.
 *
 * RULES:
 *   - inventory/{id}.model is the ONLY source a SAVED preview loads from.
 *   - SHARED ACROSS BRANCHES: every branch keeps its own inventory copy
 *     (branches/{branch}/inventory), so a saved model is written to the SAME
 *     product in EVERY branch (main_branch, marilao, santa maria, ...).
 *     "Same product" = same document id, or the same product name.
 *     This is AUTOMATIC (no button): on save/replace/recover, and also when
 *     the page opens (branches whose copy has no model get the model).
 *   - Each product has ONE model and ONE history row
 *     (history doc id = "{branchId}_{productId}").
 *   - Generating for a product that already has a model replaces it
 *     (after the admin confirms).
 *   - ONE GENERATOR: Hyper3D Rodin 2.5 through Pixazo (Tripo was removed).
 *     Task ids always start with "px_". Needs PIXAZO_API_KEY in config.local.php.
 *     NOTHING is ever re-submitted automatically: one click = at most one paid job.
 *   - RESUMABLE: once Pixazo returns a task id (credit spent), it is saved on
 *     the product's history row. If any later step fails, "Resume" finishes
 *     the job from that task id WITHOUT spending another credit.
 *   - RECOVER BOX: paste a "px_..." Task ID (or the raw Request ID from Pixazo)
 *     to preview or re-attach a job Pixazo still has. Free: it only reads.
 *   - UNSAVED PREVIEW: "Preview (not saved)" streams a task's model through
 *     preview_3d_model.php just to look at it. Nothing is stored.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php';
?>

<!-- FIX: was "https\://..." -- the backslash made the browser treat this as a
     file on our OWN server (404), so <model-viewer> was never defined. -->
<script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/3.5.0/model-viewer.min.js"></script>

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
    .model-dot { font-size: 0.6rem; font-weight: 800; color: #16a34a; text-transform: uppercase; letter-spacing: 0.5px; }

    .kiri-dropzone { border: 2px dashed var(--border-color); border-radius: 20px; padding: 20px; text-align: center; cursor: pointer; transition: 0.3s; background: var(--surface-alt); min-height: 180px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .kiri-dropzone:hover { border-color: var(--primary); background: rgba(245, 158, 11, 0.05); }

    .kiri-status { font-size: 0.8rem; color: var(--text-light); text-align: center; margin-top: 12px; font-weight: 600; }

    /* Persistent error box: stays until the next attempt, text is selectable */
    .kiri-error { display: none; margin-top: 14px; padding: 14px 16px; border-radius: 14px; background: rgba(198, 40, 40, 0.08); border: 1px solid rgba(198, 40, 40, 0.35); color: #c62828; font-size: 0.78rem; font-weight: 600; white-space: pre-wrap; word-break: break-word; user-select: text; }
    .kiri-error-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; margin-top: 10px; gap: 8px; }

    .kiri-small-btn { font-size: 0.7rem; font-weight: 800; padding: 6px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--surface); color: var(--text-main); cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }
    .kiri-small-btn:hover { border-color: var(--primary); }
    .kiri-small-btn.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
    .kiri-small-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    .kiri-recover { margin-top: 18px; padding-top: 16px; border-top: 1px dashed var(--border-color); }
    .kiri-recover p { font-size: 0.72rem; color: var(--text-light); margin: 0 0 8px 0; }
    .kiri-recover-row { display: flex; gap: 8px; flex-wrap: wrap; }
    .kiri-recover-row input { flex: 1; min-width: 160px; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--border-color); background: var(--surface-alt); color: var(--text-main); font-size: 0.8rem; font-family: monospace; }

    .kiri-history-item { display: flex; align-items: center; gap: 14px; padding: 12px 18px; border-radius: 16px; background: var(--surface-alt); margin-bottom: 8px; border: 1px solid var(--border-color); color: var(--text-main); cursor: pointer; transition: 0.2s; }
    .kiri-history-item:hover { background: var(--border-color); transform: translateY(-2px); }
    .kiri-history-item img { width: 44px; height: 44px; border-radius: 10px; object-fit: cover; background: #eee; flex-shrink: 0; }
    .kiri-history-name { font-weight: 700; font-size: 0.9rem; margin: 0; color: var(--text-main); }
    .kiri-history-meta { font-size: 0.7rem; margin: 2px 0 0 0; font-weight: 600; color: var(--text-light); }
    .kiri-history-meta.ok { color: #16a34a; }
    .kiri-history-meta.warn { color: #c62828; }
    .kiri-history-pending { font-size: 0.7rem; margin: 6px 0 0 0; font-weight: 600; color: #b45309; }
    .kiri-history-pending.failed { color: #c62828; }
    .kiri-history-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }

    .kiri-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
    @media (max-width: 900px) { .kiri-grid { grid-template-columns: 1fr; } }

    /* Grey background so white models are visible */
    .kiri-preview-box { height: 420px; border-radius: 20px; overflow: hidden; background: #e5e5e5; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; position: relative; }
    .kiri-preview-placeholder { color: #888; text-align: center; font-size: 0.8rem; padding: 20px; }
    .kiri-preview-placeholder i { opacity: 0.6; margin-bottom: 10px; display: block; font-size: 2rem; }
    .kiri-preview-placeholder.is-error { color: #c62828; }

    /* Overlays on top of the 3D viewer */
    .kiri-preview-badge { position: absolute; top: 12px; left: 12px; padding: 4px 10px; border-radius: 999px; background: rgba(180, 83, 9, 0.9); color: #fff; font-size: 0.65rem; font-weight: 800; letter-spacing: 0.5px; pointer-events: none; }
    .kiri-preview-progress { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); padding: 4px 12px; border-radius: 999px; background: rgba(0, 0, 0, 0.6); color: #fff; font-size: 0.7rem; font-weight: 700; pointer-events: none; }
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
                <div class="custom-dropdown-btn" id="dropdownButton">
                    <img id="selectedImage" alt="Product">
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
            <div id="dropzone" class="kiri-dropzone mb-2">
                <div id="dropzoneContent" class="w-full">
                    <i class="fa-solid fa-image text-3xl mb-2"></i>
                    <p class="text-sm font-semibold" style="color: var(--text-main);">Click to select a clear, well-lit photo of the flower</p>
                    <p class="text-xs mt-1" style="color: var(--text-light);">Plain background works best. JPEG, PNG, or WEBP, max 10MB.</p>
                </div>
            </div>

            <button id="generateBtn" class="btn-primary w-full py-4 mt-6 text-sm uppercase tracking-widest" disabled>
                <i class="fa-solid fa-wand-magic-sparkles mr-2"></i> <span id="generateBtnLabel">Generate 3D Model</span>
            </button>
            <p id="statusText" class="kiri-status" style="display:none;"></p>

            <div id="errorBox" class="kiri-error">
                <div id="errorText"></div>
                <div class="kiri-error-actions">
                    <button type="button" id="previewErrorBtn" class="kiri-small-btn primary" style="display:none;">Preview (not saved)</button>
                    <button type="button" id="copyErrorBtn" class="kiri-small-btn">Copy details</button>
                    <button type="button" id="closeErrorBtn" class="kiri-small-btn">Close</button>
                </div>
            </div>

            <div class="kiri-recover">
                <p><i class="fa-solid fa-life-ring mr-1"></i> Already paid for a generation that failed to save? Paste its Hyper3D Task ID (starts with <b>px_</b>, or the Request ID from Pixazo) to <b>Preview</b> it (nothing is saved), or select its product above and <b>Recover</b> it without using another credit.</p>
                <div class="kiri-recover-row">
                    <input type="text" id="recoverTaskId" placeholder="Task ID (px_...) or Pixazo Request ID" autocomplete="off" spellcheck="false">
                    <button type="button" id="previewTaskBtn" class="kiri-small-btn">Preview</button>
                    <button type="button" id="recoverBtn" class="kiri-small-btn primary">Recover</button>
                </div>
            </div>
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
    // ---------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------
    // FIX: via.placeholder.com was also written as "https\://..." (broken),
    // and that service is unreliable anyway. An inline SVG never fails.
    const PLACEHOLDER_IMG = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="100%" height="100%" fill="#eeeeee"/><text x="50%" y="60%" font-size="34" text-anchor="middle">🌸</text></svg>'
    );
    const MODEL_LOAD_TIMEOUT_MS = 20000;            // saved models (CDN, fast)
    const UNSAVED_PREVIEW_TIMEOUT_MS = 180000;      // unsaved models stream via our server (can be 40+ MB)
    const VIEWER_LIB_TIMEOUT_MS = 8000;             // how long the viewer script may take to load
    const POLL_INTERVAL_MS = 5000;
    const MAX_POLL_ATTEMPTS = 120;                  // 120 x 5s = 10 minutes
    const HISTORY_FETCH_LIMIT = 50;                 // raw entries fetched; duplicates are collapsed
    const TASK_ID_PATTERN = /^[A-Za-z0-9_-]{1,128}$/; // same rule the PHP endpoints enforce

    // ---------------------------------------------------------------
    // Page state
    // ---------------------------------------------------------------
    let selectedFile = null;
    let selectedProductId = '';
    let productMap = {};          // inventory doc id -> product data (live)
    let historyDocs = [];         // kiri_history entries (live): [{ id, ...data }]
    let historyLoaded = false;    // false until the first history snapshot arrives
    let historyError = '';        // set if the history listener fails
    let previewRequestId = 0;     // newest preview wins; older ones are ignored
    let isGenerating = false;     // true while ANY job (new or resumed) runs
    let activeJobProductId = '';  // which product the running job belongs to
    let errorTaskId = '';         // task id shown in the error box (for its Preview button)
    let errorTaskLabel = '';      // product name shown in the error box

    // ---------------------------------------------------------------
    // Small helpers
    // ---------------------------------------------------------------
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[char]);
    }

    // Hyper3D (Pixazo) task ids always start with "px_".
    // Anything else saved in an OLD history row is a Tripo task (Tripo was removed).
    function isPixazoTask(taskId) {
        return String(taskId || '').startsWith('px_');
    }

    // Turns what the admin pasted into a task id the server understands.
    //   "px_abc..."                       -> used as it is
    //   the raw Pixazo Request ID         -> wrapped: "px_" + base64url(request id)
    //                                        (exactly what the PHP side does, so both match)
    //   anything else / too long          -> '' (rejected)
    function normalizeTaskId(value) {
        const text = String(value || '').trim();
        if (!text) return '';
        if (text.startsWith('px_')) return TASK_ID_PATTERN.test(text) ? text : '';
        if (!/^[A-Za-z0-9_.-]{1,90}$/.test(text)) return '';
        const encoded = btoa(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        return 'px_' + encoded;
    }

    function isHttpUrl(value) {
        return typeof value === 'string' && /^https?:\/\//i.test(value.trim());
    }

    // Works out where a model URL lives, from its hostname.
    function urlHost(url) {
        try {
            const host = new URL(url).hostname;
            if (host.endsWith('tripo3d.com')) return 'tripo';
            if (host === 'res.cloudinary.com') return 'cloudinary';
            if (host === 'firebasestorage.googleapis.com' || host.endsWith('.firebasestorage.app')) return 'firebase';
            return 'other';
        } catch (_) {
            return 'other';
        }
    }

    function productImage(p) {
        return p && isHttpUrl(p.image) ? p.image.trim() : PLACEHOLDER_IMG;
    }

    function productHasModel(p) {
        return !!p && isHttpUrl(p.model);
    }

    // Branch key used inside history doc ids. Only safe characters allowed.
    function currentBranchKey() {
        return String(window.currentBranch || 'default').replace(/[^A-Za-z0-9_-]/g, '_');
    }

    // ONE history doc per product per branch: "{branch}_{productId}".
    function historyDocIdFor(productId) {
        return `${currentBranchKey()}_${productId}`;
    }

    // Full URL of the streaming preview endpoint for a task (same origin).
    function unsavedPreviewUrl(taskId) {
        return new URL('preview_3d_model.php?taskId=' + encodeURIComponent(taskId), window.location.href).href;
    }

    function setStatus(msg) {
        const el = document.getElementById('statusText');
        el.style.display = msg ? 'block' : 'none';
        el.innerText = msg || '';
    }

    // Persistent, copyable error box (no screenshot needed).
    // taskId (optional) enables the box's "Preview (not saved)" button.
    function showError(message, taskId = '', label = '') {
        errorTaskId = taskId;
        errorTaskLabel = label;
        document.getElementById('errorText').innerText = message;
        document.getElementById('previewErrorBtn').style.display = taskId ? 'inline-block' : 'none';
        document.getElementById('errorBox').style.display = 'block';
    }

    function clearError() {
        errorTaskId = '';
        errorTaskLabel = '';
        document.getElementById('errorText').innerText = '';
        document.getElementById('previewErrorBtn').style.display = 'none';
        document.getElementById('errorBox').style.display = 'none';
    }

    // Turns common raw error messages into a plain-English next step.
    function hintForError(message) {
        const m = String(message || '');
        if (/file size too large|too large/i.test(m)) {
            return "Hint: the model file is bigger than Cloudinary allows (the free plan caps raw files at 10 MB, and textured PBR models are often larger). Enable Firebase Storage or raise the Cloudinary limit, then press Resume. You can still use \"Preview (not saved)\" to look at it now.";
        }
        if (/not configured/i.test(m)) {
            return 'Hint: add CLOUDINARY_CLOUD_NAME and CLOUDINARY_UPLOAD_PRESET to config.local.php (same values as the app), then press Resume.';
        }
        if (/HTTP 50[234]|timed out|timeout/i.test(m)) {
            return 'Hint: the server or network took too long. The paid model is still at the generator, so press Resume.';
        }
        if (/permission|insufficient/i.test(m)) {
            return 'Hint: Firestore refused the write. Check that you are signed in as an admin and that firestore.rules allows inventory / kiri_history writes.';
        }
        if (/PIXAZO|Hyper3D/i.test(m)) {
            return 'Hint: Hyper3D needs PIXAZO_API_KEY in config.local.php (a Pixazo API key with credit), and Supabase set up to host the reference photo.';
        }
        if (/not authenticated/i.test(m)) {
            return 'Hint: your admin session expired. Log in again, then press Resume.';
        }
        return '';
    }

    function renderPreviewMessage(iconClass, title, detail, isError) {
        document.getElementById('previewBox').innerHTML = `
            <div class="kiri-preview-placeholder ${isError ? 'is-error' : ''}">
                <i class="fa-solid ${iconClass}"></i>
                <p style="font-weight:700; font-size:0.95rem;">${escapeHtml(title)}</p>
                ${detail ? `<small>${escapeHtml(detail)}</small>` : ''}
            </div>`;
    }

    // Accepts a Firestore Timestamp OR an ISO string; returns a readable date.
    function formatWhen(value) {
        const d = (value && typeof value.toDate === 'function') ? value.toDate() : new Date(value);
        return isNaN(d) ? '' : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }

    // POSTs and returns the parsed JSON. Throws a readable error on HTTP
    // errors, success:false, or a PHP error page (HTML instead of JSON).
    async function requestJson(url, options) {
        const response = await fetch(url, options);
        let data = {};
        try { data = await response.json(); } catch (_) { data = {}; }
        if (!response.ok || !data.success) {
            throw new Error(data.message || `Server error (HTTP ${response.status}).`);
        }
        return data;
    }

    // ---------------------------------------------------------------
    // 3D preview
    // ---------------------------------------------------------------

    // Resolves true once <model-viewer> is registered, or false if the
    // script never loaded (offline, blocked by an extension, bad URL...).
    function whenViewerReady() {
        if (customElements.get('model-viewer')) return Promise.resolve(true);
        return Promise.race([
            customElements.whenDefined('model-viewer').then(() => true),
            new Promise(resolve => setTimeout(() => resolve(false), VIEWER_LIB_TIMEOUT_MS))
        ]);
    }

    // A hint that matches WHERE the file lives, so the admin knows what to check.
    function failHintFor(url) {
        switch (urlHost(url)) {
            case 'tripo':
                return "This product's model is a temporary Tripo link, which expires about 5 minutes after generation. Regenerate it to save a permanent copy.";
            case 'cloudinary':
                return 'The Cloudinary file may have been deleted. Regenerate the model to re-upload it.';
            case 'firebase':
                return 'Check that the Firebase Storage bucket allows browser downloads (CORS) and that the file still exists.';
            default:
                return "Check that the product's model field points directly to a public .glb/.gltf file.";
        }
    }

    /**
     * Shows a model in the preview box.
     * options.timeoutMs  - how long to wait before giving up
     * options.failDetail - custom hint if it fails (default: based on the host)
     * options.badge      - small label in the corner (e.g. "PREVIEW ONLY - NOT SAVED")
     */
    async function showPreview(glbUrl, options = {}) {
        const {
            timeoutMs = MODEL_LOAD_TIMEOUT_MS,
            failDetail = '',
            badge = ''
        } = options;
        const requestId = ++previewRequestId;

        if (!isHttpUrl(glbUrl)) {
            renderPreviewMessage('fa-triangle-exclamation', 'Invalid 3D model URL.', '', true);
            return;
        }

        renderPreviewMessage('fa-spinner fa-spin', 'Loading 3D model...', '', false);

        const viewerReady = await whenViewerReady();
        if (requestId !== previewRequestId) return; // user picked something else meanwhile

        if (!viewerReady) {
            renderPreviewMessage(
                'fa-plug-circle-xmark',
                '3D viewer failed to load.',
                'The model-viewer script could not be downloaded. Check your internet connection and refresh the page.',
                true
            );
            return;
        }

        const detail = failDetail || failHintFor(glbUrl);

        const viewer = document.createElement('model-viewer');
        viewer.setAttribute('alt', '3D model preview');
        viewer.setAttribute('camera-controls', '');
        viewer.setAttribute('auto-rotate', '');
        viewer.setAttribute('loading', 'eager');
        viewer.style.width = '100%';
        viewer.style.height = '100%';

        // Overlays: optional badge + a loading percentage that disappears on load.
        const overlays = [];
        if (badge) {
            const badgeEl = document.createElement('div');
            badgeEl.className = 'kiri-preview-badge';
            badgeEl.textContent = badge;
            overlays.push(badgeEl);
        }
        const progressEl = document.createElement('div');
        progressEl.className = 'kiri-preview-progress';
        progressEl.textContent = 'Loading 0%';
        overlays.push(progressEl);

        let settled = false;
        let timeoutId = null;

        // Runs the outcome ONCE, and only if this is still the newest preview.
        const finish = (outcome) => {
            if (settled || requestId !== previewRequestId) return;
            settled = true;
            clearTimeout(timeoutId);
            outcome();
        };

        // model-viewer reports download progress from 0 to 1.
        viewer.addEventListener('progress', (e) => {
            const pct = Math.round((e.detail && e.detail.totalProgress ? e.detail.totalProgress : 0) * 100);
            progressEl.textContent = `Loading ${pct}%`;
        });

        viewer.addEventListener('load', () => finish(() => progressEl.remove()), { once: true });
        viewer.addEventListener('error', () => finish(() =>
            renderPreviewMessage('fa-triangle-exclamation', 'Could not load this 3D model.', detail, true)
        ), { once: true });

        timeoutId = setTimeout(() => finish(() =>
            renderPreviewMessage('fa-clock', 'Model loading timed out.', detail, true)
        ), timeoutMs);

        document.getElementById('previewBox').replaceChildren(viewer, ...overlays);
        viewer.src = glbUrl.trim(); // set LAST, after the listeners are attached
    }

    // Look at a Hyper3D task's model WITHOUT saving it anywhere.
    function previewUnsavedTask(rawTaskId, label = '') {
        const taskId = normalizeTaskId(rawTaskId);
        if (!taskId) {
            alert('That does not look like a Hyper3D Task ID. Paste the px_... id (or the Pixazo Request ID) exactly.');
            return;
        }

        showPreview(unsavedPreviewUrl(taskId), {
            timeoutMs: UNSAVED_PREVIEW_TIMEOUT_MS,
            failDetail: 'The generator may no longer keep this task, or the download was interrupted. Check the browser console (F12) and the PHP error log.',
            badge: 'PREVIEW ONLY - NOT SAVED' + (label ? ` • ${label}` : '')
        });
        document.getElementById('previewBox').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // ---------------------------------------------------------------
    // Product dropdown (also used by history clicks)
    // ---------------------------------------------------------------

    // Selects a product and previews ITS inventory model.
    // This is the ONLY place a preview of a SAVED model starts from.
    function selectProduct(id) {
        const p = productMap[id];
        if (!p) return;

        selectedProductId = id;
        document.getElementById('productSelect').value = id;
        document.getElementById('selectedText').innerText = p.name || 'Unnamed';
        document.getElementById('selectedImage').src = productImage(p);
        document.getElementById('dropdownMenu').classList.remove('open');
        updateGenerateButtonState();

        if (productHasModel(p)) {
            showPreview(p.model.trim());
        } else {
            previewRequestId++; // cancel any preview still loading
            renderPreviewMessage(
                'fa-cube',
                `No saved 3D model for ${p.name || 'this product'}.`,
                'Upload a reference photo and click Generate to create one.',
                false
            );
        }
    }

    function loadInventory() {
        getBranchPath('inventory').onSnapshot(snap => {
            productMap = {};
            let menuHtml = '';
            let selectHtml = '<option value="">-- Select a product --</option>';

            snap.forEach(doc => {
                const p = doc.data();
                if (p.isDeleted === true || p.status === 'archived') return;

                productMap[doc.id] = p;

                // data-product-id + one delegated click listener, instead of
                // pasting the name into onclick="..." (broke on apostrophes).
                menuHtml += `
                    <div class="custom-dropdown-item" data-product-id="${escapeHtml(doc.id)}">
                        <img src="${escapeHtml(productImage(p))}" alt="">
                        <div class="info">
                            <div class="name">${escapeHtml(p.name || 'Unnamed')}</div>
                            <div class="stock">Stock: ${escapeHtml(p.stock || 0)} • ₱${parseFloat(p.price || 0).toFixed(2)}
                                ${productHasModel(p) ? '<span class="model-dot">• 3D</span>' : ''}</div>
                        </div>
                    </div>`;
                selectHtml += `<option value="${escapeHtml(doc.id)}">${escapeHtml(p.name || 'Unnamed')}</option>`;
            });

            document.getElementById('dropdownMenu').innerHTML = menuHtml
                || '<div style="padding:16px; color:var(--text-light); text-align:center;">No products found.</div>';
            document.getElementById('productSelect').innerHTML = selectHtml;

            // Broken product photos fall back to the placeholder.
            document.querySelectorAll('#dropdownMenu img').forEach(img => {
                img.onerror = () => { img.onerror = null; img.src = PLACEHOLDER_IMG; };
            });

            // The selected product's model may have just changed -> refresh the button label.
            updateGenerateButtonState();
            // History rows show product names/status from inventory -> redraw them.
            renderHistory();

            // Once per page load: give branches that lack a model the one other branches have.
            fillMissingModelsInAllBranches();
        }, err => {
            console.error('Inventory load failed:', err);
            document.getElementById('dropdownMenu').innerHTML =
                `<div style="padding:16px; color:#c62828; text-align:center;">Could not load products: ${escapeHtml(err.message)}</div>`;
        });
    }

    // ---------------------------------------------------------------
    // Branch sharing: one product, one model, EVERY branch
    // ---------------------------------------------------------------

    // "Red Rose", " red  rose " and "RED ROSE" all count as the same product.
    function normalizeName(name) {
        return String(name || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    // Writes the model link to the same product in EVERY branch
    // (skipping copies that already have this exact link).
    // Returns the list of document paths that were updated.
    async function applyModelToAllBranches(productId, productName, modelUrl) {
        const targetName = normalizeName(productName);

        // All branch ids (main_branch, marilao, santa maria...) + the current one, just in case.
        const branchesSnap = await db.collection('branches').get();
        const branchIds = new Set(branchesSnap.docs.map(d => d.id));
        branchIds.add(window.currentBranch || 'main_branch');

        // Find the matching product document in each branch's inventory.
        const refs = [];
        for (const branchId of branchIds) {
            const invSnap = await db.collection('branches').doc(branchId).collection('inventory').get();
            invSnap.forEach(doc => {
                const d = doc.data();
                if (d.isDeleted === true || d.status === 'archived') return;

                const sameId = doc.id === productId;
                const sameName = targetName !== '' && normalizeName(d.name) === targetName;
                const alreadyHasIt = isHttpUrl(d.model) && d.model.trim() === modelUrl;
                if ((sameId || sameName) && !alreadyHasIt) refs.push(doc.ref);
            });
        }

        // Firestore allows 500 writes per batch; stay safely below that.
        for (let i = 0; i < refs.length; i += 400) {
            const batch = db.batch();
            refs.slice(i, i + 400).forEach(ref => batch.update(ref, {
                model: modelUrl,
                updatedAt: firebase.firestore.FieldValue.serverTimestamp()
            }));
            await batch.commit();
        }

        return refs.map(ref => ref.path);
    }

    // Runs ONCE when the page opens. For every product name, if ANY branch has a
    // model and another branch's copy of that product has none, copy it over.
    // It only FILLS EMPTY models; it never overwrites a different existing one
    // (replacing happens only when an admin generates/recovers a model).
    let crossBranchFillDone = false;
    async function fillMissingModelsInAllBranches() {
        if (crossBranchFillDone) return;
        crossBranchFillDone = true;

        try {
            const branchesSnap = await db.collection('branches').get();
            const branchIds = new Set(branchesSnap.docs.map(d => d.id));
            const here = window.currentBranch || 'main_branch';
            branchIds.add(here);

            // name -> { url: model found somewhere, missing: [refs with no model] }
            const groups = {};
            for (const branchId of branchIds) {
                const invSnap = await db.collection('branches').doc(branchId).collection('inventory').get();
                invSnap.forEach(doc => {
                    const d = doc.data();
                    if (d.isDeleted === true || d.status === 'archived') return;

                    const key = normalizeName(d.name);
                    if (!key) return;
                    const group = groups[key] || (groups[key] = { url: '', missing: [] });

                    if (isHttpUrl(d.model)) {
                        // Prefer the model of the branch the admin is in; else the first one found.
                        if (!group.url || branchId === here) group.url = d.model.trim();
                    } else {
                        group.missing.push(doc.ref);
                    }
                });
            }

            const jobs = [];
            Object.values(groups).forEach(g => {
                if (g.url) g.missing.forEach(ref => jobs.push({ ref, url: g.url }));
            });
            if (jobs.length === 0) return;

            for (let i = 0; i < jobs.length; i += 400) {
                const batch = db.batch();
                jobs.slice(i, i + 400).forEach(job => batch.update(job.ref, {
                    model: job.url,
                    updatedAt: firebase.firestore.FieldValue.serverTimestamp()
                }));
                await batch.commit();
            }
            console.log(`Auto-shared 3D models: filled ${jobs.length} product record(s) in other branches.`);
        } catch (err) {
            // Not critical (e.g. no permission). The next save/replace still shares automatically.
            console.warn('Automatic model sharing across branches skipped:', err.message);
        }
    }

    // ---------------------------------------------------------------
    // Buttons
    // ---------------------------------------------------------------
    function updateGenerateButtonState() {
        document.getElementById('generateBtn').disabled =
            isGenerating || !selectedProductId || !selectedFile;

        // Tell the admin up front that this product's model will be replaced.
        document.getElementById('generateBtnLabel').innerText =
            productHasModel(productMap[selectedProductId])
                ? 'Regenerate & Replace 3D Model'
                : 'Generate 3D Model';

        document.getElementById('recoverBtn').disabled = isGenerating;
    }

    // ---------------------------------------------------------------
    // The job, split into steps so it can be RESUMED from a task id
    // ---------------------------------------------------------------

    // STEP 1 (spends a generator credit): upload the photo, get a task id back.
    // Returns { taskId }. Nothing is ever sent twice: one call = at most one paid job.
    async function submitGeneration(file) {
        setStatus('Uploading reference photo...');

        const formData = new FormData();
        formData.append('image', file);

        const data = await requestJson('generate_3d_model.php', { method: 'POST', body: formData });
        if (!data.taskId) throw new Error('The 3D generator did not return a task ID.');
        return { taskId: data.taskId };
    }

    // STEP 2 (free): poll until Hyper3D says done. Throws if Hyper3D failed.
    async function waitForGenerator(taskId) {
        const who = 'Hyper3D';
        setStatus(`${who} is reconstructing the 3D model...`);
        for (let attempt = 1; attempt <= MAX_POLL_ATTEMPTS; attempt++) {
            const status = await requestJson('check_3d_model_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ taskId })
            });

            if (status.failed) {
                const err = new Error(status.message || `${who} reported that this generation failed.`);
                err.generatorFailed = true; // the generator failed: resuming would fail again, so no Resume button
                err.generator = who;
                throw err;
            }
            if (status.done === true) return;

            setStatus(status.progress !== undefined
                ? `${who} is reconstructing... (${status.progress}% complete)`
                : `${who} is reconstructing... (attempt ${attempt}/${MAX_POLL_ATTEMPTS})`);

            await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL_MS));
        }
        throw new Error(`Generation timed out while waiting for ${who}. Press Resume to keep waiting.`);
    }

    // STEP 3 (free): server copies the GLB to Supabase / Firebase Storage / Cloudinary.
    async function saveModelFile(taskId, productId) {
        setStatus('Saving the finished 3D model...');
        return requestJson('download_3d_model_result.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ taskId, productId })
        });
    }

    // Records an unfinished, ALREADY PAID job on the product's history row,
    // so it survives a failure, a closed tab, or a refresh.
    // merge:true = only these fields change; the rest of the row is kept.
    async function markPending(productId, productName, taskId, pendingStatus, pendingError) {
        try {
            await db.collection('kiri_history').doc(historyDocIdFor(productId)).set({
                name: productName,
                productName: productName,
                productId: productId,
                branchId: window.currentBranch || null,
                pendingTaskId: taskId,
                pendingStatus: pendingStatus,          // 'generating' | 'save_failed' | 'generator_failed'
                pendingError: pendingError || null,
                pendingUpdatedAt: new Date().toISOString(),
                createdAt: new Date().toISOString()    // keeps the row at the top of the list
            }, { merge: true });
        } catch (e) {
            console.warn('Could not record the pending task (copy the Task ID from the error box):', e);
        }
    }

    // After a successful save: write the product's ONE history row (full
    // replace, which also clears any pending fields), then delete older rows
    // for the same product (legacy random-id rows).
    async function saveHistoryForProduct(productId, productName, saved, taskId, previousModelUrl) {
        const historyId = historyDocIdFor(productId);
        const branchId = window.currentBranch || null;

        await db.collection('kiri_history').doc(historyId).set({
            name: productName,
            productName: productName,
            productId: productId,
            branchId: branchId,
            url: saved.url,                    // record only; previews read inventory
            provider: saved.provider || 'unknown',
            storagePath: saved.storagePath || null,
            cloudinaryPublicId: saved.cloudinaryPublicId || null,
            previousModelUrl: previousModelUrl || null, // for a future storage cleanup
            taskId: taskId,
            type: 'hyper3d_image_to_3d',
            generator: 'hyper3d',
            userId: firebase.auth().currentUser ? firebase.auth().currentUser.uid : 'admin_uploader',
            createdAt: new Date().toISOString()
        });

        const dupes = await db.collection('kiri_history').where('productId', '==', productId).get();
        const batch = db.batch();
        let deleteCount = 0;
        dupes.forEach(doc => {
            const d = doc.data();
            const sameBranch = !d.branchId || d.branchId === branchId;
            if (doc.id !== historyId && sameBranch) {
                batch.delete(doc.ref);
                deleteCount++;
            }
        });
        if (deleteCount > 0) await batch.commit();
    }

    /**
     * Runs a full job for ONE product.
     *   existingTaskId = null    -> NEW generation (uploads the photo, spends a credit)
     *   existingTaskId = "..."   -> RESUME a paid task (no upload, no new credit)
     */
    async function runJob(productId, existingTaskId) {
        const product = productMap[productId];
        if (isGenerating || !product) return;

        const isResume = !!existingTaskId;
        if (!isResume && !selectedFile) return;

        const productName = product.name || 'Product';
        const previousModelUrl = productHasModel(product) ? product.model.trim() : null;

        if (previousModelUrl && !confirm(
            `${productName} already has a 3D model.\n\n` +
            (isResume
                ? 'Saving this paid generation will REPLACE it in EVERY branch (web preview and mobile AR). No new credit is used.'
                : 'Generating a new one will REPLACE it in EVERY branch (web preview and mobile AR) and use 1 generator credit.') +
            '\n\nContinue?'
        )) {
            return;
        }

        isGenerating = true;
        activeJobProductId = productId;
        clearError();
        updateGenerateButtonState();
        renderHistory(); // hide Resume buttons while this job runs

        let taskId = existingTaskId || null;

        try {
            // STEP 1: only for NEW jobs. From here on the credit is spent,
            // so the task id is recorded BEFORE anything else can fail.
            if (!taskId) {
                const submitted = await submitGeneration(selectedFile);
                taskId = submitted.taskId;
                await markPending(productId, productName, taskId, 'generating', null);
            }

            // STEP 2: wait for Hyper3D. If it fails, we STOP (no automatic second job,
            // so nothing is charged twice without you pressing Generate again).
            await waitForGenerator(taskId);

            // STEP 3: copy the finished file somewhere permanent.
            const saved = await saveModelFile(taskId, productId);

            // STEP 4: REPLACE the product's model (the field web + app read).
            setStatus('Replacing the product model...');
            const inventoryRef = getBranchPath('inventory').doc(productId);
            await inventoryRef.update({
                model: saved.url,
                updatedAt: firebase.firestore.FieldValue.serverTimestamp()
            });

            // STEP 4b: read it back FROM THE SERVER to prove the write landed
            // on this exact product document.
            const check = await inventoryRef.get({ source: 'server' });
            const writtenUrl = check.exists ? String(check.data().model || '').trim() : '';
            if (writtenUrl !== saved.url) {
                throw new Error(
                    'The model was saved to storage, but the product record did not update. ' +
                    'Document checked: ' + inventoryRef.path
                );
            }
            console.log('3D model written to', inventoryRef.path, '->', saved.url);

            // STEP 4c: share it with the same product in EVERY branch.
            // The main save above already worked, so a failure here is reported, not fatal.
            let sharedPaths = [inventoryRef.path];
            let shareWarning = '';
            try {
                setStatus('Sharing the model with all branches...');
                const otherPaths = await applyModelToAllBranches(productId, productName, saved.url);
                sharedPaths = Array.from(new Set([inventoryRef.path, ...otherPaths]));
            } catch (shareErr) {
                console.warn('Could not share with other branches:', shareErr);
                shareWarning = `\n\nWARNING: other branches were NOT updated (${shareErr.message}). ` +
                    'They will be filled automatically the next time this page is opened by an admin.';
            }

            // STEP 5: replace this product's ONE history row (clears pending).
            try {
                await saveHistoryForProduct(productId, productName, saved, taskId, previousModelUrl);
            } catch (historyErr) {
                console.warn('History update failed (model still saved):', historyErr);
            }

            if (selectedProductId === productId) {
                showPreview(saved.url);
            }
            setStatus('');

            const via = saved.provider === 'cloudinary' ? ' (via Cloudinary backup)' : '';
            alert(
                (previousModelUrl
                    ? `${productName}'s 3D model was replaced${via}.`
                    : `3D model saved to ${productName}${via}.`) +
                `\n\nUpdated ${sharedPaths.length} product record(s):\n${sharedPaths.join('\n')}` +
                `\nStorage: ${saved.provider || 'unknown'}` +
                shareWarning
            );
        } catch (err) {
            console.error(err);
            setStatus('');

            const message = err.message || 'Unknown error.';
            const hint = hintForError(message);

            if (taskId) {
                // A credit WAS spent. Remember the task so it can be resumed.
                await markPending(
                    productId,
                    productName,
                    taskId,
                    err.generatorFailed ? 'generator_failed' : 'save_failed',
                    message
                );
                showError(
                    `${productName}: ${message}\n\n` +
                    `Hyper3D Task ID: ${taskId}\n` +
                    (err.generatorFailed
                        ? `Hyper3D could not build this model. Try another photo.`
                        : `This generation is already paid for. Press "Resume" on ${productName}'s history row (or paste the Task ID in the Recover box) to finish saving it without using another credit.`) +
                    (hint ? `\n\n${hint}` : ''),
                    err.generatorFailed ? '' : taskId,   // Preview only makes sense if Hyper3D succeeded
                    productName
                );
            } else {
                // Failed before Hyper3D accepted the task (no task id = nothing was charged, nothing to resume).
                showError(`Generation failed before Hyper3D accepted the task (nothing was charged): ${message}` + (hint ? `\n\n${hint}` : ''));
            }
        } finally {
            isGenerating = false;
            activeJobProductId = '';
            updateGenerateButtonState();
            renderHistory();
        }
    }

    // "Recover" box: attach a paid task id to the SELECTED product.
    function recoverFromInput() {
        const input = document.getElementById('recoverTaskId');
        const taskId = normalizeTaskId(input.value);

        if (!selectedProductId) {
            alert('Select the product this generation belongs to first.');
            return;
        }
        if (!taskId) {
            alert('That does not look like a Hyper3D Task ID. Paste the px_... id (or the Pixazo Request ID) exactly.');
            return;
        }

        runJob(selectedProductId, taskId).then(() => { input.value = ''; });
    }

    // ---------------------------------------------------------------
    // Generation history  (shows product names; previews read inventory)
    // ---------------------------------------------------------------

    // Finds which inventory product a history entry belongs to.
    //   1. By productId (every entry this page writes has one).
    //   2. Fallback for older/manual entries: by name, where
    //      "Red Rose - 3D Model" matches the product named "Red Rose".
    function findLinkedProductId(entry) {
        if (entry.productId && productMap[entry.productId]) {
            return entry.productId;
        }

        const baseName = String(entry.productName || entry.name || '')
            .replace(/\s*-\s*3D Model\s*$/i, '')
            .trim()
            .toLowerCase();
        if (!baseName) return null;

        const match = Object.keys(productMap).find(id =>
            String(productMap[id].name || '').trim().toLowerCase() === baseName
        );
        return match || null;
    }

    // Display name: current product name if found, else what history remembers.
    function historyDisplayName(entry, productId) {
        if (productId && productMap[productId]) {
            return productMap[productId].name || 'Unnamed product';
        }
        return String(entry.productName || entry.name || 'Unknown product')
            .replace(/\s*-\s*3D Model\s*$/i, '')
            .trim();
    }

    // HTML for the "unfinished generation" part of a row (empty if none).
    function pendingHtml(entry, productId) {
        if (!entry.pendingTaskId || !entry.pendingStatus) return '';

        const shortId = String(entry.pendingTaskId).slice(0, 8);
        const id = escapeHtml(entry.id);

        // This product's job is running right now -> no buttons.
        if (isGenerating && activeJobProductId === productId) {
            return `<p class="kiri-history-pending">Generating now... (Task ${escapeHtml(shortId)}...)</p>`;
        }

        const dismissBtn = `<button type="button" class="kiri-small-btn" data-action="dismiss" data-history-id="${id}">Dismiss</button>`;

        // An OLD row from the Tripo days: Tripo is gone, so it cannot be resumed or previewed.
        if (!isPixazoTask(entry.pendingTaskId)) {
            return `
                <p class="kiri-history-pending failed">Old Tripo generation (Task ${escapeHtml(shortId)}...). Tripo is no longer connected, so this cannot be resumed. Dismiss to clear it.</p>
                <div class="kiri-history-actions">${dismissBtn}</div>`;
        }
        const previewBtn = `<button type="button" class="kiri-small-btn" data-action="preview-unsaved" data-history-id="${id}">Preview (not saved)</button>`;
        const resumeBtn = productId
            ? `<button type="button" class="kiri-small-btn primary" data-action="resume" data-history-id="${id}" ${isGenerating ? 'disabled' : ''}>Resume (no new credit)</button>`
            : '';

        // 'tripo_failed' is what older rows say; new rows say 'generator_failed'.
        if (entry.pendingStatus === 'generator_failed' || entry.pendingStatus === 'tripo_failed') {
            return `
                <p class="kiri-history-pending failed">Last generation failed at the 3D generator (Task ${escapeHtml(shortId)}...).</p>
                <div class="kiri-history-actions">${dismissBtn}</div>`;
        }

        const text = entry.pendingStatus === 'save_failed'
            ? `Paid generation not saved yet: ${entry.pendingError || 'unknown error'}`
            : 'Unfinished generation (the page may have closed). It is already paid for.';

        return `
            <p class="kiri-history-pending ${entry.pendingStatus === 'save_failed' ? 'failed' : ''}">${escapeHtml(text)} (Task ${escapeHtml(shortId)}...)</p>
            <div class="kiri-history-actions">${resumeBtn}${previewBtn}${dismissBtn}</div>`;
    }

    // Draws the list: ONE row per product (newest entry wins).
    // Called by BOTH live listeners, so it always reflects current inventory.
    function renderHistory() {
        const listEl = document.getElementById('historyList');

        if (historyError) {
            listEl.innerHTML = `<p class="text-xs py-4 text-center" style="color:#c62828;">Could not load history: ${escapeHtml(historyError)}</p>`;
            return;
        }
        if (!historyLoaded) return; // keep "Loading history..." until data arrives

        const seen = new Set();
        const rows = [];

        // historyDocs is newest-first, so the first row per product is the latest.
        historyDocs.forEach(entry => {
            const productId = findLinkedProductId(entry);
            const key = productId || entry.productId || `name:${historyDisplayName(entry, null).toLowerCase()}`;
            if (seen.has(key)) return;
            seen.add(key);

            const product = productId ? productMap[productId] : null;
            let metaClass = 'warn';
            let metaText = 'Product not found in this branch';
            if (product && productHasModel(product)) {
                metaClass = 'ok';
                metaText = '3D model ready';
            } else if (product) {
                metaText = 'No 3D model saved on this product yet';
            }

            const when = formatWhen(entry.createdAt);

            rows.push(`
                <div class="kiri-history-item" data-history-id="${escapeHtml(entry.id)}">
                    <img src="${escapeHtml(productImage(product))}" alt="">
                    <div style="flex:1; min-width:0;">
                        <p class="kiri-history-name">${escapeHtml(historyDisplayName(entry, productId))}</p>
                        <p class="kiri-history-meta ${metaClass}">${escapeHtml(metaText)}${when ? ' • ' + escapeHtml(when) : ''}</p>
                        ${pendingHtml(entry, productId)}
                    </div>
                    <i class="fa-solid fa-eye" style="color:var(--text-light);" title="Preview"></i>
                </div>`);
        });

        listEl.innerHTML = rows.length
            ? rows.join('')
            : '<p class="text-xs italic py-4 text-center" style="color: var(--text-light);">No generated models yet.</p>';

        listEl.querySelectorAll('img').forEach(img => {
            img.onerror = () => { img.onerror = null; img.src = PLACEHOLDER_IMG; };
        });
    }

    // Row click: select the product and preview ITS inventory model.
    function previewHistoryEntry(entryId) {
        const entry = historyDocs.find(e => e.id === entryId);
        if (!entry) return;

        const productId = findLinkedProductId(entry);
        if (!productId) {
            previewRequestId++; // cancel any preview still loading
            renderPreviewMessage(
                'fa-triangle-exclamation',
                `${historyDisplayName(entry, null)} is not in this branch's inventory.`,
                'The product may have been deleted or belongs to another branch.',
                true
            );
            return;
        }

        selectProduct(productId); // same path as the dropdown -> inventory model only
        document.getElementById('previewBox').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // "Resume" button on a row: finish the paid task for that row's product.
    function resumeFromHistory(entryId) {
        const entry = historyDocs.find(e => e.id === entryId);
        if (!entry || !entry.pendingTaskId) return;

        const productId = findLinkedProductId(entry);
        if (!productId) {
            alert('This product is not in the current branch, so the model cannot be attached here.');
            return;
        }

        selectProduct(productId);
        runJob(productId, entry.pendingTaskId);
    }

    // "Preview (not saved)" button on a row.
    function previewPendingFromHistory(entryId) {
        const entry = historyDocs.find(e => e.id === entryId);
        if (!entry || !entry.pendingTaskId) return;
        previewUnsavedTask(entry.pendingTaskId, historyDisplayName(entry, findLinkedProductId(entry)));
    }

    // "Dismiss" button: forget an unfinished task.
    async function dismissPending(entryId) {
        const entry = historyDocs.find(e => e.id === entryId);
        if (!entry) return;
        if (!confirm('Forget this unfinished generation? Copy its Task ID first if you might still want to recover it:\n\n' + entry.pendingTaskId)) return;

        const ref = db.collection('kiri_history').doc(entryId);
        const del = firebase.firestore.FieldValue.delete();
        try {
            if (entry.url) {
                // The row also records a real, saved model -> keep it, drop only the pending part.
                await ref.update({ pendingTaskId: del, pendingStatus: del, pendingError: del, pendingUpdatedAt: del });
            } else {
                // The row existed ONLY for this unfinished job -> remove it.
                await ref.delete();
            }
        } catch (e) {
            showError('Could not dismiss: ' + e.message + (hintForError(e.message) ? '\n\n' + hintForError(e.message) : ''));
        }
    }

    function loadHistory() {
        db.collection('kiri_history').orderBy('createdAt', 'desc').limit(HISTORY_FETCH_LIMIT).onSnapshot(snap => {
            historyError = '';
            historyLoaded = true;
            historyDocs = snap.docs.map(doc => ({ id: doc.id, ...doc.data() }));
            renderHistory();
        }, err => {
            console.error('History load failed:', err);
            historyError = err.message;
            renderHistory();
        });
    }

    // ---------------------------------------------------------------
    // Wiring (runs once the page HTML exists)
    // ---------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof db === 'undefined') {
            renderPreviewMessage('fa-triangle-exclamation', 'Firebase is not configured.', 'Check firebase-applet-config.json.', true);
            return;
        }

        const selectedImage = document.getElementById('selectedImage');
        selectedImage.src = PLACEHOLDER_IMG;
        selectedImage.onerror = () => { selectedImage.src = PLACEHOLDER_IMG; };

        document.getElementById('dropdownButton').addEventListener('click', () => {
            document.getElementById('dropdownMenu').classList.toggle('open');
        });

        document.getElementById('dropdownMenu').addEventListener('click', (e) => {
            const item = e.target.closest('[data-product-id]');
            if (item) selectProduct(item.dataset.productId);
        });

        window.addEventListener('click', (e) => {
            if (!document.getElementById('customDropdown').contains(e.target)) {
                document.getElementById('dropdownMenu').classList.remove('open');
            }
        });

        document.getElementById('dropzone').addEventListener('click', () => {
            document.getElementById('photoInput').click();
        });

        document.getElementById('photoInput').addEventListener('change', (e) => {
            selectedFile = e.target.files[0] || null;
            if (selectedFile) {
                const reader = new FileReader();
                reader.onload = (ev) => {
                    document.getElementById('dropzoneContent').innerHTML =
                        `<img src="${ev.target.result}" style="max-height:140px; max-width:100%; border-radius:12px; object-fit:contain;">`;
                };
                reader.readAsDataURL(selectedFile);
            }
            updateGenerateButtonState();
        });

        document.getElementById('generateBtn').addEventListener('click', () => runJob(selectedProductId, null));
        document.getElementById('recoverBtn').addEventListener('click', recoverFromInput);
        document.getElementById('previewTaskBtn').addEventListener('click', () => {
            previewUnsavedTask(document.getElementById('recoverTaskId').value.trim());
        });

        document.getElementById('previewErrorBtn').addEventListener('click', () => {
            if (errorTaskId) previewUnsavedTask(errorTaskId, errorTaskLabel);
        });
        document.getElementById('copyErrorBtn').addEventListener('click', async () => {
            const text = document.getElementById('errorText').innerText;
            try {
                await navigator.clipboard.writeText(text);
                document.getElementById('copyErrorBtn').innerText = 'Copied!';
                setTimeout(() => { document.getElementById('copyErrorBtn').innerText = 'Copy details'; }, 1500);
            } catch (_) {
                alert(text); // clipboard blocked -> show it so it can be copied manually
            }
        });
        document.getElementById('closeErrorBtn').addEventListener('click', clearError);

        // One listener for the whole history list (event delegation).
        // Buttons are checked FIRST so clicking one doesn't also count as a row click.
        document.getElementById('historyList').addEventListener('click', (e) => {
            const actionBtn = e.target.closest('[data-action]');
            if (actionBtn) {
                if (actionBtn.disabled) return;
                const id = actionBtn.dataset.historyId;
                const action = actionBtn.dataset.action;
                if (action === 'resume') resumeFromHistory(id);
                if (action === 'preview-unsaved') previewPendingFromHistory(id);
                if (action === 'dismiss') dismissPending(id);
                return;
            }

            const item = e.target.closest('[data-history-id]');
            if (item) previewHistoryEntry(item.dataset.historyId);
        });

        loadInventory();
        loadHistory();
    });
</script>

<?php include 'templates/footer.php'; ?>