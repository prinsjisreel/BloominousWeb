<?php
/**
 * BLOOMINOUS - 3D Realism: Step 1 (Submit to Tripo v3)
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

$configPath = __DIR__ . '/config.local.php';
if (!file_exists($configPath)) {
    fail('Missing config.local.php');
}
require_once $configPath;

$apiKey = getenv('TRIPO_API_KEY');
if (!$apiKey) {
    fail('TRIPO_API_KEY is empty.');
}
if (strpos($apiKey, 'tcli_') === 0) {
    fail('Use your Secret API Key (tsk_), not Client ID.');
}

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

try {
    // STEP 1: Upload image to Tripo v3
    $curlFile = new CURLFile($file['tmp_name'], $mime, basename($file['name']));
    
    $ch = curl_init('https://openapi.tripo3d.ai/v3/files');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => ['file' => $curlFile],
        CURLOPT_TIMEOUT => 60,
    ]);

    $uploadBody = curl_exec($ch);
    $uploadStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($uploadBody === false || $uploadStatus !== 200) {
        throw new Exception('Tripo v3 upload failed (HTTP ' . $uploadStatus . '): ' . $uploadBody);
    }

    $uploadData = json_decode($uploadBody, true);
    // v3 returns file_token
    $fileToken = $uploadData['data']['file_token'] ?? null;
    if (!$fileToken) {
        throw new Exception('Tripo v3 did not return a file_token. Response: ' . $uploadBody);
    }

    // STEP 2: Generate 3D Model with v3.1
    $payload = [
        'input' => $fileToken, // v3 uses 'input' instead of 'file'
        'model' => 'v3.1-20260211', // The latest model you wanted
        'texture' => true,
        'pbr' => true
    ];

    $ch = curl_init('https://openapi.tripo3d.ai/v3/generation/image-to-model');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ]);

    $taskBody = curl_exec($ch);
    $taskStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($taskBody === false || $taskStatus !== 200) {
        throw new Exception('Tripo v3 generation rejected (HTTP ' . $taskStatus . '): ' . $taskBody);
    }

    $taskData = json_decode($taskBody, true);
    $taskId = $taskData['data']['task_id'] ?? null;
    if (!$taskId) {
        throw new Exception('Tripo v3 did not return a task ID. Response: ' . $taskBody);
    }

    echo json_encode([
        'success' => true,
        'taskId' => $taskId,
        'message' => 'Generation task submitted to v3 API.'
    ]);

} catch (Throwable $e) {
    error_log('Tripo v3 generation error: ' . $e->getMessage());
    fail($e->getMessage(), 502);
}