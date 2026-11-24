-- ===========================================================================
-- FreeSub migration 2026_11_001 — Clerk identity
--
-- Purpose: move the identity key from a local password hash to a verified
-- Clerk user id, WITHOUT destroying existing rows or relationships.
--
--   * Existing users keep their id, so reservations.user_id,
--     subdomains.user_id, audit_logs.actor_user_id and
--     abuse_reports.reporter_user_id keep pointing at the same account.
--   * password_hash becomes nullable (new Clerk-only accounts have no hash).
--     Existing hashes are left untouched so the offline break-glass command
--     keeps working until an operator removes them.
--   * No column is dropped and no row is deleted. Nothing here is destructive.
--
-- Usage (MySQL / MariaDB):
--   mysql -u root -p freesub < database/migrations/2026_11_001_clerk_identity.sql
--
-- Or let the app do it:  php database/migrate.php
--
-- Every statement is guarded so the file can be re-run safely.
-- ===========================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- 1. New columns (nullable / defaulted: existing rows stay valid)
-- --------------------------------------------------------------------------
-- MySQL 8.0.29+/MariaDB 10.6 support IF NOT EXISTS on ADD COLUMN; older
-- servers reject it, so database/migrate.php inspects information_schema
-- instead. This file is the readable, canonical form of that operation.

ALTER TABLE `users`
    ADD COLUMN `clerk_user_id` VARCHAR(191) NULL DEFAULT NULL,
    ADD COLUMN `username` VARCHAR(64) NULL DEFAULT NULL,
    ADD COLUMN `avatar_url` VARCHAR(500) NULL DEFAULT NULL,
    ADD COLUMN `auth_provider` VARCHAR(32) NOT NULL DEFAULT 'clerk';

-- --------------------------------------------------------------------------
-- 2. The identity key
-- --------------------------------------------------------------------------
ALTER TABLE `users`
    ADD UNIQUE KEY `users_clerk_user_id_unique` (`clerk_user_id`);

-- --------------------------------------------------------------------------
-- 3. Local passwords become optional (new accounts never receive one)
-- --------------------------------------------------------------------------
ALTER TABLE `users`
    MODIFY COLUMN `password_hash` VARCHAR(255) NULL DEFAULT NULL;

-- --------------------------------------------------------------------------
-- 4. How existing rows are linked
-- --------------------------------------------------------------------------
-- Nothing is guessed here on purpose: an account is bound to a Clerk user
-- only when Clerk itself tells us the identity is verified, and the binding
-- happens inside User::syncFromClerk() in a single transaction.
--
--   a. If the account already has clerk_user_id, it is used as-is.
--   b. Otherwise the case-insensitive email match is used, but ONLY when the
--      Clerk email address is marked verified (verified_at IS NOT NULL).
--   c. Otherwise a fresh account is created with role = 'user'.
--
-- Admins are never created, promoted or demoted by the sync path. The role
-- column is not touched by any statement in this file.
--
-- Optional dry run: list the accounts that will be linkable by verified email
-- once Clerk sign-in is live.
--
--   SELECT id, email, name, role
--     FROM users
--    WHERE clerk_user_id IS NULL
--    ORDER BY id;
--
-- --------------------------------------------------------------------------
-- 5. Local audit tables for the Clerk handoff (no tokens are stored)
-- --------------------------------------------------------------------------
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
