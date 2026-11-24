<?php

declare(strict_types=1);

/**
 * Cloudflare implementation of DnsProviderInterface.
 *
 * This class adapts FreeSub's existing provider contract onto the Cloudflare
 * client. It is deliberately thin: validation lives in DnsRecordValidator, the
 * HTTP layer in CloudflareApiClient, and the create/delete/sync lifecycle in
 * DnsManager. Anything that needs the database belongs there, not here — which
 * keeps this driver usable from a CLI worker as well as from the admin page.
 *
 * The five original contract methods keep their exact signatures and return
 * shapes. The richer operations required by the DNS engine
 * (listRecords/findRecord/createRecord/updateRecord/deleteRecord/verifyZone)
 * are additional public methods, not replacements.
 */
final class CloudflareDnsProvider implements DnsProviderInterface
{
    private CloudflareApiClient $client;

    public function __construct(?CloudflareApiClient $client = null)
    {
        $this->client = $client ?? new CloudflareApiClient();
    }

    public function name(): string
    {
        return 'cloudflare';
    }

    public function client(): CloudflareApiClient
    {
        return $this->client;
    }

    /** Token present *and* a zone id configured. */
    public function isConfigured(): bool
    {
        return $this->client->isConfigured() && $this->client->zoneId() !== '';
    }

    public function rootDomain(): string
    {
        return strtolower(trim((string) config('dns.root_domain', '')));
    }

    /* ---------------------------------------------------------------------
     * DnsProviderInterface
     * ------------------------------------------------------------------ */

    /**
     * Availability check used by the public search.
     *
     * Only an exact A/AAAA/CNAME at the name counts as taken. Any error degrades
     * to "unknown, assume available" so a Cloudflare outage never blocks a
     * reservation — the provider is advisory by contract.
     */
    public function isHostnameAvailable(string $fqdn): array
    {
        $name = strtolower(rtrim(trim($fqdn), '.'));

        if (!$this->isConfigured()) {
            return ['available' => true, 'reason' => null];
        }

        if (DnsRecordValidator::validateName($name, $this->rootDomain()) !== null) {
            return ['available' => true, 'reason' => null];
        }

        try {
            foreach ($this->client->recordsAtName($name) as $record) {
                $type = (string) ($record['type'] ?? '');

                if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
                    return ['available' => false, 'reason' => $name . ' is already in use.'];
                }

                if ($type === 'NS') {
                    // A delegated zone belongs to somebody else.
                    return ['available' => false, 'reason' => $name . ' is delegated to another nameserver.'];
                }
            }
        } catch (CloudflareApiException $exception) {
            DnsLog::record('cloudflare.availability_failed', [
                'name' => $name,
                'reason' => $exception->reason(),
            ]);

            return ['available' => true, 'reason' => null];
        }

        return ['available' => true, 'reason' => null];
    }

    /**
     * Create the record set for a hostname.
     *
     * A hostname maps to exactly one A or AAAA record. That is a deliberate
     * simplification of the original multi-record contract: it makes "one
     * subdomain owns one record" true, which is what the delete and sync paths
     * rely on.
     *
     * Options: type (A|AAAA), content (address). Both are validated here even
     * though DnsManager also validates — a driver must not trust its caller.
     *
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, records?: list<array<string, mixed>>}
     */
    public function createHostname(array $domain, string $fqdn, array $options = []): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'DNS automation is not configured.'];
        }

        $type = strtoupper((string) ($options['type'] ?? 'A'));
        $content = (string) ($options['content'] ?? '');
        $root = (string) ($domain['root_domain'] ?? $this->rootDomain());

        $check = DnsRecordValidator::validate(
            ['type' => $type, 'name' => $fqdn, 'content' => $content, 'ttl' => $options['ttl'] ?? 1],
            $root
        );

        if (!$check['ok']) {
            return ['ok' => false, 'message' => (string) reset($check['errors'])];
        }

        $zone = $this->verifyZone($root);

        if (!$zone['ok']) {
            return ['ok' => false, 'message' => $zone['message']];
        }

        try {
            // Reconcile before creating: a previous attempt may have landed.
            $existing = $this->client->findRecord($check['type'], $check['name']);

            if ($existing !== null) {
                return [
                    'ok' => true,
                    'message' => 'The record already exists.',
                    'records' => [$existing],
                ];
            }

            $created = $this->client->createRecord(
                $check['type'],
                $check['name'],
                $check['content'],
                $check['ttl'],
                null,
                'freesub'
            );

            return [
                'ok' => true,
                'message' => 'Record created.',
                'records' => [$created],
            ];
        } catch (CloudflareApiException $exception) {
            DnsLog::record('cloudflare.create_failed', [
                'name' => $check['name'],
                'reason' => $exception->reason(),
                'ambiguous' => $exception->isAmbiguous(),
            ]);

            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * Remove the A/AAAA/CNAME records at a hostname that this platform owns.
     *
     * NS, MX, TXT and CAA records at the same name are left alone: they are
     * never ours to remove.
     *
     * @param array<string, mixed> $domain
     * @return array{ok: bool, message: string}
     */
    public function deleteHostname(array $domain, string $fqdn): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'DNS automation is not configured.'];
        }

        $name = strtolower(rtrim(trim($fqdn), '.'));
        $root = (string) ($domain['root_domain'] ?? $this->rootDomain());

        if (DnsRecordValidator::validateName($name, $root) !== null) {
            return ['ok' => false, 'message' => 'That hostname is not inside the managed zone.'];
        }

        try {
            $mine = array_values(array_filter(
                $this->client->recordsAtName($name),
                static fn (array $record): bool => in_array((string) $record['type'], ['A', 'AAAA', 'CNAME'], true)
            ));

            if ($mine === []) {
                return ['ok' => true, 'message' => 'No records to remove.'];
            }

            foreach ($mine as $record) {
                $this->client->deleteRecord((string) $record['id']);
            }

            return ['ok' => true, 'message' => sprintf('Removed %d record(s).', count($mine))];
        } catch (CloudflareApiException $exception) {
            DnsLog::record('cloudflare.delete_failed', [
                'name' => $name,
                'reason' => $exception->reason(),
            ]);

            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /* ---------------------------------------------------------------------
     * Additional record operations (Task 03)
     * ------------------------------------------------------------------ */

    /**
     * @param array{type?: string, name?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listRecords(array $filters = []): array
    {
        return $this->client->listRecords($filters);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRecord(string $type, string $name): ?array
    {
        return $this->client->findRecord($type, $name);
    }

    /**
     * @return array<string, mixed>
     */
    public function createRecord(string $type, string $name, string $content, int $ttl = 1): array
    {
        return $this->client->createRecord($type, $name, $content, $ttl);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateRecord(string $recordId, array $payload): array
    {
        return $this->client->updateRecord($recordId, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteRecord(string $recordId): array
    {
        return $this->client->deleteRecord($recordId);
    }

    /**
     * @return array{ok: bool, code: string, zone_id: string, zone_name: string|null, status: string|null, nameservers: list<string>, message: string}
     */
    public function verifyZone(?string $rootDomain = null): array
    {
        return $this->client->verifyZone($rootDomain ?? $this->rootDomain());
    }
}
