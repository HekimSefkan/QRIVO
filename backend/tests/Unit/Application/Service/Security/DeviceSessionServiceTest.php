<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Security;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Security\DeviceSessionService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Domain\Security\DeviceContext;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\DeviceSessionRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;

/**
 * Device & session security — PROJECT_SPECIFICATION.md §6.13.
 *
 * SQLite in-memory; production uses MySQL. Covers device registration, new-device
 * and multi-device detection, fingerprint binding (log-only vs enforced), idle
 * timeout, IP-change observation, and the attendance risk signals.
 */
final class DeviceSessionServiceTest extends TestCase
{
    private \PDO $pdo;
    private DeviceSessionRepository $sessions;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE device_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL,
                device_fingerprint TEXT, device_name TEXT, ip_address TEXT, user_agent TEXT,
                access_token_hash TEXT, refresh_token_hash TEXT, expires_at TEXT NOT NULL,
                last_active_at TEXT, revoked_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            );
            CREATE TABLE security_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, severity TEXT NOT NULL,
                user_id INTEGER, attendance_session_id INTEGER, ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT NOT NULL
            );
            CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, actor_user_id INTEGER,
                target_entity TEXT NOT NULL, target_id INTEGER, old_value TEXT, new_value TEXT, reason TEXT, ip_address TEXT, created_at TEXT NOT NULL
            );
        SQL);

        $this->sessions = new DeviceSessionRepository($this->connection());
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
     * @param array{max_active_sessions?:int, enforce_fingerprint_binding?:bool, idle_timeout_seconds?:int} $device
     */
    private function service(array $device = []): DeviceSessionService
    {
        $config = new Config(QRIVO_ROOT);
        $ref = new \ReflectionProperty($config, 'data');
        $ref->setAccessible(true);
        $data = $ref->getValue($config);
        $data['security'] = ['device' => array_merge([
            'max_active_sessions'          => 5,
            'enforce_fingerprint_binding'  => false,
            'idle_timeout_seconds'         => 0,
        ], $device)];
        $ref->setValue($config, $data);

        $log = new SecurityLogService(
            $this->createMock(LoggerInterface::class),
            new SecurityEventRepository($this->connection()),
            new AuditLogRepository($this->connection()),
        );

        return new DeviceSessionService($this->createMock(LoggerInterface::class), $this->sessions, $log, $config);
    }

    private function insertSession(
        int $userId,
        ?string $fingerprint = null,
        string $expiresAt = '2099-01-01 00:00:00',
        ?string $revokedAt = null,
        ?string $ip = '10.0.0.1',
        ?string $lastActive = '2026-01-01 00:00:00',
    ): int {
        $now = '2026-01-01 00:00:00';
        $this->pdo->prepare(
            'INSERT INTO device_sessions (uuid, user_id, device_fingerprint, ip_address, expires_at, revoked_at, last_active_at, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([bin2hex(random_bytes(6)), $userId, $fingerprint, $ip, $expiresAt, $revokedAt, $lastActive, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    private function events(?string $type = null): int
    {
        if ($type === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) c FROM security_events')->fetch()['c'];
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) c FROM security_events WHERE event_type = ?');
        $stmt->execute([$type]);

        return (int) $stmt->fetch()['c'];
    }

    private function device(?string $fingerprintSeed = 'PHPUnit-UA', ?string $ip = '10.0.0.1'): DeviceContext
    {
        return DeviceContext::fromRequest($ip, $fingerprintSeed);
    }

    // ─── registration ───────────────────────────────────────────────────────

    public function test_first_ever_login_is_not_flagged_as_new_device(): void
    {
        $cols = $this->service()->registerSession(1, $this->device('ua-1'));

        $this->assertNotNull($cols['device_fingerprint']);
        $this->assertSame(0, $this->events('NEW_DEVICE'));
    }

    public function test_login_from_an_unseen_fingerprint_records_new_device(): void
    {
        $this->insertSession(1, DeviceContext::fromRequest(null, 'ua-old')->fingerprint);

        $this->service()->registerSession(1, $this->device('ua-new'));

        $this->assertSame(1, $this->events('NEW_DEVICE'));
    }

    public function test_login_from_a_known_fingerprint_is_not_flagged(): void
    {
        $known = DeviceContext::fromRequest(null, 'ua-same')->fingerprint;
        $this->insertSession(1, $known);

        $this->service()->registerSession(1, $this->device('ua-same'));

        $this->assertSame(0, $this->events('NEW_DEVICE'));
    }

    public function test_new_device_is_not_flagged_when_fingerprint_is_unknown(): void
    {
        $this->insertSession(1, 'some-fp');

        // No UA and no device id → null fingerprint → cannot assert "new".
        $this->service()->registerSession(1, DeviceContext::fromRequest('10.0.0.9', null));

        $this->assertSame(0, $this->events('NEW_DEVICE'));
    }

    public function test_exceeding_the_active_session_ceiling_is_suspicious(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertSession(1, "fp-{$i}");
        }

        $this->service(['max_active_sessions' => 5])->registerSession(1, $this->device('ua-6'));

        $this->assertSame(1, $this->events('SUSPICIOUS_DEVICE'));
        $row = $this->pdo->query("SELECT details FROM security_events WHERE event_type='SUSPICIOUS_DEVICE'")->fetch();
        $this->assertStringContainsString('multiple_active_sessions', $row['details']);
    }

    public function test_revoked_and_expired_sessions_do_not_count_toward_the_ceiling(): void
    {
        $this->insertSession(1, 'fp-a', revokedAt: '2026-01-02 00:00:00');
        $this->insertSession(1, 'fp-b', expiresAt: '2000-01-01 00:00:00');

        $this->service(['max_active_sessions' => 2])->registerSession(1, $this->device('ua-x'));

        $this->assertSame(0, $this->events('SUSPICIOUS_DEVICE'));
    }

    // ─── per-request enforcement ────────────────────────────────────────────

    public function test_matching_fingerprint_touches_activity_without_an_event(): void
    {
        $fp = DeviceContext::fromRequest(null, 'ua-bound')->fingerprint;
        $id = $this->insertSession(1, $fp, lastActive: '2026-01-01 00:00:00');

        $this->service()->assertSessionUsable($this->sessions->findRow($id), $this->device('ua-bound'));

        $this->assertSame(0, $this->events());
        $this->assertNotSame('2026-01-01 00:00:00', $this->sessions->findRow($id)['last_active_at']);
    }

    public function test_fingerprint_mismatch_is_logged_but_not_rejected_by_default(): void
    {
        $id = $this->insertSession(1, DeviceContext::fromRequest(null, 'ua-bound')->fingerprint);

        $this->service()->assertSessionUsable($this->sessions->findRow($id), $this->device('ua-attacker'));

        $this->assertSame(1, $this->events('SUSPICIOUS_DEVICE'));
        $row = $this->pdo->query("SELECT severity, details FROM security_events LIMIT 1")->fetch();
        $this->assertSame('HIGH', $row['severity']);
        $this->assertStringContainsString('fingerprint_mismatch', $row['details']);
    }

    public function test_fingerprint_mismatch_is_rejected_when_binding_is_enforced(): void
    {
        $id = $this->insertSession(1, DeviceContext::fromRequest(null, 'ua-bound')->fingerprint);

        $threw = false;
        try {
            $this->service(['enforce_fingerprint_binding' => true])
                ->assertSessionUsable($this->sessions->findRow($id), $this->device('ua-attacker'));
        } catch (UnauthorizedException $e) {
            $threw = true;
            $this->assertStringContainsString('different device', $e->getMessage());
        }

        $this->assertTrue($threw, 'expected the request to be rejected');
        $this->assertSame(1, $this->events('SUSPICIOUS_DEVICE'));
    }

    public function test_idle_timeout_expires_the_session(): void
    {
        $id = $this->insertSession(1, null, lastActive: date('Y-m-d H:i:s', time() - 7200));

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('inactivity');
        $this->service(['idle_timeout_seconds' => 3600])
            ->assertSessionUsable($this->sessions->findRow($id), $this->device('ua-x'));
    }

    public function test_recent_activity_passes_the_idle_timeout(): void
    {
        $id = $this->insertSession(1, null, lastActive: date('Y-m-d H:i:s', time() - 60));

        $this->service(['idle_timeout_seconds' => 3600])
            ->assertSessionUsable($this->sessions->findRow($id), $this->device('ua-x'));

        $this->assertTrue(true);
    }

    public function test_ip_change_is_flagged_once_then_stored(): void
    {
        $id = $this->insertSession(1, null, ip: '10.0.0.1');
        $svc = $this->service();

        $svc->assertSessionUsable($this->sessions->findRow($id), $this->device('ua-x', '203.0.113.7'));
        $svc->assertSessionUsable($this->sessions->findRow($id), $this->device('ua-x', '203.0.113.7'));

        $this->assertSame(1, $this->events('SUSPICIOUS_DEVICE'));
        $this->assertSame('203.0.113.7', $this->sessions->findRow($id)['ip_address']);
    }

    // ─── attendance risk signals ────────────────────────────────────────────

    public function test_risk_signals_flag_a_device_mismatch(): void
    {
        $id = $this->insertSession(7, DeviceContext::fromRequest(null, 'ua-bound')->fingerprint);

        $signals = $this->service()->attendanceRiskSignals($id, DeviceContext::fromRequest(null, 'ua-other')->fingerprint);

        $this->assertContains('DEVICE_MISMATCH', $signals);
    }

    public function test_risk_signals_flag_multiple_active_devices(): void
    {
        $bound = DeviceContext::fromRequest(null, 'ua-bound')->fingerprint;
        $id = $this->insertSession(7, $bound);
        for ($i = 0; $i < 5; $i++) {
            $this->insertSession(7, "fp-extra-{$i}");
        }

        $signals = $this->service(['max_active_sessions' => 5])->attendanceRiskSignals($id, $bound);

        $this->assertContains('MULTIPLE_ACTIVE_DEVICES', $signals);
        $this->assertNotContains('DEVICE_MISMATCH', $signals);
    }

    public function test_risk_signals_flag_a_new_device(): void
    {
        $this->insertSession(7, 'original-device-fp');           // id 1 — earlier session
        $new = $this->insertSession(7, 'brand-new-fp');          // id 2 — first time this fp appears

        $signals = $this->service()->attendanceRiskSignals($new, 'brand-new-fp');

        $this->assertContains('NEW_DEVICE', $signals);
    }

    public function test_risk_signals_empty_for_a_single_stable_device(): void
    {
        $fp = 'stable-fp';
        $id = $this->insertSession(7, $fp);

        $this->assertSame([], $this->service()->attendanceRiskSignals($id, $fp));
    }

    public function test_risk_signals_empty_when_session_missing(): void
    {
        $this->assertSame([], $this->service()->attendanceRiskSignals(0, 'fp'));
        $this->assertSame([], $this->service()->attendanceRiskSignals(999, 'fp'));
    }
}
