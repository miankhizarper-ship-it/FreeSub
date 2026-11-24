<?php

declare(strict_types=1);

/**
 * Minimal Clerk Backend API client.
 *
 * Only the endpoints this application actually needs are implemented:
 *
 *   GET  /v1/me                    identity of the secret key (config check)
 *   GET  /v1/users/{id}            authoritative user record
 *   GET  /v1/sessions/{id}         is this session still alive?
 *   POST /v1/sign_in_tokens        issue a Clerk-side session for a linked user
 *   POST /v1/clients/verify        redeem a client JWT minted by the browser SDK
 *   POST /v1/sessions/{id}/revoke  sign the user out in Clerk too
 *
 * Rules enforced here:
 *   * The secret key is read from the environment at call time, never stored
 *     on an object that could be dumped, and never logged.
 *   * Error messages returned to callers never contain the key or the
 *     response body of a failed authenticated request.
 *   * A subclass may override transport() to run the client without network
 *     access (used by the test suite).
 */
class ClerkApiClient
{
    private string $secretKey;
    private string $baseUrl;
    /** @var Closure(string, string, ?array<string, mixed>): array{status: int, body: string}|null */
    private ?Closure $transport = null;
    /** @var Closure(string, string, ?array<string, mixed>): array{status: int, body: string}|null */
    private static ?Closure $defaultTransport = null;

    public function __construct(?string $secretKey = null, ?string $baseUrl = null)
    {
        $this->secretKey = trim($secretKey ?? (string) config('clerk.secret_key', ''));
        $this->baseUrl = rtrim($baseUrl ?? (string) config('clerk.backend_api_url', 'https://api.clerk.com'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    /**
     * Replace the HTTP layer (tests / offline environments).
     *
     * @param Closure(string, string, ?array<string, mixed>): array{status: int, body: string}|null $transport
     */
    public function setTransport(?Closure $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Install a process-wide transport override (tests only).
     *
     * @param Closure(string, string, ?array<string, mixed>): array{status: int, body: string}|null $transport
     */
    public static function setDefaultTransport(?Closure $transport): void
    {
        self::$defaultTransport = $transport;
    }

    /**
     * @return Closure(string, string, ?array<string, mixed>): array{status: int, body: string}|null
     */
    public function transportClosure(): Closure
    {
        $client = $this;

        return static fn (string $method, string $path, ?array $payload = null): array => $client->request($method, $path, $payload);
    }

    /* ---------------------------------------------------------------------
     * Read operations
     * ------------------------------------------------------------------ */

    /**
     * The Clerk user record. Returns null when the user does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function getUser(string $clerkUserId): ?array
    {
        if (!preg_match('/^user_[A-Za-z0-9_]{1,180}$/', $clerkUserId)) {
            return null;
        }

        $response = $this->request('GET', '/v1/users/' . rawurlencode($clerkUserId));

        if ($response['status'] === 404) {
            return null;
        }

        if ($response['status'] !== 200) {
            throw new ClerkApiException('Unable to load the Clerk user record.', $response['status']);
        }

        return $this->decode($response['body']);
    }

    /**
     * Look users up by email address.
     *
     * @return list<array<string, mixed>>
     */
    public function findUsersByEmail(string $email): array
    {
        $email = strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [];
        }

        $response = $this->request('GET', '/v1/users?email_address=' . rawurlencode($email) . '&limit=10');

        if ($response['status'] !== 200) {
            throw new ClerkApiException('Unable to search Clerk users.', $response['status']);
        }

        $payload = json_decode($response['body'], true);

        if (!is_array($payload)) {
            return [];
        }

        // The endpoint returns either a bare array or PaginatedResponse{data:[]}.
        $users = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;

        return array_values(array_filter($users, static fn ($row): bool => is_array($row)));
    }

    /**
     * Is the session still active? Returns the session object, or null when
     * Clerk says it is gone/revoked/expired.
     *
     * @return array<string, mixed>|null
     */
    public function getSession(string $sessionId): ?array
    {
        if (!preg_match('/^sess_[A-Za-z0-9_]{1,180}$/', $sessionId)) {
            return null;
        }

        $response = $this->request('GET', '/v1/sessions/' . rawurlencode($sessionId));

        if ($response['status'] === 404) {
            return null;
        }

        if ($response['status'] !== 200) {
            throw new ClerkApiException('Unable to load the Clerk session.', $response['status']);
        }

        return $this->decode($response['body']);
    }

    /**
     * Identity check for the configured secret key. Useful from the CLI and
     * the admin overview: never rendered with the key itself.
     *
     * @return array<string, mixed>|null
     */
    public function me(): ?array
    {
        $response = $this->request('GET', '/v1/me');

        return $response['status'] === 200 ? $this->decode($response['body']) : null;
    }

    /**
     * Exchange a short-lived client JWT (minted by the browser SDK) for a
     * Clerk session. Returns the session id or null when Clerk refuses.
     */
    public function verifyClientToken(string $clientJwt): ?string
    {
        $response = $this->request('POST', '/v1/clients/verify', ['token' => $clientJwt]);

        if ($response['status'] !== 200) {
            return null;
        }

        $client = $this->decode($response['body']);
        $sessionId = $client['last_active_session_id'] ?? null;

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    /**
     * Create a Clerk-side session for a user we have already authenticated.
     * Used when an administrator needs to sign in as themselves through the
     * break-glass path while Clerk remains the session authority.
     *
     * @return array{ticket: string, id: string}|null
     */
    public function createSignInToken(string $clerkUserId, int $expiresInSeconds = 60): ?array
    {
        $response = $this->request('POST', '/v1/sign_in_tokens', [
            'user_id' => $clerkUserId,
            'expires_in_seconds' => max(10, min(1800, $expiresInSeconds)),
        ]);

        if ($response['status'] !== 200) {
            return null;
        }

        $payload = $this->decode($response['body']);

        $token = $payload['token'] ?? null;
        $id = $payload['id'] ?? null;

        if (!is_string($token) || $token === '' || !is_string($id)) {
            return null;
        }

        return ['ticket' => $token, 'id' => $id];
    }

    public function revokeSession(string $sessionId): bool
    {
        if (!preg_match('/^sess_[A-Za-z0-9_]{1,180}$/', $sessionId)) {
            return false;
        }

        $response = $this->request('POST', '/v1/sessions/' . rawurlencode($sessionId) . '/revoke');

        return in_array($response['status'], [200, 204, 404], true);
    }

    /* ---------------------------------------------------------------------
     * Transport
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $path, ?array $payload = null): array
    {
        if (!$this->isConfigured()) {
            throw new ClerkApiException('CLERK_SECRET_KEY is not configured.', 0);
        }

        $transport = $this->transport ?? self::$defaultTransport;

        if ($transport !== null) {
            $result = $transport($method, $path, $payload);

            if (!is_array($result) || !isset($result['status'])) {
                throw new ClerkApiException('Clerk transport returned no response.', 0);
            }

            return ['status' => (int) $result['status'], 'body' => (string) ($result['body'] ?? '')];
        }

        return $this->httpRequest($method, $path, $payload);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: string}
     */
    private function httpRequest(string $method, string $path, ?array $payload): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Accept: application/json',
            'User-Agent: FreeSub/1.0 (+clerk-backend)',
        ];

        $body = null;

        if ($payload !== null) {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => 8,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        $responseBody = @file_get_contents($this->baseUrl . $path, false, $context);

        if ($responseBody === false) {
            throw new ClerkApiException('Clerk Backend API is unreachable.', 0);
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new ClerkApiException('Clerk returned an unreadable response.', 0);
        }

        return $decoded;
    }
}

/**
 * Raised for transport/configuration problems. The message is safe to log:
 * it never contains the secret key or a full Clerk response body.
 */
class ClerkApiException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 0)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
