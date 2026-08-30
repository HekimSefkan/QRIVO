<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Logging\Logger;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Router;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Every security failure path defined by SECURITY_RULES.md §12, exercised
 * through the real Router (Phase 22). Each asserts the request is *rejected*
 * with the right status and (where the spec requires it) a security event —
 * nothing is weakened, no validation is bypassed.
 */
final class SecurityFailurePathsTest extends TestCase
{
    use AcademicSchemaTrait;

    private Router $router;
    private Connection $db;
    private Logger $logger;
    private Config $config;
    /** @var array<string, int> */
    private array $ids;
    private string $teacherTok;
    private string $studentTok;
    private int $sessionId;
    private string $qr;

    protected function setUp(): void
    {
        $this->pdo    = $this->buildAcademicDb();
        $this->db     = $this->buildConnection();
        $this->config = new Config(QRIVO_ROOT);
        $this->logger = new Logger($this->config);
        $this->router = new Router(QRIVO_ROOT);

        $this->ids = $this->seedSchedulingFixtures();
        $dow = (int) (new \DateTimeImmutable('now'))->format('N') - 1;
        $this->wireAssignmentAndSchedule($dow, '00:00:00', '23:59:00');
        $this->enrolFixtureStudent();

        $this->setPassword('teacher@x.test');
        $this->setPassword('student@x.test');
        $this->teacherTok = $this->login('teacher@x.test');
        $this->studentTok = $this->login('student@x.test');

        // A live session + a fresh QR for the QR/challenge failure paths.
        $start = $this->post('/api/v1/teacher/attendance/start', ['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId']], $this->teacherTok);
        self::assertSame(201, $start['status'], json_encode($start['body']));
        $this->sessionId = (int) $start['body']['data']['session']['id'];

        $qr = $this->get("/api/v1/teacher/attendance/{$this->sessionId}/qr", $this->teacherTok);
        $this->qr = $qr['body']['data']['qr_string'];
    }

    // ─── harness ───────────────────────────────────────────────────────────

    /** @return array{status:int, body:array<string,mixed>} */
    private function call(string $method, string $uri, array $body = [], ?string $token = null, array $server = []): array
    {
        [$path, $qs] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($qs, $query);
        $headers = ['user-agent' => 'QRIVO-E2E', 'content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        $response = $this->router->dispatch(
            new Request($method, $path, $query, $body, $headers, $server + ['REMOTE_ADDR' => '198.51.100.20']),
            $this->db, $this->logger, $this->config,
        );
        $ref = new \ReflectionProperty($response, 'data');
        $ref->setAccessible(true);

        return ['status' => $response->getStatusCode(), 'body' => (array) $ref->getValue($response)];
    }

    private function post(string $u, array $b, ?string $t = null, array $s = []): array
    {
        return $this->call('POST', $u, $b, $t, $s);
    }

    private function get(string $u, ?string $t = null): array
    {
        return $this->call('GET', $u, [], $t);
    }

    private function patch(string $u, array $b, ?string $t = null): array
    {
        return $this->call('PATCH', $u, $b, $t);
    }

    private function setPassword(string $email, string $pw = 'Passw0rd!x'): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
            ->execute([password_hash($pw, PASSWORD_ARGON2ID), $email]);
    }

    private function login(string $email, string $pw = 'Passw0rd!x', string $ip = '198.51.100.20'): string
    {
        $r = $this->call('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $pw], null, ['REMOTE_ADDR' => $ip]);
        self::assertSame(200, $r['status'], "login {$email}: " . json_encode($r['body']));

        return $r['body']['data']['access_token'];
    }

    private function events(string $type): int
    {
        $s = $this->pdo->prepare('SELECT COUNT(*) c FROM security_events WHERE event_type = ?');
        $s->execute([$type]);

        return (int) $s->fetch()['c'];
    }

    private function newChallenge(?string $qr = null, ?string $token = null): array
    {
        return $this->post('/api/v1/student/attendance/challenge', ['qr' => $qr ?? $this->qr], $token ?? $this->studentTok);
    }

    // ─── Authentication ────────────────────────────────────────────────────

    public function test_invalid_login_is_rejected_generically_and_logged(): void
    {
        $before = $this->events('LOGIN_FAILURE');
        $r = $this->call('POST', '/api/v1/auth/login', ['email' => 'teacher@x.test', 'password' => 'wrong-pw'], null, ['REMOTE_ADDR' => '198.51.100.21']);

        self::assertSame(401, $r['status']);
        self::assertSame('Invalid credentials.', $r['body']['message']);
        self::assertArrayNotHasKey('data', array_filter($r['body'], static fn ($v) => $v !== null));
        self::assertGreaterThan($before, $this->events('LOGIN_FAILURE'));
    }

    public function test_login_rate_limit_trips(): void
    {
        // config default: 20/ip, 10/email in a 15-min window.
        for ($i = 0; $i < 12; $i++) {
            $this->call('POST', '/api/v1/auth/login', ['email' => 'ratelimit@x.test', 'password' => 'x'], null, ['REMOTE_ADDR' => '198.51.100.30']);
        }
        $r = $this->call('POST', '/api/v1/auth/login', ['email' => 'ratelimit@x.test', 'password' => 'x'], null, ['REMOTE_ADDR' => '198.51.100.30']);

        self::assertSame(429, $r['status']);
    }

    public function test_revoked_refresh_token_reuse_is_flagged(): void
    {
        $login = $this->call('POST', '/api/v1/auth/login', ['email' => 'student@x.test', 'password' => 'Passw0rd!x'], null, ['REMOTE_ADDR' => '198.51.100.20']);
        $refresh = $login['body']['data']['refresh_token'];

        $this->post('/api/v1/auth/refresh', ['refresh_token' => $refresh]);           // rotates → old one revoked
        $reuse = $this->post('/api/v1/auth/refresh', ['refresh_token' => $refresh]);   // reuse

        self::assertSame(401, $reuse['status']);
        self::assertGreaterThanOrEqual(1, $this->events('TOKEN_REUSE'));
    }

    public function test_expired_or_bogus_bearer_is_rejected(): void
    {
        self::assertSame(401, $this->get('/api/v1/auth/me', 'not-a-real-token')['status']);
        self::assertSame(401, $this->get('/api/v1/student/reports/attendance', 'deadbeef')['status']);
    }

    public function test_token_is_dead_after_logout(): void
    {
        $tok = $this->login('teacher@x.test');
        self::assertSame(200, $this->get('/api/v1/auth/me', $tok)['status']);
        $this->post('/api/v1/auth/logout', [], $tok);
        self::assertSame(401, $this->get('/api/v1/auth/me', $tok)['status']);
    }

    // ─── Authorization: IDOR / BOLA / privilege escalation ─────────────────

    public function test_student_cannot_reach_admin_or_teacher_endpoints(): void
    {
        self::assertSame(403, $this->post('/api/v1/admin/schools', ['name' => 'x', 'code' => 'x'], $this->studentTok)['status']);
        self::assertSame(403, $this->get('/api/v1/admin/reports/institution', $this->studentTok)['status']);
        self::assertSame(403, $this->get("/api/v1/teacher/attendance/{$this->sessionId}/live", $this->studentTok)['status']);
        self::assertSame(403, $this->get("/api/v1/teacher/reports/course/{$this->ids['courseId']}", $this->studentTok)['status']);
        self::assertGreaterThanOrEqual(1, $this->events('UNAUTHORIZED_ACCESS'));
    }

    public function test_teacher_cannot_touch_another_teachers_session_idor(): void
    {
        $otherUser = $this->makeUser('t2@x.test', ['TEACHER']);
        $this->pdo->prepare('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$otherUser, $this->ids['departmentId'], 'E-9', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $this->setPassword('t2@x.test');
        $otherTok = $this->login('t2@x.test');

        self::assertSame(403, $this->get("/api/v1/teacher/attendance/{$this->sessionId}", $otherTok)['status']);
        self::assertSame(403, $this->get("/api/v1/teacher/attendance/{$this->sessionId}/live", $otherTok)['status']);
        self::assertSame(403, $this->get("/api/v1/teacher/attendance/{$this->sessionId}/qr", $otherTok)['status']);
        self::assertSame(403, $this->post("/api/v1/teacher/attendance/{$this->sessionId}/close", [], $otherTok)['status']);
        self::assertGreaterThanOrEqual(1, $this->events('IDOR_ATTEMPT'));
        self::assertSame('ACTIVE', $this->pdo->query("SELECT status FROM attendance_sessions WHERE id={$this->sessionId}")->fetch()['status']);
    }

    public function test_teacher_cannot_start_a_session_for_an_unassigned_class(): void
    {
        // A second class the fixture teacher is NOT assigned to.
        $now = '2026-01-01 00:00:00';
        $this->pdo->prepare('INSERT INTO classes (program_id, academic_term_id, name, grade_level, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$this->ids['programId'], $this->ids['termId'], 'CE-9Z', 9, $now, $now]);
        $otherClass = (int) $this->pdo->lastInsertId();

        $r = $this->post('/api/v1/teacher/attendance/start', ['class_id' => $otherClass, 'course_id' => $this->ids['courseId']], $this->teacherTok);
        self::assertSame(403, $r['status']);
        self::assertGreaterThanOrEqual(1, $this->events('UNAUTHORIZED_ATTENDANCE'));
    }

    // ─── QR failure paths ─────────────────────────────────────────────────

    public function test_malformed_qr_is_rejected(): void
    {
        $r = $this->newChallenge('not-a-qrivo-qr-at-all');
        self::assertContains($r['status'], [401, 404, 409, 422]);
        self::assertSame('ACTIVE', $this->pdo->query("SELECT status FROM attendance_sessions WHERE id={$this->sessionId}")->fetch()['status']);
    }

    public function test_tampered_qr_signature_is_rejected_and_logged(): void
    {
        $before = $this->events('QR_INVALID');
        // flip the last character of the signature
        $tampered = substr($this->qr, 0, -1) . ($this->qr[-1] === 'a' ? 'b' : 'a');

        $r = $this->newChallenge($tampered);
        self::assertContains($r['status'], [401, 409]);
        self::assertGreaterThan($before, $this->events('QR_INVALID'));
    }

    public function test_expired_qr_is_rejected(): void
    {
        // Build a QR whose timestamp is an hour old, signed with the real secret.
        $secret = $this->pdo->query("SELECT session_secret FROM attendance_sessions WHERE id={$this->sessionId}")->fetch()['session_secret'];
        $uuid   = $this->pdo->query("SELECT uuid FROM attendance_sessions WHERE id={$this->sessionId}")->fetch()['uuid'];
        $ts     = time() - 3600;
        $nonce  = bin2hex(random_bytes(16));
        $msg    = "qrivo.v1.{$uuid}.{$ts}.{$nonce}";
        $sig    = hash_hmac('sha256', $msg, $secret);

        $r = $this->newChallenge("{$msg}.{$sig}");
        self::assertContains($r['status'], [401, 409]);
        self::assertGreaterThanOrEqual(1, $this->events('QR_EXPIRED'));
    }

    public function test_qr_nonce_replay_is_rejected(): void
    {
        $first = $this->newChallenge();
        self::assertSame(201, $first['status']);
        $replay = $this->newChallenge(); // same QR string → same nonce → same student
        self::assertSame(409, $replay['status']);
        self::assertGreaterThanOrEqual(1, $this->events('QR_REPLAY'));
    }

    // ─── Challenge failure paths ─────────────────────────────────────────

    public function test_challenge_response_with_a_wrong_nonce_is_rejected(): void
    {
        $c = $this->newChallenge();
        self::assertSame(201, $c['status']);

        $r = $this->post('/api/v1/student/attendance/verify', [
            'challenge_id' => $c['body']['data']['challenge_id'],
            'nonce'        => str_repeat('0', 64),
            'qr'           => $this->qr,
        ], $this->studentTok);

        self::assertSame(401, $r['status']);
        self::assertSame('WAITING', $this->pdo->query("SELECT status FROM attendance_records WHERE attendance_session_id={$this->sessionId} AND student_id={$this->ids['studentId']}")->fetch()['status']);
    }

    public function test_a_challenge_cannot_be_used_by_another_student(): void
    {
        // second enrolled student
        $u = $this->makeUser('stu2@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$u, $this->ids['programId'], 'S-2', 2025, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $s2 = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO student_class_assignments (student_id, class_id, academic_term_id, enrolled_at) VALUES (?,?,?,?)')->execute([$s2, $this->ids['classId'], $this->ids['termId'], '2026-01-01 00:00:00']);
        $this->pdo->prepare('INSERT INTO student_courses (student_id, course_id, class_id, academic_term_id, created_at) VALUES (?,?,?,?,?)')->execute([$s2, $this->ids['courseId'], $this->ids['classId'], $this->ids['termId'], '2026-01-01 00:00:00']);
        $this->pdo->prepare('INSERT INTO attendance_records (attendance_session_id, student_id, status, source, created_at, updated_at) VALUES (?,?,?,?,?,?)')->execute([$this->sessionId, $s2, 'WAITING', 'SYSTEM', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $this->setPassword('stu2@x.test');
        $s2Tok = $this->login('stu2@x.test');

        // S-1 gets a challenge; S-2 tries to redeem it.
        $c = $this->newChallenge(null, $this->studentTok);
        $r = $this->post('/api/v1/student/attendance/verify', [
            'challenge_id' => $c['body']['data']['challenge_id'],
            'nonce'        => $c['body']['data']['nonce'],
            'qr'           => $this->qr,
        ], $s2Tok);

        self::assertSame(403, $r['status']);
        self::assertGreaterThanOrEqual(1, $this->events('QR_REPLAY') + $this->events('CHALLENGE_INVALID') + $this->events('UNAUTHORIZED_ATTENDANCE'));
    }

    // ─── Attendance failure paths ────────────────────────────────────────

    public function test_duplicate_attendance_is_rejected(): void
    {
        $c1 = $this->newChallenge();
        $v1 = $this->post('/api/v1/student/attendance/verify', ['challenge_id' => $c1['body']['data']['challenge_id'], 'nonce' => $c1['body']['data']['nonce'], 'qr' => $this->qr], $this->studentTok);
        self::assertSame(200, $v1['status']);
        self::assertSame('PRESENT', $v1['body']['data']['status']);

        // A fresh QR + fresh challenge, but the student is already PRESENT.
        $qr2 = $this->get("/api/v1/teacher/attendance/{$this->sessionId}/qr", $this->teacherTok)['body']['data']['qr_string'];
        $c2  = $this->newChallenge($qr2);
        $v2  = $this->post('/api/v1/student/attendance/verify', ['challenge_id' => $c2['body']['data']['challenge_id'], 'nonce' => $c2['body']['data']['nonce'], 'qr' => $qr2], $this->studentTok);

        self::assertSame(409, $v2['status']);
        self::assertGreaterThanOrEqual(1, $this->events('DUPLICATE_ATTENDANCE'));
    }

    public function test_a_non_enrolled_student_cannot_get_a_challenge(): void
    {
        $u = $this->makeUser('outsider@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$u, $this->ids['programId'], 'S-OUT', 2025, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $this->setPassword('outsider@x.test');
        $outTok = $this->login('outsider@x.test');

        $r = $this->newChallenge(null, $outTok);
        self::assertContains($r['status'], [403, 404]);
        self::assertGreaterThanOrEqual(1, $this->events('UNAUTHORIZED_ATTENDANCE'));
    }

    public function test_manual_attendance_on_a_non_member_student_is_rejected(): void
    {
        $u = $this->makeUser('nonmember@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$u, $this->ids['programId'], 'S-NM', 2025, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $nm = (int) $this->pdo->lastInsertId();

        $r = $this->patch("/api/v1/teacher/attendance/{$this->sessionId}/student/{$nm}", ['status' => 'PRESENT'], $this->teacherTok);
        self::assertSame(404, $r['status']);
        self::assertGreaterThanOrEqual(1, $this->events('UNAUTHORIZED_ATTENDANCE'));
    }

    public function test_manual_attendance_on_a_cancelled_session_is_rejected(): void
    {
        $this->post("/api/v1/teacher/attendance/{$this->sessionId}/cancel", ['reason' => 'test'], $this->teacherTok);
        $r = $this->patch("/api/v1/teacher/attendance/{$this->sessionId}/student/{$this->ids['studentId']}", ['status' => 'PRESENT'], $this->teacherTok);
        self::assertSame(409, $r['status']);
    }

    // ─── Generic security ────────────────────────────────────────────────

    public function test_sql_injection_in_a_report_filter_is_neutralised(): void
    {
        [, $adminTok] = $this->adminToken();
        // A classic injection payload as a filter value.
        $r = $this->get('/api/v1/admin/reports/institution?department_id=1%20OR%201%3D1', $adminTok);
        // Rejected as a bad integer (422) — never executed.
        self::assertSame(422, $r['status']);

        // And the tables are intact.
        self::assertGreaterThan(0, (int) $this->pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c']);
    }

    public function test_xss_payload_in_a_name_is_stored_verbatim_not_executed(): void
    {
        [, $adminTok] = $this->adminToken();
        $payload = '<script>alert(1)</script>';
        $r = $this->post('/api/v1/admin/schools', ['name' => $payload, 'code' => 'XSS1'], $adminTok);

        self::assertSame(201, $r['status']);
        // Stored as data, not interpreted; no second row, no injection.
        self::assertSame($payload, $this->pdo->query("SELECT name FROM schools WHERE code='XSS1'")->fetch()['name']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) c FROM schools WHERE code='XSS1'")->fetch()['c']);
    }

    public function test_concurrent_duplicate_session_start_yields_one_active(): void
    {
        // Sequential proxy for concurrency: a second start for the same
        // (class, course, term) while one is ACTIVE is refused.
        $r = $this->post('/api/v1/teacher/attendance/start', ['class_id' => $this->ids['classId'], 'course_id' => $this->ids['courseId']], $this->teacherTok);
        self::assertSame(409, $r['status']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) c FROM attendance_sessions WHERE class_id={$this->ids['classId']} AND course_id={$this->ids['courseId']} AND status='ACTIVE'")->fetch()['c']);
    }

    public function test_challenge_rate_limit_abuse_is_throttled(): void
    {
        // config default: 10 challenge requests per (student, session) / 5 min.
        // Each of our requests reuses the same QR nonce, so it is refused as a
        // replay (409) well before the rate limit — which is itself a defence.
        $r = $this->newChallenge();
        self::assertContains($r['status'], [201, 409, 429]);

        // Hammer with distinct fresh QRs to reach the rate limiter.
        $last = 201;
        for ($i = 0; $i < 14; $i++) {
            $qr = $this->get("/api/v1/teacher/attendance/{$this->sessionId}/qr", $this->teacherTok)['body']['data']['qr_string'];
            $last = $this->newChallenge($qr)['status'];
            if ($last === 429) {
                break;
            }
        }
        self::assertSame(429, $last, 'challenge rate limit never tripped');
    }

    /** @return array{0:int, 1:string} */
    private function adminToken(): array
    {
        $id = $this->makeUser('admin@x.test', ['ADMIN']);
        $this->setPassword('admin@x.test');

        return [$id, $this->login('admin@x.test')];
    }
}
