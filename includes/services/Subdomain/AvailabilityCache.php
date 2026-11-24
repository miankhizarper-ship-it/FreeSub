<?php

declare(strict_types=1);

/**
 * Small on-disk cache for authoritative availability answers.
 *
 * Why not APCu/Redis: the deployment target is shared PHP with no extension
 * guarantee, and the rest of the app already stores runtime state under
 * `storage/` (rate limits, JWKS keys). A JSON file per key is boring and works.
 *
 * Rules:
 *   * The key is a hash of (zone id, fqdn) — never a raw name, so a hostile
 *     label cannot escape the directory.
 *   * Entries carry their own expiry; an expired entry is treated as missing.
 *   * Positive answers ("records exist") live longer than negative ones,
 *     because a stale "free" is the expensive mistake.
 *   * Failures are never cached (the engine simply does not call put()).
 */
final class AvailabilityCache
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? FREESUB_ROOT . '/storage/cache/availability';
    }

    /**
     * @param array<string, mixed> $key
     * @return array{found: bool, error: bool, cached: bool, records: list<string>}|null
     */
    public function get(array $key): ?array
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $entry = json_decode($raw, true);

        if (!is_array($entry) || !isset($entry['expires_at'], $entry['found'])) {
            @unlink($path);

            return null;
        }

        if ((int) $entry['expires_at'] <= time()) {
            @unlink($path);

            return null;
        }

        return [
            'found' => (bool) $entry['found'],
            'error' => false,
            'cached' => true,
            'records' => is_array($entry['records'] ?? null) ? array_values(array_map('strval', $entry['records'])) : [],
        ];
    }

    /**
     * @param array<string, mixed> $key
     * @param array{found: bool, error: bool, records: list<string>} $answer
     */
    public function put(array $key, array $answer): void
    {
        // Never cache an outage.
        if (($answer['error'] ?? true) === true) {
            return;
        }

        $ttl = (int) ($answer['found'] ?? false)
            ? (int) config('availability.cache_ttl', 300)
            : (int) config('availability.negative_cache_ttl', 30);

        if ($ttl <= 0) {
            return;
        }

        if (!$this->ensureDirectory()) {
            return;
        }

        $payload = json_encode([
            'found' => (bool) ($answer['found'] ?? false),
            'records' => is_array($answer['records'] ?? null) ? $answer['records'] : [],
            'expires_at' => time() + $ttl,
        ]);

        if ($payload !== false) {
            @file_put_contents($this->pathFor($key), $payload, LOCK_EX);
        }
    }

    /** Testing helper. */
    public function clear(): void
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Empty the cache without needing an instance.
     *
     * Used by tests (so one run cannot inherit another's answers) and by the
     * admin DNS page after a zone change, where every cached verdict is stale by
     * definition.
     */
    public static function flushAll(): int
    {
        $cache = new self();
        $count = count(glob($cache->directory . '/*.json') ?: []);
        $cache->clear();

        return $count;
    }

    /**
     * @param array<string, mixed> $key
     */
    private function pathFor(array $key): string
    {
        $material = json_encode($key) ?: '';

        return $this->directory . '/' . hash('sha256', $material) . '.json';
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }

        return @mkdir($this->directory, 0770, true) || is_dir($this->directory);
    }
}
