<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Teacher;

use QRIVO\Application\Service\BaseService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\DayOfWeek;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\TeacherSelfRepository;

/**
 * A teacher's own data for the web panel (PROJECT_SPECIFICATION.md §12):
 * profile, weekly schedule, today's lessons, the active session, recent
 * sessions and overall totals.
 *
 * Mirrors {@see \QRIVO\Application\Service\Student\StudentSelfService}. The
 * backend stays authoritative — the web client only renders these payloads and
 * makes no authorization decision of its own.
 *
 * `teachers.id` is always resolved from the authenticated token; it can never be
 * supplied by the caller. Every row returned belongs to that teacher's own
 * `teacher_class_assignments` / `attendance_sessions` (spec §6.2:
 * "Teacher: only assigned courses and classes"). Added in Phase 25 — see
 * docs/ACCEPTED_DEVIATIONS.md AD-018.
 */
final class TeacherSelfService extends BaseService
{
    private const RECENT_SESSION_LIMIT = 10;

    public function __construct(
        LoggerInterface $logger,
        private readonly TeacherSelfRepository $self,
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
            throw new ForbiddenException('This account is not a teacher account.');
        }

        return [
            'uuid'            => (string) $row['uuid'],
            'email'           => (string) $row['email'],
            'first_name'      => (string) $row['first_name'],
            'last_name'       => (string) $row['last_name'],
            'employee_number' => (string) $row['employee_number'],
            'department_id'   => (int) $row['department_id'],
            'roles'           => is_array($actor['roles'] ?? null) ? array_values($actor['roles']) : [],
        ];
    }

    /**
     * The teacher's full weekly schedule.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function schedule(array $actor): array
    {
        return ['schedule' => $this->scheduleRows($this->teacherId($actor))];
    }

    /**
     * Dashboard aggregate — exactly the four blocks the specification names:
     * today's lessons, the active session, recent sessions, overall totals.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function dashboard(array $actor, ?\DateTimeImmutable $at = null): array
    {
        $at      ??= new \DateTimeImmutable('now');
        $teacherId = $this->teacherId($actor);

        $schedule = $this->scheduleRows($teacherId);
        $todayDow = DayOfWeek::fromDateTime($at)->value;
        $now      = $at->format('H:i:s');

        $today = [];
        foreach ($schedule as $slot) {
            if ($slot['day_of_week'] !== $todayDow) {
                continue;
            }
            // `is_now` lets the client show the "YOKLAMA BAŞLAT" button first;
            // the SERVER still decides via /teacher/attendance/eligibility.
            $slot['is_now'] = $slot['start_time'] <= substr($now, 0, 5) && substr($now, 0, 5) <= $slot['end_time'];
            $today[] = $slot;
        }

        return [
            'profile'         => $this->profile($actor),
            'today_schedule'  => $today,
            'active_sessions' => $this->shapeSessions($this->self->sessionsForTeacher($teacherId, ['ACTIVE'], 10)),
            'recent_sessions' => $this->shapeSessions($this->self->sessionsForTeacher($teacherId, ['CLOSED', 'CANCELLED'], self::RECENT_SESSION_LIMIT)),
            'totals'          => $this->self->totalsForTeacher($teacherId),
            'server_time'     => $at->format('c'),
        ];
    }

    // ─── internals ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $actor */
    private function teacherId(array $actor): int
    {
        $id = $this->relationships->findTeacherIdForUser((int) $actor['user_id']);
        if ($id === null) {
            throw new ForbiddenException('This account is not a teacher account.');
        }

        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scheduleRows(int $teacherId): array
    {
        return array_map(static function (array $r): array {
            $dow = (int) $r['day_of_week'];

            return [
                'course_schedule_id'          => (int) $r['course_schedule_id'],
                'teacher_class_assignment_id' => (int) $r['teacher_class_assignment_id'],
                'course_id'                   => (int) $r['course_id'],
                'course_name'                 => (string) $r['course_name'],
                'course_code'                 => (string) $r['course_code'],
                'class_id'                    => (int) $r['class_id'],
                'class_name'                  => (string) $r['class_name'],
                'room_id'                     => (int) $r['room_id'],
                'room_name'                   => (string) $r['room_name'],
                'room_code'                   => (string) $r['room_code'],
                'academic_term_id'            => (int) $r['academic_term_id'],
                'academic_term_name'          => (string) $r['academic_term_name'],
                'term_is_active'              => (bool) $r['term_is_active'],
                'day_of_week'                 => $dow,
                'day'                         => DayOfWeek::tryFrom($dow)?->label() ?? '',
                'start_time'                  => substr((string) $r['start_time'], 0, 5),
                'end_time'                    => substr((string) $r['end_time'], 0, 5),
            ];
        }, $this->self->scheduleForTeacher($teacherId));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function shapeSessions(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'id'               => (int) $r['id'],
            'uuid'             => (string) $r['uuid'],
            'status'           => (string) $r['status'],
            'start_time'       => (string) $r['start_time'],
            'end_time'         => $r['end_time'] ?? null,
            'expires_at'       => (string) $r['expires_at'],
            'course_id'        => (int) $r['course_id'],
            'course_name'      => (string) $r['course_name'],
            'course_code'      => (string) $r['course_code'],
            'class_id'         => (int) $r['class_id'],
            'class_name'       => (string) $r['class_name'],
            'room_id'          => (int) $r['room_id'],
            'room_name'        => (string) $r['room_name'],
            'academic_term_id' => (int) $r['academic_term_id'],
            'counts'           => [
                'TOTAL'          => (int) $r['total'],
                'PRESENT'        => (int) $r['present'],
                'ABSENT'         => (int) $r['absent'],
                'LATE'           => (int) $r['late'],
                'EXCUSED'        => (int) $r['excused'],
                'WAITING'        => (int) $r['waiting'],
                'PENDING_REVIEW' => (int) $r['pending_review'],
            ],
        ], $rows);
    }
}
