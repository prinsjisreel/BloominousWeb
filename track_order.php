<?php
/**
 * BLOOMINOUS - Order Tracking (Firebase Spoke)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: templates/my_orders.php");
    exit();
}

$order_id = $_GET['id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Order | BloomShop</title>
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
        }

        .glass-nav {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
        }

        .track-container { 
            max-width: 800px; 
            margin: 0 auto; 
            padding: 60px 20px; 
        }

        .track-card { 
            background: var(--white); 
            border-radius: 40px; 
            padding: 50px; 
            box-shadow: 0 25px 70px rgba(123, 121, 242, 0.08); 
            border: 1px solid #f0f2f5;
        }

        .tracking-timeline { 
            position: relative; 
            padding-left: 60px; 
            margin-top: 50px; 
        }
        .tracking-timeline::before { 
            content: ''; 
            position: absolute; 
            left: 24px; 
            top: 0; 
            bottom: 0; 
            width: 4px; 
            background: #f0f2f5; 
            border-radius: 10px;
        }

        .timeline-item { 
            position: relative; 
            margin-bottom: 50px; 
            transition: 0.3s;
        }
        .timeline-item::before { 
            content: ''; 
            position: absolute; 
            left: -44px; 
            top: 5px; 
            width: 20px; 
            height: 20px; 
            border-radius: 50%; 
            background: #fff; 
            border: 5px solid #f0f2f5; 
            z-index: 1; 
            transition: 0.3s;
        }
        .timeline-item.active::before { 
            border-color: var(--primary); 
            background: var(--primary); 
            box-shadow: 0 0 0 8px rgba(123, 121, 242, 0.15); 
        }
        .timeline-item.active h4 { color: var(--primary); }

        .timeline-content h4 { 
            font-weight: 900; 
            color: #363949; 
            font-size: 1.1rem; 
            margin-bottom: 8px; 
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .timeline-content p { 
            font-size: 0.9rem; 
            color: #7d8da1; 
            font-weight: 500;
            line-height: 1.6;
        }
        .timeline-date { 
            font-size: 0.75rem; 
            color: #b2bec3; 
            font-weight: 800; 
            text-transform: uppercase; 
            margin-top: 10px; 
            letter-spacing: 1px;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: var(--primary-light);
            color: var(--primary);
        }

        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        @keyframes floating {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
    </style>
</head>
<body>

<nav class="glass-nav py-5 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-6 flex justify-between items-center">
        <div class="flex items-center gap-2">
            <div style="height: 32px; display: flex; align-items: center; justify-content: center;">
                <img src="assets/images/asset.jpg" alt="BLOOMINOUS" style="max-height: 100%; max-width: 100%; object-fit: contain;">
            </div>
            <h1 class="text-xl font-black italic tracking-tighter text-[#363949] hidden sm:block">BLOOMINOUS</h1>
        </div>
        <div class="flex items-center gap-8 text-[11px] font-extrabold uppercase tracking-[2px] text-gray-400">
            <a href="templates/shop.php" class="hover:text-[#7B79F2] transition-colors">Shop</a>
            <a href="templates/my_orders.php" class="hover:text-[#7B79F2] transition-colors">My Orders</a>
            <a href="logout.php" class="text-red-400 hover:text-red-600 transition-colors">Logout</a>
        </div>
    </div>
</nav>

<div class="track-container">
    <div class="flex items-center gap-6 mb-12">
        <a href="templates/my_orders.php" class="w-12 h-12 bg-white rounded-2xl flex items-center justify-center text-[#7d8da1] hover:text-[#7B79F2] hover:shadow-lg transition-all border border-gray-100">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <h1 class="text-4xl font-black text-[#363949] uppercase tracking-tighter">Track Order</h1>
            <p class="text-[#7d8da1] font-bold text-[10px] uppercase tracking-[2px]">ID: <span id="displayOrderId">...</span></p>
        </div>
    </div>

    <div class="track-card">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-8 pb-10 mb-10 border-b border-gray-100">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 bg-[#eef2ff] rounded-2xl flex items-center justify-center text-[#7B79F2]">
                    <i class="fa-solid fa-user fa-xl"></i>
                </div>
                <div>
                    <p class="text-[10px] font-black text-[#7d8da1] uppercase tracking-widest mb-1">Customer Name</p>
                    <h3 class="text-xl font-black text-[#363949]" id="customerName">Loading...</h3>
                </div>
            </div>
            <div class="text-left md:text-right">
                <p class="text-[10px] font-black text-[#7d8da1] uppercase tracking-widest mb-2">Current Status</p>
                <span id="currentStatus" class="status-badge">
                    Loading...
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-10 mb-12">
            <div class="p-6 bg-[#fcfaff] rounded-3xl border border-gray-50">
                <p class="text-[10px] font-black text-[#7d8da1] uppercase tracking-widest mb-3">Delivery Address</p>
                <p class="text-sm font-bold text-[#363949] leading-relaxed" id="deliveryAddress">Loading address...</p>
            </div>
            <div class="p-6 bg-[#fcfaff] rounded-3xl border border-gray-50">
                <p class="text-[10px] font-black text-[#7d8da1] uppercase tracking-widest mb-3">Order Total</p>
                <p class="text-2xl font-black text-[#7B79F2]" id="orderTotal">₱0.00</p>
            </div>
        </div>

        <div class="tracking-timeline" id="timeline">
            <!-- Timeline items will be loaded here -->
            <div class="text-center py-10">
                <i class="fa-solid fa-spinner fa-spin text-2xl text-[#7B79F2]"></i>
            </div>
        </div>
    </div>
</div>

<script>
    <?php
        $configPath = __DIR__ . '/firebase-applet-config.json';
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
        const orderId = "<?php echo $order_id; ?>";
        document.getElementById('displayOrderId').innerText = orderId.toUpperCase();

        document.addEventListener('DOMContentLoaded', () => {
            const timeline = document.getElementById('timeline');

            db.collection('orders').doc(orderId).onSnapshot(doc => {
                if (!doc.exists) {
                    console.error('Order not found!');
                    return;
                }

                const o = doc.data();
                document.getElementById('customerName').innerText = o.customer_name || 'Valued Customer';
                document.getElementById('deliveryAddress').innerText = o.address || 'No address provided';
                document.getElementById('orderTotal').innerText = '₱' + parseFloat(o.total_price || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
                
                const status = o.status || 'Pending';
                document.getElementById('currentStatus').innerText = status;

                const steps = [
                    { 
                        label: 'Order Placed', 
                        desc: 'Your order has been received and is being processed by our florists.', 
                        active: true,
                        date: o.timestamp ? o.timestamp.toDate() : new Date()
                    },
                    { 
                        label: 'Preparing', 
                        desc: 'Our expert florists are hand-crafting your unique arrangement.', 
                        active: ['Preparing', 'Shipped', 'Delivered'].includes(status) 
                    },
                    { 
                        label: 'On Delivery', 
                        desc: 'Your flowers are on the way to the delivery address.', 
                        active: ['Shipped', 'Delivered'].includes(status) 
                    },
                    { 
                        label: 'Delivered', 
                        desc: 'The order has been successfully delivered. Enjoy your blooms!', 
                        active: status === 'Delivered' 
                    }
                ];

                let html = '';
                steps.forEach(step => {
                    const dateStr = step.date ? step.date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
                    
                    html += `
                    <div class="timeline-item ${step.active ? 'active' : ''}">
                        <div class="timeline-content">
                            <h4>${step.label}</h4>
                            <p>${step.desc}</p>
                            ${step.active && dateStr ? `<div class="timeline-date">${dateStr}</div>` : ''}
                        </div>
                    </div>
                    `;
                });
                timeline.innerHTML = html;
            }, error => {
                console.error("Error tracking order:", error);
            });
        });
    }
</script>

</body>
</html>