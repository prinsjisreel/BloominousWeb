<?php
/**
 * BLOOMINOUS - 3D Realism: Step 1 (Submit)
 *
 * Receives the admin's uploaded flower photo and submits it to Hyper3D's
 * Rodin API. This is the ONLY file that ever touches the real Hyper3D
 * API key -- the browser only ever talks to THIS endpoint, never to
 * Hyper3D directly. Same "client never holds the secret" pattern as
 * PaymentService and the Firebase Admin SDK calls elsewhere in this app.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// ============================================================
// PASTE YOUR REAL HYPER3D API KEY HERE. Never commit the real
// value to source control -- treat this file the same way you'd
// treat payment_service.php's secret key constant.
// ============================================================
define('HYPER3D_API_KEY', 'PASTE_YOUR_HYPER3D_API_KEY_HERE');
// ============================================================

if (HYPER3D_API_KEY === 'PASTE_YOUR_HYPER3D_API_KEY_HERE') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Hyper3D API key has not been configured on the server yet.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No image file was received.']);
    exit();
}

$uploadedFile = $_FILES['image'];
if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Upload failed (error code ' . $uploadedFile['error'] . ').']);
    exit();
}

// 10MB cap -- generous for a phone/camera photo, small enough to fail
// fast on an accidental huge file rather than tying up the request.
if ($uploadedFile['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Image is too large (max 10MB).']);
    exit();
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
$mimeType = mime_content_type($uploadedFile['tmp_name']);
if (!in_array($mimeType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please upload a JPEG, PNG, or WEBP image.']);
    exit();
}

try {
    $curlFile = new CURLFile($uploadedFile['tmp_name'], $mimeType, $uploadedFile['name']);

    $ch = curl_init('https://api.hyper3d.com/api/v2/rodin');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . HYPER3D_API_KEY,
        ],
        // Gen-2.5-Medium is a reasonable default balance of quality vs.
        // generation time for a product-catalog flower model -- not a
        // hard requirement, just a sensible starting tier.
        CURLOPT_POSTFIELDS => [
            'images' => $curlFile,
            'tier' => 'Gen-2.5-Medium',
            'geometry_file_format' => 'glb',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new Exception('Could not reach Hyper3D: ' . $curlError);
    }

    $data = json_decode($responseBody, true);

    // Hyper3D returns HTTP 201 on successful submission, with a
    // top-level uuid and jobs.subscription_key -- NOT a plain 200.
    if (($httpCode !== 200 && $httpCode !== 201) || !empty($data['error']) || empty($data['uuid'])) {
        $errMsg = $data['error'] ?? $data['message'] ?? ('HTTP ' . $httpCode);
        throw new Exception('Hyper3D rejected the submission: ' . $errMsg);
    }

    $subscriptionKey = $data['jobs']['subscription_key'] ?? null;
    if (!$subscriptionKey) {
        throw new Exception('Hyper3D did not return a subscription key to poll.');
    }

    echo json_encode([
        'success' => true,
        'uuid' => $data['uuid'],
        'subscriptionKey' => $subscriptionKey,
    ]);
} catch (\Throwable $e) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}