<?php 
/**
 * BLOOMINOUS - Settings & Configurations (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Check
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php'; 
?>

<style>
    .settings-content { padding: 30px; max-width: 1200px; margin: 0 auto; font-family: 'Poppins', sans-serif; }
    .settings-top-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 25px; margin-bottom: 30px; }
    .card { background: #fff; padding: 25px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid #f0f0f0; }
    .form-group { margin-bottom: 15px; }
    label { font-size: 0.75rem; font-weight: 700; color: #b2bec3; text-transform: uppercase; margin-bottom: 5px; display: block; }
    input, textarea { width: 100%; padding: 10px; border: 1px solid #eee; border-radius: 10px; outline: none; font-size: 0.9rem; background: #fcfcfc; transition: 0.3s; }
    input:focus, textarea:focus { border-color: #7B79F2; background: #fff; }
    .btn-save { background: #7B79F2; color: white; border: none; padding: 12px; border-radius: 10px; font-weight: 700; cursor: pointer; width: 100%; margin-top: 10px; transition: 0.3s; }
    .btn-save:hover { background: #5a58d1; transform: translateY(-2px); }
    
    .table-card { background: #fff; border-radius: 20px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th { text-align: left; padding: 15px; color: #b2bec3; font-size: 0.75rem; text-transform: uppercase; border-bottom: 2px solid #f8f9fa; }
    td { padding: 15px; border-bottom: 1px solid #f8f9fa; font-size: 0.9rem; color: #2d3436; }
    .badge-admin { background: #eef2ff; color: #6b5cd7; padding: 4px 12px; border-radius: 50px; font-size: 0.7rem; font-weight: 800; }
    .alert { padding: 15px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; font-size: 0.9rem; display: none; }
    
    .delete-btn { color: #ff7782; transition: 0.2s; background: none; border: none; cursor: pointer; font-size: 1.1rem; }
    .delete-btn:hover { color: #e84118; transform: scale(1.1); }
</style>

<main class="settings-content">
    <div style="margin-bottom: 30px;">
        <h1 style="font-weight: 800; font-size: 28px; color: #363949;">Settings & Configurations</h1>
        <p style="color: #7d8da1; font-size: 0.9rem;">Manage your shop identity and admin access.</p>
    </div>

    <div id="success-alert" class="alert" style="background: #e6fff6; color: #41f1b6;"></div>
    <div id="error-alert" class="alert" style="background: #ffe6e6; color: #ff7782;"></div>

    <div class="settings-top-grid">
        <!-- SHOP INFORMATION -->
        <div class="card">
            <h4 style="font-weight: 800; margin-bottom: 20px; color: #363949;"><i class="fa-solid fa-shop mr-2" style="color: #7B79F2;"></i> Shop Information</h4>
            <form id="shopSettingsForm">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Shop Name</label>
                        <input type="text" id="shopName" required>
                    </div>
                    <div class="form-group">
                        <label>Contact No.</label>
                        <input type="text" id="contactNumber" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="emailAddress" required>
                </div>
                <div class="form-group">
                    <label>Physical Address</label>
                    <textarea id="shopAddress" rows="2" required></textarea>
                </div>
                <button type="submit" id="saveShopBtn" class="btn-save">Save Shop Profile</button>
            </form>
        </div>

        <!-- QUICK ADD ADMIN -->
        <div class="card" style="background: #fcfaff; border: 1px dashed #7B79F2;">
            <h4 style="font-weight: 800; margin-bottom: 20px; color: #7B79F2;"><i class="fa-solid fa-user-shield mr-2"></i> Add New Admin</h4>
            <form id="addAdminForm">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="adminUser" required placeholder="Enter username">
                </div>
                <div class="form-group">
                    <label>Temporary Password</label>
                    <input type="password" id="adminPass" required placeholder="••••••••">
                </div>
                <button type="submit" id="addAdminBtn" class="btn-save" style="background: #363949;">Create Admin Account</button>
            </form>
        </div>

        <!-- CHANGE PASSWORD -->
        <div class="card" style="background: #fff5f5; border: 1px dashed #FF5252;">
            <h4 style="font-weight: 800; margin-bottom: 20px; color: #FF5252;"><i class="fa-solid fa-key mr-2"></i> Change Password</h4>
            <form id="changePasswordForm">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" id="newPassword" required placeholder="Minimum 6 characters">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" id="confirmNewPassword" required placeholder="Confirm new password">
                </div>
                <button type="submit" id="changePasswordBtn" class="btn-save" style="background: #FF5252;">Update Password</button>
            </form>
        </div>
    </div>

    <!-- ADMIN USERS TABLE -->
    <div class="table-card">
        <h4 style="font-weight: 800; color: #363949;"><i class="fa-solid fa-users-gear mr-2" style="color: #7B79F2;"></i> Admin Access Management</h4>
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Access Level</th>
                    <th>Account Status</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody id="adminListData">
                <tr><td colspan="4" style="text-align:center; padding: 40px;" class="text-muted">Loading admins...</td></tr>
            </tbody>
        </table>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const adminListData = document.getElementById('adminListData');

        // Load Shop Settings
        db.collection('settings').doc('shop').onSnapshot(doc => {
            if (doc.exists) {
                const s = doc.data();
                document.getElementById('shopName').value = s.shop_name || '';
                document.getElementById('contactNumber').value = s.contact_number || '';
                document.getElementById('emailAddress').value = s.email_address || '';
                document.getElementById('shopAddress').value = s.shop_address || '';
            }
        });

        // Load Admins
        db.collection('users').where('role', '==', 'admin').onSnapshot(snap => {
            if (snap.empty) {
                adminListData.innerHTML = "<tr><td colspan='4' style='text-align:center; padding: 40px;' class='text-muted'>No admins found.</td></tr>";
                return;
            }

            let html = '';
            snap.forEach(doc => {
                const a = doc.data();
                const id = doc.id;
                const isSelf = id === "<?php echo $_SESSION['user_id']; ?>";

                html += `
                <tr>
                    <td style="font-weight: 700; color: #363949;">${a.username}</td>
                    <td><span class="badge-admin">${a.role.toUpperCase()}</span></td>
                    <td><span style="color: #41f1b6; font-size: 0.8rem; font-weight: 700;">● Active</span></td>
                    <td style="text-align: right;">
                        ${!isSelf ? `
                            <button onclick="deleteAdmin('${id}')" class="delete-btn">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                         : 
                            <span style="color: #eee;" title="You cannot delete yourself"><i class="fa-solid fa-trash"></i></span>
                        `}
                    </td>
                </tr>
                `;
            });
            adminListData.innerHTML = html;
        });

        // Update Shop Settings
        document.getElementById('shopSettingsForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('saveShopBtn');
            btn.disabled = true;
            btn.innerText = 'Saving...';

            try {
                await db.collection('settings').doc('shop').set({
                    shop_name: document.getElementById('shopName').value,
                    contact_number: document.getElementById('contactNumber').value,
                    email_address: document.getElementById('emailAddress').value,
                    shop_address: document.getElementById('shopAddress').value
                }, { merge: true });
                showSuccess('Shop information updated successfully!');
            } catch (err) {
                showError('Error: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = 'Save Shop Profile';
            }
        };

        // Add Admin
        document.getElementById('addAdminForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('addAdminBtn');
            btn.disabled = true;
            btn.innerText = 'Creating...';

            const rawUsername = document.getElementById('adminUser').value.trim().toLowerCase();
            const password = document.getElementById('adminPass').value.trim();

            // Construct valid email for Firebase Auth
            const email = rawUsername.includes('@') ? rawUsername : ${rawUsername}@bloom.com;

            let secondaryApp = null;
            let secondaryAuth = null;
            try {
                // Initialize secondary app safely to avoid duplicate app errors or logging out the main admin
                try {
                    secondaryApp = firebase.app('SecondaryApp');
                } catch (appErr) {
                    secondaryApp = firebase.initializeApp(firebaseConfig, 'SecondaryApp');
                }
                secondaryAuth = secondaryApp.auth();
                const userCredential = await secondaryAuth.createUserWithEmailAndPassword(email, password);
                const newUser = userCredential.user;

                await db.collection('users').doc(newUser.uid).set({
                    username: rawUsername,
                    email: email,
                    role: 'admin',
                    created_at: firebase.firestore.FieldValue.serverTimestamp()
                });

                showSuccess('New admin added successfully!');
                document.getElementById('addAdminForm').reset();
            } catch (err) {
                if (err.code === 'auth/email-already-in-use') {
                    try {
                        let existingUid = null;
                        
                        // 1. Try to sign in using secondaryAuth to retrieve the UID
                        try {
                            const tempCredential = await secondaryAuth.signInWithEmailAndPassword(email, password);
                            existingUid = tempCredential.user.uid;
                        } catch (signInErr) {
                            // If sign-in fails, let's query the 'customers' collection by email
                            const customerQuery = await db.collection('customers').where('email', '==', email).limit(1).get();
                            if (!customerQuery.empty) {
                                existingUid = customerQuery.docs[0].id;
                            } else {
                                // Also check existing users collection by username or email
                                const userQuery = await db.collection('users').where('username', '==', rawUsername).limit(1).get();
                                if (!userQuery.empty) {
                                    existingUid = userQuery.docs[0].id;
                                } else {
                                    const userEmailQuery = await db.collection('users').where('email', '==', email).limit(1).get();
                                    if (!userEmailQuery.empty) {
                                        existingUid = userEmailQuery.docs[0].id;
                                    }
                                }
                            }
                        }

                        if (existingUid) {
                            await db.collection('users').doc(existingUid).set({
                                username: rawUsername,
                                email: email,
                                role: 'admin',
                                created_at: firebase.firestore.FieldValue.serverTimestamp()
                            }, { merge: true });
                            
                            showSuccess('Existing admin account updated and registered successfully!');
                            document.getElementById('addAdminForm').reset();
                        } else {
                            showError('Error: This admin account already exists in Firebase Authentication with a different password.');
                        }
                    } catch (updateErr) {
                        showError('Error during update: ' + updateErr.message);
                    }
                } else {
                    showError('Error: ' + err.message);
                }
            } finally {
                if (secondaryApp) {
                    try {
                        await secondaryApp.delete();
                    } catch (secErr) {}
                }
                btn.disabled = false;
                btn.innerText = 'Create Admin Account';
            }
        };

        // Change Password
        document.getElementById('changePasswordForm').onsubmit = async (e) => {
            e.preventDefault();
            const btn = document.getElementById('changePasswordBtn');
            const pass = document.getElementById('newPassword').value;
            const confirmPass = document.getElementById('confirmNewPassword').value;

            if (pass.length < 6) {
                showError('Password must be at least 6 characters.');
                return;
            }

            if (pass !== confirmPass) {
                showError('Passwords do not match.');
                return;
            }

            btn.disabled = true;
            btn.innerText = 'Updating...';

            try {
                const user = window.auth.currentUser;
                if (!user) {
                    throw new Error('No user is currently logged in via Firebase Auth.');
                }
                await user.updatePassword(pass);
                showSuccess('Password updated successfully!');
                document.getElementById('changePasswordForm').reset();
            } catch (err) {
                showError('Error: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerText = 'Update Password';
            }
        };
    });

    async function deleteAdmin(id) {
        if (confirm('Are you sure you want to remove this admin?')) {
            try {
                await db.collection('users').doc(id).delete();
                showSuccess('Admin account removed successfully!');
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
            setTimeout(() => e.style.display = 'none', 3000);
        }
    }
</script>

<?php include 'templates/footer.php'; ?>