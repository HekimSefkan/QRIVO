<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Attendance;

use QRIVO\Application\Service\AttendanceEligibilityService;
use QRIVO\Application\Service\BaseService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Application\Validation\Validator;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Entity\Attendance\AttendanceSession;
use QRIVO\Domain\Enum\AttendanceEligibilityReason;
use QRIVO\Domain\Enum\AttendanceStatus;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;
use QRIVO\Infrastructure\Repository\ScheduleRepository;

/**
 * Attendance session creation — ATTENDANCE_ALGORITHM.md §2, implemented exactly
 * and in order. This class does NOT redesign the algorithm.
 *
 * Validation sequence:
 *   1. Teacher authentication ...... performed by the controller (BaseController::authenticate)
 *   2. Teacher authorization ....... AttendanceEligibilityService (TEACHER role + teacher profile)
 *   3. Course assignment .......... AttendanceEligibilityService (teacher_class_assignments)
 *   4. Class assignment ........... AttendanceEligibilityService (teacher_class_assignments)
 *   5. Schedule validation ........ AttendanceEligibilityService (course_schedules on the day)
 *   6. Date validation ........... AttendanceEligibilityService (day_of_week of `at`)
 *   7. Time validation ........... AttendanceEligibilityService (start_time <= now <= end_time)
 *   8. Room validation ........... room is taken from the covering course_schedule; a
 *                                  caller-supplied room must match it
 *   9. Academic term validation ... resolved term must be is_active = 1
 *  10. Active session check ...... inside the transaction, with a class row lock, no
 *                                  ACTIVE session may already exist for (class, course, term)
 *
 * On success (single transaction — CONSTRAINTS.md §6):
 *   - INSERT attendance_sessions (status ACTIVE, server-generated session_secret)
 *   - INSERT one WAITING/SYSTEM attendance_records row per enrolled student
 *
 * Security:
 *   - `session_secret` is generated server-side and never returned (DD-002).
 *   - Every unauthorized attempt is recorded as a security event.
 */
final class AttendanceSessionService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        private readonly Connection $db,
        private readonly AttendanceEligibilityService $eligibility,
        private readonly AttendanceSessionRepository $sessions,
        private readonly AttendanceRecordRepository $records,
        private readonly ScheduleRepository $schedule,
        private readonly RelationshipRepository $relationships,
        private readonly SecurityLogService $securityLog,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed>   $actor  validated actor context
     * @param array<string, mixed>   $input  { class_id, course_id, [academic_term_id], [room_id] }
     * @param \DateTimeImmutable|null $at     evaluation time (defaults to now)
     * @return array{session: array<string, mixed>, counts: array<string, int>}
     */
    public function start(array $actor, array $input, ?\DateTimeImmutable $at = null): array
    {
        $at ??= new \DateTimeImmutable('now');

        (new Validator())->validate($input, [
            'class_id'         => 'required|integer',
            'course_id'        => 'required|integer',
            'academic_term_id' => 'integer',
            'room_id'          => 'integer',
        ]);

        $classId       = (int) $input['class_id'];
        $courseId      = (int) $input['course_id'];
        $requestedTerm = isset($input['academic_term_id']) && is_numeric($input['academic_term_id'])
            ? (int) $input['academic_term_id'] : null;
        $requestedRoom = isset($input['room_id']) && is_numeric($input['room_id'])
            ? (int) $input['room_id'] : null;

        // Steps 2-8 (+ term resolution) — the scheduling-authorization engine.
        $eligibility = $this->eligibility->forTeacher($actor, $classId, $courseId, $requestedTerm, $at);

        if (!$eligibility->isAuthorized()) {
            $this->recordDenied($actor, $eligibility->reason, $classId, $courseId);

            throw match ($eligibility->reason) {
                AttendanceEligibilityReason::NOT_A_TEACHER,
                AttendanceEligibilityReason::NO_TEACHER_PROFILE,
                AttendanceEligibilityReason::NOT_ASSIGNED_TO_CLASS_COURSE
                    => new ForbiddenException('You are not authorized to start an attendance session for this class.'),
                default
                    => new ConflictException($eligibility->reason->message()),
            };
        }

        $termId          = (int) $eligibility->academicTermId;
        $scheduledRoomId = (int) $eligibility->roomId;
        $scheduleEnd     = (string) ($eligibility->schedule['end_time'] ?? '');

        // Step 9 — academic term must be active.
        if (!$this->schedule->termIsActive($termId)) {
            throw new ConflictException('The academic term for this class is not active.');
        }

        // Step 8 — room is the scheduled room; a supplied room must match it.
        if ($requestedRoom !== null && $requestedRoom !== $scheduledRoomId) {
            $this->recordDenied($actor, AttendanceEligibilityReason::NOT_ASSIGNED_TO_CLASS_COURSE, $classId, $courseId);
            throw new ForbiddenException('The requested room does not match the scheduled room for this class.');
        }

        $teacherId = $this->relationships->findTeacherIdForUser((int) $actor['user_id']);
        if ($teacherId === null) {
            throw new ForbiddenException('You are not authorized to start an attendance session.');
        }

        $startTime = $at->format('Y-m-d H:i:s');
        $expiresAt = $at->format('Y-m-d') . ' ' . $this->toHms($scheduleEnd);

        // Step 10 + creation — one transaction, class row locked (CONSTRAINTS.md §6).
        $sessionId = $this->db->transaction(function () use (
            $classId, $courseId, $termId, $scheduledRoomId, $teacherId, $startTime, $expiresAt
        ): int {
            $this->sessions->lockClassRow($classId);

            if ($this->sessions->findActiveForClassCourseTerm($classId, $courseId, $termId, lock: true) !== null) {
                throw new ConflictException('An active attendance session already exists for this class and course.');
            }

            $id = $this->sessions->create([
                'uuid'             => $this->uuidV4(),
                'course_id'        => $courseId,
                'class_id'         => $classId,
                'teacher_id'       => $teacherId,
                'room_id'          => $scheduledRoomId,
                'academic_term_id' => $termId,
                'start_time'       => $startTime,
                'end_time'         => null,
                'expires_at'       => $expiresAt,
                'status'           => 'ACTIVE',
                'session_secret'   => bin2hex(random_bytes(32)),
            ]);

            $this->records->initialiseForClassEnrollment($id, $classId, $termId);

            return $id;
        });

        $this->securityLog->recordAuditLog(
            'ATTENDANCE_SESSION_STARTED',
            (int) $actor['user_id'],
            'attendance_session',
            $sessionId,
            null,
            ['class_id' => $classId, 'course_id' => $courseId, 'academic_term_id' => $termId, 'room_id' => $scheduledRoomId],
            null,
            is_string($actor['ip_address'] ?? null) ? $actor['ip_address'] : null,
        );

        return $this->present($sessionId);
    }

    /**
     * View a session the teacher owns.
     *
     * @param array<string, mixed> $actor
     * @return array{session: array<string, mixed>, counts: array<string, int>}
     */
    public function viewOwned(array $actor, int $sessionId): array
    {
        $row = $this->sessions->findRow($sessionId);
        if ($row === null) {
            throw new NotFoundException('Attendance session not found.');
        }

        $teacherId = $this->relationships->findTeacherIdForUser((int) $actor['user_id']);
        if ($teacherId === null || (int) $row['teacher_id'] !== $teacherId) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::IDOR_ATTEMPT,
                'HIGH',
                (int) $actor['user_id'],
                is_string($actor['ip_address'] ?? null) ? $actor['ip_address'] : null,
                is_string($actor['user_agent'] ?? null) ? $actor['user_agent'] : null,
                ['action' => 'view attendance session', 'attendance_session_id' => $sessionId],
            );
            throw new ForbiddenException('You are not authorized to view this attendance session.');
        }

        return $this->present($sessionId);
    }

    // ─── Internals ─────────────────────────────────────────────────────────────

    /**
     * @return array{session: array<string, mixed>, counts: array<string, int>}
     */
    private function present(int $sessionId): array
    {
        $row = $this->sessions->findRow($sessionId);
        \assert($row !== null);

        $counts = ['TOTAL' => $this->records->countForSession($sessionId)];
        foreach (AttendanceStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ($this->records->countByStatus($sessionId) as $status => $n) {
            $counts[$status] = $n;
        }

        return [
            'session' => AttendanceSession::fromRow($row)->toArray(),
            'counts'  => $counts,
        ];
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function recordDenied(array $actor, AttendanceEligibilityReason $reason, int $classId, int $courseId): void
    {
        $this->securityLog->recordSecurityEvent(
            SecurityEventType::UNAUTHORIZED_ATTENDANCE,
            'MEDIUM',
            isset($actor['user_id']) && is_numeric($actor['user_id']) ? (int) $actor['user_id'] : null,
            is_string($actor['ip_address'] ?? null) ? $actor['ip_address'] : null,
            is_string($actor['user_agent'] ?? null) ? $actor['user_agent'] : null,
            ['action' => 'start attendance session', 'reason' => $reason->value, 'class_id' => $classId, 'course_id' => $courseId],
        );
    }

    private function toHms(string $t): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $t) ? $t . ':00' : ($t !== '' ? $t : '23:59:59');
    }

    private function uuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
