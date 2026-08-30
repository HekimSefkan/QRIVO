<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Academic;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class TeacherRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'teachers';
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['user_id', 'department_id', 'employee_number'];
    }

    /** @return string[] */
    protected function searchableColumns(): array
    {
        return ['employee_number'];
    }
}
