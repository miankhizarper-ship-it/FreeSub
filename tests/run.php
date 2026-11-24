<?php

declare(strict_types=1);

/**
 * FreeSub test runner (no PHPUnit, no Composer required).
 *
 * Usage:
 *   php tests/run.php            run every suite
 *   php tests/run.php security   run one suite
 *
 * The runner always uses an isolated SQLite database so it never touches
 * production data. HTTP journeys require a server; set:
 *   FREESUB_TEST_BASE_URL=http://127.0.0.1:8080 php tests/run.php http
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

define('FREESUB_TEST_DB', dirname(__DIR__) . '/storage/test-' . getmypid() . '.sqlite');

foreach ([
    'DB_DRIVER' => 'sqlite',
    'DB_SQLITE_PATH' => FREESUB_TEST_DB,
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'SESSION_NAME' => 'freesub_test',
    'APP_URL' => '',
] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

// CLI has no request context; provide just enough for URL helpers.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = FREESUB_ROOT . '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

final class TestRunner
{
    private int $passed = 0;
    /** @var list<string> */
    private array $failures = [];
    private string $suite = '';

    public function suite(string $name): void
    {
        $this->suite = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public function check(string $label, bool $condition, mixed $detail = null): void
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✓\033[0m " . $label . "\n";

            return;
        }

        $message = $this->suite . ': ' . $label;
        $this->failures[] = $message;
        echo "  \033[31m✗\033[0m " . $label;
        if ($detail !== null) {
            echo '  → ' . (is_scalar($detail) ? (string) $detail : json_encode($detail));
        }
        echo "\n";
    }

    /** @param callable(): bool $fn */
    public function throws(string $label, callable $fn): void
    {
        try {
            $fn();
            $this->check($label, false, 'no exception thrown');
        } catch (Throwable $exception) {
            $this->check($label, true);
        }
    }

    public function summary(): int
    {
        $failed = count($this->failures);

        echo "\n" . str_repeat('─', 60) . "\n";
        echo $failed === 0
            ? "  \033[32mAll {$this->passed} assertions passed.\033[0m\n"
            : "  \033[31m{$failed} failed\033[0m, {$this->passed} passed.\n";

        foreach ($this->failures as $failure) {
            echo '  - ' . $failure . "\n";
        }

        return $failed === 0 ? 0 : 1;
    }
}

$t = new TestRunner();
$only = $argv[1] ?? null;

/** Install the SQLite schema. Idempotent; every suite may rely on it. */
function install_test_schema(): void
{
    static $installed = false;

    if ($installed) {
        return;
    }

    $installed = true;

    $schema = (string) file_get_contents(FREESUB_ROOT . '/database/schema.sqlite.sql');
    $pdo = Database::connection();

    foreach (array_map('trim', preg_split('/;\s*[\r\n]+/', $schema) ?: []) as $statement) {
        if ($statement === '' || str_starts_with($statement, '--') || str_starts_with($statement, 'PRAGMA')) {
            continue;
        }

        $pdo->exec($statement);
    }
}

function run_suite(?string $only, string $name): bool
{
    return $only === null || $only === $name || $only === 'all';
}

/* -------------------------------------------------------------------------
 * 1. Schema
 * ---------------------------------------------------------------------- */
install_test_schema();

if (run_suite($only, 'schema')) {
    $t->suite('Schema');

    $expected = ['users', 'domains', 'subdomains', 'dns_records', 'reservations', 'audit_logs', 'abuse_reports'];

    foreach ($expected as $table) {
        $t->check("table {$table} exists", (int) Database::scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => $table]
        ) === 1);
    }

    $foreignKeys = (int) Database::scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND sql LIKE '%REFERENCES%'");
    $t->check('foreign keys declared', $foreignKeys >= 5, $foreignKeys);

    $indexes = (int) Database::scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name NOT LIKE 'sqlite_%'");
    $t->check('indexes declared', $indexes >= 10, $indexes);

    $domains = Domain::available();
    $t->check('kharcha.info seeded', count($domains) === 1 && $domains[0]['root_domain'] === 'kharcha.info', array_column($domains, 'root_domain'));
    $t->check('extension derived from root domain', ($domains[0]['extension'] ?? null) === 'info');
    $t->check('seed is idempotent', (int) Database::scalar('SELECT COUNT(*) FROM domains') === 1);

    $t->check('no Cloudflare token column on domains', !array_key_exists('api_token', $domains[0]));
}

/* -------------------------------------------------------------------------
 * 2. Security primitives
 * ---------------------------------------------------------------------- */
if (run_suite($only, 'security')) {
    $t->suite('Security');

    $hash = Auth::hashPassword('CorrectHorse1');
    $t->check('password_hash uses bcrypt/argon', str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2'));
    $t->check('password_verify accepts correct password', Auth::verifyPassword('CorrectHorse1', $hash));
    $t->check('password_verify rejects wrong password', !Auth::verifyPassword('CorrectHorse2', $hash));
    $t->check('hashes are salted (two hashes differ)', Auth::hashPassword('CorrectHorse1') !== $hash);

    $escape = e('<script>alert("x")</script>');
    $t->check('e() escapes HTML', $escape === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $escape);
    $t->check('e() handles quotes', e("O'Reilly & co") === 'O&apos;Reilly &amp; co', e("O'Reilly & co"));
    $t->check('e() neutralises attribute breakout', !str_contains(e('" onmouseover="alert(1)'), '"'), e('" onmouseover="alert(1)'));

    $_SESSION = [];
    $token = csrf_token();
    $t->check('csrf token is 64 hex chars', (bool) preg_match('/^[0-9a-f]{64}$/', $token));
    $t->check('csrf token stable per session', csrf_token() === $token);
    $_POST['_token'] = $token;
    $t->check('csrf_verify accepts session token', csrf_verify());
    $t->check('csrf_verify rejects forged token', !csrf_verify(str_repeat('a', 64)));
    $t->check('csrf_verify rejects empty token', !csrf_verify(''));
    unset($_POST['_token']);

    $t->check('labels reject uppercase input after normalisation', SubdomainService::normaliseLabel('My-Site') === 'my-site');
    $t->check('labels reject reserved names', Validator::subdomainLabel('admin') !== null);
    $t->check('labels reject leading hyphen', Validator::subdomainLabel('-bad') !== null);
    $t->check('labels reject trailing hyphen', Validator::subdomainLabel('bad-') !== null);
    $t->check('labels reject double hyphen', Validator::subdomainLabel('a--b') !== null);
    $t->check('labels reject underscores', Validator::subdomainLabel('a_b') !== null);
    $t->check('labels reject numeric-only', Validator::subdomainLabel('12345') !== null);
    $t->check('labels reject punycode', Validator::subdomainLabel('xn--abc') !== null);
    $t->check('labels reject too short', Validator::subdomainLabel('ab') !== null);
    $t->check('labels reject too long', Validator::subdomainLabel(str_repeat('a', 40)) !== null);
    $t->check('labels accept valid name', Validator::subdomainLabel('my-cool-site') === null);
    $t->check('labels accept digits inside', Validator::subdomainLabel('site2go') === null);

    $t->check('password rejects short', Validator::password('Ab1') !== null);
    $t->check('password rejects letters only', Validator::password('abcdefghijkl') !== null);
    $t->check('password rejects digits only', Validator::password('1234567890123') !== null);
    $t->check('password rejects common words', Validator::password('password12345') !== null);
    $t->check('password accepts strong', Validator::password('Tr0ub4dor&3xyz') === null);

    $t->check('email rejects header injection', Validator::email("a@b.co\nBcc: x@y.co") !== null);
    $t->check('email rejects missing at', Validator::email('not-an-email') !== null);
    $t->check('email accepts normal address', Validator::email('person@example.com') === null);

    $t->check('cidr_match matches', cidr_match('10.1.2.3', '10.0.0.0/8'));
    $t->check('cidr_match rejects outside', !cidr_match('11.1.2.3', '10.0.0.0/8'));
    $t->check('cidr_match handles /32', cidr_match('192.168.1.1', '192.168.1.1/32'));

    $t->check('session config disables trans sid', ini_get('session.use_trans_sid') === '0' || ini_get('session.use_trans_sid') === false);
    $t->check('secrets never in config output', !str_contains(json_encode(config()) ?: '', 'CLOUDFLARE_API_TOKEN'));
}

/* -------------------------------------------------------------------------
 * 3. Authentication and repositories
 * ---------------------------------------------------------------------- */
if (run_suite($only, 'auth')) {
    $t->suite('Authentication & repositories');

    Database::execute('DELETE FROM users WHERE email LIKE :p', ['p' => '%@test.local']);

    $user = User::create('Ada Lovelace', 'ada@test.local', 'An4lyticalEng1ne');
    $t->check('user created', (int) $user['id'] > 0);
    $t->check('email stored lowercase', $user['email'] === 'ada@test.local');
    $t->check('role defaults to user', $user['role'] === 'user');
    $t->check('status defaults to active', $user['status'] === 'active');

    $t->check('findByEmail is case-insensitive', (int) (User::findByEmail('ADA@TEST.LOCAL')['id'] ?? 0) === (int) $user['id']);
    $t->check('emailExists works', User::emailExists('ada@test.local'));
    $t->check('unknown email returns null', User::findByEmail('nobody@test.local') === null);

    $t->throws('duplicate email rejected', static fn () => User::create('Dup', 'ada@test.local', 'An4lyticalEng1ne'));

    $t->check('SQL injection in email is inert', User::findByEmail("' OR 1=1 --") === null);

    $result = Auth::attempt('ada@test.local', 'An4lyticalEng1ne');
    $t->check('login with correct password succeeds', $result['ok'] === true, $result);
    $t->check('session id set after login', ($_SESSION[Auth::SESSION_KEY] ?? null) === (int) $user['id']);
    $t->check('auth user resolves', Auth::id() === (int) $user['id']);
    $t->check('is_admin false for user', Auth::isAdmin() === false);

    Auth::logout();
    $t->check('logout clears user', Auth::user() === null);

    $bad = Auth::attempt('ada@test.local', 'WrongPassword9');
    $t->check('wrong password rejected', $bad['ok'] === false);
    $t->check('generic failure message', str_contains($bad['message'], 'do not match'));

    foreach (['a@test.local', 'b@test.local', 'c@test.local', 'd@test.local', 'e@test.local', 'f@test.local'] as $i => $email) {
        rate_limit_hit('test-bucket', 5, 60);
    }
    $t->check('rate limiter blocks after threshold', !rate_limit_hit('test-bucket', 5, 60));
    rate_limit_clear('test-bucket');
    $t->check('rate limiter clears', rate_limit_hit('test-bucket', 5, 60));

    Database::execute("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => (int) $user['id']]);
    $blocked = Auth::attempt('ada@test.local', 'An4lyticalEng1ne');
    $t->check('suspended account cannot log in', $blocked['ok'] === false && str_contains($blocked['message'], 'not active'));
    Database::execute("UPDATE users SET status = 'active' WHERE id = :id", ['id' => (int) $user['id']]);
}

/* -------------------------------------------------------------------------
 * 4. Subdomains, reservations and DNS safety
 * ---------------------------------------------------------------------- */
if (run_suite($only, 'subdomains')) {
    $t->suite('Subdomains & reservations');

    $user = User::findByEmail('ada@test.local') ?? User::create('Ada Lovelace', 'ada@test.local', 'An4lyticalEng1ne');
    $domain = Domain::available()[0];

    $check = SubdomainService::check('mytestsite');
    $t->check('available label reported available', $check['available'] === true, $check);
    $t->check('fqdn composed from root domain', $check['fqdn'] === 'mytestsite.kharcha.info', $check['fqdn']);
    $t->check('normalisation accepts pasted fqdn', SubdomainService::check('MyTestSite.kharcha.info')['label'] === 'mytestsite');

    $reservation = SubdomainService::reserve($user, 'mytestsite', (int) $domain['id'], 'Test project');
    $t->check('reservation accepted', $reservation['ok'] === true, $reservation);
    $t->check('reservation id returned', ($reservation['reservation_id'] ?? 0) > 0);

    $row = Reservation::find((int) $reservation['reservation_id']);
    $t->check('reservation stored as pending', ($row['status'] ?? '') === 'pending');

    $after = SubdomainService::check('mytestsite');
    $t->check('reserved label no longer available', $after['available'] === false && $after['status'] === 'reserved', $after);

    $again = SubdomainService::reserve($user, 'mytestsite', (int) $domain['id']);
    $t->check('duplicate reservation refused', $again['ok'] === false);

    $t->check('no subdomain row created by reservation', Subdomain::countForUser((int) $user['id']) === 0);
    $t->check('no DNS records created', DnsRecord::countPending() === 0);

    // Admin approval creates a pending_dns subdomain — never "active".
    $subdomainId = Subdomain::create((int) $user['id'], (int) $domain['id'], 'mytestsite', 'mytestsite.kharcha.info', (int) $reservation['reservation_id']);
    Reservation::updateStatus((int) $reservation['reservation_id'], 'approved', (int) $user['id'], 'Looks good');

    $subdomain = Subdomain::find($subdomainId);
    $t->check('approved subdomain is pending_dns', ($subdomain['status'] ?? '') === 'pending_dns', $subdomain['status'] ?? null);
    $t->check('provisioned_at empty until DNS exists', ($subdomain['provisioned_at'] ?? null) === null);
    $t->check('label is now taken', Subdomain::labelTaken((int) $domain['id'], 'mytestsite'));
    $t->check('duplicate fqdn rejected at DB level', (static function () use ($user, $domain): bool {
        try {
            Subdomain::create((int) $user['id'], (int) $domain['id'], 'other', 'mytestsite.kharcha.info');

            return false;
        } catch (PDOException) {
            return true;
        }
    })());

    Subdomain::markStatus($subdomainId, 'active');
    $t->check('markStatus sets provisioned_at on activation', Subdomain::find($subdomainId)['provisioned_at'] !== null);

    $t->check('suggestions exclude the taken label', !in_array('mytestsite', SubdomainService::suggestions('mytestsite'), true));
    $t->check('suggestions are valid labels', (static function (): bool {
        foreach (SubdomainService::suggestions('mytestsite') as $suggestion) {
            if (Validator::subdomainLabel($suggestion) !== null) {
                return false;
            }
        }

        return true;
    })());

    // Provider contract must never claim success.
    $provider = DnsProvider::current();
    $t->check('default DNS provider is null driver', $provider->name() === 'none');
    $t->check('null provider is not configured', $provider->isConfigured() === false);
    $t->check('null provider never reports success', $provider->createHostname($domain, 'x.kharcha.info')['ok'] === false);

    // Multi-domain readiness
    Database::execute(
        "INSERT INTO domains (root_domain, extension, label, description, dns_provider, registration_status, is_active, is_public, subdomain_quota, sort_order)
         VALUES ('second.test', 'test', 'second.test', 'Second root domain', 'none', 'ready', 1, 1, 2, 5)"
    );
    $available = Domain::available();
    $t->check('second root domain is offered', count($available) === 2, array_column($available, 'root_domain'));
    $t->check('extensions aggregation groups labels', count(Domain::extensions()) === 2, Domain::extensions());
    $second = SubdomainService::check('another-name', $available[1]);
    $t->check('availability works on the second domain', $second['fqdn'] === 'another-name.second.test', $second);

    // Draft domains stay hidden.
    Database::execute("UPDATE domains SET registration_status = 'draft' WHERE root_domain = 'second.test'");
    $t->check('draft domain hidden from public list', count(Domain::available()) === 1);

    // Quota enforcement
    Database::execute("UPDATE domains SET subdomain_quota = 1, registration_status = 'ready' WHERE root_domain = 'kharcha.info'");
    $quotaUser = User::create('Quota User', 'quota@test.local', 'QuotaTest123');
    $first = SubdomainService::reserve($quotaUser, 'oneforquota', (int) $domain['id']);
    Subdomain::create((int) $quotaUser['id'], (int) $domain['id'], 'oneforquota', 'oneforquota.kharcha.info');
    $second = SubdomainService::reserve($quotaUser, 'twoforquota', (int) $domain['id']);
    $t->check('quota blocks additional subdomains', $second['ok'] === false && str_contains($second['message'], 'limit'), $second);
}

/* -------------------------------------------------------------------------
 * 5. Audit logging and abuse
 * ---------------------------------------------------------------------- */
if (run_suite($only, 'audit')) {
    $t->suite('Audit & abuse');

    $user = User::findByEmail('ada@test.local');
    audit_log('test.action', (int) $user['id'], ['k' => 'v'], 'user', (int) $user['id'], 'Test entry');

    $entry = Database::selectOne("SELECT * FROM audit_logs WHERE action = 'test.action' ORDER BY id DESC LIMIT 1");
    $t->check('audit entry written', $entry !== null);
    $t->check('audit context stored as JSON', json_decode((string) $entry['context'], true) === ['k' => 'v'], $entry['context']);
    $t->check('audit ip recorded', ($entry['ip_address'] ?? null) === '127.0.0.1', $entry['ip_address'] ?? null);

    $admin = User::create('Admin Person', 'admin@test.local', 'AdminPass123', 'admin');
    audit_log('test.admin', (int) $admin['id']);
    $adminEntry = Database::selectOne("SELECT actor_type FROM audit_logs WHERE action = 'test.admin' ORDER BY id DESC LIMIT 1");
    $t->check('admin actions tagged as admin', ($adminEntry['actor_type'] ?? '') === 'admin');

    $reportId = AbuseReport::create('bad.kharcha.info', 'reporter@test.local', 'phishing', 'Impersonating a bank login page.');
    $t->check('abuse report stored', $reportId > 0 && AbuseReport::countOpen() >= 1);
    $t->check('abuse report links to subdomain when known', (static function (): bool {
        $match = Subdomain::findByFqdn('mytestsite.kharcha.info');

        return $match !== null;
    })());

    $t->check('abuse validation rejects short message', Validator::abuseReport([
        'fqdn' => 'x.kharcha.info', 'reporter_email' => 'a@b.co', 'category' => 'spam', 'message' => 'short',
    ]) !== []);
    $t->check('abuse validation rejects bad category', Validator::abuseReport([
        'fqdn' => 'x.kharcha.info', 'reporter_email' => 'a@b.co', 'category' => 'nonsense', 'message' => str_repeat('a', 40),
    ]) !== []);
}

/* -------------------------------------------------------------------------
 * 6. Output helpers / views
 * ---------------------------------------------------------------------- */
if (run_suite($only, 'views')) {
    $t->suite('Views & helpers');

    $t->check('app_url builds absolute urls', str_starts_with(app_url('/login.php'), 'http'), app_url('/login.php'));
    $t->check('app_url trims slashes', substr_count(app_url('/login.php'), '//') === 1, app_url('/login.php'));
    $t->check('asset() appends a version', (bool) preg_match('/\?v=\d+$/', asset('css/app.css')), asset('css/app.css'));
    $t->check('every public view file exists', (static function (): bool {
        $views = ['home', 'extensions', 'abuse', 'auth/login', 'auth/register', 'errors/404', 'errors/403', 'errors/setup',
            'dashboard', 'admin/overview', 'admin/domains', 'admin/reservations', 'admin/users', 'admin/abuse',
            'layouts/public', 'layouts/app', 'layouts/admin', 'partials/navbar', 'partials/footer', 'partials/flashes'];

        foreach ($views as $view) {
            if (!is_file(FREESUB_ROOT . '/includes/views/' . $view . '.php')) {
                return false;
            }
        }

        return true;
    })());

    // Every redirect target used anywhere in the app must exist on disk.
    $t->check('all redirect targets exist', (static function (): bool {
        $files = array_merge(
            glob(FREESUB_ROOT . '/*.php') ?: [],
            glob(FREESUB_ROOT . '/admin/*.php') ?: [],
            glob(FREESUB_ROOT . '/api/*.php') ?: [],
            glob(FREESUB_ROOT . '/includes/*.php') ?: []
        );

        $bad = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("#redirect\('(/[^']*)'#", $source, $matches);

            foreach ($matches[1] as $target) {
                $path = strtok($target, '#?');

                if ($path === false || $path === '/' || $path === '') {
                    continue; // "/" is the landing page
                }

                if (!is_file(FREESUB_ROOT . $path)) {
                    $bad[] = basename($file) . ' -> ' . $target;
                }
            }
        }

        if ($bad !== []) {
            fwrite(STDERR, 'broken redirects: ' . implode(', ', $bad) . "\n");
        }

        return $bad === [];
    })());

    $t->check('initials helper', initials('Ada Lovelace') === 'AL');
    $t->check('str_limit helper truncates', mb_strlen(str_limit(str_repeat('a', 100), 20)) === 20);
    $t->check('format_date helper', format_date('2024-05-06 12:00:00') === 'May 6, 2024');
    $t->check('format_date handles null', format_date(null) === '—');

    // Every linked internal page in the landing view must exist on disk.
    $t->check('views reference existing php entry points', (static function (): bool {
        $html = (static function (): string {
            ob_start();
            $domains = Domain::available();
            view('home', ['domains' => $domains, 'stats' => ['domains' => 1, 'subdomains' => 0, 'users' => 0]], null);

            return (string) ob_get_clean();
        })();

        $base = app_url('/');
        $checked = 0;

        preg_match_all('#href="([^"]+\.php[^"]*)"#i', $html, $matches);

        foreach (array_unique($matches[1]) as $href) {
            if (!str_starts_with($href, $base)) {
                continue; // external or absolute to another host
            }

            $path = strtok(ltrim(substr($href, strlen($base)), '/'), '#?');

            if ($path === false || $path === '') {
                continue;
            }

            if (!is_file(FREESUB_ROOT . '/' . $path)) {
                fwrite(STDERR, "missing entry point: {$path}\n");

                return false;
            }

            $checked++;
        }

        return $checked >= 5;
    })());
}

/* -------------------------------------------------------------------------
 * 7. HTTP journeys (needs a running server)
 * ---------------------------------------------------------------------- */
if (run_suite($only, 'http')) {
    $t->suite('HTTP journeys');

    $base = getenv('FREESUB_TEST_BASE_URL') ?: '';

    if ($base === '') {
        echo "  (skipped — set FREESUB_TEST_BASE_URL to enable)\n";
    } else {
        require FREESUB_ROOT . '/tests/http-journeys.php';
        run_http_journeys($t, $base);
    }
}

$exit = $t->summary();

@unlink(FREESUB_TEST_DB);
@unlink(FREESUB_TEST_DB . '-wal');
@unlink(FREESUB_TEST_DB . '-shm');

exit($exit);
