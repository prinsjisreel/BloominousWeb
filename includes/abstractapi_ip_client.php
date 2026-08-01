<?php
/**
 * BLOOMINOUS - AbstractAPI "IP Intelligence" Client
 *
 * Endpoint and field names CONFIRMED against a real successful call
 * during testing (not just documentation this time) - the response
 * nests VPN/proxy/etc under "security", with each field prefixed
 * "is_" (is_vpn, is_proxy, is_tor, is_hosting, is_relay, is_mobile,
 * is_abuse) - not the bare names originally assumed.
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

/**
 * CONFIRMED real shape (from a live successful call):
 *   { "security": { "is_vpn": bool, "is_proxy": bool, "is_tor": bool,
 *                    "is_hosting": bool, "is_relay": bool,
 *                    "is_mobile": bool, "is_abuse": bool } }
 */
function bloom_abstractapi_ip_normalize(array $data): array
{
    $security = $data['security'] ?? [];

    return [
        'vpn' => ($security['is_vpn'] ?? false) === true,
        'proxy' => ($security['is_proxy'] ?? false) === true,
        'tor' => ($security['is_tor'] ?? false) === true,
        'hosting' => ($security['is_hosting'] ?? false) === true,
        'relay' => ($security['is_relay'] ?? false) === true,
        'abuse' => ($security['is_abuse'] ?? false) === true,
    ];
}