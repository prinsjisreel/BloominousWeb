<?php
/**
 * BLOOMINOUS - Walk-in Cancellation Override Verification
 *
 * Single-use, admin-generated codes (8 per batch, Google-authenticator-
 * backup-code style) required for an employee's 4th+ walk-in
 * cancellation of the day at a given branch. Codes are stored in
 * `override_codes`, a collection firestore.rules restricts to
 * isAdmin() only — this endpoint is the ONLY way a code can ever be
 * checked or burned by anyone who isn't an admin, using the Admin SDK
 * to bypass that rule server-side.
 *
 * Every successful override is also logged to `admin_actions`, so a
 * manager reviewing the Admin Activity Log sees exactly which employee
 * used which code to cancel which order.
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

// Confirm the caller is genuinely staff — same lookup pattern as
// set_session.php, so a customer account can never reach this endpoint
// even if they somehow obtained a valid ID token session.
try {
    $userData = bloom_firestore_get_document_rest('users', $uid);
} catch (\Throwable $e) {
    bloom_json_response(['success' => false, 'message' => 'Could not verify staff status.'], 503);
}

$role = $userData['role'] ?? null;
if (!in_array($role, ['admin', 'super-admin', 'staff', 'employee'], true)) {
    bloom_json_response(['success' => false, 'message' => 'Not authorized.'], 403);
}

$body = bloom_json_input();
$branchId = trim((string) ($body['branchId'] ?? ''));
$code = strtoupper(trim((string) ($body['code'] ?? '')));
$orderId = trim((string) ($body['orderId'] ?? ''));

if ($branchId === '' || $code === '' || $orderId === '') {
    bloom_json_response(['success' => false, 'message' => 'Missing required fields.'], 400);
}

try {
    $db = bloom_firestore();
    $query = $db->collection('override_codes')
        ->where('branchId', '=', $branchId)
        ->where('code', '=', $code)
        ->where('used', '=', false)
        ->limit(1);

    $docs = iterator_to_array($query->documents());

    if (empty($docs)) {
        bloom_json_response([
            'success' => true,
            'valid' => false,
            'message' => 'Invalid or already-used override code.',
        ]);
    }

    $codeDoc = $docs[0];
    $codeDoc->reference()->update([
        ['path' => 'used', 'value' => true],
        ['path' => 'usedAt', 'value' => FieldValue::serverTimestamp()],
        ['path' => 'usedBy', 'value' => $uid],
        ['path' => 'usedForOrderId', 'value' => $orderId],
    ]);

    // Best-effort audit entry — matches admin_actions' schema exactly
    // (actorUid/actorRole/action/targetUid), even though this write
    // comes from the Admin SDK and bypasses the client-write rule
    // entirely, since a server write is inherently trusted.
    try {
        $employeeEmail = $userData['email'] ?? '';
        $db->collection('admin_actions')->add([
            'actorUid' => $uid,
            'actorEmail' => $employeeEmail,
            'actorRole' => $role,
            'action' => 'walkin_cancel_override',
            'targetUid' => $orderId,
            'targetEmail' => null,
            'details' => "Used override code to cancel walk-in order at branch $branchId (code now permanently burned).",
            'timestamp' => FieldValue::serverTimestamp(),
        ]);
    } catch (\Throwable $e) {
        error_log('verify_override_code.php: audit log write failed: ' . $e->getMessage());
    }

    bloom_json_response(['success' => true, 'valid' => true]);
} catch (\Throwable $e) {
    error_log('verify_override_code.php failed: ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'Server error verifying code.'], 500);
}