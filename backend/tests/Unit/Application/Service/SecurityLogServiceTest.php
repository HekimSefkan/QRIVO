<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Security\LogSanitizer;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;

/**
 * The single choke point for security events and audit logs
 * (PROJECT_SPECIFICATION.md §6.15, SECURITY_RULES.md §9 / §10 / §11).
 *
 * Focus: nothing sensitive reaches the database, and recording never crashes the
 * caller.
 */
final class SecurityLogServiceTest extends TestCase
{
    private \PDO $pdo;
    private SecurityLogService $service;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE security_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, severity TEXT NOT NULL,
                user_id INTEGER, attendance_session_id INTEGER, ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT NOT NULL
            );
            CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, actor_user_id INTEGER,
                target_entity TEXT NOT NULL, target_id INTEGER, old_value TEXT, new_value TEXT, reason TEXT, ip_address TEXT, created_at TEXT NOT NULL
            );
        SQL);

        $this->service = new SecurityLogService(
            $this->createMock(LoggerInterface::class),
            new SecurityEventRepository($this->connection()),
            new AuditLogRepository($this->connection()),
        );
    }

    private function connection(): Connection
    {
        $connection = new Connection(new Config(QRIVO_ROOT));
        $ref = new \ReflectionProperty($connection, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($connection, $this->pdo);

        return $connection;
    }

    private function lastEvent(): array
    {
        return $this->pdo->query('SELECT * FROM security_events ORDER BY id DESC LIMIT 1')->fetch();
    }

    private function lastAudit(): array
    {
        return $this->pdo->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch();
    }

    // ─── security events ────────────────────────────────────────────────────

    public function test_security_event_details_are_redacted_before_persistence(): void
    {
        $this->service->recordSecurityEvent(
            SecurityEventType::LOGIN_FAILURE,
            'MEDIUM',
            7,
            '203.0.113.4',
            'PHPUnit',
            [
                'email'         => 'victim@example.com',
                'password'      => 'super-secret-pw',
                'access_token'  => str_repeat('a', 128),
                'reason'        => 'invalid_credentials',
                'nested'        => ['refresh_token' => 'r-r-r'],
            ],
        );

        $details = $this->lastEvent()['details'];

        $this->assertStringNotContainsString('super-secret-pw', $details);
        $this->assertStringNotContainsString('r-r-r', $details);
        $this->assertStringNotContainsString(str_repeat('a', 128), $details);
        // Necessary, non-sensitive context is kept.
        $this->assertStringContainsString('victim@example.com', $details);
        $this->assertStringContainsString('invalid_credentials', $details);

        $decoded = json_decode($details, true);
        $this->assertSame(LogSanitizer::REDACTED, $decoded['password']);
        $this->assertSame(LogSanitizer::REDACTED, $decoded['nested']['refresh_token']);
    }

    public function test_empty_details_persist_as_null(): void
    {
        $this->service->recordSecurityEvent(SecurityEventType::NEW_DEVICE, 'LOW', 1);
        $this->assertNull($this->lastEvent()['details']);
    }

    public function test_security_event_recording_is_fail_safe(): void
    {
        $this->pdo->exec('DROP TABLE security_events');

        // Must not throw even though the insert will fail.
        $this->service->recordSecurityEvent(SecurityEventType::LOGIN_FAILURE, 'HIGH', 1);
        $this->addToAssertionCount(1);
    }

    // ─── audit logs ─────────────────────────────────────────────────────────

    public function test_audit_old_and_new_values_are_redacted(): void
    {
        $this->service->recordAuditLog(
            'USER_UPDATED',
            9,
            'user',
            3,
            ['email' => 'a@x.test', 'password_hash' => '$argon2id$v=19$m=65536...'],
            ['email' => 'b@x.test', 'password_hash' => '$argon2id$v=19$m=65536...NEW'],
            'profile edit',
            '203.0.113.9',
        );

        $row = $this->lastAudit();
        $this->assertStringNotContainsString('argon2id', $row['old_value']);
        $this->assertStringNotContainsString('argon2id', $row['new_value']);
        $this->assertSame('profile edit', $row['reason']);
        $this->assertStringContainsString('a@x.test', $row['old_value']);
        $this->assertStringContainsString('b@x.test', $row['new_value']);
    }

    public function test_write_audit_log_returns_id_and_redacts(): void
    {
        $id = $this->service->writeAuditLog(
            'ATTENDANCE_STATUS_CHANGED',
            5,
            'attendance_record',
            11,
            ['status' => 'WAITING'],
            ['status' => 'PRESENT', 'token' => 'leak-me'],
            'manual correction',
            '198.51.100.1',
        );

        $this->assertGreaterThan(0, $id);
        $row = $this->lastAudit();
        $this->assertStringNotContainsString('leak-me', $row['new_value']);
        $this->assertStringContainsString('PRESENT', $row['new_value']);
        $this->assertStringContainsString('WAITING', $row['old_value']);
    }

    public function test_clean_audit_payload_passes_through_unchanged(): void
    {
        $this->service->recordAuditLog(
            'SCHOOL_CREATED',
            1,
            'school',
            2,
            null,
            ['name' => 'Engineering', 'code' => 'ENG'],
            null,
            '203.0.113.1',
        );

        $decoded = json_decode($this->lastAudit()['new_value'], true);
        $this->assertSame(['name' => 'Engineering', 'code' => 'ENG'], $decoded);
    }
}
