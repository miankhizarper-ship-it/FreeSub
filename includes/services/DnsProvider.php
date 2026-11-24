<?php

declare(strict_types=1);

/**
 * DNS provider abstraction.
 *
 * Nothing in this foundation creates, changes or deletes DNS records. The
 * interface and the "null" driver exist so the rest of the application can be
 * written against a stable contract while the Cloudflare driver is built.
 *
 * A future CloudflareDnsProvider will implement this interface using the token
 * from the environment (never from the database).
 */

interface DnsProviderInterface
{
    /** Machine readable provider key, e.g. "cloudflare" or "none". */
    public function name(): string;

    /** True when the provider is configured well enough to attempt writes. */
    public function isConfigured(): bool;

    /**
     * Check whether a hostname can be created for the given root domain.
     *
     * @return array{available: bool, reason: string|null}
     */
    public function isHostnameAvailable(string $fqdn): array;

    /**
     * Create the record set for a hostname.
     *
     * @param array<string, mixed> $domain  Row from the domains table
     * @param array<string, mixed> $options Provider specific options
     * @return array{ok: bool, message: string, records?: list<array<string, mixed>>}
     */
    public function createHostname(array $domain, string $fqdn, array $options = []): array;

    /**
     * Remove every record owned by a hostname.
     *
     * @param array<string, mixed> $domain
     * @return array{ok: bool, message: string}
     */
    public function deleteHostname(array $domain, string $fqdn): array;
}

/**
 * Safe default driver: it never claims success.
 */
final class NullDnsProvider implements DnsProviderInterface
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function isHostnameAvailable(string $fqdn): array
    {
        // Without a provider we cannot confirm or deny availability.
        return ['available' => true, 'reason' => null];
    }

    public function createHostname(array $domain, string $fqdn, array $options = []): array
    {
        return [
            'ok' => false,
            'message' => 'DNS automation is not enabled yet. Your reservation is saved and will be provisioned manually.',
        ];
    }

    public function deleteHostname(array $domain, string $fqdn): array
    {
        return [
            'ok' => false,
            'message' => 'DNS automation is not enabled yet.',
        ];
    }
}

final class DnsProvider
{
    public static function make(?string $name = null): DnsProviderInterface
    {
        $name = strtolower($name ?? (string) config('dns.provider', 'none'));

        /** @var DnsProviderInterface $provider */
        $provider = match ($name) {
            // 'cloudflare' => new CloudflareDnsProvider(...), // future work
            default => new NullDnsProvider(),
        };

        return $provider;
    }

    public static function current(): DnsProviderInterface
    {
        static $instance = null;

        if ($instance === null) {
            $instance = self::make();
        }

        return $instance;
    }
}
