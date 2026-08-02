<?php
/**
 * BLOOMINOUS - Pre-Checkout Phone Risk Check (UX layer, not the security
 * boundary)
 *
 * Called by checkout.php's normalizePhone() flow BEFORE
 * startSmsVerification() fires — stops an obviously disposable/VOIP
 * number early, saving an SMS credit and giving the person instant
 * feedback instead of letting them go through the full OTP flow for
 * nothing.
 *
 * IMPORTANT: this is a convenience/cost-saving layer, NOT the real fraud
 * boundary. A scripted request could skip this file entirely and call
 * submit_order.php directly — that file has its own, unbypassable
 * version of this same check (see submit_order.php's "3d" section),
 * because every real order MUST pass through it regardless of what the
 * client does. Losing this file would only cost you SMS credits and a
 * worse UX, never actual fraud protection.
 *
 * Requires a valid Firebase ID token, since checkout only happens while
 * signed in — unlike register.php's pre-account check_email_risk.php.
 */

require_once __DIR__ . '/includes/firebase_admin.php';
require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/abstractapi_phone_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$idToken = bloom_get_bearer_token();
if (!$idToken) {
    bloom_json_response(['success' => false, 'message' => 'Missing Authorization token'], 401);
}

try {
    $uid = bloom_verify_id_token($idToken);
} catch (\Throwable $e) {
    bloom_json_response(['success' => false, 'message' => 'Invalid or expired session. Please sign in again.'], 401);
}

$clientIp = bloom_get_client_ip();

// Rate limit first, same reasoning as check_email_risk.php: cheap, local,
// stops raw flooding before any paid API call runs.
if (!bloom_check_and_record_attempt('phone_risk_check', $clientIp, 5, 600)) {
    bloom_json_response([
        'success' => true,
        'block' => true,
        'reason' => 'Too many attempts. Please wait a few minutes and try again.',
    ], 429);
}

$body = bloom_json_input();
$phone = trim((string) ($body['phone'] ?? ''));

if ($phone === '' || !preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
    bloom_json_response(['success' => false, 'message' => 'Invalid phone format.'], 400);
}

try {
    $result = bloom_abstractapi_check_phone($phone);
} catch (\Throwable $e) {
    error_log('bloom_abstractapi_check_phone failed, failing open: ' . $e->getMessage());
    bloom_json_response(['success' => true, 'block' => false]);
}

$block = $result['disposable'] || $result['voip'] || !$result['valid'];

$reason = null;
if ($result['disposable']) {
    $reason = 'This looks like a disposable/temporary phone number. Please use your real mobile number.';
} elseif ($result['voip']) {
    $reason = 'Virtual/VOIP numbers can\'t be used for delivery verification. Please use a real mobile number.';
} elseif (!$result['valid']) {
    $reason = 'This phone number doesn\'t appear to be valid. Please double-check it.';
}

bloom_json_response([
    'success' => true,
    'block' => $block,
    'reason' => $reason,
]);