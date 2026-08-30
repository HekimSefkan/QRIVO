<?php

declare(strict_types=1);

namespace QRIVO\Application\Service;

use QRIVO\Domain\Attendance\AttendanceEligibility;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Entity\Schedule\CourseSchedule;
use QRIVO\Domain\Enum\AttendanceEligibilityReason;
use QRIVO\Domain\Enum\DayOfWeek;
use QRIVO\Domain\Enum\SecurityEventType;
use QRIVO\Domain\Enum\UserRole;
use QRIVO\Infrastructure\Repository\ScheduleRepository;

/**
 * Answers the Phase 9 keystone question:
 *
 *   "May this teacher create attendance for course C, class K (term T),
 *    at time now — and if so, in which room?"
 *
 * This is the pre-QR portion of ATTENDANCE_ALGORITHM.md §2:
 *   step 2  teacher authorization ....... TEACHER role + teacher profile
 *   steps 3-4 course + class assignment .. teacher_class_assignments (C-016)
 *   steps 5-8 schedule / date / time ..... course_schedules covering (day, time)
 *   step 9  academic term ............... active term, or the term supplied
 *
 * All decisions are server-side. Phase 10 (attendance session creation) will
 * call this and must not proceed on a non-AUTHORIZED result.
 */
final class AttendanceEligibilityService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        private readonly ScheduleRepository $schedule,
        private readonly SecurityLogService $securityLog,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed>     $actor  validated actor context (user_id, roles, ...)
     * @param int|null                 $academicTermId  null → use the active term
     * @param \DateTimeImmutable|null   $at     null → skip the schedule/time check (assignment-only)
     */
    public function forTeacher(
        array $actor,
        int $classId,
        int $courseId,
        ?int $academicTermId = null,
        ?\DateTimeImmutable $at = null,
    ): AttendanceEligibility {
        $userId = isset($actor['user_id']) && is_numeric($actor['user_id']) ? (int) $actor['user_id'] : 0;
        $roles  = is_array($actor['roles'] ?? null) ? $actor['roles'] : [];

        if (!in_array(UserRole::TEACHER->value, $roles, true)) {
            return $this->deny($actor, AttendanceEligibilityReason::NOT_A_TEACHER);
        }

        // steps 3-4: assignment lookup (also resolves the term when not supplied)
        $assignment = $this->schedule->findAssignmentForTeacherUser($userId, $classId, $courseId, $academicTermId);
        if ($assignment === null) {
            // Distinguish "no active term" from "not assigned" only when the caller
            // relied on the active term.
            $reason = $academicTermId === null && !$this->schedule->activeTermExists()
                ? AttendanceEligibilityReason::NO_ACTIVE_TERM
                : AttendanceEligibilityReason::NOT_ASSIGNED_TO_CLASS_COURSE;

            return $this->deny($actor, $reason, $academicTermId);
        }

        $tcaId  = (int) $assignment['id'];
        $termId = (int) $assignment['academic_term_id'];

        // steps 5-8: schedule / day / time
        if ($at !== null) {
            $dow  = DayOfWeek::fromDateTime($at)->value;
            $time = $at->format('H:i:s');

            if (!$this->schedule->assignmentHasScheduleOnDay($tcaId, $dow)) {
                return $this->deny($actor, AttendanceEligibilityReason::NOT_SCHEDULED_ON_DAY, $termId, $tcaId);
            }

            $slot = $this->schedule->findCoveringSchedule($tcaId, $dow, $time);
            if ($slot === null) {
                return $this->deny($actor, AttendanceEligibilityReason::OUTSIDE_SCHEDULED_TIME, $termId, $tcaId);
            }

            $scheduleResource = CourseSchedule::fromRow($slot)->toArray();

            return AttendanceEligibility::authorized($tcaId, $termId, (int) $slot['room_id'], $scheduleResource);
        }

        return AttendanceEligibility::authorized($tcaId, $termId);
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function deny(
        array $actor,
        AttendanceEligibilityReason $reason,
        ?int $termId = null,
        ?int $tcaId = null,
    ): AttendanceEligibility {
        // A teacher probing classes they are not assigned to is a low-severity
        // authorization signal worth recording.
        if (in_array($reason, [
            AttendanceEligibilityReason::NOT_ASSIGNED_TO_CLASS_COURSE,
            AttendanceEligibilityReason::NOT_A_TEACHER,
        ], true)) {
            $this->securityLog->recordSecurityEvent(
                SecurityEventType::UNAUTHORIZED_ACCESS,
                'LOW',
                isset($actor['user_id']) && is_numeric($actor['user_id']) ? (int) $actor['user_id'] : null,
                is_string($actor['ip_address'] ?? null) ? $actor['ip_address'] : null,
                is_string($actor['user_agent'] ?? null) ? $actor['user_agent'] : null,
                ['action' => 'check attendance eligibility', 'reason' => $reason->value],
            );
        }

        return AttendanceEligibility::denied($reason, $termId, $tcaId);
    }
}
