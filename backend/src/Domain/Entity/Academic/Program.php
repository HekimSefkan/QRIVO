<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Academic;

/** Degree program within a department (TABLES.md Group 2). */
final class Program
{
    public function __construct(
        public readonly int $id,
        public readonly int $departmentId,
        public readonly string $name,
        public readonly string $code,
        public readonly int $durationYears,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $deletedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:            (int) $row['id'],
            departmentId:  (int) $row['department_id'],
            name:          (string) $row['name'],
            code:          (string) $row['code'],
            durationYears: (int) $row['duration_years'],
            createdAt:     $row['created_at'] ?? null,
            updatedAt:     $row['updated_at'] ?? null,
            deletedAt:     $row['deleted_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'department_id'  => $this->departmentId,
            'name'           => $this->name,
            'code'           => $this->code,
            'duration_years' => $this->durationYears,
            'created_at'     => $this->createdAt,
            'updated_at'     => $this->updatedAt,
        ];
    }
}
