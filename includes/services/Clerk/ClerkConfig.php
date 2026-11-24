<?php

declare(strict_types=1);

/**
 * Clerk configuration derived from the environment.
 *
 * The publishable key carries the frontend API host, which saves an extra
 * setting and prevents the two from drifting apart:
 *
 *   pk_test_<base64 of "your-instance.clerk.accounts.dev$">
 *
 * The secret key never leaves the server: nothing in this class is rendered,
 * returned by an API or written to the database.
 */
final class ClerkConfig
{
    /**
     * The frontend API host, e.g. "eternal-porpoise-2112.clerk.accounts.dev".
     */
    public static function frontendApiHost(): ?string
    {
        $configured = trim((string) config('clerk.frontend_api_host', ''));

        if ($configured !== '') {
            return self::normaliseHost($configured);
        }

        $key = self::publishableKey();

        if ($key === null) {
            return null;
        }

        // pk_test_<b64> / pk_live_<b64>: the payload is the host plus a "$" marker.
        $parts = explode('_', $key, 3);

        if (count($parts) !== 3 || $parts[0] !== 'pk') {
            return null;
        }

        $decoded = ClerkTokenVerifier::base64UrlDecode(rtrim($parts[2], '$'));

        if ($decoded === null || $decoded === '') {
            return null;
        }

        $host = rtrim($decoded, '$');
        $host = rtrim($host, '/');

        return self::normaliseHost($host);
    }

    public static function publishableKey(): ?string
    {
        $key = trim((string) config('clerk.publishable_key', ''));

        return str_starts_with($key, 'pk_test_') || str_starts_with($key, 'pk_live_') ? $key : null;
    }

    public static function isTestInstance(): bool
    {
        return str_starts_with((string) self::publishableKey(), 'pk_test_');
    }

    public static function secretKeyConfigured(): bool
    {
        $key = trim((string) config('clerk.secret_key', ''));

        return str_starts_with($key, 'sk_test_') || str_starts_with($key, 'sk_live_');
    }

    /**
     * Enough configuration for the browser SDK to boot.
     */
    public static function isConfigured(): bool
    {
        return self::publishableKey() !== null && self::frontendApiHost() !== null;
    }

    /**
     * Enough configuration for the server side handoff to complete.
     */
    public static function canCompleteSignIn(): bool
    {
        return self::isConfigured() && self::secretKeyConfigured();
    }

    /**
     * JWKS endpoint used to verify session tokens.
     */
    public static function jwksUrl(): string
    {
        $configured = trim((string) config('clerk.jwks_url', ''));

        if ($configured !== '') {
            return $configured;
        }

        $host = self::frontendApiHost();

        return $host !== null ? 'https://' . $host . '/.well-known/jwks.json' : '';
    }

    /**
     * The `iss` every genuine token from this instance carries.
     */
    public static function issuer(): ?string
    {
        $host = self::frontendApiHost();

        return $host !== null ? 'https://' . $host : null;
    }

    /**
     * Origin allowlist for the `azp` claim.
     *
     * Falls back to the configured APP_URL when CLERK_AUTHORIZED_PARTIES is
     * empty, so a single-instance deployment cannot silently accept tokens
     * minted for another origin.
     *
     * @return list<string>
     */
    public static function authorizedParties(): array
    {
        $configured = (array) config('clerk.authorized_parties', []);
        $parties = [];

        foreach ($configured as $party) {
            if (is_string($party) && trim($party) !== '') {
                $parties[] = rtrim(trim($party), '/');
            }
        }

        if ($parties === []) {
            $appUrl = config('url');

            if (is_string($appUrl) && $appUrl !== '') {
                $parties[] = rtrim($appUrl, '/');
            }
        }

        return array_values(array_unique($parties));
    }

    /**
     * Public data handed to the browser SDK. Never contains a secret.
     *
     * @return array{publishableKey: string, frontendApi: string|null, signInPath: string, signUpPath: string}
     */
    public static function publicConfiguration(): array
    {
        return [
            'publishableKey' => (string) self::publishableKey(),
            'frontendApi' => self::frontendApiHost(),
            'signInPath' => '/login.php',
            'signUpPath' => '/register.php',
        ];
    }

    /**
     * Is this browser origin one we accept sessions from?
     */
    public static function isAuthorizedParty(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        return in_array(rtrim($origin, '/'), self::authorizedParties(), true);
    }

    /**
     * Human readable readiness report (no secrets). Used by the CLI check
     * command and the admin overview.
     *
     * @return array<string, array{ok: bool, detail: string}>
     */
    public static function diagnostics(): array
    {
        $host = self::frontendApiHost();

        return [
            'publishable_key' => [
                'ok' => self::publishableKey() !== null,
                'detail' => self::publishableKey() !== null
                    ? (self::isTestInstance() ? 'test instance' : 'live instance')
                    : 'CLERK_PUBLISHABLE_KEY is missing or malformed',
            ],
            'frontend_api' => [
                'ok' => $host !== null,
                'detail' => $host ?? 'could not be derived from the publishable key',
            ],
            'secret_key' => [
                'ok' => self::secretKeyConfigured(),
                'detail' => self::secretKeyConfigured()
                    ? 'configured server-side'
                    : 'CLERK_SECRET_KEY is missing (server-side sign-in cannot complete)',
            ],
            'authorized_parties' => [
                'ok' => self::authorizedParties() !== [],
                'detail' => self::authorizedParties() === []
                    ? 'none configured and APP_URL is empty'
                    : implode(', ', self::authorizedParties()),
            ],
            'jwks_url' => [
                'ok' => self::jwksUrl() !== '',
                'detail' => self::jwksUrl() !== '' ? self::jwksUrl() : 'unknown',
            ],
            'webhook_secret' => [
                'ok' => trim((string) config('clerk.webhook_secret', '')) !== '',
                'detail' => trim((string) config('clerk.webhook_secret', '')) !== ''
                    ? 'configured (webhook signature checks enabled)'
                    : 'not configured (no webhooks are accepted)',
            ],
        ];
    }

    private static function normaliseHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        if (str_contains($host, '://')) {
            $parsed = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsed) ? $parsed : '';
        }

        $host = rtrim($host, '/');

        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) === 1 && str_contains($host, '.')
            ? $host
            : null;
    }
}
