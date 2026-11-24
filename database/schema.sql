-- ===========================================================================
-- FreeSub — MySQL schema
-- Target: MySQL 8.0+ / MariaDB 10.6+
-- Charset: utf8mb4 / utf8mb4_unicode_ci
--
-- Usage:
--   mysql -u root -p < database/schema.sql
--
-- Notes:
--   * The schema is additive and safe to re-run (CREATE TABLE IF NOT EXISTS).
--   * Never re-run a destructive statement against a live installation.
--   * Cloudflare API tokens are NEVER stored here: they belong to the
--     environment (.env). Only a public zone reference is kept per domain.
-- ===========================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS `freesub`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `freesub`;

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    -- Identity now lives in Clerk. The hash column is kept (nullable) so an
    -- existing installation can migrate without copying password data around:
    -- it is only consulted by the offline CLI break-glass command.
    `password_hash` VARCHAR(255) NULL DEFAULT NULL,
    `clerk_user_id` VARCHAR(191) NULL DEFAULT NULL
        COMMENT 'Verified Clerk user id (sub). Primary identity key once linked.',
    `username` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Optional Clerk username',
    `avatar_url` VARCHAR(500) NULL DEFAULT NULL,
    `auth_provider` VARCHAR(32) NOT NULL DEFAULT 'clerk' COMMENT 'clerk | break_glass',
    `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    `status` ENUM('active', 'suspended', 'pending') NOT NULL DEFAULT 'active',
    `email_verified_at` DATETIME NULL DEFAULT NULL,
    `last_login_at` DATETIME NULL DEFAULT NULL,
    `last_login_ip` VARCHAR(45) NULL DEFAULT NULL,
    `failed_login_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL DEFAULT NULL,
    `subdomain_quota` SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Per-account allowance; NULL falls back to the domain quota',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_email_unique` (`email`),
    UNIQUE KEY `users_clerk_user_id_unique` (`clerk_user_id`),
    KEY `users_status_index` (`status`),
    KEY `users_role_index` (`role`),
    KEY `users_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- clerk_handoffs — one-time handoff between the browser SDK and PHP
-- A random token is generated server-side after Clerk confirms the session;
-- only its SHA-256 digest is stored, and it is single-use and short lived.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clerk_handoffs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 of the session token. The token itself is never stored.',
    `user_id` BIGINT UNSIGNED NOT NULL,
    `clerk_user_id` VARCHAR(191) NULL DEFAULT NULL,
    `state` ENUM('pending', 'consumed') NOT NULL DEFAULT 'pending',
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `consumed_at` DATETIME NULL DEFAULT NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `clerk_handoffs_token_hash_unique` (`token_hash`),
    KEY `clerk_handoffs_expires_at_index` (`expires_at`),
    CONSTRAINT `clerk_handoffs_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- clerk_sessions — mirror of the Clerk session backing a local PHP session
-- Lets the app re-verify "is this session still alive?" with Clerk and lets
-- an administrator see/stop active sessions. No tokens are stored.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clerk_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` VARCHAR(191) NOT NULL COMMENT 'Clerk session id (sess_...)',
    `user_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'active',
    `expire_at` DATETIME NULL DEFAULT NULL,
    `last_verified_at` DATETIME NULL DEFAULT NULL,
    `ended_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `clerk_sessions_session_id_unique` (`session_id`),
    KEY `clerk_sessions_user_id_index` (`user_id`),
    CONSTRAINT `clerk_sessions_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- domains — root domains owned by the platform (multi-domain ready)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `domains` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `root_domain` VARCHAR(190) NOT NULL COMMENT 'Apex domain, e.g. kharcha.info',
    `extension` VARCHAR(63) GENERATED ALWAYS AS (SUBSTRING_INDEX(`root_domain`, '.', -1)) STORED
        COMMENT 'Last label, used by the public extensions listing',
    `label` VARCHAR(100) NOT NULL COMMENT 'Human friendly name shown in the UI',
    `description` VARCHAR(255) NULL DEFAULT NULL,
    `dns_provider` VARCHAR(32) NOT NULL DEFAULT 'none' COMMENT 'none | cloudflare',
    `zone_reference` VARCHAR(190) NULL DEFAULT NULL
        COMMENT 'Public identifier of the DNS zone (Cloudflare zone id). NEVER a token.',
    `nameservers` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Comma separated NS hostnames (informational)',
    `registration_status` ENUM('draft', 'ready', 'maintenance', 'retired') NOT NULL DEFAULT 'ready',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_public` TINYINT(1) NOT NULL DEFAULT 1,
    `subdomain_quota` SMALLINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Per-user default quota for this root domain',
    `sort_order` SMALLINT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `domains_root_domain_unique` (`root_domain`),
    KEY `domains_active_public_index` (`is_active`, `is_public`),
    KEY `domains_extension_index` (`extension`),
    KEY `domains_registration_status_index` (`registration_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- reservations — a user's intent to own a label before DNS is provisioned
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reservations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `label` VARCHAR(63) NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
    `purpose` VARCHAR(255) NULL DEFAULT NULL,
    `admin_note` VARCHAR(500) NULL DEFAULT NULL,
    `reviewed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `reviewed_at` DATETIME NULL DEFAULT NULL,
    `expires_at` DATETIME NULL DEFAULT NULL,
    `request_ip` VARCHAR(45) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `reservations_user_status_index` (`user_id`, `status`),
    KEY `reservations_domain_label_index` (`domain_id`, `label`, `status`),
    KEY `reservations_status_created_index` (`status`, `created_at`),
    KEY `reservations_reviewed_by_foreign` (`reviewed_by`),
    KEY `reservations_expires_at_index` (`expires_at`),
    -- A nullable stand-in for a partial unique index: MySQL has no WHERE clause
    -- on UNIQUE, but a unique index ignores rows whose guard is NULL. The
    -- application writes 1 for pending/approved and NULL otherwise, so at most
    -- one *live* reservation can exist per label while history is preserved.
    `live_guard` TINYINT UNSIGNED NULL DEFAULT NULL
        COMMENT '1 while pending/approved, NULL otherwise (partial-unique emulation)',
    UNIQUE KEY `reservations_live_label_unique` (`domain_id`, `label`, `live_guard`),
    CONSTRAINT `reservations_user_id_foreign` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `reservations_domain_id_foreign` FOREIGN KEY (`domain_id`)
        REFERENCES `domains` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `reservations_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- subdomains — provisioned (or provisioning) hostnames
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subdomains` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `reservation_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `label` VARCHAR(63) NOT NULL,
    `fqdn` VARCHAR(190) NOT NULL,
    `status` ENUM('pending_dns', 'active', 'suspended', 'failed', 'terminated') NOT NULL DEFAULT 'pending_dns',
    `target` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Destination the hostname should resolve to',
    `provisioned_at` DATETIME NULL DEFAULT NULL,
    `expires_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `subdomains_fqdn_unique` (`fqdn`),
    UNIQUE KEY `subdomains_domain_label_unique` (`domain_id`, `label`),
    UNIQUE KEY `subdomains_reservation_unique` (`reservation_id`),
    KEY `subdomains_user_status_index` (`user_id`, `status`),
    KEY `subdomains_status_index` (`status`),
    CONSTRAINT `subdomains_user_id_foreign` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `subdomains_domain_id_foreign` FOREIGN KEY (`domain_id`)
        REFERENCES `domains` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `subdomains_reservation_id_foreign` FOREIGN KEY (`reservation_id`)
        REFERENCES `reservations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- dns_records — desired/known DNS state per subdomain
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dns_records` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Root domain (zone) this record belongs to',
    `subdomain_id` BIGINT UNSIGNED NOT NULL,
    `type` ENUM('A', 'AAAA', 'CNAME', 'TXT', 'MX') NOT NULL,
    `name` VARCHAR(190) NOT NULL COMMENT 'Relative or absolute record name',
    `content` VARCHAR(2000) NOT NULL,
    `ttl` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 = automatic',
    `priority` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'MX only',
    `proxied` TINYINT(1) NOT NULL DEFAULT 0,
    `provider_record_id` VARCHAR(190) NULL DEFAULT NULL COMMENT 'Remote id, e.g. Cloudflare record id',
    `status` ENUM('pending', 'active', 'failed', 'deleted') NOT NULL DEFAULT 'pending',
    `last_error` VARCHAR(500) NULL DEFAULT NULL,
    `last_synced_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When the remote record was last compared with this row',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `dns_records_subdomain_type_name_unique` (`subdomain_id`, `type`, `name`),
    KEY `dns_records_status_index` (`status`),
    KEY `dns_records_provider_record_index` (`provider_record_id`),
    KEY `dns_records_domain_index` (`domain_id`),
    CONSTRAINT `dns_records_subdomain_id_foreign` FOREIGN KEY (`subdomain_id`)
        REFERENCES `subdomains` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `dns_records_domain_id_foreign` FOREIGN KEY (`domain_id`)
        REFERENCES `domains` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- audit_logs — append-only trail of security and admin relevant actions
-- ---------------------------------------------------------------------------
-- DNS provisioning queue (Task 04). An approved reservation creates a row here;
-- a worker (admin page or CLI) turns it into a verified record. A subdomain only
-- becomes `active` after the read-back in ProvisioningQueue::provision().
CREATE TABLE IF NOT EXISTS `provisioning_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subdomain_id` BIGINT UNSIGNED NOT NULL,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `fqdn` VARCHAR(190) NOT NULL,
    `status` ENUM('pending', 'running', 'done', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(500) NULL DEFAULT NULL,
    `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `requested_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `provisioning_jobs_status_index` (`status`, `available_at`),
    KEY `provisioning_jobs_subdomain_index` (`subdomain_id`),
    KEY `provisioning_jobs_fqdn_index` (`fqdn`),
    CONSTRAINT `provisioning_jobs_subdomain_id_foreign` FOREIGN KEY (`subdomain_id`)
        REFERENCES `subdomains` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `provisioning_jobs_domain_id_foreign` FOREIGN KEY (`domain_id`)
        REFERENCES `domains` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `provisioning_jobs_requested_by_foreign` FOREIGN KEY (`requested_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `actor_type` ENUM('user', 'admin', 'system', 'guest') NOT NULL DEFAULT 'guest',
    `action` VARCHAR(80) NOT NULL COMMENT 'e.g. user.login, subdomain.reserved',
    `entity_type` VARCHAR(60) NULL DEFAULT NULL,
    `entity_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `description` VARCHAR(500) NULL DEFAULT NULL,
    `context` JSON NULL DEFAULT NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `audit_logs_user_created_index` (`user_id`, `created_at`),
    KEY `audit_logs_action_index` (`action`),
    KEY `audit_logs_entity_index` (`entity_type`, `entity_id`),
    KEY `audit_logs_created_at_index` (`created_at`),
    CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- abuse_reports — public reports against a hostname
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `abuse_reports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subdomain_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `reported_fqdn` VARCHAR(190) NOT NULL,
    `reporter_name` VARCHAR(100) NULL DEFAULT NULL,
    `reporter_email` VARCHAR(190) NOT NULL,
    `category` ENUM('phishing', 'malware', 'spam', 'copyright', 'illegal', 'other') NOT NULL DEFAULT 'other',
    `message` TEXT NOT NULL,
    `status` ENUM('new', 'reviewing', 'resolved', 'dismissed') NOT NULL DEFAULT 'new',
    `handled_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `handled_at` DATETIME NULL DEFAULT NULL,
    `resolution_note` VARCHAR(1000) NULL DEFAULT NULL,
    `request_ip` VARCHAR(45) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `abuse_reports_status_created_index` (`status`, `created_at`),
    KEY `abuse_reports_fqdn_index` (`reported_fqdn`),
    KEY `abuse_reports_handled_by_foreign` (`handled_by`),
    CONSTRAINT `abuse_reports_subdomain_id_foreign` FOREIGN KEY (`subdomain_id`)
        REFERENCES `subdomains` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `abuse_reports_handled_by_foreign` FOREIGN KEY (`handled_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Initial root domain. Additional root domains can be added with a plain
-- INSERT — no code changes are required.
-- ---------------------------------------------------------------------------
INSERT INTO `domains`
    (`root_domain`, `label`, `description`, `dns_provider`, `registration_status`, `is_active`, `is_public`, `subdomain_quota`, `sort_order`)
VALUES
    ('kharcha.info', 'kharcha.info', 'Our first free extension — short, memorable and ready for your next idea.', 'none', 'ready', 1, 1, 5, 0)
ON DUPLICATE KEY UPDATE `root_domain` = VALUES(`root_domain`);
