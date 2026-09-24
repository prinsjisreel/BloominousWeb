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

        /* --- Terms & Conditions agreement row --- */
        .terms-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            text-align: left;
            margin-top: 20px;
            padding: 14px 16px;
            background: #fafafa;
            border-radius: 15px;
            border: 1px solid #f0f0f0;
        }
        .terms-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            min-width: 18px;
            margin-top: 2px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        .terms-row label {
            margin: 0;
            text-transform: none;
            letter-spacing: normal;
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--text-main);
            cursor: pointer;
            line-height: 1.6;
        }
        .terms-link {
            display: inline;
            width: auto;
            margin: 0;
            padding: 0;
            border: none;
            border-radius: 0;
            box-shadow: none;
            text-transform: none;
            letter-spacing: normal;
            vertical-align: baseline;
            background: none;
            color: var(--primary);
            font-weight: 800;
            text-decoration: underline;
            cursor: pointer;
            font-size: inherit;
            font-family: inherit;
            line-height: inherit;
            transition: none;
        }
        .terms-link:hover {
            background: none;
            color: #d97706;
            transform: none;
            box-shadow: none;
        }

        /* --- Terms of Service modal --- */
        #tosOverlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(20, 20, 20, 0.55);
            z-index: 999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        #tosOverlay.open { display: flex; }
        #tosModal {
            background: var(--white);
            border-radius: 28px;
            max-width: 620px;
            width: 100%;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 30px 60px rgba(0,0,0,0.2);
            text-align: left;
        }
        .tos-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 28px 32px 16px;
            border-bottom: 1px solid #f0f0f0;
        }
        .tos-modal-header h3 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem;
            font-weight: 900;
            color: var(--text-main);
            margin: 0;
        }
        .tos-close-btn {
            display: inline-block;
            width: auto;
            padding: 6px 10px;
            margin: 0;
            background: #f5f5f5;
            color: var(--text-muted);
            border-radius: 10px;
            box-shadow: none;
            text-transform: none;
            letter-spacing: normal;
            font-size: 0.9rem;
        }
        .tos-close-btn:hover { background: #eee; transform: none; box-shadow: none; }
        .tos-modal-body {
            padding: 20px 32px 32px;
            overflow-y: auto;
            font-size: 0.82rem;
            line-height: 1.6;
            color: #444;
        }
        .tos-modal-body p { margin: 0 0 14px; }
        .tos-section-title {
            font-weight: 800;
            color: var(--text-main);
            font-size: 0.85rem;
            margin: 20px 0 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .tos-section-title:first-child { margin-top: 0; }
        .tos-modal-footer {
            padding: 16px 32px 28px;
            border-top: 1px solid #f0f0f0;
        }
        .tos-modal-footer button { margin-top: 0; }
    </style>
    <!-- Firebase Dependencies -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>
    <script src="assets/script/device_fingerprint.js"></script>
    <!-- FIXED: was Cloudflare Turnstile's api.js. check_email_risk.php's
         server-side verification was already switched to Google reCAPTCHA
         v2 (see recaptcha_client.php) — Turnstile was dropped project-wide
         because Hostinger's own ModSecurity/CDN layer was interfering
         with Cloudflare's challenge resources. This file was the one
         piece never updated to match. -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
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

            <!-- FIXED: was a .cf-turnstile div reading a nonexistent
                 TURNSTILE_SITE_KEY env var (rendered as data-sitekey="").
                 Now reads RECAPTCHA_SITE_KEY, which is already configured
                 in config.local.php and already what check_email_risk.php
                 expects to verify server-side. -->
            <div class="g-recaptcha" data-sitekey="<?php
                require_once __DIR__ . '/config.local.php';
                echo htmlspecialchars(getenv('RECAPTCHA_SITE_KEY') ?: '', ENT_QUOTES);
            ?>"
                 data-callback="onRecaptchaSuccess"
                 data-expired-callback="onRecaptchaExpired"
                 data-error-callback="onRecaptchaError"
                 style="margin-bottom: 15px;"></div>

            <div class="terms-row">
                <input type="checkbox" id="agreeTerms" required>
                <label for="agreeTerms">
                    I have read and agree to the
                    <button type="button" class="terms-link" onclick="openTermsModal()">Terms and Conditions</button>
                    of Bloominous Flower Shop.
                </label>
            </div>

            <button type="submit" id="register-btn">
                <i class="fa-solid fa-user-plus mr-2"></i> Join Experience
            </button>
        </form>
        <div class="footer-link">
            Belong here already? <a href="index.php">Sign In</a>
        </div>
    </div>

    <div id="tosOverlay">
        <div id="tosModal">
            <div class="tos-modal-header">
                <h3>Terms of Service</h3>
                <button type="button" class="tos-close-btn" onclick="closeTermsModal()">
                    <i class="fa-solid fa-xmark"></i> Close
                </button>
            </div>
            <div class="tos-modal-body">
                <p>By placing an order on this website or mobile application, you are agreeing to the following terms and conditions:</p>

                <div class="tos-section-title">Order Acceptance Policy</div>
                <p>All orders and online requests received are subject to acceptance by Bloominous Flower Shop. We reserve the right, at our absolute discretion, to reject or cancel any order without giving prior reasons (e.g., due to stock depletion or system fraud flags). In the event of an order rejection by our management, any payment received will be refunded or canceled in full via the original payment method used.</p>

                <div class="tos-section-title">Delivery &amp; Pickup Policy</div>
                <p>Flower deliveries are available from Mondays to Sundays. For online ordering, customers may select their preferred fulfillment mode: Cash on Delivery (COD), Store Pick-up, or E-Wallet payment. For same-day deliveries, orders must be placed within the available operational hours of the target branches. Specific time-slot deliveries are subject to local traffic conditions and courier availability. While we strive for punctuality, Bloominous Flower Shop cannot be held liable for late deliveries caused by severe weather conditions, extreme traffic, or factors outside our control. The sender is responsible for providing accurate recipient details (full name, complete address, and active contact number). If a delivery fails due to incorrect customer details or an uncontactable recipient, a re-delivery fee may apply.</p>

                <div class="tos-section-title">Changes to Your Order</div>
                <p>If you wish to make changes to your order (including delivery addresses or card greeting messages), please contact our team immediately through our official channels. For scheduled or pre-orders, request changes at least one day prior to the delivery date. For same-day orders, we will make every effort to accommodate modifications, but changes cannot be guaranteed once processing has begun.</p>

                <div class="tos-section-title">Cancellation &amp; Refund Policy</div>
                <p>Advance / Pre-Orders: Cancellations requested before the order goes into the preparation pipeline may be granted and processed via store credit or account balance adjustment. Same-Day &amp; Custom AR Orders: Orders that have already been prepared, assembled, or dispatched by our florists cannot be canceled or refunded due to the perishable nature of floral stocks. Non-Refundable Policy: Strictly no cash refund transactions are permitted once a floral arrangement has been custom-built, accepted, or successfully delivered. If an order cannot be fulfilled by our shop due to unforeseen supply issues, a full replacement or reimbursement will be issued.</p>

                <div class="tos-section-title">Product, AR Customization, and Substitution Policy</div>
                <p>Perishable Nature: Flowers are natural, perishable goods. Their actual color, shade, bouquet size, fillers and bloom stage may slightly vary from visual previews. Augmented Reality (AR) Previews: The 3D and AR customization modules within our web and mobile application serve as interactive visual references. While AR models provide a 3D preview of arrangement styles, wrappers, and ribbons, minor differences between the digital preview and the physical hand-crafted arrangement may occur. Substitutions: All items are subject to live stock availability. If specific flower species, wrapper colors, or accessories become unavailable, Bloominous Flower Shop reserves the right to substitute them with materials of equivalent or greater value and quality to maintain the design aesthetic.</p>

                <div class="tos-section-title">Payments</div>
                <p>We accept Cash on Pick-up, Cash on Delivery (COD), and digital E-Wallets (GCash, PayMaya, etc.). Online e-wallet transactions and digital receipts are validated securely through integrated payment channels. We do not store sensitive payment card credentials directly on our local servers.</p>

                <div class="tos-section-title">Freshness &amp; Quality Assurance</div>
                <p>We are committed to delivering fresh floral arrangements. Fresh flowers are visually scanned and monitored using our integrated freshness tracking tools. If you receive flowers that are severely damaged or defective upon arrival, you must report the issue within 24 hours of receipt by providing your Order ID and clear photographs of the product. Valid reports will be reviewed by our customer support for a replacement on the next available delivery date.</p>

                <div class="tos-section-title">Promos &amp; Discount Vouchers</div>
                <p>Discount vouchers and promotional promo codes may be issued periodically at our discretion. Promotional codes cannot be combined with other active discounts or retroactively applied to completed orders. Bloominous Flower Shop reserves the right to modify or discontinue promo offers without prior notice.</p>

                <div class="tos-section-title">Force Majeure &amp; Uncontrollable Circumstances</div>
                <p>Bloominous Flower Shop shall not be liable for delayed performance or delivery failures resulting from severe weather conditions (typhoons, heavy floods), acts of God, government restrictions, power outages, system network disruptions, or other events beyond our reasonable control.</p>

                <div class="tos-section-title">Customer Information &amp; Privacy</div>
                <p>We value your privacy. Personal information collected during account registration and checkout—such as your full name, contact details, email address, and recipient address—is strictly used to fulfill transactions, process orders, send status updates, and improve user service. We do not lease, sell, or rent your personal information to third parties.</p>

                <div class="tos-section-title">Store Details &amp; Operating Headquarters</div>
                <p>Bloominous Flower Shop. Operating Hours: 12 Hours Daily (Catering to Walk-in &amp; Online Customers).</p>
            </div>
            <div class="tos-modal-footer">
                <button type="button" onclick="closeTermsModal()">I Understand</button>
            </div>
        </div>
    </div>

    <script>
        <?php
            $configPath = __DIR__ . '/firebase-applet-config.json';
            $config = file_exists($configPath) ? file_get_contents($configPath) : '{}';
            echo "const firebaseConfig = " . $config . ";";
        ?>

        // FIXED: renamed from turnstileToken throughout this file. This
        // MUST match the field name check_email_risk.php actually reads
        // now ($body['recaptchaToken']) — that file was already switched
        // to reCAPTCHA in an earlier session; this variable was the last
        // piece of Turnstile terminology left in the codebase.
        let recaptchaToken = '';
        const errorBox = document.getElementById('error-box');

        function openTermsModal() {
            document.getElementById('tosOverlay').classList.add('open');
        }
        function closeTermsModal() {
            document.getElementById('tosOverlay').classList.remove('open');
        }

        window.onRecaptchaSuccess = function (token) {
            recaptchaToken = token;
            errorBox.style.display = 'none';
        };

        window.onRecaptchaError = function () {
            recaptchaToken = '';
            console.error('reCAPTCHA widget failed to load.');
            errorBox.innerText = 'Verification failed to load. Please refresh the page and try again.';
            errorBox.style.display = 'block';
        };

        window.onRecaptchaExpired = function () {
            recaptchaToken = '';
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

                if (!document.getElementById('agreeTerms').checked) {
                    errorBox.innerText = 'You must agree to the Terms and Conditions to create an account.';
                    errorBox.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-user-plus mr-2"></i> Join Experience';
                    return;
                }

                if (!recaptchaToken) {
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
                    // known abuse), now ALSO covering rate limiting +
                    // reCAPTCHA + domain allow-list server-side (see
                    // check_email_risk.php). The secret key never touches
                    // the browser. Fails open if AbstractAPI is unreachable.
                    let emailRiskFlag = false;
                    let emailRiskScoreBump = 0;
                    try {
                        const riskResp = await fetch('check_email_risk.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ email, recaptchaToken })
                        });
                        const riskResult = await riskResp.json();

                        // A non-2xx response (400 malformed email/reCAPTCHA
                        // fail, 405 wrong method, 429 rate-limited) never has
                        // a `block` key — it has `success:false` + either
                        // `message` (405/400 invalid-email path) or `reason`
                        // (429/reCAPTCHA path). Check both field names before
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
                        agreedToTermsAt: firebase.firestore.FieldValue.serverTimestamp(),
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

                    // NEW: reCAPTCHA v2 tokens are single-use — the one
                    // just sent to check_email_risk.php (or that would
                    // have been sent, had we gotten past an earlier check
                    // like the blocklist/device-ban ones above) is spent
                    // the moment it's verified, win or lose. Without this
                    // reset, a second attempt after ANY failure here would
                    // silently reuse a dead token and fail again with a
                    // confusing "verification challenge" message that has
                    // nothing to do with whatever actually went wrong.
                    if (typeof grecaptcha !== 'undefined') {
                        grecaptcha.reset();
                    }
                    recaptchaToken = '';
                }
            };
        }
    </script>
</body>
</html>