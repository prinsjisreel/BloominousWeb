<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    <script src="https://cdn.tailwindcss.com"></script>
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
                    console.log('Successfully expired Recycled Bouquet:', stock, 'pcs');
                }
            } catch (err) {
                console.error('Error auto-expiring Recycled Bouquet:', err);
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
            --primary: #E91E63;
            --secondary: #7B79F2;
            --background: #FFFDF7;
            --accent: #FF5252;
            --dark: #121212;
            --text-main: #363949;
            --text-light: #7d8da1;
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--background); color: var(--text-main); }
        h1, h2, h3, .brand-font { font-family: 'Cormorant Garamond', serif; }
        
        .sidebar { width: 260px; height: 100vh; position: fixed; left: 0; top: 0; background: #fff; box-shadow: 2px 0 10px rgba(0,0,0,0.03); z-index: 100; overflow-y: auto; border-right: 1px solid #f0f0f0; }
        .main-content { margin-left: 260px; padding: 20px; }
        .sidebar-link { display: flex; align-items: center; gap: 15px; padding: 12px 25px; color: var(--text-light); transition: 0.3s; text-decoration: none; font-weight: 600; font-size: 0.85rem; border-radius: 0 50px 50px 0; margin-right: 20px; margin-bottom: 2px; }
        .sidebar-link:hover, .sidebar-link.active { background: rgba(233, 30, 99, 0.05); color: var(--primary); }
        .sidebar-link.active { border-left: 4px solid var(--primary); background: rgba(233, 30, 99, 0.08); }
        .sidebar-link i { font-size: 1.1rem; width: 20px; text-align: center; }

        .card { background: #fff; padding: 24px; border-radius: 24px; box-shadow: 0 10px 20px rgba(0,0,0,0.02); border: 1px solid #f0f0f0; transition: 0.3s; }
        .card:hover { transform: translateY(-4px); box-shadow: 0 15px 30px rgba(0,0,0,0.05); }

        .btn-primary { background: var(--primary); color: white; padding: 10px 20px; border-radius: 12px; font-weight: 700; transition: 0.3s; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; }
        .btn-primary:hover { opacity: 0.9; transform: scale(1.02); }

        .btn-secondary { background: var(--secondary); color: white; padding: 10px 20px; border-radius: 12px; font-weight: 700; transition: 0.3s; border: none; cursor: pointer; font-size: 0.85rem; }
        .btn-secondary:hover { opacity: 0.9; }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #eee; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #ddd; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="p-8 text-center border-b border-gray-50 bg-white">
        <a href="admin.php" class="inline-block">
            <div style="height: 45px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px;">
                <img src="assets/images/asset.png" alt="BLOOM" style="max-height: 100%; max-width: 100%; object-fit: contain;" onerror="this.src='assets/images/asset.jpg'">
            </div>
            <p class="brand-font text-lg font-black tracking-widest text-[#E91E63]">BLOOMINOUS</p>
            <p class="text-[9px] uppercase tracking-[0.3em] text-gray-400 font-bold mt-1">Management System</p>
        </a>
    </div>

    <nav class="mt-6 px-2">
        <a href="admin.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-grid-2"></i>
            <span>Dashboard</span>
        </a>
        <a href="order_management.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'order_management.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-shopping-bag"></i>
            <span>Orders</span>
        </a>
        <a href="product_management.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'product_management.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-box"></i>
            <span>Inventory</span>
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
            <span>POS Analytics</span>
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
        <a href="manage_branches.php" class="sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_branches.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-code-branch"></i>
            <span>Manage Branches</span>
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
    <div class="flex justify-end items-center mb-8 bg-white p-4 rounded-2xl shadow-sm">
        <div id="notification-bell" class="relative cursor-pointer mr-6">
            <i class="fa-solid fa-bell text-gray-400 text-xl"></i>
            <span id="notif-count" class="hidden absolute -top-2 -right-2 bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">0</span>
            
            <!-- Notification Dropdown -->
            <div id="notif-dropdown" class="hidden absolute right-0 mt-4 w-80 bg-white rounded-2xl shadow-2xl border border-gray-100 z-[200] overflow-hidden">
                <div class="p-4 border-bottom border-gray-100 flex justify-between items-center">
                    <h4 class="font-black text-xs uppercase tracking-widest text-gray-800">Notifications</h4>
                    <button id="clear-notifs" class="text-[10px] text-indigo-500 font-bold uppercase">Clear All</button>
                </div>
                <div id="notif-list" class="max-h-96 overflow-y-auto">
                    <div class="p-8 text-center text-gray-300 italic text-xs">No new notifications</div>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-4 mr-6 border-r pr-6 border-gray-100">
            <div class="relative">
                <select id="branch-selector" onchange="setBranch(this.value)" <?php echo (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super-admin') ? 'disabled' : ''; ?> class="bg-gray-50 border border-gray-200 text-gray-700 text-[10px] font-black uppercase tracking-widest rounded-lg focus:ring-indigo-500 focus:border-indigo-500 block w-full p-2.5 appearance-none pr-8 cursor-pointer disabled:opacity-50">
                    <option value="main_branch">Main Branch</option>
                    <!-- Other branches will be loaded via JS -->
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-500">
                    <i class="fa-solid fa-chevron-down text-[8px]"></i>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const selector = document.getElementById('branch-selector');
                    selector.value = window.currentBranch;

                    // Load all branches from Firestore
                    db.collection('branches').get().then(snap => {
                        // Clear existing except default if needed, or rebuild
                        selector.innerHTML = '';
                        
                        // Add "All Branches" option if user is super admin
                        // const adminEmail = "789jojoalvarado@gmail.com";
                        // if (firebase.auth().currentUser && firebase.auth().currentUser.email === adminEmail) {
                        //     const allOpt = document.createElement('option');
                        //     allOpt.value = 'all';
                        //     allOpt.text = 'All Branches (View Only)';
                        //     selector.appendChild(allOpt);
                        // }

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
        <div class="flex items-center gap-3 border-l pl-6 border-gray-100">
            <div class="text-right">
                <p class="text-xs font-bold text-gray-800"><?php echo $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'User'; ?></p>
                <p class="text-[10px] text-gray-400 uppercase font-bold"><?php 
                    $dispRole = $_SESSION['role'] ?? 'Member';
                    echo str_replace('-', ' ', $dispRole);
                ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl <?php echo ($_SESSION['role'] ?? '') === 'super-admin' ? 'bg-gray-800 text-white' : 'bg-pink-100 text-pink-500'; ?> flex items-center justify-center font-black text-sm">
                <?php echo strtolower(substr($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)); ?>
            </div>
        </div>
    </div>
