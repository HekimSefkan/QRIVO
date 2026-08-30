<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Schedule;

/** A course offered to a class in a term (TABLES.md `class_courses`). */
final class ClassCourse
{
    public function __construct(
        public readonly int $id,
        public readonly int $classId,
        public readonly int $courseId,
        public readonly int $academicTermId,
        public readonly ?string $createdAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            classId:        (int) $row['class_id'],
            courseId:       (int) $row['course_id'],
            academicTermId: (int) $row['academic_term_id'],
            createdAt:      $row['created_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'class_id'         => $this->classId,
            'course_id'        => $this->courseId,
            'academic_term_id' => $this->academicTermId,
            'created_at'       => $this->createdAt,
        ];
    }
}
