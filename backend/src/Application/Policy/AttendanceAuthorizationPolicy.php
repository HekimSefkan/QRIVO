<?php

declare(strict_types=1);

namespace QRIVO\Application\Policy;

use QRIVO\Application\Service\AuthorizationService;
use QRIVO\Domain\Contract\PolicyInterface;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Enum\UserRole;

/**
 * Relationship-based authorization for attendance resources.
 *
 * Combines all four layers for the attendance domain:
 *   - role                (TEACHER for management abilities, STUDENT for submit)
 *   - permission          (attendance.session.*, attendance.record.update, ...)
 *   - relationship        (teacher assigned to class+course; student enrolled)
 *   - ownership            (teacher owns the session; student acts on own record)
 *
 * This is a pure predicate (returns bool). Controllers/services that need to
 * abort a request use the throwing guards on {@see AuthorizationService}; this
 * policy is for read-side "can the actor see this button / row" decisions made
 * *server-side* and for composing higher-level checks.
 *
 * Abilities:
 *   - 'session.start'   resource: class_id, course_id, academic_term_id?
 *   - 'session.close'   resource: teacher_user_id (owner)  [+ class/course]
 *   - 'session.cancel'  resource: teacher_user_id (owner)  [+ class/course]
 *   - 'live.view'       resource: teacher_user_id (owner)
 *   - 'record.update'   resource: teacher_user_id (owner), class_id, course_id
 *   - 'qr.submit'       resource: course_id, academic_term_id?
 */
final class AttendanceAuthorizationPolicy implements PolicyInterface
{
    public function __construct(private readonly AuthorizationService $authz) {}

    /**
     * @param array<string, mixed>      $actor
     * @param array<string, mixed>|null $resource
     */
    public function authorize(array $actor, string $ability, ?array $resource = null): bool
    {
        $resource ??= [];

        $classId = $this->int($resource['class_id'] ?? null);
        $courseId = $this->int($resource['course_id'] ?? null);
        $termId = $this->int($resource['academic_term_id'] ?? null);
        $ownerUserId = $this->int($resource['teacher_user_id'] ?? null);

        return match ($ability) {
            'session.start' =>
                $this->authz->hasPermission($actor, Permission::ATTENDANCE_SESSION_START)
                && $classId !== null && $courseId !== null
                && $this->authz->teacherCanAccessClassCourse($actor, $classId, $courseId, $termId),

            'session.close', 'session.cancel' =>
                $this->authz->hasPermission(
                    $actor,
                    $ability === 'session.close'
                        ? Permission::ATTENDANCE_SESSION_CLOSE
                        : Permission::ATTENDANCE_SESSION_CANCEL,
                )
                && $this->ownsOrSuperAdmin($actor, $ownerUserId),

            'live.view' =>
                $this->authz->hasPermission($actor, Permission::ATTENDANCE_LIVE_VIEW)
                && $this->ownsOrSuperAdmin($actor, $ownerUserId),

            'record.update' =>
                $this->authz->hasPermission($actor, Permission::ATTENDANCE_RECORD_UPDATE)
                && $this->ownsOrSuperAdmin($actor, $ownerUserId)
                // A teacher may only override records in a class+course they are assigned to.
                && ($this->authz->isSuperAdmin($actor)
                    || ($classId !== null && $courseId !== null
                        && $this->authz->teacherCanAccessClassCourse($actor, $classId, $courseId, $termId))),

            'qr.submit' =>
                $this->authz->hasRole($actor, UserRole::STUDENT)
                && $this->authz->hasPermission($actor, Permission::ATTENDANCE_QR_SUBMIT)
                && $courseId !== null
                && $this->authz->studentEnrolledInCourse($actor, $courseId, $termId),

            default => false,
        };
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function ownsOrSuperAdmin(array $actor, ?int $ownerUserId): bool
    {
        return $this->authz->isSuperAdmin($actor) || $this->authz->ownsResource($actor, $ownerUserId);
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
