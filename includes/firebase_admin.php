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
 * Used by submit_order.php / restore_trust.php for transactions, queries,
 * and writes. The login path does NOT use this — see
 * bloom_firestore_get_document_rest() below, which reads single documents
 * over plain HTTPS and needs no gRPC. rate_limiter.php also avoids this
 * for the same reason — see bloom_firestore_set_document_rest() below.
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
 * used by rate_limiter.php.
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
    if (array_key_exists('timestampValue', $value)) return $value['timestampValue'];
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
// ← NEW: REST write support (added for rate_limiter.php)
// ============================================================

/**
 * Writes (creates or fully overwrites) a single Firestore document over the
 * plain REST API — the write-side companion to
 * bloom_firestore_get_document_rest(). Same no-gRPC trust model.
 *
 * Uses PATCH with no updateMask, which Firestore's REST API treats as a
 * full document replace (equivalent to .set() without merge) — exactly
 * what rate_limiter.php needs, since it always writes the complete current
 * state of a counter document, never a partial update.
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
// End of new REST write support
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
    echo json_encode($payload);
    exit();
}