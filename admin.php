<?php
/**
 * BLOOMINOUS - Admin Dashboard (Firebase Spoke)
 */
session_start();

// Sinisiguro na admin lamang ang makakapasok dito.
if (!isset($_SESSION['admin_id'])) {
    header("Location: index.php?error=unauthorized");
    exit();
}

// Admin Display Name
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';

include 'templates/header.php'; 
?>

<style>
    /* BLOOM Admin Aesthetic - Unified */
    main { width: 98%; max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
    .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 3.5rem; }
    
    /* 8 Stat Cards Grid */
    .insights-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 4rem; }
    .stat-card { display: block; text-decoration: none; cursor: pointer; padding: 2.5rem 2rem; border-radius: 35px; color: white; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); position: relative; overflow: hidden; border: none; }
    .stat-card:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(233, 30, 99, 0.15); }
    .stat-card h1 { font-size: 2.5rem; font-weight: 800; margin-bottom: 4px; color: white; position: relative; z-index: 2; font-family: 'Inter', sans-serif; }
    .stat-card p { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 2px; font-weight: 800; opacity: 0.8; color: white; position: relative; z-index: 2; }
    .stat-card i { position: absolute; right: -15px; bottom: -15px; font-size: 6rem; opacity: 0.15; color: white; transform: rotate(-15deg); transition: 0.4s; }
    .stat-card:hover i { transform: rotate(0deg) scale(1.1); opacity: 0.25; }

    /* Unified Gradients */
    .bloom-pink { background: linear-gradient(135deg, #E91E63, #FF5252); }
    .bloom-indigo { background: linear-gradient(135deg, #7B79F2, #4da3ff); }
    .bloom-teal { background: linear-gradient(135deg, #00ced1, #16a085); }
    .bloom-orange { background: linear-gradient(135deg, #ffb142, #f39c12); }

    /* Analytics Section */
    .analytics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2.5rem; }
    .chart-box { background: #fff; padding: 3.5rem; border-radius: 35px; border: 1px solid #f0f0f0; box-shadow: 0 10px 30px rgba(0,0,0,0.02); }
    .chart-box h3 { font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; margin-bottom: 2.5rem; color: var(--text-main); font-weight: 900; }
    
    .chart-row { display: flex; align-items: center; gap: 20px; margin-bottom: 30px; }
    .chart-row span { width: 180px; font-size: 0.8rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }
    .progress-bar { background: #f8f9fb; height: 10px; width: 100%; border-radius: 10px; overflow: hidden; }
    .bar-fill { height: 100%; border-radius: 10px; transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1); }
    .pink-bar { background: var(--primary); }
    .indigo-bar { background: var(--secondary); }

    @media screen and (max-width: 1200px) { .insights-grid { grid-template-columns: repeat(2, 1fr); } }
    @media screen and (max-width: 768px) { .analytics-grid { grid-template-columns: 1fr; } .insights-grid { grid-template-columns: 1fr; } }
</style>

<main>
    <div class="top-header">
        <div class="title-area">
            <h1 class="brand-font text-6xl font-black text-gray-800">Dashboard</h1>
            <p style="color:var(--text-light); font-weight:800; font-size: 0.7rem; margin-top: 12px; text-transform: uppercase; letter-spacing: 2px;">
                <i class="fas fa-satellite-dish mr-2 text-pink-500"></i> Active Session: <?php echo date('F d, Y'); ?>
            </p>
        </div>
        <div class="flex gap-4">
            <div class="bg-white p-6 border border-gray-100 rounded-3xl text-center min-w-[150px]">
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Live Nodes</p>
                <div class="flex items-center justify-center gap-2">
                    <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                    <span class="brand-font text-2xl font-black text-gray-800" id="stat-active">...</span>
                </div>
            </div>
        </div>
    </div>

    <!-- REAL-TIME STAT CARDS -->
    <div class="insights-grid">
        <div class="stat-card bloom-pink" onclick="window.location.href='customer.php'">
            <i class="fas fa-users"></i>
            <h1 id="stat-customers">...</h1>
            <p>Customer Base</p>
        </div>
        <div class="stat-card bloom-indigo" onclick="window.location.href='order_management.php'">
            <i class="fas fa-shopping-bag"></i>
            <h1 id="stat-orders">...</h1>
            <p>Order Volume</p>
        </div>
        <div class="stat-card bloom-teal" onclick="window.location.href='product_management.php'">
            <i class="fas fa-cube"></i>
            <h1 id="stat-products">...</h1>
            <p>Unique SKUs</p>
        </div>
        <div class="stat-card bloom-orange" onclick="window.location.href='spoilage_tracking.php'">
            <i class="fas fa-recycle"></i>
            <h1 id="stat-spoilage">...</h1>
            <p>Spoilage Value</p>
        </div>
        <div class="stat-card bloom-indigo" style="background: linear-gradient(135deg, #6c5ce7, #a29bfe);">
            <i class="fas fa-hourglass-start"></i>
            <h1 id="stat-pending">...</h1>
            <p>Pending Orders</p>
        </div>
        <div class="stat-card bloom-teal" style="background: linear-gradient(135deg, #00b894, #55efc4);">
            <i class="fas fa-truck-fast"></i>
            <h1 id="stat-deliveries">...</h1>
            <p>Active Shipments</p>
        </div>
        <div class="stat-card bloom-pink" style="background: linear-gradient(135deg, #fd79a8, #fab1a0);">
            <i class="fas fa-warehouse"></i>
            <h1 id="stat-stock">...</h1>
            <p>Total Inventory</p>
        </div>
        <div class="stat-card bloom-orange" style="background: linear-gradient(135deg, #e17055, #ff7675);">
            <i class="fas fa-calendar-check"></i>
            <h1 id="stat-session"><?php echo date('H:i'); ?></h1>
            <p>System Time</p>
        </div>
    </div>

    <div class="analytics-grid">
        <div class="chart-box blue-top">
            <h3>Top 5 - Best Sellers</h3>
            <div id="best-sellers-list">
                <p class='text-muted text-sm italic'>Loading data...</p>
            </div>
        </div>
        <div class="chart-box pink-top">
            <h3>Bottom 5 - Low Performers</h3>
            <div id="low-performers-list">
                <p class='text-muted text-sm italic'>Loading data...</p>
            </div>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Fetch Stats from Firestore
        getBranchPath('customers').onSnapshot(snap => {
            document.getElementById('stat-customers').innerText = snap.size;
            document.getElementById('stat-active').innerText = snap.size; // Placeholder for active users
        });

        getBranchPath('spoilage').onSnapshot(snap => {
            let totalLoss = 0;
            snap.forEach(doc => {
                totalLoss += parseFloat(doc.data().loss_amount || 0);
            });
            document.getElementById('stat-spoilage').innerText = '₱' + Math.round(totalLoss).toLocaleString();
        });

        // Orders are global but filtered by branchId
        db.collection('orders').where('branchId', '==', window.currentBranch).onSnapshot(snap => {
            document.getElementById('stat-orders').innerText = snap.size;
            let pending = 0;
            let deliveries = 0;
            
            const salesMap = {};

            snap.forEach(doc => {
                const o = doc.data();
                // Check root status, status in items, or delivery_status field
                let status = (o.status || (o.items && o.items[0] ? o.items[0].status : "") || "").toLowerCase();
                let delStatus = (o.delivery_status || "").toLowerCase();
                
                // Count Pending (both fields)
                if (status === 'pending' || delStatus === 'pending') {
                    pending++;
                }
                
                // Count Deliveries (Expansion to cover all Flutter app possible statuses)
                const isActiveDelivery = [
                    'processing', 
                    'in_progress', 
                    'out_for_delivery', 
                    'on delivery', 
                    'in transit',
                    'ready for delivery'
                ].includes(status) || [
                    'processing', 
                    'in transit', 
                    'out for delivery', 
                    'on the way'
                ].includes(delStatus);

                if (isActiveDelivery) {
                    deliveries++;
                }
                
                // Aggregate sales for analytics (only completed)
                if ((status === 'completed' || status === 'delivered' || delStatus === 'delivered') && o.items) {
                    o.items.forEach(item => {
                        const key = item.id || item.name || 'Unknown';
                        if (!salesMap[key]) {
                            salesMap[key] = { name: item.name || 'Unknown', qty: 0 };
                        }
                        salesMap[key].qty += (item.qty || 0);
                    });
                }
            });
            
            document.getElementById('stat-pending').innerText = pending;
            document.getElementById('stat-deliveries').innerText = deliveries;

            // Update Analytics Lists
            updateAnalytics(salesMap);
        });

        getBranchPath('inventory').onSnapshot(snap => {
            document.getElementById('stat-products').innerText = snap.size;
            let totalStock = 0;
            snap.forEach(doc => {
                totalStock += (doc.data().stock || 0);
            });
            document.getElementById('stat-stock').innerText = totalStock;
        });

        function updateAnalytics(salesMap) {
            // Convert map to array
            const salesArray = Object.keys(salesMap).map(id => ({
                id,
                name: salesMap[id].name,
                qty: salesMap[id].qty
            }));

            // Sort for Best Sellers
            const bestSellers = [...salesArray].sort((a, b) => b.qty - a.qty).slice(0, 5);
            // Sort for Low Performers
            const lowPerformers = [...salesArray].sort((a, b) => a.qty - b.qty).slice(0, 5);

            renderList('best-sellers-list', bestSellers, 'green-bar');
            renderList('low-performers-list', lowPerformers, 'pink-bar');
        }

        function renderList(elementId, items, barClass) {
            const container = document.getElementById(elementId);
            if (items.length === 0) {
                container.innerHTML = "<p class='text-muted text-sm italic'>No sales data available yet.</p>";
                return;
            }

            const maxQty = Math.max(...items.map(i => i.qty)) || 1;
            let html = '';
            
            items.forEach(item => {
                const percentage = (item.qty / maxQty) * 100;
                html += `
                    <div class="chart-row">
                        <span>${item.name}</span>
                        <div class="progress-bar">
                            <div class="bar-fill ${barClass}" style="width: ${percentage}%"></div>
                        </div>
                        <div style="font-size: 0.8rem; font-weight: 800; color: #363949; width: 40px; text-align: right;">${item.qty}</div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }
    });
</script>

<?php include 'templates/footer.php'; ?>
