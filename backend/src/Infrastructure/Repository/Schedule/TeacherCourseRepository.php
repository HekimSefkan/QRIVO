<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Schedule;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class TeacherCourseRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'teacher_courses';
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
        return ['teacher_id', 'course_id', 'academic_term_id'];
    }
}
