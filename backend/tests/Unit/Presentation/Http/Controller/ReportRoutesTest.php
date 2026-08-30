<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Controller;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Attendance reporting (PROJECT_SPECIFICATION.md §6.16) through the real Router.
 *
 * The authorization hierarchy is proved end-to-end:
 *   - students see only their own data;
 *   - teachers only their assigned courses/classes/students;
 *   - admins only via `report.institution.view`.
 */
final class ReportRoutesTest extends TestCase
{
    use AcademicSchemaTrait;

    private Router $router;
    private Connection $db;
    private Logger $logger;
    private Config $config;
    /** @var array<string, int> */
    private array $ids;

    private int $courseB;
    private int $teacherBUserId;
    private int $classB;

    protected function setUp(): void
    {
        $this->pdo    = $this->buildAcademicDb();
        $this->db     = $this->buildConnection();
        $this->config = new Config(QRIVO_ROOT);
        $this->logger = new Logger($this->config);
        $this->router = new Router(QRIVO_ROOT);

        $this->ids = $this->seedSchedulingFixtures();
        $this->wireAssignmentAndSchedule();
        $students = $this->enrolStudents(3); // S-1..S-3 in the fixture class, course A

        $now = '2026-01-01 00:00:00';

        // Second course + class, taught by a second teacher; S-1 also attends here.
        $this->pdo->prepare('INSERT INTO courses (department_id, name, code, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$this->ids['departmentId'], 'Algorithms', 'ALG201', $now, $now]);
        $this->courseB = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO classes (program_id, academic_term_id, name, grade_level, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$this->ids['programId'], $this->ids['termId'], 'CE-2B', 2, $now, $now]);
        $this->classB = (int) $this->pdo->lastInsertId();

        $this->teacherBUserId = $this->makeUser('teacherB@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$this->teacherBUserId, $this->ids['departmentId'], 'E-2', $now, $now]);
        $teacherBId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_courses (teacher_id, course_id, academic_term_id, created_at) VALUES (?,?,?,?)')
            ->execute([$teacherBId, $this->courseB, $this->ids['termId'], $now]);
        $this->pdo->prepare('INSERT INTO teacher_class_assignments (teacher_id, class_id, course_id, academic_term_id, created_at) VALUES (?,?,?,?,?)')
            ->execute([$teacherBId, $this->classB, $this->courseB, $this->ids['termId'], $now]);
        $this->pdo->prepare('INSERT INTO student_class_assignments (student_id, class_id, academic_term_id, enrolled_at) VALUES (?,?,?,?)')
            ->execute([$students[0], $this->classB, $this->ids['termId'], $now]);

        // Course A: two sessions with records.
        $a1 = $this->sessionFor($this->ids['courseId'], $this->ids['classId'], $this->ids['teacherId'], '2026-03-02 09:00:00');
        $this->attendanceRecord($a1, $students[0], 'PRESENT', 'QR', '2026-03-02 09:02:00');
        $this->attendanceRecord($a1, $students[1], 'PRESENT', 'QR', '2026-03-02 09:03:00');
        $this->attendanceRecord($a1, $students[2], 'ABSENT', 'MANUAL', '2026-03-02 11:00:00');
        $a2 = $this->sessionFor($this->ids['courseId'], $this->ids['classId'], $this->ids['teacherId'], '2026-03-09 09:00:00');
        $this->attendanceRecord($a2, $students[0], 'LATE', 'QR', '2026-03-09 09:25:00');

        // Course B: one session, S-1 PRESENT.
        $teacherBTeacherId = $teacherBId;
        $b1 = $this->sessionFor($this->courseB, $this->classB, $teacherBTeacherId, '2026-03-03 13:00:00');
        $this->attendanceRecord($b1, $students[0], 'PRESENT', 'QR', '2026-03-03 13:01:00');
    }

    private function sessionFor(int $courseId, int $classId, int $teacherId, string $startTime): int
    {
        $now = '2026-01-01 00:00:00';
        $this->pdo->prepare(
            'INSERT INTO attendance_sessions (uuid, course_id, class_id, teacher_id, room_id, academic_term_id, start_time, end_time, expires_at, status, session_secret, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,NULL,?,?,?,?,?)'
        )->execute([
            bin2hex(random_bytes(8)) . '-s', $courseId, $classId, $teacherId, $this->ids['roomId'],
            $this->ids['termId'], $startTime, '2099-01-01 00:00:00', 'CLOSED', bin2hex(random_bytes(8)), $now, $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function get(string $uri, ?int $userId): JsonResponse
    {
        [$path, $qs] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($qs, $query);
        $headers = ['user-agent' => 'PHPUnit'];
        if ($userId !== null) {
            $headers['authorization'] = 'Bearer ' . $this->issueToken($userId);
        }

        return $this->router->dispatch(
            new Request('GET', $path, $query, [], $headers, ['REMOTE_ADDR' => '203.0.113.7']),
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

        return $ref->getValue($r)['data'];
    }

    private function securityEvents(string $type): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) c FROM security_events WHERE event_type = ?');
        $stmt->execute([$type]);

        return (int) $stmt->fetch()['c'];
    }

    // ─── student ────────────────────────────────────────────────────────────

    public function test_student_sees_only_their_own_attendance(): void
    {
        $res = $this->get('/api/v1/student/reports/attendance', $this->ids['studentUserId']);
        $this->assertSame(200, $res->getStatusCode());

        $body = $this->body($res);
        $this->assertSame('student.attendance_history', $body['report']);
        // S-1: PRESENT (A1) + LATE (A2) + PRESENT (B1) = 3 records, all theirs.
        $this->assertSame(3, $body['meta']['total']);
        $this->assertSame(2, $body['summary']['counts']['present']);
        $this->assertSame(1, $body['summary']['counts']['late']);
    }

    public function test_student_report_filters_and_paginates(): void
    {
        $body = $this->body($this->get(
            '/api/v1/student/reports/attendance?course_id=' . $this->ids['courseId'] . '&per_page=1&page=1',
            $this->ids['studentUserId'],
        ));
        $this->assertSame(2, $body['meta']['total']);      // course A only
        $this->assertCount(1, $body['records']);
        $this->assertSame(2, $body['meta']['total_pages']);
    }

    public function test_student_report_rejects_bad_filters(): void
    {
        $this->assertSame(422, $this->get('/api/v1/student/reports/attendance?from=nonsense', $this->ids['studentUserId'])->getStatusCode());
        $this->assertSame(422, $this->get('/api/v1/student/reports/attendance?status=BOGUS', $this->ids['studentUserId'])->getStatusCode());
        $this->assertSame(422, $this->get('/api/v1/student/reports/attendance?course_id=-4', $this->ids['studentUserId'])->getStatusCode());
    }

    public function test_student_cannot_reach_teacher_or_admin_reports(): void
    {
        $u = $this->ids['studentUserId'];
        $this->assertSame(403, $this->get('/api/v1/teacher/reports/course/' . $this->ids['courseId'], $u)->getStatusCode());
        $this->assertSame(403, $this->get('/api/v1/admin/reports/institution', $u)->getStatusCode());
    }

    // ─── teacher ────────────────────────────────────────────────────────────

    public function test_assigned_teacher_gets_course_and_class_reports(): void
    {
        $t = $this->ids['teacherUserId'];

        $course = $this->body($this->get('/api/v1/teacher/reports/course/' . $this->ids['courseId'], $t));
        $this->assertSame('teacher.course_attendance', $course['report']);
        $this->assertCount(2, $course['sessions']);
        $this->assertSame(2, $course['summary']['counts']['present']); // A1: S-1, S-2
        $this->assertSame(1, $course['summary']['counts']['late']);    // A2: S-1

        $class = $this->body($this->get('/api/v1/teacher/reports/class/' . $this->ids['classId'], $t));
        $this->assertSame(3, $class['meta']['total']); // 3 students in course A's class
    }

    public function test_teacher_cannot_report_on_an_unassigned_course(): void
    {
        $res = $this->get('/api/v1/teacher/reports/course/' . $this->courseB, $this->ids['teacherUserId']);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(1, $this->securityEvents('UNAUTHORIZED_ACCESS'));
    }

    public function test_teacher_student_report_is_scoped_to_their_own_courses(): void
    {
        // Teacher A shares the fixture class with S-1, but S-1 also attends course B
        // (teacher B). Teacher A's report must NOT include the course-B record.
        $body = $this->body($this->get('/api/v1/teacher/reports/student/' . $this->ids['studentId'], $this->ids['teacherUserId']));

        $this->assertSame('teacher.student_history', $body['report']);
        $this->assertSame(2, $body['meta']['total']); // PRESENT (A1) + LATE (A2) only
        foreach ($body['records'] as $row) {
            $this->assertSame((int) $this->ids['courseId'], (int) $row['course_id']);
        }
    }

    public function test_teacher_cannot_report_on_a_student_outside_their_classes(): void
    {
        // A brand-new student in no class of teacher A.
        $u = $this->makeUser('lonely@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$u, $this->ids['programId'], 'S-99', 2025, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $lonelyId = (int) $this->pdo->lastInsertId();

        $res = $this->get('/api/v1/teacher/reports/student/' . $lonelyId, $this->ids['teacherUserId']);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_teacher_student_report_filter_may_only_narrow_scope(): void
    {
        // course B is not in teacher A's scope — supplying it as a filter is refused.
        $res = $this->get(
            '/api/v1/teacher/reports/student/' . $this->ids['studentId'] . '?course_id=' . $this->courseB,
            $this->ids['teacherUserId'],
        );
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_teacher_cannot_reach_admin_reports(): void
    {
        $this->assertSame(403, $this->get('/api/v1/admin/reports/attendance-statistics', $this->ids['teacherUserId'])->getStatusCode());
    }

    // ─── admin ──────────────────────────────────────────────────────────────

    public function test_admin_institution_and_statistics_reports(): void
    {
        $admin = $this->makeUser('admin@x.test', ['ADMIN']);

        $inst = $this->body($this->get('/api/v1/admin/reports/institution', $admin));
        $this->assertSame('admin.institution', $inst['report']);
        $this->assertSame(5, $inst['summary']['total_records']); // A1: 3, A2: 1, B1: 1
        $this->assertSame(3, $inst['summary']['counts']['present']);
        $this->assertNotEmpty($inst['by_department']);
        $this->assertNotEmpty($inst['by_status']);

        $stats = $this->body($this->get('/api/v1/admin/reports/attendance-statistics', $admin));
        $this->assertSame('admin.attendance_statistics', $stats['report']);
        $this->assertArrayHasKey('by_day', $stats);
    }

    public function test_admin_department_and_course_reports_with_404(): void
    {
        $admin = $this->makeUser('admin@x.test', ['ADMIN']);

        $dep = $this->body($this->get('/api/v1/admin/reports/department/' . $this->ids['departmentId'], $admin));
        $this->assertSame('admin.department', $dep['report']);
        $this->assertNotEmpty($dep['by_course']);

        $course = $this->body($this->get('/api/v1/admin/reports/course/' . $this->ids['courseId'], $admin));
        $this->assertSame('admin.course_statistics', $course['report']);

        $this->assertSame(404, $this->get('/api/v1/admin/reports/department/99999', $admin)->getStatusCode());
        $this->assertSame(404, $this->get('/api/v1/admin/reports/course/99999', $admin)->getStatusCode());
    }

    // ─── unauthenticated ────────────────────────────────────────────────────

    public function test_every_report_endpoint_requires_authentication(): void
    {
        foreach ([
            '/api/v1/student/reports/attendance',
            '/api/v1/teacher/reports/course/1',
            '/api/v1/teacher/reports/class/1',
            '/api/v1/teacher/reports/student/1',
            '/api/v1/admin/reports/institution',
            '/api/v1/admin/reports/department/1',
            '/api/v1/admin/reports/course/1',
            '/api/v1/admin/reports/attendance-statistics',
        ] as $uri) {
            $this->assertSame(401, $this->get($uri, null)->getStatusCode(), $uri);
        }
    }
}
