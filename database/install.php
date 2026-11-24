<?php

declare(strict_types=1);

/**
 * CLI installer: imports schema.sql and reports what it found.
 *
 * Usage:
 *   php database/install.php
 *   php database/install.php --fresh     (drops and recreates all tables)
 *
 * "--fresh" is destructive and refuses to run when any user exists unless
 * --force is passed, so existing installations are never wiped accidentally.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

define('FREESUB_ALLOW_CLI', true);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$args = array_slice($argv, 1);
$fresh = in_array('--fresh', $args, true);
$force = in_array('--force', $args, true);

$pdo = Database::connection();
$driver = Database::isSqlite() ? 'sqlite' : 'mysql';

if ($fresh) {
    $existingUsers = 0;

    try {
        $existingUsers = (int) Database::scalar('SELECT COUNT(*) FROM users');
    } catch (Throwable) {
        $existingUsers = 0;
    }

    if ($existingUsers > 0 && !$force) {
        fwrite(STDERR, "Refusing to wipe {$existingUsers} existing user(s). Re-run with --force if you are sure.\n");
        exit(1);
    }

    $tables = ['abuse_reports', 'dns_records', 'subdomains', 'reservations', 'audit_logs', 'domains', 'users'];

    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = OFF');
    } else {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    }

    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }

    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    echo "Dropped existing tables.\n";
}

$schemaPath = $driver === 'sqlite'
    ? __DIR__ . '/schema.sqlite.sql'
    : __DIR__ . '/schema.sql';

if (!is_file($schemaPath)) {
    fwrite(STDERR, "Schema file not found: {$schemaPath}\n");
    exit(1);
}

$sql = (string) file_get_contents($schemaPath);

// The MySQL schema carries CLI-only statements that PDO cannot execute.
$sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b.*?;\s*$/mis', '', $sql) ?? $sql;
$sql = preg_replace('/^\s*SET (NAMES|time_zone)\b.*?;\s*$/mis', '', $sql) ?? $sql;

// PDO::exec only runs a single statement on most drivers, so execute the
// schema statement by statement (the files contain no stored procedures).
$statements = array_filter(
    array_map('trim', preg_split('/;\s*[\r\n]+/', $sql) ?: []),
    static fn (string $statement): bool => $statement !== ''
        && !str_starts_with($statement, '--')
        && !str_starts_with($statement, 'PRAGMA')
);

foreach ($statements as $statement) {
    $pdo->exec($statement);
}

$tables = ['users', 'domains', 'subdomains', 'dns_records', 'reservations', 'audit_logs', 'abuse_reports'];
$missing = [];

foreach ($tables as $table) {
    try {
        $count = (int) Database::scalar('SELECT COUNT(*) FROM ' . $table);
        printf("  %-14s ok (%d rows)\n", $table, $count);
    } catch (Throwable $exception) {
        $missing[] = $table;
        printf("  %-14s MISSING\n", $table);
    }
}

$domains = Database::select('SELECT root_domain, registration_status, is_active FROM domains ORDER BY id');

echo "\nRoot domains:\n";

foreach ($domains as $domain) {
    printf("  - %s (status: %s, active: %s)\n", $domain['root_domain'], $domain['registration_status'], ((int) $domain['is_active']) === 1 ? 'yes' : 'no');
}

if ($missing !== []) {
    fwrite(STDERR, "\nSchema import incomplete. Missing: " . implode(', ', $missing) . "\n");
    exit(1);
}

echo "\nSchema ready on {$driver}. Create an administrator with:\n";
echo "  php database/create-admin.php \"Admin Name\" admin@example.com\n";
