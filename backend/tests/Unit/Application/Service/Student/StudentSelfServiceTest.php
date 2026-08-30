<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Application\Service\Student;

use PHPUnit\Framework\TestCase;
use QRIVO\Application\Service\Student\StudentSelfService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\StudentSelfRepository;
use QRIVO\Tests\Support\AcademicSchemaTrait;

/**
 * Student self-service (PROJECT_SPECIFICATION.md §6.11) — profile, schedule,
 * attendance history, dashboard. Only the caller's own data is ever returned.
 */
final class StudentSelfServiceTest extends TestCase
{
    use AcademicSchemaTrait;

    /** @var array<string, int> */
    private array $ids;
    /** @var array{id:int, uuid:string, secret:string} */
    private array $session;

    protected function setUp(): void
    {
        $this->pdo     = $this->buildAcademicDb();
        $this->ids     = $this->seedSchedulingFixtures();
        $this->wireAssignmentAndSchedule(0, '09:00:00', '11:00:00'); // Monday 09-11 for the fixture class
        $this->enrolFixtureStudent();
        $this->session = $this->insertSession('ACTIVE');
        $this->attendanceRecord($this->session['id'], $this->ids['studentId'], 'PRESENT', 'QR', '2026-03-02 09:05:00');
    }

    private function service(): StudentSelfService
    {
        $db = $this->buildConnection();
        return new StudentSelfService(
            $this->createMock(LoggerInterface::class),
            new StudentSelfRepository($db),
            new RelationshipRepository($db),
        );
    }

    private function student(): array
    {
        return $this->actor($this->ids['studentUserId'], ['STUDENT']);
    }

    public function test_profile_returns_own_identity_and_student_fields(): void
    {
        $p = $this->service()->profile($this->student());

        $this->assertSame('S-1', $p['student_number']);
        $this->assertSame(2025, $p['enrollment_year']);
        $this->assertSame($this->ids['programId'], $p['program_id']);
        $this->assertSame('student@x.test', $p['email']);
        $this->assertContains('STUDENT', $p['roles']);
    }

    public function test_profile_rejects_non_student_account(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->service()->profile($this->actor($this->ids['teacherUserId'], ['TEACHER']));
    }

    public function test_schedule_returns_the_students_class_meetings(): void
    {
        $s = $this->service()->schedule($this->student());

        $this->assertCount(1, $s['schedule']);
        $slot = $s['schedule'][0];
        $this->assertSame($this->ids['courseId'], $slot['course_id']);
        $this->assertSame('Monday', $slot['day']);
        $this->assertSame('09:00', $slot['start_time']);
        $this->assertSame('11:00', $slot['end_time']);
    }

    public function test_schedule_is_empty_for_a_student_with_no_enrollment(): void
    {
        $u = $this->makeUser('lonely@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$u, $this->ids['programId'], 'S-LONELY', 2025, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        $this->assertSame([], $this->service()->schedule($this->actor($u, ['STUDENT']))['schedule']);
    }

    public function test_attendance_history_returns_own_records_paginated(): void
    {
        // add a second, older session + record for the same student
        $older = $this->insertSession('CLOSED');
        $this->pdo->exec("UPDATE attendance_sessions SET start_time='2025-12-01 09:00:00' WHERE id={$older['id']}");
        $this->attendanceRecord($older['id'], $this->ids['studentId'], 'ABSENT', 'MANUAL', '2025-12-01 09:10:00');

        $h = $this->service()->attendanceHistory($this->student(), ['per_page' => 1, 'page' => 1]);

        $this->assertCount(1, $h['history']);
        $this->assertSame(2, $h['meta']['total']);
        $this->assertSame(2, $h['meta']['total_pages']);
        // newest first
        $this->assertSame('PRESENT', $h['history'][0]['status']);
        $this->assertSame('QR', $h['history'][0]['source']);
        $this->assertSame($this->session['id'], $h['history'][0]['attendance_session_id']);

        $page2 = $this->service()->attendanceHistory($this->student(), ['per_page' => 1, 'page' => 2]);
        $this->assertSame('ABSENT', $page2['history'][0]['status']);
    }

    public function test_attendance_history_never_leaks_another_students_records(): void
    {
        $otherUser = $this->makeUser('other@x.test', ['STUDENT']);
        $this->pdo->prepare('INSERT INTO students (user_id, program_id, student_number, enrollment_year, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$otherUser, $this->ids['programId'], 'S-OTHER', 2025, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $otherStudentId = (int) $this->pdo->lastInsertId();
        $this->attendanceRecord($this->session['id'], $otherStudentId, 'LATE', 'MANUAL', '2026-03-02 09:30:00');

        $mine = $this->service()->attendanceHistory($this->student(), []);
        $this->assertCount(1, $mine['history']);
        $this->assertSame('PRESENT', $mine['history'][0]['status']);

        $theirs = $this->service()->attendanceHistory($this->actor($otherUser, ['STUDENT']), []);
        $this->assertCount(1, $theirs['history']);
        $this->assertSame('LATE', $theirs['history'][0]['status']);
    }

    public function test_dashboard_aggregates_profile_today_summary_and_recent(): void
    {
        $d = $this->service()->dashboard($this->student());

        $this->assertSame('S-1', $d['profile']['student_number']);
        $this->assertSame(['PRESENT' => 1], $d['attendance_summary']);
        $this->assertCount(1, $d['recent_attendance']);
        $this->assertArrayHasKey('today_schedule', $d);
        $this->assertIsArray($d['today_schedule']);
    }
}
