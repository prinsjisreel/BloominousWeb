<?php
/**
 * BLOOMINOUS - PHP Session Bridge
 *
 * Establishes the PHP session ($_SESSION['user_id'] / ['admin_id'], role,
 * etc.) that the rest of the app's PHP pages gate on.
 *
 * SECURITY: this used to trust $_POST['uid'] / $_POST['role'] directly —
 * anyone could POST role=admin and get every $_SESSION['admin_id']-gated
 * page (admin.php, fraud_analytics.php, manage_accounts.php, ...) to treat
 * them as an administrator, without ever authenticating with Firebase.
 *
 * Now the ONLY accepted proof of identity is a verified Firebase ID token.
 * The uid comes from the token's signature, not from the request body, and
 * the role comes from a server-side Firestore read (the same source of
 * truth the rules themselves use via getRole()) — never from the client.
 */

require_once __DIR__ . '/firebase_admin.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$idToken = bloom_get_bearer_token();
if (!$idToken) {
    // Fall back to a POSTed idToken field too, since this endpoint is
    // called via FormData rather than a JSON body / Authorization header.
    $idToken = $_POST['idToken'] ?? null;
}

if (!$idToken) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Missing ID token']);
    exit();
}

try {
    $uid = bloom_verify_id_token($idToken);
} catch (\Throwable $e) {
    http_response_code(401);
<<<<<<< HEAD
    // TEMP DEBUG — remove getMessage()/getFile()/getLine() once the real
    // cause is found. Never ship exception internals to the client
    // long-term (can leak file paths); this is diagnostic-only.
    error_log('set_session.php verifyIdToken failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired session',
        'debug' => $e->getMessage(), // TEMP — delete this line after debugging
    ]);
=======
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session']);
>>>>>>> e40752bc8317b6e222960ae6c39345e76e216a4e
    exit();
}

// Device recognition (fraud signal only, not primary auth): the client
// always sends its current device hash; deviceOtpVerified is only true
// after verify_device_otp.php has just approved this exact device.
$deviceHash = (string) ($_POST['deviceHash'] ?? '');
$deviceOtpVerified = ($_POST['deviceOtpVerified'] ?? '') === '1';

// Pull the verified token's own claims for email — also signed by Firebase,
// so this is safe to trust, unlike anything read from $_POST.
$firebaseUser = bloom_auth()->getUser($uid);
$email = $firebaseUser->email ?? ($_POST['email'] ?? '');

// Role comes from Firestore, server-side, never from the client. This
// mirrors getRole() in firestore.rules — same source of truth on both sides.
// Uses the plain-REST reader (not bloom_firestore()/FirestoreClient) so
// login works without the PHP grpc extension installed.
$role = 'customer';
$username = $email ? explode('@', $email)[0] : 'User';
$branchId = 'main_branch';

try {
    $userData = bloom_firestore_get_document_rest('users', $uid);
} catch (\Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Could not reach Firestore to resolve role']);
    exit();
}

$customerData = null;

if ($userData !== null) {
    $role = $userData['role'] ?? 'customer';
    $username = $userData['username'] ?? $userData['firstName'] ?? $username;
    $branchId = $userData['branchId'] ?? $branchId;
} else {
    // No management-console profile — check if this uid has a customer
    // profile instead, same fallback the old client-side logic used.
    try {
        $customerData = bloom_firestore_get_document_rest('customers', $uid);
    } catch (\Throwable $e) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Could not reach Firestore to resolve role']);
        exit();
    }
    if ($customerData !== null) {
        $username = $customerData['name'] ?? $customerData['username'] ?? $username;
    }
}

$validRoles = ['customer', 'admin', 'super-admin', 'staff', 'employee', 'delivery'];
if (!in_array($role, $validRoles, true)) {
    $role = 'customer';
}

// --- Email verification gate (customers only, and only for accounts that
// opted into it at signup) -------------------------------------------------
// Only register.php stamps requireEmailVerification: true on new customer
// docs. Accounts created before this feature shipped never have that field
// set, so this deliberately does NOT retroactively lock out your existing
// customer base - it only holds new signups to "you must click the
// confirmation link we emailed you" before they can log in.
if ($role === 'customer') {
    try {
        $fraudCheckData = $customerData ?? bloom_firestore_get_document_rest('customers', $uid);
    } catch (\Throwable $e) {
        $fraudCheckData = null; // fail open on read errors — don't lock users out over a transient Firestore blip
    }

    if ($fraudCheckData !== null
        && ($fraudCheckData['requireEmailVerification'] ?? false) === true
        && !$firebaseUser->emailVerified
    ) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'code' => 'EMAIL_NOT_VERIFIED',
            'message' => 'Please verify your email address before logging in. Check your inbox (and spam folder) for the confirmation link we sent when you registered.',
        ]);
        exit();
    }

    // --- New-device step-up gate (customers only, and only if the account
    // is already flagged) ---------------------------------------------------
    // Deliberately scoped narrow: a normal, un-flagged customer logging in
    // from a new phone/browser is NOT interrupted. This only bites accounts
    // that are already isRestricted or have accumulated a meaningful
    // fraudScore - i.e. exactly the case where "someone else is logging
    // into this account from an unrecognized device" is worth an extra check.
    if ($fraudCheckData !== null) {
        $isFlagged = ($fraudCheckData['isRestricted'] ?? false) === true
            || (int) ($fraudCheckData['fraudScore'] ?? 0) >= 50;
        $knownDevices = $fraudCheckData['deviceHashes'] ?? [];
        $isKnownDevice = $deviceHash !== '' && in_array($deviceHash, $knownDevices, true);

        if ($isFlagged && !$isKnownDevice && !$deviceOtpVerified) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'code' => 'DEVICE_VERIFICATION_REQUIRED',
                'message' => 'This account is flagged for review and this device is not recognized. Verify via the email code we just sent to continue.',
            ]);
            exit();
        }
    }
}

// Passed the gate (or wasn't subject to it) — record this device against
// the account for future logins, regardless of current flag status, so a
// history exists by the time an account does become flagged. Best-effort:
// never block login over this write failing.
if ($role === 'customer' && $deviceHash !== '') {
    try {
        bloom_firestore()->collection('customers')->document($uid)->update([
            ['path' => 'deviceHashes', 'value' => \Google\Cloud\Firestore\FieldValue::arrayUnion([$deviceHash])],
        ]);
    } catch (\Throwable $e) {
        error_log('set_session.php: could not record deviceHash for ' . $uid . ': ' . $e->getMessage());
    }
}

if (in_array($role, ['admin', 'super-admin', 'staff', 'employee'], true)) {
    $_SESSION['admin_id'] = $uid;
    $_SESSION['admin_name'] = $username;
    date_default_timezone_set('Asia/Manila');
    $_SESSION['admin_login_time'] = date('g:i A');
} else {
    $_SESSION['user_id'] = $uid;
    $_SESSION['username'] = $username;
}

$_SESSION['role'] = $role;
$_SESSION['email'] = $email;
$_SESSION['branchId'] = $branchId;

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'role' => $role]);