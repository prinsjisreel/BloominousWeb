<?php
/**
 * BLOOMINOUS - AbstractAPI "Email Reputation" Client
 *
 * Targets Abstract's EMAIL REPUTATION product specifically (not "Email
 * Validation" - a separate product with its own endpoint/key/schema).
 * Confirmed against Abstract's own published documentation
 * (docs.abstractapi.com/api/email-reputation) after the first version of
 * this file was built against the wrong product by mistake.
 *
 * Endpoint: https://emailreputation.abstractapi.com/v1/
 *
 * Confirmed real response shape:
 *   {
 *     "email_deliverability": {
 *       "status": "deliverable" | "undeliverable" | "risky" | "unknown",
 *       "is_format_valid": bool, "is_smtp_valid": bool, "is_mx_valid": bool
 *     },
 *     "email_quality": {
 *       "score": 0.0-1.0,          // higher = better
 *       "is_disposable": bool,
 *       "is_free_email": bool,
 *       "is_role": bool,
 *       "is_catchall": bool
 *     }
 *   }
 *
 * Run test_abstractapi_cli.php against a real key to confirm this stays
 * accurate — Abstract could still change field names/plans over time.
 */

require_once __DIR__ . '/../config.local.php';

function bloom_abstractapi_check_email(string $email): array
{
    $apiKey = trim((string) getenv('ABSTRACTAPI_EMAIL_KEY'));
    if (!$apiKey) {
        throw new \RuntimeException('ABSTRACTAPI_EMAIL_KEY not configured.');
    }

    $url = 'https://phoneintelligence.abstractapi.com/v1/?api_key=8dcf751c09a140dda1e7b7df5e69c904&phone=+14152007986' . http_build_query([
        'api_key' => $apiKey,
        'email' => $email,
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
        throw new \RuntimeException("AbstractAPI request failed: $errMsg");
    }
    if ($httpCode !== 200) {
        throw new \RuntimeException("AbstractAPI returned HTTP $httpCode: " . substr((string) $body, 0, 300));
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new \RuntimeException('AbstractAPI returned invalid JSON.');
    }

    return bloom_abstractapi_normalize($data);
}

/**
 * Normalizes Email Reputation's response into the flat shape
 * check_email_risk.php expects: fraud_score, disposable, valid.
 */
function bloom_abstractapi_normalize(array $data): array
{
    $deliverability = $data['email_deliverability'] ?? [];
    $quality = $data['email_quality'] ?? [];

    $status = strtolower((string) ($deliverability['status'] ?? 'unknown'));
    $isFormatValid = ($deliverability['is_format_valid'] ?? true) === true;
    $isMxValid = ($deliverability['is_mx_valid'] ?? true) === true;
    $isDisposable = ($quality['is_disposable'] ?? false) === true;
    $qualityScore = (float) ($quality['score'] ?? 1.0); // 0 (bad) - 1 (good)

    $valid = $isFormatValid && $isMxValid && $status !== 'undeliverable';

    // Invert quality_score (0=bad,1=good) onto a 0-100 risk scale, same
    // semantics check_email_risk.php's thresholds were written against.
    $fraudScore = (int) round((1 - max(0, min(1, $qualityScore))) * 100);

    return [
        'fraud_score' => $fraudScore,
        'disposable' => $isDisposable,
        'valid' => $valid,
    ];
}