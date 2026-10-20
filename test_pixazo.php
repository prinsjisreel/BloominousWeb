<?php
/**
 * BLOOMINOUS - Free test of the Hyper3D (Pixazo) backup. NO generation is started.
 * Run from the BloominousWeb folder:
 *     php test_pixazo.php            (checks 1-4, all free)
 *     php test_pixazo.php submit     (also checks 5: a deliberately EMPTY job, rejected before any model is built)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }

require_once __DIR__ . '/config.local.php';
require_once __DIR__ . '/includes/pixazo_hyper3d.php';

function line(string $label, bool $ok, string $detail = ''): void
{
    echo ($ok ? '[ OK ] ' : '[FAIL] ') . $label . ($detail !== '' ? "  ->  $detail" : '') . PHP_EOL;
}

echo "== Pixazo backup test (no credit used) ==" . PHP_EOL;

// 1. Offline logic (no internet needed)
$id = 'hyper3d-rodin-v2-5-image-to-3d_019d1234-5678-9abc-def0-123456789abc';
$task = bloom_px_task_id($id);
line('Task id is safe + reversible',
    preg_match('/^[A-Za-z0-9_-]{1,128}$/', $task) === 1 && bloom_px_request_id($task) === $id, $task);
line('Finds the .glb in a sample result',
    bloom_px_find_glb_url(['model_mesh' => ['url' => 'https://x.com/output.glb']]) === 'https://x.com/output.glb');

// 2. Key is set
line('PIXAZO_API_KEY is set in config.local.php', bloom_px_enabled(), bloom_px_enabled() ? '(hidden)' : 'add the putenv line');
if (!bloom_px_enabled()) { exit(1); }

// 3. Key accepted? Ask about a job that does not exist (free).
try {
    $reply = bloom_px_get_status($task);
    $code = $reply['code'];
    if ($code === 401 || $code === 403) {
        line('Pixazo accepts the key', false, "HTTP $code - key is wrong, expired, or has no access");
    } elseif ($code === 404 || $code === 400 || $code === 422) {
        line('Pixazo accepts the key', true, "HTTP $code for a fake job = key works, job simply does not exist");
    } else {
        line('Pixazo reachable', true, "HTTP $code: " . substr($reply['raw'], 0, 160));
    }
} catch (Throwable $e) {
    line('Pixazo reachable', false, $e->getMessage());
}

// 4. Can we host a photo publicly on Supabase? Uploads a 1-pixel PNG (free).
try {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $tmp = tempnam(sys_get_temp_dir(), 'pxtest_');
    file_put_contents($tmp, $png);
    $url = bloom_px_image_url($tmp, 'image/png');
    @unlink($tmp);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    line('Photo hosted with a PUBLIC link (Pixazo needs this)', $http === 200, "HTTP $http  $url");
} catch (Throwable $e) {
    line('Photo hosting on Supabase', false, $e->getMessage());
}

// 5. Optional: send an EMPTY job. No image = nothing to build, so it should be rejected.
if (($argv[1] ?? '') === 'submit') {
    try {
        $reply = bloom_px_http('POST', BLOOM_PX_SUBMIT_URL, ['image_urls' => [], 'geometry_file_format' => 'glb'], 60);
        $rejected = $reply['code'] >= 400;
        line('Empty job is rejected (nothing built)', $rejected,
            "HTTP {$reply['code']}: " . substr($reply['raw'], 0, 160));
        if (!$rejected) {
            echo "       WARNING: Pixazo accepted it. Check your console for request "
               . ($reply['json']['request_id'] ?? '?') . PHP_EOL;
        }
    } catch (Throwable $e) {
        line('Submit endpoint reachable', false, $e->getMessage());
    }
}
echo "== done ==" . PHP_EOL;