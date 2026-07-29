<?php
/**
 * BLOOMINOUS - Custom Email Verification Sender
 *
 * Replaces the client-side `user.sendEmailVerification()` call. That default
 * Firebase flow sends from a generic noreply@<project>.firebaseapp.com
 * address using Firebase's plain template, and the link it generates points
 * through https://<project>.firebaseapp.com/__/auth/action - a domain family
 * (firebaseapp.com / web.app) that gets flagged by Safe Browsing / mail
 * filters often enough (widely abused for phishing) that a legitimate link
 * can still trigger a browser warning.
 *
 * This generates the SAME underlying Firebase verification token via the
 * Admin SDK, but with handleCodeInApp=true and a continueUrl pointing at
 * OUR OWN domain (verify_email.php). That means the link the person actually
 * clicks goes straight to your own site - verify_email.php then finishes the
 * verification client-side with applyActionCode(). We send that link
 * ourselves via bloom_send_mail() (your own configured address), styled to
 * match the rest of BLOOMINOUS's emails (same pattern as send_device_otp.php).
 */

require_once __DIR__ . '/includes/firebase_admin.php';
require_once __DIR__ . '/includes/mailer_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bloom_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$idToken = bloom_get_bearer_token() ?? ($_POST['idToken'] ?? null);
if (!$idToken) {
    bloom_json_response(['success' => false, 'message' => 'Missing ID token'], 401);
}

try {
    $uid = bloom_verify_id_token($idToken);
} catch (\Throwable $e) {
    bloom_json_response(['success' => false, 'message' => 'Invalid or expired session.'], 401);
}

try {
    $userRecord = bloom_auth()->getUser($uid);
} catch (\Throwable $e) {
    bloom_json_response(['success' => false, 'message' => 'Account not found.'], 404);
}

$email = $userRecord->email ?? null;
if (!$email) {
    bloom_json_response(['success' => false, 'message' => 'No email on file for this account.'], 400);
}

if ($userRecord->emailVerified) {
    bloom_json_response(['success' => true, 'message' => 'This email is already verified.']);
}

// Build the continue URL from the request itself, so this works on
// whatever domain it's actually deployed on without hardcoding one.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$continueUrl = $scheme . '://' . $host . '/verify_email.php';

try {
    $link = bloom_auth()->getEmailVerificationLink($email, [
        'url' => $continueUrl,
        'handleCodeInApp' => true, // keeps the whole flow on OUR domain, not firebaseapp.com
    ]);
} catch (\Throwable $e) {
    error_log('getEmailVerificationLink failed for ' . $email . ': ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'Could not generate verification link. Try again shortly.'], 502);
}

try {
    bloom_send_mail(
        $email,
        'BLOOMINOUS - Confirm Your Email Address',
        "
        <div style='font-family: \"Poppins\", \"Inter\", sans-serif; background: #FFFDF7; padding: 40px; color: #363949; max-width: 500px; margin: 0 auto; border-radius: 30px; border: 1px solid #f0f0f0;'>
            <div style='text-align: center; font-size: 24px; font-weight: 900; letter-spacing: 4px; color: #F59E0B; margin-bottom: 20px;'>BLOOM</div>
            <h2 style='text-align: center; font-weight: 800; margin-bottom: 10px;'>Confirm Your Email</h2>
            <p style='text-align: center; color: #7d8da1; font-size: 13px; margin-bottom: 30px;'>Welcome to BLOOMINOUS! Please confirm this is your email address to activate your account.</p>
            <div style='text-align: center; margin-bottom: 20px;'>
                <a href='" . htmlspecialchars($link, ENT_QUOTES) . "' style='display: inline-block; background: #F59E0B; color: #ffffff; text-decoration: none; font-weight: 700; padding: 14px 36px; border-radius: 999px; font-size: 15px;'>Verify My Email</a>
            </div>
            <p style='text-align: center; color: #b2bec3; font-size: 11px; margin-top: 30px; line-height: 1.6;'>If you didn't create a BLOOMINOUS account, you can safely ignore this email. This link expires after a short time - if it stops working, just request a new one from the login page.</p>
        </div>
        "
    );
} catch (\Throwable $e) {
    error_log('bloom_send_mail (verify email) failed: ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'Could not send verification email. Try again shortly.'], 502);
}

bloom_json_response(['success' => true, 'message' => 'Verification email sent.']);