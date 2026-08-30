<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Security;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Security\RiskScoringService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Attendance\RiskAssessment;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\RiskLevel;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrChallengeRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Infrastructure\Repository\SystemSettingRepository;

/**
 * The centralised risk-scoring engine (PROJECT_SPECIFICATION.md §6.14,
 * ATTENDANCE_ALGORITHM.md §9, SECURITY_RULES.md §8).
 *
 * Every spec-defined signal has a scenario here, plus the score→level
 * boundaries, the score cap, signal de-duplication, the detection windows, and
 * the `system_settings` / `config` override precedence.
 *
 * SQLite in-memory; production uses MySQL.
 */
final class RiskScoringServiceTest extends TestCase
{
    private const STUDENT_ID = 7;
    private const SESSION_ID = 3;
    private const USER_ID = 42;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE qr_challenges (
                id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE,
                attendance_session_id INTEGER NOT NULL, student_id INTEGER NOT NULL,
                nonce TEXT NOT NULL UNIQUE, qr_nonce TEXT NOT NULL, expires_at TEXT NOT NULL,
                used_at TEXT, created_at TEXT NOT NULL
            );
            CREATE TABLE security_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, severity TEXT NOT NULL,
                user_id INTEGER, attendance_session_id INTEGER, ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT NOT NULL
            );
            CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, actor_user_id INTEGER,
                target_entity TEXT NOT NULL, target_id INTEGER, old_value TEXT, new_value TEXT, reason TEXT, ip_address TEXT, created_at TEXT NOT NULL
            );
            CREATE TABLE system_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT, "key" TEXT NOT NULL UNIQUE, "value" TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'string', description TEXT,
                created_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00', updated_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00'
            );
        SQL);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function connection(): Connection
    {
        $connection = new Connection(new Config(QRIVO_ROOT));
        $ref = new \ReflectionProperty($connection, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($connection, $this->pdo);

        return $connection;
    }

    /**
     * @param array<string, mixed> $configOverrides  dot-key => value merged into `risk.*`
     */
    private function service(array $configOverrides = []): RiskScoringService
    {
        $config = new Config(QRIVO_ROOT);
        if ($configOverrides !== []) {
            $ref = new \ReflectionProperty($config, 'data');
            $ref->setAccessible(true);
            $data = $ref->getValue($config);
            foreach ($configOverrides as $dotKey => $value) {
                $parts = explode('.', $dotKey);
                $cursor = &$data;
                foreach ($parts as $p) {
                    if (!isset($cursor[$p]) || !is_array($cursor[$p])) {
                        $cursor[$p] = [];
                    }
                    $cursor = &$cursor[$p];
                }
                $cursor = $value;
                unset($cursor);
            }
            $ref->setValue($config, $data);
        }

        $log = new SecurityLogService(
            $this->createMock(LoggerInterface::class),
            new SecurityEventRepository($this->connection()),
            new AuditLogRepository($this->connection()),
        );

        return new RiskScoringService(
            $this->createMock(LoggerInterface::class),
            new QrChallengeRepository($this->connection()),
            new SecurityEventRepository($this->connection()),
            new SystemSettingRepository($this->connection()),
            $log,
            $config,
        );
    }

    private function seedEvent(string $type, int $userId = self::USER_ID, string $ago = '-1 minute'): void
    {
        $this->pdo->prepare('INSERT INTO security_events (event_type, severity, user_id, created_at) VALUES (?,?,?,?)')
            ->execute([$type, 'HIGH', $userId, (new \DateTimeImmutable($ago))->format('Y-m-d H:i:s')]);
    }

    private function seedChallenges(int $count, string $ago = '-1 minute'): void
    {
        $ts = (new \DateTimeImmutable($ago))->format('Y-m-d H:i:s');
        for ($i = 0; $i < $count; $i++) {
            $this->pdo->prepare('INSERT INTO qr_challenges (uuid, attendance_session_id, student_id, nonce, qr_nonce, expires_at, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([bin2hex(random_bytes(6)), self::SESSION_ID, self::STUDENT_ID, "n{$i}-" . random_int(1, 1_000_000), "q{$i}", '2099-01-01 00:00:00', $ts]);
        }
    }

    private function setting(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT INTO system_settings ("key", "value", type) VALUES (?,?,?)')
            ->execute([$key, $value, 'integer']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function evaluate(array $context = []): RiskAssessment
    {
        return $this->service()->evaluate(
            self::STUDENT_ID,
            self::SESSION_ID,
            new \DateTimeImmutable('now'),
            $context + ['user_id' => self::USER_ID],
        );
    }

    // ─── no signals ─────────────────────────────────────────────────────────

    public function test_clean_attempt_is_low_risk(): void
    {
        $risk = $this->evaluate();

        $this->assertSame(RiskLevel::LOW, $risk->level);
        $this->assertSame(0, $risk->score);
        $this->assertSame('PRESENT', $risk->outcome->value);
        $this->assertSame([], $risk->signals);
    }

    // ─── one scenario per spec-defined signal ───────────────────────────────

    public function test_signal_expired_qr(): void
    {
        $this->seedEvent('QR_EXPIRED');
        $risk = $this->evaluate();

        $this->assertContains('EXPIRED_QR', $risk->signals);
        $this->assertSame(15, $risk->score);
        $this->assertSame(RiskLevel::LOW, $risk->level); // one fumble ≠ elevation
    }

    public function test_signal_replay_attempt_is_high(): void
    {
        $this->seedEvent('QR_REPLAY');
        $risk = $this->evaluate();

        $this->assertContains('REPLAY_ATTEMPT', $risk->signals);
        $this->assertSame(60, $risk->score);
        $this->assertSame(RiskLevel::HIGH, $risk->level);
        $this->assertSame('PENDING_REVIEW', $risk->outcome->value);
    }

    public function test_signal_invalid_challenge(): void
    {
        $this->seedEvent('CHALLENGE_INVALID');
        $this->assertContains('INVALID_CHALLENGE', $this->evaluate()->signals);
    }

    public function test_signal_invalid_challenge_also_from_qr_invalid_and_challenge_expired(): void
    {
        $this->seedEvent('QR_INVALID');
        $this->seedEvent('CHALLENGE_EXPIRED');
        $risk = $this->evaluate();

        // Three distinct event types, one signal, counted once.
        $this->assertSame(['INVALID_CHALLENGE'], $risk->signals);
        $this->assertSame(25, $risk->score);
    }

    public function test_signal_excessive_retry(): void
    {
        $this->seedChallenges(6); // default excessive_count = 6
        $risk = $this->evaluate();

        $this->assertContains('EXCESSIVE_RETRY', $risk->signals);
        $this->assertSame(RiskLevel::MEDIUM, $risk->level);
    }

    public function test_signal_excessive_retry_not_tripped_below_threshold(): void
    {
        $this->seedChallenges(5);
        $this->assertSame([], $this->evaluate()->signals);
    }

    public function test_signal_duplicate_attendance(): void
    {
        $this->seedEvent('DUPLICATE_ATTENDANCE');
        $risk = $this->evaluate();

        $this->assertContains('DUPLICATE_ATTENDANCE', $risk->signals);
        $this->assertSame(RiskLevel::MEDIUM, $risk->level);
    }

    public function test_signal_new_device(): void
    {
        $risk = $this->evaluate(['device_signals' => ['NEW_DEVICE']]);

        $this->assertSame(['NEW_DEVICE'], $risk->signals);
        $this->assertSame(15, $risk->score);
    }

    public function test_signal_multiple_device_activity(): void
    {
        $risk = $this->evaluate(['device_signals' => ['MULTIPLE_ACTIVE_DEVICES']]);

        $this->assertSame(['MULTIPLE_DEVICE_ACTIVITY'], $risk->signals);
        $this->assertSame(RiskLevel::MEDIUM, $risk->level);
    }

    public function test_device_mismatch_maps_to_multiple_device_activity(): void
    {
        $risk = $this->evaluate(['device_signals' => ['DEVICE_MISMATCH']]);
        $this->assertSame(['MULTIPLE_DEVICE_ACTIVITY'], $risk->signals);
    }

    public function test_unknown_device_signal_is_ignored(): void
    {
        $this->assertSame([], $this->evaluate(['device_signals' => ['WAT', '', 123]])->signals);
    }

    public function test_signal_suspicious_ip_from_configured_list(): void
    {
        $svc = $this->service(['risk.ip.suspicious_list' => '203.0.113.5, 198.51.100.9']);
        $risk = $svc->evaluate(self::STUDENT_ID, self::SESSION_ID, new \DateTimeImmutable('now'), [
            'user_id' => self::USER_ID,
            'ip_address' => '198.51.100.9',
        ]);

        $this->assertContains('SUSPICIOUS_IP', $risk->signals);
    }

    public function test_signal_suspicious_ip_does_not_fire_for_unlisted_ip(): void
    {
        // Default config: empty list ⇒ campus WiFi never a false positive (OQ-010).
        $this->assertSame([], $this->evaluate(['ip_address' => '10.1.2.3'])->signals);
    }

    public function test_signal_location_mismatch_only_when_supplied(): void
    {
        $this->assertSame([], $this->evaluate()->signals);

        $risk = $this->evaluate(['location_mismatch' => true]);
        $this->assertSame(['LOCATION_MISMATCH'], $risk->signals);
        $this->assertSame(RiskLevel::MEDIUM, $risk->level);
    }

    public function test_signal_unauthorized_relationship_from_context_blocks(): void
    {
        $risk = $this->evaluate(['unauthorized_relationship' => true]);

        $this->assertContains('UNAUTHORIZED_RELATIONSHIP', $risk->signals);
        $this->assertSame(100, $risk->score);
        $this->assertSame(RiskLevel::BLOCKED, $risk->level);
        $this->assertSame('BLOCKED', $risk->outcome->value);
    }

    public function test_signal_unauthorized_relationship_from_history(): void
    {
        $this->seedEvent('UNAUTHORIZED_ATTENDANCE');
        $this->assertSame(RiskLevel::BLOCKED, $this->evaluate()->level);
    }

    // ─── combination / scoring maths ────────────────────────────────────────

    public function test_signals_combine_additively(): void
    {
        $this->seedEvent('QR_EXPIRED');       // 15
        $this->seedChallenges(6);             // EXCESSIVE_RETRY 30
        $risk = $this->evaluate(['device_signals' => ['NEW_DEVICE']]); // 15

        $this->assertSame(60, $risk->score);
        $this->assertSame(RiskLevel::HIGH, $risk->level);
        $this->assertContains('EXPIRED_QR', $risk->signals);
        $this->assertContains('EXCESSIVE_RETRY', $risk->signals);
        $this->assertContains('NEW_DEVICE', $risk->signals);
    }

    public function test_score_is_capped_at_100(): void
    {
        $this->seedEvent('QR_REPLAY');            // 60
        $this->seedEvent('DUPLICATE_ATTENDANCE'); // 50
        $this->seedEvent('QR_EXPIRED');           // 15
        $risk = $this->evaluate();

        $this->assertSame(100, $risk->score);
        $this->assertSame(RiskLevel::BLOCKED, $risk->level);
    }

    // ─── detection windows ─────────────────────────────────────────────────

    public function test_history_outside_the_window_is_ignored(): void
    {
        $this->seedEvent('QR_REPLAY', ago: '-2 hours'); // window default 900s
        $this->assertSame([], $this->evaluate()->signals);
    }

    public function test_retries_outside_the_window_are_ignored(): void
    {
        $this->seedChallenges(6, ago: '-1 hour'); // retry window default 300s
        $this->assertSame([], $this->evaluate()->signals);
    }

    public function test_history_is_ignored_without_a_user_id(): void
    {
        $this->seedEvent('QR_REPLAY');
        $risk = $this->service()->evaluate(self::STUDENT_ID, self::SESSION_ID, new \DateTimeImmutable('now'), []);
        $this->assertSame([], $risk->signals);
    }

    public function test_history_is_scoped_to_the_user(): void
    {
        $this->seedEvent('QR_REPLAY', userId: 999);
        $this->assertSame([], $this->evaluate()->signals);
    }

    // ─── configuration precedence (spec §6.14: not hard-coded) ──────────────

    public function test_config_override_changes_a_weight(): void
    {
        $svc = $this->service(['risk.weight.new_device' => 100]);
        $risk = $svc->evaluate(self::STUDENT_ID, self::SESSION_ID, new \DateTimeImmutable('now'), [
            'user_id' => self::USER_ID,
            'device_signals' => ['NEW_DEVICE'],
        ]);

        $this->assertSame(100, $risk->score);
        $this->assertSame(RiskLevel::BLOCKED, $risk->level);
    }

    public function test_system_settings_override_beats_config(): void
    {
        // config default new_device = 15; system_settings says 45.
        $this->setting('risk.weight.new_device', '45');
        $risk = $this->evaluate(['device_signals' => ['NEW_DEVICE']]);

        $this->assertSame(45, $risk->score);
        $this->assertSame(RiskLevel::MEDIUM, $risk->level);
    }

    public function test_system_settings_override_threshold(): void
    {
        $this->setting('risk.threshold.medium', '10');
        $this->seedEvent('QR_EXPIRED'); // weight 15

        $this->assertSame(RiskLevel::MEDIUM, $this->evaluate()->level);
    }

    // ─── escalation events (integration with security_events) ───────────────

    public function test_record_escalation_emits_for_medium_high_blocked_and_skips_low(): void
    {
        $svc = $this->service();

        $svc->recordEscalation(new RiskAssessment(RiskLevel::LOW, 0, RiskLevel::LOW->toOutcome(), []), self::USER_ID, '1.2.3.4', 'UA', self::SESSION_ID);
        $this->assertSame(0, $this->eventCount());

        $svc->recordEscalation(new RiskAssessment(RiskLevel::MEDIUM, 40, RiskLevel::MEDIUM->toOutcome(), ['NEW_DEVICE']), self::USER_ID, '1.2.3.4', 'UA', self::SESSION_ID);
        $this->assertSame(1, $this->eventCount('RISK_ESCALATION'));

        $svc->recordEscalation(new RiskAssessment(RiskLevel::HIGH, 70, RiskLevel::HIGH->toOutcome(), ['REPLAY_ATTEMPT']), self::USER_ID, '1.2.3.4', 'UA', self::SESSION_ID);
        $this->assertSame(2, $this->eventCount('RISK_ESCALATION'));

        $svc->recordEscalation(new RiskAssessment(RiskLevel::BLOCKED, 100, RiskLevel::BLOCKED->toOutcome(), ['UNAUTHORIZED_RELATIONSHIP']), self::USER_ID, '1.2.3.4', 'UA', self::SESSION_ID);
        $this->assertSame(1, $this->eventCount('BLOCKED_ATTENDANCE'));

        $row = $this->pdo->query("SELECT severity, details FROM security_events WHERE event_type='BLOCKED_ATTENDANCE'")->fetch();
        $this->assertSame('CRITICAL', $row['severity']);
        $this->assertStringContainsString('UNAUTHORIZED_RELATIONSHIP', $row['details']);
    }

    private function eventCount(?string $type = null): int
    {
        if ($type === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) c FROM security_events')->fetch()['c'];
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) c FROM security_events WHERE event_type = ?');
        $stmt->execute([$type]);

        return (int) $stmt->fetch()['c'];
    }
}
