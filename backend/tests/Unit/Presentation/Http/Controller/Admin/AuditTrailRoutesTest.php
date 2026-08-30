<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Controller\Admin;

use PHPUnit\Framework\TestCase;
use QRIVO\Domain\Security\LogSanitizer;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Read-only admin views of the security-event and audit trail (Phase 20),
 * dispatched through the real Router. Authorization is server-side
 * (`security.event.view` / `audit.log.view`); payloads are re-sanitized on read.
 */
final class AuditTrailRoutesTest extends TestCase
{
    use AcademicSchemaTrait;

    private Router $router;
    private Connection $db;
    private Logger $logger;
    private Config $config;

    protected function setUp(): void
    {
        $this->pdo    = $this->buildAcademicDb();
        $this->db     = $this->buildConnection();
        $this->config = new Config(QRIVO_ROOT);
        $this->logger = new Logger($this->config);
        $this->router = new Router(QRIVO_ROOT);

        $this->pdo->exec("INSERT INTO security_events (event_type, severity, user_id, ip_address, user_agent, details, created_at) VALUES
            ('LOGIN_FAILURE', 'MEDIUM', NULL, '203.0.113.1', 'x', '{\"email\":\"a@x.test\",\"reason\":\"invalid_credentials\"}', '2026-03-01 10:00:00'),
            ('QR_REPLAY',     'HIGH',   5,    '203.0.113.2', 'x', '{\"session_uuid\":\"u-1\"}',                                '2026-03-02 10:00:00'),
            ('RISK_ESCALATION','MEDIUM',5,    '203.0.113.2', 'x', '{\"risk_score\":40}',                                      '2026-03-03 10:00:00')");

        $this->pdo->exec("INSERT INTO audit_logs (event_type, actor_user_id, target_entity, target_id, old_value, new_value, reason, ip_address, created_at) VALUES
            ('SCHOOL_CREATED',            9, 'school',            2,  NULL, '{\"name\":\"Eng\"}', NULL, '203.0.113.9', '2026-03-01 09:00:00'),
            ('ATTENDANCE_STATUS_CHANGED', 3, 'attendance_record',11, '{\"status\":\"WAITING\"}', '{\"status\":\"PRESENT\"}', 'manual', '203.0.113.3', '2026-03-02 09:00:00'),
            ('LOGIN_SUCCESS',            7, 'user',              7,  NULL, NULL, NULL, '203.0.113.7', '2026-03-03 09:00:00')");
    }

    private function get(string $uri, ?string $token): JsonResponse
    {
        [$path, $qs] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($qs, $query);
        $headers = ['user-agent' => 'PHPUnit'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }

        return $this->router->dispatch(
            new Request('GET', $path, $query, [], $headers, ['REMOTE_ADDR' => '203.0.113.50']),
            $this->db,
            $this->logger,
            $this->config,
        );
    }

    /** @return array<string, mixed> */
    private function body(JsonResponse $r): array
    {
        $ref = new \ReflectionProperty($r, 'data');
        $ref->setAccessible(true);

        return $ref->getValue($r);
    }

    // ─── authorization ──────────────────────────────────────────────────────

    public function test_unauthenticated_is_rejected(): void
    {
        $this->assertSame(401, $this->get('/api/v1/admin/security-events', null)->getStatusCode());
        $this->assertSame(401, $this->get('/api/v1/admin/audit-logs', null)->getStatusCode());
    }

    public function test_teacher_and_student_are_forbidden(): void
    {
        foreach (['TEACHER', 'STUDENT'] as $role) {
            $token = $this->issueToken($this->makeUser(strtolower($role) . '@x.test', [$role]));
            $this->assertSame(403, $this->get('/api/v1/admin/security-events', $token)->getStatusCode(), $role);
            $this->assertSame(403, $this->get('/api/v1/admin/audit-logs', $token)->getStatusCode(), $role);
        }
    }

    public function test_admin_can_read_both_trails(): void
    {
        $token = $this->issueToken($this->makeUser('admin@x.test', ['ADMIN']));

        $events = $this->get('/api/v1/admin/security-events', $token);
        $this->assertSame(200, $events->getStatusCode());
        $body = $this->body($events);
        $this->assertCount(3, $body['data']);
        $this->assertSame(3, $body['meta']['total']);
        // newest first
        $this->assertSame('RISK_ESCALATION', $body['data'][0]['event_type']);

        $audits = $this->get('/api/v1/admin/audit-logs', $token);
        $this->assertSame(200, $audits->getStatusCode());
        $this->assertCount(3, $this->body($audits)['data']);
    }

    // ─── filtering & pagination ─────────────────────────────────────────────

    public function test_security_events_filter_by_type_and_severity(): void
    {
        $token = $this->issueToken($this->makeUser('admin@x.test', ['ADMIN']));

        $body = $this->body($this->get('/api/v1/admin/security-events?event_type=QR_REPLAY', $token));
        $this->assertCount(1, $body['data']);
        $this->assertSame('QR_REPLAY', $body['data'][0]['event_type']);

        $body = $this->body($this->get('/api/v1/admin/security-events?severity=MEDIUM', $token));
        $this->assertCount(2, $body['data']);

        $body = $this->body($this->get('/api/v1/admin/security-events?user_id=5', $token));
        $this->assertCount(2, $body['data']);
    }

    public function test_audit_logs_filter_by_entity_and_actor(): void
    {
        $token = $this->issueToken($this->makeUser('admin@x.test', ['ADMIN']));

        $body = $this->body($this->get('/api/v1/admin/audit-logs?target_entity=attendance_record', $token));
        $this->assertCount(1, $body['data']);
        $this->assertSame('manual', $body['data'][0]['reason']);

        $body = $this->body($this->get('/api/v1/admin/audit-logs?event_type=LOGIN_SUCCESS', $token));
        $this->assertCount(1, $body['data']);
    }

    public function test_pagination_meta(): void
    {
        $token = $this->issueToken($this->makeUser('admin@x.test', ['ADMIN']));

        $body = $this->body($this->get('/api/v1/admin/security-events?per_page=2&page=1', $token));
        $this->assertCount(2, $body['data']);
        $this->assertSame(2, $body['meta']['per_page']);
        $this->assertSame(2, $body['meta']['total_pages']);

        $body = $this->body($this->get('/api/v1/admin/security-events?per_page=2&page=2', $token));
        $this->assertCount(1, $body['data']);
    }

    // ─── no-secrets guarantee on read ──────────────────────────────────────

    public function test_read_side_re_sanitizes_legacy_rows(): void
    {
        // A row written before the write-side sweep existed, carrying a secret.
        $this->pdo->exec("INSERT INTO audit_logs (event_type, actor_user_id, target_entity, target_id, new_value, created_at)
            VALUES ('LEGACY', 1, 'user', 1, '{\"password\":\"plaintext\",\"note\":\"ok\"}', '2026-03-04 09:00:00')");

        $token = $this->issueToken($this->makeUser('admin@x.test', ['ADMIN']));
        $body  = $this->body($this->get('/api/v1/admin/audit-logs?event_type=LEGACY', $token));

        $row = $body['data'][0];
        $this->assertSame(LogSanitizer::REDACTED, $row['new_value']['password']);
        $this->assertSame('ok', $row['new_value']['note']);
    }
}
