<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Attendance;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Attendance\AttendanceSessionService;
use QRIVO\Application\Service\AttendanceEligibilityService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\ScheduleRepository;
use QRIVO\Infrastructure\Repository\SystemSettingRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * ATTENDANCE_ALGORITHM.md §7 — session close / cancel.
 */
final class SessionCloseServiceTest extends TestCase
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
        $this->wireAssignmentAndSchedule();
        $this->enrolStudents(3);
        $this->session = $this->insertSession('ACTIVE');
        // 1 PRESENT, 2 WAITING
        $students = $this->pdo->query('SELECT id FROM students ORDER BY id')->fetchAll();
        $this->attendanceRecord($this->session['id'], (int) $students[0]['id'], 'PRESENT', 'QR', '2026-03-02 09:02:00');
        $this->attendanceRecord($this->session['id'], (int) $students[1]['id'], 'WAITING', 'SYSTEM');
        $this->attendanceRecord($this->session['id'], (int) $students[2]['id'], 'WAITING', 'SYSTEM');
    }

    private function service(): AttendanceSessionService
    {
        $log = $this->securityLogService($this->db);

        return new AttendanceSessionService(
            $this->createMock(LoggerInterface::class),
            $this->db,
            new AttendanceEligibilityService($this->createMock(LoggerInterface::class), new ScheduleRepository($this->db), $log),
            new AttendanceSessionRepository($this->db),
            new AttendanceRecordRepository($this->db),
            new ScheduleRepository($this->db),
            new RelationshipRepository($this->db),
            $log,
            new SystemSettingRepository($this->db),
        );
    }

    private function teacher(): array
    {
        return $this->actor($this->ids['teacherUserId'], ['TEACHER']);
    }

    private function sessionStatus(): string
    {
        return (string) $this->pdo->query("SELECT status FROM attendance_sessions WHERE id={$this->session['id']}")->fetch()['status'];
    }

    /** @return array<string, int> */
    private function recordStatuses(): array
    {
        $out = [];
        foreach ($this->pdo->query("SELECT status, COUNT(*) c FROM attendance_records WHERE attendance_session_id={$this->session['id']} GROUP BY status")->fetchAll() as $r) {
            $out[$r['status']] = (int) $r['c'];
        }

        return $out;
    }

    private function setSetting(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT INTO system_settings ("key","value",type) VALUES (?,?,?)')->execute([$key, $value, 'string']);
    }

    private function lastAudit(string $type): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_logs WHERE event_type = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$type]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    // ─── close ──────────────────────────────────────────────────────────────

    public function test_close_transitions_active_to_closed(): void
    {
        $this->service()->close($this->teacher(), $this->session['id']);

        $this->assertSame('CLOSED', $this->sessionStatus());
        $this->assertNotNull($this->pdo->query("SELECT end_time FROM attendance_sessions WHERE id={$this->session['id']}")->fetch()['end_time']);
    }

    public function test_close_resolves_waiting_to_absent_by_default(): void
    {
        $this->service()->close($this->teacher(), $this->session['id']);

        $s = $this->recordStatuses();
        $this->assertSame(1, $s['PRESENT']);   // untouched
        $this->assertSame(2, $s['ABSENT']);    // both WAITING resolved
        $this->assertArrayNotHasKey('WAITING', $s);
    }

    public function test_close_honours_system_setting_pending_review(): void
    {
        $this->setSetting('attendance.close.waiting_default_status', 'PENDING_REVIEW');
        $this->service()->close($this->teacher(), $this->session['id']);

        $s = $this->recordStatuses();
        $this->assertSame(2, $s['PENDING_REVIEW']);
        $this->assertSame(1, $s['PRESENT']);
    }

    public function test_close_writes_an_audit_log(): void
    {
        $this->service()->close($this->teacher(), $this->session['id']);

        $a = $this->lastAudit('ATTENDANCE_SESSION_CLOSED');
        $this->assertNotNull($a);
        $this->assertSame((string) $this->ids['teacherUserId'], (string) $a['actor_user_id']);
        $this->assertSame('attendance_session', $a['target_entity']);
        $this->assertStringContainsString('CLOSED', (string) $a['new_value']);
        $this->assertStringContainsString('waiting_resolved_count', (string) $a['new_value']);
    }

    public function test_close_is_idempotent_guarded_second_call_conflicts(): void
    {
        $this->service()->close($this->teacher(), $this->session['id']);

        $this->expectException(ConflictException::class);
        $this->service()->close($this->teacher(), $this->session['id']);
    }

    public function test_cannot_close_a_cancelled_session(): void
    {
        $this->pdo->exec("UPDATE attendance_sessions SET status='CANCELLED' WHERE id={$this->session['id']}");

        $this->expectException(ConflictException::class);
        $this->service()->close($this->teacher(), $this->session['id']);
    }

    public function test_close_by_a_non_owner_is_forbidden_and_logged(): void
    {
        $otherTeacherUser = $this->makeUser('other.teacher@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$otherTeacherUser, $this->ids['departmentId'], 'E-OTHER', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        try {
            $this->service()->close($this->actor($otherTeacherUser, ['TEACHER']), $this->session['id']);
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
        }

        $this->assertSame('ACTIVE', $this->sessionStatus()); // unchanged
        $this->assertSame(1, $this->securityEventCount('IDOR_ATTEMPT'));
    }

    // ─── cancel ─────────────────────────────────────────────────────────────

    public function test_cancel_transitions_active_to_cancelled_without_touching_records(): void
    {
        $this->service()->cancel($this->teacher(), $this->session['id'], ['reason' => 'Fire drill']);

        $this->assertSame('CANCELLED', $this->sessionStatus());
        $s = $this->recordStatuses();
        $this->assertSame(2, $s['WAITING']);   // untouched — a cancelled session carries no meaning
        $this->assertSame(1, $s['PRESENT']);

        $a = $this->lastAudit('ATTENDANCE_SESSION_CANCELLED');
        $this->assertNotNull($a);
        $this->assertSame('Fire drill', $a['reason']);
    }

    public function test_cannot_cancel_a_closed_session(): void
    {
        $this->service()->close($this->teacher(), $this->session['id']);

        $this->expectException(ConflictException::class);
        $this->service()->cancel($this->teacher(), $this->session['id']);
    }

    public function test_cancel_by_a_non_owner_is_forbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->service()->cancel($this->actor($this->ids['studentUserId'], ['STUDENT']), $this->session['id']);
    }
}
