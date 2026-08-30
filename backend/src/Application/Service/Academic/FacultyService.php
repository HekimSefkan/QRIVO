<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Academic;

use QRIVO\Domain\Entity\Academic\Faculty;

final class FacultyService extends AbstractAcademicService
{
    protected function entityName(): string
    {
        return 'faculty';
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'school_id' => 'required|integer',
            'name'      => 'required|string|min_length:1|max_length:255',
            'code'      => 'required|string|min_length:1|max_length:50',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return ['school_id' => 'school_id'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        if ($isCreate || array_key_exists('school_id', $input)) {
            $data['school_id'] = (int) ($input['school_id'] ?? 0);
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
        return Faculty::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('school_id', 'schools', $data['school_id'] ?? null);

        if (isset($data['school_id'], $data['code'])
            && $this->repo->existsActiveMatching(
                ['school_id' => (int) $data['school_id'], 'code' => $data['code']],
                $existing['id'] ?? null,
            )
        ) {
            $this->fail('code', 'A faculty with this code already exists in this school.');
        }
    }

    /** @return array<int, array{0:string,1:string,2:bool}> */
    protected function blockingChildren(): array
    {
        return [['departments', 'faculty_id', true]];
    }
}
