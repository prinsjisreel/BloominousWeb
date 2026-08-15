<?php
/**
 * BLOOMINOUS - AbstractAPI Phone Validation Diagnostic (CLI ONLY)
 * Usage: php test_abstractapi_phone_cli.php +639171234567
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script only runs from the command line.');
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php test_abstractapi_phone_cli.php <phone_in_E.164>\n");
    exit(1);
}

$phone = $argv[1];
require_once __DIR__ . '/includes/abstractapi_phone_client.php';
require_once __DIR__ . '/config.local.php';

$apiKey = trim((string) getenv('ABSTRACTAPI_PHONE_KEY'));
$url = 'https://phoneintelligence.abstractapi.com/v1/?' . http_build_query([
    'api_key' => $apiKey,
    'phone' => $phone,
]);

echo "Testing AbstractAPI Phone Validation for: {$phone}\n";
echo "Requesting: {$url}\n";
echo str_repeat('-', 60) . "\n";

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
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
    $result = bloom_abstractapi_phone_normalize($data);
    echo "Normalized result (what check_phone_risk.php / submit_order.php actually use):\n";
    print_r($result);
    echo "\nCompare the RAW body above against what abstractapi_phone_client.php\n";
    echo "reads (phone_validation.*, phone_risk.*) — adjust the normalizer if\n";
    echo "the real field names/nesting differ.\n";
} else {
    echo "Non-200 - check ABSTRACTAPI_PHONE_KEY in config.local.php.\n";
}