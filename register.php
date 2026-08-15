<?php
/**
 * BLOOMINOUS - User Registration (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join BloomShop - Register</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Cormorant+Garamond:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary: #F59E0B;
            --secondary: #121212;
            --bg: #FFFDF7;
            --text-main: #121212;
            --text-muted: #888888;
            --white: #ffffff;
            --error: #FF5252;
        }
        body {
             font-family: 'Inter', sans-serif;
             background: var(--bg);
             display: flex;
             justify-content: center;
             align-items: center;
             min-height: 100vh;
             margin: 0;
             padding: 30px;
        }
        .register-card {
             background: var(--white);
             padding: 50px;
             border-radius: 40px;
             box-shadow: 0 20px 60px rgba(245, 158, 11, 0.06);
             width: 100%;
             max-width: 500px;
             text-align: center;
             border: 1px solid rgba(245, 158, 11, 0.03);
             position: relative;
        }
        .brand-name {
             font-family: 'Cormorant Garamond', serif;
             font-size: 32px;
             font-weight: 900;
             letter-spacing: 4px;
             color: var(--primary);
             margin-bottom: 5px;
         }
        h2 {
             font-family: 'Cormorant Garamond', serif;
             color: var(--text-main);
             margin-bottom: 5px;
             font-weight: 800;
             font-size: 32px;
         }
        p.subtitle { color: var(--text-muted); font-size: 14px; margin-bottom: 35px; font-weight: 500; }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            text-align: left;
        }
        .form-group {
            position: relative;
            margin-bottom: 15px;
            text-align: left;
        }
        .full-width { grid-column: span 2; }
        .form-group i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.9rem;
            transition: 0.3s;
            pointer-events: none;
        }
        label {
            display: block;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin-bottom: 6px;
            margin-left: 10px;
        }
        input, select {
             width: 100%;
             padding: 14px 15px 14px 45px;
             border: 1px solid #f0f0f0;
             border-radius: 15px;
             box-sizing: border-box;
             outline: none;
             transition: 0.3s;
             background: #fafafa;
             font-family: 'Inter';
            font-weight: 500;
            color: var(--text-main);
            font-size: 0.85rem;
        }
        input:focus, select:focus {
             border-color: var(--primary);
             background: var(--white);
             box-shadow: 0 0 15px rgba(245, 158, 11, 0.05);
        }
        button {
             width: 100%;
             padding: 16px;
             background: var(--primary);
             color: white;
             border: none;
             border-radius: 15px;
             cursor: pointer;
             font-size: 14px;
             font-weight: 800;
             transition: 0.4s;
             margin-top: 15px;
             box-shadow: 0 8px 25px rgba(245, 158, 11, 0.2);
             text-transform: uppercase;
             letter-spacing: 2px;
        }
        button:hover {
             background: #d97706;
             transform: translateY(-3px);
             box-shadow: 0 12px 30px rgba(217, 119, 6, 0.3);
         }
        .footer-link { margin-top: 30px; font-size: 0.85rem; color: var(--text-muted); font-weight: 500; }
        .footer-link a { color: var(--secondary); text-decoration: none; font-weight: 700; }
        .footer-link a:hover { text-decoration: underline; }
        .error-msg {
              background: #fff5f8;
              color: var(--error);
              padding: 12px;
              border-radius: 12px;
              font-size: 0.8rem;
              margin-bottom: 20px;
              border: 1px solid rgba(233, 30, 99, 0.1);
             text-align: center;
              display: none;
              font-weight: 700;
         }
    </style>
    <!-- Firebase Dependencies -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>
    <script src="assets/script/device_fingerprint.js"></script>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body>
    <div class="register-card">
        <div class="brand-name">BLOOMINOUS</div>
        <h2>Create Account</h2>
        <p class="subtitle">Join our community of flower lovers</p>

        <div id="error-box" class="error-msg"></div>
        <form id="register-form">
            <div class="form-grid">
                <div class="form-group">
                    <label>First Name</label>
                    <i class="fa-solid fa-user"></i>
                    <input type="text" id="firstName" placeholder="First" required>
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <i class="fa-solid fa-user-tag"></i>
                    <input type="text" id="lastName" placeholder="Last" required>
                </div>
                <div class="form-group full-width">
                    <label>Middle Name (Optional)</label>
                    <i class="fa-solid fa-signature"></i>
                    <input type="text" id="middleName" placeholder="Middle Name">
                </div>
                <div class="form-group">
                    <label>Birthday</label>
                    <i class="fa-solid fa-cake-candles"></i>
                    <input type="date" id="birthday" required>
                </div>
                <div class="form-group">
                    <label>Sex</label>
                    <i class="fa-solid fa-venus-mars"></i>
                    <select id="sex" required style="padding-left: 45px;">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Email Access</label>
                    <i class="fa-solid fa-envelope"></i>
                    <input type="email" id="email" placeholder="email@address.com" required>
                </div>
                <div class="form-group full-width">
                    <label>Security Key</label>
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" id="password" placeholder=" " required>
                </div>
            </div>

            <div class="cf-turnstile" data-sitekey="<?php
                require_once __DIR__ . '/config.local.php';
                echo htmlspecialchars(getenv('TURNSTILE_SITE_KEY') ?: '', ENT_QUOTES);
            ?>"
                 data-callback="onTurnstileSuccess"
                 data-error-callback="onTurnstileError"
                 data-expired-callback="onTurnstileExpired"
                 style="margin-bottom: 15px;"></div>

            <button type="submit" id="register-btn">
                <i class="fa-solid fa-user-plus mr-2"></i> Join Experience
            </button>
        </form>
        <div class="footer-link">
            Belong here already? <a href="index.php">Sign In</a>
        </div>
    </div>
    <script>
        <?php
            $configPath = __DIR__ . '/firebase-applet-config.json';
            $config = file_exists($configPath) ? file_get_contents($configPath) : '{}';
            echo "const firebaseConfig = " . $config . ";";
        ?>

        let turnstileToken = '';
        const errorBox = document.getElementById('error-box');

        window.onTurnstileSuccess = function (token) {
            turnstileToken = token;
            errorBox.style.display = 'none';
        };

        window.onTurnstileError = function (errorCode) {
            turnstileToken = '';
            console.error('Turnstile widget error:', errorCode);
            errorBox.innerText = 'Verification failed to load. Please refresh the page and try again.';
            errorBox.style.display = 'block';
        };

        window.onTurnstileExpired = function () {
            turnstileToken = '';
            errorBox.innerText = 'Verification expired. Please complete it again.';
            errorBox.style.display = 'block';
        };

        if (firebaseConfig.apiKey) {
            firebase.initializeApp(firebaseConfig);
            const auth = firebase.auth();
            const db = firebase.firestore();
            const registerForm = document.getElementById('register-form');

            registerForm.onsubmit = async (e) => {
                e.preventDefault();
                const firstName = document.getElementById('firstName').value.trim();
                const lastName = document.getElementById('lastName').value.trim();
                const middleName = document.getElementById('middleName').value.trim();
                const birthday = document.getElementById('birthday').value;
                const sex = document.getElementById('sex').value;
                const email = document.getElementById('email').value.trim().toLowerCase();
                const password = document.getElementById('password').value;
                const btn = document.getElementById('register-btn');
                const fullName = (firstName + " " + (middleName ? middleName + " " : "") + lastName).trim();

                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Synchronizing...';
                errorBox.style.display = 'none';

                if (!turnstileToken) {
                    errorBox.innerText = 'Please complete the verification challenge.';
                    errorBox.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-user-plus mr-2"></i> Join Experience';
                    return;
                }

                try {
                    // Security Enforcement Check: Block blacklisted emails from creating accounts
                    const blocklistSnapshot = await db.collection('blocked_emails').doc(email).get();
                    if (blocklistSnapshot.exists) {
                        throw new Error("Security Restriction: This email address is permanently blacklisted due to automated fraud threshold failures.");
                    }

                    // Security Enforcement Check: Block devices tied to a prior
                    // auto-escalated fraud case from opening a fresh account.
                    const deviceHash = await window.bloomGetDeviceId();
                    const deviceBanSnapshot = await db.collection('banned_devices').doc(deviceHash).get();
                    if (deviceBanSnapshot.exists) {
                        throw new Error("Security Restriction: This device is not eligible to create a new account. Contact support if you believe this is an error.");
                    }

                    // Security Enforcement Check: IPQualityScore email risk
                    // (disposable/temp-mail domains, undeliverable addresses,
                    // known abuse), now ALSO covering rate limiting + Turnstile
                    // + domain allow-list server-side (see check_email_risk.php).
                    // The API key never touches the browser. Fails open if
                    // IPQS is unreachable.
                    let emailRiskFlag = false;
                    let emailRiskScoreBump = 0;
                    try {
                        const riskResp = await fetch('check_email_risk.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ email, turnstileToken })
                        });
                        const riskResult = await riskResp.json();

                        // A non-2xx response (400 malformed email/Turnstile
                        // fail, 405 wrong method, 429 rate-limited) never has
                        // a `block` key — it has `success:false` + either
                        // `message` (405/400 invalid-email path) or `reason`
                        // (429/Turnstile path). Check both field names before
                        // falling back to a generic message.
                        if (!riskResp.ok) {
                            const requestError = new Error(riskResult.message || riskResult.reason || 'Could not validate this email. Please check it and try again.');
                            requestError.isRiskBlock = true;
                            throw requestError;
                        }

                        if (riskResult.block) {
                            const blockedError = new Error(riskResult.reason || "This email address failed our risk check.");
                            blockedError.isRiskBlock = true;
                            throw blockedError;
                        }
                        emailRiskFlag = !!riskResult.flag;
                        emailRiskScoreBump = riskResult.scoreBump || 0;
                    } catch (riskError) {
                        if (riskError.isRiskBlock) {
                            throw riskError;
                        }
                        // Only genuine network/parse errors from our own
                        // endpoint reach here — fail open, don't block
                        // signup over that.
                    }

                    let role = 'customer';

                    const userData = {
                        firstName: firstName,
                        lastName: lastName,
                        middleName: middleName,
                        fullName: fullName,
                        birthday: birthday,
                        sex: sex,
                        email: email,
                        role: role,
                        points: 0,
                        total_spend: 0,
                        lastLogin: firebase.firestore.FieldValue.serverTimestamp(),
                        created_at: firebase.firestore.FieldValue.serverTimestamp()
                    };

                    // Proceed with standard sign-up if clear
                    const userCredential = await auth.createUserWithEmailAndPassword(email, password);
                    const user = userCredential.user;

                    // password field intentionally NOT written here —
                    // firestore.rules now blocks any client write to
                    // `customers` that includes a `password` key. Firebase
                    // Auth is the real password store; this document never
                    // needs its own plaintext copy.
                    await db.collection('customers').doc(user.uid).set({
                        ...userData,
                        name: fullName,
                        deviceHashes: [deviceHash],
                        requireEmailVerification: true
                    });

                    // Send our own branded verification email instead of
                    // Firebase's default firebaseapp.com flow. Best-effort:
                    // if this fails, still let the account exist rather than
                    // losing the Auth user just created; the person can
                    // request another one from the login page's resend link.
                    try {
                        const freshIdTokenForVerify = await user.getIdToken();
                        await fetch('send_verification_email.php', {
                            method: 'POST',
                            headers: { 'Authorization': 'Bearer ' + freshIdTokenForVerify }
                        });
                    } catch (verifyEmailError) {
                        console.warn('Could not send verification email:', verifyEmailError);
                    }

                    if (emailRiskFlag && emailRiskScoreBump > 0) {
                        try {
                            const freshIdToken = await user.getIdToken();
                            await fetch('record_email_risk.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                    'Authorization': 'Bearer ' + freshIdToken
                                },
                                body: new URLSearchParams({ scoreBump: String(emailRiskScoreBump) })
                            });
                        } catch (recordError) {
                            console.warn('Could not record email risk score:', recordError);
                        }
                    }

                    window.location.href = 'index.php?registered=verify_pending';

                } catch (error) {
                    console.error(error);
                    errorBox.innerText = error.message || 'Registration failed. Please try again.';
                    errorBox.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-user-plus mr-2"></i> Join Experience';
                }
            };
        }
    </script>
</body>
</html>