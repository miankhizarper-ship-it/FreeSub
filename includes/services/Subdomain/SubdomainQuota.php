<?php

declare(strict_types=1);

/**
 * Quota resolution and accounting (Task 04).
 *
 * A user's allowance is the most specific value available:
 *
 *   users.subdomain_quota   (an administrator granted this account extra names)
 *   domains.subdomain_quota (the per-root-domain default)
 *   config subdomains.initial_quota (the platform default for a new account)
 *
 * Usage counts everything that actually occupies a name: non-terminated
 * subdomains plus pending/approved reservations. Counting reservations is what
 * stops a user from queueing more requests than they may ever hold.
 */
final class SubdomainQuota
{
    /**
     * The allowance for one user on one root domain.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $domain
     */
    public static function limit(array $user, ?array $domain = null): int
    {
        $override = $user['subdomain_quota'] ?? null;

        if ($override !== null && (int) $override > 0) {
            return (int) $override;
        }

        if ($domain !== null && (int) ($domain['subdomain_quota'] ?? 0) > 0) {
            return (int) $domain['subdomain_quota'];
        }

        return max(1, (int) config('subdomains.initial_quota', 2));
    }

    /**
     * How many names this user currently holds or has asked for.
     */
    public static function used(int $userId): int
    {
        $subdomains = (int) Database::scalar(
            "SELECT COUNT(*) FROM subdomains WHERE user_id = :id AND status <> 'terminated'",
            ['id' => $userId]
        );

        $reservations = (int) Database::scalar(
            "SELECT COUNT(*) FROM reservations WHERE user_id = :id AND status IN ('pending', 'approved')",
            ['id' => $userId]
        );

        return $subdomains + $reservations;
    }

    /**
     * Full picture for the dashboard.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $domain
     * @return array{limit: int, used: int, remaining: int, reserved: int, active: int}
     */
    public static function summary(array $user, ?array $domain = null): array
    {
        $userId = (int) $user['id'];
        $limit = self::limit($user, $domain);
        $used = self::used($userId);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'reserved' => (int) Database::scalar(
                "SELECT COUNT(*) FROM reservations WHERE user_id = :id AND status IN ('pending', 'approved')",
                ['id' => $userId]
            ),
            'active' => (int) Database::scalar(
                "SELECT COUNT(*) FROM subdomains WHERE user_id = :id AND status = 'active'",
                ['id' => $userId]
            ),
        ];
    }

    /**
     * The dashboard's view of the allowance, without changing any rule.
     *
     * The reservation service compares *account-wide* usage against the limit
     * of the domain being requested (`ReservationService::create()`), so this
     * mirrors exactly that: `used` is the account figure, and the headline
     * `limit` is the largest per-domain allowance, i.e. the domain that still
     * has room. `remaining` is therefore "how many more names this account can
     * actually create right now", which is what a person reads it as.
     *
     * The per-domain rows below are shown next to it, so no number is presented
     * without the context that explains it.
     *
     * @param list<array<string, mixed>> $domains Public, active root domains
     * @return array{used: int, limit: int, remaining: int, primary: array{domain_id: int, root_domain: string, limit: int, used: int, remaining: int}|null}
     */
    public static function overview(array $user, array $domains): array
    {
        $rows = self::byDomain($user, $domains);
        $used = self::used((int) $user['id']);

        if ($rows === []) {
            // No domain is open: report the platform default so the page can
            // still render an honest "0 of N" instead of dividing by zero.
            $limit = max(1, (int) config('subdomains.initial_quota', 2));

            return ['used' => $used, 'limit' => $limit, 'remaining' => max(0, $limit - $used), 'primary' => null];
        }

        $limit = 0;

        foreach ($rows as $row) {
            $limit = max($limit, $row['limit']);
        }

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'primary' => $rows[0],
        ];
    }

    /**
     * Per-domain usage, as the reservation service counts it.
     *
     * Usage is per root domain because a reservation occupies a label on one
     * domain. The overall figures use the same arithmetic so the numbers never
     * contradict each other.
     *
     * @return list<array{domain_id: int, root_domain: string, limit: int, used: int, remaining: int}>
     */
    public static function byDomain(array $user, array $domains): array
    {
        $userId = (int) $user['id'];
        $rows = [];

        foreach ($domains as $domain) {
            $domainId = (int) ($domain['id'] ?? 0);

            if ($domainId <= 0) {
                continue;
            }

            $subdomains = (int) Database::scalar(
                "SELECT COUNT(*) FROM subdomains WHERE user_id = :id AND domain_id = :domain AND status <> 'terminated'",
                ['id' => $userId, 'domain' => $domainId]
            );

            $reservations = (int) Database::scalar(
                "SELECT COUNT(*) FROM reservations WHERE user_id = :id AND domain_id = :domain AND status IN ('pending', 'approved')",
                ['id' => $userId, 'domain' => $domainId]
            );

            $limit = self::limit($user, $domain);
            $used = $subdomains + $reservations;

            $rows[] = [
                'domain_id' => $domainId,
                'root_domain' => (string) ($domain['root_domain'] ?? ''),
                'limit' => $limit,
                'used' => $used,
                'remaining' => max(0, $limit - $used),
            ];
        }

        return $rows;
    }

    /**
     * Administrator sets an account allowance. Bounded, audited, server-side.
     */
    public static function setLimit(int $userId, ?int $limit, int $actorId): array
    {
        if ($limit !== null && ($limit < 0 || $limit > 500)) {
            return ['ok' => false, 'message' => 'Quota must be between 0 and 500.'];
        }

        Database::execute(
            'UPDATE users SET subdomain_quota = :quota, updated_at = :updated_at WHERE id = :id',
            ['quota' => $limit, 'updated_at' => now(), 'id' => $userId]
        );

        audit_log(
            'admin.quota_updated',
            $actorId,
            ['target_user' => $userId, 'quota' => $limit],
            'user',
            $userId,
            $limit === null ? 'Quota reset to the domain default' : 'Quota set to ' . $limit
        );

        return ['ok' => true, 'message' => 'Quota updated.'];
    }
}
