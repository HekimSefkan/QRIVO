<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Attendance session status values.
 *
 * Defined in PROJECT_SPECIFICATION.md §6.5.
 */
enum SessionStatus: string
{
    case ACTIVE    = 'ACTIVE';
    case CLOSED    = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isTerminal(): bool
    {
        return $this === self::CLOSED || $this === self::CANCELLED;
    }
}
