<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Schedule;

/** A course a teacher is responsible for in a term (TABLES.md `teacher_courses`). */
final class TeacherCourse
{
    public function __construct(
        public readonly int $id,
        public readonly int $teacherId,
        public readonly int $courseId,
        public readonly int $academicTermId,
        public readonly ?string $createdAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            teacherId:      (int) $row['teacher_id'],
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
            'teacher_id'       => $this->teacherId,
            'course_id'        => $this->courseId,
            'academic_term_id' => $this->academicTermId,
            'created_at'       => $this->createdAt,
        ];
    }
}
