<?php

declare(strict_types=1);

namespace QRIVO\Domain\Contract;

use QRIVO\Domain\Attendance\RiskAssessment;

/**
 * Risk evaluation for an attendance attempt — step 13 of ATTENDANCE_ALGORITHM.md §4.
 *
 * The challenge-response pipeline always calls this and always persists the
 * result to `risk_assessments`. Phase 12 ships a basic implementation; Phase 19
 * replaces it with the full engine (device / IP / location / multi-device,
 * configured via `system_settings`).
 */
interface RiskEvaluatorInterface
{
    public function evaluate(int $studentId, int $sessionId, \DateTimeImmutable $at): RiskAssessment;
}
