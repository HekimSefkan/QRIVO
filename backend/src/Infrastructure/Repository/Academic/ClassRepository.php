<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Academic;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class ClassRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'classes';
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['program_id', 'academic_term_id', 'name', 'grade_level'];
    }

    /** @return string[] */
    protected function searchableColumns(): array
    {
        return ['name'];
    }
}
