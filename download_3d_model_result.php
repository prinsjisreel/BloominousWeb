<?php
/**
 * BLOOMINOUS - 3D Realism: Step 3 (Download v3 Result)
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
        CURLOPT_TIMEOUT => 30,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Could not retrieve final task data (HTTP ' . $httpCode . ')');
    }

    $data = json_decode($responseBody, true);
    
    // v3 returns the GLB inside data.output.model_url
    $glbUrl = $data['data']['output']['model_url'] ?? null;

    if (!$glbUrl) {
        throw new Exception('No .glb model URL found in the v3 response.');
    }

    // Note: v3 model URLs expire in 5 minutes. The app downloads it immediately.
    echo json_encode(['success' => true, 'url' => $glbUrl]);

} catch (\Throwable $e) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}