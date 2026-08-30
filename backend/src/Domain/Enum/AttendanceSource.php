<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * How an `attendance_records.status` value was set (TABLES.md `attendance_records.source`, DD-006):
 * - SYSTEM: initialised when the session was created (all students start WAITING)
 * - QR:     set via the challenge-response attendance flow
 * - MANUAL: set by a teacher override
 */
enum AttendanceSource: string
{
    case SYSTEM = 'SYSTEM';
    case QR     = 'QR';
    case MANUAL = 'MANUAL';
}
