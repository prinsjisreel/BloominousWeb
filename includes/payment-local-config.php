<?php
/**
 * BLOOMINOUS - Local PayMongo Credentials (NOT committed to git)
 *
 * Loaded automatically by includes/payment_helper.php if this file exists:
 *   if (file_exists(__DIR__ . '/payment-local-config.php')) { require_once ... }
 *
 * Same pattern as mailer-local-config.php: putenv() sets each value for the
 * current request, and the rest of the code reads it back with getenv().
 * That keeps the secret key out of every other file — and out of GitHub.
 *
 * Add this line to your .gitignore so it is never committed:
 *   includes/payment-local-config.php
 *
 * LOCAL (XAMPP) vs HOSTINGER:
 *   - Locally, APP_URL must include the /BLOOM folder.
 *   - On Hostinger, change APP_URL to your real domain (https://...),
 *     and put your webhook secret in PAYMONGO_WEBHOOK_SECRET.
 */

// PayMongo Dashboard -> Developers -> API Keys -> "Secret key" (starts with sk_test_).
// Use the SECRET key, not the public pk_test_ key.
putenv('PAYMONGO_SECRET_KEY=sk_test_u26zru8XDoaAjZLLA1YjU9rA');

// Returned by PayMongo when you register the webhook (starts with whsk_).
// Not needed for local testing — PayMongo can't reach localhost anyway, so
// templates/success.php confirms payments locally instead.
putenv('PAYMONGO_WEBHOOK_SECRET=whsk_REPLACE_LATER');

// Where PayMongo sends the customer back after paying (no trailing slash).
// Local: http://localhost/BLOOM   |   Hostinger: https://your-domain.com
putenv('APP_URL=http://localhost/BLOOM');