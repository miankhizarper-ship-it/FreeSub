<?php

declare(strict_types=1);

/**
 * Cloudflare v4 API client for DNS records.
 *
 * Scope of this class: HTTP, response parsing, retries and error classification.
 * It knows nothing about FreeSub's tables, users or authorization — that is
 * DnsManager's job. Keeping the split means the record rules can be tested
 * without a network and the transport can be stubbed.
 *
 * Rules enforced here:
 *   * Credentials come from config (environment) at call time. They are never
 *     stored on a property that could be dumped, never logged and never put in
 *     an exception message.
 *   * Every request is built from a fixed base URL plus a path assembled from
 *     validated identifiers — there is no caller-supplied URL, so this cannot
 *     be turned into an SSRF primitive.
 *   * Writes are never blind-retried. A transport failure or 5xx on a write is
 *     surfaced as an *ambiguous* exception; the caller reconciles first.
 *   * Reads are retried on 429/5xx with exponential backoff, honouring
 *     Retry-After.
 *
 * Not `final`, for one reason: the test suite subclasses it to replace
 * sleepMs() so retry-policy tests run instantly. Mirrors ClerkApiClient, which
 * has the same seam.
 */
class CloudflareApiClient
{
    private string $token;
    private string $baseUrl;
    private ?Closure $transport = null;
    /** @var Closure(string, string, array<string, mixed>|null): array{status: int, body: string}|null */
    private static ?Closure $defaultTransport = null;

    private int $connectTimeout;
    private int $requestTimeout;
    private int $maxRetries;
    private int $retryBackoffMs;
    private int $perPage;
    private int $maxPages;

    /**
     * @param array<string, mixed>|null $options connect_timeout, request_timeout, max_retries,
     *                                           retry_backoff_ms, per_page, max_pages
     */
    public function __construct(?string $token = null, ?string $baseUrl = null, ?array $options = null)
    {
        $options ??= [];

        $this->token = trim($token ?? (string) config('dns.cloudflare.api_token', ''));
        $this->baseUrl = rtrim(
            $baseUrl ?? (string) config('dns.cloudflare.base_url', 'https://api.cloudflare.com/client/v4'),
            '/'
        );

        $this->connectTimeout = max(1, (int) ($options['connect_timeout'] ?? config('dns.cloudflare.connect_timeout', 5)));
        $this->requestTimeout = max(1, (int) ($options['request_timeout'] ?? config('dns.cloudflare.request_timeout', 15)));
        $this->maxRetries = max(0, min(3, (int) ($options['max_retries'] ?? config('dns.cloudflare.max_retries', 2))));
        $this->retryBackoffMs = max(0, (int) ($options['retry_backoff_ms'] ?? config('dns.cloudflare.retry_backoff_ms', 250)));
        $this->perPage = max(1, min(1000, (int) ($options['per_page'] ?? config('dns.cloudflare.per_page', 100))));
        $this->maxPages = max(1, min(50, (int) ($options['max_pages'] ?? config('dns.cloudflare.max_pages', 10))));
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Configured zone id (a public identifier, not a credential). */
    public function zoneId(): string
    {
        return trim((string) config('dns.cloudflare.zone_id', ''));
    }

    /**
     * Replace the HTTP layer (tests / offline environments).
     *
     * @param Closure(string, string, array<string, mixed>|null): array{status: int, body: string}|null $transport
     */
    public function setTransport(?Closure $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Process-wide transport override, mirroring ClerkApiClient::setDefaultTransport.
     *
     * @param Closure(string, string, array<string, mixed>|null): array{status: int, body: string}|null $transport
     */
    public static function setDefaultTransport(?Closure $transport): void
    {
        self::$defaultTransport = $transport;
    }

    /**
     * @return Closure(string, string, array<string, mixed>|null): array{status: int, body: string}
     */
    public function transportClosure(): Closure
    {
        $client = $this;

        return static fn (string $method, string $path, ?array $payload = null): array => $client->request($method, $path, $payload);
    }

    /* ---------------------------------------------------------------------
     * Zone
     * ------------------------------------------------------------------ */

    /**
     * Fetch the configured zone.
     *
     * @return array<string, mixed> The Cloudflare `result` object
     */
    public function getZone(?string $zoneId = null): array
    {
        $zoneId = $this->requireZoneId($zoneId);
        $response = $this->request('GET', '/zones/' . $zoneId);

        return $this->resultObject($response);
    }

    /**
     * Prove the configured zone exists, is active, and belongs to the expected
     * root domain. Nothing is written until this passes.
     *
     * @return array{
     *     ok: bool,
     *     code: string,
     *     zone_id: string,
     *     zone_name: string|null,
     *     status: string|null,
     *     nameservers: list<string>,
     *     message: string
     * }
     */
    public function verifyZone(string $expectedRootDomain, ?string $zoneId = null): array
    {
        $zoneId = $zoneId !== null && $zoneId !== '' ? $zoneId : $this->zoneId();
        $expected = strtolower(rtrim(trim($expectedRootDomain), '.'));

        $base = [
            'ok' => false,
            'code' => 'error',
            'zone_id' => $zoneId,
            'zone_name' => null,
            'status' => null,
            'nameservers' => [],
            'message' => '',
        ];

        if (!$this->isConfigured()) {
            return array_merge($base, ['code' => 'not_configured', 'message' => 'CLOUDFLARE_API_TOKEN is not set.']);
        }

        if ($zoneId === '') {
            return array_merge($base, ['code' => 'not_configured', 'message' => 'CLOUDFLARE_ZONE_ID is not set.']);
        }

        if ($expected === '') {
            return array_merge($base, ['code' => 'not_configured', 'message' => 'DNS_ROOT_DOMAIN is not set.']);
        }

        try {
            $zone = $this->getZone($zoneId);
        } catch (CloudflareApiException $exception) {
            $code = match ($exception->reason()) {
                'auth', 'forbidden' => 'auth',
                'not_found' => 'not_found',
                'rate_limited' => 'rate_limited',
                default => 'error',
            };

            return array_merge($base, [
                'code' => $code,
                'message' => match ($code) {
                    'auth' => 'The API token was rejected or lacks Zone/Zone/Read on this zone.',
                    // Cloudflare's own wording is reused when it exists; otherwise the
                // reason is described without echoing a response body.
                'not_found' => 'The zone id does not exist in this account (or the token cannot see it).',
                    'rate_limited' => 'Cloudflare rate limited the zone lookup. Try again shortly.',
                    default => 'The zone could not be read: ' . $exception->getMessage(),
                },
            ]);
        }

        $name = strtolower(rtrim((string) ($zone['name'] ?? ''), '.'));
        $status = isset($zone['status']) ? (string) $zone['status'] : null;
        $nameservers = array_values(array_filter(array_map(
            static fn ($ns): string => strtolower(rtrim((string) $ns, '.')),
            is_array($zone['name_servers'] ?? null) ? $zone['name_servers'] : []
        )));

        $result = array_merge($base, [
            'zone_name' => $name !== '' ? $name : null,
            'status' => $status,
            'nameservers' => $nameservers,
        ]);

        if ($name === '') {
            return array_merge($result, ['code' => 'error', 'message' => 'Cloudflare returned a zone without a name.']);
        }

        if ($name !== $expected) {
            // This is the guard that makes "arbitrary zone selection" impossible:
            // a token that happens to see other zones can only ever touch this one.
            return array_merge($result, [
                'code' => 'zone_mismatch',
                'message' => sprintf(
                    'Refusing to use zone %s: it does not match the configured root domain %s.',
                    $name,
                    $expected
                ),
            ]);
        }

        if ($status !== null && $status !== 'active') {
            return array_merge($result, [
                'code' => 'zone_pending',
                'message' => sprintf('Zone %s is in status "%s"; writes are refused until it is active.', $name, $status),
            ]);
        }

        return array_merge($result, [
            'ok' => true,
            'code' => 'ok',
            'message' => sprintf('Zone %s verified as active.', $name),
        ]);
    }

    /* ---------------------------------------------------------------------
     * Records
     * ------------------------------------------------------------------ */

    /**
     * List every DNS record in the configured zone.
     *
     * @param array{type?: string, name?: string} $filters Optional server-side filters
     * @return list<array<string, mixed>> Normalised records (id, type, name, content, ttl, proxied, ...)
     */
    public function listRecords(array $filters = [], ?string $zoneId = null): array
    {
        $zoneId = $this->requireZoneId($zoneId);
        $records = [];
        $page = 1;
        $totalPages = 1;

        do {
            $query = ['per_page' => $this->perPage, 'page' => $page];

            foreach (['type', 'name'] as $key) {
                if (isset($filters[$key]) && $filters[$key] !== '') {
                    $query[$key] = (string) $filters[$key];
                }
            }

            $response = $this->request('GET', '/zones/' . $zoneId . '/dns_records?' . http_build_query($query));
            // decode() is what validates `success`, so `result` is only read from
            // a response Cloudflare actually marked as successful.
            $decoded = $this->decode($response);
            $result = $decoded['result'];

            if (!is_array($result)) {
                throw new CloudflareApiException('Cloudflare returned an unexpected record list.', 'invalid_response', 0, false);
            }

            foreach ($result as $record) {
                if (is_array($record)) {
                    $records[] = self::normaliseRecord($record);
                }
            }

            $info = is_array($decoded['result_info']) ? $decoded['result_info'] : [];
            $totalPages = max(1, min($this->maxPages, (int) ($info['total_pages'] ?? 1)));

            // A page that is not full means there is nothing left, whatever the
            // pagination metadata claims.
            if (count($result) < $this->perPage) {
                break;
            }

            $page++;
        } while ($page <= $totalPages);

        return $records;
    }

    /**
     * Find one record by type and absolute name.
     *
     * @return array<string, mixed>|null
     */
    public function findRecord(string $type, string $name, ?string $zoneId = null): ?array
    {
        $type = strtoupper(trim($type));
        $name = strtolower(rtrim(trim($name), '.'));

        foreach ($this->listRecords(['type' => $type, 'name' => $name], $zoneId) as $record) {
            if (strcasecmp((string) $record['type'], $type) === 0 && (string) $record['name'] === $name) {
                return $record;
            }
        }

        return null;
    }

    /**
     * All records pointing at exactly this name (any type).
     *
     * @return list<array<string, mixed>>
     */
    public function recordsAtName(string $name, ?string $zoneId = null): array
    {
        $name = strtolower(rtrim(trim($name), '.'));

        return array_values(array_filter(
            $this->listRecords(['name' => $name], $zoneId),
            static fn (array $record): bool => (string) $record['name'] === $name
        ));
    }

    /**
     * Create a record. Never proxied, never retried.
     *
     * @return array<string, mixed> The created record as Cloudflare returned it
     */
    public function createRecord(
        string $type,
        string $name,
        string $content,
        int $ttl = 1,
        ?string $zoneId = null,
        ?string $comment = null
    ): array {
        $type = $this->requireWritableType($type);
        $zoneId = $this->requireZoneId($zoneId);

        $payload = [
            'type' => $type,
            'name' => strtolower(rtrim(trim($name), '.')),
            'content' => trim($content),
            'ttl' => self::normaliseTtl($ttl),
            // Proxying is a platform decision. A user can never turn it on.
            'proxied' => false,
        ];

        if ($comment !== null && $comment !== '') {
            $payload['comment'] = mb_substr($comment, 0, 100);
        }

        $response = $this->request('POST', '/zones/' . $zoneId . '/dns_records', $payload);

        return $this->resultObject($response);
    }

    /**
     * Replace an existing record. Never retried.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateRecord(string $recordId, array $payload, ?string $zoneId = null): array
    {
        $recordId = self::requireIdentifier($recordId, 'record id');
        $zoneId = $this->requireZoneId($zoneId);

        if (isset($payload['type'])) {
            $payload['type'] = $this->requireWritableType((string) $payload['type']);
        }

        if (isset($payload['ttl'])) {
            $payload['ttl'] = self::normaliseTtl((int) $payload['ttl']);
        }

        $payload['proxied'] = false;

        $response = $this->request('PATCH', '/zones/' . $zoneId . '/dns_records/' . $recordId, $payload);

        return $this->resultObject($response);
    }

    /**
     * Delete exactly one record by id. Never retried, never a wildcard.
     *
     * @return array<string, mixed> Cloudflare's `result` (usually ['id' => ...])
     */
    public function deleteRecord(string $recordId, ?string $zoneId = null): array
    {
        $recordId = self::requireIdentifier($recordId, 'record id');
        $zoneId = $this->requireZoneId($zoneId);

        $response = $this->request('DELETE', '/zones/' . $zoneId . '/dns_records/' . $recordId);

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    /* ---------------------------------------------------------------------
     * Transport
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function request(string $method, string $path, ?array $payload = null): array
    {
        $method = strtoupper($method);

        if (!in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            throw new CloudflareApiException('Unsupported HTTP method.', 'config');
        }

        // Only paths this client builds can be requested: the identifier
        // segments are validated before they get here, and anything with a
        // scheme or an authority is refused outright.
        if (preg_match('#^/[A-Za-z0-9_\-/\.?=&%:,]*$#', $path) !== 1) {
            throw new CloudflareApiException('Refusing to build a request for that path.', 'config');
        }

        if (!$this->isConfigured()) {
            throw new CloudflareApiException('CLOUDFLARE_API_TOKEN is not configured.', 'config');
        }

        $idempotent = $method === 'GET' || $method === 'PUT';
        $attempt = 0;

        while (true) {
            try {
                $response = $this->dispatch($method, $path, $payload);
            } catch (CloudflareApiException $exception) {
                // Transport failure. Retrying a write here is exactly the bug the
                // brief calls out: a timeout may follow a successful create.
                if (!$idempotent || $attempt >= $this->maxRetries) {
                    throw $exception;
                }

                $this->backoff($attempt);
                $attempt++;

                continue;
            }

            $status = $response['status'];

            if ($status >= 200 && $status < 300) {
                return $response;
            }

            if ($status === 0) {
                // The transport produced no HTTP response (timeout, reset,
                // DNS failure). Same policy as a transport exception: reads may
                // retry, writes must not.
                if (!$idempotent || $attempt >= $this->maxRetries) {
                    throw new CloudflareApiException(
                        'The Cloudflare API did not respond.',
                        'transport',
                        0,
                        // Ambiguous for a write: the request may have landed.
                        true
                    );
                }

                DnsLog::record('cloudflare.retry', ['method' => $method, 'status' => 0, 'attempt' => $attempt + 1]);
                $this->backoff($attempt);
                $attempt++;

                continue;
            }

            $parsed = self::parseError($status, $response['body']);
            $retryable = $idempotent && in_array($status, [429, 500, 502, 503, 504], true);

            if ($retryable && $attempt < $this->maxRetries) {
                DnsLog::record('cloudflare.retry', [
                    'method' => $method,
                    'status' => $status,
                    'attempt' => $attempt + 1,
                ]);
                $this->backoff($attempt, self::retryAfter($response['headers']));
                $attempt++;

                continue;
            }

            throw new CloudflareApiException(
                $parsed['message'],
                $parsed['reason'],
                $status,
                // A 5xx on a write is genuinely ambiguous; a 4xx is not.
                !$idempotent && $status >= 500,
                $parsed['code']
            );
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function dispatch(string $method, string $path, ?array $payload): array
    {
        $transport = $this->transport ?? self::$defaultTransport;

        if ($transport !== null) {
            $result = $transport($method, $path, $payload);

            if (!is_array($result) || !isset($result['status'])) {
                throw new CloudflareApiException('The HTTP transport returned no response.', 'transport', 0, true);
            }

            return [
                'status' => (int) $result['status'],
                'body' => (string) ($result['body'] ?? ''),
                'headers' => is_array($result['headers'] ?? null) ? array_change_key_case($result['headers'], CASE_LOWER) : [],
            ];
        }

        return $this->curl($method, $path, $payload);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function curl(string $method, string $path, ?array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new CloudflareApiException(
                'The PHP cURL extension is required for live DNS operations.',
                'config'
            );
        }

        $url = $this->baseUrl . $path;

        // Defence in depth: the base URL comes from the environment, so a typo or
        // a hostile .env must not be able to make this client talk plaintext to
        // the internet. Loopback http is allowed for the optional local stub.
        if (!$this->isAllowedUrl($url)) {
            throw new CloudflareApiException(
                'CLOUDFLARE_API_BASE_URL must be https (plain http is only allowed for loopback addresses).',
                'config'
            );
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'User-Agent: FreeSub/1.0 (+dns-engine)',
        ];

        $body = null;

        if ($payload !== null) {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }

        $handle = curl_init();
        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($responseBody === false || $errorNumber !== 0) {
            DnsLog::record('cloudflare.transport_error', [
                'method' => $method,
                'errno' => $errorNumber,
                // cURL messages never contain the request headers.
                'error' => DnsLog::scrub($errorMessage),
                'timeout' => in_array($errorNumber, [CURLE_OPERATION_TIMEDOUT], true),
            ]);

            throw new CloudflareApiException(
                $errorNumber === CURLE_OPERATION_TIMEDOUT
                    ? 'The Cloudflare API did not respond in time.'
                    : 'The Cloudflare API is unreachable.',
                'transport',
                $status,
                true
            );
        }

        return ['status' => $status, 'body' => (string) $responseBody, 'headers' => $responseHeaders];
    }

    /* ---------------------------------------------------------------------
     * Parsing helpers
     * ------------------------------------------------------------------ */

    /**
     * @return array{result: mixed, result_info: array<string, mixed>|null}
     */
    private function decode(array $response): array
    {
        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            throw new CloudflareApiException(
                'Cloudflare returned a response that is not JSON.',
                'invalid_response',
                $response['status']
            );
        }

        if (($decoded['success'] ?? false) !== true) {
            $parsed = self::parseError($response['status'], $response['body']);

            throw new CloudflareApiException($parsed['message'], $parsed['reason'], $response['status'], false, $parsed['code']);
        }

        return [
            'result' => $decoded['result'] ?? null,
            'result_info' => is_array($decoded['result_info'] ?? null) ? $decoded['result_info'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resultObject(array $response): array
    {
        $decoded = $this->decode($response);

        if (!is_array($decoded['result'])) {
            throw new CloudflareApiException('Cloudflare returned no result object.', 'invalid_response', $response['status']);
        }

        return $decoded['result'];
    }

    /**
     * Turn a Cloudflare error envelope into a safe message plus a stable reason.
     *
     * The API's own wording is kept (it is useful and contains no secrets) but
     * the response body is never returned wholesale to a caller.
     *
     * @return array{message: string, reason: string, code: string|null}
     */
    public static function parseError(int $status, string $body): array
    {
        $decoded = json_decode($body, true);
        $messages = [];
        $code = null;

        if (is_array($decoded) && is_array($decoded['errors'] ?? null)) {
            foreach ($decoded['errors'] as $error) {
                if (!is_array($error)) {
                    continue;
                }

                $code ??= isset($error['code']) ? (string) $error['code'] : null;
                $text = trim((string) ($error['message'] ?? ''));

                if ($text !== '') {
                    $messages[] = $text;
                }
            }
        }

        $reason = self::classify($status, $code);
        $fallback = match (true) {
            $status === 401 => 'The Cloudflare API token was rejected.',
            $status === 403 => 'The Cloudflare API token is missing a required permission.',
            $status === 404 => 'The requested Cloudflare resource does not exist.',
            $status === 429 => 'Cloudflare rate limited the request.',
            $status >= 500 => 'Cloudflare reported a server error.',
            default => 'The Cloudflare API rejected the request.',
        };

        $message = $messages === [] ? $fallback : mb_substr(implode(' ', $messages), 0, 300);

        return ['message' => DnsLog::scrub($message), 'reason' => $reason, 'code' => $code];
    }

    /** Map HTTP status and Cloudflare error code onto one stable reason. */
    public static function classify(int $status, ?string $code): string
    {
        // Documented Cloudflare codes for credentials and permissions.
        $authCodes = ['10000', '9103', '9106', '9109', '1000', '1010'];
        $rateCodes = ['971', '10024'];
        $notFoundCodes = ['7000', '7003', '81044'];

        if ($code !== null && in_array($code, $authCodes, true)) {
            return 'auth';
        }

        if ($code !== null && in_array($code, $rateCodes, true)) {
            return 'rate_limited';
        }

        if ($code !== null && in_array($code, $notFoundCodes, true)) {
            return 'not_found';
        }

        return match (true) {
            $status === 400, $status === 422 => 'validation',
            $status === 401 => 'auth',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server',
            $status === 0 => 'transport',
            default => 'error',
        };
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function normaliseRecord(array $record): array
    {
        return [
            'id' => (string) ($record['id'] ?? ''),
            'type' => strtoupper((string) ($record['type'] ?? '')),
            'name' => strtolower(rtrim((string) ($record['name'] ?? ''), '.')),
            'content' => (string) ($record['content'] ?? ''),
            'ttl' => (int) ($record['ttl'] ?? 1),
            'proxied' => (bool) ($record['proxied'] ?? false),
            'priority' => isset($record['priority']) ? (int) $record['priority'] : null,
            'comment' => isset($record['comment']) ? (string) $record['comment'] : null,
            'modified_on' => isset($record['modified_on']) ? (string) $record['modified_on'] : null,
            'created_on' => isset($record['created_on']) ? (string) $record['created_on'] : null,
        ];
    }

    /* ---------------------------------------------------------------------
     * Small guards
     * ------------------------------------------------------------------ */

    private function requireZoneId(?string $zoneId = null): string
    {
        $zoneId = $zoneId !== null && $zoneId !== '' ? $zoneId : $this->zoneId();

        return self::requireIdentifier($zoneId, 'zone id');
    }

    private function requireWritableType(string $type): string
    {
        $type = strtoupper(trim($type));
        $allowed = array_map('strtoupper', (array) config('dns.allowed_record_types', ['A', 'AAAA', 'CNAME']));

        if (!in_array($type, $allowed, true)) {
            // NS/MX/TXT/CAA can never be created or changed through this client,
            // whatever a caller asks for.
            throw new CloudflareApiException('Only A, AAAA and CNAME records can be written.', 'validation');
        }

        return $type;
    }

    public static function requireIdentifier(string $value, string $label = 'identifier'): string
    {
        $value = trim($value);

        if (preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $value) !== 1) {
            throw new CloudflareApiException(sprintf('Refusing an invalid %s.', $label), 'validation');
        }

        return $value;
    }

    public static function normaliseTtl(int $ttl): int
    {
        // 1 = "automatic". Anything else must be a Cloudflare-supported value.
        if ($ttl === 1) {
            return 1;
        }

        return in_array($ttl, [60, 120, 300, 600, 900, 1800, 3600, 7200, 10800, 14400, 18000, 21600, 43200, 86400], true)
            ? $ttl
            : 1;
    }

    private function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower((string) $parts['scheme']) === 'https') {
            return true;
        }

        if (strtolower((string) $parts['scheme']) !== 'http') {
            return false;
        }

        return in_array(strtolower((string) $parts['host']), ['127.0.0.1', 'localhost', '[::1]', '::1'], true);
    }

    private function backoff(int $attempt, ?int $retryAfterSeconds = null): void
    {
        if ($retryAfterSeconds !== null) {
            // Honour Retry-After, but never sleep for a minute inside a request.
            $this->sleepMs(min(5000, max(0, $retryAfterSeconds) * 1000));

            return;
        }

        $this->sleepMs(min(4000, $this->retryBackoffMs * (2 ** $attempt)));
    }

    /** Overridable in tests so a retry storm does not slow the suite down. */
    protected function sleepMs(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function retryAfter(array $headers): ?int
    {
        if (!isset($headers['retry-after']) || !is_numeric($headers['retry-after'])) {
            return null;
        }

        return (int) $headers['retry-after'];
    }
}
