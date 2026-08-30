<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Academic;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class DepartmentRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'departments';
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['faculty_id', 'name', 'code'];
    }

    /** @return string[] */
    protected function searchableColumns(): array
    {
        return ['name', 'code'];
    }
}
