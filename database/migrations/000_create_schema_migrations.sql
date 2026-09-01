-- QRIVO — Migration Ledger
-- Migration: 000_create_schema_migrations
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
--
-- Depends on: nothing (must be the first file applied)
--
-- Creates:
--   schema_migrations — the applied-migration ledger used by
--                       backend/scripts/migrate.php to make re-runs a no-op.
--
-- This is INFRASTRUCTURE, not a domain table: it is deliberately absent from
-- database/docs/TABLES.md (which defines the 31 domain tables) and carries no
-- foreign keys, no personal data and no secrets. See docs/ACCEPTED_DEVIATIONS.md
-- AD-017.
--
-- The runner also creates this table itself before the first file is applied, so
-- the ledger exists even on a completely empty database. Applying this file is
-- therefore idempotent by construction (`CREATE TABLE IF NOT EXISTS`).

SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `filename`    VARCHAR(255)  NOT NULL COMMENT 'Migration file name, e.g. 001_create_auth_tables.sql',
    `checksum`    CHAR(64)      NOT NULL COMMENT 'SHA-256 of the file contents when applied',
    `statements`  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Number of SQL statements executed',
    `duration_ms` INT UNSIGNED  NOT NULL DEFAULT 0,
    `applied_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_schema_migrations_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Applied-migration ledger; written by backend/scripts/migrate.php';
