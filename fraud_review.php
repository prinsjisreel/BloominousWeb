<?php
/**
 * BLOOMINOUS - Fraud Review (admin-only, server-side)
 *
 * The ONE place where an admin's fraud decision is made, used by both
 * fraud_analytics.php (web) and fraud_analytics_page.dart (app, through
 * fraud_review_service.dart).
 *
 * POST JSON, with "Authorization: Bearer <Firebase ID token>":
 *
 *   { "action": "history", "uid": "<customer uid>" }
 *     -> the customer's transaction history (computed here, on the
 *        server) + the last recorded review, if any.
 *
 *   { "action": "decide", "uid": "<customer uid>",
 *     "decision": "confirmed_fraud" | "false_alarm",
 *     "reason": "<at least 15 characters>",
 *     "seenTotalOrders": <int>, "seenFlaggedOrders": <int> }
 *     -> records the decision, in ONE transaction:
 *        confirmed_fraud: account blacklisted (status 'blocked') and
 *                         every device linked to it banned.
 *        false_alarm:     any restriction lifted, and this customer is
 *                         allow-listed on banned devices linked to them
 *                         (the device stays banned for everyone else,
 *                         see section 3c of submit_order.php). Nothing
 *                         else changes for the customer.
 *
 * WHY THE SAFEGUARDS (nothing is decided instantly or blindly):
 *   - Admins only, checked HERE from users/{uid}.role, not trusted from
 *     the client.
 *   - A written reason is required (kept in admin_actions).
 *   - Confirming fraud requires recorded evidence (activity on the
 *     account or a flagged order). No evidence, no blacklist.
 *   - The admin must have seen the CURRENT history: the client sends the
 *     order counts it displayed. If new orders arrived since, the request
 *     is refused (HISTORY_CHANGED) and the admin must review again.
 *
 * ONE RESPONSE PER REQUEST: every reply goes through
 * bloom_review_respond(), which always stops the script afterwards. A
 * second JSON object after the first would make the reply unreadable.
 *
 * Response codes: RATE_LIMITED, FORBIDDEN, INVALID_REQUEST, NOT_FOUND,
 * INVALID_DECISION, REASON_REQUIRED, HISTORY_CHANGED, NO_EVIDENCE,
 * ALREADY_BLOCKED.
 *
 * All Firestore calls use the REST helpers (no gRPC), same as
 * submit_order.php.
 */

require_once __DIR__ . '/includes/firestore_rest.php';
require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/fraud_activity.php';

const BLOOM_REVIEW_LIMIT_WINDOW_SECONDS = 600;
const BLOOM_REVIEW_LIMIT_PER_IP = 120;
const BLOOM_REVIEW_LIMIT_PER_ADMIN = 120;
const BLOOM_REVIEW_REASON_MIN_CHARS = 15;
const BLOOM_REVIEW_REASON_MAX_CHARS = 500;
const BLOOM_REVIEW_MAX_DEVICES = 20;
const BLOOM_REVIEW_DECISIONS = ['confirmed_fraud', 'false_alarm'];
const BLOOM_REVIEW_ADMIN_ROLES = ['admin', 'super-admin'];

// Account-history notes added to fraudFlags. Their wording is classified
// by both dashboards as "Account Action" (history, not fraud activity):
// 'blacklisted', 'reviewed by admin' and 'restriction' are keywords there.
const BLOOM_REVIEW_FLAG_CONFIRMED = 'Account blacklisted by admin after review (confirmed fraud)';
const BLOOM_REVIEW_FLAG_FALSE_ALARM = 'Reviewed by admin: false alarm';
const BLOOM_REVIEW_FLAG_RESTRICTION_LIFTED = 'Restriction lifted by admin after review (false alarm)';

/**
 * Sends ONE JSON reply and stops the script, no matter whether the
 * shared bloom_json_response() exits on its own.
 */
function bloom_review_respond(array $payload, int $status = 200): void
{
    bloom_json_response($payload, $status);
    exit;
}

/** Sends an error reply and stops. */
function bloom_review_fail(string $code, string $message, int $status): void
{
    bloom_review_respond(['success' => false, 'code' => $code, 'message' => $message], $status);
}

set_exception_handler(function (\Throwable $e) {
    error_log('fraud_review.php failed: ' . $e->getMessage());
    bloom_review_respond(['success' => false, 'message' => 'The review could not be completed right now. Please try again.'], 500);
});

/** Rate-limit wrapper that fails open, same pattern as submit_order.php. */
function bloom_review_rate_limit_allows(string $bucket, string $key, int $max, int $windowSeconds): bool
{
    try {
        return bloom_check_and_record_attempt($bucket, $key, $max, $windowSeconds);
    } catch (\Throwable $e) {
        error_log("fraud_review.php: rate limiter failed for {$bucket}, failing open: " . $e->getMessage());
        return true;
    }
}

/** Text length that also counts letters like "ñ" as one character. */
function bloom_review_text_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

/** The stored review, made JSON-friendly (timestamps -> ISO text). */
function bloom_review_for_json($review): ?array
{
    if (!is_array($review)) {
        return null;
    }
    $clean = $review;
    if (($clean['reviewedAt'] ?? null) instanceof DateTimeInterface) {
        $clean['reviewedAt'] = $clean['reviewedAt']->format(DATE_ATOM);
    }
    return $clean;
}

/** Every valid device hash linked to this customer (profile + orders). */
function bloom_review_collect_device_hashes(array $customer, array $orderRows): array
{
    $hashes = [];
    $candidates = is_array($customer['deviceHashes'] ?? null) ? $customer['deviceHashes'] : [];
    foreach ($orderRows as $row) {
        $candidates[] = $row['data']['deviceHash'] ?? null;
    }
    foreach ($candidates as $hash) {
        if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash)) {
            $hashes[] = $hash;
        }
    }
    return array_slice(array_values(array_unique($hashes)), 0, BLOOM_REVIEW_MAX_DEVICES);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_review_fail('INVALID_REQUEST', 'Method not allowed', 405);
}

// --- 1. Per-IP limit, before any token check ---
if (!bloom_review_rate_limit_allows('fraud_review_ip', bloom_get_client_ip(), BLOOM_REVIEW_LIMIT_PER_IP, BLOOM_REVIEW_LIMIT_WINDOW_SECONDS)) {
    bloom_review_fail('RATE_LIMITED', 'Too many requests. Please wait a few minutes.', 429);
}

// --- 2. Who is asking? Verified token -> uid -> role from users/{uid} ---
$idToken = bloom_get_bearer_token();
if (!$idToken) {
    bloom_review_fail('FORBIDDEN', 'Missing Authorization token', 401);
}

try {
    $adminUid = bloom_verify_id_token($idToken);
} catch (\Throwable $e) {
    bloom_review_fail('FORBIDDEN', 'Invalid or expired session. Please sign in again.', 401);
}

$adminUser = bloom_firestore_get_document_rest('users', $adminUid);
$adminRole = (string) ($adminUser['role'] ?? '');
if (!in_array($adminRole, BLOOM_REVIEW_ADMIN_ROLES, true)) {
    bloom_review_fail('FORBIDDEN', 'Only admins can review fraud activity.', 403);
}
$adminEmail = (string) ($adminUser['email'] ?? '');

if (!bloom_review_rate_limit_allows('fraud_review_uid', $adminUid, BLOOM_REVIEW_LIMIT_PER_ADMIN, BLOOM_REVIEW_LIMIT_WINDOW_SECONDS)) {
    bloom_review_fail('RATE_LIMITED', 'Too many requests. Please wait a few minutes.', 429);
}

// --- 3. Which customer? ---
$body = bloom_json_input();
$action = (string) ($body['action'] ?? '');
$targetUid = (string) ($body['uid'] ?? '');

if (!in_array($action, ['history', 'decide'], true) || !bloom_is_valid_doc_id($targetUid)) {
    bloom_review_fail('INVALID_REQUEST', 'Invalid review request.', 400);
}

$customer = bloom_firestore_get_document_rest('customers', $targetUid);
if ($customer === null) {
    bloom_review_fail('NOT_FOUND', 'Customer account not found.', 404);
}

// --- 4. Transaction history (always computed fresh, on the server) ---
$orderRows = bloom_firestore_query_rest('orders', 'user_id', $targetUid);

// Account age from Firebase Auth: the customer can't edit this value.
$accountCreatedAt = null;
try {
    $authRecord = bloom_auth()->getUser($targetUid);
    $created = $authRecord->metadata->createdAt ?? null;
    $accountCreatedAt = $created instanceof DateTimeInterface ? $created : null;
} catch (\Throwable $e) {
    error_log('fraud_review.php: could not read account creation time: ' . $e->getMessage());
}

$history = bloom_build_account_history($orderRows, $accountCreatedAt);

if ($action === 'history') {
    bloom_review_respond([
        'success' => true,
        'history' => $history,
        'review' => bloom_review_for_json($customer['fraudReview'] ?? null),
    ]);
}

// --- 5. Decision: validate everything BEFORE writing anything ---
$decision = (string) ($body['decision'] ?? '');
if (!in_array($decision, BLOOM_REVIEW_DECISIONS, true)) {
    bloom_review_fail('INVALID_DECISION', 'Unknown decision.', 400);
}

$reason = trim(strip_tags((string) ($body['reason'] ?? '')));
$reasonLength = bloom_review_text_length($reason);
if ($reasonLength < BLOOM_REVIEW_REASON_MIN_CHARS || $reasonLength > BLOOM_REVIEW_REASON_MAX_CHARS) {
    bloom_review_fail('REASON_REQUIRED', 'Please write a reason of ' . BLOOM_REVIEW_REASON_MIN_CHARS . ' to ' . BLOOM_REVIEW_REASON_MAX_CHARS . ' characters explaining your decision.', 400);
}

// The admin must have seen the CURRENT history.
$seenTotal = $body['seenTotalOrders'] ?? null;
$seenFlagged = $body['seenFlaggedOrders'] ?? null;
if (!is_int($seenTotal) || !is_int($seenFlagged)
    || $seenTotal !== $history['totalOrders']
    || $seenFlagged !== $history['flaggedOrders']) {
    bloom_review_fail('HISTORY_CHANGED', 'This customer\'s order history changed since you opened it. Please review the updated history before deciding.', 409);
}

if (($customer['status'] ?? null) === 'blocked') {
    bloom_review_fail('ALREADY_BLOCKED', 'This account is already blacklisted.', 409);
}

$accountCategories = bloom_clean_string_list($customer['fraudCategories'] ?? []);
$hasEvidence = count($accountCategories) > 0 || $history['flaggedOrders'] > 0;
if ($decision === 'confirmed_fraud' && !$hasEvidence) {
    bloom_review_fail('NO_EVIDENCE', 'There is no recorded fraud activity on this account to confirm.', 409);
}

// --- 6. Snapshot of what the admin was looking at ---
$now = bloom_rest_now();
$accountCodes = bloom_clean_string_list($customer['fraudCodes'] ?? []);
$riskLevelAtReview = in_array($customer['riskLevel'] ?? null, BLOOM_RISK_LEVELS, true)
    ? $customer['riskLevel']
    : bloom_fraud_risk_level($accountCategories, $accountCodes);

$review = [
    'decision' => $decision,
    'reason' => $reason,
    'reviewedBy' => $adminUid,
    'reviewedByRole' => $adminRole,
    'reviewedAt' => $now,
    'riskLevelAtReview' => $riskLevelAtReview,
    'categoriesAtReview' => $accountCategories,
    'historyProfileAtReview' => $history['historyProfile'],
    'totalOrdersAtReview' => $history['totalOrders'],
    'completedOrdersAtReview' => $history['completedOrders'],
    'flaggedOrdersAtReview' => $history['flaggedOrders'],
];
if (is_numeric($customer['riskScore'] ?? null)) {
    $review['riskScoreAtReview'] = (int) $customer['riskScore'];
}
if ($history['accountAgeDays'] !== null) {
    $review['accountAgeDaysAtReview'] = $history['accountAgeDays'];
}

$isConfirmed = ($decision === 'confirmed_fraud');
$deviceHashes = bloom_review_collect_device_hashes($customer, $orderRows);
$flagText = $isConfirmed ? BLOOM_REVIEW_FLAG_CONFIRMED : BLOOM_REVIEW_FLAG_FALSE_ALARM;

// --- 7. Write everything in ONE transaction: all of it, or none ---
$outcome = bloom_firestore_transaction_rest(function (BloomRestTransaction $tx) use ($targetUid, $review, $flagText, $isConfirmed, $deviceHashes, $adminUid, $now) {
    // Every READ first, then every WRITE (Firestore transaction rule).
    $fresh = $tx->get('customers', $targetUid);
    if ($fresh === null) {
        return ['status' => 'missing'];
    }
    if (($fresh['status'] ?? null) === 'blocked') {
        return ['status' => 'blocked'];
    }

    // False alarm: which of this customer's devices are on the ban list?
    $bannedDocs = [];
    if (!$isConfirmed) {
        foreach ($deviceHashes as $hash) {
            $bannedDoc = $tx->get('banned_devices', $hash);
            if ($bannedDoc !== null) {
                $bannedDocs[$hash] = $bannedDoc;
            }
        }
    }

    $notes = [$flagText];
    $update = ['fraudReview' => $review];
    $restrictionLifted = false;

    if ($isConfirmed) {
        $update['status'] = 'blocked';
    } elseif (($fresh['isRestricted'] ?? false) === true) {
        $update['isRestricted'] = false;
        $notes[] = BLOOM_REVIEW_FLAG_RESTRICTION_LIFTED;
        $restrictionLifted = true;
    }

    $update['fraudFlags'] = bloom_merge_unique(bloom_clean_string_list($fresh['fraudFlags'] ?? []), $notes);
    $tx->set('customers', $targetUid, $update, true);

    if ($isConfirmed) {
        foreach ($deviceHashes as $hash) {
            $tx->set('banned_devices', $hash, [
                'bannedUid' => $targetUid,
                'reason' => 'Banned after admin fraud review (confirmed fraud)',
                'bannedBy' => $adminUid,
                'source' => 'fraud_review',
                'bannedAt' => $now,
            ], true);
        }
    } else {
        // The device stays banned for everyone else; only this reviewed
        // customer may use it without triggering the hard-evidence rule.
        foreach ($bannedDocs as $hash => $bannedDoc) {
            $tx->set('banned_devices', $hash, [
                'allowedUids' => bloom_merge_unique(bloom_clean_string_list($bannedDoc['allowedUids'] ?? []), [$targetUid]),
            ], true);
        }
    }

    return [
        'status' => 'ok',
        'restrictionLifted' => $restrictionLifted,
        'allowListedDevices' => count($bannedDocs),
    ];
});

if (($outcome['status'] ?? '') === 'missing') {
    bloom_review_fail('NOT_FOUND', 'Customer account not found.', 404);
}
if (($outcome['status'] ?? '') === 'blocked') {
    bloom_review_fail('ALREADY_BLOCKED', 'This account is already blacklisted.', 409);
}

$restrictionLifted = (bool) ($outcome['restrictionLifted'] ?? false);
$allowListedDevices = (int) ($outcome['allowListedDevices'] ?? 0);
$bannedDeviceCount = $isConfirmed ? count($deviceHashes) : 0;

// --- 8. Audit trail (best-effort: the decision is already saved) ---
try {
    $details = $isConfirmed
        ? "Confirmed fraud after review: account blacklisted, {$bannedDeviceCount} device(s) banned."
        : 'Reviewed fraud activity: marked as false alarm.'
            . ($restrictionLifted ? ' Restriction lifted.' : '')
            . ($allowListedDevices > 0 ? " Customer allow-listed on {$allowListedDevices} banned device(s)." : '');

    bloom_firestore_add_document_rest('admin_actions', [
        'actorUid' => $adminUid,
        'actorEmail' => $adminEmail,
        'actorRole' => $adminRole,
        'action' => $isConfirmed ? 'fraud_review_confirmed' : 'fraud_review_false_alarm',
        'targetUid' => $targetUid,
        'targetEmail' => (string) ($customer['email'] ?? ''),
        'details' => $details,
        'reason' => $reason,
        'review' => $review,
        'bannedDeviceCount' => $bannedDeviceCount,
        'restrictionLifted' => $restrictionLifted,
        'allowListedDevices' => $allowListedDevices,
        'source' => 'fraud_review.php',
        'timestamp' => $now,
    ]);
} catch (\Throwable $e) {
    error_log('fraud_review.php: audit log write failed (decision already saved): ' . $e->getMessage());
}

bloom_review_respond([
    'success' => true,
    'decision' => $decision,
    'bannedDeviceCount' => $bannedDeviceCount,
    'restrictionLifted' => $restrictionLifted,
    'allowListedDevices' => $allowListedDevices,
    'review' => bloom_review_for_json($review),
]);