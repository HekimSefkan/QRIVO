<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Academic;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class SchoolRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'schools';
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['name', 'code'];
    }

    /** @return string[] */
    protected function searchableColumns(): array
    {
        return ['name', 'code'];
    }
}
