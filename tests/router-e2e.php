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
$GLOBALS['FREESUB_CONFIG_OVERRIDE'] = [
    'clerk' => [
        'authorized_parties' => array_values(array_filter([
            'http://localhost:8099',
            'http://127.0.0.1:8099',
        ])),
    ],
];

$harness->installVerifier();
$harness->installApiClient();
$harness->addUser('user_2legacy', 'legacy-admin@clerk-test.local', 'Legacy', 'Admin');
$harness->addUser('user_2handoff', 'handoff@clerk-test.local', 'Alan', 'Turing');

$GLOBALS['FREESUB_E2E_CLERK'] = $harness;

require dirname(__DIR__) . '/router.php';
