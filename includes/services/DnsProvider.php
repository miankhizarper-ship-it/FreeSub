<?php

declare(strict_types=1);

/**
 * DNS provider abstraction.
 *
 * Nothing in this foundation creates, changes or deletes DNS records. The
 * interface and the "null" driver exist so the rest of the application can be
 * written against a stable contract while the Cloudflare driver is built.
 *
 * CloudflareDnsProvider implements this interface using the token from the
 * environment (never from the database). It adds the record-level operations
 * (list/find/create/update/delete/verifyZone) as extra public methods so this
 * contract — and every existing caller — stays unchanged.
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
    /**
     * Instantiate a driver by name.
     *
     * 'cloudflare' only produces a live driver when a token *and* a zone id are
     * configured; otherwise it falls back to NullDnsProvider, which never claims
     * success. A misconfigured environment therefore degrades to "automation
     * disabled", never to "automation attempted with half the credentials".
     */
    public static function make(?string $name = null): DnsProviderInterface
    {
        $name = strtolower($name ?? (string) config('dns.provider', 'none'));

        if ($name === 'cloudflare') {
            $provider = new CloudflareDnsProvider();

            if ($provider->isConfigured()) {
                return $provider;
            }

            DnsLog::record('dns.cloudflare_not_configured', [
                'token' => $provider->client()->isConfigured() ? 'present' : 'missing',
                'zone_id' => $provider->client()->zoneId() !== '' ? 'present' : 'missing',
            ]);

            return new NullDnsProvider();
        }

        /** @var DnsProviderInterface $provider */
        $provider = match ($name) {
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
