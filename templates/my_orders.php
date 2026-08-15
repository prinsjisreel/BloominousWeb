<?php
/**
 * BLOOMINOUS - Customer Orders (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;

if (!$user_id) {
    header("Location: ../index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders | BloomShop</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Firebase SDK -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>
    
    <style>
        :root {
            --primary: #7B79F2;
            --primary-light: #eef2ff;
            --text-main: #363949;
            --text-muted: #7d8da1;
            --white: #ffffff;
            --bg: #fcfaff;
        }

        body { 
            font-family: 'Poppins', sans-serif; 
            background-color: var(--bg); 
            color: var(--text-main);
            margin: 0;
        }

        .glass-nav {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
        }

        .orders-container { 
            max-width: 900px; 
            margin: 0 auto; 
            padding: 60px 20px; 
        }

        .order-card { 
            background: var(--white); 
            border-radius: 30px; 
            padding: 30px; 
            margin-bottom: 25px; 
            box-shadow: 0 15px 35px rgba(123, 121, 242, 0.05); 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border: 1px solid #f0f2f5;
            transition: 0.3s;
        }
        .order-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 45px rgba(123, 121, 242, 0.1);
        }

        .status-badge { 
            padding: 8px 16px; 
            border-radius: 50px; 
            font-size: 0.7rem; 
            font-weight: 800; 
            text-transform: uppercase; 
            letter-spacing: 0.5px;
            display: inline-block;
        }
        
        .status-pending { background: #fff9e6; color: #ffbb55; }
        .status-paid { background: #e6fff6; color: #41f1b6; }
        .status-processing { background: #eef2ff; color: #7B79F2; }
        .status-shipped { background: #eef2ff; color: var(--primary); }
        .status-delivered { background: #e6fff6; color: #41f1b6; }
        .status-cancelled { background: #fff0f0; color: #ff5b5b; }

        .btn-track {
            font-size: 0.75rem;
            font-weight: 800;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .btn-track:hover {
            color: #5a58d1;
            gap: 10px;
        }

        .empty-state {
            text-align: center;
            padding: 80px 40px;
            background: var(--white);
            border-radius: 40px;
            box-shadow: 0 20px 50px rgba(123, 121, 242, 0.05);
        }
    </style>
</head>
<body>

<nav class="glass-nav py-5 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-6 flex justify-between items-center">
        <div class="flex items-center gap-2">
            <div style="height: 32px; display: flex; align-items: center; justify-content: center;">
                <img src="../assets/images/asset.jpg" alt="BLOOM" style="max-height: 100%; max-width: 100%; object-fit: contain;">
            </div>
            <h1 class="text-xl font-black italic tracking-tighter text-[#363949] hidden sm:block">BLOOM</h1>
        </div>
        <div class="flex items-center gap-8 text-[11px] font-extrabold uppercase tracking-[2px] text-gray-400">
            <a href="shop.php" class="hover:text-[#7B79F2] transition-colors">Shop</a>
            <a href="my_orders.php" class="text-[#7B79F2]">My Orders</a>
            <a href="../logout.php" class="text-red-400 hover:text-red-600 transition-colors">Logout</a>
        </div>
    </div>
</nav>

<div class="orders-container">
    <div class="mb-12">
        <h2 class="text-4xl font-black text-[#363949] uppercase tracking-tighter">Order History</h2>
        <p class="text-[#7d8da1] font-medium mt-2">Track and manage your floral purchases</p>
    </div>

    <div id="orders-list">
        <div class="text-center py-32">
            <i class="fa-solid fa-spinner fa-spin fa-3x text-[#7B79F2] mb-6"></i>
            <p class="text-[#7d8da1] font-bold uppercase tracking-widest text-xs">Fetching your orders...</p>
        </div>
    </div>
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
    
    if (firebaseConfig.apiKey) {
        firebase.initializeApp(firebaseConfig);
        const db = firebase.firestore();
        const userId = "<?php echo $user_id; ?>";

        document.addEventListener('DOMContentLoaded', () => {
            const ordersList = document.getElementById('orders-list');

            // Removed .orderBy() to avoid index requirements
            db.collection('orders')
              .where('user_id', '==', userId)
              .onSnapshot(snap => {
                if (snap.empty) {
                    ordersList.innerHTML = `
                        <div class="empty-state">
                            <div class="w-24 h-24 bg-[#eef2ff] rounded-full flex items-center justify-center text-[#7B79F2] mx-auto mb-8">
                                <i class="fa-solid fa-box-open fa-3x"></i>
                            </div>
                            <h3 class="text-2xl font-black text-[#363949] mb-4">No Orders Yet</h3>
                            <p class="text-[#7d8da1] font-medium mb-10">You haven't placed any orders with us. <br>Ready to brighten someone's day?</p>
                            <a href="shop.php" class="px-10 py-4 bg-[#7B79F2] text-white rounded-full font-bold uppercase tracking-widest hover:bg-[#5a58d1] transition-all shadow-lg">
                                Start Shopping
                            </a>
                        </div>
                    `;
                    return;
                }

                let orders = [];
                snap.forEach(doc => {
                    orders.push({ id: doc.id, ...doc.data() });
                });

                // Client-side sort by timestamp descending
                orders.sort((a, b) => {
                    const timeA = a.timestamp ? a.timestamp.toMillis() : 0;
                    const timeB = b.timestamp ? b.timestamp.toMillis() : 0;
                    return timeB - timeA;
                });

                let html = '';
                orders.forEach(o => {
                    const id = o.id;
                    const status = o.status || 'Pending';
                    const statusClass = status.toLowerCase();
                    const date = o.timestamp ? o.timestamp.toDate().toLocaleDateString('en-US', { 
                        month: 'short', 
                        day: 'numeric', 
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    }) : 'Just now';
                    const price = parseFloat(o.total_price || 0).toLocaleString(undefined, {minimumFractionDigits: 2});

                    html += `
                    <div class="order-card">
                        <div>
                            <div class="flex items-center gap-3 mb-2">
                                <span class="status-badge status-${statusClass}">${status}</span>
                            </div>
                            <h3 class="font-black text-[#363949] text-2xl mb-1">₱${price}</h3>
                            <p class="text-[11px] font-bold text-[#7d8da1] uppercase tracking-widest">${date}</p>
                        </div>
                        <div class="text-right flex flex-col gap-3">
                            <a href="../track_order.php?id=${id}" class="btn-track">
                                Track Order <i class="fa-solid fa-arrow-right"></i>
                            </a>
                            ${status.toLowerCase() === 'pending' ? `
                                <button onclick="cancelOrder('${id}')" class="text-[10px] font-black uppercase tracking-widest text-red-400 hover:text-red-600 transition-colors">
                                    <i class="fa-solid fa-xmark mr-1"></i> Cancel Order
                                </button>
                            ` : ''}
                        </div>
                    </div>
                    `;
                });
                ordersList.innerHTML = html;
            }, error => {
                console.error("Error fetching orders:", error);
                ordersList.innerHTML = `<p class="text-center text-red-500 font-bold">Error loading orders: ${error.message}</p>`;
            });
        });

        window.cancelOrder = async function(orderId) {
            if (!confirm('Are you sure you want to cancel this order? This action cannot be undone.')) return;

            try {
                await db.collection('orders').doc(orderId).update({
                    status: 'cancelled',
                    cancelledAt: firebase.firestore.FieldValue.serverTimestamp()
                });
                alert('Order cancelled successfully.');
            } catch (error) {
                console.error("Error cancelling order:", error);
                alert('Failed to cancel order: ' + error.message);
            }
        };
    }
</script>

</body>
</html>
