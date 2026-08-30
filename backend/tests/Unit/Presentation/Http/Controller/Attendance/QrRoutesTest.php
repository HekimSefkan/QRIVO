<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Controller\Attendance;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Attendance\QrService;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\Attendance\QrNonceRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * GET /api/v1/teacher/attendance/{id}/qr  and
 * POST /api/v1/student/attendance/qr/verify — via the real Router.
 */
final class QrRoutesTest extends TestCase
{
    use AcademicSchemaTrait;

    private Router $router;
    private Connection $db;
    private Logger $logger;
    private Config $config;
    /** @var array<string, int> */
    private array $ids;
    /** @var array{id:int, uuid:string, secret:string} */
    private array $session;

    protected function setUp(): void
    {
        $this->pdo     = $this->buildAcademicDb();
        $this->db      = $this->buildConnection();
        $this->config  = new Config(QRIVO_ROOT);
        $this->logger  = new Logger($this->config);
        $this->router  = new Router(QRIVO_ROOT);
        $this->ids     = $this->seedSchedulingFixtures();
        $this->session = $this->insertSession('ACTIVE');
    }

    private function dispatch(string $method, string $uri, array $body = [], ?string $token = null): JsonResponse
    {
        $headers = ['user-agent' => 'PHPUnit', 'content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        return $this->router->dispatch(
            new Request($method, $uri, [], $body, $headers, ['REMOTE_ADDR' => '203.0.113.11']),
            $this->db,
            $this->logger,
            $this->config,
        );
    }

    private function freshQrString(): string
    {
        $svc = new QrService(
            $this->logger,
            new AttendanceSessionRepository($this->db),
            new QrNonceRepository($this->db),
            new RelationshipRepository($this->db),
            $this->securityLogService($this->db),
            $this->config,
        );
        $row = (new AttendanceSessionRepository($this->db))->findRow($this->session['id']);

        return $svc->generate($row, new \DateTimeImmutable('now'))['qr_string'];
    }

    // ─── Teacher: generate ───────────────────────────────────────────────────

    public function test_teacher_gets_current_qr_for_own_session(): void
    {
        $token = $this->issueToken($this->ids['teacherUserId']);
        $res   = $this->dispatch('GET', "/api/v1/teacher/attendance/{$this->session['id']}/qr", [], $token);

        $this->assertSame(200, $res->getStatusCode());
        $data = $res->getData()['data'];
        $this->assertSame($this->session['uuid'], $data['payload']['session_id']);
        $this->assertMatchesRegularExpression('/^qrivo\.v1\./', $data['qr_string']);
        $this->assertStringNotContainsString('session_secret', (string) json_encode($res->getData()));
        $this->assertStringNotContainsString($this->session['secret'], (string) json_encode($res->getData()));
    }

    public function test_unauthenticated_qr_is_401(): void
    {
        $this->assertSame(401, $this->dispatch('GET', "/api/v1/teacher/attendance/{$this->session['id']}/qr")->getStatusCode());
    }

    public function test_student_cannot_generate_qr_403(): void
    {
        $token = $this->issueToken($this->makeUser('s@x.test', ['STUDENT']));
        $this->assertSame(403, $this->dispatch('GET', "/api/v1/teacher/attendance/{$this->session['id']}/qr", [], $token)->getStatusCode());
    }

    public function test_other_teacher_cannot_generate_qr_403(): void
    {
        $u = $this->makeUser('t2@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$u, $this->ids['departmentId'], 'E-2', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        $res = $this->dispatch('GET', "/api/v1/teacher/attendance/{$this->session['id']}/qr", [], $this->issueToken($u));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(1, $this->securityEventCount('IDOR_ATTEMPT'));
    }

    public function test_qr_for_closed_session_is_409(): void
    {
        $closed = $this->insertSession('CLOSED');
        $token  = $this->issueToken($this->ids['teacherUserId']);
        $this->assertSame(409, $this->dispatch('GET', "/api/v1/teacher/attendance/{$closed['id']}/qr", [], $token)->getStatusCode());
    }

    // ─── Student: verify ─────────────────────────────────────────────────────

    public function test_student_verifies_valid_qr(): void
    {
        $token = $this->issueToken($this->ids['studentUserId']);
        $res   = $this->dispatch('POST', '/api/v1/student/attendance/qr/verify', ['qr' => $this->freshQrString()], $token);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($res->getData()['data']['valid']);
        $this->assertSame('VALID', $res->getData()['data']['reason']);
    }

    public function test_student_verify_reports_malformed(): void
    {
        $token = $this->issueToken($this->ids['studentUserId']);
        $res   = $this->dispatch('POST', '/api/v1/student/attendance/qr/verify', ['qr' => 'not-a-qr'], $token);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($res->getData()['data']['valid']);
        $this->assertSame('MALFORMED', $res->getData()['data']['reason']);
    }

    public function test_teacher_cannot_use_student_verify_403(): void
    {
        $token = $this->issueToken($this->ids['teacherUserId']);
        $res   = $this->dispatch('POST', '/api/v1/student/attendance/qr/verify', ['qr' => $this->freshQrString()], $token);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_student_verify_requires_qr_field_422(): void
    {
        $token = $this->issueToken($this->ids['studentUserId']);
        $this->assertSame(422, $this->dispatch('POST', '/api/v1/student/attendance/qr/verify', [], $token)->getStatusCode());
    }
}
