<?php
/**
 * BLOOMINOUS - Pre-Login Rate Limit Check
 *
 * Called by index.php's loginForm handler BEFORE
 * auth.signInWithEmailAndPassword() fires.
 *
 * This is the REAL security boundary — index.php already has a
 * client-side lockout (getLockoutState()/isCurrentlyLocked()), but that
 * lives entirely in the browser (localStorage), meaning anyone can
 * bypass it just by clearing storage, using a private window, or
 * skipping the browser entirely and scripting requests directly against
 * Firebase Auth. This endpoint is server-side, IP-based, and can't be
 * cleared or skipped the same way.
 *
 * Deliberately stricter than registration's rate limit (5/10min): a
 * successful login grants access to an EXISTING account — for staff or
 * super-admin accounts specifically, that's full system access, not
 * just one new customer row. Same bucket/window mechanism either way
 * (rate_limiter.php), just tuned tighter here given the higher stakes.
 *
 * Fails OPEN on infra errors (Firestore unreachable) — never lock every
 * real customer out of logging in over a third-party/infra hiccup.
 */

require_once __DIR__ . '/includes/rate_limiter.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$clientIp = bloom_get_client_ip();

// 5 attempts per 15 minutes per IP — tighter window than registration's
// 5/10min, since brute-forcing a password needs far more than 5 tries
// to succeed, so even this modest limit makes automated guessing
// impractically slow, while still giving a real person who mistypes
// their password a few times plenty of room.
if (!bloom_check_and_record_attempt('login_attempt', $clientIp, 5, 900)) {
    http_response_code(429);
    echo json_encode([
        'success' => true,
        'block' => true,
        'reason' => 'Too many login attempts. Please wait about 15 minutes and try again.',
    ]);
    exit();
}

echo json_encode(['success' => true, 'block' => false]);