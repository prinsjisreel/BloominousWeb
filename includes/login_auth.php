<?php
session_start();
include 'db_connect.php';

// Opsyonal: I-set ang timezone sa Pinas para sigurado kung gagamit ka ng PHP date()
date_default_timezone_set('Asia/Manila'); 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Linisin ang input
    $user_input = mysqli_real_escape_string($conn, $_POST['user_input']);
    $pass = $_POST['password'];

    // Hanapin ang user gamit ang bagong columns: firstname at lastname
    $stmt = $conn->prepare("SELECT id, password, role, firstname, lastname FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $user_input);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        
        // Password verification (Plain text gaya ng nasa database mo)
        if ($pass === $row['password']) {
            
            // --- BAGONG CODE: I-update ang last_login column sa database ---
            $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update_stmt->bind_param("i", $row['id']);
            $update_stmt->execute();
            // ----------------------------------------------------------------

            if ($row['role'] === 'admin') {
                $_SESSION['admin_id'] = $row['id'];
                $_SESSION['admin_name'] = $row['firstname']; // Ginagamit na ang firstname
                header("Location: ../admin.php");
                exit();
            } else {
                // Para sa Customer/Shop
                $_SESSION['customer_id'] = $row['id'];
                $_SESSION['firstname'] = $row['firstname']; // Ito ang tatawagin sa "Hi, [Name]"
                $_SESSION['lastname'] = $row['lastname'];
                $_SESSION['role'] = $row['role'];
                
                header("Location: ../templates/shop.php");
                exit();
            }
        }
    }
    
    // Redirect pabalik sa index.php (Login) kung mali ang credentials
    header("Location: ../index.php?error=invalid");
    exit();
}
?>