-- QRIVO — QR Nonce Replay Store Migration
-- Migration: 006_create_qr_used_nonces
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
--
-- Depends on: 005 (attendance_sessions)
--
-- The "nonce store" for dynamic-QR replay protection, named in
-- docs/ARCHITECTURE_FREEZE.md §2.8 ("nonce store (in-memory or `qr_used_nonces`)").
-- Recorded here as the durable option. It is NOT one of the 31 domain tables in
-- database/docs/TABLES.md — see docs/ACCEPTED_DEVIATIONS.md AD-008.
--
-- A QR payload nonce is written here the first time that QR is consumed
-- (turned into a challenge). `UNIQUE(nonce)` makes a second consumption of the
-- same QR frame impossible, even under concurrency — this is the "Nonce" half of
-- ATTENDANCE_ALGORITHM.md §10 / SECURITY_RULES.md §5 QR replay protection (the
-- "expiration" half is the server-side timestamp check).

SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

CREATE TABLE IF NOT EXISTS `qr_used_nonces` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attendance_session_id` BIGINT UNSIGNED NOT NULL,
    `nonce`                 VARCHAR(64)     NOT NULL COMMENT 'The nonce from a consumed QR payload',
    `consumed_at`           DATETIME        NOT NULL COMMENT 'When the QR was presented to the backend',
    `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_qr_used_nonces_nonce` (`nonce`),
    KEY `idx_qun_session` (`attendance_session_id`),
    KEY `idx_qun_consumed_at` (`consumed_at`),
    CONSTRAINT `fk_qun_session` FOREIGN KEY (`attendance_session_id`)
        REFERENCES `attendance_sessions`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Consumed dynamic-QR nonces — QR-layer replay protection';
