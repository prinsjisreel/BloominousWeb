<?php
/**
 * BLOOMINOUS - Hyper3D Rodin 2.5 (via Pixazo) = the BACKUP 3D generator.
 *
 * Tripo is always tried first. If Tripo fails, the pages call the helpers in
 * this file so Pixazo runs Hyper3D Rodin 2.5 (image-to-3d) instead.
 *
 * Pixazo API (https://www.pixazo.ai/models/hyper3d)
 *   Submit : POST https://gateway.pixazo.ai/hyper3d-rodin-v2-5-image-to-3d/v1/hyper3d-rodin-v2-5-image-to-3d-request
 *   Check  : GET  https://gateway.pixazo.ai/v2/requests/status/{request_id}
 *   Key    : header  Ocp-Apim-Subscription-Key: <your key>
 *   Status : QUEUED | PROCESSING | COMPLETED | FAILED | ERROR
 *   Result : output.model_mesh.url  (the .glb)
 *   Images : must be PUBLIC http(s) links (JPG or PNG), not base64.
 *
 * HOW A PIXAZO TASK LOOKS TO THE REST OF THE APP
 *   The browser and the PHP endpoints only pass around a "taskId" string.
 *   A Pixazo job's taskId is "px_" + the request id made URL-safe.
 *   Anything starting with "px_" is a Pixazo job; everything else is Tripo.
 *   (Only letters, digits, "_" and "-" are used, so it passes the same
 *   safe-ID checks the other endpoints already enforce.)
 *
 * SETTINGS in config.local.php (server-side only, never in the app):
 *   putenv('PIXAZO_API_KEY=your-pixazo-key');       // REQUIRED to turn the backup on
 *   putenv('PIXAZO_RODIN_TIER=Gen-2.5-High');       // optional (see BLOOM_PX_TIERS)
 *   putenv('PIXAZO_RODIN_QUALITY=50K Quad');        // optional (see BLOOM_PX_QUALITIES)
 *   // Pixazo must be able to OPEN the reference photo from the internet, so
 *   // it is uploaded to your Supabase bucket (same SUPABASE_* settings as the
 *   // 3D models) under 3d_inputs/ and its public link is sent.
 *
 * COMPATIBILITY: PHP 7.4+ (no str_starts_with, no PHP 8.2-only cURL options).
 * Nothing here runs unless PIXAZO_API_KEY is set, so Tripo-only setups are unchanged.
 */

const BLOOM_PX_TASK_PREFIX = 'px_';
const BLOOM_PX_SUBMIT_URL = 'https://gateway.pixazo.ai/hyper3d-rodin-v2-5-image-to-3d/v1/hyper3d-rodin-v2-5-image-to-3d-request';
const BLOOM_PX_STATUS_URL = 'https://gateway.pixazo.ai/v2/requests/status/';
const BLOOM_PX_TIERS = ['Gen-2.5-Minimum', 'Gen-2.5-Extreme-Low', 'Gen-2.5-Low', 'Gen-2.5-Medium', 'Gen-2.5-High', 'Gen-2.5-Extreme-High'];
const BLOOM_PX_QUALITIES = ['Auto', '4K Quad', '8K Quad', '18K Quad', '50K Quad', '100K Quad', '200K Quad', '2K Triangle', '20K Triangle', '50K Triangle', '150K Triangle', '500K Triangle'];

function bloom_px_key(): string
{
    return trim((string) getenv('PIXAZO_API_KEY'));
}

/** True when a PIXAZO_API_KEY exists, i.e. the Hyper3D backup is switched on. */
function bloom_px_enabled(): bool
{
    return bloom_px_key() !== '';
}

/** Does this taskId belong to Pixazo (Hyper3D) instead of Tripo? */
function bloom_px_is_task(string $taskId): bool
{
    return strpos($taskId, BLOOM_PX_TASK_PREFIX) === 0;
}

/** Pixazo request id -> our safe task id ("px_" + base64url). */
function bloom_px_task_id(string $requestId): string
{
    return BLOOM_PX_TASK_PREFIX . rtrim(strtr(base64_encode($requestId), '+/', '-_'), '=');
}

/** Our task id -> the exact request id Pixazo gave us. */
function bloom_px_request_id(string $taskId): string
{
    $encoded = substr($taskId, strlen(BLOOM_PX_TASK_PREFIX));
    $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
    return ($decoded === false) ? '' : $decoded;
}

/**
 * One call to Pixazo. Returns ['code' => HTTP status, 'json' => decoded body or null, 'raw' => body].
 * Throws only when the network itself fails.
 */
function bloom_px_http(string $method, string $url, ?array $jsonBody = null, int $timeout = 60): array
{
    $headers = [
        'Ocp-Apim-Subscription-Key: ' . bloom_px_key(),
        'Cache-Control: no-cache',
        'Accept: application/json',
    ];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
    ];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Hyper3D (Pixazo) is unreachable: ' . $err);
    }
    $json = json_decode((string) $raw, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : null, 'raw' => (string) $raw];
}

/** A short, readable reason taken from a Pixazo reply (shapes differ, so several are tried). */
function bloom_px_error_text(array $reply): string
{
    $json = $reply['json'];
    if (is_array($json)) {
        foreach (['error', 'message', 'detail', 'error_message'] as $key) {
            if (empty($json[$key])) {
                continue;
            }
            if (is_string($json[$key])) {
                return $json[$key];
            }
            if (is_array($json[$key])) {
                return substr((string) json_encode($json[$key]), 0, 300);
            }
        }
    }
    return substr($reply['raw'], 0, 300);
}

/**
 * Makes the reference photo reachable by Pixazo. Returns a PUBLIC link.
 * Pixazo only accepts public JPG/PNG links (no base64), so the photo is
 * uploaded to the public Supabase bucket first. A WEBP photo is converted
 * to JPG on the way (needs PHP's GD extension, which XAMPP/Hostinger have).
 */
function bloom_px_image_url(string $tmpPath, string $mime): string
{
    $bytes = (string) file_get_contents($tmpPath);
    if ($bytes === '') {
        throw new RuntimeException('The reference photo could not be read.');
    }

    // Pixazo takes JPG or PNG only: convert WEBP.
    if ($mime === 'image/webp') {
        if (!function_exists('imagecreatefromwebp')) {
            throw new RuntimeException('Hyper3D needs a JPG or PNG photo (this server cannot convert WEBP). Please upload a JPG or PNG.');
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new RuntimeException('The WEBP photo could not be converted. Please upload a JPG or PNG.');
        }
        ob_start();
        imagejpeg($img, null, 92);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        $mime = 'image/jpeg';
    }

    $baseUrl = rtrim(trim((string) getenv('SUPABASE_URL')), '/');
    $secretKey = trim((string) getenv('SUPABASE_SECRET_KEY'));
    $bucket = trim((string) getenv('SUPABASE_BUCKET'));
    if ($bucket === '') {
        $bucket = 'models';
    }
    if ($baseUrl === '' || $secretKey === '' || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $bucket)) {
        throw new RuntimeException('Hyper3D needs Supabase set up (SUPABASE_URL and SUPABASE_SECRET_KEY in config.local.php) to host the reference photo with a public link.');
    }

    $extension = ($mime === 'image/png') ? 'png' : 'jpg';
    $objectPath = '3d_inputs/' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $objectPath)));

    $headers = [
        'apikey: ' . $secretKey,
        'Content-Type: ' . $mime,
        'x-upsert: true',
        'Expect:',
    ];
    if (strpos($secretKey, 'eyJ') === 0) { // legacy service_role key (a JWT)
        $headers[] = 'Authorization: Bearer ' . $secretKey;
    }

    $ch = curl_init($baseUrl . '/storage/v1/object/' . rawurlencode($bucket) . '/' . $encodedPath);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        throw new RuntimeException('Could not host the reference photo on Supabase for Hyper3D (HTTP ' . $code . ') ' . $err);
    }

    return $baseUrl . '/storage/v1/object/public/' . rawurlencode($bucket) . '/' . $encodedPath;
}

/**
 * Starts a Hyper3D Rodin 2.5 job (this is where Pixazo charges for the run).
 * Returns our task id: "px_<request id>".
 */
function bloom_px_submit(string $imageUrl): string
{
    $tier = trim((string) getenv('PIXAZO_RODIN_TIER'));
    if (!in_array($tier, BLOOM_PX_TIERS, true)) {
        $tier = 'Gen-2.5-High';
    }
    $quality = trim((string) getenv('PIXAZO_RODIN_QUALITY'));
    if (!in_array($quality, BLOOM_PX_QUALITIES, true)) {
        $quality = '50K Quad'; // smooth, light enough for the mobile AR viewer
    }

    $reply = bloom_px_http('POST', BLOOM_PX_SUBMIT_URL, [
        'image_urls' => [$imageUrl],
        'tier' => $tier,
        'quality_mesh_option' => $quality,
        'geometry_file_format' => 'glb',
        'material' => 'PBR',
    ], 90);

    // The docs promise HTTP 202 (queued); any 2xx is accepted.
    if ($reply['code'] < 200 || $reply['code'] >= 300) {
        throw new RuntimeException('Hyper3D (Pixazo) rejected the job (HTTP ' . $reply['code'] . '): ' . bloom_px_error_text($reply));
    }

    $requestId = (string) ($reply['json']['request_id'] ?? '');
    if ($requestId === '') {
        throw new RuntimeException('Hyper3D (Pixazo) did not return a request id.');
    }
    $taskId = bloom_px_task_id($requestId);
    if (strlen($taskId) > 128) {
        throw new RuntimeException('Hyper3D (Pixazo) returned a request id that is too long to track.');
    }

    $firstStatus = strtoupper((string) ($reply['json']['status'] ?? ''));
    if ($firstStatus === 'FAILED' || $firstStatus === 'ERROR') {
        throw new RuntimeException('Hyper3D (Pixazo) could not start the job: ' . bloom_px_error_text($reply));
    }

    return $taskId;
}

/** GET /v2/requests/status/{request_id}. */
function bloom_px_get_status(string $taskId): array
{
    $requestId = bloom_px_request_id($taskId);
    if ($requestId === '') {
        throw new RuntimeException('This Hyper3D task id is not valid.');
    }
    return bloom_px_http('GET', BLOOM_PX_STATUS_URL . rawurlencode($requestId), null, 30);
}

/**
 * Same shape the browser already expects from check_3d_model_status.php:
 *   ['done' => bool, 'failed' => bool, 'statusText' => string, 'message' => string]
 */
function bloom_px_status(string $taskId): array
{
    $reply = bloom_px_get_status($taskId);

    if ($reply['code'] === 404) {
        return ['done' => false, 'failed' => true, 'statusText' => 'not_found',
            'message' => 'Hyper3D (Pixazo) no longer has this job.'];
    }
    if ($reply['code'] < 200 || $reply['code'] >= 300) {
        throw new RuntimeException('Hyper3D status check failed (HTTP ' . $reply['code'] . '): ' . bloom_px_error_text($reply));
    }

    $status = strtoupper((string) ($reply['json']['status'] ?? 'UNKNOWN'));

    if ($status === 'COMPLETED') {
        return ['done' => true, 'failed' => false, 'statusText' => 'completed', 'message' => ''];
    }
    if ($status === 'FAILED' || $status === 'ERROR') {
        return ['done' => false, 'failed' => true, 'statusText' => strtolower($status),
            'message' => 'Hyper3D could not build this model (' . strtolower($status) . '): ' . bloom_px_error_text($reply)];
    }
    // QUEUED / PROCESSING / anything new: keep waiting.
    return ['done' => false, 'failed' => false, 'statusText' => strtolower($status), 'message' => ''];
}

/**
 * Finds the .glb link inside the "output" part of a finished reply.
 * Documented spot: output.model_mesh.url. Because Pixazo's docs also mention
 * other spots (output.media_url), the rest of "output" is searched as a backup.
 */
function bloom_px_find_glb_url($output): string
{
    if (is_array($output) && isset($output['model_mesh']['url']) && is_string($output['model_mesh']['url'])
        && preg_match('#^https://#i', $output['model_mesh']['url'])) {
        return $output['model_mesh']['url'];
    }

    $urls = [];
    $walk = function ($value) use (&$walk, &$urls): void {
        if (is_string($value)) {
            if (preg_match('#^https://#i', $value)) {
                $urls[] = $value;
            }
        } elseif (is_array($value)) {
            foreach ($value as $child) {
                $walk($child);
            }
        }
    };
    $walk($output);

    foreach ($urls as $url) { // best: the path itself ends in .glb
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('/\.glb$/i', $path)) {
            return $url;
        }
    }
    foreach ($urls as $url) { // next: ".glb" appears somewhere in the link
        if (stripos($url, '.glb') !== false) {
            return $url;
        }
    }
    return '';
}

/** The finished model's .glb link (Pixazo keeps it on its own storage). */
function bloom_px_model_url(string $taskId): string
{
    $reply = bloom_px_get_status($taskId);

    if ($reply['code'] < 200 || $reply['code'] >= 300) {
        throw new RuntimeException('Could not retrieve the Hyper3D result (HTTP ' . $reply['code'] . '): ' . bloom_px_error_text($reply));
    }
    $status = strtoupper((string) ($reply['json']['status'] ?? ''));
    if ($status !== 'COMPLETED') {
        throw new RuntimeException('The Hyper3D job is not finished yet (status: ' . ($status !== '' ? strtolower($status) : 'unknown') . ').');
    }

    $url = bloom_px_find_glb_url($reply['json']['output'] ?? null);
    if ($url === '') {
        error_log('pixazo_hyper3d: no .glb link found in output: ' . substr($reply['raw'], 0, 600));
        throw new RuntimeException('Hyper3D finished but returned no .glb model link.');
    }
    return $url;
}