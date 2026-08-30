<?php

declare(strict_types=1);

namespace QRIVO\Domain\Enum;

/**
 * Attendance outcome produced by risk evaluation
 * (ATTENDANCE_ALGORITHM.md §9, `risk_assessments.outcome`):
 *
 *   PRESENT        — record the student PRESENT (LOW; MEDIUM also records a SECURITY_EVENT)
 *   PENDING_REVIEW — record PENDING_REVIEW for the teacher to resolve (HIGH)
 *   BLOCKED        — no attendance record written; challenge is still consumed (BLOCKED)
 */
enum RiskOutcome: string
{
    case PRESENT        = 'PRESENT';
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case BLOCKED        = 'BLOCKED';

    public function attendanceStatus(): ?AttendanceStatus
    {
        return match ($this) {
            self::PRESENT        => AttendanceStatus::PRESENT,
            self::PENDING_REVIEW => AttendanceStatus::PENDING_REVIEW,
            self::BLOCKED        => null,
        };
    }
}
