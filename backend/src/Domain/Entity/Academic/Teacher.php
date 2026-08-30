<?php

declare(strict_types=1);

namespace QRIVO\Domain\Entity\Academic;

/** Teacher profile, linked 1:1 to a users record (TABLES.md Group 3). */
final class Teacher
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $departmentId,
        public readonly string $employeeNumber,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $deletedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            userId:         (int) $row['user_id'],
            departmentId:   (int) $row['department_id'],
            employeeNumber: (string) $row['employee_number'],
            createdAt:      $row['created_at'] ?? null,
            updatedAt:      $row['updated_at'] ?? null,
            deletedAt:      $row['deleted_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->userId,
            'department_id'   => $this->departmentId,
            'employee_number' => $this->employeeNumber,
            'created_at'      => $this->createdAt,
            'updated_at'      => $this->updatedAt,
        ];
    }
}
