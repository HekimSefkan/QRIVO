<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Controller;

use PHPUnit\Framework\TestCase;
use QRIVO\Domain\Security\DeviceContext;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Device/session security through the real HTTP stack (Router → BaseController →
 * AuthService → DeviceSessionService). PROJECT_SPECIFICATION.md §6.13.
 */
final class DeviceSessionRoutesTest extends TestCase
{
    use AcademicSchemaTrait;

    private const UA = 'QRIVO-Mobile/1.0';
    private const IP = '203.0.113.44';

    private Router $router;
    private Connection $db;
    private Logger $logger;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo    = $this->buildAcademicDb();
        $this->db     = $this->buildConnection();
        $this->logger = new Logger(new Config(QRIVO_ROOT));
        $this->router = new Router(QRIVO_ROOT);
        $this->userId = $this->makeUser('device@x.test', ['STUDENT']);
    }

    private function config(bool $enforceBinding = false, int $idleTimeout = 0): Config
    {
        $config = new Config(QRIVO_ROOT);
        $ref = new \ReflectionProperty($config, 'data');
        $ref->setAccessible(true);
        $data = $ref->getValue($config);
        $data['security'] = ['device' => [
            'max_active_sessions'         => 5,
            'enforce_fingerprint_binding' => $enforceBinding,
            'idle_timeout_seconds'        => $idleTimeout,
        ]];
        $ref->setValue($config, $data);

        return $config;
    }

    private function fingerprintFor(?string $deviceId): ?string
    {
        return DeviceContext::fromRequest(self::IP, self::UA, $deviceId)->fingerprint;
    }

    /** Insert an access token bound to a device fingerprint; return the raw token. */
    private function issueBoundToken(?string $sessionFingerprint): string
    {
        $raw = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO device_sessions (uuid, user_id, device_fingerprint, ip_address, user_agent, access_token_hash, refresh_token_hash, expires_at, last_active_at, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            bin2hex(random_bytes(8)) . '-s',
            $this->userId,
            $sessionFingerprint,
            self::IP,
            self::UA,
            hash('sha256', $raw),
            hash('sha256', $raw . '-r'),
            date('Y-m-d H:i:s', time() + 3600),
            $now,
            $now,
            $now,
        ]);

        return $raw;
    }

    private function me(string $token, ?string $deviceId, ?Config $config = null): JsonResponse
    {
        $headers = ['user-agent' => self::UA, 'authorization' => 'Bearer ' . $token];
        if ($deviceId !== null) {
            $headers['x-device-id'] = $deviceId;
        }

        return $this->router->dispatch(
            new Request('GET', '/api/v1/auth/me', [], [], $headers, ['REMOTE_ADDR' => self::IP]),
            $this->db,
            $this->logger,
            $config ?? $this->config(),
        );
    }

    private function suspiciousEvents(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) c FROM security_events WHERE event_type='SUSPICIOUS_DEVICE'")->fetch()['c'];
    }

    // ─── tests ──────────────────────────────────────────────────────────────

    public function test_matching_device_passes_with_no_event(): void
    {
        $token = $this->issueBoundToken($this->fingerprintFor('device-A'));

        $this->assertSame(200, $this->me($token, 'device-A')->getStatusCode());
        $this->assertSame(0, $this->suspiciousEvents());
    }

    public function test_mismatched_device_is_logged_but_allowed_by_default(): void
    {
        $token = $this->issueBoundToken($this->fingerprintFor('device-A'));

        $this->assertSame(200, $this->me($token, 'device-B')->getStatusCode());
        $this->assertSame(1, $this->suspiciousEvents());
    }

    public function test_mismatched_device_is_rejected_when_binding_is_enforced(): void
    {
        $token = $this->issueBoundToken($this->fingerprintFor('device-A'));

        $response = $this->me($token, 'device-B', $this->config(enforceBinding: true));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(1, $this->suspiciousEvents());
    }

    public function test_request_without_a_device_id_is_not_penalised(): void
    {
        $token = $this->issueBoundToken($this->fingerprintFor('device-A'));

        // No X-Device-Id header → fingerprint derived from UA only; still a
        // mismatch against the id-derived one, logged but never fatal here.
        $this->assertSame(200, $this->me($token, null)->getStatusCode());
    }

    public function test_session_without_a_stored_fingerprint_is_unaffected(): void
    {
        $token = $this->issueBoundToken(null);

        $this->assertSame(200, $this->me($token, 'device-A')->getStatusCode());
        $this->assertSame(0, $this->suspiciousEvents());
    }

    public function test_activity_is_recorded_on_the_session(): void
    {
        $token = $this->issueBoundToken($this->fingerprintFor('device-A'));
        $this->pdo->exec("UPDATE device_sessions SET last_active_at = '2026-01-01 00:00:00'");

        $this->me($token, 'device-A');

        $row = $this->pdo->query('SELECT last_active_at FROM device_sessions LIMIT 1')->fetch();
        $this->assertNotSame('2026-01-01 00:00:00', $row['last_active_at']);
    }
}
