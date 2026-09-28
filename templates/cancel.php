<?php
/**
 * BLOOMINOUS - Payment Cancel Handler
 *
 * PayMongo sends the customer here if they back out of the GCash/Maya page.
 * The order already exists (paymentStatus 'Pending'), so "Try Again" goes to
 * checkout.php?resume=1, which offers "Continue Payment" for THAT order
 * instead of creating a duplicate one. BloominousApp customers (who land
 * here in the phone browser, signed out) retry from the app instead.
 */
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled | Bloominous</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #fcf9f2; }
        .cancel-card { background: #fff; border-radius: 30px; padding: 60px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.05); max-width: 500px; width: 100%; }
        .x-icon { width: 80px; height: 80px; background: #ffebee; color: #e74c3c; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 30px; }
        .btn-retry { display: inline-block; background: #333; color: #fff; padding: 15px 40px; border-radius: 50px; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; margin-top: 30px; transition: 0.3s; }
        .btn-retry:hover { background: #000; transform: scale(1.05); }
        .btn-secondary { display: inline-block; color: #999; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 2px; margin-top: 20px; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

<div class="cancel-card">
    <div class="x-icon">
        <i class="fa-solid fa-xmark"></i>
    </div>
    <h1 class="text-3xl font-black text-gray-800 uppercase tracking-tight mb-4">Payment Cancelled</h1>
    <p class="text-gray-400 mb-8">The payment was not completed. No charges were made.</p>
    
    <div class="bg-gray-50 p-6 rounded-2xl text-left mb-8">
        <p class="text-xs text-gray-500 text-center">
            Your order is saved and waiting for payment. Tap <strong>Try Again</strong> to pay with GCash or Maya — you won't need to re-enter your details.
        </p>
    </div>

    <a href="checkout.php?resume=1" class="btn-retry">Try Again</a>
    <div><a href="my_orders.php" class="btn-secondary">Go to My Orders</a></div>

    <!-- BloominousApp customers land here in the phone's browser, which has
         no web login — the buttons above would send them to the login page.
         Their retry lives inside the app instead. -->
    <p class="text-[11px] text-gray-400 font-semibold mt-8">
        <i class="fa-solid fa-mobile-screen-button mr-1"></i>
        Using the Bloominous app? Just switch back to it and tap <strong>Continue Payment</strong>.
    </p>
</div>

</body>
</html>