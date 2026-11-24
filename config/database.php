<?php

declare(strict_types=1);

/**
 * Database configuration.
 *
 * Production uses MySQL/MariaDB through PDO. SQLite exists only as a
 * developer convenience so the schema and repositories can be exercised
 * without a MySQL server; it is never used automatically in production.
 */

require_once __DIR__ . '/env.php';

$driver = strtolower((string) env('DB_DRIVER', 'mysql'));

$sqlitePath = (string) env('DB_SQLITE_PATH', 'storage/database.sqlite');

if ($sqlitePath !== '' && !str_starts_with($sqlitePath, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $sqlitePath)) {
    $sqlitePath = (defined('FREESUB_ROOT') ? FREESUB_ROOT : dirname(__DIR__)) . '/' . ltrim($sqlitePath, '/');
}

return [
    'driver' => $driver,
    'host' => (string) env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => (string) env('DB_DATABASE', 'freesub'),
    'username' => (string) env('DB_USERNAME', 'freesub'),
    'password' => (string) env('DB_PASSWORD', ''),
    'charset' => (string) env('DB_CHARSET', 'utf8mb4'),
    'collation' => 'utf8mb4_unicode_ci',
    'sqlite_path' => $sqlitePath,
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ],
];
