<?php

declare(strict_types=1);

namespace QRIVO\Tests\Support;

use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Authorization\RolePermissionMap;
use QRIVO\Infrastructure\Config\Config;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;

/**
 * In-memory SQLite schema for the Phase 8 academic-structure tests.
 *
 * Mirrors migrations 001 + 002 + 003 closely enough to exercise the repositories
 * and services, including foreign keys (PRAGMA foreign_keys = ON) so the
 * database-level RESTRICT behaviour is under test alongside the application-level
 * relationship checks.
 */
trait AcademicSchemaTrait
{
    private \PDO $pdo;

    private function buildAcademicDb(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL DEFAULT 'x',
                first_name TEXT NOT NULL DEFAULT 'Test',
                last_name TEXT NOT NULL DEFAULT 'User',
                is_active INTEGER NOT NULL DEFAULT 1,
                is_approved INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00',
                updated_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00',
                deleted_at TEXT
            );
            CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL DEFAULT '');
            CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL DEFAULT '');
            CREATE TABLE role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, PRIMARY KEY (role_id, permission_id));
            CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00', PRIMARY KEY (user_id, role_id));
            CREATE TABLE security_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, severity TEXT NOT NULL,
                user_id INTEGER, attendance_session_id INTEGER, ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT NOT NULL
            );
            CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, actor_user_id INTEGER,
                target_entity TEXT NOT NULL, target_id INTEGER, old_value TEXT, new_value TEXT, reason TEXT, ip_address TEXT, created_at TEXT NOT NULL
            );
            CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, email_attempted TEXT NOT NULL,
                ip_address TEXT NOT NULL, user_agent TEXT, success INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
            );
            CREATE TABLE device_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL,
                device_fingerprint TEXT, device_name TEXT, ip_address TEXT, user_agent TEXT,
                access_token_hash TEXT, refresh_token_hash TEXT, expires_at TEXT NOT NULL,
                last_active_at TEXT, revoked_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            );

            CREATE TABLE schools (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, code TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT
            );
            CREATE TABLE faculties (
                id INTEGER PRIMARY KEY AUTOINCREMENT, school_id INTEGER NOT NULL, name TEXT NOT NULL, code TEXT NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                UNIQUE (school_id, code),
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT
            );
            CREATE TABLE departments (
                id INTEGER PRIMARY KEY AUTOINCREMENT, faculty_id INTEGER NOT NULL, name TEXT NOT NULL, code TEXT NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                UNIQUE (faculty_id, code),
                FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE RESTRICT
            );
            CREATE TABLE programs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, department_id INTEGER NOT NULL, name TEXT NOT NULL, code TEXT NOT NULL,
                duration_years INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                UNIQUE (department_id, code),
                FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
            );
            CREATE TABLE rooms (
                id INTEGER PRIMARY KEY AUTOINCREMENT, school_id INTEGER NOT NULL, name TEXT NOT NULL, code TEXT NOT NULL,
                capacity INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                UNIQUE (school_id, code),
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT
            );
            CREATE TABLE courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT, department_id INTEGER NOT NULL, name TEXT NOT NULL, code TEXT NOT NULL,
                credit_hours INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                UNIQUE (department_id, code),
                FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
            );
            CREATE TABLE academic_years (
                id INTEGER PRIMARY KEY AUTOINCREMENT, school_id INTEGER NOT NULL, name TEXT NOT NULL,
                start_date TEXT NOT NULL, end_date TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                UNIQUE (school_id, name),
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT
            );
            CREATE TABLE academic_terms (
                id INTEGER PRIMARY KEY AUTOINCREMENT, academic_year_id INTEGER NOT NULL, name TEXT NOT NULL,
                term_number INTEGER NOT NULL, start_date TEXT NOT NULL, end_date TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT
            );
            CREATE TABLE classes (
                id INTEGER PRIMARY KEY AUTOINCREMENT, program_id INTEGER NOT NULL, academic_term_id INTEGER NOT NULL,
                name TEXT NOT NULL, grade_level INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT,
                FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT
            );
            CREATE TABLE teachers (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL UNIQUE, department_id INTEGER NOT NULL,
                employee_number TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
            );
            CREATE TABLE students (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL UNIQUE, program_id INTEGER NOT NULL,
                student_number TEXT NOT NULL UNIQUE, enrollment_year INTEGER NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT
            );

            CREATE TABLE class_courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT, class_id INTEGER NOT NULL, course_id INTEGER NOT NULL,
                academic_term_id INTEGER NOT NULL, created_at TEXT NOT NULL,
                UNIQUE (class_id, course_id, academic_term_id),
                FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
                FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT
            );
            CREATE TABLE teacher_courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT, teacher_id INTEGER NOT NULL, course_id INTEGER NOT NULL,
                academic_term_id INTEGER NOT NULL, created_at TEXT NOT NULL,
                UNIQUE (teacher_id, course_id, academic_term_id),
                FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
                FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT
            );
            CREATE TABLE teacher_class_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT, teacher_id INTEGER NOT NULL, class_id INTEGER NOT NULL,
                course_id INTEGER NOT NULL, academic_term_id INTEGER NOT NULL, created_at TEXT NOT NULL,
                UNIQUE (teacher_id, class_id, course_id, academic_term_id),
                FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
                FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
                FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT
            );
            CREATE TABLE student_class_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER NOT NULL, class_id INTEGER NOT NULL,
                academic_term_id INTEGER NOT NULL, enrolled_at TEXT NOT NULL,
                UNIQUE (student_id, class_id, academic_term_id),
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
                FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
                FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT
            );
            CREATE TABLE student_courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER NOT NULL, course_id INTEGER NOT NULL,
                class_id INTEGER NOT NULL, academic_term_id INTEGER NOT NULL, created_at TEXT NOT NULL,
                UNIQUE (student_id, course_id, academic_term_id),
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
                FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
                FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT
            );
            CREATE TABLE course_schedules (
                id INTEGER PRIMARY KEY AUTOINCREMENT, teacher_class_assignment_id INTEGER NOT NULL, room_id INTEGER NOT NULL,
                day_of_week INTEGER NOT NULL, start_time TEXT NOT NULL, end_time TEXT NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                FOREIGN KEY (teacher_class_assignment_id) REFERENCES teacher_class_assignments(id) ON DELETE RESTRICT,
                FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT
            );
            CREATE TABLE attendance_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE,
                course_id INTEGER NOT NULL, class_id INTEGER NOT NULL, teacher_id INTEGER NOT NULL,
                room_id INTEGER NOT NULL, academic_term_id INTEGER NOT NULL,
                start_time TEXT NOT NULL, end_time TEXT, expires_at TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'ACTIVE', session_secret TEXT NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
                FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT,
                FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
                FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT,
                FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT
            );
            CREATE TABLE attendance_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT, attendance_session_id INTEGER NOT NULL, student_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'WAITING', source TEXT NOT NULL DEFAULT 'SYSTEM', marked_at TEXT,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                UNIQUE (attendance_session_id, student_id),
                FOREIGN KEY (attendance_session_id) REFERENCES attendance_sessions(id) ON DELETE RESTRICT,
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT
            );
        SQL);

        // Seed roles/permissions from the canonical map.
        foreach (array_keys(RolePermissionMap::all()) as $roleName) {
            $pdo->prepare('INSERT INTO roles (name, display_name) VALUES (?, ?)')->execute([$roleName, $roleName]);
        }
        $allPermissions = [];
        foreach (RolePermissionMap::all() as $perms) {
            foreach ($perms as $p) {
                $allPermissions[$p] = true;
            }
        }
        foreach (array_keys($allPermissions) as $permName) {
            $pdo->prepare('INSERT INTO permissions (name, display_name) VALUES (?, ?)')->execute([$permName, $permName]);
        }
        foreach (RolePermissionMap::all() as $roleName => $perms) {
            foreach ($perms as $permName) {
                $pdo->prepare(
                    'INSERT INTO role_permissions (role_id, permission_id)
                     SELECT (SELECT id FROM roles WHERE name = ?), (SELECT id FROM permissions WHERE name = ?)'
                )->execute([$roleName, $permName]);
            }
        }

        return $pdo;
    }

    private function buildConnection(): Connection
    {
        $connection = new Connection(new Config(QRIVO_ROOT));
        $ref        = new \ReflectionClass($connection);
        $prop       = $ref->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($connection, $this->pdo);

        return $connection;
    }

    private function securityLogService(Connection $db): SecurityLogService
    {
        return new SecurityLogService(
            $this->createMock(\QRIVO\Domain\Contract\LoggerInterface::class),
            new SecurityEventRepository($db),
            new AuditLogRepository($db),
        );
    }

    /**
     * @param string[] $roleNames
     * @return int user id
     */
    private function makeUser(string $email, array $roleNames = []): int
    {
        $this->pdo->prepare('INSERT INTO users (uuid, email) VALUES (?, ?)')
            ->execute([bin2hex(random_bytes(6)) . '-u', $email]);
        $uid = (int) $this->pdo->lastInsertId();
        foreach ($roleNames as $role) {
            $this->pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = ?))'
            )->execute([$uid, $role]);
        }
        return $uid;
    }

    /**
     * @param string[] $roleNames
     * @return array<string, mixed>
     */
    private function actor(int $userId = 1, array $roleNames = ['ADMIN']): array
    {
        return [
            'user_id'    => $userId,
            'uuid'       => 'uuid-' . $userId,
            'email'      => 'admin@x.test',
            'first_name' => 'Ada',
            'last_name'  => 'Admin',
            'roles'      => $roleNames,
            'session_id' => 1,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'PHPUnit',
        ];
    }

    /**
     * Issue a usable access token for a user by writing a device_sessions row
     * (token stored as a SHA-256 hash, exactly like AuthService).
     */
    private function issueToken(int $userId): string
    {
        $raw = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO device_sessions (uuid, user_id, access_token_hash, refresh_token_hash, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            bin2hex(random_bytes(8)) . '-s',
            $userId,
            hash('sha256', $raw),
            hash('sha256', $raw . '-r'),
            date('Y-m-d H:i:s', time() + 3600),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
        ]);

        return $raw;
    }

    /** Seed schools→…→academic_terms and return a bag of ids. */
    private function seedHierarchy(): array
    {
        $now = '2026-01-01 00:00:00';
        $ins = fn (string $sql, array $p) => $this->pdo->prepare($sql)->execute($p);

        $ins('INSERT INTO schools (name, code, created_at, updated_at) VALUES (?, ?, ?, ?)', ['Main', 'MAIN', $now, $now]);
        $schoolId = (int) $this->pdo->lastInsertId();
        $ins('INSERT INTO faculties (school_id, name, code, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$schoolId, 'Eng', 'ENG', $now, $now]);
        $facultyId = (int) $this->pdo->lastInsertId();
        $ins('INSERT INTO departments (faculty_id, name, code, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$facultyId, 'CE', 'CE', $now, $now]);
        $departmentId = (int) $this->pdo->lastInsertId();
        $ins('INSERT INTO programs (department_id, name, code, duration_years, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$departmentId, 'CE BSc', 'CEBSC', 4, $now, $now]);
        $programId = (int) $this->pdo->lastInsertId();
        $ins('INSERT INTO academic_years (school_id, name, start_date, end_date, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)', [$schoolId, '2025-2026', '2025-09-01', '2026-06-30', $now, $now]);
        $yearId = (int) $this->pdo->lastInsertId();
        $ins('INSERT INTO academic_terms (academic_year_id, name, term_number, start_date, end_date, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)', [$yearId, 'Fall 2025', 1, '2025-09-01', '2026-01-15', $now, $now]);
        $termId = (int) $this->pdo->lastInsertId();

        return compact('schoolId', 'facultyId', 'departmentId', 'programId', 'yearId', 'termId');
    }

    /**
     * On top of seedHierarchy(): a course, a room, a class, a teacher (+user),
     * a student (+user). Returns the id bag merged with the hierarchy ids.
     *
     * @return array<string, int>
     */
    private function seedSchedulingFixtures(): array
    {
        $ids = $this->seedHierarchy();
        $now = '2026-01-01 00:00:00';
        $ins = fn (string $sql, array $p) => $this->pdo->prepare($sql)->execute($p);

        $ins('INSERT INTO courses (department_id, name, code, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$ids['departmentId'], 'Data Structures', 'DS101', $now, $now]);
        $ids['courseId'] = (int) $this->pdo->lastInsertId();

        $ins('INSERT INTO rooms (school_id, name, code, capacity, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$ids['schoolId'], 'Hall A', 'A1', 100, $now, $now]);
        $ids['roomId'] = (int) $this->pdo->lastInsertId();

        $ins('INSERT INTO classes (program_id, academic_term_id, name, grade_level, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$ids['programId'], $ids['termId'], 'CE-1A', 1, $now, $now]);
        $ids['classId'] = (int) $this->pdo->lastInsertId();

        $teacherUser = $this->makeUser('teacher@x.test', ['TEACHER']);
        $ids['teacherUserId'] = $teacherUser;
        $ins('INSERT INTO teachers (user_id, department_id, employee_number, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$teacherUser, $ids['departmentId'], 'E-1', $now, $now]);
        $ids['teacherId'] = (int) $this->pdo->lastInsertId();

        $studentUser = $this->makeUser('student@x.test', ['STUDENT']);
        $ids['studentUserId'] = $studentUser;
        $ins('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$studentUser, $ids['programId'], 'S-1', 2025, $now, $now]);
        $ids['studentId'] = (int) $this->pdo->lastInsertId();

        return $ids;
    }

    /**
     * On top of seedSchedulingFixtures(): a class_course + teacher_course +
     * teacher_class_assignment + one course_schedule slot. Returns the tca id.
     * $this->ids is updated with `tcaId` and `scheduleId`.
     */
    private function wireAssignmentAndSchedule(int $dayOfWeek = 0, string $start = '09:00:00', string $end = '11:00:00'): int
    {
        $now = '2026-01-01 00:00:00';
        $i   = $this->ids;
        $this->pdo->prepare('INSERT INTO class_courses (class_id, course_id, academic_term_id, created_at) VALUES (?,?,?,?)')->execute([$i['classId'], $i['courseId'], $i['termId'], $now]);
        $this->pdo->prepare('INSERT INTO teacher_courses (teacher_id, course_id, academic_term_id, created_at) VALUES (?,?,?,?)')->execute([$i['teacherId'], $i['courseId'], $i['termId'], $now]);
        $this->pdo->prepare('INSERT INTO teacher_class_assignments (teacher_id, class_id, course_id, academic_term_id, created_at) VALUES (?,?,?,?,?)')->execute([$i['teacherId'], $i['classId'], $i['courseId'], $i['termId'], $now]);
        $tcaId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO course_schedules (teacher_class_assignment_id, room_id, day_of_week, start_time, end_time, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')->execute([$tcaId, $i['roomId'], $dayOfWeek, $start, $end, $now, $now]);

        $this->ids['tcaId']      = $tcaId;
        $this->ids['scheduleId'] = (int) $this->pdo->lastInsertId();

        return $tcaId;
    }

    /**
     * Enrol $n students in ($classId, $termId). $this->ids['studentId'] (from the
     * fixtures) is enrolled as #1. Returns the list of student ids.
     *
     * @return int[]
     */
    private function enrolStudents(int $n): array
    {
        $now = '2026-01-01 00:00:00';
        $ids = [];
        for ($k = 1; $k <= $n; $k++) {
            if ($k === 1) {
                $sid = $this->ids['studentId'];
            } else {
                $u = $this->makeUser("student{$k}@x.test", ['STUDENT']);
                $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
                    ->execute([$u, $this->ids['programId'], "S-{$k}", 2025, $now, $now]);
                $sid = (int) $this->pdo->lastInsertId();
            }
            $this->pdo->prepare('INSERT INTO student_class_assignments (student_id, class_id, academic_term_id, enrolled_at) VALUES (?,?,?,?)')
                ->execute([$sid, $this->ids['classId'], $this->ids['termId'], $now]);
            $ids[] = $sid;
        }

        return $ids;
    }

    /** A DateTimeImmutable on a Monday at $time (schedules default to day 0 = Monday). */
    private function mondayAt(string $time = '10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable("2026-03-02 {$time}"); // 2026-03-02 is a Monday
    }

    private function scheduleRepo(): \QRIVO\Infrastructure\Repository\ScheduleRepository
    {
        return new \QRIVO\Infrastructure\Repository\ScheduleRepository($this->buildConnection());
    }

    private function referenceRepo(): \QRIVO\Infrastructure\Repository\ReferenceRepository
    {
        return new \QRIVO\Infrastructure\Repository\ReferenceRepository($this->buildConnection());
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) c FROM {$table}")->fetch()['c'];
    }

    private function securityEventCount(?string $eventType = null): int
    {
        if ($eventType === null) {
            return $this->rowCount('security_events');
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM security_events WHERE event_type = ?');
        $stmt->execute([$eventType]);
        return (int) $stmt->fetch()['c'];
    }
}
