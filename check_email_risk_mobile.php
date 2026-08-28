<?php
/**
 * BLOOMINOUS - Pre-Signup Email Risk Check (Mobile)
 *
 * Mobile twin of check_email_risk.php. Layers 1, 3, 4, 5, 6 are copied
 * verbatim — same functions, same thresholds, same scoring. Only Layer 2
 * differs: App Check replaces Turnstile, since Turnstile's widget has no
 * native mobile equivalent (see appcheck_client.php).
 *
 * Rate limiter uses a SEPARATE key ('email_risk_check_mobile' vs.
 * 'email_risk_check') so a burst of mobile signups can't exhaust the
 * web form's rate limit budget or vice versa — same reasoning as scoping
 * DeviceSecurityService by platform on the Flutter side.
 */

require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/appcheck_client.php';
require_once __DIR__ . '/includes/email_domain_policy.php';
require_once __DIR__ . '/includes/disposable_domains.php';
require_once __DIR__ . '/includes/abstractapi_client.php';
require_once __DIR__ . '/includes/abstractapi_ip_client.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// --- Layer 1: Rate limit (identical policy — runs before body parsing) ---
$clientIp = bloom_get_client_ip();

if (!bloom_check_and_record_attempt('email_risk_check_mobile', $clientIp, 5, 600)) {
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
$appCheckToken = (string) ($body['appCheckToken'] ?? '');

if (!$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit();
}

// --- Layer 2: App Check bot/tamper check (replaces Turnstile) ---
if (!bloom_verify_app_check($appCheckToken)) {
    http_response_code(400);
    echo json_encode([
        'success' => true,
        'block' => true,
        'reason' => 'App verification failed. Please update the app and try again.',
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

// --- Layer 4: Local, free, offline disposable-domain check ---
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

// --- Layer 5: AbstractAPI email validation ---
try {
    $result = bloom_abstractapi_check_email($email);
} catch (\Throwable $e) {
    error_log('bloom_abstractapi_check_email failed, failing open: ' . $e->getMessage());
    echo json_encode(['success' => true, 'block' => false, 'flag' => false, 'scoreBump' => 0]);
    exit();
}

$fraudScore = (int) ($result['fraud_score'] ?? 0);
$isDisposable = ($result['disposable'] ?? false) === true;
$isValid = ($result['valid'] ?? true) !== false;

$block = $isDisposable || !$isValid || $fraudScore >= 90;
$flag = !$block && $fraudScore >= 50;
$scoreBump = $flag ? min(30, max(10, intdiv($fraudScore, 2))) : 0;

$reason = null;
if ($isDisposable) {
    $reason = 'This looks like a disposable/temporary email address. Please use a permanent email to register.';
} elseif (!$isValid) {
    $reason = 'This email address doesn\'t appear to be deliverable. Please double-check it.';
}

// --- Layer 6: AbstractAPI IP Intelligence — soft signal only ---
if (!$block) {
    try {
        $ipResult = bloom_abstractapi_check_ip($clientIp);

        if ($ipResult['tor']) {
            echo json_encode([
                'success' => true,
                'block' => true,
                'reason' => 'Registration cannot be completed through Tor. Please use a standard connection.',
                'flag' => false,
                'scoreBump' => 0,
            ]);
            exit();
        }

        if ($ipResult['vpn'] || $ipResult['proxy'] || $ipResult['abuse']) {
            $flag = true;
            $scoreBump = max($scoreBump, 15);
        }
    } catch (\Throwable $e) {
        error_log('bloom_abstractapi_check_ip failed, failing open: ' . $e->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'block' => $block,
    'reason' => $reason,
    'flag' => $flag,
    'scoreBump' => $scoreBump,
]);