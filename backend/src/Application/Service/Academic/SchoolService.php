<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Academic;

use QRIVO\Domain\Entity\Academic\School;

final class SchoolService extends AbstractAcademicService
{
    protected function entityName(): string
    {
        return 'school';
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'name' => 'required|string|min_length:1|max_length:255',
            'code' => 'required|string|min_length:1|max_length:50',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
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
        return School::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        if (isset($data['code']) && $this->repo->existsActiveMatching(['code' => $data['code']], $existing['id'] ?? null)) {
            $this->fail('code', 'A school with this code already exists.');
        }
    }

    /** @return array<int, array{0:string,1:string,2:bool}> */
    protected function blockingChildren(): array
    {
        return [
            ['faculties', 'school_id', true],
            ['rooms', 'school_id', true],
            ['academic_years', 'school_id', false],
        ];
    }
}
