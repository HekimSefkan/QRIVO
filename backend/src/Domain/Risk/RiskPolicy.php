<?php

declare(strict_types=1);

namespace QRIVO\Domain\Risk;

use QRIVO\Domain\Enum\RiskLevel;
use QRIVO\Domain\Enum\RiskSignal;

/**
 * The resolved risk-scoring parameters — per-signal weights, the LOW/MEDIUM/
 * HIGH/BLOCKED thresholds, and the detection windows.
 *
 * Spec §6.14: "Risk values managed via `system_settings` or configuration — not
 * hard-coded." This object is the single, immutable representation of that
 * configuration; {@see \QRIVO\Application\Service\Security\RiskScoringService}
 * builds it once per evaluation and is the only consumer.
 *
 * The scoring mechanism is fixed and simple: score = Σ weight(signal) for each
 * signal that fired, capped at {@see self::SCORE_CAP}; the score maps to a level
 * by threshold; the level maps to an outcome by the fixed §9 table
 * ({@see RiskLevel::toOutcome()}).
 */
final class RiskPolicy
{
    public const SCORE_CAP = 100;

    /**
     * @param array<string, int> $weights          signal value => weight
     * @param int                $mediumThreshold  score ≥ this  → at least MEDIUM
     * @param int                $highThreshold    score ≥ this  → at least HIGH
     * @param int                $blockedThreshold score ≥ this  → BLOCKED
     * @param int                $retryWindowSeconds   look-back for challenge-retry counting
     * @param int                $excessiveRetryCount  challenge requests in the window that trip EXCESSIVE_RETRY
     * @param int                $historyWindowSeconds look-back over `security_events` for recent-abuse signals
     * @param list<string>       $suspiciousIps    exact IPs that trip SUSPICIOUS_IP (default empty ⇒ never)
     */
    public function __construct(
        private readonly array $weights,
        private readonly int $mediumThreshold,
        private readonly int $highThreshold,
        private readonly int $blockedThreshold,
        public readonly int $retryWindowSeconds,
        public readonly int $excessiveRetryCount,
        public readonly int $historyWindowSeconds,
        private readonly array $suspiciousIps,
    ) {}

    public function weightFor(RiskSignal $signal): int
    {
        return $this->weights[$signal->value] ?? $signal->defaultWeight();
    }

    public function isSuspiciousIp(?string $ip): bool
    {
        return $ip !== null && $ip !== '' && in_array($ip, $this->suspiciousIps, true);
    }

    /**
     * @param iterable<RiskSignal> $signals
     */
    public function score(iterable $signals): int
    {
        $total = 0;
        foreach ($signals as $signal) {
            $total += max(0, $this->weightFor($signal));
        }

        return min(self::SCORE_CAP, $total);
    }

    public function levelForScore(int $score): RiskLevel
    {
        return match (true) {
            $score >= $this->blockedThreshold => RiskLevel::BLOCKED,
            $score >= $this->highThreshold    => RiskLevel::HIGH,
            $score >= $this->mediumThreshold  => RiskLevel::MEDIUM,
            default                           => RiskLevel::LOW,
        };
    }

    /** @return array{medium:int, high:int, blocked:int} */
    public function thresholds(): array
    {
        return [
            'medium'  => $this->mediumThreshold,
            'high'    => $this->highThreshold,
            'blocked' => $this->blockedThreshold,
        ];
    }
}
