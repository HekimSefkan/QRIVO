<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Schedule;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class TeacherClassAssignmentRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'teacher_class_assignments';
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
        return ['teacher_id', 'class_id', 'course_id', 'academic_term_id'];
    }
}
