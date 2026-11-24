-- ===========================================================================
-- FreeSub — SQLite schema (LOCAL DEVELOPMENT ONLY)
--
-- MySQL/MariaDB is the supported production database; this file exists so the
-- application can be exercised without a database server (see README).
-- Column names, constraints and indexes mirror database/schema.sql.
-- ===========================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NULL,
    clerk_user_id VARCHAR(191) NULL,
    username VARCHAR(64) NULL,
    avatar_url VARCHAR(500) NULL,
    auth_provider VARCHAR(32) NOT NULL DEFAULT 'clerk',
    role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'pending')),
    email_verified_at TEXT NULL,
    last_login_at TEXT NULL,
    last_login_ip VARCHAR(45) NULL,
    failed_login_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT NULL,
    subdomain_quota INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (email);
CREATE UNIQUE INDEX IF NOT EXISTS users_clerk_user_id_unique ON users (clerk_user_id);
CREATE INDEX IF NOT EXISTS users_status_index ON users (status);
CREATE INDEX IF NOT EXISTS users_role_index ON users (role);

-- Clerk handoff/session bookkeeping. Only hashes are stored, never tokens.
CREATE TABLE IF NOT EXISTS clerk_handoffs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash CHAR(64) NOT NULL,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    clerk_user_id VARCHAR(191) NULL,
    state TEXT NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'consumed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS clerk_handoffs_token_hash_unique ON clerk_handoffs (token_hash);
CREATE INDEX IF NOT EXISTS clerk_handoffs_expires_at_index ON clerk_handoffs (expires_at);

CREATE TABLE IF NOT EXISTS clerk_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id VARCHAR(191) NOT NULL,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    expire_at TEXT NULL,
    last_verified_at TEXT NULL,
    ended_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS clerk_sessions_session_id_unique ON clerk_sessions (session_id);
CREATE INDEX IF NOT EXISTS clerk_sessions_user_id_index ON clerk_sessions (user_id);

CREATE TABLE IF NOT EXISTS domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    root_domain VARCHAR(190) NOT NULL,
    extension VARCHAR(63) NULL,
    label VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    dns_provider VARCHAR(32) NOT NULL DEFAULT 'none',
    zone_reference VARCHAR(190) NULL,
    nameservers VARCHAR(500) NULL,
    registration_status TEXT NOT NULL DEFAULT 'ready' CHECK (registration_status IN ('draft', 'ready', 'maintenance', 'retired')),
    is_active INTEGER NOT NULL DEFAULT 1,
    is_public INTEGER NOT NULL DEFAULT 1,
    subdomain_quota INTEGER NOT NULL DEFAULT 5,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS domains_root_domain_unique ON domains (root_domain);
CREATE INDEX IF NOT EXISTS domains_active_public_index ON domains (is_active, is_public);
CREATE INDEX IF NOT EXISTS domains_extension_index ON domains (extension);

CREATE TABLE IF NOT EXISTS reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    domain_id INTEGER NOT NULL REFERENCES domains (id) ON DELETE RESTRICT,
    label VARCHAR(63) NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled', 'expired')),
    purpose VARCHAR(255) NULL,
    admin_note VARCHAR(500) NULL,
    reviewed_by INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    reviewed_at TEXT NULL,
    expires_at TEXT NULL,
    request_ip VARCHAR(45) NULL,
    -- MySQL cannot express a partial unique index, so it constrains
    -- (domain_id, label, live_guard) where live_guard is 1 only while live.
    -- SQLite does have partial indexes, but the column exists on both engines so
    -- the application writes exactly one INSERT and one UPDATE shape.
    live_guard INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS reservations_user_status_index ON reservations (user_id, status);
CREATE INDEX IF NOT EXISTS reservations_domain_label_index ON reservations (domain_id, label, status);
CREATE INDEX IF NOT EXISTS reservations_status_created_index ON reservations (status, created_at);
CREATE INDEX IF NOT EXISTS reservations_expires_at_index ON reservations (expires_at);

-- Race-safe reservation: at most ONE live (pending/approved) reservation per
-- label per root domain, enforced by the database rather than by a SELECT.
-- Cancelled/rejected/expired rows fall out of the index, so history is kept and
-- the label can be reserved again later. (Task 04)
CREATE UNIQUE INDEX IF NOT EXISTS reservations_live_label_unique
    ON reservations (domain_id, label)
    WHERE status IN ('pending', 'approved');

CREATE TABLE IF NOT EXISTS subdomains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    domain_id INTEGER NOT NULL REFERENCES domains (id) ON DELETE RESTRICT,
    reservation_id INTEGER NULL UNIQUE REFERENCES reservations (id) ON DELETE SET NULL,
    label VARCHAR(63) NOT NULL,
    fqdn VARCHAR(190) NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending_dns' CHECK (status IN ('pending_dns', 'active', 'suspended', 'failed', 'terminated')),
    target VARCHAR(255) NULL,
    provisioned_at TEXT NULL,
    expires_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS subdomains_fqdn_unique ON subdomains (fqdn);
CREATE UNIQUE INDEX IF NOT EXISTS subdomains_domain_label_unique ON subdomains (domain_id, label);
CREATE INDEX IF NOT EXISTS subdomains_user_status_index ON subdomains (user_id, status);
CREATE INDEX IF NOT EXISTS subdomains_status_index ON subdomains (status);

CREATE TABLE IF NOT EXISTS dns_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    -- Denormalised from subdomains so a record can be traced to its zone even
    -- after the subdomain row is gone. NULL only for legacy rows.
    domain_id INTEGER NULL REFERENCES domains (id) ON DELETE SET NULL,
    subdomain_id INTEGER NOT NULL REFERENCES subdomains (id) ON DELETE CASCADE,
    type TEXT NOT NULL CHECK (type IN ('A', 'AAAA', 'CNAME', 'TXT', 'MX')),
    name VARCHAR(190) NOT NULL,
    content VARCHAR(2000) NOT NULL,
    ttl INTEGER NOT NULL DEFAULT 1,
    priority INTEGER NULL,
    proxied INTEGER NOT NULL DEFAULT 0,
    provider_record_id VARCHAR(190) NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'active', 'failed', 'deleted')),
    last_error VARCHAR(500) NULL,
    last_synced_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS dns_records_subdomain_type_name_unique ON dns_records (subdomain_id, type, name);
CREATE INDEX IF NOT EXISTS dns_records_status_index ON dns_records (status);
CREATE INDEX IF NOT EXISTS dns_records_domain_index ON dns_records (domain_id);
CREATE INDEX IF NOT EXISTS dns_records_provider_record_index ON dns_records (provider_record_id);

-- DNS provisioning queue (Task 04). An approved reservation creates a row here;
-- a worker (admin page or CLI) turns it into a verified record. A subdomain only
-- becomes `active` after the read-back in ProvisioningQueue::provision().
CREATE TABLE IF NOT EXISTS provisioning_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subdomain_id INTEGER NOT NULL REFERENCES subdomains (id) ON DELETE CASCADE,
    domain_id INTEGER NOT NULL REFERENCES domains (id) ON DELETE RESTRICT,
    fqdn VARCHAR(190) NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'done', 'failed', 'cancelled')),
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    requested_by INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS provisioning_jobs_status_index ON provisioning_jobs (status, available_at);
CREATE INDEX IF NOT EXISTS provisioning_jobs_subdomain_index ON provisioning_jobs (subdomain_id);
CREATE INDEX IF NOT EXISTS provisioning_jobs_fqdn_index ON provisioning_jobs (fqdn);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    actor_type TEXT NOT NULL DEFAULT 'guest' CHECK (actor_type IN ('user', 'admin', 'system', 'guest')),
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(60) NULL,
    entity_id INTEGER NULL,
    description VARCHAR(500) NULL,
    context TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS audit_logs_user_created_index ON audit_logs (user_id, created_at);
CREATE INDEX IF NOT EXISTS audit_logs_action_index ON audit_logs (action);
CREATE INDEX IF NOT EXISTS audit_logs_entity_index ON audit_logs (entity_type, entity_id);

CREATE TABLE IF NOT EXISTS abuse_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subdomain_id INTEGER NULL REFERENCES subdomains (id) ON DELETE SET NULL,
    reported_fqdn VARCHAR(190) NOT NULL,
    reporter_name VARCHAR(100) NULL,
    reporter_email VARCHAR(190) NOT NULL,
    category TEXT NOT NULL DEFAULT 'other' CHECK (category IN ('phishing', 'malware', 'spam', 'copyright', 'illegal', 'other')),
    message TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'reviewing', 'resolved', 'dismissed')),
    handled_by INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    handled_at TEXT NULL,
    resolution_note VARCHAR(1000) NULL,
    request_ip VARCHAR(45) NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS abuse_reports_status_created_index ON abuse_reports (status, created_at);
CREATE INDEX IF NOT EXISTS abuse_reports_fqdn_index ON abuse_reports (reported_fqdn);

INSERT INTO domains (root_domain, extension, label, description, dns_provider, registration_status, is_active, is_public, subdomain_quota, sort_order)
SELECT 'kharcha.info', 'info', 'kharcha.info', 'Our first free extension — short, memorable and ready for your next idea.', 'none', 'ready', 1, 1, 5, 0
WHERE NOT EXISTS (SELECT 1 FROM domains WHERE root_domain = 'kharcha.info');
