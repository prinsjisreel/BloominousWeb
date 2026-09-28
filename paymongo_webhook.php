<?php
/**
 * BLOOMINOUS - PayMongo Webhook Receiver
 *
 * PayMongo's servers call this URL (not the customer's browser) the moment
 * a GCash/Maya payment succeeds. This is the SOURCE OF TRUTH for "paid".
 *
 * Register once (see setup notes) for the event:
 *   checkout_session.payment.paid
 *
 * Response codes matter here: 2xx tells PayMongo "got it, stop sending";
 * 5xx tells PayMongo "try again later" (it retries automatically).
 */

require_once __DIR__ . '/includes/firestore_rest.php';
require_once __DIR__ . '/includes/payment_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Read the body EXACTLY as sent — the signature is computed over these
// raw bytes, so we must not json_decode + re-encode before checking it.
$rawBody = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
$webhookSecret = getenv('PAYMONGO_WEBHOOK_SECRET') ?: '';

if ($webhookSecret === '') {
    error_log('paymongo_webhook: PAYMONGO_WEBHOOK_SECRET is not configured.');
    bloom_json_response(['success' => false, 'message' => 'Webhook not configured'], 500);
}

if (!PaymentHelper::verifyWebhookSignature($rawBody, $signatureHeader, $webhookSecret)) {
    bloom_json_response(['success' => false, 'message' => 'Invalid signature'], 401);
}

$event = json_decode($rawBody, true) ?? [];
$eventType = $event['data']['attributes']['type'] ?? '';
$session = $event['data']['attributes']['data'] ?? [];

// We only act on successful checkout payments; acknowledge everything else
// so PayMongo doesn't keep retrying events we don't care about.
if ($eventType !== 'checkout_session.payment.paid') {
    bloom_json_response(['received' => true, 'ignored' => $eventType]);
}

$orderId = (string) ($session['attributes']['metadata']['order_id'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $orderId)) {
    error_log('paymongo_webhook: paid event without a valid order_id in metadata.');
    bloom_json_response(['received' => true, 'ignored' => 'missing_order_id']);
}

try {
    $outcome = PaymentHelper::finalizeOrderFromSession($orderId, $session);
    bloom_json_response(['received' => true, 'outcome' => $outcome]);
} catch (\Throwable $e) {
    // 500 on purpose: Firestore hiccup -> let PayMongo retry later.
    error_log('paymongo_webhook failed for order ' . $orderId . ': ' . $e->getMessage());
    bloom_json_response(['received' => false], 500);
}