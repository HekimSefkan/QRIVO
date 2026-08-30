<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Attendance;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Attendance\ManualAttendanceService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Domain\Exception\ValidationException;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Teacher manual attendance — ATTENDANCE_ALGORITHM.md §6 / PROJECT_SPECIFICATION.md §6.9.
 */
final class ManualAttendanceServiceTest extends TestCase
{
    use AcademicSchemaTrait;

    private Connection $db;
    /** @var array<string, int> */
    private array $ids;
    /** @var array{id:int, uuid:string, secret:string} */
    private array $session;

    protected function setUp(): void
    {
        $this->pdo     = $this->buildAcademicDb();
        $this->db      = $this->buildConnection();
        $this->ids     = $this->seedSchedulingFixtures();
        $this->session = $this->insertSession('ACTIVE');
        $this->enrolFixtureStudent();
        $this->waitingRecord($this->session['id']); // fixture student -> WAITING/SYSTEM
    }

    private function service(): ManualAttendanceService
    {
        return new ManualAttendanceService(
            $this->createMock(LoggerInterface::class),
            $this->db,
            new AttendanceSessionRepository($this->db),
            new AttendanceRecordRepository($this->db),
            new RelationshipRepository($this->db),
            $this->securityLogService($this->db),
        );
    }

    private function teacher(): array
    {
        return $this->actor($this->ids['teacherUserId'], ['TEACHER']);
    }

    private function record(): array
    {
        return $this->pdo->query("SELECT * FROM attendance_records WHERE student_id={$this->ids['studentId']} AND attendance_session_id={$this->session['id']}")->fetch();
    }

    private function lastAudit(): array
    {
        return $this->pdo->query("SELECT * FROM audit_logs WHERE event_type='ATTENDANCE_STATUS_CHANGED' ORDER BY id DESC LIMIT 1")->fetch();
    }

    // ─── Happy paths ─────────────────────────────────────────────────────────

    public function test_marks_waiting_student_present(): void
    {
        $out = $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], ['status' => 'PRESENT']);

        $this->assertSame('WAITING', $out['previous_status']);
        $this->assertSame('PRESENT', $out['status']);
        $this->assertSame('MANUAL', $out['source']);

        $rec = $this->record();
        $this->assertSame('PRESENT', $rec['status']);
        $this->assertSame('MANUAL', $rec['source']);
        $this->assertNotNull($rec['marked_at']);
    }

    public function test_teacher_can_override_qr_submitted_status_with_a_reason(): void
    {
        $this->pdo->exec("UPDATE attendance_records SET status='PRESENT', source='QR', marked_at='2026-02-01 09:00:00' WHERE student_id={$this->ids['studentId']}");

        $out = $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], [
            'status' => 'ABSENT', 'reason' => 'Left after scanning; not actually present.',
        ]);

        $this->assertSame('PRESENT', $out['previous_status']);
        $this->assertSame('ABSENT', $out['status']);
        $this->assertSame('Left after scanning; not actually present.', $this->lastAudit()['reason']);
    }

    public function test_teacher_can_resolve_pending_review(): void
    {
        $this->pdo->exec("UPDATE attendance_records SET status='PENDING_REVIEW' WHERE student_id={$this->ids['studentId']}");
        $out = $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], ['status' => 'EXCUSED']);
        $this->assertSame('PENDING_REVIEW', $out['previous_status']);
        $this->assertSame('EXCUSED', $out['status']);
    }

    public function test_all_teacher_assignable_states_are_accepted(): void
    {
        foreach (['ABSENT', 'LATE', 'EXCUSED', 'PRESENT', 'WAITING'] as $target) {
            // reset to a different state first
            $this->pdo->exec("UPDATE attendance_records SET status='" . ($target === 'WAITING' ? 'PRESENT' : 'WAITING') . "' WHERE student_id={$this->ids['studentId']}");
            $out = $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], ['status' => $target]);
            $this->assertSame($target, $out['status']);
        }
    }

    // ─── Audit: actor / target / prev / new / timestamp / reason / ip ────────

    public function test_every_modification_is_audited_with_all_required_fields(): void
    {
        $actor = $this->teacher();
        $this->service()->updateStudentStatus($actor, $this->session['id'], $this->ids['studentId'], [
            'status' => 'LATE', 'reason' => 'Arrived 20 min late',
        ], new \DateTimeImmutable('2026-03-02 09:20:00'));

        $a = $this->lastAudit();
        $this->assertSame('ATTENDANCE_STATUS_CHANGED', $a['event_type']);
        $this->assertSame((string) $this->ids['teacherUserId'], (string) $a['actor_user_id']);      // actor
        $this->assertSame('attendance_record', $a['target_entity']);                                 // target
        $this->assertNotNull($a['target_id']);
        $this->assertSame('Arrived 20 min late', $a['reason']);                                       // reason
        $this->assertSame('203.0.113.9', $a['ip_address']);                                           // ip
        $this->assertNotNull($a['created_at']);                                                       // timestamp

        $old = json_decode($a['old_value'], true);
        $new = json_decode($a['new_value'], true);
        $this->assertSame('WAITING', $old['status']);                                                 // previous state
        $this->assertSame('LATE', $new['status']);                                                    // new state
        $this->assertSame('MANUAL', $new['source']);
        $this->assertSame($this->ids['studentId'], $new['student_id']);
        $this->assertSame($this->ids['teacherId'], $new['teacher_id']);
        $this->assertSame($this->session['id'], $new['attendance_session_id']);
        $this->assertSame('WAITING', $new['old_status']);
        $this->assertSame('LATE', $new['new_status']);
    }

    public function test_reason_is_null_in_audit_when_not_provided(): void
    {
        $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], ['status' => 'ABSENT']);
        $this->assertNull($this->lastAudit()['reason']);
    }

    public function test_update_and_audit_are_atomic(): void
    {
        $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], ['status' => 'PRESENT']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) c FROM audit_logs WHERE event_type='ATTENDANCE_STATUS_CHANGED'")->fetch()['c']);
        $this->assertSame('PRESENT', $this->record()['status']);
    }

    // ─── Students can NEVER modify their own attendance ──────────────────────

    public function test_student_actor_is_forbidden(): void
    {
        $studentActor = $this->actor($this->ids['studentUserId'], ['STUDENT']);
        try {
            $this->service()->updateStudentStatus($studentActor, $this->session['id'], $this->ids['studentId'], ['status' => 'PRESENT']);
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
        }
        // unchanged, and no audit
        $this->assertSame('WAITING', $this->record()['status']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) c FROM audit_logs WHERE event_type='ATTENDANCE_STATUS_CHANGED'")->fetch()['c']);
    }

    public function test_teacher_cannot_modify_their_own_attendance(): void
    {
        // The fixture teacher's user is also enrolled as a student in the same class.
        $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$this->ids['teacherUserId'], $this->ids['programId'], 'DUAL-1', 2025, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $selfStudentId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO student_class_assignments (student_id, class_id, academic_term_id, enrolled_at) VALUES (?,?,?,?)')
            ->execute([$selfStudentId, $this->ids['classId'], $this->ids['termId'], '2026-01-01 00:00:00']);

        try {
            $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $selfStudentId, ['status' => 'PRESENT']);
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
        }
        $this->assertGreaterThanOrEqual(1, $this->securityEventCount(SecurityEventType::UNAUTHORIZED_ATTENDANCE->value));
    }

    // ─── Authorization: ownership ──────────────────────────────────────────

    public function test_non_owner_teacher_is_forbidden_and_logged(): void
    {
        $u = $this->makeUser('other-teacher@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$u, $this->ids['departmentId'], 'E-OT', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        try {
            $this->service()->updateStudentStatus($this->actor($u, ['TEACHER']), $this->session['id'], $this->ids['studentId'], ['status' => 'PRESENT']);
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
        }
        $this->assertSame(1, $this->securityEventCount(SecurityEventType::IDOR_ATTEMPT->value));
        $this->assertSame('WAITING', $this->record()['status']);
    }

    public function test_teacher_without_profile_is_forbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->service()->updateStudentStatus($this->actor($this->makeUser('ghost@x.test', ['TEACHER']), ['TEACHER']), $this->session['id'], $this->ids['studentId'], ['status' => 'PRESENT']);
    }

    // ─── Membership / lookups ─────────────────────────────────────────────

    public function test_missing_session_is_404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->updateStudentStatus($this->teacher(), 999999, $this->ids['studentId'], ['status' => 'PRESENT']);
    }

    public function test_student_not_in_session_is_404_and_logged(): void
    {
        $stranger = $this->makeUser('stranger@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$stranger, $this->ids['programId'], 'OUT-1', 2025, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $strangerStudentId = (int) $this->pdo->lastInsertId();

        try {
            $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $strangerStudentId, ['status' => 'PRESENT']);
            $this->fail('expected NotFoundException');
        } catch (NotFoundException) {
        }
        $this->assertSame(1, $this->securityEventCount(SecurityEventType::UNAUTHORIZED_ATTENDANCE->value));
    }

    // ─── Status / transition validation ──────────────────────────────────

    public function test_missing_status_is_422(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], []);
    }

    public function test_unknown_status_is_422(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], ['status' => 'BOGUS']);
    }

    public function test_pending_review_is_not_teacher_assignable_422(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], ['status' => 'PENDING_REVIEW']);
    }

    public function test_no_op_change_is_422(): void
    {
        try {
            $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], ['status' => 'WAITING']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->getErrors());
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) c FROM audit_logs')->fetch()['c']);
    }

    public function test_cancelled_session_rejected_409(): void
    {
        $this->pdo->exec("UPDATE attendance_sessions SET status='CANCELLED' WHERE id={$this->session['id']}");
        $this->expectException(ConflictException::class);
        $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], ['status' => 'PRESENT']);
    }

    public function test_closed_session_still_allows_manual_resolution(): void
    {
        $this->pdo->exec("UPDATE attendance_sessions SET status='CLOSED' WHERE id={$this->session['id']}");
        $out = $this->service()->updateStudentStatus($this->teacher(), $this->session['id'], $this->ids['studentId'], ['status' => 'ABSENT']);
        $this->assertSame('ABSENT', $out['status']);
    }
}
