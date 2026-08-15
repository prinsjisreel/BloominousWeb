<?php
/**
 * BLOOMINOUS - Payment Cancel Handler
 */
session_start();

$order_id = $_SESSION['pending_order_id'] ?? null;
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
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

<div class="cancel-card">
    <div class="x-icon">
        <i class="fa-solid fa-xmark"></i>
    </div>
    <h1 class="text-3xl font-black text-gray-800 uppercase tracking-tight mb-4">Payment Cancelled</h1>
    <p class="text-gray-400 mb-8">The payment process was cancelled. No charges were made.</p>
    
    <div class="bg-gray-50 p-6 rounded-2xl text-left mb-8">
        <p class="text-xs text-gray-500 text-center">If you encountered an error, you can try placing the order again or choose a different payment method.</p>
    </div>

    <a href="checkout.php" class="btn-retry">Try Again</a>
</div>

</body>
</html>
