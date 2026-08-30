-- QRIVO — QR Challenges & Risk Assessments Migration
-- Migration: 007_create_qr_challenges
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
--
-- Depends on: 005 (attendance_sessions), 003 (students)
--
-- Creates:
--   qr_challenges     — single-use challenge tokens (TABLES.md Group 6)
--   risk_assessments  — risk evaluation result per challenge (TABLES.md Group 7)
--
-- Constraints follow CONSTRAINTS.md — C-002 (UNIQUE(nonce)), C-003 (UNIQUE(uuid)),
-- FK-46..FK-50 (all ON DELETE RESTRICT — DD-010). Indexes follow INDEXES.md.
-- `used_at` is NULL until the challenge is consumed, set atomically inside the
-- attendance transaction (DD-003).

SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

-- ─── qr_challenges ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `qr_challenges` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL COMMENT 'challenge_id sent to the mobile client',
    `attendance_session_id` BIGINT UNSIGNED NOT NULL,
    `student_id`            BIGINT UNSIGNED NOT NULL,
    `nonce`                 VARCHAR(255)    NOT NULL COMMENT 'Server-generated, globally unique challenge nonce',
    `qr_nonce`              VARCHAR(255)    NOT NULL COMMENT 'The nonce from the scanned QR payload (DD-004)',
    `expires_at`           DATETIME        NOT NULL,
    `used_at`              DATETIME             NULL DEFAULT NULL COMMENT 'NULL = unused; set atomically on successful use (DD-003)',
    `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_qr_challenges_uuid`  (`uuid`),
    UNIQUE KEY `uq_qr_challenges_nonce` (`nonce`),
    KEY `idx_qc_session_student` (`attendance_session_id`, `student_id`),
    KEY `idx_qc_expires_at` (`expires_at`),
    KEY `idx_qc_qr_nonce` (`qr_nonce`),
    CONSTRAINT `fk_qc_session` FOREIGN KEY (`attendance_session_id`) REFERENCES `attendance_sessions`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_qc_student` FOREIGN KEY (`student_id`)            REFERENCES `students`(`id`)            ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Single-use challenge tokens for challenge-response attendance (ATTENDANCE_ALGORITHM.md §4)';

-- ─── risk_assessments ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `risk_assessments` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `qr_challenge_id`       BIGINT UNSIGNED NOT NULL,
    `student_id`            BIGINT UNSIGNED NOT NULL,
    `attendance_session_id` BIGINT UNSIGNED NOT NULL,
    `risk_level`            ENUM('LOW','MEDIUM','HIGH','BLOCKED') NOT NULL,
    `risk_score`            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `signals`              JSON                 NULL COMMENT 'Which risk signals fired; must NOT contain passwords or tokens',
    `outcome`             ENUM('PRESENT','PENDING_REVIEW','BLOCKED') NOT NULL,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ra_session` (`attendance_session_id`),
    KEY `idx_ra_student_time` (`student_id`, `created_at`),
    KEY `idx_ra_risk_level` (`risk_level`),
    CONSTRAINT `fk_ra_challenge` FOREIGN KEY (`qr_challenge_id`)       REFERENCES `qr_challenges`(`id`)        ON DELETE RESTRICT,
    CONSTRAINT `fk_ra_student`   FOREIGN KEY (`student_id`)            REFERENCES `students`(`id`)             ON DELETE RESTRICT,
    CONSTRAINT `fk_ra_session2`  FOREIGN KEY (`attendance_session_id`) REFERENCES `attendance_sessions`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Risk evaluation result for an attendance attempt (basic in Phase 12; expanded in Phase 19)';
