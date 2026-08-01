<?php
/**
 * BLOOMINOUS - Server-side Order Submission
 *
 * Replaces the client-side db.collection('orders').add(...) call in
 * templates/checkout.php. All fraud scoring (velocity + geo mismatch) is
 * computed here, against the Admin SDK's view of Firestore, and the
 * resulting fraudScore/isRestricted/fraudFlags are written here too.
 *
 * The browser can no longer set its own fraudScore, skip the velocity
 * check, or write straight to `orders`/`customers` — see firestore.rules,
 * which denies client `orders` create entirely and already denied client
 * writes to the fraud fields on `customers`.
 */

require_once __DIR__ . '/includes/firebase_admin.php';
require_once __DIR__ . '/includes/abstractapi_ip_client.php';

use Google\Cloud\Firestore\FieldValue;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
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

$body = bloom_json_input();

// Defense in depth: if the client claims a different user_id than its own
// verified token, something is wrong — never trust the body's user id alone.
if (isset($body['user_id']) && $body['user_id'] !== $uid) {
    bloom_json_response(['success' => false, 'message' => 'User mismatch'], 403);
}

$db = bloom_firestore();

$customerRef = $db->collection('customers')->document($uid);
$customerSnap = $customerRef->snapshot();

if (!$customerSnap->exists()) {
    bloom_json_response(['success' => false, 'message' => 'Customer profile not found'], 404);
}

$customer = $customerSnap->data();

if (($customer['status'] ?? null) === 'blocked') {
    bloom_json_response(['success' => false, 'message' => 'This account has been blocked.'], 403);
}

// --- 2. Restriction gate: mirrors the old client check, but server-trusted ---
$isRestricted = ($customer['isRestricted'] ?? false) === true;
$otpVerified = ($body['otpVerified'] ?? false) === true;

if ($isRestricted && !$otpVerified) {
    bloom_json_response([
        'success' => false,
        'code' => 'RESTRICTED',
        'message' => 'This account is currently restricted. Verify your phone number to continue.',
    ], 403);
}

// If the client claims OTP verification, confirm it against Auth itself —
// don't just take the flag's word for it. The phone must actually be linked
// to this account (see the linkWithPhoneNumber change in checkout.php).
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

// --- 3. Validate the minimum shape of the order payload ---
$required = ['name', 'phone', 'address', 'items', 'subtotal', 'shippingFee', 'paymentMethod', 'branchId'];
foreach ($required as $field) {
    if (!isset($body[$field]) || $body[$field] === '') {
        bloom_json_response(['success' => false, 'message' => "Missing field: $field"], 400);
    }
}

$items = $body['items'];
if (!is_array($items) || count($items) === 0) {
    bloom_json_response(['success' => false, 'message' => 'Cart is empty'], 400);
}

$subtotal = (float) $body['subtotal'];
$shippingFee = (float) $body['shippingFee'];
$isGift = ($body['isGift'] ?? false) === true;
$branchId = (string) $body['branchId'];
$customerLat = isset($body['customerLat']) && $body['customerLat'] !== '' ? (float) $body['customerLat'] : null;
$customerLng = isset($body['customerLng']) && $body['customerLng'] !== '' ? (float) $body['customerLng'] : null;

// Server-captured only — never trust an IP the client claims in the body.
// X-Forwarded-For may hold a chain (client, proxy1, proxy2...); the first
// entry is the original client IF your reverse proxy is trusted/configured
// to set it correctly. Treat this as a fraud SIGNAL, not a hard identity.
$forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$requestIp = $forwardedFor ? trim(explode(',', $forwardedFor)[0]) : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$deviceHash = isset($body['deviceHash']) && preg_match('/^[a-f0-9]{64}$/', $body['deviceHash']) ? $body['deviceHash'] : null;

// --- 3b. Banned device gate: a device tied to a prior auto-ban skips
// straight to restriction, independent of this account's own score ---
$accumulatedScoreBump = 10;
$localFraudFlags = [];
$triggerAutoRestriction = false;

if ($deviceHash !== null) {
    $bannedDeviceSnap = $db->collection('banned_devices')->document($deviceHash)->snapshot();
    if ($bannedDeviceSnap->exists()) {
        $accumulatedScoreBump += 60;
        $localFraudFlags[] = 'Order placed from a previously banned device';
        $triggerAutoRestriction = true;
    }
}

// --- 3c. AbstractAPI IP Intelligence: a signal, not a hard block.
// VPN/proxy usage alone is common and legitimate (weighted lightly, no
// auto-restriction); Tor or a flagged-abuse IP is treated as higher
// confidence risk. Relay/mobile are deliberately NOT penalized - relay
// covers privacy features like Apple's iCloud Private Relay used by
// many ordinary iPhone customers, and mobile is just "this is a phone
// carrier connection," both completely normal for real customers.
// Fails open (skips scoring) if unreachable/unconfigured — never block
// a checkout over a third-party vendor outage.
if ($requestIp !== 'unknown') {
    try {
        $ipResult = bloom_abstractapi_check_ip($requestIp);

        if ($ipResult['tor'] || $ipResult['abuse']) {
            $accumulatedScoreBump += 40;
            $localFraudFlags[] = 'High-risk IP reputation (Tor/abuse flagged)';
            $triggerAutoRestriction = true;
        } elseif ($ipResult['vpn'] || $ipResult['proxy']) {
            $accumulatedScoreBump += 15;
            $localFraudFlags[] = 'VPN/Proxy detected';
        }
    } catch (\Throwable $e) {
        error_log('bloom_abstractapi_check_ip failed, failing open: ' . $e->getMessage());
    }
}

// --- 4. Velocity check: any order from this uid in the last 5 minutes? ---

$fiveMinAgo = new DateTimeImmutable('-5 minutes');
$ordersQuery = $db->collection('orders')->where('user_id', '=', $uid)->documents();

$hasRecentVelocitySpam = false;
foreach ($ordersQuery as $orderDoc) {
    if (!$orderDoc->exists()) continue;
    $oData = $orderDoc->data();
    $ts = $oData['createdAt'] ?? $oData['timestamp'] ?? null;
    if ($ts instanceof \Google\Cloud\Core\Timestamp) {
        $orderTime = $ts->get();
        if ($orderTime >= $fiveMinAgo) {
            $hasRecentVelocitySpam = true;
            break;
        }
    }
}

if ($hasRecentVelocitySpam) {
    $accumulatedScoreBump += 35;
    $localFraudFlags[] = 'Rapid Separated Checkouts Flagged (< 5 min window)';
    $triggerAutoRestriction = true;
}

// --- 5. Geo mismatch check: device location vs assigned branch ---
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
    $branchSnap = $db->collection('branches')->document($branchId)->snapshot();
    if ($branchSnap->exists()) {
        $branchData = $branchSnap->data();
        $branchLat = $branchData['latitude'] ?? null;
        $branchLng = $branchData['longitude'] ?? null;
        if (is_numeric($branchLat) && is_numeric($branchLng)) {
            $distance = bloom_haversine_km((float) $branchLat, (float) $branchLng, $customerLat, $customerLng);
            if ($distance > 50) {
                $accumulatedScoreBump += 45;
                $localFraudFlags[] = 'Severe Device-to-Destination Mismatch';
            }
        }
    }
}

// --- 6. Apply the score to the customer profile (server is the only writer) ---
$checkAutoBan = false;
$baseScore = (int) ($customer['fraudScore'] ?? 0);
$ultimateScore = min(100, $baseScore + $accumulatedScoreBump);

$customerUpdate = ['fraudScore' => $ultimateScore];

if ($triggerAutoRestriction) {
    $expiry = new DateTimeImmutable('+30 days');
    $customerUpdate['isRestricted'] = true;
    $customerUpdate['restrictedUntil'] = $expiry;
    $localFraudFlags[] = 'Automated 30-Day Restriction: Rapid checkout loop velocity limit violated.';
} elseif ($otpVerified) {
    // Trust restored via verified phone — mirrors the old client-side reset,
    // now actually persisted since the server is allowed to write it.
    $customerUpdate['isRestricted'] = false;
    $customerUpdate['fraudScore'] = min($ultimateScore, 10);
    $ultimateScore = $customerUpdate['fraudScore'];
}

if ($ultimateScore >= 100) {
    $checkAutoBan = true;
}

if (!empty($localFraudFlags)) {
    $customerUpdate['fraudFlags'] = FieldValue::arrayUnion($localFraudFlags);
}

$customerRef->update(array_map(
    fn($key, $value) => ['path' => $key, 'value' => $value],
    array_keys($customerUpdate),
    array_values($customerUpdate)
));

if ($triggerAutoRestriction) {
    $db->collection('notifications')->add([
        'title' => 'Fraud Alert - Account Restricted',
        'message' => "Account [$uid] was soft-restricted automatically due to rapid checkout loops.",
        'type' => 'fraud',
        'branchId' => $branchId,
        'created_at' => FieldValue::serverTimestamp(),
        'read' => false,
    ]);

    // Velocity spam blocks the order outright — same behavior as before.
    bloom_json_response([
        'success' => false,
        'code' => 'RESTRICTED',
        'message' => 'Multiple checkouts detected in a short window. This account has been automatically restricted for 30 days. Verify your phone number to continue.',
    ], 403);
}

if ($checkAutoBan) {
    $customerRef->update([['path' => 'status', 'value' => 'blocked']]);
    $email = $body['email'] ?? null;
    if ($email) {
        $db->collection('blocked_emails')->document(strtolower($email))->set([
            'blockedUid' => $uid,
            'reason' => 'Automated mitigation framework lockout: Terminal limit reached.',
            'blockedAt' => FieldValue::serverTimestamp(),
        ]);
    }
    if ($deviceHash !== null) {
        $db->collection('banned_devices')->document($deviceHash)->set([
            'bannedUid' => $uid,
            'reason' => 'Automated mitigation framework lockout: Terminal limit reached.',
            'bannedAt' => FieldValue::serverTimestamp(),
        ]);
    }
    $db->collection('notifications')->add([
        'title' => 'Security Alert - Account Blocked',
        'message' => "Account associated with {$body['name']} reached peak fraud limits and has been blacklisted.",
        'type' => 'warning',
        'branchId' => $branchId,
        'created_at' => FieldValue::serverTimestamp(),
        'read' => false,
    ]);
    bloom_json_response(['success' => false, 'code' => 'BLOCKED', 'message' => 'This account has been blocked.'], 403);
}

// --- 7. Sequential invoice number, transaction-safe (ports header.php's JS logic) ---
$invoiceId = $db->runTransaction(function ($transaction) use ($db) {
    $counterRef = $db->collection('counters')->document('invoices');
    $counterSnap = $transaction->snapshot($counterRef);
    $year = (int) date('Y');
    $data = $counterSnap->exists() ? $counterSnap->data() : [];
    $nextNumber = (($data['year'] ?? null) === $year ? ($data['current'] ?? 0) : 0) + 1;
    $id = sprintf('INV-%d-%04d', $year, $nextNumber);
    $transaction->set($counterRef, ['current' => $nextNumber, 'year' => $year], ['merge' => true]);
    return $id;
});

// --- 8. Create the order (server-computed fraud fields only) ---
$finalTotal = $subtotal + $shippingFee;
$normalizedPhone = preg_replace('/[^0-9+]/', '', $body['phone']);

$orderRef = $db->collection('orders')->add([
    'user_id' => $uid,
    'invoiceId' => $invoiceId,
    'customer_name' => $body['name'],
    'customerName' => $body['name'],
    'recipientName' => $body['name'],
    'recipientPhone' => $body['phone'],
    'email' => $body['email'] ?? '',
    'address' => $body['address'],
    'phone' => $normalizedPhone,
    'payment_method' => $body['paymentMethod'],
    'items' => $items,
    'subtotal' => $subtotal,
    'shipping_fee' => $shippingFee,
    'total_price' => $finalTotal,
    'branchId' => $branchId,
    'status' => 'pending',
    'paymentStatus' => 'Pending',
    'locked' => false,
    'type' => 'WEB',
    'isGift' => $isGift,
    'fraudScore' => $accumulatedScoreBump,
    'fraudFlags' => $localFraudFlags,
    'requestIp' => $requestIp,
    'deviceHash' => $deviceHash,
    'timestamp' => FieldValue::serverTimestamp(),
    'createdAt' => FieldValue::serverTimestamp(),
]);

$db->collection('notifications')->add([
    'title' => 'New Web Order Placed',
    'message' => "Order {$invoiceId} valued at P" . number_format($finalTotal, 2) . " received from {$body['name']}.",
    'type' => 'sale',
    'branchId' => $branchId,
    'created_at' => FieldValue::serverTimestamp(),
    'read' => false,
]);

bloom_json_response([
    'success' => true,
    'orderId' => $orderRef->id(),
    'invoiceId' => $invoiceId,
]);