<?php

declare(strict_types=1);

/**
 * Router for the end-to-end HTTP test run.
 *
 * Identical to router.php except that it installs the same Clerk test double
 * the unit suite uses (committed fixture RSA key + in-memory Backend API), so
 * the real login/handoff/webhook code can be exercised over HTTP without a live
 * Clerk instance or network access.
 *
 * This file is only used by `tests/http-clerk.sh`; production traffic never
 * loads it.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/support/ClerkTestHarness.php';

$harness = new ClerkTestHarness();

// Authorized parties must match the origin the browser actually used, which the
// test client controls per request.
//
// The origin comes from FREESUB_E2E_ORIGIN (exported by tests/http-clerk.sh for
// whatever port was requested). Hard-coding a port here made `tests/http-clerk.sh
// 8100` fail with `origin_rejected` on every bridge call — a harness defect that
// looked exactly like an authentication regression.
$origin = rtrim((string) (getenv('FREESUB_E2E_ORIGIN') ?: 'http://localhost:8099'), '/');
$port = (string) (parse_url($origin, PHP_URL_PORT) ?: '8099');

$GLOBALS['FREESUB_CONFIG_OVERRIDE'] = [
    'clerk' => [
        'authorized_parties' => array_values(array_unique([
            $origin,
            'http://localhost:' . $port,
            'http://127.0.0.1:' . $port,
        ])),
    ],
];

$harness->installVerifier();
$harness->installApiClient();

// Cloudflare is intentionally NOT installed here: the DNS diagnostics page must
// work with no provider configured, and no test in this run may reach a zone.
// (The DNS engine itself is covered by tests/dns-suite.php with its own double.)
CloudflareApiClient::setDefaultTransport(null);
$harness->addUser('user_2legacy', 'legacy-admin@clerk-test.local', 'Legacy', 'Admin');
$harness->addUser('user_2handoff', 'handoff@clerk-test.local', 'Alan', 'Turing');

$GLOBALS['FREESUB_E2E_CLERK'] = $harness;

require dirname(__DIR__) . '/router.php';
