<?php
/**
 * BLOOMINOUS - 3D Realism: Step 3 (Download Result)
 *
 * Called once the browser's polling in check_3d_model_status.php
 * reports the job is done. Asks Hyper3D for the actual downloadable
 * file list and pulls out the .glb URL.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

define('HYPER3D_API_KEY', 'PASTE_YOUR_HYPER3D_API_KEY_HERE');

$input = json_decode(file_get_contents('php://input'), true);
$taskUuid = $input['uuid'] ?? null;

if (!$taskUuid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing uuid.']);
    exit();
}

// Recursively searches the decoded response for anything that looks
// like a .glb download URL. Hyper3D's exact download-response shape
// isn't fully pinned down in their public docs across API versions, so
// -- same defensive spirit as kiri_service.dart's _findGlbUrl() --
// this walks the whole structure rather than assuming one fixed path.
function bloom_find_glb_url($node) {
    if (is_string($node) && str_ends_with(strtolower($node), '.glb')) {
        return $node;
    }
    if (is_array($node)) {
        foreach ($node as $value) {
            $found = bloom_find_glb_url($value);
            if ($found !== null) return $found;
        }
    }
    return null;
}

try {
    $ch = curl_init('https://api.hyper3d.com/api/v2/download');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . HYPER3D_API_KEY,
            'Content-Type: application/json',
            'accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['task_uuid' => $taskUuid]),
        CURLOPT_TIMEOUT => 30,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false || $httpCode !== 200) {
        throw new Exception('Download lookup failed (HTTP ' . $httpCode . ')');
    }

    $data = json_decode($responseBody, true);
    $glbUrl = bloom_find_glb_url($data);

    if (!$glbUrl) {
        throw new Exception('No .glb file found in Hyper3D\'s response.');
    }

    echo json_encode(['success' => true, 'url' => $glbUrl]);
} catch (\Throwable $e) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}