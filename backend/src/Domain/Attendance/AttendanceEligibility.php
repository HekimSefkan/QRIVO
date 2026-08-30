<?php

declare(strict_types=1);

namespace QRIVO\Domain\Attendance;

use QRIVO\Domain\Enum\AttendanceEligibilityReason;

/**
 * Immutable result of the scheduling-authorization check:
 * "may this teacher open attendance for this course/class at this time, and where?"
 *
 * Phase 9 produces this; Phase 10's attendance-session creation consumes it.
 */
final class AttendanceEligibility
{
    /**
     * @param array<string, mixed>|null $schedule the matched course_schedules row (API-shaped), when time was checked
     */
    private function __construct(
        public readonly AttendanceEligibilityReason $reason,
        public readonly ?int $teacherClassAssignmentId = null,
        public readonly ?int $academicTermId = null,
        public readonly ?int $roomId = null,
        public readonly ?array $schedule = null,
    ) {}

    public static function denied(AttendanceEligibilityReason $reason, ?int $termId = null, ?int $tcaId = null): self
    {
        return new self($reason, $tcaId, $termId);
    }

    /**
     * @param array<string, mixed>|null $schedule
     */
    public static function authorized(int $tcaId, int $termId, ?int $roomId = null, ?array $schedule = null): self
    {
        return new self(AttendanceEligibilityReason::AUTHORIZED, $tcaId, $termId, $roomId, $schedule);
    }

    public function isAuthorized(): bool
    {
        return $this->reason->isAuthorized();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'authorized'                  => $this->isAuthorized(),
            'reason'                      => $this->reason->value,
            'message'                     => $this->reason->message(),
            'teacher_class_assignment_id' => $this->teacherClassAssignmentId,
            'academic_term_id'            => $this->academicTermId,
            'room_id'                     => $this->roomId,
            'schedule'                    => $this->schedule,
        ];
    }
}
