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
    password_hash VARCHAR(255) NOT NULL,
    role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'pending')),
    email_verified_at TEXT NULL,
    last_login_at TEXT NULL,
    last_login_ip VARCHAR(45) NULL,
    failed_login_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (email);
CREATE INDEX IF NOT EXISTS users_status_index ON users (status);
CREATE INDEX IF NOT EXISTS users_role_index ON users (role);

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
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS reservations_user_status_index ON reservations (user_id, status);
CREATE INDEX IF NOT EXISTS reservations_domain_label_index ON reservations (domain_id, label, status);
CREATE INDEX IF NOT EXISTS reservations_status_created_index ON reservations (status, created_at);

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
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS dns_records_subdomain_type_name_unique ON dns_records (subdomain_id, type, name);
CREATE INDEX IF NOT EXISTS dns_records_status_index ON dns_records (status);

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
