<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bloominous | Welcome</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html { scroll-behavior: smooth; }
    </style>
</head>
<body class="bg-[#eff0f5] font-sans">

    <!-- Navigation -->
    <nav class="bg-white shadow-sm sticky top-0 z-50">
        <!-- Logo Header -->
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between border-b border-gray-50">
            <div class="text-orange-500 font-black text-3xl italic tracking-tighter cursor-pointer" onclick="window.scrollTo(0,0)">BLOOM</div>
            <div class="md:hidden text-gray-600"><i class="fa-solid fa-bars text-2xl"></i></div>
        </div>

        <!-- Menu Navigation Bar -->
        <div class="hidden md:flex max-w-7xl mx-auto px-4 py-3 items-center justify-center space-x-8 text-[15px] font-medium text-slate-600">
            <a href="landing_page.php" class="hover:text-orange-500 transition-colors">Best Sellers</a>
            
            <!-- DROPDOWN OCCASIONS -->
            <div class="relative group">
                <div class="flex items-center gap-1 cursor-pointer group-hover:text-orange-500 transition-colors pb-1">
                    <span>Occasions</span>
                    <i class="fa-solid fa-chevron-down text-[10px] mt-1 group-hover:rotate-180 transition-transform"></i>
                </div>
                <div class="absolute left-1/2 -translate-x-1/2 hidden group-hover:block bg-white shadow-xl border border-gray-100 min-w-[200px] z-50 rounded-sm py-3 mt-0">
                    <div class="flex flex-col text-center">
                        <a href="#" class="px-4 py-2 text-slate-500 hover:bg-orange-50 hover:text-orange-500">Valentines</a>
                        <a href="#" class="px-4 py-2 text-slate-500 hover:bg-orange-50 hover:text-orange-500">Anniversary</a>
                        <a href="#" class="px-4 py-2 text-slate-500 hover:bg-orange-50 hover:text-orange-500">Birthday</a>
                        <a href="#" class="px-4 py-2 text-slate-500 hover:bg-orange-50 hover:text-orange-500">Mothers Day</a>
                    </div>
                </div>
            </div>

            <!-- DROPDOWN FLOWERS -->
            <div class="relative group">
                <div class="flex items-center gap-1 cursor-pointer group-hover:text-orange-500 transition-colors pb-1">
                    <span>Flowers</span>
                    <i class="fa-solid fa-chevron-down text-[10px] mt-1 group-hover:rotate-180 transition-transform"></i>
                </div>
                <div class="absolute left-1/2 -translate-x-1/2 hidden group-hover:block bg-white shadow-xl border border-gray-100 min-w-[180px] z-50 rounded-sm py-3 mt-0">
                    <div class="flex flex-col text-center">
                        <a href="#" class="px-4 py-2 text-slate-500 hover:bg-orange-50 hover:text-orange-500">Roses</a>
                        <a href="#" class="px-4 py-2 text-slate-500 hover:bg-orange-50 hover:text-orange-500">Sunflowers</a>
                        <a href="#" class="px-4 py-2 text-slate-500 hover:bg-orange-50 hover:text-orange-500">Tulips</a>
                    </div>
                </div>
            </div>

            <!-- DROPDOWN DELIVERY -->
            <div class="relative group">
                <div class="flex items-center gap-1 cursor-pointer group-hover:text-orange-500 transition-colors pb-1">
                    <span>Delivery Locations</span>
                    <i class="fa-solid fa-chevron-down text-[10px] mt-1 group-hover:rotate-180 transition-transform"></i>
                </div>
                <div class="absolute left-1/2 -translate-x-1/2 hidden group-hover:block bg-white shadow-xl border border-gray-100 min-w-[300px] z-50 rounded-sm py-4 mt-0">
                    <div class="grid grid-cols-2 text-sm text-center">
                        <a href="#" class="px-4 py-1.5 text-slate-500 hover:text-orange-500">Malolos City</a>
                        <a href="#" class="px-4 py-1.5 text-slate-500 hover:text-orange-500">Baliuag</a>
                        <a href="#" class="px-4 py-1.5 text-slate-500 hover:text-orange-500">Santa Maria</a>
                        <a href="#" class="px-4 py-1.5 text-slate-500 hover:text-orange-500">SJDM City</a>
                    </div>
                </div>
            </div>

            <!-- DROPDOWN GIFTS -->
            <div class="relative group">
                <div class="flex items-center gap-1 cursor-pointer group-hover:text-orange-500 transition-colors pb-1">
                    <span>Gifts</span>
                    <i class="fa-solid fa-chevron-down text-[10px] mt-1 group-hover:rotate-180 transition-transform"></i>
                </div>
                <div class="absolute left-1/2 -translate-x-1/2 hidden group-hover:block bg-white shadow-xl border border-gray-100 min-w-[180px] z-50 rounded-sm py-3 mt-0">
                    <div class="flex flex-col text-center">
                        <a href="#" class="px-4 py-2 text-slate-500 hover:bg-orange-50 hover:text-orange-500">Cakes</a>
                        <a href="#" class="px-4 py-2 text-slate-500 hover:bg-orange-50 hover:text-orange-500">Chocolates</a>
                    </div>
                </div>
            </div>

            <a href="about" class="hover:text-orange-500 transition-colors">About Us</a>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="max-w-7xl mx-auto mt-4 px-4">
        <div class="relative w-full h-[400px] md:h-[500px] overflow-hidden rounded-sm shadow-sm group">
            <img src="https://images.unsplash.com/photo-1490750967868-88aa4486c946?q=80&w=2000&auto=format&fit=crop" 
                 class="w-full h-full object-cover group-hover:scale-105 transition duration-700">
            <div class="absolute inset-0 bg-black/40 flex items-center px-12 text-white">
                <div class="max-w-lg">
                    <h2 class="text-5xl md:text-7xl font-black italic mb-4 uppercase">Bloominous <br><span class="text-orange-400">Fresh Deals</span></h2>
                    <p class="mb-8 opacity-90">Experience gifting in 3D/AR before you buy.</p>
                    <a href="#" class="bg-orange-500 hover:bg-orange-600 px-10 py-4 font-bold uppercase transition">Shop Now</a>
                </div>
            </div>
        </div>
    </header>

    <!-- SECTION: ABOUT US -->
    <section id="about" class="py-24 bg-white mt-16 border-y border-gray-100">
        <div class="max-w-7xl mx-auto px-4 grid md:grid-cols-2 gap-16 items-center">
            <div>
                <h2 class="text-4xl font-bold text-slate-800 mb-6 leading-tight">Empowering Gifts with <br><span class="text-orange-500 italic">Modern Technology</span></h2>
                <p class="text-slate-600 leading-relaxed mb-8">
                   blending nature’s freshness with the cutting-edge technology of 3D and Augmented Reality (AR).
                </p>
                <div class="grid grid-cols-2 gap-6 text-sm">
                    <div class="border-l-4 border-orange-400 pl-4">
                        <h4 class="font-bold text-slate-800">Our Mission</h4>
                        <p class="text-slate-500 mt-1">To provide a transparent shopping experience through realistic product visualization.</p>
                    </div>
                    <div class="border-l-4 border-orange-400 pl-4">
                        <h4 class="font-bold text-slate-800">Our Vision</h4>
                        <p class="text-slate-500 mt-1">To become the leading destination for flower gifts in the Philippines through innovation.</p>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <img src="https://images.unsplash.com/photo-1526047932273-341f2a7631f9?q=80&w=800&auto=format&fit=crop" class="rounded-lg shadow-md h-64 w-full object-cover">
                <img src="" class="rounded-lg shadow-md h-64 w-full object-cover mt-8">
            </div>
        </div>
    </section>

    <!-- SECTION: BLOG -->
    <section id="blog" class="py-24 max-w-7xl mx-auto px-4">
        <h2 class="text-4xl font-bold text-slate-800 mb-10">All About Flowers</h2>
        <div class="grid md:grid-cols-2 gap-8">
            <!-- Card 1 -->
            <div class="bg-white p-10 rounded-xl shadow-sm hover:shadow-md transition-all border-t-4 border-transparent hover:border-orange-500 group">
                <h3 class="text-2xl font-bold text-slate-800 mb-4 group-hover:text-orange-500">Why Spring Flowers Are Perfect for New Beginnings</h3>
                <p class="text-slate-500 mb-6 leading-relaxed">There is no season quite like spring. The days grow longer, the air turns warmer, and the world almost overnight remembers how to bloom...</p>
                <a href="#" class="text-orange-500 font-bold uppercase text-sm tracking-wider">Read More →</a>
            </div>
            <!-- Card 2 -->
            <div class="bg-white p-10 rounded-xl shadow-sm hover:shadow-md transition-all border-t-4 border-transparent hover:border-orange-500 group">
                <h3 class="text-2xl font-bold text-slate-800 mb-4 group-hover:text-orange-500">Thoughtful Flower Gifts for Women Who Inspire You</h3>
                <p class="text-slate-500 mb-6 leading-relaxed">Every one of us has a woman in our life who has made a difference — a mother who sacrificed without hesitation, a mentor who believed in us...</p>
                <a href="#" class="text-orange-500 font-bold uppercase text-sm tracking-wider">Read More →</a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="py-12 bg-slate-900 text-center">
        <div class="text-white font-black text-2xl mb-4 italic tracking-tighter">BLOOMINOUS</div>
        <p class="text-slate-500 text-[10px] uppercase tracking-widest">© 2026 Bloominous Flower Shop | Information Technology Project</p>
    </footer>

</body>
</html>