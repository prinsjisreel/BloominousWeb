<?php
$url = 'https://phoneintelligence.abstractapi.com/v1/?' . http_build_query([
    'api_key' => '74b540a1e7074516a86190260fa4dfdb',
    'phone' => '+14152007986',
]);
echo "Requesting: $url\n";
echo str_repeat('-', 60) . "\n";
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errNo = curl_errno($ch);
$errMsg = curl_error($ch);
curl_close($ch);
echo "HTTP status: $httpCode\n";
if ($errNo !== 0) { echo "cURL error: $errMsg\n"; exit; }
echo "\nRAW response:\n$body\n";