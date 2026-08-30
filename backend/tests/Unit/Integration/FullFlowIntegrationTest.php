<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * End-to-end validation of the complete approved QRIVO flow (Phase 22),
 * dispatched through the real Router at every step:
 *
 *   Authentication → Authorization → Academic structure → Course → Schedule →
 *   Attendance session → Dynamic QR → QR scan → Challenge → Challenge response →
 *   Security validation → Risk evaluation → Attendance transaction →
 *   Live attendance → Manual attendance → Session closing → Reporting
 *
 * SQLite in-memory schema (mirrors migrations 001–008); production uses MySQL.
 */
final class FullFlowIntegrationTest extends TestCase
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
    }

    // ─── HTTP helpers ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     * @return array{status:int, body:array<string, mixed>}
     */
    private function call(string $method, string $uri, array $body = [], ?string $token = null): array
    {
        [$path, $qs] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($qs, $query);

        $headers = ['user-agent' => 'QRIVO-E2E', 'content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }

        $response = $this->router->dispatch(
            new Request($method, $path, $query, $body, $headers, ['REMOTE_ADDR' => '198.51.100.10']),
            $this->db,
            $this->logger,
            $this->config,
        );

        $ref = new \ReflectionProperty($response, 'data');
        $ref->setAccessible(true);

        return ['status' => $response->getStatusCode(), 'body' => (array) $ref->getValue($response)];
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body, ?string $token = null): array
    {
        return $this->call('POST', $uri, $body, $token);
    }

    private function get(string $uri, ?string $token = null): array
    {
        return $this->call('GET', $uri, [], $token);
    }

    /** @param array<string, mixed> $body */
    private function patch(string $uri, array $body, ?string $token): array
    {
        return $this->call('PATCH', $uri, $body, $token);
    }

    /** Create a login-capable user with a role and return [userId, token]. */
    private function makeLoginUser(string $email, string $role, string $password = 'Passw0rd!x'): array
    {
        $id = $this->makeUser($email, [$role]);
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_ARGON2ID), $id]);

        $login = $this->post('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
        self::assertSame(200, $login['status'], "login failed for {$email}");

        return [$id, $login['body']['data']['access_token']];
    }

    /** @return int the `data.id` of a 201 create */
    private function created(array $response, string $context): int
    {
        self::assertSame(201, $response['status'], "{$context}: " . json_encode($response['body']));

        return (int) $response['body']['data']['id'];
    }

    private function eventCount(string $type): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) c FROM security_events WHERE event_type = ?');
        $stmt->execute([$type]);

        return (int) $stmt->fetch()['c'];
    }

    private function auditExists(string $type): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM audit_logs WHERE event_type = ? LIMIT 1');
        $stmt->execute([$type]);

        return $stmt->fetch() !== false;
    }

    // ─── the complete approved flow ─────────────────────────────────────────

    public function test_complete_approved_flow_end_to_end(): void
    {
        // ── 1. AUTHENTICATION ──────────────────────────────────────────────
        [$adminId, $adminTok]     = $this->makeLoginUser('admin@e2e.test', 'ADMIN');
        [$teacherUserId, $teachTok] = $this->makeLoginUser('teacher@e2e.test', 'TEACHER');
        [$studentUserId, $studTok] = $this->makeLoginUser('student@e2e.test', 'STUDENT');
        [, $student2Tok]           = $this->makeLoginUser('student2@e2e.test', 'STUDENT');
        $student2UserId = (int) $this->pdo->query("SELECT id FROM users WHERE email='student2@e2e.test'")->fetch()['id'];

        self::assertTrue($this->auditExists('LOGIN_SUCCESS'));

        // ── 2. AUTHORIZATION (negative) — a student cannot administer ───────
        self::assertSame(403, $this->post('/api/v1/admin/schools', ['name' => 'X', 'code' => 'X'], $studTok)['status']);
        self::assertGreaterThanOrEqual(1, $this->eventCount('UNAUTHORIZED_ACCESS'));

        // ── 3. ACADEMIC STRUCTURE (admin, via HTTP) ────────────────────────
        $schoolId  = $this->created($this->post('/api/v1/admin/schools', ['name' => 'Tech U', 'code' => 'TU'], $adminTok), 'school');
        $facultyId = $this->created($this->post('/api/v1/admin/faculties', ['school_id' => $schoolId, 'name' => 'Engineering', 'code' => 'ENG'], $adminTok), 'faculty');
        $deptId    = $this->created($this->post('/api/v1/admin/departments', ['faculty_id' => $facultyId, 'name' => 'Computer Eng', 'code' => 'CE'], $adminTok), 'department');
        $programId = $this->created($this->post('/api/v1/admin/programs', ['department_id' => $deptId, 'name' => 'CE BSc', 'code' => 'CEBSC', 'duration_years' => 4], $adminTok), 'program');
        $roomId    = $this->created($this->post('/api/v1/admin/rooms', ['school_id' => $schoolId, 'name' => 'Hall A', 'code' => 'A1', 'capacity' => 120], $adminTok), 'room');
        $yearId    = $this->created($this->post('/api/v1/admin/academic-years', ['school_id' => $schoolId, 'name' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => 1], $adminTok), 'year');
        $termId    = $this->created($this->post('/api/v1/admin/academic-terms', ['academic_year_id' => $yearId, 'name' => 'Term 1', 'term_number' => 1, 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => 1], $adminTok), 'term');
        $classId   = $this->created($this->post('/api/v1/admin/classes', ['program_id' => $programId, 'academic_term_id' => $termId, 'name' => 'CE-1A', 'grade_level' => 1], $adminTok), 'class');

        // ── 4. COURSE ─────────────────────────────────────────────────────
        $courseId  = $this->created($this->post('/api/v1/admin/courses', ['department_id' => $deptId, 'name' => 'Data Structures', 'code' => 'DS101', 'credit_hours' => 4], $adminTok), 'course');

        // teacher + student profiles (link the existing user accounts)
        $teacherId = $this->created($this->post('/api/v1/admin/teachers', ['user_id' => $teacherUserId, 'department_id' => $deptId, 'employee_number' => 'E-1'], $adminTok), 'teacher');
        $studentId = $this->created($this->post('/api/v1/admin/students', ['user_id' => $studentUserId, 'program_id' => $programId, 'student_number' => 'S-1', 'enrollment_year' => 2025], $adminTok), 'student');
        $student2Id = $this->created($this->post('/api/v1/admin/students', ['user_id' => $student2UserId, 'program_id' => $programId, 'student_number' => 'S-2', 'enrollment_year' => 2025], $adminTok), 'student2');

        // ── 5. SCHEDULE (assignments + a slot that covers *now*) ───────────
        $this->created($this->post('/api/v1/admin/class-courses', ['class_id' => $classId, 'course_id' => $courseId, 'academic_term_id' => $termId], $adminTok), 'class_course');
        $this->created($this->post('/api/v1/admin/teacher-courses', ['teacher_id' => $teacherId, 'course_id' => $courseId, 'academic_term_id' => $termId], $adminTok), 'teacher_course');
        $tcaId = $this->created($this->post('/api/v1/admin/teacher-class-assignments', ['teacher_id' => $teacherId, 'class_id' => $classId, 'course_id' => $courseId, 'academic_term_id' => $termId], $adminTok), 'tca');

        $todayDow = (int) (new \DateTimeImmutable('now'))->format('N') - 1; // DayOfWeek: 0 = Monday
        $this->created($this->post('/api/v1/admin/course-schedules', [
            'teacher_class_assignment_id' => $tcaId,
            'room_id'                     => $roomId,
            'day_of_week'                 => $todayDow,
            'start_time'                  => '00:00:00',
            'end_time'                    => '23:59:00',
        ], $adminTok), 'schedule');

        // enrol both students (populates the derived student_courses rows)
        $this->created($this->post('/api/v1/admin/student-class-assignments', ['student_id' => $studentId, 'class_id' => $classId, 'academic_term_id' => $termId], $adminTok), 'enrol S-1');
        $this->created($this->post('/api/v1/admin/student-class-assignments', ['student_id' => $student2Id, 'class_id' => $classId, 'academic_term_id' => $termId], $adminTok), 'enrol S-2');

        self::assertTrue($this->auditExists('COURSE_CREATED'));
        self::assertTrue($this->auditExists('USER_ROLE_ATTACHED'));

        // ── 6. ATTENDANCE SESSION ─────────────────────────────────────────
        $start = $this->post('/api/v1/teacher/attendance/start', ['class_id' => $classId, 'course_id' => $courseId], $teachTok);
        self::assertSame(201, $start['status'], json_encode($start['body']));
        $sessionId = (int) $start['body']['data']['session']['id'];
        self::assertSame('ACTIVE', $start['body']['data']['session']['status']);
        self::assertSame(2, $start['body']['data']['counts']['WAITING']); // both students initialised
        self::assertArrayNotHasKey('session_secret', $start['body']['data']['session']); // DD-002
        self::assertTrue($this->auditExists('ATTENDANCE_SESSION_STARTED'));

        // ── 7. DYNAMIC QR ─────────────────────────────────────────────────
        $qr = $this->get("/api/v1/teacher/attendance/{$sessionId}/qr", $teachTok);
        self::assertSame(200, $qr['status']);
        $qrString = $qr['body']['data']['qr_string'];
        self::assertStringStartsWith('qrivo.v1.', $qrString);
        self::assertArrayNotHasKey('session_secret', $qr['body']['data']);

        // ── 8-13. QR SCAN → CHALLENGE → RESPONSE → SECURITY → RISK → TRANSACTION
        $challenge = $this->post('/api/v1/student/attendance/challenge', ['qr' => $qrString], $studTok);
        self::assertSame(201, $challenge['status'], json_encode($challenge['body']));
        $challengeId = $challenge['body']['data']['challenge_id'];
        $nonce       = $challenge['body']['data']['nonce'];

        $verify = $this->post('/api/v1/student/attendance/verify', [
            'challenge_id' => $challengeId,
            'nonce'        => $nonce,
            'qr'           => $qrString,
        ], $studTok);
        self::assertSame(200, $verify['status'], json_encode($verify['body']));
        self::assertSame('PRESENT', $verify['body']['data']['status']);
        self::assertSame('QR', $verify['body']['data']['source']);

        // security validation + risk evaluation + attendance transaction side-effects
        self::assertSame('PRESENT', $this->pdo->query("SELECT status FROM attendance_records WHERE attendance_session_id={$sessionId} AND student_id={$studentId}")->fetch()['status']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) c FROM risk_assessments WHERE attendance_session_id={$sessionId}")->fetch()['c']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) c FROM qr_challenges WHERE used_at IS NOT NULL")->fetch()['c']);
        self::assertTrue($this->auditExists('ATTENDANCE_RECORDED'));

        // replay: the SAME challenge cannot be used twice
        $replay = $this->post('/api/v1/student/attendance/verify', ['challenge_id' => $challengeId, 'nonce' => $nonce, 'qr' => $qrString], $studTok);
        self::assertSame(409, $replay['status']);

        // ── 14. LIVE ATTENDANCE ───────────────────────────────────────────
        $live = $this->get("/api/v1/teacher/attendance/{$sessionId}/live", $teachTok);
        self::assertSame(200, $live['status']);
        self::assertSame(1, $live['body']['data']['counters']['PRESENT']);
        self::assertSame(1, $live['body']['data']['counters']['WAITING']);

        // ── 15. MANUAL ATTENDANCE ─────────────────────────────────────────
        $manual = $this->patch("/api/v1/teacher/attendance/{$sessionId}/student/{$student2Id}", ['status' => 'LATE', 'reason' => 'Arrived 20 min late'], $teachTok);
        self::assertSame(200, $manual['status'], json_encode($manual['body']));
        self::assertSame('LATE', $this->pdo->query("SELECT status FROM attendance_records WHERE attendance_session_id={$sessionId} AND student_id={$student2Id}")->fetch()['status']);
        self::assertTrue($this->auditExists('ATTENDANCE_STATUS_CHANGED'));

        // a student can NEVER modify attendance (SECURITY_RULES.md §7)
        self::assertSame(403, $this->patch("/api/v1/teacher/attendance/{$sessionId}/student/{$studentId}", ['status' => 'PRESENT'], $studTok)['status']);

        // ── 16. SESSION CLOSING ───────────────────────────────────────────
        $close = $this->post("/api/v1/teacher/attendance/{$sessionId}/close", [], $teachTok);
        self::assertSame(200, $close['status'], json_encode($close['body']));
        self::assertSame('CLOSED', $close['body']['data']['session']['status']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) c FROM attendance_records WHERE attendance_session_id={$sessionId} AND status='WAITING'")->fetch()['c']);
        self::assertTrue($this->auditExists('ATTENDANCE_SESSION_CLOSED'));

        // after close: new submissions blocked; the session cannot be re-closed or cancelled
        $afterClose = $this->post('/api/v1/student/attendance/challenge', ['qr' => $qrString], $studTok);
        self::assertContains($afterClose['status'], [409, 404], 'challenge after close must be rejected');
        self::assertSame(409, $this->post("/api/v1/teacher/attendance/{$sessionId}/close", [], $teachTok)['status']);
        self::assertSame(409, $this->post("/api/v1/teacher/attendance/{$sessionId}/cancel", [], $teachTok)['status']);

        // ── 17. REPORTING ────────────────────────────────────────────────
        $courseReport = $this->get("/api/v1/teacher/reports/course/{$courseId}", $teachTok);
        self::assertSame(200, $courseReport['status']);
        self::assertSame(1, $courseReport['body']['data']['summary']['counts']['present']);
        self::assertSame(1, $courseReport['body']['data']['summary']['counts']['late']);
        self::assertCount(1, $courseReport['body']['data']['sessions']);

        $institution = $this->get('/api/v1/admin/reports/institution', $adminTok);
        self::assertSame(200, $institution['status']);
        self::assertSame(2, $institution['body']['data']['summary']['total_records']);

        $selfReport = $this->get('/api/v1/student/reports/attendance', $studTok);
        self::assertSame(200, $selfReport['status']);
        self::assertSame(1, $selfReport['body']['data']['meta']['total']);   // only S-1's own record
        self::assertSame('PRESENT', $selfReport['body']['data']['records'][0]['status']);

        // student cannot see another student's report data
        $s2self = $this->get('/api/v1/student/reports/attendance', $student2Tok);
        self::assertSame('LATE', $s2self['body']['data']['records'][0]['status']);
        self::assertSame(1, $s2self['body']['data']['meta']['total']);
    }
}
