<?php

declare(strict_types=1);

/**
 * Task 05 dashboard suite.
 *
 * Unit-level assertions about the pieces the dashboard adds: the status map, the
 * release rules, the timeline builder, the quota view and the provisioning
 * lookups. Included by tests/run.php under the `dashboard` suite, and runnable
 * on its own through the same runner:
 *
 *   php tests/run.php dashboard
 *
 * These assertions deliberately avoid HTTP: they check the decisions, not the
 * markup (tests/dashboard-render.php and tests/http-dashboard.py cover that).
 */

if (!function_exists('dash_make_user')) {
    /**
     * Create a throwaway account in the test database.
     *
     * @return array<string, mixed>
     */
    function dash_make_user(string $suffix = ''): array
    {
        $email = 'dashboard-' . ($suffix !== '' ? $suffix : bin2hex(random_bytes(4))) . '@example.test';

        return User::create('Dashboard ' . ucfirst($suffix !== '' ? $suffix : 'user'), $email, 'Str0ngPassw0rd!');
    }
}

if (!function_exists('dash_make_subdomain')) {
    /**
     * Create a subdomain row for a user, bypassing the reservation flow so each
     * status can be examined directly.
     */
    function dash_make_subdomain(int $userId, int $domainId, string $label, string $status, ?string $target = null): int
    {
        $id = Subdomain::create($userId, $domainId, $label, $label . '.kharcha.info', null, $target);

        if ($status !== 'pending_dns') {
            Database::execute(
                'UPDATE subdomains SET status = :status, updated_at = :now WHERE id = :id',
                ['status' => $status, 'now' => now(), 'id' => $id]
            );
        }

        return $id;
    }
}

$t->suite('User dashboard');

/* ---------------------------------------------------------------------- */
/* Canonical status vocabulary                                            */
/* ---------------------------------------------------------------------- */

$t->check('the dashboard describes every subdomain status the schema allows', (static function (): bool {
    $ddl = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(FREESUB_ROOT . '/database/schema.sqlite.sql'));

    if (!preg_match('/CREATE TABLE IF NOT EXISTS subdomains\s*\((.*?)\n\);/s', $ddl, $table)) {
        return false;
    }

    if (!preg_match('/status[^,]*CHECK \(status IN \(([^)]*)\)\)/', $table[1], $check)) {
        return false;
    }

    preg_match_all("/'([a-z_]+)'/", $check[1], $matches);

    return $matches[1] === DashboardStatus::subdomainStates();
})());

$t->check('the dashboard describes every reservation status the schema allows', (static function (): bool {
    $ddl = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(FREESUB_ROOT . '/database/schema.sqlite.sql'));

    if (!preg_match('/CREATE TABLE IF NOT EXISTS reservations\s*\((.*?)\n\);/s', $ddl, $table)) {
        return false;
    }

    if (!preg_match('/status[^,]*CHECK \(status IN \(([^)]*)\)\)/', $table[1], $check)) {
        return false;
    }

    preg_match_all("/'([a-z_]+)'/", $check[1], $matches);

    return $matches[1] === DashboardStatus::reservationStates();
})());

$t->check('the dashboard invents no status outside the backend vocabulary', (static function (): bool {
    $known = array_merge(DashboardStatus::subdomainStates(), DashboardStatus::reservationStates(),
        ['pending', 'running', 'done', 'failed', 'cancelled', 'active', 'suspended', 'pending_dns', 'terminated']);

    $source = (string) file_get_contents(FREESUB_ROOT . '/includes/services/Dashboard/DashboardStatus.php');

    preg_match_all("/'([a-z_]+)' => \[/", $source, $matches);

    foreach ($matches[1] as $status) {
        if (!in_array($status, $known, true)) {
            return false;
        }
    }

    return true;
})());

$t->check('no status is described by colour alone', (static function (): bool {
    foreach (array_merge(DashboardStatus::subdomainStates(), DashboardStatus::reservationStates()) as $status) {
        $meta = DashboardStatus::subdomain($status) + DashboardStatus::reservation($status);

        if (trim((string) ($meta['label'] ?? '')) === '') {
            return false;
        }
    }

    return true;
})());

$t->check('an unknown status degrades to readable text', DashboardStatus::subdomain('something_new')['label'] === 'Something new');
$t->check('an unknown reservation status degrades too', DashboardStatus::reservation('x_y')['label'] === 'X y');

/* ---------------------------------------------------------------------- */
/* Release rules (§12)                                                    */
/* ---------------------------------------------------------------------- */

$t->check('releasing is allowed for a name that holds no live record',
    DashboardStatus::canReleaseFromDashboard('pending_dns')
    && DashboardStatus::canReleaseFromDashboard('failed')
    && DashboardStatus::canReleaseFromDashboard('suspended'));

$t->check('releasing an active name is refused', !DashboardStatus::canReleaseFromDashboard('active'));
$t->check('releasing an already released name is refused', !DashboardStatus::canReleaseFromDashboard('terminated'));
$t->check('an active name explains the support path',
    str_contains((string) DashboardStatus::releaseBlockedReason('active'), 'support'));
$t->check('a releasable name has no blocking reason', DashboardStatus::releaseBlockedReason('failed') === null);

/* ---------------------------------------------------------------------- */
/* Timeline (§6): only real events, only real timestamps                  */
/* ---------------------------------------------------------------------- */

$timelineUser = dash_make_user('timeline');
$domain = Domain::available()[0] ?? Domain::find(1);

$noEvents = DashboardStatus::timeline(
    ['status' => 'pending_dns', 'created_at' => now(), 'updated_at' => now(), 'provisioned_at' => null],
    null,
    [],
    null
);

$t->check('a subdomain with no recorded events has an empty timeline', $noEvents === []);

$withJob = DashboardStatus::timeline(
    ['status' => 'pending_dns', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00', 'provisioned_at' => null],
    null,
    [],
    ['status' => 'pending', 'attempts' => 0, 'created_at' => '2026-01-01 10:05:00', 'updated_at' => '2026-01-01 10:05:00']
);

$t->check('a queued job produces exactly one step', count($withJob) === 1, count($withJob));
$t->check('an unattempted job invents no attempt timestamp', (static function () use ($withJob): bool {
    foreach ($withJob as $step) {
        if ($step['key'] === 'attempted') {
            return false;
        }
    }

    return true;
})());

$withAttempt = DashboardStatus::timeline(
    ['status' => 'failed', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 11:00:00', 'provisioned_at' => null],
    null,
    [],
    ['status' => 'failed', 'attempts' => 3, 'created_at' => '2026-01-01 10:05:00', 'updated_at' => '2026-01-02 11:00:00']
);

$keys = array_column($withAttempt, 'key');
$t->check('a failed job records the attempt', in_array('attempted', $keys, true), $keys);
$t->check('every step in a failed timeline has a timestamp', (static function () use ($withAttempt): bool {
    foreach ($withAttempt as $step) {
        if ($step['at'] === null) {
            return false;
        }
    }

    return true;
})());
$t->check('the failure step is marked failed, not done',
    (static function () use ($withAttempt): bool {
        foreach ($withAttempt as $step) {
            if ($step['key'] === 'failed') {
                return $step['state'] === 'failed';
            }
        }

        return false;
    })());

$t->check('a verification step needs a real provider record and a sync time', (static function (): bool {
    $without = DashboardStatus::timeline(
        ['status' => 'pending_dns', 'created_at' => now(), 'updated_at' => now(), 'provisioned_at' => null],
        null,
        [['type' => 'A', 'name' => 'x.kharcha.info', 'content' => '1.2.3.4', 'status' => 'pending', 'last_synced_at' => null, 'has_provider_record' => 0]],
        null
    );

    $with = DashboardStatus::timeline(
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now(), 'provisioned_at' => now()],
        null,
        [['type' => 'A', 'name' => 'x.kharcha.info', 'content' => '1.2.3.4', 'status' => 'active', 'last_synced_at' => now(), 'has_provider_record' => 1]],
        null
    );

    return $without === [] && in_array('verified', array_column($with, 'key'), true);
})());

$t->check('the timeline never reads a provider identifier or an operator error', (static function (): bool {
    $source = (string) preg_replace('/^\s*(\*|\/\/).*$/m', '', (string) file_get_contents(FREESUB_ROOT . '/includes/services/Dashboard/DashboardStatus.php'));

    return !str_contains($source, 'provider_record_id') && !str_contains($source, 'last_error');
})());

$t->check('the failure copy is safe to show a user', (static function (): bool {
    $message = DashboardStatus::failureMessage();

    return str_contains($message, 'contact support')
        && !str_contains($message, 'Cloudflare')
        && !str_contains($message, '400')
        && !str_contains($message, 'token');
})());

/* ---------------------------------------------------------------------- */
/* Provisioning lookups carry no secrets (§20)                            */
/* ---------------------------------------------------------------------- */

$quotaUser = dash_make_user('provision');
$subdomainId = dash_make_subdomain((int) $quotaUser['id'], (int) $domain['id'], 'provcheck', 'failed');

Database::execute(
    "INSERT INTO provisioning_jobs (subdomain_id, domain_id, fqdn, status, attempts, last_error, created_at, updated_at)
     VALUES (:sid, :did, :fqdn, 'failed', 2, :error, :now, :now)",
    [
        'sid' => $subdomainId, 'did' => (int) $domain['id'], 'fqdn' => 'provcheck.kharcha.info',
        'error' => 'cloudflare 400 token=super-secret-value', 'now' => now(),
    ]
);

Database::execute(
    "INSERT INTO dns_records (subdomain_id, domain_id, type, name, content, ttl, provider_record_id, status, last_synced_at, created_at, updated_at)
     VALUES (:sid, :did, 'A', :name, '203.0.113.9', 1, 'cf_record_secret', 'active', :now, :now, :now)",
    ['sid' => $subdomainId, 'did' => (int) $domain['id'], 'name' => 'provcheck.kharcha.info', 'now' => now()]
);

$provisioning = Subdomain::provisioningFor($subdomainId);

$t->check('a provisioning lookup returns the newest job', (int) $provisioning['job']['attempts'] === 2);
$t->check('a provisioning lookup never returns the operator error', !array_key_exists('last_error', (array) $provisioning['job']));
$t->check('a provisioning lookup never returns the provider record id',
    !array_key_exists('provider_record_id', (array) $provisioning['records'][0]));
$t->check('a record lookup says whether a provider record exists, as a boolean',
    (int) $provisioning['records'][0]['has_provider_record'] === 1);
$t->check('the serialised lookup contains no secret material', (static function () use ($provisioning): bool {
    $blob = (string) json_encode($provisioning);

    return !str_contains($blob, 'super-secret-value') && !str_contains($blob, 'cf_record_secret');
})());

$many = Subdomain::provisioningForMany([$subdomainId]);
$t->check('the batched lookup returns the same shape', (int) ($many[$subdomainId]['attempts'] ?? 0) === 2);
$t->check('the batched lookup excludes the operator error', !array_key_exists('last_error', (array) ($many[$subdomainId] ?? [])));

/* ---------------------------------------------------------------------- */
/* Quota view (§4) — mirrors the rule the reservation service enforces    */
/* ---------------------------------------------------------------------- */

$quotaUser = dash_make_user('quota');
$domains = Domain::available();
$primary = $domains[0];

$empty = SubdomainQuota::overview($quotaUser, $domains);
$t->check('a new account reports zero usage', $empty['used'] === 0, $empty);
$t->check('a new account reports a positive limit', $empty['limit'] > 0, $empty);
$t->check('a new account reports its full remaining allowance', $empty['remaining'] === $empty['limit'], $empty);
$t->check('a new account names the extension it will use first',
    ($empty['primary']['root_domain'] ?? '') === (string) $primary['root_domain']);

$perDomain = SubdomainQuota::byDomain($quotaUser, $domains);
$t->check('per-domain rows exist for every open domain', count($perDomain) === count($domains), count($perDomain));
$t->check('per-domain totals match the account figure', (static function () use ($perDomain, $empty): bool {
    $used = 0;

    foreach ($perDomain as $row) {
        $used += $row['used'];
    }

    return $used === $empty['used'];
})());

// Usage must count what the service counts: live subdomains + live reservations.
dash_make_subdomain((int) $quotaUser['id'], (int) $primary['id'], 'quota-one', 'active');
$afterOne = SubdomainQuota::overview($quotaUser, $domains);
$t->check('an active subdomain raises usage by one', $afterOne['used'] === $empty['used'] + 1, $afterOne);
$t->check('remaining falls by one', $afterOne['remaining'] === $empty['remaining'] - 1, $afterOne);

$t->check('the view agrees with the rule the service enforces', (static function () use ($quotaUser, $domains): bool {
    $view = SubdomainQuota::overview($quotaUser, $domains);
    $domain = $domains[0];

    // Exactly the two numbers ReservationService::create() compares.
    return $view['used'] === SubdomainQuota::used((int) $quotaUser['id'])
        && $view['limit'] === SubdomainQuota::limit($quotaUser, $domain);
})());

// A released name must not consume quota, and must not appear as manageable.
$releasedId = dash_make_subdomain((int) $quotaUser['id'], (int) $primary['id'], 'quota-released', 'terminated');
$afterRelease = SubdomainQuota::overview($quotaUser, $domains);
$t->check('a released name does not consume quota', $afterRelease['used'] === $afterOne['used'], $afterRelease);
$t->check('a released name is absent from the managed list',
    !in_array('quota-released.kharcha.info', array_column(Subdomain::forUser((int) $quotaUser['id'], 100), 'fqdn'), true));
$t->check('a released name is available to the owner as history',
    in_array('quota-released.kharcha.info', array_column(Subdomain::forUser((int) $quotaUser['id'], 100, true), 'fqdn'), true));

// An administrator raising the limit must be reflected immediately.
SubdomainQuota::setLimit((int) $quotaUser['id'], 25, (int) ($adminUser['id'] ?? 1));
// The controller re-reads the account on every request, so the assertion must
// too — a stale array would hide a real bug.
$raised = SubdomainQuota::overview(User::find((int) $quotaUser['id']) ?? $quotaUser, $domains);
$t->check('a raised quota is reflected on the dashboard', $raised['limit'] === 25, $raised);

// A quota of zero (an operator can set one) must not divide by zero anywhere.
SubdomainQuota::setLimit((int) $quotaUser['id'], 0, (int) ($adminUser['id'] ?? 1));
$zero = SubdomainQuota::overview(User::find((int) $quotaUser['id']) ?? $quotaUser, $domains);
$t->check('a zero override falls back to the domain default instead of zero', $zero['limit'] > 0, $zero);
SubdomainQuota::setLimit((int) $quotaUser['id'], null, (int) ($adminUser['id'] ?? 1));

/* ---------------------------------------------------------------------- */
/* Ownership in SQL (§10)                                                 */
/* ---------------------------------------------------------------------- */

$alice = dash_make_user('alice');
$bob = dash_make_user('bob');

$aliceSub = dash_make_subdomain((int) $alice['id'], (int) $primary['id'], 'alice-name', 'active');
$bobSub = dash_make_subdomain((int) $bob['id'], (int) $primary['id'], 'bob-name', 'active');

$t->check('an owner can load their own subdomain', Subdomain::findForUser($aliceSub, (int) $alice['id']) !== null);
$t->check('an owner cannot load someone else\'s subdomain', Subdomain::findForUser($aliceSub, (int) $bob['id']) === null);
$t->check('a foreign lookup discloses nothing about the row', Subdomain::findForUser($aliceSub, (int) $bob['id']) === null);
$t->check('listing is scoped to the account',
    array_column(Subdomain::forUser((int) $bob['id'], 100), 'fqdn') === ['bob-name.kharcha.info'],
    array_column(Subdomain::forUser((int) $bob['id'], 100), 'fqdn'));
$t->check('reservation history is scoped to the account', (static function () use ($alice, $bob, $primary): bool {
    Reservation::create((int) $alice['id'], (int) $primary['id'], 'alice-res', null, (int) $alice['id'], ReservationService::defaultExpiry());
    Reservation::create((int) $bob['id'], (int) $primary['id'], 'bob-res', null, (int) $bob['id'], ReservationService::defaultExpiry());

    $aliceHistory = array_column(ReservationService::history((int) $alice['id'], 100), 'label');
    $bobHistory = array_column(ReservationService::history((int) $bob['id'], 100), 'label');

    return $aliceHistory === ['alice-res'] && $bobHistory === ['bob-res'];
})());

$t->check('deleting for another account removes nothing', Subdomain::deleteForUser($aliceSub, (int) $bob['id']) === false);
$t->check('the row survives the refused delete', Subdomain::findForUser($aliceSub, (int) $alice['id']) !== null);

/* ---------------------------------------------------------------------- */
/* CSRF + method discipline on the dashboard entry points (§11)           */
/* ---------------------------------------------------------------------- */

$pageChecks = [
    'dashboard.php' => ['is_post', 'csrf_check'],
    'dashboard/search.php' => ['is_post', 'csrf_check', 'rate_limit_hit'],
    'dashboard/reservations.php' => ['is_post', 'csrf_check'],
    'dashboard/settings.php' => ['is_post', 'csrf_check'],
];

foreach ($pageChecks as $page => $required) {
    $source = (string) file_get_contents(FREESUB_ROOT . '/' . $page);

    foreach ($required as $needle) {
        $t->check($page . ' calls ' . $needle . ' before mutating', str_contains($source, $needle));
    }

    $t->check($page . ' never mutates before the POST guard', (static function () use ($source): bool {
        // Comments name the services they explain; only code counts.
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        $source = (string) preg_replace('/^\s*\/\/.*$/m', '', $source);

        // Everything up to `if (is_post())` is the GET path. Nothing there may
        // write, and every write must be inside that guard.
        $guard = strpos($source, 'if (is_post())');

        if ($guard === false) {
            return true;
        }

        $before = substr($source, 0, $guard);
        // Reads are fine on a GET; only writes are not. database.php's own
        // `UPDATE` strings are not in a page, so no false positive escapes here.
        $writes = ['Database::execute(', 'deleteForUser(', 'Reservation::transition(',
            'ReservationService::create(', 'ProvisioningQueue::enqueue(', 'ProvisioningQueue::run(',
            'ProvisioningQueue::cancel', 'audit_log('];

        foreach ($writes as $write) {
            if (str_contains($before, $write)) {
                return false;
            }
        }

        return true;
    })());
}

$detail = (string) file_get_contents(FREESUB_ROOT . '/dashboard/subdomain.php');
$t->check('the detail page never writes anything', !str_contains($detail, 'Database::execute')
    && !str_contains($detail, 'deleteForUser') && !str_contains($detail, 'csrf_check'));
$t->check('validation is a whitelist, not a cast',
    str_contains($detail, 'ctype_digit') && str_contains($detail, 'findForUser'));

/* ---------------------------------------------------------------------- */
/* Secrets and internals never reach a dashboard view (§20)               */
/* ---------------------------------------------------------------------- */

$t->check('no dashboard view reads the Clerk identity column', (static function (): bool {
    foreach (glob(FREESUB_ROOT . '/includes/views/dashboard/*.php') ?: [] as $view) {
        $source = (string) file_get_contents($view);

        if (str_contains($source, 'clerk_user_id') || str_contains($source, 'clerk_session')) {
            return false;
        }
    }

    return true;
})());

$t->check('no dashboard view touches the operator error column', (static function (): bool {
    foreach (glob(FREESUB_ROOT . '/includes/views/dashboard/*.php') ?: [] as $view) {
        if (str_contains((string) file_get_contents($view), 'last_error')) {
            return false;
        }
    }

    return true;
})());

$t->check('no dashboard view offers a role or auth mutation', (static function (): bool {
    foreach (glob(FREESUB_ROOT . '/includes/views/dashboard/*.php') ?: [] as $view) {
        $source = (string) file_get_contents($view);

        if (preg_match('/name="(role|status|password|clerk[^"]*)"/', $source) === 1) {
            return false;
        }
    }

    return true;
})());

$t->check('no dashboard page calls the DNS provider directly', (static function (): bool {
    foreach (glob(FREESUB_ROOT . '/dashboard/*.php') ?: [] as $page) {
        $source = (string) file_get_contents($page);

        foreach (['createRecord(', 'deleteRecord(', '->client()', 'CloudflareApiClient'] as $needle) {
            if (str_contains($source, $needle)) {
                return false;
            }
        }
    }

    return true;
})());

$t->check('no dashboard page provisions anything', (static function (): bool {
    foreach (glob(FREESUB_ROOT . '/dashboard/*.php') ?: [] as $page) {
        if (str_contains((string) file_get_contents($page), 'ProvisioningQueue::run')) {
            return false;
        }
    }

    return true;
})());

/* ---------------------------------------------------------------------- */
/* Shell: one nav definition, every destination real (§3)                 */
/* ---------------------------------------------------------------------- */

$t->check('the shell links only to pages that exist', (static function (): bool {
    $layout = (string) file_get_contents(FREESUB_ROOT . '/includes/views/layouts/dashboard.php');

    preg_match_all("#\['key' => '[a-z_]+', 'label' => '[^']+', 'href' => '(/[^']+)'#", $layout, $matches);

    foreach ($matches[1] as $href) {
        $path = (string) strtok($href, '?');

        if (!is_file(FREESUB_ROOT . $path)) {
            return false;
        }
    }

    return count($matches[1]) >= 5;
})());

$t->check('the shell includes sign-out', str_contains(
    (string) file_get_contents(FREESUB_ROOT . '/includes/views/layouts/dashboard.php'),
    '/logout.php'
));

$t->check('the mobile panel is absent from the desktop rendering', (static function (): bool {
    $layout = (string) file_get_contents(FREESUB_ROOT . '/includes/views/layouts/dashboard.php');

    // Exactly one of the two navigations may be present at any width, so the
    // page never exposes the same link list twice to assistive tech.
    return preg_match('/id="dashNav"[^>]*class="[^"]*d-lg-none"/', $layout) === 1
        || preg_match('/class="[^"]*d-lg-none[^"]*"[^>]*id="dashNav"/', $layout) === 1;
})());

$t->check('the shell is not a second copy of the public navbar', !str_contains(
    (string) file_get_contents(FREESUB_ROOT . '/includes/views/layouts/dashboard.php'),
    "partial('partials/navbar'"
));

$t->check('the dashboard reuses the existing design tokens', (static function (): bool {
    $css = (string) file_get_contents(FREESUB_ROOT . '/assets/css/app.css');

    return str_contains($css, '.dash-shell') && str_contains($css, 'var(--fs-brand)') && str_contains($css, '.stat-card');
})());

$t->check('a released subdomain keeps a readable status label',
    DashboardStatus::subdomain('terminated')['label'] === 'Released');

/* ---------------------------------------------------------------------- */
/* The preview harness refuses to run outside a preview database          */
/* ---------------------------------------------------------------------- */

$t->check('the preview sign-in is guarded by environment and database', (static function (): bool {
    $source = (string) file_get_contents(FREESUB_ROOT . '/preview-login.php');

    return str_contains($source, "'local', 'testing'")
        && str_contains($source, 'dashboard-preview')
        && str_contains($source, 'http_response_code(404)');
})());

$t->check('the preview sign-in cannot be reached with a POST', !preg_match('/is_post\(\)/', (string) file_get_contents(FREESUB_ROOT . '/preview-login.php')));
$t->check('nothing in the app links to the preview sign-in', (static function (): bool {
    foreach (array_merge(glob(FREESUB_ROOT . '/includes/views/*.php') ?: [],
        glob(FREESUB_ROOT . '/includes/views/**/*.php') ?: []) as $view) {
        if (str_contains((string) file_get_contents($view), 'preview-login')) {
            return false;
        }
    }

    return true;
})());
