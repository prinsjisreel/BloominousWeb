<?php
/**
 * BLOOMINOUS - 3D Realism: Step 2 (Poll the generator's status)
 *
 * Checks a Hyper3D (Pixazo) job. Task ids always start with "px_".
 * Always answers in the shape the page expects:
 *   { success, done, failed, statusText, message? }
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Only READ the session; unlock it so polling doesn't block other admin tabs.
session_write_close();

require_once __DIR__ . '/config.local.php';
require_once __DIR__ . '/includes/pixazo_hyper3d.php';

$input = json_decode(file_get_contents('php://input'), true);
$taskId = (string) ($input['taskId'] ?? '');

if ($taskId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing taskId.']);
    exit();
}
if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $taskId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid taskId.']);
    exit();
}
// Tripo is gone: anything that is not a Hyper3D id cannot be checked.
if (!bloom_px_is_task($taskId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This is not a Hyper3D task id (old Tripo task ids are no longer supported).']);
    exit();
}

try {
    if (!bloom_px_enabled()) {
        throw new Exception('PIXAZO_API_KEY is missing from config.local.php, so this job cannot be checked.');
    }

    $state = bloom_px_status($taskId);
    echo json_encode([
        'success' => true,
        'done' => $state['done'],
        'failed' => $state['failed'],
        'statusText' => $state['statusText'],
        'message' => $state['message']
        // Pixazo gives no percentage, so "progress" is left out on purpose.
    ]);
} catch (\Throwable $e) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}