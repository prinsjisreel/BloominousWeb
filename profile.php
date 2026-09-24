<?php
/**
 * BLOOMINOUS - Staff/Admin Profile
 * Self-service profile page for any signed-in staff, admin, or
 * super-admin account. Mirrors lib/profile_page.dart's layout on
 * mobile: identity card with role badge, Edit Profile, Change
 * Password, and (new on both platforms) a verified Mobile Number.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: index.php");
    exit();
}

include 'templates/header.php';
?>
<style>
    .profile-card { background: white; border: 1px solid #f0f0f0; border-radius: 30px; padding: 2.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.02); }
    .role-badge {
        display: inline-flex; padding: 6px 16px; border-radius: 20px;
        background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3);
        color: #F59E0B; font-size: 0.65rem; font-weight: 900; letter-spacing: 1px;
    }
    .action-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: 1.1rem 1.4rem; border: 1px solid #f0f0f0; border-radius: 18px;
        margin-bottom: 12px; background: #fff;
    }
    .action-row-left { display: flex; align-items: center; gap: 14px; }
    .action-icon-circle {
        width: 40px; height: 40px; border-radius: 12px; background: #fafafa;
        display: flex; align-items: center; justify-content: center; color: #F59E0B; font-size: 1rem;
    }
    .action-title { font-weight: 800; font-size: 0.85rem; color: #333; }
    .action-subtitle { font-size: 0.72rem; color: #aaa; margin-top: 2px; }
    .btn-outline-gold {
        background: none; border: 1.5px solid #F59E0B; color: #F59E0B;
        padding: 8px 18px; border-radius: 12px; font-weight: 800; font-size: 0.72rem;
        text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer;
    }
    .btn-outline-gold:hover { background: rgba(245, 158, 11, 0.08); }
    .field-group { margin-bottom: 16px; text-align: left; }
    .field-group label { display: block; font-size: 0.65rem; font-weight: 800; color: #999; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .field-group input, .field-group select {
        width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid #f0f0f0;
        background: #fafafa; font-weight: 600; font-size: 0.85rem; outline: none; box-sizing: border-box;
    }
    .field-group input:focus, .field-group select:focus { border-color: #F59E0B; background: #fff; }
    .otp-box-row { display: flex; gap: 8px; justify-content: center; margin: 16px 0; }
    .otp-box { width: 42px; height: 50px; border: 2px solid #f0f0f0; border-radius: 12px; text-align: center; font-weight: 900; font-size: 1.1rem; outline: none; }
    .otp-box:focus { border-color: #F59E0B; }
    .save-btn { width: 100%; padding: 13px; background: #F59E0B; color: white; border: none; border-radius: 12px; font-weight: 800; cursor: pointer; margin-top: 6px; }
    .save-btn:hover { background: #d97706; }
    .cancel-btn { width: 100%; padding: 13px; background: #f5f5f5; color: #888; border: none; border-radius: 12px; font-weight: 800; cursor: pointer; margin-top: 8px; }
    .status-verified { color: #16a34a; font-weight: 800; font-size: 0.7rem; display: flex; align-items: center; gap: 6px; }
    .status-unset { color: #bbb; font-weight: 700; font-size: 0.75rem; }
    #phoneAlert { display: none; background: #fef2f2; color: #DC2626; padding: 10px 14px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; margin-top: 10px; line-height: 1.5; }
    .test-mode-badge {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(123, 121, 242, 0.1); color: #7B79F2;
        font-size: 0.68rem; font-weight: 800; padding: 6px 12px;
        border-radius: 10px; margin-bottom: 12px;
    }
</style>

<main style="padding: 1.5rem; max-width: 720px; margin: 0 auto;">
    <div class="mb-10">
        <h1 class="brand-font text-5xl font-black text-gray-800">My Profile</h1>
        <p class="text-gray-400 text-sm font-medium mt-1">Your account details, security, and contact information.</p>
    </div>

    <div id="profileRoot">
        <div class="text-center p-12 text-gray-300 italic">Loading profile...</div>
    </div>
</main>

<!-- Invisible reCAPTCHA container required by Firebase's linkWithPhoneNumber -->
<div id="recaptcha-container"></div>

<script>
    // FIXED: templates/header.php already ran firebase.initializeApp()
    // and exposed window.db / window.auth globally — every other admin
    // page (fraud_analytics.php, sales_anomalies.php) reads those same
    // globals directly and never re-initializes. This file previously
    // called firebase.initializeApp() a SECOND time here, which the
    // Firebase SDK throws on immediately ("Firebase App named '[DEFAULT]'
    // already exists") — that error killed the rest of this script before
    // auth.onAuthStateChanged() ever got registered, which is why the
    // page was stuck on "Loading profile..." forever with no visible
    // error unless you opened DevTools. No local `db`/`auth` declared
    // here at all now — `db` and `auth` below are the same bare globals
    // header.php's own inline scripts already use.
    const root = document.getElementById('profileRoot');

    let currentUserData = null;
    let currentUid = null;
    let confirmationResult = null;
    let recaptchaVerifier = null;

    let testPhoneNumbers = [];
    db.collection('config').doc('testPhoneNumbers').get().then(doc => {
        if (doc.exists && Array.isArray(doc.data().numbers)) {
            testPhoneNumbers = doc.data().numbers.map(n => n.replace(/\s+/g, ''));
        }
    }).catch(() => { testPhoneNumbers = []; });

    const roleLabels = {
        'super-admin': 'SUPER ADMINISTRATOR',
        'admin': 'ADMINISTRATOR',
        'staff': 'STAFF / EMPLOYEE',
        'employee': 'STAFF / EMPLOYEE'
    };

    function normalizePhone(raw) {
        let phone = (raw || '').replace(/\D/g, '');
        if (phone.startsWith('0')) phone = '63' + phone.substring(1);
        else if (!phone.startsWith('63')) phone = '63' + phone;
        return '+' + phone;
    }

    function maskPhone(phone) {
        if (!phone || phone.length < 4) return phone || '';
        return phone.slice(0, -4).replace(/\d/g, '•') + phone.slice(-4);
    }

    auth.onAuthStateChanged(async (user) => {
        if (!user) {
            window.location.href = 'index.php';
            return;
        }
        currentUid = user.uid;
        const doc = await db.collection('users').doc(user.uid).get();
        currentUserData = doc.exists ? doc.data() : null;
        renderProfile(user, currentUserData);
    });

    function renderProfile(user, data) {
        const role = data?.role || 'staff';
        const roleLabel = roleLabels[role] || 'STAFF / EMPLOYEE';
        const fullName = [data?.firstName, data?.middleName, data?.lastName].filter(Boolean).join(' ')
            || data?.username || user.email.split('@')[0];
        const phoneNumber = data?.phoneNumber || null;

        root.innerHTML = `
            ${data === null ? `
            <div style="background:#fef2f2; border:1px solid rgba(220,38,38,0.15); border-radius:16px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-triangle-exclamation" style="color:#DC2626;"></i>
                <span style="font-size:0.75rem; color:#DC2626; font-weight:700;">No Firestore profile found for ${user.email}</span>
            </div>` : ''}

            <div class="profile-card mb-6" style="text-align:center;">
                <h2 class="brand-font" style="font-size:1.7rem; font-weight:800; color:#222;">${fullName}</h2>
                <p style="font-size:0.8rem; color:#999; margin-top:4px;">${user.email}</p>
                <div style="margin-top:12px;"><span class="role-badge">${roleLabel}</span></div>
            </div>

            <div class="profile-card mb-6">
                <div class="action-row" style="margin-bottom:0; border:none; padding:0;">
                    <div class="action-row-left">
                        <div class="action-icon-circle"><i class="fa-solid fa-phone"></i></div>
                        <div>
                            <div class="action-title">Mobile Number</div>
                            <div class="action-subtitle">
                                ${phoneNumber
                                    ? `<span class="status-verified"><i class="fa-solid fa-circle-check"></i> ${maskPhone(phoneNumber)} — Verified</span>`
                                    : '<span class="status-unset">Not set</span>'}
                            </div>
                        </div>
                    </div>
                    <button class="btn-outline-gold" id="phoneActionBtn">${phoneNumber ? 'Change' : 'Add Number'}</button>
                </div>
                <div id="phoneFormArea" style="margin-top:18px; display:none;"></div>
                <div id="phoneAlert"></div>
            </div>

            <div class="profile-card mb-6">
                <div class="action-row" style="border:none; padding:0; margin-bottom:16px;">
                    <div class="action-row-left">
                        <div class="action-icon-circle"><i class="fa-solid fa-id-card"></i></div>
                        <div class="action-title">Edit Profile</div>
                    </div>
                </div>
                <div class="field-group"><label>First Name</label><input type="text" id="editFirstName" value="${data?.firstName || ''}"></div>
                <div class="field-group"><label>Middle Name</label><input type="text" id="editMiddleName" value="${data?.middleName || ''}"></div>
                <div class="field-group"><label>Last Name</label><input type="text" id="editLastName" value="${data?.lastName || ''}"></div>
                <div class="field-group"><label>Birthday</label><input type="date" id="editBirthday" value="${data?.birthday || ''}"></div>
                <div class="field-group">
                    <label>Sex</label>
                    <select id="editSex">
                        <option value="Male" ${data?.sex !== 'Female' ? 'selected' : ''}>Male</option>
                        <option value="Female" ${data?.sex === 'Female' ? 'selected' : ''}>Female</option>
                    </select>
                </div>
                <button class="save-btn" id="saveProfileBtn">Save Changes</button>
            </div>

            <div class="profile-card">
                <div class="action-row" style="border:none; padding:0; margin-bottom:16px;">
                    <div class="action-row-left">
                        <div class="action-icon-circle"><i class="fa-solid fa-lock"></i></div>
                        <div class="action-title">Change Password</div>
                    </div>
                </div>
                <div class="field-group"><label>New Password</label><input type="password" id="newPassword" placeholder="At least 6 characters"></div>
                <button class="save-btn" id="changePasswordBtn">Update Password</button>
            </div>
        `;

        wireProfileEvents(user);
    }

    function wireProfileEvents(user) {
        document.getElementById('phoneActionBtn').onclick = () => showPhoneStepOne();

        document.getElementById('saveProfileBtn').onclick = async () => {
            const btn = document.getElementById('saveProfileBtn');
            btn.disabled = true;
            btn.innerText = 'Saving...';
            try {
                await db.collection('users').doc(currentUid).set({
                    firstName: document.getElementById('editFirstName').value.trim(),
                    middleName: document.getElementById('editMiddleName').value.trim(),
                    lastName: document.getElementById('editLastName').value.trim(),
                    birthday: document.getElementById('editBirthday').value || null,
                    sex: document.getElementById('editSex').value
                }, { merge: true });
                alert('Profile updated!');
                const refreshed = await db.collection('users').doc(currentUid).get();
                currentUserData = refreshed.data();
                renderProfile(user, currentUserData);
            } catch (e) {
                alert('Error saving profile: ' + e.message);
                btn.disabled = false;
                btn.innerText = 'Save Changes';
            }
        };

        document.getElementById('changePasswordBtn').onclick = async () => {
            const pw = document.getElementById('newPassword').value;
            if (pw.length < 6) {
                alert('Password must be at least 6 characters.');
                return;
            }
            const btn = document.getElementById('changePasswordBtn');
            btn.disabled = true;
            btn.innerText = 'Updating...';
            try {
                await user.updatePassword(pw);
                alert('Password updated successfully!');
                document.getElementById('newPassword').value = '';
            } catch (e) {
                alert('Error: ' + e.message + (e.code === 'auth/requires-recent-login' ? ' Please log out and back in, then try again.' : ''));
            } finally {
                btn.disabled = false;
                btn.innerText = 'Update Password';
            }
        };
    }

    function showPhoneStepOne() {
        const area = document.getElementById('phoneFormArea');
        area.style.display = 'block';
        document.getElementById('phoneAlert').style.display = 'none';
        area.innerHTML = `
            <div class="field-group">
                <label>Mobile Number</label>
                <input type="tel" id="newPhoneInput" placeholder="09XX XXX XXXX">
            </div>
            <button class="save-btn" id="sendPhoneCodeBtn">Send Verification Code</button>
            <button class="cancel-btn" id="cancelPhoneBtn">Cancel</button>
        `;
        document.getElementById('cancelPhoneBtn').onclick = () => { area.style.display = 'none'; area.innerHTML = ''; };
        document.getElementById('sendPhoneCodeBtn').onclick = sendPhoneCode;
    }

    async function sendPhoneCode() {
        const rawPhone = document.getElementById('newPhoneInput').value.trim();
        if (!rawPhone) {
            showPhoneAlert('Please enter a mobile number.');
            return;
        }
        const phone = normalizePhone(rawPhone);
        const isTestNumber = testPhoneNumbers.includes(phone);
        const btn = document.getElementById('sendPhoneCodeBtn');
        btn.disabled = true;
        btn.innerText = 'Sending...';

        try {
            if (!recaptchaVerifier) {
                recaptchaVerifier = new firebase.auth.RecaptchaVerifier('recaptcha-container', { size: 'invisible' });
            }
            confirmationResult = await auth.currentUser.linkWithPhoneNumber(phone, recaptchaVerifier);
            showPhoneStepTwo(phone, isTestNumber);
        } catch (e) {
            if (e.code === 'auth/billing-not-enabled') {
                if (isTestNumber) {
                    showPhoneAlert('This number is registered as a test number, but the format sent (' + phone + ') doesn\'t match what\'s configured in Firebase Console. Check the exact format there (including country code) and try again.');
                } else {
                    showPhoneAlert(
                        'Automated SMS isn\'t active yet for this project (requires enabling the Blaze plan in Firebase). ' +
                        (testPhoneNumbers.length > 0
                            ? 'For now, use one of the registered test numbers instead: ' + testPhoneNumbers.join(', ')
                            : 'No test numbers are registered yet — add one under Firebase Console → Authentication → Sign-in method → Phone → "Phone numbers for testing" to keep testing this flow.')
                    );
                }
            } else {
                showPhoneAlert(e.message || 'Could not send verification code.');
            }
            btn.disabled = false;
            btn.innerText = 'Send Verification Code';
        }
    }

    function showPhoneStepTwo(phone, isTestNumber) {
        const area = document.getElementById('phoneFormArea');
        area.innerHTML = `
            ${isTestNumber ? `<div class="test-mode-badge"><i class="fa-solid fa-flask"></i> Test Mode Number Detected — no real SMS sent, use the fixed test code from Firebase Console.</div>` : ''}
            <p style="font-size:0.75rem; color:#888; margin-bottom:10px;">Enter the 6-digit code sent to ${phone}</p>
            <div class="otp-box-row">
                <input class="otp-box" maxlength="1" inputmode="numeric">
                <input class="otp-box" maxlength="1" inputmode="numeric">
                <input class="otp-box" maxlength="1" inputmode="numeric">
                <input class="otp-box" maxlength="1" inputmode="numeric">
                <input class="otp-box" maxlength="1" inputmode="numeric">
                <input class="otp-box" maxlength="1" inputmode="numeric">
            </div>
            <button class="save-btn" id="verifyPhoneCodeBtn">Verify Number</button>
            <button class="cancel-btn" id="cancelPhoneBtn2">Cancel</button>
        `;
        const boxes = area.querySelectorAll('.otp-box');
        boxes.forEach((box, i) => {
            box.addEventListener('input', () => { if (box.value.length === 1 && i < 5) boxes[i + 1].focus(); });
            box.addEventListener('keydown', (e) => { if (e.key === 'Backspace' && !box.value && i > 0) boxes[i - 1].focus(); });
        });
        document.getElementById('cancelPhoneBtn2').onclick = () => { area.style.display = 'none'; area.innerHTML = ''; };
        document.getElementById('verifyPhoneCodeBtn').onclick = () => confirmPhoneCode(phone, boxes);
    }

    async function confirmPhoneCode(phone, boxes) {
        const code = Array.from(boxes).map(b => b.value).join('');
        if (code.length !== 6) {
            showPhoneAlert('Please enter the full 6-digit code.');
            return;
        }
        const btn = document.getElementById('verifyPhoneCodeBtn');
        btn.disabled = true;
        btn.innerText = 'Verifying...';

        try {
            await confirmationResult.confirm(code);

            await db.collection('users').doc(currentUid).set({
                phoneNumber: phone,
                phoneVerifiedAt: firebase.firestore.FieldValue.serverTimestamp()
            }, { merge: true });

            alert('Mobile number verified and saved!');
            location.reload();
        } catch (e) {
            showPhoneAlert(e.message || 'Verification failed. Please check the code and try again.');
            btn.disabled = false;
            btn.innerText = 'Verify Number';
        }
    }

    function showPhoneAlert(msg) {
        const el = document.getElementById('phoneAlert');
        el.innerText = msg;
        el.style.display = 'block';
    }
</script>

<?php include 'templates/footer.php'; ?>