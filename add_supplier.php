<?php 
/**
 * BLOOMINOUS - Add Supplier (Firebase Spoke)
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

<!-- Modern CSS Styles -->
<style>
    :root {
        --primary-color: #6c5ce7;
        --secondary-color: #a29bfe;
        --bg-color: #f8f9fa;
        --text-dark: #2d3436;
        --text-muted: #636e72;
        --white: #ffffff;
        --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    }

    body { background-color: var(--bg-color); font-family: 'Inter', 'Poppins', sans-serif; }

    .main-content { display: flex; justify-content: center; align-items: center; min-height: 80vh; padding: 20px; }

    .form-card {
        background: var(--white);
        width: 100%;
        max-width: 500px;
        padding: 40px;
        border-radius: 24px;
        box-shadow: var(--shadow);
    }

    .form-header { text-align: center; margin-bottom: 30px; }
    .form-header .icon-box {
        width: 60px; height: 60px; background: var(--secondary-color);
        color: var(--primary-color); border-radius: 15px;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 15px; font-size: 1.5rem;
    }
    .form-header h2 { font-weight: 700; color: var(--text-dark); margin: 0; font-size: 1.5rem; }
    .form-header p { color: var(--text-muted); font-size: 0.9rem; }

    .input-group { margin-bottom: 20px; }
    .input-group label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-dark); font-size: 0.85rem; }
    
    .input-wrapper { position: relative; }
    .input-wrapper i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
    
    .input-wrapper input, .input-wrapper textarea {
        width: 100%; padding: 12px 15px 12px 45px;
        border: 1.5px solid #eee; border-radius: 12px;
        background: #fafafa; transition: 0.3s; font-size: 0.95rem;
    }

    .input-wrapper input:focus, .input-wrapper textarea:focus {
        border-color: var(--primary-color); background: #fff; outline: none;
        box-shadow: 0 0 0 4px rgba(108, 92, 231, 0.1);
    }

    .btn-container { margin-top: 30px; }
    .btn-save {
        width: 100%; padding: 14px; background: var(--primary-color);
        color: white; border: none; border-radius: 12px;
        font-weight: 600; cursor: pointer; transition: 0.3s; font-size: 1rem;
    }
    .btn-save:hover { background: #5849d4; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(108, 92, 231, 0.3); }

    .btn-cancel {
        display: block; text-align: center; margin-top: 15px;
        color: var(--text-muted); text-decoration: none; font-size: 0.85rem; transition: 0.2s;
    }
    .btn-cancel:hover { color: var(--primary-color); }

    .alert { padding: 12px; border-radius: 10px; margin-bottom: 20px; font-size: 0.85rem; }
    .alert-danger { background: #ffeef0; color: #d63031; border: 1px solid #fab1a0; }
</style>

<div class="main-content">
    <div class="form-card">
        <div class="form-header">
            <div class="icon-box"><i class="fas fa-plus"></i></div>
            <h2>Add New Supplier</h2>
            <p>Enter the details to register a new supplier</p>
        </div>

        <form id="addSupplierForm">
            <div class="input-group">
                <label>Company Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-building"></i>
                    <input type="text" id="supplierName" placeholder="Ex. Bloom Floral Shop" required>
                </div>
            </div>

            <div class="input-group">
                <label>Contact Person</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="contactPerson" placeholder="Representative Name" required>
                </div>
            </div>

            <div class="input-group">
                <label>Phone Number</label>
                <div class="input-wrapper">
                    <i class="fas fa-phone"></i>
                    <input type="text" id="phone" placeholder="0912 345 6789" required>
                </div>
            </div>

            <div class="input-group">
                <label>Office Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-map-marker-alt" style="top: 20px;"></i>
                    <textarea id="address" rows="3" placeholder="Full Business Address" required style="padding-top: 12px;"></textarea>
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
    document.getElementById('addSupplierForm').onsubmit = async (e) => {
        e.preventDefault();
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerText = 'Creating...';

        try {
            await db.collection('suppliers').add({
                supplier_name: document.getElementById('supplierName').value,
                contact_person: document.getElementById('contactPerson').value,
                phone: document.getElementById('phone').value,
                address: document.getElementById('address').value,
                created_at: firebase.firestore.FieldValue.serverTimestamp()
            });
            alert('Supplier Added Successfully!');
            window.location.href = 'supplier.php';
        } catch (err) {
            alert('Error: ' + err.message);
            btn.disabled = false;
            btn.innerText = 'Create Supplier';
        }
    };
</script>

<?php include 'templates/footer.php'; ?>