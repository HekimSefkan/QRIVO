<?php

declare(strict_types=1);

namespace QRIVO\Domain\Contract;

use QRIVO\Domain\Attendance\RiskAssessment;

/**
 * Risk evaluation for an attendance attempt — step 13 of ATTENDANCE_ALGORITHM.md §4.
 *
 * The challenge-response pipeline always calls this and always persists the
 * result to `risk_assessments`. Phase 12 ships a basic implementation; Phase 18
 * adds device/session signals via `$context['device_signals']`; Phase 19
 * replaces it with the full engine (IP / location, configured via
 * `system_settings`).
 *
 * `$context` carries pre-computed signals gathered outside this evaluator, e.g.
 * `['device_signals' => ['DEVICE_MISMATCH', 'MULTIPLE_ACTIVE_DEVICES']]`.
 */
interface RiskEvaluatorInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function evaluate(int $studentId, int $sessionId, \DateTimeImmutable $at, array $context = []): RiskAssessment;
}
