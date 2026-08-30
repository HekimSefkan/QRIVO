<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Academic;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class CourseRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'courses';
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['department_id', 'name', 'code', 'credit_hours'];
    }

    /** @return string[] */
    protected function searchableColumns(): array
    {
        return ['name', 'code'];
    }
}
