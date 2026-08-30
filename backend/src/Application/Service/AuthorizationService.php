<?php

declare(strict_types=1);

namespace QRIVO\Application\Service;

use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Enum\UserRole;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Infrastructure\Repository\PermissionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;

/**
 * Server-side authorization engine.
 *
 * Enforces the four layers required by SECURITY_RULES.md §4 and
 * ARCHITECTURE_RULES.md §6:
 *
 *   1. Role-based access control (SUPER_ADMIN / ADMIN / TEACHER / STUDENT)
 *   2. Permission-based checks (roles -> role_permissions -> permissions)
 *   3. Resource ownership (the actor owns the target record)
 *   4. Relationship-based access (teacher <-> class/course, student <-> class/course)
 *
 * Threat coverage:
 *   - IDOR / BOLA .......... requireOwnership() + relationship guards; every
 *                            resource-scoped action must pass an ownership OR a
 *                            relationship check, not just a role check.
 *   - Privilege escalation . requireRole() / requirePermission() deny by default;
 *                            guardRoleAssignment() blocks self-escalation and
 *                            non-SUPER_ADMIN granting of SUPER_ADMIN.
 *   - Unauthorized access .. all checks fail closed; denials raise
 *                            ForbiddenException (HTTP 403) and a security event.
 *
 * Principles:
 *   - The actor context comes only from a validated access token
 *     (AuthService::validateToken). Roles/permissions are re-resolved from the
 *     database here — a client-supplied role or permission list is never trusted.
 *   - Frontend visibility is NOT a security mechanism. Every privileged code path
 *     calls a guard here.
 *   - Failing a check never throws a raw error to the client; the guard raises a
 *     generic ForbiddenException.
 *
 * @phpstan-type ActorContext array{user_id:int, roles?:string[], email?:string, uuid?:string}
 */
final class AuthorizationService extends BaseService
{
    /** @var array<int, string[]> memoized permission names per user id (per request) */
    private array $permissionCache = [];

    public function __construct(
        LoggerInterface $logger,
        private readonly PermissionRepository   $permissionRepo,
        private readonly RelationshipRepository $relationshipRepo,
        private readonly SecurityLogService     $securityLog,
    ) {
        parent::__construct($logger);
    }

    // ─── 1. Role-based access control ───────────────────────────────────────────

    /**
     * @param array<string, mixed> $actor
     */
    public function isSuperAdmin(array $actor): bool
    {
        return $this->hasRole($actor, UserRole::SUPER_ADMIN);
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function hasRole(array $actor, UserRole|string $role): bool
    {
        $needle = $role instanceof UserRole ? $role->value : $role;

        return in_array($needle, $this->actorRoles($actor), true);
    }

    /**
     * @param array<string, mixed>       $actor
     * @param array<UserRole|string>     $roles
     */
    public function hasAnyRole(array $actor, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($actor, $role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>   $actor
     * @param array<UserRole|string> $roles
     * @throws ForbiddenException
     */
    public function requireAnyRole(array $actor, array $roles, string $action = 'perform this action'): void
    {
        if ($this->hasAnyRole($actor, $roles)) {
            return;
        }

        $this->deny(
            $actor,
            SecurityEventType::PRIVILEGE_ESCALATION,
            $action,
            ['required_roles' => array_map(
                static fn ($r): string => $r instanceof UserRole ? $r->value : (string) $r,
                $roles
            )],
        );
    }

    /**
     * @param array<string, mixed> $actor
     * @throws ForbiddenException
     */
    public function requireRole(array $actor, UserRole|string $role, string $action = 'perform this action'): void
    {
        $this->requireAnyRole($actor, [$role], $action);
    }

    // ─── 2. Permission-based checks ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $actor
     */
    public function hasPermission(array $actor, Permission|string $permission): bool
    {
        // SUPER_ADMIN has full system access (SECURITY_RULES.md §4). This mirrors
        // the explicit SUPER_ADMIN grants seeded in 002_seed_rbac_permissions.sql;
        // both agree, and this short-circuit is the documented interim resolution
        // of OQ-005.
        if ($this->isSuperAdmin($actor)) {
            return true;
        }

        $needle = $permission instanceof Permission ? $permission->value : $permission;

        return in_array($needle, $this->actorPermissions($actor), true);
    }

    /**
     * @param array<string, mixed>          $actor
     * @param array<Permission|string>      $permissions
     */
    public function hasAllPermissions(array $actor, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($actor, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $actor
     * @throws ForbiddenException
     */
    public function requirePermission(
        array $actor,
        Permission|string $permission,
        string $action = 'perform this action',
    ): void {
        if ($this->hasPermission($actor, $permission)) {
            return;
        }

        $needle = $permission instanceof Permission ? $permission->value : $permission;
        $this->deny($actor, SecurityEventType::UNAUTHORIZED_ACCESS, $action, ['required_permission' => $needle]);
    }

    // ─── 3. Resource ownership (IDOR / BOLA) ────────────────────────────────────

    /**
     * True when the actor is the owner of a resource whose owning user id is
     * $ownerUserId.
     *
     * @param array<string, mixed> $actor
     */
    public function ownsResource(array $actor, ?int $ownerUserId): bool
    {
        $actorId = $this->actorId($actor);

        return $ownerUserId !== null && $actorId !== null && $actorId === $ownerUserId;
    }

    /**
     * Require that the actor owns the target resource. Optionally allow a set of
     * privileged roles (e.g. ADMIN) to bypass ownership.
     *
     * A failure is recorded as an IDOR/BOLA attempt.
     *
     * @param array<string, mixed>   $actor
     * @param array<UserRole|string> $bypassRoles roles allowed to access regardless of ownership
     * @throws ForbiddenException
     */
    public function requireOwnership(
        array $actor,
        ?int $ownerUserId,
        string $resourceType,
        int|string|null $resourceId = null,
        array $bypassRoles = [],
    ): void {
        if ($bypassRoles !== [] && $this->hasAnyRole($actor, $bypassRoles)) {
            return;
        }

        if ($this->ownsResource($actor, $ownerUserId)) {
            return;
        }

        $this->deny(
            $actor,
            SecurityEventType::IDOR_ATTEMPT,
            "access {$resourceType}",
            [
                'resource_type'  => $resourceType,
                'resource_id'    => $resourceId,
                'resource_owner' => $ownerUserId,
            ],
        );
    }

    // ─── 4. Relationship-based access ───────────────────────────────────────────

    /**
     * @param array<string, mixed> $actor
     */
    public function teacherCanAccessClassCourse(
        array $actor,
        int $classId,
        int $courseId,
        ?int $academicTermId = null,
    ): bool {
        $actorId = $this->actorId($actor);
        if ($actorId === null || !$this->hasRole($actor, UserRole::TEACHER)) {
            return false;
        }

        return $this->relationshipRepo->teacherAssignedToClassCourse($actorId, $classId, $courseId, $academicTermId);
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function teacherCanAccessClass(array $actor, int $classId, ?int $academicTermId = null): bool
    {
        $actorId = $this->actorId($actor);
        if ($actorId === null || !$this->hasRole($actor, UserRole::TEACHER)) {
            return false;
        }

        return $this->relationshipRepo->teacherAssignedToClass($actorId, $classId, $academicTermId);
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function teacherCanAccessCourse(array $actor, int $courseId, ?int $academicTermId = null): bool
    {
        $actorId = $this->actorId($actor);
        if ($actorId === null || !$this->hasRole($actor, UserRole::TEACHER)) {
            return false;
        }

        return $this->relationshipRepo->teacherTeachesCourse($actorId, $courseId, $academicTermId);
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function studentEnrolledInCourse(array $actor, int $courseId, ?int $academicTermId = null): bool
    {
        $actorId = $this->actorId($actor);
        if ($actorId === null || !$this->hasRole($actor, UserRole::STUDENT)) {
            return false;
        }

        return $this->relationshipRepo->studentEnrolledInCourse($actorId, $courseId, $academicTermId);
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function studentEnrolledInClass(array $actor, int $classId, ?int $academicTermId = null): bool
    {
        $actorId = $this->actorId($actor);
        if ($actorId === null || !$this->hasRole($actor, UserRole::STUDENT)) {
            return false;
        }

        return $this->relationshipRepo->studentEnrolledInClass($actorId, $classId, $academicTermId);
    }

    /**
     * Require that the teacher is assigned to teach $courseId to $classId. This
     * is the authorization basis for attendance session creation
     * (ATTENDANCE_ALGORITHM.md §2, steps 3-4).
     *
     * @param array<string, mixed> $actor
     * @throws ForbiddenException
     */
    public function requireTeacherClassCourse(
        array $actor,
        int $classId,
        int $courseId,
        ?int $academicTermId = null,
        string $action = 'manage attendance for this class',
    ): void {
        if ($this->teacherCanAccessClassCourse($actor, $classId, $courseId, $academicTermId)) {
            return;
        }

        $this->deny(
            $actor,
            SecurityEventType::UNAUTHORIZED_ACCESS,
            $action,
            ['class_id' => $classId, 'course_id' => $courseId, 'academic_term_id' => $academicTermId],
        );
    }

    /**
     * Require that the student is enrolled in $courseId (challenge-response
     * validation step 8).
     *
     * @param array<string, mixed> $actor
     * @throws ForbiddenException
     */
    public function requireStudentCourseMembership(
        array $actor,
        int $courseId,
        ?int $academicTermId = null,
        string $action = 'submit attendance for this course',
    ): void {
        if ($this->studentEnrolledInCourse($actor, $courseId, $academicTermId)) {
            return;
        }

        $this->deny(
            $actor,
            SecurityEventType::UNAUTHORIZED_ATTENDANCE,
            $action,
            ['course_id' => $courseId, 'academic_term_id' => $academicTermId],
        );
    }

    // ─── Privilege-escalation guard for role assignment ─────────────────────────

    /**
     * Guard the "assign role to user" operation.
     *
     * Rules:
     *   - The actor must hold the iam.role.assign permission (SUPER_ADMIN only in
     *     the default map).
     *   - Only a SUPER_ADMIN may grant or revoke the SUPER_ADMIN role.
     *   - Nobody may change their own role set (no self-escalation, no
     *     self-lockout of a privileged actor by a lower actor is possible anyway
     *     because of the permission gate).
     *
     * @param array<string, mixed> $actor
     * @throws ForbiddenException
     */
    public function guardRoleAssignment(array $actor, int $targetUserId, UserRole|string $roleToAssign): void
    {
        $role = $roleToAssign instanceof UserRole ? $roleToAssign : UserRole::from((string) $roleToAssign);

        $this->requirePermission($actor, Permission::IAM_ROLE_ASSIGN, 'assign user roles');

        if ($this->actorId($actor) === $targetUserId) {
            $this->deny($actor, SecurityEventType::PRIVILEGE_ESCALATION, 'modify own roles', [
                'target_user_id' => $targetUserId,
                'role'           => $role->value,
            ]);
        }

        if ($role === UserRole::SUPER_ADMIN && !$this->isSuperAdmin($actor)) {
            $this->deny($actor, SecurityEventType::PRIVILEGE_ESCALATION, 'grant SUPER_ADMIN', [
                'target_user_id' => $targetUserId,
                'role'           => $role->value,
            ]);
        }
    }

    // ─── Internals ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $actor
     * @return string[]
     */
    private function actorRoles(array $actor): array
    {
        $roles = $actor['roles'] ?? [];

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    /**
     * Permission names for the actor, resolved from the database and memoized for
     * the lifetime of this service instance (one request).
     *
     * @param array<string, mixed> $actor
     * @return string[]
     */
    private function actorPermissions(array $actor): array
    {
        $id = $this->actorId($actor);
        if ($id === null) {
            return [];
        }

        if (!isset($this->permissionCache[$id])) {
            $this->permissionCache[$id] = $this->permissionRepo->getPermissionNamesForUser($id);
        }

        return $this->permissionCache[$id];
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function actorId(array $actor): ?int
    {
        $id = $actor['user_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Record a denial as a security event and raise a generic ForbiddenException.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $context
     * @throws ForbiddenException
     */
    private function deny(array $actor, SecurityEventType $eventType, string $action, array $context = []): never
    {
        $severity = match ($eventType) {
            SecurityEventType::PRIVILEGE_ESCALATION,
            SecurityEventType::IDOR_ATTEMPT           => 'HIGH',
            SecurityEventType::UNAUTHORIZED_ATTENDANCE => 'MEDIUM',
            default                                   => 'MEDIUM',
        };

        $this->securityLog->recordSecurityEvent(
            $eventType,
            $severity,
            $this->actorId($actor),
            is_string($actor['ip_address'] ?? null) ? $actor['ip_address'] : null,
            is_string($actor['user_agent'] ?? null) ? $actor['user_agent'] : null,
            array_merge(['action' => $action, 'actor_roles' => $this->actorRoles($actor)], $context),
        );

        // Generic message — never leak which check failed or why.
        throw new ForbiddenException('You are not authorized to ' . $action . '.');
    }
}
