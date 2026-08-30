<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Academic;

/** Student profile, linked 1:1 to a users record (TABLES.md Group 3). */
final class Student
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $programId,
        public readonly string $studentNumber,
        public readonly int $enrollmentYear,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $deletedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            userId:         (int) $row['user_id'],
            programId:      (int) $row['program_id'],
            studentNumber:  (string) $row['student_number'],
            enrollmentYear: (int) $row['enrollment_year'],
            createdAt:      $row['created_at'] ?? null,
            updatedAt:      $row['updated_at'] ?? null,
            deletedAt:      $row['deleted_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->userId,
            'program_id'      => $this->programId,
            'student_number'  => $this->studentNumber,
            'enrollment_year' => $this->enrollmentYear,
            'created_at'      => $this->createdAt,
            'updated_at'      => $this->updatedAt,
        ];
    }
}
