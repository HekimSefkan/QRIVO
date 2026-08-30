<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Granular permission vocabulary for RBAC.
 *
 * Names are derived from PROJECT_SPECIFICATION.md §6 functional requirements.
 * The role -> permission mapping lives in
 * {@see \QRIVO\Domain\Authorization\RolePermissionMap} and is projected into SQL
 * by database/migrations/002_seed_rbac_permissions.sql.
 *
 * Security (SECURITY_RULES.md §4):
 * - Having a role is NOT sufficient — a permission grant is also required for
 *   privileged actions, and resource ownership / relationship checks are layered
 *   on top for resource-scoped actions.
 * - Every check is performed server-side. Frontend visibility is never a
 *   security boundary.
 */
enum Permission: string
{
    // Academic structure management (spec §6.3) — ADMIN
    case ACADEMIC_SCHOOL_MANAGE        = 'academic.school.manage';
    case ACADEMIC_FACULTY_MANAGE       = 'academic.faculty.manage';
    case ACADEMIC_DEPARTMENT_MANAGE    = 'academic.department.manage';
    case ACADEMIC_PROGRAM_MANAGE       = 'academic.program.manage';
    case ACADEMIC_ACADEMIC_YEAR_MANAGE = 'academic.academic_year.manage';
    case ACADEMIC_ACADEMIC_TERM_MANAGE = 'academic.academic_term.manage';
    case ACADEMIC_CLASS_MANAGE         = 'academic.class.manage';
    case ACADEMIC_ROOM_MANAGE          = 'academic.room.manage';
    case ACADEMIC_COURSE_MANAGE        = 'academic.course.manage';
    case ACADEMIC_TEACHER_MANAGE       = 'academic.teacher.manage';
    case ACADEMIC_STUDENT_MANAGE       = 'academic.student.manage';

    // Course & schedule assignment (spec §6.4) — ADMIN
    case ASSIGNMENT_COURSE_MANAGE      = 'assignment.course.manage';
    case ASSIGNMENT_SCHEDULE_MANAGE    = 'assignment.schedule.manage';

    // Identity & access management (spec §6.2) — SUPER_ADMIN
    case IAM_ROLE_ASSIGN               = 'iam.role.assign';

    // Attendance sessions (spec §6.5, §6.10) — TEACHER
    case ATTENDANCE_SESSION_START      = 'attendance.session.start';
    case ATTENDANCE_SESSION_CLOSE      = 'attendance.session.close';
    case ATTENDANCE_SESSION_CANCEL     = 'attendance.session.cancel';

    // Live attendance (spec §6.8) — TEACHER
    case ATTENDANCE_LIVE_VIEW          = 'attendance.live.view';

    // Manual attendance (spec §6.9) — TEACHER
    case ATTENDANCE_RECORD_UPDATE      = 'attendance.record.update';

    // Challenge-response / QR attendance (spec §6.7, §6.12) — STUDENT
    case ATTENDANCE_QR_SUBMIT          = 'attendance.qr.submit';

    // Reporting (spec §6.16)
    case REPORT_INSTITUTION_VIEW       = 'report.institution.view';
    case REPORT_COURSE_VIEW            = 'report.course.view';
    case REPORT_SELF_VIEW              = 'report.self.view';

    // Security & audit (spec §6.15) — ADMIN
    case SECURITY_EVENT_VIEW           = 'security.event.view';
    case AUDIT_LOG_VIEW                = 'audit.log.view';

    // Self-service (spec §6.11) — all authenticated actors
    case PROFILE_SELF_VIEW             = 'profile.self.view';
    case SCHEDULE_SELF_VIEW            = 'schedule.self.view';
    case ATTENDANCE_HISTORY_SELF_VIEW  = 'attendance.history.self.view';

    /**
     * All permission string values.
     *
     * @return string[]
     */
    public static function allValues(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
