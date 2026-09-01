<?php

declare(strict_types=1);

/**
 * QRIVO — end-to-end smoke test against a RUNNING server.
 *
 *   php scripts/smoke_test.php [--base-url=http://localhost:8000]
 *
 * Walks the complete happy path over real HTTP:
 *
 *   health → admin login → discover the live schedule → teacher login
 *   → start attendance → fetch dynamic QR → student login → QR preflight
 *   → request challenge → submit challenge response → assert PRESENT
 *   → teacher live list → manual override of a second student → close session
 *   → verify the closed session and its audit trail
 *
 * Every step prints; any unexpected HTTP status or payload aborts loudly with
 * the status line and the response body. It asserts against the REAL security
 * behaviour — nothing is relaxed to make it pass.
 *
 * Prerequisites: `php scripts/migrate.php && php scripts/seed.php`, and a server
 * running (`php -S localhost:8000 -t public`).
 */

require_once __DIR__ . '/_cli.php';

$baseUrl = 'http://localhost:8000';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = rtrim(substr($arg, 11), '/');
    }
}

$apiRoot  = $baseUrl . '/api/v1';
$password = (string) ($_ENV['SEED_DEFAULT_PASSWORD'] ?? '');

if ($password === '') {
    qrivo_abort('SEED_DEFAULT_PASSWORD is not set in backend/.env — run the seeder first.');
}

qrivo_heading('QRIVO smoke test');
qrivo_line('  target : ' . $apiRoot);
qrivo_line();

$step = 0;

/**
 * Perform a request. Returns [status, decodedBody, rawBody].
 *
 * @param array<string, mixed>|null $body
 * @param array<string, string>     $headers
 * @return array{0:int, 1:array<string,mixed>, 2:string}
 */
function http_call(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $ch = curl_init($url);

    $requestHeaders = ['Accept: application/json'];
    foreach ($headers as $name => $value) {
        $requestHeaders[] = $name . ': ' . $value;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $requestHeaders,
    ]);

    if ($body !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        qrivo_abort(
            "Cannot reach {$url}" . PHP_EOL
            . '         ' . $error . PHP_EOL
            . '         Is the server running?  php -S localhost:8000 -t public'
        );
    }

    $decoded = json_decode((string) $raw, true);

    return [$status, is_array($decoded) ? $decoded : [], (string) $raw];
}

/**
 * Assert the HTTP status, or abort printing the status and the whole body.
 *
 * @param array{0:int, 1:array<string,mixed>, 2:string} $response
 * @return array<string, mixed> the `data` payload
 */
function expect_status(array $response, int $expected, string $what): array
{
    [$status, $decoded, $raw] = $response;

    if ($status !== $expected) {
        qrivo_line();
        qrivo_fail("{$what}: expected HTTP {$expected}, got HTTP {$status}");
        qrivo_line();
        qrivo_line('       Response body:');
        foreach (explode("\n", wordwrap($raw, 100, "\n", true)) as $line) {
            qrivo_line('         ' . $line);
        }
        qrivo_line();
        exit(1);
    }

    return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
}

function assert_that(bool $condition, string $what, string $detail = ''): void
{
    if (!$condition) {
        qrivo_line();
        qrivo_fail('Assertion failed: ' . $what);
        if ($detail !== '') {
            qrivo_line('       ' . $detail);
        }
        qrivo_line();
        exit(1);
    }
}

function step(string $title): void
{
    global $step;
    $step++;
    qrivo_line(qrivo_paint(str_pad(' ' . $step . '. ', 5), 'cyan') . $title);
}

function detail(string $text): void
{
    qrivo_line('       ' . qrivo_paint($text, 'grey'));
}

/** @return array{token:string, refresh:string} */
function login(string $apiRoot, string $email, string $password): array
{
    $data = expect_status(
        http_call('POST', $apiRoot . '/auth/login', ['email' => $email, 'password' => $password]),
        200,
        "login as {$email}",
    );

    assert_that(!empty($data['access_token']), 'login returned an access token');
    assert_that(!isset($data['user']['password_hash']), 'login response must not contain password_hash');

    return ['token' => (string) $data['access_token'], 'refresh' => (string) ($data['refresh_token'] ?? '')];
}

$bearer = static fn (string $token): array => ['Authorization' => 'Bearer ' . $token];

// ─── 1. health ──────────────────────────────────────────────────────────────

step('Health check');
$health = expect_status(http_call('GET', $apiRoot . '/health'), 200, 'GET /health');
assert_that(($health['database'] ?? '') === 'ok', 'database reports ok', 'health payload: ' . json_encode($health));
detail('api=' . ($health['api'] ?? '?') . '  database=' . ($health['database'] ?? '?') . '  env=' . ($health['env'] ?? '?'));

// ─── 2. admin login + discovery ─────────────────────────────────────────────

step('Log in as SUPER_ADMIN and discover the live schedule');
$admin = login($apiRoot, 'superadmin@qrivo.local', $password);

$schedules = expect_status(
    http_call('GET', $apiRoot . '/admin/course-schedules?per_page=100', null, $bearer($admin['token'])),
    200,
    'GET /admin/course-schedules',
);

// The seeder guarantees one slot on today's weekday covering the current time.
$todayDow = (int) date('N') - 1;   // QRIVO DayOfWeek: 0 = Monday … 6 = Sunday
$nowTime  = date('H:i:s');
$live     = null;

foreach ($schedules as $row) {
    if ((int) ($row['day_of_week'] ?? -1) !== $todayDow) {
        continue;
    }
    $start = str_pad((string) $row['start_time'], 8, ':00');
    $end   = str_pad((string) $row['end_time'], 8, ':00');
    if ($start <= $nowTime && $nowTime <= $end) {
        $live = $row;
        break;
    }
}

assert_that(
    $live !== null,
    'a course schedule covers the current time',
    'No slot for day_of_week=' . $todayDow . ' covering ' . $nowTime . '. Re-run `php scripts/seed.php` to re-centre the demo slot.',
);

$assignment = expect_status(
    http_call('GET', $apiRoot . '/admin/teacher-class-assignments/' . (int) $live['teacher_class_assignment_id'], null, $bearer($admin['token'])),
    200,
    'GET /admin/teacher-class-assignments/{id}',
);

$classId  = (int) $assignment['class_id'];
$courseId = (int) $assignment['course_id'];
detail("schedule #{$live['id']} {$live['day']} {$live['start_time']}–{$live['end_time']}  → class {$classId}, course {$courseId}");

// ─── 3. teacher login ───────────────────────────────────────────────────────

step('Log in as TEACHER');
$teacher = login($apiRoot, 'teacher1@qrivo.local', $password);
$me = expect_status(http_call('GET', $apiRoot . '/auth/me', null, $bearer($teacher['token'])), 200, 'GET /auth/me');
assert_that(in_array('TEACHER', (array) ($me['roles'] ?? []), true), 'teacher token carries the TEACHER role');
detail('roles: ' . implode(', ', (array) $me['roles']));

// ─── 4. start attendance session ────────────────────────────────────────────

step('Start an attendance session');
$startResponse = http_call(
    'POST',
    $apiRoot . '/teacher/attendance/start',
    ['class_id' => $classId, 'course_id' => $courseId],
    $bearer($teacher['token']),
);

if ($startResponse[0] === 409) {
    qrivo_line();
    qrivo_fail('An ACTIVE session already exists for this class/course.');
    qrivo_line('       The smoke test needs to create one. Reset the demo data with:');
    qrivo_line('           php scripts/migrate.php --fresh && php scripts/seed.php');
    qrivo_line();
    exit(1);
}

$session = expect_status($startResponse, 201, 'POST /teacher/attendance/start');
$sessionId = (int) $session['session']['id'];

assert_that(($session['session']['status'] ?? '') === 'ACTIVE', 'new session is ACTIVE');
assert_that(!array_key_exists('session_secret', $session['session']), 'session_secret is NOT exposed to clients (DD-002)');
assert_that((int) ($session['counts']['WAITING'] ?? 0) === 12, 'all 12 enrolled students initialised to WAITING', 'counts: ' . json_encode($session['counts']));
detail("session #{$sessionId}  status=ACTIVE  WAITING={$session['counts']['WAITING']}  session_secret withheld");

// ─── 5. dynamic QR ──────────────────────────────────────────────────────────

step('Fetch the dynamic QR');
$qr = expect_status(
    http_call('GET', $apiRoot . "/teacher/attendance/{$sessionId}/qr", null, $bearer($teacher['token'])),
    200,
    'GET /teacher/attendance/{id}/qr',
);

$qrString = (string) $qr['qr_string'];
assert_that(str_starts_with($qrString, 'qrivo.v1.'), 'QR uses the documented wire format');
assert_that(count(explode('.', $qrString)) === 6, 'QR has 6 dot-separated parts');
assert_that(!array_key_exists('session_secret', $qr), 'QR response does not leak session_secret');
detail('qr=' . substr($qrString, 0, 46) . '…  ttl=' . $qr['ttl_seconds'] . 's');

// ─── 6. student login ───────────────────────────────────────────────────────
//
// Two student accounts are used deliberately:
//   student01 — the CLEAN happy path. It is never used for a rejection probe,
//               because the risk engine (correctly) remembers recent security
//               events per user: a deliberate replay would raise student01 to
//               HIGH risk and turn the next run's outcome into PENDING_REVIEW.
//   student12 — the PROBE account for every intentional rejection.

step('Log in as STUDENT (clean path) and the probe account');
$student = login($apiRoot, 'student01@qrivo.local', $password);
$probe   = login($apiRoot, 'student12@qrivo.local', $password);
detail('student01 = clean happy path,  student12 = rejection probes');

// ─── 7. QR preflight (non-consuming) ────────────────────────────────────────

step('Preflight the scanned QR');
$preflight = expect_status(
    http_call('POST', $apiRoot . '/student/attendance/qr/verify', ['qr' => $qrString], $bearer($student['token'])),
    200,
    'POST /student/attendance/qr/verify',
);
assert_that(($preflight['valid'] ?? false) === true, 'QR is reported valid', json_encode($preflight));
assert_that(($preflight['reason'] ?? '') === 'VALID', 'preflight reason is VALID');
detail('valid=true  reason=VALID  session_uuid=' . substr((string) ($preflight['session_uuid'] ?? ''), 0, 8) . '…');

// ─── 8. challenge ───────────────────────────────────────────────────────────

step('Request a challenge');
$challenge = expect_status(
    http_call('POST', $apiRoot . '/student/attendance/challenge', ['qr' => $qrString], $bearer($student['token'])),
    201,
    'POST /student/attendance/challenge',
);
assert_that(!empty($challenge['challenge_id']), 'challenge_id issued');
assert_that(!empty($challenge['nonce']), 'challenge nonce issued');
detail('challenge_id=' . substr((string) $challenge['challenge_id'], 0, 8) . '…  expires_at=' . ($challenge['expires_at'] ?? '?'));

// ─── 9. challenge response → attendance transaction ─────────────────────────

step('Submit the challenge response');
$verify = expect_status(
    http_call('POST', $apiRoot . '/student/attendance/verify', [
        'challenge_id' => $challenge['challenge_id'],
        'nonce'        => $challenge['nonce'],
        'qr'           => $qrString,
    ], $bearer($student['token'])),
    200,
    'POST /student/attendance/verify',
);

assert_that(($verify['status'] ?? '') === 'PRESENT', 'attendance recorded as PRESENT', json_encode($verify));
assert_that(($verify['source'] ?? '') === 'QR', 'source is QR');
detail('status=' . $verify['status'] . '  source=' . $verify['source'] . '  risk=' . ($verify['risk']['level'] ?? '?') . '/' . ($verify['risk']['outcome'] ?? '?'));

// ─── 10. replay must be rejected (security control, not relaxed) ────────────
//
// Run on the PROBE account. The challenge is consumed (`used_at`) inside the
// transaction BEFORE risk evaluation, so the first submission consumes it
// whether risk lets the attendance through (200) or blocks it (403) — either
// way the second submission must be rejected as already-used.

step('Re-submitting a used challenge must be rejected (probe account)');
$probeChallenge = expect_status(
    http_call('POST', $apiRoot . '/student/attendance/challenge', ['qr' => $qrString], $bearer($probe['token'])),
    201,
    'POST /student/attendance/challenge (probe)',
);

$probeSubmit = static fn (): array => http_call('POST', $apiRoot . '/student/attendance/verify', [
    'challenge_id' => $probeChallenge['challenge_id'],
    'nonce'        => $probeChallenge['nonce'],
    'qr'           => $qrString,
], $bearer($probe['token']));

[$firstStatus, , $firstBody] = $probeSubmit();
assert_that(
    in_array($firstStatus, [200, 403], true),
    'first submission is accepted (200) or risk-blocked (403) — both consume the challenge',
    'got HTTP ' . $firstStatus . ': ' . $firstBody,
);

[$replayStatus, , $replayBody] = $probeSubmit();
assert_that($replayStatus === 409, 'single-use challenge replay returns HTTP 409', 'got HTTP ' . $replayStatus . ': ' . $replayBody);
detail('first=HTTP ' . $firstStatus . ', replay=HTTP 409 — challenge is single-use (used_at set in the transaction)');

// ─── 11. teacher live attendance ────────────────────────────────────────────

step('Read the teacher live attendance list');
$liveView = expect_status(
    http_call('GET', $apiRoot . "/teacher/attendance/{$sessionId}/live", null, $bearer($teacher['token'])),
    200,
    'GET /teacher/attendance/{id}/live',
);

assert_that((int) ($liveView['counters']['TOTAL'] ?? 0) === 12, 'roster covers all 12 enrolled students', json_encode($liveView['counters'] ?? []));
assert_that((int) ($liveView['counters']['PRESENT'] ?? 0) >= 1, 'the QR attendance shows up in the live counters', json_encode($liveView['counters'] ?? []));
assert_that(count($liveView['students'] ?? []) === 12, 'roster lists all 12 students');

// student01 must appear as PRESENT via QR in the teacher's roster.
$clean = null;
foreach ($liveView['students'] as $row) {
    if (($row['student_number'] ?? '') === 'STU-2025-001') {
        $clean = $row;
        break;
    }
}
assert_that($clean !== null, 'student01 appears in the teacher roster');
assert_that(($clean['status'] ?? '') === 'PRESENT', 'student01 is PRESENT in the live roster', json_encode($clean));
assert_that(($clean['source'] ?? '') === 'QR', 'student01 source is QR');
detail('TOTAL=' . $liveView['counters']['TOTAL'] . '  PRESENT=' . $liveView['counters']['PRESENT'] . '  WAITING=' . $liveView['counters']['WAITING'] . '  (student01 = PRESENT/QR)');

// ─── 12. manual override ────────────────────────────────────────────────────

step('Teacher manually marks a second student LATE');
$waiting = null;
foreach ($liveView['students'] as $row) {
    if (($row['status'] ?? '') === 'WAITING') {
        $waiting = $row;
        break;
    }
}
assert_that($waiting !== null, 'a WAITING student exists to override');

$override = expect_status(
    http_call('PATCH', $apiRoot . "/teacher/attendance/{$sessionId}/student/" . (int) $waiting['student_id'], [
        'status' => 'LATE',
        'reason' => 'Smoke test: arrived after the QR window',
    ], $bearer($teacher['token'])),
    200,
    'PATCH /teacher/attendance/{id}/student/{studentId}',
);

assert_that(($override['status'] ?? '') === 'LATE', 'status changed to LATE');
assert_that(($override['source'] ?? '') === 'MANUAL', 'source recorded as MANUAL');
assert_that(($override['previous_status'] ?? '') === 'WAITING', 'previous status captured for the audit trail');
assert_that(!empty($override['audit_id']), 'an audit_logs row id was returned');
detail('student ' . $waiting['student_number'] . ': WAITING → LATE  source=MANUAL  audit_id=' . $override['audit_id']);

// ─── 13. a student may never modify attendance ──────────────────────────────

step('A student must NOT be able to modify attendance');
[$forbiddenStatus, , $forbiddenBody] = http_call(
    'PATCH',
    $apiRoot . "/teacher/attendance/{$sessionId}/student/" . (int) $waiting['student_id'],
    ['status' => 'PRESENT'],
    $bearer($probe['token']),
);
assert_that($forbiddenStatus === 403, 'student override returns HTTP 403', 'got HTTP ' . $forbiddenStatus . ': ' . $forbiddenBody);
detail('HTTP 403 — SECURITY_RULES.md §7 upheld');

// ─── 14. close the session ──────────────────────────────────────────────────
//
// The close assertions are DERIVED from the counters immediately before the
// close, so they stay exact regardless of how many records the probe steps
// consumed: every WAITING becomes ABSENT, every settled status is untouched.

step('Close the attendance session');
$before = expect_status(
    http_call('GET', $apiRoot . "/teacher/attendance/{$sessionId}/live/counters", null, $bearer($teacher['token'])),
    200,
    'GET /teacher/attendance/{id}/live/counters',
)['counters'];

$closed = expect_status(
    http_call('POST', $apiRoot . "/teacher/attendance/{$sessionId}/close", [], $bearer($teacher['token'])),
    200,
    'POST /teacher/attendance/{id}/close',
);

$after = $closed['counts'];

assert_that(($closed['session']['status'] ?? '') === 'CLOSED', 'session is CLOSED');
assert_that((int) ($after['WAITING'] ?? -1) === 0, 'no WAITING records remain', json_encode($after));
assert_that(
    (int) $after['ABSENT'] === (int) $before['ABSENT'] + (int) $before['WAITING'],
    'every WAITING record became ABSENT',
    'before=' . json_encode($before) . ' after=' . json_encode($after),
);
assert_that((int) $after['PRESENT'] === (int) $before['PRESENT'], 'PRESENT records survived the close');
assert_that((int) $after['LATE'] === (int) $before['LATE'], 'the LATE override survived the close');
assert_that((int) $after['PRESENT'] >= 1 && (int) $after['LATE'] >= 1, 'the session ends with the QR and manual results intact');
detail(
    'WAITING ' . $before['WAITING'] . ' → 0,  ABSENT ' . $before['ABSENT'] . ' → ' . $after['ABSENT']
    . ',  PRESENT=' . $after['PRESENT'] . ',  LATE=' . $after['LATE']
);

// ─── 15. post-close submissions are blocked ─────────────────────────────────

step('Attendance submissions after close must be rejected');
[$afterStatus, , $afterBody] = http_call('POST', $apiRoot . '/student/attendance/challenge', ['qr' => $qrString], $bearer($probe['token']));
assert_that(in_array($afterStatus, [404, 409], true), 'challenge after close is rejected', 'got HTTP ' . $afterStatus . ': ' . $afterBody);

[$recloseStatus, , ] = http_call('POST', $apiRoot . "/teacher/attendance/{$sessionId}/close", [], $bearer($teacher['token']));
assert_that($recloseStatus === 409, 're-closing a CLOSED session returns HTTP 409', 'got HTTP ' . $recloseStatus);
detail('challenge → HTTP ' . $afterStatus . ',  re-close → HTTP 409');

// ─── 16. audit + security trail ─────────────────────────────────────────────

step('Verify the audit and security trail');
$audits = expect_status(
    http_call('GET', $apiRoot . '/admin/audit-logs?per_page=100', null, $bearer($admin['token'])),
    200,
    'GET /admin/audit-logs',
);

$eventTypes = array_column($audits, 'event_type');
foreach (['LOGIN_SUCCESS', 'ATTENDANCE_SESSION_STARTED', 'ATTENDANCE_RECORDED', 'ATTENDANCE_STATUS_CHANGED', 'ATTENDANCE_SESSION_CLOSED'] as $required) {
    assert_that(in_array($required, $eventTypes, true), "audit trail contains {$required}", 'saw: ' . implode(', ', array_unique($eventTypes)));
}

$events = expect_status(
    http_call('GET', $apiRoot . '/admin/security-events?per_page=100', null, $bearer($admin['token'])),
    200,
    'GET /admin/security-events',
);

// Nothing sensitive may appear anywhere in either trail.
$haystack = json_encode($audits) . json_encode($events);
foreach ([$password, $teacher['token'], $student['token'], $probe['token'], $admin['token'], $admin['refresh']] as $secret) {
    if ($secret === '') {
        continue;
    }
    assert_that(!str_contains($haystack, $secret), 'no password or raw token appears in the audit/security trail');
}

detail(count($audits) . ' audit row(s), ' . count($events) . ' security event(s), no secrets present');

// ─── done ───────────────────────────────────────────────────────────────────

qrivo_line();
qrivo_line(qrivo_paint('  SMOKE TEST PASSED', 'green') . ' — ' . $step . ' steps, full attendance lifecycle verified.');
qrivo_line();
