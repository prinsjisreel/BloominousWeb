<?php
/**
 * BLOOMINOUS - Pre-Signup Email Risk Check
 *
 * Called by register.php BEFORE createUserWithEmailAndPassword. No auth
 * required (there's no account yet), so this is intentionally rate-limit-
 * worthy — consider adding a simple per-IP throttle if abuse shows up.
 *
 * Fails OPEN: if IPQS is unreachable, unset, or errors, we don't block
 * registration over a third-party outage — we just skip the check.
 *
 * Only a distilled decision goes back to the browser, never the raw IPQS
 * payload (avoid handing out exactly which signals we check on).
 */

require_once __DIR__ . '/includes/ipqs_client.php';
require_once __DIR__ . '/includes/disposable_domains.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$email = filter_var($body['email'] ?? '', FILTER_VALIDATE_EMAIL);

if (!$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit();
}

// Local, free, offline check FIRST. This still blocks known temp-mail
// domains (10minutemail, guerrillamail, mailinator, etc.) even if IPQS
// is unreachable, rate-limited, or fails open below. No Firebase, no
// Blaze plan, no external network call needed for this part.
if (bloom_is_disposable_domain($email)) {
    echo json_encode([
        'success' => true,
        'block' => true,
        'reason' => 'This looks like a disposable/temporary email address. Please use a permanent email to register.',
        'flag' => false,
        'scoreBump' => 0,
    ]);
    exit();
}

try {
    $result = bloom_ipqs_check_email($email);
} catch (\Throwable $e) {
    error_log('bloom_ipqs_check_email failed, failing open: ' . $e->getMessage());
    echo json_encode(['success' => true, 'block' => false, 'flag' => false, 'scoreBump' => 0]);
    exit();
}

$fraudScore = (int) ($result['fraud_score'] ?? 0);
$isDisposable = ($result['disposable'] ?? false) === true;
$isValid = ($result['valid'] ?? true) !== false; // treat missing 'valid' as valid
$recentAbuse = ($result['recent_abuse'] ?? false) === true;
$isHoneypot = ($result['honeypot'] ?? false) === true;

// Hard block: disposable/temp-mail domains, undeliverable addresses, known
// recent abuse, or a very high IPQS fraud_score.
$block = $isDisposable || !$isValid || $recentAbuse || $fraudScore >= 90;

// Soft flag: worth bumping this account's initial fraudScore once created,
// but not worth refusing signup over.
$flag = !$block && ($fraudScore >= 50 || $isHoneypot);
$scoreBump = $flag ? min(30, max(10, intdiv($fraudScore, 2))) : 0;

$reason = null;
if ($isDisposable) {
    $reason = 'This looks like a disposable/temporary email address. Please use a permanent email to register.';
} elseif (!$isValid) {
    $reason = 'This email address doesn\'t appear to be deliverable. Please double-check it.';
} elseif ($recentAbuse || $fraudScore >= 90) {
    $reason = 'This email address is associated with recent fraud/abuse reports and can\'t be used to register.';
}

echo json_encode([
    'success' => true,
    'block' => $block,
    'reason' => $reason,
    'flag' => $flag,
    'scoreBump' => $scoreBump,
]);