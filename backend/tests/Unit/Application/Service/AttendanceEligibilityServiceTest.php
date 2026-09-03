<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\AttendanceEligibilityService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\AttendanceEligibilityReason;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Infrastructure\Repository\ScheduleRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * The Phase 9 keystone: can a teacher open attendance for a course/class/time?
 */
final class AttendanceEligibilityServiceTest extends TestCase
{
    use AcademicSchemaTrait;

    /** @var array<string, int> */
    private array $ids;

    protected function setUp(): void
    {
        $this->pdo = $this->buildAcademicDb();
        $this->ids = $this->seedSchedulingFixtures();
    }

    private function service(): AttendanceEligibilityService
    {
        $db = $this->buildConnection();
        return new AttendanceEligibilityService(
            $this->createMock(LoggerInterface::class),
            new ScheduleRepository($db),
            $this->securityLogService($db),
        );
    }

    private function teacherActor(): array
    {
        return $this->actor($this->ids['teacherUserId'], ['TEACHER']);
    }

    /** Wire class_course + teacher_course + tca + one Monday 09:00-11:00 schedule. */
    private function fullyScheduled(): void
    {
        $now = '2026-01-01 00:00:00';
        $this->pdo->prepare('INSERT INTO class_courses (class_id, course_id, academic_term_id, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$this->ids['classId'], $this->ids['courseId'], $this->ids['termId'], $now]);
        $this->pdo->prepare('INSERT INTO teacher_courses (teacher_id, course_id, academic_term_id, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$this->ids['teacherId'], $this->ids['courseId'], $this->ids['termId'], $now]);
        $this->pdo->prepare('INSERT INTO teacher_class_assignments (teacher_id, class_id, course_id, academic_term_id, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->ids['teacherId'], $this->ids['classId'], $this->ids['courseId'], $this->ids['termId'], $now]);
        $tcaId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO course_schedules (teacher_class_assignment_id, room_id, day_of_week, start_time, end_time, created_at, updated_at) VALUES (?, ?, 0, ?, ?, ?, ?)')
            ->execute([$tcaId, $this->ids['roomId'], '09:00:00', '11:00:00', $now, $now]);
    }

    private function assignmentOnly(): void
    {
        $now = '2026-01-01 00:00:00';
        $this->pdo->prepare('INSERT INTO class_courses (class_id, course_id, academic_term_id, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$this->ids['classId'], $this->ids['courseId'], $this->ids['termId'], $now]);
        $this->pdo->prepare('INSERT INTO teacher_courses (teacher_id, course_id, academic_term_id, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$this->ids['teacherId'], $this->ids['courseId'], $this->ids['termId'], $now]);
        $this->pdo->prepare('INSERT INTO teacher_class_assignments (teacher_id, class_id, course_id, academic_term_id, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->ids['teacherId'], $this->ids['classId'], $this->ids['courseId'], $this->ids['termId'], $now]);
    }

    // A Monday 10:00 and a Tuesday 10:00 for the tests.
    private function monday10(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-02 10:00:00'); // 2026-03-02 is a Monday
    }
    private function tuesday10(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-03 10:00:00');
    }

    public function test_non_teacher_is_denied(): void
    {
        $result = $this->service()->forTeacher($this->actor(1, ['ADMIN']), $this->ids['classId'], $this->ids['courseId'], null, $this->monday10());
        $this->assertFalse($result->isAuthorized());
        $this->assertSame(AttendanceEligibilityReason::NOT_A_TEACHER, $result->reason);
    }

    public function test_teacher_not_assigned_is_denied_and_logged(): void
    {
        $this->assignmentOnly(); // create an assignment for THIS teacher…
        // …but ask about a different course the teacher is not assigned to.
        $result = $this->service()->forTeacher($this->teacherActor(), $this->ids['classId'], 999, $this->ids['termId'], $this->monday10());

        $this->assertFalse($result->isAuthorized());
        $this->assertSame(AttendanceEligibilityReason::NOT_ASSIGNED_TO_CLASS_COURSE, $result->reason);
        $this->assertSame(1, $this->securityEventCount(SecurityEventType::UNAUTHORIZED_ACCESS->value));
    }

    public function test_assignment_only_check_authorizes_without_time(): void
    {
        $this->assignmentOnly();
        $result = $this->service()->forTeacher($this->teacherActor(), $this->ids['classId'], $this->ids['courseId'], $this->ids['termId'], null);

        $this->assertTrue($result->isAuthorized());
        $this->assertNotNull($result->teacherClassAssignmentId);
        $this->assertSame($this->ids['termId'], $result->academicTermId);
        $this->assertNull($result->roomId);
    }

    public function test_authorized_when_assigned_and_within_scheduled_time(): void
    {
        $this->fullyScheduled();
        $result = $this->service()->forTeacher($this->teacherActor(), $this->ids['classId'], $this->ids['courseId'], null, $this->monday10());

        $this->assertTrue($result->isAuthorized());
        $this->assertSame($this->ids['roomId'], $result->roomId);
        $this->assertSame('Monday', $result->schedule['day']);
    }

    public function test_denied_when_not_scheduled_on_that_day(): void
    {
        $this->fullyScheduled(); // schedule is Monday only
        $result = $this->service()->forTeacher($this->teacherActor(), $this->ids['classId'], $this->ids['courseId'], null, $this->tuesday10());

        $this->assertFalse($result->isAuthorized());
        $this->assertSame(AttendanceEligibilityReason::NOT_SCHEDULED_ON_DAY, $result->reason);
    }

    public function test_denied_when_outside_scheduled_time(): void
    {
        $this->fullyScheduled(); // Monday 09:00-11:00
        $late   = new \DateTimeImmutable('2026-03-02 13:00:00');
        $result = $this->service()->forTeacher($this->teacherActor(), $this->ids['classId'], $this->ids['courseId'], null, $late);

        $this->assertFalse($result->isAuthorized());
        $this->assertSame(AttendanceEligibilityReason::OUTSIDE_SCHEDULED_TIME, $result->reason);
    }

    public function test_active_term_resolution_when_term_not_supplied(): void
    {
        $this->fullyScheduled();
        // termId omitted → resolves via academic_terms.is_active = 1 (seedHierarchy sets it)
        $result = $this->service()->forTeacher($this->teacherActor(), $this->ids['classId'], $this->ids['courseId'], null, $this->monday10());
        $this->assertTrue($result->isAuthorized());

        // Deactivate the term → no active term → NO_ACTIVE_TERM
        $this->pdo->exec('UPDATE academic_terms SET is_active = 0');
        $result2 = $this->service()->forTeacher($this->teacherActor(), $this->ids['classId'], $this->ids['courseId'], null, $this->monday10());
        $this->assertFalse($result2->isAuthorized());
        $this->assertSame(AttendanceEligibilityReason::NO_ACTIVE_TERM, $result2->reason);
    }

    // ─── Schedule-window boundaries (Phase 30) ──────────────────────────────
    //
    // The window is INCLUSIVE at both ends: ScheduleRepository::findCoveringSchedule
    // matches on `start_time <= t AND end_time >= t`. These four tests pin that
    // contract to the second, so a future change to the comparison (or to the
    // application timezone) cannot silently move the boundary.
    //
    // The fixture schedule is Monday 09:00:00-11:00:00 (see fullyScheduled()).

    public function test_denied_one_second_before_the_lesson_starts(): void
    {
        $this->fullyScheduled();
        $result = $this->service()->forTeacher(
            $this->teacherActor(),
            $this->ids['classId'],
            $this->ids['courseId'],
            $this->ids['termId'],
            new \DateTimeImmutable('2026-01-05 08:59:59'), // Monday
        );

        $this->assertFalse($result->isAuthorized());
        $this->assertSame(AttendanceEligibilityReason::OUTSIDE_SCHEDULED_TIME, $result->reason);
    }

    public function test_authorized_exactly_at_the_start_second(): void
    {
        $this->fullyScheduled();
        $result = $this->service()->forTeacher(
            $this->teacherActor(),
            $this->ids['classId'],
            $this->ids['courseId'],
            $this->ids['termId'],
            new \DateTimeImmutable('2026-01-05 09:00:00'),
        );

        $this->assertTrue($result->isAuthorized(), 'the start second is inside the window');
    }

    public function test_authorized_exactly_at_the_end_second(): void
    {
        $this->fullyScheduled();
        $result = $this->service()->forTeacher(
            $this->teacherActor(),
            $this->ids['classId'],
            $this->ids['courseId'],
            $this->ids['termId'],
            new \DateTimeImmutable('2026-01-05 11:00:00'),
        );

        $this->assertTrue($result->isAuthorized(), 'the end second is inside the window');
    }

    public function test_denied_one_second_after_the_lesson_ends(): void
    {
        $this->fullyScheduled();
        $result = $this->service()->forTeacher(
            $this->teacherActor(),
            $this->ids['classId'],
            $this->ids['courseId'],
            $this->ids['termId'],
            new \DateTimeImmutable('2026-01-05 11:00:01'),
        );

        $this->assertFalse($result->isAuthorized());
        $this->assertSame(AttendanceEligibilityReason::OUTSIDE_SCHEDULED_TIME, $result->reason);
    }

    /**
     * The timezone that "now" is constructed in is LOAD-BEARING.
     *
     * course_schedules stores wall-clock time, and the service compares it with
     * $at->format('H:i:s') -- which reads $at in ITS OWN zone. So the same
     * instant produces different verdicts depending on the zone it was built in.
     *
     * That is precisely the Phase 30 defect: config/app.php read APP_TIMEZONE but
     * nothing ever called date_default_timezone_set(), so `new
     * DateTimeImmutable('now')` at every call site was UTC while the machine and
     * MySQL ran on UTC+3. A 12:30 lesson was evaluated as 09:30 and refused.
     *
     * This test pins the consequence rather than restating format()'s contract:
     * one instant, two zones, two different answers. If it ever stops holding,
     * the comparison has changed and the bootstrap guarantee below matters less.
     */
    public function test_the_timezone_of_now_changes_the_verdict_for_the_same_instant(): void
    {
        $this->fullyScheduled();

        // One instant: 09:30 Istanbul == 06:30 UTC.
        $asIstanbul = new DateTimeImmutable('2026-01-05 09:30:00', new DateTimeZone('Europe/Istanbul'));
        $sameInstantAsUtc = $asIstanbul->setTimezone(new DateTimeZone('UTC'));

        self::assertSame(
            $asIstanbul->getTimestamp(),
            $sameInstantAsUtc->getTimestamp(),
            'precondition: these must be the same moment in time',
        );

        $inZone = $this->service()->forTeacher(
            $this->teacherActor(), $this->ids['classId'], $this->ids['courseId'], $this->ids['termId'], $asIstanbul,
        );
        $inUtc = $this->service()->forTeacher(
            $this->teacherActor(), $this->ids['classId'], $this->ids['courseId'], $this->ids['termId'], $sameInstantAsUtc,
        );

        self::assertTrue($inZone->isAuthorized(), '09:30 Istanbul is inside the 09:00-11:00 window');
        self::assertFalse($inUtc->isAuthorized(), 'the same instant read as 06:30 UTC falls outside it');
        self::assertSame(AttendanceEligibilityReason::OUTSIDE_SCHEDULED_TIME, $inUtc->reason);
    }

    /**
     * The bootstrap guarantee: the configured timezone is a real, applicable
     * identifier. Without this, the test above is a curiosity; with it, every
     * call site writing `new DateTimeImmutable('now')` lands in the app's zone.
     */
    public function test_configured_timezone_is_a_real_identifier(): void
    {
        $config = new \QRIVO\Infrastructure\Config\Config(QRIVO_ROOT);
        $configured = $config->getString('app.timezone', 'UTC');

        self::assertContains(
            $configured,
            \DateTimeZone::listIdentifiers(),
            'APP_TIMEZONE must be a valid IANA identifier, not an abbreviation or a raw offset',
        );

        // And it must be applicable without PHP emitting a warning.
        self::assertTrue(date_default_timezone_set($configured));
    }
}
