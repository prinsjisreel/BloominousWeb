<?php
/**
 * BLOOMINOUS - Checkout Page
 * Handles order summary, shipping details, initiates payment, calculates fraud thresholds, and tracks distance anomalies.
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
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>

    <!-- Leaflet (OpenStreetMap) - free, no API key needed, works on localhost -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    
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
        
        .form-group-fieldset {
            position: relative;
            margin-bottom: 24px;
        }
        .form-group-fieldset label {
            position: absolute;
            top: -10px;
            left: 15px;
            background: white;
            padding: 0 6px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #aaa;
            z-index: 10;
        }
        .form-fieldset-input {
            width: 100%;
            padding: 14px 20px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-main);
            outline: none;
            transition: all 0.3s;
            background: #fff;
        }
        .form-fieldset-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(123, 121, 242, 0.1);
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

        #map-canvas {
            width: 100%;
            height: 240px;
            border-radius: 16px;
            background-color: #e5e7eb;
            position: relative;
            z-index: 0;
            isolation: isolate;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .tab-btn.active {
            color: #ff5252;
            border-bottom: 2px solid #ff5252;
        }
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
    <!-- Soft Restriction Notice Banner -->
    <div id="restrictionBannerNotice" class="hidden w-full mb-8 bg-amber-50 border border-amber-200 text-amber-800 p-5 rounded-3xl flex items-center gap-4 shadow-sm animate-pulse">
        <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center text-amber-600 flex-shrink-0 text-lg">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div>
            <h4 class="font-black uppercase text-xs tracking-wider">Account Soft Restriction Active</h4>
            <p class="text-xs font-semibold opacity-90 mt-0.5" id="restrictionBannerMessage">This account was restricted for 30 days due to behavioral tracking flags.</p>
        </div>
    </div>

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
                        <div class="form-group-fieldset">
                            <label>Full Name</label>
                            <input type="text" id="fullName" class="form-fieldset-input" value="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group-fieldset">
                            <label>Phone Number</label>
                            <input type="tel" id="phone" class="form-fieldset-input" placeholder="(+63) XXX XXX XXXX" required>
                        </div>
                    </div>

                    <div class="form-group-fieldset">
                        <label>Region, Province, City, Barangay</label>
                        <div class="relative">
                            <input type="text" id="regionCityBarangay" class="form-fieldset-input cursor-pointer pr-10" readonly placeholder="Select Location" onclick="openLocationModal()" required>
                            <div class="absolute inset-y-0 right-0 flex items-center px-4 text-gray-400 pointer-events-none">
                                <i class="fa-solid fa-chevron-down text-xs"></i>
                            </div>
                        </div>
                    </div>

                    <div class="form-group-fieldset">
                        <label>Postal Code</label>
                        <input type="text" id="postalCode" class="form-fieldset-input" placeholder="e.g. 3019" required>
                    </div>

                    <div class="form-group-fieldset">
                        <label>Street Name, Building, House No.</label>
                        <input type="text" id="address" class="form-fieldset-input" placeholder="e.g., 64 Lias Road, Lias" required>
                    </div>

                    <!-- Gift Selection Checkbox Option -->
                    <div class="mb-6 p-4 rounded-2xl bg-pink-50/30 border border-pink-100/50 flex items-center gap-3">
                        <input type="checkbox" id="isGiftCheckbox" class="w-5 h-5 accent-pink-500 rounded cursor-pointer transition-all">
                        <label for="isGiftCheckbox" class="text-xs font-bold text-gray-700 uppercase tracking-wide cursor-pointer selection:bg-transparent">
                            <i class="fa-solid fa-gift text-pink-500 mr-1"></i> Send this order as a gift
                        </label>
                    </div>

                    <input type="email" id="email" class="hidden" value="<?php echo htmlspecialchars($_SESSION['email'] ?? 'guest@bloom.com'); ?>">

                    <!-- Interactive Google Map Box and GEO Pinner -->
                    <div class="mb-8 space-y-4">
                        <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                            <div class="text-center sm:text-left">
                                <h4 class="font-black text-[#363949] uppercase text-xs tracking-tight">Precise Delivery Location</h4>
                                <p class="text-[10px] text-[#7d8da1] font-semibold mt-0.5">Get lower shipping fees by pinning your location</p>
                            </div>
                            <button type="button" id="getLocationBtn" class="px-5 py-3 bg-[#7B79F2] text-white rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-[#5a58d1] transition-all flex items-center gap-2 shadow-sm">
                                <i class="fa-solid fa-location-crosshairs text-xs"></i>
                                Update Location
                            </button>
                        </div>

                        <div id="locationStatus" class="hidden p-3 bg-green-50 text-green-600 rounded-xl text-[10px] font-bold text-center border border-green-100 flex items-center justify-center gap-2">
                            <i class="fa-solid fa-route"></i>
                            <span id="locationStatusText">Estimated Route: 0.0km from branch</span>
                        </div>

                        <div id="map-canvas">
                            <div id="mapPlaceholder" class="absolute inset-0 flex flex-col items-center justify-center bg-slate-100 text-gray-400 gap-2">
                                <i class="fa-solid fa-map-location-dot text-4xl text-[#7B79F2]/40"></i>
                                <span class="text-xs font-bold uppercase tracking-wider">Google Maps View Integration</span>
                            </div>
                        </div>
                        
                        <input type="hidden" id="customerLat">
                        <input type="hidden" id="customerLng">
                        <input type="hidden" id="calculatedShipping" value="120">
                    </div>

                    <div class="form-group-fieldset">
                        <label>Payment Method</label>
                        <select id="paymentMethod" class="form-fieldset-input appearance-none cursor-pointer pr-10">
                            <option value="GCash">GCash (via PayMongo)</option>
                            <option value="PayMaya">Maya (via PayMongo)</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="COD" id="codOption">Cash on Delivery</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-400">
                            <i class="fa-solid fa-chevron-down text-xs"></i>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Order Summary -->
        <div class="lg:col-span-1">
            <div class="card sticky top-32">
                <h3 class="text-xl font-black text-[#363949] mb-8 uppercase tracking-tight">Order Summary</h3>
                <div class="mb-8" id="orderItemsDisplay"></div>
                <div class="space-y-4 pt-6 border-t border-gray-100">
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-bold text-[#7d8da1]">Subtotal</span>
                        <span class="font-bold text-[#363949]" id="subtotalDisplay"> 0.00</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-bold text-[#7d8da1]">Est. Road Distance</span>
                        <span class="font-bold text-[#363949]" id="distanceDisplay">0.0 KM</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-bold text-[#7d8da1]">Shipping</span>
                        <span class="font-bold text-[#363949]" id="shippingDisplay"> 120.00</span>
                    </div>
                    <div class="flex justify-between items-center pt-4 border-t border-gray-100">
                        <span class="text-lg font-black text-[#363949]">Total</span>
                        <span class="text-2xl font-black text-[#7B79F2]" id="totalDisplay"> 0.00</span>
                    </div>
                </div>
                <button type="button" id="placeOrderBtn" class="btn-checkout mt-10">
                    Place Order
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Location Selection Dialog Modal Box Area -->
<div id="locationModal" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm hidden flex items-center justify-center p-4 animate-fade-in">
    <div class="bg-white rounded-3xl w-full max-w-xl overflow-hidden shadow-2xl flex flex-col h-[500px]">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-lg font-bold text-gray-800">Select Delivery Location</h3>
            <button type="button" onclick="closeLocationModal()" class="text-gray-400 hover:text-red-500 transition-colors">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        <div class="grid grid-cols-4 text-center border-b border-gray-100 text-sm font-bold text-gray-400 bg-white">
            <button id="tab-regions" class="tab-btn py-3 active">Region</button>
            <button id="tab-provinces" class="tab-btn py-3" disabled>Province</button>
            <button id="tab-cities" class="tab-btn py-3" disabled>City</button>
            <button id="tab-barangays" class="tab-btn py-3" disabled>Barangay</button>
        </div>
        <div id="modal-list-container" class="flex-1 overflow-y-auto p-4 space-y-1 bg-white">
            <p class="text-center text-xs text-gray-400 italic py-8">Loading geo directories data...</p>
        </div>
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

<!-- SMS Verification Modal (Restricted Account Identity Check) -->
<div id="smsModal" class="fixed inset-0 z-[60] bg-black/50 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-[35px] p-8 w-full max-w-sm text-center shadow-2xl">
        <h3 class="text-xl font-black text-[#363949] mb-2">Identity Verification</h3>
        <p class="text-xs text-gray-400 font-medium mb-1" id="smsModalSubtitle">Enter the 6-digit code to restore your account trust.</p>
        <p class="text-[10px] text-[#7B79F2] font-bold uppercase tracking-widest mb-6 hidden" id="smsTestModeBadge">
            <i class="fa-solid fa-flask"></i> Test Mode Number Detected
        </p>
        <div id="otp-inputs" class="flex justify-between gap-2 mb-6">
            <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
            <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
            <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
            <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
            <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
            <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
        </div>
        <button id="verifyOtpBtn" type="button" class="w-full bg-[#7B79F2] hover:bg-[#5a58d1] text-white font-black py-4 rounded-2xl uppercase tracking-widest text-xs mb-4 transition-all">Verify Identity</button>
        
        <!-- UI FIX CONTAINER: Wraps bottom actions inside a clear flex column layout to force line breaks and distinct layout mapping -->
        <div class="mt-4 flex flex-col items-center gap-3">
            <p id="resendTimerWrap" class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Resend in <span id="timer">60</span>s</p>
            <button id="resendOtpBtn" type="button" class="hidden text-[#7B79F2] font-black text-[10px] uppercase tracking-widest hover:text-[#5a58d1] transition-colors">Resend Code</button>
            <button id="closeSmsModalBtn" type="button" class="text-gray-300 hover:text-gray-500 font-bold text-[10px] uppercase tracking-widest transition-colors">Cancel</button>
        </div>
        
        <div id="recaptcha-container" class="hidden"></div>
    </div>
</div>

<style>
    .otp-box {
        width: 40px;
        height: 48px;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        text-align: center;
        font-weight: 900;
        font-size: 1.1rem;
        color: var(--text-main);
    }
    .otp-box:focus { border-color: var(--primary); outline: none; }
</style>

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
        const auth = firebase.auth();
        const userId = "<?php echo $user_id; ?>";
        let assignedBranchId = localStorage.getItem('bloom_branch_id') || 'main_branch';
        let accountIsRestrictedCurrently = false;

        // --- SMS / OTP Identity Verification (for soft-restricted accounts) ---
        let testPhoneNumbers = [];
        db.collection('config').doc('testPhoneNumbers').get().then(doc => {
            if (doc.exists && Array.isArray(doc.data().numbers)) {
                testPhoneNumbers = doc.data().numbers.map(n => n.replace(/\s+/g, ''));
            }
        }).catch(() => { testPhoneNumbers = []; });

        // --- STRENGTHENED AND NORMALIZED PHONE PARSER ENGINE ---
        function normalizePhone(p) { 
            let phone = (p || '').replace(/\D/g, ''); // Extract absolute numeric components only
            if (phone.startsWith('0')) {
                phone = '63' + phone.substring(1); // Standardized local mobile trunks to country matrix
            }
            return '+' + phone; // Full E.164 verification standard alignment signature
        }

        let resendTimerInterval = null;
        let pendingConfirmationResult = null;
        let otpVerifiedThisSession = false;

        window.recaptchaVerifier = new firebase.auth.RecaptchaVerifier('recaptcha-container', { 'size': 'invisible' });

        document.querySelectorAll('.otp-box').forEach((input, index) => {
            input.addEventListener('input', () => {
                if (input.value.length === 1 && index < 5) document.querySelectorAll('.otp-box')[index + 1].focus();
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !input.value && index > 0) document.querySelectorAll('.otp-box')[index - 1].focus();
            });
        });

        function startResendTimer() {
            let timeLeft = 60;
            const wrap = document.getElementById('resendTimerWrap');
            const resendBtn = document.getElementById('resendOtpBtn');
            const timerSpan = document.getElementById('timer');
            wrap.classList.remove('hidden');
            resendBtn.classList.add('hidden');
            timerSpan.innerText = timeLeft;
            if (resendTimerInterval) clearInterval(resendTimerInterval);
            resendTimerInterval = setInterval(() => {
                timeLeft--;
                timerSpan.innerText = timeLeft;
                if (timeLeft <= 0) {
                    clearInterval(resendTimerInterval);
                    wrap.classList.add('hidden');
                    resendBtn.classList.remove('hidden');
                }
            }, 1000);
        }

        async function startSmsVerification(rawPhone) {
            const phone = normalizePhone(rawPhone); // Fully sanitizes inputs dynamically
            const isTestNumber = testPhoneNumbers.includes(phone);

            const badge = document.getElementById('smsTestModeBadge');
            const subtitle = document.getElementById('smsModalSubtitle');
            if (isTestNumber) {
                badge.classList.remove('hidden');
                subtitle.innerText = 'This is a registered test number — no real SMS will be sent. Use the fixed test code from Firebase Console.';
            } else {
                badge.classList.add('hidden');
                subtitle.innerText = 'Enter the 6-digit code to restore your account trust.';
            }

            try {
                document.getElementById('smsModal').classList.remove('hidden');
                pendingConfirmationResult = await auth.signInWithPhoneNumber(phone, window.recaptchaVerifier);
                startResendTimer();
            } catch (error) {
                alert("SMS Error: " + error.message);
            }
        }

        document.getElementById('verifyOtpBtn').addEventListener('click', async () => {
            let code = "";
            document.querySelectorAll('.otp-box').forEach(i => code += i.value);
            if (code.length !== 6) return alert("Please enter the full 6-digit code.");
            try {
                await pendingConfirmationResult.confirm(code);
                otpVerifiedThisSession = true;
                if (resendTimerInterval) clearInterval(resendTimerInterval);

                db.collection('customers').doc(userId).update({
                    fraudScore: 10,
                    isRestricted: false,
                    fraudFlags: firebase.firestore.FieldValue.arrayUnion("Identity verified via SMS - Trust Restored")
                }).catch(() => {});

                document.getElementById('smsModal').classList.add('hidden');
                submitOrder();
            } catch (error) {
                alert("Verification failed: " + error.message);
            }
        });

        document.getElementById('resendOtpBtn').addEventListener('click', () => {
            const phone = document.getElementById('phone').value;
            startSmsVerification(phone);
        });

        document.getElementById('closeSmsModalBtn').addEventListener('click', () => {
            document.getElementById('smsModal').classList.add('hidden');
            if (resendTimerInterval) clearInterval(resendTimerInterval);
        });
        
        // Setup coordinate indicators for Haversine evaluations
        let currentBranchLat = 14.7573;
        let currentBranchLng = 120.9439;
        let currentBranchName = 'branch';
        let leafletMap = null, leafletCustomerMarker = null, leafletBranchMarker = null, leafletLine = null;

        // Geolocation Engine
        document.getElementById('getLocationBtn').addEventListener('click', () => {
            const statusDiv = document.getElementById('locationStatus');
            const btn = document.getElementById('getLocationBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-xs"></i> Synchronizing GPS...';

            if (!navigator.geolocation) {
                alert("Geolocation is not supported by your browser environment.");
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-location-crosshairs text-xs"></i> Update Location';
                return;
            }

            navigator.geolocation.getCurrentPosition(
                async (position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;

                    document.getElementById('customerLat').value = lat;
                    document.getElementById('customerLng').value = lng;

                    // Make sure branch data from the database has loaded, then pick the closest one
                    await branchesLoadedPromise;
                    const nearest = findNearestBranch(lat, lng);
                    let distance;
                    if (nearest) {
                        currentBranchLat = nearest.branch.latitude;
                        currentBranchLng = nearest.branch.longitude;
                        currentBranchName = nearest.branch.name;
                        nearestBranchId = nearest.branch.id;
                        distance = nearest.distance;
                    } else {
                        // No branch data found in the database - fall back to the Haversine estimate vs the default branch
                        distance = calculateDistance(lat, lng, currentBranchLat, currentBranchLng);
                    }

                    document.getElementById('distanceDisplay').innerText = distance.toFixed(1) + ' KM';
                    
                    // Update Dynamic Route Pricing Metrics
                    let shippingFee = 120.00;
                    if (distance > 10) {
                        shippingFee += Math.ceil(distance - 10) * 10; // Extra charge per KM past regional baseline limits
                    }
                    document.getElementById('calculatedShipping').value = shippingFee;
                    document.getElementById('shippingDisplay').innerText = ' ' + shippingFee.toFixed(2);

                    document.getElementById('locationStatusText').innerText =
                        'Estimated Route: ' + distance.toFixed(1) + 'km from ' + currentBranchName;
                    statusDiv.classList.remove('hidden');
                    renderLocationMap(lat, lng);

                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-location-crosshairs text-xs"></i> Update Location';
                    
                    renderCart();

                    // Refine with real driving-road distance when a Google Maps key is configured
                    getRealRoadDistance(currentBranchLat, currentBranchLng, lat, lng).then(roadKm => {
                        if (roadKm !== null) {
                            document.getElementById('distanceDisplay').innerText = roadKm.toFixed(1) + ' KM';
                            document.getElementById('locationStatusText').innerText =
                                'Estimated Route: ' + roadKm.toFixed(1) + 'km from ' + currentBranchName;
                            let refinedFee = 120.00;
                            if (roadKm > 10) refinedFee += Math.ceil(roadKm - 10) * 10;
                            document.getElementById('calculatedShipping').value = refinedFee;
                            document.getElementById('shippingDisplay').innerText = ' ' + refinedFee.toFixed(2);
                            renderCart();
                        }
                    });
                },
                (error) => {
                    alert("GPS Signal Loss: Unable to retrieve precise localization telemetry context. Check authorization parameters.");
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-location-crosshairs text-xs"></i> Update Location';
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        });

        // Renders a live map (customer pin -> nearest branch pin) via Leaflet/OpenStreetMap.
        // This works with no API key at all, so it renders the same on localhost and in production.
        function renderLocationMap(lat, lng) {
            const canvas = document.getElementById('map-canvas');

            if (!leafletMap) {
                canvas.innerHTML = ''; // clear the placeholder
                leafletMap = L.map(canvas, { zoomControl: true, attributionControl: true });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(leafletMap);
            }

            const customerPoint = [lat, lng];
            const branchPoint = [currentBranchLat, currentBranchLng];

            if (leafletCustomerMarker) leafletMap.removeLayer(leafletCustomerMarker);
            if (leafletBranchMarker) leafletMap.removeLayer(leafletBranchMarker);
            if (leafletLine) leafletMap.removeLayer(leafletLine);

            leafletCustomerMarker = L.marker(customerPoint).addTo(leafletMap).bindPopup('Your location');
            leafletBranchMarker = L.marker(branchPoint).addTo(leafletMap).bindPopup(currentBranchName);
            leafletLine = L.polyline([customerPoint, branchPoint], { color: '#7B79F2', weight: 3, dashArray: '6 6' }).addTo(leafletMap);

            leafletMap.fitBounds(leafletLine.getBounds(), { padding: [30, 30] });

            // Leaflet needs a resize nudge since the container was just made visible/populated
            setTimeout(() => leafletMap.invalidateSize(), 100);
        }

        // Cart Rendering Engine
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
                            <span class="font-black text-[#363949] ml-4"> ${itemTotal.toLocaleString()}</span>
                        </div>
                    `;
                });
            }
            document.getElementById('orderItemsDisplay').innerHTML = itemsHtml;
            document.getElementById('subtotalDisplay').innerText = ` ${subtotal.toLocaleString()}`;
            const shipping = parseFloat(document.getElementById('calculatedShipping').value) || 0;
            document.getElementById('totalDisplay').innerText = ` ${(subtotal + shipping).toLocaleString()}`;
            
            window.currentSubtotal = subtotal;
            window.currentItems = items;
        }

        window.updateQty = function(itemId, delta) {
            const cart = JSON.parse(localStorage.getItem('bloom_cart') || '{}');
            if (cart[itemId]) {
                cart[itemId].qty += delta;
                if (cart[itemId].qty <= 0) delete cart[itemId];
                localStorage.setItem('bloom_cart', JSON.stringify(cart));
                renderCart();
            }
        };
        renderCart();

        // Dynamic Gift Toggle Logic Matrix
        const giftCheckbox = document.getElementById('isGiftCheckbox');
        const paymentDropdown = document.getElementById('paymentMethod');
        const codOption = document.getElementById('codOption');

        giftCheckbox.addEventListener('change', () => {
            if (giftCheckbox.checked) {
                codOption.disabled = true;
                if (paymentDropdown.value === 'COD') {
                    paymentDropdown.value = 'GCash';
                }
            } else {
                codOption.disabled = false;
            }
        });

        // Real-Time Account Soft-Restriction Listener Engine Hook
        if (userId) {
            db.collection('customers').doc(userId).onSnapshot(doc => {
                if (doc.exists) {
                    const c = doc.data();
                    const banner = document.getElementById('restrictionBannerNotice');
                    const bannerMsg = document.getElementById('restrictionBannerMessage');
                    accountIsRestrictedCurrently = (c.isRestricted === true);
                    if (accountIsRestrictedCurrently) {
                        let remainingDaysText = "for 30 days";
                        if (c.restrictedUntil) {
                            const targetExpiry = c.restrictedUntil.toDate();
                            const rightNow = new Date();
                            const timeDifference = targetExpiry - rightNow;
                            const dynamicDays = Math.ceil(timeDifference / (1000 * 60 * 60 * 24));
                            if (dynamicDays > 0) remainingDaysText = `for the next ${dynamicDays} days`;
                        }

                        if (banner && bannerMsg) {
                            bannerMsg.innerHTML = `This account was restricted ${remainingDaysText}. A verification code will be required to place an order.`;
                            banner.classList.remove('hidden');
                        }
                    } else {
                        if (banner) banner.classList.add('hidden');
                    }
                }
            });
        }

        // Place Order Triggers
        document.getElementById('placeOrderBtn').addEventListener('click', () => {
            const rawPhone = document.getElementById('phone').value.trim();
            if(!rawPhone || !document.getElementById('address').value.trim()) {
                alert("Please complete required tracking and contact entry fields.");
                return;
            }
            if (accountIsRestrictedCurrently && !otpVerifiedThisSession) {
                startSmsVerification(rawPhone);
            } else {
                submitOrder();
            }
        });

        // Checkout Validation & Order Placement
        async function submitOrder() {
            const items = window.currentItems || [];
            const subtotal = window.currentSubtotal || 0;
            if (items.length === 0) return alert('Your cart is empty.');
            
            const btn = document.getElementById('placeOrderBtn');
            const name = document.getElementById('fullName').value;
            const regionBlock = document.getElementById('regionCityBarangay').value;
            const postalCode = document.getElementById('postalCode').value;
            const rawStreet = document.getElementById('address').value;
            const phone = document.getElementById('phone').value;
            const paymentMethod = document.getElementById('paymentMethod').value;
            const shippingFee = parseFloat(document.getElementById('calculatedShipping').value);
            const customerLat = document.getElementById('customerLat').value;
            const customerLng = document.getElementById('customerLng').value;

            if (!name || !phone || !rawStreet || !postalCode || !regionBlock) {
                return alert('Please populate all structured textfields to authenticate delivery logs.');
            }

            const fullStitchedAddress = `${rawStreet}, ${regionBlock}, Postal Code: ${postalCode}`;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Analyzing Security Protocols...';
            
            try {
                // In-Memory Automated Velocity Verification Framework Checks
                let accumulatedScoreBump = 10; 
                let localFraudFlags = [];
                let triggerAutoRestriction = false;
                
                const rightNowMs = Date.now();
                const fiveMinutesAgoMs = rightNowMs - (5 * 60 * 1000); 
                
                const velocityQuery = await db.collection('orders')
                    .where('user_id', '==', userId)
                    .get();

                let hasRecentVelocitySpam = false;
                if (!velocityQuery.empty) {
                    velocityQuery.forEach(oDoc => {
                        const oData = oDoc.data();
                        const orderTimestamp = oData.createdAt || oData.timestamp;
                        if (orderTimestamp) {
                            const orderTimeMs = orderTimestamp.toDate().getTime();
                            if (orderTimeMs >= fiveMinutesAgoMs && orderTimeMs <= rightNowMs) {
                                hasRecentVelocitySpam = true;
                            }
                        }
                    });
                }

                if (hasRecentVelocitySpam) {
                    accumulatedScoreBump += 35; 
                    localFraudFlags.push("Rapid Separated Checkouts Flagged (< 5 min window)");
                    triggerAutoRestriction = true; 
                }

                // Geographical Mismatch Evaluation Rules Engine
                const isGiftChecked = giftCheckbox.checked;
                if (!isGiftChecked && customerLat && customerLng) {
                    const deviceToBranchDistance = calculateDistance(currentBranchLat, currentBranchLng, parseFloat(customerLat), parseFloat(customerLng));
                    if (deviceToBranchDistance > 50) { 
                        accumulatedScoreBump += 45; 
                        localFraudFlags.push("Severe Device-to-Destination Mismatch");
                    }
                }

                const custRef = db.collection('customers').doc(userId);
                let checkAutoBan = false;

                await db.runTransaction(async (transaction) => {
                    const custDoc = await transaction.get(custRef);
                    if (custDoc.exists) {
                        let baseScore = custDoc.data().fraudScore || 0;
                        let ultimateScore = Math.min(100, baseScore + accumulatedScoreBump);
                        
                        let payloadUpdate = { fraudScore: ultimateScore };
                        
                        if (triggerAutoRestriction) {
                            const expiryDate = new Date();
                            expiryDate.setDate(expiryDate.getDate() + 30); 
                            
                            payloadUpdate.isRestricted = true;
                            payloadUpdate.restrictedUntil = firebase.firestore.Timestamp.fromDate(expiryDate);
                            localFraudFlags.push("Automated 30-Day Restriction: Rapid checkout loop velocity limit violated.");
                        }

                        if (ultimateScore >= 100) {
                            checkAutoBan = true; 
                        }

                        if (localFraudFlags.length > 0) {
                            payloadUpdate.fraudFlags = firebase.firestore.FieldValue.arrayUnion(...localFraudFlags);
                        }
                        transaction.update(custRef, payloadUpdate);
                    }
                });

                // --- INTEGRATED TRIGGER: LIVE ALERT ROUTED ON RESTRICTION ACTION ---
                if (triggerAutoRestriction) {
                    await db.collection('notifications').add({
                        title: 'Fraud Alert - Account Restricted',
                        message: `Account [${userId}] was soft-restricted automatically due to rapid checkout loops.`,
                        type: 'fraud',
                        branchId: assignedBranchId,
                        created_at: firebase.firestore.FieldValue.serverTimestamp(),
                        read: false
                    });
                }

                if (checkAutoBan) {
                    const batch = db.batch();
                    batch.update(custRef, { status: "blocked" });
                    if (document.getElementById('email').value) {
                        const blocklistRef = db.collection('blocked_emails').doc(document.getElementById('email').value.toLowerCase());
                        batch.set(blocklistRef, {
                            blockedUid: userId,
                            reason: "Automated mitigation framework lockout: Terminal limit reached.",
                            blockedAt: firebase.firestore.FieldValue.serverTimestamp()
                        });
                    }
                    await batch.commit();

                    // Notify Admins of critical account ban
                    await db.collection('notifications').add({
                        title: 'Security Alert - Account Blocked',
                        message: `Account associated with ${name} reached peak fraud limits and has been blacklisted.`,
                        type: 'warning',
                        branchId: assignedBranchId,
                        created_at: firebase.firestore.FieldValue.serverTimestamp(),
                        read: false
                    });
                }

                if (hasRecentVelocitySpam) {
                    sessionStorage.setItem('bloom_shop_error', "Velocity Check Warning: System detected multiple checkouts processed rapidly. This account has been automatically restricted for 30 days.");
                    window.location.href = 'shop.php';
                    return;
                }

                // Create Order document manifest payload
                const finalTotal = subtotal + shippingFee;
                const generatedOrderId = 'BLM-' + Date.now();
                
                const orderRef = await db.collection('orders').add({
                    user_id: userId,
                    customer_name: name,
                    customerName: name, 
                    recipientName: name,
                    recipientPhone: phone,
                    email: document.getElementById('email').value,
                    address: fullStitchedAddress,
                    phone: normalizePhone(phone), // Commit perfectly normalized telemetry strings
                    payment_method: paymentMethod,
                    items: items,
                    subtotal: subtotal,
                    shipping_fee: shippingFee,
                    total_price: finalTotal,
                    branchId: assignedBranchId,
                    status: 'pending',
                    type: 'WEB',
                    isGift: isGiftChecked,
                    fraudScore: accumulatedScoreBump,
                    fraudFlags: localFraudFlags,
                    timestamp: firebase.firestore.FieldValue.serverTimestamp(),
                    createdAt: firebase.firestore.FieldValue.serverTimestamp()
                });

                // --- INTEGRATED TRIGGER: SUCCESSFUL WEB TRANSACTION BROADCAST ---
                await db.collection('notifications').add({
                    title: 'New Web Order Placed',
                    message: `Order #${generatedOrderId} valued at P${finalTotal.toLocaleString()} received from ${name.trim()}.`,
                    type: 'sale',
                    branchId: assignedBranchId,
                    created_at: firebase.firestore.FieldValue.serverTimestamp(),
                    read: false
                });
                
                localStorage.removeItem('bloom_cart');
                
                if (['GCash', 'PayMaya'].includes(paymentMethod)) {
                    document.getElementById('pm_order_id').value = orderRef.id;
                    document.getElementById('pm_amount').value = finalTotal;
                    document.getElementById('pm_email').value = document.getElementById('email').value;
                    document.getElementById('pm_name').value = name;
                    document.getElementById('pm_method').value = paymentMethod;
                    document.getElementById('paymongoForm').submit();
                } else {
                    window.location.href = '../track_order.php?id=' + orderRef.id + '&success=true';
                }
            } catch (error) {
                if (error.message.includes("permission-denied") || error.code === "permission-denied") {
                    sessionStorage.setItem('bloom_shop_error', "Checkout Blocked: This transaction was rejected by security rules because your account profile carries an active soft restriction profile.");
                    window.location.href = 'shop.php';
                } else {
                    alert("Transaction Failed: " + error.message);
                    btn.disabled = false;
                    btn.innerHTML = 'Place Order';
                }
            }
        }

        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371;
            const dLat = deg2rad(lat2 - lat1);
            const dLon = deg2rad(lon2 - lon1);
            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(deg2rad(lat1)) * Math.cos(deg2rad(lat2)) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
            return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }
        function deg2rad(deg) { return deg * (Math.PI / 180); }

        async function getRealRoadDistance(originLat, originLng, destLat, destLng) {
            if (!GOOGLE_MAPS_KEY) return null;
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
                        "routingPreference": "TRAFFIC_AWARE"
                    })
                });
                const data = await response.json();
                if (data.routes && data.routes.length > 0) return data.routes[0].distanceMeters / 1000;
                return null;
            } catch (err) { return null; }
        }

        // Philippine Standard Geographic Code Picker (PSGC) API Engine
        let selectedRegion = '', selectedProvince = '', selectedCity = '', selectedBarangay = '';
        window.openLocationModal = function() {
            document.getElementById('locationModal').classList.remove('hidden');
            loadRegions();
        }
        window.closeLocationModal = function() {
            document.getElementById('locationModal').classList.add('hidden');
        }

        function updateTabState(activeTab) {
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
                if(btn.id === 'tab-' + activeTab) btn.classList.add('active');
            });
        }

        async function loadRegions() {
            updateTabState('regions');
            const container = document.getElementById('modal-list-container');
            container.innerHTML = '<p class="text-center text-xs text-gray-400 italic py-8">Fetching regions...</p>';
            try {
                const res = await fetch('https://psgc.gitlab.io/api/regions/');
                const data = await res.json();
                let html = '';
                data.sort((a,b) => a.name.localeCompare(b.name)).forEach(reg => {
                    html += `<button type="button" onclick="selectRegion('${reg.code}', '${reg.name}')" class="w-full text-left px-4 py-3 rounded-xl hover:bg-red-50 hover:text-red-500 font-semibold text-sm transition-all text-gray-700">${reg.name}</button>`;
                });
                container.innerHTML = html;
            } catch(e) { container.innerHTML = '<p class="text-center text-xs text-red-400">Error loading data components.</p>'; }
        }

        window.selectRegion = function(code, name) {
            selectedRegion = name;
            document.getElementById('tab-provinces').disabled = false;
            loadProvinces(code);
        }

        async function loadProvinces(regionCode) {
            updateTabState('provinces');
            const container = document.getElementById('modal-list-container');
            container.innerHTML = '<p class="text-center text-xs text-gray-400 italic py-8">Fetching provinces...</p>';
            try {
                let url = `https://psgc.gitlab.io/api/regions/${regionCode}/provinces/`;
                if(regionCode === '130000000') url = `https://psgc.gitlab.io/api/regions/${regionCode}/cities-municipalities/`;
                
                const res = await fetch(url);
                const data = await res.json();
                let html = '';
                
                if(regionCode === '130000000') {
                    selectedProvince = 'Metro Manila';
                    document.getElementById('tab-cities').disabled = false;
                    updateTabState('cities');
                    data.sort((a,b) => a.name.localeCompare(b.name)).forEach(city => {
                        html += `<button type="button" onclick="selectCity('${city.code}', '${city.name}')" class="w-full text-left px-4 py-3 rounded-xl hover:bg-red-50 hover:text-red-500 font-semibold text-sm transition-all text-gray-700">${city.name}</button>`;
                    });
                } else {
                    data.sort((a,b) => a.name.localeCompare(b.name)).forEach(prov => {
                        html += `<button type="button" onclick="selectProvince('${prov.code}', '${prov.name}')" class="w-full text-left px-4 py-3 rounded-xl hover:bg-red-50 hover:text-red-500 font-semibold text-sm transition-all text-gray-700">${prov.name}</button>`;
                    });
                }
                container.innerHTML = html;
            } catch(e) { container.innerHTML = '<p class="text-center text-xs text-red-400">Error processing request.</p>'; }
        }

        window.selectProvince = function(code, name) {
            selectedProvince = name;
            document.getElementById('tab-cities').disabled = false;
            loadCities(code);
        }

        async function loadCities(provCode) {
            updateTabState('cities');
            const container = document.getElementById('modal-list-container');
            container.innerHTML = '<p class="text-center text-xs text-gray-400 italic py-8">Fetching cities...</p>';
            try {
                const res = await fetch(`https://psgc.gitlab.io/api/provinces/${provCode}/cities-municipalities/`);
                const data = await res.json();
                let html = '';
                data.sort((a,b) => a.name.localeCompare(b.name)).forEach(city => {
                    html += `<button type="button" onclick="selectCity('${city.code}', '${city.name}')" class="w-full text-left px-4 py-3 rounded-xl hover:bg-red-50 hover:text-red-500 font-semibold text-sm transition-all text-gray-700">${city.name}</button>`;
                });
                container.innerHTML = html;
            } catch(e) { container.innerHTML = '<p class="text-center text-xs text-red-400">Error loading data parameters.</p>'; }
        }

        window.selectCity = function(code, name) {
            selectedCity = name;
            document.getElementById('tab-barangays').disabled = false;
            loadBarangays(code);
        }

        async function loadBarangays(cityCode) {
            updateTabState('barangays');
            const container = document.getElementById('modal-list-container');
            container.innerHTML = '<p class="text-center text-xs text-gray-400 italic py-8">Fetching barangays...</p>';
            try {
                const res = await fetch(`https://psgc.gitlab.io/api/cities-municipalities/${cityCode}/barangays/`);
                const data = await res.json();
                let html = '';
                data.sort((a,b) => a.name.localeCompare(b.name)).forEach(brgy => {
                    html += `<button type="button" onclick="selectBarangay('${brgy.name}')" class="w-full text-left px-4 py-3 rounded-xl hover:bg-red-50 hover:text-red-500 font-semibold text-sm transition-all text-gray-700">${brgy.name}</button>`;
                });
                container.innerHTML = html;
            } catch(e) { container.innerHTML = '<p class="text-center text-xs text-red-400">Error handling endpoint queries.</p>'; }
        }

        window.selectBarangay = function(name) {
            selectedBarangay = name;
            document.getElementById('regionCityBarangay').value = `${selectedRegion}, ${selectedProvince}, ${selectedCity}, ${selectedBarangay}`;
            closeLocationModal();
        }

        // Branch Tracking & Haversine Distance Logic
        let allBranches = [];
        let nearestBranchId = assignedBranchId;
        const branchesLoadedPromise = db.collection('branches').get().then(snap => {
            snap.forEach(doc => { 
                const d = doc.data();
                allBranches.push({ id: doc.id, name: d.name || doc.id, latitude: d.latitude, longitude: d.longitude });
                if (doc.id === assignedBranchId) {
                    currentBranchLat = d.latitude || currentBranchLat;
                    currentBranchLng = d.longitude || currentBranchLng;
                    currentBranchName = d.name || d.location || assignedBranchId;
                }
            });
        }).catch(() => {});

        // Finds the branch (from the branches loaded from the database) closest to a given point
        function findNearestBranch(lat, lng) {
            let nearest = null;
            let nearestDist = Infinity;
            allBranches.forEach(b => {
                if (typeof b.latitude !== 'number' || typeof b.longitude !== 'number') return;
                const d = calculateDistance(lat, lng, b.latitude, b.longitude);
                if (d < nearestDist) {
                    nearestDist = d;
                    nearest = b;
                }
            });
            return nearest ? { branch: nearest, distance: nearestDist } : null;
        }
    }
</script>
</body>
</html>