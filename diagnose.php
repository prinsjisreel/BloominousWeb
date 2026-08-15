<?php
/**
 * BLOOMINOUS - One-time deployment diagnostic
 *
 * Upload this to your project ROOT on Hostinger (same level as index.php),
 * visit it in the browser once, screenshot/copy the output, then DELETE
 * this file immediately. It reports on file presence and PHP extensions —
 * useful for you to debug, but not something that should stay on a public
 * server long-term.
 */

header('Content-Type: text/plain');

echo "=== BLOOMINOUS DEPLOYMENT DIAGNOSTIC ===\n\n";

echo "PHP version: " . PHP_VERSION . "\n\n";

// --- 1. Required files ---
echo "--- Files ---\n";
$files = [
    'vendor/autoload.php'        => __DIR__ . '/vendor/autoload.php',
    'serviceAccountKey.json'     => __DIR__ . '/serviceAccountKey.json',
    'firebase-applet-config.json'=> __DIR__ . '/firebase-applet-config.json',
    'includes/config.local.php'  => __DIR__ . '/includes/config.local.php',
];
foreach ($files as $label => $path) {
    echo str_pad($label, 32) . ": " . (file_exists($path) ? "FOUND" : "MISSING") . "\n";
}

// --- 2. Required PHP extensions ---
echo "\n--- Extensions ---\n";
$extensions = ['openssl', 'mbstring', 'ctype', 'json', 'curl', 'grpc'];
foreach ($extensions as $ext) {
    echo str_pad($ext, 32) . ": " . (extension_loaded($ext) ? "LOADED" : "NOT LOADED") . "\n";
}

// --- 3. serviceAccountKey.json sanity check (no secrets printed) ---
echo "\n--- serviceAccountKey.json sanity check ---\n";
$keyPath = __DIR__ . '/serviceAccountKey.json';
if (file_exists($keyPath)) {
    $raw = file_get_contents($keyPath);
    $json = json_decode($raw, true);
    if ($json === null) {
        echo "INVALID JSON — json_last_error: " . json_last_error_msg() . "\n";
    } else {
        echo "Valid JSON: yes\n";
        echo "project_id: " . ($json['project_id'] ?? '(missing)') . "\n";
        echo "client_email present: " . (isset($json['client_email']) ? 'yes' : 'no') . "\n";
        $pk = $json['private_key'] ?? '';
        echo "private_key present: " . ($pk ? 'yes' : 'no') . "\n";
        if ($pk) {
            echo "private_key starts correctly: " . (str_starts_with($pk, '-----BEGIN PRIVATE KEY-----') ? 'yes' : 'no') . "\n";
            echo "private_key contains real newlines: " . (str_contains($pk, "\n") ? 'yes' : 'NO - likely corrupted by upload') . "\n";
        }
    }
} else {
    echo "(skipped — file not found)\n";
}

// --- 4. Attempt an actual Kreait Auth verifyIdToken bootstrap (no real token) ---
echo "\n--- Kreait Factory bootstrap test ---\n";
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    if (file_exists($keyPath) && class_exists('\Kreait\Firebase\Factory')) {
        try {
            $factory = (new \Kreait\Firebase\Factory())->withServiceAccount($keyPath);
            $auth = $factory->createAuth();
            echo "Factory + createAuth(): SUCCESS (service account loaded correctly)\n";
        } catch (\Throwable $e) {
            echo "Factory + createAuth(): FAILED\n";
            echo "Exception: " . get_class($e) . "\n";
            echo "Message: " . $e->getMessage() . "\n";
        }
    } else {
        echo "(skipped — missing vendor class or key file)\n";
    }
} else {
    echo "(skipped — vendor/autoload.php not found)\n";
}

// --- 5. Server clock (relevant to JWT verification) ---
echo "\n--- Server time ---\n";
echo "Server time (UTC): " . gmdate('Y-m-d H:i:s') . "\n";
echo "(Compare this to the actual current UTC time — more than a couple minutes off can break token verification.)\n";

echo "\n=== END DIAGNOSTIC — DELETE THIS FILE NOW ===\n";