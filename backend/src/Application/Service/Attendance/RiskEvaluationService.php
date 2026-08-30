<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Attendance;

use QRIVO\Application\Service\BaseService;
use QRIVO\Domain\Attendance\RiskAssessment;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Contract\RiskEvaluatorInterface;
use QRIVO\Domain\Enum\RiskLevel;
use QRIVO\Domain\Enum\RiskOutcome;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Repository\Attendance\QrChallengeRepository;

/**
 * Risk evaluation for an attendance attempt — step 13 of ATTENDANCE_ALGORITHM.md §4.
 *
 * By the time the pipeline reaches this step the hard-failure signals (expired
 * QR, replay, invalid challenge, wrong relationship, duplicate attendance) have
 * already rejected the request. What this evaluator weighs is:
 *
 *   - retry pressure: a burst of challenge requests for the same (student,
 *     session) inside the window;
 *   - device signals (Phase 18): `DEVICE_MISMATCH`, `MULTIPLE_ACTIVE_DEVICES`,
 *     `NEW_DEVICE`, supplied via `$context['device_signals']` by
 *     {@see \QRIVO\Application\Service\Security\DeviceSessionService}.
 *
 * The full engine (IP / location / `system_settings`) is Phase 19.
 * Spec §6.14: thresholds are configuration, never hard-coded.
 *
 * Outcome mapping (ATTENDANCE_ALGORITHM.md §9):
 *   LOW     -> PRESENT
 *   MEDIUM  -> PRESENT (+ the caller records a SECURITY_EVENT)
 *   HIGH    -> PENDING_REVIEW
 *   BLOCKED -> no attendance record
 */
final class RiskEvaluationService extends BaseService implements RiskEvaluatorInterface
{
    private readonly int $softThreshold;
    private readonly int $highThreshold;
    private readonly int $windowSeconds;

    /** @var array<string, int> device-signal name => rank */
    private const DEVICE_SIGNAL_LEVEL = [
        'DEVICE_MISMATCH'         => 2, // HIGH  — likely token relay / theft
        'MULTIPLE_ACTIVE_DEVICES' => 1, // MEDIUM
        'NEW_DEVICE'              => 1, // MEDIUM
    ];

    public function __construct(
        LoggerInterface $logger,
        private readonly QrChallengeRepository $challenges,
        Config $config,
    ) {
        parent::__construct($logger);
        $this->softThreshold = max(1, $config->getInt('attendance.risk.soft_retry_threshold', 6));
        $this->highThreshold = max($this->softThreshold + 1, $config->getInt('attendance.risk.high_retry_threshold', 9));
        $this->windowSeconds = max(30, $config->getInt('attendance.risk.retry_window_seconds', 300));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function evaluate(int $studentId, int $sessionId, \DateTimeImmutable $at, array $context = []): RiskAssessment
    {
        // ── retry pressure ──
        $since  = $at->modify("-{$this->windowSeconds} seconds")->format('Y-m-d H:i:s');
        $recent = $this->challenges->countForStudentSessionSince($studentId, $sessionId, $since);

        $rank    = 0; // 0 LOW · 1 MEDIUM · 2 HIGH
        $score   = 0;
        $signals = [];

        if ($recent >= $this->highThreshold) {
            $rank      = 2;
            $score     = min(100, $recent * 10);
            $signals[] = 'EXCESSIVE_CHALLENGE_REQUESTS';
        } elseif ($recent >= $this->softThreshold) {
            $rank      = 1;
            $score     = min(100, $recent * 10);
            $signals[] = 'ELEVATED_CHALLENGE_REQUESTS';
        }

        // ── device signals (Phase 18) ──
        /** @var string[] $deviceSignals */
        $deviceSignals = array_values(array_filter(
            (array) ($context['device_signals'] ?? []),
            static fn ($s): bool => is_string($s) && $s !== '',
        ));

        foreach ($deviceSignals as $signal) {
            $signalRank = self::DEVICE_SIGNAL_LEVEL[$signal] ?? 1;
            $rank       = max($rank, $signalRank);
            $score      = max($score, $signalRank >= 2 ? 70 : 40);
            $signals[]  = $signal;
        }

        if ($rank === 0) {
            return RiskAssessment::low();
        }

        $level   = $rank >= 2 ? RiskLevel::HIGH : RiskLevel::MEDIUM;
        $outcome = $rank >= 2 ? RiskOutcome::PENDING_REVIEW : RiskOutcome::PRESENT;

        return new RiskAssessment($level, $score, $outcome, array_values(array_unique($signals)));
    }
}
