<?php
error_reporting(0);
session_start();

// 1. DATABASE CONNECTION
$conn = new mysqli("localhost", "root", "", "bloomshop_db");
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// --- LOGIC: UPDATE QUANTITY ---
if (isset($_POST['update_cart'])) {
    $p_id = $_POST['product_id'];
    $new_qty = $_POST['quantity'];
    if ($new_qty > 0) {
        $_SESSION['cart'][$p_id] = $new_qty;
    } else {
        unset($_SESSION['cart'][$p_id]); // Burahin pag ginawang 0
    }
    header("Location: cart.php");
    exit();
}

// --- LOGIC: REMOVE ITEM ---
if (isset($_GET['remove'])) {
    $p_id = $_GET['remove'];
    unset($_SESSION['cart'][$p_id]);
    header("Location: cart.php");
    exit();
}

// Logic para sa User Display
$user_display_name = $_SESSION['username'] ?? 'Guest'; 
$is_logged_in = isset($_SESSION['user_id']); 

// Count items for icon
$cart_count = isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cart | Bloominous</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f4f7; }
    </style>
</head>
<body>

    <!-- NAVIGATION BAR -->
    <nav class="bg-white sticky top-0 z-50 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="shop.php" class="text-orange-500 font-black text-3xl italic">BLOOM</a>
            
            <div class="flex items-center gap-6 text-sm font-medium text-gray-700">
                <a href="shop.php" class="hover:text-orange-500 uppercase text-xs font-bold">Continue Shopping</a>
                <div class="relative cursor-pointer group">
                    <i class="fas fa-shopping-cart text-2xl text-orange-500"></i>
                    <span class="absolute -top-2 -right-2 bg-orange-500 text-white text-[10px] rounded-full px-1.5 font-bold"><?= $cart_count ?></span>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto p-4 md:p-8">
        <h2 class="text-2xl font-black text-gray-800 uppercase mb-6 tracking-tight">Shopping Cart</h2>

        <?php if (empty($_SESSION['cart'])): ?>
            <!-- EMPTY CART UI -->
            <div class="bg-white rounded-lg p-12 text-center shadow-sm">
                <div class="mb-6">
                    <i class="fas fa-shopping-basket text-6xl text-gray-200"></i>
                </div>
                <h3 class="text-gray-500 font-bold uppercase tracking-widest mb-4">Your cart is empty</h3>
                <a href="shop.php" class="bg-orange-500 text-white px-8 py-3 rounded-sm font-bold hover:bg-orange-600 transition inline-block">GO SHOPPING</a>
            </div>
        <?php else: ?>
            <div class="flex flex-col lg:flex-row gap-8">
                
                <!-- CART ITEMS LIST -->
                <div class="flex-1 space-y-4">
                    <div class="bg-white p-4 rounded-sm shadow-sm hidden md:grid grid-cols-6 text-[10px] font-bold text-gray-400 uppercase tracking-widest border-b">
                        <div class="col-span-3">Product</div>
                        <div class="text-center">Price</div>
                        <div class="text-center">Quantity</div>
                        <div class="text-right">Total</div>
                    </div>

                    <?php 
                    $grand_total = 0;
                    // Kunin ang mga IDs mula sa session cart
                    $cart_ids = array_keys($_SESSION['cart']);
                    $ids_string = implode(',', $cart_ids);
                    
                    $query = "SELECT * FROM products WHERE id IN ($ids_string)";
                    $result = mysqli_query($conn, $query);

                    while($row = mysqli_fetch_assoc($result)): 
                        $p_id = $row['id'];
                        $qty = $_SESSION['cart'][$p_id];
                        $subtotal = $row['price'] * $qty;
                        $grand_total += $subtotal;
                    ?>
                    <div class="bg-white p-4 rounded-sm shadow-sm grid grid-cols-1 md:grid-cols-6 items-center gap-4">
                        <!-- Product Info -->
                        <div class="col-span-3 flex items-center gap-4">
                            <img src="uploads/<?= $row['image'] ?>" class="w-16 h-16 object-cover rounded-sm border" onerror="this.src='https://via.placeholder.com/100'">
                            <div>
                                <h4 class="text-sm font-bold text-gray-800 leading-tight"><?= htmlspecialchars($row['product_name']) ?></h4>
                                <a href="cart.php?remove=<?= $p_id ?>" class="text-[10px] text-red-500 font-bold hover:underline mt-1 block">REMOVE</a>
                            </div>
                        </div>

                        <!-- Price -->
                        <div class="text-center text-sm font-medium text-gray-600">
                            ₱<?= number_format($row['price'], 2) ?>
                        </div>

                        <!-- Quantity Update -->
                        <div class="text-center">
                            <form method="POST" class="flex items-center justify-center gap-1">
                                <input type="hidden" name="product_id" value="<?= $p_id ?>">
                                <input type="number" name="quantity" value="<?= $qty ?>" min="1" max="<?= $row['stock_quantity'] ?>" 
                                       onchange="this.form.submit()"
                                       class="w-12 border text-center text-sm py-1 rounded-sm focus:ring-1 focus:ring-orange-500 outline-none">
                                <input type="hidden" name="update_cart">
                            </form>
                        </div>

                        <!-- Subtotal -->
                        <div class="text-right text-orange-500 font-bold text-sm">
                            ₱<?= number_format($subtotal, 2) ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>

                <!-- SUMMARY CARD -->
                <div class="w-full lg:w-80">
                    <div class="bg-white p-6 rounded-sm shadow-sm sticky top-24">
                        <h3 class="text-sm font-black uppercase text-gray-800 mb-4 border-b pb-2">Order Summary</h3>
                        
                        <div class="flex justify-between text-sm mb-2 text-gray-600">
                            <span>Subtotal</span>
                            <span>₱<?= number_format($grand_total, 2) ?></span>
                        </div>
                        <div class="flex justify-between text-sm mb-4 text-gray-600 border-b pb-4">
                            <span>Shipping Fee</span>
                            <span class="text-green-500 font-bold text-xs uppercase italic text-[10px]">Free</span>
                        </div>

                        <div class="flex justify-between mb-6">
                            <span class="text-lg font-black text-gray-800 uppercase">Total</span>
                            <span class="text-xl font-black text-orange-500 italic">₱<?= number_format($grand_total, 2) ?></span>
                        </div>

                        <?php if($is_logged_in): ?>
                            <a href="checkout.php" class="block w-full bg-orange-500 text-white text-center py-3 rounded-sm font-bold hover:bg-orange-600 transition shadow-lg shadow-orange-100 uppercase text-xs tracking-widest">
                                Proceed to Checkout
                            </a>
                        <?php else: ?>
                            <a href="../index.php" class="block w-full bg-gray-800 text-white text-center py-3 rounded-sm font-bold hover:bg-black transition uppercase text-xs tracking-widest">
                                Login to Checkout
                            </a>
                        <?php endif; ?>

                        <p class="text-[9px] text-gray-400 mt-4 text-center italic">Shipping available within Marilao area only.</p>
                    </div>
                </div>

            </div>
        <?php endif; ?>
    </main>

</body>
</html>