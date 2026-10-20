<?php
/**
 * BLOOMINOUS - Pixazo-only SELF TEST  (command line only, spends NO credit)
 *
 * Run it from the BloominousWeb folder (the one that holds config.local.php):
 *
 *   php test_pixazo_only.php                    -> all checks, no old job
 *   php test_pixazo_only.php px_XXXXXXXX        -> also tests RECOVERING an old job
 *   php test_pixazo_only.php <Pixazo request id> -> same, using the raw request id
 *   php test_pixazo_only.php --skip-upload      -> skip the tiny Supabase test upload
 *
 * WHY IT IS SAFE: this file never calls bloom_px_submit(), the only function that
 * creates (and charges for) a Hyper3D job. It only reads (status checks), lints
 * your files, and uploads one 70-byte test picture to Supabase (free).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pass = 0;
$fail = 0;

function t_pass($label)          { global $pass; $pass++; echo "[PASS] $label\n"; }
function t_fail($label, $why='') { global $fail; $fail++; echo "[FAIL] $label" . ($why !== '' ? "\n       -> $why" : '') . "\n"; }
function t_info($text)           { echo "[INFO] $text\n"; }
function t_head($text)           { echo "\n=== $text ===\n"; }

$args = array_slice($argv, 1);
$skipUpload = in_array('--skip-upload', $args, true);
$jobArg = '';
foreach ($args as $a) {
    if (strpos($a, '--') !== 0) { $jobArg = trim($a); }
}

// --------------------------------------------------------------------
t_head('1. This computer');
// --------------------------------------------------------------------
echo 'PHP ' . PHP_VERSION . "\n";
version_compare(PHP_VERSION, '7.4.0', '>=') ? t_pass('PHP is 7.4 or newer') : t_fail('PHP is too old (need 7.4+)');
extension_loaded('curl') ? t_pass('cURL extension is on') : t_fail('cURL extension is OFF', 'enable extension=curl in php.ini');
function_exists('imagecreatefromwebp')
    ? t_pass('GD can convert WEBP photos')
    : t_info('GD cannot read WEBP here (JPG/PNG photos still work; WEBP uploads would be refused by the page)');

// --------------------------------------------------------------------
t_head('2. Settings (config.local.php)');
// --------------------------------------------------------------------
$base = __DIR__;
if (!is_file($base . '/config.local.php')) {
    t_fail('config.local.php not found next to this script', 'run the command from the BloominousWeb folder');
    exit(1);
}
require $base . '/config.local.php';

if (!is_file($base . '/includes/pixazo_hyper3d.php')) {
    t_fail('includes/pixazo_hyper3d.php not found');
    exit(1);
}
require $base . '/includes/pixazo_hyper3d.php';

$key = bloom_px_key();
if ($key === '') {
    t_fail('PIXAZO_API_KEY is empty', "add putenv('PIXAZO_API_KEY=...'); to config.local.php");
} else {
    t_pass('PIXAZO_API_KEY is set (' . strlen($key) . ' characters, starts with "' . substr($key, 0, 4) . '...")');
}
foreach (['SUPABASE_URL', 'SUPABASE_SECRET_KEY'] as $name) {
    trim((string) getenv($name)) !== '' ? t_pass("$name is set") : t_fail("$name is empty", 'needed to host the reference photo for Pixazo');
}
t_info('SUPABASE_BUCKET = ' . (trim((string) getenv('SUPABASE_BUCKET')) !== '' ? trim((string) getenv('SUPABASE_BUCKET')) : '(empty -> "models")'));
if (trim((string) getenv('TRIPO_API_KEY')) !== '') {
    t_info('TRIPO_API_KEY is still in config.local.php. Nothing uses it now; you may delete that line.');
}

// --------------------------------------------------------------------
t_head('3. Files: syntax + no Tripo left');
// --------------------------------------------------------------------
$phpFiles = [
    'generate_3d_model.php',
    'check_3d_model_status.php',
    'download_3d_model_result.php',
    'preview_3d_model.php',
    'includes/pixazo_hyper3d.php',
];
foreach ($phpFiles as $file) {
    $path = $base . '/' . $file;
    if (!is_file($path)) { t_fail("$file is missing"); continue; }

    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    $code === 0 ? t_pass("$file: no syntax errors") : t_fail("$file: syntax error", implode(' ', $out));

    // The old Tripo web address / key name must not appear in any CODE that runs.
    $source = (string) file_get_contents($path);
    $hits = [];
    if (preg_match('/openapi\.tripo3d/i', $source)) { $hits[] = 'openapi.tripo3d (a Tripo web call)'; }
    if (preg_match('/getenv\(\s*[\'"]TRIPO_API_KEY/i', $source)) { $hits[] = 'getenv(TRIPO_API_KEY)'; }
    $hits ? t_fail("$file still calls Tripo", implode(', ', $hits)) : t_pass("$file: no Tripo calls");
}

// --------------------------------------------------------------------
t_head('4. Task id wrapping (what Recover relies on)');
// --------------------------------------------------------------------
$sample = 'hyper3d-rodin-v2-5-image-to-3d_019d1234-5678-9abc-def0-123456789abc';
$wrapped = bloom_px_task_id($sample);
(bloom_px_request_id($wrapped) === $sample) ? t_pass('request id -> px_ id -> request id comes back identical') : t_fail('id round trip broke');
preg_match('/^[A-Za-z0-9_-]{1,128}$/', $wrapped) ? t_pass('px_ id passes the safe-id check') : t_fail('px_ id has illegal characters');
bloom_px_is_task($wrapped) ? t_pass('px_ ids are recognised') : t_fail('px_ id not recognised');
!bloom_px_is_task('aef2dc4a-23a9-4426-af6b-ceec354a61a4') ? t_pass('an old Tripo-style id is NOT treated as a Hyper3D id') : t_fail('old Tripo id mistaken for Hyper3D');

// --------------------------------------------------------------------
t_head('5. Can we talk to Pixazo with this key? (free read-only call)');
// --------------------------------------------------------------------
if ($key === '') {
    t_info('skipped: no key');
} else {
    try {
        $reply = bloom_px_get_status(bloom_px_task_id('bloom-selftest-this-job-does-not-exist'));
        $code = $reply['code'];
        if ($code === 401 || $code === 403) {
            t_fail("Pixazo rejected the key (HTTP $code)", bloom_px_error_text($reply));
        } elseif ($code === 404) {
            t_pass('Pixazo accepted the key (HTTP 404 = "no such job", exactly what we expect for a fake id)');
        } else {
            t_info("Pixazo answered HTTP $code for a fake id: " . bloom_px_error_text($reply) . ' (not a key problem, so counting it as reachable)');
            t_pass('Pixazo is reachable');
        }
    } catch (Throwable $e) {
        t_fail('Could not reach Pixazo', $e->getMessage());
    }
}

// --------------------------------------------------------------------
t_head('6. Hosting the reference photo on Supabase (the step before the paid call)');
// --------------------------------------------------------------------
if ($skipUpload) {
    t_info('skipped (--skip-upload)');
} else {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $tmp = tempnam(sys_get_temp_dir(), 'bloom_px_');
    file_put_contents($tmp, $png);
    try {
        $url = bloom_px_image_url($tmp, 'image/png');
        t_pass('Supabase accepted the test picture');
        t_info($url);

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        ($http === 200 && $body !== false && substr($body, 1, 3) === 'PNG')
            ? t_pass('the public link opens without a login (Pixazo needs exactly this)')
            : t_fail("the public link did NOT open (HTTP $http)", 'make the Supabase bucket PUBLIC');
    } catch (Throwable $e) {
        t_fail('Supabase hosting failed', $e->getMessage());
    }
    @unlink($tmp);
}

// --------------------------------------------------------------------
t_head('7. Recover an OLD job (optional, free)');
// --------------------------------------------------------------------
if ($jobArg === '') {
    t_info('skipped. To test recovery, run again with a real job id from an earlier generation:');
    t_info('  php test_pixazo_only.php px_XXXXXXXX');
} elseif ($key === '') {
    t_info('skipped: no key');
} else {
    $taskId = (strpos($jobArg, 'px_') === 0) ? $jobArg : bloom_px_task_id($jobArg);
    t_info('Using task id: ' . $taskId);
    try {
        $state = bloom_px_status($taskId);
        t_info('Pixazo says: ' . $state['statusText'] . ($state['message'] !== '' ? ' (' . $state['message'] . ')' : ''));

        if ($state['done']) {
            $glb = bloom_px_model_url($taskId);
            t_pass('Pixazo still has this job AND returned a model link');
            t_info($glb);

            // Ask for only the first 12 bytes: a real .glb starts with "glTF".
            $ch = curl_init($glb);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RANGE => '0-11', CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
            $head = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            ($head !== false && substr((string) $head, 0, 4) === 'glTF' && ($http === 200 || $http === 206))
                ? t_pass('the link downloads a real .glb model, so Recover / Preview will work for this job')
                : t_fail("the model link did not give a valid .glb (HTTP $http)", 'Pixazo may have expired the file');
        } elseif ($state['failed']) {
            t_fail('this job FAILED or no longer exists at Pixazo', $state['message']);
        } else {
            t_info('the job is not finished yet; try again in a minute');
        }
    } catch (Throwable $e) {
        t_fail('Recovery check failed', $e->getMessage());
    }
}

echo "\n--------------------------------------------\n";
echo "RESULT: $pass passed, $fail failed\n";
echo $fail === 0 ? "All good. NO credit was used by this test.\n" : "Fix the [FAIL] lines above, then run it again.\n";
exit($fail === 0 ? 0 : 1);