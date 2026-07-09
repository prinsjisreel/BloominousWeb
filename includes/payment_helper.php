<?php
/**
 * BLOOMINOUS - PayMongo Payment Helper
 * Handles interaction with the PayMongo API for checkout sessions.
 */

class PaymentHelper {
    private static $secretKey = null;

    private static function getSecretKey() {
        if (self::$secretKey === null) {
            // Fallback to the test key if env is not set
            self::$secretKey = getenv('PAYMONGO_SECRET_KEY') ?: 'sk_test_u26zru8XDoaAjZLLA1YjU9rA';
        }
        return self::$secretKey;
    }

    public static function createCheckoutSession($amount, $description, $customerName, $customerEmail) {
        $url = "https://api.paymongo.com/v1/checkout_sessions";
        
        // PayMongo amounts are in cents
        $amountInCents = intval($amount * 100);
        
        $baseUrl = getenv('APP_URL') ?: 'https://' . $_SERVER['HTTP_HOST'];

        $data = [
            'data' => [
                'attributes' => [
                    'send_email_receipt' => true,
                    'show_description' => true,
                    'show_line_items' => true,
                    'description' => $description,
                    'line_items' => [
                        [
                            'currency' => 'PHP',
                            'amount' => $amountInCents,
                            'description' => $description,
                            'name' => 'Bloom Bouquet Order',
                            'quantity' => 1
                        ]
                    ],
                    'payment_method_types' => ['gcash', 'paymaya', 'card', 'dob', 'grab_pay'],
                    'success_url' => $baseUrl . '/templates/success.php',
                    'cancel_url' => $baseUrl . '/templates/cancel.php'
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(self::getSecretKey() . ':')
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 || $httpCode === 201) {
            $result = json_decode($response, true);
            return $result['data']['attributes']['checkout_url'];
        } else {
            $error = json_decode($response, true);
            $errorMsg = isset($error['errors'][0]['detail']) ? $error['errors'][0]['detail'] : 'Unknown Payment Error';
            throw new Exception("Payment Error: " . $errorMsg);
        }
    }
}
?>
