<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Attendance;

use QRIVO\Domain\Enum\SessionStatus;

/**
 * A QR attendance session (TABLES.md `attendance_sessions`).
 *
 * Security (DD-002): `session_secret` is held here for server-side QR signing but
 * is NEVER included in {@see self::toArray()} — it must not leave the backend.
 */
final class AttendanceSession
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly int $courseId,
        public readonly int $classId,
        public readonly int $teacherId,
        public readonly int $roomId,
        public readonly int $academicTermId,
        public readonly string $startTime,
        public readonly ?string $endTime,
        public readonly string $expiresAt,
        public readonly SessionStatus $status,
        public readonly string $sessionSecret,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            uuid:           (string) $row['uuid'],
            courseId:       (int) $row['course_id'],
            classId:        (int) $row['class_id'],
            teacherId:      (int) $row['teacher_id'],
            roomId:         (int) $row['room_id'],
            academicTermId: (int) $row['academic_term_id'],
            startTime:      (string) $row['start_time'],
            endTime:        $row['end_time'] ?? null,
            expiresAt:      (string) $row['expires_at'],
            status:         SessionStatus::from((string) $row['status']),
            sessionSecret:  (string) $row['session_secret'],
            createdAt:      $row['created_at'] ?? null,
            updatedAt:      $row['updated_at'] ?? null,
        );
    }

    /**
     * Public representation — deliberately WITHOUT `session_secret`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'course_id'        => $this->courseId,
            'class_id'         => $this->classId,
            'teacher_id'       => $this->teacherId,
            'room_id'          => $this->roomId,
            'academic_term_id' => $this->academicTermId,
            'start_time'       => $this->startTime,
            'end_time'         => $this->endTime,
            'expires_at'       => $this->expiresAt,
            'status'           => $this->status->value,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
        ];
    }
}
