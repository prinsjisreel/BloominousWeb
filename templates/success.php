<?php
/**
 * BLOOMINOUS - Payment Return Page (GCash / Maya via PayMongo)
 *
 * PayMongo sends the customer's browser here after the e-wallet step.
 * Arriving on this page is NOT proof of payment — anyone can type this URL.
 * So this page never marks anything paid by itself. It:
 *   1. Reads the order from Firestore (server-side, REST + service account).
 *   2. If the webhook hasn't marked it Paid yet, asks PayMongo directly
 *      and runs the SAME PaymentHelper::finalizeOrderFromSession() the
 *      webhook uses (so the result is identical either way).
 *   3. Shows Paid / Confirming / Needs Review accordingly.
 */
session_start();

require_once __DIR__ . '/../includes/firestore_rest.php';
require_once __DIR__ . '/../includes/payment_helper.php';

// Security Check
$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
if (!$user_id) {
    header("Location: ../index.php");
    exit();
}

$orderId = (string) ($_GET['order_id'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $orderId)) {
    $orderId = '';
}

// How many times this page has auto-refreshed while waiting (max 6 x 5s).
$attempt = min(6, max(0, (int) ($_GET['attempt'] ?? 0)));

$viewState = 'unknown'; // paid | confirming | issue | unknown
$invoiceId = '';
$amountPaid = null;
$paymentChannel = '';

if ($orderId !== '') {
    try {
        $order = bloom_firestore_get_document_rest('orders', $orderId);

        if ($order !== null && (($order['user_id'] ?? null) === $user_id)) {
            // Webhook not in yet? Ask PayMongo ourselves (fallback path).
            if (($order['paymentStatus'] ?? '') !== 'Paid' && !empty($order['paymongoCheckoutId'])) {
                try {
                    $session = PaymentHelper::retrieveCheckoutSession($order['paymongoCheckoutId']);
                    PaymentHelper::finalizeOrderFromSession($orderId, $session);
                    $order = bloom_firestore_get_document_rest('orders', $orderId) ?? $order; // re-read the fresh state
                } catch (\Throwable $e) {
                    error_log('success.php PayMongo fallback failed: ' . $e->getMessage());
                }
            }

            $invoiceId = $order['invoiceId'] ?? $orderId;
            $paymentStatus = $order['paymentStatus'] ?? 'Pending';

            if ($paymentStatus === 'Paid') {
                $viewState = 'paid';
                $amountPaid = $order['amountPaid'] ?? ($order['total_price'] ?? null);
                $paymentChannel = strtoupper((string) ($order['paymentChannel'] ?? $order['payment_method'] ?? ''));
            } elseif ($paymentStatus === 'Needs Review') {
                $viewState = 'issue';
            } else {
                $viewState = 'confirming';
            }
        }
    } catch (\Throwable $e) {
        error_log('success.php could not load order ' . $orderId . ': ' . $e->getMessage());
        $viewState = 'confirming';
    }
}

$shouldAutoRefresh = ($viewState === 'confirming' && $attempt < 6);
$refreshUrl = 'success.php?order_id=' . rawurlencode($orderId) . '&attempt=' . ($attempt + 1);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($shouldAutoRefresh): ?>
        <meta http-equiv="refresh" content="5;url=<?php echo htmlspecialchars($refreshUrl); ?>">
    <?php endif; ?>
    <title>Payment Status | Bloominous</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #fcfaff; }
        .success-card { background: #fff; border-radius: 35px; padding: 60px; text-align: center; box-shadow: 0 30px 60px rgba(123, 121, 242, 0.1); max-width: 550px; width: 100%; border: 1px solid #f0f2f5; }
        .state-icon { width: 90px; height: 90px; border-radius: 30px; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 35px; transform: rotate(-10deg); }
        .icon-paid { background: #eef2ff; color: #7B79F2; }
        .icon-wait { background: #fff9e6; color: #ffbb55; }
        .icon-issue { background: #fff0f0; color: #ff5b5b; }
        .btn-track { display: inline-block; background: #7B79F2; color: #fff; padding: 18px 45px; border-radius: 20px; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; margin-top: 30px; transition: 0.3s; box-shadow: 0 15px 30px rgba(123, 121, 242, 0.2); }
        .btn-track:hover { background: #5a58d1; transform: translateY(-5px); box-shadow: 0 20px 40px rgba(123, 121, 242, 0.3); }
        .btn-secondary { display: inline-block; color: #7d8da1; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 2px; margin-top: 20px; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

<div class="success-card">

<?php if ($viewState === 'paid'): ?>
    <div class="state-icon icon-paid"><i class="fa-solid fa-check"></i></div>
    <h1 class="text-3xl font-black text-[#363949] uppercase tracking-tight mb-4">Payment Successful!</h1>
    <p class="text-[#7d8da1] mb-8 font-medium">
        Thank you for your purchase. Your order
        <span class="text-[#7B79F2] font-bold">#<?php echo htmlspecialchars($invoiceId); ?></span>
        is paid and now being processed.
    </p>

    <div class="bg-[#f8faff] p-8 rounded-3xl text-left mb-8 border border-gray-50">
        <h4 class="text-[10px] font-black uppercase tracking-[2px] text-[#7d8da1] mb-6">Payment and Delivery Status</h4>
        <div class="flex gap-5 mb-6">
            <div class="w-10 h-10 bg-white rounded-2xl flex items-center justify-center text-sm font-black text-[#7B79F2] shadow-sm border border-gray-100 italic">01</div>
            <div>
                <p class="text-xs font-black text-[#363949] uppercase tracking-tight mb-1">Payment Confirmed</p>
                <p class="text-[11px] text-[#7d8da1] font-bold leading-relaxed">
                    <?php if ($amountPaid !== null): ?>
                        P<?php echo number_format((float) $amountPaid, 2); ?>
                    <?php endif; ?>
                    received<?php echo $paymentChannel ? ' via ' . htmlspecialchars($paymentChannel) : ''; ?> and verified with PayMongo.
                </p>
            </div>
        </div>
        <div class="flex gap-5">
            <div class="w-10 h-10 bg-white rounded-2xl flex items-center justify-center text-sm font-black text-[#7B79F2] shadow-sm border border-gray-100 italic">02</div>
            <div>
                <p class="text-xs font-black text-[#363949] uppercase tracking-tight mb-1">Order Preparation</p>
                <p class="text-[11px] text-[#7d8da1] font-bold leading-relaxed">Our florists are now preparing your arrangement for delivery.</p>
            </div>
        </div>
    </div>

    <a href="../track_order.php?id=<?php echo rawurlencode($orderId); ?>" class="btn-track">Track My Order</a>

<?php elseif ($viewState === 'confirming'): ?>
    <div class="state-icon icon-wait"><i class="fa-solid fa-hourglass-half"></i></div>
    <h1 class="text-3xl font-black text-[#363949] uppercase tracking-tight mb-4">Confirming Payment</h1>
    <p class="text-[#7d8da1] mb-8 font-medium">
        We're waiting for GCash/Maya to confirm your payment for order
        <span class="text-[#7B79F2] font-bold">#<?php echo htmlspecialchars($invoiceId ?: $orderId); ?></span>.
        <?php if ($shouldAutoRefresh): ?>
            This page will refresh automatically.
        <?php else: ?>
            This is taking longer than usual. If you completed the payment, it will appear in My Orders once confirmed — please don't pay twice.
        <?php endif; ?>
    </p>
    <a href="my_orders.php" class="btn-track">Go to My Orders</a>

<?php elseif ($viewState === 'issue'): ?>
    <div class="state-icon icon-issue"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <h1 class="text-3xl font-black text-[#363949] uppercase tracking-tight mb-4">Payment Under Review</h1>
    <p class="text-[#7d8da1] mb-8 font-medium">
        We received a payment for order
        <span class="text-[#7B79F2] font-bold">#<?php echo htmlspecialchars($invoiceId); ?></span>,
        but it needs a quick check by our staff. We'll contact you shortly — no need to pay again.
    </p>
    <a href="my_orders.php" class="btn-track">Go to My Orders</a>

<?php else: ?>
    <div class="state-icon icon-wait"><i class="fa-solid fa-circle-question"></i></div>
    <h1 class="text-3xl font-black text-[#363949] uppercase tracking-tight mb-4">Order Not Found</h1>
    <p class="text-[#7d8da1] mb-8 font-medium">We couldn't find this order on your account. Please check My Orders.</p>
    <a href="my_orders.php" class="btn-track">Go to My Orders</a>
<?php endif; ?>

    <div><a href="shop.php" class="btn-secondary">Continue Shopping</a></div>
</div>

</body>
</html>