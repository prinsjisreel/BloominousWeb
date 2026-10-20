<?php
/**
 * BLOOMINOUS - 3D Realism: Step 3 (Download the Result + Save Permanently)
 *
 * The generator is Hyper3D Rodin 2.5 through Pixazo. Task ids always start
 * with "px_". (Tripo has been removed.)
 *
 * WHY THIS EXISTS:
 * The model link a generator hands back lives on the generator's own storage,
 * and we cannot count on it forever. Saving that link into inventory.model
 * could break the web preview AND the mobile AR viewer later. So this endpoint
 * copies the GLB somewhere permanent that WE control.
 *
 * WHERE IT GOES (tried in this order, first success wins):
 *   1. Supabase Storage  (free plan: 1 GB, files up to 50 MB)
 *   2. Firebase Storage  (needs the Blaze plan)
 *   3. Cloudinary        (free plan: raw files up to 10 MB)
 * Any provider that is not set up, or that fails for ANY reason, is skipped
 * and the SAME bytes are tried on the next one. Change the order (or drop
 * providers) with BLOOM_3D_STORAGE_ORDER in config.local.php.
 *
 * COMPATIBILITY: written to run on PHP 7.4+ (no PHP 8.2-only cURL constants,
 * no str_starts_with), because XAMPP and Hostinger versions can differ.
 *
 * CONTRACT (old callers keep working; "url" is still the main key):
 *   POST JSON  { "taskId": "<px_... Hyper3D task id>", "productId": "<inventory doc id, optional>" }
 *   200        { success: true, url, provider, storagePath, cloudinaryPublicId, sizeBytes }
 *              provider = "supabase" | "firebase_storage" | "cloudinary"
 *   4xx / 5xx  { success: false, message }
 *
 * SETTINGS in config.local.php (all server-side secrets, never in the app):
 *   putenv('SUPABASE_URL=https://xxxxxxxx.supabase.co');
 *   putenv('SUPABASE_SECRET_KEY=sb_secret_...');   // or the legacy service_role key
 *   putenv('SUPABASE_BUCKET=models');              // a PUBLIC bucket
 *   putenv('BLOOM_3D_STORAGE_ORDER=supabase');     // optional, default: supabase,firebase,cloudinary
 *   // Cloudinary (optional fallback, same values as the app's api_keys.dart):
 *   putenv('CLOUDINARY_CLOUD_NAME=...');
 *   putenv('CLOUDINARY_UPLOAD_PRESET=...');        // an UNSIGNED preset
 */

ini_set('display_errors', '0');
ini_set('memory_limit', '256M'); // the GLB is held in memory while uploading
set_time_limit(300);             // generator download + up to three upload attempts

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Sends ONE JSON error reply and stops the script. */
function bloom_3d_fail(string $message, int $status = 500): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/** PHP 7-safe "does $text start with $prefix?" (str_starts_with is PHP 8+). */
function bloom_3d_starts_with(string $text, string $prefix): bool
{
    return $prefix === '' || strpos($text, $prefix) === 0;
}

if (!isset($_SESSION['admin_id'])) {
    bloom_3d_fail('Not authenticated', 401);
}

// We only needed to READ the session. Releasing it now means this long
// request won't freeze the admin's other tabs (PHP locks the session file).
session_write_close();

require_once __DIR__ . '/config.local.php';             // PIXAZO_API_KEY + SUPABASE_* + CLOUDINARY_* via putenv()
require_once __DIR__ . '/includes/firebase-config.php'; // $firebaseConfig + FIREBASE_SERVICE_ACCOUNT_JSON
require_once __DIR__ . '/includes/pixazo_hyper3d.php';     // Hyper3D (Pixazo) generator helpers

// Composer libraries (google/auth comes with kreait/firebase-php).
// Loaded directly instead of includes/firebase_admin.php so a gRPC
// extension crash can never take down this request before the other
// storage providers get their chance.
$bloomAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($bloomAutoload)) {
    require_once $bloomAutoload;
}

const BLOOM_3D_MAX_BYTES = 60 * 1024 * 1024;           // 60 MB safety cap for one model
const BLOOM_3D_FIREBASE_FOLDER = '3d_models';          // Firebase Storage folder
const BLOOM_3D_SUPABASE_FOLDER = '3d_models';          // folder inside the Supabase bucket
const BLOOM_3D_CLOUDINARY_FOLDER = 'bloom/3d_models';  // same "bloom/..." style the app uses
const BLOOM_3D_KNOWN_PROVIDERS = ['supabase', 'firebase', 'cloudinary'];

// ====================================================================
// STEP 1: Get the finished model's link from Pixazo (Hyper3D)
// ====================================================================

/** Link to the finished model's .glb, asked from Pixazo with the task id. */
function bloom_3d_get_model_url(string $taskId): string
{
    if (!bloom_px_is_task($taskId)) {
        throw new RuntimeException('This is not a Hyper3D task id (old Tripo task ids are no longer supported).');
    }
    if (!bloom_px_enabled()) {
        throw new RuntimeException('PIXAZO_API_KEY is missing from config.local.php, so this Hyper3D model cannot be fetched.');
    }
    return bloom_px_model_url($taskId);
}

/**
 * Downloads the GLB bytes ONCE. Every upload attempt reuses these bytes,
 * exactly like ImageUploadService reuses the same Uint8List in the app.
 *
 * Size cap: cURL hands us the file in small chunks through
 * CURLOPT_WRITEFUNCTION. We append each chunk ourselves and stop as soon as
 * the total passes BLOOM_3D_MAX_BYTES. (This option exists in every PHP
 * version, unlike CURLOPT_XFERINFOFUNCTION which needs PHP 8.2.)
 */
function bloom_3d_download_glb(string $url): string
{
    $bytes = '';
    $tooBig = false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
        // Called once per received chunk. Must return the chunk's length;
        // returning anything else tells cURL to abort the download.
        CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$bytes, &$tooBig): int {
            if (strlen($bytes) + strlen($chunk) > BLOOM_3D_MAX_BYTES) {
                $tooBig = true;
                return 0; // abort
            }
            $bytes .= $chunk;
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errMsg = curl_error($ch);
    curl_close($ch);

    if ($tooBig) {
        throw new RuntimeException('The generated model is larger than ' . (BLOOM_3D_MAX_BYTES / 1024 / 1024) . ' MB and was not saved.');
    }
    if ($ok === false) {
        throw new RuntimeException('Could not download the model from the 3D generator: ' . $errMsg);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException("The generator's temporary model link returned HTTP {$httpCode} (it may have already expired).");
    }
    // Every valid .glb file starts with the 4 bytes "glTF".
    if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'glTF') {
        throw new RuntimeException('The file the 3D generator returned is not a valid .glb model.');
    }

    return $bytes;
}

// ====================================================================
// STEP 2a: Provider - Supabase Storage
// ====================================================================

/** "3d_models/abc/def.glb" -> each segment URL-encoded, slashes kept. */
function bloom_3d_encode_path(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * Uploads to a PUBLIC Supabase Storage bucket through its REST API.
 * Returns ['url' => permanent public URL, 'storagePath' => path inside the bucket].
 *
 * The secret key lives only in config.local.php on the server. The browser
 * and the Flutter app never see it: they only get the public URL back.
 */
function bloom_3d_upload_to_supabase(string $bytes, string $objectPath): array
{
    $baseUrl = rtrim(trim((string) getenv('SUPABASE_URL')), '/');
    $secretKey = trim((string) getenv('SUPABASE_SECRET_KEY'));
    $bucket = trim((string) getenv('SUPABASE_BUCKET'));
    if ($bucket === '') {
        $bucket = 'models';
    }

    if ($baseUrl === '' || !preg_match('#^https://#i', $baseUrl)) {
        throw new RuntimeException('Supabase Storage is missing SUPABASE_URL in config.local.php (it looks like https://xxxx.supabase.co).');
    }
    if ($secretKey === '') {
        throw new RuntimeException('Supabase Storage is missing SUPABASE_SECRET_KEY in config.local.php.');
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $bucket)) {
        throw new RuntimeException('SUPABASE_BUCKET has characters that are not allowed.');
    }

    $encodedPath = bloom_3d_encode_path($objectPath);

    $headers = [
        // New-style keys (sb_secret_...) go in "apikey" only; they are not JWTs.
        'apikey: ' . $secretKey,
        'Content-Type: model/gltf-binary',
        'x-upsert: true', // same path again (e.g. Resume) replaces instead of failing
    ];
    // Legacy keys (service_role) are JWTs that start with "eyJ" and also go in Authorization.
    if (bloom_3d_starts_with($secretKey, 'eyJ')) {
        $headers[] = 'Authorization: Bearer ' . $secretKey;
    }

    // Big uploads (40+ MB) over HTTP/2 can be cut off by some hosts with
    // "PROTOCOL_ERROR". So: force plain HTTP/1.1, turn off the "Expect:
    // 100-continue" pause, and retry a couple of times on network failures.
    $headers[] = 'Expect:';
    $uploadUrl = $baseUrl . '/storage/v1/object/' . rawurlencode($bucket) . '/' . $encodedPath;

    $responseBody = false;
    $httpCode = 0;
    $errMsg = '';
    $maxAttempts = 3;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $bytes, // the raw file is the whole request body
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 240,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errMsg = curl_error($ch);
        curl_close($ch);

        // Success, or an answer from Supabase itself (even an error): stop retrying.
        if ($responseBody !== false) {
            break;
        }

        error_log('Supabase upload attempt ' . $attempt . '/' . $maxAttempts . ' failed: ' . $errMsg);
        if ($attempt < $maxAttempts) {
            sleep($attempt * 2); // wait 2s, then 4s
        }
    }

    if ($responseBody === false) {
        throw new RuntimeException('Supabase Storage unreachable after ' . $maxAttempts . ' attempts: ' . $errMsg);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        // Supabase answers with JSON such as {"statusCode":"413","error":"Payload too large","message":"..."}
        $json = json_decode((string) $responseBody, true);
        $reason = is_array($json) && !empty($json['message'])
            ? (string) $json['message']
            : substr((string) $responseBody, 0, 200);
        throw new RuntimeException('Supabase Storage rejected the upload (HTTP ' . $httpCode . '): ' . $reason);
    }

    return [
        // Public buckets need no token: this link works for the web viewer and the app's AR viewer.
        'url' => $baseUrl . '/storage/v1/object/public/' . rawurlencode($bucket) . '/' . $encodedPath,
        'storagePath' => $objectPath,
    ];
}

// ====================================================================
// STEP 2b: Provider - Firebase Storage
// ====================================================================

/** Random UUID v4. Used as the Firebase Storage "download token". */
function bloom_3d_uuid_v4(): string
{
    $raw = random_bytes(16);
    $raw[6] = chr((ord($raw[6]) & 0x0f) | 0x40); // version 4
    $raw[8] = chr((ord($raw[8]) & 0x3f) | 0x80); // RFC 4122 variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($raw), 4));
}

/** OAuth access token for Cloud Storage, from the project's service account. */
function bloom_3d_storage_access_token(): string
{
    if (!class_exists('\Google\Auth\Credentials\ServiceAccountCredentials')) {
        throw new RuntimeException('google/auth library is not installed (run composer install).');
    }
    if (!is_file(FIREBASE_SERVICE_ACCOUNT_JSON)) {
        throw new RuntimeException('serviceAccountKey.json was not found.');
    }

    $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials(
        'https://www.googleapis.com/auth/devstorage.read_write',
        FIREBASE_SERVICE_ACCOUNT_JSON
    );
    $token = $credentials->fetchAuthToken();
    if (empty($token['access_token'])) {
        throw new RuntimeException('Could not obtain a Cloud Storage access token.');
    }
    return $token['access_token'];
}

/**
 * Uploads to Firebase Storage. Returns ['url' => permanent URL, 'storagePath' => path].
 * Throws on ANY problem so the caller can move on to the next provider.
 */
function bloom_3d_upload_to_firebase(string $bytes, string $objectPath): array
{
    global $firebaseConfig; // from includes/firebase-config.php
    $bucket = (string) ($firebaseConfig['storageBucket'] ?? '');
    if ($bucket === '') {
        throw new RuntimeException('storageBucket is missing from includes/firebase-config.php.');
    }

    $downloadToken = bloom_3d_uuid_v4();
    $metadata = [
        'name' => $objectPath,
        'contentType' => 'model/gltf-binary',
        'cacheControl' => 'public, max-age=31536000',
        'metadata' => ['firebaseStorageDownloadTokens' => $downloadToken],
    ];

    // One request, two parts: JSON metadata, then the raw GLB bytes.
    $boundary = 'bloom_' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\n"
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
        . json_encode($metadata) . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: model/gltf-binary\r\n\r\n"
        . $bytes . "\r\n"
        . "--{$boundary}--";

    $ch = curl_init('https://storage.googleapis.com/upload/storage/v1/b/'
        . rawurlencode($bucket) . '/o?uploadType=multipart');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . bloom_3d_storage_access_token(),
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60, // kept short so a dead Storage doesn't delay the next provider
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errMsg = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Firebase Storage unreachable: ' . $errMsg);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('Firebase Storage rejected the upload (HTTP ' . $httpCode . '): '
            . substr((string) $responseBody, 0, 200));
    }

    return [
        'url' => 'https://firebasestorage.googleapis.com/v0/b/' . rawurlencode($bucket)
            . '/o/' . rawurlencode($objectPath)
            . '?alt=media&token=' . $downloadToken,
        'storagePath' => $objectPath,
    ];
}

// ====================================================================
// STEP 2c: Provider - Cloudinary
// ====================================================================

/**
 * Uploads to Cloudinary with the UNSIGNED preset (same preset the app uses,
 * so no API secret ever lives in this project either).
 *
 * Uses the "raw" endpoint: a .glb is not a picture, so Cloudinary should
 * store and serve it byte-for-byte instead of trying to process it as an image.
 *
 * Returns ['url' => secure_url, 'publicId' => public_id, 'bytes' => int].
 */
function bloom_3d_upload_to_cloudinary(string $bytes, string $folder, string $fileBaseName): array
{
    $cloudName = trim((string) getenv('CLOUDINARY_CLOUD_NAME'));
    $preset = trim((string) getenv('CLOUDINARY_UPLOAD_PRESET'));

    // Same "not configured" checks as CloudinaryService.uploadBytes() in the app.
    if ($cloudName === '' || bloom_3d_starts_with($cloudName, 'PASTE_')) {
        throw new RuntimeException('Cloudinary is not configured (missing CLOUDINARY_CLOUD_NAME in config.local.php).');
    }
    if ($preset === '' || bloom_3d_starts_with($preset, 'PASTE_')) {
        throw new RuntimeException('Cloudinary is not configured (missing CLOUDINARY_UPLOAD_PRESET in config.local.php).');
    }

    // cURL's multipart file upload needs a real file, so write a temp copy.
    $tmpPath = tempnam(sys_get_temp_dir(), 'bloom_glb_');
    if ($tmpPath === false || file_put_contents($tmpPath, $bytes) === false) {
        throw new RuntimeException('Could not write a temporary file for the Cloudinary upload.');
    }

    try {
        $ch = curl_init('https://api.cloudinary.com/v1_1/' . rawurlencode($cloudName) . '/raw/upload');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($tmpPath, 'model/gltf-binary', $fileBaseName . '.glb'),
                'upload_preset' => $preset,
                'folder' => $folder,
                // Raw files keep the extension inside the public id, so the
                // delivered URL ends in ".glb" like a normal model link.
                'public_id' => $fileBaseName . '.glb',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errMsg = curl_error($ch);
        curl_close($ch);
    } finally {
        @unlink($tmpPath); // always clean up, success or failure
    }

    if ($responseBody === false) {
        throw new RuntimeException('Could not reach Cloudinary: ' . $errMsg);
    }

    // Cloudinary replies with JSON both on success and on error.
    $json = json_decode((string) $responseBody, true);
    if (!is_array($json)) {
        throw new RuntimeException('Cloudinary returned an unexpected response (HTTP ' . $httpCode . ').');
    }
    if ($httpCode !== 200) {
        $msg = is_array($json['error'] ?? null)
            ? (string) ($json['error']['message'] ?? 'Upload failed')
            : 'Upload failed (HTTP ' . $httpCode . ')';
        throw new RuntimeException('Cloudinary: ' . $msg);
    }

    $url = (string) ($json['secure_url'] ?? '');
    $publicId = (string) ($json['public_id'] ?? '');
    if ($url === '' || $publicId === '') {
        throw new RuntimeException('Cloudinary did not return a URL.');
    }

    return [
        'url' => $url,
        'publicId' => $publicId,
        'bytes' => (int) ($json['bytes'] ?? strlen($bytes)),
    ];
}

// ====================================================================
// STEP 2: Try each storage provider in order
// ====================================================================

/**
 * Which providers to try, and in what order.
 * Default: supabase, firebase, cloudinary. Override in config.local.php with
 *   putenv('BLOOM_3D_STORAGE_ORDER=supabase');          // only Supabase
 *   putenv('BLOOM_3D_STORAGE_ORDER=supabase,cloudinary');
 * Unknown names are ignored.
 */
function bloom_3d_storage_order(): array
{
    $raw = trim((string) getenv('BLOOM_3D_STORAGE_ORDER'));
    if ($raw === '') {
        return BLOOM_3D_KNOWN_PROVIDERS;
    }

    $order = [];
    foreach (explode(',', strtolower($raw)) as $name) {
        $name = trim($name);
        if (in_array($name, BLOOM_3D_KNOWN_PROVIDERS, true) && !in_array($name, $order, true)) {
            $order[] = $name;
        }
    }
    return $order ?: BLOOM_3D_KNOWN_PROVIDERS;
}

/**
 * PHP twin of ImageUploadService.uploadBytes(), now with three providers:
 * try each in order -> first success returns, any failure moves on to the next
 * with the SAME bytes.
 */
function bloom_3d_save_model(string $bytes, string $productFolder, string $taskId): array
{
    $errors = [];

    foreach (bloom_3d_storage_order() as $provider) {
        try {
            if ($provider === 'supabase') {
                $saved = bloom_3d_upload_to_supabase(
                    $bytes,
                    BLOOM_3D_SUPABASE_FOLDER . "/{$productFolder}/{$taskId}.glb"
                );
                return [
                    'url' => $saved['url'],
                    'provider' => 'supabase',
                    'storagePath' => $saved['storagePath'],
                    'cloudinaryPublicId' => null,
                ];
            }

            if ($provider === 'firebase') {
                $saved = bloom_3d_upload_to_firebase(
                    $bytes,
                    BLOOM_3D_FIREBASE_FOLDER . "/{$productFolder}/{$taskId}.glb"
                );
                return [
                    'url' => $saved['url'],
                    'provider' => 'firebase_storage',
                    'storagePath' => $saved['storagePath'],
                    'cloudinaryPublicId' => null,
                ];
            }

            // $provider === 'cloudinary'
            $saved = bloom_3d_upload_to_cloudinary(
                $bytes,
                BLOOM_3D_CLOUDINARY_FOLDER . '/' . $productFolder,
                $taskId
            );
            return [
                'url' => $saved['url'],
                'provider' => 'cloudinary',
                'storagePath' => null,
                'cloudinaryPublicId' => $saved['publicId'],
            ];
        } catch (\Throwable $e) {
            // Deliberately broad: ANY failure means "try the next provider".
            $errors[] = ucfirst($provider) . ': ' . $e->getMessage();
            error_log("download_3d_model_result.php: {$provider} failed ({$e->getMessage()}). Trying the next provider.");
        }
    }

    // Every provider failed: one clear message that names each reason.
    throw new RuntimeException('Model upload failed on every storage provider. ' . implode(' | ', $errors));
}

// --------------------------------------------------------------------
// Request handling
// --------------------------------------------------------------------

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$taskId = (string) ($input['taskId'] ?? '');
$productId = (string) ($input['productId'] ?? '');

// Both IDs end up inside a file path, so only safe characters are allowed.
if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $taskId)) {
    bloom_3d_fail('Missing or invalid taskId.', 400);
}
if ($productId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $productId)) {
    bloom_3d_fail('Invalid productId.', 400);
}

try {
    $temporaryUrl = bloom_3d_get_model_url($taskId);
    $glbBytes = bloom_3d_download_glb($temporaryUrl);

    // productId is optional so an older caller that only sends taskId still works.
    $productFolder = $productId !== '' ? $productId : 'unassigned';

    $saved = bloom_3d_save_model($glbBytes, $productFolder, $taskId);

    echo json_encode([
        'success' => true,
        'url' => $saved['url'],                               // same key as before; now permanent
        'provider' => $saved['provider'],                     // "supabase" | "firebase_storage" | "cloudinary"
        'storagePath' => $saved['storagePath'],               // set for Supabase / Firebase
        'cloudinaryPublicId' => $saved['cloudinaryPublicId'], // set only when Cloudinary won
        'sizeBytes' => strlen($glbBytes),
    ]);
} catch (\Throwable $e) {
    error_log('download_3d_model_result.php failed for task ' . $taskId . ': ' . $e->getMessage());
    bloom_3d_fail($e->getMessage(), 502);
}