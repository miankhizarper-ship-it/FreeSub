<?php

declare(strict_types=1);

/**
 * Verify the Clerk configuration without leaking any secret.
 *
 *   php database/clerk-check.php
 *
 * Exit codes: 0 = usable, 2 = incomplete configuration, 3 = Clerk unreachable
 * or the secret key was rejected.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

echo "FreeSub — Clerk configuration check\n";
echo str_repeat('-', 62) . "\n";

$ok = true;

foreach (ClerkConfig::diagnostics() as $key => $diagnostic) {
    if (!$diagnostic['ok'] && $key !== 'webhook_secret') {
        $ok = false;
    }

    printf("  %-20s %-5s %s\n", $key, $diagnostic['ok'] ? 'OK' : 'MISS', $diagnostic['detail']);
}

echo str_repeat('-', 62) . "\n";

// The dependency that is easiest to get wrong: the JWT template the browser
// mints the handoff token from must exist in this Clerk instance.
$template = (string) config('clerk.jwt_template', 'freesub_handoff');
echo "  JWT template expected: {$template}\n";
echo "  Create it in the Clerk dashboard under Configure → JWT Templates, with\n";
echo "  claims including the default session claims (sub, sid, exp, iss, azp).\n\n";

if (!ClerkConfig::frontendApiHost()) {
    echo "The frontend API host could not be derived from CLERK_PUBLISHABLE_KEY.\n";
    echo "Check that the key looks like pk_test_<base64> or pk_live_<base64>.\n";
    exit(2);
}

if (!ClerkConfig::secretKeyConfigured()) {
    echo "CLERK_SECRET_KEY is missing. The browser can still render the sign-in form,\n";
    echo "but the server cannot finish the handoff, so nobody can sign in.\n";
    echo "Copy it from the Clerk dashboard (API keys) into .env — never commit it.\n";
    exit(2);
}

$client = new ClerkApiClient();

try {
    $me = $client->me();
} catch (ClerkApiException $exception) {
    echo "The Clerk Backend API is unreachable from this server (status {$exception->status()}).\n";
    echo "Check outbound HTTPS access to " . (string) config('clerk.backend_api_url', 'https://api.clerk.com') . ".\n";
    exit(3);
}

if ($me === null) {
    echo "The Clerk Backend API rejected the secret key (401/403).\n";
    echo "Confirm the key belongs to this instance and has not been rolled.\n";
    exit(3);
}

$instance = (string) ($me['instance_id'] ?? $me['id'] ?? '');
echo "  Secret key accepted" . ($instance !== '' ? " (instance " . substr($instance, 0, 12) . "…)" : '') . "\n";

echo "\nConfiguration looks usable. Next: php database/link-clerk.php (optional)\n";

exit($ok ? 0 : 2);
