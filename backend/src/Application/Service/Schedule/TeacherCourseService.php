<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Schedule;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Domain\Entity\Schedule\TeacherCourse;

/** Manages which courses a teacher is responsible for in a term (`teacher_courses`). */
final class TeacherCourseService extends AbstractAcademicService
{
    protected function entityName(): string
    {
        return 'teacher_course';
    }

    protected function usesSoftDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'teacher_id'       => 'required|integer',
            'course_id'        => 'required|integer',
            'academic_term_id' => 'required|integer',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return ['teacher_id' => 'teacher_id', 'course_id' => 'course_id', 'academic_term_id' => 'academic_term_id'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        foreach (['teacher_id', 'course_id', 'academic_term_id'] as $key) {
            if ($isCreate || array_key_exists($key, $input)) {
                $data[$key] = (int) ($input[$key] ?? 0);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return TeacherCourse::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('teacher_id', 'teachers', $data['teacher_id'] ?? null);
        $this->requireReference('course_id', 'courses', $data['course_id'] ?? null);
        $this->requireReference('academic_term_id', 'academic_terms', $data['academic_term_id'] ?? null, false);

        if (isset($data['teacher_id'], $data['course_id'], $data['academic_term_id'])
            && $this->repo->existsActiveMatching([
                'teacher_id'       => (int) $data['teacher_id'],
                'course_id'        => (int) $data['course_id'],
                'academic_term_id' => (int) $data['academic_term_id'],
            ], $existing['id'] ?? null)
        ) {
            $this->fail('course_id', 'This teacher is already assigned to this course for this term.');
        }
    }

    /** @return array<int, array{0:string,1:string,2:bool}> */
    protected function blockingChildren(): array
    {
        return [];
    }
}
