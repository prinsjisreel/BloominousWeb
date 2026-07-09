<?php
session_start();

// If already logged in, redirect
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
    <title>Login - BLOOM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=Cormorant+Garamond:wght@700;900&display=swap" rel="stylesheet">
    <!-- Firebase SDK -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>
    <style>
        :root {
            --primary: #E91E63;
            --secondary: #7B79F2;
            --background: #FFFDF7;
            --dark: #121212;
            --text-main: #363949;
        }
        body { font-family: 'Inter', sans-serif; background: var(--background); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; color: var(--text-main); }
        .login-container { background: #ffffff; padding: 4rem; border-radius: 40px; box-shadow: 0 40px 100px rgba(233,30,99,0.06); width: 100%; max-width: 480px; text-align: center; border: 1px solid rgba(233,30,99,0.03); margin: 2rem; position: relative; overflow: hidden; }
        
        .brand-name { font-family: 'Cormorant Garamond', serif; font-size: 3rem; font-weight: 900; letter-spacing: 6px; color: var(--primary); margin-bottom: 2rem; position: relative; z-index: 2; }
        
        h2 { font-family: 'Cormorant Garamond', serif; color: var(--dark); font-size: 2.5rem; margin-bottom: 0.5rem; font-weight: 900; line-height: 1.1; }
        p.subtitle { color: #aaa; font-size: 0.9rem; margin-bottom: 3rem; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; }
        
        .error-msg { background: #fff5f8; color: var(--primary); padding: 1.2rem; border-radius: 20px; font-size: 0.8rem; margin-bottom: 2.5rem; border: 1px solid rgba(233,30,99,0.1); text-align: center; display: none; font-weight: 800; letter-spacing: 0.5px; }
        
        input { width: 100%; padding: 1.2rem 1.5rem; margin: 0.8rem 0; border: 1px solid #f0f0f0; border-radius: 20px; box-sizing: border-box; outline: none; transition: 0.4s; font-size: 1rem; background: #fafafa; font-weight: 600; color: var(--text-main); }
        input:focus { border-color: var(--primary); background: #fff; box-shadow: 0 10px 25px rgba(233, 30, 99, 0.05); }
        
        button { width: 100%; padding: 1.2rem; background: var(--primary); color: white; border: none; border-radius: 20px; cursor: pointer; font-size: 0.9rem; font-weight: 900; transition: 0.4s; margin-top: 2rem; box-shadow: 0 15px 35px rgba(233, 30, 99, 0.2); text-transform: uppercase; letter-spacing: 3px; }
        button:hover { background: #d81b60; transform: translateY(-5px); box-shadow: 0 20px 45px rgba(233, 30, 99, 0.3); }
        button:disabled { background: #eee; color: #ccc; box-shadow: none; transform: none; }
        
        .register-link { margin-top: 3.5rem; font-size: 0.85rem; color: #bbb; font-weight: 600; }
        .register-link a { color: var(--secondary); text-decoration: none; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; margin-left: 8px; }
        .register-link a:hover { color: var(--primary); }

        .blob { position: absolute; width: 300px; height: 300px; background: var(--primary); opacity: 0.03; filter: blur(80px); border-radius: 50%; z-index: 1; }
        .blob-1 { top: -150px; right: -150px; }
        .blob-2 { bottom: -150px; left: -150px; }

        /* Password field with eye icon toggle */
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
            background: none;
            border: none;
            padding: 0;
            margin: 0;
            width: auto;
            box-shadow: none;
        }
        .toggle-password:hover { color: var(--primary); background: none; transform: translateY(-50%); box-shadow: none; }
        .toggle-password svg { width: 20px; height: 20px; display: block; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        
        <div class="brand-name">BLOOM</div>
        <h2 class="brand-font">Authenticated Access</h2>
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

        // Ensure authDomain is set for web login
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

            loginForm.onsubmit = async (e) => {
                e.preventDefault();
                const email = document.getElementById('email').value.trim();
                const password = document.getElementById('password').value;
                const btn = document.getElementById('login-btn');

                btn.disabled = true;
                btn.innerText = 'Logging in...';
                errorBox.style.display = 'none';

                try {
                    const userCredential = await auth.signInWithEmailAndPassword(email, password);
                    const user = userCredential.user;

                    // Check role in Firestore
                    let userDoc = await db.collection('users').doc(user.uid).get();
                    let role = 'customer';
                    let username = user.email.split('@')[0];

                    if (userDoc.exists) {
                        const userData = userDoc.data();
                        role = userData.role || 'customer';
                        username = userData.username || username;
                        const branchId = userData.branchId || 'main_branch';

                        // DEBUG: verify which document was found and what role it holds
                        console.log('[LOGIN DEBUG] Found in "users" collection', {
                            uid: user.uid,
                            docId: userDoc.id,
                            rawRole: userData.role,
                            resolvedRole: role,
                            fullData: userData
                        });
                        
                        // Set current branch to user's assigned branch if it exists
                        localStorage.setItem('bloom_branch_id', branchId);
                    } else {
                        // Check in 'customers' collection (Flutter App)
                        userDoc = await db.collection('customers').doc(user.uid).get();
                        if (userDoc.exists) {
                            const userData = userDoc.data();
                            role = 'customer';
                            username = userData.name || userData.username || username;

                            // DEBUG: this means no matching doc was found in "users",
                            // so it fell through to "customers" and forced role = 'customer'
                            console.log('[LOGIN DEBUG] NOT found in "users" collection, fell through to "customers"', {
                                uid: user.uid,
                                docId: userDoc.id,
                                fullData: userData
                            });
                        } else {
                            // DEBUG: no matching doc in either collection at all
                            console.log('[LOGIN DEBUG] No matching document in "users" OR "customers" for uid:', user.uid, '- defaulting role to "customer"');
                        }
                    }

                    // Send to PHP to set session
                    const formData = new FormData();
                    formData.append('uid', user.uid);
                    formData.append('email', user.email);
                    formData.append('role', role);
                    formData.append('username', username);
                    formData.append('branchId', userDoc.exists ? (userDoc.data().branchId || 'main_branch') : 'main_branch');

                    const response = await fetch('includes/set_session.php', {
                        method: 'POST',
                        body: formData
                    });

                    if (response.ok) {
                        // DEBUG: final role used to decide the redirect
                        console.log('[LOGIN DEBUG] Final role before redirect:', role);

                        if (role === 'admin' || role === 'super-admin' || role === 'staff' || role === 'employee') {
                            window.location.href = 'admin.php';
                        } else if (role === 'delivery') {
                            window.location.href = 'delivery_status.php';
                        } else {
                            window.location.href = 'templates/shop.php';
                        }
                    } else {
                        throw new Error('Failed to establish session.');
                    }
                } catch (error) {
                    errorBox.innerText = error.message;
                    errorBox.style.display = 'block';
                    btn.disabled = false;
                    btn.innerText = 'Login';
                }
            };

            // Show/hide password toggle
            const togglePasswordBtn = document.getElementById('toggle-password');
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');

            const eyeOpenPath = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            const eyeClosedPath = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';

            togglePasswordBtn.addEventListener('click', () => {
                const isPassword = passwordInput.getAttribute('type') === 'password';
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                eyeIcon.innerHTML = isPassword ? eyeClosedPath : eyeOpenPath;
                togglePasswordBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        }
    </script>
</body>
</html>