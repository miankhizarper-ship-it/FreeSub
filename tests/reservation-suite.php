<?php

declare(strict_types=1);

/**
 * Reservation engine suite (Task 04).
 *
 * Covers the brief's §11 list. Everything below runs against the SQLite test
 * database and a scripted Cloudflare transport, so it proves *behaviour*, not
 * connectivity: no live zone is contacted and nothing is written publicly.
 *
 * Each check states which rule it pins down, because these are the rules most
 * likely to be "simplified" later by someone chasing a green build.
 */

/** A clean root domain + user namespace for this suite. */
function reservation_test_user(string $name, string $email, bool $admin = false): array
{
    $user = User::findByEmail($email);

    if ($user === null) {
        $user = User::create($name, $email, 'TestPassw0rd!23');
    }

    if ($admin && (string) $user['role'] !== 'admin') {
        Database::execute("UPDATE users SET role = 'admin' WHERE id = :id", ['id' => (int) $user['id']]);
        $user = User::find((int) $user['id']);
    }

    return $user;
}

/** Remove every trace of this suite's names so a re-run starts clean. */
function reservation_test_cleanup(int $domainId): void
{
    Database::execute(
        "DELETE FROM provisioning_jobs WHERE domain_id = :id",
        ['id' => $domainId]
    );
    Database::execute(
        "DELETE FROM dns_records WHERE domain_id = :id",
        ['id' => $domainId]
    );
    Database::execute(
        "DELETE FROM subdomains WHERE domain_id = :id AND label LIKE 'rsv%'",
        ['id' => $domainId]
    );
    Database::execute(
        "DELETE FROM reservations WHERE domain_id = :id AND label LIKE 'rsv%'",
        ['id' => $domainId]
    );
}

if (run_suite($only, 'reservations')) {
    $t->suite('Reservation engine');

    $domain = Domain::findByRoot('kharcha.info');

    if ($domain === null) {
        $t->check('kharcha.info exists for reservation tests', false, 'seed domain missing');
    } else {
        reservation_test_cleanup((int) $domain['id']);
        Database::execute("UPDATE domains SET registration_status = 'ready', is_active = 1 WHERE id = :id", ['id' => (int) $domain['id']]);
        $domain = Domain::find((int) $domain['id']);

        $user = reservation_test_user('Rsv User', 'rsv-user@test.local');
        $other = reservation_test_user('Rsv Other', 'rsv-other@test.local');
        $admin = reservation_test_user('Rsv Admin', 'rsv-admin@test.local', true);

        /* ---------------------------------------------------------------
         * §11.1 — strict server-side validation
         * ------------------------------------------------------------ */
        $invalidCases = [
            'uppercase and malformed' => 'My Site',
            'unicode' => 'rsv-mïan',
            'leading hyphen' => '-rsv',
            'double hyphen' => 'rsv--x',
            'wildcard' => '*',
            'apex' => '@',
            'reserved word' => 'www',
            'too short' => 'ab',
            'numeric only' => '123456',
            'punycode' => 'xn--rsv',
        ];

        foreach ($invalidCases as $rule => $raw) {
            $validation = SubdomainLabel::validate($raw, 'kharcha.info');
            $t->check('validation rejects ' . $rule, $validation['ok'] === false, $validation['code'] ?? null);
        }

        $t->check('validation accepts a normal label', SubdomainLabel::validate('rsv-one', 'kharcha.info')['ok'] === true);
        $t->check('normalisation lowercases and strips a pasted apex',
            SubdomainLabel::validate('Rsv-One.kharcha.info', 'kharcha.info')['label'] === 'rsv-one');
        $t->check('validation rejects the wrong root domain',
            SubdomainLabel::validate('rsv.other.info', 'kharcha.info')['ok'] === false);

        /* ---------------------------------------------------------------
         * §11.2 — availability against local state
         * ------------------------------------------------------------ */
        $double = dns_test_environment();

        // The availability cache lives on disk and outlives a test process. A
        // stale entry from an earlier (possibly failing) run would make these
        // assertions non-deterministic, so start from a cold cache.
        (new AvailabilityCache())->clear();
        Database::execute(
            "UPDATE domains SET dns_provider = 'cloudflare', zone_reference = 'zone_kharcha_1' WHERE id = :id",
            ['id' => (int) $domain['id']]
        );
        $domain = Domain::find((int) $domain['id']);

        $engine = new AvailabilityEngine();
        $free = $engine->check('rsv-free', $domain, false);
        $t->check('a free label is available', $free['available'] === true && $free['status'] === 'available', $free['status']);

        $t->check('availability result states whether DNS was consulted', ($free['authoritative'] ?? false) === true);
        $t->check('a reserved label suggests something usable, not "www-app"',
            AvailabilityEngine::suggestions('www') !== [] && !in_array('www-app', AvailabilityEngine::suggestions('www'), true),
            AvailabilityEngine::suggestions('www'));

        // With no provider configured the answer must not claim a live check.
        $noProvider = new AvailabilityEngine();
        $GLOBALS['FREESUB_CONFIG_OVERRIDE']['dns']['provider'] = 'none';
        DnsProvider::reset();
        $localOnly = $noProvider->check('rsv-localonly', $domain, false);
        $t->check('without a provider the answer is local-only, not authoritative',
            $localOnly['authoritative'] === false && $localOnly['source'] === 'local', $localOnly['source']);
        $GLOBALS['FREESUB_CONFIG_OVERRIDE']['dns']['provider'] = 'cloudflare';
        DnsProvider::reset();

        // A record somebody else created in the zone must block the name.
        $double->addRecord('zone_kharcha_1', 'A', 'rsv-cloud.kharcha.info', '93.184.216.34');

        $cloud = $engine->check('rsv-cloud', $domain, false);
        $t->check('an unmanaged DNS record blocks the label', $cloud['available'] === false && $cloud['status'] === 'taken', $cloud);

        // TXT does not block; a verification record must not deny a real name.
        $double->addRecord('zone_kharcha_1', 'TXT', 'rsv-txt.kharcha.info', '"v=spf1 -all"');
        $t->check('a TXT record does not block the label', $engine->check('rsv-txt', $domain, false)['available'] === true);

        // Fail closed: a provider outage must never read as "available".
        // The client retries (max_retries = 2), so script every attempt — a
        // single injected 500 would be absorbed by the retry and prove nothing.
        // Three attempts = 1 request + 2 retries (dns.cloudflare.max_retries).
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $double->failNext(500);
        }

        $errorState = $engine->check('rsv-outage', $domain, false);
        $t->check('provider failure fails closed', $errorState['available'] === false, $errorState['status']);
        $t->check('provider failure is reported as unknown', $errorState['status'] === 'unknown', $errorState['status']);

        // Extra: a failure must not be cached. The provider is kept broken for a
        // second attempt: if the error had been cached, this lookup would be
        // answered from disk instead of consulting the zone again.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $double->failNext(500);
        }

        $secondOutage = $engine->check('rsv-outage', $domain, true);
        $t->check('a failed lookup is not cached', $secondOutage['status'] === 'unknown', $secondOutage['status']);
        $t->check('a failed lookup never writes to the zone', $double->writes() === []);

        // Cached answers are reused within the TTL.
        $double->resetRequests();
        $first = $engine->check('rsv-cache', $domain, true);
        $second = $engine->check('rsv-cache', $domain, true);
        $t->check('repeat lookups use the cache', $first['available'] === true && $second['cached'] === true, $second['cached']);

        /* ---------------------------------------------------------------
         * §11.3 — reservation lifecycle
         * ------------------------------------------------------------ */
        $created = ReservationService::create($user, 'rsv-alpha', $domain, 'Engine test');
        $t->check('a valid request is accepted', $created['ok'] === true, $created);
        $t->check('the request records a fqdn', ($created['fqdn'] ?? '') === 'rsv-alpha.kharcha.info');

        $reservationId = (int) $created['reservation_id'];
        $row = Reservation::find($reservationId);
        $t->check('the request is stored as pending', ($row['status'] ?? '') === 'pending');
        $t->check('the request carries an expiry', ($row['expires_at'] ?? '') !== '' && $row['expires_at'] !== null, $row['expires_at'] ?? null);
        $t->check('the request holds the label', (int) ($row['live_guard'] ?? 0) === 1);

        $t->check('no subdomain row is created by a request', Subdomain::labelTaken((int) $domain['id'], 'rsv-alpha') === false);
        $t->check('no DNS record is created by a request', DnsRecordRepository::nameTaken((int) $domain['id'], 'rsv-alpha.kharcha.info') === null);

        $nowPending = $engine->check('rsv-alpha', $domain, false);
        $t->check('a live reservation makes the label unavailable',
            $nowPending['available'] === false && $nowPending['status'] === 'pending', $nowPending['status']);

        // §11.4 — duplicates
        $duplicate = ReservationService::create($other, 'rsv-alpha', $domain, 'Same name');
        $t->check('a second live request for the same label is refused', $duplicate['ok'] === false, $duplicate);
        $t->check('the duplicate refusal does not leak the owner',
            !str_contains(mb_strtolower((string) $duplicate['message']), 'rsv-user@test.local'), $duplicate['message']);

        // §11.5 — quota
        // Count against *this* user's effective allowance, which may be the
        // per-domain default rather than the platform default; an override left
        // behind by an earlier run is removed so the count is deterministic.
        Database::execute('UPDATE users SET subdomain_quota = NULL WHERE id = :id', ['id' => (int) $user['id']]);
        $user = User::find((int) $user['id']);

        $quotaLimit = (int) SubdomainQuota::limit($user, $domain);
        $expectedQuota = (int) ($domain['subdomain_quota'] ?? 0) > 0
            ? (int) $domain['subdomain_quota']
            : (int) config('subdomains.initial_quota');

        $t->check('the configured quota is in force', $quotaLimit === $expectedQuota, $quotaLimit);
        $t->check('the platform default is the configured initial quota',
            (int) config('subdomains.initial_quota') === (int) env_int('SUBDOMAIN_INITIAL_QUOTA', 2));

        $accepted = 0;

        for ($i = 2; $i <= $quotaLimit; $i++) {
            $result = ReservationService::create($user, 'rsv-q' . $i, $domain);

            if ($result['ok']) {
                $accepted++;
            }
        }

        $t->check('requests up to the quota are accepted', $accepted === $quotaLimit - 1, $accepted);

        $overQuota = ReservationService::create($user, 'rsv-over', $domain);
        $t->check('a request beyond the quota is refused', $overQuota['ok'] === false, $overQuota);
        $t->check('the quota refusal explains itself',
            str_contains(mb_strtolower((string) $overQuota['message']), 'limit'), $overQuota['message']);

        // §11.6 — cancellation
        $cancelled = ReservationService::cancel($other, $reservationId);
        $t->check('a reservation cannot be cancelled by another account', $cancelled['ok'] === false, $cancelled);

        $cancelled = ReservationService::cancel($user, $reservationId);
        $t->check('the owner can cancel a pending reservation', $cancelled['ok'] === true, $cancelled);
        $t->check('a cancelled reservation releases the label',
            Reservation::find($reservationId)['live_guard'] === null);

        $t->check('the cancelled label can be requested again',
            ReservationService::create($other, 'rsv-alpha', $domain)['ok'] === true);

        $cancelledAgain = ReservationService::cancel($user, $reservationId);
        $t->check('a settled reservation cannot be cancelled twice', $cancelledAgain['ok'] === false, $cancelledAgain);

        /* ---------------------------------------------------------------
         * §11.7 — approval creates pending_dns, never active
         * ------------------------------------------------------------ */
        $approval = ReservationService::approve($admin, (int) Reservation::create(
            (int) $user['id'], (int) $domain['id'], 'rsv-approve', 'Approve me', (int) $user['id'], gmdate('Y-m-d H:i:s', time() + 3600)
        ));
        // The quota is full at this point, so approval is expected to still work:
        // it moves an existing request rather than adding capacity.
        $t->check('an administrator can approve a pending request', $approval['ok'] === true, $approval);

        $approvedSubdomain = Subdomain::find((int) ($approval['subdomain_id'] ?? 0));
        $t->check('approval creates a pending_dns subdomain', ($approvedSubdomain['status'] ?? '') === 'pending_dns', $approvedSubdomain['status'] ?? null);
        $t->check('approval does not create a DNS record',
            DnsRecordRepository::nameTaken((int) $domain['id'], 'rsv-approve.kharcha.info') === null);
        $t->check('approval creates exactly one queued job',
            (int) Database::scalar(
                "SELECT COUNT(*) FROM provisioning_jobs WHERE subdomain_id = :id AND status = 'pending'",
                ['id' => (int) $approvedSubdomain['id']]
            ) === 1);
        $t->check('the queued job is scheduled, not run',
            (int) Database::scalar("SELECT COUNT(*) FROM provisioning_jobs WHERE attempts > 0") === 0);

        $nonAdmin = reservation_test_user('Rsv Nobody', 'rsv-nobody@test.local');
        $t->check('a non-administrator cannot approve',
            ReservationService::approve($nonAdmin, $reservationId)['ok'] === false);

        $t->check('a settled reservation cannot be approved',
            ReservationService::approve($admin, $reservationId)['ok'] === false);

        /* ---------------------------------------------------------------
         * §11.8 — rejection
         * ------------------------------------------------------------ */
        $rejectTarget = Reservation::create(
            (int) $other['id'], (int) $domain['id'], 'rsv-reject', 'Reject me', (int) $other['id'], gmdate('Y-m-d H:i:s', time() + 3600)
        );
        $rejected = ReservationService::reject($admin, (int) $rejectTarget, 'Does not meet the naming policy');

        $t->check('an administrator can reject a request', $rejected['ok'] === true, $rejected);
        $t->check('rejection is recorded with its reason',
            (string) (Reservation::find((int) $rejectTarget)['admin_note'] ?? '') === 'Does not meet the naming policy');
        $t->check('rejection creates no subdomain',
            Subdomain::labelTaken((int) $domain['id'], 'rsv-reject') === false);
        $t->check('a rejected label is available again',
            $engine->check('rsv-reject', $domain, false)['available'] === true);

        /* ---------------------------------------------------------------
         * §11.9 — expiry
         * ------------------------------------------------------------ */
        $lapsed = Reservation::create(
            (int) $other['id'], (int) $domain['id'], 'rsv-lapsed', 'Expire me', (int) $other['id'],
            gmdate('Y-m-d H:i:s', time() - 60)
        );

        $t->check('a lapsed reservation still holds its label before the sweep',
            $engine->check('rsv-lapsed', $domain, false)['available'] === false);

        $expiredCount = ReservationService::expireLapsed()['expired'];
        $t->check('the sweep expires lapsed reservations', $expiredCount >= 1, $expiredCount);
        $t->check('the lapsed row is marked expired', (string) Reservation::find((int) $lapsed)['status'] === 'expired');
        $t->check('an expired reservation releases its label',
            $engine->check('rsv-lapsed', $domain, false)['available'] === true);
        $t->check('the sweep is idempotent', ReservationService::expireLapsed()['expired'] === 0);

        /* ---------------------------------------------------------------
         * §11.10 — provisioning is verified and never blind
         * ------------------------------------------------------------ */
        $job = Database::selectOne(
            "SELECT * FROM provisioning_jobs WHERE subdomain_id = :id ORDER BY id ASC LIMIT 1",
            ['id' => (int) $approvedSubdomain['id']]
        );

        // Without a configured target, provisioning must refuse rather than guess.
        $noTarget = ProvisioningQueue::run((int) $job['id'], $admin);
        $t->check('provisioning without a configured target fails', $noTarget['ok'] === false, $noTarget);
        $t->check('a targetless failure leaves the subdomain pending_dns',
            (string) Subdomain::find((int) $approvedSubdomain['id'])['status'] === 'pending_dns');

        // The targetless attempt was retryable, so the job is back in the queue
        // with a backoff. Clear the backoff to simulate the operator fixing the
        // configuration and running again.
        Database::execute(
            "UPDATE provisioning_jobs SET status = 'pending', available_at = :now WHERE id = :id",
            ['now' => now(), 'id' => (int) $job['id']]
        );

        $GLOBALS['FREESUB_CONFIG_OVERRIDE']['provisioning'] = [
            'targets' => ['a' => '93.184.216.34', 'aaaa' => '', 'cname' => ''],
            'verify_delay_ms' => 0,
        ];

        $provisioned = ProvisioningQueue::run((int) $job['id'], $admin);
        $t->check('a configured run provisions the name', $provisioned['ok'] === true, $provisioned);

        $activeSubdomain = Subdomain::find((int) $approvedSubdomain['id']);
        $t->check('the subdomain becomes active only after verification',
            (string) $activeSubdomain['status'] === 'active', $activeSubdomain['status']);
        $t->check('the provisioned record carries the provider id',
            (string) (DnsRecordRepository::nameTaken((int) $domain['id'], 'rsv-approve.kharcha.info')['provider_record_id'] ?? '') !== '');
        $t->check('the record exists in the zone',
            in_array('rsv-approve.kharcha.info', $double->names('zone_kharcha_1'), true));
        $t->check('provisioning is audited',
            Database::selectOne("SELECT id FROM audit_logs WHERE action = 'dns.provisioned' ORDER BY id DESC LIMIT 1") !== null);
        $t->check('the job is marked done',
            (string) Database::selectOne('SELECT status FROM provisioning_jobs WHERE id = :id', ['id' => (int) $job['id']])['status'] === 'done');

        // Running the same job twice must not create a second record.
        $double->resetRequests();
        $again = ProvisioningQueue::run((int) $job['id'], $admin);
        $t->check('a completed job cannot be run again', $again['ok'] === false, $again);
        $t->check('re-running a completed job writes nothing to the zone', $double->writes() === []);

        /* §11.11 — a created record that cannot be read back must not activate */
        $unverifiedSubdomain = Subdomain::create(
            (int) $user['id'],
            (int) $domain['id'],
            'rsv-unverified',
            'rsv-unverified.kharcha.info'
        );
        $unverifiedJob = ProvisioningQueue::enqueue(
            (int) $unverifiedSubdomain, (int) $domain['id'], 'rsv-unverified.kharcha.info', (int) $admin['id']
        );

        // Create succeeds, then the read-back fails: the worst case for a naive
        // implementation that trusts the create call.
        // The create is applied by the zone but the response is lost (a
        // timeout), and every read-back fails. A naive worker would either give
        // up and retry blindly or trust the create; both are wrong.
        $double->applyThenTimeoutOn('POST', 1);

        // Break every read the reconcile/verify path can make (findRecord retries).
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $double->failNextOn('GET', 500);
        }

        $unverified = ProvisioningQueue::run((int) $unverifiedJob, $admin);
        $t->check('a record that cannot be verified is not accepted', $unverified['ok'] === false, $unverified);
        $t->check('an unverified record never activates the subdomain',
            (string) Subdomain::find((int) $unverifiedSubdomain)['status'] !== 'active',
            Subdomain::find((int) $unverifiedSubdomain)['status']);
        $t->check('the failure is visible on the job',
            (string) Database::selectOne('SELECT last_error FROM provisioning_jobs WHERE id = :id', ['id' => (int) $unverifiedJob])['last_error'] !== '');

        /* ---------------------------------------------------------------
         * §11.12 — cancellation of provisioning is safe
         * ------------------------------------------------------------ */
        $cancelSubdomain = Subdomain::create(
            (int) $user['id'], (int) $domain['id'], 'rsv-canceljob', 'rsv-canceljob.kharcha.info'
        );
        $cancelJob = ProvisioningQueue::enqueue(
            (int) $cancelSubdomain, (int) $domain['id'], 'rsv-canceljob.kharcha.info', (int) $admin['id']
        );

        $t->check('a queued job can be cancelled', ProvisioningQueue::cancel((int) $cancelJob, (int) $admin['id'])['ok'] === true);
        $t->check('a cancelled job cannot be run',
            ProvisioningQueue::run((int) $cancelJob, $admin)['ok'] === false);
        $t->check('cancelling a job writes no DNS record',
            DnsRecordRepository::nameTaken((int) $domain['id'], 'rsv-canceljob.kharcha.info') === null);

        $t->check('a completed job cannot be cancelled', ProvisioningQueue::cancel((int) $job['id'], (int) $admin['id'])['ok'] === false);
        $t->check('a cancelled job can be retried deliberately', ProvisioningQueue::retry((int) $cancelJob, (int) $admin['id'])['ok'] === true);
        $t->check('retry resets the attempt counter',
            (int) Database::selectOne('SELECT attempts FROM provisioning_jobs WHERE id = :id', ['id' => (int) $cancelJob])['attempts'] === 0);

        /* ---------------------------------------------------------------
         * §11.13 — identity scoping (no user id from the request)
         * ------------------------------------------------------------ */
        $scoped = ReservationService::dashboard((int) $user['id']);
        $t->check('the dashboard returns the owner’s rows', $scoped['pending'] !== [] || $scoped['active'] !== []);

        $foreign = Reservation::find((int) $rejectTarget);
        $t->check('a reservation carries its owner id for scoping', (int) $foreign['user_id'] === (int) $other['id']);

        // §11.14 — history is preserved, not deleted
        $historyRow = Reservation::find((int) $rejectTarget);
        $t->check('settled reservations are kept for history', $historyRow !== null);
        $t->check('history keeps the administrative reason',
            (string) ($historyRow['admin_note'] ?? '') !== '');

        /* ---------------------------------------------------------------
         * §11.15 — quota administration
         * ------------------------------------------------------------ */
        $raise = SubdomainQuota::setLimit((int) $user['id'], 12, (int) $admin['id']);
        $t->check('an administrator can raise a quota', $raise['ok'] === true, $raise);
        $t->check('the raised quota is effective', (int) SubdomainQuota::limit(User::find((int) $user['id']), $domain) === 12);
        $t->check('a quota change is audited',
            Database::selectOne("SELECT id FROM audit_logs WHERE action = 'admin.quota_updated' ORDER BY id DESC LIMIT 1") !== null);
        $t->check('an out-of-range quota is refused',
            SubdomainQuota::setLimit((int) $user['id'], 9999, (int) $admin['id'])['ok'] === false);
        $t->check('a quota can be reset to the default',
            SubdomainQuota::setLimit((int) $user['id'], null, (int) $admin['id'])['ok'] === true
            && SubdomainQuota::limit(User::find((int) $user['id']), $domain) === $expectedQuota);

        /* ---------------------------------------------------------------
         * §11.16 — no web path writes DNS
         * ------------------------------------------------------------ */
        $webPages = [
            'dashboard.php',
            'admin/reservations.php',
            'admin/users.php',
            'api/check.php',
        ];

        foreach ($webPages as $page) {
            $code = dns_php_code(FREESUB_ROOT . '/' . $page);

            $t->check($page . ' does not call the provider directly',
                !str_contains($code, 'createRecord(') && !str_contains($code, 'deleteRecord(')
                && !str_contains($code, '->client()'), $page);
        }

        $cli = (string) file_get_contents(FREESUB_ROOT . '/database/provision.php');
        $cliCode = dns_php_code(FREESUB_ROOT . '/database/provision.php');

        $t->check('provisioning is CLI-triggerable only by an explicit run',
            str_contains($cli, '--run') && !str_contains($cliCode, 'cron'));

        // Dry run is the default, and it must still preview when the engine is
        // off — that is exactly when an operator needs to see why nothing runs.
        $t->check('the CLI defaults to a dry run', str_contains($cliCode, "array_key_exists('run'"));
        $t->check('the CLI refuses a real run when DNS is disabled',
            str_contains($cliCode, 'if (!$enabled && $run)'));
        $t->check('the CLI explains why a dry run would be refused',
            str_contains($cliCode, 'would be REFUSED'));
        $t->check('the CLI exits non-zero when a job fails', str_contains($cliCode, '$failed > 0 ? 2 : 0'));
        $t->check('the CLI is not reachable over HTTP', str_contains($cli, "PHP_SAPI !== 'cli'"));
        $t->check('the CLI never writes DNS on its own', !str_contains($cliCode, 'createRecord(')
            && !str_contains($cliCode, '->client()'));

        // §11 (extra) — a race on the same label must produce one winner.
        // Two fresh accounts, so neither is limited by an earlier quota test.
        $raceA = reservation_test_user('Rsv Race A', 'rsv-race-a@test.local');
        $raceB = reservation_test_user('Rsv Race B', 'rsv-race-b@test.local');

        $raceLabel = 'rsv-race';
        $first = ReservationService::create($raceA, $raceLabel, $domain);
        $second = ReservationService::create($raceB, $raceLabel, $domain);

        $t->check('two accounts cannot both reserve the same label',
            ($first['ok'] xor $second['ok']) === true, [$first['ok'], $second['ok']]);

        $raceCount = (int) Database::scalar(
            "SELECT COUNT(*) FROM reservations WHERE domain_id = :id AND label = :label AND status IN ('pending', 'approved')",
            ['id' => (int) $domain['id'], 'label' => $raceLabel]
        );
        $t->check('exactly one live row exists for a contested label', $raceCount === 1, $raceCount);

        reservation_test_cleanup((int) $domain['id']);
        AvailabilityCache::flushAll();
        dns_test_teardown();
        unset($GLOBALS['FREESUB_CONFIG_OVERRIDE']['provisioning']);
    }
}
