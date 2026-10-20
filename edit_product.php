<?php
/**
 * BLOOMINOUS - Edit Product (Firebase Spoke)
 *
 * - Follows the admin theme (light + dark) through header.php's CSS variables.
 * - The SKU is read-only (assigned when the product was created).
 * - Images are ATTACHED FILES (1 to 5), not links. Existing photos are kept
 *   as tiles; new files upload to Firebase Storage first, Cloudinary second.
 *   Saved as `image` (cover) and `images` (all).
 * - Branches: the page detects every branch that already has this product
 *   and shows them as LOCKED chips. The admin can add more branches; the
 *   product is then created in each new branch's inventory in the same
 *   Firestore transaction that updates the shared details everywhere.
 *   Stock stays per-branch: editing stock changes only the branch being
 *   edited, and new branches start with the stock typed here.
 * - 3D model: not editable here (3D Realism Hub). The existing `model`
 *   value is never overwritten, and new branch copies inherit it.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

// GET PRODUCT ID FROM URL (Firestore ids: letters, numbers, - and _ only)
$p_id = $_GET['id'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $p_id)) {
    header("Location: product_management.php");
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
    .edit-content { padding: 1.5rem; max-width: 800px; margin: 0 auto; }

    .edit-card {
        background: var(--surface);
        border-radius: 30px;
        padding: 3rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.02);
        border: 1px solid var(--border-color);
    }

    .page-head { display: flex; align-items: center; gap: 18px; margin-bottom: 2.5rem; }
    .page-head-icon {
        width: 56px; height: 56px; border-radius: 18px; flex-shrink: 0;
        background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3);
        color: var(--primary); font-size: 1.4rem;
        display: flex; align-items: center; justify-content: center;
    }
    .page-title { font-family: 'Cormorant Garamond', serif; font-size: 2.4rem; font-weight: 900; color: var(--text-main); margin: 0; line-height: 1.1; }
    .page-sub { color: var(--text-light); font-size: 0.85rem; font-weight: 500; margin: 4px 0 0; }

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

    /* Read-only SKU */
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
    .meta-label { font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 1px; }
    .link-btn { background: none; border: none; cursor: pointer; color: var(--primary); font-weight: 800; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; }
    .link-btn:hover { text-decoration: underline; }

    .branch-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; min-height: 8px; }
    .branch-chip {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 14px; border-radius: 50px;
        background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.35);
        color: var(--primary); font-size: 0.75rem; font-weight: 800;
    }
    .branch-chip.is-new { padding-right: 8px; border-style: dashed; }
    .branch-chip.is-locked { background: var(--surface-alt); border-color: var(--border-color); color: var(--text-main); }
    .branch-chip.is-locked i { color: var(--text-light); font-size: 0.65rem; }
    .branch-chip .new-tag { font-size: 0.55rem; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; }
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

    .image-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-top: 12px; }
    .image-tile { position: relative; aspect-ratio: 1 / 1; border-radius: 14px; overflow: hidden; border: 1px solid var(--border-color); background: var(--surface-alt); }
    .image-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .tile-btn {
        position: absolute; top: 6px; width: 26px; height: 26px; border-radius: 50%;
        border: none; cursor: pointer; background: rgba(0,0,0,0.6); color: #fff; font-size: 0.7rem;
        display: flex; align-items: center; justify-content: center;
    }
    .tile-btn.remove { right: 6px; }
    .tile-btn.remove:hover { background: #DC2626; }
    .tile-btn.cover { left: 6px; }
    .tile-btn.cover:hover { background: var(--primary); }
    .image-tile .cover-badge {
        position: absolute; left: 6px; bottom: 6px;
        background: var(--primary); color: #fff;
        font-size: 0.55rem; font-weight: 900; text-transform: uppercase; letter-spacing: 1px;
        padding: 3px 8px; border-radius: 50px;
    }
    .image-tile .new-badge {
        position: absolute; right: 6px; bottom: 6px;
        background: rgba(0,0,0,0.6); color: #fff;
        font-size: 0.5rem; font-weight: 900; text-transform: uppercase; letter-spacing: 1px;
        padding: 3px 7px; border-radius: 50px;
    }

    /* Messages */
    .form-error {
        display: none; margin-bottom: 1.5rem; padding: 12px 16px; border-radius: 12px;
        background: rgba(220, 38, 38, 0.08); color: #DC2626;
        border: 1px solid rgba(220, 38, 38, 0.2);
        font-size: 0.8rem; font-weight: 700; line-height: 1.5;
    }
    .upload-status { display: none; margin-top: 12px; text-align: center; font-size: 0.75rem; font-weight: 700; color: var(--text-light); }

    .btn-group { display: flex; justify-content: flex-end; gap: 15px; margin-top: 10px; padding-top: 30px; border-top: 1px solid var(--border-color); }
    .btn-cancel {
        background: var(--surface-alt); color: var(--text-light);
        padding: 16px 35px; border-radius: 15px; text-decoration: none; font-weight: 800;
        transition: 0.3s; display: flex; align-items: center;
        font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px;
        border: 1px solid var(--border-color);
    }
    .btn-cancel:hover { color: var(--text-main); border-color: var(--text-light); }
    #saveBtn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

    .back-link { display: inline-flex; align-items: center; gap: 10px; color: var(--text-light); text-decoration: none; font-weight: 700; margin-bottom: 2rem; transition: 0.3s; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; }
    .back-link:hover { color: var(--primary); transform: translateX(-5px); }

    @media (max-width: 640px) {
        .edit-card { padding: 1.75rem; }
        .form-grid { grid-template-columns: 1fr; }
        .span-2 { grid-column: auto; }
        .image-grid { grid-template-columns: repeat(3, 1fr); }
        .btn-group { flex-direction: column-reverse; }
    }

    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>. */
    html[data-theme="dark"] .edit-card { box-shadow: none; }
    html[data-theme="dark"] .branch-menu { box-shadow: 0 15px 30px rgba(0,0,0,0.45); }
    html[data-theme="dark"] .edit-content select.form-control { color-scheme: dark; }
    html[data-theme="dark"] .form-error { background: rgba(220, 38, 38, 0.15); color: #fca5a5; border-color: rgba(220, 38, 38, 0.35); }
</style>

<div class="edit-content">
    <a href="product_management.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> <span>Archive Control</span>
    </a>

    <div class="edit-card">
        <div class="page-head">
            <div class="page-head-icon"><i class="fa-solid fa-pen-nib"></i></div>
            <div>
                <h2 class="page-title">Modify Artifact</h2>
                <p class="page-sub">Refine the master data for this inventory unit.</p>
            </div>
        </div>

        <div id="formError" class="form-error" role="alert"></div>

        <form id="editProductForm" novalidate>
            <div class="form-grid">
                <div class="form-group span-2">
                    <label for="name">Product Name <span class="required">*</span></label>
                    <input type="text" id="name" class="form-control" placeholder="Loading..." maxlength="120" required>
                </div>

                <!-- Branch picker: locked chips = already has the product -->
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
                        <span id="branchCount" class="meta-label">0 branches</span>
                        <button type="button" id="selectAllBranches" class="link-btn">Add all branches</button>
                    </div>
                    <div id="branchChips" class="branch-chips"></div>
                    <small class="field-hint">Locked branches already have this product (to remove it from one, use Archive in Inventory). Pick more branches to add it there. Name, category, price and photos are synced to every branch listed.</small>
                </div>

                <div class="form-group">
                    <label for="barcode">Barcode / SKU</label>
                    <div class="sku-wrap">
                        <input type="text" id="barcode" class="form-control" value="..." readonly tabindex="-1">
                        <i class="fa-solid fa-lock sku-lock"></i>
                    </div>
                    <small class="field-hint">The code is fixed once a product is created.</small>
                </div>

                <div class="form-group">
                    <label for="category">Category <span class="required">*</span></label>
                    <select id="category" class="form-control" required>
                        <option value="Bouquets">Bouquets</option>
                        <option value="Flower Baskets">Flower Baskets</option>
                        <option value="Box Arrangements">Box Arrangements</option>
                        <option value="Sympathy Flowers">Sympathy Flowers</option>
                        <option value="Wedding Flowers">Wedding Flowers</option>
                        <option value="Single Stems">Single Stems</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="price">Unit Price (₱) <span class="required">*</span></label>
                    <input type="number" step="0.01" min="0" id="price" class="form-control" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label for="stock">Stock Quantity <span class="required">*</span></label>
                    <input type="number" step="1" min="0" id="stock" class="form-control" placeholder="0" required>
                    <small class="field-hint">Updates this branch only. Branches you add start with this amount.</small>
                </div>

                <div class="form-group span-2">
                    <label>Product Images <span class="required">*</span></label>
                    <input type="file" id="imageInput" accept="image/jpeg,image/png,image/webp" multiple hidden>
                    <div id="dropzone" class="dropzone" role="button" tabindex="0">
                        <i class="fa-solid fa-images"></i>
                        <span id="dropzoneText">Click or drag photos here (JPG, PNG, WEBP)</span>
                    </div>
                    <div class="image-meta">
                        <span id="imageCount" class="meta-label">0 / 5 images</span>
                        <small class="field-hint" style="margin-top:0;">The first photo is the cover. Click the star on a photo to make it the cover.</small>
                    </div>
                    <div id="imageGrid" class="image-grid"></div>
                </div>
            </div>

            <div class="btn-group">
                <a href="product_management.php" class="btn-cancel">Discard</a>
                <button type="submit" id="saveBtn" class="btn-primary px-12 py-5 text-sm uppercase tracking-widest" disabled>
                    <i class="fa-solid fa-check-double mr-2"></i> Update Artifact
                </button>
            </div>
            <p id="uploadStatus" class="upload-status"></p>
        </form>
    </div>
</div>

<script>
    // ---------- Settings ----------
    const PRODUCT_ID = <?php echo json_encode($p_id); ?>;
    const MAX_IMAGES = 5;
    const MAX_FILE_BYTES = 10 * 1024 * 1024;           // 10 MB per original photo
    const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const COMPRESS_MAX_DIMENSION = 1280;                // longest side after resizing
    const COMPRESS_QUALITY = 0.82;
    const FIREBASE_UPLOAD_TIMEOUT_MS = 20000;           // give up on Firebase after 20s
    const SKU_PATTERN = /^BLOOM-(\d{1,6})$/;
    const SAVE_BTN_HTML = '<i class="fa-solid fa-check-double mr-2"></i> Update Artifact';
    const CLOUDINARY = <?php echo json_encode($cloudinaryConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // ---------- Page state ----------
    let imageEntries = [];              // ordered: { kind:'existing', url, provider } | { kind:'new', file, previewUrl }
    let firebaseStorageUsable = true;   // flips to false after the first Firebase failure
    let allBranches = [];               // [{ id, name }]
    let selectedBranchIds = [];         // existing branches first, then newly picked ones
    let existingBranchDocs = new Map(); // branchId -> DocumentReference that ALREADY holds this product
    let activeOptionIndex = -1;
    let originalProduct = null;         // data of the document being edited
    let originalPrice = 0;
    let originalStock = 0;
    let productCode = '';

    // ---------- Elements ----------
    const form = document.getElementById('editProductForm');
    const nameInput = document.getElementById('name');
    const categoryInput = document.getElementById('category');
    const skuInput = document.getElementById('barcode');
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
        formError.textContent = message; // textContent: never treated as HTML
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
            ? '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Updating...'
            : SAVE_BTN_HTML;
        uploadStatus.textContent = statusText;
        uploadStatus.style.display = statusText ? 'block' : 'none';
    }

    function extensionFor(type) {
        return { 'image/png': 'png', 'image/webp': 'webp' }[type] || 'jpg';
    }

    function branchNameOf(id) {
        const branch = allBranches.find(b => b.id === id);
        return branch ? branch.name : id;
    }

    function isArchived(data) {
        return !!data && (data.isDeleted === true || data.status === 'archived');
    }

    function branchInventory(branchId) {
        return db.collection('branches').doc(branchId).collection('inventory');
    }

    function skuCounterRef(branchId) {
        return db.collection('counters').doc('sku_' + branchId);
    }

    // ---------- Branch picker ----------
    const isLocked = (id) => existingBranchDocs.has(id);

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
            empty.textContent = selectedBranchIds.length === allBranches.length
                ? 'This product is in every branch.'
                : 'No branch matches your search.';
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

    function closeBranchMenu() { branchMenu.classList.remove('open'); }

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
            const locked = isLocked(id);
            chip.className = 'branch-chip ' + (locked ? 'is-locked' : 'is-new');

            if (locked) {
                const lockIcon = document.createElement('i');
                lockIcon.className = 'fa-solid fa-lock';
                chip.appendChild(lockIcon);
            }

            const text = document.createElement('span');
            text.textContent = branchNameOf(id);
            chip.appendChild(text);

            if (!locked) {
                const tag = document.createElement('span');
                tag.className = 'new-tag';
                tag.textContent = 'New';
                chip.appendChild(tag);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.title = 'Remove branch';
                removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                removeBtn.addEventListener('click', () => removeBranch(id));
                chip.appendChild(removeBtn);
            }
            branchChips.appendChild(chip);
        });

        const total = selectedBranchIds.length;
        const added = selectedBranchIds.filter(id => !isLocked(id)).length;
        branchCount.textContent = `${total} ${total === 1 ? 'branch' : 'branches'}` + (added ? ` (${added} new)` : '');
        selectAllBranchesBtn.style.display = (allBranches.length > 0 && total < allBranches.length) ? 'inline' : 'none';
    }

    function onBranchesChanged() {
        renderBranchChips();
        if (branchMenu.classList.contains('open')) renderBranchMenu();
    }

    function addBranch(id) {
        if (!selectedBranchIds.includes(id)) selectedBranchIds.push(id);
        branchSearch.value = '';
        hideError();
        onBranchesChanged();
        branchSearch.focus();
    }

    function removeBranch(id) {
        if (isLocked(id)) return; // branches that already hold the product cannot be removed here
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
            // Enter picks the highlighted branch instead of submitting the form.
            e.preventDefault();
            const active = branchMenu.querySelector('.branch-option.is-active');
            if (active) addBranch(active.dataset.id);
        }
    });
    selectAllBranchesBtn.addEventListener('click', () => {
        allBranches.forEach(b => { if (!selectedBranchIds.includes(b.id)) selectedBranchIds.push(b.id); });
        branchSearch.value = '';
        hideError();
        closeBranchMenu();
        onBranchesChanged();
    });
    document.addEventListener('click', (e) => {
        if (!branchPicker.contains(e.target)) closeBranchMenu();
    });

    // ---------- Loading the product, branches, and where it already lives ----------
    async function loadEverything() {
        try {
            // 1. The product being edited (in the branch the admin is working in)
            const currentRef = branchInventory(window.currentBranch).doc(PRODUCT_ID);
            const currentSnap = await currentRef.get();
            if (!currentSnap.exists) {
                alert('Product not found in this branch. Switch to the right branch and try again.');
                window.location.href = 'product_management.php';
                return;
            }
            originalProduct = currentSnap.data();
            productCode = String(originalProduct.code || '').toUpperCase();

            // 2. All branches
            const branchSnap = await db.collection('branches').get();
            allBranches = branchSnap.docs
                .map(doc => ({ id: doc.id, name: doc.data().name || doc.id }))
                .sort((a, b) => a.name.localeCompare(b.name));
            if (!allBranches.some(b => b.id === window.currentBranch)) {
                allBranches.push({ id: window.currentBranch, name: window.currentBranch });
            }

            // 3. Which branches ALREADY have this product?
            //    Match by the same document id, or (older products) by the same code.
            existingBranchDocs = new Map();
            existingBranchDocs.set(window.currentBranch, currentRef);

            const others = allBranches.filter(b => b.id !== window.currentBranch);
            const found = await Promise.all(others.map(async (branch) => {
                const byId = branchInventory(branch.id).doc(PRODUCT_ID);
                const idSnap = await byId.get();
                if (idSnap.exists && !isArchived(idSnap.data())) return [branch.id, byId];

                if (productCode) {
                    const codeSnap = await branchInventory(branch.id).where('code', '==', productCode).limit(5).get();
                    const hit = codeSnap.docs.find(d => !isArchived(d.data()));
                    if (hit) return [branch.id, hit.ref];
                }
                return null;
            }));
            found.filter(Boolean).forEach(([branchId, ref]) => existingBranchDocs.set(branchId, ref));

            // Existing branches first (current branch at the very front), alphabetical after.
            selectedBranchIds = [window.currentBranch].concat(
                Array.from(existingBranchDocs.keys())
                    .filter(id => id !== window.currentBranch)
                    .sort((a, b) => branchNameOf(a).localeCompare(branchNameOf(b)))
            );

            populateForm(originalProduct);
            branchSearch.disabled = false;
            branchSearch.placeholder = 'Search or choose a branch to add...';
            onBranchesChanged();
            saveBtn.disabled = false;
        } catch (err) {
            console.error('Could not load the product:', err);
            showError('Could not load this product: ' + err.message);
        }
    }

    function populateForm(p) {
        nameInput.value = p.name || '';
        nameInput.placeholder = 'e.g. Red Rose Bouquet';
        skuInput.value = p.code || '';
        priceInput.value = p.price ?? 0;
        stockInput.value = p.stock ?? 0;
        categoryInput.value = p.category || 'Bouquets';

        originalPrice = typeof p.price === 'number' ? p.price : parseFloat(p.price) || 0;
        originalStock = typeof p.stock === 'number' ? p.stock : parseInt(p.stock, 10) || 0;

        // Existing photos become tiles. The old default placeholder is not a real photo.
        const urls = (Array.isArray(p.images) && p.images.length) ? p.images : (p.image ? [p.image] : []);
        const providers = Array.isArray(p.imageProviders) ? p.imageProviders : [];
        imageEntries = urls
            .map((url, i) => ({ kind: 'existing', url: url, provider: providers[i] || 'existing' }))
            .filter(entry => typeof entry.url === 'string' && entry.url && !entry.url.includes('picsum.photos'))
            .slice(0, MAX_IMAGES);
        renderImages();
    }

    // Wait until Firebase has restored the login: reads below need a signed-in user.
    let initialised = false;
    firebase.auth().onAuthStateChanged(user => {
        if (user && !initialised) {
            initialised = true;
            loadEverything();
        }
    });

    // ---------- Image picking ----------
    function addFiles(fileList) {
        hideError();
        const incoming = Array.from(fileList || []);
        const room = MAX_IMAGES - imageEntries.length;

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
            imageEntries.push({ kind: 'new', file: file, previewUrl: URL.createObjectURL(file) });
        });

        if (incoming.length > room) {
            skipped.push(`${incoming.length - room} extra file(s), the limit is ${MAX_IMAGES}`);
        }
        if (skipped.length) showError('Skipped: ' + skipped.join('; '));
        renderImages();
    }

    function removeImage(index) {
        const entry = imageEntries[index];
        if (entry.kind === 'new') URL.revokeObjectURL(entry.previewUrl);
        imageEntries.splice(index, 1);
        renderImages();
    }

    function makeCover(index) {
        const [entry] = imageEntries.splice(index, 1);
        imageEntries.unshift(entry);
        renderImages();
    }

    function renderImages() {
        imageGrid.innerHTML = '';

        imageEntries.forEach((entry, index) => {
            const tile = document.createElement('div');
            tile.className = 'image-tile';

            const img = document.createElement('img');
            img.src = entry.kind === 'new' ? entry.previewUrl : entry.url;
            img.alt = 'Product photo ' + (index + 1);
            tile.appendChild(img);

            if (index === 0) {
                const badge = document.createElement('span');
                badge.className = 'cover-badge';
                badge.textContent = 'Cover';
                tile.appendChild(badge);
            } else {
                const coverBtn = document.createElement('button');
                coverBtn.type = 'button';
                coverBtn.className = 'tile-btn cover';
                coverBtn.title = 'Make this the cover photo';
                coverBtn.innerHTML = '<i class="fa-solid fa-star"></i>';
                coverBtn.addEventListener('click', () => makeCover(index));
                tile.appendChild(coverBtn);
            }

            if (entry.kind === 'new') {
                const newBadge = document.createElement('span');
                newBadge.className = 'new-badge';
                newBadge.textContent = 'New';
                tile.appendChild(newBadge);
            }

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'tile-btn remove';
            removeBtn.title = 'Remove photo';
            removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            removeBtn.addEventListener('click', () => removeImage(index));
            tile.appendChild(removeBtn);

            imageGrid.appendChild(tile);
        });

        const isFull = imageEntries.length >= MAX_IMAGES;
        imageCount.textContent = `${imageEntries.length} / ${MAX_IMAGES} images`;
        dropzone.classList.toggle('is-full', isFull);
        dropzoneText.textContent = isFull
            ? 'Image limit reached'
            : 'Click or drag photos here (JPG, PNG, WEBP)';
        imageInput.value = ''; // lets the same file be picked again after removal
    }

    dropzone.addEventListener('click', () => {
        if (imageEntries.length < MAX_IMAGES) imageInput.click();
    });
    dropzone.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && imageEntries.length < MAX_IMAGES) {
            e.preventDefault();
            imageInput.click();
        }
    });
    imageInput.addEventListener('change', (e) => addFiles(e.target.files));

    ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, (e) => {
        e.preventDefault();
        if (imageEntries.length < MAX_IMAGES) dropzone.classList.add('dragover');
    }));
    ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, (e) => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
    }));
    dropzone.addEventListener('drop', (e) => addFiles(e.dataTransfer.files));

    // ---------- Image processing + upload ----------
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

    /**
     * Build a guaranteed-unique filename for every upload.
     * Why this exists:
     *   `Date.now()` alone repeats inside fast loops, and identical names make
     *   Firebase OVERWRITE the previous object and Cloudinary REUSE the old
     *   public_id — which is exactly the "old image keeps coming back" bug.
     *   A UUID makes each filename truly unique, so nothing is ever overwritten
     *   and no browser or CDN cache can serve an old image for the same URL.
     */
    function uniqueFileName(blob, index) {
        const ext = extensionFor(blob.type);
        const rnd = (typeof crypto !== 'undefined' && crypto && typeof crypto.randomUUID === 'function')
            ? crypto.randomUUID()
            : Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
        return `product_${Date.now()}_${index}_${rnd}.${ext}`;
    }

    function uploadToFirebase(blob, path) {
        return new Promise((resolve, reject) => {
            const task = firebase.storage().ref(path).put(blob, {
                contentType: blob.type,
                // Tells the CDN this object is immutable — future writes to the
                // same path would still be blocked by the unique filename above.
                cacheControl: 'public, max-age=31536000, immutable'
            });
            const timer = setTimeout(() => {
                task.cancel();
                reject(new Error('Firebase Storage did not respond in time.'));
            }, FIREBASE_UPLOAD_TIMEOUT_MS);

            task.then(snapshot => snapshot.ref.getDownloadURL())
                .then(url => { clearTimeout(timer); resolve(url); })
                .catch(err => { clearTimeout(timer); reject(err); });
        });
    }

    async function uploadToCloudinary(blob, index) {
        if (!CLOUDINARY.cloudName || !CLOUDINARY.uploadPreset) {
            throw new Error('Image storage is unavailable: Firebase Storage failed and Cloudinary is not configured in config.local.php.');
        }

        // Unique name used as the Cloudinary public_id base. A unique public_id
        // is what stops Cloudinary from returning a cached old secure_url for
        // a freshly-uploaded file.
        const baseName = uniqueFileName(blob, index).replace(/\.[^.]+$/, '');
        const publicId = `bloominous/products/${baseName}`;

        const formData = new FormData();
        formData.append('file', blob, baseName + '.' + extensionFor(blob.type));
        formData.append('upload_preset', CLOUDINARY.uploadPreset);
        formData.append('public_id', publicId);
        formData.append('folder', 'bloominous/products');

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
        // Cache-bust the URL just in case a CDN edge still holds the old bytes.
        return result.secure_url + `?v=${Date.now()}`;
    }

    // Firebase Storage first; after its first failure, Cloudinary for the rest.
    async function uploadImage(blob, index) {
        const path = `products/${uniqueFileName(blob, index)}`;

        if (firebaseStorageUsable && typeof firebase.storage === 'function') {
            try {
                const url = await uploadToFirebase(blob, path);
                // Cache-bust so a deleted-then-readded image never shows old bytes.
                return { url: url + `?v=${Date.now()}`, provider: 'firebase' };
            } catch (err) {
                console.warn('Firebase Storage failed, switching to Cloudinary:', err);
                firebaseStorageUsable = false;
            }
        }

        const url = await uploadToCloudinary(blob, index);
        return { url, provider: 'cloudinary' };
    }

    // ---------- Save ----------
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideError();

        const name = nameInput.value.trim();
        const category = categoryInput.value;
        const newPrice = parseFloat(priceInput.value);
        const newStock = Number(stockInput.value);

        if (!name) { showError('Please enter a product name.'); nameInput.focus(); return; }
        if (!category) { showError('Please choose a category.'); categoryInput.focus(); return; }
        if (!Number.isFinite(newPrice) || newPrice < 0) { showError('Please enter a valid unit price (0 or more).'); priceInput.focus(); return; }
        if (stockInput.value === '' || !Number.isInteger(newStock) || newStock < 0) { showError('Stock must be a whole number (0 or more).'); stockInput.focus(); return; }
        if (imageEntries.length === 0) { showError('Please keep or attach at least one product image.'); return; }

        const user = firebase.auth().currentUser;
        if (!user) {
            showError('Your session is still loading. Please wait a moment and try again.');
            return;
        }

        const branchIds = selectedBranchIds.slice();                       // snapshot
        const existingIds = branchIds.filter(id => existingBranchDocs.has(id));
        const newBranchIds = branchIds.filter(id => !existingBranchDocs.has(id));

        setBusy(true, 'Checking branches...');

        try {
            // 1. A new branch must not already use this product code for a DIFFERENT product.
            for (const branchId of newBranchIds) {
                if (!productCode) break;
                const clash = await branchInventory(branchId).where('code', '==', productCode).limit(5).get();
                const conflict = clash.docs.find(d => d.id !== PRODUCT_ID && !isArchived(d.data()));
                if (conflict) {
                    throw new Error(`${branchNameOf(branchId)} already has a different product using the code ${productCode}. Remove that branch or change the other product first.`);
                }
            }

            // 2. Upload NEW photos only, keeping the order the admin arranged.
            const finalUrls = [];
            const finalProviders = [];
            for (let i = 0; i < imageEntries.length; i++) {
                const entry = imageEntries[i];
                if (entry.kind === 'existing') {
                    finalUrls.push(entry.url);
                    finalProviders.push(entry.provider);
                    continue;
                }
                setBusy(true, `Uploading image ${i + 1} of ${imageEntries.length}...`);
                const blob = await compressImage(entry.file);
                const result = await uploadImage(blob, i);
                finalUrls.push(result.url);
                finalProviders.push(result.provider);
            }

            // 3. One transaction: update existing branches + create the new ones.
            setBusy(true, 'Saving changes...');
            const serverTime = firebase.firestore.FieldValue.serverTimestamp();
            const codeMatch = SKU_PATTERN.exec(productCode);
            const codeNumber = codeMatch ? parseInt(codeMatch[1], 10) : 0;
            const currentBranchId = window.currentBranch;

            await db.runTransaction(async (transaction) => {
                // READS first (Firestore rule: all reads before any write).
                const counterSnaps = await Promise.all(
                    newBranchIds.map(id => transaction.get(skuCounterRef(id)))
                );

                // Shared details, synced to every branch that has the product.
                const sharedFields = {
                    name: name,
                    category: category,
                    price: newPrice,
                    image: finalUrls[0],
                    images: finalUrls,
                    imageProviders: finalProviders,
                    availableBranchIds: branchIds,
                    updatedAt: serverTime
                };

                // WRITES: existing branches. Stock changes only for the branch being edited.
                existingIds.forEach(branchId => {
                    const fields = Object.assign({}, sharedFields);
                    if (branchId === currentBranchId) fields.stock = newStock;
                    transaction.update(existingBranchDocs.get(branchId), fields);
                });

                // WRITES: brand-new branches (same id and code as this product).
                newBranchIds.forEach((branchId, i) => {
                    if (codeNumber > 0) {
                        const counterNow = counterSnaps[i].exists ? (counterSnaps[i].data().current || 0) : 0;
                        transaction.set(skuCounterRef(branchId), {
                            current: Math.max(counterNow, codeNumber),
                            branchId: branchId,
                            updatedAt: serverTime
                        }, { merge: true });
                    }
                    transaction.set(branchInventory(branchId).doc(PRODUCT_ID), Object.assign({}, sharedFields, {
                        code: productCode || (originalProduct.code || ''),
                        stock: newStock,
                        branchId: branchId,
                        model: originalProduct.model || '',
                        createdAt: serverTime
                    }));
                });
            });

            // 4. Accountability record. Price/stock old -> new, same as before, plus branches added.
            try {
                const addedText = newBranchIds.length
                    ? ` Added to: ${newBranchIds.map(branchNameOf).join(', ')}.`
                    : '';
                await db.collection('admin_actions').add({
                    actorUid: user.uid,
                    actorEmail: window.currentUserEmail || null,
                    actorRole: window.currentUserRole || null,
                    action: 'inventory_item_updated',
                    targetUid: PRODUCT_ID,
                    targetEmail: null,
                    details: `"${name}" — Price: ₱${originalPrice.toFixed(2)} → ₱${newPrice.toFixed(2)}, Stock: ${originalStock} → ${newStock}, ${finalUrls.length} image(s).${addedText}`,
                    timestamp: firebase.firestore.FieldValue.serverTimestamp()
                });
            } catch (auditError) {
                console.warn('Audit log write failed (product still updated):', auditError);
            }

            imageEntries.forEach(entry => { if (entry.kind === 'new') URL.revokeObjectURL(entry.previewUrl); });
            alert(newBranchIds.length
                ? `Product updated and added to: ${newBranchIds.map(branchNameOf).join(', ')}`
                : 'Product updated successfully!');
            window.location.href = 'product_management.php';
        } catch (err) {
            console.error(err);
            showError('Could not update the product: ' + err.message);
        } finally {
            // Always release the button — even on thrown errors or early returns.
            // If we're navigating away this is harmless; if we stay, the admin can retry.
            setBusy(false);
        }
    });
</script>

<?php include 'templates/footer.php'; ?>