<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Attendance;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Attendance\AttendanceSessionService;
use QRIVO\Application\Service\AttendanceEligibilityService;
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
use QRIVO\Infrastructure\Repository\ScheduleRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * ATTENDANCE_ALGORITHM.md §2 — the 10-step session creation sequence.
 */
final class AttendanceSessionServiceTest extends TestCase
{
    use AcademicSchemaTrait;

    private Connection $db;
    /** @var array<string, int> */
    private array $ids;

    protected function setUp(): void
    {
        $this->pdo = $this->buildAcademicDb();
        $this->db  = $this->buildConnection();
        $this->ids = $this->seedSchedulingFixtures();
    }

    private function service(): AttendanceSessionService
    {
        $securityLog = $this->securityLogService($this->db);

        return new AttendanceSessionService(
            $this->createMock(LoggerInterface::class),
            $this->db,
            new AttendanceEligibilityService($this->createMock(LoggerInterface::class), new ScheduleRepository($this->db), $securityLog),
            new AttendanceSessionRepository($this->db),
            new AttendanceRecordRepository($this->db),
            new ScheduleRepository($this->db),
            new RelationshipRepository($this->db),
            $securityLog,
        );
    }

    private function teacherActor(): array
    {
        return $this->actor($this->ids['teacherUserId'], ['TEACHER']);
    }

    private function body(array $overrides = []): array
    {
        return array_merge(['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId']], $overrides);
    }

    // ─── Happy path ────────────────────────────────────────────────────────────

    public function test_starts_session_and_initialises_all_students_as_waiting(): void
    {
        $this->wireAssignmentAndSchedule();
        $this->enrolStudents(3);

        $result = $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt('10:00:00'));

        $s = $result['session'];
        $this->assertSame('ACTIVE', $s['status']);
        $this->assertSame($this->ids['roomId'], $s['room_id']);
        $this->assertSame($this->ids['termId'], $s['academic_term_id']);
        $this->assertNull($s['end_time'], 'end_time is NULL until close (CONSTRAINTS.md §4)');
        $this->assertSame('2026-03-02 11:00:00', $s['expires_at'], 'expiry = scheduled meeting end');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $s['uuid']);

        $this->assertSame(3, $result['counts']['TOTAL']);
        $this->assertSame(3, $result['counts']['WAITING']);
        $this->assertSame(0, $result['counts']['PRESENT']);

        // records really exist, all WAITING / SYSTEM
        $rows = (new AttendanceRecordRepository($this->db))->forSession($s['id']);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('WAITING', $row['status']);
            $this->assertSame('SYSTEM', $row['source']);
        }
    }

    public function test_session_secret_is_never_returned(): void
    {
        $this->wireAssignmentAndSchedule();
        $this->enrolStudents(1);
        $result = $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt());

        $this->assertArrayNotHasKey('session_secret', $result['session']);
        $this->assertStringNotContainsString('session_secret', (string) json_encode($result));

        // but it WAS stored
        $stored = (new AttendanceSessionRepository($this->db))->findRow($result['session']['id']);
        $this->assertNotEmpty($stored['session_secret']);
    }

    public function test_session_creation_is_audited(): void
    {
        $this->wireAssignmentAndSchedule();
        $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt());

        $c = (int) $this->pdo->query("SELECT COUNT(*) c FROM audit_logs WHERE event_type = 'ATTENDANCE_SESSION_STARTED'")->fetch()['c'];
        $this->assertSame(1, $c);
    }

    // ─── Step 2-4: authorization / relationships ──────────────────────────────

    public function test_non_teacher_cannot_start(): void
    {
        $this->wireAssignmentAndSchedule();
        $this->expectException(ForbiddenException::class);
        $this->service()->start($this->actor(1, ['ADMIN']), $this->body(), $this->mondayAt());
    }

    public function test_teacher_not_assigned_to_class_course_is_forbidden_and_logged(): void
    {
        $this->wireAssignmentAndSchedule(); // assigns to $courseId
        try {
            // ask for a different course
            $this->service()->start($this->teacherActor(), $this->body(['course_id' => 999]), $this->mondayAt());
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
        }

        $this->assertSame(1, $this->securityEventCount(SecurityEventType::UNAUTHORIZED_ATTENDANCE->value));
    }

    // ─── Step 5-7: schedule / date / time ────────────────────────────────────

    public function test_cannot_start_when_not_scheduled_today(): void
    {
        $this->wireAssignmentAndSchedule(dayOfWeek: 0); // Monday only
        $tuesday = new \DateTimeImmutable('2026-03-03 10:00:00');

        $this->expectException(ConflictException::class);
        $this->service()->start($this->teacherActor(), $this->body(), $tuesday);
    }

    public function test_cannot_start_outside_scheduled_time(): void
    {
        $this->wireAssignmentAndSchedule(0, '09:00:00', '11:00:00');
        $this->expectException(ConflictException::class);
        $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt('15:00:00'));
    }

    // ─── Step 8: room ────────────────────────────────────────────────────────

    public function test_supplied_room_must_match_scheduled_room(): void
    {
        $this->wireAssignmentAndSchedule();
        $this->pdo->prepare('INSERT INTO rooms (school_id, name, code, capacity, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$this->ids['schoolId'], 'Wrong', 'W1', 10, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $wrongRoom = (int) $this->pdo->lastInsertId();

        $this->expectException(ForbiddenException::class);
        $this->service()->start($this->teacherActor(), $this->body(['room_id' => $wrongRoom]), $this->mondayAt());
    }

    public function test_supplied_matching_room_is_accepted(): void
    {
        $this->wireAssignmentAndSchedule();
        $result = $this->service()->start($this->teacherActor(), $this->body(['room_id' => $this->ids['roomId']]), $this->mondayAt());
        $this->assertSame('ACTIVE', $result['session']['status']);
    }

    // ─── Step 9: academic term ───────────────────────────────────────────────

    public function test_cannot_start_when_term_inactive(): void
    {
        $this->wireAssignmentAndSchedule();
        $this->pdo->exec('UPDATE academic_terms SET is_active = 0');

        // active-term resolution now finds nothing → NO_ACTIVE_TERM → 409
        $this->expectException(ConflictException::class);
        $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt());
    }

    // ─── Step 10: duplicate active session ───────────────────────────────────

    public function test_duplicate_active_session_for_class_course_is_rejected(): void
    {
        $this->wireAssignmentAndSchedule();
        $this->enrolStudents(2);
        $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt('09:30:00'));

        try {
            $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt('10:00:00'));
            $this->fail('expected ConflictException');
        } catch (ConflictException) {
        }

        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) c FROM attendance_sessions WHERE status='ACTIVE'")->fetch()['c']);
        // students initialised exactly once
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) c FROM attendance_records')->fetch()['c']);
    }

    public function test_can_start_again_after_previous_session_no_longer_active(): void
    {
        $this->wireAssignmentAndSchedule();
        $first = $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt('09:30:00'));
        $this->pdo->exec("UPDATE attendance_sessions SET status='CLOSED' WHERE id=" . $first['session']['id']);

        $second = $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt('10:00:00'));
        $this->assertNotSame($first['session']['id'], $second['session']['id']);
    }

    // ─── Input validation ────────────────────────────────────────────────────

    public function test_missing_required_fields_is_rejected(): void
    {
        $this->wireAssignmentAndSchedule();
        $this->expectException(ValidationException::class);
        $this->service()->start($this->teacherActor(), ['class_id' => $this->ids['classId']], $this->mondayAt());
    }

    // ─── viewOwned ───────────────────────────────────────────────────────────

    public function test_view_owned_session(): void
    {
        $this->wireAssignmentAndSchedule();
        $created = $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt());

        $view = $this->service()->viewOwned($this->teacherActor(), $created['session']['id']);
        $this->assertSame($created['session']['uuid'], $view['session']['uuid']);
    }

    public function test_view_other_teachers_session_is_forbidden_and_logged(): void
    {
        $this->wireAssignmentAndSchedule();
        $created = $this->service()->start($this->teacherActor(), $this->body(), $this->mondayAt());

        $otherUser = $this->makeUser('other-teacher@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$otherUser, $this->ids['departmentId'], 'E-9', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        try {
            $this->service()->viewOwned($this->actor($otherUser, ['TEACHER']), $created['session']['id']);
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
        }
        $this->assertSame(1, $this->securityEventCount(SecurityEventType::IDOR_ATTEMPT->value));
    }

    public function test_view_missing_session_is_404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->viewOwned($this->teacherActor(), 424242);
    }
}
