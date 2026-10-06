<?php
/**
 * BLOOMINOUS - Server-side Order Submission
 *
 * Replaces the client-side db.collection('orders').add(...) call in
 * templates/checkout.php. All fraud CHECKS and all PRICING run here,
 * against the server's own view of Firestore, so a modified browser or
 * app can't skip them or change what gets charged.
 *
 * The browser can't write straight to `orders`/`customers` fraud fields —
 * see firestore.rules, which denies client `orders` create entirely and
 * denies client writes to the fraud fields on `customers`.
 *
 * --- SHARED WEB + APP CONTRACT ---
 * templates/checkout.php (web) and delivery_details_page.dart /
 * order_submission_service.dart (app) both POST here. Section 3a keeps the
 * two platforms consistent: one payment-method vocabulary ('gcash' | 'maya'
 * | 'cod'), no COD on gift orders, and an optional plain-text `notes` field.
 * Response codes: RESTRICTED, BLOCKED, EMAIL_UNREACHABLE, RATE_LIMITED,
 * plus the pricing codes from includes/order_pricing.php (EMPTY_CART,
 * INVALID_ITEM, INVALID_QUANTITY, TOO_MANY_ITEMS, QUANTITY_LIMIT,
 * PRODUCT_UNAVAILABLE, OUT_OF_STOCK) and INVALID_FEE.
 *
 * --- SERVER-SIDE PRICING (section 3b) ---
 * Client-sent item prices, names and `subtotal` are NOT trusted. Every
 * line is priced from its inventory document (includes/order_pricing.php).
 * The client's `subtotal` is only compared against the real one: if it
 * claimed LESS, that's recorded as a Payment Fraud activity.
 *
 * --- RATE LIMITING (prevention) ---
 * Sections 0 and 1b cap how often orders can be ATTEMPTED, before any
 * paid API call, Firestore query, or invoice number is used:
 *   - per IP address: generous, because mobile carriers put many real
 *     customers behind one shared IP; stops raw request floods cheaply.
 *   - per account (verified uid): the real limit; can't be faked, since
 *     the uid comes from a signed Firebase ID token.
 * This PREVENTS floods; the velocity check in section 4 DETECTS and
 * records unusual-but-allowed repeat orders for admin review.
 *
 * --- RESTRICTIONS (section 2) ---
 * A restriction lasts at most BLOOM_RESTRICTION_MAX_DAYS (30) days. While
 * active, the customer must pass phone OTP to order. Once the 30 days
 * are over it is treated as expired: no OTP is asked and the account is
 * unrestricted automatically on its next order (section 6).
 *
 * --- FRAUD ACTIVITIES + TRIAGE ---
 * Each check below answers one yes/no question. Every "yes" is recorded
 * as a fraud ACTIVITY, tagged with a category from the e-commerce fraud
 * studies this module is based on (see includes/fraud_activity.php):
 *   account_takeover | payment_fraud | fake_transaction | malicious_return
 *
 * On top of the activities, includes/fraud_activity.php derives:
 *   - riskLevel (critical/high/medium/low): rule-based, from categories
 *   - riskScore (0-100): evidence-tiered points per activity code
 * Both are saved on the ORDER (this order's activities only) and on the
 * CUSTOMER (whole account history). They decide REVIEW ORDER and how
 * loud the admin notification is. They NEVER block or restrict anyone,
 * and they are never sent back to the customer.
 *
 * The ORDER STILL GOES THROUGH. Deciding whether an activity is real
 * fraud is a human decision, made after reviewing the customer's history
 * in the Fraud Activity Log (fraud_review.php).
 *
 * The ONE automatic action left is hard evidence: an order from a device
 * already banned. That restricts the account at once.
 *
 * --- REST MIGRATION (no gRPC) ---
 * Every Firestore call in this file goes through the plain REST helpers
 * (includes/firestore_rest.php + firebase_admin.php's *_rest functions)
 * instead of bloom_firestore(). The gRPC FirestoreClient crashes PHP on the
 * local XAMPP install (browser sees ERR_CONNECTION_RESET).
 */

require_once __DIR__ . '/includes/firestore_rest.php';
require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/fraud_activity.php';
require_once __DIR__ . '/includes/order_pricing.php';
require_once __DIR__ . '/includes/abstractapi_ip_client.php';
require_once __DIR__ . '/includes/abstractapi_phone_client.php';

// Rate-limit settings. Window is in seconds (600 = 10 minutes).
const BLOOM_ORDER_LIMIT_WINDOW_SECONDS = 600;
const BLOOM_ORDER_LIMIT_PER_IP = 30;   // shared carrier IPs → keep generous
const BLOOM_ORDER_LIMIT_PER_USER = 5;  // one real customer rarely needs more

// Delivery fee sanity range, in pesos. The fee is ₱1 per road km, so
// ₱2,000 already covers any delivery a branch could realistically make.
// (A full server-side fee calculation is a planned follow-up.)
const BLOOM_ORDER_MAX_SHIPPING_FEE = 2000;

// Risk levels that make an admin notification "high priority".
const BLOOM_PRIORITY_RISK_LEVELS = ['high', 'critical'];

// Safety net: any uncaught error (Firestore/network hiccup) still answers
// with JSON, so checkout shows a clear message instead of a blank
// 500 page or "Failed to fetch".
set_exception_handler(function (\Throwable $e) {
    error_log('submit_order.php failed: ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'We could not place your order right now. Please try again.'], 500);
});

/**
 * Wraps the shared rate limiter. bloom_check_and_record_attempt() already
 * fails open on its own; this is a second safety net in case that helper
 * ever changes to throw instead.
 */
function bloom_order_rate_limit_allows(string $action, string $key, int $maxAttempts, int $windowSeconds): bool
{
    try {
        return bloom_check_and_record_attempt($action, $key, $maxAttempts, $windowSeconds);
    } catch (\Throwable $e) {
        error_log("submit_order.php: rate limiter failed for {$action}, failing open: " . $e->getMessage());
        return true;
    }
}

function bloom_order_rate_limited_response(): void
{
    bloom_json_response([
        'success' => false,
        'code' => 'RATE_LIMITED',
        'message' => 'Too many order attempts. Please wait a few minutes and try again. If you already placed an order, check My Orders first.',
    ], 429);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

// --- 0. Per-IP rate limit — runs FIRST, before even verifying the token,
// so a flood of requests is turned away as cheaply as possible.
$clientIpForLimit = bloom_get_client_ip();
if (!bloom_order_rate_limit_allows('submit_order_ip', $clientIpForLimit, BLOOM_ORDER_LIMIT_PER_IP, BLOOM_ORDER_LIMIT_WINDOW_SECONDS)) {
    bloom_order_rate_limited_response();
}

// --- 1. Authenticate: the ID token is the only trustworthy identity here ---
$idToken = bloom_get_bearer_token();
if (!$idToken) {
    bloom_json_response(['success' => false, 'message' => 'Missing Authorization token'], 401);
}

try {
    $uid = bloom_verify_id_token($idToken);
} catch (\Throwable $e) {
    bloom_json_response(['success' => false, 'message' => 'Invalid or expired session. Please sign in again.'], 401);
}

// --- 1b. Per-account rate limit — the uid comes from a signed token, so
// switching networks, VPNs, or fake headers can't get around this one.
if (!bloom_order_rate_limit_allows('submit_order_uid', $uid, BLOOM_ORDER_LIMIT_PER_USER, BLOOM_ORDER_LIMIT_WINDOW_SECONDS)) {
    bloom_order_rate_limited_response();
}

$body = bloom_json_input();

// Defense in depth: if the client claims a different user_id than its own
// verified token, something is wrong — never trust the body's user id alone.
if (isset($body['user_id']) && $body['user_id'] !== $uid) {
    bloom_json_response(['success' => false, 'message' => 'User mismatch'], 403);
}

$customer = bloom_firestore_get_document_rest('customers', $uid);

if ($customer === null) {
    bloom_json_response(['success' => false, 'message' => 'Customer profile not found'], 404);
}

if (($customer['status'] ?? null) === 'blocked') {
    bloom_json_response(['success' => false, 'code' => 'BLOCKED', 'message' => 'This account has been blocked.'], 403);
}

// --- 2. Restriction gate: server-trusted, at most 30 days ---
// active  = restricted and the 30 days are not over -> OTP required
// expired = restricted but the 30 days are over     -> lifted in section 6
$restriction = bloom_restriction_state($customer);
$isRestricted = $restriction['active'];
$restrictionExpired = $restriction['expired'];
$otpVerified = ($body['otpVerified'] ?? false) === true;

if ($isRestricted && !$otpVerified) {
    bloom_json_response([
        'success' => false,
        'code' => 'RESTRICTED',
        'message' => 'This account is currently restricted. Verify your phone number to continue.',
    ], 403);
}

// If the client claims OTP verification, confirm it against Auth itself —
// don't just take the flag's word for it.
if ($isRestricted && $otpVerified) {
    try {
        $userRecord = bloom_auth()->getUser($uid);
        $linkedPhone = $userRecord->phoneNumber ?? null;
        if (!$linkedPhone) {
            bloom_json_response(['success' => false, 'message' => 'Phone verification not found on this account.'], 403);
        }
    } catch (\Throwable $e) {
        bloom_json_response(['success' => false, 'message' => 'Could not verify phone status.'], 403);
    }
}

// --- 2b. Email mail-server existence check — free (plain DNS, no API
// quota). Only runs for accounts that are already flagged. Blocks THIS
// order only, never the account.
require_once __DIR__ . '/includes/email_domain_policy.php';

if (bloom_customer_is_fraud_flagged($customer)) {
    $customerEmail = $customer['email'] ?? null;
    if ($customerEmail) {
        $atPos = strrpos($customerEmail, '@');
        $emailDomain = $atPos !== false ? substr($customerEmail, $atPos + 1) : null;

        if ($emailDomain && !bloom_domain_has_mail_server($emailDomain)) {
            bloom_json_response([
                'success' => false,
                'code' => 'EMAIL_UNREACHABLE',
                'message' => 'We couldn\'t verify your email address is still active. Please update your email or try again shortly.',
            ], 403);
        }
    }
}

// --- 3. Validate the minimum shape of the order payload ---
// 'subtotal' is no longer required: the server computes it (section 3b).
$required = ['name', 'phone', 'address', 'items', 'shippingFee', 'paymentMethod', 'branchId'];
foreach ($required as $field) {
    if (!isset($body[$field]) || $body[$field] === '') {
        bloom_json_response(['success' => false, 'message' => "Missing field: $field"], 400);
    }
}

$isGift = ($body['isGift'] ?? false) === true;
$branchId = (string) $body['branchId'];
$customerLat = isset($body['customerLat']) && $body['customerLat'] !== '' ? (float) $body['customerLat'] : null;
$customerLng = isset($body['customerLng']) && $body['customerLng'] !== '' ? (float) $body['customerLng'] : null;
$normalizedPhone = preg_replace('/[^0-9+]/', '', $body['phone']);

if (!bloom_is_valid_doc_id($branchId)) {
    bloom_json_response(['success' => false, 'message' => 'Invalid branch. Please recalculate your delivery location.'], 400);
}

// Delivery fee: still computed by the client for now, but it must be a
// real number inside a sane range.
$shippingFee = is_numeric($body['shippingFee']) ? round((float) $body['shippingFee'], 2) : -1.0;
if ($shippingFee < 0 || $shippingFee > BLOOM_ORDER_MAX_SHIPPING_FEE) {
    bloom_json_response(['success' => false, 'code' => 'INVALID_FEE', 'message' => 'The delivery fee looks wrong. Please recalculate your delivery location and try again.'], 400);
}

// --- 3a. Shared web + app contract ---
// One payment-method vocabulary for both platforms. Older spellings are
// mapped onto it ('COD' from the previous web checkout, 'paymaya' from
// PayMongo's own naming) so every stored order uses the same three values.
$paymentAliases = [
    'gcash' => 'gcash',
    'maya' => 'maya',
    'paymaya' => 'maya',
    'cod' => 'cod',
];
$rawPaymentMethod = strtolower(trim((string) $body['paymentMethod']));
if (!isset($paymentAliases[$rawPaymentMethod])) {
    bloom_json_response(['success' => false, 'message' => 'Unsupported payment method. Please choose GCash, Maya, or Cash on Delivery.'], 400);
}
$paymentMethod = $paymentAliases[$rawPaymentMethod];

// Same rule both checkouts show in their UI — enforced here so a modified
// client can't skip it.
if ($isGift && $paymentMethod === 'cod') {
    bloom_json_response(['success' => false, 'message' => 'Cash on Delivery isn\'t available for gift orders. Please choose GCash or Maya.'], 400);
}

// Optional delivery instructions ("Order Notes" on web and app). Plain text
// only, trimmed, and capped so nobody can stuff a huge blob into an order.
$notes = trim(strip_tags((string) ($body['notes'] ?? '')));
$notes = function_exists('mb_substr') ? mb_substr($notes, 0, 500) : substr($notes, 0, 500);

// Every fraud activity this order triggers is collected here. Each entry is
// { category, code, reason } built by bloom_fraud_activity().
$fraudActivities = [];
$hasHardEvidence = false; // true only for PROOF (banned device), never for a suspicious pattern

// --- 3b. Server-side pricing (Payment Fraud prevention) ---
// Runs BEFORE the paid fraud lookups below, so an out-of-stock or invalid
// cart is refused without spending AbstractAPI quota.
try {
    $pricing = bloom_price_order_items($body['items'], $branchId);
} catch (BloomOrderPricingException $e) {
    bloom_json_response([
        'success' => false,
        'code' => $e->errorCode,
        'message' => $e->getMessage(),
    ], $e->httpStatus);
}

$items = $pricing['items'];
$subtotal = $pricing['subtotal'];

// The client's own subtotal is kept ONLY to compare. If it claimed LESS
// than the real price, record it (Payment Fraud). Not blocked: the order is
// charged the correct amount anyway, and an honest customer can hold an
// outdated price if an admin changed it while the item sat in their cart.
$clientSubtotal = isset($body['subtotal']) && is_numeric($body['subtotal']) ? round((float) $body['subtotal'], 2) : null;
if ($clientSubtotal !== null && $clientSubtotal + 0.009 < $subtotal) {
    $fraudActivities[] = bloom_fraud_activity('payment_fraud', 'price_mismatch', 'Payment amount mismatch: cart prices sent were lower than current product prices');
}

// Server-captured only — never trust an IP the client claims in the body.
// X-Forwarded-For may hold a chain (client, proxy1, proxy2...); the first
// entry is the original client IF the reverse proxy is trusted to set it.
// Treat this as a fraud SIGNAL, not a hard identity.
$forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$requestIp = $forwardedFor ? trim(explode(',', $forwardedFor)[0]) : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$deviceHash = isset($body['deviceHash']) && preg_match('/^[a-f0-9]{64}$/', $body['deviceHash']) ? $body['deviceHash'] : null;

// --- 3c. Banned device (Fake Transaction, HARD EVIDENCE) ---
if ($deviceHash !== null) {
    $bannedDevice = bloom_firestore_get_document_rest('banned_devices', $deviceHash);
    // An admin may have reviewed THIS customer and marked the match a false
    // alarm (fraud_review.php adds them to allowedUids). The device stays
    // banned for everyone else.
    $allowedUids = is_array($bannedDevice['allowedUids'] ?? null) ? $bannedDevice['allowedUids'] : [];
    if ($bannedDevice !== null && !in_array($uid, $allowedUids, true)) {        $fraudActivities[] = bloom_fraud_activity('fake_transaction', 'banned_device', 'Order placed from a previously banned device');
        $hasHardEvidence = true;
    }
}

// --- 3d. IP reputation (Account Theft) ---
// Recorded only — VPNs are also used by ordinary privacy-minded customers.
// Relay/mobile are deliberately NOT recorded. Fails open if the vendor is
// unreachable.
if ($requestIp !== 'unknown') {
    try {
        $ipResult = bloom_abstractapi_check_ip($requestIp);

        if ($ipResult['tor'] || $ipResult['abuse']) {
            $fraudActivities[] = bloom_fraud_activity('account_takeover', 'ip_tor_abuse', 'High-risk IP reputation (Tor/abuse flagged)');
        } elseif ($ipResult['vpn'] || $ipResult['proxy']) {
            $fraudActivities[] = bloom_fraud_activity('account_takeover', 'ip_vpn_proxy', 'VPN/Proxy detected');
        }
    } catch (\Throwable $e) {
        error_log('bloom_abstractapi_check_ip failed, failing open: ' . $e->getMessage());
    }
}

// --- 3e. Phone checks (Fake Transaction) ---
try {
    $phoneResult = bloom_abstractapi_check_phone($normalizedPhone);

    if ($phoneResult['disposable'] || $phoneResult['voip']) {
        $fraudActivities[] = bloom_fraud_activity('fake_transaction', 'phone_disposable_voip', 'Disposable/VOIP phone number used at checkout');
    }
} catch (\Throwable $e) {
    error_log('bloom_abstractapi_check_phone failed, failing open: ' . $e->getMessage());
}

try {
    $phoneReuseRows = bloom_firestore_query_rest('orders', 'phone', $normalizedPhone);
    foreach ($phoneReuseRows as $reuseRow) {
        $reuseData = $reuseRow['data'];
        if (($reuseData['user_id'] ?? null) !== $uid) {
            $fraudActivities[] = bloom_fraud_activity('fake_transaction', 'phone_reuse', 'Phone number already associated with a different account');
            break;
        }
    }
} catch (\Throwable $e) {
    error_log('Phone reuse check failed, failing open: ' . $e->getMessage());
}

// --- 3f. Delivery address reuse (Fake Transaction) ---
$normalizedAddress = strtolower(trim(preg_replace('/\s+/', ' ', (string) $body['address'])));

try {
    $addressReuseRows = bloom_firestore_query_rest('orders', 'normalizedAddress', $normalizedAddress);
    foreach ($addressReuseRows as $reuseRow) {
        $reuseData = $reuseRow['data'];
        if (($reuseData['user_id'] ?? null) !== $uid) {
            $fraudActivities[] = bloom_fraud_activity('fake_transaction', 'address_reuse', 'Delivery address already associated with a different account');
            break;
        }
    }
} catch (\Throwable $e) {
    error_log('Address reuse check failed, failing open: ' . $e->getMessage());
}

// --- 4. Velocity (Fake Transaction) ---
// Records repeat/rapid orders that stay UNDER the rate limit, so the admin
// can see how they were bunched together.
$fiveMinAgo = new DateTimeImmutable('-5 minutes');
$pastOrderRows = bloom_firestore_query_rest('orders', 'user_id', $uid);

$recentOrderCount = 0;
$orderCount = 0;
foreach ($pastOrderRows as $orderRow) {
    $orderCount++;
    $oData = $orderRow['data'];
    $ts = $oData['createdAt'] ?? $oData['timestamp'] ?? null;
    if ($ts instanceof \DateTimeInterface && $ts >= $fiveMinAgo) {
        $recentOrderCount++;
    }
}
$isFirstOrder = ($orderCount === 0);

if ($recentOrderCount === 1) {
    $fraudActivities[] = bloom_fraud_activity('fake_transaction', 'velocity_repeat', 'Repeat checkout within a 5-minute window');
} elseif ($recentOrderCount >= 2) {
    $fraudActivities[] = bloom_fraud_activity('fake_transaction', 'velocity_rapid', "Multiple rapid checkouts flagged ({$recentOrderCount} prior orders in under 5 minutes)");
}

// --- 5. Geo mismatch (Account Theft) ---
function bloom_haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusKm * $c;
}

if (!$isGift && $customerLat !== null && $customerLng !== null) {
    $branchData = bloom_firestore_get_document_rest('branches', $branchId);
    if ($branchData !== null) {
        $branchLat = $branchData['latitude'] ?? null;
        $branchLng = $branchData['longitude'] ?? null;
        if (is_numeric($branchLat) && is_numeric($branchLng)) {
            $distance = bloom_haversine_km((float) $branchLat, (float) $branchLng, $customerLat, $customerLng);
            if ($distance > 50) {
                $fraudActivities[] = bloom_fraud_activity('account_takeover', 'geo_mismatch', 'Severe Device-to-Destination Mismatch');
            }
        }
    }
}

// --- 5b. Triage for THIS order only ---
// fraudCodes / riskScore / riskLevel from this order's own activities.
// (The customer's account-wide values are built in section 6.)
$orderRisk = bloom_build_fraud_order_fields($fraudActivities);

// --- 6. Save activities on the customer, then apply the only automatic
// actions that remain:
//   (a) banned-device match  -> restrict for 30 days (hard evidence)
//   (b) restricted customer who just passed phone OTP -> lift restriction
//   (c) restriction older than 30 days               -> lift (expired)
// bloom_build_fraud_customer_update() also writes the account-wide
// fraudCodes / riskScore / riskLevel (whole history, merged).
$now = bloom_rest_now();
$customerUpdate = bloom_build_fraud_customer_update($customer, $fraudActivities, $now);

if ($hasHardEvidence) {
    $customerUpdate['isRestricted'] = true;
    $customerUpdate['restrictedUntil'] = new DateTimeImmutable('+' . BLOOM_RESTRICTION_MAX_DAYS . ' days');
    $customerUpdate['fraudFlags'] = bloom_merge_unique(
        $customerUpdate['fraudFlags'] ?? [],
        ['Automated 30-Day Restriction: confirmed hard-evidence match (previously banned device).']
    );
} elseif ($isRestricted && $otpVerified) {
    $existingFlags = $customerUpdate['fraudFlags'] ?? ($customer['fraudFlags'] ?? []);
    $customerUpdate['isRestricted'] = false;
    $customerUpdate['fraudFlags'] = bloom_merge_unique(
        is_array($existingFlags) ? $existingFlags : [],
        ['Identity verified via SMS - Trust Restored']
    );
} elseif ($restrictionExpired) {
    $existingFlags = $customerUpdate['fraudFlags'] ?? ($customer['fraudFlags'] ?? []);
    $customerUpdate['isRestricted'] = false;
    $customerUpdate['fraudFlags'] = bloom_merge_unique(
        is_array($existingFlags) ? $existingFlags : [],
        ['Restriction expired after ' . BLOOM_RESTRICTION_MAX_DAYS . ' days - lifted automatically']
    );
}

if (!empty($customerUpdate)) {
    bloom_firestore_update_fields_rest('customers', $uid, $customerUpdate);
}

if ($hasHardEvidence) {
    bloom_firestore_add_document_rest('notifications', [
        'title' => 'Fraud Alert - Account Restricted',
        'message' => "Account [$uid] was restricted: order placed from a previously banned device.",
        'type' => 'fraud',
        'priority' => 'high',
        'riskLevel' => 'critical',
        'branchId' => $branchId,
        'created_at' => $now,
        'read' => false,
    ]);

    bloom_json_response([
        'success' => false,
        'code' => 'RESTRICTED',
        'message' => 'This order could not be completed. This account has been restricted for 30 days. Verify your phone number to continue.',
    ], 403);
}

// --- 7. Sequential invoice number, transaction-safe ---
$invoiceId = bloom_firestore_transaction_rest(function (BloomRestTransaction $tx) {
    $year = (int) date('Y');
    $data = $tx->get('counters', 'invoices') ?? [];
    $nextNumber = ((int) ($data['year'] ?? 0) === $year ? (int) ($data['current'] ?? 0) : 0) + 1;
    $id = sprintf('INV-%d-%04d', $year, $nextNumber);
    $tx->set('counters', 'invoices', ['current' => $nextNumber, 'year' => $year], true);
    return $id;
});

// --- 8. Create the order (server-computed prices and fraud fields only) ---
$finalTotal = round($subtotal + $shippingFee, 2);
$orderCategories = array_values(array_unique(array_column($fraudActivities, 'category')));

$orderId = bloom_firestore_add_document_rest('orders', [
    'user_id' => $uid,
    'invoiceId' => $invoiceId,
    'customer_name' => $body['name'],
    'customerName' => $body['name'],
    'recipientName' => $body['name'],
    'recipientPhone' => $body['phone'],
    'email' => $body['email'] ?? '',
    'address' => $body['address'],
    'normalizedAddress' => $normalizedAddress,
    'phone' => $normalizedPhone,
    'payment_method' => $paymentMethod,
    'notes' => $notes,
    'items' => $items,                     // server-priced lines (real name/price)
    'subtotal' => $subtotal,               // server-computed
    'clientSubtotal' => $clientSubtotal,   // what the client claimed, for admin review
    'shipping_fee' => $shippingFee,
    'total_price' => $finalTotal,          // what PayMongo / the rider will collect
    'branchId' => $branchId,
    'status' => 'pending',
    'paymentStatus' => 'Pending',
    'locked' => false,
    'type' => 'WEB',
    'isGift' => $isGift,
    'isFirstOrder' => $isFirstOrder,
    'fraudActivities' => $fraudActivities,
    'fraudFlags' => array_column($fraudActivities, 'reason'),
    'fraudCategories' => $orderCategories,
    'fraudCodes' => $orderRisk['fraudCodes'],   // triage: this order only
    'riskScore' => $orderRisk['riskScore'],
    'riskLevel' => $orderRisk['riskLevel'],
    'requestIp' => $requestIp,
    'deviceHash' => $deviceHash,
    'timestamp' => $now,
    'createdAt' => $now,
]);

// --- 9. Notifications ---
bloom_firestore_add_document_rest('notifications', [
    'title' => 'New Web Order Placed',
    'message' => "Order {$invoiceId} valued at P" . number_format($finalTotal, 2) . " received from {$body['name']}.",
    'type' => 'sale',
    'branchId' => $branchId,
    'created_at' => $now,
    'read' => false,
]);

if (!empty($fraudActivities)) {
    $categoryLabels = array_map(fn($key) => BLOOM_FRAUD_CATEGORIES[$key], $orderCategories);

    // Account-wide level after this order (falls back to what was stored
    // before, then to this order's level).
    $accountRiskLevel = $customerUpdate['riskLevel'] ?? ($customer['riskLevel'] ?? $orderRisk['riskLevel']);

    // Escalate if EITHER this order or the whole account is high/critical.
    $isPriority = in_array($orderRisk['riskLevel'], BLOOM_PRIORITY_RISK_LEVELS, true)
        || in_array($accountRiskLevel, BLOOM_PRIORITY_RISK_LEVELS, true);

    bloom_firestore_add_document_rest('notifications', [
        'title' => $isPriority ? 'High-Priority Fraud Activity' : 'Fraud Activity Recorded',
        'message' => "Order {$invoiceId} from account [$uid] recorded: " . implode(', ', $categoryLabels)
            . '. Order risk: ' . ucfirst($orderRisk['riskLevel']) . " ({$orderRisk['riskScore']} pts)"
            . '; account risk: ' . ucfirst((string) $accountRiskLevel)
            . '. Review it in the Fraud Activity Log.',
        'type' => 'fraud',
        'priority' => $isPriority ? 'high' : 'normal',
        'riskLevel' => $orderRisk['riskLevel'],
        'riskScore' => $orderRisk['riskScore'],
        'accountRiskLevel' => $accountRiskLevel,
        'branchId' => $branchId,
        'created_at' => $now,
        'read' => false,
    ]);
}

// The server's own totals are returned too, so clients can show the
// customer exactly what was charged. Risk values are deliberately NOT
// returned: telling a fraudster how they were scored helps them adapt.
bloom_json_response([
    'success' => true,
    'orderId' => $orderId,
    'invoiceId' => $invoiceId,
    'subtotal' => $subtotal,
    'shippingFee' => $shippingFee,
    'total' => $finalTotal,
]);