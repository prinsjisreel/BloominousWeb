<?php   
/**
 * BLOOMINOUS - Account Management (Staff & Delivery)
 *
 * Account creation is SUPER-ADMIN ONLY (Option B), matching the
 * mobile app's manage_employees_page.dart. Creation uses the
 * invite-token pattern required by firestore.rules:
 *   1. Secondary app creates the Firebase Auth account.
 *   2. The super-admin's OWN session writes invites/{newUid}.
 *   3. The secondary app (signed in AS the new account) writes
 *      employees/{newUid} and users/{newUid}. The rules' 
 *      hasValidInvite() check approves these because step 2 exists.
 *   4. The invite is deleted (best-effort).
 */
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Security Check - must be logged in as staff
if (!isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

$userRole = $_SESSION['role'] ?? 'admin';
$isSuperAdmin = ($userRole === 'super-admin');
include 'templates/header.php';  
?>

<style>
    .manage-content { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
    .page-header { margin-bottom: 3.5rem; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap; }
         
    .manage-content label { font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 1.5px; }
    .manage-content input, .manage-content select { width: 100%; padding: 15px 18px; border: 1px solid var(--border-color); border-radius: 15px; outline: none; font-size: 0.9rem; background: var(--surface-alt); color: var(--text-main); transition: 0.3s; font-weight: 600; }
    .manage-content input:focus, .manage-content select:focus { border-color: var(--primary); background: var(--surface); box-shadow: 0 0 15px rgba(233, 30, 99, 0.05); }
         
    .manage-content table { width: 100%; border-collapse: collapse; }
    .manage-content th { text-align: left; padding: 25px 20px; color: var(--text-light); font-size: 0.75rem; text-transform: uppercase; font-weight: 800; border-bottom: 1px solid var(--border-color); letter-spacing: 1px; background: var(--surface-alt); }
    .manage-content td { padding: 20px; border-bottom: 1px solid #f8f9fa; font-size: 0.9rem; color: var(--text-main); font-weight: 500; }
         
    .badge { padding: 6px 16px; border-radius: 50px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
    .badge-super-admin { background: #000; color: #fff; }
    .badge-admin { background: rgba(233, 30, 99, 0.1); color: var(--primary); }
    .badge-staff { background: rgba(123, 121, 242, 0.1); color: var(--secondary); }
    .badge-delivery { background: rgba(255, 177, 66, 0.1); color: #f39c12; }
         
    .alert { padding: 18px 24px; border-radius: 20px; margin-bottom: 30px; font-weight: 800; font-size: 0.8rem; display: none; text-align: center; text-transform: uppercase; letter-spacing: 1px; }
    .alert-success { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
    .alert-error { background: #fff5f8; color: var(--primary); }
    .delete-btn { width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; background: #fff5f8; color: var(--primary); transition: 0.3s; border: none; cursor: pointer; }
    .delete-btn:hover { background: var(--primary); color: white; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(233, 30, 99, 0.15); }
    .form-section-title { font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; font-weight: 900; color: var(--text-main); border-bottom: 3px solid var(--primary); display: inline-block; padding-bottom: 8px; margin-bottom: 30px; }
    .migrate-btn { display: flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 12px; border: 2px solid var(--secondary); color: var(--secondary); background: none; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; cursor: pointer; transition: 0.3s; }
    .migrate-btn:hover { background: var(--secondary); color: white; }
    .migrate-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .locked-note { font-size: 0.85rem; line-height: 1.6; color: var(--text-light); font-weight: 500; }

    /* ============ DARK MODE ============
       Only active when header.php sets data-theme="dark" on <html>. */
    html[data-theme="dark"] .manage-content td { border-bottom-color: var(--border-color); }
    html[data-theme="dark"] .manage-content :is(input, select) { color-scheme: dark; }
    /* A solid black pill vanishes on a dark page -- invert it instead */
    html[data-theme="dark"] .badge-super-admin { background: var(--text-main); color: var(--background); }
    html[data-theme="dark"] .badge-admin { background: rgba(233, 30, 99, 0.18); }
    html[data-theme="dark"] .alert-success { background: rgba(46, 204, 113, 0.15); color: #6ee7b7; }
    html[data-theme="dark"] .alert-error { background: rgba(233, 30, 99, 0.15); color: #f9a8d4; }
    html[data-theme="dark"] .delete-btn { background: rgba(233, 30, 99, 0.15); }
    html[data-theme="dark"] .delete-btn:hover { background: var(--primary); box-shadow: none; }

    /* Tailwind class remap, scoped to this page */
    html[data-theme="dark"] .manage-content :is(.text-gray-800, .text-gray-700) { color: var(--text-main); }
    html[data-theme="dark"] .manage-content :is(.text-gray-500, .text-gray-400, .text-gray-300, .text-muted) { color: var(--text-light); }
    html[data-theme="dark"] .manage-content .bg-white { background-color: var(--surface); }
    html[data-theme="dark"] .manage-content :is(.border-gray-50, .border-gray-100) { border-color: var(--border-color); }
</style>

<main class="manage-content">
    <div class="page-header">
        <div>
            <h1 class="brand-font text-5xl font-black text-gray-800">Manage Accounts</h1>
            <p class="text-gray-400 font-medium text-sm mt-1">Manage employee accounts, roles, and branch assignments.</p>
        </div>
        <?php if ($isSuperAdmin): ?>
        <!-- One-time legacy-data migration trigger, mirroring the
             mobile app's "Migrate Legacy Data" button exactly. Super-
             admin only, since the /employees create rule only permits
             this cross-account write for super-admin sessions. -->
        <button id="migrateBtn" class="migrate-btn" onclick="runEmployeeMigration()">
            <i class="fa-solid fa-arrows-rotate"></i>
            <span>Migrate Legacy Data</span>
        </button>
        <?php endif; ?>
    </div>

    <div id="success-alert" class="alert alert-success"></div>
    <div id="error-alert" class="alert alert-error"></div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- ADD ACCOUNT FORM (super-admin only, Option B) -->
        <div class="lg:col-span-1">
            <?php if ($isSuperAdmin): ?>
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
                                <option value="admin">Shop Admin (Owner)</option>
                                <option value="employee">Staff / Intern</option>
                                <option value="delivery">Logistics Fleet</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Access Key</label>
                            <input type="password" id="accPass" required placeholder="••••••••" minlength="6">
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
            <?php else: ?>
            <!-- Plain admins can view the roster but cannot create accounts. -->
            <div class="card bg-white">
                <h4 class="form-section-title"><i class="fa-solid fa-lock mr-2" style="color: var(--primary);"></i> Registration Locked</h4>
                <p class="locked-note">Only the Super Admin can create staff, admin, or delivery accounts. Please contact the Super Admin if a new account is needed.</p>
            </div>
            <?php endif; ?>
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
                                <th>Employee</th>
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
    // json_encode safely turns the PHP string into a valid JS string literal.
    const currentRole = <?php echo json_encode($userRole); ?>;

    // How long an invite stays valid. Must match the mobile app (10 minutes).
    const INVITE_TTL_MS = 10 * 60 * 1000;

    document.addEventListener('DOMContentLoaded', () => {
        const accountListData = document.getElementById('accountListData');
        const accBranchSelect = document.getElementById('accBranch');
        let branchMap = {};

        db.collection('branches').onSnapshot(snap => {
            let options = '<option value="">Select Branch</option>';
            branchMap = {};
            snap.forEach(doc => {
                const b = doc.data();
                const bName = b.name || doc.id;
                branchMap[doc.id] = bName;
                options += `<option value="${doc.id}">${bName}</option>`;
            });
            // The branch <select> only exists for super-admins.
            if (accBranchSelect) {
                accBranchSelect.innerHTML = options;
            }
        });

        let rolesToFetch = ['employee', 'delivery'];
        if (currentRole === 'super-admin') {
            rolesToFetch.push('admin');
        }
        db.collection('employees').where('role', 'in', rolesToFetch).onSnapshot(snap => {
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

        /**
         * Creates a staff account using the invite-token pattern.
         * `db` = the DEFAULT app (the super-admin's own session).
         * `secondaryApp` = a throwaway app that signs in AS the new account.
         */
        async function createEmployeeAccount(email, password, profileData) {
            const secondaryApp = firebase.initializeApp(firebase.app().options, 'Secondary_' + Date.now());
            let newUser = null;             // set once the Auth account exists
            let inviteRef = null;           // set once the invite is written
            let employeeDocWritten = false; // for rollback cleanup
            let step = 'creating the login account (Firebase Auth)';

            try {
                // STEP 1: create the Auth account (secondary app signs in as it).
                const cred = await secondaryApp.auth().createUserWithEmailAndPassword(email, password);
                newUser = cred.user;
                const uid = newUser.uid;

                // STEP 2: the super-admin's OWN session writes the permission slip.
                // Field names are the shared contract with mobile's _addEmployee().
                step = 'writing the invite (your super-admin session)';
                inviteRef = db.collection('invites').doc(uid);
                await inviteRef.set({
                    role: profileData.role,
                    email: email,
                    createdBy: firebase.auth().currentUser.uid,
                    expiresAt: firebase.firestore.Timestamp.fromDate(new Date(Date.now() + INVITE_TTL_MS))
                });

                // STEP 3a: the new account writes its own full profile.
                step = 'writing the employee profile (new account session)';
                await secondaryApp.firestore().collection('employees').doc(uid).set({
                    ...profileData,
                    uid: uid,
                    created_at: firebase.firestore.FieldValue.serverTimestamp()
                });
                employeeDocWritten = true;

                // STEP 3b: the new account writes its slim role pointer.
                step = 'writing the role pointer in users (new account session)';
                await secondaryApp.firestore().collection('users').doc(uid).set({
                    uid: uid,
                    email: email,
                    role: profileData.role,
                    created_at: firebase.firestore.FieldValue.serverTimestamp()
                });

                return uid;
            } catch (err) {
                // ROLLBACK: never leave a half-made account behind.
                if (employeeDocWritten && newUser) {
                    try {
                        await db.collection('employees').doc(newUser.uid).delete();
                    } catch (cleanupErr) {
                        console.warn('Rollback: could not delete employees doc:', cleanupErr);
                    }
                }
                if (newUser) {
                    try {
                        // The secondary app is still signed in as this user, so it can delete itself.
                        await newUser.delete();
                    } catch (cleanupErr) {
                        console.warn('Rollback: could not delete Auth account:', cleanupErr);
                    }
                }
                const wrapped = new Error(`Failed while ${step}: ${err.message}`);
                wrapped.code = err.code;
                throw wrapped;
            } finally {
                // STEP 4: consume the invite (best-effort) so it can't be reused.
                if (inviteRef) {
                    try {
                        await inviteRef.delete();
                    } catch (inviteErr) {
                        console.warn('Invite cleanup failed (it will expire on its own):', inviteErr);
                    }
                }
                try { await secondaryApp.auth().signOut(); } catch (e) { /* already signed out */ }
                await secondaryApp.delete();
            }
        }

        const addForm = document.getElementById('addAccountForm');
        // The form only exists for super-admins, so only wire it up if it's there.
        if (addForm) {
            addForm.onsubmit = async (e) => {
                e.preventDefault();
                const btn = document.getElementById('addAccountBtn');
                const inputEmail = document.getElementById('accUser').value.trim().toLowerCase();
                const inputPassword = document.getElementById('accPass').value;
                const selectedRole = document.getElementById('accRole').value;
                const selectedBranch = document.getElementById('accBranch').value;

                btn.disabled = true;
                btn.innerText = 'Analyzing credential registers...';

                try {
                    // Guard 1: PHP says you're logged in, but Firebase JS must agree too.
                    if (!firebase.auth().currentUser) {
                        throw new Error('Your Firebase session is not ready yet. Please refresh the page and try again.');
                    }
                    // Guard 2: UI mirror of the rules (the rules are the real lock).
                    if (currentRole !== 'super-admin') {
                        throw new Error('Only the Super Admin can create accounts.');
                    }

                    const customerLookup = await db.collection('customers').where('email', '==', inputEmail).get();
                    if (!customerLookup.empty) {
                        throw new Error("Security Registry Rejection: This email address is already registered as a standard customer profile. Escalation to corporate roles via this module is strictly prohibited.");
                    }

                    const userLookup = await db.collection('users').where('email', '==', inputEmail).get();
                    if (!userLookup.empty) {
                        throw new Error("Registry Collision: An internal employee profile is already mapped to this email domain target.");
                    }

                    btn.innerText = 'Deploying account...';

                    const newUid = await createEmployeeAccount(inputEmail, inputPassword, {
                        firstName: document.getElementById('accFirstName').value.trim(),
                        middleName: document.getElementById('accMiddleName').value.trim(),
                        lastName: document.getElementById('accLastName').value.trim(),
                        birthday: document.getElementById('accBirthday').value,
                        sex: document.getElementById('accSex').value,
                        username: inputEmail,
                        email: inputEmail,
                        role: selectedRole,
                        branchId: selectedBranch
                    });

                    try {
                        await db.collection('admin_actions').add({
                            actorUid: firebase.auth().currentUser.uid,
                            actorEmail: window.currentUserEmail || '',
                            actorRole: currentRole,
                            action: 'create_employee_account',
                            targetUid: newUid,
                            targetEmail: inputEmail,
                            details: `Created new ${selectedRole} account, assigned to branch ${selectedBranch}.`,
                            timestamp: firebase.firestore.FieldValue.serverTimestamp()
                        });
                    } catch (auditError) {
                        console.warn('Audit log write failed (account still created):', auditError);
                    }

                    showSuccess('Corporate employee account deployed successfully!');
                    addForm.reset();
                } catch (err) {
                    console.error('Account creation error:', err);
                    showError(err.message);
                } finally {
                    btn.disabled = false;
                    btn.innerText = 'Deploy Account';
                }
            };
        }
    });

    async function deleteAccount(id) {
        if (confirm('Are you sure you want to remove this account?')) {
            try {
                await Promise.all([
                    db.collection('employees').doc(id).delete(),
                    db.collection('users').doc(id).delete()
                ]);
                showSuccess('Account removed successfully!');
            } catch (err) {
                showError('Error: ' + err.message);
            }
        }
    }

    // One-time backfill, mirrors InventoryData's
    // migrateLegacyEmployeesToEmployeesCollection() in the mobile app
    // exactly -- same read (non-customer 'users' docs), same skip
    // condition (an 'employees' doc already exists), same fields
    // copied across. Safe to run from either platform, or both.
    async function runEmployeeMigration() {
        if (!confirm('Run one-time migration to copy legacy employee profiles into the new Employees collection? This is safe to run more than once.')) return;
        const btn = document.getElementById('migrateBtn');
        if (btn) { btn.disabled = true; btn.querySelector('span').innerText = 'Migrating...'; }
        try {
            const snap = await db.collection('users').where('role', 'in', ['admin', 'super-admin', 'employee', 'delivery']).get();
            let migrated = 0;
            let skippedAlready = 0;
            for (const doc of snap.docs) {
                const uid = doc.id;
                const data = doc.data();
                const existing = await db.collection('employees').doc(uid).get();
                if (existing.exists) {
                    skippedAlready++;
                    continue;
                }
                await db.collection('employees').doc(uid).set({
                    uid: uid,
                    firstName: data.firstName || null,
                    middleName: data.middleName || null,
                    lastName: data.lastName || null,
                    birthday: data.birthday || null,
                    sex: data.sex || null,
                    email: data.email || null,
                    employeeId: data.employeeId || null,
                    role: data.role,
                    branchId: data.branchId || null,
                    created_at: data.createdAt || firebase.firestore.FieldValue.serverTimestamp(),
                    migratedFromUsersCollection: true
                });
                migrated++;
            }
            showSuccess(`Migration complete: ${migrated} account(s) migrated, ${skippedAlready} already up to date.`);
        } catch (err) {
            showError('Migration failed: ' + err.message);
        } finally {
            if (btn) { btn.disabled = false; btn.querySelector('span').innerText = 'Migrate Legacy Data'; }
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
            // Errors stay longer (8s) since they carry step details worth reading.
            setTimeout(() => e.style.display = 'none', 8000);
        }
    }
</script>

<?php include 'templates/footer.php'; ?>