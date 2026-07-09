<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = $_POST['uid'] ?? '';
    $email = $_POST['email'] ?? '';
    $role = $_POST['role'] ?? 'customer';
    $username = $_POST['username'] ?? '';
    $branchId = $_POST['branchId'] ?? 'main_branch';

    if (!empty($uid)) {
        if ($role === 'admin' || $role === 'super-admin' || $role === 'staff' || $role === 'employee') {
            $_SESSION['admin_id'] = $uid; // Using admin_id for both to simplify access to management pages
            $_SESSION['admin_name'] = $username;
        } else {
            $_SESSION['user_id'] = $uid;
            $_SESSION['username'] = $username;
        }
        $_SESSION['role'] = $role;
        $_SESSION['email'] = $email;
        $_SESSION['branchId'] = $branchId;
        
        http_response_code(200);
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid UID']);
    }
} else {
    http_response_code(405);
}
?>
