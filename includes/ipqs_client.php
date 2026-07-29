<?php
/**
 * BLOOMINOUS - IPQualityScore (IPQS) Client
 *
 * Thin wrapper around the IPQS "IP Reputation" and "Email Validation" APIs.
 * Used by submit_order.php (bloom_ipqs_check_ip) and check_email_risk.php
 * (bloom_ipqs_check_email) as a fraud SIGNAL, never a hard identity check.
 *
 * Both callers wrap these in try/catch and fail OPEN on any exception
 * (unreachable API, bad key, timeout, non-200, malformed JSON) - so this
 * file always throws on failure rather than returning a fake "safe" result.
 * Swallowing errors here would silently hide real outages from the caller.
 */

require_once __DIR__ . '/../config.local.php';

/**
 * Low-level GET against the IPQS JSON API with a short timeout.
 * Throws on missing key, transport failure, non-200, or malformed JSON.
 */
function bloom_ipqs_request(string $endpoint, string $subject, array $extraParams = []): array
{
    $apiKey = getenv('IPQS_API_KEY');
    if (!$apiKey) {
        throw new \RuntimeException('IPQS_API_KEY is not configured.');
    }

    $url = sprintf(
        'https://ipqualityscore.com/api/json/%s/%s/%s',
        $endpoint,
        rawurlencode($apiKey),
        rawurlencode($subject)
    );

    if (!empty($extraParams)) {
        $url .= '?' . http_build_query($extraParams);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0) {
        throw new \RuntimeException("IPQS request failed: $errMsg");
    }
    if ($httpCode !== 200) {
        throw new \RuntimeException("IPQS returned HTTP $httpCode");
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new \RuntimeException('IPQS returned malformed JSON.');
    }
    if (($data['success'] ?? false) !== true) {
        $message = $data['message'] ?? 'unknown error';
        throw new \RuntimeException("IPQS reported failure: $message");
    }

    return $data;
}

/**
 * IP reputation lookup. Returns keys consumed by submit_order.php:
 * fraud_score, proxy, vpn, tor, recent_abuse (plus whatever else IPQS sends).
 */
function bloom_ipqs_check_ip(string $ip): array
{
    return bloom_ipqs_request('ip', $ip);
}

/**
 * Email risk lookup. Returns keys consumed by check_email_risk.php:
 * fraud_score, disposable, valid, recent_abuse, honeypot (plus extras).
 */
function bloom_ipqs_check_email(string $email): array
{
    // strictness=2 is IPQS's most aggressive disposable-domain detection
    // tier. Costs nothing extra, just widens what counts as "disposable".
    return bloom_ipqs_request('email', $email, ['strictness' => 2]);
}