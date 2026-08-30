<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Schedule;

/**
 * Teacher assigned to teach a course to a class in a term
 * (TABLES.md `teacher_class_assignments`).
 *
 * This is the authorization record for attendance session creation
 * (ATTENDANCE_ALGORITHM.md §2 steps 3-4).
 */
final class TeacherClassAssignment
{
    public function __construct(
        public readonly int $id,
        public readonly int $teacherId,
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
            teacherId:      (int) $row['teacher_id'],
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
            'teacher_id'       => $this->teacherId,
            'class_id'         => $this->classId,
            'course_id'        => $this->courseId,
            'academic_term_id' => $this->academicTermId,
            'created_at'       => $this->createdAt,
        ];
    }
}
