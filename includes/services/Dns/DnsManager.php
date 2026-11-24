<?php

declare(strict_types=1);

/**
 * DNS lifecycle: create, delete and reconcile.
 *
 * Everything that needs both a zone and a database row lives here.
 *
 * Ownership model, which is what makes the destructive operations safe:
 *   1. A remote record is only ever addressed by the Cloudflare id stored in
 *      `dns_records.provider_record_id`, and that column is only written from a
 *      response Cloudflare itself returned.
 *   2. A row is only ever addressed together with its root domain id.
 *   3. A sync never deletes. It reports.
 *
 * Ordering, which is what makes create safe:
 *   1. validate, 2. conflict check, 3. verify zone, 4. insert `pending` row,
 *   5. call Cloudflare, 6. store the returned id, 7. audit.
 * A crash between 4 and 6 leaves a `pending` row that the next sync reconciles —
 * never an untracked remote record. A create is never blind-retried: if the
 * outcome is ambiguous the remote records are listed before anything else is
 * attempted.
 */
final class DnsManager
{
    private CloudflareDnsProvider $provider;

    public function __construct(?CloudflareDnsProvider $provider = null)
    {
        $this->provider = $provider ?? new CloudflareDnsProvider();
    }

    public function provider(): CloudflareDnsProvider
    {
        return $this->provider;
    }

    public function isEnabled(): bool
    {
        return $this->provider->isConfigured();
    }

    /** The single apex this installation may write to. */
    public function rootDomain(): string
    {
        return $this->provider->rootDomain();
    }

    /* ---------------------------------------------------------------------
     * Status / diagnostics
     * ------------------------------------------------------------------ */

    /**
     * Everything the admin page needs, with no secret in it.
     *
     * @param array<string, mixed>|null $domain Root domain row, when known
     * @return array<string, mixed>
     */
    public function status(?array $domain = null, bool $live = false): array
    {
        $root = (string) ($domain['root_domain'] ?? $this->rootDomain());
        $zoneId = (string) ($domain['zone_reference'] ?? '') !== ''
            ? (string) $domain['zone_reference']
            : $this->provider->client()->zoneId();

        $status = [
            'provider' => $this->provider->name(),
            'enabled' => (string) config('dns.provider', 'none') === 'cloudflare',
            'configured' => $this->provider->isConfigured(),
            'token_present' => $this->provider->client()->isConfigured(),
            'zone_id_configured' => $zoneId !== '',
            'zone_id' => $zoneId,
            'root_domain' => $root,
            'api_base' => $this->provider->client()->baseUrl(),
            'zone' => null,
            'records' => null,
            'error' => null,
        ];

        if ($domain !== null && isset($domain['id'])) {
            $status['records'] = DnsRecordRepository::statsForDomain((int) $domain['id']);
        }

        if (!$status['enabled']) {
            return $status;
        }

        if (!$status['configured']) {
            $status['error'] = 'CLOUDFLARE_API_TOKEN or CLOUDFLARE_ZONE_ID is missing.';

            return $status;
        }

        if ($live) {
            // The only place the admin page touches the network, and it is
            // strictly read-only.
            $status['zone'] = $this->provider->verifyZone($root);
        }

        return $status;
    }

    /**
     * Read-only connection test. Never writes.
     *
     * @param array<string, mixed> $domain
     * @return array{ok: bool, message: string, zone: array<string, mixed>}
     */
    public function testConnection(array $domain, ?int $actorId = null): array
    {
        $root = (string) ($domain['root_domain'] ?? $this->rootDomain());
        $zone = $this->provider->verifyZone($root);

        DnsLog::record('dns.connection_test', [
            'domain' => $root,
            'ok' => $zone['ok'],
            'code' => $zone['code'],
        ]);

        audit_log(
            $zone['ok'] ? 'dns.connection_test_ok' : 'dns.connection_test_failed',
            $actorId,
            ['root_domain' => $root, 'code' => $zone['code'], 'actor_type' => $actorId !== null ? 'admin' : 'system'],
            'domain',
            isset($domain['id']) ? (int) $domain['id'] : null,
            $zone['message']
        );

        return ['ok' => $zone['ok'], 'message' => $zone['message'], 'zone' => $zone];
    }

    /* ---------------------------------------------------------------------
     * CREATE
     * ------------------------------------------------------------------ */

    /**
     * Create a record for a subdomain FreeSub already owns.
     *
     * @param array<string, mixed> $subdomain Row from `subdomains`
     * @param array{type?: mixed, name?: mixed, content?: mixed, ttl?: mixed} $input
     * @return array{ok: bool, message: string, errors?: array<string, string>, record?: array<string, mixed>, provider_record_id?: string}
     */
    public function createForSubdomain(array $subdomain, array $input, ?int $actorId = null): array
    {
        $domain = Domain::find((int) ($subdomain['domain_id'] ?? 0));

        if ($domain === null) {
            return ['ok' => false, 'message' => 'The root domain for this subdomain no longer exists.'];
        }

        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => 'DNS automation is not enabled.'];
        }

        $root = (string) $domain['root_domain'];
        $fqdn = (string) $subdomain['fqdn'];

        // A caller may omit the name; it is then the subdomain's own name and
        // nothing else. A caller may never target a name outside its subdomain,
        // so the name is resolved *before* validation and then checked.
        $requestedName = trim((string) ($input['name'] ?? ''));

        if ($requestedName === '') {
            $requestedName = $fqdn;
        }

        $input['name'] = $requestedName;

        $validated = DnsRecordValidator::validate($input, $root);
        $name = (string) ($validated['name'] ?? $requestedName);

        if ($name !== $fqdn) {
            return [
                'ok' => false,
                'message' => 'Records can only be created for the subdomain itself.',
                'errors' => ['name' => 'The record name must be ' . $fqdn . '.'],
            ];
        }

        if (!$validated['ok']) {
            return ['ok' => false, 'message' => 'Please correct the highlighted fields.', 'errors' => $validated['errors']];
        }

        $zone = $this->provider->verifyZone($root);

        if (!$zone['ok']) {
            return ['ok' => false, 'message' => $zone['message']];
        }

        $domainId = (int) $domain['id'];
        $subdomainId = (int) $subdomain['id'];

        // Local conflict checks first: they are free and they catch the cases a
        // remote lookup would miss (a row that is pending, or failed).
        $existing = DnsRecordRepository::nameTaken($domainId, $name);

        if ($existing !== null) {
            // A CNAME is exclusive by definition: it may not share a name with
            // anything, and nothing may share a name with it.
            if ((string) $existing['type'] === 'CNAME' || $validated['type'] === 'CNAME') {
                return [
                    'ok' => false,
                    'message' => 'A CNAME cannot share a name with another record.',
                    'errors' => ['name' => 'CNAME conflict.'],
                ];
            }

            if ((string) $existing['type'] !== $validated['type']) {
                return [
                    'ok' => false,
                    'message' => 'A different record type already exists at that name.',
                    'errors' => ['name' => 'Conflicting record type.'],
                ];
            }

            return (int) $existing['subdomain_id'] === $subdomainId
                ? ['ok' => false, 'message' => 'This subdomain already has a record for that name.', 'errors' => ['name' => 'A record already exists.']]
                : ['ok' => false, 'message' => 'That hostname is already managed by another subdomain.', 'errors' => ['name' => 'Owned by another subdomain.']];
        }

        try {
            // Remote conflicts are checked too: records created outside FreeSub
            // are never overwritten, only reported.
            foreach ($this->provider->client()->recordsAtName($name) as $remote) {
                if (in_array((string) $remote['type'], ['A', 'AAAA', 'CNAME'], true)) {
                    return ['ok' => false, 'message' => 'A record for ' . $name . ' already exists in the zone.'];
                }
            }
        } catch (CloudflareApiException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        $recordId = Database::transaction(static function () use ($domainId, $subdomainId, $validated, $root): int {
            return DnsRecordRepository::create(
                $domainId,
                $subdomainId,
                (string) $validated['type'],
                (string) $validated['name'],
                (string) $validated['content'],
                (int) $validated['ttl'],
                false
            );
        });

        try {
            $created = $this->provider->createRecord(
                (string) $validated['type'],
                (string) $validated['name'],
                (string) $validated['content'],
                (int) $validated['ttl']
            );
        } catch (CloudflareApiException $exception) {
            $message = $this->reconcileAfterFailure($recordId, (string) $validated['name'], (string) $validated['type'], (string) $validated['content']);

            if ($message !== null) {
                // The record did land after all (a timeout that followed a
                // successful create). Adopt it instead of creating a duplicate.
                $record = DnsRecordRepository::find($recordId);
                audit_log(
                    'dns.record_created',
                    $actorId,
                    [
                        'root_domain' => $root,
                        'name' => (string) $validated['name'],
                        'type' => (string) $validated['type'],
                        'reconciled' => true,
                        'actor_type' => $actorId !== null ? 'admin' : 'system',
                    ],
                    'dns_record',
                    $recordId,
                    'Created ' . $validated['type'] . ' ' . $validated['name']
                );

                return [
                    'ok' => true,
                    'message' => 'Record created (reconciled after a timeout).',
                    'record' => $record ?? [],
                    'provider_record_id' => $message,
                ];
            }

            DnsRecordRepository::markFailed($recordId, $exception->getMessage());

            audit_log(
                'dns.record_create_failed',
                $actorId,
                [
                    'root_domain' => $root,
                    'name' => (string) $validated['name'],
                    'type' => (string) $validated['type'],
                    'reason' => $exception->reason(),
                    'actor_type' => $actorId !== null ? 'admin' : 'system',
                ],
                'dns_record',
                $recordId,
                'Failed to create ' . $validated['type'] . ' ' . $validated['name']
            );

            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        $providerRecordId = (string) ($created['id'] ?? '');

        if ($providerRecordId === '') {
            DnsRecordRepository::markFailed($recordId, 'Cloudflare returned a record without an id.');

            return ['ok' => false, 'message' => 'Cloudflare returned a record without an id; nothing was recorded.'];
        }

        DnsRecordRepository::markActive($recordId, $providerRecordId);

        audit_log(
            'dns.record_created',
            $actorId,
            [
                'root_domain' => $root,
                'name' => (string) $validated['name'],
                'type' => (string) $validated['type'],
                'record_id' => $recordId,
                'actor_type' => $actorId !== null ? 'admin' : 'system',
            ],
            'dns_record',
            $recordId,
            'Created ' . $validated['type'] . ' ' . $validated['name']
        );

        return [
            'ok' => true,
            'message' => 'Record created.',
            'record' => DnsRecordRepository::find($recordId) ?? [],
            'provider_record_id' => $providerRecordId,
        ];
    }

    /**
     * After a failed create, look for the record before giving up.
     *
     * Returns the Cloudflare record id when it exists (and adopts it), null when
     * it genuinely does not. This is the "never blind-retry a create" rule made
     * concrete.
     */
    private function reconcileAfterFailure(int $recordId, string $name, string $type, string $content): ?string
    {
        try {
            $remote = $this->provider->findRecord($type, $name);
        } catch (CloudflareApiException $exception) {
            DnsLog::record('dns.reconcile_failed', ['name' => $name, 'reason' => $exception->reason()]);

            return null;
        }

        if ($remote === null || (string) $remote['id'] === '') {
            return null;
        }

        if ((string) $remote['content'] !== $content) {
            // Somebody else's record at our name: do not adopt it, do not touch it.
            DnsLog::record('dns.reconcile_mismatch', ['name' => $name, 'expected' => $content]);

            return null;
        }

        DnsRecordRepository::markActive($recordId, (string) $remote['id']);

        return (string) $remote['id'];
    }

    /* ---------------------------------------------------------------------
     * UPDATE
     * ------------------------------------------------------------------ */

    /**
     * Change the content/TTL of a record FreeSub owns.
     *
     * @param array{content?: mixed, ttl?: mixed} $input
     * @return array{ok: bool, message: string, errors?: array<string, string>}
     */
    public function updateRecord(int $recordId, int $domainId, array $input, ?int $actorId = null): array
    {
        $record = DnsRecordRepository::findForDomain($recordId, $domainId);

        if ($record === null || (string) $record['status'] === 'deleted') {
            return ['ok' => false, 'message' => 'That record does not belong to this zone.'];
        }

        $type = (string) $record['type'];

        if (!in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            return ['ok' => false, 'message' => 'Only A, AAAA and CNAME records can be changed here.'];
        }

        $domain = Domain::find($domainId);
        $validated = DnsRecordValidator::validate(
            [
                'type' => $type,
                'name' => (string) $record['name'],
                'content' => $input['content'] ?? '',
                'ttl' => $input['ttl'] ?? $record['ttl'],
            ],
            (string) ($domain['root_domain'] ?? $this->rootDomain())
        );

        if (!$validated['ok']) {
            return ['ok' => false, 'message' => 'Please correct the highlighted fields.', 'errors' => $validated['errors']];
        }

        $providerRecordId = (string) ($record['provider_record_id'] ?? '');

        if ($providerRecordId === '') {
            return ['ok' => false, 'message' => 'This record has no provider id yet; run a sync first.'];
        }

        try {
            $this->provider->updateRecord($providerRecordId, [
                'type' => $validated['type'],
                'name' => $validated['name'],
                'content' => $validated['content'],
                'ttl' => $validated['ttl'],
            ]);
        } catch (CloudflareApiException $exception) {
            DnsRecordRepository::markFailed($recordId, $exception->getMessage());

            audit_log(
                'dns.record_update_failed',
                $actorId,
                ['record_id' => $recordId, 'reason' => $exception->reason(), 'actor_type' => $actorId !== null ? 'admin' : 'system'],
                'dns_record',
                $recordId,
                'Failed to update ' . $type . ' ' . $record['name']
            );

            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        DnsRecordRepository::updateDesired($recordId, (string) $validated['content'], (int) $validated['ttl']);

        audit_log(
            'dns.record_updated',
            $actorId,
            ['record_id' => $recordId, 'type' => $type, 'name' => (string) $record['name'], 'actor_type' => $actorId !== null ? 'admin' : 'system'],
            'dns_record',
            $recordId,
            'Updated ' . $type . ' ' . $record['name']
        );

        return ['ok' => true, 'message' => 'Record updated.'];
    }

    /* ---------------------------------------------------------------------
     * DELETE
     * ------------------------------------------------------------------ */

    /**
     * Delete one record FreeSub owns.
     *
     * The remote id comes from our own row. A caller that supplies a record id
     * (an integer, our primary key) still cannot reach a row outside its zone.
     *
     * @return array{ok: bool, message: string}
     */
    public function deleteRecord(int $recordId, int $domainId, ?int $actorId = null): array
    {
        $record = DnsRecordRepository::findForDomain($recordId, $domainId);

        if ($record === null || (string) $record['status'] === 'deleted') {
            return ['ok' => false, 'message' => 'That record does not belong to this zone.'];
        }

        $providerRecordId = (string) ($record['provider_record_id'] ?? '');

        if ($providerRecordId !== '') {
            try {
                // Exactly one id. No name-based sweep, no wildcard.
                $this->provider->deleteRecord($providerRecordId);
            } catch (CloudflareApiException $exception) {
                if ($exception->reason() !== 'not_found') {
                    return ['ok' => false, 'message' => $exception->getMessage()];
                }
                // Already gone upstream: our row still needs closing.
            }
        }

        DnsRecordRepository::markDeleted($recordId);

        audit_log(
            'dns.record_deleted',
            $actorId,
            [
                'record_id' => $recordId,
                'type' => (string) $record['type'],
                'name' => (string) $record['name'],
                'actor_type' => $actorId !== null ? 'admin' : 'system',
            ],
            'dns_record',
            $recordId,
            'Deleted ' . $record['type'] . ' ' . $record['name']
        );

        return ['ok' => true, 'message' => 'Record deleted.'];
    }

    /* ---------------------------------------------------------------------
     * SYNC
     * ------------------------------------------------------------------ */

    /**
     * Compare FreeSub's desired state with the live zone.
     *
     * Never deletes anything, locally or remotely. Unknown records are reported
     * and left exactly as they are — they may belong to another system that
     * shares the zone.
     *
     * @param array<string, mixed> $domain
     * @return array{
     *     ok: bool,
     *     message: string,
     *     checked: int,
     *     missing: list<array<string, mixed>>,
     *     modified: list<array<string, mixed>>,
     *     unknown: list<array<string, mixed>>,
     *     ok_records: list<string>
     * }
     */
    public function sync(array $domain, ?int $actorId = null, bool $writeAudit = true): array
    {
        $domainId = (int) ($domain['id'] ?? 0);
        $root = (string) ($domain['root_domain'] ?? $this->rootDomain());

        $result = [
            'ok' => false,
            'message' => '',
            'checked' => 0,
            'missing' => [],
            'modified' => [],
            'unknown' => [],
            'ok_records' => [],
        ];

        if (!$this->isEnabled()) {
            return array_merge($result, ['message' => 'DNS automation is not enabled.']);
        }

        $zone = $this->provider->verifyZone($root);

        if (!$zone['ok']) {
            return array_merge($result, ['message' => $zone['message']]);
        }

        try {
            $remote = $this->provider->listRecords();
        } catch (CloudflareApiException $exception) {
            return array_merge($result, ['message' => $exception->getMessage()]);
        }

        $byId = [];

        foreach ($remote as $record) {
            if ((string) $record['id'] !== '') {
                $byId[(string) $record['id']] = $record;
            }
        }

        $owned = DnsRecordRepository::managed($domainId);
        $ownedNames = [];

        foreach ($owned as $row) {
            $result['checked']++;
            $name = (string) $row['name'];
            $ownedNames[$name] = true;
            $providerId = (string) ($row['provider_record_id'] ?? '');

            if ($providerId === '' || !isset($byId[$providerId])) {
                // Either never created, or deleted outside FreeSub. Either way we
                // report it; we never recreate automatically.
                $result['missing'][] = [
                    'id' => (int) $row['id'],
                    'name' => $name,
                    'type' => (string) $row['type'],
                    'expected' => (string) $row['content'],
                ];

                continue;
            }

            $live = $byId[$providerId];

            if ((string) $live['content'] !== (string) $row['content']
                || (int) $live['ttl'] !== (int) $row['ttl']
                || strcasecmp((string) $live['type'], (string) $row['type']) !== 0
            ) {
                $result['modified'][] = [
                    'id' => (int) $row['id'],
                    'name' => $name,
                    'type' => (string) $row['type'],
                    'expected' => (string) $row['content'],
                    'actual' => (string) $live['content'],
                ];

                continue;
            }

            DnsRecordRepository::touchSynced((int) $row['id']);
            $result['ok_records'][] = $name;
        }

        foreach ($remote as $record) {
            $name = (string) $record['name'];

            if (isset($ownedNames[$name])) {
                continue;
            }

            // Never claimed by FreeSub. Report only: a TXT/MX/CAA record, another
            // system's host record, or a developer's manual entry.
            $result['unknown'][] = [
                'id' => (string) $record['id'],
                'name' => $name,
                'type' => (string) $record['type'],
                'content' => (string) $record['content'],
            ];
        }

        $result['ok'] = true;
        $result['message'] = sprintf(
            'Checked %d record(s): %d in sync, %d missing, %d modified, %d unmanaged.',
            $result['checked'],
            count($result['ok_records']),
            count($result['missing']),
            count($result['modified']),
            count($result['unknown'])
        );

        if ($writeAudit) {
            audit_log(
                'dns.sync',
                $actorId,
                [
                    'root_domain' => $root,
                    'checked' => $result['checked'],
                    'missing' => count($result['missing']),
                    'modified' => count($result['modified']),
                    'unknown' => count($result['unknown']),
                    'actor_type' => $actorId !== null ? 'admin' : 'system',
                ],
                'domain',
                $domainId,
                $result['message']
            );
        }

        return $result;
    }
}
