<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Student;

use QRIVO\Application\Service\BaseService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\DayOfWeek;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\StudentSelfRepository;

/**
 * A student's own data for the mobile app (PROJECT_SPECIFICATION.md §6.11):
 * profile, schedule, attendance history, and a dashboard aggregate.
 *
 * The backend is authoritative — the mobile client only renders these payloads.
 * Every method resolves `students.id` from the authenticated token and returns
 * only that student's rows (spec §6.2: "Student: only own data").
 */
final class StudentSelfService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        private readonly StudentSelfRepository $self,
        private readonly RelationshipRepository $relationships,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function profile(array $actor): array
    {
        $row = $this->self->profileForUser((int) $actor['user_id']);
        if ($row === null) {
            throw new ForbiddenException('This account is not a student account.');
        }

        return [
            'uuid'            => (string) $row['uuid'],
            'email'           => (string) $row['email'],
            'first_name'      => (string) $row['first_name'],
            'last_name'       => (string) $row['last_name'],
            'student_number'  => (string) $row['student_number'],
            'program_id'      => (int) $row['program_id'],
            'enrollment_year' => (int) $row['enrollment_year'],
            'roles'           => is_array($actor['roles'] ?? null) ? array_values($actor['roles']) : [],
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function schedule(array $actor): array
    {
        return ['schedule' => $this->scheduleRows($this->studentId($actor))];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $query { page?, per_page? }
     * @return array<string, mixed>
     */
    public function attendanceHistory(array $actor, array $query): array
    {
        $studentId = $this->studentId($actor);
        $page      = max(1, (int) ($query['page'] ?? 1));
        $perPage   = max(1, min(100, (int) ($query['per_page'] ?? 20)));

        $result = $this->self->attendanceHistoryForStudent($studentId, $page, $perPage);

        return [
            'history' => array_map([$this, 'shapeHistoryRow'], $result['data']),
            'meta'    => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function dashboard(array $actor): array
    {
        $studentId = $this->studentId($actor);

        $recent = $this->self->attendanceHistoryForStudent($studentId, 1, 5)['data'];

        return [
            'profile'            => $this->profile($actor),
            'today_schedule'     => $this->todaySchedule($studentId),
            'attendance_summary' => $this->self->attendanceSummaryForStudent($studentId),
            'recent_attendance'  => array_map([$this, 'shapeHistoryRow'], $recent),
        ];
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $actor
     */
    private function studentId(array $actor): int
    {
        $id = $this->relationships->findStudentIdForUser((int) $actor['user_id']);
        if ($id === null) {
            throw new ForbiddenException('This account is not a student account.');
        }

        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scheduleRows(int $studentId): array
    {
        return array_map(static function (array $r): array {
            $dow = (int) $r['day_of_week'];

            return [
                'course_id'        => (int) $r['course_id'],
                'class_id'         => (int) $r['class_id'],
                'academic_term_id' => (int) $r['academic_term_id'],
                'room_id'          => (int) $r['room_id'],
                'day_of_week'      => $dow,
                'day'              => DayOfWeek::tryFrom($dow)?->label() ?? (string) $dow,
                'start_time'       => substr((string) $r['start_time'], 0, 5),
                'end_time'         => substr((string) $r['end_time'], 0, 5),
            ];
        }, $this->self->scheduleForStudent($studentId));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function todaySchedule(int $studentId): array
    {
        $todayDow = DayOfWeek::fromDateTime(new \DateTimeImmutable('now'))->value;

        return array_values(array_filter(
            $this->scheduleRows($studentId),
            static fn (array $row): bool => $row['day_of_week'] === $todayDow,
        ));
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function shapeHistoryRow(array $r): array
    {
        return [
            'attendance_session_id' => (int) $r['attendance_session_id'],
            'course_id'             => (int) $r['course_id'],
            'class_id'              => (int) $r['class_id'],
            'academic_term_id'      => (int) $r['academic_term_id'],
            'status'                => (string) $r['status'],
            'source'                => (string) $r['source'],
            'marked_at'             => $r['marked_at'] ?? null,
            'session_start_time'    => (string) $r['session_start_time'],
            'session_status'        => (string) $r['session_status'],
        ];
    }
}
