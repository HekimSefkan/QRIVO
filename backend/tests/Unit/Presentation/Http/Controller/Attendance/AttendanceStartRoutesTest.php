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
 * POST /api/v1/teacher/attendance/start and GET /api/v1/teacher/attendance/{id},
 * dispatched through the real Router. The controller evaluates eligibility at
 * "now", so the schedule fixture is wired to cover the current wall-clock time.
 */
final class AttendanceStartRoutesTest extends TestCase
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
    }

    private function dispatch(string $method, string $uri, array $body = [], ?string $token = null): JsonResponse
    {
        $headers = ['user-agent' => 'PHPUnit', 'content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        [$path, $qs] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($qs, $query);

        return $this->router->dispatch(
            new Request($method, $path, $query, $body, $headers, ['REMOTE_ADDR' => '203.0.113.8']),
            $this->db,
            $this->logger,
            $this->config,
        );
    }

    /** Wire a schedule slot that covers the current wall-clock time on today's day. */
    private function wireScheduleForNow(): void
    {
        $now = new \DateTimeImmutable('now');
        $dow = ((int) $now->format('N')) - 1;
        $h   = (int) $now->format('G');
        $start = $h < 1 ? '00:00:00' : $now->modify('-1 hour')->format('H:i:s');
        $end   = $h > 22 ? '23:59:59' : $now->modify('+1 hour')->format('H:i:s');

        $this->wireAssignmentAndSchedule($dow, $start, $end);
    }

    private function startBody(array $overrides = []): array
    {
        return array_merge(['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId']], $overrides);
    }

    public function test_teacher_starts_session_via_http(): void
    {
        $this->wireScheduleForNow();
        $this->enrolStudents(2);
        $token = $this->issueToken($this->ids['teacherUserId']);

        $res = $this->dispatch('POST', '/api/v1/teacher/attendance/start', $this->startBody(), $token);
        $this->assertSame(201, $res->getStatusCode());

        $data = $res->getData()['data'];
        $this->assertSame('ACTIVE', $data['session']['status']);
        $this->assertSame(2, $data['counts']['TOTAL']);
        $this->assertArrayNotHasKey('session_secret', $data['session']);

        // GET own session
        $id  = $data['session']['id'];
        $get = $this->dispatch('GET', "/api/v1/teacher/attendance/{$id}", [], $token);
        $this->assertSame(200, $get->getStatusCode());
        $this->assertSame($data['session']['uuid'], $get->getData()['data']['session']['uuid']);
    }

    public function test_unauthenticated_start_is_401(): void
    {
        $this->assertSame(401, $this->dispatch('POST', '/api/v1/teacher/attendance/start', $this->startBody())->getStatusCode());
    }

    public function test_student_cannot_start_403(): void
    {
        $this->wireScheduleForNow();
        $token = $this->issueToken($this->makeUser('s@x.test', ['STUDENT']));
        $this->assertSame(403, $this->dispatch('POST', '/api/v1/teacher/attendance/start', $this->startBody(), $token)->getStatusCode());
    }

    public function test_unassigned_teacher_cannot_start_403(): void
    {
        $this->wireScheduleForNow();
        // a teacher with a profile but no assignment for this class/course
        $u = $this->makeUser('t2@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$u, $this->ids['departmentId'], 'E-77', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $token = $this->issueToken($u);

        $this->assertSame(403, $this->dispatch('POST', '/api/v1/teacher/attendance/start', $this->startBody(), $token)->getStatusCode());
    }

    public function test_duplicate_active_session_is_409(): void
    {
        $this->wireScheduleForNow();
        $token = $this->issueToken($this->ids['teacherUserId']);

        $this->assertSame(201, $this->dispatch('POST', '/api/v1/teacher/attendance/start', $this->startBody(), $token)->getStatusCode());
        $this->assertSame(409, $this->dispatch('POST', '/api/v1/teacher/attendance/start', $this->startBody(), $token)->getStatusCode());
    }

    public function test_missing_body_is_422(): void
    {
        $this->wireScheduleForNow();
        $token = $this->issueToken($this->ids['teacherUserId']);
        $this->assertSame(422, $this->dispatch('POST', '/api/v1/teacher/attendance/start', ['class_id' => $this->ids['classId']], $token)->getStatusCode());
    }

    public function test_cannot_view_another_teachers_session_403(): void
    {
        $this->wireScheduleForNow();
        $ownerToken = $this->issueToken($this->ids['teacherUserId']);
        $id = $this->dispatch('POST', '/api/v1/teacher/attendance/start', $this->startBody(), $ownerToken)->getData()['data']['session']['id'];

        $u = $this->makeUser('t3@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$u, $this->ids['departmentId'], 'E-33', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        $this->assertSame(403, $this->dispatch('GET', "/api/v1/teacher/attendance/{$id}", [], $this->issueToken($u))->getStatusCode());
    }
}
