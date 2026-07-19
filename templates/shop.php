<?php
/**
 * BLOOMINOUS - Customer Shop Spoke
 */
session_start();

// Hub and Spoke Security Check
$user_id = $_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? null;

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
    <title>Shop | Bloominous</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Firebase SDK -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-auth-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #fcf9f2; }
        .shop-container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .product-card { background: #fff; border-radius: 20px; overflow: hidden; transition: 0.3s; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .product-card:hover { transform: translateY(-10px); }
        .product-img { width: 100%; height: 200px; object-fit: cover; }
        .product-info { padding: 20px; }
        .btn-add { width: 100%; padding: 12px; background: #7380ec; color: #fff; border-radius: 12px; font-weight: 800; text-transform: uppercase; font-size: 0.75rem; border: none; cursor: pointer; transition: 0.3s; }
        .btn-add:hover { background: #5a65c1; }
    </style>
</head>
<body>

<nav class="bg-white py-4 shadow-sm sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 flex justify-between items-center">
        <div class="flex items-center gap-2">
            <img src="../assets/images/asset.jpg" alt="BLOOM" class="h-8 object-contain">
            <h1 class="text-xl font-black italic tracking-tighter text-orange-500">BLOOM</h1>
        </div>
        <div class="flex items-center gap-6">
            <a href="shop.php" class="text-orange-500 font-bold text-xs uppercase tracking-widest">Shop</a>
            <a href="my_orders.php" class="text-gray-400 font-bold text-xs uppercase tracking-widest hover:text-orange-500">My Orders</a>
            <div class="relative cursor-pointer" onclick="toggleCart()">
                <i class="fa-solid fa-shopping-cart text-gray-400 text-xl"></i>
                <span id="cart-count" class="absolute -top-2 -right-2 bg-orange-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">0</span>
            </div>
            <a href="../logout.php" class="text-red-400 font-bold text-xs uppercase tracking-widest">Logout</a>
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

<div class="shop-container">
    <div class="flex justify-between items-end mb-12">
        <div>
            <h2 class="text-3xl font-black text-gray-800 uppercase tracking-tight">Fresh Collection</h2>
            <p class="text-gray-400 text-sm">Hand-picked flowers delivered to your doorstep.</p>
        </div>
        <div class="flex gap-4">
            <button class="px-6 py-2 bg-white rounded-full text-xs font-bold uppercase tracking-widest shadow-sm">All</button>
            <button class="px-6 py-2 bg-gray-100 rounded-full text-xs font-bold uppercase tracking-widest text-gray-400">Bouquets</button>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-8" id="product-grid">
        <div class="col-span-full text-center py-20 text-gray-400 italic">Loading fresh flowers...</div>
    </div>
</div>

<script>
    <?php
        $firebaseConfigJson = file_get_contents(__DIR__ . '/../firebase-applet-config.json');
        echo "const firebaseConfig = " . $firebaseConfigJson . ";";
    ?>
    firebase.initializeApp(firebaseConfig);
    const db = firebase.firestore();

    // Branch Management
    window.currentBranch = localStorage.getItem('bloom_branch_id') || 'main_branch';
    window.getBranchPath = (collectionName) => {
        if (collectionName === 'orders' || collectionName === 'customers' || collectionName === 'users') {
            return db.collection(collectionName);
        }
        return db.collection('branches').doc(window.currentBranch).collection(collectionName);
    };

    let cart = JSON.parse(localStorage.getItem('bloom_cart') || '{}');
    let allProducts = {};
    let userLat = null;
    let userLng = null;

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

    // Try to get user location for distance display
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => {
            userLat = pos.coords.latitude;
            userLng = pos.coords.longitude;
        }, err => {
            console.warn("Geolocation failed or denied:", err);
        }, { timeout: 5000 });
    }

    updateCartUI();

    document.addEventListener('DOMContentLoaded', () => {
        const grid = document.getElementById('product-grid');

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

        // Fetch from ALL branches to show cross-branch availability
        const loadProducts = () => {
            db.collection('branches').onSnapshot(branchSnap => {
                if (branchSnap.empty) {
                    grid.innerHTML = '<div class="col-span-full text-center py-20 text-gray-400 italic">No stores found.</div>';
                    return;
                }

                const branchMap = {};
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

                Promise.all(inventoryPromises).then(results => {
                    let grouped = {};
                    results.forEach(res => {
                        res.docs.forEach(doc => {
                            const p = doc.data();
                            const name = p.name || 'Unnamed';
                            const branchId = res.branchId;

                            if (!grouped[name]) {
                                grouped[name] = {
                                    ...p,
                                    id: doc.id,
                                    branches: [{ branchId: branchId, stock: p.stock, id: doc.id }]
                                };
                            } else {
                                grouped[name].branches.push({ branchId: branchId, stock: p.stock, id: doc.id });
                                if (p.stock > (grouped[name].stock || 0)) {
                                    grouped[name].image = p.image || grouped[name].image;
                                    grouped[name].price = p.price || grouped[name].price;
                                }
                            }
                        });
                    });

                    allProducts = {};
                    let html = '';
                    
                    Object.values(grouped).forEach(p => {
                        const id = p.id;
                        allProducts[id] = p;
                        const img = p.image ? p.image : 'https://via.placeholder.com/300x200?text=Bloom';
                        
                        let minDistance = Infinity;
                        let branchListHtml = '';
                        p.branches.forEach(b => {
                            const bData = branchMap[b.branchId] || { name: 'Branch' };
                            const bName = bData.name;
                            
                            if (userLat && userLng && bData.lat && bData.lng) {
                                const d = calculateDistance(userLat, userLng, bData.lat, bData.lng);
                                if (d < minDistance) minDistance = d;
                            }

                            branchListHtml += `<div class="flex justify-between items-center text-[10px] mt-1 border-t pt-1 border-gray-50">
                                <span class="text-gray-400 font-medium">${bName}</span>
                                <span class="${b.stock < 10 ? 'text-red-400' : 'text-green-500'} font-black">${b.stock} left</span>
                            </div>`;
                        });

                        let distanceBadge = '';
                        if (minDistance !== Infinity) {
                            let roadD = minDistance;
                            if (minDistance < 5) roadD = minDistance * 1.3;
                            else if (minDistance < 20) roadD = minDistance * 1.8;
                            else roadD = minDistance * 2.5; 
                            
                            distanceBadge = `<span class="bg-pink-50 text-pink-500 text-[8px] px-2 py-0.5 rounded-full font-black ml-2 uppercase">~${roadD.toFixed(1)}KM</span>`;
                        }

                        const isRecycled = p.name === 'Recycled Bouquet';
                        const recycledBadge = isRecycled ? `<span class="bg-green-100 text-green-600 text-[8px] px-2 py-0.5 rounded-full font-black ml-2 uppercase">Eco-Salvaged</span>` : '';

                        html += `
                        <div class="product-card group ${isRecycled ? 'border-2 border-green-200' : ''}">
                            <div class="relative overflow-hidden h-[200px]">
                                <img src="${img}" class="product-img w-full h-full object-cover transition-transform group-hover:scale-110" alt="Product" onerror="this.src='https://via.placeholder.com/300x200?text=Bloom'">
                                ${isRecycled ? '<div class="absolute top-2 left-2 bg-green-500 text-white text-[10px] px-2 py-1 rounded font-bold">RECYCLED</div>' : ''}
                            </div>
                            <div class="product-info">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center">
                                        <span class="text-[10px] uppercase font-bold text-gray-400 tracking-widest">${p.category || 'Flower'}</span>
                                        ${recycledBadge}
                                    </div>
                                    ${distanceBadge}
                                </div>
                                <h3 class="font-bold text-gray-800 my-1 truncate">${p.name || 'Unnamed'}</h3>
                                <p class="text-indigo-600 font-black text-lg mb-2">₱${parseFloat(p.price || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</p>
                                
                                ${isRecycled ? `<p class="text-[10px] text-green-700 font-medium italic mb-3 leading-tight">${p.description}</p>` : ''}

                                <div class="mb-4">
                                    <p class="text-[8px] font-black uppercase text-gray-300 tracking-widest mb-1">Availability</p>
                                    ${branchListHtml}
                                </div>

                                <button onclick="addToCart('${id}')" class="btn-add ${isRecycled ? 'bg-green-600 hover:bg-green-700' : ''}">Add to Cart</button>
                            </div>
                        </div>
                        `;
                    });

                    if (html === '') {
                        grid.innerHTML = '<div class="col-span-full text-center py-20 text-gray-400 italic">No flowers in stock right now.</div>';
                    } else {
                        grid.innerHTML = html;
                    }
                }).catch(err => {
                    console.error("Inventory error:", err);
                    grid.innerHTML = `<div class="col-span-full text-center py-20 text-red-500 font-bold">Failed to load flowers: ${err.message}</div>`;
                });
            }, error => {
                console.error("Branch listener error:", error);
                grid.innerHTML = `<div class="col-span-full text-center py-20 text-red-500 font-bold">Error connecting to database.</div>`;
            });
        };

        // Check auth status first
        firebase.auth().onAuthStateChanged(user => {
            if (user) {
                loadProducts();
            } else {
                grid.innerHTML = '<div class="col-span-full text-center py-20 text-gray-400 italic">Auth needed. Redirecting to login...</div>';
                setTimeout(() => window.location.href = '../index.php', 2000);
            }
        });
    });

    function addToCart(id) {
        if (!cart[id]) {
            cart[id] = {
                id: id,
                name: allProducts[id].name,
                price: allProducts[id].price,
                qty: 0
            };
        }
        cart[id].qty += 1;
        localStorage.setItem('bloom_cart', JSON.stringify(cart));
        updateCartUI();
        
        const btn = event.target;
        const originalText = btn.innerText;
        btn.innerText = 'ADDED!';
        btn.style.background = '#10b981';
        setTimeout(() => {
            btn.innerText = originalText;
            btn.style.background = '#7380ec';
        }, 1000);
    }

    function updateCartUI() {
        const count = Object.values(cart).reduce((a, b) => a + b.qty, 0);
        document.getElementById('cart-count').innerText = count;
    }

    function toggleCart() {
        const total = Object.values(cart).reduce((sum, item) => sum + (item.price * item.qty), 0);
        window.location.href = 'checkout.php?amount=' + total;
    }
</script>

</body>
</html>