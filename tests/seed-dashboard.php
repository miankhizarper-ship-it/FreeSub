<?php

declare(strict_types=1);

/**
 * Task 05 preview harness.
 *
 * Seeds a throwaway SQLite database with a realistic account — a verified
 * identity, an active subdomain with a verified DNS record, a pending one with a
 * queued job, a failed one with a retry-shaped job, a released one that must not
 * appear — plus reservations in every state and a *second* account whose rows
 * must never be reachable.
 *
 * Then it signs a real PHP session in (writing the same keys Auth reads) and
 * prints the URLs to open. It writes nothing into the repository: the database
 * lives under storage/ (gitignored) and is deleted by --reset.
 *
 * Usage:
 *   php tests/seed-dashboard.php            # seed and print urls
 *   php tests/seed-dashboard.php --reset    # delete the preview database
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);

define('FREESUB_ROOT', $root);

$reset = in_array('--reset', $argv ?? [], true);

// Resolve the path exactly the way the application does, so the file this script
// seeds is the file the app will read. A plain getenv() fallback silently
// disagreed with config/database.php whenever DB_SQLITE_PATH was set.
$dbConfig = require $root . '/config/database.php';
$dbPath = (string) ($dbConfig['sqlite_path'] ?? '');
$sessionDir = $root . '/storage/sessions';

if ($dbPath === '' || ($dbConfig['driver'] ?? '') !== 'sqlite') {
    fwrite(STDERR, "This harness needs DB_DRIVER=sqlite and a DB_SQLITE_PATH. It refuses to touch a MySQL install.\n");
    exit(1);
}

if ($reset) {
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    foreach (glob($sessionDir . '/preview-*') ?: [] as $file) {
        unlink($file);
    }

    echo "Preview database removed.\n";
    exit(0);
}

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $dbPath);
putenv('SESSION_DRIVER=file');
putenv('SESSION_PATH=' . $sessionDir);

$_ENV['DB_DRIVER'] = 'sqlite';
$_ENV['DB_SQLITE_PATH'] = $dbPath;

if (!is_file($dbPath)) {
    touch($dbPath);
}

require_once $root . '/includes/bootstrap.php';

// ---------------------------------------------------------------------------
// Preconditions — the shipped schema + migrations must already be in place
// ---------------------------------------------------------------------------

// Created by:
//   DB_SQLITE_PATH=… php database/install.php --fresh --force
//   DB_SQLITE_PATH=… php database/migrate.php
// Both are the real shipped paths, so a preview never runs against a schema
// that the application would not accept.
$required = ['users', 'domains', 'subdomains', 'reservations', 'dns_records', 'provisioning_jobs', 'audit_logs'];

foreach ($required as $table) {
    try {
        Database::scalar('SELECT COUNT(*) FROM ' . $table);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Table `{$table}` is missing in {$dbPath}.\n"
            . "Run: DB_SQLITE_PATH={$dbPath} php database/install.php --fresh --force\n"
            . "then: DB_SQLITE_PATH={$dbPath} php database/migrate.php\n");
        exit(1);
    }
}

if (SubdomainQuota::limit(User::find(1) ?? ['id' => 0], null) < 1) {
    fwrite(STDERR, "Migrations have not been applied (users.subdomain_quota is missing).\n");
    exit(1);
}

foreach ($required as $table) {
    Database::execute('DELETE FROM ' . $table);
}

try {
    Database::execute("DELETE FROM sqlite_sequence WHERE name IN ('users','domains','subdomains','reservations','dns_records','provisioning_jobs','audit_logs')");
} catch (Throwable) {
    // sqlite_sequence only exists once an AUTOINCREMENT row has been written.
}

$pdo = Database::connection();
$pdo->exec('PRAGMA foreign_keys = ON');

// ---------------------------------------------------------------------------
// Accounts
// ---------------------------------------------------------------------------

$now = gmdate('Y-m-d H:i:s');

Database::execute(
    'INSERT INTO users (id, name, email, clerk_user_id, role, status, email_verified_at, last_login_at, subdomain_quota, created_at, updated_at)
     VALUES (:id, :name, :email, :clerk, :role, :status, :verified, :login, :quota, :created, :updated)',
    [
        'id' => 1, 'name' => 'Ayesha Khan', 'email' => 'ayesha@example.test',
        'clerk' => 'user_preview_owner', 'role' => 'user', 'status' => 'active',
        'verified' => $now, 'login' => $now, 'quota' => null,
        'created' => gmdate('Y-m-d H:i:s', time() - 86400 * 120), 'updated' => $now,
    ]
);

Database::execute(
    'INSERT INTO users (id, name, email, clerk_user_id, role, status, email_verified_at, subdomain_quota, created_at, updated_at)
     VALUES (:id, :name, :email, :clerk, :role, :status, :verified, :quota, :created, :updated)',
    [
        'id' => 2, 'name' => 'Other Person', 'email' => 'other@example.test',
        'clerk' => 'user_preview_other', 'role' => 'user', 'status' => 'active',
        'verified' => $now, 'quota' => null,
        'created' => gmdate('Y-m-d H:i:s', time() - 86400 * 60), 'updated' => $now,
    ]
);

Database::execute(
    'INSERT INTO domains (id, root_domain, extension, label, description, dns_provider, nameservers, is_active, is_public, registration_status, subdomain_quota, sort_order, created_at, updated_at)
     VALUES (:id, :root, :ext, :label, :description, :provider, :ns, 1, 1, :status, :quota, :sort, :created, :updated)',
    [
        'id' => 1, 'root' => 'kharcha.info', 'ext' => 'info', 'label' => 'Kharcha',
        'description' => 'Preview root domain',
        // The engine stays disabled in a preview: nothing here may reach a
        // provider, and the dashboard must render fine either way.
        'provider' => 'none',
        'ns' => 'ada.ns.cloudflare.com, bob.ns.cloudflare.com',
        'status' => 'ready', 'quota' => 5, 'sort' => 1,
        'created' => $now, 'updated' => $now,
    ]
);

// ---------------------------------------------------------------------------
// Subdomains — one per interesting state, for the owner only
// ---------------------------------------------------------------------------

$subdomains = [
    // label, status, target, created offset, provisioned offset
    ['atlas', 'active', '203.0.113.10', -86400 * 30, -86400 * 29],
    ['lantern', 'pending_dns', '203.0.113.11', -86400 * 2, null],
    ['rusty', 'failed', '203.0.113.12', -86400 * 9, null],
    ['quiet-harbor', 'suspended', '203.0.113.13', -86400 * 45, -86400 * 44],
    ['gone', 'terminated', null, -86400 * 90, null],
];

foreach ($subdomains as $index => [$label, $status, $target, $createdOffset, $provisionedOffset]) {
    Database::execute(
        'INSERT INTO subdomains (id, user_id, domain_id, reservation_id, label, fqdn, status, target, provisioned_at, created_at, updated_at)
         VALUES (:id, 1, 1, NULL, :label, :fqdn, :status, :target, :provisioned, :created, :updated)',
        [
            'id' => $index + 1,
            'label' => $label,
            'fqdn' => $label . '.kharcha.info',
            'status' => $status,
            'target' => $target,
            'provisioned' => $provisionedOffset !== null ? gmdate('Y-m-d H:i:s', time() + $provisionedOffset) : null,
            'created' => gmdate('Y-m-d H:i:s', time() + $createdOffset),
            'updated' => gmdate('Y-m-d H:i:s', time() + $createdOffset + 3600),
        ]
    );
}

// Another account's subdomain: the detail page must 404 on it.
Database::execute(
    'INSERT INTO subdomains (id, user_id, domain_id, label, fqdn, status, target, provisioned_at, created_at, updated_at)
     VALUES (99, 2, 1, :label, :fqdn, :status, :target, :provisioned, :created, :updated)',
    [
        'label' => 'not-yours', 'fqdn' => 'not-yours.kharcha.info', 'status' => 'active',
        'target' => '198.51.100.7', 'provisioned' => $now, 'created' => $now, 'updated' => $now,
    ]
);

// ---------------------------------------------------------------------------
// DNS records + provisioning jobs
// ---------------------------------------------------------------------------

// atlas: verified A record
Database::execute(
    "INSERT INTO dns_records (subdomain_id, domain_id, type, name, content, ttl, proxied, provider_record_id, status, last_synced_at, created_at, updated_at)
     VALUES (1, 1, 'A', :name, :content, 1, 0, :provider_id, 'active', :synced, :created, :updated)",
    [
        'name' => 'atlas.kharcha.info', 'content' => '203.0.113.10',
        'provider_id' => 'cf_preview_1', 'synced' => gmdate('Y-m-d H:i:s', time() - 86400 * 29),
        'created' => gmdate('Y-m-d H:i:s', time() - 86400 * 30), 'updated' => $now,
    ]
);

Database::execute(
    "INSERT INTO provisioning_jobs (subdomain_id, domain_id, fqdn, status, attempts, created_at, updated_at)
     VALUES (1, 1, :fqdn, 'done', 1, :created, :updated)",
    ['fqdn' => 'atlas.kharcha.info', 'created' => gmdate('Y-m-d H:i:s', time() - 86400 * 30), 'updated' => gmdate('Y-m-d H:i:s', time() - 86400 * 29)]
);

// lantern: queued, never attempted
Database::execute(
    "INSERT INTO provisioning_jobs (subdomain_id, domain_id, fqdn, status, attempts, created_at, updated_at)
     VALUES (2, 1, :fqdn, 'pending', 0, :created, :updated)",
    ['fqdn' => 'lantern.kharcha.info', 'created' => gmdate('Y-m-d H:i:s', time() - 86400), 'updated' => gmdate('Y-m-d H:i:s', time() - 86400)]
);

// rusty: failed after 3 attempts, with an operator-facing error that must never
// reach the browser.
Database::execute(
    "INSERT INTO provisioning_jobs (subdomain_id, domain_id, fqdn, status, attempts, last_error, created_at, updated_at)
     VALUES (3, 1, :fqdn, 'failed', 3, :error, :created, :updated)",
    [
        'fqdn' => 'rusty.kharcha.info',
        'error' => 'Cloudflare API 400: {"success":false,"errors":[{"code":81044,"message":"Record already exists."}]} token=cf_secret_must_not_leak',
        'created' => gmdate('Y-m-d H:i:s', time() - 86400 * 9), 'updated' => gmdate('Y-m-d H:i:s', time() - 86400 * 8),
    ]
);

// quiet-harbor: was active, then suspended; record still present but not served
Database::execute(
    "INSERT INTO dns_records (subdomain_id, domain_id, type, name, content, ttl, proxied, provider_record_id, status, last_synced_at, created_at, updated_at)
     VALUES (4, 1, 'A', :name, :content, 1, 0, :provider_id, 'active', :synced, :created, :updated)",
    [
        'name' => 'quiet-harbor.kharcha.info', 'content' => '203.0.113.13',
        'provider_id' => 'cf_preview_4', 'synced' => gmdate('Y-m-d H:i:s', time() - 86400 * 44),
        'created' => gmdate('Y-m-d H:i:s', time() - 86400 * 45), 'updated' => $now,
    ]
);

// ---------------------------------------------------------------------------
// Reservations — owner (2 pending against the 5-slot quota, plus history)
// ---------------------------------------------------------------------------

$reservations = [
    // id, user, label, status, reviewed, expires, note
    [1, 1, 'lantern', 'approved', gmdate('Y-m-d H:i:s', time() - 86400 * 2), gmdate('Y-m-d H:i:s', time() + 86400 * 5), null],
    [2, 1, 'cobalt', 'pending', null, gmdate('Y-m-d H:i:s', time() + 86400 * 6), null],
    [3, 1, 'driftwood', 'pending', null, gmdate('Y-m-d H:i:s', time() + 86400 * 6), null],
    [4, 1, 'spammy', 'rejected', gmdate('Y-m-d H:i:s', time() - 86400 * 12), null, 'The purpose field looked like bulk link-building.'],
    [5, 1, 'oldname', 'cancelled', null, null, 'Cancelled by the user.'],
    [6, 1, 'lapsed', 'expired', null, gmdate('Y-m-d H:i:s', time() - 86400 * 20), null],
    [7, 2, 'not-yours', 'pending', null, gmdate('Y-m-d H:i:s', time() + 86400 * 6), null],
];

foreach ($reservations as [$id, $userId, $label, $status, $reviewedAt, $expiresAt, $note]) {
    Database::execute(
        'INSERT INTO reservations (id, user_id, domain_id, label, status, purpose, admin_note, reviewed_by, reviewed_at, expires_at, created_at, updated_at)
         VALUES (:id, :user, 1, :label, :status, :purpose, :note, :reviewed_by, :reviewed, :expires, :created, :updated)',
        [
            'id' => $id, 'user' => $userId, 'label' => $label, 'status' => $status,
            'purpose' => 'Preview seed data', 'note' => $note,
            // NULL, not an invented staff id: no administrator exists in this
            // seed, and the FK would (correctly) refuse the row.
            'reviewed_by' => null,
            'reviewed' => $reviewedAt, 'expires' => $expiresAt,
            'created' => gmdate('Y-m-d H:i:s', time() - 86400 * 14), 'updated' => $reviewedAt ?? $now,
        ]
    );
}

// The reservations table is the single source of truth for the live guard: a
// pending or approved request holds its label, everything else has released it.
// Rather than hand-writing a `live_guard` that could drift from the schema, set
// it here exactly the way Reservation::transition() does.
Database::execute(
    "UPDATE reservations SET live_guard = CASE WHEN status IN ('pending', 'approved') THEN 1 ELSE NULL END"
);

// Link lantern's subdomain to its approved reservation, as the real path does.
Database::execute('UPDATE subdomains SET reservation_id = 1 WHERE id = 2');

// ---------------------------------------------------------------------------
// Print the URLs
// ---------------------------------------------------------------------------

$base = getenv('FREESUB_PREVIEW_BASE') ?: 'http://127.0.0.1:8160';

echo "Seeded " . $dbPath . "\n";
echo "Owner: ayesha@example.test (user 1), other account: other@example.test (user 2)\n";
echo "Foreign subdomain id for the IDOR check: 99\n";
echo "\nOpen:\n";
echo "  {$base}/preview-login.php?a=owner\n";
echo "  {$base}/preview-login.php?a=other\n";
