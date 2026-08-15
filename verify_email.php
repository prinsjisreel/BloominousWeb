<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email - BLOOM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=Cormorant+Garamond:wght@700;900&display=swap" rel="stylesheet">
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>
    <style>
        :root {
            --primary: #F59E0B;
            --secondary: #121212;
            --background: #FFFDF7;
            --dark: #121212;
            --text-main: #363949;
        }
        body { font-family: 'Inter', sans-serif; background: var(--background); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; color: var(--text-main); }
        .card { background: #ffffff; padding: 4rem; border-radius: 40px; box-shadow: 0 40px 100px rgba(245,158,11,0.06); width: 100%; max-width: 480px; text-align: center; border: 1px solid rgba(245,158,11,0.03); margin: 2rem; }
        .brand-name { font-family: 'Cormorant Garamond', serif; font-size: 3rem; font-weight: 900; letter-spacing: 6px; color: var(--primary); margin-bottom: 2rem; }
        h2 { font-family: 'Cormorant Garamond', serif; color: var(--dark); font-size: 2.2rem; margin-bottom: 0.5rem; font-weight: 900; line-height: 1.1; }
        p.subtitle { color: #aaa; font-size: 0.9rem; margin-bottom: 2rem; font-weight: 600; }
        .icon { font-size: 3rem; margin-bottom: 1rem; }
        a.btn, button.btn { display: inline-block; width: 100%; padding: 1.2rem; background: var(--primary); color: white; border: none; border-radius: 20px; cursor: pointer; font-size: 0.9rem; font-weight: 900; transition: 0.4s; margin-top: 1.5rem; box-shadow: 0 15px 35px rgba(245, 158, 11, 0.2); text-transform: uppercase; letter-spacing: 3px; text-decoration: none; box-sizing: border-box; }
        a.btn:hover, button.btn:hover { background: #d97706; transform: translateY(-5px); box-shadow: 0 20px 45px rgba(217, 119, 6, 0.3); }
        .spinner { width: 40px; height: 40px; border: 4px solid #f0f0f0; border-top-color: var(--primary); border-radius: 50%; margin: 0 auto 1.5rem; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand-name">BLOOM</div>
        <div id="state-verifying">
            <div class="spinner"></div>
            <h2>Verifying your email...</h2>
            <p class="subtitle">Just a moment.</p>
        </div>
        <div id="state-success" style="display:none;">
            <div class="icon">✓</div>
            <h2>Email Verified!</h2>
            <p class="subtitle">Your account is now active. You can log in below.</p>
            <a class="btn" href="index.php">Continue to Login</a>
        </div>
        <div id="state-error" style="display:none;">
            <div class="icon">!</div>
            <h2>Link Expired or Invalid</h2>
            <p class="subtitle" id="error-detail">This verification link is no longer valid. Log in and use the resend option to get a new one.</p>
            <a class="btn" href="index.php">Return to Login</a>
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
        firebase.initializeApp(firebaseConfig);
        const auth = firebase.auth();

        const params = new URLSearchParams(window.location.search);
        const mode = params.get('mode');
        const oobCode = params.get('oobCode');

        const showState = (id) => {
            ['state-verifying', 'state-success', 'state-error'].forEach(s => {
                document.getElementById(s).style.display = (s === id) ? 'block' : 'none';
            });
        };

        (async () => {
            if (mode !== 'verifyEmail' || !oobCode) {
                document.getElementById('error-detail').innerText = 'This link is missing required information.';
                showState('state-error');
                return;
            }

            try {
                // applyActionCode does not require the user to be signed in -
                // the oobCode itself proves ownership of the email address.
                await auth.applyActionCode(oobCode);
                showState('state-success');
            } catch (err) {
                console.error('applyActionCode failed:', err);
                document.getElementById('error-detail').innerText =
                    (err.code === 'auth/expired-action-code')
                        ? 'This link has expired. Log in and use the resend option to get a new one.'
                        : 'This link is invalid or has already been used.';
                showState('state-error');
            }
        })();
    </script>
</body>
</html>