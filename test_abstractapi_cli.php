<?php
/**
 * BLOOMINOUS - AbstractAPI (Email Reputation) Diagnostic (CLI ONLY)
 *
 * Run from a terminal on your own machine.
 * Usage: php test_abstractapi_cli.php someone@domain.com
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script only runs from the command line.');
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php test_abstractapi_cli.php someone@domain.com\n");
    exit(1);
}

$email = $argv[1];
require_once __DIR__ . '/includes/abstractapi_client.php';

echo "Testing AbstractAPI (Email Reputation) for: {$email}\n";
echo str_repeat('-', 60) . "\n";

try {
    $result = bloom_abstractapi_check_email($email);
    echo "SUCCESS\n\n";
    echo "Normalized result (what check_email_risk.php actually uses):\n";
    print_r($result);
} catch (\Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}