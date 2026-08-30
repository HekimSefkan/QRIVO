<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Schedule;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class StudentClassAssignmentRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'student_class_assignments';
    }

    protected function usesSoftDeletes(): bool
    {
        return false;
    }

    protected function createdAtColumn(): ?string
    {
        return 'enrolled_at';
    }

    protected function updatedAtColumn(): ?string
    {
        return null;
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['student_id', 'class_id', 'academic_term_id'];
    }
}
