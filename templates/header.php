<?php
// These two are computed unconditionally, every request — the forced
// setcookie() call below needs them regardless of whether a session was
// already active or brand new this request.
$bloomIsHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$bloomSessionLifetimeSeconds = 60 * 60 * 24 * 30; // 30 days — stay logged in until actual logout

// Only configure cookie PARAMS if no session is active yet — calling
// session_set_cookie_params() on an already-active session throws a PHP
// warning.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => $bloomSessionLifetimeSeconds,
        'path' => '/',
        'domain' => '',
        'secure' => $bloomIsHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) $bloomSessionLifetimeSeconds);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// FIXED: PHP only sends a Set-Cookie header when a session is first
// CREATED — an existing session cookie is just silently reused with
// whatever expiration it originally had, no matter what gets configured
// afterward. Since this file runs on EVERY admin page after login, this
// forces a fresh Set-Cookie on every single page load — a sliding
// window, so an actively used account effectively never expires.
setcookie(session_name(), session_id(), [
    'expires' => time() + $bloomSessionLifetimeSeconds,
    'path' => '/',
    'domain' => '',
    'secure' => $bloomIsHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? $_SESSION['admin_role'] ?? '';

// 1. If not logged in at all, redirect to index.php
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id']) && !isset($_SESSION['customer_id']) && !isset($_SESSION['delivery_id'])) {
    header("Location: index.php");
    exit();
}

// 2. Customers are never allowed to see any of the admin management pages
if ($user_role === 'customer') {
    header("Location: templates/shop.php");
    exit();
}

// 3. Delivery personnel can ONLY see delivery_status.php
if ($user_role === 'delivery') {
    if ($current_page !== 'delivery_status.php' && $current_page !== 'logout.php') {
        header("Location: delivery_status.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bloominous Admin</title>

    <!-- NEW: Theme resolution -- runs BEFORE Tailwind/CSS load and BEFORE
         the page paints, so there is no light-mode "flash" that then
         flips to dark a moment later. Resolution order, exactly as
         requested: an explicit saved choice always wins; otherwise the
         browser's own OS/system dark-mode setting decides; otherwise
         light. The <html> element gets class="dark" (for Tailwind's
         `dark:` variant to key off of) AND data-theme="dark" (for the
         plain-CSS variables below, which don't depend on Tailwind at
         all) -- both are set together so either styling approach works
         on any given page. -->
    <script>
        (function () {
            const saved = localStorage.getItem('bloom_theme'); // 'dark' | 'light' | null
            const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const isDark = saved ? saved === 'dark' : systemPrefersDark;
            const root = document.documentElement;
            if (isDark) {
                root.classList.add('dark');
                root.setAttribute('data-theme', 'dark');
            } else {
                root.classList.remove('dark');
                root.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // NEW: tells Tailwind to switch on the presence of the 'dark'
        // class on <html> (set by the script above) rather than its
        // default behavior, which otherwise ONLY follows the OS setting
        // live with no way for a saved user preference to override it.
        // This is what makes `dark:bg-[#1A1A1A]`-style utility classes
        // usable on any page from here on.
        tailwind.config = { darkMode: 'class' };
    </script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Cormorant+Garamond:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Firebase SDK -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>

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
            window.db = firebase.firestore();
            window.auth = firebase.auth();
        }

        // Global Branch Management
        window.currentBranch = localStorage.getItem('bloom_branch_id') || '<?php echo $_SESSION['branchId'] ?? 'main_branch'; ?>';
        window.currentUserRole = '<?php echo $user_role; ?>';
        window.currentUserName = '<?php echo addslashes($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Staff'); ?>';
        window.currentUserEmail = '<?php echo addslashes(strtolower($_SESSION['email'] ?? '')); ?>';
        
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super-admin'): ?>
            window.currentBranch = '<?php echo $_SESSION['branchId'] ?? 'main_branch'; ?>';
            localStorage.setItem('bloom_branch_id', window.currentBranch);
        <?php endif; ?>

        window.getBranchPath = (collectionName) => {
            if (collectionName === 'orders' || collectionName === 'customers' || collectionName === 'users') {
                return db.collection(collectionName);
            }
            return db.collection('branches').doc(window.currentBranch).collection(collectionName);
        };

        window.setBranch = (branchId) => {
            localStorage.setItem('bloom_branch_id', branchId);
            window.location.reload();
        };

        // NEW: theme API, callable from ANY page (e.g. settings.php's
        // Dark Mode switch). Persists the explicit choice so it's what
        // the resolution script in <head> finds on every future page
        // load -- from this point on, the saved choice always wins over
        // the system preference, exactly like the mobile app's own
        // _persistTheme() -> shared_preferences flow. A full page
        // reload (rather than a live class toggle) is used deliberately
        // so every element on the page -- including ones rendered by
        // inline PHP <?php ?> conditionals that only run once per
        // request -- gets a consistent, correctly-themed render, rather
        // than a partial live-DOM flip that could miss something.
        window.setBloomTheme = (mode) => {
            localStorage.setItem('bloom_theme', mode); // 'dark' or 'light'
            window.location.reload();
        };
        window.toggleBloomTheme = () => {
            const isDark = document.documentElement.classList.contains('dark');
            window.setBloomTheme(isDark ? 'light' : 'dark');
        };
        // Lets a page (e.g. settings.php) know which state to render its
        // toggle switch in, without needing its own separate localStorage
        // read -- one source of truth.
        window.isBloomThemeDark = () => document.documentElement.classList.contains('dark');

        // --- Invoice / Void system: Phase 1 data-model helpers ---

        // Transaction-safe sequential invoice numbering (INV-2026-0001, INV-2026-0002, ...).
        // Uses a Firestore transaction so two simultaneous checkouts (any branch) can never
        // be issued the same number.
        window.generateInvoiceId = async () => {
            const counterRef = db.collection('counters').doc('invoices');
            const year = new Date().getFullYear();
            let invoiceId;
            await db.runTransaction(async (transaction) => {
                const counterDoc = await transaction.get(counterRef);
                const data = counterDoc.exists ? counterDoc.data() : {};
                // Running number resets automatically whenever the year rolls over
                const nextNumber = (data.year === year ? (data.current || 0) : 0) + 1;
                invoiceId = `INV-${year}-${String(nextNumber).padStart(4, '0')}`;
                transaction.set(counterRef, { current: nextNumber, year: year }, { merge: true });
            });
            return invoiceId;
        };

        // Same pattern, reserved for the Void module (VOID-2026-0001, ...).
        // No refund counter exists anymore — the business does not return money on a
        // cancelled/voided item, so there is nothing to number besides the void itself.
        window.generateVoidId = async () => {
            const counterRef = db.collection('counters').doc('voids');
            const year = new Date().getFullYear();
            let voidId;
            await db.runTransaction(async (transaction) => {
                const counterDoc = await transaction.get(counterRef);
                const data = counterDoc.exists ? counterDoc.data() : {};
                const nextNumber = (data.year === year ? (data.current || 0) : 0) + 1;
                voidId = `VOID-${year}-${String(nextNumber).padStart(4, '0')}`;
                transaction.set(counterRef, { current: nextNumber, year: year }, { merge: true });
            });
            return voidId;
        };

        // Returns true if the order is a finalized invoice (locked) and must not be edited directly.
        window.isOrderLocked = async (orderId) => {
            const doc = await db.collection('orders').doc(orderId).get();
            if (!doc.exists) return false;
            return doc.data().locked === true;
        };

        // Call before any direct status/field edit on an order. Returns true (and alerts the
        // user) if the edit should be BLOCKED because the invoice is already finalized.
        window.blockIfLocked = async (orderId) => {
            const locked = await window.isOrderLocked(orderId);
            if (locked) {
                alert("This invoice is finalized and can't be edited directly. Use the Void module instead.");
            }
            return locked;
        };

        // Auto-check expired Recycled Bouquet (2 days expiration) & cleanup "Recycled Flowers"
        async function checkExpiredRecycledBouquets() {
            if (!window.db || !window.currentBranch) return;
            try {
                const invRef = db.collection('branches').doc(window.currentBranch).collection('inventory');
                
                // Cleanup any stale 'Recycled Flowers' documents so they are deleted
                const flowersSnap = await invRef.where('name', '==', 'Recycled Flowers').get();
                if (!flowersSnap.empty) {
                    const batchDel = db.batch();
                    flowersSnap.forEach(d => {
                        batchDel.delete(d.ref);
                    });
                    await batchDel.commit();
                    console.log('Successfully deleted old Recycled Flowers from inventory.');
                }

                const snap = await invRef.where('name', '==', 'Recycled Bouquet').get();
                if (snap.empty) return;

                const doc = snap.docs[0];
                const bouquet = doc.data();
                const stock = bouquet.stock || 0;
                if (stock <= 0) return;

                const timestamp = bouquet.updatedAt || bouquet.createdAt;
                if (!timestamp) return;

                const date = timestamp.toDate();
                const now = new Date();
                const diffTime = now - date; // in milliseconds
                const diffDays = diffTime / (1000 * 60 * 60 * 24);

                if (diffDays >= 2) {
                    const batch = db.batch();
                    
                    // 1. Set stock of Recycled Bouquet to 0
                    batch.update(doc.ref, {
                        stock: 0,
                        updatedAt: firebase.firestore.FieldValue.serverTimestamp()
                    });

                    // 2. Add Spoilage/Loss Record
                    const spoilRef = db.collection('branches').doc(window.currentBranch).collection('spoilage').doc();
                    batch.set(spoilRef, {
                        productId: doc.id,
                        product_id: doc.id,
                        flower_name: 'Recycled Bouquet',
                        quantity: stock,
                        loss_amount: stock * (bouquet.price || 150.0),
                        reason: 'Expired Recycled Bouquet',
                        reported_by: 'System (Auto Expiry)',
                        is_salvaged: false,
                        createdAt: firebase.firestore.FieldValue.serverTimestamp(),
                        created_at: firebase.firestore.FieldValue.serverTimestamp()
                    });

                    // 3. Add Notification
                    const notifRef = db.collection('notifications').doc();
                    batch.set(notifRef, {
                        title: 'Recycled Bouquet Expired',
                        message: `[${window.currentBranch}] ${stock} pcs of Recycled Bouquet expired after 2 days and moved to spoilage.`,
                        type: 'warning',
                        branchId: window.currentBranch,
                        created_at: firebase.firestore.FieldValue.serverTimestamp(),
                        read: false
                    });

                    await batch.commit();
                    console.log('Successfully deleted expired items.');
                }
            } catch (err) {
                console.error('Error handling expired items: ', err);
            }
        }

        // Run the auto-expiration check when page loads
        document.addEventListener('DOMContentLoaded', () => {
            if (window.db) {
                setTimeout(checkExpiredRecycledBouquets, 2000);
            }
        });
    </script>

    <style>
        :root {
            /* BRAND COLOR REMAP: Shifted from hot pink properties to brand logo amber yellow specs */
            --primary: #F59E0B;
            --secondary: #7B79F2;
            --background: #FFFDF7;
            --accent: #FF5252;
            --dark: #121212;
            --text-main: #363949;
            --text-light: #7d8da1;

            /* NEW: neutral tokens the rest of this stylesheet now reads
               through, instead of hardcoding white/black directly.
               Light-mode values here match what the page already looked
               like before -- this is a relabeling, not a redesign. */
            --surface: #ffffff;
            --surface-alt: #fafafa;
            --border-color: #f0f0f0;
            --text-secondary: #6b7280;
        }

        /* NEW: dark-mode palette. Deliberately reuses the EXACT hex
           values auth_page.dart already uses for its dark theme
           (bgColor/cardColor/textColor/subTextColor/borderColor), so a
           super-admin who has dark mode on in the app sees the same
           visual language on the web portal -- not two unrelated "dark
           modes" that happen to share a name. --primary/--secondary are
           intentionally NOT overridden here -- the brand amber and
           purple stay identical in both themes, since the ask was to
           "keep the yellow areas" as the one constant across light and
           dark. */
        html[data-theme="dark"] {
            --background: #1C1814;
            --dark: #EAE6DF;
            --text-main: #EAE6DF;
            --text-light: #A0998F;
            --surface: #2A241D;
            --surface-alt: #221D17;
            --border-color: #3F382F;
            --text-secondary: #A0998F;
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--background); color: var(--text-main); transition: background-color 0.2s ease, color 0.2s ease; }
        h1, h2, h3, .brand-font { font-family: 'Cormorant Garamond', serif; }
        
        .sidebar { width: 260px; height: 100vh; position: fixed; left: 0; top: 0; background: var(--surface); box-shadow: 2px 0 10px rgba(0,0,0,0.03); z-index: 100; overflow-y: auto; border-right: 1px solid var(--border-color); }
        .main-content { margin-left: 260px; padding: 20px; }
        .sidebar-link { display: flex; align-items: center; gap: 15px; padding: 12px 25px; color: var(--text-main); transition: 0.3s; text-decoration: none; font-weight: 600; font-size: 0.85rem; border-radius: 0 50px 50px 0; margin-right: 20px; margin-bottom: 2px; }
        
        /* Active/hover tint stays amber-based in BOTH themes on purpose
           -- this is exactly the "keep the yellow areas" instruction.
           A low-alpha amber overlay reads correctly against both a
           white surface and the dark #2A241D surface without needing
           two separate rules. */
        .sidebar-link:hover, .sidebar-link.active { background: rgba(245, 158, 11, 0.08); color: var(--primary); }
        .sidebar-link.active { border-left: 4px solid var(--primary); background: rgba(245, 158, 11, 0.12); }
        .sidebar-link i { font-size: 1.1rem; width: 20px; text-align: center; }

        .card { background: var(--surface); padding: 24px; border-radius: 24px; box-shadow: 0 10px 20px rgba(0,0,0,0.02); border: 1px solid var(--border-color); transition: 0.3s; }
        .card:hover { transform: translateY(-4px); box-shadow: 0 15px 30px rgba(0,0,0,0.05); }

        /* --primary/--secondary untouched between themes -- these
           buttons look identical in light and dark, as intended. */
        .btn-primary { background: var(--primary); color: white; padding: 10px 20px; border-radius: 12px; font-weight: 700; transition: 0.3s; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; }
        .btn-primary:hover { opacity: 0.9; transform: scale(1.02); }

        .btn-secondary { background: var(--secondary); color: white; padding: 10px 20px; border-radius: 12px; font-weight: 700; transition: 0.3s; border: none; cursor: pointer; font-size: 0.85rem; }
        .btn-secondary:hover { opacity: 0.9; }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-light); }

        /* Makes the top-right identity chip look/feel like the clickable
           "go to my profile" affordance it now is, without touching the
           surrounding layout at all. */
        .profile-link { text-decoration: none; color: inherit; display: flex; align-items: center; gap: 12px; border-radius: 14px; padding: 4px 8px; margin: -4px -8px; transition: 0.2s; }
        .profile-link:hover { background: rgba(245, 158, 11, 0.06); }

        /* NEW: the shared topbar container (branch selector row, bell,
           profile chip) and the notification dropdown -- both live in
           header.php's own markup, so they get full dark treatment here
           directly, same as the sidebar above. Any page-specific card
           (e.g. a dashboard stat tile) still needs its own `dark:`
           Tailwind classes added when that page is next touched. */
        .kiri-topbar, #notif-dropdown { background: var(--surface) !important; border-color: var(--border-color) !important; }
        #notif-dropdown .border-gray-100 { border-color: var(--border-color) !important; }
        #notif-dropdown .text-gray-800 { color: var(--text-main) !important; }
        #notif-dropdown .hover\:bg-gray-50:hover { background: var(--surface-alt) !important; }
        #branch-selector { background: var(--surface-alt) !important; color: var(--text-main) !important; border-color: var(--border-color) !important; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="pt-8 px-8 pb-4 text-center border-b border-gray-50 bg-white" style="background: var(--surface); border-color: var(--border-color);">
        <a href="admin.php" class="inline-block no-underline" style="text-decoration: none;">
            <div style="height: 45px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px;">
                <img src="assets/images/asset.png" alt="BLOOM" style="max-height: 100%; max-width: 100%; object-fit: contain;" onerror="this.src='assets/images/asset.jpg'">
            </div>
            <!-- UI FIX: Realigned typography branding color matrix to brand amber-yellow -->
            <p class="m-0 brand-font text-lg font-black tracking-widest text-[#F59E0B] no-underline" style="text-decoration: none;">BLOOMINOUS</p>
            <p class="m-0 text-[9px] uppercase tracking-[0.3em] font-bold no-underline" style="text-decoration: none; color: var(--text-light);">Management System</p>
        </a>
    </div>

    <nav class="mt-2 px-2">
        <a href="admin.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-grid-2"></i>
            <span>Dashboard</span>
        </a>
        <a href="order_management.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'order_management.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-shopping-bag"></i>
            <span>Orders</span>
        </a>
        <a href="invoice_portal.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'invoice_portal.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-receipt"></i>
            <span>Invoice Portal</span>
        </a>
        <a href="preorder_reservation.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'preorder_reservation.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Event Organizer & Reservation</span>
        </a>
        <!-- Fraud Analytics Clickable Nav Entry with Active Icon Logic -->
        <a href="fraud_analytics.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'fraud_analytics.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-user-secret"></i>
            <span>Fraud Analytics</span>
        </a>
        <a href="sales_anomalies.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'sales_anomalies.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>Sales Anomalies</span>
        </a>
        <a href="override_codes.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'override_codes.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-key"></i>
            <span>Override Codes</span>
        </a>

         <a href="admin_activity_log.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_activity_log.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-clipboard-list"></i>
            <span>Admin Activity Log</span>
        </a>



        <a href="product_management.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'product_management.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-box"></i>
            <span>Inventory</span>
        </a>

        <a href="kiri_generator.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'kiri_generator.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-cube"></i>
            <span>3D Realism Hub</span>
        </a>

        <a href="product_catalog.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'product_catalog.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-list"></i>
            <span>Product Catalog</span>
        </a>
        <a href="customer.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'customer.php' || basename($_SERVER['PHP_SELF']) == 'customer_profile.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-users"></i>
            <span>Customers</span>
        </a>
        <a href="supplier.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'supplier.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-truck"></i>
            <span>Suppliers</span>
        </a>
        <a href="delivery_status.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'delivery_status.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-truck-fast"></i>
            <span>Delivery Status</span>
        </a>
        <a href="pos.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'pos.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-chart-line"></i>
            <span>Sales Report</span>
        </a>
        <a href="pos_terminal.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'pos_terminal.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-cash-register"></i>
            <span>POS Terminal</span>
        </a>
        <a href="spoilage_tracking.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'spoilage_tracking.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-trash-can"></i>
            <span>Spoilage Tracker</span>
        </a>
        <a href="freshness.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'freshness.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            <span>Freshness Analysis</span>
        </a>
        <a href="promos_discounts.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'promos_discounts.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-tags"></i>
            <span>Promos & Discounts</span>
        </a>
        <a href="manage_accounts.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_accounts.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-user-gear"></i>
            <span>Manage Accounts</span>
        </a>
        <a href="settings.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-cog"></i>
            <span>Settings</span>
        </a>
        <a href="logout.php" class="sidebar-link text-red-400 mt-10">
            <i class="fa-solid fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </nav>
</div>

<div class="main-content">
    <div class="kiri-topbar flex justify-end items-center mb-8 p-4 rounded-2xl shadow-sm" style="background: var(--surface); border: 1px solid var(--border-color);">
        <div id="notification-bell" class="relative cursor-pointer mr-6">
            <i class="fa-solid fa-bell text-xl" style="color: var(--text-light);"></i>
            <span id="notif-count" class="hidden absolute -top-2 -right-2 bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">0</span>
            
            <!-- Notification Dropdown -->
            <div id="notif-dropdown" class="hidden absolute right-0 mt-4 w-80 bg-white rounded-2xl shadow-2xl border border-gray-100 z-[200] overflow-hidden">
                <div class="p-4 border-b border-gray-100 flex justify-between items-center">
                    <h4 class="font-black text-xs uppercase tracking-widest text-gray-800">Notifications</h4>
                    <button id="clear-notifs" class="text-[10px] text-indigo-500 font-bold uppercase">Clear All</button>
                </div>
                <div id="notif-list" class="max-h-96 overflow-y-auto">
                    <div class="p-8 text-center text-gray-300 italic text-xs">No new notifications</div>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-4 mr-6 border-r pr-6" style="border-color: var(--border-color);">
            <div class="relative">
                <select id="branch-selector" onchange="setBranch(this.value)" <?php echo (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super-admin') ? 'disabled' : ''; ?> class="bg-gray-50 border border-gray-200 text-[10px] font-black uppercase tracking-widest rounded-lg focus:ring-indigo-500 focus:border-indigo-500 block w-full p-2.5 appearance-none pr-8 cursor-pointer disabled:opacity-50">
                    <option value="main_branch">Main Branch</option>
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2" style="color: var(--text-light);">
                    <i class="fa-solid fa-chevron-down text-[8px]"></i>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const selector = document.getElementById('branch-selector');
                    selector.value = window.currentBranch;

                    // Load all branches from Firestore
                    db.collection('branches').get().then(snap => {
                        selector.innerHTML = '';
                        
                        snap.forEach(doc => {
                            const opt = document.createElement('option');
                            opt.value = doc.id;
                            opt.text = doc.data().name || doc.id;
                            selector.appendChild(opt);
                        });

                        // Re-select current
                        selector.value = window.currentBranch;
                    });
                });
            </script>
        </div>
        <a href="profile.php" class="profile-link">
            <div class="text-right">
                <p class="m-0 text-xs font-bold" style="color: var(--text-main);"><?php echo $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'User'; ?></p>
                <p class="m-0 text-[10px] uppercase font-bold" style="color: var(--text-light);"><?php 
                    $dispRole = $_SESSION['role'] ?? 'Member';
                    echo str_replace('-', ' ', $dispRole);
                ?></p>
            </div>
            <!-- UI FIX: Remapped standard fallback profile layout avatar card background wrapper to a soft amber yellow background layout tint -->
            <div class="w-10 h-10 rounded-xl <?php echo ($_SESSION['role'] ?? '') === 'super-admin' ? 'bg-gray-800 text-white' : 'bg-amber-100 text-amber-500'; ?> flex items-center justify-center font-black text-sm">
                <?php echo strtolower(substr($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)); ?>
            </div>
        </a>
    </div>

    <!-- NOTIFICATION SYSTEM SCRIPT MATRIX INJECTION -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (!window.db) return;

            const bell = document.getElementById('notification-bell');
            const dropdown = document.getElementById('notif-dropdown');
            const countBadge = document.getElementById('notif-count');
            const notifList = document.getElementById('notif-list');
            const clearBtn = document.getElementById('clear-notifs');

            // Toggle Dropdown Visibility Matrix
            bell.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdown.classList.toggle('hidden');
            });
            document.addEventListener('click', () => dropdown.classList.add('hidden'));
            dropdown.addEventListener('click', (e) => e.stopPropagation());

            // Build dynamic live query targeting notification items
            let notifQuery = db.collection('notifications');
            
            // SECURITY FILTER CONTEXT: If the user is a location-specific Admin, only show notifications matching their branchId.
            // If the user is a Super Admin, do not clip by branchId—give them omniscient corporate overview insight telemetry.
            if (window.currentUserRole !== 'super-admin') {
                notifQuery = notifQuery.where('branchId', '==', window.currentBranch);
            }

            // Real-Time Listener Hook
            notifQuery.orderBy('created_at', 'desc').limit(20).onSnapshot(snap => {
                if (snap.empty) {
                    notifList.innerHTML = `<div class="p-8 text-center text-gray-300 italic text-xs">No new notifications</div>`;
                    countBadge.classList.add('hidden');
                    countBadge.innerText = "0";
                    return;
                }

                let unreadCount = 0;
                let html = '';

                snap.forEach(doc => {
                    const n = doc.data();
                    if (!n.read) unreadCount++;

                    let icon = '<i class="fa-solid fa-circle-info text-blue-500"></i>';
                    if (n.type === 'warning' || n.type === 'fraud') icon = '<i class="fa-solid fa-triangle-exclamation text-amber-500"></i>';
                    if (n.type === 'success' || n.type === 'sale') icon = '<i class="fa-solid fa-circle-check text-green-500"></i>';

                    html += `
                        <div class="p-4 border-b border-gray-50 flex items-start gap-3 hover:bg-gray-50 transition-colors cursor-pointer ${!n.read ? 'bg-amber-50/20 font-medium' : ''}" onclick="markAsRead('${doc.id}')">
                            <div class="mt-0.5 text-sm">${icon}</div>
                            <div class="flex-1">
                                <div class="text-xs text-gray-800 font-bold">${n.title || 'System Broadcast'}</div>
                                <div class="text-[11px] text-gray-500 mt-0.5 leading-relaxed">${n.message || ''}</div>
                            </div>
                        </div>
                    `;
                });

                notifList.innerHTML = html;

                if (unreadCount > 0) {
                    countBadge.innerText = unreadCount;
                    countBadge.classList.remove('hidden');
                } else {
                    countBadge.classList.add('hidden');
                }
            }, err => console.error("Notification telemetry error: ", err));

            // Mark a single notification document as read
            window.markAsRead = async (id) => {
                try {
                    await db.collection('notifications').doc(id).update({ read: true });
                } catch(e) { console.error("Update blocked: ", e); }
            };

            // Clear All notifications within the current user's scope
            clearBtn.addEventListener('click', async () => {
                try {
                    let snapshot;
                    if (window.currentUserRole === 'super-admin') {
                        snapshot = await db.collection('notifications').get();
                    } else {
                        snapshot = await db.collection('notifications').where('branchId', '==', window.currentBranch).get();
                    }

                    if (snapshot.empty) return;
                    const batch = db.batch();
                    snapshot.forEach(doc => batch.delete(doc.ref));
                    await batch.commit();
                } catch(e) { alert("Purge failed: " + e.message); }
            });
        });
    </script>