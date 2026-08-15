<?php
/**
 * BLOOMINOUS - AbstractAPI IP Intelligence Diagnostic (CLI ONLY)
 * Usage: php test_abstractapi_ip_cli.php 131.226.98.149
 *
 * Makes exactly ONE request (earlier version made two - a raw call plus
 * a second one inside bloom_abstractapi_check_ip() - wasting a free-tier
 * credit and producing confusing back-to-back output on every test run).
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script only runs from the command line.');
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php test_abstractapi_ip_cli.php <ip_address>\n");
    exit(1);
}

$ip = $argv[1];
require_once __DIR__ . '/includes/abstractapi_ip_client.php';
require_once __DIR__ . '/config.local.php';

$apiKey = trim((string) getenv('ABSTRACTAPI_IP_KEY'));
$url = 'https://ip-intelligence.abstractapi.com/v1/?api_key=b3d739e396814a4fb1f3e9df0bb9bad3&ip_address=131.226.97.169' . http_build_query([
    'api_key' => $apiKey,
    'ip_address' => $ip,
]);

echo "Testing AbstractAPI IP Intelligence for: {$ip}\n";
echo "Requesting: {$url}\n";
echo str_repeat('-', 60) . "\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errNo = curl_errno($ch);
$errMsg = curl_error($ch);
curl_close($ch);

echo "HTTP status: {$httpCode}\n";
if ($errNo !== 0) {
    echo "cURL error: {$errMsg}\n";
    exit(1);
}

echo "\nRAW response body:\n{$body}\n\n";

if ($httpCode === 200) {
    $data = json_decode($body, true);
    // Reuses the SAME already-fetched $data - no second network call.
    $result = bloom_abstractapi_ip_normalize($data);
    echo "Normalized result (what submit_order.php will actually use):\n";
    print_r($result);
} else {
    echo "Non-200 - check ABSTRACTAPI_IP_KEY in config.local.php.\n";
}