<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Academic;

/** Academic year scoped to a school (TABLES.md Group 2). Not soft-deleted. */
final class AcademicYear
{
    public function __construct(
        public readonly int $id,
        public readonly int $schoolId,
        public readonly string $name,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly bool $isActive,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int) $row['id'],
            schoolId:  (int) $row['school_id'],
            name:      (string) $row['name'],
            startDate: (string) $row['start_date'],
            endDate:   (string) $row['end_date'],
            isActive:  (bool) $row['is_active'],
            createdAt: $row['created_at'] ?? null,
            updatedAt: $row['updated_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'school_id'  => $this->schoolId,
            'name'       => $this->name,
            'start_date' => $this->startDate,
            'end_date'   => $this->endDate,
            'is_active'  => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
