<?php 
/**
 * BLOOMINOUS - Edit Supplier (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

$id = $_GET['id'] ?? null;
if (!$id) { header("Location: supplier.php"); exit(); }

include 'templates/header.php'; 
?>

<style>
    :root {
        --primary-color: #6c5ce7;
        --accent-color: #fd9644;
        --bg-color: #fdfae7;
        --text-dark: #2d3436;
        --text-muted: #636e72;
        --white: #ffffff;
        --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    }

    body { background-color: var(--bg-color); font-family: 'Poppins', sans-serif; }

    .main-content { 
        display: flex; 
        justify-content: center; 
        align-items: center; 
        min-height: 80vh; 
        padding: 40px 20px; 
    }

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
        width: 60px; height: 60px; 
        background: #fff4e6;
        color: var(--accent-color); 
        border-radius: 15px;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 15px; font-size: 1.5rem;
    }
    .form-header h2 { font-weight: 700; color: var(--text-dark); margin: 0; font-size: 1.5rem; }
    .form-header p { color: var(--text-muted); font-size: 0.9rem; }

    .input-group { margin-bottom: 20px; text-align: left; }
    .input-group label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-dark); font-size: 0.85rem; }
    
    .input-wrapper { position: relative; }
    .input-wrapper i { 
        position: absolute; 
        left: 15px; 
        top: 50%; 
        transform: translateY(-50%); 
        color: var(--text-muted); 
    }
    
    .input-wrapper input, .input-wrapper textarea {
        width: 100%; 
        padding: 12px 15px 12px 45px;
        border: 1.5px solid #eee; 
        border-radius: 12px;
        background: #fafafa; 
        transition: 0.3s; 
        font-size: 0.95rem;
        box-sizing: border-box;
    }

    .input-wrapper input:focus, .input-wrapper textarea:focus {
        border-color: var(--accent-color); 
        background: #fff; 
        outline: none;
        box-shadow: 0 0 0 4px rgba(253, 150, 68, 0.1);
    }

    .btn-container { margin-top: 30px; }
    .btn-save {
        width: 100%; 
        padding: 14px; 
        background: var(--accent-color);
        color: white; 
        border: none; 
        border-radius: 12px;
        font-weight: 600; 
        cursor: pointer; 
        transition: 0.3s; 
        font-size: 1rem;
    }
    .btn-save:hover { 
        background: #e67e22; 
        transform: translateY(-2px); 
        box-shadow: 0 5px 15px rgba(253, 150, 68, 0.3); 
    }

    .btn-cancel {
        display: block; 
        text-align: center; 
        margin-top: 15px;
        color: var(--text-muted); 
        text-decoration: none; 
        font-size: 0.85rem; 
        transition: 0.2s;
    }
    .btn-cancel:hover { color: var(--accent-color); }
</style>

<div class="main-content">
    <div class="form-card">
        <div class="form-header">
            <div class="icon-box"><i class="fas fa-edit"></i></div>
            <h2>Update Supplier</h2>
            <p>Modify the information for this supplier</p>
        </div>

        <form id="editSupplierForm">
            <div class="input-group">
                <label>Company Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-building"></i>
                    <input type="text" id="supplierName" required>
                </div>
            </div>

            <div class="input-group">
                <label>Contact Person</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="contactPerson" required>
                </div>
            </div>

            <div class="input-group">
                <label>Phone Number</label>
                <div class="input-wrapper">
                    <i class="fas fa-phone"></i>
                    <input type="text" id="phone" required>
                </div>
            </div>

            <div class="input-group">
                <label>Office Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-map-marker-alt" style="top: 20px; transform: none;"></i>
                    <textarea id="address" rows="3" required style="padding-top: 12px;"></textarea>
                </div>
            </div>

            <div class="btn-container">
                <button type="submit" id="saveBtn" class="btn-save">
                    Save Changes
                </button>
                <a href="supplier.php" class="btn-cancel">Discard Changes & Go Back</a>
            </div>
        </form>
    </div>
</div>

<script>
    const supplierId = "<?php echo $id; ?>";

    document.addEventListener('DOMContentLoaded', async () => {
        try {
            const doc = await db.collection('suppliers').doc(supplierId).get();
            if (!doc.exists) {
                alert('Supplier not found!');
                window.location.href = 'supplier.php';
                return;
            }
            const s = doc.data();
            document.getElementById('supplierName').value = s.supplier_name;
            document.getElementById('contactPerson').value = s.contact_person;
            document.getElementById('phone').value = s.phone;
            document.getElementById('address').value = s.address;
        } catch (err) {
            alert('Error loading supplier: ' + err.message);
        }
    });

    document.getElementById('edit_supplierForm').onsubmit = async (e) => {
        e.preventDefault();
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerText = 'Saving...';

        try {
            await db.collection('suppliers').doc(supplierId).update({
                supplier_name: document.getElementById('supplierName').value,
                contact_person: document.getElementById('contactPerson').value,
                phone: document.getElementById('phone').value,
                address: document.getElementById('address').value
            });
            alert('Updated Successfully!');
            window.location.href = 'supplier.php';
        } catch (err) {
            alert('Error: ' + err.message);
            btn.disabled = false;
            btn.innerText = 'Save Changes';
        }
    };
</script>

<?php include 'templates/footer.php'; ?>