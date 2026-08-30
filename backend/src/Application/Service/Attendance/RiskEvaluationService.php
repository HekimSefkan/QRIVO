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
 * This is the BASIC evaluator: by the time the pipeline reaches this step the
 * hard-failure signals (expired QR, replay, invalid challenge, wrong
 * relationship, duplicate attendance) have already rejected the request. What
 * remains that this phase can measure is *retry pressure* — a burst of challenge
 * requests for the same (student, session). The full engine (device, IP,
 * location, multi-device) and `system_settings` integration is Phase 19.
 *
 * Spec §6.14: risk thresholds are configuration, never hard-coded.
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

    public function evaluate(int $studentId, int $sessionId, \DateTimeImmutable $at): RiskAssessment
    {
        $since = $at->modify("-{$this->windowSeconds} seconds")->format('Y-m-d H:i:s');
        $recent = $this->challenges->countForStudentSessionSince($studentId, $sessionId, $since);

        if ($recent >= $this->highThreshold) {
            return new RiskAssessment(RiskLevel::HIGH, min(100, $recent * 10), RiskOutcome::PENDING_REVIEW, ['EXCESSIVE_CHALLENGE_REQUESTS']);
        }

        if ($recent >= $this->softThreshold) {
            return new RiskAssessment(RiskLevel::MEDIUM, min(100, $recent * 10), RiskOutcome::PRESENT, ['ELEVATED_CHALLENGE_REQUESTS']);
        }

        return RiskAssessment::low();
    }
}
