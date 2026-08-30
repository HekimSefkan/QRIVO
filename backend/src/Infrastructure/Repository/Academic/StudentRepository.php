<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Academic;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class StudentRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'students';
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['user_id', 'program_id', 'student_number', 'enrollment_year'];
    }

    /** @return string[] */
    protected function searchableColumns(): array
    {
        return ['student_number'];
    }
}
