<?php
/**
 * BLOOMINOUS - Firebase App Check Verification
 *
 * Mobile-native twin of turnstile_client.php. Turnstile proves "a human
 * is driving this browser" via a JS challenge; App Check proves "this
 * request came from a real, unmodified copy of the BloominousApp binary"
 * via Play Integrity (Android) / App Attest (iOS). Same job, different
 * mechanism, because Turnstile's widget only runs in a browser context.
 *
 * Policy mirrors turnstile_client.php's two-tier structure exactly:
 *   - empty/missing token → hard block, no network call needed.
 *   - verification call throws → UNVERIFIED (see below), currently
 *     defaults to BLOCK rather than fail-open.
 */

require_once __DIR__ . '/../vendor/autoload.php';

function bloom_verify_app_check(string $token): bool
{
    if ($token === '') {
        return false; // no token = hard fail, same policy as Turnstile's empty-token case
    }

    try {
        $factory = (new \Kreait\Firebase\Factory())
            ->withServiceAccount(__DIR__ . '/../serviceAccountKey.json');
        $appCheck = $factory->createAppCheck();
        $appCheck->verifyToken($token); // throws on invalid/expired/forged token
        return true;
    } catch (\Throwable $e) {
        // ⚠️ UNVERIFIED — per your own Principle #1, this needs a live
        // CLI test before being trusted in production. I cannot tell
        // from here whether Kreait throws a distinct exception type for
        // "genuinely invalid token" vs. "network failure reaching
        // Google's verification service" — those SHOULD be handled
        // differently (block vs. fail-open), the same way
        // bloom_verify_turnstile() distinguishes a curl error from an
        // actual `success: false` response. Right now this treats every
        // exception as a block, which is the safer default (App Check
        // failure sits in the same "bot detection failure" hard-block
        // category as Turnstile per Principle #5), but it means a
        // genuine Firebase outage would also block registrations rather
        // than fail open like your other layers do.
        //
        // Build test_appcheck_cli.php (same pattern as
        // test_abstractapi_cli.php), send one deliberately garbage token
        // and one real one, and paste the real output before trusting this.
        error_log('bloom_verify_app_check failed: ' . $e->getMessage());
        return false;
    }
}