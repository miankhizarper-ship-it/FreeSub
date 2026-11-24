<?php

declare(strict_types=1);

/**
 * Cloudflare API exception.
 *
 * The message is always safe to show to an administrator: it never contains the
 * API token, request headers or the raw response body. The machine readable
 * `reason()` is what callers branch on.
 */
final class CloudflareApiException extends RuntimeException
{
    /**
     * @param string      $message   Safe, human readable summary
     * @param string      $reason    auth|forbidden|not_found|rate_limited|validation|server|transport|config|invalid_response|error
     * @param int         $status    HTTP status, 0 when the request never completed
     * @param bool        $ambiguous True when a write may or may not have been applied
     *                               (timeout, connection reset, 5xx on a POST). The caller
     *                               MUST reconcile before retrying.
     * @param string|null $cfCode    Cloudflare error code, when the API supplied one
     */
    public function __construct(
        string $message,
        private readonly string $reason = 'error',
        private readonly int $status = 0,
        private readonly bool $ambiguous = false,
        private readonly ?string $cfCode = null
    ) {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** True when the outcome of a write is unknown and the records must be re-read. */
    public function isAmbiguous(): bool
    {
        return $this->ambiguous;
    }

    public function cfCode(): ?string
    {
        return $this->cfCode;
    }
}

/**
 * Structured internal logging for the DNS engine.
 *
 * Two rules, both about not leaking secrets:
 *   * the API token is scrubbed from every string value, and
 *   * keys that could carry credentials are dropped entirely.
 *
 * Entries are also buffered in memory so the admin diagnostics page can show
 * what the current request actually did. Persistent history lives in
 * `audit_logs` (see DnsManager).
 */
final class DnsLog
{
    private const REDACTED = '***';
    private const SECRET_KEYS = ['token', 'api_token', 'authorization', 'secret', 'password', 'api_key', 'headers'];

    /** @var list<array<string, mixed>> */
    private static array $entries = [];

    /**
     * @param array<string, mixed> $context
     */
    public static function record(string $event, array $context = []): void
    {
        $safe = self::sanitise($context);

        // Cap the buffer: a long-running sync must not grow without bound.
        if (count(self::$entries) < 200) {
            self::$entries[] = ['event' => $event, 'context' => $safe, 'at' => gmdate('c')];
        }

        $parts = [];

        foreach ($safe as $key => $value) {
            $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES));
        }

        error_log('[freesub.dns] ' . $event . ($parts === [] ? '' : ' ' . implode(' ', $parts)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        return self::$entries;
    }

    public static function reset(): void
    {
        self::$entries = [];
    }

    /**
     * @param array<mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitise(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            $name = strtolower((string) $key);

            if (in_array($name, self::SECRET_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = self::sanitise($value);

                continue;
            }

            $safe[$key] = is_string($value) ? self::scrub($value) : $value;
        }

        return $safe;
    }

    /** Remove any occurrence of the configured token from free text. */
    public static function scrub(string $text): string
    {
        $token = (string) config('dns.cloudflare.api_token', '');

        if ($token !== '' && strlen($token) >= 8) {
            $text = str_replace($token, self::REDACTED, $text);
        }

        // Belt and braces: a Cloudflare token is 40 chars of [A-Za-z0-9_-]. Blunt
        // but effective at stopping an accidental copy of *some* credential.
        return (string) preg_replace('/\b[A-Za-z0-9_\-]{40}\b/', self::REDACTED, $text);
    }
}
