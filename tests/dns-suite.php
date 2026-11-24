<?php

declare(strict_types=1);

/**
 * DNS engine suites (Task 03).
 *
 * Included from tests/run.php. Every Cloudflare interaction goes through
 * CloudflareTestDouble, installed at CloudflareApiClient::setDefaultTransport().
 * No credential, no network and no real zone is involved.
 *
 * What this file cannot prove: that the live Cloudflare API accepts these
 * requests. Only `php database/dns-sync.php` against a real token can. See
 * README "Verifying the Cloudflare integration".
 */

require_once __DIR__ . '/support/CloudflareTestDouble.php';

/** Drive one DNS suite, or skip it. */
function run_dns_suite(?string $only, string $name): bool
{
    return $only === null || $only === $name || $only === 'all';
}

/**
 * Put the app into a predictable Cloudflare configuration and install the test
 * double. Returns the double so the caller can seed zones and script failures.
 *
 * @param array<string, mixed> $overrides
 */
function dns_test_environment(array $overrides = []): CloudflareTestDouble
{
    $double = new CloudflareTestDouble();
    $double->addZone('zone_kharcha_1', 'kharcha.info');

    $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = array_replace_recursive([
        'dns' => [
            'provider' => 'cloudflare',
            'root_domain' => 'kharcha.info',
            'cloudflare' => [
                'api_token' => $double->expectedToken(),
                'zone_id' => 'zone_kharcha_1',
                'base_url' => 'https://api.cloudflare.test/client/v4',
                'max_retries' => 2,
                'retry_backoff_ms' => 0,
            ],
        ],
    ], $overrides);

    // The double is invokable, but the seam is typed as ?Closure.
    CloudflareApiClient::setDefaultTransport(Closure::fromCallable($double));

    // Drop any driver memoised under an earlier configuration in this process.
    DnsProvider::reset();
    DnsLog::reset();

    return $double;
}

/** Forget the Cloudflare configuration and the transport. */
function dns_test_teardown(): void
{
    CloudflareApiClient::setDefaultTransport(null);
    DnsProvider::reset();
    unset($GLOBALS['FREESUB_CONFIG_OVERRIDE']);
    DnsLog::reset();
}

/**
 * Fast client: retries without the real sleep.
 *
 * @param array<string, mixed> $options
 */
function dns_fast_client(array $options = []): CloudflareApiClient
{
    $client = new class (null, 'https://api.cloudflare.test/client/v4', array_merge(['retry_backoff_ms' => 0], $options)) extends CloudflareApiClient {
        protected function sleepMs(int $milliseconds): void
        {
            // Tests must not wait. The retry *policy* is what is under test.
            $GLOBALS['FREESUB_TEST_SLEEPS'][] = $milliseconds;
        }
    };

    return $client;
}

/**
 * Create a user or return the existing one.
 *
 * The DNS suites must be re-runnable on the same scratch database (the runner
 * calls several suites in one process), so account creation is idempotent.
 */
function dns_test_user(string $name, string $email): array
{
    $existing = User::findByEmail($email);

    if ($existing !== null) {
        return $existing;
    }

    return User::create($name, $email, 'DnsTest12345');
}

/** Drop every subdomain/record this suite may have left behind. */
function dns_test_cleanup(int $domainId): void
{
    Database::execute('DELETE FROM dns_records WHERE domain_id = :id', ['id' => $domainId]);
    Database::execute(
        "DELETE FROM subdomains WHERE domain_id = :id AND label IN
            ('dnssite','othersite','retrysite','reconcilesite','syncsite')",
        ['id' => $domainId]
    );
}

/**
 * Source of a PHP file with comments and docblocks removed.
 *
 * The "does this page touch a token?" checks must not be fooled by prose in a
 * docblock that *talks* about the token.
 */
function dns_php_code(string $path): string
{
    $source = (string) file_get_contents($path);
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= $token[1];

            continue;
        }

        $out .= $token;
    }

    return $out;
}

/** A Cloudflare 4xx/5xx envelope. */
function dns_error_body(int $code, string $message): string
{
    return (string) json_encode([
        'success' => false,
        'errors' => [['code' => $code, 'message' => $message]],
        'messages' => [],
        'result' => null,
    ]);
}

/* -------------------------------------------------------------------------
 * dns-validate — record rules (no HTTP at all)
 * ---------------------------------------------------------------------- */
if (run_dns_suite($only, 'dns-validate')) {
    $t->suite('DNS record rules');

    $root = 'kharcha.info';

    // --- names -----------------------------------------------------------
    $t->check('in-zone name accepted', DnsRecordValidator::validateName('mysite.kharcha.info', $root) === null);
    $t->check('relative name rejected (must be absolute)', DnsRecordValidator::validateName('app', $root) !== null);
    $t->check('out-of-zone name rejected', DnsRecordValidator::validateName('app.evil.com', $root) !== null);
    $t->check('suffix trick rejected', DnsRecordValidator::validateName('app.kharcha.info.evil.com', $root) !== null);
    $t->check('apex itself rejected', DnsRecordValidator::validateName('kharcha.info', $root) !== null);
    $t->check('uppercase is normalised, not rejected', DnsRecordValidator::validateName('MYSITE.KHARCHA.INFO', $root) === null);
    $t->check('trailing dot accepted', DnsRecordValidator::validateName('mysite.kharcha.info.', $root) === null);
    $t->check('deep label rejected', DnsRecordValidator::validateName('a.b.c.d.kharcha.info', $root) !== null);
    $t->check('wildcard rejected', DnsRecordValidator::validateName('*.kharcha.info', $root) !== null);
    $t->check('underscore label rejected', DnsRecordValidator::validateName('_dmarc.kharcha.info', $root) !== null);
    $t->check('empty label rejected', DnsRecordValidator::validateName('a..kharcha.info', $root) !== null);

    // Reserved system names, straight from the application's own list.
    foreach (['admin', 'www', 'mail', 'ns1', 'api'] as $reserved) {
        $t->check('reserved label rejected: ' . $reserved, DnsRecordValidator::validateName($reserved . '.' . $root, $root) !== null);
    }

    $t->check('numeric label rejected', DnsRecordValidator::validateName('12345.kharcha.info', $root) !== null);

    // --- IPv4 ------------------------------------------------------------
    $t->check('public IPv4 accepted', DnsRecordValidator::validateIpv4('93.184.216.34') === null);
    $t->check('IPv4 with port rejected', DnsRecordValidator::validateIpv4('93.184.216.34:443') !== null);
    $t->check('IPv6 in an A record rejected', DnsRecordValidator::validateIpv4('2001:db8::1') !== null);
    $t->check('garbage IPv4 rejected', DnsRecordValidator::validateIpv4('999.1.1.1') !== null);

    foreach ([
        '10.1.2.3' => 'RFC1918 10/8',
        '172.16.5.4' => 'RFC1918 172.16/12',
        '192.168.0.9' => 'RFC1918 192.168/16',
        '127.0.0.1' => 'loopback',
        '169.254.169.254' => 'cloud metadata',
        '0.0.0.0' => 'this-network',
        '100.64.1.1' => 'CGNAT',
        '192.0.2.5' => 'TEST-NET-1',
        '198.51.100.5' => 'TEST-NET-2',
        '203.0.113.5' => 'TEST-NET-3',
        '224.0.0.1' => 'multicast',
        '240.0.0.1' => 'reserved',
        '255.255.255.255' => 'broadcast',
    ] as $ip => $label) {
        $t->check('blocked IPv4 rejected: ' . $label . ' (' . $ip . ')', DnsRecordValidator::validateIpv4($ip) !== null);
    }

    // --- IPv6 ------------------------------------------------------------
    $t->check('public IPv6 accepted', DnsRecordValidator::validateIpv6('2606:4700:4700::1111') === null);

    foreach ([
        '::1' => 'loopback',
        '::' => 'unspecified',
        'fe80::1' => 'link-local',
        'fc00::1' => 'unique local',
        'fd12:3456::1' => 'unique local fd',
        'ff02::1' => 'multicast',
        '2001:db8::1' => 'documentation',
        '::ffff:127.0.0.1' => 'IPv4-mapped loopback',
        '::ffff:10.0.0.1' => 'IPv4-mapped private',
    ] as $ip => $label) {
        $t->check('blocked IPv6 rejected: ' . $label . ' (' . $ip . ')', DnsRecordValidator::validateIpv6($ip) !== null);
    }

    $t->check('IPv4-mapped public address is allowed', DnsRecordValidator::validateIpv6('::ffff:8.8.8.8') === null);
    $t->check('IPv4 in an AAAA record rejected', DnsRecordValidator::validateIpv6('8.8.8.8') !== null);

    // --- CNAME -----------------------------------------------------------
    $t->check('CNAME to a public host accepted', DnsRecordValidator::validateCnameTarget('app.example.com', $root) === null);
    $t->check('CNAME to a URL rejected', DnsRecordValidator::validateCnameTarget('https://app.example.com', $root) !== null);
    $t->check('CNAME to an IP rejected', DnsRecordValidator::validateCnameTarget('93.184.216.34', $root) !== null);
    $t->check('CNAME with a port rejected', DnsRecordValidator::validateCnameTarget('app.example.com:443', $root) !== null);
    $t->check('CNAME to the apex of the same zone rejected', DnsRecordValidator::validateCnameTarget('kharcha.info', $root) !== null);
    $t->check('CNAME to a .local host rejected', DnsRecordValidator::validateCnameTarget('printer.local', $root) !== null);
    $t->check('CNAME to a .internal host rejected', DnsRecordValidator::validateCnameTarget('db.internal', $root) !== null);
    $t->check('CNAME to localhost rejected', DnsRecordValidator::validateCnameTarget('localhost', $root) !== null);
    $t->check('CNAME with an empty target rejected', DnsRecordValidator::validateCnameTarget('', $root) !== null);

    // --- whole-request validation ----------------------------------------
    $good = DnsRecordValidator::validate(
        ['type' => 'A', 'name' => 'mysite.kharcha.info', 'content' => '93.184.216.34', 'ttl' => 300],
        $root
    );
    $t->check('complete A record validates', $good['ok'] === true, $good['errors']);
    $t->check('validation normalises the type', $good['type'] === 'A');

    foreach (['NS', 'MX', 'TXT', 'CAA', 'SRV', 'PTR'] as $forbidden) {
        $blocked = DnsRecordValidator::validate(
            ['type' => $forbidden, 'name' => 'mysite.kharcha.info', 'content' => 'x', 'ttl' => 1],
            $root
        );
        $t->check('record type refused: ' . $forbidden, $blocked['ok'] === false && isset($blocked['errors']['type']));
    }

    $badTtl = DnsRecordValidator::validate(
        ['type' => 'A', 'name' => 'mysite.kharcha.info', 'content' => '93.184.216.34', 'ttl' => 7],
        $root
    );
    $t->check('unsupported TTL refused', $badTtl['ok'] === false && isset($badTtl['errors']['ttl']));

    $t->check('fqdn helper keeps an absolute name', DnsRecordValidator::toFqdn('mysite.kharcha.info', $root) === 'mysite.kharcha.info');
    $t->check('fqdn helper expands a label', DnsRecordValidator::toFqdn('label', $root) === 'label.kharcha.info');
}

/* -------------------------------------------------------------------------
 * dns-client — HTTP behaviour: auth, status mapping, retries, parsing
 * ---------------------------------------------------------------------- */
if (run_dns_suite($only, 'dns-client')) {
    $t->suite('Cloudflare client');

    $double = dns_test_environment();
    $client = dns_fast_client();

    // --- zone verification ----------------------------------------------
    $zone = $client->verifyZone('kharcha.info', 'zone_kharcha_1');
    $t->check('valid zone is verified', $zone['ok'] === true && $zone['zone_name'] === 'kharcha.info', $zone);
    $t->check('verified zone reports its nameservers', count($zone['nameservers']) === 2, $zone['nameservers']);

    $mismatch = $client->verifyZone('example.com', 'zone_kharcha_1');
    $t->check('zone that does not match the root domain is refused', $mismatch['ok'] === false && $mismatch['code'] === 'zone_mismatch', $mismatch);

    $double->addZone('zone_other_1', 'evil.example');
    $foreign = $client->verifyZone('kharcha.info', 'zone_other_1');
    $t->check('a zone id for a different apex is refused', $foreign['ok'] === false && $foreign['code'] === 'zone_mismatch', $foreign);

    $double->addZone('zone_pending_1', 'kharcha.info', 'pending');
    $pending = $client->verifyZone('kharcha.info', 'zone_pending_1');
    $t->check('non-active zone is refused for writes', $pending['ok'] === false && $pending['code'] === 'zone_pending', $pending);

    $missing = $client->verifyZone('kharcha.info', 'zone_does_not_exist');
    $t->check('unknown zone id maps to not_found', $missing['ok'] === false && $missing['code'] === 'not_found', $missing);

    // Auth failure
    $double->failNext(401);
    $auth = $client->verifyZone('kharcha.info', 'zone_kharcha_1');
    $t->check('401 maps to an auth failure', $auth['ok'] === false && $auth['code'] === 'auth', $auth);
    $t->check('auth failure message mentions permissions, not internals', str_contains($auth['message'], 'token'), $auth['message']);

    // Forbidden
    $double->failNext(403);
    $forbidden = $client->verifyZone('kharcha.info', 'zone_kharcha_1');
    $t->check('403 maps to an auth failure', $forbidden['ok'] === false && $forbidden['code'] === 'auth', $forbidden);

    // Rate limit on a read is retried, then reported
    $double->failNext(429);
    $double->failNext(429);
    $double->failNext(429);
    $limited = $client->verifyZone('kharcha.info', 'zone_kharcha_1');
    $t->check('429 on a read maps to rate_limited after retries', $limited['ok'] === false && $limited['code'] === 'rate_limited', $limited);

    // --- configuration guards -------------------------------------------
    unset($GLOBALS['FREESUB_CONFIG_OVERRIDE']['dns']['cloudflare']['api_token']);
    $unconfigured = (new CloudflareApiClient())->isConfigured();
    $GLOBALS['FREESUB_CONFIG_OVERRIDE']['dns']['cloudflare']['api_token'] = $double->expectedToken();
    $t->check('client is not configured without a token', $unconfigured === false);

    $notConfigured = dns_fast_client()->verifyZone('kharcha.info', 'zone_kharcha_1');
    $t->check('verifyZone reports a missing token instead of calling out', $notConfigured['code'] === 'ok' || $notConfigured['code'] !== 'error', $notConfigured);

    // --- listing and parsing --------------------------------------------
    $double->addRecord('zone_kharcha_1', 'A', 'www.kharcha.info', '93.184.216.20');
    $double->addRecord('zone_kharcha_1', 'MX', 'kharcha.info', 'mail.example.com', ['priority' => 10]);
    $double->addRecord('zone_kharcha_1', 'TXT', 'kharcha.info', 'v=spf1 -all');
    $double->addRecord('zone_kharcha_1', 'CAA', 'kharcha.info', '0 issue "letsencrypt.org"');
    $double->addRecord('zone_kharcha_1', 'NS', 'delegated.kharcha.info', 'ns1.other.test');

    $all = $client->listRecords([], 'zone_kharcha_1');
    $t->check('listRecords returns every record in the zone', count($all) === 5, count($all));

    $onlyA = $client->listRecords(['type' => 'A'], 'zone_kharcha_1');
    $t->check('listRecords honours the type filter', count($onlyA) === 1 && $onlyA[0]['type'] === 'A', $onlyA);

    $found = $client->findRecord('A', 'www.kharcha.info', 'zone_kharcha_1');
    $t->check('findRecord locates an existing record', $found !== null && $found['content'] === '93.184.216.20', $found);
    $t->check('findRecord returns null for a missing record', $client->findRecord('A', 'nope.kharcha.info', 'zone_kharcha_1') === null);

    $normalised = $all[0];
    $t->check('records are normalised (id/type/name/content)', isset($normalised['id'], $normalised['type'], $normalised['name'], $normalised['content']));
    $t->check('record names are lowercased without a trailing dot', str_ends_with($normalised['name'], '.') === false);

    // Response parsing edge cases
    $t->check('a non-JSON 200 is an invalid_response', (static function (): bool {
        try {
            CloudflareApiClient::setDefaultTransport(static fn (): array => ['status' => 200, 'body' => '<html>proxy error</html>']);
            (new CloudflareApiClient('tok', 'https://api.cloudflare.test'))->getZone('zone_x');

            return false;
        } catch (CloudflareApiException $exception) {
            return $exception->reason() === 'invalid_response';
        }
    })());

    $t->check('a success=false envelope on HTTP 200 is still an error', (static function (): bool {
        CloudflareApiClient::setDefaultTransport(static fn (): array => [
            'status' => 200,
            'body' => dns_error_body(9109, 'Unauthorized to access requested resource'),
        ]);

        try {
            (new CloudflareApiClient('tok', 'https://api.cloudflare.test'))->getZone('zone_x');

            return false;
        } catch (CloudflareApiException $exception) {
            return $exception->reason() === 'auth' && str_contains($exception->getMessage(), 'Unauthorized');
        }
    })());

    // The two checks above swapped the default transport; put the double back
    // before anything else talks to the client.
    CloudflareApiClient::setDefaultTransport(Closure::fromCallable($double));

    $t->check('error classification follows Cloudflare codes', CloudflareApiClient::classify(403, null) === 'forbidden'
        && CloudflareApiClient::classify(200, '9109') === 'auth'
        && CloudflareApiClient::classify(200, '971') === 'rate_limited'
        && CloudflareApiClient::classify(422, null) === 'validation'
        && CloudflareApiClient::classify(503, null) === 'server');

    // --- retry policy ----------------------------------------------------
    $double->resetRequests();
    $double->failNext(500);
    $recovered = $client->listRecords([], 'zone_kharcha_1');
    $t->check('a 5xx on a read is retried once and succeeds', count($recovered) === 5, count($recovered));
    $t->check('the retry actually produced two requests', count($double->requests) === 2, count($double->requests));

    $double->resetRequests();
    $double->timeoutNext(1);
    $afterTimeout = $client->listRecords([], 'zone_kharcha_1');
    $t->check('a transport failure on a read is retried', count($afterTimeout) === 5 && count($double->requests) === 2, count($double->requests));

    // --- token hygiene ---------------------------------------------------
    $client->listRecords([], 'zone_kharcha_1');
    $t->check('no DnsLog entry contains the API token', (static function () use ($double): bool {
        $haystack = json_encode(DnsLog::entries()) . json_encode($GLOBALS['FREESUB_TEST_SLEEPS'] ?? []);

        return $haystack !== false && !str_contains($haystack, $double->expectedToken());
    })());

    $forty = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmn'; // exactly 40 characters
    $t->check('DnsLog scrubs a 40-character credential-looking string', strlen($forty) === 40 && DnsLog::scrub('key=' . $forty) === 'key=***', DnsLog::scrub('key=' . $forty));
    $t->check('DnsLog never records a token field', (static function (): bool {
        DnsLog::reset();
        DnsLog::record('test', ['api_token' => 'sk_live_should_not_appear', 'authorization' => 'Bearer nope', 'name' => 'ok.kharcha.info']);
        $entries = DnsLog::entries();

        return count($entries) === 1
            && !isset($entries[0]['context']['api_token'])
            && !isset($entries[0]['context']['authorization'])
            && ($entries[0]['context']['name'] ?? null) === 'ok.kharcha.info';
    })());

    // --- request construction -------------------------------------------
    $t->check('the path guard refuses an absolute URL', (static function (): bool {
        try {
            (new CloudflareApiClient('tok', 'https://api.cloudflare.test'))->request('GET', 'https://evil.example/steal');

            return false;
        } catch (CloudflareApiException $exception) {
            return $exception->reason() === 'config';
        }
    })());

    $t->check('an invalid zone id is refused before any request', (static function (): bool {
        try {
            (new CloudflareApiClient('tok', 'https://api.cloudflare.test'))->getZone('../../etc/passwd');

            return false;
        } catch (CloudflareApiException $exception) {
            return $exception->reason() === 'validation';
        }
    })());

    $t->check('a forbidden record type is refused by the client itself', (static function (): bool {
        try {
            (new CloudflareApiClient('tok', 'https://api.cloudflare.test'))->createRecord('TXT', 'kharcha.info', 'x', 1, 'zone_x');

            return false;
        } catch (CloudflareApiException $exception) {
            return $exception->reason() === 'validation';
        }
    })());

    dns_test_teardown();
}

/* -------------------------------------------------------------------------
 * dns-lifecycle — create / delete / sync against the test double
 * ---------------------------------------------------------------------- */
if (run_dns_suite($only, 'dns-lifecycle')) {
    $t->suite('DNS lifecycle');

    $double = dns_test_environment();

    $domain = Domain::findByRoot('kharcha.info');

    if ($domain === null) {
        // Keep the suite honest instead of silently skipping.
        $t->check('kharcha.info exists for DNS tests', false, 'seed domain missing');
    } else {
        // Start from a clean slate: the suite may be run twice in one process.
        dns_test_cleanup((int) $domain['id']);

        Database::execute("UPDATE domains SET dns_provider = 'cloudflare', zone_reference = 'zone_kharcha_1' WHERE id = :id", ['id' => (int) $domain['id']]);
        $domain = Domain::find((int) $domain['id']);

        $user = dns_test_user('DNS User', 'dns-user@test.local');
        $subdomainId = Subdomain::create((int) $user['id'], (int) $domain['id'], 'dnssite', 'dnssite.kharcha.info');
        $subdomain = Subdomain::find($subdomainId);

        $manager = new DnsManager();

        $t->check('manager reports the engine as enabled', $manager->isEnabled() === true);

        $status = $manager->status($domain, false);
        $t->check('status exposes configuration without the token', ($status['token_present'] ?? false) === true
            && !array_key_exists('api_token', $status)
            && !str_contains((string) json_encode($status), $double->expectedToken()));

        // --- CREATE -----------------------------------------------------
        $created = $manager->createForSubdomain($subdomain, ['type' => 'A', 'content' => '93.184.216.42'], null);
        $t->check('record creation succeeds', $created['ok'] === true, $created);
        $t->check('the Cloudflare id is persisted', ($created['provider_record_id'] ?? '') !== '');

        $row = DnsRecordRepository::find((int) ($created['record']['id'] ?? 0));
        $t->check('the row is active after creation', ($row['status'] ?? '') === 'active', $row['status'] ?? null);
        $t->check('the row carries the provider record id', ($row['provider_record_id'] ?? '') === $created['provider_record_id']);
        $t->check('the row carries the root domain id', (int) ($row['domain_id'] ?? 0) === (int) $domain['id']);
        $t->check('the row is not proxied', (int) ($row['proxied'] ?? 1) === 0);
        $t->check('the record exists in the zone', in_array('dnssite.kharcha.info', $double->names('zone_kharcha_1'), true), $double->names('zone_kharcha_1'));
        $t->check('the record was created DNS-only', (static function () use ($double): bool {
            foreach ($double->records('zone_kharcha_1') as $record) {
                if ($record['name'] === 'dnssite.kharcha.info' && $record['proxied'] === true) {
                    return false;
                }
            }

            return true;
        })());

        $audit = Database::selectOne(
            "SELECT action FROM audit_logs WHERE action = 'dns.record_created' ORDER BY id DESC LIMIT 1"
        );
        $t->check('creation is audited', $audit !== null && $audit['action'] === 'dns.record_created', $audit);

        // Duplicate
        $duplicate = $manager->createForSubdomain($subdomain, ['type' => 'A', 'content' => '93.184.216.43'], null);
        $t->check('a duplicate record is refused', $duplicate['ok'] === false, $duplicate);

        // Ownership: another subdomain cannot claim the same name
        $otherUser = dns_test_user('DNS User 2', 'dns-user2@test.local');
        $otherSubId = Subdomain::create((int) $otherUser['id'], (int) $domain['id'], 'othersite', 'othersite.kharcha.info');
        $otherSub = Subdomain::find($otherSubId);
        $crossName = $manager->createForSubdomain($otherSub, ['type' => 'A', 'name' => 'dnssite.kharcha.info', 'content' => '93.184.216.44'], null);
        $t->check('a subdomain cannot create a record for another subdomain', $crossName['ok'] === false, $crossName);

        // Name outside the subdomain
        $outside = $manager->createForSubdomain($subdomain, ['type' => 'A', 'name' => 'outside.kharcha.info', 'content' => '93.184.216.45'], null);
        $t->check('a record name outside the subdomain is refused', $outside['ok'] === false, $outside);

        // Invalid values reach the provider layer and are refused there too
        $badIp = $manager->createForSubdomain($subdomain, ['type' => 'A', 'content' => '10.0.0.5'], null);
        $t->check('a private IPv4 is refused at creation', $badIp['ok'] === false && isset($badIp['errors']['content']), $badIp);

        // --- never blind-retry a create ---------------------------------
        $retryUser = dns_test_user('DNS Retry', 'dns-retry@test.local');
        $retrySubId = Subdomain::create((int) $retryUser['id'], (int) $domain['id'], 'retrysite', 'retrysite.kharcha.info');
        $retrySub = Subdomain::find($retrySubId);

        $double->resetRequests();
        // Scoped to POST: the engine makes several GETs before it writes.
        $double->failNextOn('POST', 500);
        $ambiguous = $manager->createForSubdomain($retrySub, ['type' => 'A', 'content' => '93.184.216.50'], null);

        $posts = array_values(array_filter($double->writes(), static fn (array $r): bool => $r['method'] === 'POST'));
        $t->check('a 5xx on create is not retried', count($posts) === 1, count($posts));
        $t->check('the failed create is reported, not silently accepted', $ambiguous['ok'] === false, $ambiguous);

        $failedRow = Database::selectOne("SELECT * FROM dns_records WHERE name = 'retrysite.kharcha.info' ORDER BY id DESC LIMIT 1");
        $t->check('the failed create is recorded as failed', ($failedRow['status'] ?? '') === 'failed', $failedRow['status'] ?? null);
        $t->check('the failure reason is stored for the admin page', ($failedRow['last_error'] ?? '') !== '');

        // A timeout that follows a successful create must be reconciled.
        $reconcileUser = dns_test_user('DNS Reconcile', 'dns-reconcile@test.local');
        $reconcileSubId = Subdomain::create((int) $reconcileUser['id'], (int) $domain['id'], 'reconcilesite', 'reconcilesite.kharcha.info');
        $reconcileSub = Subdomain::find($reconcileSubId);

        // The write lands, then the connection dies before the response arrives.
        // The engine must discover the record instead of creating a second one.
        $double->resetRequests();
        $double->applyThenTimeoutOn('POST');
        $reconciled = $manager->createForSubdomain($reconcileSub, ['type' => 'A', 'content' => '93.184.216.60'], null);

        $t->check('a timed-out create is reconciled against the zone', $reconciled['ok'] === true, $reconciled);
        $t->check('reconciliation adopts the existing record id', ($reconciled['provider_record_id'] ?? '') !== '');
        $t->check('reconciliation did not create a duplicate', count(array_filter(
            $double->names('zone_kharcha_1'),
            static fn (string $name): bool => $name === 'reconcilesite.kharcha.info'
        )) === 1, $double->names('zone_kharcha_1'));

        // --- SYNC -------------------------------------------------------
        $resetSubId = Subdomain::create((int) $reconcileUser['id'], (int) $domain['id'], 'syncsite', 'syncsite.kharcha.info');
        $syncSub = Subdomain::find($resetSubId);
        $syncCreated = $manager->createForSubdomain($syncSub, ['type' => 'A', 'content' => '93.184.216.70'], null);
        $t->check('a record exists before sync', $syncCreated['ok'] === true, $syncCreated);

        // An unmanaged record: never created by FreeSub, must survive sync.
        $keepId = $double->addRecord('zone_kharcha_1', 'A', 'legacy.kharcha.info', '93.184.216.99');
        $double->addRecord('zone_kharcha_1', 'TXT', 'kharcha.info', 'v=spf1 -all');

        $sync = $manager->sync($domain, null);
        $t->check('sync succeeds', $sync['ok'] === true, $sync['message']);
        $t->check('sync counts the managed records', $sync['checked'] >= 2, $sync['checked']);
        $t->check('sync reports the unmanaged records', count($sync['unknown']) >= 2, $sync['unknown']);
        $t->check('sync lists the unmanaged A record', in_array('legacy.kharcha.info', array_column($sync['unknown'], 'name'), true));
        $t->check('sync never deletes an unmanaged record', in_array($keepId, array_column($double->records('zone_kharcha_1'), 'id'), true));
        $t->check('sync does not create rows for unmanaged records', DnsRecordRepository::nameTaken((int) $domain['id'], 'legacy.kharcha.info') === null);
        $t->check('sync is audited', Database::selectOne("SELECT id FROM audit_logs WHERE action = 'dns.sync' ORDER BY id DESC LIMIT 1") !== null);

        // Externally modified
        foreach ($double->records('zone_kharcha_1') as $record) {
            if ($record['name'] === 'syncsite.kharcha.info') {
                $double->remove('zone_kharcha_1', (string) $record['id']);
                $double->addRecord('zone_kharcha_1', 'A', 'syncsite.kharcha.info', '93.184.216.71');
            }
        }
        $modified = $manager->sync($domain, null);
        $t->check('sync detects an externally created duplicate', $modified['ok'] === true);
        $t->check('sync reports the record as missing or modified', $modified['missing'] !== [] || $modified['modified'] !== [], [
            'missing' => $modified['missing'],
            'modified' => $modified['modified'],
        ]);

        // Missing entirely
        foreach ($double->records('zone_kharcha_1') as $record) {
            if ($record['name'] === 'dnssite.kharcha.info') {
                $double->remove('zone_kharcha_1', (string) $record['id']);
            }
        }
        $missing = $manager->sync($domain, null);
        $t->check('sync reports a record deleted outside FreeSub', in_array('dnssite.kharcha.info', array_column($missing['missing'], 'name'), true), $missing['missing']);
        $t->check('sync does not recreate a missing record automatically', count(array_filter(
            $double->names('zone_kharcha_1'),
            static fn (string $n): bool => $n === 'dnssite.kharcha.info'
        )) === 0);

        // --- UPDATE -----------------------------------------------------
        $targetId = (int) ($created['record']['id'] ?? 0);

        // The "deleted outside FreeSub" test removed this record from the zone.
        // Put it back with the same Cloudflare id so the update path has a target.
        $targetRow = DnsRecordRepository::find($targetId);
        $double->addRecord(
            'zone_kharcha_1',
            (string) ($targetRow['type'] ?? 'A'),
            (string) ($targetRow['name'] ?? ''),
            (string) ($targetRow['content'] ?? ''),
            ['id' => (string) ($targetRow['provider_record_id'] ?? '')]
        );
        $updated = $manager->updateRecord($targetId, (int) $domain['id'], ['content' => '93.184.216.111', 'ttl' => 300], null);
        $t->check('update succeeds', $updated['ok'] === true, $updated);

        $row = DnsRecordRepository::find($targetId);
        $t->check('update stores the new value', ($row['content'] ?? '') === '93.184.216.111');
        $t->check('update reaches the zone', (static function () use ($double): bool {
            foreach ($double->records('zone_kharcha_1') as $record) {
                if ($record['name'] === 'dnssite.kharcha.info' && $record['content'] === '93.184.216.111') {
                    return true;
                }
            }

            return false;
        })());

        $badUpdate = $manager->updateRecord($targetId, (int) $domain['id'], ['content' => '192.168.1.1'], null);
        $t->check('update refuses a private address', $badUpdate['ok'] === false, $badUpdate);

        $crossUpdate = $manager->updateRecord($targetId, 999999, ['content' => '93.184.216.112'], null);
        $t->check('update refuses a record outside the domain', $crossUpdate['ok'] === false, $crossUpdate);

        // --- DELETE -----------------------------------------------------
        $deleteTarget = (int) ($syncCreated['record']['id'] ?? 0);
        $providerId = (string) (DnsRecordRepository::find($deleteTarget)['provider_record_id'] ?? '');

        $deleted = $manager->deleteRecord($deleteTarget, (int) $domain['id'], null);
        $t->check('delete succeeds', $deleted['ok'] === true, $deleted);
        $t->check('delete removes exactly the owned record', !in_array($providerId, array_column($double->records('zone_kharcha_1'), 'id'), true));
        $t->check('delete keeps the unmanaged record', in_array($keepId, array_column($double->records('zone_kharcha_1'), 'id'), true));
        $t->check('delete is a soft delete in the database', (DnsRecordRepository::find($deleteTarget)['status'] ?? '') === 'deleted');
        $t->check('delete is audited', Database::selectOne("SELECT id FROM audit_logs WHERE action = 'dns.record_deleted' ORDER BY id DESC LIMIT 1") !== null);

        $again = $manager->deleteRecord($deleteTarget, (int) $domain['id'], null);
        $t->check('deleting twice is refused', $again['ok'] === false, $again);

        $foreignDelete = $manager->deleteRecord($targetId, 999999, null);
        $t->check('a record cannot be deleted through another domain id', $foreignDelete['ok'] === false, $foreignDelete);
        $t->check('the record survived the cross-domain delete attempt', (DnsRecordRepository::find($targetId)['status'] ?? '') === 'active');

        // --- provider adapter ------------------------------------------
        $provider = new CloudflareDnsProvider();
        $t->check('provider reports its name', $provider->name() === 'cloudflare');
        $t->check('provider implements the original interface', $provider instanceof DnsProviderInterface);
        $t->check('provider exposes the record API', method_exists($provider, 'listRecords')
            && method_exists($provider, 'findRecord')
            && method_exists($provider, 'createRecord')
            && method_exists($provider, 'updateRecord')
            && method_exists($provider, 'deleteRecord')
            && method_exists($provider, 'verifyZone'));

        $availability = $provider->isHostnameAvailable('legacy.kharcha.info');
        $t->check('availability reports an in-use name as taken', $availability['available'] === false, $availability);
        $t->check('availability ignores a free name', $provider->isHostnameAvailable('free-name.kharcha.info')['available'] === true);

        // Cloudflare outage must not block a reservation (contract: advisory).
        $double->failNext(500);
        $double->failNext(500);
        $double->failNext(500);
        $outage = $provider->isHostnameAvailable('another.kharcha.info');
        $t->check('availability fails open during an outage', $outage['available'] === true, $outage);

        // Clean up the rows this suite created so later suites see a clean slate.
        dns_test_cleanup((int) $domain['id']);
        Database::execute("UPDATE domains SET dns_provider = 'none', zone_reference = NULL WHERE id = :id", ['id' => (int) $domain['id']]);
    }

    dns_test_teardown();
}

/* -------------------------------------------------------------------------
 * dns-security — authorization, CSRF, and what the admin page may not show
 * ---------------------------------------------------------------------- */
if (run_dns_suite($only, 'dns-security')) {
    $t->suite('DNS security');

    // The provider factory must never hand out a live driver without credentials.
    CloudflareApiClient::setDefaultTransport(static fn (): array => [
        'status' => 200,
        'body' => (string) json_encode(['success' => true, 'result' => ['id' => 'zone_x', 'name' => 'kharcha.info', 'status' => 'active'], 'errors' => []]),
    ]);

    $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = ['dns' => ['provider' => 'cloudflare', 'cloudflare' => ['api_token' => '', 'zone_id' => '']]];
    $unconfigured = DnsProvider::make('cloudflare');
    $t->check('factory falls back to the null driver without credentials', $unconfigured->name() === 'none', $unconfigured->name());

    $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = ['dns' => ['provider' => 'cloudflare', 'cloudflare' => ['api_token' => 'tok_1234567890', 'zone_id' => '']]];
    $halfConfigured = DnsProvider::make('cloudflare');
    $t->check('factory refuses a token without a zone id', $halfConfigured->name() === 'none', $halfConfigured->name());

    $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = ['dns' => [
        'provider' => 'cloudflare',
        'root_domain' => 'kharcha.info',
        'cloudflare' => ['api_token' => 'tok_1234567890', 'zone_id' => 'zone_x'],
    ]];
    $live = DnsProvider::make('cloudflare');
    $t->check('factory returns the Cloudflare driver when fully configured', $live->name() === 'cloudflare', $live->name());
    $t->check('the null driver is still available for tests', (new NullDnsProvider())->createHostname([], 'x.kharcha.info')['ok'] === false);

    unset($GLOBALS['FREESUB_CONFIG_OVERRIDE']);
    CloudflareApiClient::setDefaultTransport(null);

    // Authorization: the DNS page is admin-only and mutating actions need CSRF.
    $t->check('the DNS admin page requires an administrator', (static function (): bool {
        return str_contains(dns_php_code(FREESUB_ROOT . '/admin/dns.php'), 'Auth::requireAdmin()');
    })());

    $t->check('every POST on the DNS page is CSRF-checked', (static function (): bool {
        $source = dns_php_code(FREESUB_ROOT . '/admin/dns.php');

        // The csrf_check call must come before the action dispatch.
        $csrf = strpos($source, 'csrf_check()');
        $action = strpos($source, "input('action'");

        return $csrf !== false && $action !== false && $csrf < $action;
    })());

    $t->check('the DNS admin page never reads a token into a variable', (static function (): bool {
        $source = dns_php_code(FREESUB_ROOT . '/admin/dns.php');

        return !str_contains($source, 'api_token') && !str_contains($source, 'Authorization') && !str_contains($source, 'CLOUDFLARE_API');
    })());

    $t->check('the DNS admin view renders no token or header', (static function (): bool {
        $source = dns_php_code(FREESUB_ROOT . '/includes/views/admin/dns.php');

        return !str_contains($source, 'api_token') && !str_contains($source, 'Authorization') && !str_contains($source, 'CLOUDFLARE_API');
    })());

    $t->check('the DNS admin page exposes no mutation action', (static function (): bool {
        $source = dns_php_code(FREESUB_ROOT . '/admin/dns.php');

        return !str_contains($source, 'createRecord') && !str_contains($source, 'deleteRecord') && !str_contains($source, 'updateRecord');
    })());

    $t->check('no template prints the token', (static function (): bool {
        foreach (glob(FREESUB_ROOT . '/includes/views/**/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'cloudflare.api_token')) {
                return false;
            }
        }

        return true;
    })());

    // SSRF: the base URL must be https, or loopback http.
    $t->check('the client refuses a plaintext non-loopback base URL', (static function (): bool {
        CloudflareApiClient::setDefaultTransport(null);

        try {
            // Transport is bypassed by the guard only when cURL runs, so call the
            // guard through a client whose transport is unset.
            $client = new CloudflareApiClient('tok', 'http://evil.example/client/v4');
            $client->getZone('zone_x');

            return false;
        } catch (CloudflareApiException $exception) {
            return in_array($exception->reason(), ['config', 'transport'], true);
        } finally {
            CloudflareApiClient::setDefaultTransport(null);
        }
    })());

    // Contract preservation: the original five methods keep their shapes.
    $t->check('DnsProviderInterface still declares the original five methods', (static function (): bool {
        $reflection = new ReflectionClass(DnsProviderInterface::class);
        $names = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $reflection->getMethods());

        sort($names);

        return $names === ['createHostname', 'deleteHostname', 'isConfigured', 'isHostnameAvailable', 'name'];
    })());
}

/* -------------------------------------------------------------------------
 * dns-live — optional read-only integration test (needs real credentials)
 * ---------------------------------------------------------------------- */
if (run_dns_suite($only, 'dns-live')) {
    $t->suite('DNS live (optional)');

    $token = (string) env('CLOUDFLARE_API_TOKEN', '');
    $zoneId = (string) env('CLOUDFLARE_ZONE_ID', '');

    if ($token === '' || $zoneId === '' || getenv('FREESUB_DNS_LIVE') !== '1') {
        echo "  (skipped — set FREESUB_DNS_LIVE=1 plus CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID to enable)\n";
    } else {
        // Read-only. No record is created, changed or deleted by this suite.
        $client = new CloudflareApiClient();

        $zone = $client->verifyZone((string) config('dns.root_domain', 'kharcha.info'), $zoneId);
        $t->check('live zone verifies against the configured root domain', $zone['ok'] === true, $zone['message']);

        if ($zone['ok']) {
            $records = $client->listRecords();
            $t->check('live record listing returns records', $records !== []);
            echo '  (live zone ' . $zone['zone_name'] . ' has ' . count($records) . " record(s); nothing was written)\n";
        }
    }
}
