-- QRIVO — RBAC Permissions Seed Migration
-- Migration: 002_seed_rbac_permissions
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
--
-- Depends on: 001_create_auth_tables.sql (roles, permissions, role_permissions)
--
-- Seeds the `permissions` catalogue and the `role_permissions` mapping used by
-- the server-side authorization layer (Phase 7).
--
-- Permission names are derived from PROJECT_SPECIFICATION.md §6 functional
-- requirements. Role -> permission assignments mirror
-- backend/src/Domain/Authorization/RolePermissionMap.php — the two MUST be kept
-- in sync. RolePermissionMap is the canonical reference; this file is its SQL
-- projection.
--
-- Idempotent: re-running makes no changes (INSERT IGNORE on unique keys).
--
-- NOTE: the relationship-based authorization tables (teachers, students,
-- teacher_class_assignments, teacher_courses, student_courses,
-- student_class_assignments) are created in the Academic Structure phase
-- (Phase 8). Until they exist the relationship resolver denies by default —
-- see docs/ACCEPTED_DEVIATIONS.md AD-001.

SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

-- ─── Permission catalogue ─────────────────────────────────────────────────────

INSERT IGNORE INTO `permissions` (`name`, `display_name`) VALUES
    -- Academic structure management (spec §6.3) — ADMIN
    ('academic.school.manage',         'Manage schools'),
    ('academic.faculty.manage',        'Manage faculties'),
    ('academic.department.manage',     'Manage departments'),
    ('academic.program.manage',        'Manage programs'),
    ('academic.academic_year.manage',  'Manage academic years'),
    ('academic.academic_term.manage',  'Manage academic terms'),
    ('academic.class.manage',          'Manage classes'),
    ('academic.room.manage',           'Manage rooms'),
    ('academic.course.manage',         'Manage courses'),
    ('academic.teacher.manage',        'Manage teacher profiles'),
    ('academic.student.manage',        'Manage student profiles'),
    -- Course & schedule assignment (spec §6.4) — ADMIN
    ('assignment.course.manage',       'Manage course/teacher/class assignments'),
    ('assignment.schedule.manage',     'Manage course schedules'),
    -- Identity & access management (spec §6.2) — SUPER_ADMIN
    ('iam.role.assign',                'Assign or revoke user roles'),
    -- Attendance sessions (spec §6.5, §6.10) — TEACHER
    ('attendance.session.start',       'Start an attendance session'),
    ('attendance.session.close',       'Close an attendance session'),
    ('attendance.session.cancel',      'Cancel an attendance session'),
    -- Live attendance (spec §6.8) — TEACHER
    ('attendance.live.view',           'View live attendance for own session'),
    -- Manual attendance (spec §6.9) — TEACHER
    ('attendance.record.update',       'Manually change a student attendance status'),
    -- Challenge-response / QR attendance (spec §6.7, §6.12) — STUDENT
    ('attendance.qr.submit',           'Submit QR challenge-response attendance'),
    -- Reporting (spec §6.16)
    ('report.institution.view',        'View institution-level attendance reports'),
    ('report.course.view',             'View course/class attendance reports for assigned courses'),
    ('report.self.view',               'View own attendance report'),
    -- Security & audit (spec §6.15) — ADMIN
    ('security.event.view',            'View security events'),
    ('audit.log.view',                 'View audit logs'),
    -- Self-service (spec §6.11) — all authenticated actors
    ('profile.self.view',              'View own profile'),
    ('schedule.self.view',             'View own schedule'),
    ('attendance.history.self.view',   'View own attendance history');

-- ─── SUPER_ADMIN → every permission (full system access, SECURITY_RULES.md §4) ──

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
  FROM `roles` r
  CROSS JOIN `permissions` p
 WHERE r.`name` = 'SUPER_ADMIN';

-- ─── ADMIN → institutional administration ─────────────────────────────────────

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
  FROM `roles` r
  JOIN `permissions` p
    ON p.`name` IN (
        'academic.school.manage', 'academic.faculty.manage', 'academic.department.manage',
        'academic.program.manage', 'academic.academic_year.manage', 'academic.academic_term.manage',
        'academic.class.manage', 'academic.room.manage', 'academic.course.manage',
        'academic.teacher.manage', 'academic.student.manage',
        'assignment.course.manage', 'assignment.schedule.manage',
        'report.institution.view',
        'security.event.view', 'audit.log.view'
    )
 WHERE r.`name` = 'ADMIN';

-- ─── TEACHER → own courses/classes only ──────────────────────────────────────

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
  FROM `roles` r
  JOIN `permissions` p
    ON p.`name` IN (
        'attendance.session.start', 'attendance.session.close', 'attendance.session.cancel',
        'attendance.live.view', 'attendance.record.update',
        'report.course.view',
        'profile.self.view', 'schedule.self.view'
    )
 WHERE r.`name` = 'TEACHER';

-- ─── STUDENT → own data only ────────────────────────────────────────────────

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
  FROM `roles` r
  JOIN `permissions` p
    ON p.`name` IN (
        'attendance.qr.submit',
        'report.self.view',
        'profile.self.view', 'schedule.self.view', 'attendance.history.self.view'
    )
 WHERE r.`name` = 'STUDENT';
