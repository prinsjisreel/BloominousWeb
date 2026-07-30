<?php
/**
 * BLOOMINOUS - Simple Firestore-backed Rate Limiter
 *
 * Fixed-window counter per (bucket, IP). Used to slow down automated
 * hammering of unauthenticated endpoints — right now just
 * check_email_risk.php.
 *
 * REVISED: uses the plain Firestore REST API (bloom_firestore_get/set
 * _document_rest) instead of bloom_firestore()'s gRPC-based Admin SDK
 * client. bloom_firestore() requires the PHP `grpc` extension to be
 * installed, which is often missing on local dev environments (XAMPP on
 * Windows especially) even when it's present in production — causing a
 * hard-to-diagnose fatal error that looks like "fetch failed" in the
 * browser, with no HTTP response at all. The REST approach needs nothing
 * but plain HTTPS, matching the same choice already made for
 * set_session.php's login path (see firebase_admin.php's own comments).
 *
 * TRADE-OFF: this is a plain read-then-write, not an atomic transaction.
 * Two requests from the exact same IP arriving in the same instant could
 * theoretically both read the same count and both allow one attempt
 * through past the limit. Acceptable here — rate limiting is one layer
 * among five (Turnstile, disposable list, IPQS, domain policy all still
 * apply), not a security boundary on its own, and the worst case is one
 * extra request slipping through, not a breach.
 *
 * Fails OPEN on Firestore errors, matching the project's existing policy:
 * a third-party or infra outage should never be the reason a legitimate
 * signup is blocked.
 */

require_once __DIR__ . '/firebase_admin.php';

function bloom_get_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $value) {
        if (!$value) {
            continue;
        }
        $first = trim(explode(',', $value)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }

    return '0.0.0.0';
}

function bloom_check_and_record_attempt(string $bucket, string $ip, int $maxAttempts, int $windowSeconds): bool
{
    try {
        $docId = hash('sha256', $bucket . ':' . $ip);
        $now = time();

        $existing = bloom_firestore_get_document_rest('rate_limits', $docId);

        if ($existing !== null) {
            $windowStart = (int) ($existing['windowStart'] ?? 0);
            $count = (int) ($existing['count'] ?? 0);

            if (($now - $windowStart) < $windowSeconds) {
                if ($count >= $maxAttempts) {
                    return false; // over limit, window still active
                }
                bloom_firestore_set_document_rest('rate_limits', $docId, [
                    'ip' => $ip,
                    'bucket' => $bucket,
                    'windowStart' => $windowStart,
                    'count' => $count + 1,
                ]);
                return true;
            }
            // Window expired - fall through to start a fresh one below.
        }

        bloom_firestore_set_document_rest('rate_limits', $docId, [
            'ip' => $ip,
            'bucket' => $bucket,
            'windowStart' => $now,
            'count' => 1,
        ]);
        return true;
    } catch (\Throwable $e) {
        error_log('bloom_check_and_record_attempt failed, failing open: ' . $e->getMessage());
        return true;
    }
}