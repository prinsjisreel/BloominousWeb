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

// ---------------------------------------------------------------------------
// Shutdown tracer — registered FIRST so any fatal below lands in
// set_session_trace.log with file:line. Turns Apache's opaque
// ERR_CONNECTION_RESET into a diagnosable message.
// ---------------------------------------------------------------------------
register_shutdown_function(function () {
    $e = error_get_last();
    $line = $e
        ? "FATAL: {$e['message']} in {$e['file']}:{$e['line']}"
        : 'clean exit';
    @file_put_contents(__DIR__ . '/set_session_trace.log', date('c') . " — {$line}\n", FILE_APPEND);
});

require_once __DIR__ . '/firebase_admin.php';
require_once __DIR__ . '/fraud_activity.php';

// Keep stray PHP notices OUT of this JSON response body — a warning
// printed inline here corrupts the response and breaks response.json()
// on the client.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// These two are computed unconditionally, every request — the forced
// setcookie() call below needs them regardless of whether a session was
// already active or brand new this request.
$bloomIsHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$bloomSessionLifetimeSeconds = 60 * 60 * 24 * 30; // 30 days — stay logged in until actual logout

// Only configure cookie PARAMS if no session is active yet — calling
// session_set_cookie_params() on an already-active session throws a PHP
// warning.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => $bloomSessionLifetimeSeconds,
        'path' => '/',
        'domain' => '',
        'secure' => $bloomIsHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) $bloomSessionLifetimeSeconds);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// FIXED: PHP only sends a Set-Cookie header when a session is first
// CREATED — an existing session cookie is just silently reused with
// whatever expiration it originally had, no matter what gets configured
// afterward. This is THE endpoint that runs right after successful
// login, so forcing a fresh Set-Cookie here — every time, unconditionally
// — is what actually guarantees the browser walks away from a
// successful login holding a correctly-dated 30-day cookie, instead of
// silently keeping whatever it already had.
setcookie(session_name(), session_id(), [
    'expires' => time() + $bloomSessionLifetimeSeconds,
    'path' => '/',
    'domain' => '',
    'secure' => $bloomIsHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

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
    // Server-side diagnostic log only — never echoed to the client.
    // Safe to keep permanently; it's just PHP's error log, not a response body.
    error_log('set_session.php verifyIdToken failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired session',
    ]);
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

    // --- New-device visibility for flagged accounts (customers only) ------
    // Restriction is enforced ONLY at checkout (submit_order.php's
    // RESTRICTED response + checkout's SMS-verification gate), matching
    // mobile, which never blocked login either. A flagged account can
    // always log in and see its own profile/restriction status; it still
    // can't complete an order without phone verification while restricted.
    //
    // The signal isn't thrown away — it's recorded as an admin notification.
    //
    // FRAUD ACTIVITY MODEL: "flagged" used to mean fraudScore >= 50. Fraud
    // scoring has been removed, so it now means: restricted, OR at least
    // one fraud activity recorded on the account. The shared helper in
    // fraud_activity.php owns that definition (submit_order.php uses the
    // same one), including a fallback for older accounts that only have a
    // score from before the change.
    //
    // Written via the REST-based writer only — the gRPC client crashes the
    // entire PHP process natively on this local environment, which is why
    // customer login once failed with ERR_CONNECTION_RESET.
    if ($fraudCheckData !== null) {
        $isFlagged = bloom_customer_is_fraud_flagged($fraudCheckData);
        $knownDevices = $fraudCheckData['deviceHashes'] ?? [];
        $isKnownDevice = $deviceHash !== '' && in_array($deviceHash, $knownDevices, true);

        if ($isFlagged && !$isKnownDevice) {
            try {
                $notifId = bin2hex(random_bytes(10));
                bloom_firestore_set_document_rest('notifications', $notifId, [
                    'title' => 'Flagged Account — New Device Login',
                    'message' => "Account [$uid] ($email) is restricted or has recorded fraud activity, and just logged in from an unrecognized device.",
                    'type' => 'fraud',
                    'branchId' => $branchId,
                    'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                    'read' => false,
                ]);
            } catch (\Throwable $e) {
                error_log('set_session.php: could not log new-device notification for ' . $uid . ': ' . $e->getMessage());
            }
        }
    }
}

// Passed the gate (or wasn't subject to it) — record this device against
// the account for future logins, regardless of current flag status, so a
// history exists by the time an account does become flagged. Best-effort:
// never block login over this write failing.
//
// bloom_firestore_set_document_rest() does a FULL document replace, not
// a partial update — so the existing document is read first and the new
// hash merged into it in PHP, then the COMPLETE document (every field,
// not just deviceHashes) is written back. Skipping this read-merge step
// and writing only {deviceHashes: [...]} would silently delete every
// other field on the customer's document on their very next login.
if ($role === 'customer' && $deviceHash !== '') {
    try {
        $currentCustomerDoc = bloom_firestore_get_document_rest('customers', $uid) ?? [];
        $existingHashes = $currentCustomerDoc['deviceHashes'] ?? [];
        if (!in_array($deviceHash, $existingHashes, true)) {
            $existingHashes[] = $deviceHash;
        }
        $currentCustomerDoc['deviceHashes'] = $existingHashes;
        bloom_firestore_set_document_rest('customers', $uid, $currentCustomerDoc);
    } catch (\Throwable $e) {
        error_log('set_session.php: could not record deviceHash for ' . $uid . ': ' . $e->getMessage());
    }
}

// Same device-hash recording as above, for staff/admin/super-admin/delivery.
// Mirrors the customer block exactly: read-merge-write against the FULL
// document, targeting 'employees/{uid}' since that's where staff profile
// data (and, per the mobile-side fix, device history) lives after the
// users/employees collection split. Also raises the same "Staff Login —
// New Device" notification mobile's _checkAndTrackDevice() does, so both
// platforms produce the identical notification for the identical event.
if (in_array($role, ['admin', 'super-admin', 'staff', 'employee', 'delivery'], true) && $deviceHash !== '') {
    try {
        $currentEmployeeDoc = bloom_firestore_get_document_rest('employees', $uid) ?? [];

        // null (never tracked before) vs. an array (tracked, possibly
        // empty) are distinguished on purpose -- this is what decides
        // whether an unrecognized hash means "brand new account, no
        // history yet" (no notification) or "this account has history,
        // and THIS device isn't part of it" (notification fires).
        $priorHashes = $currentEmployeeDoc['deviceHashes'] ?? null;
        $hasNoDeviceHistoryYet = $priorHashes === null;
        $knownHashesList = is_array($priorHashes) ? $priorHashes : [];
        $isRecognized = in_array($deviceHash, $knownHashesList, true);

        if (!$isRecognized && !$hasNoDeviceHistoryYet) {
            try {
                $notifId = bin2hex(random_bytes(10));
                bloom_firestore_set_document_rest('notifications', $notifId, [
                    'title' => 'Staff Login — New Device',
                    'message' => "$email logged into the admin portal from a device not previously seen on this account.",
                    'type' => 'fraud',
                    'branchId' => $branchId,
                    'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                    'read' => false,
                ]);
            } catch (\Throwable $notifError) {
                error_log('set_session.php: could not log staff new-device notification for ' . $uid . ': ' . $notifError->getMessage());
            }
        }

        if (!$isRecognized) {
            $knownHashesList[] = $deviceHash;
        }
        $currentEmployeeDoc['deviceHashes'] = $knownHashesList;
        // Only fill these in if genuinely missing -- e.g. the very
        // first time this employee ever logs in via web and no
        // 'employees' doc existed at all yet. Never overwrites real
        // profile data (firstName, branchId, etc.) that's already there.
        $currentEmployeeDoc['uid'] = $currentEmployeeDoc['uid'] ?? $uid;
        $currentEmployeeDoc['email'] = $currentEmployeeDoc['email'] ?? $email;
        $currentEmployeeDoc['role'] = $currentEmployeeDoc['role'] ?? $role;
        bloom_firestore_set_document_rest('employees', $uid, $currentEmployeeDoc);
    } catch (\Throwable $e) {
        error_log('set_session.php: could not record employee deviceHash for ' . $uid . ': ' . $e->getMessage());
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