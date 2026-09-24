<?php
/**
 * BLOOMINOUS - Firestore Test Data Cleanup (CLI ONLY)
 *
 * Deletes Firestore documents in bulk instead of removing them one-by-one
 * in the Firebase console. Runs through bloom_firestore() (includes/firebase_admin.php),
 * so it authenticates with the service account and IGNORES firestore.rules entirely -
 * treat this like a database admin tool, not a normal app request.
 *
 * SAFE BY DEFAULT: without --confirm, this only PRINTS what it would delete.
 * Nothing is removed from Firestore until you re-run the same command with --confirm.
 *
 * Usage:
 *   Preview docs where a field matches a value:
 *     php cleanup_test_data.php <collection> <field> <value>
 *
 *   Actually delete them:
 *     php cleanup_test_data.php <collection> <field> <value> --confirm
 *
 *   Wipe an ENTIRE collection (no field filter) - requires BOTH flags:
 *     php cleanup_test_data.php <collection> --all --confirm
 *
 * Examples:
 *   php cleanup_test_data.php orders isTestOrder true
 *   php cleanup_test_data.php orders isTestOrder true --confirm
 *   php cleanup_test_data.php orders customerEmail test@bloominous.dev --confirm
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script only runs from the command line.');
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/firebase_admin.php'; // gives us bloom_firestore()

// ---------------------------------------------------------
// 1. Parse CLI arguments. Flags (--confirm, --all) can appear
//    anywhere, so we pull them out first and treat whatever's
//    left over as positional (collection, field, value).
// ---------------------------------------------------------
$rawArgs = array_slice($argv, 1); // $argv[0] is always the script's own filename
$confirm = false;
$deleteAll = false;
$positional = [];

foreach ($rawArgs as $arg) {
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif ($arg === '--all') {
        $deleteAll = true;
    } else {
        $positional[] = $arg;
    }
}

$collection = $positional[0] ?? null;
$field = $positional[1] ?? null;
$value = $positional[2] ?? null;

if (!$collection) {
    fwrite(STDERR, "Usage: php cleanup_test_data.php <collection> <field> <value> [--confirm]\n");
    fwrite(STDERR, "       php cleanup_test_data.php <collection> --all --confirm\n");
    exit(1);
}

if (!$deleteAll && (!$field || $value === null)) {
    fwrite(STDERR, "Missing <field> and <value>. To wipe the whole collection instead, pass --all --confirm.\n");
    exit(1);
}

// CLI arguments always arrive as plain strings. Without this conversion,
// a boolean field like `isTestOrder` (stored as true/false in Firestore)
// would be compared against the literal text "true" and never match.
if ($value === 'true') {
    $value = true;
} elseif ($value === 'false') {
    $value = false;
} elseif (is_numeric($value)) {
    $value = $value + 0; // "+ 0" nudges PHP into picking int or float for us
}

// ---------------------------------------------------------
// 2. Find the matching documents first (read before write).
// ---------------------------------------------------------
$firestore = bloom_firestore();
$collectionRef = $firestore->collection($collection);

$query = $deleteAll ? $collectionRef : $collectionRef->where($field, '=', $value);
$documents = $query->documents();

$docRefs = [];
foreach ($documents as $document) {
    if ($document->exists()) {
        $docRefs[] = $document->reference();
    }
}

$count = count($docRefs);

if ($count === 0) {
    echo "No matching documents found in '{$collection}'. Nothing to do.\n";
    exit(0);
}

// ---------------------------------------------------------
// 3. Dry run vs. real deletion.
// ---------------------------------------------------------
if (!$confirm) {
    echo "DRY RUN - would delete {$count} document(s) from '{$collection}':\n";
    foreach ($docRefs as $ref) {
        echo "  - {$ref->id()}\n";
    }
    echo "\nRe-run the exact same command with --confirm to actually delete these. This cannot be undone.\n";
    exit(0);
}

// Firestore batched writes cap out at 500 operations per batch, so large
// cleanups are split into chunks of 500 and committed one chunk at a time.
$chunks = array_chunk($docRefs, 500);
$deleted = 0;

foreach ($chunks as $chunk) {
    $batch = $firestore->batch();
    foreach ($chunk as $ref) {
        $batch->delete($ref);
    }
    $batch->commit();
    $deleted += count($chunk);
    echo "Deleted {$deleted} / {$count}...\n";
}

echo "Done. Deleted {$count} document(s) from '{$collection}'.\n";