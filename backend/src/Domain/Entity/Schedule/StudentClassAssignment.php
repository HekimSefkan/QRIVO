<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Schedule;

/** Student enrolled in a class for a term (TABLES.md `student_class_assignments`). */
final class StudentClassAssignment
{
    public function __construct(
        public readonly int $id,
        public readonly int $studentId,
        public readonly int $classId,
        public readonly int $academicTermId,
        public readonly ?string $enrolledAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            studentId:      (int) $row['student_id'],
            classId:        (int) $row['class_id'],
            academicTermId: (int) $row['academic_term_id'],
            enrolledAt:     $row['enrolled_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'student_id'       => $this->studentId,
            'class_id'         => $this->classId,
            'academic_term_id' => $this->academicTermId,
            'enrolled_at'      => $this->enrolledAt,
        ];
    }
}
