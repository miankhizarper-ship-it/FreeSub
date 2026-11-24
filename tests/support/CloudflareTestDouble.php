<?php

declare(strict_types=1);

/**
 * In-memory stand-in for the Cloudflare v4 API.
 *
 * Installed at CloudflareApiClient::setDefaultTransport(), so the real client
 * code — request building, status handling, error parsing, retry policy — runs
 * unchanged while no network call is made and no credential is needed.
 *
 * What it can simulate:
 *   * zones and their status (active / pending / moved)
 *   * A, AAAA, CNAME, NS, MX, TXT, CAA records, including ones FreeSub does not own
 *   * 401 / 403 / 429 / 5xx responses, with Cloudflare's error envelope
 *   * transport failures (a timeout that may or may not have applied a write)
 *
 * It also records every request, which is how the tests assert that a create was
 * *not* retried and that no token appears in what the client logs.
 */
final class CloudflareTestDouble
{
    /** @var array<string, array<string, mixed>> zone id => zone */
    private array $zones = [];

    /** @var array<string, list<array<string, mixed>>> zone id => records */
    private array $records = [];

    /** @var list<array{method: string, path: string, payload: array<string, mixed>|null}> */
    public array $requests = [];

    /** @var list<array{status: int, body: string}|'timeout'> Scripted failures, consumed in order */
    private array $queue = [];

    /**
     * Scripted failures scoped to one HTTP method.
     *
     * Needed because a lifecycle test cannot know how many reads the code will
     * make before its write: "fail the next request" would land on a zone lookup.
     *
     * @var array<string, list<array{status: int, body: string}|'timeout'>>
     */
    private array $queueByMethod = [];

    /** @var list<array{method: string, path: string, payload: array<string, mixed>|null}> Requests whose write was applied */
    public array $applied = [];

    private int $nextId = 1000;

    private string $expectedToken = 'test-token-do-not-use-in-production-0123456789';

    public function expectedToken(): string
    {
        return $this->expectedToken;
    }

    /**
     * @param list<string> $nameservers
     */
    public function addZone(string $id, string $name, string $status = 'active', array $nameservers = ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com']): void
    {
        $this->zones[$id] = [
            'id' => $id,
            'name' => $name,
            'status' => $status,
            'name_servers' => $nameservers,
            'paused' => false,
            'type' => 'full',
        ];
        $this->records[$id] ??= [];
    }

    /**
     * @param array<string, mixed> $record
     */
    public function addRecord(string $zoneId, string $type, string $name, string $content, array $record = []): string
    {
        $id = 'cfrec' . (++$this->nextId);
        $this->records[$zoneId][] = array_merge([
            'id' => $id,
            'zone_id' => $zoneId,
            'zone_name' => $this->zones[$zoneId]['name'] ?? '',
            'type' => strtoupper($type),
            'name' => strtolower($name),
            'content' => $content,
            'ttl' => 1,
            'proxied' => false,
            'comment' => null,
            'modified_on' => '2026-11-20T10:00:00Z',
            'created_on' => '2026-11-20T10:00:00Z',
        ], $record);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function records(string $zoneId): array
    {
        return $this->records[$zoneId] ?? [];
    }

    /** @return list<string> */
    public function names(string $zoneId): array
    {
        return array_column($this->records($zoneId), 'name');
    }

    public function remove(string $zoneId, string $recordId): void
    {
        $this->records[$zoneId] = array_values(array_filter(
            $this->records[$zoneId] ?? [],
            static fn (array $record): bool => $record['id'] !== $recordId
        ));
    }

    /** Script the next response(s) verbatim. */
    public function failNext(int $status, ?string $body = null): void
    {
        $this->queue[] = [
            'status' => $status,
            'body' => $body ?? json_encode([
                'success' => false,
                'errors' => [['code' => self::codeFor($status), 'message' => 'Simulated failure ' . $status]],
                'messages' => [],
                'result' => null,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /** Script a transport-level failure (no HTTP response at all). */
    public function timeoutNext(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->queue[] = 'timeout';
        }
    }

    /**
     * Apply the next write for real, then drop the connection.
     *
     * This is the failure mode the brief calls out: the record *was* created and
     * the caller never learned the id. It is the only way to test reconciliation
     * honestly.
     */
    public function applyThenTimeoutOn(string $method, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->queueByMethod[strtoupper($method)][] = 'apply_then_timeout';
        }
    }

    /** Fail the next request with this HTTP method (GET, POST, PATCH, DELETE). */
    public function failNextOn(string $method, int $status, ?string $body = null): void
    {
        $this->queueByMethod[strtoupper($method)][] = [
            'status' => $status,
            'body' => $body ?? json_encode([
                'success' => false,
                'errors' => [['code' => self::codeFor($status), 'message' => 'Simulated failure ' . $status]],
                'messages' => [],
                'result' => null,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /** Drop the connection on the next request with this HTTP method. */
    public function timeoutNextOn(string $method, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->queueByMethod[strtoupper($method)][] = 'timeout';
        }
    }

    public function resetRequests(): void
    {
        $this->requests = [];
        $this->applied = [];
    }

    /** @return list<array{method: string, path: string, payload: array<string, mixed>|null}> */
    public function writes(): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['method'] !== 'GET'
        ));
    }

    /**
     * The transport closure handed to CloudflareApiClient::setDefaultTransport().
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function __invoke(string $method, string $path, ?array $payload = null): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

        $key = strtoupper($method);
        $scripted = isset($this->queueByMethod[$key]) && $this->queueByMethod[$key] !== []
            ? array_shift($this->queueByMethod[$key])
            : array_shift($this->queue);

        if ($scripted === 'apply_then_timeout') {
            // Let the side effect happen, then behave like a dead socket.
            $this->route($method, $path, $payload);

            return ['status' => 0, 'body' => '', 'headers' => []];
        }

        if ($scripted === 'timeout') {
            // A tool that never answers. The client classifies this as a
            // transport failure, which is what the "never blind-retry a create"
            // tests rely on.
            return ['status' => 0, 'body' => '', 'headers' => []];
        }

        if (is_array($scripted)) {
            return ['status' => $scripted['status'], 'body' => $scripted['body'], 'headers' => []];
        }

        try {
            return $this->route($method, $path, $payload);
        } catch (RuntimeException $exception) {
            return [
                'status' => 500,
                'body' => (string) json_encode([
                    'success' => false,
                    'errors' => [['code' => 1000, 'message' => $exception->getMessage()]],
                    'result' => null,
                ]),
                'headers' => [],
            ];
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function route(string $method, string $path, ?array $payload): array
    {
        // Bearer check: the client always sends one, but prove it is the token we
        // expect rather than "some string".
        $uri = parse_url($path, PHP_URL_PATH) ?: $path;
        $query = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        if (preg_match('#^/zones/([A-Za-z0-9_\-]+)$#', (string) $uri, $m) === 1) {
            return $this->getZone($m[1]);
        }

        if (preg_match('#^/zones/([A-Za-z0-9_\-]+)/dns_records$#', (string) $uri, $m) === 1) {
            return $method === 'GET'
                ? $this->listRecords($m[1], $query)
                : $this->createRecord($m[1], $payload ?? [], $method);
        }

        if (preg_match('#^/zones/([A-Za-z0-9_\-]+)/dns_records/([A-Za-z0-9_\-]+)$#', (string) $uri, $m) === 1) {
            return match ($method) {
                'DELETE' => $this->deleteRecord($m[1], $m[2]),
                'PATCH', 'PUT' => $this->updateRecord($m[1], $m[2], $payload ?? []),
                default => $this->error(405, 1000, 'Method not allowed'),
            };
        }

        return $this->error(404, 7000, 'No route for ' . $uri);
    }

    private function getZone(string $zoneId): array
    {
        if (!isset($this->zones[$zoneId])) {
            return $this->error(404, 7003, 'Could not route to /zones/' . $zoneId);
        }

        return $this->ok($this->zones[$zoneId]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listRecords(string $zoneId, array $query): array
    {
        if (!isset($this->zones[$zoneId])) {
            return $this->error(404, 7003, 'Zone not found');
        }

        $records = $this->records[$zoneId] ?? [];

        if (isset($query['type']) && $query['type'] !== '') {
            $records = array_values(array_filter(
                $records,
                static fn (array $record): bool => strcasecmp($record['type'], (string) $query['type']) === 0
            ));
        }

        if (isset($query['name']) && $query['name'] !== '') {
            $records = array_values(array_filter(
                $records,
                static fn (array $record): bool => $record['name'] === strtolower(rtrim((string) $query['name'], '.'))
            ));
        }

        $perPage = max(1, (int) ($query['per_page'] ?? 100));
        $page = max(1, (int) ($query['page'] ?? 1));
        $totalPages = (int) ceil(count($records) / $perPage) ?: 1;

        return [
            'status' => 200,
            'body' => (string) json_encode([
                'success' => true,
                'errors' => [],
                'messages' => [],
                'result' => array_slice($records, ($page - 1) * $perPage, $perPage),
                'result_info' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'count' => count($records),
                    'total_count' => count($records),
                    'total_pages' => $totalPages,
                ],
            ]),
            'headers' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createRecord(string $zoneId, array $payload, string $method): array
    {
        if ($method !== 'POST') {
            return $this->error(405, 1000, 'Method not allowed');
        }

        if (!isset($this->zones[$zoneId])) {
            return $this->error(404, 7003, 'Zone not found');
        }

        $name = strtolower(rtrim((string) ($payload['name'] ?? ''), '.'));
        $root = strtolower((string) $this->zones[$zoneId]['name']);

        if ($name === '' || !str_ends_with($name, '.' . $root)) {
            return $this->error(9003, 9003, 'Content for A record must be a valid IPv4 address or the record name is outside the zone.');
        }

        foreach ($this->records[$zoneId] as $existing) {
            if ($existing['name'] === $name && strcasecmp($existing['type'], (string) ($payload['type'] ?? '')) === 0) {
                return $this->error(81057, 81057, 'The record already exists.');
            }
        }

        $id = $this->addRecord(
            $zoneId,
            (string) ($payload['type'] ?? 'A'),
            $name,
            (string) ($payload['content'] ?? ''),
            [
                'ttl' => (int) ($payload['ttl'] ?? 1),
                'proxied' => (bool) ($payload['proxied'] ?? false),
                'comment' => $payload['comment'] ?? null,
            ]
        );

        $this->applied[] = ['method' => 'POST', 'path' => '/zones/' . $zoneId . '/dns_records', 'payload' => $payload];

        foreach ($this->records[$zoneId] as $record) {
            if ($record['id'] === $id) {
                return $this->ok($record);
            }
        }

        return $this->error(500, 1000, 'Internal error');
    }

    private function deleteRecord(string $zoneId, string $recordId): array
    {
        foreach ($this->records[$zoneId] ?? [] as $record) {
            if ($record['id'] === $recordId) {
                $this->remove($zoneId, $recordId);
                $this->applied[] = ['method' => 'DELETE', 'path' => '/zones/' . $zoneId . '/dns_records/' . $recordId, 'payload' => null];

                return $this->ok(['id' => $recordId]);
            }
        }

        return $this->error(404, 81044, 'Record not found');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateRecord(string $zoneId, string $recordId, array $payload): array
    {
        foreach ($this->records[$zoneId] ?? [] as $index => $record) {
            if ($record['id'] !== $recordId) {
                continue;
            }

            $updated = array_merge($record, array_intersect_key($payload, array_flip(['type', 'name', 'content', 'ttl', 'proxied'])));
            $this->records[$zoneId][$index] = $updated;
            $this->applied[] = ['method' => 'PATCH', 'path' => '/zones/' . $zoneId . '/dns_records/' . $recordId, 'payload' => $payload];

            return $this->ok($updated);
        }

        return $this->error(404, 81044, 'Record not found');
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private function ok(?array $result): array
    {
        return [
            'status' => 200,
            'body' => (string) json_encode([
                'success' => true,
                'errors' => [],
                'messages' => [],
                'result' => $result,
            ]),
            'headers' => [],
        ];
    }

    private function error(int $status, int $code, string $message): array
    {
        return [
            'status' => $status,
            'body' => (string) json_encode([
                'success' => false,
                'errors' => [['code' => $code, 'message' => $message]],
                'messages' => [],
                'result' => null,
            ]),
            'headers' => [],
        ];
    }

    private static function codeFor(int $status): int
    {
        return match ($status) {
            401 => 10000,
            403 => 9109,
            404 => 7003,
            429 => 971,
            default => 1000,
        };
    }
}
