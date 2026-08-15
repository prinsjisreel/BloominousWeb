<?php
session_start();
include 'includes/db_connect.php';

// Security check
if (!isset($_SESSION['user_id'])) { die("Unauthorized."); }

if (isset($_GET['id'])) {
    $id = mysqli_real_escape_string($conn, $_GET['id']);

    // Burahin muna ang image sa folder (optional but recommended)
    $res = mysqli_query($conn, "SELECT image FROM products WHERE id = '$id'");
    $row = mysqli_fetch_assoc($res);
    if ($row && !empty($row['image'])) { @unlink("uploads/" . $row['image']); }

    // DELETE QUERY
    $sql = "DELETE FROM products WHERE id = '$id'";
    
    if (mysqli_query($conn, $sql)) {
        header("Location: product_management.php?msg=deleted");
        exit();
    } else {
        echo "Error: " . mysqli_error($conn);
    }
} else {
    header("Location: product_management.php");
}
?>