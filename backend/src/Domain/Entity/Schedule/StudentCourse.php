<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Schedule;

/**
 * Student enrolled in a course (TABLES.md `student_courses`).
 *
 * Derived from `student_class_assignments` + `class_courses` and kept in sync by
 * the application (DD-005). Used by challenge-response validation step 8.
 */
final class StudentCourse
{
    public function __construct(
        public readonly int $id,
        public readonly int $studentId,
        public readonly int $courseId,
        public readonly int $classId,
        public readonly int $academicTermId,
        public readonly ?string $createdAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            studentId:      (int) $row['student_id'],
            courseId:       (int) $row['course_id'],
            classId:        (int) $row['class_id'],
            academicTermId: (int) $row['academic_term_id'],
            createdAt:      $row['created_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'student_id'       => $this->studentId,
            'course_id'        => $this->courseId,
            'class_id'         => $this->classId,
            'academic_term_id' => $this->academicTermId,
            'created_at'       => $this->createdAt,
        ];
    }
}
