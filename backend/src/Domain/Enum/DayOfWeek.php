<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Day of week as stored in `course_schedules.day_of_week` (TABLES.md Group 4):
 * 0 = Monday … 6 = Sunday.
 */
enum DayOfWeek: int
{
    case MONDAY    = 0;
    case TUESDAY   = 1;
    case WEDNESDAY = 2;
    case THURSDAY  = 3;
    case FRIDAY    = 4;
    case SATURDAY  = 5;
    case SUNDAY    = 6;

    public function label(): string
    {
        return match ($this) {
            self::MONDAY    => 'Monday',
            self::TUESDAY   => 'Tuesday',
            self::WEDNESDAY => 'Wednesday',
            self::THURSDAY  => 'Thursday',
            self::FRIDAY    => 'Friday',
            self::SATURDAY  => 'Saturday',
            self::SUNDAY    => 'Sunday',
        };
    }

    /**
     * The QRIVO day-of-week value for a PHP date (PHP `N`: 1=Mon..7=Sun → 0..6).
     */
    public static function fromDateTime(\DateTimeInterface $dt): self
    {
        return self::from(((int) $dt->format('N')) - 1);
    }
}
