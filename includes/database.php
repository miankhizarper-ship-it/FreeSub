<?php

declare(strict_types=1);

/**
 * PDO connection factory.
 *
 * One lazily created connection per request. MySQL/MariaDB is the primary
 * driver; SQLite is supported for local development only so the repositories
 * and schema can be exercised without a database server.
 */
final class Database
{
    private static ?PDO $connection = null;

    /** @var array<string, mixed> */
    private static array $config = [];

    private static bool $sqliteConstraintsEnabled = false;

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        if (self::$config === []) {
            self::$config = require FREESUB_ROOT . '/config/database.php';
        }

        return self::$config;
    }

    public static function isSqlite(): bool
    {
        return (self::config()['driver'] ?? 'mysql') === 'sqlite';
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = self::config();
        $driver = (string) ($config['driver'] ?? 'mysql');

        try {
            if ($driver === 'sqlite') {
                $path = (string) $config['sqlite_path'];
                $directory = dirname($path);

                if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                    throw new RuntimeException('Unable to create the SQLite storage directory.');
                }

                $pdo = new PDO('sqlite:' . $path, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA busy_timeout = 5000');
                $pdo->exec('PRAGMA foreign_keys = ON');
                self::$sqliteConstraintsEnabled = true;
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $config['host'],
                    (int) $config['port'],
                    $config['database'],
                    $config['charset']
                );

                $pdo = new PDO($dsn, (string) $config['username'], (string) $config['password'], $config['options']);

                // Session-level hardening; ignored gracefully by older servers.
                $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
                $pdo->exec("SET SESSION time_zone = '+00:00'");
            }
        } catch (PDOException $exception) {
            // Never leak credentials or DSN details to the browser.
            error_log('[freesub] database connection failed: ' . $exception->getMessage());

            throw new RuntimeException('Database connection failed. Check your configuration.', 0, $exception);
        }

        self::$connection = $pdo;

        return self::$connection;
    }

    /**
     * Execute a prepared statement and return the statement.
     *
     * @param array<int|string, mixed> $params
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::connection()->lastInsertId();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback($pdo);

            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Translate driver specific SQLSTATE/error codes into stable application
     * error codes understood by the repositories.
     */
    public static function errorCode(Throwable $exception): string
    {
        if (!$exception instanceof PDOException) {
            return 'unknown';
        }

        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $sqlState = (string) ($exception->getCode() ?: ($exception->errorInfo[0] ?? ''));

        if ($driverCode === 1062 || $sqlState === '23000' || str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
            return 'duplicate';
        }

        if ($driverCode === 1452 || $driverCode === 1451 || str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed')) {
            return 'foreign_key';
        }

        return 'database';
    }

    /** Testing helper: forget the current connection. */
    public static function reset(): void
    {
        self::$connection = null;
        self::$sqliteConstraintsEnabled = false;
    }

    public static function isSqliteConstraintsEnabled(): bool
    {
        return self::$sqliteConstraintsEnabled;
    }
}
