-- QRIVO — Academic & Institutional Structure Migration
-- Migration: 003_create_academic_structure
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
--
-- Depends on: 001_create_auth_tables.sql (users)
--
-- Creates the academic structure (Phase 8):
--   schools, faculties, departments, programs, rooms, courses,
--   academic_years, academic_terms, classes, teachers, students
--
-- Table shapes follow database/docs/TABLES.md (Groups 2 & 3),
-- constraints follow database/docs/CONSTRAINTS.md (C-006..C-025, FK-07..FK-19),
-- indexes follow database/docs/INDEXES.md. Creation order respects the FK
-- dependency chain (DATABASE_DECISIONS.md DD-013).
--
-- Soft delete (deleted_at) on structural entities per DD-009. `academic_years`
-- and `academic_terms` are NOT soft-deleted (no deleted_at column in TABLES.md).
--
-- Assignment/scheduling tables (class_courses, teacher_courses,
-- teacher_class_assignments, student_class_assignments, student_courses,
-- course_schedules) belong to Phase 9 and are NOT created here.

SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

-- ─── schools ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `schools` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255)  NOT NULL,
    `code`       VARCHAR(50)   NOT NULL,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME          NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_schools_code` (`code`),
    KEY `idx_schools_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Top-level institutional unit';

-- ─── faculties ───────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `faculties` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `school_id`  INT UNSIGNED  NOT NULL,
    `name`       VARCHAR(255)  NOT NULL,
    `code`       VARCHAR(50)   NOT NULL,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME          NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_faculties_school_code` (`school_id`, `code`),
    KEY `idx_faculties_deleted_at` (`deleted_at`),
    CONSTRAINT `fk_faculties_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Faculty within a school';

-- ─── departments ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `departments` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `faculty_id` INT UNSIGNED  NOT NULL,
    `name`       VARCHAR(255)  NOT NULL,
    `code`       VARCHAR(50)   NOT NULL,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME          NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_departments_faculty_code` (`faculty_id`, `code`),
    KEY `idx_departments_deleted_at` (`deleted_at`),
    CONSTRAINT `fk_departments_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculties`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Department within a faculty';

-- ─── programs ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `programs` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `department_id`  INT UNSIGNED      NOT NULL,
    `name`           VARCHAR(255)      NOT NULL,
    `code`           VARCHAR(50)       NOT NULL,
    `duration_years` TINYINT UNSIGNED  NOT NULL,
    `created_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME              NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_programs_department_code` (`department_id`, `code`),
    KEY `idx_programs_deleted_at` (`deleted_at`),
    CONSTRAINT `fk_programs_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Degree program within a department';

-- ─── rooms ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `rooms` (
    `id`         INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    `school_id`  INT UNSIGNED       NOT NULL,
    `name`       VARCHAR(100)       NOT NULL,
    `code`       VARCHAR(50)        NOT NULL,
    `capacity`   SMALLINT UNSIGNED      NULL DEFAULT NULL,
    `created_at` DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME               NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rooms_school_code` (`school_id`, `code`),
    KEY `idx_rooms_deleted_at` (`deleted_at`),
    CONSTRAINT `fk_rooms_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Physical room or lecture hall';

-- ─── courses ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `courses` (
    `id`            INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    `department_id` INT UNSIGNED       NOT NULL,
    `name`          VARCHAR(255)       NOT NULL,
    `code`          VARCHAR(50)        NOT NULL,
    `credit_hours`  TINYINT UNSIGNED       NULL DEFAULT NULL,
    `created_at`    DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`    DATETIME               NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_courses_department_code` (`department_id`, `code`),
    KEY `idx_courses_deleted_at` (`deleted_at`),
    CONSTRAINT `fk_courses_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Course definition';

-- ─── academic_years ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `academic_years` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `school_id`  INT UNSIGNED  NOT NULL,
    `name`       VARCHAR(50)   NOT NULL COMMENT 'e.g. 2025-2026',
    `start_date` DATE          NOT NULL,
    `end_date`   DATE          NOT NULL,
    `is_active`  TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_academic_years_school_name` (`school_id`, `name`),
    KEY `idx_academic_years_is_active` (`is_active`),
    CONSTRAINT `fk_academic_years_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Academic year scoped to a school';

-- ─── academic_terms ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `academic_terms` (
    `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `academic_year_id` INT UNSIGNED      NOT NULL,
    `name`             VARCHAR(100)      NOT NULL COMMENT 'e.g. Fall 2025',
    `term_number`      TINYINT UNSIGNED  NOT NULL COMMENT '1, 2, 3',
    `start_date`       DATE              NOT NULL,
    `end_date`         DATE              NOT NULL,
    `is_active`        TINYINT(1)        NOT NULL DEFAULT 0,
    `created_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_academic_terms_is_active` (`is_active`),
    KEY `idx_academic_terms_year` (`academic_year_id`),
    CONSTRAINT `fk_academic_terms_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Term within an academic year';

-- ─── classes ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `classes` (
    `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `program_id`       INT UNSIGNED      NOT NULL,
    `academic_term_id` INT UNSIGNED      NOT NULL,
    `name`             VARCHAR(100)      NOT NULL COMMENT 'e.g. CE-3A',
    `grade_level`      TINYINT UNSIGNED  NOT NULL COMMENT 'Year of study',
    `created_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`       DATETIME              NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_classes_program` (`program_id`),
    KEY `idx_classes_term` (`academic_term_id`),
    KEY `idx_classes_deleted_at` (`deleted_at`),
    CONSTRAINT `fk_classes_program` FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_classes_term`    FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='A class group of students in a program for a term';

-- ─── teachers ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `teachers` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`         BIGINT UNSIGNED  NOT NULL,
    `department_id`   INT UNSIGNED     NOT NULL,
    `employee_number` VARCHAR(50)      NOT NULL,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME             NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_teachers_user` (`user_id`),
    UNIQUE KEY `uq_teachers_employee_number` (`employee_number`),
    KEY `idx_teachers_department` (`department_id`),
    KEY `idx_teachers_deleted_at` (`deleted_at`),
    CONSTRAINT `fk_teachers_user`       FOREIGN KEY (`user_id`)       REFERENCES `users`(`id`)       ON DELETE RESTRICT,
    CONSTRAINT `fk_teachers_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Teacher profile, linked 1:1 to a users record';

-- ─── students ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `students` (
    `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`         BIGINT UNSIGNED  NOT NULL,
    `program_id`      INT UNSIGNED     NOT NULL,
    `student_number`  VARCHAR(50)      NOT NULL,
    `enrollment_year` YEAR             NOT NULL,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME             NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_students_user` (`user_id`),
    UNIQUE KEY `uq_students_student_number` (`student_number`),
    KEY `idx_students_program` (`program_id`),
    KEY `idx_students_deleted_at` (`deleted_at`),
    CONSTRAINT `fk_students_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)     ON DELETE RESTRICT,
    CONSTRAINT `fk_students_program` FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Student profile, linked 1:1 to a users record';
