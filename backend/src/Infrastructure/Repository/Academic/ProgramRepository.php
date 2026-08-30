<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Academic;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class ProgramRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'programs';
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['department_id', 'name', 'code', 'duration_years'];
    }

    /** @return string[] */
    protected function searchableColumns(): array
    {
        return ['name', 'code'];
    }
}
