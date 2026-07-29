<?php
/**
 * BLOOMINOUS - Restore Account Trust (post phone verification)
 *
 * Called right after templates/checkout.php links a verified phone number
 * to the signed-in account (currentUser.linkWithPhoneNumber(...).confirm()).
 * The server re-checks Firebase Auth itself for a linked phone before
 * touching fraudScore — it does not trust a client-supplied "verified" flag.
 */

require_once __DIR__ . '/includes/firebase_admin.php';

use Google\Cloud\Firestore\FieldValue;

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
    bloom_json_response(['success' => false, 'message' => 'Invalid or expired session.'], 401);
}

try {
    $userRecord = bloom_auth()->getUser($uid);
} catch (\Throwable $e) {
    bloom_json_response(['success' => false, 'message' => 'Account not found.'], 404);
}

if (empty($userRecord->phoneNumber)) {
    bloom_json_response(['success' => false, 'message' => 'No verified phone number found on this account.'], 403);
}

$db = bloom_firestore();
$customerRef = $db->collection('customers')->document($uid);

$customerRef->update([
    ['path' => 'fraudScore', 'value' => 10],
    ['path' => 'isRestricted', 'value' => false],
    ['path' => 'fraudFlags', 'value' => FieldValue::arrayUnion(['Identity verified via SMS - Trust Restored'])],
]);

bloom_json_response(['success' => true]);