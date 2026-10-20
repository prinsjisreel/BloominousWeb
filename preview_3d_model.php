<?php
/**
 * BLOOMINOUS - 3D Realism: Preview an UNSAVED model (Hyper3D via Pixazo)
 *
 * GET preview_3d_model.php?taskId=<px_... Hyper3D task id>
 *
 * Lets an admin LOOK at a generated model without saving it anywhere:
 *   - no Supabase / Firebase Storage / Cloudinary, no Firestore writes
 *   - the GLB is streamed from Pixazo THROUGH this server to the browser,
 *     piece by piece, so it is never held in memory or written to disk
 *   - it is served from our own domain, so the browser's cross-site (CORS)
 *     rules never block it
 *
 * Works only while Pixazo still keeps the job. Each request asks Pixazo for the
 * job's current result link, so an old link never has to be remembered.
 *
 * Success: the raw .glb bytes (Content-Type: model/gltf-binary)
 * Failure: JSON { success: false, message } with a 4xx/5xx status
 *          (model-viewer then fires its "error" event)
 */

ini_set('display_errors', '0');
set_time_limit(300); // a 40+ MB model can take a while on a slow connection

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Sends a JSON error (only possible before any model bytes were sent). */
function bloom_preview_fail(string $message, int $status): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
    }
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    bloom_preview_fail('Not authenticated', 401);
}

// Read-only use of the session: unlock it so other admin tabs don't freeze
// while this (possibly long) download streams.
session_write_close();

require_once __DIR__ . '/config.local.php';
require_once __DIR__ . '/includes/pixazo_hyper3d.php';

$taskId = (string) ($_GET['taskId'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $taskId)) {
    bloom_preview_fail('Missing or invalid taskId.', 400);
}

// Tripo is gone: only Hyper3D (Pixazo) task ids ("px_...") can be previewed.
if (!bloom_px_is_task($taskId)) {
    bloom_preview_fail('This is not a Hyper3D task id (old Tripo task ids are no longer supported).', 400);
}

// --------------------------------------------------------------------
// STEP 1: ask Pixazo for the finished model's .glb link
// (same call download_3d_model_result.php makes)
// --------------------------------------------------------------------
if (!bloom_px_enabled()) {
    bloom_preview_fail('PIXAZO_API_KEY is missing from config.local.php.', 500);
}
try {
    $glbUrl = bloom_px_model_url($taskId);
} catch (Throwable $e) {
    bloom_preview_fail($e->getMessage(), 502);
}

// --------------------------------------------------------------------
// STEP 2: stream the GLB to the browser as it downloads
// --------------------------------------------------------------------

// Turn off PHP output buffering/compression so each chunk goes out right away
// (otherwise PHP would collect the whole 40 MB before sending anything).
while (ob_get_level() > 0) {
    ob_end_clean();
}
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

$started = false;   // becomes true once the first valid chunk is sent
$firstHttp = 0;

$ch = curl_init($glbUrl);
curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 240,
    // Called for every chunk Pixazo sends. We forward it to the browser.
    // Returning anything other than the chunk length aborts the download.
    CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$started, &$firstHttp): int {
        if (!$started) {
            // First chunk: check it really is a model before sending headers.
            $firstHttp = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($firstHttp !== 200) {
                return 0; // abort; handled after curl_exec
            }
            if (strlen($chunk) >= 4 && substr($chunk, 0, 4) !== 'glTF') {
                return 0; // not a .glb file
            }

            $started = true;
            http_response_code(200);
            header('Content-Type: model/gltf-binary');
            header('Cache-Control: private, no-store'); // a preview, not a saved file
            header('X-Content-Type-Options: nosniff');

            // Lets model-viewer show a real loading percentage.
            $length = curl_getinfo($handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            if ($length > 0) {
                header('Content-Length: ' . (int) $length);
            }
        }

        echo $chunk;
        flush();
        return strlen($chunk);
    },
]);

$ok = curl_exec($ch);
$errMsg = curl_error($ch);
curl_close($ch);

if (!$started) {
    // Nothing was sent yet, so we can still reply with a proper error.
    if ($firstHttp !== 0 && $firstHttp !== 200) {
        bloom_preview_fail("The generator's temporary model link returned HTTP {$firstHttp}.", 502);
    }
    if ($ok === false) {
        bloom_preview_fail('Could not download the model from the 3D generator: ' . $errMsg, 502);
    }
    bloom_preview_fail('The file the 3D generator returned is not a valid .glb model.', 502);
}

// If the connection broke MID-stream, the browser receives a cut-off file and
// model-viewer reports an error; headers are already sent, so nothing to add.
if ($ok === false) {
    error_log('preview_3d_model.php: stream interrupted for task ' . $taskId . ': ' . $errMsg);
}
exit;