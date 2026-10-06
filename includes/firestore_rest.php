<?php
/**
 * BLOOMINOUS - Firestore REST Toolkit (no gRPC)
 *
 * WHY THIS FILE EXISTS: bloom_firestore() (the gRPC FirestoreClient) crashes
 * PHP outright on the local XAMPP install — the browser only sees
 * ERR_CONNECTION_RESET. firebase_admin.php already has REST get / set /
 * equality-query / delete helpers; this file adds the pieces the checkout
 * + payment flow needs, on the same no-gRPC, service-account trust model:
 *
 *   bloom_firestore_update_fields_rest()        partial update (like ->update())
 *   bloom_firestore_add_document_rest()         auto-ID create (like ->add())
 *   bloom_firestore_transaction_rest()          read-then-write transaction
 *                                               (like ->runTransaction())
 *   bloom_firestore_get_document_by_path_rest() read a NESTED document, e.g.
 *                                               branches/{id}/inventory/{id}
 *
 * It reuses bloom_firestore_rest_context() and the decoder from
 * firebase_admin.php, so there is still ONE place that owns credentials.
 *
 * Timestamps: REST has no FieldValue::serverTimestamp(), so callers pass a
 * real DateTimeImmutable (bloom_rest_now()) — the same approach
 * rate_limiter.php and set_session.php already use.
 */

require_once __DIR__ . '/firebase_admin.php';

/** "Now" as a value the encoder stores as a real Firestore timestamp. */
function bloom_rest_now(): \DateTimeImmutable
{
    return new \DateTimeImmutable('now');
}

/** Base URL: .../projects/{id}/databases/(default)/documents */
function bloom_rest_documents_url(string $projectId): string
{
    return sprintf('https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents', $projectId);
}

/** Full resource name used inside commit() writes. */
function bloom_rest_document_name(string $projectId, string $collection, string $docId): string
{
    return sprintf('projects/%s/databases/(default)/documents/%s/%s', $projectId, $collection, $docId);
}

/**
 * Encoder with one fix over bloom_encode_firestore_value(): an EMPTY PHP
 * array is saved as an empty LIST. The original helper checks
 * array_keys($v) === range(0, count($v) - 1), and range(0, -1) is [0, -1],
 * so [] was silently stored as an empty MAP — breaking fields like
 * fraudFlags that must stay a list.
 */
function bloom_rest_encode_value($value): array
{
    if ($value instanceof \DateTimeInterface) {
        $utc = (new \DateTimeImmutable('@' . $value->getTimestamp()))
            ->setTimezone(new \DateTimeZone('UTC'));
        return ['timestampValue' => $utc->format('Y-m-d\TH:i:s\Z')];
    }
    if (is_string($value)) return ['stringValue' => $value];
    if (is_int($value))    return ['integerValue' => (string) $value];
    if (is_float($value))  return ['doubleValue' => $value];
    if (is_bool($value))   return ['booleanValue' => $value];
    if ($value === null)   return ['nullValue' => null];
    if (is_array($value)) {
        if ($value === [] || bloom_array_is_list($value)) {
            return ['arrayValue' => ['values' => array_map('bloom_rest_encode_value', array_values($value))]];
        }
        return ['mapValue' => ['fields' => bloom_rest_encode_fields($value)]];
    }
    throw new \InvalidArgumentException('Unsupported Firestore value type: ' . gettype($value));
}

function bloom_rest_encode_fields(array $fields)
{
    $out = [];
    foreach ($fields as $key => $value) {
        $out[(string) $key] = bloom_rest_encode_value($value);
    }
    // json_encode([]) is "[]", but Firestore wants an object "{}".
    return $out === [] ? (object) [] : $out;
}

/** array_is_list() only exists on PHP 8.1+; this works on older XAMPP builds too. */
function bloom_array_is_list(array $value): bool
{
    $i = 0;
    foreach ($value as $key => $_) {
        if ($key !== $i++) return false;
    }
    return true;
}

/** Field names with symbols must be `backticked` in update masks. */
function bloom_rest_field_path(string $field): string
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
        return $field;
    }
    return '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], $field) . '`';
}

/**
 * Reads ONE document by its full path, including nested subcollections,
 * e.g. "branches/main_branch/inventory/abc123". Returns the decoded fields,
 * or null if the document doesn't exist.
 *
 * WHY A NEW HELPER: every other helper here builds its URL with
 * rawurlencode($collection), which turns the slashes in a nested path into
 * %2F — Firestore then looks for ONE collection literally named
 * "branches/main_branch/inventory" and fails. This one encodes each path
 * segment separately and keeps the slashes between them.
 */
function bloom_firestore_get_document_by_path_rest(string $documentPath): ?array
{
    $segments = explode('/', trim($documentPath, '/'));

    // A document path is always collection/doc pairs: an EVEN number of parts.
    if (count($segments) < 2 || count($segments) % 2 !== 0) {
        throw new \InvalidArgumentException('Not a document path: ' . $documentPath);
    }
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new \InvalidArgumentException('Invalid segment in document path: ' . $documentPath);
        }
    }

    [$httpClient, $projectId, $accessToken] = bloom_firestore_rest_context();
    $url = bloom_rest_documents_url($projectId) . '/' . implode('/', array_map('rawurlencode', $segments));

    try {
        $response = $httpClient->request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
            return null;
        }
        throw $e;
    }

    $body = json_decode((string) $response->getBody(), true) ?? [];
    return bloom_decode_firestore_fields($body['fields'] ?? []);
}

/**
 * Partial update of an EXISTING document — only the keys in $fields change,
 * every other field is left alone (unlike bloom_firestore_set_document_rest,
 * which replaces the whole document). Fails if the document doesn't exist.
 */
function bloom_firestore_update_fields_rest(string $collection, string $docId, array $fields): void
{
    if ($fields === []) {
        return;
    }
    [$httpClient, $projectId, $accessToken] = bloom_firestore_rest_context();

    $url = bloom_rest_documents_url($projectId) . '/' . rawurlencode($collection) . '/' . rawurlencode($docId);

    // updateMask.fieldPaths must repeat once per field; built by hand because
    // Guzzle's array query format would produce fieldPaths[0]=..., which
    // Firestore rejects.
    $queryParts = ['currentDocument.exists=true'];
    foreach (array_keys($fields) as $field) {
        $queryParts[] = 'updateMask.fieldPaths=' . rawurlencode(bloom_rest_field_path((string) $field));
    }

    $httpClient->request('PATCH', $url . '?' . implode('&', $queryParts), [
        'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        'json'    => ['fields' => bloom_rest_encode_fields($fields)],
    ]);
}

/**
 * Creates a document with a Firestore-generated ID (like ->add()).
 * Returns the new document's ID.
 */
function bloom_firestore_add_document_rest(string $collection, array $data): string
{
    [$httpClient, $projectId, $accessToken] = bloom_firestore_rest_context();

    $response = $httpClient->request('POST', bloom_rest_documents_url($projectId) . '/' . rawurlencode($collection), [
        'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        'json'    => ['fields' => bloom_rest_encode_fields($data)],
    ]);

    $body = json_decode((string) $response->getBody(), true) ?? [];
    $parts = explode('/', $body['name'] ?? '');
    return (string) end($parts);
}

/**
 * The object handed to your transaction callback. Reads go out immediately
 * (inside the transaction); writes are QUEUED and only sent together at
 * commit, so either all of them land or none do.
 */
class BloomRestTransaction
{
    private $httpClient;
    private $projectId;
    private $accessToken;
    private $transactionId;
    public $writes = [];

    public function __construct($httpClient, string $projectId, string $accessToken, string $transactionId)
    {
        $this->httpClient = $httpClient;
        $this->projectId = $projectId;
        $this->accessToken = $accessToken;
        $this->transactionId = $transactionId;
    }

    /** Reads one document inside the transaction. Returns null if missing. */
    public function get(string $collection, string $docId): ?array
    {
        $url = bloom_rest_documents_url($this->projectId) . '/' . rawurlencode($collection) . '/' . rawurlencode($docId);
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $this->accessToken],
                'query'   => ['transaction' => $this->transactionId],
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
        $body = json_decode((string) $response->getBody(), true) ?? [];
        return bloom_decode_firestore_fields($body['fields'] ?? []);
    }

    /** Queue a partial update (document must exist). */
    public function update(string $collection, string $docId, array $fields): void
    {
        $this->writes[] = [
            'update'          => [
                'name'   => bloom_rest_document_name($this->projectId, $collection, $docId),
                'fields' => bloom_rest_encode_fields($fields),
            ],
            'updateMask'      => ['fieldPaths' => array_map('bloom_rest_field_path', array_map('strval', array_keys($fields)))],
            'currentDocument' => ['exists' => true],
        ];
    }

    /** Queue a set. With $merge = true only the given fields change (like set(..., ['merge' => true])). */
    public function set(string $collection, string $docId, array $fields, bool $merge = false): void
    {
        $write = [
            'update' => [
                'name'   => bloom_rest_document_name($this->projectId, $collection, $docId),
                'fields' => bloom_rest_encode_fields($fields),
            ],
        ];
        if ($merge) {
            $write['updateMask'] = ['fieldPaths' => array_map('bloom_rest_field_path', array_map('strval', array_keys($fields)))];
        }
        $this->writes[] = $write;
    }
}

/**
 * Runs $callback(BloomRestTransaction $tx) as a Firestore transaction.
 * If another request changed the same documents first, Firestore rejects
 * the commit (HTTP 409 ABORTED) and we simply run the callback again with
 * fresh reads — the same retry behaviour ->runTransaction() gives you.
 *
 * Returns whatever the callback returns.
 */
function bloom_firestore_transaction_rest(callable $callback, int $maxAttempts = 5)
{
    [$httpClient, $projectId, $accessToken] = bloom_firestore_rest_context();
    $baseUrl = bloom_rest_documents_url($projectId);
    $headers = ['Authorization' => 'Bearer ' . $accessToken];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $begin = $httpClient->request('POST', $baseUrl . ':beginTransaction', [
            'headers' => $headers,
            'json'    => (object) [],
        ]);
        $transactionId = (json_decode((string) $begin->getBody(), true) ?? [])['transaction'] ?? null;
        if (!$transactionId) {
            throw new \RuntimeException('Firestore did not start a transaction.');
        }

        $tx = new BloomRestTransaction($httpClient, $projectId, $accessToken, $transactionId);

        try {
            $result = $callback($tx);
        } catch (\Throwable $e) {
            // Release the transaction's locks before bubbling the error up.
            try {
                $httpClient->request('POST', $baseUrl . ':rollback', [
                    'headers' => $headers,
                    'json'    => ['transaction' => $transactionId],
                ]);
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }

        try {
            $httpClient->request('POST', $baseUrl . ':commit', [
                'headers' => $headers,
                'json'    => ['writes' => $tx->writes, 'transaction' => $transactionId],
            ]);
            return $result;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            if ($status === 409 && $attempt < $maxAttempts) {
                usleep(100000 * $attempt); // brief back-off, then retry with fresh reads
                continue;
            }
            throw $e;
        }
    }

    throw new \RuntimeException('Firestore transaction failed after ' . $maxAttempts . ' attempts.');
}