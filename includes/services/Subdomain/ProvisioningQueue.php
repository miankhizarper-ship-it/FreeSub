<?php

declare(strict_types=1);

/**
 * DNS provisioning queue (Task 04).
 *
 * An approved reservation does not become a live hostname by approval. It
 * becomes a *job* here, and the job only succeeds when the Cloudflare record
 * exists and reads back with the expected value.
 *
 * Guarantees, in the order they matter:
 *
 *   1. **No false active.** `activate()` is only reached after a successful
 *      create *and* a successful read-back. Any other outcome leaves the
 *      subdomain `pending_dns` or `failed`, never `active`.
 *   2. **No blind retry.** A job that ends ambiguously (timeout, 5xx on POST)
 *      is reconciled: the zone is read first, and if our record is there with
 *      the right value it is adopted. Only a *definite* failure is retried.
 *   3. **Bounded retries.** `attempts` is incremented before the write and
 *      checked before it, so a crash loop cannot hammer the API.
 *   4. **Single owner.** A job is claimed with a conditional UPDATE, so two
 *      workers (or an admin and the CLI) cannot provision the same subdomain
 *      at once.
 *   5. **Visible failures.** `last_error` and the job status are rendered in the
 *      admin queue; nothing fails silently.
 */
final class ProvisioningQueue
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const MAX_ATTEMPTS = 5;

    /**
     * Queue a subdomain for provisioning. Idempotent per subdomain: if a live
     * job already exists it is returned instead of creating a second one.
     */
    public static function enqueue(int $subdomainId, int $domainId, string $fqdn, ?int $actorId = null): int
    {
        $existing = Database::selectOne(
            "SELECT id FROM provisioning_jobs
              WHERE subdomain_id = :id AND status IN ('pending', 'running')
              ORDER BY id ASC LIMIT 1",
            ['id' => $subdomainId]
        );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        Database::execute(
            'INSERT INTO provisioning_jobs
                (subdomain_id, domain_id, fqdn, status, attempts, available_at, requested_by, created_at, updated_at)
             VALUES
                (:subdomain_id, :domain_id, :fqdn, :status, 0, :available_at, :requested_by, :created_at, :updated_at)',
            [
                'subdomain_id' => $subdomainId,
                'domain_id' => $domainId,
                'fqdn' => strtolower($fqdn),
                'status' => self::PENDING,
                'available_at' => now(),
                'requested_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $jobId = Database::lastInsertId();

        audit_log(
            'dns.provision_queued',
            $actorId,
            ['fqdn' => $fqdn, 'subdomain_id' => $subdomainId, 'job_id' => $jobId, 'actor_type' => $actorId !== null ? 'admin' : 'system'],
            'subdomain',
            $subdomainId,
            'Queued for DNS provisioning: ' . $fqdn
        );

        return $jobId;
    }

    /**
     * Cancel every live job for a subdomain (used when a reservation is
     * rejected or released).
     */
    public static function cancelForSubdomain(int $subdomainId, ?int $actorId = null): int
    {
        $affected = Database::execute(
            "UPDATE provisioning_jobs
                SET status = :cancelled, updated_at = :updated_at
              WHERE subdomain_id = :id AND status IN ('pending', 'running')",
            ['cancelled' => self::CANCELLED, 'updated_at' => now(), 'id' => $subdomainId]
        );

        if ($affected > 0) {
            audit_log(
                'dns.provision_cancelled',
                $actorId,
                ['subdomain_id' => $subdomainId, 'jobs' => $affected],
                'subdomain',
                $subdomainId,
                'Provisioning cancelled'
            );
        }

        return $affected;
    }

    /**
     * Claim the next runnable job.
     *
     * The claim is a conditional UPDATE: only the winner sees `rowCount() === 1`,
     * so two processes cannot work the same job.
     *
     * @return array<string, mixed>|null
     */
    public static function claim(?int $jobId = null): ?array
    {
        $lockTimeout = max(30, (int) config('provisioning.lock_timeout', 300));
        $staleBefore = gmdate('Y-m-d H:i:s', time() - $lockTimeout);

        $sql = "SELECT * FROM provisioning_jobs
                 WHERE status = :pending
                   AND available_at <= :now
                   AND attempts < :max_attempts";

        $params = [
            'pending' => self::PENDING,
            'now' => now(),
            'max_attempts' => self::maxAttempts(),
        ];

        if ($jobId !== null) {
            $sql .= ' AND id = :id';
            $params['id'] = $jobId;
        }

        // Recover jobs abandoned by a crashed worker.
        Database::execute(
            "UPDATE provisioning_jobs
                SET status = :pending, updated_at = :updated_at
              WHERE status = :running AND updated_at < :stale",
            ['pending' => self::PENDING, 'updated_at' => now(), 'running' => self::RUNNING, 'stale' => $staleBefore]
        );

        $job = Database::selectOne($sql . ' ORDER BY id ASC LIMIT 1', $params);

        if ($job === null) {
            return null;
        }

        $claimed = Database::execute(
            "UPDATE provisioning_jobs
                SET status = :running, attempts = attempts + 1, updated_at = :updated_at
              WHERE id = :id AND status = :pending",
            ['running' => self::RUNNING, 'updated_at' => now(), 'id' => (int) $job['id'], 'pending' => self::PENDING]
        );

        if ($claimed !== 1) {
            return null;
        }

        $job['status'] = self::RUNNING;
        $job['attempts'] = (int) $job['attempts'] + 1;

        return $job;
    }

    /**
     * Provision one job.
     *
     * @param array<string, mixed> $job       A claimed job row
     * @param array<string, mixed>|null $admin Acting administrator, null for CLI
     * @return array{ok: bool, status: string, message: string, provider_record_id?: string}
     */
    public static function provision(array $job, ?array $admin = null): array
    {
        $jobId = (int) $job['id'];
        $subdomainId = (int) $job['subdomain_id'];
        $actorId = $admin !== null ? (int) $admin['id'] : null;
        $fqdn = (string) $job['fqdn'];

        $subdomain = Subdomain::find($subdomainId);
        $domain = Domain::find((int) $job['domain_id']);

        if ($subdomain === null || $domain === null) {
            return self::fail($jobId, $subdomainId, $actorId, 'The subdomain or root domain no longer exists.', true);
        }

        if ((string) $subdomain['status'] === 'terminated') {
            return self::fail($jobId, $subdomainId, $actorId, 'The subdomain was released before provisioning.', true);
        }

        $manager = new DnsManager();

        if (!$manager->isEnabled()) {
            // Not a failure of the name: the engine is simply switched off. The
            // job stays pending so it runs once an operator enables it.
            self::release($jobId, 900);

            return ['ok' => false, 'status' => 'deferred', 'message' => 'DNS provisioning is not enabled.'];
        }

        $zone = $manager->provider()->verifyZone((string) $domain['root_domain']);

        if (!$zone['ok']) {
            // A broken zone or credential is an operator problem, not the
            // requester's. Keep the job, tell the admin.
            self::fail($jobId, $subdomainId, $actorId, $zone['message'], true, 'pending');

            return ['ok' => false, 'status' => 'failed', 'message' => $zone['message']];
        }

        // Addresses come from configuration: a subdomain is a name, not a host,
        // so the platform decides where it points.
        $targets = self::targetsFor((string) $domain['root_domain']);

        if ($targets === []) {
            return self::fail($jobId, $subdomainId, $actorId, 'No provisioning target is configured (DNS_PROVISIONING_A and/or DNS_PROVISIONING_AAAA).', true);
        }

        foreach ($targets as $target) {
            $outcome = self::provisionTarget($manager, $subdomain, $domain, $fqdn, $target, $actorId);

            if (!$outcome['ok']) {
                // Leave the subdomain pending_dns; the admin sees last_error.
                self::fail($jobId, $subdomainId, $actorId, $outcome['message'], $outcome['retry']);

                return ['ok' => false, 'status' => 'failed', 'message' => $outcome['message']];
            }
        }

        // Only now, with every record read back successfully, may the subdomain
        // become active. This is the single place that writes that state.
        Subdomain::markStatus($subdomainId, 'active');

        Database::execute(
            'UPDATE provisioning_jobs
                SET status = :done, last_error = NULL, updated_at = :updated_at
              WHERE id = :id',
            ['done' => self::DONE, 'updated_at' => now(), 'id' => $jobId]
        );

        audit_log(
            'dns.provisioned',
            $actorId,
            [
                'fqdn' => $fqdn,
                'subdomain_id' => $subdomainId,
                'targets' => $targets,
                'attempt' => (int) ($job['attempts'] ?? 1),
                'actor_type' => $actorId !== null ? 'admin' : 'system',
            ],
            'subdomain',
            $subdomainId,
            'Provisioned and verified: ' . $fqdn
        );

        return ['ok' => true, 'status' => 'active', 'message' => 'Provisioned and verified: ' . $fqdn];
    }

    /**
     * Create one record and prove it exists.
     *
     * @param array<string, mixed> $subdomain
     * @param array<string, mixed> $domain
     * @param array{0: string, 1: string} $target type, content
     * @return array{ok: bool, message: string, retry: bool}
     */
    private static function provisionTarget(
        DnsManager $manager,
        array $subdomain,
        array $domain,
        string $fqdn,
        array $target,
        ?int $actorId
    ): array {
        [$type, $content] = $target;
        $subdomainId = (int) $subdomain['id'];

        // Re-use the engine's create path: it validates, checks conflicts,
        // inserts the pending row, calls Cloudflare, stores the returned id and
        // audits — and it reconciles instead of blind-retrying.
        $record = DnsRecordRepository::nameTaken((int) $domain['id'], $fqdn);

        if ($record === null) {
            $created = $manager->createForSubdomain($subdomain, ['type' => $type, 'content' => $content], $actorId);

            if (!$created['ok']) {
                // A validation/conflict failure will not fix itself on retry.
                $permanent = isset($created['errors']) || str_contains((string) $created['message'], 'already');

                return ['ok' => false, 'message' => (string) $created['message'], 'retry' => !$permanent];
            }

            $record = DnsRecordRepository::find((int) ($created['record']['id'] ?? 0));
        }

        if ($record === null || (string) ($record['provider_record_id'] ?? '') === '') {
            return ['ok' => false, 'message' => 'The record was not recorded with a provider id.', 'retry' => true];
        }

        // ---- read-back verification ---------------------------------------
        $delay = max(0, (int) config('provisioning.verify_delay_ms', 0));

        if ($delay > 0) {
            usleep($delay * 1000);
        }

        try {
            $live = $manager->provider()->findRecord($type, $fqdn);
        } catch (CloudflareApiException $exception) {
            return ['ok' => false, 'message' => 'Could not verify the record: ' . $exception->getMessage(), 'retry' => true];
        }

        if ($live === null) {
            return ['ok' => false, 'message' => 'The record was created but could not be read back from the zone.', 'retry' => true];
        }

        if ((string) $live['id'] !== (string) $record['provider_record_id']) {
            return ['ok' => false, 'message' => 'The zone returned a different record id than the one stored.', 'retry' => false];
        }

        if (strcasecmp((string) $live['content'], (string) $record['content']) !== 0) {
            return ['ok' => false, 'message' => 'The live record value does not match what was requested.', 'retry' => false];
        }

        DnsRecordRepository::markActive((int) $record['id'], (string) $live['id']);
        DnsRecordRepository::touchSynced((int) $record['id']);

        return ['ok' => true, 'message' => 'Verified ' . $type . ' ' . $fqdn, 'retry' => false];
    }

    /**
     * Mark a job (and the subdomain) as failed, keeping the detail visible.
     */
    private static function fail(int $jobId, int $subdomainId, ?int $actorId, string $message, bool $retryable, ?string $jobStatus = null): array
    {
        $attempts = (int) Database::scalar('SELECT attempts FROM provisioning_jobs WHERE id = :id', ['id' => $jobId]);
        $exhausted = $attempts >= self::maxAttempts();

        $status = $jobStatus ?? ($retryable && !$exhausted ? self::PENDING : self::FAILED);
        $availableAt = $status === self::PENDING
            ? gmdate('Y-m-d H:i:s', time() + min(3600, 60 * (2 ** max(1, $attempts))))
            : now();

        Database::execute(
            'UPDATE provisioning_jobs
                SET status = :status, last_error = :error, available_at = :available_at, updated_at = :updated_at
              WHERE id = :id',
            [
                'status' => $status,
                'error' => mb_substr($message, 0, 500),
                'available_at' => $availableAt,
                'updated_at' => now(),
                'id' => $jobId,
            ]
        );

        // The subdomain is marked failed only when no retry is left; otherwise it
        // stays pending_dns, which is the truth: not live, still expected.
        if ($status === self::FAILED && $subdomainId > 0) {
            Subdomain::markStatus($subdomainId, 'failed');
        }

        audit_log(
            'dns.provision_failed',
            $actorId,
            [
                'job_id' => $jobId,
                'subdomain_id' => $subdomainId,
                'error' => mb_substr($message, 0, 300),
                'will_retry' => $status === self::PENDING,
                'actor_type' => $actorId !== null ? 'admin' : 'system',
            ],
            'subdomain',
            $subdomainId > 0 ? $subdomainId : null,
            'Provisioning failed'
        );

        return ['ok' => false, 'status' => $status, 'message' => $message];
    }

    private static function release(int $jobId, int $delaySeconds): void
    {
        Database::execute(
            'UPDATE provisioning_jobs SET status = :pending, available_at = :available_at, updated_at = :updated_at WHERE id = :id',
            [
                'pending' => self::PENDING,
                'available_at' => gmdate('Y-m-d H:i:s', time() + $delaySeconds),
                'updated_at' => now(),
                'id' => $jobId,
            ]
        );
    }

    public static function maxAttempts(): int
    {
        return max(1, min(20, (int) config('provisioning.max_attempts', self::MAX_ATTEMPTS)));
    }

    /**
     * Where a new subdomain points. Read from the environment, never from a
     * request: a user must not be able to aim their subdomain at an address of
     * their choosing.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function targetsFor(string $rootDomain): array
    {
        $targets = [];
        $a = trim((string) config('provisioning.targets.a', ''));
        $aaaa = trim((string) config('provisioning.targets.aaaa', ''));
        $cname = trim((string) config('provisioning.targets.cname', ''));

        if ($a !== '' && DnsRecordValidator::validateIpv4($a) === null) {
            $targets[] = ['A', $a];
        }

        if ($aaaa !== '' && DnsRecordValidator::validateIpv6($aaaa) === null) {
            $targets[] = ['AAAA', $aaaa];
        }

        // A CNAME is only used when no address is configured: it cannot coexist
        // with an A/AAAA at the same name.
        if ($targets === [] && $cname !== '' && DnsRecordValidator::validateCnameTarget($cname, $rootDomain) === null) {
            $targets[] = ['CNAME', strtolower(rtrim($cname, '.'))];
        }

        return $targets;
    }

    /**
     * Run one specific job, by id.
     *
     * The admin UI and the CLI both go through here so neither can bypass the
     * claim step: an already-running job simply returns null and does nothing.
     *
     * @param array<string, mixed>|null $admin Null for the CLI.
     * @return array{ok: bool, status: string, message: string}
     */
    public static function run(int $jobId, ?array $admin = null): array
    {
        if ($jobId <= 0) {
            return ['ok' => false, 'status' => 'invalid', 'message' => 'No job was selected.'];
        }

        $job = self::claim($jobId);

        if ($job === null) {
            $existing = Database::selectOne('SELECT status, last_error FROM provisioning_jobs WHERE id = :id', ['id' => $jobId]);

            if ($existing === null) {
                return ['ok' => false, 'status' => 'missing', 'message' => 'That provisioning job no longer exists.'];
            }

            return [
                'ok' => false,
                'status' => (string) $existing['status'],
                'message' => 'This job is ' . (string) $existing['status'] . ' and cannot be run right now.'
                    . ((string) ($existing['last_error'] ?? '') !== '' ? ' Last error: ' . (string) $existing['last_error'] : ''),
            ];
        }

        return self::provision($job, $admin);
    }

    /**
     * Put a failed or cancelled job back in the queue.
     *
     * `attempts` is reset because a human decided the conditions changed (a
     * credential fixed, a conflict resolved). Automatic retries never do this —
     * that is the difference between "retry" and "blind retry".
     */
    public static function retry(int $jobId, ?int $actorId = null): array
    {
        $job = Database::selectOne('SELECT * FROM provisioning_jobs WHERE id = :id', ['id' => $jobId]);

        if ($job === null) {
            return ['ok' => false, 'message' => 'That provisioning job no longer exists.'];
        }

        if ((string) $job['status'] === self::DONE) {
            return ['ok' => false, 'message' => 'That job already completed; nothing to retry.'];
        }

        if ((string) $job['status'] === self::RUNNING) {
            return ['ok' => false, 'message' => 'That job is running right now.'];
        }

        Database::execute(
            "UPDATE provisioning_jobs
                SET status = :pending, attempts = 0, available_at = :available_at, updated_at = :updated_at
              WHERE id = :id AND status IN ('failed', 'cancelled')",
            ['pending' => self::PENDING, 'available_at' => now(), 'updated_at' => now(), 'id' => $jobId]
        );

        // A failed subdomain goes back to pending_dns: it is expected again, not
        // live, and not broken beyond repair.
        if ((string) Subdomain::find((int) $job['subdomain_id'])['status'] === 'failed') {
            Subdomain::markStatus((int) $job['subdomain_id'], 'pending_dns');
        }

        audit_log(
            'dns.provision_retry_queued',
            $actorId,
            ['job_id' => $jobId, 'previous_status' => (string) $job['status']],
            'subdomain',
            (int) $job['subdomain_id'],
            'Provisioning retry queued'
        );

        return ['ok' => true, 'message' => 'Job #' . $jobId . ' is queued again.'];
    }

    /**
     * Cancel a job that has not succeeded.
     *
     * A completed job is refused: cancelling it would leave a live DNS record
     * with no owner, which is exactly the state this system exists to avoid.
     */
    public static function cancel(int $jobId, ?int $actorId = null): array
    {
        $job = Database::selectOne('SELECT * FROM provisioning_jobs WHERE id = :id', ['id' => $jobId]);

        if ($job === null) {
            return ['ok' => false, 'message' => 'That provisioning job no longer exists.'];
        }

        if ((string) $job['status'] === self::DONE) {
            return ['ok' => false, 'message' => 'That job completed; release the subdomain through support instead.'];
        }

        $affected = Database::execute(
            "UPDATE provisioning_jobs
                SET status = :cancelled, updated_at = :updated_at
              WHERE id = :id AND status IN ('pending', 'failed', 'running')",
            ['cancelled' => self::CANCELLED, 'updated_at' => now(), 'id' => $jobId]
        );

        if ($affected === 0) {
            return ['ok' => false, 'message' => 'That job is already ' . (string) $job['status'] . '.'];
        }

        audit_log(
            'dns.provision_cancelled',
            $actorId,
            ['job_id' => $jobId, 'fqdn' => (string) $job['fqdn']],
            'subdomain',
            (int) $job['subdomain_id'],
            'Provisioning cancelled'
        );

        return ['ok' => true, 'message' => 'Job #' . $jobId . ' cancelled.'];
    }

    /**
     * Queue rows for the admin page.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::select(
            'SELECT j.*, s.status AS subdomain_status, d.root_domain
               FROM provisioning_jobs j
               LEFT JOIN subdomains s ON s.id = j.subdomain_id
               LEFT JOIN domains d ON d.id = j.domain_id
              ORDER BY j.id DESC LIMIT ' . $limit
        );
    }

    /**
     * @return array{pending: int, running: int, failed: int, done: int}
     */
    public static function counts(): array
    {
        $row = Database::selectOne(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done
             FROM provisioning_jobs"
        );

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'running' => (int) ($row['running'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'done' => (int) ($row['done'] ?? 0),
        ];
    }
}
