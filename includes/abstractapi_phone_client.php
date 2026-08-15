<?php
/**
 * BLOOMINOUS - AbstractAPI Phone Validation Client
 *
 * Endpoint CONFIRMED via a live successful test call:
 * https://phoneintelligence.abstractapi.com/v1/ — this is AbstractAPI's
 * newer "Phone Intelligence" product, a separate product from the older
 * "Phone Validation" (phonevalidation.abstractapi.com) — same naming
 * pattern as "IP Intelligence" vs. plain "IP Geolocation" found earlier
 * this session. The response schema itself matched what was originally
 * assumed from documentation (phone_validation.*, phone_risk.*) — only
 * the hostname and the product-specific key were wrong, not the field
 * names.
 *
 *   { "phone_validation": { "is_valid": bool, "line_status": "active",
 *                            "is_voip": bool },
 *     "phone_risk":       { "risk_level": "low"|"medium"|"high",
 *                            "is_disposable": bool,
 *                            "is_abuse_detected": bool },
 *     "phone_carrier":    { "name": "...", "line_type": "Wireless"|... } }
 *
 * Not independently verified against a live call from this environment
 * (no network access to abstractapi.com here) - run
 * test_abstractapi_phone_cli.php against a real key before trusting this
 * in production, same discipline as the email/IP clients before it.
 *
 * Used two places, deliberately for different reasons:
 *   - check_phone_risk.php: a UX pre-check, called from checkout.php
 *     BEFORE real SMS verification fires — stops an obviously-disposable
 *     number early, saving an SMS credit and giving instant feedback.
 *     NOT the security boundary — a scripted request could skip this file
 *     entirely.
 *   - submit_order.php: the actual, unbypassable enforcement — every
 *     order goes through this file no matter what the client does, so
 *     THIS is where the real fraud-scoring decision lives (score-based,
 *     matching the existing IP/device sections' philosophy, not a hard
 *     block — see submit_order.php's own comments for why).
 */

require_once __DIR__ . '/../config.local.php';

function bloom_abstractapi_check_phone(string $phone): array
{
    $apiKey = trim((string) getenv('ABSTRACTAPI_PHONE_KEY'));
    if (!$apiKey) {
        throw new \RuntimeException('ABSTRACTAPI_PHONE_KEY not configured.');
    }

    $url = 'https://phoneintelligence.abstractapi.com/v1/?' . http_build_query([
        'api_key' => $apiKey,
        'phone' => $phone,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    curl_close($ch);

    if ($errNo !== 0) {
        throw new \RuntimeException("AbstractAPI phone request failed: $errMsg");
    }
    if ($httpCode !== 200) {
        throw new \RuntimeException("AbstractAPI phone returned HTTP $httpCode: " . substr((string) $body, 0, 300));
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new \RuntimeException('AbstractAPI phone returned invalid JSON.');
    }

    return bloom_abstractapi_phone_normalize($data);
}

function bloom_abstractapi_phone_normalize(array $data): array
{
    $validation = $data['phone_validation'] ?? [];
    $risk = $data['phone_risk'] ?? [];

    return [
        'valid' => ($validation['is_valid'] ?? true) === true,
        'voip' => ($validation['is_voip'] ?? false) === true,
        'disposable' => ($risk['is_disposable'] ?? false) === true,
        'abuse' => ($risk['is_abuse_detected'] ?? false) === true,
    ];
}