<?php
/**
 * BLOOMINOUS - Google reCAPTCHA v2 Verification Client
 *
 * Replaces turnstile_client.php. Same calling contract as before:
 * bloom_verify_recaptcha($token, $clientIp) returns true/false.
 *
 * A MISSING token hard-blocks (no token = the widget was never solved,
 * nothing to verify) — matching the old Turnstile client's behavior.
 * The verification API itself being unreachable fails OPEN, same as
 * every other infra-dependent check in this pipeline (AbstractAPI,
 * Firestore) — a Google outage should never be able to block every
 * registration on this site.
 */

function bloom_verify_recaptcha(string $token, string $clientIp): bool {
    if ($token === '') {
        return false;
    }

    require_once __DIR__ . '/../config.local.php';
    $secret = getenv('RECAPTCHA_SECRET_KEY') ?: '';
    if ($secret === '') {
        error_log('bloom_verify_recaptcha: RECAPTCHA_SECRET_KEY not configured.');
        return false;
    }

    try {
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $clientIp,
            ]),
            CURLOPT_TIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            // Fail OPEN — Google unreachable is an infra problem, not
            // evidence the person is a bot.
            error_log('bloom_verify_recaptcha: siteverify unreachable, failing open.');
            return true;
        }

        $result = json_decode($response, true);
        return ($result['success'] ?? false) === true;
    } catch (\Throwable $e) {
        error_log('bloom_verify_recaptcha exception, failing open: ' . $e->getMessage());
        return true;
    }
}