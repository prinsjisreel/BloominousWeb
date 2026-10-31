<?php 
/**
 * BLOOMINOUS - Add Supplier (Firebase Spoke)
 *
 * Layout note: templates/header.php already opens <div class="main-content">
 * (the area to the right of the sidebar, which also holds the top bar) and
 * footer.php closes it. This page must NOT redefine .main-content - doing so
 * (the old version did) re-centred the top bar and pushed the form off to
 * the right. The form now lives in its own .supplier-wrap instead.
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

<!-- Every colour comes from the shared theme variables in header.php
     (--surface, --surface-alt, --border-color, --text-main, --text-light,
     --primary), so this page follows light AND dark mode automatically. -->
<style>
    .supplier-wrap { display: flex; justify-content: center; align-items: flex-start; padding: 1rem 0 2rem; }

    .form-card {
        background: var(--surface);
        width: 100%;
        max-width: 520px;
        padding: clamp(1.5rem, 5vw, 2.75rem);
        border-radius: 28px;
        border: 1px solid var(--border-color);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
    }

    .form-header { text-align: center; margin-bottom: 1.75rem; }
    .form-header .icon-box {
        width: 64px; height: 64px;
        background: rgba(245, 158, 11, 0.14);
        color: var(--primary);
        border: 1px solid rgba(245, 158, 11, 0.35);
        border-radius: 18px;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 1rem; font-size: 1.5rem;
    }
    .form-header h2 { font-family: 'Cormorant Garamond', serif; font-weight: 900; color: var(--text-main); margin: 0; font-size: clamp(1.5rem, 4vw, 1.9rem); }
    .form-header p { color: var(--text-light); font-size: 0.9rem; margin-top: 0.25rem; }

    .input-group { margin-bottom: 1.25rem; }
    .input-group label { display: block; margin-bottom: 8px; font-weight: 700; color: var(--text-main); font-size: 0.85rem; }

    .input-wrapper { position: relative; }
    .input-wrapper i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-light); pointer-events: none; }
    .input-wrapper i.top-icon { top: 22px; transform: none; }

    .input-wrapper input, .input-wrapper textarea {
        width: 100%; padding: 13px 15px 13px 46px;
        border: 1.5px solid var(--border-color); border-radius: 14px;
        background: var(--surface-alt); color: var(--text-main);
        transition: 0.25s; font-size: 0.95rem; font-family: inherit;
    }
    .input-wrapper textarea { resize: vertical; min-height: 96px; padding-top: 13px; }
    .input-wrapper input::placeholder, .input-wrapper textarea::placeholder { color: var(--text-light); opacity: 0.8; }

    .input-wrapper input:focus, .input-wrapper textarea:focus {
        border-color: var(--primary); outline: none;
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.18);
    }
    .input-wrapper:focus-within i { color: var(--primary); }

    .btn-container { margin-top: 1.75rem; }
    .btn-save {
        width: 100%; padding: 14px; background: var(--primary);
        color: #1C1814; /* dark letters stay readable on amber in both themes */
        border: none; border-radius: 14px;
        font-weight: 800; letter-spacing: 0.02em; cursor: pointer; transition: 0.25s; font-size: 1rem;
    }
    .btn-save:hover:not(:disabled) { filter: brightness(1.08); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3); }
    .btn-save:disabled { opacity: 0.6; cursor: not-allowed; }

    .btn-cancel {
        display: block; text-align: center; margin-top: 1rem;
        color: var(--text-light); text-decoration: none; font-size: 0.85rem; font-weight: 600; transition: 0.2s;
    }
    .btn-cancel:hover { color: var(--primary); }

    .alert { padding: 12px 14px; border-radius: 12px; margin-bottom: 1.25rem; font-size: 0.85rem; font-weight: 600; display: none; }
    .alert.show { display: block; }
    .alert-danger  { background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.35); }
    .alert-success { background: rgba(34, 197, 94, 0.12); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.35); }

    /* Phones: tighter card; 16px inputs stop iOS from zooming in on focus */
    @media (max-width: 640px) {
        .supplier-wrap { padding-top: 0.5rem; }
        .form-card { border-radius: 22px; }
        .input-wrapper input, .input-wrapper textarea { font-size: 16px; }
    }
</style>

<div class="supplier-wrap">
    <div class="form-card">
        <div class="form-header">
            <div class="icon-box"><i class="fas fa-plus"></i></div>
            <h2>Add New Supplier</h2>
            <p>Enter the details to register a new supplier</p>
        </div>

        <!-- Messages appear here instead of a pop-up alert() -->
        <div id="formMessage" class="alert" role="alert"></div>

        <form id="addSupplierForm">
            <div class="input-group">
                <label for="supplierName">Company Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-building"></i>
                    <input type="text" id="supplierName" placeholder="Ex. Bloom Floral Shop" autocomplete="organization" required>
                </div>
            </div>

            <div class="input-group">
                <label for="contactPerson">Contact Person</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="contactPerson" placeholder="Representative Name" autocomplete="name" required>
                </div>
            </div>

            <div class="input-group">
                <label for="phone">Phone Number</label>
                <div class="input-wrapper">
                    <i class="fas fa-phone"></i>
                    <input type="tel" id="phone" placeholder="0912 345 6789" inputmode="tel" autocomplete="tel" required>
                </div>
            </div>

            <div class="input-group">
                <label for="address">Office Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-map-marker-alt top-icon"></i>
                    <textarea id="address" rows="3" placeholder="Full Business Address" autocomplete="street-address" required></textarea>
                </div>
            </div>

            <div class="btn-container">
                <button type="submit" id="saveBtn" class="btn-save">
                    Create Supplier
                </button>
                <a href="supplier.php" class="btn-cancel">Back to Supplier List</a>
            </div>
        </form>
    </div>
</div>

<script>
    // Shows a message box above the form: kind = 'danger' | 'success'.
    function showMessage(kind, text) {
        const box = document.getElementById('formMessage');
        box.className = 'alert alert-' + kind + ' show';
        box.textContent = text;
    }

    document.getElementById('addSupplierForm').onsubmit = async (e) => {
        e.preventDefault();
        const btn = document.getElementById('saveBtn');

        // trim() removes accidental leading/trailing spaces before saving.
        const data = {
            supplier_name: document.getElementById('supplierName').value.trim(),
            contact_person: document.getElementById('contactPerson').value.trim(),
            phone: document.getElementById('phone').value.trim(),
            address: document.getElementById('address').value.trim()
        };

        // A field with only spaces passes the browser's "required" check,
        // so check it ourselves.
        if (Object.values(data).some(v => v === '')) {
            showMessage('danger', 'Please fill in every field.');
            return;
        }

        btn.disabled = true;
        btn.innerText = 'Creating...';

        try {
            await db.collection('suppliers').add({
                ...data,
                created_at: firebase.firestore.FieldValue.serverTimestamp()
            });
            showMessage('success', 'Supplier added successfully! Redirecting...');
            setTimeout(() => { window.location.href = 'supplier.php'; }, 700);
        } catch (err) {
            showMessage('danger', 'Error: ' + err.message);
            btn.disabled = false;
            btn.innerText = 'Create Supplier';
        }
    };
</script>

<?php include 'templates/footer.php'; ?>