<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Outcome of the "may this teacher open attendance for course/class/time?" check.
 *
 * This mirrors the pre-QR portion of ATTENDANCE_ALGORITHM.md §2 (teacher
 * authentication is handled before this point; steps 2-9 are covered here).
 */
enum AttendanceEligibilityReason: string
{
    case AUTHORIZED                  = 'AUTHORIZED';
    case NOT_A_TEACHER               = 'NOT_A_TEACHER';
    case NO_TEACHER_PROFILE          = 'NO_TEACHER_PROFILE';
    case NO_ACTIVE_TERM              = 'NO_ACTIVE_TERM';
    case NOT_ASSIGNED_TO_CLASS_COURSE = 'NOT_ASSIGNED_TO_CLASS_COURSE';
    case NOT_SCHEDULED_ON_DAY        = 'NOT_SCHEDULED_ON_DAY';
    case OUTSIDE_SCHEDULED_TIME      = 'OUTSIDE_SCHEDULED_TIME';

    public function isAuthorized(): bool
    {
        return $this === self::AUTHORIZED;
    }

    public function message(): string
    {
        return match ($this) {
            self::AUTHORIZED                   => 'Authorized to open attendance.',
            self::NOT_A_TEACHER                => 'The account does not hold the TEACHER role.',
            self::NO_TEACHER_PROFILE           => 'The account has no teacher profile.',
            self::NO_ACTIVE_TERM               => 'No active academic term could be determined.',
            self::NOT_ASSIGNED_TO_CLASS_COURSE => 'You are not assigned to teach this course to this class this term.',
            self::NOT_SCHEDULED_ON_DAY         => 'This class is not scheduled to meet today.',
            self::OUTSIDE_SCHEDULED_TIME       => 'The current time is outside the scheduled meeting time.',
        };
    }
}
