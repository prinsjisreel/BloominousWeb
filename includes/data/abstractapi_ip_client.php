<?php
/**
 * BLOOMINOUS - AbstractAPI "IP Intelligence" Client
 *
 * Replaces the removed IPQS IP-reputation check in submit_order.php.
 * Checks VPN/proxy/Tor/abuse status for the checkout request's IP.
 *
 * Endpoint per AbstractAPI's documentation: ipgeolocation.abstractapi.com
 * (the product is BRANDED "IP Intelligence" in the dashboard, but the
 * underlying REST hostname documented is ipgeolocation.abstractapi.com -
 * same naming quirk as Email Reputation's product-name-vs-hostname
 * mismatch found earlier this session). NOT independently verified
 * against a live call from this environment (no network access to
 * abstractapi.com here) - run test_abstractapi_ip_cli.php against your
 * real key before trusting this in production, same as was done for the
 * email client.
 *
 * Expected response shape (based on the dashboard's "Security" card
 * grouping seen in testing): a nested "security" object containing
 * vpn, proxy, tor, hosting, relay, mobile, abuse - all booleans. This
 * client checks a couple of plausible field locations defensively in
 * case the real JSON nests it differently than the UI grouping implies.
 */

require_once __DIR__ . '/../config.local.php';

function bloom_abstractapi_check_ip(string $ip): array
{
    $apiKey = trim((string) getenv('ABSTRACTAPI_IP_KEY'));
    if (!$apiKey) {
        throw new \RuntimeException('ABSTRACTAPI_IP_KEY not configured.');
    }

    $url = 'https://ipgeolocation.abstractapi.com/v1/?' . http_build_query([
        'api_key' => $apiKey,
        'ip_address' => $ip,
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
        throw new \RuntimeException("AbstractAPI IP request failed: $errMsg");
    }
    if ($httpCode !== 200) {
        throw new \RuntimeException("AbstractAPI IP returned HTTP $httpCode: " . substr((string) $body, 0, 300));
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new \RuntimeException('AbstractAPI IP returned invalid JSON.');
    }

    return bloom_abstractapi_ip_normalize($data);
}

function bloom_abstractapi_ip_normalize(array $data): array
{
    // Try nested under "security" first (matches the dashboard's card
    // grouping); fall back to top-level fields if the real API doesn't
    // nest them that way.
    $security = $data['security'] ?? $data;

    return [
        'vpn' => ($security['vpn'] ?? false) === true,
        'proxy' => ($security['proxy'] ?? false) === true,
        'tor' => ($security['tor'] ?? false) === true,
        'hosting' => ($security['hosting'] ?? false) === true,
        'relay' => ($security['relay'] ?? false) === true,
        'abuse' => ($security['abuse'] ?? false) === true,
    ];
}