<?php
/**
 * BLOOMINOUS - Customer Orders (Firebase Spoke)
 *
 * Shopee-style order history with the SAME stages as BloominousApp
 * (customer_profile_page.dart -> _buildOrderTrackingRow):
 *   To Pay -> To Ship -> To Deliver -> To Rate
 * plus Completed (everything rated) and Cancelled.
 *
 * - Orders are read live from `orders` (user_id == this customer).
 * - Ratings are written to / read from the top-level `reviews`
 *   collection, one doc per product per order: "<orderId>_<productId>".
 * - "Buy Again" re-adds items through assets/script/bloom_cart.js
 *   (same Firestore cart contract as BloominousApp).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
$user_id = $_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;

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
    <title>My Orders | Bloominous</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- Firebase SDK -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>
    <!-- Shared Firestore cart service (cart badge + Buy Again) -->
    <script src="../assets/script/bloom_cart.js"></script>

    <style>
        :root {
            --accent: #f97316;        /* BLOOM orange */
            --accent-dark: #ea580c;
            --teal: #14b8a6;          /* "delivered" status line */
            --text-main: #1f2937;
            --text-muted: #9ca3af;
            --border: #f1ede4;
            --soft: #fcf9f2;
            --star: #f59e0b;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--soft); color: var(--text-main); margin: 0; }
        .orders-container { max-width: 1000px; margin: 0 auto; padding: 32px 20px 60px; }

        /* ============ STAGE TABS ============ */
        .stage-tabs { display: flex; background: #fff; border-radius: 16px 16px 0 0; border-bottom: 1px solid var(--border); overflow-x: auto; }
        .stage-tab { flex: 1; min-width: 110px; padding: 18px 10px 16px; text-align: center; font-size: 0.85rem; font-weight: 500; color: var(--text-main); background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; white-space: nowrap; transition: color 0.2s, border-color 0.2s; position: relative; }
        .stage-tab:hover { color: var(--accent); }
        .stage-tab.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: 600; }
        .stage-count { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; margin-left: 6px; border-radius: 9999px; background: #ef4444; color: #fff; font-size: 0.65rem; font-weight: 700; vertical-align: 1px; }

        /* ============ ORDER SEARCH ============ */
        .order-search { display: flex; align-items: center; gap: 12px; background: #f3f0ea; border-radius: 0 0 16px 16px; padding: 4px 18px; margin-bottom: 16px; }
        .order-search i { color: var(--text-muted); }
        .order-search input { flex: 1; border: none; outline: none; background: transparent; padding: 12px 0; font-size: 0.88rem; color: var(--text-main); }
        .order-search input::placeholder { color: var(--text-muted); }

        /* ============ ORDER CARD ============ */
        .order-card { background: #fff; border-radius: 16px; margin-bottom: 16px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.03); }
        .order-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 18px 24px 14px; border-bottom: 1px solid #f3f4f6; flex-wrap: wrap; }
        .order-shop { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 0.9rem; }
        .order-shop .tag { background: var(--accent); color: #fff; font-size: 0.6rem; font-weight: 800; padding: 3px 7px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.05em; }
        .order-shop .invoice { color: var(--text-muted); font-weight: 500; font-size: 0.75rem; }
        .order-state { display: flex; align-items: center; gap: 12px; font-size: 0.82rem; }
        .order-state .line { display: flex; align-items: center; gap: 6px; }
        .order-state .divider { width: 1px; height: 16px; background: #e5e7eb; }
        .order-state .label { color: var(--accent); font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }

        .order-item { display: flex; gap: 14px; padding: 14px 24px; align-items: center; border-bottom: 1px solid #f9fafb; }
        .order-item:last-child { border-bottom: none; }
        .order-item img { width: 78px; height: 78px; object-fit: cover; border-radius: 10px; border: 1px solid #f3f4f6; background: #f3f4f6; flex-shrink: 0; }
        .order-item-name { font-size: 0.92rem; font-weight: 500; color: var(--text-main); line-height: 1.35; }
        .order-item-meta { font-size: 0.75rem; color: var(--text-muted); margin-top: 3px; }
        .order-item-qty { font-size: 0.8rem; color: var(--text-main); margin-top: 3px; }
        .order-item-right { margin-left: auto; text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0; }
        .order-item-price { color: var(--accent); font-weight: 600; font-size: 0.9rem; }

        .btn-rate { padding: 6px 16px; border-radius: 6px; border: 1px solid var(--star); color: var(--star); background: #fffaf0; font-size: 0.72rem; font-weight: 700; cursor: pointer; transition: 0.2s; white-space: nowrap; }
        .btn-rate:hover { background: var(--star); color: #fff; }
        .rated-stars { color: var(--star); font-size: 0.75rem; white-space: nowrap; }
        .rated-stars small { color: var(--text-muted); font-weight: 600; margin-left: 4px; font-size: 0.65rem; text-transform: uppercase; }

        .order-foot { background: #fffdf8; border-top: 1px dashed #f3e8d6; padding: 18px 24px 20px; }
        .order-total { display: flex; justify-content: flex-end; align-items: baseline; gap: 10px; font-size: 0.88rem; }
        .order-total strong { color: var(--accent); font-size: 1.6rem; font-weight: 600; }
        .order-date { font-size: 0.72rem; color: var(--text-muted); text-align: right; margin-top: 2px; }
        .order-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
        .btn-solid { min-width: 160px; padding: 11px 20px; border-radius: 6px; border: none; background: var(--accent); color: #fff; font-size: 0.85rem; font-weight: 500; cursor: pointer; transition: background 0.2s; text-align: center; }
        .btn-solid:hover { background: var(--accent-dark); }
        .btn-solid:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-outline { min-width: 160px; padding: 11px 20px; border-radius: 6px; border: 1px solid #e5e7eb; background: #fff; color: #374151; font-size: 0.85rem; cursor: pointer; transition: 0.2s; text-align: center; }
        .btn-outline:hover { border-color: #d1d5db; background: #f9fafb; }
        .btn-outline.danger { color: #ef4444; }

        .empty-state { text-align: center; padding: 70px 30px; background: #fff; border-radius: 16px; }

        /* ============ RATING MODAL ============ */
        #rateOverlay { display: none; position: fixed; inset: 0; background: rgba(20,20,30,0.55); z-index: 100; align-items: center; justify-content: center; padding: 20px; }
        #rateOverlay.show { display: flex; }
        .rate-card { background: #fff; border-radius: 24px; padding: 30px; width: 100%; max-width: 460px; box-shadow: 0 30px 60px rgba(0,0,0,0.15); }
        .rate-stars { display: flex; justify-content: center; gap: 10px; margin: 22px 0 6px; }
        .rate-star { font-size: 2.2rem; color: #e5e7eb; cursor: pointer; transition: transform 0.15s, color 0.15s; background: none; border: none; padding: 0; }
        .rate-star.on { color: var(--star); }
        .rate-star:hover { transform: scale(1.15); }
        .rate-label { text-align: center; font-weight: 800; color: var(--star); min-height: 24px; font-size: 0.9rem; }
        .rate-textarea { width: 100%; margin-top: 18px; border: 1px solid #eceef3; border-radius: 14px; padding: 14px 16px; font-size: 0.9rem; outline: none; resize: vertical; min-height: 100px; font-family: inherit; }
        .rate-textarea:focus { border-color: var(--accent); }
        .rate-submit { width: 100%; margin-top: 18px; padding: 14px; border-radius: 12px; border: none; background: var(--accent); color: #fff; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
        .rate-submit:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Small toast for Buy Again / errors */
        #ordersToast { position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%) translateY(20px); background: #1f2937; color: #fff; padding: 12px 22px; border-radius: 9999px; font-size: 0.82rem; font-weight: 600; opacity: 0; pointer-events: none; transition: 0.25s; z-index: 120; }
        #ordersToast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

        @media (max-width: 640px) {
            .order-head, .order-item, .order-foot { padding-left: 16px; padding-right: 16px; }
            .order-item img { width: 64px; height: 64px; }
            .btn-solid, .btn-outline { min-width: 0; flex: 1; }
        }
    </style>
</head>
<body>

<!-- Same white nav as shop.php -->
<nav class="bg-white py-4 shadow-sm sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 flex justify-between items-center">
        <a href="landing_page.php" class="flex items-center gap-2">
            <img src="../assets/images/asset.jpg" alt="BLOOM" class="h-8 object-contain">
            <h1 class="text-xl font-black italic tracking-tighter text-orange-500">BLOOM</h1>
        </a>
        <div class="flex items-center gap-6">
            <a href="shop.php" class="text-gray-400 font-bold text-xs uppercase tracking-widest hover:text-orange-500">Shop</a>
            <a href="my_orders.php" class="text-orange-500 font-bold text-xs uppercase tracking-widest">My Orders</a>
            <div class="relative cursor-pointer" onclick="goToCheckout()">
                <i class="fa-solid fa-shopping-cart text-gray-400 text-xl"></i>
                <span id="cart-count" class="absolute -top-2 -right-2 bg-orange-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">0</span>
            </div>
            <a href="../logout.php" class="text-red-400 font-bold text-xs uppercase tracking-widest">Logout</a>
        </div>
    </div>
</nav>

<div class="orders-container">
    <!-- STAGE TABS (filled by renderTabs) -->
    <div class="stage-tabs" id="stageTabs"></div>

    <!-- SEARCH BY ORDER ID / PRODUCT / BRANCH -->
    <div class="order-search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="orderSearch" placeholder="You can search by Order ID, Product name or Branch" autocomplete="off">
    </div>

    <div id="orders-list">
        <div class="text-center py-24">
            <i class="fa-solid fa-spinner fa-spin fa-2x text-orange-400 mb-4"></i>
            <p class="text-gray-400 font-semibold text-xs uppercase tracking-widest">Fetching your orders...</p>
        </div>
    </div>
</div>

<!-- RATING MODAL -->
<div id="rateOverlay">
    <div class="rate-card">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[2px] text-gray-400">Rate Product</p>
                <h3 id="rateProductName" class="text-xl font-bold text-gray-800 mt-1"></h3>
            </div>
            <button type="button" onclick="closeRateModal()" class="text-gray-300 hover:text-gray-500 text-xl"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="rate-stars" id="rateStars">
            <button type="button" class="rate-star" data-value="1"><i class="fa-solid fa-star"></i></button>
            <button type="button" class="rate-star" data-value="2"><i class="fa-solid fa-star"></i></button>
            <button type="button" class="rate-star" data-value="3"><i class="fa-solid fa-star"></i></button>
            <button type="button" class="rate-star" data-value="4"><i class="fa-solid fa-star"></i></button>
            <button type="button" class="rate-star" data-value="5"><i class="fa-solid fa-star"></i></button>
        </div>
        <div class="rate-label" id="rateLabel">Tap a star to rate</div>

        <textarea id="rateComment" class="rate-textarea" maxlength="500" placeholder="Share how the flowers looked and arrived (optional)"></textarea>
        <div class="text-right text-[10px] font-bold text-gray-400 mt-1"><span id="rateCharCount">0</span>/500</div>

        <button type="button" id="rateSubmitBtn" class="rate-submit" disabled onclick="submitReview()">Submit Rating</button>
    </div>
</div>

<div id="ordersToast"></div>

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

    // ---- Shared data contracts (must match BloominousApp) ----
    const REVIEWS_COLLECTION = 'reviews';
    const ONLINE_PAYMENT_METHODS = ['gcash', 'maya', 'paymaya', 'paymongo'];
    // Status words that mean each stage. Web + app + legacy values, normalized.
    const RECEIVED_STATUSES = ['confirmed', 'delivered', 'completed'];
    const DELIVERING_STATUSES = ['shipped', 'out_for_delivery', 'in_transit'];

    // Tab order + labels (same journey as the app's tracking row).
    const STAGES = [
        { key: 'all',        label: 'All' },
        { key: 'to_pay',     label: 'To Pay' },
        { key: 'to_ship',    label: 'To Ship' },
        { key: 'to_deliver', label: 'To Deliver' },
        { key: 'to_rate',    label: 'To Rate' },
        { key: 'completed',  label: 'Completed' },
        { key: 'cancelled',  label: 'Cancelled' }
    ];

    // What each card's top-right says for its stage.
    const STAGE_DISPLAY = {
        to_pay:     { icon: 'fa-wallet',        color: '#f59e0b', line: 'Waiting for payment',        label: 'To Pay' },
        to_ship:    { icon: 'fa-box',           color: '#6366f1', line: 'Your flowers are being prepared', label: 'To Ship' },
        to_deliver: { icon: 'fa-truck-fast',    color: '#14b8a6', line: 'Out for delivery',           label: 'To Deliver' },
        to_rate:    { icon: 'fa-truck',         color: '#14b8a6', line: 'Parcel has been delivered',  label: 'To Rate' },
        completed:  { icon: 'fa-truck',         color: '#14b8a6', line: 'Parcel has been delivered',  label: 'Completed' },
        cancelled:  { icon: 'fa-circle-xmark',  color: '#9ca3af', line: 'Order was cancelled',        label: 'Cancelled' }
    };

    const STAR_LABELS = { 1: 'Terrible', 2: 'Poor', 3: 'Fair', 4: 'Good', 5: 'Amazing' };

    const PLACEHOLDER_IMG = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160"><rect width="100%" height="100%" fill="#f3efe8"/><text x="50%" y="56%" font-size="60" text-anchor="middle">🌸</text></svg>'
    );

    // ---- Page state ----
    let latestOrders = null;   // null = first snapshot not in yet
    let myReviews = {};        // reviewId -> review (web OR app)
    let branchNames = {};      // branchId -> display name
    let cartItems = {};
    let cartReady = null;
    // Allow deep links like my_orders.php?tab=to_rate (same idea as the app's initialTabIndex).
    let activeStage = (() => {
        const t = new URLSearchParams(window.location.search).get('tab');
        return STAGES.some(s => s.key === t) ? t : 'all';
    })();
    let orderSearch = '';
    let rateTarget = null;
    let selectedRating = 0;

    // ------------------------------------------------------------------
    // HELPERS
    // ------------------------------------------------------------------
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

    function starsHtml(count) {
        let html = '';
        for (let i = 1; i <= 5; i++) html += `<i class="${i <= count ? 'fa-solid' : 'fa-regular'} fa-star"></i>`;
        return html;
    }

    function showToast(message) {
        const t = document.getElementById('ordersToast');
        t.innerText = message;
        t.classList.add('show');
        clearTimeout(showToast._timer);
        showToast._timer = setTimeout(() => t.classList.remove('show'), 2600);
    }

    // "In Transit" / "in-transit" / "IN_TRANSIT" -> "in_transit"
    function normalizeStatus(raw) {
        return String(raw || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
    }

    function buildReviewId(orderId, productId) {
        return `${orderId}_${productId}`;
    }

    function itemProductId(item) {
        return (item && (item.productId || item.id)) || '';
    }

    function itemQty(item) {
        const q = parseInt(item && (item.qty ?? item.quantity), 10);
        return Number.isNaN(q) || q < 1 ? 1 : q;
    }

    // ------------------------------------------------------------------
    // STAGE LOGIC — mirrors the app's _buildOrderTrackingRow
    // ------------------------------------------------------------------
    // Returns: 'cancelled' | 'to_pay' | 'to_ship' | 'to_deliver' | 'received'
    function baseStage(o) {
        const status = normalizeStatus(o.status);
        if (status === 'cancelled') return 'cancelled';
        if (RECEIVED_STATUSES.includes(status)) return 'received';
        if (DELIVERING_STATUSES.includes(status)) return 'to_deliver';

        const paymentStatus = String(o.paymentStatus || o.payment_status || '').toLowerCase();
        const paymentMethod = String(o.payment_method || o.paymentMethod || '').toLowerCase();
        const isOnlinePayment = ONLINE_PAYMENT_METHODS.includes(paymentMethod);
        const paymentUnresolved = paymentStatus.includes('pending') || paymentStatus.includes('awaiting');
        return (isOnlinePayment && paymentUnresolved) ? 'to_pay' : 'to_ship';
    }

    // Items in a received order that can still be rated (have an id, no review yet).
    function unratedItems(o) {
        return (Array.isArray(o.items) ? o.items : []).filter(item => {
            const pid = itemProductId(item);
            return pid && !myReviews[buildReviewId(o.id, pid)];
        });
    }

    // Final tab for an order: received orders split into To Rate vs Completed.
    function orderStage(o) {
        const base = baseStage(o);
        if (base !== 'received') return base;
        return unratedItems(o).length > 0 ? 'to_rate' : 'completed';
    }

    // Badge numbers = item quantities, exactly like the app's red badges.
    function stageCounts() {
        const counts = { to_pay: 0, to_ship: 0, to_deliver: 0, to_rate: 0 };
        (latestOrders || []).forEach(o => {
            const stage = orderStage(o);
            if (stage === 'to_rate') {
                counts.to_rate += unratedItems(o).reduce((sum, i) => sum + itemQty(i), 0);
            } else if (counts[stage] !== undefined) {
                counts[stage] += (o.items || []).reduce((sum, i) => sum + itemQty(i), 0);
            }
        });
        return counts;
    }

    function matchesSearch(o, term) {
        if (!term) return true;
        const q = term.toLowerCase();
        const haystack = [
            o.id,
            o.invoiceId,
            branchNames[o.branchId] || o.branchId,
            ...(o.items || []).map(i => i.name)
        ].filter(Boolean).join(' ').toLowerCase();
        return haystack.includes(q);
    }

    // ------------------------------------------------------------------
    // RENDER
    // ------------------------------------------------------------------
    function renderTabs() {
        const counts = stageCounts();
        document.getElementById('stageTabs').innerHTML = STAGES.map(s => {
            const n = counts[s.key] || 0;
            return `<button class="stage-tab ${s.key === activeStage ? 'active' : ''}" onclick="setStage('${s.key}')">
                ${s.label}${n > 0 ? `<span class="stage-count">${n > 99 ? '99+' : n}</span>` : ''}
            </button>`;
        }).join('');
    }

    function setStage(key) {
        activeStage = key;
        // Keep the URL in sync so refresh/back stays on the same tab.
        const url = new URL(window.location.href);
        if (key === 'all') url.searchParams.delete('tab'); else url.searchParams.set('tab', key);
        history.replaceState(null, '', url);
        renderAll();
    }

    function emptyStateHtml() {
        const messages = {
            all: ['No orders yet', 'Ready to brighten someone\'s day?'],
            to_pay: ['Nothing to pay', 'Orders waiting for GCash/Maya payment show up here.'],
            to_ship: ['Nothing to ship', 'Orders being prepared by the florist show up here.'],
            to_deliver: ['Nothing on the way', 'Orders out for delivery show up here.'],
            to_rate: ['All caught up!', 'Received items waiting for your rating show up here.'],
            completed: ['No completed orders', 'Delivered and rated orders show up here.'],
            cancelled: ['No cancelled orders', 'Good news — nothing was cancelled.']
        };
        const [title, sub] = orderSearch ? ['No matching orders', `Nothing matches "${escapeHtml(orderSearch)}".`] : messages[activeStage];
        return `
            <div class="empty-state">
                <div class="w-20 h-20 bg-orange-50 rounded-full flex items-center justify-center text-orange-400 mx-auto mb-6">
                    <i class="fa-solid fa-receipt fa-2x"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800 mb-2">${title}</h3>
                <p class="text-gray-400 text-sm mb-8">${sub}</p>
                <a href="shop.php" class="px-8 py-3 bg-orange-500 text-white rounded-full font-semibold text-sm hover:bg-orange-600 transition-all">Continue Shopping</a>
            </div>`;
    }

    function orderCardHtml(o) {
        const stage = orderStage(o);
        const display = STAGE_DISPLAY[stage];
        const isReceived = stage === 'to_rate' || stage === 'completed';
        const branchName = branchNames[o.branchId] || o.branchId || 'Bloominous';
        const total = o.total_price ?? o.total_amount ?? 0;
        const created = o.timestamp || o.createdAt;
        const date = created && created.toDate ? created.toDate().toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Just now';
        const items = Array.isArray(o.items) ? o.items : [];

        // --- One row per item; received orders get Rate / Rated stars ---
        const itemsHtml = items.map((item, index) => {
            const pid = itemProductId(item);
            const review = pid ? myReviews[buildReviewId(o.id, pid)] : null;
            const qty = itemQty(item);
            const lineTotal = (parseFloat(item.price) || 0) * qty;

            let rateHtml = '';
            if (review) {
                rateHtml = `<span class="rated-stars">${starsHtml(parseInt(review.rating || 0))}<small>Rated</small></span>`;
            } else if (isReceived && pid) {
                rateHtml = `<button class="btn-rate" onclick="openRateModal('${o.id}', ${index})"><i class="fa-solid fa-star mr-1"></i>Rate</button>`;
            }

            return `
                <div class="order-item">
                    <img src="${escapeHtml(item.image || PLACEHOLDER_IMG)}" alt="" onerror="this.src=PLACEHOLDER_IMG">
                    <div class="min-w-0">
                        <div class="order-item-name">${escapeHtml(item.name || 'Item')}</div>
                        <div class="order-item-meta">Branch: ${escapeHtml(branchNames[item.branchId] || branchName)}</div>
                        <div class="order-item-qty">x${qty}</div>
                    </div>
                    <div class="order-item-right">
                        <span class="order-item-price">${formatPeso(lineTotal)}</span>
                        ${rateHtml}
                    </div>
                </div>`;
        }).join('');

        // --- Footer buttons depend on the stage ---
        const canCancel = normalizeStatus(o.status) === 'pending';
        const trackBtn = `<a class="btn-outline" href="../track_order.php?id=${encodeURIComponent(o.id)}">Track Order</a>`;
        const cancelBtn = canCancel ? `<button class="btn-outline danger" onclick="cancelOrder('${o.id}')">Cancel Order</button>` : '';
        const buyAgainBtn = `<button class="btn-solid" onclick="buyAgain('${o.id}', this)">Buy Again</button>`;
        const rateAllBtn = stage === 'to_rate'
            ? `<button class="btn-solid" onclick="openFirstUnrated('${o.id}')">Rate</button>`
            : '';

        let actions = '';
        if (stage === 'to_pay' || stage === 'to_ship') actions = cancelBtn + trackBtn;
        else if (stage === 'to_deliver') actions = trackBtn;
        else if (stage === 'to_rate') actions = rateAllBtn + trackBtn;
        else if (stage === 'completed') actions = buyAgainBtn + trackBtn;
        else if (stage === 'cancelled') actions = buyAgainBtn;

        return `
            <div class="order-card">
                <div class="order-head">
                    <div class="order-shop">
                        <i class="fa-solid fa-store text-gray-500"></i>
                        <span>${escapeHtml(branchName)}</span>
                        <span class="tag">${o.type === 'POS' ? 'In-store' : 'Online'}</span>
                        ${o.invoiceId ? `<span class="invoice">${escapeHtml(o.invoiceId)}</span>` : ''}
                    </div>
                    <div class="order-state">
                        <span class="line" style="color:${display.color};"><i class="fa-solid ${display.icon}"></i>${display.line}</span>
                        <span class="divider"></span>
                        <span class="label">${display.label}</span>
                    </div>
                </div>

                ${itemsHtml || '<div class="order-item text-sm text-gray-400">No item details saved for this order.</div>'}

                <div class="order-foot">
                    <div class="order-total">Order Total: <strong>${formatPeso(total)}</strong></div>
                    <div class="order-date">Ordered ${escapeHtml(date)}</div>
                    <div class="order-actions">${actions}</div>
                </div>
            </div>`;
    }

    function renderOrders() {
        const list = document.getElementById('orders-list');
        if (latestOrders === null) return;

        const visible = latestOrders.filter(o =>
            (activeStage === 'all' || orderStage(o) === activeStage) && matchesSearch(o, orderSearch)
        );
        list.innerHTML = visible.length ? visible.map(orderCardHtml).join('') : emptyStateHtml();
    }

    function renderAll() {
        renderTabs();
        renderOrders();
    }

    function updateCartUI() {
        document.getElementById('cart-count').innerText = BloomCart.count(cartItems);
    }

    renderTabs(); // show tabs immediately (counts fill in when data arrives)

    if (firebaseConfig.apiKey) {
        firebase.initializeApp(firebaseConfig);
        const auth = firebase.auth();
        const db = firebase.firestore();
        const userId = "<?php echo htmlspecialchars($user_id, ENT_QUOTES); ?>";

        // Wait for Firebase Auth to restore the session before any query,
        // otherwise Firestore rules see no user and deny the read.
        auth.onAuthStateChanged((user) => {
            if (!user) {
                window.location.href = '../index.php';
                return;
            }

            const ordersList = document.getElementById('orders-list');

            // LISTENER 1: my orders (sorted client-side, no index needed).
            db.collection('orders')
              .where('user_id', '==', userId)
              .onSnapshot(snap => {
                const orders = [];
                snap.forEach(doc => orders.push({ id: doc.id, ...doc.data() }));
                orders.sort((a, b) => {
                    const ta = (a.timestamp || a.createdAt) ? (a.timestamp || a.createdAt).toMillis() : 0;
                    const tb = (b.timestamp || b.createdAt) ? (b.timestamp || b.createdAt).toMillis() : 0;
                    return tb - ta;
                });
                latestOrders = orders;
                renderAll();
            }, error => {
                console.error("Error fetching orders:", error);
                ordersList.innerHTML = `<p class="text-center text-red-500 font-bold">Error loading orders: ${escapeHtml(error.message)}</p>`;
            });

            // LISTENER 2: my reviews (web OR app) — moves orders from To Rate to Completed.
            db.collection(REVIEWS_COLLECTION)
              .where('userId', '==', userId)
              .onSnapshot(snap => {
                myReviews = {};
                snap.forEach(doc => { myReviews[doc.id] = doc.data(); });
                renderAll();
            }, error => console.warn("Could not load your reviews:", error.message));

            // Branch display names (public read), loaded once.
            db.collection('branches').get().then(snap => {
                snap.forEach(b => { branchNames[b.id] = (b.data() || {}).name || b.id; });
                renderAll();
            }).catch(err => console.warn('Branch names unavailable:', err.message));

            // Live cart badge.
            cartReady = BloomCart.whenSignedIn()
                .then(() => {
                    BloomCart.listen(items => { cartItems = items; updateCartUI(); });
                    return true;
                })
                .catch(() => false);
        });

        // --- Search box ---
        document.getElementById('orderSearch').addEventListener('input', (e) => {
            orderSearch = e.target.value.trim();
            renderOrders();
        });

        // ------------------------------------------------------------------
        // RATING MODAL
        // ------------------------------------------------------------------
        window.openRateModal = function(orderId, itemIndex) {
            const order = (latestOrders || []).find(o => o.id === orderId);
            const item = order && Array.isArray(order.items) ? order.items[itemIndex] : null;
            if (!order || !item) return;

            rateTarget = {
                orderId: orderId,
                productId: itemProductId(item),
                productName: item.name || 'Item',
                branchId: item.branchId || order.branchId || '',
                customerName: order.customer_name || order.customerName || 'Customer'
            };
            selectedRating = 0;
            paintStars(0);
            document.getElementById('rateProductName').innerText = rateTarget.productName;
            document.getElementById('rateComment').value = '';
            document.getElementById('rateCharCount').innerText = '0';
            document.getElementById('rateOverlay').classList.add('show');
        };

        // Footer "Rate" button: opens the first item still waiting for a rating.
        window.openFirstUnrated = function(orderId) {
            const order = (latestOrders || []).find(o => o.id === orderId);
            if (!order) return;
            const target = unratedItems(order)[0];
            if (target) openRateModal(orderId, order.items.indexOf(target));
        };

        window.closeRateModal = function() {
            rateTarget = null;
            document.getElementById('rateOverlay').classList.remove('show');
        };

        function paintStars(value) {
            document.querySelectorAll('#rateStars .rate-star').forEach(star => {
                star.classList.toggle('on', Number(star.dataset.value) <= value);
            });
            document.getElementById('rateLabel').innerText = value ? STAR_LABELS[value] : 'Tap a star to rate';
            document.getElementById('rateSubmitBtn').disabled = selectedRating === 0;
        }

        document.querySelectorAll('#rateStars .rate-star').forEach(star => {
            star.addEventListener('mouseenter', () => paintStars(Number(star.dataset.value)));
            star.addEventListener('click', () => {
                selectedRating = Number(star.dataset.value);
                paintStars(selectedRating);
            });
        });
        document.getElementById('rateStars').addEventListener('mouseleave', () => paintStars(selectedRating));

        document.getElementById('rateComment').addEventListener('input', (e) => {
            document.getElementById('rateCharCount').innerText = e.target.value.length;
        });

        document.getElementById('rateOverlay').addEventListener('click', (e) => {
            if (e.target.id === 'rateOverlay') closeRateModal();
        });

        window.submitReview = async function() {
            if (!rateTarget || selectedRating < 1 || selectedRating > 5) return;

            const btn = document.getElementById('rateSubmitBtn');
            btn.disabled = true;
            btn.innerText = 'Submitting...';

            const reviewId = buildReviewId(rateTarget.orderId, rateTarget.productId);

            try {
                // New id = CREATE (allowed). Existing id = UPDATE (denied by rules),
                // which is what blocks a second rating of the same item.
                await db.collection(REVIEWS_COLLECTION).doc(reviewId).set({
                    orderId: rateTarget.orderId,
                    productId: rateTarget.productId,
                    productName: rateTarget.productName,
                    branchId: rateTarget.branchId,
                    userId: userId,
                    customerName: rateTarget.customerName,
                    rating: selectedRating,
                    comment: document.getElementById('rateComment').value.trim().slice(0, 500),
                    source: 'web',
                    createdAt: firebase.firestore.FieldValue.serverTimestamp()
                });
                closeRateModal();
                showToast('Thank you! Your rating has been recorded.');
            } catch (error) {
                console.error("Error submitting review:", error);
                showToast(error.code === 'permission-denied'
                    ? 'This item was already rated, or the order is not received yet.'
                    : 'Failed to submit rating: ' + error.message);
            } finally {
                btn.innerText = 'Submit Rating';
                btn.disabled = selectedRating === 0;
            }
        };

        // ------------------------------------------------------------------
        // ORDER ACTIONS
        // ------------------------------------------------------------------
        window.cancelOrder = async function(orderId) {
            if (!confirm('Are you sure you want to cancel this order? This action cannot be undone.')) return;
            try {
                await db.collection('orders').doc(orderId).update({
                    status: 'cancelled',
                    cancelledAt: firebase.firestore.FieldValue.serverTimestamp()
                });
                showToast('Order cancelled.');
            } catch (error) {
                console.error("Error cancelling order:", error);
                showToast('Failed to cancel order: ' + error.message);
            }
        };

        // Re-adds every item of an order to the Firestore cart, then opens checkout.
        window.buyAgain = async function(orderId, btn) {
            const order = (latestOrders || []).find(o => o.id === orderId);
            if (!order || !Array.isArray(order.items) || order.items.length === 0) return;

            const ready = await cartReady;
            if (!ready) { showToast('Please log in again to use your cart.'); return; }

            if (btn) { btn.disabled = true; btn.innerText = 'Adding...'; }
            try {
                for (const item of order.items) {
                    const pid = itemProductId(item);
                    if (!pid) continue;
                    await BloomCart.add({
                        id: pid,
                        name: item.name,
                        price: item.price,
                        branchId: item.branchId || order.branchId || null,
                        image: item.image || null
                    }, itemQty(item));
                }
                await goToCheckout();
            } catch (err) {
                console.error('Buy again failed:', err);
                showToast('Could not add items to cart: ' + err.message);
                if (btn) { btn.disabled = false; btn.innerText = 'Buy Again'; }
            }
        };
    }

    // Opens checkout with the current cart total (same as shop.php).
    async function goToCheckout() {
        try {
            const items = await BloomCart.load();
            window.location.href = 'checkout.php?amount=' + BloomCart.total(items);
        } catch (err) {
            showToast('Could not open checkout: ' + err.message);
        }
    }
</script>

</body>
</html>