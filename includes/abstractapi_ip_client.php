<?php
/**
 * BLOOMINOUS - AbstractAPI "IP Intelligence" Client
 *
 * Replaces the removed IPQS IP-reputation check in submit_order.php.
 * Checks VPN/proxy/Tor/abuse status for the checkout request's IP.
 *
 * Endpoint CONFIRMED via Abstract's own published documentation and
 * example calls (docs.abstractapi.com/api/ip-intelligence): the real
 * hostname is ipgeolocation.abstractapi.com — this is the one product
 * where the dashboard brand name ("IP Intelligence") does NOT match the
 * hostname, unlike Email Reputation and Phone Intelligence, which both
 * use their brand name as the hostname directly.
 *
 * Endpoint: https://ipgeolocation.abstractapi.com/v1/
 *
 * Confirmed real response shape (from Abstract's own published example):
 *   {
 *     "ip_address": "185.197.192.65",
 *     "security": {
 *       "is_vpn": bool, "is_proxy": bool, "is_tor": bool,
 *       "is_hosting": bool, "is_relay": bool, "is_mobile": bool,
 *       "is_abuse": bool
 *     },
 *     ...
 *   }
 * Note the "is_" prefix on every field — an earlier version of this
 * file checked bare field names (vpn, proxy, tor) with no prefix, which
 * would have silently read as false forever even with a working URL.
 */

require_once __DIR__ . '/../config.local.php';

function bloom_abstractapi_check_ip(string $ip): array
{
    $apiKey = trim((string) getenv('ABSTRACTAPI_IP_KEY'));
    if (!$apiKey) {
        throw new \RuntimeException('ABSTRACTAPI_IP_KEY not configured.');
    }

    // FIXED: previously pointed at a hardcoded leaked key/test IP glued
    // onto a malformed http_build_query() call (no ? or & separator) —
    // same bug pattern found in the email client. Every call was
    // hitting a broken URL and throwing, which check_email_risk.php's
    // (and submit_order.php's) fail-open handling silently converted
    // into "no flags," explaining why AbstractAPI's dashboard usage
    // never increased.
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
    $security = $data['security'] ?? [];

    // FIXED: field names now match Abstract's confirmed real response
    // exactly (is_vpn, is_proxy, etc. — every flag prefixed with "is_").
    // The previous version checked bare names with no prefix, which
    // never existed in the real response and would have silently
    // evaluated to false forever.
    return [
        'vpn' => ($security['is_vpn'] ?? false) === true,
        'proxy' => ($security['is_proxy'] ?? false) === true,
        'tor' => ($security['is_tor'] ?? false) === true,
        'hosting' => ($security['is_hosting'] ?? false) === true,
        'relay' => ($security['is_relay'] ?? false) === true,
        'abuse' => ($security['is_abuse'] ?? false) === true,
    ];
}