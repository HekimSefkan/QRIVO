<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\DTO\Auth\LoginRequestDTO;
use QRIVO\Application\Service\AuthService;
use QRIVO\Application\Service\LoginAttemptService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Domain\Security\DeviceContext;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\DeviceSessionRepository;
use QRIVO\Infrastructure\Repository\LoginAttemptRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Infrastructure\Repository\UserRepository;

/**
 * Device & session security integrated with authentication
 * (PROJECT_SPECIFICATION.md §6.13). SQLite in-memory; production uses MySQL.
 */
final class AuthServiceDeviceSessionTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE, email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL, first_name TEXT NOT NULL DEFAULT 'T', last_name TEXT NOT NULL DEFAULT 'U',
                is_active INTEGER NOT NULL DEFAULT 1, is_approved INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT
            );
            CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE);
            CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (user_id, role_id));
            CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, email_attempted TEXT NOT NULL,
                ip_address TEXT NOT NULL, user_agent TEXT, success INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
            );
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

        $this->insertUser();
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
     * @param array<string, mixed> $deviceConfig
     */
    private function authService(array $deviceConfig = []): AuthService
    {
        $config = new Config(QRIVO_ROOT);
        $ref = new \ReflectionProperty($config, 'data');
        $ref->setAccessible(true);
        $data = $ref->getValue($config);
        $data['auth'] = [
            'access_token_ttl'  => 3600,
            'refresh_token_ttl' => 2592000,
            'rate_limit' => ['max_by_ip' => 100, 'max_by_email' => 100, 'window_seconds' => 900],
        ];
        $data['security'] = ['device' => array_merge([
            'max_active_sessions'         => 5,
            'enforce_fingerprint_binding' => false,
            'idle_timeout_seconds'        => 0,
        ], $deviceConfig)];
        $ref->setValue($config, $data);

        $db  = $this->connection();
        $log = new SecurityLogService(
            $this->createMock(LoggerInterface::class),
            new SecurityEventRepository($db),
            new AuditLogRepository($db),
        );

        return new AuthService(
            $this->createMock(LoggerInterface::class),
            new UserRepository($db),
            new DeviceSessionRepository($db),
            new LoginAttemptService($this->createMock(LoggerInterface::class), new LoginAttemptRepository($db), $config),
            $log,
            $config,
        );
    }

    private function insertUser(string $email = 'student@x.test', string $password = 'Password123!'): void
    {
        $this->pdo->prepare(
            'INSERT INTO users (uuid, email, password_hash, created_at, updated_at) VALUES (?,?,?,?,?)'
        )->execute([
            bin2hex(random_bytes(8)) . '-u',
            $email,
            password_hash($password, PASSWORD_ARGON2ID),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
        ]);
    }

    private function dto(string $ip = '198.51.100.1', ?string $deviceId = 'device-A', ?string $deviceName = 'Sam iPhone'): LoginRequestDTO
    {
        return new LoginRequestDTO('student@x.test', 'Password123!', $ip, 'QRIVO-Mobile/1.0', $deviceId, $deviceName);
    }

    private function events(string $type): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) c FROM security_events WHERE event_type = ?');
        $stmt->execute([$type]);

        return (int) $stmt->fetch()['c'];
    }

    /** @return array<string, mixed> */
    private function lastSession(): array
    {
        return $this->pdo->query('SELECT * FROM device_sessions ORDER BY id DESC LIMIT 1')->fetch();
    }

    // ─── registration on login ──────────────────────────────────────────────

    public function test_login_persists_device_fingerprint_and_name(): void
    {
        $this->authService()->login($this->dto());

        $session = $this->lastSession();
        $this->assertNotEmpty($session['device_fingerprint']);
        $this->assertSame('Sam iPhone', $session['device_name']);
        // The fingerprint is a hash, not the raw device id.
        $this->assertNotSame('device-A', $session['device_fingerprint']);
        $this->assertSame(64, strlen($session['device_fingerprint']));
    }

    public function test_first_login_does_not_emit_new_device(): void
    {
        $this->authService()->login($this->dto());
        $this->assertSame(0, $this->events('NEW_DEVICE'));
    }

    public function test_login_from_a_second_device_emits_new_device(): void
    {
        $svc = $this->authService();
        $svc->login($this->dto(deviceId: 'device-A'));
        $svc->login($this->dto(deviceId: 'device-B'));

        $this->assertSame(1, $this->events('NEW_DEVICE'));
    }

    public function test_login_over_the_active_session_ceiling_is_suspicious(): void
    {
        $svc = $this->authService(['max_active_sessions' => 3]);
        for ($i = 0; $i < 4; $i++) {
            $svc->login($this->dto(deviceId: "device-{$i}"));
        }

        $this->assertGreaterThanOrEqual(1, $this->events('SUSPICIOUS_DEVICE'));
    }

    // ─── per-request enforcement ────────────────────────────────────────────

    public function test_validate_token_returns_the_request_fingerprint(): void
    {
        $login = $this->authService()->login($this->dto());
        $device = DeviceContext::fromRequest('198.51.100.1', 'QRIVO-Mobile/1.0', 'device-A');

        $context = $this->authService()->validateToken($login->accessToken, $device);

        $this->assertArrayHasKey('device_fingerprint', $context);
        $this->assertSame($device->fingerprint, $context['device_fingerprint']);
    }

    public function test_validate_token_without_device_context_still_works(): void
    {
        $login = $this->authService()->login($this->dto());

        $context = $this->authService()->validateToken($login->accessToken);

        $this->assertSame('student@x.test', $context['email']);
        $this->assertNull($context['device_fingerprint']);
    }

    public function test_validate_token_flags_a_device_mismatch(): void
    {
        $login = $this->authService()->login($this->dto(deviceId: 'device-A'));
        $other = DeviceContext::fromRequest('198.51.100.1', 'QRIVO-Mobile/1.0', 'stolen-device');

        // Not enforced: the call succeeds but the mismatch is logged.
        $this->authService()->validateToken($login->accessToken, $other);

        $this->assertSame(1, $this->events('SUSPICIOUS_DEVICE'));
    }

    public function test_validate_token_rejects_a_mismatch_when_binding_is_enforced(): void
    {
        $login = $this->authService(['enforce_fingerprint_binding' => true])->login($this->dto(deviceId: 'device-A'));
        $other = DeviceContext::fromRequest('198.51.100.1', 'QRIVO-Mobile/1.0', 'stolen-device');

        $this->expectException(UnauthorizedException::class);
        $this->authService(['enforce_fingerprint_binding' => true])->validateToken($login->accessToken, $other);
    }

    public function test_validate_token_enforces_the_idle_timeout(): void
    {
        $login = $this->authService()->login($this->dto());
        $this->pdo->exec("UPDATE device_sessions SET last_active_at = '2026-01-01 00:00:00'");

        $device = DeviceContext::fromRequest('198.51.100.1', 'QRIVO-Mobile/1.0', 'device-A');

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('inactivity');
        $this->authService(['idle_timeout_seconds' => 60])->validateToken($login->accessToken, $device);
    }

    // ─── refresh ────────────────────────────────────────────────────────────

    public function test_refresh_carries_the_device_fingerprint_onto_the_new_session(): void
    {
        $login = $this->authService()->login($this->dto(deviceId: 'device-A'));
        $before = $this->lastSession()['device_fingerprint'];

        $this->authService()->refresh($login->refreshToken, '198.51.100.1', 'QRIVO-Mobile/1.0', 'device-A', 'Sam iPhone');

        $after = $this->lastSession();
        $this->assertSame($before, $after['device_fingerprint']);
        $this->assertSame('Sam iPhone', $after['device_name']);
    }
}
