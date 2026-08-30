<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Schedule;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Entity\Schedule\TeacherClassAssignment;
use QRIVO\Infrastructure\Repository\AbstractCrudRepository;
use QRIVO\Infrastructure\Repository\ReferenceRepository;
use QRIVO\Infrastructure\Repository\ScheduleRepository;

/**
 * Manages `teacher_class_assignments` — the record that a teacher teaches a
 * specific course to a specific class in a term. This is the authorization basis
 * for attendance session creation (ATTENDANCE_ALGORITHM.md §2 steps 3-4).
 *
 * For the assignment to be coherent (spec §6.4 — "which teacher teaches which
 * course, to which class ... during which academic term") it requires:
 *   - the course is offered to the class that term (`class_courses`)
 *   - the teacher is responsible for the course that term (`teacher_courses`)
 */
final class TeacherClassAssignmentService extends AbstractAcademicService
{
    public function __construct(
        LoggerInterface $logger,
        AbstractCrudRepository $repo,
        SecurityLogService $audit,
        ReferenceRepository $reference,
        private readonly ScheduleRepository $schedule,
    ) {
        parent::__construct($logger, $repo, $audit, $reference);
    }

    protected function entityName(): string
    {
        return 'teacher_class_assignment';
    }

    protected function usesSoftDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'teacher_id'       => 'required|integer',
            'class_id'         => 'required|integer',
            'course_id'        => 'required|integer',
            'academic_term_id' => 'required|integer',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return [
            'teacher_id'       => 'teacher_id',
            'class_id'         => 'class_id',
            'course_id'        => 'course_id',
            'academic_term_id' => 'academic_term_id',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        foreach (['teacher_id', 'class_id', 'course_id', 'academic_term_id'] as $key) {
            if ($isCreate || array_key_exists($key, $input)) {
                $data[$key] = (int) ($input[$key] ?? 0);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return TeacherClassAssignment::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('teacher_id', 'teachers', $data['teacher_id'] ?? null);
        $this->requireReference('class_id', 'classes', $data['class_id'] ?? null);
        $this->requireReference('course_id', 'courses', $data['course_id'] ?? null);
        $this->requireReference('academic_term_id', 'academic_terms', $data['academic_term_id'] ?? null, false);

        $teacherId = (int) ($data['teacher_id'] ?? 0);
        $classId   = (int) ($data['class_id'] ?? 0);
        $courseId  = (int) ($data['course_id'] ?? 0);
        $termId    = (int) ($data['academic_term_id'] ?? 0);

        if ($this->repo->existsActiveMatching([
            'teacher_id'       => $teacherId,
            'class_id'         => $classId,
            'course_id'        => $courseId,
            'academic_term_id' => $termId,
        ], $existing['id'] ?? null)) {
            $this->fail('course_id', 'This teacher is already assigned to teach this course to this class this term.');
        }

        if (!$this->schedule->courseOfferedToClass($classId, $courseId, $termId)) {
            $this->fail('course_id', 'This course is not offered to this class in this term — add a class-course first.');
        }

        if (!$this->schedule->teacherResponsibleForCourse($teacherId, $courseId, $termId)) {
            $this->fail('teacher_id', 'This teacher is not responsible for this course in this term — add a teacher-course first.');
        }
    }

    /** @return array<int, array{0:string,1:string,2:bool}> */
    protected function blockingChildren(): array
    {
        return [
            ['course_schedules', 'teacher_class_assignment_id', false],
        ];
    }
}
