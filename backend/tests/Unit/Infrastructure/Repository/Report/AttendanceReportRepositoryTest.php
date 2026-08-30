<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Infrastructure\Repository\Report;

use PHPUnit\Framework\TestCase;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\Report\AttendanceReportRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Aggregation maths for the attendance reports (PROJECT_SPECIFICATION.md §6.16).
 * SQLite in-memory; production uses MySQL.
 */
final class AttendanceReportRepositoryTest extends TestCase
{
    use AcademicSchemaTrait;

    private Connection $db;
    private AttendanceReportRepository $repo;
    /** @var array<string, int> */
    private array $ids;
    private int $session1;
    private int $session2;
    private int $courseB;

    protected function setUp(): void
    {
        $this->pdo  = $this->buildAcademicDb();
        $this->db   = $this->buildConnection();
        $this->repo = new AttendanceReportRepository($this->db);
        $this->ids  = $this->seedSchedulingFixtures();
        $this->wireAssignmentAndSchedule();
        $students = $this->enrolStudents(4); // S-1..S-4 in the fixture class

        // A second course in the same department.
        $this->pdo->prepare('INSERT INTO courses (department_id, name, code, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute([$this->ids['departmentId'], 'Algorithms', 'ALG201', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $this->courseB = (int) $this->pdo->lastInsertId();

        // Session 1 (course A, 2026-03-02): 3 PRESENT, 1 ABSENT.
        $this->session1 = $this->sessionOn($this->ids['courseId'], '2026-03-02 09:00:00', 'CLOSED');
        $this->attendanceRecord($this->session1, $students[0], 'PRESENT', 'QR', '2026-03-02 09:03:00');
        $this->attendanceRecord($this->session1, $students[1], 'PRESENT', 'QR', '2026-03-02 09:04:00');
        $this->attendanceRecord($this->session1, $students[2], 'PRESENT', 'MANUAL', '2026-03-02 09:20:00');
        $this->attendanceRecord($this->session1, $students[3], 'ABSENT', 'MANUAL', '2026-03-02 11:00:00');

        // Session 2 (course A, 2026-03-09): 1 PRESENT, 1 LATE, 1 EXCUSED, 1 WAITING.
        $this->session2 = $this->sessionOn($this->ids['courseId'], '2026-03-09 09:00:00', 'CLOSED');
        $this->attendanceRecord($this->session2, $students[0], 'PRESENT', 'QR', '2026-03-09 09:02:00');
        $this->attendanceRecord($this->session2, $students[1], 'LATE', 'QR', '2026-03-09 09:25:00');
        $this->attendanceRecord($this->session2, $students[2], 'EXCUSED', 'MANUAL', '2026-03-09 09:00:00');
        $this->attendanceRecord($this->session2, $students[3], 'WAITING', 'SYSTEM', null);

        // Session 3 (course B, 2026-03-03): 2 PRESENT.
        $s3 = $this->sessionOn($this->courseB, '2026-03-03 13:00:00', 'CLOSED');
        $this->attendanceRecord($s3, $students[0], 'PRESENT', 'QR', '2026-03-03 13:01:00');
        $this->attendanceRecord($s3, $students[1], 'PRESENT', 'QR', '2026-03-03 13:02:00');
    }

    private function sessionOn(int $courseId, string $startTime, string $status): int
    {
        $now = '2026-01-01 00:00:00';
        $this->pdo->prepare(
            'INSERT INTO attendance_sessions (uuid, course_id, class_id, teacher_id, room_id, academic_term_id, start_time, end_time, expires_at, status, session_secret, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,NULL,?,?,?,?,?)'
        )->execute([
            bin2hex(random_bytes(8)) . '-s', $courseId, $this->ids['classId'], $this->ids['teacherId'],
            $this->ids['roomId'], $this->ids['termId'], $startTime, '2099-01-01 00:00:00', $status,
            bin2hex(random_bytes(8)), $now, $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // ─── summary ────────────────────────────────────────────────────────────

    public function test_summary_counts_all_statuses_and_present_rate(): void
    {
        $s = $this->repo->summary([]);

        $this->assertSame(10, $s['total_records']);          // 4 + 4 + 2
        $this->assertSame(9, $s['marked_records']);          // minus 1 WAITING
        $this->assertSame(3, $s['sessions']);
        $this->assertSame(6, $s['counts']['present']);
        $this->assertSame(1, $s['counts']['absent']);
        $this->assertSame(1, $s['counts']['late']);
        $this->assertSame(1, $s['counts']['excused']);
        $this->assertSame(1, $s['counts']['waiting']);
        $this->assertSame(round(6 / 9, 4), $s['present_rate']);
    }

    public function test_summary_respects_course_filter(): void
    {
        $s = $this->repo->summary(['course_id' => $this->ids['courseId']]);
        $this->assertSame(8, $s['total_records']);
        $this->assertSame(2, $s['sessions']);
    }

    public function test_summary_respects_date_range(): void
    {
        $s = $this->repo->summary(['from' => '2026-03-09 00:00:00', 'to' => '2026-03-09 23:59:59']);
        $this->assertSame(1, $s['sessions']);
        $this->assertSame(4, $s['total_records']);
    }

    public function test_summary_respects_status_and_source_filters(): void
    {
        $this->assertSame(5, $this->repo->summary(['source' => 'QR', 'status' => 'PRESENT'])['total_records']);
        $this->assertSame(1, $this->repo->summary(['source' => 'MANUAL', 'status' => 'PRESENT'])['total_records']);
    }

    // ─── grouped summary ────────────────────────────────────────────────────

    public function test_grouped_by_course(): void
    {
        $rows = $this->repo->groupedSummary([], 'course');
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[(int) $r['key']] = $r;
        }
        $this->assertSame(4, $byKey[$this->ids['courseId']]['counts']['present']); // 3 + 1 across course-A sessions
        $this->assertSame(2, $byKey[$this->courseB]['counts']['present']);
        $this->assertSame('Data Structures', $byKey[$this->ids['courseId']]['label']);
    }

    public function test_grouped_by_status(): void
    {
        $rows = $this->repo->groupedSummary([], 'status');
        $map  = [];
        foreach ($rows as $r) {
            $map[$r['key']] = $r['total_records'];
        }
        $this->assertSame(6, $map['PRESENT']);
        $this->assertSame(1, $map['LATE']);
    }

    public function test_grouped_by_day_is_chronological(): void
    {
        $rows = $this->repo->groupedSummary(['course_id' => $this->ids['courseId']], 'day');
        $this->assertSame(['2026-03-02', '2026-03-09'], array_column($rows, 'key'));
    }

    public function test_grouped_by_student(): void
    {
        $rows = $this->repo->groupedSummary(['course_id' => $this->ids['courseId']], 'student');
        // S-1 has PRESENT + PRESENT across the two course-A sessions.
        $this->assertSame('S-1', $rows[0]['label']);
        $this->assertSame(2, $rows[0]['counts']['present']);
    }

    // ─── pagination ─────────────────────────────────────────────────────────

    public function test_paginated_sessions_newest_first_with_total(): void
    {
        $p1 = $this->repo->paginatedSessions(['course_id' => $this->ids['courseId']], 1, 1);
        $this->assertSame(2, $p1['total']);
        $this->assertCount(1, $p1['data']);
        $this->assertSame('2026-03-09 09:00:00', $p1['data'][0]['start_time']); // newest
        $this->assertSame(3, $p1['data'][0]['counts']['present'] + $p1['data'][0]['counts']['late'] + $p1['data'][0]['counts']['excused']);

        $p2 = $this->repo->paginatedSessions(['course_id' => $this->ids['courseId']], 2, 1);
        $this->assertSame('2026-03-02 09:00:00', $p2['data'][0]['start_time']);
    }

    public function test_paginated_student_breakdown(): void
    {
        $p = $this->repo->paginatedStudentBreakdown(['class_id' => $this->ids['classId']], 1, 2);
        $this->assertSame(4, $p['total']);
        $this->assertCount(2, $p['data']);
        $this->assertSame('S-1', $p['data'][0]['student_number']);
    }

    public function test_paginated_records_scoped_by_list_filters(): void
    {
        // student S-1 (ids order), restricted to course A only
        $students = $this->pdo->query('SELECT id FROM students ORDER BY id')->fetchAll();
        $s1 = (int) $students[0]['id'];

        $all   = $this->repo->paginatedRecords(['student_id' => $s1], 1, 50);
        $scoped = $this->repo->paginatedRecords(['student_id' => $s1, 'course_ids' => [$this->ids['courseId']]], 1, 50);

        $this->assertSame(3, $all['total']);    // 2 course-A + 1 course-B
        $this->assertSame(2, $scoped['total']); // course-A only
    }
}
