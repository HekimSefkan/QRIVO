-- QRIVO — Attendance Sessions & Records Migration
-- Migration: 005_create_attendance_sessions
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
--
-- Depends on: 003 (courses, classes, teachers, rooms, academic_terms, students)
--
-- Creates the attendance core (TABLES.md Group 5):
--   attendance_sessions, attendance_records
--
-- Constraints follow CONSTRAINTS.md — C-001 (UNIQUE(attendance_session_id,
-- student_id) — the database-level duplicate-attendance guard), C-026
-- (UNIQUE(uuid)), FK-39..FK-45 (all ON DELETE RESTRICT — DD-010). Indexes follow
-- INDEXES.md. `end_time` is NULL until the session is closed/cancelled
-- (CONSTRAINTS.md §4).
--
-- QR / challenge / risk tables are later phases and are NOT created here.

SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

-- ─── attendance_sessions ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `attendance_sessions` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`             CHAR(36)        NOT NULL COMMENT 'Safe external identifier; used in QR payloads',
    `course_id`        INT UNSIGNED    NOT NULL,
    `class_id`         INT UNSIGNED    NOT NULL,
    `teacher_id`       INT UNSIGNED    NOT NULL,
    `room_id`          INT UNSIGNED    NOT NULL,
    `academic_term_id` INT UNSIGNED    NOT NULL,
    `start_time`       DATETIME        NOT NULL COMMENT 'When the session was started',
    `end_time`         DATETIME            NULL DEFAULT NULL COMMENT 'Set on close/cancel',
    `expires_at`       DATETIME        NOT NULL COMMENT 'Session auto-expiry',
    `status`           ENUM('ACTIVE','CLOSED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
    `session_secret`   VARCHAR(255)    NOT NULL COMMENT 'Server-generated HMAC key for QR signing — NEVER exposed via the API (DD-002)',
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_attendance_sessions_uuid` (`uuid`),
    KEY `idx_as_status_teacher` (`status`, `teacher_id`),
    KEY `idx_as_status_class`   (`status`, `class_id`),
    KEY `idx_as_teacher_status` (`teacher_id`, `status`),
    KEY `idx_as_class_course_status` (`class_id`, `course_id`, `status`),
    KEY `idx_as_status` (`status`),
    CONSTRAINT `fk_as_course`  FOREIGN KEY (`course_id`)        REFERENCES `courses`(`id`)         ON DELETE RESTRICT,
    CONSTRAINT `fk_as_class`   FOREIGN KEY (`class_id`)         REFERENCES `classes`(`id`)         ON DELETE RESTRICT,
    CONSTRAINT `fk_as_teacher` FOREIGN KEY (`teacher_id`)       REFERENCES `teachers`(`id`)        ON DELETE RESTRICT,
    CONSTRAINT `fk_as_room`    FOREIGN KEY (`room_id`)          REFERENCES `rooms`(`id`)           ON DELETE RESTRICT,
    CONSTRAINT `fk_as_term`    FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='A QR attendance session — created by a teacher, scoped to course + class + term';

-- ─── attendance_records ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `attendance_records` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attendance_session_id` BIGINT UNSIGNED NOT NULL,
    `student_id`            BIGINT UNSIGNED NOT NULL,
    `status`                ENUM('WAITING','PRESENT','ABSENT','LATE','EXCUSED','PENDING_REVIEW') NOT NULL DEFAULT 'WAITING',
    `source`                ENUM('SYSTEM','QR','MANUAL') NOT NULL DEFAULT 'SYSTEM',
    `marked_at`             DATETIME            NULL DEFAULT NULL COMMENT 'When status changed from WAITING',
    `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_attendance_records_session_student` (`attendance_session_id`, `student_id`),
    KEY `idx_ar_session_status` (`attendance_session_id`, `status`),
    KEY `idx_ar_student_session` (`student_id`, `attendance_session_id`),
    CONSTRAINT `fk_ar_session` FOREIGN KEY (`attendance_session_id`) REFERENCES `attendance_sessions`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ar_student` FOREIGN KEY (`student_id`)            REFERENCES `students`(`id`)            ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per enrolled student per session; UNIQUE(session,student) is the duplicate-attendance guard (C-001)';
