<?php
/**
 * BLOOMINOUS - Record Email Risk Score (post-signup)
 *
 * firestore.rules blocks clients from setting fraudScore/fraudFlags on
 * their own `customers` doc, even at create time — so register.php calls
 * this right after account creation to persist the IPQS-based score bump
 * check_email_risk.php computed, via the Admin SDK.
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

$scoreBump = (int) ($_POST['scoreBump'] ?? 0);
// Clamp server-side — never trust the client's number beyond a sane ceiling,
// even though it only echoes what check_email_risk.php told it moments ago.
$scoreBump = max(0, min(30, $scoreBump));

if ($scoreBump <= 0) {
    bloom_json_response(['success' => true, 'message' => 'Nothing to record.']);
}

$db = bloom_firestore();
$customerRef = $db->collection('customers')->document($uid);
$snap = $customerRef->snapshot();

if (!$snap->exists()) {
    bloom_json_response(['success' => false, 'message' => 'Customer profile not found.'], 404);
}

$current = (int) ($snap->data()['fraudScore'] ?? 0);
$newScore = min(100, $current + $scoreBump);

$customerRef->update([
    ['path' => 'fraudScore', 'value' => $newScore],
    ['path' => 'fraudFlags', 'value' => FieldValue::arrayUnion(['Elevated email risk at signup (IPQS)'])],
]);

bloom_json_response(['success' => true]);