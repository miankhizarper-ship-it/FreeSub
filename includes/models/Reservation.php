<?php

declare(strict_types=1);

/**
 * Reservation repository.
 *
 * A reservation records a user's request to own a label. It never creates a
 * DNS record by itself and never pretends a hostname is live.
 */
final class Reservation
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'expired'];

    /**
     * Reject/cancel/expire rows stay, so the label's history is preserved. What
     * a live row holds is the *name*, which is what the unique index enforces.
     */
    private const LIVE_STATUSES = ['pending', 'approved'];

    public static function create(
        int $userId,
        int $domainId,
        string $label,
        ?string $purpose = null,
        ?int $requestedBy = null,
        ?string $expiresAt = null
    ): int {
        Database::execute(
            'INSERT INTO reservations (user_id, domain_id, label, status, purpose, request_ip, expires_at, live_guard, created_at, updated_at)
             VALUES (:user_id, :domain_id, :label, :status, :purpose, :ip, :expires_at, :live_guard, :created_at, :updated_at)',
            [
                'user_id' => $userId,
                'domain_id' => $domainId,
                'label' => mb_strtolower($label),
                'status' => 'pending',
                'purpose' => $purpose !== null ? mb_substr($purpose, 0, 255) : null,
                'ip' => request_ip(),
                'expires_at' => $expiresAt,
                // 1 while live, NULL otherwise: MySQL's stand-in for a partial
                // unique index (see the 2026_11_003 migration).
                'live_guard' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return Database::lastInsertId();
    }

    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM reservations WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * Pending reservation for the same label, if any.
     */
    public static function pendingFor(int $domainId, string $label): ?array
    {
        return Database::selectOne(
            "SELECT * FROM reservations
             WHERE domain_id = :domain_id AND label = :label AND status IN ('pending', 'approved')
             LIMIT 1",
            ['domain_id' => $domainId, 'label' => $label]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::select(
            'SELECT r.*, d.root_domain
             FROM reservations r
             INNER JOIN domains d ON d.id = r.domain_id
             WHERE r.user_id = :user_id
             ORDER BY r.id DESC LIMIT ' . $limit,
            ['user_id' => $userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function pending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::select(
            "SELECT r.*, d.root_domain, u.name AS user_name, u.email AS user_email
             FROM reservations r
             INNER JOIN domains d ON d.id = r.domain_id
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.status = 'pending'
             ORDER BY r.id ASC LIMIT " . $limit
        );
    }

    public static function countForUser(int $userId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM reservations WHERE user_id = :id', ['id' => $userId]);
    }

    public static function countByStatus(string $status): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM reservations WHERE status = :status',
            ['status' => $status]
        );
    }

    /**
     * Conditional status change, used everywhere a race is possible.
     *
     * The `AND status = :from` clause is the whole trick: two workers cannot both
     * move a row out of `pending`, so a user cancelling while an administrator
     * approves cannot both succeed. Returns the number of rows changed (0 or 1).
     *
     * `live_guard` is cleared on every terminal status so the unique index stops
     * considering the row, which is what releases the label.
     */
    public static function transition(
        int $id,
        string $from,
        string $to,
        ?int $reviewedBy = null,
        ?string $note = null
    ): int {
        if (!in_array($from, self::STATUSES, true) || !in_array($to, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown reservation status.');
        }

        return Database::execute(
            'UPDATE reservations
                SET status = :to,
                    live_guard = :live_guard,
                    reviewed_by = :reviewed_by,
                    reviewed_at = :reviewed_at,
                    admin_note = COALESCE(:note, admin_note),
                    updated_at = :updated_at
              WHERE id = :id AND status = :from',
            [
                'to' => $to,
                'live_guard' => in_array($to, self::LIVE_STATUSES, true) ? 1 : null,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $reviewedBy !== null ? now() : null,
                'note' => $note !== null && trim($note) !== '' ? mb_substr($note, 0, 500) : null,
                'updated_at' => now(),
                'id' => $id,
                'from' => $from,
            ]
        );
    }

    /** @return list<array<string, mixed>> Live (label-holding) rows for a domain. */
    public static function liveForDomain(int $domainId): array
    {
        return Database::select(
            "SELECT r.*, u.name AS user_name, u.email AS user_email, d.root_domain
               FROM reservations r
               INNER JOIN users u ON u.id = r.user_id
               INNER JOIN domains d ON d.id = r.domain_id
              WHERE r.domain_id = :id AND r.status IN ('pending', 'approved')
              ORDER BY r.id ASC",
            ['id' => $domainId]
        );
    }

    public static function updateStatus(int $id, string $status, ?int $reviewedBy = null, ?string $note = null): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown reservation status.');
        }

        return Database::execute(
            'UPDATE reservations
             SET status = :status, live_guard = :live_guard, reviewed_by = :reviewed_by,
                 reviewed_at = :reviewed_at, admin_note = :note, updated_at = :updated_at
             WHERE id = :id',
            [
                'status' => $status,
                'live_guard' => in_array($status, self::LIVE_STATUSES, true) ? 1 : null,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $reviewedBy !== null ? now() : null,
                'note' => $note,
                'updated_at' => now(),
                'id' => $id,
            ]
        ) > 0;
    }
}
