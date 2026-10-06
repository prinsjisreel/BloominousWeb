<?php
session_start();
// Already signed in to the management console? No need to recover anything.
if (isset($_SESSION['admin_id'])) {
    header("Location: admin.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - BLOOM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=Cormorant+Garamond:wght@700;900&display=swap" rel="stylesheet">
    <!-- No Firebase SDK on this page: the reset email is created and sent by
         request_password_reset.php, from the shop's own Gmail. -->
    <style>
        :root {
            --primary: #F59E0B;
            --secondary: #121212;
            --background: #FFFDF7;
            --dark: #121212;
            --text-main: #363949;
        }
        body { font-family: 'Inter', sans-serif; background: var(--background); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; color: var(--text-main); }
        .login-container { background: #ffffff; padding: 4rem; border-radius: 40px; box-shadow: 0 40px 100px rgba(245,158,11,0.06); width: 100%; max-width: 480px; text-align: center; border: 1px solid rgba(245,158,11,0.03); margin: 2rem; position: relative; overflow: hidden; z-index: 10; box-sizing: border-box; }
        .brand-name { font-family: 'Cormorant Garamond', serif; font-size: 3rem; font-weight: 900; letter-spacing: 6px; color: var(--primary); margin-bottom: 2rem; position: relative; z-index: 2; }
        h2 { font-family: 'Cormorant Garamond', serif; color: var(--dark); font-size: 2.5rem; margin-bottom: 0.5rem; font-weight: 900; line-height: 1.1; }
        p.subtitle { color: #aaa; font-size: 0.9rem; margin-bottom: 3rem; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; }
        .status-msg { padding: 1.2rem; border-radius: 20px; font-size: 0.8rem; margin-bottom: 2.5rem; text-align: center; font-weight: 800; letter-spacing: 0.5px; display: none; line-height: 1.6; }
        .error-layout { background: #fff5f8; color: #E91E63; border: 1px solid rgba(233,30,99,0.1); }
        .success-layout { background: #f0fdf4; color: #15803d; border: 1px solid rgba(21,128,61,0.1); }

        input[type="email"] {
            width: 100%;
            padding: 1.2rem 1.5rem;
            margin: 0.8rem 0;
            border: 1px solid #f0f0f0;
            border-radius: 20px;
            box-sizing: border-box;
            outline: none;
            transition: 0.4s;
            font-size: 1rem;
            background: #fafafa;
            font-weight: 600;
            color: var(--text-main);
        }
        input[type="email"]:focus { border-color: var(--primary); background: #fff; box-shadow: 0 10px 25px rgba(245, 158, 11, 0.05); }

        button { width: 100%; padding: 1.2rem; background: var(--primary); color: white; border: none; border-radius: 20px; cursor: pointer; font-size: 0.9rem; font-weight: 900; transition: 0.4s; margin-top: 2rem; box-shadow: 0 15px 35px rgba(245, 158, 11, 0.2); text-transform: uppercase; letter-spacing: 3px; }
        button:hover { background: #d97706; transform: translateY(-5px); box-shadow: 0 20px 45px rgba(217, 119, 6, 0.3); }
        button:disabled { background: #eee; color: #ccc; box-shadow: none; transform: none; cursor: not-allowed; }

        .hint { font-size: 0.75rem; color: #bbb; font-weight: 600; margin-top: 1.2rem; line-height: 1.6; }
        .back-link { margin-top: 3.5rem; font-size: 0.85rem; color: #bbb; font-weight: 600; }
        .back-link a { color: var(--secondary); text-decoration: none; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; margin-left: 8px; transition: color 0.4s ease, opacity 0.4s ease; }
        .back-link a:hover { color: var(--primary); opacity: 0.8; }
        .blob { position: absolute; width: 300px; height: 300px; background: var(--primary); opacity: 0.03; filter: blur(80px); border-radius: 50%; z-index: 1; }
        .blob-1 { top: -150px; right: -150px; }
        .blob-2 { bottom: -150px; left: -150px; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="brand-name">BLOOM</div>
        <h2 class="brand-font">Account Recovery</h2>
        <p class="subtitle" id="form-subtitle">Reset your password</p>
        <div id="status-box" class="status-msg" role="status" aria-live="polite"></div>

        <form id="email-request-form" novalidate>
            <input type="email" id="recovery-email" placeholder="Email Address" autocomplete="email" required autofocus>
            <button type="submit" id="request-btn">Send Reset Link</button>
            <p class="hint">We'll email you a secure link to choose a new password. The link works once and expires after about an hour.</p>
        </form>

        <div class="back-link">
            Remember credentials? <a href="index.php">Return to Sign In</a>
        </div>
    </div>

    <script>
        const emailForm = document.getElementById('email-request-form');
        const emailInput = document.getElementById('recovery-email');
        const requestBtn = document.getElementById('request-btn');
        const statusBox = document.getElementById('status-box');
        const subtitleText = document.getElementById('form-subtitle');

        // Courtesy cooldown between requests from this page. The REAL limits
        // live on the server (request_password_reset.php).
        const RESEND_COOLDOWN_SECONDS = 60;
        let cooldownTimer = null;

        function showStatus(message, tone) {
            statusBox.textContent = message; // textContent: never treated as HTML
            statusBox.className = 'status-msg ' + (tone === 'success' ? 'success-layout' : 'error-layout');
            statusBox.style.display = 'block';
        }

        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function setButtonReady() {
            requestBtn.disabled = false;
            requestBtn.innerText = 'Send Reset Link';
        }

        function startCooldown() {
            let secondsLeft = RESEND_COOLDOWN_SECONDS;
            requestBtn.disabled = true;
            requestBtn.innerText = `Resend in ${secondsLeft}s`;
            clearInterval(cooldownTimer);
            cooldownTimer = setInterval(() => {
                secondsLeft--;
                if (secondsLeft <= 0) {
                    clearInterval(cooldownTimer);
                    requestBtn.disabled = false;
                    requestBtn.innerText = 'Resend Reset Link';
                } else {
                    requestBtn.innerText = `Resend in ${secondsLeft}s`;
                }
            }, 1000);
        }

        emailForm.onsubmit = async (event) => {
            event.preventDefault();
            statusBox.style.display = 'none';

            const email = emailInput.value.trim().toLowerCase();
            if (!isValidEmail(email)) {
                showStatus('Please enter a valid email address.', 'error');
                emailInput.focus();
                return;
            }

            requestBtn.disabled = true;
            requestBtn.innerText = 'Sending...';

            try {
                // Our server creates the secure Firebase link and emails it
                // from the shop's Gmail. The link is never sent back here.
                const response = await fetch('request_password_reset.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email })
                });

                // Guard against a PHP error page (HTML) instead of JSON.
                let result = {};
                try { result = await response.json(); } catch (_) { result = {}; }

                if (result.success) {
                    // Same message whether or not the account exists.
                    subtitleText.innerText = 'Check your inbox';
                    showStatus(
                        `If an account exists for ${email}, we've sent a password reset link. ` +
                        'Check your inbox and spam folder, then follow the link to choose a new password.',
                        'success'
                    );
                    startCooldown();
                    return;
                }

                showStatus(result.message || 'We could not send the reset link right now. Please try again shortly.', 'error');
                if (result.code === 'RATE_LIMITED') {
                    startCooldown();
                } else {
                    setButtonReady();
                }
            } catch (error) {
                console.error('Password reset request failed:', error);
                showStatus('Network error. Please check your connection and try again.', 'error');
                setButtonReady();
            }
        };
    </script>
</body>
</html>