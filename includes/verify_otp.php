<?php
session_start();
include('db_connect.php'); 

if (!isset($_SESSION['temp_email'])) {
    header("Location: ../register.php");
    exit();
}

if (isset($_POST['verify'])) {
    $otp_input = $_POST['otp'];
    $email = $_SESSION['temp_email'];
    $current_time = date("Y-m-d H:i:s");

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND otp_code = ?");
    $stmt->bind_param("ss", $email, $otp_input);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if ($current_time > $user['otp_expiry']) {
            $error = "OTP has expired! Please register again.";
        } else {
            $update = $conn->prepare("UPDATE users SET is_verified = 1, otp_code = NULL, otp_expiry = NULL WHERE email = ?");
            $update->bind_param("s", $email);
            if ($update->execute()) {
                unset($_SESSION['temp_email']);
                echo "<script>alert('Account verified successfully!'); window.location='../index.php';</script>";
            }
        }
    } else {
        $error = "Invalid OTP Code. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - BLOOM</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f3e5f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .container { background: #fff; padding: 40px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; }
        h2 { color: #6a1b9a; margin-bottom: 10px; }
        p { color: #555; font-size: 14px; margin-bottom: 20px; }
        .email-info { font-weight: 600; color: #6a1b9a; }
        input { width: 100%; padding: 15px; margin: 10px 0; border: 2px solid #eee; border-radius: 10px; box-sizing: border-box; text-align: center; font-size: 20px; letter-spacing: 5px; outline: none; }
        input:focus { border-color: #6a1b9a; }
        button { width: 100%; padding: 12px; background: #6a1b9a; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; transition: 0.3s; }
        button:hover { background: #4a148c; }
        .error-msg { color: #e53935; font-size: 13px; margin-bottom: 10px; }
        .resend { margin-top: 20px; font-size: 13px; color: #777; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Verify OTP</h2>
        <p>We've sent a 6-digit code to <br><span class="email-info"><?php echo $_SESSION['temp_email']; ?></span></p>
        
        <?php if(isset($error)) { echo "<div class='error-msg'>$error</div>"; } ?>

        <form method="POST" action="">
            <input type="text" name="otp" placeholder="000000" maxlength="6" required autofocus>
            <button type="submit" name="verify">Verify Account</button>
        </form>

        <div class="resend">
            Didn't get the code? <a href="../register.php" style="color: #6a1b9a; text-decoration: none;">Try again</a>
        </div>
    </div>
</body>
</html>