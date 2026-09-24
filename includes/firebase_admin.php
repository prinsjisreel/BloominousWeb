<?php
/**
 * BLOOMINOUS - Firebase Admin SDK Bootstrap
 *
 * Server-side only. Uses the service account key to talk to Firestore /
 * Firebase Auth with full privileges (bypasses firestore.rules by design).
 *
 * DO NOT expose this file or serviceAccountKey.json publicly. Anything that
 * needs to trust a computed value (fraud score, restriction state, order
 * totals) must be computed here, never accepted verbatim from the browser.
 */

// ---------------------------------------------------------------------------
// Shutdown tracer — registered FIRST so any fatal in this bootstrap or in
// anything it requires lands in firebase_admin_trace.log with file:line.
// Convert's an opaque Apache ERR_CONNECTION_RESET into a diagnosable message.
// NOTE: this can ONLY catch PHP-level fatals — a native extension crash
// (confirmed to happen with this environment's grpc extension) bypasses
// PHP's shutdown machinery entirely and produces NO log line at all. An
// unchanged log file after a failed request is itself the diagnostic
// signal in that case.
// ---------------------------------------------------------------------------
register_shutdown_function(function () {
    $e = error_get_last();
    $line = $e
        ? "FATAL: {$e['message']} in {$e['file']}:{$e['line']}"
        : 'clean exit';
    @file_put_contents(__DIR__ . '/firebase_admin_trace.log', date('c') . " — {$line}\n", FILE_APPEND);
});

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/firebase-config.php';

use Kreait\Firebase\Factory;
use Google\Cloud\Firestore\FirestoreClient;

/**
 * Returns a singleton Kreait Factory wired to the service account.
 */
function bloom_firebase_factory(): Factory
{
    static $factory = null;
    if ($factory === null) {
        $factory = (new Factory())->withServiceAccount(FIREBASE_SERVICE_ACCOUNT_JSON);
    }
    return $factory;
}

/**
 * Firestore, built on the Google Cloud client.
 *
 * NOTE: despite `'transport' => 'rest'` below, google/cloud-firestore's
 * FirestoreClient constructor calls requireGrpc() unconditionally and
 * always opens a gRPC connection internally — the 'transport' option does
 * NOT make this REST-only in this SDK version. This function requires the
 * PHP `grpc` extension to be installed and enabled to work at all.
 *
 * CONFIRMED this local environment's grpc extension crashes the entire
 * PHP process (not a catchable exception — a native-level crash) the
 * instant this function actually opens a real connection. set_session.php
 * no longer calls this function at all, for exactly that reason — see its
 * own comments. Still used by submit_order.php / restore_trust.php, which
 * work fine on Hostinger's server (different, working grpc install) but
 * would hit the same crash if tested locally.
 */
function bloom_firestore(): FirestoreClient
{
    static $firestore = null;
    if ($firestore === null) {
        global $firebaseConfig; // from includes/firebase-config.php
        $firestore = new FirestoreClient([
            'keyFilePath' => FIREBASE_SERVICE_ACCOUNT_JSON,
            'projectId' => $firebaseConfig['projectId'],
            'transport' => 'rest',
        ]);
    }
    return $firestore;
}

/**
 * Fetches a single Firestore document over the plain Firestore REST API,
 * authenticated with the same service account as bloom_firestore() — same
 * trust model, same server-side-only source of truth, just no gRPC.
 *
 * Intentionally narrow: only supports "get one document by collection +
 * id," which is all the login path (set_session.php) needs. It is NOT a
 * replacement for bloom_firestore() — no queries, no transactions. See
 * bloom_firestore_set_document_rest() below for its write-side companion,
 * used by rate_limiter.php AND now set_session.php.
 *
 * @throws \Throwable on auth/network failure — callers should let this
 *         bubble up rather than silently treating it as "no such user."
 */
function bloom_firestore_get_document_rest(string $collection, string $docId): ?array
{
    static $credentials = null;
    static $httpClient = null;
    static $projectId = null;

    if ($credentials === null) {
        global $firebaseConfig;
        $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials(
            'https://www.googleapis.com/auth/datastore',
            FIREBASE_SERVICE_ACCOUNT_JSON // accepts a file path directly
        );
        $httpClient = new \GuzzleHttp\Client();
        $projectId = $firebaseConfig['projectId'];
    }

    $token = $credentials->fetchAuthToken();
    if (empty($token['access_token'])) {
        throw new \RuntimeException('Could not obtain a Firestore access token.');
    }

    $url = sprintf(
        'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s/%s',
        $projectId,
        rawurlencode($collection),
        rawurlencode($docId)
    );

    try {
        $response = $httpClient->request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $token['access_token']],
        ]);
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
            return null; // document doesn't exist
        }
        throw $e;
    }

    $body = json_decode((string) $response->getBody(), true) ?? [];
    return bloom_decode_firestore_fields($body['fields'] ?? []);
}

/**
 * Decodes the Firestore REST API's typed field wrappers (stringValue,
 * integerValue, mapValue, arrayValue, ...) into a plain associative array,
 * matching what DocumentSnapshot::data() returns from the SDK.
 */
function bloom_decode_firestore_fields(array $fields): array
{
    $out = [];
    foreach ($fields as $key => $value) {
        $out[$key] = bloom_decode_firestore_value($value);
    }
    return $out;
}

function bloom_decode_firestore_value(array $value)
{
    if (array_key_exists('stringValue', $value)) return $value['stringValue'];
    if (array_key_exists('integerValue', $value)) return (int) $value['integerValue'];
    if (array_key_exists('doubleValue', $value)) return (float) $value['doubleValue'];
    if (array_key_exists('booleanValue', $value)) return $value['booleanValue'];
    if (array_key_exists('nullValue', $value)) return null;
    // FIXED: was returning the raw ISO string, which the encoder's
    // is_string() branch then re-wrote as a plain stringValue on any
    // read-decode-write round trip (exactly what set_session.php's
    // deviceHashes merge does on EVERY customer login). That silently
    // converted every real Timestamp field on the document — including
    // customers.restrictedUntil, the field the checkout restriction
    // banner reads — into a plain string, breaking any .toDate() call
    // downstream. Decoding into a real DateTimeImmutable makes this
    // symmetric with bloom_encode_firestore_value()'s DateTimeInterface
    // branch, so a round trip now preserves the Timestamp type correctly.
    if (array_key_exists('timestampValue', $value)) {
        return new \DateTimeImmutable($value['timestampValue']);
    }
    if (array_key_exists('referenceValue', $value)) return $value['referenceValue'];
    if (array_key_exists('mapValue', $value)) {
        return bloom_decode_firestore_fields($value['mapValue']['fields'] ?? []);
    }
    if (array_key_exists('arrayValue', $value)) {
        return array_map('bloom_decode_firestore_value', $value['arrayValue']['values'] ?? []);
    }
    return null;
}

// ============================================================
// REST write support (added for rate_limiter.php; now also used by
// set_session.php to avoid the confirmed-crashing gRPC client)
// ============================================================

/**
 * Writes (creates or fully overwrites) a single Firestore document over the
 * plain REST API — the write-side companion to
 * bloom_firestore_get_document_rest(). Same no-gRPC trust model.
 *
 * Uses PATCH with no updateMask, which Firestore's REST API treats as a
 * FULL document replace (equivalent to .set() without merge). This means
 * $data MUST contain every field you want the document to keep — any
 * existing field not included here is silently dropped. For a genuine
 * partial update of an existing multi-field document (like appending to
 * customers/{uid}.deviceHashes), read the document first, merge in PHP,
 * then pass the complete merged array here — see set_session.php for the
 * pattern.
 */
function bloom_firestore_set_document_rest(string $collection, string $docId, array $data): void
{
    static $credentials = null;
    static $httpClient = null;
    static $projectId = null;

    if ($credentials === null) {
        global $firebaseConfig;
        $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials(
            'https://www.googleapis.com/auth/datastore',
            FIREBASE_SERVICE_ACCOUNT_JSON
        );
        $httpClient = new \GuzzleHttp\Client();
        $projectId = $firebaseConfig['projectId'];
    }

    $token = $credentials->fetchAuthToken();
    if (empty($token['access_token'])) {
        throw new \RuntimeException('Could not obtain a Firestore access token.');
    }

    $url = sprintf(
        'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s/%s',
        $projectId,
        rawurlencode($collection),
        rawurlencode($docId)
    );

    $httpClient->request('PATCH', $url, [
        'headers' => ['Authorization' => 'Bearer ' . $token['access_token']],
        'json' => ['fields' => bloom_encode_firestore_fields($data)],
    ]);
}

/**
 * Encodes a plain PHP associative array into the Firestore REST API's typed
 * field format (stringValue, integerValue, mapValue, ...) — the exact
 * inverse of bloom_decode_firestore_fields() above.
 */
function bloom_encode_firestore_fields(array $fields): array
{
    $out = [];
    foreach ($fields as $key => $value) {
        $out[$key] = bloom_encode_firestore_value($value);
    }
    return $out;
}

function bloom_encode_firestore_value($value): array
{
    // DateTime support — Firestore's REST API has no equivalent of
    // FieldValue::serverTimestamp() (that's a gRPC-SDK-only sentinel), so
    // any caller wanting a genuine Firestore timestamp field must pass a
    // real PHP DateTimeInterface instead. This branch must stay
    // symmetric with bloom_decode_firestore_value()'s timestampValue
    // branch above — a value read out as a DateTimeImmutable needs to
    // come back through here as a real timestampValue again on write,
    // not fall through to is_string() below.
    if ($value instanceof \DateTimeInterface) {
        $utc = (clone $value)->setTimezone(new \DateTimeZone('UTC'));
        return ['timestampValue' => $utc->format('Y-m-d\TH:i:s.u\Z')];
    }
    if (is_string($value)) return ['stringValue' => $value];
    if (is_int($value)) return ['integerValue' => (string) $value];
    if (is_float($value)) return ['doubleValue' => $value];
    if (is_bool($value)) return ['booleanValue' => $value];
    if ($value === null) return ['nullValue' => null];
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        return $isList
            ? ['arrayValue' => ['values' => array_map('bloom_encode_firestore_value', $value)]]
            : ['mapValue' => ['fields' => bloom_encode_firestore_fields($value)]];
    }
    throw new \InvalidArgumentException('Unsupported Firestore value type: ' . gettype($value));
}

// ============================================================
// End of REST write support
// ============================================================

function bloom_auth(): \Kreait\Firebase\Auth
{
    static $auth = null;
    if ($auth === null) {
        $auth = bloom_firebase_factory()->createAuth();
    }
    return $auth;
}

/**
 * Verifies a Firebase ID token sent from the client and returns the
 * authenticated UID. Throws on any failure (expired, forged, wrong project).
 *
 * This is the ONLY source of truth for "who is making this request" on
 * these endpoints — never trust $_SESSION['user_id'] for authorization
 * decisions, since it is set from an unauthenticated POST (see
 * includes/set_session.php) and can be forged.
 */
function bloom_verify_id_token(string $idToken): string
{
    $verifiedIdToken = bloom_auth()->verifyIdToken($idToken);
    return (string) $verifiedIdToken->claims()->get('sub');
}

/**
 * Pulls the Bearer token out of the Authorization header.
 */
function bloom_get_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? null;

    if (!$header && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $header = $v;
                break;
            }
        }
    }

    if (!$header || stripos($header, 'Bearer ') !== 0) {
        return null;
    }

    return trim(substr($header, 7));
}

function bloom_json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function bloom_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload);
    exit();
}

// ============================================================
// ← NEW: REST read/query/delete support (added for CLI cleanup
// scripts — bloom_firestore()'s gRPC requirement crashes PHP CLI
// with an access violation on this environment, same root cause
// already documented above and in set_session.php's own history.
// These stay on the same no-gRPC REST model as the get/set helpers.
// ============================================================

/**
 * Shared bootstrap (credentials + HTTP client + a fresh access token) used
 * by every REST helper below, so bloom_firestore_list_documents_rest(),
 * bloom_firestore_query_rest(), and bloom_firestore_delete_document_rest()
 * don't each duplicate this setup block the way the older get/set
 * functions above independently do.
 */
function bloom_firestore_rest_context(): array
{
    static $credentials = null;
    static $httpClient = null;
    static $projectId = null;

    if ($credentials === null) {
        global $firebaseConfig;
        $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials(
            'https://www.googleapis.com/auth/datastore',
            FIREBASE_SERVICE_ACCOUNT_JSON
        );
        $httpClient = new \GuzzleHttp\Client();
        $projectId = $firebaseConfig['projectId'];
    }

    $token = $credentials->fetchAuthToken();
    if (empty($token['access_token'])) {
        throw new \RuntimeException('Could not obtain a Firestore access token.');
    }

    return [$httpClient, $projectId, $token['access_token']];
}

/**
 * Lists every document in a collection over the REST API - the no-gRPC
 * equivalent of $firestore->collection($name)->documents(). Pages through
 * results automatically (Firestore returns at most ~300 docs per call).
 *
 * Returns an array of ['id' => docId, 'data' => [...decoded fields...]].
 * Any timestampValue fields come back as DateTimeImmutable, same as
 * bloom_firestore_get_document_rest() via bloom_decode_firestore_fields().
 */
function bloom_firestore_list_documents_rest(string $collection): array
{
    [$httpClient, $projectId, $accessToken] = bloom_firestore_rest_context();

    $results = [];
    $pageToken = null;

    do {
        $url = sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s',
            $projectId,
            rawurlencode($collection)
        );
        $query = ['pageSize' => 300];
        if ($pageToken) {
            $query['pageToken'] = $pageToken;
        }

        $response = $httpClient->request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'query' => $query,
        ]);

        $body = json_decode((string) $response->getBody(), true) ?? [];

        foreach ($body['documents'] ?? [] as $doc) {
            // "name" is the full resource path; the doc ID is its last segment.
            $parts = explode('/', $doc['name']);
            $id = end($parts);
            $results[] = ['id' => $id, 'data' => bloom_decode_firestore_fields($doc['fields'] ?? [])];
        }

        $pageToken = $body['nextPageToken'] ?? null;
    } while ($pageToken !== null);

    return $results;
}

/**
 * Runs a "WHERE field = value" equality query over the REST API - the
 * no-gRPC equivalent of $firestore->collection($c)->where($f, '=', $v).
 * Intentionally narrow (equality only, one field) since that's all the
 * cleanup scripts need; not a general query builder.
 *
 * Returns an array of ['id' => docId, 'data' => [...decoded fields...]].
 */
function bloom_firestore_query_rest(string $collection, string $field, $value): array
{
    [$httpClient, $projectId, $accessToken] = bloom_firestore_rest_context();

    $url = sprintf(
        'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents:runQuery',
        $projectId
    );

    $response = $httpClient->request('POST', $url, [
        'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        'json' => [
            'structuredQuery' => [
                'from' => [['collectionId' => $collection]],
                'where' => [
                    'fieldFilter' => [
                        'field' => ['fieldPath' => $field],
                        'op' => 'EQUAL',
                        'value' => bloom_encode_firestore_value($value),
                    ],
                ],
            ],
        ],
    ]);

    $rows = json_decode((string) $response->getBody(), true) ?? [];

    $results = [];
    foreach ($rows as $row) {
        if (!isset($row['document'])) {
            continue; // runQuery sends periodic empty "no match yet" entries
        }
        $parts = explode('/', $row['document']['name']);
        $id = end($parts);
        $results[] = ['id' => $id, 'data' => bloom_decode_firestore_fields($row['document']['fields'] ?? [])];
    }

    return $results;
}

/**
 * Deletes a single document over the REST API - the delete-side companion
 * to bloom_firestore_set_document_rest(). A 404 (already gone) is treated
 * as success rather than an error, so re-running a cleanup script twice
 * is always safe.
 */
function bloom_firestore_delete_document_rest(string $collection, string $docId): void
{
    [$httpClient, $projectId, $accessToken] = bloom_firestore_rest_context();

    $url = sprintf(
        'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s/%s',
        $projectId,
        rawurlencode($collection),
        rawurlencode($docId)
    );

    try {
        $httpClient->request('DELETE', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        if (!$e->getResponse() || $e->getResponse()->getStatusCode() !== 404) {
            throw $e;
        }
    }
}

// ============================================================
// End of new REST read/query/delete support
// ============================================================