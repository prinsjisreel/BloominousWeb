<?php
/**
 * BLOOMINOUS - Payment Success Handler
 */
session_start();

// Security Check
$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;

if (!$user_id) {
    header("Location: ../index.php");
    exit();
}

$order_id = $_SESSION['pending_order_id'] ?? $_GET['order_id'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Success | Bloominous</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Firebase SDK -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>

    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #fcfaff; }
        .success-card { background: #fff; border-radius: 35px; padding: 60px; text-align: center; box-shadow: 0 30px 60px rgba(123, 121, 242, 0.1); max-width: 550px; width: 100%; border: 1px solid #f0f2f5; }
        .check-icon { width: 90px; height: 90px; background: #eef2ff; color: #7B79F2; border-radius: 30px; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 35px; transform: rotate(-10deg); }
        .btn-track { display: inline-block; background: #7B79F2; color: #fff; padding: 18px 45px; border-radius: 20px; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; margin-top: 30px; transition: 0.3s; box-shadow: 0 15px 30px rgba(123, 121, 242, 0.2); }
        .btn-track:hover { background: #5a58d1; transform: translateY(-5px); box-shadow: 0 20px 40px rgba(123, 121, 242, 0.3); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

<div class="success-card">
    <div class="check-icon">
        <i class="fa-solid fa-check"></i>
    </div>
    <h1 class="text-3xl font-black text-[#363949] uppercase tracking-tight mb-4">Payment Successful!</h1>
    <p class="text-[#7d8da1] mb-8 font-medium">Thank you for your purchase. Your order <span class="text-[#7B79F2] font-bold">#<?php echo htmlspecialchars($order_id); ?></span> has been placed successfully and is now being processed.</p>
    
    <div class="bg-[#f8faff] p-8 rounded-3xl text-left mb-8 border border-gray-50">
        <h4 class="text-[10px] font-black uppercase tracking-[2px] text-[#7d8da1] mb-6">Payment and Delivery Status</h4>
        <div class="flex gap-5 mb-6">
            <div class="w-10 h-10 bg-white rounded-2xl flex items-center justify-center text-sm font-black text-[#7B79F2] shadow-sm border border-gray-100 italic">01</div>
            <div>
                <p class="text-xs font-black text-[#363949] uppercase tracking-tight mb-1">Payment Confirmed</p>
                <p class="text-[11px] text-[#7d8da1] font-bold leading-relaxed">Your payment via PayMongo has been verified and applied to your order.</p>
            </div>
        </div>
        <div class="flex gap-5">
            <div class="w-10 h-10 bg-white rounded-2xl flex items-center justify-center text-sm font-black text-[#7B79F2] shadow-sm border border-gray-100 italic">02</div>
            <div>
                <p class="text-xs font-black text-[#363949] uppercase tracking-tight mb-1">Order Preparation</p>
                <p class="text-[11px] text-[#7d8da1] font-bold leading-relaxed">Our florists are now preparing your arrangement for delivery.</p>
            </div>
        </div>
    </div>

    <a href="../track_order.php?id=<?php echo htmlspecialchars($order_id); ?>" class="btn-track">Track My Order</a>
</div>

<script>
    <?php
        $configPath = __DIR__ . '/../firebase-applet-config.json';
        if (file_exists($configPath)) {
            $firebaseConfigJson = file_get_contents($configPath);
            echo "const firebaseConfig = " . $firebaseConfigJson . ";";
        } else {
            echo "const firebaseConfig = {};";
        }
    ?>
    
    if (firebaseConfig.apiKey && "<?php echo $order_id; ?>") {
        firebase.initializeApp(firebaseConfig);
        const db = firebase.firestore();
        const orderId = "<?php echo $order_id; ?>";

        // Update Order Status in background
        db.collection('orders').doc(orderId).update({
            payment_status: 'paid',
            status: 'processing',
            paidAt: firebase.firestore.FieldValue.serverTimestamp()
        }).then(() => {
            console.log("Order status updated successfully.");
        }).catch(err => {
            console.error("Error updating order status:", err);
        });
    }
</script>

</body>
</html>
