<?php

declare(strict_types=1);

/**
 * CLI helper: create (or promote) an administrator account.
 *
 * Usage:
 *   php database/create-admin.php "Ada Lovelace" ada@example.com
 *   php database/create-admin.php "Ada Lovelace" ada@example.com --promote
 *
 * The password is read from the terminal without echoing. For unattended
 * provisioning you may pass --password=...; prefer the interactive prompt.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$promote = in_array('--promote', array_slice($argv, 1), true);

$name = $args[0] ?? null;
$email = $args[1] ?? null;

if ($name === null || $email === null) {
    fwrite(STDERR, "Usage: php database/create-admin.php \"Full Name\" email@example.com [--promote]\n");
    exit(1);
}

if (Validator::email($email) !== null) {
    fwrite(STDERR, "That email address is not valid.\n");
    exit(1);
}

$email = mb_strtolower($email);
$existing = User::findByEmail($email);

if ($existing !== null) {
    if (!$promote) {
        fwrite(STDERR, "A user with that email already exists. Pass --promote to make them an administrator.\n");
        exit(1);
    }

    Database::execute("UPDATE users SET role = 'admin', status = 'active', updated_at = :now WHERE id = :id", [
        'now' => now(),
        'id' => (int) $existing['id'],
    ]);

    audit_log('admin.promoted', (int) $existing['id'], [], 'user', (int) $existing['id'], 'Promoted to administrator via CLI');
    echo "Promoted {$email} to administrator.\n";
    exit(0);
}

$password = null;

// Optional non-interactive use: --password=...
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--password=')) {
        $password = substr($argument, strlen('--password='));
    }
}

if ($password === null) {
    fwrite(STDOUT, "Password (min 10 chars, hidden): ");

    $isTty = function_exists('posix_isatty') ? @posix_isatty(STDIN) : true;

    if ($isTty) {
        @shell_exec('stty -echo');
    }

    $password = trim((string) fgets(STDIN));

    if ($isTty) {
        @shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
}

$error = Validator::password($password);

if ($error !== null) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}

$user = User::create($name, $email, $password, 'admin');

audit_log('admin.created', (int) $user['id'], [], 'user', (int) $user['id'], 'Administrator created via CLI');

echo "Administrator created: {$user['email']} (#{$user['id']}).\n";
