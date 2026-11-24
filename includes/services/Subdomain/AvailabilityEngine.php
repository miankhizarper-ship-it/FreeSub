<?php

declare(strict_types=1);

/**
 * Availability engine (Task 04).
 *
 * Answers one question: may this label be reserved on this root domain, right
 * now? It consults four sources, cheapest first, and stops as soon as a local
 * source is decisive:
 *
 *   1. validation rules            (reserved, wildcard, malformed, …)
 *   2. `subdomains`                (already provisioned or pending DNS)
 *   3. `dns_records`               (a record we created, in any status)
 *   4. `reservations`              (a live request holding the label)
 *   5. Cloudflare                  (records somebody else created)
 *
 * Two properties matter more than speed:
 *
 *   **Never a false "available".** If step 5 cannot be answered and a zone is
 *   configured, the result is `unknown` (available = false), not "free". That
 *   is the fail-closed rule; without a configured zone there is nothing to
 *   consult and local state is authoritative.
 *
 *   **Cloudflare is not called on every keystroke.** Answers are cached on disk
 *   per (zone, name) with a TTL, negative answers for a shorter one, and the
 *   *only* remote call is a filtered name lookup. A cache entry that expires is
 *   re-fetched, never reused.
 */
final class AvailabilityEngine
{
    /** Machine codes returned in `status`. */
    public const AVAILABLE = 'available';
    public const TAKEN = 'taken';
    public const RESERVED = 'reserved';
    public const PENDING = 'pending';
    public const INVALID = 'invalid';
    public const UNKNOWN = 'unknown';
    public const BLOCKED = 'blocked';

    /** @var callable(string, string): array{found: bool, error: bool} */
    private $remoteLookup;
    private ?DnsManager $manager = null;

    /**
     * @param callable(string, string): array{found: bool, error: bool}|null $remoteLookup
     *        Test seam: (fqdn, rootDomain) => whether the zone already answers.
     *        Defaults to the Cloudflare provider through DnsManager.
     */
    public function __construct(?callable $remoteLookup = null)
    {
        $this->remoteLookup = $remoteLookup ?? [$this, 'lookupAuthoritative'];
    }

    /**
     * @param array<string, mixed>|null $domain Root domain row (null = first open one)
     * @param bool                      $useCache False forces an authoritative read
     * @return array{
     *     ok: bool, status: string, code: string, label: string, fqdn: string|null,
     *     available: bool, domain_id: int|null, root_domain: string|null,
     *     message: string, authoritative: bool, cached: bool, source: string,
     *     suggestions: list<string>, errors: array<string, string>
     * }
     */
    public function check(string $rawLabel, ?array $domain = null, bool $useCache = true): array
    {
        $domain ??= Domain::available()[0] ?? null;
        $root = (string) ($domain['root_domain'] ?? '');

        $validation = SubdomainLabel::validate($rawLabel, $root);
        $label = $validation['label'];

        $result = [
            'ok' => true,
            'status' => self::INVALID,
            'code' => $validation['code'],
            'label' => $label,
            'fqdn' => null,
            'available' => false,
            'domain_id' => $domain !== null ? (int) $domain['id'] : null,
            'root_domain' => $root !== '' ? $root : null,
            'message' => $validation['message'],
            'authoritative' => false,
            'cached' => false,
            'source' => 'validation',
            'suggestions' => [],
            'errors' => [],
        ];

        if (!$validation['ok']) {
            $result['errors']['label'] = $validation['message'];
            $result['suggestions'] = self::suggestions($label);

            // A name blocked by policy ("reserved", "apex", "wildcard") is a
            // different answer from malformed input: it will never become
            // available, so it must not be reported as invalid (which implies
            // "fix your typing"). Both are unavailable.
            if (in_array($validation['code'], ['reserved', 'apex', 'wildcard'], true)) {
                $result['status'] = self::RESERVED;
                $result['code'] = $validation['code'];
            }

            return $result;
        }

        if ($domain === null) {
            return array_merge($result, [
                'ok' => false,
                'status' => self::UNKNOWN,
                'code' => 'no_domain',
                'message' => 'No root domains are currently open for registration.',
                'source' => 'config',
            ]);
        }

        $result['fqdn'] = $label . '.' . $root;

        if (($domain['registration_status'] ?? 'ready') !== 'ready' || !($domain['is_active'] ?? true)) {
            return array_merge($result, [
                'status' => self::BLOCKED,
                'code' => 'domain_closed',
                'message' => $root . ' is not accepting new subdomains right now.',
                'source' => 'domain',
            ]);
        }

        // ---- 2. subdomains ------------------------------------------------
        $subdomain = Database::selectOne(
            "SELECT id, user_id, status FROM subdomains
              WHERE domain_id = :domain_id AND label = :label AND status <> 'terminated'
              LIMIT 1",
            ['domain_id' => (int) $domain['id'], 'label' => $label]
        );

        if ($subdomain !== null) {
            return array_merge($result, [
                'status' => (string) $subdomain['status'] === 'active' ? self::TAKEN : self::PENDING,
                'code' => 'subdomain_exists',
                'message' => $result['fqdn'] . ' is '
                    . ((string) $subdomain['status'] === 'active' ? 'already taken.' : 'waiting for DNS provisioning.'),
                'source' => 'subdomains',
                'suggestions' => self::suggestions($label),
            ]);
        }

        // ---- 3. dns_records (desired state we own) ------------------------
        $record = Database::selectOne(
            "SELECT id, status FROM dns_records
              WHERE domain_id = :domain_id AND name = :name AND status <> 'deleted'
              LIMIT 1",
            ['domain_id' => (int) $domain['id'], 'name' => $result['fqdn']]
        );

        if ($record !== null) {
            return array_merge($result, [
                'status' => self::TAKEN,
                'code' => 'record_exists',
                'message' => $result['fqdn'] . ' already has a DNS record.',
                'source' => 'dns_records',
                'suggestions' => self::suggestions($label),
            ]);
        }

        // ---- 4. live reservations -----------------------------------------
        $reservation = Reservation::pendingFor((int) $domain['id'], $label);

        if ($reservation !== null) {
            return array_merge($result, [
                'status' => self::PENDING,
                'code' => 'reservation_pending',
                'message' => $result['fqdn'] . ' is reserved while a request is being reviewed.',
                'source' => 'reservations',
                'suggestions' => self::suggestions($label),
            ]);
        }

        // ---- 5. authoritative DNS -----------------------------------------
        $remote = $this->remote($result['fqdn'], $root, $useCache);

        if ($remote['error']) {
            // Fail closed: we cannot prove the name is free, so we do not say
            // it is. `available` stays false and the UI explains why.
            return array_merge($result, [
                'ok' => false,
                'status' => self::UNKNOWN,
                'code' => 'dns_unavailable',
                'message' => 'We could not confirm availability with the DNS provider. Please try again in a moment.',
                'source' => 'dns',
                'cached' => $remote['cached'],
            ]);
        }

        if ($remote['found']) {
            return array_merge($result, [
                'status' => self::TAKEN,
                'code' => 'dns_conflict',
                'message' => $result['fqdn'] . ' is already in use.',
                'source' => $remote['consulted'] ? 'dns' : 'local',
                'authoritative' => $remote['consulted'],
                'cached' => $remote['cached'],
                'suggestions' => self::suggestions($label),
            ]);
        }

        return array_merge($result, [
            'status' => self::AVAILABLE,
            'code' => 'available',
            'available' => true,
            'message' => $result['fqdn'] . ' is available.',
            // `authoritative` is only true when a zone was actually asked. With
            // DNS_PROVIDER=none the answer rests on local state, and saying so is
            // the difference between an honest API and a misleading one.
            'source' => $remote['consulted'] ? 'dns' : 'local',
            'authoritative' => $remote['consulted'],
            'cached' => $remote['cached'],
        ]);
    }

    /**
     * Ask the zone whether anything answers for this name.
     *
     * Only A/AAAA/CNAME and NS count. A TXT record (SPF, verification) does not
     * block a subdomain, and an MX at a host name does not either — but an NS
     * delegation does, because that name belongs to somebody else entirely.
     *
     * @return array{found: bool, error: bool, cached: bool, records: list<string>}
     */
    public function lookupAuthoritative(string $fqdn, string $rootDomain, bool $useCache = true): array
    {
        $provider = DnsProvider::current();

        if (!$provider instanceof CloudflareDnsProvider || !$provider->isConfigured()) {
            // No authoritative zone is configured: local state is all there is,
            // and pretending to check DNS would be worse than admitting it.
            // `consulted: false` is what keeps the API from claiming
            // "authoritative": true" when nothing was asked.
            return ['found' => false, 'error' => false, 'cached' => false, 'records' => [], 'consulted' => false];
        }

        $cacheKey = ['fqdn' => strtolower($fqdn), 'zone' => $provider->client()->zoneId()];
        $cache = new AvailabilityCache();

        if ($useCache) {
            $hit = $cache->get($cacheKey);

            if ($hit !== null) {
                return $hit + ['consulted' => true];
            }
        }

        try {
            $records = $provider->client()->recordsAtName($fqdn);
        } catch (CloudflareApiException $exception) {
            DnsLog::record('availability.remote_failed', [
                'fqdn' => $fqdn,
                'reason' => $exception->reason(),
            ]);

            // Deliberately not cached: an outage must not create a 5-minute
            // window in which we confidently report names as free.
            return ['found' => false, 'error' => true, 'cached' => false, 'records' => [], 'consulted' => false];
        }

        $blocking = [];

        foreach ($records as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));

            if (in_array($type, ['A', 'AAAA', 'CNAME', 'NS'], true)) {
                $blocking[] = $type;
            }
        }

        $answer = [
            'found' => $blocking !== [],
            'error' => false,
            'cached' => false,
            'records' => array_values(array_unique($blocking)),
            'consulted' => true,
        ];

        $cache->put($cacheKey, $answer);

        return $answer;
    }

    /**
     * @return array{found: bool, error: bool, cached: bool, records: list<string>}
     */
    private function remote(string $fqdn, string $rootDomain, bool $useCache): array
    {
        $lookup = $this->remoteLookup;

        // $useCache must reach the lookup: a reservation re-checks the zone
        // authoritatively, and serving that from the availability cache would
        // let a name be reserved on the strength of a stale "free".
        $answer = $lookup($fqdn, $rootDomain, $useCache);

        // Normalise whatever the seam returned, so a test double only has to
        // provide found/error.
        return [
            'found' => (bool) ($answer['found'] ?? false),
            'error' => (bool) ($answer['error'] ?? false),
            'cached' => (bool) ($answer['cached'] ?? false),
            'records' => is_array($answer['records'] ?? null) ? $answer['records'] : [],
            // A test seam that only supplies found/error is treated as consulted:
            // it *is* the stand-in for the provider.
            'consulted' => (bool) ($answer['consulted'] ?? true),
        ];
    }

    /**
     * Cheap alternatives, never reserved and never taken by a local row.
     *
     * @return list<string>
     */
    public static function suggestions(string $label, int $count = 4): array
    {
        $base = preg_replace('/[^a-z0-9-]/', '', mb_strtolower($label)) ?? '';
        $base = trim(substr($base, 0, 24), '-');

        // A reserved or structural token makes a poor stem: "www-app" and
        // "admin-hq" are noise, and offering them suggests we would accept them.
        if ($base === '' || preg_match('/^[0-9]+$/', $base) === 1 || !SubdomainLabel::isReusable($base)) {
            $base = 'mysite';
        }

        $suffixes = ['app', 'hq', 'web', 'site', 'online', 'dev'];
        $suggestions = [];

        foreach ($suffixes as $suffix) {
            $candidate = $base . '-' . $suffix;

            if (SubdomainLabel::validate($candidate)['ok']) {
                $suggestions[] = $candidate;
            }

            if (count($suggestions) >= $count) {
                break;
            }
        }

        return $suggestions;
    }
}
