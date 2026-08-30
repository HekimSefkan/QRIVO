<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Academic;

use QRIVO\Domain\Entity\Academic\AcademicTerm;

final class AcademicTermService extends AbstractAcademicService
{
    protected function entityName(): string
    {
        return 'academic_term';
    }

    protected function usesSoftDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function createRules(): array
    {
        return [
            'academic_year_id' => 'required|integer',
            'name'             => 'required|string|min_length:1|max_length:100',
            'term_number'      => 'required|integer_range:1,3',
            'start_date'       => 'required|date',
            'end_date'         => 'required|date',
            'is_active'        => 'boolean',
        ];
    }

    /** @return array<string, string> */
    protected function filterMap(): array
    {
        return ['academic_year_id' => 'academic_year_id', 'is_active' => 'is_active'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function fromInput(array $input, bool $isCreate): array
    {
        $data = [];
        if ($isCreate || array_key_exists('academic_year_id', $input)) {
            $data['academic_year_id'] = (int) ($input['academic_year_id'] ?? 0);
        }
        if ($isCreate || array_key_exists('name', $input)) {
            $data['name'] = trim((string) ($input['name'] ?? ''));
        }
        if ($isCreate || array_key_exists('term_number', $input)) {
            $data['term_number'] = (int) ($input['term_number'] ?? 0);
        }
        if ($isCreate || array_key_exists('start_date', $input)) {
            $data['start_date'] = (string) ($input['start_date'] ?? '');
        }
        if ($isCreate || array_key_exists('end_date', $input)) {
            $data['end_date'] = (string) ($input['end_date'] ?? '');
        }
        if ($isCreate || array_key_exists('is_active', $input)) {
            $data['is_active'] = $this->toBool($input['is_active'] ?? false);
        }

        return $data;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    protected function toResource(array $row): array
    {
        return AcademicTerm::fromRow($row)->toArray();
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $existing
     */
    protected function assertConsistent(array $data, ?array $existing): void
    {
        $this->requireReference('academic_year_id', 'academic_years', $data['academic_year_id'] ?? null, false);

        if (!empty($data['start_date']) && !empty($data['end_date']) && $data['end_date'] <= $data['start_date']) {
            $this->fail('end_date', 'The end_date must be after the start_date.');
        }
    }

    /** @return array<int, array{0:string,1:string,2:bool}> */
    protected function blockingChildren(): array
    {
        return [['classes', 'academic_term_id', true]];
    }
}
