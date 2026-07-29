<?php
/**
 * BLOOMINOUS - New Device Email Step-Up (verify code)
 *
 * On success, adds the device hash to customers/{uid}.deviceHashes so it's
 * recognized next time, and returns success so the client can retry
 * includes/set_session.php with deviceOtpVerified=true. Does NOT touch
 * fraudScore / isRestricted — recognizing a device is a separate concern
 * from the account's underlying fraud trust level.
 */

require_once __DIR__ . '/includes/firebase_admin.php';

use Google\Cloud\Firestore\FieldValue;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$idToken = bloom_get_bearer_token() ?? ($_POST['idToken'] ?? null);
if (!$idToken) {
    bloom_json_response(['success' => false, 'message' => 'Missing ID token'], 401);
}

try {
    $uid = bloom_verify_id_token($idToken);
} catch (\Throwable $e) {
    bloom_json_response(['success' => false, 'message' => 'Invalid or expired session.'], 401);
}

$code = trim((string) ($_POST['code'] ?? ''));
$deviceHash = (string) ($_POST['deviceHash'] ?? '');

if (!preg_match('/^\d{6}$/', $code)) {
    bloom_json_response(['success' => false, 'message' => 'Invalid code format.'], 400);
}
if ($deviceHash === '' || !preg_match('/^[a-f0-9]{64}$/', $deviceHash)) {
    bloom_json_response(['success' => false, 'message' => 'Missing or malformed device signature.'], 400);
}

try {
    $userRecord = bloom_auth()->getUser($uid);
} catch (\Throwable $e) {
    bloom_json_response(['success' => false, 'message' => 'Account not found.'], 404);
}
$email = $userRecord->email ?? null;
if (!$email) {
    bloom_json_response(['success' => false, 'message' => 'No email on file for this account.'], 400);
}

$db = bloom_firestore();
$otpRef = $db->collection('customer_otps')->document(strtolower($email));
$otpSnap = $otpRef->snapshot();

if (!$otpSnap->exists()) {
    bloom_json_response(['success' => false, 'message' => 'No pending verification for this account.'], 404);
}

$otpData = $otpSnap->data();

if (($otpData['uid'] ?? null) !== $uid || ($otpData['purpose'] ?? null) !== 'device_verification') {
    bloom_json_response(['success' => false, 'message' => 'No pending verification for this account.'], 404);
}

$expiresAt = $otpData['expiresAt'] ?? null;
$isExpired = true;
if ($expiresAt instanceof \Google\Cloud\Core\Timestamp) {
    $isExpired = $expiresAt->get() < new DateTimeImmutable('now');
}
if ($isExpired) {
    bloom_json_response(['success' => false, 'message' => 'Code expired. Request a new one.'], 410);
}

if (!hash_equals((string) $otpData['code'], $code) || ($otpData['deviceHash'] ?? null) !== $deviceHash) {
    bloom_json_response(['success' => false, 'message' => 'Incorrect code.'], 403);
}

$customerRef = $db->collection('customers')->document($uid);
$customerRef->update([
    ['path' => 'deviceHashes', 'value' => FieldValue::arrayUnion([$deviceHash])],
]);

$otpRef->delete();

bloom_json_response(['success' => true, 'message' => 'Device confirmed.']);