<?php
/**
 * BLOOMINOUS - Restriction Appeals
 *
 * A restricted customer cannot order with ANY payment method. The only way
 * back is to appeal, and an admin decides. This file is the one server-side
 * door for that whole flow (website AND mobile app call it):
 *
 *   CUSTOMER (signed in, restricted):
 *   { "action": "status" }
 *     -> is the account restricted, is there an appeal, can a new one be sent?
 *   { "action": "submit", "message": "<20 to 1000 characters>" }
 *     -> saves the appeal (fraud_appeals/{uid}) and notifies the super admin.
 *
 *   ADMIN / SUPER ADMIN (checked here from users/{uid}.role):
 *   { "action": "decide", "uid": "<customer uid>",
 *     "decision": "approve" | "deny",
 *     "reason": "<15 to 500 characters>",
 *     "seenAppealCount": <int> }
 *     -> approve: restriction lifted (+ the customer is allow-listed on their
 *                 banned devices so the same device does not instantly
 *                 re-restrict them, same as a false alarm in fraud_review.php)
 *                 AND the stored score drops to BLOOM_APPEAL_APPROVED_SCORE and
 *                 the account is marked "false alarm" (fraudReview), so Cash
 *                 on Delivery comes back too.
 *                 The HISTORY stays: fraudCodes, fraudCategories, fraudFlags
 *                 and every order's own fraud fields are not touched. The
 *                 score is always recalculated from fraudCodes when new fraud
 *                 activity is recorded (bloom_build_fraud_customer_update),
 *                 so if the customer does it again the score jumps back up to
 *                 what the WHOLE history adds up to, and COD goes off again.
 *        deny:    restriction stays; the customer may appeal again after
 *                 BLOOM_APPEAL_COOLDOWN_HOURS.
 *
 * WHY ONE DOCUMENT PER CUSTOMER (fraud_appeals/{uid}):
 *   The document id IS the customer's uid, so a customer can never have two
 *   open appeals: "one pending appeal" is enforced by the database layout and
 *   re-checked inside a transaction, not by trusting the browser.
 *   Older appeals are kept in admin_actions (the audit log) by every decision.
 *
 * WHO CAN READ THE APPEALS: admins read fraud_appeals straight from the
 * browser (firestore.rules); nobody can write it from a browser. Every write
 * goes through this file with the Admin SDK.
 *
 * Response codes: RATE_LIMITED, FORBIDDEN, INVALID_REQUEST, NOT_FOUND,
 * BLOCKED, NOT_RESTRICTED, MESSAGE_INVALID, ALREADY_PENDING, COOLDOWN,
 * NO_APPEAL, ALREADY_DECIDED, APPEAL_CHANGED, REASON_REQUIRED,
 * INVALID_DECISION.
 *
 * All Firestore calls use the REST helpers (no gRPC), same as
 * submit_order.php and fraud_review.php.
 */

require_once __DIR__ . '/includes/firestore_rest.php';
require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/fraud_activity.php';
// bloom_is_valid_doc_id() lives here (shared with submit_order.php).
require_once __DIR__ . '/includes/order_pricing.php';

const BLOOM_APPEALS_COLLECTION = 'fraud_appeals';

const BLOOM_APPEAL_LIMIT_WINDOW_SECONDS = 600;
const BLOOM_APPEAL_LIMIT_PER_IP = 30;
const BLOOM_APPEAL_LIMIT_PER_USER = 5;

const BLOOM_APPEAL_MESSAGE_MIN_CHARS = 20;
const BLOOM_APPEAL_MESSAGE_MAX_CHARS = 1000;
const BLOOM_APPEAL_REASON_MIN_CHARS = 15;
const BLOOM_APPEAL_REASON_MAX_CHARS = 500;

// After a DENIED appeal the customer waits this long before sending another.
// Stops someone from re-sending the same appeal all day, and gives the
// admin team a predictable workload.
const BLOOM_APPEAL_COOLDOWN_HOURS = 24;

const BLOOM_APPEAL_MAX_DEVICES = 20;

// After an APPROVED appeal the stored score drops to this clean baseline (or
// stays where it is if it was already lower). Same baseline the old phone
// verification used. The fraud HISTORY (codes, categories, flags) is NOT
// touched, see the approve block below.
const BLOOM_APPEAL_APPROVED_SCORE = 10;
const BLOOM_APPEAL_ADMIN_ROLES = ['admin', 'super-admin'];

// Notes added to fraudFlags. Both contain the word "restriction", which the
// fraud dashboards classify as an "Account Action" (history), not as a new
// fraud activity.
const BLOOM_APPEAL_FLAG_APPROVED = 'Restriction lifted by admin after appeal';
const BLOOM_APPEAL_FLAG_DENIED = 'Appeal reviewed by admin: restriction kept';

/** Sends ONE JSON reply and stops the script. */
function bloom_appeal_respond(array $payload, int $status = 200): void
{
    bloom_json_response($payload, $status);
    exit;
}

/** Sends an error reply and stops. */
function bloom_appeal_fail(string $code, string $message, int $status, array $extra = []): void
{
    bloom_appeal_respond(array_merge(['success' => false, 'code' => $code, 'message' => $message], $extra), $status);
}

set_exception_handler(function (\Throwable $e) {
    error_log('fraud_appeal.php failed: ' . $e->getMessage());
    bloom_appeal_respond(['success' => false, 'message' => 'Your request could not be completed right now. Please try again.'], 500);
});

/** Rate-limit wrapper that fails open, same pattern as fraud_review.php. */
function bloom_appeal_rate_limit_allows(string $bucket, string $key, int $max, int $windowSeconds): bool
{
    try {
        return bloom_check_and_record_attempt($bucket, $key, $max, $windowSeconds);
    } catch (\Throwable $e) {
        error_log("fraud_appeal.php: rate limiter failed for {$bucket}, failing open: " . $e->getMessage());
        return true;
    }
}

/** Text length that counts letters like "ñ" as one character. */
function bloom_appeal_text_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
}

/** Cleans free text from a person: no HTML tags, trimmed, tidy line breaks. */
function bloom_appeal_clean_text($value): string
{
    $text = trim(strip_tags((string) $value));
    // Many blank lines in a row -> one blank line.
    return (string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $text));
}

/** A timestamp as ISO text for JSON, or null. */
function bloom_appeal_iso($value): ?string
{
    return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
}

/** Every valid device hash linked to this customer (profile + orders). */
function bloom_appeal_collect_device_hashes(array $customer, array $orderRows): array
{
    $candidates = is_array($customer['deviceHashes'] ?? null) ? $customer['deviceHashes'] : [];
    foreach ($orderRows as $row) {
        $candidates[] = $row['data']['deviceHash'] ?? null;
    }

    $hashes = [];
    foreach ($candidates as $hash) {
        if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash)) {
            $hashes[] = $hash;
        }
    }
    return array_slice(array_values(array_unique($hashes)), 0, BLOOM_APPEAL_MAX_DEVICES);
}

/**
 * Can this customer send an appeal right now? Looks at their stored appeal
 * (may be null). Returns:
 *   canSubmit     bool
 *   blockedBy     null | 'pending' | 'cooldown'
 *   canAppealAt   DateTimeInterface|null   (only for the cooldown)
 */
function bloom_appeal_submit_state(?array $appeal, DateTimeInterface $now): array
{
    if (!is_array($appeal)) {
        return ['canSubmit' => true, 'blockedBy' => null, 'canAppealAt' => null];
    }

    $status = $appeal['status'] ?? null;
    if ($status === 'pending') {
        return ['canSubmit' => false, 'blockedBy' => 'pending', 'canAppealAt' => null];
    }

    if ($status === 'denied' && ($appeal['decidedAt'] ?? null) instanceof DateTimeInterface) {
        // "@timestamp" works on every PHP version (createFromInterface needs 8.0+).
        $next = (new DateTimeImmutable('@' . $appeal['decidedAt']->getTimestamp()))
            ->modify('+' . BLOOM_APPEAL_COOLDOWN_HOURS . ' hours');
        if ($next > $now) {
            return ['canSubmit' => false, 'blockedBy' => 'cooldown', 'canAppealAt' => $next];
        }
    }

    return ['canSubmit' => true, 'blockedBy' => null, 'canAppealAt' => null];
}

/** The appeal, made safe for the CUSTOMER to see (no admin notes). */
function bloom_appeal_for_customer(?array $appeal): ?array
{
    if (!is_array($appeal)) {
        return null;
    }
    return [
        'status' => (string) ($appeal['status'] ?? ''),
        'message' => (string) ($appeal['message'] ?? ''),
        'submittedAt' => bloom_appeal_iso($appeal['submittedAt'] ?? null),
        'decidedAt' => bloom_appeal_iso($appeal['decidedAt'] ?? null),
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_appeal_fail('INVALID_REQUEST', 'Method not allowed', 405);
}

// --- 1. Per-IP limit, before any token check ---
if (!bloom_appeal_rate_limit_allows('fraud_appeal_ip', bloom_get_client_ip(), BLOOM_APPEAL_LIMIT_PER_IP, BLOOM_APPEAL_LIMIT_WINDOW_SECONDS)) {
    bloom_appeal_fail('RATE_LIMITED', 'Too many requests. Please wait a few minutes and try again.', 429);
}

// --- 2. Who is asking? Only a verified token counts. ---
$idToken = bloom_get_bearer_token();
if (!$idToken) {
    bloom_appeal_fail('FORBIDDEN', 'Missing Authorization token', 401);
}

try {
    $callerUid = bloom_verify_id_token($idToken);
} catch (\Throwable $e) {
    bloom_appeal_fail('FORBIDDEN', 'Invalid or expired session. Please sign in again.', 401);
}

if (!bloom_appeal_rate_limit_allows('fraud_appeal_uid', $callerUid, BLOOM_APPEAL_LIMIT_PER_USER, BLOOM_APPEAL_LIMIT_WINDOW_SECONDS)) {
    bloom_appeal_fail('RATE_LIMITED', 'Too many requests. Please wait a few minutes and try again.', 429);
}

$body = bloom_json_input();
$action = (string) ($body['action'] ?? '');
if (!in_array($action, ['status', 'submit', 'decide'], true)) {
    bloom_appeal_fail('INVALID_REQUEST', 'Invalid request.', 400);
}

$now = bloom_rest_now();

/* =========================================================================
 * ADMIN: decide on an appeal
 * ======================================================================= */
if ($action === 'decide') {
    // Role comes from users/{uid} on the server, never from the browser.
    // The verified token's uid IS the admin; it is saved with the decision
    // and in the audit log.
    $adminUid = $callerUid;
    $adminUser = bloom_firestore_get_document_rest('users', $adminUid);
    $adminRole = (string) ($adminUser['role'] ?? '');
    if (!in_array($adminRole, BLOOM_APPEAL_ADMIN_ROLES, true)) {
        bloom_appeal_fail('FORBIDDEN', 'Only admins can decide on appeals.', 403);
    }
    $adminEmail = (string) ($adminUser['email'] ?? '');

    $targetUid = (string) ($body['uid'] ?? '');
    if (!bloom_is_valid_doc_id($targetUid)) {
        bloom_appeal_fail('INVALID_REQUEST', 'Invalid appeal request.', 400);
    }

    $decision = (string) ($body['decision'] ?? '');
    if (!in_array($decision, ['approve', 'deny'], true)) {
        bloom_appeal_fail('INVALID_DECISION', 'Unknown decision.', 400);
    }

    $reason = bloom_appeal_clean_text($body['reason'] ?? '');
    $reasonLength = bloom_appeal_text_length($reason);
    if ($reasonLength < BLOOM_APPEAL_REASON_MIN_CHARS || $reasonLength > BLOOM_APPEAL_REASON_MAX_CHARS) {
        bloom_appeal_fail('REASON_REQUIRED', 'Please write a reason of ' . BLOOM_APPEAL_REASON_MIN_CHARS . ' to ' . BLOOM_APPEAL_REASON_MAX_CHARS . ' characters explaining your decision.', 400);
    }

    // The admin must be deciding on the appeal they actually read. If the
    // customer sent a newer one in the meantime, the counts differ.
    $seenAppealCount = $body['seenAppealCount'] ?? null;
    if (!is_int($seenAppealCount)) {
        bloom_appeal_fail('INVALID_REQUEST', 'Invalid appeal request.', 400);
    }

    $customer = bloom_firestore_get_document_rest('customers', $targetUid);
    if ($customer === null) {
        bloom_appeal_fail('NOT_FOUND', 'Customer account not found.', 404);
    }
    if (($customer['status'] ?? null) === 'blocked') {
        bloom_appeal_fail('BLOCKED', 'This account is already blacklisted.', 409);
    }

    $isApprove = ($decision === 'approve');

    // Devices linked to this customer, so an approval is not undone by the
    // very next order from the same (previously banned) phone.
    $deviceHashes = [];
    if ($isApprove) {
        $orderRows = bloom_firestore_query_rest('orders', 'user_id', $targetUid);
        $deviceHashes = bloom_appeal_collect_device_hashes($customer, $orderRows);
    }

    // ONE transaction: the appeal, the customer and the device list change
    // together, or not at all. Every READ comes before every WRITE.
    $outcome = bloom_firestore_transaction_rest(function (BloomRestTransaction $tx) use ($targetUid, $isApprove, $reason, $adminUid, $adminRole, $deviceHashes, $now, $seenAppealCount) {
        $appeal = $tx->get(BLOOM_APPEALS_COLLECTION, $targetUid);
        if ($appeal === null) {
            return ['status' => 'no_appeal'];
        }
        if (($appeal['status'] ?? null) !== 'pending') {
            return ['status' => 'already_decided'];
        }
        if ((int) ($appeal['appealCount'] ?? 0) !== $seenAppealCount) {
            return ['status' => 'changed'];
        }

        $fresh = $tx->get('customers', $targetUid);
        if ($fresh === null) {
            return ['status' => 'missing'];
        }
        if (($fresh['status'] ?? null) === 'blocked') {
            return ['status' => 'blocked'];
        }

        $bannedDocs = [];
        if ($isApprove) {
            foreach ($deviceHashes as $hash) {
                $bannedDoc = $tx->get('banned_devices', $hash);
                if ($bannedDoc !== null) {
                    $bannedDocs[$hash] = $bannedDoc;
                }
            }
        }

        // ---- writes ----
        $tx->set(BLOOM_APPEALS_COLLECTION, $targetUid, [
            'status' => $isApprove ? 'approved' : 'denied',
            'decidedBy' => $adminUid,
            'decidedByRole' => $adminRole,
            'decidedAt' => $now,
            'decisionReason' => $reason,
        ], true);

        $note = $isApprove ? BLOOM_APPEAL_FLAG_APPROVED : BLOOM_APPEAL_FLAG_DENIED;
        $update = [
            'fraudFlags' => bloom_merge_unique(bloom_clean_string_list($fresh['fraudFlags'] ?? []), [$note]),
        ];

        $restrictionLifted = false;
        if ($isApprove && ($fresh['isRestricted'] ?? false) === true) {
            $update['isRestricted'] = false;
            $restrictionLifted = true;
        }
        $scoreBefore = null;
        $scoreAfter = null;
        if ($isApprove) {
            // LOWER THE SCORE, KEEP THE HISTORY.
            // Only the two score numbers change. fraudCodes, fraudCategories,
            // fraudFlags, riskLevel and the per-order fraud fields stay as
            // they are, so the next time fraud activity is recorded the score
            // is rebuilt from the full history and the customer is judged as
            // a repeat case, not a first-timer.
            $scoreBefore = is_numeric($fresh['riskScore'] ?? null)
                ? (int) $fresh['riskScore']
                : (is_numeric($fresh['fraudScore'] ?? null) ? (int) $fresh['fraudScore'] : 0);
            $scoreAfter = min($scoreBefore, BLOOM_APPEAL_APPROVED_SCORE);
            if (array_key_exists('riskScore', $fresh)) {
                $update['riskScore'] = $scoreAfter;
            }
            if (array_key_exists('fraudScore', $fresh)) {
                $update['fraudScore'] = $scoreAfter;
            }

            // Same shape fraud_review.php saves for a false alarm, so
            // bloom_customer_cleared_by_review() (includes/fraud_activity.php)
            // and the dashboards read it the same way. The score before and
            // after are kept here so reviewers can see what the approval
            // changed.
            $update['fraudReview'] = [
                'decision' => 'false_alarm',
                'reason' => $reason,
                'reviewedBy' => $adminUid,
                'reviewedByRole' => $adminRole,
                'reviewedAt' => $now,
                'source' => 'appeal',
                'scoreBeforeApproval' => $scoreBefore,
                'scoreAfterApproval' => $scoreAfter,
            ];
        }
        $tx->set('customers', $targetUid, $update, true);

        foreach ($bannedDocs as $hash => $bannedDoc) {
            $tx->set('banned_devices', $hash, [
                'allowedUids' => bloom_merge_unique(bloom_clean_string_list($bannedDoc['allowedUids'] ?? []), [$targetUid]),
            ], true);
        }

        return [
            'status' => 'ok',
            'restrictionLifted' => $restrictionLifted,
            'allowListedDevices' => count($bannedDocs),
            'scoreBefore' => $scoreBefore,
            'scoreAfter' => $scoreAfter,
        ];
    });

    switch ($outcome['status'] ?? '') {
        case 'no_appeal':
            bloom_appeal_fail('NO_APPEAL', 'This customer has no appeal to decide.', 404);
        case 'already_decided':
            bloom_appeal_fail('ALREADY_DECIDED', 'This appeal was already decided. Refresh the page to see the result.', 409);
        case 'changed':
            bloom_appeal_fail('APPEAL_CHANGED', 'The customer sent a newer appeal since you opened this one. Please read it before deciding.', 409);
        case 'missing':
            bloom_appeal_fail('NOT_FOUND', 'Customer account not found.', 404);
        case 'blocked':
            bloom_appeal_fail('BLOCKED', 'This account is already blacklisted.', 409);
    }

    $restrictionLifted = (bool) ($outcome['restrictionLifted'] ?? false);
    $allowListedDevices = (int) ($outcome['allowListedDevices'] ?? 0);
    $scoreBefore = $outcome['scoreBefore'] ?? null;
    $scoreAfter = $outcome['scoreAfter'] ?? null;

    // Audit trail (best-effort: the decision is already saved).
    try {
        $details = $isApprove
            ? 'Appeal approved.'
                . ($restrictionLifted ? ' Restriction lifted.' : ' (Account was no longer restricted.)')
                . ($scoreBefore !== null ? " Score lowered from {$scoreBefore} to {$scoreAfter}; fraud history kept." : '')
                . ' Marked as false alarm (Cash on Delivery restored).'
                . ($allowListedDevices > 0 ? " Customer allow-listed on {$allowListedDevices} banned device(s)." : '')
            : 'Appeal denied: restriction kept.';

        bloom_firestore_add_document_rest('admin_actions', [
            'actorUid' => $adminUid,
            'actorEmail' => $adminEmail,
            'actorRole' => $adminRole,
            'action' => $isApprove ? 'appeal_approved' : 'appeal_denied',
            'targetUid' => $targetUid,
            'targetEmail' => (string) ($customer['email'] ?? ''),
            'details' => $details,
            'reason' => $reason,
            'restrictionLifted' => $restrictionLifted,
            'allowListedDevices' => $allowListedDevices,
            'scoreBefore' => $scoreBefore,
            'scoreAfter' => $scoreAfter,
            'source' => 'fraud_appeal.php',
            'timestamp' => $now,
        ]);
    } catch (\Throwable $e) {
        error_log('fraud_appeal.php: audit log write failed (decision already saved): ' . $e->getMessage());
    }

    bloom_appeal_respond([
        'success' => true,
        'decision' => $decision,
        'restrictionLifted' => $restrictionLifted,
        'allowListedDevices' => $allowListedDevices,
        'scoreBefore' => $scoreBefore,
        'scoreAfter' => $scoreAfter,
    ]);
}

/* =========================================================================
 * CUSTOMER: status / submit (the caller IS the customer)
 * ======================================================================= */
$uid = $callerUid;

$customer = bloom_firestore_get_document_rest('customers', $uid);
if ($customer === null) {
    bloom_appeal_fail('NOT_FOUND', 'Customer profile not found.', 404);
}

if (($customer['status'] ?? null) === 'blocked') {
    bloom_appeal_fail('BLOCKED', 'This account has been blocked. Please contact the shop directly.', 403);
}

$restriction = bloom_restriction_state($customer);
$restricted = $restriction['active'];
$restrictedUntil = $customer['restrictedUntil'] ?? null;
$appeal = bloom_firestore_get_document_rest(BLOOM_APPEALS_COLLECTION, $uid);
$submitState = bloom_appeal_submit_state($appeal, $now);

if ($action === 'status') {
    bloom_appeal_respond([
        'success' => true,
        'restricted' => $restricted,
        'restrictedUntil' => $restricted ? bloom_appeal_iso($restrictedUntil) : null,
        // A customer can only appeal while actually restricted.
        'canAppeal' => $restricted && $submitState['canSubmit'],
        'blockedBy' => $restricted ? $submitState['blockedBy'] : null,
        'canAppealAt' => bloom_appeal_iso($submitState['canAppealAt']),
        'appeal' => bloom_appeal_for_customer($appeal),
        'messageMinChars' => BLOOM_APPEAL_MESSAGE_MIN_CHARS,
        'messageMaxChars' => BLOOM_APPEAL_MESSAGE_MAX_CHARS,
    ]);
}

// ---- action === 'submit' ----
if (!$restricted) {
    bloom_appeal_fail('NOT_RESTRICTED', 'Your account is not restricted, so there is nothing to appeal.', 409);
}

$message = bloom_appeal_clean_text($body['message'] ?? '');
$messageLength = bloom_appeal_text_length($message);
if ($messageLength < BLOOM_APPEAL_MESSAGE_MIN_CHARS || $messageLength > BLOOM_APPEAL_MESSAGE_MAX_CHARS) {
    bloom_appeal_fail('MESSAGE_INVALID', 'Please write your appeal in ' . BLOOM_APPEAL_MESSAGE_MIN_CHARS . ' to ' . BLOOM_APPEAL_MESSAGE_MAX_CHARS . ' characters.', 400);
}

if (!$submitState['canSubmit']) {
    if ($submitState['blockedBy'] === 'pending') {
        bloom_appeal_fail('ALREADY_PENDING', 'You already sent an appeal. Please wait for an admin to review it.', 409);
    }
    bloom_appeal_fail('COOLDOWN', 'Your last appeal was not approved. You can send a new one later.', 429, [
        'canAppealAt' => bloom_appeal_iso($submitState['canAppealAt']),
    ]);
}

// Snapshot of the account at the moment of the appeal, so the admin sees
// what the customer looked like THEN even if the score moves later.
$customerName = (string) ($customer['name'] ?? $customer['username'] ?? '');
$snapshot = [
    'uid' => $uid,
    'status' => 'pending',
    'message' => $message,
    'customerName' => $customerName,
    'customerEmail' => (string) ($customer['email'] ?? ''),
    'submittedAt' => $now,
    'restrictedUntil' => $restrictedUntil instanceof DateTimeInterface ? $restrictedUntil : null,
    'riskScoreAtAppeal' => is_numeric($customer['riskScore'] ?? null) ? (int) $customer['riskScore'] : null,
    'riskLevelAtAppeal' => in_array($customer['riskLevel'] ?? null, BLOOM_RISK_LEVELS, true) ? $customer['riskLevel'] : null,
    'fraudCodesAtAppeal' => bloom_clean_string_list($customer['fraudCodes'] ?? []),
    // Decision fields start empty for every new appeal.
    'decidedBy' => null,
    'decidedByRole' => null,
    'decidedAt' => null,
    'decisionReason' => null,
];

// ONE transaction: re-check "no open appeal / no cooldown" against the
// freshest copy, then save. Two taps of the Send button cannot create two
// appeals, because both write to the SAME document id (the uid).
$result = bloom_firestore_transaction_rest(function (BloomRestTransaction $tx) use ($uid, $snapshot, $now) {
    $existing = $tx->get(BLOOM_APPEALS_COLLECTION, $uid);
    $state = bloom_appeal_submit_state($existing, $now);
    if (!$state['canSubmit']) {
        return ['status' => $state['blockedBy'], 'canAppealAt' => $state['canAppealAt']];
    }

    $count = (int) ($existing['appealCount'] ?? 0) + 1;
    $tx->set(BLOOM_APPEALS_COLLECTION, $uid, $snapshot + ['appealCount' => $count], false);
    return ['status' => 'ok', 'appealCount' => $count];
});

if (($result['status'] ?? '') === 'pending') {
    bloom_appeal_fail('ALREADY_PENDING', 'You already sent an appeal. Please wait for an admin to review it.', 409);
}
if (($result['status'] ?? '') === 'cooldown') {
    bloom_appeal_fail('COOLDOWN', 'Your last appeal was not approved. You can send a new one later.', 429, [
        'canAppealAt' => bloom_appeal_iso($result['canAppealAt'] ?? null),
    ]);
}

// Tell the super admin. Best-effort: the appeal is already saved, so a
// failed notification must not make the customer think it was not sent.
// branchId 'super-admin' matches no real branch, so only the super admin's
// notification bell (which is not filtered by branch) shows it.
try {
    bloom_firestore_add_document_rest('notifications', [
        'title' => 'Appeal Received - Restricted Account',
        'message' => 'A restricted customer sent an appeal. Open the Fraud Activity Log to review it and decide.',
        'type' => 'fraud',
        'priority' => 'high',
        'riskLevel' => 'medium',
        'appealUid' => $uid,
        'branchId' => 'super-admin',
        'created_at' => $now,
        'read' => false,
    ]);
} catch (\Throwable $e) {
    error_log('fraud_appeal.php: notification write failed (appeal already saved): ' . $e->getMessage());
}

bloom_appeal_respond([
    'success' => true,
    'message' => 'Your appeal was sent. An admin will review it.',
    'appeal' => ['status' => 'pending', 'submittedAt' => bloom_appeal_iso($now)],
]);