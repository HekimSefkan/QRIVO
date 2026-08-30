<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Academic;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class AcademicYearRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'academic_years';
    }

    protected function usesSoftDeletes(): bool
    {
        return false;
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['school_id', 'name', 'start_date', 'end_date', 'is_active'];
    }

    /** @return string[] */
    protected function searchableColumns(): array
    {
        return ['name'];
    }
}
