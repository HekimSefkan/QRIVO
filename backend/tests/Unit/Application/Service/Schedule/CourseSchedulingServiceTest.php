<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Schedule;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Schedule\ClassCourseService;
use QRIVO\Application\Service\Schedule\CourseScheduleService;
use QRIVO\Application\Service\Schedule\StudentClassAssignmentService;
use QRIVO\Application\Service\Schedule\StudentCourseService;
use QRIVO\Application\Service\Schedule\TeacherClassAssignmentService;
use QRIVO\Application\Service\Schedule\TeacherCourseService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\ValidationException;
use QRIVO\Infrastructure\Repository\ReferenceRepository;
use QRIVO\Infrastructure\Repository\ScheduleRepository;
use QRIVO\Infrastructure\Repository\Schedule\ClassCourseRepository;
use QRIVO\Infrastructure\Repository\Schedule\CourseScheduleRepository;
use QRIVO\Infrastructure\Repository\Schedule\StudentClassAssignmentRepository;
use QRIVO\Infrastructure\Repository\Schedule\StudentCourseRepository;
use QRIVO\Infrastructure\Repository\Schedule\TeacherClassAssignmentRepository;
use QRIVO\Infrastructure\Repository\Schedule\TeacherCourseRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

final class CourseSchedulingServiceTest extends TestCase
{
    use AcademicSchemaTrait;

    private \QRIVO\Infrastructure\Database\Connection $db;
    /** @var array<string, int> */
    private array $ids;

    protected function setUp(): void
    {
        $this->pdo = $this->buildAcademicDb();
        $this->db  = $this->buildConnection();
        $this->ids = $this->seedSchedulingFixtures();
    }

    private function logger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    private function classCourses(): ClassCourseService
    {
        return new ClassCourseService($this->logger(), new ClassCourseRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db), new ScheduleRepository($this->db));
    }
    private function teacherCourses(): TeacherCourseService
    {
        return new TeacherCourseService($this->logger(), new TeacherCourseRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function tca(): TeacherClassAssignmentService
    {
        return new TeacherClassAssignmentService($this->logger(), new TeacherClassAssignmentRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db), new ScheduleRepository($this->db));
    }
    private function sca(): StudentClassAssignmentService
    {
        return new StudentClassAssignmentService($this->logger(), new StudentClassAssignmentRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db), new ScheduleRepository($this->db));
    }
    private function studentCourses(): StudentCourseService
    {
        return new StudentCourseService($this->logger(), new StudentCourseRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db));
    }
    private function schedules(): CourseScheduleService
    {
        return new CourseScheduleService($this->logger(), new CourseScheduleRepository($this->db), $this->securityLogService($this->db), new ReferenceRepository($this->db), new ScheduleRepository($this->db));
    }

    private function actorAdmin(): array
    {
        return $this->actor(1, ['ADMIN']);
    }

    /** class_course + teacher_course + tca happy path → returns tca id */
    private function assignTeacher(): int
    {
        $a = $this->actorAdmin();
        $this->classCourses()->create(['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
        $this->teacherCourses()->create(['teacher_id' => $this->ids['teacherId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);

        return $this->tca()->create([
            'teacher_id' => $this->ids['teacherId'], 'class_id' => $this->ids['classId'],
            'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId'],
        ], $a)['id'];
    }

    // ─── class_courses ───────────────────────────────────────────────────────

    public function test_class_course_crud_and_composite_uniqueness(): void
    {
        $a  = $this->actorAdmin();
        $cc = $this->classCourses()->create(['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
        $this->assertSame($this->ids['courseId'], $cc['course_id']);

        $this->expectException(ValidationException::class);
        $this->classCourses()->create(['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
    }

    public function test_class_course_rejects_unknown_refs(): void
    {
        $this->expectException(ValidationException::class);
        $this->classCourses()->create(['class_id' => 999, 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $this->actorAdmin());
    }

    // ─── teacher_class_assignments — the authorization record ────────────────

    public function test_tca_requires_class_course_and_teacher_course_first(): void
    {
        $a = $this->actorAdmin();

        // Neither prerequisite exists yet.
        try {
            $this->tca()->create(['teacher_id' => $this->ids['teacherId'], 'class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('course_id', $e->getErrors());
        }

        // Add class_course only — still missing teacher_course.
        $this->classCourses()->create(['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
        try {
            $this->tca()->create(['teacher_id' => $this->ids['teacherId'], 'class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('teacher_id', $e->getErrors());
        }

        // Add teacher_course — now it works.
        $this->teacherCourses()->create(['teacher_id' => $this->ids['teacherId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
        $created = $this->tca()->create(['teacher_id' => $this->ids['teacherId'], 'class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
        $this->assertGreaterThan(0, $created['id']);
    }

    public function test_tca_composite_uniqueness(): void
    {
        $this->assignTeacher();
        $this->expectException(ValidationException::class);
        $this->tca()->create([
            'teacher_id' => $this->ids['teacherId'], 'class_id' => $this->ids['classId'],
            'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId'],
        ], $this->actorAdmin());
    }

    public function test_tca_cannot_be_deleted_while_scheduled(): void
    {
        $tcaId = $this->assignTeacher();
        $this->schedules()->create([
            'teacher_class_assignment_id' => $tcaId, 'room_id' => $this->ids['roomId'],
            'day_of_week' => 0, 'start_time' => '09:00', 'end_time' => '11:00',
        ], $this->actorAdmin());

        $this->expectException(ConflictException::class);
        $this->tca()->delete($tcaId, $this->actorAdmin());
    }

    // ─── course_schedules — conflicts ───────────────────────────────────────

    public function test_schedule_rejects_end_before_start(): void
    {
        $tcaId = $this->assignTeacher();
        $this->expectException(ValidationException::class);
        $this->schedules()->create([
            'teacher_class_assignment_id' => $tcaId, 'room_id' => $this->ids['roomId'],
            'day_of_week' => 0, 'start_time' => '11:00', 'end_time' => '09:00',
        ], $this->actorAdmin());
    }

    public function test_schedule_room_double_booking_rejected(): void
    {
        $tcaId = $this->assignTeacher();
        $a = $this->actorAdmin();
        $this->schedules()->create([
            'teacher_class_assignment_id' => $tcaId, 'room_id' => $this->ids['roomId'],
            'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00',
        ], $a);

        // Second teacher/assignment, same room, overlapping time.
        $u2 = $this->makeUser('t2@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$u2, $this->ids['departmentId'], 'E-2', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $t2 = (int) $this->pdo->lastInsertId();
        $this->teacherCourses()->create(['teacher_id' => $t2, 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
        $tca2 = $this->tca()->create(['teacher_id' => $t2, 'class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a)['id'];

        $this->expectException(ConflictException::class);
        $this->schedules()->create([
            'teacher_class_assignment_id' => $tca2, 'room_id' => $this->ids['roomId'],
            'day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '12:00',
        ], $a);
    }

    public function test_schedule_teacher_double_booking_rejected(): void
    {
        $tcaId = $this->assignTeacher();
        $a = $this->actorAdmin();
        $this->schedules()->create([
            'teacher_class_assignment_id' => $tcaId, 'room_id' => $this->ids['roomId'],
            'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '11:00',
        ], $a);

        // Same teacher, different class/assignment, different room, overlapping time.
        $this->pdo->prepare('INSERT INTO classes (program_id, academic_term_id, name, grade_level, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$this->ids['programId'], $this->ids['termId'], 'CE-1B', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $class2 = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO rooms (school_id, name, code, capacity, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$this->ids['schoolId'], 'Hall B', 'B1', 50, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $room2 = (int) $this->pdo->lastInsertId();

        $this->classCourses()->create(['class_id' => $class2, 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
        $tca2 = $this->tca()->create(['teacher_id' => $this->ids['teacherId'], 'class_id' => $class2, 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a)['id'];

        $this->expectException(ConflictException::class);
        $this->schedules()->create([
            'teacher_class_assignment_id' => $tca2, 'room_id' => $room2,
            'day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '12:00',
        ], $a);
    }

    public function test_non_overlapping_schedules_are_allowed(): void
    {
        $tcaId = $this->assignTeacher();
        $a = $this->actorAdmin();
        $this->schedules()->create(['teacher_class_assignment_id' => $tcaId, 'room_id' => $this->ids['roomId'], 'day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '11:00'], $a);
        $second = $this->schedules()->create(['teacher_class_assignment_id' => $tcaId, 'room_id' => $this->ids['roomId'], 'day_of_week' => 3, 'start_time' => '11:00', 'end_time' => '13:00'], $a);
        $this->assertGreaterThan(0, $second['id']);
    }

    // ─── student_courses derivation (DD-005) ────────────────────────────────

    public function test_enrolling_student_derives_student_courses(): void
    {
        $a = $this->actorAdmin();
        $this->classCourses()->create(['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);

        $this->assertSame(0, $this->rowCount('student_courses'));
        $this->sca()->create(['student_id' => $this->ids['studentId'], 'class_id' => $this->ids['classId'], 'academic_term_id' => $this->ids['termId']], $a);

        $this->assertSame(1, $this->rowCount('student_courses'));
        $rows = $this->studentCourses()->list(['student_id' => $this->ids['studentId']], $a);
        $this->assertSame($this->ids['courseId'], $rows['data'][0]['course_id']);
    }

    public function test_unenrolling_student_removes_derived_student_courses(): void
    {
        $a = $this->actorAdmin();
        $this->classCourses()->create(['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
        $enr = $this->sca()->create(['student_id' => $this->ids['studentId'], 'class_id' => $this->ids['classId'], 'academic_term_id' => $this->ids['termId']], $a);
        $this->assertSame(1, $this->rowCount('student_courses'));

        $this->sca()->delete($enr['id'], $a);
        $this->assertSame(0, $this->rowCount('student_courses'));
    }

    public function test_adding_class_course_after_enrollment_backfills_student_courses(): void
    {
        $a = $this->actorAdmin();
        $this->sca()->create(['student_id' => $this->ids['studentId'], 'class_id' => $this->ids['classId'], 'academic_term_id' => $this->ids['termId']], $a);
        $this->assertSame(0, $this->rowCount('student_courses'));

        $this->classCourses()->create(['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
        $this->assertSame(1, $this->rowCount('student_courses'));
    }

    public function test_removing_class_course_prunes_student_courses(): void
    {
        $a = $this->actorAdmin();
        $cc = $this->classCourses()->create(['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId']], $a);
        $this->sca()->create(['student_id' => $this->ids['studentId'], 'class_id' => $this->ids['classId'], 'academic_term_id' => $this->ids['termId']], $a);
        $this->assertSame(1, $this->rowCount('student_courses'));

        $this->classCourses()->delete($cc['id'], $a);
        $this->assertSame(0, $this->rowCount('student_courses'));
    }

    public function test_student_courses_is_read_only(): void
    {
        $this->expectException(ConflictException::class);
        $this->studentCourses()->create(['student_id' => $this->ids['studentId'], 'course_id' => $this->ids['courseId']], $this->actorAdmin());
    }
}
