<?php

declare(strict_types=1);

/**
 * Subdomain repository (database state only — DNS is handled by a provider
 * driver that is intentionally not implemented yet).
 */
final class Subdomain
{
    /**
     * Every subdomain the user owns, newest first.
     *
     * `$includeTerminated = false` is the default because a released name is no
     * longer theirs to manage: the dashboard lists active names, and released
     * ones only appear in that account's own history (Task 05 §6 — a Released
     * state exists, but it is read-only).
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId, int $limit = 100, bool $includeTerminated = false): array
    {
        $limit = max(1, min(500, $limit));

        $sql = 'SELECT s.*, d.root_domain, d.nameservers
                  FROM subdomains s
                  INNER JOIN domains d ON d.id = s.domain_id
                 WHERE s.user_id = :user_id';

        if (!$includeTerminated) {
            $sql .= " AND s.status <> 'terminated'";
        }

        return Database::select($sql . ' ORDER BY s.id DESC LIMIT ' . $limit, ['user_id' => $userId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $row = Database::selectOne(
            'SELECT s.*, d.root_domain FROM subdomains s
             INNER JOIN domains d ON d.id = s.domain_id
             WHERE s.id = :id LIMIT 1',
            ['id' => $id]
        );

        return $row !== null ? self::normalise($row) : null;
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        $row = Database::selectOne(
            'SELECT s.*, d.root_domain FROM subdomains s
             INNER JOIN domains d ON d.id = s.domain_id
             WHERE s.id = :id AND s.user_id = :user_id LIMIT 1',
            ['id' => $id, 'user_id' => $userId]
        );

        return $row !== null ? self::normalise($row) : null;
    }

    public static function findByFqdn(string $fqdn): ?array
    {
        return Database::selectOne(
            'SELECT * FROM subdomains WHERE fqdn = :fqdn LIMIT 1',
            ['fqdn' => mb_strtolower(trim($fqdn))]
        );
    }

    public static function labelTaken(int $domainId, string $label): bool
    {
        return Database::scalar(
            'SELECT COUNT(*) FROM subdomains WHERE domain_id = :domain_id AND label = :label',
            ['domain_id' => $domainId, 'label' => $label]
        ) > 0;
    }

    public static function create(int $userId, int $domainId, string $label, string $fqdn, ?int $reservationId = null, ?string $target = null): int
    {
        Database::execute(
            'INSERT INTO subdomains (user_id, domain_id, reservation_id, label, fqdn, status, target, created_at, updated_at)
             VALUES (:user_id, :domain_id, :reservation_id, :label, :fqdn, :status, :target, :created_at, :updated_at)',
            [
                'user_id' => $userId,
                'domain_id' => $domainId,
                'reservation_id' => $reservationId,
                'label' => $label,
                'fqdn' => $fqdn,
                'status' => 'pending_dns',
                'target' => $target,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return Database::lastInsertId();
    }

    public static function countForUser(int $userId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM subdomains WHERE user_id = :id AND status <> 'terminated'",
            ['id' => $userId]
        );
    }

    public static function countActive(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM subdomains WHERE status = 'active'");
    }

    public static function countAll(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM subdomains WHERE status <> 'terminated'"
        );
    }

    /**
     * Record the outcome of a provider call. Called by the (future) DNS job.
     *
     * Two explicit statements instead of a CASE expression: drivers bind
     * parameters with different storage classes (text vs integer), which makes
     * "? = 1" unreliable across MySQL and SQLite.
     */
    public static function markStatus(int $id, string $status, ?string $error = null): void
    {
        $allowed = ['pending_dns', 'active', 'suspended', 'failed', 'terminated'];

        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Unknown subdomain status.');
        }

        if ($status === 'active') {
            Database::execute(
                'UPDATE subdomains
                 SET status = :status,
                     provisioned_at = COALESCE(provisioned_at, :provisioned_at),
                     updated_at = :updated_at
                 WHERE id = :id',
                ['status' => $status, 'provisioned_at' => now(), 'updated_at' => now(), 'id' => $id]
            );

            return;
        }

        Database::execute(
            'UPDATE subdomains SET status = :status, updated_at = :updated_at WHERE id = :id',
            ['status' => $status, 'updated_at' => now(), 'id' => $id]
        );
    }

    public static function deleteForUser(int $id, int $userId): bool
    {
        return Database::execute(
            "UPDATE subdomains SET status = 'terminated', updated_at = :updated_at WHERE id = :id AND user_id = :user_id",
            ['updated_at' => now(), 'id' => $id, 'user_id' => $userId]
        ) > 0;
    }

    /**
     * Everything the dashboard needs to explain one subdomain's DNS state.
     *
     * Deliberately one small query per concern instead of one per row when
     * rendering a list: the caller fetches jobs for a set of ids in a single
     * query (see provisioningForMany()), so a list of N subdomains costs a
     * constant number of queries.
     *
     * Returns only user-safe fields. `last_error` is *not* included: it is
     * written for operators by the DNS engine and may quote a provider response.
     * The owner sees DashboardStatus::failureMessage() instead.
     *
     * @return array{job: array<string, mixed>|null, records: list<array<string, mixed>>}
     */
    public static function provisioningFor(int $subdomainId): array
    {
        $job = Database::selectOne(
            'SELECT id, subdomain_id, status, attempts, available_at, created_at, updated_at
               FROM provisioning_jobs
              WHERE subdomain_id = :id
              ORDER BY id DESC LIMIT 1',
            ['id' => $subdomainId]
        );

        // `has_provider_record` is computed in SQL so the provider's record id
        // never leaves the database: the timeline only needs to know whether the
        // zone gave us one back, not what it was.
        $records = Database::select(
            "SELECT type, name, content, status, last_synced_at, created_at,
                    CASE WHEN provider_record_id IS NULL OR provider_record_id = '' THEN 0 ELSE 1 END AS has_provider_record
               FROM dns_records
              WHERE subdomain_id = :id AND status <> 'deleted'
              ORDER BY id ASC",
            ['id' => $subdomainId]
        );

        return [
            'job' => $job !== null ? self::normaliseJob($job) : null,
            'records' => $records,
        ];
    }

    /**
     * Latest provisioning job for several subdomains at once.
     *
     * One query, keyed by subdomain id. Used by the list page so rendering N
     * rows does not become N queries (Task 05 §21).
     *
     * @param list<int> $subdomainIds
     * @return array<int, array<string, mixed>> Map of subdomain id => job
     */
    public static function provisioningForMany(array $subdomainIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $subdomainIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        // The max id per subdomain is the latest job; the inner join keeps only
        // the newest row per subdomain without a correlated subquery per row.
        $rows = Database::select(
            "SELECT j.id, j.subdomain_id, j.status, j.attempts, j.available_at, j.created_at, j.updated_at
               FROM provisioning_jobs j
               INNER JOIN (
                    SELECT subdomain_id, MAX(id) AS latest_id
                      FROM provisioning_jobs
                     WHERE subdomain_id IN ($placeholders)
                     GROUP BY subdomain_id
               ) newest ON newest.latest_id = j.id",
            $ids
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['subdomain_id']] = self::normaliseJob($row);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normaliseJob(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['subdomain_id'] = (int) ($row['subdomain_id'] ?? 0);
        $row['attempts'] = (int) ($row['attempts'] ?? 0);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalise(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['domain_id'] = (int) $row['domain_id'];
        $row['user_id'] = (int) $row['user_id'];

        return $row;
    }
}
