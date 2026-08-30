<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Schedule;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Entity\Schedule\StudentClassAssignment;
use QRIVO\Infrastructure\Repository\AbstractCrudRepository;
use QRIVO\Infrastructure\Repository\ReferenceRepository;
use QRIVO\Infrastructure\Repository\ScheduleRepository;

/**
 * Manages `student_class_assignments` (a student's enrollment in a class for a
 * term). Enrolling/unenrolling keeps the derived `student_courses` rows in sync
 * (DD-005): on create, one row per course offered to the class; on delete, all
 * derived rows for that (student, class, term) are removed.
 */
final class StudentClassAssignmentService extends AbstractAcademicService
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
        return 'student_class_assignment';
    }

    protected function usesSoftDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'student_id'       => 'required|integer',
            'class_id'         => 'required|integer',
            'academic_term_id' => 'required|integer',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return ['student_id' => 'student_id', 'class_id' => 'class_id', 'academic_term_id' => 'academic_term_id'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        foreach (['student_id', 'class_id', 'academic_term_id'] as $key) {
            if ($isCreate || array_key_exists($key, $input)) {
                $data[$key] = (int) ($input[$key] ?? 0);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return StudentClassAssignment::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('student_id', 'students', $data['student_id'] ?? null);
        $this->requireReference('class_id', 'classes', $data['class_id'] ?? null);
        $this->requireReference('academic_term_id', 'academic_terms', $data['academic_term_id'] ?? null, false);

        if (isset($data['student_id'], $data['class_id'], $data['academic_term_id'])
            && $this->repo->existsActiveMatching([
                'student_id'       => (int) $data['student_id'],
                'class_id'         => (int) $data['class_id'],
                'academic_term_id' => (int) $data['academic_term_id'],
            ], $existing['id'] ?? null)
        ) {
            $this->fail('class_id', 'This student is already enrolled in this class for this term.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $actor
     */
    protected function afterCreate(int $id, array $data, array $actor): void
    {
        $this->schedule->syncStudentCourses(
            (int) $data['student_id'],
            (int) $data['class_id'],
            (int) $data['academic_term_id'],
        );
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $actor
     */
    protected function afterDelete(int $id, array $existing, array $actor): void
    {
        $this->schedule->unsyncStudentCourses(
            (int) $existing['student_id'],
            (int) $existing['class_id'],
            (int) $existing['academic_term_id'],
        );
    }
}
