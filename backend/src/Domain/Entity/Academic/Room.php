<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Academic;

/** Physical room or lecture hall (TABLES.md Group 2). */
final class Room
{
    public function __construct(
        public readonly int $id,
        public readonly int $schoolId,
        public readonly string $name,
        public readonly string $code,
        public readonly ?int $capacity = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $deletedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int) $row['id'],
            schoolId:  (int) $row['school_id'],
            name:      (string) $row['name'],
            code:      (string) $row['code'],
            capacity:  isset($row['capacity']) && $row['capacity'] !== null ? (int) $row['capacity'] : null,
            createdAt: $row['created_at'] ?? null,
            updatedAt: $row['updated_at'] ?? null,
            deletedAt: $row['deleted_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'school_id'  => $this->schoolId,
            'name'       => $this->name,
            'code'       => $this->code,
            'capacity'   => $this->capacity,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
