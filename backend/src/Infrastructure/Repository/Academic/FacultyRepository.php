<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Academic;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class FacultyRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'faculties';
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['school_id', 'name', 'code'];
    }

    /** @return string[] */
    protected function searchableColumns(): array
    {
        return ['name', 'code'];
    }
}
