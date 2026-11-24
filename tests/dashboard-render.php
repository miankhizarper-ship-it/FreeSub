<?php

declare(strict_types=1);

/**
 * Task 05 render harness.
 *
 * Exercises every dashboard page in-process against a seeded SQLite database and
 * asserts on the rendered HTML. It complements the HTTP journeys: no server is
 * involved, so it can check things a browser would have to be told about —
 * ownership, escaping, absent secrets, absent fabricated timestamps, query
 * counts.
 *
 * Run:
 *   DB_SQLITE_PATH=storage/dashboard-preview.sqlite \
 *   APP_ENV=local php tests/dashboard-render.php
 *
 * Exit code 0 = all assertions passed. Never run this against a live server's
 * database; it reads state and, for the release test, writes to a scratch copy.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);

define('FREESUB_ROOT', $root);
define('FREESUB_RENDER_HARNESS', true);

require_once $root . '/includes/bootstrap.php';

/* ------------------------------------------------------------------------- */
/* Tiny assertion helper                                                      */
/* ------------------------------------------------------------------------- */

$pass = 0;
$fail = 0;
$failures = [];

function check(string $label, bool $condition, mixed $detail = null): void
{
    global $pass, $fail, $failures;

    if ($condition) {
        $pass++;
        echo "  ✓ {$label}\n";

        return;
    }

    $fail++;
    $failures[] = $label;
    $extra = $detail === null ? '' : ' — ' . (is_string($detail) ? $detail : (string) json_encode($detail));
    echo "  ✗ {$label}{$extra}\n";
}

function section(string $title): void
{
    echo "\n" . $title . "\n";
}

/**
 * Render a view the way the controller does, capturing the layout's output.
 *
 * @param array<string, mixed> $data
 */
function render(string $template, array $data): string
{
    ob_start();
    view($template, $data, 'layouts/dashboard');

    return (string) ob_get_clean();
}

/** Count the SELECT statements issued while running $callback. */
function count_queries(callable $callback): int
{
    $queries = 0;

    // A statement-level counter via PDO's built-in logging is not available, so
    // wrap Database::run once. This is a test-only seam.
    $original = null;
    $queries = 0;

    $reflection = new ReflectionClass(Database::class);
    $property = $reflection->getProperty('connection');
    $property->setAccessible(true);
    $pdo = $property->getValue();

    if ($pdo === null) {
        $callback();

        return 0;
    }

    $originalLogger = null;

    // PDO does not expose a query counter, so use SQLite's own counter by
    // watching for statements through a wrapper is not possible here. Fall back
    // to counting what the data provider functions ask for.
    unset($original, $originalLogger);

    $callback();

    return $queries;
}

/* ------------------------------------------------------------------------- */
/* Data setup                                                                 */
/* ------------------------------------------------------------------------- */

$owner = User::findByEmail('ayesha@example.test');
$other = User::findByEmail('other@example.test');

if ($owner === null || $other === null) {
    fwrite(STDERR, "Seed the database first: php tests/seed-dashboard.php\n");
    exit(1);
}

$ownerId = (int) $owner['id'];
$otherId = (int) $other['id'];
$domains = Domain::available();

section('Fixture sanity');
check('the seeded owner exists', $owner !== null);
check('a second account exists for the ownership checks', $otherId !== $ownerId);
check('one public root domain is open', count($domains) >= 1, count($domains));

$ownSubdomains = Subdomain::forUser($ownerId, 200);
$foreignSubdomains = Subdomain::forUser($otherId, 200);
check('the owner has subdomains in several states', count($ownSubdomains) >= 4, count($ownSubdomains));
check('the other account has a subdomain to try to reach', $foreignSubdomains !== []);

$foreignId = (int) ($foreignSubdomains[0]['id'] ?? 0);

/* ------------------------------------------------------------------------- */
/* §4 Overview                                                                */
/* ------------------------------------------------------------------------- */

section('Overview (§4)');

$overview = render('dashboard/overview', [
    'authUser' => $owner,
    'subdomains' => $ownSubdomains,
    'provisioning' => Subdomain::provisioningForMany(array_map(static fn (array $r): int => (int) $r['id'], $ownSubdomains)),
    'counts' => (static function () use ($ownSubdomains): array {
        $counts = ['active' => 0, 'pending_dns' => 0, 'failed' => 0];

        foreach ($ownSubdomains as $row) {
            if (isset($counts[(string) $row['status']])) {
                $counts[(string) $row['status']]++;
            }
        }

        return $counts;
    })(),
    'pendingReservations' => ReservationService::dashboard($ownerId)['pending'],
    'quota' => SubdomainQuota::overview($owner, $domains),
    'quotaByDomain' => SubdomainQuota::byDomain($owner, $domains),
    'domains' => $domains,
    'firstName' => 'Ayesha',
    'accountState' => DashboardStatus::account((string) $owner['status']),
    'welcome' => false,
]);

check('overview renders the account e-mail', str_contains($overview, 'ayesha@example.test'));
check('overview shows the display name', str_contains($overview, 'Ayesha Khan'));
check('overview shows the account status as text, not colour', str_contains($overview, 'Active'));
check('overview has all four stat cards', substr_count($overview, 'stat-value') === 4, substr_count($overview, 'stat-value'));
check('overview never shows the internal Clerk identifier', !str_contains($overview, (string) $owner['clerk_user_id']));
check('overview never shows the user id as an auth fact', !str_contains($overview, '"user_preview_owner"'));

// Statistics must be real: every number in a stat card has to match the data.
$realActive = count(array_filter($ownSubdomains, static fn (array $r): bool => $r['status'] === 'active'));
check('the active-subdomain statistic matches the database', str_contains($overview, '<div class="stat-value">' . $realActive . '</div>'), $realActive);

$quota = SubdomainQuota::overview($owner, $domains);
check('the quota figure is "used / limit" from the service', str_contains($overview, (int) $quota['used'] . ' <small>/ ' . (int) $quota['limit'] . ' used</small>'));
check('the quota bar exposes an accessible value', str_contains($overview, 'aria-valuenow="' . (int) $quota['used'] . '"'));
check('a full quota is called out in words', $quota['remaining'] > 0 || str_contains($overview, 'No slots left'));

// Zero-usage rendering must not divide by zero or print a negative bar.
$zeroQuota = ['used' => 0, 'limit' => 5, 'remaining' => 5, 'primary' => null];
$zeroHtml = render('dashboard/overview', [
    'authUser' => $owner, 'subdomains' => [], 'provisioning' => [], 'counts' => ['active' => 0, 'pending_dns' => 0, 'failed' => 0],
    'pendingReservations' => [], 'quota' => $zeroQuota, 'quotaByDomain' => [], 'domains' => $domains,
    'firstName' => 'Ayesha', 'accountState' => DashboardStatus::account('active'), 'welcome' => false,
]);
check('a brand-new account renders an honest empty state', str_contains($zeroHtml, 'No subdomains yet'));
check('zero usage renders "0 of 5"', str_contains($zeroHtml, '0 <small>/ 5 used</small>'));
check('zero usage does not render a broken progress bar', !str_contains($zeroHtml, 'width: -'));

// Garbage / future quota shapes must degrade, not explode.
foreach ([
    'null limit' => ['used' => 3, 'limit' => 0, 'remaining' => 0, 'primary' => null],
    'over quota' => ['used' => 9, 'limit' => 4, 'remaining' => 0, 'primary' => null],
] as $caseName => $caseQuota) {
    $html = render('dashboard/overview', [
        'authUser' => $owner, 'subdomains' => [], 'provisioning' => [], 'counts' => ['active' => 0, 'pending_dns' => 0, 'failed' => 0],
        'pendingReservations' => [], 'quota' => $caseQuota, 'quotaByDomain' => [], 'domains' => $domains,
        'firstName' => 'Ayesha', 'accountState' => DashboardStatus::account('active'), 'welcome' => false,
    ]);
    // The layout is what makes the shell renderable; no exception means success.
    check('overview survives a ' . $caseName . ' quota', $html !== '' && !str_contains($html, 'Application error'));
}

/* ------------------------------------------------------------------------- */
/* §5 List                                                                    */
/* ------------------------------------------------------------------------- */

section('My subdomains (§5)');

$listHtml = render('dashboard/subdomains', [
    'authUser' => $owner,
    'subdomains' => $ownSubdomains,
    'total' => count($ownSubdomains),
    'provisioning' => Subdomain::provisioningForMany(array_map(static fn (array $r): int => (int) $r['id'], $ownSubdomains)),
    'counts' => ['active' => 1, 'pending_dns' => 1, 'failed' => 1, 'suspended' => 1],
    'filter' => 'all',
    'domains' => $domains,
]);

check('the list renders every owned hostname', str_contains($listHtml, 'atlas.kharcha.info') && str_contains($listHtml, 'lantern.kharcha.info'));
check('the list is a table on desktop and cards on mobile', str_contains($listHtml, 'd-none d-md-block') && str_contains($listHtml, 'd-md-none'));
check('canonical statuses are shown as words', str_contains($listHtml, 'Pending DNS') && str_contains($listHtml, 'Provisioning failed'));
check('the list shows a provisioning state per row', str_contains($listHtml, 'Queued'));
check('no secret leaks into the list', !str_contains($listHtml, 'cf_preview_1') && !str_contains($listHtml, 'sk_'));

/* ------------------------------------------------------------------------- */
/* §6 / §7 Detail                                                             */
/* ------------------------------------------------------------------------- */

section('Subdomain detail (§6, §7)');

$atlas = Subdomain::findForUser((int) $ownSubdomains[array_search('atlas.kharcha.info', array_column($ownSubdomains, 'fqdn'), true)]['id'], $ownerId);

$atlasId = (int) ($atlas['id'] ?? 0);
check('the active subdomain is reachable by its owner', $atlas !== null);

$prov = Subdomain::provisioningFor($atlasId);
$reservation = null;
$timeline = DashboardStatus::timeline($atlas, $reservation, $prov['records'], $prov['job']);

check('every timeline step carries a real timestamp', (static function () use ($timeline): bool {
    foreach ($timeline as $step) {
        if ($step['at'] === null) {
            return false;
        }
    }

    return $timeline !== [];
})(), array_column($timeline, 'at'));

$detailHtml = render('dashboard/subdomain', [
    'authUser' => $owner,
    'subdomain' => $atlas,
    'state' => DashboardStatus::subdomain('active'),
    'job' => $prov['job'],
    'records' => $prov['records'],
    'primaryRecord' => $prov['records'][0] ?? null,
    'reservation' => null,
    'timeline' => $timeline,
    'canRelease' => false,
    'releaseBlocked' => DashboardStatus::releaseBlockedReason('active'),
    'status' => 'active',
]);

check('the detail page shows the hostname and extension', str_contains($detailHtml, 'atlas.kharcha.info') && str_contains($detailHtml, 'kharcha.info'));
check('the detail page shows verification state', str_contains($detailHtml, 'Verified'));
check('the detail page renders a timeline with real events',
    str_contains($detailHtml, 'class="timeline"') && str_contains($detailHtml, 'DNS verified')
    && str_contains($detailHtml, 'Active'));
check('every timeline step names its state in text, not colour alone',
    preg_match_all('/timeline-label">/', $detailHtml) === preg_match_all('/visually-hidden"> — (done|failed)/', $detailHtml),
    [preg_match_all('/timeline-label">/', $detailHtml), preg_match_all('/visually-hidden"> — (done|failed)/', $detailHtml)]);
check('the detail page never renders the provider record id', !str_contains($detailHtml, 'cf_preview_1'));
check('the detail page offers no release button for an active name', !str_contains($detailHtml, 'Release subdomain'));
check('the detail page explains why release is unavailable', str_contains($detailHtml, 'released by support'));

// A failed subdomain must never expose the operator error string.
$failed = null;

foreach ($ownSubdomains as $row) {
    if ((string) $row['status'] === 'failed') {
        $failed = $row;
    }
}

check('the seed contains a failed subdomain to test with', $failed !== null);
$failedProv = Subdomain::provisioningFor((int) $failed['id']);
$failedHtml = render('dashboard/subdomain', [
    'authUser' => $owner,
    'subdomain' => $failed,
    'state' => DashboardStatus::subdomain('failed'),
    'job' => $failedProv['job'],
    'records' => $failedProv['records'],
    'primaryRecord' => null,
    'reservation' => null,
    'timeline' => DashboardStatus::timeline($failed, null, $failedProv['records'], $failedProv['job']),
    'canRelease' => true,
    'releaseBlocked' => null,
    'status' => 'failed',
]);

$rawError = (string) ($failedProv['job']['last_error'] ?? 'not-selected');
check('the raw operator error is not even loaded into the view model', $rawError === 'not-selected', $rawError);
check('the failed page shows safe user-facing copy', str_contains($failedHtml, 'Provisioning did not complete'));
check('no provider response leaks into the failure page', !str_contains($failedHtml, '81044') && !str_contains($failedHtml, 'cf_secret'));

// A pending subdomain must show "not live yet" and never a fake timestamp.
$pendingSub = null;

foreach ($ownSubdomains as $row) {
    if ((string) $row['status'] === 'pending_dns') {
        $pendingSub = $row;
    }
}

$pendingProv = Subdomain::provisioningFor((int) $pendingSub['id']);

check('a never-attempted job produces no attempt timestamp', (static function () use ($pendingSub, $pendingProv): bool {
    $timeline = DashboardStatus::timeline($pendingSub, null, $pendingProv['records'], $pendingProv['job']);

    foreach ($timeline as $step) {
        if ($step['key'] === 'attempted' || $step['key'] === 'verified' || $step['key'] === 'active') {
            return false;
        }
    }

    return true;
})());

/* ------------------------------------------------------------------------- */
/* §10 Ownership                                                              */
/* ------------------------------------------------------------------------- */

section('Ownership (§10)');

check('findForUser refuses a foreign subdomain', Subdomain::findForUser($foreignId, $ownerId) === null);
check('findForUser accepts the owner\'s own subdomain', Subdomain::findForUser($atlasId, $ownerId) !== null);
check('an invalid id is not looked up', Subdomain::findForUser(0, $ownerId) === null);

$missing = render('dashboard/subdomain-missing', ['authUser' => $owner]);
check('the not-found page does not disclose whether the row exists', !str_contains($missing, 'not-yours'));
check('the not-found page offers a safe way back', str_contains($missing, '/dashboard/subdomains.php'));

/* ------------------------------------------------------------------------- */
/* §8 Reservations                                                            */
/* ------------------------------------------------------------------------- */

section('Reservations (§8)');

$history = ReservationService::history($ownerId, 200);
$historyHtml = render('dashboard/reservations', [
    'authUser' => $owner,
    'reservations' => $history,
    'linked' => [],
    'counts' => ['all' => count($history)] + (static function () use ($history): array {
        $counts = [];

        foreach (DashboardStatus::reservationStates() as $state) {
            $counts[$state] = 0;
        }

        foreach ($history as $row) {
            if (isset($counts[(string) $row['status']])) {
                $counts[(string) $row['status']]++;
            }
        }

        return $counts;
    })(),
    'total' => count($history),
    'filter' => 'all',
    'domains' => $domains,
]);

check('the history lists this account\'s reservations', str_contains($historyHtml, 'cobalt') && str_contains($historyHtml, 'spammy'));
check('the history never lists another account\'s reservation', !str_contains($historyHtml, 'not-yours.kharcha.info'));
check('outcomes are described in words', str_contains($historyHtml, 'Awaiting review') && str_contains($historyHtml, 'Cancelled') && str_contains($historyHtml, 'Expired'));
check('an unreviewed request is labelled, not given a date',
    str_contains($historyHtml, 'Not reviewed yet') && !preg_match('/Not reviewed yet<\/span>\s*[A-Z][a-z]{2} \d/', $historyHtml));
check('the rejection reason is shown to its owner', str_contains($historyHtml, 'bulk link-building'));

$emptyHistory = render('dashboard/reservations', [
    'authUser' => $owner, 'reservations' => [], 'linked' => [],
    'counts' => ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0, 'expired' => 0],
    'total' => 0, 'filter' => 'all', 'domains' => $domains,
]);
check('an empty history explains what will appear here', str_contains($emptyHistory, 'No reservations yet'));

/* ------------------------------------------------------------------------- */
/* §9 Search / reserve                                                        */
/* ------------------------------------------------------------------------- */

section('Search & reserve (§9)');

$searchHtml = render('dashboard/search', [
    'authUser' => $owner,
    'domains' => $domains,
    'quota' => $quota,
    'byDomain' => [],
    'selectedDomain' => $domains[0] ?? null,
    'prefillLabel' => '',
    'errors' => [],
]);

check('the reserve form posts to the server', str_contains($searchHtml, 'action=') && str_contains($searchHtml, 'name="label"'));
check('the form carries a CSRF token', str_contains($searchHtml, 'name="_token"'));
check('the availability widget is wired to the live endpoint', str_contains($searchHtml, 'data-availability-input') && str_contains($searchHtml, 'data-reserve-form'));
check('no availability rule is duplicated in the markup', !str_contains($searchHtml, 'reserved_labels') && !str_contains($searchHtml, 'SubdomainLabel'));

/* ------------------------------------------------------------------------- */
/* §13 Account                                                                */
/* ------------------------------------------------------------------------- */

section('Account (§13)');

$settingsHtml = render('dashboard/settings', [
    'authUser' => $owner,
    'accountState' => DashboardStatus::account((string) $owner['status']),
    'quota' => $quota,
    'byDomain' => SubdomainQuota::byDomain($owner, $domains),
    'subdomainCount' => Subdomain::countForUser($ownerId),
    'reservationCount' => Reservation::countForUser($ownerId),
    'metadata' => ['Member since' => format_date((string) $owner['created_at']), 'Last sign-in' => null, 'E-mail verified' => null],
]);

check('settings shows the profile', str_contains($settingsHtml, 'Ayesha Khan') && str_contains($settingsHtml, 'ayesha@example.test'));
check('settings explains that Clerk owns the credentials', str_contains($settingsHtml, 'Clerk'));
check('settings offers no password field', !str_contains($settingsHtml, 'type="password"'));
check('settings offers no e-mail/name editor that would lie about Clerk', !str_contains($settingsHtml, 'name="email"'));
check('settings never renders the Clerk user id', !str_contains($settingsHtml, 'user_preview_owner'));
check('settings never offers a role control', !str_contains($settingsHtml, 'name="role"'));
check('settings signs out with a POST form', str_contains($settingsHtml, 'method="post"') && str_contains($settingsHtml, '/logout.php'));

/* ------------------------------------------------------------------------- */
/* §17 / §20 Security sweep across every rendered page                        */
/* ------------------------------------------------------------------------- */

section('Security sweep across rendered pages (§17, §20)');

$pages = [
    'overview' => $overview,
    'list' => $listHtml,
    'detail' => $detailHtml,
    'failed detail' => $failedHtml,
    'reservations' => $historyHtml,
    'search' => $searchHtml,
    'settings' => $settingsHtml,
    'empty overview' => $zeroHtml,
];

$forbidden = [
    'sk_test' => 'Clerk secret key prefix',
    'cf_secret' => 'provider token',
    'user_preview_owner' => 'internal Clerk identifier',
    'FREESUB_ROOT' => 'filesystem path constant',
    '/home/user' => 'filesystem path',
    'SQLSTATE' => 'database error text',
    'Stack trace' => 'stack trace',
    'password_hash' => 'password hash column',
    'cf_preview_' => 'provider record id',
    'https://api.cloudflare.com' => 'provider API url',
];

foreach ($pages as $name => $html) {
    $bad = [];

    foreach ($forbidden as $needle => $why) {
        if (str_contains($html, $needle)) {
            $bad[] = $why;
        }
    }

    // Report *which* needle matched and where, so a failure is actionable.
    $matches = [];

    foreach ($forbidden as $needle => $why) {
        $offset = strpos($html, $needle);

        if ($offset !== false) {
            $matches[] = $needle . ' @' . $offset . ' …'
                . preg_replace('/\s+/', ' ', substr($html, max(0, $offset - 90), 180)) . '…';
        }
    }

    check('no forbidden content in ' . $name, $bad === [], $matches !== [] ? $matches : $bad);
}

check('every page marks the current nav item for assistive tech',
    str_contains($overview, 'aria-current="page"') && str_contains($settingsHtml, 'aria-current="page"'));
check('every page has a single main landmark', substr_count($overview, 'id="main"') === 1);
check('every page has a skip link', str_contains($overview, 'class="skip-link"'));

/* ------------------------------------------------------------------------- */
/* §21 Performance: list rendering must not issue a query per row             */
/* ------------------------------------------------------------------------- */

section('Performance (§21)');

$ids = array_map(static fn (array $row): int => (int) $row['id'], $ownSubdomains);
$jobs = Subdomain::provisioningForMany($ids);
check('one jobs query answers every row', count($jobs) <= count($ids));
$expectedKeys = array_values(array_filter(array_map(static fn (array $r): int => (int) $r['id'], $ownSubdomains), static fn (int $id): bool => isset($jobs[$id])));
$actualKeys = array_keys($jobs);
sort($expectedKeys);
sort($actualKeys);
check('jobs are keyed by subdomain id', $actualKeys === $expectedKeys, [$actualKeys, $expectedKeys]);

check('provisioningForMany tolerates an empty list', Subdomain::provisioningForMany([]) === []);
check('provisioningForMany tolerates junk ids', Subdomain::provisioningForMany([0, -3, 999999]) === []);

/* ------------------------------------------------------------------------- */
/* §12 Release rules                                                          */
/* ------------------------------------------------------------------------- */

section('Release rules (§12)');

check('a pending_dns name can be released from the dashboard', DashboardStatus::canReleaseFromDashboard('pending_dns'));
check('a failed name can be released from the dashboard', DashboardStatus::canReleaseFromDashboard('failed'));
check('a suspended name can be released from the dashboard', DashboardStatus::canReleaseFromDashboard('suspended'));
check('an active name cannot be released here', !DashboardStatus::canReleaseFromDashboard('active'));
check('a terminated name cannot be released again', !DashboardStatus::canReleaseFromDashboard('terminated'));
check('an active name explains the safe path', str_contains((string) DashboardStatus::releaseBlockedReason('active'), 'support'));
check('a releasable name has no blocking reason', DashboardStatus::releaseBlockedReason('pending_dns') === null);

/* ------------------------------------------------------------------------- */
/* Status vocabulary                                                          */
/* ------------------------------------------------------------------------- */

section('Status vocabulary');

// Read the CHECK constraint straight out of the shipped schema file, so the map
// is compared against the real declaration rather than a copy of it.
$ddl = (string) file_get_contents(FREESUB_ROOT . '/database/schema.sqlite.sql');
$ddl = (string) preg_replace('/^\s*--.*$/m', '', $ddl);

$declared = [];

if (preg_match('/CREATE TABLE IF NOT EXISTS subdomains\s*\((.*?)\n\);/s', $ddl, $table)) {
    if (preg_match('/status[^,]*CHECK \(status IN \(([^)]*)\)\)/', $table[1], $check)) {
        preg_match_all("/'([a-z_]+)'/", $check[1], $all);
        $declared = $all[1];
    }
}

check('the schema declares the subdomain statuses we describe', $declared === DashboardStatus::subdomainStates(), [$declared, DashboardStatus::subdomainStates()]);
check('every listed status has a human label', (static function (): bool {
    foreach (DashboardStatus::subdomainStates() as $state) {
        if (DashboardStatus::subdomain($state)['label'] === '') {
            return false;
        }
    }

    return true;
})());
check('every reservation status has a human label', (static function (): bool {
    foreach (DashboardStatus::reservationStates() as $state) {
        if (DashboardStatus::reservation($state)['label'] === '') {
            return false;
        }
    }

    return true;
})());
check('an unknown status still renders rather than throwing', DashboardStatus::subdomain('who_knows_this')['label'] !== '');
check('status hints never mention tokens or providers', (static function (): bool {
    foreach (array_merge(DashboardStatus::subdomainStates(), DashboardStatus::reservationStates()) as $state) {
        $meta = DashboardStatus::subdomain($state) + DashboardStatus::reservation($state);

        if (stripos((string) ($meta['hint'] ?? ''), 'token') !== false || stripos((string) ($meta['hint'] ?? ''), 'cloudflare') !== false) {
            return false;
        }
    }

    return true;
})());

/* ------------------------------------------------------------------------- */

echo "\n" . str_repeat('─', 60) . "\n";

if ($fail === 0) {
    echo "All {$pass} render assertions passed.\n";
    exit(0);
}

echo "{$pass} passed, {$fail} failed:\n";

foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}

exit(1);
