<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\LoginAttemptService;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Infrastructure\Repository\LoginAttemptRepository;

/**
 * LoginAttemptService unit tests.
 *
 * Uses SQLite in-memory for the `login_attempts` table.
 */
final class LoginAttemptServiceTest extends TestCase
{
    private \PDO $pdo;
    private LoginAttemptRepository $repo;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE login_attempts (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id          INTEGER,
                email_attempted  TEXT NOT NULL,
                ip_address       TEXT NOT NULL,
                user_agent       TEXT,
                success          INTEGER NOT NULL DEFAULT 0,
                created_at       TEXT NOT NULL
            );
        ");

        $db           = $this->buildConnection();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->repo   = new LoginAttemptRepository($db);
    }

    private function buildConnection(): Connection
    {
        $config     = new Config(QRIVO_ROOT);
        $connection = new Connection($config);

        $reflection = new \ReflectionClass($connection);
        $prop       = $reflection->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($connection, $this->pdo);

        return $connection;
    }

    private function buildService(int $maxByIp = 5, int $maxByEmail = 3, int $window = 900): LoginAttemptService
    {
        $db     = $this->buildConnection();
        $repo   = new LoginAttemptRepository($db);
        $config = new Config(QRIVO_ROOT);

        // Inject rate limit config
        $reflection = new \ReflectionClass($config);
        $prop       = $reflection->getProperty('data');
        $prop->setAccessible(true);
        $data = $prop->getValue($config);
        $data['auth'] = [
            'rate_limit' => [
                'max_by_ip'      => $maxByIp,
                'max_by_email'   => $maxByEmail,
                'window_seconds' => $window,
            ],
        ];
        $prop->setValue($config, $data);

        return new LoginAttemptService($this->logger, $repo, $config);
    }

    private function insertAttempt(string $ip, string $email, bool $success = false, int $secondsAgo = 60): void
    {
        $createdAt = date('Y-m-d H:i:s', time() - $secondsAgo);
        $this->repo->create([
            'user_id'         => null,
            'email_attempted' => $email,
            'ip_address'      => $ip,
            'user_agent'      => 'PHPUnit',
            'success'         => $success ? 1 : 0,
            'created_at'      => $createdAt,
        ]);
    }

    public function test_not_rate_limited_when_no_attempts(): void
    {
        $service = $this->buildService();
        $this->assertFalse($service->isRateLimited('1.2.3.4', 'user@test.com'));
    }

    public function test_rate_limited_by_ip_when_threshold_reached(): void
    {
        $service = $this->buildService(maxByIp: 3);

        $this->insertAttempt('10.0.0.1', 'a@test.com');
        $this->insertAttempt('10.0.0.1', 'b@test.com');
        $this->insertAttempt('10.0.0.1', 'c@test.com');

        $this->assertTrue($service->isRateLimited('10.0.0.1', 'new@test.com'));
    }

    public function test_not_rate_limited_below_ip_threshold(): void
    {
        $service = $this->buildService(maxByIp: 3);

        $this->insertAttempt('10.0.0.1', 'a@test.com');
        $this->insertAttempt('10.0.0.1', 'b@test.com');
        // Only 2 of 3 used — should not be rate-limited yet

        $this->assertFalse($service->isRateLimited('10.0.0.1', 'new@test.com'));
    }

    public function test_rate_limited_by_email_when_threshold_reached(): void
    {
        $service = $this->buildService(maxByIp: 100, maxByEmail: 2);

        $this->insertAttempt('1.1.1.1', 'victim@test.com');
        $this->insertAttempt('2.2.2.2', 'victim@test.com');

        $this->assertTrue($service->isRateLimited('9.9.9.9', 'victim@test.com'));
    }

    public function test_old_attempts_outside_window_do_not_count(): void
    {
        $service = $this->buildService(maxByIp: 2, window: 300); // 5-minute window

        // Attempts from 10 minutes ago — outside the 5-minute window
        $this->insertAttempt('10.0.0.1', 'a@test.com', false, 601);
        $this->insertAttempt('10.0.0.1', 'b@test.com', false, 601);

        $this->assertFalse($service->isRateLimited('10.0.0.1', 'new@test.com'));
    }

    public function test_successful_attempts_do_not_count_toward_rate_limit(): void
    {
        $service = $this->buildService(maxByIp: 2);

        // 2 successful attempts — should NOT trigger rate limit
        $this->insertAttempt('10.0.0.1', 'a@test.com', true);
        $this->insertAttempt('10.0.0.1', 'b@test.com', true);

        $this->assertFalse($service->isRateLimited('10.0.0.1', 'new@test.com'));
    }

    public function test_record_creates_attempt_record(): void
    {
        $service = $this->buildService();
        $service->record(1, 'user@test.com', '1.2.3.4', 'Mozilla/5.0', false);

        $stmt = $this->pdo->query("SELECT * FROM login_attempts LIMIT 1");
        $row  = $stmt->fetch();

        $this->assertSame('user@test.com', $row['email_attempted']);
        $this->assertSame('1.2.3.4', $row['ip_address']);
        $this->assertSame(0, (int) $row['success']);
        $this->assertSame(1, (int) $row['user_id']);
    }

    public function test_record_with_null_user_id_for_unknown_user(): void
    {
        $service = $this->buildService();
        $service->record(null, 'ghost@test.com', '1.2.3.4', 'PHPUnit', false);

        $stmt = $this->pdo->query("SELECT user_id FROM login_attempts LIMIT 1");
        $row  = $stmt->fetch();

        $this->assertNull($row['user_id']);
    }

    public function test_record_does_not_throw_on_repository_failure(): void
    {
        // Simulate a broken repo by closing the connection
        $this->pdo->exec("DROP TABLE login_attempts");

        $service = $this->buildService();
        // Should not throw — attempt recording must not block auth flow
        $service->record(null, 'x@test.com', '1.2.3.4', 'PHPUnit', false);
        $this->assertTrue(true);
    }
}
