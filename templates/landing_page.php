<?php
/**
 * BLOOMINOUS - Public Landing Page
 * Starts the session (no redirect) only so the nav can tell
 * guests apart from logged-in customers.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/version.php';

// Same identity check shop.php uses. null = guest.
$landingUserId = $_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? null;
$isLoggedIn = $landingUserId !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BloomShop | Premium Floral Arrangements</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #7B79F2;
            --primary-light: #eef2ff;
            --text-main: #363949;
            --text-muted: #7d8da1;
        }
        html { scroll-behavior: smooth; }
        body { font-family: 'Poppins', sans-serif; background-color: #fcfaff; color: var(--text-main); }
        
        .hero-section { 
            height: 90vh; 
            display: flex; 
            align-items: center; 
            background: linear-gradient(rgba(0,0,0,0.3), rgba(0,0,0,0.3)), url('https://images.unsplash.com/photo-1526047932273-341f2a7631f9?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80') center/cover no-repeat; 
            position: relative; 
            color: #fff;
        }
        
        .glass-nav {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
            padding: 16px 40px;
            border-radius: 50px;
            font-weight: 700;
            transition: 0.3s;
            display: inline-block;
            box-shadow: 0 10px 20px rgba(123, 121, 242, 0.2);
        }
        .btn-primary:hover {
            background: #5a58d1;
            transform: translateY(-3px);
            box-shadow: 0 15px 25px rgba(123, 121, 242, 0.3);
        }

        .product-card {
            background: #fff;
            border-radius: 30px;
            overflow: hidden;
            transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 1px solid #f0f2f5;
        }
        .product-card:hover {
            transform: translateY(-15px);
            box-shadow: 0 25px 50px rgba(123, 121, 242, 0.1);
        }
        .product-img {
            width: 100%;
            height: 280px;
            object-fit: cover;
            transition: 0.5s;
        }
        .product-card:hover .product-img {
            transform: scale(1.1);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 25px;
            margin: 0 auto 25px;
            font-size: 1.8rem;
            transition: 0.3s;
        }
        .feature-card:hover .feature-icon {
            background: var(--primary);
            color: #fff;
            transform: rotateY(180deg);
        }

        /* --- FAQ accordion (native <details>/<summary>) --- */
        .faq-item {
            background: #fff;
            border: 1px solid #f0f2f5;
            border-radius: 25px;
            transition: 0.3s;
        }
        .faq-item:hover { border-color: rgba(123, 121, 242, 0.3); }
        .faq-item[open] {
            border-color: var(--primary);
            box-shadow: 0 15px 35px rgba(123, 121, 242, 0.08);
        }
        .faq-item summary {
            list-style: none;              /* hide default triangle (Firefox/Chrome) */
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 24px 30px;
        }
        .faq-item summary::-webkit-details-marker { display: none; } /* hide triangle (Safari) */
        .faq-icon {
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.3s;
        }
        .faq-item[open] .faq-icon {
            background: var(--primary);
            color: #fff;
            transform: rotate(45deg);      /* "+" turns into "x" */
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
    <!-- Firebase SDK -->
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.8.0/firebase-firestore-compat.js"></script>
    <!-- Shared guest gate (login-before-cart helper) -->
    <script src="../assets/script/guest_gate.js"></script>
</head>
<body>

<nav class="glass-nav py-5 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-6 flex justify-between items-center">
        <div class="flex items-center gap-2">
            <img src="../assets/images/asset.jpg" alt="BLOOM" class="h-8 object-contain">
            <h1 class="text-xl font-black italic tracking-tighter text-[#363949] hidden sm:block">BLOOM</h1>
        </div>
        <div class="hidden md:flex gap-10 text-[11px] font-extrabold uppercase tracking-[2px] text-gray-400">
            <a href="landing_page.php" class="text-[#7B79F2]">Home</a>
            <a href="#about" class="hover:text-[#7B79F2] transition-colors">About</a>
            <a href="shop.php" class="hover:text-[#7B79F2] transition-colors">Shop</a>
            <a href="#faqs" class="hover:text-[#7B79F2] transition-colors">FAQs</a>
            <?php if ($isLoggedIn): ?>
                <a href="my_orders.php" class="hover:text-[#7B79F2] transition-colors">My Orders</a>
                <a href="../logout.php" class="hover:text-[#7B79F2] transition-colors">Logout</a>
            <?php else: ?>
                <!-- Guests: My Orders goes through the login gate, then continues to my_orders.php -->
                <a href="my_orders.php" onclick="window.bloomRequireLogin({ action: 'view_orders' }, 'Please log in to view your orders.'); return false;" class="hover:text-[#7B79F2] transition-colors">My Orders</a>
                <a href="../index.php" class="hover:text-[#7B79F2] transition-colors">Login</a>
                <a href="../register.php" class="px-6 py-2 bg-[#7B79F2] text-white rounded-full hover:bg-[#5a58d1] transition-all">Join Now</a>
                 <a href="#" class="px-6 py-2 bg-[#7B79F2] text-white rounded-full hover:bg-[#5a58d1] transition-all">Download</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="hero-section">
    <div class="max-w-7xl mx-auto px-6 w-full">
        <div class="max-w-2xl">
            <span class="inline-block px-4 py-1 bg-white/20 backdrop-blur-md rounded-full text-xs font-bold uppercase tracking-widest mb-6">New Collection 2026</span>
            <h2 class="text-7xl font-black uppercase tracking-tighter mb-6 leading-[0.9]">Elegance in <br><span class="text-[#7B79F2]">Every Bloom</span></h2>
            <p class="text-lg font-medium text-gray-200 mb-10 max-w-lg">Experience the art of floral design with our premium, hand-picked arrangements delivered straight to your doorstep.</p>
            <div class="flex gap-4">
                <a href="shop.php" class="btn-primary">Shop Collection</a>
                <a href="#featured" class="px-8 py-4 border-2 border-white/30 rounded-full font-bold hover:bg-white hover:text-gray-900 transition-all">View Featured</a>
            </div>
        </div>
    </div>
</div>

<div id="about" class="max-w-7xl mx-auto px-6 py-32">
    <div class="max-w-3xl mx-auto text-center mb-20">
        <span class="text-[#7B79F2] font-black uppercase tracking-widest text-xs">About BLOOM</span>
        <h2 class="text-5xl font-black uppercase tracking-tighter text-gray-800 mt-2 mb-6">Retail, Reimagined</h2>
        <p class="text-gray-500 text-lg font-medium leading-relaxed">
            BLOOM is a hybrid Web and Mobile Point of Sale (POS) and e-commerce system built for Bloominous Flower Shop.
            It streamlines retail operations by connecting physical store sales with online order management in real time,
            providing a seamless shopping and administrative experience.
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-10 mb-24">
        <div class="text-center feature-card">
            <div class="feature-icon"><i class="fa-solid fa-cube"></i></div>
            <h3 class="text-xl font-black mb-4 uppercase tracking-tight">AR Bouquet Customization</h3>
            <p class="text-gray-400 text-base font-medium leading-relaxed">Interactively design and preview 3D floral arrangements in Augmented Reality before you buy.</p>
        </div>
        <div class="text-center feature-card">
            <div class="feature-icon"><i class="fa-solid fa-leaf"></i></div>
            <h3 class="text-xl font-black mb-4 uppercase tracking-tight">AI Freshness Analysis</h3>
            <p class="text-gray-400 text-base font-medium leading-relaxed">Tracks inventory health through visual monitoring, ensuring product quality and smart stock rotation.</p>
        </div>
        <div class="text-center feature-card">
            <div class="feature-icon"><i class="fa-solid fa-shop"></i></div>
            <h3 class="text-xl font-black mb-4 uppercase tracking-tight">Omnichannel POS</h3>
            <p class="text-gray-400 text-base font-medium leading-relaxed">Walk-in and online transactions, stock levels, and order histories stay synchronized across all platforms - with Cash, GCash, and Cash on Delivery, plus integrated order fulfillment and tracking.</p>
        </div>
    </div>

    <div class="bg-white rounded-[40px] border border-gray-100 p-14 text-center">
        <span class="text-[#7B79F2] font-black uppercase tracking-widest text-xs">Supported Occasions & Products</span>
        <p class="text-gray-500 text-lg font-medium leading-relaxed max-w-3xl mx-auto mt-4">
            BLOOM facilitates the sale and distribution of specialized floral arrangements, gift sets, and accessories
            tailored for every milestone that matters.
        </p>
        <div class="flex flex-wrap justify-center gap-3 mt-10">
            <?php foreach (['Birthdays', 'Anniversaries', 'Graduations', "Valentine's Day", "Mother's Day", "Father's Day", 'Special Events & Celebrations'] as $occasion): ?>
                <span class="px-6 py-2.5 bg-[#eef2ff] text-[#7B79F2] text-xs font-black uppercase tracking-widest rounded-full"><?php echo htmlspecialchars($occasion); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div id="featured" class="max-w-7xl mx-auto px-6 py-32">
    <div class="flex flex-col md:flex-row justify-between items-end mb-20 gap-6">
        <div>
            <span class="text-[#7B79F2] font-black uppercase tracking-widest text-xs">Our Best Sellers</span>
            <h2 class="text-5xl font-black uppercase tracking-tighter text-gray-800 mt-2">Featured Collections</h2>
        </div>
        <p class="text-gray-400 max-w-md font-medium">Carefully curated arrangements designed to bring joy and elegance to any space or occasion.</p>
    </div>
    
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-10" id="featured-products">
        <!-- Products will be loaded here -->
        <div class="col-span-full text-center py-20">
            <i class="fa-solid fa-spinner fa-spin fa-3x text-[#7B79F2] mb-4"></i>
            <p class="text-gray-400 font-bold uppercase tracking-widest text-xs">Loading featured flowers...</p>
        </div>
    </div>
</div>

<div class="bg-white py-32 border-y border-gray-100">
    <div class="max-w-7xl mx-auto px-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-16">
            <div class="text-center feature-card">
                <div class="feature-icon"><i class="fa-solid fa-truck-fast"></i></div>
                <h3 class="text-xl font-black mb-4 uppercase tracking-tight">Express Delivery</h3>
                <p class="text-gray-400 text-sm font-medium leading-relaxed">Same-day delivery for orders placed before 2 PM within the metro area. Freshness guaranteed.</p>
            </div>
            <div class="text-center feature-card">
                <div class="feature-icon"><i class="fa-solid fa-certificate"></i></div>
                <h3 class="text-xl font-black mb-4 uppercase tracking-tight">Premium Quality</h3>
                <p class="text-gray-400 text-sm font-medium leading-relaxed">We source our blooms from the finest growers daily to ensure vibrant colors and long-lasting beauty.</p>
            </div>
            <div class="text-center feature-card">
                <div class="feature-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                <h3 class="text-xl font-black mb-4 uppercase tracking-tight">Expert Florists</h3>
                <p class="text-gray-400 text-sm font-medium leading-relaxed">Every arrangement is a masterpiece, uniquely crafted by our award-winning design team.</p>
            </div>
        </div>
    </div>
</div>

<!-- ============ FAQs (data: Firestore "faqs", managed in settings.php) ============ -->
<div id="faqs" class="max-w-4xl mx-auto px-6 py-32 scroll-mt-24">
    <div class="text-center mb-16">
        <span class="text-[#7B79F2] font-black uppercase tracking-widest text-xs">Need Help?</span>
        <h2 class="text-5xl font-black uppercase tracking-tighter text-gray-800 mt-2 mb-4">Frequently Asked Questions</h2>
        <p class="text-gray-400 font-medium">Quick answers about orders, delivery, and payments.</p>
    </div>

    <div id="faq-list" class="flex flex-col gap-4">
        <!-- FAQs will be loaded here -->
        <div class="text-center py-12">
            <i class="fa-solid fa-spinner fa-spin fa-2x text-[#7B79F2] mb-4"></i>
            <p class="text-gray-400 font-bold uppercase tracking-widest text-xs">Loading FAQs...</p>
        </div>
    </div>
</div>

<div class="py-32 bg-[#7B79F2] relative overflow-hidden">
    <div class="absolute top-0 right-0 w-96 h-96 bg-white/10 rounded-full -mr-48 -mt-48 blur-3xl"></div>
    <div class="absolute bottom-0 left-0 w-96 h-96 bg-black/10 rounded-full -ml-48 -mb-48 blur-3xl"></div>
    
    <div class="max-w-4xl mx-auto px-6 text-center relative z-10">
        <h2 class="text-5xl font-black text-white uppercase tracking-tighter mb-8">Ready to brighten <br>someone's day?</h2>
        <p class="text-white/80 text-lg font-medium mb-12">Join our loyalty program today and get 10% off your first order plus exclusive access to seasonal collections.</p>
        <a href="../register.php" class="px-12 py-5 bg-white text-[#7B79F2] rounded-full font-black uppercase tracking-widest hover:scale-110 transition-all shadow-2xl">Get Started Now</a>
    </div>
</div>

<footer class="bg-white py-20">
    <div class="max-w-7xl mx-auto px-6">
        <div class="flex flex-col md:flex-row justify-between items-center gap-10">
            <div class="flex items-center gap-2">
                <img src="../assets/images/asset.jpg" alt="BLOOM" class="h-6 object-contain">
                <h1 class="text-xl font-black italic tracking-tighter text-[#363949]">BLOOM</h1>
            </div>
            <div class="flex gap-8 text-[10px] font-black uppercase tracking-widest text-gray-400">
                <a href="#" class="hover:text-[#7B79F2]">Privacy Policy</a>
                <a href="#" class="hover:text-[#7B79F2]">Terms of Service</a>
                <a href="#" class="hover:text-[#7B79F2]">Contact Us</a>
            </div>
            <p class="text-gray-400 text-[10px] font-black uppercase tracking-widest">© 2026 BloomShop. All rights reserved. &middot; <?php echo htmlspecialchars(bloom_version_string()); ?></p>
        </div>
    </div>
</footer>

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
    // Same helper settings.php uses: turns text into safe HTML
    // (so a "<" typed in an FAQ shows as a character, not as a tag).
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Builds the FAQ accordion from Firestore docs.
    function renderFaqs(docs) {
        const faqList = document.getElementById('faq-list');
        if (!faqList) return;

        if (docs.length === 0) {
            faqList.innerHTML = `
                <div class="text-center py-12 bg-gray-50 rounded-[30px] border-2 border-dashed border-gray-200">
                    <i class="fa-regular fa-circle-question fa-2x text-gray-300 mb-3"></i>
                    <p class="text-gray-400 font-bold uppercase tracking-widest text-xs">No FAQs yet. Check back soon!</p>
                </div>
            `;
            return;
        }

        faqList.innerHTML = docs.map(doc => {
            const f = doc.data();
            return `
                <details class="faq-item">
                    <summary>
                        <span class="font-black text-gray-800 text-base md:text-lg tracking-tight">${escapeHtml(f.question || '')}</span>
                        <span class="faq-icon"><i class="fa-solid fa-plus text-sm"></i></span>
                    </summary>
                    <p class="px-8 pb-7 -mt-1 text-gray-500 font-medium leading-relaxed whitespace-pre-line">${escapeHtml(f.answer || '')}</p>
                </details>
            `;
        }).join('');
    }

    // Shown when FAQs can't be read (missing config, rules, or network).
    function showFaqError() {
        const faqList = document.getElementById('faq-list');
        if (!faqList) return;
        faqList.innerHTML = `
            <div class="text-center py-12 text-gray-400 font-bold uppercase tracking-widest text-xs">
                FAQs are unavailable right now. Please try again later.
            </div>
        `;
    }

    if (firebaseConfig.apiKey) {
        firebase.initializeApp(firebaseConfig);
        const db = firebase.firestore();
        
        // Use main_branch by default for public landing page
        const branchId = 'main_branch';
        const getBranchPath = (coll) => db.collection('branches').doc(branchId).collection(coll);

        document.addEventListener('DOMContentLoaded', () => {
            const featuredGrid = document.getElementById('featured-products');

            // Fetch top 4 products from main branch featured section
            getBranchPath('inventory').limit(4).onSnapshot(snap => {
                if (snap.empty) {
                    featuredGrid.innerHTML = `
                        <div class="col-span-full text-center py-20 bg-gray-50 rounded-[40px] border-2 border-dashed border-gray-200">
                            <i class="fa-solid fa-box-open fa-3x text-gray-200 mb-4"></i>
                            <p class="text-gray-400 font-bold uppercase tracking-widest text-xs">No products available at the moment.</p>
                        </div>
                    `;
                    return;
                }

                let html = '';
                snap.forEach(doc => {
                    const p = doc.data();
                    const img = p.image ? p.image : 'https://picsum.photos/seed/' + doc.id + '/400/500';
                    
                    html += `
                    <div class="product-card group">
                        <div class="overflow-hidden relative">
                            <img src="${img}" class="product-img" alt="${p.name || 'Product'}" onerror="this.src='https://picsum.photos/seed/flower/400/500'">
                            <div class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                <a href="shop.php" title="View in shop" class="w-12 h-12 bg-white rounded-full flex items-center justify-center text-[#7B79F2] shadow-xl transform translate-y-10 group-hover:translate-y-0 transition-transform">
                                    <i class="fa-solid fa-cart-plus"></i>
                                </a>
                            </div>
                        </div>
                        <div class="p-8">
                            <div class="flex justify-between items-start mb-2">
                                <span class="text-[9px] uppercase font-black text-[#7B79F2] bg-[#eef2ff] px-3 py-1 rounded-full tracking-widest">${p.category || 'Premium'}</span>
                                <div class="flex text-[10px] text-yellow-400">
                                    <i class="fa-solid fa-star"></i>
                                    <i class="fa-solid fa-star"></i>
                                    <i class="fa-solid fa-star"></i>
                                    <i class="fa-solid fa-star"></i>
                                    <i class="fa-solid fa-star"></i>
                                </div>
                            </div>
                            <h3 class="font-black text-gray-800 text-xl tracking-tight mb-4">${p.name || 'Unnamed'}</h3>
                            <div class="flex justify-between items-center">
                                <p class="text-[#7B79F2] font-black text-2xl">₱${parseFloat(p.price || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</p>
                                <span class="text-[10px] font-black text-gray-300 uppercase tracking-widest">${(p.stock || 0) > 0 ? 'In Stock' : 'Out of Stock'}</span>
                            </div>
                        </div>
                    </div>
                    `;
                });
                featuredGrid.innerHTML = html;
            });

            // Live FAQ list: oldest first, same order as settings.php.
            db.collection('faqs').orderBy('createdAt', 'asc').onSnapshot(
                snap => renderFaqs(snap.docs),
                error => {
                    console.error('FAQ listener error:', error);
                    showFaqError();
                }
            );
        });
    } else {
        document.addEventListener('DOMContentLoaded', showFaqError);
    }
</script>

</body>
</html>