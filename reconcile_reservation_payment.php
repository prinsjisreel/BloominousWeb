<?php
/**
 * BLOOMINOUS - Reservation Deposit Reconciler
 *
 * "Did this reservation's GCash/Maya deposit get paid? If yes, record it."
 *
 * Works WITHOUT the PayMongo webhook: it asks PayMongo directly (using the
 * server's secret key) for the checkout session and, if a payment is 'paid',
 * writes it to the reservation ledger through the same routine the webhook
 * uses. The browser/app only ever sends a reservation id — never an amount —
 * so nobody can fake a payment through this endpoint.
 *
 * Who may call it:
 *   - Staff / admin  : logged-in PHP session (reservation.php uses this)
 *   - The customer   : Firebase ID token in "Authorization: Bearer ..." and
 *                      the token's uid must equal reservation.customer_uid
 *
 * Request  : POST {"reservation_id": "abc123"}
 * Response : {"success": true, "outcome": "paid|already_recorded|not_paid|..."}
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/includes/firestore_rest.php';
require_once __DIR__ . '/includes/payment_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input = bloom_json_input();
$reservationId = (string) ($input['reservation_id'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $reservationId)) {
    bloom_json_response(['success' => false, 'message' => 'Invalid reservation id'], 400);
}

try {
    $reservation = bloom_firestore_get_document_rest('reservations', $reservationId);
    if ($reservation === null) {
        bloom_json_response(['success' => false, 'message' => 'Reservation not found'], 404);
    }

    // ---- Who is asking? ----
    $isStaff = !empty($_SESSION['admin_id']);
    $isOwner = false;

    if (!$isStaff) {
        $token = bloom_get_bearer_token();
        if ($token === null) {
            bloom_json_response(['success' => false, 'message' => 'Not authorized'], 401);
        }
        try {
            $uid = bloom_verify_id_token($token);
        } catch (\Throwable $e) {
            bloom_json_response(['success' => false, 'message' => 'Invalid or expired login'], 401);
        }
        $isOwner = ($reservation['customer_uid'] ?? '') === $uid;
    }

    if (!$isStaff && !$isOwner) {
        bloom_json_response(['success' => false, 'message' => 'Not authorized for this reservation'], 403);
    }

    // ---- Is there a PayMongo session to check? ----
    $sessionId = (string) ($reservation['checkout_session_id'] ?? '');
    if ($sessionId === '') {
        bloom_json_response(['success' => true, 'outcome' => 'no_online_payment']);
    }

    // Ask PayMongo for the truth, then record it (idempotent).
    $session = PaymentHelper::retrieveCheckoutSession($sessionId);
    $outcome = PaymentHelper::finalizeReservationFromSession($reservationId, $session);

    bloom_json_response(['success' => true, 'outcome' => $outcome]);
} catch (\Throwable $e) {
    error_log('reconcile_reservation_payment failed for ' . $reservationId . ': ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'Could not verify the payment right now.'], 500);
}