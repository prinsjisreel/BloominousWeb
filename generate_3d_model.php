<?php
/**
 * BLOOMINOUS - 3D Realism: Step 1 (Send the photo to the 3D generator)
 *
 * The ONLY generator is Hyper3D Rodin 2.5, reached through Pixazo.
 * (Tripo has been removed. includes/pixazo_hyper3d.php does all the work.)
 *
 * POST (multipart)  image=<file>
 * 200  { success, taskId, provider: "hyper3d", message }
 *      taskId always starts with "px_".
 * 4xx/5xx { success: false, message }
 *
 * CREDIT SAFETY: a credit is only spent at the very last line of the try block
 * (bloom_px_submit). Everything before it (login check, photo checks, hosting
 * the photo on Supabase) can fail without costing anything.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function fail($message, $status = 500) {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    fail('Not authenticated', 401);
}

// Only READ the session; unlock it so other admin tabs don't wait on this request.
session_write_close();

$configPath = __DIR__ . '/config.local.php';
if (!file_exists($configPath)) {
    fail('Missing config.local.php');
}
require_once $configPath;
require_once __DIR__ . '/includes/pixazo_hyper3d.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['image'])) {
    fail('No image file received.', 400);
}

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    fail('Image upload failed: ' . $file['error'], 400);
}

$mime = mime_content_type($file['tmp_name']);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    fail('Please upload a JPG, PNG, or WEBP image.', 400);
}

// Stop BEFORE doing anything if the key is missing (nothing is charged).
if (!bloom_px_enabled()) {
    fail('PIXAZO_API_KEY is missing from config.local.php, so no 3D model can be generated.', 503);
}

try {
    // 1. Pixazo can only open PUBLIC links, so the photo is hosted on Supabase first (free).
    $imageUrl = bloom_px_image_url($file['tmp_name'], $mime);

    // 2. THIS is the call Pixazo charges for. It returns our "px_..." task id.
    $taskId = bloom_px_submit($imageUrl);

    echo json_encode([
        'success' => true,
        'taskId' => $taskId,
        'provider' => 'hyper3d',
        'message' => 'Generation task submitted to Hyper3D Rodin.'
    ]);
} catch (Throwable $e) {
    error_log('Hyper3D generation error: ' . $e->getMessage());
    fail($e->getMessage(), 502);
}