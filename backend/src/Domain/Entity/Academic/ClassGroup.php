<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Academic;

/**
 * Class — a group of students in a program for a term (TABLES.md `classes`).
 *
 * Named ClassGroup because `Class` is a reserved word in PHP.
 */
final class ClassGroup
{
    public function __construct(
        public readonly int $id,
        public readonly int $programId,
        public readonly int $academicTermId,
        public readonly string $name,
        public readonly int $gradeLevel,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $deletedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            programId:      (int) $row['program_id'],
            academicTermId: (int) $row['academic_term_id'],
            name:           (string) $row['name'],
            gradeLevel:     (int) $row['grade_level'],
            createdAt:      $row['created_at'] ?? null,
            updatedAt:      $row['updated_at'] ?? null,
            deletedAt:      $row['deleted_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'program_id'       => $this->programId,
            'academic_term_id' => $this->academicTermId,
            'name'             => $this->name,
            'grade_level'      => $this->gradeLevel,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
        ];
    }
}
