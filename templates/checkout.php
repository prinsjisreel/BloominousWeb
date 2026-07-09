<?php
/**
 * BLOOMINOUS - Checkout Page
 * Handles order summary, shipping details, and initiates payment.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check - Allow both users and admins
$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;

if (!$user_id) {
    header("Location: ../index.php");
    exit();
}

$user_name = $_SESSION['username'] ?? $_SESSION['admin_name'] ?? 'Valued Customer';
$user_email = $_SESSION['email'] ?? '';

// Get order details from session or URL
$amount = isset($_GET['amount']) ? floatval($_GET['amount']) : (isset($_SESSION['checkout_amount']) ? $_SESSION['checkout_amount'] : 0);
$items = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];

if ($amount <= 0 && empty($items)) {
    header("Location: shop.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | BloomShop</title>
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

        .checkout-container { 
            max-width: 1100px; 
            margin: 0 auto; 
            padding: 60px 20px; 
        }

        .card { 
            background: var(--white); 
            border-radius: 35px; 
            padding: 40px; 
            box-shadow: 0 20px 50px rgba(123, 121, 242, 0.05); 
            border: 1px solid #f0f2f5;
        }

        .input-group { margin-bottom: 25px; }
        .input-label { 
            display: block; 
            font-size: 0.75rem; 
            font-weight: 800; 
            text-transform: uppercase; 
            letter-spacing: 1px; 
            color: var(--text-muted);
            margin-bottom: 10px;
        }
        .input-field {
            width: 100%;
            padding: 15px 25px;
            background: #f8faff;
            border: 2px solid transparent;
            border-radius: 20px;
            font-weight: 600;
            transition: 0.3s;
            outline: none;
        }
        .input-field:focus {
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 10px 20px rgba(123, 121, 242, 0.05);
        }

        .btn-checkout {
            width: 100%;
            padding: 20px;
            background: var(--primary);
            color: #fff;
            border-radius: 25px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            transition: 0.3s;
            box-shadow: 0 15px 30px rgba(123, 121, 242, 0.2);
        }
        .btn-checkout:hover {
            background: #5a58d1;
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(123, 121, 242, 0.3);
        }
        .btn-checkout:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px dashed #eee;
        }
        .order-item:last-child { border-bottom: none; }
    </style>
</head>
<body>

<nav class="glass-nav py-5 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-6 flex justify-between items-center">
        <div class="flex items-center gap-2">
            <img src="../assets/images/asset.jpg" alt="BLOOM" class="h-8 object-contain">
            <h1 class="text-xl font-black italic tracking-tighter text-[#363949] hidden sm:block">BLOOM</h1>
        </div>
        <div class="flex items-center gap-8 text-[11px] font-extrabold uppercase tracking-[2px] text-gray-400">
            <a href="shop.php" class="hover:text-[#7B79F2] transition-colors">Shop</a>
            <a href="my_orders.php" class="hover:text-[#7B79F2] transition-colors">My Orders</a>
            <a href="../logout.php" class="text-red-400 hover:text-red-600 transition-colors">Logout</a>
        </div>
    </div>
</nav>

<div class="checkout-container">
    <div class="mb-12">
        <h2 class="text-4xl font-black text-[#363949] uppercase tracking-tighter">Checkout</h2>
        <p class="text-[#7d8da1] font-medium mt-2">Complete your order and bring beauty home</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
        <!-- Shipping Details -->
        <div class="lg:col-span-2">
            <div class="card">
                <h3 class="text-2xl font-black text-[#363949] mb-8 uppercase tracking-tight">Shipping Information</h3>
                
                <form id="checkoutForm">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="input-group">
                            <label class="input-label">Full Name</label>
                            <input type="text" id="fullName" class="input-field" value="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>" required>
                        </div>
                        <div class="input-group">
                            <label class="input-label">Email Address</label>
                            <input type="email" id="email" class="input-field" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label class="input-label">Delivery Address</label>
                        <textarea id="address" class="input-field h-24 py-4 mb-4" placeholder="Street, Barangay, City, Province" required></textarea>
                    </div>

                    <div class="mb-8 p-6 bg-white rounded-3xl border-2 border-dashed border-[#7B79F2]/20">
                        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                            <div class="text-center md:text-left">
                                <h4 class="font-black text-[#363949] uppercase text-sm tracking-tight">Precise Delivery Location</h4>
                                <p class="text-[10px] text-[#7d8da1] font-bold mt-1">Get lower shipping fees by pinning your location</p>
                            </div>
                            <button type="button" id="getLocationBtn" class="px-6 py-3 bg-[#7B79F2] text-white rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-[#5a58d1] transition-all flex items-center gap-2">
                                <i class="fa-solid fa-location-dot"></i>
                                Pin My location
                            </button>
                        </div>
                        <div id="locationStatus" class="mt-4 hidden p-3 bg-green-50 text-green-600 rounded-xl text-[10px] font-bold text-center border border-green-100 flex items-center justify-center gap-2">
                            <i class="fa-solid fa-circle-check"></i>
                            Location Pinned Successfully
                        </div>
                        <input type="hidden" id="customerLat">
                        <input type="hidden" id="customerLng">
                        <input type="hidden" id="calculatedShipping" value="120">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="input-group">
                            <label class="input-label">Recipient Name (Sino ang pagbibigyan)</label>
                            <input type="text" id="recipientName" class="input-field" placeholder="Sino ang makakatanggap ng bulaklak? (Optional)">
                        </div>
                        <div class="input-group">
                            <label class="input-label">Recipient Phone Number (Optional)</label>
                            <input type="tel" id="recipientPhone" class="input-field" placeholder="Recipient's phone number">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="input-group">
                            <label class="input-label">Phone Number</label>
                            <input type="tel" id="phone" class="input-field" placeholder="09XXXXXXXXX" required>
                        </div>
                        <div class="input-group">
                            <label class="input-label">Payment Method</label>
                            <select id="paymentMethod" class="input-field">
                                <option value="GCash">GCash (via PayMongo)</option>
                                <option value="PayMaya">Maya (via PayMongo)</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="COD">Cash on Delivery</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-8 p-6 bg-[#f8faff] rounded-3xl border border-gray-100">
                        <p class="text-xs font-bold text-[#7d8da1] leading-relaxed">
                            <i class="fa-solid fa-shield-halved mr-2 text-[#7B79F2]"></i>
                            Your payment is secured. By clicking "Place Order", you agree to our terms and conditions.
                        </p>
                    </div>
                </form>
            </div>
        </div>

        <!-- PayMongo Processing Form (Hidden) -->
        <form id="paymongoForm" action="../includes/process_payment.php" method="POST" class="hidden">
            <input type="hidden" name="order_id" id="pm_order_id">
            <input type="hidden" name="amount" id="pm_amount" value="0">
            <input type="hidden" name="email" id="pm_email">
            <input type="hidden" name="name" id="pm_name">
            <input type="hidden" name="method" id="pm_method">
        </form>

        <!-- Order Summary -->
        <div class="lg:col-span-1">
            <div class="card sticky top-32">
                <h3 class="text-xl font-black text-[#363949] mb-8 uppercase tracking-tight">Order Summary</h3>
                
                <div class="mb-8" id="orderItemsDisplay">
                    <!-- Items will be listed here from localStorage -->
                </div>

                <div class="space-y-4 pt-6 border-t border-gray-100">
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-bold text-[#7d8da1]">Subtotal</span>
                        <span class="font-bold text-[#363949]" id="subtotalDisplay">₱0.00</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-bold text-[#7d8da1]">Est. Road Distance</span>
                        <span class="font-bold text-[#363949]" id="distanceDisplay">0.0 KM</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-bold text-[#7d8da1]">Shipping</span>
                        <span class="font-bold text-[#363949]" id="shippingDisplay">₱120.00</span>
                    </div>
                    <div class="flex justify-between items-center pt-4 border-t border-gray-100">
                        <span class="text-lg font-black text-[#363949]">Total</span>
                        <span class="text-2xl font-black text-[#7B79F2]" id="totalDisplay">₱0.00</span>
                    </div>
                </div>

                <button type="button" id="placeOrderBtn" class="btn-checkout mt-10">
                    Place Order
                </button>
            </div>
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
        
        $mapsKey = getenv('GOOGLE_MAPS_PLATFORM_KEY') ?: '';
        echo "const GOOGLE_MAPS_KEY = '" . $mapsKey . "';";
    ?>
    
    if (firebaseConfig.apiKey) {
        firebase.initializeApp(firebaseConfig);
        const db = firebase.firestore();
        const userId = "<?php echo $user_id; ?>";
        const branchId = localStorage.getItem('bloom_branch_id') || 'main_branch';

        // Load and Render Cart Logic
        function renderCart() {
            const cart = JSON.parse(localStorage.getItem('bloom_cart') || '{}');
            const items = Object.values(cart);
            
            let subtotal = 0;
            let itemsHtml = '';
            
            if (items.length === 0) {
                itemsHtml = '<p class="text-xs italic text-gray-400 text-center py-4">Your cart is empty.</p>';
            } else {
                items.forEach(item => {
                    const itemTotal = item.price * item.qty;
                    subtotal += itemTotal;
                    itemsHtml += `
                        <div class="order-item group">
                            <div class="flex flex-col flex-1">
                                <span class="text-sm font-bold text-[#363949]">${item.name}</span>
                                <div class="flex items-center gap-3 mt-2">
                                    <div class="flex items-center bg-[#f8faff] rounded-xl border border-gray-100 overflow-hidden">
                                        <button onclick="updateQty('${item.id}', -1)" class="w-8 h-8 flex items-center justify-center text-[#7B79F2] hover:bg-[#7B79F2] hover:text-white transition-all text-xs">
                                            <i class="fa-solid fa-minus"></i>
                                        </button>
                                        <span class="w-8 text-center text-[10px] font-black text-[#363949]">${item.qty}</span>
                                        <button onclick="updateQty('${item.id}', 1)" class="w-8 h-8 flex items-center justify-center text-[#7B79F2] hover:bg-[#7B79F2] hover:text-white transition-all text-xs">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">P${item.price.toLocaleString()} ea</span>
                                </div>
                            </div>
                            <span class="font-black text-[#363949] ml-4">₱${itemTotal.toLocaleString()}</span>
                        </div>
                    `;
                });
            }
            
            document.getElementById('orderItemsDisplay').innerHTML = itemsHtml;
            document.getElementById('subtotalDisplay').innerText = `₱${subtotal.toLocaleString()}`;
            
            // Re-calculate total with shipping
            const shipping = parseFloat(document.getElementById('calculatedShipping').value) || 0;
            document.getElementById('totalDisplay').innerText = `₱${(subtotal + shipping).toLocaleString()}`;
            
            // Global subtotal for checkout order object
            window.currentSubtotal = subtotal;
            window.currentItems = items;
        }

        window.updateQty = function(itemId, delta) {
            const cart = JSON.parse(localStorage.getItem('bloom_cart') || '{}');
            if (cart[itemId]) {
                cart[itemId].qty += delta;
                if (cart[itemId].qty <= 0) {
                    delete cart[itemId];
                }
                localStorage.setItem('bloom_cart', JSON.stringify(cart));
                renderCart();
            }
        };

        // Initialize Cart display
        renderCart();
        
        // Branch Selection Logic
        let allBranches = [];
        let assignedBranchId = localStorage.getItem('bloom_branch_id') || 'main_branch';
        let assignedBranchName = 'Loading...';

        // Fetch all branches to find the nearest one
        db.collection('branches').get().then(snap => {
            snap.forEach(doc => {
                const data = doc.data();
                allBranches.push({ id: doc.id, ...data });
                if (doc.id === assignedBranchId) {
                    assignedBranchName = data.name || 'Main Store';
                }
            });
        }).catch(err => {
            console.error("Error fetching branches:", err);
            assignedBranchName = 'Main Store';
        });

        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371; // Radius of the earth in km
            const dLat = deg2rad(lat2 - lat1);
            const dLon = deg2rad(lon2 - lon1);
            const a = 
                Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(deg2rad(lat1)) * Math.cos(deg2rad(lat2)) * 
                Math.sin(dLon / 2) * Math.sin(dLon / 2)
            ; 
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)); 
            const d = R * c; // Distance in km
            return d;
        }

        function deg2rad(deg) {
            return deg * (Math.PI / 180);
        }

        async function getRealRoadDistance(originLat, originLng, destLat, destLng) {
            if (!GOOGLE_MAPS_KEY) {
                console.warn("Google Maps Key missing, using estimation.");
                return null;
            }

            try {
                const response = await fetch('https://routes.googleapis.com/v2:computeRoutes', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Goog-Api-Key': GOOGLE_MAPS_KEY,
                        'X-Goog-FieldMask': 'routes.distanceMeters,routes.duration'
                    },
                    body: JSON.stringify({
                        "origin": { "location": { "latLng": { "latitude": originLat, "longitude": originLng } } },
                        "destination": { "location": { "latLng": { "latitude": destLat, "longitude": destLng } } },
                        "travelMode": "DRIVING",
                        "routingPreference": "TRAFFIC_AWARE",
                        "routeModifiers": { "avoidTolls": true }
                    })
                });

                const data = await response.json();
                if (data.routes && data.routes.length > 0) {
                    return data.routes[0].distanceMeters / 1000;
                }
                return null;
            } catch (err) {
                console.error("Routes API failure:", err);
                return null;
            }
        }

        function updateTotals() {
            const shipping = parseFloat(document.getElementById('calculatedShipping').value) || 0;
            document.getElementById('shippingDisplay').innerText = `₱${shipping.toLocaleString()}`;
            
            // Show distance if calculated
            const statusText = document.getElementById('locationStatus').innerText;
            if (statusText.includes('km')) {
                const distMatch = statusText.match(/(\d+\.\d+)km/);
                if (distMatch) {
                    document.getElementById('distanceDisplay').innerText = distMatch[1] + ' KM';
                }
            }
            
            renderCart();
        }

        // Initialize display
        updateTotals();

        // Geolocation Logic
        document.getElementById('getLocationBtn').addEventListener('click', () => {
            const btn = document.getElementById('getLocationBtn');
            const status = document.getElementById('locationStatus');
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Calculating precise route...';

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(async (pos) => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    
                    document.getElementById('customerLat').value = lat;
                    document.getElementById('customerLng').value = lng;
                    
                    // Find Nearest Branch
                    let minDistance = Infinity;
                    let nearestBranch = null;

                    allBranches.forEach(branch => {
                        const bLat = branch.latitude || (branch.location ? branch.location.lat : null);
                        const bLng = branch.longitude || (branch.location ? branch.location.lng : null);
                        
                        if (bLat && bLng) {
                            const dist = calculateDistance(bLat, bLng, lat, lng);
                            if (dist < minDistance) {
                                minDistance = dist;
                                nearestBranch = branch;
                            }
                        }
                    });

                    // Target Coordinates
                    let branchLat = 14.7573;
                    let branchLng = 120.9439;
                    if (nearestBranch) {
                        assignedBranchId = nearestBranch.id;
                        assignedBranchName = nearestBranch.name || nearestBranch.id;
                        branchLat = nearestBranch.latitude || (nearestBranch.location ? nearestBranch.location.lat : branchLat);
                        branchLng = nearestBranch.longitude || (nearestBranch.location ? nearestBranch.location.lng : branchLng);
                    }

                    // Try real road distance
                    let finalDistanceKm = null;
                    let isEstimated = false;

                    try {
                        finalDistanceKm = await getRealRoadDistance(branchLat, branchLng, lat, lng);
                    } catch (e) {
                        console.warn("Road distance failed, falling back to estimation");
                    }

                    if (!finalDistanceKm) {
                        const straightLineKm = calculateDistance(branchLat, branchLng, lat, lng);
                        isEstimated = true;
                        // Apply Road Distance Approximation Factor
                        if (straightLineKm < 5) {
                            finalDistanceKm = straightLineKm * 1.3;
                        } else if (straightLineKm < 20) {
                            finalDistanceKm = straightLineKm * 1.8;
                        } else {
                            finalDistanceKm = straightLineKm * 2.1; 
                        }
                    }
                    
                    // Calculate dynamic shipping based on distance
                    let shippingFee = 50; // Base fee
                    shippingFee += Math.ceil(finalDistanceKm) * 15; // ₱15 per km
                    shippingFee = Math.max(50, Math.min(shippingFee, 800));
                    
                    document.getElementById('calculatedShipping').value = shippingFee;
                    
                    status.classList.remove('hidden', 'bg-amber-50', 'text-amber-700', 'border-amber-100');
                    status.classList.add('bg-green-50', 'text-green-600', 'border-green-100');
                    const methodLabel = isEstimated ? 'Estimated' : 'Google Maps';
                    status.innerHTML = `<i class="fa-solid fa-route"></i> ${methodLabel} Route: <b>${finalDistanceKm.toFixed(1)}km</b> from ${assignedBranchName}`;
                    
                    updateTotals();
                    btn.innerHTML = '<i class="fa-solid fa-location-dot"></i> Update location';
                    btn.disabled = false;
                }, (err) => {
                    status.classList.remove('hidden', 'bg-green-50', 'text-green-600', 'border-green-100');
                    status.classList.add('bg-amber-50', 'text-amber-700', 'border-amber-100');
                    status.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> Location access not available (${err.message}). Using standard shipping rate (₱120).`;
                    status.classList.remove('hidden');
                    
                    document.getElementById('calculatedShipping').value = 120;
                    updateTotals();
                    
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-location-dot"></i> Pin My location';
                }, { timeout: 8000 });
            } else {
                status.classList.remove('hidden', 'bg-green-50', 'text-green-600', 'border-green-100');
                status.classList.add('bg-amber-50', 'text-amber-700', 'border-amber-100');
                status.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> Geolocation is not supported by this browser. Using standard shipping rate (₱120).`;
                status.classList.remove('hidden');
                
                document.getElementById('calculatedShipping').value = 120;
                updateTotals();
                btn.disabled = false;
            }
        });

        document.getElementById('placeOrderBtn').addEventListener('click', async () => {
            const items = window.currentItems || [];
            const subtotal = window.currentSubtotal || 0;

            if (items.length === 0) {
                alert('Your cart is empty.');
                return;
            }

            const btn = document.getElementById('placeOrderBtn');
            const name = document.getElementById('fullName').value;
            const email = document.getElementById('email').value;
            const address = document.getElementById('address').value;
            const phone = document.getElementById('phone').value;
            const recipientName = document.getElementById('recipientName').value.trim() || name;
            const recipientPhone = document.getElementById('recipientPhone').value.trim() || phone;
            const paymentMethod = document.getElementById('paymentMethod').value;
            const shippingFee = parseFloat(document.getElementById('calculatedShipping').value);
            const customerLat = document.getElementById('customerLat').value;
            const customerLng = document.getElementById('customerLng').value;

            if (!name || !email || !address || !phone) {
                alert('Please fill in all required fields.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Processing...';

            try {
                // 1. Create Order in Firestore
                const totalPrice = subtotal + shippingFee;
                const orderData = {
                    user_id: userId,
                    customer_name: name,
                    customerName: name, // for compatibility
                    recipientName: recipientName,
                    recipientPhone: recipientPhone,
                    email: email,
                    address: address,
                    phone: phone,
                    payment_method: paymentMethod,
                    items: items,
                    subtotal: subtotal,
                    shipping_fee: shippingFee,
                    total_price: totalPrice,
                    branchId: assignedBranchId,
                    branchName: assignedBranchName,
                    status: 'pending',
                    type: 'WEB',
                    location: customerLat ? { lat: parseFloat(customerLat), lng: parseFloat(customerLng) } : null,
                    timestamp: firebase.firestore.FieldValue.serverTimestamp(),
                    createdAt: firebase.firestore.FieldValue.serverTimestamp()
                };

                const orderRef = await db.collection('orders').add(orderData);

                // Clear cart
                localStorage.removeItem('bloom_cart');

        // 2. Decide redirection based on payment method
                const onlineMethods = ['GCash', 'PayMaya'];
                const pmForm = document.getElementById('paymongoForm');
                
                if (onlineMethods.includes(paymentMethod) && pmForm) {
                    // Redirect to PayMongo processing
                    document.getElementById('pm_order_id').value = orderRef.id;
                    document.getElementById('pm_amount').value = totalPrice;
                    document.getElementById('pm_email').value = email;
                    document.getElementById('pm_name').value = name;
                    document.getElementById('pm_method').value = paymentMethod;
                    pmForm.submit();
                } else if (onlineMethods.includes(paymentMethod) && !pmForm) {
                    console.error("PayMongo form not found in DOM");
                    alert("Payment processing bridge failed. Please contact support.");
                } else {
                    // Redirect to Success/Tracking directly for COD or manual methods
                    window.location.href = '../track_order.php?id=' + orderRef.id + '&success=true';
                }

            } catch (error) {
                console.error("Checkout Error:", error);
                
                let errorMessage = 'An error occurred during checkout. Please try again.';
                if (error.message) {
                    errorMessage += '\n\nDetails: ' + error.message;
                }
                
                alert(errorMessage);
                btn.disabled = false;
                btn.innerHTML = 'Place Order';
            }
        });

        // Test Firestore Connection
        async function testConnection() {
            try {
                // Try a simple read to check permissions/connectivity
                await db.collection('test').doc('connection').get();
                console.log("Firestore connection test completed (Read attempt).");
            } catch (error) {
                console.error("Firestore Connection Warning:", error);
                // If it's a permission error, it means we ARE connected but rules are blocking us.
            }
        }
        testConnection();
    }
</script>

</body>
</html>
