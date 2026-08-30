<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Academic;

use QRIVO\Domain\Entity\Academic\ClassGroup;

final class ClassService extends AbstractAcademicService
{
    protected function entityName(): string
    {
        return 'class';
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'program_id'       => 'required|integer',
            'academic_term_id' => 'required|integer',
            'name'             => 'required|string|min_length:1|max_length:100',
            'grade_level'      => 'required|integer_range:1,12',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return ['program_id' => 'program_id', 'academic_term_id' => 'academic_term_id'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        if ($isCreate || array_key_exists('program_id', $input)) {
            $data['program_id'] = (int) ($input['program_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('academic_term_id', $input)) {
            $data['academic_term_id'] = (int) ($input['academic_term_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('name', $input)) {
            $data['name'] = trim((string) ($input['name'] ?? ''));
        }
        if ($isCreate || array_key_exists('grade_level', $input)) {
            $data['grade_level'] = (int) ($input['grade_level'] ?? 0);
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return ClassGroup::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('program_id', 'programs', $data['program_id'] ?? null);
        $this->requireReference('academic_term_id', 'academic_terms', $data['academic_term_id'] ?? null, false);
    }

    /** @return array<int, array{0:string,1:string,2:bool}> */
    protected function blockingChildren(): array
    {
        // student_class_assignments / teacher_class_assignments / class_courses
        // (Phase 9) and attendance_sessions (Phase 10) will be added as they land.
        return [];
    }
}
