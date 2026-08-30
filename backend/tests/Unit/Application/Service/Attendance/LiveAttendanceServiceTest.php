<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Attendance;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Attendance\LiveAttendanceService;
use QRIVO\Application\Service\Attendance\QrService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrNonceRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Teacher live attendance (ATTENDANCE_ALGORITHM.md §8, PROJECT_SPECIFICATION.md §6.8).
 */
final class LiveAttendanceServiceTest extends TestCase
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
    }

    private function service(): LiveAttendanceService
    {
        $log = $this->securityLogService($this->db);

        return new LiveAttendanceService(
            $this->createMock(LoggerInterface::class),
            new AttendanceSessionRepository($this->db),
            new AttendanceRecordRepository($this->db),
            new RelationshipRepository($this->db),
            new QrService(
                $this->createMock(LoggerInterface::class),
                new AttendanceSessionRepository($this->db),
                new QrNonceRepository($this->db),
                new RelationshipRepository($this->db),
                $log,
                new Config(QRIVO_ROOT),
            ),
            $log,
        );
    }

    private function teacher(): array
    {
        return $this->actor($this->ids['teacherUserId'], ['TEACHER']);
    }

    private function seedRoster(): void
    {
        $this->rosterStudent($this->session['id'], 'Ada', 'Adams', 'S-001', 'PRESENT', 'QR');
        $this->rosterStudent($this->session['id'], 'Ben', 'Baker', 'S-002', 'WAITING');
        $this->rosterStudent($this->session['id'], 'Cara', 'Clark', 'S-003', 'ABSENT', 'MANUAL');
        $this->rosterStudent($this->session['id'], 'Dan', 'Davis', 'S-004', 'LATE', 'MANUAL');
        $this->rosterStudent($this->session['id'], 'Eve', 'Evans', 'S-005', 'EXCUSED', 'MANUAL');
        $this->rosterStudent($this->session['id'], 'Fay', 'Frost', 'S-006', 'PENDING_REVIEW', 'SYSTEM');
    }

    // ─── Snapshot ────────────────────────────────────────────────────────────

    public function test_snapshot_returns_session_qr_counters_and_students(): void
    {
        $this->seedRoster();
        $snap = $this->service()->snapshot($this->teacher(), $this->session['id']);

        $this->assertSame($this->session['uuid'], $snap['session']['uuid']);
        $this->assertSame($this->ids['roomId'], $snap['session']['room_id']);
        $this->assertArrayHasKey('qr', $snap);
        $this->assertMatchesRegularExpression('/^qrivo\.v1\./', $snap['qr']['qr_string']);

        // Live counters — every specified state + TOTAL (ATTENDANCE_ALGORITHM.md §8)
        $this->assertSame(6, $snap['counters']['TOTAL']);
        $this->assertSame(1, $snap['counters']['PRESENT']);
        $this->assertSame(1, $snap['counters']['WAITING']);
        $this->assertSame(1, $snap['counters']['ABSENT']);
        $this->assertSame(1, $snap['counters']['LATE']);
        $this->assertSame(1, $snap['counters']['EXCUSED']);
        $this->assertSame(1, $snap['counters']['PENDING_REVIEW']);

        $this->assertCount(6, $snap['students']);
        $first = $snap['students'][0];
        $this->assertSame(['student_id', 'student_number', 'first_name', 'last_name', 'status', 'source', 'marked_at'], array_keys($first));
        $this->assertSame('Adams', $first['last_name']); // ordered by last name
    }

    public function test_snapshot_never_exposes_session_secret(): void
    {
        $this->seedRoster();
        $snap = $this->service()->snapshot($this->teacher(), $this->session['id']);

        $this->assertStringNotContainsString('session_secret', (string) json_encode($snap));
        $this->assertStringNotContainsString($this->session['secret'], (string) json_encode($snap));
    }

    public function test_no_qr_block_when_session_not_active(): void
    {
        $closed = $this->insertSession('CLOSED');
        $snap   = $this->service()->snapshot($this->teacher(), $closed['id']);

        $this->assertArrayNotHasKey('qr', $snap);
        $this->assertSame('CLOSED', $snap['session']['status']);
    }

    // ─── Authorization on EVERY call ─────────────────────────────────────────

    public function test_other_teacher_is_forbidden_and_logged_on_every_endpoint(): void
    {
        $this->seedRoster();
        $otherUser = $this->makeUser('other-teacher@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$otherUser, $this->ids['departmentId'], 'E-OT', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $stranger = $this->actor($otherUser, ['TEACHER']);

        foreach (['snapshot', 'counters', 'students'] as $method) {
            try {
                $this->service()->{$method}($stranger, $this->session['id']);
                $this->fail("expected ForbiddenException from {$method}");
            } catch (ForbiddenException) {
            }
        }

        $this->assertSame(3, $this->securityEventCount(SecurityEventType::IDOR_ATTEMPT->value));
    }

    public function test_teacher_without_profile_is_forbidden(): void
    {
        $noProfile = $this->makeUser('ghost@x.test', ['TEACHER']);
        $this->expectException(ForbiddenException::class);
        $this->service()->counters($this->actor($noProfile, ['TEACHER']), $this->session['id']);
    }

    public function test_missing_session_is_404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->snapshot($this->teacher(), 999999);
    }

    // ─── Filters — only authorized info ─────────────────────────────────────

    public function test_search_filter(): void
    {
        $this->seedRoster();
        $r = $this->service()->students($this->teacher(), $this->session['id'], ['search' => 'Baker']);
        $this->assertCount(1, $r['students']);
        $this->assertSame('S-002', $r['students'][0]['student_number']);

        $byNumber = $this->service()->students($this->teacher(), $this->session['id'], ['search' => 'S-003']);
        $this->assertCount(1, $byNumber['students']);
    }

    public function test_status_filter(): void
    {
        $this->seedRoster();
        $present = $this->service()->students($this->teacher(), $this->session['id'], ['status' => 'present']);
        $this->assertCount(1, $present['students']);
        $this->assertSame('PRESENT', $present['students'][0]['status']);

        // unknown status is ignored, not an error
        $all = $this->service()->students($this->teacher(), $this->session['id'], ['status' => 'BOGUS']);
        $this->assertCount(6, $all['students']);
    }

    public function test_only_this_sessions_students_are_returned(): void
    {
        $this->seedRoster();
        $other = $this->insertSession('ACTIVE');
        $this->rosterStudent($other['id'], 'Zoe', 'Zephyr', 'S-999', 'PRESENT', 'QR');

        $r = $this->service()->students($this->teacher(), $this->session['id']);
        $numbers = array_column($r['students'], 'student_number');
        $this->assertNotContains('S-999', $numbers);
        $this->assertCount(6, $r['students']);
    }

    // ─── Polling / delta signal ────────────────────────────────────────────

    public function test_counters_endpoint_is_lightweight_and_carries_a_version(): void
    {
        $this->seedRoster();
        $c = $this->service()->counters($this->teacher(), $this->session['id']);

        $this->assertArrayHasKey('counters', $c);
        $this->assertArrayHasKey('students_version', $c);
        $this->assertArrayHasKey('remaining_seconds', $c);
        $this->assertArrayNotHasKey('students', $c);
        $this->assertArrayNotHasKey('qr', $c);
    }

    public function test_version_changes_when_a_record_transitions(): void
    {
        $sid = $this->rosterStudent($this->session['id'], 'Ivy', 'Ives', 'S-010', 'WAITING');
        $before = $this->service()->counters($this->teacher(), $this->session['id'])['students_version'];

        $this->pdo->prepare("UPDATE attendance_records SET status='PRESENT', updated_at='2026-02-02 09:00:00' WHERE student_id=?")->execute([$sid]);

        $after = $this->service()->counters($this->teacher(), $this->session['id']);
        $this->assertNotSame($before, $after['students_version']);
        $this->assertSame(1, $after['counters']['PRESENT']);
        $this->assertSame(0, $after['counters']['WAITING']);
    }

    // ─── End-to-end: challenge-response updates the live view ───────────────

    public function test_live_view_reflects_a_qr_attendance(): void
    {
        $this->enrolFixtureStudent();
        $this->waitingRecord($this->session['id']);
        $at = new \DateTimeImmutable('now');

        $before = $this->service()->counters($this->teacher(), $this->session['id'], $at);
        $this->assertSame(1, $before['counters']['WAITING']);
        $this->assertSame(0, $before['counters']['PRESENT']);

        // run the real challenge-response flow
        $challengeSvc = $this->makeChallengeService();
        $qr = $this->qrStringFor($this->session, $at);
        $c  = $challengeSvc->requestChallenge($this->actor($this->ids['studentUserId'], ['STUDENT']), ['qr' => $qr], $at);
        $challengeSvc->verify($this->actor($this->ids['studentUserId'], ['STUDENT']), [
            'challenge_id' => $c['challenge_id'], 'nonce' => $c['nonce'], 'qr' => $qr,
        ], $at->modify('+5 seconds'));

        $after = $this->service()->counters($this->teacher(), $this->session['id'], $at->modify('+6 seconds'));
        $this->assertSame(0, $after['counters']['WAITING']);
        $this->assertSame(1, $after['counters']['PRESENT']);
        $this->assertNotSame($before['students_version'], $after['students_version']);

        $roster = $this->service()->students($this->teacher(), $this->session['id'])['students'];
        $this->assertSame('PRESENT', $roster[0]['status']);
        $this->assertSame('QR', $roster[0]['source']);
    }

    private function makeChallengeService(): \QRIVO\Application\Service\Attendance\ChallengeService
    {
        $log  = $this->securityLogService($this->db);
        $chRepo = new \QRIVO\Infrastructure\Repository\Attendance\QrChallengeRepository($this->db);
        $qr   = new QrService(
            $this->createMock(LoggerInterface::class),
            new AttendanceSessionRepository($this->db),
            new QrNonceRepository($this->db),
            new RelationshipRepository($this->db),
            $log,
            new Config(QRIVO_ROOT),
        );

        return new \QRIVO\Application\Service\Attendance\ChallengeService(
            $this->createMock(LoggerInterface::class),
            $this->db,
            $qr,
            new \QRIVO\Application\Service\Attendance\RiskEvaluationService($this->createMock(LoggerInterface::class), $chRepo, new Config(QRIVO_ROOT)),
            new AttendanceSessionRepository($this->db),
            $chRepo,
            new AttendanceRecordRepository($this->db),
            new \QRIVO\Infrastructure\Repository\Attendance\RiskAssessmentRepository($this->db),
            new RelationshipRepository($this->db),
            $log,
            new \QRIVO\Application\Service\Security\DeviceSessionService(
                $this->createMock(LoggerInterface::class),
                new \QRIVO\Infrastructure\Repository\DeviceSessionRepository($this->db),
                $log,
                new Config(QRIVO_ROOT),
            ),
            new Config(QRIVO_ROOT),
        );
    }
}
