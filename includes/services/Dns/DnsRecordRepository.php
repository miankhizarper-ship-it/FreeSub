<?php

declare(strict_types=1);

/**
 * Desired-state repository for `dns_records`.
 *
 * This table is the record of what FreeSub *believes* exists in the zone. It is
 * never treated as proof: every mutation records the Cloudflare id that came
 * back, and a sync compares the two.
 *
 * Ownership rule: a row is only ever addressed by its primary key *and* its
 * owning root domain. Nothing in this class accepts a Cloudflare record id from
 * a caller, so an attacker cannot ask the engine to delete someone else's
 * record by guessing an id.
 */
final class DnsRecordRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forSubdomain(int $subdomainId): array
    {
        return Database::select(
            "SELECT * FROM dns_records WHERE subdomain_id = :id AND status <> 'deleted' ORDER BY id ASC",
            ['id' => $subdomainId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forDomain(int $domainId): array
    {
        return Database::select(
            "SELECT * FROM dns_records WHERE domain_id = :id AND status <> 'deleted' ORDER BY name ASC, id ASC",
            ['id' => $domainId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function pending(int $limit = 100): array
    {
        return Database::select(
            "SELECT * FROM dns_records WHERE status IN ('pending', 'failed') ORDER BY id ASC LIMIT " . max(1, min(1000, $limit))
        );
    }

    /**
     * Only rows FreeSub owns *and* is allowed to touch: active, or explicitly
     * recorded as `failed` (a create we know did not land).
     *
     * @return list<array<string, mixed>>
     */
    public static function managed(int $domainId): array
    {
        return Database::select(
            "SELECT * FROM dns_records
              WHERE domain_id = :id AND status IN ('active', 'failed')
              ORDER BY name ASC, id ASC",
            ['id' => $domainId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM dns_records WHERE id = :id', ['id' => $id]);
    }

    /** Row by id, scoped to the zone it belongs to. */
    public static function findForDomain(int $id, int $domainId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM dns_records WHERE id = :id AND domain_id = :domain_id',
            ['id' => $id, 'domain_id' => $domainId]
        );
    }

    /** Is this exact name already spoken for inside the zone? */
    public static function nameTaken(int $domainId, string $name, ?int $ignoreId = null): ?array
    {
        $sql = "SELECT * FROM dns_records
                 WHERE domain_id = :domain_id AND name = :name AND status <> 'deleted'";
        $params = ['domain_id' => $domainId, 'name' => strtolower($name)];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore_id';
            $params['ignore_id'] = $ignoreId;
        }

        return Database::selectOne($sql . ' LIMIT 1', $params);
    }

    /** Same name, different type — the CNAME conflict case. */
    public static function conflictingType(int $domainId, string $name, string $type, ?int $ignoreId = null): ?array
    {
        $sql = "SELECT * FROM dns_records
                 WHERE domain_id = :domain_id AND name = :name AND type <> :type AND status <> 'deleted'";
        $params = ['domain_id' => $domainId, 'name' => strtolower($name), 'type' => strtoupper($type)];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore_id';
            $params['ignore_id'] = $ignoreId;
        }

        return Database::selectOne($sql . ' LIMIT 1', $params);
    }

    /**
     * @return array{total: int, active: int, pending: int, failed: int}
     */
    public static function statsForDomain(int $domainId): array
    {
        $row = Database::selectOne(
            "SELECT
                SUM(CASE WHEN status <> 'deleted' THEN 1 ELSE 0 END) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
             FROM dns_records WHERE domain_id = :id",
            ['id' => $domainId]
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    /**
     * Insert the desired-state row. Called *before* the provider so a crash
     * mid-flight leaves a `pending` row to reconcile rather than an orphaned
     * remote record nobody knows about.
     */
    public static function create(
        int $domainId,
        int $subdomainId,
        string $type,
        string $name,
        string $content,
        int $ttl = 1,
        bool $proxied = false
    ): int {
        Database::execute(
            'INSERT INTO dns_records (domain_id, subdomain_id, type, name, content, ttl, priority, proxied, status, created_at, updated_at)
             VALUES (:domain_id, :subdomain_id, :type, :name, :content, :ttl, NULL, :proxied, :status, :created_at, :updated_at)',
            [
                'domain_id' => $domainId,
                'subdomain_id' => $subdomainId,
                'type' => strtoupper($type),
                'name' => strtolower($name),
                'content' => $content,
                'ttl' => $ttl,
                // Proxying is never enabled by this engine.
                'proxied' => $proxied ? 1 : 0,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return Database::lastInsertId();
    }

    /** Mark a row active and store the Cloudflare id that was returned. */
    public static function markActive(int $id, string $providerRecordId): void
    {
        Database::execute(
            "UPDATE dns_records
                SET status = 'active',
                    provider_record_id = :provider_record_id,
                    last_error = NULL,
                    last_synced_at = :synced_at,
                    updated_at = :updated_at
              WHERE id = :id",
            [
                'provider_record_id' => $providerRecordId,
                'synced_at' => now(),
                'updated_at' => now(),
                'id' => $id,
            ]
        );
    }

    public static function markFailed(int $id, string $error): void
    {
        Database::execute(
            "UPDATE dns_records
                SET status = 'failed', last_error = :error, updated_at = :updated_at
              WHERE id = :id",
            ['error' => mb_substr($error, 0, 500), 'updated_at' => now(), 'id' => $id]
        );
    }

    /** Soft delete: the row stays as history, the remote record is gone. */
    public static function markDeleted(int $id): void
    {
        Database::execute(
            "UPDATE dns_records
                SET status = 'deleted', last_synced_at = :synced_at, updated_at = :updated_at
              WHERE id = :id",
            ['synced_at' => now(), 'updated_at' => now(), 'id' => $id]
        );
    }

    public static function touchSynced(int $id): void
    {
        Database::execute(
            'UPDATE dns_records SET last_synced_at = :synced_at, updated_at = :updated_at WHERE id = :id',
            ['synced_at' => now(), 'updated_at' => now(), 'id' => $id]
        );
    }

    /** Update the desired value after a successful remote update. */
    public static function updateDesired(int $id, string $content, int $ttl): void
    {
        Database::execute(
            "UPDATE dns_records
                SET content = :content, ttl = :ttl, status = 'active', last_error = NULL,
                    last_synced_at = :synced_at, updated_at = :updated_at
              WHERE id = :id",
            [
                'content' => $content,
                'ttl' => $ttl,
                'synced_at' => now(),
                'updated_at' => now(),
                'id' => $id,
            ]
        );
    }

    public static function countPending(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM dns_records WHERE status = 'pending'");
    }

    public static function countActive(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM dns_records WHERE status = 'active'");
    }
}
