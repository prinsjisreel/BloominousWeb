<?php
/**
 * BLOOMINOUS - PayMongo Payment Helper (GCash / Maya)
 *
 * The ONE place in BloominousWeb that talks to PayMongo. Everything else
 * (create_payment_session.php, paymongo_webhook.php, templates/success.php)
 * goes through this class, so both web and app share one set of rules.
 *
 * Flow in one sentence: submit_order.php creates the order (paymentStatus
 * 'Pending') -> create_payment_session.php asks PayMongo for a hosted
 * GCash/Maya page -> the customer pays there -> PayMongo calls
 * paymongo_webhook.php -> finalizeOrderFromSession() marks the order 'Paid'.
 *
 * Secrets are read from environment variables ONLY (no fallback key in code):
 *   PAYMONGO_SECRET_KEY      sk_test_... / sk_live_...
 *   PAYMONGO_WEBHOOK_SECRET  whsk_...  (returned when you register the webhook)
 *   APP_URL                  e.g. https://yourdomain.com (no trailing slash)
 * For local/shared hosting, set them in includes/payment-local-config.php
 * (gitignored) — same pattern as mailer-local-config.php.
 *
 * Firestore access here uses the REST toolkit (includes/firestore_rest.php),
 * not the gRPC client, so it runs on local XAMPP as well as on Hostinger.
 */

require_once __DIR__ . '/firestore_rest.php';

if (file_exists(__DIR__ . '/payment-local-config.php')) {
    require_once __DIR__ . '/payment-local-config.php';
}

class PaymentHelper
{
    const API_BASE = 'https://api.paymongo.com/v1';

    // PayMongo rejects checkout amounts below PHP 20.00 (2000 centavos).
    const MIN_CENTAVOS = 2000;

    // Bloominous name  =>  PayMongo name. This map is the allow-list:
    // anything not listed here can never be sent to PayMongo.
    const METHOD_MAP = [
        'gcash' => 'gcash',
        'maya'  => 'paymaya',
    ];

    /**
     * Turns whatever the UI sent ("GCash", " maya ", "PayMaya") into our
     * canonical lowercase name ("gcash" / "maya").
     */
    public static function normalizeMethod(?string $method): string
    {
        $m = strtolower(trim((string) $method));
        return $m === 'paymaya' ? 'maya' : $m;
    }

    public static function isOnlineMethod(?string $method): bool
    {
        return array_key_exists(self::normalizeMethod($method), self::METHOD_MAP);
    }

    /**
     * Pesos -> centavos. round() first, THEN cast: 199.99 * 100 is really
     * 19998.999999... in floating point, and a plain (int) cast would
     * silently chop it down to 19998.
     */
    public static function toCentavos(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public static function baseUrl(): string
    {
        $url = getenv('APP_URL') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return rtrim($url, '/');
    }

    private static function secretKey(): string
    {
        $key = getenv('PAYMONGO_SECRET_KEY');
        if (!$key) {
            throw new RuntimeException('PAYMONGO_SECRET_KEY is not configured on this server.');
        }
        return $key;
    }

    /**
     * Low-level HTTP call to PayMongo. Throws on network failure or any
     * non-2xx response, carrying PayMongo's own error message.
     */
    private static function request(string $httpMethod, string $path, ?array $payload = null): array
    {
        $ch = curl_init(self::API_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $httpMethod,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                // PayMongo uses Basic auth: secret key as username, empty password.
                'Authorization: Basic ' . base64_encode(self::secretKey() . ':'),
            ],
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Could not reach PayMongo: ' . $curlError);
        }

        $json = json_decode($raw, true) ?? [];
        if ($httpCode < 200 || $httpCode >= 300) {
            $detail = $json['errors'][0]['detail'] ?? ('HTTP ' . $httpCode);
            throw new RuntimeException('PayMongo error: ' . $detail);
        }
        return $json;
    }

    /**
     * Creates a hosted PayMongo checkout page limited to ONE wallet
     * (the one the customer picked on our checkout page).
     *
     * $order keys: orderId, invoiceId, amount (pesos, from Firestore — never
     * from the browser), method, customerName, email, phone.
     *
     * Returns ['id' => 'cs_...', 'checkoutUrl' => 'https://checkout.paymongo.com/...'].
     */
    public static function createCheckoutSession(array $order): array
    {
        $method = self::normalizeMethod($order['method'] ?? '');
        if (!self::isOnlineMethod($method)) {
            throw new InvalidArgumentException('Unsupported online payment method.');
        }

        $centavos = self::toCentavos((float) ($order['amount'] ?? 0));
        if ($centavos < self::MIN_CENTAVOS) {
            throw new InvalidArgumentException('Online payments require a total of at least PHP 20.00.');
        }

        $base = self::baseUrl();
        $orderIdParam = rawurlencode($order['orderId']);
        $invoiceId = $order['invoiceId'] ?: $order['orderId'];

        $attributes = [
            'send_email_receipt'   => !empty($order['email']),
            'show_description'     => true,
            'show_line_items'      => true,
            'description'          => 'BLOOM Order ' . $invoiceId,
            'reference_number'     => $invoiceId,
            'line_items'           => [[
                'currency' => 'PHP',
                'amount'   => $centavos,
                'name'     => 'Bloom Order ' . $invoiceId,
                'quantity' => 1,
            ]],
            'payment_method_types' => [self::METHOD_MAP[$method]],
            'success_url'          => $base . '/templates/success.php?order_id=' . $orderIdParam,
            'cancel_url'           => $base . '/templates/cancel.php?order_id=' . $orderIdParam,
            // metadata is echoed back to us in the webhook — this is how the
            // webhook knows WHICH Firestore order a payment belongs to.
            'metadata'             => [
                'order_id'   => (string) $order['orderId'],
                'invoice_id' => (string) $invoiceId,
                'source'     => 'BloominousWeb',
            ],
        ];

        // Pre-fill the PayMongo page. array_filter drops empty values so we
        // never send PayMongo a blank email/phone it would reject.
        $billing = array_filter([
            'name'  => $order['customerName'] ?? '',
            'email' => $order['email'] ?? '',
            'phone' => $order['phone'] ?? '',
        ]);
        if (!empty($billing)) {
            $attributes['billing'] = $billing;
        }

        $json = self::request('POST', '/checkout_sessions', ['data' => ['attributes' => $attributes]]);

        return [
            'id'          => $json['data']['id'],
            'checkoutUrl' => $json['data']['attributes']['checkout_url'],
        ];
    }

    /**
     * Returns the full checkout session resource ({id, type, attributes}).
     */
    public static function retrieveCheckoutSession(string $sessionId): array
    {
        $json = self::request('GET', '/checkout_sessions/' . rawurlencode($sessionId));
        return $json['data'] ?? [];
    }

    /**
     * Looks inside a checkout session for a payment whose status is 'paid'.
     * Returns ['paymentId', 'centavos', 'channel'] or null if nothing paid yet.
     */
    public static function extractPaidPayment(array $session): ?array
    {
        $payments = $session['attributes']['payments'] ?? [];
        foreach ($payments as $payment) {
            if (($payment['attributes']['status'] ?? '') === 'paid') {
                return [
                    'paymentId' => (string) ($payment['id'] ?? ''),
                    'centavos'  => (int) ($payment['attributes']['amount'] ?? 0),
                    'channel'   => (string) ($payment['attributes']['source']['type'] ?? ''),
                ];
            }
        }
        return null;
    }

    /**
     * Checks the Paymongo-Signature header ("t=...,te=...,li=...") so a
     * stranger can't POST a fake "payment paid" event to our webhook.
     * te = signature in test mode, li = signature in live mode.
     */
    public static function verifyWebhookSignature(string $rawBody, string $header, string $secret): bool
    {
        if ($header === '' || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }
        if (empty($parts['t'])) {
            return false;
        }

        $expected = hash_hmac('sha256', $parts['t'] . '.' . $rawBody, $secret);

        foreach (['te', 'li'] as $slot) {
            if (!empty($parts[$slot]) && hash_equals($expected, $parts[$slot])) {
                return true;
            }
        }
        return false;
    }

    /**
     * THE shared "mark this order paid" routine — used by BOTH the webhook
     * and the success-page fallback, so the rules can never drift apart.
     *
     * Idempotent: calling it twice (webhook retry + success page, or two
     * webhook deliveries) only ever marks the order Paid once.
     *
     * Returns one of: not_paid | order_missing | session_mismatch |
     * already_paid | paid | amount_mismatch | paid_after_cancel
     */
    public static function finalizeOrderFromSession(string $orderId, array $session): string
    {
        $paid = self::extractPaidPayment($session);
        if ($paid === null) {
            return 'not_paid';
        }

        $sessionId = (string) ($session['id'] ?? '');

        $result = bloom_firestore_transaction_rest(function (BloomRestTransaction $tx) use ($orderId, $sessionId, $paid) {
            $order = $tx->get('orders', $orderId);
            if ($order === null) {
                return ['outcome' => 'order_missing'];
            }

            // The session must be one WE created for THIS order.
            $knownSessions = is_array($order['paymongoCheckoutIds'] ?? null) ? $order['paymongoCheckoutIds'] : [];
            if (!empty($order['paymongoCheckoutId'])) {
                $knownSessions[] = $order['paymongoCheckoutId'];
            }
            if ($sessionId === '' || !in_array($sessionId, $knownSessions, true)) {
                return ['outcome' => 'session_mismatch'];
            }

            if (($order['paymentStatus'] ?? '') === 'Paid') {
                return ['outcome' => 'already_paid'];
            }

            $expectedCentavos = self::toCentavos((float) ($order['total_price'] ?? 0));
            $currentStatus = strtolower((string) ($order['status'] ?? 'pending'));

            // Plain key => value pairs; only these fields change on the order.
            $update = [
                'paymentStatus'     => 'Paid',
                'paidAt'            => bloom_rest_now(),
                'amountPaid'        => $paid['centavos'] / 100,
                'paymongoPaymentId' => $paid['paymentId'],
                'paymentChannel'    => $paid['channel'],
            ];

            if ($paid['centavos'] !== $expectedCentavos) {
                // Money arrived, but not the amount we expected — a human decides.
                $update['paymentStatus'] = 'Needs Review';
                $update['paymentIssue'] = 'amount_mismatch';
                $outcome = 'amount_mismatch';
            } elseif ($currentStatus === 'cancelled') {
                // Customer cancelled, but still paid in another tab — keep it
                // cancelled and flag it so staff can refund.
                $update['paymentIssue'] = 'paid_after_cancel';
                $outcome = 'paid_after_cancel';
            } else {
                if ($currentStatus === 'pending') {
                    $update['status'] = 'processing';
                }
                $outcome = 'paid';
            }

            $tx->update('orders', $orderId, $update);

            return [
                'outcome'   => $outcome,
                'invoiceId' => $order['invoiceId'] ?? $orderId,
                'branchId'  => $order['branchId'] ?? null,
                'name'      => $order['customer_name'] ?? 'a customer',
                'amount'    => $paid['centavos'] / 100,
                'channel'   => $paid['channel'],
            ];
        });

        // Notifications are written AFTER the transaction commits, so a
        // retried transaction can't create duplicate alerts.
        $outcome = $result['outcome'];
        if (in_array($outcome, ['paid', 'amount_mismatch', 'paid_after_cancel'], true)) {
            $channelLabel = strtoupper($result['channel'] ?: 'e-wallet');
            $titles = [
                'paid'              => 'Online Payment Received',
                'amount_mismatch'   => 'Payment Needs Review - Amount Mismatch',
                'paid_after_cancel' => 'Payment Received on Cancelled Order - Refund Needed',
            ];
            bloom_firestore_add_document_rest('notifications', [
                'title'      => $titles[$outcome],
                'message'    => "Order {$result['invoiceId']}: P" . number_format($result['amount'], 2)
                              . " paid via {$channelLabel} by {$result['name']}.",
                'type'       => $outcome === 'paid' ? 'sale' : 'warning',
                'branchId'   => $result['branchId'],
                'created_at' => bloom_rest_now(),
                'read'       => false,
            ]);
        }

        return $outcome;
    }
}   