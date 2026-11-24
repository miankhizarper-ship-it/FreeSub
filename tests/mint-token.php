<?php

declare(strict_types=1);

/**
 * Mint a Clerk-shaped session token with the committed fixture key.
 *
 * Test-only helper for the end-to-end HTTP run: in production the token is
 * minted by clerk-js inside the browser from the `freesub_handoff` JWT template.
 *
 *   php tests/mint-token.php <clerk_user_id> [ttl_seconds]
 */

require_once __DIR__ . '/support/ClerkTestHarness.php';

$sub = $argv[1] ?? 'user_2handoff';
$ttl = (int) ($argv[2] ?? 60);

echo (new ClerkTestHarness())->token([
    'sub' => $sub,
    'azp' => getenv('FREESUB_E2E_ORIGIN') ?: 'http://localhost:8099',
    'iss' => 'https://' . (new ClerkTestHarness())->instanceHost(),
    'exp' => time() + $ttl,
]);
