<?php
/**
 * BLOOMINOUS - Cloudflare Turnstile Verification
 *
 * Free bot-behavior check, used instead of reCAPTCHA Enterprise (see
 * BLOOM_session_handoff_v2.md Part B — reCAPTCHA Enterprise's free tier
 * dropped to 10,000/month in 2026 and needs a billed GCP project beyond
 * that; Turnstile has no such requirement).
 *
 * The SITE key is public and lives in register.php's HTML (it has to —
 * the widget runs in the browser). Only the SECRET key is sensitive, and
 * it never leaves this file — same trust boundary as IPQS's API key in
 * ipqs_client.php.
 */

require_once __DIR__ . '/../config.local.php';

/**
 * Verifies a Turnstile token against Cloudflare's siteverify endpoint.
 *
 * Two different "not verified" cases are handled differently on purpose:
 *   - empty/missing token → the widget never ran, or something skipped
 *     JS execution entirely. Hard block, NOT fail-open.
 *   - Cloudflare unreachable → infra problem, not the token's fault.
 *     Fails open here, same policy as IPQS, so a Cloudflare outage
 *     doesn't take down registration entirely.
 */
function bloom_verify_turnstile(string $token, string $remoteIp): bool
{
    if ($token === '') {
        return false; // no token = hard fail, not an outage
    }

    $secret = getenv('TURNSTILE_SECRET_KEY');
    if (!$secret) {
        error_log('TURNSTILE_SECRET_KEY not configured; skipping Turnstile check.');
        return true; // not configured yet = treat as disabled
    }

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $remoteIp,
        ]),
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);

    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    curl_close($ch);

    if ($errNo !== 0 || $body === false) {
        error_log('Turnstile siteverify request failed, failing open.');
        return true;
    }

    $data = json_decode($body, true);
    return is_array($data) && ($data['success'] ?? false) === true;
}