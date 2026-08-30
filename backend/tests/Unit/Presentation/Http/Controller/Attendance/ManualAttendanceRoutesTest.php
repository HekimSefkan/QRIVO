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
 * PATCH /api/v1/teacher/attendance/{attendanceId}/student/{studentId} via the real Router.
 */
final class ManualAttendanceRoutesTest extends TestCase
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

    private function patch(array $body, ?string $token, ?int $sid = null, ?int $stu = null): JsonResponse
    {
        $sid ??= $this->session['id'];
        $stu ??= $this->ids['studentId'];
        $headers = ['user-agent' => 'PHPUnit', 'content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }

        return $this->router->dispatch(
            new Request('PATCH', "/api/v1/teacher/attendance/{$sid}/student/{$stu}", [], $body, $headers, ['REMOTE_ADDR' => '203.0.113.14']),
            $this->db,
            $this->logger,
            $this->config,
        );
    }

    public function test_owner_teacher_updates_and_it_is_audited(): void
    {
        $token = $this->issueToken($this->ids['teacherUserId']);
        $res   = $this->patch(['status' => 'present', 'reason' => 'Roll call'], $token);

        $this->assertSame(200, $res->getStatusCode());
        $d = $res->getData()['data'];
        $this->assertSame('WAITING', $d['previous_status']);
        $this->assertSame('PRESENT', $d['status']);
        $this->assertSame('MANUAL', $d['source']);

        $a = $this->pdo->query("SELECT * FROM audit_logs WHERE event_type='ATTENDANCE_STATUS_CHANGED'")->fetch();
        $this->assertSame((string) $this->ids['teacherUserId'], (string) $a['actor_user_id']);
        $this->assertSame('Roll call', $a['reason']);
        $this->assertSame('203.0.113.14', $a['ip_address']);
    }

    public function test_student_cannot_use_the_endpoint_403(): void
    {
        $token = $this->issueToken($this->ids['studentUserId']);
        $this->assertSame(403, $this->patch(['status' => 'PRESENT'], $token)->getStatusCode());
        // record untouched
        $this->assertSame('WAITING', $this->pdo->query("SELECT status FROM attendance_records WHERE student_id={$this->ids['studentId']}")->fetch()['status']);
    }

    public function test_unauthenticated_401(): void
    {
        $this->assertSame(401, $this->patch(['status' => 'PRESENT'], null)->getStatusCode());
    }

    public function test_other_teacher_403(): void
    {
        $u = $this->makeUser('t2@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$u, $this->ids['departmentId'], 'E-2', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        $this->assertSame(403, $this->patch(['status' => 'PRESENT'], $this->issueToken($u))->getStatusCode());
        $this->assertSame(1, $this->securityEventCount('IDOR_ATTEMPT'));
    }

    public function test_invalid_status_422(): void
    {
        $token = $this->issueToken($this->ids['teacherUserId']);
        $this->assertSame(422, $this->patch(['status' => 'PENDING_REVIEW'], $token)->getStatusCode());
        $this->assertSame(422, $this->patch(['status' => 'nope'], $token)->getStatusCode());
        $this->assertSame(422, $this->patch([], $token)->getStatusCode());
    }

    public function test_student_not_in_session_404(): void
    {
        $token = $this->issueToken($this->ids['teacherUserId']);
        $out = $this->makeUser('out@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$out, $this->ids['programId'], 'OUT-9', 2025, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $outSid = (int) $this->pdo->lastInsertId();

        $this->assertSame(404, $this->patch(['status' => 'PRESENT'], $token, null, $outSid)->getStatusCode());
    }

    public function test_cancelled_session_409(): void
    {
        $this->pdo->exec("UPDATE attendance_sessions SET status='CANCELLED' WHERE id={$this->session['id']}");
        $this->assertSame(409, $this->patch(['status' => 'PRESENT'], $this->issueToken($this->ids['teacherUserId']))->getStatusCode());
    }

    public function test_non_numeric_route_params_404(): void
    {
        // {attendanceId:\d+}{studentId:\d+} — non-numeric never matches the route.
        $token   = $this->issueToken($this->ids['teacherUserId']);
        $headers = ['authorization' => 'Bearer ' . $token, 'content-type' => 'application/json'];
        $res = $this->router->dispatch(
            new Request('PATCH', '/api/v1/teacher/attendance/abc/student/xyz', [], ['status' => 'PRESENT'], $headers, ['REMOTE_ADDR' => '1.1.1.1']),
            $this->db, $this->logger, $this->config,
        );
        $this->assertSame(404, $res->getStatusCode());
    }
}
