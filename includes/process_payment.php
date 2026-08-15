<?php
require_once __DIR__ . '/payment_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = $_POST['order_id'] ?? null;
    $amount = $_POST['amount'] ?? 0;
    $email = $_POST['email'] ?? '';
    $name = $_POST['name'] ?? 'Customer';
    $description = "Order #" . $orderId . " at BLOOM";

    if (!$orderId || $amount <= 0) {
        die("Invalid order request.");
    }

    try {
        $checkoutUrl = PaymentHelper::createCheckoutSession($amount, $description, $name, $email);
        
        // Save order ID in session so we can update it on success
        $_SESSION['pending_order_id'] = $orderId;
        
        header("Location: " . $checkoutUrl);
        exit();
    } catch (Exception $e) {
        echo "Error creating payment session: " . $e->getMessage();
        echo "<br><a href='../templates/checkout.php'>Go back</a>";
    }
} else {
    header("Location: ../templates/shop.php");
}
