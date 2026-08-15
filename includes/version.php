<?php
/**
 * BLOOM System Version Helper
 * ----------------------------------------------------------------
 * Reads version.json (auto-bumped by the git pre-commit hook every
 * time a commit is made - see .githooks/pre-commit) and pairs it
 * with the current commit hash so the footer always shows exactly
 * what code is live, without needing shell_exec() on the server.
 */

function bloom_get_version(): array
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    $default = [
        'major'      => 1,
        'minor'      => 0,
        'patch'      => 0,
        'build'      => 0,
        'updated_at' => null,
    ];

    $jsonPath = __DIR__ . '/../version.json';
    $data = $default;

    if (file_exists($jsonPath)) {
        $decoded = json_decode(file_get_contents($jsonPath), true);
        if (is_array($decoded)) {
            $data = array_merge($default, $decoded);
        }
    }

    $data['commit'] = bloom_get_commit_hash();

    $version = $data;
    return $version;
}

/**
 * Reads the short commit hash directly from the .git folder.
 * Avoids shell_exec() so it still works on hosts where that's disabled.
 */
function bloom_get_commit_hash(): string
{
    $gitDir = __DIR__ . '/../.git';
    $headFile = $gitDir . '/HEAD';

    if (!file_exists($headFile)) {
        return 'unknown';
    }

    $head = trim(file_get_contents($headFile));

    // HEAD normally looks like: "ref: refs/heads/main"
    if (strpos($head, 'ref:') === 0) {
        $refPath = $gitDir . '/' . trim(substr($head, 5));
        if (file_exists($refPath)) {
            $hash = trim(file_get_contents($refPath));
        } else {
            // Branch has no loose ref file yet (e.g. just after gc) - check packed-refs
            $hash = bloom_get_hash_from_packed_refs($gitDir, trim(substr($head, 5)));
        }
    } else {
        // Detached HEAD - the hash is written directly
        $hash = $head;
    }

    return $hash ? substr($hash, 0, 7) : 'unknown';
}

function bloom_get_hash_from_packed_refs(string $gitDir, string $ref): ?string
{
    $packedRefs = $gitDir . '/packed-refs';
    if (!file_exists($packedRefs)) {
        return null;
    }
    foreach (file($packedRefs) as $line) {
        if (str_ends_with(trim($line), $ref)) {
            return explode(' ', trim($line))[0];
        }
    }
    return null;
}

/**
 * Public-facing formatted string, e.g. "v1.0.7 (build 7 · a04a6e3)"
 */
function bloom_version_string(): string
{
    $v = bloom_get_version();
    return sprintf(
        'v%d.%d.%d (build %d · %s)',
        $v['major'],
        $v['minor'],
        $v['patch'],
        $v['build'],
        $v['commit']
    );
}