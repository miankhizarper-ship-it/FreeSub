<?php

declare(strict_types=1);

/**
 * Subdomain repository (database state only — DNS is handled by a provider
 * driver that is intentionally not implemented yet).
 */
final class Subdomain
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return Database::select(
            'SELECT s.*, d.root_domain, d.nameservers
             FROM subdomains s
             INNER JOIN domains d ON d.id = s.domain_id
             WHERE s.user_id = :user_id
             ORDER BY s.id DESC LIMIT ' . $limit,
            ['user_id' => $userId]
        );
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
