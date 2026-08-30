<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Schedule;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Domain\Entity\Schedule\StudentCourse;
use QRIVO\Domain\Exception\ConflictException;

/**
 * Read access to `student_courses` — the derived enrollment lookup (DD-005).
 *
 * Rows are maintained automatically by {@see StudentClassAssignmentService} and
 * {@see ClassCourseService}. Direct create/update/delete is rejected; only the
 * list and get endpoints are wired.
 */
final class StudentCourseService extends AbstractAcademicService
{
    protected function entityName(): string
    {
        return 'student_course';
    }

    protected function usesSoftDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return [
            'student_id'       => 'student_id',
            'course_id'        => 'course_id',
            'class_id'         => 'class_id',
            'academic_term_id' => 'academic_term_id',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        return [];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return StudentCourse::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function create(array $input, array $actor): array
    {
        throw new ConflictException('student_courses is derived and cannot be modified directly. Manage student_class_assignments / class_courses instead.');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function update(int $id, array $input, array $actor): array
    {
        throw new ConflictException('student_courses is derived and cannot be modified directly.');
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function delete(int $id, array $actor): void
    {
        throw new ConflictException('student_courses is derived and cannot be modified directly.');
    }
}
