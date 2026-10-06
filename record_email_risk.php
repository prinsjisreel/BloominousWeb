<?php
/**
 * BLOOMINOUS - Record Email Risk (post-signup)
 *
 * The signup page calls this right after account creation when
 * check_email_risk.php answered flag: true. firestore.rules blocks
 * clients from writing fraud fields on their own `customers` doc, so the
 * server records it here.
 *
 * FRAUD ACTIVITY MODEL: this no longer adds points to a fraud score. It
 * records ONE fraud activity, "Elevated email risk at signup", under
 * fake_transaction (throwaway accounts created to place bogus orders),
 * the same way submit_order.php records activities at checkout, and
 * raises an admin notification.
 *
 * CONTRACT (unchanged): clients still POST idToken + scoreBump.
 * scoreBump is now read only as yes/no (> 0 means "flagged"), so the web
 * signup page and the app need no changes.
 *
 * REST MIGRATION: uses the REST helpers instead of bloom_firestore()
 * (gRPC), which crashes PHP on the local XAMPP install.
 */

require_once __DIR__ . '/includes/firebase_admin.php';
require_once __DIR__ . '/includes/firestore_rest.php';
require_once __DIR__ . '/includes/fraud_activity.php';

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

// Only "was it flagged or not" matters now; the number itself is ignored.
$wasFlagged = (int) ($_POST['scoreBump'] ?? 0) > 0;

if (!$wasFlagged) {
    bloom_json_response(['success' => true, 'message' => 'Nothing to record.']);
}

try {
    $customer = bloom_firestore_get_document_rest('customers', $uid);
} catch (\Throwable $e) {
    error_log('record_email_risk.php: could not read customer ' . $uid . ': ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'Could not record signup check right now.'], 503);
}

if ($customer === null) {
    bloom_json_response(['success' => false, 'message' => 'Customer profile not found.'], 404);
}

$now = bloom_rest_now();
$activity = bloom_fraud_activity('fake_transaction', 'email_risk_signup', 'Elevated email risk at signup');
$customerUpdate = bloom_build_fraud_customer_update($customer, [$activity], $now);

try {
    bloom_firestore_update_fields_rest('customers', $uid, $customerUpdate);

    bloom_firestore_add_document_rest('notifications', [
        'title' => 'Fraud Activity Recorded',
        'message' => "New account [$uid] was flagged at signup: " . BLOOM_FRAUD_CATEGORIES['fake_transaction'] . ' (elevated email risk). Review it in the Fraud Activity Log.',
        'type' => 'fraud',
        'branchId' => 'main_branch',
        'created_at' => $now,
        'read' => false,
    ]);
} catch (\Throwable $e) {
    error_log('record_email_risk.php: could not record activity for ' . $uid . ': ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'Could not record signup check right now.'], 503);
}

bloom_json_response(['success' => true]);