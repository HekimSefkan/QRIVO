<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Academic;

/**
 * School — top-level institutional unit (TABLES.md Group 2).
 */
final class School
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $code,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $deletedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int) $row['id'],
            name:      (string) $row['name'],
            code:      (string) $row['code'],
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
            'name'       => $this->name,
            'code'       => $this->code,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
