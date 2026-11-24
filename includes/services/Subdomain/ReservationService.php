<?php

declare(strict_types=1);

/**
 * Reservation lifecycle (Task 04).
 *
 *   create  → pending   (holds the label until it expires or is decided)
 *   approve → approved  (queues a provisioning job; still holds the label)
 *   reject  → rejected  (releases the label)
 *   cancel  → cancelled (user-initiated, releases the label)
 *   expire  → expired   (a lapsed pending request releases the label)
 *
 * Ownership and authorization are decided here, once, so the dashboard, the
 * admin queue and any future API cannot drift apart:
 *   * every user-facing method takes the *authenticated* user id from the
 *     caller's session (the caller passes Auth::id(), never a form value);
 *   * every admin-facing method takes the acting administrator and re-checks
 *     the role from the database before writing.
 *
 * Race conditions are handled by the database, not by good intentions:
 *   * a unique index allows at most one live reservation per (domain, label);
 *   * the "is it still pending?" check and the UPDATE are one statement;
 *   * a duplicate request is caught as a duplicate-key error and reported as
 *     "you already asked for this".
 */
final class ReservationService
{
    /**
     * Create a reservation for the authenticated user.
     *
     * @param array<string, mixed> $user  Authenticated row (never a form value)
     * @param array<string, mixed>|null $domain
     * @return array{ok: bool, message: string, reservation_id?: int, label?: string, fqdn?: string, errors?: array<string, string>, status?: string}
     */
    public static function create(array $user, string $rawLabel, ?array $domain, ?string $purpose = null): array
    {
        $userId = (int) ($user['id'] ?? 0);

        if ($userId <= 0) {
            return ['ok' => false, 'message' => 'You need to be signed in to reserve a name.'];
        }

        // Re-read the role/status from the database: a stale session must not be
        // able to act, and a suspended account must not reserve anything.
        $account = User::find($userId);

        if ($account === null || (string) ($account['status'] ?? '') !== 'active') {
            return ['ok' => false, 'message' => 'This account cannot reserve names right now.'];
        }

        if ($domain === null) {
            $domain = Domain::available()[0] ?? null;
        }

        if ($domain === null || !($domain['is_active'] ?? false)) {
            return ['ok' => false, 'message' => 'That extension is not available.'];
        }

        if ((string) ($domain['registration_status'] ?? 'ready') !== 'ready') {
            return ['ok' => false, 'message' => 'That extension is not accepting new subdomains right now.'];
        }

        $validation = SubdomainLabel::validate($rawLabel, (string) $domain['root_domain']);

        if (!$validation['ok']) {
            return [
                'ok' => false,
                'message' => $validation['message'],
                'errors' => ['label' => $validation['message']],
            ];
        }

        $label = $validation['label'];
        $fqdn = $label . '.' . $domain['root_domain'];

        // Availability is re-checked here and not trusted from the client. A
        // stale UI must not be able to reserve a name that was taken meanwhile.
        $availability = new AvailabilityEngine();
        $check = $availability->check($label, $domain, false);

        if (!$check['available']) {
            return [
                'ok' => false,
                'message' => $check['message'],
                'errors' => ['label' => $check['message']],
                'status' => $check['status'],
            ];
        }

        // Quota, counted against what the account actually holds.
        $limit = SubdomainQuota::limit($account, $domain);
        $used = SubdomainQuota::used($userId);

        if ($used >= $limit) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'You have reached your limit of %d subdomain%s. Cancel a pending request or contact support.',
                    $limit,
                    $limit === 1 ? '' : 's'
                ),
                'errors' => ['quota' => 'Quota reached.'],
            ];
        }

        $expiresAt = self::defaultExpiry();

        try {
            $reservationId = Database::transaction(static function () use ($userId, $domain, $label, $purpose, $expiresAt): int {
                // Final authoritative checks inside the transaction. The unique
                // index is the real guard; these produce a better message.
                if (Subdomain::labelTaken((int) $domain['id'], $label)) {
                    throw new DomainException('That name was just provisioned. Please pick another.');
                }

                if (Reservation::pendingFor((int) $domain['id'], $label) !== null) {
                    throw new DomainException('That name is already reserved. Please pick another.');
                }

                return Reservation::create(
                    $userId,
                    (int) $domain['id'],
                    $label,
                    $purpose !== null && trim($purpose) !== '' ? trim($purpose) : null,
                    (int) $userId,
                    $expiresAt
                );
            });
        } catch (DomainException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'errors' => ['label' => $exception->getMessage()]];
        } catch (PDOException $exception) {
            if (Database::errorCode($exception) === 'duplicate') {
                // Either the live-label unique index or a concurrent request from
                // the same user won the race. Both mean "already asked for".
                return [
                    'ok' => false,
                    'message' => 'You already have a live request for that name.',
                    'errors' => ['label' => 'Duplicate request.'],
                ];
            }

            error_log('[freesub] reservation insert failed: ' . $exception->getMessage());

            return ['ok' => false, 'message' => 'The reservation could not be saved. Please try again.'];
        }

        audit_log(
            'subdomain.reserved',
            $userId,
            [
                'fqdn' => $fqdn,
                'label' => $label,
                'domain_id' => (int) $domain['id'],
                'reservation_id' => $reservationId,
                'expires_at' => $expiresAt,
            ],
            'reservation',
            $reservationId,
            'Reserved ' . $fqdn
        );

        return [
            'ok' => true,
            'message' => 'Reservation received for ' . $fqdn . '. We will email you once it is reviewed.',
            'reservation_id' => $reservationId,
            'label' => $label,
            'fqdn' => $fqdn,
        ];
    }

    /**
     * A user cancels their own pending request.
     *
     * Ownership is enforced in the UPDATE's WHERE clause, so a guessed id from
     * another account changes nothing (and is reported as not found).
     *
     * @param array<string, mixed> $user
     */
    public static function cancel(array $user, int $reservationId): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $reservation = Reservation::find($reservationId);

        if ($reservation === null || (int) $reservation['user_id'] !== $userId) {
            // Same answer for "does not exist" and "not yours": no enumeration.
            audit_log('subdomain.reservation_cancel_denied', $userId, ['reservation_id' => $reservationId], 'reservation', $reservationId, 'Cancel denied');

            return ['ok' => false, 'message' => 'That reservation could not be found.'];
        }

        if ((string) $reservation['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'Only a pending reservation can be cancelled.'];
        }

        $affected = Reservation::transition(
            $reservationId,
            'pending',
            'cancelled',
            null,
            'Cancelled by the user.'
        );

        if ($affected === 0) {
            // Lost a race with an administrator's decision: say so honestly.
            return ['ok' => false, 'message' => 'That reservation was just reviewed and can no longer be cancelled.'];
        }

        audit_log(
            'subdomain.reservation_cancelled',
            $userId,
            ['reservation_id' => $reservationId, 'label' => $reservation['label']],
            'reservation',
            $reservationId,
            'Reservation cancelled by the user'
        );

        return ['ok' => true, 'message' => 'Reservation cancelled.'];
    }

    /**
     * Administrator approves a request.
     *
     * Approval does **not** activate anything. It releases the label to the
     * provisioning queue, which creates the DNS record and then — only after a
     * read-back proves it exists — flips the subdomain to `active`.
     *
     * @param array<string, mixed> $admin Authenticated administrator row
     */
    public static function approve(array $admin, int $reservationId, ?string $note = null): array
    {
        if (!self::isAdministrator($admin)) {
            audit_log('admin.reservation_approve_denied', (int) ($admin['id'] ?? 0), ['reservation_id' => $reservationId], 'reservation', $reservationId, 'Approve denied');

            return ['ok' => false, 'message' => 'You do not have permission to review reservations.'];
        }

        $adminId = (int) $admin['id'];
        $reservation = Reservation::find($reservationId);

        if ($reservation === null) {
            return ['ok' => false, 'message' => 'Reservation not found.'];
        }

        if ((string) $reservation['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'Only a pending reservation can be approved.'];
        }

        $domain = Domain::find((int) $reservation['domain_id']);

        if ($domain === null) {
            return ['ok' => false, 'message' => 'The root domain for this reservation no longer exists.'];
        }

        // Expired while sitting in the queue: closing it is the honest answer.
        if (self::isExpired($reservation)) {
            Reservation::transition($reservationId, 'pending', 'expired', null, 'Expired before review.');
            audit_log('admin.reservation_expired', $adminId, ['reservation_id' => $reservationId], 'reservation', $reservationId, 'Expired before review');

            return ['ok' => false, 'message' => 'That reservation expired before it could be approved.'];
        }

        if (Subdomain::labelTaken((int) $domain['id'], (string) $reservation['label'])) {
            return ['ok' => false, 'message' => 'That label is already provisioned as a subdomain.'];
        }

        try {
            $result = Database::transaction(static function () use ($reservation, $domain, $adminId, $note): array {
                $affected = Reservation::transition(
                    (int) $reservation['id'],
                    'pending',
                    'approved',
                    $adminId,
                    $note !== null && trim($note) !== '' ? trim($note) : null
                );

                if ($affected === 0) {
                    throw new DomainException('That reservation was already reviewed.');
                }

                // Create the subdomain in `pending_dns`. It is never active here:
                // provisioning has to prove the record exists first.
                $subdomainId = Subdomain::create(
                    (int) $reservation['user_id'],
                    (int) $domain['id'],
                    (string) $reservation['label'],
                    $reservation['label'] . '.' . $domain['root_domain'],
                    (int) $reservation['id']
                );

                // Queue exactly one provisioning job for it.
                $jobId = ProvisioningQueue::enqueue($subdomainId, (int) $domain['id'], (string) $reservation['label'] . '.' . $domain['root_domain'], $adminId);

                return ['subdomain_id' => $subdomainId, 'job_id' => $jobId];
            });
        } catch (DomainException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        } catch (PDOException $exception) {
            return Database::errorCode($exception) === 'duplicate'
                ? ['ok' => false, 'message' => 'That label is already provisioned or reserved.']
                : ['ok' => false, 'message' => 'The reservation could not be approved.'];
        }

        audit_log(
            'admin.reservation_approved',
            $adminId,
            [
                'fqdn' => $reservation['label'] . '.' . $domain['root_domain'],
                'reservation_id' => (int) $reservation['id'],
                'subdomain_id' => $result['subdomain_id'],
                'job_id' => $result['job_id'],
            ],
            'subdomain',
            $result['subdomain_id'],
            'Reservation approved; queued for DNS provisioning'
        );

        return [
            'ok' => true,
            'message' => 'Reservation approved. The subdomain is queued as pending DNS.',
            'subdomain_id' => $result['subdomain_id'],
            'job_id' => $result['job_id'],
        ];
    }

    /**
     * Administrator rejects a request. Releases the label.
     *
     * @param array<string, mixed> $admin
     */
    public static function reject(array $admin, int $reservationId, ?string $note = null): array
    {
        if (!self::isAdministrator($admin)) {
            audit_log('admin.reservation_reject_denied', (int) ($admin['id'] ?? 0), ['reservation_id' => $reservationId], 'reservation', $reservationId, 'Reject denied');

            return ['ok' => false, 'message' => 'You do not have permission to review reservations.'];
        }

        $adminId = (int) $admin['id'];
        $reservation = Reservation::find($reservationId);

        if ($reservation === null) {
            return ['ok' => false, 'message' => 'Reservation not found.'];
        }

        if (!in_array((string) $reservation['status'], ['pending', 'approved'], true)) {
            return ['ok' => false, 'message' => 'That reservation has already been closed.'];
        }

        $affected = Reservation::transition(
            $reservationId,
            (string) $reservation['status'],
            'rejected',
            $adminId,
            $note !== null && trim($note) !== '' ? trim($note) : null
        );

        if ($affected === 0) {
            return ['ok' => false, 'message' => 'That reservation was already reviewed.'];
        }

        // A rejected *approved* reservation may have a queued job and a pending
        // subdomain. Cancel both so nothing is provisioned for a rejected name.
        self::tearDown((int) $reservation['id'], $adminId);

        audit_log(
            'admin.reservation_rejected',
            $adminId,
            ['reservation_id' => $reservationId, 'label' => $reservation['label'], 'note' => $note],
            'reservation',
            $reservationId,
            'Reservation rejected'
        );

        return ['ok' => true, 'message' => 'Reservation rejected.'];
    }

    /**
     * Expire every pending reservation whose hold has lapsed.
     *
     * Safe to run repeatedly (cron or the provisioning CLI). Only rows that are
     * still pending and past `expires_at` are touched.
     *
     * @return array{expired: int, ids: list<int>}
     */
    public static function expireLapsed(?int $actorId = null): array
    {
        $rows = Database::select(
            "SELECT id, user_id, label, domain_id FROM reservations
              WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < :now
              ORDER BY id ASC LIMIT 500",
            ['now' => now()]
        );

        $expired = [];

        foreach ($rows as $row) {
            $affected = Reservation::transition((int) $row['id'], 'pending', 'expired', null, 'Expired automatically.');

            if ($affected === 0) {
                continue;
            }

            $expired[] = (int) $row['id'];

            audit_log(
                'subdomain.reservation_expired',
                $actorId,
                ['reservation_id' => (int) $row['id'], 'label' => (string) $row['label'], 'actor_type' => 'system'],
                'reservation',
                (int) $row['id'],
                'Reservation expired'
            );
        }

        return ['expired' => count($expired), 'ids' => $expired];
    }

    /**
     * History for one user, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

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
     * The dashboard buckets, all scoped to one authenticated user.
     *
     * @param array<string, mixed> $user
     * @return array{pending: list<array<string, mixed>>, active: list<array<string, mixed>>, rejected: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    public static function dashboard(int $userId): array
    {
        $rows = self::history($userId, 200);

        $pending = [];
        $rejected = [];
        $history = [];

        foreach ($rows as $row) {
            $status = (string) $row['status'];

            if (in_array($status, ['pending', 'approved'], true)) {
                $pending[] = $row;
            }

            if ($status === 'rejected') {
                $rejected[] = $row;
            }

            if (in_array($status, ['cancelled', 'expired', 'rejected'], true)) {
                $history[] = $row;
            }
        }

        $active = Database::select(
            "SELECT s.*, d.root_domain FROM subdomains s
               INNER JOIN domains d ON d.id = s.domain_id
              WHERE s.user_id = :user_id AND s.status <> 'terminated'
              ORDER BY s.id DESC LIMIT 100",
            ['user_id' => $userId]
        );

        return ['pending' => $pending, 'active' => $active, 'rejected' => $rejected, 'history' => $history];
    }

    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $reservation */
    public static function isExpired(array $reservation): bool
    {
        $expiresAt = $reservation['expires_at'] ?? null;

        return is_string($expiresAt) && $expiresAt !== '' && $expiresAt < now();
    }

    /**
     * Role is read from the database, never from the session or a claim.
     *
     * @param array<string, mixed> $actor
     */
    public static function isAdministrator(array $actor): bool
    {
        $id = (int) ($actor['id'] ?? 0);

        if ($id <= 0) {
            return false;
        }

        $row = Database::selectOne('SELECT role, status FROM users WHERE id = :id', ['id' => $id]);

        return $row !== null
            && (string) $row['role'] === 'admin'
            && (string) $row['status'] === 'active';
    }

    /** Default hold: configurable, never null (a reservation must always lapse). */
    public static function defaultExpiry(): string
    {
        $hours = max(1, (int) config('subdomains.reservation_ttl_hours', 168));

        return gmdate('Y-m-d H:i:s', time() + ($hours * 3600));
    }

    /** Release the queue and any pending subdomain created for a reservation. */
    private static function tearDown(int $reservationId, int $adminId): void
    {
        $subdomain = Database::selectOne(
            'SELECT id, status FROM subdomains WHERE reservation_id = :id LIMIT 1',
            ['id' => $reservationId]
        );

        if ($subdomain === null) {
            return;
        }

        ProvisioningQueue::cancelForSubdomain((int) $subdomain['id'], $adminId);

        // Only a subdomain that never became live may be terminated here; an
        // active one is released through the DNS-aware flow instead.
        if (in_array((string) $subdomain['status'], ['pending_dns', 'failed'], true)) {
            Database::execute(
                "UPDATE subdomains SET status = 'terminated', updated_at = :updated_at WHERE id = :id",
                ['updated_at' => now(), 'id' => (int) $subdomain['id']]
            );
        }
    }
}
