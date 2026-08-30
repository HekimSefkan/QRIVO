<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Attendance;

use QRIVO\Application\Service\BaseService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Application\Validation\Validator;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Enum\AttendanceStatus;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Domain\Exception\ValidationException;
use QRIVO\Infrastructure\Database\Connection;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceRecordRepository;
use QRIVO\Infrastructure\Repository\Attendance\AttendanceSessionRepository;
use QRIVO\Infrastructure\Repository\RelationshipRepository;

/**
 * Teacher manual attendance — ATTENDANCE_ALGORITHM.md §6, exactly and in order.
 *
 *   1 Teacher authentication ...... controller (BaseController::authenticate)
 *   2 Teacher authorization ....... controller (`attendance.record.update` — TEACHER only;
 *                                   students never hold this permission)
 *   3 Attendance ownership ........ the caller must be the TEACHER who owns the session
 *   4 Student membership .......... the student must be enrolled in the session's class
 *   5 Status validation .......... body status ∈ {WAITING, PRESENT, ABSENT, LATE, EXCUSED}
 *   6 State transition validation . session must not be CANCELLED; new status ≠ current
 *   7 Update ..................... status + source=MANUAL + marked_at, in a transaction
 *   8 Audit log ................. actor, target, old/new state, timestamp, reason, ip
 *
 * SECURITY_RULES.md §7: a student can NEVER modify their own attendance — enforced
 * at step 2 (permission) and step 3 (only the owning teacher passes).
 */
final class ManualAttendanceService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        private readonly Connection $db,
        private readonly AttendanceSessionRepository $sessions,
        private readonly AttendanceRecordRepository $records,
        private readonly RelationshipRepository $relationships,
        private readonly SecurityLogService $securityLog,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input  { status, reason? }
     * @return array<string, mixed>
     */
    public function updateStudentStatus(
        array $actor,
        int $sessionId,
        int $studentId,
        array $input,
        ?\DateTimeImmutable $at = null,
    ): array {
        $at ??= new \DateTimeImmutable('now');

        (new Validator())->validate($input, [
            'status' => 'required|string',
            'reason' => 'string|max_length:500',
        ]);

        $newStatus = strtoupper(trim((string) $input['status']));
        $reason    = isset($input['reason']) && is_string($input['reason']) && trim($input['reason']) !== ''
            ? trim($input['reason'])
            : null;

        // Step 5 — status validation.
        $status = AttendanceStatus::tryFrom($newStatus);
        if ($status === null || !in_array($status, AttendanceStatus::teacherAssignable(), true)) {
            throw new ValidationException('Validation failed.', [
                'status' => ['Status must be one of: '
                    . implode(', ', array_map(static fn (AttendanceStatus $s): string => $s->value, AttendanceStatus::teacherAssignable())) . '.'],
            ]);
        }

        // Step 3 — attendance ownership.
        $session   = $this->sessions->findRow($sessionId);
        if ($session === null) {
            throw new NotFoundException('Attendance session not found.');
        }
        $teacherId = $this->relationships->findTeacherIdForUser((int) $actor['user_id']);
        if ($teacherId === null || (int) $session['teacher_id'] !== $teacherId) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::IDOR_ATTEMPT,
                'HIGH',
                isset($actor['user_id']) && is_numeric($actor['user_id']) ? (int) $actor['user_id'] : null,
                $this->ip($actor),
                $this->ua($actor),
                ['action' => 'manual attendance', 'attendance_session_id' => $sessionId, 'student_id' => $studentId],
            );
            throw new ForbiddenException('You are not authorized to modify attendance for this session.');
        }

        // Step 6 — session-level transition validation.
        if (($session['status'] ?? null) === 'CANCELLED') {
            throw new ConflictException('This attendance session has been cancelled.');
        }

        // SECURITY_RULES.md §7 — no one may set their own attendance status, not
        // even a teacher who is also enrolled in their own class.
        $targetUserId = $this->relationships->findUserIdForStudent($studentId);
        if ($targetUserId !== null && $targetUserId === (int) $actor['user_id']) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::UNAUTHORIZED_ATTENDANCE,
                'HIGH',
                (int) $actor['user_id'],
                $this->ip($actor),
                $this->ua($actor),
                ['action' => 'manual attendance', 'reason' => 'self_modification', 'attendance_session_id' => $sessionId],
            );
            throw new ForbiddenException('You cannot modify your own attendance.');
        }

        // Step 4 — student membership.
        $enrolled = $this->relationships->studentIdEnrolledInClass(
            $studentId,
            (int) $session['class_id'],
            (int) $session['academic_term_id'],
        );
        $existing = $this->records->findForSessionStudent($sessionId, $studentId);

        if (!$enrolled && $existing === null) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::UNAUTHORIZED_ATTENDANCE,
                'MEDIUM',
                (int) $actor['user_id'],
                $this->ip($actor),
                $this->ua($actor),
                ['action' => 'manual attendance', 'reason' => 'student_not_in_session', 'attendance_session_id' => $sessionId, 'student_id' => $studentId],
            );
            throw new NotFoundException('That student is not part of this attendance session.');
        }

        $oldStatus = $existing !== null ? (string) $existing['status'] : null;
        $oldSource = $existing !== null ? (string) $existing['source'] : null;

        // Step 6 — record-level transition validation (no-op rejected).
        if ($oldStatus === $status->value) {
            throw new ValidationException('Validation failed.', [
                'status' => ["The attendance record is already {$status->value}."],
            ]);
        }

        $marked = $at->format('Y-m-d H:i:s');

        // Steps 7 + 8 — update and audit, atomically (CONSTRAINTS.md §6).
        $auditId = $this->db->transaction(function () use (
            $existing, $sessionId, $studentId, $status, $marked, $oldStatus, $oldSource, $reason, $actor, $teacherId, $at
        ): int {
            $locked = $this->records->findForSessionStudent($sessionId, $studentId, lock: true);

            if ($locked === null) {
                $recordId = $this->records->insertManual($sessionId, $studentId, $status->value, $marked);
                $prevStatus = null;
                $prevSource = null;
            } else {
                $recordId   = (int) $locked['id'];
                $prevStatus = (string) $locked['status'];
                $prevSource = (string) $locked['source'];
                $this->records->setStatusManual($recordId, $status->value, $marked);
            }

            // Step 8 — mandatory audit fields (ATTENDANCE_ALGORITHM.md §6):
            //   actor, target, previous state, new state, timestamp, reason, ip.
            // Written inside the transaction — MUST commit with the record change.
            return $this->securityLog->writeAuditLog(
                'ATTENDANCE_STATUS_CHANGED',
                (int) $actor['user_id'],                       // actor
                'attendance_record',                            // target entity
                $recordId,                                      // target id
                ['status' => $prevStatus, 'source' => $prevSource], // previous state
                [                                               // new state (+ the spec's audit identifiers)
                    'status'                => $status->value,
                    'source'                => 'MANUAL',
                    'teacher_id'            => $teacherId,
                    'student_id'            => $studentId,
                    'attendance_session_id' => $sessionId,
                    'old_status'            => $prevStatus,
                    'new_status'            => $status->value,
                    'marked_at'             => $at->format('c'), // timestamp
                ],
                $reason,                                         // reason (where specified)
                $this->ip($actor),                              // ip
            );
        });

        return [
            'attendance_session_id' => $sessionId,
            'student_id'            => $studentId,
            'previous_status'       => $oldStatus,
            'previous_source'       => $oldSource,
            'status'                => $status->value,
            'source'                => 'MANUAL',
            'reason'                => $reason,
            'marked_at'             => $at->format('c'),
            'audit_id'              => $auditId,
        ];
    }

    /** @param array<string, mixed> $actor */
    private function ip(array $actor): ?string
    {
        return is_string($actor['ip_address'] ?? null) ? $actor['ip_address'] : null;
    }

    /** @param array<string, mixed> $actor */
    private function ua(array $actor): ?string
    {
        return is_string($actor['user_agent'] ?? null) ? $actor['user_agent'] : null;
    }
}
