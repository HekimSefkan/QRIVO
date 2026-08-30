-- QRIVO — Course Assignments & Scheduling Migration
-- Migration: 004_create_course_scheduling
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
--
-- Depends on: 003_create_academic_structure.sql (classes, courses, academic_terms,
--             rooms, teachers, students)
--
-- Creates the Phase 9 assignment/scheduling tables:
--   class_courses, teacher_courses, teacher_class_assignments,
--   student_class_assignments, student_courses, course_schedules
--
-- Shapes follow database/docs/TABLES.md Group 4; constraints follow
-- CONSTRAINTS.md (C-014..C-018, FK-20..FK-38); indexes follow INDEXES.md.
-- Creation order respects the FK dependency chain (DD-013).
--
-- These tables are NOT soft-deleted (no deleted_at in TABLES.md).
--
-- `teacher_class_assignments` is the authorization record for attendance session
-- creation (ATTENDANCE_ALGORITHM.md §2, steps 3-4 / RELATIONSHIPS.md §4).

SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

-- ─── class_courses ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `class_courses` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_id`         INT UNSIGNED NOT NULL,
    `course_id`        INT UNSIGNED NOT NULL,
    `academic_term_id` INT UNSIGNED NOT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_class_courses` (`class_id`, `course_id`, `academic_term_id`),
    KEY `idx_class_courses_term` (`academic_term_id`),
    CONSTRAINT `fk_cc_class`  FOREIGN KEY (`class_id`)         REFERENCES `classes`(`id`)         ON DELETE RESTRICT,
    CONSTRAINT `fk_cc_course` FOREIGN KEY (`course_id`)        REFERENCES `courses`(`id`)         ON DELETE RESTRICT,
    CONSTRAINT `fk_cc_term`   FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Courses offered to a class in a term';

-- ─── teacher_courses ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `teacher_courses` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `teacher_id`       INT UNSIGNED NOT NULL,
    `course_id`        INT UNSIGNED NOT NULL,
    `academic_term_id` INT UNSIGNED NOT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_teacher_courses` (`teacher_id`, `course_id`, `academic_term_id`),
    KEY `idx_teacher_courses_course` (`course_id`),
    CONSTRAINT `fk_tc_teacher` FOREIGN KEY (`teacher_id`)       REFERENCES `teachers`(`id`)        ON DELETE RESTRICT,
    CONSTRAINT `fk_tc_course`  FOREIGN KEY (`course_id`)        REFERENCES `courses`(`id`)         ON DELETE RESTRICT,
    CONSTRAINT `fk_tc_term`    FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Courses a teacher is responsible for in a term';

-- ─── teacher_class_assignments (authorization record) ────────────────────────

CREATE TABLE IF NOT EXISTS `teacher_class_assignments` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `teacher_id`       INT UNSIGNED NOT NULL,
    `class_id`         INT UNSIGNED NOT NULL,
    `course_id`        INT UNSIGNED NOT NULL,
    `academic_term_id` INT UNSIGNED NOT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tca` (`teacher_id`, `class_id`, `course_id`, `academic_term_id`),
    KEY `idx_tca_teacher_term` (`teacher_id`, `academic_term_id`),
    KEY `idx_tca_class_course`  (`class_id`, `course_id`),
    CONSTRAINT `fk_tca_teacher` FOREIGN KEY (`teacher_id`)       REFERENCES `teachers`(`id`)        ON DELETE RESTRICT,
    CONSTRAINT `fk_tca_class`   FOREIGN KEY (`class_id`)         REFERENCES `classes`(`id`)         ON DELETE RESTRICT,
    CONSTRAINT `fk_tca_course`  FOREIGN KEY (`course_id`)        REFERENCES `courses`(`id`)         ON DELETE RESTRICT,
    CONSTRAINT `fk_tca_term`    FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Teacher assigned to teach a course to a class in a term — attendance authorization basis';

-- ─── student_class_assignments ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `student_class_assignments` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id`       BIGINT UNSIGNED NOT NULL,
    `class_id`         INT UNSIGNED    NOT NULL,
    `academic_term_id` INT UNSIGNED    NOT NULL,
    `enrolled_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sca` (`student_id`, `class_id`, `academic_term_id`),
    KEY `idx_sca_class_term` (`class_id`, `academic_term_id`),
    CONSTRAINT `fk_sca_student` FOREIGN KEY (`student_id`)       REFERENCES `students`(`id`)        ON DELETE RESTRICT,
    CONSTRAINT `fk_sca_class`   FOREIGN KEY (`class_id`)         REFERENCES `classes`(`id`)         ON DELETE RESTRICT,
    CONSTRAINT `fk_sca_term`    FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Student enrolled in a class for a term';

-- ─── student_courses (derived lookup — DD-005) ──────────────────────────────

CREATE TABLE IF NOT EXISTS `student_courses` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id`       BIGINT UNSIGNED NOT NULL,
    `course_id`        INT UNSIGNED    NOT NULL,
    `class_id`         INT UNSIGNED    NOT NULL,
    `academic_term_id` INT UNSIGNED    NOT NULL,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_student_courses` (`student_id`, `course_id`, `academic_term_id`),
    KEY `idx_student_courses_lookup` (`student_id`, `course_id`),
    CONSTRAINT `fk_sc_student` FOREIGN KEY (`student_id`)       REFERENCES `students`(`id`)        ON DELETE RESTRICT,
    CONSTRAINT `fk_sc_course`  FOREIGN KEY (`course_id`)        REFERENCES `courses`(`id`)         ON DELETE RESTRICT,
    CONSTRAINT `fk_sc_class`   FOREIGN KEY (`class_id`)         REFERENCES `classes`(`id`)         ON DELETE RESTRICT,
    CONSTRAINT `fk_sc_term`    FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Derived from student_class_assignments + class_courses; kept in sync by the application (DD-005)';

-- ─── course_schedules ───────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `course_schedules` (
    `id`                          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `teacher_class_assignment_id` INT UNSIGNED     NOT NULL,
    `room_id`                     INT UNSIGNED     NOT NULL,
    `day_of_week`                 TINYINT UNSIGNED NOT NULL COMMENT '0=Mon … 6=Sun',
    `start_time`                  TIME             NOT NULL,
    `end_time`                    TIME             NOT NULL,
    `created_at`                  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cs_assignment_day` (`teacher_class_assignment_id`, `day_of_week`),
    KEY `idx_cs_room` (`room_id`),
    CONSTRAINT `fk_cs_assignment` FOREIGN KEY (`teacher_class_assignment_id`) REFERENCES `teacher_class_assignments`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cs_room`       FOREIGN KEY (`room_id`)                     REFERENCES `rooms`(`id`)                    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='When and where a teacher-class assignment meets';
