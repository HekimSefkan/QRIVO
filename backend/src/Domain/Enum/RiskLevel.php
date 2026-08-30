<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Risk levels (PROJECT_SPECIFICATION.md §6.14, ATTENDANCE_ALGORITHM.md §9).
 */
enum RiskLevel: string
{
    case LOW     = 'LOW';
    case MEDIUM  = 'MEDIUM';
    case HIGH    = 'HIGH';
    case BLOCKED = 'BLOCKED';
}
