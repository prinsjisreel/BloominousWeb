<?php
/**
 * BLOOMINOUS - Customer Shop Spoke
 *
 * Public storefront:
 *  - Guests (no session) can browse every product, search, and open a
 *    product profile (Shopee-style detail view with ratings).
 *  - Account-only actions (Add to Cart, Buy Now, Cart/Checkout, My Orders)
 *    send guests to login first via assets/script/guest_gate.js.
 *  - After login, the customer lands back here and any pending
 *    "add to cart" / "view orders" action is finished automatically.
 *  - The cart lives in Firestore (carts/{uid}) through
 *    assets/script/bloom_cart.js — the same contract BloominousApp uses.
 *  - Ratings are read from the top-level `reviews` collection, written by
 *    my_orders.php (web) and BloominousApp after an order is received.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Who is looking at the shop? null = guest.
// NOTE: no redirect here anymore — browsing is public on purpose.
$user_id = $_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? null;
$isLoggedIn = $user_id !== null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop | Bloominous</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Firebase SDK -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>
    <!-- Shared guest gate (login-before-cart helper) -->
    <script src="../assets/script/guest_gate.js"></script>
    <!-- Shared Firestore cart service (same data contract as BloominousApp) -->
    <script src="../assets/script/bloom_cart.js"></script>
    <style>
        :root {
            --shop-accent: #f97316;   /* BLOOM orange (nav, stars, highlights) */
            --shop-price: #4f46e5;    /* indigo price, same as the cards */
            --shop-btn: #7380ec;      /* Add to Cart purple-blue */
            --shop-btn-hover: #5a65c1;
            --shop-border: #f1ede4;
            --shop-soft: #fcf9f2;
        }
        body { font-family: 'Poppins', sans-serif; background-color: #fcf9f2; }
        .shop-container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .product-card { background: #fff; border-radius: 20px; overflow: hidden; transition: 0.3s; box-shadow: 0 10px 30px rgba(0,0,0,0.05); cursor: pointer; }
        .product-card:hover { transform: translateY(-10px); }
        .product-img { width: 100%; height: 200px; object-fit: cover; }
        .product-info { padding: 20px; }
        .btn-add { width: 100%; padding: 12px; background: var(--shop-btn); color: #fff; border-radius: 12px; font-weight: 800; text-transform: uppercase; font-size: 0.75rem; border: none; cursor: pointer; transition: 0.3s; }
        .btn-add:hover { background: var(--shop-btn-hover); }
        .card-rating { color: var(--shop-accent); font-size: 0.65rem; font-weight: 800; }
        .card-rating span { color: #9ca3af; font-weight: 600; margin-left: 4px; }

        /* ============ CATEGORY CHIPS ============ */
        .cat-chip { padding: 8px 22px; border-radius: 9999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; background: #f3f4f6; color: #9ca3af; border: none; cursor: pointer; transition: 0.2s; white-space: nowrap; }
        .cat-chip.active { background: #fff; color: #1f2937; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

        /* ============ NAV SEARCH BAR + SUGGESTIONS ============
           Lives inside the original white nav, between the BLOOM logo
           and the links. Soft cream pill that turns orange on focus. */
        .shop-nav-inner { gap: 32px; }
        .shop-search-wrap { position: relative; flex: 1; max-width: 560px; min-width: 0; }
        .shop-search-box { display: flex; align-items: center; gap: 10px; background: var(--shop-soft); border: 1.5px solid #f1ede4; border-radius: 9999px; padding: 3px 4px 3px 18px; transition: border-color 0.2s, box-shadow 0.2s, background 0.2s; }
        .shop-search-box:hover { border-color: #fed7aa; }
        .shop-search-box:focus-within { background: #fff; border-color: var(--shop-accent); box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.12); }
        .shop-search-box > i { color: #d1d5db; font-size: 0.85rem; transition: color 0.2s; }
        .shop-search-box:focus-within > i { color: var(--shop-accent); }
        .shop-search-input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 0.85rem; font-weight: 500; padding: 8px 0; color: #1f2937; }
        .shop-search-input::placeholder { color: #9ca3af; }
        .shop-search-clear { display: none; width: 28px; height: 28px; border-radius: 9999px; border: none; background: #f3f4f6; color: #9ca3af; cursor: pointer; flex-shrink: 0; font-size: 0.75rem; }
        .shop-search-clear.show { display: inline-flex; align-items: center; justify-content: center; }
        .shop-search-btn { width: 36px; height: 36px; border-radius: 9999px; border: none; background: var(--shop-accent); color: #fff; font-size: 0.8rem; cursor: pointer; flex-shrink: 0; transition: background 0.2s, transform 0.2s; }
        .shop-search-btn:hover { background: #ea580c; transform: scale(1.05); }
        .shop-suggest { display: none; position: absolute; top: calc(100% + 10px); left: 0; right: 0; background: #fff; border-radius: 22px; box-shadow: 0 20px 50px rgba(0,0,0,0.12); z-index: 60; overflow: hidden; }
        .shop-suggest.show { display: block; }
        .suggest-head { padding: 14px 22px 6px; font-size: 0.6rem; font-weight: 800; letter-spacing: 0.15em; text-transform: uppercase; color: #9ca3af; }
        .suggest-item { display: flex; align-items: center; gap: 14px; padding: 10px 22px; cursor: pointer; }
        .suggest-item:hover, .suggest-item.active { background: var(--shop-soft); }
        .suggest-item img { width: 44px; height: 44px; border-radius: 12px; object-fit: cover; background: #f3f4f6; }
        .suggest-name { font-weight: 700; color: #1f2937; font-size: 0.9rem; }
        .suggest-name mark { background: #ffedd5; color: inherit; border-radius: 4px; padding: 0 2px; }
        .suggest-meta { font-size: 0.7rem; color: #9ca3af; font-weight: 600; }
        .suggest-price { margin-left: auto; font-weight: 900; color: var(--shop-price); font-size: 0.9rem; white-space: nowrap; }
        .suggest-empty { padding: 18px 22px; color: #9ca3af; font-size: 0.85rem; font-style: italic; }

        /* ============ PRODUCT PROFILE (Shopee-style) ============ */
        #productOverlay { display: none; position: fixed; inset: 0; background: rgba(20, 20, 20, 0.55); z-index: 60; overflow-y: auto; padding: 40px 16px; }
        #productOverlay.show { display: block; }
        .pp-shell { max-width: 1150px; margin: 0 auto; position: relative; }
        .pp-card { background: #fff; border-radius: 24px; padding: 28px; margin-bottom: 18px; }
        .pp-close { position: absolute; top: -14px; right: -14px; width: 42px; height: 42px; border-radius: 50%; border: none; background: #fff; color: #374151; box-shadow: 0 6px 18px rgba(0,0,0,0.18); cursor: pointer; z-index: 2; }
        .pp-top { display: grid; grid-template-columns: 450px 1fr; gap: 36px; }
        .pp-main-img { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; border-radius: 18px; background: #f3f4f6; }
        .pp-thumbs { display: flex; gap: 10px; margin-top: 12px; overflow-x: auto; }
        .pp-thumb { width: 80px; height: 80px; border-radius: 12px; object-fit: cover; cursor: pointer; border: 2px solid transparent; background: #f3f4f6; flex-shrink: 0; }
        .pp-thumb.active { border-color: var(--shop-accent); }
        .pp-title { font-size: 1.5rem; font-weight: 700; color: #1f2937; line-height: 1.35; }
        .pp-statline { display: flex; align-items: center; gap: 16px; margin-top: 12px; flex-wrap: wrap; font-size: 0.9rem; color: #6b7280; }
        .pp-sep { width: 1px; height: 20px; background: #e5e7eb; }
        .pp-rating-num { color: var(--shop-accent); font-weight: 700; text-decoration: underline; text-underline-offset: 4px; }
        .pp-stars { color: var(--shop-accent); font-size: 0.85rem; letter-spacing: 1px; }
        .pp-stat-num { color: #1f2937; font-weight: 700; text-decoration: underline; text-underline-offset: 4px; }
        .pp-pricebox { background: var(--shop-soft); border-radius: 14px; padding: 18px 22px; margin-top: 18px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .pp-price { font-size: 2rem; font-weight: 800; color: var(--shop-price); }
        .pp-tag { font-size: 0.65rem; font-weight: 800; padding: 4px 10px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em; }
        .pp-row { display: grid; grid-template-columns: 130px 1fr; align-items: start; padding: 16px 0; }
        .pp-row-label { color: #9ca3af; font-size: 0.85rem; font-weight: 500; padding-top: 8px; }
        .pp-options { display: flex; flex-wrap: wrap; gap: 10px; }
        .pp-option { padding: 9px 16px; border: 1px solid #e5e7eb; border-radius: 6px; background: #fff; font-size: 0.85rem; color: #374151; cursor: pointer; position: relative; text-align: left; }
        .pp-option small { display: block; font-size: 0.65rem; color: #9ca3af; font-weight: 600; }
        .pp-option.active { border-color: var(--shop-accent); color: var(--shop-accent); }
        .pp-option.active::after { content: '\2713'; position: absolute; right: 0; bottom: 0; background: var(--shop-accent); color: #fff; font-size: 0.55rem; padding: 0 4px; border-top-left-radius: 6px; }
        .pp-qty { display: inline-flex; align-items: center; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; }
        .pp-qty button { width: 36px; height: 36px; border: none; background: #fff; color: #6b7280; cursor: pointer; font-size: 1rem; }
        .pp-qty button:disabled { color: #d1d5db; cursor: not-allowed; }
        .pp-qty input { width: 56px; height: 36px; border: none; border-left: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb; text-align: center; font-weight: 600; outline: none; }
        .pp-actions { display: flex; gap: 14px; margin-top: 22px; flex-wrap: wrap; }
        .pp-btn-cart { padding: 14px 28px; border-radius: 6px; border: 1px solid var(--shop-accent); background: #fff7ed; color: var(--shop-accent); font-weight: 600; font-size: 0.95rem; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; min-width: 200px; justify-content: center; }
        .pp-btn-buy { padding: 14px 28px; border-radius: 6px; border: none; background: var(--shop-accent); color: #fff; font-weight: 600; font-size: 0.95rem; cursor: pointer; min-width: 200px; }
        .pp-btn-cart:disabled, .pp-btn-buy:disabled { opacity: 0.5; cursor: not-allowed; }
        .pp-section-title { font-size: 1.05rem; font-weight: 800; color: #1f2937; margin-bottom: 18px; text-transform: uppercase; letter-spacing: 0.05em; }
        .pp-rating-summary { display: flex; align-items: center; gap: 30px; background: #fff7ed; border: 1px solid #ffedd5; border-radius: 14px; padding: 22px 26px; flex-wrap: wrap; }
        .pp-rating-big { font-size: 1.9rem; font-weight: 800; color: var(--shop-accent); line-height: 1; }
        .pp-rating-big span { font-size: 1rem; font-weight: 600; }
        .pp-chip { padding: 7px 16px; border-radius: 6px; border: 1px solid #e5e7eb; background: #fff; color: #374151; font-size: 0.8rem; cursor: pointer; }
        .pp-chip.active { border-color: var(--shop-accent); color: var(--shop-accent); }
        .pp-review { display: flex; gap: 14px; padding: 20px 0; border-bottom: 1px solid #f3f4f6; }
        .pp-review:last-child { border-bottom: none; }
        .pp-avatar { width: 40px; height: 40px; border-radius: 50%; background: #fff7ed; color: var(--shop-accent); display: flex; align-items: center; justify-content: center; font-weight: 800; flex-shrink: 0; }
        .pp-muted { color: #9ca3af; font-style: italic; font-size: 0.9rem; padding: 18px 0; }
        .pp-similar-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; }
        .pp-similar-card { border: 1px solid #f3f4f6; border-radius: 14px; overflow: hidden; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
        .pp-similar-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.06); }
        .pp-similar-card img { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; background: #f3f4f6; }

        @media (max-width: 960px) {
            .pp-top { grid-template-columns: 1fr; }
            .pp-similar-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 860px) {
            /* Phones/tablets: logo + links on top, search gets its own full row */
            .shop-nav-inner { flex-wrap: wrap; row-gap: 12px; }
            .shop-search-wrap { order: 3; flex-basis: 100%; max-width: none; }
        }
        @media (max-width: 640px) {
            .pp-row { grid-template-columns: 1fr; gap: 8px; }
            .pp-row-label { padding-top: 0; }
        }
    </style>
</head>
<body>

<nav id="shopNav" class="bg-white py-4 shadow-sm sticky top-0 z-50">
    <div class="shop-nav-inner max-w-7xl mx-auto px-4 flex justify-between items-center">
        <a href="landing_page.php" class="flex items-center gap-2 flex-shrink-0">
            <img src="../assets/images/asset.jpg" alt="BLOOM" class="h-8 object-contain">
            <h1 class="text-xl font-black italic tracking-tighter text-orange-500">BLOOM</h1>
        </a>

        <!-- SEARCH BAR + SUGGESTION DROPDOWN (inline in the nav) -->
        <div class="shop-search-wrap" id="shopSearchWrap">
            <div class="shop-search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="shopSearch" class="shop-search-input" placeholder="Search roses, bouquets, gift boxes..." autocomplete="off">
                <button type="button" class="shop-search-clear" id="shopSearchClear" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
                <button type="button" class="shop-search-btn" id="shopSearchBtn" title="Search"><i class="fa-solid fa-arrow-right"></i></button>
            </div>
            <div class="shop-suggest" id="shopSuggest"></div>
        </div>

        <div class="flex items-center gap-6 flex-shrink-0">
            <a href="shop.php" class="text-orange-500 font-bold text-xs uppercase tracking-widest">Shop</a>
            <a href="my_orders.php" onclick="openMyOrders(event)" class="text-gray-400 font-bold text-xs uppercase tracking-widest hover:text-orange-500">My Orders</a>
            <div class="relative cursor-pointer" onclick="toggleCart()">
                <i class="fa-solid fa-shopping-cart text-gray-400 text-xl"></i>
                <span id="cart-count" class="absolute -top-2 -right-2 bg-orange-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">0</span>
            </div>
            <?php if ($isLoggedIn): ?>
                <a href="../logout.php" class="text-red-400 font-bold text-xs uppercase tracking-widest">Logout</a>
            <?php else: ?>
                <a href="../index.php" class="text-gray-400 font-bold text-xs uppercase tracking-widest hover:text-orange-500">Login</a>
                <a href="../register.php" class="px-5 py-2 bg-orange-500 text-white rounded-full font-bold text-xs uppercase tracking-widest hover:bg-orange-600 transition-all">Join Now</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- HARDENED FRAUD INTERCEPTOR DYNAMIC WARNING BANNER -->
<div id="shopFraudNoticeContainer" class="hidden max-w-7xl mx-auto mt-6 px-4">
    <div class="w-full bg-red-50 border border-red-200 text-red-800 p-5 rounded-3xl flex items-center gap-4 shadow-sm">
        <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center text-red-600 flex-shrink-0 text-lg">
            <i class="fa-solid fa-shield-virus animate-bounce"></i>
        </div>
        <div class="flex-1">
            <h4 class="font-black uppercase text-xs tracking-wider">Security Protocol Restriction Triggered</h4>
            <p class="text-xs font-semibold opacity-90 mt-0.5" id="shopFraudNoticeMessage"></p>
        </div>
        <button type="button" onclick="document.getElementById('shopFraudNoticeContainer').classList.add('hidden')" class="text-red-400 hover:text-red-700 transition-colors px-2">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
    </div>
</div>

<?php if (!$isLoggedIn): ?>
<!-- GUEST INFO STRIP: tells visitors they can browse, but need an account to buy -->
<div class="max-w-7xl mx-auto mt-6 px-4">
    <div class="w-full bg-indigo-50 border border-indigo-100 text-indigo-800 p-4 rounded-3xl flex items-center gap-3 text-xs font-semibold">
        <i class="fa-solid fa-circle-info"></i>
        <span>You're browsing as a guest. <a href="../index.php" class="underline font-black">Log in</a> or <a href="../register.php" class="underline font-black">create an account</a> to add items to your cart and track orders.</span>
    </div>
</div>
<?php endif; ?>

<div class="shop-container">
    <div id="resultsAnchor"></div>
    <div class="flex justify-between items-end mb-8 gap-6 flex-wrap">
        <div>
            <h2 class="text-3xl font-black text-gray-800 uppercase tracking-tight">Fresh Collection</h2>
            <p class="text-gray-400 text-sm">Hand-picked flowers delivered to your doorstep.</p>
        </div>
        <!-- Built from the categories that actually exist in inventory -->
        <div class="flex gap-3 flex-wrap" id="categoryChips">
            <button class="cat-chip active">All</button>
        </div>
    </div>

    <p id="resultsLabel" class="text-xs font-bold uppercase tracking-widest text-gray-400 mb-6 hidden"></p>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-8" id="product-grid">
        <div class="col-span-full text-center py-20 text-gray-400 italic">Loading fresh flowers...</div>
    </div>
</div>

<!-- PRODUCT PROFILE MODAL (filled in by renderProductProfile) -->
<div id="productOverlay">
    <div class="pp-shell" id="productShell"></div>
</div>

<script>
    <?php
        $firebaseConfigJson = file_get_contents(__DIR__ . '/../firebase-applet-config.json');
        echo "const firebaseConfig = " . $firebaseConfigJson . ";";
    ?>
    firebase.initializeApp(firebaseConfig);
    const db = firebase.firestore();

    // json_encode turns PHP null into JS null, and a real id into a quoted string.
    const userId = <?php echo json_encode($user_id); ?>;
    const isLoggedIn = userId !== null;

    // ---- Shared data contracts (must match BloominousApp) ----
    const REVIEWS_COLLECTION = 'reviews';

    // Inline SVG fallback: never a broken image, never depends on an outside site.
    const PLACEHOLDER_IMG = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><rect width="100%" height="100%" fill="#f3efe8"/><text x="50%" y="54%" font-size="90" text-anchor="middle">🌸</text></svg>'
    );

    // --- POST-LOGIN RESUME ---
    // If this visitor was sent to login by the guest gate, finish what they started.
    const pendingIntent = isLoggedIn ? window.bloomConsumeLoginIntent() : null;
    if (pendingIntent && pendingIntent.action === 'view_orders') {
        window.location.replace('my_orders.php');
    }
    // An "add to cart" can only run once products are loaded, so we park it here.
    let pendingCartIntent = (pendingIntent && pendingIntent.action === 'add_to_cart') ? pendingIntent : null;

    // Branch Management
    window.currentBranch = localStorage.getItem('bloom_branch_id') || 'main_branch';
    window.getBranchPath = (collectionName) => {
        if (collectionName === 'orders' || collectionName === 'customers' || collectionName === 'users') {
            return db.collection(collectionName);
        }
        return db.collection('branches').doc(window.currentBranch).collection(collectionName);
    };

    // ---- Page state ----
    let allProducts = {};        // productKey -> grouped product (same flower across branches)
    let productList = [];        // same products as an array, in display order
    let branchMap = {};          // branchId -> { name, lat, lng }
    let reviewsCache = [];       // every review, loaded once (public read)
    let cartItems = {};          // live copy of carts/{uid}.items from BloomCart.listen
    let cartReady = null;        // Promise<boolean>: true once the Firestore cart is usable
    let currentSearch = '';
    let activeCategory = 'All';
    let suggestionResults = [];
    let activeSuggestion = -1;
    let profileState = null;     // { key, branchDocId, qty, imageIdx, filter } while open
    let userLat = null;
    let userLng = null;

    // ------------------------------------------------------------------
    // SMALL HELPERS
    // ------------------------------------------------------------------
    // Anything typed by a person (names, reviews) goes through this before innerHTML.
    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatPeso(value) {
        return '₱' + parseFloat(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderStars(avg) {
        let html = '';
        for (let i = 1; i <= 5; i++) {
            if (avg >= i) html += '<i class="fa-solid fa-star"></i>';
            else if (avg >= i - 0.5) html += '<i class="fa-solid fa-star-half-stroke"></i>';
            else html += '<i class="fa-regular fa-star"></i>';
        }
        return html;
    }

    // "Juan Dela Cruz" -> "J****z" (privacy masking like Shopee)
    function maskName(name) {
        const clean = String(name || 'Customer').trim();
        if (clean.length <= 2) return clean.charAt(0) + '*';
        return clean.charAt(0) + '****' + clean.charAt(clean.length - 1);
    }

    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    // Straight-line km -> rough road km (same multipliers as before).
    function roadDistanceKm(branchId) {
        const b = branchMap[branchId];
        if (!userLat || !userLng || !b || !b.lat || !b.lng) return null;
        const d = calculateDistance(userLat, userLng, b.lat, b.lng);
        if (d < 5) return d * 1.3;
        if (d < 20) return d * 1.8;
        return d * 2.5;
    }

    function getProductImages(p) {
        const list = [p.image, ...(Array.isArray(p.images) ? p.images : [])].filter(Boolean);
        return list.length ? [...new Set(list)] : [PLACEHOLDER_IMG];
    }

    // The branch a quick "Add to Cart" uses: nearest branch if we know the
    // customer's location, otherwise the branch with the most stock.
    function defaultBranch(p) {
        const ranked = p.branches.slice().sort((a, b) => {
            const da = roadDistanceKm(a.branchId);
            const dbb = roadDistanceKm(b.branchId);
            if (da !== null && dbb !== null) return da - dbb;
            return b.stock - a.stock;
        });
        return ranked[0];
    }

    // All reviews for a grouped product: matches ANY of its branch doc ids,
    // or its name (an order item may carry another branch's doc id).
    function reviewsFor(p) {
        const ids = p.branches.map(b => b.id);
        return reviewsCache
            .filter(r => ids.includes(r.productId) || (p.name && r.productName === p.name))
            .sort((a, b) => (b.createdAt ? b.createdAt.toMillis() : 0) - (a.createdAt ? a.createdAt.toMillis() : 0));
    }

    function summarizeReviews(reviews) {
        const counts = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
        let total = 0;
        reviews.forEach(r => {
            const stars = Math.min(5, Math.max(1, parseInt(r.rating || 0)));
            counts[stars]++;
            total += stars;
        });
        return { count: reviews.length, counts, avg: reviews.length ? total / reviews.length : 0 };
    }

    // "Sold" only shows if inventory carries a soldCount field (see notes).
    function soldCountFor(p) {
        const values = p.branches.map(b => b.soldCount).filter(v => typeof v === 'number');
        return values.length ? values.reduce((a, b) => a + b, 0) : null;
    }

    // ------------------------------------------------------------------
    // GEOLOCATION: when it arrives later, redraw so distances appear
    // ------------------------------------------------------------------
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => {
            userLat = pos.coords.latitude;
            userLng = pos.coords.longitude;
            if (productList.length) renderGrid();
            if (profileState) renderProductProfile();
        }, err => {
            console.warn("Geolocation failed or denied:", err);
        }, { timeout: 5000 });
    }

    // ------------------------------------------------------------------
    // FIRESTORE CART (logged-in customers only)
    // ------------------------------------------------------------------
    // 1) wait for Firebase Auth to restore the session
    // 2) move any old localStorage cart into Firestore (one time)
    // 3) keep cartItems live, so the badge also updates from the app/other tabs
    if (isLoggedIn) {
        cartReady = BloomCart.whenSignedIn()
            .then(() => BloomCart.migrateLegacy().catch(err => {
                console.warn('Legacy cart migration skipped:', err.message);
                return 0;
            }))
            .then(() => {
                BloomCart.listen(items => {
                    cartItems = items;
                    updateCartUI();
                    if (profileState) renderProductProfile(); // refresh "x already in cart"
                });
                return true;
            })
            .catch(err => {
                // PHP session exists but Firebase Auth session doesn't.
                console.warn('Cart unavailable:', err.message);
                return false;
            });
    }

    updateCartUI();

    // ------------------------------------------------------------------
    // SEARCH
    // ------------------------------------------------------------------
    // Ranks products by how well they match: name start > name contains > other fields.
    function searchProducts(term, list) {
        const q = term.trim().toLowerCase();
        if (!q) return list.slice();

        const ranked = [];
        list.forEach(p => {
            const name = (p.name || '').toLowerCase();
            const category = (p.category || '').toLowerCase();
            const description = (p.description || '').toLowerCase();

            let score = -1;
            if (name.startsWith(q)) score = 0;
            else if (name.includes(q)) score = 1;
            else if (category.includes(q)) score = 2;
            else if (description.includes(q)) score = 3;

            if (score >= 0) ranked.push({ p, score });
        });
        ranked.sort((a, b) => a.score - b.score || (a.p.name || '').localeCompare(b.p.name || ''));
        return ranked.map(r => r.p);
    }

    function highlightMatch(text, term) {
        const raw = String(text || '');
        const q = term.trim();
        const idx = raw.toLowerCase().indexOf(q.toLowerCase());
        if (!q || idx === -1) return escapeHtml(raw);
        return escapeHtml(raw.slice(0, idx))
            + '<mark>' + escapeHtml(raw.slice(idx, idx + q.length)) + '</mark>'
            + escapeHtml(raw.slice(idx + q.length));
    }

    // Products in the active category, then filtered by the search text.
    function visibleProducts() {
        const inCategory = activeCategory === 'All'
            ? productList
            : productList.filter(p => (p.category || 'Flower') === activeCategory);
        return searchProducts(currentSearch, inCategory);
    }

    function renderSuggestions() {
        const box = document.getElementById('shopSuggest');
        const term = currentSearch.trim();
        if (!term) { hideSuggestions(); return; }

        // Suggestions search ALL products, even outside the active category.
        suggestionResults = searchProducts(term, productList).slice(0, 6);
        activeSuggestion = -1;

        if (suggestionResults.length === 0) {
            box.innerHTML = `<div class="suggest-empty">No flowers match "${escapeHtml(term)}". Try "rose" or "bouquet".</div>`;
        } else {
            box.innerHTML = '<div class="suggest-head">Suggested for you</div>' +
                suggestionResults.map((p, i) => {
                    const summary = summarizeReviews(reviewsFor(p));
                    const ratingText = summary.count ? ` · <i class="fa-solid fa-star" style="color: var(--shop-accent);"></i> ${summary.avg.toFixed(1)}` : '';
                    return `
                    <div class="suggest-item" data-index="${i}" onmousedown="pickSuggestion(${i})">
                        <img src="${escapeHtml(getProductImages(p)[0])}" alt="" onerror="this.src=PLACEHOLDER_IMG">
                        <div>
                            <div class="suggest-name">${highlightMatch(p.name || 'Unnamed', term)}</div>
                            <div class="suggest-meta">${escapeHtml(p.category || 'Flower')}${ratingText}</div>
                        </div>
                        <div class="suggest-price">${formatPeso(p.price)}</div>
                    </div>`;
                }).join('');
        }
        box.classList.add('show');
    }

    function hideSuggestions() {
        document.getElementById('shopSuggest').classList.remove('show');
        activeSuggestion = -1;
    }

    function pickSuggestion(index) {
        const p = suggestionResults[index];
        if (!p) return;
        hideSuggestions();
        openProductProfile(p.key);
    }

    function moveSuggestionHighlight(step) {
        if (suggestionResults.length === 0) return;
        activeSuggestion = (activeSuggestion + step + suggestionResults.length) % suggestionResults.length;
        document.querySelectorAll('#shopSuggest .suggest-item').forEach(el => {
            el.classList.toggle('active', Number(el.dataset.index) === activeSuggestion);
        });
    }

    // ------------------------------------------------------------------
    // CATEGORY CHIPS (built from real inventory categories)
    // ------------------------------------------------------------------
    function renderCategoryChips() {
        const categories = ['All', ...new Set(productList.map(p => p.category || 'Flower'))];
        if (!categories.includes(activeCategory)) activeCategory = 'All';
        document.getElementById('categoryChips').innerHTML = categories.map(c => `
            <button class="cat-chip ${c === activeCategory ? 'active' : ''}" onclick="setCategory('${escapeHtml(c).replace(/&#39;/g, "\\'")}')">${escapeHtml(c)}</button>
        `).join('');
    }

    function setCategory(category) {
        activeCategory = category;
        renderCategoryChips();
        renderGrid();
    }

    // Scrolls the results into view without hiding them under the sticky header.
    function scrollToResults() {
        const header = document.getElementById('shopNav');
        const target = document.getElementById('resultsAnchor');
        const top = target.getBoundingClientRect().top + window.scrollY - (header ? header.offsetHeight : 0) - 16;
        window.scrollTo({ top, behavior: 'smooth' });
    }

    // ------------------------------------------------------------------
    // PRODUCT GRID
    // ------------------------------------------------------------------
    function productCardHtml(p) {
        const img = getProductImages(p)[0];

        let minDistance = null;
        let branchListHtml = '';
        p.branches.forEach(b => {
            const bName = (branchMap[b.branchId] || { name: 'Branch' }).name;
            const d = roadDistanceKm(b.branchId);
            if (d !== null && (minDistance === null || d < minDistance)) minDistance = d;

            branchListHtml += `<div class="flex justify-between items-center text-[10px] mt-1 border-t pt-1 border-gray-50">
                <span class="text-gray-400 font-medium">${escapeHtml(bName)}</span>
                <span class="${b.stock < 10 ? 'text-red-400' : 'text-green-500'} font-black">${b.stock} left</span>
            </div>`;
        });

        const distanceBadge = minDistance !== null
            ? `<span class="bg-pink-50 text-pink-500 text-[8px] px-2 py-0.5 rounded-full font-black ml-2 uppercase">~${minDistance.toFixed(1)}KM</span>`
            : '';

        const summary = summarizeReviews(reviewsFor(p));
        const ratingLine = summary.count
            ? `<div class="card-rating mb-1"><i class="fa-solid fa-star"></i> ${summary.avg.toFixed(1)}<span>(${summary.count} rating${summary.count > 1 ? 's' : ''})</span></div>`
            : '';

        const isRecycled = p.name === 'Recycled Bouquet';
        const recycledBadge = isRecycled ? `<span class="bg-green-100 text-green-600 text-[8px] px-2 py-0.5 rounded-full font-black ml-2 uppercase">Eco-Salvaged</span>` : '';

        // Guests see a lock icon so it's clear this button leads to login.
        const addLabel = isLoggedIn ? 'Add to Cart' : '<i class="fa-solid fa-lock mr-1"></i> Log in to Add';

        return `
        <div class="product-card group ${isRecycled ? 'border-2 border-green-200' : ''}" onclick="openProductProfile('${p.key}')">
            <div class="relative overflow-hidden h-[200px]">
                <img src="${escapeHtml(img)}" class="product-img w-full h-full object-cover transition-transform group-hover:scale-110" alt="Product" onerror="this.src=PLACEHOLDER_IMG">
                ${isRecycled ? '<div class="absolute top-2 left-2 bg-green-500 text-white text-[10px] px-2 py-1 rounded font-bold">RECYCLED</div>' : ''}
            </div>
            <div class="product-info">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <span class="text-[10px] uppercase font-bold text-gray-400 tracking-widest">${escapeHtml(p.category || 'Flower')}</span>
                        ${recycledBadge}
                    </div>
                    ${distanceBadge}
                </div>
                <h3 class="font-bold text-gray-800 my-1 truncate">${highlightMatch(p.name || 'Unnamed', currentSearch)}</h3>
                ${ratingLine}
                <p class="text-indigo-600 font-black text-lg mb-2">${formatPeso(p.price)}</p>

                ${isRecycled && p.description ? `<p class="text-[10px] text-green-700 font-medium italic mb-3 leading-tight">${escapeHtml(p.description)}</p>` : ''}

                <div class="mb-4">
                    <p class="text-[8px] font-black uppercase text-gray-300 tracking-widest mb-1">Availability</p>
                    ${branchListHtml}
                </div>

                <button onclick="event.stopPropagation(); addToCart('${p.key}', this)" class="btn-add ${isRecycled ? 'bg-green-600 hover:bg-green-700' : ''}">${addLabel}</button>
            </div>
        </div>`;
    }

    function renderGrid() {
        const grid = document.getElementById('product-grid');
        const label = document.getElementById('resultsLabel');
        const visible = visibleProducts();
        const term = currentSearch.trim();

        // "Showing 3 results for 'rose'" only while searching/filtering.
        if (term || activeCategory !== 'All') {
            label.innerHTML = term
                ? `${visible.length} result${visible.length === 1 ? '' : 's'} for "${escapeHtml(term)}"`
                : `${visible.length} item${visible.length === 1 ? '' : 's'} in ${escapeHtml(activeCategory)}`;
            label.classList.remove('hidden');
        } else {
            label.classList.add('hidden');
        }

        if (productList.length === 0) {
            grid.innerHTML = '<div class="col-span-full text-center py-20 text-gray-400 italic">No flowers in stock right now.</div>';
            return;
        }

        if (visible.length === 0) {
            // Empty search: suggest the best-rated items instead of a dead end.
            const popular = productList.slice()
                .sort((a, b) => summarizeReviews(reviewsFor(b)).avg - summarizeReviews(reviewsFor(a)).avg)
                .slice(0, 4);
            grid.innerHTML = `
                <div class="col-span-full text-center py-10 text-gray-400 italic">Nothing matched. You might like these instead:</div>
                ${popular.map(productCardHtml).join('')}`;
            return;
        }

        grid.innerHTML = visible.map(productCardHtml).join('');
    }

    // ------------------------------------------------------------------
    // PRODUCT PROFILE (Shopee-style detail view)
    // ------------------------------------------------------------------
    function openProductProfile(key) {
        const p = allProducts[key];
        if (!p) return;
        const start = defaultBranch(p);
        profileState = { key, branchDocId: start.id, qty: 1, imageIdx: 0, filter: 'all' };
        renderProductProfile();

        const overlay = document.getElementById('productOverlay');
        overlay.classList.add('show');
        overlay.scrollTop = 0;
        document.body.style.overflow = 'hidden';
    }

    function closeProductProfile() {
        profileState = null;
        document.getElementById('productOverlay').classList.remove('show');
        document.body.style.overflow = '';
    }

    function selectedBranchOf(p) {
        return p.branches.find(b => b.id === profileState.branchDocId) || p.branches[0];
    }

    // How many more of this branch's item can go into the cart right now.
    function remainingForBranch(b) {
        const inCart = (cartItems[b.id] && cartItems[b.id].qty) || 0;
        return Math.max(0, b.stock - inCart);
    }

    function selectBranch(docId) {
        if (!profileState) return;
        profileState.branchDocId = docId;
        profileState.qty = 1;
        renderProductProfile();
    }

    function changeQty(step) {
        if (!profileState) return;
        setQtyValue(profileState.qty + step);
    }

    // Clamps typed/clicked quantity between 1 and what is still available.
    function setQtyValue(value) {
        if (!profileState) return;
        const p = allProducts[profileState.key];
        const max = Math.max(1, remainingForBranch(selectedBranchOf(p)));
        const n = parseInt(value, 10);
        profileState.qty = Number.isNaN(n) ? 1 : Math.min(max, Math.max(1, n));
        renderProductProfile();
    }

    function setProfileImage(index) {
        if (!profileState) return;
        profileState.imageIdx = index;
        renderProductProfile();
    }

    function setProfileReviewFilter(filter) {
        if (!profileState) return;
        profileState.filter = filter;
        renderProductProfile();
    }

    function renderProductProfile() {
        if (!profileState) return;
        const p = allProducts[profileState.key];
        if (!p) { closeProductProfile(); return; } // product sold out / removed meanwhile

        const branch = selectedBranchOf(p);
        const remaining = remainingForBranch(branch);
        const inCart = (cartItems[branch.id] && cartItems[branch.id].qty) || 0;
        const price = typeof branch.price === 'number' ? branch.price : p.price;

        const images = getProductImages(p);
        const activeImg = images[profileState.imageIdx] || images[0];

        const reviews = reviewsFor(p);
        const summary = summarizeReviews(reviews);
        const sold = soldCountFor(p);

        // --- Rating / ratings count / sold header line ---
        const statParts = [];
        statParts.push(summary.count
            ? `<span class="pp-rating-num">${summary.avg.toFixed(1)}</span><span class="pp-stars">${renderStars(summary.avg)}</span>`
            : '<span>No ratings yet</span>');
        statParts.push(`<span><span class="pp-stat-num">${summary.count}</span> Ratings</span>`);
        if (sold !== null) statParts.push(`<span><span class="pp-stat-num">${sold}</span> Sold</span>`);
        const statLine = statParts.join('<span class="pp-sep"></span>');

        // --- Branch options (like Shopee's Model/Color chips) ---
        const branchOptions = p.branches.map(b => {
            const bName = (branchMap[b.branchId] || { name: 'Branch' }).name;
            const d = roadDistanceKm(b.branchId);
            const meta = `${b.stock} left${d !== null ? ` · ~${d.toFixed(1)}km` : ''}`;
            return `<button class="pp-option ${b.id === branch.id ? 'active' : ''}" onclick="selectBranch('${b.id}')">
                ${escapeHtml(bName)}<small>${meta}</small>
            </button>`;
        }).join('');

        // --- Stock / cart status text next to the quantity box ---
        let stockText;
        if (remaining <= 0) stockText = `<span class="text-red-400 font-bold">You already have all ${branch.stock} available in your cart</span>`;
        else stockText = `<span class="text-gray-400">${branch.stock} pieces available${inCart ? ` · ${inCart} already in your cart` : ''}</span>`;

        const soldOut = remaining <= 0;
        const lockIcon = isLoggedIn ? '' : '<i class="fa-solid fa-lock"></i>';

        // --- Reviews list filtered by chip ---
        const filtered = profileState.filter === 'all'
            ? reviews
            : reviews.filter(r => parseInt(r.rating) === Number(profileState.filter));
        const reviewsHtml = filtered.length === 0
            ? `<div class="pp-muted">${reviews.length ? 'No reviews in this filter yet.' : 'No reviews yet. Customers can rate this after receiving their order.'}</div>`
            : filtered.map(r => {
                const date = r.createdAt ? r.createdAt.toDate().toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Just now';
                const name = maskName(r.customerName);
                return `
                    <div class="pp-review">
                        <div class="pp-avatar">${escapeHtml(name.charAt(0).toUpperCase())}</div>
                        <div style="flex:1;">
                            <div class="text-sm font-semibold text-gray-800">${escapeHtml(name)}</div>
                            <div class="pp-stars">${renderStars(parseInt(r.rating || 0))}</div>
                            <div class="text-[11px] text-gray-400 mt-1 mb-2">${escapeHtml(date)}</div>
                            ${r.comment ? `<div class="text-sm text-gray-700 leading-relaxed" style="white-space: pre-line;">${escapeHtml(r.comment)}</div>` : ''}
                        </div>
                    </div>`;
            }).join('');

        const chips = ['all', 5, 4, 3, 2, 1].map(f => {
            const label = f === 'all' ? 'All' : `${f} Star (${summary.counts[f]})`;
            return `<button class="pp-chip ${String(profileState.filter) === String(f) ? 'active' : ''}" onclick="setProfileReviewFilter('${f}')">${label}</button>`;
        }).join('');

        // --- "You may also like": same category first, then best rated ---
        const others = productList.filter(x => x.key !== p.key);
        const sameCategory = others.filter(x => (x.category || '') === (p.category || ''));
        const rest = others.filter(x => (x.category || '') !== (p.category || ''))
            .sort((a, b) => summarizeReviews(reviewsFor(b)).avg - summarizeReviews(reviewsFor(a)).avg);
        const similar = [...sameCategory, ...rest].slice(0, 5);
        const similarHtml = similar.length === 0
            ? '<div class="pp-muted">More flowers coming soon.</div>'
            : `<div class="pp-similar-grid">${similar.map(s => {
                const sSum = summarizeReviews(reviewsFor(s));
                return `
                <div class="pp-similar-card" onclick="openProductProfile('${s.key}')">
                    <img src="${escapeHtml(getProductImages(s)[0])}" alt="" onerror="this.src=PLACEHOLDER_IMG">
                    <div class="p-3">
                        <div class="text-sm font-semibold text-gray-800 truncate">${escapeHtml(s.name || 'Unnamed')}</div>
                        <div class="flex justify-between items-center mt-1">
                            <span class="font-black text-indigo-600 text-sm">${formatPeso(s.price)}</span>
                            ${sSum.count ? `<span class="card-rating"><i class="fa-solid fa-star"></i> ${sSum.avg.toFixed(1)}</span>` : ''}
                        </div>
                    </div>
                </div>`;
            }).join('')}</div>`;

        document.getElementById('productShell').innerHTML = `
            <button class="pp-close" onclick="closeProductProfile()" title="Close"><i class="fa-solid fa-xmark"></i></button>

            <div class="pp-card">
                <div class="text-xs text-gray-400 mb-5">
                    <a href="#" onclick="closeProductProfile(); return false;" class="text-indigo-500 hover:underline">Shop</a>
                    <i class="fa-solid fa-chevron-right mx-2 text-[9px]"></i>
                    <a href="#" onclick="closeProductProfile(); setCategory('${escapeHtml(p.category || 'Flower').replace(/&#39;/g, "\\'")}'); return false;" class="text-indigo-500 hover:underline">${escapeHtml(p.category || 'Flower')}</a>
                    <i class="fa-solid fa-chevron-right mx-2 text-[9px]"></i>
                    <span class="text-gray-600">${escapeHtml(p.name || 'Unnamed')}</span>
                </div>

                <div class="pp-top">
                    <div>
                        <img class="pp-main-img" src="${escapeHtml(activeImg)}" alt="${escapeHtml(p.name || 'Product')}" onerror="this.src=PLACEHOLDER_IMG">
                        <div class="pp-thumbs">
                            ${images.map((src, i) => `<img class="pp-thumb ${i === profileState.imageIdx ? 'active' : ''}" src="${escapeHtml(src)}" alt="" onmouseenter="setProfileImage(${i})" onclick="setProfileImage(${i})" onerror="this.src=PLACEHOLDER_IMG">`).join('')}
                        </div>
                    </div>

                    <div>
                        <div class="pp-title">${escapeHtml(p.name || 'Unnamed')}</div>
                        <div class="pp-statline">${statLine}</div>

                        <div class="pp-pricebox">
                            <span class="pp-price">${formatPeso(price)}</span>
                            <span class="pp-tag" style="background:#eef2ff; color:#4f46e5;">${escapeHtml(p.category || 'Flower')}</span>
                            ${p.name === 'Recycled Bouquet' ? '<span class="pp-tag" style="background:#dcfce7; color:#16a34a;">Eco-Salvaged</span>' : ''}
                        </div>

                        ${p.description ? `<div class="pp-row"><span class="pp-row-label">Description</span><span class="text-sm text-gray-600 leading-relaxed" style="padding-top:8px;">${escapeHtml(p.description)}</span></div>` : ''}

                        <div class="pp-row">
                            <span class="pp-row-label">Delivery</span>
                            <span class="text-sm text-gray-700" style="padding-top:8px;"><i class="fa-solid fa-truck-fast text-teal-500 mr-2"></i>Fresh from the selected branch to your doorstep</span>
                        </div>

                        <div class="pp-row">
                            <span class="pp-row-label">Branch</span>
                            <div class="pp-options">${branchOptions}</div>
                        </div>

                        <div class="pp-row">
                            <span class="pp-row-label">Quantity</span>
                            <div class="flex items-center gap-4 flex-wrap">
                                <div class="pp-qty">
                                    <button onclick="changeQty(-1)" ${profileState.qty <= 1 || soldOut ? 'disabled' : ''}><i class="fa-solid fa-minus"></i></button>
                                    <input type="number" min="1" value="${profileState.qty}" ${soldOut ? 'disabled' : ''} onchange="setQtyValue(this.value)">
                                    <button onclick="changeQty(1)" ${profileState.qty >= remaining || soldOut ? 'disabled' : ''}><i class="fa-solid fa-plus"></i></button>
                                </div>
                                <span class="text-xs">${stockText}</span>
                            </div>
                        </div>

                        <div class="pp-actions">
                            <button class="pp-btn-cart" id="ppAddBtn" onclick="profileAddToCart(false)" ${soldOut ? 'disabled' : ''}>
                                ${lockIcon}<i class="fa-solid fa-cart-plus"></i> Add To Cart
                            </button>
                            <button class="pp-btn-buy" id="ppBuyBtn" onclick="profileAddToCart(true)" ${soldOut ? 'disabled' : ''}>
                                ${lockIcon} Buy Now
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pp-card">
                <div class="pp-section-title">Product Ratings</div>
                <div class="pp-rating-summary">
                    <div>
                        <div class="pp-rating-big">${summary.avg.toFixed(1)} <span>out of 5</span></div>
                        <div class="pp-stars" style="font-size:1.1rem; margin-top:6px;">${renderStars(summary.avg)}</div>
                    </div>
                    <div class="flex flex-wrap gap-2">${chips}</div>
                </div>
                <div class="mt-2">${reviewsHtml}</div>
            </div>

            <div class="pp-card">
                <div class="pp-section-title">You May Also Like</div>
                ${similarHtml}
            </div>
        `;
    }

    // ------------------------------------------------------------------
    // CART ACTIONS
    // ------------------------------------------------------------------
    // The ONE function that writes to the cart. Card button, profile
    // buttons, and the post-login resume all come through here.
    // Returns true when the item really landed in Firestore.
    async function addProductToCart(key, branchDocId, qty) {
        const p = allProducts[key];
        if (!p) return false;
        const branch = p.branches.find(b => b.id === branchDocId) || defaultBranch(p);

        const ready = await cartReady;
        if (!ready) {
            window.bloomShowToast('Your session expired. Please log in again to use your cart.');
            return false;
        }

        // Never let the cart hold more than the branch actually has.
        const remaining = remainingForBranch(branch);
        if (qty > remaining) {
            const bName = (branchMap[branch.branchId] || { name: 'this branch' }).name;
            window.bloomShowToast(remaining <= 0
                ? `All available "${p.name}" from ${bName} are already in your cart.`
                : `Only ${remaining} more "${p.name}" available from ${bName}.`);
            return false;
        }

        try {
            await BloomCart.add({
                id: branch.id,                 // inventory doc id (cart contract)
                name: p.name,
                price: typeof branch.price === 'number' ? branch.price : p.price,
                branchId: branch.branchId,
                image: p.image || null
            }, qty);
            return true;
        } catch (err) {
            console.error('Add to cart failed:', err);
            window.bloomShowToast('Could not add to cart: ' + err.message);
            return false;
        }
    }

    // Sends a guest to login, remembering exactly what they wanted.
    function gateGuest(key, branchDocId, qty, buyNow, message) {
        const p = allProducts[key];
        window.bloomRequireLogin(
            {
                action: 'add_to_cart',
                productId: branchDocId || (p ? p.id : null),
                productName: p ? p.name : null,
                qty: qty || 1,
                buyNow: buyNow === true
            },
            message
        );
    }

    // Card "Add to Cart" button: 1 unit from the default branch.
    async function addToCart(key, btn) {
        if (!isLoggedIn) {
            gateGuest(key, null, 1, false, 'Please log in to add items to your cart.');
            return;
        }
        const p = allProducts[key];
        if (!p) return;

        const originalHtml = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerText = 'ADDING...'; }

        const ok = await addProductToCart(key, defaultBranch(p).id, 1);

        if (btn) {
            btn.disabled = false;
            if (ok) {
                btn.innerText = 'ADDED!';
                btn.style.background = '#10b981';
                setTimeout(() => { btn.innerHTML = originalHtml; btn.style.background = ''; }, 1000);
            } else {
                btn.innerHTML = originalHtml;
            }
        }
    }

    // Profile "Add To Cart" (buyNow=false) and "Buy Now" (buyNow=true).
    async function profileAddToCart(buyNow) {
        if (!profileState) return;
        const { key, branchDocId, qty } = profileState;

        if (!isLoggedIn) {
            gateGuest(key, branchDocId, qty, buyNow, buyNow ? 'Please log in to buy this item.' : 'Please log in to add items to your cart.');
            return;
        }

        const addBtn = document.getElementById('ppAddBtn');
        const buyBtn = document.getElementById('ppBuyBtn');
        if (addBtn) addBtn.disabled = true;
        if (buyBtn) buyBtn.disabled = true;

        const ok = await addProductToCart(key, branchDocId, qty);

        if (ok && buyNow) {
            await goToCheckout();
            return;
        }
        if (ok) {
            window.bloomShowToast(`${qty} × "${allProducts[key].name}" added to your cart.`);
            if (profileState) profileState.qty = 1;
        }
        renderProductProfile(); // re-enables buttons with fresh stock numbers
    }

    // Runs once after login + product load: finishes what the guest picked.
    async function applyPendingCartIntent() {
        if (!pendingCartIntent) return;
        const intent = pendingCartIntent;
        pendingCartIntent = null; // clear FIRST: onSnapshot may re-render later

        // Find the grouped product holding that inventory doc; fall back to name.
        let target = Object.values(allProducts).find(p => p.branches.some(b => b.id === intent.productId));
        if (!target && intent.productName) {
            target = Object.values(allProducts).find(p => p.name === intent.productName);
        }

        if (!target) {
            window.bloomShowToast('Sorry, the item you picked is no longer in stock.');
            return;
        }

        const branchDocId = target.branches.some(b => b.id === intent.productId) ? intent.productId : defaultBranch(target).id;
        const qty = Math.max(1, parseInt(intent.qty, 10) || 1);
        const ok = await addProductToCart(target.key, branchDocId, qty);

        if (ok && intent.buyNow) {
            await goToCheckout();
        } else if (ok) {
            window.bloomShowToast(`"${target.name}" was added to your cart.`);
        }
    }

    // Reads the cart fresh (the live listener may be a moment behind) and opens checkout.
    async function goToCheckout() {
        try {
            const items = await BloomCart.load();
            window.location.href = 'checkout.php?amount=' + BloomCart.total(items);
        } catch (err) {
            window.bloomShowToast('Could not open checkout: ' + err.message);
        }
    }

    // "My Orders" link handler.
    function openMyOrders(event) {
        if (isLoggedIn) return; // let the normal link work
        event.preventDefault(); // guest: stop the link, go to login instead
        window.bloomRequireLogin({ action: 'view_orders' }, 'Please log in to view your orders.');
    }

    function updateCartUI() {
        // Guests always see 0.
        const count = isLoggedIn ? BloomCart.count(cartItems) : 0;
        document.getElementById('cart-count').innerText = count;
    }

    function toggleCart() {
        if (!isLoggedIn) {
            window.bloomRequireLogin(null, 'Please log in to view your cart.');
            return;
        }
        goToCheckout();
    }

    // ------------------------------------------------------------------
    // PAGE BOOT
    // ------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        const grid = document.getElementById('product-grid');
        const searchInput = document.getElementById('shopSearch');
        const clearBtn = document.getElementById('shopSearchClear');

        // --- DEFENSIVE FIREWALL USER ALERT LOOP INTERCEPTOR ---
        const fraudNoticeMsg = sessionStorage.getItem('bloom_shop_error');
        if (fraudNoticeMsg) {
            const noticeContainer = document.getElementById('shopFraudNoticeContainer');
            const noticeText = document.getElementById('shopFraudNoticeMessage');
            if (noticeContainer && noticeText) {
                noticeText.innerText = fraudNoticeMsg;
                noticeContainer.classList.remove('hidden');
            }
            sessionStorage.removeItem('bloom_shop_error'); // Wipe immediately to avoid looping notifications
        }

        // --- LIVE ACCOUNT RESTRICTION LISTENER ---
        // Watches the customer's own doc in real time so a restriction placed
        // by an admin (fraud_analytics.php) or triggered automatically shows
        // up immediately, without needing a page reload. Guests skip this.
        if (isLoggedIn) {
            db.collection('customers').doc(userId).onSnapshot(doc => {
                if (!doc.exists) return;
                const c = doc.data();
                const noticeContainer = document.getElementById('shopFraudNoticeContainer');
                const noticeText = document.getElementById('shopFraudNoticeMessage');
                if (!noticeContainer || !noticeText) return;

                if (c.isRestricted === true) {
                    let remainingDaysText = "for 30 days";
                    if (c.restrictedUntil) {
                        const targetExpiry = c.restrictedUntil.toDate();
                        const dynamicDays = Math.ceil((targetExpiry - new Date()) / (1000 * 60 * 60 * 24));
                        if (dynamicDays > 0) remainingDaysText = `for the next ${dynamicDays} days`;
                    }
                    noticeText.innerText = `Your account was restricted ${remainingDaysText}. A verification code will be required to place an order.`;
                    noticeContainer.classList.remove('hidden');
                } else {
                    noticeContainer.classList.add('hidden');
                }
            });
        }

        // --- REVIEWS: loaded once, public read (guests see ratings too) ---
        const reviewsLoaded = db.collection(REVIEWS_COLLECTION).get()
            .then(snap => {
                reviewsCache = [];
                snap.forEach(doc => reviewsCache.push({ id: doc.id, ...doc.data() }));
            })
            .catch(err => {
                // Shop still works without ratings; stars just don't show.
                console.warn('Reviews unavailable:', err.message);
                reviewsCache = [];
            });

        // Fetch from ALL branches to show cross-branch availability.
        // Requires firestore.rules to allow public read on /branches (guests).
        const loadProducts = () => {
            db.collection('branches').onSnapshot(branchSnap => {
                if (branchSnap.empty) {
                    grid.innerHTML = '<div class="col-span-full text-center py-20 text-gray-400 italic">No stores found.</div>';
                    return;
                }

                branchMap = {};
                const inventoryPromises = [];

                branchSnap.forEach(b => {
                    const data = b.data();
                    branchMap[b.id] = {
                        name: data.name || b.id,
                        lat: data.latitude || (data.location ? data.location.lat : null),
                        lng: data.longitude || (data.location ? data.location.lng : null)
                    };
                    inventoryPromises.push(
                        b.ref.collection('inventory').where('stock', '>', 0).get().then(invSnap => {
                            return { branchId: b.id, docs: invSnap.docs };
                        })
                    );
                });

                // Wait for inventory AND reviews, so the first paint already has stars.
                Promise.all([Promise.all(inventoryPromises), reviewsLoaded]).then(([results]) => {
                    const grouped = {};
                    results.forEach(res => {
                        res.docs.forEach(doc => {
                            const p = doc.data();
                            // Archived items stay out of the storefront.
                            if (p.isDeleted === true || p.status === 'archived') return;

                            const name = p.name || 'Unnamed';
                            const branchEntry = {
                                branchId: res.branchId,
                                id: doc.id,
                                stock: parseInt(p.stock || 0),
                                price: typeof p.price === 'number' ? p.price : parseFloat(p.price || 0),
                                soldCount: typeof p.soldCount === 'number' ? p.soldCount : undefined
                            };

                            if (!grouped[name]) {
                                grouped[name] = { ...p, id: doc.id, key: doc.id, branches: [branchEntry] };
                            } else {
                                grouped[name].branches.push(branchEntry);
                                if (branchEntry.stock > (grouped[name].stock || 0)) {
                                    grouped[name].image = p.image || grouped[name].image;
                                    grouped[name].price = p.price || grouped[name].price;
                                }
                            }
                        });
                    });

                    allProducts = {};
                    productList = Object.values(grouped);
                    productList.forEach(p => { allProducts[p.key] = p; });

                    renderCategoryChips();
                    renderGrid();
                    if (currentSearch.trim()) renderSuggestions();
                    if (profileState) renderProductProfile();

                    // Products are now known -> finish a post-login "add to cart", if any.
                    applyPendingCartIntent();
                }).catch(err => {
                    console.error("Inventory error:", err);
                    grid.innerHTML = `<div class="col-span-full text-center py-20 text-red-500 font-bold">Failed to load flowers: ${escapeHtml(err.message)}</div>`;
                });
            }, error => {
                console.error("Branch listener error:", error);
                grid.innerHTML = `<div class="col-span-full text-center py-20 text-red-500 font-bold">Error connecting to database.</div>`;
            });
        };

        // Everyone (guest or customer) can see products now.
        loadProducts();

        // --- Search bar wiring ---
        searchInput.addEventListener('input', () => {
            currentSearch = searchInput.value;
            clearBtn.classList.toggle('show', currentSearch.length > 0);
            renderGrid();
            renderSuggestions();
        });

        searchInput.addEventListener('focus', () => {
            if (currentSearch.trim()) renderSuggestions();
        });

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') { e.preventDefault(); moveSuggestionHighlight(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); moveSuggestionHighlight(-1); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                if (activeSuggestion >= 0) pickSuggestion(activeSuggestion);
                else { hideSuggestions(); scrollToResults(); } // plain Enter = show results in the grid
            }
            else if (e.key === 'Escape') { hideSuggestions(); }
        });

        document.getElementById('shopSearchBtn').addEventListener('click', () => {
            hideSuggestions();
            scrollToResults();
        });

        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            currentSearch = '';
            clearBtn.classList.remove('show');
            hideSuggestions();
            renderGrid();
            searchInput.focus();
        });

        // Clicking anywhere outside the search area closes the dropdown.
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#shopSearchWrap')) hideSuggestions();
        });

        // --- Profile wiring: backdrop click and Escape both close it ---
        document.getElementById('productOverlay').addEventListener('click', (e) => {
            if (e.target.id === 'productOverlay') closeProductProfile();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && profileState) closeProductProfile();
        });
    });
</script>

</body>
</html>