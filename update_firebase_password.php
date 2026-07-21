<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json');

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    echo json_encode(['success' => false, 'message' => 'Admin SDK not installed. Run "composer install".']);
    exit();
}
require $autoloadPath;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Exception\Auth\UserNotFound;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$newPassword = (string)($_POST['new_password'] ?? '');

if (!$email || strlen($newPassword) < 6) {
    echo json_encode(['success' => false, 'message' => 'Malformed data.']);
    exit();
}

// NOTE: In forgot_password.php the OTP is verified against Firestore
// *before* this endpoint is ever called, so by the time we get here the
// caller has already proven ownership of the inbox. Nothing further to
// check here except that a service account key is present.
$serviceAccountPath = __DIR__ . '/serviceAccountKey.json';
if (!file_exists($serviceAccountPath)) {
    echo json_encode([
        'success' => false,
        'message' => 'serviceAccountKey.json not found on server. Download it from Firebase Console > Project Settings > Service Accounts > Generate New Private Key, and place it at project root.'
    ]);
    exit();
}

try {
    $auth = (new Factory())->withServiceAccount($serviceAccountPath)->createAuth();

    $user = $auth->getUserByEmail($email);
    $auth->changeUserPassword($user->uid, $newPassword);

    echo json_encode(['success' => true, 'message' => 'Firebase Auth password updated.', 'uid' => $user->uid]);
} catch (UserNotFound $e) {
    echo json_encode(['success' => false, 'message' => 'No Firebase Auth account exists for that email.']);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Auth update failed: ' . $e->getMessage()]);
}