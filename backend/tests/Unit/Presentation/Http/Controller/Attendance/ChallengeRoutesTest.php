<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Controller\Attendance;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * POST /api/v1/student/attendance/challenge and .../verify — via the real Router.
 * The controller evaluates "now", so the QR fixture is generated at now.
 */
final class ChallengeRoutesTest extends TestCase
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
        $this->enrolFixtureStudent();
        $this->waitingRecord($this->session['id']);
    }

    private function dispatch(string $method, string $uri, array $body = [], ?string $token = null): JsonResponse
    {
        $headers = ['user-agent' => 'PHPUnit', 'content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        return $this->router->dispatch(
            new Request($method, $uri, [], $body, $headers, ['REMOTE_ADDR' => '203.0.113.12']),
            $this->db,
            $this->logger,
            $this->config,
        );
    }

    public function test_full_scan_challenge_verify_flow(): void
    {
        $token = $this->issueToken($this->ids['studentUserId']);
        $qr    = $this->qrStringFor($this->session);

        $ch = $this->dispatch('POST', '/api/v1/student/attendance/challenge', ['qr' => $qr], $token);
        $this->assertSame(201, $ch->getStatusCode());
        $d = $ch->getData()['data'];
        $this->assertArrayHasKey('challenge_id', $d);
        $this->assertArrayHasKey('nonce', $d);

        $v = $this->dispatch('POST', '/api/v1/student/attendance/verify', [
            'challenge_id' => $d['challenge_id'], 'nonce' => $d['nonce'], 'qr' => $qr,
        ], $token);
        $this->assertSame(200, $v->getStatusCode());
        $this->assertSame('PRESENT', $v->getData()['data']['status']);

        $this->assertSame('PRESENT', $this->pdo->query("SELECT status FROM attendance_records WHERE student_id={$this->ids['studentId']}")->fetch()['status']);
    }

    public function test_challenge_requires_auth(): void
    {
        $this->assertSame(401, $this->dispatch('POST', '/api/v1/student/attendance/challenge', ['qr' => 'x'])->getStatusCode());
    }

    public function test_teacher_cannot_request_challenge(): void
    {
        $token = $this->issueToken($this->ids['teacherUserId']);
        $this->assertSame(403, $this->dispatch('POST', '/api/v1/student/attendance/challenge', ['qr' => $this->qrStringFor($this->session)], $token)->getStatusCode());
    }

    public function test_verify_with_reused_challenge_is_409(): void
    {
        $token = $this->issueToken($this->ids['studentUserId']);
        $qr    = $this->qrStringFor($this->session);
        $d     = $this->dispatch('POST', '/api/v1/student/attendance/challenge', ['qr' => $qr], $token)->getData()['data'];
        $body  = ['challenge_id' => $d['challenge_id'], 'nonce' => $d['nonce'], 'qr' => $qr];

        $this->assertSame(200, $this->dispatch('POST', '/api/v1/student/attendance/verify', $body, $token)->getStatusCode());
        $this->assertSame(409, $this->dispatch('POST', '/api/v1/student/attendance/verify', $body, $token)->getStatusCode());
    }

    public function test_verify_rejects_wrong_nonce_401(): void
    {
        $token = $this->issueToken($this->ids['studentUserId']);
        $qr    = $this->qrStringFor($this->session);
        $d     = $this->dispatch('POST', '/api/v1/student/attendance/challenge', ['qr' => $qr], $token)->getData()['data'];

        $res = $this->dispatch('POST', '/api/v1/student/attendance/verify', [
            'challenge_id' => $d['challenge_id'], 'nonce' => str_repeat('0', 64), 'qr' => $qr,
        ], $token);
        $this->assertSame(401, $res->getStatusCode());
        $this->assertStringNotContainsStringIgnoringCase('nonce', (string) json_encode($res->getData()));
    }

    public function test_challenge_validation_error_422(): void
    {
        $token = $this->issueToken($this->ids['studentUserId']);
        $this->assertSame(422, $this->dispatch('POST', '/api/v1/student/attendance/challenge', [], $token)->getStatusCode());
    }
}
