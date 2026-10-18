<?php
/**
 * BLOOMINOUS - 3D Realism: Step 2 (Poll Tripo v3 Status)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

require_once __DIR__ . '/config.local.php';
$apiKey = getenv('TRIPO_API_KEY');

$input = json_decode(file_get_contents('php://input'), true);
$taskId = $input['taskId'] ?? null;

if (!$taskId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing taskId.']);
    exit();
}

try {
    $ch = curl_init('https://openapi.tripo3d.ai/v3/tasks/' . $taskId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 20,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Status check failed (HTTP ' . $httpCode . ')');
    }

    $data = json_decode($responseBody, true);
    
    // v3 response format: { code: 0, data: { status: "...", progress: 50, ... } }
    $status = strtolower($data['data']['status'] ?? 'unknown');
    $progress = $data['data']['progress'] ?? 0;

    echo json_encode([
        'success' => true,
        'done' => ($status === 'success'),
        'failed' => in_array($status, ['failed', 'cancelled']),
        'statusText' => $status,
        'progress' => $progress
    ]);

} catch (\Throwable $e) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}