<?php

declare(strict_types=1);

/**
 * Test double for the Clerk Backend API plus a real RSA signing key.
 *
 * The tests do not mock verification: a 2048-bit key pair is generated at
 * runtime, tokens are signed with it exactly like Clerk signs them, and the
 * verifier only ever sees the public half (served as a JWKS document). The
 * Backend API is replaced at the transport seam of ClerkApiClient, so the code
 * paths under test are the production ones.
 */
final class ClerkTestHarness
{
    private static ?array $keys = null;

    public string $instanceId = 'ins_testharness1';
    /** @var array<string, array<string, mixed>> clerk user id => record */
    public array $users = [];
    /** @var array<string, array<string, mixed>> session id => record */
    public array $sessions = [];
    /** @var list<array{method: string, path: string, payload: array<string, mixed>|null}> */
    public array $calls = [];
    /** Make the next Backend API call fail (simulates an outage). */
    public bool $unreachable = false;
    /** Force a specific status for a path prefix. @var array<string, int> */
    public array $forcedStatus = [];

    public function instanceHost(): string
    {
        return 'freesub-test.clerk.accounts.dev';
    }

    public function issuer(): string
    {
        return 'https://' . $this->instanceHost();
    }

    public function kid(): string
    {
        return 'ins_testharness1-key1';
    }

    /* ---------------------------------------------------------------------
     * Signing keys
     * ------------------------------------------------------------------ */

    /**
     * The committed fixture key pair.
     *
     * Generated once and committed on purpose: some PHP runtimes (php-wasm, for
     * example) ship openssl without a secure RNG, so generating a key at test
     * time is not portable. Signing and verification are real either way.
     *
     * @return array{private: string, public: string, rsa: array{n: string, e: string}}
     */
    public static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        $bundle = (string) file_get_contents(dirname(__DIR__) . '/fixtures/clerk-test-key.pem');

        if (preg_match('/-----BEGIN PUBLIC KEY-----.*?-----END PUBLIC KEY-----/s', $bundle, $public) !== 1
            || preg_match('/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s', $bundle, $private) !== 1) {
            throw new RuntimeException('Test key fixture is missing or malformed.');
        }

        $privateKey = openssl_pkey_get_private($private[0]);

        if ($privateKey === false) {
            throw new RuntimeException('Test private key could not be loaded.');
        }

        $details = openssl_pkey_get_details($privateKey);

        if ($details === false) {
            throw new RuntimeException('Test key details unavailable.');
        }

        self::$keys = [
            'private' => $private[0] . "\n",
            'public' => $public[0] . "\n",
            'rsa' => ['n' => (string) $details['rsa']['n'], 'e' => (string) $details['rsa']['e']],
        ];

        return self::$keys;
    }

    public static function publicPem(): string
    {
        return self::keys()['public'];
    }

    /**
     * The JWK document a real Clerk instance publishes.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwksDocument(string $kid = 'ins_testharness1-key1'): array
    {
        $rsa = self::keys()['rsa'];

        return [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => $kid,
                'alg' => 'RS256',
                'use' => 'sig',
                'n' => self::base64Url($rsa['n']),
                'e' => self::base64Url($rsa['e']),
            ]],
        ];
    }

    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /* ---------------------------------------------------------------------
     * Token minting (the browser SDK's job in production)
     * ------------------------------------------------------------------ */

    /**
     * Build a signed session token.
     *
     * @param array<string, mixed> $overrides
     */
    public function token(array $overrides = [], ?string $privateKey = null): string
    {
        $now = time();

        $claims = array_merge([
            'sub' => 'user_2testharness1',
            'sid' => 'sess_testharness1',
            'azp' => 'http://localhost',
            'iss' => $this->issuer(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 60,
            'v' => 2,
        ], $overrides);

        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid()];

        $encodedHeader = self::base64Url(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR));

        $key = $privateKey ?? self::keys()['private'];
        $signature = '';

        if (!openssl_sign($encodedHeader . '.' . $encodedPayload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the test token.');
        }

        return $encodedHeader . '.' . $encodedPayload . '.' . self::base64Url($signature);
    }

    /**
     * Point the production verifier at this harness' JWKS, with no network.
     */
    public function installVerifier(): void
    {
        $harness = $this;

        ClerkTokenVerifier::setJwksLoader(static function (string $url) use ($harness): array {
            return ['keys' => [
                $harness->kid() => self::publicPem(),
            ]];
        });
    }

    /**
     * Point the production API client at this harness' in-memory backend.
     */
    public function installApiClient(): void
    {
        $harness = $this;
        $client = new ClerkApiClient('sk_test_harness', 'https://api.clerk.test');
        $client->setTransport(static function (string $method, string $path, ?array $payload) use ($harness): array {
            return $harness->handle($method, $path, $payload);
        });

        ClerkApiClient::setDefaultTransport($client->transportClosure());
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: string}
     */
    public function handle(string $method, string $path, ?array $payload): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

        if ($this->unreachable) {
            throw new ClerkApiException('Clerk Backend API is unreachable.', 0);
        }

        foreach ($this->forcedStatus as $prefix => $status) {
            if (str_starts_with($path, $prefix)) {
                return ['status' => $status, 'body' => '{"errors":[{"message":"forced"}]}'];
            }
        }

        if ($path === '/v1/me') {
            return ['status' => 200, 'body' => json_encode(['id' => $this->instanceId, 'instance_id' => $this->instanceId])];
        }

        if ($method === 'GET' && preg_match('#^/v1/users/([^/?]+)$#', $path, $matches) === 1) {
            $user = $this->users[rawurldecode($matches[1])] ?? null;

            return $user === null
                ? ['status' => 404, 'body' => '{"errors":[{"message":"not found"}]}']
                : ['status' => 200, 'body' => json_encode($user)];
        }

        if ($method === 'GET' && str_starts_with($path, '/v1/users?email_address=')) {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $email = strtolower((string) ($query['email_address'] ?? ''));

            $matches = array_values(array_filter(
                $this->users,
                static fn (array $user): bool => self::primaryEmail($user) === $email
            ));

            return ['status' => 200, 'body' => json_encode($matches)];
        }

        if ($method === 'GET' && preg_match('#^/v1/sessions/([^/?]+)$#', $path, $matches) === 1) {
            $session = $this->sessions[rawurldecode($matches[1])] ?? null;

            return $session === null
                ? ['status' => 404, 'body' => '{"errors":[{"message":"not found"}]}']
                : ['status' => 200, 'body' => json_encode($session)];
        }

        if ($method === 'POST' && preg_match('#^/v1/sessions/([^/]+)/revoke$#', $path, $matches) === 1) {
            $id = rawurldecode($matches[1]);

            if (isset($this->sessions[$id])) {
                $this->sessions[$id]['status'] = 'revoked';
            }

            return ['status' => 200, 'body' => json_encode($this->sessions[$id] ?? [])];
        }

        if ($method === 'POST' && $path === '/v1/sign_in_tokens') {
            return ['status' => 200, 'body' => json_encode([
                'object' => 'sign_in_token',
                'id' => 'sit_test1',
                'token' => 'signintoken_test1',
                'user_id' => $payload['user_id'] ?? null,
            ])];
        }

        return ['status' => 404, 'body' => '{"errors":[{"message":"unsupported endpoint"}]}'];
    }

    /**
     * Register a Clerk user shaped like the real API response, with a verified
     * primary email address.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function addUser(
        string $id,
        string $email,
        string $firstName = 'Ada',
        string $lastName = 'Lovelace',
        bool $verified = true,
        bool $banned = false,
        ?string $username = null
    ): array {
        $user = [
            'id' => $id,
            'object' => 'user',
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'image_url' => 'https://img.clerk.test/' . $id . '.png',
            'primary_email_address_id' => 'idn_' . $id,
            'email_addresses' => [[
                'id' => 'idn_' . $id,
                'object' => 'email_address',
                'email_address' => $email,
                'verification' => $verified
                    ? ['status' => 'verified', 'strategy' => 'email_code', 'verified_at' => time() * 1000]
                    : ['status' => 'unverified', 'strategy' => 'email_code', 'verified_at' => null],
                'linked_to' => [],
                'reserved' => false,
            ]],
            'banned' => $banned,
            'locked' => false,
            'created_at' => time() * 1000,
            'last_sign_in_at' => time() * 1000,
        ];

        $this->users[$id] = $user;

        $this->sessions['sess_' . $id] = [
            'object' => 'session',
            'id' => 'sess_' . $id,
            'user_id' => $id,
            'client_id' => 'client_' . $id,
            'status' => 'active',
            'last_active_at' => time(),
            'expire_at' => time() + 3600,
            'abandon_at' => time() + 7200,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        return $user;
    }

    public static function primaryEmail(array $user): ?string
    {
        $primaryId = $user['primary_email_address_id'] ?? null;

        foreach ((array) ($user['email_addresses'] ?? []) as $address) {
            if (!is_array($address)) {
                continue;
            }

            if ($primaryId !== null && ($address['id'] ?? null) !== $primaryId) {
                continue;
            }

            return strtolower((string) ($address['email_address'] ?? ''));
        }

        return null;
    }

    public function markSessionRevoked(string $clerkUserId): void
    {
        $this->sessions['sess_' . $clerkUserId]['status'] = 'revoked';
    }

    public function callCount(string $method, string $pathPrefix): int
    {
        return count(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method && str_starts_with($call['path'], $pathPrefix)
        ));
    }

}
