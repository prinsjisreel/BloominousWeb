<?php
/**
 * BLOOMINOUS - IPQS Diagnostic Script (TEMPORARY - delete after use)
 *
 * Run this directly in the browser: yourdomain.com/test_ipqs_debug.php?email=someone@10minutemail.com
 * It bypasses check_email_risk.php and register.php entirely and prints
 * the RAW IPQS response, so you can see exactly what IPQS itself says
 * about a given email - separate from any of our own app logic.
 *
 * DELETE THIS FILE once you're done debugging. It has no auth check and
 * will burn IPQS quota if left publicly reachable.
 */

require_once __DIR__ . '/config.local.php';

header('Content-Type: text/plain');

$apiKey = getenv('IPQS_API_KEY');
echo "1) API key loaded from config.local.php: ";
echo $apiKey ? substr($apiKey, 0, 4) . str_repeat('*', strlen($apiKey) - 4) . "\n" : "❌ NOT SET / empty\n";

if (!$apiKey) {
    echo "\nSTOP: getenv('IPQS_API_KEY') returned nothing. Either config.local.php\n";
    echo "isn't being included, or putenv() ran but something reset the env\n";
    echo "between here and there (e.g. a different PHP-FPM worker/process).\n";
    exit;
}

echo "\n2) curl extension loaded: " . (extension_loaded('curl') ? "yes\n" : "❌ NO - IPQS calls will always fail/throw\n");

$email = $_GET['email'] ?? 'test@10minutemail.com';
echo "\n3) Testing email: $email\n";

$url = sprintf(
    'https://ipqualityscore.com/api/json/email/%s/%s?strictness=2',
    rawurlencode($apiKey),
    rawurlencode($email)
);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$body = curl_exec($ch);
$errNo = curl_errno($ch);
$errMsg = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\n4) curl error number: $errNo" . ($errMsg ? " ($errMsg)" : "") . "\n";
echo "5) HTTP status code: $httpCode\n";
echo "6) Raw response body:\n";
echo $body . "\n";

$data = json_decode((string) $body, true);
if (is_array($data)) {
    echo "\n7) Parsed 'success' field: " . var_export($data['success'] ?? null, true) . "\n";
    echo "8) Parsed 'disposable' field: " . var_export($data['disposable'] ?? null, true) . "\n";
    echo "9) Parsed 'fraud_score' field: " . var_export($data['fraud_score'] ?? null, true) . "\n";
    if (isset($data['message'])) {
        echo "10) IPQS message: " . $data['message'] . "\n";
    }
} else {
    echo "\n7) ❌ Response was not valid JSON - this is what makes check_email_risk.php\n";
    echo "   throw and fail OPEN in production, letting the signup through.\n";
}