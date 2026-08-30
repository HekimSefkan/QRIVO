<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Controller\Student;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Student self-service endpoints via the real Router (mobile-app backing API).
 */
final class StudentSelfRoutesTest extends TestCase
{
    use AcademicSchemaTrait;

    private Router $router;
    private Connection $db;
    private Logger $logger;
    private Config $config;
    /** @var array<string, int> */
    private array $ids;

    protected function setUp(): void
    {
        $this->pdo    = $this->buildAcademicDb();
        $this->db     = $this->buildConnection();
        $this->config = new Config(QRIVO_ROOT);
        $this->logger = new Logger($this->config);
        $this->router = new Router(QRIVO_ROOT);
        $this->ids    = $this->seedSchedulingFixtures();
        $this->wireAssignmentAndSchedule(0, '09:00:00', '11:00:00');
        $this->enrolFixtureStudent();
        $session = $this->insertSession('ACTIVE');
        $this->attendanceRecord($session['id'], $this->ids['studentId'], 'PRESENT', 'QR', '2026-03-02 09:05:00');
    }

    private function get(string $uri, ?string $token): JsonResponse
    {
        $headers = ['user-agent' => 'PHPUnit'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        [$path, $qs] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($qs, $query);

        return $this->router->dispatch(new Request('GET', $path, $query, [], $headers, ['REMOTE_ADDR' => '203.0.113.16']), $this->db, $this->logger, $this->config);
    }

    private function paths(): array
    {
        return [
            '/api/v1/student/dashboard',
            '/api/v1/student/profile',
            '/api/v1/student/schedule',
            '/api/v1/student/attendance/history',
        ];
    }

    public function test_student_can_read_all_self_endpoints(): void
    {
        $token = $this->issueToken($this->ids['studentUserId']);
        foreach ($this->paths() as $p) {
            $this->assertSame(200, $this->get($p, $token)->getStatusCode(), $p);
        }

        $profile = $this->get('/api/v1/student/profile', $token)->getData()['data'];
        $this->assertSame('S-1', $profile['student_number']);

        $history = $this->get('/api/v1/student/attendance/history', $token);
        $this->assertArrayHasKey('meta', $history->getData());
        $this->assertCount(1, $history->getData()['data']);
    }

    public function test_unauthenticated_gets_401(): void
    {
        foreach ($this->paths() as $p) {
            $this->assertSame(401, $this->get($p, null)->getStatusCode(), $p);
        }
    }

    public function test_teacher_gets_403(): void
    {
        $token = $this->issueToken($this->ids['teacherUserId']);
        foreach ($this->paths() as $p) {
            $this->assertSame(403, $this->get($p, $token)->getStatusCode(), $p);
        }
    }

    public function test_admin_without_self_permissions_gets_403(): void
    {
        $token = $this->issueToken($this->makeUser('a@x.test', ['ADMIN']));
        $this->assertSame(403, $this->get('/api/v1/student/schedule', $token)->getStatusCode());
    }
}
