<?php

declare(strict_types=1);

/**
 * Apply pending migrations to an existing installation.
 *
 *   php database/migrate.php            apply (safe to re-run)
 *   php database/migrate.php --status   show what is already applied
 *
 * Two migrations are covered:
 *   2026_11_001  Clerk identity (users.clerk_user_id, clerk_handoffs, ...)
 *   2026_11_002  DNS engine (dns_records.domain_id, dns_records.last_synced_at)
 *
 * It never drops a column, never deletes a row and never touches users.role.
 * On MySQL/MariaDB each step is guarded by an information_schema check, because
 * `ADD COLUMN IF NOT EXISTS` is not portable across the supported versions.
 *
 * SQLite (development) is rebuilt from database/schema.sqlite.sql when the
 * users table is missing the new columns; the table is copied, not recreated.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$statusOnly = in_array('--status', array_slice($argv, 1), true);
$driver = Database::isSqlite() ? 'sqlite' : 'mysql';

echo "FreeSub — pending migrations\n";
echo "Driver: {$driver}\n\n";

/**
 * @return list<string>
 */
function sqlite_columns(string $table): array
{
    $columns = [];

    foreach (Database::select('PRAGMA table_info(' . $table . ')') as $row) {
        $columns[] = (string) $row['name'];
    }

    return $columns;
}

function sqlite_index_exists(string $name): bool
{
    return (int) Database::scalar(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :name",
        ['name' => $name]
    ) > 0;
}

function mysql_has_column(string $table, string $column): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
        ['t' => $table, 'c' => $column]
    ) > 0;
}

function mysql_has_index(string $table, string $index): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i',
        ['t' => $table, 'i' => $index]
    ) > 0;
}

function mysql_column_nullable(string $table, string $column): bool
{
    return (string) Database::scalar(
        'SELECT is_nullable FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
        ['t' => $table, 'c' => $column]
    ) === 'YES';
}

$applied = 0;
$skipped = 0;
$failed = 0;

$step = static function (string $label, callable $action) use (&$applied, &$skipped, &$failed, $statusOnly): void {
    try {
        if ($action() === false) {
            echo "  = {$label} (already applied)\n";
            $skipped++;

            return;
        }

        echo $statusOnly ? "  ? {$label} (would apply)\n" : "  + {$label}\n";
        $applied++;
    } catch (Throwable $exception) {
        echo "  ! {$label}: {$exception->getMessage()}\n";
        $failed++;
    }
};

if ($driver === 'sqlite') {
    $columns = sqlite_columns('users');

    $step('users.clerk_user_id', static function () use ($columns, $statusOnly): bool {
        if (in_array('clerk_user_id', $columns, true)) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('ALTER TABLE users ADD COLUMN clerk_user_id VARCHAR(191) NULL');
        }

        return true;
    });

    $step('users.username', static function () use ($columns, $statusOnly): bool {
        if (in_array('username', $columns, true)) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('ALTER TABLE users ADD COLUMN username VARCHAR(64) NULL');
        }

        return true;
    });

    $step('users.avatar_url', static function () use ($columns, $statusOnly): bool {
        if (in_array('avatar_url', $columns, true)) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) NULL');
        }

        return true;
    });

    $step('users.auth_provider', static function () use ($columns, $statusOnly): bool {
        if (in_array('auth_provider', $columns, true)) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute("ALTER TABLE users ADD COLUMN auth_provider VARCHAR(32) NOT NULL DEFAULT 'clerk'");
        }

        return true;
    });

    $step('unique index on users.clerk_user_id', static function () use ($statusOnly): bool {
        if (sqlite_index_exists('users_clerk_user_id_unique')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('CREATE UNIQUE INDEX IF NOT EXISTS users_clerk_user_id_unique ON users (clerk_user_id)');
        }

        return true;
    });

    $step('password_hash nullable', static function () use ($statusOnly): bool {
        // SQLite cannot widen a column in place, so the users table is rebuilt
        // inside a transaction: ids are preserved, every row is copied, and the
        // row count is verified before the transaction is committed.
        $notNull = false;

        foreach (Database::select('PRAGMA table_info(users)') as $row) {
            if ((string) $row['name'] === 'password_hash' && (int) $row['notnull'] === 1) {
                $notNull = true;
            }
        }

        if (!$notNull) {
            return false;
        }

        if ($statusOnly) {
            return true;
        }

        $before = (int) Database::scalar('SELECT COUNT(*) FROM users');

        Database::transaction(static function (): void {
            // Foreign keys off for the swap: child tables keep pointing at the
            // same ids, and SQLite would otherwise refuse the rename.
            Database::execute('PRAGMA foreign_keys = OFF');
            Database::execute('DROP TABLE IF EXISTS users_pre_clerk');
            Database::execute('ALTER TABLE users RENAME TO users_pre_clerk');
            Database::execute('CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NULL,
                clerk_user_id VARCHAR(191) NULL,
                username VARCHAR(64) NULL,
                avatar_url VARCHAR(500) NULL,
                auth_provider VARCHAR(32) NOT NULL DEFAULT \'clerk\',
                role TEXT NOT NULL DEFAULT \'user\' CHECK (role IN (\'user\', \'admin\')),
                status TEXT NOT NULL DEFAULT \'active\' CHECK (status IN (\'active\', \'suspended\', \'pending\')),
                email_verified_at TEXT NULL,
                last_login_at TEXT NULL,
                last_login_ip VARCHAR(45) NULL,
                failed_login_attempts INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            Database::execute(
                'INSERT INTO users (id, name, email, password_hash, clerk_user_id, username, avatar_url,
                                    auth_provider, role, status, email_verified_at, last_login_at,
                                    last_login_ip, failed_login_attempts, locked_until, created_at, updated_at)
                 SELECT id, name, email, password_hash,
                        COALESCE(clerk_user_id, NULL), COALESCE(username, NULL), COALESCE(avatar_url, NULL),
                        COALESCE(auth_provider, \'clerk\'), role, status, email_verified_at, last_login_at,
                        last_login_ip, failed_login_attempts, locked_until, created_at, updated_at
                   FROM users_pre_clerk'
            );
            Database::execute('DROP TABLE users_pre_clerk');
            Database::execute('CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (email)');
            Database::execute('CREATE UNIQUE INDEX IF NOT EXISTS users_clerk_user_id_unique ON users (clerk_user_id)');
            Database::execute('CREATE INDEX IF NOT EXISTS users_status_index ON users (status)');
            Database::execute('CREATE INDEX IF NOT EXISTS users_role_index ON users (role)');
            Database::execute('PRAGMA foreign_keys = ON');
        });

        $after = (int) Database::scalar('SELECT COUNT(*) FROM users');

        if ($before !== $after) {
            throw new RuntimeException("row count changed while rebuilding users ({$before} -> {$after})");
        }

        return true;
    });

    $step('clerk_handoffs table', static function () use ($statusOnly): bool {
        if ((int) Database::scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='clerk_handoffs'") > 0) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('CREATE TABLE clerk_handoffs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash CHAR(64) NOT NULL,
                user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                clerk_user_id VARCHAR(191) NULL,
                state TEXT NOT NULL DEFAULT \'pending\' CHECK (state IN (\'pending\', \'consumed\')),
                attempts INTEGER NOT NULL DEFAULT 0,
                expires_at TEXT NOT NULL,
                consumed_at TEXT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            Database::execute('CREATE UNIQUE INDEX IF NOT EXISTS clerk_handoffs_token_hash_unique ON clerk_handoffs (token_hash)');
            Database::execute('CREATE INDEX IF NOT EXISTS clerk_handoffs_expires_at_index ON clerk_handoffs (expires_at)');
        }

        return true;
    });

    $step('clerk_sessions table', static function () use ($statusOnly): bool {
        if ((int) Database::scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='clerk_sessions'") > 0) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('CREATE TABLE clerk_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id VARCHAR(191) NOT NULL,
                user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                status VARCHAR(32) NOT NULL DEFAULT \'active\',
                expire_at TEXT NULL,
                last_verified_at TEXT NULL,
                ended_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            Database::execute('CREATE UNIQUE INDEX IF NOT EXISTS clerk_sessions_session_id_unique ON clerk_sessions (session_id)');
            Database::execute('CREATE INDEX IF NOT EXISTS clerk_sessions_user_id_index ON clerk_sessions (user_id)');
        }

        return true;
    });

    /* -- 2026_11_002 — DNS engine -------------------------------------- */

    $dnsColumns = static function (): array {
        $columns = [];

        foreach (Database::select('PRAGMA table_info(dns_records)') as $row) {
            $columns[] = (string) $row['name'];
        }

        return $columns;
    };

    $step('dns_records.domain_id', static function () use ($dnsColumns, $statusOnly): bool {
        if (in_array('domain_id', $dnsColumns(), true)) {
            return false;
        }

        if (!$statusOnly) {
            // SQLite cannot add a column with a REFERENCES clause; the column is
            // added plainly and backfilled from the owning subdomain. Integrity is
            // enforced by the application and by the fresh-install schema.
            Database::execute('ALTER TABLE dns_records ADD COLUMN domain_id INTEGER NULL');
            Database::execute(
                'UPDATE dns_records
                    SET domain_id = (SELECT s.domain_id FROM subdomains s WHERE s.id = dns_records.subdomain_id)
                  WHERE domain_id IS NULL'
            );
        }

        return true;
    });

    $step('dns_records.last_synced_at', static function () use ($dnsColumns, $statusOnly): bool {
        if (in_array('last_synced_at', $dnsColumns(), true)) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('ALTER TABLE dns_records ADD COLUMN last_synced_at TEXT NULL');
        }

        return true;
    });

    $step('dns_records domain index', static function () use ($statusOnly): bool {
        if (sqlite_index_exists('dns_records_domain_index')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('CREATE INDEX IF NOT EXISTS dns_records_domain_index ON dns_records (domain_id)');
        }

        return true;
    });

    $step('dns_records provider record index', static function () use ($statusOnly): bool {
        if (sqlite_index_exists('dns_records_provider_record_index')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('CREATE INDEX IF NOT EXISTS dns_records_provider_record_index ON dns_records (provider_record_id)');
        }

        return true;
    });
} else {
    $step('users.clerk_user_id', static function () use ($statusOnly): bool {
        if (mysql_has_column('users', 'clerk_user_id')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('ALTER TABLE users ADD COLUMN clerk_user_id VARCHAR(191) NULL DEFAULT NULL COMMENT \'Verified Clerk user id (sub)\'');
        }

        return true;
    });

    $step('users.username', static function () use ($statusOnly): bool {
        if (mysql_has_column('users', 'username')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('ALTER TABLE users ADD COLUMN username VARCHAR(64) NULL DEFAULT NULL');
        }

        return true;
    });

    $step('users.avatar_url', static function () use ($statusOnly): bool {
        if (mysql_has_column('users', 'avatar_url')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) NULL DEFAULT NULL');
        }

        return true;
    });

    $step('users.auth_provider', static function () use ($statusOnly): bool {
        if (mysql_has_column('users', 'auth_provider')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute("ALTER TABLE users ADD COLUMN auth_provider VARCHAR(32) NOT NULL DEFAULT 'clerk'");
        }

        return true;
    });

    $step('unique index on users.clerk_user_id', static function () use ($statusOnly): bool {
        if (mysql_has_index('users', 'users_clerk_user_id_unique')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('ALTER TABLE users ADD UNIQUE KEY users_clerk_user_id_unique (clerk_user_id)');
        }

        return true;
    });

    $step('password_hash nullable', static function () use ($statusOnly): bool {
        if (mysql_column_nullable('users', 'password_hash')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL');
        }

        return true;
    });

    $step('clerk_handoffs table', static function () use ($statusOnly): bool {
        if ((int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => 'clerk_handoffs']
        ) > 0) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('CREATE TABLE clerk_handoffs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash CHAR(64) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                clerk_user_id VARCHAR(191) NULL DEFAULT NULL,
                state ENUM(\'pending\', \'consumed\') NOT NULL DEFAULT \'pending\',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL DEFAULT NULL,
                ip_address VARCHAR(45) NULL DEFAULT NULL,
                user_agent VARCHAR(255) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY clerk_handoffs_token_hash_unique (token_hash),
                KEY clerk_handoffs_expires_at_index (expires_at),
                CONSTRAINT clerk_handoffs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }

        return true;
    });

    $step('clerk_sessions table', static function () use ($statusOnly): bool {
        if ((int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => 'clerk_sessions']
        ) > 0) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('CREATE TABLE clerk_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id VARCHAR(191) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT \'active\',
                expire_at DATETIME NULL DEFAULT NULL,
                last_verified_at DATETIME NULL DEFAULT NULL,
                ended_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY clerk_sessions_session_id_unique (session_id),
                KEY clerk_sessions_user_id_index (user_id),
                CONSTRAINT clerk_sessions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }

        return true;
    });

    /* -- 2026_11_002 — DNS engine -------------------------------------- */

    $step('dns_records.domain_id', static function () use ($statusOnly): bool {
        if (mysql_has_column('dns_records', 'domain_id')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute(
                'ALTER TABLE dns_records
                   ADD COLUMN domain_id BIGINT UNSIGNED NULL DEFAULT NULL
                   COMMENT \'Root domain (zone) this record belongs to\' AFTER id'
            );
            Database::execute(
                'UPDATE dns_records
                   JOIN subdomains ON subdomains.id = dns_records.subdomain_id
                    SET dns_records.domain_id = subdomains.domain_id
                  WHERE dns_records.domain_id IS NULL'
            );
            Database::execute(
                'ALTER TABLE dns_records
                   ADD CONSTRAINT dns_records_domain_id_foreign FOREIGN KEY (domain_id)
                   REFERENCES domains (id) ON DELETE SET NULL ON UPDATE CASCADE'
            );
        }

        return true;
    });

    $step('dns_records.last_synced_at', static function () use ($statusOnly): bool {
        if (mysql_has_column('dns_records', 'last_synced_at')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute(
                'ALTER TABLE dns_records
                   ADD COLUMN last_synced_at TIMESTAMP NULL DEFAULT NULL
                   COMMENT \'When the remote record was last compared with this row\' AFTER last_error'
            );
        }

        return true;
    });

    $step('dns_records domain index', static function () use ($statusOnly): bool {
        if (mysql_has_index('dns_records', 'dns_records_domain_index')) {
            return false;
        }

        if (!$statusOnly) {
            Database::execute('ALTER TABLE dns_records ADD KEY dns_records_domain_index (domain_id)');
        }

        return true;
    });
}

echo "\n";

$users = (int) Database::scalar('SELECT COUNT(*) FROM users');
$linked = (int) Database::scalar('SELECT COUNT(*) FROM users WHERE clerk_user_id IS NOT NULL');
$admins = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'");

echo "Accounts:        {$users}\n";
echo "Linked to Clerk: {$linked}\n";
echo "Administrators:  {$admins}\n";
echo "Applied: {$applied}  Already present: {$skipped}  Failed: {$failed}\n";

if ($failed > 0) {
    echo "\nSome steps failed. Fix the reported problem and run the command again.\n";
    exit(1);
}

if (!$statusOnly) {
    echo "\nNext:\n";
    echo "  1. php database/clerk-check.php      verify the Clerk configuration\n";
    echo "  2. php database/link-clerk.php       bind existing accounts to Clerk ids\n";
    echo "  3. php database/dns-sync.php --dry-run   inspect DNS state (read-only)\n";
}

exit(0);
