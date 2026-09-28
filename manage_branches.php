<?php
/**
 * BLOOMINOUS - Manage Branches
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
    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>.
       .card (the branch cards) already follows the theme via header.php;
       these rules cover the Tailwind classes on this page and its modal.
       :is() lists the page and the modal once -- the modal sits outside
       the page wrapper in the HTML. */
    html[data-theme="dark"] :is(.branches-content, #modalContent) :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] :is(.branches-content, #modalContent) :is(.text-gray-500, .text-gray-400, .text-gray-300) { color: var(--text-light); }
    html[data-theme="dark"] :is(.branches-content, #modalContent) .bg-white { background-color: var(--surface); }
    html[data-theme="dark"] :is(.branches-content, #modalContent) :is(.bg-gray-50, .bg-gray-100) { background-color: var(--surface-alt); }
    html[data-theme="dark"] :is(.branches-content, #modalContent) :is(.border-gray-50, .border-gray-100) { border-color: var(--border-color); }
    html[data-theme="dark"] #modalContent :is(input, select) { color: var(--text-main); color-scheme: dark; }
    html[data-theme="dark"] #modalContent input:disabled { opacity: 0.6; }

    /* Pale pink chips -> translucent pink tints */
    html[data-theme="dark"] .branches-content .bg-pink-50 { background-color: rgba(236, 72, 153, 0.14); }
    html[data-theme="dark"] .branches-content .border-pink-50 { border-color: rgba(236, 72, 153, 0.25); }
    html[data-theme="dark"] .branches-content .text-pink-200 { color: rgba(236, 72, 153, 0.55); }

    /* Hover states must be re-declared: the dark "at rest" rules above
       are more specific than Tailwind's hover classes and would block them. */
    html[data-theme="dark"] .branches-content .hover\:bg-indigo-50:hover { background-color: rgba(99, 102, 241, 0.18); }
    html[data-theme="dark"] .branches-content .hover\:bg-pink-50:hover { background-color: rgba(236, 72, 153, 0.18); }
    html[data-theme="dark"] .branches-content .hover\:text-indigo-500:hover { color: #818cf8; }
    html[data-theme="dark"] .branches-content .hover\:text-pink-500:hover { color: #f472b6; }
    html[data-theme="dark"] #modalContent .hover\:bg-gray-200:hover { background-color: var(--border-color); }
    html[data-theme="dark"] #modalContent .hover\:text-pink-500:hover { color: #f472b6; }

    /* Soft pink glow under the Add button looks like a smudge on dark */
    html[data-theme="dark"] .branches-content .shadow-pink-200\/50 { box-shadow: none; }
    html[data-theme="dark"] #modalContent { box-shadow: none; }
</style>

<div class="branches-content max-w-7xl mx-auto px-6 py-8">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-16 gap-8">
        <div>
            <h1 class="brand-font text-6xl font-black text-gray-800 tracking-tight">Manage Branches</h1>
            <p class="text-gray-400 text-sm mt-1 font-medium italic">Manage flower shop branches and operational status.</p>
        </div>
        <button onclick="openAddModal()" class="btn-primary shadow-2xl shadow-pink-200/50 flex items-center px-10 py-4 rounded-3xl font-black text-xs uppercase tracking-[0.2em] transform hover:-translate-y-1 transition-all">
            <i class="fa-solid fa-plus-circle mr-3 text-lg"></i>
            <span>Add New Branch</span>
        </button>
    </div>

    <!-- Branch List -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8" id="branch-list">
        <!-- Loading State -->
        <div class="col-span-full py-20 text-center">
            <i class="fa-solid fa-circle-notch fa-spin text-4xl text-pink-200"></i>
            <p class="mt-4 text-gray-400 font-medium tracking-wide text-xs uppercase">Connecting to Cloud Firestore...</p>
        </div>
    </div>
</div>

<!-- Add / Edit Branch Modal -->
<div id="addModal" class="fixed inset-0 bg-black/40 backdrop-blur-md hidden z-[300] flex items-center justify-center p-4">
    <div class="bg-white rounded-[35px] w-full max-w-md shadow-2xl overflow-hidden scale-95 opacity-0 transition-all duration-300 transform border border-gray-100" id="modalContent">
        <div class="p-10">
            <div class="flex justify-between items-center mb-8">
                <h2 class="brand-font text-3xl font-black text-gray-800" id="modalTitle">New Branch</h2>
                <button onclick="closeModal()" class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center text-gray-400 hover:text-pink-500 transition-colors">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>

            <form id="branchForm" class="space-y-6">
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-3 ml-1">Branch Name</label>
                    <input type="text" id="branchName" required placeholder="e.g., Marilao Branch" class="w-full bg-gray-50 border border-gray-100 rounded-2xl px-6 py-4 focus:outline-none focus:ring-2 focus:ring-pink-500/10 transition-all font-semibold text-sm">
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-3 ml-1">Branch ID (Unique Key)</label>
                    <input type="text" id="branchId" required placeholder="e.g., marilao_main" class="w-full bg-gray-50 border border-gray-100 rounded-2xl px-6 py-4 focus:outline-none focus:ring-2 focus:ring-pink-500/10 transition-all font-semibold text-sm">
                    <p class="text-[9px] text-gray-400 mt-2 italic px-1">Internal identifier. Use lowercase and underscores only.</p>
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-3 ml-1">Physical Address</label>
                    <input type="text" id="branchLocation" placeholder="e.g., SM Marilao, Bulacan" class="w-full bg-gray-50 border border-gray-100 rounded-2xl px-6 py-4 focus:outline-none focus:ring-2 focus:ring-pink-500/10 transition-all font-semibold text-sm">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-3 ml-1">Latitude</label>
                        <input type="number" step="any" id="branchLat" placeholder="14.7573" class="w-full bg-gray-50 border border-gray-100 rounded-2xl px-6 py-4 focus:outline-none focus:ring-2 focus:ring-pink-500/10 transition-all font-semibold text-sm">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-3 ml-1">Longitude</label>
                        <input type="number" step="any" id="branchLng" placeholder="120.9439" class="w-full bg-gray-50 border border-gray-100 rounded-2xl px-6 py-4 focus:outline-none focus:ring-2 focus:ring-pink-500/10 transition-all font-semibold text-sm">
                    </div>
                </div>

                <div class="pt-6 flex gap-4">
                    <button type="button" onclick="closeModal()" class="flex-1 px-6 py-4 rounded-2xl font-bold text-gray-500 bg-gray-100 hover:bg-gray-200 transition-all text-sm">Cancel</button>
                    <button type="submit" id="branchSubmitBtn" class="flex-[2] btn-primary justify-center py-4 text-sm">Create Store Space</button>
                </div>
            </form>
        </div>
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

    // Latest branch data, keyed by id. Edit reads from here instead of
    // having the name/address pasted into the HTML -- that pasting broke
    // on any name containing an apostrophe (e.g. "Rose's Garden").
    let branchCache = {};

    // null = the modal is in "add" mode; a branch id = "editing that branch".
    // One variable decides the mode, so the modal can't get stuck halfway.
    let editingBranchId = null;

    // Puts the modal back into a clean "Add New Branch" state.
    function resetModalToAddMode() {
        editingBranchId = null;
        branchForm.reset();
        branchIdInput.disabled = false;
        modalTitle.innerText = 'New Branch';
        submitBtn.innerText = 'Create Store Space';
    }

    function openModal() {
        addModal.classList.remove('hidden');
        setTimeout(() => {
            modalContent.classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function openAddModal() {
        resetModalToAddMode();
        openModal();
    }

    // FIX: closing the modal (Cancel, X, or after saving) ALWAYS resets it,
    // so a cancelled Edit can never leak into the next "Add New Branch".
    function closeModal() {
        modalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            addModal.classList.add('hidden');
            resetModalToAddMode();
        }, 300);
    }

    // Load Branches Real-time
    db.collection('branches').onSnapshot(snap => {
        branchCache = {};

        if (snap.empty) {
            branchList.innerHTML = `
                <div class="col-span-full py-20 text-center bg-white rounded-[35px] border-2 border-dashed border-pink-50">
                    <div class="w-20 h-20 bg-pink-50 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-code-branch text-pink-200 text-3xl"></i>
                    </div>
                    <h3 class="brand-font text-2xl font-black text-gray-800">No stores mapped yet</h3>
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
            html += `
                <div class="card p-8 group relative">
                    <div class="flex justify-between items-start mb-6">
                        <div class="w-14 h-14 bg-pink-50 rounded-2xl flex items-center justify-center text-pink-500">
                            <i class="fa-solid fa-store text-2xl"></i>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="editBranch('${id}')" title="Edit branch" class="w-8 h-8 rounded-lg bg-gray-50 text-gray-300 hover:text-indigo-500 hover:bg-indigo-50 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                                <i class="fa-solid fa-pen-to-square text-sm"></i>
                            </button>
                            <button onclick="deleteBranch('${id}')" title="Delete branch" class="w-8 h-8 rounded-lg bg-gray-50 text-gray-300 hover:text-pink-500 hover:bg-pink-50 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                                <i class="fa-solid fa-trash-can text-sm"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-6">
                        <h3 class="brand-font font-black text-gray-800 text-2xl">${data.name || id}</h3>
                    </div>
                    
                    <p class="text-gray-500 text-xs font-semibold leading-relaxed line-clamp-2 min-h-[32px]">
                        <i class="fa-solid fa-location-dot mr-2 text-pink-300"></i>
                        ${data.location || 'No physical address provided'}
                    </p>
                    
                    <div class="mt-8 pt-6 border-t border-gray-50 flex justify-between items-center">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-pink-500 rounded-full shadow-[0_0_8px_rgba(233,30,99,0.5)]"></span>
                            <span class="text-[9px] font-black tracking-[0.2em] text-gray-400 uppercase">Operational</span>
                        </div>
                        <button onclick="setBranch('${id}')" class="text-[10px] font-black uppercase tracking-[0.2em] text-pink-500 hover:text-pink-700 transition-colors">
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

        editingBranchId = id;
        document.getElementById('branchName').value = data.name || '';
        branchIdInput.value = id;
        branchIdInput.disabled = true;
        document.getElementById('branchLocation').value = data.location || '';
        document.getElementById('branchLat').value = data.latitude || 0;
        document.getElementById('branchLng').value = data.longitude || 0;

        modalTitle.innerText = 'Edit Branch Node';
        submitBtn.innerText = 'Update Regional Node';
        openModal();
    }

    // One submit handler for both modes -- editingBranchId decides which.
    branchForm.onsubmit = async (e) => {
        e.preventDefault();
        const bName = document.getElementById('branchName').value;
        const bLoc = document.getElementById('branchLocation').value;
        const bLat = parseFloat(document.getElementById('branchLat').value) || 0;
        const bLng = parseFloat(document.getElementById('branchLng').value) || 0;

        submitBtn.disabled = true;
        try {
            if (editingBranchId) {
                // --- EDIT an existing branch ---
                await db.collection('branches').doc(editingBranchId).update({
                    name: bName,
                    location: bLoc,
                    latitude: bLat,
                    longitude: bLng
                });
            } else {
                // --- CREATE a new branch ---
                const bId = branchIdInput.value.toLowerCase().replace(/\s+/g, '_');

                const existing = await db.collection('branches').doc(bId).get();
                if (existing.exists) {
                    alert('Branch ID already exists! Please use a different one.');
                    return;
                }

                await db.collection('branches').doc(bId).set({
                    name: bName,
                    location: bLoc,
                    latitude: bLat,
                    longitude: bLng,
                    createdAt: firebase.firestore.FieldValue.serverTimestamp()
                });
            }
            closeModal();
        } catch (error) {
            alert((editingBranchId ? 'Update failed: ' : 'Error creating branch: ') + error.message);
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