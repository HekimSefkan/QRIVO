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
 * Live-attendance endpoints via the real Router. Every request is
 * authorization-gated (permission + session ownership).
 */
final class LiveAttendanceRoutesTest extends TestCase
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
        $this->rosterStudent($this->session['id'], 'Ada', 'Adams', 'R-1', 'PRESENT', 'QR');
        $this->rosterStudent($this->session['id'], 'Ben', 'Baker', 'R-2', 'WAITING');
    }

    private function dispatch(string $uri, ?string $token = null): JsonResponse
    {
        $headers = ['user-agent' => 'PHPUnit'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        [$path, $qs] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($qs, $query);

        return $this->router->dispatch(
            new Request('GET', $path, $query, [], $headers, ['REMOTE_ADDR' => '203.0.113.13']),
            $this->db,
            $this->logger,
            $this->config,
        );
    }

    private function paths(): array
    {
        $id = $this->session['id'];
        return [
            "/api/v1/teacher/attendance/{$id}/live",
            "/api/v1/teacher/attendance/{$id}/live/counters",
            "/api/v1/teacher/attendance/{$id}/live/students",
        ];
    }

    public function test_owner_teacher_gets_200_on_every_endpoint(): void
    {
        $token = $this->issueToken($this->ids['teacherUserId']);
        foreach ($this->paths() as $p) {
            $this->assertSame(200, $this->dispatch($p, $token)->getStatusCode(), $p);
        }

        $snap = $this->dispatch($this->paths()[0], $token)->getData()['data'];
        $this->assertSame(2, $snap['counters']['TOTAL']);
        $this->assertCount(2, $snap['students']);
        $this->assertStringNotContainsString('session_secret', (string) json_encode($snap));
    }

    public function test_unauthenticated_gets_401_on_every_endpoint(): void
    {
        foreach ($this->paths() as $p) {
            $this->assertSame(401, $this->dispatch($p)->getStatusCode(), $p);
        }
    }

    public function test_student_gets_403_on_every_endpoint(): void
    {
        $token = $this->issueToken($this->makeUser('s@x.test', ['STUDENT']));
        foreach ($this->paths() as $p) {
            $this->assertSame(403, $this->dispatch($p, $token)->getStatusCode(), $p);
        }
    }

    public function test_other_teacher_gets_403_on_every_endpoint(): void
    {
        $u = $this->makeUser('t2@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$u, $this->ids['departmentId'], 'E-2', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $token = $this->issueToken($u);

        foreach ($this->paths() as $p) {
            $this->assertSame(403, $this->dispatch($p, $token)->getStatusCode(), $p);
        }
        $this->assertSame(3, $this->securityEventCount('IDOR_ATTEMPT'));
    }

    public function test_status_and_search_filters_over_http(): void
    {
        $id    = $this->session['id'];
        $token = $this->issueToken($this->ids['teacherUserId']);

        $present = $this->dispatch("/api/v1/teacher/attendance/{$id}/live/students?status=PRESENT", $token)->getData()['data'];
        $this->assertCount(1, $present['students']);

        $search = $this->dispatch("/api/v1/teacher/attendance/{$id}/live/students?search=Baker", $token)->getData()['data'];
        $this->assertCount(1, $search['students']);
        $this->assertSame('R-2', $search['students'][0]['student_number']);
    }
}
