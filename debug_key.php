<?php
header('Content-Type: text/plain');

echo "=== DIRECTORY DIAGNOSTIC ===\n";
echo "Current working directory: " . getcwd() . "\n\n";

$configPaths = [
    __DIR__ . '/config.local.php',
    __DIR__ . '/includes/config.local.php'
];

foreach ($configPaths as $path) {
    echo "Looking for: $path\n";
    if (file_exists($path)) {
        echo "Status: FOUND!\n";
        require_once $path;
        break;
    } else {
        echo "Status: NOT FOUND.\n";
    }
}

echo "\n=== KEY DIAGNOSTIC ===\n";
$key = getenv('TRIPO_API_KEY');
if ($key) {
    // Show only the first 5 and last 5 characters so we don't leak the full key
    $masked = substr($key, 0, 5) . '...' . substr($key, -5);
    echo "TRIPO_API_KEY is set! Value: $masked\n";
    echo "Length: " . strlen($key) . " characters.\n";
} else {
    echo "TRIPO_API_KEY is NOT SET (returns false to getenv).\n";
}