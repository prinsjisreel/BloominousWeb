<?php
/**
 * BLOOMINOUS - Manage Branches
 *
 * Each branch stores: name, location (+ address, the same text, for the app),
 * latitude, longitude and deliveryRadiusKm. The website checkout, the mobile
 * app checkout and submit_order.php all read these same fields, so a branch
 * added here applies everywhere.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}
include 'templates/header.php';
?>

<style>
    /* ============ THEME ============
       header.php sets data-theme="dark" or "light" on <html> and defines the
       shared tokens (--surface, --surface-alt, --border-color, --text-main,
       --text-light). Everything here reads those tokens, so this page and its
       modal switch with the rest of the system - no hard-coded dark colours.

       Only two things need their own light/dark pair, because amber TEXT must
       be darker on white and lighter on dark to stay readable:
         --bm-amber-text  amber accents (icons, "Switch Access")
         --bm-label       soft form-label colour (never pure black or white) */
    .branches-content, #addModal {
        --bm-amber-text: #B45309;
        --bm-label: #5d4e3e;
        --bm-amber-soft: rgba(245, 158, 11, 0.16);
        --bm-overlay: rgba(40, 28, 14, 0.45);
        --bm-error-text: #b91c1c;
        --bm-error-bg: rgba(239, 68, 68, 0.08);
    }
    html[data-theme="dark"] .branches-content,
    html[data-theme="dark"] #addModal {
        --bm-amber-text: #FBBF24;
        --bm-label: #d6c9b6;
        --bm-amber-soft: rgba(245, 158, 11, 0.14);
        --bm-overlay: rgba(0, 0, 0, 0.65);
        --bm-error-text: #fca5a5;
        --bm-error-bg: rgba(239, 68, 68, 0.12);
    }

    /* ---------- page text ---------- */
    .bm-title   { color: var(--text-main); }
    .bm-name    { color: var(--text-main); }
    .bm-address { color: var(--text-light); }
    .bm-strong  { color: var(--text-main); }
    .bm-accent  { color: var(--bm-amber-text); }

    .bm-add-btn { box-shadow: 0 10px 24px rgba(245, 158, 11, 0.25); }
    html[data-theme="dark"] .bm-add-btn { box-shadow: none; }

    /* ---------- branch cards (.card itself already follows the theme) ---------- */
    .bm-card-icon { background: var(--bm-amber-soft); color: var(--bm-amber-text); }
    .bm-card-btn  { background: var(--surface-alt); color: var(--text-light); }
    .bm-card-btn:hover { background: var(--bm-amber-soft); color: var(--bm-amber-text); }
    .bm-divider { border-top: 1px solid var(--border-color); }
    .bm-dot { background: #F59E0B; box-shadow: 0 0 8px rgba(245, 158, 11, 0.55); }
    .bm-switch { color: var(--bm-amber-text); }
    .bm-switch:hover { opacity: 0.75; }
    .bm-empty { border: 2px dashed var(--border-color); }

    /* ---------- the modal ---------- */
    /* The overlay's padding keeps a gap at the top and bottom, so the panel
       can never touch the edges of the screen. */
    .bm-overlay {
        position: fixed;
        inset: 0;
        z-index: 10000;                 /* above the sidebar and the top bar */
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: var(--bm-overlay);
        backdrop-filter: blur(6px);
    }
    .bm-overlay.bm-hidden { display: none; }

    /* Column layout: fixed header, scrolling middle, fixed footer. If the
       screen is short, the middle scrolls; the panel never grows past it. */
    .bm-panel {
        width: 100%;
        max-width: 460px;
        max-height: calc(100vh - 32px);
        max-height: calc(100dvh - 32px);
        display: flex;
        flex-direction: column;
        background: var(--surface);
        border: 1px solid var(--border-color);
        border-radius: 28px;
        overflow: hidden;
        transform: scale(0.95);
        opacity: 0;
        transition: transform 0.25s ease, opacity 0.25s ease;
    }
    html:not([data-theme="dark"]) .bm-panel { box-shadow: 0 30px 80px rgba(60, 40, 10, 0.28); }
    .bm-panel.bm-in { transform: scale(1); opacity: 1; }

    .bm-head {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 22px 28px 16px;
        border-bottom: 1px solid var(--border-color);
    }
    .bm-head h2 { margin: 0; font-size: 1.6rem; font-weight: 900; color: var(--text-main); }
    .bm-close {
        width: 38px; height: 38px;
        border: 0; border-radius: 999px;
        display: flex; align-items: center; justify-content: center;
        background: var(--surface-alt);
        color: var(--text-light);
        cursor: pointer;
        transition: all 0.2s;
    }
    .bm-close:hover { background: var(--bm-amber-soft); color: var(--bm-amber-text); }

    .bm-form { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }   /* min-height:0 lets the middle shrink and scroll */
    .bm-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        padding: 20px 28px 8px;
        display: flex;
        flex-direction: column;
        gap: 18px;
    }
    .bm-foot {
        flex: 0 0 auto;
        display: flex;
        gap: 12px;
        padding: 16px 28px 22px;
        border-top: 1px solid var(--border-color);
        background: var(--surface);
    }

    .bm-label {
        display: block;
        margin: 0 0 8px 4px;
        font-size: 10px;
        font-weight: 600;               /* medium weight: calm, not shouty */
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--bm-label);
    }
    .bm-input {
        width: 100%;
        box-sizing: border-box;
        padding: 14px 18px;
        border-radius: 16px;
        border: 1px solid var(--border-color);
        background: var(--surface-alt);
        color: var(--text-main);
        font-size: 14px;
        font-weight: 600;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    html[data-theme="dark"] .bm-input { color-scheme: dark; }
    .bm-input::placeholder { color: var(--text-light); opacity: 0.6; font-weight: 500; }
    .bm-input:focus { border-color: #F59E0B; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.20); }
    .bm-input:disabled { opacity: 0.55; cursor: not-allowed; }
    .bm-hint { margin: 6px 4px 0; font-size: 10px; font-style: italic; line-height: 1.5; color: var(--text-light); }
    .bm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .bm-link {
        align-self: flex-start;
        padding: 0;
        border: 0;
        background: none;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--bm-label);
        cursor: pointer;
    }
    .bm-link i { color: var(--bm-amber-text); }   /* the small icon keeps the amber accent */
    .bm-link:hover { opacity: 0.75; }
    .bm-error {
        margin: 0;
        padding: 10px 14px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
        color: var(--bm-error-text);
        background: var(--bm-error-bg);
        border: 1px solid rgba(239, 68, 68, 0.35);
    }
    .bm-hidden { display: none; }

    .bm-btn { padding: 14px 18px; border: 0; border-radius: 16px; font-size: 14px; font-weight: 800; cursor: pointer; transition: all 0.2s; }
    .bm-btn:disabled { opacity: 0.6; cursor: wait; }
    .bm-btn-cancel { flex: 1; background: var(--surface-alt); color: var(--text-light); border: 1px solid var(--border-color); }
    .bm-btn-cancel:hover { color: var(--text-main); }
    /* Amber button with dark text: readable in BOTH themes. */
    .bm-btn-save { flex: 2; background: linear-gradient(135deg, #FBBF24, #F59E0B); color: #2a1a05; box-shadow: 0 10px 24px rgba(245, 158, 11, 0.28); }
    .bm-btn-save:hover:not(:disabled) { transform: translateY(-1px); }

    /* Short screens (phone sideways, small laptop windows): tighter spacing. */
    @media (max-height: 620px) {
        .bm-head { padding: 14px 22px 10px; }
        .bm-body { padding: 14px 22px 6px; gap: 14px; }
        .bm-foot { padding: 12px 22px 16px; }
    }
</style>

<div class="branches-content max-w-7xl mx-auto px-6 py-8">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-16 gap-8">
        <div>
            <h1 class="bm-title brand-font text-6xl font-black tracking-tight">Manage Branches</h1>
            <p class="text-gray-400 text-sm mt-1 font-medium italic">Manage flower shop branches and operational status.</p>
        </div>
        <button onclick="openAddModal()" class="bm-add-btn btn-primary flex items-center px-10 py-4 rounded-3xl font-black text-xs uppercase tracking-[0.2em] transform hover:-translate-y-1 transition-all">
            <i class="fa-solid fa-plus-circle mr-3 text-lg"></i>
            <span>Add New Branch</span>
        </button>
    </div>

    <!-- Branch List -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8" id="branch-list">
        <!-- Loading State -->
        <div class="col-span-full py-20 text-center">
            <i class="fa-solid fa-circle-notch fa-spin text-4xl bm-accent" style="opacity:.5"></i>
            <p class="mt-4 text-gray-400 font-medium tracking-wide text-xs uppercase">Connecting to Cloud Firestore...</p>
        </div>
    </div>
</div>

<!-- Add / Edit Branch Modal -->
<div id="addModal" class="bm-overlay bm-hidden">
    <div class="bm-panel" id="modalContent" role="dialog" aria-modal="true" aria-labelledby="modalTitle">

        <div class="bm-head">
            <h2 id="modalTitle" class="brand-font">New Branch</h2>
            <button type="button" onclick="closeModal()" class="bm-close" aria-label="Close">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>

        <!-- The form wraps BOTH the scrolling fields and the footer buttons,
             so Submit works even though it sits outside the scroll area. -->
        <form id="branchForm" class="bm-form">
            <div class="bm-body">
                <div>
                    <label class="bm-label" for="branchName">Branch Name</label>
                    <input type="text" id="branchName" required placeholder="e.g., Marilao Branch" class="bm-input">
                </div>
                <div>
                    <label class="bm-label" for="branchId">Branch ID (Unique Key)</label>
                    <input type="text" id="branchId" required placeholder="e.g., marilao_main" class="bm-input">
                    <p class="bm-hint">Internal identifier. Use lowercase and underscores only.</p>
                </div>
                <div>
                    <label class="bm-label" for="branchLocation">Physical Address</label>
                    <input type="text" id="branchLocation" placeholder="e.g., SM Marilao, Bulacan" class="bm-input">
                </div>

                <div class="bm-row">
                    <div>
                        <label class="bm-label" for="branchLat">Latitude *</label>
                        <input type="number" step="any" id="branchLat" required placeholder="14.7573" class="bm-input">
                    </div>
                    <div>
                        <label class="bm-label" for="branchLng">Longitude *</label>
                        <input type="number" step="any" id="branchLng" required placeholder="120.9439" class="bm-input">
                    </div>
                </div>

                <button type="button" onclick="useMyLocation()" class="bm-link">
                    <i class="fa-solid fa-location-crosshairs mr-1"></i> Use my current location
                </button>

                <div>
                    <label class="bm-label" for="branchRadius">Delivery Radius (km) *</label>
                    <input type="number" step="any" min="0.5" max="200" id="branchRadius" required placeholder="e.g., 10" class="bm-input">
                    <p class="bm-hint">This branch only delivers to addresses within this distance of its location. Applies to both the website and the mobile app checkout.</p>
                </div>

                <p id="branchFormError" class="bm-error bm-hidden"></p>
            </div>

            <div class="bm-foot">
                <button type="button" onclick="closeModal()" class="bm-btn bm-btn-cancel">Cancel</button>
                <button type="submit" id="branchSubmitBtn" class="bm-btn bm-btn-save">Create Store Space</button>
            </div>
        </form>
    </div>
</div>

<script>
    const branchList = document.getElementById('branch-list');
    const addModal = document.getElementById('addModal');
    const modalContent = document.getElementById('modalContent');
    const branchForm = document.getElementById('branchForm');
    const modalTitle = document.getElementById('modalTitle');
    const submitBtn = document.getElementById('branchSubmitBtn');
    const branchIdInput = document.getElementById('branchId');
    const formError = document.getElementById('branchFormError');

    // Same limits as the BloominousApp admin screen (shop_location_page.dart).
    const MIN_RADIUS_KM = 0.5;
    const MAX_RADIUS_KM = 200;

    // Latest branch data, keyed by id. Edit reads from here instead of
    // having the name/address pasted into the HTML -- that pasting broke
    // on any name containing an apostrophe (e.g. "Rose's Garden").
    let branchCache = {};

    // null = the modal is in "add" mode; a branch id = "editing that branch".
    // One variable decides the mode, so the modal can't get stuck halfway.
    let editingBranchId = null;

    // Makes any text safe to print inside HTML (names/addresses can contain < > & quotes).
    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function showFormError(message) {
        formError.textContent = message || '';
        formError.classList.toggle('bm-hidden', !message);
    }

    // Puts the modal back into a clean "Add New Branch" state.
    function resetModalToAddMode() {
        editingBranchId = null;
        branchForm.reset();
        showFormError('');
        branchIdInput.disabled = false;
        modalTitle.innerText = 'New Branch';
        submitBtn.innerText = 'Create Store Space';
    }

    function openModal() {
        addModal.classList.remove('bm-hidden');
        // Freeze the page behind the modal so only the modal scrolls.
        document.body.style.overflow = 'hidden';
        const body = addModal.querySelector('.bm-body');
        if (body) body.scrollTop = 0;   // every opening starts at the top of the form
        setTimeout(() => modalContent.classList.add('bm-in'), 10);
    }

    function openAddModal() {
        resetModalToAddMode();
        openModal();
    }

    // Closing the modal (Cancel, X, Esc, backdrop, or after saving) ALWAYS
    // resets it, so a cancelled Edit can never leak into the next "Add New Branch".
    function closeModal() {
        modalContent.classList.remove('bm-in');
        setTimeout(() => {
            addModal.classList.add('bm-hidden');
            document.body.style.overflow = '';
            resetModalToAddMode();
        }, 250);
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !addModal.classList.contains('bm-hidden')) closeModal();
    });
    addModal.addEventListener('mousedown', e => {
        if (e.target === addModal) closeModal();   // only the dark area, not the panel
    });

    function useMyLocation() {
        if (!navigator.geolocation) {
            showFormError('This browser cannot share your location. Please type the latitude and longitude.');
            return;
        }
        navigator.geolocation.getCurrentPosition(
            pos => {
                document.getElementById('branchLat').value = pos.coords.latitude;
                document.getElementById('branchLng').value = pos.coords.longitude;
                showFormError('');
            },
            () => showFormError('Could not get your location. Please type the latitude and longitude.')
        );
    }

    // One rule for add AND edit, so they can never disagree.
    // Returns {error} or {lat, lng, radius}.
    function readGeoFields() {
        const lat = parseFloat(document.getElementById('branchLat').value);
        const lng = parseFloat(document.getElementById('branchLng').value);
        const radius = parseFloat(document.getElementById('branchRadius').value);

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return { error: 'Please enter the branch latitude and longitude. The delivery radius is measured from this point.' };
        }
        if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            return { error: 'Latitude must be between -90 and 90, and longitude between -180 and 180.' };
        }
        if (!Number.isFinite(radius)) {
            return { error: 'Please enter a delivery radius in kilometres.' };
        }
        if (radius < MIN_RADIUS_KM || radius > MAX_RADIUS_KM) {
            return { error: `Delivery radius must be between ${MIN_RADIUS_KM} and ${MAX_RADIUS_KM} km.` };
        }
        return { lat, lng, radius };
    }

    // Load Branches Real-time
    db.collection('branches').onSnapshot(snap => {
        branchCache = {};

        if (snap.empty) {
            branchList.innerHTML = `
                <div class="bm-empty col-span-full py-20 text-center rounded-[35px]">
                    <div class="bm-card-icon w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-code-branch text-3xl"></i>
                    </div>
                    <h3 class="bm-name brand-font text-2xl font-black">No stores mapped yet</h3>
                    <p class="text-gray-400 mt-2 font-medium">Add a branch to begin tracking inventory per location.</p>
                </div>
            `;
            return;
        }

        let html = '';
        snap.forEach(doc => {
            const data = doc.data();
            const id = doc.id;
            branchCache[id] = data;

            // Older branches were created before radius existed: no radius = no limit.
            const radius = Number(data.deliveryRadiusKm);
            const hasRadius = Number.isFinite(radius) && radius > 0;
            const radiusHtml = hasRadius
                ? `<i class="fa-solid fa-bullseye mr-2 bm-accent"></i>Delivers within <b class="bm-strong">${esc(radius)} km</b>`
                : `<i class="fa-solid fa-triangle-exclamation mr-2 bm-accent"></i><span class="bm-accent">No radius set - delivers anywhere. Edit to set one.</span>`;

            html += `
                <div class="card p-8 group relative">
                    <div class="flex justify-between items-start mb-6">
                        <div class="bm-card-icon w-14 h-14 rounded-2xl flex items-center justify-center">
                            <i class="fa-solid fa-store text-2xl"></i>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="editBranch('${esc(id)}')" title="Edit branch" class="bm-card-btn w-8 h-8 rounded-lg transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                                <i class="fa-solid fa-pen-to-square text-sm"></i>
                            </button>
                            <button onclick="deleteBranch('${esc(id)}')" title="Delete branch" class="bm-card-btn w-8 h-8 rounded-lg transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                                <i class="fa-solid fa-trash-can text-sm"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-6">
                        <h3 class="bm-name brand-font font-black text-2xl">${esc(data.name || id)}</h3>
                    </div>

                    <p class="bm-address text-xs font-semibold leading-relaxed line-clamp-2 min-h-[32px]">
                        <i class="fa-solid fa-location-dot mr-2 bm-accent"></i>
                        ${esc(data.location || data.address || 'No physical address provided')}
                    </p>

                    <p class="bm-address text-xs font-semibold leading-relaxed mt-3">
                        ${radiusHtml}
                    </p>

                    <div class="bm-divider mt-8 pt-6 flex justify-between items-center">
                        <div class="flex items-center gap-2">
                            <span class="bm-dot w-2 h-2 rounded-full"></span>
                            <span class="text-[9px] font-black tracking-[0.2em] text-gray-400 uppercase">Operational</span>
                        </div>
                        <button onclick="setBranch('${esc(id)}')" class="bm-switch text-[10px] font-black uppercase tracking-[0.2em] transition-colors">
                            Switch Access
                        </button>
                    </div>
                </div>
            `;
        });
        branchList.innerHTML = html;
    });

    // Opens the modal in "edit" mode, filled from the cached branch data.
    function editBranch(id) {
        const data = branchCache[id];
        if (!data) {
            alert('This branch could not be found. It may have just been deleted.');
            return;
        }

        resetModalToAddMode();
        editingBranchId = id;
        document.getElementById('branchName').value = data.name || '';
        branchIdInput.value = id;
        branchIdInput.disabled = true;
        document.getElementById('branchLocation').value = data.location || data.address || '';

        // Older branches saved 0 / 0 when no coordinates were typed. Show those
        // (and a missing radius) as empty so the admin has to enter real values.
        const lat = Number(data.latitude), lng = Number(data.longitude);
        const hasCoords = Number.isFinite(lat) && Number.isFinite(lng) && !(lat === 0 && lng === 0);
        document.getElementById('branchLat').value = hasCoords ? lat : '';
        document.getElementById('branchLng').value = hasCoords ? lng : '';
        const radius = Number(data.deliveryRadiusKm);
        document.getElementById('branchRadius').value = Number.isFinite(radius) && radius > 0 ? radius : '';

        modalTitle.innerText = 'Edit Branch Node';
        submitBtn.innerText = 'Update Regional Node';
        openModal();
    }

    // One submit handler for both modes -- editingBranchId decides which.
    branchForm.onsubmit = async (e) => {
        e.preventDefault();
        showFormError('');

        const bName = document.getElementById('branchName').value.trim();
        const bLoc = document.getElementById('branchLocation').value.trim();
        if (!bName) {
            showFormError('Please enter the branch name.');
            return;
        }

        const geo = readGeoFields();
        if (geo.error) {
            showFormError(geo.error);
            return;
        }

        // `location` (web) and `address` (app) are kept identical on purpose.
        const fields = {
            name: bName,
            location: bLoc,
            address: bLoc,
            latitude: geo.lat,
            longitude: geo.lng,
            deliveryRadiusKm: geo.radius
        };

        submitBtn.disabled = true;
        try {
            if (editingBranchId) {
                // --- EDIT an existing branch ---
                await db.collection('branches').doc(editingBranchId).update(fields);
            } else {
                // --- CREATE a new branch ---
                const bId = branchIdInput.value.trim().toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
                if (!bId) {
                    showFormError('Please enter a Branch ID (lowercase letters, numbers and underscores).');
                    return;
                }

                const existing = await db.collection('branches').doc(bId).get();
                if (existing.exists) {
                    showFormError('Branch ID already exists! Please use a different one.');
                    return;
                }

                await db.collection('branches').doc(bId).set({
                    ...fields,
                    createdAt: firebase.firestore.FieldValue.serverTimestamp()
                });
            }
            closeModal();
        } catch (error) {
            showFormError((editingBranchId ? 'Update failed: ' : 'Error creating branch: ') + error.message);
        } finally {
            submitBtn.disabled = false;
        }
    };

    async function deleteBranch(id) {
        if (id === 'main_branch') {
            alert('Main Branch cannot be deleted.');
            return;
        }

        if (confirm('Are you sure you want to delete this branch? Inventory data within this branch will NOT be deleted but will become inaccessible via the standard UI.')) {
            try {
                await db.collection('branches').doc(id).delete();
            } catch (error) {
                alert('Error deleting branch: ' + error.message);
            }
        }
    }
</script>

<?php include 'templates/footer.php'; ?>