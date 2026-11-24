<?php

declare(strict_types=1);

/**
 * Bind existing FreeSub accounts to Clerk users.
 *
 * Migration support only — the application also links accounts automatically
 * on first Clerk sign-in, but only when Clerk reports a *verified* email that
 * matches an existing row. This command lets an operator do it deliberately,
 * which is what you want for the administrator account.
 *
 *   php database/link-clerk.php --list
 *   php database/link-clerk.php --email=admin@example.com --clerk-id=user_2abc...
 *   php database/link-clerk.php --email=admin@example.com --clerk-email=admin@example.com
 *   php database/link-clerk.php --unlinked
 *
 * --clerk-email looks the Clerk user up through the Backend API, so the id is
 * never typed by hand and always comes from Clerk itself.
 *
 * Roles are never modified by this command.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/**
 * @param list<string> $argv
 */
function link_cli_option(array $argv, string $name): ?string
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return substr($argument, strlen($name) + 3);
        }
    }

    return null;
}

$arguments = array_slice($argv, 1);

if (in_array('--help', $arguments, true) || $arguments === []) {
    echo "Usage:\n";
    echo "  php database/link-clerk.php --list\n";
    echo "  php database/link-clerk.php --unlinked\n";
    echo "  php database/link-clerk.php --email=you@example.com --clerk-id=user_...\n";
    echo "  php database/link-clerk.php --email=you@example.com --clerk-email=you@example.com\n";
    exit(0);
}

if (in_array('--list', $arguments, true) || in_array('--unlinked', $arguments, true)) {
    $onlyUnlinked = in_array('--unlinked', $arguments, true);

    $rows = Database::select(
        'SELECT id, email, name, role, status, clerk_user_id, auth_provider
           FROM users
          ' . ($onlyUnlinked ? 'WHERE clerk_user_id IS NULL' : '') . '
          ORDER BY id ASC'
    );

    if ($rows === []) {
        echo "No accounts to show.\n";
        exit(0);
    }

    printf("%-4s %-32s %-8s %-24s %s\n", 'id', 'email', 'role', 'clerk_user_id', 'provider');

    foreach ($rows as $row) {
        printf(
            "%-4s %-32s %-8s %-24s %s\n",
            $row['id'],
            mb_strimwidth((string) $row['email'], 0, 32, '…'),
            $row['role'],
            $row['clerk_user_id'] ?? '—',
            $row['auth_provider'] ?? '—'
        );
    }

    exit(0);
}

$email = link_cli_option($arguments, 'email');
$clerkId = link_cli_option($arguments, 'clerk-id');
$clerkEmail = link_cli_option($arguments, 'clerk-email');

if ($email === null || ($clerkId === null && $clerkEmail === null)) {
    fwrite(STDERR, "Provide --email plus either --clerk-id or --clerk-email. See --help.\n");
    exit(1);
}

$user = User::findByEmail($email);

if ($user === null) {
    fwrite(STDERR, "No local account with that email.\n");
    exit(1);
}

if ($clerkEmail !== null) {
    $client = new ClerkApiClient();

    if (!$client->isConfigured()) {
        fwrite(STDERR, "CLERK_SECRET_KEY is required for --clerk-email lookups.\n");
        exit(1);
    }

    try {
        $list = $client->findUsersByEmail($clerkEmail);
    } catch (ClerkApiException $exception) {
        fwrite(STDERR, "Clerk lookup failed (status {$exception->status()}).\n");
        exit(2);
    }

    if ($list === []) {
        fwrite(STDERR, "Clerk has no user with that email. Sign in once with Clerk first.\n");
        exit(1);
    }

    if (count($list) > 1) {
        fwrite(STDERR, "Clerk returned several users for that email; pass --clerk-id explicitly.\n");
        exit(1);
    }

    $clerkId = (string) ($list[0]['id'] ?? '');
    echo "Resolved {$clerkEmail} -> {$clerkId}\n";
}

try {
    $linked = User::attachClerkIdentity((int) $user['id'], (string) $clerkId);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

audit_log(
    'admin.clerk_linked',
    (int) $user['id'],
    ['clerk_user_id' => $clerkId],
    'user',
    (int) $user['id'],
    'Clerk identity linked from the CLI'
);

echo "Linked {$linked['email']} (#{$linked['id']}, role {$linked['role']}) to {$clerkId}.\n";
echo "The account keeps its role and all reservations/subdomains.\n";
