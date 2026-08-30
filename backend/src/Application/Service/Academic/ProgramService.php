<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Academic;

use QRIVO\Domain\Entity\Academic\Program;

final class ProgramService extends AbstractAcademicService
{
    protected function entityName(): string
    {
        return 'program';
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'department_id'  => 'required|integer',
            'name'           => 'required|string|min_length:1|max_length:255',
            'code'           => 'required|string|min_length:1|max_length:50',
            'duration_years' => 'required|integer_range:1,10',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return ['department_id' => 'department_id'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        if ($isCreate || array_key_exists('department_id', $input)) {
            $data['department_id'] = (int) ($input['department_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('name', $input)) {
            $data['name'] = trim((string) ($input['name'] ?? ''));
        }
        if ($isCreate || array_key_exists('code', $input)) {
            $data['code'] = strtoupper(trim((string) ($input['code'] ?? '')));
        }
        if ($isCreate || array_key_exists('duration_years', $input)) {
            $data['duration_years'] = (int) ($input['duration_years'] ?? 0);
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return Program::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('department_id', 'departments', $data['department_id'] ?? null);

        if (isset($data['department_id'], $data['code'])
            && $this->repo->existsActiveMatching(
                ['department_id' => (int) $data['department_id'], 'code' => $data['code']],
                $existing['id'] ?? null,
            )
        ) {
            $this->fail('code', 'A program with this code already exists in this department.');
        }
    }

    /** @return array<int, array{0:string,1:string,2:bool}> */
    protected function blockingChildren(): array
    {
        return [
            ['classes', 'program_id', true],
            ['students', 'program_id', true],
        ];
    }
}
