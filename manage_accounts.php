<?php   
/**
 * BLOOMINOUS - Account Management (Staff & Delivery)
 */
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Security Check - Admin or Super Admin
if (!isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

$userRole = $_SESSION['role'] ?? 'admin';
include 'templates/header.php';  
?>

<style>
    .manage-content { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
    .page-header { margin-bottom: 3.5rem; }
         
    label { font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 1.5px; }
    input, select { width: 100%; padding: 15px 18px; border: 1px solid #f0f0f0; border-radius: 15px; outline: none; font-size: 0.9rem; background: #fafafa; transition: 0.3s; font-weight: 600; }
    input:focus, select:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 15px rgba(233, 30, 99, 0.05); }
         
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; padding: 25px 20px; color: var(--text-light); font-size: 0.75rem; text-transform: uppercase; font-weight: 800; border-bottom: 1px solid #f0f0f0; letter-spacing: 1px; background: #fafafa; }
    td { padding: 20px; border-bottom: 1px solid #f8f9fa; font-size: 0.9rem; color: var(--text-main); font-weight: 500; }
         
    .badge { padding: 6px 16px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
    .badge-super-admin { background: #000; color: #fff; }
    .badge-admin { background: rgba(233, 30, 99, 0.1); color: var(--primary); }
    .badge-staff { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .badge-delivery { background: rgba(255, 177, 66, 0.1); color: #f39c12; }
         
    .alert { padding: 18px 24px; border-radius: 20px; margin-bottom: 30px; font-weight: 800; font-size: 0.8rem; display: none; text-align: center; text-transform: uppercase; letter-spacing: 1px; }
    .delete-btn { width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; background: #fff5f8; color: var(--primary); transition: 0.3s; border: none; cursor: pointer; }
    .delete-btn:hover { background: var(--primary); color: white; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(233, 30, 99, 0.15); }
    .form-section-title { font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; font-weight: 900; color: var(--text-main); border-bottom: 3px solid var(--primary); display: inline-block; padding-bottom: 8px; margin-bottom: 30px; }
</style>

<main class="manage-content">
    <div class="page-header">
        <h1 class="brand-font text-5xl font-black text-gray-800">Manage Accounts</h1>
        <p class="text-gray-400 font-medium text-sm mt-1">Manage employee accounts, roles, and branch assignments.</p>
    </div>

    <div id="success-alert" class="alert" style="background: rgba(46, 204, 113, 0.1); color: #27ae60;"></div>
    <div id="error-alert" class="alert" style="background: #fff5f8; color: var(--primary);"></div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- ADD ACCOUNT FORM -->
        <div class="lg:col-span-1">
            <div class="card bg-white">
                <h4 class="form-section-title"><i class="fa-solid fa-user-plus mr-2" style="color: var(--primary);"></i> Register New</h4>
                <form id="addAccountForm" class="mt-4">
                    <div class="grid grid-cols-1 gap-4">
                        <div class="form-group">
                            <label>Legal Name</label>
                            <input type="text" id="accFirstName" required placeholder="First Name">
                        </div>
                        <div class="form-group">
                            <input type="text" id="accMiddleName" placeholder="Middle Name (Optional)">
                        </div>
                        <div class="form-group">
                            <input type="text" id="accLastName" required placeholder="Last Name">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 mt-2">
                        <div class="form-group">
                            <label>Birthday</label>
                            <input type="date" id="accBirthday" required>
                        </div>
                        <div class="form-group">
                            <label>Sex</label>
                            <select id="accSex" required>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group mt-2">
                        <label>Login Credentials (Email)</label>
                        <input type="email" id="accUser" required placeholder="staff@bloom.com">
                    </div>
                    <div class="grid grid-cols-2 gap-4 mt-2">
                        <div class="form-group">
                            <label>Organization Role</label>
                            <select id="accRole" required>
                                <?php if ($userRole === 'super-admin'): ?>
                                <option value="admin">Shop Admin (Owner)</option>
                                <?php endif; ?>
                                <option value="employee">Staff / Intern</option>
                                <option value="delivery">Logistics Fleet</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Access Key</label>
                            <input type="password" id="accPass" required placeholder="••••••••">
                        </div>
                    </div>
                    <div class="form-group mt-2">
                        <label>Assigned Outlet</label>
                        <select id="accBranch" required>
                            <option value="">Loading branches...</option>
                        </select>
                    </div>
                                         
                    <button type="submit" id="addAccountBtn" class="btn-primary w-full justify-center py-4 mt-4 text-sm uppercase tracking-widest">Deploy Account</button>
                </form>
            </div>
        </div>

        <!-- ACCOUNTS TABLE -->
        <div class="lg:col-span-2">
            <div class="card bg-white p-0 overflow-hidden">
                <div class="p-8 border-b border-gray-50 flex justify-between items-center">
                    <h4 class="brand-font text-2xl font-black text-gray-800"><i class="fa-solid fa-users mr-2 text-[#7B79F2]"></i> Active Employees</h4>
                </div>
                <div class="overflow-x-auto">
                    <table>
                        <thead>
                            <tr>
                                Dedication<th>Employee</th>
                                <th>Assignment</th>
                                <th>Branch</th>
                                <th style="text-align: right;">Removal</th>
                            </tr>
                        </thead>
                        <tbody id="accountListData">
                            <tr><td colspan="4" style="text-align:center; padding: 60px;" class="text-gray-300 font-medium italic">Establishing connection to database...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    const currentRole = '<?php echo $userRole; ?>';
    document.addEventListener('DOMContentLoaded', () => {
        const accountListData = document.getElementById('accountListData');
        const accBranchSelect = document.getElementById('accBranch');
        let branchMap = {};

        // Load Branches for dropdown and mapping
        db.collection('branches').onSnapshot(snap => {
            let options = '<option value="">Select Branch</option>';
            branchMap = {};
            snap.forEach(doc => {
                const b = doc.data();
                const bName = b.name || doc.id;
                branchMap[doc.id] = bName;
                options += `<option value="${doc.id}">${bName}</option>`;
            });
            accBranchSelect.innerHTML = options;
        });

        // Load Accounts (Staff & Delivery & Admin for Super Admin)
        let rolesToFetch = ['employee', 'delivery'];
        if (currentRole === 'super-admin') {
            rolesToFetch.push('admin');
        }
        db.collection('users').where('role', 'in', rolesToFetch).onSnapshot(snap => {
            if (snap.empty) {
                accountListData.innerHTML = "<tr><td colspan='4' style='text-align:center; padding: 40px;' class='text-muted'>No accounts found.</td></tr>";
                return;
            }
            let html = '';
            snap.forEach(doc => {
                const a = doc.data();
                const id = doc.id;
                                 
                let roleClass = 'badge-staff';
                let roleLabel = 'Staff';
                if (a.role === 'super-admin') {
                    roleClass = 'badge-super-admin';
                    roleLabel = 'Super Admin';
                } else if (a.role === 'admin') {
                    roleClass = 'badge-admin';
                    roleLabel = 'Admin';
                } else if (a.role === 'delivery') {
                    roleClass = 'badge-delivery';
                    roleLabel = 'Delivery';
                }                                 
                const fullName = `${a.firstName || ''} ${a.middleName || ''} ${a.lastName || a.name || ''}`.trim();
                const branchName = branchMap[a.branchId] || a.branchId || 'Main Branch';
                html += `
                <tr>
                    <td style="font-weight: 600;">${fullName || 'N/A'}</td>
                    <td><span class="badge ${roleClass}">${roleLabel}</span></td>
                    <td><span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">${branchName}</span></td>
                    <td style="text-align: right;">
                        ${(currentRole === 'super-admin' || (currentRole === 'admin' && a.role !== 'admin' && a.role !== 'super-admin')) ? `
                        <button onclick="deleteAccount('${id}')" class="delete-btn">
                            <i class="fa-solid fa-trash"></i>
                        </button>` : ''}
                    </td>
                </tr>`;
            });
            accountListData.innerHTML = html;
        });

        // --- SECURED REGISTRATION INTERCEPTOR SUBMIT FLOW ---
        document.getElementById('addAccountForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('addAccountBtn');
            const inputEmail = document.getElementById('accUser').value.trim().toLowerCase();
            
            btn.disabled = true;
            btn.innerText = 'Analyzing credential registers...';
            
            try {
                // 1. HARD SECURITY CHECK: Search customers collection first prior to auth allocation
                const customerLookup = await db.collection('customers').where('email', '==', inputEmail).get();
                if (!customerLookup.empty) {
                    throw new Error("Security Registry Rejection: This email address is already registered as a standard customer profile. Escalation to corporate roles via this module is strictly prohibited.");
                }

                // 2. Search existing internal management users collection
                const userLookup = await db.collection('users').where('email', '==', inputEmail).get();
                if (!userLookup.empty) {
                    throw new Error("Registry Collision: An internal employee profile is already mapped to this email domain target.");
                }

                // 3. If email is completely clean across all directories, commit enrollment safely
                await db.collection('users').add({
                    firstName: document.getElementById('accFirstName').value.trim(),
                    middleName: document.getElementById('accMiddleName').value.trim(),
                    lastName: document.getElementById('accLastName').value.trim(),
                    birthday: document.getElementById('accBirthday').value,
                    sex: document.getElementById('accSex').value,
                    username: inputEmail,
                    email: inputEmail,
                    role: document.getElementById('accRole').value,
                    branchId: document.getElementById('accBranch').value,
                    password: document.getElementById('accPass').value, // Explicit structural layout sync
                    created_at: firebase.firestore.FieldValue.serverTimestamp()
                });

                showSuccess('Corporate employee account deployed successfully!');
                document.getElementById('addAccountForm').reset();
            } catch (err) {
                showError(err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = 'Deploy Account';
            }
        };
    });

    async function deleteAccount(id) {
        if (confirm('Are you sure you want to remove this account?')) {
            try {
                await db.collection('users').doc(id).delete();
                showSuccess('Account removed successfully!');
            } catch (err) {
                showError('Error: ' + err.message);
            }
        }
    }

    function showSuccess(msg) {
        const s = document.getElementById('success-alert');
        if (s) {
            s.innerText = msg;
            s.style.display = 'block';
            setTimeout(() => s.style.display = 'none', 3000);
        }
    }

    function showError(msg) {
        const e = document.getElementById('error-alert');
        if (e) {
            e.innerText = msg;
            e.style.display = 'block';
            setTimeout(() => e.style.display = 'none', 5000);
        }
    }
</script>

<?php include 'templates/footer.php'; ?>