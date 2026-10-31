<?php
/**
 * BLOOMINOUS - Cancellation Override Verification
 *
 * Single-use, admin-generated codes (8 per batch, Google-authenticator-
 * backup-code style) required for an employee's 4th+ order cancellation of
 * the day at a given branch (walk-in AND online orders). Codes are stored
 * in `override_codes`, a collection firestore.rules restricts to isAdmin()
 * only - this endpoint is the ONLY way a code can ever be checked or burned
 * by anyone who isn't an admin, using the service account to bypass that
 * rule server-side.
 *
 * WHY THIS FILE USES THE REST HELPERS (firestore_rest.php):
 * the previous version called bloom_firestore() (the gRPC Firestore client).
 * This hosting's grpc extension is unreliable (see the note in
 * includes/firebase_admin.php), so every request ended in the generic
 * "Server error verifying code." even for a correct code. The REST helpers
 * are what the rest of the shop (submit_order.php, fraud_appeal.php, ...)
 * already uses successfully.
 *
 * Every successful override is also logged to `admin_actions`, so a
 * manager reviewing the Admin Activity Log sees exactly which employee used
 * which code to cancel which order.
 *
 * Request (POST, JSON, Authorization: Bearer <Firebase ID token>):
 *   { "branchId": "...", "code": "A1B2C3D4", "orderId": "..." }
 * Response:
 *   { success: true,  valid: true }                      code accepted AND burned
 *   { success: true,  valid: false, message: "..." }     wrong / used / other branch
 *   { success: false, message: "..." }                   server problem
 */

require_once __DIR__ . '/includes/firebase_admin.php';
require_once __DIR__ . '/includes/firestore_rest.php';

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

// Confirm the caller is genuinely staff - same lookup pattern as
// set_session.php, so a customer account can never reach this endpoint
// even if they somehow obtained a valid ID token session.
try {
    $userData = bloom_firestore_get_document_rest('users', $uid);
} catch (\Throwable $e) {
    error_log('verify_override_code.php: user lookup failed: ' . $e->getMessage());
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
    // An employee may only use a code made for THEIR OWN branch. Their
    // branch can sit on users/{uid} or on employees/{uid}; check both. If no
    // branch is recorded anywhere, this check is skipped (the code's own
    // branchId below still has to match the order's branch).
    if (in_array($role, ['staff', 'employee'], true)) {
        $myBranch = trim((string) ($userData['branchId'] ?? ''));
        if ($myBranch === '') {
            try {
                $employeeData = bloom_firestore_get_document_rest('employees', $uid);
                $myBranch = trim((string) ($employeeData['branchId'] ?? ''));
            } catch (\Throwable $e) {
                $myBranch = '';
            }
        }
        if ($myBranch !== '' && $myBranch !== $branchId) {
            bloom_json_response([
                'success' => true,
                'valid' => false,
                'message' => 'This override code is not for your branch.',
            ]);
        }
    }

    // Find the code. The REST helper filters on ONE field, so it looks the
    // code up and the branch / unused checks are done right here.
    $matches = bloom_firestore_query_rest('override_codes', 'code', $code);
    $candidate = null;
    foreach ($matches as $row) {
        $data = $row['data'];
        if (($data['branchId'] ?? '') === $branchId && ($data['used'] ?? false) === false) {
            $candidate = $row;
            break;
        }
    }

    if ($candidate === null) {
        bloom_json_response([
            'success' => true,
            'valid' => false,
            'message' => 'Invalid or already-used override code.',
        ]);
    }

    // Burn it inside a transaction: if two employees type the same code at
    // the same moment, only ONE of them gets to flip used=false -> true.
    $codeDocId = (string) $candidate['id'];
    $burned = bloom_firestore_transaction_rest(function ($tx) use ($codeDocId, $uid, $orderId) {
        $fresh = $tx->get('override_codes', $codeDocId);
        if ($fresh === null || ($fresh['used'] ?? false) !== false) {
            return false; // somebody else used it a moment ago
        }
        $tx->update('override_codes', $codeDocId, [
            'used' => true,
            'usedAt' => bloom_rest_now(),
            'usedBy' => $uid,
            'usedForOrderId' => $orderId,
        ]);
        return true;
    });

    if (!$burned) {
        bloom_json_response([
            'success' => true,
            'valid' => false,
            'message' => 'Invalid or already-used override code.',
        ]);
    }

    // Best-effort audit entry - matches admin_actions' schema exactly. The
    // code is already burned at this point, so a failed log write must never
    // turn into an error for the employee.
    try {
        bloom_firestore_add_document_rest('admin_actions', [
            'actorUid' => $uid,
            'actorEmail' => (string) ($userData['email'] ?? ''),
            'actorRole' => (string) $role,
            'action' => 'walkin_cancel_override',
            'targetUid' => $orderId,
            'targetEmail' => null,
            'details' => "Used override code to cancel order $orderId at branch $branchId (code now permanently burned).",
            'timestamp' => bloom_rest_now(),
        ]);
    } catch (\Throwable $e) {
        error_log('verify_override_code.php: audit log write failed: ' . $e->getMessage());
    }

    bloom_json_response(['success' => true, 'valid' => true]);
} catch (\Throwable $e) {
    error_log('verify_override_code.php failed: ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'Server error verifying code.'], 500);
}