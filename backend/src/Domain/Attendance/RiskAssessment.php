<?php

declare(strict_types=1);

namespace QRIVO\Domain\Attendance;

use QRIVO\Domain\Enum\RiskLevel;
use QRIVO\Domain\Enum\RiskOutcome;

/**
 * The result of risk evaluation for one attendance attempt
 * (ATTENDANCE_ALGORITHM.md §9). Persisted to `risk_assessments`.
 *
 * `signals` names which risk signals fired — it must never contain passwords,
 * tokens, or private keys (TABLES.md `risk_assessments.signals`).
 */
final class RiskAssessment
{
    /**
     * @param string[] $signals
     */
    public function __construct(
        public readonly RiskLevel $level,
        public readonly int $score,
        public readonly RiskOutcome $outcome,
        public readonly array $signals = [],
    ) {}

    public static function low(): self
    {
        return new self(RiskLevel::LOW, 0, RiskOutcome::PRESENT, []);
    }

    /** @return array<string, mixed> row for `risk_assessments` (ids filled by the caller) */
    public function toRow(): array
    {
        return [
            'risk_level' => $this->level->value,
            'risk_score' => $this->score,
            'signals'    => $this->signals === [] ? null : json_encode(array_values($this->signals), JSON_UNESCAPED_SLASHES),
            'outcome'    => $this->outcome->value,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'risk_level' => $this->level->value,
            'risk_score' => $this->score,
            'outcome'    => $this->outcome->value,
            'signals'    => array_values($this->signals),
        ];
    }
}
