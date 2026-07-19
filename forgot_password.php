<?php session_start(); // If already logged in, redirect if (isset($_SESSION['admin_id'])) {     header("Location: admin.php");     exit(); } ?> 
<!DOCTYPE html> 
<html lang="en"> 
<head>     
    <meta charset="UTF-8">     
    <meta name="viewport" content="width=device-width, initial-scale=1.0">     
    <title>Reset Password - BLOOM</title>     
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=Cormorant+Garamond:wght@700;900&display=swap" rel="stylesheet">     
    <!-- Firebase SDK -->     
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>     
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>     
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>     
    <style>         
        :root {             
            --primary: #F59E0B;             
            --secondary: #121212;             
            --background: #FFFDF7;             
            --dark: #121212;             
            --text-main: #363949;         
        }         
        body { font-family: 'Inter', sans-serif; background: var(--background); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; color: var(--text-main); }         
        .login-container { background: #ffffff; padding: 4rem; border-radius: 40px; box-shadow: 0 40px 100px rgba(245,158,11,0.06); width: 100%; max-width: 480px; text-align: center; border: 1px solid rgba(245,158,11,0.03); margin: 2rem; position: relative; overflow: hidden; z-index: 10; }                           
        .brand-name { font-family: 'Cormorant Garamond', serif; font-size: 3rem; font-weight: 900; letter-spacing: 6px; color: var(--primary); margin-bottom: 2rem; position: relative; z-index: 2; }                           
        h2 { font-family: 'Cormorant Garamond', serif; color: var(--dark); font-size: 2.5rem; margin-bottom: 0.5rem; font-weight: 900; line-height: 1.1; }         
        p.subtitle { color: #aaa; font-size: 0.9rem; margin-bottom: 3rem; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; }                           
        .status-msg { padding: 1.2rem; border-radius: 20px; font-size: 0.8rem; margin-bottom: 2.5rem; text-align: center; font-weight: 800; letter-spacing: 0.5px; display: none; }         
        .error-layout { background: #fff5f8; color: #E91E63; border: 1px solid rgba(233,30,99,0.1); }         
        .success-layout { background: #f0fdf4; color: #15803d; border: 1px solid rgba(21,128,61,0.1); }                           
        
        input[type="email"], input[type="password"], input[type="text"] { 
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
        input[type="email"]:focus, input[type="password"]:focus, input[type="text"]:focus { border-color: var(--primary); background: #fff; box-shadow: 0 10px 25px rgba(245, 158, 11, 0.05); }                           
        
        .otp-container { display: flex; justify-content: space-between; gap: 10px; margin: 1.5rem 0; }         
        .otp-input { width: 50px; height: 55px; border: 1px solid #f0f0f0; border-radius: 14px; background: #fafafa; text-align: center; font-size: 1.25rem; font-weight: 800; color: var(--text-main); outline: none; transition: 0.3s ease; box-sizing: border-box; }         
        .otp-input:focus { border-color: var(--primary); background: #fff; box-shadow: 0 8px 20px rgba(245, 158, 11, 0.08); }                           
        
        button { width: 100%; padding: 1.2rem; background: var(--primary); color: white; border: none; border-radius: 20px; cursor: pointer; font-size: 0.9rem; font-weight: 900; transition: 0.4s; margin-top: 2rem; box-shadow: 0 15px 35px rgba(245, 158, 11, 0.2); text-transform: uppercase; letter-spacing: 3px; }         
        button:hover { background: #d97706; transform: translateY(-5px); box-shadow: 0 20px 45px rgba(217, 119, 6, 0.3); }         
        button:disabled { background: #eee; color: #ccc; box-shadow: none; transform: none; }                           
        
        .back-link { margin-top: 3.5rem; font-size: 0.85rem; color: #bbb; font-weight: 600; }         
        .back-link a { color: var(--secondary); text-decoration: none; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; margin-left: 8px; transition: color 0.4s ease, opacity 0.4s ease; }         
        .back-link a:hover { color: var(--primary); opacity: 0.8; }         
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
        .toggle-password:hover { color: var(--primary) !important; transform: translateY(-50%) scale(1.1) !important; }         
        .toggle-password svg { width: 20px; height: 20px; display: block; }     
    </style> 
</head> 
<body>     
    <div class="login-container">         
        <div class="blob blob-1"></div>         
        <div class="blob blob-2"></div>                           
        <div class="brand-name">BLOOM</div>         
        <h2 class="brand-font">Account Recovery</h2>         
        <p class="subtitle" id="form-subtitle">Reset your clearance key</p>         
        <div id="status-box" class="status-msg"></div>         
        
        <form id="email-request-form">             
            <input type="email" id="recovery-email" placeholder="Email Address" required autofocus>             
            <button type="submit" id="request-btn">Send Recovery Code</button>         
        </form>         
        
        <form id="otp-verify-form" style="display: none;">             
            <div class="otp-container">                 
                <input type="text" class="otp-input" maxlength="1" pattern="\d" required>                 
                <input type="text" class="otp-input" maxlength="1" pattern="\d" required>                 
                <input type="text" class="otp-input" maxlength="1" pattern="\d" required>                 
                <input type="text" class="otp-input" maxlength="1" pattern="\d" required>                 
                <input type="text" class="otp-input" maxlength="1" pattern="\d" required>                 
                <input type="text" class="otp-input" maxlength="1" pattern="\d" required>             
            </div>             
            <button type="submit" id="verify-btn">Verify Security Token</button>         
        </form>         
        
        <form id="password-reset-form" style="display: none;">             
            <div class="password-wrapper">                 
                <input type="password" id="new-password" placeholder="New Password" required>                 
                <button type="button" class="toggle-password" data-target="new-password" aria-label="Show password" tabindex="-1">                     
                    <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">                         
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>                         
                        <circle cx="12" cy="12" r="3"></circle>                     
                    </svg>                 
                </button>             
            </div>             
            <div class="password-wrapper">                 
                <input type="password" id="confirm-password" placeholder="Confirm New Password" required>                 
                <button type="button" class="toggle-password" data-target="confirm-password" aria-label="Show password" tabindex="-1">                     
                    <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">                         
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>                         
                        <circle cx="12" cy="12" r="3"></circle>                     
                    </svg>                 
                </button>             
            </div>             
            <button type="submit" id="reset-btn">Update Password</button>         
        </form>         
        <div class="back-link">             
            Remember credentials? <a href="index.php">Return to Sign In</a>         
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
            console.error("Firebase Configuration payload lost!");         
        } else {             
            firebase.initializeApp(firebaseConfig);             
            const db = firebase.firestore();             
            const auth = firebase.auth();
            const emailForm = document.getElementById('email-request-form');             
            const otpForm = document.getElementById('otp-verify-form');             
            const passwordForm = document.getElementById('password-reset-form');             
            const statusBox = document.getElementById('status-box');             
            const subtitleText = document.getElementById('form-subtitle');             
            let generatedOtpCode = '';             
            let targetUserEmail = '';             
            
            emailForm.onsubmit = async (e) => {                 
                e.preventDefault();                 
                const emailInput = document.getElementById('recovery-email').value.trim().toLowerCase();                 
                const requestBtn = document.getElementById('request-btn');                 
                requestBtn.disabled = true;                 
                requestBtn.innerText = 'Routing telemetry...';                 
                statusBox.style.display = 'none';                 
                statusBox.className = 'status-msg';                 
                try {                     
                    const userCheck = await db.collection('users').where('email', '==', emailInput).get();                     
                    const custCheck = await db.collection('customers').where('email', '==', emailInput).get();                     
                    if (userCheck.empty && custCheck.empty && emailInput !== '789jojoalvarado@gmail.com') {                         
                        throw new Error("No active registry matches this account endpoint.");                     
                    }                     
                    targetUserEmail = emailInput;                     
                    generatedOtpCode = Math.floor(100000 + Math.random() * 900000).toString();                     
                    await db.collection('customer_otps').doc(targetUserEmail).set({                         
                        code: generatedOtpCode,                         
                        timestamp: firebase.firestore.FieldValue.serverTimestamp()                     
                    });                     
                    
                    const mailData = new FormData();                     
                    mailData.append('email', targetUserEmail);                     
                    mailData.append('code', generatedOtpCode);                     
                    const emailResponse = await fetch('send_recovery_email.php', {                         
                        method: 'POST',                         
                        body: mailData                     
                    });                     
                    const mailResult = await emailResponse.json();                     
                    if (!mailResult.success) {                         
                        throw new Error(mailResult.message);                     
                    }                     
                    emailForm.style.display = 'none';                     
                    otpForm.style.display = 'block';                     
                    subtitleText.innerText = 'Enter the 6-digit confirmation key';                                          
                    statusBox.innerText = "Security token successfully sent to your Gmail inbox.";                     
                    statusBox.className = 'status-msg success-layout';                     
                    statusBox.style.display = 'block';                                          
                    document.querySelector('.otp-input').focus();                 
                } catch (error) {                     
                    statusBox.innerText = "Error: " + error.message;                     
                    statusBox.className = 'status-msg error-layout';                     
                    statusBox.style.display = 'block';                     
                    requestBtn.disabled = false;                     
                    requestBtn.innerText = 'Send Recovery Code';                 
                }             
            };             
            const otpInputs = document.querySelectorAll('.otp-input');             
            otpInputs.forEach((input, index) => {                 
                input.addEventListener('input', (e) => {                     
                    if (input.value && index < otpInputs.length - 1) {                         
                        otpInputs[index + 1].focus();                     
                    }                 
                });                 
                input.addEventListener('keydown', (e) => {                     
                    if (e.key === 'Backspace' && !input.value && index > 0) {                         
                        otpInputs[index - 1].focus();                     
                    }                 
                });             
            });             
            otpForm.onsubmit = async (e) => {                 
                e.preventDefault();                 
                const verifyBtn = document.getElementById('verify-btn');                 
                verifyBtn.disabled = true;                 
                verifyBtn.innerText = 'Validating authenticity...';                 
                statusBox.style.display = 'none';                 
                statusBox.className = 'status-msg';                 
                let enteredCode = '';                 
                otpInputs.forEach(input => enteredCode += input.value);                 
                try {                     
                    const otpSnapshot = await db.collection('customer_otps').doc(targetUserEmail).get();                                          
                    if (!otpSnapshot.exists || otpSnapshot.data().code !== enteredCode) {                         
                        throw new Error("Invalid confirmation key token provided. Processing aborted.");                     
                    }                     
                    await db.collection('customer_otps').doc(targetUserEmail).delete();                     
                    otpForm.style.display = 'none';                     
                    passwordForm.style.display = 'block';                     
                    subtitleText.innerText = 'Configure your new security credentials';                     
                    statusBox.innerText = "Identity Authenticated safely. Please type your new credentials.";                     
                    statusBox.className = 'status-msg success-layout';                     
                    statusBox.style.display = 'block';                     
                    document.getElementById('new-password').focus();                 
                } catch (error) {                     
                    statusBox.innerText = error.message;                     
                    statusBox.className = 'status-msg error-layout';                     
                    statusBox.style.display = 'block';                     
                    verifyBtn.disabled = false;                     
                    verifyBtn.innerText = 'Verify Security Token';                                          
                    otpInputs.forEach(input => input.value = '');                     
                    otpInputs[0].focus();                 
                }             
            };             
            passwordForm.onsubmit = async (e) => {                 
                e.preventDefault();                 
                const newPassword = document.getElementById('new-password').value;                 
                const confirmPassword = document.getElementById('confirm-password').value;                 
                const resetBtn = document.getElementById('reset-btn');                 
                statusBox.style.display = 'none';                 
                statusBox.className = 'status-msg';                 
                if (newPassword !== confirmPassword) {                     
                    statusBox.innerText = "Password mismatch configuration detected. Please match fields.";                     
                    statusBox.className = 'status-msg error-layout';                     
                    statusBox.style.display = 'block';                     
                    return;                 
                }                 
                if (newPassword.length < 6) {                     
                    statusBox.innerText = "Security mismatch: Password must contain at least 6 characters.";                     
                    statusBox.className = 'status-msg error-layout';                     
                    statusBox.style.display = 'block';                     
                    return;                 
                }                 
                resetBtn.disabled = true;                 
                resetBtn.innerText = 'Updating credentials...';                 
                try {                     
                    // 1. Core Native Update: Sends a native verification token link directly into the vault[cite: 7]
                    await auth.sendPasswordResetEmail(targetUserEmail);

                    // 2. Firestore Sync Layer Update[cite: 7]
                    const batch = db.batch();                     
                    const usersRef = await db.collection('users').where('email', '==', targetUserEmail).get();                     
                    const custRefByEmail = await db.collection('customers').where('email', '==', targetUserEmail).get();                     
                    const custRefByCustEmail = await db.collection('customers').where('custEmail', '==', targetUserEmail).get();                     
                    
                    usersRef.forEach(doc => { batch.update(doc.ref, { password: newPassword }); });                     
                    custRefByEmail.forEach(doc => { batch.update(doc.ref, { password: newPassword }); });                     
                    custRefByCustEmail.forEach(doc => { batch.update(doc.ref, { password: newPassword }); });                     
                    
                    await batch.commit();                     
                    statusBox.innerText = "Password sync mapping finalized successfully! An additional configuration verification hyperlink has been sent to your Gmail address to verify your vault changes safely.";                     
                    statusBox.className = 'status-msg success-layout';                     
                    statusBox.style.display = 'block';                     
                    setTimeout(() => {                         
                        window.location.href = 'index.php';                     
                    }, 5000);                 
                } catch (error) {                     
                    statusBox.innerText = "Update rejected: " + error.message;                     
                    statusBox.className = 'status-msg error-layout';                     
                    statusBox.style.display = 'block';                     
                    resetBtn.disabled = false;                     
                    resetBtn.innerText = 'Update Password';                 
                }             
            };             
            const eyeOpenPath = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';             
            const eyeClosedPath = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';                          
            document.querySelectorAll('.toggle-password').forEach(btn => {                 
                btn.addEventListener('click', (e) => {                     
                    e.preventDefault();                     
                    e.stopPropagation();                     
                    const targetId = btn.getAttribute('data-target');                     
                    const inputField = document.getElementById(targetId);                     
                    const eyeIcon = btn.querySelector('.eye-icon');                                          
                    const isPassword = inputField.getAttribute('type') === 'password';                     
                    inputField.setAttribute('type', isPassword ? 'text' : 'password');                     
                    eyeIcon.innerHTML = isPassword ? eyeClosedPath : eyeOpenPath;                 
                });             
            });         
        }     
    </script> 
</body> 
</html>