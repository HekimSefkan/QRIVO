<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Academic;

use QRIVO\Domain\Entity\Academic\Department;

final class DepartmentService extends AbstractAcademicService
{
    protected function entityName(): string
    {
        return 'department';
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'faculty_id' => 'required|integer',
            'name'       => 'required|string|min_length:1|max_length:255',
            'code'       => 'required|string|min_length:1|max_length:50',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return ['faculty_id' => 'faculty_id'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        if ($isCreate || array_key_exists('faculty_id', $input)) {
            $data['faculty_id'] = (int) ($input['faculty_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('name', $input)) {
            $data['name'] = trim((string) ($input['name'] ?? ''));
        }
        if ($isCreate || array_key_exists('code', $input)) {
            $data['code'] = strtoupper(trim((string) ($input['code'] ?? '')));
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return Department::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('faculty_id', 'faculties', $data['faculty_id'] ?? null);

        if (isset($data['faculty_id'], $data['code'])
            && $this->repo->existsActiveMatching(
                ['faculty_id' => (int) $data['faculty_id'], 'code' => $data['code']],
                $existing['id'] ?? null,
            )
        ) {
            $this->fail('code', 'A department with this code already exists in this faculty.');
        }
    }

    /** @return array<int, array{0:string,1:string,2:bool}> */
    protected function blockingChildren(): array
    {
        return [
            ['programs', 'department_id', true],
            ['courses', 'department_id', true],
            ['teachers', 'department_id', true],
        ];
    }
}
