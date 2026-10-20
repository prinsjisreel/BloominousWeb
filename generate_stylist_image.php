<?php
/**
 * BLOOMINOUS - Visual Stylist image proxy (BloominousApp only)
 *
 * This file is the BRIDGE between the Flutter app and Pixazo, so the Pixazo
 * key never lives in the APK. All settings come from config.local.php:
 *   putenv('PIXAZO_API_KEY=...');            // required
 *   putenv('FIREBASE_WEB_API_KEY=...');      // required (the WEB apiKey, not Android)
 *   putenv('STYLIST_DAILY_LIMIT=3');         // optional, previews per customer per day
 *   putenv('PIXAZO_QUALITY=medium');         // optional, GPT Image quality: low | medium | high
 *   putenv('STYLIST_GPT_FALLBACK=on');       // optional, "off" disables the paid GPT Image fallback
 *
 * Photo edits -> 1st: Pixazo "Qwen Image 3.0 Pro" (Free Tier eligible)
 *                2nd: Pixazo "GPT Image 2.5 Flare" (wallet only) - used ONLY when Qwen
 *                     cannot start, or when the Qwen job ends FAILED / ERROR.
 *                Both need a PUBLIC LINK to the photo (not base64), so the photo is
 *                parked in stylist_tmp/ under a random name for an hour.
 * Text only   -> Pixazo GPT Image 2.5 Flare at 1536x1024 (wallet; only used when
 *                the Visual Stylist has no room photo).
 *
 * Contract with the app (lib/stylist_image_service.dart) - unchanged:
 *   POST, Authorization: Bearer <Firebase ID token>, JSON body
 *   { "action": "submit", "prompt": "..." }                                       text -> image
 *   { "action": "edit", "prompt": "...", "image": "data:image/jpeg;base64,..." }  photo edit
 *       -> { success: true, requestId, status: "QUEUED" }
 *   { "action": "status", "requestId": "<model>_..." }
 *       -> { success: true, status: "QUEUED" | "PROCESSING" }
 *       -> { success: true, status: "COMPLETED", imageUrl }
 *   Any failure -> { success: false, code, message }
 *
 * The app keeps polling the ORIGINAL requestId even after the GPT fallback starts;
 * this file maps it to the fallback job behind the scenes ("alias").
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---------------------------------------------------------------------
// Settings: load config.local.php (next to this file, or one folder above),
// then read each value from the environment it sets with putenv().
// ---------------------------------------------------------------------
foreach ([__DIR__ . '/config.local.php', dirname(__DIR__) . '/config.local.php'] as $localConfig) {
    if (is_file($localConfig)) {
        require_once $localConfig;
        break;
    }
}

/** Reads one setting; returns $default when it is missing or blank. */
function stylist_setting(string $name, string $default = ''): string
{
    $value = trim((string) getenv($name));
    return $value !== '' ? $value : $default;
}

define('PIXAZO_API_KEY', stylist_setting('PIXAZO_API_KEY'));
define('FIREBASE_WEB_API_KEY', stylist_setting('FIREBASE_WEB_API_KEY'));

$stylistQuality = stylist_setting('PIXAZO_QUALITY', 'medium');
define('PIXAZO_QUALITY', in_array($stylistQuality, ['low', 'medium', 'high'], true) ? $stylistQuality : 'medium');

// max() keeps a typo like "abc" or "-5" from turning the limit off.
define('STYLIST_DAILY_LIMIT', max(1, (int) stylist_setting('STYLIST_DAILY_LIMIT', '3')));

// The paid GPT Image fallback is ON unless this is set to "off".
define('STYLIST_GPT_FALLBACK', strtolower(stylist_setting('STYLIST_GPT_FALLBACK', 'on')) !== 'off');

// Usage counters live ABOVE public_html so a browser can never open them.
define('STYLIST_STORE_DIR', dirname(__DIR__) . '/bloom_private/stylist_usage');

const PIXAZO_SUBMIT_URL        = 'https://gateway.pixazo.ai/gpt-image-2-5-flare/v1/text-to-image';
const PIXAZO_GPT_EDIT_URL      = 'https://gateway.pixazo.ai/gpt-image-2-5-flare/v1/image-to-image/editing';
const PIXAZO_EDIT_URL          = 'https://gateway.pixazo.ai/qwen-image-3-0-pro/v1/image-to-image/editing';
const PIXAZO_STATUS_URL        = 'https://gateway.pixazo.ai/v2/requests/status/';
const STYLIST_IMAGE_SIZE       = '1536x1024'; // text-only (GPT Image) output size
const STYLIST_PROMPT_MAX_CHARS = 1200;
const STYLIST_KEEP_REQUESTS    = 20;          // recent job IDs remembered per user
const STYLIST_KEEP_JOBS        = 20;          // recent job plans (fallback / alias) remembered per user
const STYLIST_MAX_IMAGE_CHARS  = 4000000;     // about 3 MB of photo once decoded
const STYLIST_JPEG_PREFIX      = 'data:image/jpeg;base64,';
const STYLIST_JOB_ID_PATTERN   = '/^(gpt-image-2-5-flare|qwen-image-3-0-pro)_[A-Za-z0-9-]{8,80}$/';

// Where photos wait for Pixazo to fetch them. If you move to your real domain,
// change STYLIST_TMP_URL to match.
const STYLIST_TMP_DIR          = __DIR__ . '/stylist_tmp';
const STYLIST_TMP_URL          = 'https://honeydew-duck-132160.hostingersite.com/stylist_tmp/';
const STYLIST_TMP_MAX_AGE      = 3600;        // seconds before a parked photo is deleted

// Things the Qwen edit must NOT do (Qwen 3.0 Pro supports a real negative prompt).
const STYLIST_EDIT_NEGATIVE    = 'people, text, letters, watermark, logo, blurry, distorted, '
    . 'changed room, different furniture, extra furniture, cartoon, illustration';

// ---------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------

function stylist_respond(int $httpCode, array $body): void
{
    http_response_code($httpCode);
    echo json_encode($body);
    exit;
}

function stylist_fail(int $httpCode, string $code, string $message): void
{
    stylist_respond($httpCode, ['success' => false, 'code' => $code, 'message' => $message]);
}

/** One place for every outgoing HTTP call. Never throws; returns status + decoded JSON. */
function stylist_http(string $method, string $url, array $headers, ?string $body = null, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return ['status' => $status, 'json' => is_array($decoded) ? $decoded : null, 'error' => $error];
}

/** Reads "Authorization: Bearer xyz" even when Apache hides the header. */
function stylist_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
}

/** Asks Google to confirm the login token. Returns the Firebase uid, or null if invalid. */
function stylist_verify_uid(string $idToken): ?string
{
    $res = stylist_http(
        'POST',
        'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . urlencode(FIREBASE_WEB_API_KEY),
        ['Content-Type: application/json'],
        json_encode(['idToken' => $idToken]),
        10
    );
    $uid = $res['json']['users'][0]['localId'] ?? null;
    return (is_string($uid) && preg_match('/^[A-Za-z0-9]{10,128}$/', $uid)) ? $uid : null;
}

/**
 * Opens this user's usage file under a lock, lets $change edit it, then saves it.
 * Shape: {
 *   day: "2026-10-20", count: 3,
 *   requests: ["qwen-image-3-0-pro_...", ...],                 // job IDs this user owns
 *   jobs: { "<job id>": { fallback: {url, body}, alias: "<id>" } }   // fallback plan / redirect
 * }
 */
function stylist_with_usage(string $uid, callable $change): array
{
    if (!is_dir(STYLIST_STORE_DIR) && !mkdir(STYLIST_STORE_DIR, 0700, true) && !is_dir(STYLIST_STORE_DIR)) {
        stylist_fail(500, 'STORE_UNAVAILABLE', 'The image service is not set up correctly yet.');
    }

    $handle = fopen(STYLIST_STORE_DIR . '/' . $uid . '.json', 'c+');
    if ($handle === false) {
        stylist_fail(500, 'STORE_UNAVAILABLE', 'The image service is not set up correctly yet.');
    }
    flock($handle, LOCK_EX);

    $usage = json_decode(stream_get_contents($handle) ?: '', true);
    if (!is_array($usage)) {
        $usage = [];
    }
    $requests = is_array($usage['requests'] ?? null) ? $usage['requests'] : [];
    $jobs     = is_array($usage['jobs'] ?? null) ? $usage['jobs'] : [];
    $today    = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

    // New day: reset the counter, but keep remembering recent job IDs and their plans.
    if (($usage['day'] ?? '') !== $today) {
        $usage = ['day' => $today, 'count' => 0, 'requests' => $requests, 'jobs' => $jobs];
    }
    $usage['requests'] = $requests;
    $usage['jobs']     = $jobs;

    $usage = $change($usage);

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($usage));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $usage;
}

/** Validates the customer's prompt and returns it trimmed. */
function stylist_read_prompt(array $input): string
{
    $prompt = trim((string) ($input['prompt'] ?? ''));
    $length = mb_strlen($prompt);
    if ($length < 3 || $length > STYLIST_PROMPT_MAX_CHARS) {
        stylist_fail(400, 'INVALID_PROMPT', 'Please describe the arrangement in 3 to 1,200 characters.');
    }
    return $prompt;
}

/**
 * Parks the customer's photo where Pixazo can open it, and returns the public link.
 *  - the file name is 32 random hex characters, so the link cannot be guessed
 *  - anything older than an hour is deleted on every call (no cron job needed)
 *  - the folder blocks directory listing and script execution
 */
function stylist_host_photo(string $jpegBinary): string
{
    if (!is_dir(STYLIST_TMP_DIR) && !mkdir(STYLIST_TMP_DIR, 0755, true) && !is_dir(STYLIST_TMP_DIR)) {
        error_log('generate_stylist_image: cannot create ' . STYLIST_TMP_DIR);
        stylist_fail(500, 'STORE_UNAVAILABLE', 'The image service is not set up correctly yet.');
    }

    $guard = STYLIST_TMP_DIR . '/.htaccess';
    if (!is_file($guard)) {
        $rules = [
            'Options -Indexes',
            '<FilesMatch "\.(php|phtml|phar)$">',
            '  Require all denied',
            '</FilesMatch>',
        ];
        @file_put_contents($guard, implode("\n", $rules) . "\n");
    }

    // Sweep out old photos.
    foreach (glob(STYLIST_TMP_DIR . '/*.jpg') ?: [] as $old) {
        if (@filemtime($old) < time() - STYLIST_TMP_MAX_AGE) {
            @unlink($old);
        }
    }

    $name = bin2hex(random_bytes(16)) . '.jpg';
    if (file_put_contents(STYLIST_TMP_DIR . '/' . $name, $jpegBinary, LOCK_EX) === false) {
        error_log('generate_stylist_image: cannot write photo into ' . STYLIST_TMP_DIR);
        stylist_fail(500, 'STORE_UNAVAILABLE', 'The image service is not set up correctly yet.');
    }
    return STYLIST_TMP_URL . $name;
}

/**
 * Sends ONE job to Pixazo. Never exits the script, so the caller can try a
 * fallback. Returns ['ok' => true, 'id' => request_id]
 *      or          ['ok' => false, 'code' => ..., 'http' => ..., 'message' => ...]
 */
function stylist_call_pixazo(string $url, array $body): array
{
    $res = stylist_http(
        'POST',
        $url,
        [
            'Content-Type: application/json',
            'Cache-Control: no-cache',
            'Ocp-Apim-Subscription-Key: ' . PIXAZO_API_KEY,
        ],
        json_encode($body),
        30
    );

    if ($res['status'] === 402) {
        error_log('generate_stylist_image: Pixazo says Insufficient Balance (402) for ' . $url);
        return ['ok' => false, 'code' => 'PROVIDER_BALANCE', 'http' => 503,
            'message' => 'The image service is temporarily unavailable.'];
    }
    if ($res['status'] === 429) {
        error_log('generate_stylist_image: Pixazo is busy (429) for ' . $url);
        return ['ok' => false, 'code' => 'PROVIDER_BUSY', 'http' => 503,
            'message' => 'The image service is busy. Please try again shortly.'];
    }

    $id = $res['json']['request_id'] ?? null;
    if (!in_array($res['status'], [200, 202], true) || !is_string($id) || $id === '') {
        error_log('generate_stylist_image: submit failed for ' . $url . ' HTTP ' . $res['status'] . ' ' . $res['error']
            . ' body=' . json_encode($res['json']));
        return ['ok' => false, 'code' => 'PROVIDER_ERROR', 'http' => 502,
            'message' => 'The image service could not start your preview.'];
    }

    return ['ok' => true, 'id' => $id];
}

/**
 * Checks the daily limit, then sends the job to Pixazo - all under this user's
 * lock so two quick taps can't both slip past the limit.
 *
 * $primary / $fallback are ['url' => ..., 'body' => ...].
 *  - If the primary cannot start, the fallback is started immediately.
 *  - If the primary starts, the fallback plan is SAVED so status() can start it
 *    later when the primary job ends FAILED / ERROR.
 * The daily counter goes up by one per customer action, whichever model ran.
 * Returns the request_id the app should poll.
 */
function stylist_start_job(string $uid, array $primary, ?array $fallback = null): string
{
    $requestId = null;

    stylist_with_usage($uid, function (array $usage) use ($primary, $fallback, &$requestId): array {
        if ((int) $usage['count'] >= STYLIST_DAILY_LIMIT) {
            stylist_fail(429, 'DAILY_LIMIT', "You've reached today's limit for stylist previews. Please try again tomorrow.");
        }

        $call         = stylist_call_pixazo($primary['url'], $primary['body']);
        $savedPlanFor = $fallback;

        if (!$call['ok'] && $fallback !== null) {
            error_log('generate_stylist_image: first model could not start (' . $call['code'] . '), starting GPT Image fallback.');
            $call         = stylist_call_pixazo($fallback['url'], $fallback['body']);
            $savedPlanFor = null; // already on the fallback; nothing left to fall back to
        }

        if (!$call['ok']) {
            stylist_fail($call['http'], $call['code'], $call['message']);
        }

        $requestId         = $call['id'];
        $usage['count']    = (int) $usage['count'] + 1;
        $usage['requests'] = array_slice(array_merge([$requestId], $usage['requests']), 0, STYLIST_KEEP_REQUESTS);

        if ($savedPlanFor !== null) {
            $usage['jobs'][$requestId] = ['fallback' => $savedPlanFor];
        }
        $usage['jobs'] = array_slice($usage['jobs'], -STYLIST_KEEP_JOBS, null, true);

        return $usage;
    });

    return (string) $requestId;
}

/**
 * The first model's job ended FAILED / ERROR. Starts the saved fallback ONCE and
 * points the original job ID at it. Returns the fallback's request_id, or null
 * when there is no fallback (or it could not start).
 */
function stylist_start_saved_fallback(string $uid, string $requestId): ?string
{
    $newId = null;

    stylist_with_usage($uid, function (array $usage) use ($requestId, &$newId): array {
        $job = $usage['jobs'][$requestId] ?? null;
        if (!is_array($job)) {
            return $usage;
        }
        if (isset($job['alias'])) {          // already redirected by an earlier poll
            $newId = (string) $job['alias'];
            return $usage;
        }
        if (!isset($job['fallback']['url'], $job['fallback']['body'])) {
            return $usage;                    // no fallback was planned for this job
        }

        $call = stylist_call_pixazo((string) $job['fallback']['url'], (array) $job['fallback']['body']);
        unset($usage['jobs'][$requestId]['fallback']); // one try only, never a loop

        if ($call['ok']) {
            $usage['jobs'][$requestId]['alias'] = $call['id'];
            $newId = $call['id'];
        } else {
            error_log('generate_stylist_image: GPT Image fallback could not start (' . $call['code'] . ').');
        }
        return $usage;
    });

    return $newId;
}

// ---------------------------------------------------------------------
// Gatekeeping: method, configuration, login
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stylist_fail(405, 'METHOD_NOT_ALLOWED', 'Use POST.');
}

// A blank key means config.local.php was not found or does not set it.
if (PIXAZO_API_KEY === '' || FIREBASE_WEB_API_KEY === '') {
    error_log('generate_stylist_image: PIXAZO_API_KEY or FIREBASE_WEB_API_KEY is missing from config.local.php');
    stylist_fail(503, 'NOT_CONFIGURED', 'The image service is not set up yet.');
}

$token = stylist_bearer_token();
$uid   = $token ? stylist_verify_uid($token) : null;
if ($uid === null) {
    stylist_fail(401, 'NO_SESSION', 'Please sign in again to use the Visual Stylist.');
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    stylist_fail(400, 'BAD_REQUEST', 'The request could not be read.');
}
$action = (string) ($input['action'] ?? '');

// ---------------------------------------------------------------------
// action = submit  ->  text-only image (GPT Image 2.5 Flare, paid)
// ---------------------------------------------------------------------

if ($action === 'submit') {
    $prompt    = stylist_read_prompt($input);
    $requestId = stylist_start_job($uid, [
        'url'  => PIXAZO_SUBMIT_URL,
        'body' => [
            'prompt'             => $prompt,
            'n'                  => 1,
            'size'               => STYLIST_IMAGE_SIZE,
            'quality'            => PIXAZO_QUALITY,
            'format'             => 'jpeg',
            'output_compression' => 85,
            'background'         => 'opaque',
        ],
    ]);
    stylist_respond(200, ['success' => true, 'requestId' => $requestId, 'status' => 'QUEUED']);
}

// ---------------------------------------------------------------------
// action = edit  ->  stage flowers into the customer's room photo
//                    1st Qwen Image 3.0 Pro (Free Tier), 2nd GPT Image 2.5 Flare
// ---------------------------------------------------------------------

if ($action === 'edit') {
    $prompt = stylist_read_prompt($input);
    $image  = (string) ($input['image'] ?? '');

    if (strpos($image, STYLIST_JPEG_PREFIX) !== 0 || strlen($image) > STYLIST_MAX_IMAGE_CHARS) {
        stylist_fail(400, 'INVALID_PHOTO', 'Please upload a smaller JPEG photo.');
    }

    $binary = base64_decode(substr($image, strlen(STYLIST_JPEG_PREFIX)), true);
    $info   = ($binary === false) ? false : @getimagesizefromstring($binary);
    if ($binary === false || $info === false) {
        stylist_fail(400, 'INVALID_PHOTO', 'That file does not look like a photo.');
    }

    // Qwen 3.0 Pro wants each side between 512 and 2048 pixels. The app already
    // resizes to 1536x1024 (or 1024x1536); this is a safety net.
    $width  = (int) $info[0];
    $height = (int) $info[1];
    if ($width < 512 || $height < 512 || $width > 2048 || $height > 2048) {
        stylist_fail(400, 'INVALID_PHOTO', 'Please upload a photo between 512 and 2048 pixels.');
    }

    $photoUrl = stylist_host_photo($binary);

    // 1st choice: Qwen Image 3.0 Pro (paid from the Free Tier image credit).
    $qwen = [
        'url'  => PIXAZO_EDIT_URL,
        'body' => [
            'image_url'       => $photoUrl,
            'prompt'          => $prompt,
            'size'            => $width . 'x' . $height,        // keep the photo's own size
            'n'               => 1,
            'negative_prompt' => STYLIST_EDIT_NEGATIVE,
            'seed'            => random_int(1, 2147483647),     // new variation on every try
            'watermark'       => false,
            'prompt_extend'   => false,                         // use OUR prompt exactly as written
        ],
    ];

    // The catch: GPT Image 2.5 Flare (paid from the wallet), only if Qwen fails.
    $gpt = STYLIST_GPT_FALLBACK ? [
        'url'  => PIXAZO_GPT_EDIT_URL,
        'body' => [
            'prompt'  => $prompt,
            'image'   => $photoUrl,
            'size'    => ($width >= $height) ? '1536x1024' : '1024x1536',
            'quality' => PIXAZO_QUALITY,
            'format'  => 'jpeg',
        ],
    ] : null;

    $requestId = stylist_start_job($uid, $qwen, $gpt);
    stylist_respond(200, ['success' => true, 'requestId' => $requestId, 'status' => 'QUEUED']);
}

// ---------------------------------------------------------------------
// action = status  ->  check a job this same user started
// ---------------------------------------------------------------------

if ($action === 'status') {
    $requestId = (string) ($input['requestId'] ?? '');
    if (!preg_match(STYLIST_JOB_ID_PATTERN, $requestId)) {
        stylist_fail(400, 'BAD_REQUEST', 'Unknown preview reference.');
    }

    $usage = stylist_with_usage($uid, fn (array $u): array => $u); // read-only peek
    if (!in_array($requestId, $usage['requests'], true)) {
        stylist_fail(403, 'NOT_YOURS', 'This preview does not belong to your account.');
    }

    // If the fallback already took over this job, check the fallback instead.
    $job    = $usage['jobs'][$requestId] ?? [];
    $pollId = (string) ($job['alias'] ?? $requestId);

    $res = stylist_http(
        'GET',
        PIXAZO_STATUS_URL . rawurlencode($pollId),
        ['Ocp-Apim-Subscription-Key: ' . PIXAZO_API_KEY],
        null,
        15
    );

    $state = strtoupper((string) ($res['json']['status'] ?? ''));
    if ($res['status'] !== 200 || $state === '') {
        stylist_fail(502, 'PROVIDER_ERROR', 'Could not check the preview status.');
    }

    if ($state === 'COMPLETED') {
        $url = $res['json']['output']['media_url'][0] ?? null;
        if (!is_string($url) || strpos($url, 'https://') !== 0) {
            stylist_fail(502, 'PROVIDER_ERROR', 'The preview finished but no image was returned.');
        }
        stylist_respond(200, ['success' => true, 'status' => 'COMPLETED', 'imageUrl' => $url]);
    }

    if ($state === 'FAILED' || $state === 'ERROR') {
        error_log('generate_stylist_image: job ' . $pollId . ' ended ' . $state . ': ' . ($res['json']['error'] ?? ''));

        // The first model failed: hand the job to GPT Image (once), and keep the app waiting.
        if (!isset($job['alias'])) {
            $fallbackId = stylist_start_saved_fallback($uid, $requestId);
            if ($fallbackId !== null) {
                stylist_respond(200, ['success' => true, 'status' => 'QUEUED']);
            }
        }
        stylist_fail(502, 'GENERATION_FAILED', 'The preview could not be created.');
    }

    stylist_respond(200, ['success' => true, 'status' => $state]); // QUEUED or PROCESSING
}

stylist_fail(400, 'UNKNOWN_ACTION', 'Unknown action.');