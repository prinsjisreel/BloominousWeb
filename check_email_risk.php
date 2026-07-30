<?php
/**
 * BLOOMINOUS - Pre-Signup Email Risk Check
 *
 * Called by register.php BEFORE createUserWithEmailAndPassword. Five
 * layers now, cheapest/fastest first:
 *
 *   1. Rate limit         (rate_limiter.php)       — stop request-flooding
 *   2. Turnstile           (turnstile_client.php)   — stop bots/scripts
 *   3. Domain allow-list    (email_domain_policy.php) — OFF by default
 *   4. Disposable domains   (disposable_domains.php)  — existing, free
 *   5. IPQS                 (ipqs_client.php)         — existing, last
 *
 * Rate limiting runs FIRST, before even parsing the email — it's purely
 * IP-based and needs no valid input to do its job. Running it after email
 * validation (as an earlier version of this file did) let a script bypass
 * the entire limiter just by sending malformed emails, since the 400
 * response for invalid format exited before the limiter ever ran.
 *
 * Fails OPEN on infra problems (IPQS, Firestore, Cloudflare unreachable) —
 * see each layer's own file for its specific reasoning. The one exception
 * is a missing Turnstile token, which hard-blocks (see turnstile_client.php).
 *
 * Only a distilled decision goes back to the browser, never raw payloads
 * from IPQS/Turnstile — avoid handing out exactly which signals we check.
 */

require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/turnstile_client.php';
require_once __DIR__ . '/includes/email_domain_policy.php';
require_once __DIR__ . '/includes/ipqs_client.php';
require_once __DIR__ . '/includes/disposable_domains.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// ← MOVED UP: rate limit now runs first, before touching the request body
// at all. It only needs the caller's IP, not a parsed/valid email — so
// there's no reason to wait until after validation to check it.
$clientIp = bloom_get_client_ip();

if (!bloom_check_and_record_attempt('email_risk_check', $clientIp, 5, 600)) {
    http_response_code(429);
    echo json_encode([
        'success' => true,
        'block' => true,
        'reason' => 'Too many attempts. Please wait a few minutes and try again.',
        'flag' => false,
        'scoreBump' => 0,
    ]);
    exit();
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$email = filter_var($body['email'] ?? '', FILTER_VALIDATE_EMAIL);
$turnstileToken = (string) ($body['turnstileToken'] ?? '');

if (!$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit();
}

// --- Layer 2: Turnstile bot check ---
if (!bloom_verify_turnstile($turnstileToken, $clientIp)) {
    http_response_code(400);
    echo json_encode([
        'success' => true,
        'block' => true,
        'reason' => 'Verification failed. Please refresh the page and try again.',
        'flag' => false,
        'scoreBump' => 0,
    ]);
    exit();
}

// --- Layer 3: Domain allow-list (disabled unless explicitly enabled) ---
if (!bloom_is_domain_allowed($email)) {
    echo json_encode([
        'success' => true,
        'block' => true,
        'reason' => 'Registration is currently limited to major email providers (Gmail, Outlook, Yahoo, iCloud). Please use one of those.',
        'flag' => false,
        'scoreBump' => 0,
    ]);
    exit();
}

// --- Layer 4: Local, free, offline disposable-domain check (existing) ---
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

// --- Layer 5: IPQS fraud/risk score (existing, unchanged) ---
try {
    $result = bloom_ipqs_check_email($email);
} catch (\Throwable $e) {
    error_log('bloom_ipqs_check_email failed, failing open: ' . $e->getMessage());
    echo json_encode(['success' => true, 'block' => false, 'flag' => false, 'scoreBump' => 0]);
    exit();
}

$fraudScore = (int) ($result['fraud_score'] ?? 0);
$isDisposable = ($result['disposable'] ?? false) === true;
$isValid = ($result['valid'] ?? true) !== false;
$recentAbuse = ($result['recent_abuse'] ?? false) === true;
$isHoneypot = ($result['honeypot'] ?? false) === true;

$block = $isDisposable || !$isValid || $recentAbuse || $fraudScore >= 90;
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