<?php
/**
 * BLOOMINOUS - Restore Account Trust (post phone verification)
 *
 * Called right after templates/checkout.php links a verified phone number
 * to the signed-in account (currentUser.linkWithPhoneNumber(...).confirm()).
 * The server re-checks Firebase Auth itself for a linked phone before
 * touching fraudScore — it does not trust a client-supplied "verified" flag.
 *
 * RESTRICTED ACCOUNTS CAN NO LONGER USE THIS TO GET FREE.
 * Before the appeal flow, verifying a phone number lifted a restriction
 * right here. That would now be a back door around the appeal, so while a
 * restriction is ACTIVE this file refuses (code RESTRICTED_APPEAL_ONLY) and
 * changes nothing. The only ways out of a restriction are:
 *   - an admin approves the customer's appeal (fraud_appeal.php), or
 *   - the 30 days run out (handled automatically by submit_order.php).
 * For accounts that are NOT restricted this file behaves exactly as before.
 */

require_once __DIR__ . '/includes/firebase_admin.php';
// bloom_restriction_state() lives here (single source of the 30-day rule).
require_once __DIR__ . '/includes/fraud_activity.php';

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

// Is this account under an ACTIVE restriction? Read with the same REST
// helper submit_order.php uses, so both files always agree.
$customer = bloom_firestore_get_document_rest('customers', $uid);
if ($customer === null) {
    bloom_json_response(['success' => false, 'message' => 'Customer profile not found.'], 404);
}

if (bloom_restriction_state($customer)['active']) {
    bloom_json_response([
        'success' => false,
        'code' => 'RESTRICTED_APPEAL_ONLY',
        'message' => 'Your account is restricted. Verifying your phone number does not lift it. Please send an appeal instead.',
        'canAppeal' => true,
    ], 403);
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