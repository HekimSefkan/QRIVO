<?php

declare(strict_types=1);

/**
 * QRIVO — demo dataset definition (structure only, NO secrets).
 *
 * Consumed by backend/scripts/seed.php, which does the inserting and generates
 * every `password_hash` at runtime with PASSWORD_ARGON2ID. There is deliberately
 * no password, hash, token or key in this file — the demo password comes from
 * `SEED_DEFAULT_PASSWORD` in backend/.env (gitignored).
 *
 * The shape mirrors database/docs/TABLES.md. Nothing here changes the schema.
 */

return [
    'school'     => ['name' => 'QRIVO Demo University',      'code' => 'QDU'],
    'faculty'    => ['name' => 'Faculty of Engineering',     'code' => 'ENG'],
    'department' => ['name' => 'Computer Engineering',       'code' => 'CENG'],
    'program'    => ['name' => 'Computer Engineering BSc',   'code' => 'CENG-BSC', 'duration_years' => 4],

    'academic_year' => ['name' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30'],
    'academic_term' => ['name' => 'Fall 2025', 'term_number' => 1, 'start_date' => '2025-09-01', 'end_date' => '2026-06-30'],

    'rooms' => [
        ['name' => 'Lecture Hall A', 'code' => 'A-101', 'capacity' => 120],
        ['name' => 'Lab B',          'code' => 'B-205', 'capacity' => 40],
    ],

    'courses' => [
        ['name' => 'Data Structures',        'code' => 'CENG201', 'credit_hours' => 5],
        ['name' => 'Operating Systems',      'code' => 'CENG301', 'credit_hours' => 4],
        ['name' => 'Database Systems',       'code' => 'CENG305', 'credit_hours' => 4],
    ],

    'class' => ['name' => 'CENG-2A', 'grade_level' => 2],

    // Privileged accounts. `role` maps to the seeded `roles` rows.
    'staff' => [
        ['email' => 'superadmin@qrivo.local', 'first_name' => 'Sena',  'last_name' => 'Yilmaz',  'role' => 'SUPER_ADMIN'],
        ['email' => 'admin@qrivo.local',      'first_name' => 'Adem',  'last_name' => 'Kaya',    'role' => 'ADMIN'],
    ],

    // Teachers get a `teachers` profile row in addition to the TEACHER role.
    'teachers' => [
        ['email' => 'teacher1@qrivo.local', 'first_name' => 'Elif',  'last_name' => 'Demir',  'employee_number' => 'EMP-1001'],
        ['email' => 'teacher2@qrivo.local', 'first_name' => 'Murat', 'last_name' => 'Aydin',  'employee_number' => 'EMP-1002'],
    ],

    // 12 students — all enrolled in the single demo class.
    'students' => [
        ['email' => 'student01@qrivo.local', 'first_name' => 'Ayse',    'last_name' => 'Celik',    'student_number' => 'STU-2025-001'],
        ['email' => 'student02@qrivo.local', 'first_name' => 'Burak',   'last_name' => 'Sahin',    'student_number' => 'STU-2025-002'],
        ['email' => 'student03@qrivo.local', 'first_name' => 'Ceren',   'last_name' => 'Ozturk',   'student_number' => 'STU-2025-003'],
        ['email' => 'student04@qrivo.local', 'first_name' => 'Deniz',   'last_name' => 'Arslan',   'student_number' => 'STU-2025-004'],
        ['email' => 'student05@qrivo.local', 'first_name' => 'Emre',    'last_name' => 'Dogan',    'student_number' => 'STU-2025-005'],
        ['email' => 'student06@qrivo.local', 'first_name' => 'Fatma',   'last_name' => 'Kilic',    'student_number' => 'STU-2025-006'],
        ['email' => 'student07@qrivo.local', 'first_name' => 'Gokhan',  'last_name' => 'Aslan',    'student_number' => 'STU-2025-007'],
        ['email' => 'student08@qrivo.local', 'first_name' => 'Hande',   'last_name' => 'Yildiz',   'student_number' => 'STU-2025-008'],
        ['email' => 'student09@qrivo.local', 'first_name' => 'Ibrahim', 'last_name' => 'Korkmaz',  'student_number' => 'STU-2025-009'],
        ['email' => 'student10@qrivo.local', 'first_name' => 'Jale',    'last_name' => 'Erdogan',  'student_number' => 'STU-2025-010'],
        ['email' => 'student11@qrivo.local', 'first_name' => 'Kemal',   'last_name' => 'Polat',    'student_number' => 'STU-2025-011'],
        ['email' => 'student12@qrivo.local', 'first_name' => 'Leyla',   'last_name' => 'Simsek',   'student_number' => 'STU-2025-012'],
    ],

    'enrollment_year' => 2025,

    /*
     * Course → teacher wiring. `course` is the course code above.
     *   teacher       — index into `teachers`
     *   room          — index into `rooms`
     *   schedule      — 'now'  => a window around the current time on today's
     *                            weekday, so "start attendance" works immediately
     *                   int    => a fixed DayOfWeek (0 = Monday … 6 = Sunday)
     *   start / end   — only used for the fixed-day entries
     */
    'assignments' => [
        ['course' => 'CENG201', 'teacher' => 0, 'room' => 0, 'schedule' => 'now'],
        ['course' => 'CENG301', 'teacher' => 0, 'room' => 1, 'schedule' => 2, 'start' => '13:00:00', 'end' => '15:00:00'],
        ['course' => 'CENG305', 'teacher' => 1, 'room' => 1, 'schedule' => 4, 'start' => '09:00:00', 'end' => '11:00:00'],
    ],
];
