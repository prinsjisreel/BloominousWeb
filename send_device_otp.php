<?php
/**
 * BLOOMINOUS - New Device Email Step-Up (send code)
 *
 * Called by index.php's login flow when set_session.php responds with
 * code 'DEVICE_VERIFICATION_REQUIRED': the signed-in-with-Firebase user is
 * on a device we've never seen before AND the account is already flagged
 * (isRestricted or fraudScore >= 50). We email a 6-digit code to the
 * account's own address (from the verified ID token, never from the
 * request body) before letting the session proceed.
 */

require_once __DIR__ . '/includes/firebase_admin.php';
require_once __DIR__ . '/includes/mailer_config.php';

use Google\Cloud\Firestore\FieldValue;

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

$deviceHash = (string) ($_POST['deviceHash'] ?? '');
if ($deviceHash === '' || !preg_match('/^[a-f0-9]{64}$/', $deviceHash)) {
    bloom_json_response(['success' => false, 'message' => 'Missing or malformed device signature.'], 400);
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

$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = new DateTimeImmutable('+10 minutes');

$db = bloom_firestore();
$db->collection('customer_otps')->document(strtolower($email))->set([
    'uid' => $uid,
    'code' => $code,
    'deviceHash' => $deviceHash,
    'purpose' => 'device_verification',
    'expiresAt' => $expiresAt,
    'createdAt' => FieldValue::serverTimestamp(),
]);

try {
    bloom_send_mail(
        $email,
        'BLOOMINOUS - Confirm This New Device',
        "
        <div style='font-family: \"Poppins\", \"Inter\", sans-serif; background: #FFFDF7; padding: 40px; color: #363949; max-width: 500px; margin: 0 auto; border-radius: 30px; border: 1px solid #f0f0f0;'>
            <div style='text-align: center; font-size: 24px; font-weight: 900; letter-spacing: 4px; color: #F59E0B; margin-bottom: 20px;'>BLOOM</div>
            <h2 style='text-align: center; font-weight: 800; margin-bottom: 10px;'>Confirm This Device</h2>
            <p style='text-align: center; color: #7d8da1; font-size: 13px; margin-bottom: 30px;'>We noticed a sign-in from a device we don't recognize on this account. Enter this code to continue.</p>
            <div style='background: #fafafa; border: 2px dashed #F59E0B; padding: 20px; border-radius: 20px; text-align: center; font-size: 32px; font-weight: 900; letter-spacing: 8px; color: #363949;'>
                $code
            </div>
            <p style='text-align: center; color: #b2bec3; font-size: 11px; margin-top: 30px; line-height: 1.6;'>If this wasn't you, do not share this code with anyone, and consider changing your password.</p>
        </div>
        "
    );
} catch (\Throwable $e) {
    error_log('bloom_send_mail (device otp) failed: ' . $e->getMessage());
    bloom_json_response(['success' => false, 'message' => 'Could not send verification email. Try again shortly.'], 502);
}

bloom_json_response(['success' => true, 'message' => 'Verification code sent to your email.']);