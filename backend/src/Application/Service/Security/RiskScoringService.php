<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Security;

use QRIVO\Application\Service\BaseService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Attendance\RiskAssessment;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Contract\RiskEvaluatorInterface;
use QRIVO\Domain\Enum\RiskSignal;
use QRIVO\Domain\Risk\RiskPolicy;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Repository\Attendance\QrChallengeRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Infrastructure\Repository\SystemSettingRepository;

/**
 * The single, centralised risk-scoring engine
 * (PROJECT_SPECIFICATION.md §6.14, ATTENDANCE_ALGORITHM.md §9,
 * SECURITY_RULES.md §8).
 *
 * There is exactly ONE implementation of {@see RiskEvaluatorInterface}; every
 * attendance attempt is scored here and nowhere else. Signal DETECTION happens
 * in the pipeline and in {@see DeviceSessionService}; this service only WEIGHS
 * the signals it is given (via `$context`) plus the ones it can read itself
 * (challenge-retry pressure, recent `security_events` for the user).
 *
 * Signals: the fixed catalogue in {@see RiskSignal} — nothing else.
 * Scoring:  score = Σ configured-weight(signal), capped at 100.
 * Level:    score vs the configured MEDIUM / HIGH / BLOCKED thresholds.
 * Outcome:  the fixed §9 table ({@see RiskLevel::toOutcome()}).
 *
 * Every tunable comes from `system_settings` → `config/risk.php` → the
 * {@see RiskSignal} defaults (spec §6.14: never hard-coded).
 */
final class RiskScoringService extends BaseService implements RiskEvaluatorInterface
{
    /** `security_events.event_type` → the risk signal it contributes when recent. */
    private const HISTORY_MAP = [
        'QR_EXPIRED'              => RiskSignal::EXPIRED_QR,
        'QR_REPLAY'               => RiskSignal::REPLAY_ATTEMPT,
        'QR_INVALID'              => RiskSignal::INVALID_CHALLENGE,
        'CHALLENGE_INVALID'       => RiskSignal::INVALID_CHALLENGE,
        'CHALLENGE_EXPIRED'       => RiskSignal::INVALID_CHALLENGE,
        'DUPLICATE_ATTENDANCE'    => RiskSignal::DUPLICATE_ATTENDANCE,
        'UNAUTHORIZED_ATTENDANCE' => RiskSignal::UNAUTHORIZED_RELATIONSHIP,
    ];

    /** Phase-18 device-signal name → canonical risk signal. */
    private const DEVICE_MAP = [
        'NEW_DEVICE'              => RiskSignal::NEW_DEVICE,
        'MULTIPLE_ACTIVE_DEVICES' => RiskSignal::MULTIPLE_DEVICE_ACTIVITY,
        'DEVICE_MISMATCH'         => RiskSignal::MULTIPLE_DEVICE_ACTIVITY,
    ];

    public function __construct(
        LoggerInterface $logger,
        private readonly QrChallengeRepository $challenges,
        private readonly SecurityEventRepository $securityEvents,
        private readonly SystemSettingRepository $settings,
        private readonly SecurityLogService $securityLog,
        private readonly Config $config,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $context
     *   device_signals?: string[]            — from DeviceSessionService (Phase 18)
     *   user_id?: int                        — for the security_events look-back
     *   ip_address?: string                  — for SUSPICIOUS_IP
     *   location_mismatch?: bool             — OQ-003 (inert unless supplied)
     *   unauthorized_relationship?: bool     — explicit override of the history signal
     */
    public function evaluate(int $studentId, int $sessionId, \DateTimeImmutable $at, array $context = []): RiskAssessment
    {
        $policy = $this->policy();

        /** @var array<string, RiskSignal> $fired  keyed by ->value to dedupe */
        $fired = [];
        $add = static function (RiskSignal $s) use (&$fired): void {
            $fired[$s->value] = $s;
        };

        // 1. Device signals (detected in Phase 18, passed through here).
        foreach ($this->stringList($context['device_signals'] ?? null) as $name) {
            if (isset(self::DEVICE_MAP[$name])) {
                $add(self::DEVICE_MAP[$name]);
            }
        }

        // 2. Excessive retry — challenge requests for this (student, session).
        $retrySince = $at->modify("-{$policy->retryWindowSeconds} seconds")->format('Y-m-d H:i:s');
        if ($this->challenges->countForStudentSessionSince($studentId, $sessionId, $retrySince) >= $policy->excessiveRetryCount) {
            $add(RiskSignal::EXCESSIVE_RETRY);
        }

        // 3. Recent abuse recorded against this user in `security_events`.
        $userId = (isset($context['user_id']) && is_numeric($context['user_id'])) ? (int) $context['user_id'] : 0;
        if ($userId > 0) {
            $historySince = $at->modify("-{$policy->historyWindowSeconds} seconds")->format('Y-m-d H:i:s');
            $counts = $this->securityEvents->countByUserSince($userId, $historySince);
            foreach (self::HISTORY_MAP as $eventType => $signal) {
                if (($counts[$eventType] ?? 0) > 0) {
                    $add($signal);
                }
            }
        }

        // 4. Suspicious IP — configured list only (OQ-010: no heuristic on shared campus WiFi).
        $ip = (isset($context['ip_address']) && is_string($context['ip_address'])) ? $context['ip_address'] : null;
        if ($policy->isSuspiciousIp($ip)) {
            $add(RiskSignal::SUSPICIOUS_IP);
        }

        // 5. Location mismatch — OQ-003: no location pipeline yet; honoured only
        //    when a caller explicitly supplies the signal.
        if (($context['location_mismatch'] ?? null) === true) {
            $add(RiskSignal::LOCATION_MISMATCH);
        }

        // 6. Unauthorized relationship — explicit override (the history map above
        //    already covers the recorded-event case).
        if (($context['unauthorized_relationship'] ?? null) === true) {
            $add(RiskSignal::UNAUTHORIZED_RELATIONSHIP);
        }

        $signals = array_values($fired);
        if ($signals === []) {
            return RiskAssessment::low();
        }

        $score = $policy->score($signals);
        $level = $policy->levelForScore($score);

        return new RiskAssessment(
            $level,
            $score,
            $level->toOutcome(),
            array_map(static fn (RiskSignal $s): string => $s->value, $signals),
        );
    }

    /**
     * Record the SECURITY_EVENT the spec requires for an elevated outcome
     * (§9: MEDIUM → "PRESENT + SECURITY_EVENT"; HIGH → PENDING_REVIEW;
     * BLOCKED → blocked). No-op for LOW. Call this OUTSIDE the DB transaction.
     * The level → event-type mapping lives on {@see RiskLevel}.
     */
    public function recordEscalation(
        RiskAssessment $risk,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        ?int $attendanceSessionId,
    ): void {
        $type = $risk->level->securityEventType();
        if ($type === null) {
            return;
        }

        $this->securityLog->recordSecurityEvent(
            $type,
            $risk->level->securityEventSeverity(),
            $userId,
            $ip,
            $userAgent,
            [
                'attendance_session_id' => $attendanceSessionId,
                'risk_level'            => $risk->level->value,
                'risk_score'            => $risk->score,
                'signals'               => $risk->signals,
            ],
        );
    }

    // ─── policy resolution (system_settings → config/risk.php → defaults) ─────

    private function policy(): RiskPolicy
    {
        $overrides = $this->settings->allWithPrefix('risk.');

        $int = function (string $key, int $default) use ($overrides): int {
            if (isset($overrides[$key]) && is_numeric($overrides[$key])) {
                return (int) $overrides[$key];
            }
            $cfg = $this->config->get($key);

            return is_numeric($cfg) ? (int) $cfg : $default;
        };

        $weights = [];
        foreach (RiskSignal::all() as $signal) {
            $weights[$signal->value] = $int($signal->weightKey(), $signal->defaultWeight());
        }

        $ipRaw = $overrides['risk.ip.suspicious_list']
            ?? (string) $this->config->get('risk.ip.suspicious_list', '');
        $ips = array_values(array_filter(
            array_map('trim', explode(',', $ipRaw)),
            static fn (string $v): bool => $v !== '',
        ));

        return new RiskPolicy(
            weights: $weights,
            mediumThreshold: $int('risk.threshold.medium', 30),
            highThreshold: $int('risk.threshold.high', 60),
            blockedThreshold: $int('risk.threshold.blocked', 100),
            retryWindowSeconds: max(30, $int('risk.retry.window_seconds', 300)),
            excessiveRetryCount: max(1, $int('risk.retry.excessive_count', 6)),
            historyWindowSeconds: max(60, $int('risk.history.window_seconds', 900)),
            suspiciousIps: $ips,
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($v): bool => is_string($v) && $v !== '',
        ));
    }
}
