<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Attendance record status values.
 *
 * Defined in PROJECT_SPECIFICATION.md §6.9 and ATTENDANCE_ALGORITHM.md.
 * PENDING_REVIEW was added as a full status value (OQ-001 resolution).
 */
enum AttendanceStatus: string
{
    case WAITING        = 'WAITING';
    case PRESENT        = 'PRESENT';
    case ABSENT         = 'ABSENT';
    case LATE           = 'LATE';
    case EXCUSED        = 'EXCUSED';
    case PENDING_REVIEW = 'PENDING_REVIEW';

    /**
     * Returns statuses that a teacher may manually assign.
     *
     * @return list<self>
     */
    public static function teacherAssignable(): array
    {
        return [
            self::WAITING,
            self::PRESENT,
            self::ABSENT,
            self::LATE,
            self::EXCUSED,
        ];
    }

    /**
     * Returns statuses that are terminal (no further transitions expected).
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [
            self::PRESENT,
            self::ABSENT,
            self::LATE,
            self::EXCUSED,
        ];
    }
}
