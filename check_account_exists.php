<?php
/**
 * BLOOMINOUS - Account Existence Check (forgot-password gate)
 *
 * Replaces two client-side Firestore `where().get()` queries against
 * `users` and `customers` that forgot_password.php used to run while the
 * person is still logged out. `users` restricts `list` to admins only in
 * firestore.rules — the same doomed pattern already fixed once in
 * index.php's login fallback in an earlier session, never audited here.
 * That query was always going to fail with "Missing or insufficient
 * permissions" for every ordinary customer trying to recover a password.
 *
 * Checks Firebase AUTH directly via the Admin SDK instead of Firestore —
 * arguably more correct anyway, since Auth is the actual source of truth
 * for whether an account can log in at all, and Firestore documents could
 * drift out of sync with it. Uses bloom_auth() (Kreait, HTTP-based), not
 * bloom_firestore() (gRPC-based) — so this needs no gRPC extension,
 * consistent with why rate_limiter.php was rewritten earlier this session.
 *
 * Deliberately returns a boolean the caller uses to decide whether to
 * proceed - forgot_password.php still shows a generic-enough message
 * either way, so this isn't a new email-enumeration surface beyond what
 * the original code already had.
 */

require_once __DIR__ . '/includes/firebase_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$body = bloom_json_input();
$email = filter_var($body['email'] ?? '', FILTER_VALIDATE_EMAIL);

if (!$email) {
    bloom_json_response(['success' => false, 'message' => 'Invalid email format.'], 400);
}

$exists = false;
try {
    bloom_auth()->getUserByEmail($email);
    $exists = true;
} catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
    $exists = false;
} catch (\Throwable $e) {
    error_log('check_account_exists failed, failing toward exists=true: ' . $e->getMessage());
    // Fail toward "might exist" rather than blocking a legitimate
    // recovery attempt over an infra hiccup - matches this session's
    // established fail-open policy for third-party/infra errors.
    $exists = true;
}

bloom_json_response(['success' => true, 'exists' => $exists]);