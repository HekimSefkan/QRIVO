<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\DTO\Auth\LoginRequestDTO;
use QRIVO\Application\Service\AuthService;
use QRIVO\Application\Service\LoginAttemptService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Exception\TooManyRequestsException;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\DeviceSessionRepository;
use QRIVO\Infrastructure\Repository\LoginAttemptRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Infrastructure\Repository\UserRepository;

/**
 * AuthService unit tests using SQLite in-memory database.
 *
 * These tests exercise the full authentication flow WITHOUT a live MySQL server.
 * SQLite is used only for the test schema; the production code uses MySQL.
 *
 * Covers:
 * - Successful authentication
 * - Invalid credentials (wrong password)
 * - User not found
 * - Inactive account
 * - Unapproved account
 * - Rate limiting
 * - Successful logout
 * - Logout with invalid token
 * - Successful token refresh
 * - Expired refresh token
 * - Revoked refresh token (token reuse detection)
 * - Token validation
 * - Invalid token validation
 */
final class AuthServiceTest extends TestCase
{
    private \PDO $pdo;
    private AuthService $authService;
    private LoginAttemptRepository $attemptRepo;

    protected function setUp(): void
    {
        $this->pdo = $this->buildSqliteDb();
        $this->authService = $this->buildAuthService(maxByIp: 20, maxByEmail: 10);
    }

    // ─── SQLite Setup ──────────────────────────────────────────────────────────

    private function buildSqliteDb(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $pdo->exec("
            CREATE TABLE users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid          TEXT NOT NULL UNIQUE,
                email         TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                first_name    TEXT NOT NULL,
                last_name     TEXT NOT NULL,
                is_active     INTEGER NOT NULL DEFAULT 0,
                is_approved   INTEGER NOT NULL DEFAULT 0,
                created_at    TEXT NOT NULL,
                updated_at    TEXT NOT NULL,
                deleted_at    TEXT
            );

            CREATE TABLE roles (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE
            );

            CREATE TABLE user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                PRIMARY KEY (user_id, role_id)
            );

            CREATE TABLE login_attempts (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id          INTEGER,
                email_attempted  TEXT NOT NULL,
                ip_address       TEXT NOT NULL,
                user_agent       TEXT,
                success          INTEGER NOT NULL DEFAULT 0,
                created_at       TEXT NOT NULL
            );

            CREATE TABLE device_sessions (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid                TEXT NOT NULL UNIQUE,
                user_id             INTEGER NOT NULL,
                device_fingerprint  TEXT,
                device_name         TEXT,
                ip_address          TEXT,
                user_agent          TEXT,
                access_token_hash   TEXT,
                refresh_token_hash  TEXT,
                expires_at          TEXT NOT NULL,
                last_active_at      TEXT,
                revoked_at          TEXT,
                created_at          TEXT NOT NULL,
                updated_at          TEXT NOT NULL
            );

            CREATE TABLE security_events (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type            TEXT NOT NULL,
                severity              TEXT NOT NULL,
                user_id               INTEGER,
                attendance_session_id INTEGER,
                ip_address            TEXT,
                user_agent            TEXT,
                details               TEXT,
                created_at            TEXT NOT NULL
            );

            CREATE TABLE audit_logs (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type    TEXT NOT NULL,
                actor_user_id INTEGER,
                target_entity TEXT NOT NULL,
                target_id     INTEGER,
                old_value     TEXT,
                new_value     TEXT,
                reason        TEXT,
                ip_address    TEXT,
                created_at    TEXT NOT NULL
            );
        ");

        return $pdo;
    }

    private function buildConnection(): Connection
    {
        // Inject the pre-built SQLite PDO via reflection
        $config     = new Config(QRIVO_ROOT);
        $connection = new Connection($config);

        $reflection = new \ReflectionClass($connection);
        $prop       = $reflection->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($connection, $this->pdo);

        return $connection;
    }

    private function buildAuthService(int $maxByIp = 20, int $maxByEmail = 10, int $window = 900): AuthService
    {
        $db     = $this->buildConnection();
        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->buildConfig($maxByIp, $maxByEmail, $window);

        $userRepo          = new UserRepository($db);
        $sessionRepo       = new DeviceSessionRepository($db);
        $this->attemptRepo = new LoginAttemptRepository($db);
        $securityEventRepo = new SecurityEventRepository($db);
        $auditLogRepo      = new AuditLogRepository($db);

        $securityLogService  = new SecurityLogService($logger, $securityEventRepo, $auditLogRepo);
        $loginAttemptService = new LoginAttemptService($logger, $this->attemptRepo, $config);

        return new AuthService($logger, $userRepo, $sessionRepo, $loginAttemptService, $securityLogService, $config);
    }

    private function buildConfig(int $maxByIp = 20, int $maxByEmail = 10, int $window = 900): Config
    {
        $config = new Config(QRIVO_ROOT);

        // Inject auth config values via reflection
        $reflection = new \ReflectionClass($config);
        $prop       = $reflection->getProperty('data');
        $prop->setAccessible(true);
        $data = $prop->getValue($config);
        $data['auth'] = [
            'access_token_ttl'  => 3600,
            'refresh_token_ttl' => 2592000,
            'rate_limit' => [
                'max_by_ip'      => $maxByIp,
                'max_by_email'   => $maxByEmail,
                'window_seconds' => $window,
            ],
        ];
        $prop->setValue($config, $data);

        return $config;
    }

    private function insertUser(
        string $email    = 'student@example.com',
        string $password = 'Password123!',
        bool   $active   = true,
        bool   $approved = true,
    ): int {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $uuid = bin2hex(random_bytes(8)) . '-' . bin2hex(random_bytes(4)) . '-4' . substr(bin2hex(random_bytes(2)), 1) . '-a' . substr(bin2hex(random_bytes(2)), 1) . '-' . bin2hex(random_bytes(6));
        $now  = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (uuid, email, password_hash, first_name, last_name, is_active, is_approved, created_at, updated_at)
             VALUES (:uuid, :email, :hash, :fn, :ln, :active, :approved, :now, :now)'
        );
        $stmt->execute([
            'uuid'     => $uuid,
            'email'    => $email,
            'hash'     => $hash,
            'fn'       => 'Test',
            'ln'       => 'User',
            'active'   => $active ? 1 : 0,
            'approved' => $approved ? 1 : 0,
            'now'      => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function makeLoginDTO(
        string $email    = 'student@example.com',
        string $password = 'Password123!',
        string $ip       = '127.0.0.1',
    ): LoginRequestDTO {
        return new LoginRequestDTO($email, $password, $ip, 'PHPUnit/Test');
    }

    // ─── Login Tests ───────────────────────────────────────────────────────────

    public function test_login_success_returns_tokens(): void
    {
        $this->insertUser();
        $dto    = $this->makeLoginDTO();
        $result = $this->authService->login($dto);

        $this->assertNotEmpty($result->accessToken);
        $this->assertNotEmpty($result->refreshToken);
        $this->assertSame('Bearer', $result->tokenType);
        $this->assertNotEmpty($result->expiresAt);
        $this->assertSame('student@example.com', $result->user['email']);
        // Security: password_hash must NOT be in the response
        $this->assertArrayNotHasKey('password_hash', $result->user);
        $this->assertArrayNotHasKey('password', $result->user);
    }

    public function test_login_success_stores_hashed_token_not_plaintext(): void
    {
        $this->insertUser();
        $result = $this->authService->login($this->makeLoginDTO());

        // Verify access token is not stored in plaintext
        $stmt = $this->pdo->query("SELECT access_token_hash, refresh_token_hash FROM device_sessions LIMIT 1");
        $row  = $stmt->fetch();

        $this->assertNotSame($result->accessToken, $row['access_token_hash']);
        $this->assertNotSame($result->refreshToken, $row['refresh_token_hash']);
        // The stored value must be a SHA-256 hash (64 hex chars)
        $this->assertSame(64, strlen($row['access_token_hash']));
        $this->assertSame(64, strlen($row['refresh_token_hash']));
    }

    public function test_login_success_records_successful_attempt(): void
    {
        $this->insertUser();
        $this->authService->login($this->makeLoginDTO());

        $stmt  = $this->pdo->query("SELECT success FROM login_attempts ORDER BY id DESC LIMIT 1");
        $row   = $stmt->fetch();
        $this->assertSame(1, (int) $row['success']);
    }

    public function test_login_wrong_password_throws_unauthorized(): void
    {
        $this->insertUser();
        $dto = $this->makeLoginDTO(password: 'WrongPassword!');

        $this->expectException(UnauthorizedException::class);
        $this->authService->login($dto);
    }

    public function test_login_user_not_found_throws_unauthorized(): void
    {
        $dto = $this->makeLoginDTO(email: 'nonexistent@example.com');

        $this->expectException(UnauthorizedException::class);
        $this->authService->login($dto);
    }

    public function test_login_wrong_password_does_not_reveal_email_exists(): void
    {
        $this->insertUser();
        $dto = $this->makeLoginDTO(password: 'WrongPassword!');

        try {
            $this->authService->login($dto);
            $this->fail('Expected UnauthorizedException');
        } catch (UnauthorizedException $e) {
            // Security: same message whether email exists or not
            $this->assertSame('Invalid credentials.', $e->getMessage());
        }
    }

    public function test_login_nonexistent_user_does_not_reveal_absence(): void
    {
        $dto = $this->makeLoginDTO(email: 'ghost@example.com');

        try {
            $this->authService->login($dto);
            $this->fail('Expected UnauthorizedException');
        } catch (UnauthorizedException $e) {
            $this->assertSame('Invalid credentials.', $e->getMessage());
        }
    }

    public function test_login_inactive_account_throws_unauthorized(): void
    {
        $this->insertUser(active: false, approved: true);
        $dto = $this->makeLoginDTO();

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Account is inactive.');
        $this->authService->login($dto);
    }

    public function test_login_unapproved_account_throws_unauthorized(): void
    {
        $this->insertUser(active: true, approved: false);
        $dto = $this->makeLoginDTO();

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Account is pending approval.');
        $this->authService->login($dto);
    }

    public function test_login_records_failed_attempt_on_wrong_password(): void
    {
        $this->insertUser();
        try {
            $this->authService->login($this->makeLoginDTO(password: 'wrong'));
        } catch (UnauthorizedException) {}

        $stmt = $this->pdo->query("SELECT success FROM login_attempts ORDER BY id DESC LIMIT 1");
        $row  = $stmt->fetch();
        $this->assertSame(0, (int) $row['success']);
    }

    // ─── Rate Limiting Tests ───────────────────────────────────────────────────

    public function test_login_rate_limited_by_ip_throws_too_many_requests(): void
    {
        // Build service with very low threshold (2 attempts per IP)
        $authService = $this->buildAuthService(maxByIp: 2, maxByEmail: 100, window: 900);

        // Pre-fill 2 failed attempts from same IP
        $now = date('Y-m-d H:i:s');
        $this->attemptRepo->create(['user_id' => null, 'email_attempted' => 'x@x.com', 'ip_address' => '10.0.0.1', 'user_agent' => 'Test', 'success' => 0, 'created_at' => $now]);
        $this->attemptRepo->create(['user_id' => null, 'email_attempted' => 'y@y.com', 'ip_address' => '10.0.0.1', 'user_agent' => 'Test', 'success' => 0, 'created_at' => $now]);

        $dto = new LoginRequestDTO('target@example.com', 'pass', '10.0.0.1', 'Test');

        $this->expectException(TooManyRequestsException::class);
        $authService->login($dto);
    }

    public function test_login_rate_limited_by_email_throws_too_many_requests(): void
    {
        // Build service with very low email threshold (2 attempts per email)
        $authService = $this->buildAuthService(maxByIp: 100, maxByEmail: 2, window: 900);

        $now = date('Y-m-d H:i:s');
        $this->attemptRepo->create(['user_id' => null, 'email_attempted' => 'victim@example.com', 'ip_address' => '1.1.1.1', 'user_agent' => 'T', 'success' => 0, 'created_at' => $now]);
        $this->attemptRepo->create(['user_id' => null, 'email_attempted' => 'victim@example.com', 'ip_address' => '2.2.2.2', 'user_agent' => 'T', 'success' => 0, 'created_at' => $now]);

        $dto = new LoginRequestDTO('victim@example.com', 'pass', '9.9.9.9', 'Test');

        $this->expectException(TooManyRequestsException::class);
        $authService->login($dto);
    }

    public function test_successful_attempts_do_not_count_toward_rate_limit(): void
    {
        $this->insertUser();
        $authService = $this->buildAuthService(maxByIp: 2, maxByEmail: 100);

        // Record 2 successful attempts (should NOT count)
        $now = date('Y-m-d H:i:s');
        $this->attemptRepo->create(['user_id' => 1, 'email_attempted' => 'student@example.com', 'ip_address' => '127.0.0.1', 'user_agent' => 'T', 'success' => 1, 'created_at' => $now]);
        $this->attemptRepo->create(['user_id' => 1, 'email_attempted' => 'student@example.com', 'ip_address' => '127.0.0.1', 'user_agent' => 'T', 'success' => 1, 'created_at' => $now]);

        // This should still work — successful attempts don't count toward limit
        $result = $authService->login($this->makeLoginDTO());
        $this->assertNotEmpty($result->accessToken);
    }

    // ─── Logout Tests ──────────────────────────────────────────────────────────

    public function test_logout_revokes_session(): void
    {
        $this->insertUser();
        $loginResult = $this->authService->login($this->makeLoginDTO());

        $this->authService->logout($loginResult->accessToken, '127.0.0.1');

        // Session should now be revoked
        $stmt = $this->pdo->query("SELECT revoked_at FROM device_sessions ORDER BY id DESC LIMIT 1");
        $row  = $stmt->fetch();
        $this->assertNotNull($row['revoked_at']);
    }

    public function test_logout_with_invalid_token_does_not_throw(): void
    {
        $this->authService->logout('invalid-token-that-does-not-exist', '127.0.0.1');
        $this->assertTrue(true); // Should not throw
    }

    public function test_token_is_invalid_after_logout(): void
    {
        $this->insertUser();
        $loginResult = $this->authService->login($this->makeLoginDTO());
        $this->authService->logout($loginResult->accessToken, '127.0.0.1');

        $this->expectException(UnauthorizedException::class);
        $this->authService->validateToken($loginResult->accessToken);
    }

    // ─── Token Refresh Tests ───────────────────────────────────────────────────

    public function test_refresh_returns_new_tokens(): void
    {
        $this->insertUser();
        $loginResult   = $this->authService->login($this->makeLoginDTO());
        $refreshResult = $this->authService->refresh($loginResult->refreshToken, '127.0.0.1', 'PHPUnit/Test');

        $this->assertNotEmpty($refreshResult->accessToken);
        $this->assertNotEmpty($refreshResult->refreshToken);
        // New tokens must differ from old ones
        $this->assertNotSame($loginResult->accessToken, $refreshResult->accessToken);
        $this->assertNotSame($loginResult->refreshToken, $refreshResult->refreshToken);
    }

    public function test_refresh_rotates_token_old_token_invalid(): void
    {
        $this->insertUser();
        $loginResult = $this->authService->login($this->makeLoginDTO());
        $this->authService->refresh($loginResult->refreshToken, '127.0.0.1', 'PHPUnit/Test');

        // Old refresh token must now be invalid (revoked)
        $this->expectException(UnauthorizedException::class);
        $this->authService->refresh($loginResult->refreshToken, '127.0.0.1', 'PHPUnit/Test');
    }

    public function test_refresh_with_revoked_token_throws_unauthorized(): void
    {
        $this->insertUser();
        $loginResult = $this->authService->login($this->makeLoginDTO());
        // Logout revokes the session
        $this->authService->logout($loginResult->accessToken, '127.0.0.1');

        $this->expectException(UnauthorizedException::class);
        $this->authService->refresh($loginResult->refreshToken, '127.0.0.1', 'PHPUnit/Test');
    }

    public function test_refresh_with_invalid_token_throws_unauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->authService->refresh('completely-invalid-token', '127.0.0.1', 'PHPUnit/Test');
    }

    public function test_refresh_with_expired_session_throws_unauthorized(): void
    {
        $this->insertUser();
        $loginResult = $this->authService->login($this->makeLoginDTO());

        // Manually expire the session in the DB
        $this->pdo->exec("UPDATE device_sessions SET expires_at = '2000-01-01 00:00:00'");

        $this->expectException(UnauthorizedException::class);
        $this->authService->refresh($loginResult->refreshToken, '127.0.0.1', 'PHPUnit/Test');
    }

    // ─── Token Validation Tests ────────────────────────────────────────────────

    public function test_validate_token_returns_user_context(): void
    {
        $this->insertUser();
        $loginResult = $this->authService->login($this->makeLoginDTO());
        $context     = $this->authService->validateToken($loginResult->accessToken);

        $this->assertSame('student@example.com', $context['email']);
        $this->assertIsInt($context['user_id']);
        $this->assertIsArray($context['roles']);
        $this->assertArrayHasKey('session_id', $context);
    }

    public function test_validate_token_with_invalid_token_throws_unauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->authService->validateToken('not-a-valid-token');
    }

    public function test_validate_token_with_expired_session_throws_unauthorized(): void
    {
        $this->insertUser();
        $loginResult = $this->authService->login($this->makeLoginDTO());

        // Expire the session
        $this->pdo->exec("UPDATE device_sessions SET expires_at = '2000-01-01 00:00:00'");

        // DeviceSessionRepository.findByAccessTokenHash already filters expired sessions
        $this->expectException(UnauthorizedException::class);
        $this->authService->validateToken($loginResult->accessToken);
    }

    public function test_validate_token_context_does_not_include_password_hash(): void
    {
        $this->insertUser();
        $loginResult = $this->authService->login($this->makeLoginDTO());
        $context     = $this->authService->validateToken($loginResult->accessToken);

        $this->assertArrayNotHasKey('password_hash', $context);
        $this->assertArrayNotHasKey('password', $context);
    }

    // ─── Security: Token Hashing ───────────────────────────────────────────────

    public function test_hash_token_produces_sha256_hex(): void
    {
        $rawToken = bin2hex(random_bytes(64));
        $hash     = $this->authService->hashToken($rawToken);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertNotSame($rawToken, $hash);
    }

    public function test_hash_token_is_deterministic(): void
    {
        $rawToken = 'consistent-token-value';
        $this->assertSame(
            $this->authService->hashToken($rawToken),
            $this->authService->hashToken($rawToken)
        );
    }

    // ─── User entity safety ────────────────────────────────────────────────────

    public function test_login_response_user_does_not_expose_password_hash(): void
    {
        $this->insertUser();
        $result = $this->authService->login($this->makeLoginDTO());

        $this->assertArrayNotHasKey('password_hash', $result->user);
        $this->assertArrayNotHasKey('is_active', $result->user);
        $this->assertArrayNotHasKey('is_approved', $result->user);
        $this->assertArrayNotHasKey('deleted_at', $result->user);
    }

    // ─── Audit trail for authentication events (SECURITY_RULES.md §11) ──────────

    public function test_successful_login_is_audited(): void
    {
        $this->insertUser();
        $this->authService->login($this->makeLoginDTO());

        $row = $this->pdo->query("SELECT * FROM audit_logs WHERE event_type = 'LOGIN_SUCCESS' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('user', $row['target_entity']);
        $this->assertNotNull($row['actor_user_id']);
        $this->assertNotNull($row['created_at']);
    }

    public function test_logout_is_audited(): void
    {
        $this->insertUser();
        $login = $this->authService->login($this->makeLoginDTO());
        $this->authService->logout($login->accessToken, '127.0.0.1');

        $row = $this->pdo->query("SELECT * FROM audit_logs WHERE event_type = 'LOGOUT' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('device_session', $row['target_entity']);
    }

    public function test_token_refresh_is_audited(): void
    {
        $this->insertUser();
        $login = $this->authService->login($this->makeLoginDTO());
        $this->authService->refresh($login->refreshToken, '127.0.0.1', 'PHPUnit/Test');

        $row = $this->pdo->query("SELECT * FROM audit_logs WHERE event_type = 'TOKEN_REFRESHED' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('device_session', $row['target_entity']);
        $this->assertSame('refresh token rotation', $row['reason']);
    }

    public function test_no_raw_token_is_ever_written_to_audit_or_security_tables(): void
    {
        $this->insertUser();
        $login = $this->authService->login($this->makeLoginDTO());
        // a failed login (security event) + a refresh (audit) for good measure
        try {
            $this->authService->login($this->makeLoginDTO(password: 'wrong'));
        } catch (UnauthorizedException) {}
        $refresh = $this->authService->refresh($login->refreshToken, '127.0.0.1', 'PHPUnit/Test');

        $blobs = array_merge(
            array_column($this->pdo->query('SELECT details FROM security_events')->fetchAll(), 'details'),
            array_column($this->pdo->query('SELECT old_value FROM audit_logs')->fetchAll(), 'old_value'),
            array_column($this->pdo->query('SELECT new_value FROM audit_logs')->fetchAll(), 'new_value'),
        );
        $haystack = implode("\n", array_filter($blobs));

        foreach ([$login->accessToken, $login->refreshToken, $refresh->accessToken, $refresh->refreshToken, 'Password123!'] as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
    }
}
