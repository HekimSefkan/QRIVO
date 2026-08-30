<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Policy;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Policy\AttendanceAuthorizationPolicy;
use QRIVO\Application\Policy\SelfOwnedResourcePolicy;
use QRIVO\Application\Service\AuthorizationService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\UserRole;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\PermissionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Tests\Support\RbacSchemaTrait;

/**
 * Tests for the PolicyInterface implementations:
 * - SelfOwnedResourcePolicy (resource ownership / IDOR)
 * - AttendanceAuthorizationPolicy (role + permission + relationship + ownership)
 */
final class AuthorizationPolicyTest extends TestCase
{
    use RbacSchemaTrait;

    private AuthorizationService $authz;

    protected function setUp(): void
    {
        $this->pdo = $this->buildRbacDb();
        $db        = $this->buildConnection();
        $logger    = $this->createMock(LoggerInterface::class);

        $this->authz = new AuthorizationService(
            $logger,
            new PermissionRepository($db),
            new RelationshipRepository($db),
            new SecurityLogService($logger, new SecurityEventRepository($db), new AuditLogRepository($db)),
        );
    }

    // ─── SelfOwnedResourcePolicy ───────────────────────────────────────────────

    public function test_self_owned_policy_grants_owner_only(): void
    {
        $policy = new SelfOwnedResourcePolicy($this->authz);
        $actor  = $this->actorContext(7, ['STUDENT']);

        $this->assertTrue($policy->authorize($actor, 'view', ['owner_user_id' => 7]));
        $this->assertFalse($policy->authorize($actor, 'view', ['owner_user_id' => 8]));
        $this->assertFalse($policy->authorize($actor, 'view', null));
    }

    public function test_self_owned_policy_respects_bypass_roles(): void
    {
        $policy = new SelfOwnedResourcePolicy($this->authz, [UserRole::ADMIN, UserRole::SUPER_ADMIN]);
        $admin  = $this->actorContext(1, ['ADMIN']);
        $student = $this->actorContext(2, ['STUDENT']);

        $this->assertTrue($policy->authorize($admin, 'view', ['owner_user_id' => 999]));
        $this->assertFalse($policy->authorize($student, 'view', ['owner_user_id' => 999]));
    }

    // ─── AttendanceAuthorizationPolicy ─────────────────────────────────────────

    public function test_attendance_policy_session_start_requires_permission_and_relationship(): void
    {
        $policy = new AttendanceAuthorizationPolicy($this->authz);

        $teacherUid = $this->createUser('t@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, employee_number) VALUES (?, ?)')->execute([$teacherUid, 'E1']);
        $teacherId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO teacher_class_assignments (teacher_id, class_id, course_id, academic_term_id) VALUES (?, 5, 6, 1)'
        )->execute([$teacherId]);

        $assigned = $this->actorContext($teacherUid, ['TEACHER']);

        $this->assertTrue($policy->authorize($assigned, 'session.start', [
            'class_id' => 5, 'course_id' => 6, 'academic_term_id' => 1,
        ]));

        // Same teacher, class they are NOT assigned to
        $this->assertFalse($policy->authorize($assigned, 'session.start', [
            'class_id' => 5, 'course_id' => 99, 'academic_term_id' => 1,
        ]));

        // A student cannot start a session even with relationship data present
        $studentUid = $this->createUser('s@x.test', ['STUDENT']);
        $student    = $this->actorContext($studentUid, ['STUDENT']);
        $this->assertFalse($policy->authorize($student, 'session.start', [
            'class_id' => 5, 'course_id' => 6, 'academic_term_id' => 1,
        ]));
    }

    public function test_attendance_policy_close_requires_session_ownership(): void
    {
        $policy = new AttendanceAuthorizationPolicy($this->authz);

        $ownerUid = $this->createUser('owner@x.test', ['TEACHER']);
        $otherUid = $this->createUser('other@x.test', ['TEACHER']);
        $owner    = $this->actorContext($ownerUid, ['TEACHER']);
        $other    = $this->actorContext($otherUid, ['TEACHER']);

        $this->assertTrue($policy->authorize($owner, 'session.close', ['teacher_user_id' => $ownerUid]));
        $this->assertFalse($policy->authorize($other, 'session.close', ['teacher_user_id' => $ownerUid]));
    }

    public function test_attendance_policy_record_update_requires_ownership_and_assignment(): void
    {
        $policy = new AttendanceAuthorizationPolicy($this->authz);

        $teacherUid = $this->createUser('t@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, employee_number) VALUES (?, ?)')->execute([$teacherUid, 'E1']);
        $teacherId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO teacher_class_assignments (teacher_id, class_id, course_id, academic_term_id) VALUES (?, 5, 6, 1)'
        )->execute([$teacherId]);

        $actor = $this->actorContext($teacherUid, ['TEACHER']);

        $this->assertTrue($policy->authorize($actor, 'record.update', [
            'teacher_user_id' => $teacherUid, 'class_id' => 5, 'course_id' => 6, 'academic_term_id' => 1,
        ]));

        // Owns the session but not assigned to that class/course
        $this->assertFalse($policy->authorize($actor, 'record.update', [
            'teacher_user_id' => $teacherUid, 'class_id' => 7, 'course_id' => 8, 'academic_term_id' => 1,
        ]));

        // Assigned but does not own the session
        $this->assertFalse($policy->authorize($actor, 'record.update', [
            'teacher_user_id' => 999, 'class_id' => 5, 'course_id' => 6, 'academic_term_id' => 1,
        ]));
    }

    public function test_attendance_policy_qr_submit_requires_student_enrollment(): void
    {
        $policy = new AttendanceAuthorizationPolicy($this->authz);

        $studentUid = $this->createUser('s@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, student_number) VALUES (?, ?)')->execute([$studentUid, 'S1']);
        $studentId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO student_courses (student_id, course_id, class_id, academic_term_id) VALUES (?, 6, 5, 1)'
        )->execute([$studentId]);

        $actor = $this->actorContext($studentUid, ['STUDENT']);

        $this->assertTrue($policy->authorize($actor, 'qr.submit', ['course_id' => 6, 'academic_term_id' => 1]));
        $this->assertFalse($policy->authorize($actor, 'qr.submit', ['course_id' => 999, 'academic_term_id' => 1]));
    }

    public function test_attendance_policy_super_admin_override(): void
    {
        $policy = new AttendanceAuthorizationPolicy($this->authz);
        $root   = $this->actorContext(1, ['SUPER_ADMIN']);

        $this->assertTrue($policy->authorize($root, 'session.close', ['teacher_user_id' => 555]));
        $this->assertTrue($policy->authorize($root, 'record.update', [
            'teacher_user_id' => 555, 'class_id' => 1, 'course_id' => 2, 'academic_term_id' => 1,
        ]));
    }

    public function test_attendance_policy_unknown_ability_denied(): void
    {
        $policy = new AttendanceAuthorizationPolicy($this->authz);
        $root   = $this->actorContext(1, ['SUPER_ADMIN']);

        $this->assertFalse($policy->authorize($root, 'delete.everything', []));
    }
}
