<?php session_start(); // If already logged in, redirect if (isset($_SESSION['admin_id'])) {     header("Location: admin.php");     exit(); } ?>
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

            // Registration just redirected here after creating the account
            // and sending the confirmation email - tell the person to go
            // check their inbox before they try logging in.
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('registered') === 'verify_pending') {
                errorBox.innerText = 'Account created! Check your email (and spam folder) for a confirmation link before logging in.';
                errorBox.style.display = 'block';
            }

            // --- Escalating lockout (client-side, per browser) ---
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
                const tier = Math.ceil(fails / 3); // 1st tier of 3 fails = tier 1, etc.
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

            // Re-check lock state on page load (refresh / navigate back doesn't reset it)
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

                // ← NEW: real, server-side rate limit — the client-side
                // lockout above is a UX nicety only (localStorage-based,
                // trivially bypassed). This is the actual security
                // boundary, checked BEFORE Firebase Auth ever runs.
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
                    // Fail open — never block a real login over this
                    // endpoint being unreachable.
                    console.warn('Login rate-limit check failed, proceeding anyway:', rateLimitError);
                }

                let targetUser = null;
                let userDocData = null;
                let matchedCollection = '';

                try {
                    // 1. Primary Attempt: Attempt core authentication natively via Firebase Auth vault[cite: 3]
                    try {
                        const userCredential = await auth.signInWithEmailAndPassword(email, password);
                        targetUser = userCredential.user;
                    } catch (authError) {
                        // 2. FALLBACK INTERCEPTOR LOGIC: modern Firebase Auth merges
                        // wrong-password and user-not-found into a single
                        // 'auth/invalid-credential' code (email enumeration
                        // protection), so we check for that instead of the
                        // legacy codes, which are no longer thrown.
                        if (authError.code === 'auth/invalid-credential' || authError.code === 'auth/wrong-password' || authError.code === 'auth/user-not-found') {

                            // NOTE: intentionally NOT querying 'users' here.
                            // firestore.rules only allows `list` on /users to
                            // admins, and this code runs while unauthenticated
                            // (login just failed) - that query can never
                            // succeed and previously threw its own
                            // "Missing or insufficient permissions" error on
                            // top of the real one. /customers allows public
                            // read, so only that collection is checked.
                            const custQuery = await db.collection('customers').where('email', '==', email.toLowerCase()).get();
                            const custFallbackQuery = await db.collection('customers').where('custEmail', '==', email.toLowerCase()).get();

                            let matchedDoc = null;

                            if (!custQuery.empty) {
                                matchedDoc = custQuery.docs[0];
                                matchedCollection = 'customers';
                            } else if (!custFallbackQuery.empty) {
                                matchedDoc = custFallbackQuery.docs[0];
                                matchedCollection = 'customers';
                            }

                            // If matched document text matches exactly what was submitted, force sync re-authentication[cite: 3, 5]
                            if (matchedDoc && matchedDoc.data().password === password) {
                                userDocData = matchedDoc.data();
                                
                                // Sign in using the old password placeholder dynamically to acquire session tokens,
                                // then immediately push the update parameters to override the vault password string safely[cite: 3, 5]
                                const fallbackAuthCredential = await auth.signInWithEmailAndPassword(email, matchedDoc.data().password);
                                targetUser = fallbackAuthCredential.user;
                                await targetUser.updatePassword(password); 
                            } else {
                                throw authError; // Plaintext data mismatch as well, reject completely[cite: 3]
                            }
                        } else {
                            throw authError; // Network error or something else, bubble up exception[cite: 3]
                        }
                    }

                    // 3. Resolve role configuration and set dynamic session vectors[cite: 3]
                    let userDoc = await db.collection('users').doc(targetUser.uid).get();
                    let existsInUsers = userDoc.exists;
                    let userData = userDocData || (existsInUsers ? userDoc.data() : null);

                    if (!existsInUsers && matchedCollection !== 'users') {
                        // Ordinary customers were never written into /users
                        // (only /customers), so this lookup denying with
                        // "permission-denied" here is the EXPECTED outcome,
                        // not a real error - firestore.rules only allows
                        // `list` on /users to admins. Treat that specific
                        // failure as "not an admin account" and keep going
                        // as a customer, instead of crashing the whole login.
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
                            // permission-denied => not an admin/staff account, proceed as customer
                        }
                    }

                    let role = 'customer';
                    let username = targetUser.email.split('@')[0];
                    let finalBranchId = 'main_branch';

                    if (targetUser.email.toLowerCase() === '789jojoalvarado@gmail.com') {
                        role = 'super-admin';
                        username = 'Super Admin';
                        finalBranchId = 'main_branch';
                        existsInUsers = true;
                        
                        const superAdminData = {
                            uid: targetUser.uid,
                            email: targetUser.email.toLowerCase(),
                            username: '789jojoalvarado',
                            firstName: 'Super',
                            lastName: 'Admin',
                            role: 'super-admin',
                            branchId: 'main_branch',
                            created_at: firebase.firestore.FieldValue.serverTimestamp()
                        };
                        await db.collection('users').doc(targetUser.uid).set(superAdminData, { merge: true });
                        localStorage.setItem('bloom_branch_id', 'main_branch');
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

                    // Only send a proof of identity (the ID token). The server
                    // verifies it and looks up uid/role/username itself —
                    // it no longer trusts anything the client claims here.
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

                    // Correct credentials, but this account was created after
                    // the email-verification feature shipped and hasn't
                    // clicked its confirmation link yet. This is NOT a wrong
                    // password/lockout situation, so we handle it here and
                    // return early instead of falling through to the
                    // generic catch block (which would count it as a failed
                    // login attempt and show a misleading error).
                    if (!response.ok && sessionResult.code === 'EMAIL_NOT_VERIFIED') {
                        errorBox.innerHTML = (sessionResult.message || 'Please verify your email before logging in.') +
                            ' <button type="button" id="resend-verify-btn" style="text-decoration:underline;background:none;border:none;color:inherit;cursor:pointer;padding:0;font:inherit;">Resend verification email</button>';
                        errorBox.style.display = 'block';

                        const resendBtn = document.getElementById('resend-verify-btn');
                        if (resendBtn) {
                            resendBtn.onclick = async () => {
                                resendBtn.disabled = true;
                                resendBtn.innerText = 'Sending...';
                                try {
                                    const resendIdToken = await targetUser.getIdToken();
                                    const resendResp = await fetch('send_verification_email.php', {
                                        method: 'POST',
                                        headers: { 'Authorization': 'Bearer ' + resendIdToken }
                                    });
                                    const resendResult = await resendResp.json();
                                    if (!resendResp.ok || !resendResult.success) {
                                        throw new Error(resendResult.message || 'Could not resend.');
                                    }
                                    resendBtn.innerText = 'Sent! Check your inbox.';
                                } catch (resendError) {
                                    resendBtn.innerText = 'Could not resend - try again shortly.';
                                    resendBtn.disabled = false;
                                }
                            };
                        }

                        btn.disabled = false;
                        btn.innerText = 'Login';
                        return;
                    }

                    // This account is already flagged and we don't recognize
                    // this device — walk the user through an email code
                    // before trying set_session.php again.
                    if (!response.ok && sessionResult.code === 'DEVICE_VERIFICATION_REQUIRED') {
                        const sendResp = await fetch('send_device_otp.php', {
                            method: 'POST',
                            headers: { 'Authorization': 'Bearer ' + idToken },
                            body: new URLSearchParams({ deviceHash })
                        });
                        const sendResult = await sendResp.json();
                        if (!sendResult.success) {
                            throw new Error(sendResult.message || 'Could not send verification email.');
                        }

                        const code = prompt('This device isn\'t recognized on this flagged account. Enter the 6-digit code we just emailed you:');
                        if (!code) {
                            throw new Error('Device verification cancelled.');
                        }

                        const verifyResp = await fetch('verify_device_otp.php', {
                            method: 'POST',
                            headers: { 'Authorization': 'Bearer ' + idToken },
                            body: new URLSearchParams({ deviceHash, code })
                        });
                        const verifyResult = await verifyResp.json();
                        if (!verifyResult.success) {
                            throw new Error(verifyResult.message || 'Incorrect or expired code.');
                        }

                        formData.append('deviceOtpVerified', '1');
                        response = await fetch('includes/set_session.php', {
                            method: 'POST',
                            body: formData
                        });
                        sessionResult = await response.json();
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
                        throw new Error('Failed to establish session.');
                    }
                } catch (error) {
                    console.error(error);
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