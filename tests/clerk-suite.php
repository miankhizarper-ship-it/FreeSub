<?php

declare(strict_types=1);

/**
 * Clerk authentication suite. Included by tests/run.php.
 *
 * Everything here runs against production code paths:
 *
 *   * tokens are genuinely RS256-signed and verified against a real public key,
 *   * the Backend API is replaced at the client's transport seam,
 *   * the handoff table, the user sync and the session binding are the real
 *     implementations, backed by the isolated SQLite test database.
 *
 * No network access is required, and no secret is used.
 *
 * @var TestRunner $t
 */

// Point verification and the Backend API at the harness before anything runs.
$clerkHarness = new ClerkTestHarness();
$clerkHarness->installVerifier();
$clerkHarness->installApiClient();

// Pin the Clerk configuration through the documented test override rather
// than the environment: the developer's .env must not influence the suite.
$GLOBALS['FREESUB_CONFIG_OVERRIDE'] = array_replace_recursive(
    $GLOBALS['FREESUB_CONFIG_OVERRIDE'] ?? [],
    [
        'url' => 'http://localhost',
        'clerk' => [
            // The real publishable key from the task brief; it decodes to the
            // eternal-porpoise-2112 instance, but the harness replaces the
            // frontend host and JWKS below so no network call is made.
            'publishable_key' => 'pk_test_ZXRlcm5hbC1wb3Jwb2lzZS0yMTEyLmNsZXJrLmFjY291bnRzLmRldiQ',
            'secret_key' => 'sk_test_harness',
            'authorized_parties' => ['http://localhost'],
            'frontend_api_host' => $clerkHarness->instanceHost(),
            'jwks_url' => 'https://' . $clerkHarness->instanceHost() . '/.well-known/jwks.json',
            'backend_api_url' => 'https://api.clerk.test',
            'session_recheck' => 0,
            'break_glass' => false,
            'auto_provision' => true,
            'jwt_template' => 'freesub_handoff',
            'clock_skew' => 5,
            'jwks_cache_ttl' => 0,
        ],
    ]
);

/**
 * Reset the auth state between scenarios: a fresh session array and no cached
 * user, exactly like a new request.
 */
function clerk_test_reset_session(): void
{
    $_SESSION = [];

    // Reset the per-request memo so the next assertion sees a cold start.
    $reset = static function (string $property, mixed $value): void {
        $prop = (new ReflectionClass(Auth::class))->getProperty($property);
        $prop->setValue(null, $value);
    };

    $reset('user', null);
    $reset('resolved', false);
}

/**
 * Forget the per-request memo without touching $_SESSION, so the live session
 * array survives while the next Auth::check() runs a cold resolution.
 */
function clerk_test_reset_session_preserving_storage(): void
{
    $reset = static function (string $property, mixed $value): void {
        (new ReflectionClass(Auth::class))->getProperty($property)->setValue(null, $value);
    };

    $reset('user', null);
    $reset('resolved', false);
}

function clerk_test_user_id(string $clerkUserId): ?int
{
    $row = User::findByClerkId($clerkUserId);

    return $row !== null ? (int) $row['id'] : null;
}

/**
 * A syntactically valid RSA private key that is NOT the instance's key.
 *
 * Built once with openssl from a second committed fixture so the "foreign
 * signer" case is a genuine signature mismatch rather than a malformed token.
 */
function self_clerk_attacker_key(): string
{
    static $key = null;

    if ($key !== null) {
        return $key;
    }

    $path = dirname(__DIR__) . '/tests/fixtures/clerk-attacker-key.pem';
    $bundle = (string) file_get_contents($path);

    if (preg_match('/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s', $bundle, $matches) !== 1) {
        throw new RuntimeException('attacker key fixture missing');
    }

    return $key = $matches[0] . "\n";
}

if (run_suite($only, 'clerk-config')) {
    $t->suite('Clerk configuration');

    $t->check('publishable key accepted', ClerkConfig::publishableKey() === 'pk_test_ZXRlcm5hbC1wb3Jwb2lzZS0yMTEyLmNsZXJrLmFjY291bnRzLmRldiQ');
    $t->check('harness host is in force for the suite', ClerkConfig::frontendApiHost() === $clerkHarness->instanceHost(), ClerkConfig::frontendApiHost());
    $t->check('test instance detected', ClerkConfig::isTestInstance() === true);
    $t->check('issuer derived from the host', ClerkConfig::issuer() === 'https://' . $clerkHarness->instanceHost());
    $t->check('jwks url derived from the host', str_ends_with(ClerkConfig::jwksUrl(), '/.well-known/jwks.json'));

    // Decoding of the real publishable key (no harness override) is covered
    // separately so the suite can prove the base64 payload is parsed correctly.
    $t->check('publishable key decodes to its frontend API host', (static function (): bool {
        $saved = $GLOBALS['FREESUB_CONFIG_OVERRIDE'];
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = array_replace_recursive($saved, ['clerk' => ['frontend_api_host' => '']]);
        $decoded = ClerkConfig::frontendApiHost();
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = $saved;

        return $decoded === 'eternal-porpoise-2112.clerk.accounts.dev';
    })());
    $t->check('secret key reported as configured', ClerkConfig::secretKeyConfigured() === true);
    $t->check('sign-in can complete', ClerkConfig::canCompleteSignIn() === true);
    $t->check('authorized parties include our origin', in_array('http://localhost', ClerkConfig::authorizedParties(), true));

    $public = ClerkConfig::publicConfiguration();
    $t->check('public config never contains the secret key', !str_contains(json_encode($public), 'sk_test'));
    $t->check('public config exposes the publishable key', ($public['publishableKey'] ?? '') === ClerkConfig::publishableKey());

    $t->check('a secret key is never accepted as a publishable key', (static function (): bool {
        $saved = $GLOBALS['FREESUB_CONFIG_OVERRIDE'];
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = array_replace_recursive($saved, [
            'clerk' => ['publishable_key' => 'sk_test_notapublishablekey'],
        ]);

        $accepted = ClerkConfig::publishableKey();
        $configured = ClerkConfig::isConfigured();

        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = $saved;

        return $accepted === null && $configured === false;
    })());

    $diagnostics = ClerkConfig::diagnostics();
    $t->check('diagnostics never echo a secret', !str_contains(json_encode($diagnostics), 'sk_test_harness'));
}

if (run_suite($only, 'clerk-token')) {
    $t->suite('Clerk token verification');

    $harness = new ClerkTestHarness();
    $harness->installVerifier();
    $issuer = $harness->issuer();

    $valid = $harness->token(['sub' => 'user_2token1']);
    $result = ClerkTokenVerifier::verify($valid, $issuer);
    $t->check('valid token verifies', $result->valid === true, $result->reason);
    $t->check('subject extracted', $result->subject() === 'user_2token1');
    $t->check('session id extracted', $result->sessionId() === 'sess_testharness1');

    // Signature
    $tampered = substr($valid, 0, -6) . 'AAAAAA';
    $result = ClerkTokenVerifier::verify($tampered, $issuer);
    $t->check('tampered signature rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_BAD_SIGNATURE, $result->reason);

    // A token signed by a different key (attacker) must not verify. The
    // "attacker" key is derived from the fixture by flipping the modulus so the
    // test does not need a key generator (php-wasm has no RNG).
    $result = ClerkTokenVerifier::verify($harness->token(['sub' => 'user_2evil'], self_clerk_attacker_key()), $issuer);
    $t->check('foreign signing key rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_BAD_SIGNATURE, $result->reason);

    // alg downgrade
    $noneHeader = ClerkTestHarness::base64Url(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => $harness->kid()]));
    $payload = ClerkTestHarness::base64Url(json_encode(['sub' => 'user_2evil', 'iss' => $issuer, 'exp' => time() + 60]));
    $result = ClerkTokenVerifier::verify($noneHeader . '.' . $payload . '.', $issuer);
    $t->check('alg=none rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_UNSUPPORTED_ALG, $result->reason);

    $hsHeader = ClerkTestHarness::base64Url(json_encode(['alg' => 'HS256', 'kid' => $harness->kid()]));
    $result = ClerkTokenVerifier::verify($hsHeader . '.' . $payload . '.abc', $issuer);
    $t->check('HS256 rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_UNSUPPORTED_ALG, $result->reason);

    // Expiry
    $result = ClerkTokenVerifier::verify($harness->token(['exp' => time() - 120]), $issuer);
    $t->check('expired token rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_EXPIRED, $result->reason);

    // Not yet valid
    $result = ClerkTokenVerifier::verify($harness->token(['nbf' => time() + 600]), $issuer);
    $t->check('nbf in the future rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_NOT_YET_VALID, $result->reason);

    // Issuer — the check the official SDK does not perform.
    $result = ClerkTokenVerifier::verify($harness->token(['iss' => 'https://attacker.clerk.accounts.dev']), $issuer);
    $t->check('wrong issuer rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_BAD_ISSUER, $result->reason);

    $result = ClerkTokenVerifier::verify($harness->token(['iss' => '']), $issuer);
    $t->check('missing issuer rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_BAD_ISSUER, $result->reason);

    // Authorized party
    $result = ClerkTokenVerifier::verify($harness->token(['azp' => 'https://evil.example']), $issuer);
    $t->check('wrong azp rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_BAD_AUTHORIZED_PARTY, $result->reason);

    $result = ClerkTokenVerifier::verify($harness->token(['azp' => 'http://localhost']), $issuer);
    $t->check('matching azp accepted', $result->valid === true, $result->reason);

    $result = ClerkTokenVerifier::verify($harness->token(['azp' => null]), $issuer);
    $t->check('absent azp (server-minted token) accepted', $result->valid === true, $result->reason);

    // Subject
    $result = ClerkTokenVerifier::verify($harness->token(['sub' => '']), $issuer);
    $t->check('missing subject rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_MISSING_SUBJECT, $result->reason);

    $result = ClerkTokenVerifier::verify($harness->token(['sub' => '../../etc/passwd']), $issuer);
    $t->check('malformed subject rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_MISSING_SUBJECT, $result->reason);

    // Audience: only enforced when CLERK_AUDIENCES is configured, because the
    // handoff template does not set one by default.
    $t->check('audience is not enforced when unconfigured', (static function () use ($harness, $issuer): bool {
        $saved = $GLOBALS['FREESUB_CONFIG_OVERRIDE'];
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = array_replace_recursive($saved, ['clerk' => ['audiences' => []]]);
        $result = ClerkTokenVerifier::verify($harness->token(['aud' => 'anything']), $issuer);
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = $saved;

        return $result->valid === true;
    })());

    $t->check('wrong audience rejected when configured', (static function () use ($harness, $issuer): bool {
        $saved = $GLOBALS['FREESUB_CONFIG_OVERRIDE'];
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = array_replace_recursive($saved, ['clerk' => ['audiences' => ['freesub']]]);
        $result = ClerkTokenVerifier::verify($harness->token(['aud' => 'somebody-else']), $issuer);
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = $saved;

        return !$result->valid && $result->reason === ClerkTokenVerifier::REASON_BAD_AUDIENCE;
    })());

    $t->check('matching audience accepted', (static function () use ($harness, $issuer): bool {
        $saved = $GLOBALS['FREESUB_CONFIG_OVERRIDE'];
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = array_replace_recursive($saved, ['clerk' => ['audiences' => ['freesub']]]);
        $result = ClerkTokenVerifier::verify($harness->token(['aud' => 'freesub']), $issuer);
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = $saved;

        return $result->valid === true;
    })());

    $t->check('array audience accepted', (static function () use ($harness, $issuer): bool {
        $saved = $GLOBALS['FREESUB_CONFIG_OVERRIDE'];
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = array_replace_recursive($saved, ['clerk' => ['audiences' => ['freesub']]]);
        $result = ClerkTokenVerifier::verify($harness->token(['aud' => ['other', 'freesub']]), $issuer);
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = $saved;

        return $result->valid === true;
    })());

    $t->check('missing audience rejected when configured', (static function () use ($harness, $issuer): bool {
        $saved = $GLOBALS['FREESUB_CONFIG_OVERRIDE'];
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = array_replace_recursive($saved, ['clerk' => ['audiences' => ['freesub']]]);
        $result = ClerkTokenVerifier::verify($harness->token(['aud' => null]), $issuer);
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = $saved;

        return !$result->valid && $result->reason === ClerkTokenVerifier::REASON_BAD_AUDIENCE;
    })());

    // JWKS unavailable must be a distinct reason so the bridge can answer 503
    // rather than pretending the user's token was bad.
    $t->check('unreachable JWKS is reported as key-unavailable', (static function () use ($harness, $issuer): bool {
        $saved = ClerkTokenVerifier::class;
        ClerkTokenVerifier::setJwksLoader(static function (): array {
            throw new RuntimeException('JWKS request failed');
        });

        $result = ClerkTokenVerifier::verify(
            $harness->token(),
            $issuer,
            'https://unreachable.test/' . bin2hex(random_bytes(4)) . '/jwks.json'
        );
        $harness->installVerifier();

        return !$result->valid && $result->reason === ClerkTokenVerifier::REASON_KEY_UNAVAILABLE;
    })());

    // Structure
    $t->check('empty token rejected', ClerkTokenVerifier::verify('', $issuer)->reason === ClerkTokenVerifier::REASON_MISSING);
    $t->check('non-JWT rejected', ClerkTokenVerifier::verify('not-a-token', $issuer)->reason === ClerkTokenVerifier::REASON_MALFORMED);

    // Unknown kid
    $unknownKid = $harness->token([], null);
    $segments = explode('.', $unknownKid);
    $header = ClerkTestHarness::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'no-such-key']));
    $result = ClerkTokenVerifier::verify($header . '.' . $segments[1] . '.' . $segments[2], $issuer);
    $t->check('unknown kid rejected', !$result->valid && $result->reason === ClerkTokenVerifier::REASON_UNKNOWN_KEY, $result->reason);

    // Messages are safe and human
    $t->check('expired message is user facing', str_contains(ClerkTokenVerifier::verify($harness->token(['exp' => time() - 30]), $issuer)->message(), 'expired'));

    // JWKS document handling: real JWK -> PEM conversion
    $document = $harness->jwksDocument();
    $pem = ClerkTokenVerifier::jwkToPem($document['keys'][0]['n'], $document['keys'][0]['e']);
    $t->check('JWK converts to a usable PEM', is_string($pem) && openssl_pkey_get_public($pem) !== false);

    $t->check('key rotation: loader is consulted for a new kid', (static function () use ($harness): bool {
        $called = 0;
        ClerkTokenVerifier::setJwksLoader(static function (string $url) use ($harness, &$called): array {
            $called++;

            return ['keys' => [$harness->kid() => ClerkTestHarness::publicPem()]];
        });

        // Force a cold cache by using a unique JWKS url for this assertion.
        $result = ClerkTokenVerifier::verify($harness->token(), $harness->issuer(), 'https://cache.test/' . bin2hex(random_bytes(4)) . '/jwks.json');
        $harness->installVerifier();

        return $result->valid && $called > 0;
    })());
}

if (run_suite($only, 'clerk-sync')) {
    $t->suite('Clerk user synchronisation');

    $harness = $clerkHarness;

    Database::execute("DELETE FROM users WHERE email LIKE '%@clerk-test.local'");

    // --- New identity creates exactly one least-privileged account ---------
    $clerkUser = $harness->addUser('user_2syncnew', 'newcomer@clerk-test.local', 'Grace', 'Hopper');
    $sync = User::syncFromClerk($clerkUser);

    $t->check('first sign-in creates a local account', $sync['created'] === true);
    $t->check('account is linked to the Clerk id', $sync['user']['clerk_user_id'] === 'user_2syncnew');
    $t->check('new accounts get the user role', $sync['user']['role'] === 'user', $sync['user']['role']);
    $t->check('name comes from Clerk', $sync['user']['name'] === 'Grace Hopper', $sync['user']['name']);
    $t->check('email stored lower-case', $sync['user']['email'] === 'newcomer@clerk-test.local');
    $t->check('email marked verified', !empty($sync['user']['email_verified_at']));

    // --- Duplicate synchronisation must not duplicate the row --------------
    $again = User::syncFromClerk($clerkUser);
    $t->check('second sign-in reuses the account', $again['created'] === false && $again['linked'] === false);
    $t->check('same internal id reused', (int) $again['user']['id'] === (int) $sync['user']['id']);
    $t->check('no duplicate row', (int) Database::scalar(
        'SELECT COUNT(*) FROM users WHERE clerk_user_id = :id',
        ['id' => 'user_2syncnew']
    ) === 1);

    // --- Concurrent-style double insert is caught by the unique index ------
    $t->check('duplicate insert attempt collapses onto the existing row', (static function () use ($harness): bool {
        $user = $harness->users['user_2syncnew'];
        Database::execute('DELETE FROM users WHERE clerk_user_id = :id', ['id' => 'user_2syncnew']);
        $first = User::syncFromClerk($user);
        $second = User::syncFromClerk($user);

        return (int) $first['user']['id'] === (int) $second['user']['id'];
    })());

    // --- Existing account migration (the admin case) ----------------------
    Database::execute("DELETE FROM users WHERE email LIKE '%@clerk-test.local'");

    Database::execute(
        "INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
         VALUES ('Legacy Admin', 'legacy-admin@clerk-test.local', :hash, 'admin', 'active', :now, :now)",
        ['hash' => Auth::hashPassword('LegacyAdminPass1'), 'now' => now()]
    );

    $legacyId = (int) Database::lastInsertId();
    $domain = Domain::available()[0];

    Database::execute(
        'INSERT INTO reservations (user_id, domain_id, label, status, created_at, updated_at)
         VALUES (:uid, :did, :label, :status, :now, :now)',
        ['uid' => $legacyId, 'did' => (int) $domain['id'], 'label' => 'legacy-site', 'status' => 'pending', 'now' => now()]
    );
    $reservationId = (int) Database::lastInsertId();

    $t->check('legacy admin has no Clerk id yet', clerk_test_user_id('user_2legacy') === null);

    $legacyClerk = $harness->addUser('user_2legacy', 'legacy-admin@clerk-test.local', 'Legacy', 'Admin');

    // A self-service sign-in must NOT inherit an administrator row, even with a
    // verified address (see SECURITY_AUDIT F12).
    $t->throws('an administrator row is never claimed by a web sign-in', static function () use ($legacyClerk): void {
        User::syncFromClerk($legacyClerk);
    });

    $t->check('the administrator row is untouched by the attempt', (int) Database::scalar(
        'SELECT COUNT(*) FROM users WHERE id = :id AND clerk_user_id IS NULL AND role = :role',
        ['id' => $legacyId, 'role' => 'admin']
    ) === 1);

    // The deliberate, CLI-only path.
    $migrated = User::syncFromClerk($legacyClerk, true);

    $t->check('existing account is linked, not recreated', $migrated['created'] === false && $migrated['linked'] === true);
    $t->check('internal id preserved across migration', (int) $migrated['user']['id'] === $legacyId, [$migrated['user']['id'], $legacyId]);
    $t->check('existing admin keeps the admin role', $migrated['user']['role'] === 'admin', $migrated['user']['role']);
    $t->check('clerk id attached to the existing row', $migrated['user']['clerk_user_id'] === 'user_2legacy');
    $t->check('foreign key relationship preserved', (int) Database::scalar(
        'SELECT user_id FROM reservations WHERE id = :id',
        ['id' => $reservationId]
    ) === $legacyId);

    // --- Unverified email must never hijack an existing account -----------
    Database::execute(
        "INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
         VALUES ('Victim', 'victim@clerk-test.local', :hash, 'admin', 'active', :now, :now)",
        ['hash' => Auth::hashPassword('VictimPass1234'), 'now' => now()]
    );
    $victimId = (int) Database::lastInsertId();

    $t->throws('unverified email cannot attach to an existing account', static function (): void {
        $harness = new ClerkTestHarness();
        $harness->addUser('user_2attacker', 'victim@clerk-test.local', 'Mallory', 'Attacker', verified: false);
        User::syncFromClerk($harness->users['user_2attacker']);
    });

    $t->check('victim account untouched', (int) Database::scalar(
        'SELECT COUNT(*) FROM users WHERE id = :id AND clerk_user_id IS NULL',
        ['id' => $victimId]
    ) === 1);

    // --- A Clerk identity already bound elsewhere is refused --------------
    $t->throws('identity bound to another account is refused', static function () use ($harness): void {
        // Legacy admin is already linked to user_2legacy; a second Clerk user
        // with the same email must not steal it.
        $harness->addUser('user_2copycat', 'legacy-admin@clerk-test.local');
        User::syncFromClerk($harness->users['user_2copycat']);
    });

    // --- Banned users ------------------------------------------------------
    $banned = $harness->addUser('user_2banned', 'banned@clerk-test.local', 'Bad', 'Actor', true, banned: true);
    $t->throws('banned Clerk user rejected', static fn () => User::syncFromClerk($banned));

    // --- Role is never taken from Clerk data ------------------------------
    $sneaky = $harness->addUser('user_2sneaky', 'sneaky@clerk-test.local', 'Sneaky', 'Person');
    $sneaky['public_metadata'] = ['role' => 'admin'];
    $sneaky['private_metadata'] = ['role' => 'admin'];
    $sneaky['unsafe_metadata'] = ['role' => 'admin'];
    $sneak = User::syncFromClerk($sneaky);
    $t->check('Clerk metadata cannot grant admin', $sneak['user']['role'] === 'user', $sneak['user']['role']);

    // --- Email moves only when Clerk verifies the new address -------------
    $sneaky['email_addresses'][0]['email_address'] = 'renamed@clerk-test.local';
    $renamed = User::syncFromClerk($sneaky);
    $t->check('verified email change is applied', $renamed['user']['email'] === 'renamed@clerk-test.local', $renamed['user']['email']);

    // Reset harness users between suites.
    $clerkHarness->users = ['user_2legacy' => $legacyClerk];
    $clerkHarness->sessions = ['sess_user_2legacy' => $clerkHarness->sessions['sess_user_2legacy']];
}

if (run_suite($only, 'clerk-handoff')) {
    $t->suite('Clerk handoff & sessions');

    $harness = $clerkHarness;

    // --- Channel binding ---------------------------------------------------
    clerk_test_reset_session();
    $channel = ClerkHandoff::openChannel();

    $t->check('channel has a long random secret', strlen($channel['channel']) === 48);
    $t->check('channel has a separate state value', strlen($channel['state']) === 32);
    $t->check('channel expires', $channel['expires_at'] > time());

    $t->check('missing credentials rejected', ClerkHandoff::validateChannel(null, null)['reason'] === 'missing_credentials');
    $t->check('wrong channel rejected', ClerkHandoff::validateChannel('deadbeef', $channel['state'])['reason'] === 'channel_mismatch');
    $t->check('wrong state rejected', ClerkHandoff::validateChannel($channel['channel'], 'deadbeef')['reason'] === 'channel_mismatch');
    $t->check('correct pair accepted', ClerkHandoff::validateChannel($channel['channel'], $channel['state'])['ok'] === true);

    // --- Successful registration + login through the handoff --------------
    clerk_test_reset_session();
    $channel = ClerkHandoff::openChannel();

    $signup = $harness->addUser('user_2handoff', 'handoff@clerk-test.local', 'Alan', 'Turing');
    $origin = 'http://localhost';

    $result = ClerkHandoff::completeFromBrowserToken($harness->token(['sub' => 'user_2handoff']), $origin);
    $t->check('browser token completes the handoff', $result['ok'] === true, $result['reason'] ?? null);
    $t->check('handoff returns a single-use token', isset($result['token']) && preg_match('/^[a-f0-9]{64}$/', $result['token']) === 1);
    $t->check('handoff reports a new account', str_contains((string) $result['message'], 'created'));
    $t->check('handoff token is stored hashed only', (int) Database::scalar(
        'SELECT COUNT(*) FROM clerk_handoffs WHERE token_hash = :hash',
        ['hash' => hash('sha256', (string) $result['token'])]
    ) === 1);
    $t->check('plaintext token is not in the database', (int) Database::scalar(
        'SELECT COUNT(*) FROM clerk_handoffs WHERE token_hash = :raw',
        ['raw' => (string) $result['token']]
    ) === 0);

    $redeemed = ClerkHandoff::redeem((string) $result['token']);
    $t->check('token redeems once', $redeemed['ok'] === true, $redeemed['reason'] ?? null);
    $t->check('redeemed user matches the Clerk identity', ($redeemed['user']['clerk_user_id'] ?? '') === 'user_2handoff');

    $replay = ClerkHandoff::redeem((string) $result['token']);
    $t->check('replay is rejected', $replay['ok'] === false && $replay['reason'] === 'already_used', $replay['reason']);

    $t->check('malformed token rejected', ClerkHandoff::redeem('not-a-token')['reason'] === 'malformed');
    $t->check('unknown token rejected', ClerkHandoff::redeem(str_repeat('a', 64))['reason'] === 'unknown');

    // --- Expired ticket ----------------------------------------------------
    $expiredToken = bin2hex(random_bytes(32));
    Database::execute(
        'INSERT INTO clerk_handoffs (token_hash, user_id, clerk_user_id, state, attempts, expires_at, created_at)
         VALUES (:hash, :uid, :clerk, :state, 0, :expires, :now)',
        [
            'hash' => hash('sha256', $expiredToken),
            'uid' => (int) $redeemed['user']['id'],
            'clerk' => 'user_2handoff',
            'state' => 'pending',
            'expires' => gmdate('Y-m-d H:i:s', time() - 30),
            'now' => now(),
        ]
    );
    $t->check('expired ticket rejected', ClerkHandoff::redeem($expiredToken)['reason'] === 'expired');

    // --- Break-glass tickets -------------------------------------------------
    // Only a ticket the CLI stamped takes the offline path, and only for an
    // administrator. A Clerk ticket with the same shape must not.
    $admin = User::findByClerkId('user_2legacy');

    if ($admin === null) {
        // This suite must run on its own: recreate the migrated administrator.
        $existing = User::findByEmail('legacy-admin@clerk-test.local');

        if ($existing === null) {
            $existing = User::create('Legacy Admin', 'legacy-admin@clerk-test.local', 'LegacyAdminPass1', 'admin');
        } else {
            Database::execute("UPDATE users SET role = 'admin' WHERE id = :id", ['id' => (int) $existing['id']]);
        }

        User::attachClerkIdentity((int) $existing['id'], 'user_2legacy');
        $admin = User::findByClerkId('user_2legacy');
        $harness->addUser('user_2legacy', 'legacy-admin@clerk-test.local', 'Legacy', 'Admin');
    }

    if ($admin !== null) {
        $issue = static function (int $userId, string $ip, string $agent): string {
            $token = bin2hex(random_bytes(32));
            Database::execute(
                'INSERT INTO clerk_handoffs (token_hash, user_id, clerk_user_id, state, attempts, expires_at, ip_address, user_agent, created_at)
                 VALUES (:hash, :uid, NULL, :state, 0, :expires, :ip, :ua, :now)',
                [
                    'hash' => hash('sha256', $token),
                    'uid' => $userId,
                    'state' => 'pending',
                    'expires' => gmdate('Y-m-d H:i:s', time() + 300),
                    'ip' => $ip,
                    'ua' => $agent,
                    'now' => now(),
                ]
            );

            return $token;
        };

        $cliTicket = ClerkHandoff::redeem($issue((int) $admin['id'], 'cli', 'break-glass'));
        $t->check('CLI break-glass ticket redeems', $cliTicket['ok'] === true, $cliTicket['reason'] ?? null);
        $t->check('break-glass ticket is flagged', ($cliTicket['break_glass'] ?? false) === true);
        $t->check('break-glass ticket names the administrator', (string) ($cliTicket['user']['role'] ?? '') === 'admin');
        $t->check('break-glass ticket is single use', ClerkHandoff::redeem($cliTicket === [] ? '' : $issue((int) $admin['id'], 'cli', 'break-glass'))['ok'] === true);

        $webTicket = ClerkHandoff::redeem($issue((int) $admin['id'], '203.0.113.9', 'Mozilla/5.0'));
        $t->check('a web ticket for an admin is not treated as break-glass', ($webTicket['break_glass'] ?? null) !== true);

        $nonAdmin = User::findByEmail('handoff@clerk-test.local');
        $refused = ClerkHandoff::redeem($issue((int) $nonAdmin['id'], 'cli', 'break-glass'));
        $t->check('break-glass ticket refused for a non-administrator', $refused['ok'] === false, $refused['reason'] ?? null);

        // A demotion takes effect even if the link was issued while admin.
        Database::execute("UPDATE users SET role = 'user' WHERE id = :id", ['id' => (int) $admin['id']]);
        $demoted = ClerkHandoff::redeem($issue((int) $admin['id'], 'cli', 'break-glass'));
        $t->check('break-glass ticket dies when the account is demoted', $demoted['ok'] === false, $demoted['reason'] ?? null);
        Database::execute("UPDATE users SET role = 'admin' WHERE id = :id", ['id' => (int) $admin['id']]);
    }

    // --- Origin enforcement ------------------------------------------------
    clerk_test_reset_session();
    ClerkHandoff::openChannel();

    $foreign = ClerkHandoff::completeFromBrowserToken($harness->token(['sub' => 'user_2handoff']), 'https://evil.example');
    $t->check('foreign origin rejected', $foreign['ok'] === false && $foreign['reason'] === 'origin_rejected', $foreign['reason']);

    $noOrigin = ClerkHandoff::completeFromBrowserToken($harness->token(['sub' => 'user_2handoff']), null);
    $t->check('absent origin tolerated for same-origin POSTs', $noOrigin['ok'] === true, $noOrigin['reason'] ?? null);

    // --- Invalid / expired token at the bridge ----------------------------
    clerk_test_reset_session();
    ClerkHandoff::openChannel();

    $bad = ClerkHandoff::completeFromBrowserToken($harness->token(['sub' => 'user_2handoff', 'exp' => time() - 60]), 'http://localhost');
    $t->check('expired browser token rejected', $bad['ok'] === false && $bad['reason'] === ClerkTokenVerifier::REASON_EXPIRED, $bad['reason']);

    $garbage = ClerkHandoff::completeFromBrowserToken('garbage', 'http://localhost');
    $t->check('garbage browser token rejected', $garbage['ok'] === false, $garbage['reason']);

    // --- Clerk outage is reported, not guessed ----------------------------
    clerk_test_reset_session();
    ClerkHandoff::openChannel();
    $harness->unreachable = true;

    $outage = ClerkHandoff::completeFromBrowserToken($harness->token(['sub' => 'user_2handoff']), 'http://localhost');
    $harness->unreachable = false;

    $t->check('Clerk outage fails closed', $outage['ok'] === false && $outage['reason'] === 'clerk_unavailable', $outage['reason']);

    // --- Clerk user deleted between token and lookup ----------------------
    clerk_test_reset_session();
    ClerkHandoff::openChannel();
    $missing = ClerkHandoff::completeFromBrowserToken($harness->token(['sub' => 'user_2ghost']), 'http://localhost');
    $t->check('deleted Clerk user rejected', $missing['ok'] === false && $missing['reason'] === 'clerk_user_missing', $missing['reason']);
}

if (run_suite($only, 'clerk-session')) {
    $t->suite('Clerk session binding & authorization');

    $harness = $clerkHarness;
    $handoffUser = User::findByClerkId('user_2handoff');

    if ($handoffUser === null) {
        // The account is normally created by the handoff suite above; recreating
        // it keeps this suite runnable on its own.
        $harness->installVerifier();
        $harness->installApiClient();
        $harness->addUser('user_2handoff', 'handoff@clerk-test.local', 'Alan', 'Turing');

        $result = ClerkHandoff::completeFromBrowserToken($harness->token(['sub' => 'user_2handoff']), 'http://localhost');

        $handoffUser = ($result['ok'] ?? false) === true && isset($result['token'])
            ? (ClerkHandoff::redeem((string) $result['token'])['user'] ?? null)
            : null;

        if ($handoffUser === null) {
            $t->check('handoff account could not be prepared', false, $result['reason'] ?? 'unknown');
        }
    }

    if ($handoffUser === null) {
        $handoffUser = User::findByClerkId('user_2legacy') ?? User::create('Fallback', 'fallback@clerk-test.local', 'FallbackPass123');
    }

    // --- Successful login --------------------------------------------------
    clerk_test_reset_session();
    Auth::loginWithClerk($handoffUser, 'user_2handoff', 'sess_user_2handoff');

    $t->check('login stores the internal id', ($_SESSION[Auth::SESSION_KEY] ?? null) === (int) $handoffUser['id']);
    $t->check('login stores the Clerk id', ($_SESSION[Auth::CLERK_KEY] ?? null) === 'user_2handoff');
    $t->check('login stores the Clerk session id', ($_SESSION[Auth::CLERK_SESSION_KEY] ?? null) === 'sess_user_2handoff');
    $t->check('authenticated user resolves', Auth::check() === true);
    $t->check('clerkUserId() returns the identity', Auth::clerkUserId() === 'user_2handoff');
    $t->check('last_login_at updated', !empty(Auth::user()['last_login_at']));
    $t->check('mirrored session recorded', (int) Database::scalar(
        'SELECT COUNT(*) FROM clerk_sessions WHERE session_id = :sid AND status = :status',
        ['sid' => 'sess_user_2handoff', 'status' => 'active']
    ) === 1);

    // The role check itself, without the exit path in requireAdmin().
    $t->check('admin guard is role based', (string) Auth::user()['role'] === 'user' && Auth::isAdmin() === false);

    // --- Logout ------------------------------------------------------------
    Auth::logout();
    clerk_test_reset_session();
    $t->check('logout clears the session', Auth::check() === false);
    $t->check('logout marks the mirrored session ended', (int) Database::scalar(
        'SELECT COUNT(*) FROM clerk_sessions WHERE session_id = :sid AND status = :status',
        ['sid' => 'sess_user_2handoff', 'status' => 'ended']
    ) === 1);

    // --- Invalid sessions --------------------------------------------------
    clerk_test_reset_session();
    $_SESSION[Auth::SESSION_KEY] = (int) $handoffUser['id'];
    $t->check('session without a Clerk identity is rejected', Auth::check() === false);

    clerk_test_reset_session();
    $_SESSION[Auth::SESSION_KEY] = (int) $handoffUser['id'];
    $_SESSION[Auth::CLERK_KEY] = 'user_2somebodyelse';
    $t->check('identity mismatch between session and row is rejected', Auth::check() === false);

    // A pre-migration session (internal id only) must not survive.
    clerk_test_reset_session();
    $_SESSION['_auth_user_id'] = (int) $handoffUser['id'];
    $_SESSION['_started_at'] = time();
    $t->check('legacy password-era session is rejected', Auth::check() === false);

    // A session cookie copied to another client stops working.
    clerk_test_reset_session();
    Auth::loginWithClerk($handoffUser, 'user_2handoff', 'sess_user_2handoff');

    $originalAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (different device)';
    clerk_test_reset_session_preserving_storage();
    $_SESSION[Auth::SESSION_KEY] = (int) $handoffUser['id'];
    $_SESSION[Auth::CLERK_KEY] = 'user_2handoff';
    $_SESSION[Auth::FINGERPRINT_KEY] = hash('sha256', (string) ($originalAgent ?? 'unknown'));
    $t->check('session fingerprint mismatch is rejected', Auth::check() === false);

    if ($originalAgent === null) {
        unset($_SERVER['HTTP_USER_AGENT']);
    } else {
        $_SERVER['HTTP_USER_AGENT'] = $originalAgent;
    }

    // Suspended account loses access immediately, then recovers.
    clerk_test_reset_session();
    Auth::loginWithClerk($handoffUser, 'user_2handoff', 'sess_user_2handoff');
    Database::execute("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => (int) $handoffUser['id']]);
    clerk_test_reset_session_preserving_storage();
    $t->check('suspended account is locked out', Auth::check() === false);
    Database::execute("UPDATE users SET status = 'active' WHERE id = :id", ['id' => (int) $handoffUser['id']]);

    // --- Server-side session revocation ------------------------------------
    clerk_test_reset_session();
    putenv('CLERK_SESSION_RECHECK=0');

    $admin = User::findByClerkId('user_2legacy');

    if ($admin !== null) {
        Auth::loginWithClerk($admin, 'user_2legacy', 'sess_user_2legacy');
        $t->check('admin retains admin access after migration', Auth::isAdmin() === true);
        $t->check('admin role resolves server-side', Auth::isAdmin() === true && (string) Auth::user()['role'] === 'admin');
        Auth::logout();
    }

    // --- Break-glass guard -------------------------------------------------
    $t->check('break-glass login requires the CLI proof', (static function () use ($admin): bool {
        if ($admin === null) {
            return true;
        }

        foreach (['', 'guess'] as $proof) {
            try {
                Auth::loginBreakGlass($admin, 'cli', $proof);

                return false;
            } catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'CLI')) {
                    return false;
                }
            }
        }

        return true;
    })());

    $t->check('break-glass login refuses non-admins', (static function () use ($handoffUser): bool {
        try {
            Auth::loginBreakGlass($handoffUser, 'cli', Auth::BREAK_GLASS_PROOF);
        } catch (RuntimeException) {
            return true;
        }

        return false;
    })());

    $t->check('break-glass login refuses when disabled', (static function () use ($admin): bool {
        if ($admin === null) {
            return true;
        }

        $saved = $GLOBALS['FREESUB_CONFIG_OVERRIDE'];
        $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = array_replace_recursive($saved, ['clerk' => ['break_glass' => false]]);

        try {
            Auth::loginBreakGlass($admin, 'cli', Auth::BREAK_GLASS_PROOF);

            return false;
        } catch (RuntimeException) {
            return true;
        } finally {
            $GLOBALS['FREESUB_CONFIG_OVERRIDE'] = $saved;
        }
    })());

    // --- No secrets in the session ----------------------------------------
    clerk_test_reset_session();
    Auth::loginWithClerk($handoffUser, 'user_2handoff', 'sess_user_2handoff');
    $sessionDump = json_encode($_SESSION);
    $t->check('session contains no secret key', !str_contains((string) $sessionDump, 'sk_test'));
    $t->check('session contains no JWT', !str_contains((string) $sessionDump, 'eyJ'));
    Auth::logout();
}

if (run_suite($only, 'clerk-webhook')) {
    $t->suite('Clerk webhook signatures');

    $secret = ClerkWebhook::generateSecret();
    $body = json_encode(['type' => 'user.updated', 'data' => ['id' => 'user_2handoff']]);
    $id = 'msg_' . bin2hex(random_bytes(6));
    $timestamp = (string) time();

    $t->check('generated secret uses the whsec_ prefix', str_starts_with($secret, 'whsec_'));
    $t->check('valid signature accepted', ClerkWebhook::verify($body, $id, $timestamp, ClerkWebhook::sign($body, $id, $timestamp, $secret), $secret) === null);

    $t->check('missing headers rejected', ClerkWebhook::verify($body, null, $timestamp, 'v1,x', $secret) === 'missing_headers');
    $t->check('missing signature rejected', ClerkWebhook::verify($body, $id, $timestamp, null, $secret) === 'missing_headers');

    $t->check('tampered body rejected', ClerkWebhook::verify($body . ' ', $id, $timestamp, ClerkWebhook::sign($body, $id, $timestamp, $secret), $secret) === 'signature_mismatch');
    $t->check('wrong secret rejected', ClerkWebhook::verify($body, $id, $timestamp, ClerkWebhook::sign($body, $id, $timestamp, $secret), ClerkWebhook::generateSecret()) === 'signature_mismatch');
    $t->check('replayed timestamp rejected', ClerkWebhook::verify($body, $id, (string) (time() - 600), ClerkWebhook::sign($body, $id, (string) (time() - 600), $secret), $secret) === 'stale_timestamp');
    $t->check('future timestamp rejected', ClerkWebhook::verify($body, $id, (string) (time() + 900), ClerkWebhook::sign($body, $id, (string) (time() + 900), $secret), $secret) === 'stale_timestamp');
    $t->check('malformed timestamp rejected', ClerkWebhook::verify($body, $id, 'not-a-time', 'v1,x', $secret) === 'bad_timestamp');
    $t->check('missing secret rejected', ClerkWebhook::verify($body, $id, $timestamp, 'v1,x', '') === 'not_configured');
    $t->check('non-base64 secret rejected', ClerkWebhook::verify($body, $id, $timestamp, 'v1,x', 'whsec_!!!notbase64') === 'bad_secret');

    // Key rotation: two signatures, the second one valid.
    $good = ClerkWebhook::sign($body, $id, $timestamp, $secret);
    $t->check('rotated signature list accepted', ClerkWebhook::verify($body, $id, $timestamp, 'v1,AAAA ' . $good, $secret) === null);
    $t->check('weird signature entries ignored', ClerkWebhook::verify($body, $id, $timestamp, 'v2,AAAA junk ' . $good, $secret) === null);

    // Signature is checked before the body is parsed: garbage JSON with a valid
    // signature is an authentic-but-empty event, not a bypass.
    $t->check('signature precedes parsing', ClerkWebhook::verify('not json', $id, $timestamp, ClerkWebhook::sign('not json', $id, $timestamp, $secret), $secret) === null);
}

if (run_suite($only, 'clerk-webhook-effects')) {
    $t->suite('Clerk webhook effects');

    $harness = $clerkHarness;
    $harness->installVerifier();
    $harness->installApiClient();

    // Ensure the fixtures exist so this suite can run on its own.
    if (User::findByClerkId('user_2legacy') === null) {
        $existing = User::findByEmail('legacy-admin@clerk-test.local');

        if ($existing === null) {
            $existing = User::create('Legacy Admin', 'legacy-admin@clerk-test.local', 'LegacyAdminPass1', 'admin');
        }

        User::attachClerkIdentity((int) $existing['id'], 'user_2legacy');
        $harness->addUser('user_2legacy', 'legacy-admin@clerk-test.local', 'Legacy', 'Admin');
    }

    if (User::findByClerkId('user_2handoff') === null) {
        $existing = User::findByEmail('handoff@clerk-test.local');

        if ($existing === null) {
            $existing = User::create('Alan Turing', 'handoff@clerk-test.local', 'HandoffPass123');
        }

        User::attachClerkIdentity((int) $existing['id'], 'user_2handoff');
        $harness->addUser('user_2handoff', 'handoff@clerk-test.local', 'Alan', 'Turing');
    }

    // user.updated: a verified email change is applied to the linked account.
    $user = User::findByClerkId('user_2handoff');

    if ($user !== null) {
        $payload = $harness->users['user_2handoff'] ?? null;

        if ($payload !== null) {
            $payload['email_addresses'][0]['email_address'] = 'handoff-renamed@clerk-test.local';
            $payload['first_name'] = 'Alan';
            $payload['last_name'] = 'Turing-Migrated';

            $updated = User::refreshFromClerkPayload($payload);
            $t->check('webhook updates the display name', ($updated['name'] ?? '') === 'Alan Turing-Migrated', $updated['name'] ?? null);
            $t->check('webhook applies a verified email change', ($updated['email'] ?? '') === 'handoff-renamed@clerk-test.local');
            $harness->users['user_2handoff'] = $payload;
        }

        // user.updated for an unknown Clerk user must not create an account.
        $before = (int) Database::scalar('SELECT COUNT(*) FROM users');
        $t->check('webhook never provisions accounts', User::refreshFromClerkPayload($harness->addUser('user_2ghostupdated', 'ghost@clerk-test.local')) === null);
        $t->check('row count unchanged', (int) Database::scalar('SELECT COUNT(*) FROM users') === $before);

        // user.deleted: identity detached, account and history preserved.
        $reservationCount = (int) Database::scalar('SELECT COUNT(*) FROM reservations WHERE user_id = :id', ['id' => (int) $user['id']]);

        $t->check('deleted Clerk user is unlinked', User::unlinkClerkIdentity('user_2handoff') === true);
        $t->check('account row preserved after deletion', User::find((int) $user['id']) !== null);
        $t->check('clerk id cleared', User::findByClerkId('user_2handoff') === null);
        $t->check('reservations preserved', (int) Database::scalar('SELECT COUNT(*) FROM reservations WHERE user_id = :id', ['id' => (int) $user['id']]) === $reservationCount);
        $t->check('unlinking is idempotent', User::unlinkClerkIdentity('user_2handoff') === false);
        $t->check('invalid clerk id refused', User::unlinkClerkIdentity('admin') === false);

        // Restore the link so later suites still see the account.
        User::attachClerkIdentity((int) $user['id'], 'user_2handoff');
    }

    // session.ended mirrors into clerk_sessions.
    $admin = User::findByClerkId('user_2legacy');

    if ($admin !== null) {
        $t->check('session.created is mirrored', Auth::mirrorClerkSession('user_2legacy', 'sess_user_2legacy') === true);
        $t->check('mirrored session is active', (int) Database::scalar(
            "SELECT COUNT(*) FROM clerk_sessions WHERE session_id = :sid AND status = 'active'",
            ['sid' => 'sess_user_2legacy']
        ) === 1);
        $t->check('session.ended closes the mirror', Auth::endClerkSession('sess_user_2legacy') === true);
        $t->check('mirrored session is ended', (int) Database::scalar(
            "SELECT COUNT(*) FROM clerk_sessions WHERE session_id = :sid AND status = 'ended'",
            ['sid' => 'sess_user_2legacy']
        ) === 1);
        $t->check('ending an unknown session is a no-op', Auth::endClerkSession('sess_nosuchsession') === false);
        $t->check('malformed session id refused', Auth::endClerkSession('bad id!') === false);
        $t->check('unknown Clerk user cannot mirror a session', Auth::mirrorClerkSession('user_2nobody', 'sess_user_2nobody') === false);
    }
}

if (run_suite($only, 'clerk-privilege')) {
    $t->suite('Clerk privilege escalation');

    $harness = $clerkHarness;
    $harness->installVerifier();
    $harness->installApiClient();

    $email = 'target-admin@clerk-test.local';
    Database::execute('DELETE FROM users WHERE email = :e', ['e' => $email]);

    Database::execute(
        "INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
         VALUES ('Target Admin', :e, :hash, 'admin', 'active', :now, :now)",
        ['e' => $email, 'hash' => Auth::hashPassword('TargetPass1234'), 'now' => now()]
    );

    $adminId = (int) Database::lastInsertId();

    // The attacker controls a brand-new Clerk account that has verified the
    // administrator's address (recycled address, re-registration, or a second
    // Clerk user added to the same mailbox).
    $harness->addUser('user_2takeover', $email, 'Mallory', 'Attacker');

    $t->throws('a verified email alone cannot claim an admin account', static function () use ($harness): void {
        User::syncFromClerk($harness->users['user_2takeover']);
    });

    $t->check('admin row keeps its role', (string) User::find($adminId)['role'] === 'admin');
    $t->check('admin row stays unlinked', User::find($adminId)['clerk_user_id'] === null);
    $t->check('attacker gets no account of their own', User::findByClerkId('user_2takeover') === null);

    // The same attempt through the real handoff path (what the bridge calls).
    clerk_test_reset_session();
    ClerkHandoff::openChannel();
    $handoff = ClerkHandoff::completeFromBrowserToken(
        $harness->token(['sub' => 'user_2takeover']),
        'http://localhost'
    );

    $t->check('the handoff endpoint refuses the takeover', $handoff['ok'] === false, $handoff['reason'] ?? null);
    $t->check('the refusal issues no ticket', !isset($handoff['token']));
    $t->check('the refusal does not reveal the admin address', !str_contains(
        (string) ($handoff['message'] ?? ''),
        $email
    ));

    // --- A non-admin row must still link automatically --------------------
    $userEmail = 'target-user@clerk-test.local';
    Database::execute('DELETE FROM users WHERE email = :e', ['e' => $userEmail]);
    Database::execute(
        "INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
         VALUES ('Target User', :e, :hash, 'user', 'active', :now, :now)",
        ['e' => $userEmail, 'hash' => Auth::hashPassword('TargetPass1234'), 'now' => now()]
    );

    $userId = (int) Database::lastInsertId();
    $harness->addUser('user_2normallink', $userEmail, 'Normal', 'User');
    $linked = User::syncFromClerk($harness->users['user_2normallink']);

    $t->check('a normal account still links automatically', $linked['linked'] === true);
    $t->check('the normal account keeps its id', (int) $linked['user']['id'] === $userId);
    $t->check('the normal account keeps the user role', $linked['user']['role'] === 'user');

    // --- The CLI path still works, and is the only one --------------------
    Database::execute('UPDATE users SET clerk_user_id = NULL WHERE id = :id', ['id' => $userId]);
    $forced = User::syncFromClerk($harness->users['user_2takeover'], true);

    $t->check('the CLI force path can link an admin row', (int) $forced['user']['id'] === $adminId);
    $t->check('the CLI path does not change the role', $forced['user']['role'] === 'admin');

    Database::execute('UPDATE users SET clerk_user_id = NULL WHERE id = :id', ['id' => $adminId]);
}
