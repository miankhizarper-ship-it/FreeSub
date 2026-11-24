<?php

declare(strict_types=1);

/**
 * Root domain repository.
 *
 * Adding a root domain is a single INSERT — no code changes and no config
 * deployment. The UI and API read from this table only.
 */
final class Domain
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM domains';

        if ($onlyActive) {
            $sql .= " WHERE is_active = 1 AND registration_status IN ('ready', 'maintenance')";
        }

        $sql .= ' ORDER BY sort_order ASC, root_domain ASC';

        $rows = Database::select($sql);

        return array_map([self::class, 'normalise'], $rows);
    }

    /**
     * Domains that may be offered to end users right now.
     *
     * @return list<array<string, mixed>>
     */
    public static function available(): array
    {
        $rows = Database::select(
            "SELECT * FROM domains
             WHERE is_active = 1 AND is_public = 1 AND registration_status = 'ready'
             ORDER BY sort_order ASC, root_domain ASC"
        );

        return array_map([self::class, 'normalise'], $rows);
    }

    /**
     * Distinct public extensions with domain counts.
     *
     * @return list<array{extension: string, domains: int, root_domain: string}>
     */
    public static function extensions(): array
    {
        $rows = Database::select(
            "SELECT extension, COUNT(*) AS domains, MIN(root_domain) AS root_domain
             FROM domains
             WHERE is_active = 1 AND is_public = 1 AND registration_status = 'ready'
             GROUP BY extension
             ORDER BY MIN(sort_order) ASC, extension ASC"
        );

        return array_map(static fn (array $row): array => [
            'extension' => (string) $row['extension'],
            'domains' => (int) $row['domains'],
            'root_domain' => (string) $row['root_domain'],
        ], $rows);
    }

    public static function find(int $id): ?array
    {
        $row = Database::selectOne('SELECT * FROM domains WHERE id = :id LIMIT 1', ['id' => $id]);

        return $row !== null ? self::normalise($row) : null;
    }

    public static function findByRoot(string $rootDomain): ?array
    {
        $row = Database::selectOne(
            'SELECT * FROM domains WHERE root_domain = :root LIMIT 1',
            ['root' => mb_strtolower(trim($rootDomain))]
        );

        return $row !== null ? self::normalise($row) : null;
    }

    public static function count(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM domains');
    }

    public static function countActive(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM domains WHERE is_active = 1');
    }

    /**
     * Cast driver specific types (SQLite returns strings/int interchangeably).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalise(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['is_public'] = (bool) $row['is_public'];
        $row['subdomain_quota'] = (int) ($row['subdomain_quota'] ?? config('subdomains.default_quota', 5));
        $row['sort_order'] = (int) ($row['sort_order'] ?? 0);

        if (empty($row['extension'])) {
            $row['extension'] = substr((string) $row['root_domain'], (int) strrpos((string) $row['root_domain'], '.') + 1);
        }

        return $row;
    }
}
