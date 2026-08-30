<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Controller\Schedule;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Scheduling endpoints dispatched through the real Router: admin CRUD for the
 * assignment tables, RBAC for `/admin/*`, and the teacher attendance-eligibility
 * query.
 */
final class SchedulingRoutesTest extends TestCase
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

        $request = new Request($method, $path, $query, $body, $headers, ['REMOTE_ADDR' => '203.0.113.7']);

        return $this->router->dispatch($request, $this->db, $this->logger, $this->config);
    }

    public function test_admin_builds_full_assignment_chain(): void
    {
        $token = $this->issueToken($this->makeUser('admin@x.test', ['ADMIN']));
        $t = $this->ids['termId'];

        $cc = $this->dispatch('POST', '/api/v1/admin/class-courses', ['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $t], $token);
        $this->assertSame(201, $cc->getStatusCode());

        $tc = $this->dispatch('POST', '/api/v1/admin/teacher-courses', ['teacher_id' => $this->ids['teacherId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $t], $token);
        $this->assertSame(201, $tc->getStatusCode());

        $tca = $this->dispatch('POST', '/api/v1/admin/teacher-class-assignments', ['teacher_id' => $this->ids['teacherId'], 'class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId'], 'academic_term_id' => $t], $token);
        $this->assertSame(201, $tca->getStatusCode());
        $tcaId = $tca->getData()['data']['id'];

        $cs = $this->dispatch('POST', '/api/v1/admin/course-schedules', ['teacher_class_assignment_id' => $tcaId, 'room_id' => $this->ids['roomId'], 'day_of_week' => 0, 'start_time' => '09:00', 'end_time' => '11:00'], $token);
        $this->assertSame(201, $cs->getStatusCode());
        $this->assertSame('Monday', $cs->getData()['data']['day']);

        $sca = $this->dispatch('POST', '/api/v1/admin/student-class-assignments', ['student_id' => $this->ids['studentId'], 'class_id' => $this->ids['classId'], 'academic_term_id' => $t], $token);
        $this->assertSame(201, $sca->getStatusCode());

        // student_courses was derived and is readable
        $listed = $this->dispatch('GET', '/api/v1/admin/student-courses?student_id=' . $this->ids['studentId'], [], $token);
        $this->assertSame(200, $listed->getStatusCode());
        $this->assertCount(1, $listed->getData()['data']);

        // …but not writable (only GET is routed → 405 Method Not Allowed)
        $this->assertSame(405, $this->dispatch('POST', '/api/v1/admin/student-courses', [], $token)->getStatusCode());
    }

    public function test_tca_missing_prerequisites_is_422(): void
    {
        $token = $this->issueToken($this->makeUser('admin@x.test', ['ADMIN']));
        $res   = $this->dispatch('POST', '/api/v1/admin/teacher-class-assignments', [
            'teacher_id' => $this->ids['teacherId'], 'class_id' => $this->ids['classId'],
            'course_id' => $this->ids['courseId'], 'academic_term_id' => $this->ids['termId'],
        ], $token);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function test_non_admin_cannot_touch_scheduling(): void
    {
        $tToken = $this->issueToken($this->makeUser('t@x.test', ['TEACHER']));
        $sToken = $this->issueToken($this->makeUser('s@x.test', ['STUDENT']));

        $this->assertSame(403, $this->dispatch('GET', '/api/v1/admin/teacher-class-assignments', [], $tToken)->getStatusCode());
        $this->assertSame(403, $this->dispatch('GET', '/api/v1/admin/course-schedules', [], $sToken)->getStatusCode());
        $this->assertSame(401, $this->dispatch('GET', '/api/v1/admin/class-courses')->getStatusCode());
    }

    // ─── /teacher/attendance/eligibility ───────────────────────────────────

    private function wireSchedule(): void
    {
        $now = '2026-01-01 00:00:00';
        $this->pdo->prepare('INSERT INTO class_courses (class_id, course_id, academic_term_id, created_at) VALUES (?,?,?,?)')->execute([$this->ids['classId'], $this->ids['courseId'], $this->ids['termId'], $now]);
        $this->pdo->prepare('INSERT INTO teacher_courses (teacher_id, course_id, academic_term_id, created_at) VALUES (?,?,?,?)')->execute([$this->ids['teacherId'], $this->ids['courseId'], $this->ids['termId'], $now]);
        $this->pdo->prepare('INSERT INTO teacher_class_assignments (teacher_id, class_id, course_id, academic_term_id, created_at) VALUES (?,?,?,?,?)')->execute([$this->ids['teacherId'], $this->ids['classId'], $this->ids['courseId'], $this->ids['termId'], $now]);
        $tcaId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO course_schedules (teacher_class_assignment_id, room_id, day_of_week, start_time, end_time, created_at, updated_at) VALUES (?,?,0,?,?,?,?)')->execute([$tcaId, $this->ids['roomId'], '09:00:00', '11:00:00', $now, $now]);
    }

    public function test_eligibility_requires_teacher_permission(): void
    {
        $sToken = $this->issueToken($this->makeUser('s2@x.test', ['STUDENT']));
        $res    = $this->dispatch('GET', '/api/v1/teacher/attendance/eligibility?class_id=1&course_id=1', [], $sToken);
        $this->assertSame(403, $res->getStatusCode());

        $this->assertSame(401, $this->dispatch('GET', '/api/v1/teacher/attendance/eligibility?class_id=1&course_id=1')->getStatusCode());
    }

    public function test_eligibility_authorized_for_assigned_teacher_in_time(): void
    {
        $this->wireSchedule();
        $token = $this->issueToken($this->ids['teacherUserId']);

        $res = $this->dispatch(
            'GET',
            '/api/v1/teacher/attendance/eligibility?class_id=' . $this->ids['classId'] . '&course_id=' . $this->ids['courseId'] . '&at=2026-03-02T10:00:00',
            [],
            $token,
        );

        $this->assertSame(200, $res->getStatusCode());
        $data = $res->getData()['data'];
        $this->assertTrue($data['authorized']);
        $this->assertSame($this->ids['roomId'], $data['room_id']);
        $this->assertSame('AUTHORIZED', $data['reason']);
    }

    public function test_eligibility_denied_for_unassigned_class(): void
    {
        $this->wireSchedule();
        $token = $this->issueToken($this->ids['teacherUserId']);

        $res = $this->dispatch(
            'GET',
            '/api/v1/teacher/attendance/eligibility?class_id=' . $this->ids['classId'] . '&course_id=999&at=2026-03-02T10:00:00',
            [],
            $token,
        );

        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($res->getData()['data']['authorized']);
        $this->assertSame('NOT_ASSIGNED_TO_CLASS_COURSE', $res->getData()['data']['reason']);
    }

    public function test_eligibility_denied_outside_scheduled_time(): void
    {
        $this->wireSchedule();
        $token = $this->issueToken($this->ids['teacherUserId']);

        $res = $this->dispatch(
            'GET',
            '/api/v1/teacher/attendance/eligibility?class_id=' . $this->ids['classId'] . '&course_id=' . $this->ids['courseId'] . '&at=2026-03-02T15:00:00',
            [],
            $token,
        );

        $this->assertFalse($res->getData()['data']['authorized']);
        $this->assertSame('OUTSIDE_SCHEDULED_TIME', $res->getData()['data']['reason']);
    }
}
