<?php

declare(strict_types=1);

/**
 * QRIVO — demo data seeder (LOCAL DEVELOPMENT ONLY).
 *
 *   php scripts/seed.php
 *
 * Creates a realistic, ready-to-demo dataset so a human can log in and walk the
 * whole attendance flow immediately. Resolves FINAL_AUDIT F-2 / OQ-004 for local
 * development (production provisioning remains an open question).
 *
 * Safety rules enforced here:
 *   - refuses to run unless APP_ENV=local
 *   - every password_hash is generated at RUNTIME with PASSWORD_ARGON2ID;
 *     no hash and no plaintext is ever stored in the repository
 *   - the demo password comes from SEED_DEFAULT_PASSWORD in backend/.env
 *   - fully idempotent: every insert is keyed on the table's UNIQUE column, so
 *     re-running changes nothing
 *
 * It writes ONLY through the documented schema. No schema, algorithm or security
 * control is modified.
 */

require_once __DIR__ . '/_cli.php';

$config = qrivo_config();

qrivo_heading('QRIVO demo seeder');

// ─── guards ─────────────────────────────────────────────────────────────────

qrivo_require_local_env('seed.php');

$password = (string) ($_ENV['SEED_DEFAULT_PASSWORD'] ?? '');

if ($password === '') {
    qrivo_abort(
        'SEED_DEFAULT_PASSWORD is not set in backend/.env.' . PHP_EOL
        . '         Add a development password, e.g.:' . PHP_EOL
        . '             SEED_DEFAULT_PASSWORD=<choose-a-local-dev-password>' . PHP_EOL
        . '         backend/.env is gitignored, so it is never committed.'
    );
}

if (strlen($password) < 8) {
    qrivo_abort('SEED_DEFAULT_PASSWORD must be at least 8 characters.');
}

$pdo     = qrivo_database_pdo();
$dataset = require QRIVO_ROOT . '/../database/seeders/demo_dataset.php';

// The migrations must have run first.
$hasUsers = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchColumn();
if (!$hasUsers) {
    qrivo_abort('Schema not found. Run `php scripts/migrate.php` first.');
}

qrivo_line('  database : ' . $config->getString('database.database', 'qrivo'));
qrivo_line('  password : from SEED_DEFAULT_PASSWORD (hashed with Argon2id at runtime)');
qrivo_line();

// ─── helpers ────────────────────────────────────────────────────────────────

$created = ['inserted' => 0, 'existing' => 0];

/**
 * Idempotent "find by unique key, else insert" — returns the row id.
 *
 * @param array<string, mixed> $unique columns that identify the row
 * @param array<string, mixed> $data   full column set used when inserting
 */
$upsert = function (string $table, array $unique, array $data) use ($pdo, &$created): int {
    $where  = implode(' AND ', array_map(static fn (string $c): string => "`{$c}` = :{$c}", array_keys($unique)));
    $select = $pdo->prepare("SELECT `id` FROM `{$table}` WHERE {$where} LIMIT 1");
    $select->execute($unique);
    $id = $select->fetchColumn();

    if ($id !== false) {
        $created['existing']++;

        return (int) $id;
    }

    $columns      = implode(', ', array_map(static fn (string $c): string => "`{$c}`", array_keys($data)));
    $placeholders = implode(', ', array_map(static fn (string $c): string => ":{$c}", array_keys($data)));
    $pdo->prepare("INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})")->execute($data);
    $created['inserted']++;

    return (int) $pdo->lastInsertId();
};

/**
 * Idempotent course-schedule writer.
 *
 * A schedule is identified by (teacher_class_assignment_id, day_of_week) — the
 * demo dataset has exactly one slot per assignment per weekday. The start time
 * must NOT be part of the key: the "live" slot is computed from the current
 * clock, so keying on it would insert a second, overlapping row on every re-run
 * (and overlapping slots are precisely what the scheduling rules reject).
 *
 * `$refresh` re-centres an existing live slot on the current time so that
 * re-seeding hours later still leaves a schedule that covers "now".
 */
$upsertSchedule = function (int $tcaId, int $roomId, int $dow, string $start, string $end, bool $refresh) use ($pdo, &$created): void {
    $select = $pdo->prepare(
        'SELECT `id` FROM `course_schedules`
          WHERE `teacher_class_assignment_id` = :tca AND `day_of_week` = :dow LIMIT 1'
    );
    $select->execute(['tca' => $tcaId, 'dow' => $dow]);
    $id = $select->fetchColumn();

    if ($id !== false) {
        $created['existing']++;

        if ($refresh) {
            $pdo->prepare(
                'UPDATE `course_schedules`
                    SET `room_id` = :room, `start_time` = :start, `end_time` = :end
                  WHERE `id` = :id'
            )->execute(['room' => $roomId, 'start' => $start, 'end' => $end, 'id' => (int) $id]);
        }

        return;
    }

    $pdo->prepare(
        'INSERT INTO `course_schedules` (`teacher_class_assignment_id`, `room_id`, `day_of_week`, `start_time`, `end_time`)
         VALUES (:tca, :room, :dow, :start, :end)'
    )->execute(['tca' => $tcaId, 'room' => $roomId, 'dow' => $dow, 'start' => $start, 'end' => $end]);
    $created['inserted']++;
};

/** Link a user to a role (composite PK, so INSERT IGNORE is the idempotent form). */
$attachRole = function (int $userId, string $role) use ($pdo): void {
    $pdo->prepare(
        'INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
         SELECT :uid, `id` FROM `roles` WHERE `name` = :role'
    )->execute(['uid' => $userId, 'role' => $role]);
};

$uuid = static function (): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
};

/**
 * Create (or find) a user. The Argon2id hash is computed HERE, at runtime.
 * is_active / is_approved are 1 so the account can log in immediately.
 */
$makeUser = function (string $email, string $first, string $last) use ($pdo, $uuid, $password, &$created): int {
    $select = $pdo->prepare('SELECT `id` FROM `users` WHERE `email` = :e LIMIT 1');
    $select->execute(['e' => $email]);
    $id = $select->fetchColumn();

    if ($id !== false) {
        $created['existing']++;

        return (int) $id;
    }

    $pdo->prepare(
        'INSERT INTO `users` (`uuid`, `email`, `password_hash`, `first_name`, `last_name`, `is_active`, `is_approved`)
         VALUES (:uuid, :email, :hash, :first, :last, 1, 1)'
    )->execute([
        'uuid'  => $uuid(),
        'email' => $email,
        'hash'  => password_hash($password, PASSWORD_ARGON2ID), // runtime hash — never stored in the repo
        'first' => $first,
        'last'  => $last,
    ]);
    $created['inserted']++;

    return (int) $pdo->lastInsertId();
};

$now = new DateTimeImmutable('now');

// ─── 1. institutional structure ─────────────────────────────────────────────

qrivo_info('Institutional structure');

$schoolId = $upsert('schools', ['code' => $dataset['school']['code']], $dataset['school']);
$facultyId = $upsert(
    'faculties',
    ['school_id' => $schoolId, 'code' => $dataset['faculty']['code']],
    $dataset['faculty'] + ['school_id' => $schoolId],
);
$departmentId = $upsert(
    'departments',
    ['faculty_id' => $facultyId, 'code' => $dataset['department']['code']],
    $dataset['department'] + ['faculty_id' => $facultyId],
);
$programId = $upsert(
    'programs',
    ['department_id' => $departmentId, 'code' => $dataset['program']['code']],
    $dataset['program'] + ['department_id' => $departmentId],
);

qrivo_ok("school #{$schoolId} → faculty #{$facultyId} → department #{$departmentId} → program #{$programId}");

// ─── 2. academic calendar (term must be ACTIVE for attendance) ──────────────

qrivo_info('Academic calendar');

$yearId = $upsert(
    'academic_years',
    ['school_id' => $schoolId, 'name' => $dataset['academic_year']['name']],
    $dataset['academic_year'] + ['school_id' => $schoolId, 'is_active' => 1],
);
$termId = $upsert(
    'academic_terms',
    ['academic_year_id' => $yearId, 'name' => $dataset['academic_term']['name']],
    $dataset['academic_term'] + ['academic_year_id' => $yearId, 'is_active' => 1],
);

// The eligibility engine requires an ACTIVE term; make sure a pre-existing row is active.
$pdo->prepare('UPDATE `academic_terms` SET `is_active` = 1 WHERE `id` = :id')->execute(['id' => $termId]);
$pdo->prepare('UPDATE `academic_years` SET `is_active` = 1 WHERE `id` = :id')->execute(['id' => $yearId]);

qrivo_ok("academic year #{$yearId} → term #{$termId} '{$dataset['academic_term']['name']}' (ACTIVE)");

// ─── 3. rooms, courses, class ───────────────────────────────────────────────

qrivo_info('Rooms, courses and class');

$roomIds = [];
foreach ($dataset['rooms'] as $room) {
    $roomIds[] = $upsert('rooms', ['school_id' => $schoolId, 'code' => $room['code']], $room + ['school_id' => $schoolId]);
}

$courseIds = [];
foreach ($dataset['courses'] as $course) {
    $courseIds[$course['code']] = $upsert(
        'courses',
        ['department_id' => $departmentId, 'code' => $course['code']],
        $course + ['department_id' => $departmentId],
    );
}

$classId = $upsert(
    'classes',
    ['program_id' => $programId, 'academic_term_id' => $termId, 'name' => $dataset['class']['name']],
    $dataset['class'] + ['program_id' => $programId, 'academic_term_id' => $termId],
);

qrivo_ok(count($roomIds) . ' room(s), ' . count($courseIds) . " course(s), class #{$classId} '{$dataset['class']['name']}'");

// ─── 4. accounts ────────────────────────────────────────────────────────────

qrivo_info('User accounts (Argon2id hashed at runtime)');

/** @var list<array{email:string, role:string, name:string}> $logins */
$logins = [];

foreach ($dataset['staff'] as $person) {
    $userId = $makeUser($person['email'], $person['first_name'], $person['last_name']);
    $attachRole($userId, $person['role']);
    $logins[] = ['email' => $person['email'], 'role' => $person['role'], 'name' => $person['first_name'] . ' ' . $person['last_name']];
}

$teacherIds = [];
foreach ($dataset['teachers'] as $person) {
    $userId = $makeUser($person['email'], $person['first_name'], $person['last_name']);
    $attachRole($userId, 'TEACHER');
    $teacherIds[] = $upsert(
        'teachers',
        ['user_id' => $userId],
        [
            'user_id'         => $userId,
            'department_id'   => $departmentId,
            'employee_number' => $person['employee_number'],
        ],
    );
    $logins[] = ['email' => $person['email'], 'role' => 'TEACHER', 'name' => $person['first_name'] . ' ' . $person['last_name']];
}

$studentIds = [];
foreach ($dataset['students'] as $person) {
    $userId = $makeUser($person['email'], $person['first_name'], $person['last_name']);
    $attachRole($userId, 'STUDENT');
    $studentIds[] = $upsert(
        'students',
        ['user_id' => $userId],
        [
            'user_id'         => $userId,
            'program_id'      => $programId,
            'student_number'  => $person['student_number'],
            'enrollment_year' => $dataset['enrollment_year'],
        ],
    );
    $logins[] = ['email' => $person['email'], 'role' => 'STUDENT', 'name' => $person['first_name'] . ' ' . $person['last_name']];
}

qrivo_ok(count($dataset['staff']) . ' staff, ' . count($teacherIds) . ' teacher(s), ' . count($studentIds) . ' student(s)');

// ─── 5. assignments + schedules ─────────────────────────────────────────────

qrivo_info('Course assignments and schedules');

// DayOfWeek in QRIVO is 0 = Monday … 6 = Sunday (src/Domain/Enum/DayOfWeek.php);
// PHP's 'N' is 1 = Monday … 7 = Sunday.
$todayDow = (int) $now->format('N') - 1;

// A window that certainly covers "now" on today's weekday, clamped to the day.
$windowStart = max('00:00:00', $now->modify('-1 hour')->format('H:i:s'));
$windowEnd   = min('23:59:00', $now->modify('+3 hours')->format('H:i:s'));
if ($windowEnd <= $windowStart) {          // e.g. seeded at 23:30
    $windowStart = '00:00:00';
    $windowEnd   = '23:59:00';
}

$liveSchedule = null;

foreach ($dataset['assignments'] as $assignment) {
    $courseId  = $courseIds[$assignment['course']];
    $teacherId = $teacherIds[$assignment['teacher']];
    $roomId    = $roomIds[$assignment['room']];

    // class_courses — the course is taught to this class in this term
    $upsert(
        'class_courses',
        ['class_id' => $classId, 'course_id' => $courseId, 'academic_term_id' => $termId],
        ['class_id' => $classId, 'course_id' => $courseId, 'academic_term_id' => $termId],
    );

    // teacher_courses — the teacher is responsible for the course
    $upsert(
        'teacher_courses',
        ['teacher_id' => $teacherId, 'course_id' => $courseId, 'academic_term_id' => $termId],
        ['teacher_id' => $teacherId, 'course_id' => $courseId, 'academic_term_id' => $termId],
    );

    // teacher_class_assignments — the authorization record for starting attendance
    $tcaId = $upsert(
        'teacher_class_assignments',
        ['teacher_id' => $teacherId, 'class_id' => $classId, 'course_id' => $courseId, 'academic_term_id' => $termId],
        ['teacher_id' => $teacherId, 'class_id' => $classId, 'course_id' => $courseId, 'academic_term_id' => $termId],
    );

    $isLive = $assignment['schedule'] === 'now';
    $dow    = $isLive ? $todayDow : (int) $assignment['schedule'];
    $start  = $isLive ? $windowStart : $assignment['start'];
    $end    = $isLive ? $windowEnd : $assignment['end'];

    // `$isLive` slots are re-centred on every run so the demo is always startable.
    $upsertSchedule($tcaId, $roomId, $dow, $start, $end, $isLive);

    if ($isLive) {
        $liveSchedule = [
            'course_code' => $assignment['course'],
            'course_id'   => $courseId,
            'teacher'     => $dataset['teachers'][$assignment['teacher']]['email'],
            'window'      => $start . '–' . $end,
            'dow'         => $dow,
        ];
    }
}

qrivo_ok(count($dataset['assignments']) . ' assignment(s) + schedule(s)');

// ─── 6. student enrolment (+ the derived student_courses rows, DD-005) ──────

qrivo_info('Student enrolment');

$classCourseIds = $pdo->prepare('SELECT `course_id` FROM `class_courses` WHERE `class_id` = :c AND `academic_term_id` = :t');
$classCourseIds->execute(['c' => $classId, 't' => $termId]);
$enrolledCourseIds = array_map(static fn (array $r): int => (int) $r['course_id'], $classCourseIds->fetchAll());

foreach ($studentIds as $studentId) {
    $upsert(
        'student_class_assignments',
        ['student_id' => $studentId, 'class_id' => $classId, 'academic_term_id' => $termId],
        ['student_id' => $studentId, 'class_id' => $classId, 'academic_term_id' => $termId, 'enrolled_at' => $now->format('Y-m-d H:i:s')],
    );

    // student_courses is the denormalised membership lookup (DD-005) that the
    // challenge-response step 8 check reads. Keep it in sync with the class.
    foreach ($enrolledCourseIds as $courseId) {
        $upsert(
            'student_courses',
            ['student_id' => $studentId, 'course_id' => $courseId, 'academic_term_id' => $termId],
            ['student_id' => $studentId, 'course_id' => $courseId, 'class_id' => $classId, 'academic_term_id' => $termId],
        );
    }
}

qrivo_ok(count($studentIds) . ' student(s) enrolled in ' . count($enrolledCourseIds) . ' course(s) each');

// ─── summary ────────────────────────────────────────────────────────────────

qrivo_heading('Seeded accounts');
qrivo_line('  All accounts share the password from SEED_DEFAULT_PASSWORD in backend/.env.');
qrivo_line();

$w = 26;
qrivo_line('  ' . str_pad('E-MAIL', $w) . str_pad('ROLE', 14) . 'NAME');
qrivo_line('  ' . str_repeat('─', $w + 14 + 22));

foreach ($logins as $login) {
    qrivo_line('  ' . str_pad($login['email'], $w) . str_pad($login['role'], 14) . $login['name']);
}

qrivo_line();
qrivo_line('  rows inserted: ' . $created['inserted'] . ', already present: ' . $created['existing']);

if ($liveSchedule !== null) {
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    qrivo_heading('Ready to start attendance right now');
    qrivo_line('  teacher    : ' . $liveSchedule['teacher']);
    qrivo_line('  course     : ' . $liveSchedule['course_code'] . ' (id ' . $liveSchedule['course_id'] . ')');
    qrivo_line('  class      : ' . $dataset['class']['name'] . ' (id ' . $classId . ')');
    qrivo_line('  scheduled  : ' . $days[$liveSchedule['dow']] . ' ' . $liveSchedule['window'] . '  ← covers the current time');
    qrivo_line();
    qrivo_line('  POST /api/v1/teacher/attendance/start  {"class_id": ' . $classId . ', "course_id": ' . $liveSchedule['course_id'] . '}');
}

qrivo_line();
qrivo_line(qrivo_paint('  Seeding complete.', 'green'));
qrivo_line();
