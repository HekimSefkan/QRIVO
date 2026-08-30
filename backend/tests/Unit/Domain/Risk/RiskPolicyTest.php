<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Domain\Risk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QRIVO\Domain\Enum\RiskLevel;
use QRIVO\Domain\Enum\RiskSignal;
use QRIVO\Domain\Risk\RiskPolicy;

/**
 * Pure scoring maths for the risk model (PROJECT_SPECIFICATION.md §6.14,
 * ATTENDANCE_ALGORITHM.md §9). No database.
 */
final class RiskPolicyTest extends TestCase
{
    private function policy(array $weights = [], int $medium = 30, int $high = 60, int $blocked = 100): RiskPolicy
    {
        return new RiskPolicy(
            weights: $weights,
            mediumThreshold: $medium,
            highThreshold: $high,
            blockedThreshold: $blocked,
            retryWindowSeconds: 300,
            excessiveRetryCount: 6,
            historyWindowSeconds: 900,
            suspiciousIps: ['203.0.113.9'],
        );
    }

    public function test_weight_falls_back_to_the_signal_default(): void
    {
        $policy = $this->policy();
        $this->assertSame(RiskSignal::REPLAY_ATTEMPT->defaultWeight(), $policy->weightFor(RiskSignal::REPLAY_ATTEMPT));
    }

    public function test_configured_weight_overrides_the_default(): void
    {
        $policy = $this->policy(['NEW_DEVICE' => 99]);
        $this->assertSame(99, $policy->weightFor(RiskSignal::NEW_DEVICE));
    }

    public function test_score_sums_weights_and_caps_at_100(): void
    {
        $policy = $this->policy(['NEW_DEVICE' => 40, 'SUSPICIOUS_IP' => 40]);
        $this->assertSame(80, $policy->score([RiskSignal::NEW_DEVICE, RiskSignal::SUSPICIOUS_IP]));

        $policy = $this->policy(['NEW_DEVICE' => 70, 'SUSPICIOUS_IP' => 70]);
        $this->assertSame(RiskPolicy::SCORE_CAP, $policy->score([RiskSignal::NEW_DEVICE, RiskSignal::SUSPICIOUS_IP]));
    }

    public function test_negative_weights_are_clamped_to_zero(): void
    {
        $policy = $this->policy(['NEW_DEVICE' => -50]);
        $this->assertSame(0, $policy->score([RiskSignal::NEW_DEVICE]));
    }

    #[DataProvider('levelBoundaries')]
    public function test_level_for_score(int $score, RiskLevel $expected): void
    {
        $this->assertSame($expected, $this->policy()->levelForScore($score));
    }

    public static function levelBoundaries(): array
    {
        return [
            'just below medium' => [29, RiskLevel::LOW],
            'exactly medium'    => [30, RiskLevel::MEDIUM],
            'just below high'   => [59, RiskLevel::MEDIUM],
            'exactly high'      => [60, RiskLevel::HIGH],
            'just below blocked'=> [99, RiskLevel::HIGH],
            'exactly blocked'   => [100, RiskLevel::BLOCKED],
        ];
    }

    public function test_outcome_mapping_is_fixed_by_the_spec(): void
    {
        $this->assertSame('PRESENT', RiskLevel::LOW->toOutcome()->value);
        $this->assertSame('PRESENT', RiskLevel::MEDIUM->toOutcome()->value);
        $this->assertSame('PENDING_REVIEW', RiskLevel::HIGH->toOutcome()->value);
        $this->assertSame('BLOCKED', RiskLevel::BLOCKED->toOutcome()->value);
    }

    public function test_suspicious_ip_membership(): void
    {
        $policy = $this->policy();
        $this->assertTrue($policy->isSuspiciousIp('203.0.113.9'));
        $this->assertFalse($policy->isSuspiciousIp('10.0.0.1'));
        $this->assertFalse($policy->isSuspiciousIp(null));
        $this->assertFalse($policy->isSuspiciousIp(''));
    }

    public function test_only_the_ten_spec_signals_exist(): void
    {
        $names = array_map(static fn (RiskSignal $s): string => $s->value, RiskSignal::all());
        sort($names);
        $this->assertSame([
            'DUPLICATE_ATTENDANCE',
            'EXCESSIVE_RETRY',
            'EXPIRED_QR',
            'INVALID_CHALLENGE',
            'LOCATION_MISMATCH',
            'MULTIPLE_DEVICE_ACTIVITY',
            'NEW_DEVICE',
            'REPLAY_ATTEMPT',
            'SUSPICIOUS_IP',
            'UNAUTHORIZED_RELATIONSHIP',
        ], $names);
    }
}
