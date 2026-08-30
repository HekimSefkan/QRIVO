<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service;

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
}
