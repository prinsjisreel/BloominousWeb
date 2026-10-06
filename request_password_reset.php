<?php
/**
 * BLOOMINOUS - Request a Password Reset (web + app)
 *
 *   POST /request_password_reset.php
 *   Body: { "email": "customer@example.com" }
 *
 *   200 { success: true, message }    ALWAYS the same for any valid email,
 *                                     whether or not an account exists
 *   400 { success: false, message }   malformed email
 *   429 { success: false, code: 'RATE_LIMITED', message }
 *   502 { success: false, message }   link or email could not be created/sent
 *
 * HOW IT STAYS SAFE:
 *   - The reset LINK is created by Firebase (Admin SDK). It is secret,
 *     single-use, expires, and the new password is typed on Firebase's own
 *     page. Nothing here can change a password.
 *   - The link is only ever emailed to the address it belongs to; it is
 *     never returned to the caller.
 *   - Same response for known and unknown emails, so this can't be used to
 *     find out who has an account.
 *   - Rate-limited per IP and per email (reuses rate_limiter.php), so it
 *     can't be used to flood inboxes or burn the shop's Gmail sending quota.
 *
 * Replaces the old browser-generated OTP flow (customer_otps +
 * send_recovery_email.php + update_firebase_password.php), which let
 * anyone reset any account's password.
 */

require_once __DIR__ . '/includes/firebase_admin.php';
require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/mailer_config.php';

ini_set('display_errors', '0');

// Where Firebase's "Continue" button sends the customer after they choose
// a new password. Its domain must be listed in Firebase Console →
// Authentication → Settings → Authorized domains (if not, the email is
// still sent, just without that button — see the fallback below).
const BLOOM_RESET_CONTINUE_URL = 'https://honeydew-duck-132160.hostingersite.com/index.php';

// Rate limits (window in seconds).
const BLOOM_RESET_LIMIT_PER_IP = 10;          // per network, per 15 minutes
const BLOOM_RESET_IP_WINDOW = 900;
const BLOOM_RESET_LIMIT_PER_EMAIL = 3;        // per inbox, per hour
const BLOOM_RESET_EMAIL_WINDOW = 3600;

function bloom_reset_generic_success(): void
{
    bloom_json_response([
        'success' => true,
        'message' => "If an account exists for that email, we've sent a password reset link.",
    ]);
}

function bloom_reset_rate_limited(string $message): void
{
    bloom_json_response(['success' => false, 'code' => 'RATE_LIMITED', 'message' => $message], 429);
}

/** True when Firebase says there is no account for this email. */
function bloom_reset_is_unknown_user(\Throwable $e): bool
{
    return $e instanceof \Kreait\Firebase\Exception\Auth\UserNotFound
        || preg_match('/EMAIL_NOT_FOUND|USER_NOT_FOUND/i', $e->getMessage()) === 1;
}

/**
 * Asks Firebase for a password-reset link. If Firebase rejects only the
 * "continue" URL (domain not authorized), retries without it.
 */
function bloom_reset_create_link(string $email): string
{
    $auth = bloom_auth();
    try {
        return $auth->getPasswordResetLink($email, ['continueUrl' => BLOOM_RESET_CONTINUE_URL]);
    } catch (\Throwable $e) {
        if (preg_match('/CONTINUE_URI|UNAUTHORIZED_DOMAIN/i', $e->getMessage()) === 1) {
            error_log('request_password_reset.php: continue URL rejected, sending link without it: ' . $e->getMessage());
            return $auth->getPasswordResetLink($email);
        }
        throw $e;
    }
}

/** The branded email body, same look as the device-verification email. */
function bloom_reset_email_html(string $link): string
{
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    return "
    <div style='font-family: \"Poppins\", \"Inter\", sans-serif; background: #FFFDF7; padding: 40px; color: #363949; max-width: 500px; margin: 0 auto; border-radius: 30px; border: 1px solid #f0f0f0;'>
        <div style='text-align: center; font-size: 24px; font-weight: 900; letter-spacing: 4px; color: #F59E0B; margin-bottom: 20px;'>BLOOM</div>
        <h2 style='text-align: center; font-weight: 800; margin-bottom: 10px;'>Reset Your Password</h2>
        <p style='text-align: center; color: #7d8da1; font-size: 13px; margin-bottom: 30px; line-height: 1.6;'>
            We received a request to reset the password for your BLOOM account. Tap the button below to choose a new one.
        </p>
        <div style='text-align: center; margin-bottom: 30px;'>
            <a href='{$safeLink}' style='display: inline-block; background: #F59E0B; color: #ffffff; text-decoration: none; font-weight: 900; letter-spacing: 2px; text-transform: uppercase; font-size: 13px; padding: 16px 32px; border-radius: 20px;'>Reset Password</a>
        </div>
        <p style='text-align: center; color: #b2bec3; font-size: 11px; line-height: 1.6;'>
            This link works once and expires in about an hour.<br>
            If you didn't ask for this, you can ignore this email — your password stays the same.
        </p>
    </div>";
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

// --- 1. Per-IP limit: stops one machine from spamming many inboxes. ---
if (!bloom_check_and_record_attempt('password_reset_ip', bloom_get_client_ip(), BLOOM_RESET_LIMIT_PER_IP, BLOOM_RESET_IP_WINDOW)) {
    bloom_reset_rate_limited('Too many reset requests. Please wait a few minutes and try again.');
}

// --- 2. Validate the email. ---
$body = bloom_json_input();
$email = strtolower(trim((string) ($body['email'] ?? '')));

if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bloom_json_response(['success' => false, 'message' => 'Please enter a valid email address.'], 400);
}

// --- 3. Per-email limit: stops one inbox from being flooded. Applies to
// every email the same way, so it reveals nothing about which exist.
if (!bloom_check_and_record_attempt('password_reset_email', $email, BLOOM_RESET_LIMIT_PER_EMAIL, BLOOM_RESET_EMAIL_WINDOW)) {
    bloom_reset_rate_limited('A reset link was already sent to this email recently. Please check your inbox and spam folder, or try again later.');
}

// --- 4. Ask Firebase for the secure link. ---
try {
    $link = bloom_reset_create_link($email);
} catch (\Throwable $e) {
    if (bloom_reset_is_unknown_user($e)) {
        bloom_reset_generic_success(); // same answer as a real account
    }
    error_log('request_password_reset.php: could not create reset link: ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'We could not send the reset link right now. Please try again shortly.'], 502);
}

// --- 5. Email it from the shop's own address. ---
try {
    bloom_send_mail($email, 'BLOOM - Reset Your Password', bloom_reset_email_html($link));
} catch (\Throwable $e) {
    error_log('request_password_reset.php: could not send reset email: ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'We could not send the reset link right now. Please try again shortly.'], 502);
}

bloom_reset_generic_success();