<?php
/**
 * BLOOMINOUS - Create GCash / Maya Payment Session
 *
 * Called by templates/checkout.php (via assets/script/online_payment.js)
 * RIGHT AFTER submit_order.php has created the order, and by BloominousApp
 * (PaymentService.startOrderPayment) — same contract for both platforms:
 *
 *   POST /create_payment_session.php
 *   Authorization: Bearer <Firebase ID token>
 *   Body: { "orderId": "<Firestore order doc id>", "paymentMethod": "gcash" | "maya" }
 *
 *   200 { success: true,  checkoutUrl, orderId }
 *   4xx { success: false, code?, message }
 *       codes: ALREADY_PAID, ORDER_CLOSED, RATE_LIMITED
 *
 * The amount charged ALWAYS comes from the order document in Firestore,
 * never from the request body — the client only says WHICH order.
 *
 * --- RATE LIMITING (prevention) ---
 *   0. per IP address, before the token is even verified (cheap flood stop;
 *      generous because mobile carriers share one IP across many users)
 *   2. per account (verified uid) — the real limit; can't be faked
 * Both run BEFORE any PayMongo API call (retrieve or create).
 * Section 5 (session reuse) is the second line of defense: repeated
 * clicks for the same order and wallet return the SAME PayMongo page
 * instead of creating new ones.
 *
 * Uses the REST toolkit (no gRPC), so it works on local XAMPP too.
 */

require_once __DIR__ . '/includes/firestore_rest.php';
require_once __DIR__ . '/includes/payment_helper.php';
require_once __DIR__ . '/includes/rate_limiter.php';

// Rate-limit settings. Window is in seconds (600 = 10 minutes).
const BLOOM_PAYMENT_LIMIT_WINDOW_SECONDS = 600;
const BLOOM_PAYMENT_LIMIT_PER_IP = 30;    // shared carrier IPs → keep generous
const BLOOM_PAYMENT_LIMIT_PER_USER = 10;  // retries + GCash/Maya switching

function bloom_payment_rate_limited_response(): void
{
    bloom_json_response([
        'success' => false,
        'code' => 'RATE_LIMITED',
        'message' => 'Too many payment attempts. Please wait a few minutes and try again.',
    ], 429);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

// --- 0. Per-IP limit — before verifying the token, so floods are turned
// away as cheaply as possible. (Spoofable via headers without a trusted
// proxy — which is why section 2's per-account limit is the strict one.)
if (!bloom_check_and_record_attempt('create_payment_session_ip', bloom_get_client_ip(), BLOOM_PAYMENT_LIMIT_PER_IP, BLOOM_PAYMENT_LIMIT_WINDOW_SECONDS)) {
    bloom_payment_rate_limited_response();
}

// --- 1. Who is asking? (same pattern as submit_order.php) ---
$idToken = bloom_get_bearer_token();
if (!$idToken) {
    bloom_json_response(['success' => false, 'message' => 'Missing Authorization token'], 401);
}

try {
    $uid = bloom_verify_id_token($idToken);
} catch (\Throwable $e) {
    bloom_json_response(['success' => false, 'message' => 'Invalid or expired session. Please sign in again.'], 401);
}

// --- 2. Per-account limit (reuses rate_limiter.php). The bucket name
// 'create_payment_session' is unchanged on purpose, so counters that
// already exist keep working after this update.
if (!bloom_check_and_record_attempt('create_payment_session', $uid, BLOOM_PAYMENT_LIMIT_PER_USER, BLOOM_PAYMENT_LIMIT_WINDOW_SECONDS)) {
    bloom_payment_rate_limited_response();
}

// --- 3. Validate the request body ---
$body = bloom_json_input();
$orderId = (string) ($body['orderId'] ?? '');
$method = PaymentHelper::normalizeMethod($body['paymentMethod'] ?? '');

if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $orderId)) {
    bloom_json_response(['success' => false, 'message' => 'Invalid order reference.'], 400);
}
if (!PaymentHelper::isOnlineMethod($method)) {
    bloom_json_response(['success' => false, 'message' => 'Please choose GCash or Maya for online payment.'], 400);
}

try {
    $order = bloom_firestore_get_document_rest('orders', $orderId);

    // --- 4. The order must exist AND belong to this user. We answer 404 in
    // both cases so nobody can probe which order IDs exist.
    if ($order === null || (($order['user_id'] ?? null) !== $uid)) {
        bloom_json_response(['success' => false, 'message' => 'Order not found.'], 404);
    }

    if (($order['paymentStatus'] ?? '') === 'Paid') {
        bloom_json_response(['success' => false, 'code' => 'ALREADY_PAID', 'message' => 'This order is already paid.'], 409);
    }

    $status = strtolower((string) ($order['status'] ?? 'pending'));
    if ($status !== 'pending') {
        bloom_json_response(['success' => false, 'code' => 'ORDER_CLOSED', 'message' => 'This order can no longer be paid online.'], 409);
    }

    // Only orders placed as an online method can be paid online. Switching
    // between GCash <-> Maya on retry is allowed; COD -> online is not.
    if (!PaymentHelper::isOnlineMethod($order['payment_method'] ?? '')) {
        bloom_json_response(['success' => false, 'message' => 'This order was not placed with an online payment method.'], 409);
    }

    // --- 5. Idempotency: reuse a still-open session instead of stacking
    // up new ones every time the customer clicks "Pay" again.
    $existingSessionId = $order['paymongoCheckoutId'] ?? null;
    $existingMethod = PaymentHelper::normalizeMethod($order['payment_method'] ?? '');

    if ($existingSessionId) {
        try {
            $existing = PaymentHelper::retrieveCheckoutSession($existingSessionId);

            // Paid already but the webhook hasn't landed yet? Finish it now.
            if (PaymentHelper::extractPaidPayment($existing) !== null) {
                PaymentHelper::finalizeOrderFromSession($orderId, $existing);
                bloom_json_response(['success' => false, 'code' => 'ALREADY_PAID', 'message' => 'This order is already paid.'], 409);
            }

            $isActive = ($existing['attributes']['status'] ?? '') === 'active';
            $existingUrl = $existing['attributes']['checkout_url'] ?? null;
            if ($isActive && $existingUrl && $existingMethod === $method) {
                bloom_json_response(['success' => true, 'checkoutUrl' => $existingUrl, 'orderId' => $orderId]);
            }
        } catch (\Throwable $e) {
            // Couldn't look up the old session — just create a fresh one below.
            error_log('Reusing PayMongo session failed, creating new: ' . $e->getMessage());
        }
    }

    // --- 6. Create a new hosted GCash/Maya page for this order ---
    $session = PaymentHelper::createCheckoutSession([
        'orderId'      => $orderId,
        'invoiceId'    => $order['invoiceId'] ?? '',
        'amount'       => (float) ($order['total_price'] ?? 0),
        'method'       => $method,
        'customerName' => $order['customer_name'] ?? '',
        'email'        => $order['email'] ?? '',
        'phone'        => $order['phone'] ?? '',
    ]);

    // Remember EVERY session we ever made for this order, so a payment on
    // an older tab is still recognized by the webhook. (REST has no
    // arrayUnion, so: old list + new id, duplicates removed.)
    $knownSessions = is_array($order['paymongoCheckoutIds'] ?? null) ? $order['paymongoCheckoutIds'] : [];
    $knownSessions[] = $session['id'];

    bloom_firestore_update_fields_rest('orders', $orderId, [
        'paymongoCheckoutId'  => $session['id'],
        'paymongoCheckoutIds' => array_values(array_unique($knownSessions)),
        'payment_method'      => $method,
        'paymentSessionAt'    => bloom_rest_now(),
    ]);

    bloom_json_response(['success' => true, 'checkoutUrl' => $session['checkoutUrl'], 'orderId' => $orderId]);

} catch (\InvalidArgumentException $e) {
    bloom_json_response(['success' => false, 'message' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('create_payment_session failed: ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'We could not start your GCash/Maya payment. Please try again.'], 502);
}