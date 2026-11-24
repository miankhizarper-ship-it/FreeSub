<?php

declare(strict_types=1);

/**
 * Clerk session-token verification (native RS256, no JWT shortcuts).
 *
 * Why this exists instead of a one-liner around a library:
 *
 *   * The handoff design (see includes/services/Clerk/ClerkHandoff.php) already
 *     makes the browser unable to influence which identity PHP trusts. Even so,
 *     every token that reaches this class is verified from scratch: signature,
 *     kid, alg, issuer, audience, authorized party, exp/nbf with a small clock
 *     skew, and the presence of a subject.
 *   * Clerk's official PHP SDK verifies signature, exp and azp but not `iss`,
 *     which this project's requirements ask for explicitly. Issuer checking is
 *     implemented here (see tests/run.php, suite `clerk`).
 *   * No dependency on network access at boot: the JWKS is fetched lazily,
 *     cached on disk with a TTL, and re-fetched exactly once when an unknown
 *     `kid` shows up (key rotation).
 *
 * Signing keys are converted from RFC 7517 JWK to PEM with a small DER encoder
 * so no extra extension or library is required beyond openssl.
 */
final class ClerkTokenVerifier
{
    public const REASON_MISSING = 'token_missing';
    public const REASON_MALFORMED = 'token_malformed';
    public const REASON_UNSUPPORTED_ALG = 'token_unsupported_alg';
    public const REASON_UNKNOWN_KEY = 'token_unknown_key';
    public const REASON_BAD_SIGNATURE = 'token_invalid_signature';
    public const REASON_EXPIRED = 'token_expired';
    public const REASON_NOT_YET_VALID = 'token_not_yet_valid';
    public const REASON_BAD_ISSUER = 'token_invalid_issuer';
    public const REASON_BAD_AUDIENCE = 'token_invalid_audience';
    public const REASON_BAD_AUTHORIZED_PARTY = 'token_invalid_authorized_parties';
    public const REASON_MISSING_SUBJECT = 'token_missing_subject';
    public const REASON_KEY_UNAVAILABLE = 'jwk_unavailable';

    /** @var (Closure(string): array{keys: array<string, string>})|null */
    private static ?Closure $jwksLoader = null;

    /**
     * Test/development seam: replace how the JWKS document is obtained.
     *
     * The callback receives the JWKS URL and must return
     * ['keys' => ['<kid>' => '<PEM>']] or throw RuntimeException.
     *
     * @param (Closure(string): array{keys: array<string, string>})|null $loader
     */
    public static function setJwksLoader(?Closure $loader): void
    {
        self::$jwksLoader = $loader;
    }

    /**
     * Verify a raw session token (JWT string).
     */
    public static function verify(string $token, ?string $expectedIssuer = null, ?string $jwksUrl = null): ClerkToken
    {
        $token = trim($token);

        if ($token === '') {
            return ClerkToken::rejected(self::REASON_MISSING);
        }

        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            return ClerkToken::rejected(self::REASON_MALFORMED, 'not a JWS compact serialization');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $header = self::decodeJsonSegment($encodedHeader);
        $claims = self::decodeJsonSegment($encodedPayload);

        if ($header === null || $claims === null) {
            return ClerkToken::rejected(self::REASON_MALFORMED, 'undecodable header or payload');
        }

        $alg = strtoupper((string) ($header['alg'] ?? ''));

        if ($alg !== 'RS256') {
            // Rejecting anything else blocks the classic "alg: none" and
            // "alg: HS256 signed with the public key" downgrade tricks.
            return ClerkToken::rejected(self::REASON_UNSUPPORTED_ALG, $alg === '' ? 'no alg' : $alg);
        }

        $kid = (string) ($header['kid'] ?? '');

        if ($kid === '') {
            return ClerkToken::rejected(self::REASON_UNKNOWN_KEY, 'header has no kid');
        }

        $signature = self::base64UrlDecode($encodedSignature);

        if ($signature === null || $signature === '') {
            return ClerkToken::rejected(self::REASON_MALFORMED, 'undecodable signature');
        }

        try {
            $signingKey = self::signingKey($kid, $jwksUrl);
        } catch (RuntimeException $exception) {
            return ClerkToken::rejected(self::REASON_KEY_UNAVAILABLE, $exception->getMessage());
        }

        if ($signingKey === null) {
            return ClerkToken::rejected(self::REASON_UNKNOWN_KEY, $kid);
        }

        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $signingKey,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            return ClerkToken::rejected(self::REASON_BAD_SIGNATURE);
        }

        return self::validateClaims($claims, $expectedIssuer);
    }

    /**
     * Claim checks that only run once the signature is known to be genuine.
     *
     * @param array<string, mixed> $claims
     */
    private static function validateClaims(array $claims, ?string $expectedIssuer): ClerkToken
    {
        $skew = max(0, (int) config('clerk.clock_skew', 5));
        $now = time();

        $exp = isset($claims['exp']) && is_numeric($claims['exp']) ? (int) $claims['exp'] : null;

        if ($exp === null) {
            return ClerkToken::rejected(self::REASON_MALFORMED, 'no exp claim');
        }

        if ($now - $skew >= $exp) {
            return ClerkToken::rejected(self::REASON_EXPIRED);
        }

        $nbf = isset($claims['nbf']) && is_numeric($claims['nbf']) ? (int) $claims['nbf'] : null;

        if ($nbf !== null && $now + $skew < $nbf) {
            return ClerkToken::rejected(self::REASON_NOT_YET_VALID, 'nbf in the future');
        }

        $issuer = isset($claims['iss']) && is_string($claims['iss']) ? rtrim($claims['iss'], '/') : null;

        if ($issuer === null || $issuer === '') {
            return ClerkToken::rejected(self::REASON_BAD_ISSUER, 'no iss claim');
        }

        if ($expectedIssuer !== null && $expectedIssuer !== '') {
            if (!hash_equals(rtrim($expectedIssuer, '/'), $issuer)) {
                return ClerkToken::rejected(self::REASON_BAD_ISSUER, $issuer);
            }
        }

        $audiences = self::audiences();

        if ($audiences !== []) {
            $tokenAudience = $claims['aud'] ?? null;
            $tokenAudiences = is_array($tokenAudience) ? $tokenAudience : [$tokenAudience];

            $matched = false;

            foreach ($tokenAudiences as $audience) {
                if (is_string($audience) && in_array($audience, $audiences, true)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return ClerkToken::rejected(self::REASON_BAD_AUDIENCE);
            }
        }

        $parties = self::authorizedParties();

        if ($parties !== []) {
            $azp = $claims['azp'] ?? null;

            // Clerk omits `azp` for server-initiated tokens (handoff tickets,
            // backend-minted sign-in tokens, machine tokens). When it is
            // absent there is no browser origin to compare, and the integrity
            // of the token was already proven by the signature above.
            if (is_string($azp) && $azp !== '') {
                if (!in_array($azp, $parties, true)) {
                    return ClerkToken::rejected(self::REASON_BAD_AUTHORIZED_PARTY, $azp);
                }
            }
        }

        $sub = $claims['sub'] ?? null;

        if (!is_string($sub) || $sub === '' || !preg_match('/^[A-Za-z0-9_\-]{3,191}$/', $sub)) {
            return ClerkToken::rejected(self::REASON_MISSING_SUBJECT);
        }

        return ClerkToken::verified($claims);
    }

    /* ---------------------------------------------------------------------
     * Signing keys
     * ------------------------------------------------------------------ */

    /**
     * Resolve a kid to an OpenSSL key, from disk cache first.
     *
     * @return OpenSSLAsymmetricKey|null
     */
    private static function signingKey(string $kid, ?string $jwksUrl)
    {
        // Keyed by kid *and* URL: the same kid from a different instance (or a
        // different test harness) must never reuse another instance's key.
        static $memory = [];

        $memoryKey = hash('sha256', ($jwksUrl ?? (string) config('clerk.jwks_url', '')) . '|' . $kid);

        if (isset($memory[$memoryKey])) {
            return $memory[$memoryKey];
        }

        $keys = self::jwksKeys($jwksUrl, false);

        if (!isset($keys[$kid])) {
            // A rotated key: refetch once, ignoring the cache.
            $keys = self::jwksKeys($jwksUrl, true);
        }

        if (!isset($keys[$kid])) {
            return null;
        }

        $key = openssl_pkey_get_public($keys[$kid]);

        if ($key === false) {
            throw new RuntimeException('cached JWK is not a valid public key');
        }

        $memory[$memoryKey] = $key;

        return $key;
    }

    /**
     * @return array<string, string> kid => PEM
     */
    private static function jwksKeys(?string $jwksUrl, bool $forceRefresh): array
    {
        $url = $jwksUrl ?? (string) config('clerk.jwks_url', '');
        $ttl = max(0, (int) config('clerk.jwks_cache_ttl', 300));
        $cacheFile = FREESUB_ROOT . '/storage/cache/clerk-jwks-' . hash('sha256', $url) . '.json';

        if (!$forceRefresh && is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);

            if (is_array($cached) && isset($cached['keys'], $cached['fetched_at'])
                && (time() - (int) $cached['fetched_at']) < $ttl) {
                return (array) $cached['keys'];
            }
        }

        $keys = self::loadJwks($url);

        if ($keys !== []) {
            @file_put_contents($cacheFile, json_encode([
                'fetched_at' => time(),
                'keys' => $keys,
            ]), LOCK_EX);
        }

        return $keys;
    }

    /**
     * @return array<string, string> kid => PEM
     */
    private static function loadJwks(string $url): array
    {
        if (self::$jwksLoader !== null) {
            $result = (self::$jwksLoader)($url);

            return (array) ($result['keys'] ?? $result);
        }

        if ($url === '') {
            throw new RuntimeException('no JWKS url configured');
        }

        $raw = self::httpGet($url);

        if ($raw === null) {
            throw new RuntimeException('JWKS request failed');
        }

        $document = json_decode($raw, true);

        if (!is_array($document) || !isset($document['keys']) || !is_array($document['keys'])) {
            throw new RuntimeException('JWKS response is not a key set');
        }

        $keys = [];

        foreach ($document['keys'] as $jwk) {
            if (!is_array($jwk) || ($jwk['kty'] ?? '') !== 'RSA' || !isset($jwk['kid'], $jwk['n'], $jwk['e'])) {
                continue;
            }

            $pem = self::jwkToPem((string) $jwk['n'], (string) $jwk['e']);

            if ($pem !== null) {
                $keys[(string) $jwk['kid']] = $pem;
            }
        }

        return $keys;
    }

    private static function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => false,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);

        return is_string($raw) ? $raw : null;
    }

    /* ---------------------------------------------------------------------
     * JWK -> PEM (RFC 7517 -> RFC 5280 SubjectPublicKeyInfo)
     * ------------------------------------------------------------------ */

    public static function jwkToPem(string $modulus, string $exponent): ?string
    {
        $n = self::base64UrlDecode($modulus);
        $e = self::base64UrlDecode($exponent);

        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }

        $rsaPublicKey = self::derSequence(self::derInteger($n), self::derInteger($e));

        // AlgorithmIdentifier ::= SEQUENCE { OID rsaEncryption, NULL }
        $algorithm = self::derSequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01", "\x05\x00");

        $subjectPublicKey = "\x03" . self::derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        $spki = self::derSequence($algorithm, $subjectPublicKey);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string ...$parts): string
    {
        $content = implode('', $parts);

        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $encoded = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    public static function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;

        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJsonSegment(string $segment): ?array
    {
        $json = self::base64UrlDecode($segment);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private static function authorizedParties(): array
    {
        $configured = (array) config('clerk.authorized_parties', []);
        $parties = [];

        foreach ($configured as $party) {
            if (!is_string($party) || trim($party) === '') {
                continue;
            }

            $normalised = rtrim(trim($party), '/');
            $parties[] = $normalised;

            // A bare origin and the same origin with a trailing slash are the
            // same party; Clerk always emits the origin without a path.
            if (!str_contains($normalised, '://')) {
                $parties[] = 'https://' . $normalised;
                $parties[] = 'http://' . $normalised;
            }
        }

        return array_values(array_unique($parties));
    }

    /** @return list<string> */
    private static function audiences(): array
    {
        $configured = config('clerk.audiences', []);

        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $configured
        )));
    }
}
