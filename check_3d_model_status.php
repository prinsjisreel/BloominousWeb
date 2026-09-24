<?php
/**
 * BLOOMINOUS - 3D Realism: Step 2 (Poll Status)
 *
 * Called repeatedly by the browser (every few seconds) while a
 * generation is in progress. Each call is a single, quick check --
 * the polling LOOP lives in JavaScript on the admin's page, not in
 * this PHP file. A PHP script that tried to loop/sleep server-side
 * for up to 10 minutes would get killed by shared hosting's script
 * execution time limit; a browser has no such limit, so the waiting
 * belongs there instead.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

define('HYPER3D_API_KEY', 'PASTE_YOUR_HYPER3D_API_KEY_HERE');

$input = json_decode(file_get_contents('php://input'), true);
$subscriptionKey = $input['subscriptionKey'] ?? null;

if (!$subscriptionKey) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing subscriptionKey.']);
    exit();
}

try {
    $ch = curl_init('https://api.hyper3d.com/api/v2/status');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . HYPER3D_API_KEY,
            'Content-Type: application/json',
            'accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['subscription_key' => $subscriptionKey]),
        CURLOPT_TIMEOUT => 20,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false || $httpCode !== 200) {
        throw new Exception('Status check failed (HTTP ' . $httpCode . ')');
    }

    $data = json_decode($responseBody, true);

    // Hyper3D returns a LIST of job statuses (a single image submission
    // can internally spawn more than one job). The whole task is done
    // only once every single one reports "Done" -- and it's failed if
    // ANY of them reports "Failed". This mirrors kiri_service.dart's
    // own defensive status-string checking, just with an explicit list
    // instead of a single flexible field.
    $jobs = $data['jobs'] ?? $data['list'] ?? [];
    $statuses = [];
    foreach ($jobs as $job) {
        $statuses[] = strtolower($job['status'] ?? '');
    }

    $failed = in_array('failed', $statuses, true) || in_array('canceled', $statuses, true);
    $allDone = !empty($statuses) && !in_array(false, array_map(fn($s) => $s === 'done', $statuses), true);

    echo json_encode([
        'success' => true,
        'done' => $allDone,
        'failed' => $failed,
        'statuses' => $statuses,
    ]);
} catch (\Throwable $e) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}