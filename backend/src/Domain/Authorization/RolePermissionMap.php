<?php

declare(strict_types=1);

namespace QRIVO\Domain\Authorization;

use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Enum\UserRole;

/**
 * Canonical role -> permission mapping.
 *
 * This is the single source of truth for which built-in role is granted which
 * permission. It is:
 *
 * - projected into SQL by database/migrations/002_seed_rbac_permissions.sql
 *   (that file MUST be kept in sync with this class);
 * - used by the test suite to seed an equivalent RBAC schema.
 *
 * At runtime the authoritative check is still a database lookup
 * (`role_permissions`) — see {@see \QRIVO\Application\Service\AuthorizationService}.
 * This class documents intent and keeps seeds/tests consistent.
 *
 * Security (SECURITY_RULES.md §4):
 * - SUPER_ADMIN has full system access.
 * - ADMIN is institution-level and permission-controlled.
 * - TEACHER is limited to assigned courses/classes (enforced additionally by
 *   relationship checks).
 * - STUDENT is limited to own data (enforced additionally by ownership checks).
 */
final class RolePermissionMap
{
    /**
     * @return array<string, string[]> role name => permission values
     */
    public static function all(): array
    {
        return [
            UserRole::SUPER_ADMIN->value => Permission::allValues(),

            UserRole::ADMIN->value => [
                Permission::ACADEMIC_SCHOOL_MANAGE->value,
                Permission::ACADEMIC_FACULTY_MANAGE->value,
                Permission::ACADEMIC_DEPARTMENT_MANAGE->value,
                Permission::ACADEMIC_PROGRAM_MANAGE->value,
                Permission::ACADEMIC_ACADEMIC_YEAR_MANAGE->value,
                Permission::ACADEMIC_ACADEMIC_TERM_MANAGE->value,
                Permission::ACADEMIC_CLASS_MANAGE->value,
                Permission::ACADEMIC_ROOM_MANAGE->value,
                Permission::ACADEMIC_COURSE_MANAGE->value,
                Permission::ACADEMIC_TEACHER_MANAGE->value,
                Permission::ACADEMIC_STUDENT_MANAGE->value,
                Permission::ASSIGNMENT_COURSE_MANAGE->value,
                Permission::ASSIGNMENT_SCHEDULE_MANAGE->value,
                Permission::REPORT_INSTITUTION_VIEW->value,
                Permission::SECURITY_EVENT_VIEW->value,
                Permission::AUDIT_LOG_VIEW->value,
            ],

            UserRole::TEACHER->value => [
                Permission::ATTENDANCE_SESSION_START->value,
                Permission::ATTENDANCE_SESSION_CLOSE->value,
                Permission::ATTENDANCE_SESSION_CANCEL->value,
                Permission::ATTENDANCE_LIVE_VIEW->value,
                Permission::ATTENDANCE_RECORD_UPDATE->value,
                Permission::REPORT_COURSE_VIEW->value,
                Permission::PROFILE_SELF_VIEW->value,
                Permission::SCHEDULE_SELF_VIEW->value,
            ],

            UserRole::STUDENT->value => [
                Permission::ATTENDANCE_QR_SUBMIT->value,
                Permission::REPORT_SELF_VIEW->value,
                Permission::PROFILE_SELF_VIEW->value,
                Permission::SCHEDULE_SELF_VIEW->value,
                Permission::ATTENDANCE_HISTORY_SELF_VIEW->value,
            ],
        ];
    }

    /**
     * Permissions granted to a single role name (empty for unknown roles).
     *
     * @return string[]
     */
    public static function forRole(string $roleName): array
    {
        return self::all()[$roleName] ?? [];
    }

    /**
     * Union of permissions granted to any of the given role names.
     *
     * @param string[] $roleNames
     * @return string[]
     */
    public static function forRoles(array $roleNames): array
    {
        $granted = [];
        foreach ($roleNames as $role) {
            foreach (self::forRole($role) as $permission) {
                $granted[$permission] = true;
            }
        }

        return array_keys($granted);
    }
}
