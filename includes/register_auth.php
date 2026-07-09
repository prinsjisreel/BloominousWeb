<?php
// includes/register_auth.php
include 'db_connect.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Pagkuha ng inputs mula sa form
    $firstname = mysqli_real_escape_string($conn, $_POST['firstname']);
    $lastname = mysqli_real_escape_string($conn, $_POST['lastname']);
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = mysqli_real_escape_string($conn, $_POST['password']);

    // Check kung existing na ang email/username
    $checkEmail = "SELECT * FROM users WHERE username = '$username'";
    $result = mysqli_query($conn, $checkEmail);

    if (mysqli_num_rows($result) > 0) {
        header("Location: ../register.php?error=taken");
        exit();
    } else {
        // FIX: Tinanggal ang 'fullname' sa listahan at ang 'NULL' sa VALUES
        // Dapat 5 columns (firstname, lastname, username, password, role) 
        // at 5 values lang ang nandoon.
        $query = "INSERT INTO users (firstname, lastname, username, password, role) 
                  VALUES ('$firstname', '$lastname', '$username', '$password', 'customer')";
        
        if (mysqli_query($conn, $query)) {
            header("Location: ../index.php?status=success");
            exit();
        } else {
            // Ito ang magpapakita kung may SQL error pa
            echo "Error: " . mysqli_error($conn);
        }
    }
}
?>