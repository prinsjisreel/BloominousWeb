<?php
/**
 * BLOOMINOUS - Server-side Order Submission
 *
 * Replaces the client-side db.collection('orders').add(...) call in
 * templates/checkout.php. All fraud scoring (velocity + geo mismatch) is
 * computed here, against the Admin SDK's view of Firestore, and the
 * resulting fraudScore/isRestricted/fraudFlags/riskTier are written here too.
 *
 * The browser can no longer set its own fraudScore, skip the velocity
 * check, or write straight to `orders`/`customers` — see firestore.rules,
 * which denies client `orders` create entirely and already denied client
 * writes to the fraud fields on `customers`.
 *
 * --- RISK TIER MODEL (updated) ---
 * Every check below (device, IP, phone, address, velocity, geo) is now
 * PURELY ADDITIVE — it bumps the score and appends a human-readable flag,
 * nothing more. No single one of them can restrict an account by itself
 * anymore. Restriction is decided ONCE, in section 6, from two things only:
 *   (a) hard evidence — a device hash that matches a PRIOR confirmed ban.
 *       That's proof, not a guess, so it can restrict on its own.
 *   (b) the CUMULATIVE score crossing the Critical tier (90+), which by
 *       construction requires several independent soft signals to have
 *       actually converged — a single ambiguous action (like re-ordering
 *       twice in five minutes) can never get there alone.
 * Tiers: Low (0-30) silent log | Medium (31-60) dashboard flag only |
 * High (61-89) logged + watchlisted, order still completes | Critical
 * (90-100) restricted, phone verification required to lift.
 */

require_once __DIR__ . '/includes/firebase_admin.php';
require_once __DIR__ . '/includes/abstractapi_ip_client.php';
require_once __DIR__ . '/includes/abstractapi_phone_client.php';

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

// --- 2b. Email mail-server existence check — free (plain DNS, no API
// quota), gated to accounts that already carry some fraud score so it
// never adds latency to an ordinary customer's checkout. Blocks THIS
// order only, never the account — a temporary DNS/mail-server outage on
// a real domain is possible and shouldn't cost someone their account,
// just this one attempt (they can simply retry).
//
// Uses the same canary pattern as email_domain_policy.php: confirm OUR
// OWN DNS resolution is even working (via gmail.com) before trusting a
// failure result for the customer's domain — otherwise a local DNS
// hiccup on our end would incorrectly block every checkout at once,
// not just the ones that deserve it.
require_once __DIR__ . '/includes/email_domain_policy.php';

$customerBaseScore = (int) ($customer['fraudScore'] ?? 0);
if ($customerBaseScore > 0) {
    $customerEmail = $customer['email'] ?? null;
    if ($customerEmail) {
        $atPos = strrpos($customerEmail, '@');
        $emailDomain = $atPos !== false ? substr($customerEmail, $atPos + 1) : null;

        // bloom_domain_has_mail_server() already runs its own gmail.com
        // canary check internally and fails open (returns true) if OUR
        // OWN DNS looks broken — no need to duplicate that check here.
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
$normalizedPhone = preg_replace('/[^0-9+]/', '', $body['phone']);

// Server-captured only — never trust an IP the client claims in the body.
// X-Forwarded-For may hold a chain (client, proxy1, proxy2...); the first
// entry is the original client IF your reverse proxy is trusted/configured
// to set it correctly. Treat this as a fraud SIGNAL, not a hard identity.
$forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$requestIp = $forwardedFor ? trim(explode(',', $forwardedFor)[0]) : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$deviceHash = isset($body['deviceHash']) && preg_match('/^[a-f0-9]{64}$/', $body['deviceHash']) ? $body['deviceHash'] : null;

// --- 3b. Banned device gate: a device tied to a prior auto-ban is HARD
// EVIDENCE, not a behavioral guess — this exact browser/device already
// caused a confirmed fraud lockout once before. That's the one signal in
// this whole file allowed to restrict an account on its own; everything
// else below only adds to the score (see section 6 for why that matters).
$orderRiskScore = 10; // this order's OWN stored rating — always starts at 10
$customerScoreBump = 0; // what ADDS to the customer's cumulative score — starts at 0, only rises when something is actually found
$localFraudFlags = [];
$hasHardEvidence = false; // true only for PROOF (banned device), never for a suspicious pattern
$triggerAutoRestriction = false;

if ($deviceHash !== null) {
    $bannedDeviceSnap = $db->collection('banned_devices')->document($deviceHash)->snapshot();
    if ($bannedDeviceSnap->exists()) {
        $orderRiskScore += 90;
        $customerScoreBump += 90;
        $localFraudFlags[] = 'Order placed from a previously banned device';
        $hasHardEvidence = true;
    }
}

// --- 3c. AbstractAPI IP Intelligence: a signal, not a hard block.
// VPN/proxy usage alone is common and legitimate (weighted lightly);
// Tor or a flagged-abuse IP is weighted much higher, but — like every
// other soft signal in this file — it only feeds the cumulative score.
// It can no longer restrict an account on its own; it takes real
// convergence with something else to reach the Critical tier now.
// Relay/mobile are deliberately NOT penalized - relay covers privacy
// features like Apple's iCloud Private Relay used by many ordinary
// iPhone customers, and mobile is just "this is a phone carrier
// connection," both completely normal for real customers.
// Fails open (skips scoring) if unreachable/unconfigured — never block
// a checkout over a third-party vendor outage.
if ($requestIp !== 'unknown') {
    try {
        $ipResult = bloom_abstractapi_check_ip($requestIp);

        if ($ipResult['tor'] || $ipResult['abuse']) {
            $orderRiskScore += 40;
            $customerScoreBump += 40;
            $localFraudFlags[] = 'High-risk IP reputation (Tor/abuse flagged)';
        } elseif ($ipResult['vpn'] || $ipResult['proxy']) {
            $orderRiskScore += 15;
            $customerScoreBump += 15;
            $localFraudFlags[] = 'VPN/Proxy detected';
        }
    } catch (\Throwable $e) {
        error_log('bloom_abstractapi_check_ip failed, failing open: ' . $e->getMessage());
    }
}

// --- 3d. AbstractAPI Phone Validation + cross-account reuse — the REAL
// enforcement. check_phone_risk.php (called from checkout.php before SMS
// verification) is only a UX convenience; a scripted request could skip
// it entirely, so this section is what actually can't be bypassed —
// every order passes through here regardless of what the client did.
//
// Score-based only, same as every other soft signal here: a disposable/
// VOIP number is genuinely unusual for a flower delivery (someone has to
// be reachable to receive it), so it carries real weight — but weight
// alone, never an automatic restriction. It takes convergence with
// something else to actually lock the account.
//
// Cross-account reuse (same phone tied to a DIFFERENT uid already) is a
// meaningfully stronger signal than IP/device sharing, since phone
// numbers aren't naturally shared across strangers the way IPs are — but
// still not proof on its own (a family could legitimately share one
// phone across two accounts), so it adds real weight without an
// automatic restriction.
try {
    $phoneResult = bloom_abstractapi_check_phone($normalizedPhone);

    if ($phoneResult['disposable'] || $phoneResult['voip']) {
        $orderRiskScore += 35;
        $customerScoreBump += 35;
        $localFraudFlags[] = 'Disposable/VOIP phone number used at checkout';
    }
} catch (\Throwable $e) {
    error_log('bloom_abstractapi_check_phone failed, failing open: ' . $e->getMessage());
}

try {
    $phoneReuseQuery = $db->collection('orders')->where('phone', '=', $normalizedPhone)->documents();
    foreach ($phoneReuseQuery as $reuseDoc) {
        if (!$reuseDoc->exists()) continue;
        $reuseData = $reuseDoc->data();
        if (($reuseData['user_id'] ?? null) !== $uid) {
            $orderRiskScore += 25;
            $customerScoreBump += 25;
            $localFraudFlags[] = 'Phone number already associated with a different account';
            break;
        }
    }
} catch (\Throwable $e) {
    error_log('Phone reuse check failed, failing open: ' . $e->getMessage());
}

// --- 3e. Delivery address reuse — same identity-reuse pattern as phone,
// but targeting something a scammer with a genuinely fresh email, device,
// and SIM STILL can't easily rotate: where the flowers actually need to
// be delivered. A brand-new "clean" account is far less clean if it's
// shipping to an address that's already tied to a different, previously
// flagged customer.
//
// Lightly normalized (lowercase, trimmed, collapsed whitespace) so
// trivial formatting differences (extra spaces) don't cause a false
// "different address" miss — not a full address-parsing solution, just
// enough to catch the common case of someone reusing the literal same
// text across accounts.
$normalizedAddress = strtolower(trim(preg_replace('/\s+/', ' ', (string) $body['address'])));

try {
    $addressReuseQuery = $db->collection('orders')->where('normalizedAddress', '=', $normalizedAddress)->documents();
    foreach ($addressReuseQuery as $reuseDoc) {
        if (!$reuseDoc->exists()) continue;
        $reuseData = $reuseDoc->data();
        if (($reuseData['user_id'] ?? null) !== $uid) {
            $orderRiskScore += 20;
            $customerScoreBump += 20;
            $localFraudFlags[] = 'Delivery address already associated with a different account';
            break;
        }
    }
} catch (\Throwable $e) {
    error_log('Address reuse check failed, failing open: ' . $e->getMessage());
}

// --- 4. Velocity check: how many of this uid's past orders fall inside
// the last 5 minutes? This USED TO hard-restrict on any single repeat —
// that was the actual bug (see the "why this syntax" notes below). A real
// customer legitimately checks out twice in 5 minutes all the time:
// ordering for two different recipients, retrying after a declined
// payment, fixing a typo by just re-ordering. So velocity now behaves
// like every other signal in this file: it adds to the score, and gets
// louder the more it repeats, but it can no longer restrict anyone by
// itself — see section 6.
$fiveMinAgo = new DateTimeImmutable('-5 minutes');
$ordersQuery = $db->collection('orders')->where('user_id', '=', $uid)->documents();

$recentOrderCount = 0; // how many PAST orders fall inside the 5-minute window
$orderCount = 0;       // total past orders (any time) — used for isFirstOrder below
foreach ($ordersQuery as $orderDoc) {
    if (!$orderDoc->exists()) continue;
    $orderCount++;
    $oData = $orderDoc->data();
    $ts = $oData['createdAt'] ?? $oData['timestamp'] ?? null;
    if ($ts instanceof \Google\Cloud\Core\Timestamp && $ts->get() >= $fiveMinAgo) {
        $recentOrderCount++;
    }
}
$isFirstOrder = ($orderCount === 0);

if ($recentOrderCount === 1) {
    // This is the customer's 2nd order inside 5 minutes. Common, often
    // innocent — scored, not punished.
    $orderRiskScore += 25;
    $customerScoreBump += 25;
    $localFraudFlags[] = 'Repeat checkout within a 5-minute window';
} elseif ($recentOrderCount >= 2) {
    // 3rd+ order in the same short window starts to look automated rather
    // than accidental — scored higher, but STILL only feeds the
    // cumulative total in section 6, never restricts by itself here.
    $orderRiskScore += 40;
    $customerScoreBump += 40;
    $localFraudFlags[] = "Multiple rapid checkouts flagged ({$recentOrderCount} prior orders in under 5 minutes)";
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
                $orderRiskScore += 45;
                $customerScoreBump += 45;
                $localFraudFlags[] = 'Severe Device-to-Destination Mismatch';
            }
        }
    }
}

// --- 5b. Graduated trust: amplify (never originate) risk on a genuinely
// first-ever order. This targets the hardest case in fraud prevention —
// someone with a brand-new email, device, AND SIM, none of which have
// any history anywhere yet, so no reputation-based check above can catch
// them alone. Rather than inventing a new signal from nothing (which
// would risk punishing completely innocent new customers), this only
// AMPLIFIES risk that's already been found by another layer above —
// "first order" + "already flagged for something else" is meaningfully
// more suspicious than either fact alone, so it adds real extra weight,
// but a clean first order with zero other flags is untouched.
if ($isFirstOrder && $customerScoreBump > 0) {
    $orderRiskScore += 20;
    $customerScoreBump += 20;
    $localFraudFlags[] = 'First order combined with pre-existing risk signal(s)';
}

// --- 6. Apply the score to the customer profile, then classify the
// CUMULATIVE total into a risk tier. This single block is what actually
// decides whether anyone gets restricted — nothing above this point is
// allowed to make that call on its own anymore (except the hard-evidence
// banned-device match, which is proof rather than a guess).
//
// Tiers mirror the same Low/Medium/High/Critical vocabulary as the Sales
// Anomalies dashboard, so admins only ever learn one severity scale:
//   Low (0-30)      -> silent log, nothing else happens.
//   Medium (31-60)  -> still just logged, visible on the fraud dashboard.
//   High (61-89)    -> logged + watchlisted (riskTier stored), but the
//                      order still completes with no extra friction.
//   Critical (90+)  -> restricted for 30 days, phone OTP required to lift.
$checkAutoBan = false;
$baseScore = (int) ($customer['fraudScore'] ?? 0);
$ultimateScore = min(100, $baseScore + $customerScoreBump);

$tierLowMax = 30;
$tierMediumMax = 60;
$tierHighMax = 89;

$classifyTier = function (int $score) use ($tierLowMax, $tierMediumMax, $tierHighMax): string {
    if ($score <= $tierLowMax) return 'low';
    if ($score <= $tierMediumMax) return 'medium';
    if ($score <= $tierHighMax) return 'high';
    return 'critical';
};

$riskTier = $classifyTier($ultimateScore);

// Critical is reached one of two ways: hard evidence (the banned-device
// match above) or the cumulative score itself crossing the threshold
// because several independent soft signals genuinely converged. Either
// way, once we're here, restrict.
$triggerAutoRestriction = $hasHardEvidence || $riskTier === 'critical';

$customerUpdate = ['fraudScore' => $ultimateScore, 'riskTier' => $riskTier];

if ($triggerAutoRestriction) {
    $expiry = new DateTimeImmutable('+30 days');
    $customerUpdate['isRestricted'] = true;
    $customerUpdate['restrictedUntil'] = $expiry;
    $localFraudFlags[] = $hasHardEvidence
        ? 'Automated 30-Day Restriction: confirmed hard-evidence match (previously banned device).'
        : 'Automated 30-Day Restriction: multiple risk signals converged to a Critical score.';
} elseif ($otpVerified) {
    // Trust restored via verified phone — mirrors the old client-side reset,
    // now actually persisted since the server is allowed to write it.
    $customerUpdate['isRestricted'] = false;
    $customerUpdate['fraudScore'] = min($ultimateScore, 10);
    $ultimateScore = $customerUpdate['fraudScore'];
    $riskTier = $classifyTier($ultimateScore);
    $customerUpdate['riskTier'] = $riskTier;
} elseif ($customerScoreBump === 0 && $baseScore > 10) {
    // Reward good behavior: a checkout that raised zero new flags at all
    // (nothing from device/IP/phone/address/velocity/geo) is treated as
    // evidence the account isn't currently doing anything wrong — even
    // if it's still carrying an elevated score from something earlier.
    // Applies regardless of payment method, including COD.
    //
    // Floored at 10, NOT 0 — this matches the OTP-recovery reset above
    // (min($ultimateScore, 10)), which is this app's actual established
    // "clean baseline," not zero. Once an account works its way back
    // down to 10 through clean orders, it freezes there: the condition
    // ($baseScore > 10) stops applying entirely once the score reaches
    // that floor, so it never decays further and never dips below it.
    $decayedScore = max(10, $baseScore - 5);
    $customerUpdate['fraudScore'] = $decayedScore;
    $ultimateScore = $decayedScore;
    $riskTier = $classifyTier($ultimateScore);
    $customerUpdate['riskTier'] = $riskTier;
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
        'message' => $hasHardEvidence
            ? "Account [$uid] was restricted: order placed from a previously banned device."
            : "Account [$uid] was restricted: multiple risk signals converged to a Critical score.",
        'type' => 'fraud',
        'branchId' => $branchId,
        'created_at' => FieldValue::serverTimestamp(),
        'read' => false,
    ]);

    bloom_json_response([
        'success' => false,
        'code' => 'RESTRICTED',
        'message' => 'This order could not be completed. This account has been automatically restricted for 30 days. Verify your phone number to continue.',
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

$orderRef = $db->collection('orders')->add([
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
    'fraudScore' => $orderRiskScore,
    'fraudFlags' => $localFraudFlags,
    'riskTier' => $riskTier, // the account's tier immediately after this order — powers the Fraud Analytics click-through history
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