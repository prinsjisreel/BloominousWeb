<?php
// These two are computed unconditionally, every request — the forced
// setcookie() call at the bottom needs them regardless of whether a
// session was already active or brand new this request.
$bloomIsHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$bloomSessionLifetimeSeconds = 60 * 60 * 24 * 30; // 30 days — stay logged in until actual logout

// Only configure cookie PARAMS if no session is active yet — calling
// session_set_cookie_params() on an already-active session throws a PHP
// warning.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => $bloomSessionLifetimeSeconds,
        'path' => '/',
        'domain' => '',
        'secure' => $bloomIsHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) $bloomSessionLifetimeSeconds);
}

session_start();

// FIXED: PHP only sends a Set-Cookie header when a session is first
// CREATED — if the browser already holds an older cookie (from before
// session_set_cookie_params() was ever configured, or from any point
// before this fix existed), PHP just keeps reusing that old,
// expiration-less cookie forever and never re-issues it, no matter what
// gets configured afterward. Confirmed via a diagnostic script: on a
// request with an existing session cookie, headers_list() showed no
// Set-Cookie line at all, even with session_set_cookie_params()
// returning true and session_get_cookie_params() confirming the
// 30-day config. This forces a fresh Set-Cookie on EVERY request,
// unconditionally, with a sliding 30-day expiration — an actively used
// account effectively never expires; only a truly abandoned one does.
setcookie(session_name(), session_id(), [
    'expires' => time() + $bloomSessionLifetimeSeconds,
    'path' => '/',
    'domain' => '',
    'secure' => $bloomIsHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// If already logged in, redirect based on role — matches the exact same
// role-based routing the login flow's own JS uses right after signing
// in. FIXED: this previously only checked $_SESSION['admin_id'], which
// is NEVER set for a customer session (set_session.php sets
// $_SESSION['user_id'] for customers instead) — so a logged-in customer
// opening this page directly (a new tab, typing the root URL, a
// bookmark) always saw the raw login form again, even with a perfectly
// valid session. This wasn't a device-recognition or timing issue at
// all; it was a deterministic gap in this one check, identical on both
// localhost and the deployed site since it's pure PHP logic.
if (isset($_SESSION['admin_id']) || isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'customer';
    if (in_array($role, ['admin', 'super-admin', 'staff', 'employee'], true)) {
        header("Location: admin.php");
    } elseif ($role === 'delivery') {
        header("Location: delivery_status.php");
    } else {
        header("Location: templates/shop.php");
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - BLOOM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=Cormorant+Garamond:wght@700;900&display=swap" rel="stylesheet">
    <!-- Firebase SDK -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>
    <script src="assets/script/device_fingerprint.js"></script>
    <style>
        :root {
            --primary: #F59E0B;
            --secondary: #121212;
            --background: #FFFDF7;
            --dark: #121212;
            --text-main: #363949;
        }
        body { font-family: 'Inter', sans-serif; background: var(--background); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; color: var(--text-main); }
        .login-container { background: #ffffff; padding: 4rem; border-radius: 40px; box-shadow: 0 40px 100px rgba(245,158,11,0.06); width: 100%; max-width: 480px; text-align: center; border: 1px solid rgba(245,158,11,0.03); margin: 2rem; position: relative; overflow: hidden; }
                 
        .brand-name { font-family: 'Cormorant Garamond', serif; font-size: 3rem; font-weight: 900; letter-spacing: 6px; color: var(--primary); margin-bottom: 2rem; position: relative; z-index: 2; }
                 
        h2 { font-family: 'Cormorant Garamond', serif; color: var(--dark); font-size: 2.5rem; margin-bottom: 0.5rem; font-weight: 900; line-height: 1.1; }
        p.subtitle { color: #aaa; font-size: 0.9rem; margin-bottom: 3rem; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; }
                 
        .error-msg { background: #fef2f2; color: #DC2626; padding: 1.2rem; border-radius: 20px; font-size: 0.8rem; margin-bottom: 2.5rem; border: 1px solid rgba(220,38,38,0.15); text-align: center; display: none; font-weight: 800; letter-spacing: 0.5px; }
                 
        input { width: 100%; padding: 1.2rem 1.5rem; margin: 0.8rem 0; border: 1px solid #f0f0f0; border-radius: 20px; box-sizing: border-box; outline: none; transition: 0.4s; font-size: 1rem; background: #fafafa; font-weight: 600; color: var(--text-main); }
        input:focus { border-color: var(--primary); background: #fff; box-shadow: 0 10px 25px rgba(245, 158, 11, 0.05); }
        input:disabled { background: #f3f3f3; color: #bbb; border-color: #eee; cursor: not-allowed; }
                 
        button { width: 100%; padding: 1.2rem; background: var(--primary); color: white; border: none; border-radius: 20px; cursor: pointer; font-size: 0.9rem; font-weight: 900; transition: 0.4s; margin-top: 2rem; box-shadow: 0 15px 35px rgba(245, 158, 11, 0.2); text-transform: uppercase; letter-spacing: 3px; }
        button:hover { background: #d97706; transform: translateY(-5px); box-shadow: 0 20px 45px rgba(217, 119, 6, 0.3); }
        button:disabled { background: #eee; color: #ccc; box-shadow: none; transform: none; }
                 
        .forgot-password-wrapper { text-align: right; padding-right: 0.5rem; margin-top: 2px; }
        .forgot-password-wrapper a { font-size: 0.75rem; color: #bbb; text-decoration: none; font-weight: 600; transition: color 0.3s ease; }
        .forgot-password-wrapper a:hover { color: var(--primary); }
        .register-link { margin-top: 3.5rem; font-size: 0.85rem; color: #bbb; font-weight: 600; }
        .register-link a { color: var(--secondary); text-decoration: none; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; margin-left: 8px; transition: color 0.4s ease, opacity 0.4s ease; }
        .register-link a:hover { color: var(--primary); opacity: 0.8; }
        .blob { position: absolute; width: 300px; height: 300px; background: var(--primary); opacity: 0.03; filter: blur(80px); border-radius: 50%; z-index: 1; }
        .blob-1 { top: -150px; right: -150px; }
        .blob-2 { bottom: -150px; left: -150px; }
        .password-wrapper { position: relative; }
        .password-wrapper input { padding-right: 3.2rem; }
                 
        .toggle-password {
            position: absolute;
            top: 50%;
            right: 1.3rem;
            transform: translateY(-50%);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #bbb;
            transition: color 0.3s;
            background: none !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
            width: auto !important;
            box-shadow: none !important;
        }
        .toggle-password:hover {
             color: var(--primary) !important;
             background: none !important;
             transform: translateY(-50%) scale(1.1) !important;
             box-shadow: none !important;
         }
        .toggle-password svg { width: 20px; height: 20px; display: block; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
                 
        <div class="brand-name">BLOOM</div>
        <h2>Authenticated Access</h2>
        <p class="subtitle">Management Console Login</p>
        <div id="error-box" class="error-msg"></div>
        <form id="login-form">
            <input type="email" id="email" placeholder="Email Address" required autofocus>
            <div class="password-wrapper">
                <input type="password" id="password" placeholder="Password" required>
                <button type="button" class="toggle-password" id="toggle-password" aria-label="Show password" tabindex="-1">
                    <svg id="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>
            <div class="forgot-password-wrapper">
                <a href="forgot_password.php" tabindex="-1">Forgot Password?</a>
            </div>
            <button type="submit" id="login-btn">Login</button>
        </form>
        <div class="register-link">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
    <script>
        <?php
            $configPath = __DIR__ . '/firebase-applet-config.json';
            $config = file_exists($configPath) ? file_get_contents($configPath) : '{}';
            echo "const firebaseConfig = " . $config . ";";
        ?>
        if (firebaseConfig.projectId && !firebaseConfig.authDomain) {
            firebaseConfig.authDomain = firebaseConfig.projectId + ".firebaseapp.com";
        }
        if (!firebaseConfig.apiKey) {
            console.error("Firebase Config is missing!");
            document.getElementById('error-box').innerText = "System Error: Firebase configuration not found.";
            document.getElementById('error-box').style.display = 'block';
        } else {
            firebase.initializeApp(firebaseConfig);
            const auth = firebase.auth();
            const db = firebase.firestore();
            const loginForm = document.getElementById('login-form');
            const errorBox = document.getElementById('error-box');

            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('registered') === 'verify_pending') {
                errorBox.innerText = 'Account created! Check your email (and spam folder) for a confirmation link before logging in.';
                errorBox.style.display = 'block';
            }

            const LOCKOUT_KEY = 'bloom_login_lockout';
            let lockoutCountdownInterval = null;

            function getLockoutState() {
                try {
                    return JSON.parse(localStorage.getItem(LOCKOUT_KEY)) || { fails: 0, lockUntil: 0 };
                } catch (e) {
                    return { fails: 0, lockUntil: 0 };
                }
            }

            function saveLockoutState(state) {
                localStorage.setItem(LOCKOUT_KEY, JSON.stringify(state));
            }

            function clearLockoutState() {
                localStorage.removeItem(LOCKOUT_KEY);
            }

            function lockDurationForFails(fails) {
                const tier = Math.ceil(fails / 3);
                if (tier <= 1) return 30;
                if (tier === 2) return 60;
                return 120;
            }

            function setFormDisabled(disabled) {
                document.getElementById('email').disabled = disabled;
                document.getElementById('password').disabled = disabled;
                document.getElementById('login-btn').disabled = disabled;
            }

            function startLockoutCountdown(lockUntil) {
                setFormDisabled(true);
                if (lockoutCountdownInterval) clearInterval(lockoutCountdownInterval);

                const tick = () => {
                    const remaining = Math.ceil((lockUntil - Date.now()) / 1000);
                    if (remaining <= 0) {
                        clearInterval(lockoutCountdownInterval);
                        lockoutCountdownInterval = null;
                        setFormDisabled(false);
                        errorBox.style.display = 'none';
                        const state = getLockoutState();
                        state.lockUntil = 0;
                        saveLockoutState(state);
                    } else {
                        errorBox.innerText = `Too many failed attempts. Please wait ${remaining}s`;
                        errorBox.style.display = 'block';
                    }
                };
                tick();
                lockoutCountdownInterval = setInterval(tick, 1000);
            }

            function isCurrentlyLocked(state) {
                return !!(state.lockUntil && state.lockUntil > Date.now());
            }

            function registerFailedAttempt() {
                const state = getLockoutState();
                state.fails += 1;
                if (state.fails % 3 === 0) {
                    const durationSec = lockDurationForFails(state.fails);
                    state.lockUntil = Date.now() + durationSec * 1000;
                    saveLockoutState(state);
                    startLockoutCountdown(state.lockUntil);
                } else {
                    saveLockoutState(state);
                }
            }

            function registerSuccessfulLogin() {
                clearLockoutState();
            }

            (function checkLockoutOnLoad() {
                const state = getLockoutState();
                if (isCurrentlyLocked(state)) {
                    startLockoutCountdown(state.lockUntil);
                }
            })();

            loginForm.onsubmit = async (e) => {
                e.preventDefault();

                const lockState = getLockoutState();
                if (isCurrentlyLocked(lockState)) {
                    startLockoutCountdown(lockState.lockUntil);
                    return;
                }

                const email = document.getElementById('email').value.trim();
                const password = document.getElementById('password').value;
                const btn = document.getElementById('login-btn');
                btn.disabled = true;
                btn.innerText = 'Logging in...';
                errorBox.style.display = 'none';

                try {
                    const rateLimitResp = await fetch('check_login_risk.php', {
                        method: 'POST',
                    });
                    if (rateLimitResp.ok) {
                        const rateLimitResult = await rateLimitResp.json();
                        if (rateLimitResult.block) {
                            errorBox.innerText = rateLimitResult.reason || 'Too many attempts. Please wait and try again.';
                            errorBox.style.display = 'block';
                            btn.disabled = false;
                            btn.innerText = 'Login';
                            return;
                        }
                    }
                } catch (rateLimitError) {
                    console.warn('Login rate-limit check failed, proceeding anyway:', rateLimitError);
                }

                let targetUser = null;
                let userDocData = null;
                let matchedCollection = '';

                try {
                    try {
                        const userCredential = await auth.signInWithEmailAndPassword(email, password);
                        targetUser = userCredential.user;
                    } catch (authError) {
                        throw authError;
                    }

                    let userDoc = await db.collection('users').doc(targetUser.uid).get();
                    let existsInUsers = userDoc.exists;
                    let userData = userDocData || (existsInUsers ? userDoc.data() : null);

                    if (!existsInUsers && matchedCollection !== 'users') {
                        try {
                            const emailQuery = await db.collection('users').where('email', '==', targetUser.email.toLowerCase()).limit(1).get();
                            if (!emailQuery.empty) {
                                userDoc = emailQuery.docs[0];
                                userData = userDoc.data();
                                existsInUsers = true;
                                await db.collection('users').doc(userDoc.id).update({ uid: targetUser.uid });
                            }
                        } catch (usersLookupError) {
                            if (usersLookupError.code !== 'permission-denied') {
                                throw usersLookupError;
                            }
                        }
                    }

                    let role = 'customer';
                    let username = targetUser.email.split('@')[0];
                    let finalBranchId = 'main_branch';

                    // CHANGED: the super-admin branch now checks for an
                    // existing 'users' doc by email FIRST, mirroring the
                    // exact same fix applied to getUserData() on mobile.
                    // Previously this unconditionally set() a fresh doc
                    // at whatever targetUser.uid happened to be THIS
                    // login, with no memory of any doc that might exist
                    // under a DIFFERENT (stale) uid for this same email
                    // -- that gap is exactly what caused the duplicate
                    // account split you found in Firestore. If the Auth
                    // account is ever recreated again in the future
                    // (same email, new uid), this now finds the old
                    // profile via email lookup and consolidates it onto
                    // the current uid instead of quietly abandoning it.
                    if (targetUser.email.toLowerCase() === '789jojoalvarado@gmail.com') {
                        role = 'super-admin';
                        existsInUsers = true;

                        let existingSuperAdminData = null;
                        let existingSuperAdminDocId = null;
                        try {
                            const superAdminQuery = await db.collection('users')
                                .where('email', '==', '789jojoalvarado@gmail.com')
                                .limit(1)
                                .get();
                            if (!superAdminQuery.empty) {
                                existingSuperAdminData = superAdminQuery.docs[0].data();
                                existingSuperAdminDocId = superAdminQuery.docs[0].id;
                            }
                        } catch (superAdminLookupError) {
                            console.warn('Super-admin email lookup failed, proceeding with bootstrap defaults:', superAdminLookupError);
                        }

                        const superAdminData = {
                            ...(existingSuperAdminData || {}),
                            uid: targetUser.uid,
                            email: '789jojoalvarado@gmail.com',
                            username: existingSuperAdminData?.username || '789jojoalvarado',
                            firstName: existingSuperAdminData?.firstName || 'Super',
                            lastName: existingSuperAdminData?.lastName || 'Admin',
                            role: 'super-admin',
                            branchId: existingSuperAdminData?.branchId || 'main_branch',
                            created_at: existingSuperAdminData?.created_at || firebase.firestore.FieldValue.serverTimestamp()
                        };
                        await db.collection('users').doc(targetUser.uid).set(superAdminData, { merge: true });

                        // Consolidation: if the doc we found was sitting
                        // under a DIFFERENT (stale) uid than the one
                        // currently signed in, remove it now that its
                        // data has been copied forward -- otherwise it
                        // stays behind as a dead duplicate forever.
                        if (existingSuperAdminDocId && existingSuperAdminDocId !== targetUser.uid) {
                            try {
                                await db.collection('users').doc(existingSuperAdminDocId).delete();
                            } catch (cleanupError) {
                                console.warn('Could not delete stale super-admin doc (non-fatal):', cleanupError);
                            }
                        }

                        username = superAdminData.username;
                        finalBranchId = superAdminData.branchId;
                        localStorage.setItem('bloom_branch_id', finalBranchId);
                    } else if (existsInUsers && userData) {
                        role = userData.role || 'customer';
                        username = userData.username || userData.firstName || username;
                        finalBranchId = userData.branchId || 'main_branch';
                        localStorage.setItem('bloom_branch_id', finalBranchId);
                    } else {
                        const customerDoc = await db.collection('customers').doc(targetUser.uid).get();
                        if (customerDoc.exists) {
                            const customerData = customerDoc.data();
                            role = 'customer';
                            username = customerData.name || customerData.username || username;
                            finalBranchId = 'main_branch';
                        }
                    }

                    const idToken = await targetUser.getIdToken();
                    const deviceHash = await window.bloomGetDeviceId();
                    const formData = new FormData();
                    formData.append('idToken', idToken);
                    formData.append('deviceHash', deviceHash);

                    let response = await fetch('includes/set_session.php', {
                        method: 'POST',
                        body: formData
                    });
                    let sessionResult = await response.json();

                    if (!response.ok && sessionResult.code === 'EMAIL_NOT_VERIFIED') {
                        errorBox.innerHTML = (sessionResult.message || 'Please verify your email before logging in.') +
                            ' <button type="button" id="resend-verify-btn" style="text-decoration:underline;background:none;border:none;color:inherit;cursor:pointer;padding:0;font:inherit;">Resend verification email</button>';
                        errorBox.style.display = 'block';

                        const resendBtn = document.getElementById('resend-verify-btn');
                        if (resendBtn) {
                            resendBtn.onclick = async () => {
                                resendBtn.disabled = true;
                                resendBtn.innerText = 'Sending...';

                                let primarySucceeded = false;
                                try {
                                    const resendIdToken = await targetUser.getIdToken();
                                    const resendResp = await fetch('send_verification_email.php', {
                                        method: 'POST',
                                        headers: { 'Authorization': 'Bearer ' + resendIdToken }
                                    });
                                    const resendResult = await resendResp.json();
                                    primarySucceeded = resendResp.ok && resendResult.success === true;
                                } catch (primaryError) {
                                    console.warn('Custom resend failed, falling back to Firebase native:', primaryError);
                                }

                                try {
                                    if (!primarySucceeded) {
                                        await targetUser.sendEmailVerification();
                                    }
                                    resendBtn.innerText = 'Sent! Check your inbox.';
                                } catch (fallbackError) {
                                    console.error(fallbackError);
                                    resendBtn.innerText = 'Could not resend - try again shortly.';
                                    resendBtn.disabled = false;
                                }
                            };
                        }

                        btn.disabled = false;
                        btn.innerText = 'Login';
                        return;
                    }

                    if (response.ok && sessionResult.success) {
                        registerSuccessfulLogin();
                        const serverRole = sessionResult.role;
                        if (serverRole === 'admin' || serverRole === 'super-admin' || serverRole === 'staff' || serverRole === 'employee') {
                            window.location.href = 'admin.php';
                        } else if (serverRole === 'delivery') {
                            window.location.href = 'delivery_status.php';
                        } else {
                            window.location.href = 'templates/shop.php';
                        }
                    } else {
                        const sessionError = new Error(sessionResult.message || 'Failed to establish session.');
                        sessionError.isSessionError = true;
                        throw sessionError;
                    }
                } catch (error) {
                    console.error(error);

                    if (error.isSessionError) {
                        errorBox.innerText = error.message;
                        errorBox.style.display = 'block';
                        btn.disabled = false;
                        btn.innerText = 'Login';
                        return;
                    }

                    registerFailedAttempt();
                    const state = getLockoutState();
                    if (!isCurrentlyLocked(state)) {
                        errorBox.innerText = 'Invalid email or password';
                        errorBox.style.display = 'block';
                        btn.disabled = false;
                        btn.innerText = 'Login';
                    }
                }
            };

            const togglePasswordBtn = document.getElementById('toggle-password');
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            const eyeOpenPath = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            const eyeClosedPath = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';

            togglePasswordBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const isPassword = passwordInput.getAttribute('type') === 'password';
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                eyeIcon.innerHTML = isPassword ? eyeClosedPath : eyeOpenPath;
                togglePasswordBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        }
    </script>
</body>
</html>