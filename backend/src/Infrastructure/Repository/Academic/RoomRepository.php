<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Repository\Academic;

use QRIVO\Infrastructure\Repository\AbstractCrudRepository;

final class RoomRepository extends AbstractCrudRepository
{
    protected function table(): string
    {
        return 'rooms';
    }

    /** @return string[] */
    protected function writableColumns(): array
    {
        return ['school_id', 'name', 'code', 'capacity'];
    }

    /** @return string[] */
    protected function searchableColumns(): array
    {
        return ['name', 'code'];
    }
}
