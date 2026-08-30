<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\AuthorizationService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Enum\UserRole;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\PermissionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Tests\Support\RbacSchemaTrait;

/**
 * AuthorizationService unit tests.
 *
 * Covers all four authorization layers plus IDOR/BOLA and privilege-escalation
 * defence, using an in-memory SQLite schema that mirrors migrations 001 + 002.
 */
final class AuthorizationServiceTest extends TestCase
{
    use RbacSchemaTrait;

    private AuthorizationService $authz;

    protected function setUp(): void
    {
        $this->pdo = $this->buildRbacDb();
        $db        = $this->buildConnection();
        $logger    = $this->createMock(LoggerInterface::class);

        $securityLog = new SecurityLogService(
            $logger,
            new SecurityEventRepository($db),
            new AuditLogRepository($db),
        );

        $this->authz = new AuthorizationService(
            $logger,
            new PermissionRepository($db),
            new RelationshipRepository($db),
            $securityLog,
        );
    }

    // ─── 1. Role-based access control ───────────────────────────────────────────

    public function test_has_role_is_true_for_assigned_role(): void
    {
        $uid   = $this->createUser('t@x.test', ['TEACHER']);
        $actor = $this->actorContext($uid, ['TEACHER']);

        $this->assertTrue($this->authz->hasRole($actor, UserRole::TEACHER));
        $this->assertFalse($this->authz->hasRole($actor, UserRole::ADMIN));
    }

    public function test_has_any_role(): void
    {
        $actor = $this->actorContext(1, ['STUDENT']);

        $this->assertTrue($this->authz->hasAnyRole($actor, [UserRole::ADMIN, UserRole::STUDENT]));
        $this->assertFalse($this->authz->hasAnyRole($actor, [UserRole::ADMIN, UserRole::TEACHER]));
    }

    public function test_require_role_passes_for_correct_role(): void
    {
        $actor = $this->actorContext(1, ['ADMIN']);

        $this->authz->requireRole($actor, UserRole::ADMIN);
        $this->addToAssertionCount(1);
    }

    public function test_require_role_throws_forbidden_for_wrong_role(): void
    {
        $actor = $this->actorContext(1, ['STUDENT']);

        $this->expectException(ForbiddenException::class);
        $this->authz->requireRole($actor, UserRole::ADMIN, 'manage schools');
    }

    public function test_require_role_denial_records_privilege_escalation_event(): void
    {
        $uid   = $this->createUser('s@x.test', ['STUDENT']);
        $actor = $this->actorContext($uid, ['STUDENT']);

        try {
            $this->authz->requireRole($actor, UserRole::ADMIN, 'manage schools');
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
            // expected
        }

        $this->assertSame(1, $this->securityEventCount(SecurityEventType::PRIVILEGE_ESCALATION->value));
    }

    // ─── 2. Permission-based checks ─────────────────────────────────────────────

    public function test_admin_has_academic_manage_permission(): void
    {
        $uid   = $this->createUser('a@x.test', ['ADMIN']);
        $actor = $this->actorContext($uid, ['ADMIN']);

        $this->assertTrue($this->authz->hasPermission($actor, Permission::ACADEMIC_SCHOOL_MANAGE));
        $this->assertTrue($this->authz->hasPermission($actor, Permission::SECURITY_EVENT_VIEW));
    }

    public function test_admin_does_not_have_teacher_or_student_permissions(): void
    {
        $uid   = $this->createUser('a@x.test', ['ADMIN']);
        $actor = $this->actorContext($uid, ['ADMIN']);

        $this->assertFalse($this->authz->hasPermission($actor, Permission::ATTENDANCE_SESSION_START));
        $this->assertFalse($this->authz->hasPermission($actor, Permission::ATTENDANCE_QR_SUBMIT));
    }

    public function test_teacher_has_only_teacher_permissions(): void
    {
        $uid   = $this->createUser('t@x.test', ['TEACHER']);
        $actor = $this->actorContext($uid, ['TEACHER']);

        $this->assertTrue($this->authz->hasPermission($actor, Permission::ATTENDANCE_SESSION_START));
        $this->assertTrue($this->authz->hasPermission($actor, Permission::ATTENDANCE_RECORD_UPDATE));
        $this->assertFalse($this->authz->hasPermission($actor, Permission::ACADEMIC_SCHOOL_MANAGE));
        $this->assertFalse($this->authz->hasPermission($actor, Permission::IAM_ROLE_ASSIGN));
    }

    public function test_student_has_only_student_permissions(): void
    {
        $uid   = $this->createUser('s@x.test', ['STUDENT']);
        $actor = $this->actorContext($uid, ['STUDENT']);

        $this->assertTrue($this->authz->hasPermission($actor, Permission::ATTENDANCE_QR_SUBMIT));
        $this->assertTrue($this->authz->hasPermission($actor, Permission::REPORT_SELF_VIEW));
        $this->assertFalse($this->authz->hasPermission($actor, Permission::ATTENDANCE_SESSION_START));
        $this->assertFalse($this->authz->hasPermission($actor, Permission::REPORT_INSTITUTION_VIEW));
    }

    public function test_super_admin_has_every_permission(): void
    {
        $uid   = $this->createUser('root@x.test', ['SUPER_ADMIN']);
        $actor = $this->actorContext($uid, ['SUPER_ADMIN']);

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                $this->authz->hasPermission($actor, $permission),
                "SUPER_ADMIN should have {$permission->value}",
            );
        }
    }

    public function test_non_super_admin_permissions_resolve_from_database(): void
    {
        // Permission checks for non-SUPER_ADMIN actors go through the database
        // (user_roles -> role_permissions -> permissions), never a client-supplied
        // permission list.
        $uid   = $this->createUser('s@x.test', ['STUDENT']);
        $actor = $this->actorContext($uid, ['STUDENT']);

        $this->assertFalse($this->authz->hasPermission($actor, Permission::ACADEMIC_SCHOOL_MANAGE));

        $repo = new PermissionRepository($this->buildConnection());
        $resolved = $repo->getPermissionNamesForUser($uid);
        $this->assertNotContains('academic.school.manage', $resolved);
        $this->assertContains('attendance.qr.submit', $resolved);
    }

    public function test_require_permission_denial_records_unauthorized_access_event(): void
    {
        $uid   = $this->createUser('t@x.test', ['TEACHER']);
        $actor = $this->actorContext($uid, ['TEACHER']);

        $this->expectException(ForbiddenException::class);
        try {
            $this->authz->requirePermission($actor, Permission::ACADEMIC_SCHOOL_MANAGE, 'manage schools');
        } finally {
            $this->assertSame(1, $this->securityEventCount(SecurityEventType::UNAUTHORIZED_ACCESS->value));
        }
    }

    // ─── 3. Resource ownership (IDOR / BOLA) ────────────────────────────────────

    public function test_owns_resource_true_when_ids_match(): void
    {
        $actor = $this->actorContext(42, ['STUDENT']);

        $this->assertTrue($this->authz->ownsResource($actor, 42));
        $this->assertFalse($this->authz->ownsResource($actor, 43));
        $this->assertFalse($this->authz->ownsResource($actor, null));
    }

    public function test_require_ownership_passes_for_owner(): void
    {
        $actor = $this->actorContext(42, ['STUDENT']);

        $this->authz->requireOwnership($actor, 42, 'attendance_record', 99);
        $this->addToAssertionCount(1);
    }

    public function test_require_ownership_blocks_cross_user_access_and_logs_idor(): void
    {
        $victim  = $this->createUser('victim@x.test', ['STUDENT']);
        $attacker = $this->createUser('attacker@x.test', ['STUDENT']);
        $actor   = $this->actorContext($attacker, ['STUDENT']);

        try {
            // attacker tries to read victim's record by guessing the owner id
            $this->authz->requireOwnership($actor, $victim, 'attendance_history', 7);
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException $e) {
            $this->assertStringNotContainsString('owner', strtolower($e->getMessage()));
        }

        $this->assertSame(1, $this->securityEventCount(SecurityEventType::IDOR_ATTEMPT->value));
    }

    public function test_require_ownership_allows_bypass_role(): void
    {
        $admin = $this->createUser('a@x.test', ['ADMIN']);
        $actor = $this->actorContext($admin, ['ADMIN']);

        // ADMIN is an allowed bypass role for viewing a student's data
        $this->authz->requireOwnership($actor, 12345, 'student_report', 1, [UserRole::ADMIN]);
        $this->addToAssertionCount(1);
        $this->assertSame(0, $this->securityEventCount());
    }

    // ─── 4. Relationship-based access ───────────────────────────────────────────

    public function test_teacher_can_access_assigned_class_course_only(): void
    {
        $uid = $this->createUser('t@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, employee_number) VALUES (?, ?)')->execute([$uid, 'E1']);
        $teacherId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO teacher_class_assignments (teacher_id, class_id, course_id, academic_term_id) VALUES (?, 10, 20, 1)'
        )->execute([$teacherId]);

        $actor = $this->actorContext($uid, ['TEACHER']);

        $this->assertTrue($this->authz->teacherCanAccessClassCourse($actor, 10, 20, 1));
        $this->assertFalse($this->authz->teacherCanAccessClassCourse($actor, 10, 21, 1), 'different course');
        $this->assertFalse($this->authz->teacherCanAccessClassCourse($actor, 11, 20, 1), 'different class');
        $this->assertFalse($this->authz->teacherCanAccessClassCourse($actor, 10, 20, 2), 'different term');
    }

    public function test_teacher_without_profile_has_no_relationship_access(): void
    {
        $uid   = $this->createUser('t@x.test', ['TEACHER']);
        $actor = $this->actorContext($uid, ['TEACHER']);

        $this->assertFalse($this->authz->teacherCanAccessClassCourse($actor, 10, 20, 1));
    }

    public function test_require_teacher_class_course_blocks_unassigned_teacher_and_logs(): void
    {
        $uid = $this->createUser('t@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, employee_number) VALUES (?, ?)')->execute([$uid, 'E1']);
        $teacherId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO teacher_class_assignments (teacher_id, class_id, course_id, academic_term_id) VALUES (?, 10, 20, 1)'
        )->execute([$teacherId]);

        $actor = $this->actorContext($uid, ['TEACHER']);

        $this->expectException(ForbiddenException::class);
        try {
            $this->authz->requireTeacherClassCourse($actor, 99, 99, 1, 'start an attendance session');
        } finally {
            $this->assertSame(1, $this->securityEventCount(SecurityEventType::UNAUTHORIZED_ACCESS->value));
        }
    }

    public function test_student_course_membership_relationship(): void
    {
        $uid = $this->createUser('s@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, student_number) VALUES (?, ?)')->execute([$uid, 'S1']);
        $studentId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO student_courses (student_id, course_id, class_id, academic_term_id) VALUES (?, 20, 10, 1)'
        )->execute([$studentId]);

        $actor = $this->actorContext($uid, ['STUDENT']);

        $this->assertTrue($this->authz->studentEnrolledInCourse($actor, 20, 1));
        $this->assertFalse($this->authz->studentEnrolledInCourse($actor, 21, 1));
    }

    public function test_require_student_course_membership_blocks_non_member_and_logs_unauthorized_attendance(): void
    {
        $uid   = $this->createUser('s@x.test', ['STUDENT']);
        $actor = $this->actorContext($uid, ['STUDENT']);

        $this->expectException(ForbiddenException::class);
        try {
            $this->authz->requireStudentCourseMembership($actor, 20, 1, 'submit attendance');
        } finally {
            $this->assertSame(1, $this->securityEventCount(SecurityEventType::UNAUTHORIZED_ATTENDANCE->value));
        }
    }

    public function test_relationship_checks_are_role_scoped(): void
    {
        // An ADMIN is not a teacher/student — relationship predicates return false.
        $actor = $this->actorContext(1, ['ADMIN']);

        $this->assertFalse($this->authz->teacherCanAccessClassCourse($actor, 10, 20, 1));
        $this->assertFalse($this->authz->studentEnrolledInCourse($actor, 20, 1));
    }

    // ─── Privilege escalation guard ────────────────────────────────────────────

    public function test_guard_role_assignment_allows_super_admin(): void
    {
        $root = $this->createUser('root@x.test', ['SUPER_ADMIN']);
        $target = $this->createUser('u@x.test', ['STUDENT']);
        $actor = $this->actorContext($root, ['SUPER_ADMIN']);

        $this->authz->guardRoleAssignment($actor, $target, UserRole::TEACHER);
        $this->addToAssertionCount(1);
    }

    public function test_guard_role_assignment_blocks_non_privileged_actor(): void
    {
        $uid   = $this->createUser('t@x.test', ['TEACHER']);
        $actor = $this->actorContext($uid, ['TEACHER']);

        $this->expectException(ForbiddenException::class);
        $this->authz->guardRoleAssignment($actor, 999, UserRole::ADMIN);
    }

    public function test_guard_role_assignment_blocks_self_modification(): void
    {
        $root  = $this->createUser('root@x.test', ['SUPER_ADMIN']);
        $actor = $this->actorContext($root, ['SUPER_ADMIN']);

        try {
            $this->authz->guardRoleAssignment($actor, $root, UserRole::ADMIN);
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
            // expected
        }

        $this->assertSame(1, $this->securityEventCount(SecurityEventType::PRIVILEGE_ESCALATION->value));
    }

    public function test_guard_role_assignment_blocks_admin_granting_super_admin(): void
    {
        // An ADMIN who was somehow granted iam.role.assign still cannot grant SUPER_ADMIN.
        $adminUid = $this->createUser('a@x.test', ['ADMIN']);
        $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT (SELECT id FROM roles WHERE name = ?), (SELECT id FROM permissions WHERE name = ?)'
        )->execute(['ADMIN', Permission::IAM_ROLE_ASSIGN->value]);

        $actor = $this->actorContext($adminUid, ['ADMIN']);

        $this->expectException(ForbiddenException::class);
        try {
            $this->authz->guardRoleAssignment($actor, 999, UserRole::SUPER_ADMIN);
        } finally {
            $this->assertSame(1, $this->securityEventCount(SecurityEventType::PRIVILEGE_ESCALATION->value));
        }
    }

    // ─── Denial hygiene ───────────────────────────────────────────────────────

    public function test_denial_message_is_generic(): void
    {
        $actor = $this->actorContext(1, ['STUDENT']);

        try {
            $this->authz->requirePermission($actor, Permission::AUDIT_LOG_VIEW, 'view audit logs');
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException $e) {
            $this->assertStringNotContainsString('permission', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('audit_log_view', strtolower($e->getMessage()));
            $this->assertSame('You are not authorized to view audit logs.', $e->getMessage());
        }
    }

    public function test_no_roles_context_is_denied_everything(): void
    {
        $actor = ['user_id' => 500, 'roles' => []];

        $this->assertFalse($this->authz->hasPermission($actor, Permission::PROFILE_SELF_VIEW));
        $this->assertFalse($this->authz->hasAnyRole($actor, [UserRole::STUDENT, UserRole::TEACHER, UserRole::ADMIN]));
        $this->expectException(ForbiddenException::class);
        $this->authz->requireRole($actor, UserRole::STUDENT);
    }
}
