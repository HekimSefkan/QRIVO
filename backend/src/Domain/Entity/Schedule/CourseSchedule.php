<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Schedule;

use QRIVO\Domain\Enum\DayOfWeek;

/**
 * A weekly meeting of a teacher-class assignment (TABLES.md `course_schedules`).
 *
 * Used for the schedule/date/time validation of attendance session creation
 * (ATTENDANCE_ALGORITHM.md §2 steps 5-8).
 */
final class CourseSchedule
{
    public function __construct(
        public readonly int $id,
        public readonly int $teacherClassAssignmentId,
        public readonly int $roomId,
        public readonly int $dayOfWeek,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                       (int) $row['id'],
            teacherClassAssignmentId: (int) $row['teacher_class_assignment_id'],
            roomId:                   (int) $row['room_id'],
            dayOfWeek:                (int) $row['day_of_week'],
            startTime:                self::hhmm($row['start_time']),
            endTime:                  self::hhmm($row['end_time']),
            createdAt:                $row['created_at'] ?? null,
            updatedAt:                $row['updated_at'] ?? null,
        );
    }

    public function day(): DayOfWeek
    {
        return DayOfWeek::from($this->dayOfWeek);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                          => $this->id,
            'teacher_class_assignment_id' => $this->teacherClassAssignmentId,
            'room_id'                     => $this->roomId,
            'day_of_week'                 => $this->dayOfWeek,
            'day'                         => $this->day()->label(),
            'start_time'                  => $this->startTime,
            'end_time'                    => $this->endTime,
            'created_at'                  => $this->createdAt,
            'updated_at'                  => $this->updatedAt,
        ];
    }

    private static function hhmm(mixed $value): string
    {
        $s = (string) $value;
        // Normalise "HH:MM:SS" → "HH:MM" for the API.
        return preg_match('/^(\d{2}:\d{2})/', $s, $m) ? $m[1] : $s;
    }
}
