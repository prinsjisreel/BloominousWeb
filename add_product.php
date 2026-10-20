<?php
/**
 * BLOOMINOUS - Add Product (Firebase Spoke)
 *
 * - Follows the admin theme (light + dark) through header.php's CSS variables.
 * - The admin picks one or more BRANCHES (searchable dropdown + chips).
 *   The product is created in every selected branch's inventory:
 *     branches/{branchId}/inventory/{productId}
 *   using the SAME product id and the SAME product code in each branch.
 * - Product code is auto-assigned (BLOOM-001, BLOOM-002, ...). The number is
 *   reserved inside ONE Firestore transaction together with all the product
 *   documents, so it is all-or-nothing and two admins can never get the
 *   same code.
 * - 1 to 5 product photos are REQUIRED. Each photo is uploaded ONCE to
 *   Firebase Storage first; if Firebase Storage fails, Cloudinary is used.
 *   EVERY upload gets its own random id (Cloudinary public_id / Storage
 *   file name), so a photo can never overwrite another product's photo.
 *   The URLs are saved as:
 *     image  : first photo (what shop, POS and BloominousApp already read)
 *     images : every photo, in the order the admin arranged them
 * - 3D models are NOT set here (3D Realism Hub handles them);
 *   `model: ""` is still written so the document shape matches the app.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

// Cloudinary fallback settings live in config.local.php (never in this file).
$configPath = __DIR__ . '/config.local.php';
if (file_exists($configPath)) {
    require_once $configPath;
}
$cloudinaryConfig = [
    'cloudName'    => getenv('CLOUDINARY_CLOUD_NAME') ?: '',
    'uploadPreset' => getenv('CLOUDINARY_UPLOAD_PRESET') ?: '',
];

include 'templates/header.php';
?>

<!-- Firebase Storage is not loaded by header.php, so this page adds it. -->
<script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-storage-compat.js"></script>

<style>
    .add-content { padding: 1.5rem; max-width: 800px; margin: 0 auto; }

    .form-card {
        background: var(--surface);
        border-radius: 30px;
        padding: 3rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.02);
        border: 1px solid var(--border-color);
    }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .span-2 { grid-column: span 2; }

    .form-group { margin-bottom: 1.5rem; }
    .form-group label { display: block; font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 8px; letter-spacing: 1px; }
    .form-group label .required { color: var(--primary); }

    .form-control {
        width: 100%; padding: 14px 18px;
        border: 1px solid var(--border-color); border-radius: 12px;
        outline: none; transition: 0.3s;
        background: var(--surface-alt); color: var(--text-main);
        font-weight: 500; font-size: 0.9rem;
    }
    .form-control:focus { border-color: var(--primary); background: var(--surface); box-shadow: 0 0 15px rgba(245, 158, 11, 0.08); }
    .form-control::placeholder { color: var(--text-light); opacity: 0.7; }

    /* Auto-generated SKU: read-only, monospace, with a lock icon */
    .sku-wrap { position: relative; }
    .sku-wrap .form-control[readonly] { cursor: not-allowed; font-family: 'Courier New', monospace; font-weight: 800; letter-spacing: 1.5px; padding-right: 44px; }
    .sku-wrap .sku-lock { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: var(--text-light); font-size: 0.85rem; }

    .field-hint { color: var(--text-light); font-size: 0.7rem; margin-top: 6px; display: block; line-height: 1.5; }

    /* ---------- Branch picker ---------- */
    .branch-picker { position: relative; }
    .branch-input-wrap { position: relative; }
    .branch-input-wrap .form-control { padding-left: 44px; padding-right: 44px; }
    .branch-input-wrap .branch-icon { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--text-light); font-size: 0.85rem; pointer-events: none; }
    .branch-input-wrap .branch-caret { position: absolute; right: 18px; top: 50%; transform: translateY(-50%); color: var(--text-light); font-size: 0.7rem; pointer-events: none; }

    .branch-menu {
        display: none; position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 50;
        max-height: 240px; overflow-y: auto;
        background: var(--surface); border: 1px solid var(--border-color); border-radius: 14px;
        box-shadow: 0 15px 30px rgba(0,0,0,0.12);
    }
    .branch-menu.open { display: block; }
    .branch-option {
        display: flex; align-items: center; gap: 12px; width: 100%;
        padding: 12px 16px; border: none; background: none; cursor: pointer; text-align: left;
        color: var(--text-main); font-size: 0.85rem; font-weight: 700;
        border-bottom: 1px solid var(--border-color);
    }
    .branch-option:last-child { border-bottom: none; }
    .branch-option:hover, .branch-option.is-active { background: rgba(245, 158, 11, 0.1); color: var(--primary); }
    .branch-option small { display: block; color: var(--text-light); font-weight: 600; font-size: 0.68rem; }
    .branch-option i { color: var(--primary); }
    .branch-empty { padding: 16px; text-align: center; color: var(--text-light); font-size: 0.78rem; font-weight: 600; }

    .branch-meta { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; }
    .link-btn { background: none; border: none; cursor: pointer; color: var(--primary); font-weight: 800; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; }
    .link-btn:hover { text-decoration: underline; }

    .branch-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; min-height: 8px; }
    .branch-chip {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 8px 8px 14px; border-radius: 50px;
        background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.35);
        color: var(--primary); font-size: 0.75rem; font-weight: 800;
    }
    .branch-chip button {
        width: 20px; height: 20px; border-radius: 50%; border: none; cursor: pointer;
        background: rgba(245, 158, 11, 0.25); color: var(--primary); font-size: 0.6rem;
        display: flex; align-items: center; justify-content: center;
    }
    .branch-chip button:hover { background: #DC2626; color: #fff; }

    /* ---------- Image picker ---------- */
    .dropzone {
        border: 2px dashed var(--border-color); border-radius: 18px;
        padding: 2rem 1rem; text-align: center; cursor: pointer;
        background: var(--surface-alt); color: var(--text-light);
        transition: 0.25s; font-weight: 700; font-size: 0.85rem;
    }
    .dropzone i { font-size: 1.6rem; display: block; margin-bottom: 8px; }
    .dropzone:hover, .dropzone.dragover { border-color: var(--primary); color: var(--primary); }
    .dropzone.is-full { opacity: 0.5; cursor: not-allowed; }
    .dropzone.is-full:hover { border-color: var(--border-color); color: var(--text-light); }

    .image-meta { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; }
    .image-count { font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1px; }

    .image-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-top: 12px; }
    .image-tile { position: relative; aspect-ratio: 1 / 1; border-radius: 14px; overflow: hidden; border: 1px solid var(--border-color); background: var(--surface-alt); }
    .image-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .image-tile .remove-btn {
        position: absolute; top: 6px; right: 6px;
        width: 26px; height: 26px; border-radius: 50%;
        border: none; cursor: pointer;
        background: rgba(0,0,0,0.6); color: #fff; font-size: 0.75rem;
        display: flex; align-items: center; justify-content: center;
    }
    .image-tile .remove-btn:hover { background: #DC2626; }
    .image-tile .cover-badge {
        position: absolute; left: 6px; bottom: 6px;
        background: var(--primary); color: #fff;
        font-size: 0.55rem; font-weight: 900; text-transform: uppercase; letter-spacing: 1px;
        padding: 3px 8px; border-radius: 50px;
    }

    /* Messages */
    .form-error {
        display: none; margin-bottom: 1.5rem; padding: 12px 16px; border-radius: 12px;
        background: rgba(220, 38, 38, 0.08); color: #DC2626;
        border: 1px solid rgba(220, 38, 38, 0.2);
        font-size: 0.8rem; font-weight: 700; line-height: 1.5;
    }
    .upload-status { display: none; margin-top: 12px; text-align: center; font-size: 0.75rem; font-weight: 700; color: var(--text-light); }

    #saveBtn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

    .back-link { display: inline-flex; align-items: center; gap: 10px; color: var(--text-light); text-decoration: none; font-weight: 700; margin-bottom: 2rem; transition: 0.3s; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; }
    .back-link:hover { color: var(--primary); transform: translateX(-5px); }

    @media (max-width: 640px) {
        .form-card { padding: 1.75rem; }
        .form-grid { grid-template-columns: 1fr; }
        .span-2 { grid-column: auto; }
        .image-grid { grid-template-columns: repeat(3, 1fr); }
    }

    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>. */
    html[data-theme="dark"] .form-card { box-shadow: none; }
    html[data-theme="dark"] .branch-menu { box-shadow: 0 15px 30px rgba(0,0,0,0.45); }
    html[data-theme="dark"] .add-content select.form-control { color-scheme: dark; }
    html[data-theme="dark"] .form-error { background: rgba(220, 38, 38, 0.15); color: #fca5a5; border-color: rgba(220, 38, 38, 0.35); }
    html[data-theme="dark"] .add-content :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] .add-content :is(.text-gray-500, .text-gray-400) { color: var(--text-light); }
</style>

<div class="add-content">
    <a href="product_management.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> <span>Catalog Overview</span>
    </a>

    <div class="mb-10">
        <h1 class="brand-font text-4xl font-black text-gray-800">New Artifact Enrollment</h1>
        <p class="text-gray-400 text-sm font-medium mt-1">Populate your collection with artisan floral arrangements.</p>
    </div>

    <div class="form-card">
        <div id="formError" class="form-error" role="alert"></div>

        <form id="addProductForm" novalidate>
            <div class="form-grid">
                <div class="form-group span-2">
                    <label for="name">Designation / Name <span class="required">*</span></label>
                    <input type="text" id="name" class="form-control" placeholder="e.g. Red Rose Bouquet" maxlength="120" required>
                </div>

                <!-- Branch picker: searchable dropdown + chips -->
                <div class="form-group span-2">
                    <label for="branchSearch">Available Branches <span class="required">*</span></label>
                    <div class="branch-picker" id="branchPicker">
                        <div class="branch-input-wrap">
                            <i class="fa-solid fa-store branch-icon"></i>
                            <input type="text" id="branchSearch" class="form-control" placeholder="Loading branches..." autocomplete="off" disabled>
                            <i class="fa-solid fa-chevron-down branch-caret"></i>
                        </div>
                        <div id="branchMenu" class="branch-menu" role="listbox"></div>
                    </div>
                    <div class="branch-meta">
                        <span id="branchCount" class="image-count">0 branches selected</span>
                        <button type="button" id="selectAllBranches" class="link-btn">Select all branches</button>
                    </div>
                    <div id="branchChips" class="branch-chips"></div>
                    <small class="field-hint">The product is added to the inventory of every branch listed here, so you don't have to add it branch by branch.</small>
                </div>

                <div class="form-group">
                    <label for="category">Category <span class="required">*</span></label>
                    <select id="category" class="form-control" required>
                        <option value="">Select Category</option>
                        <option value="Bouquets">Bouquets</option>
                        <option value="Flower Baskets">Flower Baskets</option>
                        <option value="Box Arrangements">Box Arrangements</option>
                        <option value="Sympathy Flowers">Sympathy Flowers</option>
                        <option value="Wedding Flowers">Wedding Flowers</option>
                        <option value="Single Stems">Single Stems</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="barcode">Product Code / SKU</label>
                    <div class="sku-wrap">
                        <input type="text" id="barcode" class="form-control" value="Generating..." readonly tabindex="-1">
                        <i class="fa-solid fa-lock sku-lock"></i>
                    </div>
                    <small id="skuHint" class="field-hint">Assigned automatically when you save.</small>
                </div>

                <div class="form-group">
                    <label for="price">Unit Price (₱) <span class="required">*</span></label>
                    <input type="number" step="0.01" min="0" id="price" class="form-control" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label for="stock">Initial Stock Quantity <span class="required">*</span></label>
                    <input type="number" step="1" min="0" id="stock" class="form-control" placeholder="0" required>
                    <small class="field-hint">Applied to each selected branch. Adjust per branch later in Inventory.</small>
                </div>

                <div class="form-group span-2">
                    <label>Product Images <span class="required">*</span></label>
                    <input type="file" id="imageInput" accept="image/jpeg,image/png,image/webp" multiple hidden>
                    <div id="dropzone" class="dropzone" role="button" tabindex="0">
                        <i class="fa-solid fa-images"></i>
                        <span id="dropzoneText">Click or drag photos here (JPG, PNG, WEBP)</span>
                    </div>
                    <div class="image-meta">
                        <span id="imageCount" class="image-count">0 / 5 images</span>
                        <small class="field-hint" style="margin-top:0;">The first photo is the cover shown in the shop.</small>
                    </div>
                    <div id="imageGrid" class="image-grid"></div>
                </div>
            </div>

            <button type="submit" id="saveBtn" class="btn-primary w-full py-5 text-sm uppercase tracking-widest mt-2" style="justify-content:center;">
                <i class="fa-solid fa-cloud-arrow-up mr-2"></i> Commit to Inventory
            </button>
            <p id="uploadStatus" class="upload-status"></p>
        </form>
    </div>
</div>

<script>
    // ---------- Settings ----------
    const MAX_IMAGES = 5;
    const MAX_FILE_BYTES = 10 * 1024 * 1024;           // 10 MB per original photo
    const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const COMPRESS_MAX_DIMENSION = 1280;                // longest side after resizing
    const COMPRESS_QUALITY = 0.82;
    const FIREBASE_UPLOAD_TIMEOUT_MS = 20000;           // give up on Firebase after 20s
    const SKU_PREFIX = 'BLOOM-';
    const SKU_PAD = 3;                                  // BLOOM-001
    // 1-6 digits only: ignores old timestamp codes like BLOOM-1729580000000.
    const SKU_PATTERN = /^BLOOM-(\d{1,6})$/;
    const SAVE_BTN_HTML = '<i class="fa-solid fa-cloud-arrow-up mr-2"></i> Commit to Inventory';
    const CLOUDINARY = <?php echo json_encode($cloudinaryConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // ---------- Page state ----------
    let selectedImages = [];          // [{ file: File, previewUrl: string }]
    let firebaseStorageUsable = true; // flips to false after the first Firebase failure
    let allBranches = [];             // [{ id, name }] loaded from Firestore
    let selectedBranchIds = [];       // ids the admin picked, in pick order
    let activeOptionIndex = -1;       // keyboard highlight inside the dropdown
    const highestSkuCache = new Map(); // branchId -> highest BLOOM-### already used there

    // ---------- Elements ----------
    const form = document.getElementById('addProductForm');
    const nameInput = document.getElementById('name');
    const categoryInput = document.getElementById('category');
    const skuInput = document.getElementById('barcode');
    const skuHint = document.getElementById('skuHint');
    const priceInput = document.getElementById('price');
    const stockInput = document.getElementById('stock');
    const imageInput = document.getElementById('imageInput');
    const dropzone = document.getElementById('dropzone');
    const dropzoneText = document.getElementById('dropzoneText');
    const imageGrid = document.getElementById('imageGrid');
    const imageCount = document.getElementById('imageCount');
    const saveBtn = document.getElementById('saveBtn');
    const uploadStatus = document.getElementById('uploadStatus');
    const formError = document.getElementById('formError');
    const branchPicker = document.getElementById('branchPicker');
    const branchSearch = document.getElementById('branchSearch');
    const branchMenu = document.getElementById('branchMenu');
    const branchChips = document.getElementById('branchChips');
    const branchCount = document.getElementById('branchCount');
    const selectAllBranchesBtn = document.getElementById('selectAllBranches');

    // ---------- Small helpers ----------
    function showError(message) {
        console.error("Bloominous Error:", message);
        formError.textContent = message;
        formError.style.display = 'block';
        formError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function hideError() {
        formError.style.display = 'none';
        formError.textContent = '';
    }

    function setBusy(isBusy, statusText = '') {
        saveBtn.disabled = isBusy;
        saveBtn.innerHTML = isBusy
            ? '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Saving...'
            : SAVE_BTN_HTML;
        uploadStatus.textContent = statusText;
        uploadStatus.style.display = statusText ? 'block' : 'none';
    }

    function extensionFor(type) {
        return { 'image/png': 'png', 'image/webp': 'webp' }[type] || 'jpg';
    }

    // A random id that is different for every single upload. Used as the
    // Cloudinary public_id and the Storage file name, so one photo can
    // never overwrite another product's photo.
    function newUniqueId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().replace(/-/g, '');
        }
        return Date.now().toString(36) + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 12);
    }

    function branchNameOf(id) {
        const branch = allBranches.find(b => b.id === id);
        return branch ? branch.name : id;
    }

    // ---------- Firestore paths ----------
    function branchInventory(branchId) {
        return db.collection('branches').doc(branchId).collection('inventory');
    }

    function skuCounterRef(branchId) {
        return db.collection('counters').doc('sku_' + branchId);
    }

    // ---------- Auto SKU ----------
    function formatSku(number) {
        return SKU_PREFIX + String(number).padStart(SKU_PAD, '0');
    }

    // Highest BLOOM-### number already used in ONE branch's inventory.
    // `force` skips the cache (used right before saving).
    async function getHighestSkuNumber(branchId, force = false) {
        if (!force && highestSkuCache.has(branchId)) {
            return highestSkuCache.get(branchId);
        }
        const snap = await branchInventory(branchId).get();
        let highest = 0;
        snap.forEach(doc => {
            const match = SKU_PATTERN.exec(String(doc.data().code || '').toUpperCase());
            if (match) {
                highest = Math.max(highest, parseInt(match[1], 10));
            }
        });
        highestSkuCache.set(branchId, highest);
        return highest;
    }

    // Shows the NEXT code as a preview. The real number is reserved on save.
    // The same code goes to every selected branch, so the preview takes the
    // highest number found across ALL selected branches.
    async function previewNextSku() {
        if (selectedBranchIds.length === 0) {
            skuInput.value = SKU_PREFIX + '...';
            skuHint.textContent = 'Select at least one branch to see the next code.';
            return;
        }
        try {
            const numbers = await Promise.all(selectedBranchIds.map(async (branchId) => {
                const [highest, counterDoc] = await Promise.all([
                    getHighestSkuNumber(branchId),
                    skuCounterRef(branchId).get()
                ]);
                const counterValue = counterDoc.exists ? (counterDoc.data().current || 0) : 0;
                return Math.max(highest, counterValue);
            }));
            skuInput.value = formatSku(Math.max(...numbers) + 1);
            skuHint.textContent = 'Assigned automatically when you save. If another admin saves first, the next free code is used.';
        } catch (err) {
            console.warn('Could not preview the next SKU:', err);
            skuInput.value = SKU_PREFIX + '...';
            skuHint.textContent = 'The code will be assigned when you save.';
        }
    }

    // ---------- Branch picker ----------
    function unselectedBranches(filterText) {
        const q = (filterText || '').trim().toLowerCase();
        return allBranches.filter(b =>
            !selectedBranchIds.includes(b.id) &&
            (q === '' || b.name.toLowerCase().includes(q) || b.id.toLowerCase().includes(q))
        );
    }

    function renderBranchMenu() {
        const matches = unselectedBranches(branchSearch.value);
        branchMenu.innerHTML = '';
        activeOptionIndex = matches.length > 0 ? 0 : -1;

        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'branch-empty';
            empty.textContent = allBranches.length === 0
                ? 'No branches found in the database.'
                : (selectedBranchIds.length === allBranches.length ? 'All branches are selected.' : 'No branch matches your search.');
            branchMenu.appendChild(empty);
            return;
        }

        matches.forEach((branch, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'branch-option' + (index === activeOptionIndex ? ' is-active' : '');
            option.setAttribute('role', 'option');
            option.dataset.id = branch.id;

            const icon = document.createElement('i');
            icon.className = 'fa-solid fa-plus';
            const label = document.createElement('span');
            label.textContent = branch.name;
            const small = document.createElement('small');
            small.textContent = branch.id;
            label.appendChild(small);

            option.appendChild(icon);
            option.appendChild(label);
            // mousedown (not click) so it fires before the input loses focus
            option.addEventListener('mousedown', (e) => { e.preventDefault(); addBranch(branch.id); });
            branchMenu.appendChild(option);
        });
    }

    function openBranchMenu() {
        if (allBranches.length === 0) return;
        renderBranchMenu();
        branchMenu.classList.add('open');
    }

    function closeBranchMenu() {
        branchMenu.classList.remove('open');
    }

    function highlightOption(newIndex) {
        const options = branchMenu.querySelectorAll('.branch-option');
        if (options.length === 0) return;
        activeOptionIndex = (newIndex + options.length) % options.length;
        options.forEach((el, i) => el.classList.toggle('is-active', i === activeOptionIndex));
        options[activeOptionIndex].scrollIntoView({ block: 'nearest' });
    }

    function renderBranchChips() {
        branchChips.innerHTML = '';
        selectedBranchIds.forEach(id => {
            const chip = document.createElement('span');
            chip.className = 'branch-chip';

            const text = document.createElement('span');
            text.textContent = branchNameOf(id);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.title = 'Remove branch';
            removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            removeBtn.addEventListener('click', () => removeBranch(id));

            chip.appendChild(text);
            chip.appendChild(removeBtn);
            branchChips.appendChild(chip);
        });

        const count = selectedBranchIds.length;
        branchCount.textContent = `${count} ${count === 1 ? 'branch' : 'branches'} selected`;
        selectAllBranchesBtn.style.display = (allBranches.length > 0 && count < allBranches.length) ? 'inline' : 'none';
    }

    // Runs after ANY change to the selection.
    function onBranchesChanged() {
        renderBranchChips();
        if (branchMenu.classList.contains('open')) renderBranchMenu();
        previewNextSku();
    }

    function addBranch(id) {
        if (!selectedBranchIds.includes(id)) {
            selectedBranchIds.push(id);
        }
        branchSearch.value = '';
        hideError();
        onBranchesChanged();
        branchSearch.focus();
    }

    function removeBranch(id) {
        selectedBranchIds = selectedBranchIds.filter(x => x !== id);
        onBranchesChanged();
    }

    branchSearch.addEventListener('focus', openBranchMenu);
    branchSearch.addEventListener('input', openBranchMenu);
    branchSearch.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); openBranchMenu(); highlightOption(activeOptionIndex + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); openBranchMenu(); highlightOption(activeOptionIndex - 1); }
        else if (e.key === 'Escape') { closeBranchMenu(); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            const active = branchMenu.querySelector('.branch-option.is-active');
            if (active) addBranch(active.dataset.id);
        }
    });
    selectAllBranchesBtn.addEventListener('click', () => {
        selectedBranchIds = allBranches.map(b => b.id);
        branchSearch.value = '';
        hideError();
        closeBranchMenu();
        onBranchesChanged();
    });
    document.addEventListener('click', (e) => {
        if (!branchPicker.contains(e.target)) closeBranchMenu();
    });

    // Loads branches immediately. Firestore rules allow public read for branches.
    async function loadBranches() {
        console.log("Bloominous: Attempting to load branches from Firestore...");
        try {
            const snap = await db.collection('branches').get();
            console.log("Bloominous: Successfully fetched branches:", snap.docs.length);
            
            allBranches = snap.docs
                .map(doc => ({ id: doc.id, name: doc.data().name || doc.id }))
                .sort((a, b) => a.name.localeCompare(b.name));

            // Start with the branch the admin is currently working in.
            if (allBranches.some(b => b.id === window.currentBranch)) {
                selectedBranchIds = [window.currentBranch];
            }

            branchSearch.disabled = false;
            branchSearch.placeholder = 'Search or choose a branch...';
            onBranchesChanged();
        } catch (err) {
            console.error('Bloominous Firestore Error:', err);
            branchSearch.placeholder = 'Could not load branches';
            showError('Could not load the branch list. Check your internet or Firestore permissions. (' + err.message + ')');
        }
    }

    // FIX: Load branches immediately.
    // Your Firestore rules allow public read for branches (allow read: if true;),
    // so we don't need to wait for Firebase Auth to finish loading before
    // populating the dropdown. This stops the page from freezing on
    // "Loading branches..." if the Auth token is slow or missing.
    console.log("Bloominous: Triggering immediate branch load...");
    loadBranches();

    // ---------- Image picking ----------
    function addFiles(fileList) {
        hideError();
        const incoming = Array.from(fileList || []);
        const room = MAX_IMAGES - selectedImages.length;

        if (room <= 0) {
            showError(`You can attach up to ${MAX_IMAGES} images. Remove one to add another.`);
            return;
        }

        const skipped = [];
        incoming.slice(0, room).forEach(file => {
            if (!ALLOWED_TYPES.includes(file.type)) {
                skipped.push(`${file.name} (only JPG, PNG or WEBP)`);
                return;
            }
            if (file.size > MAX_FILE_BYTES) {
                skipped.push(`${file.name} (larger than 10 MB)`);
                return;
            }
            selectedImages.push({ file, previewUrl: URL.createObjectURL(file) });
        });

        if (incoming.length > room) {
            skipped.push(`${incoming.length - room} extra file(s), the limit is ${MAX_IMAGES}`);
        }
        if (skipped.length) {
            showError('Skipped: ' + skipped.join('; '));
        }
        renderImages();
    }

    function removeImage(index) {
        URL.revokeObjectURL(selectedImages[index].previewUrl);
        selectedImages.splice(index, 1);
        renderImages();
    }

    function renderImages() {
        imageGrid.innerHTML = '';

        selectedImages.forEach((entry, index) => {
            const tile = document.createElement('div');
            tile.className = 'image-tile';

            const img = document.createElement('img');
            img.src = entry.previewUrl;
            img.alt = entry.file.name;
            tile.appendChild(img);

            if (index === 0) {
                const badge = document.createElement('span');
                badge.className = 'cover-badge';
                badge.textContent = 'Cover';
                tile.appendChild(badge);
            }

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-btn';
            removeBtn.title = 'Remove photo';
            removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            removeBtn.addEventListener('click', () => removeImage(index));
            tile.appendChild(removeBtn);

            imageGrid.appendChild(tile);
        });

        const isFull = selectedImages.length >= MAX_IMAGES;
        imageCount.textContent = `${selectedImages.length} / ${MAX_IMAGES} images`;
        dropzone.classList.toggle('is-full', isFull);
        dropzoneText.textContent = isFull
            ? 'Image limit reached'
            : 'Click or drag photos here (JPG, PNG, WEBP)';
        imageInput.value = ''; // lets the same file be picked again after removal
    }

    dropzone.addEventListener('click', () => {
        if (selectedImages.length < MAX_IMAGES) imageInput.click();
    });
    dropzone.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && selectedImages.length < MAX_IMAGES) {
            e.preventDefault();
            imageInput.click();
        }
    });
    imageInput.addEventListener('change', (e) => addFiles(e.target.files));

    ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, (e) => {
        e.preventDefault();
        if (selectedImages.length < MAX_IMAGES) dropzone.classList.add('dragover');
    }));
    ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, (e) => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
    }));
    dropzone.addEventListener('drop', (e) => addFiles(e.dataTransfer.files));

    // ---------- Image processing + upload ----------

    // Resizes a photo so its longest side is at most 1280px and saves it as
    // JPEG. Keeps the original if resizing fails or doesn't make it smaller.
    function compressImage(file) {
        return new Promise((resolve) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                URL.revokeObjectURL(url);
                const scale = Math.min(1, COMPRESS_MAX_DIMENSION / Math.max(img.width, img.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(img.width * scale);
                canvas.height = Math.round(img.height * scale);
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#ffffff'; // transparent PNG areas become white, not black
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(
                    (blob) => resolve(blob && blob.size < file.size ? blob : file),
                    'image/jpeg',
                    COMPRESS_QUALITY
                );
            };
            img.onerror = () => {
                URL.revokeObjectURL(url);
                resolve(file);
            };
            img.src = url;
        });
    }

    function uploadToFirebase(blob, path) {
        return new Promise((resolve, reject) => {
            const task = firebase.storage().ref(path).put(blob, { contentType: blob.type });
            // Firebase retries for minutes on some errors; stop waiting after 20s.
            const timer = setTimeout(() => {
                task.cancel();
                reject(new Error('Firebase Storage did not respond in time.'));
            }, FIREBASE_UPLOAD_TIMEOUT_MS);

            task.then(snapshot => snapshot.ref.getDownloadURL())
                .then(url => { clearTimeout(timer); resolve(url); })
                .catch(err => { clearTimeout(timer); reject(err); });
        });
    }

    // `uniqueId` is sent as the Cloudinary public_id. Without it, an upload
    // preset that "uses the file name" would store every photo under the
    // same name and each new upload would overwrite the previous one.
    async function uploadToCloudinary(blob, uniqueId) {
        if (!CLOUDINARY.cloudName || !CLOUDINARY.uploadPreset) {
            throw new Error('Image storage is unavailable: Firebase Storage failed and Cloudinary is not configured in config.local.php.');
        }
        const formData = new FormData();
        formData.append('file', blob, `${uniqueId}.${extensionFor(blob.type)}`);
        formData.append('upload_preset', CLOUDINARY.uploadPreset);
        formData.append('folder', 'bloominous/products');
        formData.append('public_id', uniqueId);

        const response = await fetch(
            `https://api.cloudinary.com/v1_1/${encodeURIComponent(CLOUDINARY.cloudName)}/image/upload`,
            { method: 'POST', body: formData }
        );

        let result = {};
        try { result = await response.json(); } catch (_) { result = {}; }

        if (!response.ok || !result.secure_url) {
            const reason = (result.error && result.error.message) || `HTTP ${response.status}`;
            throw new Error('Cloudinary upload failed: ' + reason);
        }
        return result.secure_url;
    }

    // Firebase Storage first; after its first failure, Cloudinary for the rest.
    // Photos are uploaded ONCE and shared by every selected branch.
    async function uploadImage(blob) {
        const uniqueId = newUniqueId();
        const path = `products/${uniqueId}.${extensionFor(blob.type)}`;

        if (firebaseStorageUsable && typeof firebase.storage === 'function') {
            try {
                const url = await uploadToFirebase(blob, path);
                return { url, provider: 'firebase' };
            } catch (err) {
                console.warn('Firebase Storage failed, switching to Cloudinary:', err);
                firebaseStorageUsable = false;
            }
        }

        const url = await uploadToCloudinary(blob, uniqueId);
        return { url, provider: 'cloudinary' };
    }

    // ---------- Save ----------
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideError();

        const name = nameInput.value.trim();
        const category = categoryInput.value;
        const price = parseFloat(priceInput.value);
        const stock = Number(stockInput.value);
        const branchIds = selectedBranchIds.slice(); // snapshot: can't change mid-save

        if (!name) { showError('Please enter a product name.'); nameInput.focus(); return; }
        if (branchIds.length === 0) { showError('Please select at least one branch for this product.'); branchSearch.focus(); return; }
        if (!category) { showError('Please choose a category.'); categoryInput.focus(); return; }
        if (!Number.isFinite(price) || price < 0) { showError('Please enter a valid unit price (0 or more).'); priceInput.focus(); return; }
        if (stockInput.value === '' || !Number.isInteger(stock) || stock < 0) { showError('Initial stock must be a whole number (0 or more).'); stockInput.focus(); return; }
        if (selectedImages.length === 0) { showError('Please attach at least one product image.'); return; }

        const user = firebase.auth().currentUser;
        if (!user) {
            showError('Your session is still loading. Please wait a moment and try again.');
            return;
        }

        setBusy(true, 'Preparing images...');

        try {
            // 1. Upload every photo ONCE, in the order shown.
            const uploaded = [];
            for (let i = 0; i < selectedImages.length; i++) {
                setBusy(true, `Uploading image ${i + 1} of ${selectedImages.length}...`);
                const blob = await compressImage(selectedImages[i].file);
                uploaded.push(await uploadImage(blob));
            }
            const imageUrls = uploaded.map(u => u.url);
            console.log('Saved image URLs for "' + name + '":', imageUrls);

            // 2. Reserve the SKU and create the product in EVERY selected
            //    branch inside ONE transaction (all-or-nothing).
            setBusy(true, `Adding product to ${branchIds.length} ${branchIds.length === 1 ? 'branch' : 'branches'}...`);

            // Fresh scan of each branch's existing codes (cache skipped).
            const highestPerBranch = await Promise.all(branchIds.map(id => getHighestSkuNumber(id, true)));
            const highestExisting = Math.max(0, ...highestPerBranch);

            // One id for the product, reused in every branch, so the same
            // product can be recognised across branches.
            const productId = db.collection('branches').doc().id;
            const serverTime = firebase.firestore.FieldValue.serverTimestamp();

            const code = await db.runTransaction(async (transaction) => {
                // READS first (Firestore rule: all reads before any write).
                const counterSnaps = await Promise.all(
                    branchIds.map(id => transaction.get(skuCounterRef(id)))
                );
                const highestCounter = Math.max(
                    0,
                    ...counterSnaps.map(snap => (snap.exists ? (snap.data().current || 0) : 0))
                );

                // One number for all branches: above every counter AND every existing code.
                const nextNumber = Math.max(highestCounter, highestExisting) + 1;
                const sku = formatSku(nextNumber);

                // WRITES: a counter update + a product document per branch.
                branchIds.forEach(branchId => {
                    transaction.set(skuCounterRef(branchId), {
                        current: nextNumber,
                        branchId: branchId,
                        updatedAt: serverTime
                    }, { merge: true });

                    transaction.set(branchInventory(branchId).doc(productId), {
                        name: name,
                        category: category,
                        code: sku,
                        price: price,
                        stock: stock,
                        image: imageUrls[0],             // cover: already read by shop, POS, app
                        images: imageUrls,               // full gallery (max 5)
                        imageProviders: uploaded.map(u => u.provider),
                        branchId: branchId,              // the branch THIS document lives in
                        availableBranchIds: branchIds,   // every branch it was added to (snapshot at creation)
                        createdAt: serverTime,
                        updatedAt: serverTime,
                        model: ''                        // 3D model is added later via 3D Realism Hub
                    });
                });

                return sku;
            });

            skuInput.value = code;
            const branchNames = branchIds.map(branchNameOf);

            // 3. Accountability record: same action name/shape as the
            // Flutter app's inventory_item_created.
            try {
                await db.collection('admin_actions').add({
                    actorUid: user.uid,
                    actorEmail: window.currentUserEmail || null,
                    actorRole: window.currentUserRole || null,
                    action: 'inventory_item_created',
                    targetUid: productId,
                    targetEmail: null,
                    details: `Created "${name}" (${code}) at ₱${price.toFixed(2)}, initial stock ${stock} per branch, ${imageUrls.length} image(s). Branches: ${branchNames.join(', ')}.`,
                    timestamp: firebase.firestore.FieldValue.serverTimestamp()
                });
            } catch (auditError) {
                console.warn('Audit log write failed (product still created):', auditError);
            }

            selectedImages.forEach(entry => URL.revokeObjectURL(entry.previewUrl));
            alert(`Product added successfully to ${branchIds.length} branch(es)!`);
            window.location.href = 'product_management.php';

        } catch (err) {
            console.error(err);
            showError('Could not save the product: ' + err.message);
        } finally {
            setBusy(false);
        }
    });
</script>

<?php include 'templates/footer.php'; ?>