<?php
/**
 * BLOOMINOUS - Checkout Page
 * Handles order summary, shipping details, initiates payment, calculates fraud thresholds, and tracks distance anomalies.
 *
 * CUSTOMER INFO FLOW (aligned with BloominousApp's delivery_details_page.dart):
 *   Recipient Information -> Gift Checkout -> Delivery Location (Region /
 *   Province / City / Barangay picker popup, postal auto-fill, street search
 *   with suggestions, GPS auto-fill of ALL location fields) -> Route map +
 *   Nearest Branch -> Order Notes -> Payment Method -> Summary.
 *   The address string, delivery-fee formula, nearest-branch rule and
 *   payment-method values are IDENTICAL on web and app, so orders from both
 *   platforms land in Firestore in one consistent shape.
 *
 * CART SOURCE:
 *   The Order Summary reads the customer's Firestore cart (carts/{uid})
 *   through assets/script/bloom_cart.js — the same service shop.php uses.
 *
 * PAYMENT FLOW (GCash / Maya via PayMongo):
 *   1. submit_order.php creates the order (fraud checks, paymentStatus 'Pending').
 *   2. assets/script/online_payment.js -> create_payment_session.php returns a
 *      PayMongo-hosted GCash/Maya page, and the browser is sent there.
 *   3. PayMongo's webhook (paymongo_webhook.php) marks the order 'Paid'.
 * If step 2 fails or the customer backs out, the created order is remembered
 * in sessionStorage so "Continue Payment" retries THAT order instead of
 * creating a duplicate one.
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
// NOTE: cancel.php's "Try Again" link arrives as checkout.php?resume=1 with no
// amount and an already-cleared cart, so let it through. The JS below then
// checks whether an unpaid GCash/Maya order really exists to resume.
$allowResume = isset($_GET['resume']);
if ($amount <= 0 && empty($items) && !$allowResume) {
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
    <!-- Shared Firestore cart service (must load AFTER the Firebase SDK) -->
    <script src="../assets/script/bloom_cart.js"></script>
    <script src="../assets/script/device_fingerprint.js"></script>
    <!-- GCash / Maya (PayMongo) client helper -->
    <script src="../assets/script/online_payment.js"></script>
    <script>
        // SAFETY NET: if online_payment.js fails to load (missing file, wrong
        // path, 404 on the server), the page must NOT crash. Without this, the
        // first call to bloomIsOnlinePayment() throws "is not a function" and
        // stops the whole checkout script — taking the location picker, map,
        // and branch loading down with it. The `||` keeps the real helper when
        // it did load, and only fills in a stand-in when it didn't.
        window.bloomIsOnlinePayment = window.bloomIsOnlinePayment || function (method) {
            const m = String(method || '').trim().toLowerCase();
            return m === 'gcash' || m === 'maya' || m === 'paymaya';
        };
        window.bloomStartOnlinePayment = window.bloomStartOnlinePayment || async function () {
            console.error('online_payment.js did not load — check assets/script/online_payment.js exists on the server.');
            throw new Error('GCash/Maya payment is temporarily unavailable. Your order is saved — please try again shortly or choose another payment method.');
        };
    </script>

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
            padding: 36px 40px; 
            box-shadow: 0 20px 50px rgba(123, 121, 242, 0.05); 
            border: 1px solid #f0f2f5; 
        }
        .section-title {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #aab;
            margin-bottom: 22px;
        }
        
        .form-group-fieldset {
            position: relative;
            margin-bottom: 24px;
        }
        .form-group-fieldset label.field-label {
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
        .form-fieldset-input.input-error {
            border-color: #ff5b5b;
            box-shadow: 0 0 0 3px rgba(255, 91, 91, 0.1);
        }
        select.form-fieldset-input {
            appearance: none;
            -webkit-appearance: none;
            padding-right: 44px;
            cursor: pointer;
        }
        .select-chevron {
            pointer-events: none;
            position: absolute;
            top: 0;
            bottom: 0;
            right: 16px;
            display: flex;
            align-items: center;
            color: #9ca3af;
            font-size: 0.7rem;
        }

        /* Gift toggle switch (mirrors the app's SwitchListTile) */
        .switch { position: relative; width: 48px; height: 28px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .switch .slider {
            position: absolute; inset: 0; background: #e2e8f0;
            border-radius: 999px; transition: 0.2s; cursor: pointer;
        }
        .switch .slider::before {
            content: ''; position: absolute; width: 22px; height: 22px; left: 3px; top: 3px;
            background: #fff; border-radius: 50%; transition: 0.2s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .switch input:checked + .slider { background: var(--primary); }
        .switch input:checked + .slider::before { transform: translateX(20px); }
        .switch input:focus-visible + .slider { outline: 2px solid var(--primary); outline-offset: 2px; }

        /* Street search suggestions (mirrors the app's suggestion list) */
        .suggestion-list {
            margin-top: -14px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 10px 25px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .suggestion-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            width: 100%;
            text-align: left;
            padding: 10px 14px;
            font-size: 12px;
            color: var(--text-main);
            border-bottom: 1px solid #f1f5f9;
        }
        .suggestion-item:last-child { border-bottom: none; }
        .suggestion-item:hover { background: #f8faff; }

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

        .qty-btn:disabled {
            opacity: 0.4;
            cursor: wait;
        }

        .map-box {
            width: 100%;
            height: 240px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }
        #map-canvas {
            background-color: #e5e7eb;
            position: relative;
            z-index: 0;
            isolation: isolate;
            overflow: hidden;
        }

        /* Location picker popup tabs (original design) */
        .tab-btn.active {
            color: #ff5252;
            border-bottom: 2px solid #ff5252;
        }
        .tab-btn:disabled {
            cursor: not-allowed;
            opacity: 0.6;
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
    <!-- Soft Restriction Notice Banner (switches to "Identity Verified" after OTP, like the app) -->
    <div id="restrictionBannerNotice" class="hidden w-full mb-8 bg-amber-50 border border-amber-200 text-amber-800 p-5 rounded-3xl flex items-center gap-4 shadow-sm">
        <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center text-amber-600 flex-shrink-0 text-lg">
            <i class="fa-solid fa-triangle-exclamation" id="restrictionBannerIcon"></i>
        </div>
        <div>
            <h4 class="font-black uppercase text-xs tracking-wider" id="restrictionBannerTitle">Account Soft Restriction Active</h4>
            <p class="text-xs font-semibold opacity-90 mt-0.5" id="restrictionBannerMessage">This account was restricted for 30 days due to behavioral tracking flags.</p>
        </div>
    </div>

    <!-- Unpaid GCash/Maya Order Notice (shown when an order was created but not yet paid) -->
    <div id="pendingPaymentNotice" class="hidden w-full mb-8 bg-[#eef2ff] border border-[#d9dcff] text-[#363949] p-5 rounded-3xl flex flex-col sm:flex-row sm:items-center gap-4 shadow-sm">
        <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center text-[#7B79F2] flex-shrink-0 text-lg">
            <i class="fa-solid fa-wallet"></i>
        </div>
        <div class="flex-1">
            <h4 class="font-black uppercase text-xs tracking-wider">Order Awaiting Payment</h4>
            <p class="text-xs font-semibold text-[#7d8da1] mt-0.5" id="pendingPaymentMessage">Your order was created but not yet paid. Continue with GCash or Maya to complete it.</p>
        </div>
        <button type="button" id="discardPendingBtn" class="text-[10px] font-black uppercase tracking-widest text-gray-400 hover:text-red-500 transition-colors">
            Start a new order
        </button>
    </div>

    <div class="mb-12">
        <h2 class="text-4xl font-black text-[#363949] uppercase tracking-tighter">Checkout</h2>
        <p class="text-[#7d8da1] font-medium mt-2">Complete your order and bring beauty home</p>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
        <!-- Delivery Details (same section order as the app's DeliveryDetailsPage) -->
        <div class="lg:col-span-2 space-y-6">

            <!-- 1. Recipient Information -->
            <div class="card">
                <h3 class="section-title">Recipient Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="form-group-fieldset">
                        <label class="field-label" for="recipientName">Recipient Name</label>
                        <input type="text" id="recipientName" class="form-fieldset-input" value="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>" autocomplete="name">
                    </div>
                    <div class="form-group-fieldset">
                        <label class="field-label" for="phone">Phone Number</label>
                        <input type="tel" id="phone" class="form-fieldset-input" placeholder="09XX XXX XXXX" inputmode="tel" maxlength="16" autocomplete="tel">
                        <p id="phoneError" class="hidden text-[10px] font-bold text-red-400 mt-1 ml-2">Enter a valid PH mobile number (11 digits, starts with 09).</p>
                    </div>
                </div>
            </div>

            <!-- 2. Gift Checkout -->
            <div class="card">
                <h3 class="section-title">Gift Checkout</h3>
                <label class="flex items-center justify-between gap-4 cursor-pointer">
                    <div>
                        <p class="text-sm font-bold text-[#363949]"><i class="fa-solid fa-gift text-pink-500 mr-1"></i> Send this order as a gift</p>
                        <p id="giftSubtitle" class="text-[11px] text-[#7d8da1] font-medium mt-1">Recipient contact details will be kept secure.</p>
                    </div>
                    <span class="switch">
                        <input type="checkbox" id="isGiftCheckbox">
                        <span class="slider"></span>
                    </span>
                </label>
            </div>

            <!-- 3. Delivery Location -->
            <div class="card">
                <h3 class="section-title">Delivery Location</h3>

                <!-- Original location picker: opens the Region / Province / City / Barangay popup -->
                <div class="form-group-fieldset">
                    <label class="field-label" for="regionCityBarangay">Region, Province, City, Barangay</label>
                    <div class="relative">
                        <input type="text" id="regionCityBarangay" class="form-fieldset-input cursor-pointer pr-10" readonly placeholder="Select Location">
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 text-gray-400 pointer-events-none">
                            <i class="fa-solid fa-chevron-down text-xs"></i>
                        </div>
                    </div>
                </div>

                <div class="form-group-fieldset">
                    <label class="field-label" for="postalCode">Postal Code</label>
                    <input type="text" id="postalCode" class="form-fieldset-input" placeholder="e.g. 3019" inputmode="numeric" maxlength="4">
                </div>

                <div class="form-group-fieldset">
                    <label class="field-label" for="street" id="streetLabel">Street Name, Building, House No.</label>
                    <textarea id="street" rows="2" class="form-fieldset-input resize-none pr-12" placeholder="e.g., 64 Lias Road" autocomplete="off"></textarea>
                    <span id="streetSpinner" class="hidden absolute right-4 top-4 text-[#7B79F2]"><i class="fa-solid fa-spinner fa-spin"></i></span>
                </div>
                <div id="streetSuggestions" class="hidden suggestion-list"></div>

                <div id="recipientConfirmedBadge" class="hidden -mt-3 mb-6 p-3 rounded-xl bg-green-50 text-green-600 text-[11px] font-bold flex items-center gap-2">
                    <i class="fa-solid fa-circle-check"></i> Recipient address confirmed for delivery.
                </div>

                <!-- Route map: branch -> delivery destination -->
                <div id="mapPlaceholder" class="map-box bg-slate-50 flex flex-col items-center justify-center gap-2 text-gray-400">
                    <i class="fa-solid fa-map-location-dot text-4xl text-[#7B79F2]/40"></i>
                    <span id="mapPlaceholderText" class="text-xs font-bold text-center px-6">Your delivery route will appear here</span>
                </div>
                <div id="map-canvas" class="map-box hidden"></div>

                <div id="nearestBranchCard" class="hidden mt-4 p-4 rounded-2xl bg-blue-50/60 border border-blue-100 flex items-center gap-3">
                    <i class="fa-solid fa-store text-blue-500"></i>
                    <div>
                        <p id="nearestBranchName" class="text-sm font-bold text-[#363949]"></p>
                        <p id="nearestBranchDistance" class="text-[11px] font-semibold text-[#7d8da1]"></p>
                    </div>
                </div>

                <button type="button" id="getLocationBtn" class="mt-4 px-5 py-3 bg-[#7B79F2] text-white rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-[#5a58d1] transition-all flex items-center gap-2 shadow-sm disabled:opacity-60 disabled:cursor-wait">
                    <i class="fa-solid fa-location-crosshairs text-xs"></i> Get Current Location
                </button>
            </div>

            <!-- 4. Order Notes -->
            <div class="card">
                <h3 class="section-title">Order Notes</h3>
                <div class="form-group-fieldset !mb-0">
                    <label class="field-label" for="orderNotes">Specific instructions (Optional)</label>
                    <textarea id="orderNotes" rows="3" maxlength="500" class="form-fieldset-input resize-none" placeholder="e.g., Leave at the guardhouse, call upon arrival"></textarea>
                </div>
            </div>

            <!-- 5. Payment Method — values are the shared Bloominous contract: 'gcash' | 'maya' | 'cod' -->
            <div class="card">
                <h3 class="section-title">Payment Method</h3>
                <div class="form-group-fieldset">
                    <label class="field-label" for="paymentMethod">Select Payment Method</label>
                    <select id="paymentMethod" class="form-fieldset-input">
                        <option value="gcash">GCash</option>
                        <option value="maya">Maya</option>
                        <option value="cod" id="codOption">Cash on Delivery</option>
                    </select>
                    <span class="select-chevron"><i class="fa-solid fa-chevron-down"></i></span>
                </div>
                <div id="onlinePaymentHint" class="-mt-3 p-4 rounded-2xl bg-[#f8faff] border border-gray-100 flex items-start gap-3">
                    <i class="fa-solid fa-shield-halved text-[#7B79F2] mt-0.5"></i>
                    <p class="text-[11px] text-[#7d8da1] font-semibold leading-relaxed">
                        After placing your order, you'll be taken to PayMongo's secure page to approve the payment in your
                        <span id="onlinePaymentHintWallet" class="font-black text-[#363949]">GCash</span> app. No screenshots needed — we confirm your payment automatically.
                    </p>
                </div>
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
                        <span class="font-bold text-[#363949]" id="subtotalDisplay">₱0.00</span>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <span class="text-sm font-bold text-[#7d8da1]" id="deliveryFeeLabel">Delivery Fee</span>
                        <span class="font-bold text-[#363949] whitespace-nowrap" id="shippingDisplay">—</span>
                    </div>
                    <div class="flex justify-between items-center pt-4 border-t border-gray-100">
                        <span class="text-lg font-black text-[#363949]">Total</span>
                        <span class="text-2xl font-black text-[#7B79F2]" id="totalDisplay">₱0.00</span>
                    </div>
                </div>
                <button type="button" id="placeOrderBtn" class="btn-checkout mt-10">
                    Place Order &amp; Pay
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Location Selection Dialog Modal Box Area (original design) -->
<div id="locationModal" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm hidden flex items-center justify-center p-4 animate-fade-in">
    <div class="bg-white rounded-3xl w-full max-w-xl overflow-hidden shadow-2xl flex flex-col h-[500px]">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="text-lg font-bold text-gray-800">Select Delivery Location</h3>
            <button type="button" id="closeLocationModalBtn" class="text-gray-400 hover:text-red-500 transition-colors">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        <div class="grid grid-cols-4 text-center border-b border-gray-100 text-sm font-bold text-gray-400 bg-white">
            <button type="button" id="tab-regions" class="tab-btn py-3 active">Region</button>
            <button type="button" id="tab-provinces" class="tab-btn py-3" disabled>Province</button>
            <button type="button" id="tab-cities" class="tab-btn py-3" disabled>City</button>
            <button type="button" id="tab-barangays" class="tab-btn py-3" disabled>Barangay</button>
        </div>
        <div id="modal-list-container" class="flex-1 overflow-y-auto p-4 space-y-1 bg-white">
            <p class="text-center text-xs text-gray-400 italic py-8">Loading geo directories data...</p>
        </div>
    </div>
</div>

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

        <!-- Shown only when linkWithPhoneNumber fails because real SMS isn't
             active yet on this Firebase project (billing not enabled). Lists
             registered test numbers as the current working alternative —
             never auto-selects one. -->
        <div id="billingNotEnabledNotice" class="hidden text-left mt-4 p-4 bg-amber-50 border border-amber-200 rounded-2xl">
            <p class="text-[11px] font-bold text-amber-800 leading-relaxed">Automated SMS isn't active yet for this project.</p>
            <p class="text-[10px] text-amber-700 mt-1 leading-relaxed" id="billingNotEnabledDetail"></p>
        </div>
        
        <div id="recaptcha-container" class="hidden"></div>
    </div>
</div>

<!-- Toast (the web's version of the app's SnackBar) -->
<div id="toast" class="hidden fixed bottom-6 left-1/2 -translate-x-1/2 z-[70] max-w-md w-[calc(100%-2rem)] px-5 py-4 rounded-2xl shadow-2xl text-xs font-bold text-white"></div>

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

        // Resolves once Firebase has restored the signed-in user after a page
        // load. Firestore reads made before this can fail with "permission
        // denied" simply because auth wasn't ready yet.
        const authReady = new Promise(resolve => {
            const unsubscribe = auth.onAuthStateChanged(user => {
                unsubscribe();
                resolve(user);
            });
        });

        // =====================================================================
        // SHARED CONTRACT CONSTANTS — must match delivery_details_page.dart
        // =====================================================================
        const FEE_PER_KM = 1.0;                 // app: static const double feePerKm = 1.0
        const DEFAULT_BRANCH_LAT = 14.7573;     // app's fallback for a branch with no coordinates
        const DEFAULT_BRANCH_LNG = 120.9439;
        const NCR_REGION_CODE = '130000000';    // Metro Manila has no provinces
        const PSGC_BASE = 'https://psgc.gitlab.io/api';
        const NOMINATIM_BASE = 'https://nominatim.openstreetmap.org';

        const POSTAL_CODES = {
            "Meycauayan": "3020",
            "Malolos": "3000",
            "Marilao": "3019",
            "Bacoor City": "4102",
            "Imus City": "4103",
            "Tagaytay City": "4120",
            "Baguio City": "2600",
            "Cebu City": "6000",
            "Mandaue City": "6014",
            "Quezon City": "1100",
            "Manila": "1000",
            "Makati City": "1200",
            "Taguig City": "1630",
            "Pasig City": "1600",
            "Angeles City": "2009",
            "Santa Rosa City": "4026",
            "Calamba City": "4027"
        };

        const CITY_COORDINATES = {
            "Meycauayan": { lat: 14.7410, lng: 120.9634 },
            "Malolos": { lat: 14.8510, lng: 120.8162 },
            "Marilao": { lat: 14.7584, lng: 120.9575 },
            "Bacoor City": { lat: 14.4613, lng: 120.9622 },
            "Imus City": { lat: 14.4294, lng: 120.9367 },
            "Tagaytay City": { lat: 14.1153, lng: 120.9621 },
            "Baguio City": { lat: 16.4164, lng: 120.5930 },
            "Cebu City": { lat: 10.3157, lng: 123.8854 },
            "Mandaue City": { lat: 10.3446, lng: 123.9390 },
            "Quezon City": { lat: 14.6760, lng: 121.0437 },
            "Manila": { lat: 14.5995, lng: 120.9842 },
            "Makati City": { lat: 14.5547, lng: 121.0244 },
            "Taguig City": { lat: 14.5176, lng: 121.0509 },
            "Pasig City": { lat: 14.5764, lng: 121.0851 },
            "Angeles City": { lat: 15.1441, lng: 120.5887 },
            "Santa Rosa City": { lat: 14.3121, lng: 121.0933 },
            "Calamba City": { lat: 14.2128, lng: 121.1649 }
        };

        // =====================================================================
        // DOM REFERENCES (looked up once)
        // =====================================================================
        const recipientInput = document.getElementById('recipientName');
        const phoneInput = document.getElementById('phone');
        const giftCheckbox = document.getElementById('isGiftCheckbox');
        const giftSubtitle = document.getElementById('giftSubtitle');
        const locationInput = document.getElementById('regionCityBarangay');
        const locationModal = document.getElementById('locationModal');
        const modalList = document.getElementById('modal-list-container');
        const locationTabs = {
            regions: document.getElementById('tab-regions'),
            provinces: document.getElementById('tab-provinces'),
            cities: document.getElementById('tab-cities'),
            barangays: document.getElementById('tab-barangays')
        };
        const postalInput = document.getElementById('postalCode');
        const streetInput = document.getElementById('street');
        const streetLabel = document.getElementById('streetLabel');
        const streetSpinner = document.getElementById('streetSpinner');
        const streetSuggestionsBox = document.getElementById('streetSuggestions');
        const recipientConfirmedBadge = document.getElementById('recipientConfirmedBadge');
        const mapPlaceholder = document.getElementById('mapPlaceholder');
        const mapPlaceholderText = document.getElementById('mapPlaceholderText');
        const mapCanvas = document.getElementById('map-canvas');
        const nearestBranchCard = document.getElementById('nearestBranchCard');
        const nearestBranchNameEl = document.getElementById('nearestBranchName');
        const nearestBranchDistanceEl = document.getElementById('nearestBranchDistance');
        const getLocationBtn = document.getElementById('getLocationBtn');
        const notesInput = document.getElementById('orderNotes');
        const paymentDropdown = document.getElementById('paymentMethod');
        const codOption = document.getElementById('codOption');
        const placeOrderBtn = document.getElementById('placeOrderBtn');

        // =====================================================================
        // PAGE STATE — declared BEFORE any function that reads it runs
        // =====================================================================
        let assignedBranchId = localStorage.getItem('bloom_branch_id') || 'main_branch';

        // Restriction / OTP
        let accountIsRestrictedCurrently = false;
        let restrictedUntilText = 'for 30 days';
        let otpVerifiedThisSession = false;

        // Location selection — the popup AND "Get Current Location" both write here
        let selectedRegion = null;        // { code, name }
        let selectedProvince = null;      // { code, name }  (Metro Manila: { code: null, name: 'Metro Manila' })
        let selectedCity = null;          // { code, name }
        let selectedBarangayName = '';
        let isMetroManila = false;
        let activeLocationStep = 'regions';
        let locationStepToken = 0;        // only the newest popup list may render

        // Gift + locations
        let sendAsGift = false;
        let recipientConfirmed = false;   // gift: picked from a street suggestion
        let deviceLat = null;             // REAL device GPS only -> sent as customerLat
        let deviceLng = null;
        let destinationLat = null;        // where the flowers go (fee + map)
        let destinationLng = null;

        // Delivery estimate (mirrors the app's distanceKm / deliveryFee / nearestBranchName)
        let branchLat = null;
        let branchLng = null;
        let nearestBranchName = '';
        let distanceKm = 0;
        let deliveryFee = 0;
        let deliveryReady = false;
        let isUsingRealRoadDistance = false;
        let isCalculatingFee = false;
        let isAutoFillingAddress = false;
        let feeRequestId = 0;             // only the newest fee calculation may update the page

        // Street search
        let streetDebounce = null;
        let streetSearchId = 0;

        // Map
        let leafletMap = null, leafletDestMarker = null, leafletBranchMarker = null, leafletLine = null;

        // Cart mirror
        let cartState = {};
        let cartStatus = 'loading'; // 'loading' | 'ready' | 'error'
        const qtyUpdatesInFlight = new Set();

        // =====================================================================
        // SMALL HELPERS
        // =====================================================================
        let toastTimer = null;
        function showToast(message, tone = 'info') {
            const toast = document.getElementById('toast');
            toast.innerText = message;
            toast.classList.remove('hidden', 'bg-[#363949]', 'bg-red-500', 'bg-emerald-500');
            toast.classList.add(tone === 'error' ? 'bg-red-500' : tone === 'success' ? 'bg-emerald-500' : 'bg-[#363949]');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toast.classList.add('hidden'), 4500);
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, ch => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
            ));
        }

        function formatPeso(amount) {
            return '₱' + Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function roundMoney(amount) {
            return Math.round(amount * 100) / 100;
        }

        function lc(value) {
            return String(value || '').toLowerCase().trim();
        }

        // fetch() with a time limit, like the app's .timeout(Duration(seconds: 8))
        async function fetchWithTimeout(url, ms = 8000, options = {}) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), ms);
            try {
                return await fetch(url, { ...options, signal: controller.signal });
            } finally {
                clearTimeout(timer);
            }
        }

        // PSGC names look like "City of Meycauayan" while the lookup tables use
        // "Meycauayan" / "Bacoor City". Normalizing both sides lets them match.
        function normalizeCityName(name) {
            return lc(name)
                .replace(/^city of\s+/, '')
                .replace(/\s+city$/, '')
                .trim();
        }

        // "Barangay Lias" / "Brgy. Lias" / "Lias" all become "lias".
        function normalizeBarangayName(name) {
            return lc(name)
                .replace(/^(barangay|brgy\.?)\s+/, '')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function lookupByCityName(table, cityName) {
            const target = normalizeCityName(cityName);
            const key = Object.keys(table).find(k => normalizeCityName(k) === target);
            return key ? table[key] : null;
        }

        // =====================================================================
        // ACCOUNT RESTRICTION BANNER (same two states as the app)
        // =====================================================================
        function renderRestrictionBanner() {
            const banner = document.getElementById('restrictionBannerNotice');
            const title = document.getElementById('restrictionBannerTitle');
            const message = document.getElementById('restrictionBannerMessage');
            const icon = document.getElementById('restrictionBannerIcon');

            if (!accountIsRestrictedCurrently) {
                banner.classList.add('hidden');
                return;
            }
            banner.classList.remove('hidden');
            if (otpVerifiedThisSession) {
                banner.classList.remove('animate-pulse');
                icon.className = 'fa-solid fa-circle-check';
                title.innerText = 'Identity Verified';
                message.innerText = "You've verified your identity for this order. You can now place it normally.";
            } else {
                banner.classList.add('animate-pulse');
                icon.className = 'fa-solid fa-triangle-exclamation';
                title.innerText = 'Account Soft Restriction Active';
                message.innerText = `This account was restricted ${restrictedUntilText} due to behavioral tracking flags. A verification code will be required to place an order.`;
            }
        }

        if (userId) {
            db.collection('customers').doc(userId).onSnapshot(doc => {
                if (!doc.exists) return;
                const c = doc.data();
                accountIsRestrictedCurrently = (c.isRestricted === true);
                restrictedUntilText = 'for 30 days';
                if (accountIsRestrictedCurrently && c.restrictedUntil) {
                    const dynamicDays = Math.ceil((c.restrictedUntil.toDate() - new Date()) / (1000 * 60 * 60 * 24));
                    if (dynamicDays > 0) restrictedUntilText = `for the next ${dynamicDays} days`;
                }
                if (!accountIsRestrictedCurrently) otpVerifiedThisSession = false; // same reset as the app
                renderRestrictionBanner();
            }, error => console.warn('Restriction listener error:', error));
        }

        // =====================================================================
        // SMS / OTP IDENTITY VERIFICATION (for soft-restricted accounts)
        // =====================================================================
        let testPhoneNumbers = [];
        db.collection('config').doc('testPhoneNumbers').get().then(doc => {
            if (doc.exists && Array.isArray(doc.data().numbers)) {
                testPhoneNumbers = doc.data().numbers.map(n => n.replace(/\s+/g, ''));
            }
        }).catch(() => { testPhoneNumbers = []; });

        // E.164 (+639XXXXXXXXX) — the format Firebase Phone Auth needs for SMS.
        function normalizePhone(p) { 
            let phone = (p || '').replace(/\D/g, '');
            if (phone.startsWith('0')) {
                phone = '63' + phone.substring(1);
            }
            return '+' + phone;
        }

        // Philippine mobile validator. Accepts "0917 123 4567",
        // "+63 917 123 4567", "639171234567" or "9171234567" and returns the
        // canonical 11-digit local form "09171234567" — or null if it isn't a
        // real PH mobile number. The 11-digit form is what gets stored on the
        // order, so submit_order.php's phone-reuse fraud check compares
        // like-with-like across orders.
        function toLocalPhMobile(p) {
            let digits = (p || '').replace(/\D/g, '');
            if (digits.startsWith('63')) {
                digits = '0' + digits.substring(2);
            } else if (digits.length === 10 && digits.startsWith('9')) {
                digits = '0' + digits;
            }
            return /^09\d{9}$/.test(digits) ? digits : null;
        }

        function validatePhoneField() {
            const errorText = document.getElementById('phoneError');
            const localPhone = toLocalPhMobile(phoneInput.value);
            phoneInput.classList.toggle('input-error', localPhone === null);
            errorText.classList.toggle('hidden', localPhone !== null);
            return localPhone;
        }

        // Live feedback: clear the red state as soon as the number becomes valid.
        phoneInput.addEventListener('input', () => {
            if (phoneInput.classList.contains('input-error')) {
                validatePhoneField();
            }
        });

        let resendTimerInterval = null;
        let pendingConfirmationResult = null;

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
            const phone = normalizePhone(rawPhone);
            const isTestNumber = testPhoneNumbers.includes(phone);

            // Pre-check: catches an obviously disposable/VOIP number BEFORE
            // spending a real SMS credit on it. UX/cost-saving only — the real
            // enforcement lives server-side in submit_order.php (section 3d).
            // Skipped for registered test numbers; fails OPEN on any error.
            if (!isTestNumber) {
                try {
                    const freshToken = await auth.currentUser.getIdToken();
                    const riskResp = await fetch('../check_phone_risk.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': 'Bearer ' + freshToken,
                        },
                        body: JSON.stringify({ phone }),
                    });
                    if (riskResp.ok) {
                        const riskResult = await riskResp.json();
                        if (riskResult.block) {
                            showToast(riskResult.reason || 'This phone number can\'t be used for verification.', 'error');
                            return;
                        }
                    }
                } catch (riskError) {
                    console.warn('Phone risk pre-check failed, proceeding anyway:', riskError);
                }
            }

            const badge = document.getElementById('smsTestModeBadge');
            const subtitle = document.getElementById('smsModalSubtitle');
            const billingNotice = document.getElementById('billingNotEnabledNotice');
            billingNotice.classList.add('hidden');
            if (isTestNumber) {
                badge.classList.remove('hidden');
                subtitle.innerText = 'This is a registered test number — no real SMS will be sent. Use the fixed test code from Firebase Console.';
            } else {
                badge.classList.add('hidden');
                subtitle.innerText = 'Enter the 6-digit code to restore your account trust.';
            }

            try {
                document.getElementById('smsModal').classList.remove('hidden');
                // linkWithPhoneNumber attaches the verified phone to the CURRENT
                // signed-in account (signInWithPhoneNumber would swap identities).
                pendingConfirmationResult = await auth.currentUser.linkWithPhoneNumber(phone, window.recaptchaVerifier);
                startResendTimer();
            } catch (error) {
                // auth/billing-not-enabled = real SMS isn't active for this
                // project yet (Blaze plan). Registered test numbers never hit it.
                if (error.code === 'auth/billing-not-enabled') {
                    const detailEl = document.getElementById('billingNotEnabledDetail');
                    detailEl.innerText = testPhoneNumbers.length > 0
                        ? 'For now, use one of the registered test numbers instead: ' + testPhoneNumbers.join(', ')
                        : 'No test numbers are registered yet — add one under Firebase Console → Authentication → Sign-in method → Phone → "Phone numbers for testing."';
                    billingNotice.classList.remove('hidden');
                } else {
                    showToast("SMS Error: " + error.message, 'error');
                }
            }
        }

        document.getElementById('verifyOtpBtn').addEventListener('click', async () => {
            let code = "";
            document.querySelectorAll('.otp-box').forEach(i => code += i.value);
            if (code.length !== 6) return showToast("Please enter the full 6-digit code.", 'error');
            try {
                await pendingConfirmationResult.confirm(code);
                if (resendTimerInterval) clearInterval(resendTimerInterval);

                // Force a fresh ID token so it reflects the newly-linked phone,
                // then let the server (which checks Auth directly) restore trust.
                const freshToken = await auth.currentUser.getIdToken(true);
                const res = await fetch('../restore_trust.php', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + freshToken }
                });
                const result = await res.json();
                if (!result.success) {
                    showToast(result.message || 'Could not verify phone. Please try again.', 'error');
                    return;
                }

                otpVerifiedThisSession = true;
                renderRestrictionBanner();
                document.getElementById('smsModal').classList.add('hidden');
                showToast('Identity verified! Placing your order...', 'success');
                submitOrder();
            } catch (error) {
                showToast("Verification failed: " + error.message, 'error');
            }
        });

        document.getElementById('resendOtpBtn').addEventListener('click', () => {
            startSmsVerification(phoneInput.value);
        });

        document.getElementById('closeSmsModalBtn').addEventListener('click', () => {
            document.getElementById('smsModal').classList.add('hidden');
            if (resendTimerInterval) clearInterval(resendTimerInterval);
        });

        // =====================================================================
        // CART (Firestore carts/{uid} via assets/script/bloom_cart.js)
        // =====================================================================
        function renderCart() {
            const items = Object.values(cartState);
            const subtotal = BloomCart.total(cartState);
            let itemsHtml = '';

            if (cartStatus === 'loading') {
                itemsHtml = '<p class="text-xs italic text-gray-400 text-center py-4"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Loading your cart...</p>';
            } else if (cartStatus === 'error') {
                itemsHtml = '<p class="text-xs font-bold text-red-400 text-center py-4">We couldn\'t load your cart. Please refresh the page.</p>';
            } else if (items.length === 0) {
                itemsHtml = '<p class="text-xs italic text-gray-400 text-center py-4">Your cart is empty.</p>';
            } else {
                items.forEach(item => {
                    const itemTotal = item.price * item.qty;
                    const busy = qtyUpdatesInFlight.has(item.id) ? 'disabled' : '';
                    itemsHtml += `
                        <div class="order-item group">
                            <div class="flex flex-col flex-1">
                                <span class="text-sm font-bold text-[#363949]">${escapeHtml(item.name)}</span>
                                <div class="flex items-center gap-3 mt-2">
                                    <div class="flex items-center bg-[#f8faff] rounded-xl border border-gray-100 overflow-hidden">
                                        <button type="button" ${busy} onclick="updateQty('${escapeHtml(item.id)}', -1)" class="qty-btn w-8 h-8 flex items-center justify-center text-[#7B79F2] hover:bg-[#7B79F2] hover:text-white transition-all text-xs">
                                            <i class="fa-solid fa-minus"></i>
                                        </button>
                                        <span class="w-8 text-center text-[10px] font-black text-[#363949]">${item.qty}</span>
                                        <button type="button" ${busy} onclick="updateQty('${escapeHtml(item.id)}', 1)" class="qty-btn w-8 h-8 flex items-center justify-center text-[#7B79F2] hover:bg-[#7B79F2] hover:text-white transition-all text-xs">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <span class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">${formatPeso(item.price)} ea</span>
                                </div>
                            </div>
                            <span class="font-black text-[#363949] ml-4">${formatPeso(itemTotal)}</span>
                        </div>
                    `;
                });
            }
            document.getElementById('orderItemsDisplay').innerHTML = itemsHtml;
            document.getElementById('subtotalDisplay').innerText = formatPeso(subtotal);

            // Same label format as the app: "Delivery Fee (4.2km × ₱1)"
            document.getElementById('deliveryFeeLabel').innerText = distanceKm > 0
                ? `Delivery Fee (${distanceKm.toFixed(1)}km × ₱${FEE_PER_KM})`
                : 'Delivery Fee';
            document.getElementById('shippingDisplay').innerText = isCalculatingFee
                ? 'Calculating...'
                : (deliveryReady ? formatPeso(deliveryFee) : '—');
            document.getElementById('totalDisplay').innerText = formatPeso(subtotal + (deliveryReady ? deliveryFee : 0));

            // submitOrder() reads these. Until the cart has really loaded,
            // they stay empty so an order can never be built from a guess.
            window.currentSubtotal = cartStatus === 'ready' ? subtotal : 0;
            window.currentItems = cartStatus === 'ready' ? items : [];
        }

        // +/- buttons. Saves the new quantity to Firestore; 0 removes the item.
        window.updateQty = async function(itemId, delta) {
            const item = cartState[itemId];
            if (!item || qtyUpdatesInFlight.has(itemId)) return;

            qtyUpdatesInFlight.add(itemId);
            renderCart();

            try {
                await BloomCart.setQty(itemId, item.qty + delta);
            } catch (error) {
                console.error('Cart quantity update failed:', error);
                showToast('Could not update your cart. Please check your connection and try again.', 'error');
            } finally {
                qtyUpdatesInFlight.delete(itemId);
                renderCart();
            }
        };

        async function startCartSync() {
            const user = await authReady;
            if (!user) {
                window.location.href = '../index.php';
                return;
            }
            try {
                await BloomCart.migrateLegacy();
            } catch (error) {
                console.warn('Legacy cart migration skipped:', error);
            }
            BloomCart.listen(
                items => {
                    cartState = items;
                    cartStatus = 'ready';
                    renderCart();
                },
                () => {
                    cartStatus = 'error';
                    renderCart();
                }
            );
        }

        // =====================================================================
        // BRANCHES (loaded once; used for nearest-branch + delivery fee)
        // =====================================================================
        const allBranches = [];
        const branchesLoadedPromise = db.collection('branches').get().then(snap => {
            snap.forEach(doc => {
                const d = doc.data();
                allBranches.push({ id: doc.id, name: d.name || doc.id, latitude: d.latitude, longitude: d.longitude });
            });
        }).catch(error => console.warn('Could not load branches:', error));

        // =====================================================================
        // DELIVERY LOCATION — DATA LAYER
        // Selection state + PSGC fetches. BOTH the popup and "Get Current
        // Location" go through these functions, so they always fill the same
        // fields the same way.
        // =====================================================================
        const psgcCache = new Map();

        async function psgcGet(path) {
            if (psgcCache.has(path)) return psgcCache.get(path);
            const res = await fetchWithTimeout(PSGC_BASE + path, 10000);
            if (!res.ok) throw new Error('Location service returned ' + res.status);
            const data = await res.json();
            const items = data
                .map(d => ({ code: d.code, name: d.name }))
                .sort((a, b) => a.name.localeCompare(b.name));
            psgcCache.set(path, items);
            return items;
        }

        const fetchRegions = () => psgcGet('/regions/');
        const fetchProvinces = regionCode => psgcGet(`/regions/${regionCode}/provinces/`);
        const fetchBarangays = cityCode => psgcGet(`/cities-municipalities/${cityCode}/barangays/`);

        // Metro Manila's cities hang directly off the region; everywhere else
        // they hang off the province.
        function fetchCitiesForSelection() {
            return isMetroManila
                ? psgcGet(`/regions/${selectedRegion.code}/cities-municipalities/`)
                : psgcGet(`/provinces/${selectedProvince.code}/cities-municipalities/`);
        }

        // A gift recipient's confirmed pin no longer matches a newly chosen area.
        function onLocationChanged() {
            if (sendAsGift && recipientConfirmed) clearDeliveryEstimate();
        }

        // Shows the chosen parts in the original "Region, Province, City, Barangay" field.
        function updateLocationDisplay() {
            locationInput.value = [
                selectedRegion && selectedRegion.name,
                selectedProvince && selectedProvince.name,
                selectedCity && selectedCity.name,
                selectedBarangayName
            ].filter(Boolean).join(', ');
        }

        function setRegion(region) {
            onLocationChanged();
            selectedRegion = region;
            isMetroManila = region.code === NCR_REGION_CODE;
            selectedProvince = isMetroManila ? { code: null, name: 'Metro Manila' } : null;
            selectedCity = null;
            selectedBarangayName = '';
            updateLocationDisplay();
        }

        function setProvince(province) {
            onLocationChanged();
            selectedProvince = province;
            selectedCity = null;
            selectedBarangayName = '';
            updateLocationDisplay();
        }

        function setCity(city, { recalculateFee = true } = {}) {
            onLocationChanged();
            selectedCity = city;
            selectedBarangayName = '';
            // Auto-fill postal code from the shared lookup table (like the app).
            postalInput.value = lookupByCityName(POSTAL_CODES, city.name) || '';
            updateLocationDisplay();
            if (recalculateFee && !sendAsGift) calculateDeliveryFeeFromGps();
        }

        function setBarangay(name) {
            selectedBarangayName = name;
            updateLocationDisplay();
        }

        // Same format as the app's _updateFullAddress():
        // "street, Brgy. X, City, Province, Region, Postal"
        function buildFullAddress() {
            const parts = [];
            const street = streetInput.value.trim();
            const postal = postalInput.value.trim();
            if (street) parts.push(street);
            if (selectedBarangayName) parts.push('Brgy. ' + selectedBarangayName);
            if (selectedCity) parts.push(selectedCity.name);
            if (selectedProvince) parts.push(selectedProvince.name);
            if (selectedRegion) parts.push(selectedRegion.name);
            if (postal) parts.push(postal);
            return parts.join(', ');
        }

        // =====================================================================
        // DELIVERY LOCATION — POPUP (original Region / Province / City / Barangay tabs)
        // =====================================================================
        function updateLocationTabs() {
            Object.entries(locationTabs).forEach(([step, tab]) => {
                tab.classList.toggle('active', step === activeLocationStep);
            });
            locationTabs.regions.disabled = false;
            locationTabs.provinces.disabled = !selectedRegion || isMetroManila;
            locationTabs.cities.disabled = !(isMetroManila ? selectedRegion : (selectedProvince && selectedProvince.code));
            locationTabs.barangays.disabled = !selectedCity;
        }

        function showModalMessage(text, isError = false) {
            modalList.innerHTML = '';
            const p = document.createElement('p');
            p.className = isError
                ? 'text-center text-xs text-red-400 py-8'
                : 'text-center text-xs text-gray-400 italic py-8';
            p.textContent = text;
            modalList.appendChild(p);
        }

        // Builds the clickable list with real DOM elements (names are shown as
        // plain text, so an apostrophe in a place name can't break anything).
        function renderModalList(items, isSelected, onPick) {
            modalList.innerHTML = '';
            if (items.length === 0) {
                showModalMessage('No locations found here.');
                return;
            }
            items.forEach(item => {
                const button = document.createElement('button');
                button.type = 'button';
                const selected = isSelected(item);
                button.className = 'w-full text-left px-4 py-3 rounded-xl hover:bg-red-50 hover:text-red-500 font-semibold text-sm transition-all '
                    + (selected ? 'bg-red-50 text-red-500' : 'text-gray-700');
                button.textContent = item.name;
                button.addEventListener('click', () => onPick(item));
                modalList.appendChild(button);
            });
        }

        async function showLocationStep(step) {
            activeLocationStep = step;
            updateLocationTabs();
            const token = ++locationStepToken;

            const loadingText = {
                regions: 'Fetching regions...',
                provinces: 'Fetching provinces...',
                cities: 'Fetching cities...',
                barangays: 'Fetching barangays...'
            };
            showModalMessage(loadingText[step]);

            try {
                if (step === 'regions') {
                    const regions = await fetchRegions();
                    if (token !== locationStepToken) return;
                    renderModalList(regions, r => selectedRegion && r.code === selectedRegion.code, region => {
                        setRegion(region);
                        showLocationStep(isMetroManila ? 'cities' : 'provinces');
                    });
                } else if (step === 'provinces') {
                    const provinces = await fetchProvinces(selectedRegion.code);
                    if (token !== locationStepToken) return;
                    renderModalList(provinces, p => selectedProvince && p.code === selectedProvince.code, province => {
                        setProvince(province);
                        showLocationStep('cities');
                    });
                } else if (step === 'cities') {
                    const cities = await fetchCitiesForSelection();
                    if (token !== locationStepToken) return;
                    renderModalList(cities, c => selectedCity && c.code === selectedCity.code, city => {
                        setCity(city);
                        showLocationStep('barangays');
                    });
                } else {
                    const barangays = await fetchBarangays(selectedCity.code);
                    if (token !== locationStepToken) return;
                    renderModalList(barangays, b => b.name === selectedBarangayName, barangay => {
                        setBarangay(barangay.name);
                        closeLocationModal();
                    });
                }
            } catch (error) {
                if (token === locationStepToken) {
                    showModalMessage('Error loading locations. Please check your connection and try again.', true);
                }
            }
        }

        function openLocationModal() {
            locationModal.classList.remove('hidden');
            // Reopen where the customer left off (or where GPS stopped).
            let startStep = 'regions';
            if (selectedCity) startStep = 'barangays';
            else if (selectedRegion && (isMetroManila || selectedProvince)) startStep = 'cities';
            else if (selectedRegion) startStep = 'provinces';
            showLocationStep(startStep);
        }

        function closeLocationModal() {
            locationModal.classList.add('hidden');
        }

        locationInput.addEventListener('click', openLocationModal);
        locationInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openLocationModal();
            }
        });
        document.getElementById('closeLocationModalBtn').addEventListener('click', closeLocationModal);
        locationModal.addEventListener('click', (event) => {
            if (event.target === locationModal) closeLocationModal(); // click on the dark backdrop
        });
        Object.entries(locationTabs).forEach(([step, tab]) => {
            tab.addEventListener('click', () => {
                if (!tab.disabled) showLocationStep(step);
            });
        });

        // =====================================================================
        // STREET SEARCH WITH SUGGESTIONS (mirrors _searchStreetSuggestions)
        // =====================================================================
        streetInput.addEventListener('input', () => {
            // Gift: editing the street by hand un-confirms the recipient pin,
            // so the customer must pick a suggestion again.
            if (sendAsGift && recipientConfirmed) clearDeliveryEstimate();

            clearTimeout(streetDebounce);
            const query = streetInput.value.trim();
            if (query.length < 4) {
                renderStreetSuggestions([]);
                return;
            }
            streetDebounce = setTimeout(() => searchStreetSuggestions(query), 600);
        });

        async function searchStreetSuggestions(query) {
            const searchId = ++streetSearchId;
            streetSpinner.classList.remove('hidden');
            try {
                const cityContext = selectedCity ? ', ' + selectedCity.name : '';
                const url = `${NOMINATIM_BASE}/search?format=jsonv2&addressdetails=0&limit=5&countrycodes=ph&q=`
                    + encodeURIComponent(`${query}${cityContext}, Philippines`);
                const res = await fetchWithTimeout(url, 8000);
                if (!res.ok || searchId !== streetSearchId) return;

                const data = await res.json();
                if (searchId !== streetSearchId) return; // a newer search already started

                const suggestions = data
                    .map(e => ({ displayName: e.display_name, lat: parseFloat(e.lat), lng: parseFloat(e.lon) }))
                    .filter(s => s.displayName && !isNaN(s.lat) && !isNaN(s.lng));
                renderStreetSuggestions(suggestions);
            } catch (error) {
                console.warn('Address search failed:', error);
            } finally {
                if (searchId === streetSearchId) streetSpinner.classList.add('hidden');
            }
        }

        function renderStreetSuggestions(suggestions) {
            streetSuggestionsBox.innerHTML = '';
            if (suggestions.length === 0) {
                streetSuggestionsBox.classList.add('hidden');
                return;
            }
            suggestions.forEach(s => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'suggestion-item';

                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-location-dot text-[#7B79F2] mt-0.5';

                const text = document.createElement('span');
                text.textContent = s.displayName; // textContent = never interpreted as HTML

                button.append(icon, text);
                button.addEventListener('click', () => selectStreetSuggestion(s));
                streetSuggestionsBox.appendChild(button);
            });
            streetSuggestionsBox.classList.remove('hidden');
        }

        async function selectStreetSuggestion(suggestion) {
            streetInput.value = suggestion.displayName.split(',')[0];
            renderStreetSuggestions([]);

            if (sendAsGift) {
                recipientConfirmed = true;
                recipientConfirmedBadge.classList.remove('hidden');
                await calculateDeliveryFeeForDestination(suggestion.lat, suggestion.lng);
            }
        }

        // Close the suggestion list when clicking anywhere else.
        document.addEventListener('click', (event) => {
            if (!streetSuggestionsBox.contains(event.target) && event.target !== streetInput) {
                streetSuggestionsBox.classList.add('hidden');
            }
        });

        // =====================================================================
        // GET CURRENT LOCATION — fills street, postal AND
        // Region / Province / City / Barangay, then calculates the fee.
        // =====================================================================
        function updateLocationButton() {
            const busy = isAutoFillingAddress || isCalculatingFee;
            getLocationBtn.disabled = busy;
            getLocationBtn.innerHTML = busy
                ? '<i class="fa-solid fa-spinner fa-spin text-xs"></i> Locating...'
                : '<i class="fa-solid fa-location-crosshairs text-xs"></i> Get Current Location';
        }

        getLocationBtn.addEventListener('click', handleGetCurrentLocation);

        async function handleGetCurrentLocation() {
            if (!navigator.geolocation) {
                showToast('Geolocation is not supported by this browser.', 'error');
                return;
            }
            isAutoFillingAddress = true;
            updateLocationButton();

            try {
                const position = await new Promise((resolve, reject) =>
                    navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 8000 })
                );
                deviceLat = position.coords.latitude;
                deviceLng = position.coords.longitude;

                if (sendAsGift) {
                    showToast("Your location has been recorded for verification. Please search and select the RECIPIENT's address below.");
                    return;
                }

                const geocoded = await reverseGeocode(deviceLat, deviceLng);
                if (geocoded) {
                    await applyReverseGeocodedAddress(geocoded);
                } else {
                    showToast("We couldn't look up your address from GPS. Please tap the location field to select it.");
                }

                await calculateDeliveryFeeFromGps();
            } catch (error) {
                const message = error && error.code === 1
                    ? 'Location permission was denied. Please allow location access in your browser settings.'
                    : 'Could not determine location: ' + (error && error.message ? error.message : error);
                showToast(message, 'error');
            } finally {
                isAutoFillingAddress = false;
                updateLocationButton();
            }
        }

        async function reverseGeocode(lat, lng) {
            try {
                const res = await fetchWithTimeout(
                    `${NOMINATIM_BASE}/reverse?format=jsonv2&lat=${lat}&lon=${lng}&addressdetails=1`, 8000
                );
                if (!res.ok) return null;
                return await res.json();
            } catch (error) {
                console.warn('Reverse geocode failed:', error);
                return null;
            }
        }

        // Walks Region -> Province -> City -> Barangay using the GPS address.
        // Returns the deepest level it managed to fill: null | 'region' |
        // 'province' | 'city' | 'barangay'.
        async function autoSelectLocation(addr) {
            // OpenStreetMap stores PH areas under different keys depending on
            // the place, so every candidate name is tried at each level.
            const areaNames = [addr.region, addr.state, addr.state_district, addr.province, addr.county]
                .filter(Boolean)
                .map(lc);
            const cityGuess = addr.city || addr.town || addr.municipality || '';
            const barangayGuess = addr.suburb || addr.village || addr.quarter || addr.neighbourhood || addr.hamlet || '';

            // 1. Region
            const regions = await fetchRegions();
            const region = regions.find(r => r.code === NCR_REGION_CODE
                ? areaNames.some(n => n.includes('metro manila') || n.includes('national capital') || n === 'ncr')
                : areaNames.some(n => n.length >= 4 && lc(r.name).includes(n)));
            if (!region) return null;
            setRegion(region);

            // 2. Province (Metro Manila has none)
            if (!isMetroManila) {
                const provinces = await fetchProvinces(region.code);
                const province = provinces.find(p =>
                    areaNames.some(n => n === lc(p.name) || (n.length >= 4 && lc(p.name).includes(n)))
                );
                if (!province) return 'region';
                setProvince(province);
            }

            // 3. City
            if (!cityGuess) return isMetroManila ? 'region' : 'province';
            const cityTarget = normalizeCityName(cityGuess);
            const cities = await fetchCitiesForSelection();
            const city = cities.find(c => normalizeCityName(c.name) === cityTarget)
                || cities.find(c => {
                    const n = normalizeCityName(c.name);
                    return n.length >= 4 && (n.includes(cityTarget) || cityTarget.includes(n));
                });
            if (!city) return isMetroManila ? 'region' : 'province';
            setCity(city, { recalculateFee: false }); // the GPS flow calculates the fee itself afterwards

            // 4. Barangay
            if (!barangayGuess) return 'city';
            const barangayTarget = normalizeBarangayName(barangayGuess);
            const barangays = await fetchBarangays(city.code);
            const barangay = barangays.find(b => normalizeBarangayName(b.name) === barangayTarget)
                || barangays.find(b => {
                    const n = normalizeBarangayName(b.name);
                    return n.length >= 4 && barangayTarget.length >= 4 && (n.includes(barangayTarget) || barangayTarget.includes(n));
                });
            if (!barangay) return 'city';
            setBarangay(barangay.name);
            return 'barangay';
        }

        async function applyReverseGeocodedAddress(geocoded) {
            const addr = geocoded.address || {};

            if (addr.road) {
                streetInput.value = addr.house_number ? `${addr.house_number} ${addr.road}` : addr.road;
            }

            let reached = null;
            try {
                reached = await autoSelectLocation(addr);
            } catch (error) {
                console.warn('Could not auto-select the location:', error);
            }

            // GPS postcode is more precise than the city table, so it wins.
            if (addr.postcode) postalInput.value = addr.postcode;

            if (reached === 'barangay') {
                showToast('Address filled in from your location — please double-check it.', 'success');
            } else if (reached) {
                showToast('We filled in what we could. Tap the location field to confirm the rest.');
            } else {
                showToast('Detected your general area — please select Region, Province, City and Barangay manually.');
            }
        }

        // =====================================================================
        // DELIVERY FEE (mirrors _calculateDeliveryFeeFromGps / ForDestination)
        // =====================================================================
        async function geocodeSelectedCity() {
            try {
                const place = [
                    selectedCity && selectedCity.name,
                    selectedProvince && selectedProvince.name,
                    'Philippines'
                ].filter(Boolean).join(', ');
                const res = await fetchWithTimeout(
                    `${NOMINATIM_BASE}/search?format=jsonv2&limit=1&countrycodes=ph&q=${encodeURIComponent(place)}`, 8000
                );
                if (!res.ok) return null;
                const data = await res.json();
                if (!data.length) return null;
                const lat = parseFloat(data[0].lat), lng = parseFloat(data[0].lon);
                return isNaN(lat) || isNaN(lng) ? null : { lat, lng };
            } catch (error) {
                console.warn('City geocode failed:', error);
                return null;
            }
        }

        // Non-gift: destination = device GPS, else the city's known center,
        // else the city looked up online.
        async function calculateDeliveryFeeFromGps() {
            let destination = null;
            if (deviceLat !== null && deviceLng !== null) {
                destination = { lat: deviceLat, lng: deviceLng };
            } else if (selectedCity) {
                destination = lookupByCityName(CITY_COORDINATES, selectedCity.name) || await geocodeSelectedCity();
            }

            if (!destination) {
                showToast('Tap "Get Current Location" or select your city to determine the delivery fee.', 'error');
                return;
            }
            await calculateDeliveryFeeForDestination(destination.lat, destination.lng);
        }

        // Same multipliers as the app when Google road distance isn't available.
        function estimateRoadKm(straightLineKm) {
            if (straightLineKm < 3) return straightLineKm * 1.3;
            if (straightLineKm < 15) return straightLineKm * 1.8;
            return straightLineKm * 3.1;
        }

        async function calculateDeliveryFeeForDestination(destLat, destLng) {
            const requestId = ++feeRequestId;
            isCalculatingFee = true;
            updateLocationButton();
            renderCart();

            try {
                await branchesLoadedPromise;
                if (allBranches.length === 0) throw new Error('No branches found to calculate delivery.');

                // Nearest branch by straight-line distance
                let nearest = null;
                let minDistanceKm = Infinity;
                allBranches.forEach(b => {
                    const bLat = typeof b.latitude === 'number' ? b.latitude : DEFAULT_BRANCH_LAT;
                    const bLng = typeof b.longitude === 'number' ? b.longitude : DEFAULT_BRANCH_LNG;
                    const d = calculateDistance(bLat, bLng, destLat, destLng);
                    if (d < minDistanceKm) {
                        minDistanceKm = d;
                        nearest = { id: b.id, name: b.name, lat: bLat, lng: bLng };
                    }
                });

                // Real road distance when a Google key is configured, else the estimate
                const roadKm = await getRealRoadDistance(nearest.lat, nearest.lng, destLat, destLng);
                const km = roadKm !== null ? roadKm : estimateRoadKm(minDistanceKm);

                if (requestId !== feeRequestId) return; // a newer calculation replaced this one

                nearestBranchName = nearest.name;
                assignedBranchId = nearest.id;
                branchLat = nearest.lat;
                branchLng = nearest.lng;
                destinationLat = destLat;
                destinationLng = destLng;
                distanceKm = km;
                isUsingRealRoadDistance = roadKm !== null;
                deliveryFee = roundMoney(km * FEE_PER_KM);
                deliveryReady = true;

                renderNearestBranch();
                renderRouteMap();
            } catch (error) {
                if (requestId === feeRequestId) {
                    showToast('Could not calculate the delivery fee: ' + error.message, 'error');
                }
            } finally {
                if (requestId === feeRequestId) {
                    isCalculatingFee = false;
                    updateLocationButton();
                    renderCart();
                }
            }
        }

        // Forget the current estimate (gift toggle, recipient address changed, ...)
        function clearDeliveryEstimate() {
            feeRequestId++; // any calculation still running is now ignored
            isCalculatingFee = false;
            recipientConfirmed = false;
            recipientConfirmedBadge.classList.add('hidden');
            nearestBranchName = '';
            destinationLat = null;
            destinationLng = null;
            distanceKm = 0;
            deliveryFee = 0;
            deliveryReady = false;
            isUsingRealRoadDistance = false;
            renderNearestBranch();
            renderRouteMap();
            updateLocationButton();
            renderCart();
        }

        function renderNearestBranch() {
            if (!nearestBranchName) {
                nearestBranchCard.classList.add('hidden');
                return;
            }
            nearestBranchCard.classList.remove('hidden');
            nearestBranchNameEl.innerText = 'Nearest Branch: ' + nearestBranchName;
            nearestBranchDistanceEl.innerText = (isUsingRealRoadDistance ? 'Google Maps Road Distance: ' : 'Est. Road Distance: ')
                + distanceKm.toFixed(1) + ' KM';
            nearestBranchDistanceEl.className = isUsingRealRoadDistance
                ? 'text-[11px] font-bold text-green-600'
                : 'text-[11px] font-semibold text-[#7d8da1]';
        }

        // =====================================================================
        // ROUTE MAP (branch -> destination), Leaflet / OpenStreetMap
        // =====================================================================
        function renderRouteMap() {
            const hasRoute = destinationLat !== null && destinationLng !== null && branchLat !== null && branchLng !== null;
            if (!hasRoute) {
                mapCanvas.classList.add('hidden');
                mapPlaceholder.classList.remove('hidden');
                mapPlaceholderText.innerText = sendAsGift
                    ? "Search and select the recipient's address to see the route"
                    : 'Your delivery route will appear here';
                return;
            }

            mapPlaceholder.classList.add('hidden');
            mapCanvas.classList.remove('hidden');

            if (!leafletMap) {
                leafletMap = L.map(mapCanvas, { zoomControl: true, attributionControl: true });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(leafletMap);
            }

            const destinationPoint = [destinationLat, destinationLng];
            const branchPoint = [branchLat, branchLng];

            if (leafletDestMarker) leafletMap.removeLayer(leafletDestMarker);
            if (leafletBranchMarker) leafletMap.removeLayer(leafletBranchMarker);
            if (leafletLine) leafletMap.removeLayer(leafletLine);

            leafletDestMarker = L.marker(destinationPoint).addTo(leafletMap)
                .bindPopup(sendAsGift ? 'Recipient' : 'Delivery location');
            leafletBranchMarker = L.marker(branchPoint).addTo(leafletMap).bindPopup(nearestBranchName || 'Branch');
            leafletLine = L.polyline([destinationPoint, branchPoint], { color: '#7B79F2', weight: 3, dashArray: '6 6' }).addTo(leafletMap);

            // Leaflet needs a resize nudge because the container was just un-hidden
            setTimeout(() => {
                leafletMap.invalidateSize();
                leafletMap.fitBounds(leafletLine.getBounds(), { padding: [40, 40] });
            }, 100);
        }

        // =====================================================================
        // GIFT TOGGLE (mirrors the app's SwitchListTile onChanged)
        // =====================================================================
        giftCheckbox.addEventListener('change', () => {
            sendAsGift = giftCheckbox.checked;
            clearDeliveryEstimate();

            codOption.disabled = sendAsGift;
            codOption.text = sendAsGift ? 'Cash on Delivery (unavailable for gifts)' : 'Cash on Delivery';
            if (sendAsGift && paymentDropdown.value === 'cod') paymentDropdown.value = 'gcash';

            giftSubtitle.innerText = sendAsGift
                ? "You'll need to search and confirm the recipient's exact address below. Cash on Delivery is unavailable for gifts."
                : 'Recipient contact details will be kept secure.';
            streetLabel.innerText = sendAsGift
                ? "Search recipient's Street / Building / House No."
                : 'Street Name, Building, House No.';
            getLocationBtn.classList.toggle('hidden', sendAsGift);

            updatePaymentHint();
            refreshPlaceOrderLabel();

            // Switching back to a normal order: re-estimate from what we already know.
            if (!sendAsGift && (deviceLat !== null || selectedCity)) {
                calculateDeliveryFeeFromGps();
            }
        });

        // =====================================================================
        // ONLINE PAYMENT STATE (GCash / Maya)
        // An order that was CREATED but not yet PAID is remembered here, so the
        // button retries payment for that same order instead of creating a
        // second one. sessionStorage survives a refresh and the round-trip to
        // PayMongo's cancel page, but not closing the tab.
        // =====================================================================
        const PENDING_ORDER_KEY = 'bloom_pending_online_order';
        let pendingOnlineOrder = null; // { orderId, invoiceId, method, total }

        function savePendingOnlineOrder(order) {
            pendingOnlineOrder = order;
            try { sessionStorage.setItem(PENDING_ORDER_KEY, JSON.stringify(order)); } catch (_) {}
            showPendingNotice();
            refreshPlaceOrderLabel();
        }

        function clearPendingOnlineOrder() {
            pendingOnlineOrder = null;
            try { sessionStorage.removeItem(PENDING_ORDER_KEY); } catch (_) {}
            document.getElementById('pendingPaymentNotice').classList.add('hidden');
            refreshPlaceOrderLabel();
        }

        function showPendingNotice() {
            if (!pendingOnlineOrder) return;
            const label = pendingOnlineOrder.invoiceId || pendingOnlineOrder.orderId;
            const totalText = typeof pendingOnlineOrder.total === 'number'
                ? ` (${formatPeso(pendingOnlineOrder.total)})`
                : '';
            document.getElementById('pendingPaymentMessage').innerText =
                `Order ${label}${totalText} was created but isn't paid yet. Tap "Continue Payment" to finish with GCash or Maya.`;
            document.getElementById('pendingPaymentNotice').classList.remove('hidden');
        }

        // One place decides the button's words.
        function refreshPlaceOrderLabel() {
            if (placeOrderBtn.disabled) return;
            if (pendingOnlineOrder) {
                placeOrderBtn.innerHTML = 'Continue Payment';
            } else if (window.bloomIsOnlinePayment(paymentDropdown.value)) {
                placeOrderBtn.innerHTML = 'Place Order &amp; Pay';
            } else {
                placeOrderBtn.innerHTML = 'Place Order';
            }
        }

        function updatePaymentHint() {
            const hint = document.getElementById('onlinePaymentHint');
            const isOnline = window.bloomIsOnlinePayment(paymentDropdown.value);
            hint.classList.toggle('hidden', !isOnline);
            document.getElementById('onlinePaymentHintWallet').innerText =
                paymentDropdown.value === 'maya' ? 'Maya' : 'GCash';
        }

        function setButtonBusy(text) {
            placeOrderBtn.disabled = true;
            placeOrderBtn.innerHTML = `<i class="fa-solid fa-spinner fa-spin mr-2"></i> ${text}`;
        }

        function setButtonReady() {
            placeOrderBtn.disabled = false;
            refreshPlaceOrderLabel();
        }

        // Hands an EXISTING order to PayMongo. Used right after the order is
        // created, and again by "Continue Payment" on retry.
        async function startOnlinePaymentFor(orderId) {
            setButtonBusy('Opening ' + (paymentDropdown.value === 'maya' ? 'Maya' : 'GCash') + '...');
            try {
                const idToken = await auth.currentUser.getIdToken();
                await window.bloomStartOnlinePayment({
                    orderId: orderId,
                    paymentMethod: paymentDropdown.value,
                    idToken: idToken
                });
                // Success: the browser is now leaving for PayMongo, so the
                // button deliberately stays disabled (no double-clicks).
            } catch (error) {
                showToast(error.message || 'Could not start your payment. Please try again.', 'error');
                setButtonReady();
            }
        }

        // On page load: is there an unpaid order from earlier in this tab?
        // Double-check it with Firestore before offering "Continue Payment".
        async function restorePendingOnlineOrder() {
            let saved = null;
            try { saved = JSON.parse(sessionStorage.getItem(PENDING_ORDER_KEY) || 'null'); } catch (_) {}
            if (!saved || !saved.orderId) return;

            try {
                await authReady;
                const snap = await db.collection('orders').doc(saved.orderId).get();
                const order = snap.exists ? snap.data() : null;
                const stillPayable = order
                    && order.paymentStatus !== 'Paid'
                    && String(order.status || '').toLowerCase() === 'pending';

                if (!stillPayable) {
                    clearPendingOnlineOrder();
                    return;
                }

                paymentDropdown.value = window.bloomIsOnlinePayment(saved.method) ? saved.method : 'gcash';
                saved.total = typeof order.total_price === 'number' ? order.total_price : saved.total;
                savePendingOnlineOrder(saved);
                updatePaymentHint();
            } catch (error) {
                console.warn('Could not check the pending online order:', error);
            }
        }

        document.getElementById('discardPendingBtn').addEventListener('click', () => {
            if (confirm('Start a new order? The unpaid order will stay in My Orders as pending.')) {
                clearPendingOnlineOrder();
            }
        });

        paymentDropdown.addEventListener('change', () => {
            // An unpaid GCash/Maya order can switch between GCash <-> Maya,
            // but can't silently become COD (it's already an online order).
            if (pendingOnlineOrder && !window.bloomIsOnlinePayment(paymentDropdown.value)) {
                showToast('This order was placed for online payment. You can switch between GCash and Maya, or tap "Start a new order" to pay another way.', 'error');
                paymentDropdown.value = pendingOnlineOrder.method || 'gcash';
            }
            if (pendingOnlineOrder) {
                pendingOnlineOrder.method = paymentDropdown.value;
                try { sessionStorage.setItem(PENDING_ORDER_KEY, JSON.stringify(pendingOnlineOrder)); } catch (_) {}
            }
            updatePaymentHint();
            refreshPlaceOrderLabel();
        });

        // =====================================================================
        // VALIDATION + PLACE ORDER (mirrors _handlePlaceOrderTapped / _processCheckout)
        // =====================================================================
        // Returns an error message, or null when everything is ready.
        function validateCheckoutForm() {
            if (!recipientInput.value.trim()) {
                return 'Please enter the recipient name.';
            }
            if (validatePhoneField() === null) {
                return 'Please enter a valid PH mobile number (11 digits, starts with 09).';
            }
            if (!selectedRegion || !selectedCity || !selectedBarangayName || !streetInput.value.trim()) {
                return 'Please fill in all recipient, contact and location choices.';
            }
            if (sendAsGift && !recipientConfirmed) {
                return "Please search and select the recipient's exact delivery address using the suggestions below the street field.";
            }
            if (sendAsGift && paymentDropdown.value === 'cod') {
                return "Cash on Delivery isn't available for gift orders. Please choose GCash or Maya.";
            }
            if (isCalculatingFee) {
                return 'Still calculating your delivery fee — one moment.';
            }
            if (!deliveryReady) {
                return sendAsGift
                    ? "Please select the recipient's address from the suggestions so we can calculate the delivery fee."
                    : 'Tap "Get Current Location" or select your city so we can calculate the delivery fee.';
            }
            return null;
        }

        placeOrderBtn.addEventListener('click', () => {
            // Retry path: the order already exists — only redo the payment step.
            if (pendingOnlineOrder) {
                startOnlinePaymentFor(pendingOnlineOrder.orderId);
                return;
            }

            const formError = validateCheckoutForm();
            if (formError) {
                showToast(formError, 'error');
                if (phoneInput.classList.contains('input-error')) phoneInput.focus();
                return;
            }

            if (accountIsRestrictedCurrently && !otpVerifiedThisSession) {
                startSmsVerification(phoneInput.value.trim());
            } else {
                submitOrder();
            }
        });

        async function submitOrder() {
            if (cartStatus !== 'ready') {
                return showToast('Your cart is still loading. Please wait a moment and try again.', 'error');
            }
            const items = window.currentItems || [];
            const subtotal = window.currentSubtotal || 0;
            if (items.length === 0) return showToast('Your cart is empty.', 'error');

            const formError = validateCheckoutForm();
            if (formError) return showToast(formError, 'error');

            const localPhone = validatePhoneField();
            const paymentMethod = paymentDropdown.value; // 'gcash' | 'maya' | 'cod'
            const shippingFee = deliveryFee;
            const finalTotal = subtotal + shippingFee;

            setButtonBusy('Analyzing Security Protocols...');

            try {
                // Fraud scoring, the restriction gate, and the order/customer
                // writes all happen server-side in submit_order.php.
                const idToken = await auth.currentUser.getIdToken();
                const deviceHash = await window.bloomGetDeviceId();

                const response = await fetch('../submit_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + idToken
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        name: recipientInput.value.trim(),
                        email: <?php echo json_encode($_SESSION['email'] ?? ''); ?>,
                        address: buildFullAddress(),
                        phone: localPhone,
                        paymentMethod: paymentMethod,
                        items: items,
                        subtotal: subtotal,
                        shippingFee: shippingFee,
                        branchId: assignedBranchId,
                        isGift: sendAsGift,
                        customerLat: deviceLat,   // real device GPS only (fraud signal), or null
                        customerLng: deviceLng,
                        otpVerified: otpVerifiedThisSession,
                        deviceHash: deviceHash,
                        notes: notesInput.value.trim()
                    })
                });

                // Guard against a PHP error page (HTML) instead of JSON.
                let result = {};
                try { result = await response.json(); } catch (_) { result = {}; }

                if (!result.success) {
                    if (result.code === 'RESTRICTED' || result.code === 'BLOCKED') {
                        sessionStorage.setItem('bloom_shop_error', result.message);
                        window.location.href = 'shop.php';
                        return;
                    }
                    showToast(result.message || 'Transaction failed.', 'error');
                    setButtonReady();
                    return;
                }

                // The order exists on the server, so the cart has done its job.
                // Best-effort: a failed clear must never block the payment step.
                try {
                    await BloomCart.clear();
                } catch (clearError) {
                    console.warn('Order placed, but the cart could not be cleared:', clearError);
                }

                if (window.bloomIsOnlinePayment(paymentMethod)) {
                    savePendingOnlineOrder({
                        orderId: result.orderId,
                        invoiceId: result.invoiceId || '',
                        method: paymentMethod,
                        total: finalTotal
                    });
                    placeOrderBtn.disabled = false; // let startOnlinePaymentFor manage it
                    await startOnlinePaymentFor(result.orderId);
                } else {
                    window.location.href = '../track_order.php?id=' + result.orderId + '&success=true';
                }
            } catch (error) {
                showToast("Transaction Failed: " + error.message, 'error');
                setButtonReady();
            }
        }

        // =====================================================================
        // DISTANCE HELPERS
        // =====================================================================
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
                const response = await fetchWithTimeout('https://routes.googleapis.com/v2:computeRoutes', 8000, {
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

        // =====================================================================
        // START-UP
        // =====================================================================
        renderCart();          // first paint: "Loading your cart..."
        renderRouteMap();      // placeholder until a route exists
        updatePaymentHint();
        refreshPlaceOrderLabel();
        fetchRegions().catch(() => {}); // warm the cache so the popup opens instantly
        startCartSync();
        restorePendingOnlineOrder();
    }
</script>
</body>
</html>