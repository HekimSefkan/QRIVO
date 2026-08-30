<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Schedule;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

/**
 * Read repository for `student_courses` — the derived enrollment lookup (DD-005).
 * Rows are written by {@see \QRIVO\Infrastructure\Repository\ScheduleRepository}
 * as a side effect of student/class-course changes, not by this repository.
 */
final class StudentCourseRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'student_courses';
    }

    protected function usesSoftDeletes(): bool
    {
        return false;
    }

    protected function updatedAtColumn(): ?string
    {
        return null;
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['student_id', 'course_id', 'class_id', 'academic_term_id'];
    }
}
