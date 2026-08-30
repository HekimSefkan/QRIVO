<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Attendance;

use QRIVO\Domain\Enum\AttendanceSource;
use QRIVO\Domain\Enum\AttendanceStatus;

/** A single student's attendance state within a session (TABLES.md `attendance_records`). */
final class AttendanceRecord
{
    public function __construct(
        public readonly int $id,
        public readonly int $attendanceSessionId,
        public readonly int $studentId,
        public readonly AttendanceStatus $status,
        public readonly AttendanceSource $source,
        public readonly ?string $markedAt,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                  (int) $row['id'],
            attendanceSessionId: (int) $row['attendance_session_id'],
            studentId:           (int) $row['student_id'],
            status:              AttendanceStatus::from((string) $row['status']),
            source:              AttendanceSource::from((string) $row['source']),
            markedAt:            $row['marked_at'] ?? null,
            createdAt:           $row['created_at'] ?? null,
            updatedAt:           $row['updated_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                    => $this->id,
            'attendance_session_id' => $this->attendanceSessionId,
            'student_id'            => $this->studentId,
            'status'                => $this->status->value,
            'source'                => $this->source->value,
            'marked_at'             => $this->markedAt,
            'created_at'            => $this->createdAt,
            'updated_at'            => $this->updatedAt,
        ];
    }
}
